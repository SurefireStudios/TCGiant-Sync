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

echo "\nLISTING TEMPLATES SHIP; THEIR HARNESSES DO NOT\n" . str_repeat( '=', 112 ) . "\n";

// 3.27.8's files, in the zip the build makes. The updater's archive ships
// everything .gitattributes does not exclude, which is all of includes/ and
// admin/.
require_once $root . '/tools/build-manifest.php';
$built = tcgiant_build_file_list( $root );
$new   = array(
	'includes/class-tcgiant-sync-templates.php'            => 'TCGiant_Sync_Templates',
	'includes/class-tcgiant-sync-template-renderer.php'    => 'TCGiant_Sync_Template_Renderer',
	'includes/class-tcgiant-sync-template-sanitiser.php'   => 'TCGiant_Sync_Template_Sanitiser',
	'includes/class-tcgiant-sync-template-policies.php'    => 'TCGiant_Sync_Template_Policies',
	'includes/class-tcgiant-sync-template-admin.php'       => 'TCGiant_Sync_Template_Admin',
	'includes/class-tcgiant-sync-template-starters.php'    => 'TCGiant_Sync_Template_Starters',
	'includes/class-tcgiant-sync-wplister-converter.php'   => 'TCGiant_Sync_WPLister_Converter',
	'admin/views/templates.php'                            => '',
	'admin/views/template-list.php'                        => '',
	'admin/views/template-edit.php'                        => '',
	'admin/views/template-preview.php'                     => '',
	'admin/views/template-preview-panel.php'               => '',
	'admin/views/template-blocks.php'                      => '',
	'admin/views/template-settings.php'                    => '',
	'admin/views/template-guide.php'                       => '',
	'admin/views/template-convert.php'                     => '',
	'admin/assets/js/templates.js'                         => '',
);
check( 'every listing-template file is in the build', array_values( array_diff( array_keys( $new ), $built ) ), array() );

// Each class is found by the plugin's autoloader: TCGiant_Sync_X_Y in
// includes/class-tcgiant-sync-x-y.php.
$unloadable = array();
foreach ( $new as $file => $class ) {
	if ( '' === $class ) {
		continue;
	}
	$expect = 'includes/class-tcgiant-sync-' . preg_replace( '/[^a-z0-9\-]/', '', strtolower( str_replace( '_', '-', substr( $class, strlen( 'TCGiant_Sync_' ) ) ) ) ) . '.php';
	if ( $expect !== $file || 1 !== preg_match( '/^class ' . $class . ' \{/m', (string) file_get_contents( $root . '/' . $file ) ) ) {
		$unloadable[] = $class;
	}
}
check( '  and each class where the autoloader looks for it', $unloadable, array() );

$harness = array( 'tests/support/template-world.php', 'tests/wordpress/kses.php', 'tests/test-template-render.php' );
check( 'the harnesses, the played WordPress and its kses copy do not ship', array_values( array_intersect( $harness, $built ) ), array() );
check( '  nor reach the updater\'s archive', 1 === preg_match( '/^\/tests\s+export-ignore/m', $attributes ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
