<?php

namespace HexaPrWire\Core\Infrastructure\WordPress;

use HexaPrWire\Core\Contracts\CustomerPolicyRepository;
use HexaPrWire\Core\Customer\RoleLifecycle;
use HexaPrWire\Core\Customer\SubmissionMode;

final class WordPressCustomerPolicyRepository implements CustomerPolicyRepository {
	public const MODE_META = 'hprwc_submission_mode';
	public const PUBLICATIONS_META = 'hprwc_allowed_publications';
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

	public function publication_access_configured( int $user_id ): bool {
		return metadata_exists( 'user', $user_id, self::PUBLICATIONS_META );
	}

	public function allowed_publications( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::PUBLICATIONS_META, true );
		$stored = is_array( $stored ) ? $stored : [];
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $stored ) ) ) );
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

	public function save( int $user_id, string $mode, array $publication_ids, array $prices = [] ): void {
		$publication_ids = array_values( array_unique( array_filter( array_map( 'absint', $publication_ids ) ) ) );
		$valid_publications = get_terms( [ 'taxonomy' => 'publication', 'hide_empty' => false, 'fields' => 'ids', 'include' => $publication_ids ?: [ 0 ] ] );
		$valid_publications = is_wp_error( $valid_publications ) ? [] : array_values( array_map( 'absint', $valid_publications ) );
		$price_ids = array_values( array_unique( array_filter( array_map( 'absint', array_keys( $prices ) ) ) ) );
		$valid_price_ids = get_terms( [ 'taxonomy' => 'publication', 'hide_empty' => false, 'fields' => 'ids', 'include' => $price_ids ?: [ 0 ] ] );
		$valid_price_ids = is_wp_error( $valid_price_ids ) ? [] : array_values( array_map( 'absint', $valid_price_ids ) );
		$clean_prices = [];
		foreach ( $prices as $term_id => $price ) {
			$term_id = absint( $term_id );
			if ( in_array( $term_id, $valid_price_ids, true ) && is_scalar( $price ) && '' !== trim( (string) $price ) && is_numeric( $price ) && (float) $price >= 0 ) {
				$clean_prices[ $term_id ] = number_format( (float) $price, 2, '.', '' );
			}
		}
		update_user_meta( $user_id, self::MODE_META, SubmissionMode::normalize( $mode ) );
		update_user_meta( $user_id, self::PUBLICATIONS_META, $valid_publications );
		update_user_meta( $user_id, self::PRICES_META, $clean_prices );
	}
}
