<?php

namespace HexaPrWire\Core\Integrations;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Contracts\PublicationRepository;
use HexaPrWire\Core\Domain\Publication\PublicationUrl;

/**
 * Elementor adapter for the single-release "View This Press Release On" grid:
 * the `publication_links` query scopes the grid to the release's outlets, and
 * the Publication Release URL dynamic tag links each card to that outlet's copy.
 */
final class ElementorPublicationQuery implements Module {
	/** @var array<int,array<int,array<string,mixed>>> */
	private array $by_release = [];

	public function __construct( private PublicationRepository $publications, private PublicationUrl $urls ) {}

	public function register(): void {
		add_action( 'elementor/query/publication_links', [ $this, 'scope' ] );
		add_action( 'elementor/dynamic_tags/register', [ $this, 'register_tags' ] );
	}

	public function scope( \WP_Query $query ): void {
		$ids = array_keys( $this->release_publications( get_queried_object_id() ) );
		$query->set( 'post_type', 'publication' );
		$query->set( 'post__in', $ids ?: [ 0 ] );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'posts_per_page', -1 );
	}

	public function register_tags( mixed $dynamic_tags ): void {
		if ( ! class_exists( '\\Elementor\\Core\\DynamicTags\\Data_Tag' ) || ! is_object( $dynamic_tags ) ) {
			return;
		}
		PublicationReleaseUrlTag::$resolve = [ $this, 'release_url' ];
		$dynamic_tags->register( new PublicationReleaseUrlTag() );
	}

	/** The current loop publication's URL for the queried release, or the outlet homepage when it has no prefix. */
	public function release_url(): string {
		$release = get_post( get_queried_object_id() );
		$publication_id = (int) get_the_ID();
		$publication = $release instanceof \WP_Post ? ( $this->release_publications( $release->ID )[ $publication_id ] ?? null ) : null;
		$url = null !== $publication ? $this->urls->for_slug( $publication, $release->post_name ) : '';
		return '' !== $url ? $url : esc_url_raw( (string) get_post_meta( $publication_id, 'url', true ) );
	}

	/** @return array<int,array<string,mixed>> keyed by publication ID */
	private function release_publications( int $post_id ): array {
		if ( ! isset( $this->by_release[ $post_id ] ) ) {
			$this->by_release[ $post_id ] = array_column( $this->publications->for_release( $post_id ), null, 'id' );
		}
		return $this->by_release[ $post_id ];
	}
}
