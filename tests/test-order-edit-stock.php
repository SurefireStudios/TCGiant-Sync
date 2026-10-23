<?php
/**
 * Harness: saving an imported eBay order from its edit screen leaves the
 * stock alone (3.27.6).
 *
 * Numismax sold a quantity-1 coin on eBay. The sync took its stock to 0 from
 * eBay's figures, and imported the order, and WooCommerce took nothing off for
 * it - block_double_reduction() sees to that, since eBay has counted the sale
 * already. Then the shop added the tracking number and marked the order
 * Completed on its edit screen, and the coin went to -1.
 *
 * Saving an order's items there runs WooCommerce's
 * wc_maybe_adjust_line_item_product_stock() for each line of an order in
 * processing, completed or on-hold. It takes off the line's quantity less
 * what WooCommerce recorded taking off for it (_reduced_stock) - none, here -
 * so it took the sale off a second time. It asks
 * woocommerce_prevent_adjust_line_item_product_stock first (WooCommerce 3.7.1
 * and later; the plugin needs 5.0), and the plugin now says yes for a line of
 * an order imported from eBay.
 *
 * Loaded for real: WordPress's plugin.php and WP_Hook (tests/wordpress), and
 * TCGiant_Sync_Inventory, which hooks itself in as on a site. WooCommerce's
 * admin function is in includes/admin/wc-admin-functions.php, which runs only
 * on an admin screen; it is reproduced below, as WooCommerce has it since
 * 3.7.1, with the filter asked exactly as it asks it. Its stock write and the
 * order's own reduction are played.
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-88s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 160 ) );
}

// WordPress's own hooks.
require __DIR__ . '/wordpress/plugin.php';

$OPTIONS  = array( 'woocommerce_manage_stock' => 'yes' );
$PRODUCTS = array();
$ORDERS   = array();
$LOG      = array();
$NEXT     = 9000;

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function wp_next_scheduled() { return true; }
function wp_schedule_event() { return true; }
function wp_clear_scheduled_hook() { return 0; }
function wc_stock_amount( $value ) { return (int) $value; }
function absint( $value ) { return abs( (int) $value ); }

// --- WooCommerce, as far as an order's stock goes -------------------------------

class WC_Product {
	private $id;
	public function __construct( $id ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function managing_stock() { return true; }
	public function get_stock_quantity() { return $GLOBALS['PRODUCTS'][ $this->id ]; }
}
function wc_get_product( $id ) { return isset( $GLOBALS['PRODUCTS'][ (int) $id ] ) ? new WC_Product( $id ) : false; }

/** wc_update_product_stock(), as far as the number goes: one statement, no floor. */
function wc_update_product_stock( $product, $quantity = null, $operation = 'set' ) {
	$id = is_object( $product ) ? $product->get_id() : (int) $product;
	if ( 'increase' === $operation ) {
		$GLOBALS['PRODUCTS'][ $id ] += (int) $quantity;
	} elseif ( 'decrease' === $operation ) {
		$GLOBALS['PRODUCTS'][ $id ] -= (int) $quantity;
	} else {
		$GLOBALS['PRODUCTS'][ $id ] = (int) $quantity;
	}
	return $GLOBALS['PRODUCTS'][ $id ];
}

