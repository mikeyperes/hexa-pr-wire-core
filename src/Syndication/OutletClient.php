<?php

namespace HexaPrWire\Core\Syndication;

use HexaPrWire\Core\CredentialRepository;
use HexaPrWire\Core\DestinationRegistry;
use HexaPrWire\Core\PublicationResolver;

/**
 * Calls an outlet's Distributor commands with the shared Hexa PR Wire token.
 * Every command only makes the outlet pull from hexaprwire.com.
 */
final class OutletClient {
	public function __construct(
		private CredentialRepository $credentials,
		private PublicationResolver $resolver,
		private DestinationRegistry $destinations
	) {}

	/** @return array{ok:bool,status:int,message:string} */
	public function command( string $host, string $route, array $body = [], int $timeout = 60 ): array {
		$token = $this->credentials->get();
		if ( '' === $token ) {
			return [ 'ok' => false, 'status' => 0, 'message' => 'The shared Hexa PR Wire token is not configured.' ];
		}
		$response = wp_remote_post(
			'https://' . $host . '/wp-json/hpr-distributor/v1/' . ltrim( $route, '/' ),
			[
				'timeout'             => $timeout,
				'redirection'         => 0,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => MB_IN_BYTES,
				'headers'             => [ 'Accept' => 'application/json', 'Cache-Control' => 'no-cache', 'X-HPR-Token' => $token, 'User-Agent' => 'HexaPRWireCore/' . HPRWC_VERSION ],
				'body'                => $body,
			]
		);
		$token = '';
		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'status' => 0, 'message' => $response->get_error_message() ];
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$ok = $status >= 200 && $status < 300 && is_array( $json ) && ! empty( $json['success'] );
		return [ 'ok' => $ok, 'status' => $status, 'message' => is_array( $json ) ? (string) ( $json['message'] ?? ( $ok ? 'OK' : 'Failed' ) ) : 'HTTP ' . $status ];
	}

	/**
	 * Outlet hosts (as used in their press-release URL prefix) for one release,
	 * or for every approved outlet when $post_id is 0.
	 *
	 * @return array<int,string>
	 */
	public function hosts( int $post_id = 0 ): array {
		if ( $post_id > 0 ) {
			$resolved = $this->resolver->resolve_post( $post_id );
		} else {
			$term_ids = get_terms( [ 'taxonomy' => PublicationResolver::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ] );
			$resolved = $this->resolver->resolve_term_ids( is_wp_error( $term_ids ) ? [] : array_map( 'absint', $term_ids ) );
		}
		$hosts = [];
		foreach ( $this->destinations->enforce( $resolved )['targets'] as $target ) {
			$host = strtolower( (string) wp_parse_url( (string) $target['prefix'], PHP_URL_HOST ) );
			if ( '' !== $host ) {
				$hosts[] = $host;
			}
		}
		return array_values( array_unique( $hosts ) );
	}
}
