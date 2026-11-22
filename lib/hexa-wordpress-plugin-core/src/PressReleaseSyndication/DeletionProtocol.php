<?php

namespace Hexa\PluginCore\PressReleaseSyndication;

/** The current JSON deletion manifest and its legacy text representation. */
final class DeletionProtocol {
	public const ROUTE = '/deletions';
	public const PATH = 'wp-json/' . Protocol::REST_NAMESPACE . self::ROUTE;
	public const LEGACY_ACTION = 'purge_release_list';
	public const LEGACY_PATH = 'wp-admin/admin-ajax.php?action=' . self::LEGACY_ACTION;

	/** Assemble the public JSON manifest without changing the source's stored data. */
	public static function manifest( array $slugs, array $sources, string $updated_gmt ): array {
		return [ 'slugs' => $slugs, 'sources' => $sources, 'updated_gmt' => $updated_gmt ];
	}

	/** Decode current JSON, or signal the caller to use the legacy endpoint. */
	public static function read( string $json ): ?array {
		$body = json_decode( $json, true );
		if ( ! is_array( $body ) ) {
			return null;
		}
		$sources = [];
		foreach ( (array) ( $body['sources'] ?? [] ) as $source ) {
			$id = sanitize_text_field( (string) ( $source['id'] ?? '' ) );
			if ( preg_match( '/^' . Protocol::POST_ID_PREFIX . '\d+$/', $id ) ) {
				$sources[] = [ 'id' => $id, 'slug' => sanitize_title( (string) ( $source['slug'] ?? '' ) ) ];
			}
		}
		return [ 'slugs' => self::normalize_slugs( (array) ( $body['slugs'] ?? [] ) ), 'sources' => $sources ];
	}

	/** Encode the public legacy list. */
	public static function legacy_body( array $slugs ): string {
		return implode( ',', $slugs );
	}

	/** Decode comma-separated or newline-separated legacy slugs. */
	public static function read_legacy( string $body ): array {
		$body = trim( $body );
		if ( '' === $body ) {
			return [];
		}
		$parts = preg_split( '/[\r\n,]+/', $body );
		return self::normalize_slugs( is_array( $parts ) ? $parts : [] );
	}

	private static function normalize_slugs( array $slugs ): array {
		return array_values( array_unique( array_filter( array_map( 'sanitize_title', $slugs ) ) ) );
	}
}
