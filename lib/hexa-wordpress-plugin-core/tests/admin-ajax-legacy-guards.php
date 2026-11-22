<?php
/** Compares complete AJAX guards with their original WordPress responses. */

declare( strict_types=1 );

use Hexa\PluginCore\WpAdminAjax\AjaxGuard;

require dirname( __DIR__ ) . '/src/WpAdminAjax/AjaxGuard.php';

final class GuardResult extends RuntimeException {
    public function __construct( public string $mode, public mixed $payload, public ?int $status ) {
        parent::__construct( $mode );
    }
}

function wp_send_json_error( mixed $payload = null, ?int $status = null ): void {
    throw new GuardResult( 'json', $payload, $status );
}

function check_ajax_referer( $action = -1, $field = false, $stop = true ) {
    $value = $field && isset( $_REQUEST[ $field ] )
        ? $_REQUEST[ $field ] : ( $_REQUEST['_ajax_nonce'] ?? $_REQUEST['_wpnonce'] ?? '' );
    $result = wp_verify_nonce( $value, $action );
    if ( $stop && ! $result ) {
        throw new GuardResult( 'wp_die', -1, 403 );
    }
    return $result;
}

function wp_verify_nonce( mixed $nonce, mixed $action ): int|false {
    $GLOBALS['trace'][] = [ 'nonce', $nonce, $action ];
    return match ( [ $nonce, $action ] ) {
        [ 'fresh', 'fixture_action' ] => 1,
        [ 'previous', 'fixture_action' ] => 2,
        default => false,
    };
}

function current_user_can( string $capability, mixed ...$objects ): bool {
    $GLOBALS['trace'][] = [ 'capability', $capability, $objects ];
    return $GLOBALS['editor'] && 'edit_post' === $capability && [ 7 ] === $objects;
}

function observe( callable $callback ): array {
    $GLOBALS['trace'] = [];
    try {
        $callback();
        return [ 'allowed', $GLOBALS['trace'] ];
    } catch ( GuardResult $failure ) {
        return [ $failure->mode, $failure->payload, $failure->status, $GLOBALS['trace'] ];
    }
}

$states = [ [ 'nonce' => 'fresh' ], [ '_ajax_nonce' => 'previous' ], [ '_wpnonce' => 'fresh' ], [ 'nonce' => [ 'fresh' ], '_ajax_nonce' => 'fresh' ], [ 'nonce' => 'invalid' ], [] ];
$checks = 0;
foreach ( $states as $_REQUEST ) {
    foreach ( [ true, false ] as $editor ) {
        foreach ( [ true, false ] as $nonce_first ) {
            $before = observe(
                static function () use ( $nonce_first ): void {
                    if ( $nonce_first ) {
                        check_ajax_referer( 'fixture_action', 'nonce' );
                    }
                    if ( ! current_user_can( 'edit_post', 7 ) ) {
                        wp_send_json_error( [ 'message' => 'Permission denied.' ] );
                    }
                    if ( ! $nonce_first ) {
                        check_ajax_referer( 'fixture_action', 'nonce' );
                    }
                }
            );
            $after = observe(
                static fn() => AjaxGuard::require_access( 'edit_post', 'fixture_action', 'nonce', [ [ 'message' => 'Permission denied.' ] ], objects: [ 7 ], nonce_first: $nonce_first )
            );
            if ( $before !== $after ) {
                throw new RuntimeException( 'Capability order, native nonce, or failure response changed' );
            }
            ++$checks;
        }

        $before = observe(
            static function (): void {
                if ( ! current_user_can( 'edit_post', 7 ) ) {
                    wp_send_json_error( 'Unauthorized', 403 );
                }
                if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'fixture_action' ) ) {
                    wp_send_json_error( 'Bad nonce', 400 );
                }
            }
        );
        $after = observe(
            static fn() => AjaxGuard::require_access( 'edit_post', 'fixture_action', capability_error: [ 'Unauthorized', 403 ], nonce_error: [ 'Bad nonce', 400 ], objects: [ 7 ], nonce_source: static fn() => $_REQUEST['nonce'] ?? null )
        );
        if ( $before !== $after ) {
            throw new RuntimeException( 'Explicit nonce or custom error response changed' );
        }
        ++$checks;
    }
}

echo "PASS: {$checks} complete AJAX guard parity checks.\n";
