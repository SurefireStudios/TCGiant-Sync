<?php
/**
 * Harness: taking a shop out of the deletion fan-out, and putting it back.
 *
 * A former customer wrote in: "Please purge and unregister the webhook endpoint
 * for mikestrading.com from your TCGiant-Sync-Relay/2.1 dispatch queue." They
 * could name our User-Agent because they were reading it out of their own
 * access log. Their site answered our route with 404 - the plugin was gone -
 * and the fan-out went on posting a signed request to it roughly every forty
 * seconds, because nothing in the relay, the dashboard or the plugin had ever
 * been able to take a site off that list. The payload names an eBay buyer, so
 * this was somebody else's personal data arriving at a third party with no
 * relationship to us, indefinitely.
 *
 * The risk runs the other way, and the first attempt at this got it wrong four
 * times over. A notice we fail to deliver to a shop that IS connected is a
 * compliance failure with eBay, and review found that the first cut could
 * silence a live shop by: counting any non-2xx as an uninstall; counting a
 * failure at our own end against every site at once; deleting a row on a
 * signature that a restored database backup can produce; and depending on a
 * column that a lost migration race would leave missing, which would have
 * stopped notices to everyone.
 *
 * So nearly all of the assertions below are about the asymmetry: only evidence
 * the plugin is gone may make us quieter, and nothing is ever unrecoverable.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-70s got %-18s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$site = dirname( __DIR__, 2 ) . '/website';

if ( ! is_dir( $site . '/syncconnect' ) ) {
	echo "\n  SKIPPED - the relay is not in this checkout (it is deployed by hand).\n\n";
	echo "  0 passed, 0 failed\n\n";
	exit( 0 );
}

$relay     = (string) file_get_contents( $site . '/syncconnect/relay.php' );
$dashboard = (string) file_get_contents( $site . '/syncconnect/dashboard.php' );
$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

echo "\nONLY AN UNINSTALL MAY MAKE US QUIETER\n" . str_repeat( '=', 118 ) . "\n";

// Verbatim replica of the two rules that decide whether a failure counts.
const GONE_CODES           = array( 404, 410 );
const DORMANT_AFTER_SECS   = 86400;
const DORMANT_AFTER_FAILS  = 200;

function counts_as_gone( $code ) {
	return in_array( (int) $code, GONE_CODES, true );
}

function should_set_aside( $code, $consecutive, $failing_for ) {
	return counts_as_gone( $code )
		&& $consecutive >= DORMANT_AFTER_FAILS
		&& $failing_for >= DORMANT_AFTER_SECS;
}

$day = 86400;

// The route is not registered. That is what an uninstalled plugin looks like.
check( 'a 404 for a day sets a shop aside', should_set_aside( 404, 2160, $day ), true );
check( '  and so does a 410', should_set_aside( 410, 2160, $day ), true );

// Everything else means something is wrong, which is not the same as gone.
// Each of these is a LIVE shop, and silencing one is the compliance failure.
check( 'a 401 signature mismatch never does', should_set_aside( 401, 9999, 30 * $day ), false );
check( 'a WAF 403 never does', should_set_aside( 403, 9999, 30 * $day ), false );
check( 'a 500 from an unrelated plugin never does', should_set_aside( 500, 9999, 30 * $day ), false );
check( 'a 502 through a long outage never does', should_set_aside( 502, 9999, 30 * $day ), false );
check( 'a 429 never does', should_set_aside( 429, 9999, 30 * $day ), false );
check( 'a connection that never completed never does', should_set_aside( 0, 9999, 30 * $day ), false );
check( 'a redirect never does', should_set_aside( 301, 9999, 30 * $day ), false );

// And the clock still governs the codes that do count.
check( 'a two-hour run of 404s is not enough', should_set_aside( 404, 180, 2 * 3600 ), false );
check( 'nor 404s just short of a day', should_set_aside( 404, 2000, 23 * 3600 ), false );
check( 'nor a day with too few attempts to be sure', should_set_aside( 404, 30, 3 * $day ), false );

check( 'the rule is in the source, not only here', false !== strpos( $relay, "define( 'MAD_GONE_CODES', array( 404, 410 ) );" ), true );
check( '  and it gates the count', false !== strpos( $relay, 'if ( ! in_array( (int) $code, MAD_GONE_CODES, true ) ) {' ), true );

echo "\nIF NOTHING ANSWERED ANYWHERE, IT IS US\n" . str_repeat( '=', 118 ) . "\n";

/** Verbatim: a round in which no site succeeded accrues nothing. */
function round_is_our_fault( array $codes ) {
	if ( count( $codes ) <= 1 ) {
		return false;
	}

	foreach ( $codes as $c ) {
		if ( $c >= 200 && $c < 300 ) {
			return false;
		}
	}

	return true;
}

