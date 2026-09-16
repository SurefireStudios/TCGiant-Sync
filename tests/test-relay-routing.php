<?php
/**
 * Harness: which address the plugin asks, and in what order.
 *
 * Merchants reach us from whatever address their host gives them. Those get
 * classified by reputation systems through nobody's fault - one shop runs from
 * a residential line, another shares a machine in Sydney with strangers - and
 * our host's filter then answers their SERVER with a page asking it to run
 * JavaScript. A server cannot, so that shop cannot connect to eBay at all. One
 * merchant sent us the evidence: their request reached our address, over our
 * own certificate, and was answered by a holding page.
 *
 * The fix is a hostname the host exempts from that filter. The risk in the fix
 * is everyone else: a shop connecting happily today must not be broken by a
 * problem with a hostname it never needed. So the new one is tried first and
 * the old routes stay underneath, and a failure costs one wasted request rather
 * than a disconnection.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-62s got %-28s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

// ---- the routing, verbatim, with the network replaced by a script -------------------

class Relay {
	const API_RELAY_URL      = 'https://api.tcgiant.com/relay.php';
	const RELAY_URL          = 'https://tcgiant.com/syncconnect/relay.php';
	const RELAY_FALLBACK_URL = 'https://tcgiant.com/syncconnect/connect.php';

	/** What each address will answer: 'ok', 'intercepted', 'error' or 'failed'. */
	public $answers = array();

	/** Every address asked, in order. */
	public $asked = array();

	private function post( $url ) {
		$this->asked[] = $url;
		return $this->answers[ $url ] ?? 'ok';
	}

	private function intercepted( $answer ) {
		return 'intercepted' === $answer;
	}

	private function errored( $answer ) {
		return 'error' === $answer;
	}

	/**
	 * 'failed' is the service answering with a failure status - the one thing
	 * this harness could not say when it was written, and the one thing that
	 * actually happened.
	 */
	private function answered_cleanly( $answer ) {
		return 'failed' !== $answer;
	}

	public function post_to_relay() {
		$api = $this->post( self::API_RELAY_URL );

		if ( ! $this->errored( $api ) && ! $this->intercepted( $api ) && $this->answered_cleanly( $api ) ) {
			return 'api';
		}

		$response = $this->post( self::RELAY_URL );

		if ( ! $this->intercepted( $response ) ) {
			return 'main';
		}

		$fallback = $this->post( self::RELAY_FALLBACK_URL );

		return $this->intercepted( $fallback ) ? 'nothing got through' : 'alternate';
	}
}

function run( array $answers ) {
	$relay          = new Relay();
	$relay->answers = $answers;
	$used           = $relay->post_to_relay();

	return array( $used, $relay->asked );
}

echo "\nTHE SHOP THAT WAS BLOCKED\n" . str_repeat( '=', 108 ) . "\n";

// Their server is challenged on the main site and nowhere else. Before the new
// hostname existed, every route they had went through the thing challenging
// them, which is why they could not connect at all.
list( $used, $asked ) = run( array(
	Relay::RELAY_URL          => 'intercepted',
	Relay::RELAY_FALLBACK_URL => 'intercepted',
) );

check( 'connects on the exempt hostname', $used, 'api' );
check( '  and never has to try the challenged ones', count( $asked ), 1 );

echo "\nTHE SHOPS THAT WERE ALREADY FINE\n" . str_repeat( '=', 108 ) . "\n";

list( $used, $asked ) = run( array() );
check( 'answered first time', $used, 'api' );
check( '  one request, as before', count( $asked ), 1 );

echo "\nWHEN THE NEW HOSTNAME IS THE PROBLEM\n" . str_repeat( '=', 108 ) . "\n";

// The whole point of keeping the old routes: a subdomain a week old should not
// be able to disconnect a shop that has been working for a year.
list( $used, $asked ) = run( array( Relay::API_RELAY_URL => 'error' ) );
check( 'a network error falls through to the main site', $used, 'main' );
check( '  having tried both, in order', $asked, array( Relay::API_RELAY_URL, Relay::RELAY_URL ) );

list( $used ) = run( array( Relay::API_RELAY_URL => 'intercepted' ) );
check( 'so does a challenge on the new hostname', $used, 'main' );

list( $used, $asked ) = run( array(
	Relay::API_RELAY_URL => 'error',
	Relay::RELAY_URL     => 'intercepted',
) );
check( 'and the third route is still there beneath both', $used, 'alternate' );
check( '  all three tried, in order', $asked, array( Relay::API_RELAY_URL, Relay::RELAY_URL, Relay::RELAY_FALLBACK_URL ) );

