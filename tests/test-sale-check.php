<?php
/**
 * Harness: an eBay sale reaches the website while the delta sync is behind.
 *
 * A shop whose delta sync had been stuck for eleven days saw no eBay sale reach
 * its website in all that time, and took sold items down by hand. Order sync
 * skipped every product linked to an eBay listing, on the grounds that the
 * delta sets their stock from eBay - true while the delta keeps up, and nothing
 * at all while it is behind.
 *
 * Since 3.27.0 order sync queues a stock check, tcgiant_sync_settle_item, for
 * each eBay sale of a linked product, in an Action Scheduler group of its own
 * so that starting a sync cannot clear it. The check reads the listing from
 * eBay. An ended listing is settled exactly as the delta would settle it; a
 * live one only ever has its stock LOWERED to what eBay says is left. So it can
 * neither take a sale off twice nor put a sold item back up for sale - the
 * coin shop's complaint, from the same release.
 *
 * Since 3.28.0 a variation listing is checked variation by variation, and a
 * variation's eBay sale counts as its parent's listing's, not as the sale of a
 * product with no link.
 *
 * Loaded for real: TCGiant_Sync_Importer, TCGiant_Sync_Inventory,
 * TCGiant_Sync_Variations and TCGiant_Sync_Runtime. WordPress, WooCommerce's stock functions, Action
 * Scheduler and eBay are played here, and a queued check is run the way Action
 * Scheduler runs one: through the hook the importer registered for it.
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

$ROOT = dirname( __DIR__ );

// --- the shop the importer runs in --------------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$OPTIONS   = array();
$PRODUCTS  = array();
$QUEUE     = array();
$HOOKS     = array();
$LOG       = array();
$EVENTS    = array();
$UNGUARDED = array();
$EBAY      = array();
$SYNCED    = array();

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

/**
 * The database, as far as finding a listing's product goes: by _ebay_item_id,
 * with or without the trash, as each query asks.
 */
class Harness_Wpdb {
	public $options  = 'wp_options';
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( $args, &$i ) {
			$value = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
		}, $query );
	}
	public function get_var( $query ) {
		// The stock checks' joins (3.28.0 fix round 3): read by name.
		if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '(tcgiant_sync_settle_joined_[^']+)' LIMIT 1$/", trim( $query ), $jm ) ) {
			return array_key_exists( $jm[1], $GLOBALS['OPTIONS'] ) ? (string) $GLOBALS['OPTIONS'][ $jm[1] ] : null;
		}
		if ( ! preg_match( "/meta_key = '_ebay_item_id' AND (?:pm\\.)?meta_value = '([^']*)'/", $query, $m ) ) {
			return null;
		}
		$live_only = false !== strpos( $query, "post_status != 'trash'" );
		foreach ( $GLOBALS['PRODUCTS'] as $id => $p ) {
			if ( ( $p['meta']['_ebay_item_id'] ?? null ) === $m[1] && ( ! $live_only || 'trash' !== $p['post_status'] ) ) {
				return (string) $id;
			}
		}
		return null;
	}
	public function query( $query ) {
		// The stock checks' joins (3.28.0 fix round 3): one upsert keeping the
		// later time, autoload off; deleted by name. $GLOBALS['ON_JOIN_QUERY'],
		// when set, runs just before the statement lands.
		if ( preg_match( "/^INSERT INTO wp_options \\( option_name, option_value, autoload \\) VALUES \\( '(tcgiant_sync_settle_joined_[^']+)', '?(\\d+)'?, 'no' \\)\\s*ON DUPLICATE KEY UPDATE option_value = GREATEST\\( option_value \\+ 0, '?(\\d+)'? \\)$/", trim( $query ), $jm ) ) {
			if ( isset( $GLOBALS['ON_JOIN_QUERY'] ) ) {
				call_user_func( $GLOBALS['ON_JOIN_QUERY'], $jm[1], (int) $jm[2] );
			}
			$GLOBALS['OPTIONS'][ $jm[1] ] = (string) ( array_key_exists( $jm[1], $GLOBALS['OPTIONS'] ) ? max( (int) $GLOBALS['OPTIONS'][ $jm[1] ], (int) $jm[3] ) : (int) $jm[2] );
			$GLOBALS['JOIN_AUTOLOAD'][ $jm[1] ] = false;
			return 1;
		}
		if ( preg_match( "/^DELETE FROM wp_options WHERE option_name = '(tcgiant_sync_settle_joined_[^']+)'$/", trim( $query ), $jm ) ) {
			$had = array_key_exists( $jm[1], $GLOBALS['OPTIONS'] );
			unset( $GLOBALS['OPTIONS'][ $jm[1] ] );
			return $had ? 1 : 0;
		}
		return 0;
	}
}
$wpdb = new Harness_Wpdb();
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) { return true; }
}


function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['HOOKS'][ $hook ][] = array( $callback, $accepted_args );
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return add_action( $hook, $callback, $priority, $accepted_args ); }
function wp_clear_scheduled_hook() {}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['OPTIONS'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['OPTIONS'][ $name ] );
	return true;
}
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function has_term() { return false; }

