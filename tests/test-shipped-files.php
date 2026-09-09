<?php
/**
 * Harness: what a merchant actually downloads.
 *
 * The WordPress.org listing banners and the README screenshots lived under
 * /assets and shipped to every merchant by both routes. They are presentation:
 * no PHP, CSS or JavaScript in the plugin reads anything under that directory.
 * They were roughly seven tenths of the download.
 *
 * tools/check-archive-parity.php already proves the two routes agree with each
 * other. It cannot notice both routes agreeing to ship a megabyte and a half of
 * pictures again, which is what this file is for.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-64s got %-22s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$root = dirname( __DIR__ );

echo "\nTHE PRESENTATION IMAGES DO NOT SHIP\n" . str_repeat( '=', 112 ) . "\n";

// Route one: the zip someone downloads and uploads by hand.
$manifest = file_get_contents( $root . '/tools/build-manifest.php' );
preg_match( '/function tcgiant_build_include_dirs\(\)\s*\{\s*return array\(([^)]*)\)/', $manifest, $m );
$dirs = array_map(
	function ( $d ) {
		return trim( $d, " \t\n'\"" );
	},
	array_filter( explode( ',', $m[1] ?? '' ) )
);

check( 'the build ships three directories', $dirs, array( 'admin', 'includes', 'languages' ) );
check( '  and assets is not one of them', in_array( 'assets', $dirs, true ), false );

// Route two: the source archive the updater downloads, which is a blocklist -
// anything not excluded ships.
$attributes = file_get_contents( $root . '/.gitattributes' );
check( 'the source archive excludes assets', 1 === preg_match( '/^\/assets\s+export-ignore/m', $attributes ), true );

// The files are still in the repository: export-ignore does not untrack them,
// and the README displays them from these paths.
check( 'the banner is still in the repository for the README',
	file_exists( $root . '/assets/src/img/banner-1544x500px.png' ), true );

$readme = file_exists( $root . '/README.md' ) ? file_get_contents( $root . '/README.md' ) : '';
check( '  and the README still points at that path',
	'' === $readme || false !== strpos( $readme, 'assets/src/img/' ), true );

echo "\nNOTHING IN THE PLUGIN READS THEM\n" . str_repeat( '=', 112 ) . "\n";

// If this ever stops being true, the images have to go back into the build.
$sources = array_merge(
	glob( $root . '/includes/*.php' ) ?: array(),
	glob( $root . '/admin/*.php' ) ?: array(),
	glob( $root . '/admin/views/*.php' ) ?: array(),
	glob( $root . '/admin/assets/css/*.css' ) ?: array(),
	array( $root . '/tcgiant-sync.php' )
);

$readers = array();
foreach ( $sources as $file ) {
	$body = (string) file_get_contents( $file );
	if ( false !== strpos( $body, 'assets/src' ) ) {
		$readers[] = basename( $file );
	}
}

check( 'no shipped file references assets/src', $readers, array() );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
