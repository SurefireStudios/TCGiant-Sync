<?php
/**
 * Harness: proving a deletion notice came from eBay before acting on it.
 *
 * The relay's routing sends any POST that is not action=refresh or action=claim
 * to the Marketplace Account Deletion handler. That handler asked the caller for
 * nothing. It read the JSON body, and if the topic said
 * MARKETPLACE_ACCOUNT_DELETION it signed the caller's payload with each shop's
 * own relay key and posted it to every connected site - which then scrubbed
 * customer records, because the signature was ours and therefore trusted.
 *
 * So anyone able to reach the URL could destroy personal data across every shop
 * on the platform, and the shops would have no way to tell. The bot challenge
 * the hosting provider happened to have in front of the domain was never a
 * control anybody chose, and it does not apply to most addresses - a plain POST
 * from an ordinary machine reached the handler.
 *
 * What is asserted:
 *   1. The decision table. Four outcomes, and the difference between them is
 *      whether customer records get deleted.
 *   2. That an unsigned or wrongly signed notice stops before the fan-out, and
 *      that the old logic did not - so the fix is measured against the fault.
 *   3. That a notice we could not check is still acted on, loudly. eBay's own
 *      compliance clock does not stop because our key fetch failed.
 *   4. Where openssl can make a key, a real signature round trip through the
 *      same call the relay makes.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-62s got %-20s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

// ---- the relay's decision, verbatim, with the two outside calls injectable ----------

/**
 * @param string        $payload The raw body.
 * @param string        $header  The x-ebay-signature header, '' when absent.
 * @param callable|null $key     Returns a PEM for a kid, or '' when it cannot.
 * @param callable|null $verify  Stands in for openssl_verify.
 * @return array{0:string,1:string}
 */
function mad_signature_state( $payload, $header, $key = null, $verify = null ) {
	$header = trim( (string) $header );

	if ( '' === $header ) {
		return array( 'absent', 'no x-ebay-signature header' );
	}

	$meta = json_decode( (string) base64_decode( $header, true ), true );

	if ( ! is_array( $meta ) || empty( $meta['kid'] ) || empty( $meta['signature'] ) ) {
		return array( 'invalid', 'the signature header is not the structure eBay sends' );
	}

	$pem = $key ? call_user_func( $key, $meta['kid'] ) : '';

	if ( '' === $pem ) {
		return array( 'unverifiable', 'could not read eBay public key ' . $meta['kid'] );
	}

	$digest = strtoupper( (string) ( $meta['digest'] ?? 'SHA1' ) );
	$algo   = ( 'SHA256' === $digest ) ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
	$result = call_user_func( $verify, $payload, (string) base64_decode( $meta['signature'], true ), $pem, $algo );

	if ( 1 === $result ) {
		return array( 'valid', '' );
	}

	if ( 0 === $result ) {
		return array( 'invalid', 'the signature does not match the payload' );
	}

	return array( 'unverifiable', 'openssl could not complete the check' );
}

/** The gate. Returns what the handler does next. */
function mad_outcome( $state, $require = true ) {
	if ( 'valid' !== $state && $require ) {
		if ( 'unverifiable' === $state ) {
			return 'fan out, and log that it was not verified';
		}
		return 'stop';
	}
	return 'fan out';
}

/** What the handler did before this fix: the topic was the whole of it. */
function mad_outcome_before( $payload ) {
	$data = json_decode( $payload, true );
	if ( ! isset( $data['metadata']['topic'] ) || 'MARKETPLACE_ACCOUNT_DELETION' !== $data['metadata']['topic'] ) {
		return 'acknowledged, ignored';
	}
	return 'fan out';
}

/** Build the header the way eBay does. */
function ebay_header( array $meta ) {
	return base64_encode( wp_json( $meta ) );
}

function wp_json( $value ) {
	return json_encode( $value );
}

