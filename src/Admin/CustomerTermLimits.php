<?php

namespace HexaPrWire\Core\Admin;

use Hexa\PluginCore\Taxonomies\TermChoiceLimits;
use HexaPrWire\Core\Contracts\Module;

/**
 * Customer limits on release categories and tags (Hexa PR Wire → Editor
 * Screens), enforced by Core's TermChoiceLimits. Staff (edit_others_posts)
 * are never limited.
 *
 * Customers never see the release category (Press Release, filter
 * hprwc_release_category) in the Categories box; every customer save keeps it
 * on the release next to the category they picked.
 */
final class CustomerTermLimits implements Module {
	public const SINGLE_CATEGORY = 'hprwc_customer_single_category';
	public const MAX_TAGS = 'hprwc_customer_max_tags';
	private const STAFF = 'edit_others_posts';
	private const SAVE_ACTION = 'hprwc_save_customer_term_limits';

	public function register(): void {
		( new TermChoiceLimits( [
			[ 'taxonomy' => 'category', 'max' => self::single_category() ? 1 : 0, 'capability' => self::STAFF, 'post_types' => [ 'post' ] ],
			[ 'taxonomy' => 'post_tag', 'max' => self::max_tags(), 'capability' => self::STAFF, 'post_types' => [ 'post' ] ],
		] ) )->register();
		add_action( 'admin_post_' . self::SAVE_ACTION, [ $this, 'save' ] );
		add_filter( 'get_terms_args', [ $this, 'hide_release_category' ], 10, 2 );
		// save_post (not save_post_post, which fires first) so this runs after
		// TermChoiceLimits trims the customer's pick at priority 99.
		add_action( 'save_post', [ $this, 'keep_release_category' ], 100, 2 );
	}

	/** Category every release belongs to (filter: hprwc_release_category). */
	public static function release_category(): ?\WP_Term {
		$term = get_category_by_slug( (string) apply_filters( 'hprwc_release_category', 'press-release' ) );
		return $term instanceof \WP_Term ? $term : null;
	}

	private static function is_customer(): bool {
		return is_user_logged_in() && ! current_user_can( self::STAFF );
	}

	/** Leaves the release category out of the customer's Categories box (All and Most Used). */
	public function hide_release_category( array $args, array $taxonomies ): array {
		if ( ! is_admin() || wp_doing_ajax() || ! in_array( 'category', $taxonomies, true ) || ! self::is_customer() ) {
			return $args;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || 'post' !== $screen->post_type ) {
			return $args;
		}
		// Looking up the release category runs get_terms again; skip that nested call.
		static $looking_up = false;
		if ( $looking_up ) {
			return $args;
		}
		$looking_up = true;
		$term = self::release_category();
		$looking_up = false;
		if ( ! $term ) {
			return $args;
		}
		$args['exclude'] = array_merge( wp_parse_id_list( $args['exclude'] ?? [] ), [ (int) $term->term_id ] );
		return $args;
	}

	/** A customer's save keeps the release category next to the category they picked. */
	public function keep_release_category( int $post_id, \WP_Post $post ): void {
		$term = self::release_category();
		if ( 'post' !== $post->post_type || ! $term || ! self::is_customer() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		wp_set_post_categories( $post_id, [ (int) $term->term_id ], true );
	}

	public static function single_category(): bool {
		return '1' === (string) get_option( self::SINGLE_CATEGORY, '1' );
	}

	/** 0 means no limit. */
	public static function max_tags(): int {
		return max( 0, (int) get_option( self::MAX_TAGS, 3 ) );
	}

	public function render(): void {
		?>
		<div class="hprwc-panel">
			<h2>Customer categories and tags</h2>
			<p>Limits for customers on the release editor. Staff are never limited.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>
				<p><label><input type="checkbox" name="single_category" value="1" <?php checked( self::single_category() ); ?>> Customers can pick only one category (shown as radio buttons). Press Release is always kept and not shown to them.</label></p>
				<p><label>Most tags a customer can add <input type="number" min="0" max="50" name="max_tags" value="<?php echo esc_attr( (string) self::max_tags() ); ?>" class="small-text"></label> <span class="description">0 means no limit.</span></p>
				<?php submit_button( 'Save customer limits', 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::SAVE_ACTION ) ) {
			wp_die( 'Invalid request.' );
		}
		update_option( self::SINGLE_CATEGORY, empty( $_POST['single_category'] ) ? '0' : '1' );
		update_option( self::MAX_TAGS, min( 50, max( 0, (int) ( $_POST['max_tags'] ?? 3 ) ) ) );
		wp_safe_redirect( add_query_arg( [ 'page' => 'hexa-pr-wire-core-editor', 'updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
