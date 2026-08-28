<?php
/**
 * eBay OAuth 2.0 Client
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_OAuth class
 */
class TCGiant_Sync_OAuth {

	/**
	 * Instance of this class.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * eBay OAuth Endpoints.
	 */
	const AUTH_ENDPOINT_PRODUCTION = 'https://auth.ebay.com/oauth2/authorize';
	const TOKEN_ENDPOINT_PRODUCTION = 'https://api.ebay.com/identity/v1/oauth2/token';

	/**
	 * Main instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Get eBay Settings.
	 */
	public function get_settings() {
		return get_option( 'tcgiant_sync_ebay_settings', array() );
	}

	/**
	 * Check if authenticated.
	 */
	public function is_authenticated() {
		$settings = $this->get_settings();
		return ! empty( $settings['access_token'] ) && ! empty( $settings['refresh_token'] );
	}

	/**
	 * Transient key holding the pending OAuth state token.
	 */
	const STATE_TRANSIENT = 'tcgiant_oauth_state';

	/**
	 * How long a pending OAuth handshake stays valid (seconds).
	 *
	 * Generous on purpose: the merchant may pause on eBay's consent screen, and
	 * expiring mid-flow rejects a perfectly good connection with a confusing
	 * "no pending authorization" error.
	 */
	const STATE_TTL = 1800;

