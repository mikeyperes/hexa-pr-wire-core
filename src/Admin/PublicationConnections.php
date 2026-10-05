<?php

namespace HexaPrWire\Core\Admin;

use Hexa\PluginCore\WpAdminAjax\AjaxGuard;
use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Contracts\PublicationRepository;
use HexaPrWire\Core\Domain\Publication\ConnectionType;
use HexaPrWire\Core\DestinationRegistry;
use HexaPrWire\Core\ForceSyncService;
use HexaPrWire\Core\PublicationResolver;
use HexaPrWire\Core\Syndication\OutletClient;

/**
 * Hexa PR Wire → Publications: every publication record with how releases reach
 * it, and for Distributor-managed outlets a live health check over AJAX.
 */
final class PublicationConnections implements Module {
	private const AJAX_ACTION = 'hprwc_publication_health';
	private const NONCE = 'hprwc_publication_health';

	public function __construct(
		private PublicationRepository $publications,
		private DestinationRegistry $destinations,
		private OutletClient $outlets
	) {}

	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_health' ] );
	}

	public function render(): void {
		$rows = $this->rows();
		$counts = array_fill_keys( array_keys( ConnectionType::LABELS ), 0 ) + [ '' => 0 ];
		foreach ( $rows as $row ) {
			$counts[ $row['type'] ]++;
		}
		$inactive = count( array_filter( $rows, static fn( array $row ): bool => ! $row['active'] ) );
		?>
		<div class="hprwc-panel hprwc-connections" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<div class="hprwc-conn-head">
				<div>
					<h2>Publications &amp; connections</h2>
					<p class="description">Every publication record, how releases reach it, and a live check of each Distributor-managed outlet.</p>
				</div>
				<button type="button" class="button button-primary" data-conn-refresh><span class="dashicons dashicons-update" aria-hidden="true"></span> Check managed outlets</button>
			</div>

			<div class="hprwc-conn-filters" role="tablist" aria-label="Filter by connection">
				<button type="button" class="is-active" data-conn-filter="all">All <span><?php echo count( $rows ); ?></span></button>
				<?php foreach ( ConnectionType::LABELS as $key => $label ) : ?>
					<button type="button" data-conn-filter="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?> <span><?php echo (int) $counts[ $key ]; ?></span></button>
				<?php endforeach; ?>
				<?php if ( $counts[''] ) : ?>
					<button type="button" data-conn-filter="unset">Not set <span><?php echo (int) $counts['']; ?></span></button>
				<?php endif; ?>
				<?php if ( $inactive ) : ?>
					<button type="button" data-conn-filter="inactive">Inactive <span><?php echo (int) $inactive; ?></span></button>
				<?php endif; ?>
			</div>
			<p class="hprwc-conn-summary" data-conn-summary aria-live="polite"></p>

			<div class="hprwc-conn-table-wrap">
				<table class="hprwc-conn-table">
					<thead>
						<tr>
							<th>Publication</th>
							<th>Connection</th>
							<th>Live status</th>
							<th>Last push from Hexa PR Wire</th>
							<th>Releases</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr data-id="<?php echo (int) $row['id']; ?>" data-type="<?php echo esc_attr( '' === $row['type'] ? 'unset' : $row['type'] ); ?>" data-active="<?php echo $row['active'] ? '1' : '0'; ?>" data-checkable="<?php echo $row['checkable'] ? '1' : '0'; ?>">
							<td>
								<a class="hprwc-conn-name" href="<?php echo esc_url( (string) get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
								<?php if ( '' !== $row['url'] ) : ?>
									<a class="hprwc-conn-domain" href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['domain'] ); ?></a>
								<?php endif; ?>
								<?php if ( ! $row['active'] ) : ?><span class="hprwc-tag">Inactive</span><?php endif; ?>
							</td>
							<td>
								<span class="hprwc-type hprwc-type--<?php echo esc_attr( '' === $row['type'] ? 'unset' : $row['type'] ); ?>"><?php echo esc_html( '' === $row['type'] ? 'Not set' : ConnectionType::LABELS[ $row['type'] ] ); ?></span>
								<?php if ( '' !== $row['push_host'] ) : ?>
									<div class="hprwc-conn-meta">Push host: <?php echo esc_html( $row['push_host'] ); ?><?php echo $row['approved'] ? '' : ' · <strong>not approved</strong>'; ?></div>
								<?php elseif ( 'managed' === $row['type'] ) : ?>
									<div class="hprwc-conn-meta"><strong>No press-release URL prefix</strong></div>
								<?php endif; ?>
							</td>
							<td data-conn-live>
								<?php if ( $row['checkable'] ) : ?>
									<span class="hprwc-state" data-state="idle">Not checked</span>
								<?php else : ?>
									<span class="hprwc-muted"><?php echo esc_html( $this->no_check_reason( $row ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php $this->render_last_push( $row['last_push'] ); ?></td>
							<td>
								<strong><?php echo number_format_i18n( $row['release_count'] ); ?></strong>
								<?php if ( $row['last_release'] instanceof \WP_Post ) : ?>
									<div class="hprwc-conn-meta">Latest: <a href="<?php echo esc_url( (string) get_edit_post_link( $row['last_release']->ID ) ); ?>"><?php echo esc_html( wp_trim_words( get_the_title( $row['last_release'] ), 8 ) ); ?></a> · <?php echo esc_html( human_time_diff( (int) get_post_time( 'U', true, $row['last_release'] ) ) ); ?> ago</div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="description">Set a publication's connection type on its record (<em>Connection Type</em> field). Managed outlets are checked through Distributor's protected health endpoint.</p>
		</div>
		<?php
	}

	public function ajax_health(): void {
		AjaxGuard::require_nonce_or_error( self::NONCE );
		AjaxGuard::require_capability_or_error( 'manage_options' );
		$id = absint( $_POST['publication_id'] ?? 0 );
		$row = null;
		foreach ( $this->rows() as $candidate ) {
			if ( $candidate['id'] === $id ) {
				$row = $candidate;
				break;
			}
		}
		if ( null === $row || ! $row['checkable'] ) {
			wp_send_json_error( [ 'message' => 'This publication has no approved Distributor host to check.' ], 400 );
		}

		$result = $this->outlets->health( $row['push_host'] );
		$data = $result['data'];
		if ( ! $result['ok'] ) {
			$label = match ( true ) {
				0 === $result['status'] => 'Unreachable',
				in_array( $result['status'], [ 401, 403 ], true ) => 'Token rejected',
				404 === $result['status'] => 'Distributor missing or outdated',
				default => 'Error',
			};
			wp_send_json_success( [ 'state' => 'error', 'label' => $label, 'lines' => [ 0 === $result['status'] ? $result['message'] : 'HTTP ' . $result['status'] . ' · ' . $result['message'] ] ] );
		}

		$versions = (array) ( $data['versions'] ?? [] );
		$feed = (array) ( $data['feed'] ?? [] );
		$last_pull = (array) ( $data['last_pull'] ?? [] );
		$last_post = is_array( $data['last_post'] ?? null ) ? $data['last_post'] : [];
		$lines = [ 'Distributor ' . (string) ( $versions['distributor'] ?? '?' ) . ' · WordPress ' . (string) ( $versions['wordpress'] ?? '?' ) ];
		$lines[] = 'Feed ' . ( empty( $feed['enabled'] ) ? 'off' : 'on' ) . ( ! empty( $feed['outlet'] ) ? ' (' . (string) $feed['outlet'] . ')' : '' );
		if ( ! empty( $last_pull['ended_gmt'] ) ) {
			$lines[] = 'Last pull ' . $this->ago( (string) $last_pull['ended_gmt'] ) . ( ! empty( $last_pull['error'] ) ? ' · error: ' . (string) $last_pull['error'] : '' );
		}
		if ( ! empty( $last_post['date_gmt'] ) ) {
			$lines[] = 'Latest copy ' . $this->ago( (string) $last_post['date_gmt'] ) . ': ' . wp_trim_words( (string) ( $last_post['title'] ?? '' ), 8 );
		}
		$problem = empty( $feed['enabled'] ) || ! empty( $last_pull['error'] );
		wp_send_json_success( [ 'state' => $problem ? 'warn' : 'ok', 'label' => $problem ? 'Connected · attention' : 'Connected', 'lines' => array_map( 'sanitize_text_field', $lines ) ] );
	}

	/** @return array<int,array<string,mixed>> */
	private function rows(): array {
		$terms_by_publication = [];
		$terms = get_terms( [ 'taxonomy' => PublicationResolver::TAXONOMY, 'hide_empty' => false ] );
		foreach ( is_wp_error( $terms ) ? [] : $terms as $term ) {
			$publication_id = $this->publications->mapped_post_id( (int) $term->term_id );
			if ( $publication_id > 0 ) {
				$terms_by_publication[ $publication_id ][] = $term;
			}
		}
		$last_push = get_option( ForceSyncService::OUTLET_LAST_OPTION, [] );
		$last_push = is_array( $last_push ) ? $last_push : [];

		$rows = [];
		foreach ( $this->publications->all() as $publication ) {
			$id = (int) $publication['id'];
			$type = ConnectionType::of( $publication );
			$push_host = strtolower( (string) wp_parse_url( (string) $publication['prefix'], PHP_URL_HOST ) );
			$approved = '' !== $push_host && $this->destinations->is_approved( $id, $push_host );
			$terms = $terms_by_publication[ $id ] ?? [];
			$term_ids = array_map( static fn( \WP_Term $term ): int => (int) $term->term_id, $terms );
			$latest = [] === $term_ids ? [] : get_posts( [
				'post_type' => 'post',
				'post_status' => 'publish',
				'numberposts' => 1,
				'no_found_rows' => true,
				'tax_query' => [ [ 'taxonomy' => PublicationResolver::TAXONOMY, 'field' => 'term_id', 'terms' => $term_ids ] ],
			] );
			$rows[] = [
				'id' => $id,
				'title' => (string) $publication['title'],
				'url' => (string) $publication['url'],
				'domain' => preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( (string) $publication['url'], PHP_URL_HOST ) ) ),
				'active' => (bool) $publication['status'],
				'type' => $type,
				'push_host' => $push_host,
				'approved' => $approved,
				'checkable' => 'managed' === $type && $approved,
				'last_push' => is_array( $last_push[ $id ] ?? null ) ? $last_push[ $id ] : null,
				'release_count' => array_sum( array_map( static fn( \WP_Term $term ): int => (int) $term->count, $terms ) ),
				'last_release' => $latest[0] ?? null,
			];
		}
		$order = array_flip( array_keys( ConnectionType::LABELS ) );
		$key = static fn( array $row ): array => [ $row['active'] ? 0 : 1, $order[ $row['type'] ] ?? 9, strtolower( $row['title'] ) ];
		usort( $rows, static fn( array $a, array $b ): int => $key( $a ) <=> $key( $b ) );
		return $rows;
	}

	private function no_check_reason( array $row ): string {
		return match ( $row['type'] ) {
			'rss' => 'Pulls the RSS feed; no push connection',
			'external' => 'Managed outside Hexa PR Wire',
			'premium' => 'Premium placement; delivered manually',
			'managed' => '' === $row['push_host'] ? 'Set a press-release URL prefix' : 'Push host not approved',
			default => 'Set the connection type',
		};
	}

	private function render_last_push( ?array $push ): void {
		if ( null === $push ) {
			echo '<span class="hprwc-muted">No push recorded yet</span>';
			return;
		}
		echo '<span class="hprwc-state" data-state="' . ( empty( $push['ok'] ) ? 'error' : 'ok' ) . '">' . ( empty( $push['ok'] ) ? 'Failed' : 'Delivered' ) . '</span>';
		echo '<div class="hprwc-conn-meta">' . esc_html( $this->ago( (string) ( $push['time_gmt'] ?? '' ) ) ) . ( ! empty( $push['post_title'] ) ? ' · ' . esc_html( wp_trim_words( (string) $push['post_title'], 8 ) ) : '' ) . '</div>';
		if ( empty( $push['ok'] ) && ! empty( $push['message'] ) ) {
			echo '<div class="hprwc-conn-meta hprwc-conn-error">' . esc_html( (string) $push['message'] ) . '</div>';
		}
	}

	private function ago( string $gmt ): string {
		$time = '' !== $gmt ? strtotime( $gmt . ' UTC' ) : false;
		return false !== $time ? human_time_diff( $time ) . ' ago' : 'unknown time';
	}
}