// A broken resolver, blocked egress or an expired CA bundle answers the same
// for every site at once. Counting that would set aside the whole customer
// base after a day of it.
check( 'a round where nothing answered is our fault', round_is_our_fault( array( 0, 0, 0, 0 ) ), true );
check( '  even when the codes look like real answers', round_is_our_fault( array( 404, 404, 404 ) ), true );
check( 'one success means the rest are genuinely theirs', round_is_our_fault( array( 200, 404, 404 ) ), false );
check( 'a single-site install is never judged this way', round_is_our_fault( array( 404 ) ), false );

check( 'the guard is in the source', false !== strpos( $relay, 'if ( ! $any_success && count( $outcomes ) > 1 ) {' ), true );
check( '  and it holds the counts where they are', false !== strpos( $relay, "\$GLOBALS['mad_failures'] = mad_previous_failures( \$state_file );" ), true );
check( '  and says so', false !== strpos( $relay, 'Treating that as a fault at this end' ), true );

echo "\nNOTHING IS UNRECOVERABLE\n" . str_repeat( '=', 118 ) . "\n";

// The per-site key and the site URL live side by side in wp_options, so any
// copy of a shop's database carries both. Deleting the plugin on a restored
// backup would sign the PRODUCTION url with the PRODUCTION key - and a DELETE
// would have unsubscribed the live shop for good, silently, because nothing
// re-inserts a row but a full OAuth reconnect.
check( 'the relay never deletes a site', 0, substr_count( $relay, 'DELETE FROM sites' ) );
check( '  the dashboard does not either', 0, substr_count( $dashboard, 'DELETE FROM sites' ) );
check( 'deregister sets the site aside instead', false !== strpos( $relay, "mad_set_dormant( \$db, (string) \$row['site_url'], true );" ), true );
check( '  and says so in its reply', false !== strpos( $relay, "'status' => 'set_aside'" ), true );
check( '  so the same mistake costs an hour, not a customer', false !== strpos( $relay, 'restored if it answers' ), true );

check( 'a site set aside is still tried hourly', false !== strpos( $relay, "define( 'MAD_DORMANT_RETRY_SECONDS', 3600 );" ), true );
check( '  and restored the moment it answers', false !== strpos( $relay, 'is answering again and has been restored' ), true );
check( 'reconnecting restores it at once', false !== strpos( $relay, 'dormant_since = 0, last_probe = 0 WHERE site_url = :url' ), true );

// A shop refreshing its eBay token through this relay is installed, connected
// and running - the strongest liveness signal there is.
check( 'a token refresh restores it too', false !== strpos( $relay, 'refreshed its token, so it is live and has been restored' ), true );
check( '  never at the cost of the refresh itself', false !== strpos( $relay, '// Never at the cost of the refresh itself.' ), true );

echo "\nA MISSING COLUMN MUST NOT SILENCE EVERYONE\n" . str_repeat( '=', 118 ) . "\n";

// The two columns are last in the migration list, and the catch used to sit
// outside the loop - so one lost ALTER race skipped them, and a SELECT naming
// them would throw inside a catch that swallows it. Notices to every shop,
// gone, quietly.
check( 'each column migrates on its own', false !== strpos( $relay, 'Migration skipped for ' ), true );
check( '  so one failure cannot skip the rest', strpos( $relay, 'foreach ( $migrations as $col => $sql ) {' ) < strpos( $relay, "\t\ttry {" ), true );
check( 'the fan-out checks the columns exist', false !== strpos( $relay, "\$have_dormancy = (int) \$db->querySingle(" ), true );
check( '  and sends to everybody when they do not', false !== strpos( $relay, "\$db->query( 'SELECT site_url, relay_signing_key, 0 AS dormant_since FROM sites' )" ), true );
check( '  a NULL never drops a site from the fan-out', false !== strpos( $relay, 'COALESCE(dormant_since, 0) = 0 OR COALESCE(last_probe, 0) <' ), true );
check( 'the dashboard migrates what it renders', false !== strpos( $dashboard, "'dormant_since'   => \"ALTER TABLE sites ADD COLUMN dormant_since INTEGER DEFAULT 0\"," ), true );

