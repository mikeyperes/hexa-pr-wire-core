<?php

namespace HexaPrWire\Core\Seo;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Contracts\PublicationRepository;
use HexaPrWire\Core\Domain\Publication\PublicationUrl;

final class CanonicalService implements Module {
	public function __construct( private PublicationRepository $publications, private PublicationUrl $urls ) {}

	public function register(): void {
		add_action( 'acf/save_post', [ $this, 'synchronize' ], 25 );
		add_action( 'save_post_post', [ $this, 'synchronize' ], 90 );
	}

	public function synchronize( mixed $post_id ): void {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || 'post' !== get_post_type( $post_id ) || ! get_post_meta( $post_id, 'canonical_enable', true ) ) {
			return;
		}
		$url = esc_url_raw( (string) get_post_meta( $post_id, 'canonical_custom_url', true ) );
		if ( '' === $url ) {
			$post = get_post( $post_id );
			$publications = $this->publications->for_release( $post_id );
			usort( $publications, static fn( array $a, array $b ): int => [ empty( $a['featured'] ) ? 1 : 0, strtolower( (string) $a['title'] ), (int) $a['id'] ] <=> [ empty( $b['featured'] ) ? 1 : 0, strtolower( (string) $b['title'] ), (int) $b['id'] ] );
			if ( $post instanceof \WP_Post && isset( $publications[0] ) ) {
				$url = $this->urls->for_slug( $publications[0], $post->post_name );
			}
		}
		if ( '' !== $url ) {
			update_post_meta( $post_id, 'rank_math_canonical_url', $url );
			update_post_meta( $post_id, 'canonical_live_url', $url );
		}
	}
}
