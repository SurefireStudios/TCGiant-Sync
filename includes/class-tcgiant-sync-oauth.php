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
	 */
	const STATE_TTL = 900;

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
		$params = array(
			'site_url' => get_site_url(),
		);

		if ( ! empty( $state ) ) {
			$params['state'] = $state;
		}

		return 'https://tcgiant.com/syncconnect/relay.php?' . http_build_query( $params );
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
	public function refresh_access_token() {
		$settings = $this->get_settings();
		if ( empty( $settings['refresh_token'] ) ) {
			return false;
		}

		// Include the site's API call count so the relay can compute global budget.
		$api = TCGiant_Sync_API::instance();

		$response = wp_remote_post( 'https://tcgiant.com/syncconnect/relay.php', array(
			'body'    => array(
				'action'          => 'refresh',
				'refresh_token'   => $settings['refresh_token'],
				'site_url'        => get_site_url(),
				'api_calls_today' => $api->get_daily_call_count(),
				'api_calls_date'  => gmdate( 'Y-m-d' ),
			),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			TCGiant_Sync_Logger::error( 'Token Refresh Error: ' . $response->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

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

		TCGiant_Sync_Logger::error( 'Token Refresh Error Body: ' . wp_json_encode( self::redact_secrets( $body ) ) );
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
