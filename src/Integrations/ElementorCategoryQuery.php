<?php

namespace HexaPrWire\Core\Integrations;

use HexaPrWire\Core\Contracts\Module;

/**
 * Elementor Query ID `category_all_types`: a Loop Grid filtered by a category
 * lists that category's items from every public post type that uses
 * categories (posts and resources), not just the one type Elementor allows.
 * The grid keeps its own category, order and count settings.
 */
final class ElementorCategoryQuery implements Module {
	public const QUERY_ID = 'category_all_types';

	public function register(): void {
		add_action( 'elementor/query/' . self::QUERY_ID, [ $this, 'widen' ] );
	}

	public function widen( \WP_Query $query ): void {
		$query->set( 'post_type', self::types() );
	}

	/** @return list<string> public post types registered with the category taxonomy */
	public static function types(): array {
		return array_values( array_filter(
			get_post_types( [ 'public' => true ] ),
			static fn( string $type ): bool => is_object_in_taxonomy( $type, 'category' )
		) );
	}
}
