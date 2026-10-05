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
