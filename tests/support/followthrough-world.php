<?php
/**
 * The world tests/test-order-followthrough.php runs in (3.37.0): WordPress,
 * WooCommerce and Action Scheduler stubbed as far as the order import, the
 * tracking push, feedback and the refund sweep use them, and eBay simulated
 * behind the real API class, down to the HTTP call.
 *
 * Loaded for real: TCGiant_Sync_Environment, TCGiant_Sync_API,
 * TCGiant_Sync_Sites, TCGiant_Sync_Inventory (its stock guard on eBay
 * orders), TCGiant_Sync_Orders (constructed, so its hooks are the real
 * ones) and TCGiant_Sync_Order_Followthrough (registered). Nothing is sent
 * anywhere.
 *
 * WooCommerce's refund is played as 8.x plays wc_create_refund(): the
 * remaining amount checked, woocommerce_create_refund before the save, the
 * restock only when asked, partially or fully refunded, and a full one
 * setting the order to Refunded; its customer email asks
 * woocommerce_email_enabled_<id>, the id being customer_partially_refunded_
 * order for a partial one, as WC_Email_Customer_Refunded_Order names itself.
 *
 * eBay ($EBAY, orders by ID) answers GetOrders by CreateTime, ModTime and
 * OrderIDArray as its reference says - ModTime with OutputSelector honoured,
 * each order cut to the paths selected - and CompleteSale from $COMPLETE, a
 * script of answers, 'ok' when it runs out.
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__, 2 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$OPTIONS     = array();
$AUTOLOAD    = array();
$LOG         = array();
$TRANSIENTS  = array();
$FILTERS     = array();
$WARNINGS    = array();
$ORDERS      = array();
$IDX         = array(); // eBay order ID => WooCommerce order IDs.
$IDX_WAS     = array();
$STOCK       = array( 501 => 5, 502 => 5, 503 => 5 );
$EBAY        = array(); // eBay order ID => order (see ebay()).
$EBAY_WAS    = array(); // An ID eBay gave an order before => its ID now.
$COMPLETE    = array(); // CompleteSale's answers, in turn.
$CALLS       = array(); // Trading calls by name.
$BODIES      = array(); // Request bodies by call name, as sent.
$HTTP        = 0;       // Every request to eBay.
$AS          = array(); // Action Scheduler's pending actions.
$EMAILS      = array(); // Customer emails WooCommerce sent.
$REFUND_ARGS = array(); // wc_create_refund()'s arguments, each call.
$RESTOCKED   = 0;
$CREATED     = 0;       // wc_create_order() calls.
$ON_HTTP     = null;    // Run on each request to eBay, as another request might.

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
function __( $text, $domain = null ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_xml( $text ) { return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' ); }
function wp_rand( $min = 0, $max = 0 ) { return $min; }
function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-4%03x-%04x-%012x', mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 0, 4095 ), mt_rand( 32768, 49151 ), mt_rand( 0, 281474976710655 ) ); }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { add_filter( $hook, $cb, $prio, $args ); }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['FILTERS'][ $hook ][ $prio ][] = array( $cb, $args ); ksort( $GLOBALS['FILTERS'][ $hook ] ); }
function apply_filters( $hook, $value, ...$rest ) {
	foreach ( $GLOBALS['FILTERS'][ $hook ] ?? array() as $list ) {
		foreach ( $list as $f ) {
			$value = call_user_func_array( $f[0], array_slice( array_merge( array( $value ), $rest ), 0, $f[1] ) );
		}
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['FILTERS'][ $hook ] ?? array() as $list ) {
		foreach ( $list as $f ) {
			call_user_func_array( $f[0], array_slice( $args, 0, $f[1] ) );
		}
	}
}
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function add_option( $name, $value = '' ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; $GLOBALS['AUTOLOAD'][ $name ] = $autoload; return true; }
function delete_option( $name ) { unset( $GLOBALS['OPTIONS'][ $name ] ); return true; }
function get_transient( $key ) {
	$t = $GLOBALS['TRANSIENTS'][ $key ] ?? null;
	return null !== $t && $t[1] > time() ? $t[0] : false;
}
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $key ] = array( $value, time() + (int) $ttl ); return true; }
function delete_transient( $key ) { unset( $GLOBALS['TRANSIENTS'][ $key ] ); return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function maybe_unserialize( $v ) { return is_string( $v ) && preg_match( '/^(a|s|i|b):/', $v ) ? unserialize( $v ) : $v; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function is_email( $email ) { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false; }
function email_exists( $email ) { return false; }
function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
function wc_get_order_status_name( $status ) { return array( 'pending' => 'Pending payment', 'processing' => 'Processing', 'on-hold' => 'On hold', 'cancelled' => 'Cancelled', 'completed' => 'Completed', 'refunded' => 'Refunded', 'failed' => 'Failed' )[ $status ] ?? $status; }
function wc_get_price_decimals() { return 2; }
function wc_format_decimal( $n, $dp = false ) { return false === $dp ? (string) $n : number_format( (float) $n, (int) $dp, '.', '' ); }

// The live shop, so nothing is held back as from a staging copy (unless the
// process defined TCGIANT_SYNC_IS_STAGING first).
function wp_load_alloptions() { return array( 'home' => 'https://mytcgshop.com', 'siteurl' => 'https://mytcgshop.com' ); }
function wp_get_environment_type() { return 'production'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

/** The database: options rows (holds, call counts), and the orders' eBay IDs. */
class Harness_Wpdb {
	public $options  = 'wp_options';
	public $postmeta = 'wp_postmeta';
	public $prefix   = 'wp_';
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$query = str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query );
		return vsprintf( $query, $args );
	}
	public function suppress_errors( $quiet = true ) { return false; }
	public function query( $query ) {
		if ( preg_match( "/^INSERT INTO wp_options \( option_name, option_value, autoload \) VALUES \( '([^']+)', '([^']*)', 'no' \)\s*ON DUPLICATE KEY UPDATE/s", $query, $m ) ) {
			$GLOBALS['OPTIONS'][ $m[1] ] = (int) ( $GLOBALS['OPTIONS'][ $m[1] ] ?? 0 ) + 1;
			return 1;
		}
		if ( preg_match( "/^INSERT INTO wp_options \( option_name, option_value, autoload \) VALUES \( '([^']+)', '([^']*)', 'no' \)$/s", $query, $m ) ) {
			if ( array_key_exists( $m[1], $GLOBALS['OPTIONS'] ) ) {
				return false;
			}
			$GLOBALS['OPTIONS'][ $m[1] ] = $m[2];
			return 1;
		}
		if ( preg_match( "/^DELETE FROM wp_options WHERE option_name = '([^']+)' AND option_value = '([^']*)'$/", $query, $m ) ) {
			if ( isset( $GLOBALS['OPTIONS'][ $m[1] ] ) && (string) $GLOBALS['OPTIONS'][ $m[1] ] === $m[2] ) {
				unset( $GLOBALS['OPTIONS'][ $m[1] ] );
				return 1;
			}
			return 0;
		}
		return 0;
	}
	public function get_var( $query ) {
		if ( preg_match( "/option_name = '([^']+)'/", $query, $m ) ) {
			if ( ! array_key_exists( $m[1], $GLOBALS['OPTIONS'] ) ) {
				return null;
			}
			$v = $GLOBALS['OPTIONS'][ $m[1] ];
			return is_scalar( $v ) ? (string) $v : serialize( $v );
		}
		if ( preg_match( "/meta_key = '_ebay_order_id' AND meta_value = '([^']+)'/", $query, $m ) ) {
			$hit = $GLOBALS['IDX'][ $m[1] ] ?? array();
			return 0 === strpos( $query, 'SELECT COUNT' ) ? (string) count( $hit ) : ( $hit ? (string) min( $hit ) : null );
		}
		if ( preg_match( "/meta_key = '_ebay_order_id_was' AND meta_value = '([^']+)'/", $query, $m ) ) {
			return (string) count( $GLOBALS['IDX_WAS'][ $m[1] ] ?? array() );
		}
		if ( preg_match( "/meta_key = '_ebay_item_id' AND meta_value = '11000000000([0-9])'/", $query, $m ) ) {
			return (string) ( 500 + (int) $m[1] );
		}
		return null;
	}
}
$wpdb = new Harness_Wpdb();

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
}
class TCGiant_Sync_OAuth {
	public static $connected = true;
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_access_token() { return 'token'; }
	public function refresh_access_token() { return 'fresh-token'; }
	public function get_settings() { return array( 'marketplace' => 'EBAY_US' ); }
	public function is_authenticated() { return self::$connected; }
}

