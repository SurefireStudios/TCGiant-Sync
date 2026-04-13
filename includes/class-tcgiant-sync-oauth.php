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
	 */
	private static $_instance = null;

	/**
	 * eBay OAuth Endpoints.
	 */
	const AUTH_ENDPOINT_PRODUCTION = 'https://auth.ebay.com/oauth2/authorize';
	const TOKEN_ENDPOINT_PRODUCTION = 'https://api.ebay.com/identity/v1/oauth2/token';

	/**
	 * Main instance.
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
	 * Get Authorization URL.
	 * 
	 * Redirects to the central TCGiant Relay.
	 */
	public function get_authorization_url() {
		$params = array(
			'site_url' => get_site_url(),
		);

		return 'https://tcgiant.com/syncconnect/relay.php?' . http_build_query( $params );
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
			
			// Generate a unique secret for this site provided by the relay if any, 
			// or just use a site-specific salt for MAD verification.
			if ( empty( $settings['relay_secret'] ) ) {
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

		$response = wp_remote_post( 'https://tcgiant.com/syncconnect/relay.php', array(
			'body'    => array(
				'action'        => 'refresh',
				'refresh_token' => $settings['refresh_token'],
			),
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
			return $body['access_token'];
		}

		TCGiant_Sync_Logger::error( 'Token Refresh Error Body: ' . wp_json_encode( $body ) );
		return false;
	}
}
