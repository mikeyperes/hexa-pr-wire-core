<?php

namespace Hexa\PluginCore\Fields;

/**
 * Native load, format and write of field values using ACF's storage layout
 * and return formats.
 */
final class Values {
    public const EDITOR_ROW_INDEX = '_hexa_row_index';

    private const LAYOUT_TYPES = [ 'accordion', 'tab', 'message' ];
    /** ACF field-type `default_value` defaults, merged into every field ACF loads. */
    private const TYPE_DEFAULTS = [
        'text' => '', 'textarea' => '', 'number' => '', 'range' => '', 'email' => '', 'url' => '', 'wysiwyg' => '', 'color_picker' => '',
        'select' => '', 'checkbox' => '', 'radio' => '', 'button_group' => '', 'true_false' => 0,
    ];

    /**
     * Resolve a selector (name or key) to its definition and stored name, as
     * acf_maybe_get_field() does: a key resolves directly; a name resolves
     * through its stored `_name` reference. Reads are strict (a never-saved
     * name is unknown, so get_field() returns the raw stored value or null);
     * update_field() is not strict and also matches registered names.
     *
     * @param array{0:string,1:int|string} $context
     * @return array{0:array<string,mixed>|null,1:string}
     */
    public static function resolve( string $selector, array $context, bool $strict = true ): array {
        if ( str_starts_with( $selector, 'field_' ) ) {
            $field = FieldGroups::get_field( $selector );
            if ( null !== $field ) {
                return [ $field, (string) $field['name'] ];
            }
        }
        // As acf_get_meta_field(): the stored reference is a field key or, occasionally, a field name.
        $reference = Storage::get( $context, $selector, true );
        if ( is_string( $reference ) && '' !== $reference ) {
            $field = FieldGroups::get_field( $reference );
            if ( null !== $field ) {
                return [ $field, $selector ];
            }
        }
        if ( ! $strict ) {
            $field = FieldGroups::get_field( $selector, Storage::acf_id( $context ) );
            if ( null !== $field ) {
                return [ $field, $selector ];
            }
        }
        return [ null, $selector ];
    }

