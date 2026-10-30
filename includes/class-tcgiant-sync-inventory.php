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
	const SETTLE_KEYS = array( '_ebay_stock_settled_at', '_ebay_stock_settled_item', '_ebay_unsold_at_end', '_ebay_quantity_listed', '_ebay_quantity_sold', '_ebay_restock_reviewed', '_ebay_variations_settled_item', '_ebay_unmatched_variations', '_ebay_end_reason', '_ebay_end_reason_item' );

	/**
	 * The WooCommerce -> eBay stock push (3.28.0).
	 *
	 * A hook of its own: Action Scheduler hands a callback its arguments in
	 * order, and the old hook's first argument is a SKU - which can be a
	 * number, the item number. Pushes queued before the upgrade still run, on
	 * tcgiant_sync_update_ebay_stock (process_ebay_stock_update()).
	 *
	 * A group of its own too, which neither starting a sync nor Emergency Stop
	 * clears: a website sale's push must reach eBay whatever the sync does.
	 */
	const PUSH_HOOK  = 'tcgiant_sync_push_stock';
	const PUSH_GROUP = 'tcgiant_sync_stock';

	/**
	 * How long to wait before each try again of a push eBay did not take: 5
	 * and 15 minutes, 1, 3 and 6 hours - about 10 hours in all. Then it is
	 * logged as an error and listed (PUSH_FAILURES_OPTION).
	 */
	const PUSH_RETRY_DELAYS = array( 300, 900, 3600, 10800, 21600 );

	/**
	 * Pushes eBay still refused after every try, newest last, at most
	 * PUSH_FAILURES_KEEP of them. Not autoloaded.
	 */
	const PUSH_FAILURES_OPTION = 'tcgiant_sync_push_failures';
	const PUSH_FAILURES_KEEP   = 200;

	/**
	 * A product's stock changes still on their way to eBay: token => the time
	 * until which it counts - or, for a push queued since 3.28.0's fix round
	 * 8, array( until, queued_at ), queued_at the time it was queued. While
	 * one counts, an import keeps WooCommerce's figure when it is the lower -
	 * see TCGiant_Sync_Mapper::push_pending(), which reads the same key.
	 */
	const PUSH_PENDING_KEY = '_ebay_push_pending';

	/**
	 * How long a push still counts as on its way after it is done, for an
	 * import whose eBay figures were read before the push landed.
	 */
	const PUSH_PENDING_TAIL = 900;

	/**
	 * How long a queued push counts at most, for one whose action is lost:
	 * two days (mark_push_pending()). An entry with no queued_at, written
	 * before fix round 8, was queued this long before its until.
	 */
	const PUSH_PENDING_FOR = 172800;

	/**
	 * The pending mark of a sale whose push never reached eBay - see
	 * push_done(). Kept until a later push or a settle of the listing lands.
	 */
	const UNSENT_TOKEN = 'unsent';

	/**
	 * How many units the sales behind UNSENT_TOKEN took off that eBay never
	 * heard of, by push token. While only that mark holds, an import takes
	 * eBay's figure less their sum, if that is lower -
	 * TCGiant_Sync_Mapper::sale_hold(), which reads it by name.
	 */
	const UNSENT_QTY_KEY = '_ebay_unsent_qty';

	/**
	 * eBay's QuantitySold that the product's stock has taken off, for one
	 * listing (and variation SKU): array( item, sku, sold ). See sold_seen().
	 * The import writes it too, by name (TCGiant_Sync_Mapper).
	 */
	const SOLD_SEEN_KEY = '_ebay_sold_seen';

	/**
	 * Seconds by which a record's stamp must be older than an eBay order's
	 * creationDate before order sync takes the sale off itself
	 * (take_order_sale()). The stamp is the Trading API's clock, the order
	 * the Fulfillment API's: a read that already counts a sale may be stamped
	 * a little before the order's creationDate. Inside the margin the sale is
	 * left to its check, which can only be later, never twice (3.28.0 release
	 * fix round 3).
	 */
	const ORDER_CLOCK_MARGIN = 300;

	/**
	 * WooCommerce's actions round a stock write that a record of eBay's sales
	 * is written from (with_record()), in the order it fires them:
	 * woocommerce_updated_product_stock from its data store right after the
	 * stock SQL (WooCommerce 3.6 and later), then, after its save of the
	 * product, woocommerce_product_set_stock or woocommerce_variation_set_stock
	 * (3.28.0 release fix round 4).
	 */
	const RECORD_HOOKS = array( 'woocommerce_updated_product_stock', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' );

	/**
	 * The listing the product's _ebay_listing_type was read from eBay for.
	 * Without it the type may be a default some older code wrote.
	 */
	const TYPE_ITEM_KEY = '_ebay_listing_type_item';

	/**
	 * The name a stock push is watched under (TCGiant_Sync_Runtime), and its
	 * crash record's.
	 */
	const PUSH_JOB = 'Stock push';

	/**
	 * The pending token of a website sale's push starts with this. While one
	 * counts, an import leaves the product's stock and its record of eBay's
	 * sales alone - see TCGiant_Sync_Mapper::sale_hold().
	 */
	const SALE_TOKEN_PREFIX = 'sale-';

	/**
	 * The tries of a product's pushes under way: run => started. Each has a
	 * safety try queued (see arm_safety()) for a request the host ends with
	 * no PHP error to report it.
	 */
	const PUSH_RUNNING_KEY = '_ebay_push_running';

	/**
	 * How long a try may run.
	 */
	const PUSH_RUN_LIMIT = 900;

	/**
	 * When a try still marked as running is taken for one the host ended:
	 * a minute past its limit, for every try alike (fix round 3). Its safety
	 * try is not queued for any earlier.
	 */
	const PUSH_ENDED_AFTER = self::PUSH_RUN_LIMIT + 60;

	/**
	 * Whether the shop has an auction: 'yes' or 'no', for a day. See
	 * shop_may_have_auctions().
	 */
	const AUCTIONS_TRANSIENT = 'tcgiant_shop_has_auctions';

	/**
	 * What the 3.28.0 upgrade found and did about "Send website stock changes
	 * to eBay?" - see maybe_upgrade_push_switch(): since, legacy (what
	 * "Reduce WooCommerce stock on eBay sale?" held: '0', '1' or 'absent'),
	 * flipped (whether it turned sending on) and notice (the dashboard
	 * notice: pending, dismissed or none); and held ('wplister') where it left
	 * sending off because WP-Lister for eBay was active. Autoloaded, as it is
	 * read on every request.
	 */
	const PUSH_SWITCH_OPTION = 'tcgiant_sync_push_switch';

	/**
	 * Whether a shop whose "Reduce WooCommerce stock on eBay sale?" was saved
	 * as No has its stock pushes on in 3.28.0. Until then that setting turned
	 * them off as well, though it says nothing of the kind, and No is its
	 * default. True: the owner's decision (2026-10-12), with the reworked push
	 * in the same release. False only if that rework is taken out: those
	 * shops then keep pushes off, and the notice asks them to turn them on.
	 */
	const FLIP_LEGACY_ZERO = true;

	/**
	 * Set for a day once the log has said that stock changes are not being
	 * sent to eBay because the shop switched sending off.
	 */
	const PUSH_OFF_NOTED = 'tcgiant_push_off_noted';

	/**
	 * When the shop's eBay tokens were last taken away - Disconnect, or any
	 * save of the settings without them (connection_saved()). Not a key of
	 * tcgiant_sync_ebay_settings, so no form save can touch it. Saving tokens
	 * deletes it.
	 */
	const DISCONNECTED_OPTION = 'tcgiant_sync_disconnected_at';

	/**
	 * How long after a disconnect stock changes are still queued, and a push
	 * that finds no token is still tried again: item 14's tries (about 10
	 * hours) and an hour more. A shop that disconnects and connects again
	 * within it still gets its website sales to eBay - the tries carry them
	 * past the reconnect, as before 3.28.0. One away longer, or never
	 * connected, has nothing to send them with.
	 */
	const PUSH_RECONNECT_WINDOW = self::PUSH_RETRY_DELAYS[0] + self::PUSH_RETRY_DELAYS[1] + self::PUSH_RETRY_DELAYS[2] + self::PUSH_RETRY_DELAYS[3] + self::PUSH_RETRY_DELAYS[4] + 3600;

	/**
	 * Products whose stock WooCommerce is about to reduce for a website order.
	 *
	 * The order's own push sends that sale. The stock listeners leave these
	 * alone, or block checkout, payment webhooks and status changes in the
	 * admin - none of which fire the classic checkout's hook - queued a
	 * second push for the same sale, and eBay was told about it twice.
	 *
	 * @var array<int,bool>
	 */
	private static $order_reduction_products = array();

	/**
	 * What the import records about the auction a product follows: its
	 * opening bid, the bid it has reached, how many bids, and Buy It Now. They
	 * describe one listing, and go when the product moves on to another.
	 */
	const AUCTION_FIGURE_KEYS = array( '_ebay_start_price', '_ebay_current_price', '_ebay_bid_count', '_ebay_buy_it_now_price' );

	/**
	 * The eBay auction the shop listed from WooCommerce - pushed, or relisted
	 * by the plugin or on eBay from one that was. Compared with the listing the
	 * product follows: an auction started on eBay, or moved on to from a
	 * listing the shop had pushed at a fixed price, is eBay's, not the shop's.
	 */
	const AUCTION_LISTED_KEY = '_ebay_auction_listed_item';

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
	 * The try under way in this request, product and run, whose running
	 * mark beat() refreshes before each eBay call (fix round 4).
	 *
	 * @var array|null
	 */
	private static $beat_run = null;

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

		// Last of all, so it sees the final answer: the products a website
		// order is about to take stock from, which the order's push sends.
		add_filter( 'woocommerce_can_reduce_order_stock', array( $this, 'note_order_reduction' ), PHP_INT_MAX, 2 );

		// An order's stock put back - cancelled, refunded, back to pending -
		// takes its lines' push marks with it, so paid again it is sent again.
		add_action( 'woocommerce_restore_order_stock', array( $this, 'forget_order_pushes' ) );

		// Nor when an imported eBay order is saved from its edit screen. There
		// WooCommerce takes off each line's quantity less what it recorded
		// taking off before - and since the line above stops it taking any,
		// it had recorded none. Adding tracking and marking the order
		// Completed took the sale off a second time: a sold coin at -1.
		add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', array( $this, 'keep_ebay_order_lines' ), 10, 2 );

		// Real-time stock sync: manual stock edits, CSV imports, REST API updates.
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_product_stock_change' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_variation_stock_change' ) );

		// Background task for eBay update: one product, by its own listing.
		add_action( self::PUSH_HOOK, array( $this, 'process_stock_push' ), 10, 1 );

		// Pushes queued before 3.28.0, by SKU and quantity.
		add_action( 'tcgiant_sync_update_ebay_stock', array( $this, 'process_ebay_stock_update' ), 10, 2 );

		// Weekly inventory reconciliation: the weekly start, the follow-up
		// that carries a pass on a slice at a time, and WP-Cron's check on a
		// pass that has gone quiet (3.28.0).
		add_action( 'tcgiant_sync_reconcile_inventory', array( $this, 'reconcile_inventory' ) );
		add_action( self::RECONCILE_HOOK, array( $this, 'continue_reconciliation' ), 10, 1 );
		add_action( self::RECONCILE_STALL_HOOK, array( $this, 'reconcile_stall_check' ) );
		add_action( 'init', array( $this, 'schedule_reconciliation' ) );

		// Once, at the first request of any kind after the update to 3.28.0 -
		// a shopper's, cron's, the admin's, WP-CLI's: what it does about
		// "Send website stock changes to eBay?". One autoloaded read after.
		add_action( 'init', array( __CLASS__, 'maybe_upgrade_push_switch' ), 1 );

		// "Import eBay auctions" changed: the day's answer to whether the shop
		// has an auction (shop_may_have_auctions()) is asked again.
		add_action( 'update_option_tcgiant_sync_ebay_settings', array( __CLASS__, 'auctions_setting_saved' ), 10, 2 );

		// The tokens taken away, or saved again: when, for the queues
		// (DISCONNECTED_OPTION).
		add_action( 'update_option_tcgiant_sync_ebay_settings', array( __CLASS__, 'connection_saved' ), 10, 2 );

		// A product on a running eBay auction cannot be bought here: a website
		// sale took it at whatever the bidding had reached, and then either
		// sold it twice or ended the auction and cancelled every bid. The
		// product shows the auction instead, with a link to it on eBay.
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'filter_price_html' ), 20, 2 );
		add_action( 'woocommerce_simple_add_to_cart', array( __CLASS__, 'render_auction_notice' ), 31 );
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
		// And each write here saves the product. Another plugin's work on a
		// save that saves it again, round and round, is stopped after a few
		// rounds rather than taking the request down - see guard_loops(). For
		// every caller: the delta, the per-sale stock check, the ended-listing
		// check and the Stock Review buttons.
		TCGiant_Sync_Runtime::guard_loops();

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
			TCGiant_Sync_Runtime::unguard_loops();
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
		$settled_item = (string) get_post_meta( $product_id, '_ebay_item_id', true );
		update_post_meta( $product_id, '_ebay_stock_settled_item', $settled_item );

		// Why it ended, when eBay's figures say everything sold: for the
		// Listings screen's Sold view. A reason already recorded for this
		// listing - the shop ended it, or the plugin did at 0 - stands.
		if ( (int) $listed >= 1 && (int) $sold >= (int) $listed ) {
			self::note_end_reason( $product_id, $settled_item, self::END_SOLD_OUT, false );
		}

		return $held;
	}

	/**
	 * Why a listing ended, as _ebay_end_reason records it, for the listing
	 * _ebay_end_reason_item names. Spent with the rest of a settle when a new
	 * listing starts (SETTLE_KEYS).
	 *
	 * - END_STOCK_ZERO: the plugin ended it because WooCommerce's stock
	 *   reached 0 (3.28.0).
	 * - END_BY_SHOP: the shop ended it from WordPress - End Listing on the
	 *   product, or End Listing on the Listings screen - and eBay took it.
	 * - END_SOLD_OUT: eBay's final figures say every unit sold.
	 */
	const END_STOCK_ZERO = 'stock_zero';
	const END_BY_SHOP    = 'by_shop';
	const END_SOLD_OUT   = 'sold_out';

	/**
	 * Record why a listing ended.
	 *
	 * @param int    $product_id Product holding the link.
	 * @param string $item_id    The listing.
	 * @param string $reason     One of the END_ constants.
	 * @param bool   $replace    Whether a reason already recorded for this
	 *                           listing gives way.
	 * @return void
	 */
	private static function note_end_reason( $product_id, $item_id, $reason, $replace ) {
		$product_id = (int) $product_id;
		$item_id    = (string) $item_id;

		if ( '' === $item_id ) {
			return;
		}

		if ( ! $replace
			&& '' !== (string) get_post_meta( $product_id, '_ebay_end_reason', true )
			&& $item_id === (string) get_post_meta( $product_id, '_ebay_end_reason_item', true ) ) {
			return;
		}

		update_post_meta( $product_id, '_ebay_end_reason', (string) $reason );
		update_post_meta( $product_id, '_ebay_end_reason_item', $item_id );
	}

	/**
	 * The shop ended a listing from WordPress, and eBay took it.
	 *
	 * Recorded as Ended, when (eBay's EndTime from the answer, or now), and
	 * why. Nothing is settled: the listing's figures come from eBay when the
	 * sync next reads it.
	 *
	 * @param int    $product_id Product holding the link.
	 * @param string $item_id    The listing.
	 * @param mixed  $response   EndItem's answer.
	 * @return void
	 */
	public static function record_shop_end( $product_id, $item_id, $response = null ) {
		$product_id = (int) $product_id;
		$end        = is_array( $response ) ? TCGiant_Sync_API::scalar_value( $response['EndTime'] ?? '' ) : '';

		update_post_meta( $product_id, '_ebay_listing_status', 'Ended' );
		update_post_meta( $product_id, '_ebay_end_time', '' !== $end ? $end : gmdate( 'Y-m-d\TH:i:s.000\Z' ) );
		self::note_end_reason( $product_id, (string) $item_id, self::END_BY_SHOP, true );
	}

	/**
	 * What the Listings screen says about how a listing ended, for its Sold
	 * and Ended views: sold on eBay, out of stock on the website, ended by
	 * the shop, and how many of a listing of several sold.
	 *
	 * Only from what was recorded about this listing - the reason
	 * (_ebay_end_reason_item) and eBay's final figures
	 * (_ebay_stock_settled_item) must name it - so nothing a product's
	 * earlier listing left is said about its new one. eBay is not asked.
	 *
	 * "Out of stock on your website" is the plugin ending the listing at 0,
	 * or eBay selling fewer than were listed while WooCommerce has none: the
	 * stock went somewhere other than eBay - a website sale, usually, or a
	 * hand edit to 0, which reads the same from here.
	 *
	 * @param int    $product_id The product.
	 * @param string $item_id    The listing the row is for.
	 * @param string $view       Its view: Active, Ended or Sold.
	 * @return string Plain text, '' when nothing is known or the listing is live.
	 */
	public static function end_note( $product_id, $item_id, $view ) {
		$product_id = (int) $product_id;
		$item_id    = (string) $item_id;

		if ( 'Active' === $view || '' === $item_id ) {
			return '';
		}

		$reason = $item_id === (string) get_post_meta( $product_id, '_ebay_end_reason_item', true )
			? (string) get_post_meta( $product_id, '_ebay_end_reason', true )
			: '';

		$listed = null;
		$sold   = null;
		if ( $item_id === (string) get_post_meta( $product_id, '_ebay_stock_settled_item', true ) ) {
			$l = get_post_meta( $product_id, '_ebay_quantity_listed', true );
			$s = get_post_meta( $product_id, '_ebay_quantity_sold', true );
			if ( is_numeric( $l ) && is_numeric( $s ) ) {
				$listed = max( 0, (int) $l );
				$sold   = max( 0, (int) $s );
			}
		}

		$sold_out = self::END_SOLD_OUT === $reason || ( null !== $listed && $listed >= 1 && $sold >= $listed );
		$several  = null !== $listed && $listed > 1;

		// Every unit sold on eBay: nothing more to say.
		if ( $sold_out && self::END_BY_SHOP !== $reason ) {
			/* translators: %d: units the listing held, every one of them sold */
			return $several ? sprintf( __( 'Sold on eBay: all %d', 'tcgiant-sync' ), $listed ) : __( 'Sold on eBay', 'tcgiant-sync' );
		}

		if ( self::END_BY_SHOP === $reason ) {
			$note = __( 'Ended by you', 'tcgiant-sync' );
		} elseif ( 'Sold' === $view && ( self::END_STOCK_ZERO === $reason || null !== $listed ) ) {
			// None left here, and eBay did not sell them all.
			$note = __( 'Out of stock on your website', 'tcgiant-sync' );
		} elseif ( self::END_STOCK_ZERO === $reason ) {
			// Ended at 0, and restocked since.
			$note = __( 'Ended when your stock reached 0', 'tcgiant-sync' );
		} elseif ( null !== $listed && 0 === $sold ) {
			return __( 'Ended unsold', 'tcgiant-sync' );
		} else {
			$note = '';
		}

		// And how many of a listing of several sold on eBay, when any did.
		/* translators: 1: units sold on eBay, 2: units the listing held */
		$of = $several && $sold > 0 ? sprintf( __( '%1$d of %2$d sold on eBay', 'tcgiant-sync' ), min( $sold, $listed ), $listed ) : '';

		if ( '' === $note ) {
			return $of;
		}

		return '' !== $of ? $note . ' - ' . $of : $note;
	}

	/**
	 * Lower a product's stock to what eBay says is left on a live listing.
	 *
	 * Never raises it: eBay's figure is lower when it sold something WooCommerce
	 * has not heard of, and WooCommerce's is lower when the website did. Either
	 * way the lower one is true.
	 *
	 * $record, when given, writes the record of eBay's sales that this
	 * lowering takes in, given the stock now: once, only if the stock did
	 * come down, and from WooCommerce's own actions round the write - the
	 * first right after its stock SQL, before its save of the product runs
	 * other plugins' hooks (arm_record()). A listener that throws, or a
	 * request that dies, between the stock and its record no longer leaves a
	 * sale taken off that the next read takes off again (3.28.0 release fix
	 * round 4).
	 *
	 * @param int           $product_id Product.
	 * @param int           $available  eBay's listed minus sold.
	 * @param callable|null $record     Writes the record, given the stock now.
	 * @return int|null Stock now held, or null when the product does not track
	 *                  a quantity or the write did not take.
	 */
	public static function lower_stock_to( $product_id, $available, $record = null ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product || ! $product->managing_stock() ) {
			return null;
		}

		$current   = $product->get_stock_quantity();
		$available = max( 0, (int) $available );

		if ( null !== $current && $available >= (int) $current ) {
			return (int) $current;
		}

		$armed = self::arm_record( $product, $current, $record );

		self::begin_ebay_origin();
		TCGiant_Sync_Runtime::guard_loops();

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
			TCGiant_Sync_Runtime::unguard_loops();
			self::end_ebay_origin();
			self::disarm_record( $armed );
		}

		return $available;
	}

	/**
	 * Get ready to write a record of eBay's sales from a stock write about to
	 * be made (lower_stock_to(), take_order_sale(), and the import's save of
	 * a product, TCGiant_Sync_Mapper): a listener at PHP_INT_MIN on each of
	 * RECORD_HOOKS, so the record is written before anyone else's listener
	 * runs, and - from woocommerce_updated_product_stock, right after
	 * WooCommerce's stock SQL - before its save of the product runs other
	 * plugins' save hooks. It acts for this product's stock only (the
	 * product, or the product holding its stock), writes the record once,
	 * and only when the stock did come down - or, given $holds, when it
	 * holds the figure the import writes; and it is inert once
	 * disarm_record() has run, even where it could not be taken off the hook.
	 * One mechanism for every "write the stock, then the record" pair
	 * (3.28.0 release fix rounds 3, 4 and 5).
	 *
	 * @param WC_Product    $product The product being written.
	 * @param int|null      $before  The stock it holds before the write.
	 * @param callable|null $record  Writes the record, given the stock now.
	 * @param callable|null $holds   Given the stock now, whether the record is
	 *                               to be written; null for "came down".
	 * @return object|null What disarm_record() takes; null with no record.
	 */
	public static function arm_record( $product, $before, $record, $holds = null ) {
		if ( ! is_callable( $record ) || ! is_object( $product ) || ( null === $before && ! is_callable( $holds ) ) ) {
			return null;
		}

		$id     = (int) $product->get_id();
		$holder = method_exists( $product, 'get_stock_managed_by_id' ) ? (int) $product->get_stock_managed_by_id() : $id;
		$armed          = new stdClass();
		$armed->ids     = array_values( array_unique( array( $id, $holder ) ) );
		$armed->holder  = $holder;
		$armed->before  = null === $before ? null : (int) $before;
		$armed->holds   = is_callable( $holds ) ? $holds : null;
		$armed->record  = $record;
		$armed->live    = true;
		$armed->written = false;

		$armed->listener = function ( $changed ) use ( $armed ) {
			// woocommerce_updated_product_stock passes the product's id, the
			// stock actions the product.
			$changed_id = is_object( $changed ) && method_exists( $changed, 'get_id' ) ? (int) $changed->get_id() : ( is_numeric( $changed ) ? (int) $changed : 0 );
			if ( $armed->live && in_array( $changed_id, $armed->ids, true ) ) {
				self::write_record( $armed );
			}
		};

		if ( function_exists( 'add_action' ) ) {
			foreach ( self::RECORD_HOOKS as $hook ) {
				add_action( $hook, $armed->listener, PHP_INT_MIN );
			}
		}

		return $armed;
	}

	/**
	 * The stock write is over, thrown past or not: the listeners off, and the
	 * record written if the stock came down and no action wrote it - a
	 * WooCommerce that never fired them.
	 *
	 * @param object|null $armed From arm_record().
	 * @return bool Whether the record was written.
	 */
	public static function disarm_record( $armed ) {
		if ( ! is_object( $armed ) ) {
			return false;
		}

		$armed->live = false;
		if ( function_exists( 'remove_action' ) ) {
			foreach ( self::RECORD_HOOKS as $hook ) {
				remove_action( $hook, $armed->listener, PHP_INT_MIN );
			}
		}
		self::write_record( $armed );

		return (bool) $armed->written;
	}

	/**
	 * Write the record once, if the stock came down (or holds the import's
	 * figure).
	 *
	 * The stock is read from the holder's _stock meta, not the product
	 * object: with WooCommerce's product instance caching on - for shops
	 * newly installed on WooCommerce 11.0 and later - wc_get_product() still
	 * gives the copy from before the write at PHP_INT_MIN on
	 * woocommerce_updated_product_stock, because WooCommerce drops it at
	 * priority 10 on that same action. Its data store has just deleted the
	 * holder's post meta from the cache, so the meta is the stock now
	 * (3.28.0 release fix round 5).
	 *
	 * @param object $armed From arm_record().
	 * @return void
	 */
	private static function write_record( $armed ) {
		if ( $armed->written ) {
			return;
		}

		$after = (int) get_post_meta( $armed->holder, '_stock', true );
		if ( null !== $armed->holds ? ! call_user_func( $armed->holds, $after ) : ( null === $armed->before || $after >= $armed->before ) ) {
			return;
		}

		$armed->written = true;
		call_user_func( $armed->record, $after );
	}

	/**
	 * Lower each variation of a variable product to what eBay says that
	 * variation has left. Never raises one.
	 *
	 * A variation listing's figures are per variation; the item's own are the
	 * whole listing's, which only the parent can take. Settling the parent
	 * alone left a variation eBay had sold out in stock here, and the website
	 * sold it again. Variations are paired by TCGiant_Sync_Variations::match().
	 * One eBay has that this product has not is left alone, and said once.
	 *
	 * @param int    $parent_id     Variable product linked to the listing.
	 * @param array  $item          eBay's Item, with its Variations.
	 * @param string $source        For the log.
	 * @param bool   $before_relist Called by the check before a relist or
	 *                              push: a lowering then undoes what the
	 *                              shop may have restocked, and is a warning.
	 * @param bool   $sale_hold     The sale check of a live listing: a variation
	 *                              keeping its own stock, with a website sale's
	 *                              push on its way and no record of eBay's
	 *                              sales, is left to that push, which reads
	 *                              eBay itself (3.28.0 release fix round 1).
	 * @return array|null matched, lowered, unmatched and failed; null when
	 *                    there is nothing to settle from - not a variable
	 *                    product, or eBay sent no variations.
	 */
	public static function settle_variations( $parent_id, array $item, $source, $before_relist = false, $sale_hold = false ) {
		$parent_id = (int) $parent_id;
		$parent    = wc_get_product( $parent_id );

		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return null;
		}

		$ebay = TCGiant_Sync_Variations::from_item( $item );
		if ( ! $ebay ) {
			return null;
		}

		$match   = TCGiant_Sync_Variations::match( $parent, $ebay );
		$item_id = TCGiant_Sync_Variations::scalar( $item['ItemID'] ?? '' );
		$lowered = array();
		$failed  = array();

		foreach ( $match['pairs'] as $i => $ids ) {
			$available = $ebay[ $i ]['available'];
			if ( null === $available ) {
				continue;
			}

			foreach ( $ids as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}

				// Stock kept on the parent, shared by its variations: one
				// variation's eBay figure says nothing about the others'. The
				// parent is brought to the listing's total instead.
				if ( self::stock_held_by_parent( $variation ) ) {
					continue;
				}

				// No quantity kept: only selling out can be said, and it is.
				if ( ! $variation->managing_stock() ) {
					if ( 0 === (int) $available && $variation->is_in_stock() ) {
						self::begin_ebay_origin();
						try {
							wc_update_product_stock_status( (int) $variation_id, 'outofstock' );
						} finally {
							self::end_ebay_origin();
						}
						$lowered[] = sprintf( '#%d out of stock', $variation_id );
					}
					continue;
				}

				// The sale check's sale hold (sale_hold_of(), as the import's).
				// Lower here by a website sale whose push is on its way, with no
				// record to say which of eBay's sales it has: that push sorts the
				// two out. Only a sale that never got there (its unsent mark):
				// eBay's figure less its units, as the import takes it (3.28.0
				// release fix rounds 1 and 2).
				$hold = $sale_hold ? self::sale_hold_of( $variation_id ) : null;
				if ( 'sale' === $hold && null === self::sold_seen( $variation_id, $item_id, $ebay[ $i ]['sku'] ) ) {
					continue;
				}
				$units = is_int( $hold ) ? max( 0, $hold ) : 0;

				$before = $variation->get_stock_quantity();
				// What this takes off is eBay's sales reaching the stock: the
				// stock push must not take them off again (sold_seen()).
				// Written with the stock (lower_stock_to()), and once:
				// absorbing is relative.
				$done   = (object) array( 'absorbed' => false );
				$sku_i  = (string) $ebay[ $i ]['sku'];
				$sold_i = (int) $ebay[ $i ]['sold'];
				$absorb = function ( $now ) use ( $done, $variation_id, $item_id, $sku_i, $sold_i, $before, $available ) {
					$done->absorbed = true;
					self::absorb_sold( $variation_id, $item_id, $sku_i, $sold_i, (int) $before - (int) $now, $available );
				};
				$now    = self::lower_stock_to( $variation_id, max( 0, (int) $available - $units ), null === $before ? null : $absorb );

				// Nothing to lower: the record moves on all the same.
				if ( null !== $now && null !== $before && ! $done->absorbed ) {
					$absorb( $now );
				}

				// eBay still above WooCommerce with a sale that never got
				// there: the push that tells it, as the import queues (fix
				// round 4), whether or not this lowered anything (release fix
				// round 3).
				if ( $units > 0 && null !== $now && (int) $available > $now ) {
					self::queue_after_ebay_sale( $variation_id );
				}

				if ( null === $now ) {
					// The write did not take: not counted as settled.
					$failed[] = sprintf( '#%d', $variation_id );
				} elseif ( null !== $before && $now < (int) $before ) {
					$lowered[] = sprintf( '#%d %d to %d', $variation_id, (int) $before, $now );
				}
			}
		}

		// Stock kept on the parent and shared by some variations: eBay's sales
		// of each of those come off the shared stock (settle_pool()). Without
		// a record, not while a website sale is on its way: the shared figure
		// is then lower for a reason eBay has not heard of yet, and the sale's
		// push sorts the two out.
		$pool = self::settle_pool( $parent_id, $ebay, $item_id, self::sale_pending( $parent_id ) ? 'unrecorded' : 'none' );
		if ( null !== $pool ) {
			if ( null === $pool['now'] ) {
				$failed[] = sprintf( '#%d (the shared stock)', $parent_id );
			} elseif ( $pool['now'] < $pool['was'] ) {
				$lowered[] = sprintf( '#%d (the shared stock) %d to %d', $parent_id, $pool['was'], $pool['now'] );
			}
		}

		// eBay's variations this product has none of. Said when the list
		// changes, not on every pass.
		$unmatched = array();
		foreach ( $match['unmatched'] as $i ) {
			if ( '' !== $ebay[ $i ]['sku'] ) {
				$unmatched[] = $ebay[ $i ]['sku'];
				continue;
			}
			$pairs = array();
			foreach ( $ebay[ $i ]['specifics'] as $name => $value ) {
				$pairs[] = $name . '=' . $value;
			}
			$unmatched[] = implode( ', ', $pairs );
		}
		sort( $unmatched );

		$was = get_post_meta( $parent_id, '_ebay_unmatched_variations', true );
		if ( $unmatched !== ( is_array( $was ) ? $was : array() ) ) {
			if ( $unmatched ) {
				update_post_meta( $parent_id, '_ebay_unmatched_variations', $unmatched );
				TCGiant_Sync_Logger::warning( sprintf(
					'%s: eBay item %s has variations with no matching variation on WC #%d (%s). Their stock was not checked.',
					$source,
					$item_id,
					$parent_id,
					implode( '; ', $unmatched )
				) );
			} else {
				delete_post_meta( $parent_id, '_ebay_unmatched_variations' );
			}
		}

		if ( $lowered ) {
			if ( $before_relist ) {
				// The variations are what a relist sends and the website sells:
				// a restock lost here is lost on both, so the shop is told.
				TCGiant_Sync_Logger::warning( sprintf(
					'%s: eBay item %s - variations of WC #%d lowered to eBay\'s figures before listing it again: %s. eBay sold them on that listing and the sync had not brought it in yet. If you restocked one since, set that variation\'s stock again and push the product to eBay.',
					$source,
					$item_id,
					$parent_id,
					implode( ', ', $lowered )
				) );
			} else {
				TCGiant_Sync_Logger::log( sprintf(
					'%s: eBay item %s - variations of WC #%d lowered to eBay\'s figures: %s.',
					$source,
					$item_id,
					$parent_id,
					implode( ', ', $lowered )
				) );
			}

			// The parent's price range and stock status follow its variations;
			// not a change to send anywhere.
			if ( class_exists( 'WC_Product_Variable' ) ) {
				self::begin_ebay_origin();
				try {
					WC_Product_Variable::sync( $parent_id );
				} finally {
					self::end_ebay_origin();
				}
			}
		}

		// Only a live listing's sale check comes round again by itself. An
		// ended listing is not settled again until it is relisted or pushed,
		// and neither goes ahead while a variation still shows what eBay sold.
		$status = TCGiant_Sync_Variations::scalar( $item['SellingStatus']['ListingStatus'] ?? '' );
		$ended  = '' !== $status && 'Active' !== $status;

		if ( $failed ) {
			TCGiant_Sync_Logger::warning( sprintf(
				$ended
					? '%s: eBay item %s has ended, but the stock of variation(s) %s of WC #%d could not be set to eBay\'s figures - something else on this site may be holding it - so they may still be for sale here. Set their stock by hand; the listing is not relisted or pushed until it can be set.'
					: '%s: eBay item %s - the stock of variation(s) %s of WC #%d could not be set to eBay\'s figures; they are tried again at the next stock check.',
				$source,
				$item_id,
				implode( ', ', $failed ),
				$parent_id
			) );
		}

		if ( $ended && '' !== $item_id && ! $failed ) {
			update_post_meta( $parent_id, '_ebay_variations_settled_item', $item_id );
		}

		return array(
			'matched'   => count( $match['pairs'] ),
			'lowered'   => count( $lowered ),
			'unmatched' => $unmatched,
			'failed'    => count( $failed ),
		);
	}

	/**
	 * Whether a variation's stock is its parent's, shared with the others -
	 * WooCommerce's "manage stock" set on the parent only.
	 *
	 * @param WC_Product $variation Variation.
	 * @return bool
	 */
	public static function stock_held_by_parent( $variation ) {
		return $variation instanceof WC_Product
			&& method_exists( $variation, 'get_stock_managed_by_id' )
			&& (int) $variation->get_stock_managed_by_id() !== (int) $variation->get_id();
	}

	/**
	 * eBay's sales of the variations sharing a parent's stock come off that
	 * stock: for each, its QuantitySold since its record, or with none, what
	 * it shows below the shared figure - each shows the whole of it, so never
	 * their sum against it. Only ever lowered; then each variation's record
	 * moves on to what eBay says. Order sync's settle and the import both
	 * use it (3.28.0).
	 *
	 * @param int     $parent_id The variable product.
	 * @param array[] $ebay      The listing's variations, as
	 *                           TCGiant_Sync_Variations::from_item() gives them.
	 * @param string  $item_id   The listing.
	 * @param string  $hold      'none'; 'unrecorded' to leave out a variation
	 *                           with no record (a website sale on its way, or
	 *                           one that never got there); 'all' to change
	 *                           nothing.
	 * @return array|null was and now (null when the stock could not be
	 *                    written), or null when the parent keeps no stock.
	 */
	public static function settle_pool( $parent_id, array $ebay, $item_id, $hold = 'none' ) {
		$parent = wc_get_product( (int) $parent_id );
		if ( ! $parent || ! $parent->managing_stock() || null === $parent->get_stock_quantity() ) {
			return null;
		}

		$pool = (int) $parent->get_stock_quantity();
		if ( 'all' === $hold ) {
			return array( 'was' => $pool, 'now' => $pool );
		}

		$pooled = self::pooled_variations( $parent_id, $ebay );
		$unseen = 0;
		$known  = array();

		foreach ( $pooled as $i => $p ) {
			if ( '' === $p['sku'] || null === $p['available'] ) {
				continue;
			}
			$record = self::own_record( $p['wc_id'], $item_id, $p['sku'] );
			if ( null === $record && 'unrecorded' === $hold ) {
				continue;
			}
			$u = self::unseen( $p['wc_id'], $item_id, $p['sku'], (int) $p['available'], (int) $p['sold'], null === $record ? $pool : null );
			if ( null !== $u ) {
				$unseen     += $u;
				$known[ $i ] = true;
			}
		}

		// An eBay sale of one variation comes off the shared stock: eBay's
		// other variations still show the stock before it. The new figure is
		// sent to them, as a sale is - queued first, as order sync's take
		// queues it: the push reads WooCommerce's stock when it runs, and a
		// request that dies after the stock write no longer leaves them
		// untold (fix round 4; release fix round 4). Its records are made
		// with the stock, so eBay's answer to it is no new sale.
		if ( $unseen > 0 ) {
			self::queue_after_ebay_sale( (int) $parent_id );
		}

		$records = function () use ( $known, $pooled, $item_id ) {
			foreach ( array_keys( $known ) as $i ) {
				self::record_sold_seen( $pooled[ $i ]['wc_id'], $item_id, $pooled[ $i ]['sku'], (int) $pooled[ $i ]['sold'] );
			}
		};
		$now = $unseen > 0 ? self::lower_stock_to( (int) $parent_id, $pool - $unseen, $records ) : $pool;
		// Written again when the stock was lowered - the same records - and
		// so when there was nothing to lower too.
		if ( null !== $now ) {
			$records();
		}

		return array( 'was' => $pool, 'now' => $now );
	}

	/**
	 * eBay's sales came off WooCommerce's stock here, by order sync's settle
	 * or the import, and eBay does not show the result: queue the push that
	 * tells it. A stock change made in WooCommerce, sent past the 80%
	 * ceiling as a sale is (is_priority()). One waiting is enough.
	 *
	 * @param int $product_id Product or variation whose stock was lowered.
	 * @return void
	 */
	public static function queue_after_ebay_sale( $product_id ) {
		$product  = wc_get_product( (int) $product_id );
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		if ( ! $product || ! self::pushes_enabled( $settings ) ) {
			return;
		}

		$target = self::instance()->push_target( $product, false );

		// Not on eBay: nothing to send, and nothing to read.
		if ( is_wp_error( $target ) && 'not_linked' === $target->get_error_code() ) {
			return;
		}

		// Not connected to eBay, and not just for a while (may_queue()), as
		// the other two queues: nothing could send it.
		if ( ! self::may_queue( $settings ) ) {
			return;
		}

		if ( is_wp_error( $target ) ) {
			return;
		}

		$holder_id = (int) $target['product_id'];

		// A website sale's push of it already on its way reads eBay itself
		// when it runs and sends the figure then: a second one only doubled
		// the calls (3.28.0 release fix round 1). Only a sale's: an edit's
		// push may be waiting hours for its try again, and eBay's other
		// variations would go on selling the unit meanwhile (release fix
		// round 2).
		if ( self::live_sale_push( $holder_id ) ) {
			return;
		}

		$dedup_key = 'tcgiant_stock_pending_' . $holder_id;
		if ( get_transient( $dedup_key ) ) {
			return;
		}
		set_transient( $dedup_key, 1, 60 );

		as_enqueue_async_action( self::PUSH_HOOK, array( array(
			'product_id' => $holder_id,
			'reason'     => 'edit',
			'ebay_sale'  => 1,
			'item'       => (string) $target['item_id'],
			'token'      => self::mark_push_pending( $holder_id ),
		) ), self::PUSH_GROUP );
	}

	/**
	 * Whether this ended listing's variations are already in the stock.
	 *
	 * @param int    $parent_id    Variable product.
	 * @param string $ebay_item_id The listing.
	 * @return bool
	 */
	public static function variations_already_settled( $parent_id, $ebay_item_id ) {
		return '' !== (string) $ebay_item_id
			&& (string) $ebay_item_id === (string) get_post_meta( (int) $parent_id, '_ebay_variations_settled_item', true );
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

		// A website order's own reduction: the order's push sends it. Only
		// that order's products: another product's change in the same
		// request - a refund, a hand edit, another order's line edited - is
		// still pushed. (Before 3.28.0, one order's reduction stopped every
		// stock change for the rest of the request.)
		if ( self::reduced_by_order( $product ) ) {
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

		if ( self::reduced_by_order( $variation ) ) {
			return;
		}

		$this->queue_stock_update_for_product( $variation );
	}

	/**
	 * Whether this stock change is a website order's reduction, which the
	 * order's own push sends to eBay - see note_order_reduction().
	 *
	 * @param WC_Product $product Product or variation whose stock changed.
	 * @return bool
	 */
	private static function reduced_by_order( $product ) {
		return isset( self::$order_reduction_products[ (int) $product->get_id() ] );
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
	 * Queue a push of one product's stock to eBay, after a stock change that
	 * did not come from eBay or from a website order: a hand edit, a CSV
	 * import, the REST API.
	 *
	 * Only the product is queued, never a quantity or a SKU. The push reads
	 * the product's stock when it runs, and finds the listing from the product
	 * itself: until 3.28.0 a SKU was queued and looked up again in the
	 * database, and two products sharing a barcode, or a Sell Similar copy
	 * sharing a SKU, sent one product's stock to the other's listing - or
	 * ended it.
	 *
	 * @param WC_Product $product Product or variation object.
	 */
	private function queue_stock_update_for_product( $product ) {
		// This stock value came from eBay — pushing it back would be an echo.
		if ( self::is_push_suppressed() ) {
			return;
		}

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );

		if ( ! self::pushes_enabled( $settings ) ) {
			self::note_push_off();
			return;
		}

		$target = $this->push_target( $product, false );

		// Most of a shop's products are not on eBay at all: nothing to say,
		// and nothing to read - a CSV of them on a shop with no tokens asks
		// the database nothing.
		if ( is_wp_error( $target ) && 'not_linked' === $target->get_error_code() ) {
			return;
		}

		// Not connected to eBay, and not just for a while: never connected, or
		// disconnected longer ago than the tries would last. Nothing could
		// send it. A shop disconnected for less still queues it, and the
		// tries carry it past the reconnect (PUSH_RECONNECT_WINDOW).
		if ( ! self::may_queue( $settings ) ) {
			return;
		}

		if ( is_wp_error( $target ) ) {
			self::log_not_pushed( (int) $product->get_id(), $target );
			return;
		}

		// The product whose stock is sent: itself, or for a variation whose
		// stock its parent keeps, the parent.
		$product_id = (int) $target['product_id'];
		$holder     = $product_id === (int) $product->get_id() ? $product : wc_get_product( $product_id );

		// A product that does not track stock has no quantity to send.
		if ( ! $holder || ! $holder->managing_stock() ) {
			return;
		}

		$new_stock = $holder->get_stock_quantity();

		// Same guard as the order path. The stock-set hooks should only fire
		// for tracked products, but nothing here depended on that being true,
		// and a null arriving downstream reads as zero and ends the listing.
		if ( null === $new_stock || '' === $new_stock ) {
			return;
		}

		// An auction is always one item, so there is no quantity to send it.
		// Only running out matters to it - see push_auction().
		if ( (int) $new_stock > 0 && $target['auction'] ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Real-time stock sync: WC #%d stock changed to %d. It is on eBay auction %s, which is always one item, so no quantity is sent.',
				$product_id, $new_stock, $target['item_id']
			) );
			return;
		}

		// One push waiting for this product is enough: it reads the stock when
		// it runs, so a change made before then goes with it. The mark is
		// taken off as that push starts, so a change made while it is under
		// way queues another.
		$dedup_key = 'tcgiant_stock_pending_' . $product_id;
		if ( get_transient( $dedup_key ) ) {
			return;
		}
		set_transient( $dedup_key, 1, 60 );

		TCGiant_Sync_Logger::log( sprintf(
			'Real-time stock sync: WC #%d stock changed to %d - queuing eBay update for listing %s.',
			$product_id, $new_stock, $target['item_id']
		) );

		as_enqueue_async_action( self::PUSH_HOOK, array( array(
			'product_id' => $product_id,
			'reason'     => 'edit',
			'item'       => $target['item_id'],
			'token'      => self::mark_push_pending( $product_id ),
		) ), self::PUSH_GROUP );
	}

	/**
	 * Whether stock changes made in WooCommerce are sent to eBay at all: "Send
	 * website stock changes to eBay?" (push_stock_to_ebay), on unless the
	 * shop says No. The one place that decides.
	 *
	 * Until 3.28.0 "Reduce WooCommerce stock on eBay sale?" (enable_order_sync)
	 * decided it, though it says nothing of the kind: saved as No, its
	 * default, it stopped every push, so an item sold on the website stayed
	 * for sale on eBay. Now it only does what it says. A shop that has not
	 * answered the new setting follows the old one only while
	 * FLIP_LEGACY_ZERO is false; so pushes are on for those shops as soon as
	 * 3.28.0 is in, whether or not maybe_upgrade_push_switch() has run yet.
	 * And it follows the old one as the upgrade found it (legacy_zero()):
	 * the relabelled field, saved since, never changes what is sent.
	 *
	 * The order screen's "Push to eBay" is asked for by hand and goes whatever
	 * this says.
	 *
	 * @param mixed $settings tcgiant_sync_ebay_settings; read when not an array.
	 * @return bool
	 */
	public static function pushes_enabled( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		}

		if ( isset( $settings['push_stock_to_ebay'] ) ) {
			return ! is_scalar( $settings['push_stock_to_ebay'] ) || '0' !== (string) $settings['push_stock_to_ebay'];
		}

		return self::FLIP_LEGACY_ZERO || ! self::legacy_zero( $settings );
	}

	/**
	 * Whether the shop was a "No" shop - "Reduce WooCommerce stock on eBay
	 * sale?" stored as '0' - when the 3.28.0 upgrade looked: the record's
	 * legacy, frozen then. Since 3.28.0 that field means something else, and
	 * an Import save stores its default, '0', on any shop; read live, it
	 * would turn pushes off (in a build with FLIP_LEGACY_ZERO false) for
	 * shops that never were "No" shops. Only before the record exists - the
	 * part of the first request before init - is the stored key read. A
	 * shop that had none, or a record that is not one, is not a "No" shop.
	 *
	 * @param array $settings tcgiant_sync_ebay_settings.
	 * @return bool
	 */
	private static function legacy_zero( array $settings ) {
		$record = get_option( self::PUSH_SWITCH_OPTION, false );
		$legacy = false === $record ? ( $settings['enable_order_sync'] ?? null ) : ( $record['legacy'] ?? null );

		return is_scalar( $legacy ) && '0' === (string) $legacy;
	}

	/**
	 * Say, once a day, that a stock change was not sent because the shop
	 * switched sending off: pushes are never off with nothing in the log to
	 * say so.
	 *
	 * @return void
	 */
	private static function note_push_off() {
		if ( get_transient( self::PUSH_OFF_NOTED ) ) {
			return;
		}
		set_transient( self::PUSH_OFF_NOTED, 1, DAY_IN_SECONDS );

		TCGiant_Sync_Logger::log( 'A website stock change was not sent to eBay because "Send website stock changes to eBay?" is No in Settings. (Said once a day.)' );
	}

	/**
	 * Whether the shop holds an eBay connection: both tokens, as
	 * TCGiant_Sync_OAuth::is_authenticated() asks.
	 *
	 * @param mixed $settings tcgiant_sync_ebay_settings.
	 * @return bool
	 */
	public static function connected( $settings ) {
		return ! empty( $settings['access_token'] ) && ! empty( $settings['refresh_token'] );
	}

	/**
	 * Whether the shop disconnected from eBay within PUSH_RECONNECT_WINDOW.
	 * The stamp is read from its row: this request may have read it - or
	 * found it missing - before another request saved or deleted it. Asked
	 * only when no tokens are stored.
	 *
	 * @return bool
	 */
	private static function disconnected_lately() {
		return time() - (int) self::option_row( self::DISCONNECTED_OPTION ) < self::PUSH_RECONNECT_WINDOW;
	}

	/**
	 * Whether a stock change is queued for eBay at all: the shop is
	 * connected, or disconnected lately enough for the tries to outlast it.
	 * This request's copy without tokens is checked against the row: a
	 * checkout that began before a reconnect, and takes the stock after it,
	 * is connected all the same.
	 *
	 * @param mixed $settings tcgiant_sync_ebay_settings.
	 * @return bool
	 */
	private static function may_queue( $settings ) {
		return self::connected( $settings ) || self::connected( self::settings_row() ) || self::disconnected_lately();
	}

	/**
	 * Hook: update_option_tcgiant_sync_ebay_settings. The tokens taken away
	 * - Disconnect, or any other save without them - stamp the time
	 * (DISCONNECTED_OPTION); tokens saved again, by the connection or the
	 * relay's hand-back, take the stamp away.
	 *
	 * @param mixed $old The settings before.
	 * @param mixed $new The settings now.
	 * @return void
	 */
	public static function connection_saved( $old, $new ) {
		if ( self::connected( $new ) ) {
			delete_option( self::DISCONNECTED_OPTION );
		} elseif ( self::connected( $old ) ) {
			update_option( self::DISCONNECTED_OPTION, time(), false );
		}
	}

	/**
	 * tcgiant_sync_ebay_settings as the database holds it now, read straight
	 * from its row: get_option() gives this request's copy, loaded when the
	 * request began, and a change saved by another request since - a
	 * Disconnect, a token refresh, a switch turned off - is not in it. For
	 * the stop checks of a push, and for a write-back that changes one key
	 * after a long call. A non-array is no settings; a row that cannot be
	 * read gives this request's copy.
	 *
	 * @return array
	 */
	public static function settings_row() {
		$value = self::option_row( 'tcgiant_sync_ebay_settings' );
		if ( null === $value ) {
			$value = get_option( 'tcgiant_sync_ebay_settings', array() );
		}

		return is_array( $value ) ? $value : array();
	}

	/**
	 * An option as its row holds it now, past this request's cache; null
	 * when there is no row, or it cannot be read.
	 *
	 * @param string $option Option name.
	 * @return mixed
	 */
	private static function option_row( $option ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the point is to miss the cache.
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );

		return null === $row ? null : maybe_unserialize( $row );
	}

	/**
	 * Write back a few keys of the settings after a long call - the order
	 * import's, the weekly stock check's, the store name: onto the settings
	 * as the database holds them now (settings_row()), changing only those
	 * keys, so that what another request saved meanwhile - a Disconnect, a
	 * refreshed token - stands. This request's cached copy is dropped first:
	 * update_option() compares with it, and skips the write when it happens
	 * to equal the new value.
	 *
	 * @param array $keys Key => value.
	 * @return void
	 */
	public static function save_settings_keys( array $keys ) {
		$settings = array_replace( self::settings_row(), $keys );

		self::forget_settings_copy();
		update_option( 'tcgiant_sync_ebay_settings', $settings );
	}

	/**
	 * Drop this request's cached copy of the settings - the autoloaded set
	 * and the option's own entry, for one WordPress does not autoload - so
	 * that the next get_option() reads them as the database holds them now.
	 *
	 * @return void
	 */
	public static function forget_settings_copy() {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'tcgiant_sync_ebay_settings', 'options' );
	}

	/**
	 * Why a push that is to be tried again must stop now, for good - '' when
	 * it may go on: a staging copy, or "Send website stock changes to eBay?"
	 * switched to No since it was queued - by any request: the settings are
	 * read from their row. Either way it is not tried again, not given up as
	 * an unsent sale, and leaves no safety try behind. (A push asked for by
	 * hand is never tried again, so never asks.)
	 *
	 * @return string
	 */
	private static function push_stopped() {
		$blocked = TCGiant_Sync_Environment::write_block();
		if ( $blocked ) {
			return $blocked->get_error_message();
		}

		if ( ! self::pushes_enabled( self::settings_row() ) ) {
			return '"Send website stock changes to eBay?" is No in Settings.';
		}

		return '';
	}

	/**
	 * Hook: init, priority 1 - at the first request of any kind after the
	 * update to 3.28.0, and again only when a later release changes
	 * FLIP_LEGACY_ZERO.
	 *
	 * A shop whose "Reduce WooCommerce stock on eBay sale?" is stored as No,
	 * and that has not answered "Send website stock changes to eBay?", had
	 * its stock pushes switched off by that setting (pushes_enabled()). They
	 * are on now: this says so, in the log, and on the dashboard
	 * (push_notice_pending()). Nothing is sent at the upgrade, and the
	 * settings are not written - not from a shopper's request, and not with
	 * anything a stale copy of them could put back.
	 *
	 * Unless WP-Lister for eBay is active - its code loaded, as 3.27.8's
	 * converter tells (installed but not activated, it is not; active for a
	 * whole multisite network, it is): the shop may have switched pushes off
	 * so that WP-Lister alone sends its stock, and two plugins sending to the
	 * same listings overwrite each other's quantities. Then pushes stay off,
	 * stored as the shop's own No (push_stock_to_ebay '0', the one key
	 * written, onto the row: save_settings_keys()), so that nothing turns
	 * them on later - not WP-Lister deactivated, not a later release
	 * (redecide_push_switch() never moves a stored choice). One warning says
	 * why, and the notice says how to turn them on.
	 *
	 * The record is the claim on the run: it holds no time, so a request
	 * racing this one inserts the identical row, which MySQL reports as no
	 * row changed, add_option() is false there, and the line is written once.
	 * It keeps the FLIP_LEGACY_ZERO it was decided with (flip): see
	 * redecide_push_switch().
	 *
	 * @return void
	 */
	public static function maybe_upgrade_push_switch() {
		$record = get_option( self::PUSH_SWITCH_OPTION, false );

		if ( false !== $record ) {
			// Decided by this build's switch (a record from before 'flip' was
			// kept was decided with it on).
			if ( is_array( $record ) && self::FLIP_LEGACY_ZERO !== (bool) ( $record['flip'] ?? true ) ) {
				self::redecide_push_switch( $record );
			}
			return;
		}

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$legacy   = isset( $settings['enable_order_sync'] ) && is_scalar( $settings['enable_order_sync'] ) ? (string) $settings['enable_order_sync'] : 'absent';
		$affected = '0' === $legacy && ! isset( $settings['push_stock_to_ebay'] );
		$held     = $affected && TCGiant_Sync_WPLister_Converter::wplister_active();

		$record = array(
			'since'   => '3.28.0',
			'legacy'  => $legacy,
			'flipped' => self::FLIP_LEGACY_ZERO && $affected && ! $held,
			'notice'  => $affected ? 'pending' : 'none',
			'flip'    => self::FLIP_LEGACY_ZERO,
		);
		if ( $held ) {
			$record['held'] = 'wplister';
		}

		if ( ! add_option( self::PUSH_SWITCH_OPTION, $record, '', true ) ) {
			return;
		}

		if ( $held ) {
			self::save_settings_keys( array( 'push_stock_to_ebay' => '0' ) );
			TCGiant_Sync_Logger::warning( 'Updated to 3.28.0: website stock changes are still not sent to eBay, because WP-Lister for eBay is active on this site, and two plugins sending stock to the same eBay listings overwrite each other\'s quantities. To use TCGiant Sync\'s, turn off WP-Lister\'s stock sync, then set "Send website stock changes to eBay?" to Yes in Settings.' );
		} elseif ( $record['flipped'] ) {
			TCGiant_Sync_Logger::warning( 'Updated to 3.28.0: website stock changes are now sent to eBay. They had been switched off on this site since "Reduce WooCommerce stock on eBay sale?" was saved as No - that setting was wrongly turning them off as well, and now only does what it says. To stop sending stock to eBay, set "Send website stock changes to eBay?" to No in Settings.' );
		}
	}

	/**
	 * A release with the other FLIP_LEGACY_ZERO: decided again, for a shop
	 * the switch decides - a "No" shop when the upgrade looked (the record's
	 * legacy, legacy_zero()), "Send website stock changes to eBay?" never
	 * stored. Its record is rewritten, one line says which way it went, and
	 * the notice is due again, in words of its own ('redecided'). A shop
	 * that has stored the switch - saved it as Yes or No - is never moved;
	 * its record only learns the new switch. Nothing is sent.
	 *
	 * The rewrite is the claim: a request racing this one writes the same
	 * row, which MySQL reports as no row changed, so update_option() is
	 * false there and the line is written once.
	 *
	 * @param array $record The record, decided with the other switch.
	 * @return void
	 */
	private static function redecide_push_switch( array $record ) {
		$settings = self::settings_row();
		$decides  = ! isset( $settings['push_stock_to_ebay'] ) && self::legacy_zero( $settings );

		$record['flip'] = self::FLIP_LEGACY_ZERO;
		if ( $decides ) {
			$record['flipped']   = self::FLIP_LEGACY_ZERO;
			$record['notice']    = 'pending';
			$record['redecided'] = true;
		}

		if ( ! update_option( self::PUSH_SWITCH_OPTION, $record, true ) || ! $decides ) {
			return;
		}

		TCGiant_Sync_Logger::warning( self::FLIP_LEGACY_ZERO
			? 'Updated: this version of TCGiant Sync sends this site\'s website stock changes to eBay, as "Send website stock changes to eBay?" has never been chosen here. To stop them, set "Send website stock changes to eBay?" to No in Settings.'
			: 'Updated: this version of TCGiant Sync does not send this site\'s website stock changes to eBay, as "Send website stock changes to eBay?" has never been chosen here. To send them, set "Send website stock changes to eBay?" to Yes in Settings.' );
	}

	/**
	 * The 3.28.0 upgrade's record (maybe_upgrade_push_switch()); empty before
	 * it has run.
	 *
	 * @return array
	 */
	public static function push_switch_record() {
		return get_option( self::PUSH_SWITCH_OPTION, array() );
	}

	/**
	 * Whether the dashboard notice about the switch has still to be seen.
	 *
	 * @return bool
	 */
	public static function push_notice_pending() {
		return 'pending' === ( self::push_switch_record()['notice'] ?? '' );
	}

	/**
	 * Put the notice away: Got it, a save of Import Settings, or the shop's
	 * own choice made since.
	 *
	 * @return void
	 */
	public static function dismiss_push_notice() {
		$record = self::push_switch_record();
		if ( 'pending' !== ( $record['notice'] ?? '' ) ) {
			return;
		}
		$record['notice'] = 'dismissed';
		update_option( self::PUSH_SWITCH_OPTION, $record, true );
	}

	/**
	 * The switch, for the usage ping and the dashboard: 'off'; 'on_by_upgrade'
	 * - on because the 3.28.0 upgrade turned it on, and never chosen since
	 * (push_stock_to_ebay not stored); or 'on' - chosen as Yes, or on with
	 * nothing turned on by the upgrade.
	 *
	 * @return string
	 */
	public static function push_switch_state() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );

		if ( ! self::pushes_enabled( $settings ) ) {
			return 'off';
		}
		return ! isset( $settings['push_stock_to_ebay'] ) && ! empty( self::push_switch_record()['flipped'] ) ? 'on_by_upgrade' : 'on';
	}

	/**
	 * Hook: update_option_tcgiant_sync_ebay_settings. "Import eBay auctions"
	 * changed: the day's answer to whether the shop has an auction
	 * (shop_may_have_auctions()) is asked again.
	 *
	 * @param mixed $old The settings before.
	 * @param mixed $new The settings now.
	 * @return void
	 */
	public static function auctions_setting_saved( $old, $new ) {
		if ( ( $old['import_auctions'] ?? null ) !== ( $new['import_auctions'] ?? null ) ) {
			delete_transient( self::AUCTIONS_TRANSIENT );
		}
	}

	/**
	 * The product that holds a product's eBay listing link: itself, or for a
	 * variation, its parent - a variation has no link of its own.
	 *
	 * The one rule for "linked through the parent", shared by order sync and
	 * the stock push.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return int
	 */
	public static function link_owner( $product ) {
		$id = (int) $product->get_id();

		if ( '' === (string) get_post_meta( $id, '_ebay_item_id', true ) && $product->get_parent_id() ) {
			return (int) $product->get_parent_id();
		}

		return $id;
	}

	/**
	 * What a stock push for this product is sent to, or why nothing is.
	 *
	 * Every push, queued or run, asks this one place: the listing comes from
	 * the product itself, never from a SKU or barcode another product may
	 * share.
	 *
	 * Stock a variable product keeps for all its variations together - the
	 * parent manages it - is sent as that one figure to every variation of
	 * the listing (a "pool" push). A variation whose stock its parent keeps
	 * is pushed as its parent.
	 *
	 * @param WC_Product $product The product whose stock changed.
	 * @param bool       $full    Also refuse a listing another product claims.
	 *                            One more database read, so it is left to the
	 *                            push itself rather than every stock change.
	 * @return array|WP_Error product_id (the product whose stock is sent),
	 *                        owner_id (the product holding the link), item_id,
	 *                        var_sku (eBay's SKU for a variation, '' otherwise),
	 *                        variation, pool and auction; or why there is
	 *                        nothing to push.
	 */
	private function push_target( $product, $full = true ) {
		$product_id = (int) $product->get_id();
		$owner_id   = self::link_owner( $product );
		$item_id    = (string) get_post_meta( $owner_id, '_ebay_item_id', true );

		if ( '' === $item_id ) {
			return new WP_Error( 'not_linked', 'Not linked to an eBay listing' );
		}

		$variation = $product->is_type( 'variation' );
		$pool      = false;

		if ( $product->is_type( 'variable' ) ) {
			// Each variation keeps its own stock: they push for themselves. The
			// import keeps a total on the parent too, which says nothing about
			// any one variation, so it is not sent either.
			if ( ! $product->managing_stock() || ! self::shares_parent_stock( $product ) ) {
				return new WP_Error( 'variable_parent', sprintf(
					'WC #%d is a variable product whose variations keep their own stock, so its own figure is not sent to eBay listing %s',
					$product_id,
					$item_id
				) );
			}
			$pool = true;
		} elseif ( $variation && self::stock_held_by_parent( $product ) ) {
			// The parent's pool, sent as the parent.
			$parent = wc_get_product( $owner_id );
			if ( ! $parent ) {
				return new WP_Error( 'parent_stock', sprintf( 'variation #%d takes its stock from WC #%d, which is not there', $product_id, $owner_id ) );
			}
			return $this->push_target( $parent, $full );
		}

		// eBay's SKU for the variation: what the import recorded, or the
		// variation's own - never the parent's, which a variation with no SKU
		// shows. That one named the whole listing, and at 0 ended it.
		$var_sku = '';
		if ( $variation ) {
			$var_sku = (string) $product->get_meta( '_ebay_variation_sku' );
			if ( '' === $var_sku ) {
				$var_sku = (string) $product->get_sku( 'edit' );
			}
			if ( '' === $var_sku ) {
				return new WP_Error( 'no_variation_sku', sprintf(
					'Variation has no SKU: eBay listing %s can only be told about a variation by its SKU, and variation #%d of WC #%d has none, so its stock is not sent. Give it the SKU it has on eBay (3.28.0 cannot yet send a variation without one)',
					$item_id,
					$product_id,
					$owner_id
				) );
			}
		}

		// The same listing claimed by another product - one copied before the
		// duplicate guard: either one's stock would land on both.
		if ( $full ) {
			$shared = TCGiant_Sync_Listing_Link::find_products_sharing_item_id( $owner_id, $item_id );
			if ( $shared ) {
				return new WP_Error( 'shared_item', sprintf(
					'eBay listing %s is linked to WC #%d and also to #%s, so a stock change of one would be sent for all of them. Nothing is sent until one of them is unlinked',
					$item_id,
					$owner_id,
					implode( ', #', $shared )
				) );
			}
		}

		return array(
			'product_id' => $product_id,
			'owner_id'   => $owner_id,
			'item_id'    => $item_id,
			'var_sku'    => $var_sku,
			'variation'  => $variation,
			'pool'       => $pool,
			'auction'    => 'Chinese' === (string) get_post_meta( $owner_id, '_ebay_listing_type', true ),
		);
	}

	/**
	 * Whether any of a variable product's variations takes its stock from it.
	 *
	 * @param WC_Product $parent Variable product.
	 * @return bool
	 */
	private static function shares_parent_stock( $parent ) {
		foreach ( (array) $parent->get_children() as $child_id ) {
			$child = wc_get_product( (int) $child_id );
			if ( $child && self::stock_held_by_parent( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Say why a linked product's stock change is not sent. A warning: the
	 * product is on eBay, and eBay is not told.
	 *
	 * @param int      $product_id Product.
	 * @param WP_Error $why        From push_target().
	 * @return void
	 */
	private static function log_not_pushed( $product_id, $why ) {
		$message = sprintf( 'Stock change for WC #%d not sent to eBay: %s.', $product_id, $why->get_error_message() );

		if ( 'not_linked' === $why->get_error_code() ) {
			TCGiant_Sync_Logger::log( $message );
		} else {
			TCGiant_Sync_Logger::warning( $message );
		}
	}

	/**
	 * Note that a stock change of this product is on its way to eBay.
	 *
	 * @param int    $product_id The product whose stock is pushed.
	 * @param string $kind       'sale' for a website sale's push: an import
	 *                           then leaves the stock alone altogether.
	 * @return string The push's token, for push_done().
	 */
	private static function mark_push_pending( $product_id, $kind = '' ) {
		$token   = uniqid( 'sale' === $kind ? self::SALE_TOKEN_PREFIX : '', true );
		$pending = self::pending_entries( $product_id );

		// Two days at most, for a push whose action is lost: past that it no
		// longer holds back an import. The time it was queued goes with it,
		// for a pool waiting on it (own_push_on_its_way(), fix round 8).
		$pending[ $token ] = array(
			'until'     => time() + self::PUSH_PENDING_FOR,
			'queued_at' => time(),
		);
		update_post_meta( (int) $product_id, self::PUSH_PENDING_KEY, $pending );

		return $token;
	}

	/**
	 * A push is over - sent, refused for good, or found to have nothing to
	 * send. It still counts for PUSH_PENDING_TAIL, for an import already
	 * holding eBay's figures from before it landed.
	 *
	 * A sale whose push never reached eBay keeps its mark (UNSENT_TOKEN)
	 * until a later push or a settle of the listing succeeds: until then an
	 * import would put the sold unit back.
	 *
	 * @param array $job    The push.
	 * @param bool  $forget Take the mark off at once, with no tail.
	 * @param bool  $unsent A sale that never reached eBay.
	 * @return void
	 */
	private static function push_done( array $job, $forget = false, $unsent = false ) {
		$product_id = (int) $job['product_id'];
		$token      = (string) ( $job['token'] ?? '' );
		$pending    = self::pending_entries( $product_id );
		$changed    = false;

		// A variation's own push for a pool is over: its tries are kept no
		// longer (own_push_on_its_way()).
		if ( ! empty( $job['pool_after'] ) ) {
			self::forget_own_tries( $product_id, $token );
		}

		// Its tail is a plain time: a push that is over has no queue time to
		// be read.
		if ( '' !== $token && isset( $pending[ $token ] ) ) {
			if ( $forget ) {
				unset( $pending[ $token ] );
			} else {
				$pending[ $token ] = min( self::pending_until( $pending[ $token ] ), time() + self::PUSH_PENDING_TAIL );
			}
			$changed = true;
		}

		if ( $unsent ) {
			// The units with it, by the push's token: added to a mark still
			// holding (a new count for one that had lapsed), and set again,
			// never added twice, when the same push gives up twice - a push
			// run twice over gave up twice and counted its sale twice (fix
			// round 4).
			$units = array();
			if ( isset( $pending[ self::UNSENT_TOKEN ] ) ) {
				$had   = get_post_meta( $product_id, self::UNSENT_QTY_KEY, true );
				$units = is_array( $had ) ? $had : ( is_numeric( $had ) ? array( 'earlier' => (int) $had ) : array() );
			}
			$units[ '' !== $token ? $token : uniqid( 'unsent-', true ) ] = max( 0, (int) ( $job['qty'] ?? 0 ) );
			update_post_meta( $product_id, self::UNSENT_QTY_KEY, $units );

			$pending[ self::UNSENT_TOKEN ] = time() + 30 * DAY_IN_SECONDS;
			$changed                        = true;
		}

		if ( $changed ) {
			if ( $pending ) {
				update_post_meta( $product_id, self::PUSH_PENDING_KEY, $pending );
			} else {
				delete_post_meta( $product_id, self::PUSH_PENDING_KEY );
			}
		}
	}

	/**
	 * eBay and WooCommerce agree again for this product - a push landed, or
	 * a settle brought eBay's figures in: a sale that never reached eBay no
	 * longer needs holding back.
	 *
	 * @param int $product_id Product.
	 * @return void
	 */
	public static function forget_unsent_sale( $product_id ) {
		$pending = self::pending_entries( $product_id );

		if ( isset( $pending[ self::UNSENT_TOKEN ] ) ) {
			unset( $pending[ self::UNSENT_TOKEN ] );
			delete_post_meta( (int) $product_id, self::UNSENT_QTY_KEY );
			if ( $pending ) {
				update_post_meta( (int) $product_id, self::PUSH_PENDING_KEY, $pending );
			} else {
				delete_post_meta( (int) $product_id, self::PUSH_PENDING_KEY );
			}
		}
	}

	/**
	 * How a stock check holds back for a website sale, as the import does
	 * (TCGiant_Sync_Mapper::sale_hold(), which reads the same marks by name):
	 * 'sale' while a sale's push is on its way; with only the mark of sales
	 * whose push never got there, their units (UNSENT_QTY_KEY); null when
	 * nothing is held. A mark with no units recorded holds as a push on its
	 * way does (3.28.0 release fix round 2).
	 *
	 * @param int $product_id Product or variation.
	 * @return string|int|null
	 */
	public static function sale_hold_of( $product_id ) {
		$pending = self::pending_pushes( $product_id );

		foreach ( array_keys( $pending ) as $token ) {
			if ( 0 === strpos( (string) $token, self::SALE_TOKEN_PREFIX ) ) {
				return 'sale';
			}
		}

		if ( ! isset( $pending[ self::UNSENT_TOKEN ] ) ) {
			return null;
		}

		$units = get_post_meta( (int) $product_id, self::UNSENT_QTY_KEY, true );
		if ( is_array( $units ) ) {
			return max( 0, (int) array_sum( array_map( 'intval', $units ) ) );
		}

		return is_numeric( $units ) ? max( 0, (int) $units ) : 'sale';
	}

	/**
	 * Whether a website sale of this product is still on its way to eBay, or
	 * never got there (UNSENT_TOKEN).
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function sale_pending( $product_id ) {
		foreach ( array_keys( self::pending_pushes( $product_id ) ) as $token ) {
			if ( self::UNSENT_TOKEN === $token || 0 === strpos( $token, self::SALE_TOKEN_PREFIX ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The pushes still counting for a product, the expired ones dropped.
	 *
	 * @param int $product_id Product.
	 * @return array token => until.
	 */
	private static function pending_pushes( $product_id ) {
		$out = array();

		foreach ( self::pending_entries( $product_id ) as $token => $entry ) {
			$out[ $token ] = self::pending_until( $entry );
		}

		return $out;
	}

	/**
	 * The entries of the pushes still counting for a product, as written:
	 * until, or array( until, queued_at ) (PUSH_PENDING_KEY). What is written
	 * back keeps each one's queue time.
	 *
	 * @param int $product_id Product.
	 * @return array token => entry.
	 */
	private static function pending_entries( $product_id ) {
		$pending = get_post_meta( (int) $product_id, self::PUSH_PENDING_KEY, true );
		$now     = time();
		$out     = array();

		foreach ( is_array( $pending ) ? $pending : array() as $token => $entry ) {
			if ( self::pending_until( $entry ) >= $now ) {
				$out[ (string) $token ] = $entry;
			}
		}

		return $out;
	}

	/**
	 * Until when a pending entry counts.
	 *
	 * @param mixed $entry From PUSH_PENDING_KEY.
	 * @return int
	 */
	private static function pending_until( $entry ) {
		return is_array( $entry ) ? (int) ( $entry['until'] ?? 0 ) : (int) $entry;
	}

	/**
	 * When a pending push was queued: as it was written (fix round 8), or
	 * for an entry from before, its until less PUSH_PENDING_FOR.
	 *
	 * @param mixed $entry From PUSH_PENDING_KEY.
	 * @return int
	 */
	private static function pending_queued_at( $entry ) {
		if ( is_array( $entry ) && isset( $entry['queued_at'] ) ) {
			return (int) $entry['queued_at'];
		}

		return self::pending_until( $entry ) - self::PUSH_PENDING_FOR;
	}

	// ───────────────────────────────────────────────────────────────────────────
	// What eBay has sold that WooCommerce has heard of (3.28.0)
	//
	// A push after a website sale used to send eBay's figure less the sale.
	// Two sales before the queue ran each took their sale off a figure that
	// already had both off, and the second ended a listing that still had one
	// to sell. What a push needs to know instead is which of eBay's own sales
	// WooCommerce's figure has not taken off yet. So eBay's QuantitySold is
	// recorded, per listing and per variation, whenever WooCommerce is known
	// to have taken off every eBay sale up to it: when a push has worked it
	// out, and when an import writes eBay's figure. Any sold since are the
	// ones WooCommerce has not heard of.
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * eBay's QuantitySold that this product's stock has taken off, for this
	 * listing and SKU.
	 *
	 * @param int    $product_id The product holding the stock.
	 * @param string $item_id    The listing.
	 * @param string $sku        eBay's variation SKU, '' for the whole listing.
	 * @return int|null Null when nothing is recorded for this listing.
	 */
	public static function sold_seen( $product_id, $item_id, $sku = '' ) {
		$seen = self::own_record( $product_id, $item_id, $sku );

		return null !== $seen && isset( $seen['sold'] ) ? max( 0, (int) $seen['sold'] ) : null;
	}

	/**
	 * The record kept for this listing and SKU, whichever kind: array( sold )
	 * once eBay's QuantitySold is known, or array( sent ) - what eBay was left
	 * showing by a push that landed when eBay's answer did not say how many it
	 * had sold (see record_sent()).
	 *
	 * @param int    $product_id The product holding the stock.
	 * @param string $item_id    The listing.
	 * @param string $sku        eBay's variation SKU, or ''.
	 * @return array|null
	 */
	private static function own_record( $product_id, $item_id, $sku ) {
		$seen = get_post_meta( (int) $product_id, self::SOLD_SEEN_KEY, true );

		if ( ! is_array( $seen ) || ! ( isset( $seen['sold'] ) || isset( $seen['sent'] ) )
			|| (string) ( $seen['item'] ?? '' ) !== (string) $item_id
			|| (string) ( $seen['sku'] ?? '' ) !== (string) $sku ) {
			return null;
		}

		return $seen;
	}

	/**
	 * A push landed and eBay's answer did not say how many the listing has
	 * sold: what eBay was left showing is noted instead. Any fall in that
	 * figure by the next time eBay is read is eBay's own sales (unseen()).
	 * Never over a record of eBay's QuantitySold, which says more.
	 *
	 * @param int    $product_id The product holding the stock.
	 * @param string $item_id    The listing.
	 * @param string $sku        eBay's variation SKU, or ''.
	 * @param int    $sent       What eBay shows now.
	 * @return void
	 */
	private static function record_sent( $product_id, $item_id, $sku, $sent ) {
		if ( null !== self::sold_seen( $product_id, $item_id, $sku ) ) {
			return;
		}

		update_post_meta( (int) $product_id, self::SOLD_SEEN_KEY, array(
			'item' => (string) $item_id,
			'sku'  => (string) $sku,
			'sent' => max( 0, (int) $sent ),
		) );
	}

	/**
	 * eBay's sales of this listing (or variation) that the product's stock
	 * has not taken off, from what eBay says now.
	 *
	 * - A record of eBay's QuantitySold: those sold since it.
	 * - What a landed push left eBay showing: any fall since, no more than
	 *   eBay has sold.
	 * - Neither, for a website sale's push: what eBay shows below the stock
	 *   before the sale, no more than eBay has sold.
	 * - Otherwise there is nothing to go on: null.
	 *
	 * @param int      $product_id The product holding the stock.
	 * @param string   $item_id    The listing.
	 * @param string   $sku        eBay's variation SKU, or ''.
	 * @param int      $available  What eBay has available now.
	 * @param int      $sold       eBay's QuantitySold now.
	 * @param int|null $before     A sale's stock before it, or null.
	 * @return int|null
	 */
	private static function unseen( $product_id, $item_id, $sku, $available, $sold, $before ) {
		$record    = self::own_record( $product_id, $item_id, $sku );
		$available = max( 0, (int) $available );
		$sold      = max( 0, (int) $sold );

		if ( null !== $record && isset( $record['sold'] ) ) {
			$seen = max( 0, (int) $record['sold'] );
			if ( $sold < $seen ) {
				// A cancelled eBay sale gives its unit back. eBay counting again
				// from a lower figure without one is not documented, but would
				// look the same: said, and the record follows it down.
				TCGiant_Sync_Logger::warning( sprintf(
					'WC #%d: eBay now says listing %s%s has sold %d, fewer than the %d recorded. A cancelled eBay sale does that; if none was cancelled, check the stock in Seller Hub.',
					$product_id,
					$item_id,
					'' !== (string) $sku ? ' (variation ' . $sku . ')' : '',
					$sold,
					$seen
				) );
			}
			return max( 0, $sold - $seen );
		}

		if ( null !== $record ) {
			return min( $sold, max( 0, (int) $record['sent'] - $available ) );
		}

		if ( null !== $before ) {
			return min( $sold, max( 0, (int) $before - $available ) );
		}

		return null;
	}

	/**
	 * Record that this product's stock has taken off eBay's sales up to
	 * $sold, for this listing and SKU.
	 *
	 * Stamped 'at' with eBay's own Timestamp on the latest answer read in
	 * this request (TCGiant_Sync_API::ebay_time()): no earlier than the read
	 * whose figures these are, by eBay's clock. Order sync takes an eBay
	 * sale off at once only when the stamp is older than the order
	 * (take_order_sale()); a stamp later than the read only leaves a sale to
	 * the sale check. With no answer read in this request, no stamp (3.28.0
	 * release fix round 2).
	 *
	 * @param int    $product_id The product holding the stock.
	 * @param string $item_id    The listing.
	 * @param string $sku        eBay's variation SKU, or ''.
	 * @param int    $sold       eBay's QuantitySold.
	 * @return void
	 */
	public static function record_sold_seen( $product_id, $item_id, $sku, $sold ) {
		$record = array(
			'item' => (string) $item_id,
			'sku'  => (string) $sku,
			'sold' => max( 0, (int) $sold ),
		);

		$at = self::ebay_time();
		if ( $at > 0 ) {
			$record['at'] = $at;
		}

		update_post_meta( (int) $product_id, self::SOLD_SEEN_KEY, $record );
	}

	/**
	 * eBay's Timestamp on the latest answer this request read; 0 when there
	 * was none, or an API that keeps none (a harness's stand-in).
	 *
	 * @return int
	 */
	private static function ebay_time() {
		if ( ! class_exists( 'TCGiant_Sync_API' ) ) {
			return 0;
		}

		$api = TCGiant_Sync_API::instance();

		return method_exists( $api, 'ebay_time' ) ? (int) $api->ebay_time() : 0;
	}

	/**
	 * Order sync's eBay sale of a variation whose stock its parent keeps,
	 * shared with the others (3.28.0 release fix round 2): taken off the
	 * shared stock at once, the pool's push queued so eBay's other
	 * variations hear of it, and the quantity added to the variation's
	 * record - but only when no read of eBay has seen the sale yet: the
	 * record's stamp, eBay's own Timestamp on the read that wrote it, is
	 * older than the order's creationDate by more than ORDER_CLOCK_MARGIN.
	 * A read since has taken it, or will (settle_pool(), from the record).
	 * With no record, no stamp or no creationDate, or inside the margin, it
	 * is left to the sale check.
	 *
	 * The push is queued first: it reads WooCommerce's stock when it runs,
	 * after this. The record is written with the stock (arm_record(), as
	 * lower_stock_to() writes every settle's): from WooCommerce's actions at
	 * PHP_INT_MIN, the first right after its stock SQL, so before its save of
	 * the product and anyone else's listener - one that throws or dies no
	 * longer leaves the sale taken but not recorded, for its check to take
	 * again (release fix rounds 3 and 4). If no action came but the stock
	 * moved, the record is written on the way out. Never the record first:
	 * that turns "twice" into "never".
	 *
	 * @param int    $parent_id    The variable product holding the stock.
	 * @param int    $variation_id The variation sold.
	 * @param string $item_id      The listing that sold.
	 * @param string $sku          eBay's SKU for the variation.
	 * @param int    $quantity     The order line's quantity.
	 * @param int    $created      The order's creationDate, a Unix time; 0 when unknown.
	 * @return bool Whether it was taken off here.
	 */
	public static function take_order_sale( $parent_id, $variation_id, $item_id, $sku, $quantity, $created ) {
		$record = self::own_record( $variation_id, $item_id, $sku );
		if ( null === $record || ! isset( $record['sold'] ) || empty( $record['at'] ) || (int) $created < 1
			|| (int) $created - (int) $record['at'] <= self::ORDER_CLOCK_MARGIN || (int) $quantity < 1 ) {
			return false;
		}

		self::queue_after_ebay_sale( (int) $parent_id );

		$record['sold'] = max( 0, (int) $record['sold'] ) + (int) $quantity;
		$variation      = wc_get_product( (int) $variation_id );
		// Written once, and only when the shared stock did come down: a
		// write another plugin holds back takes nothing off, and the check
		// is then the one to take it.
		$armed = $variation ? self::arm_record( $variation, self::held_stock( (int) $parent_id ), function () use ( $variation_id, $record ) {
			update_post_meta( (int) $variation_id, self::SOLD_SEEN_KEY, $record );
		} ) : null;
		if ( null === $armed ) {
			return false;
		}

		self::begin_ebay_origin();
		try {
			wc_update_product_stock( (int) $variation_id, (int) $quantity, 'decrease' );
		} finally {
			self::end_ebay_origin();
			$taken = self::disarm_record( $armed );
		}

		return $taken;
	}

	/**
	 * The stock a product holds now, as WooCommerce reads it; null when it
	 * does not manage stock.
	 *
	 * @param int $product_id Product.
	 * @return int|null
	 */
	private static function held_stock( $product_id ) {
		$product = wc_get_product( (int) $product_id );

		return $product && $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
	}

	/**
	 * A settle lowered this product's stock towards eBay's figure: that much
	 * of eBay's sales has now been taken off. Recorded only where a record is
	 * already kept - a lowering on its own does not say which sales it was.
	 *
	 * A record of what a landed push left eBay showing is first turned into
	 * one of eBay's QuantitySold: the sales since it are those still unseen.
	 *
	 * @param int      $product_id The product holding the stock.
	 * @param string   $item_id    The listing.
	 * @param string   $sku        eBay's variation SKU, or ''.
	 * @param int      $sold       eBay's QuantitySold now.
	 * @param int      $lowered_by How far the settle lowered the stock.
	 * @param int|null $available  What eBay has available now, when known.
	 * @return void
	 */
	public static function absorb_sold( $product_id, $item_id, $sku, $sold, $lowered_by, $available = null ) {
		$seen = self::sold_seen( $product_id, $item_id, $sku );

		$converted = false;
		if ( null === $seen && null !== $available && null !== self::own_record( $product_id, $item_id, $sku ) ) {
			$seen      = max( 0, (int) $sold ) - (int) self::unseen( $product_id, $item_id, $sku, $available, $sold, null );
			$converted = true;
		}

		if ( null === $seen ) {
			return;
		}

		$now = min( max( 0, (int) $sold ), $seen + max( 0, (int) $lowered_by ) );

		if ( $converted || $now !== $seen ) {
			self::record_sold_seen( $product_id, $item_id, $sku, $now );
		}
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
	 * Hook: woocommerce_can_reduce_order_stock, last of all.
	 *
	 * Notes the products a website order is about to take stock from, so the
	 * stock listeners leave that change to the order's own push (see
	 * $order_reduction_products). Only when the final answer is yes: a
	 * reduction another plugin stops is not a sale, and a hand edit in the
	 * same request is still pushed. Never changes the answer.
	 *
	 * @param bool          $can_reduce The answer so far.
	 * @param WC_Order|mixed $order     The order.
	 * @return bool
	 */
	public function note_order_reduction( $can_reduce, $order ) {
		if ( ! $can_reduce || ! is_a( $order, 'WC_Abstract_Order' ) || 'ebay' === $order->get_meta( '_ebay_order_source' ) ) {
			return $can_reduce;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			self::$order_reduction_products[ (int) $product->get_id() ] = true;

			// The stock WooCommerce actually writes: the parent's, for a
			// variation whose stock its parent keeps.
			if ( self::stock_held_by_parent( $product ) ) {
				self::$order_reduction_products[ (int) $product->get_stock_managed_by_id() ] = true;
			}
		}

		return $can_reduce;
	}

	/**
	 * Orders whose stock WooCommerce has been told to leave alone on saving,
	 * so the log says so once an order rather than once a line.
	 *
	 * @var array
	 */
	private static $kept_orders = array();

	/**
	 * Hook: woocommerce_prevent_adjust_line_item_product_stock.
	 *
	 * WooCommerce's wc_maybe_adjust_line_item_product_stock() asks this
	 * before it changes a product's stock for an order line saved, added or
	 * removed on the order's edit screen (WooCommerce 3.7.1 and later; the
	 * plugin needs 5.0). For a line of an order imported from eBay the answer
	 * is yes: eBay has counted that sale, the sync keeps the stock from it,
	 * and WooCommerce took nothing off when the order came in
	 * (block_double_reduction()), so whatever it worked out would be wrong -
	 * the sale taken off twice, or stock put back that it never took.
	 *
	 * @param bool                $prevent What WooCommerce, or another plugin, has said.
	 * @param WC_Order_Item|mixed $item    The order line.
	 * @return bool
	 */
	public function keep_ebay_order_lines( $prevent, $item = null ) {
		if ( $prevent || ! is_object( $item ) || ! method_exists( $item, 'get_order' ) ) {
			return $prevent;
		}

		$order = $item->get_order();
		if ( ! is_a( $order, 'WC_Abstract_Order' ) || 'ebay' !== $order->get_meta( '_ebay_order_source' ) ) {
			return $prevent;
		}

		$order_id = (int) $order->get_id();
		if ( ! isset( self::$kept_orders[ $order_id ] ) ) {
			self::$kept_orders[ $order_id ] = true;
			TCGiant_Sync_Logger::log( sprintf(
				'Order #%d came from eBay: saving it does not change WooCommerce stock, which the sync keeps from eBay.',
				$order_id
			) );
		}

		return true;
	}

	/**
	 * Push a website order's sale out to eBay.
	 *
	 * Each line is queued once. The push asks eBay what the listing has left
	 * and what it has sold, takes off WooCommerce's figure only the eBay sales
	 * WooCommerce has not heard of, and sends that - never more than eBay
	 * shows. Sending WooCommerce's figure alone, as before 3.28.0, put back on
	 * eBay a unit eBay had sold meanwhile.
	 *
	 * WooCommerce fires this every time it reduces an order's stock, even
	 * when every line was reduced before. A line is marked when its push is
	 * queued (_tcgiant_ebay_push_queued, the push's token) and again when the
	 * push has reached eBay (_tcgiant_ebay_push), and is queued again only
	 * when neither stands: its stock put back since (see
	 * forget_order_pushes()), or a push that is no longer on its way and
	 * never got there.
	 *
	 * @param WC_Order $order Order whose stock was just reduced.
	 */
	public function sync_stock_to_ebay( $order ) {
		try {
			$this->queue_order_pushes( $order );
		} finally {
			// The listeners' hold on this order's products ends with it.
			self::$order_reduction_products = array();
		}
	}

	/**
	 * Hook: woocommerce_restore_order_stock - an order cancelled, refunded or
	 * set back to pending, its stock put back.
	 *
	 * Its lines' marks go with the stock they were written for: paid later
	 * and reduced again, the sale is pushed again. It was not, and eBay kept
	 * the unit the website had sold a second time.
	 *
	 * @param WC_Order $order The order.
	 * @return void
	 */
	public function forget_order_pushes( $order ) {
		// Nothing of a reduction is left to hold the listeners back from.
		self::$order_reduction_products = array();

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			// Only the lines whose stock was put back: WooCommerce takes their
			// _reduced_stock away as it does.
			if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_meta( '_reduced_stock' ) > 0 ) {
				continue;
			}
			if ( '' === (string) $item->get_meta( '_tcgiant_ebay_push' ) && '' === (string) $item->get_meta( '_tcgiant_ebay_push_queued' ) ) {
				continue;
			}
			$item->update_meta_data( '_tcgiant_ebay_push', '' );
			$item->update_meta_data( '_tcgiant_ebay_push_queued', '' );
			$item->save_meta_data();
		}
	}

	/**
	 * The body of sync_stock_to_ebay().
	 *
	 * @param WC_Order $order Order whose stock was just reduced.
	 * @return void
	 */
	private function queue_order_pushes( $order ) {
		// Reducing stock for an order that was itself imported from eBay is an
		// eBay-originated change; eBay already decremented its own quantity.
		if ( self::is_push_suppressed() ) {
			return;
		}

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );

		if ( ! self::pushes_enabled( $settings ) ) {
			self::note_push_off();
			return;
		}

		$woo_sync_cats = isset( $settings['woo_category_ids'] ) && is_array( $settings['woo_category_ids'] ) ? $settings['woo_category_ids'] : array();
		$queue         = array();
		$taken         = array(); // Units this reduction took, per product whose stock is sent.
		$may_queue     = null;    // Asked once, at the first product on eBay (may_queue()).

		foreach ( $order->get_items() as $item ) {
			// Shipping, fee and tax rows are plain WC_Order_Item objects and
			// have no get_product().
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			// Its push has reached eBay already.
			if ( '' !== (string) $item->get_meta( '_tcgiant_ebay_push' ) ) {
				continue;
			}

			// What WooCommerce took off for this line. None: nothing to send.
			$sold = (int) $item->get_meta( '_reduced_stock' );
			if ( $sold <= 0 ) {
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

			// A product that does not track stock has no quantity to report.
			//
			// get_stock_quantity() returns null for an untracked product. That
			// null was once queued as the new quantity, read as 0 downstream,
			// and with auto-end-on-zero turned on - which is the default -
			// ENDED the eBay listing. Selling one on the website took the eBay
			// listing with it.
			if ( ! $product->managing_stock() ) {
				continue;
			}

			$target = $this->push_target( $product, false );

			// Not on eBay: nothing to say, and nothing to read.
			if ( is_wp_error( $target ) && 'not_linked' === $target->get_error_code() ) {
				continue;
			}

			// Not connected, and not just for a while: nothing is queued for
			// this sale. Nothing has been queued yet, so it ends here.
			if ( null === $may_queue ) {
				$may_queue = self::may_queue( $settings );
			}
			if ( ! $may_queue ) {
				return;
			}

			if ( is_wp_error( $target ) ) {
				self::log_not_pushed( (int) $product->get_id(), $target );
				continue;
			}

			$holder_id = (int) $target['product_id'];
			$queued    = (string) $item->get_meta( '_tcgiant_ebay_push_queued' );

			// Its push is on its way, or being tried again.
			if ( '' !== $queued && isset( self::pending_pushes( $holder_id )[ $queued ] ) ) {
				continue;
			}

			$holder    = $holder_id === (int) $product->get_id() ? $product : wc_get_product( $holder_id );
			$new_stock = $holder ? $holder->get_stock_quantity() : null;

			// Never queue an absent figure as though it were zero.
			if ( null === $new_stock || '' === $new_stock ) {
				continue;
			}

			// An auction is always one item: only running out is sent.
			if ( (int) $new_stock > 0 && $target['auction'] ) {
				continue;
			}

			$taken[ $holder_id ] = ( $taken[ $holder_id ] ?? 0 ) + $sold;
			$queue[]             = array( $item, $target, $sold, (int) $new_stock );
		}

		foreach ( $queue as $entry ) {
			list( $item, $target, $sold, $new_stock ) = $entry;

			$holder_id = (int) $target['product_id'];
			$token     = self::mark_push_pending( $holder_id, 'sale' );

			// Schedule background update to eBay.
			// We use background sync to avoid slowing down the customer checkout.
			// 'before' is the stock just before this order took its units: for
			// a listing with no record yet of the eBay sales WooCommerce has
			// heard of, what eBay shows below it is put down to eBay's own.
			as_enqueue_async_action( self::PUSH_HOOK, array( array(
				'product_id' => $holder_id,
				'reason'     => 'order',
				'qty'        => $sold,
				'order'      => (int) $order->get_id(),
				'line'       => (int) $item->get_id(),
				'token'      => $token,
				'item'       => $target['item_id'],
				'before'     => $new_stock + (int) $taken[ $holder_id ],
			) ), self::PUSH_GROUP );

			$item->update_meta_data( '_tcgiant_ebay_push_queued', $token );
			$item->save_meta_data();
		}
	}

	/**
	 * Mark an order line as having reached eBay.
	 *
	 * @param array $job The order push.
	 * @return void
	 */
	private static function mark_line_pushed( array $job ) {
		if ( empty( $job['order'] ) || empty( $job['line'] ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( (int) $job['order'] );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && (int) $item->get_id() === (int) $job['line'] ) {
				$item->update_meta_data( '_tcgiant_ebay_push', (string) time() );
				$item->save_meta_data();
				return;
			}
		}
	}

	// ───────────────────────────────────────────────────────────────────────────
	// Background stock update processor
	//
	// One push sends one product's stock to the listing the product itself
	// follows. Whether a listing has ended is read back from eBay with
	// GetItem, never from the wording or the code of an error; a push eBay
	// does not take is tried again for about 10 hours rather than recorded as
	// ended; and only an EndItem eBay accepted writes Ended.
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Hook: tcgiant_sync_push_stock.
	 *
	 * @param array $job product_id; reason - 'edit' (a stock change made in
	 *                   WooCommerce), 'order' (a website sale) or 'manual';
	 *                   item, the listing it was queued for; qty and before,
	 *                   the units a sale took and the stock before it; and
	 *                   attempt.
	 * @return void
	 */
	public function process_stock_push( $job ) {
		$job = self::push_job( $job );

		if ( null === $job ) {
			TCGiant_Sync_Logger::warning( 'A queued stock push named no product, so nothing was sent to eBay.' );
			return;
		}

		// A safety try (arm_safety()): nothing to do unless the try it stands
		// behind never finished.
		if ( isset( $job['safety'] ) ) {
			$job = self::safety_due( $job );
			if ( null === $job ) {
				return;
			}
		}

		// A change made from now on reads the stock after this one has: it is
		// another push's.
		delete_transient( 'tcgiant_stock_pending_' . $job['product_id'] );

		// The one push waiting for tomorrow for this product is this one.
		if ( ! empty( $job['tomorrow'] ) ) {
			delete_transient( 'tcgiant_push_tomorrow_' . $job['product_id'] );
		}

		$this->run_push( $job, true );
	}

	/**
	 * Send one product's stock to eBay now, in this request - the order
	 * screen's "Push to eBay". The same steps as a queued push, never tried
	 * again later, and sent whatever "Send website stock changes to eBay"
	 * says: it was asked for by hand.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param string     $reason  'manual'.
	 * @return array status ('success', 'skipped' or 'error') and message, in
	 *               words for the order screen.
	 */
	public function push_now( $product, $reason = 'manual' ) {
		$job = $product instanceof WC_Product ? self::push_job( array( 'product_id' => $product->get_id(), 'reason' => $reason ) ) : null;

		if ( null === $job ) {
			return self::push_outcome( 'error', 'Product not found' );
		}

		return $this->run_push( $job, false );
	}

	/**
	 * A queued push's arguments, checked.
	 *
	 * @param mixed $job As queued.
	 * @return array|null
	 */
	private static function push_job( $job ) {
		if ( ! is_array( $job ) || empty( $job['product_id'] ) || (int) $job['product_id'] < 1 ) {
			return null;
		}

		$reason = (string) ( $job['reason'] ?? 'edit' );

		$out = array(
			'product_id' => (int) $job['product_id'],
			'reason'     => in_array( $reason, array( 'edit', 'order', 'manual' ), true ) ? $reason : 'edit',
			'attempt'    => max( 1, (int) ( $job['attempt'] ?? 1 ) ),
		);

		foreach ( array( 'qty', 'order', 'line', 'before', 'pool_after', 'since', 'out' ) as $key ) {
			if ( isset( $job[ $key ] ) && is_numeric( $job[ $key ] ) ) {
				$out[ $key ] = max( 0, (int) $job[ $key ] );
			}
		}

		foreach ( array( 'token', 'item', 'refusal', 'safety', 'out_day' ) as $key ) {
			if ( isset( $job[ $key ] ) && is_scalar( $job[ $key ] ) && '' !== (string) $job[ $key ] ) {
				$out[ $key ] = (string) $job[ $key ];
			}
		}

		foreach ( array( 'ended_try', 'tomorrow', 'ebay_sale', 'own_queued', 'e518' ) as $key ) {
			if ( ! empty( $job[ $key ] ) ) {
				$out[ $key ] = 1;
			}
		}

		return $out;
	}

	/**
	 * What a push came to.
	 *
	 * @param string $status  success, skipped, error; or, inside a push,
	 *                        retry, tomorrow and failed (given up at once).
	 * @param string $message In words.
	 * @param array  $extra   error (WP_Error), refusal and ended_try, for a
	 *                        try again.
	 * @return array
	 */
	private static function push_outcome( $status, $message, array $extra = array() ) {
		return array( 'status' => $status, 'message' => $message ) + $extra;
	}

	/**
	 * Steps 1 to 7 of a push: what to send, sending it, and what eBay's answer
	 * means.
	 *
	 * @param array $job       From push_job().
	 * @param bool  $may_retry False for push_now().
	 * @return array See push_outcome().
	 */
	private function run_push( array $job, $may_retry ) {
		$product_id = $job['product_id'];

		// A staging copy sends nothing, and must leave nothing behind either:
		// no read spent on a change that cannot go, nothing recorded about a
		// listing it has not asked eBay about. Final - never tried again.
		$blocked = TCGiant_Sync_Environment::write_block();
		if ( $blocked ) {
			TCGiant_Sync_Environment::note_blocked( sprintf( 'Stock change for WC #%d', $product_id ), $blocked );
			self::push_done( $job );
			return self::push_outcome( 'skipped', $blocked->get_error_message() );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || 'trash' === get_post_status( $product_id ) ) {
			TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: the product is no longer there, so nothing was sent to eBay.', $product_id ) );
			self::push_done( $job );
			return self::push_outcome( 'skipped', 'Product no longer exists' );
		}

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );

		// The settings as the database holds them now - read from the row, not
		// this request's copy, so a change saved by another request since the
		// push was queued counts. Not for a push asked for by hand, which says
		// it was sent or why not.
		$row = 'manual' === $job['reason'] ? null : self::settings_row();

		// Switched off since it was queued: final as well.
		if ( null !== $row && ! self::pushes_enabled( $row ) ) {
			TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d not sent: "Send website stock changes to eBay?" is No.', $product_id ) );
			self::push_done( $job );
			return self::push_outcome( 'skipped', 'Sending stock changes to eBay is switched off' );
		}

		// No tokens stored, and no disconnect lately enough for a reconnect to
		// come in time: never connected, or away longer than the tries
		// (PUSH_RECONNECT_WINDOW). Each try would only fail for want of a
		// token, so it ends now, in one line, and is not listed as a failure.
		// A sale keeps its unsent mark, so that an import cannot bring the
		// sold unit back. Within the window the tries go on, and carry the
		// change past the reconnect.
		if ( null !== $row && ! self::connected( $row ) && ! self::disconnected_lately() ) {
			TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d not sent: this site is not connected to eBay. Connect it in Settings; the next stock change of the product is sent then.', $product_id ) );
			self::push_done( $job, false, 'order' === $job['reason'] );
			return self::push_outcome( 'skipped', 'Not connected to eBay' );
		}

		// Connected to another eBay account since this request began: its copy
		// of the settings still holds the old account's token, not yet due
		// perhaps, which the try would send. Dropped, so the try uses the
		// account the database holds.
		$mine = is_array( $settings ) ? (string) ( $settings['refresh_token'] ?? '' ) : '';
		if ( null !== $row && (string) ( $row['refresh_token'] ?? '' ) !== $mine ) {
			self::forget_settings_copy();
		}

		$target = $this->push_target( $product, true );
		if ( is_wp_error( $target ) ) {
			self::log_not_pushed( $product_id, $target );
			self::push_done( $job );
			return self::push_outcome( 'skipped', $target->get_error_message() );
		}

		// Sent as another product: a variation whose stock its parent keeps.
		if ( (int) $target['product_id'] !== $product_id ) {
			$product = wc_get_product( $target['product_id'] );
			if ( ! $product ) {
				self::push_done( $job );
				return self::push_outcome( 'skipped', 'Product no longer exists' );
			}
			$job['product_id'] = (int) $target['product_id'];
			$product_id        = $job['product_id'];
		}

		// The product has moved on to another listing since this was queued:
		// item 8 followed a relist. A stock change made in WooCommerce is not
		// sent: the move brought that listing's own figure in. A website sale
		// is: the unit has gone from the shelf whichever listing it was on, so
		// the new listing is told, from what it shows itself - never more than
		// it has, and with nothing worked out for the old one.
		if ( isset( $job['item'] ) && (string) $job['item'] !== (string) $target['item_id'] ) {
			if ( 'order' !== $job['reason'] ) {
				TCGiant_Sync_Logger::log( sprintf(
					'Stock push for WC #%d not sent: it was queued for eBay listing %s, and the product follows listing %s now, whose own figure came with the move.',
					$product_id,
					$job['item'],
					$target['item_id']
				) );
				self::push_done( $job, true );
				return self::push_outcome( 'skipped', 'The product follows another eBay listing now' );
			}

			TCGiant_Sync_Logger::log( sprintf(
				'Stock push for WC #%d: a website sale queued for eBay listing %s, and the product follows listing %s now; the sale is sent to that listing instead.',
				$product_id,
				$job['item'],
				$target['item_id']
			) );
			$job['item']     = (string) $target['item_id'];
			$job['relisted'] = 1;
			unset( $job['before'] );
		}

		// Untracked: get_stock_quantity() is null, which once read as 0 and
		// ended the listing of a product the shop still had.
		if ( ! $product->managing_stock() || null === $product->get_stock_quantity() ) {
			TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: its stock is not tracked in WooCommerce, so there is no quantity to send to eBay listing %s.', $product_id, $target['item_id'] ) );
			self::push_done( $job );
			return self::push_outcome( 'skipped', 'Stock not tracked in WooCommerce - nothing to send' );
		}

		$wc       = (int) $product->get_stock_quantity();
		$auto_end = ! isset( $settings['auto_end_zero_stock'] ) || ! empty( $settings['auto_end_zero_stock'] );

		// Ended, and its sales already in the stock: nothing a push sends can
		// reach it. Relist sells it again. A mark 3.27 left - it recorded a
		// failed EndItem as ended and settled - is not taken on trust: the
		// push reads the listing first, and a live listing has it cleared.
		if ( ! $target['auction']
			&& 'Ended' === (string) get_post_meta( $target['owner_id'], '_ebay_listing_status', true )
			&& self::listing_already_settled( $target['owner_id'], $target['item_id'] ) ) {
			if ( self::settle_trusted( $target['owner_id'] ) ) {
				TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: eBay listing %s has ended and its sales are in the stock, so nothing was sent. Relist it to sell it on eBay again.', $product_id, $target['item_id'] ) );
				self::push_done( $job );
				return self::push_outcome( 'skipped', 'Listing ended - relist it to sell on eBay' );
			}
			$job['check_ended'] = 1;
		}

		// A sale, a push asked for by hand, and anything going to 0 stop an
		// oversell, so they may go past the fleet's 80% ceiling. A raised or
		// lowered quantity from a hand edit or an import waits for the next
		// day when the ceiling is reached.
		$push = function () use ( $job, $target, $wc, $auto_end ) {
			if ( $target['pool'] ) {
				return $this->push_pool( $job, $target, $wc, $auto_end );
			}
			return $target['auction']
				? $this->push_auction( $job, $target, $wc, $auto_end )
				: $this->push_fixed_price( $job, $target, $wc, $auto_end );
		};

		// Past its last try - a safety try for a last try the host stopped:
		// given up, as the last try would have been. Nothing is sent.
		if ( (int) $job['attempt'] > count( self::PUSH_RETRY_DELAYS ) + 1 ) {
			return $this->give_up( $job, $target, self::push_outcome( 'error', 'stopped before it finished, on its last try' ), $wc );
		}

		// Waiting on eBay's 518: nothing is sent until the shared question
		// says it has lifted - one question an hour for every waiting push,
		// check and pass (3.28.0 release fix round 2). Past SETTLE_CHAIN_DAYS
		// it is sent, and given up if it meets the 518 again.
		if ( ! empty( $job['e518'] ) && $may_retry && class_exists( 'TCGiant_Sync_Importer' )
			&& time() - (int) ( $job['since'] ?? 0 ) < TCGiant_Sync_Importer::SETTLE_CHAIN_DAYS * DAY_IN_SECONDS
			&& ! TCGiant_Sync_Importer::ebay_limit_lifted() ) {
			$when = TCGiant_Sync_Importer::ebay_518_until();
			as_schedule_single_action( $when, self::PUSH_HOOK, array( self::push_job( $job ) ), self::PUSH_GROUP );
			self::hold_pending_until( $job, $when );
			TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d to eBay listing %s waits for eBay\'s call limit (518) to lift: asked again at %s UTC, not counted as a try.', $product_id, $target['item_id'], gmdate( 'H:i', $when ) ) );
			return self::push_outcome( 'retry', 'eBay\'s call limit (518) has not lifted yet' );
		}

		// A push the host stops part way is tried again, and says so: before
		// 3.28.0 it left nothing behind to finish it, and its order line
		// marked as sent. A try the host ends with nothing PHP can report -
		// the process killed, a request time limit outside PHP - has its
		// safety try, queued before eBay is called.
		$safety = $may_retry ? self::arm_safety( $job ) : null;

		TCGiant_Sync_Runtime::watch( self::PUSH_JOB, function ( $message ) use ( $job, $target, $may_retry, $wc, $safety ) {
			$this->push_died( $job, $target, $may_retry, $wc, $message, $safety );
		} );

		$beating        = self::$beat_run;
		self::$beat_run = $safety ? array( (int) $job['product_id'], (string) $safety['run'] ) : null;

		try {
			$result = ( self::is_priority( $job, $wc ) )
				? TCGiant_Sync_API::instance()->with_stock_priority( $push )
				: $push();
		} catch ( Throwable $e ) {
			self::$beat_run = $beating;
			TCGiant_Sync_Runtime::unwatch();
			$this->push_died( $job, $target, $may_retry, $wc, $e->getMessage(), $safety );
			throw $e;
		}

		self::$beat_run = $beating;
		TCGiant_Sync_Runtime::unwatch();

		// Tried again later: the safety try is handed the try again
		// (push_again_later()). Anything else is final, and it is not needed.
		if ( in_array( $result['status'], array( 'retry', 'tomorrow' ), true ) ) {
			return $this->push_again_later( $job, $target, $result, $may_retry, $wc, $safety );
		}

		self::disarm_safety( $job, $safety );

		if ( 'failed' === $result['status'] ) {
			return $this->give_up( $job, $target, $result, $wc );
		}

		self::push_done( $job );

		// eBay took this one: what an earlier push of the product to the
		// same listing could not get there is now out of date, and leaves
		// Stock Review's list.
		if ( 'success' === $result['status'] ) {
			self::forget_push_failure( (int) $job['product_id'], (string) $target['item_id'] );
		}

		return $result;
	}

	/**
	 * Whether a push goes past the fleet's 80% ceiling: a sale, a push by
	 * hand, or stock going to 0.
	 *
	 * @param array $job The push.
	 * @param int   $wc  WooCommerce's stock.
	 * @return bool
	 */
	private static function is_priority( array $job, $wc ) {
		return 'edit' !== $job['reason'] || (int) $wc <= 0 || ! empty( $job['ebay_sale'] );
	}

	/**
	 * Queue a safety try before a try calls eBay: the next try, at the time
	 * this one is taken for ended by the host (PUSH_ENDED_AFTER) - never
	 * sooner, so it never runs beside a try still going. The try marks itself
	 * as running; finishing in any way it knows of - sent, given up, queued
	 * again, stopped with an error PHP reports - it takes the safety try off
	 * the queue and the mark away (disarm_safety()), or hands it the try
	 * again (schedule_next()). A request the host ends with nothing to
	 * report leaves both, and the safety try finishes the push
	 * (safety_due()).
	 *
	 * @param array $job The push.
	 * @return array run (the mark), args (the safety try's), when (its time).
	 */
	private static function arm_safety( array $job ) {
		$attempt = (int) $job['attempt'];
		$when    = time() + self::PUSH_ENDED_AFTER;
		$run     = uniqid( 'run-', true );

		$next            = (array) self::push_job( $job );
		$next['attempt'] = $attempt + 1;
		$next['safety']  = $run;
		unset( $next['tomorrow'], $next['refusal'], $next['ended_try'] );

		// The try's number goes with its mark: a pool waiting on this push
		// counts its tries by it (own_push_on_its_way()).
		$runs         = self::runs( $job['product_id'] );
		$runs[ $run ] = array( 'started' => time(), 'when' => $when, 'attempt' => $attempt );
		update_post_meta( (int) $job['product_id'], self::PUSH_RUNNING_KEY, $runs );

		as_schedule_single_action( $when, self::PUSH_HOOK, array( $next ), self::PUSH_GROUP );

		return array( 'run' => $run, 'args' => array( $next ), 'when' => $when );
	}

	/**
	 * The try finished, in a way it knows of: its safety try is not needed.
	 *
	 * @param array      $job    The push.
	 * @param array|null $safety From arm_safety().
	 * @return void
	 */
	private static function disarm_safety( array $job, $safety ) {
		if ( ! is_array( $safety ) ) {
			return;
		}

		self::end_run( $job['product_id'], $safety['run'] );

		// Action Scheduler 3.0 and later, which WooCommerce brings. Without it
		// the safety try still runs, finds its try finished, and does nothing.
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::PUSH_HOOK, $safety['args'], self::PUSH_GROUP );
		}
	}

	/**
	 * A safety try has come round.
	 *
	 * - The try it stands behind finished: nothing to do.
	 * - Sending switched off since, or a staging copy (push_stopped()): run
	 *   now, to stop there for good - nothing is put off.
	 * - It was handed that push's try again (schedule_next()): this is it,
	 *   now, or put off to its time.
	 * - Still within the time a try can run, and a minute (PUSH_ENDED_AFTER),
	 *   for every try alike: looked at again once it is past it, so the two
	 *   never send together. A safety try runs early only when it is run by
	 *   hand (Tools > Scheduled Actions) or by a second queue runner.
	 * - Past it: the host ended that try, and this is the next one.
	 *
	 * @param array $job The safety try, from push_job().
	 * @return array|null The push to run, or null.
	 */
	private static function safety_due( array $job ) {
		$product_id = (int) $job['product_id'];
		$run        = (string) $job['safety'];
		$runs       = self::runs( $product_id );

		if ( ! isset( $runs[ $run ] ) ) {
			return null;
		}

		// Switched off since, or a staging copy: nothing is put off or looked
		// at again. The push runs now, and stops there for good, saying why.
		if ( '' !== self::push_stopped() ) {
			self::end_run( $product_id, $run );
			unset( $job['safety'] );
			return $job;
		}

		if ( isset( $runs[ $run ]['next'] ) ) {
			self::end_run( $product_id, $run );
			// Due by this safety try's own time: it is the try again. Later (a
			// longer wait, or tomorrow): put off to then.
			if ( (int) $runs[ $run ]['at'] > (int) ( $runs[ $run ]['when'] ?? 0 ) + 2 * MINUTE_IN_SECONDS ) {
				as_schedule_single_action( (int) $runs[ $run ]['at'], self::PUSH_HOOK, array( $runs[ $run ]['next'] ), self::PUSH_GROUP );
				return null;
			}
			return self::push_job( $runs[ $run ]['next'] );
		}

		// The try's last sign of life: its start, or the last eBay call it
		// began (beat()). One Trading call ends within a few minutes, so a
		// try still going never looks ended (fix round 4).
		$alive = max( (int) $runs[ $run ]['started'], (int) ( $runs[ $run ]['beat'] ?? 0 ) );
		if ( time() < $alive + self::PUSH_ENDED_AFTER ) {
			as_schedule_single_action( $alive + self::PUSH_ENDED_AFTER, self::PUSH_HOOK, array( $job ), self::PUSH_GROUP );
			return null;
		}

		self::end_run( $product_id, $run );
		unset( $job['safety'] );

		TCGiant_Sync_Logger::error( sprintf(
			'Stock push for WC #%d was stopped before it finished, with nothing PHP could report - the host ended the request. Tried again now (try %d of %d).',
			$product_id,
			(int) $job['attempt'],
			count( self::PUSH_RETRY_DELAYS ) + 1
		) );

		return $job;
	}

	/**
	 * The try under way is alive: its running mark notes the time, just
	 * before an eBay call. Its safety try takes it for ended only once that
	 * is PUSH_ENDED_AFTER ago (safety_due()).
	 *
	 * @return void
	 */
	private static function beat() {
		if ( ! self::$beat_run ) {
			return;
		}

		list( $product_id, $run ) = self::$beat_run;
		$runs                     = self::runs( $product_id );
		if ( isset( $runs[ $run ] ) && ! isset( $runs[ $run ]['next'] ) ) {
			$runs[ $run ]['beat'] = time();
			update_post_meta( (int) $product_id, self::PUSH_RUNNING_KEY, $runs );
		}
	}

	/**
	 * A product's tries marked as running - or handed on to their safety
	 * try - for two days at most, or until a try again handed over is due.
	 *
	 * @param int $product_id Product.
	 * @return array run => started, attempt, and next and at for a try again.
	 */
	private static function runs( $product_id ) {
		$runs = get_post_meta( (int) $product_id, self::PUSH_RUNNING_KEY, true );
		$out  = array();

		foreach ( is_array( $runs ) ? $runs : array() as $run => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['started'] ) ) {
				continue;
			}
			$until = max( (int) $entry['started'], (int) ( $entry['at'] ?? 0 ) ) + 2 * DAY_IN_SECONDS;
			if ( $until > time() ) {
				$out[ (string) $run ] = $entry;
			}
		}

		return $out;
	}

	/**
	 * Take a try's running mark away.
	 *
	 * @param int    $product_id Product.
	 * @param string $run        The mark.
	 * @return void
	 */
	private static function end_run( $product_id, $run ) {
		$runs = self::runs( $product_id );

		if ( isset( $runs[ $run ] ) ) {
			unset( $runs[ $run ] );
			if ( $runs ) {
				update_post_meta( (int) $product_id, self::PUSH_RUNNING_KEY, $runs );
			} else {
				delete_post_meta( (int) $product_id, self::PUSH_RUNNING_KEY );
			}
		}
	}

	/**
	 * eBay's ListingType for a listing, from an answer the plugin already
	 * holds - an import, the hourly check, a settle, a push's GetItem: kept
	 * with the listing it is for, so a push need not ask again before an
	 * EndItem (type_confirmed_fixed()). Only for the listing the product
	 * follows now.
	 *
	 * @param int    $product_id Product holding the link.
	 * @param string $item_id    The listing the answer is about.
	 * @param mixed  $type       eBay's ListingType.
	 * @return void
	 */
	public static function note_listing_type( $product_id, $item_id, $type ) {
		$type    = TCGiant_Sync_API::scalar_value( $type );
		$item_id = (string) $item_id;

		if ( '' === $type || '' === $item_id || (string) get_post_meta( (int) $product_id, '_ebay_item_id', true ) !== $item_id ) {
			return;
		}

		update_post_meta( (int) $product_id, '_ebay_listing_type', $type );
		update_post_meta( (int) $product_id, self::TYPE_ITEM_KEY, $item_id );

		if ( 'Chinese' === $type ) {
			self::auctions_seen();
		}
	}

	/**
	 * Whether the shop may have an eBay auction among its products: "Import
	 * eBay auctions" is on, or a product is recorded as on one. Kept for a
	 * day, and set at once when an auction is imported or pushed.
	 *
	 * @return bool
	 */
	public static function shop_may_have_auctions() {
		global $wpdb;

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		if ( is_array( $settings ) && '1' === (string) ( $settings['import_auctions'] ?? '0' ) ) {
			return true;
		}

		$known = get_transient( self::AUCTIONS_TRANSIENT );
		if ( 'yes' === $known || 'no' === $known ) {
			return 'yes' === $known;
		}

		$any = $wpdb->get_var( "SELECT pm.post_id FROM {$wpdb->postmeta} pm WHERE pm.meta_key = '_ebay_listing_type' AND pm.meta_value = 'Chinese' LIMIT 1" );
		set_transient( self::AUCTIONS_TRANSIENT, $any ? 'yes' : 'no', DAY_IN_SECONDS );

		return (bool) $any;
	}

	/**
	 * An auction has just been imported, pushed or read: the shop has one.
	 *
	 * @return void
	 */
	public static function auctions_seen() {
		set_transient( self::AUCTIONS_TRANSIENT, 'yes', DAY_IN_SECONDS );
	}

	/**
	 * Whether a product's Ended-and-settled marks are 3.28's own - written
	 * from an EndItem eBay took, or from eBay's final figures - rather than
	 * 3.27's, which a failed EndItem wrote too.
	 *
	 * @param int $owner_id Product holding the link.
	 * @return bool
	 */
	private static function settle_trusted( $owner_id ) {
		return '' !== (string) get_post_meta( (int) $owner_id, '_ebay_end_reason', true )
			|| '' !== (string) get_post_meta( (int) $owner_id, '_ebay_quantity_listed', true );
	}

	/**
	 * Whether the listing is known to be fixed-price: eBay said so, for this
	 * listing - an import, a push, or GetItem. A type written by the 3.x
	 * backfill or the listings table is a guess, and an auction ended with
	 * EndItem loses its bids.
	 *
	 * @param int    $owner_id Product holding the link.
	 * @param string $item_id  The listing.
	 * @return bool
	 */
	private static function type_confirmed_fixed( $owner_id, $item_id ) {
		return self::type_says_fixed( $owner_id )
			&& (string) $item_id === (string) get_post_meta( (int) $owner_id, self::TYPE_ITEM_KEY, true );
	}

	/**
	 * Whether the product records its listing as fixed-price, by eBay's word
	 * or a default older code wrote.
	 *
	 * @param int $owner_id Product holding the link.
	 * @return bool
	 */
	private static function type_says_fixed( $owner_id ) {
		return in_array( (string) get_post_meta( (int) $owner_id, '_ebay_listing_type', true ), array( 'FixedPriceItem', 'StoresFixedPrice' ), true );
	}

	/**
	 * The host stopped a push part way - a PHP time or memory limit, or an
	 * error thrown in another plugin's hook. Tried again later, as a refusal
	 * would be; it was not sent, as far as anything here knows.
	 *
	 * @param array      $job       The push.
	 * @param array      $target    From push_target().
	 * @param bool       $may_retry False for push_now().
	 * @param int        $wc        WooCommerce's stock.
	 * @param string     $message   What stopped it.
	 * @param array|null $safety    From arm_safety().
	 * @return void
	 */
	private function push_died( array $job, array $target, $may_retry, $wc, $message, $safety = null ) {
		TCGiant_Sync_Logger::error( sprintf( 'Stock push for WC #%d to eBay listing %s was stopped before it finished: %s', $job['product_id'], $target['item_id'], $message ) );

		if ( $may_retry ) {
			$this->push_again_later( $job, $target, self::push_outcome( 'retry', 'stopped before it finished' ), true, $wc, $safety );
		} else {
			self::disarm_safety( $job, $safety );
			self::push_done( $job );
		}
	}

	/**
	 * Read the listing first, and work out what WooCommerce's figure is once
	 * the eBay sales it has not heard of are taken off.
	 *
	 * Those sales are worked out by unseen(): QuantitySold since the figure
	 * recorded for the listing, any fall since what a landed push left eBay
	 * showing, or - with neither, for a sale - what eBay shows below the
	 * stock before the sale. They are taken off WooCommerce's stock - only
	 * lowered, origin-guarded - and the record moves on to what eBay says
	 * now, before anything is sent: a try again, or the next push, then does
	 * not take them off twice.
	 *
	 * A stock change made in WooCommerce, or a push by hand, for a listing
	 * with nothing on record has nothing to go on: WooCommerce's figure is
	 * taken as it is, and the record is made once it lands (stock_sent(),
	 * record_once_landed()). A sale moved on to a relist by item 8 starts
	 * that listing's record from what it shows.
	 *
	 * @param array  $job        The push.
	 * @param array  $read       From read_listing().
	 * @param int    $wc         WooCommerce's stock.
	 * @param string $ledger_sku '' for the listing, eBay's SKU for a variation.
	 * @return int|null WooCommerce's stock now, or null when it could not be
	 *                  lowered.
	 */
	private function take_off_unseen( array $job, array $read, $wc, $ledger_sku ) {
		$product_id = $job['product_id'];
		$item_id    = (string) $read['item_id'];
		$sold       = (int) $read['sold'];
		$before     = 'order' === $job['reason'] && isset( $job['before'] ) ? (int) $job['before'] : null;

		$unseen = ! empty( $job['relisted'] ) && null === self::own_record( $product_id, $item_id, $ledger_sku )
			? 0
			: self::unseen( $product_id, $item_id, $ledger_sku, (int) $read['avail'], $sold, $before );

		if ( null === $unseen ) {
			return (int) $wc;
		}

		$record = function () use ( $product_id, $item_id, $ledger_sku, $sold ) {
			self::record_sold_seen( $product_id, $item_id, $ledger_sku, $sold );
		};

		if ( $unseen > 0 ) {
			// The record written with the stock (release fix round 4).
			$now = self::lower_stock_to( $product_id, (int) $wc - $unseen, $record );
			if ( null === $now ) {
				TCGiant_Sync_Logger::warning( sprintf( 'Stock push for WC #%d: eBay sold %d that WooCommerce has not taken off, but its stock could not be lowered - something else on this site may be holding it. Tried again later.', $product_id, $unseen ) );
				return null;
			}
			TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: %d sale(s) on eBay listing %s had not reached WooCommerce yet, so its stock was lowered from %d to %d first.', $product_id, $unseen, $item_id, (int) $wc, $now ) );
			$wc = $now;
		}

		$record();

		return (int) $wc;
	}

	/**
	 * For a push with nothing on record about the listing: once eBay shows
	 * WooCommerce's own figure, the two agree from eBay's QuantitySold then
	 * on, and that is recorded - by landed().
	 *
	 * @param array  $job        The push, as it goes.
	 * @param string $item_id    The listing.
	 * @param string $ledger_sku '' for the listing, eBay's SKU for a variation.
	 * @param int    $sold       eBay's QuantitySold, as just read.
	 * @param int    $want       What eBay is to show.
	 * @param int    $wc         WooCommerce's stock.
	 * @return array The push, carrying the record to make.
	 */
	private static function record_once_landed( array $job, $item_id, $ledger_sku, $sold, $want, $wc ) {
		unset( $job['record'] );

		if ( (int) $want === (int) $wc && null === self::sold_seen( $job['product_id'], $item_id, $ledger_sku ) ) {
			$job['record'] = array( 'item' => (string) $item_id, 'sku' => (string) $ledger_sku, 'sold' => (int) $sold );
		}

		return $job;
	}

	/**
	 * A fixed-price listing, or one variation of one.
	 *
	 * @param array $job      The push.
	 * @param array $target   From push_target().
	 * @param int   $wc       WooCommerce's stock now.
	 * @param bool  $auto_end "Auto-end listing when stock reaches 0".
	 * @return array See push_outcome().
	 */
	private function push_fixed_price( array $job, array $target, $wc, $auto_end ) {
		$product_id = $job['product_id'];
		$item_id    = $target['item_id'];
		$sku        = $target['var_sku'];
		$reason     = $job['reason'];

		// A stock change made in WooCommerce says what WooCommerce holds, and
		// is sent as it is without asking eBay first. Everything else reads
		// first: a sale, a push by hand, a try again, a mark 3.27 left, and an
		// EndItem for a listing not known to be fixed-price (an auction ended
		// that way loses its bids) - unless the product says fixed-price and
		// the shop has no auction at all, where asking would only double the
		// calls of a CSV that sets thousands to 0.
		$reads_first = 'edit' !== $reason
			|| (int) $job['attempt'] > 1
			|| ! empty( $job['check_ended'] )
			|| ( (int) $wc <= 0 && ! $target['variation'] && $auto_end && ! self::type_confirmed_fixed( $target['owner_id'], $item_id )
				&& ( ! self::type_says_fixed( $target['owner_id'] ) || self::shop_may_have_auctions() ) );

		if ( $reads_first ) {
			$read = $this->read_listing( $target, $job );

			if ( isset( $read['stop'] ) ) {
				return $read['stop'];
			}

			if ( isset( $read['error'] ) ) {
				// eBay's answer never said how many: on the last try the shop's
				// own figure is sent rather than nothing at all.
				if ( 'no_quantity' === $read['error']->get_error_code() && (int) $job['attempt'] > count( self::PUSH_RETRY_DELAYS ) ) {
					$want = max( 0, (int) $wc );
				} else {
					return self::read_failed( $read['error'] );
				}
			} else {
				// An auction, whatever the product says: its own rule.
				if ( ! empty( $read['auction'] ) ) {
					return $this->push_auction( $job, $target, $wc, $auto_end, $read['item'] );
				}

				$avail      = (int) $read['avail'];
				$sku        = $read['sku'];
				$job['pre'] = array( $avail, (int) $read['sold'] );
				$wc         = $this->take_off_unseen( $job, $read, $wc, $target['variation'] ? $sku : '' );

				if ( null === $wc ) {
					return self::push_outcome( 'retry', 'WooCommerce\'s stock could not be lowered' );
				}

				// A sale, or a push by hand, never puts a unit back on eBay; a
				// stock change made in WooCommerce sends what it says.
				$want = 'edit' === $reason ? max( 0, $wc ) : max( 0, min( $avail, $wc ) );
				$job  = self::record_once_landed( $job, $item_id, $target['variation'] ? $sku : '', (int) $read['sold'], $want, $wc );

				if ( $want === $avail ) {
					TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: %s already shows %d available, so nothing was sent.', $product_id, self::listing_words( $target, $sku ), $avail ) );
					self::landed( $job );
					return self::push_outcome( 'manual' === $reason ? 'skipped' : 'success', sprintf( 'eBay already shows %d', $avail ) );
				}
			}
		} else {
			$want = max( 0, (int) $wc );
		}

		$resent = false;

		while ( true ) {
			$sent   = $this->send_stock( $item_id, $want, $sku, $target['variation'], $auto_end );
			$result = $sent['result'];

			if ( ! is_wp_error( $result ) ) {
				// Taken for a SKU other than the one the variation had here -
				// eBay's, found on the listing or sent once more: that is the
				// SKU from now on, and the next change goes to it first time.
				if ( $target['variation'] && (string) $sku !== (string) $target['var_sku'] ) {
					update_post_meta( $product_id, '_ebay_variation_sku', $sku );
				}
				return $this->stock_sent( $job, $target, $sent['call'], $want, $sku, $result );
			}

			$failed = $this->stock_refused( $job, $target, $sent['call'], $want, $sku, $result, $auto_end, $resent );

			// Sent again once, to the SKU eBay has for it.
			if ( isset( $failed['resend'] ) ) {
				$sku    = $failed['resend'];
				$resent = true;
				continue;
			}

			return $failed;
		}
	}

	/**
	 * A read that failed: the ceiling's refusal waits for tomorrow, anything
	 * else is tried again.
	 *
	 * @param WP_Error $error The failure.
	 * @return array See push_outcome().
	 */
	private static function read_failed( $error ) {
		$data = $error->get_error_data();
		if ( 'rate_limited' === $error->get_error_code() && is_array( $data ) && ! empty( $data['daily_budget'] ) ) {
			return self::push_outcome( 'tomorrow', $error->get_error_message(), array( 'error' => $error ) );
		}

		return self::push_outcome( 'retry', $error->get_error_message(), array( 'error' => $error ) );
	}

	/**
	 * One call to eBay: ReviseInventoryStatus, or EndItem for a listing with
	 * no variations going to 0 with auto-end on.
	 *
	 * Never EndItem for a variation: one variation selling out does not end
	 * every other one.
	 *
	 * @param string $item_id   Listing.
	 * @param int    $want      Available quantity to set.
	 * @param string $sku       eBay's SKU for the variation, or ''.
	 * @param bool   $variation Whether a variation is meant.
	 * @param bool   $auto_end  "Auto-end listing when stock reaches 0".
	 * @return array call, result.
	 */
	private function send_stock( $item_id, $want, $sku, $variation, $auto_end ) {
		$api = TCGiant_Sync_API::instance();

		self::beat();

		if ( 0 === (int) $want && ! $variation && $auto_end ) {
			return array( 'call' => 'EndItem', 'result' => $api->end_item( $item_id ) );
		}

		// With auto-end off, 0 is sent as 0: with eBay's Out-of-Stock control
		// on, the listing stays live and hidden until restocked.
		return array( 'call' => 'ReviseInventoryStatus', 'result' => $api->revise_inventory_status( $item_id, $want, $sku ) );
	}

	/**
	 * eBay and WooCommerce agree for this push: an order line is marked as
	 * sent, a sale that never reached eBay before is held back no longer, and
	 * a listing with nothing on record has eBay's QuantitySold recorded (see
	 * record_once_landed()).
	 *
	 * @param array $job The push.
	 * @return void
	 */
	private static function landed( array $job ) {
		if ( 'order' === $job['reason'] ) {
			self::mark_line_pushed( $job );
		}
		if ( isset( $job['record'] ) ) {
			self::record_sold_seen( $job['product_id'], $job['record']['item'], $job['record']['sku'], $job['record']['sold'] );
		}
		self::forget_unsent_sale( $job['product_id'] );

		// A variation's own push the pool's 0 waited on (queue_own_push()):
		// landed, the pool's 0 is tried once more, unless a push of the pool
		// is still on its way to do it (fix round 5).
		if ( ! empty( $job['pool_after'] ) && ! self::live_push( (int) $job['pool_after'] ) ) {
			self::queue_after_ebay_sale( (int) $job['pool_after'] );
		}
	}

	/**
	 * eBay took the change.
	 *
	 * @param array  $job    The push.
	 * @param array  $target From push_target().
	 * @param string $call   EndItem or ReviseInventoryStatus.
	 * @param int    $want   What was sent.
	 * @param string $sku    The SKU it was sent for.
	 * @param array  $result eBay's answer.
	 * @return array See push_outcome().
	 */
	private function stock_sent( array $job, array $target, $call, $want, $sku, $result ) {
		$product_id = $job['product_id'];

		self::landed( $job );

		// The only place a push writes Ended: eBay said the listing ended.
		if ( 'EndItem' === $call ) {
			self::record_stock_end( $target['owner_id'], $target['item_id'], $result );
			TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: eBay listing %s ended - its stock reached 0.', $product_id, $target['item_id'] ), 'success' );
			return self::push_outcome( 'success', 'eBay listing ended (sold out)' );
		}

		TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: %s set to %d available.', $product_id, self::listing_words( $target, $sku ), $want ), 'success' );

		// eBay takes a quantity only for a fixed-price listing.
		self::confirm_fixed_price( $target );

		// eBay's answer gives the listing's Quantity, available and sold
		// together: what it has sold is that less what was just sent. That is
		// the record from now on - with no call to ask. Without it (an answer
		// that does not say, or a change read back after a refusal), what eBay
		// shows now is noted where nothing better is kept.
		$ledger_sku = $target['variation'] ? (string) $sku : '';
		$sold       = is_array( $result ) ? self::revise_answer_sold( $result, $target['item_id'], $ledger_sku, $want ) : null;

		if ( null === $sold ) {
			self::record_sent( $product_id, $target['item_id'], $ledger_sku, $want );
			return self::push_outcome( 'success', sprintf( 'eBay stock set to %d', $want ) );
		}

		$seen = self::sold_seen( $product_id, $target['item_id'], $ledger_sku );

		// eBay sold some since the record, which WooCommerce has not taken
		// off - and this change, worked out without them, has just put them
		// back on eBay. Taken off now, and eBay told again: one more call, only
		// when it happens. A hand edit, or a refund or cancel putting stock
		// back, used to write over an eBay sale this way.
		if ( null !== $seen && $sold > $seen && empty( $job['corrected'] ) ) {
			return $this->correct_unseen( $job, $target, $want, $sku, $sold - $seen, $sold );
		}

		if ( null !== $seen && $sold < $seen ) {
			TCGiant_Sync_Logger::warning( sprintf( 'Stock push for WC #%d: eBay says %s has sold %d, fewer than the %d recorded. A cancelled eBay sale does that; if none was cancelled, check the stock in Seller Hub.', $product_id, self::listing_words( $target, $sku ), $sold, $seen ) );
		}

		self::record_sold_seen( $product_id, $target['item_id'], $ledger_sku, $sold );

		return self::push_outcome( 'success', sprintf( 'eBay stock set to %d', $want ) );
	}

	/**
	 * eBay's revise answer shows sales since the record: they are taken off
	 * WooCommerce's stock, the record moves on to them, and eBay is sent the
	 * figure once more without them.
	 *
	 * @param array  $job    The push.
	 * @param array  $target From push_target().
	 * @param int    $want   What was sent.
	 * @param string $sku    The SKU it was sent for.
	 * @param int    $unseen eBay's sales WooCommerce had not taken off.
	 * @param int    $sold   eBay's QuantitySold, from its answer.
	 * @return array See push_outcome().
	 */
	private function correct_unseen( array $job, array $target, $want, $sku, $unseen, $sold ) {
		$product_id = $job['product_id'];
		$ledger_sku = $target['variation'] ? (string) $sku : '';
		$product    = wc_get_product( $product_id );
		$wc         = $product && $product->managing_stock() ? $product->get_stock_quantity() : null;

		if ( null === $wc ) {
			self::record_sold_seen( $product_id, $target['item_id'], $ledger_sku, $sold );
			return self::push_outcome( 'success', sprintf( 'eBay stock set to %d', $want ) );
		}

		$item_id = (string) $target['item_id'];
		$record  = function () use ( $product_id, $item_id, $ledger_sku, $sold ) {
			self::record_sold_seen( $product_id, $item_id, $ledger_sku, $sold );
		};
		// The record written with the stock (release fix round 4).
		$now = self::lower_stock_to( $product_id, (int) $wc - (int) $unseen, $record );
		if ( null === $now ) {
			TCGiant_Sync_Logger::warning( sprintf( 'Stock push for WC #%d: eBay sold %d that WooCommerce has not taken off, but its stock could not be lowered - something else on this site may be holding it. Tried again later.', $product_id, $unseen ) );
			return self::push_outcome( 'retry', 'WooCommerce\'s stock could not be lowered' );
		}

		$record();

		$again = 'edit' === $job['reason'] ? max( 0, (int) $now ) : max( 0, min( (int) $want, (int) $now ) );

		TCGiant_Sync_Logger::log( sprintf(
			'Stock push for WC #%d: eBay had sold %d on %s that WooCommerce had not heard of, and the change just sent put them back. WooCommerce lowered from %d to %d; eBay sent %d.',
			$product_id,
			$unseen,
			self::listing_words( $target, $sku ),
			(int) $wc,
			(int) $now,
			$again
		) );

		if ( $again === (int) $want ) {
			return self::push_outcome( 'success', sprintf( 'eBay stock set to %d', $want ) );
		}

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$auto_end = ! isset( $settings['auto_end_zero_stock'] ) || ! empty( $settings['auto_end_zero_stock'] );
		$sent     = $this->send_stock( $target['item_id'], $again, $sku, $target['variation'], $auto_end );

		if ( ! is_wp_error( $sent['result'] ) ) {
			$job['corrected'] = 1;
			return $this->stock_sent( $job, $target, $sent['call'], $again, $sku, $sent['result'] );
		}

		if ( 'staging_blocked' === $sent['result']->get_error_code() ) {
			return self::push_outcome( 'skipped', $sent['result']->get_error_message() );
		}

		// Not taken: a try again reads eBay and sends it then - the sales are
		// off WooCommerce and on the record, so nothing is taken off twice.
		return self::push_outcome( 'retry', $sent['result']->get_error_message(), array( 'error' => $sent['result'] ) );
	}

	/**
	 * eBay's QuantitySold for one listing or variation, from its answer to a
	 * ReviseInventoryStatus: the Quantity it gives back is available and sold
	 * together (eBay's documentation, verified for the design).
	 *
	 * @param array  $result eBay's answer.
	 * @param string $item_id The listing.
	 * @param string $sku    eBay's variation SKU, or '' for the listing.
	 * @param int    $want   The available quantity sent.
	 * @return int|null Null when the answer does not say.
	 */
	private static function revise_answer_sold( array $result, $item_id, $sku, $want ) {
		$rows = $result['InventoryStatus'] ?? null;
		if ( ! is_array( $rows ) ) {
			return null;
		}
		if ( isset( $rows['ItemID'] ) || isset( $rows['Quantity'] ) ) {
			$rows = array( $rows );
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || TCGiant_Sync_API::scalar_value( $row['ItemID'] ?? '' ) !== (string) $item_id ) {
				continue;
			}
			if ( '' !== (string) $sku && TCGiant_Sync_API::scalar_value( $row['SKU'] ?? '' ) !== (string) $sku ) {
				continue;
			}
			$quantity = TCGiant_Sync_API::scalar_value( $row['Quantity'] ?? '' );
			return is_numeric( $quantity ) ? max( 0, (int) $quantity - max( 0, (int) $want ) ) : null;
		}

		return null;
	}

	/**
	 * eBay took a quantity for the listing: it is a fixed-price one (eBay's
	 * ReviseInventoryStatus is for nothing else), and the product knows it
	 * for this listing from now on - no read before an EndItem.
	 *
	 * Only in a shop with no auction. That eBay never takes a quantity for an
	 * auction is its documentation, not something seen; where an auction
	 * could be among the products, only eBay's own word for the type - a
	 * GetItem, a listing page - confirms it (note_listing_type()).
	 *
	 * @param array $target From push_target().
	 * @return void
	 */
	private static function confirm_fixed_price( array $target ) {
		$owner_id = (int) $target['owner_id'];

		if ( self::shop_may_have_auctions() ) {
			return;
		}

		if ( '' === (string) get_post_meta( $owner_id, '_ebay_listing_type', true ) ) {
			update_post_meta( $owner_id, '_ebay_listing_type', 'FixedPriceItem' );
		} elseif ( ! self::type_says_fixed( $owner_id ) ) {
			return;
		}

		update_post_meta( $owner_id, self::TYPE_ITEM_KEY, (string) $target['item_id'] );
	}

	/**
	 * eBay did not take the change. What the listing shows now decides what
	 * that meant - never the error's code or its words, which differ by call
	 * and by eBay site.
	 *
	 * @param array    $job      The push.
	 * @param array    $target   From push_target().
	 * @param string   $call     The call eBay refused.
	 * @param int      $want     What was sent.
	 * @param string   $sku      The SKU it was sent for.
	 * @param WP_Error $error    eBay's refusal.
	 * @param bool     $auto_end "Auto-end listing when stock reaches 0".
	 * @param bool     $resent   Whether this was already sent again once.
	 * @return array See push_outcome(), or resend => the SKU to send to.
	 */
	private function stock_refused( array $job, array $target, $call, $want, $sku, $error, $auto_end, $resent ) {
		$product_id = $job['product_id'];
		$item_id    = $target['item_id'];
		$again      = array( 'error' => $error, 'ended_try' => 'EndItem' === $call );

		// Refused before it was sent, and logged where it was refused.
		if ( 'staging_blocked' === $error->get_error_code() ) {
			return self::push_outcome( 'skipped', $error->get_error_message() );
		}

		// The plugin's own ceiling, which a sale and anything going to 0 go
		// past: this is a hand edit or an import, and it waits for tomorrow.
		$data = $error->get_error_data();
		if ( 'rate_limited' === $error->get_error_code() && is_array( $data ) && ! empty( $data['daily_budget'] ) ) {
			return self::push_outcome( 'tomorrow', $error->get_error_message(), $again );
		}

		// Asked past the ceiling only for a push that stops an oversell; a
		// raised or lowered quantity's check waits like the change itself.
		$read_it = function () use ( $sku, $target, $job ) {
			return $this->read_listing( array( 'var_sku' => $sku ) + $target, $job );
		};

		// Out of calls, no token, a timeout: eBay has said nothing.
		if ( TCGiant_Sync_API::is_transient_error( $error ) ) {
			return $this->check_unanswered( $job, $target, $call, $want, $sku, $error, $read_it ) ?? self::push_outcome( 'retry', $error->get_error_message(), $again );
		}

		$codes = implode( ', ', TCGiant_Sync_API::error_codes( $error ) );

		// The same refusal as the last try, of a listing this try's own read
		// found unchanged: asking again would hear what the last check heard.
		if ( isset( $job['refusal'], $job['pre'] ) && md5( $call . '|' . $codes . '|' . (int) $job['pre'][0] . '|' . (int) $job['pre'][1] ) === $job['refusal'] ) {
			return self::same_refusal( $job, $want, $error, $again + array( 'refusal' => $job['refusal'] ) );
		}

		$check = ( self::is_priority( $job, $want ) )
			? TCGiant_Sync_API::instance()->with_stock_priority( $read_it )
			: $read_it();

		if ( isset( $check['stop'] ) ) {
			return $check['stop'];
		}
		if ( isset( $check['error'] ) ) {
			$failed = self::read_failed( $check['error'] );
			return 'tomorrow' === $failed['status'] ? $failed : self::push_outcome( 'retry', $error->get_error_message(), $again );
		}

		// An auction after all: its own rule, with the answer in hand.
		if ( ! empty( $check['auction'] ) ) {
			return $this->push_auction( $job, $target, $want, $auto_end, $check['item'] );
		}

		$avail = (int) $check['avail'];

		// It shows what was sent: the change is in, whatever eBay answered.
		if ( 'ReviseInventoryStatus' === $call && $avail === (int) $want ) {
			if ( 'edit' === $job['reason'] ) {
				$job = self::record_once_landed( $job, $item_id, $target['variation'] ? (string) $check['sku'] : '', (int) $check['sold'], $want, $want );
			}
			return $this->stock_sent( $job, $target, $call, $want, $sku, array() );
		}

		if ( 0 === (int) $want && $avail > 0 ) {
			// The last variation with stock, which eBay will not set to 0
			// without Out-of-Stock control: it would leave a listing with
			// nothing to sell. Ended instead, so it cannot sell again - unless
			// another of the product's variations has stock here, or a change
			// on its way, which would bring the listing back to life: then the
			// zero is tried again once that has gone.
			if ( $target['variation'] && ! empty( $check['others_zero'] ) ) {
				$sibling = self::sibling_with_stock( $target['owner_id'], $product_id );
				if ( $sibling ) {
					TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: eBay will not set variation %s of listing %s to 0 while it is the last with stock there, but variation #%d has stock here or a change on its way, so the listing is not ended; tried again later.', $product_id, $sku, $item_id, $sibling ) );
					return self::push_outcome( 'retry', $error->get_error_message(), $again );
				}
				TCGiant_Sync_Logger::warning( sprintf(
					'Stock push for WC #%d: variation %s is the last one on eBay listing %s with stock, and eBay would not set it to 0 (%s). The listing was ended so it cannot sell again. Turn on Out-of-Stock control in eBay\'s selling preferences to keep listings like this live at 0 instead.',
					$product_id,
					$sku,
					$item_id,
					'' !== $codes ? 'eBay code ' . $codes : $error->get_error_message()
				) );
				return $this->end_after_refusal( $job, $target );
			}

			// "Auto-end" is No, but eBay will not take 0 without Out-of-Stock
			// control: ended all the same, so it cannot oversell.
			if ( ! $target['variation'] && ! $auto_end ) {
				if ( ! get_transient( 'tcgiant_zero_refused_warned' ) ) {
					set_transient( 'tcgiant_zero_refused_warned', 1, DAY_IN_SECONDS );
					TCGiant_Sync_Logger::warning( sprintf(
						'"Auto-end listing when stock reaches 0" is No, but eBay would not accept 0 for listing %s (%s). Turn on Out-of-Stock control in eBay\'s selling preferences. The listing was ended so it cannot sell again.',
						$item_id,
						'' !== $codes ? 'code ' . $codes : $error->get_error_message()
					) );
				}
				return $this->end_after_refusal( $job, $target );
			}
		}

		// Sent to the wrong SKU, or without one to a listing that is tracked
		// by SKU: sent once more, to what eBay has.
		if ( ! $resent && 'ReviseInventoryStatus' === $call ) {
			$better = $target['variation']
				? (string) $check['sku']
				: ( 'SKU' === $check['tracking'] && '' === (string) $sku ? (string) $check['item_sku'] : '' );

			if ( '' !== $better && $better !== (string) $sku ) {
				return array( 'resend' => $better );
			}
		}

		// A listing made through eBay's Inventory API takes no change from the
		// Trading API. It is sent through the Inventory API instead - but only
		// when eBay names this very listing as that SKU's offer, never because
		// some inventory item happens to have the SKU.
		$inventory = $this->push_through_inventory_api( $job, $target, $want, $sku, $check );
		if ( null !== $inventory ) {
			return $inventory;
		}

		// eBay does not know the SKU: it will not know it later either.
		if ( array_intersect( TCGiant_Sync_API::error_codes( $error ), array( '21675', '21676' ) ) ) {
			TCGiant_Sync_Logger::error( sprintf( 'Stock push for WC #%d: eBay listing %s does not know SKU "%s" (%s), so the stock was not sent. Give the product the SKU it has on eBay.', $product_id, $item_id, $sku, $error->get_error_message() ) );
			return self::push_outcome( 'error', $error->get_error_message() );
		}

		// The same refusal as the last try, of a listing that has not changed.
		$refusal = md5( $call . '|' . $codes . '|' . $avail . '|' . (int) ( $check['sold'] ?? 0 ) );
		if ( isset( $job['refusal'] ) && $refusal === $job['refusal'] ) {
			return self::same_refusal( $job, $want, $error, $again + array( 'refusal' => $refusal ) );
		}

		return self::push_outcome( 'retry', $error->get_error_message(), $again + array( 'refusal' => $refusal ) );
	}

	/**
	 * eBay refused the same way twice, the listing unchanged. A raised or
	 * lowered quantity from a hand edit or an import is given up: ten hours
	 * of tries will not change eBay's answer. A sale, a push by hand and a
	 * change to 0 stop an oversell, and keep decision 9's tries to the end -
	 * each from now on only reading and sending, without the check after
	 * the refusal.
	 *
	 * @param array    $job   The push.
	 * @param int      $want  What was sent.
	 * @param WP_Error $error eBay's refusal.
	 * @param array    $again error, ended_try and refusal for a try again.
	 * @return array See push_outcome().
	 */
	private static function same_refusal( array $job, $want, $error, array $again ) {
		if ( self::is_priority( $job, $want ) ) {
			return self::push_outcome( 'retry', $error->get_error_message(), $again );
		}

		return self::push_outcome( 'failed', $error->get_error_message(), $again + array( 'same_twice' => true ) );
	}

	/**
	 * A stock change made in WooCommerce, for a listing with nothing on
	 * record, whose quantity call got no answer that says it was not taken -
	 * a timeout, an answer that could not be read. eBay is asked there and
	 * then whether it took it. Asked only on the try again, an eBay sale in
	 * the meantime would look like the old figure, and the try again would
	 * put the sold unit back.
	 *
	 * @param array    $job     The push.
	 * @param array    $target  From push_target().
	 * @param string   $call    The call that went unanswered.
	 * @param int      $want    What was sent.
	 * @param string   $sku     The SKU it was sent for.
	 * @param WP_Error $error   What came back instead.
	 * @param callable $read_it Reads the listing, past the ceiling or not.
	 * @return array|null An outcome, or null to try again later.
	 */
	private function check_unanswered( array $job, array $target, $call, $want, $sku, $error, $read_it ) {
		$ledger_sku = $target['variation'] ? (string) $sku : '';

		// Out of calls (eBay's 518 and 10007 come back as rate_limited) and no
		// token both say nothing was taken.
		if ( 'edit' !== $job['reason'] || 'ReviseInventoryStatus' !== $call
			|| null !== self::sold_seen( $job['product_id'], $target['item_id'], $ledger_sku )
			|| in_array( $error->get_error_code(), array( 'rate_limited', 'no_token' ), true ) ) {
			return null;
		}

		$check = ( self::is_priority( $job, $want ) )
			? TCGiant_Sync_API::instance()->with_stock_priority( $read_it )
			: $read_it();

		if ( isset( $check['stop'] ) ) {
			return $check['stop'];
		}
		if ( isset( $check['error'] ) ) {
			// Whether this change got there is not known, so neither is what
			// eBay was left showing: a note of that from an earlier push no
			// longer says anything.
			if ( null !== self::own_record( $job['product_id'], $target['item_id'], $ledger_sku ) ) {
				delete_post_meta( (int) $job['product_id'], self::SOLD_SEEN_KEY );
			}
			return null;
		}
		if ( ! empty( $check['auction'] ) || (int) $check['avail'] !== (int) $want ) {
			return null;
		}

		$job = self::record_once_landed( $job, $target['item_id'], $target['variation'] ? (string) $check['sku'] : '', (int) $check['sold'], $want, $want );

		return $this->stock_sent( $job, $target, $call, $want, $sku, array() );
	}

	/**
	 * The listing's variations that take their stock from the parent: those
	 * eBay variations matched (TCGiant_Sync_Variations::match()) to a
	 * WooCommerce variation whose stock the parent keeps.
	 *
	 * @param int     $parent_id The variable product.
	 * @param array[] $ebay      From TCGiant_Sync_Variations::from_item().
	 * @return array eBay index => its figures, with wc_id, the variation the
	 *               record of its eBay sales is kept on.
	 */
	private static function pooled_variations( $parent_id, array $ebay ) {
		$parent = wc_get_product( (int) $parent_id );
		if ( ! $parent || ! $ebay ) {
			return array();
		}

		$match = TCGiant_Sync_Variations::match( $parent, $ebay );
		$out   = array();

		foreach ( $match['pairs'] as $i => $ids ) {
			foreach ( (array) $ids as $id ) {
				$child = wc_get_product( (int) $id );
				if ( $child && self::stock_held_by_parent( $child ) ) {
					$out[ $i ] = $ebay[ $i ] + array( 'wc_id' => (int) $id );
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * eBay shows the shared figure for a pooled variation: its sales so far
	 * are in the shared stock, and that is its record from now on - where it
	 * has none.
	 *
	 * @param array  $job     The push.
	 * @param array  $pooled  From pooled_variations(), as eBay shows them now.
	 * @param string $item_id The listing.
	 * @param int    $want    The shared figure.
	 * @return void
	 */
	private static function record_pool_landed( array $job, array $pooled, $item_id, $want ) {
		foreach ( $pooled as $p ) {
			if ( '' === $p['sku'] || null === $p['available'] || (int) $p['available'] !== (int) $want
				|| null !== self::sold_seen( $p['wc_id'], $item_id, $p['sku'] ) ) {
				continue;
			}
			self::record_sold_seen( $p['wc_id'], $item_id, $p['sku'], (int) $p['sold'] );
		}
	}

	/**
	 * The variations of the product keeping stock of their own, when eBay
	 * will not set the last of the pool's variations to 0 (so every other
	 * variation eBay has shows 0, or is gone). Each is one of:
	 *
	 * - waiting: a stock change of its own is on its way to eBay - the pool
	 *   waits for it;
	 * - to_send: paired with a variation of this listing (by
	 *   TCGiant_Sync_Variations::match()), with stock here and 0 on eBay -
	 *   its own push sends it, and the pool tries again after it;
	 * - off_ebay: stock here, but nothing on this listing it pairs with -
	 *   eBay may have removed it, as it does a variation set to 0 before it
	 *   ever sold. It cannot keep the listing alive, so it does not stop the
	 *   listing ending; the seller is told.
	 *
	 * One with no stock here is none of them.
	 *
	 * Once this pool push has queued their own pushes ($sent_once), one
	 * this listing still shows at 0 with stock here is waited for only while
	 * its push is still on its way - queued, running, or failed once with
	 * its try again due by the pool's next try ($pool_next) - and no longer
	 * once it has failed twice, met eBay's call limit or a refusal for good
	 * (own_push_on_its_way()). The pool's 0 stops an oversell: before fix
	 * round 5 it waited out that push's whole ten hours; in fix round 5 a
	 * single timeout, or a push still running, ended a listing L kept live
	 * (fix round 6). Not waited for, it is to_send, which push_pool() then
	 * takes as not got there.
	 *
	 * On the pool's last try ($pool_next 0) nothing is waited for: a wait
	 * there gave the pool up with eBay still selling the last shared unit,
	 * and nothing tried again (fix round 7).
	 *
	 * @param int     $parent_id The variable product.
	 * @param array[] $ebay      The listing's variations, from read_listing().
	 * @param bool    $sent_once This push already queued their own pushes.
	 * @param int     $pool_next When the pool's next try is due; 0 for none.
	 * @return array waiting, to_send (variation IDs), off_ebay (ID => SKU).
	 */
	private static function own_stock_variations( $parent_id, array $ebay, $sent_once = false, $pool_next = 0 ) {
		$out    = array(
			'waiting'  => array(),
			'to_send'  => array(),
			'off_ebay' => array(),
		);
		$parent = wc_get_product( (int) $parent_id );
		if ( ! $parent ) {
			return $out;
		}

		$paired = array();
		$match  = TCGiant_Sync_Variations::match( $parent, $ebay );
		foreach ( $match['pairs'] as $i => $ids ) {
			foreach ( (array) $ids as $id ) {
				$paired[ (int) $id ] = $ebay[ $i ];
			}
		}

		foreach ( (array) $parent->get_children() as $child_id ) {
			$child_id = (int) $child_id;
			$child    = wc_get_product( $child_id );
			if ( ! $child || 'publish' !== $child->get_status() || self::stock_held_by_parent( $child ) ) {
				continue;
			}
			$at_zero = $child->managing_stock() && (int) $child->get_stock_quantity() > 0
				&& isset( $paired[ $child_id ] ) && '' !== (string) $paired[ $child_id ]['sku']
				&& 0 === (int) $paired[ $child_id ]['available'];
			if ( $pool_next > 0 && self::live_push( $child_id ) && ( ! ( $sent_once && $at_zero ) || self::own_push_on_its_way( $child_id, $pool_next ) ) ) {
				$out['waiting'][] = $child_id;
				continue;
			}
			if ( ! $child->managing_stock() || (int) $child->get_stock_quantity() <= 0 ) {
				continue;
			}
			if ( isset( $paired[ $child_id ] ) && '' !== (string) $paired[ $child_id ]['sku'] ) {
				if ( 0 === (int) $paired[ $child_id ]['available'] ) {
					$out['to_send'][] = $child_id;
				}
				continue;
			}
			$out['off_ebay'][ $child_id ] = (string) $child->get_sku( 'edit' );
		}

		return $out;
	}

	/**
	 * Whether a push of the product is queued or running: a pending token
	 * that is not the tail of a push already done (a push keeps its token
	 * until it is over). Never the mark of a sale whose push gave up - that
	 * push is over, and waiting for it held a pool at 0 open for good (fix
	 * round 4) - and never a push parked for tomorrow, past the ceiling or
	 * by eBay's own call limit: waiting a day for it, eBay sold the last
	 * shared unit (fix round 5).
	 *
	 * @param int $product_id Product or variation.
	 * @return bool
	 */
	private static function live_push( $product_id ) {
		if ( get_transient( 'tcgiant_push_tomorrow_' . (int) $product_id ) ) {
			return false;
		}

		foreach ( self::pending_pushes( $product_id ) as $token => $until ) {
			if ( self::UNSENT_TOKEN !== (string) $token && (int) $until > time() + self::PUSH_PENDING_TAIL ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a website sale's push of the product is on its way, and not
	 * parked for tomorrow: a sale's token, not the tail of one already done
	 * (3.28.0 release fix round 2).
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	private static function live_sale_push( $product_id ) {
		if ( get_transient( 'tcgiant_push_tomorrow_' . (int) $product_id ) ) {
			return false;
		}

		foreach ( self::pending_pushes( $product_id ) as $token => $until ) {
			if ( 0 === strpos( (string) $token, self::SALE_TOKEN_PREFIX ) && (int) $until > time() + self::PUSH_PENDING_TAIL ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Post meta on a variation: the failed tries of its own pushes for a pool
	 * (queue_own_push()), by push token - failed (the try), next (when its
	 * try again is due) and stop (why the pool waits no longer: refused, or
	 * call limit). Kept where an object cache cannot drop it, and taken off
	 * when that push is over (fix round 7).
	 */
	const OWN_TRIES_KEY = '_ebay_own_push_tries';

	/**
	 * Whether a variation's push, which the pool's 0 waits on once the pool
	 * has queued the variation's own push, is still on its way (fix rounds
	 * 6 to 8):
	 *
	 * - running (its running mark still beats): yes;
	 * - queued and not yet run: yes, within PUSH_ENDED_AFTER of being queued
	 *   (its entry's queued_at, pending_queued_at()); queued before that,
	 *   with no running mark and no failed try, its action is lost
	 *   (cancelled, or a fatal error before it began): no;
	 * - failed one try: yes, if its try again is due by the pool's next try
	 *   and has not been due for more than PUSH_ENDED_AFTER - past that, with
	 *   no try running, that try again is lost the same way: no;
	 * - failed two tries, or its last try met eBay's call limit (518) or a
	 *   refusal read back from the listing: no.
	 *
	 * Only asked before the pool's last try: on that one nothing is waited
	 * for (own_stock_variations()).
	 *
	 * Given up, or parked for tomorrow, it is not on its way at all
	 * (live_push()). A try the host ended with nothing PHP could report has
	 * failed, and its safety try is its try again. So the pool waits a few
	 * minutes past one failure at most - the push's 5 minutes, inside the
	 * pool's next 15 - and never the ten hours a push's tries can take.
	 *
	 * @param int $variation_id The variation.
	 * @param int $pool_next    When the pool's next try is due.
	 * @return bool
	 */
	private static function own_push_on_its_way( $variation_id, $pool_next ) {
		$noted  = get_post_meta( (int) $variation_id, self::OWN_TRIES_KEY, true );
		$noted  = is_array( $noted ) ? $noted : array();
		$failed = 0;
		$due    = 0;
		$fresh  = false;

		// Its failed tries, noted by its token while it is on its way. One
		// with none is queued, not yet run, since its entry's queue time.
		foreach ( self::pending_entries( $variation_id ) as $token => $entry ) {
			if ( self::UNSENT_TOKEN === (string) $token || self::pending_until( $entry ) <= time() + self::PUSH_PENDING_TAIL ) {
				continue;
			}
			if ( ! isset( $noted[ $token ] ) ) {
				$fresh = $fresh || self::pending_queued_at( $entry ) > time() - self::PUSH_ENDED_AFTER;
				continue;
			}
			if ( '' !== (string) $noted[ $token ]['stop'] ) {
				return false;
			}
			if ( (int) $noted[ $token ]['failed'] > $failed ) {
				$failed = (int) $noted[ $token ]['failed'];
				$due    = (int) $noted[ $token ]['next'];
			}
		}

		// A try running now, or one the host ended.
		$running = false;
		foreach ( self::runs( $variation_id ) as $entry ) {
			if ( isset( $entry['next'] ) ) {
				continue;
			}
			$attempt = (int) ( $entry['attempt'] ?? $failed + 1 );
			$alive   = max( (int) $entry['started'], (int) ( $entry['beat'] ?? 0 ) );
			if ( time() < $alive + self::PUSH_ENDED_AFTER ) {
				$running = true;
				$failed  = max( $failed, $attempt - 1 );
			} elseif ( $attempt > $failed ) {
				$failed = $attempt;
				$due    = $alive + self::PUSH_ENDED_AFTER;
			}
		}

		if ( $failed >= 2 ) {
			return false;
		}
		if ( $running ) {
			return true;
		}

		// A try again not begun PUSH_ENDED_AFTER past its time, and no try
		// running: its action is lost, as a push never run is (fix round 8).
		if ( $failed > 0 && time() > $due + self::PUSH_ENDED_AFTER ) {
			return false;
		}

		return 0 === $failed ? $fresh : $due <= $pool_next;
	}

	/**
	 * A variation's own push for a pool (queue_own_push()) failed a try and
	 * is tried again: noted for the pool waiting on it, by the push's token -
	 * the try, when its try again is due, and whether the pool stops waiting
	 * now: eBay's call limit (518), or a refusal read back from the listing
	 * (own_push_on_its_way()). In post meta (OWN_TRIES_KEY): a transient an
	 * object cache dropped read as a push not yet run, and the pool waited
	 * to its last try (fix round 7).
	 *
	 * @param array $job    The push.
	 * @param array $result The try's outcome.
	 * @param int   $next   When its try again is due.
	 * @return void
	 */
	private static function note_own_try( array $job, array $result, $next ) {
		$token = (string) ( $job['token'] ?? '' );
		if ( '' === $token ) {
			return;
		}

		$error = $result['error'] ?? null;
		$stop  = '';
		if ( isset( $result['refusal'] ) ) {
			$stop = 'refused';
		} elseif ( $error instanceof WP_Error && ( 'rate_limited' === $error->get_error_code() || in_array( '518', TCGiant_Sync_API::error_codes( $error ), true ) ) ) {
			$stop = 'call limit';
		}

		$noted = get_post_meta( (int) $job['product_id'], self::OWN_TRIES_KEY, true );
		$noted = array_intersect_key( is_array( $noted ) ? $noted : array(), self::pending_pushes( $job['product_id'] ) );

		$noted[ $token ] = array(
			'failed' => (int) $job['attempt'],
			'next'   => (int) $next,
			'stop'   => $stop,
		);
		update_post_meta( (int) $job['product_id'], self::OWN_TRIES_KEY, $noted );
	}

	/**
	 * A variation's own push is over - landed, given up, or found nothing to
	 * send: its tries are taken off (push_done()).
	 *
	 * @param int    $variation_id The variation.
	 * @param string $token        The push's token.
	 * @return void
	 */
	private static function forget_own_tries( $variation_id, $token ) {
		$noted = get_post_meta( (int) $variation_id, self::OWN_TRIES_KEY, true );
		if ( ! is_array( $noted ) || ! isset( $noted[ (string) $token ] ) ) {
			return;
		}

		unset( $noted[ (string) $token ] );
		if ( $noted ) {
			update_post_meta( (int) $variation_id, self::OWN_TRIES_KEY, $noted );
		} else {
			delete_post_meta( (int) $variation_id, self::OWN_TRIES_KEY );
		}
	}

	/**
	 * Queue a variation's own push: a stock change made in WooCommerce, as
	 * a hand edit queues one. It sends the variation's figure through its
	 * own record of eBay's sales.
	 *
	 * It is there only so that the pool's 0 can land, which stops an
	 * oversell: so it goes past the 80% ceiling as a sale does (decision 8,
	 * fix round 5). Waiting for tomorrow, it kept the pool waiting, and eBay
	 * sold the last shared unit. And when it lands, the pool's 0 is tried
	 * once more if nothing else of the pool's is on its way (landed()).
	 *
	 * @param int    $variation_id The variation.
	 * @param string $item_id      Its listing.
	 * @param int    $parent_id    The variable product whose pool waits on it.
	 * @return void
	 */
	private static function queue_own_push( $variation_id, $item_id, $parent_id ) {
		set_transient( 'tcgiant_stock_pending_' . (int) $variation_id, 1, 60 );
		as_enqueue_async_action( self::PUSH_HOOK, array( array(
			'product_id' => (int) $variation_id,
			'reason'     => 'edit',
			'ebay_sale'  => 1,
			'pool_after' => (int) $parent_id,
			'item'       => (string) $item_id,
			'token'      => self::mark_push_pending( (int) $variation_id ),
		) ), self::PUSH_GROUP );
	}

	/**
	 * Another variation of the product with stock here, or a stock change on
	 * its way to eBay.
	 *
	 * @param int $parent_id    The variable product.
	 * @param int $variation_id The variation being pushed.
	 * @return int A sibling's ID, or 0.
	 */
	private static function sibling_with_stock( $parent_id, $variation_id ) {
		$parent = wc_get_product( (int) $parent_id );
		if ( ! $parent ) {
			return 0;
		}

		foreach ( (array) $parent->get_children() as $child_id ) {
			$child_id = (int) $child_id;
			if ( $child_id === (int) $variation_id ) {
				continue;
			}
			$child = wc_get_product( $child_id );
			if ( ! $child || 'publish' !== $child->get_status() ) {
				continue;
			}
			if ( ( $child->managing_stock() && (int) $child->get_stock_quantity() > 0 ) || self::pending_pushes( $child_id ) ) {
				return $child_id;
			}
		}

		return 0;
	}

	/**
	 * End a listing that would not take 0.
	 *
	 * @param array $job    The push.
	 * @param array $target From push_target().
	 * @return array See push_outcome().
	 */
	private function end_after_refusal( array $job, array $target ) {
		self::beat();
		$result = TCGiant_Sync_API::instance()->end_item( $target['item_id'] );

		if ( ! is_wp_error( $result ) ) {
			return $this->stock_sent( $job, $target, 'EndItem', 0, '', $result );
		}

		if ( 'staging_blocked' === $result->get_error_code() ) {
			return self::push_outcome( 'skipped', $result->get_error_message() );
		}

		return self::push_outcome( 'retry', $result->get_error_message(), array( 'error' => $result, 'ended_try' => true ) );
	}

	/**
	 * Set the quantity through eBay's Inventory API, for a listing made
	 * through it, which takes no change from the Trading API.
	 *
	 * Only when eBay's offers for the listing's SKU name this listing: until
	 * 3.28.0 any inventory item with the product's SKU was changed, whatever
	 * listing it belonged to. Read back with GetItem afterwards, like every
	 * other change.
	 *
	 * @param array  $job    The push.
	 * @param array  $target From push_target().
	 * @param int    $want   Available quantity to set.
	 * @param string $sku    The SKU the Trading call was sent with.
	 * @param array  $check  What GetItem said after the refusal.
	 * @return array|null An outcome, or null when the listing is not one of
	 *                    the Inventory API's, as far as eBay says.
	 */
	private function push_through_inventory_api( array $job, array $target, $want, $sku, array $check ) {
		$api      = TCGiant_Sync_API::instance();
		$rest_sku = $target['variation'] ? (string) $check['sku'] : (string) $check['item_sku'];

		if ( '' === $rest_sku ) {
			return null;
		}

		// Not logged when eBay has no offer for it: most listings are not the
		// Inventory API's, and that is the answer for all of them.
		self::beat();
		$offers = $api->request( 'sell/inventory/v1/offer', 'GET', array(), array( 'sku' => rawurlencode( $rest_sku ) ), false );
		$found  = false;

		foreach ( ( is_array( $offers ) && isset( $offers['offers'] ) && is_array( $offers['offers'] ) ) ? $offers['offers'] : array() as $offer ) {
			if ( is_array( $offer ) && (string) ( $offer['listing']['listingId'] ?? '' ) === (string) $target['item_id'] ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return null;
		}

		self::beat();
		$item = $api->get_inventory_item( $rest_sku, false );
		if ( is_wp_error( $item ) || ! is_array( $item ) ) {
			$error = is_wp_error( $item ) ? $item : new WP_Error( 'no_item', sprintf( 'eBay sent nothing for inventory item %s', $rest_sku ) );
			return self::push_outcome( 'retry', $error->get_error_message(), array( 'error' => $error ) );
		}

		$item['availability']['shipToLocationAvailability']['quantity'] = max( 0, (int) $want );
		self::beat();
		$put = $api->request( 'sell/inventory/v1/inventory_item/' . rawurlencode( $rest_sku ), 'PUT', $item );

		if ( is_wp_error( $put ) ) {
			if ( 'staging_blocked' === $put->get_error_code() ) {
				return self::push_outcome( 'skipped', $put->get_error_message() );
			}
			return self::push_outcome( 'retry', $put->get_error_message(), array( 'error' => $put ) );
		}

		$after = $this->read_listing( array( 'var_sku' => $target['variation'] ? $rest_sku : $sku ) + $target, $job );
		if ( isset( $after['stop'] ) ) {
			return $after['stop'];
		}
		if ( isset( $after['error'] ) || (int) $after['avail'] !== (int) $want ) {
			$why = isset( $after['error'] ) ? $after['error'] : new WP_Error( 'not_applied', sprintf( 'eBay\'s Inventory API took %d for %s, but the listing still shows %d', (int) $want, $rest_sku, (int) $after['avail'] ) );
			return self::push_outcome( 'retry', $why->get_error_message(), array( 'error' => $why ) );
		}

		TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: eBay listing %s was made through eBay\'s Inventory API, so its stock was sent through that, for SKU %s.', $target['product_id'], $target['item_id'], $rest_sku ) );

		return $this->stock_sent( $job, $target, 'ReviseInventoryStatus', $want, $rest_sku, array() );
	}

	/**
	 * Record that eBay ended a listing because its stock reached 0.
	 *
	 * Ended, with eBay's end time, why (for the Sold view to come: the reason
	 * and the listing it is about), and settled there and then: the stock is
	 * 0, and nothing eBay says about this listing later can be lower, so a
	 * relist after the shop restocks lists the restock instead of bringing
	 * the old listing's figures in over it.
	 *
	 * @param int    $owner_id Product holding the link.
	 * @param string $item_id  The listing.
	 * @param mixed  $response EndItem's answer.
	 * @return void
	 */
	private static function record_stock_end( $owner_id, $item_id, $response ) {
		$end = is_array( $response ) ? TCGiant_Sync_API::scalar_value( $response['EndTime'] ?? '' ) : '';

		update_post_meta( $owner_id, '_ebay_listing_status', 'Ended' );
		update_post_meta( $owner_id, '_ebay_end_time', '' !== $end ? $end : gmdate( 'Y-m-d\TH:i:s.000\Z' ) );
		update_post_meta( $owner_id, '_ebay_end_reason', 'stock_zero' );
		update_post_meta( $owner_id, '_ebay_end_reason_item', (string) $item_id );
		update_post_meta( $owner_id, '_ebay_stock_settled_at', current_time( 'mysql' ) );
		update_post_meta( $owner_id, '_ebay_stock_settled_item', (string) $item_id );
	}

	/**
	 * Read the listing a push is for.
	 *
	 * What eBay says is kept as it goes: the listing type, for this listing
	 * (so an EndItem knows it is not an auction), and a live listing recorded
	 * here as Ended has that mark taken off.
	 *
	 * @param array $target From push_target(); var_sku the SKU to look for.
	 * @param array $job    The push.
	 * @return array One of: stop => an outcome (the listing has ended and is
	 *               settled, or the variation or listing is not there); error
	 *               => WP_Error (try again); or item, item_id, avail, sold,
	 *               sku (eBay's for the variation), others_zero, tracking,
	 *               item_sku, auction and, for a pool, variations.
	 */
	private function read_listing( array $target, array $job ) {
		$item_id  = $target['item_id'];
		$owner_id = $target['owner_id'];
		self::beat();
		$response = TCGiant_Sync_API::instance()->get_item( $item_id );

		if ( is_wp_error( $response ) ) {
			// Not this seller's, or not there at all: asking again will not
			// change that.
			if ( array_intersect( TCGiant_Sync_API::error_codes( $response ), array( '17', '231' ) ) ) {
				TCGiant_Sync_Logger::error( sprintf( 'Stock push: eBay listing %s, which WC #%d follows, cannot be found on this eBay account (%s), so its stock was not sent.', $item_id, $owner_id, $response->get_error_message() ) );
				return array( 'stop' => self::push_outcome( 'error', $response->get_error_message() ) );
			}
			return array( 'error' => $response );
		}

		if ( empty( $response['Item'] ) || ! is_array( $response['Item'] ) ) {
			return array( 'error' => new WP_Error( 'no_item', sprintf( 'eBay sent nothing for listing %s', $item_id ) ) );
		}

		$item   = $response['Item'] + array( 'ItemID' => $item_id );
		$status = TCGiant_Sync_API::scalar_value( $item['SellingStatus']['ListingStatus'] ?? '' );
		$type   = TCGiant_Sync_API::scalar_value( $item['ListingType'] ?? '' );

		self::note_listing_type( $owner_id, $item_id, $type );

		// Over on eBay: settled the way the delta and the sale check settle
		// an ended listing, from this answer.
		if ( '' !== $status && 'Active' !== $status ) {
			TCGiant_Sync_Importer::settle_ended_from_item( $owner_id, $item, 'Stock push' );

			// Its EndItem got through, though the answer was lost on the way.
			if ( ! empty( $job['ended_try'] ) && '' === (string) get_post_meta( $owner_id, '_ebay_end_reason', true ) ) {
				update_post_meta( $owner_id, '_ebay_end_reason', 'stock_zero' );
				update_post_meta( $owner_id, '_ebay_end_reason_item', (string) $item_id );
			}

			// eBay's own figures, which a 3.27 mark lacked: the end is now
			// eBay's word, not a guess, and needs no asking again.
			if ( '' === (string) get_post_meta( $owner_id, '_ebay_quantity_listed', true ) ) {
				$listed = TCGiant_Sync_API::scalar_value( $item['Quantity'] ?? '' );
				$sold   = TCGiant_Sync_API::scalar_value( $item['SellingStatus']['QuantitySold'] ?? '' );
				if ( is_numeric( $listed ) && is_numeric( $sold ) ) {
					update_post_meta( $owner_id, '_ebay_quantity_listed', (int) $listed );
					update_post_meta( $owner_id, '_ebay_quantity_sold', (int) $sold );
				}
			}

			self::forget_unsent_sale( $job['product_id'] );
			TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: eBay listing %s has ended (%s), so nothing was sent; its final figures were brought in.', $target['product_id'], $item_id, $status ) );
			return array( 'stop' => self::push_outcome( 'skipped', sprintf( 'Listing ended on eBay (%s)', $status ) ) );
		}

		// Live on eBay but recorded here as Ended - by the hourly check when it
		// could not read it, or by 3.27 after an EndItem eBay refused. The
		// record is put right, and whatever was settled about it is spent.
		if ( 'Ended' === (string) get_post_meta( $owner_id, '_ebay_listing_status', true ) ) {
			update_post_meta( $owner_id, '_ebay_listing_status', 'Active' );
			self::clear_settled_mark( $owner_id );
			TCGiant_Sync_Logger::log( sprintf( 'Stock push: eBay listing %s is live, though WC #%d had it recorded as ended; recorded as live again.', $item_id, $owner_id ) );
		}

		$base = array(
			'item'     => $item,
			'item_id'  => (string) $item_id,
			'auction'  => 'Chinese' === $type,
			'tracking' => TCGiant_Sync_API::scalar_value( $item['InventoryTrackingMethod'] ?? '' ),
			'item_sku' => TCGiant_Sync_API::scalar_value( $item['SKU'] ?? '' ),
		);

		$item_sold = TCGiant_Sync_API::scalar_value( $item['SellingStatus']['QuantitySold'] ?? '0' );
		$item_sold = is_numeric( $item_sold ) ? (int) $item_sold : 0;

		if ( $base['auction'] ) {
			return $base + array( 'avail' => 0, 'sold' => $item_sold, 'sku' => '', 'others_zero' => false );
		}

		if ( ! empty( $target['pool'] ) ) {
			return $base + array( 'avail' => 0, 'sold' => $item_sold, 'sku' => '', 'others_zero' => false, 'variations' => TCGiant_Sync_Variations::from_item( $item ) );
		}

		if ( ! $target['variation'] ) {
			$listed = TCGiant_Sync_API::scalar_value( $item['Quantity'] ?? '' );

			if ( ! is_numeric( $listed ) ) {
				return array( 'error' => new WP_Error( 'no_quantity', sprintf( 'eBay did not say how many listing %s has', $item_id ) ) );
			}

			return $base + array(
				'avail'       => max( 0, (int) $listed - $item_sold ),
				'sold'        => $item_sold,
				'sku'         => '',
				'others_zero' => false,
			);
		}

		// One variation: by its SKU, else as the settles pair them.
		$ebay  = TCGiant_Sync_Variations::from_item( $item );
		$index = null;

		foreach ( $ebay as $i => $variation ) {
			if ( '' !== (string) $target['var_sku'] && $variation['sku'] === (string) $target['var_sku'] ) {
				$index = $i;
				break;
			}
		}

		$parent = null === $index ? wc_get_product( $owner_id ) : false;
		if ( $parent ) {
			$match = TCGiant_Sync_Variations::match( $parent, $ebay );
			foreach ( $match['pairs'] as $i => $ids ) {
				if ( in_array( (int) $target['product_id'], array_map( 'intval', $ids ), true ) ) {
					$index = $i;
					break;
				}
			}
		}

		if ( null === $index || '' === $ebay[ $index ]['sku'] || null === $ebay[ $index ]['available'] ) {
			$why = null === $index
				? sprintf( 'variation %s is not on eBay listing %s - eBay takes a variation off when it is set to 0 before it has sold, unless Out-of-Stock control is on. Push the product from WooCommerce to put it back', $target['var_sku'], $item_id )
				: sprintf( 'eBay listing %s has that variation without a SKU or a quantity, and eBay can only be told about one by its SKU', $item_id );
			TCGiant_Sync_Logger::error( sprintf( 'Stock push for WC #%d not sent: %s.', $target['product_id'], $why ) );
			return array( 'stop' => self::push_outcome( 'error', 'Variation SKU not on the eBay listing' ) );
		}

		$others_zero = true;
		foreach ( $ebay as $i => $variation ) {
			if ( $i !== $index && 0 !== $variation['available'] ) {
				$others_zero = false;
				break;
			}
		}

		return $base + array(
			'avail'       => (int) $ebay[ $index ]['available'],
			'sold'        => (int) $ebay[ $index ]['sold'],
			'sku'         => $ebay[ $index ]['sku'],
			'others_zero' => $others_zero,
		);
	}

	/**
	 * A variable product whose variations share the stock it keeps: that one
	 * figure is sent to each of the listing's variations taking it, up to four
	 * to a call, and read back. Before 3.28.0 the listing was ended at 0 and
	 * otherwise never told; now each of them shows the shop's figure, and at
	 * 0 each is set to 0 - the last one with stock, which eBay will not take
	 * 0 for without Out-of-Stock control, then ends the listing, unless a
	 * variation keeping its own stock still has some. A variation keeping its
	 * own stock is never sent the shared figure.
	 *
	 * The record of eBay's sales is kept per pooled variation, on it.
	 *
	 * @param array $job      The push.
	 * @param array $target   From push_target(), a pool.
	 * @param int   $wc       The shared stock.
	 * @param bool  $auto_end "Auto-end listing when stock reaches 0".
	 * @return array See push_outcome().
	 */
	private function push_pool( array $job, array $target, $wc, $auto_end ) {
		$product_id = $job['product_id'];
		$item_id    = $target['item_id'];
		$read       = $this->read_listing( $target, $job );

		if ( isset( $read['stop'] ) ) {
			return $read['stop'];
		}
		if ( isset( $read['error'] ) ) {
			return self::read_failed( $read['error'] );
		}
		if ( ! empty( $read['auction'] ) ) {
			return $this->push_auction( $job, $target, $wc, $auto_end, $read['item'] );
		}

		// Only the listing's variations whose stock the parent keeps take the
		// shared figure. One keeping stock of its own is pushed by its own
		// changes, never by the pool's: sent the parent's figure, it was
		// overwritten, and at 0 the listing was ended while it had stock.
		$pooled = self::pooled_variations( $product_id, $read['variations'] );
		if ( ! $pooled ) {
			TCGiant_Sync_Logger::warning( sprintf( 'Stock push for WC #%d: none of the variations of eBay listing %s matches a variation of it that takes its stock from it, so the shared stock was not sent.', $product_id, $item_id ) );
			return self::push_outcome( 'skipped', 'No variation on eBay takes the shared stock' );
		}

		// eBay's sales of those variations that the shared stock has not taken
		// off: each variation's since its own record. Each pooled variation
		// shows the whole pool on eBay, so with no record a sale's push looks
		// at each one against the stock before the sale - never at their sum.
		$before = 'order' === $job['reason'] && isset( $job['before'] ) ? (int) $job['before'] : null;
		$unseen = 0;
		$known  = array();
		foreach ( $pooled as $i => $p ) {
			if ( '' === $p['sku'] || null === $p['available'] ) {
				continue;
			}
			$u = ! empty( $job['relisted'] ) && null === self::own_record( $p['wc_id'], $item_id, $p['sku'] )
				? 0
				: self::unseen( $p['wc_id'], $item_id, $p['sku'], (int) $p['available'], (int) $p['sold'], $before );
			if ( null !== $u ) {
				$unseen     += $u;
				$known[ $i ] = true;
			}
		}

		$records = function () use ( $known, $pooled, $item_id ) {
			foreach ( array_keys( $known ) as $i ) {
				self::record_sold_seen( $pooled[ $i ]['wc_id'], $item_id, $pooled[ $i ]['sku'], (int) $pooled[ $i ]['sold'] );
			}
		};
		if ( $unseen > 0 ) {
			// The records written with the stock (release fix round 4).
			$now = self::lower_stock_to( $product_id, (int) $wc - $unseen, $records );
			if ( null === $now ) {
				TCGiant_Sync_Logger::warning( sprintf( 'Stock push for WC #%d: eBay sold %d of the variations sharing its stock that WooCommerce has not taken off, but the stock could not be lowered - something else on this site may be holding it. Tried again later.', $product_id, $unseen ) );
				return self::push_outcome( 'retry', 'WooCommerce\'s stock could not be lowered' );
			}
			TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: %d sale(s) of the variations of eBay listing %s sharing its stock had not reached WooCommerce yet, so the shared stock was lowered from %d to %d first.', $product_id, $unseen, $item_id, (int) $wc, $now ) );
			$wc = $now;
		}
		$records();

		$want    = max( 0, (int) $wc );
		$changes = array();
		$no_sku  = 0;

		foreach ( $pooled as $p ) {
			if ( '' === $p['sku'] ) {
				$no_sku++;
				continue;
			}
			$to = 'edit' === $job['reason'] ? $want : min( (int) $p['available'], $want );
			if ( null === $p['available'] || $to !== (int) $p['available'] ) {
				$changes[ $p['sku'] ] = $to;
			}
		}

		if ( $no_sku ) {
			TCGiant_Sync_Logger::warning( sprintf( 'Stock push for WC #%d: %d variation(s) of eBay listing %s have no SKU, so the shared stock was not sent to them.', $product_id, $no_sku, $item_id ) );
		}

		if ( ! $changes ) {
			// Nothing to send: eBay already shows the shared figure for each,
			// so each one's sales so far are in it.
			self::record_pool_landed( $job, $pooled, $item_id, $want );
			self::landed( $job );
			return self::push_outcome( 'manual' === $job['reason'] ? 'skipped' : 'success', sprintf( 'eBay already shows %d for each variation', $want ) );
		}

		// Those with stock first, then the zeros: eBay refuses only the last
		// variation with stock, so it is the last one sent.
		arsort( $changes );

		$api     = TCGiant_Sync_API::instance();
		$refused = null;
		foreach ( array_chunk( $changes, 4, true ) as $batch ) {
			self::beat();
			$result = $api->revise_inventory_statuses( $item_id, $batch );
			if ( ! is_wp_error( $result ) ) {
				continue;
			}

			if ( 'staging_blocked' === $result->get_error_code() ) {
				return self::push_outcome( 'skipped', $result->get_error_message() );
			}
			if ( TCGiant_Sync_API::is_transient_error( $result ) ) {
				return self::push_outcome( 'retry', $result->get_error_message(), array( 'error' => $result ) );
			}
			$refused = $result;
		}

		// Several variations in one call: eBay can take some and not the
		// others, and say so only as a warning. What took is read back from
		// eBay itself, refused or not.
		$check = $this->read_listing( $target, $job );
		if ( isset( $check['stop'] ) ) {
			return $check['stop'];
		}

		$left = array();
		if ( ! isset( $check['error'] ) ) {
			foreach ( $check['variations'] as $v ) {
				if ( isset( $changes[ $v['sku'] ] ) && (int) $v['available'] !== (int) $changes[ $v['sku'] ] ) {
					$left[ $v['sku'] ] = (int) $v['available'];
				}
			}
		}

		if ( isset( $check['error'] ) || $left ) {
			// Going to 0, and the one eBay kept is the last with stock. A
			// variation keeping stock of its own can keep the listing alive
			// only from this listing: one with a change on its way is waited
			// for; one this listing has at 0 while WooCommerce has some is
			// sent by its own push, and the pool tries again after it. One
			// eBay no longer has (nothing here pairs with it) cannot, and the
			// listing ends - before 3.28.0's fix round 3 it never did, and
			// eBay went on selling a shared unit the shop no longer had.
			$with_stock = isset( $check['error'] ) ? array() : array_values( array_filter( $check['variations'], function ( $v ) { return (int) $v['available'] > 0; } ) );
			if ( 0 === $want && 1 === count( $with_stock ) && isset( $left[ $with_stock[0]['sku'] ] ) ) {
				$why_not = $refused ? $refused->get_error_message() : 'eBay kept the last variation with stock';
				$again   = $refused ? array( 'error' => $refused ) : array();
				// The pool's next try: its own try again, or none on its last.
				$pool_next = (int) $job['attempt'] <= count( self::PUSH_RETRY_DELAYS ) ? time() + self::PUSH_RETRY_DELAYS[ (int) $job['attempt'] - 1 ] : 0;
				$own       = self::own_stock_variations( $product_id, $check['variations'], ! empty( $job['own_queued'] ), $pool_next );

				if ( $own['waiting'] ) {
					TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: eBay will not set the last of the variations sharing its stock to 0, but variation #%s keeps stock of its own and has a change on its way, so listing %s is not ended; tried again later.', $product_id, implode( ', #', $own['waiting'] ), $item_id ) );
					return self::push_outcome( 'retry', $why_not, $again );
				}

				// Its own push is queued once in a push's tries: one that eBay
				// still shows at 0 after it, with nothing of its own on the way,
				// did not get there and cannot keep the listing live (fix
				// round 4). Queued every try, a push eBay refused for good went
				// round again on each. Never on the pool's last try: there is
				// no try after it to send the 0 (fix round 7).
				if ( $own['to_send'] && empty( $job['own_queued'] ) && $pool_next > 0 ) {
					foreach ( $own['to_send'] as $variation_id ) {
						self::queue_own_push( $variation_id, $item_id, $product_id );
					}
					TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: eBay will not set the last of the variations sharing its stock to 0, but variation #%s keeps stock of its own here and shows 0 on eBay listing %s: its own push is queued to send it, and the shared stock is tried again after it.', $product_id, implode( ', #', $own['to_send'] ), $item_id ) );
					return self::push_outcome( 'retry', $why_not, $again + array( 'own_queued' => true ) );
				}

				if ( $own['to_send'] ) {
					TCGiant_Sync_Logger::warning( sprintf( 'Stock push for WC #%d: variation(s) #%s keep stock of their own here, but their own push did not get it to eBay listing %s, which still shows 0 for them - so they could not keep it live. Check them in Seller Hub.', $product_id, implode( ', #', $own['to_send'] ), $item_id ) );
				}

				if ( $own['off_ebay'] ) {
					$named = array();
					foreach ( $own['off_ebay'] as $variation_id => $own_sku ) {
						$named[] = '' !== $own_sku ? sprintf( '#%d (%s)', $variation_id, $own_sku ) : sprintf( '#%d', $variation_id );
					}
					TCGiant_Sync_Logger::warning( sprintf( 'Stock push for WC #%d: variation(s) %s keep stock of their own here, but eBay listing %s has no variation of theirs with a SKU to send it to - eBay removes a variation set to 0 before it has sold, unless Out-of-Stock control is on - so they could not keep it live. Push the product from WooCommerce to list them again.', $product_id, implode( ', ', $named ), $item_id ) );
				}

				TCGiant_Sync_Logger::warning( sprintf( 'Stock push for WC #%d: the variations of eBay listing %s share stock that has run out, and eBay would not set the last of them to 0. The listing was ended so it cannot sell again. Turn on Out-of-Stock control in eBay\'s selling preferences to keep listings like this live at 0 instead.', $product_id, $item_id ) );
				return $this->end_after_refusal( $job, $target );
			}

			$why = $refused ? $refused : ( isset( $check['error'] ) ? $check['error'] : new WP_Error( 'not_applied', sprintf( 'eBay did not take the shared stock for %s', implode( ', ', array_keys( $left ) ) ) ) );
			return self::push_outcome( 'retry', $why->get_error_message(), array( 'error' => $why ) );
		}

		self::record_pool_landed( $job, self::pooled_variations( $product_id, $check['variations'] ), $item_id, $want );
		self::landed( $job );
		TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d: the shared stock, %d, sent to %d variation(s) of eBay listing %s.', $product_id, $want, count( $changes ), $item_id ), 'success' );

		return self::push_outcome( 'success', sprintf( 'eBay stock set to %d for each variation', $want ) );
	}

	/**
	 * "eBay listing 111", or "variation BLK of eBay listing 777", for the log.
	 *
	 * @param array  $target From push_target().
	 * @param string $sku    The SKU sent.
	 * @return string
	 */
	private static function listing_words( array $target, $sku ) {
		return $target['variation']
			? sprintf( 'variation %s of eBay listing %s', $sku, $target['item_id'] )
			: sprintf( 'eBay listing %s', $target['item_id'] );
	}

	/**
	 * Try a push again later, or give it up.
	 *
	 * Nothing about the listing is recorded on any failure: it stays Active,
	 * so the hourly check and later pushes still see it. A try again works
	 * out again what to send from what eBay and WooCommerce say then: no
	 * figure is carried over from this try.
	 *
	 * The try again is the try's safety try, where it has one, handed this
	 * push (schedule_next()): no second action is queued for it.
	 *
	 * @param array      $job       The push.
	 * @param array      $target    From push_target().
	 * @param array      $result    retry or tomorrow, with error, refusal and
	 *                              ended_try.
	 * @param bool       $may_retry False for push_now().
	 * @param int        $wc        WooCommerce's stock.
	 * @param array|null $safety    From arm_safety().
	 * @return array See push_outcome().
	 */
	private function push_again_later( array $job, array $target, array $result, $may_retry, $wc, $safety = null ) {
		$product_id = $job['product_id'];
		$error      = $result['error'] ?? null;
		$why        = '' !== (string) ( $result['message'] ?? '' ) ? (string) $result['message'] : ( $error instanceof WP_Error ? $error->get_error_message() : '' );
		$codes      = TCGiant_Sync_API::error_codes( $error );
		$code       = $codes ? implode( ', ', $codes ) : ( $error instanceof WP_Error ? (string) $error->get_error_code() : '' );

		// The order screen's push: said there and then, never queued.
		if ( ! $may_retry ) {
			TCGiant_Sync_Logger::error( sprintf( 'Manual stock push for WC #%d to eBay listing %s did not go through: %s', $product_id, $target['item_id'], $why ) );
			self::disarm_safety( $job, $safety );
			self::push_done( $job );
			return self::push_outcome( 'error', $why );
		}

		// Switched off, or found to be a staging copy, while this try ran:
		// final. Not tried again, and not given up as a sale that never
		// reached eBay - nothing is to be sent.
		$stopped = self::push_stopped();
		if ( '' !== $stopped ) {
			TCGiant_Sync_Logger::log( sprintf( 'Stock push for WC #%d to eBay listing %s did not go through (%s), and is not tried again: %s', $product_id, $target['item_id'], $why, $stopped ) );
			self::disarm_safety( $job, $safety );
			self::push_done( $job );
			return self::push_outcome( 'skipped', $stopped );
		}

		// The plugin's share of the day's eBay calls is used up: tomorrow,
		// spread over its first half hour, without counting as a try. One
		// waiting per product is enough - it reads the stock when it runs.
		if ( 'tomorrow' === $result['status'] ) {
			$waiting = 'tcgiant_push_tomorrow_' . $product_id;
			if ( get_transient( $waiting ) ) {
				self::disarm_safety( $job, $safety );
				self::push_done( $job );
				return self::push_outcome( 'retry', $why );
			}

			$when = gmmktime( 0, 0, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ) + 1, (int) gmdate( 'Y' ) ) + wp_rand( 0, 30 * MINUTE_IN_SECONDS );
			set_transient( $waiting, 1, max( 60, $when - time() + HOUR_IN_SECONDS ) );
			$next = array( 'tomorrow' => 1 ) + (array) self::push_job( $job );
			unset( $next['safety'] );
			self::schedule_next( $job, $safety, $next, $when );
			self::hold_pending_until( $job, $when );
			// Not a warning: a large import past the ceiling would write one per
			// product, and each is sent tomorrow without anyone doing anything.
			TCGiant_Sync_Logger::log( sprintf(
				'Stock push for WC #%d (stock %d) to eBay listing %s waits until %s UTC: today\'s share of eBay calls is used up, and only changes that stop an oversell - a sale, or stock reaching 0 - are sent past it.',
				$product_id,
				$wc,
				$target['item_id'],
				gmdate( 'Y-m-d H:i', $when )
			) );
			return self::push_outcome( 'retry', $why );
		}

		$attempt = (int) $job['attempt'];
		$delays  = self::PUSH_RETRY_DELAYS;
		$tries   = count( $delays ) + 1;

		// eBay's own 518, at any try: it lifts when it lifts, so the push
		// waits on it hourly and uncounted (outage_wait()) rather than
		// spending its tries hours apart - it reached eBay up to four hours
		// after the limit lifted (3.28.0 release fix round 2).
		$e518 = class_exists( 'TCGiant_Sync_Importer' ) && TCGiant_Sync_Importer::ebay_out_of_calls( $error );

		if ( $attempt <= count( $delays ) && ! $e518 ) {
			$next            = (array) self::push_job( $job );
			$next['attempt'] = $attempt + 1;
			// No longer waiting on a 518: its next try asks nothing first.
			unset( $next['refusal'], $next['ended_try'], $next['safety'], $next['tomorrow'], $next['e518'] );

			if ( isset( $result['refusal'] ) ) {
				$next['refusal'] = (string) $result['refusal'];
			}
			if ( ! empty( $result['ended_try'] ) ) {
				$next['ended_try'] = 1;
			}
			if ( ! empty( $result['own_queued'] ) ) {
				$next['own_queued'] = 1;
			}

			$delay = $delays[ $attempt - 1 ];
			if ( ! empty( $job['pool_after'] ) ) {
				self::note_own_try( $job, $result, time() + $delay );
			}
			self::schedule_next( $job, $safety, $next, time() + $delay );

			TCGiant_Sync_Logger::warning( sprintf(
				'Stock push for WC #%d to eBay listing %s did not go through (%s%s). Trying again in %s (try %d of %d).',
				$product_id,
				$target['item_id'],
				$why,
				'' !== $code ? '; ' . $code : '',
				$delay >= HOUR_IN_SECONDS ? sprintf( '%d hour%s', (int) ( $delay / HOUR_IN_SECONDS ), $delay >= 2 * HOUR_IN_SECONDS ? 's' : '' ) : sprintf( '%d minutes', (int) ( $delay / MINUTE_IN_SECONDS ) ),
				$attempt + 1,
				$tries
			) );

			return self::push_outcome( 'retry', $why );
		}

		// Its last try failed for a reason that passes, or any try met eBay's
		// 518: before it is given up, eBay is asked whether it answers at
		// all, and the day's calls are looked at, as item D's sale check
		// does - a long outage gave up pushes the sale checks waited out
		// (3.28.0 release fix rounds 1 and 2).
		$wait = self::outage_wait( $job, $error );
		if ( null !== $wait ) {
			$next = $wait['keep'] + (array) self::push_job( $job );
			unset( $next['refusal'], $next['ended_try'], $next['safety'], $next['tomorrow'] );
			if ( empty( $wait['keep']['e518'] ) ) {
				unset( $next['e518'] );
			}
			self::schedule_next( $job, $safety, $next, $wait['when'] );
			self::hold_pending_until( $job, $wait['when'] );
			// A variation's own push for a pool: its try noted, so the pool
			// waits on it no longer - eBay's 518, or its last try (fix round 6).
			if ( ! empty( $job['pool_after'] ) ) {
				self::note_own_try( $job, $result, $wait['when'] );
			}

			TCGiant_Sync_Logger::warning( sprintf(
				'Stock push for WC #%d to eBay listing %s did not go through (%s%s) %s. Trying again %s, not counted as a try.',
				$product_id,
				$target['item_id'],
				$why,
				'' !== $code ? '; ' . $code : '',
				$attempt > count( $delays ) ? 'on its last try' : sprintf( 'on try %d of %d', $attempt, $tries ),
				$wait['again']
			) );

			return self::push_outcome( 'retry', $why );
		}

		self::disarm_safety( $job, $safety );

		return $this->give_up( $job, $target, $result, $wc );
	}

	/**
	 * A push that waits - an outage, eBay's 518, the day's calls - holds back
	 * an import for as long as it waits: its pending entry's until moved to
	 * the wait's end and its tail, its queue time kept. Its two days ran out
	 * before a three-day wait did, and an import then put back a sale the
	 * push had yet to send (3.28.0 release fix round 2).
	 *
	 * @param array $job  The push.
	 * @param int   $when When it is tried again.
	 * @return void
	 */
	private static function hold_pending_until( array $job, $when ) {
		$token = (string) ( $job['token'] ?? '' );
		if ( '' === $token ) {
			return;
		}

		$product_id = (int) $job['product_id'];
		$entries    = self::pending_entries( $product_id );
		$until      = (int) $when + self::PUSH_PENDING_TAIL;
		if ( ! isset( $entries[ $token ] ) || self::pending_until( $entries[ $token ] ) >= $until ) {
			return;
		}

		$entries[ $token ] = array(
			'until'     => $until,
			'queued_at' => self::pending_queued_at( $entries[ $token ] ),
		);
		update_post_meta( $product_id, self::PUSH_PENDING_KEY, $entries );
	}

	/**
	 * Whether a push whose last try failed waits rather than gives up, and
	 * till when - item D's rules for a sale check (TCGiant_Sync_Importer):
	 *
	 * - eBay's own 518 (ebay_out_of_calls()): hourly, every 15 minutes in the
	 *   hour after 00:05:30 UTC (after_ebay_518()), uncounted, for up to
	 *   SETTLE_CHAIN_DAYS from when it was queued; each try asks the shared
	 *   question before it sends (run_push(); release fix round 2).
	 * - The plugin's own day budget (out_for_the_day()): the next 00:05:30
	 *   UTC, uncounted; blocked again within the hour after that, 15 minutes.
	 *   After SETTLE_OUT_DAYS such days, given up.
	 * - A timeout, an answer that was not eBay's, or a throttle: if eBay does
	 *   not answer at all (ebay_not_answering(), shared by every check and
	 *   push for five minutes), the push's last delay again, uncounted, for
	 *   up to SETTLE_CHAIN_DAYS from when it was queued.
	 *
	 * Anything else - eBay's refusal, no token (item 17's rules for a
	 * disconnected shop), a host that stopped the try - is given up as before.
	 *
	 * @param array $job   The push.
	 * @param mixed $error The last try's error.
	 * @return array|null when, keep (since, out, out_day and e518 for the
	 *                    job) and again (for the log); null to give up.
	 */
	private static function outage_wait( array $job, $error ) {
		if ( ! $error instanceof WP_Error || ! class_exists( 'TCGiant_Sync_Importer' )
			|| ! in_array( $error->get_error_code(), array( 'http_request_failed', 'invalid_xml', 'rate_limited' ), true ) ) {
			return null;
		}

		// From when it was queued: its pending entry says (fix round 8).
		$since = (int) ( $job['since'] ?? 0 );
		if ( $since < 1 ) {
			$entries = self::pending_entries( (int) $job['product_id'] );
			$token   = (string) ( $job['token'] ?? '' );
			$since   = isset( $entries[ $token ] ) ? self::pending_queued_at( $entries[ $token ] ) : time();
		}

		// eBay's own 518 lifts when it lifts: hourly, uncounted, for up to
		// SETTLE_CHAIN_DAYS, each try asking the shared question first
		// (TCGiant_Sync_Importer::ebay_limit_lifted(), in run_push();
		// release fix round 2). The plugin's own budget waits for 00:05:30.
		if ( TCGiant_Sync_Importer::ebay_out_of_calls( $error ) ) {
			if ( time() - $since >= TCGiant_Sync_Importer::SETTLE_CHAIN_DAYS * DAY_IN_SECONDS ) {
				return null;
			}
			$when = TCGiant_Sync_Importer::after_ebay_518();
			TCGiant_Sync_Importer::note_ebay_518( $when );
			return array(
				'when'  => $when,
				'keep'  => array( 'since' => $since, 'e518' => 1 ),
				'again' => sprintf( 'in %d minutes, once eBay\'s call limit (518) has lifted', (int) round( ( $when - time() ) / MINUTE_IN_SECONDS ) ),
			);
		}

		if ( TCGiant_Sync_Importer::out_for_the_day( $error ) ) {
			$reset = gmmktime( 0, 0, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ), (int) gmdate( 'Y' ) ) + TCGiant_Sync_Importer::SETTLE_AFTER_MIDNIGHT;
			if ( gmdate( 'Y-m-d' ) === (string) ( $job['out_day'] ?? '' ) && time() < $reset + HOUR_IN_SECONDS ) {
				return array(
					'when'  => time() + TCGiant_Sync_Importer::SETTLE_RETRY_DELAYS[0],
					'keep'  => array( 'since' => $since ),
					'again' => 'in 15 minutes: the day\'s eBay calls have only just started again',
				);
			}

			$out = (int) ( $job['out'] ?? 0 );
			if ( $out >= TCGiant_Sync_Importer::SETTLE_OUT_DAYS ) {
				return null;
			}

			$when = TCGiant_Sync_Importer::after_budget_resets();
			return array(
				'when'  => $when,
				'keep'  => array( 'since' => $since, 'out' => $out + 1, 'out_day' => gmdate( 'Y-m-d', $when ) ),
				'again' => 'just after 00:05 UTC, once the day\'s eBay calls start again',
			);
		}

		if ( time() - $since < TCGiant_Sync_Importer::SETTLE_CHAIN_DAYS * DAY_IN_SECONDS && TCGiant_Sync_Importer::ebay_not_answering() ) {
			$last = self::PUSH_RETRY_DELAYS[ count( self::PUSH_RETRY_DELAYS ) - 1 ];
			return array(
				'when'  => time() + $last,
				'keep'  => array( 'since' => $since ),
				'again' => sprintf( 'in %d hours: eBay is not answering other calls either', (int) ( $last / HOUR_IN_SECONDS ) ),
			);
		}

		return null;
	}

	/**
	 * Queue a push's try again, for its own time, and take the try's safety
	 * try off the queue. Without Action Scheduler 3 (no way to take it off),
	 * a try again due after the safety try is handed to it instead - it puts
	 * the push off to its time when it runs (safety_due()) - so the queue
	 * does not hold both; one due sooner is queued, and the safety try finds
	 * its try finished and does nothing. So a push is never sent twice, and
	 * a try again is never held back to the safety try's time.
	 *
	 * @param array      $job    The push now.
	 * @param array|null $safety From arm_safety().
	 * @param array      $next   The try again's arguments.
	 * @param int        $when   When it is due.
	 * @return void
	 */
	private static function schedule_next( array $job, $safety, array $next, $when ) {
		if ( is_array( $safety ) ) {
			if ( (int) $when > (int) $safety['when'] && ! function_exists( 'as_unschedule_action' ) ) {
				$runs                   = self::runs( $job['product_id'] );
				$runs[ $safety['run'] ] = array(
					'started' => (int) ( $runs[ $safety['run'] ]['started'] ?? time() ),
					'when'    => (int) $safety['when'],
					'next'    => $next,
					'at'      => (int) $when,
				);
				update_post_meta( (int) $job['product_id'], self::PUSH_RUNNING_KEY, $runs );
				return;
			}
			self::disarm_safety( $job, $safety );
		}

		as_schedule_single_action( (int) $when, self::PUSH_HOOK, array( $next ), self::PUSH_GROUP );
	}

	/**
	 * Give a push up: logged as an error and listed. A sale that never
	 * reached eBay keeps holding back imports (push_done()'s unsent mark).
	 *
	 * @param array $job    The push.
	 * @param array $target From push_target().
	 * @param array $result The last outcome.
	 * @param int   $wc     WooCommerce's stock.
	 * @return array See push_outcome().
	 */
	private function give_up( array $job, array $target, array $result, $wc ) {
		$error = $result['error'] ?? null;
		$why   = '' !== (string) ( $result['message'] ?? '' ) ? (string) $result['message'] : ( $error instanceof WP_Error ? $error->get_error_message() : '' );
		$codes = TCGiant_Sync_API::error_codes( $error );
		$code  = $codes ? implode( ', ', $codes ) : ( $error instanceof WP_Error ? (string) $error->get_error_code() : '' );

		TCGiant_Sync_Logger::error( sprintf(
			'Stock push for WC #%d to eBay listing %s gave up %s. eBay\'s last answer: %s%s. Nothing was changed on eBay or recorded about the listing; WooCommerce holds %d. Check the listing in Seller Hub.',
			$job['product_id'],
			$target['item_id'],
			self::given_up_after( $job, $result ),
			$why,
			'' !== $code ? ' (' . $code . ')' : '',
			$wc
		) );

		self::record_push_failure( $job, $target, $code, $why, $wc );
		// A sale's own mark goes at once: nothing of it reached eBay for an
		// import to be behind, and the unsent mark, with its units, holds
		// from now on - only as far as those units (fix round 3).
		self::push_done( $job, 'order' === $job['reason'], 'order' === $job['reason'] );

		return self::push_outcome( 'error', $why );
	}

	/**
	 * How a push came to be given up, for its log line: its tries, and over
	 * how long - days when it waited out an outage or eBay's 518 uncounted,
	 * which "about 10 hours" misstated (3.28.0 release fix round 3).
	 *
	 * @param array $job    The push.
	 * @param array $result The last outcome.
	 * @return string
	 */
	private static function given_up_after( array $job, array $result ) {
		if ( ! empty( $result['same_twice'] ) ) {
			return 'after eBay refused it the same way twice, with the listing unchanged';
		}

		$since = (int) ( $job['since'] ?? 0 );
		$span  = $since > 0 ? time() - $since : 0;
		if ( $span < 11 * HOUR_IN_SECONDS ) {
			return sprintf( 'after %d tries over about 10 hours', count( self::PUSH_RETRY_DELAYS ) + 1 );
		}

		$tries = max( 1, (int) ( $job['attempt'] ?? 1 ) );
		$error = $result['error'] ?? null;
		if ( ! empty( $job['e518'] ) || ( class_exists( 'TCGiant_Sync_Importer' ) && TCGiant_Sync_Importer::ebay_out_of_calls( $error ) ) ) {
			$for = 'eBay\'s call limit (518) to lift';
		} elseif ( ! empty( $job['out'] ) ) {
			$for = 'the day\'s eBay calls to start again';
		} else {
			$for = 'eBay to answer again';
		}

		return sprintf(
			'after %d %s over about %s, waiting for %s',
			$tries,
			1 === $tries ? 'try' : 'tries',
			$span >= 2 * DAY_IN_SECONDS ? sprintf( '%d days', (int) round( $span / DAY_IN_SECONDS ) ) : sprintf( '%d hours', (int) round( $span / HOUR_IN_SECONDS ) ),
			$for
		);
	}

	/**
	 * Keep a push eBay never took, for Stock Review to list (3.28.1).
	 *
	 * @param array  $job    The push.
	 * @param array  $target From push_target().
	 * @param string $code   eBay's code, or the error's.
	 * @param string $why    eBay's message.
	 * @param int    $wc     WooCommerce's stock then.
	 * @return void
	 */
	private static function record_push_failure( array $job, array $target, $code, $why, $wc ) {
		$failures = get_option( self::PUSH_FAILURES_OPTION, array() );
		$failures = is_array( $failures ) ? $failures : array();

		$failures[] = array(
			'time'       => time(),
			'product_id' => (int) $job['product_id'],
			'item_id'    => (string) $target['item_id'],
			'sku'        => (string) $target['var_sku'],
			'reason'     => (string) $job['reason'],
			'qty'        => (int) ( $job['qty'] ?? 0 ),
			'order'      => (int) ( $job['order'] ?? 0 ),
			'stock'      => (int) $wc,
			'code'       => (string) $code,
			'message'    => (string) $why,
		);

		update_option( self::PUSH_FAILURES_OPTION, array_slice( $failures, -self::PUSH_FAILURES_KEEP ), false );
	}

	/**
	 * Hook: tcgiant_sync_update_ebay_stock - a push queued before 3.28.0, by
	 * SKU and quantity.
	 *
	 * The SKU only finds the product; the product's own listing and its
	 * stock now are what is sent, not the quantity queued, which may be
	 * hours old. A SKU that more than one product answers to is not guessed
	 * at: it is said, and nothing is sent.
	 *
	 * @param string $sku      The SKU queued.
	 * @param int    $quantity The quantity queued; not used.
	 * @return void
	 */
	public function process_ebay_stock_update( $sku, $quantity = null ) {
		// A staging copy sends nothing, and spends nothing finding out what it
		// would have sent - not even a database read.
		$blocked = TCGiant_Sync_Environment::write_block();
		if ( $blocked ) {
			TCGiant_Sync_Environment::note_blocked( 'Stock change for SKU ' . $sku, $blocked );
			return;
		}

		$product_id = $this->find_legacy_push_product( (string) $sku );

		if ( $product_id ) {
			$this->process_stock_push( array( 'product_id' => $product_id, 'reason' => 'edit' ) );
		}
	}

	/**
	 * The one product an old SKU-and-quantity push is about: by the SKU eBay
	 * knows it by, by its WooCommerce SKU, or by the listing it follows - the
	 * number in an EBAY- SKU, or the SKU itself where that is the item
	 * number. Trashed products are left out. Every product any of the three
	 * finds is counted: one product's eBay SKU that is another's WooCommerce
	 * SKU names both, and is not sent to either.
	 *
	 * @param string $sku The SKU queued.
	 * @return int Product, or 0 when none or more than one has it.
	 */
	private function find_legacy_push_product( $sku ) {
		global $wpdb;

		if ( '' === $sku ) {
			return 0;
		}

		$item_id = 1 === preg_match( '/^EBAY-(\d+)$/', $sku, $m ) ? $m[1] : $sku;
		$found   = array();

		foreach ( array( '_ebay_sku' => $sku, '_sku' => $sku, '_ebay_item_id' => $item_id ) as $key => $value ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_status != 'trash'
				 WHERE pm.meta_key = %s AND pm.meta_value = %s LIMIT 2",
				$key,
				$value
			) );
			foreach ( (array) $ids as $id ) {
				$found[ (int) $id ] = true;
			}
		}

		$found = array_keys( $found );
		sort( $found );

		if ( count( $found ) > 1 ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'A stock change queued before the update to 3.28.0 for SKU %s matches WC #%s, so it was not sent to eBay. Edit the stock of the product it was for to send it.',
				$sku,
				implode( ' and #', $found )
			) );
			return 0;
		}

		if ( $found ) {
			return $found[0];
		}

		TCGiant_Sync_Logger::log( sprintf( 'A stock change queued before the update to 3.28.0 for SKU %s matches no product, so nothing was sent to eBay.', $sku ) );

		return 0;
	}

	// ───────────────────────────────────────────────────────────────────────────
	// eBay auctions
	//
	// A product on a running auction is not for sale on the website, and stock
	// pushes treat an auction as what it is: one item, which only ever runs
	// out. The stock push's own rule for one is push_auction(), reached
	// through push_target() like every other push.
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Whether a product follows an eBay auction.
	 *
	 * @param mixed $product A product, or anything else (false for that).
	 * @return bool
	 */
	public static function is_auction_listing( $product ) {
		return $product instanceof WC_Product
			&& 'Chinese' === (string) $product->get_meta( '_ebay_listing_type' )
			&& '' !== (string) $product->get_meta( '_ebay_item_id' );
	}

	/**
	 * Whether eBay's final figures for the auction a product follows are in
	 * its stock - the auction is over and nothing more can come of it.
	 *
	 * The settle mark, not the listing status. The hourly check records a
	 * listing as Ended when it cannot read it from eBay, and so did a stock
	 * push whose EndItem eBay refused; either way the auction may still go to
	 * a bidder.
	 *
	 * A settle from before 3.27.0 did not note which listing it was for. It
	 * was only ever written from eBay's final figures, so it counts - for the
	 * listing recorded as Ended, and only if it was written after that
	 * listing's end: relists then did not clear it, and an older one can sit
	 * on a product that has moved on to a newer auction.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private static function auction_settled( $product ) {
		$settled_at = (string) $product->get_meta( '_ebay_stock_settled_at' );
		if ( '' === $settled_at ) {
			return false;
		}

		$settled_item = (string) $product->get_meta( '_ebay_stock_settled_item' );
		if ( '' !== $settled_item ) {
			return (string) $product->get_meta( '_ebay_item_id' ) === $settled_item;
		}

		if ( 'Ended' !== (string) $product->get_meta( '_ebay_listing_status' ) ) {
			return false;
		}

		$end = strtotime( (string) $product->get_meta( '_ebay_end_time' ) );

		return ! $end || (int) get_gmt_from_date( $settled_at, 'U' ) >= $end;
	}

	/**
	 * Whether the auction is over, for what the storefront says - never for
	 * whether the product can be bought. Settled, or recorded as Ended with its
	 * end time passed: eBay runs no auction past its end. An Ended record with
	 * no end time, or one still ahead, is no evidence that it is over.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private static function auction_over( $product ) {
		if ( self::auction_settled( $product ) ) {
			return true;
		}

		if ( 'Ended' !== (string) $product->get_meta( '_ebay_listing_status' ) ) {
			return false;
		}

		$end = strtotime( (string) $product->get_meta( '_ebay_end_time' ) );

		return $end && $end <= time();
	}

	/**
	 * Whether a product cannot be bought on the website because of its eBay
	 * auction.
	 *
	 * While the auction runs, or might still be running: always. Once it is
	 * over and settled, a product the shop listed at auction from WooCommerce
	 * goes back on sale at its own price, and one imported from an eBay
	 * auction does not - its price is eBay's opening bid, not one the shop
	 * set. Which it is goes by the auction itself, not by whether the product
	 * was ever pushed: an Update pushed to an imported auction does not make
	 * it the shop's, nor does a push of the fixed-price listing the product
	 * had before the seller put it up at auction on eBay.
	 *
	 * @param mixed $product Product.
	 * @return bool
	 */
	public static function auction_blocks_purchase( $product ) {
		if ( ! self::is_auction_listing( $product ) ) {
			return false;
		}

		if ( ! self::auction_settled( $product ) ) {
			return true;
		}

		return (string) $product->get_meta( self::AUCTION_LISTED_KEY ) !== (string) $product->get_meta( '_ebay_item_id' );
	}

	/**
	 * woocommerce_is_purchasable: never for a product its auction blocks.
	 *
	 * Only ever turns a yes into a no. WooCommerce asks again when an item is
	 * added to the cart, and drops one already in a cart when the cart is next
	 * loaded, so a cached page still showing Add to cart cannot sell it.
	 *
	 * @param bool  $purchasable What WooCommerce and others decided.
	 * @param mixed $product     The product.
	 * @return bool
	 */
	public static function filter_purchasable( $purchasable, $product = null ) {
		if ( ! $purchasable || ! $product instanceof WC_Product ) {
			return $purchasable;
		}

		return ! self::auction_blocks_purchase( $product );
	}

	/**
	 * woocommerce_get_price_html: a running auction shows the auction - its
	 * current bid, or its opening bid before anyone has bid - rather than a
	 * price it cannot be bought at.
	 *
	 * @param string $html    WooCommerce's price.
	 * @param mixed  $product The product.
	 * @return string
	 */
	public static function filter_price_html( $html, $product = null ) {
		if ( ! $product instanceof WC_Product || ! self::auction_blocks_purchase( $product ) || self::auction_over( $product ) ) {
			return $html;
		}

		return '<span class="tcgiant-auction-price">' . self::auction_label( $product ) . '</span>';
	}

	/**
	 * In place of Add to cart on the product page: the auction, and a link to
	 * it on eBay.
	 *
	 * @return void
	 */
	public static function render_auction_notice() {
		global $product;

		if ( ! $product instanceof WC_Product || ! self::auction_blocks_purchase( $product ) ) {
			return;
		}

		$url   = 'https://www.ebay.com/itm/' . rawurlencode( (string) $product->get_meta( '_ebay_item_id' ) );
		$ended = self::auction_over( $product );
		$text  = $ended ? esc_html__( 'This auction has ended on eBay.', 'tcgiant-sync' ) : self::auction_label( $product );
		$link  = $ended ? __( 'View it on eBay', 'tcgiant-sync' ) : __( 'Bid on eBay', 'tcgiant-sync' );

		echo '<p class="tcgiant-auction-notice">' . $text . ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $link ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped text and wc_price().
	}

	/**
	 * "Auction on eBay - current bid $37.00 (4 bids)", as escaped HTML.
	 *
	 * eBay's own figures, recorded by the import. A product listed from
	 * WooCommerce that has not been imported since has none yet, and shows its
	 * regular price, which is the opening bid it was listed with. One imported
	 * before 3.28.0 has none either, and its price is the bid it had reached
	 * then: it shows no figure until it is next imported.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function auction_label( $product ) {
		$bids    = (int) $product->get_meta( '_ebay_bid_count' );
		$current = (string) $product->get_meta( '_ebay_current_price' );
		$opening = (string) $product->get_meta( '_ebay_start_price' );
		$bin     = (string) $product->get_meta( '_ebay_buy_it_now_price' );

		// Before the first bid, eBay's current price is the opening bid.
		if ( ! is_numeric( $opening ) && 0 === $bids && '' !== (string) $product->get_meta( '_ebay_bid_count' ) && is_numeric( $current ) ) {
			$opening = $current;
		}

		// The shop's own price is the opening bid only while no import has
		// written over it since the shop listed it: an import before 3.28.0
		// priced an auction at its current bid.
		if ( ! is_numeric( $opening )
			&& (string) $product->get_meta( '_ebay_export_last_pushed' ) > (string) $product->get_meta( '_sync_last_updated' ) ) {
			$opening = (string) $product->get_regular_price();
		}

		if ( $bids > 0 && is_numeric( $current ) ) {
			$label = sprintf(
				/* translators: 1: current bid, as a price, 2: number of bids */
				esc_html( _n( 'Auction on eBay - current bid %1$s (%2$d bid)', 'Auction on eBay - current bid %1$s (%2$d bids)', $bids, 'tcgiant-sync' ) ),
				wc_price( (float) $current ),
				$bids
			);
		} elseif ( is_numeric( $opening ) ) {
			$label = sprintf(
				/* translators: %s: opening bid, as a price */
				esc_html__( 'Auction on eBay - opening bid %s', 'tcgiant-sync' ),
				wc_price( (float) $opening )
			);
		} else {
			$label = esc_html__( 'Auction on eBay', 'tcgiant-sync' );
		}

		if ( is_numeric( $bin ) && (float) $bin > 0 ) {
			$label .= ' ' . sprintf(
				/* translators: %s: Buy It Now price */
				esc_html__( 'or Buy It Now %s', 'tcgiant-sync' ),
				wc_price( (float) $bin )
			);
		}

		return $label;
	}

	/**
	 * Send a stock change to the eBay auction a product follows - the stock
	 * push's rule for an auction. Reached through push_target() for a product
	 * recorded as on an auction, and from any push whose GetItem says the
	 * listing is one, whatever the product says.
	 *
	 * An auction is always one item, so a quantity is never sent: eBay's
	 * quantity call is for fixed-price listings. Only running out matters, and
	 * then the auction is ended only when nobody has bid. Ending it with bids
	 * would cancel every one of them, and eBay will not end it at all in its
	 * last 12 hours - so it is left running and the shop is told, in the log,
	 * to end it in Seller Hub if the item has gone.
	 *
	 * Nothing is recorded as ended unless eBay ended it, or GetItem says it
	 * is over. A failed EndItem used to be written down as an ended, settled
	 * listing, so after a restock nothing local knew the auction was still
	 * running; and "already ended" was read from the words of eBay's answer.
	 *
	 * @param array      $job      The push.
	 * @param array      $target   From push_target().
	 * @param int        $wc       Its stock now.
	 * @param bool       $auto_end "Auto-end listing when stock reaches 0".
	 * @param array|null $item     GetItem's Item, when the push has it.
	 * @return array See push_outcome().
	 */
	private function push_auction( array $job, array $target, $wc, $auto_end, $item = null ) {
		$product_id = (int) $target['owner_id'];
		$item_id    = $target['item_id'];
		$product    = wc_get_product( $product_id );

		if ( (int) $wc > 0 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'WC #%d is on eBay auction %s. An auction is always one item, so no quantity is sent.',
				$product_id,
				$item_id
			) );
			return self::push_outcome( 'skipped', 'eBay auction - quantities are not sent to auctions' );
		}

		// Over, and its final figures in, by 3.28.0's own record. A mark 3.27
		// left - it wrote one for an EndItem eBay refused - or an Ended the
		// hourly check wrote when it could not read the auction is not taken
		// on trust: eBay is asked below, as it is for the bids.
		if ( ! is_array( $item ) && $product && self::auction_settled( $product ) && self::settle_trusted( $product_id ) ) {
			TCGiant_Sync_Logger::log( sprintf(
				'WC #%d has no stock. Its eBay auction %s is already recorded as ended, so nothing was sent.',
				$product_id,
				$item_id
			) );
			return self::push_outcome( 'skipped', 'Auction already ended' );
		}

		if ( ! $auto_end ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'WC #%d has no stock, but its eBay auction %s is still running. "Auto-end listing when stock reaches 0" is off, so it was left running; if the item has gone, end it in Seller Hub.',
				$product_id,
				$item_id
			) );
			return self::push_outcome( 'skipped', 'Auction left running - auto-end is off' );
		}

		// Whether anyone has bid decides it, so eBay is asked - unless the push
		// has just asked.
		if ( ! is_array( $item ) ) {
			self::beat();
			$response = TCGiant_Sync_API::instance()->get_item( $item_id );

			if ( is_wp_error( $response ) || empty( $response['Item'] ) || ! is_array( $response['Item'] ) ) {
				$error = is_wp_error( $response ) ? $response : new WP_Error( 'no_item', 'eBay returned nothing' );

				if ( array_intersect( TCGiant_Sync_API::error_codes( $error ), array( '17', '231' ) ) ) {
					TCGiant_Sync_Logger::error( sprintf( 'WC #%d has no stock, but eBay auction %s cannot be found on this eBay account (%s), so nothing was sent.', $product_id, $item_id, $error->get_error_message() ) );
					return self::push_outcome( 'error', $error->get_error_message() );
				}

				return self::push_outcome( 'retry', sprintf( 'WC #%d has no stock, but eBay could not be asked whether auction %s has bids (%s), so it was not ended yet', $product_id, $item_id, $error->get_error_message() ), array( 'error' => $error ) );
			}

			$item = $response['Item'] + array( 'ItemID' => $item_id );
		}

		$status = TCGiant_Sync_API::scalar_value( $item['SellingStatus']['ListingStatus'] ?? '' );

		// Over already: its final figures, from this answer.
		if ( '' !== $status && 'Active' !== $status ) {
			TCGiant_Sync_Importer::settle_ended_from_item( $product_id, $item, 'Stock push' );
			TCGiant_Sync_Logger::log( sprintf(
				'WC #%d has no stock. eBay auction %s has already ended (%s); its final figures were brought in.',
				$product_id,
				$item_id,
				$status
			) );
			return self::push_outcome( 'skipped', sprintf( 'Auction already ended on eBay (%s)', $status ) );
		}

		// Still running, though recorded here as over: put right, as
		// read_listing() does for a fixed-price listing.
		if ( 'Ended' === (string) get_post_meta( $product_id, '_ebay_listing_status', true ) ) {
			update_post_meta( $product_id, '_ebay_listing_status', 'Active' );
			self::clear_settled_mark( $product_id );
			TCGiant_Sync_Logger::log( sprintf( 'Stock push: eBay auction %s is still running, though WC #%d had it recorded as ended; recorded as running again.', $item_id, $product_id ) );
		}

		$bids = (int) TCGiant_Sync_API::scalar_value( $item['SellingStatus']['BidCount'] ?? '0' );

		if ( $bids > 0 ) {
			TCGiant_Sync_Logger::error( sprintf(
				'WC #%1$d has no stock, but eBay auction %2$s has %3$d bid(s). Ending it from here would cancel every bid, and eBay will not end it at all in its last 12 hours, so it was left running. If the item has gone, end it in Seller Hub.',
				$product_id,
				$item_id,
				$bids
			) );
			return self::push_outcome( 'skipped', sprintf( 'eBay auction has %d bid(s) - left running; end it in Seller Hub if the item has gone', $bids ) );
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Auto-ending eBay auction %s (WC #%d): stock reached 0 and nobody has bid.',
			$item_id,
			$product_id
		) );

		self::beat();
		$result = TCGiant_Sync_API::instance()->end_item( $item_id );

		if ( ! is_wp_error( $result ) ) {
			self::landed( $job );
			self::record_stock_end( $product_id, $item_id, $result );
			return self::push_outcome( 'success', 'eBay auction ended (sold out)' );
		}

		// Refused before it was sent, and logged where it was refused.
		if ( 'staging_blocked' === $result->get_error_code() ) {
			return self::push_outcome( 'skipped', $result->get_error_message() );
		}

		$again = sprintf( 'Could not end eBay auction %s for WC #%d: %s. It is still recorded as running', $item_id, $product_id, $result->get_error_message() );

		if ( TCGiant_Sync_API::is_transient_error( $result ) ) {
			return self::push_outcome( 'retry', $again, array( 'error' => $result, 'ended_try' => true ) );
		}

		// Whether it was over all along is eBay's to say, not its wording's.
		self::beat();
		$check = TCGiant_Sync_API::instance()->get_item( $item_id );
		if ( ! is_wp_error( $check ) && ! empty( $check['Item'] ) && is_array( $check['Item'] ) ) {
			$after = TCGiant_Sync_API::scalar_value( $check['Item']['SellingStatus']['ListingStatus'] ?? '' );
			if ( '' !== $after && 'Active' !== $after ) {
				TCGiant_Sync_Importer::settle_ended_from_item( $product_id, $check['Item'] + array( 'ItemID' => $item_id ), 'Stock push' );
				TCGiant_Sync_Logger::log( sprintf( 'eBay auction %s had already ended (%s) - its final figures were brought in.', $item_id, $after ) );
				return self::push_outcome( 'skipped', sprintf( 'Auction already ended on eBay (%s)', $after ) );
			}
		}

		return self::push_outcome( 'retry', $again, array( 'error' => $result, 'ended_try' => true ) );
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
	 * What a product needs to know about the listing it is about to follow,
	 * read from eBay's answer for that listing.
	 *
	 * @param array $item The listing, as GetItem returns it.
	 * @return array status ('Active' or 'Ended'), start_time, end_time,
	 *               listing_type and listing_duration - each '' when eBay did
	 *               not say.
	 */
	public static function listing_facts( array $item ) {
		$status = TCGiant_Sync_API::scalar_value( $item['SellingStatus']['ListingStatus'] ?? '' );

		return array(
			'status'           => ( '' === $status || 'Active' === $status ) ? 'Active' : 'Ended',
			'start_time'       => TCGiant_Sync_API::scalar_value( $item['ListingDetails']['StartTime'] ?? '' ),
			'end_time'         => TCGiant_Sync_API::scalar_value( $item['ListingDetails']['EndTime'] ?? '' ),
			'listing_type'     => TCGiant_Sync_API::scalar_value( $item['ListingType'] ?? '' ),
			'listing_duration' => TCGiant_Sync_API::scalar_value( $item['ListingDuration'] ?? '' ),
		);
	}

	/**
	 * Move a product from an ended eBay listing on to the listing eBay relisted
	 * it as.
	 *
	 * A seller who relists on eBay - Relist, or Sell Similar from an ended
	 * listing - gets a new item number for the same goods. Nothing tied the new
	 * number to the product that sold the old one, so the import made a second
	 * product for it, and the first sat on with the old listing's stock: a
	 * website sale of it went to the ended listing and never reached the live
	 * one, and the coin could sell twice. This is the one place a product is
	 * moved on to a relist, whichever part of the plugin noticed it - the
	 * import, the delta sync, the per-sale stock check or a relist from the
	 * Listings screen - so they cannot disagree about what moving means.
	 *
	 * Everything recorded about the old listing's ending is forgotten, as when
	 * the plugin relists itself: those figures describe a listing the product no
	 * longer follows, and applying them later would wipe a restock.
	 *
	 * @param int    $product_id  Product to move.
	 * @param string $old_item_id The listing it followed.
	 * @param string $new_item_id The relist it follows from now on.
	 * @param array  $facts       See listing_facts().
	 * @param string $source      Who noticed, for the log: "Import", "Delta"...
	 * @return void
	 */
	public static function follow_relist( $product_id, $old_item_id, $new_item_id, array $facts, $source ) {
		$product_id  = (int) $product_id;
		$old_item_id = (string) $old_item_id;
		$new_item_id = (string) $new_item_id;

		if ( $product_id < 1 || '' === $old_item_id || '' === $new_item_id || $old_item_id === $new_item_id ) {
			return;
		}

		$status = 'Ended' === ( $facts['status'] ?? '' ) ? 'Ended' : 'Active';

		update_post_meta( $product_id, '_ebay_item_id', $new_item_id );
		// The same key a push writes when it replaces an ended listing, so the
		// two kinds of relist read back alike.
		update_post_meta( $product_id, '_ebay_previous_item_id', $old_item_id );
		update_post_meta( $product_id, '_ebay_listing_status', $status );
		// A relist keeps the description eBay had, so the record of what was
		// sent follows the listing to its new number, as when the plugin
		// relists it (3.27.8, TCGiant_Sync_Exporter::relist_ended_listing()).
		if ( (string) get_post_meta( $product_id, '_tcgiant_tpl_item', true ) === $old_item_id ) {
			update_post_meta( $product_id, '_tcgiant_tpl_item', $new_item_id );
		}

		foreach ( array(
			'_ebay_start_time'       => 'start_time',
			'_ebay_listing_type'     => 'listing_type',
			'_ebay_listing_duration' => 'listing_duration',
		) as $meta_key => $fact ) {
			if ( '' !== (string) ( $facts[ $fact ] ?? '' ) ) {
				update_post_meta( $product_id, $meta_key, (string) $facts[ $fact ] );
			}
		}

		// The type is eBay's word for the new listing, or not known for it.
		if ( '' !== (string) ( $facts['listing_type'] ?? '' ) ) {
			update_post_meta( $product_id, self::TYPE_ITEM_KEY, $new_item_id );
			if ( 'Chinese' === (string) $facts['listing_type'] ) {
				self::auctions_seen();
			}
		} else {
			delete_post_meta( $product_id, self::TYPE_ITEM_KEY );
		}

		// The new listing's end, or none. The old one's would have the hourly
		// check mark a fresh seven-day relist Ended the moment the old date
		// passed.
		$end_time = (string) ( $facts['end_time'] ?? '' );
		if ( '' !== $end_time ) {
			update_post_meta( $product_id, '_ebay_end_time', $end_time );
		} else {
			delete_post_meta( $product_id, '_ebay_end_time' );
		}

		// A new listing: what was settled about the old one no longer applies.
		self::clear_settled_mark( $product_id );
		delete_transient( 'tcgiant_restocked_after_sale' );

		// A refusal to remove the product was about the old listing too. Left
		// in place, the same refusal on the new one would never be said.
		delete_post_meta( $product_id, '_ebay_removal_refused' );

		// So is a stock warning still owed for the old listing's move - see
		// TCGiant_Sync_Mapper::adopt_relist().
		delete_post_meta( $product_id, '_ebay_relist_stock_check' );

		// And the old auction's bids and prices: shown on a new auction they
		// would name bids nobody has made. The import of the new listing
		// records its own.
		foreach ( self::AUCTION_FIGURE_KEYS as $key ) {
			delete_post_meta( $product_id, $key );
		}

		// An auction the shop listed from WooCommerce, relisted on eBay, is
		// still the shop's - its opening bid is the one the shop pushed.
		if ( (string) get_post_meta( $product_id, self::AUCTION_LISTED_KEY, true ) === $old_item_id
			&& 'Chinese' === (string) ( $facts['listing_type'] ?? '' ) ) {
			update_post_meta( $product_id, self::AUCTION_LISTED_KEY, $new_item_id );
		}

		self::follow_item_number_sku( $product_id, $old_item_id, $new_item_id );

		TCGiant_Sync_DB::upsert( array(
			'product_id'      => $product_id,
			'ebay_item_id'    => $new_item_id,
			'listing_status'  => $status,
			'ebay_url'        => 'https://www.ebay.com/itm/' . $new_item_id,
			'ebay_start_time' => (string) ( $facts['start_time'] ?? '' ),
			'ebay_end_time'   => $end_time,
			'last_synced'     => current_time( 'mysql' ),
		) );

		TCGiant_Sync_Logger::log( sprintf(
			'%s: eBay listing %s is a relist of %s; WC #%d now follows the new listing, so the item stays one product.',
			$source,
			$new_item_id,
			$old_item_id,
			$product_id
		), 'success' );
	}

	/**
	 * Carry a SKU made from the eBay item number over to the new number.
	 *
	 * A listing with no SKU of its own is imported with its item number as the
	 * SKU, or "EBAY-" and the number. The second form is read as an address: a
	 * stock change is sent straight to the listing it names. Left as it was,
	 * every stock change on a relisted product would go to the listing that had
	 * ended - so a website sale would never reach the live one. And an older
	 * listing's number left as the SKU reads as goods of the shop's own, which
	 * a relist carrying a SKU of the seller's own does not match.
	 *
	 * Used by every move to a new listing: follow_relist(), and a relist or a
	 * push that replaces an ended listing.
	 *
	 * @param int    $product_id  Product.
	 * @param string $old_item_id The listing it followed.
	 * @param string $new_item_id The listing it follows now.
	 * @return void
	 */
	public static function follow_item_number_sku( $product_id, $old_item_id, $new_item_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$forms = array(
			$old_item_id           => $new_item_id,
			'EBAY-' . $old_item_id => 'EBAY-' . $new_item_id,
		);

		$sku      = (string) $product->get_sku();
		$ebay_sku = (string) get_post_meta( $product_id, '_ebay_sku', true );
		$new_sku  = ( '' !== $sku && isset( $forms[ $sku ] ) ) ? $forms[ $sku ] : '';
		$new_ebay = ( '' !== $ebay_sku && isset( $forms[ $ebay_sku ] ) ) ? $forms[ $ebay_sku ] : '';

		if ( '' === $new_sku && '' === $new_ebay ) {
			return;
		}

		// Never take a SKU another product already has. Two products with one
		// SKU would send one's stock to the other's listing.
		foreach ( array_unique( array_filter( array( $new_sku, $new_ebay ) ) ) as $wanted ) {
			$holder = (int) wc_get_product_id_by_sku( $wanted );
			if ( $holder && $holder !== $product_id ) {
				TCGiant_Sync_Logger::warning( sprintf(
					'WC #%1$d now follows eBay listing %2$s, but its SKU still names the old listing %3$s: "%4$s" is already the SKU of WC #%5$d. Give one of them a different SKU so stock changes reach listing %2$s.',
					$product_id,
					$new_item_id,
					$old_item_id,
					$wanted,
					$holder
				) );
				return;
			}
		}

		// Not a stock change, but saved as the sync's own work all the same,
		// so nothing is sent back to eBay for it.
		self::begin_ebay_origin();

		try {
			if ( '' !== $new_sku ) {
				$product->set_sku( $new_sku );
				// A product save like any other, round the save-loop breaker
				// (3.27.4) for every caller: a push or a relist that follows a
				// relist, and the sale check moving a product on to one, are
				// not inside a guarded run of their own.
				TCGiant_Sync_Runtime::guard_loops();
				try {
					$product->save();
				} finally {
					TCGiant_Sync_Runtime::unguard_loops();
				}
			}
			if ( '' !== $new_ebay ) {
				update_post_meta( $product_id, '_ebay_sku', $new_ebay );
			}
		} catch ( Exception $e ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'WC #%d now follows eBay listing %s, but its SKU could not be changed from "%s" (%s). Change it by hand so stock changes reach the new listing.',
				$product_id,
				$new_item_id,
				$sku,
				$e->getMessage()
			) );
		} finally {
			self::end_ebay_origin();
		}
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
		//
		// Whatever its latest push is doing. A push says "pushed" only between
		// pushes: a bulk push marks each product queued, a push marks it
		// pushing before it asks eBay, and one that dies leaves it failed - so
		// a product pushed months ago, selling out while queued for a re-push,
		// was trashed. _ebay_export_last_pushed is written only by a push that
		// succeeded, or by Link completing one that did not, so it holds
		// through all of that. A push still pushing, or one that did not
		// finish, may well have made the listing that sold, even on its first
		// push. Queued alone is left out: an imported product queued from its
		// own screen has always been removable.
		$export_status = (string) get_post_meta( $product_id, '_ebay_export_status', true );
		if ( in_array( $export_status, array( 'pushed', 'pushing', 'failed' ), true )
			|| '' !== (string) get_post_meta( $product_id, '_ebay_export_last_pushed', true ) ) {
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

		// Trashing goes through wp_update_post(), and its save_post as well.
		TCGiant_Sync_Runtime::guard_loops();

		try {
			wp_trash_post( $product_id );
		} finally {
			TCGiant_Sync_Runtime::unguard_loops();
		}

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
		TCGiant_Sync_Runtime::guard_loops();

		try {
			if ( $product->managing_stock() ) {
				wc_update_product_stock( (int) $product_id, 0, 'set' );
			}
			wc_update_product_stock_status( (int) $product_id, 'outofstock' );
		} finally {
			TCGiant_Sync_Runtime::unguard_loops();
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
		TCGiant_Sync_Runtime::guard_loops();

		try {
			if ( $product->managing_stock() ) {
				wc_update_product_stock( (int) $product_id, $figure, 'set' );
			}
			wc_update_product_stock_status( (int) $product_id, $figure > 0 ? 'instock' : 'outofstock' );
		} finally {
			TCGiant_Sync_Runtime::unguard_loops();
			self::end_ebay_origin();
		}

		delete_post_meta( (int) $product_id, '_ebay_unsold_at_end' );

		return $figure;
	}

	/**
	 * Products that sold out on eBay and are still in the catalogue, that the
	 * sold-out cleanup would move to the trash.
	 *
	 * The backlog left by removal having lived on one code path. Every one of
	 * these was settled from eBay's own Quantity and QuantitySold for the
	 * listing it still follows, and came out at nothing, which is the
	 * sold-out test - so the judgement is already made and recorded, and
	 * nothing here needs asking again.
	 *
	 * Only those. A settle from before 3.27.0 recorded no figures, and "Ended,
	 * out of stock and settled" was taken for an eBay sell-out - but a coin
	 * sold on the website and settled then looks exactly the same. Those are
	 * counted (count_sold_out_unverified()) and left alone.
	 *
	 * And not a product remove_if_allowed() would keep whatever it was asked:
	 * one the shop pushed to eBay, or one in a Preserve category. They were
	 * listed, counted in the panel's headline and offered to its button,
	 * which then kept every one of them.
	 *
	 * Deliberately not acted on automatically. A shop should be shown the list
	 * and asked, not told afterwards that an upgrade trashed some products.
	 *
	 * @param int $limit 0 for all.
	 * @return int[] Product ids.
	 */
	public static function find_sold_out_not_removed( $limit = 0 ) {
		global $wpdb;

		$sql = self::sold_out_sql( 'SELECT p.ID', true ) . ' ORDER BY p.ID DESC';

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql . ' LIMIT %d', (int) $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Products ended, out of stock and settled before 3.27.0, with no record
	 * of eBay's figures: what the sold-out list used to take for eBay
	 * sell-outs. Some sold on the website instead, and nothing here can tell
	 * which, so Stock Review lists them apart, for the shop to check one by
	 * one and trash by hand - the sold-out button never takes them (and
	 * sold_out_on_ebay() refuses them). The same keeps as the verified list:
	 * not a product the shop pushed, nor one in a Preserve category.
	 *
	 * @param int $limit 0 for all.
	 * @return int[] Product ids, newest first.
	 */
	public static function find_sold_out_unverified( $limit = 0 ) {
		global $wpdb;

		$sql = self::sold_out_sql( 'SELECT p.ID', false ) . ' ORDER BY p.ID DESC';

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql . ' LIMIT %d', (int) $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * How many are in that list.
	 *
	 * @return int
	 */
	public static function count_sold_out_unverified() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( self::sold_out_sql( 'SELECT COUNT(*)', false ) );
	}

	/**
	 * The queries above.
	 *
	 * @param string $select   What to select.
	 * @param bool   $verified eBay's figures for the listing the product
	 *                         follows say it sold out (true), or a settle
	 *                         from before 3.27.0 left none (false).
	 * @return string SQL.
	 */
	private static function sold_out_sql( $select, $verified ) {
		global $wpdb;

		$figures = $verified
			// Out of stock because eBay sold it, by eBay's own figures for the
			// listing it follows - not because the website did.
			? "sitem.meta_value = item.meta_value
			  AND CAST( qlisted.meta_value AS SIGNED ) >= 1
			  AND CAST( qsold.meta_value AS SIGNED ) >= CAST( qlisted.meta_value AS SIGNED )"
			// No figures and no listing named: settled before 3.27.0.
			: 'qlisted.meta_id IS NULL AND sitem.meta_id IS NULL';

		// remove_if_allowed()'s own rules, so a list is what the shop may
		// remove: not a product the shop pushed to eBay - whatever its latest
		// push is doing - nor one in a Preserve category.
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$preserve = is_array( $settings ) && isset( $settings['preserve_woo_category_ids'] ) && is_array( $settings['preserve_woo_category_ids'] )
			? array_values( array_filter( array_map( 'absint', $settings['preserve_woo_category_ids'] ) ) )
			: array();

		$keeps = "
			  AND ( pushed.meta_value IS NULL OR pushed.meta_value NOT IN ( 'pushed', 'pushing', 'failed' ) )
			  AND ( lastpush.meta_value IS NULL OR lastpush.meta_value = '' )";

		if ( $preserve ) {
			$keeps .= "
			  AND NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr
			        INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			        WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_cat' AND tt.term_id IN ( " . implode( ', ', $preserve ) . ' ) )';
		}

		return "{$select}
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} listing ON listing.post_id = p.ID AND listing.meta_key = '_ebay_listing_status'
			INNER JOIN {$wpdb->postmeta} settled ON settled.post_id = p.ID AND settled.meta_key = '_ebay_stock_settled_at'
			INNER JOIN {$wpdb->postmeta} stock   ON stock.post_id   = p.ID AND stock.meta_key   = '_stock_status'
			LEFT JOIN  {$wpdb->postmeta} item    ON item.post_id    = p.ID AND item.meta_key    = '_ebay_item_id'
			LEFT JOIN  {$wpdb->postmeta} qlisted ON qlisted.post_id = p.ID AND qlisted.meta_key = '_ebay_quantity_listed'
			LEFT JOIN  {$wpdb->postmeta} qsold   ON qsold.post_id   = p.ID AND qsold.meta_key   = '_ebay_quantity_sold'
			LEFT JOIN  {$wpdb->postmeta} zeroed  ON zeroed.post_id  = p.ID AND zeroed.meta_key  = '_ebay_restock_reviewed' AND zeroed.meta_value = 'zeroed'
			LEFT JOIN  {$wpdb->postmeta} sitem   ON sitem.post_id   = p.ID AND sitem.meta_key   = '_ebay_stock_settled_item'
			LEFT JOIN  {$wpdb->postmeta} pushed  ON pushed.post_id  = p.ID AND pushed.meta_key  = '_ebay_export_status'
			LEFT JOIN  {$wpdb->postmeta} lastpush ON lastpush.post_id = p.ID AND lastpush.meta_key = '_ebay_export_last_pushed'
			WHERE p.post_type = 'product'
			  AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			  AND listing.meta_value = 'Ended'
			  AND stock.meta_value = 'outofstock'
			  AND settled.meta_value <> ''
			  AND {$figures}
			  -- Set to 0 from Stock Review as a website sale: not eBay's to remove.
			  AND zeroed.meta_id IS NULL{$keeps}";
	}

	/**
	 * Whether a product is, right now, what the sold-out cleanup may move to
	 * the trash: its listing recorded as Ended, out of stock, and eBay's own
	 * final figures for that listing - the one it still follows - saying
	 * every unit sold. Not one set to 0 from Stock Review as a website sale.
	 *
	 * Asked of each product as the cleanup reaches it. The list was the one
	 * drawn when the button was pressed, or whatever ids the request named,
	 * and a product relisted, restocked or put right since was trashed on the
	 * strength of what it had been.
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function sold_out_on_ebay( $product_id ) {
		$product_id = (int) $product_id;
		$status     = get_post_status( $product_id );

		if ( ! $status || in_array( $status, array( 'trash', 'auto-draft' ), true ) || 'product' !== get_post_type( $product_id ) ) {
			return false;
		}

		$item = (string) get_post_meta( $product_id, '_ebay_item_id', true );

		if ( '' === $item
			|| 'Ended' !== (string) get_post_meta( $product_id, '_ebay_listing_status', true )
			|| 'outofstock' !== (string) get_post_meta( $product_id, '_stock_status', true )
			|| '' === (string) get_post_meta( $product_id, '_ebay_stock_settled_at', true )
			|| $item !== (string) get_post_meta( $product_id, '_ebay_stock_settled_item', true )
			|| 'zeroed' === (string) get_post_meta( $product_id, '_ebay_restock_reviewed', true ) ) {
			return false;
		}

		$listed = get_post_meta( $product_id, '_ebay_quantity_listed', true );
		$sold   = get_post_meta( $product_id, '_ebay_quantity_sold', true );

		return is_numeric( $listed ) && is_numeric( $sold ) && (int) $listed >= 1 && (int) $sold >= (int) $listed;
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
	 * Stock pushes eBay still refused after every try (PUSH_FAILURES_OPTION),
	 * newest first, for Stock Review to list. Each as record_push_failure()
	 * wrote it.
	 *
	 * @return array[]
	 */
	public static function push_failures() {
		$failures = get_option( self::PUSH_FAILURES_OPTION, array() );

		if ( ! is_array( $failures ) ) {
			return array();
		}

		return array_reverse( array_values( array_filter( $failures, function ( $row ) {
			return is_array( $row ) && ! empty( $row['product_id'] );
		} ) ) );
	}

	/**
	 * Clear that list, when the shop has dealt with it. Nothing else changes.
	 *
	 * @return void
	 */
	public static function forget_push_failures() {
		delete_option( self::PUSH_FAILURES_OPTION );
	}

	/**
	 * Take a product's rows for a listing off that list: a later push of it
	 * to that listing reached eBay. Only that listing's - a row naming none
	 * goes too - so a push to the listing a product follows now does not
	 * drop the row of another it was linked to, which may still be live and
	 * still showing more than the shop holds. Written only when there was
	 * one to take off.
	 *
	 * @param int    $product_id The product the push was for.
	 * @param string $item_id    The listing it reached.
	 * @return void
	 */
	private static function forget_push_failure( $product_id, $item_id ) {
		$failures = get_option( self::PUSH_FAILURES_OPTION, array() );

		if ( ! is_array( $failures ) || empty( $failures ) ) {
			return;
		}

		$kept = array_values( array_filter( $failures, function ( $row ) use ( $product_id, $item_id ) {
			if ( ! is_array( $row ) || (int) ( $row['product_id'] ?? 0 ) !== (int) $product_id ) {
				return true;
			}
			$row_item = (string) ( $row['item_id'] ?? '' );
			return '' !== $row_item && $row_item !== (string) $item_id;
		} ) );

		if ( count( $kept ) !== count( $failures ) ) {
			update_option( self::PUSH_FAILURES_OPTION, $kept, false );
		}
	}

	/**
	 * How find_relist_pairs() pages through the relist marks: so many marks
	 * a round, so many rounds a request, so many listings asked after at a
	 * time.
	 */
	const RELIST_PAIRS_SCAN   = 2000;
	const RELIST_PAIRS_ROUNDS = 10;
	const RELIST_PAIRS_CHUNK  = 200;

	/**
	 * Products that may be the same item twice: one imported from a relist,
	 * which recorded the listing it was relisted from (_ebay_relist_parent_id,
	 * 3.28.0), beside another product still linked to that listing.
	 *
	 * 3.28.0 moves the product that sold the old listing on to its relist.
	 * Before it, a relist became a second product while the first stayed on
	 * the ended listing. Since 3.28.0 every import of a relist records the
	 * listing it came from on the product it writes, so those pairs come to
	 * light as the sync reads each relist again. Only listed: which copy to
	 * keep is the shop's to say.
	 *
	 * Not a pair whose older product's listing was a single item that sold,
	 * by eBay's figures for it: the relist is then usually another copy of
	 * the item, and both products are right (3.28.0's decision not to move
	 * a product on to such a relist). Never a product with itself.
	 *
	 * Asked for, not run on every Stock Review load, and bounded. The marks
	 * are read newest first, RELIST_PAIRS_SCAN at a time by their meta_id -
	 * one indexed read each - with the products holding those marks'
	 * listings looked up RELIST_PAIRS_CHUNK listings at a time; at most
	 * RELIST_PAIRS_ROUNDS rounds a request, and the answer says where to go
	 * on from. One join of the two sets of post meta was cheap only with this
	 * plugin's (meta_key, meta_value) index, which a large shared-host table
	 * may never have taken; without it, it read every linked product once
	 * per relist mark. And reading only the newest marks missed the very
	 * pairs this is for: the duplicates from before 3.28.0 got their marks
	 * when 3.28.0 first read them, so on a shop that relists every day they
	 * are the oldest.
	 *
	 * Neither in the trash nor an auto-draft, and the relist's product still
	 * linked to a listing. eBay is not asked.
	 *
	 * @param int $limit  Most pairs.
	 * @param int $before Start below this meta_id (0: from the newest).
	 * @return array pairs (each: product_id and item_id, the relist's;
	 *               parent_item; other_id, the product still on the parent
	 *               listing), checked (marks read) and next (the meta_id to
	 *               go on below, 0 once every mark has been read).
	 */
	public static function find_relist_pairs( $limit = 200, $before = 0 ) {
		global $wpdb;

		$limit   = max( 1, (int) $limit );
		$cursor  = max( 0, (int) $before );
		$pairs   = array();
		$checked = 0;

		for ( $round = 0; $round < self::RELIST_PAIRS_ROUNDS; $round++ ) {
			$below = $cursor > 0 ? $wpdb->prepare( ' AND rp.meta_id < %d', $cursor ) : '';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$relists = $wpdb->get_results( $wpdb->prepare(
				"SELECT rp.meta_id AS mark, rp.post_id AS product_id, ni.meta_value AS item_id, rp.meta_value AS parent_item
				 FROM {$wpdb->postmeta} rp
				 INNER JOIN {$wpdb->posts} np ON np.ID = rp.post_id AND np.post_type = 'product' AND np.post_status NOT IN ( 'trash', 'auto-draft' )
				 INNER JOIN {$wpdb->postmeta} ni ON ni.post_id = rp.post_id AND ni.meta_key = '_ebay_item_id' AND ni.meta_value <> '' AND ni.meta_value <> rp.meta_value
				 WHERE rp.meta_key = '_ebay_relist_parent_id' AND rp.meta_value <> ''{$below}
				 ORDER BY rp.meta_id DESC
				 LIMIT %d",
				self::RELIST_PAIRS_SCAN
			), ARRAY_A );

			$relists = is_array( $relists ) ? $relists : array();
			if ( empty( $relists ) ) {
				return array( 'pairs' => $pairs, 'checked' => $checked, 'next' => 0 );
			}

			$holders = self::relist_parent_holders( $relists );

			foreach ( $relists as $row ) {
				$checked++;
				$cursor = (int) $row['mark'];
				$others = $holders[ (string) $row['parent_item'] ] ?? array();
				sort( $others );

				foreach ( $others as $other_id ) {
					// Never itself: a product can hold both listings - two
					// _ebay_item_id rows, after an import that copied meta, or
					// a restore.
					if ( (int) $other_id === (int) $row['product_id'] ) {
						continue;
					}

					if ( self::sold_single_item( $other_id, (string) $row['parent_item'] ) ) {
						continue;
					}

					$pairs[] = array(
						'product_id'  => (int) $row['product_id'],
						'item_id'     => (string) $row['item_id'],
						'parent_item' => (string) $row['parent_item'],
						'other_id'    => (int) $other_id,
					);
				}

				// Enough: the rest wait for the next request, from this mark.
				if ( count( $pairs ) >= $limit ) {
					return array( 'pairs' => $pairs, 'checked' => $checked, 'next' => $cursor );
				}
			}

			if ( count( $relists ) < self::RELIST_PAIRS_SCAN ) {
				return array( 'pairs' => $pairs, 'checked' => $checked, 'next' => 0 );
			}
		}

		// Out of rounds, with marks still below this one.
		return array( 'pairs' => $pairs, 'checked' => $checked, 'next' => $cursor );
	}

	/**
	 * Who still holds each parent listing a round of relist marks names:
	 * listing => product ids, by meta_value IN (...), RELIST_PAIRS_CHUNK at
	 * a time.
	 *
	 * @param array[] $relists The round's marks.
	 * @return array
	 */
	private static function relist_parent_holders( array $relists ) {
		global $wpdb;

		$holders = array();
		$parents = array_values( array_unique( array_map( function ( $row ) {
			return (string) $row['parent_item'];
		}, $relists ) ) );

		foreach ( array_chunk( $parents, self::RELIST_PAIRS_CHUNK ) as $chunk ) {
			$in = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$found = $wpdb->get_results( $wpdb->prepare(
				"SELECT o.post_id AS other_id, o.meta_value AS item_id
				 FROM {$wpdb->postmeta} o
				 INNER JOIN {$wpdb->posts} op ON op.ID = o.post_id AND op.post_type = 'product' AND op.post_status NOT IN ( 'trash', 'auto-draft' )
				 WHERE o.meta_key = '_ebay_item_id' AND o.meta_value IN ( {$in} )",
				$chunk
			), ARRAY_A );

			foreach ( is_array( $found ) ? $found : array() as $row ) {
				$holders[ (string) $row['item_id'] ][] = (int) $row['other_id'];
			}
		}

		return $holders;
	}

	/**
	 * Whether eBay's final figures for a listing a product follows say it
	 * was a single item, and that it sold.
	 *
	 * @param int    $product_id The product.
	 * @param string $item_id    The listing.
	 * @return bool
	 */
	private static function sold_single_item( $product_id, $item_id ) {
		if ( (string) $item_id !== (string) get_post_meta( (int) $product_id, '_ebay_stock_settled_item', true ) ) {
			return false;
		}

		$listed = get_post_meta( (int) $product_id, '_ebay_quantity_listed', true );
		$sold   = get_post_meta( (int) $product_id, '_ebay_quantity_sold', true );

		return is_numeric( $listed ) && is_numeric( $sold ) && 1 === (int) $listed && (int) $sold >= 1;
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
	 * Most listing pages, of 200, one slice of the weekly stock check reads
	 * before it hands the rest of its pass on - fewer when the request's time
	 * or memory runs short first (run_reconcile_slice()).
	 */
	const RECONCILE_MAX_PAGES = 25;

	/**
	 * The Action Scheduler follow-up that carries a pass of the weekly stock
	 * check on from its place, and its group (3.28.0).
	 */
	const RECONCILE_HOOK  = 'tcgiant_sync_reconcile_continue';
	const RECONCILE_GROUP = 'tcgiant_sync_reconcile';

	/**
	 * WP-Cron's check on a pass that has gone quiet, and how long after its
	 * next slice was due it looks: two hours (reconcile_stall_check()).
	 */
	const RECONCILE_STALL_HOOK    = 'tcgiant_sync_reconcile_stall';
	const RECONCILE_STALL_SECONDS = 7200;

	/**
	 * Where a pass has got to, with its totals so far: started_at, page (the
	 * next to read), total_pages (0 until a page is read), checked and
	 * mismatches; tries (failed reads of that page in a row) and wait_until
	 * (when the next slice is due after a refusal or a failed read, else 0);
	 * owner and beat (the slice at work on it, and when it last wrote);
	 * kills (slices in a row killed on that page) and skipped (pages stepped
	 * over). Never autoloaded, and written straight to its row - see
	 * write_reconcile_place().
	 */
	const RECONCILE_OPTION = 'tcgiant_sync_reconcile_pass';

	/**
	 * A pass not finished this many days after it began starts again from
	 * page 1: what it had found is out of date.
	 */
	const RECONCILE_MAX_DAYS = 3;

	/**
	 * A page eBay could not be read for, for a reason that passes - a
	 * timeout, eBay busy, no token for a while - is tried again this long
	 * after, up to RECONCILE_TRIES tries in all; then the pass stops.
	 */
	const RECONCILE_RETRY_SECONDS = 900;
	const RECONCILE_TRIES         = 5;

	/**
	 * A slice that has written nothing for this long is taken to be gone. It
	 * writes after every page, and hands on about a minute into its request.
	 */
	const RECONCILE_LIVE_SECONDS = 600;

	/**
	 * Slices in a row a page may see killed before the pass steps over it,
	 * its listings unchecked: three, as the full sync's scan counts the
	 * batches a page kills (TCGiant_Sync_Importer::SCAN_READ_KILLS). A page
	 * that stops its request every time - a host's memory or time limit -
	 * was read again by the stall check every two hours until the pass was
	 * three days old, and the pass never finished.
	 */
	const RECONCILE_PAGE_KILLS = 3;

	/**
	 * A pass the day's eBay calls ran out on waits for the next UTC day:
	 * until this many seconds past midnight, 00:05:30, as a stock check does
	 * (TCGiant_Sync_Importer::SETTLE_AFTER_MIDNIGHT).
	 */
	const RECONCILE_AFTER_MIDNIGHT = 330;

	/**
	 * The pass's row as this request last wrote it, while a slice here holds
	 * the pass; null otherwise.
	 *
	 * @var string|null
	 */
	private static $reconcile_held = null;

	/**
	 * The weekly stock check, from WP-Cron: compare each live eBay listing's
	 * available quantity with its product's WooCommerce stock, and log the
	 * ones that differ. Report only - it changes no stock, here or on eBay -
	 * and its eBay calls only read, so a staging copy makes them as before.
	 *
	 * Until 3.28.0 each run began at page 1 and stopped after
	 * RECONCILE_MAX_PAGES pages of 200, so a shop with more listings than
	 * that never had the rest checked: a shop's 9,000 listings are 47
	 * pages, and pages 26 to 47 were never read. A pass now reads every page,
	 * a slice at a time (run_reconcile_slice()), and keeps its place between
	 * slices (RECONCILE_OPTION). One pass at a time: a weekly run while one
	 * is under way starts no second one, and carries on one gone quiet.
	 */
	public function reconcile_inventory() {
		$this->reconcile_guarded( 'weekly' );
	}

	/**
	 * Carry a pass of the weekly stock check on: the Action Scheduler
	 * follow-up a slice hands the rest of it to (RECONCILE_HOOK).
	 *
	 * @param int $pass When the pass it was queued for began (its started_at).
	 */
	public function continue_reconciliation( $pass = 0 ) {
		$this->reconcile_guarded( 'follow-up', (int) $pass );
	}

	/**
	 * WP-Cron's check on a pass that has gone quiet (RECONCILE_STALL_HOOK),
	 * as the full sync's scan has its WP-Cron backstop: armed as each slice
	 * begins and again as it hands on, RECONCILE_STALL_SECONDS after the next
	 * slice is due, and moved on by every slice. It finds the pass where it
	 * was only when the follow-up was lost or the host killed the slice
	 * outright, and carries it on from its place.
	 */
	public function reconcile_stall_check() {
		$this->reconcile_guarded( 'stall' );
	}

	/**
	 * Run one of the three, unattended: an uncaught Error takes down every
	 * hook scheduled after it in the same tick. A slice that threw keeps the
	 * place its last page left, and lets go of the pass for the stall check
	 * to carry on.
	 *
	 * @param string $how   weekly, follow-up or stall.
	 * @param int    $asked For a follow-up: the pass it was queued for.
	 */
	private function reconcile_guarded( $how, $asked = 0 ) {
		try {
			$this->do_reconcile_inventory( $how, $asked );
		} catch ( Exception $e ) {
			TCGiant_Sync_Logger::error( 'Reconciliation: Exception — ' . $e->getMessage() );
		} catch ( Error $e ) {
			TCGiant_Sync_Logger::error( 'Reconciliation: Fatal — ' . $e->getMessage() );
		} finally {
			self::let_go_of_reconcile();
		}
	}

	/**
	 * Start a pass, carry one on, or leave one to the run that has it.
	 *
	 * @param string $how   weekly, follow-up or stall.
	 * @param int    $asked For a follow-up: the pass it was queued for.
	 */
	private function do_reconcile_inventory( $how, $asked = 0 ) {
		$raw   = null;
		$place = self::reconcile_place( $raw );

		// A follow-up for a pass that has ended, or that a newer one has
		// replaced since; the stall check with no pass to look at.
		if ( ( 'follow-up' === $how && ( null === $place || $place['started_at'] !== (int) $asked ) )
			|| ( 'stall' === $how && null === $place ) ) {
			return;
		}

		// A slice at work on it now is left to it.
		if ( null !== $place && self::reconcile_slice_live( $place ) ) {
			if ( 'weekly' === $how ) {
				self::say_reconcile_under_way( $place );
			}
			return;
		}

		// As the database holds them now: a pass runs over days.
		$settings = self::settings_row();
		if ( isset( $settings['enable_reconciliation'] ) && empty( $settings['enable_reconciliation'] ) ) {
			if ( null !== $place ) {
				self::end_reconcile_pass( $raw, 'Reconciliation: turned off in Settings, so the check in progress stops. ' . self::reconcile_so_far( $place ) );
			} elseif ( 'weekly' === $how ) {
				TCGiant_Sync_Logger::log( 'Reconciliation: Starting weekly inventory check...' );
				TCGiant_Sync_Logger::log( 'Reconciliation: Disabled in settings. Skipping.' );
			}
			return;
		}

		$expired = null !== $place && $place['started_at'] < time() - self::RECONCILE_MAX_DAYS * DAY_IN_SECONDS;

		// Out of date while still waiting on eBay's 518: it stops, as a push
		// or a sale check stops at its three days. Started again now it would
		// only go back to asking every hour; the next weekly run starts a
		// fresh pass (3.28.0 release fix round 3).
		if ( $expired && ! empty( $place['e518'] ) && 'weekly' !== $how ) {
			self::end_reconcile_pass( $raw, sprintf(
				'Reconciliation: the check begun %s had not finished after %d days, with eBay\'s call limit (518) still holding, so it stops. %s The next weekly check starts again from page 1.',
				self::reconcile_begun( $place ),
				self::RECONCILE_MAX_DAYS,
				self::reconcile_so_far( $place )
			), 'warning' );
			return;
		}

		if ( null !== $place && ! $expired ) {
			$due = self::reconcile_due( $place );
			if ( $due > 0 ) {
				// Waiting for its time, or for the follow-up queued for it.
				if ( 'weekly' === $how ) {
					self::say_reconcile_under_way( $place );
				}
				if ( 'follow-up' !== $how ) {
					self::arm_reconcile_stall( $due + self::RECONCILE_STALL_SECONDS );
				}
				return;
			}

			if ( 'follow-up' !== $how ) {
				TCGiant_Sync_Logger::log( sprintf(
					'Reconciliation: the check begun %s had stopped at page %d%s without carrying on, so it carries on from there.',
					self::reconcile_begun( $place ),
					$place['page'],
					self::reconcile_of( $place )
				) );
			}

			$this->run_reconcile_slice( $place, $raw );
			return;
		}

		// Only a weekly run starts a pass (the others returned above with
		// none to carry on), and a pass gone out of date starts again.
		if ( $expired ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Reconciliation: the check begun %s had not finished after %d days, so it starts again from page 1. %s',
				self::reconcile_begun( $place ),
				self::RECONCILE_MAX_DAYS,
				self::reconcile_so_far( $place )
			) );
		}

		TCGiant_Sync_Logger::log( 'Reconciliation: Starting weekly inventory check...' );

		$this->run_reconcile_slice( self::fresh_reconcile_place( time() ), $raw );
	}

	/**
	 * One slice of a pass: from its place, read pages and check their
	 * listings until every page is read, RECONCILE_MAX_PAGES are, or the
	 * request's time or memory runs short (TCGiant_Sync_Runtime::over_budget(),
	 * as the delta and full syncs stop) - always at least one, so that every
	 * slice moves the pass on - then hand the rest to a follow-up.
	 *
	 * Each page is the listings ending from when the pass began, as the full
	 * sync's scan asks for them (TCGiant_Sync_Importer::scan_end_from()):
	 * eBay pages a seller's listings by when they end, and asked from "now"
	 * on every page, a listing that ended between two slices moved every
	 * listing after it up a place, and one went unchecked. Asked from the
	 * start, a listing that ends meanwhile stays in the answer, ended, and is
	 * passed over: there is no live listing to compare with.
	 *
	 * One slice at a time. It takes the pass by writing its place over the
	 * row as read, and each later write goes over its own last one
	 * (write_reconcile_place()), so a run that finds another at work leaves
	 * it, and a slice another run has taken over stops at its next page.
	 *
	 * @param array       $place The pass, as reconcile_place() gives it.
	 * @param string|null $raw   Its row as read; null when there is none yet.
	 */
	private function run_reconcile_slice( array $place, $raw ) {
		// Still held by a slice that is gone: it never let go, so its request
		// was killed outright on the page the pass is at. Only that page read
		// and checked resets the count; after RECONCILE_PAGE_KILLS in a row it
		// is stepped over.
		$stepped = 0;
		if ( '' !== $place['owner'] ) {
			$place['kills']++;
			if ( $place['kills'] >= self::RECONCILE_PAGE_KILLS ) {
				$stepped = $place['page'];
				$place['page']++;
				$place['skipped']++;
				$place['kills'] = 0;
				$place['tries'] = 0;
			}
		}

		$place['owner']      = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'reconcile', true );
		$place['beat']       = time();
		$place['wait_until'] = 0;

		self::$reconcile_held = self::write_reconcile_place( $place, $raw );
		if ( null === self::$reconcile_held ) {
			// Another run wrote first - unless there is still no row at all:
			// the database would not take the write.
			if ( null === $raw && null === self::reconcile_place() ) {
				TCGiant_Sync_Logger::error( 'Reconciliation: could not start - the database would not record where the check had got to. It tries again next week.' );
			}
			return;
		}

		if ( $stepped > 0 ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Reconciliation: page %d%s ended its request %d times running, so it is stepped over: its listings are not checked in this pass.',
				$stepped,
				self::reconcile_of( $place ),
				self::RECONCILE_PAGE_KILLS
			) );

			// The last page stepped over: nothing left to read.
			if ( $place['total_pages'] > 0 && $place['page'] > $place['total_pages'] ) {
				self::finish_reconcile_pass( $place );
				return;
			}
		}

		// Killed outright, with nothing in its request run: this carries the
		// pass on. Moved on as each slice begins and hands on.
		self::arm_reconcile_stall( time() + self::RECONCILE_STALL_SECONDS );

		TCGiant_Sync_Runtime::raise_time_limit( 300 );

		// Waiting on eBay's 518: nothing is read until the shared question
		// says it has lifted (3.28.0 release fix round 2). A pass is kept for
		// RECONCILE_MAX_DAYS at most, so it waits no longer than that.
		if ( ! empty( $place['e518'] ) && class_exists( 'TCGiant_Sync_Importer' ) && ! TCGiant_Sync_Importer::ebay_limit_lifted() ) {
			$when = TCGiant_Sync_Importer::ebay_518_until();
			self::hand_on_reconcile( $place, $when, sprintf( 'Reconciliation: eBay\'s call limit (518) has not lifted yet, so page %d%s is read later - asked again at %s UTC.', $place['page'], self::reconcile_of( $place ), gmdate( 'H:i', $when ) ) );
			return;
		}

		$api      = TCGiant_Sync_API::instance();
		$from     = $place['page'];
		$read     = 0;
		$end_from = gmdate( 'Y-m-d\TH:i:s.000\Z', $place['started_at'] );

		while ( true ) {
			if ( $read > 0 ) {
				TCGiant_Sync_Runtime::release_memory();
				$over = TCGiant_Sync_Runtime::over_budget();

				if ( $read >= self::RECONCILE_MAX_PAGES || '' !== $over ) {
					if ( self::hand_on_reconcile( $place, time(), sprintf( 'Reconciliation: %s, continuing.', self::reconcile_pages( $from, $place ) ) ) && '' !== $over ) {
						// Picked up by the queue runner in this same request, the
						// follow-up would start as short of time or memory.
						TCGiant_Sync_Runtime::end_queue_runs();
					}
					return;
				}
			}

			$result = $api->get_active_listings( $place['page'], 200, '', $end_from );

			if ( is_wp_error( $result ) ) {
				self::reconcile_read_failed( $place, $from, $result );
				return;
			}

			// No listings on this page: past the last of them.
			if ( ! isset( $result['ItemArray']['Item'] ) ) {
				self::finish_reconcile_pass( $place );
				return;
			}

			// The Trading API returns a bare item object when only one matches.
			$items = $result['ItemArray']['Item'];
			if ( isset( $items['ItemID'] ) ) {
				$items = array( $items );
			}

			$place['total_pages'] = isset( $result['PaginationResult']['TotalNumberOfPages'] )
				? (int) $result['PaginationResult']['TotalNumberOfPages']
				: 1;
			unset( $result );

			list( $checked, $mismatches, $varied ) = self::reconcile_items( $items );
			unset( $items );

			$place['checked']    += $checked;
			$place['mismatches'] += $mismatches;
			$place['varied']     += $varied;
			$place['e518']        = 0;
			$place['page']++;
			$place['tries'] = 0;
			$place['kills'] = 0;
			$place['beat']  = time();
			$read++;

			if ( ! self::keep_reconcile_place( $place ) ) {
				return;
			}

			if ( $place['page'] > $place['total_pages'] ) {
				self::finish_reconcile_pass( $place );
				return;
			}
		}
	}

	/**
	 * Check one page's listings against their products' stock, logging each
	 * that differs. Changes nothing.
	 *
	 * A listing with variations is not compared: its Quantity is the total
	 * of all its variations, which no one product's stock is - a pool's
	 * shared stock shows on each of them - so it was reported as a mismatch
	 * every week. They are counted and said in the end line: this check
	 * compares single listings only (3.28.0 release fix rounds 2 and 3).
	 *
	 * @param array $items The page's Item entries.
	 * @return int[] How many were checked, how many differed, and how many
	 *               listings with variations were left out.
	 */
	private static function reconcile_items( array $items ) {
		global $wpdb;

		$checked    = 0;
		$mismatches = 0;
		$varied     = 0;

		foreach ( $items as $item ) {
			$item_id  = $item['ItemID'] ?? '';
			$ebay_qty = isset( $item['Quantity'] ) ? (int) $item['Quantity'] : null;
			$title    = $item['Title'] ?? 'Unknown';

			if ( empty( $item_id ) || null === $ebay_qty ) {
				continue;
			}

			// Ended since the pass began: the pages are asked from then.
			$status = TCGiant_Sync_API::scalar_value( $item['SellingStatus']['ListingStatus'] ?? '' );
			if ( '' !== $status && 'Active' !== $status ) {
				continue;
			}

			if ( ! empty( $item['Variations'] ) ) {
				$varied++;
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

		return array( $checked, $mismatches, $varied );
	}

	/**
	 * A page could not be read. eBay's own 518: the page is asked about
	 * again at the next slot, an hour at most (TCGiant_Sync_Importer::
	 * after_ebay_518()), its place kept. The plugin's own day budget - the
	 * shared 80% ceiling - used up: the pass waits for the next 00:05:30
	 * UTC, its place kept, however often. A failure that passes is tried
	 * again in RECONCILE_RETRY_SECONDS, up to RECONCILE_TRIES tries; anything
	 * else, or a page that never comes, stops the pass. A refusal never
	 * starts it again from page 1.
	 *
	 * @param array    $place The pass, as this slice holds it.
	 * @param int      $from  The page this slice began at.
	 * @param WP_Error $error What the read returned.
	 */
	private static function reconcile_read_failed( array $place, $from, $error ) {
		$done = self::reconcile_pages( $from, $place );
		$done = '' !== $done ? $done . '; ' : '';
		$why  = $error->get_error_message();
		$page = sprintf( 'page %d%s', $place['page'], self::reconcile_of( $place ) );

		// eBay's own 518 lifts when it lifts: the page is read again hourly,
		// each slice asking the shared question before it sends (3.28.0
		// release fix round 2), within the pass's RECONCILE_MAX_DAYS. The
		// plugin's own day budget waits for 00:05:30.
		if ( class_exists( 'TCGiant_Sync_Importer' ) && TCGiant_Sync_Importer::ebay_out_of_calls( $error ) ) {
			$when          = TCGiant_Sync_Importer::after_ebay_518();
			$place['e518'] = 1;
			TCGiant_Sync_Importer::note_ebay_518( $when );
			self::hand_on_reconcile( $place, $when, sprintf(
				'Reconciliation: %seBay\'s call limit is reached (%s), so %s is read once it lifts - asked again at %s UTC.',
				$done,
				$why,
				$page,
				gmdate( 'H:i', $when )
			), 'warning' );
			return;
		}

		// Anything else: no longer waiting on a 518.
		$place['e518'] = 0;

		if ( self::reconcile_out_for_the_day( $error ) ) {
			self::hand_on_reconcile( $place, self::reconcile_after_budget_resets(), sprintf(
				'Reconciliation: %sthe day\'s eBay calls are used up (%s), so %s is read just after 00:05 UTC.',
				$done,
				$why,
				$page
			), 'warning' );
			return;
		}

		$tries = $place['tries'] + 1;

		if ( TCGiant_Sync_API::is_transient_error( $error ) && $tries < self::RECONCILE_TRIES ) {
			$place['tries'] = $tries;
			self::hand_on_reconcile( $place, time() + self::RECONCILE_RETRY_SECONDS, sprintf(
				'Reconciliation: %s%s could not be read (%s), so it is tried again in %d minutes (try %d of %d).',
				$done,
				$page,
				$why,
				(int) ( self::RECONCILE_RETRY_SECONDS / MINUTE_IN_SECONDS ),
				$tries + 1,
				self::RECONCILE_TRIES
			), 'warning' );
			return;
		}

		self::end_reconcile_pass( self::$reconcile_held, sprintf(
			'Reconciliation: %s could not be read%s (%s), so this check stops. %s The next weekly check starts again from page 1.',
			$page,
			$tries > 1 ? sprintf( ' after %d tries', $tries ) : '',
			$why,
			self::reconcile_so_far( $place )
		), 'error' );
	}

	/**
	 * Whether a read was refused for the day's calls: the plugin's own daily
	 * budget - every shop's together, past its 80% ceiling - or eBay's 518
	 * (taken first, hourly, whenever the importer's helpers are loaded).
	 * Not eBay's HTTP 429, a moment's throttle, which is tried again as any
	 * failure that passes is.
	 *
	 * @param WP_Error $error What the read returned.
	 * @return bool
	 */
	private static function reconcile_out_for_the_day( $error ) {
		if ( 'rate_limited' !== $error->get_error_code() ) {
			return false;
		}

		$data = $error->get_error_data();

		return ( is_array( $data ) && ! empty( $data['daily_budget'] ) )
			|| in_array( '518', TCGiant_Sync_API::error_codes( $error ), true );
	}

	/**
	 * The next 00:05:30 UTC: the day's call budget is counted by the UTC day.
	 *
	 * @return int
	 */
	private static function reconcile_after_budget_resets() {
		$today = gmmktime( 0, 0, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ), (int) gmdate( 'Y' ) ) + self::RECONCILE_AFTER_MIDNIGHT;

		return $today > time() ? $today : $today + DAY_IN_SECONDS;
	}

	/**
	 * Hand the rest of a pass to a follow-up due at $when, its place kept,
	 * and say so in one line.
	 *
	 * @param array  $place The pass, as this slice holds it.
	 * @param int    $when  When the next slice is due.
	 * @param string $line  For the log.
	 * @param string $level Its level.
	 * @return bool False when another run had taken the pass over.
	 */
	private static function hand_on_reconcile( array $place, $when, $line, $level = 'info' ) {
		$when                = (int) $when;
		$place['owner']      = '';
		$place['wait_until'] = $when > time() ? $when : 0;
		$place['beat']       = time();

		if ( ! self::keep_reconcile_place( $place ) ) {
			return false;
		}
		// Written with no owner: no longer this request's.
		self::$reconcile_held = null;

		if ( function_exists( 'as_schedule_single_action' ) ) {
			// Never two: one left from before - lost, or for a pass replaced
			// since - goes. Only those waiting; not this one, if it is one.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::RECONCILE_HOOK, null, self::RECONCILE_GROUP );
			}
			as_schedule_single_action( max( time(), $when ), self::RECONCILE_HOOK, array( 'pass' => $place['started_at'] ), self::RECONCILE_GROUP );
		}

		self::arm_reconcile_stall( max( time(), $when ) + self::RECONCILE_STALL_SECONDS );

		TCGiant_Sync_Logger::log( $line, $level );

		return true;
	}

	/**
	 * Every page is read: record the result, and say it in one line.
	 *
	 * last_reconciliation and its mismatch count are written here only, and
	 * onto the settings as the database holds them now (save_settings_keys()):
	 * a pass spans hours or days, and this request's copy of them would put
	 * back what another request changed meanwhile - a Disconnect, a refreshed
	 * token.
	 *
	 * @param array $place The pass, as this slice holds it.
	 */
	private static function finish_reconcile_pass( array $place ) {
		if ( ! self::end_reconcile_pass( self::$reconcile_held, '' ) ) {
			return;
		}

		$pages = max( 0, $place['page'] - 1 - $place['skipped'] );

		self::save_settings_keys( array(
			'last_reconciliation'            => current_time( 'mysql' ),
			'last_reconciliation_mismatches' => $place['mismatches'],
		) );

		TCGiant_Sync_Logger::log( sprintf(
			'Reconciliation: Complete. Checked %d items across %d page%s, found %d mismatch(es)%s.%s',
			$place['checked'],
			$pages,
			1 === $pages ? '' : 's',
			$place['mismatches'],
			$place['skipped'] > 0 ? sprintf( ', %d page%s skipped', $place['skipped'], 1 === $place['skipped'] ? '' : 's' ) : '',
			$place['varied'] > 0 ? sprintf( ' %d listing%s with variations %s skipped. This check compares single listings only.', $place['varied'], 1 === $place['varied'] ? '' : 's', 1 === $place['varied'] ? 'was' : 'were' ) : ''
		), $place['mismatches'] > 0 || $place['skipped'] > 0 ? 'warning' : 'success' );

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

	/**
	 * End a pass - done, given up on, or turned off - unless its row has
	 * changed since it was read: then it is another run's. The follow-up
	 * and the stall check go with it.
	 *
	 * @param string|null $raw   The pass's row as read or last written.
	 * @param string      $line  For the log; '' for none.
	 * @param string      $level Its level.
	 * @return bool Whether it was this run's to end.
	 */
	private static function end_reconcile_pass( $raw, $line, $level = 'info' ) {
		global $wpdb;

		$held                 = null !== $raw && $raw === self::$reconcile_held;
		self::$reconcile_held = null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$gone = null === $raw ? 0 : $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			self::RECONCILE_OPTION,
			$raw
		) );

		if ( 1 !== (int) $gone ) {
			if ( $held ) {
				TCGiant_Sync_Logger::log( 'Reconciliation: another run has taken this check over, so this one stops.' );
			}
			return false;
		}

		wp_clear_scheduled_hook( self::RECONCILE_STALL_HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::RECONCILE_HOOK, null, self::RECONCILE_GROUP );
		}

		if ( '' !== $line ) {
			TCGiant_Sync_Logger::log( $line, $level );
		}

		return true;
	}

	/**
	 * Write where this slice has got to over its own last write. A write
	 * that finds the row changed means another run has taken the pass over.
	 *
	 * @param array $place The pass.
	 * @return bool Whether it is still this slice's.
	 */
	private static function keep_reconcile_place( array $place ) {
		$held = null === self::$reconcile_held ? null : self::write_reconcile_place( $place, self::$reconcile_held );

		if ( null === $held ) {
			self::$reconcile_held = null;
			TCGiant_Sync_Logger::log( 'Reconciliation: another run has taken this check over, so this one stops.' );
			return false;
		}

		self::$reconcile_held = $held;

		return true;
	}

	/**
	 * A slice ended without handing on - something threw: let go of the
	 * pass, its place as its last page left it, for the stall check.
	 */
	private static function let_go_of_reconcile() {
		$held = self::$reconcile_held;
		if ( null === $held ) {
			return;
		}
		self::$reconcile_held = null;

		$place          = self::reconcile_fields( maybe_unserialize( $held ) );
		$place['owner'] = '';
		self::write_reconcile_place( $place, $held );
	}

	/**
	 * The pass, as its row holds it now - past this request's cache - or
	 * null when there is none.
	 *
	 * @param string|null $raw Set to the row as read.
	 * @return array|null
	 */
	private static function reconcile_place( &$raw = null ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the point is to miss the cache.
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::RECONCILE_OPTION ) );
		$raw = null === $row ? null : (string) $row;

		return null === $raw ? null : self::reconcile_fields( maybe_unserialize( $raw ) );
	}

	/**
	 * A pass's fields from what its row held, each of its type. A row that
	 * is not one began at 0: out of date, so it starts again.
	 *
	 * @param mixed $value The row, unserialised.
	 * @return array
	 */
	private static function reconcile_fields( $value ) {
		$place = self::fresh_reconcile_place( 0 );
		$value = is_array( $value ) ? $value : array();

		foreach ( $place as $key => $default ) {
			if ( isset( $value[ $key ] ) ) {
				$place[ $key ] = is_int( $default ) ? (int) $value[ $key ] : (string) $value[ $key ];
			}
		}

		return $place;
	}

	/**
	 * A pass about to read its first page.
	 *
	 * @param int $now When it begins.
	 * @return array
	 */
	private static function fresh_reconcile_place( $now ) {
		return array(
			'started_at'  => (int) $now,
			'page'        => 1,
			'total_pages' => 0,
			'checked'     => 0,
			'mismatches'  => 0,
			'tries'       => 0,
			'wait_until'  => 0,
			'owner'       => '',
			'beat'        => 0,
			'kills'       => 0,
			'skipped'     => 0,
			'e518'        => 0,
			'varied'      => 0,
		);
	}

	/**
	 * Write the pass's row over the row as read, only while it still holds
	 * exactly that - so that of two runs only one carries a pass on - or, with
	 * none read, as a plain INSERT, which the options table's unique name
	 * lets in for one request only. As the full sync's scan takes its place
	 * (TCGiant_Sync_Importer::claim_scan_batch()). Never autoloaded, and read
	 * only from the row (reconcile_place()).
	 *
	 * @param array       $place The pass.
	 * @param string|null $raw   The row as read; null when there was none.
	 * @return string|null The row as written; null when another run's write
	 *                     came first, or the database refused it.
	 */
	private static function write_reconcile_place( array $place, $raw ) {
		global $wpdb;

		$value = maybe_serialize( $place );

		if ( null === $raw ) {
			$quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : false;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$took = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				self::RECONCILE_OPTION,
				$value
			) );
			if ( method_exists( $wpdb, 'suppress_errors' ) ) {
				$wpdb->suppress_errors( $quiet );
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$took = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				self::RECONCILE_OPTION,
				$raw
			) );
		}

		return 1 === (int) $took ? $value : null;
	}

	/**
	 * Whether a slice is at work on the pass: one holds it, and has written
	 * in the last RECONCILE_LIVE_SECONDS.
	 *
	 * @param array $place The pass.
	 * @return bool
	 */
	private static function reconcile_slice_live( array $place ) {
		return '' !== $place['owner'] && $place['beat'] > time() - self::RECONCILE_LIVE_SECONDS;
	}

	/**
	 * When a pass that no slice is at work on is next due, if it is in hand:
	 * waiting for its time (wait_until), or for its follow-up, queued and not
	 * more than RECONCILE_STALL_SECONDS overdue. 0 when it has gone quiet - or
	 * for the follow-up itself, which Action Scheduler has running, not
	 * waiting.
	 *
	 * @param array $place The pass.
	 * @return int
	 */
	private static function reconcile_due( array $place ) {
		$now = time();

		if ( $place['wait_until'] > $now ) {
			return $place['wait_until'];
		}

		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 0;
		}

		// When the one waiting is due. True, for one Action Scheduler has
		// running, is not in hand: no slice has written for
		// RECONCILE_LIVE_SECONDS (reconcile_slice_live()), so it was killed,
		// and Action Scheduler has yet to notice.
		$next = as_next_scheduled_action( self::RECONCILE_HOOK, array( 'pass' => $place['started_at'] ), self::RECONCILE_GROUP );

		if ( is_int( $next ) && $next > $now - self::RECONCILE_STALL_SECONDS ) {
			return max( $now, $next );
		}

		return 0;
	}

	/**
	 * The stall check, due at $at - and only then: one already set is moved.
	 *
	 * @param int $at When.
	 */
	private static function arm_reconcile_stall( $at ) {
		// Cleared first: WordPress refuses a second event for the same hook
		// within ten minutes of one already scheduled.
		wp_clear_scheduled_hook( self::RECONCILE_STALL_HOOK );
		wp_schedule_single_event( (int) $at, self::RECONCILE_STALL_HOOK );
	}

	/**
	 * A weekly run found the pass under way: say so, and that no second one
	 * starts.
	 *
	 * @param array $place The pass.
	 */
	private static function say_reconcile_under_way( array $place ) {
		$read = $place['page'] - 1;

		TCGiant_Sync_Logger::log( sprintf(
			'Reconciliation: the check begun %s is still under way (%s%s), so a second one does not start.',
			self::reconcile_begun( $place ),
			$read > 0 ? sprintf( '%s%s checked', 1 === $read ? 'page 1' : 'pages 1-' . $read, self::reconcile_of( $place ) ) : 'no page checked yet',
			$place['wait_until'] > time() ? sprintf( '; page %d is due at %s UTC', $place['page'], gmdate( 'H:i', $place['wait_until'] ) ) : ''
		) );
	}

	/**
	 * When a pass began, for the log.
	 *
	 * @param array $place The pass.
	 * @return string
	 */
	private static function reconcile_begun( array $place ) {
		return gmdate( 'Y-m-d H:i', $place['started_at'] ) . ' UTC';
	}

	/**
	 * " of 47", once a page has said how many there are.
	 *
	 * @param array $place The pass.
	 * @return string
	 */
	private static function reconcile_of( array $place ) {
		return $place['total_pages'] > 0 ? sprintf( ' of %d', $place['total_pages'] ) : '';
	}

	/**
	 * "pages 26-47 of 47 checked": what a slice has read; '' for nothing.
	 *
	 * @param int   $from  The page the slice began at.
	 * @param array $place The pass, as the slice holds it.
	 * @return string
	 */
	private static function reconcile_pages( $from, array $place ) {
		$last = $place['page'] - 1;

		if ( $last < $from ) {
			return '';
		}

		return sprintf(
			'%s%s checked',
			$last === $from ? sprintf( 'page %d', $from ) : sprintf( 'pages %d-%d', $from, $last ),
			self::reconcile_of( $place )
		);
	}

	/**
	 * What a pass that stops short had found, for the log.
	 *
	 * @param array $place The pass.
	 * @return string
	 */
	private static function reconcile_so_far( array $place ) {
		$read = $place['page'] - 1;

		if ( $read < 1 ) {
			return 'No page had been checked.';
		}

		return sprintf(
			'%s%s had been checked: %d item(s), %d mismatch(es).',
			1 === $read ? 'Page 1' : 'Pages 1-' . $read,
			self::reconcile_of( $place ),
			$place['checked'],
			$place['mismatches']
		);
	}
}
