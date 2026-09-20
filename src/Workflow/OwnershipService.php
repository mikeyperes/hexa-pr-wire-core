<?php

namespace HexaPrWire\Core\Workflow;

use HexaPrWire\Core\Contracts\Module;

final class OwnershipService implements Module {
	public function register(): void {
		add_action( 'wp_after_insert_post', [ $this, 'capture_owner' ], 10, 4 );
	}

	/** @param array<string,mixed> $post_before */
	public function capture_owner( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		unset( $update, $post_before );
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) || metadata_exists( 'post', $post_id, 'submitted_by' ) ) {
			return;
		}
		$owner = (int) $post->post_author;
		if ( $owner > 0 ) {
			update_post_meta( $post_id, 'submitted_by', $owner );
			update_post_meta( $post_id, '_submitted_by', 'field_651368fa55448' );
		}
	}
}
