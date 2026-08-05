<?php
/**
 * Inventory Synchronization Logic
 *
 * Handles bidirectional stock updates between WooCommerce and eBay.
 * Features: real-time stock sync, background reconciliation, auto-end on zero.
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
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * Depth counter for suppressed WC → eBay stock pushes.
	 *
	 * Incremented while the plugin is itself writing stock that came FROM eBay.
	 * A counter rather than a boolean so nested calls (product save that also
	 * saves variations) unwind correctly.
	 *
	 * @var int
	 */
	private static $push_suppression_depth = 0;

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
	 * Begin a block of stock writes that originate from eBay.
	 *
	 * WooCommerce fires woocommerce_product_set_stock / _variation_set_stock
	 * from WC_Product_Data_Store_CPT::handle_updated_props() whenever
	 * stock_quantity changes — including during an import, where the value came
	 * from eBay in the first place. Without this guard every imported product
	 * queues a redundant push back to eBay, and any item mapped to 0 stock
	 * triggers auto-end and permanently ENDS the seller's live listing.
	 *
	 * Always pair with end_ebay_origin() in a finally block.
	 */
	public static function begin_ebay_origin() {
		self::$push_suppression_depth++;
	}

	/**
	 * End a block of eBay-originated stock writes.
	 */
	public static function end_ebay_origin() {
		if ( self::$push_suppression_depth > 0 ) {
			self::$push_suppression_depth--;
		}
	}

	/**
	 * Whether WC → eBay stock pushes are currently suppressed.
	 *
	 * @return bool
	 */
	public static function is_push_suppressed() {
		return self::$push_suppression_depth > 0;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		// WooCommerce to eBay stock sync (order stock reduction).
		add_action( 'woocommerce_reduce_order_stock', array( $this, 'sync_stock_to_ebay' ) );

		// eBay has already decremented its own quantity by the time we hear about
		// an order, and the importer writes that quantity straight into
		// WooCommerce. Letting WooCommerce reduce stock again for the same sale
		// took two off for every one sold.
		add_filter( 'woocommerce_can_reduce_order_stock', array( $this, 'block_double_reduction' ), 10, 2 );
		
		// Real-time stock sync: manual stock edits, CSV imports, REST API updates.
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_product_stock_change' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_variation_stock_change' ) );

		// Background task for eBay update.
		add_action( 'tcgiant_sync_update_ebay_stock', array( $this, 'process_ebay_stock_update' ), 10, 2 );

		// Weekly inventory reconciliation.
		add_action( 'tcgiant_sync_reconcile_inventory', array( $this, 'reconcile_inventory' ) );
		add_action( 'init', array( $this, 'schedule_reconciliation' ) );
	}

	/**
	 * Schedule weekly inventory reconciliation if enabled.
	 */
	public function schedule_reconciliation() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$enabled = ! isset( $settings['enable_reconciliation'] ) || ! empty( $settings['enable_reconciliation'] );

		if ( $enabled && ! wp_next_scheduled( 'tcgiant_sync_reconcile_inventory' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'tcgiant_sync_reconcile_inventory' );
		} elseif ( ! $enabled ) {
			wp_clear_scheduled_hook( 'tcgiant_sync_reconcile_inventory' );
		}
	}

	// ───────────────────────────────────────────────────────────────────────────
	// Real-time stock sync (WC → eBay)
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Detect when a product's stock changes (manual edit, REST API, CSV import).
	 *
	 * @param WC_Product $product The product whose stock changed.
	 */
	public function on_product_stock_change( $product ) {
		if ( ! $product ) {
			return;
		}

		// Circular update guard: don't push to eBay if this change came FROM eBay.
		if ( $this->is_ebay_origin_change( $product->get_id() ) ) {
			return;
		}

		// Don't fire during checkout (the order hook handles that separately).
		if ( $this->is_checkout_context() ) {
			return;
		}

		$this->queue_stock_update_for_product( $product );
	}

	/**
	 * Detect when a variation's stock changes.
	 *
	 * @param WC_Product_Variation $variation The variation whose stock changed.
	 */
	public function on_variation_stock_change( $variation ) {
		if ( ! $variation ) {
			return;
		}

		if ( $this->is_ebay_origin_change( $variation->get_id() ) ) {
			return;
		}

		if ( $this->is_checkout_context() ) {
			return;
		}

		$this->queue_stock_update_for_product( $variation );
	}

	/**
	 * Check if a stock change originated from eBay sync (prevents circular updates).
	 *
	 * @param int $product_id Product/Variation ID.
	 * @return bool True if this is an eBay-originated change.
	 */
	private function is_ebay_origin_change( $product_id ) {
		return (bool) get_transient( 'tcgiant_stock_from_ebay_' . $product_id );
	}

	/**
	 * Mark a product as being updated from eBay (to prevent circular updates).
	 *
	 * Call this before setting WC stock from eBay data.
	 *
	 * @param int $product_id Product/Variation ID.
	 */
	public static function mark_ebay_origin( $product_id ) {
		set_transient( 'tcgiant_stock_from_ebay_' . $product_id, 1, 30 );
	}

	/**
	 * Check if we're in a checkout/order context.
	 *
	 * @return bool
	 */
	private function is_checkout_context() {
		if ( did_action( 'woocommerce_checkout_order_processed' ) ) {
			return true;
		}
		if ( did_action( 'woocommerce_reduce_order_stock' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Queue a stock update for a single product/variation.
	 *
	 * @param WC_Product $product Product or variation object.
	 */
	private function queue_stock_update_for_product( $product ) {
		// This stock value came from eBay — pushing it back would be an echo.
		if ( self::is_push_suppressed() ) {
			return;
		}

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );

		// Master kill-switch.
		if ( isset( $settings['enable_order_sync'] ) && '0' === $settings['enable_order_sync'] ) {
			return;
		}

		$ebay_item_id = $product->get_meta( '_ebay_item_id' );
		$ebay_sku     = $product->get_meta( '_ebay_sku' );

		// Must have an eBay link to sync.
		if ( empty( $ebay_item_id ) && empty( $ebay_sku ) ) {
			$sku = $product->get_sku();
			if ( empty( $sku ) ) {
				return;
			}
			$ebay_sku = $sku; // Fallback.
		}

		if ( empty( $ebay_sku ) ) {
			$ebay_sku = $product->get_sku();
		}

		if ( empty( $ebay_sku ) ) {
			return;
		}

		$new_stock = $product->get_stock_quantity();

		// Dedup: don't queue if an identical update is already pending.
		$dedup_key = 'tcgiant_stock_pending_' . md5( $ebay_sku . '_' . $new_stock );
		if ( get_transient( $dedup_key ) ) {
			return;
		}
		set_transient( $dedup_key, 1, 60 );

		TCGiant_Sync_Logger::log( sprintf(
			'Real-time stock sync: WC #%d (SKU: %s) stock changed to %d — queuing eBay update.',
			$product->get_id(), $ebay_sku, $new_stock
		) );

		as_enqueue_async_action( 'tcgiant_sync_update_ebay_stock', array( 
			'sku'      => $ebay_sku, 
			'quantity' => $new_stock 
		), 'tcgiant_sync_group' );
	}

	// ───────────────────────────────────────────────────────────────────────────
	// Order-based stock sync (existing logic)
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Hook: When WooCommerce reduces stock for an order.
	 *
	 * @param WC_Order $order The order object.
	 */
	public function block_double_reduction( $can_reduce, $order ) {
		if ( ! $can_reduce || ! is_a( $order, 'WC_Abstract_Order' ) ) {
			return $can_reduce;
		}

		if ( 'ebay' !== $order->get_meta( '_ebay_order_source' ) ) {
			return $can_reduce;
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Order #%d came from eBay, which already deducted the quantity — leaving WooCommerce stock to the sync.',
			$order->get_id()
		) );

		return false;
	}

	/**
	 * Push a WooCommerce stock change out to eBay.
	 *
	 * @param WC_Order $order Order whose stock was just reduced.
	 */
	public function sync_stock_to_ebay( $order ) {
		// Reducing stock for an order that was itself imported from eBay is an
		// eBay-originated change; eBay already decremented its own quantity.
		if ( self::is_push_suppressed() ) {
			return;
		}

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );

		// Respect the master kill-switch if set.
		if ( isset( $settings['enable_order_sync'] ) && '0' === $settings['enable_order_sync'] ) {
			return;
		}

		$woo_sync_cats = isset( $settings['woo_category_ids'] ) && is_array( $settings['woo_category_ids'] ) ? $settings['woo_category_ids'] : array();

		foreach ( $order->get_items() as $item ) {
			// Shipping, fee and tax rows are plain WC_Order_Item objects and
			// have no get_product().
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			// If specific WooCommerce categories were chosen, filter items out.
			if ( ! empty( $woo_sync_cats ) ) {
				$product_id   = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
				$product_cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $product_cats ) ) {
					$intersect = array_intersect( $woo_sync_cats, $product_cats );
					if ( empty( $intersect ) ) {
						continue; // The product is not in any of the allowed syncer categories.
					}
				}
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

	// ───────────────────────────────────────────────────────────────────────────
	// Background stock update processor
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Process the background update to eBay.
	 *
	 * Uses ReviseInventoryStatus for FixedPrice items (lightweight API call).
	 * Auto-ends listing when quantity reaches 0.
	 *
	 * @param string $sku      eBay SKU.
	 * @param int    $quantity New stock quantity.
	 */
	public function process_ebay_stock_update( $sku, $quantity ) {
		$api = TCGiant_Sync_API::instance();
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$auto_end = ! isset( $settings['auto_end_zero_stock'] ) || ! empty( $settings['auto_end_zero_stock'] );
		
		TCGiant_Sync_Logger::log( sprintf( 'Syncing stock to eBay for SKU %s: New Quantity %d', $sku, $quantity ) );

		// Auto-end when quantity hits 0 (eBay rejects qty=0 via ReviseInventoryStatus).
		if ( $auto_end && (int) $quantity <= 0 ) {
			$item_id = $this->find_ebay_item_id_by_sku( $sku );
			if ( $item_id ) {
				// Check if listing is already marked as ended locally.
				$local_status = get_post_meta( $this->find_product_id_by_sku( $sku ) ?: 0, '_ebay_listing_status', true );
				if ( 'Ended' === $local_status ) {
					TCGiant_Sync_Logger::log( sprintf(
						'SKU %s (eBay #%s) — listing already ended locally, skipping EndItem call.',
						$sku, $item_id
					) );
					return;
				}

				TCGiant_Sync_Logger::log( sprintf(
					'Auto-ending eBay listing %s (SKU: %s) — stock reached 0.',
					$item_id, $sku
				) );
				$result = $api->end_item( $item_id );
				if ( is_wp_error( $result ) ) {
					$err_msg = $result->get_error_message();
					// eBay error 1047 = "The auction has already been closed" — treat as success.
					if ( strpos( $err_msg, 'already been closed' ) !== false
						|| strpos( $err_msg, 'ended' ) !== false
						|| strpos( $err_msg, 'No changes allowed' ) !== false
					) {
						TCGiant_Sync_Logger::log( sprintf(
							'eBay listing %s was already ended on eBay — updating local status.',
							$item_id
						) );
					} else {
						TCGiant_Sync_Logger::error( sprintf(
							'Failed to auto-end eBay listing %s: %s',
							$item_id, $err_msg
						) );
					}
				} else {
					TCGiant_Sync_Logger::log( sprintf( 'Successfully ended eBay listing %s.', $item_id ), 'success' );
				}
				// Always mark local status as Ended when stock is 0.
				$product_id = $this->find_product_id_by_sku( $sku );
				if ( $product_id ) {
					update_post_meta( $product_id, '_ebay_listing_status', 'Ended' );
				}
				return;
			}
		}
		
		$result = $api->update_inventory_item_availability( $sku, $quantity );

		if ( is_wp_error( $result ) ) {
			$err_msg  = $result->get_error_message();
			$err_code = $result->get_error_code();

			if ( 'not_found_on_ebay' === $err_code ) {
				TCGiant_Sync_Logger::log( sprintf( 'Skipped eBay sync for SKU %s: Item is not linked to eBay.', $sku ) );
			} elseif ( strpos( $err_msg, 'No changes allowed' ) !== false
				|| strpos( $err_msg, 'ended' ) !== false
				|| strpos( $err_msg, 'already been closed' ) !== false
			) {
				// Listing ended on eBay — update local status silently.
				TCGiant_Sync_Logger::log( sprintf(
					'SKU %s: eBay listing is ended — skipping stock update, marking local status as Ended.',
					$sku
				) );
				$product_id = $this->find_product_id_by_sku( $sku );
				if ( $product_id ) {
					update_post_meta( $product_id, '_ebay_listing_status', 'Ended' );
				}
			} else {
				TCGiant_Sync_Logger::error( sprintf( 'Failed to sync stock to eBay for SKU %s. Error: %s', $sku, $err_msg ) );
			}
		} else {
			TCGiant_Sync_Logger::log( sprintf( 'Successfully synced stock to eBay for SKU %s', $sku ) );
		}
	}

	/**
	 * Find an eBay Item ID by SKU from post meta.
	 *
	 * @param string $sku eBay SKU.
	 * @return string|false eBay Item ID or false.
	 */
	private function find_ebay_item_id_by_sku( $sku ) {
		global $wpdb;

		// Try _ebay_sku first, then WC SKU. Exclude trashed products.
		$product_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_status != 'trash'
			 WHERE pm.meta_key = '_ebay_sku' AND pm.meta_value = %s LIMIT 1",
			$sku
		) );

		if ( ! $product_id ) {
			$product_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_status != 'trash'
				 WHERE pm.meta_key = '_sku' AND pm.meta_value = %s LIMIT 1",
				$sku
			) );
		}

		if ( $product_id ) {
			return get_post_meta( $product_id, '_ebay_item_id', true );
		}

		return false;
	}

	/**
	 * Find a WC product ID by eBay SKU.
	 *
	 * @param string $sku eBay SKU.
	 * @return int|false Product ID or false.
	 */
	private function find_product_id_by_sku( $sku ) {
		global $wpdb;
		$product_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_status != 'trash'
			 WHERE pm.meta_key = '_ebay_sku' AND pm.meta_value = %s LIMIT 1",
			$sku
		) );
		return $product_id ? (int) $product_id : false;
	}

	// ───────────────────────────────────────────────────────────────────────────
	// Inventory Reconciliation
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Background inventory reconciliation.
	 *
	 * Compares WooCommerce stock quantities against eBay active listing quantities.
	 * Logs mismatches as warnings.
	 */
	public function reconcile_inventory() {
		// Runs unattended from WP-Cron, where an uncaught Error takes down every
		// hook scheduled after it in the same tick.
		try {
			$this->do_reconcile_inventory();
		} catch ( Exception $e ) {
			TCGiant_Sync_Logger::error( 'Reconciliation: Exception — ' . $e->getMessage() );
		} catch ( Error $e ) {
			TCGiant_Sync_Logger::error( 'Reconciliation: Fatal — ' . $e->getMessage() );
		}
	}

	/**
	 * Maximum listing pages to walk during one reconciliation run.
	 */
	const RECONCILE_MAX_PAGES = 25;

	/**
	 * Perform the reconciliation pass.
	 *
	 * Compares WooCommerce stock quantities against eBay active listing
	 * quantities across all pages and logs mismatches as warnings.
	 */
	private function do_reconcile_inventory() {
		TCGiant_Sync_Logger::log( 'Reconciliation: Starting weekly inventory check...' );

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		if ( isset( $settings['enable_reconciliation'] ) && empty( $settings['enable_reconciliation'] ) ) {
			TCGiant_Sync_Logger::log( 'Reconciliation: Disabled in settings. Skipping.' );
			return;
		}

		$api         = TCGiant_Sync_API::instance();
		$mismatches  = 0;
		$checked     = 0;
		$page        = 1;
		$total_pages = 1;

		global $wpdb;

		do {
			// get_active_listings() is the real GetSellerList wrapper. The old
			// call here was to a get_seller_list() method that has never
			// existed, so this task fatalled on every run.
			$result = $api->get_active_listings( $page, 200 );

			if ( is_wp_error( $result ) ) {
				TCGiant_Sync_Logger::error( sprintf(
					'Reconciliation: Failed to fetch eBay listings on page %d — %s',
					$page,
					$result->get_error_message()
				) );
				break;
			}

			if ( ! isset( $result['ItemArray']['Item'] ) ) {
				break;
			}

			// The Trading API returns a bare item object when only one matches.
			$items = $result['ItemArray']['Item'];
			if ( isset( $items['ItemID'] ) ) {
				$items = array( $items );
			}

			$total_pages = isset( $result['PaginationResult']['TotalNumberOfPages'] )
				? (int) $result['PaginationResult']['TotalNumberOfPages']
				: 1;

			foreach ( $items as $item ) {
				$item_id  = $item['ItemID'] ?? '';
				$ebay_qty = isset( $item['Quantity'] ) ? (int) $item['Quantity'] : null;
				$title    = $item['Title'] ?? 'Unknown';

				if ( empty( $item_id ) || null === $ebay_qty ) {
					continue;
				}

				// eBay reports the listed quantity; available stock is that
				// minus what has already sold.
				$sold = isset( $item['SellingStatus']['QuantitySold'] ) ? (int) $item['SellingStatus']['QuantitySold'] : 0;
				$ebay_available = max( 0, $ebay_qty - $sold );

				// Find the WC product by eBay Item ID.
				$product_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT pm.post_id FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_status != 'trash'
					 WHERE pm.meta_key = '_ebay_item_id' AND pm.meta_value = %s LIMIT 1",
					$item_id
				) );

				if ( ! $product_id ) {
					continue; // Not linked to a WC product.
				}

				$product = wc_get_product( $product_id );
				if ( ! $product || ! $product->managing_stock() ) {
					continue;
				}

				$wc_qty = (int) $product->get_stock_quantity();
				$checked++;

				if ( $wc_qty !== $ebay_available ) {
					$mismatches++;
					TCGiant_Sync_Logger::warning( sprintf(
						'Reconciliation mismatch: "%s" (eBay #%s, WC #%d) — WC stock: %d, eBay available: %d',
						$title, $item_id, $product_id, $wc_qty, $ebay_available
					) );
				}
			}

			$page++;
		} while ( $page <= $total_pages && $page <= self::RECONCILE_MAX_PAGES );

		if ( $total_pages > self::RECONCILE_MAX_PAGES ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Reconciliation: Stopped at the %d-page cap (%d pages available). Remaining listings were not checked.',
				self::RECONCILE_MAX_PAGES, $total_pages
			) );
		}

		// Re-read settings — the loop above can span minutes, and writing a
		// stale copy back would clobber any change made in the meantime.
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$settings['last_reconciliation'] = current_time( 'mysql' );
		$settings['last_reconciliation_mismatches'] = $mismatches;
		update_option( 'tcgiant_sync_ebay_settings', $settings );

		TCGiant_Sync_Logger::log( sprintf(
			'Reconciliation: Complete. Checked %d items, found %d mismatch(es).',
			$checked, $mismatches
		), $mismatches > 0 ? 'warning' : 'success' );
	}
}
