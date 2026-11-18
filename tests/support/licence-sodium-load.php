<?php
/**
 * test-license-server.php's child (3.41.0 fix round 1, L8): the licence
 * class's signature check on a PHP with no sodium function defined, where it
 * must load WordPress's own wp-includes/sodium_compat/autoload.php first.
 *
 * usage: php licence-sodium-load.php <plugin root> <fake ABSPATH> <case file> [release]
 *
 * The fake ABSPATH holds wp-includes/sodium_compat/autoload.php, or not. Its
 * autoload.php (written by the harness) defines the sodium functions from the
 * test shim, as WordPress's sodium_compat does from its own code: the real
 * sodium_compat is not on this machine. Prints one JSON line.
 *
 * @package TCGiant_Sync
 */

list( , $root, $abspath, $case ) = $argv;

$out = array( 'sodium_before' => function_exists( 'sodium_crypto_sign_verify_detached' ), 'ext' => extension_loaded( 'sodium' ) );

define( 'ABSPATH', rtrim( $abspath, '/\\' ) . '/' );
// The test key, as the harness trusts it, unless asked to play a release
// ("release": no constant, so test- keys are not trusted).
if ( 'release' !== ( $argv[4] ?? '' ) ) {
	define( 'TCGIANT_SYNC_LICENCE_TEST_KEYS', true );
}

// The class reads none of WordPress until a check runs; verify_entitlement()
// needs none of it.
require $root . '/includes/class-tcgiant-sync-entitlements.php';
// The class as a candidate is, the test key its only key: once the owner's
// real key has replaced it, a copy with the array put back to that shape is
// loaded, so a "release" here is one that slipped out with the test key.
$src  = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-license.php' );
$test = "'test-2026-11' => '6rMKh3pRb2fjwWb8NYSNrOwhT2i+SDeHGJmymftnha0='";
if ( false === strpos( $src, $test ) ) {
	$copy = sys_get_temp_dir() . '/tcg-licence-child-' . getmypid() . '.php';
	file_put_contents( $copy, preg_replace( '/const SERVER_KEYS = array\(.*?\);/s', "const SERVER_KEYS = array(\n\t\t" . $test . ",\n\t);", $src, 1 ) );
	require $copy;
	@unlink( $copy );
} else {
	require $root . '/includes/class-tcgiant-sync-license.php';
}

$c   = json_decode( (string) file_get_contents( $case ), true );
$got = TCGiant_Sync_License::verify_entitlement( $c['ent'], $c['expect'] );

$out['why']          = $got['why'];
$out['status']       = is_array( $got['payload'] ) ? $got['payload']['status'] : null;
$out['sodium_after'] = function_exists( 'sodium_crypto_sign_verify_detached' );
$out['loaded']       = array_values( array_filter( get_included_files(), function ( $f ) { return false !== strpos( str_replace( '\\', '/', $f ), 'sodium_compat/autoload.php' ); } ) ) ? true : false;

echo json_encode( $out ), "\n";
