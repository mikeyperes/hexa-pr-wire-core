<?php

declare( strict_types=1 );

use Hexa\PluginCore\PluginUpdates\DirectPluginInstaller;
use Hexa\PluginCore\PluginUpdates\GitHubPluginUpdater;
use Hexa\PluginCore\PluginUpdates\UpdaterFilesystem;
use Hexa\PluginCore\PluginUpdates\UpdaterConfig;

define( 'ABSPATH', sys_get_temp_dir() . '/hexa-core-updater-wordpress-' . bin2hex( random_bytes( 6 ) ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );

final class WP_Error {
    public function __construct( private string $code, private string $message ) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function get_transient( string $key ): mixed { return $GLOBALS['updater_transient'] ?? false; }
function set_transient( string $key, mixed $value, int $expiration ): void { $GLOBALS['updater_transient'] = $value; }
function untrailingslashit( string $value ): string { return rtrim( $value, '/\\' ); }

$GLOBALS['updater_site_active'] = [];
$GLOBALS['updater_network_active'] = [];
$GLOBALS['updater_cache_cleared'] = false;
$GLOBALS['updater_activation_calls'] = [];
$GLOBALS['updater_activation_failure'] = false;

function get_option( string $key, mixed $default = false ): mixed {
    return 'active_plugins' === $key ? $GLOBALS['updater_site_active'] : $default;
}

function is_plugin_active_for_network( string $plugin ): bool {
    return in_array( $plugin, $GLOBALS['updater_network_active'], true );
}

function wp_clean_plugins_cache( bool $clear_update_cache = true ): void {
    $GLOBALS['updater_cache_cleared'] = true;
}

function activate_plugin( string $plugin, string $redirect = '', bool $network_wide = false, bool $silent = false ): null|WP_Error {
    $GLOBALS['updater_activation_calls'][] = compact( 'plugin', 'network_wide', 'silent' );
    if ( ! $GLOBALS['updater_cache_cleared'] ) {
        return new WP_Error( 'invalid_plugin', 'Plugin discovery is stale.' );
    }
    if ( $GLOBALS['updater_activation_failure'] ) {
        return new WP_Error( 'activation_failed', 'Synthetic activation failure.' );
    }
    if ( $network_wide ) {
        $GLOBALS['updater_network_active'][] = $plugin;
        $GLOBALS['updater_network_active'] = array_values( array_unique( $GLOBALS['updater_network_active'] ) );
    } else {
        $GLOBALS['updater_site_active'][] = $plugin;
        $GLOBALS['updater_site_active'] = array_values( array_unique( $GLOBALS['updater_site_active'] ) );
    }
    return null;
}

final class UpdaterFilesystemDouble {
    public string $method = 'direct';

    public function exists( string $path ): bool { return file_exists( $path ); }
    public function delete( string $path, bool $recursive = false ): bool { return UpdaterFilesystem::delete_path( $path ); }
    public function move( string $source, string $destination, bool $overwrite = false ): bool { return rename( $source, $destination ); }
    public function is_dir( string $path ): bool { return is_dir( $path ); }
}

$passed = 0;
$failed = 0;
function updater_activation_expect( bool $condition, string $message ): void {
    global $passed, $failed;
    if ( $condition ) {
        ++$passed;
        echo "PASS {$message}\n";
        return;
    }
    ++$failed;
    echo "FAIL {$message}\n";
}

$root = dirname( __DIR__ );
require $root . '/src/CoreContracts/ModuleInterface.php';
require $root . '/src/PluginUpdates/UpdaterConfig.php';
require $root . '/src/PluginUpdates/GitHubVersionClient.php';
require $root . '/src/PluginUpdates/UpdaterFilesystem.php';
require $root . '/src/PluginUpdates/GitHubPluginUpdater.php';
require $root . '/src/PluginUpdates/UpdateProgressStore.php';
require $root . '/src/PluginUpdates/DirectPluginInstaller.php';

global $wp_filesystem;
$wp_filesystem = new UpdaterFilesystemDouble();

$runtime = 'hws-base-tools-main/hws-base-tools.php';
$canonical = 'hws-base-tools/hws-base-tools.php';
$config = new UpdaterConfig( [
    'plugin_basename' => $runtime,
    'canonical_plugin_basename' => $canonical,
    'plugin_slug' => 'hws-base-tools',
    'proper_folder_name' => 'hws-base-tools',
    'runtime_folder_name' => 'hws-base-tools-main',
    'plugin_starter_file' => 'hws-base-tools.php',
    'github_repo' => 'mikeyperes/hws-base-tools',
    'version' => '13.2.7',
    'plugin_name' => 'HWS Base Tools',
] );
$hook = [ 'plugin' => $runtime, 'type' => 'plugin', 'action' => 'update' ];
$result = [ 'destination' => WP_PLUGIN_DIR . '/hws-base-tools/' ];

$runtime_dir   = WP_PLUGIN_DIR . '/hws-base-tools-main';
$canonical_dir = WP_PLUGIN_DIR . '/hws-base-tools';
$package_root  = ABSPATH . 'wp-content/upgrade/update';
$extracted     = $package_root . '/hws-base-tools-release';

register_shutdown_function( static fn() => UpdaterFilesystem::delete_directory( ABSPATH ) );
wp_mkdir_p( $runtime_dir . '/.git' );
$git_head = "ref: refs/heads/main\n";
file_put_contents( $runtime_dir . '/.git/HEAD', $git_head );

$updater = new GitHubPluginUpdater( $config );
$download_refusal = $updater->pre_download( false, 'update.zip', null, [ 'plugin' => $runtime ] );
updater_activation_expect(
    is_wp_error( $download_refusal ) && 'hexa_plugin_core_git_checkout' === $download_refusal->get_error_code()
    && 'This plugin copy is updated through Git.' === $download_refusal->get_error_message(),
    'the native bulk updater refuses a Git copy before downloading the package'
);
updater_activation_expect(
    'cached.zip' === $updater->pre_download( 'cached.zip', 'update.zip', null, [ 'plugin' => 'another/another.php' ] ),
    'the download hook leaves other plugins’ update packages alone'
);
$refusal = $updater->pre_install( true, $hook );
updater_activation_expect(
    is_wp_error( $refusal ) && 'This plugin copy is updated through Git.' === $refusal->get_error_message(),
    'the native updater tells the administrator to update the Git copy through Git'
);
$direct_refusal = ( new DirectPluginInstaller( $config ) )->run();
updater_activation_expect(
    is_wp_error( $refusal ) && is_wp_error( $direct_refusal )
    && 'hexa_plugin_core_git_checkout' === $direct_refusal->get_error_code()
    && $refusal->get_error_message() === $direct_refusal->get_error_message(),
    'the Update Center refuses the same Git copy with the same notice'
);
updater_activation_expect(
    is_file( $runtime_dir . '/.git/HEAD' ) && $git_head === file_get_contents( $runtime_dir . '/.git/HEAD' ),
    'neither update path changes the Git metadata'
);
UpdaterFilesystem::delete_directory( $runtime_dir . '/.git' );

wp_mkdir_p( $canonical_dir );
file_put_contents( $canonical_dir . '/.git', 'gitdir: /example/worktree' );
$worktree_refusal = $updater->pre_install( true, $hook );
updater_activation_expect(
    is_wp_error( $worktree_refusal ) && is_file( $canonical_dir . '/.git' ),
    'a Git worktree marker in the canonical destination also stops the update'
);
unlink( $canonical_dir . '/.git' );

wp_mkdir_p( $extracted . '/includes/.git' );
file_put_contents( $extracted . '/includes/.git/HEAD', $git_head );
file_put_contents( $extracted . '/hws-base-tools.php', 'new plugin version' );
file_put_contents( $runtime_dir . '/hws-base-tools.php', 'previous plugin version' );
file_put_contents( $runtime_dir . '/.DS_Store', 'live plugin marker' );

updater_activation_expect(
    false === $updater->pre_download( false, 'update.zip', null, $hook ),
    'the native updater permits a normal installation to download its package'
);
updater_activation_expect( true === $updater->pre_install( true, $hook ), 'the native updater permits a normal installed copy' );
$outside_package = $updater->source_selection( $runtime_dir, $package_root, null, $hook );
updater_activation_expect(
    is_wp_error( $outside_package ) && is_file( $runtime_dir . '/.DS_Store' ),
    'source selection rejects a live folder supplied as the extracted source'
);
$selected = $updater->source_selection( $extracted, $package_root, null, $hook );
updater_activation_expect(
    $package_root . '/hws-base-tools/' === $selected && ! file_exists( $selected . 'includes/.git' )
    && is_file( $runtime_dir . '/.DS_Store' ),
    'source selection cleans and normalizes only the extracted package'
);
updater_activation_expect(
    is_string( $selected ) && true === UpdaterFilesystem::copy_directory_clean( $selected, $canonical_dir, false )
    && 'new plugin version' === file_get_contents( $canonical_dir . '/hws-base-tools.php' ),
    'the normalized package installs new plugin files in the disposable normal copy'
);

$GLOBALS['updater_site_active'] = [ $runtime ];
$updater = new GitHubPluginUpdater( $config );
$updater->pre_install( true, $hook );
$GLOBALS['updater_site_active'] = [];
$restored = $updater->post_install( true, $hook, $result );
updater_activation_expect( true === $restored, 'post-install returns the WordPress success response' );
updater_activation_expect( [ $canonical ] === $GLOBALS['updater_site_active'], 'a site-active noncanonical install is restored under the canonical basename' );
updater_activation_expect( $GLOBALS['updater_cache_cleared'] && true === $GLOBALS['updater_activation_calls'][0]['silent'], 'reactivation clears plugin discovery and runs silently' );

$GLOBALS['updater_site_active'] = [];
$GLOBALS['updater_network_active'] = [];
$GLOBALS['updater_cache_cleared'] = false;
$GLOBALS['updater_activation_calls'] = [];
$inactive = new GitHubPluginUpdater( $config );
$inactive->pre_install( true, $hook );
$inactive->post_install( true, $hook, $result );
updater_activation_expect( [] === $GLOBALS['updater_activation_calls'] && [] === $GLOBALS['updater_site_active'], 'an intentionally inactive plugin remains inactive after update' );

$GLOBALS['updater_network_active'] = [ $runtime ];
$network = new GitHubPluginUpdater( $config );
$network->pre_install( true, $hook );
$GLOBALS['updater_network_active'] = [];
$network->post_install( true, $hook, $result );
updater_activation_expect( [ $canonical ] === $GLOBALS['updater_network_active'] && true === $GLOBALS['updater_activation_calls'][0]['network_wide'], 'network activation scope is preserved under the canonical basename' );

$GLOBALS['updater_network_active'] = [];
$GLOBALS['updater_site_active'] = [ $runtime ];
$GLOBALS['updater_activation_failure'] = true;
$failure = new GitHubPluginUpdater( $config );
$failure->pre_install( true, $hook );
$GLOBALS['updater_site_active'] = [];
$failed_result = $failure->post_install( true, $hook, $result );
updater_activation_expect( is_wp_error( $failed_result ) && 'hexa_plugin_core_reactivation_failed' === $failed_result->get_error_code(), 'a restoration failure fails the update instead of silently leaving the plugin inactive' );

echo "\n{$passed} passed, {$failed} failed.\n";
exit( 0 === $failed ? 0 : 1 );
