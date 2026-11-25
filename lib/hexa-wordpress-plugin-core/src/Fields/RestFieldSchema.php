<?php

namespace Hexa\PluginCore\Fields;

/** Builds the WordPress REST schema for Core fields. */
final class RestFieldSchema {
    private const STRING_FIELDS = [
        'text', 'textarea', 'wysiwyg', 'email', 'url', 'password', 'color_picker',
        'date_picker', 'date_time_picker', 'time_picker', 'oembed', 'radio', 'button_group',
    ];

    /** Select fields represented by the REST contract, excluding layout controls. */
    public static function properties( array $fields ): array {
        $properties = [];
        foreach ( $fields as $field ) {
            $schema = self::for_field( $field );
            if ( [] !== $schema && '' !== $field['name'] ) {
                $properties[ $field['name'] ] = $schema;
            }
        }
        return $properties;
    }

    /** Compute the JSON schema from the existing field definition. */
    public static function for_field( array $field ): array {
        $type = $field['type'];
        $schema = [ 'type' => [ 'string', 'null' ], 'required' => ! empty( $field['required'] ) ];

        if ( in_array( $type, self::STRING_FIELDS, true ) ) {
            switch ( $type ) {
                case 'text':
                case 'textarea':
                    if ( ! empty( $field['maxlength'] ) ) {
                        $schema['maxLength'] = (int) $field['maxlength'];
                    }
                    break;
                case 'email':
                case 'url':
                    $schema['format'] = 'email' === $type ? 'email' : 'uri';
                    break;
                case 'date_picker':
                    $schema['description'] = 'A `Ymd` formatted date string.';
                    break;
                case 'radio':
                case 'button_group':
                    if ( empty( $field['other_choice'] ) ) {
                        $schema['enum'] = self::choices( $field );
                        if ( ! empty( $field['allow_null'] ) ) {
                            $schema['enum'][] = null;
                        }
                    }
                    break;
            }
            return self::with_default( $schema, $field );
        }

        switch ( $type ) {
            case 'number':
            case 'range':
                $schema['type'] = [ 'number', 'null' ];
                self::apply_bounds( $schema, $field, 'minimum', 'maximum', 'floatval' );
                if ( is_numeric( $field['default_value'] ?? null ) ) {
                    $schema['default'] = (float) $field['default_value'];
                }
                return $schema;
            case 'true_false':
                $schema['type'] = [ 'boolean', 'null' ];
                if ( isset( $field['default_value'] ) && '' !== $field['default_value'] ) {
                    $schema['default'] = (bool) $field['default_value'];
                }
                return $schema;
            case 'image':
            case 'file':
                $schema['type'] = [ 'integer', 'null' ];
                foreach ( [ 'min_width'=>'minWidth', 'min_height'=>'minHeight', 'max_width'=>'maxWidth', 'max_height'=>'maxHeight' ] as $setting => $constraint ) {
                    if ( ! empty( $field[ $setting ] ) ) {
                        $schema[ $constraint ] = (int) $field[ $setting ];
                    }
                }
                foreach ( [ 'min_size'=>'minSize', 'max_size'=>'maxSize', 'mime_types'=>'mimeTypes' ] as $setting => $constraint ) {
                    if ( ! empty( $field[ $setting ] ) ) {
                        $schema[ $constraint ] = $field[ $setting ];
                    }
                }
                return $schema;
            case 'gallery':
            case 'repeater':
            case 'flexible_content':
                $schema['type'] = [ 'array', 'null' ];
                $schema['items'] = match ( $type ) {
                    'gallery' => [ 'type' => 'number' ],
                    'repeater' => [ 'type' => 'object', 'properties' => self::properties( (array) ( $field['sub_fields'] ?? [] ) ) ],
                    default => self::layout_schema( (array) ( $field['layouts'] ?? [] ) ),
                };
                self::apply_bounds( $schema, $field, 'minItems', 'maxItems', 'intval' );
                return $schema;
            case 'group':
                return [
                    'type' => [ 'object', 'null' ],
                    'properties' => self::properties( (array) ( $field['sub_fields'] ?? [] ) ),
                    'required' => $schema['required'],
                ];
            case 'google_map':
                $schema['type'] = [ 'object', 'null' ];
                $schema['properties'] = [
                    'address' => [ 'type' => 'string' ],
                    'lat' => [ 'type' => 'number', 'minimum' => -90, 'maximum' => 90 ],
                    'lng' => [ 'type' => 'number', 'minimum' => -180, 'maximum' => 180 ],
                ];
                return $schema;
            case 'link':
                $schema['type'] = [ 'object', 'null' ];
                $schema['properties'] = [
                    'url' => [ 'type' => 'string', 'format' => 'uri' ],
                    'title' => [ 'type' => 'string' ],
                    'target' => [ 'type' => 'string' ],
                ];
                return $schema;
            case 'select':
            case 'checkbox':
                $schema['type'] = 'select' === $type ? [ 'string', 'array', 'int', 'null' ] : [ 'integer', 'string', 'array', 'null' ];
                $schema['items'] = [ 'type' => [ 'string', 'select' === $type ? 'int' : 'integer' ] ];
                if ( 'select' === $type || empty( $field['allow_custom'] ) ) {
                    $schema['items']['enum'] = self::choices( $field );
                }
                if ( 'select' === $type ) {
                    self::apply_cardinality( $schema, $field );
                }
                return self::with_default( $schema, $field );
            case 'relationship':
            case 'post_object':
            case 'user':
            case 'taxonomy':
            case 'page_link':
                $schema['type'] = [ 'integer', 'array', 'null' ];
                $schema['items'] = [ 'type' => 'integer' ];
                if ( 'page_link' === $type ) {
                    $schema['type'][] = 'string';
                    $schema['items']['type'] = [ 'integer', 'string' ];
                }
                self::apply_cardinality( $schema, $field, 'relationship' === $type );
                return $schema;
            default:
                return [];
        }
    }

