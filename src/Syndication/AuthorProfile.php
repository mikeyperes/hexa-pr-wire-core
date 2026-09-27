<?php

namespace HexaPrWire\Core\Syndication;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Support\Activity;

/**
 * The Hexa PR Wire author profile's single source: the `hexaprwire` user on
 * hexaprwire.com. Published at GET /wp-json/hprwc/v1/author for every
 * Distributor; editing that user (or the admin button) pushes a refresh.
 */
final class AuthorProfile implements Module {
	public const LOGIN = 'hexaprwire';
	public const PUSH_HOOK = 'hprwc_push_author';
	public const LAST_PUSH_OPTION = 'hprwc_author_push_last';
	private const URL_KEYS = [ 'facebook', 'instagram', 'linkedin', 'x', 'twitter', 'crunchbase', 'muckrack', 'website', 'the_org', 'calendly', 'youtube', 'tiktok', 'wikipedia' ];

	public function __construct( private OutletClient $client ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'profile_update', [ $this, 'profile_updated' ], 20 );
		add_action( self::PUSH_HOOK, [ $this, 'push' ] );
		add_action( 'admin_post_hprwc_push_author', [ $this, 'admin_push' ] );
	}

	public function routes(): void {
		register_rest_route(
			'hprwc/v1',
			'/author',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn() => null === ( $profile = $this->profile() ) ? new \WP_Error( 'hprwc_author_missing', 'The Hexa PR Wire author does not exist.', [ 'status' => 404 ] ) : rest_ensure_response( $profile ),
				'permission_callback' => '__return_true',
			]
		);
	}

	/** @return array<string,mixed>|null */
	public function profile(): ?array {
		$user = get_user_by( 'login', self::LOGIN );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}
		$urls = [];
		foreach ( self::URL_KEYS as $key ) {
			$url = esc_url_raw( trim( (string) get_user_meta( $user->ID, 'urls_' . $key, true ) ) );
			if ( '' !== $url ) {
				$urls[ 'twitter' === $key ? 'x' : $key ] = $url;
			}
		}
		return [
			'login'        => $user->user_login,
			'display_name' => $user->display_name,
			'first_name'   => (string) $user->first_name,
			'last_name'    => (string) $user->last_name,
			'email'        => $user->user_email,
			'user_url'     => $user->user_url,
			'description'  => (string) $user->description,
			'urls'         => $urls,
			'avatar_url'   => $this->avatar_url( (int) $user->ID ),
			'updated_gmt'  => (string) get_user_meta( $user->ID, 'hprwc_author_updated_gmt', true ),
		];
	}

	public function profile_updated( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( $user instanceof \WP_User && self::LOGIN === $user->user_login ) {
			update_user_meta( $user_id, 'hprwc_author_updated_gmt', gmdate( 'c' ) );
			$this->schedule();
		}
	}

	public function admin_push(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hprwc_push_author' ) ) {
			wp_die( 'Invalid request.' );
		}
		$this->schedule();
		wp_safe_redirect( add_query_arg( [ 'page' => 'hexa-pr-wire-core-publications', 'author_push' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function push(): void {
		$results = [];
		foreach ( $this->client->hosts() as $host ) {
			$results[ $host ] = $this->client->command( $host, 'author/refresh' );
		}
		$ok = count( array_filter( $results, static fn( array $result ): bool => $result['ok'] ) );
		update_option( self::LAST_PUSH_OPTION, [ 'time_gmt' => gmdate( 'c' ), 'ok' => $ok, 'total' => count( $results ), 'results' => $results ], false );
		Activity::add( sprintf( 'Author profile pushed to %d of %d outlets.', $ok, count( $results ) ), $ok === count( $results ) ? 'success' : 'warning', [], 'syndication' );
	}

	private function schedule(): void {
		if ( ! wp_next_scheduled( self::PUSH_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::PUSH_HOOK );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/** A photo hosted on hexaprwire.com (outlets only accept that host). */
	private function avatar_url( int $user_id ): string {
		$attachment_id = 0;
		$simple = get_user_meta( $user_id, 'simple_local_avatar', true );
		if ( is_array( $simple ) && ! empty( $simple['media_id'] ) ) {
			$attachment_id = (int) $simple['media_id'];
		}
		if ( $attachment_id < 1 ) {
			$attachment_id = (int) get_user_meta( $user_id, 'wp_user_avatar', true );
		}
		$url = $attachment_id > 0 ? (string) wp_get_attachment_url( $attachment_id ) : '';
		if ( '' === $url ) {
			$candidate = (string) get_avatar_url( $user_id, [ 'size' => 512 ] );
			$url = str_contains( (string) wp_parse_url( $candidate, PHP_URL_HOST ), 'hexaprwire.com' ) ? $candidate : '';
		}
		return esc_url_raw( $url );
	}
}
