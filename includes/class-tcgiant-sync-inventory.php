<?php
/**
 * Inventory Synchronization Logic
 *
 * Handles bidirectional stock updates between WooCommerce and eBay.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Inventory class
 */
class TCGiant_Sync_Inventory {

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
		// WooCommerce to eBay stock sync.
		add_action( 'woocommerce_reduce_order_stock', array( $this, 'sync_stock_to_ebay' ) );
		
		// Background task for eBay update.
		add_action( 'tcgiant_sync_update_ebay_stock', array( $this, 'process_ebay_stock_update' ), 10, 2 );
	}

	/**
	 * Hook: When WooCommerce reduces stock for an order.
	 *
	 * @param WC_Order $order The order object.
	 */
	public function sync_stock_to_ebay( $order ) {
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$sku = $product->get_sku();
			$ebay_sku = $product->get_meta( '_ebay_sku' );

			if ( empty( $ebay_sku ) ) {
				$ebay_sku = $sku; // Fallback to SKU.
			}

			if ( empty( $ebay_sku ) ) {
				continue;
			}

			$new_stock = $product->get_stock_quantity();

			// Schedule background update to eBay.
			// We use background sync to avoid slowing down the customer checkout.
			as_enqueue_async_action( 'tcgiant_sync_update_ebay_stock', array( 
				'sku' => $ebay_sku, 
				'quantity' => $new_stock 
			), 'tcgiant_sync_group' );
		}
	}

	/**
	 * Process the background update to eBay.
	 *
	 * @param string $sku      eBay SKU.
	 * @param int    $quantity New stock quantity.
	 */
	public function process_ebay_stock_update( $sku, $quantity ) {
		$api = TCGiant_Sync_API::instance();
		
		TCGiant_Sync_Logger::log( sprintf( 'Syncing stock to eBay for SKU %s: New Quantity %d', $sku, $quantity ) );
		
		$result = $api->update_inventory_item_availability( $sku, $quantity );

		if ( is_wp_error( $result ) ) {
			TCGiant_Sync_Logger::error( sprintf( 'Failed to sync stock to eBay for SKU %s. Error: %s', $sku, $result->get_error_message() ) );
		} else {
			TCGiant_Sync_Logger::log( sprintf( 'Successfully synced stock to eBay for SKU %s', $sku ) );
		}
	}

	/**
	 * Poll eBay for changes (Fallback strategy).
	 */
	public function poll_ebay_for_stock_changes() {
		// This can be triggered by WP-Cron.
		// It would iterate through products and check eBay for current stock.
		// For a prototype/production V1, we focus on the real-time Woo -> eBay 
		// and manual sync for eBay -> Woo.
	}
}