echo "\nA LIVE SHOP BEHIND A REDIRECT IS NOT A DEAD ONE\n" . str_repeat( '=', 118 ) . "\n";

// Shops that moved to https or added www answered 301 forever and were scored
// as failures, so a willing site never received its notice.
//
// The first attempt let curl follow, with CURLOPT_REDIR_PROTOCOLS set to https
// and a check of the final host afterwards. That check is too late: the option
// constrains the SCHEME and not the host, so curl had already POSTed the
// payload - an eBay buyer's details, signed with that shop's key - to whatever
// host the redirect named. You cannot unsend it by noticing.
check( 'curl is not allowed to follow', false !== strpos( $relay, 'CURLOPT_FOLLOWLOCATION, false' ), true );
// Comments stripped: the note explaining this very fault names the options it
// warns about, and must not be allowed to answer for the code.
$relay_code = php_strip_whitespace( $site . '/syncconnect/relay.php' );
check( '  and nothing configures it to', substr_count( $relay_code, 'CURLOPT_POSTREDIR' ) + substr_count( $relay_code, 'CURLOPT_REDIR_PROTOCOLS' ), 0 );
check( 'where it points is read without going there', false !== strpos( $relay, 'CURLINFO_REDIRECT_URL' ), true );
check( '  and only then is a second request made', false !== strpos( $relay, '$code = mad_deliver( $moved, $payload, $signature, $timestamp );' ), true );

/** Verbatim replica of which redirects earn a second request. */
function bare_host( $host ) {
	$host = strtolower( $host );

	return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
}

function follow_to( $from, $to ) {
	$t = parse_url( $to );
	$f = parse_url( $from );

	if ( empty( $t['host'] ) || empty( $f['host'] ) ) {
		return false;
	}

	if ( 'https' !== strtolower( (string) ( $t['scheme'] ?? '' ) ) ) {
		return false;
	}

	return bare_host( $t['host'] ) === bare_host( $f['host'] );
}

$from = 'http://shop.test/wp-json/tcgiant-sync/v1/ebay-deletion';

// The two moves shops actually make.
check( 'http to https on the same host is followed', follow_to( $from, 'https://shop.test/wp-json/tcgiant-sync/v1/ebay-deletion' ), true );
check( 'adding www is followed', follow_to( $from, 'https://www.shop.test/wp-json/tcgiant-sync/v1/ebay-deletion' ), true );
check( 'dropping www is followed', follow_to( 'https://www.shop.test/x', 'https://shop.test/x' ), true );

// And the ones that would hand somebody else's buyer details to a third party.
check( 'another domain is refused', follow_to( $from, 'https://evil.test/wp-json/tcgiant-sync/v1/ebay-deletion' ), false );
check( '  including a lookalike subdomain', follow_to( $from, 'https://shop.test.evil.test/x' ), false );
check( '  and one that merely contains the name', follow_to( $from, 'https://notshop.test/x' ), false );
check( 'a redirect down to plain http is refused', follow_to( 'https://shop.test/x', 'http://shop.test/x' ), false );
check( 'a relative or malformed target is refused', follow_to( $from, '/somewhere-else' ), false );

echo "\nAND A STRANGER LEARNS NOTHING\n" . str_repeat( '=', 118 ) . "\n";

// Three distinguishable replies would let anyone walk a list of domains and
// learn which are our customers and which hold no per-site key.
check( 'every refusal answers the same way', 6, substr_count( $relay, "relay_json_out( array( 'error' => 'refused' ), 403 );" ) );
check( '  with the reason in the log only where a row exists', false !== strpos( $relay, "mad_log( 'Deregister refused for ' . \$site_url . ': signature did not match.' );" ), true );
check( 'the signature covers the address and the time', false !== strpos( $relay, "\$expected = hash_hmac( 'sha256', \$site_url . '|' . \$timestamp, \$key );" ), true );
check( '  compared without leaking where it differs', false !== strpos( $relay, 'hash_equals( $expected, $signature )' ), true );
check( '  and goes stale in five minutes', false !== strpos( $relay, 'abs( time() - $timestamp ) > 300' ), true );
check( '  with the input bounded', false !== strpos( $relay, 'strlen( $site_url ) > 512' ), true );

