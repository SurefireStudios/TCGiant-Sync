<?php
/**
 * Harness: the Stock Review lists that recover from the old ended-listing settle.
 *
 * Before 3.27.0, a listing ending set WooCommerce's stock to eBay's "listed
 * minus sold", which put coins sold on the website back on sale. 3.27.0 stops
 * that, and Stock Review lists the ones it already happened to, with a button
 * to set them to 0.
 *
 * Reviewing that list found three ways it could do harm of its own:
 *
 * - A refund is a WooCommerce order of its own, and a refunded line is stored
 *   under the refund with the product's ID. The newest "order" for a returned
 *   coin was its refund: taken for the website sale, it also crashed the whole
 *   Stock Review page, which called an order-only method on it.
 * - Products settled correctly by 3.27.0 - which can never have raised stock -
 *   were listed too, ticked, one click from losing real stock.
 * - The list is cached, so a product relisted since could be zeroed with eBay
 *   still selling it.
 *
 * Loads the real TCGiant_Sync_Inventory with WordPress and WooCommerce stubbed.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-76s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );

$META       = array();
$TRANSIENTS = array();
$LOG        = array();
$ORDERS     = array();
$QUERIES    = array();
$CANDIDATES = array();
$ORDER_ROWS = array();

// The database: the queries are recorded, and answered from the arrays above.
class Harness_Wpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $prefix   = 'wp_';
	public function prepare( $query, ...$args ) { return $query; }
	public function get_col( $query ) { $GLOBALS['QUERIES'][] = $query; return $GLOBALS['CANDIDATES']; }
	public function get_results( $query, $output = null ) { $GLOBALS['QUERIES'][] = $query; return $GLOBALS['ORDER_ROWS']; }
}
$wpdb = new Harness_Wpdb();

function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['META'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['META'][ (int) $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['META'][ (int) $id ][ $key ] ); return true; }
function get_transient( $key ) { return $GLOBALS['TRANSIENTS'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['TRANSIENTS'][ $key ] ); return true; }
function add_action() {}
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) { return 'U' === $format ? (string) strtotime( $date . ' UTC' ) : gmdate( $format, strtotime( $date . ' UTC' ) ); }

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = $message; }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
}

// WooCommerce's orders: a refund is a separate object of its own type, with a
// status that always reads "completed" and a negative total.
class Harness_Date {
	private $ts;
	public function __construct( $ts ) { $this->ts = $ts; }
	public function getTimestamp() { return $this->ts; }
}
class WC_Order {
	public $d;
	public function __construct( array $d ) { $this->d = $d; }
	public function get_type() { return 'shop_order'; }
	public function get_status() { return $this->d['status'] ?? 'processing'; }
	public function get_meta( $key ) { return $this->d['meta'][ $key ] ?? ''; }
	public function get_date_created() { return new Harness_Date( $this->d['created'] ?? time() ); }
	public function get_total() { return $this->d['total'] ?? 89.99; }
	public function get_total_refunded() { return $this->d['refunded'] ?? 0; }
}
class WC_Order_Refund {
	public $d;
	public function __construct( array $d ) { $this->d = $d; }
	public function get_type() { return 'shop_order_refund'; }
	public function get_status() { return 'completed'; }
	public function get_meta( $key ) { return ''; }
	public function get_date_created() { return new Harness_Date( $this->d['created'] ?? time() ); }
	public function get_total() { return -89.99; }
	public function get_total_refunded() { return 0; }
}
function wc_get_order( $id ) { return $GLOBALS['ORDERS'][ (int) $id ] ?? false; }

// WooCommerce's product, as far as these actions read one.
class WC_Product {
	private $id;
	public function __construct( $id ) { $this->id = (int) $id; }
	public function managing_stock() { return 'yes' === ( $GLOBALS['META'][ $this->id ]['_manage_stock'] ?? '' ); }
	public function get_stock_quantity() { return $this->managing_stock() ? (int) ( $GLOBALS['META'][ $this->id ]['_stock'] ?? 0 ) : null; }
	public function get_stock_status() { return $GLOBALS['META'][ $this->id ]['_stock_status'] ?? 'instock'; }
}
function wc_get_product( $id ) { return isset( $GLOBALS['META'][ (int) $id ] ) ? new WC_Product( $id ) : false; }
function wc_update_product_stock( $id, $qty, $op = 'set' ) { $GLOBALS['META'][ (int) $id ]['_stock'] = (int) $qty; return (int) $qty; }
function wc_update_product_stock_status( $id, $status ) { $GLOBALS['META'][ (int) $id ]['_stock_status'] = $status; }

require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-inventory.php';

function fresh() {
	$GLOBALS['META']       = array();
	$GLOBALS['TRANSIENTS'] = array();
	$GLOBALS['LOG']        = array();
	$GLOBALS['ORDERS']     = array();
	$GLOBALS['QUERIES']    = array();
	$GLOBALS['CANDIDATES'] = array();
	$GLOBALS['ORDER_ROWS'] = array();
}

$listed_at = strtotime( '2026-06-17 00:00:00' );

echo "\nA REFUND IS NOT THE WEBSITE SALE\n" . str_repeat( '=', 122 ) . "\n";

// Coin #500, listed in June, sold on the website as order #812, returned and
// refunded with its line as refund #815 - which, made later, has the higher ID
// and comes first.
fresh();
$META[500]  = array( '_ebay_start_time' => '2026-06-17T00:00:00.000Z' );
$CANDIDATES = array( 500 );
$ORDER_ROWS = array(
	array( 'product_id' => '500', 'order_id' => '815' ),
	array( 'product_id' => '500', 'order_id' => '812' ),
);
$ORDERS[815] = new WC_Order_Refund( array( 'created' => $listed_at + 9 * 86400 ) );
$ORDERS[812] = new WC_Order( array( 'created' => $listed_at + 8 * 86400 ) );

$found = TCGiant_Sync_Inventory::find_restocked_after_website_sale();
check( 'the refund is passed over for the order it refunds', $found, array( array( 'product_id' => 500, 'order_id' => 812 ) ) );
check( '  so the order linked on Stock Review is a real order', $ORDERS[ $found[0]['order_id'] ] instanceof WC_Order, true );

// Fully refunded: the coin came back, so its being in stock is right.
fresh();
$META[500]   = array( '_ebay_start_time' => '2026-06-17T00:00:00.000Z' );
$CANDIDATES  = array( 500 );
$ORDER_ROWS  = array( array( 'product_id' => '500', 'order_id' => '815' ), array( 'product_id' => '500', 'order_id' => '812' ) );
$ORDERS[815] = new WC_Order_Refund( array() );
$ORDERS[812] = new WC_Order( array( 'created' => $listed_at + 8 * 86400, 'refunded' => 89.99 ) );
check( 'a sale refunded in full is not listed', TCGiant_Sync_Inventory::find_restocked_after_website_sale(), array() );

// Only a refund for the product, no sale at all.
fresh();
$META[500]   = array( '_ebay_start_time' => '2026-06-17T00:00:00.000Z' );
$CANDIDATES  = array( 500 );
$ORDER_ROWS  = array( array( 'product_id' => '500', 'order_id' => '815' ) );
$ORDERS[815] = new WC_Order_Refund( array() );
check( 'a refund on its own is never taken for a sale', TCGiant_Sync_Inventory::find_restocked_after_website_sale(), array() );

echo "\nONLY WHAT THE OLD SETTLE COULD HAVE DONE\n" . str_repeat( '=', 122 ) . "\n";

fresh();
TCGiant_Sync_Inventory::find_restocked_after_website_sale();
$candidate_sql = $QUERIES[0] ?? '';
// Every settle since 3.27.0 notes which listing it settled; one that did not
// is from before, when a settle could raise stock.
check( 'candidates are settles from before 3.27.0 only', false !== strpos( $candidate_sql, "sitem.meta_key   = '_ebay_stock_settled_item'" ) && false !== strpos( $candidate_sql, 'sitem.meta_id IS NULL' ), true );
check( '  and ones the shop has not already looked at', false !== strpos( $candidate_sql, 'reviewed.meta_id IS NULL' ), true );
check( '  ended, in stock and settled', false !== strpos( $candidate_sql, "listing.meta_value = 'Ended'" ) && false !== strpos( $candidate_sql, "stock.meta_value = 'instock'" ), true );
check( 'their orders are read in one query, not one per product', count( $QUERIES ), 1 );

// An eBay order, or one placed before the listing started, is not the sale.
fresh();
$META[500]   = array( '_ebay_start_time' => '2026-06-17T00:00:00.000Z' );
$CANDIDATES  = array( 500 );
$ORDER_ROWS  = array( array( 'product_id' => '500', 'order_id' => '701' ), array( 'product_id' => '500', 'order_id' => '700' ) );
$ORDERS[701] = new WC_Order( array( 'created' => $listed_at + 86400, 'meta' => array( '_ebay_order_source' => 'ebay' ) ) );
$ORDERS[700] = new WC_Order( array( 'created' => $listed_at - 86400 ) );
check( 'an eBay order, or one from before the listing, is not listed', TCGiant_Sync_Inventory::find_restocked_after_website_sale(), array() );

check( 'the answer is cached', is_array( $TRANSIENTS['tcgiant_restocked_after_sale'] ?? null ), true );

echo "\nTHE BUTTONS ONLY TOUCH LISTINGS THAT HAVE ENDED\n" . str_repeat( '=', 122 ) . "\n";

// Relisted since the cached list was drawn: setting it to 0 here, with the
// push to eBay suppressed, would leave eBay selling a coin the site says is gone.
fresh();
$META[500] = array( '_manage_stock' => 'yes', '_stock' => 1, '_stock_status' => 'instock', '_ebay_listing_status' => 'Active' );
check( 'a product relisted since is not set to 0', TCGiant_Sync_Inventory::zero_stock( 500 ), false );
check( '  its stock is untouched', $META[500]['_stock'], 1 );

$META[500]['_ebay_listing_status'] = 'Ended';
check( 'an ended one is', TCGiant_Sync_Inventory::zero_stock( 500 ), true );
check( '  to 0', $META[500]['_stock'], 0 );
check( '  out of stock', $META[500]['_stock_status'], 'outofstock' );
check( '  and the log says what it was', in_array( 'Stock review: WC #500 stock was 1 before being set to 0.', $LOG, true ), true );

fresh();
$META[600] = array( '_manage_stock' => 'yes', '_stock' => 2, '_stock_status' => 'instock', '_ebay_listing_status' => 'Active', '_ebay_unsold_at_end' => '5' );
check( 'eBay\'s figure is not applied to a live listing', TCGiant_Sync_Inventory::use_ebay_figure( 600 ), null );
check( '  its stock is untouched', $META[600]['_stock'], 2 );
$META[600]['_ebay_listing_status'] = 'Ended';
check( 'it is to an ended one', TCGiant_Sync_Inventory::use_ebay_figure( 600 ), 5 );
check( '  and the note is spent', isset( $META[600]['_ebay_unsold_at_end'] ), false );

$inventory = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-inventory.php' );
check( 'the eBay-higher list is of ended listings only', false !== strpos( $inventory, "listing.meta_key = '_ebay_listing_status' AND listing.meta_value = 'Ended'" ), true );

$view = (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/stock-review.php' );
check( 'the restocked rows start unticked', false === strpos( $view, '" checked />' ), true );
check( '  and an order link is only drawn for a real order', false !== strpos( $view, '$tc_order instanceof WC_Order' ), true );

echo "\nONLY A SALE THE OLD SETTLE COULD HAVE WRITTEN OVER\n" . str_repeat( '=', 122 ) . "\n";

// Ten bullion coins; the old settle ran on 1 Sept. A website sale on 3 Sept came
// after it, took the stock down itself, and left a real figure - that is not
// what this list is for.
fresh();
$META[500]   = array( '_ebay_start_time' => '2026-06-17T00:00:00.000Z', '_ebay_stock_settled_at' => '2026-09-01 12:00:00' );
$CANDIDATES  = array( 500 );
$ORDER_ROWS  = array( array( 'product_id' => '500', 'order_id' => '900' ) );
$ORDERS[900] = new WC_Order( array( 'created' => strtotime( '2026-09-03 10:00:00 UTC' ) ) );
check( 'a website sale after the old settle ran is not listed', TCGiant_Sync_Inventory::find_restocked_after_website_sale(), array() );

fresh();
$META[500]   = array( '_ebay_start_time' => '2026-06-17T00:00:00.000Z', '_ebay_stock_settled_at' => '2026-09-01 12:00:00' );
$CANDIDATES  = array( 500 );
$ORDER_ROWS  = array( array( 'product_id' => '500', 'order_id' => '899' ) );
$ORDERS[899] = new WC_Order( array( 'created' => strtotime( '2026-08-30 10:00:00 UTC' ) ) );
check( 'one before it is', TCGiant_Sync_Inventory::find_restocked_after_website_sale(), array( array( 'product_id' => 500, 'order_id' => 899 ) ) );

echo "\nTHE SHOP LOOKS, AND THE PRODUCT LEAVES THE LIST EITHER WAY\n" . str_repeat( '=', 122 ) . "\n";

fresh();
$META[500] = array( '_manage_stock' => 'yes', '_stock' => 4, '_stock_status' => 'instock', '_ebay_listing_status' => 'Ended' );
$TRANSIENTS['tcgiant_restocked_after_sale'] = array( array( 'product_id' => 500, 'order_id' => 899 ) );
check( 'a genuine restock can be kept', TCGiant_Sync_Inventory::keep_stock( 500 ), true );
check( '  its stock untouched', $META[500]['_stock'], 4 );
check( '  marked as looked at', $META[500]['_ebay_restock_reviewed'] ?? '', 'kept' );
check( '  and the cached list dropped', isset( $TRANSIENTS['tcgiant_restocked_after_sale'] ), false );

TCGiant_Sync_Inventory::zero_stock( 500 );
check( 'one set to 0 is marked as a website sale', $META[500]['_ebay_restock_reviewed'] ?? '', 'zeroed' );

$sold_out_sql = '';
fresh();
TCGiant_Sync_Inventory::find_sold_out_not_removed();
$sold_out_sql = $QUERIES[0] ?? '';
check( '  which keeps it off the "sold out on eBay" list and its trash button', false !== strpos( $sold_out_sql, 'zeroed.meta_id IS NULL' ), true );
check( 'that list only assumes an eBay sell-out for a settle from before 3.27.0', false !== strpos( $sold_out_sql, '( qlisted.meta_value IS NULL AND sitem.meta_id IS NULL )' ), true );

check( 'a new listing forgets the review', in_array( '_ebay_restock_reviewed', TCGiant_Sync_Inventory::SETTLE_KEYS, true ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
