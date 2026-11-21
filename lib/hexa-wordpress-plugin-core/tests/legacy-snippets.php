<?php

declare( strict_types=1 );

namespace Hexa\PluginCore\LogFiles {
    function error_log( string $message ): void {
        $GLOBALS['messages'][] = $message;
    }
}

namespace {
    use Hexa\PluginCore\LogFiles\DebugLogger;
    use Hexa\PluginCore\SnippetRegistry\LegacySnippetActivator;
    use Hexa\PluginCore\WpAdminComponents\CoreUi;

    foreach ( [ 'SnippetRegistry/SnippetRegistry', 'SnippetRegistry/LegacySnippetActivator', 'WpAdminComponents/CoreUi', 'LogFiles/DebugLogger' ] as $class ) {
        require dirname( __DIR__ ) . '/src/' . $class . '.php';
    }

    define( 'WP_DEBUG', true );
    define( 'WP_DEBUG_LOG', true );

    function get_option( string $key, bool $default ): mixed {
        return $GLOBALS['options'][ $key ] ?? $default;
    }

    function esc_attr( string $input ): string {
        return htmlspecialchars( $input, ENT_QUOTES );
    }

    function esc_html( string $input ): string {
        return esc_attr( $input );
    }

    function checked( bool $checked, bool $wanted, bool $echo ): string {
        return $checked === $wanted ? ' checked="checked"' : '';
    }

    function same( mixed $expected, mixed $actual, string $why ): void {
        if ( $expected !== $actual ) {
            throw new \RuntimeException( $why . ': ' . var_export( $actual, true ) );
        }
    }

    $options = [ 'first' => '1', 'last' => 'false', 'missing' => 1, 'blocked' => 1 ];
    $snippets = [
        [ 'id' => 'first', 'function' => 'first' ],
        [ 'id' => 'absent', 'function' => 'last', 'default' => true ],
        [ 'id' => 'last', 'function' => 'last' ],
        [ 'id' => 'blocked', 'function' => 'blocked' ],
        [ 'id' => 'missing', 'function' => 'no_callback' ],
        [ 'id' => 'first', 'function' => 'first' ],
    ];

    $called = [];
    $log_messages = [];
    $log = static function ( string $value, bool $debug = false ) use ( &$log_messages ): void {
        if ( $debug ) {
            $log_messages[] = $value;
        }
    };
    LegacySnippetActivator::activate( $snippets, 'LegacySnippetFixture', $log, eligible: static fn( $item ) => 'blocked' !== $item['id'] );
    same( [ 'first', 'last', 'first' ], $called, 'Option truthiness, callback order and eligibility' );
    same( [ '🚫 Function \LegacySnippetFixture\no_callback does not exist for snippet missing.' ], $log_messages, 'Missing callback diagnostic' );

    $called = [];
    $resolver = static function ( array $item ): bool {
        $value = $GLOBALS['options'][ $item['id'] ] ?? $item['default'] ?? false;
        return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
    };
    LegacySnippetActivator::activate( $snippets, 'LegacySnippetFixture', $log, $resolver );
    same( [ 'first', 'last', 'blocked', 'first' ], $called, 'Alternate option defaults and string booleans' );

    $messages = [];
    DebugLogger::write( 'suppressed', false );
    DebugLogger::write( 'plain', true );
    DebugLogger::write( 'block', true, false, DebugLogger::SPACED_STACK );
    DebugLogger::write( 'ascii', true, false, DebugLogger::ASCII_STACK, '[Prefix] ' );
    same( [ 'plain', "block\n\n", '[Prefix] ascii' ], $messages, 'Debug gates and log formatting' );

    $slider = CoreUi::legacy_toggle( 'id-X', '<safe>', true, 'go("1")', 'extra', 'host' );
    same( '<label class="host-toggle-switch extra"><input type="checkbox" id="id-X" checked onclick="go(&quot;1&quot;)"><span class="host-toggle-slider"></span><span class="host-toggle-label">&lt;safe&gt;</span></label>', $slider, 'Slider CSS and click binding' );
    $checkbox = CoreUi::legacy_toggle( 'id-Y', '0', false, '0', '', 'host', false );
    same( '<label class="host-check "><input type="checkbox" id="id-Y" onclick="0"><span>0</span></label>', $checkbox, 'Native checkbox label and click binding' );

    echo "PASS: Legacy snippet activation, debug output and switch markup.\n";
}

namespace LegacySnippetFixture {
    function first(): void { $GLOBALS['called'][] = 'first'; }
    function last(): void { $GLOBALS['called'][] = 'last'; }
    function blocked(): void { $GLOBALS['called'][] = 'blocked'; }
}
