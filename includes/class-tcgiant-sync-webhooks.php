<?php
/**
 * Webhook Handler for eBay Notifications
 *
 * @package TCGiant_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Webhooks class
 */
class TCGiant_Sync_Webhooks {

	/**
	 * Instance.
	 */
	private static $_instance = null;

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
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		register_rest_route( 'tcgiant-sync/v1', '/ebay-deletion', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_notification' ),
				'permission_callback' => '__return_true',
			),
		) );
	}

	/**
	 * Handle actual Deletion Notification (POST) from Relay.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function handle_notification( $request ) {
		$signature = $request->get_header( 'x-tcgiant-signature' );
		$timestamp = $request->get_header( 'x-tcgiant-timestamp' );
		$body      = $request->get_body();
		
		// Verify signature from TCGiant Relay.
		$relay_secret = 'tcgiant_relay_secret_key_500'; 
		$expected_signature = hash_hmac( 'sha256', $body . $timestamp, $relay_secret );

		if ( ! hash_equals( $expected_signature, (string) $signature ) ) {
			return new WP_Error( 'unauthorized', __( 'Invalid signature.', 'tcgiant-sync' ), array( 'status' => 401 ) );
		}

		$params = $request->get_json_params();
		$ebay_user_id = $params['notification']['data']['userId'] ?? $params['userId'] ?? '';

		if ( empty( $ebay_user_id ) ) {
			// Empty payload / test ping — acknowledge instantly, no logging, no DB.
			return new WP_REST_Response( array( 'status' => 'acknowledged' ), 200 );
		}

		// Rate limit: skip if we processed a deletion in the last 30 seconds.
		// eBay sends many test pings rapidly — each one runs wc_get_orders which is expensive.
		$rate_key = 'tcgiant_del_rate';
		if ( get_transient( $rate_key ) ) {
			return new WP_REST_Response( array( 'status' => 'rate_limited' ), 200 );
		}
		set_transient( $rate_key, 1, 30 );

		// Query for matching orders — this is the expensive part.
		$orders = wc_get_orders( array(
			'meta_key'   => '_ebay_user_id',
			'meta_value' => $ebay_user_id,
			'limit'      => -1,
		) );

		// Only process and log if we actually find matching orders.
		if ( empty( $orders ) ) {
			return new WP_REST_Response( array( 'status' => 'no_match' ), 200 );
		}

		foreach ( $orders as $order ) {
			$order->set_billing_first_name( 'Deleted' );
			$order->set_billing_last_name( 'User' );
			$order->set_billing_address_1( '' );
			$order->set_billing_address_2( '' );
			$order->set_billing_city( '' );
			$order->set_billing_postcode( '' );
			$order->set_billing_email( 'deleted@ebay.com' );
			$order->set_billing_phone( '' );
			
			$order->set_shipping_first_name( 'Deleted' );
			$order->set_shipping_last_name( 'User' );
			$order->set_shipping_address_1( '' );
			$order->set_shipping_address_2( '' );
			$order->set_shipping_city( '' );
			$order->set_shipping_postcode( '' );

			$order->add_order_note( __( 'Customer data deleted due to eBay Marketplace Account Deletion request.', 'tcgiant-sync' ) );
			$order->save();
		}

		TCGiant_Sync_Logger::log( sprintf( 'eBay Deletion: Scrubbed PII from %d orders for User ID: %s', count( $orders ), $ebay_user_id ), 'success' );

		return new WP_REST_Response( array( 'status' => 'success' ), 200 );
	}
}
