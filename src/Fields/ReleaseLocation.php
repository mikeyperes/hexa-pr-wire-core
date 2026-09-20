<?php

namespace HexaPrWire\Core\Fields;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Customer\AccessPolicy;

final class ReleaseLocation implements Module {
	public function __construct( private AccessPolicy $policy ) {}

	public function register(): void {
		add_filter( 'acf/location/rule_types', [ $this, 'rule_types' ] );
		add_filter( 'acf/location/rule_values/hprwc_release', [ $this, 'rule_values' ] );
		add_filter( 'acf/location/rule_match/hprwc_release', [ $this, 'rule_match' ], 10, 3 );
	}

	/** @param array<string,mixed> $choices @return array<string,mixed> */
	public function rule_types( array $choices ): array {
		$choices['Post']['hprwc_release'] = 'Hexa PR Wire Release';
		return $choices;
	}

	/** @param array<string,string> $choices @return array<string,string> */
	public function rule_values( array $choices ): array {
		$choices['yes'] = 'Yes';
		return $choices;
	}

	/** @param array<string,mixed> $rule @param array<string,mixed> $screen */
	public function rule_match( bool $match, array $rule, array $screen ): bool {
		unset( $match );
		$post_id = absint( $screen['post_id'] ?? $_GET['post'] ?? $_POST['post_ID'] ?? 0 );
		$post_type = sanitize_key( (string) ( $screen['post_type'] ?? get_post_type( $post_id ) ?: '' ) );
		$is_release = 'post' === $post_type && $this->is_release_post( $post_id );
		$expected = 'yes' === (string) ( $rule['value'] ?? 'yes' );
		return '!=' === (string) ( $rule['operator'] ?? '==' ) ? $is_release !== $expected : $is_release === $expected;
	}

	private function is_release_post( int $post_id ): bool {
		if ( $this->policy->is_customer( get_current_user_id() ) ) {
			return true;
		}
		if ( $post_id <= 0 ) {
			return true;
		}
		foreach ( [ 'submitted_by', 'billing_invoice_id', 'invoice_id', 'press_release_date', 'press_release_location' ] as $key ) {
			if ( metadata_exists( 'post', $post_id, $key ) ) {
				return true;
			}
		}
		$slugs = wp_get_post_terms( $post_id, 'category', [ 'fields' => 'slugs' ] );
		return ! is_wp_error( $slugs ) && (bool) array_intersect( [ 'press-release', 'custom-order' ], $slugs );
	}
}
