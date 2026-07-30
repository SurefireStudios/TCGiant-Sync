<?php
/**
 * eBay Order Import
 *
 * Fetches eBay orders via Trading API's GetOrders and creates WooCommerce orders.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Orders class
 */
class TCGiant_Sync_Orders {

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

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
	 * Constructor.
	 */
	public function __construct() {
		// Cron hook for auto-importing orders.
		add_action( 'tcgiant_sync_import_orders', array( $this, 'import_recent_orders' ) );

		// Order status change → push tracking to eBay.
		add_action( 'woocommerce_order_status_completed', array( $this, 'push_tracking_to_ebay' ) );

		// Schedule order import cron if enabled.
		add_action( 'init', array( $this, 'schedule_cron' ) );
	}

	/**
	 * Schedule order import cron.
	 */
	public function schedule_cron() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		if ( empty( $settings['enable_order_import'] ) ) {
			wp_clear_scheduled_hook( 'tcgiant_sync_import_orders' );
			return;
		}

		if ( ! wp_next_scheduled( 'tcgiant_sync_import_orders' ) ) {
			wp_schedule_event( time(), 'tcgiant_15mins', 'tcgiant_sync_import_orders' );
		}
	}

	/**
	 * Import recent eBay orders (last 24 hours).
	 */
	public function import_recent_orders() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		if ( empty( $settings['enable_order_import'] ) ) {
			return;
		}

		TCGiant_Sync_Logger::log( 'Order Import: Fetching recent eBay orders...' );

		$orders = $this->fetch_orders();
		if ( is_wp_error( $orders ) ) {
			TCGiant_Sync_Logger::error( 'Order Import: Failed — ' . $orders->get_error_message() );
			return;
		}

		if ( empty( $orders ) ) {
			TCGiant_Sync_Logger::log( 'Order Import: No new orders found.' );
			return;
		}

		$created = 0;
		$skipped = 0;

		foreach ( $orders as $ebay_order ) {
			$order_id = $ebay_order['OrderID'] ?? '';

			// Deduplication: check if this eBay order was already imported.
			if ( $this->order_exists( $order_id ) ) {
				$skipped++;
				continue;
			}

			$wc_order = $this->create_wc_order( $ebay_order );
			if ( $wc_order ) {
				$created++;
			}
		}

		// Update last import timestamp.
		$settings['last_order_import'] = current_time( 'mysql' );
		update_option( 'tcgiant_sync_ebay_settings', $settings );

		TCGiant_Sync_Logger::log( sprintf(
			'Order Import: Complete. %d created, %d skipped (already imported).',
			$created, $skipped
		), 'success' );
	}

	/**
	 * Fetch orders from eBay via GetOrders Trading API.
	 *
	 * @return array|WP_Error Array of order data or error.
	 */
	private function fetch_orders() {
		$api = TCGiant_Sync_API::instance();

		// Fetch orders from the last 24 hours.
		$from = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - DAY_IN_SECONDS );
		$to   = gmdate( 'Y-m-d\TH:i:s.000\Z' );

		$xml = '