    /** Validate a submitted field against its REST shape and referenced resources. */
    public static function validate( array $field, mixed $value, string $parameter ): bool|\WP_Error {
        $valid = rest_validate_value_from_schema( $value, self::for_field( $field ), $parameter );
        if ( true !== $valid ) {
            return $valid;
        }
        if ( null === $value && ( ! in_array( $field['type'], [ 'image', 'file' ], true ) || empty( $field['required'] ) ) ) {
            return true;
        }

        $valid = match ( $field['type'] ) {
            'select' => is_array( $value ) || self::valid_choice( $field, $value ),
            'user' => self::valid_users( $field, (array) $value ),
            'post_object', 'relationship' => self::valid_posts( $field, (array) $value ),
            'image', 'file' => self::valid_attachment( $field, (int) $value ),
            default => true,
        };
        if ( ! $valid ) {
            return new \WP_Error(
                'rest_invalid_param',
                sprintf( __( '%s has an invalid value.', 'hexa-plugin-core' ), $parameter ),
                [ 'param' => $parameter, 'value' => $value ]
            );
        }
        return true;
    }

    /** Validate both keyed choices and lists that store their labels. */
    private static function valid_choice( array $field, mixed $value ): bool {
        $choices = (array) ( $field['choices'] ?? [] );
        $keys = array_diff( array_keys( $choices ), array_values( $choices ) );
        return in_array( $value, $keys ?: $choices );
    }

    /** Require an existing user and the field's configured role when present. */
    private static function valid_users( array $field, array $ids ): bool {
        foreach ( $ids as $id ) {
            $user = get_userdata( (int) $id );
            if ( ! $user || ( ! empty( $field['role'] ) && [] === array_intersect( (array) $field['role'], $user->roles ) ) ) {
                return false;
            }
        }
        return true;
    }

