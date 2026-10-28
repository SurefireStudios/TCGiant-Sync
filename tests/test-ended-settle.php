<?php
/**
 * Harness: settling an ended eBay listing can only ever lower stock.
 *
 * A coin shop wrote in that a coin it had already sold went back up for sale.
 * The coin sold on the shop's own website, so WooCommerce took its stock to 0,
 * and the plugin ended the eBay listing. eBay recorded that listing as 1 listed
 * and 0 sold - eBay never sees a website sale - and the next sync's ended-
 * listing settle wrote eBay's "listed minus sold" straight over WooCommerce's
 * stock: 1, in stock, for sale a second time. Anything sold on the website and
 * then ended on eBay could come back the same way.
 *
 * 3.27.0 keeps the lower of the two figures. eBay's is lower when it sold
 * something WooCommerce has not heard about yet; WooCommerce's is lower when the
 * website sold it, or someone adjusted it by hand. Where eBay says more are left
 * than WooCommerce holds, that is recorded (and logged once) for Stock Review,
 * never applied. A live listing's stock, which order sync now checks after each
 * eBay sale, is likewise only ever lowered (lower_stock_to).
 *
 * This loads the real TCGiant_Sync_Inventory with WooCommerce stubbed out.
 * Products are rows in an array; wc_get_product() hands back a snapshot of one,
 * as WooCommerce does; wc_update_product_stock() saves the quantity, brings the
 * status into line and fires the stock action WooCommerce fires - the one the
 * real class listens to in order to push a changed quantity to eBay. So the
 * last section can check the thing a settle must never do: send what it writes
 * back to eBay.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-78s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

// --- the world the inventory class runs in -----------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$SHOP       = array(); // Product rows by ID, as the database holds them.
$META       = array(); // Post meta by ID.
$TRANSIENTS = array();
$HOOKS      = array();
$WRITES     = array(); // Every stock write: kind, ID, value, whether pushes were suppressed.
$PUSHES     = array(); // Every WooCommerce -> eBay stock push queued.
$LOG        = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['HOOKS'][ $hook ][] = array( $callback, $accepted_args );
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_action( $hook, $callback, $priority, $accepted_args );
}
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $listener ) {
		call_user_func_array( $listener[0], array_slice( $args, 0, $listener[1] ) );
	}
}
// Never at checkout here: every stock change is either a hand edit or a settle.
function did_action( $hook ) { return 0; }
// A shop connected to eBay: a push is queued only for one holding both tokens (3.28.0).
function get_option( $name, $default = false ) { return 'tcgiant_sync_ebay_settings' === $name ? array( 'access_token' => 'harness-access', 'refresh_token' => 'harness-refresh' ) : $default; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_transient( $key ) { return $GLOBALS['TRANSIENTS'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['TRANSIENTS'][ $key ] ); return true; }

// Post meta as the database keeps it: a number goes in, a string comes out.
function get_post_meta( $id, $key = '', $single = false ) {
	$value = $GLOBALS['META'][ (int) $id ][ $key ] ?? null;
	if ( null === $value ) {
		return $single ? '' : array();
	}
	return $single ? $value : array( $value );
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['META'][ (int) $id ][ $key ] = is_scalar( $value ) ? (string) $value : $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['META'][ (int) $id ][ $key ] );
	return true;
}

// Action Scheduler: the only thing queued here is a push of stock to eBay.
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	$GLOBALS['PUSHES'][] = array( $hook, $args );
	return 1;
}

// What the push says when a product has nothing it can push (3.28.0).
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
}

/** A WooCommerce product: a snapshot of its row, taken when it was fetched. */
class Fake_Product {
	private $row;
	public function __construct( array $row ) { $this->row = $row; }
	public function get_id() { return $this->row['id']; }
	public function managing_stock() { return $this->row['manage']; }
	public function get_stock_quantity() { return $this->row['manage'] ? $this->row['qty'] : null; }
	public function get_stock_status() { return $this->row['status']; }
	public function is_in_stock() { return 'outofstock' !== $this->row['status']; }
	public function is_type( $type ) { return $this->row['type'] === $type; }
	// A variation with no SKU of its own shows its parent's, except in 'edit'.
	public function get_sku( $context = 'view' ) {
		if ( '' === (string) $this->row['sku'] && 'view' === $context && ! empty( $this->row['parent'] ) ) {
			return (string) ( $GLOBALS['SHOP'][ $this->row['parent'] ]['sku'] ?? '' );
		}
		return $this->row['sku'];
	}
	public function get_parent_id() { return (int) ( $this->row['parent'] ?? 0 ); }
	// Every product here keeps its own stock.
	public function get_stock_managed_by_id() { return $this->row['id']; }
	public function get_meta( $key, $single = true ) { return get_post_meta( $this->row['id'], $key, true ); }
}