// Post meta, kept on the product, every write noted in order.
function get_post_meta( $id, $key = '', $single = false ) {
	$meta = $GLOBALS['PRODUCTS'][ (int) $id ]['meta'] ?? array();
	if ( ! array_key_exists( $key, $meta ) ) {
		return $single ? '' : array();
	}
	return $single ? $meta[ $key ] : array( $meta[ $key ] );
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['EVENTS'][] = sprintf( 'meta %d %s=%s', $id, $key, is_scalar( $value ) ? $value : json_encode( $value ) );
	if ( ! isset( $GLOBALS['PRODUCTS'][ (int) $id ] ) ) {
		return false;
	}
	$GLOBALS['PRODUCTS'][ (int) $id ]['meta'][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	if ( isset( $GLOBALS['PRODUCTS'][ (int) $id ]['meta'][ $key ] ) ) {
		$GLOBALS['EVENTS'][] = sprintf( 'unmeta %d %s', $id, $key );
		unset( $GLOBALS['PRODUCTS'][ (int) $id ]['meta'][ $key ] );
	}
	return true;
}
function wp_trash_post( $id ) {
	$GLOBALS['EVENTS'][]                            = sprintf( 'trash %d', $id );
	$GLOBALS['PRODUCTS'][ (int) $id ]['post_status'] = 'trash';
	return true;
}

// WooCommerce: a product reads its row afresh each time, as a reloaded one does.
class Harness_Product {
	private $id;
	public function __construct( $id ) { $this->id = (int) $id; }
	private function row() { return $GLOBALS['PRODUCTS'][ $this->id ]; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return (int) $this->row()['parent']; }
	public function managing_stock() { return (bool) $this->row()['manage']; }
	public function get_stock_quantity() { return $this->row()['manage'] ? $this->row()['stock'] : null; }
	public function get_stock_status() { return $this->row()['status']; }
	// What pairing a listing's variations with a variable product's reads.
	public function is_type( $type ) { return $type === $this->row()['type']; }
	public function get_status() { return $this->row()['post_status']; }
	public function get_meta( $key, $single = true ) { return $this->row()['meta'][ $key ] ?? ''; }
	public function get_attributes() { return $this->row()['attrs'] ?? array(); }
	/** A variation with no SKU of its own shows its parent's, except in 'edit'. */
	public function get_sku( $context = 'view' ) {
		$sku = (string) $this->row()['sku'];
		if ( '' === $sku && 'view' === $context && $this->get_parent_id() ) {
			return (string) $GLOBALS['PRODUCTS'][ $this->get_parent_id() ]['sku'];
		}
		return $sku;
	}
	/** Its variations, published or retired; never those in the trash. */
	public function get_children() {
		$out = array();
		foreach ( $GLOBALS['PRODUCTS'] as $id => $p ) {
			if ( (int) $p['parent'] === $this->id && 'trash' !== $p['post_status'] ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
/** The parent's price range and stock status follow its variations: noted, with whether pushes were held off. */
class WC_Product_Variable {
	public static function sync( $id ) { $GLOBALS['SYNCED'][] = array( (int) $id, TCGiant_Sync_Inventory::is_push_suppressed() ); }
}
function sanitize_title( $title ) { return trim( preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( trim( (string) $title ) ) ), '-' ); }
// No global attributes in this shop: every variation attribute is a custom one.
function taxonomy_exists( $taxonomy ) { return false; }
function wc_get_product( $id ) {
	return isset( $GLOBALS['PRODUCTS'][ (int) $id ] ) ? new Harness_Product( $id ) : false;
}
function wc_get_product_id_by_sku( $sku ) {
	foreach ( $GLOBALS['PRODUCTS'] as $id => $p ) {
		if ( '' !== (string) $sku && $p['sku'] === $sku && 'trash' !== $p['post_status'] ) {
			return $id;
		}
	}
	return 0;
}
/**
 * WooCommerce's own: set or move a managed quantity, the status following it.
 *
 * A write made while the plugin is not holding off its push to eBay is what
 * would travel back to eBay as a stock revision of ours - and a 0 sent that
 * way ends the listing. Those are noted in $UNGUARDED.
 */
function wc_update_product_stock( $product, $quantity = null, $operation = 'set' ) {
	$id = is_object( $product ) ? $product->get_id() : (int) $product;
	if ( ! isset( $GLOBALS['PRODUCTS'][ $id ] ) ) {
		return false;
	}
	$p = &$GLOBALS['PRODUCTS'][ $id ];
	if ( null !== $quantity && $p['manage'] ) {
		$GLOBALS['EVENTS'][] = sprintf( 'stock %d %s %d', $id, $operation, $quantity );
		if ( ! TCGiant_Sync_Inventory::is_push_suppressed() ) {
			$GLOBALS['UNGUARDED'][] = $id;
		}
		if ( 'decrease' === $operation ) {
			$p['stock'] = (int) $p['stock'] - (int) $quantity;
		} elseif ( 'increase' === $operation ) {
			$p['stock'] = (int) $p['stock'] + (int) $quantity;
		} else {
			$p['stock'] = (int) $quantity;
		}
		$p['status'] = $p['stock'] > 0 ? 'instock' : 'outofstock';
	}
	return $p['manage'] ? $p['stock'] : null;
}
function wc_update_product_stock_status( $id, $status ) {
	$GLOBALS['EVENTS'][]                        = sprintf( 'status %d %s', $id, $status );
	$GLOBALS['PRODUCTS'][ (int) $id ]['status'] = $status;
}

// Action Scheduler, as the sale check uses it.
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	$GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group );
	return count( $GLOBALS['QUEUE'] );
}
function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) { return as_enqueue_async_action( $hook, $args, $group ); }
// Action Scheduler 3.0 and later: the next action with this hook and these
// arguments is taken off the queue (a stock push's safety try, 3.28.0).
function as_unschedule_action( $hook, $args = array(), $group = '' ) {
	foreach ( $GLOBALS['QUEUE'] as $i => $action ) {
		if ( $action['hook'] === $hook && $action['args'] === $args ) {
			array_splice( $GLOBALS['QUEUE'], $i, 1 );
			return $i;
		}
	}
	return null;
}
/** A pending async action answers true, as Action Scheduler's does. */
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['QUEUE'] as $action ) {
		if ( $action['hook'] === $hook && ( null === $args || $action['args'] === $args ) && ( '' === $group || $action['group'] === $group ) ) {
			return true;
		}
	}
	return false;
}
function as_unschedule_all_actions( $hook, $args = null, $group = '' ) {
	$GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) use ( $hook ) { return $a['hook'] !== $hook; } ) );
}

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function write_now( $message, $level = 'error' ) { self::log( $message, $level ); }
	public static function flush() {}
}

class TCGiant_Sync_OAuth {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_settings() { return array(); }
}

