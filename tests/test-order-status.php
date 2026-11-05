<?php
/**
 * Harness: an imported eBay order follows eBay - unpaid, paid, cancelled -
 * and none of it moves WooCommerce stock (3.31.0, audit item J).
 *
 * Until 3.31.0 every eBay order but a cancelled or inactive one arrived as
 * Processing, the status a shop ships, whether or not the buyer had paid; and
 * the import skipped any order it had imported before. An order paid for, or
 * cancelled, after it was imported stayed Processing for good: a shop could
 * ship an order nobody paid for, or one the buyer had cancelled.
 *
 * New orders are found exactly as 3.30.1 found them (fix round 3): each run
 * asks GetOrders for the orders made in the last 24 hours (CreateTimeFrom /
 * CreateTimeTo), every page up to 20, and creates any not here yet - the same
 * request body, byte for byte, so an order whose creation failed is tried
 * again every run for a day. Now an unpaid order arrives as Pending payment,
 * and the plugin's open orders - unpaid, or with a cancellation asked for -
 * are followed by their OrderID (GetOrders OrderIDArray, at most 2 calls a
 * run) until eBay says paid or cancelled, for up to 30 days. An order seen
 * again moves on with eBay - to Processing once paid, to Cancelled once
 * cancelled - while its status is still the one the import set. One a shop
 * changed by hand is left alone, with a note.
 *
 * WooCommerce takes no stock for an eBay order, in any status, and so gives
 * none back: TCGiant_Sync_Inventory::block_double_reduction(), loaded for
 * real here, against WooCommerce's own stock functions as 8.x runs them on
 * status changes. A website order is the control: it takes stock at
 * Processing and gives it back at Cancelled.
 *
 * eBay answers in two ways. Page by page from a script ($PAGES); or as a
 * simulated eBay ($SIM, the spot check's sim-orders.php, ported) that keeps
 * its orders and answers each GetOrders as its reference says - by
 * CreateTime, ModTime or OrderIDArray, the oldest change first, 100 a page -
 * with the import run every 15 minutes of simulated time.
 *
 * Loaded for real: TCGiant_Sync_API (down to the HTTP call, answered in
 * eBay's envelope), TCGiant_Sync_Environment, TCGiant_Sync_Sites,
 * TCGiant_Sync_Orders and TCGiant_Sync_Inventory. WordPress and WooCommerce
 * are stubbed as far as these use them. Nothing is sent anywhere.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-92s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$ROOT = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$OPTIONS   = array();
$AUTOLOAD  = array();
$LOG       = array();
$BODIES    = array(); // GetOrders request bodies, as sent.
$CALLS     = 0;       // GetOrders calls this run.
$ON_CALL   = null;    // Run on each GetOrders, as another request might.
$PAGES     = array(); // What eBay answers each GetOrders with, in turn: XML of orders, or 'fail'.
$SIM       = null;    // The simulated eBay's orders, when it answers instead.
$SIM_WAS   = array(); // An order's first ID => its ID now, for an ID eBay changed.
$SIM_REV   = 0;       // Bumped whenever the simulated eBay's orders change.
$V         = 0;       // Simulated now, in seconds.
$IDX       = array(); // eBay order ID => the WooCommerce orders imported from it.
$IDX_AT    = array(); // eBay order ID => when (V) it was imported.
$IDX_WAS   = array(); // An eBay order ID an order here had before => those orders.
$RUN_T0    = time();  // The real time a simulated run began at: V's anchor.
$SIM_REFUSE = array(); // OrderIDs the simulated eBay refuses to answer for.
$THROW_ON_WRITE = array(); // Orders here whose writes throw, by ID.
$ORDERS    = array(); // WooCommerce orders by ID.
$STOCK     = array(); // Product stock by product ID.
$FILTERS   = array();
$WARNINGS  = array();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function wp_rand( $min = 0, $max = 0 ) { return $min; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { add_filter( $hook, $cb, $prio, $args ); }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['FILTERS'][ $hook ][] = array( $cb, $args ); }
function apply_filters( $hook, $value, ...$rest ) {
	foreach ( $GLOBALS['FILTERS'][ $hook ] ?? array() as $f ) {
		$value = call_user_func_array( $f[0], array_slice( array_merge( array( $value ), $rest ), 0, $f[1] ) );
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['FILTERS'][ $hook ] ?? array() as $f ) {
		call_user_func_array( $f[0], array_slice( $args, 0, $f[1] ) );
	}
}
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function add_option( $name, $value = '' ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; $GLOBALS['AUTOLOAD'][ $name ] = $autoload; return true; }
function delete_option( $name ) { unset( $GLOBALS['OPTIONS'][ $name ] ); return true; }
// Transients, with their expiry in real time (moved back as simulated time passes).
function get_transient( $key ) {
	$t = $GLOBALS['TRANSIENTS'][ $key ] ?? null;
	return null !== $t && $t[1] > time() ? $t[0] : false;
}
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $key ] = array( $value, time() + (int) $ttl ); return true; }
$TRANSIENTS = array();
function wp_cache_delete( $key, $group = '' ) { return true; }
function maybe_unserialize( $v ) { return is_string( $v ) && preg_match( '/^(a|s|i|b):/', $v ) ? unserialize( $v ) : $v; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function is_email( $email ) { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false; }
function email_exists( $email ) { return false; }
function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
function wc_get_order_status_name( $status ) { return array( 'pending' => 'Pending payment', 'processing' => 'Processing', 'on-hold' => 'On hold', 'cancelled' => 'Cancelled', 'completed' => 'Completed' )[ $status ] ?? $status; }

// The live shop, so nothing is held back as from a staging copy.
function wp_load_alloptions() { return array( 'home' => 'https://numismax.com', 'siteurl' => 'https://numismax.com' ); }
function wp_get_environment_type() { return 'production'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

// The database: options read straight from their row, the eBay order an
// order was imported from, and the product a listing belongs to.
class Harness_Wpdb {
	public $options  = 'wp_options';
	public $postmeta = 'wp_postmeta';
	public $prefix   = 'wp_';
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}
	public function query( $query ) { return 1; }
	public function get_var( $query ) {
		if ( preg_match( "/option_name = '([^']+)'/", $query, $m ) ) {
			return array_key_exists( $m[1], $GLOBALS['OPTIONS'] ) ? serialize( $GLOBALS['OPTIONS'][ $m[1] ] ) : null;
		}
		if ( preg_match( "/meta_key = '_ebay_order_id' AND meta_value = '([^']+)'/", $query, $m ) ) {
			$hit = $GLOBALS['IDX'][ $m[1] ] ?? array();
			return 0 === strpos( $query, 'SELECT COUNT' ) ? (string) count( $hit ) : ( $hit ? (string) min( $hit ) : null );
		}
		if ( preg_match( "/meta_key = '_ebay_order_id_was' AND meta_value = '([^']+)'/", $query, $m ) ) {
			return (string) count( $GLOBALS['IDX_WAS'][ $m[1] ] ?? array() );
		}
		if ( preg_match( "/meta_key = '_ebay_item_id' AND meta_value = '([^']+)'/", $query, $m ) ) {
			return '110000000001' === $m[1] ? '501' : null;
		}
		return null;
	}
}
$wpdb = new Harness_Wpdb();

// eBay: GetOrders answered page by page from $PAGES, or by the simulated eBay.
function wp_remote_request( $url, $args ) {
	$call = $args['headers']['X-EBAY-API-CALL-NAME'] ?? '';
	if ( 'GetOrders' === $call ) {
		$GLOBALS['BODIES'][] = $args['body'];
		$GLOBALS['CALLS']++;
		if ( $GLOBALS['ON_CALL'] ) {
			call_user_func( $GLOBALS['ON_CALL'], $args['body'] );
		}
	}
	$page = null !== $GLOBALS['SIM'] ? sim_answer( $args['body'] ) : array_shift( $GLOBALS['PAGES'] );
	if ( 'fail' === $page ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	}
	if ( 'refuse' === $page ) {
		// eBay refusing the call itself (Ack Failure), not a transport error.
		$body = '<?xml version="1.0" encoding="UTF-8"?><' . $call . 'Response xmlns="urn:ebay:apis:eBLBaseComponents"><Timestamp>' . gmdate( 'Y-m-d\TH:i:s.000\Z' ) . '</Timestamp><Ack>Failure</Ack><Errors><ShortMessage>Invalid order ID.</ShortMessage><LongMessage>The order ID is not valid.</LongMessage><ErrorCode>21916272</ErrorCode><SeverityCode>Error</SeverityCode></Errors></' . $call . 'Response>';
		return array( 'body' => $body, 'response' => array( 'code' => 200 ), 'headers' => array() );
	}
	$body = '<?xml version="1.0" encoding="UTF-8"?><' . $call . 'Response xmlns="urn:ebay:apis:eBLBaseComponents"><Timestamp>' . gmdate( 'Y-m-d\TH:i:s.000\Z' ) . '</Timestamp><Ack>Success</Ack>' . (string) $page . '</' . $call . 'Response>';
	return array( 'body' => $body, 'response' => array( 'code' => 200 ), 'headers' => array() );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $header ) { return ''; }

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
}
class TCGiant_Sync_OAuth {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_access_token() { return 'token'; }
	public function refresh_access_token() { return 'fresh-token'; }
	public function get_settings() { return array( 'marketplace' => 'EBAY_US' ); }
}

// --- WooCommerce, as far as orders and their stock go ------------------------

abstract class WC_Abstract_Order {}

/** An order. Its status changes run WooCommerce's status hooks. */
class WC_Order extends WC_Abstract_Order {
	public $id;
	public $status        = 'pending';
	public $meta          = array();
	public $notes         = array();
	public $items         = array();
	public $stock_reduced = false;
	public $total         = 0;
	public $saves         = 0;
	public $changed_at    = null; // When (V) update_status() last ran.
	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_type() { return 'shop_order'; }
	public function get_status() { return $this->status; }
	public function set_status( $status ) { $this->status = $status; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	/** Another plugin's hook on this order failing with an Error, before anything is written. */
	private function writes() {
		if ( isset( $GLOBALS['THROW_ON_WRITE'][ $this->id ] ) ) {
			throw new TypeError( 'a hook on order #' . $this->id . ' failed' );
		}
	}
	public function update_meta_data( $key, $value ) {
		$this->writes();
		if ( '_ebay_order_id' === $key && ( $this->meta[ $key ] ?? null ) !== $value ) {
			// The database: the order is found by its new ID, no longer by the old.
			if ( isset( $this->meta[ $key ] ) ) {
				$GLOBALS['IDX'][ $this->meta[ $key ] ] = array_values( array_diff( $GLOBALS['IDX'][ $this->meta[ $key ] ] ?? array(), array( $this->id ) ) );
			}
			$GLOBALS['IDX'][ $value ][] = $this->id;
			if ( ! isset( $GLOBALS['IDX_AT'][ $value ] ) ) {
				$GLOBALS['IDX_AT'][ $value ] = $GLOBALS['V'];
			}
		}
		$this->meta[ $key ] = $value;
	}
	/** A meta row added beside any of the same key, as WooCommerce's add_meta_data( $k, $v, false ). */
	public function add_meta_data( $key, $value, $unique = false ) {
		$this->writes();
		$this->multi[ $key ][] = $value;
		if ( '_ebay_order_id_was' === $key ) {
			$GLOBALS['IDX_WAS'][ $value ][] = $this->id;
		}
	}
	public $multi = array();
	public function add_order_note( $note ) { $this->writes(); $this->notes[] = $note; }
	public function get_items() { return $this->items; }
	public function add_item( $item ) { $this->items[] = $item; }
	public function set_address( $a, $t = 'billing' ) {
		// Another plugin's hook, or the database, failing as the order is
		// built: create_wc_order() catches it and reports the order not made.
		if ( ! empty( $GLOBALS['THROW_ON_CREATE'] ) ) {
			throw new RuntimeException( 'a new-order hook failed' );
		}
	}
	public function set_billing_email( $e ) {}
	public function set_customer_id( $id ) {}
	public function set_total( $t ) { $this->total = $t; }
	public function get_total() { return $this->total; }
	public function save() { $this->writes(); $this->saves++; return $this->id; }
	/** As WooCommerce: the status, saved, the note, then the transition's hooks. */
	public function update_status( $new, $note = '' ) {
		$this->writes();
		// Another plugin's hook on this order's status change failing with an
		// Error, the order's other writes going through (fix round 5).
		if ( isset( $GLOBALS['THROW_ON_STATUS_OF'][ $this->id ] ) ) {
			throw new TypeError( 'a status hook on order #' . $this->id . ' failed' );
		}
		$old              = $this->status;
		$this->status     = $new;
		$this->changed_at = $GLOBALS['V'];
		$this->save();
		// Another plugin's hook on the change, failing - after the save, as
		// WooCommerce runs status hooks once the order is saved.
		if ( ! empty( $GLOBALS['THROW_ON_STATUS'] ) ) {
			throw new RuntimeException( 'a status hook failed' );
		}
		if ( '' !== $note ) {
			$this->add_order_note( $note );
		}
		if ( $old !== $new ) {
			do_action( 'woocommerce_order_status_' . $new, $this->id, $this );
		}
		return true;
	}
	/** The order's first save, as wc_create_order() then save() run it: pending to its status. */
	public function first_save() {
		if ( 'pending' !== $this->status ) {
			do_action( 'woocommerce_order_status_' . $this->status, $this->id, $this );
		}
	}
}
class Harness_WC_Item {
	public $props = array();
	public $meta  = array();
	public function __call( $name, $args ) { $this->props[ $name ] = $args[0] ?? null; }
	public function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
	public function add_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function get_product_id() { return isset( $this->props['set_product'] ) ? $this->props['set_product']->id : 0; }
	public function get_quantity() { return (int) ( $this->props['set_quantity'] ?? 1 ); }
}
class WC_Order_Item_Product extends Harness_WC_Item {}
class WC_Order_Item_Shipping extends Harness_WC_Item {}
class Harness_Product {
	public $id;
	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
}
function wc_get_product( $id ) { return new Harness_Product( (int) $id ); }
function wc_get_order( $id ) { return $GLOBALS['ORDERS'][ (int) $id ] ?? false; }
function wc_create_order() {
	$id                       = 9001 + count( $GLOBALS['ORDERS'] );
	$GLOBALS['ORDERS'][ $id ] = new WC_Order( $id );
	return $GLOBALS['ORDERS'][ $id ];
}

// WooCommerce 8.x's own stock functions, as its status hooks run them
// (wc-stock-functions.php): a reduction asks woocommerce_can_reduce_order_stock
// and marks each line it took from; putting stock back gives back only what a
// line recorded taking.
function wc_maybe_reduce_stock_levels( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || $order->stock_reduced ) {
		return;
	}
	if ( apply_filters( 'woocommerce_can_reduce_order_stock', true, $order ) ) {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || $item->get_meta( '_reduced_stock' ) || ! $item->get_product_id() ) {
				continue;
			}
			$GLOBALS['STOCK'][ $item->get_product_id() ] -= $item->get_quantity();
			$item->update_meta_data( '_reduced_stock', $item->get_quantity() );
		}
	}
	$order->stock_reduced = true;
}
function wc_maybe_increase_stock_levels( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! $order->stock_reduced ) {
		return;
	}
	if ( apply_filters( 'woocommerce_can_restore_order_stock', true, $order ) ) {
		foreach ( $order->get_items() as $item ) {
			$took = (int) $item->get_meta( '_reduced_stock' );
			if ( ! $took ) {
				continue;
			}
			$GLOBALS['STOCK'][ $item->get_product_id() ] += $took;
			$item->delete_meta_data( '_reduced_stock' );
		}
	}
	$order->stock_reduced = false;
}
foreach ( array( 'processing', 'completed', 'on-hold' ) as $tcg_status ) {
	add_action( 'woocommerce_order_status_' . $tcg_status, 'wc_maybe_reduce_stock_levels' );
}
foreach ( array( 'cancelled', 'pending' ) as $tcg_status ) {
	add_action( 'woocommerce_order_status_' . $tcg_status, 'wc_maybe_increase_stock_levels' );
}

