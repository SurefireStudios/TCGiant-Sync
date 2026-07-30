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
 * The include list is deliberately an allowlist rather than a blocklist:
 * forgetting to exclude something is how a 15 MB zip full of dev dependencies
 * gets shipped, whereas forgetting to include something fails loudly.
 *
 * @package TCGiant_Sync
 */

$root = dirname( __DIR__ );

// Everything the plugin needs at runtime, and nothing else.
$include_dirs  = array( 'admin', 'includes', 'languages', 'assets' );
$include_files = array( 'tcgiant-sync.php', 'uninstall.php', 'index.php', 'readme.txt', 'changelog.txt', 'LICENSE' );

// Read the version straight from the plugin header so the zip can never be
// misnamed relative to what it contains.
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

$added   = 0;
$bytes   = 0;
$skipped = array();

/**
 * Should this path be left out of the build?
 *
 * @param string $rel Path relative to the plugin root, using forward slashes.
 * @return bool
 */
function tcgiant_build_skip( $rel ) {
	$patterns = array(
		'#(^|/)\.#',                    // Dotfiles and dot-directories.
		'#(^|/)node_modules(/|$)#',
		'#(^|/)tests?(/|$)#',
		'#\.(zip|tar|gz|log|map|dist)$#i',
		'#(^|/)composer\.(json|lock)$#',
		'#(^|/)phpstan#',
		'#(^|/)Thumbs\.db$#i',
		'#(^|/)\.DS_Store$#',
	);

	foreach ( $patterns as $pattern ) {
		if ( preg_match( $pattern, $rel ) ) {
			return true;
		}
	}
	return false;
}

foreach ( $include_files as $file ) {
	$path = $root . '/' . $file;
	if ( ! is_file( $path ) ) {
		continue;
	}
	$zip->addFile( $path, 'tcgiant-sync/' . $file );
	$added++;
	$bytes += filesize( $path );
}

foreach ( $include_dirs as $dir ) {
	$base = $root . '/' . $dir;
	if ( ! is_dir( $base ) ) {
		continue;
	}

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $it as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}

		$rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );

		if ( tcgiant_build_skip( $rel ) ) {
			$skipped[] = $rel;
			continue;
		}

		$zip->addFile( $file->getPathname(), 'tcgiant-sync/' . $rel );
		$added++;
		$bytes += $file->getSize();
	}
}

$zip->close();

printf( "Built %s%s", $out, PHP_EOL );
printf( "  %d files, %.1f MB uncompressed, %.1f MB zipped%s", $added, $bytes / 1048576, filesize( $out ) / 1048576, PHP_EOL );
if ( $skipped ) {
	printf( "  skipped %d non-distributable file(s)%s", count( $skipped ), PHP_EOL );
}