list( $used ) = run( array(
	Relay::API_RELAY_URL      => 'error',
	Relay::RELAY_URL          => 'intercepted',
	Relay::RELAY_FALLBACK_URL => 'intercepted',
) );
check( 'nothing reachable is reported, not hidden', $used, 'nothing got through' );

echo "\nWHEN THE NEW HOSTNAME ANSWERS, BUT WITH A FAILURE\n" . str_repeat( '=', 108 ) . "\n";

// This is the one that got through. A loader fault left the service on the new
// hostname unable to open its database, and its token handler reported that as
// a malformed request: a well-formed HTTP 400, valid JSON, no challenge page
// and no transport error. Every test above passes in that state. The plugin
// took it for a real answer, never asked the route that was working perfectly
// the whole time, and told six days of new sellers that their own request was
// invalid.
list( $used, $asked ) = run( array( Relay::API_RELAY_URL => 'failed' ) );

check( 'a failure answer falls through to the main site', $used, 'main' );
check( '  having tried both, in order', $asked, array( Relay::API_RELAY_URL, Relay::RELAY_URL ) );

// And it must not swallow a shop's last route. If the main site fails too,
// the third is still tried rather than the failure being reported as final.
list( $used, $asked ) = run( array(
	Relay::API_RELAY_URL => 'failed',
	Relay::RELAY_URL     => 'intercepted',
) );
check( 'the route beneath it is still reached', $used, 'alternate' );

// The main site answering with a failure is NOT second-guessed. Two hostnames
// serve one service over one database, so once the second has spoken there is
// nothing left to ask that could answer differently - and the shop deserves to
// be told what it said rather than have it retried into a timeout.
list( $used, $asked ) = run( array(
	Relay::API_RELAY_URL => 'failed',
	Relay::RELAY_URL     => 'failed',
) );
check( 'a failure from the main site is reported, not retried', $used, 'main' );
check( '  so a genuine refusal costs two requests, not three', count( $asked ), 2 );

echo "\nWHAT THE SOURCE MUST KEEP DOING\n" . str_repeat( '=', 108 ) . "\n";

$oauth = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-oauth.php' );

check( 'the exempt hostname is asked first',
	strpos( $oauth, 'wp_remote_post( self::API_RELAY_URL' ) < strpos( $oauth, 'wp_remote_post( self::RELAY_URL' ), true );

// Two requests a few milliseconds apart is the burst the filter watches for,
// and this code has provoked that fault before.
$between = substr(
	$oauth,
	(int) strpos( $oauth, 'wp_remote_post( self::API_RELAY_URL' ),
	(int) strpos( $oauth, 'wp_remote_post( self::RELAY_URL' ) - (int) strpos( $oauth, 'wp_remote_post( self::API_RELAY_URL' )
);
check( '  with a pause before falling back to a challenged route',
	false !== strpos( $between, 'sleep( self::PACING_SECONDS );' ), true );

// The browser half of connecting must NOT move. eBay returns people to an
// address registered against our RuName in their developer portal, and that
// registration names the main site. A browser can satisfy a challenge anyway.
// The function runs to about thirty-five lines, so take it to its closing
// brace rather than guessing a character count.
$from      = (int) strpos( $oauth, 'function get_relay_authorization_url' );
$authorize = substr( $oauth, $from, (int) strpos( $oauth, chr( 10 ) . chr( 9 ) . '}', $from ) - $from );
check( 'the browser leg still goes to the main site', false !== strpos( $authorize, 'self::RELAY_URL' ), true );
check( '  and not to the exempt hostname', false === strpos( $authorize, 'self::API_RELAY_URL' ), true );

// A shop that cannot resolve a hostname created this week is a real thing to
// find, and probing the old name would miss it.
check( 'the name and certificate probes follow the hostname in use',
	2, substr_count( $oauth, 'wp_parse_url( self::API_RELAY_URL, PHP_URL_HOST )' ) );

check( 'a failure status from the new hostname is not taken for an answer',
	false !== strpos( $oauth, 'self::answered_cleanly( $api )' ), true );

// Only the status, deliberately. Deciding from the body which failures are
// worth retrying would have to guess, and the failure that caused this - a
// relay with no database calling it 'invalid_request' - is the guess it gets
// wrong.
check( '  judged on the status alone', 1, substr_count( $oauth, '$code >= 200 && $code < 300' ) );

check( 'the connection test reports on both hostnames',
	false !== strpos( $oauth, "'url'   => self::API_RELAY_URL," ) && false !== strpos( $oauth, "'url'   => self::RELAY_URL," ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