/** eBay: the last day's orders, and GetItem for each listing it is asked about. */
class TCGiant_Sync_API {
	// The two eBay sites this shop's listings are on, as TCGiant_Sync_Sites reads them.
	const MARKETPLACES = array(
		'EBAY_US' => array( 'label' => 'United States', 'site' => 'US' ),
		'EBAY_GB' => array( 'label' => 'United Kingdom', 'site' => 'UK' ),
	);
	public static function scalar_value( $raw ) { return is_scalar( $raw ) ? (string) $raw : ''; }
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_orders( $limit = 50, $offset = 0 ) {
		return $GLOBALS['EBAY']['orders'];
	}
	public function get_item( $item_id ) {
		$GLOBALS['EBAY']['get_items'][] = (string) $item_id;
		return $GLOBALS['EBAY']['items'][ (string) $item_id ] ?? new WP_Error( 'api_error', 'The item could not be found.' );
	}
	// The sale check reads past the shared ceiling, as a sale's push does (3.28.0).
	public function with_stock_priority( callable $fn ) { return $fn(); }
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-variations.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-importer.php';

$IMP = TCGiant_Sync_Importer::instance();

// --- driving it --------------------------------------------------------------

/** A shop with nothing in it, no queue, and eBay with no orders. */
function fresh_shop( array $settings = array() ) {
	$GLOBALS['OPTIONS']   = $settings ? array( 'tcgiant_sync_ebay_settings' => $settings ) : array();
	$GLOBALS['PRODUCTS']  = array();
	$GLOBALS['QUEUE']     = array();
	$GLOBALS['LOG']       = array();
	$GLOBALS['EVENTS']    = array();
	$GLOBALS['UNGUARDED'] = array();
	$GLOBALS['SYNCED']    = array();
	$GLOBALS['EBAY']      = array( 'orders' => array( 'orders' => array() ), 'items' => array(), 'get_items' => array() );
}

/**
 * A product; linked to a listing when given its eBay item number. One given a
 * parent is that product's variation; a variable parent says so in $extra.
 */
function product( $id, $sku, $stock, $item_id = '', array $extra = array() ) {
	$row = array_merge( array(
		'type'        => empty( $extra['parent'] ) ? 'simple' : 'variation',
		'sku'         => $sku,
		'stock'       => $stock,
		'manage'      => null !== $stock,
		'status'      => ( null === $stock || $stock > 0 ) ? 'instock' : 'outofstock',
		'post_status' => 'publish',
		'parent'      => 0,
		'meta'        => array(),
	), $extra );
	if ( '' !== $item_id ) {
		$row['meta']['_ebay_item_id'] = $item_id;
	}
	$GLOBALS['PRODUCTS'][ $id ] = $row;
}

/** A listing as GetItem returns it: eBay's figures arrive as strings. */
function listing( $item_id, $status, $listed, $sold, array $extra = array() ) {
	$GLOBALS['EBAY']['items'][ $item_id ] = array( 'Ack' => 'Success', 'Item' => array_merge( array(
		'ItemID'         => $item_id,
		'Quantity'       => (string) $listed,
		'SellingStatus'  => array( 'ListingStatus' => $status, 'QuantitySold' => (string) $sold ),
		'ListingDetails' => array( 'StartTime' => '2026-09-01T10:00:00.000Z', 'EndTime' => '2026-10-11T10:00:00.000Z' ),
	), $extra ) );
}

/** One line of an eBay order, as the Fulfillment API sends it. */
function sale( $line_item_id, $item_id, $sku, $quantity = 1 ) {
	return array( 'lineItemId' => $line_item_id, 'legacyItemId' => $item_id, 'sku' => $sku, 'quantity' => $quantity );
}

/** The day's orders: each argument is one order's lines. */
function orders( array ...$orders ) {
	$out = array();
	foreach ( $orders as $n => $lines ) {
		$out[] = array( 'orderId' => sprintf( '27-13%03d-00000', $n ), 'lineItems' => $lines );
	}
	$GLOBALS['EBAY']['orders'] = array( 'orders' => $out );
}

function order_sync() { $GLOBALS['IMP']->sync_recent_orders(); }

/** The stock check for one listing, called directly. */
function sale_check( $item_id ) {
	if ( method_exists( $GLOBALS['IMP'], 'settle_item' ) ) {
		$GLOBALS['IMP']->settle_item( $item_id );
	}
}

/** Run everything queued, as Action Scheduler would: through the hook. */
function run_queued() {
	$ran = 0;
	while ( $GLOBALS['QUEUE'] ) {
		$action = array_shift( $GLOBALS['QUEUE'] );
		foreach ( $GLOBALS['HOOKS'][ $action['hook'] ] ?? array() as $handler ) {
			call_user_func_array( $handler[0], array_slice( array_values( (array) $action['args'] ), 0, $handler[1] ) );
		}
		$ran++;
	}
	return $ran;
}

function checks_queued() {
	$out = array();
	foreach ( $GLOBALS['QUEUE'] as $action ) {
		if ( 'tcgiant_sync_settle_item' === $action['hook'] ) {
			$out[] = $action['args'];
		}
	}
	return $out;
}

function stock( $id ) { return $GLOBALS['PRODUCTS'][ $id ]['stock']; }
function status( $id ) { return $GLOBALS['PRODUCTS'][ $id ]['status']; }
function trashed( $id ) { return 'trash' === $GLOBALS['PRODUCTS'][ $id ]['post_status']; }
function meta( $id, $key ) { return get_post_meta( $id, $key, true ); }

/** What was written to one product, in order. */
function events_for( $id ) {
	return array_values( array_filter( $GLOBALS['EVENTS'], function ( $e ) use ( $id ) {
		return explode( ' ', $e )[1] === (string) $id;
	} ) );
}

function stock_writes( $id ) {
	return array_values( array_filter( events_for( $id ), function ( $e ) { return 0 === strpos( $e, 'stock ' ); } ) );
}

/** Whether $first was written, and before $then. */
function before( $first, $then ) {
	$a = array_search( $first, $GLOBALS['EVENTS'], true );
	$b = array_search( $then, $GLOBALS['EVENTS'], true );
	return false !== $a && false !== $b && $a < $b;
}

function logged( $needle, $level = null ) {
	foreach ( $GLOBALS['LOG'] as $entry ) {
		if ( false !== strpos( $entry[1], $needle ) && ( null === $level || $level === $entry[0] ) ) {
			return true;
		}
	}
	return false;
}

echo "\nAN EBAY SALE OF A LINKED PRODUCT QUEUES ONE STOCK CHECK\n" . str_repeat( '=', 124 ) . "\n";

// One of three coins sold on eBay. The delta is eleven days behind, so nothing
// else is going to tell the website.
fresh_shop();
product( 101, 'COIN-1', 3, '110000000001' );
listing( '110000000001', 'Active', 3, 1 );
orders( array( sale( '10001', '110000000001', 'COIN-1' ) ) );
order_sync();
check( 'one action queued', count( $QUEUE ), 1 );
check( '  the stock check', $QUEUE[0]['hook'] ?? null, 'tcgiant_sync_settle_item' );
check( '  for the order\'s eBay item number', $QUEUE[0]['args'] ?? null, array( '110000000001' ) );
check( '  in its own group, which starting a sync does not clear', $QUEUE[0]['group'] ?? null, 'tcgiant_sync_settle' );
check( 'the sale is marked as handled', '' !== (string) meta( 101, '_ebay_order_processed_10001' ), true );
check( 'order sync itself takes nothing off - eBay\'s figure decides', stock( 101 ), 3 );
check( '  and asks eBay nothing more', $EBAY['get_items'], array() );
check( 'the log counts it', logged( '1 eBay sales of linked products queued for a stock check' ), true );

order_sync();
check( 'order sync again, the check still waiting: still the one', checks_queued(), array( array( '110000000001' ) ) );

check( 'the stock check has a handler for Action Scheduler to call', isset( $HOOKS['tcgiant_sync_settle_item'] ), true );
run_queued();
check( 'run as Action Scheduler runs it, it reads the listing', $EBAY['get_items'], array( '110000000001' ) );
check( '  and brings the stock down to eBay\'s 2', stock( 101 ), 2 );
check( '  without sending eBay\'s own sale back to eBay', $UNGUARDED, array() );

order_sync();
check( 'order sync after the check has run: nothing queued', $QUEUE, array() );
run_queued();
check( '  eBay not asked again', count( $EBAY['get_items'] ), 1 );
check( '  stock still 2', stock( 101 ), 2 );

echo "\nTHE CHECK IS FOR THE LISTING THAT SOLD\n" . str_repeat( '=', 124 ) . "\n";

// The order's item number, which is not always the listing the SKU matched -
// two listings can carry one SKU, and a product is linked to only one of them.
fresh_shop();
product( 102, 'COIN-2', 1, '110000000002' );
orders( array( sale( '10002', '110000000077', 'COIN-2' ) ) );
order_sync();
check( 'a SKU matched to another listing: the check is for the one that sold', checks_queued(), array( array( '110000000077' ) ) );

fresh_shop();
product( 103, 'COIN-3', 1, '110000000003' );
orders( array( sale( '10003', '', 'COIN-3' ) ) );
order_sync();
check( 'no item number on the order line: the product\'s own listing', checks_queued(), array( array( '110000000003' ) ) );

// No SKU on eBay: the line is looked up as EBAY-<item>, and failing that by
// the listing the product is linked to.
fresh_shop();
product( 104, '110000000004', 1, '110000000004' );
orders( array( sale( '10004', '110000000004', '' ) ) );
order_sync();
check( 'no SKU on the order line: found by its item number', checks_queued(), array( array( '110000000004' ) ) );
check( '  and marked handled', '' !== (string) meta( 104, '_ebay_order_processed_10004' ), true );

// Two orders for the same listing in one run.
fresh_shop();
product( 105, 'STAMP-5', 5, '110000000005' );
orders( array( sale( '10005', '110000000005', 'STAMP-5' ) ), array( sale( '10006', '110000000005', 'STAMP-5', 2 ) ) );
order_sync();
check( 'two sales of one listing: one check, not two', checks_queued(), array( array( '110000000005' ) ) );
check( '  both sales marked handled', '' !== (string) meta( 105, '_ebay_order_processed_10005' ) && '' !== (string) meta( 105, '_ebay_order_processed_10006' ), true );
check( '  nothing taken off by order sync', stock( 105 ), 5 );

echo "\nA PRODUCT WITH NO EBAY LINK IS STILL REDUCED, ONCE\n" . str_repeat( '=', 124 ) . "\n";

// Sold on an eBay listing the plugin does not manage. Nothing else will ever
// take it off, so order sync does - for a shop that has said Yes to
// "Reduce WooCommerce stock on eBay sale?".
fresh_shop( array( 'enable_order_sync' => '1' ) );
product( 201, 'WEB-1', 5 );
orders( array( sale( '20001', '120000000001', 'WEB-1', 2 ) ) );
order_sync();
check( 'stock reduced by the two sold', stock( 201 ), 3 );
check( '  a decrease, written once', stock_writes( 201 ), array( 'stock 201 decrease 2' ) );
check( '  with the push back to eBay held off', $UNGUARDED, array() );
check( '  and the hold released afterwards', TCGiant_Sync_Inventory::is_push_suppressed(), false );
check( '  marked handled', '' !== (string) meta( 201, '_ebay_order_processed_20001' ), true );
check( '  no stock check queued', $QUEUE, array() );

order_sync();
check( 'order sync again takes nothing more off', stock( 201 ), 3 );
check( '  still nothing queued', $QUEUE, array() );
check( '  and eBay never asked about the listing', $EBAY['get_items'], array() );

echo "\nAN ENDED LISTING IS SETTLED AS THE DELTA WOULD SETTLE IT\n" . str_repeat( '=', 124 ) . "\n";

check( 'the stock check exists', method_exists( $IMP, 'settle_item' ), true );

// One of one, sold: the listing ends the moment it sells.
fresh_shop();
product( 301, 'COIN-301', 1, '130000000001' );
listing( '130000000001', 'Completed', 1, 1 );
sale_check( '130000000001' );
check( 'Completed, 1 listed, 1 sold: marked Ended', meta( 301, '_ebay_listing_status' ), 'Ended' );
check( '  its end recorded', meta( 301, '_ebay_end_time' ), '2026-10-11T10:00:00.000Z' );
check( '  stock settled to 0', stock( 301 ), 0 );
check( '  out of stock', status( 301 ), 'outofstock' );
check( '  eBay\'s figures kept: 1 listed', meta( 301, '_ebay_quantity_listed' ), 1 );
check( '                        1 sold', meta( 301, '_ebay_quantity_sold' ), 1 );
check( '  then removed, as a sold-out listing is', trashed( 301 ), true );
check( '  settled first, removed after', before( 'stock 301 set 0', 'trash 301' ), true );
check( '  and said so, with eBay\'s 1 listed and 1 sold', logged( 'WC #301 moved to the trash (1 listed, 1 sold)' ), true );
check( '  one call to eBay', $EBAY['get_items'], array( '130000000001' ) );
check( '  nothing sent back to eBay', $UNGUARDED, array() );

// The same, for a shop that keeps sold products.
fresh_shop( array( 'remove_sold_products' => '0' ) );
product( 302, 'COIN-302', 1, '130000000002' );
listing( '130000000002', 'Completed', 1, 1 );
sale_check( '130000000002' );
check( 'with "Remove sold products" off: kept', trashed( 302 ), false );
check( '  still settled to 0', stock( 302 ), 0 );
check( '  saying why it was kept', logged( 'WC #302 sold out on eBay but was kept' ), true );
check( '  and what eBay said', logged( 'WC #302 marked ended, stock now 0 (eBay: 1 listed, 1 sold)' ), true );

// The coin shop: sold on the website, WooCommerce at 0; the plugin then ended
// the eBay listing, which eBay records as 1 listed and 0 sold. Settling wrote
// that 1 back and the coin went up for sale again.
fresh_shop();
product( 303, 'COIN-303', 0, '130000000003' );
listing( '130000000003', 'Ended', 1, 0 );
sale_check( '130000000003' );
check( 'ended unsold after a website sale: stays at 0, not back to 1', stock( 303 ), 0 );
check( '  still out of stock', status( 303 ), 'outofstock' );
check( '  marked Ended', meta( 303, '_ebay_listing_status' ), 'Ended' );
check( '  not removed - nothing sold on eBay', trashed( 303 ), false );
// Not offered on Stock Review either: with nothing in WooCommerce this is the
// website sale itself, and "use eBay's figure" would put the coin back.
check( '  eBay\'s 1 not offered as a figure to use', meta( 303, '_ebay_unsold_at_end' ), '' );

echo "\nA LIVE LISTING ONLY EVER LOWERS THE STOCK\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop();
product( 311, 'CARD-311', 4, '130000000011' );
listing( '130000000011', 'Active', 5, 2 );
sale_check( '130000000011' );
check( '5 listed, 2 sold, WooCommerce 4: lowered to 3', stock( 311 ), 3 );
check( '  in one write', stock_writes( 311 ), array( 'stock 311 set 3' ) );
check( '  not sent back to eBay', $UNGUARDED, array() );
check( '  still in stock', status( 311 ), 'instock' );
check( '  not marked ended', meta( 311, '_ebay_listing_status' ), '' );
check( '  and logged', logged( 'Sale check: eBay item 130000000011 - WC #311 stock now 3 (eBay: 5 listed, 2 sold).' ), true );

// The website sold two that eBay has not heard of. eBay's 3 is higher, and
// raising to it would sell stock the shop no longer has.
fresh_shop();
product( 312, 'CARD-312', 2, '130000000012' );
listing( '130000000012', 'Active', 5, 2 );
sale_check( '130000000012' );
check( '5 listed, 2 sold, WooCommerce 2: left at 2', stock( 312 ), 2 );
check( '  nothing written', stock_writes( 312 ), array() );
check( '  though eBay was asked', $EBAY['get_items'], array( '130000000012' ) );

// Out-of-stock control keeps a sold-out listing live at 0.
fresh_shop();
product( 313, 'CARD-313', 1, '130000000013' );
listing( '130000000013', 'Active', 3, 3 );
sale_check( '130000000013' );
check( 'a live listing with none left: 0', stock( 313 ), 0 );
check( '  out of stock', status( 313 ), 'outofstock' );
check( '  but kept, and not marked ended - the listing is live', ! trashed( 313 ) && '' === meta( 313, '_ebay_listing_status' ), true );
check( '  and the 0 not sent to eBay, which would end the listing', $UNGUARDED, array() );

// A variation listing (3.28.0). Its Quantity is the whole listing's, which only
// the parent takes - the import gives the parent the listing's total. Each
// variation is checked against its own figures: eBay sold one of A's three, and
// the website sold B's last one, which eBay has not heard of yet.
fresh_shop();
product( 321, 'SET-321', 4, '130000000021', array( 'type' => 'variable' ) );
product( 322, 'SET-321-A', 3, '', array( 'parent' => 321 ) );
product( 323, 'SET-321-B', 0, '', array( 'parent' => 321 ) );
listing( '130000000021', 'Active', 5, 2, array( 'Variations' => array( 'Variation' => array(
	array( 'SKU' => 'SET-321-A', 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '1' ) ),
	array( 'SKU' => 'SET-321-B', 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => '1' ) ),
) ) ) );
sale_check( '130000000021' );
check( 'a live listing with variations: the parent lowered to the listing\'s 3', stock( 321 ), 3 );
check( '  in one write', stock_writes( 321 ), array( 'stock 321 set 3' ) );
check( '  variation A, 3 listed and 1 sold on eBay: from 3 to 2', stock( 322 ), 2 );
check( '    in one write', stock_writes( 322 ), array( 'stock 322 set 2' ) );
check( '  variation B, sold out here: left at 0, not raised to eBay\'s 1', stock( 323 ), 0 );
check( '    nothing written to it', events_for( 323 ), array() );
check( '  none of it sent back to eBay', $UNGUARDED, array() );
check( '  the parent brought in line with its variations once, pushes held off', $SYNCED, array( array( 321, true ) ) );
check( '  the variation logged with its figures', logged( 'Sale check: eBay item 130000000021 - variations of WC #321 lowered to eBay\'s figures: #322 3 to 2.' ), true );
check( '  and the parent', logged( 'Sale check: eBay item 130000000021 - WC #321 stock now 3 (eBay: 5 listed, 2 sold).' ), true );
check( '  every eBay variation paired: no warning', logged( 'no matching variation' ), false );
check( '  not marked settled - the listing is live', meta( 321, '_ebay_variations_settled_item' ), '' );
check( '  one call to eBay', $EBAY['get_items'], array( '130000000021' ) );

