<?php

namespace HexaPrWire\Core\Support;

use Hexa\PluginCore\ActivityLog\ActivityLogConfig;
use Hexa\PluginCore\ActivityLog\ActivityLogEntry;
use Hexa\PluginCore\ActivityLog\ActivityLogger;

final class Activity {
	private static ?ActivityLogger $logger = null;

	public static function add( string $message, string $level = 'info', array $context = [], string $source = 'core' ): void {
		self::logger()->add( new ActivityLogEntry(
			$message,
			self::sanitize_context( $context ),
			(string) get_current_user_id(),
			$source,
			gmdate( 'c' ),
			$level
		) );
	}

	public static function logger(): ActivityLogger {
		return self::$logger ??= new ActivityLogger( new ActivityLogConfig( [
			'id' => 'hprwc-activity',
			'title' => 'Hexa PR Wire Activity',
			'storage' => ActivityLogConfig::STORAGE_PERMANENT,
			'storage_key' => 'hprwc_activity_log',
			'max_entries' => 300,
		] ) );
	}

	private static function sanitize_context( array $context ): array {
		foreach ( [ 'password', 'token', 'secret', 'credential', 'authorization' ] as $sensitive ) {
			unset( $context[ $sensitive ] );
		}
		return $context;
	}
}