require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $ROOT . '/includes/class-tcgiant-sync-orders.php';

set_error_handler( function ( $no, $message ) {
	if ( $no & ( E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED ) ) {
		$GLOBALS['WARNINGS'][] = $message;
		return true;
	}
	return false;
} );

// The filter the inventory class's constructor adds, from the class itself.
$INVENTORY = ( new ReflectionClass( 'TCGiant_Sync_Inventory' ) )->newInstanceWithoutConstructor();
add_filter( 'woocommerce_can_reduce_order_stock', array( $INVENTORY, 'block_double_reduction' ), 10, 2 );

$IMPORT = ( new ReflectionClass( 'TCGiant_Sync_Orders' ) )->newInstanceWithoutConstructor();

// The follow list's option, by name, so this harness runs whole against 3.30.1 too.
$FOLLOW = 'tcgiant_sync_orders_follow';

/** One run of the order import, as the 15-minute cron makes it. */
function run_import() {
	$GLOBALS['BODIES'] = array();
	$GLOBALS['CALLS']  = 0;
	// Each run is a new request, with new instances.
	$slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
	if ( PHP_VERSION_ID < 80100 ) {
		$slot->setAccessible( true );
	}
	$slot->setValue( null, null );
	$GLOBALS['IMPORT'] = ( new ReflectionClass( 'TCGiant_Sync_Orders' ) )->newInstanceWithoutConstructor();
	// Simulated time is anchored to the real time the run began (fix round
	// 4): the simulated eBay never reads the clock while it answers, so a
	// second ticking between the request and the answer moves nothing.
	$GLOBALS['RUN_T0'] = time();
	$GLOBALS['IMPORT']->import_recent_orders();
	// wc_create_order() then save(): the new orders' first status change.
	foreach ( $GLOBALS['ORDERS'] as $order ) {
		if ( ! isset( $order->meta['__first_saved'] ) ) {
			$order->meta['__first_saved'] = true;
			$order->first_save();
		}
	}
}

function fresh_shop() {
	$GLOBALS['OPTIONS']         = array( 'tcgiant_sync_ebay_settings' => array( 'enable_order_import' => 1, 'marketplace' => 'EBAY_US' ) );
	$GLOBALS['AUTOLOAD']        = array();
	$GLOBALS['ORDERS']          = array();
	$GLOBALS['IDX']             = array();
	$GLOBALS['IDX_AT']          = array();
	$GLOBALS['LOG']             = array();
	$GLOBALS['STOCK']           = array( 501 => 5 );
	$GLOBALS['PAGES']           = array();
	$GLOBALS['SIM']             = null;
	$GLOBALS['SIM_WAS']         = array();
	$GLOBALS['V']               = 0;
	$GLOBALS['ON_CALL']         = null;
	$GLOBALS['THROW_ON_CREATE'] = false;
	$GLOBALS['THROW_ON_WRITE']  = array();
	$GLOBALS['THROW_ON_STATUS_OF'] = array();
	$GLOBALS['TRANSIENTS']      = array();
	$GLOBALS['SIM_REFUSE_ALL']  = false;
	$GLOBALS['SIM_REFUSE_BYID'] = false;
	$GLOBALS['IDX_WAS']         = array();
	$GLOBALS['SIM_REFUSE']      = array();
	$GLOBALS['RUN_T0']          = time();
}

/** The TransactionID of an order's one line: its own, as on eBay. */
function txid( $id ) { return sprintf( '%u', crc32( $id ) ); }

/** An eBay order, as GetOrders gives it. */
function ebay_order( $id, $status, $qty = 1, $tx = null ) {
	return '<Order><OrderID>' . $id . '</OrderID><OrderStatus>' . $status . '</OrderStatus><Total currencyID="USD">' . ( 12.5 * $qty ) . '</Total><BuyerUserID>buyer_' . $id . '</BuyerUserID>'
		. '<ShippingAddress><Name>Ann Buyer</Name><Street1>1 Main St</Street1><CityName>Springfield</CityName><Country>US</Country><PostalCode>62701</PostalCode></ShippingAddress>'
		. '<TransactionArray><Transaction><TransactionID>' . ( null === $tx ? txid( $id ) : $tx ) . '</TransactionID><QuantityPurchased>' . $qty . '</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice>'
		. '<Item><ItemID>110000000001</ItemID><Title>1oz Britannia</Title></Item></Transaction></TransactionArray></Order>';
}

/** A GetOrders page holding these orders. */
function page( array $orders, $pages = 1 ) {
	return '<PaginationResult><TotalNumberOfPages>' . $pages . '</TotalNumberOfPages></PaginationResult>'
		. ( $orders ? '<OrderArray>' . implode( '', $orders ) . '</OrderArray>' : '<OrderArray/>' );
}

/** The imported WooCommerce order for an eBay order. */
/** The order here for an eBay order ID it holds now, or held before eBay gave it a new one. */
function wc_for_any( $ebay_id ) {
	$o = wc_for( $ebay_id );
	if ( $o ) {
		return $o;
	}
	$was = $GLOBALS['IDX_WAS'][ $ebay_id ] ?? array();
	return $was ? $GLOBALS['ORDERS'][ min( $was ) ] : null;
}

function wc_for( $ebay_id ) {
	$ids = $GLOBALS['IDX'][ $ebay_id ] ?? array();
	return $ids ? $GLOBALS['ORDERS'][ min( $ids ) ] : null;
}

/** An order as 3.30 or an earlier run left it, made here directly. */
function stored_order( $ebay_id, $status, array $meta = array() ) {
	$o = wc_create_order();
	$o->status = $status;
	$o->meta['__first_saved'] = true;
	$o->update_meta_data( '_ebay_order_id', $ebay_id );
	$o->update_meta_data( '_ebay_order_source', 'ebay' );
	foreach ( $meta as $k => $v ) {
		$o->update_meta_data( $k, $v );
	}
	return $o;
}

function tag( $body, $name ) {
	return preg_match( '#<' . $name . '>([^<]*)</' . $name . '>#', (string) $body, $m ) ? $m[1] : null;
}

/** What the plugin wrote inside GetOrdersRequest. */
function inner( $body ) {
	return preg_match( '#<GetOrdersRequest xmlns="urn:ebay:apis:eBLBaseComponents">\n(.*)\n</GetOrdersRequest>#s', (string) $body, $m ) ? $m[1] : null;
}

function logged( $text ) {
	foreach ( $GLOBALS['LOG'] as $entry ) {
		if ( false !== strpos( $entry[1], $text ) ) {
			return true;
		}
	}
	return false;
}

function last_note( $order ) {
	$notes = $order->notes;
	return end( $notes );
}

// --- The simulated eBay (the spot check's sim-orders.php, ported) -------------
//
// Every eBay time is kept in simulated seconds (V); what the plugin stores
// (the follow list's times) moves back as simulated time passes, so time()
// always stands for "now".

function v2r( $v ) { return (int) round( $v - $GLOBALS['V'] + $GLOBALS['RUN_T0'] ); }
function r2v( $r ) { return $r - $GLOBALS['RUN_T0'] + $GLOBALS['V']; }

function sim_xml( $id, $o ) {
	$t = function ( $v ) { return gmdate( 'Y-m-d\TH:i:s.000\Z', v2r( $v ) ); };
	return '<Order><OrderID>' . $id . '</OrderID><OrderStatus>' . $o['status'] . '</OrderStatus><Total currencyID="USD">12.5</Total><BuyerUserID>b' . $id . '</BuyerUserID>'
		. '<CreatedTime>' . $t( $o['created'] ) . '</CreatedTime>'
		. ( null !== $o['paid'] ? '<PaidTime>' . $t( $o['paid'] ) . '</PaidTime>' : '' )
		. ( null !== $o['shipped'] ? '<ShippedTime>' . $t( $o['shipped'] ) . '</ShippedTime>' : '' )
		. '<CheckoutStatus><LastModifiedTime>' . $t( $o['lmt'] ) . '</LastModifiedTime></CheckoutStatus>'
		. '<ShippingAddress><Name>A</Name><Country>US</Country></ShippingAddress>'
		. '<TransactionArray>' . implode( '', array_map( function ( $tx ) {
			return '<Transaction><TransactionID>' . $tx . '</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice>'
				. '<Item><ItemID>110000000001</ItemID><Title>Card</Title></Item></Transaction>';
		}, $o['lines'] ?? array( $o['tx'] ) ) ) . '</TransactionArray></Order>';
}

/**
 * GetOrders as eBay's reference has it: OrderIDArray (any other filter then
 * ignored; an order asked for by the ID it had before it was paid is found
 * too), or the orders made (CreateTime) or changed (ModTime) in the window;
 * the oldest change first; EntriesPerPage a page (25 if not given).
 */
function sim_answer( $body ) {
	static $cache = array();
	if ( ! empty( $GLOBALS['SIM_REFUSE_ALL'] ) ) {
		return 'refuse';
	}
	$page = max( 1, (int) tag( $body, 'PageNumber' ) );
	$per  = (int) tag( $body, 'EntriesPerPage' );
	$per  = $per > 0 ? $per : 25;
	// The same question again in the same run (its next page): the same list.
	$key = $GLOBALS['V'] . '|' . $GLOBALS['SIM_REV'] . '|' . preg_replace( '#<PageNumber>\d+</PageNumber>#', '', $body );
	if ( isset( $cache[ $key ] ) ) {
		$ids = $cache[ $key ];
		return sim_page( $ids, $page, $per );
	}
	$hits = array();
	if ( false !== strpos( $body, '<OrderIDArray>' ) ) {
		preg_match_all( '#<OrderID>([^<]*)</OrderID>#', $body, $m );
		if ( ! empty( $GLOBALS['SIM_REFUSE_BYID'] ) || array_intersect( $m[1], $GLOBALS['SIM_REFUSE'] ) ) {
			return 'refuse';
		}
		foreach ( $m[1] as $asked ) {
			$id = isset( $GLOBALS['SIM'][ $asked ] ) ? $asked : ( $GLOBALS['SIM_WAS'][ $asked ] ?? null );
			if ( null !== $id && isset( $GLOBALS['SIM'][ $id ] ) ) {
				$hits[ $id ] = $GLOBALS['SIM'][ $id ]['mod'];
			}
		}
	} else {
		list( $field, $name ) = null !== tag( $body, 'CreateTimeFrom' ) ? array( 'created', 'CreateTime' ) : array( 'mod', 'ModTime' );
		$from = r2v( strtotime( (string) tag( $body, $name . 'From' ) ) );
		$to   = r2v( strtotime( (string) tag( $body, $name . 'To' ) ) );
		foreach ( $GLOBALS['SIM'] as $id => $o ) {
			if ( $o[ $field ] >= $from && $o[ $field ] <= $to ) {
				$hits[ $id ] = $o['mod'];
			}
		}
	}
	$ids  = array_map( 'strval', array_keys( $hits ) );
	$mods = array_values( $hits );
	array_multisort( $mods, SORT_ASC, SORT_NUMERIC, $ids, SORT_ASC, SORT_STRING );
	$cache = array( $key => $ids );
	return sim_page( $ids, $page, $per );
}

/** One page of the orders a question found. */
function sim_page( array $ids, $page, $per ) {
	$total = max( 1, (int) ceil( count( $ids ) / $per ) );
	$xml   = '';
	foreach ( array_slice( $ids, ( $page - 1 ) * $per, $per ) as $id ) {
		$xml .= sim_xml( $id, $GLOBALS['SIM'][ $id ] );
	}
	return '<PaginationResult><TotalNumberOfPages>' . $total . '</TotalNumberOfPages></PaginationResult>' . ( '' !== $xml ? '<OrderArray>' . $xml . '</OrderArray>' : '<OrderArray/>' );
}

/** Let $secs of simulated time pass: the plugin's stored times move back. */
function advance( $secs ) {
	$GLOBALS['V'] += $secs;
	$list = &$GLOBALS['OPTIONS'][ $GLOBALS['FOLLOW'] ];
	if ( is_array( $list ) ) {
		foreach ( $list as $id => $e ) {
			$list[ $id ]['since']   = (int) $e['since'] - $secs;
			$list[ $id ]['checked'] = (int) $e['checked'] - $secs;
		}
	} else {
		unset( $GLOBALS['OPTIONS'][ $GLOBALS['FOLLOW'] ] );
	}
	unset( $list );
	foreach ( $GLOBALS['TRANSIENTS'] as $k => $t ) {
		$GLOBALS['TRANSIENTS'][ $k ][1] = $t[1] - $secs;
	}
}

/** A new order on eBay: paid at once (eBay's managed payments), or not yet. */
function sim_new( $id, $v, $paid = true ) {
	$GLOBALS['SIM_REV']++;
	$GLOBALS['SIM'][ $id ] = array( 'status' => $paid ? 'Completed' : 'Active', 'created' => $v, 'paid' => $paid ? $v : null, 'shipped' => null, 'lmt' => $v, 'mod' => $v, 'new' => true, 'tx' => txid( $id ) );
}

/** An order imported here already. */
function sim_imported( $id, $v ) {
	$GLOBALS['SIM_REV']++;
	$GLOBALS['SIM'][ $id ] = array( 'status' => 'Completed', 'created' => $v, 'paid' => $v, 'shipped' => null, 'lmt' => $v, 'mod' => $v, 'new' => false, 'tx' => txid( $id ) );
	$o = stored_order( $id, 'processing', array( '_ebay_order_status' => 'Completed', '_ebay_order_wc_status' => 'processing' ) );
}

/** eBay changes an order: paid, cancelled; paid under a new ID if given. */
function sim_set( $id, $status, $new_id = null ) {
	$GLOBALS['SIM_REV']++;
	$o = $GLOBALS['SIM'][ $id ];
	$o['status'] = $status;
	$o['mod']    = $GLOBALS['V'];
	$o['lmt']    = $GLOBALS['V'];
	if ( 'Completed' === $status ) {
		$o['paid'] = $GLOBALS['V'];
	}
	if ( null !== $new_id ) {
		unset( $GLOBALS['SIM'][ $id ] );
		$o['was']                     = $id;
		$GLOBALS['SIM_WAS'][ $id ]    = $new_id;
		$GLOBALS['SIM'][ $new_id ]    = $o;
	} else {
		$GLOBALS['SIM'][ $id ] = $o;
	}
}

/** eBay combines unpaid orders into a Combined Invoice, under a new ID, Active. */
function sim_combine( array $ids, $new_id ) {
	$GLOBALS['SIM_REV']++;
	$lines = array();
	foreach ( $ids as $id ) {
		$lines = array_merge( $lines, $GLOBALS['SIM'][ $id ]['lines'] ?? array( $GLOBALS['SIM'][ $id ]['tx'] ) );
		unset( $GLOBALS['SIM'][ $id ] );
		$GLOBALS['SIM_WAS'][ $id ] = $new_id;
	}
	$GLOBALS['SIM'][ $new_id ] = array( 'status' => 'Active', 'created' => $GLOBALS['V'], 'paid' => null, 'shipped' => null, 'lmt' => $GLOBALS['V'], 'mod' => $GLOBALS['V'], 'new' => false, 'tx' => $lines[0], 'lines' => $lines );
}

function sim_reset() {
	fresh_shop();
	$GLOBALS['SIM'] = array();
}

/**
 * Run the cron every $step seconds until $until (V), applying $events
 * (V => callable) as their time comes. Returns what each run cost.
 */
