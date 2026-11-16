<?php

namespace HexaPrWire\Core\Integrations;

use HexaPrWire\Core\Admin\CustomerTermLimits;
use SMP\VerifiedProfiles\ProfileTypes\ProfileTypes;

/** Public identity and association reads; no private profile or account fields. */
final class ReleaseProfileRepository {
	public const PAGE_SIZE = 20;

	public function author( int $id ): ?array {
		$user = get_userdata( $id );
		if ( ! $user instanceof \WP_User || ! count_user_posts( $id, 'post', true ) ) {
			return null;
		}
		return [
			'id' => $id,
			'name' => $user->display_name,
			'url' => get_author_posts_url( $id ),
			'image_url' => get_avatar_url( $id, [ 'size' => 192 ] ),
		];
	}

	public function find_author( string $url ): ?array {
		$home = wp_parse_url( home_url( '/' ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' )
			|| ( $home['host'] ?? '' ) !== ( $parts['host'] ?? '' )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
			|| ( $home['port'] ?? 443 ) !== ( $parts['port'] ?? 443 ) ) {
			return null;
		}
		$slug = rawurldecode( basename( untrailingslashit( $parts['path'] ?? '' ) ) );
		$user = get_user_by( 'slug', $slug );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}
		$author = $this->author( $user->ID );
		return $author && untrailingslashit( $author['url'] ) === untrailingslashit( $url ) ? $author : null;
	}

	public function people( string $name, int $page ): array {
		$name = trim( sanitize_text_field( $name ) );
		$profiles = new VerifiedProfiles();
		if ( ! $profiles->available() || mb_strlen( $name ) < 2 || mb_strlen( $name ) > 120 ) {
			return $this->empty_page( $page );
		}
		$types = ProfileTypes::post_types();
		if ( ! $types ) {
			return $this->empty_page( $page );
		}
		// Explicit person metadata makes the database page and count the same eligible set.
		$query = new \WP_Query( [
			'post_type' => $types,
			'post_status' => 'publish',
			'has_password' => false,
			's' => $name,
			'search_columns' => [ 'post_title' ],
			'meta_key' => 'profile_type',
			'meta_value' => 'person',
			'posts_per_page' => self::PAGE_SIZE,
			'paged' => $page,
			'fields' => 'ids',
			'orderby' => [ 'title' => 'ASC', 'ID' => 'ASC' ],
		] );
		$people = array_map( [ $profiles, 'public_person' ], $query->posts );
		return [
			'items' => $people,
			'page' => $page,
			'total' => $query->found_posts,
			'total_pages' => $query->max_num_pages,
		];
	}

	public function releases( array $selection, int $page ): array {
		$category = CustomerTermLimits::release_category();
		if ( ! $category ) {
			return $this->empty_page( $page );
		}
		$args = [
			'post_type' => 'post',
			'post_status' => 'publish',
			'has_password' => false,
			'posts_per_page' => self::PAGE_SIZE,
			'paged' => $page,
			'orderby' => [ 'date' => 'DESC', 'ID' => 'DESC' ],
			'ignore_sticky_posts' => true,
			'category__in' => [ $category->term_id ],
		];
		if ( isset( $selection['author'] ) ) {
			$args['author'] = (int) $selection['author'];
		} else {
			$args['meta_query'] = [ [
				'key' => '^profiles_[0-9]+_profile$',
				'compare_key' => 'REGEXP',
				'value' => (string) $selection['profile'],
				'compare' => '=',
			] ];
		}
		$query = new \WP_Query( $args );
		$items = [];
		foreach ( $query->posts as $post ) {
			$items[] = [
				'id' => $post->ID,
				'title' => get_the_title( $post ),
				'url' => get_permalink( $post ),
				'date_gmt' => get_post_time( 'c', true, $post ),
				'excerpt' => wp_strip_all_tags( $post->post_excerpt ),
			];
		}
		return [ 'items' => $items, 'page' => $page, 'total' => $query->found_posts, 'total_pages' => $query->max_num_pages ];
	}

	private function empty_page( int $page ): array {
		return [ 'items' => [], 'page' => $page, 'total' => 0, 'total_pages' => 0 ];
	}
}