// The same check again, nothing new on eBay.
$written = count( $EVENTS );
sale_check( '130000000021' );
check( 'checked again with nothing new on eBay: nothing written', array_slice( $EVENTS, $written ), array() );
check( '  the parent not brought in line again', count( $SYNCED ), 1 );

// The shop turned an imported variable product into a simple one, and it is
// still linked to the listing with variations. The listing's total is all it
// can take: there are no variations here to pair with eBay's, and none missing.
fresh_shop();
product( 324, 'SET-324', 4, '130000000024' );
listing( '130000000024', 'Active', 5, 2, array( 'Variations' => array( 'Variation' => array(
	array( 'SKU' => 'SET-324-A', 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '1' ) ),
	array( 'SKU' => 'SET-324-B', 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => '1' ) ),
) ) ) );
sale_check( '130000000024' );
check( 'a simple product on a listing with variations: lowered to the listing\'s 3', stock( 324 ), 3 );
check( '  in one write', stock_writes( 324 ), array( 'stock 324 set 3' ) );
check( '  no warning about variations it cannot have', logged( 'no matching variation' ), false );
check( '  nothing recorded as unmatched', meta( 324, '_ebay_unmatched_variations' ), '' );
check( '  and nothing to bring in line', $SYNCED, array() );

echo "\nWHEN EBAY CANNOT ANSWER, NOTHING CHANGES\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop();
product( 331, 'CARD-331', 4, '130000000031' );
$EBAY['items']['130000000031'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 45001 milliseconds' );
sale_check( '130000000031' );
check( 'GetItem fails: a warning naming the listing, product and reason', logged( 'Sale check: could not read eBay item 130000000031 for WC #331 (cURL error 28', 'warning' ), true );
check( '  stock unchanged', stock( 331 ), 4 );
check( '  nothing written at all', events_for( 331 ), array() );

fresh_shop();
product( 332, 'CARD-332', 4, '130000000032' );
$EBAY['items']['130000000032'] = array( 'Ack' => 'Success' );
sale_check( '130000000032' );
check( 'GetItem answers without the item: a warning', logged( 'could not read eBay item 130000000032 for WC #332 (eBay returned nothing)', 'warning' ), true );
check( '  nothing written at all', events_for( 332 ), array() );

echo "\nA LISTING NO LIVE PRODUCT IS LINKED TO\n" . str_repeat( '=', 124 ) . "\n";

// Since 3.28.0 eBay is asked once: the listing could be a relist of one a
// product follows (test-relist-adopt.php). This one names no listing it was
// relisted from, so that is all that happens.
fresh_shop();
product( 341, 'CARD-341', 1, '130000000041' );
listing( '130000000041', 'Completed', 1, 1 );
listing( '130000000099', 'Completed', 1, 1 );
sale_check( '130000000099' );
check( 'a listing no product is linked to: eBay asked once, about it', $EBAY['get_items'], array( '130000000099' ) );
check( '  nothing written', $EVENTS, array() );
check( '  nothing logged', $LOG, array() );
check( '  and the product linked to another listing untouched', stock( 341 ) . '/' . status( 341 ) . '/' . ( trashed( 341 ) ? 'trash' : 'live' ), '1/instock/live' );

fresh_shop();
product( 342, 'CARD-342', 1, '130000000042', array( 'post_status' => 'trash' ) );
listing( '130000000042', 'Completed', 1, 1 );
sale_check( '130000000042' );
check( 'a product already in the trash: eBay not asked', $EBAY['get_items'], array() );
check( '  nothing written', $EVENTS, array() );

echo "\nA SALE OF ONE VARIATION\n" . str_repeat( '=', 124 ) . "\n";

// A variable listing imported from eBay. The parent carries the link; its
// variations carry eBay's variation SKUs and no link of their own - and the
// delta sets each one's stock outright from eBay, sales already deducted.
// eBay: red 3 listed, 1 sold, so 2 left. Whichever of the delta and order sync
// reaches the sale first, the variation should end at eBay's 2.
//
// Until 3.28.0 order sync treated a variation as unlinked and took the sale off
// itself, so when the delta had counted it first red ended at 1. A variation's
// sale is its parent's listing's now: order sync queues the stock check for
// that listing, and the check lowers each variation to eBay's own figure.
foreach ( array( 'the delta counted it first' => 2, 'the delta behind' => 3 ) as $when => $before ) {
	fresh_shop( array( 'enable_order_sync' => '1' ) );
	product( 401, 'SET-401', null, '140000000001', array( 'type' => 'variable' ) );
	product( 402, 'SET-401-RED', $before, '', array( 'parent' => 401 ) );
	product( 403, 'SET-401-BLUE', 1, '', array( 'parent' => 401 ) );
	listing( '140000000001', 'Active', 5, 2, array( 'Variations' => array( 'Variation' => array(
		array( 'SKU' => 'SET-401-RED', 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '1' ) ),
		array( 'SKU' => 'SET-401-BLUE', 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => '1' ) ),
	) ) ) );
	orders( array( sale( '40001', '140000000001', 'SET-401-RED' ) ) );
	order_sync();
	check( sprintf( 'red sold, %s: a check for the parent\'s listing', $when ), checks_queued(), array( array( '140000000001' ) ) );
	check( '  order sync itself takes nothing off', stock_writes( 402 ), array() );
	check( '  the sale marked handled on the variation the SKU found', '' !== (string) meta( 402, '_ebay_order_processed_40001' ), true );
	run_queued();
	order_sync();
	check( sprintf( '  red ends at eBay\'s 2 (WooCommerce had %d)', $before ), stock( 402 ), 2 );
	check( '  blue, 1 left on eBay and 1 here, untouched', events_for( 403 ), array() );
	check( '  order sync again: nothing more queued', $QUEUE, array() );
	check( '  nothing sent back to eBay', $UNGUARDED, array() );
}

