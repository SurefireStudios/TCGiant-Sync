<?php
/**
 * Harness: tools/check-version.php, the release workflow's version gate.
 *
 * A candidate is staged with its changelog entries dated as the literal
 * YYYY-MM-DD until the day it goes out. The release workflow runs
 * check-version, lint, check-hooks and archive parity before it builds the
 * zip, and check-version only compared the three places the version number is
 * written - so the 3.27.5 candidate passed with "= 3.27.5 - YYYY-MM-DD =" in
 * readme.txt, which WordPress shows merchants under View details, and
 * "YYYY-MM-DD - version 3.27.5" in changelog.txt. A release tagged from it
 * would have shipped both, and one tagged with no entry at all would have
 * shipped the last version's notes as this one's.
 *
 * This runs the real tool, in a child process, against copies of this tree's
 * own tcgiant-sync.php, readme.txt and changelog.txt with the newest entries
 * dated, undated, missing or impossible.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-78s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 200 ) );
}

$ROOT = dirname( __DIR__ );
$WORK = sys_get_temp_dir() . '/tcg-check-version-harness-' . getmypid();

$HEADER    = (string) file_get_contents( $ROOT . '/tcgiant-sync.php' );
$README    = (string) file_get_contents( $ROOT . '/readme.txt' );
$CHANGELOG = (string) file_get_contents( $ROOT . '/changelog.txt' );
$VERSION   = preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', $HEADER, $m ) ? $m[1] : '';
$V         = preg_quote( $VERSION, '/' );

/** This version's two entries, given $date - whatever date they have in the tree. */
function dated( $date ) {
	global $README, $CHANGELOG, $V;
	$readme    = preg_replace( '/^=\s*' . $V . '\s+-\s+\S+\s*=[ \t]*$/m', '= ' . $GLOBALS['VERSION'] . ' - ' . $date . ' =', $README, -1, $n1 );
	$changelog = preg_replace( '/^\S+\s+-\s+version\s+' . $V . '[ \t]*$/m', $date . ' - version ' . $GLOBALS['VERSION'], $CHANGELOG, -1, $n2 );
	return array( 1 === $n1 && 1 === $n2, $readme, $changelog );
}

/** Run the real tool on a copy of the tree's three files, as given. Returns array( exit code, output ). */
function check_version( $name, $header, $readme, $changelog ) {
	global $ROOT, $WORK;
	$dir = $WORK . '/' . $name;
	@mkdir( $dir . '/tools', 0777, true );
	copy( $ROOT . '/tools/check-version.php', $dir . '/tools/check-version.php' );
	file_put_contents( $dir . '/tcgiant-sync.php', $header );
	file_put_contents( $dir . '/readme.txt', $readme );
	file_put_contents( $dir . '/changelog.txt', $changelog );
	$out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $dir . '/tools/check-version.php' ) . ' 2>&1', $out, $code );
	return array( $code, implode( "\n", $out ) );
}

echo "\nTHE NEWEST CHANGELOG ENTRIES MUST BE THIS VERSION, DATED (FAILS ON THE 3.27.5 CANDIDATE)\n" . str_repeat( '=', 112 ) . "\n";

check( 'this tree has a version, and an entry for it in each changelog', array( '' !== $VERSION, dated( '2026-10-20' )[0] ), array( true, true ) );

list( , $readme, $changelog ) = dated( '2026-10-20' );
list( $code, $out )           = check_version( 'dated', $HEADER, $readme, $changelog );
check( 'both entries dated: passes', array( $code, 0 === strpos( $out, 'OK - version ' . $VERSION . ', and all 3 places agree.' ) ), array( 0, true ) );

// As the candidate was staged.
list( , $readme, $changelog ) = dated( 'YYYY-MM-DD' );
list( $code, $out )           = check_version( 'undated', $HEADER, $readme, $changelog );
check( 'both still YYYY-MM-DD: refused, naming both files', array( $code, false !== strpos( $out, 'readme.txt: the ' . $VERSION . ' entry is dated "YYYY-MM-DD", not a real date' ), false !== strpos( $out, 'changelog.txt: the ' . $VERSION . ' entry is dated "YYYY-MM-DD", not a real date' ) ), array( 1, true, true ) );

