<?php

$root = dirname( __DIR__ );
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$required = [
	'assets/admin/core.css', 'assets/admin/core.js', 'assets/admin/editor-checklist.js', 'assets/admin/delivery-notifications.js',
	'src/Admin/CustomerActions.php', 'src/Admin/CustomerProfile.php', 'src/Admin/EditorChecklist.php', 'src/Admin/EditorStyles.php',
	'src/Customer/AccessController.php', 'src/Integrations/BillingPricingBridge.php', 'src/Migration/LegacyMigration.php',
	'src/Migration/ParityReport.php', 'src/Cli/Commands.php',
];
foreach ( $required as $relative ) {
	$assert( is_file( $root . '/' . $relative ), "required component {$relative} exists" );
}

$php_files = [];
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	$php_files[] = $file->getPathname();
	$source = (string) file_get_contents( $file->getPathname() );
	$assert( str_contains( $source, 'namespace HexaPrWire\\Core' ), 'source file is namespaced: ' . $file->getFilename() );
	if ( ! str_ends_with( $file->getPathname(), '/Syndication/DeletionManifest.php' ) ) {
		$assert( ! preg_match( "/add_action\\s*\\(\\s*['\"]wp_ajax_nopriv_/", $source ), 'public AJAX is absent from ' . $file->getFilename() );
	}
}

$all_source = implode( "\n", array_map( static fn( string $file ): string => (string) file_get_contents( $file ), $php_files ) );
$assert( ! preg_match( "/(?:get|update|add)_user_meta\\s*\\([^;]*['\"]password['\"]/i", $all_source ), 'plaintext password metadata is never read or written' );
$assert( ! preg_match( "/add_action\\s*\\(\\s*['\"]wp_ajax_nopriv_hprwc_send_delivery_links/", $all_source ), 'delivery email AJAX has no public action' );
$assert( ! preg_match( "/add_action\\s*\\(\\s*['\"]wp_ajax_nopriv_hprwc_notify_draft_update/", $all_source ), 'draft email AJAX has no public action' );

$bootstrap = (string) file_get_contents( $root . '/src/Bootstrap/Plugin.php' );
foreach ( [ 'CustomerActions', 'CustomerProfile', 'EditorChecklist', 'EditorStyles', 'AccessController', 'BillingPricingBridge', 'DeliveryNotifications', 'Commands' ] as $module ) {
	$assert( str_contains( $bootstrap, 'new ' . $module . '(' ) || str_contains( $bootstrap, 'new ' . $module . '()' ), "bootstrap registers {$module}" );
}

$main = (string) file_get_contents( $root . '/hexa-pr-wire-core.php' );
$readme = (string) file_get_contents( $root . '/readme.txt' );
$assert( str_contains( $main, 'Version: 2.0.0' ) && str_contains( $main, "HPRWC_VERSION', '2.0.0" ), 'plugin header and runtime version agree' );
$assert( ! str_contains( $readme, 'Stable tag: 1.0.1' ), 'readme is no longer pinned to the legacy release' );

fwrite( STDOUT, "Static contract tests passed.\n" );
