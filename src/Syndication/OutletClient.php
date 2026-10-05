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
		$result = $this->request( 'POST', $host, $route, $body, $timeout );
		unset( $result['data'] );
		return $result;
	}

	/**
	 * Distributor's token-protected health report for one outlet host.
	 *
	 * @return array{ok:bool,status:int,message:string,data:array<string,mixed>}
	 */
	public function health( string $host, int $timeout = 20 ): array {
		return $this->request( 'GET', $host, 'health', [], $timeout );
	}

	/** Whether the outlet exposes Distributor's REST namespace at all (any version). */
	public function has_distributor( string $host ): bool {
		return 200 === $this->request( 'GET', $host, '', [], 15 )['status'];
	}

	/** @return array{ok:bool,status:int,message:string,data:array<string,mixed>} */
	private function request( string $method, string $host, string $route, array $body, int $timeout ): array {
		$token = $this->credentials->get();
		if ( '' === $token ) {
			return [ 'ok' => false, 'status' => 0, 'message' => 'The shared Hexa PR Wire token is not configured.', 'data' => [] ];
		}
		$response = wp_remote_request(
			'https://' . $host . '/wp-json/hpr-distributor/v1/' . ltrim( $route, '/' ),
			[
				'method'              => $method,
				'timeout'             => $timeout,
				'redirection'         => 0,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => MB_IN_BYTES,
				'headers'             => [ 'Accept' => 'application/json', 'Cache-Control' => 'no-cache', 'X-HPR-Token' => $token, 'User-Agent' => 'HexaPRWireCore/' . HPRWC_VERSION ],
				'body'                => 'POST' === $method ? $body : null,
			]
		);
		$token = '';
		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'status' => 0, 'message' => $response->get_error_message(), 'data' => [] ];
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$json = is_array( $json ) ? $json : [];
		// Commands answer {success:true,...}; the health route returns its report directly.
		$ok = $status >= 200 && $status < 300 && [] !== $json && ( 'GET' === $method || ! empty( $json['success'] ) );
		return [ 'ok' => $ok, 'status' => $status, 'message' => (string) ( $json['message'] ?? ( $ok ? 'OK' : 'HTTP ' . $status ) ), 'data' => $json ];
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
