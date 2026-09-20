<?php

namespace HexaPrWire\Core\Infrastructure\WordPress;

use HexaPrWire\Core\Contracts\PublicationRepository;

final class WordPressPublicationRepository implements PublicationRepository {
	public function all( array $criteria = [] ): array {
		$query = new \WP_Query(
			[
				'post_type' => 'publication',
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'orderby' => [ 'title' => 'ASC', 'ID' => 'ASC' ],
				'no_found_rows' => true,
				'update_post_term_cache' => false,
			]
		);
		$items = [];
		foreach ( $query->posts as $post ) {
			$item = $this->present( $post );
			if ( isset( $criteria['status'] ) && (bool) $criteria['status'] !== (bool) $item['status'] ) {
				continue;
			}
			if ( isset( $criteria['tier'] ) && (string) $criteria['tier'] !== (string) $item['product_tier'] ) {
				continue;
			}
			if ( isset( $criteria['featured'] ) && (bool) $criteria['featured'] !== (bool) $item['featured'] ) {
				continue;
			}
			if ( isset( $criteria['new_source'] ) && (bool) $criteria['new_source'] !== (bool) $item['new_source'] ) {
				continue;
			}
			$items[] = $item;
		}
		return $items;
	}

	public function for_release( int $post_id ): array {
		$terms = wp_get_object_terms( $post_id, 'publication', [ 'orderby' => 'name', 'order' => 'ASC' ] );
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		$items = [];
		$seen = [];
		foreach ( $terms as $term ) {
			$publication_id = $this->mapped_post_id( (int) $term->term_id );
			if ( $publication_id <= 0 || isset( $seen[ $publication_id ] ) ) {
				continue;
			}
			$post = get_post( $publication_id );
			if ( ! $post instanceof \WP_Post || 'publication' !== $post->post_type || 'publish' !== $post->post_status ) {
				continue;
			}
			$seen[ $publication_id ] = true;
			$item = $this->present( $post );
			$item['term_id'] = (int) $term->term_id;
			$item['term_name'] = (string) $term->name;
			$items[] = $item;
		}
		return $items;
	}

	public function mapped_post_id( int $term_id ): int {
		$value = get_term_meta( $term_id, 'publication', true );
		if ( $value instanceof \WP_Post ) {
			return (int) $value->ID;
		}
		if ( is_array( $value ) ) {
			return absint( $value['ID'] ?? $value['id'] ?? 0 );
		}
		return absint( $value );
	}

	/** @return array<string,mixed> */
	private function present( \WP_Post $post ): array {
		$field = static fn( string $key ): mixed => get_post_meta( $post->ID, $key, true );
		$icon = $field( 'icon' );
		return [
			'id' => (int) $post->ID,
			'title' => get_the_title( $post ),
			'slug' => (string) $post->post_name,
			'dr' => (string) $field( 'dr' ),
			'da' => (string) $field( 'da' ),
			'tf' => (string) $field( 'tf' ),
			'status' => (bool) $field( 'status' ),
			'url_nice' => (string) $field( 'url_nice' ),
			'prefix' => esc_url_raw( (string) $field( 'url_press_release_prefix' ) ),
			'product_tier' => sanitize_key( (string) $field( 'product_tier' ) ),
			'featured' => (bool) $field( 'featured' ),
			'new_source' => (bool) $field( 'new_source' ),
			'url' => esc_url_raw( (string) $field( 'url' ) ),
			'icon_id' => is_array( $icon ) ? absint( $icon['ID'] ?? 0 ) : absint( $icon ),
		];
	}
}
