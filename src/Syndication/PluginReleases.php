<?php

namespace HexaPrWire\Core\Syndication;

/**
 * Latest released version of a Hexa plugin: the `Version:` header of its main
 * file on GitHub's main branch, the same source the plugins' own updaters use.
 * Looked up once per plugin for every outlet and cached for two minutes.
 */
final class PluginReleases {
	private const TTL = 120;

	public function latest( string $repo, string $main_file ): string {
		$key = 'hprwc_release_' . md5( $repo . '|' . $main_file );
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get( 'https://raw.githubusercontent.com/' . $repo . '/main/' . rawurlencode( $main_file ), [ 'timeout' => 15 ] );
		$version = '';
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) && preg_match( '/^[\s\*]*Version:\s*([0-9][^\s]*)/mi', (string) wp_remote_retrieve_body( $response ), $match ) ) {
			$version = $match[1];
		}
		set_transient( $key, $version, '' === $version ? 60 : self::TTL );
		return $version;
	}
}
