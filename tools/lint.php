<?php
/**
 * Cross-platform `php -l` runner for the plugin source.
 *
 * Usage:  php tools/lint.php
 *         composer lint
 *
 * Walks the plugin's own PHP files (skipping vendored and reference code) and
 * reports any syntax errors. Exits non-zero on failure so it can gate CI.
 *
 * @package TCGiant_Sync
 */

$root = dirname( __DIR__ );

$skipDirs = array(
	'vendor',
	'node_modules',
	'zips',
	'.git',
	'plugin-update-checker',
);

$rii = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $file ) use ( $skipDirs ) {
			$name = $file->getFilename();
			if ( $file->isDir() ) {
				// Skip vendored/reference trees, including the competitor copy.
				if ( in_array( $name, $skipDirs, true ) ) {
					return false;
				}
				if ( strpos( $name, 'wp-lister-ebay' ) === 0 ) {
					return false;
				}
			}
			return true;
		}
	)
);

$php     = PHP_BINARY;
$checked = 0;
$failed  = array();

foreach ( $rii as $file ) {
	if ( ! $file->isFile() || strtolower( $file->getExtension() ) !== 'php' ) {
		continue;
	}

	$path = $file->getPathname();
	$out  = array();
	$code = 0;
	exec( escapeshellarg( $php ) . ' -l ' . escapeshellarg( $path ) . ' 2>&1', $out, $code );

	$checked++;
	if ( 0 !== $code ) {
		$failed[ $path ] = implode( "\n", $out );
	}
}

if ( $failed ) {
	foreach ( $failed as $path => $msg ) {
		echo 'FAIL ' . str_replace( $root . DIRECTORY_SEPARATOR, '', $path ) . PHP_EOL;
		echo '     ' . str_replace( "\n", "\n     ", trim( $msg ) ) . PHP_EOL;
	}
	echo PHP_EOL . sprintf( '%d of %d file(s) failed to parse.', count( $failed ), $checked ) . PHP_EOL;
	exit( 1 );
}

echo sprintf( 'OK — %d file(s) parsed cleanly.', $checked ) . PHP_EOL;
exit( 0 );
