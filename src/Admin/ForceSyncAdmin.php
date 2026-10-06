<?php

namespace HexaPrWire\Core\Admin;

use HexaPrWire\Core\CredentialRepository;
use HexaPrWire\Core\DestinationRegistry;
use HexaPrWire\Core\ForceSyncService;
use HexaPrWire\Core\PublicationResolver;
use HexaPrWire\Core\Syndication\OutletPush;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Distribution panel on the release editor, directly above the content.
 *
 * Everyone who can edit the release sees each selected outlet's live state and
 * can refresh it; only administrators can force an outlet to pull the release.
 * The panel loads its state over AJAX: right after a save it waits for the
 * real-time outlet push to finish, otherwise it re-checks every outlet link.
 */
final class ForceSyncAdmin {
	private const NONCE_ACTION = 'hprwc_force_sync_admin';

	private const AJAX_RESOLVE = 'hprwc_resolve_publications';

	private const AJAX_FORCE = 'hprwc_force_sync_publication';

	public function __construct(
		private PublicationResolver $resolver,
		private CredentialRepository $credentials,
		private DestinationRegistry $destinations,
		private ForceSyncService $force_sync
	) {}

	public function register(): void {
		$this->remove_legacy_hooks();
		add_action( 'admin_init', [ $this, 'remove_legacy_hooks' ], 1 );
		add_action( 'admin_init', [ $this, 'remove_legacy_hooks' ], 999 );
		add_action( 'wp_ajax_hpr_force_sync_publication', [ $this, 'reject_legacy_ajax' ], -999 );
		add_action( 'wp_ajax_hpr_bulk_publication_all', [ $this, 'reject_legacy_ajax' ], -999 );
		add_action( 'add_meta_boxes', [ $this, 'remove_legacy_meta_box' ], 100 );
		add_action( 'edit_form_after_title', [ $this, 'render_panel' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_' . self::AJAX_RESOLVE, [ $this, 'ajax_resolve_publications' ] );
		add_action( 'wp_ajax_' . self::AJAX_FORCE, [ $this, 'ajax_force_publication' ] );
	}

	/** The legacy Code Snippets box stays stored for rollback but never renders. */
	public function remove_legacy_meta_box(): void {
		$this->remove_legacy_hooks();
		remove_meta_box( 'hpr-force-sync', 'post', 'normal' );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		global $post;
		if ( ! $screen || 'post' !== $screen->post_type || ! $post instanceof \WP_Post || ! $this->can_view( (int) $post->ID ) ) {
			return;
		}

		wp_enqueue_style( 'hprwc-force-sync-admin', HPRWC_URL . 'assets/admin.css', [], HPRWC_VERSION );
		wp_enqueue_script( 'hprwc-force-sync-admin', HPRWC_URL . 'assets/admin.js', [ 'jquery' ], HPRWC_VERSION, true );
		wp_localize_script(
			'hprwc-force-sync-admin',
			'hprwcForceSync',
			[
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE_ACTION ),
				'postId'        => (int) $post->ID,
				'autoSelectAll' => 'post-new.php' === $hook_suffix && $this->is_admin_user(),
				'actions'       => [
					'resolve' => self::AJAX_RESOLVE,
					'force'   => self::AJAX_FORCE,
				],
			]
		);
	}

	public function render_panel( \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || ! taxonomy_exists( PublicationResolver::TAXONOMY ) || ! $this->can_view( (int) $post->ID ) ) {
			return;
		}

		$is_admin = $this->is_admin_user();
		?>
		<section class="hprwc-dist" data-hprwc-panel data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>" data-hprwc-admin="<?php echo $is_admin ? '1' : '0'; ?>" aria-busy="true">
			<header class="hprwc-dist__head">
				<div class="hprwc-dist__title">
					<h2>Distribution</h2>
					<p class="hprwc-dist__summary" data-hprwc-summary aria-live="polite">Loading outlet links…</p>
				</div>
				<div class="hprwc-dist__actions">
					<button type="button" class="hprwc-btn" data-hprwc-run="check" disabled>
						<span class="hprwc-btn__icon dashicons dashicons-update" aria-hidden="true"></span>Refresh
					</button>
					<?php if ( $is_admin ) : ?>
						<button type="button" class="hprwc-btn hprwc-btn--primary" data-hprwc-run="force" disabled>
							<span class="hprwc-btn__icon dashicons dashicons-upload" aria-hidden="true"></span>Force sync
						</button>
					<?php endif; ?>
				</div>
			</header>

			<div class="hprwc-dist__notice" data-hprwc-notice hidden></div>
			<?php if ( $is_admin ) : ?>
				<ul class="hprwc-dist__warnings" data-hprwc-warnings hidden></ul>
			<?php endif; ?>

			<div class="hprwc-dist__body">
				<div class="hprwc-dist__loader" data-hprwc-loader><span class="hprwc-spin" aria-hidden="true"></span>Loading outlet links…</div>
				<?php if ( $is_admin ) : ?>
					<div class="hprwc-dist__select" data-hprwc-select-bar hidden>
						<button type="button" class="button-link" data-hprwc-select="all">Select all</button>
						<span aria-hidden="true">·</span>
						<button type="button" class="button-link" data-hprwc-select="none">Select none</button>
					</div>
				<?php endif; ?>
				<ul class="hprwc-dist__list" data-hprwc-rows></ul>
			</div>
		</section>
		<?php
	}

