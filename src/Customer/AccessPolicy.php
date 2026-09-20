<?php

namespace HexaPrWire\Core\Customer;

use HexaPrWire\Core\Contracts\CustomerPolicyRepository;

final class AccessPolicy {
	public function __construct( private CustomerPolicyRepository $policies ) {}

	public function is_customer( int $user_id ): bool {
		return $this->policies->is_customer( $user_id );
	}

	public function mode( int $user_id ): string {
		return $this->policies->mode( $user_id );
	}

	public function can_create( int $user_id ): bool {
		return ! $this->is_customer( $user_id ) || SubmissionMode::can_create( $this->mode( $user_id ) );
	}

	public function can_publish( int $user_id ): bool {
		return ! $this->is_customer( $user_id ) || SubmissionMode::can_publish( $this->mode( $user_id ) );
	}

	public function can_edit_post( int $user_id, \WP_Post $post ): bool {
		if ( ! $this->is_customer( $user_id ) || 'post' !== $post->post_type ) {
			return true;
		}
		return SubmissionMode::is_full( $this->mode( $user_id ) ) || (int) $post->post_author === $user_id;
	}

	public function can_use_publication( int $user_id, int $term_id ): bool {
		if ( ! $this->is_customer( $user_id ) || SubmissionMode::is_full( $this->mode( $user_id ) ) ) {
			return true;
		}
		return in_array( $term_id, $this->policies->allowed_publications( $user_id ), true );
	}

	/** @param int[] $term_ids @return int[] */
	public function filter_publications( int $user_id, array $term_ids ): array {
		return array_values( array_filter( array_unique( array_map( 'absint', $term_ids ) ), fn( int $id ): bool => $this->can_use_publication( $user_id, $id ) ) );
	}
}
