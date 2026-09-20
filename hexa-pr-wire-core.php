<?php
/**
 * Plugin Name: Hexa PR Wire Core
 * Plugin URI: https://hexaprwire.com/
 * Description: Source-side customer access, publication registry, editorial workflow, feeds, links, and secure delivery controls for Hexa PR Wire.
 * Version: 2.1.1
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Hexa PR Wire
 * Text Domain: hexa-pr-wire-core
 */

namespace HexaPrWire\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HPRWC_VERSION', '2.1.1' );
define( 'HPRWC_FILE', __FILE__ );
define( 'HPRWC_DIR', plugin_dir_path( __FILE__ ) );
define( 'HPRWC_URL', plugin_dir_url( __FILE__ ) );

require_once HPRWC_DIR . 'vendor/hexa/plugin-core/bootstrap.php';

\HexaPluginCorePackageRegistry::register_candidate(
	'hexa-pr-wire-core',
	HPRWC_DIR . 'vendor/hexa/plugin-core',
	[
		'minimum_version' => '3.0.6',
		'maximum_version' => '3.0.6',
		'priority'        => 40,
	]
);

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$file = HPRWC_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	},
	true,
	true
);

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->register();
	},
	30
);

register_activation_hook( __FILE__, [ Bootstrap\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Bootstrap\Activator::class, 'deactivate' ] );
