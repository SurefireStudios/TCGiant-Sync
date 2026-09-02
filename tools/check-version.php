<?php
/**
 * Check the version is the same number everywhere it is written.
 *
 * It is written in three places and nothing has ever made them agree:
 *
 *   - the Version: header in tcgiant-sync.php, which is what WordPress shows
 *     on the Plugins screen and what decides whether an update is offered;
 *   - the TCGIANT_SYNC_VERSION constant, which is what the plugin reports about
 *     itself in logs, in its user agent, and in the meta it stamps on products;
 *   - Stable tag in readme.txt.
 *
 * build-zip.php reads only the header. So a zip can be named 3.12.0, tell every
 * site that runs it that it is 3.11.1, and advertise a third number to anyone
 * reading the readme, and every one of those is silent. The release checklist
 * says to update all three by hand, which is the kind of instruction that works
 * until the day it does not.
 *
 * The stamped constant is the one that bites hardest: it goes into product meta
 * as the version that pushed a listing, so a wrong value misattributes a
 * merchant's data to a release that never touched it.
 *
 * @package TCGiant_Sync
 */

$root = dirname( __DIR__ );

/**
 * Pull one value out of a file, or record why it could not be found.
 *
 * @param string $path    Absolute path to read.
 * @param string $pattern Regex with one capturing group.
 * @param string $what    Human name for the value, used in the failure.
 * @param array  $errors  Collected failures, appended to.
 * @return string|null
 */
function tcg_version_from( $path, $pattern, $what, array &$errors ) {
	if ( ! is_readable( $path ) ) {
		$errors[] = sprintf( 'cannot read %s', basename( $path ) );
		return null;
	}

	$src = file_get_contents( $path );

	if ( ! preg_match( $pattern, $src, $m ) ) {
		$errors[] = sprintf( 'could not find %s in %s', $what, basename( $path ) );
		return null;
	}

	return trim( $m[1] );
}

$errors = array();

$found = array(
	'plugin header'          => tcg_version_from(
		$root . '/tcgiant-sync.php',
		'/^\s*\*\s*Version:\s*(.+)$/mi',
		'the Version: header',
		$errors
	),
	'TCGIANT_SYNC_VERSION'   => tcg_version_from(
		$root . '/tcgiant-sync.php',
		'/define\s*\(\s*[\'"]TCGIANT_SYNC_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
		'the TCGIANT_SYNC_VERSION constant',
		$errors
	),
	'readme.txt Stable tag'  => tcg_version_from(
		$root . '/readme.txt',
		'/^\s*Stable tag:\s*(.+)$/mi',
		'Stable tag',
		$errors
	),
);

if ( $errors ) {
	fwrite( STDERR, "check-version: could not read every version.\n" );
	foreach ( $errors as $error ) {
		fwrite( STDERR, '  - ' . $error . "\n" );
	}
	exit( 1 );
}

$distinct = array_unique( array_values( $found ) );

if ( count( $distinct ) > 1 ) {
	fwrite( STDERR, "check-version: the version does not agree with itself.\n" );
	foreach ( $found as $where => $value ) {
		fwrite( STDERR, sprintf( "  %-22s %s\n", $where, $value ) );
	}
	fwrite( STDERR, "  All three must match before tagging a release.\n" );
	exit( 1 );
}

printf( "OK - version %s, and all %d places agree.\n", reset( $distinct ), count( $found ) );
exit( 0 );