class WC_Abstract_Order {
	public $id = 0;
	public $status = 'pending';
	public $meta = array();
	public $items = array();
	public $stock_reduced = false;
	public function get_id() { return $this->id; }
	public function get_status() { return $this->status; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function get_items() { return $this->items; }
	public function get_qty_refunded_for_item( $item_id ) { return 0; }
}
class WC_Order extends WC_Abstract_Order {}

class WC_Order_Item_Product {
	public $id;
	public $order_id;
	public $product_id;
	public $quantity;
	public $meta = array();
	public function __construct( $order_id, $product_id, $quantity ) {
		$this->id         = ++$GLOBALS['NEXT'];
		$this->order_id   = $order_id;
		$this->product_id = $product_id;
		$this->quantity   = $quantity;
	}
	public function get_id() { return $this->id; }
	public function get_type() { return 'line_item'; }
	public function get_quantity() { return $this->quantity; }
	public function get_product() { return wc_get_product( $this->product_id ); }
	public function get_order() { return $GLOBALS['ORDERS'][ $this->order_id ] ?? false; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() {}
}

/** An order with one line, saved. */
function order( $product_id, $quantity, $status, array $meta = array() ) {
	$order         = new WC_Order();
	$order->id     = ++$GLOBALS['NEXT'];
	$order->status = $status;
	$order->meta   = $meta;
	$GLOBALS['ORDERS'][ $order->id ] = $order;
	$order->items[] = new WC_Order_Item_Product( $order->id, $product_id, $quantity );
	return $order;
}

/**
 * WooCommerce reducing an order's stock as it is paid or marked processing:
 * wc_maybe_reduce_stock_levels() and wc_reduce_stock_levels(), as far as the
 * filter and the record go. The order is marked as reduced either way.
 */
function woocommerce_reduces( $order ) {
	if ( apply_filters( 'woocommerce_can_reduce_order_stock', true, $order ) ) {
		foreach ( $order->get_items() as $item ) {
			wc_update_product_stock( $item->get_product(), $item->get_quantity(), 'decrease' );
			$item->update_meta_data( '_reduced_stock', $item->get_quantity() );
		}
	}
	$order->stock_reduced = true;
}

/**
 * REPRODUCED from WooCommerce (includes/admin/wc-admin-functions.php, 3.7.1
 * and later): wc_maybe_adjust_line_item_product_stock(), which saving an
 * order's items on its edit screen runs for each line of an order in
 * processing, completed or on-hold.
 */
function wc_maybe_adjust_line_item_product_stock( $item, $item_quantity = -1 ) {
	if ( 'line_item' !== $item->get_type() ) {
		return false;
	}

	/**
	 * Prevent adjust line item product stock.
	 *
	 * @since 3.7.1
	 * @param bool $prevent If should prevent.
	 * @param WC_Order_Item $item Item object.
	 * @param int           $item_quantity Optional quantity to check against.
	 */
	if ( apply_filters( 'woocommerce_prevent_adjust_line_item_product_stock', false, $item, $item_quantity ) ) {
		return false;
	}

	$product               = $item->get_product();
	$item_quantity         = wc_stock_amount( $item_quantity >= 0 ? $item_quantity : $item->get_quantity() );
	$already_reduced_stock = wc_stock_amount( $item->get_meta( '_reduced_stock', true ) );

	if ( ! $product || ! $product->managing_stock() ) {
		return false;
	}

	$order                  = $item->get_order();
	$refunded_item_quantity = $order->get_qty_refunded_for_item( $item->get_id() );
	$diff                   = $item_quantity + $refunded_item_quantity - $already_reduced_stock;

	// 0 as $item_quantity usually indicates we're deleting the order item.
	if ( 0 === $item_quantity ) {
		$diff = min( absint( $refunded_item_quantity ), $already_reduced_stock ) * -1;
	}

	if ( $diff < 0 ) {
		$new_stock = wc_update_product_stock( $product, $diff * -1, 'increase' );
	} elseif ( $diff > 0 ) {
		$new_stock = wc_update_product_stock( $product, $diff, 'decrease' );
	} else {
		return false;
	}

	$item->update_meta_data( '_reduced_stock', $item_quantity );
	$item->save();

	return array( 'from' => $new_stock + $diff, 'to' => $new_stock );
}

/** The shop clicks Update on the order's edit screen: its items saved, then its status. */
function shop_updates( $order, $status ) {
	if ( in_array( $order->get_status(), array( 'processing', 'completed', 'on-hold' ), true ) ) {
		foreach ( $order->get_items() as $item ) {
			wc_maybe_adjust_line_item_product_stock( $item );
		}
	}
	$order->status = $status;
}

// --- The plugin -----------------------------------------------------------------

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = $message; }
	public static function warning( $message ) { self::log( $message ); }
	public static function error( $message ) { self::log( $message ); }
}

require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
$INVENTORY = TCGiant_Sync_Inventory::instance();

$COIN = 501;
function logged( $words ) { return count( array_filter( $GLOBALS['LOG'], function ( $l ) use ( $words ) { return false !== strpos( $l, $words ); } ) ); }

