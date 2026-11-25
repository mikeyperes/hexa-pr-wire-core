<?php

namespace Hexa\PluginCore\Fields;

/** Exposes Core-managed fields through the existing WordPress REST resources. */
final class RestFields {
    private static bool $registered = false;
    private static array $resources = [];
    private static array $bindings = [];

    /** Bind REST registration once, even when several managed plugins boot. */
    public static function register(): void {
        if ( self::$registered ) {
            return;
        }
        self::$registered = true;
        add_action( 'rest_api_init', [ self::class, 'register_fields' ], 11 );
    }

    /** Register the legacy field property on resource types with Core REST groups. */
    public static function register_fields(): void {
        $native = array_column( FieldGroups::native(), null, 'key' );
        $groups = array_filter( array_column( FieldGroups::all(), null, 'key' ), static function ( array $group ) use ( $native ): bool {
            return isset( $native[ $group['key'] ] ) && ! empty( $group['active'] ) && ! empty( $group['show_in_rest'] )
                && ! Audience::hidden( $group );
        } );
        if ( [] === $groups ) {
            return;
        }
        foreach ( get_post_types( [ 'show_in_rest' => true ], 'objects' ) as $type ) {
            self::add_resource( $type->name, 'post', $groups );
            if ( isset( self::$resources[ $type->name ] ) && post_type_supports( $type->name, 'revisions' ) ) {
                self::$resources[ $type->name . '-revision' ] = self::$resources[ $type->name ];
            }
        }
        foreach ( get_taxonomies( [ 'show_in_rest' => true ], 'objects' ) as $taxonomy ) {
            self::add_resource( $taxonomy->name, 'term', $groups );
        }
        self::add_resource( 'user', 'user', $groups );
        self::add_resource( 'comment', 'comment', $groups );

        foreach ( array_keys( self::$resources ) as $type ) {
            self::bind( $type );
        }
        add_filter( 'rest_pre_dispatch', [ self::class, 'refresh_fields' ], 9 );
    }

    /** Keep existing REST registrations when another plugin updates a resource by request. */
    public static function refresh_fields( mixed $response ): mixed {
        foreach ( array_keys( self::$resources ) as $type ) {
            self::bind( $type );
        }
        return $response;
    }

    /** Find the groups capable of matching the resource before an object ID is known. */
    private static function add_resource( string $type, string $kind, array $groups ): void {
        $possible = array_filter( $groups, static fn( array $group ): bool => self::supports_resource( $group, $kind, $type ) );
        if ( [] !== $possible ) {
            self::$resources[ $type ] = [ 'kind' => $kind, 'groups' => $possible ];
        }
    }

    /** Check the resource portion of the existing field group location. */
    private static function supports_resource( array $group, string $kind, string $type ): bool {
        $rules = match ( $kind ) {
            'post' => [ 'post_type', 'post', 'page', 'post_template', 'page_template', 'post_category', 'post_taxonomy', 'post_status', 'page_type', 'page_parent', 'post_format' ],
            'term' => [ 'taxonomy' ],
            'user' => [ 'user_form', 'user_role' ],
            'comment' => [ 'comment' ],
        };
        foreach ( (array) $group['location'] as $clause ) {
            $recognized = false;
            foreach ( $clause as $rule ) {
                $parameter = $rule['param'];
                if ( ! in_array( $parameter, $rules, true ) ) {
                    continue;
                }
                $recognized = true;
                if ( in_array( $parameter, [ 'post_type', 'taxonomy' ], true ) && 'all' !== $rule['value'] ) {
                    if ( ( $type === $rule['value'] ) !== ( '!=' !== $rule['operator'] ) ) {
                        continue 2;
                    }
                }
                if ( 'post' === $kind && in_array( $parameter, [ 'post', 'page' ], true )
                    && '!=' !== $rule['operator'] && $type !== get_post_type( (int) $rule['value'] ) ) {
                    continue 2;
                }
            }
            if ( $recognized ) {
                return true;
            }
        }
        return false;
    }

