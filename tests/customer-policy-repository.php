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
$assert( 'unrestricted' === $repository->publication_access_mode( 7 ), 'legacy customers default to unrestricted publication access' );
$assert( ! $repository->publication_access_configured( 7 ), 'unrestricted customers do not enforce an allowlist' );
$repository->save( 7, 'full_access', [ 11 ], [ 11 => '0', 12 => '199.995', 99 => '50', 13 => '-1' ] );
$assert( ! $repository->publication_access_configured( 7 ), 'ordinary saves do not activate publication restrictions' );
$assert( [ 11 ] === $repository->allowed_publications( 7 ), 'unrestricted saves preserve validated publication selections' );
$assert( [ 11 => '0.00', 12 => '200.00' ] === $repository->publication_prices( 7 ), 'prices are normalized independently of the entitlement list' );
$assert( 'full_access' === $repository->mode( 7 ), 'explicit modes replace the legacy flag' );
$repository->save( 7, 'edit_existing', [ 12, 99 ], [], 'restricted' );
$assert( 'restricted' === $repository->publication_access_mode( 7 ), 'restrictions activate only after an explicit restricted save' );
$assert( $repository->publication_access_configured( 7 ), 'restricted customers enforce the stored allowlist' );
$assert( [ 12 ] === $repository->allowed_publications( 7 ), 'restricted publication selections remain validated' );
$repository->save( 7, 'edit_existing', [ 11 ], [], 'unrestricted' );
$assert( ! $repository->publication_access_configured( 7 ), 'explicit unrestricted mode disables allowlist enforcement' );
$assert( [ 11 ] === $repository->allowed_publications( 7 ), 'unrestricted mode does not erase the saved allowlist' );

fwrite( STDOUT, "Customer policy repository tests passed.\n" );
