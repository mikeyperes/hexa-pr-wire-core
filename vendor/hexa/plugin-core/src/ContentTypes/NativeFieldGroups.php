<?php

namespace Hexa\PluginCore\ContentTypes;

/**
 * Native WordPress storage and editing for content-type field groups while ACF
 * is not active.
 *
 * Values use the meta keys ACF would write (group sub fields as
 * `<group>_<field>` plus the `_<meta_key>` field-key reference), so existing
 * data stays readable and carries over if ACF is activated later. Supported
 * field types: text, textarea, email, url, number, radio, select, true_false
 * and group. Other ACF types are skipped in native mode.
 */
final class NativeFieldGroups {
    public const INPUT = 'hexa_native_fields';

    private const NONCE_ACTION = 'hexa_native_fields_save';
    private const NONCE_FIELD  = 'hexa_native_fields_nonce';
    private const SUPPORTED    = [ 'text', 'textarea', 'email', 'url', 'number', 'radio', 'select', 'true_false' ];

    public function __construct( private ContentTypeRegistry $registry ) {}

    /** True when ACF is unavailable and this module owns field storage and editing. */
    public static function active(): bool {
        return ! function_exists( 'acf_add_local_field_group' );
    }

    /** `acf` when ACF owns the field groups, `native` otherwise. */
    public static function mode(): string {
        return self::active() ? 'native' : 'acf';
    }