	public function ajax_resolve_publications(): void {
		$this->verify_nonce();

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || ! $this->can_view( $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}

		$saved = $this->destinations->enforce( $this->resolver->resolve_post( $post_id ) );
		if ( isset( $_POST['term_ids'] ) ) {
			$raw_term_ids = is_array( $_POST['term_ids'] ) ? wp_unslash( $_POST['term_ids'] ) : [];
			$term_ids     = array_values( array_unique( array_filter( array_map( 'absint', $raw_term_ids ) ) ) );
			sort( $term_ids, SORT_NUMERIC );
			$preview = $this->destinations->enforce( $this->resolver->resolve_term_ids( $term_ids ) );
		} else {
			$preview = $saved;
		}

		$saved_ids  = array_map( static fn( array $target ): int => (int) $target['publication_id'], $saved['targets'] );
		$stored     = get_post_meta( $post_id, ForceSyncService::RESULT_META, true );
		$stored     = is_array( $stored ) ? $stored : [];
		$published  = 'publish' === $post->post_status;
		$is_admin   = $this->is_admin_user();
		$targets    = [];

		foreach ( $preview['targets'] as $target ) {
			$publication_id = (int) $target['publication_id'];
			$is_saved       = in_array( $publication_id, $saved_ids, true );
			$result         = $is_saved && isset( $stored[ $publication_id ] ) && is_array( $stored[ $publication_id ] ) ? $stored[ $publication_id ] : null;
			$targets[]      = [
				'publication_id' => $publication_id,
				'title'          => (string) $target['title'],
				'domain'         => (string) $target['domain'],
				'live_url'       => $this->resolver->build_live_url( $target, $post ),
				'saved'          => $is_saved,
				'status'         => $is_saved ? $this->present_status( $result ) : $this->state( 'missing', 'Not created', 'Update the release to send it here.' ),
			];
		}

		wp_send_json_success(
			[
				'targets'      => $targets,
				'warnings'     => $is_admin ? $preview['warnings'] : [],
				'is_saved'     => $preview['term_ids'] === $saved['term_ids'],
				'published'    => $published,
				'push_pending' => $published && false !== wp_next_scheduled( OutletPush::PUSH_HOOK, [ $post_id ] ),
				'can_force'    => $is_admin && $published && $this->credentials->exists(),
			]
		);
	}

	public function ajax_force_publication(): void {
		$this->verify_nonce();

		$post_id        = absint( $_POST['post_id'] ?? 0 );
		$publication_id = absint( $_POST['publication_id'] ?? 0 );
		$mode           = sanitize_key( wp_unslash( $_POST['mode'] ?? 'force' ) );

		if ( ! in_array( $mode, [ 'check', 'force' ], true ) ) {
			wp_send_json_error( [ 'error_code' => 'invalid_mode', 'message' => 'Invalid Force Sync mode.' ], 400 );
		}

		$allowed = 'force' === $mode ? $this->is_admin_user() && $this->can_view( $post_id ) : $this->can_view( $post_id );
		if ( ! $allowed ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}

		$result = $this->force_sync->run( $post_id, $publication_id, $mode );
		$status = $this->present_status( $result );
		if ( 'unassigned_publication' === ( $result['error_code'] ?? '' ) ) {
			wp_send_json_error( [ 'status' => $status, 'message' => (string) $result['message'] ], 403 );
		}

		wp_send_json_success( [ 'publication_id' => $publication_id, 'status' => $status ] );
	}

