<?php

namespace HexaPrWire\Core\Infrastructure\WordPress;

use HexaPrWire\Core\Contracts\CustomerPolicyRepository;
use HexaPrWire\Core\Customer\RoleLifecycle;
use HexaPrWire\Core\Customer\SubmissionMode;

final class WordPressCustomerPolicyRepository implements CustomerPolicyRepository {
	public const MODE_META = 'hprwc_submission_mode';
	public const ACCESS_MODE_META = 'hprwc_publication_access_mode';
	public const PUBLICATIONS_META = 'hprwc_allowed_publications';
	public const EXCLUDED_META = 'hprwc_excluded_publications';
	public const PRICES_META = 'hprwc_publication_prices';

	public function is_customer( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user instanceof \WP_User && in_array( RoleLifecycle::ROLE, (array) $user->roles, true );
	}

	public function mode( int $user_id ): string {
		$stored = get_user_meta( $user_id, self::MODE_META, true );
		if ( '' !== (string) $stored ) {
			return SubmissionMode::normalize( $stored );
		}
		return '1' === (string) get_user_meta( $user_id, 'free_publishing', true )
			? SubmissionMode::CREATE_PUBLISH
			: SubmissionMode::EDIT_EXISTING;
	}

	public function publication_access_mode( int $user_id ): string {
		return self::normalize_access_mode( get_user_meta( $user_id, self::ACCESS_MODE_META, true ) );
	}

	public function publication_access_configured( int $user_id ): bool {
		return self::ACCESS_UNRESTRICTED !== $this->publication_access_mode( $user_id );
	}

	public function allowed_publications( int $user_id ): array {
		return $this->stored_ids( $user_id, self::PUBLICATIONS_META );
	}

	public function excluded_publications( int $user_id, bool $with_descendants = true ): array {
		$ids = $this->stored_ids( $user_id, self::EXCLUDED_META );
		if ( ! $with_descendants ) {
			return $ids;
		}
		foreach ( $ids as $term_id ) {
			$children = get_term_children( $term_id, 'publication' );
			$ids = array_merge( $ids, is_wp_error( $children ) ? [] : array_map( 'absint', $children ) );
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	public function publication_prices( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::PRICES_META, true );
		$stored = is_array( $stored ) ? $stored : [];
		$prices = [];
		foreach ( $stored as $term_id => $price ) {
			$term_id = absint( $term_id );
			if ( $term_id <= 0 || ! is_scalar( $price ) || ! is_numeric( $price ) || (float) $price < 0 ) {
				continue;
			}
			$prices[ $term_id ] = number_format( (float) $price, 2, '.', '' );
		}
		ksort( $prices, SORT_NUMERIC );
		return $prices;
	}

	public function save( int $user_id, string $mode, array $publication_ids, array $prices = [], string $publication_access_mode = self::ACCESS_UNRESTRICTED, ?array $excluded_publication_ids = null ): void {
		$valid_price_ids = $this->existing_publication_ids( array_keys( $prices ) );
		$clean_prices = [];
		foreach ( $prices as $term_id => $price ) {
			$term_id = absint( $term_id );
			if ( in_array( $term_id, $valid_price_ids, true ) && is_scalar( $price ) && '' !== trim( (string) $price ) && is_numeric( $price ) && (float) $price >= 0 ) {
				$clean_prices[ $term_id ] = number_format( (float) $price, 2, '.', '' );
			}
		}
		update_user_meta( $user_id, self::MODE_META, SubmissionMode::normalize( $mode ) );
		update_user_meta( $user_id, self::ACCESS_MODE_META, self::normalize_access_mode( $publication_access_mode ) );
		update_user_meta( $user_id, self::PUBLICATIONS_META, $this->existing_publication_ids( $publication_ids ) );
		if ( null !== $excluded_publication_ids ) {
			update_user_meta( $user_id, self::EXCLUDED_META, $this->existing_publication_ids( $excluded_publication_ids ) );
		}
		update_user_meta( $user_id, self::PRICES_META, $clean_prices );
	}

	private static function normalize_access_mode( mixed $value ): string {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		return in_array( $value, [ self::ACCESS_RESTRICTED, self::ACCESS_EXCLUDED ], true ) ? $value : self::ACCESS_UNRESTRICTED;
	}

	/** @return int[] */
	private function stored_ids( int $user_id, string $meta_key ): array {
		$stored = get_user_meta( $user_id, $meta_key, true );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $stored ) ? $stored : [] ) ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/** @param array<int|string,mixed> $ids @return int[] the given IDs that are existing publication terms */
	private function existing_publication_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$valid = get_terms( [ 'taxonomy' => 'publication', 'hide_empty' => false, 'fields' => 'ids', 'include' => $ids ?: [ 0 ] ] );
		return is_wp_error( $valid ) ? [] : array_values( array_map( 'absint', $valid ) );
	}
}
