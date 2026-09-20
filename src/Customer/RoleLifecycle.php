<?php

namespace HexaPrWire\Core\Customer;

use HexaPrWire\Core\Contracts\Module;

final class RoleLifecycle implements Module {
	public const ROLE = 'hexa_pr_wire_user';

	public static function install(): void {
		$capabilities = [
			'read' => true,
			'upload_files' => true,
			'edit_posts' => true,
			'edit_published_posts' => true,
			'publish_posts' => false,
			'delete_posts' => false,
			'delete_published_posts' => false,
			'edit_others_posts' => false,
			'read_private_posts' => false,
		];
		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, 'Hexa PR Wire Customer', $capabilities );
			return;
		}
		foreach ( $capabilities as $capability => $granted ) {
			$granted ? $role->add_cap( $capability ) : $role->remove_cap( $capability );
		}
	}

	public function register(): void {
		add_action( 'init', [ self::class, 'install' ], 4 );
	}
}
