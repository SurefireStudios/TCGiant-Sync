<?php
/**
 * Harness: the dashboard's log reader.
 *
 * It reads a file that has no size limit and no rotation, on a server with no
 * shell access, so it must never try to hold the whole thing. The seek path is
 * the part worth testing: land mid-line and you print a fragment as though it
 * were a record.
 */

$DIR = sys_get_temp_dir() . '/tcg-log-tail';
if ( ! is_dir( $DIR ) ) {
	mkdir( $DIR, 0777, true );
}

// ---- verbatim from dashboard.php --------------------------------------------
function read_tail( $relay_log_path ) {
	$relay_log_size  = is_file($relay_log_path) ? (int) filesize($relay_log_path) : 0;
	$relay_log_lines = [];

	if (is_readable($relay_log_path) && $relay_log_size > 0) {
		$tail_bytes = 262144;

		if ($relay_log_size <= $tail_bytes) {
			$raw = (string) file_get_contents($relay_log_path);
		} else {
			$fh = fopen($relay_log_path, 'rb');
			fseek($fh, -$tail_bytes, SEEK_END);
			$raw = (string) fread($fh, $tail_bytes);
			fclose($fh);
			$cut = strpos($raw, "\n");
			$raw = (false === $cut) ? '' : substr($raw, $cut + 1);
		}

		$relay_log_lines = array_slice(
			array_filter(array_map('rtrim', explode("\n", $raw)), 'strlen'),
			-200
		);
		$relay_log_lines = array_reverse($relay_log_lines);
	}

	return $relay_log_lines;
}
// -----------------------------------------------------------------------------

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-56s got %-10s want %s\n", $ok ? 'PASS' : 'FAIL', $label,
		var_export( $got, true ), var_export( $want, true ) );
}

echo "\nREADING A LOG THAT NOBODY PRUNES\n" . str_repeat( '=', 92 ) . "\n";

// --- missing --------------------------------------------------------------
check( 'a file that is not there gives nothing', read_tail( $DIR . '/nope.txt' ), array() );

// --- empty ----------------------------------------------------------------
file_put_contents( $DIR . '/empty.txt', '' );
check( 'an empty file gives nothing', read_tail( $DIR . '/empty.txt' ), array() );

// --- the real one ---------------------------------------------------------
$real = "2026-04-01 16:21:30 - Challenge received. Code: abc, Response: def\n"
	. "2026-08-08 06:37:49 - API usage update failed: Call to a member function bindValue() on bool\n"
	. "2026-09-02 17:24:40 - Deletion notice refused by 17 of 28 site(s): https://a.example (401)\n";
file_put_contents( $DIR . '/real.txt', $real );

$lines = read_tail( $DIR . '/real.txt' );
check( 'three entries read', count( $lines ), 3 );
check( 'newest is first', (bool) strpos( $lines[0], 'Deletion notice refused' ), true );
check( 'oldest is last',  (bool) strpos( $lines[2], 'Challenge received' ), true );

// --- trailing newline must not become a blank entry -------------------------
check( 'no empty entry from the trailing newline',
	count( array_filter( $lines, function ( $l ) { return '' === trim( $l ); } ) ), 0 );

// --- a file bigger than the window ------------------------------------------
$fh = fopen( $DIR . '/big.txt', 'wb' );
for ( $i = 1; $i <= 12000; $i++ ) {
	fwrite( $fh, sprintf( "2026-09-02 00:00:00 - entry %05d %s\n", $i, str_repeat( 'x', 60 ) ) );
}
fclose( $fh );

$size  = filesize( $DIR . '/big.txt' );
$lines = read_tail( $DIR . '/big.txt' );

printf( "  (the big file is %s KB, well past the 256 KB window)\n", number_format( $size / 1024, 0 ) );

check( 'a large file is capped at 200 entries', count( $lines ), 200 );
check( 'the newest entry is the last one written',
	(bool) strpos( $lines[0], 'entry 12000' ), true );

// The seek lands mid-line. Every entry shown must be a whole one.
$whole = true;
foreach ( $lines as $line ) {
	if ( 0 !== strpos( $line, '2026-09-02 00:00:00 - entry ' ) ) {
		$whole = false;
	}
}
check( 'no truncated fragment is shown as an entry', $whole, true );

// --- one enormous line with no newline in the window ------------------------
file_put_contents( $DIR . '/oneline.txt', str_repeat( 'y', 300000 ) );
check( 'a single line larger than the window yields nothing rather than a fragment',
	read_tail( $DIR . '/oneline.txt' ), array() );

// --- memory --------------------------------------------------------------
$before = memory_get_peak_usage();
read_tail( $DIR . '/big.txt' );
check( 'reading it did not balloon memory', memory_get_peak_usage() < $before + 4194304, true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );

array_map( 'unlink', glob( $DIR . '/*.txt' ) );
rmdir( $DIR );

exit( $fail > 0 ? 1 : 0 );
