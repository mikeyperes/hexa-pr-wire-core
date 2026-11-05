<?php

namespace HexaPrWire\Core\Syndication;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one existing outlet category a release is placed in, per outlet.
 *
 * Stored on the release as `outlet term slug => {slug, name}`. The outlet feed
 * carries the choice as `<hpr:outletCategory>`; the outlet's Distributor adds
 * it next to its press-release category only when that category already
 * exists there, so no category is ever created on an outlet.
 */
final class OutletCategories {
	public const META = '_hprwc_outlet_categories';

	private const CACHE_TTL = 10 * MINUTE_IN_SECONDS;

	public function __construct( private OutletClient $client ) {}

	/** @return array<string,array{slug:string,name:string}> */
	public static function all( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META, true );
		$clean  = [];
		foreach ( is_array( $stored ) ? $stored : [] as $outlet => $category ) {
			if ( is_array( $category ) && '' !== (string) ( $category['slug'] ?? '' ) ) {
				$clean[ sanitize_title( (string) $outlet ) ] = [
					'slug' => sanitize_title( (string) $category['slug'] ),
					'name' => sanitize_text_field( (string) ( $category['name'] ?? $category['slug'] ) ),
				];
			}
		}
		return $clean;
	}

	/** @return array{slug:string,name:string}|null */
	public static function for_outlet( int $post_id, string $outlet ): ?array {
		return self::all( $post_id )[ sanitize_title( $outlet ) ] ?? null;
	}

	/** Save or clear (null) one outlet's category; the outlet feeds rebuild. */
	public static function set( int $post_id, string $outlet, ?array $category ): void {
		$all    = self::all( $post_id );
		$outlet = sanitize_title( $outlet );
		if ( null === $category ) {
			unset( $all[ $outlet ] );
		} else {
			$all[ $outlet ] = [ 'slug' => sanitize_title( (string) $category['slug'] ), 'name' => sanitize_text_field( (string) $category['name'] ) ];
		}
		if ( [] === $all ) {
			delete_post_meta( $post_id, self::META );
		} else {
			update_post_meta( $post_id, self::META, $all );
		}
		FeedRegistry::flush();
	}

	/**
	 * The outlet's existing categories matching $search (or exactly $slug),
	 * from its Distributor, falling back to WordPress's public category list
	 * when the outlet runs a Distributor without the categories route.
	 *
	 * @return array{ok:bool,message:string,categories:array<int,array{slug:string,name:string,path:string,count:int,link:string}>}
	 */
	public function search( string $host, string $search = '', string $slug = '' ): array {
		$host   = strtolower( $host );
		$search = sanitize_text_field( $search );
		$slug   = sanitize_title( $slug );
		$key    = 'hprwc_outlet_cats_v2_' . md5( $host . '|' . $search . '|' . $slug );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$args   = array_filter( [ 'search' => $search, 'slug' => $slug ], static fn( string $value ): bool => '' !== $value );
		$result = $this->client->categories( $host, $args );
		if ( $result['ok'] && isset( $result['data']['categories'] ) && is_array( $result['data']['categories'] ) ) {
			$found = $this->normalize( $result['data']['categories'] );
		} elseif ( 404 === $result['status'] ) {
			$found = $this->public_search( $host, $args );
			if ( null === $found ) {
				return [ 'ok' => false, 'message' => 'Could not read this outlet\'s categories.', 'categories' => [] ];
			}
		} else {
			return [ 'ok' => false, 'message' => 'Could not read this outlet\'s categories: ' . $result['message'], 'categories' => [] ];
		}

		$answer = [ 'ok' => true, 'message' => '', 'categories' => $found ];
		set_transient( $key, $answer, self::CACHE_TTL );
		return $answer;
	}

	/** @return array{slug:string,name:string,link:string}|null The outlet's existing category with exactly this slug. */
	public function find( string $host, string $slug ): ?array {
		foreach ( $this->search( $host, '', $slug )['categories'] as $category ) {
			if ( $category['slug'] === sanitize_title( $slug ) ) {
				return [ 'slug' => $category['slug'], 'name' => $category['name'], 'link' => $category['link'] ?: $this->category_link( $host, $slug ) ];
			}
		}
		return null;
	}

	/** Presentation only: resolve old saved choices without rewriting release data. */
	public function for_display( int $post_id, string $outlet, string $host ): ?array {
		$category = self::for_outlet( $post_id, $outlet );
		if ( null === $category ) {
			return null;
		}
		$resolved = $this->find( $host, $category['slug'] );
		$category['link'] = (string) ( $resolved['link'] ?? '' );
		return $category;
	}

	/** Read the outlet's canonical term link; never infer its permalink structure. */
	private function category_link( string $host, string $slug ): string {
		$key = 'hprwc_outlet_cat_link_' . md5( strtolower( $host ) . '|' . sanitize_title( $slug ) );
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		foreach ( $this->public_search( $host, [ 'slug' => sanitize_title( $slug ) ] ) ?? [] as $category ) {
			if ( $category['slug'] === sanitize_title( $slug ) && '' !== $category['link'] ) {
				set_transient( $key, $category['link'], self::CACHE_TTL );
				return $category['link'];
			}
		}
		return '';
	}

	/** @return array<int,array{slug:string,name:string,path:string,count:int,link:string}>|null */
	private function public_search( string $host, array $args ): ?array {
		$response = wp_remote_get(
			add_query_arg( array_merge( [ 'per_page' => 30, 'orderby' => 'count', 'order' => 'desc', '_fields' => 'id,name,slug,parent,count,link' ], $args ), 'https://' . $host . '/wp-json/wp/v2/categories' ),
			[ 'timeout' => 15, 'redirection' => 2, 'reject_unsafe_urls' => true, 'limit_response_size' => MB_IN_BYTES, 'headers' => [ 'Accept' => 'application/json' ] ]
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$rows = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $rows ) ? $this->normalize( $rows ) : null;
	}

	/** @return array<int,array{slug:string,name:string,path:string,count:int,link:string}> */
	private function normalize( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || '' === (string) ( $row['slug'] ?? '' ) ) {
				continue;
			}
			$name  = html_entity_decode( (string) ( $row['name'] ?? $row['slug'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$out[] = [
				'slug'  => sanitize_title( (string) $row['slug'] ),
				'name'  => sanitize_text_field( $name ),
				'path'  => sanitize_text_field( html_entity_decode( (string) ( $row['path'] ?? $name ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				'count' => (int) ( $row['count'] ?? 0 ),
				'link'  => esc_url_raw( (string) ( $row['link'] ?? '' ), [ 'http', 'https' ] ),
			];
		}
		return array_slice( $out, 0, 30 );
	}
}