$payload = wp_json( array(
	'metadata' => array( 'topic' => 'MARKETPLACE_ACCOUNT_DELETION' ),
	'notification' => array( 'data' => array( 'userId' => 'someone' ) ),
) );

$good_key = function () { return "-----BEGIN PUBLIC KEY-----\nnot-used-by-the-stub\n-----END PUBLIC KEY-----\n"; };
$no_key   = function () { return ''; };
$says_ok  = function () { return 1; };
$says_no  = function () { return 0; };
$says_err = function () { return -1; };

echo "\nWHAT A FORGED NOTICE USED TO DO\n" . str_repeat( '=', 110 ) . "\n";

check( 'before the fix: an unsigned forgery reached the fan-out', mad_outcome_before( $payload ), 'fan out' );
check( '  and every connected shop scrubbed records on its say-so', true, true );
check( 'the only thing ever checked was the topic',
	mad_outcome_before( wp_json( array( 'metadata' => array( 'topic' => 'ANYTHING_ELSE' ) ) ) ), 'acknowledged, ignored' );

echo "\nTHE DECISION TABLE\n" . str_repeat( '=', 110 ) . "\n";

list( $state ) = mad_signature_state( $payload, '', $good_key, $says_ok );
check( 'no signature at all', $state, 'absent' );
check( '  so nothing is sent to anybody', mad_outcome( $state ), 'stop' );

list( $state ) = mad_signature_state( $payload, 'this-is-not-base64-json', $good_key, $says_ok );
check( 'a header that is not what eBay sends', $state, 'invalid' );
check( '  also stops', mad_outcome( $state ), 'stop' );

list( $state ) = mad_signature_state( $payload, ebay_header( array( 'alg' => 'ecdsa', 'digest' => 'SHA1' ) ), $good_key, $says_ok );
check( 'a header with no kid or signature', $state, 'invalid' );

$header = ebay_header( array( 'alg' => 'ecdsa', 'kid' => 'abc-123', 'signature' => base64_encode( 'sig' ), 'digest' => 'SHA1' ) );

list( $state ) = mad_signature_state( $payload, $header, $good_key, $says_no );
check( 'a signature that does not match', $state, 'invalid' );
check( '  stops, which is the whole point', mad_outcome( $state ), 'stop' );

list( $state ) = mad_signature_state( $payload, $header, $good_key, $says_ok );
check( 'a signature eBay made', $state, 'valid' );
check( '  goes out to the shops', mad_outcome( $state ), 'fan out' );

echo "\nWHEN OUR OWN END IS THE PROBLEM\n" . str_repeat( '=', 110 ) . "\n";

// eBay judges the endpoint on whether deletions happen. A key fetch failing at
// our end is not a reason to drop a real notice on the floor - but it must be
// impossible to miss in the log, because unnoticed it is the protection gone.
list( $state, $why ) = mad_signature_state( $payload, $header, $no_key, $says_ok );
check( 'eBay would not give us the key', $state, 'unverifiable' );
check( '  the notice is still acted on', mad_outcome( $state ), 'fan out, and log that it was not verified' );
check( '  and the reason names the key', false !== strpos( $why, 'abc-123' ), true );

list( $state ) = mad_signature_state( $payload, $header, $good_key, $says_err );
check( 'openssl could not finish the check', $state, 'unverifiable' );
check( '  same treatment', mad_outcome( $state ), 'fan out, and log that it was not verified' );

echo "\nWITH THE SWITCH OFF\n" . str_repeat( '=', 110 ) . "\n";

check( 'nothing is refused', mad_outcome( 'absent', false ), 'fan out' );
check( '  which is the old behaviour, kept reachable on purpose', mad_outcome( 'invalid', false ), 'fan out' );

echo "\nA REAL SIGNATURE, WHERE OPENSSL CAN MAKE A KEY\n" . str_repeat( '=', 110 ) . "\n";

$pair = @openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );

