<?php

namespace HexaPrWire\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PublicationResolver {
	public const TAXONOMY = 'publication';

	public const POST_TYPE = 'publication';

	/**
	 * Resolve the saved publication taxonomy assignment for one source post.
	 *
	 * @return array{term_ids: int[], targets: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
	 */
	public function resolve_post( int $post_id ): array {
		$term_ids = wp_get_object_terms(
			$post_id,
			self::TAXONOMY,
			[
				'fields' => 'ids',
			]
		);

		if ( is_wp_error( $term_ids ) ) {
			return [
				'term_ids' => [],
				'targets'  => [],
				'warnings' => [
					$this->warning( 0, 'Publication taxonomy', 'taxonomy_error', $term_ids->get_error_message() ),
				],
			];
		}

		return $this->resolve_term_ids( array_map( 'absint', $term_ids ) );
	}

	/**
	 * Resolve an explicit taxonomy selection. Used to preview unsaved editor changes.
	 *
	 * @param array<int, mixed> $term_ids Selected publication taxonomy term IDs.
	 * @return array{term_ids: int[], targets: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
	 */
	public function resolve_term_ids( array $term_ids ): array {
		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		sort( $term_ids, SORT_NUMERIC );

		$targets       = [];
		$target_index  = [];
		$warnings      = [];

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, self::TAXONOMY );
			if ( ! $term instanceof \WP_Term ) {
				$warnings[] = $this->warning( $term_id, 'Unknown publication', 'invalid_term', 'The selected publication term no longer exists.' );
				continue;
			}

			$publication_id = $this->mapped_publication_id( $term_id );
			if ( $publication_id <= 0 ) {
				if ( $this->term_has_children( $term_id ) ) {
					// Group headings are intentionally not publication destinations.
					continue;
				}

				$warnings[] = $this->warning(
					$term_id,
					$term->name,
					'missing_mapping',
					'This selected source has no publication record mapping and cannot be forced.'
				);
				continue;
			}

			$publication = get_post( $publication_id );
			if ( ! $publication instanceof \WP_Post || self::POST_TYPE !== $publication->post_type || 'publish' !== $publication->post_status ) {
				$warnings[] = $this->warning(
					$term_id,
					$term->name,
					'invalid_publication',
					'The mapped publication record is missing or not published.'
				);
				continue;
			}

			if ( ! $this->mapping_names_match( $term, $publication ) ) {
				$warnings[] = $this->warning(
					$term_id,
					$term->name,
					'mapping_mismatch',
					sprintf(
						'This term maps to a different publication record (%s). Correct the mapping before forcing.',
						get_the_title( $publication )
					)
				);
				continue;
			}

			$status       = $this->publication_field( 'status', $publication_id );
			$product_tier = (string) $this->publication_field( 'product_tier', $publication_id );

			if ( 0 === (int) $status ) {
				$warnings[] = $this->warning(
					$term_id,
					$term->name,
					'disabled_publication',
					'This selected source is disabled and is not an eligible publishing destination.'
				);
				continue;
			}

			if ( 'standard' !== $product_tier ) {
				$warnings[] = $this->warning(
					$term_id,
					$term->name,
					'ineligible_tier',
					'This selected source is not in the standard publishing tier.'
				);
				continue;
			}

			$prefix = $this->normalize_prefix( (string) $this->publication_field( 'url_press_release_prefix', $publication_id ) );
			$host   = $this->host_from_url( $prefix );

			if ( '' === $prefix || '' === $host ) {
				$warnings[] = $this->warning(
					$term_id,
					$term->name,
					'invalid_prefix',
					'This selected source has no valid press-release URL prefix.'
				);
				continue;
			}

			if ( isset( $target_index[ $publication_id ] ) ) {
				$index = $target_index[ $publication_id ];
				$targets[ $index ]['term_ids'][] = $term_id;
				$warnings[] = $this->warning(
					$term_id,
					$term->name,
					'duplicate_mapping',
					sprintf( 'This term duplicates the %s destination and has been deduplicated.', $targets[ $index ]['title'] )
				);
				continue;
			}

			$target_index[ $publication_id ] = count( $targets );
			$targets[] = [
				'publication_id'   => $publication_id,
				'publication_slug' => $publication->post_name,
				'term_ids'         => [ $term_id ],
				'term_name'        => $term->name,
				'title'            => get_the_title( $publication ),
				'domain'           => preg_replace( '/^www\./i', '', strtolower( $host ) ),
				'prefix'           => $prefix,
				'endpoint'         => 'https://' . $host . '/wp-json/hpr-distributor/v1/force-sync',
			];
		}

		usort(
			$targets,
			static fn ( array $left, array $right ): int => strnatcasecmp( (string) $left['title'], (string) $right['title'] )
		);

		return [
			'term_ids' => $term_ids,
			'targets'  => array_values( $targets ),
			'warnings' => array_values( $warnings ),
		];
	}

	public function build_live_url( array $target, \WP_Post $post ): string {
		return trailingslashit( (string) $target['prefix'] ) . $post->post_name . '/';
	}

	private function mapped_publication_id( int $term_id ): int {
		$value = get_term_meta( $term_id, 'publication', true );

		if ( function_exists( 'get_field' ) ) {
			$acf_value = get_field( 'publication', self::TAXONOMY . '_' . $term_id, false );
			if ( false !== $acf_value && null !== $acf_value && '' !== $acf_value ) {
				$value = $acf_value;
			}
		}

		if ( $value instanceof \WP_Post ) {
			return (int) $value->ID;
		}

		if ( is_array( $value ) ) {
			return absint( $value['ID'] ?? $value['id'] ?? 0 );
		}

		return absint( $value );
	}

	private function publication_field( string $field_name, int $publication_id ) {
		if ( function_exists( 'get_field' ) ) {
			return get_field( $field_name, $publication_id );
		}

		return get_post_meta( $publication_id, $field_name, true );
	}

	private function term_has_children( int $term_id ): bool {
		$children = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'parent'     => $term_id,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
			]
		);

		return ! is_wp_error( $children ) && ! empty( $children );
	}

	private function mapping_names_match( \WP_Term $term, \WP_Post $publication ): bool {
		$term_title        = $this->comparable_name( $term->name );
		$publication_title = $this->comparable_name( get_the_title( $publication ) );
		$term_slug         = $this->comparable_name( $term->slug );
		$publication_slug  = $this->comparable_name( $publication->post_name );

		return ( '' !== $term_title && $term_title === $publication_title )
			|| ( '' !== $term_slug && $term_slug === $publication_slug );
	}

	private function comparable_name( string $value ): string {
		$value = strtolower( remove_accents( wp_strip_all_tags( $value ) ) );
		return preg_replace( '/[^a-z0-9]+/', '', $value ) ?: '';
	}

	private function normalize_prefix( string $prefix ): string {
		$prefix = trim( $prefix );
		if ( '' === $prefix ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $prefix ) ) {
			$prefix = 'https://' . ltrim( $prefix, '/' );
		}

		$parts = wp_parse_url( $prefix );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
			return '';
		}

		return trailingslashit( esc_url_raw( $prefix ) );
	}

	private function host_from_url( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = preg_replace( '/[^a-z0-9.\-]/', '', $host );

		if ( ! is_string( $host ) || '' === $host || false === strpos( $host, '.' ) || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return $host;
	}

	/**
	 * @return array{term_id: int, term_name: string, code: string, message: string}
	 */
	private function warning( int $term_id, string $term_name, string $code, string $message ): array {
		return [
			'term_id'   => $term_id,
			'term_name' => $term_name,
			'code'      => $code,
			'message'   => $message,
		];
	}
}