<CreateTimeFrom>' . $from . '</CreateTimeFrom>
<CreateTimeTo>' . $to . '</CreateTimeTo>
<OrderRole>Seller</OrderRole>
<OrderStatus>All</OrderStatus>
<Pagination>
<EntriesPerPage>100</EntriesPerPage>
<PageNumber>1</PageNumber>
</Pagination>';

		$result = $api->trading_api_request( 'GetOrders', $xml );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$orders = array();
		if ( isset( $result['OrderArray']['Order'] ) ) {
			$order_list = $result['OrderArray']['Order'];
			// Single order vs. multiple orders.
			if ( isset( $order_list['OrderID'] ) ) {
				$orders[] = $order_list;
			} else {
				$orders = $order_list;
			}
		}

		return $orders;
	}

	/**
	 * Check if an eBay order has already been imported.
	 *
	 * @param string $ebay_order_id eBay Order ID.
	 * @return bool
	 */
	private function order_exists( $ebay_order_id ) {
		if ( empty( $ebay_order_id ) ) {
			return false;
		}

		global $wpdb;

		// Check in HPOS orders meta if available, fall back to post meta.
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table = $wpdb->prefix . 'wc_orders_meta';
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE meta_key = '_ebay_order_id' AND meta_value = %s",
				$ebay_order_id
			) );
		} else {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_ebay_order_id' AND meta_value = %s",
				$ebay_order_id
			) );
		}

		return (int) $exists > 0;
	}

	/**
	 * Create a WooCommerce order from eBay order data.
	 *
	 * @param array $ebay_order eBay order data from GetOrders.
	 * @return WC_Order|false Created order or false on failure.
	 */
	private function create_wc_order( $ebay_order ) {
		$order_id    = $ebay_order['OrderID'] ?? '';
		$buyer_email = $ebay_order['BuyerUserID'] ?? '';
		$total       = (float) ( $ebay_order['Total'] ?? 0 );
		$status      = $ebay_order['OrderStatus'] ?? '';

		try {
			$wc_order = wc_create_order();
			if ( is_wp_error( $wc_order ) ) {
				TCGiant_Sync_Logger::error( 'Order Import: Failed to create WC order — ' . $wc_order->get_error_message() );
				return false;
			}

			// Set billing/shipping address.
			$address = $this->parse_address( $ebay_order );
			$wc_order->set_address( $address, 'billing' );
			$wc_order->set_address( $address, 'shipping' );

			// Set buyer email if available.
			$buyer_info = $ebay_order['TransactionArray']['Transaction'] ?? array();
			$buyer_transactions = isset( $buyer_info['Buyer'] ) ? array( $buyer_info ) : $buyer_info;

			if ( ! empty( $buyer_transactions ) ) {
				$first_tx = $buyer_transactions[0] ?? array();
				$buyer_data = $first_tx['Buyer'] ?? array();
				$email = $buyer_data['Email'] ?? '';
				if ( ! empty( $email ) && is_email( $email ) ) {
					$wc_order->set_billing_email( $email );

					// Find or create customer.
					$customer_id = email_exists( $email );
					if ( $customer_id ) {
						$wc_order->set_customer_id( $customer_id );
					}
				}
			}

			// Add line items.
			$transactions = $this->get_transactions( $ebay_order );
			foreach ( $transactions as $tx ) {
				$this->add_line_item( $wc_order, $tx );
			}

			// Set shipping cost.
			$shipping_cost = (float) ( $ebay_order['ShippingServiceSelected']['ShippingServiceCost'] ?? 0 );
			if ( $shipping_cost > 0 ) {
				$shipping_item = new WC_Order_Item_Shipping();
				$shipping_item->set_method_title( 'eBay Shipping' );
				$shipping_item->set_total( $shipping_cost );
				$wc_order->add_item( $shipping_item );
			}

			// Set order totals.
			$wc_order->set_total( $total );

			// Set order status.
			$wc_status = $this->map_order_status( $status );
			$wc_order->set_status( $wc_status );

			// Store eBay metadata.
			$wc_order->update_meta_data( '_ebay_order_id', $order_id );
			$wc_order->update_meta_data( '_ebay_order_source', 'ebay' );
			$wc_order->update_meta_data( '_ebay_buyer_user_id', $buyer_email );

			// Add order note.
			$wc_order->add_order_note( sprintf(
				/* translators: %s: eBay Order ID */
				__( 'Imported from eBay Order #%s', 'tcgiant-sync' ),
				$order_id
			) );

			$wc_order->save();

			TCGiant_Sync_Logger::log( sprintf(
				'Order Import: Created WC Order #%d from eBay Order %s ($%s)',
				$wc_order->get_id(), $order_id, number_format( $total, 2 )
			), 'success' );

			return $wc_order;

		} catch ( \Exception $e ) {
			TCGiant_Sync_Logger::error( 'Order Import: Exception — ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Parse address from eBay order data.
	 *
	 * @param array $ebay_order eBay order data.
	 * @return array WooCommerce address array.
	 */
	private function parse_address( $ebay_order ) {
		$shipping = $ebay_order['ShippingAddress'] ?? array();
		$name     = $shipping['Name'] ?? '';
		$parts    = explode( ' ', $name, 2 );

		return array(
			'first_name' => $parts[0] ?? '',
			'last_name'  => $parts[1] ?? '',
			'address_1'  => $shipping['Street1'] ?? '',
			'address_2'  => $shipping['Street2'] ?? '',
			'city'       => $shipping['CityName'] ?? '',
			'state'      => $shipping['StateOrProvince'] ?? '',
			'postcode'   => $shipping['PostalCode'] ?? '',
			'country'    => $shipping['Country'] ?? '',
			'phone'      => $shipping['Phone'] ?? '',
		);
	}

	/**
	 * Get transaction items from eBay order.
	 *
	 * @param array $ebay_order eBay order data.
	 * @return array Normalized array of transactions.
	 */
	private function get_transactions( $ebay_order ) {
		$tx_data = $ebay_order['TransactionArray']['Transaction'] ?? array();

		// Single transaction vs. multiple.
		if ( isset( $tx_data['TransactionID'] ) ) {
			return array( $tx_data );
		}

		return $tx_data;
	}

	/**
	 * Add a line item to a WC order from an eBay transaction.
	 *
	 * @param WC_Order $wc_order    WooCommerce order.
	 * @param array    $transaction eBay transaction data.
	 */
	private function add_line_item( $wc_order, $transaction ) {
		$item_data   = $transaction['Item'] ?? array();
		$ebay_item_id = $item_data['ItemID'] ?? '';
		$title       = $item_data['Title'] ?? 'eBay Item';
		$qty         = (int) ( $transaction['QuantityPurchased'] ?? 1 );
		$price       = (float) ( $transaction['TransactionPrice'] ?? 0 );

		// Try to match to a WC product.
		$wc_product = null;
		if ( ! empty( $ebay_item_id ) ) {
			global $wpdb;
			$product_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ebay_item_id' AND meta_value = %s LIMIT 1",
				$ebay_item_id
			) );
			if ( $product_id ) {
				$wc_product = wc_get_product( $product_id );
			}
		}

		if ( $wc_product ) {
			$item = new WC_Order_Item_Product();
			$item->set_product( $wc_product );
			$item->set_quantity( $qty );
			$item->set_subtotal( $price * $qty );
			$item->set_total( $price * $qty );

			// Reduce stock with circular-update guard.
			TCGiant_Sync_Inventory::mark_ebay_origin( $wc_product->get_id() );
		} else {
			// Unmatched product — create a generic line item.
			$item = new WC_Order_Item_Product();
			$item->set_name( $title );
			$item->set_quantity( $qty );
			$item->set_subtotal( $price * $qty );
			$item->set_total( $price * $qty );
		}

		$item->add_meta_data( '_ebay_item_id', $ebay_item_id, true );
		$wc_order->add_item( $item );
	}

	/**
	 * Map eBay order status to WooCommerce order status.
	 *
	 * @param string $ebay_status eBay order status.
	 * @return string WC order status.
	 */
	private function map_order_status( $ebay_status ) {
		switch ( strtolower( $ebay_status ) ) {
			case 'completed':
				return 'processing';
			case 'cancelled':
			case 'cancelcomplete':
				return 'cancelled';
			case 'inactive':
				return 'on-hold';
			default:
				return 'processing';
		}
	}

	/**
	 * Push tracking info to eBay when WC order is marked completed.
	 *
	 * @param int $order_id WC Order ID.
	 */
	public function push_tracking_to_ebay( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$ebay_order_id = $order->get_meta( '_ebay_order_id' );
		if ( empty( $ebay_order_id ) ) {
			return; // Not an eBay order.
		}

		// Get tracking number from common tracking plugins.
		$tracking_number  = $order->get_meta( '_tracking_number' );
		$tracking_carrier = $order->get_meta( '_tracking_provider' );

		// Fallback to WooCommerce Shipment Tracking plugin format.
		if ( empty( $tracking_number ) ) {
			$tracking_items = $order->get_meta( '_wc_shipment_tracking_items' );
			if ( ! empty( $tracking_items ) && is_array( $tracking_items ) ) {
				$first = $tracking_items[0] ?? array();
				$tracking_number  = $first['tracking_number'] ?? '';
				$tracking_carrier = $first['tracking_provider'] ?? '';
			}
		}

		if ( empty( $tracking_number ) ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Order #%d: No tracking number found. Skipping eBay tracking push.',
				$order_id
			) );
			return;
		}

		// Get eBay line item IDs from the order.
		$line_item_id = '';
		$transaction_id = '';
		foreach ( $order->get_items() as $item ) {
			$ebay_item_id = $item->get_meta( '_ebay_item_id' );
			if ( ! empty( $ebay_item_id ) ) {
				$line_item_id = $ebay_item_id;
				break;
			}
		}

		if ( empty( $line_item_id ) ) {
			return;
		}

		$api = TCGiant_Sync_API::instance();
		$xml = '
<OrderID>' . esc_attr( $ebay_order_id ) . '</OrderID>
<Shipment>
<ShipmentTrackingDetails>
<ShipmentTrackingNumber>' . esc_attr( $tracking_number ) . '</ShipmentTrackingNumber>
<ShippingCarrierUsed>' . esc_attr( $tracking_carrier ?: 'Other' ) . '</ShippingCarrierUsed>
</ShipmentTrackingDetails>
</Shipment>
<Shipped>true</Shipped>';

		$result = $api->trading_api_request( 'CompleteSale', $xml );

		if ( is_wp_error( $result ) ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Order #%d: Failed to push tracking to eBay — %s',
				$order_id, $result->get_error_message()
			) );
		} else {
			$order->add_order_note( sprintf(
				__( 'Tracking pushed to eBay: %s (%s)', 'tcgiant-sync' ),
				$tracking_number, $tracking_carrier ?: 'Other'
			) );
			TCGiant_Sync_Logger::log( sprintf(
				'Order #%d: Tracking pushed to eBay (%s).',
				$order_id, $tracking_number
			), 'success' );
		}
	}
}