    /** Check the selected posts against the field's type and taxonomy filters. */
    private static function valid_posts( array $field, array $ids ): bool {
        $types = array_filter( (array) ( $field['post_type'] ?? [] ) );
        $terms = array_filter( (array) ( $field['taxonomy'] ?? [] ) );
        foreach ( $ids as $id ) {
            if ( is_string( $id ) ) {
                continue;
            }
            $type = get_post_type( (int) $id );
            if ( ! $type || ( [] !== $types && ! in_array( $type, $types, true ) ) ) {
                return false;
            }
            if ( [] !== $terms && ! self::has_selected_term( (int) $id, $terms ) ) {
                return false;
            }
        }
        return true;
    }

    /** Match the configured taxonomy and slug pair on any permitted term. */
    private static function has_selected_term( int $id, array $terms ): bool {
        foreach ( $terms as $term ) {
            if ( ! str_contains( $term, ':' ) ) {
                continue;
            }
            [ $taxonomy, $slug ] = explode( ':', $term, 2 );
            if ( has_term( $slug, $taxonomy, $id ) ) {
                return true;
            }
        }
        return false;
    }

    /** Apply the field's existing media constraints to WordPress attachment data. */
    private static function valid_attachment( array $field, int $id ): bool {
        $attachment = wp_prepare_attachment_for_js( $id );
        if ( ! $attachment ) {
            return false;
        }
        foreach ( [ 'width', 'height', 'size' ] as $dimension ) {
            $value = 'size' === $dimension ? ( $attachment['filesizeInBytes'] ?? 0 ) / MB_IN_BYTES : (int) ( $attachment[ $dimension ] ?? 0 );
            if ( ( ! empty( $field[ 'min_' . $dimension ] ) && $value < (float) $field[ 'min_' . $dimension ] )
                || ( ! empty( $field[ 'max_' . $dimension ] ) && $value > (float) $field[ 'max_' . $dimension ] ) ) {
                return false;
            }
        }
        $extensions = array_filter( array_map( 'trim', explode( ',', strtolower( (string) ( $field['mime_types'] ?? '' ) ) ) ) );
        return [] === $extensions || in_array( strtolower( pathinfo( $attachment['filename'] ?? '', PATHINFO_EXTENSION ) ), $extensions, true );
    }

    /** Add a default only when it is explicitly present on the field. */
    private static function with_default( array $schema, array $field ): array {
        if ( isset( $field['default_value'] ) && '' !== $field['default_value'] ) {
            $schema['default'] = $field['default_value'];
        }
        return $schema;
    }

    /** List the accepted stored choice keys, retaining numeric types. */
    private static function choices( array $field ): array {
        return array_keys( (array) ( $field['choices'] ?? [] ) );
    }

    /** Apply the field's declared lower and upper limits. */
    private static function apply_bounds( array &$schema, array $field, string $minimum, string $maximum, callable $cast ): void {
        if ( ! empty( $field['min'] ) ) {
            $schema[ $minimum ] = $cast( $field['min'] );
        }
        if ( ! empty( $field['max'] ) ) {
            $schema[ $maximum ] = $cast( $field['max'] );
        }
    }

    /** Apply the stored field's selection count constraints. */
    private static function apply_cardinality( array &$schema, array $field, bool $unbounded = false ): void {
        if ( empty( $field['allow_null'] ) ) {
            $schema['minItems'] = 1;
        }
        if ( $unbounded ) {
            self::apply_bounds( $schema, $field, 'minItems', 'maxItems', 'intval' );
        } elseif ( empty( $field['multiple'] ) ) {
            $schema['maxItems'] = 1;
        }
    }

    /** Give each flexible layout its own set of legal properties. */
    private static function layout_schema( array $layouts ): array {
        $variants = [];
        foreach ( $layouts as $layout ) {
            $variants[] = [
                'type' => 'object',
                'properties' => [ 'acf_fc_layout' => [ 'type' => 'string', 'enum' => [ $layout['name'] ], 'required' => true ] ]
                    + self::properties( (array) ( $layout['sub_fields'] ?? [] ) ),
            ];
        }
        return [ 'type' => 'object', 'oneOf' => $variants ];
    }
}
