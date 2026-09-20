<?php

namespace HexaPrWire\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ForceSyncService {
	public const RESULT_META = '_hws_hpr_force_sync_results';

	public const LOG_OPTION = 'hws_hpr_force_sync_log';

	public function __construct(
		private PublicationResolver $resolver,
		private CredentialRepository $credentials,
		private DestinationRegistry $destinations
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function run( int $post_id, int $publication_id, string $mode = 'force' ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			return $this->error( 'invalid_post', 'Invalid press release post.' );
		}

		if ( 'publish' !== $post->post_status ) {
			return $this->error( 'post_not_published', 'Publish the press release before checking or forcing destination links.' );
		}

		$resolved = $this->destinations->enforce( $this->resolver->resolve_post( $post_id ) );
		$target   = null;

		foreach ( $resolved['targets'] as $candidate ) {
			if ( (int) $candidate['publication_id'] === $publication_id ) {
				$target = $candidate;
				break;
			}
		}

		if ( ! is_array( $target ) ) {
			return $this->error(
				'unassigned_publication',
				'This publication is not currently assigned and eligible for this post. Save the publication selection and try again.'
			);
		}

		if ( ! in_array( $mode, [ 'check', 'force' ], true ) ) {
			return $this->error( 'invalid_mode', 'Invalid Force Sync mode.' );
		}

		$live_url    = $this->resolver->build_live_url( $target, $post );
		$endpoint_ok = true;
		$http_code   = null;
		$payload     = null;
		$error       = '';

		if ( 'force' === $mode ) {
			$token = $this->credentials->get();
			if ( '' === $token ) {
				return $this->error( 'missing_credential', 'The encrypted Force Sync credential is not configured.' );
			}

			$response = wp_remote_post(
				(string) $target['endpoint'],
				[
					'timeout'            => 180,
					'redirection'        => 0,
					'sslverify'          => true,
					'reject_unsafe_urls' => true,
					'limit_response_size' => 2 * MB_IN_BYTES,
					'headers'            => [
						'Accept'        => 'application/json',
						'Cache-Control' => 'no-cache',
						'User-Agent'    => 'HexaPRWireCore/' . HPRWC_VERSION,
						'X-HPR-Token'   => $token,
					],
					'body'               => [
						'slug'        => $post->post_name,
						'feed_action' => 'force',
					],
				]
			);

			// Remove the only in-memory reference as soon as the HTTP call is built.
			$token = '';

			if ( is_wp_error( $response ) ) {
				$endpoint_ok = false;
				$error       = $response->get_error_message();
			} else {
				$http_code = (int) wp_remote_retrieve_response_code( $response );
				$payload   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

				if ( $http_code < 200 || $http_code >= 300 || ! is_array( $payload ) || empty( $payload['success'] ) ) {
					$endpoint_ok = false;
					$error       = is_array( $payload ) && ! empty( $payload['message'] )
						? (string) $payload['message']
						: 'The Distributor endpoint did not complete successfully.';
				}
			}
		}

		$live_check = $this->check_live_url( $live_url, get_the_title( $post ) );
		$summary    = [
			'error_code'         => $endpoint_ok ? '' : 'endpoint_failed',
			'time_gmt'           => current_time( 'mysql', true ),
			'mode'               => $mode,
			'post_id'            => $post_id,
			'post_title'         => get_the_title( $post ),
			'source_url'         => get_permalink( $post ),
			'publication_id'     => $publication_id,
			'publication'        => (string) $target['title'],
			'domain'             => (string) $target['domain'],
			'live_url'           => $live_url,
			'endpoint_http'      => $http_code,
			'endpoint_ok'        => $endpoint_ok,
			'endpoint'           => 'force' === $mode ? (string) $target['endpoint'] : '',
			'matched'            => is_array( $payload ) ? (int) ( $payload['matched_feed_items'] ?? 0 ) : null,
			'new_count'          => is_array( $payload ) && isset( $payload['result']['new_live_urls'] ) ? count( (array) $payload['result']['new_live_urls'] ) : 0,
			'updated_count'      => is_array( $payload ) && isset( $payload['result']['updated_live_urls'] ) ? count( (array) $payload['result']['updated_live_urls'] ) : 0,
			'missing_count'      => is_array( $payload ) && isset( $payload['result']['missing_targets'] ) ? count( (array) $payload['result']['missing_targets'] ) : 0,
			'redirects_disabled' => is_array( $payload ) && isset( $payload['redirect_cleanup']['disabled'] ) ? (int) $payload['redirect_cleanup']['disabled'] : 0,
			'public_status'      => (int) $live_check['status_code'],
			'public_ok'          => (bool) $live_check['ok'],
			'title_found'        => (bool) $live_check['title_found'],
			'ok'                 => $endpoint_ok && (bool) $live_check['ok'],
			'message'            => sanitize_text_field( $endpoint_ok ? (string) $live_check['message'] : $error ),
		];

		$this->store_result( $post_id, $publication_id, $summary );
		$this->log_result( $summary );

		return $summary;
	}

	/**
	 * @return array{ok: bool, status_code: int, title_found: bool, message: string}
	 */
	private function check_live_url( string $live_url, string $expected_title ): array {
		$response = wp_remote_get(
			$live_url,
			[
				'timeout'             => 30,
				'redirection'         => 8,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 4 * MB_IN_BYTES,
				'headers'             => [
					'Accept'     => 'text/html',
					'User-Agent' => 'HexaPRWireCore/' . HPRWC_VERSION,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'ok'          => false,
				'status_code' => 0,
				'title_found' => false,
				'message'     => sanitize_text_field( $response->get_error_message() ),
			];
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body_text   = html_entity_decode( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$title_found = '' !== $expected_title && false !== stripos( $body_text, $expected_title );

		return [
			'ok'          => 200 === $status_code && $title_found,
			'status_code' => $status_code,
			'title_found' => $title_found,
			'message'     => 200 === $status_code
				? ( $title_found ? 'Live URL verified.' : 'Live URL loaded, but the post title was not found.' )
				: 'Live URL returned HTTP ' . $status_code . '.',
		];
	}

	/**
	 * @param array<string, mixed> $entry Log entry.
	 */
	private function log_result( array $entry ): void {
		$log = get_option( self::LOG_OPTION, [] );
		$log = is_array( $log ) ? $log : [];
		array_unshift( $log, $entry );
		update_option( self::LOG_OPTION, array_slice( $log, 0, 200 ), false );
	}

	/**
	 * @param array<string, mixed> $result Result to store.
	 */
	private function store_result( int $post_id, int $publication_id, array $result ): void {
		$stored = get_post_meta( $post_id, self::RESULT_META, true );
		$stored = is_array( $stored ) ? $stored : [];
		$stored[ $publication_id ] = $result;
		update_post_meta( $post_id, self::RESULT_META, $stored );
	}

	/**
	 * @return array{ok: false, error_code: string, message: string}
	 */
	private function error( string $code, string $message ): array {
		return [
			'ok'         => false,
			'error_code' => $code,
			'message'    => $message,
		];
	}
}