// telemetry.php has matched both spellings for years, which is how we know
// rows exist with a trailing slash.
check( 'both spellings of the address are matched', false !== strpos( $relay, "WHERE site_url = :url OR site_url = :url_slash" ), true );
check( '  by the dashboard too', false !== strpos( $dashboard, "WHERE site_url = :url OR site_url = :url_slash" ), true );

echo "\nTHE DASHBOARD BUTTON\n" . str_repeat( '=', 118 ) . "\n";

check( 'it sets aside rather than deletes', false !== strpos( $dashboard, "isset( \$_POST['set_aside'] )" ), true );
check( '  and can put one back', false !== strpos( $dashboard, "isset( \$_POST['restore_site'] )" ), true );
check( '  behind a token, not just the session cookie', false !== strpos( $dashboard, "hash_equals( (string) \$_SESSION['csrf'], \$token )" ), true );
check( '  generated where it cannot be skipped', false !== strpos( $dashboard, "\$_SESSION['csrf'] = bin2hex( random_bytes( 32 ) );" ), true );

// Rendering the dashboard as the body of the POST makes the history entry a
// POST, so a reload replays the write with no confirmation.
check( '  a reload cannot replay the write', false !== strpos( $dashboard, "header( 'Location: dashboard.php' );" ), true );
check( '  so the result is carried in the session', false !== strpos( $dashboard, "\$_SESSION['notice']" ), true );

// htmlspecialchars is right for an attribute and wrong for a JavaScript string
// literal inside one: the browser decodes &#039; back to an apostrophe before
// the script sees it. site_url arrives from the OAuth state parameter.
check( '  the address never enters the script', false === strpos( $dashboard, "confirm('Remove <?=" ), true );
check( '  it is read from an attribute', 2, substr_count( $dashboard, "form.getAttribute( 'data-site' )" ) );
check( '  and the button names what it will do', false !== strpos( $dashboard, "\$dormant_since ? 'Restore' : 'Set aside'" ), true );
check( 'the try/catch is not decorative', false !== strpos( $dashboard, '$db->enableExceptions(true);' ), true );
check( 'every change is written down', false !== strpos( $dashboard, "' - Dashboard ' . ( \$aside ? 'set aside ' : 'restored ' )" ), true );

echo "\nTHE UNINSTALL THAT MEANS IT, AND THE ONE THAT DOES NOT\n" . str_repeat( '=', 118 ) . "\n";

// Deleting the plugin is how people reinstall it and move between editions;
// the settings survive that on purpose. Acting on every uninstall would take a
// shop that came back out of the fan-out while it carried on working.
$gate      = strpos( $uninstall, "if ( empty( \$tcgiant_settings['delete_data_on_uninstall'] ) ) {" );
$dereg     = strpos( $uninstall, "'action'    => 'deregister'," );
$drop_opts = strpos( $uninstall, "delete_option( \$tcgiant_option );" );

check( 'the uninstaller tells the relay', false !== $dereg, true );
check( '  only when the shop asked for its data to go', $gate < $dereg, true );
check( '  while the key it signs with still exists', $dereg < $drop_opts, true );
check( '  signed the way the relay checks', false !== strpos( $uninstall, "\$tcgiant_site_url . '|' . \$tcgiant_timestamp," ), true );
check( '  and an unreachable relay cannot hold up an uninstall', false !== strpos( $uninstall, "'timeout'  => 5," ), true );

echo "\nWHAT THE SECOND REVIEW FOUND IN THE FIRST REWORK\n" . str_repeat( '=', 118 ) . "\n";

/**
 * An unbroken run means unbroken. Replica of the accrual, run as a sequence.
 *
 * Carrying the old `since` forward across unrelated failures looked harmless
 * and was not: it is an absolute timestamp, so a site that 404s briefly, spends
 * a week at 502, then 404s once more would satisfy "failing for over a day" on
 * that single 404 and be set aside while plainly alive.
 */
