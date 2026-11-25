<?php

namespace Hexa\PluginCore\Fields;

/** Formats stored Core field values for the WordPress REST API. */
final class RestFieldValue {
    private const REFERENCE_TYPES = [ 'post_object', 'relationship', 'image', 'gallery', 'file' ];
    private const NUMERIC_TYPES = [ 'number', 'range', 'image', 'file', 'gallery', 'post_object', 'relationship', 'page_link', 'user' ];

    /** Load a value in the requested REST format from the existing Core storage. */
    public static function read( array $context, array $field, string $format ): mixed {
        $value = Values::load( $context, $field, $field['name'] );
        $formatted = 'standard' === $format
            ? self::protect_references( $field, $value, Values::format( $value, $context, $field ) )
            : self::light( $field, $value );

        return Hooks::field_filter( 'rest/format_value', $formatted, $field, Storage::acf_id( $context ), $field, $value, $format );
    }

    /** Write the accepted REST value using Core's field and child keys. */
    public static function write( array $context, array $field, mixed $value ): void {
        if ( null === $value || ( 'group' === $field['type'] && [] === $value ) ) {
            Values::erase( $context, $field, $field['name'] );
            return;
        }
        Values::write( $context, $field, $field['name'], self::write_keys( $field, $value ), true );
    }

    /** Translate submitted child names without touching absent children. */
    private static function write_keys( array $field, mixed $value ): mixed {
        if ( ! is_array( $value ) ) {
            return $value;
        }
        if ( 'group' === $field['type'] ) {
            return self::write_row( (array) ( $field['sub_fields'] ?? [] ), $value );
        }
        if ( in_array( $field['type'], [ 'repeater', 'flexible_content' ], true ) ) {
            return array_map( static function ( array $row ) use ( $field ): array {
                if ( 'repeater' === $field['type'] ) {
                    return self::write_row( (array) ( $field['sub_fields'] ?? [] ), $row );
                }
                $layout = (string) ( $row['acf_fc_layout'] ?? '' );
                return [ 'acf_fc_layout' => $layout ] + self::write_row( Values::layout_fields( $field, $layout ), $row );
            }, $value );
        }
        return $value;
    }

    /** Map only supplied children, preferring field keys over display names. */
    private static function write_row( array $children, array $input ): array {
        $result = [];
        foreach ( $children as $field ) {
            $value = $input[ $field['key'] ] ?? $input[ $field['name'] ] ?? null;
            if ( null !== $value ) {
                $result[ $field['key'] ] = self::write_keys( $field, $value );
            }
        }
        return $result;
    }

    /** Apply the default REST format without expanding media or reference objects. */
    private static function light( array $field, mixed $value ): mixed {
        $type = $field['type'];
        if ( in_array( $type, self::NUMERIC_TYPES, true ) ) {
            return self::numeric( $value );
        }

        switch ( $type ) {
            case 'true_false':
                return (bool) $value;
            case 'date_picker':
                return $value ? (string) $value : null;
            case 'group':
                return self::map_row( (array) ( $field['sub_fields'] ?? [] ), (array) $value );
            case 'repeater':
            case 'flexible_content':
                if ( ! $value || ! is_array( $value ) ) {
                    return null;
                }
                return array_values( array_map( static function ( array $row ) use ( $field, $type ): array {
                    $layout = (string) ( $row['acf_fc_layout'] ?? '' );
                    $children = 'flexible_content' === $type ? Values::layout_fields( $field, $layout ) : (array) ( $field['sub_fields'] ?? [] );
                    $contents = self::map_row( $children, $row );
                    return 'flexible_content' === $type ? [ 'acf_fc_layout' => $layout ] + $contents : $contents;
                }, $value ) );
            case 'google_map':
                if ( is_array( $value ) ) {
                    foreach ( [ 'lat', 'lng' ] as $axis ) {
                        if ( array_key_exists( $axis, $value ) ) {
                            $value[ $axis ] = self::numeric( $value[ $axis ] );
                        }
                    }
                }
                return $value;
            default:
                return $value;
        }
    }

    /** Restore field names from Core's keyed group and repeater storage. */
    private static function map_row( array $fields, array $row ): array {
        $result = [];
        foreach ( $fields as $child ) {
            if ( '' !== $child['name'] ) {
                $result[ $child['name'] ] = self::light( $child, $row[ $child['key'] ] ?? null );
            }
        }
        return $result;
    }

    /** Keep non-numeric values and cast numeric content, including lists of IDs. */
    private static function numeric( mixed $value ): mixed {
        if ( is_array( $value ) ) {
            return array_map( [ self::class, 'numeric' ], $value );
        }
        return is_numeric( $value ) ? $value + 0 : $value;
    }

    /** Respect WordPress's visibility for expanded references in standard format. */
    private static function protect_references( array $field, mixed $raw, mixed $formatted ): mixed {
        $type = $field['type'];
        if ( 'user' === $type && ! current_user_can( 'list_users' ) ) {
            return self::light( $field, $raw );
        }
        if ( in_array( $type, self::REFERENCE_TYPES, true ) ) {
            // A linked object must pass its own REST controller's read check.
            foreach ( (array) $raw as $id ) {
                if ( $id && ! self::can_read_reference( (int) $id ) ) {
                    return self::light( $field, $raw );
                }
            }
            return $formatted;
        }
        if ( ! is_array( $raw ) || ! is_array( $formatted ) ) {
            return $formatted;
        }
        if ( 'group' === $type ) {
            return self::protect_row( (array) ( $field['sub_fields'] ?? [] ), $raw, $formatted );
        }
        if ( in_array( $type, [ 'repeater', 'flexible_content' ], true ) ) {
            foreach ( $formatted as $index => $row ) {
                $source = $raw[ $index ] ?? [];
                $children = 'flexible_content' === $type
                    ? Values::layout_fields( $field, (string) ( $source['acf_fc_layout'] ?? '' ) )
                    : (array) ( $field['sub_fields'] ?? [] );
                $formatted[ $index ] = self::protect_row( $children, (array) $source, (array) $row );
            }
        }
        return $formatted;
    }

    /** Apply the same visibility constraint to nested references. */
    private static function protect_row( array $fields, array $raw, array $formatted ): array {
        foreach ( $fields as $field ) {
            $name = $field['name'];
            if ( array_key_exists( $name, $formatted ) ) {
                $formatted[ $name ] = self::protect_references( $field, $raw[ $field['key'] ] ?? null, $formatted[ $name ] );
            }
        }
        return $formatted;
    }

    /** Check whether WordPress would expose the referenced post or attachment. */
    private static function can_read_reference( int $id ): bool {
        $post = get_post( $id );
        if ( ! $post instanceof \WP_Post ) {
            return false;
        }
        $type = get_post_type_object( $post->post_type );
        $controller = $type && $type->show_in_rest ? $type->get_rest_controller() : null;
        return is_callable( [ $controller, 'check_read_permission' ] )
            && $controller->check_read_permission( $post )
            && ( ! post_password_required( $post ) || current_user_can( 'edit_post', $id ) );
    }
}