/** Numismax: a coin, one of it, sold on eBay; the sync settles it to 0 and imports the order. */
function ebay_sale() {
	global $COIN;
	$GLOBALS['PRODUCTS'][ $COIN ] = 0; // The per-sale stock check, from eBay's figures.
	$order = order( $COIN, 1, 'processing', array( '_ebay_order_source' => 'ebay', '_ebay_order_id' => '27-13777-00001' ) );
	woocommerce_reduces( $order );
	return $order;
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAN IMPORTED EBAY ORDER, SAVED FROM ITS EDIT SCREEN\n" . str_repeat( '=', 128 ) . "\n";

check( 'the filter is hooked, at 10, taking the line', array( has_filter( 'woocommerce_prevent_adjust_line_item_product_stock', array( $INVENTORY, 'keep_ebay_order_lines' ) ), $GLOBALS['wp_filter']['woocommerce_prevent_adjust_line_item_product_stock']->callbacks[10][ _wp_filter_build_unique_id( '', array( $INVENTORY, 'keep_ebay_order_lines' ), 10 ) ]['accepted_args'] ?? null ), array( 10, 2 ) );

// What 3.27.5 did: the same, with the plugin's answer taken away.
remove_filter( 'woocommerce_prevent_adjust_line_item_product_stock', array( $INVENTORY, 'keep_ebay_order_lines' ), 10 );
$order = ebay_sale();
check( 'without it (3.27.5): the import takes nothing off, but marks the order reduced', array( $PRODUCTS[ $COIN ], $order->stock_reduced, $order->items[0]->get_meta( '_reduced_stock' ) ), array( 0, true, '' ) );
shop_updates( $order, 'completed' );
check( '  and Update on the edit screen takes the sale off again: -1', $PRODUCTS[ $COIN ], -1 );
add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', array( $INVENTORY, 'keep_ebay_order_lines' ), 10, 2 );

$LOG   = array();
$order = ebay_sale();
shop_updates( $order, 'completed' );
check( 'with it: Update (tracking added, Completed) leaves the coin at 0', $PRODUCTS[ $COIN ], 0 );
check( '  nothing recorded against the line', $order->items[0]->get_meta( '_reduced_stock' ), '' );
check( '  the log says why, once for the order', logged( sprintf( 'Order #%d came from eBay: saving it does not change WooCommerce stock', $order->get_id() ) ), 1 );
shop_updates( $order, 'completed' );
shop_updates( $order, 'completed' );
check( '  saved again and again: still 0, still said once', array( $PRODUCTS[ $COIN ], logged( 'saving it does not change WooCommerce stock' ) ), array( 0, 1 ) );

// The line removed, or its quantity changed, on an eBay order: no stock moves.
$order = ebay_sale();
check( 'its line removed (quantity 0): no stock put back that was never taken', array( wc_maybe_adjust_line_item_product_stock( $order->items[0], 0 ), $PRODUCTS[ $COIN ] ), array( false, 0 ) );
check( '  its quantity changed to 3: none taken off', array( wc_maybe_adjust_line_item_product_stock( $order->items[0], 3 ), $PRODUCTS[ $COIN ] ), array( false, 0 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEVERY OTHER ORDER, AS WOOCOMMERCE HAS IT\n" . str_repeat( '=', 128 ) . "\n";

$PRODUCTS[ $COIN ] = 5;
$web = order( $COIN, 1, 'processing' );
woocommerce_reduces( $web );
check( 'a website order: WooCommerce takes its unit off as it is paid', array( $PRODUCTS[ $COIN ], $web->items[0]->get_meta( '_reduced_stock' ) ), array( 4, 1 ) );
shop_updates( $web, 'completed' );
check( '  saved from its edit screen unchanged: nothing more', $PRODUCTS[ $COIN ], 4 );
$web->items[0]->quantity = 2;
shop_updates( $web, 'completed' );
check( '  its quantity raised to 2: WooCommerce takes the second off, as before', array( $PRODUCTS[ $COIN ], $web->items[0]->get_meta( '_reduced_stock' ) ), array( 3, 2 ) );

$other = order( $COIN, 1, 'processing', array( '_ebay_order_source' => 'amazon' ) );
check( 'an order another integration imported is no eBay order', $INVENTORY->keep_ebay_order_lines( false, $other->items[0] ), false );
check( 'another plugin\'s yes stays yes, whatever the line', array( $INVENTORY->keep_ebay_order_lines( true, $other->items[0] ), $INVENTORY->keep_ebay_order_lines( true ), $INVENTORY->keep_ebay_order_lines( true, new stdClass() ) ), array( true, true, true ) );
check( 'no line, or one with no order to ask: left as it was', array( $INVENTORY->keep_ebay_order_lines( false ), $INVENTORY->keep_ebay_order_lines( false, new stdClass() ) ), array( false, false ) );
$orphan = new WC_Order_Item_Product( 1, $COIN, 1 );
check( '  a line whose order cannot be found', $INVENTORY->keep_ebay_order_lines( false, $orphan ), false );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