list( , $readme, $changelog ) = dated( '2026-10-20' );
list( , $undated_readme, $undated_changelog ) = dated( 'YYYY-MM-DD' );
list( $code, $out ) = check_version( 'readme-undated', $HEADER, $undated_readme, $changelog );
check( '  only the readme\'s left undated: refused, naming it alone', array( $code, false !== strpos( $out, 'readme.txt: the ' ), false !== strpos( $out, 'changelog.txt: ' ) ), array( 1, true, false ) );
list( $code, $out ) = check_version( 'changelog-undated', $HEADER, $readme, $undated_changelog );
check( '  only changelog.txt\'s: refused, naming it alone', array( $code, false !== strpos( $out, 'readme.txt: ' ), false !== strpos( $out, 'changelog.txt: the ' ) ), array( 1, false, true ) );

foreach ( array( '2026-13-40', '2026-02-30', '20-10-2026', 'soon' ) as $date ) {
	list( , $readme, $changelog ) = dated( $date );
	list( $code, $out )           = check_version( 'bad-date', $HEADER, $readme, $changelog );
	check( sprintf( 'dated "%s": refused', $date ), array( $code, substr_count( $out, 'entry is dated "' . $date . '", not a real date' ) ), array( 1, 2 ) );
}

// An entry forgotten: the newest one is then the last version's.
list( , $readme, $changelog ) = dated( '2026-10-20' );
list( $code, $out ) = check_version( 'no-readme-entry', $HEADER, preg_replace( '/^= ' . $V . ' - 2026-10-20 =\n/m', '', $readme ), $changelog );
check( 'no entry for it in the readme\'s changelog: refused', array( $code, 1 === preg_match( '/readme\.txt: the newest changelog entry is for \S+, not ' . $V . '/', $out ) ), array( 1, true ) );
list( $code, $out ) = check_version( 'no-changelog-entry', $HEADER, $readme, preg_replace( '/^2026-10-20 - version ' . $V . '\n/m', '', $changelog ) );
check( 'no entry for it in changelog.txt: refused', array( $code, 1 === preg_match( '/changelog\.txt: the newest changelog entry is for \S+, not ' . $V . '/', $out ) ), array( 1, true ) );
list( $code, $out ) = check_version( 'no-changelog-section', $HEADER, str_replace( '== Changelog ==', '== Changes ==', $readme ), $changelog );
check( 'a readme with no == Changelog == section: refused', array( $code, false !== strpos( $out, 'readme.txt: no changelog entry found' ) ), array( 1, true ) );

// Only the changelog counts: an entry of the same shape in a section after it
// is not taken for one.
$upgrade = substr( $readme, 0, strpos( $readme, '== Changelog ==' ) ) . "== Changelog ==\n\n== Upgrade Notice ==\n\n= " . $VERSION . " - 2026-10-20 =\nUpgrade.\n";
list( $code, $out ) = check_version( 'upgrade-notice', $HEADER, $upgrade, $changelog );
check( 'an empty changelog, the version only in a section after it: refused', array( $code, false !== strpos( $out, 'readme.txt: no changelog entry found' ) ), array( 1, true ) );

// What it checked before still holds.
list( $code, $out ) = check_version( 'stable-tag', $HEADER, preg_replace( '/^Stable tag: .*$/m', 'Stable tag: 0.0.1', $readme ), $changelog );
check( 'a Stable tag that disagrees: still refused', array( $code, false !== strpos( $out, 'the version does not agree with itself' ) ), array( 1, true ) );

// The tool is the one the release workflow and CI run.
$workflow = (string) @file_get_contents( $ROOT . '/.github/workflows/release.yml' );
check( 'the release workflow runs it before it builds the zip', '' === $workflow || ( false !== strpos( $workflow, 'php tools/check-version.php' ) && strpos( $workflow, 'php tools/check-version.php' ) < strpos( $workflow, 'php tools/build-zip.php' ) ), true );

// Tidy up.
foreach ( glob( $WORK . '/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
	foreach ( array( 'tools/check-version.php', 'tcgiant-sync.php', 'readme.txt', 'changelog.txt' ) as $file ) {
		@unlink( $dir . '/' . $file );
	}
	@rmdir( $dir . '/tools' );
	@rmdir( $dir );
}
@rmdir( $WORK );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
