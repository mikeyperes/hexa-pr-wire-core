<?php
/** Core REST fields preserve the legacy property and respect WordPress permissions. */

declare( strict_types=1 );

require __DIR__ . '/support/fields.php';

use Hexa\PluginCore\Fields\FieldGroups;
use Hexa\PluginCore\Fields\RestFields;

final class WP_REST_Request extends ArrayObject {
    public function get_url_params(): array { return [ 'id' => 21 ]; }
}
final class WP_REST_Server {}
final class WP_Error {
    public function __construct( public string $code, public string $message, public array $data = [] ) {}
}
final class WP_Post {
    public int $ID = 21;
    public string $post_type = 'organization';
    public string $post_password = '';
}

$GLOBALS['test_post'] = new WP_Post();
$GLOBALS['test_meta'] = [
    'title_field' => 'Sample',
    'size' => '2.5',
    'logo' => '42',
    'details_city' => 'Vancouver',
    'details_region' => 'British Columbia',
    'secret' => 'Internal',
    'disabled' => 'Internal',
];

function get_post( $id ) { return 21 === $id ? $GLOBALS['test_post'] : null; }
function post_password_required( $post ) { return '' !== $post->post_password; }
function wp_is_post_revision( $id ) { return false; }
function post_type_supports( $type, $feature ) { return false; }
function get_page_template_slug( $id ) { return 'default'; }
function get_post_types( $query, $output ) { return [ 'organization' => (object) [ 'name' => 'organization' ] ]; }
function get_taxonomies( $query, $output ) { return []; }
function current_user_can( $capability, $id = 0 ) { return $GLOBALS['test_can_edit'] ?? false; }
function rest_authorization_required_code() { return 401; }
function wp_slash( $value ) { return $value; }
function wp_kses_post_deep( $value ) { return $value; }
function __( $value, $domain = '' ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

function get_post_meta( $id, $key, $single ) { return $GLOBALS['test_meta'][ $key ] ?? null; }
function update_metadata( $kind, $id, $key, $value ) {
    $GLOBALS['test_meta'][ $key ] = $value;
    return true;
}
function delete_metadata( $kind, $id, $key ) {
    unset( $GLOBALS['test_meta'][ $key ] );
    return true;
}

function register_rest_field( $type, $property, $registration ) {
    $GLOBALS['wp_rest_additional_fields'][ $type ][ $property ] = $registration;
}

function rest_validate_request_arg( $value, $request, $field ) { return true; }

function rest_validate_value_from_schema( $value, $schema, $field ) {
    if ( 'number' === $schema['type'][0] && null !== $value && ! is_numeric( $value ) ) {
        return new WP_Error( 'rest_invalid_type', $field );
    }
    return true;
}

function expect_rest( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
    echo "PASS {$message}\n";
}

$location = [ [ [ 'param'=>'post_type', 'operator'=>'==', 'value'=>'organization' ] ] ];
FieldGroups::add( [
    'key'=>'group_public', 'location'=>$location, 'show_in_rest'=>1,
    'fields'=>[
        [ 'name'=>'title_field', 'type'=>'text' ],
        [ 'name'=>'size', 'type'=>'number' ],
        [ 'name'=>'logo', 'type'=>'image' ],
        [ 'name'=>'details', 'type'=>'group', 'sub_fields'=>[
            [ 'name'=>'city', 'type'=>'text' ],
            [ 'name'=>'region', 'type'=>'text' ],
        ] ],
        [ 'name'=>'items', 'type'=>'repeater', 'sub_fields'=>[ [ 'name'=>'item', 'type'=>'text' ] ] ],
        [ 'name'=>'layout', 'type'=>'tab' ],
    ],
] );
FieldGroups::add( [
    'key'=>'group_private', 'location'=>$location, 'show_in_rest'=>0,
    'fields'=>[ [ 'name'=>'secret', 'type'=>'text' ] ],
] );
FieldGroups::add( [
    'key'=>'group_disabled', 'location'=>$location, 'show_in_rest'=>1, 'active'=>false,
    'fields'=>[ [ 'name'=>'disabled', 'type'=>'text' ] ],
] );
register_rest_field( 'organization', 'acf', [
    'get_callback' => static fn() => [ 'external' => 'Existing' ],
    'schema' => [ 'properties' => [ 'external' => [ 'type'=>'string' ] ] ],
    'update_callback' => static function( $input ): bool {
        $GLOBALS['external_update'] = $input;
        return true;
    },
] );
RestFields::register();
RestFields::register();
expect_rest( 1 === count( $GLOBALS['hexa_test_hooks']['rest_api_init'] ), 'Core registers REST once across hosts' );
RestFields::register_fields();
$registration = $GLOBALS['wp_rest_additional_fields']['organization']['acf'];
$read = $registration['get_callback'];
$update = $registration['update_callback'];
$public = new WP_REST_Request( [ 'context'=>'view' ] );
$editor = new WP_REST_Request( [ 'context'=>'edit' ] );
$object = [ 'id'=>21 ];
$visible = [
    'external'=>'Existing', 'title_field'=>'Sample', 'size'=>2.5, 'logo'=>42,
    'details'=>[ 'city'=>'Vancouver', 'region'=>'British Columbia' ], 'items'=>null,
];
expect_rest( $visible === $read( $object, 'acf', $public, 'organization' ), 'Public response preserves the legacy field shapes and the existing REST property' );
expect_rest( array_keys( $visible ) === array_keys( $registration['schema']['properties'] ), 'REST schema excludes hidden and non-data controls' );
expect_rest( [ 'external'=>'Existing' ] === $read( $object, 'acf', $editor, 'organization' ), 'Anonymous edit context exposes no Core field values' );

$GLOBALS['test_post']->post_password = 'fixture-password';
expect_rest( [ 'external'=>'Existing' ] === $read( $object, 'acf', $public, 'organization' ), 'Protected posts keep Core fields private before the password is supplied' );
$public['password'] = 'fixture-password';
expect_rest( $visible === $read( $object, 'acf', $public, 'organization' ), 'The post password unlocks the same public fields' );
$GLOBALS['test_post']->post_password = '';

$before = $GLOBALS['test_meta'];
$unauthorized = $update( [ 'size'=>7 ], $GLOBALS['test_post'], 'acf', $editor, 'organization' );
expect_rest( is_wp_error( $unauthorized ) && $unauthorized->data['status'] === 401 && $before === $GLOBALS['test_meta'], 'Unauthorized REST updates write nothing' );
$GLOBALS['test_can_edit'] = true;
expect_rest( $visible === $read( $object, 'acf', $editor, 'organization' ), 'An authorized editor sees the legacy fields' );
$precheck = $registration['schema']['arg_options']['validate_callback'];
expect_rest( is_wp_error( $precheck( [ 'size'=>'invalid' ], $editor, 'acf' ) ) && $before === $GLOBALS['test_meta'], 'The WordPress REST argument validates the field before saving a post' );
$invalid = $update( [ 'title_field'=>'Unwritten', 'size'=>'invalid' ], $GLOBALS['test_post'], 'acf', $editor, 'organization' );
expect_rest( is_wp_error( $invalid ) && $before === $GLOBALS['test_meta'], 'An invalid value rejects the whole Core field update' );
expect_rest( true === $update( [
    'title_field'=>'Updated', 'size'=>7, 'details'=>[ 'city'=>'Seattle' ], 'external'=>'Other',
], $GLOBALS['test_post'], 'acf', $editor, 'organization' ), 'Authorized REST updates use existing field storage' );
expect_rest( $GLOBALS['test_meta']['size'] === 7
    && 'Seattle' === $GLOBALS['test_meta']['details_city']
    && 'British Columbia' === $GLOBALS['test_meta']['details_region'], 'A partial REST group update preserves omitted child fields' );
expect_rest( [ 'external'=>'Other' ] === $GLOBALS['external_update'], 'An existing REST provider receives only its unhandled fields' );
expect_rest( true === $update( [ 'secret'=>'Forbidden' ], $GLOBALS['test_post'], 'acf', $editor, 'organization' )
    && 'Internal' === $GLOBALS['test_meta']['secret'], 'A hidden field cannot be changed through the Core REST provider' );
expect_rest( true === $update( [ 'size'=>null ], $GLOBALS['test_post'], 'acf', $editor, 'organization' )
    && ! isset( $GLOBALS['test_meta']['size'] ), 'A null write clears the stored field through Core' );
