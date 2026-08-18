<?php
/**
 * Validate every printf-style format string in the plugin.
 *
 * On PHP 8 a malformed specifier is not a warning, it is a ValueError, and an
 * uncaught one takes the whole site down. That happened: a stray backslash in
 * a translator string turned an error message into a fatal error, on the very
 * screen a merchant uses to connect their eBay account. The site went white
 * and only FTP could recover it.
 *
 * php -l cannot see this, because the file parses perfectly well, and PHPStan
 * does not check the shape of a string it cannot always resolve. So this runs
 * every string literal through vsprintf() and reports whatever objects.
 *
 * Uses PHP's own tokeniser rather than regular expressions, so it sees exactly
 * the string literals the engine sees.
 *
 * Usage: php tools/check-formats.php
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

$root = dirname( __DIR__ );

$files = array( $root . '/tcgiant-sync.php' );

foreach ( array( $root . '/includes', $root . '/admin' ) as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$walker = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $walker as $entry ) {
		if ( 'php' !== strtolower( $entry->getExtension() ) ) {
			continue;
		}
		$as_unix = str_replace( DIRECTORY_SEPARATOR, '/', $entry->getPathname() );
		if ( false !== strpos( $as_unix, 'plugin-update-checker' ) ) {
			continue;
		}
		$files[] = $entry->getPathname();
	}
}

$problems = 0;
$checked  = 0;
$args     = array_fill( 0, 12, '1' );

foreach ( $files as $path ) {
	$src    = file_get_contents( $path );
	$tokens = token_get_all( $src );

	foreach ( $tokens as $token ) {
		if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
			continue;
		}

		// Drop the surrounding quotes. Escape sequences are left as they are:
		// a specifier that is broken by a stray escape must stay broken here,
		// because that is precisely what we are looking for.
		$literal = substr( $token[1], 1, -1 );

		// Only look at literals that actually attempt a specifier. A lone
		// trailing % is a SQL LIKE wildcard, not a format string, and there are
		// plenty of those. A percent followed by a letter, digit or dollar is
		// someone reaching for sprintf — including the broken "%1(backslash)$s"
		// that caused this check to exist, where the character after the digit
		// is what makes it fail.
		if ( ! preg_match( '/%[0-9a-zA-Z$]/', $literal ) ) {
			continue;
		}

		$checked++;

		try {
			@vsprintf( $literal, $args );
		} catch ( Throwable $e ) {
			$problems++;
			printf(
				'FAIL %s line %d%s     %s%s     %s%s',
				str_replace( $root . DIRECTORY_SEPARATOR, '', $path ),
				$token[2],
				PHP_EOL,
				$token[1],
				PHP_EOL,
				$e->getMessage(),
				PHP_EOL
			);
		}
	}
}

if ( $problems > 0 ) {
	printf( '%s%d malformed format string(s) found.%s', PHP_EOL, $problems, PHP_EOL );
	exit( 1 );
}

printf( 'OK - %d format string(s) checked, all valid.%s', $checked, PHP_EOL );
exit( 0 );
