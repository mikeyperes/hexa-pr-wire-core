<?php

$root = dirname( __DIR__ );
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$api = (string) file_get_contents( $root . '/src/Onboarding/OnboardingApi.php' );
$registry = (string) file_get_contents( $root . '/src/DestinationRegistry.php' );
$feed = (string) file_get_contents( $root . '/src/Syndication/FeedRenderer.php' );
$bootstrap = (string) file_get_contents( $root . '/src/Bootstrap/Plugin.php' );

foreach ( [ "'/onboarding'", "'/onboarding/outlet'", "'/onboarding/rollback'" ] as $route ) {
	$assert( str_contains( $api, $route ), "Core registers {$route}" );
}
$assert( str_contains( $api, "current_user_can( 'manage_options' )" ), 'all onboarding routes require administrator capability' );
$assert( ! str_contains( $api, 'wp_ajax_nopriv_' ), 'onboarding exposes no unauthenticated AJAX action' );
$assert( str_contains( $api, 'reject_sensitive_payload' ) && str_contains( $api, "'application_password'" ), 'onboarding rejects secret-bearing fields' );
$assert( str_contains( $api, 'hierarchy_revision' ) && str_contains( $api, 'plan_fingerprint' ), 'onboarding binds hierarchy and plan revisions' );
$assert( str_contains( $api, 'media_retained_for_review' ) && ! str_contains( $api, 'wp_delete_attachment' ), 'rollback retains source media' );
$assert( str_contains( $api, "'logo_attachment_id'" ) && str_contains( $api, "'icon_attachment_id'" ), 'source logo and icon receipts are returned' );
$assert( str_contains( $registry, 'public function approve(' ) && str_contains( $registry, 'public function restore(' ), 'destination approvals support bounded apply and rollback' );
$assert( 1 === substr_count( $feed, 'xmlns:media="http://search.yahoo.com/mrss/"' ), 'source feed declares Media RSS exactly once' );
$assert( str_contains( $feed, "preg_replace( '/\\s*xmlns:(?:media|hpr)=" ), 'source feed removes duplicate hook-provided namespaces' );
$assert( str_contains( $feed, 'ob_get_level() > $preamble_level' ), 'source feed tolerates namespace hooks that close their own output buffer' );
$assert( str_contains( $feed, '<media:content' ) && str_contains( $feed, '<hpr:canonicalUrl>' ), 'source feed exposes remote image and canonical release metadata' );
$assert( str_contains( $bootstrap, 'new OnboardingApi(' ), 'Core boots the onboarding module' );

fwrite( STDOUT, "Onboarding contract tests passed.\n" );
