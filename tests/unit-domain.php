<?php

function sanitize_key( mixed $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ?: '' );
}
function sanitize_title( mixed $value ): string {
	return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ) ?: '' ), '-' );
}
function absint( mixed $value ): int {
	return abs( (int) $value );
}
function esc_url_raw( mixed $value ): string {
	return filter_var( (string) $value, FILTER_VALIDATE_URL ) ? (string) $value : '';
}
function wp_http_validate_url( mixed $value ): string|false {
	$value = (string) $value;
	return preg_match( '#^https?://#i', $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ? $value : false;
}
function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}
function trailingslashit( string $value ): string {
	return untrailingslashit( $value ) . '/';
}

class WP_Post {
	public function __construct( public int $ID, public int $post_author, public string $post_type = 'post' ) {}
}

require_once dirname( __DIR__ ) . '/src/Contracts/CustomerPolicyRepository.php';
require_once dirname( __DIR__ ) . '/src/Customer/SubmissionMode.php';
require_once dirname( __DIR__ ) . '/src/Customer/AccessPolicy.php';
require_once dirname( __DIR__ ) . '/src/Domain/Publication/PublicationUrl.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$repository = new class implements \HexaPrWire\Core\Contracts\CustomerPolicyRepository {
	public array $modes = [ 7 => 'edit_existing', 8 => 'create_pending', 9 => 'create_publish', 10 => 'full_access' ];
	public array $allowed = [ 7 => [ 11 ], 8 => [ 11, 12 ], 9 => [ 12 ], 10 => [] ];
	public array $configured = [ 7 => true, 8 => true, 9 => true, 10 => true ];
	public function is_customer( int $user_id ): bool { return isset( $this->modes[ $user_id ] ); }
	public function mode( int $user_id ): string { return $this->modes[ $user_id ] ?? 'edit_existing'; }
	public function publication_access_configured( int $user_id ): bool { return $this->configured[ $user_id ] ?? false; }
	public function allowed_publications( int $user_id ): array { return $this->allowed[ $user_id ] ?? []; }
	public function publication_prices( int $user_id ): array { return []; }
	public function save( int $user_id, string $mode, array $publication_ids, array $prices = [] ): void {}
};

$policy = new \HexaPrWire\Core\Customer\AccessPolicy( $repository );
$assert( ! $policy->can_create( 7 ), 'edit-existing customers cannot create posts' );
$assert( $policy->can_create( 8 ) && ! $policy->can_publish( 8 ), 'pending customers can create but not publish' );
$assert( $policy->can_create( 9 ) && $policy->can_publish( 9 ), 'publisher customers can create and publish' );
$assert( $policy->can_use_publication( 7, 11 ) && ! $policy->can_use_publication( 7, 12 ), 'publication entitlements are enforced' );
$assert( $policy->can_use_publication( 10, 999 ), 'full-access customers can use every publication' );
$assert( $policy->can_edit_post( 7, new WP_Post( 1, 7 ) ), 'customers can edit their own releases' );
$assert( ! $policy->can_edit_post( 7, new WP_Post( 2, 8 ) ), 'constrained customers cannot edit another customer release' );
$assert( $policy->can_edit_post( 10, new WP_Post( 3, 8 ) ), 'full-access customers can edit other releases' );
$assert( [ 11 ] === $policy->filter_publications( 7, [ 11, 12, 11, 0 ] ), 'term filters remove unauthorized and invalid IDs' );
$assert( [ 11, 12 ] === $policy->filter_publications_for_existing_post( 7, [ 11, 12, 13 ], [ 12 ] ), 'existing post assignments are preserved without allowing new unauthorized terms' );
$repository->configured[7] = false;
$assert( $policy->can_use_publication( 7, 999 ), 'legacy unconfigured customers retain publication access' );

$urls = new \HexaPrWire\Core\Domain\Publication\PublicationUrl();
$assert( 'https://example.com/press-release/a-release/' === $urls->for_slug( [ 'prefix' => 'https://example.com/press-release/' ], 'A Release' ), 'publication URLs are normalized deterministically' );
$assert( '' === $urls->for_slug( [ 'prefix' => 'javascript:alert(1)' ], 'release' ), 'unsafe publication URL prefixes are rejected' );

fwrite( STDOUT, "Domain tests passed.\n" );