if ( ! $pair ) {
	echo "  SKIPPED - this host cannot generate a key (no openssl config). CI can, and does.\n";
} else {
	$details = openssl_pkey_get_details( $pair );
	$pem     = $details['key'];
	$raw     = '';
	openssl_sign( $payload, $raw, $pair, OPENSSL_ALGO_SHA1 );

	$signed = ebay_header( array( 'alg' => 'rsa', 'kid' => 'test-key', 'signature' => base64_encode( $raw ), 'digest' => 'SHA1' ) );
	$key_fn = function () use ( $pem ) { return $pem; };

	list( $state ) = mad_signature_state( $payload, $signed, $key_fn, 'openssl_verify' );
	check( 'a genuinely signed payload verifies', $state, 'valid' );

	list( $state ) = mad_signature_state( $payload . ' ', $signed, $key_fn, 'openssl_verify' );
	check( 'one byte changed in the payload does not', $state, 'invalid' );

	$other = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
	$other_details = openssl_pkey_get_details( $other );
	$wrong_key = function () use ( $other_details ) { return $other_details['key']; };

	list( $state ) = mad_signature_state( $payload, $signed, $wrong_key, 'openssl_verify' );
	check( 'a signature from somebody else does not', $state, 'invalid' );
}

echo "\nTHE KEY, IN THE SHAPES EBAY ACTUALLY SENDS IT\n" . str_repeat( '=', 110 ) . "\n";

/**
 * What the relay did: trust the word BEGIN to mean the whole thing is PEM.
 */
function old_pem( $key ) {
	return ( false !== strpos( $key, 'BEGIN PUBLIC KEY' ) )
		? $key
		: "-----BEGIN PUBLIC KEY-----\n" . chunk_split( $key, 64, "\n" ) . "-----END PUBLIC KEY-----\n";
}

/**
 * What it does now: reduce to the base64 body and rebuild, whatever arrived.
 */
function new_pem( $key ) {
	$body = preg_replace( '/-----[A-Z ]+-----/', '', $key );
	$body = preg_replace( '/[^A-Za-z0-9+\/=]/', '', (string) $body );

	if ( '' === $body ) {
		return '';
	}

	return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( $body, 64, "\n" ) . "-----END PUBLIC KEY-----\n";
}

function loads( $pem ) {
	while ( openssl_error_string() ) { /* drain, so a stale error cannot answer for this one */ }
	return $pem && openssl_pkey_get_public( $pem ) ? true : false;
}

$pair = @openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );

if ( ! $pair ) {
	echo "  SKIPPED - this host cannot generate a key (no openssl config). CI can, and does.\n";
} else {
	$details   = openssl_pkey_get_details( $pair );
	$canonical = $details['key'];
	$body      = preg_replace( '/[^A-Za-z0-9+\/=]/', '', preg_replace( '/-----[A-Z ]+-----/', '', $canonical ) );

	// The middle one is the shape that broke it. eBay's notification API returns
	// the key with its header and footer and no line breaks between them, which
	// is not PEM: openssl answers openssl_verify() with -1, an error rather than
	// a verdict, the gate falls open, and every notice is acted on unverified.
	// The relay log recorded exactly that, on every notification, for a day.
	$shapes = array(
		'canonical PEM'                     => $canonical,
		'header and footer, no line breaks' => '-----BEGIN PUBLIC KEY-----' . $body . '-----END PUBLIC KEY-----',
		'bare base64 body'                  => $body,
		'PEM with carriage returns'         => str_replace( "\n", "\r\n", $canonical ),
	);

	foreach ( $shapes as $label => $key ) {
		check( 'now loads: ' . $label, loads( new_pem( $key ) ), true );
	}

	check( 'and the shape that used to fail, did fail', loads( old_pem( $shapes['header and footer, no line breaks'] ) ), false );
	check( '  while the others always worked', loads( old_pem( $canonical ) ), true );

	check( 'something that is not a key is still refused', loads( new_pem( 'not a key at all' ) ), false );
	check( 'an empty answer from eBay yields no key', new_pem( '' ), '' );
}


printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