    /**
     * Stored value with repeater/group rows assembled and keyed by sub-field key,
     * as ACF returns it unformatted.
     *
     * @param array{0:string,1:int|string} $context
     * @param array<string,mixed> $field
     */
    public static function load( array $context, array $field, string $name, bool $editor = false ): mixed {
        $type = (string) $field['type'];
        if ( in_array( $type, self::LAYOUT_TYPES, true ) ) {
            return null;
        }
        if ( 'repeater' === $type ) {
            $count = (int) Storage::get( $context, $name );
            $value = [];
            for ( $i = 0; $i < $count; $i++ ) {
                $row = [];
                foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub ) {
                    $row[ $sub['key'] ] = self::load( $context, $sub, $name . '_' . $i . '_' . $sub['name'], $editor );
                }
                $value[] = $row;
            }
        } elseif ( 'flexible_content' === $type ) {
            $value = [];
            foreach ( self::stored_layouts( $context, $name ) as $i => $layout_name ) {
                $row = [ 'acf_fc_layout' => (string) $layout_name ];
                foreach ( self::layout_fields( $field, (string) $layout_name ) as $sub ) {
                    $row[ $sub['key'] ] = self::load( $context, $sub, $name . '_' . $i . '_' . $sub['name'], $editor );
                }
                $value[] = $row;
            }
        } elseif ( 'group' === $type ) {
            $value = [];
            foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub ) {
                $value[ $sub['key'] ] = self::load( $context, $sub, $name . '_' . $sub['name'], $editor );
            }
        } elseif ( $editor && 'clone' === $type ) {
            $value = [];
            foreach ( CloneFields::children( $field ) as $sub ) {
                $value[ $sub['key'] ] = self::load( $context, $sub, CloneFields::stored_name( $field, $name, $sub ), true );
            }
        } else {
            $value = Storage::get( $context, $name );
            if ( null === $value ) {
                // As acf_get_value(): a missing value takes the field's (or its type's) default.
                $value = $field['default_value'] ?? self::TYPE_DEFAULTS[ $type ] ?? null;
            }
        }
        return Hooks::field_filter( 'load_value', $value, $field, Storage::acf_id( $context ), $field );
    }

    /**
     * Apply the ACF return format.
     *
     * @param array{0:string,1:int|string} $context
     * @param array<string,mixed> $field
     */
    public static function format( mixed $value, array $context, array $field ): mixed {
        $formatted = self::format_type( $value, $context, $field );
        return Hooks::field_filter( 'format_value', $formatted, $field, Storage::acf_id( $context ), $field );
    }

    /**
     * @param array{0:string,1:int|string} $context
     * @param array<string,mixed> $field
     */
    private static function format_type( mixed $value, array $context, array $field ): mixed {
        $return = (string) ( $field['return_format'] ?? '' );
        switch ( (string) $field['type'] ) {
            case 'repeater':
                if ( ! is_array( $value ) || [] === $value ) {
                    return false;
                }
                $rows = [];
                foreach ( $value as $row ) {
                    $out = [];
                    foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub ) {
                        $out[ $sub['name'] ] = self::format( $row[ $sub['key'] ] ?? null, $context, $sub );
                    }
                    $rows[] = $out;
                }
                return $rows;
            case 'flexible_content':
                if ( ! is_array( $value ) || [] === $value ) {
                    return false;
                }
                $rows = [];
                foreach ( $value as $row ) {
                    $layout = (string) ( $row['acf_fc_layout'] ?? '' );
                    $out = [ 'acf_fc_layout' => $layout ];
                    foreach ( self::layout_fields( $field, $layout ) as $sub ) {
                        $out[ $sub['name'] ] = self::format( $row[ $sub['key'] ] ?? null, $context, $sub );
                    }
                    $rows[] = $out;
                }
                return $rows;
            case 'group':
                $out = [];
                foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub ) {
                    $out[ $sub['name'] ] = self::format( is_array( $value ) ? ( $value[ $sub['key'] ] ?? null ) : null, $context, $sub );
                }
                return $out;
            case 'true_false':
                return (bool) (int) $value;
            case 'textarea':
                $value = (string) $value;
                $lines = (string) ( $field['new_lines'] ?? '' );
                return 'wpautop' === $lines ? wpautop( $value ) : ( 'br' === $lines ? nl2br( $value ) : $value );
            case 'wysiwyg':
                return self::content( (string) $value );
            case 'select':
            case 'checkbox':
            case 'radio':
            case 'button_group':
                return self::choice( $value, $field, '' !== $return ? $return : 'value' );
            case 'image':
            case 'file':
                return self::media( $value, '' !== $return ? $return : 'array' );
            case 'gallery':
                if ( empty( $value ) ) {
                    return false;
                }
                return array_values( array_filter( array_map( static fn( $id ) => self::media( $id, '' !== $return ? $return : 'array' ), (array) $value ) ) );
            case 'post_object':
            case 'relationship':
                return self::posts( $value, $field, '' !== $return ? $return : 'object' );
            case 'page_link':
                return self::links( $value, $field );
            case 'user':
                return self::users( $value, $field, '' !== $return ? $return : 'array' );
            case 'taxonomy':
                return self::terms( $value, $field, '' !== $return ? $return : 'id' );
            case 'link':
                if ( ! is_array( $value ) ) {
                    return 'url' === $return ? (string) $value : $value;
                }
                $link = array_merge( [ 'title' => '', 'url' => '', 'target' => '' ], $value );
                return 'url' === $return ? $link['url'] : $link;
            case 'date_picker':
                return self::date( $value, '' !== $return ? $return : 'd/m/Y' );
            case 'date_time_picker':
                return self::date( $value, '' !== $return ? $return : 'd/m/Y g:i a' );
            case 'time_picker':
                return self::date( $value, '' !== $return ? $return : 'g:i a' );
            case 'oembed':
                return self::embed( (string) $value );
            default:
                return $value;
        }
    }

    /**
     * Write a value in ACF's layout, firing native update_value filters.
     *
     * @param array{0:string,1:int|string} $context
     * @param array<string,mixed> $field
     */
    public static function write( array $context, array $field, string $name, mixed $value, bool $editor = false ): bool {
        $value = Hooks::field_filter( 'update_value', $value, $field, Storage::acf_id( $context ), $field, $value );
        if ( null === $value && in_array( $field['type'], [ 'repeater', 'group', 'flexible_content' ], true ) ) {
            return false;
        }
        if ( $editor && 'clone' === $field['type'] ) {
            // Bug: a clone is edited as fields, but its name is only a storage marker.
            // Fix: save each submitted child under its resolved metadata name.
            // Why: existing readers keep using the original field keys and names.
            if ( [] === $value ) {
                return true;
            }
            foreach ( CloneFields::children( $field ) as $sub ) {
                if ( is_array( $value ) && array_key_exists( $sub['key'], $value ) ) {
                    self::write( $context, $sub, CloneFields::stored_name( $field, $name, $sub ), $value[ $sub['key'] ], true );
                }
            }
            $value = Storage::get( $context, $name ) ?? '';
        }
        switch ( (string) $field['type'] ) {
            case 'repeater':
                $rows = is_array( $value ) ? array_values( $value ) : [];
                $old = (int) Storage::get( $context, $name );
                foreach ( $rows as $i => $row ) {
                    self::write_children( $context, (array) ( $field['sub_fields'] ?? [] ), $name . '_' . $i . '_', $row, $editor );
                }
                for ( $i = count( $rows ); $i < $old; $i++ ) {
                    foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub ) {
                        self::erase( $context, $sub, $name . '_' . $i . '_' . $sub['name'], $editor );
                    }
                }
                // ACF stores an empty repeater as an empty string.
                Storage::update( $context, $name, [] === $rows ? '' : count( $rows ) );
                break;
            case 'flexible_content':
                $rows = is_array( $value ) ? array_values( $value ) : [];
                $layouts = array_map( static fn( $row ): string => (string) ( is_array( $row ) ? ( $row['acf_fc_layout'] ?? '' ) : '' ), $rows );
                if ( $editor ) {
                    // Bug: replacing or deleting a row can leave its old child metadata behind.
                    // Fix: erase the outgoing layout's keys before writing the new rows.
                    // Why: row positions are part of existing metadata names.
                    foreach ( self::stored_layouts( $context, $name ) as $i => $old_layout ) {
                        $source = $rows[ $i ][ self::EDITOR_ROW_INDEX ] ?? $i;
                        if ( $source !== $i || (string) $old_layout !== ( $layouts[ $i ] ?? null ) ) {
                            foreach ( self::layout_fields( $field, (string) $old_layout ) as $sub ) {
                                self::erase( $context, $sub, $name . '_' . $i . '_' . $sub['name'], true );
                            }
                        }
                    }
                }
                foreach ( $rows as $i => $row ) {
                    self::write_children( $context, self::layout_fields( $field, $layouts[ $i ] ), $name . '_' . $i . '_', $row, $editor );
                }
                if ( ! $editor || [] !== $layouts || [] !== self::stored_layouts( $context, $name ) ) {
                    Storage::update( $context, $name, $layouts );
                } else {
                    return true;
                }
                break;
            case 'group':
                self::write_children( $context, (array) ( $field['sub_fields'] ?? [] ), $name . '_', $value, $editor );
                Storage::update( $context, $name, '' );
                break;
            default:
                if ( in_array( $field['type'], self::LAYOUT_TYPES, true ) ) {
                    return true;
                }
                Storage::update( $context, $name, self::storable( $field, $value ) );
        }
        if ( '' !== (string) $field['key'] ) {
            Storage::update( $context, $name, (string) $field['key'], true );
        }
        return true;
    }

    /**
     * @param array{0:string,1:int|string} $context
     * @param array<string,mixed> $field
     */
    public static function erase( array $context, array $field, string $name, bool $editor = false ): bool {
        if ( 'repeater' === $field['type'] || 'flexible_content' === $field['type'] ) {
            $stored = Storage::get( $context, $name );
            $layouts = is_array( $stored ) ? array_values( $stored ) : [];
            $flexible = 'flexible_content' === $field['type'];
            $count = $flexible ? count( $layouts ) : (int) $stored;
            for ( $i = 0; $i < $count; $i++ ) {
                $children = $flexible ? self::layout_fields( $field, (string) $layouts[ $i ] ) : (array) ( $field['sub_fields'] ?? [] );
                foreach ( $children as $sub ) {
                    self::erase( $context, $sub, $name . '_' . $i . '_' . $sub['name'], $editor );
                }
            }
        } elseif ( 'group' === $field['type'] ) {
            foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub ) {
                self::erase( $context, $sub, $name . '_' . $sub['name'], $editor );
            }
        } elseif ( $editor && 'clone' === $field['type'] ) {
            foreach ( CloneFields::children( $field ) as $sub ) {
                self::erase( $context, $sub, CloneFields::stored_name( $field, $name, $sub ), true );
            }
        }
        Storage::delete( $context, $name, true );
        return Storage::delete( $context, $name );
    }

    /** Writes child controls through the same value pipeline as top-level controls. */
    private static function write_children( array $context, array $children, string $prefix, mixed $row, bool $editor ): void {
        if ( $editor ) {
            // Put clone changes after any source controls that share the same storage names.
            usort( $children, static fn( array $a, array $b ): int => ( 'clone' === $a['type'] ) <=> ( 'clone' === $b['type'] ) );
        }
        foreach ( $children as $child ) {
            if ( $editor && ( ! is_array( $row ) || ! array_key_exists( $child['key'], $row ) ) ) {
                continue;
            }
            self::write( $context, $child, $prefix . $child['name'], self::pick( $row, $child ), $editor );
        }
    }

    /** Prepares the submitted controls against their original stored rows before writes begin. */
    public static function prepare_editor_value( array $field, mixed $value, mixed $previous, bool $moved = false ): mixed {
        switch ( (string) $field['type'] ) {
            case 'google_map':
                // Bug: a map edit submitted as an array would lose its keys and provider details.
                // Fix: keep the original payload for an untouched location.
                // Why: readers depend on its shape and may use provider fields beyond the editor.
                if ( is_array( $value ) && is_array( $previous )
                    && ( $value['address'] ?? '' ) === (string) ( $previous['address'] ?? '' )
                    && isset( $previous['lat'], $previous['lng'] )
                    && $value['lat'] === (float) $previous['lat'] && $value['lng'] === (float) $previous['lng'] ) {
                    return $previous;
                }
                return false === $value && ! is_array( $previous ) ? ( $previous ?? false ) : $value;
            case 'clone':
                return self::prepare_editor_children( CloneFields::children( $field ), $value, $previous, $moved, true );
            case 'group':
                return self::prepare_editor_children( (array) ( $field['sub_fields'] ?? [] ), $value, $previous, $moved );
            case 'repeater':
            case 'flexible_content':
                return self::prepare_editor_rows( $field, $value, (array) $previous, $moved );
            default:
                return $value;
        }
    }

    /** Fills missing child inputs only when a row moves to a different storage name. */
    private static function prepare_editor_children( array $children, mixed $value, mixed $previous, bool $moved, bool $changes_only = false ): array {
        $clean = [];
        foreach ( $children as $child ) {
            $key = $child['key'];
            if ( is_array( $value ) && array_key_exists( $key, $value ) ) {
                $prior = $previous[ $key ] ?? null;
                $submitted = self::prepare_editor_value( $child, $value[ $key ], $prior, $moved );
                // A seamless clone and its source can share metadata on the same form.
                // Leave unchanged clone controls out of the write so they cannot undo a source edit.
                if ( ! $changes_only || $moved || $submitted != $prior ) {
                    $clean[ $key ] = $submitted;
                }
            } elseif ( $moved && is_array( $previous ) && array_key_exists( $key, $previous ) ) {
                $clean[ $key ] = $previous[ $key ];
            }
        }
        return $clean;
    }

    /** Checks submitted row identities and preserves children when rows move. */
    private static function prepare_editor_rows( array $field, array $rows, array $previous, bool $moved ): array {
        $flexible = 'flexible_content' === $field['type'];
        $layouts = $flexible ? self::layouts( $field ) : [];
        $seen = [];
        foreach ( $rows as $i => &$row ) {
            $index = $row[ self::EDITOR_ROW_INDEX ] ?? $i;
            $original = $previous[ $index ] ?? [];
            if ( $index >= 0 ) {
                if ( isset( $seen[ $index ] ) ) {
                    throw new \InvalidArgumentException( __( 'A content row was submitted more than once. Reload and try again.', 'hexa-plugin-core' ) );
                }
                $seen[ $index ] = true;
            }
            $layout = $row['acf_fc_layout'] ?? '';
            if ( $flexible ) {
                if ( ! isset( $layouts[ $layout ] ) && ( $index !== $i || $layout !== ( $original['acf_fc_layout'] ?? null ) ) ) {
                    throw new \InvalidArgumentException( __( 'A content row has an unavailable layout.', 'hexa-plugin-core' ) );
                }
                if ( $layout !== ( $original['acf_fc_layout'] ?? null ) ) {
                    $original = [];
                }
            }
            $children = $flexible ? self::layout_fields( $field, $layout ) : (array) ( $field['sub_fields'] ?? [] );
            $row = self::prepare_editor_children( $children, $row, $original, $moved || $i !== $index );
            if ( $flexible ) {
                $row['acf_fc_layout'] = $layout;
                $row[ self::EDITOR_ROW_INDEX ] = $index;
            }
        }
        unset( $row );
        if ( $flexible ) {
            foreach ( $previous as $i => $row ) {
                $layout = $row['acf_fc_layout'];
                if ( ! isset( $layouts[ $layout ] ) && $layout !== ( $rows[ $i ]['acf_fc_layout'] ?? null ) ) {
                    throw new \InvalidArgumentException( __( 'A saved content layout is unavailable. Its row must stay in place.', 'hexa-plugin-core' ) );
                }
            }
        }
        return $rows;
    }

    /** @param array<string,mixed> $field */
    private static function storable( array $field, mixed $value ): mixed {
        $multiple = ! empty( $field['multiple'] ) || in_array( $field['type'], [ 'gallery', 'relationship', 'checkbox' ], true )
            || ( 'taxonomy' === $field['type'] && in_array( (string) ( $field['field_type'] ?? 'checkbox' ), [ 'checkbox', 'multi_select' ], true ) );
        switch ( (string) $field['type'] ) {
            case 'true_false':
                return ! empty( $value ) && '0' !== $value ? 1 : 0;
            case 'image':
            case 'file':
            case 'post_object':
            case 'relationship':
            case 'gallery':
            case 'user':
            case 'taxonomy':
            case 'page_link':
                $ids = array_values( array_filter( array_map( [ self::class, 'object_id' ], is_array( $value ) && ( $multiple || array_is_list( $value ) ) ? $value : [ $value ] ), static fn( $id ) => '' !== $id && null !== $id ) );
                return $multiple ? ( [] === $ids ? '' : array_map( 'strval', $ids ) ) : ( $ids[0] ?? '' );
            case 'select':
                return $multiple ? ( empty( $value ) ? '' : array_values( (array) $value ) ) : ( is_array( $value ) ? (string) reset( $value ) : $value );
            case 'checkbox':
                return empty( $value ) ? '' : array_values( (array) $value );
            case 'date_picker':
                $value = (string) $value;
                return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? str_replace( '-', '', $value ) : $value;
            case 'date_time_picker':
                $value = (string) $value;
                return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value ) ? str_replace( 'T', ' ', $value ) . ( 16 === strlen( $value ) ? ':00' : '' ) : $value;
            case 'link':
                return is_array( $value ) ? array_intersect_key( array_merge( [ 'title' => '', 'url' => '', 'target' => '' ], $value ), [ 'title' => 1, 'url' => 1, 'target' => 1 ] ) : $value;
            default:
                return $value;
        }
    }

    private static function object_id( mixed $item ): int|string {
        if ( $item instanceof \WP_Post || $item instanceof \WP_User ) {
            return (int) $item->ID;
        }
        if ( $item instanceof \WP_Term ) {
            return (int) $item->term_id;
        }
        if ( is_array( $item ) ) {
            return (int) ( $item['ID'] ?? $item['id'] ?? $item['term_id'] ?? 0 ) ?: '';
        }
        return is_numeric( $item ) ? (int) $item : ( is_string( $item ) ? $item : '' );
    }

    /** @param array<string,mixed> $sub */
    private static function pick( mixed $row, array $sub ): mixed {
        if ( ! is_array( $row ) ) {
            return null;
        }
        return array_key_exists( $sub['name'], $row ) ? $row[ $sub['name'] ] : ( $row[ $sub['key'] ] ?? null );
    }

    /**
     * @param array<string,mixed> $field
     * @return array<int,array<string,mixed>>
     */
    public static function layout_fields( array $field, string $layout ): array {
        return (array) ( self::layouts( $field )[ $layout ]['sub_fields'] ?? [] );
    }

    /** Reads ordered layout names, including legacy fields cleared to an empty string. */
    private static function stored_layouts( array $context, string $name ): array {
        $stored = Storage::get( $context, $name );
        return is_array( $stored ) ? array_values( $stored ) : [];
    }

    /** Returns layout definitions keyed by their existing storage names. */
    public static function layouts( array $field ): array {
        $layouts = [];
        foreach ( (array) ( $field['layouts'] ?? [] ) as $definition ) {
            if ( is_array( $definition ) && '' !== (string) ( $definition['name'] ?? '' ) ) {
                $layouts[ $definition['name'] ] ??= $definition;
            }
        }
        return $layouts;
    }

    /** WordPress content formatters for Core's rich-text field values. */
    private static function content( string $value ): string {
        if ( '' === $value ) {
            return $value;
        }
        if ( ! has_filter( 'hexa_fields/the_content', 'wpautop' ) ) {
            add_filter( 'hexa_fields/the_content', 'capital_P_dangit', 11 );
            add_filter( 'hexa_fields/the_content', 'wptexturize' );
            add_filter( 'hexa_fields/the_content', 'convert_smilies', 20 );
            add_filter( 'hexa_fields/the_content', 'wpautop' );
            add_filter( 'hexa_fields/the_content', 'shortcode_unautop' );
            add_filter( 'hexa_fields/the_content', function_exists( 'wp_filter_content_tags' ) ? 'wp_filter_content_tags' : 'wp_make_content_images_responsive' );
            add_filter( 'hexa_fields/the_content', 'do_shortcode', 11 );
        }
        return (string) apply_filters( 'hexa_fields/the_content', $value );
    }

    /** @param array<string,mixed> $field */
    private static function choice( mixed $value, array $field, string $return ): mixed {
        $choices = (array) ( $field['choices'] ?? [] );
        $map = static function ( $item ) use ( $choices, $return ) {
            $label = $choices[ $item ] ?? $item;
            return 'label' === $return ? $label : ( 'array' === $return ? [ 'value' => $item, 'label' => $label ] : $item );
        };
        if ( is_array( $value ) ) {
            return array_map( $map, $value );
        }
        if ( null === $value || '' === $value ) {
            return $value;
        }
        return $map( $value );
    }

    private static function media( mixed $value, string $return ): mixed {
        $id = (int) self::object_id( $value );
        if ( $id <= 0 ) {
            return false;
        }
        if ( 'id' === $return ) {
            return $id;
        }
        if ( 'url' === $return ) {
            return (string) wp_get_attachment_url( $id );
        }
        return self::attachment( $id );
    }

    /** @return array<string,mixed>|false ACF-shaped attachment array. */
    public static function attachment( int $id ): array|false {
        $post = get_post( $id );
        if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
            return false;
        }
        $url = (string) wp_get_attachment_url( $id );
        $meta = (array) wp_get_attachment_metadata( $id );
        $mime = explode( '/', (string) $post->post_mime_type, 2 );
        $file = get_attached_file( $id );
        $attachment = [
            'ID' => $id, 'id' => $id, 'title' => $post->post_title, 'filename' => wp_basename( $file ?: $url ),
            'filesize' => (int) ( $meta['filesize'] ?? ( $file && file_exists( $file ) ? filesize( $file ) : 0 ) ),
            'url' => $url, 'link' => get_attachment_link( $id ), 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'author' => (string) $post->post_author, 'description' => $post->post_content, 'caption' => $post->post_excerpt,
            'name' => $post->post_name, 'status' => $post->post_status, 'uploaded_to' => (int) $post->post_parent,
            'date' => $post->post_date_gmt, 'modified' => $post->post_modified_gmt, 'menu_order' => (int) $post->menu_order,
            'mime_type' => $post->post_mime_type, 'type' => $mime[0], 'subtype' => $mime[1] ?? '', 'icon' => wp_mime_type_icon( $id ),
        ];
        if ( 'image' === $mime[0] ) {
            $attachment['width'] = (int) ( $meta['width'] ?? 0 );
            $attachment['height'] = (int) ( $meta['height'] ?? 0 );
            $attachment['sizes'] = [];
            foreach ( get_intermediate_image_sizes() as $size ) {
                $source = wp_get_attachment_image_src( $id, $size );
                if ( is_array( $source ) ) {
                    $attachment['sizes'][ $size ] = $source[0];
                    $attachment['sizes'][ $size . '-width' ] = (int) $source[1];
                    $attachment['sizes'][ $size . '-height' ] = (int) $source[2];
                }
            }
        }
        return $attachment;
    }

    /** @param array<string,mixed> $field */
    private static function posts( mixed $value, array $field, string $return ): mixed {
        $multiple = 'relationship' === $field['type'] || ! empty( $field['multiple'] );
        $ids = array_values( array_filter( array_map( 'intval', is_array( $value ) ? $value : ( '' === (string) $value || null === $value ? [] : [ $value ] ) ) ) );
        if ( [] === $ids ) {
            return false;
        }
        $items = 'id' === $return ? $ids : self::visible_posts( $ids, $field );
        return $multiple ? $items : ( $items[0] ?? false );
    }

    /**
     * Posts acf_get_posts() returns for these IDs: registered post types (the
     * field's own when set), not trashed or auto-draft, in ID order.
     *
     * @param array<int,int> $ids
     * @param array<string,mixed> $field
     * @return array<int,\WP_Post>
     */
    private static function visible_posts( array $ids, array $field ): array {
        $types = array_filter( (array) ( $field['post_type'] ?? [] ) );
        $statuses = array_filter( (array) ( $field['post_status'] ?? [] ) );
        $posts = [];
        foreach ( $ids as $id ) {
            $post = get_post( (int) $id );
            if ( ! $post instanceof \WP_Post || ! post_type_exists( $post->post_type ) || in_array( $post->post_type, [ 'acf-field-group', 'acf-field' ], true ) ) {
                continue;
            }
            if ( [] !== $types && ! in_array( $post->post_type, $types, true ) ) {
                continue;
            }
            if ( [] !== $statuses ? ! in_array( $post->post_status, $statuses, true ) : in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
                continue;
            }
            $posts[] = $post;
        }
        return $posts;
    }

    /** @param array<string,mixed> $field */
    private static function links( mixed $value, array $field ): mixed {
        if ( empty( $value ) ) {
            return $value;
        }
        // As ACF's page_link: post IDs become permalinks of visible posts; archive URLs stay.
        $items = [];
        foreach ( (array) $value as $item ) {
            if ( ! is_numeric( $item ) ) {
                $items[] = $item;
                continue;
            }
            $post = self::visible_posts( [ (int) $item ], $field )[0] ?? null;
            if ( null !== $post ) {
                $items[] = (string) get_permalink( $post );
            }
        }
        return ! empty( $field['multiple'] ) ? $items : ( $items[0] ?? null );
    }

    /** @param array<string,mixed> $field */
    private static function users( mixed $value, array $field, string $return ): mixed {
        $ids = array_values( array_filter( array_map( 'intval', is_array( $value ) ? $value : ( empty( $value ) ? [] : [ $value ] ) ) ) );
        $items = [];
        foreach ( $ids as $id ) {
            if ( 'id' === $return ) {
                $items[] = $id;
                continue;
            }
            $user = get_userdata( $id );
            if ( ! $user instanceof \WP_User ) {
                continue;
            }
            $items[] = 'object' === $return ? $user : [
                'ID' => $user->ID, 'user_firstname' => $user->user_firstname, 'user_lastname' => $user->user_lastname,
                'nickname' => $user->nickname, 'user_nicename' => $user->user_nicename, 'display_name' => $user->display_name,
                'user_email' => $user->user_email, 'user_url' => $user->user_url, 'user_registered' => $user->user_registered,
                'user_description' => $user->user_description, 'user_avatar' => get_avatar( $user->ID ),
            ];
        }
        return ! empty( $field['multiple'] ) ? ( [] === $items ? false : $items ) : ( $items[0] ?? false );
    }

    /** @param array<string,mixed> $field */
    private static function terms( mixed $value, array $field, string $return ): mixed {
        $multiple = in_array( (string) ( $field['field_type'] ?? 'checkbox' ), [ 'checkbox', 'multi_select' ], true );
        $ids = array_values( array_filter( array_map( 'intval', is_array( $value ) ? $value : ( empty( $value ) ? [] : [ $value ] ) ) ) );
        $items = 'object' === $return ? array_values( array_filter( array_map( static fn( int $id ) => ( $term = get_term( $id ) ) instanceof \WP_Term ? $term : null, $ids ) ) ) : $ids;
        return $multiple ? ( [] === $items ? false : $items ) : ( $items[0] ?? false );
    }

    private static function date( mixed $value, string $format ): mixed {
        if ( empty( $value ) || ! is_string( $value ) ) {
            return $value;
        }
        $timestamp = strtotime( preg_match( '/^\d{8}$/', $value ) ? substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 ) : $value );
        return false === $timestamp ? $value : date_i18n( $format, $timestamp );
    }

    private static function embed( string $url ): string {
        if ( '' === $url ) {
            return $url;
        }
        $cache = 'hexa_fields_oembed_' . md5( $url );
        $html = get_transient( $cache );
        if ( false === $html ) {
            $html = (string) wp_oembed_get( $url );
            set_transient( $cache, $html, DAY_IN_SECONDS );
        }
        return '' !== $html ? $html : $url;
    }
}
