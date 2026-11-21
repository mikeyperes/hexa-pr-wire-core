<?php
/** Core Fields resolve, save, render and iterate values with ACF present. */

declare(strict_types=1);

$GLOBALS['options'] = [];
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['filters'][ $hook ][] = $callback; return true; }
function apply_filters( $hook, $value, ...$args ) { foreach ( $GLOBALS['filters'][ $hook ] ?? [] as $cb ) { $value = $cb( $value, ...$args ); } return $value; }
function do_action( $hook, ...$args ) {}
function did_action( $hook ) { return 1; }
function doing_action( $hook = null ) { return false; }
function is_admin() { return false; }
function wp_enqueue_script( $handle ) {}
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) ); }
function get_field( ...$args ) { throw new RuntimeException( 'ACF read called' ); }
function update_field( ...$args ) { throw new RuntimeException( 'ACF write called' ); }
function acf_add_local_field_group( ...$args ) { throw new RuntimeException( 'ACF registration called' ); }
function acf_add_options_page( ...$args ) { throw new RuntimeException( 'ACF options page called' ); }
function acf_form( ...$args ) { throw new RuntimeException( 'ACF form called' ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_url( $v ) { return (string) $v; }
function __( $v, $domain = '' ) { return (string) $v; }
function esc_html__( $v, $domain = '' ) { return esc_html( $v ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_die( $message, ...$args ) { throw new InvalidArgumentException( $message ); }
function wp_kses_post( $v ) { return (string) $v; }
function wpautop( $v ) { return (string) $v; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['options'] ) ? $GLOBALS['options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
function delete_option( $name ) { $existed = array_key_exists( $name, $GLOBALS['options'] ); unset( $GLOBALS['options'][ $name ] ); return $existed; }

// ACF admin-screen groups, as stored in wp_posts.
$GLOBALS['wpdb'] = new class() {
	public string $posts = 'wp_posts';
	public string $options = 'wp_options';
	public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	public function prepare( string $sql, ...$args ): string { return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
	public function get_results( string $sql ): array {
		if ( str_contains( $sql, 'wp_options' ) ) {
			// options stored in insertion order; the test only needs `options_` / `_options_` rows.
			$rows = [];
			foreach ( $GLOBALS['options'] as $name => $value ) {
				if ( str_starts_with( $name, 'options_' ) || str_starts_with( $name, '_options_' ) ) {
					$rows[] = (object) [ 'option_name' => $name, 'option_value' => $value ];
				}
			}
			return $rows;
		}
		$row = static fn( int $id, string $type, string $status, string $title, string $key, string $name, array $settings, int $parent ): object => (object) [ 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => $title, 'post_name' => $key, 'post_excerpt' => $name, 'post_content' => serialize( $settings ), 'post_parent' => $parent, 'menu_order' => 0 ];
		return [
			$row( 10, 'acf-field-group', 'publish', 'Contacts', 'group_db_contacts', '', [ 'location' => [ [ [ 'param' => 'options_page', 'operator' => '==', 'value' => '*' ] ] ] ], 0 ),
			$row( 11, 'acf-field', 'publish', 'Emails', 'field_db_emails', 'db_emails', [ 'type' => 'repeater' ], 10 ),
			$row( 12, 'acf-field', 'publish', 'Email', 'field_db_email', 'db_email', [ 'type' => 'email' ], 11 ),
			$row( 20, 'acf-field-group', 'acf-disabled', 'Old', 'group_db_old', '', [], 0 ),
			$row( 21, 'acf-field', 'publish', 'Note', 'field_db_note', 'db_note', [ 'type' => 'text' ], 20 ),
			$row( 30, 'acf-field-group', 'trash', 'Binned', 'group_db_binned__trashed', '', [], 0 ),
			$row( 31, 'acf-field', 'publish', 'Greeting', 'field_db_greeting', 'db_greeting', [ 'type' => 'text' ], 30 ),
		];
	}
};

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'Hexa\\PluginCore\\Fields\\';
	if ( str_starts_with( $class, $prefix ) ) {
		require_once dirname( __DIR__ ) . '/src/Fields/' . substr( $class, strlen( $prefix ) ) . '.php';
	}
} );

use Hexa\PluginCore\Fields\Acf;
use Hexa\PluginCore\Fields\Field;
use Hexa\PluginCore\Fields\FieldGroups;
use Hexa\PluginCore\Fields\Form;
use Hexa\PluginCore\Fields\Renderer;

$fail = static function ( string $message ): void {
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
};

! Acf::active() && 'native' === Acf::mode() || $fail( 'Core owns fields when ACF is present.' );
\Hexa\PluginCore\Fields\OptionsPages::add( [ 'menu_slug' => 'core-test', 'page_title' => 'Core options' ] );
isset( \Hexa\PluginCore\Fields\OptionsPages::pages()['core-test'] ) || $fail( 'Core registers the options page.' );
\Hexa\PluginCore\Fields\Hooks::on( 'save_post', static fn() => null );
empty( $GLOBALS['filters']['acf/save_post'] ) && ! empty( $GLOBALS['filters']['hexa_fields/save_post'] ) || $fail( 'Field save hooks are isolated from ACF.' );

FieldGroups::add( [
	'title'    => 'Publication Profile',
	'fields'   => [ [ 'name' => 'mission', 'type' => 'textarea' ], [ 'name' => 'listed', 'type' => 'true_false' ] ],
	'location' => [ [ [ 'param' => 'options_page', 'operator' => '==', 'value' => '*' ] ] ],
] );
null !== FieldGroups::get_group( 'group_publication_profile' ) || $fail( 'A key-less group takes group_<slug of title>, as ACF does.' );
null !== FieldGroups::get_field( 'field_mission' ) || $fail( 'A key-less field takes field_<name>, as ACF does.' );

null === Field::get( 'mission', 'option' ) || $fail( 'A never-saved name returns null, as get_field() does.' );
false === Field::object( 'mission', 'option' ) || $fail( 'A never-saved name has no field object, as get_field_object() does.' );
false === Field::delete( 'mission', 'option' ) || $fail( 'delete_field() on a never-saved name returns false.' );
Field::update( 'mission', 'Report the news.', 'option' ) || $fail( 'update_field() matches a registered name.' );
'field_mission' === get_option( '_options_mission' ) || $fail( 'update_field() stores the `_name` reference.' );
'Report the news.' === Field::get( 'mission', 'option' ) || $fail( 'A saved name resolves through its reference.' );
Field::update( 'listed', 1, 'option' );
true === Field::get( 'listed', 'option' ) || $fail( 'Referenced fields are formatted (true_false returns bool).' );
'Report the news.' === Field::get( 'field_mission', 'option' ) || $fail( 'A key selector resolves directly.' );
update_option( 'options_raw_only', 'raw' );
'raw' === Field::get( 'raw_only', 'option' ) || $fail( 'An unknown name returns the raw stored value.' );
Field::delete( 'mission', 'option' ) || $fail( 'delete_field() erases a referenced field.' );
null === Field::get( 'mission', 'option' ) || $fail( 'A deleted field reads as never saved.' );

ob_start();
\Hexa\PluginCore\Fields\Form::field( [ 'key' => 'field_audio_url', 'name' => 'audio_url', 'label' => 'Audio', 'type' => 'text', 'value' => 'https://example.test/a.mp3' ] );
$html = (string) ob_get_clean();
str_contains( $html, 'name="acf[field_audio_url]"' ) && str_contains( $html, 'https://example.test/a.mp3' ) || $fail( 'Form::field() renders one field under acf[<key>] with its value, as acf_render_field_wrap() does.' );

// Missing values take the type default, as acf_get_value() does.
'' === Field::get( 'field_mission', 'option' ) || $fail( 'A missing textarea read by key returns its type default ("").' );

// Groups created in the ACF admin screen resolve from the database.
$keys = array_map( static fn( array $g ): string => (string) $g['key'], FieldGroups::all() );
in_array( 'group_db_contacts', $keys, true ) && in_array( 'group_db_old', $keys, true ) || $fail( 'FieldGroups::all() includes database groups, active and disabled, as acf_get_field_groups() does.' );
update_option( 'options_db_emails', 1 );
update_option( '_options_db_emails', 'field_db_emails' );
update_option( 'options_db_emails_0_db_email', 'desk@example.test' );
update_option( '_options_db_emails_0_db_email', 'field_db_email' );
[ [ 'db_email' => 'desk@example.test' ] ] === Field::get( 'db_emails', 'option' ) || $fail( 'A database repeater returns its rows, not the stored row count.' );
Field::have_rows( 'db_emails', 'option' ) || $fail( 'An existing repeater starts a Core row loop.' );
[ 'field_db_email' => 'desk@example.test' ] === Field::the_row() || $fail( 'Unformatted rows keep the stored subfield keys.' );
[ 'db_email' => 'desk@example.test' ] === Field::get_row( true ) || $fail( 'Formatted rows use subfield names.' );
'desk@example.test' === Field::get_sub_field( 'field_db_email', false ) || $fail( 'Core resolves a raw subfield by key.' );
! Field::have_rows( 'db_emails', 'option' ) || $fail( 'The Core loop ends after the last row.' );
update_option( '_options_db_note', 'field_db_note' );
'' === Field::get( 'db_note', 'option' ) || $fail( 'A field of a disabled database group still resolves, as in ACF.' );
FieldGroups::add( [ 'key' => 'group_db_old', 'title' => 'Code wins', 'fields' => [ [ 'key' => 'field_code_only', 'name' => 'code_only' ] ] ] );
'Code wins' === ( FieldGroups::get_group( 'group_db_old' )['title'] ?? '' ) || $fail( 'A code-registered group overrides the database group with the same key.' );

// Field lists follow get_field_objects(): the reference must resolve to a field of that same name.
update_option( 'options_alias', 'x' );
update_option( '_options_alias', 'field_db_note' );
$listed = array_keys( (array) Field::all( 'option' ) );
in_array( 'db_emails', $listed, true ) && ! in_array( 'alias', $listed, true ) && ! in_array( 'db_emails_0_db_email', $listed, true ) || $fail( 'Field::all() lists referenced fields by name and leaves out sub-field and mismatched references.' );
null === Field::get( '', 'option' ) || $fail( 'An empty selector returns null, as get_field() does.' );

// Names resolve to sub fields too (as ACF's field store does), after top-level fields.
FieldGroups::add( [ 'key' => 'group_loops', 'title' => 'Loops', 'fields' => [ [ 'key' => 'field_loops', 'name' => 'loops', 'type' => 'group', 'sub_fields' => [ [ 'key' => 'field_loop_author', 'name' => 'loop_author', 'type' => 'text' ] ] ] ] ] );
'field_loop_author' === ( FieldGroups::get_field( 'loop_author' )['key'] ?? '' ) || $fail( 'A sub field resolves by name.' );
update_option( 'options_loop_author', 'Jane' );
update_option( '_options_loop_author', 'loop_author' );
'Jane' === Field::get( 'loop_author', 'option' ) || $fail( 'A reference that names a field resolves, as acf_get_meta_field() does.' );

// Location rules pass through ACF's location filters, as acf_match_location_rule() does.
FieldGroups::add( [ 'key' => 'group_hidden', 'title' => 'Hidden', 'fields' => [], 'location' => [ [ [ 'param' => 'options_page', 'operator' => '==', 'value' => '*' ] ] ] ] );
$shown = static fn(): array => array_map( static fn( array $g ): string => $g['key'], FieldGroups::for_screen( [ 'options_page' => '*' ] ) );
in_array( 'group_hidden', $shown(), true ) || $fail( 'The built-in options_page rule matches.' );
\Hexa\PluginCore\Fields\Hooks::on( 'location/match_rule', static fn( bool $match, array $rule, array $screen, array $group ): bool => 'group_hidden' === ( $group['key'] ?? '' ) ? false : $match, 20, 4 );
! in_array( 'group_hidden', $shown(), true ) || $fail( 'A location/match_rule filter can hide a group that its built-in rule matches.' );
\Hexa\PluginCore\Fields\Hooks::on( 'location/rule_match/is_desk', static fn( bool $match, array $rule ): bool => 'yes' === $rule['value'], 10, 4 );
FieldGroups::add( [ 'key' => 'group_desk', 'title' => 'Desk', 'fields' => [], 'location' => [ [ [ 'param' => 'is_desk', 'operator' => '==', 'value' => 'yes' ] ] ] ] );
1 === count( array_filter( FieldGroups::for_screen( [] ), static fn( array $g ): bool => 'group_desk' === $g['key'] ) ) || $fail( 'A host-defined rule decides through location/rule_match/<param>.' );

// A trashed group is not listed, but its published fields still resolve, as acf_get_field() finds them.
! in_array( 'group_db_binned__trashed', array_map( static fn( array $g ): string => (string) $g['key'], FieldGroups::all() ), true ) || $fail( 'A trashed database group is not listed.' );
'db_greeting' === ( FieldGroups::get_field( 'field_db_greeting' )['name'] ?? '' ) || $fail( 'A field of a trashed database group still resolves by key.' );

$map = [ 'key' => 'field_editor_location', 'name' => 'editor_location', 'type' => 'google_map' ];
$source = [ 'key' => 'field_editor_source', 'name' => 'editor_source', 'label' => 'Original title', 'type' => 'text' ];
FieldGroups::add( [ 'key' => 'group_editor_source', 'fields' => [ $source ] ] );
FieldGroups::add( [
	'key' => 'group_editor_types',
	'fields' => [
		[
			'key' => 'field_editor_content', 'name' => 'editor_content', 'type' => 'flexible_content',
			'layouts' => [
				[ 'name' => 'intro', 'label' => 'Introduction', 'sub_fields' => [ [ 'name' => 'editor_intro' ] ] ],
				[ 'name' => 'venue', 'label' => 'Venue', 'sub_fields' => [ $map ] ],
			],
		],
		[ 'key' => 'field_editor_copy', 'name' => 'editor_copy', 'type' => 'clone', 'display' => 'group', 'clone' => [ 'group_editor_source' ], 'prefix_name' => 1 ],
		[ 'key' => 'field_editor_shared', 'name' => 'editor_shared', 'type' => 'clone', 'clone' => [ 'field_editor_source', 'field_editor_shared' ] ],
		[ 'key' => 'field_clear_map', 'name' => 'clear_map', 'type' => 'google_map' ],
	],
] );
$editor_group = FieldGroups::get_group( 'group_editor_types' );
$context = [ 'option', 'options' ];
$legacy_location = [ 'address' => 'Old place', 'lat' => '0.0', 'lng' => '10.5', 'place_id' => 'kept', 'zoom' => 15 ];
update_option( 'options_editor_content', [ 'intro', 'venue' ] );
update_option( 'options_editor_content_0_editor_intro', 'Old introduction' );
update_option( 'options_editor_content_1_editor_location', $legacy_location );
update_option( 'options_editor_copy_editor_source', 'Existing grouped value' );
update_option( 'options_editor_source', 'Existing shared value' );
update_option( 'options_clear_map', $legacy_location );

ob_start();
Renderer::fields( $editor_group['fields'], $context );
$html = (string) ob_get_clean();
str_contains( $html, 'type="hidden" name="hexa_fields[field_editor_content][0][acf_fc_layout]" value="intro"' )
	&& str_contains( $html, 'name="hexa_fields[field_editor_content][1][field_editor_location][lat]" value="0.0"' )
	&& str_contains( $html, 'name="hexa_fields[field_editor_copy][field_editor_source]" value="Existing grouped value"' )
	&& str_contains( $html, 'name="hexa_fields[field_editor_shared][field_editor_source]" value="Existing shared value"' )
	|| $fail( 'All three field editors load existing values in their nested controls.' );

Form::save( [ $editor_group ], $context, [
	'field_editor_content' => [
		[ 'acf_fc_layout' => 'venue', '_hexa_row_index' => '1', 'field_editor_location' => [ 'address' => 'Old place', 'lat' => '0', 'lng' => '10.5' ] ],
		[ 'acf_fc_layout' => 'intro', '_hexa_row_index' => '-1', 'field_editor_intro' => '<b>New introduction</b>' ],
		'_hexa_present' => '1',
	],
	'field_editor_copy' => [ 'field_editor_source' => 'Edited grouped value' ],
	'field_editor_shared' => [ 'field_editor_source' => 'Edited shared value' ],
	'field_clear_map' => [ 'address' => 'New point', 'lat' => '-17.3', 'lng' => '0' ],
] );
[ 'venue', 'intro' ] === get_option( 'options_editor_content' )
	&& $legacy_location === get_option( 'options_editor_content_0_editor_location' )
	&& 'field_editor_location' === get_option( '_options_editor_content_0_editor_location' )
	&& false === get_option( 'options_editor_content_0_editor_intro' )
	&& 'New introduction' === get_option( 'options_editor_content_1_editor_intro' )
	&& 'field_editor_intro' === get_option( '_options_editor_content_1_editor_intro' )
	|| $fail( 'Moving and replacing layout rows keeps ordered layout names, child references and unchanged map attributes.' );
'Edited grouped value' === Field::get( 'editor_copy_editor_source', 'option' )
	&& 'Edited shared value' === Field::get( 'editor_source', 'option' )
	&& 'field_editor_source' === get_option( '_options_editor_copy_editor_source' )
	&& 'field_editor_source' === get_option( '_options_editor_source' )
	|| $fail( 'Group and direct-field clones save under their original metadata names and references.' );
[ 'address' => 'New point', 'lat' => -17.3, 'lng' => 0.0 ] === Field::get( 'clear_map', 'option' )
	|| $fail( 'An edited map keeps the location array and accepts zero coordinates.' );

Form::save( [ $editor_group ], $context, [
	'field_editor_content' => [ '_hexa_present' => '1' ],
	'field_clear_map' => [ 'address' => '', 'lat' => '', 'lng' => '' ],
] );
[] === get_option( 'options_editor_content' ) && false === get_option( 'options_editor_content_1_editor_intro' )
	&& false === get_option( 'options_clear_map' )
	|| $fail( 'An editor can clear a map and remove every layout row.' );

update_option( 'options_editor_content', '' );
ob_start();
Renderer::fields( $editor_group['fields'], $context );
$html = (string) ob_get_clean();
str_contains( $html, 'class="button hexa-flexible-add"' ) || $fail( 'Legacy empty flexible content still offers the add-layout control.' );

update_option( 'options_editor_content', [ 'retired_layout' ] );
update_option( 'options_editor_content_0_editor_hidden', 'Preserved legacy data' );
$before = $GLOBALS['options'];
try {
	Form::save( [ $editor_group ], $context, [ 'field_editor_content' => [ '_hexa_present' => '1' ] ] );
	$fail( 'A missing layout cannot be deleted accidentally.' );
} catch ( InvalidArgumentException $error ) {
	$before === $GLOBALS['options'] || $fail( 'Rejected rows leave storage intact.' );
}
try {
	Form::save( [ $editor_group ], $context, [
		'field_editor_copy' => [ 'field_editor_source' => 'Do not save this yet' ],
		'field_clear_map' => [ 'address' => 'Invalid location', 'lat' => '91', 'lng' => '0' ],
	] );
	$fail( 'Map coordinates outside their bounds are rejected.' );
} catch ( InvalidArgumentException $error ) {
	$before === $GLOBALS['options'] || $fail( 'Invalid coordinates do not partially save the form.' );
}

echo "PASS: native Fields read and write existing storage and edit flexible content, clones and maps.\n";
