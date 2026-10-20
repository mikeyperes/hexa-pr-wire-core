<?php

namespace HexaPrWire\Core\Customer;

/**
 * Who a release belongs to. The public author can be Hexa PR Wire or anyone an
 * administrator picks, so ownership (access, notifications, counts) follows
 * "Submitted by" when that is a customer, and the author otherwise.
 */
final class ReleaseOwner {
	public static function id( \WP_Post $post ): int {
		$submitted = (int) get_post_meta( $post->ID, 'submitted_by', true );
		return $submitted > 0 && self::is_customer( $submitted ) ? $submitted : (int) $post->post_author;
	}

	/** @return int[] IDs of every release this user owns. */
	public static function post_ids( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_author, m.meta_value AS submitted_by FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'submitted_by' WHERE p.post_type = 'post' AND ( p.post_author = %d OR m.meta_value = %s )",
			$user_id,
			(string) $user_id
		) );
		$ids = [];
		foreach ( (array) $rows as $row ) {
			$submitted = (int) $row->submitted_by;
			$owner = $submitted > 0 && self::is_customer( $submitted ) ? $submitted : (int) $row->post_author;
			if ( $owner === $user_id ) {
				$ids[] = (int) $row->ID;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private static function is_customer( int $user_id ): bool {
		static $cache = [];
		if ( ! isset( $cache[ $user_id ] ) ) {
			$user = get_userdata( $user_id );
			$cache[ $user_id ] = $user instanceof \WP_User && in_array( RoleLifecycle::ROLE, (array) $user->roles, true );
		}
		return $cache[ $user_id ];
	}
}
