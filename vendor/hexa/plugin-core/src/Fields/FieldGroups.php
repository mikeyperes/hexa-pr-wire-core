<?php

namespace Hexa\PluginCore\Fields;

/**
 * Single registry for ACF-format field groups.
 *
 * Hosts pass the same arrays they would give `acf_add_local_field_group()`.
 * With ACF active the group is forwarded to ACF unchanged; without ACF it is
 * kept here and rendered, stored and formatted by the native engine.
 */
final class FieldGroups {
    /** @var array<string,array<string,mixed>> */
    private static array $groups = [];
    /** @var array<int,array<string,mixed>> */
    private static array $pending = [];
    /** @var array<int,array<string,mixed>> */
    private static array $acf_queue = [];
    private static bool $pending_hooked = false;
    private static bool $acf_hooked = false;

    /** @param array<string,mixed> $group */
    public static function add( array $group ): void {
        if ( ! did_action( 'init' ) && ! doing_action( 'init' ) ) {
            // ACF may not be loaded yet; decide once all plugins are loaded.
            self::$pending[] = $group;
            if ( ! self::$pending_hooked ) {
                self::$pending_hooked = true;
                add_action( 'init', [ self::class, 'flush_pending' ], 0 );
            }
            return;
        }

        $group = self::normalize( $group );
        if ( '' === $group['key'] ) {
            return;
        }
        self::$groups[ $group['key'] ] = $group;

        if ( ! Acf::active() ) {
            AdminScreens::boot();
            return;
        }
        if ( did_action( 'acf/init' ) ) {
            acf_add_local_field_group( $group );
            return;
        }
        self::$acf_queue[] = $group;
        if ( ! self::$acf_hooked ) {
            self::$acf_hooked = true;
            add_action( 'acf/init', [ self::class, 'flush_acf' ], 1 );
        }
    }

    public static function remove( string $key ): bool {
        if ( Acf::active() && function_exists( 'acf_remove_local_field_group' ) ) {
            acf_remove_local_field_group( $key );
        }
        $existed = isset( self::$groups[ $key ] );
        unset( self::$groups[ $key ] );
        return $existed;
    }

    /**
     * Runs a registration callback when field groups can be registered: on
     * `acf/init` with ACF, otherwise during `init`.
     */
    public static function ready( callable $callback, int $priority = 10 ): void {
        $run = static function () use ( $callback, $priority ): void {
            if ( Acf::active() && ! did_action( 'acf/init' ) ) {
                add_action( 'acf/init', $callback, $priority );
                return;
            }
            $callback();
        };
        if ( did_action( 'init' ) || doing_action( 'init' ) ) {
            $run();
            return;
        }
        add_action( 'init', $run, 0 );
    }

    public static function flush_pending(): void {
        $pending = self::$pending;
        self::$pending = [];
        foreach ( $pending as $group ) {
            self::add( $group );
        }
    }

    public static function flush_acf(): void {
        $queue = self::$acf_queue;
        self::$acf_queue = [];
        foreach ( $queue as $group ) {
            acf_add_local_field_group( $group );
        }
    }

