<?php

namespace Hexa\PluginCore\PressReleaseSyndication;

/** The wire author identity and JSON profile format. */
final class AuthorProtocol {
	public const LOGIN = 'hexaprwire';
	public const ROUTE = '/author';
	public const PATH = 'wp-json/' . Protocol::REST_NAMESPACE . self::ROUTE;
	private const URL_KEYS = [ 'facebook', 'instagram', 'linkedin', 'x', 'twitter', 'crunchbase', 'muckrack', 'website', 'the_org', 'calendly', 'youtube', 'tiktok', 'wikipedia' ];

	/** Read and normalize public profile links, preserving the historical X alias. */
	public static function urls( callable $read_url ): array {
		$urls = [];
		foreach ( self::URL_KEYS as $key ) {
			$url = esc_url_raw( trim( (string) $read_url( $key ) ) );
			if ( '' !== $url ) {
				$urls[ 'twitter' === $key ? 'x' : $key ] = $url;
			}
		}
		return $urls;
	}

	/** Write the public author identity in the established key order. */
	public static function profile( \WP_User $user, array $urls, string $avatar_url, string $updated_gmt ): array {
		return [
			'login'        => $user->user_login,
			'display_name' => $user->display_name,
			'first_name'   => (string) $user->first_name,
			'last_name'    => (string) $user->last_name,
			'email'        => $user->user_email,
			'user_url'     => $user->user_url,
			'description'  => (string) $user->description,
			'urls'         => $urls,
			'avatar_url'   => $avatar_url,
			'updated_gmt'  => $updated_gmt,
		];
	}

	/** Decode a usable author response. */
	public static function read( string $json ): ?array {
		$profile = json_decode( $json, true );
		return is_array( $profile ) && ! empty( $profile['display_name'] ) ? $profile : null;
	}

	/** Apply nonblank wire profile values to the outlet's existing fallback. */
	public static function merge( array $profile, array $master ): array {
		foreach ( [ 'display_name', 'first_name', 'last_name', 'user_url', 'description' ] as $key ) {
			if ( isset( $master[ $key ] ) && '' !== trim( (string) $master[ $key ] ) ) {
				$profile[ $key ] = (string) $master[ $key ];
			}
		}
		if ( isset( $master['urls'] ) && is_array( $master['urls'] ) ) {
			$profile['urls'] = array_merge( $profile['urls'], array_filter( array_map( 'esc_url_raw', $master['urls'] ) ) );
		}
		return $profile;
	}
}