	/**
	 * One display state for a stored or fresh Force Sync result.
	 *
	 * @param array<string,mixed>|null $result
	 * @return array{state:string,label:string,detail:string}
	 */
	private function present_status( ?array $result ): array {
		if ( empty( $result ) ) {
			return $this->state( 'unchecked', 'Not checked', '' );
		}

		$when = '';
		if ( ! empty( $result['time_gmt'] ) ) {
			$time = strtotime( (string) $result['time_gmt'] . ' UTC' );
			$when = false !== $time ? 'Checked ' . human_time_diff( $time ) . ' ago' : '';
		}
		$http = (int) ( $result['public_status'] ?? 0 );
		// Every checked row shows the outlet server's actual answer to the release URL.
		$response = $http > 0 ? 'Server response: HTTP ' . $http : 'Server response: none (' . (string) ( $result['message'] ?? 'no answer' ) . ')';
		$detail = static fn( string ...$parts ): string => implode( ' · ', array_filter( $parts, static fn( string $part ): bool => '' !== $part ) );

		if ( 'post_not_published' === ( $result['error_code'] ?? '' ) ) {
			return $this->state( 'waiting', 'Waiting', 'Sent once the release is published.' );
		}
		if ( ! empty( $result['ok'] ) ) {
			return $this->state( 'live', 'Live', $detail( $response, $when ) );
		}
		if ( isset( $result['endpoint_ok'] ) && empty( $result['endpoint_ok'] ) ) {
			$endpoint = ! empty( $result['endpoint_http'] ) ? 'Distributor answered HTTP ' . (int) $result['endpoint_http'] : '';
			return $this->state( 'error', 'Sync failed', $detail( $endpoint, (string) ( $result['message'] ?? '' ), $response, $when ) );
		}
		if ( in_array( $http, [ 404, 410 ], true ) ) {
			return $this->state( 'missing', 'Not created', $detail( $response, $when ) );
		}
		if ( 200 === $http && empty( $result['title_found'] ) ) {
			return $this->state( 'missing', 'Not created', $detail( $response, 'page loads without the release title', $when ) );
		}

		return $this->state( 'error', 'Error', $detail( $response, $when ) );
	}

	/** @return array{state:string,label:string,detail:string} */
	private function state( string $state, string $label, string $detail ): array {
		return [ 'state' => $state, 'label' => $label, 'detail' => sanitize_text_field( $detail ) ];
	}

	private function verify_nonce(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'The editor session expired. Reload the page and try again.' ], 403 );
		}
	}

	private function is_admin_user(): bool {
		return current_user_can( 'manage_options' );
	}

	private function can_view( int $post_id ): bool {
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Disable executable legacy callbacks while the snippets remain stored for rollback.
	 */
	public function remove_legacy_hooks(): void {
		remove_action( 'add_meta_boxes', 'hpr_force_sync_admin_register_metabox' );
		remove_action( 'add_meta_boxes', '\\hpr_distributor\\hpr_force_sync_admin_register_metabox' );
		remove_action( 'wp_ajax_hpr_force_sync_publication', 'hpr_force_sync_admin_ajax_publication' );
		remove_action( 'wp_ajax_hpr_force_sync_publication', '\\hpr_distributor\\hpr_force_sync_admin_ajax_publication' );
		remove_action( 'wp_ajax_hpr_bulk_publication_all', 'hpr_bulk_publication_all_callback' );
	}

	public function reject_legacy_ajax(): void {
		wp_send_json_error(
			[
				'error_code' => 'legacy_action_disabled',
				'message'    => 'This legacy action is disabled. Reload the post editor and use Hexa PR Wire Core.',
			],
			410
		);
	}
}
