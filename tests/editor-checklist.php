<?php
/**
 * The "Headings use H2" rule: PHP (EditorChecklist::headings_use_h2) and the
 * live editor script (editor-checklist.js) must give the same answer.
 * Run: php tests/editor-checklist.php
 */
$root = dirname( __DIR__ );
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
};

if ( ! interface_exists( 'Hexa\\PluginCore\\CoreContracts\\ModuleInterface' ) ) {
	eval( 'namespace Hexa\\PluginCore\\CoreContracts; interface ModuleInterface { public function register(): void; }' );
}
require_once $root . '/src/Contracts/Module.php';
require_once $root . '/src/Admin/EditorChecklist.php';

$cases = [
	'no headings at all' => [ '<p>Body only.</p><p>More body.</p>', false ],
	'only H2 headings' => [ '<p>Intro</p><h2>Section</h2><p>x</p><h2 class="a">Next</h2>', true ],
	'H2 plus an H3' => [ '<h2>Section</h2><h3>Sub</h3>', false ],
	'H1 only' => [ '<h1>Title</h1><p>x</p>', false ],
	'H2 plus an H1' => [ '<h1>Title</h1><h2>Section</h2>', false ],
	'uppercase H2' => [ '<H2>Section</H2>', true ],
	'h2-like tag name is not a heading' => [ '<h2x>no</h2x><p>x</p>', false ],
	'H6 only' => [ '<h6>tiny</h6>', false ],
];

$node_cases = [];
foreach ( $cases as $name => [ $html, $expected ] ) {
	$assert( $expected === \HexaPrWire\Core\Admin\EditorChecklist::headings_use_h2( $html ), "PHP rule: {$name}" );
	$node_cases[] = [ $name, $html, $expected ];
}

$script = $root . '/assets/admin/editor-checklist.js';
$runner = 'global.window = {}; global.jQuery = function(){ return { first: function(){ return { length: 0 }; } }; };'
	. 'require(' . json_encode( $script ) . ');'
	. 'const cases = ' . json_encode( $node_cases ) . ';'
	. 'let failed = 0; for (const [name, html, expected] of cases) { const got = window.hprwcHeadingsUseH2(html); if (got !== expected) { failed++; console.log("FAIL: JS rule: " + name); } else { console.log("PASS: JS rule: " + name); } }'
	. 'process.exit(failed ? 1 : 0);';
$output = [];
exec( 'node -e ' . escapeshellarg( $runner ) . ' 2>&1', $output, $code );
echo implode( "\n", $output ), "\n";
$assert( 0 === $code, 'live editor script agrees with PHP on every case' );

// Release Status counts: PHP and the editor script must agree.
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
}
require_once $root . '/src/Admin/ReleaseStatus.php';
$html = '<p>One two three <a href="https://hexaprwire.com/x">in</a> and <a href="/rel">rel</a> and <a href="https://www.example.com/y">out</a> <a href="mailto:a@b.c">m</a></p>'
	. '<h2>A</h2><h3>B</h3><h3>C</h3><p><img src="a.jpg"> four &amp; five</p>';
$php_stats = \HexaPrWire\Core\Admin\ReleaseStatus::stats( $html, 'hexaprwire.com' );
$assert( 2 === $php_stats['links_internal'] && 1 === $php_stats['links_external'] && 1 === $php_stats['h2'] && 2 === $php_stats['h3'] && 1 === $php_stats['images'], 'PHP status counts links, headings and images' );
$runner = 'global.window = {}; global.jQuery = function(x){ if (typeof x === "string" && x.charAt(0) === "<") { return { html: function(h){ return { text: function(){ return h.replace(/<[^>]*>/g, " ").replace(/&amp;/g, "&"); } }; } }; } return { first: function(){ return { length: 0 }; } }; };'
	. 'require(' . json_encode( $script ) . ');'
	. 'process.stdout.write(JSON.stringify(window.hprwcReleaseStats(' . json_encode( $html ) . ', "hexaprwire.com")));';
$js_stats = json_decode( (string) shell_exec( 'node -e ' . escapeshellarg( $runner ) ), true );
foreach ( [ 'images', 'links_internal', 'links_external', 'h2', 'h3' ] as $k ) {
	$assert( isset( $js_stats[ $k ] ) && $js_stats[ $k ] === $php_stats[ $k ], "editor script status count agrees: {$k}" );
}
$assert( $js_stats['words'] === $php_stats['words'], 'editor script word count agrees (' . $php_stats['words'] . ')' );
