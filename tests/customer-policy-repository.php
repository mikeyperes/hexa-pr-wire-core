<?php

$GLOBALS['hprwc_user_meta'] = [
	7 => [ 'free_publishing' => '1' ],
];

class WP_User {
	public function __construct( public int $ID, public array $roles ) {}
}
function get_userdata( int $user_id ): WP_User|false { return 7 === $user_id ? new WP_User( 7, [ 'hexa_pr_wire_user' ] ) : false; }
function get_user_meta( int $user_id, string $key, bool $single = false ): mixed { return $GLOBALS['hprwc_user_meta'][ $user_id ][ $key ] ?? ''; }
function metadata_exists( string $type, int $user_id, string $key ): bool { return 'user' === $type && array_key_exists( $key, $GLOBALS['hprwc_user_meta'][ $user_id ] ?? [] ); }
function update_user_meta( int $user_id, string $key, mixed $value ): bool { $GLOBALS['hprwc_user_meta'][ $user_id ][ $key ] = $value; return true; }
function get_terms( array $args ): array { return array_values( array_intersect( [ 11, 12, 13 ], array_map( 'intval', (array) ( $args['include'] ?? [] ) ) ) ); }
function is_wp_error( mixed $value ): bool { return false; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ?: '' ); }

require_once dirname( __DIR__ ) . '/src/Contracts/CustomerPolicyRepository.php';
require_once dirname( __DIR__ ) . '/vendor/hexa/plugin-core/src/CoreContracts/ModuleInterface.php';
require_once dirname( __DIR__ ) . '/src/Contracts/Module.php';
require_once dirname( __DIR__ ) . '/src/Customer/SubmissionMode.php';
require_once dirname( __DIR__ ) . '/src/Customer/RoleLifecycle.php';
require_once dirname( __DIR__ ) . '/src/Infrastructure/WordPress/WordPressCustomerPolicyRepository.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};
$repository = new \HexaPrWire\Core\Infrastructure\WordPress\WordPressCustomerPolicyRepository();
$assert( $repository->is_customer( 7 ), 'the customer role is recognized' );
$assert( 'create_publish' === $repository->mode( 7 ), 'legacy free-publishing metadata maps to the publish mode' );
$assert( ! $repository->publication_access_configured( 7 ), 'legacy customers remain unconfigured until an administrator saves publication access' );
$repository->save( 7, 'full_access', [ 11 ], [ 11 => '0', 12 => '199.995', 99 => '50', 13 => '-1' ] );
$assert( $repository->publication_access_configured( 7 ), 'saving a customer profile makes publication access explicit' );
$assert( [ 11 ] === $repository->allowed_publications( 7 ), 'allowed publications are validated' );
$assert( [ 11 => '0.00', 12 => '200.00' ] === $repository->publication_prices( 7 ), 'prices are normalized independently of the entitlement list' );
$assert( 'full_access' === $repository->mode( 7 ), 'explicit modes replace the legacy flag' );

fwrite( STDOUT, "Customer policy repository tests passed.\n" );