    /** Compose the Core fields with any existing additional field on the same resource. */
    private static function bind( string $type ): void {
        global $wp_rest_additional_fields;
        $registered = $wp_rest_additional_fields[ $type ]['acf'] ?? [];
        $previous = self::$bindings[ $type ] ?? [];
        if ( ( $registered['get_callback'] ?? null ) === ( $previous['callback'] ?? false ) ) {
            $registered = $previous['original'];
        }
        $resource = self::$resources[ $type ];
        $groups = $resource['groups'];
        $kind = $resource['kind'];
        $properties = [];
        foreach ( $groups as $group ) {
            $properties = array_replace( $properties, RestFieldSchema::properties( $group['fields'] ) );
        }
        if ( [] === $properties ) {
            return;
        }

        $read = static function ( array $object, string $name, \WP_REST_Request $request, string $subtype ) use ( $kind, $groups, $registered ): array {
            $external = $registered['get_callback'] ?? null;
            $existing = is_callable( $external ) ? (array) $external( $object, $name, $request, $subtype ) : [];
            return array_replace( $existing, self::read( $object, $request, $kind, $groups ) );
        };
        $update = static function ( mixed $input, mixed $object, string $name, \WP_REST_Request $request, string $subtype ) use ( $kind, $groups, $registered ): mixed {
            $result = self::update( $input, $object, $kind, $groups );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $external = $registered['update_callback'] ?? null;
            return [] !== $result && is_callable( $external ) ? $external( $result, $object, $name, $request, $subtype ) : true;
        };

        $schema = $registered['schema'] ?? [];
        $schema = array_replace( [
            'description' => 'ACF field data',
            'type'        => 'object',
            'properties'  => [],
        ], $schema );
        $schema['properties'] = array_replace( $schema['properties'], $properties );
        $other_validator = $schema['arg_options']['validate_callback'] ?? null;
        $schema['arg_options']['validate_callback'] = static function ( mixed $input, \WP_REST_Request $request, string $name ) use ( $other_validator, $kind, $groups ): mixed {
            $valid = is_callable( $other_validator ) ? $other_validator( $input, $request, $name ) : rest_validate_request_arg( $input, $request, $name );
            if ( true !== $valid || ! is_array( $input ) ) {
                return $valid;
            }
            $id = (int) ( $request->get_url_params()['id'] ?? 0 );
            $scoped = self::scoped_groups( $groups, $input );
            $fields = $id > 0 ? self::matched_fields( [ $kind, $id ], $scoped ) : self::fields_from_groups( $scoped );
            $validated = self::validate_input( $input, $fields );
            return is_wp_error( $validated ) ? $validated : true;
        };
        self::$bindings[ $type ] = [ 'callback' => $read, 'original' => $registered ];
        register_rest_field( $type, 'acf', [ 'get_callback' => $read, 'update_callback' => $update, 'schema' => $schema ] );
    }

    /** Resolve the WordPress object and return the fields visible in this request. */
    private static function read( array $object, \WP_REST_Request $request, string $kind, array $groups ): array {
        $context = self::context( $object, $kind );
        if ( null === $context || ( 'edit' === $request['context'] && ! Form::can_edit( $context ) ) ) {
            return [];
        }
        if ( 'post' === $kind ) {
            $post = get_post( $context[1] );
            if ( $post && post_password_required( $post ) && ! Form::can_edit( $context ) ) {
                $password = $request['password'];
                if ( ! is_string( $password ) || ! hash_equals( $post->post_password, $password ) ) {
                    return [];
                }
            }
        }
        $format = 'standard' === $request['acf_format'] ? 'standard' : 'light';
        $result = [];
        foreach ( self::matched_fields( $context, $groups ) as $field ) {
            $result[ $field['name'] ] = RestFieldValue::read( $context, $field, $format );
        }
        return $result;
    }

