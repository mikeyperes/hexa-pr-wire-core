<?php

namespace HexaPrWire\Core\Admin;

use Hexa\PluginCore\WpAdminAjax\AjaxGuard;
use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Contracts\PublicationRepository;
use HexaPrWire\Core\Domain\Publication\ConnectionType;
use HexaPrWire\Core\DestinationRegistry;
use HexaPrWire\Core\Syndication\OutletClient;
use HexaPrWire\Core\Syndication\PluginReleases;

/**
 * Hexa PR Wire → Publications: one live card per publication.
 *
 * The page renders only each publication's record; every card then loads its
 * site's state over AJAX (Distributor health + Hexa plugin versions) and can
 * update one plugin at a time through the outlet's Distributor.
 */
final class PublicationConnections implements Module {
	private const NONCE = 'hprwc_publications';
	private const ACTIONS = [ 'status' => 'hprwc_publication_status', 'update' => 'hprwc_publication_update' ];

	/** Plugins shown as tiles, in order; `always` keeps a "Not installed" tile on Internal sites. */
	private const PLUGINS = [
		'hws-base-tools'              => [ 'label' => 'HWS Base Tools', 'repo' => 'mikeyperes/hws-base-tools', 'always' => true ],
		'hexa-pr-wire-distributor'    => [ 'label' => 'Hexa PR Wire Distributor', 'repo' => 'mikeyperes/hexa-pr-wire-distributor', 'always' => true ],
		'smp-publication-integration' => [ 'label' => 'SMP Publication Integration', 'repo' => 'mikeyperes/smp-publication-integration', 'always' => true ],
		'smp-verified-profiles'       => [ 'label' => 'SMP Verified Profiles', 'repo' => 'mikeyperes/smp-verified-profiles', 'always' => false ],
	];

