<?php

namespace HexaPrWire\Core\Frontend;

use HexaPrWire\Core\Contracts\Module;

final class PressReleaseContent implements Module {
	public function register(): void {
		add_filter( 'the_content', [ $this, 'render' ], 8 );
	}

	public function render( string $content ): string {
		$post_id = get_the_ID();
		if ( $post_id <= 0 || 'post' !== get_post_type( $post_id ) || ( ! is_singular( 'post' ) && ! is_feed() ) ) {
			return $content;
		}
		$date = trim( (string) get_post_meta( $post_id, 'press_release_date', true ) );
		$location = trim( (string) get_post_meta( $post_id, 'press_release_location', true ) );
		if ( '' !== $date && '' !== $location ) {
			$content = '<p class="hprwc-dateline"><strong>' . esc_html( $location ) . ' (Hexa PR Wire — ' . esc_html( $date ) . ')</strong> — </p>' . $content;
		}
		$contacts = $this->contacts( $post_id );
		if ( $contacts ) {
			$content .= '<section class="hprwc-contact"><h2>Contact Information</h2><p>' . implode( '<br>', $contacts ) . '</p></section>';
		}
		$disclaimer = get_post_meta( $post_id, 'disclaimer', true );
		if ( is_array( $disclaimer ) ) {
			$disclaimer = reset( $disclaimer );
		}
		$disclaimer = trim( (string) $disclaimer );
		if ( '' !== $disclaimer && 'null' !== strtolower( $disclaimer ) ) {
			$content .= '<aside class="hprwc-disclaimer"><hr><small><em>' . esc_html( $disclaimer ) . '</em></small></aside>';
		}
		return $content;
	}

	/** @return string[] */
	private function contacts( int $post_id ): array {
		$count = min( 100, max( 0, (int) get_post_meta( $post_id, 'contact_information', true ) ) );
		$lines = [];
		for ( $index = 0; $index < $count; $index++ ) {
			$name = trim( (string) get_post_meta( $post_id, "contact_information_{$index}_name", true ) );
			$value = trim( (string) get_post_meta( $post_id, "contact_information_{$index}_value", true ) );
			$url = trim( (string) get_post_meta( $post_id, "contact_information_{$index}_url", true ) );
			$label = '' !== $value ? trim( $name . ( '' !== $name ? ': ' : '' ) . $value ) : ( $name ?: $url );
			if ( '' === $label ) {
				continue;
			}
			$lines[] = '' !== $url ? '<a href="' . esc_url( $url, [ 'http', 'https', 'mailto', 'tel' ] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>' : esc_html( $label );
		}
		return $lines;
	}
}