echo "\nAN ENDING ALREADY IN THE STOCK IS NOT APPLIED AGAIN\n" . str_repeat( '=', 124 ) . "\n";

// The sale check settled listing 130000000009 (1 listed, 1 sold) to 0. The shop
// restocked three. The delta, behind, reaches the same ending later: applying
// it again would take the three away.
fresh_shop();
product( 309, 'COIN-309', 3, '130000000009' );
update_post_meta( 309, '_ebay_stock_settled_at', '2026-10-12 10:00:00' );
update_post_meta( 309, '_ebay_stock_settled_item', '130000000009' );
listing( '130000000009', 'Completed', 1, 1 );
sale_check( '130000000009' );
check( 'a restock after the ending was settled is left alone', stock( 309 ), 3 );
check( '  and the product is still marked ended', meta( 309, '_ebay_listing_status' ), 'Ended' );
check( '  saying why the stock was left', logged( 'Sale check: eBay item 130000000009 is Completed' ) && logged( 'WC #309 marked ended; its sales were already in the stock, so the stock is left as it is.' ), true );

echo "\nEVERY CONNECTED SHOP GETS THE STOCK CHECK (3.27.1)\n" . str_repeat( '=', 124 ) . "\n";

// 3.27.0's stock check sat behind "Reduce WooCommerce stock on eBay sale?",
// which defaults to No - so most shops never ran it. Now the setting governs
// only products that are not linked to a listing, as its label always said.
foreach ( array( 'never saved' => array(), 'set to No' => array( 'enable_order_sync' => '0' ) ) as $how => $settings ) {
	fresh_shop( $settings );
	product( 501, 'COIN-501', 1, '150000000001' );
	product( 502, 'WEB-502', 5 );
	orders( array(
		sale( '50001', '150000000001', 'COIN-501' ),
		sale( '50002', '150000000099', 'WEB-502', 2 ),
	) );
	order_sync();
	check( sprintf( 'the setting %s: a linked sale is still checked against eBay', $how ), checks_queued(), array( array( '150000000001' ) ) );
	check( '  an unlinked product is left alone', stock( 502 ), 5 );
	check( '  and left unmarked, so saying Yes later still reduces it', (string) meta( 502, '_ebay_order_processed_50002' ), '' );
}

