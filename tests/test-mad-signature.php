<?php
/**
 * Harness: does an eBay account-deletion notice actually verify at the shop?
 *
 * The relay signs the notice; the plugin checks the signature and answers 401
 * if it does not match. Nothing on the relay side reads that answer, so a
 * mismatch is completely silent — which is why this went unnoticed.
 *
 * Both sides are reproduced verbatim. The point is not that the new code is
 * self-consistent; it is that the relay's signature and the plugin's
 * expectation are computed from the SAME key.
 */

// ---- the relay's shared secret, and one site's issued key -------------------
define( 'RELAY_SECRET', 'global_shared_secret_stand_in' );
$PER_SITE_KEY = 'a3f9c1e07b2d4856a3f9c1e07b2d4856';

$PAYLOAD   = '{"metadata":{"topic":"MARKETPLACE_ACCOUNT_DELETION"},"notification":{"data":{"username":"someseller"}}}';
$TIMESTAMP = 1788000000;

// ---- relay side, verbatim ---------------------------------------------------
function relay_sign( $row, $payload, $timestamp ) {
	$signing_key = ! empty( $row['relay_signing_key'] ) ? $row['relay_signing_key'] : RELAY_SECRET;
	return hash_hmac( 'sha256', $payload . $timestamp, $signing_key );
}

// ---- plugin side, verbatim from class-tcgiant-sync-webhooks.php -------------
function plugin_accepts( $relay_secret, $body, $timestamp, $signature ) {
	if ( empty( $relay_secret ) ) {
		return false; // 'not_configured' - deliberately no fallback.
	}
	$expected_signature = hash_hmac( 'sha256', $body . $timestamp, $relay_secret );
	return hash_equals( $expected_signature, (string) $signature );
}

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-64s got %-7s want %s\n", $ok ? 'PASS' : 'FAIL', $label,
		var_export( $got, true ), var_export( $want, true ) );
}

echo "\nDOES THE SHOP ACCEPT THE DELETION NOTICE?\n" . str_repeat( '=', 100 ) . "\n";

// --- what the relay used to send ---------------------------------------------
// SELECT site_url FROM sites  ->  relay_signing_key was never in the row.
$row_before = array( 'site_url' => 'https://shop.example' );
$sig_before = relay_sign( $row_before, $PAYLOAD, $TIMESTAMP );

check(
	'BEFORE: shop issued a key, relay signed with the shared secret',
	plugin_accepts( $PER_SITE_KEY, $PAYLOAD, $TIMESTAMP, $sig_before ),
	false
);
echo "         ^ every connected shop answered 401, and nobody was reading it.\n\n";

// --- what it sends now --------------------------------------------------------
// SELECT site_url, relay_signing_key FROM sites
$row_after = array( 'site_url' => 'https://shop.example', 'relay_signing_key' => $PER_SITE_KEY );
$sig_after = relay_sign( $row_after, $PAYLOAD, $TIMESTAMP );

check(
	'AFTER: relay signs with the key the shop was issued',
	plugin_accepts( $PER_SITE_KEY, $PAYLOAD, $TIMESTAMP, $sig_after ),
	true
);

// --- a shop from before per-site keys existed ---------------------------------
$row_legacy = array( 'site_url' => 'https://old.example', 'relay_signing_key' => '' );
$sig_legacy = relay_sign( $row_legacy, $PAYLOAD, $TIMESTAMP );

check(
	'LEGACY: no key issued, still signed with the shared secret',
	$sig_legacy === hash_hmac( 'sha256', $PAYLOAD . $TIMESTAMP, RELAY_SECRET ),
	true
);
check(
	'  and a legacy shop holding the shared secret still accepts it',
	plugin_accepts( RELAY_SECRET, $PAYLOAD, $TIMESTAMP, $sig_legacy ),
	true
);
check(
	'  so nothing that worked before stops working',
	$sig_legacy === $sig_before,
	true
);

echo "\nAND IT IS STILL A REAL SIGNATURE CHECK\n" . str_repeat( '=', 100 ) . "\n";

check( 'another shop\'s key is refused',
	plugin_accepts( 'a_different_sites_key_entirely', $PAYLOAD, $TIMESTAMP, $sig_after ), false );

check( 'a tampered body is refused',
	plugin_accepts( $PER_SITE_KEY, $PAYLOAD . ' ', $TIMESTAMP, $sig_after ), false );

check( 'a replayed timestamp is refused',
	plugin_accepts( $PER_SITE_KEY, $PAYLOAD, $TIMESTAMP + 1, $sig_after ), false );

check( 'an empty signature is refused',
	plugin_accepts( $PER_SITE_KEY, $PAYLOAD, $TIMESTAMP, '' ), false );

check( 'a shop with no key configured accepts nothing',
	plugin_accepts( '', $PAYLOAD, $TIMESTAMP, $sig_after ), false );

// The two keys must genuinely differ, or the whole test proves nothing.
check( 'the per-site key and the shared secret are not the same value',
	$PER_SITE_KEY !== RELAY_SECRET, true );
check( '  and they produce different signatures',
	$sig_before !== $sig_after, true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
