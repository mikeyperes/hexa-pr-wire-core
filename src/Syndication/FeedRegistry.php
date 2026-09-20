<?php

namespace HexaPrWire\Core\Syndication;

use HexaPrWire\Core\Contracts\Module;

final class FeedRegistry implements Module {
	public function __construct( private FeedRenderer $renderer ) {}

	public function register(): void {
		add_action( 'init', [ $this, 'register_feeds' ], 20 );
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
		$cutoff = '2024-11-20';
		$common = [];
		if ( '' !== $publication && 'reprocess-old' !== $action ) {
			$terms = array_values( array_filter( array_map( 'sanitize_title', explode( ',', $publication ) ) ) );
			if ( $terms ) {
				$common['tax_query'] = [ [ 'taxonomy' => 'publication', 'field' => 'slug', 'terms' => $terms ] ];
			}
		}
		if ( 'reprocess-old' === $action ) {
			$this->renderer->render( [ 'date_query' => [ [ 'before' => $cutoff, 'inclusive' => false ] ] ] );
			return;
		}
		if ( 'reprocess-all' === $action ) {
			$this->renderer->render_multiple( [
				array_merge( $common, [ 'date_query' => [ [ 'after' => $cutoff, 'inclusive' => true ] ] ] ),
				[ 'date_query' => [ [ 'before' => $cutoff, 'inclusive' => false ] ] ],
			] );
			return;
		}
		$this->renderer->render( array_merge( $common, [ 'date_query' => [ [ 'after' => $cutoff, 'inclusive' => true ] ] ] ) );
	}
}
