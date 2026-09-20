<?php

namespace HexaPrWire\Core\Integrations;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Contracts\PublicationRepository;

final class ElementorPublicationQuery implements Module {
	public function __construct( private PublicationRepository $publications ) {}

	public function register(): void {
		add_action( 'elementor/query/publication_links', [ $this, 'scope' ] );
	}

	public function scope( \WP_Query $query ): void {
		$post_id = get_queried_object_id();
		$ids = array_values( array_map( static fn( array $item ): int => (int) $item['id'], $this->publications->for_release( $post_id ) ) );
		$query->set( 'post_type', 'publication' );
		$query->set( 'post__in', $ids ?: [ 0 ] );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'posts_per_page', -1 );
	}
}