function wc_get_product( $id ) {
	$id = (int) $id;
	return isset( $GLOBALS['SHOP'][ $id ] ) ? new Fake_Product( $GLOBALS['SHOP'][ $id ] ) : false;
}

/**
 * As WooCommerce's own: save the quantity, let save() bring the status into
 * line with it, then fire woocommerce_product_set_stock (or the variation one).
 * A row marked 'stuck' keeps its quantity, as when something else on the site
 * holds it; one marked 'throws' fails part way, as a database error would.
 */
function wc_update_product_stock( $product, $quantity = null, $operation = 'set', $updating = false ) {
	$id = is_object( $product ) ? $product->get_id() : (int) $product;
	$GLOBALS['WRITES'][] = array( 'qty', $id, $quantity, TCGiant_Sync_Inventory::is_push_suppressed() );

	if ( ! isset( $GLOBALS['SHOP'][ $id ] ) ) {
		return null;
	}
	$row = &$GLOBALS['SHOP'][ $id ];
	if ( ! empty( $row['throws'] ) ) {
		throw new RuntimeException( 'MySQL server has gone away' );
	}
	if ( null === $quantity || ! $row['manage'] ) {
		return $row['qty'];
	}
	if ( empty( $row['stuck'] ) ) {
		$row['qty']    = 'set' === $operation ? (int) $quantity : ( 'decrease' === $operation ? $row['qty'] - (int) $quantity : $row['qty'] + (int) $quantity );
		$row['status'] = $row['qty'] > 0 ? 'instock' : 'outofstock';
	}
	unset( $row );

	$saved = wc_get_product( $id );
	do_action( $saved->is_type( 'variation' ) ? 'woocommerce_variation_set_stock' : 'woocommerce_product_set_stock', $saved );
	return $saved->get_stock_quantity();
}

function wc_update_product_stock_status( $id, $status ) {
	$id = (int) $id;
	$GLOBALS['WRITES'][] = array( 'status', $id, $status, TCGiant_Sync_Inventory::is_push_suppressed() );
	if ( isset( $GLOBALS['SHOP'][ $id ] ) ) {
		$GLOBALS['SHOP'][ $id ]['status'] = $status;
	}
}

// Its stock writes turn on the save-loop breaker, which lives in the runtime.
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-runtime.php';
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-inventory.php';

// The constructor hooks the real WooCommerce -> eBay stock push.
TCGiant_Sync_Inventory::instance();

// --- driving it --------------------------------------------------------------

/** A fresh shop holding these products, with nothing written or queued. */
function fresh_shop( array $products ) {
	foreach ( array( 'SHOP', 'META', 'TRANSIENTS', 'WRITES', 'PUSHES', 'LOG' ) as $name ) {
		$GLOBALS[ $name ] = array();
	}
	foreach ( $products as $p ) {
		$GLOBALS['SHOP'][ $p['id'] ] = $p;
		if ( ! empty( $p['item'] ) ) {
			$GLOBALS['META'][ $p['id'] ]['_ebay_item_id'] = $p['item'];
		}
	}
}

/** A product WooCommerce tracks a quantity for. */
function product( $id, $qty, array $extra = array() ) {
	return array_merge( array(
		'id'     => $id,
		'manage' => true,
		'qty'    => $qty,
		'status' => $qty > 0 ? 'instock' : 'outofstock',
		'type'   => 'simple',
		'sku'    => 'SKU-' . $id,
	), $extra );
}

/** A product with only a stock status, no quantity. */
function untracked( $id, $status ) {
	return array( 'id' => $id, 'manage' => false, 'qty' => null, 'status' => $status, 'type' => 'simple', 'sku' => 'SKU-' . $id );
}

/** A product linked to an eBay listing, so a stock change of its own is pushed. */
function linked( array $row ) {
	$row['item'] = '1100000' . str_pad( (string) $row['id'], 5, '0', STR_PAD_LEFT );
	return $row;
}

function settle( $id, $listed, $sold ) {
	return TCGiant_Sync_Inventory::apply_ended_listing_stock( $id, $listed, $sold );
}