// --- Action Scheduler ----------------------------------------------------------

function as_schedule_single_action( $when, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	if ( ! empty( $GLOBALS['AS_THROWS'] ) ) {
		throw new RuntimeException( 'Action Scheduler could not save the action' );
	}
	$GLOBALS['AS'][] =array( 'hook' => $hook, 'when' => (int) $when, 'args' => $args, 'group' => $group, 'priority' => $priority );
	return count( $GLOBALS['AS'] );
}
function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	return as_schedule_single_action( time(), $hook, $args, $group, $unique, $priority );
}
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	$next = false;
	foreach ( $GLOBALS['AS'] as $a ) {
		if ( $a['hook'] === $hook && ( '' === $group || $a['group'] === $group ) ) {
			$next = false === $next ? $a['when'] : min( $next, $a['when'] );
		}
	}
	return $next;
}
function as_unschedule_action( $hook, $args = array(), $group = '' ) {
	foreach ( $GLOBALS['AS'] as $i => $a ) {
		if ( $a['hook'] === $hook ) {
			unset( $GLOBALS['AS'][ $i ] );
			$GLOBALS['AS'] = array_values( $GLOBALS['AS'] );
			return;
		}
	}
}
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
	$GLOBALS['AS'] = array_values( array_filter( $GLOBALS['AS'], function ( $a ) use ( $hook ) { return $a['hook'] !== $hook; } ) );
}
/** The pending actions of a hook. */
function pending( $hook ) {
	return array_values( array_filter( $GLOBALS['AS'], function ( $a ) use ( $hook ) { return $a['hook'] === $hook; } ) );
}
/** Run a hook's first pending action, whenever it is due: Action Scheduler, played. */
function play( $hook ) {
	foreach ( $GLOBALS['AS'] as $i => $a ) {
		if ( $a['hook'] === $hook ) {
			unset( $GLOBALS['AS'][ $i ] );
			$GLOBALS['AS'] = array_values( $GLOBALS['AS'] );
			do_action( $hook );
			return true;
		}
	}
	return false;
}

