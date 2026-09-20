<?php

namespace HexaPrWire\Core\Admin;

use HexaPrWire\Core\CredentialRepository;
use HexaPrWire\Core\DestinationRegistry;
use HexaPrWire\Core\ForceSyncService;
use HexaPrWire\Core\PublicationResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ], 100, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_' . self::AJAX_RESOLVE, [ $this, 'ajax_resolve_publications' ] );
		add_action( 'wp_ajax_' . self::AJAX_FORCE, [ $this, 'ajax_force_publication' ] );
	}

	public function register_meta_box( string $post_type, \WP_Post $post ): void {
		$this->remove_legacy_hooks();
		if ( 'post' !== $post_type || ! taxonomy_exists( PublicationResolver::TAXONOMY ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Replace the legacy Code Snippets box while it remains available for rollback.
		remove_meta_box( 'hpr-force-sync', 'post', 'normal' );
		add_meta_box(
			'hpr-force-sync',
			'Hexa PR Wire Force Sync',
			[ $this, 'render_meta_box' ],
			'post',
			'normal',
			'high'
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->post_type || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $post;
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : absint( $_GET['post'] ?? 0 );

		wp_enqueue_style(
			'hprwc-force-sync-admin',
			HPRWC_URL . 'assets/admin.css',
			[],
			HPRWC_VERSION
		);

		wp_enqueue_script(
			'hprwc-force-sync-admin',
			HPRWC_URL . 'assets/admin.js',
			[ 'jquery' ],
			HPRWC_VERSION,
			true
		);

		wp_localize_script(
			'hprwc-force-sync-admin',
			'hprwcForceSync',
			[
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
				'postId'          => $post_id,
				'postStatus'      => $post instanceof \WP_Post ? $post->post_status : '',
				'credentialReady' => $this->credentials->exists(),
				'autoSelectAll'   => 'post-new.php' === $hook_suffix,
				'actions'         => [
					'resolve' => self::AJAX_RESOLVE,
					'force'   => self::AJAX_FORCE,
				],
				'messages'        => [
					'ajaxFailed' => 'The request failed. Reload the editor and try again.',
					'noRows'     => 'No eligible publication sources are selected.',
				],
			]
		);
	}

	public function render_meta_box( \WP_Post $post ): void {
		$resolved       = $this->destinations->enforce( $this->resolver->resolve_post( (int) $post->ID ) );
		$stored_results = get_post_meta( $post->ID, ForceSyncService::RESULT_META, true );
		$stored_results = is_array( $stored_results ) ? $stored_results : [];
		$is_published   = 'publish' === $post->post_status;
		$has_targets    = ! empty( $resolved['targets'] );
		$can_force      = $is_published && $has_targets && $this->credentials->exists();
		$can_check      = $is_published && $has_targets;
		?>
		<div class="hprwc-force-sync-panel" data-hprwc-panel data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-hprwc-can-force="<?php echo $can_force ? '1' : '0'; ?>" data-hprwc-can-check="<?php echo $can_check ? '1' : '0'; ?>" data-hprwc-has-targets="<?php echo $has_targets ? '1' : '0'; ?>">
			<p class="hprwc-description">Only eligible destinations assigned in this post's <strong>Publications</strong> taxonomy appear below. The server validates that assignment again before every request.</p>

			<div class="notice notice-warning inline hprwc-unsaved-notice" data-hprwc-unsaved-notice hidden>
				<p><strong>Unsaved publication changes.</strong> This is a live preview. Update the post before forcing or checking these destinations.</p>
			</div>

			<?php if ( ! $is_published ) : ?>
				<div class="notice notice-info inline"><p>Publish this press release before using Force Sync.</p></div>
			<?php endif; ?>

			<?php if ( ! $this->credentials->is_store_available() ) : ?>
				<div class="notice notice-error inline"><p>Hexa Credential Vault is unavailable. Force Sync is disabled until the shared Hexa Core runtime is loaded.</p></div>
			<?php elseif ( ! $this->credentials->exists() ) : ?>
				<div class="notice notice-error inline"><p>The encrypted Force Sync credential is not configured. Link checking remains available, but force requests are disabled.</p></div>
			<?php endif; ?>

			<div class="hprwc-warning-box" data-hprwc-warning-box <?php echo empty( $resolved['warnings'] ) ? 'hidden' : ''; ?>>
				<strong>Publication mapping warnings</strong>
				<ul data-hprwc-warnings>
					<?php foreach ( $resolved['warnings'] as $warning ) : ?>
						<li><strong><?php echo esc_html( (string) $warning['term_name'] ); ?>:</strong> <?php echo esc_html( (string) $warning['message'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="hprwc-actions">
				<button type="button" class="button button-primary" data-hprwc-run="force" <?php disabled( ! $can_force ); ?>>Force selected sources</button>
				<button type="button" class="button" data-hprwc-run="check" <?php disabled( ! $can_check ); ?>>Check selected links</button>
				<span class="hprwc-action-divider" aria-hidden="true"></span>
				<button type="button" class="button button-small" data-hprwc-select="all" <?php disabled( ! $has_targets ); ?>>Select all</button>
				<button type="button" class="button button-small" data-hprwc-select="none" <?php disabled( ! $has_targets ); ?>>Select none</button>
				<span class="spinner" data-hprwc-spinner></span>
				<span class="hprwc-progress" data-hprwc-progress aria-live="polite"></span>
			</div>

			<div class="hprwc-table-wrap">
				<table class="widefat striped hprwc-publication-table">
					<thead>
						<tr>
							<th class="check-column"><span class="screen-reader-text">Select source</span></th>
							<th>Publication</th>
							<th>Expected public URL</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody data-hprwc-rows>
						<?php $this->render_rows( $post, $resolved['targets'], $stored_results ); ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	public function ajax_resolve_publications(): void {
		$this->verify_nonce();

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $this->can_edit_post( $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}

		$raw_term_ids = isset( $_POST['term_ids'] ) && is_array( $_POST['term_ids'] ) ? wp_unslash( $_POST['term_ids'] ) : [];
		$term_ids     = array_values( array_unique( array_filter( array_map( 'absint', $raw_term_ids ) ) ) );
		sort( $term_ids, SORT_NUMERIC );

		$preview  = $this->destinations->enforce( $this->resolver->resolve_term_ids( $term_ids ) );
		$saved    = $post_id > 0 ? $this->destinations->enforce( $this->resolver->resolve_post( $post_id ) ) : [ 'term_ids' => [] ];
		$is_saved = $preview['term_ids'] === $saved['term_ids'];
		$post     = $post_id > 0 ? get_post( $post_id ) : null;
		$stored   = $is_saved && $post_id > 0 ? get_post_meta( $post_id, ForceSyncService::RESULT_META, true ) : [];
		$stored   = is_array( $stored ) ? $stored : [];

		wp_send_json_success(
			[
				'targets'   => $this->present_targets( $preview['targets'], $post, $stored ),
				'warnings'  => $preview['warnings'],
				'is_saved'  => $is_saved,
				'published' => $post instanceof \WP_Post && 'publish' === $post->post_status,
				'can_force' => $is_saved && $post instanceof \WP_Post && 'publish' === $post->post_status && ! empty( $preview['targets'] ) && $this->credentials->exists(),
				'can_check' => $is_saved && $post instanceof \WP_Post && 'publish' === $post->post_status && ! empty( $preview['targets'] ),
			]
		);
	}

	public function ajax_force_publication(): void {
		$this->verify_nonce();

		$post_id        = absint( $_POST['post_id'] ?? 0 );
		$publication_id = absint( $_POST['publication_id'] ?? 0 );
		$mode           = sanitize_key( wp_unslash( $_POST['mode'] ?? 'force' ) );

		if ( ! $this->can_edit_post( $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}

		if ( ! in_array( $mode, [ 'check', 'force' ], true ) ) {
			wp_send_json_error( [ 'error_code' => 'invalid_mode', 'message' => 'Invalid Force Sync mode.' ], 400 );
		}

		$result = $this->force_sync->run( $post_id, $publication_id, $mode );
		if ( empty( $result['ok'] ) ) {
			$status = 'unassigned_publication' === ( $result['error_code'] ?? '' ) ? 403 : 502;
			wp_send_json_error( $result, $status );
		}

		wp_send_json_success( $result );
	}

	/**
	 * @param array<int, array<string, mixed>> $targets Resolved targets.
	 * @param array<int|string, mixed>          $stored Stored status map.
	 */
	private function render_rows( \WP_Post $post, array $targets, array $stored ): void {
		if ( empty( $targets ) ) {
			echo '<tr class="hprwc-empty-row"><td colspan="4">No eligible publication sources are selected.</td></tr>';
			return;
		}

		foreach ( $targets as $target ) {
			$publication_id = (int) $target['publication_id'];
			$live_url       = $this->resolver->build_live_url( $target, $post );
			$result         = isset( $stored[ $publication_id ] ) && is_array( $stored[ $publication_id ] ) ? $stored[ $publication_id ] : null;
			?>
			<tr data-hprwc-row data-publication-id="<?php echo esc_attr( $publication_id ); ?>">
				<th class="check-column"><input type="checkbox" data-hprwc-source-check value="<?php echo esc_attr( $publication_id ); ?>" checked></th>
				<td><strong><?php echo esc_html( (string) $target['title'] ); ?></strong><br><code><?php echo esc_html( (string) $target['domain'] ); ?></code></td>
				<td><a href="<?php echo esc_url( $live_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $live_url ); ?></a></td>
				<td class="hprwc-row-status" data-hprwc-row-status><?php echo esc_html( $this->status_label( $result ) ); ?></td>
			</tr>
			<?php
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $targets Resolved targets.
	 * @param array<int|string, mixed>          $stored Stored result map.
	 * @return array<int, array<string, mixed>>
	 */
	private function present_targets( array $targets, ?\WP_Post $post, array $stored = [] ): array {
		$presented = [];

		foreach ( $targets as $target ) {
			$publication_id = (int) $target['publication_id'];
			$result         = isset( $stored[ $publication_id ] ) && is_array( $stored[ $publication_id ] ) ? $stored[ $publication_id ] : null;
			$presented[] = [
				'publication_id' => $publication_id,
				'title'          => (string) $target['title'],
				'domain'         => (string) $target['domain'],
				'live_url'       => $post instanceof \WP_Post ? $this->resolver->build_live_url( $target, $post ) : '',
				'status'         => $this->status_label( $result ),
			];
		}

		return $presented;
	}

	private function status_label( ?array $result ): string {
		if ( empty( $result ) ) {
			return 'Not checked';
		}

		$parts = [ ! empty( $result['ok'] ) ? 'OK' : 'Issue' ];
		if ( ! empty( $result['time_gmt'] ) ) {
			$parts[] = (string) $result['time_gmt'] . ' GMT';
		}
		if ( isset( $result['public_status'] ) ) {
			$parts[] = 'HTTP ' . (int) $result['public_status'];
		}
		if ( ! empty( $result['message'] ) ) {
			$parts[] = (string) $result['message'];
		}

		return implode( ' — ', $parts );
	}

	private function verify_nonce(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'The editor session expired. Reload the page and try again.' ], 403 );
		}
	}

	private function can_edit_post( int $post_id ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return $post_id > 0 ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
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