function sim_runs( $until, array $events = array(), $step = 900 ) {
	ksort( $events );
	$runs = array();
	while ( $GLOBALS['V'] < $until ) {
		advance( $step );
		foreach ( $events as $at => $fn ) {
			if ( $at <= $GLOBALS['V'] ) {
				$fn();
				$GLOBALS['SIM_REV']++;
				unset( $events[ $at ] );
			}
		}
		$GLOBALS['LOG'] = array();
		run_import();
		$runs[] = array(
			'v'      => $GLOBALS['V'],
			'calls'  => $GLOBALS['CALLS'],
			'follow' => count( $GLOBALS['OPTIONS'][ $GLOBALS['FOLLOW'] ] ?? array() ),
			'capped' => logged( 'Stopped at the 20-page cap' ),
		);
	}
	return $runs;
}

/** What became of eBay's orders here. */
function sim_tally() {
	$lost  = array();
	$dup   = array();
	$late  = 0;
	foreach ( $GLOBALS['SIM'] as $id => $o ) {
		$key = $o['was'] ?? $id;
		$n   = count( $GLOBALS['IDX'][ $key ] ?? array() ) + ( isset( $o['was'] ) ? count( $GLOBALS['IDX'][ $id ] ?? array() ) : 0 );
		if ( ! empty( $o['new'] ) && 0 === $n ) {
			$lost[] = $id;
		}
		if ( $n > 1 ) {
			$dup[] = $id;
		}
		if ( ! empty( $o['new'] ) && isset( $GLOBALS['IDX_AT'][ $key ] ) ) {
			$late = max( $late, $GLOBALS['IDX_AT'][ $key ] - $o['created'] );
		}
	}
	return array( 'lost' => count( $lost ), 'dup' => count( $dup ), 'late_h' => round( $late / 3600, 1 ) );
}

function max_calls( array $runs ) {
	return $runs ? max( array_column( $runs, 'calls' ) ) : 0;
}

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nEACH EBAY STATUS, AS IT ARRIVES\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
$statuses = array(
	'Active'        => 'pending',
	'InProcess'     => 'pending',
	'Completed'     => 'processing',
	'Cancelled'     => 'cancelled',
	'CancelPending' => 'on-hold',
	'Inactive'      => 'on-hold',
	'CustomCode'    => 'processing',
);
$i   = 0;
$xml = array();
foreach ( $statuses as $ebay => $wc ) {
	$xml[] = ebay_order( '30-0000' . ( ++$i ) . '-00001', $ebay );
}
$PAGES[] = page( $xml );
run_import();
$i = 0;
foreach ( $statuses as $ebay => $wc ) {
	$o = wc_for( '30-0000' . ( ++$i ) . '-00001' );
	check( sprintf( 'eBay %s arrives as %s', $ebay, $wc ), $o ? $o->status : null, $wc );
}
check( 'an unpaid order is no longer Processing, the status a shop ships (it was until 3.31.0)', wc_for( '30-00001-00001' )->status !== 'processing', true );
$o = wc_for( '30-00001-00001' );
check( '  the order keeps eBay\'s status and the one set from it', array( $o->meta['_ebay_order_status'] ?? null, $o->meta['_ebay_order_wc_status'] ?? null ), array( 'Active', 'pending' ) );
check( 'none of the seven took WooCommerce stock: the sync keeps it from eBay', $STOCK[501], 5 );
check( 'the run says 7 created', logged( 'Order Import: Complete. 7 created, 0 skipped (already imported).' ), true );
check( 'the open ones - Active, InProcess, CancelPending - are followed from now on', array_keys( $OPTIONS[ $FOLLOW ] ?? array() ), array( '30-00001-00001', '30-00002-00001', '30-00005-00001' ) );
check( '  and that run asked eBay nothing more: they were in its own read', count( $BODIES ), 1 );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nNEW ORDERS ARE FOUND EXACTLY AS 3.30.1 FOUND THEM (FIX ROUND 3)\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
$PAGES[] = page( array() );
$before  = time();
run_import();
$after = time();
$body  = (string) inner( $BODIES[0] ?? '' );
$from  = (string) tag( $body, 'CreateTimeFrom' );
$to    = (string) tag( $body, 'CreateTimeTo' );
check( 'each run asks for the orders made in the last 24 hours - 3.30.1\'s request, byte for byte', $body, "\n<CreateTimeFrom>" . $from . "</CreateTimeFrom>\n<CreateTimeTo>" . $to . "</CreateTimeTo>\n<OrderRole>Seller</OrderRole>\n<OrderStatus>All</OrderStatus>\n<Pagination>\n<EntriesPerPage>100</EntriesPerPage>\n<PageNumber>1</PageNumber>\n</Pagination>" );
check( '  from 24 hours ago to now', array( strtotime( $to ) - strtotime( $from ), strtotime( $to ) >= $before && strtotime( $to ) <= $after ), array( 86400, true ) );
check( '  nothing kept between runs: no mark, no floor, no list to try again', array_values( preg_grep( '/^tcgiant_sync_orders/', array_keys( $OPTIONS ) ) ), array() );
check( '  and an empty day says so, as 3.30.1 did', logged( 'Order Import: No new orders found.' ), true );
$PAGES[] = page( array() );
run_import();
check( 'the next run asks for the last 24 hours again', abs( strtotime( (string) tag( $BODIES[0] ?? '', 'CreateTimeFrom' ) ) - ( time() - 86400 ) ) <= 2, true );

// A page that fails: what was read is imported (3.30.1).
fresh_shop();
$PAGES = array( page( array( ebay_order( '31-00001-00001', 'Completed' ) ), 2 ), 'fail', 'fail', 'fail' );
run_import();
check( 'a day whose second page fails: the first page\'s orders are created', array( null !== wc_for( '31-00001-00001' ), logged( 'Order Import: Failed' ) ), array( true, false ) );
$PAGES = array( 'fail', 'fail', 'fail' );
run_import();
check( 'a day whose first page fails: the error is logged, nothing else happens', array( logged( 'Order Import: Failed — cURL error 28: Operation timed out' ), count( $ORDERS ) ), array( true, 1 ) );

// The 20-page cap, as 3.30.1.
fresh_shop();
for ( $tcg_p = 1; $tcg_p <= 21; $tcg_p++ ) {
	$PAGES[] = page( array( ebay_order( sprintf( '37-%05d-00001', $tcg_p ), 'Completed' ) ), 21 );
}
run_import();
check( '21 pages: 20 read, the cap logged, as 3.30.1', array( count( $BODIES ), logged( 'Order Import: Stopped at the 20-page cap (21 pages available).' ), count( $ORDERS ) ), array( 20, true, 20 ) );

// A creation that fails is tried again by every run of the day (3.30.1).
fresh_shop();
$THROW_ON_CREATE = true;
$PAGES[]         = page( array( ebay_order( '86-00001-00001', 'Completed' ) ) );
run_import();
$THROW_ON_CREATE = false;
check( 'a creation that fails: not made, the error logged', array( null === wc_for( '86-00001-00001' ), logged( 'Order Import: Exception — a new-order hook failed' ) ), array( true, true ) );
$PAGES[] = page( array( ebay_order( '86-00001-00001', 'Completed' ) ) );
run_import();
check( '  the next run reads the same day, and creates it', null !== wc_for( '86-00001-00001' ), true );