    public function register( int $priority ): void {
        add_action( 'init', [ $this, 'register_meta' ], $priority + 1 );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ], 10, 1 );
        add_action( 'save_post', [ $this, 'save' ], 10, 2 );
    }

    public function register_meta(): void {
        if ( ! self::active() ) {
            return;
        }
        foreach ( $this->groups() as $group ) {
            foreach ( $group['fields'] as $field ) {
                register_post_meta(
                    $group['post_type'],
                    $field['meta_key'],
                    [
                        'type'              => 'string',
                        'single'            => true,
                        'show_in_rest'      => false,
                        'sanitize_callback' => fn( $value ) => $this->sanitize( $field, $value ),
                        'auth_callback'     => static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
                    ]
                );
            }
        }
    }

    public function add_meta_boxes( string $post_type ): void {
        if ( ! self::active() ) {
            return;
        }
        foreach ( $this->groups( $post_type ) as $group ) {
            add_meta_box(
                'hexa-native-' . sanitize_html_class( $group['key'] ),
                $group['title'],
                [ $this, 'render' ],
                $post_type,
                'normal',
                'default',
                [ 'group' => $group ]
            );
        }
    }

    /** @param array<string,mixed> $box */
    public function render( \WP_Post $post, array $box ): void {
        $group = (array) ( $box['args']['group'] ?? [] );
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        $section = '';
        foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
            if ( $field['section'] !== $section ) {
                $section = $field['section'];
                if ( '' !== $section ) {
                    echo '<h4 style="margin:16px 0 8px">' . esc_html( $section ) . '</h4>';
                }
            }
            $value = metadata_exists( 'post', $post->ID, $field['meta_key'] )
                ? (string) get_post_meta( $post->ID, $field['meta_key'], true )
                : (string) $field['default'];
            echo '<div class="hexa-native-field" style="margin:0 0 12px">';
            $this->render_input( $field, $value );
            if ( '' !== $field['instructions'] ) {
                echo '<p class="description">' . esc_html( $field['instructions'] ) . '</p>';
            }
            echo '</div>';
        }
    }

    public function save( int $post_id, \WP_Post $post ): void {
        if ( ! self::active() || ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            return;
        }
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $input = isset( $_POST[ self::INPUT ] ) && is_array( $_POST[ self::INPUT ] ) ? wp_unslash( $_POST[ self::INPUT ] ) : [];
        foreach ( $this->groups( $post->post_type ) as $group ) {
            foreach ( $group['fields'] as $field ) {
                if ( ! array_key_exists( $field['meta_key'], $input ) ) {
                    continue;
                }
                $raw = $input[ $field['meta_key'] ];
                update_post_meta( $post_id, $field['meta_key'], $this->sanitize( $field, is_scalar( $raw ) ? (string) $raw : '' ) );
                if ( '' !== $field['key'] ) {
                    update_post_meta( $post_id, '_' . $field['meta_key'], $field['key'] );
                }
            }
        }
    }

    /**
     * Enabled field groups with their supported fields flattened to meta keys.
     *
     * @return array<int,array{post_type:string,key:string,title:string,fields:array<int,array<string,mixed>>}>
     */
    public function groups( string $post_type = '' ): array {
        $groups = [];
        foreach ( $this->registry->resolved_definitions() as $definition ) {
            $key = (string) ( $definition['post_type']['key'] ?? '' );
            if ( empty( $definition['enabled'] ) || '' === $key || ( '' !== $post_type && $post_type !== $key ) ) {
                continue;
            }
            foreach ( (array) ( $definition['field_groups'] ?? [] ) as $group ) {
                if ( empty( $group['enabled'] ) ) {
                    continue;
                }
                $acf = is_callable( $group['definition'] ?? null ) ? call_user_func( $group['definition'], $definition, $group ) : ( $group['definition'] ?? [] );
                if ( ! is_array( $acf ) ) {
                    continue;
                }
                $fields = $this->flatten( (array) ( $acf['fields'] ?? [] ) );
                if ( [] === $fields ) {
                    continue;
                }
                $groups[] = [
                    'post_type' => $key,
                    'key'       => (string) ( $acf['key'] ?? $group['group_key'] ?? $group['id'] ?? '' ),
                    'title'     => (string) ( $group['label'] ?? $acf['title'] ?? '' ),
                    'fields'    => $fields,
                ];
            }
        }
        return $groups;
    }

    /**
     * @param array<int,mixed> $fields
     * @return array<int,array<string,mixed>>
     */
    private function flatten( array $fields, string $prefix = '', string $section = '' ): array {
        $flat = [];
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $name = (string) ( $field['name'] ?? '' );
            $type = (string) ( $field['type'] ?? 'text' );
            if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]+$/', $name ) ) {
                continue;
            }
            $label = (string) ( $field['label'] ?? $name );
            if ( 'group' === $type ) {
                $flat = array_merge( $flat, $this->flatten( (array) ( $field['sub_fields'] ?? [] ), $prefix . $name . '_', $label ) );
                continue;
            }
            if ( ! in_array( $type, self::SUPPORTED, true ) ) {
                continue;
            }
            $flat[] = [
                'meta_key'     => $prefix . $name,
                'key'          => (string) ( $field['key'] ?? '' ),
                'label'        => $label,
                'type'         => $type,
                'instructions' => (string) ( $field['instructions'] ?? '' ),
                'choices'      => is_array( $field['choices'] ?? null ) ? $field['choices'] : [],
                'default'      => is_scalar( $field['default_value'] ?? null ) ? (string) $field['default_value'] : '',
                'section'      => $section,
            ];
        }
        return $flat;
    }

    /** @param array<string,mixed> $field */
    private function sanitize( array $field, mixed $value ): string {
        $value = is_scalar( $value ) ? (string) $value : '';
        switch ( $field['type'] ) {
            case 'textarea':
                return sanitize_textarea_field( $value );
            case 'email':
                return sanitize_email( $value );
            case 'url':
                return esc_url_raw( $value );
            case 'number':
                return is_numeric( $value ) ? $value : '';
            case 'true_false':
                return '1' === $value ? '1' : '0';
            case 'radio':
            case 'select':
                return array_key_exists( $value, $field['choices'] ) ? $value : '';
            default:
                return sanitize_text_field( $value );
        }
    }

    /** @param array<string,mixed> $field */
    private function render_input( array $field, string $value ): void {
        $id   = 'hexa-native-' . sanitize_html_class( $field['meta_key'] );
        $name = self::INPUT . '[' . $field['meta_key'] . ']';
        switch ( $field['type'] ) {
            case 'radio':
                echo '<fieldset><legend><strong>' . esc_html( $field['label'] ) . '</strong></legend>';
                foreach ( $field['choices'] as $choice => $label ) {
                    echo '<label style="display:block;margin:4px 0"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $choice ) . '"' . checked( $value, (string) $choice, false ) . '> ' . esc_html( (string) $label ) . '</label>';
                }
                echo '</fieldset>';
                return;
            case 'select':
                echo '<label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $field['label'] ) . '</strong></label><br><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
                foreach ( $field['choices'] as $choice => $label ) {
                    echo '<option value="' . esc_attr( (string) $choice ) . '"' . selected( $value, (string) $choice, false ) . '>' . esc_html( (string) $label ) . '</option>';
                }
                echo '</select>';
                return;
            case 'true_false':
                echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $value, '1', false ) . '> <strong>' . esc_html( $field['label'] ) . '</strong></label>';
                return;
            case 'textarea':
                echo '<label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $field['label'] ) . '</strong></label><textarea class="widefat" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
                return;
            default:
                $type = in_array( $field['type'], [ 'email', 'url', 'number' ], true ) ? $field['type'] : 'text';
                echo '<label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $field['label'] ) . '</strong></label><input class="widefat" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
        }
    }
}