function run_sequence( array $codes, $seconds_apart ) {
	$state = array();
	$t     = 0;
	$aside = false;

	foreach ( $codes as $code ) {
		$t += $seconds_apart;

		if ( $code >= 200 && $code < 300 ) {
			$state = array();
			continue;
		}

		if ( ! counts_as_gone( $code ) ) {
			$state = array();   // the run is broken
			continue;
		}

		$n     = isset( $state['n'] ) ? $state['n'] + 1 : 1;
		$since = isset( $state['since'] ) ? $state['since'] : $t;
		$state = array( 'n' => $n, 'since' => $since );

		if ( $n >= DORMANT_AFTER_FAILS && ( $t - $since ) >= DORMANT_AFTER_SECS ) {
			$aside = true;
			$state = array();   // the tally goes with it
		}
	}

	return $aside;
}

$forty = 40;

check( 'an unbroken day of 404s still sets a shop aside', run_sequence( array_fill( 0, 2340, 404 ), $forty ), true );

// The sequence the carry-forward got wrong: a live shop, set aside on one 404.
$mixed = array_merge( array_fill( 0, 100, 404 ), array_fill( 0, 3000, 502 ), array( 404 ) );
check( 'an outage between two runs of 404s does not', run_sequence( $mixed, $forty ), false );

$broken = array_merge( array_fill( 0, 2000, 404 ), array( 200 ), array_fill( 0, 2000, 404 ) );
check( 'a success in the middle restarts the clock', run_sequence( $broken, $forty ), false );

check( 'the clock restarts in the source too', false !== strpos( $relay, 'The run is broken, so the clock restarts.' ), true );
check( 'setting aside clears the tally', false !== strpos( $relay, 'unset( $failures[ $site_url ] );' ), true );
check( 'an empty round does not wipe the counts', substr_count( $relay, "\$GLOBALS['mad_failures'] = mad_previous_failures( \$state_file );" ), 2 );

echo "\nAN ACCIDENT COSTS AN HOUR; AN ADVERSARY MUST NOT GET MORE\n" . str_repeat( '=', 118 ) . "\n";

// Setting aside rather than deleting bounds a mistake to one hourly retry. It
// did not bound repetition: every deregister rewrote last_probe, so anyone
// holding the key could push that retry away for as long as they liked.
check( 'setting aside an already-set-aside site changes nothing', false !== strpos( $relay, 'AND COALESCE(dormant_since, 0) = 0' ), true );
check( '  so the hourly retry cannot be pushed away', false !== strpos( $relay, 'it must not be possible to push it away' ), true );

// A refresh eBay honoured proves the shop is live. A refresh merely attempted
// proves nothing, and this used to run on a POSTed hostname alone - before the
// token was exchanged at all.
check( 'a refresh restores only after eBay answers', strpos( $relay, '$ebay_data = json_decode( $response, true );' ) < strpos( $relay, '// Only once eBay has answered.' ), true );
check( '  gated on a token actually coming back', false !== strpos( $relay, "! empty( \$ebay_data['access_token'] )" ), true );

// That path needs no secret, so a line there would let a stranger fill a log
// nobody can rotate, on a host with no shell access.
check( 'an unknown hostname is not written to the log', false !== strpos( $relay, 'Not logged on purpose.' ), true );

check( 'one throwing site cannot end the round', false !== strpos( $relay, 'threw while being contacted' ), true );

echo "\nAND THE CONSOLE STAYS UP\n" . str_repeat( '=', 118 ) . "\n";

// enableExceptions(true) was added to this file in the same change. The @ on
// the ALTER suppresses a diagnostic, not an exception, so a lost race would
// have white-screened the only console that can restore a shop by hand.
check( 'the dashboard migration is wrapped, not suppressed', false === strpos( $dashboard, '@$db->exec($sql);' ), true );
check( '  in a real try', false !== strpos( $dashboard, 'Dashboard migration skipped for ' ), true );
check( 'a crafted site name cannot forge log lines', false !== strpos( $dashboard, "preg_replace( '/[\\x00-\\x1F\\x7F]/'" ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
