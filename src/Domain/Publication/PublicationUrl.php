<?php

namespace HexaPrWire\Core\Domain\Publication;

final class PublicationUrl {
	/** @param array<string,mixed> $publication */
	public function for_slug( array $publication, string $slug ): string {
		$prefix = esc_url_raw( trim( (string) ( $publication['prefix'] ?? '' ) ) );
		$slug = sanitize_title( $slug );
		if ( '' === $prefix || '' === $slug || ! wp_http_validate_url( $prefix ) ) {
			return '';
		}
		return esc_url_raw( trailingslashit( untrailingslashit( $prefix ) . '/' . $slug ) );
	}
}