function lower( $id, $available ) {
	if ( ! method_exists( 'TCGiant_Sync_Inventory', 'lower_stock_to' ) ) {
		return 'no lower_stock_to()';
	}
	return TCGiant_Sync_Inventory::lower_stock_to( $id, $available );
}

function qty( $id ) { return $GLOBALS['SHOP'][ $id ]['qty']; }
function status( $id ) { return $GLOBALS['SHOP'][ $id ]['status']; }
function meta( $id, $key ) { return get_post_meta( $id, $key, true ); }

/** How many writes of this kind ('qty' or 'status') were made. */
function writes( $kind ) {
	return count( array_filter( $GLOBALS['WRITES'], function ( $w ) use ( $kind ) { return $kind === $w[0]; } ) );
}

/** Whether a status write set this status. */
function status_written( $status ) {
	foreach ( $GLOBALS['WRITES'] as $w ) {
		if ( 'status' === $w[0] && $status === $w[2] ) {
			return true;
		}
	}
	return false;
}

/** Whether every write so far was made with pushes to eBay suppressed. */
function all_writes_suppressed() {
	foreach ( $GLOBALS['WRITES'] as $w ) {
		if ( ! $w[3] ) {
			return false;
		}
	}
	return ! empty( $GLOBALS['WRITES'] );
}

function logged( $needle ) {
	$n = 0;
	foreach ( $GLOBALS['LOG'] as $entry ) {
		if ( false !== strpos( $entry[1], $needle ) ) {
			$n++;
		}
	}
	return $n;
}

function suppressed() { return TCGiant_Sync_Inventory::is_push_suppressed(); }

echo "\nTHE COIN SHOP: A COIN SOLD ON THE WEBSITE STAYS SOLD\n" . str_repeat( '=', 124 ) . "\n";

// A one-off coin, sold on the website: WooCommerce took it to 0 and the plugin
// ended its eBay listing, which eBay records as 1 listed, 0 sold.
fresh_shop( array( linked( product( 501, 0 ) ) ) );
$got = settle( 501, 1, 0 );
check( 'WC 0, eBay 1 listed 0 sold: the coin stays at 0', qty( 501 ), 0 );
check( '  and out of stock', status( 501 ), 'outofstock' );
check( '  no quantity was written over it', writes( 'qty' ), 0 );
check( '  nothing set it back in stock', status_written( 'instock' ), false );
check( '  the settle reports the 0 WooCommerce holds', $got, 0 );
// Not even offered on Stock Review: with nothing in WooCommerce this is the
// website sale itself, and "use eBay's figure" would put the coin back.
check( '  and eBay\'s 1 is not offered as a figure to use', meta( 501, '_ebay_unsold_at_end' ), '' );
check( '  the product is still marked settled', '' !== meta( 501, '_ebay_stock_settled_at' ), true );
check( '  nothing pushed to eBay', $PUSHES, array() );

// The incident was the NEXT sync putting it back. Every sync must agree.
settle( 501, 1, 0 );
check( 'the next sync settles it again: still 0', qty( 501 ), 0 );
check( '  still out of stock', status( 501 ), 'outofstock' );
check( '  still nothing written over it', writes( 'qty' ), 0 );

echo "\neBAY'S FIGURE ONLY EVER LOWERS WOOCOMMERCE'S\n" . str_repeat( '=', 124 ) . "\n";

$cases = array(
	// What happened, WC stock, eBay listed, eBay sold, stock after, quantity writes, note kept.
	array( 'sold on eBay, WooCommerce not yet told', 1, 1, 1, 0, 1, '' ),
	array( 'ended unsold, nothing sold anywhere', 1, 1, 0, 1, 0, '' ),
	array( 'three of ten left on eBay, five in WooCommerce', 5, 10, 7, 3, 1, '' ),
	array( 'five left on eBay, two more sold on the website', 3, 10, 5, 3, 0, '5' ),
	array( 'ended unsold, three sold on the website', 2, 5, 0, 2, 0, '5' ),
	array( 'sold out on eBay, WooCommerce already at 0', 0, 1, 1, 0, 0, '' ),
	array( 'eBay reports more sold than listed', 2, 1, 3, 0, 1, '' ),
);

