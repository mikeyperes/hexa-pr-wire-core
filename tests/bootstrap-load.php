<?php

define( 'ABSPATH', __DIR__ . '/wordpress-fixture/' );

$GLOBALS['hprwc_test_hooks'] = [];
$GLOBALS['hprwc_test_shortcodes'] = [];

function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['hprwc_test_hooks'][] = [ 'action', $hook, $priority, $accepted_args, $callback ];
	return true;
}
function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['hprwc_test_hooks'][] = [ 'filter', $hook, $priority, $accepted_args, $callback ];
	return true;
}
function add_shortcode( string $tag, mixed $callback ): void { $GLOBALS['hprwc_test_shortcodes'][] = $tag; }
function register_activation_hook( string $file, mixed $callback ): void {}
function register_deactivation_hook( string $file, mixed $callback ): void {}
function plugin_dir_path( string $file ): string { return rtrim( dirname( $file ), '/\\' ) . '/'; }
function plugin_dir_url( string $file ): string { return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/'; }
function plugin_basename( string $file ): string { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function get_plugin_data( string $file, bool $markup = true, bool $translate = true ): array {
	return [ 'Name' => 'Hexa PR Wire Core', 'Version' => '2.0.3', 'Author' => 'Hexa PR Wire', 'PluginURI' => 'https://hexaprwire.com/', 'Description' => 'Test fixture' ];
}
function did_action( string $hook ): int { return 0; }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ?: '' ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_title( mixed $value ): string { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ) ?: '' ), '-' ); }
function is_admin(): bool { return false; }
function get_option( string $key, mixed $default = false ): mixed { return $default; }
function update_option( string $key, mixed $value, bool $autoload = true ): bool { return true; }

require_once dirname( __DIR__ ) . '/hexa-pr-wire-core.php';
\HexaPluginCorePackageRegistry::resolve();
\HexaPrWire\Core\Plugin::instance()->register();

$hooks = array_column( $GLOBALS['hprwc_test_hooks'], 1 );
foreach ( [ 'init', 'acf/init', 'user_has_cap', 'map_meta_cap', 'transition_post_status', 'rest_api_init', 'elementor/query/publication_links' ] as $required ) {
	if ( ! in_array( $required, $hooks, true ) ) {
		fwrite( STDERR, "FAIL: bootstrap did not register {$required}.\n" );
		exit( 1 );
	}
}
foreach ( [ 'display_standard_releases_table', 'display_featured_standard_releases_links', 'display_new_sources', 'publication_press_links' ] as $shortcode ) {
	if ( ! in_array( $shortcode, $GLOBALS['hprwc_test_shortcodes'], true ) ) {
		fwrite( STDERR, "FAIL: bootstrap did not register shortcode {$shortcode}.\n" );
		exit( 1 );
	}
}

foreach ( $GLOBALS['hprwc_test_hooks'] as $hook ) {
	$callback = $hook[4];
	if ( is_array( $callback ) && 2 === count( $callback ) ) {
		$reflection = new ReflectionMethod( $callback[0], (string) $callback[1] );
	} elseif ( $callback instanceof Closure ) {
		$reflection = new ReflectionFunction( $callback );
	} elseif ( is_string( $callback ) && function_exists( $callback ) ) {
		$reflection = new ReflectionFunction( $callback );
	} else {
		continue;
	}
	if ( $reflection->getNumberOfRequiredParameters() > (int) $hook[3] ) {
		fwrite( STDERR, 'FAIL: ' . $hook[1] . ' accepts ' . $hook[3] . ' hook arguments but its callback requires ' . $reflection->getNumberOfRequiredParameters() . ".\n" );
		exit( 1 );
	}
}

fwrite( STDOUT, 'Bootstrap load passed with ' . count( $GLOBALS['hprwc_test_hooks'] ) . " hooks.\n" );
