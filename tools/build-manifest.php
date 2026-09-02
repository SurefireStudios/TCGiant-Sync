<?php
/**
 * What belongs in a distributed build.
 *
 * This used to live inside build-zip.php, which was fine while build-zip.php
 * was the only thing that needed to know. It is not: customers who auto-update
 * never receive a build-zip.php zip at all. They receive the GitHub source
 * archive, whose contents are decided by export-ignore in .gitattributes — a
 * second, entirely separate exclusion list maintained by hand.
 *
 * Two lists that must agree, with nothing checking that they do, will not stay
 * agreed. They already do not: README.md reaches auto-updating sites and is
 * absent from the uploaded zip. That difference is harmless, which is exactly
 * why it went unnoticed, and a harmful one would have gone unnoticed the same
 * way.
 *
 * So the list lives here, where check-archive-parity.php can compare it against
 * what git would ship without either of them guessing at the other.
 *
 * The list is deliberately an allowlist rather than a blocklist: forgetting to
 * exclude something is how a 15 MB zip full of dev dependencies gets shipped,
 * whereas forgetting to include something fails loudly.
 *
 * @package TCGiant_Sync
 */

/**
 * Directories shipped whole, subject to the skip rules below.
 *
 * @return string[]
 */
function tcgiant_build_include_dirs() {
	return array( 'admin', 'includes', 'languages', 'assets' );
}

/**
 * Individual files shipped from the plugin root.
 *
 * @return string[]
 */
function tcgiant_build_include_files() {
	return array( 'tcgiant-sync.php', 'uninstall.php', 'index.php', 'readme.txt', 'changelog.txt', 'LICENSE' );
}

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

/**
 * Every file that belongs in a build, relative to the plugin root.
 *
 * Order is the order they are added to the zip: the root files as listed, then
 * each directory as the filesystem walks it. Callers that are comparing rather
 * than building should sort first.
 *
 * @param string     $root    Plugin root directory.
 * @param string[]|null $skipped Filled with paths the skip rules excluded.
 * @return string[]
 */
function tcgiant_build_file_list( $root, &$skipped = null ) {
	$skipped = array();
	$files   = array();

	foreach ( tcgiant_build_include_files() as $file ) {
		if ( is_file( $root . '/' . $file ) ) {
			$files[] = $file;
		}
	}

	foreach ( tcgiant_build_include_dirs() as $dir ) {
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

			$files[] = $rel;
		}
	}

	return $files;
}