$src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-orders.php' );
check( 'no mark, no floor, no list to try again, nothing on saving the settings (fix rounds 1-2, gone)', array( false !== strpos( $src, 'ModTimeFrom' ), false !== strpos( $src, 'orders_read_to' ), false !== strpos( $src, 'orders_floor' ), false !== strpos( $src, 'orders_retry' ), false !== strpos( $src, 'update_option_tcgiant_sync_ebay_settings' ) ), array( false, false, false, false, false ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nAN ORDER READ AGAIN IN ITS DAY MOVES ON WITH EBAY\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
$PAGES[] = page( array( ebay_order( '40-00001-00001', 'Active' ), ebay_order( '40-00002-00002', 'Active' ), ebay_order( '40-00003-00003', 'Completed' ) ) );
run_import();
check( 'two unpaid orders and a paid one arrive', array( wc_for( '40-00001-00001' )->status, wc_for( '40-00002-00002' )->status, wc_for( '40-00003-00003' )->status ), array( 'pending', 'pending', 'processing' ) );

// The first is paid; the second cancelled; the third read again unchanged.
$PAGES[] = page( array( ebay_order( '40-00001-00001', 'Completed' ), ebay_order( '40-00002-00002', 'Cancelled' ), ebay_order( '40-00003-00003', 'Completed' ) ) );
run_import();
$paid = wc_for( '40-00001-00001' );
$gone = wc_for( '40-00002-00002' );
check( 'paid on eBay later: Pending payment becomes Processing', $paid->status, 'processing' );
check( '  with a note saying why', last_note( $paid ), 'eBay now says this order is Completed, so TCGiant Sync set it to Processing.' );
check( '  and its records moved on', array( $paid->meta['_ebay_order_status'], $paid->meta['_ebay_order_wc_status'] ), array( 'Completed', 'processing' ) );
check( 'cancelled on eBay: Pending payment becomes Cancelled', $gone->status, 'cancelled' );
check( 'an order read again with nothing new is left exactly as it was', array( wc_for( '40-00003-00003' )->status, count( wc_for( '40-00003-00003' )->notes ) ), array( 'processing', 1 ) );
check( 'no order is made twice', count( $ORDERS ), 3 );
check( 'the run says 2 brought up to date', logged( 'Order Import: Complete. 0 created, 1 skipped (already imported), 2 brought up to date with eBay.' ), true );
check( 'and the log names each', logged( 'eBay order 40-00001-00001 is now Completed - WC Order #9001 set from pending to processing.' ), true );
check( 'paid and cancelled, they are followed no more; the day\'s read was all it took', array( isset( $OPTIONS[ $FOLLOW ] ), count( $BODIES ) ), array( false, 1 ) );
check( 'WooCommerce stock untouched through all of it', $STOCK[501], 5 );

// Paid, then cancelled: Processing to Cancelled. Still no stock movement.
$PAGES[] = page( array( ebay_order( '40-00001-00001', 'Cancelled' ) ) );
run_import();
check( 'a paid order cancelled on eBay: Processing becomes Cancelled - not shipped', $paid->status, 'cancelled' );
check( '  and WooCommerce puts back nothing, as it took nothing: the sync follows eBay\'s quantity', $STOCK[501], 5 );

// A cancellation asked for: On hold, so it is not shipped while the seller answers.
fresh_shop();
$PAGES[] = page( array( ebay_order( '41-00001-00001', 'Completed' ) ) );
run_import();
$PAGES[] = page( array( ebay_order( '41-00001-00001', 'CancelPending' ) ) );
run_import();
check( 'a buyer asks to cancel a paid order: Processing becomes On hold, and it is followed', array( wc_for( '41-00001-00001' )->status, array_keys( $OPTIONS[ $FOLLOW ] ?? array() ) ), array( 'on-hold', array( '41-00001-00001' ) ) );
$PAGES[] = page( array( ebay_order( '41-00001-00001', 'Completed' ) ) );
run_import();
check( '  the seller refuses: back to Processing, followed no more', array( wc_for( '41-00001-00001' )->status, isset( $OPTIONS[ $FOLLOW ] ) ), array( 'processing', false ) );

// The next run reads an order again: nothing happens the second time.
$o     = wc_for( '41-00001-00001' );
$notes = count( $o->notes );
$saves = $o->saves;
$PAGES[] = page( array( ebay_order( '41-00001-00001', 'Completed' ) ) );
run_import();
check( 'read again by the next run, nothing new: no note, no save', array( count( $o->notes ), $o->saves ), array( $notes, $saves ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nAN OPEN ORDER IS FOLLOWED BY ITS ORDERID ONCE ITS DAY HAS PASSED (FIX ROUND 3)\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
$PAGES[] = page( array( ebay_order( '42-00001-00001', 'Active' ), ebay_order( '42-00002-00002', 'Completed' ), ebay_order( '42-00003-00003', 'CancelPending' ), ebay_order( '42-00004-00004', 'InProcess' ) ) );
run_import();
check( 'the unpaid orders and the one with a cancellation asked for are followed; the paid one is not', array_keys( $OPTIONS[ $FOLLOW ] ?? array() ), array( '42-00001-00001', '42-00003-00003', '42-00004-00004' ) );
$entry = $OPTIONS[ $FOLLOW ]['42-00001-00001'];
check( '  each with the order here, when it was first followed, and its line', array( $entry['wc'], abs( $entry['since'] - time() ) <= 2, $entry['items'] ), array( wc_for( '42-00001-00001' )->get_id(), true, array( '110000000001-' . txid( '42-00001-00001' ) ) ) );
check( '  stored without autoload', $AUTOLOAD[ $FOLLOW ] ?? 'unset', false );

// A day on: the CreateTime read no longer brings them.
$PAGES[] = page( array() );
$PAGES[] = page( array( ebay_order( '42-00001-00001', 'Completed' ), ebay_order( '42-00003-00003', 'Cancelled' ), ebay_order( '42-00004-00004', 'Active' ) ) );
run_import();
check( 'a day on: the last day\'s read, then one call asks for the three by their OrderID', count( $BODIES ), 2 );
check( '  that call, as sent - the one new request body of 3.31.0', inner( $BODIES[1] ?? '' ), "\n<OrderIDArray>\n<OrderID>42-00001-00001</OrderID>\n<OrderID>42-00003-00003</OrderID>\n<OrderID>42-00004-00004</OrderID>\n</OrderIDArray>\n<OrderRole>Seller</OrderRole>\n<Pagination>\n<EntriesPerPage>100</EntriesPerPage>\n<PageNumber>1</PageNumber>\n</Pagination>" );
$o1 = wc_for( '42-00001-00001' );
check( 'paid: Pending payment becomes Processing, with the note', array( $o1->status, last_note( $o1 ) ), array( 'processing', 'eBay now says this order is Completed, so TCGiant Sync set it to Processing.' ) );
check( 'cancelled after a cancellation was asked for: On hold becomes Cancelled', wc_for( '42-00003-00003' )->status, 'cancelled' );
check( 'still unpaid: still Pending payment, still followed, and only it', array( wc_for( '42-00004-00004' )->status, array_keys( $OPTIONS[ $FOLLOW ] ?? array() ) ), array( 'pending', array( '42-00004-00004' ) ) );
check( '  the run says so', logged( 'Order Import: 3 open eBay order(s) asked about by their OrderID (1 call(s)): 2 brought up to date, 2 no longer open; 1 still followed.' ), true );
check( 'nothing created twice, stock untouched', array( count( $ORDERS ), $STOCK[501] ), array( 4, 5 ) );

// eBay says nothing of an order: it stays followed, and is asked about again.
$checked = $OPTIONS[ $FOLLOW ]['42-00004-00004']['checked'];
$OPTIONS[ $FOLLOW ]['42-00004-00004']['checked'] = $checked - 900;
$PAGES[] = page( array() );
$PAGES[] = page( array() );
run_import();
check( 'an order eBay does not return: still followed, marked as asked', array( isset( $OPTIONS[ $FOLLOW ]['42-00004-00004'] ), $OPTIONS[ $FOLLOW ]['42-00004-00004']['checked'] >= time() - 2 ), array( true, true ) );

// A cancellation asked for, then refused: On hold, then Processing.
fresh_shop();
$PAGES[] = page( array( ebay_order( '43-00001-00001', 'CancelPending' ) ) );
run_import();
$PAGES[] = page( array() );
$PAGES[] = page( array( ebay_order( '43-00001-00001', 'Completed' ) ) );
run_import();
check( 'a cancellation asked for, then refused by the seller: On hold becomes Processing', array( wc_for( '43-00001-00001' )->status, isset( $OPTIONS[ $FOLLOW ] ) ), array( 'processing', false ) );

// Unpaid, then: cancelled; a cancellation asked for; Inactive.
fresh_shop();
$PAGES[] = page( array( ebay_order( '43-00002-00002', 'Active' ), ebay_order( '43-00003-00003', 'Active' ), ebay_order( '43-00004-00004', 'Active' ) ) );
run_import();
$PAGES[] = page( array() );
$PAGES[] = page( array( ebay_order( '43-00002-00002', 'Cancelled' ), ebay_order( '43-00003-00003', 'CancelPending' ), ebay_order( '43-00004-00004', 'Inactive' ) ) );
run_import();
check( 'unpaid, then cancelled: Cancelled; a cancellation asked for: On hold; Inactive: On hold', array( wc_for( '43-00002-00002' )->status, wc_for( '43-00003-00003' )->status, wc_for( '43-00004-00004' )->status ), array( 'cancelled', 'on-hold', 'on-hold' ) );
check( '  only the one with a cancellation asked for still followed', array_keys( $OPTIONS[ $FOLLOW ] ?? array() ), array( '43-00003-00003' ) );
$PAGES[] = page( array() );
$PAGES[] = page( array( ebay_order( '43-00003-00003', 'CancelComplete' ) ) );
run_import();
check( '  then the cancellation completed: Cancelled, followed no more', array( wc_for( '43-00003-00003' )->status, isset( $OPTIONS[ $FOLLOW ] ) ), array( 'cancelled', false ) );

// The order's ID changes when it is paid (eBay's reference says it does, and
// takes either ID when asked): the answer is matched by its line.
fresh_shop();
$PAGES[] = page( array( ebay_order( '44-00001-00001', 'Active' ) ) );
run_import();
$PAGES[] = page( array() );
$PAGES[] = page( array( ebay_order( '44-99999-00001', 'Completed', 1, txid( '44-00001-00001' ) ) ) );
run_import();
$tcg_o = wc_for( '44-99999-00001' );
check( 'paid under a new order ID: found by its line, Processing, followed no more, nothing created', array( $tcg_o ? $tcg_o->status : null, isset( $OPTIONS[ $FOLLOW ] ), count( $ORDERS ) ), array( 'processing', false, 1 ) );
check( '  and it takes the new ID here too, the old one kept, so no later read creates it again (fix round 5)', array( $tcg_o ? $tcg_o->get_id() : null, $tcg_o ? ( $tcg_o->multi['_ebay_order_id_was'] ?? null ) : null ), array( 9001, array( '44-00001-00001' ) ) );
fresh_shop();
$PAGES[] = page( array( ebay_order( '44-00002-00002', 'Active' ) ) );
run_import();
$PAGES[] = page( array() );
$PAGES[] = page( array( ebay_order( '44-77777-00002', 'Completed' ) ) );
run_import();
check( 'an answer that is none of the orders asked about is left alone', array( wc_for( '44-00002-00002' )->status, count( $OPTIONS[ $FOLLOW ] ?? array() ), count( $ORDERS ) ), array( 'pending', 1, 1 ) );

// Thirty days: no longer followed, and not asked about.
fresh_shop();
$PAGES[] = page( array( ebay_order( '45-00001-00001', 'Active' ), ebay_order( '45-00002-00002', 'Active' ) ) );
run_import();
$OPTIONS[ $FOLLOW ]['45-00001-00001']['since'] = time() - 30 * 86400 - 60;
$OPTIONS[ $FOLLOW ]['45-00002-00002']['since'] = time() - 30 * 86400 + 60;
$PAGES[] = page( array() );
$PAGES[] = page( array() );
run_import();
check( 'followed for 30 days and a minute: dropped, not asked about', array( isset( $OPTIONS[ $FOLLOW ]['45-00001-00001'] ), false !== strpos( (string) inner( $BODIES[1] ?? '' ), '45-00001-00001' ) ), array( false, false ) );
check( '  a minute short of 30 days: still followed, and asked about', array( isset( $OPTIONS[ $FOLLOW ]['45-00002-00002'] ), false !== strpos( (string) inner( $BODIES[1] ?? '' ), '45-00002-00002' ) ), array( true, true ) );
check( '  the run says so; the order itself is left Pending payment for the shop', array( logged( '1 followed for 30 days and dropped' ), wc_for( '45-00001-00001' )->status ), array( true, 'pending' ) );

// Deleted here, or in the Trash: dropped without asking eBay.
fresh_shop();
$PAGES[] = page( array( ebay_order( '46-00001-00001', 'Active' ), ebay_order( '46-00002-00002', 'Active' ) ) );
run_import();
unset( $ORDERS[ wc_for( '46-00001-00001' )->get_id() ] );
wc_for( '46-00002-00002' )->status = 'trash';
$PAGES[] = page( array() );
run_import();
check( 'one deleted here and one in the Trash: dropped, and eBay is not asked', array( isset( $OPTIONS[ $FOLLOW ] ), count( $BODIES ), logged( '2 deleted here' ) ), array( false, 1, true ) );
fresh_shop();
$PAGES[] = page( array( ebay_order( '46-00003-00003', 'Active' ) ) );
run_import();
wc_for( '46-00003-00003' )->status = 'trash';
$PAGES[] = page( array( ebay_order( '46-00003-00003', 'Active' ) ) );
run_import();
check( 'put in the Trash, read again in its day still unpaid: followed no more', isset( $OPTIONS[ $FOLLOW ] ), false );

// A refused call: kept as it was, asked again next run.
fresh_shop();
$PAGES[] = page( array( ebay_order( '47-00001-00001', 'Active' ) ) );
run_import();
$OPTIONS[ $FOLLOW ]['47-00001-00001']['checked'] -= 900; // Last asked a run ago.
$was     = $OPTIONS[ $FOLLOW ];
$PAGES   = array( page( array() ), 'fail', 'fail', 'fail' );
run_import();
check( 'the OrderID call refused: the list kept as it was, and a warning', array( $OPTIONS[ $FOLLOW ] === $was, logged( 'the open orders could not be read by their OrderID — cURL error 28: Operation timed out. They are asked about again next run.' ) ), array( true, true ) );
$PAGES[] = page( array() );
$PAGES[] = page( array( ebay_order( '47-00001-00001', 'Completed' ) ) );
run_import();
check( '  the next run asks again: Processing', wc_for( '47-00001-00001' )->status, 'processing' );

// Paid on eBay, but set by hand here to Cancelled: the M2 rule holds in the follow-up.
fresh_shop();
$PAGES[] = page( array( ebay_order( '48-00001-00001', 'Active' ) ) );
run_import();
$o         = wc_for( '48-00001-00001' );
$o->status = 'cancelled';
$PAGES[]   = page( array() );
$PAGES[]   = page( array( ebay_order( '48-00001-00001', 'Completed' ) ) );
run_import();
check( 'followed, cancelled here by hand, then paid on eBay: left Cancelled, with the note and a warning', array( $o->status, false !== strpos( (string) last_note( $o ), 'Check it: the buyer has paid.' ), (bool) array_filter( $LOG, function ( $l ) { return 'warning' === $l[0] && false !== strpos( $l[1], 'is now Completed (paid), but WC Order #' ); } ) ), array( 'cancelled', true, true ) );
check( '  counted apart, and followed no more', array( logged( '0 brought up to date, 1 paid on eBay but left as set here (see the warnings), 1 no longer open' ), isset( $OPTIONS[ $FOLLOW ] ) ), array( true, false ) );

// Another run, overlapping this one, changed the list meanwhile: both kept.
fresh_shop();
$PAGES[] = page( array( ebay_order( '49-00001-00001', 'Active' ), ebay_order( '49-00002-00002', 'Active' ) ) );
run_import();
$PAGES[] = page( array() );
$PAGES[] = page( array( ebay_order( '49-00001-00001', 'Completed' ) ) );
$other   = $OPTIONS[ $FOLLOW ]['49-00002-00002'];
$ON_CALL = function ( $body ) use ( $other ) {
	if ( false !== strpos( $body, '<OrderIDArray>' ) ) {
		// The other run: it saw 49-00002 paid, and began following 49-00003.
		unset( $GLOBALS['OPTIONS'][ $GLOBALS['FOLLOW'] ]['49-00002-00002'] );
		$GLOBALS['OPTIONS'][ $GLOBALS['FOLLOW'] ]['49-00003-00003'] = $other;
	}
};
run_import();
$ON_CALL = null;
check( 'a run overlapping another: what each did to the list is kept', array_keys( $OPTIONS[ $FOLLOW ] ?? array() ), array( '49-00003-00003' ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nA SALE EBAY GIVES A NEW ORDER ID IS NOT CREATED TWICE (FIX ROUND 4, Q12)\n" . str_repeat( '=', 132 ) . "\n";

// eBay's reference: a "non-immediate payment" order's ID changes when it is
// paid, and a Combined Invoice's when it is combined and again when paid.
// 3.30.1 and fix round 3 created the paid order again: two Processing orders.
fresh_shop();
$PAGES[] = page( array( ebay_order( '90-00001-00001', 'Active' ) ) );
run_import();
$PAGES[] = page( array( ebay_order( '90-55555-00001', 'Completed', 1, txid( '90-00001-00001' ) ) ) );
run_import();
$o = wc_for( '90-55555-00001' );
check( 'paid within its day under a new order ID: one order, not two - the first, now Processing', array( count( $ORDERS ), $o ? $o->get_id() : null, $o ? $o->status : null ), array( 1, 9001, 'processing' ) );
check( '  it holds the new ID from now on, the old one kept beside it, with a note', array( $o->meta['_ebay_order_id'], $o->multi['_ebay_order_id_was'] ?? null, false !== strpos( implode( ' ', $o->notes ), 'eBay gave this order a new order ID, 90-55555-00001 (it was 90-00001-00001)' ) ), array( '90-55555-00001', array( '90-00001-00001' ), true ) );
check( '  followed no more; the run says so', array( isset( $OPTIONS[ $FOLLOW ] ), logged( 'eBay order 90-55555-00001 is WC Order #9001, imported before as eBay order 90-00001-00001 - matched by its items; not created again.' ), logged( '0 created, 0 skipped (already imported), 1 already here under the ID eBay gave them before (matched by their items)' ) ), array( false, true, true ) );
$PAGES[] = page( array( ebay_order( '90-00001-00001', 'Active' ), ebay_order( '90-55555-00001', 'Completed', 1, txid( '90-00001-00001' ) ) ) );
run_import();
check( 'read again, even with eBay still showing the old ID: still one order, still Processing', array( count( $ORDERS ), $o->status, logged( '0 created, 2 skipped (already imported)' ) ), array( 1, 'processing', true ) );

// Both IDs in the one read that first sees the sale: the unpaid one created,
// then the paid one matched to it in the same run.
fresh_shop();
$PAGES[] = page( array( ebay_order( '90-00002-00002', 'Active' ), ebay_order( '90-66666-00002', 'Completed', 1, txid( '90-00002-00002' ) ) ) );
run_import();
check( 'the unpaid and the paid ID in the same first read: one order, Processing, under the paid ID', array( count( $ORDERS ), wc_for( '90-66666-00002' ) ? wc_for( '90-66666-00002' )->status : null, isset( $OPTIONS[ $FOLLOW ] ) ), array( 1, 'processing', false ) );

// Still unpaid under the new ID (a Combined Invoice not yet paid), then paid.
fresh_shop();
$PAGES[] = page( array( ebay_order( '91-00001-00001', 'Active' ), ebay_order( '91-00002-00002', 'Active' ) ) );
run_import();
$tcg_a = wc_for( '91-00001-00001' );
$tcg_b = wc_for( '91-00002-00002' );
$tcg_combined = function ( $id, $status ) {
	return '<Order><OrderID>' . $id . '</OrderID><OrderStatus>' . $status . '</OrderStatus><Total currencyID="USD">25</Total><BuyerUserID>buyer_c</BuyerUserID>'
		. '<ShippingAddress><Name>Ann Buyer</Name><Country>US</Country></ShippingAddress><TransactionArray>'
		. '<Transaction><TransactionID>' . txid( '91-00001-00001' ) . '</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice><Item><ItemID>110000000001</ItemID><Title>A</Title></Item></Transaction>'
		. '<Transaction><TransactionID>' . txid( '91-00002-00002' ) . '</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice><Item><ItemID>110000000001</ItemID><Title>B</Title></Item></Transaction>'
		. '</TransactionArray></Order>';
};
$PAGES[] = page( array( $tcg_combined( '91-77777-00000', 'Active' ) ) );
run_import();
check( 'two unpaid orders combined into a Combined Invoice: no third order; the first holds the invoice\'s ID', array( count( $ORDERS ), $tcg_a->meta['_ebay_order_id'], $tcg_b->meta['_ebay_order_id'], $tcg_a->status, $tcg_b->status ), array( 2, '91-77777-00000', '91-00002-00002', 'pending', 'pending' ) );
check( '  the other has a note, and both are still followed, the first by its new ID', array( false !== strpos( (string) last_note( $tcg_b ), 'eBay combined this order with others into eBay order 91-77777-00000, which WC Order #9001 holds here.' ), array_keys( $OPTIONS[ $FOLLOW ] ?? array() ) ), array( true, array( '91-00002-00002', '91-77777-00000' ) ) );
$PAGES[] = page( array( $tcg_combined( '91-88888-00000', 'Completed' ) ) );
run_import();
check( '  paid, under yet another ID: still two orders, one per item, both Processing; none followed', array( count( $ORDERS ), $tcg_a->status, $tcg_b->status, $tcg_a->meta['_ebay_order_id'], $tcg_a->multi['_ebay_order_id_was'] ?? null, isset( $OPTIONS[ $FOLLOW ] ) ), array( 2, 'processing', 'processing', '91-88888-00000', array( '91-00001-00001', '91-77777-00000' ), false ) );
$PAGES[] = page( array( $tcg_combined( '91-88888-00000', 'Completed' ), $tcg_combined( '91-77777-00000', 'Active' ) ) );
run_import();
check( '  read again, the invoice\'s old ID too: nothing created, nothing changed', array( count( $ORDERS ), $tcg_a->status, $tcg_b->status ), array( 2, 'processing', 'processing' ) );
check( 'WooCommerce stock untouched by any of it', $STOCK[501], 5 );

// Combined and paid between two runs, eBay showing both of the invoice's IDs
// in one read: both new IDs matched in the same run.
fresh_shop();
$PAGES[] = page( array( ebay_order( '91-00001-00001', 'Active' ), ebay_order( '91-00002-00002', 'Active' ) ) );
run_import();
$PAGES[] = page( array( $tcg_combined( '91-77777-00000', 'Active' ), $tcg_combined( '91-88888-00000', 'Completed' ) ) );
run_import();
check( 'the invoice\'s two IDs in one read: still two orders, both Processing, the first under the paid ID', array( count( $ORDERS ), wc_for( '91-88888-00000' ) ? wc_for( '91-88888-00000' )->get_id() : null, wc_for( '91-00002-00002' )->status, isset( $OPTIONS[ $FOLLOW ] ) ), array( 2, 9001, 'processing', false ) );

// Only some of a new order's lines held here: created as before, with a warning.
fresh_shop();
$PAGES[] = page( array( ebay_order( '92-00001-00001', 'Active' ) ) );
run_import();
$PAGES[] = page( array( '<Order><OrderID>92-99999-00000</OrderID><OrderStatus>Completed</OrderStatus><Total currencyID="USD">25</Total><BuyerUserID>b</BuyerUserID><ShippingAddress><Name>A</Name><Country>US</Country></ShippingAddress><TransactionArray>'
	. '<Transaction><TransactionID>' . txid( '92-00001-00001' ) . '</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice><Item><ItemID>110000000001</ItemID><Title>A</Title></Item></Transaction>'
	. '<Transaction><TransactionID>77</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice><Item><ItemID>110000000002</ItemID><Title>C</Title></Item></Transaction>'
	. '</TransactionArray></Order>' ) );
run_import();
check( 'a new order only part of which an order here holds: created, as before, with a warning naming that order', array( count( $ORDERS ), null !== wc_for( '92-99999-00000' ), (bool) array_filter( $LOG, function ( $l ) { return 'warning' === $l[0] && false !== strpos( $l[1], 'eBay order 92-99999-00000 shares items with WC Order(s) #9001, imported before as eBay order(s) 92-00001-00001, but they do not hold all of it' ); } ) ), array( 2, true, true ) );

// An order here in the Trash holds the line: not matched; created, as before.
fresh_shop();
$PAGES[] = page( array( ebay_order( '93-00001-00001', 'Active' ) ) );
run_import();
wc_for( '93-00001-00001' )->status = 'trash';
$PAGES[] = page( array( ebay_order( '93-55555-00001', 'Completed', 1, txid( '93-00001-00001' ) ) ) );
run_import();
check( 'the order holding its item is in the Trash: the paid one is created, as before', array( count( $ORDERS ), null !== wc_for( '93-55555-00001' ) ), array( 2, true ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nTHE FOLLOW-UP SEES THE PAID ORDER FIRST: STILL ONE ORDER (FIX ROUND 5, MED-2)\n" . str_repeat( '=', 132 ) . "\n";

// The paid order reaches the follow-up before the day's read: the order here
// took no new ID, and the next day's read created the sale again.
$tcg_med2 = function ( $label, array $first_read ) {
	fresh_shop();
	$GLOBALS['PAGES'][] = page( array( ebay_order( '97-00001-00001', 'Active' ) ) );
	run_import();
	$paid = ebay_order( '97-55555-00001', 'Completed', 1, txid( '97-00001-00001' ) );
	$GLOBALS['PAGES'] = array_merge( $first_read, array( page( array( $paid ) ) ) );
	run_import();
	$GLOBALS['PAGES'] = array( page( array( $paid ) ) );
	run_import();
	$o = wc_for( '97-55555-00001' );
	check( $label, array( count( $GLOBALS['ORDERS'] ), $o ? $o->get_id() : null, $o ? $o->status : null, isset( $GLOBALS['OPTIONS'][ $GLOBALS['FOLLOW'] ] ) ), array( 1, 9001, 'processing', false ) );
};
$tcg_med2( 'the day\'s read times out after payment, the follow-up sees the paid order: one order, under the paid ID', array( 'fail', 'fail', 'fail' ) );
$tcg_med2( 'the day\'s read refused by eBay after payment: one order', array( 'refuse' ) );

// A later page of the day's read fails: the paid order, changed last, is on it.
fresh_shop();
$PAGES[] = page( array( ebay_order( '97-00002-00002', 'Active' ) ) );
run_import();
$tcg_paid = ebay_order( '97-66666-00002', 'Completed', 1, txid( '97-00002-00002' ) );
$PAGES = array( page( array( ebay_order( '97-00009-00009', 'Completed' ) ), 2 ), 'fail', 'fail', 'fail', page( array( $tcg_paid ) ) );
run_import();
$PAGES = array( page( array( ebay_order( '97-00009-00009', 'Completed' ), $tcg_paid ) ) );
run_import();
check( 'page 2 of the day\'s read fails, the paid order on it: one order for the sale, under the paid ID', array( count( $ORDERS ), wc_for( '97-66666-00002' ) ? wc_for( '97-66666-00002' )->get_id() : null, wc_for( '97-66666-00002' ) ? wc_for( '97-66666-00002' )->status : null ), array( 2, 9001, 'processing' ) );

// Past its day; paid, under a new ID with the payment's CreateTime, between the
// day's read and the follow-up call.
fresh_shop();
$tcg_x = stored_order( '97-00003-00003', 'pending', array( '_ebay_order_status' => 'Active', '_ebay_order_wc_status' => 'pending' ) );
$OPTIONS[ $FOLLOW ] = array( '97-00003-00003' => array( 'wc' => $tcg_x->get_id(), 'since' => time() - 2 * 86400, 'checked' => time() - 900, 'items' => array( '110000000001-' . txid( '97-00003-00003' ) ) ) );
$tcg_paid = ebay_order( '97-77777-00003', 'Completed', 1, txid( '97-00003-00003' ) );
$PAGES = array( page( array() ), page( array( $tcg_paid ) ) );
run_import();
$PAGES = array( page( array( $tcg_paid ) ) );
run_import();
check( 'paid between the day\'s read and the follow-up: one order, under the paid ID', array( count( $ORDERS ), wc_for( '97-77777-00003' ) ? wc_for( '97-77777-00003' )->status : null ), array( 1, 'processing' ) );

// A Combined Invoice paid; the next day's read refused, so the follow-up sees it.
fresh_shop();
$PAGES[] = page( array( ebay_order( '97-00004-00004', 'Active' ), ebay_order( '97-00005-00005', 'Active' ) ) );
run_import();
$tcg_inv = function ( $id, $status ) {
	return '<Order><OrderID>' . $id . '</OrderID><OrderStatus>' . $status . '</OrderStatus><Total currencyID="USD">25</Total><BuyerUserID>b</BuyerUserID><ShippingAddress><Name>A</Name><Country>US</Country></ShippingAddress><TransactionArray>'
		. '<Transaction><TransactionID>' . txid( '97-00004-00004' ) . '</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice><Item><ItemID>110000000001</ItemID><Title>A</Title></Item></Transaction>'
		. '<Transaction><TransactionID>' . txid( '97-00005-00005' ) . '</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice><Item><ItemID>110000000001</ItemID><Title>B</Title></Item></Transaction>'
		. '</TransactionArray></Order>';
};
$PAGES[] = page( array( $tcg_inv( '97-80000-00000', 'Active' ) ) );
run_import();
$PAGES = array( 'refuse', page( array( $tcg_inv( '97-90000-00000', 'Completed' ) ) ) );
run_import();
$PAGES = array( page( array( $tcg_inv( '97-90000-00000', 'Completed' ) ) ) );
run_import();
check( 'a Combined Invoice paid, the next day\'s read refused: still two orders, both Processing, the first under the paid ID', array( count( $ORDERS ), wc_for( '97-90000-00000' ) ? wc_for( '97-90000-00000' )->get_id() : null, wc_for_any( '97-00005-00005' )->status, isset( $OPTIONS[ $FOLLOW ] ) ), array( 2, 9001, 'processing', false ) );
check( '  the re-keyed entry stood for the whole sale (both lines), so the paid invoice was matched whole', logged( 'eBay order 97-90000-00000 is WC Order #9001' ), true );

// An invoice that eBay still shows unpaid in the day's read, while the
// follow-up asks about the order combined into it (no longer shown under its
// own ID): the invoice's holder is not given its own ID again (fix round 5).
fresh_shop();
$PAGES[] = page( array( ebay_order( '97-00006-00006', 'Active' ), ebay_order( '97-00007-00007', 'Active' ) ) );
run_import();
$tcg_inv2 = function ( $id, $status ) {
	return '<Order><OrderID>' . $id . '</OrderID><OrderStatus>' . $status . '</OrderStatus><Total currencyID="USD">25</Total><BuyerUserID>b</BuyerUserID><ShippingAddress><Name>A</Name><Country>US</Country></ShippingAddress><TransactionArray>'
		. '<Transaction><TransactionID>' . txid( '97-00006-00006' ) . '</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice><Item><ItemID>110000000001</ItemID><Title>A</Title></Item></Transaction>'
		. '<Transaction><TransactionID>' . txid( '97-00007-00007' ) . '</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice><Item><ItemID>110000000001</ItemID><Title>B</Title></Item></Transaction>'
		. '</TransactionArray></Order>';
};
$PAGES[] = page( array( $tcg_inv2( '97-81000-00000', 'Active' ) ) );
run_import();
$tcg_first = wc_for( '97-81000-00000' );
$tcg_notes = count( $tcg_first->notes );
$PAGES = array( page( array( $tcg_inv2( '97-81000-00000', 'Active' ) ) ), page( array( $tcg_inv2( '97-81000-00000', 'Active' ) ) ) );
run_import();
check( 'the invoice unpaid in the day\'s read, the follow-up asking the other order: no second "new ID" for the holder, no ID kept twice', array( count( $tcg_first->notes ), $tcg_first->multi['_ebay_order_id_was'] ?? null, count( $BODIES ) ), array( $tcg_notes, array( '97-00006-00006' ), 2 ) );

// The follow-up answered with an ID an order here had before (an order combined
// into an invoice, shown Inactive under its own ID): the order here keeps the
// invoice's ID; it is not given the old one back (fix round 5).
fresh_shop();
$PAGES[] = page( array( ebay_order( '97-00006-00006', 'Active' ), ebay_order( '97-00007-00007', 'Active' ) ) );
run_import();
$PAGES[] = page( array( $tcg_inv2( '97-83000-00000', 'Active' ) ) );
run_import();
$tcg_first = wc_for( '97-83000-00000' );
$PAGES = array( 'refuse', page( array( ebay_order( '97-00006-00006', 'Inactive' ) ) ) );
run_import();
check( 'the follow-up answered with the old ID of an order here: it keeps the invoice\'s ID', array( $tcg_first->meta['_ebay_order_id'], $tcg_first->multi['_ebay_order_id_was'] ?? null ), array( '97-83000-00000', array( '97-00006-00006' ) ) );

// The spot check's LOW-1 (CI2): eBay goes on showing an order combined into an
// invoice, under its own ID, as Inactive; then the invoice is paid. The
// invoice's holder holds every line of the sale, so the paid invoice is matched
// whole (2 orders, not 3). The other order goes On hold from its own Inactive
// read: whether eBay does that is on the live-test list.
fresh_shop();
$PAGES[] = page( array( ebay_order( '97-00006-00006', 'Active' ), ebay_order( '97-00007-00007', 'Active' ) ) );
run_import();
$PAGES[] = page( array( $tcg_inv2( '97-82000-00000', 'Active' ) ) );
run_import();
$PAGES[] = page( array( ebay_order( '97-00007-00007', 'Inactive' ) ) );
run_import();
$PAGES[] = page( array( $tcg_inv2( '97-92000-00000', 'Completed' ) ) );
run_import();
check( 'an order combined into an invoice shown Inactive, then the invoice paid: still two orders, the invoice\'s Processing (fix round 5)', array( count( $ORDERS ), wc_for( '97-92000-00000' ) ? wc_for( '97-92000-00000' )->status : null, wc_for( '97-00007-00007' )->status ), array( 2, 'processing', 'on-hold' ) );

// LOW-2: an order created for the whole sale, beside one holding part of it
// (the warning), then paid under a new ID: the whole-sale order takes it.
fresh_shop();
$PAGES[] = page( array( ebay_order( '98-00001-00001', 'Active' ) ) );
run_import();
$tcg_two = function ( $id, $status ) {
	return '<Order><OrderID>' . $id . '</OrderID><OrderStatus>' . $status . '</OrderStatus><Total currencyID="USD">25</Total><BuyerUserID>b</BuyerUserID><ShippingAddress><Name>A</Name><Country>US</Country></ShippingAddress><TransactionArray>'
		. '<Transaction><TransactionID>' . txid( '98-00001-00001' ) . '</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice><Item><ItemID>110000000001</ItemID><Title>A</Title></Item></Transaction>'
		. '<Transaction><TransactionID>88</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice><Item><ItemID>110000000002</ItemID><Title>F</Title></Item></Transaction>'
		. '</TransactionArray></Order>';
};
$PAGES[] = page( array( $tcg_two( '98-20000-00000', 'Active' ) ) );
run_import();
$tcg_whole = wc_for( '98-20000-00000' );
$PAGES[] = page( array( $tcg_two( '98-30000-00000', 'Completed' ) ) );
run_import();
check( 'part held, created with the warning, then paid under a new ID: the order made for the whole sale takes it (fix round 5, LOW-2)', array( count( $ORDERS ), wc_for( '98-30000-00000' ) ? wc_for( '98-30000-00000' )->get_id() : null ), array( 2, $tcg_whole->get_id() ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nAN ERROR ON ONE ORDER SKIPS ONLY THAT ORDER (FIX ROUND 4, LOW-A)\n" . str_repeat( '=', 132 ) . "\n";

// Another plugin's hook throwing an Error (not an Exception, which WooCommerce
// would swallow) as an existing order is written. It ended the whole run.
fresh_shop();
$PAGES[] = page( array( ebay_order( '94-00001-00001', 'Active' ) ) );
run_import();
$tcg_x = wc_for( '94-00001-00001' );
$THROW_ON_WRITE[ $tcg_x->get_id() ] = true;
$PAGES[] = page( array( ebay_order( '94-00001-00001', 'Completed' ), ebay_order( '94-00002-00002', 'Completed' ) ) );
run_import();
check( 'writing one order here throws an Error: the run goes on - the next order is created', array( null !== wc_for( '94-00002-00002' ), $tcg_x->status ), array( true, 'pending' ) );
check( '  the error is logged, the summary counts it, and the order is still followed', array( logged( 'Order Import: eBay order 94-00001-00001 could not be brought up to date here — a hook on order #9001 failed. It is tried again next run.' ), logged( "1 created, 0 skipped (already imported), 1 not brought up to date (an error; each order's is logged once a day)" ), isset( $OPTIONS[ $FOLLOW ]['94-00001-00001'] ) ), array( true, true, true ) );
$THROW_ON_WRITE = array();
$PAGES[] = page( array( ebay_order( '94-00001-00001', 'Completed' ), ebay_order( '94-00002-00002', 'Completed' ) ) );
run_import();
check( '  the hook fixed: the next run brings it up to date', array( $tcg_x->status, isset( $OPTIONS[ $FOLLOW ] ) ), array( 'processing', false ) );

// The same in the follow-up.
fresh_shop();
$PAGES[] = page( array( ebay_order( '95-00001-00001', 'Active' ), ebay_order( '95-00002-00002', 'Active' ) ) );
run_import();
$THROW_ON_WRITE[ wc_for( '95-00001-00001' )->get_id() ] = true;
$PAGES[] = page( array() );
$PAGES[] = page( array( ebay_order( '95-00001-00001', 'Completed' ), ebay_order( '95-00002-00002', 'Completed' ) ) );
run_import();
check( 'in the follow-up: the order whose write throws is skipped, logged and still followed; the other is brought up to date', array( wc_for( '95-00001-00001' )->status, wc_for( '95-00002-00002' )->status, array_keys( $OPTIONS[ $FOLLOW ] ?? array() ), logged( "1 brought up to date, 1 not brought up to date (an error; each order's is logged once a day), 1 no longer open" ) ), array( 'pending', 'processing', array( '95-00001-00001' ), true ) );

// The same where a sale took a new ID.
fresh_shop();
$PAGES[] = page( array( ebay_order( '96-00001-00001', 'Active' ) ) );
run_import();
$THROW_ON_WRITE[ wc_for( '96-00001-00001' )->get_id() ] = true;
$PAGES[] = page( array( ebay_order( '96-55555-00001', 'Completed', 1, txid( '96-00001-00001' ) ), ebay_order( '96-00002-00002', 'Completed' ) ) );
run_import();
check( 'paid under a new ID, writing the order here throws: logged, not created twice, the run goes on', array( count( $ORDERS ), logged( 'Order Import: eBay order 96-55555-00001, held here under the ID eBay gave it before, could not be brought up to date — a hook on order #9001 failed.' ), null !== wc_for( '96-00002-00002' ) ), array( 2, true, true ) );
$THROW_ON_WRITE = array();
$PAGES[] = page( array( ebay_order( '96-55555-00001', 'Completed', 1, txid( '96-00001-00001' ) ) ) );
run_import();
check( '  the next run: the first order takes the new ID and is Processing', array( count( $ORDERS ), wc_for( '96-55555-00001' ) ? wc_for( '96-55555-00001' )->get_id() : null, wc_for( '96-55555-00001' ) ? wc_for( '96-55555-00001' )->status : null ), array( 2, 9001, 'processing' ) );

// An order not followed (paid) that eBay now shows with a cancellation asked
// for, whose write throws: it stays followed, so the follow-up asks again after
// its day (fix round 5: the new entry was removed, and the change lost).
fresh_shop();
$PAGES[] = page( array( ebay_order( '96-00003-00003', 'Completed' ) ) );
run_import();
$THROW_ON_WRITE[ wc_for( '96-00003-00003' )->get_id() ] = true;
$PAGES[] = page( array( ebay_order( '96-00003-00003', 'CancelPending' ) ) );
run_import();
check( 'a paid order, now a cancellation asked for, whose write throws: followed from then on, so it is asked again', isset( $OPTIONS[ $FOLLOW ]['96-00003-00003'] ), true );
$THROW_ON_WRITE = array();
$PAGES = array( page( array() ), page( array( ebay_order( '96-00003-00003', 'CancelPending' ) ) ) );
run_import();
check( '  the hook fixed, its day over: the follow-up puts it On hold', wc_for( '96-00003-00003' )->status, 'on-hold' );

// A Combined Invoice whose first holder's status hook throws: the other holder
// is still brought up to date (the spot check's A06).
fresh_shop();
$PAGES[] = page( array( ebay_order( '96-00004-00004', 'Active' ), ebay_order( '96-00005-00005', 'Active' ) ) );
run_import();
$THROW_ON_STATUS_OF[ wc_for( '96-00004-00004' )->get_id() ] = true;
$PAGES[] = page( array( $tcg_combined2 = '<Order><OrderID>96-90000-00000</OrderID><OrderStatus>Completed</OrderStatus><Total currencyID="USD">25</Total><BuyerUserID>b</BuyerUserID><ShippingAddress><Name>A</Name><Country>US</Country></ShippingAddress><TransactionArray>'
	. '<Transaction><TransactionID>' . txid( '96-00004-00004' ) . '</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice><Item><ItemID>110000000001</ItemID><Title>A</Title></Item></Transaction>'
	. '<Transaction><TransactionID>' . txid( '96-00005-00005' ) . '</TransactionID><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">12.50</TransactionPrice><Item><ItemID>110000000001</ItemID><Title>B</Title></Item></Transaction>'
	. '</TransactionArray></Order>' ) );
run_import();
check( 'an invoice paid, the first holder\'s status hook throws: the other is still Processing; the first logged, still followed, under the paid ID', array( count( $ORDERS ), wc_for( '96-00005-00005' )->status, wc_for( '96-90000-00000' )->status, logged( 'eBay order 96-90000-00000 could not be brought up to date here — a status hook on order #9001 failed' ), isset( $OPTIONS[ $FOLLOW ]['96-90000-00000'] ) ), array( 2, 'processing', 'pending', true, true ) );

// An order whose write always throws: logged once a day, not every run.
fresh_shop();
$PAGES[] = page( array( ebay_order( '96-00006-00006', 'Active' ) ) );
run_import();
$THROW_ON_WRITE[ wc_for( '96-00006-00006' )->get_id() ] = true;
for ( $tcg_r = 0; $tcg_r < 4; $tcg_r++ ) {
	$PAGES[] = page( array( ebay_order( '96-00006-00006', 'Completed' ) ) );
	run_import();
}
$tcg_errs = function () { return count( array_filter( $GLOBALS['LOG'], function ( $l ) { return 'error' === $l[0] && false !== strpos( $l[1], 'eBay order 96-00006-00006 could not be brought up to date here' ); } ) ); };
check( 'an order whose write always throws, four runs: one error line, saying it is logged once a day', array( $tcg_errs(), logged( 'While it lasts, this is logged once a day.' ) ), array( 1, true ) );
foreach ( $TRANSIENTS as $tcg_k => $tcg_t ) {
	$TRANSIENTS[ $tcg_k ][1] -= 86400 + 1; // A day and a second on.
}
$PAGES[] = page( array( ebay_order( '96-00006-00006', 'Completed' ) ) );
run_import();
check( '  a day on, once more', $tcg_errs(), 2 );
$THROW_ON_WRITE = array();

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nA CALL EBAY REFUSES DOES NOT HOLD UP THE REST (FIX ROUND 4, LOW-B)\n" . str_repeat( '=', 132 ) . "\n";

$tcg_follow_n = function ( $n ) {
	fresh_shop();
	$here = stored_order( 'R-held', 'pending', array( '_ebay_order_status' => 'Active', '_ebay_order_wc_status' => 'pending' ) );
	$list = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$list[ sprintf( 'R-%05d', $i ) ] = array( 'wc' => $here->get_id(), 'since' => time() - 86400, 'checked' => time() - 90000 + $i, 'items' => array() );
	}
	$GLOBALS['OPTIONS'][ $GLOBALS['FOLLOW'] ] = $list;
};
$tcg_ids = function ( $body ) { preg_match_all( '#<OrderID>([^<]*)</OrderID>#', (string) $body, $m ); return $m[1]; };

// Not reaching eBay at all: the follow-up stops for the run, as in round 3.
$tcg_follow_n( 150 );
$PAGES = array( page( array() ), 'fail', 'fail', 'fail' );
run_import();
check( 'the first call does not reach eBay: no second call this run, the list kept', array( count( array_unique( array_map( 'inner', array_slice( $BODIES, 1 ) ) ) ), $OPTIONS[ $FOLLOW ]['R-00149']['checked'] < time() - 3600 ), array( 1, true ) );

// eBay refusing the first call: the second still goes.
$tcg_follow_n( 150 );
$PAGES = array( page( array() ), 'refuse', page( array() ) );
run_import();
check( 'eBay refuses the first call: the second still goes (it ended the follow-up)', array( count( $BODIES ), count( $tcg_ids( $BODIES[2] ?? '' ) ), $OPTIONS[ $FOLLOW ]['R-00149']['checked'] >= time() - 2 ), array( 3, 50, true ) );
check( '  the refused call\'s orders are marked, not marked as asked; a warning', array( $OPTIONS[ $FOLLOW ]['R-00000']['refused'] ?? null, $OPTIONS[ $FOLLOW ]['R-00099']['checked'] < time() - 3600, logged( 'eBay refused 1 of the calls asking about open orders by their OrderID' ) ), array( 1, true, true ) );
$PAGES = array( page( array() ), page( array() ), page( array() ) );
run_import();
check( '  the next run asks about them first, ten to a call', array( count( $tcg_ids( $BODIES[1] ?? '' ) ), $tcg_ids( $BODIES[1] ?? '' )[0] ?? null, count( $tcg_ids( $BODIES[2] ?? '' ) ) ), array( 10, 'R-00000', 10 ) );
check( '  and an order answered for is unmarked', isset( $OPTIONS[ $FOLLOW ]['R-00000']['refused'] ), false );

// Orders refused twice: one asked alone a run, the next ten together, so the
// call of ten shows whether eBay answers at all (fix round 5).
$tcg_follow_n( 30 );
foreach ( $OPTIONS[ $FOLLOW ] as $tcg_id => $tcg_e ) {
	$OPTIONS[ $FOLLOW ][ $tcg_id ]['refused'] = 2;
}
$PAGES = array( page( array() ), page( array() ), page( array() ) );
run_import();
check( '30 orders refused twice: one asked alone, the next ten in one call - 11 unmarked in a run', array( count( $tcg_ids( $BODIES[1] ?? '' ) ), count( $tcg_ids( $BODIES[2] ?? '' ) ), count( array_filter( array_column( $OPTIONS[ $FOLLOW ], 'refused' ) ) ) ), array( 1, 10, 19 ) );

// One ID eBay always refuses: told from the rest, and dropped on its own.
$tcg_follow_n( 30 );
$tcg_bad  = 'R-00007';
$tcg_runs = 0;
$ON_CALL  = function ( $body ) use ( $tcg_bad ) {
	if ( false !== strpos( $body, '<OrderID>' . $tcg_bad . '</OrderID>' ) ) {
		$GLOBALS['PAGES'] = array_merge( array( 'refuse' ), $GLOBALS['PAGES'] );
	}
};
while ( ( isset( $OPTIONS[ $FOLLOW ][ $tcg_bad ] ) || array_filter( array_column( $OPTIONS[ $FOLLOW ] ?? array(), 'refused' ) ) ) && $tcg_runs < 20 ) {
	$PAGES = array( page( array() ), page( array() ), page( array() ) );
	run_import();
	$tcg_runs++;
}
$ON_CALL = null;
check( 'one order ID eBay always refuses: dropped once refused on its own, logged, the other 29 still followed', array( isset( $OPTIONS[ $FOLLOW ][ $tcg_bad ] ), count( $OPTIONS[ $FOLLOW ] ?? array() ), logged( 'eBay refused 3 times to answer for eBay order R-00007 (WC Order #9001) by its OrderID, the last time asked on its own' ) ), array( false, 29, true ) );
check( '  the others unmarked again within a few runs (here ' . $tcg_runs . ': one refused call of 30, calls of 10, then alone, one a run, a refused order resting a run)', array( $tcg_runs <= 17, array_filter( array_column( $OPTIONS[ $FOLLOW ] ?? array(), 'refused' ) ) ), array( true, array() ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nTHE CALLS A RUN MAKES ARE BOUNDED: AT MOST 20 + 2 (FIX ROUND 3)\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
$tcg_list = array();
$tcg_here = stored_order( '50-held', 'pending', array( '_ebay_order_status' => 'Active', '_ebay_order_wc_status' => 'pending' ) );
for ( $tcg_i = 0; $tcg_i < 450; $tcg_i++ ) {
	$tcg_list[ sprintf( 'F-%05d', $tcg_i ) ] = array( 'wc' => $tcg_here->get_id(), 'since' => time() - 86400, 'checked' => time() - 90000 + $tcg_i, 'items' => array() );
}
$OPTIONS[ $FOLLOW ] = $tcg_list;
for ( $tcg_p = 1; $tcg_p <= 20; $tcg_p++ ) {
	$PAGES[] = page( array( ebay_order( sprintf( '50-%05d-00001', $tcg_p ), 'Completed' ) ), 21 );
}
$PAGES[] = page( array() );
$PAGES[] = page( array() );
run_import();
$tcg_ids = function ( $body ) { preg_match_all( '#<OrderID>([^<]*)</OrderID>#', (string) $body, $m ); return $m[1]; };
check( '21 pages of new orders and 450 open orders followed: 22 calls, the most a run makes', count( $BODIES ), 22 );
check( '  20 for the day (as 3.30.1), then 2 of 100 OrderIDs each, the least recently asked first', array( count( $tcg_ids( $BODIES[20] ) ), count( $tcg_ids( $BODIES[21] ) ), $tcg_ids( $BODIES[20] )[0], $tcg_ids( $BODIES[21] )[99] ), array( 100, 100, 'F-00000', 'F-00199' ) );
$PAGES = array( page( array() ), page( array() ), page( array() ) );
run_import();
check( 'the next run: the next 200', array( count( $BODIES ), $tcg_ids( $BODIES[1] )[0], $tcg_ids( $BODIES[2] )[99] ), array( 3, 'F-00200', 'F-00399' ) );
$PAGES = array( page( array() ), page( array() ), page( array() ) );
run_import();
check( 'the one after: the last 50 first, so every order is asked about within three runs', array_slice( $tcg_ids( $BODIES[1] ), 0, 50 ), array_map( function ( $n ) { return sprintf( 'F-%05d', $n ); }, range( 400, 449 ) ) );

// At most 2,000 followed: the oldest go first.
fresh_shop();
$tcg_here = stored_order( '51-held', 'pending', array( '_ebay_order_status' => 'Active', '_ebay_order_wc_status' => 'pending' ) );
$tcg_list = array();
for ( $tcg_i = 0; $tcg_i < 2100; $tcg_i++ ) {
	$tcg_list[ sprintf( 'G-%05d', $tcg_i ) ] = array( 'wc' => $tcg_here->get_id(), 'since' => time() - 3600 - $tcg_i, 'checked' => time() - 900, 'items' => array() );
}
$OPTIONS[ $FOLLOW ] = $tcg_list;
$PAGES = array( page( array() ), page( array() ), page( array() ) );
run_import();
check( '2,100 followed: 2,000 kept, the newest; the 100 longest followed go', array( count( $OPTIONS[ $FOLLOW ] ), isset( $OPTIONS[ $FOLLOW ]['G-01999'] ), isset( $OPTIONS[ $FOLLOW ]['G-02000'] ) ), array( 2000, true, false ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nSWITCHING ORDER IMPORT OFF AND BACK ON BEHAVES AS 3.30.1 DID\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
$PAGES[] = page( array( ebay_order( '52-00001-00001', 'Active' ) ) );
run_import();
$was = $OPTIONS[ $FOLLOW ];
$OPTIONS['tcgiant_sync_ebay_settings']['enable_order_import'] = 0;
run_import();
check( 'switched off: no call at all, and the list left as it was', array( count( $BODIES ), $OPTIONS[ $FOLLOW ] === $was ), array( 0, true ) );
// Sixty days on, switched back on.
foreach ( $OPTIONS[ $FOLLOW ] as $tcg_id => $tcg_e ) {
	$OPTIONS[ $FOLLOW ][ $tcg_id ]['since']   -= 60 * 86400;
	$OPTIONS[ $FOLLOW ][ $tcg_id ]['checked'] -= 60 * 86400;
}
$OPTIONS['tcgiant_sync_ebay_settings']['enable_order_import'] = 1;
$PAGES[] = page( array( ebay_order( '52-00002-00002', 'Completed' ) ) );
run_import();
check( 'back on 60 days later: the first run reads the last 24 hours and creates what was made in them', array( count( $BODIES ), abs( strtotime( (string) tag( $BODIES[0] ?? '', 'CreateTimeFrom' ) ) - ( time() - 86400 ) ) <= 2, null !== wc_for( '52-00002-00002' ) ), array( 1, true, true ) );
check( '  and the order followed 60 days ago is dropped, not asked about', array( isset( $OPTIONS[ $FOLLOW ] ), logged( '1 followed for 30 days and dropped' ) ), array( false, true ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nA STATUS CHANGED BY HAND IS THE SHOP'S\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
$PAGES[] = page( array( ebay_order( '53-00001-00001', 'Completed' ), ebay_order( '53-00002-00002', 'Active' ) ) );
run_import();
$shipped = wc_for( '53-00001-00001' );
$held    = wc_for( '53-00002-00002' );
$shipped->status = 'completed'; // Shipped, and marked so by hand.
$held->status    = 'on-hold';   // Held by hand.
$PAGES[] = page( array( ebay_order( '53-00001-00001', 'Cancelled' ), ebay_order( '53-00002-00002', 'Completed' ) ) );
run_import();
check( 'a shipped (Completed) order eBay later cancels stays Completed', $shipped->status, 'completed' );
check( '  with a note of what eBay says', last_note( $shipped ), 'eBay now says this order is Cancelled. Its status here was changed by hand, so TCGiant Sync left it as it is.' );
check( 'an order held by hand, then paid on eBay: stays On hold, and paid needs no note', array( $held->status, count( $held->notes ) ), array( 'on-hold', 1 ) );
check( '  eBay\'s status is recorded all the same', $held->meta['_ebay_order_status'], 'Completed' );
check( 'a deleted order is left alone, with nothing added', ( function () {
	fresh_shop();
	$GLOBALS['PAGES'][] = page( array( ebay_order( '53-00003-00003', 'Active' ) ) );
	run_import();
	$o         = wc_for( '53-00003-00003' );
	$o->status = 'trash';
	$GLOBALS['PAGES'][] = page( array( ebay_order( '53-00003-00003', 'Cancelled' ) ) );
	run_import();
	return array( $o->status, count( $o->notes ) );
} )(), array( 'trash', 1 ) );

echo "\nPAID ON EBAY, CANCELLED OR WAITING HERE BY HAND (FIX ROUND 1, M2)\n" . str_repeat( '=', 132 ) . "\n";

foreach ( array( 'cancelled', 'failed', 'refunded' ) as $by_hand ) {
	fresh_shop();
	$PAGES[] = page( array( ebay_order( '36-00001-00001', 'Active' ) ) );
	run_import();
	$o         = wc_for( '36-00001-00001' );
	$o->status = $by_hand;
	$PAGES[]   = page( array( ebay_order( '36-00001-00001', 'Completed' ) ) );
	run_import();
	check( sprintf( 'Pending payment set to %s by hand, then paid on eBay: left %s', $by_hand, $by_hand ), $o->status, $by_hand );
	check( '  with a note that the buyer has paid', false !== strpos( (string) last_note( $o ), 'eBay now says this order is Completed - paid for.' ) && false !== strpos( (string) last_note( $o ), 'Check it: the buyer has paid.' ), true );
	check( '  and a warning in the log', (bool) array_filter( $LOG, function ( $l ) use ( $o ) { return 'warning' === $l[0] && false !== strpos( $l[1], sprintf( 'is now Completed (paid), but WC Order #%d is', $o->get_id() ) ); } ), true );
	check( '  counted apart in the summary, not as brought up to date (fix round 2)', array( logged( '1 paid on eBay but left as set here (see the warnings)' ), logged( 'brought up to date with eBay' ) ), array( true, false ) );
}
fresh_shop();
$PAGES[] = page( array( ebay_order( '36-00002-00002', 'Completed' ) ) );
run_import();
$o         = wc_for( '36-00002-00002' );
$o->status = 'pending'; // Put back to waiting by hand.
$PAGES[]   = page( array( ebay_order( '36-00002-00002', 'CancelPending' ) ) );
run_import();
$PAGES[]   = page( array( ebay_order( '36-00002-00002', 'Completed' ) ) );
run_import();
check( 'Processing put back to Pending payment by hand, then paid on eBay: left, with the note', array( $o->status, false !== strpos( (string) last_note( $o ), 'the buyer has paid' ) ), array( 'pending', true ) );
foreach ( array( 'on-hold', 'completed' ) as $by_hand ) {
	fresh_shop();
	$PAGES[] = page( array( ebay_order( '36-00003-00003', 'Active' ) ) );
	run_import();
	$o         = wc_for( '36-00003-00003' );
	$o->status = $by_hand;
	$PAGES[]   = page( array( ebay_order( '36-00003-00003', 'Completed' ) ) );
	run_import();
	check( sprintf( '  set to %s by hand, then paid: no note, as before', $by_hand ), array( $o->status, count( $o->notes ) ), array( $by_hand, 1 ) );
}

echo "\nORDERS IMPORTED BEFORE 3.31.0\n" . str_repeat( '=', 132 ) . "\n";

// What 3.30 left: Processing for paid and unpaid alike, no record of either.
fresh_shop();
$a = stored_order( '60-00001-00001', 'processing' ); // Imported unpaid, now cancelled.
$b = stored_order( '60-00002-00002', 'processing' ); // Imported unpaid, now paid.
$c = stored_order( '60-00003-00003', 'completed' );  // Shipped by the shop, now cancelled.
$d = stored_order( '60-00004-00004', 'processing' ); // Imported unpaid, still unpaid.
$e = stored_order( '60-00005-00005', 'on-hold' );    // Put on hold by the shop, now paid.
$PAGES[] = page( array( ebay_order( '60-00001-00001', 'Cancelled' ), ebay_order( '60-00002-00002', 'Completed' ), ebay_order( '60-00003-00003', 'Cancelled' ), ebay_order( '60-00004-00004', 'Active' ), ebay_order( '60-00005-00005', 'Completed' ) ) );
run_import();
check( 'a 3.30 import still Processing, cancelled on eBay: Cancelled', $a->status, 'cancelled' );
check( 'a 3.30 import still Processing, paid on eBay: stays Processing, now recorded', array( $b->status, $b->meta['_ebay_order_wc_status'] ?? null ), array( 'processing', 'processing' ) );
check( 'a 3.30 import the shop marked Completed, cancelled on eBay: stays Completed, with a note', array( $c->status, count( $c->notes ) ), array( 'completed', 1 ) );
check( 'a 3.30 import still Processing, still unpaid: Pending payment, and followed from now on', array( $d->status, array_keys( $OPTIONS[ $FOLLOW ] ?? array() ) ), array( 'pending', array( '60-00004-00004' ) ) );
check( 'a 3.30 import the shop put On hold, paid on eBay: left On hold, the shop\'s own (fix round 1, L3)', array( $e->status, count( $e->notes ) ), array( 'on-hold', 0 ) );

// A 3.30 import made more than a day before the update is not in any read:
// left as it is, as 3.30.1 left it.
fresh_shop();
$f       = stored_order( '60-00006-00006', 'processing' );
$PAGES[] = page( array() );
run_import();
check( 'a 3.30 import from before the last day: not asked about, left as 3.30.1 left it', array( $f->status, count( $BODIES ), isset( $OPTIONS[ $FOLLOW ] ) ), array( 'processing', 1, false ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nSTOCK: AN EBAY ORDER NEVER MOVES IT; A WEBSITE ORDER DOES (CONTROL)\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
$PAGES[] = page( array( ebay_order( '70-00001-00001', 'Active', 2 ) ) );
run_import();
$PAGES[] = page( array( ebay_order( '70-00001-00001', 'Completed', 2 ) ) );
run_import();
$after_paid = $STOCK[501];
$PAGES[] = page( array( ebay_order( '70-00001-00001', 'Cancelled', 2 ) ) );
run_import();
check( 'an eBay order of 2: unpaid, paid, cancelled - WooCommerce stock 5, 5, 5', array( $after_paid, $STOCK[501] ), array( 5, 5 ) );
check( '  the inventory class said why', logged( 'came from eBay, which already deducted the quantity' ), true );

// Control: the same moves on a website order.
$web = wc_create_order();
$web->meta['__first_saved'] = true;
$line = new WC_Order_Item_Product();
$line->set_product( new Harness_Product( 501 ) );
$line->set_quantity( 2 );
$web->add_item( $line );
$web->update_status( 'processing' );
$taken = $STOCK[501];
$web->update_status( 'cancelled' );
check( 'control: a website order of 2 takes 2 at Processing and gives them back at Cancelled', array( $taken, $STOCK[501] ), array( 3, 5 ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nAN EBAY ORDER IS PAID FOR, AND CANCELLED, ON EBAY\n" . str_repeat( '=', 132 ) . "\n";

$ebay_order = wc_for( '70-00001-00001' );
check( 'an eBay order waiting for payment is not offered for payment here', $IMPORT->not_paid_here( true, $ebay_order ), false );
check( '  nor for the buyer to cancel here', $IMPORT->not_cancelled_here( array( 'pending', 'failed' ), $ebay_order ), array() );
check( 'control: a website order still is', array( $IMPORT->not_paid_here( true, $web ), $IMPORT->not_cancelled_here( array( 'pending', 'failed' ), $web ) ), array( true, array( 'pending', 'failed' ) ) );
check( '  and one that needs no payment stays that way', $IMPORT->not_paid_here( false, $ebay_order ), false );
check( 'both are hooked where WooCommerce asks', array( false !== strpos( $src, "add_filter( 'woocommerce_order_needs_payment', array( \$this, 'not_paid_here' ), 10, 2 );" ), false !== strpos( $src, "add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( \$this, 'not_cancelled_here' ), 10, 2 );" ) ), array( true, true ) );
$inv_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-inventory.php' );
check( 'the inventory class still blocks an eBay order\'s reduction, as loaded above', false !== strpos( $inv_src, "add_filter( 'woocommerce_can_reduce_order_stock', array( \$this, 'block_double_reduction' ), 10, 2 );" ), true );

$uninstall = (string) file_get_contents( $ROOT . '/uninstall.php' );
check( 'the class names the follow list\'s option so', defined( 'TCGiant_Sync_Orders::FOLLOW_OPTION' ) ? constant( 'TCGiant_Sync_Orders::FOLLOW_OPTION' ) : null, $FOLLOW );
check( 'deleting the plugin with its data deletes the follow list; the options fix rounds 1-2 had are gone', array( false !== strpos( $uninstall, "'" . $FOLLOW . "'," ), false !== strpos( $uninstall, 'tcgiant_sync_orders_read_to' ), false !== strpos( $uninstall, 'tcgiant_sync_orders_floor' ), false !== strpos( $uninstall, 'tcgiant_sync_orders_retry' ) ), array( true, false, false, false ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nA SIMULATED EBAY THAT HONOURS GETORDERS' FILTERS (THE SPOT CHECK'S SIMULATION, PORTED)\n" . str_repeat( '=', 132 ) . "\n";

$D = 86400;

// The stub itself: each filter, the order, the pages.
sim_reset();
sim_new( 'S-a', -2 * $D );
sim_new( 'S-b', -3600 );
sim_new( 'S-c', -7200, false );
$SIM['S-c']['mod'] = -60;
$SIM_REV++;
$tcg_body = function ( $inner ) { return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<GetOrdersRequest xmlns="urn:ebay:apis:eBLBaseComponents">' . "\n" . $inner . "\n" . '</GetOrdersRequest>'; };
$tcg_got  = function ( $xml ) { preg_match_all( '#<OrderID>([^<]*)</OrderID>#', $xml, $m ); return $m[1]; };
$tcg_t    = function ( $v ) { return gmdate( 'Y-m-d\TH:i:s.000\Z', v2r( $v ) ); };
check( 'the stub: CreateTime gives the orders made in the window, the oldest change first', $tcg_got( sim_answer( $tcg_body( '<CreateTimeFrom>' . $tcg_t( -$D ) . '</CreateTimeFrom><CreateTimeTo>' . $tcg_t( 0 ) . '</CreateTimeTo><EntriesPerPage>100</EntriesPerPage><PageNumber>1</PageNumber>' ) ) ), array( 'S-b', 'S-c' ) );
check( '  ModTime the orders changed in it', $tcg_got( sim_answer( $tcg_body( '<ModTimeFrom>' . $tcg_t( -600 ) . '</ModTimeFrom><ModTimeTo>' . $tcg_t( 0 ) . '</ModTimeTo><EntriesPerPage>100</EntriesPerPage><PageNumber>1</PageNumber>' ) ) ), array( 'S-c' ) );
check( '  OrderIDArray the orders asked for, whatever their time, by either ID', ( function () use ( $tcg_body, $tcg_got ) {
	sim_set( 'S-c', 'Completed', 'S-c-paid' );
	return $tcg_got( sim_answer( $tcg_body( '<OrderIDArray><OrderID>S-a</OrderID><OrderID>S-c</OrderID><OrderID>S-zz</OrderID></OrderIDArray><EntriesPerPage>100</EntriesPerPage><PageNumber>1</PageNumber>' ) ) );
} )(), array( 'S-a', 'S-c-paid' ) );
check( '  and pages of EntriesPerPage', array( tag( sim_answer( $tcg_body( '<CreateTimeFrom>' . $tcg_t( -3 * $D ) . '</CreateTimeFrom><CreateTimeTo>' . $tcg_t( 0 ) . '</CreateTimeTo><EntriesPerPage>2</EntriesPerPage><PageNumber>2</PageNumber>' ) ), 'TotalNumberOfPages' ), $tcg_got( sim_answer( $tcg_body( '<CreateTimeFrom>' . $tcg_t( -3 * $D ) . '</CreateTimeFrom><CreateTimeTo>' . $tcg_t( 0 ) . '</CreateTimeTo><EntriesPerPage>2</EntriesPerPage><PageNumber>2</PageNumber>' ) ) ) ), array( '2', array( 'S-c-paid' ) ) );

// SIM-B: a bulk tracking upload - 3,000 imported orders marked shipped within
// five minutes - with new orders at one a minute throughout.
sim_reset();
for ( $tcg_i = 0; $tcg_i < 3000; $tcg_i++ ) {
	sim_imported( sprintf( 'B-old-%05d', $tcg_i ), -3 * $D + $tcg_i * 60 );
}
for ( $tcg_v = 60, $tcg_i = 0; $tcg_v <= 10 * 3600; $tcg_v += 60, $tcg_i++ ) {
	sim_new( sprintf( 'B-new-%05d', $tcg_i ), $tcg_v );
}
$tcg_u = 4 * 3600 + 450;
$runs  = sim_runs( 14 * 3600, array( $tcg_u => function () use ( $tcg_u ) {
	$i = 0;
	foreach ( $GLOBALS['SIM'] as $id => $o ) {
		if ( 0 === strpos( $id, 'B-old-' ) ) {
			$GLOBALS['SIM'][ $id ]['shipped'] = $tcg_u + $i * 0.1;
			$GLOBALS['SIM'][ $id ]['mod']     = $tcg_u + $i * 0.1;
			$i++;
		}
	}
} ) );
$t = sim_tally();
check( 'SIM-B (3,000 shipped in 5 minutes, 600 new orders): none lost, none made twice', array( $t['lost'], $t['dup'] ), array( 0, 0 ) );
check( '  every new order created within 15 minutes; at most 22 calls a run', array( $t['late_h'] <= 0.25, max_calls( $runs ) <= 22 ), array( true, true ) );
printf( "    SIM-B: %d runs, %d GetOrders, at most %d a run; latest creation %.2f h after the order was made\n", count( $runs ), array_sum( array_column( $runs, 'calls' ) ), max_calls( $runs ), $t['late_h'] );

// A burst of 2,500 changes in ten minutes, to orders made in the last day as
// well as older ones (fix round 2's MED-1), new orders at one a minute.
sim_reset();
for ( $tcg_i = 0; $tcg_i < 1000; $tcg_i++ ) {
	sim_imported( sprintf( 'M-old-%05d', $tcg_i ), -6 * $D + $tcg_i * 120 );
}
for ( $tcg_i = 0; $tcg_i < 1500; $tcg_i++ ) {
	sim_imported( sprintf( 'M-day-%05d', $tcg_i ), -20 * 3600 + $tcg_i * 48 );
}
for ( $tcg_v = 60, $tcg_i = 0; $tcg_v <= 10 * 3600; $tcg_v += 60, $tcg_i++ ) {
	sim_new( sprintf( 'M-new-%05d', $tcg_i ), $tcg_v );
}
$tcg_b = 2 * 3600 + 450;
$runs  = sim_runs( 14 * 3600, array( $tcg_b => function () use ( $tcg_b ) {
	$i = 0;
	foreach ( $GLOBALS['SIM'] as $id => $o ) {
		if ( 0 !== strpos( $id, 'M-new-' ) || $o['created'] < $tcg_b ) {
			$GLOBALS['SIM'][ $id ]['mod']     = $tcg_b + $i * 0.24;
			$GLOBALS['SIM'][ $id ]['shipped'] = $tcg_b + $i * 0.24;
			$i++;
		}
	}
} ) );
$t = sim_tally();
check( 'a burst of 2,500+ changes in 10 minutes: no new order lost, none made twice', array( $t['lost'], $t['dup'] ), array( 0, 0 ) );
check( '  every new order created within 15 minutes; at most 22 calls a run', array( $t['late_h'] <= 0.25, max_calls( $runs ) <= 22 ), array( true, true ) );
printf( "    burst: %d runs, %d GetOrders, at most %d a run; latest creation %.2f h after the order was made\n", count( $runs ), array_sum( array_column( $runs, 'calls' ) ), max_calls( $runs ), $t['late_h'] );

// SIM-D: every creation fails for some hours (another plugin's hook, a
// database error), with 3,000 changes a day to orders imported before; new
// orders for two days. SIM-D5's 4,320 new orders a day are more than the 20
// pages (2,000 orders) a run reads, as on 3.30.1: the newest wait until older
// ones leave the day, so it runs until the last order's day has passed.
foreach ( array( 'D4' => array( 60, 1, 2 * $D + 3600 ), 'D6' => array( 60, 6, 2 * $D + 3600 ), 'D5' => array( 20, 12, 3 * $D + 3600 ) ) as $tcg_key => $tcg_cfg ) {
	list( $tcg_every, $tcg_fail_h, $tcg_until ) = $tcg_cfg;
	sim_reset();
	for ( $tcg_v = $tcg_every, $tcg_i = 0; $tcg_v <= 2 * $D; $tcg_v += $tcg_every, $tcg_i++ ) {
		sim_new( sprintf( '%s-new-%05d', $tcg_key, $tcg_i ), $tcg_v );
	}
	for ( $tcg_v = 29, $tcg_i = 0; $tcg_v <= 2 * $D; $tcg_v += 29, $tcg_i++ ) {
		$tcg_id = sprintf( '%s-bg-%05d', $tcg_key, $tcg_i );
		sim_imported( $tcg_id, $tcg_v - 3 * $D );
		$SIM[ $tcg_id ]['shipped'] = $tcg_v;
		$SIM[ $tcg_id ]['mod']     = $tcg_v;
	}
	$runs = sim_runs( $tcg_until, array(
		3600                      => function () { $GLOBALS['THROW_ON_CREATE'] = true; },
		( 1 + $tcg_fail_h ) * 3600 => function () { $GLOBALS['THROW_ON_CREATE'] = false; },
	) );
	$t = sim_tally();
	check( sprintf( 'SIM-%s (creation failing %d h, a new order every %d s): none lost, none made twice', $tcg_key, $tcg_fail_h, $tcg_every ), array( $t['lost'], $t['dup'] ), array( 0, 0 ) );
	check( sprintf( '  every order created within its day, as on 3.30.1; at most 22 calls a run' ), array( $t['late_h'] < 24, max_calls( $runs ) <= 22 ), array( true, true ) );
	printf( "    SIM-%s: %d runs, %d GetOrders, at most %d a run, %d runs at the 20-page cap; latest creation %.1f h after the order was made\n", $tcg_key, count( $runs ), array_sum( array_column( $runs, 'calls' ) ), max_calls( $runs ), count( array_filter( array_column( $runs, 'capped' ) ) ), $t['late_h'] );
}

// SIM-Q (fix round 4, the spot check's cases A, A2 and C): 60 unpaid orders,
// each paid 0.1 to 30 hours on under a new ID - every other one with eBay's
// CreateTime moved to the payment - and 20 pairs combined into a Combined
// Invoice three hours on, paid ten minutes after under yet another ID. One
// order here per sale, each Processing at the end.
sim_reset();
$tcg_sales = 0;
$tcg_fns   = array();
$tcg_after = array( 0.1, 0.5, 2, 12, 23, 30 );
for ( $tcg_i = 0; $tcg_i < 60; $tcg_i++ ) {
	$tcg_id = sprintf( 'Q-%05d', $tcg_i );
	$tcg_at = 600 * ( $tcg_i + 1 );
	sim_new( $tcg_id, $tcg_at, false );
	$tcg_sales++;
	$tcg_pay = $tcg_at + (int) ( $tcg_after[ $tcg_i % 6 ] * 3600 );
	$tcg_a2  = 1 === $tcg_i % 2;
	$tcg_fns[ $tcg_pay ][] = function () use ( $tcg_id, $tcg_a2 ) {
		sim_set( $tcg_id, 'Completed', $tcg_id . '-paid' );
		if ( $tcg_a2 ) {
			$GLOBALS['SIM'][ $tcg_id . '-paid' ]['created'] = $GLOBALS['V'];
		}
	};
}
for ( $tcg_i = 0; $tcg_i < 20; $tcg_i++ ) {
	$tcg_x  = sprintf( 'C-%05d-a', $tcg_i );
	$tcg_y  = sprintf( 'C-%05d-b', $tcg_i );
	$tcg_at = 450 + 1800 * $tcg_i;
	sim_new( $tcg_x, $tcg_at, false );
	sim_new( $tcg_y, $tcg_at + 300, false );
	$tcg_sales += 2;
	$tcg_inv = sprintf( 'C-%05d-inv', $tcg_i );
	$tcg_fns[ $tcg_at + 300 + 3 * 3600 ][] = function () use ( $tcg_x, $tcg_y, $tcg_inv ) { sim_combine( array( $tcg_x, $tcg_y ), $tcg_inv ); };
	$tcg_fns[ $tcg_at + 300 + 3 * 3600 + 600 ][] = function () use ( $tcg_inv ) { sim_set( $tcg_inv, 'Completed', $tcg_inv . '-paid' ); };
}
$tcg_events = array();
foreach ( $tcg_fns as $tcg_at => $tcg_list ) {
	$tcg_events[ $tcg_at ] = function () use ( $tcg_list ) {
		foreach ( $tcg_list as $fn ) {
			$fn();
		}
	};
}
$runs = sim_runs( 3 * $D, $tcg_events );
$tcg_wrong = 0;
foreach ( $ORDERS as $tcg_o ) {
	$tcg_wrong += 'processing' === $tcg_o->status ? 0 : 1;
}
check( 'SIM-Q (60 paid under a new ID, 20 Combined Invoices): one order here per sale, not one per ID - ' . $tcg_sales . ' sales', array( count( $ORDERS ), $tcg_wrong, isset( $OPTIONS[ $FOLLOW ] ) ), array( $tcg_sales, 0, false ) );
check( '  at most 22 calls a run; stock untouched', array( max_calls( $runs ) <= 22, $STOCK[501] ), array( true, 5 ) );
printf( "    SIM-Q: %d runs, %d GetOrders, at most %d a run; %d orders here for %d sales\n", count( $runs ), array_sum( array_column( $runs, 'calls' ) ), max_calls( $runs ), count( $ORDERS ), $tcg_sales );

// SIM-R (fix round 4, LOW-B): 120 unpaid orders, all paid after their day;
// eBay refuses every call that asks about one of them. It is dropped; the
// rest are Processing, and the follow-up kept to 2 calls a run.
sim_reset();
for ( $tcg_i = 0; $tcg_i < 120; $tcg_i++ ) {
	$tcg_id = sprintf( 'R-%05d', $tcg_i );
	sim_new( $tcg_id, 60 * ( $tcg_i + 1 ), false );
}
$SIM_REFUSE = array( 'R-00042' );
$runs = sim_runs( 2 * $D + 3600, array( 30 * 3600 => function () {
	foreach ( array_keys( $GLOBALS['SIM'] ) as $id ) {
		sim_set( $id, 'Completed' );
	}
} ) );
$tcg_left = array();
foreach ( $ORDERS as $tcg_o ) {
	if ( 'processing' !== $tcg_o->status ) {
		$tcg_left[] = $tcg_o->meta['_ebay_order_id'];
	}
}
check( 'SIM-R (one of 120 order IDs always refused): it alone is left Pending payment and dropped; the other 119 Processing', array( $tcg_left, isset( $OPTIONS[ $FOLLOW ] ) ), array( array( 'R-00042' ), false ) );
check( '  at most 22 calls a run, and at most 2 of them asking by OrderID', array( max_calls( $runs ) <= 22, max( array_map( function ( $r ) { return $r['calls']; }, array_slice( $runs, 100 ) ) ) <= 3 ), array( true, true ) );

// SIM-O (fix round 5, MED-1): 200 unpaid orders followed past their day; eBay
// refuses every GetOrders, or every OrderIDArray only, for 1, 3, 6 or 24 hours
// from 30 h on - a refusal that has nothing to do with the IDs; every order is
// paid an hour after eBay answers again. Fix round 4 dropped 4 / 20 / 40 / 172
// of them, left Pending payment though paid. None may be dropped.
foreach ( array( 'every GetOrders' => 'SIM_REFUSE_ALL', 'OrderIDArray only' => 'SIM_REFUSE_BYID' ) as $tcg_what => $tcg_flag ) {
	$tcg_res = array();
	foreach ( array( 1, 3, 6, 24 ) as $tcg_h ) {
		sim_reset();
		for ( $tcg_i = 0; $tcg_i < 200; $tcg_i++ ) {
			sim_new( sprintf( 'O-%05d', $tcg_i ), 30 * ( $tcg_i + 1 ), false );
		}
		$tcg_end = ( 30 + $tcg_h ) * 3600;
		$runs = sim_runs( $tcg_end + 12 * 3600, array(
			30 * 3600 => function () use ( $tcg_flag ) { $GLOBALS[ $tcg_flag ] = true; },
			$tcg_end  => function () use ( $tcg_flag ) { $GLOBALS[ $tcg_flag ] = false; },
			$tcg_end + 3600 => function () {
				foreach ( array_keys( $GLOBALS['SIM'] ) as $id ) {
					sim_set( $id, 'Completed' );
				}
			},
		) );
		$tcg_left = 0;
		foreach ( $ORDERS as $tcg_o ) {
			$tcg_left += 'processing' === $tcg_o->status ? 0 : 1;
		}
		$tcg_res[] = array( $tcg_h, $tcg_left, isset( $OPTIONS[ $FOLLOW ] ), max_calls( $runs ) <= 22 );
	}
	check( sprintf( 'SIM-O, eBay refusing %s for 1 / 3 / 6 / 24 h: no order dropped - all 200 Processing once paid, none left followed', $tcg_what ), $tcg_res, array( array( 1, 0, false, true ), array( 3, 0, false, true ), array( 6, 0, false, true ), array( 24, 0, false, true ) ) );
}

// SIM-R2 (fix round 6, MED-3): two IDs eBay always refuses side by side, in
// one call of ten - #7 and #8 among 300, and #3 and #4 of 12 - followed past
// their day, every order paid at 30 h. In round 5 both of a run's calls were
// refused, run after run: nothing was answered, so nothing was dropped and no
// other followed order was ever asked again, for 30 days. Both are dropped;
// every other order is Processing.
foreach ( array( array( 300, array( 7, 8 ) ), array( 12, array( 3, 4 ) ) ) as $tcg_case ) {
	list( $tcg_n, $tcg_bad ) = $tcg_case;
	sim_reset();
	for ( $tcg_i = 0; $tcg_i < $tcg_n; $tcg_i++ ) {
		sim_new( sprintf( 'R2-%05d', $tcg_i ), 60 * ( $tcg_i + 1 ), false );
	}
	$SIM_REFUSE = array_map( function ( $i ) { return sprintf( 'R2-%05d', $i ); }, $tcg_bad );
	$runs = sim_runs( 4 * $D, array( 30 * 3600 => function () {
		foreach ( array_keys( $GLOBALS['SIM'] ) as $id ) {
			sim_set( $id, 'Completed' );
		}
	} ) );
	$tcg_left = array();
	foreach ( $ORDERS as $tcg_o ) {
		if ( 'processing' !== $tcg_o->status ) {
			$tcg_left[] = $tcg_o->meta['_ebay_order_id'];
		}
	}
	sort( $tcg_left );
	check( sprintf( 'SIM-R2, %d followed, #%d and #%d always refused: both dropped, the other %d Processing (fix round 6)', $tcg_n, $tcg_bad[0], $tcg_bad[1], $tcg_n - 2 ), array( $tcg_left, isset( $OPTIONS[ $FOLLOW ] ), max_calls( $runs ) <= 22 ), array( $SIM_REFUSE, false, true ) );
}

// SIM-F: 400 unpaid orders over ten hours, followed for a month: paid within
// the day, paid after it (some under a new order ID), cancelled, never paid.
sim_reset();
$tcg_plan = array();
for ( $tcg_i = 0; $tcg_i < 400; $tcg_i++ ) {
	$tcg_id = sprintf( 'F-%05d', $tcg_i );
	$tcg_at = 90 * ( $tcg_i + 1 );
	sim_new( $tcg_id, $tcg_at, false );
	switch ( $tcg_i % 10 ) {
		case 0:  $tcg_plan[ $tcg_id ] = array( $tcg_at + 40 * 3600, 'Cancelled', null, 'cancelled' ); break;
		case 1:  $tcg_plan[ $tcg_id ] = array( null, null, null, 'pending' ); break;
		case 2:  $tcg_plan[ $tcg_id ] = array( $tcg_at + 30 * 3600, 'Completed', $tcg_id . '-paid', 'processing' ); break;
		case 3:  $tcg_plan[ $tcg_id ] = array( $tcg_at + 2 * 3600, 'Completed', null, 'processing' ); break;
		case 4:  $tcg_plan[ $tcg_id ] = array( $tcg_at + 26 * 3600, 'CancelPending', null, 'on-hold' ); break;
		default: $tcg_plan[ $tcg_id ] = array( $tcg_at + 30 * 3600, 'Completed', null, 'processing' );
	}
}
$tcg_events = array();
foreach ( $tcg_plan as $tcg_id => $tcg_p ) {
	if ( null !== $tcg_p[0] ) {
		$tcg_events[ $tcg_p[0] ][] = array( $tcg_id, $tcg_p[1], $tcg_p[2] );
	}
}
$tcg_fns = array();
foreach ( $tcg_events as $tcg_at => $tcg_list ) {
	$tcg_fns[ $tcg_at ] = function () use ( $tcg_list ) {
		foreach ( $tcg_list as $c ) {
			sim_set( $c[0], $c[1], $c[2] );
		}
	};
}
// The CancelPending ones are then cancelled two days on.
foreach ( $tcg_plan as $tcg_id => $tcg_p ) {
	if ( 'CancelPending' === $tcg_p[1] ) {
		$tcg_fns[ $tcg_p[0] + 2 * $D ] = function () use ( $tcg_id ) { sim_set( $tcg_id, 'Cancelled' ); };
		$tcg_plan[ $tcg_id ][3] = 'cancelled';
	}
}
$runs   = sim_runs( 31 * $D, $tcg_fns );
$wrong  = 0;
$lag    = 0;
foreach ( $tcg_plan as $tcg_id => $tcg_p ) {
	$o = wc_for_any( $tcg_id );
	if ( ! $o || $o->status !== $tcg_p[3] ) {
		$wrong++;
	} elseif ( null !== $tcg_p[0] && null !== $o->changed_at ) {
		$lag = max( $lag, $o->changed_at - ( 'CancelPending' === $tcg_p[1] ? $tcg_p[0] + 2 * $D : $tcg_p[0] ) );
	}
}
$t = sim_tally();
check( 'SIM-F (400 unpaid orders followed for a month): each ends as eBay has it - paid, cancelled, or still Pending payment', $wrong, 0 );
check( '  every change made here within an hour of eBay\'s; none lost, none made twice', array( $lag <= 3600, $t['lost'], $t['dup'] ), array( true, 0, 0 ) );
check( '  the never-paid dropped after 30 days, so the list ends empty; at most 22 calls a run', array( isset( $OPTIONS[ $FOLLOW ] ), max_calls( $runs ) <= 22 ), array( false, true ) );
check( '  WooCommerce stock untouched', $STOCK[501], 5 );
printf( "    SIM-F: %d runs, %d GetOrders, at most %d a run; at most %d orders followed; latest change %.2f h after eBay's\n", count( $runs ), array_sum( array_column( $runs, 'calls' ) ), max_calls( $runs ), max( array_column( $runs, 'follow' ) ), $lag / 3600 );

check( 'nothing above raised a PHP warning or notice', $WARNINGS, array() );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
