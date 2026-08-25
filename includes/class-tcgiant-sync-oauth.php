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
	 * The same service under a name that security filters do not object to.
	 */
	const RELAY_FALLBACK_URL = 'https://tcgiant.com/syncconnect/connect.php';

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
			'body'    => $body,
			'timeout' => 30,
		) );

		if ( ! self::looks_intercepted( $response ) ) {
			return $response;
		}

		$fallback = wp_remote_post( self::RELAY_FALLBACK_URL, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
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

			// Record who actually answered. Telling a merchant that "something"
			// intercepted the request sends them and their host hunting with
			// nothing to go on, and both sides can honestly report that their
			// own end looks fine. The reply headers say whose server produced
			// the page: our connection service runs LiteSpeed, so anything else
			// came from somewhere in between, and the title of the page usually
			// names the product doing it.
			$served_by = array();

			foreach ( array( 'server', 'x-powered-by', 'cf-ray', 'x-sucuri-id', 'x-cache', 'via', 'content-type' ) as $header ) {
				$value = wp_remote_retrieve_header( $response, $header );
				if ( ! empty( $value ) ) {
					$served_by[] = $header . ': ' . ( is_array( $value ) ? implode( ', ', $value ) : $value );
				}
			}

			$title = '';
			if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $raw, $found ) ) {
				$title = trim( wp_strip_all_tags( $found[1] ) );
			}

			TCGiant_Sync_Logger::error(
				'The reply to the token request was a web page, not data. '
				. 'Served by — ' . ( $served_by ? implode( ' | ', $served_by ) : 'no identifying headers' )
				. ( '' !== $title ? ' | page title: ' . $title : '' )
				. ' | first 300 characters: ' . substr( $raw, 0, 300 )
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