// --- WooCommerce ------------------------------------------------------------------

abstract class WC_Abstract_Order {}

class Harness_Date {
	private $t;
	public function __construct( $t ) { $this->t = (int) $t; }
	public function getTimestamp() { return $this->t; }
}

/** An order. A status changed and saved runs WooCommerce's status hooks. */
class WC_Order extends WC_Abstract_Order {
	public $id;
	public $status        = 'pending';
	public $saved_status  = 'pending';
	public $meta          = array();
	public $notes         = array();
	public $items         = array();
	public $refunds       = array();
	public $total         = 0;
	public $created;
	public $stock_reduced = false;
	public function __construct( $id ) { $this->id = $id; $this->created = time(); }
	public function get_id() { return $this->id; }
	public function get_type() { return 'shop_order'; }
	public function get_status() { return $this->status; }
	public function set_status( $status ) { $this->status = $status; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) {
		if ( '_ebay_order_id' === $key && ( $this->meta[ $key ] ?? null ) !== $value ) {
			if ( isset( $this->meta[ $key ] ) ) {
				$GLOBALS['IDX'][ $this->meta[ $key ] ] = array_values( array_diff( $GLOBALS['IDX'][ $this->meta[ $key ] ] ?? array(), array( $this->id ) ) );
			}
			$GLOBALS['IDX'][ $value ][] = $this->id;
		}
		$this->meta[ $key ] = $value;
	}
	public function add_meta_data( $key, $value, $unique = false ) {
		if ( '_ebay_order_id_was' === $key ) {
			$GLOBALS['IDX_WAS'][ $value ][] = $this->id;
		}
		$this->meta[ $key ] = $value;
	}
	public function add_order_note( $note ) { $this->notes[] = $note; return count( $this->notes ); }
	public function get_items( $type = 'line_item' ) { return $this->items; }
	public function add_item( $item ) { $item->order = $this; $this->items[] = $item; }
	public function set_address( $a, $t = 'billing' ) {}
	public function set_billing_email( $e ) {}
	public function set_customer_id( $id ) {}
	public function set_total( $t ) { $this->total = (float) $t; }
	public function get_total() { return $this->total; }
	public function get_date_created() { return new Harness_Date( $this->created ); }
	public function get_refunds() { return $this->refunds; }
	public function get_total_refunded() {
		$sum = 0.0;
		foreach ( $this->refunds as $r ) {
			$sum += $r->amount;
		}
		return $sum;
	}
	public function get_remaining_refund_amount() { return wc_format_decimal( $this->get_total() - $this->get_total_refunded(), wc_get_price_decimals() ); }
	public function save() {
		if ( $this->status !== $this->saved_status ) {
			$old                = $this->saved_status;
			$this->saved_status = $this->status;
			do_action( 'woocommerce_order_status_' . $this->status, $this->id, $this );
			do_action( 'woocommerce_order_status_changed', $this->id, $old, $this->status, $this );
		}
		return $this->id;
	}
	public function update_status( $new, $note = '' ) {
		$this->status = $new;
		if ( '' !== $note ) {
			$this->add_order_note( $note );
		}
		$this->save();
		return true;
	}
}
class Harness_WC_Item {
	public $props = array();
	public $meta  = array();
	public $order = null;
	public $saves = 0;
	public function __call( $name, $args ) { $this->props[ $name ] = $args[0] ?? null; }
	public function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
	public function add_meta_data( $k, $v, $unique = false ) { $this->meta[ $k ] = $v; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save_meta_data() { $this->saves++; $GLOBALS['ITEM_WRITES'][] = array( $this->meta[ TCGiant_Sync_Order_Followthrough::FEEDBACK_META ] ?? null ); }
	public function get_product_id() { return isset( $this->props['set_product'] ) ? $this->props['set_product']->id : 0; }
	public function get_quantity() { return (int) ( $this->props['set_quantity'] ?? 1 ); }
	public function get_order() { return $this->order; }
}
$ITEM_WRITES = array();
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
	$GLOBALS['CREATED']++;
	$id                       = 9001 + count( $GLOBALS['ORDERS'] );
	$GLOBALS['ORDERS'][ $id ] = new WC_Order( $id );
	return $GLOBALS['ORDERS'][ $id ];
}

