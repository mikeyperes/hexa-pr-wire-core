<?php

function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ?: '' ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }

require_once dirname( __DIR__ ) . '/vendor/hexa/plugin-core/src/CoreContracts/ModuleInterface.php';
require_once dirname( __DIR__ ) . '/vendor/hexa/plugin-core/src/AcfFieldFactory/AcfFieldFactory.php';
require_once dirname( __DIR__ ) . '/vendor/hexa/plugin-core/src/FieldStructures/AcfFieldGroupSettingsStore.php';
require_once dirname( __DIR__ ) . '/vendor/hexa/plugin-core/src/FieldStructures/AcfFieldGroupRegistry.php';
require_once dirname( __DIR__ ) . '/src/Contracts/Module.php';
require_once dirname( __DIR__ ) . '/src/Contracts/CustomerPolicyRepository.php';
require_once dirname( __DIR__ ) . '/src/Customer/SubmissionMode.php';
require_once dirname( __DIR__ ) . '/src/Customer/AccessPolicy.php';
require_once dirname( __DIR__ ) . '/src/Fields/ReleaseLocation.php';
require_once dirname( __DIR__ ) . '/src/Fields/FieldGroups.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};
$repo = new class implements \HexaPrWire\Core\Contracts\CustomerPolicyRepository {
	public function is_customer( int $user_id ): bool { return false; }
	public function mode( int $user_id ): string { return 'edit_existing'; }
	public function publication_access_configured( int $user_id ): bool { return false; }
	public function allowed_publications( int $user_id ): array { return []; }
	public function publication_prices( int $user_id ): array { return []; }
	public function save( int $user_id, string $mode, array $publication_ids, array $prices = [] ): void {}
};
$groups = new \HexaPrWire\Core\Fields\FieldGroups( new \HexaPrWire\Core\Fields\ReleaseLocation( new \HexaPrWire\Core\Customer\AccessPolicy( $repo ) ) );
$definitions = [ $groups->publication_group(), $groups->taxonomy_group(), $groups->public_release_group(), $groups->checklist_group(), $groups->private_release_group() ];

$keys = [];
$names = [];
$walk = static function ( array $fields ) use ( &$walk, &$keys, &$names ): void {
	foreach ( $fields as $field ) {
		$keys[] = (string) ( $field['key'] ?? '' );
		$names[] = (string) ( $field['name'] ?? '' );
		if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			$walk( $field['sub_fields'] );
		}
	}
};
foreach ( $definitions as $definition ) {
	$walk( $definition['fields'] );
}

$expected_groups = [ 'group_6506a8003237a', 'group_69326f80f12ff', 'group_64a72abaeeff0', 'group_64a6e0e504f9e', 'group_63a0418e58839' ];
$assert( $expected_groups === array_column( $definitions, 'key' ), 'the five approved ACF groups retain their stable keys' );
$required_names = [ 'dr', 'da', 'tf', 'status', 'url_press_release_prefix', 'publication', 'press_release_date', 'press_release_location', 'contact_information', 'link_output', 'checklist', 'notes_for_editorial_team', 'submitted_by', 'disclaimer', 'link_output_html', 'canonical' ];
foreach ( $required_names as $name ) {
	$assert( in_array( $name, $names, true ), "required field {$name} is registered" );
}
foreach ( [ 'password', 'invoice_id', 'billing', 'price_standard_release', 'custom_services', 'allow_credit_card', 'podcast', 'guest_name' ] as $excluded ) {
	$assert( ! in_array( $excluded, $names, true ), "excluded field {$excluded} is not owned by Core" );
}
$assert( count( $keys ) === count( array_unique( $keys ) ), 'ACF field keys are unique' );
$assert( ! in_array( '', $keys, true ), 'every ACF field has an explicit stable key' );

fwrite( STDOUT, "Field contract tests passed.\n" );