foreach ( $cases as $i => $c ) {
	list( $what, $wc, $listed, $sold, $want, $want_writes, $want_note ) = $c;
	$id = 600 + $i;
	fresh_shop( array( product( $id, $wc ) ) );
	$got = settle( $id, $listed, $sold );
	check( sprintf( 'WC %d, eBay %d listed %d sold (%s): %d', $wc, $listed, $sold, $what, $want ), qty( $id ), $want );
	check( '  returned', $got, $want );
	check( '  quantity writes', writes( 'qty' ), $want_writes );
	check( '  status', status( $id ), $want > 0 ? 'instock' : 'outofstock' );
	check( '  never raised', qty( $id ) <= $wc, true );
	check( '  eBay\'s higher figure noted', meta( $id, '_ebay_unsold_at_end' ), $want_note );
}

echo "\neBAY SAYING MORE ARE LEFT IS RECORDED, NOT APPLIED\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( array( product( 700, 2 ) ) );
settle( 700, 5, 0 );
settle( 700, 5, 0 );
check( 'WC 2, eBay 5 listed 0 sold, settled twice: WooCommerce\'s 2 stands', qty( 700 ), 2 );
check( '  still in stock', status( 700 ), 'instock' );
check( '  eBay\'s 5 is recorded', meta( 700, '_ebay_unsold_at_end' ), '5' );
check( '  and logged once across the two syncs, not on every pass', logged( 'ended with 5 unsold' ), 1 );
check( '  naming both figures', logged( 'WC #700: its eBay listing ended with 5 unsold, but WooCommerce holds 2.' ), 1 );

settle( 700, 6, 0 );
check( 'a different figure from eBay is recorded', meta( 700, '_ebay_unsold_at_end' ), '6' );
check( '  and logged, once', logged( 'WC #700: its eBay listing ended with 6 unsold' ), 1 );
check( '  WooCommerce\'s 2 still stands', qty( 700 ), 2 );

settle( 700, 5, 3 );
check( 'once eBay no longer says more, the note is cleared', meta( 700, '_ebay_unsold_at_end' ), '' );
check( '  the stock still 2', qty( 700 ), 2 );

echo "\nA PRODUCT THAT DOES NOT TRACK A QUANTITY\n" . str_repeat( '=', 124 ) . "\n";

// Only a status to go on. eBay can say a listing sold out, but not that the
// shop still has one.
fresh_shop( array( untracked( 800, 'outofstock' ) ) );
$got = settle( 800, 1, 0 );
check( 'untracked and out of stock, eBay 1 listed 0 sold: stays out of stock', status( 800 ), 'outofstock' );
check( '  never flipped to in stock', status_written( 'instock' ), false );
check( '  reported as 0', $got, 0 );
check( '  no quantity invented for it', qty( 800 ), null );

fresh_shop( array( untracked( 801, 'instock' ) ) );
$got = settle( 801, 1, 1 );
check( 'untracked and in stock, eBay 1 listed 1 sold: out of stock', status( 801 ), 'outofstock' );
check( '  reported as 0', $got, 0 );
check( '  no quantity written', writes( 'qty' ), 0 );

fresh_shop( array( untracked( 802, 'instock' ) ) );
settle( 802, 1, 0 );
check( 'untracked and in stock, eBay ended unsold: left in stock', status( 802 ), 'instock' );
check( '  its status not touched', writes( 'status' ), 0 );

echo "\nNOTHING TO GO ON\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( array( product( 900, 3 ) ) );
check( 'no figures from eBay: null', settle( 900, null, null ), null );
check( '  half an answer: null', settle( 900, 1, null ), null );
check( '  the other half: null', settle( 900, null, 1 ), null );
check( '  the stock untouched', qty( 900 ), 3 );
check( '  nothing written', $WRITES, array() );
check( '  no meta written, not even the settled mark', $META[900] ?? array(), array() );
check( 'a product that no longer exists: null', settle( 999, 1, 1 ), null );
check( '  nothing written', $WRITES, array() );

echo "\neBAY'S OWN FIGURES ARE KEPT\n" . str_repeat( '=', 124 ) . "\n";

// So that "sold out on eBay" can be told from "sold on the website" later,
// without asking eBay again - eBay drops old listings.
fresh_shop( array( product( 1000, 5 ) ) );
settle( 1000, 10, 7 );
check( 'the quantity eBay listed is kept', meta( 1000, '_ebay_quantity_listed' ), '10' );
check( '  and the quantity it sold', meta( 1000, '_ebay_quantity_sold' ), '7' );
check( '  beside the settled mark', '' !== meta( 1000, '_ebay_stock_settled_at' ), true );

