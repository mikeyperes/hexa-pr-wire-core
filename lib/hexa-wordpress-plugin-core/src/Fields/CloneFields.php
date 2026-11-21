<?php

namespace Hexa\PluginCore\Fields;

/** Resolves clone editors and their existing WordPress metadata names. */
final class CloneFields {
    /** Resolves field and group references while preventing recursive clones. */
    public static function children( array $field ): array {
        $key = (string) $field['key'];
        $ancestors = (array) ( $field['_hexa_clone_ancestors'] ?? [] );
        if ( in_array( $key, $ancestors, true ) ) {
            return [];
        }
        $ancestors[] = $key;

        $children = [];
        foreach ( (array) ( $field['clone'] ?? [] ) as $selector ) {
            if ( ! is_string( $selector ) ) {
                continue;
            }
            if ( str_starts_with( $selector, 'group_' ) ) {
                foreach ( FieldGroups::fields( $selector ) as $child ) {
                    $children[ $child['key'] ] = $child;
                }
            } elseif ( str_starts_with( $selector, 'field_' ) ) {
                $child = FieldGroups::get_field( $selector );
                if ( null !== $child ) {
                    $children[ $child['key'] ] = $child;
                }
            }
        }

        foreach ( $children as &$child ) {
            if ( self::seamless( $field ) && ! empty( $field['prefix_label'] ) ) {
                $child['label'] = trim( $field['label'] . ' ' . $child['label'] );
            }
            if ( ! empty( $field['required'] ) ) {
                $child['required'] = 1;
            }
            $child = self::with_ancestors( $child, $ancestors );
        }
        return array_values( $children );
    }

    /** Whether the clone is displayed without its own field group. */
    public static function seamless( array $field ): bool {
        return 'seamless' === ( $field['display'] ?? 'seamless' );
    }

    /** Leaves the original child name intact for filters and metadata references. */
    public static function stored_name( array $field, string $name, array $child ): string {
        $local_name = (string) $field['name'];
        $parent = '' === $local_name ? $name : substr( $name, 0, -strlen( $local_name ) );
        return ( ! empty( $field['prefix_name'] ) ? $name . '_' : $parent ) . $child['name'];
    }

    /** Carries the reference chain through containers inside a cloned group. */
    private static function with_ancestors( array $field, array $ancestors ): array {
        $field['_hexa_clone_ancestors'] = $ancestors;
        foreach ( (array) ( $field['sub_fields'] ?? [] ) as $i => $child ) {
            $field['sub_fields'][ $i ] = self::with_ancestors( $child, $ancestors );
        }
        foreach ( (array) ( $field['layouts'] ?? [] ) as $i => $layout ) {
            foreach ( (array) ( $layout['sub_fields'] ?? [] ) as $j => $child ) {
                $field['layouts'][ $i ]['sub_fields'][ $j ] = self::with_ancestors( $child, $ancestors );
            }
        }
        return $field;
    }
}
