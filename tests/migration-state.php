<?php

require_once dirname( __DIR__ ) . '/src/Migration/LegacyMigration.php';

use HexaPrWire\Core\Migration\LegacyMigration;

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$before = [
	'snippets' => [
		6 => [ 'name' => 'Active', 'active' => true ],
		8 => [ 'name' => 'Inactive', 'active' => false ],
	],
	'cpt_ui_publication' => [ 'name' => 'publication' ],
	'acf_taxonomies' => [
		[ 'ID' => 1, 'status' => 'publish', 'title' => 'Publications', 'key' => 'taxonomy_example' ],
	],
	'acf_taxonomies_active' => [
		[ 'ID' => 1, 'status' => 'publish', 'title' => 'Publications', 'key' => 'taxonomy_example' ],
	],
	'acf_groups' => [
		[ 'ID' => 2, 'status' => 'publish', 'title' => 'Active group', 'key' => 'group_active' ],
		[ 'ID' => 3, 'status' => 'acf-disabled', 'title' => 'Inactive group', 'key' => 'group_inactive' ],
	],
	'acf_groups_active' => [
		[ 'ID' => 2, 'status' => 'publish', 'title' => 'Active group', 'key' => 'group_active' ],
	],
];

$method = new ReflectionMethod( LegacyMigration::class, 'expected_after_state' );
$after = $method->invoke( new LegacyMigration(), $before );

$assert( false === $after['snippets'][6]['active'], 'active snippets are disabled' );
$assert( false === $after['snippets'][8]['active'], 'inactive snippets remain disabled' );
$assert( [] === $after['cpt_ui_publication'], 'CPT UI publication definition is removed' );
$assert( 'acf-disabled' === $after['acf_taxonomies'][0]['status'], 'ACF taxonomy uses native disabled status' );
$assert( 'acf-disabled' === $after['acf_groups'][0]['status'], 'published ACF group uses native disabled status' );
$assert( 'acf-disabled' === $after['acf_groups'][1]['status'], 'already-disabled ACF group remains disabled' );
$assert( [] === $after['acf_taxonomies_active'], 'no ACF taxonomy remains active' );
$assert( [] === $after['acf_groups_active'], 'no ACF group remains active' );

fwrite( STDOUT, "Migration state tests passed.\n" );
