<?php

namespace HexaPrWire\Core\Workflow;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Contracts\PublicationRepository;
use HexaPrWire\Core\Domain\Publication\PublicationUrl;

final class DeliveryLinkGenerator implements Module {
	private bool $running = false;

	public function __construct(
		private PublicationRepository $publications,
		private PublicationUrl $urls
	) {}

	public function register(): void {
		add_action( 'save_post_post', [ $this, 'generate' ], 80, 3 );
	}

	public function generate( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $update );
		if ( $this->running || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || '' === (string) $post->post_name ) {
			return;
		}
		$this->running = true;
		try {
			$sections = [ 'sources' => [], 'new_sources' => [], 'other_sources' => [] ];
			foreach ( $this->publications->for_release( $post_id ) as $publication ) {
				if ( empty( $publication['status'] ) || 'standard' !== $publication['product_tier'] ) {
					continue;
				}
				$url = $this->urls->for_slug( $publication, $post->post_name );
				if ( '' === $url ) {
					continue;
				}
				$line = sprintf( '%s: %s', (string) $publication['title'], $url );
				if ( empty( $publication['featured'] ) ) {
					$sections['other_sources'][] = $line;
				} elseif ( ! empty( $publication['new_source'] ) ) {
					$sections['new_sources'][] = $line;
				} else {
					$sections['sources'][] = $line;
				}
			}

			$lines = [ get_the_title( $post ), '', 'PRESS RELEASE: ' . get_permalink( $post_id ), '', 'NOTE: Please allow 1 hour for links to go live' ];
			$this->append_legacy_premium_sources( $post_id, $lines );
			foreach ( [ 'sources' => 'SOURCES', 'new_sources' => 'NEW SOURCES', 'other_sources' => 'OTHER SOURCES' ] as $key => $heading ) {
				if ( $sections[ $key ] ) {
					$lines[] = '';
					$lines[] = $heading . '---';
					$lines[] = '';
					array_push( $lines, ...$sections[ $key ] );
				}
			}
			$canonical = $this->canonical_value( $post_id );
			if ( '' !== $canonical ) {
				$lines[] = '';
				$lines[] = 'CANONICAL URL: ' . $canonical;
			}
			$plain = implode( "\n", $lines );
			$html = wpautop( make_clickable( esc_html( $plain ) ) );
			update_post_meta( $post_id, 'link_output', $html );
			update_post_meta( $post_id, 'link_output_html', $html );
			update_post_meta( $post_id, 'link_output_standard', $html );
			$other = implode( "\n", $sections['other_sources'] );
			update_post_meta( $post_id, 'non_featured_standard_urls', wpautop( make_clickable( esc_html( $other ) ) ) );
			$this->update_premium_compatibility_output( $post_id );
		} finally {
			$this->running = false;
		}
	}

	/** @param string[] $lines */
	private function append_legacy_premium_sources( int $post_id, array &$lines ): void {
		$premium = [
			'Bloomberg' => get_post_meta( $post_id, 'bloomberg_link', true ),
			'Insider' => get_post_meta( $post_id, 'markets_insider_link', true ),
			'Yahoo News' => get_post_meta( $post_id, 'yahoo_link', true ),
		];
		$premium = array_filter( $premium, static fn( mixed $url ): bool => is_string( $url ) && '' !== trim( $url ) );
		if ( $premium ) {
			$lines[] = '';
			$lines[] = 'PREMIUM SOURCES---';
			foreach ( $premium as $label => $url ) {
				$lines[] = $label . ': ' . esc_url_raw( (string) $url );
			}
		}
	}

	private function update_premium_compatibility_output( int $post_id ): void {
		$keys = [ 'markets_insider_link', 'yahoo_link', 'benzinga_link', 'bloomberg_link', 'nasdaq_link', 'digital_journal_link' ];
		$urls = [];
		foreach ( $keys as $key ) {
			$url = esc_url_raw( (string) get_post_meta( $post_id, $key, true ) );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		if ( $urls ) {
			update_post_meta( $post_id, 'link_output_premium', wpautop( make_clickable( esc_html( implode( "\n\n", $urls ) ) ) ) );
		}
	}

	private function canonical_value( int $post_id ): string {
		if ( ! get_post_meta( $post_id, 'canonical_enable', true ) ) {
			return '';
		}
		return esc_url_raw( (string) ( get_post_meta( $post_id, 'canonical_live_url', true ) ?: get_post_meta( $post_id, 'canonical_custom_url', true ) ) );
	}
}