class WC_Order_Refund {
	public $id;
	public $amount;
	public $reason;
	public $meta = array();
	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
}

/** wc_create_refund() as WooCommerce 8.x runs it. */
function wc_create_refund( $args = array() ) {
	$GLOBALS['REFUND_ARGS'][] = $args;
	$args  = array_merge( array( 'amount' => 0, 'reason' => null, 'order_id' => 0, 'line_items' => array(), 'refund_payment' => false, 'restock_items' => false ), $args );
	$order = wc_get_order( $args['order_id'] );
	if ( ! $order ) {
		return new WP_Error( 'error', 'Invalid order ID.' );
	}
	$remaining = (float) $order->get_remaining_refund_amount();
	if ( ! empty( $GLOBALS['REFUND_THROWS'] ) ) {
		return new WP_Error( 'error', 'A hook on the refund failed.' );
	}
	if ( 0 > $args['amount'] || (float) $args['amount'] > $remaining ) {
		return new WP_Error( 'error', 'Invalid refund amount.' );
	}
	$refund         = new WC_Order_Refund( 70001 + count( $GLOBALS['REFUND_ARGS'] ) );
	$refund->amount = (float) $args['amount'];
	$refund->reason = $args['reason'];
	do_action( 'woocommerce_create_refund', $refund, $args );
	$order->refunds[] = $refund;
	if ( $args['refund_payment'] ) {
		$GLOBALS['PAYMENT_REFUNDS'] = (int) ( $GLOBALS['PAYMENT_REFUNDS'] ?? 0 ) + 1;
	}
	if ( $args['restock_items'] ) {
		$GLOBALS['RESTOCKED']++;
	}
	do_action( 'woocommerce_refund_created', $refund->get_id(), $args );
	do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() );
	if ( ( $remaining - (float) $args['amount'] ) > 0 ) {
		do_action( 'woocommerce_order_partially_refunded', $order->get_id(), $refund->get_id() );
	} else {
		do_action( 'woocommerce_order_fully_refunded', $order->get_id(), $refund->get_id() );
		$parent = apply_filters( 'woocommerce_order_fully_refunded_status', 'refunded', $order->get_id(), $refund->get_id() );
		if ( $parent ) {
			$order->update_status( $parent );
		}
	}
	return $refund;
}

