<?php

declare( strict_types=1 );

namespace Hexa\PluginCore\PluginUpdates;

/** Moves a legacy active plugin reference to its canonical basename. */
final class LegacyPluginBasenameMigration {
    /**
     * Replace an active basename while preserving the host's existing duplicate and timestamp policy.
     */
    public static function migrate(
        string $legacy,
        string $canonical,
        bool $deduplicate_all = false,
        bool $prefer_legacy_network_timestamp = false,
        ?bool $autoload = null
    ): void {
        if ( $legacy === $canonical ) {
            return;
        }

        $active = (array) get_option( 'active_plugins', [] );
        if ( ! $deduplicate_all || in_array( $legacy, $active, true ) ) {
            $updated = [];
            foreach ( $active as $basename ) {
                $basename = $legacy === $basename ? $canonical : $basename;
                if ( ! $deduplicate_all && $canonical === $basename && in_array( $canonical, $updated, true ) ) {
                    continue;
                }
                $updated[] = $basename;
            }

            if ( $deduplicate_all ) {
                $updated = array_values( array_unique( $updated ) );
            }

            if ( $updated !== array_values( $active ) ) {
                update_option( 'active_plugins', $updated, $autoload );
            }
        }

        if ( ! is_multisite() ) {
            return;
        }

        $network = (array) get_site_option( 'active_sitewide_plugins', [] );
        if ( isset( $network[ $legacy ] ) ) {
            $network[ $canonical ] = $prefer_legacy_network_timestamp
                ? $network[ $legacy ]
                : ( $network[ $canonical ] ?? $network[ $legacy ] );
            unset( $network[ $legacy ] );
            update_site_option( 'active_sitewide_plugins', $network );
        }
    }
}
