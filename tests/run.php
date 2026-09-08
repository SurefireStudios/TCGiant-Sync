<?php
/**
 * Run every harness in this directory and fail if any assertion did.
 *
 * Each test-*.php is a standalone script that prints PASS/FAIL lines and ends
 * with "N passed, M failed". They are run as separate processes so one cannot
 * leak state, stubs or constants into another. No framework, on purpose: a
 * harness should be readable by anyone who can read PHP.
 *
 * Usage:  php tests/run.php            (or: composer test)
 *         php tests/run.php --verbose  (show every assertion, not just failures)
 *
 * @package TCGiant_Sync
 */

$verbose = in_array( '--verbose', $argv, true ) || in_array( '-v', $argv, true );
$files   = glob( __DIR__ . '/test-*.php' );
sort( $files );

if ( empty( $files ) ) {
	fwrite( STDERR, "run.php: no test-*.php files found\n" );
	exit( 1 );
}

$total_pass = 0;
$total_fail = 0;
$broken     = array();

foreach ( $files as $file ) {
	$name = basename( $file, '.php' );
	$cmd  = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1';
	$out  = array();
	exec( $cmd, $out, $code );
	$text = implode( "\n", $out );

	if ( ! preg_match( '/(\d+) passed, (\d+) failed/', $text, $m ) ) {
		// No summary line means the script died before finishing - a fatal, a
		// missing file, a parse error. That is a failure of the test itself.
		$broken[] = $name;
		printf( "  %-26s BROKEN (no summary; exit %d)\n", $name, $code );
		echo preg_replace( '/^/m', '      ', $text ), "\n";
		continue;
	}

	$pass = (int) $m[1];
	$fail = (int) $m[2];
	$total_pass += $pass;
	$total_fail += $fail;

	printf( "  %-26s %s  %d passed, %d failed\n", $name, $fail ? 'FAIL' : 'ok  ', $pass, $fail );

	if ( $verbose || $fail ) {
		foreach ( $out as $line ) {
			if ( $verbose || false !== strpos( $line, 'FAIL' ) ) {
				echo '      ', $line, "\n";
			}
		}
	}
}

echo "\n";
printf( "  %d file(s), %d assertions: %d passed, %d failed", count( $files ), $total_pass + $total_fail, $total_pass, $total_fail );
if ( $broken ) {
	printf( ", %d broken (%s)", count( $broken ), implode( ', ', $broken ) );
}
echo "\n";

exit( ( $total_fail > 0 || $broken ) ? 1 : 0 );
