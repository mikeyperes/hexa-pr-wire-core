<?php

namespace HexaPrWire\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pins publication record IDs to approved destination hosts.
 *
 * Publication URLs are editable content. Force Sync credentials may only be sent
 * to the administrator-approved host snapshot stored by this registry.
 */
final class DestinationRegistry {
	private const OPTION = 'hprwc_approved_destination_hosts';

	public function is_initialized(): bool {
		return is_array( get_option( self::OPTION, null ) );
	}

	/**
	 * Seed once from the audited publication registry. Later host changes fail
	 * closed until an administrator explicitly re-approves the registry.
	 */
	public function seed_from_resolver( PublicationResolver $resolver ): bool {
		if ( $this->is_initialized() || ! current_user_can( 'manage_options' ) ) {
			return $this->is_initialized();
		}

		$term_ids = get_terms(
			[
				'taxonomy'   => PublicationResolver::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);

		if ( is_wp_error( $term_ids ) ) {
			return false;
		}

		$resolved = $resolver->resolve_term_ids( array_map( 'absint', $term_ids ) );
		$approved = [];

		foreach ( $resolved['targets'] as $target ) {
			$publication_id = (int) $target['publication_id'];
			$host           = $this->normalize_host( (string) $target['domain'] );
			if ( $publication_id > 0 && '' !== $host ) {
				$approved[ $publication_id ] = [
					'host'        => $host,
					'approved_at' => gmdate( 'c' ),
				];
			}
		}

		return ! empty( $approved ) && update_option( self::OPTION, $approved, false );
	}

	public function is_approved( int $publication_id, string $host ): bool {
		$approved = get_option( self::OPTION, [] );
		if ( ! is_array( $approved ) || ! isset( $approved[ $publication_id ] ) || ! is_array( $approved[ $publication_id ] ) ) {
			return false;
		}

		$expected = $this->normalize_host( (string) ( $approved[ $publication_id ]['host'] ?? '' ) );
		$actual   = $this->normalize_host( $host );

		return '' !== $expected && '' !== $actual && hash_equals( $expected, $actual );
	}

	/**
	 * Remove unapproved force targets and expose a visible mapping warning.
	 *
	 * @param array{term_ids: int[], targets: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>} $resolved Resolver output.
	 * @return array{term_ids: int[], targets: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
	 */
	public function enforce( array $resolved ): array {
		$targets = [];

		foreach ( $resolved['targets'] as $target ) {
			if ( $this->is_approved( (int) $target['publication_id'], (string) $target['domain'] ) ) {
				$targets[] = $target;
				continue;
			}

			$resolved['warnings'][] = [
				'term_id'   => (int) ( $target['term_ids'][0] ?? 0 ),
				'term_name' => (string) $target['term_name'],
				'code'      => 'unapproved_destination',
				'message'   => 'The destination host is not in the approved Force Sync registry. An administrator must review and approve the host before credentials can be sent.',
			];
		}

		$resolved['targets'] = $targets;
		return $resolved;
	}

	private function normalize_host( string $host ): string {
		$host = strtolower( trim( $host ) );
		$host = preg_replace( '/^www\./i', '', $host );
		$host = preg_replace( '/[^a-z0-9.\-]/', '', (string) $host );

		return is_string( $host ) ? $host : '';
	}
}

