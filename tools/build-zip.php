<?php
/**
 * Build a distributable plugin zip.
 *
 * Usage:  php tools/build-zip.php
 *         composer build
 *
 * Produces zips/tcgiant-sync-<version>.zip containing only the files that
 * belong on a customer's site, wrapped in a `tcgiant-sync/` folder so it
 * installs correctly through Plugins → Add New → Upload.
 *
 * What counts as "belongs" lives in build-manifest.php, so that
 * check-archive-parity.php can compare it against what GitHub ships to
 * auto-updating sites without either of them keeping its own copy of the list.
 *
 * @package TCGiant_Sync
 */

require_once __DIR__ . '/build-manifest.php';

$root = dirname( __DIR__ );

// Read the version straight from the plugin header so the zip can never be
// misnamed relative to what it contains. check-version.php is what makes sure
// the header agrees with the constant and the readme.
$header = file_get_contents( $root . '/tcgiant-sync.php' );
if ( ! preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $header, $m ) ) {
	fwrite( STDERR, "Could not read Version from tcgiant-sync.php\n" );
	exit( 1 );
}
$version = trim( $m[1] );

$out_dir = $root . '/zips';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0777, true );
}
$out = $out_dir . '/tcgiant-sync-' . $version . '.zip';

if ( file_exists( $out ) ) {
	unlink( $out );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $out, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Could not create {$out}\n" );
	exit( 1 );
}

$skipped = array();
$files   = tcgiant_build_file_list( $root, $skipped );

$added = 0;
$bytes = 0;

foreach ( $files as $rel ) {
	$path = $root . '/' . $rel;
	$zip->addFile( $path, 'tcgiant-sync/' . $rel );
	$added++;
	$bytes += filesize( $path );
}

$zip->close();

printf( "Built %s%s", $out, PHP_EOL );
printf( "  %d files, %.1f MB uncompressed, %.1f MB zipped%s", $added, $bytes / 1048576, filesize( $out ) / 1048576, PHP_EOL );
if ( $skipped ) {
	printf( "  skipped %d non-distributable file(s)%s", count( $skipped ), PHP_EOL );
}
