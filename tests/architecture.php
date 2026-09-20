<?php

$root = dirname( __DIR__ );
$files = [
	$root . '/src/ForceSyncService.php',
	$root . '/src/PublicationResolver.php',
	$root . '/src/DestinationRegistry.php',
	$root . '/src/Admin/ForceSyncAdmin.php',
];

foreach ( $files as $file ) {
	if ( ! is_file( $file ) ) {
		fwrite( STDERR, "Missing required file: {$file}\n" );
		exit( 1 );
	}
}

$transport = file_get_contents( $root . '/src/ForceSyncService.php' );
$admin     = file_get_contents( $root . '/src/Admin/ForceSyncAdmin.php' );
$resolver  = file_get_contents( $root . '/src/PublicationResolver.php' );

$assertions = [
	[ str_contains( $transport, 'wp_remote_post(' ), 'Force transport must use POST.' ],
	[ str_contains( $transport, "'X-HPR-Token'" ), 'Force transport must use the X-HPR-Token header.' ],
	[ str_contains( $transport, "'sslverify'          => true" ), 'TLS verification must remain enabled.' ],
	[ str_contains( $transport, "'redirection'        => 0" ), 'Credential-bearing Force requests must not follow redirects.' ],
	[ ! str_contains( $transport, "'key'         =>" ), 'The credential must not be placed in a query argument.' ],
	[ str_contains( $resolver, 'resolve_post' ), 'Saved taxonomy resolution is required.' ],
	[ str_contains( $admin, "'unassigned_publication'" ), 'Tampered unassigned targets must receive an explicit rejection.' ],
	[ str_contains( $admin, "current_user_can( 'manage_options' )" ), 'Force Sync must require an administrator capability.' ],
	[ str_contains( $admin, 'legacy_action_disabled' ), 'Legacy force and bulk AJAX actions must be blocked during migration.' ],
];

foreach ( $assertions as [ $passed, $message ] ) {
	if ( ! $passed ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

fwrite( STDOUT, "Architecture checks passed.\n" );