$cron_src = php_strip_whitespace( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-cron.php' );
$from     = strpos( $cron_src, 'public function sync_orders()' );
$body     = false !== $from ? substr( $cron_src, $from, 600 ) : '';
check( 'order sync runs for every connected shop', false !== strpos( $body, 'is_authenticated()' ), true );
check( '  and no longer stops at the old setting', false === strpos( $body, "enable_order_sync" ), true );

echo "\nSALES OF LISTINGS ON OTHER EBAY SITES LEAVE THE WEBSITE'S STOCK ALONE (3.27.5)\n" . str_repeat( '=', 124 ) . "\n";

// Rabbi Ent lists on eBay.com, and Web Interpret copies every listing onto
// eBay.co.uk, eBay.de and the rest with the same SKUs, keeping the copies'
// quantities in step with the eBay.com listing. With "Import listings from"
// set to eBay United States only, a sale on a copy must not change the
// website's stock itself: it shows up as a quantity change on the eBay.com
// listing, which is what the website follows.
$only_us = array( 'enable_order_sync' => '1', 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' );

/** A line of an order, listed on one eBay marketplace and bought on another. */
function sale_on( $listed_on, $line_item_id, $item_id, $sku, $quantity = 1, $bought_on = null ) {
	$line = sale( $line_item_id, $item_id, $sku, $quantity );
	if ( null !== $listed_on ) {
		$line['listingMarketplaceId'] = $listed_on;
	}
	$line['purchaseMarketplaceId'] = null !== $bought_on ? $bought_on : $listed_on;
	return $line;
}

// The shop's own product, not linked to eBay, and a variation of its eBay.com
// listing (variations carry no link of their own), both sharing SKUs with
// eBay.co.uk copies.
fresh_shop( $only_us );
product( 601, 'WEB-601', 5 );
product( 610, 'SET-610', null, '160000000610' );
product( 611, 'SET-610-RED', 4, '', array( 'parent' => 610 ) );
orders( array(
	sale_on( 'EBAY_GB', '60001', '170000000601', 'WEB-601', 2 ),
	sale_on( 'EBAY_GB', '60002', '170000000610', 'SET-610-RED' ),
) );
order_sync();
check( 'an eBay.co.uk sale with an unlinked product\'s SKU: stock unchanged', stock( 601 ), 5 );
check( '  nothing written to it', stock_writes( 601 ), array() );
check( '  and left unmarked, so going back to all sites still reduces it', (string) meta( 601, '_ebay_order_processed_60001' ), '' );
check( 'an eBay.co.uk sale with a variation\'s SKU: stock unchanged', stock( 611 ), 4 );
check( '  and left unmarked', (string) meta( 611, '_ebay_order_processed_60002' ), '' );
check( '  nothing queued', $QUEUE, array() );
check( 'the log counts them', logged( '; 2 sales of listings on other eBay sites left alone' ), true );

// The shop's own marketplace, eBay Motors (part of it), a line eBay gave no
// marketplace for, and an eBay.com listing a buyer on eBay.co.uk bought
// through international visibility: all reduced, as before.
fresh_shop( $only_us );
product( 621, 'WEB-621', 5 );
product( 622, 'WEB-622', 5 );
product( 623, 'WEB-623', 5 );
product( 624, 'WEB-624', 5 );
orders( array(
	sale_on( 'EBAY_US', '62001', '180000000621', 'WEB-621' ),
	sale_on( 'EBAY_MOTORS_US', '62002', '180000000622', 'WEB-622' ),
	sale_on( null, '62003', '180000000623', 'WEB-623' ),
	sale_on( 'EBAY_US', '62004', '180000000624', 'WEB-624', 1, 'EBAY_GB' ),
) );
order_sync();
check( 'eBay United States: reduced', stock( 621 ), 4 );
check( 'eBay Motors, part of eBay United States: reduced', stock( 622 ), 4 );
check( 'no listingMarketplaceId on the line: reduced, as before', stock( 623 ), 4 );
check( 'listed on eBay.com, bought from eBay.co.uk: reduced - the listing\'s site decides', stock( 624 ), 4 );
check( '  and no line says anything was left alone', logged( 'left alone' ), false );

// A product imported from an eBay.co.uk listing before the setting was chosen
// keeps syncing: its own listing's sale is still checked against eBay.
fresh_shop( $only_us );
product( 631, 'COIN-631', 2, '190000000631' );
listing( '190000000631', 'Active', 2, 1 );
orders( array( sale_on( 'EBAY_GB', '63001', '190000000631', 'COIN-631' ) ) );
order_sync();
check( 'a sale of a linked eBay.co.uk listing still queues its stock check', checks_queued(), array( array( '190000000631' ) ) );
run_queued();
check( '  which brings that product down to eBay\'s figure', stock( 631 ), 1 );

// Web Interpret: the eBay.com listing and seven copies, one SKU. The copies
// sell; the eBay.com product is linked to the eBay.com listing only.
fresh_shop( $only_us );
product( 641, 'ROBOT-ARM', 10, '200000000641' );
$copies = array( 'EBAY_GB', 'EBAY_DE', 'EBAY_FR', 'EBAY_IT', 'EBAY_AU', 'EBAY_CA', 'EBAY_ES' );
$lines  = array();
foreach ( $copies as $n => $mp ) {
	$lines[] = sale_on( $mp, (string) ( 64100 + $n ), (string) ( 200000000700 + $n ), 'ROBOT-ARM' );
}
orders( $lines );
order_sync();
run_queued();
check( 'seven sales on the seven copies: the eBay.com product\'s stock is unchanged', stock( 641 ), 10 );
check( '  nothing written to its stock', stock_writes( 641 ), array() );
check( '  and eBay asked nothing: no product follows any copy', $EBAY['get_items'], array() );

// All eBay sites, the default: an eBay.co.uk sale of an unlinked product's
// SKU is reduced exactly as in 3.27.4.
foreach ( array( 'never chosen' => array( 'enable_order_sync' => '1', 'marketplace' => 'EBAY_US' ), 'All eBay sites' => array( 'enable_order_sync' => '1', 'marketplace' => 'EBAY_US', 'import_sites' => 'all' ) ) as $how => $settings ) {
	fresh_shop( $settings );
	product( 651, 'WEB-651', 5 );
	orders( array( sale_on( 'EBAY_GB', '65001', '170000000651', 'WEB-651', 2 ) ) );
	order_sync();
	check( sprintf( 'import listings from: %s - an eBay.co.uk sale is reduced as before', $how ), stock( 651 ), 3 );
	check( '  and marked handled', '' !== (string) meta( 651, '_ebay_order_processed_65001' ), true );
}

// The line left unmarked is reduced once the shop goes back to all sites.
fresh_shop( $only_us );
product( 661, 'WEB-661', 5 );
orders( array( sale_on( 'EBAY_GB', '66001', '170000000661', 'WEB-661' ) ) );
order_sync();
$OPTIONS['tcgiant_sync_ebay_settings']['import_sites'] = 'all';
order_sync();
check( 'set back to All eBay sites: the sale left alone is reduced, once', stock( 661 ), 4 );
order_sync();
check( '  and not again', stock( 661 ), 4 );

echo "\nF3 A WEBSITE SALE'S PUSH ON ITS WAY, NO RECORD OF EBAY'S SALES: THE CHECK LEAVES THE STOCK TO THE PUSH (RELEASE FIX ROUND 1)\n" . str_repeat( '=', 124 ) . "\n";

// 5 on both; the website sells 1 (its push queued, so 4 here), and eBay sells
// 2 (3 left there). Lowered to eBay's 3, the stock had the website's sale
// and eBay's two counted from one figure that holds only eBay's: the push,
// which reads eBay itself, then took eBay's two off again.
fresh_shop();
product( 670, 'COIN-670', 4, '170000000670', array( 'meta' => array( '_ebay_push_pending' => array( 'sale-670' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) ) ) ) );
listing( '170000000670', 'Active', 5, 2 );
sale_check( '170000000670' );
check( 'a website sale\'s push on its way, no record: the stock is left as it is, for the push', stock( 670 ), 4 );
check( '  and said', logged( 'WC #670 left at its stock: a website sale of it is on its way to eBay, and its push takes off eBay\'s sales.' ), true );

// With a record, the sales since it are known: lowered as before.
fresh_shop();
product( 671, 'COIN-671', 4, '170000000671', array( 'meta' => array(
	'_ebay_push_pending' => array( 'sale-671' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) ),
	'_ebay_sold_seen'    => array( 'item' => '170000000671', 'sku' => '', 'sold' => 0 ),
) ) );
listing( '170000000671', 'Active', 5, 2 );
sale_check( '170000000671' );
check( '  with a record of eBay\'s sales: lowered to eBay\'s 3, as before', stock( 671 ), 3 );

// No sale on its way: lowered as before.
fresh_shop();
product( 672, 'COIN-672', 5, '170000000672', array( 'meta' => array( '_ebay_push_pending' => array( 'edit-672' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) ) ) ) );
listing( '170000000672', 'Active', 5, 2 );
sale_check( '170000000672' );
check( '  a push on its way that is not a sale\'s: lowered to eBay\'s 3, as before', stock( 672 ), 3 );

// N3 (release fix round 2): only the mark of a sale whose push never got
// there (30 days). Held back as a sale on its way, eBay's sales stayed on
// the website: now eBay's figure less that sale's unit, as the import does.
fresh_shop();
product( 673, 'COIN-673', 4, '170000000673', array( 'meta' => array(
	'_ebay_push_pending' => array( 'unsent' => time() + 30 * DAY_IN_SECONDS ),
	'_ebay_unsent_qty'   => array( 'sale-673' => 1 ),
) ) );
listing( '170000000673', 'Active', 5, 2 );
sale_check( '170000000673' );
check( 'N3 only a sale\'s unsent mark: eBay\'s 3 less its 1 unit - 2, not left at 4', array( stock( 673 ), logged( 'left at its stock' ) ), array( 2, false ) );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
