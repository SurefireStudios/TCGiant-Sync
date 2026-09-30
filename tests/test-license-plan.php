<?php
/**
 * Harness: which plan a licence is on.
 *
 * The owner noticed monthly subscribers were not showing on the dashboard. The
 * dashboard was fine; it was being told they were annual.
 *
 * Variant detection had exactly two outcomes - lifetime when the variant name
 * said so, and annual for everything else - and Pro Monthly is a real plan sold
 * on the pricing page next to Pro Annual. Every monthly subscriber fell into
 * "everything else", so the monthly count was always zero and each of them was
 * counted at $49 of annual revenue instead of $5 of monthly. The declaration in
 * the licence class admitted as much: `'variant' => '', // annual, lifetime`.
 *
 * Detecting monthly only fixes new activations. What fixes the subscribers who
 * already exist is validate_license() re-reading the plan: it used to keep
 * nothing from the reply but the word "valid", so a licence recorded under the
 * wrong plan stayed wrong for as long as it lived.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-64s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// ---- the detector, verbatim ------------------------------------------------------------

function detect_variant( $meta, $body = array() ) {
	$meta = is_array( $meta ) ? $meta : array();
	$body = is_array( $body ) ? $body : array();

	$name = strtolower( (string) ( $meta['variant_name'] ?? '' ) );

	if ( '' !== $name ) {
		if ( false !== strpos( $name, 'lifetime' ) || false !== strpos( $name, 'founder' ) ) {
			return 'lifetime';
		}

		if ( false !== strpos( $name, 'month' ) ) {
			return 'monthly';
		}

		if ( false !== strpos( $name, 'annual' ) || false !== strpos( $name, 'year' ) ) {
			return 'annual';
		}
	}

	$expires = '';

	foreach ( array( $meta['expires_at'] ?? '', $body['license_key']['expires_at'] ?? '', $body['expires_at'] ?? '' ) as $candidate ) {
		if ( ! empty( $candidate ) ) {
			$expires = (string) $candidate;
			break;
		}
	}

	if ( '' === $expires ) {
		return '';
	}

	$days = ( strtotime( $expires ) - time() ) / DAY_IN_SECONDS;

	if ( $days <= 0 ) {
		return '';
	}

	return ( $days <= 62 ) ? 'monthly' : 'annual';
}

function in_days( $n ) {
	return gmdate( 'Y-m-d H:i:s', time() + ( $n * DAY_IN_SECONDS ) );
}

echo "\nTHE PLANS AS THEY ARE ACTUALLY SOLD\n" . str_repeat( '=', 104 ) . "\n";

// Free $0, Monthly $5, Annual $49, Lifetime $99. The first two names are what
// the pricing page calls them.
check( 'Pro Monthly is monthly', detect_variant( array( 'variant_name' => 'Pro Monthly' ) ), 'monthly' );
check( 'Pro Annual is annual', detect_variant( array( 'variant_name' => 'Pro Annual' ) ), 'annual' );
check( 'Lifetime is lifetime', detect_variant( array( 'variant_name' => 'Lifetime' ) ), 'lifetime' );

// This was the whole bug: anything unrecognised became annual.
check( 'and monthly is no longer read as annual', detect_variant( array( 'variant_name' => 'Pro Monthly' ) ) === 'annual', false );

echo "\nNAMES THEY MIGHT BE GIVEN INSTEAD\n" . str_repeat( '=', 104 ) . "\n";

check( 'billed monthly', detect_variant( array( 'variant_name' => 'TCGiant Sync Pro - billed monthly' ) ), 'monthly' );
check( 'per month', detect_variant( array( 'variant_name' => 'Pro (per month)' ) ), 'monthly' );
check( 'yearly', detect_variant( array( 'variant_name' => 'Pro Yearly' ) ), 'annual' );
check( '1 year', detect_variant( array( 'variant_name' => 'Pro 1 Year' ) ), 'annual' );

// A founder plan is a lifetime one whatever else its name contains, which is
// why that test comes first.
check( 'Founder Monthly is still lifetime', detect_variant( array( 'variant_name' => 'Founder Monthly' ) ), 'lifetime' );
check( 'Lifetime Annual is still lifetime', detect_variant( array( 'variant_name' => 'Lifetime Annual' ) ), 'lifetime' );

echo "\nWHEN THE NAME SETTLES NOTHING, THE RENEWAL DATE DOES\n" . str_repeat( '=', 104 ) . "\n";

// So this keeps working if the plans are ever renamed.
check( 'a renewal a month out is monthly', detect_variant( array( 'variant_name' => 'Starter', 'expires_at' => in_days( 30 ) ) ), 'monthly' );
check( 'a renewal a year out is annual', detect_variant( array( 'variant_name' => 'Starter', 'expires_at' => in_days( 365 ) ) ), 'annual' );
check( 'a renewal read from the licence object', detect_variant( array(), array( 'license_key' => array( 'expires_at' => in_days( 28 ) ) ) ), 'monthly' );

// Generous either side of a month: a renewal can be prorated, paused, or
// pushed out by a trial.
check( 'six weeks out is still monthly', detect_variant( array( 'expires_at' => in_days( 42 ) ) ), 'monthly' );
check( 'ninety days out is annual', detect_variant( array( 'expires_at' => in_days( 90 ) ) ), 'annual' );

echo "\nAND WHEN NOTHING SETTLES IT, IT SAYS SO\n" . str_repeat( '=', 104 ) . "\n";

// Quietly picking the middle tier is the mistake being fixed, so the detector
// declines rather than guesses, and the callers keep whatever is stored.
check( 'an unrecognised name with no date is undecided', detect_variant( array( 'variant_name' => 'Starter' ) ), '' );
check( 'nothing at all is undecided', detect_variant( array() ), '' );
check( 'an expiry already past is undecided', detect_variant( array( 'expires_at' => in_days( -5 ) ) ), '' );
check( 'rubbish is undecided', detect_variant( 'not an array' ), '' );

echo "\nWHAT THE SOURCE MUST KEEP DOING\n" . str_repeat( '=', 104 ) . "\n";

$root    = dirname( __DIR__ );
$license = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-license.php' );

check( 'there is one detector, not two copies', substr_count( $license, 'private function detect_variant' ), 1 );
check( '  used when a licence is activated', false !== strpos( $license, '$variant = $this->detect_variant($meta, $body);' ), true );
check( '  and again every time it is validated', false !== strpos( $license, "\$variant = \$this->detect_variant(\$body['meta'] ?? array(), \$body);" ), true );

// The half that fixes subscribers who already exist. Without it, detection
// only helps people who re-enter their key.
check( 'validation stores the plan it just read', false !== strpos( $license, "\$fresh['variant'] = \$variant;" ), true );
check( '  and the renewal date with it', false !== strpos( $license, "\$fresh['expires_at'] = (string) \$expires;" ), true );
check( '  but never erases a good value with an absent one', false !== strpos( $license, 'Only when offered. An absent field must not erase a good value.' ), true );

// The old default is what made this silent.
check( 'nothing defaults to annual any more', false === strpos( $license, "\$variant = 'annual';" ), true );
check( '  and an undecidable plan is reported', false !== strpos( $license, 'Could not tell which plan this licence is on' ), true );
check( 'the declared set names monthly', false !== strpos( $license, "'variant' => '', // monthly, annual, lifetime" ), true );

echo "\nAND A PAYING CUSTOMER IS NEVER FILED AS FREE\n" . str_repeat( '=', 104 ) . "\n";

$site = dirname( __DIR__, 2 ) . '/website';

if ( ! is_dir( $site . '/syncconnect' ) ) {
	echo "  SKIPPED - the dashboard is not in this checkout (it is deployed by hand).\n";
} else {
	$dashboard = (string) file_get_contents( $site . '/syncconnect/dashboard.php' );

	// The plugin sends 'pro' when a licence is active but its plan could not be
	// read, and the dashboard added anything unrecognised to the free bucket -
	// so a paying customer counted as zero and looked like a free user.
	check( 'an unrecognised plan has a bucket of its own', false !== strpos( $dashboard, "'unknown' => 0]" ), true );
	check( '  and is not added to free', false === strpos( $dashboard, "\$license_counts['free'] += (int)\$lc_row['cnt'];" ), true );
	check( '  it is priced at nothing, deliberately', false !== strpos( $dashboard, "'unknown'  => 0," ), true );
	check( '  and shown, so the gap is not silent', false !== strpos( $dashboard, 'unreadable plan' ), true );
	check( 'every badge class rendered actually exists', false !== strpos( $dashboard, "in_array(\$lic, ['free', 'monthly', 'annual', 'lifetime'], true)" ), true );

	// The four prices, confirmed against the pricing page.
	foreach ( array( "'lifetime' => 99," => 'lifetime $99', "'annual'   => 49," => 'annual $49', "'monthly'  => 5," => 'monthly $5', "'free'     => 0," => 'free $0' ) as $needle => $label ) {
		check( 'the dashboard prices ' . $label, false !== strpos( $dashboard, $needle ), true );
	}
}

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
