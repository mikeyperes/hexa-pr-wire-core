<?php
/** Compare legacy WordPress AJAX checks with the shared forwarding methods. */

declare( strict_types=1 );

use Hexa\PluginCore\WpAdminAjax\AjaxGuard;

require dirname( __DIR__ ) . '/src/WpAdminAjax/AjaxGuard.php';

function check_ajax_referer( $action = -1, $field = false, $stop = true ) {
    $nonce = ( $field && isset( $_REQUEST[ $field ] ) ) ? $_REQUEST[ $field ] : ( $_REQUEST['_ajax_nonce'] ?? $_REQUEST['_wpnonce'] ?? '' );
    $result = wp_verify_nonce( $nonce, $action );
    $GLOBALS['trace'][] = [ $action, $field, $stop, $result ];
    if ( $stop && false === $result ) {
        throw new RuntimeException( '-1/403' );
    }
    return $result;
}

function wp_verify_nonce( $nonce, $action = -1 ) {
    return match ( [ $nonce, $action ] ) {
        [ 'fresh', 'fixture_action' ] => 1,
        [ 'previous', 'fixture_action' ] => 2,
        default => false,
    };
}

function current_user_can( $capability, ...$objects ): bool {
    $GLOBALS['trace'][] = [ $capability, $objects ];
    return 'edit_post' === $capability && [ 7 ] === $objects;
}

function outcome( callable $callable ): array {
    $GLOBALS['trace'] = [];
    try {
        $value = [ 'return', $callable() ];
    } catch ( RuntimeException $exception ) {
        $value = [ 'error', $exception->getMessage() ];
    }
    return [ $value, $GLOBALS['trace'] ];
}

$cases = [
    'custom field' => [ 'nonce' => 'fresh' ],
    'alternate field' => [ '_ajax_nonce' => 'previous' ],
    'WordPress field' => [ '_wpnonce' => 'fresh' ],
    'preferred malformed field' => [ 'nonce' => [ 'fresh' ], '_ajax_nonce' => 'fresh' ],
    'invalid' => [ 'nonce' => 'bad' ],
    'missing' => [],
];
foreach ( $cases as $case => $_REQUEST ) {
    foreach ( [ true, false ] as $stop ) {
        $before = outcome( static fn() => check_ajax_referer( 'fixture_action', 'nonce', $stop ) );
        $after = outcome( static fn() => AjaxGuard::check_request_nonce( 'fixture_action', 'nonce', $stop ) );
        if ( $before !== $after ) {
            throw new RuntimeException( 'Request nonce contract changed for ' . $case );
        }
    }
    $explicit = $_REQUEST['nonce'] ?? null;
    if ( wp_verify_nonce( $explicit, 'fixture_action' ) !== AjaxGuard::verify_explicit_nonce( $explicit, 'fixture_action' ) ) {
        throw new RuntimeException( 'Explicit nonce contract changed for ' . $case );
    }
}

foreach ( [ [ 'edit_post', 7 ], [ 'edit_post', 8 ], [ 'manage_options' ], [ 'edit_user', 7 ] ] as $args ) {
    if ( outcome( static fn() => current_user_can( ...$args ) ) !== outcome( static fn() => AjaxGuard::allows( ...$args ) ) ) {
        throw new RuntimeException( 'Capability or object ID contract changed' );
    }
}

echo "PASS: 22 legacy AJAX guard parity checks.\n";
