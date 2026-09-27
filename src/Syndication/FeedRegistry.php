<?php

namespace HexaPrWire\Core\Syndication;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\CredentialRepository;

/**
 * Hexa PR Wire feeds. The outlet feed `rss_publication?publication=<slug>`
 * returns that outlet's latest releases (default 50, `limit` up to 200, or
 * the exact releases in `slug`), cached until the next release change. An
 * unknown outlet is a 404. The historical `reprocess-*` actions require the
 * shared Hexa PR Wire token.
 */
final class FeedRegistry implements Module {
	private const CUTOFF = '2024-11-20';
	private const VERSION_OPTION = 'hprwc_feed_cache_version';

	public function __construct( private FeedRenderer $renderer, private ?CredentialRepository $credentials = null ) {}

	public function register(): void {
		add_action( 'init', [ $this, 'register_feeds' ], 20 );
		foreach ( [ 'save_post_post', 'trashed_post', 'untrashed_post', 'deleted_post' ] as $hook ) {
			add_action( $hook, [ self::class, 'flush' ] );
		}
		add_action( 'set_object_terms', static function ( $object_id, $terms, $tt_ids, $taxonomy ): void {
			if ( 'publication' === $taxonomy ) {
				self::flush();
			}
		}, 10, 4 );
	}

	public static function flush(): void {
		update_option( self::VERSION_OPTION, (string) microtime( true ), false );
	}

	public function register_feeds(): void {
		add_feed( 'internal-rss', fn() => $this->renderer->render( [] ) );
		add_feed( 'rss_scale_my_publication', fn() => $this->renderer->render( [ 'category_name' => 'scale-my-publication' ] ) );
		add_feed( 'rss_michael_peres', fn() => $this->renderer->render( [ 'category_name' => 'michael-peres' ] ) );
		add_feed( 'rss_publication', [ $this, 'render_publication_feed' ] );
	}

	public function render_publication_feed(): void {
		$publication = isset( $_GET['publication'] ) ? sanitize_text_field( wp_unslash( $_GET['publication'] ) ) : '';
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$terms = array_values( array_filter( array_map( 'sanitize_title', explode( ',', $publication ) ) ) );

		if ( in_array( $action, [ 'reprocess-old', 'reprocess-all' ], true ) ) {
			if ( ! $this->token_valid() ) {
				$this->error( 403, 'The reprocess actions require the Hexa PR Wire token.' );
			}
			if ( 'reprocess-old' === $action ) {
				$this->renderer->render( [ 'date_query' => [ [ 'before' => self::CUTOFF, 'inclusive' => false ] ] ] );
			}
			$this->renderer->render_multiple( [
				array_merge( $this->outlet_query( $terms ), [ 'date_query' => [ [ 'after' => self::CUTOFF, 'inclusive' => true ] ] ] ),
				[ 'date_query' => [ [ 'before' => self::CUTOFF, 'inclusive' => false ] ] ],
			] );
		}

		if ( [] === $terms ) {
			$this->error( 400, 'Name the outlet: ?publication=<outlet-slug>.' );
		}
		foreach ( $terms as $slug ) {
			if ( ! term_exists( $slug, 'publication' ) ) {
				$this->error( 404, 'Unknown Hexa PR Wire outlet: ' . $slug );
			}
		}

		$slugs = isset( $_GET['slug'] ) ? array_values( array_filter( array_map( 'sanitize_title', explode( ',', sanitize_text_field( wp_unslash( $_GET['slug'] ) ) ) ) ) ) : [];
		$limit = isset( $_GET['limit'] ) ? max( 1, min( 200, absint( $_GET['limit'] ) ) ) : 50;
		$query = array_merge( $this->outlet_query( $terms ), [ 'date_query' => [ [ 'after' => self::CUTOFF, 'inclusive' => true ] ] ] );
		if ( [] !== $slugs ) {
			$query['post_name__in'] = $slugs;
		} else {
			$query['posts_per_page'] = $limit;
		}

		$key = 'hprwc_feed_' . md5( wp_json_encode( [ $terms, $slugs, $limit, get_option( self::VERSION_OPTION, '' ) ] ) );
		$xml = get_transient( $key );
		if ( ! is_string( $xml ) ) {
			$xml = $this->renderer->build( [ $query ] );
			set_transient( $key, $xml, HOUR_IN_SECONDS );
		}
		$this->renderer->send( $xml );
	}

	/** @param array<int,string> $terms */
	private function outlet_query( array $terms ): array {
		return [] === $terms ? [] : [ 'tax_query' => [ [ 'taxonomy' => 'publication', 'field' => 'slug', 'terms' => $terms ] ] ];
	}

	private function token_valid(): bool {
		$expected = $this->credentials ? $this->credentials->get() : '';
		$given = isset( $_SERVER['HTTP_X_HPR_TOKEN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_HPR_TOKEN'] ) ) : ( isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '' );
		return '' !== $expected && '' !== $given && hash_equals( $expected, $given );
	}

	private function error( int $status, string $message ): void {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $message );
		exit;
	}
}
