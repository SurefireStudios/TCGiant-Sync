<?php
/**
 * Check that the two ways a customer receives this plugin agree.
 *
 * There are two, and they are governed by two entirely separate lists:
 *
 *   - Someone who downloads a zip and uploads it gets what build-zip.php built,
 *     from the allowlist in build-manifest.php.
 *   - Someone whose site updates itself gets the GitHub "Source code (zip)",
 *     which is `git archive`, from the export-ignore rules in .gitattributes.
 *
 * The second is the one almost every paying customer actually receives, and it
 * is a blocklist: anything not explicitly excluded ships. So the failure it
 * invites is the opposite of build-zip.php's, and worse — a new development
 * file lands in every customer's plugins directory and nothing says a word.
 *
 * Two hand-maintained lists that must agree, with nothing checking that they
 * do, will not stay agreed. They already do not; see $allowed_in_archive below.
 *
 * Note this reads the COMMITTED tree, because that is what git archive can
 * build. Run it after committing and before tagging, which is when it matters.
 * Uncommitted files show up as "built but not shipped" and the tool says so.
 *
 * @package TCGiant_Sync
 */

require_once __DIR__ . '/build-manifest.php';

$root = dirname( __DIR__ );

/*
 * Differences that are known and deliberate.
 *
 * README.md is repository documentation. It reaches auto-updating sites and not
 * uploaded ones, which is untidy but harmless — and it is the proof that these
 * two lists drift, since nobody chose it.
 */
$allowed_in_archive = array(
	'README.md',
);

if ( ! function_exists( 'exec' ) ) {
	fwrite( STDERR, "check-archive-parity: exec() is unavailable, cannot ask git what it would ship.\n" );
	exit( 1 );
}

// Is there anything uncommitted? A difference means something different then.
exec( 'git -C ' . escapeshellarg( $root ) . ' status --porcelain 2>&1', $status_out, $status_code );
$dirty = ( 0 === $status_code && ! empty( $status_out ) );

$tmp = sys_get_temp_dir() . '/tcgiant-archive-parity-' . getmypid() . '.zip';

exec(
	'git -C ' . escapeshellarg( $root ) . ' archive --format=zip -o ' . escapeshellarg( $tmp ) . ' HEAD 2>&1',
	$archive_out,
	$archive_code
);

if ( 0 !== $archive_code || ! is_file( $tmp ) ) {
	fwrite( STDERR, "check-archive-parity: git archive failed.\n" );
	foreach ( $archive_out as $line ) {
		fwrite( STDERR, '  ' . $line . "\n" );
	}
	exit( 1 );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $tmp ) ) {
	fwrite( STDERR, "check-archive-parity: could not read the archive git produced.\n" );
	unlink( $tmp );
	exit( 1 );
}

$shipped = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$name = $zip->getNameIndex( $i );
	if ( '' === $name || '/' === substr( $name, -1 ) ) {
		continue;
	}
	$shipped[] = $name;
}
$zip->close();
unlink( $tmp );

$skipped = array();
$built   = tcgiant_build_file_list( $root, $skipped );

sort( $shipped );
sort( $built );

$only_archive = array_values( array_diff( $shipped, $built, $allowed_in_archive ) );
$only_build   = array_values( array_diff( $built, $shipped ) );

if ( empty( $only_archive ) && empty( $only_build ) ) {
	printf(
		"OK - both routes ship the same %d file(s)%s.\n",
		count( $built ),
		$allowed_in_archive ? sprintf( ' (%d known difference allowed)', count( $allowed_in_archive ) ) : ''
	);
	exit( 0 );
}

fwrite( STDERR, "check-archive-parity: the two routes do not ship the same files.\n" );

if ( $only_archive ) {
	fwrite( STDERR, sprintf( "\n  Auto-updating sites receive these, uploaded zips do not (%d):\n", count( $only_archive ) ) );
	foreach ( array_slice( $only_archive, 0, 40 ) as $file ) {
		fwrite( STDERR, '    + ' . $file . "\n" );
	}
	if ( count( $only_archive ) > 40 ) {
		fwrite( STDERR, sprintf( "    ... and %d more\n", count( $only_archive ) - 40 ) );
	}
	fwrite( STDERR, "  Add an export-ignore rule in .gitattributes, or list it in build-manifest.php.\n" );

	// The trap: git archive reads .gitattributes from the tree it is archiving,
	// not from the working directory. So a rule you have just written has no
	// effect here until it is committed, and the check goes on failing at
	// something you have already fixed.
	if ( $dirty ) {
		exec( 'git -C ' . escapeshellarg( $root ) . ' status --porcelain -- .gitattributes 2>&1', $attr_out );
		if ( ! empty( $attr_out ) ) {
			fwrite( STDERR, "  Note: .gitattributes is modified but not committed. git archive reads it\n" );
			fwrite( STDERR, "  from the committed tree, so commit the rule and run this again.\n" );
		}
	}
}

if ( $only_build ) {
	fwrite( STDERR, sprintf( "\n  Uploaded zips contain these, auto-updating sites do not (%d):\n", count( $only_build ) ) );
	foreach ( array_slice( $only_build, 0, 40 ) as $file ) {
		fwrite( STDERR, '    - ' . $file . "\n" );
	}
	if ( count( $only_build ) > 40 ) {
		fwrite( STDERR, sprintf( "    ... and %d more\n", count( $only_build ) - 40 ) );
	}
	if ( $dirty ) {
		fwrite( STDERR, "  The working tree has uncommitted changes, so new files are expected here.\n" );
		fwrite( STDERR, "  Commit them and run this again before tagging.\n" );
	} else {
		fwrite( STDERR, "  These are excluded from the release archive but shipped in the zip.\n" );
		fwrite( STDERR, "  Remove the export-ignore rule, or drop them from build-manifest.php.\n" );
	}
}

exit( 1 );
