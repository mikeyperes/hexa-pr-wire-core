<?php

namespace HexaPrWire\Core\Admin;

use Hexa\PluginCore\WpAdminAjax\AjaxActionRegistry;
use Hexa\PluginCore\WpAdminAjax\AjaxFailure;
use Hexa\PluginCore\WpAdminAjax\AjaxRequest;
use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Integrations\ReleaseSubjectRecognition;
use HexaPrWire\Core\Integrations\VerifiedProfiles;

final class ReleaseProfiles implements Module {
	private const NONCE = 'hprwc_release_profiles';

	public function register(): void {
		add_action( 'add_meta_boxes_post', [ $this, 'box' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'wp_after_insert_post', [ $this, 'save' ], 100, 1 );
		( new AjaxActionRegistry( [ 'capability' => 'manage_options', 'nonce_action' => self::NONCE ] ) )->register( [
			'hprwc_verified_profiles_query' => [ 'callback' => [ $this, 'query' ] ],
			'hprwc_release_subjects' => [ 'callback' => [ $this, 'subjects' ] ],
		] );
	}

	public function box(): void {
		if ( current_user_can( 'manage_options' ) ) {
			add_meta_box( 'hprwc-verified-profiles', 'Verified Profiles', [ $this, 'render' ], 'post', 'normal' );
		}
	}

	public function render( \WP_Post $post ): void {
		if ( ! ( new VerifiedProfiles() )->available() ) {
			echo '<p>Activate SMP Verified Profiles to match this release to a person.</p>';
			return;
		}
		if ( ! \SMP\VerifiedProfiles\ProfileTypes\ProfileTypes::post_types() ) {
			echo '<p>Enable a profile type in SMP Verified Profiles before searching for people.</p>';
			return;
		}
		wp_nonce_field( self::NONCE, 'hprwc_profile_nonce' );
		?>
		<input type="hidden" name="hprwc_verified_profile" value="" data-hprwc-profile-selection>
		<div data-hprwc-profiles data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
			<p>Search existing person profiles or identify the people this release is about. Review each suggestion before attaching it.</p>
			<p><label>Person name <input type="search" data-profile-name></label>
				<button type="button" class="button" data-profile-search>Search profiles</button>
				<button type="button" class="button" data-profile-recognize>Recognize release subjects with AI</button></p>
			<p role="status" aria-live="polite" data-profile-status></p>
			<ul data-profile-results></ul>
		</div>
		<?php
	}

	public function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $hook, [ 'post.php', 'post-new.php' ], true )
			|| ! $screen || 'post' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_script( 'hprwc-release-profiles', HPRWC_URL . 'assets/admin/release-profiles.js', [ 'jquery' ], HPRWC_VERSION, true );
		wp_localize_script( 'hprwc-release-profiles', 'hprwcReleaseProfiles', [ 'nonce' => wp_create_nonce( self::NONCE ) ] );
	}

	public function query( AjaxRequest $request ): array {
		$this->release( $request );
		return [ 'profiles' => ( new VerifiedProfiles() )->query( $request->text( 'name', '', 'post' ) ) ];
	}

	public function subjects( AjaxRequest $request ): array {
		$post = $this->release( $request );
		$this->load_generation();
		if ( ! function_exists( 'smp_verified_profiles\\smp_vp_spawn_api_request' ) ) {
			throw AjaxFailure::bad_request( 'Enable SMP Verified Profiles profile generation before using AI recognition.' );
		}
		$settings = \smp_verified_profiles\smp_vp_spawn_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['api_key'] ) ) {
			throw AjaxFailure::bad_request( 'Configure and enable the AI connection in SMP Verified Profiles, Profile Generation.' );
		}
		$title = $request->text( 'title', $post->post_title, 'post' );
		$content = $request->html( 'content', $post->post_content, 'post' );
		$result = \smp_verified_profiles\smp_vp_spawn_api_request( 'POST', '/entities/propose', [
			'post_id' => $post->ID,
			'post_type' => $post->post_type,
			'title' => $title,
			'content' => $content,
			'url' => get_permalink( $post ),
			'mode' => 'filled',
			'strictness' => 'strict',
		] );
		if ( empty( $result['success'] ) ) {
			throw AjaxFailure::bad_request( 'Subject recognition could not finish. Check the SMP Verified Profiles connection and try again.' );
		}
		$recognition = new ReleaseSubjectRecognition( new VerifiedProfiles() );
		return [ 'profiles' => $recognition->recognize( (array) ( $result['entities'] ?? [] ), $title, $content ) ];
	}

	/** Apply the explicit selection after Core has stored the release's existing profiles. */
	public function save( int $post_id ): void {
		$nonce = $_POST['hprwc_profile_nonce'] ?? '';
		if ( 'post' !== get_post_type( $post_id ) || ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), self::NONCE )
			|| ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id )
			|| wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$id = absint( $_POST['hprwc_verified_profile'] ?? 0 );
		if ( ! ( new VerifiedProfiles() )->person( $id ) ) {
			return;
		}
		$this->load_generation();
		if ( function_exists( 'smp_verified_profiles\\smp_vp_spawn_attach_profiles_to_post' ) ) {
			\smp_verified_profiles\smp_vp_spawn_attach_profiles_to_post( $post_id, [ [ 'wp_post_id' => $id ] ] );
		}
	}

	private function load_generation(): void {
		if ( function_exists( 'smp_verified_profiles\\smp_vp_load_settings_dashboard_files' ) ) {
			\smp_verified_profiles\smp_vp_load_settings_dashboard_files();
		}
	}

	private function release( AjaxRequest $request ): \WP_Post {
		$post = get_post( $request->int( 'post_id', 0, 'post' ) );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			throw AjaxFailure::bad_request( 'You cannot edit this release.' );
		}
		return $post;
	}
}