/** WooCommerce's refunded-order email to the customer, as WC_Emails sends it. */
function harness_refund_email( $order_id, $refund_id, $partial ) {
	$order = wc_get_order( $order_id );
	$id    = $partial ? 'customer_partially_refunded_order' : 'customer_refunded_order';
	if ( apply_filters( 'woocommerce_email_enabled_' . $id, true, $order, null ) ) {
		$GLOBALS['EMAILS'][] = array( $id, $order_id );
	}
}
add_action( 'woocommerce_order_fully_refunded', function ( $o, $r ) { harness_refund_email( $o, $r, false ); }, 10, 2 );
add_action( 'woocommerce_order_partially_refunded', function ( $o, $r ) { harness_refund_email( $o, $r, true ); }, 10, 2 );

// WooCommerce 8.x's stock functions on status changes, as test-order-status
// plays them: an eBay order takes and gives back none.
function wc_maybe_reduce_stock_levels( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || $order->stock_reduced ) {
		return;
	}
	if ( apply_filters( 'woocommerce_can_reduce_order_stock', true, $order ) ) {
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( '_reduced_stock' ) || ! $item->get_product_id() ) {
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
	foreach ( $order->get_items() as $item ) {
		$took = (int) $item->get_meta( '_reduced_stock' );
		if ( $took ) {
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

// --- eBay ---------------------------------------------------------------------------

/**
 * An eBay order. $o: id, status ('Completed'), buyer, created (Unix), mod
 * (Unix), cancel, lines (each: item, tx, line, created), refunds (each:
 * amount, time (Unix or null), ref, status, currency), total.
 */
function ebay( array $o ) {
	$o = array_merge( array(
		'status'  => 'Completed',
		'buyer'   => 'buyer_' . $o['id'],
		'created' => time() - 3600,
		'mod'     => time() - 600,
		'cancel'  => '',
		'lines'   => array( array( 'item' => '110000000001' ) ),
		'refunds' => array(),
		'total'   => 25.00,
	), $o );
	foreach ( $o['lines'] as $i => $line ) {
		$o['lines'][ $i ] = array_merge( array( 'tx' => (string) ( 2000000000 + $i + crc32( $o['id'] ) % 100000 ), 'created' => $o['created'], 'price' => $o['total'] / max( 1, count( $o['lines'] ) ) ), $line );
		if ( ! array_key_exists( 'line', $o['lines'][ $i ] ) ) {
			$o['lines'][ $i ]['line'] = $o['lines'][ $i ]['item'] . '-' . $o['lines'][ $i ]['tx'];
		}
	}
	$GLOBALS['EBAY'][ $o['id'] ] = $o;
	return $o;
}

function ebay_time( $t ) { return gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $t ); }

/** An eBay order as GetOrders returns it; $only, OutputSelector's paths under Order. */
function ebay_order_xml( array $o, $only = null ) {
	$parts = array(
		'OrderID'      => '<OrderID>' . $o['id'] . '</OrderID>',
		'OrderStatus'  => '<OrderStatus>' . $o['status'] . '</OrderStatus>',
		'CancelStatus' => '' !== $o['cancel'] ? '<CancelStatus>' . $o['cancel'] . '</CancelStatus>' : '',
		'Total'        => '<Total currencyID="USD">' . number_format( $o['total'], 2, '.', '' ) . '</Total>',
		'BuyerUserID'  => '' !== $o['buyer'] ? '<BuyerUserID>' . $o['buyer'] . '</BuyerUserID>' : '',
		'CreatedTime'  => '<CreatedTime>' . ebay_time( $o['created'] ) . '</CreatedTime>',
		'ShippingAddress' => '<ShippingAddress><Name>Ann Buyer</Name><Street1>1 Main St</Street1><CityName>Springfield</CityName><Country>US</Country><PostalCode>62701</PostalCode></ShippingAddress>',
	);
	$money = '<MonetaryDetails><Payments><Payment><PaymentStatus>Succeeded</PaymentStatus><PaymentAmount currencyID="USD">' . number_format( $o['total'], 2, '.', '' ) . '</PaymentAmount></Payment></Payments>';
	if ( $o['refunds'] ) {
		$money .= '<Refunds>';
		foreach ( $o['refunds'] as $r ) {
			$money .= '<Refund>'
				. ( isset( $r['ref'] ) ? '<ReferenceID type="EBAY">' . $r['ref'] . '</ReferenceID>' : '' )
				. ( isset( $r['amount'] ) ? '<RefundAmount currencyID="' . ( $r['currency'] ?? 'USD' ) . '">' . number_format( $r['amount'], 2, '.', '' ) . '</RefundAmount>' : '' )
				. ( isset( $r['status'] ) ? '<RefundStatus>' . $r['status'] . '</RefundStatus>' : '' )
				. ( ! empty( $r['time'] ) ? '<RefundTime>' . ebay_time( $r['time'] ) . '</RefundTime>' : '' )
				. '<RefundTo type="eBayUser">' . $o['buyer'] . '</RefundTo></Refund>';
		}
		$money .= '</Refunds>';
	}
	$parts['MonetaryDetails'] = $money . '</MonetaryDetails>';
	$tx = '<TransactionArray>';
	foreach ( $o['lines'] as $l ) {
		$tx .= '<Transaction><CreatedDate>' . ebay_time( $l['created'] ) . '</CreatedDate><TransactionID>' . $l['tx'] . '</TransactionID>'
			. ( '' !== (string) $l['line'] ? '<OrderLineItemID>' . $l['line'] . '</OrderLineItemID>' : '' )
			. '<QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="USD">' . number_format( $l['price'], 2, '.', '' ) . '</TransactionPrice>'
			. '<Item><ItemID>' . $l['item'] . '</ItemID><Title>Card ' . $l['item'] . '</Title></Item></Transaction>';
	}
	$parts['TransactionArray'] = $tx . '</TransactionArray>';
	if ( null !== $only ) {
		$parts = array_intersect_key( $parts, array_flip( $only ) );
	}
	return '<Order>' . implode( '', $parts ) . '</Order>';
}

/** What eBay answers a GetOrders. */
function answer_get_orders( $body ) {
	$page = (int) ( tag( $body, 'PageNumber' ) ?? 1 );
	$per  = (int) ( tag( $body, 'EntriesPerPage' ) ?? 25 );
	preg_match_all( '#<OutputSelector>([^<]+)</OutputSelector>#', $body, $sel );
	$only = null;
	if ( $sel[1] ) {
		$only = array();
		foreach ( $sel[1] as $path ) {
			if ( 0 === strpos( $path, 'OrderArray.Order.' ) ) {
				$only[] = explode( '.', substr( $path, strlen( 'OrderArray.Order.' ) ) )[0];
			}
		}
	}

	$hits = array();
	if ( preg_match_all( '#<OrderID>([^<]+)</OrderID>#', $body, $ids ) && false !== strpos( $body, '<OrderIDArray>' ) ) {
		foreach ( $ids[1] as $id ) {
			$now = $GLOBALS['EBAY_WAS'][ $id ] ?? $id;
			if ( isset( $GLOBALS['EBAY'][ $now ] ) && empty( $GLOBALS['EBAY_HIDE'][ $now ] ) ) {
				$hits[ $now ] = $GLOBALS['EBAY'][ $now ];
			}
		}
	} else {
		$field = null !== tag( $body, 'ModTimeFrom' ) ? 'mod' : 'created';
		$from  = strtotime( tag( $body, 'mod' === $field ? 'ModTimeFrom' : 'CreateTimeFrom' ) );
		$to    = strtotime( tag( $body, 'mod' === $field ? 'ModTimeTo' : 'CreateTimeTo' ) );
		foreach ( $GLOBALS['EBAY'] as $id => $o ) {
			if ( $o[ $field ] >= $from && $o[ $field ] <= $to ) {
				$hits[ $id ] = $o;
			}
		}
		uasort( $hits, function ( $a, $b ) use ( $field ) { return $a[ $field ] - $b[ $field ]; } );
	}

	$total = count( $hits );
	$pages = max( 1, (int) ceil( $total / max( 1, $per ) ) );
	$slice = array_slice( $hits, ( $page - 1 ) * $per, $per );
	$xml   = '';
	foreach ( $slice as $o ) {
		$xml .= ebay_order_xml( $o, $only );
	}
	$pagination = '<PaginationResult><TotalNumberOfPages>' . $pages . '</TotalNumberOfPages><TotalNumberOfEntries>' . $total . '</TotalNumberOfEntries></PaginationResult>'
		. '<HasMoreOrders>' . ( $page < $pages ? 'true' : 'false' ) . '</HasMoreOrders>';
	return $pagination . ( $xml ? '<OrderArray>' . $xml . '</OrderArray>' : '<OrderArray/>' );
}

function tag( $body, $name ) {
	return preg_match( '#<' . $name . '>([^<]*)</' . $name . '>#', (string) $body, $m ) ? $m[1] : null;
}

function wp_remote_request( $url, $args ) {
	$call = $args['headers']['X-EBAY-API-CALL-NAME'] ?? '';
	$GLOBALS['HTTP']++;
	$GLOBALS['CALLS'][ $call ] = (int) ( $GLOBALS['CALLS'][ $call ] ?? 0 ) + 1;
	$GLOBALS['BODIES'][ $call ][] = $args['body'];
	if ( $GLOBALS['ON_HTTP'] ) {
		call_user_func( $GLOBALS['ON_HTTP'], $call, $args['body'] );
	}

	$ack  = 'Success';
	$more = '';
	if ( 'GetOrders' === $call ) {
		if ( ! empty( $GLOBALS['GETORDERS_FAIL'] ) ) {
			$fail = array_shift( $GLOBALS['GETORDERS_FAIL'] );
			if ( 'timeout' === $fail ) {
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}
			$ack  = 'Failure';
			$more = '<Errors><ShortMessage>Invalid order ID.</ShortMessage><LongMessage>The order ID is not valid.</LongMessage><ErrorCode>21916272</ErrorCode><SeverityCode>Error</SeverityCode></Errors>';
		} else {
			$more = answer_get_orders( $args['body'] );
		}
	} elseif ( 'CompleteSale' === $call ) {
		$answer = $GLOBALS['COMPLETE'] ? array_shift( $GLOBALS['COMPLETE'] ) : 'ok';
		if ( 'timeout' === $answer ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		if ( 'warning' === $answer ) {
			$ack  = 'Warning';
			$more = '<Errors><ShortMessage>Note.</ShortMessage><LongMessage>A note.</LongMessage><ErrorCode>21919188</ErrorCode><SeverityCode>Warning</SeverityCode></Errors>';
		} elseif ( 'partial' === $answer ) {
			$ack  = 'PartialFailure';
			$more = '<Errors><ShortMessage>One change failed.</ShortMessage><LongMessage>One change failed.</LongMessage><ErrorCode>21916000</ErrorCode><SeverityCode>Error</SeverityCode></Errors>';
		} elseif ( is_array( $answer ) ) {
			$ack  = 'Failure';
			$more = '<Errors><ShortMessage>' . $answer[1] . '</ShortMessage><LongMessage>' . $answer[1] . '</LongMessage><ErrorCode>' . $answer[0] . '</ErrorCode><SeverityCode>Error</SeverityCode></Errors>';
		}
	}
	$body = '<?xml version="1.0" encoding="UTF-8"?><' . $call . 'Response xmlns="urn:ebay:apis:eBLBaseComponents"><Timestamp>' . gmdate( 'Y-m-d\TH:i:s.000\Z' ) . '</Timestamp><Ack>' . $ack . '</Ack>' . $more . '</' . $call . 'Response>';
	return array( 'body' => $body, 'response' => array( 'code' => 200 ), 'headers' => array() );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $header ) { return ''; }

require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $ROOT . '/includes/class-tcgiant-sync-orders.php';
require $ROOT . '/includes/class-tcgiant-sync-order-followthrough.php';

set_error_handler( function ( $no, $message, $file = '', $line = 0 ) {
	if ( $no & ( E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED ) ) {
		$GLOBALS['WARNINGS'][] = $message . ' (' . basename( $file ) . ':' . $line . ')';
		return true;
	}
	return false;
} );

// The plugin's own hooks: the order import's (the tracking push among them),
// the inventory's guard on eBay orders, and follow-through's.
$INVENTORY = ( new ReflectionClass( 'TCGiant_Sync_Inventory' ) )->newInstanceWithoutConstructor();
add_filter( 'woocommerce_can_reduce_order_stock', array( $INVENTORY, 'block_double_reduction' ), 10, 2 );
$IMPORT = TCGiant_Sync_Orders::instance();
TCGiant_Sync_Order_Followthrough::register();

/** A shop: orders imported, the relay's figures fresh and roomy, eBay empty. */
function shop( array $settings = array() ) {
	$GLOBALS['OPTIONS']     = array( 'tcgiant_sync_ebay_settings' => array_merge( array( 'enable_order_import' => 1, 'marketplace' => 'EBAY_US' ), $settings ) );
	$GLOBALS['AUTOLOAD']    = array();
	$GLOBALS['LOG']         = array();
	$GLOBALS['TRANSIENTS']  = array();
	$GLOBALS['ORDERS']      = array();
	$GLOBALS['IDX']         = array();
	$GLOBALS['IDX_WAS']     = array();
	$GLOBALS['STOCK']       = array( 501 => 5, 502 => 5, 503 => 5 );
	$GLOBALS['EBAY']        = array();
	$GLOBALS['EBAY_WAS']    = array();
	$GLOBALS['EBAY_HIDE']   = array();
	$GLOBALS['COMPLETE']    = array();
	$GLOBALS['GETORDERS_FAIL'] = array();
	$GLOBALS['AS']          = array();
	$GLOBALS['EMAILS']      = array();
	$GLOBALS['REFUND_ARGS'] = array();
	$GLOBALS['REFUND_THROWS'] = false;
	$GLOBALS['RESTOCKED']   = 0;
	$GLOBALS['PAYMENT_REFUNDS'] = 0;
	$GLOBALS['CREATED']     = 0;
	$GLOBALS['ITEM_WRITES'] = array();
	$GLOBALS['ON_HTTP']     = null;
	$GLOBALS['AS_THROWS']   = false;
	TCGiant_Sync_OAuth::$connected = true;
	roomy();
	reset_calls();
}

/** The relay's figures: fresh, every shop at 1,000 of 50,000, this site's own 1,000. */
function roomy( array $over = array() ) {
	$GLOBALS['TRANSIENTS'][ 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ) ] = array( array_merge( array( 'updated_at' => time(), 'daily_limit' => 50000, 'global_calls' => 1000, 'site_cap' => 1000, 'local_calls' => 0 ), $over ), time() + DAY_IN_SECONDS );
}

function reset_calls() {
	$GLOBALS['CALLS']  = array();
	$GLOBALS['BODIES'] = array();
	$GLOBALS['HTTP']   = 0;
	$slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
	if ( PHP_VERSION_ID < 80100 ) {
		$slot->setAccessible( true );
	}
	$slot->setValue( null, null );
}

function calls( $name ) { return (int) ( $GLOBALS['CALLS'][ $name ] ?? 0 ); }

/** One run of the order import, as the 15-minute cron makes it - its hook, so every listener runs. */
function run_import() {
	do_action( 'tcgiant_sync_import_orders' );
}

/** The order here for an eBay order ID. */
function wc_for( $ebay_id ) {
	$ids = $GLOBALS['IDX'][ $ebay_id ] ?? array();
	return $ids ? $GLOBALS['ORDERS'][ min( $ids ) ] : null;
}

/** An order as the import leaves it, made here directly: its lines' items, its total, its status. */
function stored_order( array $o, $status = 'processing', $total = null ) {
	$order = wc_create_order();
	$GLOBALS['CREATED']--;
	foreach ( $o['lines'] as $l ) {
		$item = new WC_Order_Item_Product();
		$item->add_meta_data( '_ebay_item_id', $l['item'], true );
		$order->add_item( $item );
	}
	$order->total = null === $total ? (float) $o['total'] : (float) $total;
	$order->update_meta_data( '_ebay_order_id', $o['id'] );
	$order->update_meta_data( '_ebay_order_source', 'ebay' );
	$order->update_meta_data( '_ebay_buyer_user_id', $o['buyer'] );
	$order->update_meta_data( '_ebay_order_status', $o['status'] );
	$order->status       = $status;
	$order->saved_status = $status;
	return $order;
}

function logged( $text ) {
	foreach ( $GLOBALS['LOG'] as $entry ) {
		if ( false !== strpos( $entry[1], $text ) ) {
			return true;
		}
	}
	return false;
}

function log_count( $text ) {
	$n = 0;
	foreach ( $GLOBALS['LOG'] as $entry ) {
		$n += false !== strpos( $entry[1], $text ) ? 1 : 0;
	}
	return $n;
}

/** A line item's feedback record. */
function fb( $order, $i = 0 ) {
	$items = $order->get_items();
	$r     = isset( $items[ $i ] ) ? $items[ $i ]->get_meta( TCGiant_Sync_Order_Followthrough::FEEDBACK_META ) : '';
	return is_array( $r ) ? $r : array();
}

function fb_state( $order, $i = 0 ) { return (string) ( fb( $order, $i )['state'] ?? '' ); }

/** The inside of a request's body. */
function inner( $call, $body ) {
	return preg_match( '#<' . $call . 'Request xmlns="urn:ebay:apis:eBLBaseComponents">\n(.*)\n</' . $call . 'Request>#s', (string) $body, $m ) ? $m[1] : null;
}
