<?php
/**
 * Harness: the relay dashboard's login throttle.
 *
 * The dashboard is the administrative view of the relay - every connected shop,
 * when it last spoke to us, and the relay's own log. It is guarded by one shared
 * password, and until now nothing at all limited how many times that password
 * could be guessed. The login path never opens the database, so a guess is cheap
 * to serve and an attacker could try as fast as the server would answer.
 *
 * That mattered more than it looked. The only thing slowing anyone down was a
 * bot challenge the hosting provider happens to run in front of the domain -
 * not a control anyone chose, and the very thing we are asking them to remove so
 * that merchants' servers can reach the relay at all.
 *
 * The password has since been changed to a strong one, so this is no longer
 * about somebody guessing it. It is about not offering an unlimited free
 * service to whoever points a script at the URL.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-62s got %-18s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

define( 'LOGIN_MAX_FAILURES', 5 );
define( 'LOGIN_LOCKOUT_SECONDS', 900 );

// ---- the dashboard's decisions, verbatim, with the clock and store injected ----------

function login_who( $ip ) {
	return substr( hash( 'sha256', (string) $ip ), 0, 16 );
}

function login_locked_for( array $store, $ip, $now ) {
	$row = $store[ login_who( $ip ) ] ?? null;

	if ( ! is_array( $row ) || (int) ( $row['count'] ?? 0 ) < LOGIN_MAX_FAILURES ) {
		return 0;
	}

	$remaining = LOGIN_LOCKOUT_SECONDS - ( $now - (int) ( $row['at'] ?? 0 ) );

	return $remaining > 0 ? (int) $remaining : 0;
}

function login_record( array $store, $ip, $success, $now ) {
	$who = login_who( $ip );

	if ( $success ) {
		unset( $store[ $who ] );
	} else {
		$count          = (int) ( $store[ $who ]['count'] ?? 0 );
		$store[ $who ]  = array( 'count' => $count + 1, 'at' => $now );
	}

	return $store;
}

function login_prune( array $store, $now ) {
	foreach ( $store as $key => $row ) {
		if ( empty( $row['at'] ) || ( $now - (int) $row['at'] ) > ( LOGIN_LOCKOUT_SECONDS * 4 ) ) {
			unset( $store[ $key ] );
		}
	}
	return $store;
}

// =====================================================================================

$now   = 1000000;
$ip    = '203.0.113.9';
$store = array();

echo "\nGUESSING COSTS SOMETHING NOW\n" . str_repeat( '=', 108 ) . "\n";

check( 'a first visit is not locked out', login_locked_for( $store, $ip, $now ), 0 );

for ( $i = 1; $i <= 4; $i++ ) {
	$store = login_record( $store, $ip, false, $now );
}
check( 'four wrong guesses still let you try', login_locked_for( $store, $ip, $now ), 0 );

$store = login_record( $store, $ip, false, $now );
check( 'the fifth locks the door', login_locked_for( $store, $ip, $now ), 900 );

check( 'and it is still shut a minute later', login_locked_for( $store, $ip, $now + 60 ), 840 );
check( 'open again after the wait', login_locked_for( $store, $ip, $now + 901 ), 0 );

// Someone who keeps hammering while locked out does not wait it out by hammering.
$store_hammered = login_record( $store, $ip, false, $now + 300 );
check( 'knocking during the lockout restarts the clock',
	login_locked_for( $store_hammered, $ip, $now + 301 ), 899 );

echo "\nGETTING IN CLEARS IT\n" . str_repeat( '=', 108 ) . "\n";

$store_ok = login_record( $store, $ip, true, $now );
check( 'a correct password forgets the failures', login_locked_for( $store_ok, $ip, $now ), 0 );
check( '  and leaves nothing behind for that address', array_key_exists( login_who( $ip ), $store_ok ), false );

echo "\nONE ADDRESS DOES NOT LOCK OUT ANOTHER\n" . str_repeat( '=', 108 ) . "\n";

// The relay is reached by merchants' servers and by us. A shared counter would
// let one bad actor lock the owner out of their own dashboard.
check( 'a different address is unaffected', login_locked_for( $store, '198.51.100.7', $now ), 0 );
check( 'the two are counted separately', login_who( $ip ) === login_who( '198.51.100.7' ), false );

echo "\nTHE FILE HOLDS NO ADDRESSES, AND DOES NOT GROW FOR EVER\n" . str_repeat( '=', 108 ) . "\n";

check( 'the key is a hash, not the address', false === strpos( login_who( $ip ), '203.0.113' ), true );
check( '  and short enough to stay tidy', strlen( login_who( $ip ) ), 16 );

$old = array(
	login_who( 'a' ) => array( 'count' => 5, 'at' => $now - ( 900 * 5 ) ),
	login_who( 'b' ) => array( 'count' => 2, 'at' => $now - 10 ),
	login_who( 'c' ) => array( 'count' => 1 ),
);
$pruned = login_prune( $old, $now );
check( 'an entry long past its lockout is dropped', array_key_exists( login_who( 'a' ), $pruned ), false );
check( 'a recent one is kept', array_key_exists( login_who( 'b' ), $pruned ), true );
check( 'a damaged one with no timestamp is dropped', array_key_exists( login_who( 'c' ), $pruned ), false );

echo "\nTHE COMPARISON ITSELF\n" . str_repeat( '=', 108 ) . "\n";

// hash_equals rather than ===, so the check does not answer faster for a wrong
// first character than for a wrong last one.
check( 'the right password is accepted', hash_equals( 'correct horse battery', 'correct horse battery' ), true );
check( 'a wrong one is not', hash_equals( 'correct horse battery', 'correct horse batteru' ), false );
check( 'nor is a prefix of it', hash_equals( 'correct horse battery', 'correct' ), false );
check( 'nor is an empty guess', hash_equals( 'correct horse battery', '' ), false );

echo "\nWHAT THE STATE FILES MUST NEVER BE\n" . str_repeat( '=', 108 ) . "\n";

// Both new files sit in the relay directory, which denies everything by default
// and then grants exactly four PHP files. Neither is one of them.
$htaccess = dirname( __DIR__, 2 ) . '/website/syncconnect/.htaccess';

if ( ! is_readable( $htaccess ) ) {
	echo "  SKIPPED - the relay is not in this checkout (it is deployed by hand).\n";
} else {
	$rules = (string) file_get_contents( $htaccess );
	check( 'the relay directory denies everything by default', false !== strpos( $rules, 'Require all denied' ), true );
	check( '  and grants only the four endpoints', 1 === preg_match( '/\^\(relay\|connect\|telemetry\|dashboard\)\\\\\.php\$/', $rules ), true );
	check( '  so the attempts file is not served', false === strpos( $rules, 'login-attempts' ), true );
	check( '  nor the key cache', false === strpos( $rules, 'ebay-keys' ), true );
}

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
