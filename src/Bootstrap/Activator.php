<?php

namespace HexaPrWire\Core\Bootstrap;

use HexaPrWire\Core\Customer\RoleLifecycle;

final class Activator {
	public static function activate(): void {
		RoleLifecycle::install();
		update_option( 'hprwc_version', HPRWC_VERSION, false );
		update_option( 'hprwc_migration_state', [ 'version' => HPRWC_VERSION, 'activated_at' => gmdate( 'c' ) ], false );
		flush_rewrite_rules( false );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'hprwc_maintenance' );
		flush_rewrite_rules( false );
	}
}
