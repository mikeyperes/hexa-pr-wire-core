<?php

declare( strict_types=1 );

namespace Hexa\PluginCore\LogFiles;

/** Writes WordPress debug messages using the host's established log format. */
final class DebugLogger {
    public const COMPACT_STACK = 'compact_stack';
    public const SPACED_STACK = 'spaced_stack';
    public const ASCII_STACK = 'ascii_stack';
    public const CALLER = 'caller';

    /** Honor the WordPress debug switches and format diagnostics without capturing arguments. */
    public static function write(
        mixed $value,
        bool $full_debug,
        bool $display_stack = false,
        string $format = self::COMPACT_STACK,
        string $prefix = '',
        int $stack_start = 2
    ): void {
        if ( ! $full_debug || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
            return;
        }

        $message = is_scalar( $value ) || null === $value ? (string) $value : print_r( $value, true );
        if ( self::CALLER === $format ) {
            $frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 );
            $caller = $frames[2]['function'] ?? 'N/A';
            $file = $frames[1]['file'] ?? 'N/A';
            $line = $frames[1]['line'] ?? 'N/A';
            error_log( "{$message}\n\n[Called by: {$caller}]\n[In file: {$file} at line {$line}]\n\n---\n" );
            return;
        }

        $spaced = self::SPACED_STACK === $format;
        if ( $spaced ) {
            $message .= "\n\n";
        }

        if ( $display_stack ) {
            $arrow = self::ASCII_STACK === $format ? '->' : '→';
            foreach ( array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), $stack_start ) as $index => $frame ) {
                $stack = sprintf(
                    'Stack #%d %s %s%s() in %s on line %s',
                    $index + 1,
                    $arrow,
                    isset( $frame['class'] ) ? $frame['class'] . ( $frame['type'] ?? '' ) : '',
                    $frame['function'] ?? 'N/A',
                    $frame['file'] ?? 'N/A',
                    $frame['line'] ?? 'N/A'
                );
                $message .= $spaced ? $stack . "\n" : "\n" . $stack;
            }
            if ( $spaced ) {
                $message .= "\n---";
            }
        }

        error_log( $prefix . $message );
    }
}