    /** Save Core fields and return the unhandled input for any existing REST provider. */
    private static function update( mixed $input, mixed $object, string $kind, array $groups ): array|\WP_Error {
        if ( ! is_array( $input ) || [] === $input ) {
            return [];
        }
        $context = self::context( (array) $object, $kind );
        if ( null === $context || ! Form::can_edit( $context ) ) {
            return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit this item.' ), [ 'status' => rest_authorization_required_code() ] );
        }

        $fields = self::matched_fields( $context, self::scoped_groups( $groups, $input ) );
        $updates = self::validate_input( $input, $fields );
        if ( is_wp_error( $updates ) ) {
            return $updates;
        }
        foreach ( $updates as [ $field, $value ] ) {
            $value = current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post_deep( $value );
            RestFieldValue::write( $context, $field, $value );
        }
        return array_diff_key( $input, $updates );
    }

    /** Limit the existing groups to the explicit scope provided by REST clients. */
    private static function scoped_groups( array $groups, array $input ): array {
        $scope = $input['_acf_field_group_scope'] ?? null;
        return is_array( $scope ) && [] !== $scope
            ? array_intersect_key( $groups, array_fill_keys( array_filter( $scope, 'is_string' ), true ) )
            : $groups;
    }

    /** Resolve and validate all submitted Core values before any field is changed. */
    private static function validate_input( array $input, array $fields ): array|\WP_Error {
        $by_key = array_column( $fields, null, 'key' );
        $key_map = is_array( $input['_acf_field_key_map'] ?? null ) ? $input['_acf_field_key_map'] : [];
        $updates = [];
        foreach ( $input as $name => $value ) {
            $mapped = $key_map[ $name ] ?? null;
            $field = is_string( $mapped ) ? ( $by_key[ $mapped ] ?? null ) : ( $fields[ $name ] ?? null );
            if ( null === $field ) {
                continue;
            }
            $validation = RestFieldSchema::validate( $field, $value, 'acf[' . $name . ']' );
            if ( true === $validation ) {
                $validation = Hooks::field_filter( 'validate_rest_value', true, $field, $value, $field );
            }
            if ( true !== $validation ) {
                return is_wp_error( $validation ) ? $validation : new \WP_Error(
                    'rest_invalid_param', __( 'A field value is invalid.', 'hexa-plugin-core' ), [ 'status' => 400 ]
                );
            }
            $updates[ $name ] = [ $field, $value ];
        }
        return $updates;
    }

    /** Build the native context from the identifiers returned by WordPress controllers. */
    private static function context( array $object, string $kind ): ?array {
        $id = (int) ( $object['id'] ?? $object['ID'] ?? $object['term_id'] ?? $object['comment_ID'] ?? 0 );
        return $id > 0 ? [ $kind, $id ] : null;
    }

    /** Reuse the existing location and visibility rules for this resource. */
    private static function matched_fields( array $context, array $groups ): array {
        $screen_context = $context;
        if ( 'post' === $context[0] ) {
            $screen_context[1] = wp_is_post_revision( $context[1] ) ?: $context[1];
        }
        $screen = Storage::screen( $screen_context );
        $matched = array_intersect_key( array_column( FieldGroups::for_screen( $screen, true ), null, 'key' ), $groups );
        return self::fields_from_groups( $matched );
    }

    /** Index REST-enabled fields by their response names. */
    private static function fields_from_groups( array $groups ): array {
        $fields = [];
        foreach ( $groups as $group ) {
            if ( Audience::hidden( $group ) ) {
                continue;
            }
            foreach ( $group['fields'] as $field ) {
                if ( '' !== $field['name'] && [] !== RestFieldSchema::for_field( $field ) ) {
                    $fields[ $field['name'] ] = $field;
                }
            }
        }
        return $fields;
    }
}
