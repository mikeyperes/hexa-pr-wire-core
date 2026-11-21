<?php

declare( strict_types=1 );

use Hexa\PluginCore\PluginUpdates\LegacyPluginBasenameMigration;
use Hexa\PluginCore\PluginUpdates\UpdaterConfig;

require_once dirname( __DIR__ ) . '/src/PluginUpdates/UpdaterConfig.php';
require_once dirname( __DIR__ ) . '/src/PluginUpdates/LegacyPluginBasenameMigration.php';

function get_plugin_data( string $file, bool $markup, bool $translate ): array {
    return [ 'Name' => 'Managed plugin', 'Version' => '1.2.3' ];
}

function plugin_basename( string $file ): string {
    return basename( dirname( $file ) ) . '/' . basename( $file );
}

function get_option( string $key, array $default ): array {
    return $GLOBALS['fixture_site_plugins'];
}

function update_option( string $key, array $value, ?bool $autoload = null ): void {
    $GLOBALS['fixture_site_plugins'] = $value;
    $GLOBALS['fixture_writes'][] = [ $key, $autoload ];
}

function is_multisite(): bool {
    return $GLOBALS['fixture_multisite'];
}

function get_site_option( string $key, array $default ): array {
    return $GLOBALS['fixture_network_plugins'];
}

function update_site_option( string $key, array $value ): void {
    $GLOBALS['fixture_network_plugins'] = $value;
    $GLOBALS['fixture_writes'][] = [ $key ];
}

function expect_value( mixed $expected, mixed $actual, string $why ): void {
    if ( $expected !== $actual ) {
        throw new RuntimeException( $why . ': ' . var_export( $actual, true ) );
    }
}

$config = UpdaterConfig::from_managed_plugin_file(
    '/plugins/installed-copy/main.php',
    'example/example-plugin',
    'example-plugin',
    'example_core_updater',
    [ 'nonce_action' => 'legacy_nonce', 'nonce_param' => 'security' ]
);
expect_value( 'installed-copy/main.php', $config->plugin_basename(), 'Runtime basename comes from the file' );
expect_value( 'installed-copy', $config->runtime_folder_name(), 'Runtime folder stays separate' );
expect_value( 'example-plugin/main.php', $config->canonical_plugin_basename(), 'Canonical basename uses the managed slug' );
expect_value( 'example-plugin', $config->proper_folder_name(), 'Canonical installation folder uses the managed slug' );
expect_value( 'legacy_nonce', $config->nonce_action(), 'Host nonce override is retained' );
expect_value( 'security', $config->nonce_param(), 'Host nonce field override is retained' );
expect_value( 'example_core_updater', $config->ajax_action_prefix(), 'Host AJAX prefix is retained' );
expect_value( 'example_core_update_progress', $config->progress_key(), 'Progress key uses the same prefix' );
expect_value( '1.2.3', $config->version(), 'Version comes from the main file header' );

$legacy = 'example-plugin/initialization.php';
$canonical = 'example-plugin/main.php';
$other = 'other-plugin/main.php';

foreach ( [ false, true ] as $all ) {
    $GLOBALS['fixture_site_plugins'] = [ $legacy, $other, $canonical, $other ];
    $GLOBALS['fixture_network_plugins'] = [ $canonical => 12, $legacy => 7, $other => 17 ];
    $GLOBALS['fixture_multisite'] = true;
    $GLOBALS['fixture_writes'] = [];
    $migrate = static fn() => LegacyPluginBasenameMigration::migrate( $legacy, $canonical, $all, $all, $all ? false : null );
    $migrate();
    expect_value( $all ? [ $canonical, $other ] : [ $canonical, $other, $other ], $GLOBALS['fixture_site_plugins'], 'Site duplicate policy is retained' );
    expect_value( [ $canonical => $all ? 7 : 12, $other => 17 ], $GLOBALS['fixture_network_plugins'], 'Network timestamp precedence is retained' );
    expect_value( [ [ 'active_plugins', $all ? false : null ], [ 'active_sitewide_plugins' ] ], $GLOBALS['fixture_writes'], 'Autoload and activation scope are retained' );
    $GLOBALS['fixture_writes'] = [];
    $migrate();
    expect_value( [], $GLOBALS['fixture_writes'], 'A second request performs no writes' );
}

$GLOBALS['fixture_site_plugins'] = [ $legacy, $other ];
$GLOBALS['fixture_network_plugins'] = [ $legacy => 7 ];
$GLOBALS['fixture_multisite'] = false;
$GLOBALS['fixture_writes'] = [];
LegacyPluginBasenameMigration::migrate( $legacy, $canonical );
expect_value( [ $canonical, $other ], $GLOBALS['fixture_site_plugins'], 'A single site preserves plugin activation' );
expect_value( [ $legacy => 7 ], $GLOBALS['fixture_network_plugins'], 'Single sites leave the network options alone' );

echo "PASS: Managed updater configuration and legacy basename migration.\n";
