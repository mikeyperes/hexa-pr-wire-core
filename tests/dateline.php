<?php
// Automatic dateline is skipped only when the article already opens with it.
$root = dirname( __DIR__ );
$assert = static function ( bool $ok, string $m ): void { if ( ! $ok ) { fwrite( STDERR, "FAIL: {$m}\n" ); exit( 1 ); } echo "PASS: {$m}\n"; };
if ( ! interface_exists( 'Hexa\\PluginCore\\CoreContracts\\ModuleInterface' ) ) {
	eval( 'namespace Hexa\\PluginCore\\CoreContracts; interface ModuleInterface { public function register(): void; }' );
}
require_once $root . '/src/Contracts/Module.php';
require_once $root . '/src/Frontend/PressReleaseContent.php';
$f = [ \HexaPrWire\Core\Frontend\PressReleaseContent::class, 'opens_with_dateline' ];
$loc = 'American Fork, Utah'; $date = 'October 5, 2026';
$assert( $f( '<p><strong>AMERICAN FORK, Utah — October 5, 2026 —</strong> A business milestone…</p>', $loc, $date ), 'uppercase typed dateline counts as a duplicate' );
$assert( $f( '<p>american fork utah, october 5 2026: text</p>', $loc, $date ), 'different punctuation still counts' );
$assert( ! $f( '<p>A business milestone becomes more useful when customers understand it.</p>', $loc, $date ), 'article without a dateline keeps the automatic one' );
$assert( ! $f( '<p>American Fork, Utah — March 1, 2025 — text</p>', $loc, $date ), 'a different date is not a duplicate' );
$assert( ! $f( '<p>' . str_repeat( 'Long opening sentence about the company. ', 10 ) . 'American Fork, Utah — October 5, 2026</p>', $loc, $date ), 'a mention deep in the article does not count' );
