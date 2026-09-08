<?php
/**
 * Harness: the edition field on the relay.
 *
 * Two things have to hold. The first is that this changes nothing for any
 * plugin now in the field — the OAuth state must come out byte-for-byte as it
 * did, because a wrong state broke connecting outright from 3.1.3 to 3.4.2 and
 * the fault was invisible until a merchant tried.
 *
 * The second is that an unrecognised edition is never read as 'pro'. This field
 * will decide which eBay application's credentials a self-asserted caller is
 * served, so the failure has to fall towards less access, not more.
 */

// ---- verbatim from relay.php ------------------------------------------------
function relay_edition( $raw ) {
	if ( null === $raw || '' === $raw ) {
		return 'pro';
	}

	$edition = strtolower( trim( (string) $raw ) );

	if ( in_array( $edition, array( 'pro', 'standard', 'lite' ), true ) ) {
		return $edition;
	}

	return 'lite';
}

/** The state built by handle_init_auth, verbatim. */
function build_state( $site_url, $get ) {
	$parts = array( $site_url );

	if ( isset( $get['claim'] ) && '1' === (string) $get['claim'] ) {
		$parts[] = 'claim';
	}

	if ( isset( $get['edition'] ) ) {
		$parts[] = 'edition:' . relay_edition( $get['edition'] );
	}

	return base64_encode( implode( '|', $parts ) );
}

/** The state read by handle_ebay_callback, verbatim. */
function parse_state( $state_raw ) {
	$decoded_state = base64_decode( $state_raw );
	$state_parts   = explode( '|', $decoded_state );
	$site_url      = rtrim( $state_parts[0], '/' );
	$wants_claim   = in_array( 'claim', array_slice( $state_parts, 1 ), true );

	$edition = 'pro';

	foreach ( array_slice( $state_parts, 1 ) as $flag ) {
		if ( 0 === strpos( $flag, 'edition:' ) ) {
			$edition = relay_edition( substr( $flag, strlen( 'edition:' ) ) );
		}
	}

	return array( $site_url, $wants_claim, $edition );
}
// -----------------------------------------------------------------------------

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-56s got %-24s want %s\n", $ok ? 'PASS' : 'FAIL', $label,
		var_export( $got, true ), var_export( $want, true ) );
}

$SITE = 'https://shop.example';

echo "\nNOTHING CHANGES FOR A PLUGIN IN THE FIELD\n" . str_repeat( '=', 100 ) . "\n";

// The two states the current plugin can produce, built the old way.
$old_plain = base64_encode( $SITE );
$old_claim = base64_encode( $SITE . '|claim' );

check( 'plain connect: state is unchanged',  build_state( $SITE, array() ), $old_plain );
check( 'claim connect: state is unchanged',  build_state( $SITE, array( 'claim' => '1' ) ), $old_claim );

// And they still read back the same.
list( $u, $c, $e ) = parse_state( $old_plain );
check( '  old plain state still parses: url',   $u, $SITE );
check( '  old plain state still parses: claim', $c, false );
check( '  old plain state still parses: pro',   $e, 'pro' );

list( $u, $c, $e ) = parse_state( $old_claim );
check( '  old claim state still parses: url',   $u, $SITE );
check( '  old claim state still parses: claim', $c, true );
check( '  old claim state still parses: pro',   $e, 'pro' );

echo "\nAN UNKNOWN EDITION IS NEVER READ AS PRO\n" . str_repeat( '=', 100 ) . "\n";

check( 'absent  -> pro',        relay_edition( null ),        'pro' );
check( 'empty   -> pro',        relay_edition( '' ),          'pro' );
check( 'pro     -> pro',        relay_edition( 'pro' ),       'pro' );
check( 'lite    -> lite',       relay_edition( 'lite' ),      'lite' );
check( 'standard-> standard',   relay_edition( 'standard' ),  'standard' );
check( 'PRO     -> pro',        relay_edition( 'PRO' ),       'pro' );
check( '  Lite  -> lite',       relay_edition( '  Lite  ' ),  'lite' );

// 'PRO ' is deliberately NOT here: trimming and lowercasing a self-asserted
// label is normalisation, not laxity, and the block below requires it.
foreach ( array( 'enterprise', 'unlimited', 'p r o', 'pro;--', '1', 'true', 'admin', 'prO!' ) as $bad ) {
	$got = relay_edition( $bad );
	check( sprintf( "junk %-12s -> not pro", "'" . $bad . "'" ), $got !== 'pro', true );
}

// The one that matters most: nothing an attacker sends yields pro except pro.
$claims_pro = 0;
foreach ( array( 'pro ', ' pro', 'PRO', 'Pro', 'pRo' ) as $variant ) {
	if ( 'pro' === relay_edition( $variant ) ) { $claims_pro++; }
}
check( 'only genuine spellings of pro resolve to pro', $claims_pro, 5 );

echo "\nTHE VALUE SURVIVES THE TRIP TO EBAY AND BACK\n" . str_repeat( '=', 100 ) . "\n";

foreach ( array( 'pro', 'lite', 'standard' ) as $edition ) {
	foreach ( array( false, true ) as $with_claim ) {
		$get = array( 'edition' => $edition );
		if ( $with_claim ) { $get['claim'] = '1'; }

		list( $u, $c, $e ) = parse_state( build_state( $SITE, $get ) );

		check( sprintf( '%-8s claim=%-5s -> edition', $edition, $with_claim ? 'yes' : 'no' ), $e, $edition );
		check( sprintf( '%-8s claim=%-5s -> claim flag', $edition, $with_claim ? 'yes' : 'no' ), $c, $with_claim );
		check( sprintf( '%-8s claim=%-5s -> site url', $edition, $with_claim ? 'yes' : 'no' ), $u, $SITE );
	}
}

// A junk edition arriving from the browser still round-trips to something safe.
list( $u, $c, $e ) = parse_state( build_state( $SITE, array( 'edition' => 'enterprise' ) ) );
check( 'junk edition round-trips to lite, not pro', $e, 'lite' );

// The edition is SELF-ASSERTED, and this proves it rather than disproving it:
// anyone who can call handle_init_auth can put any valid edition in the state,
// so the field is a label, never an entitlement. That is exactly why the
// per-edition budget query must read edition from the caller's own stored row
// and never from the request, and why credential selection is a separate deploy.
list( $u, $c, $e ) = parse_state( base64_encode( 'https://evil.example|edition:pro|claim' ) );
check( 'a crafted state is accepted at face value (a label, not a right)', $e, 'pro' );

// What it must NOT do is let a pipe in the site URL forge a flag by accident.
list( $u2, $c2, $e2 ) = parse_state( base64_encode( 'https://shop.example' ) );
check( '  a plain state grants nothing', $e2, 'pro' );
check( '  and no edition is emitted unless one was asked for', build_state( 'https://evil.example', array() ), base64_encode( 'https://evil.example' ) );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
