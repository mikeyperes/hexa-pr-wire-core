<?php
declare(strict_types=1);

// Site search returns only what any visitor may see and search engines may list.

function get_option( string $name ) {
    return [ 'page_on_front' => 12, 'page_for_posts' => 0 ][ $name ] ?? false;
}

require_once dirname( __DIR__ ) . '/src/SearchQuery/SearchQueryEngine.php';

final class PublicOnlyQuery {
    public array $vars = [ 'post__not_in' => [ 5 ], 'meta_query' => [ [ 'key' => 'kept', 'value' => '1' ] ] ];
    public function get( string $key ) { return $this->vars[ $key ] ?? ''; }
    public function set( string $key, $value ): void { $this->vars[ $key ] = $value; }
}

$query = new PublicOnlyQuery();
\Hexa\PluginCore\SearchQuery\SearchQueryEngine::restrict_to_public( $query );
$fail = static function ( string $message ): void { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); };

'publish' === $query->vars['post_status'] || $fail( 'published only' );
false === $query->vars['has_password'] || $fail( 'no password-protected items' );
[ 5, 12 ] === $query->vars['post__not_in'] || $fail( 'front page excluded and existing exclusions kept' );
$meta = $query->vars['meta_query'];
'kept' === ( $meta[0]['key'] ?? '' ) || $fail( 'existing meta query kept' );
'"noindex"' === ( $meta[1][1]['value'] ?? '' ) && 'NOT LIKE' === ( $meta[1][1]['compare'] ?? '' ) || $fail( 'Rank Math noindex excluded' );
'_yoast_wpseo_meta-robots-noindex' === ( $meta[2][1]['key'] ?? '' ) || $fail( 'Yoast noindex excluded' );

echo "PASS: site search is public-only (published, no password, no noindex, no front page).\n";