	public function __construct(
		private PublicationRepository $publications,
		private DestinationRegistry $destinations,
		private OutletClient $outlets,
		private PluginReleases $releases
	) {}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTIONS['status'], [ $this, 'ajax_status' ] );
		add_action( 'wp_ajax_' . self::ACTIONS['update'], [ $this, 'ajax_update' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'hexa-pr-wire-core' ) ) {
			return;
		}
		wp_enqueue_style( 'hprwc-publications', HPRWC_URL . 'assets/admin/publications.css', [], HPRWC_VERSION );
		wp_enqueue_script( 'hprwc-publications', HPRWC_URL . 'assets/admin/publications.js', [ 'jquery' ], HPRWC_VERSION, true );
		wp_localize_script( 'hprwc-publications', 'hprwcPublications', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'actions' => self::ACTIONS,
			'types'   => ConnectionType::LABELS,
		] );
	}

	public function render(): void {
		$cards = $this->cards();
		$counts = array_count_values( array_map( static fn( array $card ): string => $card['type'] ?: 'unset', $cards ) );
		?>
		<div class="hprwc-pubs" data-hprwc-pubs>
			<header class="hprwc-pubs__bar">
				<div>
					<h2>Publications</h2>
					<p class="hprwc-pubs__summary" data-pubs-summary aria-live="polite">Checking <?php echo count( $cards ); ?> publications…</p>
				</div>
				<div class="hprwc-pubs__tools">
					<input type="search" class="hprwc-pubs__search" placeholder="Search publications" data-pubs-search>
					<button type="button" class="button button-primary" data-pubs-refresh-all>Check all</button>
				</div>
			</header>
			<nav class="hprwc-pubs__filters" aria-label="Filter publications">
				<button type="button" class="is-active" data-pubs-filter="all">All <span><?php echo count( $cards ); ?></span></button>
				<?php foreach ( ConnectionType::LABELS as $type => $label ) : ?>
					<button type="button" data-pubs-filter="<?php echo esc_attr( $type ); ?>" title="<?php echo esc_attr( ConnectionType::DESCRIPTIONS[ $type ] ); ?>"><?php echo esc_html( $label ); ?> <span><?php echo (int) ( $counts[ $type ] ?? 0 ); ?></span></button>
				<?php endforeach; ?>
				<button type="button" data-pubs-filter="attention">Needs attention <span data-pubs-attention-count>0</span></button>
			</nav>
			<div class="hprwc-pubs__grid">
				<?php foreach ( $cards as $card ) : ?>
					<article class="hprwc-pcard" data-pub='<?php echo esc_attr( (string) wp_json_encode( $card ) ); ?>' data-type="<?php echo esc_attr( $card['type'] ?: 'unset' ); ?>" data-state="loading">
						<header class="hprwc-pcard__head">
							<span class="hprwc-light" aria-hidden="true"></span>
							<div class="hprwc-pcard__title">
								<a href="<?php echo esc_url( (string) get_edit_post_link( $card['id'] ) ); ?>"><?php echo esc_html( $card['title'] ); ?></a>
								<?php if ( '' !== $card['url'] ) : ?><a class="hprwc-pcard__domain" href="<?php echo esc_url( $card['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $card['domain'] ); ?></a><?php endif; ?>
							</div>
							<span class="hprwc-type hprwc-type--<?php echo esc_attr( $card['type'] ?: 'unset' ); ?>" title="<?php echo esc_attr( ConnectionType::DESCRIPTIONS[ $card['type'] ] ?? 'Set Connection Type on the publication record.' ); ?>"><?php echo esc_html( ConnectionType::LABELS[ $card['type'] ] ?? 'Not set' ); ?></span>
							<button type="button" class="hprwc-icon-btn" data-pub-refresh title="Check again" aria-label="Check <?php echo esc_attr( $card['title'] ); ?> again"><span class="dashicons dashicons-update"></span></button>
						</header>
						<div class="hprwc-pcard__body" data-pub-body><div class="hprwc-pcard__loading"><span class="hprwc-spin"></span>Checking site…</div></div>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/** One publication's live state: site health, Hexa plugin versions and recent releases. */
	public function ajax_status(): void {
		$card = $this->guarded_card();
		if ( ! ConnectionType::is_connected( $card['type'] ) ) {
			wp_send_json_success( [ 'connected' => false, 'site' => $this->site_check( $card['url'] ) ] );
		}

		$started = microtime( true );
		$health = $this->outlets->health( $card['host'] );
		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( ! $health['ok'] ) {
			wp_send_json_success( [ 'connected' => true, 'reachable' => false, 'http' => $health['status'], 'ms' => $ms, 'message' => $this->failure( $health['status'], $health['message'] ) ] );
		}

		$plugins = $this->outlets->plugins( $card['host'] );
		$report = $health['data'];
		wp_send_json_success( [
			'connected'      => true,
			'reachable'      => true,
			'http'           => $health['status'],
			'ms'             => $ms,
			'distributor'    => (string) ( $report['versions']['distributor'] ?? '' ),
			'wordpress'      => (string) ( $report['versions']['wordpress'] ?? '' ),
			'feed_enabled'   => ! empty( $report['feed']['enabled'] ),
			'last_sync'      => $this->gmt( (string) ( $report['last_pull']['ended_gmt'] ?? '' ) ),
			'last_sync_error'=> (string) ( $report['last_pull']['error'] ?? '' ),
			'recent'         => array_map( fn( array $post ): array => [ 'title' => $this->text( (string) $post['title'] ), 'url' => esc_url_raw( (string) $post['url'] ), 'date' => $this->gmt( (string) $post['date_gmt'] ) ], array_slice( (array) ( $report['recent_posts'] ?? ( ! empty( $report['last_post'] ) ? [ $report['last_post'] ] : [] ) ), 0, 5 ) ),
			'plugins_route'  => $plugins['ok'],
			'remote_updates' => $plugins['ok'] && ! empty( $plugins['data']['result']['remote_updates'] ),
			'plugins'        => $plugins['ok'] ? $this->plugin_tiles( (array) ( $plugins['data']['result']['plugins'] ?? [] ), $card['type'] ) : [],
		] );
	}

	/** Update one Hexa plugin on one publication's site. */
	public function ajax_update(): void {
		$card = $this->guarded_card();
		$plugin = sanitize_key( (string) wp_unslash( $_POST['plugin'] ?? '' ) );
		if ( ! ConnectionType::is_connected( $card['type'] ) || ! isset( self::PLUGINS[ $plugin ] ) ) {
			wp_send_json_error( [ 'message' => 'This plugin cannot be updated on this publication.' ], 400 );
		}
		$result = $this->outlets->update_plugin( $card['host'], $plugin );
		if ( ! $result['ok'] ) {
			wp_send_json_error( [ 'message' => $this->failure( $result['status'], $result['message'] ) ], 502 );
		}
		wp_send_json_success( (array) ( $result['data']['result'] ?? [] ) );
	}

	/** @return array<string,mixed> */
	private function guarded_card(): array {
		AjaxGuard::require_nonce_or_error( self::NONCE );
		AjaxGuard::require_capability_or_error( 'manage_options' );
		$id = absint( $_POST['publication_id'] ?? 0 );
		foreach ( $this->cards() as $card ) {
			if ( $card['id'] === $id ) {
				if ( ConnectionType::is_connected( $card['type'] ) && '' === $card['host'] ) {
					wp_send_json_error( [ 'message' => 'This publication has no approved press-release host.' ], 400 );
				}
				return $card;
			}
		}
		wp_send_json_error( [ 'message' => 'Unknown publication.' ], 404 );
	}

	/** @return array<int,array<string,mixed>> */
	private function cards(): array {
		$order = array_flip( array_keys( ConnectionType::LABELS ) );
		$cards = [];
		foreach ( $this->publications->all( [ 'status' => true ] ) as $publication ) {
			$host = strtolower( (string) wp_parse_url( (string) $publication['prefix'], PHP_URL_HOST ) );
			$cards[] = [
				'id'     => (int) $publication['id'],
				'title'  => (string) $publication['title'],
				'url'    => (string) $publication['url'],
				'domain' => preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( (string) $publication['url'], PHP_URL_HOST ) ) ),
				'type'   => ConnectionType::of( $publication ),
				'host'   => '' !== $host && $this->destinations->is_approved( (int) $publication['id'], $host ) ? $host : '',
			];
		}
		usort( $cards, static fn( array $a, array $b ): int => [ $order[ $a['type'] ] ?? 9, strtolower( $a['title'] ) ] <=> [ $order[ $b['type'] ] ?? 9, strtolower( $b['title'] ) ] );
		return $cards;
	}

	/**
	 * Tiles for the Hexa plugins on one site. A missing plugin gets a tile only
	 * on our own (Internal) sites; partner sites run just Distributor by design.
	 *
	 * @param array<int,array<string,mixed>> $installed
	 */
	private function plugin_tiles( array $installed, string $type ): array {
		$by_slug = array_column( $installed, null, 'slug' );
		$tiles = [];
		foreach ( self::PLUGINS as $slug => $meta ) {
			$plugin = $by_slug[ $slug ] ?? null;
			if ( null === $plugin && ( ! $meta['always'] || 'internal' !== $type ) ) {
				continue;
			}
			$version = (string) ( $plugin['version'] ?? '' );
			$latest = null !== $plugin ? $this->releases->latest( $meta['repo'], basename( (string) $plugin['file'] ) ) : '';
			$tiles[] = [
				'slug'      => $slug,
				'label'     => $meta['label'],
				'installed' => null !== $plugin,
				'active'    => ! empty( $plugin['active'] ),
				'version'   => $version,
				'latest'    => $latest,
				'outdated'  => '' !== $latest && version_compare( $latest, $version, '>' ),
			];
		}
		return $tiles;
	}

	/** @return array{http:int,ms:int} Outside check (GET; some sites refuse HEAD) for sites without a plugin connection. */
	private function site_check( string $url ): array {
		if ( '' === $url ) {
			return [ 'http' => 0, 'ms' => 0 ];
		}
		$started = microtime( true );
		$response = wp_remote_get( $url, [ 'timeout' => 15, 'redirection' => 5, 'limit_response_size' => 65536 ] );
		return [ 'http' => is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response ), 'ms' => (int) round( ( microtime( true ) - $started ) * 1000 ) ];
	}

	private function failure( int $status, string $message ): string {
		return match ( true ) {
			0 === $status => 'Unreachable: ' . $message,
			in_array( $status, [ 401, 403 ], true ) => 'Token rejected (HTTP ' . $status . ')',
			404 === $status => 'Distributor too old for remote checks (HTTP 404). Update Distributor once on the site.',
			default => 'HTTP ' . $status . ': ' . $message,
		};
	}

	private function gmt( string $gmt ): string {
		$time = '' !== $gmt ? strtotime( $gmt . ' UTC' ) : false;
		return false !== $time ? gmdate( 'c', $time ) : '';
	}

	private function text( string $value ): string {
		return html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
