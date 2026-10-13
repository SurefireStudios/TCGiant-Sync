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
	 * Everything recorded about how one ended listing was settled. All of it
	 * is about that listing, so all of it goes when a new one starts.
	 */
	const SETTLE_KEYS = array( '_ebay_stock_settled_at', '_ebay_stock_settled_item', '_ebay_unsold_at_end', '_ebay_quantity_listed', '_ebay_quantity_sold', '_ebay_restock_reviewed' );

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
	 * Bring a product's stock into line once its eBay listing has ended.
	 *
	 * A listing holding one of something ends the moment it sells, so an ended
	 * listing is where a sale of a single item is seen - the ordinary import
	 * never runs for one.
	 *
	 * eBay's figures only ever LOWER WooCommerce's stock here, never raise it.
	 * They used to be written over it outright, as "listed minus sold". But eBay
	 * only knows about eBay's sales. A coin sold on the shop's own website had
	 * its stock taken to 0 by WooCommerce; the plugin then ended the eBay
	 * listing, which eBay records as 1 listed and 0 sold - and the next sync
	 * wrote 1 back, putting a coin that had already gone up for sale again.
	 *
	 * So the lower of the two figures stands. eBay's is lower when it sold
	 * something WooCommerce has not heard about yet; WooCommerce's is lower when
	 * the sale happened on the website, or someone adjusted it by hand. Where
	 * eBay says more remain than WooCommerce holds, that is recorded and logged
	 * rather than applied: under-stocking can be put right later, an oversell
	 * cannot.
	 *
	 * @param int      $product_id WooCommerce product.
	 * @param int|null $listed     Quantity the listing held.
	 * @param int|null $sold       Quantity eBay reports as sold.
	 * @return int|null Stock WooCommerce now holds (0 for an unmanaged product
	 *                  that is out of stock), or null when eBay gave nothing to
	 *                  go on or the write did not take.
	 */
	public static function apply_ended_listing_stock( $product_id, $listed, $sold ) {
		if ( null === $listed || null === $sold ) {
			return null;
		}

		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return null;
		}

		$product_id     = (int) $product_id;
		$ebay_remaining = max( 0, (int) $listed - (int) $sold );

		// eBay has already accounted for its own sales, so nothing written here
		// may travel back to eBay as a stock change of ours.
		self::begin_ebay_origin();

		try {
			if ( $product->managing_stock() ) {
				$current = $product->get_stock_quantity();
				$held    = null === $current ? $ebay_remaining : min( (int) $current, $ebay_remaining );

				// Recorded when the shop still holds some, for a shop that sells
				// only on eBay to review. Not when it holds none: that is the
				// website sale whose listing the plugin then ended, and offering
				// eBay's figure for it would invite putting a sold item back.
				if ( null !== $current && (int) $current > 0 && $ebay_remaining > (int) $current ) {
					self::note_unsold_at_end( $product_id, $ebay_remaining, (int) $current );
				} else {
					delete_post_meta( $product_id, '_ebay_unsold_at_end' );
				}

				if ( null === $current || $held < (int) $current ) {
					wc_update_product_stock( $product_id, $held, 'set' );

					// Confirm the quantity landed before touching anything else.
					//
					// The status write used to come first, so a quantity that
					// failed to save still left the product marked out of stock -
					// which is how the review screen decides a product no longer
					// needs looking at. It would have vanished from the one list
					// that could have caught it, with its quantity untouched.
					$after = wc_get_product( $product_id );

					if ( ! $after || (int) $after->get_stock_quantity() !== $held ) {
						TCGiant_Sync_Logger::warning( sprintf(
							'Tried to set WC #%d to %d after its eBay listing ended, but the quantity did not change. Something else on this site is holding it, so nothing further was altered and the product stays on the Stock Review list.',
							$product_id,
							$held
						) );

						// finally still runs, so the origin guard closes cleanly.
						return null;
					}
				}

				// Out of stock when nothing is left; never back IN stock from here,
				// for the same reason the quantity never rises.
				if ( $held <= 0 ) {
					wc_update_product_stock_status( $product_id, 'outofstock' );
				}
			} else {
				// No quantity to compare, only a status: eBay can say it sold out,
				// but not that the shop still has one.
				if ( 0 === $ebay_remaining ) {
					wc_update_product_stock_status( $product_id, 'outofstock' );
				}

				$after = wc_get_product( $product_id );
				$held  = ( $after && 'outofstock' === $after->get_stock_status() ) ? 0 : $ebay_remaining;
			}
		} finally {
			self::end_ebay_origin();
		}

		// Remember that eBay has been asked about this one.
		//
		// A listing that ended without selling reports its stock as still
		// present, quite correctly - the goods are unsold and remain the
		// seller's. But "ended and in stock" is also how an unsettled product
		// looks, so without this the review screen could never tell a product
		// it had already checked from one it had not, and a legitimately
		// unsold item sat on the list for ever, unchanged however many times
		// the button was pressed.
		update_post_meta( $product_id, '_ebay_stock_settled_at', current_time( 'mysql' ) );

		// eBay's own figures, kept so that "sold out on eBay" can be told apart
		// from "sold on the website" later without asking eBay again - it drops
		// old listings.
		update_post_meta( $product_id, '_ebay_quantity_listed', (int) $listed );
		update_post_meta( $product_id, '_ebay_quantity_sold', (int) $sold );

		// And which listing they belong to. A settle is a statement about one
		// listing; a relist or push later needs to know whether THIS listing's
		// sales are already in the stock, or whether stock entered since is the
		// shop's own restock.
		update_post_meta( $product_id, '_ebay_stock_settled_item', (string) get_post_meta( $product_id, '_ebay_item_id', true ) );

		return $held;
	}

	/**
	 * Lower a product's stock to what eBay says is left on a live listing.
	 *
	 * Never raises it: eBay's figure is lower when it sold something WooCommerce
	 * has not heard of, and WooCommerce's is lower when the website did. Either
	 * way the lower one is true.
	 *
	 * @param int $product_id Product.
	 * @param int $available  eBay's listed minus sold.
	 * @return int|null Stock now held, or null when the product does not track
	 *                  a quantity or the write did not take.
	 */
	public static function lower_stock_to( $product_id, $available ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product || ! $product->managing_stock() ) {
			return null;
		}

		$current   = $product->get_stock_quantity();
		$available = max( 0, (int) $available );

		if ( null !== $current && $available >= (int) $current ) {
			return (int) $current;
		}

		self::begin_ebay_origin();

		try {
			wc_update_product_stock( (int) $product_id, $available, 'set' );

			$after = wc_get_product( (int) $product_id );

			if ( ! $after || (int) $after->get_stock_quantity() !== $available ) {
				return null;
			}

			if ( $available <= 0 ) {
				wc_update_product_stock_status( (int) $product_id, 'outofstock' );
			}
		} finally {
			self::end_ebay_origin();
		}

		return $available;
	}

	/**
	 * Record that eBay reported more left than WooCommerce holds.
	 *
	 * Logged once per product per figure, not on every pass.
	 *
	 * @param int $product_id     Product.
	 * @param int $ebay_remaining eBay's listed minus sold.
	 * @param int $current        WooCommerce's stock.
	 */
	private static function note_unsold_at_end( $product_id, $ebay_remaining, $current ) {
		if ( (int) get_post_meta( $product_id, '_ebay_unsold_at_end', true ) === (int) $ebay_remaining ) {
			return;
		}

		update_post_meta( $product_id, '_ebay_unsold_at_end', (int) $ebay_remaining );

		TCGiant_Sync_Logger::log( sprintf(
			'WC #%d: its eBay listing ended with %d unsold, but WooCommerce holds %d. The difference was sold or adjusted outside eBay, so WooCommerce\'s figure stands. Stock Review lists it if you want eBay\'s instead.',
			$product_id,
			$ebay_remaining,
			$current
		) );
	}

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

		// Same guard as the order path. The stock-set hooks should only fire
		// for tracked products, but nothing here depended on that being true,
		// and a null arriving downstream reads as zero and ends the listing.
		if ( null === $new_stock || '' === $new_stock ) {
			return;
		}

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

			// A product that does not track stock has no quantity to report.
			//
			// This hook fires on every order, whatever the line items are, and
			// get_stock_quantity() returns null for an untracked product. That
			// null was queued as the new quantity, arrived at
			// process_ebay_stock_update() where (int) null is 0, and with
			// auto-end-on-zero turned on - which is the default - ENDED the eBay
			// listing. Selling one on the website took the eBay listing with it.
			if ( ! $product->managing_stock() ) {
				continue;
			}

			$new_stock = $product->get_stock_quantity();

			// Never queue an absent figure as though it were zero.
			if ( null === $new_stock || '' === $new_stock ) {
				continue;
			}

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

					// And settled, there and then: the stock is 0, and nothing eBay
					// says about this listing later can be lower. Recorded, so a
					// relist after the shop restocks lists the restock instead of
					// bringing the old listing's figures in over it.
					update_post_meta( $product_id, '_ebay_stock_settled_at', current_time( 'mysql' ) );
					update_post_meta( $product_id, '_ebay_stock_settled_item', (string) $item_id );
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
	 * Forget that a product was settled, because its listing is live again.
	 *
	 * Settling is a statement about one ended listing. Once the item is back on
	 * eBay that statement is spent, and if the new listing ends badly the
	 * product must be reviewable again rather than permanently excused.
	 *
	 * @param int $product_id
	 * @return void
	 */
	public static function clear_settled_mark( $product_id ) {
		foreach ( self::SETTLE_KEYS as $key ) {
			delete_post_meta( (int) $product_id, $key );
		}
	}

	/**
	 * Whether this listing's ending is already in the product's stock.
	 *
	 * @param int    $product_id   Product.
	 * @param string $ebay_item_id The listing.
	 * @return bool
	 */
	public static function listing_already_settled( $product_id, $ebay_item_id ) {
		return '' !== (string) get_post_meta( (int) $product_id, '_ebay_stock_settled_at', true )
			&& (string) $ebay_item_id === (string) get_post_meta( (int) $product_id, '_ebay_stock_settled_item', true );
	}

	/**
	 * Trash a product whose eBay listing has sold out, if the shop wants that.
	 *
	 * Lives here rather than in the importer because this is where a listing is
	 * known to have ended. Removal used to hang off one branch of the delta
	 * sync, while the hourly check and the Stock Review settle saw exactly the
	 * same event and could only zero the stock - so whichever noticed first
	 * decided whether the product was ever removed, and on a large catalogue
	 * the delta sync is the slowest of the three. A shop with fifteen thousand
	 * products watched its sold items stay put and had to write in.
	 *
	 * Every refusal says why. All six were a bare return, and the line that was
	 * logged on the way past reads like success - so a merchant saw what looked
	 * like a normal ending and no explanation, and neither could we.
	 *
	 * @param int      $product_id WooCommerce product.
	 * @param int|null $listed     Quantity the listing held, from eBay.
	 * @param int|null $sold       Quantity eBay reports as sold.
	 * @return bool Whether the product was trashed.
	 */
	public static function maybe_remove_sold_out( $product_id, $listed, $sold ) {
		$product_id = (int) $product_id;

		// Ended is not the same as sold. A listing that ran out without selling,
		// or that the seller ended by hand, leaves goods the shop still owns -
		// which is why those keep their stock rather than being zeroed. Removing
		// the product would contradict the quantity just written.
		if ( null === $listed || null === $sold ) {
			return false;
		}

		if ( (int) $listed < 1 || (int) $sold < (int) $listed ) {
			return false;
		}

		return self::remove_if_allowed( $product_id );
	}

	/**
	 * Trash a product already established to have sold out.
	 *
	 * Separate from the arithmetic above so the same rules can be applied to a
	 * product whose sell-out was established earlier and recorded, without
	 * asking eBay for figures it may no longer hold - eBay drops old listings,
	 * and a backlog is by definition old.
	 *
	 * @param int $product_id
	 * @return bool Whether the product was trashed.
	 */
	public static function remove_if_allowed( $product_id ) {
		$product_id = (int) $product_id;
		$settings   = get_option( 'tcgiant_sync_ebay_settings', array() );

		// Absent means on. This is what the plugin is for, and a shop that would
		// rather keep its products can say so.
		if ( isset( $settings['remove_sold_products'] ) && empty( $settings['remove_sold_products'] ) ) {
			self::log_removal_refused( $product_id, 'the "Remove sold products" setting is off' );

			return false;
		}

		// A product the shop pushed to eBay is its own catalogue entry, not
		// something eBay lent it. Selling out there says nothing about whether
		// the shop still wants the product, and it may be restocked tomorrow.
		//
		// Worth saying out loud, because a shop that lists its own catalogue
		// rather than importing one has this on every product, and would
		// otherwise never learn why "Remove sold products" appears to do
		// nothing at all.
		if ( 'pushed' === get_post_meta( $product_id, '_ebay_export_status', true ) ) {
			self::log_removal_refused( $product_id, 'this product was pushed to eBay from WooCommerce, so it is the shop\'s own catalogue entry and is kept' );

			return false;
		}

		// The same categories the pruner is told to leave alone. Somebody who
		// has said "do not trash these" meant it here too.
		$preserve = isset( $settings['preserve_woo_category_ids'] ) && is_array( $settings['preserve_woo_category_ids'] )
			? $settings['preserve_woo_category_ids']
			: array();

		if ( ! empty( $preserve ) && has_term( $preserve, 'product_cat', $product_id ) ) {
			self::log_removal_refused( $product_id, 'it is in a category set to be preserved' );

			return false;
		}

		wp_trash_post( $product_id );

		return true;
	}

	/**
	 * Say why a sold-out product was kept.
	 *
	 * Once per product rather than once per pass: the same product can be
	 * considered by more than one path in a day, and a shop with a catalogue
	 * that is entirely exempt would otherwise get a line per sale per path.
	 *
	 * @param int    $product_id
	 * @param string $because
	 * @return void
	 */
	private static function log_removal_refused( $product_id, $because ) {
		$key = '_ebay_removal_refused';

		if ( $because === get_post_meta( $product_id, $key, true ) ) {
			return;
		}

		update_post_meta( $product_id, $key, $because );

		TCGiant_Sync_Logger::log( sprintf(
			'WC #%d sold out on eBay but was kept: %s.',
			$product_id,
			$because
		) );
	}

	/**
	 * Products that were probably sold on the website and then put back in
	 * stock by the ended-listing settle before 3.27.0.
	 *
	 * That settle wrote eBay's "listed minus sold" over WooCommerce's stock.
	 * eBay never sees a website sale, so a quantity-1 item sold on the website
	 * - whose listing the plugin then ended - came back as 1 listed, 0 sold,
	 * and went up for sale again. A coin shop found one; this finds the rest.
	 *
	 * Only settles made before 3.27.0 qualify - they are the ones with no
	 * record of which listing they settled - because a settle since then
	 * cannot raise stock.
	 * Of those: ended, in stock, with a paid website order for the product
	 * placed after its listing started. Each comes with that order so the shop
	 * can judge it - a hand restock after the sale looks the same from here.
	 * Nothing is changed by finding them.
	 *
	 * Cached for ten minutes.
	 *
	 * @param int $limit Most candidates to examine.
	 * @return array[] Each: product_id, order_id.
	 */
	public static function find_restocked_after_website_sale( $limit = 200 ) {
		$cached = get_transient( 'tcgiant_restocked_after_sale' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$found = array();

		foreach ( self::website_sales_since_listing( self::legacy_settled_in_stock( $limit ) ) as $product_id => $order_id ) {
			$found[] = array(
				'product_id' => (int) $product_id,
				'order_id'   => (int) $order_id,
			);
		}

		set_transient( 'tcgiant_restocked_after_sale', $found, 10 * MINUTE_IN_SECONDS );

		return $found;
	}

	/**
	 * Whether one product looks restocked by the old settle after a website
	 * sale. For the relist paths, which must not relist such an item.
	 *
	 * @param int $product_id Product.
	 * @return int The website order, or 0.
	 */
	public static function restocked_after_website_sale( $product_id ) {
		$product_id = (int) $product_id;

		if ( ! in_array( $product_id, self::legacy_settled_in_stock( 0, $product_id ), true ) ) {
			return 0;
		}

		$sales = self::website_sales_since_listing( array( $product_id ) );

		return isset( $sales[ $product_id ] ) ? (int) $sales[ $product_id ] : 0;
	}

	/**
	 * Ended, in stock, and settled before 3.27.0.
	 *
	 * @param int $limit      Most to return; 0 for no limit.
	 * @param int $product_id Only this product, or 0 for all.
	 * @return int[]
	 */
	private static function legacy_settled_in_stock( $limit = 0, $product_id = 0 ) {
		global $wpdb;

		$sql = "SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} listing ON listing.post_id = p.ID AND listing.meta_key = '_ebay_listing_status'
			INNER JOIN {$wpdb->postmeta} settled ON settled.post_id = p.ID AND settled.meta_key = '_ebay_stock_settled_at'
			INNER JOIN {$wpdb->postmeta} stock   ON stock.post_id   = p.ID AND stock.meta_key   = '_stock_status'
			LEFT JOIN  {$wpdb->postmeta} sitem   ON sitem.post_id   = p.ID AND sitem.meta_key   = '_ebay_stock_settled_item'
			LEFT JOIN  {$wpdb->postmeta} reviewed ON reviewed.post_id = p.ID AND reviewed.meta_key = '_ebay_restock_reviewed'
			WHERE p.post_type = 'product'
			  AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			  AND listing.meta_value = 'Ended'
			  AND stock.meta_value = 'instock'
			  AND settled.meta_value <> ''
			  -- Settled before 3.27.0: every settle since notes the listing.
			  AND sitem.meta_id IS NULL
			  AND reviewed.meta_id IS NULL";

		if ( $product_id > 0 ) {
			$sql .= $wpdb->prepare( ' AND p.ID = %d', (int) $product_id );
		}

		$sql .= ' ORDER BY p.ID DESC';

		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', (int) $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * For each product, a paid website order for it placed after its listing
	 * started.
	 *
	 * One query over the order items for all of them, read through the tables
	 * WooCommerce keeps whichever order storage is in use; then the orders
	 * themselves, newest first. Refunds are skipped: WooCommerce stores a
	 * refunded line as a line of the refund, under the product, and a refund
	 * is not a sale.
	 *
	 * @param int[] $product_ids Products.
	 * @return array product_id => order_id.
	 */
	private static function website_sales_since_listing( array $product_ids ) {
		global $wpdb;

		$product_ids = array_values( array_filter( array_map( 'intval', $product_ids ) ) );
		if ( empty( $product_ids ) ) {
			return array();
		}

		$in = implode( ',', array_fill( 0, count( $product_ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT CAST( im.meta_value AS UNSIGNED ) AS product_id, oi.order_id
			FROM {$wpdb->prefix}woocommerce_order_items oi
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = oi.order_item_id
			WHERE oi.order_item_type = 'line_item'
			  AND im.meta_key IN ( '_product_id', '_variation_id' )
			  AND im.meta_value IN ( {$in} )
			ORDER BY oi.order_id DESC",
			array_map( 'strval', $product_ids )
		), ARRAY_A );

		$found = array();

		foreach ( (array) $rows as $row ) {
			$product_id = (int) $row['product_id'];

			if ( isset( $found[ $product_id ] ) ) {
				continue;
			}

			// Only a sale the old settle could have written over: one made
			// BEFORE it ran. A website sale since then took the stock down
			// itself, and whatever the product holds now is real.
			$settled_at = (string) get_post_meta( $product_id, '_ebay_stock_settled_at', true );
			$settled_ts = '' !== $settled_at ? (int) get_gmt_from_date( $settled_at, 'U' ) : 0;

			$order = wc_get_order( (int) $row['order_id'] );

			// Real shop orders only - not refunds, subscriptions or anything else.
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
				continue;
			}

			if ( ! in_array( $order->get_status(), array( 'processing', 'completed', 'on-hold' ), true ) ) {
				continue;
			}

			// eBay's own sales are not the question: eBay counted those.
			if ( 'ebay' === $order->get_meta( '_ebay_order_source' ) ) {
				continue;
			}

			$started = strtotime( (string) get_post_meta( $product_id, '_ebay_start_time', true ) );
			$created = $order->get_date_created();
			if ( $started && $created && $created->getTimestamp() < $started ) {
				continue;
			}
			if ( $settled_ts && $created && $created->getTimestamp() > $settled_ts ) {
				continue;
			}

			$total = (float) $order->get_total();
			if ( $total > 0 && (float) $order->get_total_refunded() >= $total ) {
				continue;
			}

			$found[ $product_id ] = (int) $row['order_id'];
		}

		return $found;
	}

	/**
	 * Products whose eBay listing ended with more unsold than WooCommerce holds.
	 *
	 * Recorded by the settle, which no longer applies eBay's higher figure - see
	 * apply_ended_listing_stock(). Usually the difference sold on the website;
	 * listed so a shop that only sells on eBay can take eBay's figure instead.
	 *
	 * @param int $limit Most to return.
	 * @return array[] Each: product_id, ebay_remaining.
	 */
	public static function find_unsold_at_end( $limit = 100 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID AS product_id, CAST( m.meta_value AS SIGNED ) AS ebay_remaining
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ebay_unsold_at_end'
			INNER JOIN {$wpdb->postmeta} listing ON listing.post_id = p.ID AND listing.meta_key = '_ebay_listing_status' AND listing.meta_value = 'Ended'
			WHERE p.post_type = 'product'
			  AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			ORDER BY p.ID DESC
			LIMIT %d",
			max( 1, (int) $limit )
		), ARRAY_A );

		return array_map( function ( $row ) {
			return array(
				'product_id'     => (int) $row['product_id'],
				'ebay_remaining' => (int) $row['ebay_remaining'],
			);
		}, (array) $rows );
	}

	/**
	 * Set a product's stock to 0 at the shop's request.
	 *
	 * Not sent to eBay: the listing has already ended.
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function zero_stock( $product_id ) {
		$product = wc_get_product( (int) $product_id );

		// Only for a listing that has ended. The list it is chosen from is
		// cached, and a product relisted since must not be zeroed here with
		// eBay left selling it.
		if ( ! $product || 'Ended' !== (string) get_post_meta( (int) $product_id, '_ebay_listing_status', true ) ) {
			return false;
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Stock review: WC #%d stock was %s before being set to 0.',
			(int) $product_id,
			$product->managing_stock() ? (string) (int) $product->get_stock_quantity() : $product->get_stock_status()
		) );

		self::begin_ebay_origin();

		try {
			if ( $product->managing_stock() ) {
				wc_update_product_stock( (int) $product_id, 0, 'set' );
			}
			wc_update_product_stock_status( (int) $product_id, 'outofstock' );
		} finally {
			self::end_ebay_origin();
		}

		// Looked at, and sold on the website - which also keeps it off the
		// "sold out on eBay" list, whose trash button is for eBay sales.
		update_post_meta( (int) $product_id, '_ebay_restock_reviewed', 'zeroed' );
		delete_transient( 'tcgiant_restocked_after_sale' );

		return true;
	}

	/**
	 * The shop has looked at a product on the restocked list and its stock is
	 * genuine. It leaves the list, and relisting it is no longer refused.
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function keep_stock( $product_id ) {
		if ( ! wc_get_product( (int) $product_id ) ) {
			return false;
		}

		update_post_meta( (int) $product_id, '_ebay_restock_reviewed', 'kept' );
		delete_transient( 'tcgiant_restocked_after_sale' );

		return true;
	}

	/**
	 * Take eBay's figure for an ended listing, at the shop's request.
	 *
	 * @param int $product_id Product.
	 * @return int|null Stock now held, or null if there was nothing recorded.
	 */
	public static function use_ebay_figure( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		$figure  = get_post_meta( (int) $product_id, '_ebay_unsold_at_end', true );

		if ( ! $product || '' === $figure || 'Ended' !== (string) get_post_meta( (int) $product_id, '_ebay_listing_status', true ) ) {
			return null;
		}

		$figure = max( 0, (int) $figure );

		self::begin_ebay_origin();

		try {
			if ( $product->managing_stock() ) {
				wc_update_product_stock( (int) $product_id, $figure, 'set' );
			}
			wc_update_product_stock_status( (int) $product_id, $figure > 0 ? 'instock' : 'outofstock' );
		} finally {
			self::end_ebay_origin();
		}

		delete_post_meta( (int) $product_id, '_ebay_unsold_at_end' );

		return $figure;
	}

	/**
	 * Products that sold out on eBay and are still in the catalogue.
	 *
	 * The backlog left by removal having lived on one code path. Every one of
	 * these was settled from eBay's own Quantity and QuantitySold and came out
	 * at nothing, which is the sold-out test - so the judgement is already made
	 * and recorded, and nothing here needs asking again.
	 *
	 * Deliberately not acted on automatically. A shop should be shown the list
	 * and asked, not told afterwards that an upgrade trashed some products.
	 *
	 * @param int $limit 0 for all.
	 * @return int[] Product ids.
	 */
	public static function find_sold_out_not_removed( $limit = 0 ) {
		global $wpdb;

		$sql = "SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} listing ON listing.post_id = p.ID AND listing.meta_key = '_ebay_listing_status'
			INNER JOIN {$wpdb->postmeta} settled ON settled.post_id = p.ID AND settled.meta_key = '_ebay_stock_settled_at'
			INNER JOIN {$wpdb->postmeta} stock   ON stock.post_id   = p.ID AND stock.meta_key   = '_stock_status'
			LEFT JOIN  {$wpdb->postmeta} qlisted ON qlisted.post_id = p.ID AND qlisted.meta_key = '_ebay_quantity_listed'
			LEFT JOIN  {$wpdb->postmeta} qsold   ON qsold.post_id   = p.ID AND qsold.meta_key   = '_ebay_quantity_sold'
			LEFT JOIN  {$wpdb->postmeta} zeroed  ON zeroed.post_id  = p.ID AND zeroed.meta_key  = '_ebay_restock_reviewed' AND zeroed.meta_value = 'zeroed'
			LEFT JOIN  {$wpdb->postmeta} sitem   ON sitem.post_id   = p.ID AND sitem.meta_key   = '_ebay_stock_settled_item'
			WHERE p.post_type = 'product'
			  AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			  AND listing.meta_value = 'Ended'
			  AND stock.meta_value = 'outofstock'
			  AND settled.meta_value <> ''
			  -- Out of stock because eBay sold it, not because the website did.
			  -- Settled before 3.27.0 there are no figures, and under the old rule
			  -- out of stock after a settle could only mean an eBay sell-out.
			  AND ( ( qlisted.meta_value IS NULL AND sitem.meta_id IS NULL )
			        OR CAST( qsold.meta_value AS SIGNED ) >= CAST( qlisted.meta_value AS SIGNED ) )
			  -- Set to 0 from Stock Review as a website sale: not eBay's to remove.
			  AND zeroed.meta_id IS NULL
			ORDER BY p.ID DESC";

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql . ' LIMIT %d', (int) $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * How many are in the state above.
	 *
	 * @return int
	 */
	public static function count_sold_out_not_removed() {
		return count( self::find_sold_out_not_removed() );
	}

	/**
	 * Products whose eBay listing has ended but which still show as in stock.
	 *
	 * This is the shape every stock fault this plugin has had leaves behind: the
	 * listing is over, so nothing will import it again, yet WooCommerce is still
	 * offering the item. Sound stores return nothing here.
	 *
	 * Deliberately a database question and not an eBay one, so it is cheap
	 * enough to ask on an admin page load and needs no API budget.
	 *
	 * @param int $limit Maximum rows, or 0 for all.
	 * @return int[] Product and variation IDs, newest first.
	 */
	public static function find_unsettled_ended_products( $limit = 0 ) {
		global $wpdb;

		$sql = "SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} listing ON listing.post_id = p.ID AND listing.meta_key = '_ebay_listing_status'
			INNER JOIN {$wpdb->postmeta} stock   ON stock.post_id   = p.ID AND stock.meta_key   = '_stock_status'
			LEFT JOIN  {$wpdb->postmeta} settled ON settled.post_id = p.ID AND settled.meta_key = '_ebay_stock_settled_at'
			WHERE p.post_type IN ( 'product', 'product_variation' )
			  AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			  AND listing.meta_value = 'Ended'
			  AND stock.meta_value = 'instock'
			  AND settled.post_id IS NULL
			ORDER BY p.ID DESC";

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql . ' LIMIT %d', (int) $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * How many products are in the state above.
	 *
	 * @return int
	 */
	public static function count_unsettled_ended_products() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} listing ON listing.post_id = p.ID AND listing.meta_key = '_ebay_listing_status'
			 INNER JOIN {$wpdb->postmeta} stock   ON stock.post_id   = p.ID AND stock.meta_key   = '_stock_status'
			 LEFT JOIN  {$wpdb->postmeta} settled ON settled.post_id = p.ID AND settled.meta_key = '_ebay_stock_settled_at'
			 WHERE p.post_type IN ( 'product', 'product_variation' )
			   AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			   AND listing.meta_value = 'Ended'
			   AND stock.meta_value = 'instock'
			   AND settled.post_id IS NULL"
		);
	}

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

		// The pass above can only see listings that are still running, which is
		// precisely where stock faults do not show up: a listing that has ended
		// is never fetched again, so its stock could sit wrong indefinitely with
		// nothing to notice. Ask the database directly.
		$unsettled = self::count_unsettled_ended_products();

		if ( $unsettled > 0 ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Reconciliation: %d product(s) whose eBay listing has ended are still showing stock. Review them under TCGiant Sync, Stock Review.',
				$unsettled
			) );
		}
	}
}