	/**
	 * Get the Authorization URL to put behind a "Connect to eBay" button.
	 *
	 * This does NOT link straight to the relay. It links to a nonce-protected
	 * admin-post handler which records a one-time state token before bouncing
	 * the user out to the relay. The callback refuses to store tokens unless
	 * that state token is present, which stops a third party from planting
	 * their own eBay credentials on this site via a crafted link.
	 *
	 * @return string Nonce-protected local URL.
	 */
	public function get_authorization_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=tcgiant_oauth_start' ),
			'tcgiant_oauth_start'
		);
	}

	/**
	 * Get the real relay URL to redirect to once the handshake has started.
	 *
	 * @param string $state One-time state token to hand to the relay.
	 * @return string Relay URL.
	 */
	public function get_relay_authorization_url( $state = '' ) {
		/*
		 * Do NOT add a "state" parameter here.
		 *
		 * The relay distinguishes "start a connection" from "this is the
		 * callback coming back from eBay" by whether a state value is present.
		 * Sending one on the outbound request made every connection attempt
		 * look like a malformed callback, and the relay answered with
		 *
		 *   Invalid callback data or state. Debug -> Code: NO | State: ... |
		 *
		 * which blocked connecting and reconnecting entirely from 3.1.3 until
		 * 3.4.2. The parameter is kept in the signature for compatibility but
		 * is deliberately unused.
		 *
		 * Security is unaffected: the guard against a third party planting
		 * their own credentials is the pending-handshake transient recorded in
		 * begin_authorization(), which handle_oauth_callback() requires. That
		 * was always the primary check — comparing a returned state was only
		 * ever a bonus for a relay that echoed one back, and this one does not.
		 */
		unset( $state );

		$params = array(
			'site_url' => get_site_url(),
			// Tell the relay this build can collect its tokens over a separate
			// server-to-server request rather than having them handed back in the
			// browser's address bar. A relay that does not understand this simply
			// ignores it and returns the tokens the old way, so older and newer
			// versions of both sides keep working together.
			'claim'    => '1',
		);

		// The browser leg, not a server-to-server call: a person can answer
		// a bot check, so this one needs no alternate.
		return self::RELAY_URL . '?' . http_build_query( $params );
	}

	/**
	 * Begin an OAuth handshake: generate and store a one-time state token.
	 *
	 * @return string The generated state token.
	 */
	public function begin_authorization() {
		$state = wp_generate_password( 32, false );
		set_transient( self::STATE_TRANSIENT, $state, self::STATE_TTL );
		return $state;
	}

	/**
	 * Verify (and consume) a pending OAuth handshake.
	 *
	 * The relay is not guaranteed to echo the state parameter back, so a
	 * returned state is only compared when one is actually supplied. The
	 * presence of the pending transient is the primary check: it proves this
	 * site initiated the handshake within the last STATE_TTL seconds.
	 *
	 * @param string $returned_state State value returned by the relay, if any.
	 * @return bool True if the handshake is valid.
	 */
	public function consume_authorization_state( $returned_state = '' ) {
		$expected = get_transient( self::STATE_TRANSIENT );

		if ( empty( $expected ) ) {
			return false;
		}

		if ( ! empty( $returned_state ) && ! hash_equals( (string) $expected, (string) $returned_state ) ) {
			return false;
		}

		delete_transient( self::STATE_TRANSIENT );
		return true;
	}

	/**
	 * Save tokens received from the relay.
	 *
	 * @param array $data Token data.
	 */
	public function save_tokens_from_relay( $data ) {
		$settings = $this->get_settings();
		
		if ( ! empty( $data['access_token'] ) ) {
			$settings['access_token']  = sanitize_text_field( $data['access_token'] );
			$settings['refresh_token'] = sanitize_text_field( $data['refresh_token'] );
			$settings['token_expiry']  = time() + (int) $data['expires_in'];
			
			// Use the per-site signing key provided by the relay server.
			// This key is unique to this installation and used to verify
			// Marketplace Account Deletion notifications from the relay.
			if ( ! empty( $data['relay_key'] ) ) {
				$settings['relay_secret'] = sanitize_text_field( $data['relay_key'] );
			} elseif ( empty( $settings['relay_secret'] ) ) {
				// Fallback: generate locally if relay didn't provide one (legacy relay).
				$settings['relay_secret'] = wp_generate_password( 32, false );
			}

			update_option( 'tcgiant_sync_ebay_settings', $settings );
			return true;
		}

		return false;
	}

	/**
	 * Exchange Authorization Code for Token.
	 * @deprecated Use save_tokens_from_relay for centralized auth.
	 */
	public function exchange_code_for_token( $code ) {
		// This is now handled by the relay server.
		return false;
	}

	/**
	 * Get Access Token (with automatic refresh).
	 */
	public function get_access_token() {
		$settings = $this->get_settings();
		$now = time();

		if ( empty( $settings['access_token'] ) ) {
			return false;
		}

		// Refresh token if expired or about to expire (within 5 minutes).
		if ( (int) $settings['token_expiry'] < ( $now + 300 ) ) {
			return $this->refresh_access_token();
		}

		return $settings['access_token'];
	}

	/**
	 * Refresh Access Token.
	 */
	/**
	 * Exchange a one-time claim code for the tokens it stands for.
	 *
	 * The tokens travel over a direct request between this site and the relay,
	 * so they never appear in the address bar, in browser history, or in either
	 * server's access log. The code itself is single use and short lived, and is
	 * worthless once spent.
	 *
	 * @param string $code Claim code handed back on the redirect.
	 * @return array|WP_Error Token payload, or an error describing the refusal.
	 */
	/**
	 * The connection service.
	 */
	const RELAY_URL = 'https://tcgiant.com/syncconnect/relay.php';

	/**
	 * The same service under a different name.
	 */
	const RELAY_FALLBACK_URL = 'https://tcgiant.com/syncconnect/connect.php';

	/**
	 * The reporting endpoint. Not part of connecting, but the connection test
	 * asks it too, because it is the one address we have evidence of reaching
	 * us from a site that cannot connect. Whether it answers or is stopped
	 * like the others is the single most useful thing the test can find out:
	 * one says a filter is picking on particular requests, the other says
	 * nothing from that server reaches us at all.
	 */
	const TELEMETRY_URL = 'https://tcgiant.com/syncconnect/telemetry.php';

	/**
	 * Somewhere that is nothing to do with us, for the connection test only.
	 *
	 * When every one of our own addresses is answered by the same security
	 * page, one question decides who can fix it: is this server having trouble
	 * reaching US, or reaching ANYTHING? Asking our own addresses can never
	 * tell the two apart, so the test asks one address that is not ours.
	 *
	 * Google publish this URL for exactly this purpose — checking whether a
	 * network is interfering with traffic. It answers 204 with an empty body,
	 * and the request carries nothing about the site making it: no site
	 * address, no credentials, no data of any kind. It is sent only when
	 * someone presses the test button, never during ordinary syncing.
	 */
	const CONTROL_URL = 'https://www.google.com/generate_204';

	/**
	 * How long to wait between consecutive calls to the connection service.
	 *
	 * The protection in front of that service watches for bursts. Three calls
	 * inside one second is what a scraper looks like, and our own connection
	 * test was making exactly that shape while trying to diagnose why the
	 * connection was being challenged.
	 */
	const PACING_SECONDS = 2;

	/**
	 * Identify ourselves rather than arriving as a generic client.
	 *
	 * WordPress sends "WordPress/7.1; https://example.com" by default, which
	 * says nothing about what is calling or why, and is indistinguishable from
	 * any other script on any other site. Naming the application and its
	 * version gives anyone inspecting the traffic something to recognise, and
	 * gives us something to point at when asking for it to be recognised.
	 *
	 * @return string
	 */
	public static function user_agent_public() {
		return self::user_agent();
	}

	private static function user_agent() {
		return 'TCGiantSync/' . ( defined( 'TCGIANT_SYNC_VERSION' ) ? TCGIANT_SYNC_VERSION : '0' )
			. ' (+https://tcgiant.com)';
	}

	/**
	 * Post to the connection service, going round a security filter if one
	 * answers instead.
	 *
	 * Some hosts run software that challenges any request to a script called
	 * relay.php — the name is a common one for open proxies — and replies with
	 * a bot-check page. It carries HTTP 200 and expects a browser to try again
	 * once it has passed a check, which a server-to-server call can never do,
	 * so a site behind one never connects and retrying cannot help.
	 *
	 * The same request is then made to an endpoint that does not carry the
	 * name, sent as JSON rather than a form post so it resembles the telemetry
	 * ping those filters already allow through. Both reach the same service.
	 *
	 * Only tried when the first attempt is answered with a web page. A working
	 * connection makes one request exactly as before.
	 *
	 * @param array $body Request parameters.
	 * @return array|WP_Error
	 */
	private static function post_to_relay( array $body ) {
		$response = wp_remote_post( self::RELAY_URL, array(
			'body'       => $body,
			'timeout'    => 30,
			'user-agent' => self::user_agent(),
		) );

		if ( ! self::looks_intercepted( $response ) ) {
			return $response;
		}

		// Leave a gap before trying again. Two calls a few milliseconds apart is
		// the burst the protection in front of the service is watching for, and
		// arriving twice in quick succession while being challenged is the worst
		// possible moment to look like a scraper.
		sleep( self::PACING_SECONDS );

		$fallback = wp_remote_post( self::RELAY_FALLBACK_URL, array(
			'headers'    => array( 'Content-Type' => 'application/json' ),
			'body'       => wp_json_encode( $body ),
			'timeout'    => 30,
			'user-agent' => self::user_agent(),
		) );

		if ( self::looks_intercepted( $fallback ) ) {
			// Both were answered by whatever is in the way. Hand back the
			// first, so the caller reports on the reply that names the server
			// responsible — that is what tells the merchant's host where to
			// look.
			return $response;
		}

		if ( ! is_wp_error( $fallback ) ) {
			TCGiant_Sync_Logger::log(
				'A security filter on this server answered the usual connection request, so the alternate endpoint was used instead. The connection itself is fine.',
				'warning'
			);
		}

		return $fallback;
	}

	/**
	 * Was this reply a web page rather than an answer from the service?
	 *
	 * Deliberately narrow. A reply that merely has a PHP warning printed ahead
	 * of the JSON is not interception, and the callers already recover from
	 * that on their own — treating it as interception here would send people
	 * hunting for a firewall that does not exist.
	 *
	 * @param array|WP_Error $response
	 * @return bool
	 */
	/**
	 * Every response header, flattened, with cookie values withheld.
	 *
	 * A challenge page almost always sets a cookie to remember the check, and
	 * the cookie's NAME is usually the clearest identification of the product
	 * anywhere in the exchange. The value is of no interest and does not belong
	 * in a merchant's log, so only the name is kept.
	 *
	 * @param array $response
	 * @return array<string,string>
	 */
	private static function response_headers( $response ) {
		$headers = wp_remote_retrieve_headers( $response );

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		$flat = array();

		foreach ( (array) $headers as $name => $value ) {
			$value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;

			if ( 'set-cookie' === strtolower( (string) $name ) ) {
				$names = array();
				foreach ( explode( ',', $value ) as $cookie ) {
					$cookie = trim( $cookie );
					$eq     = strpos( $cookie, '=' );
					if ( false !== $eq && $eq > 0 ) {
						$names[] = substr( $cookie, 0, $eq );
					}
				}
				$value = $names ? implode( ', ', array_unique( $names ) ) . ' (names only)' : '(unreadable)';
			}

			$flat[ (string) $name ] = $value;
		}

		return $flat;
	}

	/**
	 * The whole reply, for showing on screen.
	 *
	 * The log keeps one line and the excerpt stops at 300 characters, which on
	 * the page in question cuts off before anything identifying. When a
	 * merchant is asked to send us what they received, they need to be able to
	 * copy all of it rather than whatever survived the trimming.
	 *
	 * @param array $response
	 * @return string
	 */
	private static function capture_response( $response ) {
		$lines = array( 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) );

		foreach ( self::response_headers( $response ) as $header => $value ) {
			$lines[] = $header . ': ' . $value;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		return implode( "\n", $lines ) . "\n\n" . substr( $body, 0, 4000 );
	}

	/**
	 * Say who answered, when the answer was a web page.
	 *
	 * Telling a merchant that "something" intercepted the request sends them
	 * and their host hunting with nothing to go on, and both sides can
	 * honestly report that their own end looks fine. The reply headers say
	 * whose server produced the page: the connection service runs LiteSpeed,
	 * so anything else came from somewhere in between, and the page title
	 * usually names the product responsible.
	 *
	 * @param array $response
	 * @return string
	 */
	private static function describe_interception( $response ) {
		$raw       = (string) wp_remote_retrieve_body( $response );
		$served_by = array();

		// Every header, rather than the seven someone thought of in advance.
		// The page that prompted this sets no icon, references nothing but the
		// W3C namespace, and identifies itself nowhere in its markup — while
		// the one header that names such products, Set-Cookie, was not among
		// the seven. Guessing which headers matter is how that happened.
		foreach ( self::response_headers( $response ) as $header => $value ) {
			$served_by[] = $header . ': ' . $value;
		}

		$title = '';
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $raw, $found ) ) {
			$title = trim( wp_strip_all_tags( $found[1] ) );
		}

		// Whatever the page loads its own icon, styles and scripts from is the
		// one thing in it that names its owner, and the 300-character excerpt
		// below was cutting off at exactly the point the icon address begins.
		// Two people can each look at their own equipment, find it innocent and
		// be telling the truth; an address belonging to one of them ends that.
		$icon = '';
		if ( preg_match( '/<link[^>]*rel=[^>]*icon[^>]*>/i', $raw, $tag )
			&& preg_match( '/href=["]?([^"\s>]+)/i', $tag[0], $href ) ) {
			$icon = $href[1];
		}

		$hosts = array();
		if ( preg_match_all( '#https?://[^\s"<>]+#i', $raw, $found_urls ) ) {
			foreach ( $found_urls[0] as $url ) {
				$host = wp_parse_url( $url, PHP_URL_HOST );
				if ( $host && ! in_array( $host, $hosts, true ) ) {
					$hosts[] = $host;
				}
				if ( count( $hosts ) >= 5 ) {
					break;
				}
			}
		}

		return 'Served by — ' . ( $served_by ? implode( ' | ', $served_by ) : 'no identifying headers' )
			. ( '' !== $title ? ' | page title: ' . $title : '' )
			. ( '' !== $icon ? ' | icon: ' . $icon : '' )
			. ( $hosts ? ' | addresses in the page: ' . implode( ', ', $hosts ) : ' | the page names no addresses' )
			. ' | first 300 characters: ' . substr( $raw, 0, 300 );
	}

	/**
	 * Ask both endpoints whether they can be reached from this server.
	 *
	 * Connecting fails in places a merchant cannot see: the browser goes off
	 * to eBay and comes back, and if the site could not collect the tokens
	 * there is nothing on screen to say why. This asks the question directly
	 * and reports what actually answered, which is the difference between
	 * "it does not work" and a sentence their host can act on.
	 *
	 * Uses the service's own health check, so nothing is created, spent or
	 * changed by running it.
	 *
	 * @return array[] One entry per endpoint.
	 */
	public function run_connection_test() {
		$endpoints = array(
			array(
				'label' => __( 'Usual route', 'tcgiant-sync' ),
				'url'   => self::RELAY_URL,
				'probe' => 'health',
				'role'  => 'connect',
			),
			array(
				'label' => __( 'Alternate route', 'tcgiant-sync' ),
				'url'   => self::RELAY_FALLBACK_URL,
				'probe' => 'health',
				'role'  => 'connect',
			),
			array(
				'label' => __( 'Reporting route', 'tcgiant-sync' ),
				'url'   => self::TELEMETRY_URL,
				'probe' => 'reject',
				'role'  => 'report',
			),
			array(
				'label' => __( 'Somewhere unrelated', 'tcgiant-sync' ),
				'url'   => self::CONTROL_URL,
				'probe' => 'reachable',
				'role'  => 'control',
			),
		);

		// Before asking anything, establish where "us" even is from here.
		//
		// Every reading so far has been of what came BACK, and all of them are
		// equally explained by the requests arriving somewhere else entirely.
		// A server that resolves our name to the wrong address would show
		// exactly this: our addresses all unreachable, unrelated sites fine,
		// nothing in our logs, and a security page belonging to whichever
		// machine it actually reached. Nobody's firewall need be involved.
		$results = array( self::probe_name(), self::probe_certificate() );

		// Spaced out, because this test was part of the problem.
		//
		// The host's own log shows our three probes arriving inside one second
		// and the protection treating that burst as automated traffic. A
		// diagnostic that provokes the fault it is measuring is worse than no
		// diagnostic. A few seconds is nothing on a button someone presses by
		// hand.
		$first = true;

		foreach ( $endpoints as $endpoint ) {
			if ( ! $first ) {
				sleep( self::PACING_SECONDS );
			}
			$first = false;

			$result         = self::probe_endpoint( $endpoint['label'], $endpoint['url'], $endpoint['probe'] );
			$result['role'] = $endpoint['role'];
			$results[]      = $result;
		}

		return $results;
	}

	/**
	 * What address does this server think our hostname has?
	 *
	 * @return array
	 */
	private static function probe_name() {
		$label = __( 'Name lookup', 'tcgiant-sync' );
		$host  = wp_parse_url( self::RELAY_URL, PHP_URL_HOST );

		if ( ! $host ) {
			return array(
				'label' => $label,
				'state' => 'unexpected',
				'role'  => 'dns',
				'detail' => __( 'Could not work out which name to look up.', 'tcgiant-sync' ),
			);
		}

		// gethostbyname() hands the name straight back when it cannot resolve.
		$resolved = gethostbyname( $host );
		$own      = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';

		if ( $resolved === $host ) {
			return array(
				'label' => $label,
				'state' => 'unreachable',
				'role'  => 'dns',
				'detail' => sprintf(
					/* translators: %s: hostname */
					__( 'This server cannot look up %s at all. Nothing can reach us until name lookups work.', 'tcgiant-sync' ),
					$host
				),
			);
		}

		$public = filter_var( $resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );

		// Resolving to a private address, or to this very machine, means the
		// requests never left the building. That is not a firewall.
		if ( ! $public || ( '' !== $own && $resolved === $own ) ) {
			return array(
				'label' => $label,
				'state' => 'unexpected',
				'role'  => 'dns',
				'detail' => sprintf(
					/* translators: 1: hostname, 2: address it resolved to, 3: this server's own address */
					__( 'This server resolves %1$s to %2$s, which is not a public address on the internet%3$s. Requests meant for us are going somewhere on this network instead, which would explain everything above without any firewall being involved. This is for your host: ask why that name resolves locally.', 'tcgiant-sync' ),
					$host,
					$resolved,
					'' !== $own ? sprintf( ' (this server is %s)', $own ) : ''
				),
			);
		}

		return array(
			'label' => $label,
			'state' => 'ok',
			'role'  => 'dns',
			'detail' => sprintf(
				/* translators: 1: hostname, 2: resolved address */
				__( 'This server resolves %1$s to %2$s. Check that against the address we publish: if it differs, the requests are reaching the wrong machine and nothing else here matters.', 'tcgiant-sync' ),
				$host,
				$resolved
			),
		);
	}

	/**
	 * Which certificate does this server actually receive from our address?
	 *
	 * This is the one reading that separates the two remaining possibilities,
	 * and it took a week to arrive at. Everything else measured what came back
	 * and was equally consistent with either.
	 *
	 * The plugin's requests verify TLS, so a reply arriving at all proves the
	 * responder presented a certificate this server trusts for our name. There
	 * are only two ways that happens. Either the responder holds our real
	 * certificate, in which case it stands at our end in front of our own
	 * server; or something on this network is inspecting encrypted traffic
	 * using an authority installed on this machine, and is impersonating us.
	 *
	 * Verification is deliberately off here: the point is to SEE the
	 * certificate, including one that would be rejected. Nothing is sent and
	 * nothing is trusted — the connection is opened and immediately closed.
	 *
	 * @return array
	 */
	private static function probe_certificate() {
		$label = __( 'Certificate', 'tcgiant-sync' );
		$host  = wp_parse_url( self::RELAY_URL, PHP_URL_HOST );

		if ( ! $host || ! function_exists( 'stream_socket_client' ) || ! function_exists( 'openssl_x509_parse' ) ) {
			return array(
				'label'  => $label,
				'state'  => 'unexpected',
				'role'   => 'tls',
				'detail' => __( 'This server cannot inspect certificates, so this check was skipped.', 'tcgiant-sync' ),
			);
		}

		$context = stream_context_create( array(
			'ssl' => array(
				'capture_peer_cert' => true,
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'SNI_enabled'       => true,
				'peer_name'         => $host,
			),
		) );

		$errno  = 0;
		$errstr = '';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$socket = @stream_socket_client(
			'ssl://' . $host . ':443',
			$errno,
			$errstr,
			15,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if ( ! $socket ) {
			return array(
				'label'  => $label,
				'state'  => 'unreachable',
				'role'   => 'tls',
				'detail' => sprintf(
					/* translators: %s: connection error */
					__( 'Could not open an encrypted connection to look at the certificate. %s', 'tcgiant-sync' ),
					$errstr ? $errstr : __( 'No reason given.', 'tcgiant-sync' )
				),
			);
		}

		$params = stream_context_get_params( $socket );
		fclose( $socket );

		$cert = isset( $params['options']['ssl']['peer_certificate'] ) ? $params['options']['ssl']['peer_certificate'] : null;

		if ( ! $cert ) {
			return array(
				'label'  => $label,
				'state'  => 'unexpected',
				'role'   => 'tls',
				'detail' => __( 'The connection opened but presented no certificate to look at.', 'tcgiant-sync' ),
			);
		}

		$parsed = openssl_x509_parse( $cert );

		// openssl_x509_fingerprint(), so the value matches what anyone else
		// gets from `openssl x509 -fingerprint -sha256`. Hashing the exported
		// text instead would be stable but would agree with nobody, which
		// defeats the point of quoting it to a hosting provider.
		$fingerprint = function_exists( 'openssl_x509_fingerprint' )
			? strtoupper( (string) openssl_x509_fingerprint( $cert, 'sha256' ) )
			: '';

		$subject = isset( $parsed['subject']['CN'] ) ? $parsed['subject']['CN'] : __( 'unnamed', 'tcgiant-sync' );
		$issuer  = isset( $parsed['issuer']['O'] ) ? $parsed['issuer']['O'] : '';
		$issuer .= isset( $parsed['issuer']['CN'] ) ? ( $issuer ? ' / ' : '' ) . $parsed['issuer']['CN'] : '';
		$issuer  = $issuer ? $issuer : __( 'unnamed', 'tcgiant-sync' );

		// A certificate that issued itself is nobody's public authority.
		$self_signed = ( isset( $parsed['subject'] ) && isset( $parsed['issuer'] ) && $parsed['subject'] === $parsed['issuer'] );

		return array(
			'label'  => $label,
			'state'  => $self_signed ? 'unexpected' : 'ok',
			'role'   => 'tls',
			'detail' => sprintf(
				/* translators: 1: hostname, 2: certificate name, 3: issuer, 4: fingerprint, 5: extra warning */
				__( 'The address for %1$s presents a certificate for "%2$s" issued by %3$s. Its fingerprint is %4$s.%5$s Compare that against the certificate we publish: if it differs, this connection is being decrypted and answered by equipment on this network rather than reaching us, and that is your host\'s to explain.', 'tcgiant-sync' ),
				$host,
				$subject,
				$issuer,
				$fingerprint ? $fingerprint : __( 'unavailable', 'tcgiant-sync' ),
				$self_signed ? ' ' . __( 'It issued itself, which no public authority does.', 'tcgiant-sync' ) : ''
			),
		);
	}

	/**
	 * Reach one endpoint and classify what came back.
	 *
	 * Two ways of asking, because the endpoints answer differently:
	 *
	 *   health  the connection service has its own health check, which replies
	 *           in plain text and touches nothing.
	 *   reject  the reporting endpoint has no health check, so it is sent a
	 *           deliberately incomplete post. It refuses with a bare 400 and
	 *           records nothing, and a bare 400 is proof enough that our own
	 *           server answered: an interception is a 200 carrying a web page.
	 *
	 * @param string $label
	 * @param string $url
	 * @param string $probe 'health' or 'reject'.
	 * @return array
	 */
	private static function probe_endpoint( $label, $url, $probe = 'health' ) {
		if ( 'reachable' === $probe ) {
			// Nothing is sent but the request itself.
			$response = wp_remote_get( $url, array(
				'timeout'    => 20,
				'user-agent' => self::user_agent(),
			) );
		} elseif ( 'reject' === $probe ) {
			$response = wp_remote_post( $url, array(
				'timeout'    => 20,
				'headers'    => array( 'Content-Type' => 'application/json' ),
				'body'       => '{}',
				'user-agent' => self::user_agent(),
			) );
		} else {
			$response = wp_remote_get( add_query_arg( 'debug_challenge', '1', $url ), array(
				'timeout'    => 20,
				'user-agent' => self::user_agent(),
			) );
		}

		if ( is_wp_error( $response ) ) {
			// Never got off this server, or nothing answered at all.
			return array(
				'label'  => $label,
				'state'  => 'unreachable',
				'detail' => sprintf(
					/* translators: %s: error message */
					__( 'This server could not reach the connection service at all. %s', 'tcgiant-sync' ),
					$response->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		// For the unrelated address, any straight answer at all will do. The
		// question is only whether this server can reach somewhere that is not
		// us without being stopped.
		if ( 'reachable' === $probe && ! self::looks_intercepted( $response ) ) {
			return array(
				'label'  => $label,
				'state'  => 'ok',
				'detail' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'Reached normally (HTTP %d). This server can reach sites that are nothing to do with us.', 'tcgiant-sync' ),
					$code
				),
			);
		}

		// The reporting endpoint refusing an incomplete post is our own server
		// talking, which is all this needs to establish.
		if ( 'reject' === $probe && $code >= 400 && $code < 500 && ! self::looks_intercepted( $response ) ) {
			return array(
				'label'  => $label,
				'state'  => 'ok',
				'detail' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'Reached normally (HTTP %d, which is this endpoint correctly refusing a deliberately empty test). Nothing is in the way on this route.', 'tcgiant-sync' ),
					$code
				),
			);
		}

		if ( false !== stripos( $raw, 'Relay is active' ) ) {
			return array(
				'label'  => $label,
				'state'  => 'ok',
				'detail' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'Reached normally (HTTP %d). Nothing is in the way on this route.', 'tcgiant-sync' ),
					$code
				),
			);
		}

		if ( self::looks_intercepted( $response ) ) {
			return array(
				'label'  => $label,
				'state'  => 'intercepted',
				'raw'    => self::capture_response( $response ),
				'detail' => sprintf(
					/* translators: 1: HTTP status code, 2: description of what answered */
					__( 'A web page was returned instead of an answer, so something on this server\'s network replied before the request reached us (HTTP %1$d). %2$s', 'tcgiant-sync' ),
					$code,
					self::describe_interception( $response )
				),
			);
		}

		return array(
			'label'  => $label,
			'state'  => 'unexpected',
			'raw'    => self::capture_response( $response ),
			'detail' => sprintf(
				/* translators: 1: HTTP status code, 2: start of the reply */
				__( 'Something answered but not in the expected form (HTTP %1$d). The reply began: %2$s', 'tcgiant-sync' ),
				$code,
				substr( trim( preg_replace( '/\s+/', ' ', $raw ) ), 0, 200 )
			),
		);
	}

	private static function looks_intercepted( $response ) {
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$raw = (string) wp_remote_retrieve_body( $response );

		if ( null !== json_decode( $raw, true ) ) {
			return false;
		}

		return (bool) preg_match( '/^[\s]*<(?:!doctype|html)/i', $raw );
	}

	public function claim_tokens_from_relay( $code ) {
		$code = trim( (string) $code );
		if ( '' === $code ) {
			return new WP_Error( 'claim_missing', __( 'No claim code was supplied.', 'tcgiant-sync' ) );
		}

		$response = self::post_to_relay( array(
			'action'   => 'claim',
			'code'     => $code,
			'site_url' => get_site_url(),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		// Same recovery as refresh_access_token(): a warning printed ahead of the
		// reply must not cost us a valid connection.
		if ( null === $body ) {
			$start = strpos( $raw, '{' );
			if ( false !== $start ) {
				$body = json_decode( substr( $raw, $start ), true );
			}
		}

		// A reply that starts with markup did not come from the connection
		// service at all — something between this site and it answered instead,
		// which is almost always bot protection or a security filter. Saying
		// "would not release the tokens" there sends people looking in the wrong
		// place entirely.
		if ( null === $body && preg_match( '/^[\s]*<(?:!doctype|html)/i', $raw ) ) {

			TCGiant_Sync_Logger::error(
				'The reply to the token request was a web page, not data. '
				. self::describe_interception( $response )
			);

			return new WP_Error(
				'claim_intercepted',
				__( 'A web page was returned instead of data, so something answered the request before it reached the connection service. The eBay account cannot finish connecting until that request gets through. The activity log records which server produced the page, which says where to look.', 'tcgiant-sync' )
				. ' HTTP ' . (string) wp_remote_retrieve_response_code( $response )
			);
		}

		if ( ! is_array( $body ) || ! isset( $body['access_token'], $body['refresh_token'] ) ) {
			// Note the plain concatenation below rather than sprintf(). This is
			// the path taken when something has already gone wrong, and it once
			// took a site down on its own: a stray backslash in the format
			// string made sprintf() throw, so instead of reporting why the
			// connection failed, the plugin produced a fatal error and left the
			// merchant staring at a broken admin screen. An error handler is
			// the last place that should be able to fail.
			return new WP_Error(
				'claim_failed',
				(
					__( 'The connection service would not release the tokens.', 'tcgiant-sync' )
					. ' HTTP ' . (string) wp_remote_retrieve_response_code( $response )
					. '. ' . __( 'Reply began:', 'tcgiant-sync' ) . ' '
					. substr( $raw, 0, 200 )
				)
			);
		}

		return array(
			'access_token'  => (string) $body['access_token'],
			'refresh_token' => (string) $body['refresh_token'],
			'expires_in'    => (string) ( $body['expires_in'] ?? 7200 ),
			'relay_key'     => (string) ( $body['relay_key'] ?? '' ),
		);
	}

	public function refresh_access_token() {
		$settings = $this->get_settings();
		if ( empty( $settings['refresh_token'] ) ) {
			return false;
		}

		// Include the site's API call count so the relay can compute global budget.
		$api = TCGiant_Sync_API::instance();

		// The renewal needs this every bit as much as the first connection:
		// a site that got connected while the filter was quiet would otherwise
		// fail the moment its token came up for renewal.
		$response = self::post_to_relay( array(
			'action'          => 'refresh',
			'refresh_token'   => $settings['refresh_token'],
			'site_url'        => get_site_url(),
			'api_calls_today' => $api->get_daily_call_count(),
			'api_calls_date'  => gmdate( 'Y-m-d' ),
		) );

		if ( is_wp_error( $response ) ) {
			TCGiant_Sync_Logger::error( 'Token Refresh Error: ' . $response->get_error_message() );
			return false;
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		// The connection service can prefix its reply with a PHP warning — a
		// locked SQLite database has done exactly this — which leaves the JSON
		// intact but no longer parseable, so a perfectly good token was thrown
		// away and the site reported itself disconnected. Recover the JSON rather
		// than lose the connection over someone else's notice.
		if ( null === $body ) {
			$start = strpos( $raw, '{' );
			if ( false !== $start ) {
				$recovered = json_decode( substr( $raw, $start ), true );
				if ( is_array( $recovered ) && isset( $recovered['access_token'] ) ) {
					TCGiant_Sync_Logger::warning( sprintf(
						'The connection service returned a warning before its reply; the token was recovered from it. Leading text: %s',
						trim( substr( $raw, 0, $start ) )
					) );
					$body = $recovered;
				}
			}
		}

		if ( isset( $body['access_token'] ) ) {
			$settings['access_token'] = $body['access_token'];
			$settings['token_expiry'] = time() + (int) $body['expires_in'];
			update_option( 'tcgiant_sync_ebay_settings', $settings );

			// Store the relay-provided global budget so the API class can use it.
			if ( isset( $body['remaining_budget'] ) ) {
				$date_key = gmdate( 'Y-m-d' );
				set_transient( 'tcgiant_relay_budget_' . $date_key, array(
					'remaining'    => (int) $body['remaining_budget'],
					'global_calls' => (int) ( $body['global_api_calls'] ?? 0 ),
					'daily_limit'  => (int) ( $body['daily_limit'] ?? 50000 ),
					'updated_at'   => time(),
				), DAY_IN_SECONDS );
			}

			return $body['access_token'];
		}

		// A decoded body of null means the reply was not JSON at all, so logging
		// the decoded value said nothing more than "it failed". Record the status
		// code and a slice of the raw reply so the next report can be diagnosed.
		if ( null === $body ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Token refresh failed: HTTP %s, reply was not JSON. First 200 characters: %s',
				wp_remote_retrieve_response_code( $response ),
				substr( (string) wp_remote_retrieve_body( $response ), 0, 200 )
			) );
			return false;
		}

		TCGiant_Sync_Logger::error( sprintf(
			'Token refresh failed: HTTP %s. Response: %s',
			wp_remote_retrieve_response_code( $response ),
			wp_json_encode( self::redact_secrets( $body ) )
		) );
		return false;
	}

	/**
	 * Replace credential-like values before anything is written to the log.
	 *
	 * This path logs whatever the relay returned when a refresh did not yield
	 * an access token. A partial or unexpected response can still carry a
	 * token or the relay signing key, and the activity log is a plaintext file
	 * that ends up in support bundles and site backups.
	 *
	 * @param mixed $data Decoded response body.
	 * @return mixed Same shape, with secret values replaced.
	 */
	private static function redact_secrets( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$secret_keys = array(
			'access_token',
			'refresh_token',
			'token',
			'relay_key',
			'relay_secret',
			'client_secret',
			'cert_id',
			'authorization',
		);

		$clean = array();
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$clean[ $key ] = self::redact_secrets( $value );
				continue;
			}

			$clean[ $key ] = in_array( strtolower( (string) $key ), $secret_keys, true )
				? '[redacted]'
				: $value;
		}

		return $clean;
	}
}
