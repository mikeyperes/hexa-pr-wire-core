<?php

namespace HexaPrWire\Core\Frontend;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Contracts\PublicationRepository;
use HexaPrWire\Core\Domain\Publication\PublicationUrl;

final class PublicationShortcodes implements Module {
	public function __construct( private PublicationRepository $publications, private PublicationUrl $urls ) {}

	public function register(): void {
		add_shortcode( 'display_standard_releases_table', [ $this, 'standard_table' ] );
		add_shortcode( 'display_featured_standard_releases_links', [ $this, 'featured_links' ] );
		add_shortcode( 'display_new_sources', [ $this, 'new_sources' ] );
		add_shortcode( 'publication_press_links', [ $this, 'release_links' ] );
	}

	public function standard_table(): string {
		$sample = $this->sample_slug();
		$rows = '';
		foreach ( $this->publications->all( [ 'status' => true, 'tier' => 'standard' ] ) as $publication ) {
			$sample_url = '' !== $sample ? $this->urls->for_slug( $publication, $sample ) : '';
			$rows .= '<tr><td>' . esc_html( (string) $publication['title'] );
			if ( '' !== (string) $publication['url'] ) {
				$rows .= ' (<a href="' . esc_url( (string) $publication['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) ( $publication['url_nice'] ?: $publication['url'] ) ) . '</a>)';
			}
			$rows .= '</td><td>' . ( '' !== $sample_url ? '<a href="' . esc_url( $sample_url ) . '" target="_blank" rel="noopener noreferrer">Sample URL</a>' : '—' ) . '</td><td>' . esc_html( trim( (string) $publication['dr'] . '/' . (string) $publication['da'], '/' ) ) . '</td></tr>';
		}
		return '<table class="hprwc-publication-table"><thead><tr><th>Publication</th><th>Example</th><th>DR/DA</th></tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	public function featured_links(): string {
		$links = [];
		foreach ( $this->publications->all( [ 'status' => true, 'tier' => 'standard', 'featured' => true ] ) as $publication ) {
			$links[] = '<a href="' . esc_url( (string) $publication['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $publication['title'] ) . '</a>';
		}
		return implode( '<br>', $links );
	}

	public function new_sources(): string {
		$html = '<div class="hprwc-new-sources">';
		foreach ( $this->publications->all( [ 'status' => true, 'tier' => 'standard', 'featured' => true, 'new_source' => true ] ) as $publication ) {
			$image = (int) $publication['icon_id'] > 0 ? wp_get_attachment_image( (int) $publication['icon_id'], 'medium', false, [ 'loading' => 'lazy' ] ) : '';
			$html .= '<article class="hprwc-source-card">' . $image . '<p><strong>' . esc_html( (string) $publication['title'] ) . '</strong>';
			if ( '' !== (string) $publication['url'] ) {
				$html .= ' — <a href="' . esc_url( (string) $publication['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) ( $publication['url_nice'] ?: $publication['url'] ) ) . '</a>';
			}
			$html .= '</p></article>';
		}
		return $html . '</div>';
	}

	public function release_links(): string {
		$post = get_post();
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			return '';
		}
		$links = [];
		foreach ( $this->publications->for_release( $post->ID ) as $publication ) {
			$url = $this->urls->for_slug( $publication, $post->post_name );
			if ( '' !== $url ) {
				$links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>';
			}
		}
		return implode( '<br>', $links );
	}

	private function sample_slug(): string {
		$value = get_option( 'options_sample_standard_release', '' );
		if ( is_numeric( $value ) ) {
			$post = get_post( absint( $value ) );
			return $post instanceof \WP_Post ? (string) $post->post_name : '';
		}
		if ( is_array( $value ) ) {
			return sanitize_title( (string) ( $value['post_name'] ?? $value['slug'] ?? '' ) );
		}
		return sanitize_title( (string) $value );
	}
}