fresh_shop( array( product( 1001, 0 ) ) );
settle( 1001, 1, 0 );
check( 'the coin: 1 listed is kept', meta( 1001, '_ebay_quantity_listed' ), '1' );
check( '  and 0 sold, which is what says it was not eBay that sold it', meta( 1001, '_ebay_quantity_sold' ), '0' );

fresh_shop( array( untracked( 1002, 'instock' ) ) );
settle( 1002, 3, 3 );
check( 'kept for an untracked product too', array( meta( 1002, '_ebay_quantity_listed' ), meta( 1002, '_ebay_quantity_sold' ) ), array( '3', '3' ) );

echo "\nA WRITE THAT DOES NOT TAKE\n" . str_repeat( '=', 124 ) . "\n";

// Something else on the site holds the quantity. Saying so and leaving the
// product where Stock Review can still see it is the safe direction.
fresh_shop( array( product( 1100, 5, array( 'stuck' => true ) ) ) );
$got = settle( 1100, 10, 7 );
check( 'a quantity write that does not take: null', $got, null );
check( '  no status written behind it', writes( 'status' ), 0 );
check( '  not marked settled, so it stays on Stock Review', meta( 1100, '_ebay_stock_settled_at' ), '' );
check( '  said so in the log', logged( 'but the quantity did not change' ), 1 );
check( '  and pushes to eBay are switched back on', suppressed(), false );

echo "\nA LIVE LISTING'S STOCK IS ONLY EVER LOWERED\n" . str_repeat( '=', 124 ) . "\n";

// lower_stock_to(): the sale check order sync queues after each eBay sale.
fresh_shop( array( product( 1200, 5 ) ) );
check( 'WC 5, eBay says 3 left: lowered to 3', lower( 1200, 3 ), 3 );
check( '  the stock is 3', qty( 1200 ), 3 );
check( '  still in stock', status( 1200 ), 'instock' );
check( '  not marked out of stock', status_written( 'outofstock' ), false );

fresh_shop( array( product( 1201, 3 ) ) );
check( 'WC 3, eBay says 5 left: never raised, still 3', lower( 1201, 5 ), 3 );
check( '  the stock is 3', qty( 1201 ), 3 );
check( '  nothing written', $WRITES, array() );

fresh_shop( array( product( 1202, 4 ) ) );
check( 'WC 4, eBay says 4 left: left alone', lower( 1202, 4 ), 4 );
check( '  nothing written', $WRITES, array() );

fresh_shop( array( product( 1203, 2 ) ) );
check( 'WC 2, nothing left on eBay: 0', lower( 1203, 0 ), 0 );
check( '  the stock is 0', qty( 1203 ), 0 );
check( '  and it is set out of stock', status_written( 'outofstock' ), true );
check( '  out of stock', status( 1203 ), 'outofstock' );

fresh_shop( array( product( 1204, 2 ) ) );
check( 'a negative figure from eBay is taken as 0', lower( 1204, -3 ), 0 );
check( '  the stock is 0, not -3', qty( 1204 ), 0 );

fresh_shop( array( product( 1205, 0 ) ) );
check( 'WC 0, eBay says 1 left: still 0', lower( 1205, 1 ), 0 );
check( '  nothing written', $WRITES, array() );

fresh_shop( array( untracked( 1206, 'instock' ) ) );
check( 'an untracked product: null', lower( 1206, 0 ), null );
check( '  left in stock', status( 1206 ), 'instock' );
check( '  nothing written', $WRITES, array() );

fresh_shop( array() );
check( 'a product that no longer exists: null', lower( 1299, 0 ), null );

fresh_shop( array( product( 1207, 5, array( 'stuck' => true ) ) ) );
check( 'a write that does not take: null', lower( 1207, 2 ), null );
check( '  no status written behind it', writes( 'status' ), 0 );
check( '  and pushes to eBay are switched back on', suppressed(), false );

echo "\nNOTHING A SETTLE WRITES IS PUSHED BACK TO eBAY\n" . str_repeat( '=', 124 ) . "\n";

// First, that the harness has teeth: a hand edit on a linked product fires the
// stock action, the real class hears it, and a push to eBay is queued.
//
// Since 3.28.0 the push names the product and reads its stock when it runs,
// on a hook of its own; the quantity is not queued.
fresh_shop( array( linked( product( 1300, 5 ) ) ) );
wc_update_product_stock( 1300, 4, 'set' );
check( 'a hand edit on a linked product is pushed to eBay, as it should be', count( $PUSHES ), 1 );
check( '  as a stock push', $PUSHES[0][0] ?? null, 'tcgiant_sync_push_stock' );
check( '  for that product, as a change made in WooCommerce', array( $PUSHES[0][1][0]['product_id'] ?? null, $PUSHES[0][1][0]['reason'] ?? null ), array( 1300, 'edit' ) );
check( '  with no quantity or SKU to go stale', array( isset( $PUSHES[0][1][0]['quantity'] ), isset( $PUSHES[0][1][0]['sku'] ) ), array( false, false ) );