    /** @return array<string,mixed>|null */
    public static function get_group( string $key ): ?array {
        if ( '' === $key ) {
            return null;
        }
        if ( Acf::active() && function_exists( 'acf_get_field_group' ) ) {
            $group = acf_get_field_group( $key );
            if ( is_array( $group ) ) {
                return $group;
            }
        }
        return self::$groups[ $key ] ?? null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array {
        if ( Acf::active() && function_exists( 'acf_get_field_groups' ) ) {
            return (array) acf_get_field_groups();
        }
        return array_values( self::$groups );
    }

    /** @return array<int,array<string,mixed>> Native groups only. */
    public static function native(): array {
        return array_values( self::$groups );
    }

    /**
     * Fields of one group, or the group containing a field key.
     *
     * @param array<string,mixed>|string $group
     * @return array<int,array<string,mixed>>
     */
    public static function fields( array|string $group ): array {
        if ( Acf::active() && function_exists( 'acf_get_fields' ) ) {
            return (array) acf_get_fields( $group );
        }
        $group = is_array( $group ) ? $group : ( self::$groups[ $group ] ?? null );
        return is_array( $group ) ? (array) $group['fields'] : [];
    }

    /**
     * One field definition by key or name, preferring groups that apply to
     * the given context.
     *
     * @return array<string,mixed>|null
     */
    public static function get_field( string $selector, mixed $context = false ): ?array {
        if ( '' === $selector ) {
            return null;
        }
        if ( Acf::active() && function_exists( 'acf_get_field' ) ) {
            $field = acf_get_field( $selector );
            return is_array( $field ) ? $field : null;
        }

        $is_key = str_starts_with( $selector, 'field_' );
        $screen = false === $context ? null : Storage::screen( Storage::context( $context ) );
        $fallback = null;
        foreach ( self::$groups as $group ) {
            $applies = null === $screen || self::matches( $group, $screen );
            $found = $is_key ? self::find_key( (array) $group['fields'], $selector, (string) $group['key'] ) : self::find_name( (array) $group['fields'], $selector, (string) $group['key'] );
            if ( null === $found ) {
                continue;
            }
            if ( $applies ) {
                return $found;
            }
            $fallback ??= $found;
        }
        return $fallback;
    }

    /**
     * Native groups whose location rules match one admin screen.
     *
     * @param array<string,mixed> $screen
     * @return array<int,array<string,mixed>>
     */
    public static function for_screen( array $screen ): array {
        $groups = array_filter( self::$groups, static fn( array $group ): bool => ! empty( $group['active'] ) && self::matches( $group, $screen ) );
        uasort( $groups, static fn( array $a, array $b ): int => (int) $a['menu_order'] <=> (int) $b['menu_order'] );
        return array_values( $groups );
    }

    /**
     * ACF location rules: OR of AND rule sets.
     *
     * @param array<string,mixed> $group
     * @param array<string,mixed> $screen
     */
    public static function matches( array $group, array $screen ): bool {
        foreach ( (array) ( $group['location'] ?? [] ) as $rules ) {
            if ( ! is_array( $rules ) || [] === $rules ) {
                continue;
            }
            $all = true;
            foreach ( $rules as $rule ) {
                if ( ! is_array( $rule ) || ! self::rule( $rule, $screen, $group ) ) {
                    $all = false;
                    break;
                }
            }
            if ( $all ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $screen
     * @param array<string,mixed> $group
     */
    private static function rule( array $rule, array $screen, array $group = [] ): bool {
        $param = (string) ( $rule['param'] ?? '' );
        $value = (string) ( $rule['value'] ?? '' );
        $equal = '!=' !== (string) ( $rule['operator'] ?? '==' );

        switch ( $param ) {
            case 'post_type':
                $actual = $screen['post_type'] ?? null;
                break;
            case 'post':
            case 'page':
                $actual = isset( $screen['post_id'] ) ? (string) $screen['post_id'] : null;
                break;
            case 'page_template':
            case 'post_template':
                $actual = isset( $screen['post_type'] ) ? (string) ( $screen['template'] ?? 'default' ) : null;
                break;
            case 'post_status':
                $actual = isset( $screen['post_id'] ) ? (string) get_post_status( (int) $screen['post_id'] ) : null;
                break;
            case 'post_taxonomy':
            case 'post_category':
                if ( ! isset( $screen['post_id'] ) ) {
                    return false;
                }
                [ $taxonomy, $term ] = array_pad( explode( ':', 'post_category' === $param ? 'category:' . $value : $value, 2 ), 2, '' );
                $hit = '' !== $term && has_term( $term, $taxonomy, (int) $screen['post_id'] );
                return $equal ? $hit : ! $hit;
            case 'user_form':
                if ( ! isset( $screen['user_form'] ) ) {
                    return false;
                }
                $hit = 'all' === $value || $value === $screen['user_form'];
                return $equal ? $hit : ! $hit;
            case 'user_role':
                if ( ! isset( $screen['user_roles'] ) ) {
                    return false;
                }
                $hit = 'all' === $value || in_array( $value, (array) $screen['user_roles'], true );
                return $equal ? $hit : ! $hit;
            case 'current_user_role':
                $user = wp_get_current_user();
                $hit = 'super_admin' === $value ? is_super_admin() : in_array( $value, (array) $user->roles, true );
                return $equal ? $hit : ! $hit;
            case 'current_user':
                $hit = 'logged_in' === $value ? is_user_logged_in() : ( 'viewing_back' === $value ? is_admin() : ( 'viewing_front' === $value ? ! is_admin() : false ) );
                return $equal ? $hit : ! $hit;
            case 'taxonomy':
                if ( ! isset( $screen['taxonomy'] ) ) {
                    return false;
                }
                $hit = 'all' === $value || $value === $screen['taxonomy'];
                return $equal ? $hit : ! $hit;
            case 'options_page':
                $actual = $screen['options_page'] ?? null;
                if ( '*' === $actual ) {
                    return $equal;
                }
                break;
            default:
                // Host-defined rules, registered with Hooks::on( 'location/rule_match/<param>', ... ).
                return (bool) apply_filters( 'hexa_fields/location/rule_match/' . $param, false, $rule, $screen, $group );
        }
        if ( null === $actual ) {
            return false;
        }
        return $equal ? (string) $actual === $value : (string) $actual !== $value;
    }

    /**
     * @param array<string,mixed> $group
     * @return array<string,mixed>
     */
    private static function normalize( array $group ): array {
        $group = array_merge(
            [ 'key' => '', 'title' => '', 'fields' => [], 'location' => [], 'menu_order' => 0, 'position' => 'normal', 'active' => true, 'description' => '' ],
            $group
        );
        $group['key'] = (string) $group['key'];
        $group['fields'] = self::normalize_fields( (array) $group['fields'], $group['key'] );
        $group['active'] = ! isset( $group['active'] ) || (bool) $group['active'];
        return $group;
    }

    /**
     * @param array<int,mixed> $fields
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_fields( array $fields, string $parent ): array {
        $normalized = [];
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $field['name'] = (string) ( $field['name'] ?? '' );
            $field['type'] = (string) ( $field['type'] ?? 'text' );
            $field['key'] = (string) ( $field['key'] ?? '' );
            if ( '' === $field['key'] ) {
                $field['key'] = 'field_' . substr( md5( $parent . '|' . $field['name'] . '|' . $field['type'] ), 0, 13 );
            }
            $field['label'] = (string) ( $field['label'] ?? $field['name'] );
            $field['parent'] = $parent;
            if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
                $field['sub_fields'] = self::normalize_fields( $field['sub_fields'], $field['key'] );
            }
            $normalized[] = $field;
        }
        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return array<string,mixed>|null
     */
    private static function find_key( array $fields, string $key, string $group ): ?array {
        foreach ( $fields as $field ) {
            if ( $key === $field['key'] ) {
                return $field;
            }
            if ( ! empty( $field['sub_fields'] ) ) {
                $found = self::find_key( (array) $field['sub_fields'], $key, $group );
                if ( null !== $found ) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return array<string,mixed>|null
     */
    private static function find_name( array $fields, string $name, string $group ): ?array {
        foreach ( $fields as $field ) {
            if ( $name === $field['name'] ) {
                return $field;
            }
        }
        return null;
    }
}
