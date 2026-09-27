<?php

namespace Hexa\PluginCore\Fields;

/**
 * ACF-compatible storage addressing.
 *
 * Contexts follow ACF's `$post_id` conventions: a post ID or object,
 * `user_{id}`, `term_{id}` or `{taxonomy}_{id}`, `comment_{id}`, and
 * `option`/`options` or any other string for option storage. Values live
 * where ACF keeps them: post/user/term/comment meta, or `{prefix}_{name}`
 * options, each with the `_{name}` field-key reference.
 */
final class Storage {
    /** @return array{0:string,1:int|string} [type, id] */
    public static function context( mixed $context = false ): array {
        if ( $context instanceof \WP_Post ) {
            return [ 'post', (int) $context->ID ];
        }
        if ( $context instanceof \WP_User ) {
            return [ 'user', (int) $context->ID ];
        }
        if ( $context instanceof \WP_Term ) {
            return [ 'term', (int) $context->term_id ];
        }
        if ( $context instanceof \WP_Comment ) {
            return [ 'comment', (int) $context->comment_ID ];
        }
        if ( false === $context || null === $context || '' === $context || 0 === $context || '0' === $context ) {
            return self::current();
        }
        if ( is_numeric( $context ) ) {
            return [ 'post', (int) $context ];
        }
        $context = (string) $context;
        if ( 'option' === $context || 'options' === $context ) {
            return [ 'option', 'options' ];
        }
        if ( preg_match( '/^(user|term|comment)_(\d+)$/', $context, $match ) ) {
            return [ $match[1], (int) $match[2] ];
        }
        if ( preg_match( '/^(.+)_(\d+)$/', $context, $match ) && function_exists( 'taxonomy_exists' ) && taxonomy_exists( $match[1] ) ) {
            return [ 'term', (int) $match[2] ];
        }
        return [ 'option', $context ];
    }

    /** ACF-style post_id string for hooks and filters. */
    public static function acf_id( array $context ): int|string {
        [ $type, $id ] = $context;
        return 'post' === $type ? (int) $id : ( 'option' === $type ? (string) $id : $type . '_' . $id );
    }

    /**
     * Screen description used for location matching.
     *
     * @param array{0:string,1:int|string} $context
     * @return array<string,mixed>
     */
    public static function screen( array $context ): array {
        [ $type, $id ] = $context;
        switch ( $type ) {
            case 'post':
                $post = get_post( (int) $id );
                return $post instanceof \WP_Post
                    ? [ 'post_type' => $post->post_type, 'post_id' => $post->ID, 'template' => (string) ( get_page_template_slug( $post ) ?: 'default' ) ]
                    : [ 'post_type' => '' ];
            case 'user':
                $user = get_userdata( (int) $id );
                return [ 'user_form' => 'edit', 'user_roles' => $user instanceof \WP_User ? (array) $user->roles : [] ];
            case 'term':
                $term = get_term( (int) $id );
                return [ 'taxonomy' => $term instanceof \WP_Term ? $term->taxonomy : '' ];
            case 'option':
                return [ 'options_page' => '*' ];
            default:
                return [];
        }
    }

    /** @param array{0:string,1:int|string} $context */
    public static function get( array $context, string $name ): mixed {
        [ $type, $id ] = $context;
        if ( 'option' === $type ) {
            return get_option( $id . '_' . $name, null );
        }
        if ( ! metadata_exists( $type, (int) $id, $name ) ) {
            return null;
        }
        return get_metadata( $type, (int) $id, $name, true );
    }

    /** @param array{0:string,1:int|string} $context */
    public static function update( array $context, string $name, mixed $value ): bool {
        [ $type, $id ] = $context;
        if ( 'option' === $type ) {
            $option = $id . '_' . $name;
            return update_option( $option, $value ) || get_option( $option ) === $value;
        }
        return false !== update_metadata( $type, (int) $id, $name, $value );
    }

    /** @param array{0:string,1:int|string} $context */
    public static function delete( array $context, string $name ): bool {
        [ $type, $id ] = $context;
        if ( 'option' === $type ) {
            return delete_option( $id . '_' . $name );
        }
        return delete_metadata( $type, (int) $id, $name );
    }

    /** @return array{0:string,1:int|string} */
    private static function current(): array {
        $post_id = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;
        if ( $post_id > 0 && ( in_the_loop() || ! function_exists( 'get_queried_object' ) || ! is_object( get_queried_object() ) || get_queried_object() instanceof \WP_Post ) ) {
            return [ 'post', $post_id ];
        }
        $object = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
        if ( $object instanceof \WP_Term ) {
            return [ 'term', (int) $object->term_id ];
        }
        if ( $object instanceof \WP_User ) {
            return [ 'user', (int) $object->ID ];
        }
        if ( $object instanceof \WP_Post ) {
            return [ 'post', (int) $object->ID ];
        }
        return [ 'post', $post_id ];
    }
}