// A variation has no listing of its own: its parent holds it.
fresh_shop( array( linked( product( 1302, null, array( 'type' => 'variable', 'manage' => false ) ) ), product( 1301, 2, array( 'type' => 'variation', 'parent' => 1302 ) ) ) );
wc_update_product_stock( 1301, 1, 'set' );
check( '  and so is one on a variation, through its parent', array( count( $PUSHES ), $PUSHES[0][1][0]['product_id'] ?? null ), array( 1, 1301 ) );

// Then every write a settle makes. Each case must really write something, or
// "nothing pushed" would prove nothing.
$push_cases = array(
	'sold on eBay, 1 to 0'          => array( product( 1310, 1 ), 1, 1 ),
	'part sold on eBay, 5 to 3'     => array( product( 1311, 5 ), 10, 7 ),
	'a variation sold out, 2 to 0'  => array( product( 1312, 2, array( 'type' => 'variation' ) ), 1, 1 ),
	'untracked, sold out on eBay'   => array( untracked( 1313, 'instock' ), 1, 1 ),
);

foreach ( $push_cases as $what => $c ) {
	list( $row, $listed, $sold ) = $c;
	fresh_shop( array( linked( $row ) ) );
	settle( $row['id'], $listed, $sold );
	check( "settle, $what: stock written", count( $WRITES ) > 0, true );
	check( '  every write made with pushes suppressed', all_writes_suppressed(), true );
	check( '  nothing pushed to eBay', $PUSHES, array() );
	check( '  and pushes switched back on afterwards', suppressed(), false );
}

fresh_shop( array( linked( product( 1320, 5 ) ) ) );
lower( 1320, 3 );
check( 'lower_stock_to, 5 to 3: stock written', writes( 'qty' ), 1 );
check( '  every write made with pushes suppressed', all_writes_suppressed(), true );
check( '  nothing pushed to eBay', $PUSHES, array() );
check( '  and pushes switched back on afterwards', suppressed(), false );

fresh_shop( array( linked( product( 1321, 2 ) ) ) );
lower( 1321, 0 );
check( 'lower_stock_to, 2 to 0: quantity and status written', array( writes( 'qty' ), writes( 'status' ) ), array( 1, 1 ) );
check( '  every write made with pushes suppressed', all_writes_suppressed(), true );
check( '  nothing pushed to eBay', $PUSHES, array() );

// A settle that dies part way must not leave pushes switched off for the rest
// of the request: every later hand edit would silently stay off eBay.
fresh_shop( array( linked( product( 1330, 5, array( 'throws' => true ) ) ) ) );
$threw = false;
try {
	settle( 1330, 1, 1 );
} catch ( RuntimeException $e ) {
	$threw = true;
}
check( 'a settle whose write fails part way', $threw, true );
check( '  still switches pushes back on', suppressed(), false );
$SHOP[1330]['throws'] = false;
wc_update_product_stock( 1330, 4, 'set' );
check( '  so the next hand edit still reaches eBay', count( $PUSHES ), 1 );

if ( method_exists( 'TCGiant_Sync_Inventory', 'lower_stock_to' ) ) {
	fresh_shop( array( linked( product( 1331, 5, array( 'throws' => true ) ) ) ) );
	try {
		lower( 1331, 1 );
	} catch ( RuntimeException $e ) {
		// As above.
	}
}
check( 'lower_stock_to failing part way also switches pushes back on', method_exists( 'TCGiant_Sync_Inventory', 'lower_stock_to' ) && ! suppressed(), true );

// Called inside a bracket of its own - the delta sync's import - it closes only
// what it opened.
fresh_shop( array( linked( product( 1340, 1 ) ) ) );
TCGiant_Sync_Inventory::begin_ebay_origin();
settle( 1340, 1, 1 );
check( 'a settle inside the importer\'s own bracket leaves that bracket open', suppressed(), true );
TCGiant_Sync_Inventory::end_ebay_origin();
check( '  until the importer closes it', suppressed(), false );
check( '  nothing pushed to eBay', $PUSHES, array() );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
