<?php

$root = dirname( __DIR__ );
require_once $root . '/vendor/hexa/plugin-core/bootstrap.php';
$expected = trim( (string) file_get_contents( $root . '/vendor/hexa/plugin-core/PACKAGE_HASH' ) );
$actual = \HexaPluginCorePackageRegistry::source_hash( $root . '/vendor/hexa/plugin-core' );
if ( '' === $expected || ! hash_equals( $expected, $actual ) ) {
	fwrite( STDERR, "FAIL: Shared Core package hash mismatch.\nExpected: {$expected}\nActual: {$actual}\n" );
	exit( 1 );
}
if ( version_compare( trim( (string) file_get_contents( $root . '/vendor/hexa/plugin-core/VERSION' ) ), '3.0.6', '<' ) ) {
	fwrite( STDERR, "FAIL: Shared Core version is older than 3.0.6.\n" );
	exit( 1 );
}
fwrite( STDOUT, "Shared Core package integrity passed.\n" );
