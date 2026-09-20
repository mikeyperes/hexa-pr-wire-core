<?php

namespace HexaPrWire\Core\Syndication;

use HexaPrWire\Core\Contracts\Module;

final class DeletionManifest implements Module {
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
		add_action( 'wp_ajax_purge_release_list', [ $this, 'legacy_response' ] );
		add_action( 'wp_ajax_nopriv_purge_release_list', [ $this, 'legacy_response' ] );
	}

	public function register_route(): void {
		register_rest_route( 'hprwc/v1', '/deletions', [
			'methods' => \WP_REST_Server::READABLE,
			'callback' => fn() => rest_ensure_response( [ 'slugs' => $this->slugs(), 'updated_gmt' => (string) get_option( 'hprwc_deletion_manifest_updated', '' ) ] ),
			'permission_callback' => '__return_true',
		] );
	}

	public function legacy_response(): void {
		nocache_headers();
		header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
		echo esc_html( implode( ',', $this->slugs() ) );
		exit;
	}

	/** @return string[] */
	public function slugs(): array {
		$count = min( 10000, max( 0, (int) get_option( 'options_slugs', 0 ) ) );
		$slugs = [];
		for ( $index = 0; $index < $count; $index++ ) {
			$slug = sanitize_title( (string) get_option( "options_slugs_{$index}_slug", '' ) );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}
		return array_values( array_unique( $slugs ) );
	}
}
