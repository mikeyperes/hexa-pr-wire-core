<?php

declare( strict_types=1 );

namespace Hexa\PluginCore\SnippetRegistry;

/** Runs saved legacy snippet callbacks in the order supplied by their host. */
final class LegacySnippetActivator {
    /** Resolve the host's option and eligibility rules before invoking each callback. */
    public static function activate(
        array $snippets,
        string $namespace,
        callable $log,
        ?callable $is_enabled = null,
        ?callable $eligible = null,
        bool $verbose = true
    ): void {
        foreach ( $snippets as $snippet ) {
            $id = (string) ( $snippet['id'] ?? '' );
            $function = (string) ( $snippet['function'] ?? '' );
            if ( ! $verbose && ( '' === $id || '' === $function ) ) {
                continue;
            }

            $enabled = $is_enabled ? (bool) $is_enabled( $snippet ) : SnippetRegistry::option_enabled( $id );
            if ( $verbose ) {
                $name = (string) ( $snippet['name'] ?? $id );
                $log( "Processing snippet: {$name} (ID: {$id})", false );
            }

            if ( ! $enabled ) {
                if ( $verbose ) {
                    $log( "🚫 Snippet {$id} is not enabled.", false );
                }
                continue;
            }

            if ( $eligible && ! $eligible( $snippet ) ) {
                continue;
            }

            if ( $verbose ) {
                $log( "Snippet {$id} is enabled. Preparing to activate." );
            }

            $callback = $verbose ? '\\' . $namespace . '\\' . $function : $namespace . '\\' . ltrim( $function, '\\' );
            if ( ! function_exists( $callback ) ) {
                $log(
                    $verbose ? "🚫 Function {$callback} does not exist for snippet {$id}." : "Enabled feature {$id} has no callable {$callback}.",
                    true
                );
                continue;
            }

            $callback();
            $log( $verbose ? "✅ Snippet {$id} activated by calling {$callback}." : "Activated feature {$id}.", false );
        }
    }
}
