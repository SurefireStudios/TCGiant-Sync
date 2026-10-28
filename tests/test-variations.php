<?php
/**
 * Harness: an eBay variation listing is settled variation by variation.
 *
 * A variation listing sells each variation from its own stock: eBay counts
 * what each one listed and sold, and WooCommerce sells each variation whatever
 * its parent says. Until 3.28.0 the plugin settled such a listing on the parent
 * alone, from the whole listing's figures:
 *
 *  - order sync found a sold variation by its SKU, read the eBay link from the
 *    variation (only the parent has one), took it for a product of the shop's
 *    own and took the sale off - after the delta had already set it from eBay,
 *    so every variation sale was deducted twice;
 *  - the sale check returned as soon as it saw a listing had variations;
 *  - an ended listing lowered the parent and left a variation eBay had sold
 *    out in stock, so the website sold it again.
 *
 * Since 3.28.0 eBay's variations are paired with the shop's by one matcher,
 * TCGiant_Sync_Variations, and each one is lowered to what eBay says that
 * variation has left - never raised.
 *
 * Loaded for real: TCGiant_Sync_Runtime, TCGiant_Sync_Variations,
 * TCGiant_Sync_Inventory and TCGiant_Sync_Importer. WordPress, WooCommerce's
 * stock functions, Action Scheduler and eBay are played here, and a queued
 * check is run the way Action Scheduler runs one: through the hook the importer
 * registered for it.
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

$OPTIONS    = array();
$PRODUCTS   = array();
$QUEUE      = array();
$HOOKS      = array();
$LOG        = array();
$EVENTS     = array();
$UNGUARDED  = array();
$EBAY       = array();
$SYNCED     = array();
$TERM_ASKED = array();

// The shop's one global attribute: Colour, with its terms by slug.
$TAXONOMIES = array(
	'pa_colour' => array(
		'label' => 'Colour',
		'terms' => array( 'black' => 'Black', 'jet-black' => 'Jet Black', 'white' => 'White' ),
	),
);

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
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
	// WooCommerce keeps a product's stock in its _stock meta: here, the
	// product's own stock (release fix round 5).
	$row = $GLOBALS['PRODUCTS'][ (int) $id ] ?? null;
	if ( '_stock' === $key && is_array( $row ) && ! empty( $row['manage'] ) && 'parent' !== $row['manage'] ) {
		return $single ? $row['stock'] : array( $row['stock'] );
	}
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

class WC_Product {}

// WooCommerce: a product reads its row afresh each time, as a reloaded one does.
// A variation whose 'manage' is 'parent' takes its stock from its parent's, as
// WooCommerce's does when only the parent manages stock.
class Harness_Product extends WC_Product {
	private $id;
	public function __construct( $id ) { $this->id = (int) $id; }
	private function row() { return $GLOBALS['PRODUCTS'][ $this->id ]; }
	private function stock_row() { return $GLOBALS['PRODUCTS'][ $this->get_stock_managed_by_id() ]; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return (int) $this->row()['parent']; }
	public function get_stock_managed_by_id() { return 'parent' === $this->row()['manage'] ? $this->get_parent_id() : $this->id; }
	public function managing_stock() { return (bool) $this->stock_row()['manage']; }
	public function get_stock_quantity() { return $this->stock_row()['manage'] ? $this->stock_row()['stock'] : null; }
	public function get_stock_status() { return $this->stock_row()['status']; }
	public function is_in_stock() { return 'outofstock' !== $this->stock_row()['status']; }
	public function is_type( $type ) { return $type === $this->row()['type']; }
	public function get_status() { return $this->row()['post_status']; }
	public function get_meta( $key, $single = true ) { return $this->row()['meta'][ $key ] ?? ''; }
	/** A variation's attributes as WooCommerce keeps them: a global one by its pa_ key and term slug. */
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
function sanitize_title( $title ) { return trim( preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( trim( strip_tags( (string) $title ) ) ) ), '-' ); }
function taxonomy_exists( $taxonomy ) { return isset( $GLOBALS['TAXONOMIES'][ $taxonomy ] ); }
function wc_attribute_label( $name ) { return $GLOBALS['TAXONOMIES'][ $name ]['label'] ?? $name; }
function get_term_by( $field, $value, $taxonomy ) {
	$GLOBALS['TERM_ASKED'][] = $field;
	if ( 'slug' !== $field ) {
		return false;
	}
	$name = $GLOBALS['TAXONOMIES'][ $taxonomy ]['terms'][ $value ] ?? null;
	return null === $name ? false : (object) array( 'name' => $name, 'slug' => $value );
}
function esc_xml( $text ) { return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' ); }
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
	// A variation sharing its parent's stock: WooCommerce writes the parent's.
	if ( 'parent' === $GLOBALS['PRODUCTS'][ $id ]['manage'] ) {
		$id = (int) $GLOBALS['PRODUCTS'][ $id ]['parent'];
	}
	// The write fails outright (a database error).
	if ( in_array( $id, $GLOBALS['FAIL_STOCK'] ?? array(), true ) ) {
		return false;
	}
	// Another plugin holds this one's quantity: the write does not take.
	if ( in_array( $id, $GLOBALS['REFUSE_STOCK'] ?? array(), true ) ) {
		$GLOBALS['EVENTS'][] = sprintf( 'refused %d %s %d', $id, $operation, $quantity );
		return $GLOBALS['PRODUCTS'][ $id ]['stock'];
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
		// WooCommerce's data store, right after its stock SQL - unless this
		// WooCommerce fires nothing (a data store of another plugin's).
		if ( empty( $GLOBALS['NO_STOCK_ACTIONS'] ) ) {
			foreach ( $GLOBALS['HOOKS']['woocommerce_updated_product_stock'] ?? array() as $l ) {
				call_user_func( $l[0], (int) $id );
			}
		}
	}
	// Something in WooCommerce's own write - its save of the product -
	// fails after the stock is written.
	if ( ! empty( $GLOBALS['THROW_AFTER_STOCK'] ) ) {
		$GLOBALS['THROW_AFTER_STOCK'] = false;
		$GLOBALS['RF4_AT_THROW']      = meta( 902, '_ebay_sold_seen' )['sold'] ?? null;
		throw new RuntimeException( 'failed after the stock was written' );
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
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_orders( $limit = 50, $offset = 0 ) {
		return $GLOBALS['EBAY']['orders'];
	}
	public function get_item( $item_id ) {
		$GLOBALS['EBAY']['get_items'][] = (string) $item_id;
		return $GLOBALS['EBAY']['items'][ (string) $item_id ] ?? new WP_Error( 'api_error', 'The item could not be found.' );
	}
	public static function scalar_value( $raw ) { return TCGiant_Sync_Variations::scalar( $raw ); }
	// The sale check reads past the shared ceiling, as a sale's push does (3.28.0).
	public function with_stock_priority( callable $fn ) { return $fn(); }
	// eBay's own Timestamp on the latest answer (3.28.0 release fix round 2): the harness's eBay clock.
	public function ebay_time() { return (int) ( $GLOBALS['EBAY']['time'] ?? 0 ); }
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
// "Import listings from" (3.27.5), which the importer asks.
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-variations.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $ROOT . '/includes/class-tcgiant-sync-importer.php';

$IMP = TCGiant_Sync_Importer::instance();

// The delta's settle, which the delta and the stock check share.
$SETTLE_ENDED = new ReflectionMethod( 'TCGiant_Sync_Importer', 'settle_ended_listing' );
if ( PHP_VERSION_ID < 80100 ) {
	$SETTLE_ENDED->setAccessible( true );
}

// --- driving it --------------------------------------------------------------

/** A shop with nothing in it, no queue, and eBay with no orders. */
function fresh_shop( array $settings = array() ) {
	$GLOBALS['OPTIONS']    = $settings ? array( 'tcgiant_sync_ebay_settings' => $settings ) : array();
	$GLOBALS['PRODUCTS']   = array();
	$GLOBALS['QUEUE']      = array();
	$GLOBALS['LOG']        = array();
	$GLOBALS['EVENTS']     = array();
	$GLOBALS['UNGUARDED']  = array();
	$GLOBALS['SYNCED']     = array();
	$GLOBALS['TERM_ASKED'] = array();
	$GLOBALS['REFUSE_STOCK'] = array();
	$GLOBALS['EBAY']       = array( 'orders' => array( 'orders' => array() ), 'items' => array(), 'get_items' => array() );
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

/** A variable product, linked to its listing when given one. */
function variable( $id, $sku, $stock, $item_id = '', array $meta = array() ) {
	product( $id, $sku, $stock, $item_id, array( 'type' => 'variable', 'meta' => $meta ) );
}

/** One of its variations: attributes as WooCommerce keeps them. */
function variation( $id, $parent, $sku, $stock, array $attrs, array $meta = array(), $post_status = 'publish' ) {
	product( $id, $sku, $stock, '', array( 'parent' => $parent, 'attrs' => $attrs, 'meta' => $meta, 'post_status' => $post_status ) );
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

/** A variation listing: the item's own figures are the whole listing's. */
function variation_listing( $item_id, $status, $listed, $sold, array $variations ) {
	listing( $item_id, $status, $listed, $sold, array( 'Variations' => array( 'Variation' => $variations ) ) );
}

/** NameValueList as eBay sends it: one pair arrives as the node itself. */
function nvl( array $pairs ) {
	$out = array();
	foreach ( $pairs as $name => $value ) {
		$out[] = array( 'Name' => $name, 'Value' => $value );
	}
	return array( 'NameValueList' => 1 === count( $out ) ? $out[0] : $out );
}

/**
 * One eBay variation, as GetItem decodes it. Quantity is what it listed in
 * all - what is left plus what sold - and null leaves it out. The price
 * carries its currency, as a value with an attribute decodes.
 */
function ev( $sku, $listed, $sold, array $specifics = array() ) {
	$v = array(
		'StartPrice'    => array( '@attributes' => array( 'currencyID' => 'USD' ), '0' => '10.00' ),
		'SellingStatus' => array( 'QuantitySold' => (string) $sold ),
	);
	if ( null !== $listed ) {
		$v['Quantity'] = (string) $listed;
	}
	if ( '' !== $sku ) {
		$v['SKU'] = $sku;
	}
	if ( $specifics ) {
		$v['VariationSpecifics'] = nvl( $specifics );
	}
	return $v;
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
function sale_check( $item_id ) { $GLOBALS['IMP']->settle_item( $item_id ); }

/** The delta's settle of an ended listing, from the row the delta holds. */
function delta_settle( $product_id, array $row ) {
	return $GLOBALS['SETTLE_ENDED']->invoke( null, $product_id, $row, (string) $row['SellingStatus']['ListingStatus'], 'Delta' );
}

/** The delta's cached row for an ended variation listing: no variation figures. */
function compact_row( $item_id, $listed, $sold ) {
	return array(
		'ItemID'        => $item_id,
		'Quantity'      => (string) $listed,
		'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => (string) $sold ),
		'Variations'    => array( 'Variation' => array() ),
	);
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

/** Every quantity or stock status written, to any product. */
function stock_events() {
	return array_values( array_filter( $GLOBALS['EVENTS'], function ( $e ) { return 0 === strpos( $e, 'stock ' ) || 0 === strpos( $e, 'status ' ); } ) );
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

/** How many log lines say it. */
function times_logged( $needle, $level = null ) {
	$n = 0;
	foreach ( $GLOBALS['LOG'] as $entry ) {
		if ( false !== strpos( $entry[1], $needle ) && ( null === $level || $level === $entry[0] ) ) {
			$n++;
		}
	}
	return $n;
}

echo "\nEBAY'S VARIATIONS, AS GETITEM SENDS THEM\n" . str_repeat( '=', 124 ) . "\n";

// A trading-card "pick your card" listing, as GetItem decodes it. eBay's
// Variation.Quantity is the variation's total, sold included.
$ebay = TCGiant_Sync_Variations::from_item( array( 'Variations' => array( 'Variation' => array(
	ev( 'CARD-A', 3, 1, array( 'Card' => 'Pikachu' ) ),
	ev( '', 1, 2, array( 'Card' => 'Mew', 'Grade' => 'PSA 9' ) ),
	ev( 'CARD-C', null, 0, array( 'Card' => 'Eevee' ) ),
	array( 'Quantity' => '2', 'StartPrice' => '12.50', 'VariationSpecifics' => nvl( array( 'Colours' => array( 'Red', 'Blue' ) ) ) ),
) ) ) );
check( 'four variations read', count( $ebay ), 4 );
check( 'available is what it listed less what sold: 3 less 1', $ebay[0]['available'], 2 );
check( '  its own figures kept: listed 3, sold 1', array( $ebay[0]['listed'], $ebay[0]['sold'] ), array( 3, 1 ) );
check( '  eBay\'s SKU', $ebay[0]['sku'], 'CARD-A' );
check( '  its specifics, one pair sent as the node itself', $ebay[0]['specifics'], array( 'Card' => 'Pikachu' ) );
check( 'more sold than listed never reads below 0', $ebay[1]['available'], 0 );
check( '  no SKU on eBay: \'\'', $ebay[1]['sku'], '' );
check( '  two specifics, in eBay\'s order', $ebay[1]['specifics'], array( 'Card' => 'Mew', 'Grade' => 'PSA 9' ) );
check( 'no Quantity from eBay: nothing known to be available', array( $ebay[2]['listed'], $ebay[2]['available'] ), array( null, null ) );
check( 'no SellingStatus: none sold', array( $ebay[3]['sold'], $ebay[3]['available'] ), array( 0, 2 ) );
check( 'a specific with several values, joined as the import joins them', $ebay[3]['specifics'], array( 'Colours' => 'Red, Blue' ) );
check( 'a price with its currency attribute read as 10.00', $ebay[0]['start_price'], '10.00' );
check( 'a plain price read as it is', $ebay[3]['start_price'], '12.50' );

// One variation left on the listing: eBay sends the node, not a list of one.
$single = TCGiant_Sync_Variations::from_item( array( 'Variations' => array( 'Variation' => ev( '', 3, 1, array( 'Size' => 'L' ) ) ) ) );
check( 'a single <Variation> read as one variation', array( count( $single ), $single[0]['available'] ?? null, $single[0]['specifics'] ?? null ), array( 1, 2, array( 'Size' => 'L' ) ) );
check( 'a listing without variations: none', TCGiant_Sync_Variations::from_item( array( 'Quantity' => '3' ) ), array() );
check( 'the delta\'s marker, which carries no figures: none', TCGiant_Sync_Variations::from_item( array( 'Variations' => array( 'Variation' => array() ) ) ), array() );

check( 'a decoded text node read as its text', TCGiant_Sync_Variations::scalar( array( '#text' => ' 5 ' ) ), '5' );
check( 'a number read as a string', TCGiant_Sync_Variations::scalar( 7 ), '7' );
check( 'something that is no value at all: \'\'', TCGiant_Sync_Variations::scalar( array( 'currencyID' => 'USD' ) ), '' );

echo "\nA VARIATION'S NAME AND VALUE, COMPARED BOTH SIDES\n" . str_repeat( '=', 124 ) . "\n";

check( 'by label and value, in either order, any case', TCGiant_Sync_Variations::signature( array( 'Size' => ' l ', 'colour' => 'BLACK' ) ), 'colour=black|size=l' );
check( '  the same from eBay\'s side', TCGiant_Sync_Variations::signature( array( 'Colour' => 'Black', 'Size' => 'L' ) ), 'colour=black|size=l' );
check( 'a label with a space reads as WooCommerce writes its key', TCGiant_Sync_Variations::signature( array( 'Card Name' => 'Mew' ) ), 'card-name=mew' );
check( 'an "Any colour" variation identifies nothing', TCGiant_Sync_Variations::signature( array( 'Colour' => '' ) ), '' );
check( '  nor does one with any value left empty', TCGiant_Sync_Variations::signature( array( 'Colour' => 'Black', 'Size' => '' ) ), '' );
check( '  nor no specifics at all', TCGiant_Sync_Variations::signature( array() ), '' );

// What a variation of the shop's is called, in eBay's terms.
fresh_shop();
variable( 950, 'TEE', 3 );
variation( 951, 950, '', 1, array( 'pa_colour' => 'black' ) );
variation( 952, 950, '', 1, array( 'color' => 'Red' ) );
variation( 953, 950, '', 1, array( 'pa_colour' => 'black' ), array( '_ebay_variation_specifics' => array( 'Colour' => 'Jet Black' ) ) );
variation( 954, 950, '', 1, array( 'pa_colour' => 'no-such-term' ) );
check( 'a global attribute: its label and its term\'s name, not pa_colour=black', TCGiant_Sync_Variations::wc_specifics( wc_get_product( 951 ) ), array( 'Colour' => 'Black' ) );
check( '  the term looked up by its slug', $TERM_ASKED, array( 'slug' ) );
check( 'a custom attribute: as the variation keeps it', TCGiant_Sync_Variations::wc_specifics( wc_get_product( 952 ) ), array( 'color' => 'Red' ) );
check( 'what eBay called it at import, when recorded, comes first', TCGiant_Sync_Variations::wc_specifics( wc_get_product( 953 ) ), array( 'Colour' => 'Jet Black' ) );
check( 'a slug with no term left: the slug itself', TCGiant_Sync_Variations::wc_specifics( wc_get_product( 954 ) ), array( 'Colour' => 'no-such-term' ) );

// The XML a relist names a variation by, which eBay will not let be changed.
check( 'eBay\'s specifics as XML, escaped', TCGiant_Sync_Variations::specifics_xml( array( 'Card' => 'Charizard & Co <Holo>', 'Grade' => '"9"' ) ),
	'<VariationSpecifics><NameValueList><Name>Card</Name><Value>Charizard &amp; Co &lt;Holo&gt;</Value></NameValueList><NameValueList><Name>Grade</Name><Value>&quot;9&quot;</Value></NameValueList></VariationSpecifics>' );

echo "\nPAIRING EBAY'S VARIATIONS WITH THE SHOP'S\n" . str_repeat( '=', 124 ) . "\n";

// One variable product, imported from a listing of a dozen variations, and
// since edited by the shop: a SKU the import had to make unique (its colour
// since renamed from Blue to Navy), a global Colour attribute, a variation the
// import retired and made again, one whose SKU the shop changed - and whose old
// SKU it then gave a new Magenta - and an "Any colour" one.
fresh_shop();
variable( 900, 'P900', 6, '190000000001' );
variation( 901, 900, 'RED-S', 2, array( 'color' => 'Red' ) );
variation( 902, 900, 'X-900', 2, array( 'color' => 'Navy' ) );
variation( 903, 900, '', 2, array( 'pa_colour' => 'black' ) );
variation( 904, 900, '', 0, array( 'color' => 'Green' ), array(), 'private' );
variation( 905, 900, '', 1, array( 'color' => 'Green' ) );
variation( 906, 900, 'PINK-2', 1, array( 'color' => 'Pink' ), array( '_ebay_variation_sku' => 'PINK-1' ) );
variation( 907, 900, '', 1, array( 'color' => '' ) );
variation( 908, 900, '', 1, array( 'color' => 'Grey' ) );
variation( 909, 900, '', 1, array( 'color' => 'Grey' ) );
variation( 910, 900, '', 0, array( 'color' => 'Purple' ), array(), 'private' );
variation( 911, 900, 'GOLD-1', 1, array( 'color' => 'Gold' ), array(), 'trash' );
variation( 912, 900, 'PINK-1', 1, array( 'color' => 'Magenta' ) );
$ebay = TCGiant_Sync_Variations::from_item( array( 'Variations' => array( 'Variation' => array(
	ev( 'RED-S', 2, 0, array( 'Color' => 'Crimson' ) ),
	ev( 'X', 2, 0, array( 'Color' => 'Blue' ) ),
	ev( '', 2, 0, array( 'Colour' => 'Black' ) ),
	ev( '', 1, 0, array( 'Color' => 'Green' ) ),
	ev( 'PINK-1', 1, 0, array( 'Color' => 'Pink' ) ),
	ev( '', 1, 0, array( 'Color' => 'Orange' ) ),
	ev( '', 1, 0, array( 'Color' => '' ) ),
	ev( '', 2, 0, array( 'Color' => 'Grey' ) ),
	ev( '', 1, 0, array( 'Color' => 'Purple' ) ),
	ev( '', 1, 0, array( 'Color' => 'Red' ) ),
	ev( 'GOLD-1', 1, 0, array( 'Color' => 'Gold' ) ),
	ev( 'NOT-HERE', 1, 0, array( 'Color' => 'Navy' ) ),
	ev( '', 1, 0, array( 'Color' => 'Magenta' ) ),
) ) ) );
$m = TCGiant_Sync_Variations::match( wc_get_product( 900 ), $ebay );
check( 'by SKU, whatever eBay now calls it', $m['pairs'][0] ?? null, array( 901 ) );
check( 'by the SKU the import made unique (X-900), though renamed Navy here', $m['pairs'][1] ?? null, array( 902 ) );
check( 'no SKU: a global pa_colour \'black\' answers to eBay\'s Colour: Black', $m['pairs'][2] ?? null, array( 903 ) );
check( 'a retired (private) duplicate never wins over the live one', $m['pairs'][3] ?? null, array( 905 ) );
check( 'the eBay SKU recorded at import beats a SKU the shop edited', $m['pairs'][4] ?? null, array( 906 ) );
check( '  the variation the shop gave that old SKU since answers by its name', $m['pairs'][12] ?? null, array( 912 ) );
check( 'two published duplicates of one combination both answer for it', $m['pairs'][7] ?? null, array( 908, 909 ) );
check( 'only a retired one to be had: that one', $m['pairs'][8] ?? null, array( 910 ) );
check( 'a variation already claimed by SKU is not claimed again by name', isset( $m['pairs'][9] ), false );
check( 'a variation in the trash answers for nothing', isset( $m['pairs'][10] ), false );
check( 'an eBay SKU the shop has not got: tried by name, already claimed', isset( $m['pairs'][11] ), false );
check( 'unmatched: Orange (none here), "Any" (no value), Red, Gold and NOT-HERE', $m['unmatched'], array( 5, 6, 9, 10, 11 ) );
$claimed = array();
foreach ( $m['pairs'] as $ids ) {
	$claimed = array_merge( $claimed, $ids );
}
check( 'no variation of the shop\'s answers for two of eBay\'s', count( $claimed ), count( array_unique( $claimed ) ) );

// The parent's own SKU, which a SKU-less variation shows in the storefront.
// eBay's SKU for one variation happens to be it: no variation of the shop's
// may answer to it through its parent.
fresh_shop();
variable( 960, 'BUNDLE', 2, '190000000002' );
variation( 961, 960, '', 1, array( 'size' => 'S' ) );
variation( 962, 960, '', 1, array( 'size' => 'M' ) );
$m = TCGiant_Sync_Variations::match( wc_get_product( 960 ), TCGiant_Sync_Variations::from_item( array( 'Variations' => array( 'Variation' => array(
	ev( 'BUNDLE', 1, 0, array( 'Size' => 'XL' ) ),
	ev( '', 1, 0, array( 'Size' => 'M' ) ),
) ) ) ) );
check( 'eBay\'s SKU is the parent\'s: no SKU-less variation answers to it', $m['unmatched'], array( 0 ) );
check( '  the other still found by name', $m['pairs'][1] ?? null, array( 962 ) );

// An eBay SKU that is not the shop's falls back to the variation's name.
fresh_shop();
variable( 970, 'SHIRT', 2, '190000000003' );
variation( 971, 970, 'SHIRT-S-OLD', 1, array( 'size' => 'S' ) );
$m = TCGiant_Sync_Variations::match( wc_get_product( 970 ), TCGiant_Sync_Variations::from_item( array( 'Variations' => array( 'Variation' => ev( 'SHIRT-S-NEW', 1, 0, array( 'Size' => 'S' ) ) ) ) ) );
check( 'an eBay SKU the shop has not got: found by its name', $m['pairs'][0] ?? null, array( 971 ) );

echo "\nSETTLING ONE LISTING'S VARIATIONS\n" . str_repeat( '=', 124 ) . "\n";

// Only a variable product has variations to settle, and only eBay's own
// variation figures can settle them.
fresh_shop();
product( 980, 'COIN-980', 2, '195000000001' );
variable( 981, 'SET-981', 2, '195000000002' );
variation( 982, 981, 'SET-981-A', 2, array( 'card' => 'A' ) );
$item = array( 'ItemID' => '195000000002', 'Quantity' => '2', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '2' ) );
check( 'a simple product: nothing to settle', TCGiant_Sync_Inventory::settle_variations( 980, $item + array( 'Variations' => array( 'Variation' => array( ev( 'COIN-980', 1, 1 ) ) ) ), 'Test' ), null );
check( 'a variable product, eBay sent no variations: nothing to settle', TCGiant_Sync_Inventory::settle_variations( 981, $item, 'Test' ), null );
check( '  the delta\'s marker alone: nothing to settle', TCGiant_Sync_Inventory::settle_variations( 981, $item + array( 'Variations' => array( 'Variation' => array() ) ), 'Test' ), null );
check( '  nothing written, nothing marked', $EVENTS, array() );
check( '  and the variation still at 2', stock( 982 ), 2 );

// With eBay's figures it lowers, says what it did, and marks an ended listing.
$result = TCGiant_Sync_Inventory::settle_variations( 981, $item + array( 'Variations' => array( 'Variation' => array( ev( 'SET-981-A', 2, 2 ), ev( 'SET-981-B', 1, 0 ) ) ) ), 'Test' );
check( 'what it did: one paired, one lowered, B not on the shop, no write refused', $result, array( 'matched' => 1, 'lowered' => 1, 'unmatched' => array( 'SET-981-B' ), 'failed' => 0 ) );
check( '  A from 2 to 0, out of stock', stock( 982 ) . '/' . status( 982 ), '0/outofstock' );
check( '  with the push to eBay held off', $UNGUARDED, array() );
check( '  and the hold released afterwards', TCGiant_Sync_Inventory::is_push_suppressed(), false );
check( '  the ended listing\'s variations marked settled', meta( 981, '_ebay_variations_settled_item' ), '195000000002' );
check( '  as the importer asks', TCGiant_Sync_Inventory::variations_already_settled( 981, '195000000002' ), true );
check( '  for that listing, not another', TCGiant_Sync_Inventory::variations_already_settled( 981, '195000000099' ), false );
check( '  nor a listing with no number', TCGiant_Sync_Inventory::variations_already_settled( 981, '' ), false );

// A live listing is never marked: it may sell again before it ends. Nor is one
// whose status eBay did not say.
foreach ( array( 'live' => 'Active', 'of no stated status' => null ) as $what => $listing_status ) {
	fresh_shop();
	variable( 981, 'SET-981', 2, '195000000002' );
	variation( 982, 981, 'SET-981-A', 2, array( 'card' => 'A' ) );
	$row = array( 'ItemID' => '195000000002', 'Variations' => array( 'Variation' => array( ev( 'SET-981-A', 2, 1 ) ) ) );
	if ( null !== $listing_status ) {
		$row['SellingStatus'] = array( 'ListingStatus' => $listing_status );
	}
	TCGiant_Sync_Inventory::settle_variations( 981, $row, 'Test' );
	check( sprintf( 'a listing %s: A lowered to eBay\'s 1', $what ), stock( 982 ), 1 );
	check( '  but not marked settled', meta( 981, '_ebay_variations_settled_item' ), '' );
}

echo "\nA SALE OF ONE VARIATION IS TAKEN OFF ONCE\n" . str_repeat( '=', 124 ) . "\n";

// A set imported from eBay: the parent carries the link, its variations carry
// eBay's variation SKUs and no link of their own. eBay: red 3 listed, 1 sold,
// so 2 left. The shop has "Reduce WooCommerce stock on eBay sale?" on.
//
// Until 3.28.0 order sync read the link from the variation, found none, and
// took the sale off as though red were a product of the shop's own. When the
// delta had already set red from eBay (to 2), red ended at 1.
foreach ( array( 'the delta counted it first' => 2, 'the delta behind' => 3 ) as $when => $red ) {
	fresh_shop( array( 'enable_order_sync' => '1' ) );
	variable( 401, 'SET-401', 4, '140000000001' );
	variation( 402, 401, 'SET-401-RED', $red, array( 'color' => 'Red' ) );
	variation( 403, 401, 'SET-401-BLUE', 1, array( 'color' => 'Blue' ) );
	variation_listing( '140000000001', 'Active', 5, 2, array(
		ev( 'SET-401-RED', 3, 1, array( 'Color' => 'Red' ) ),
		ev( 'SET-401-BLUE', 2, 1, array( 'Color' => 'Blue' ) ),
	) );
	orders( array( sale( '40001', '140000000001', 'SET-401-RED' ) ) );
	order_sync();
	check( sprintf( 'red sold, %s: one check, for the listing that sold', $when ), checks_queued(), array( array( '140000000001' ) ) );
	check( '  order sync itself writes no stock', stock_events(), array() );
	check( '  the line marked handled on the variation the SKU found', '' !== (string) meta( 402, '_ebay_order_processed_40001' ), true );
	check( '  not on the parent', meta( 401, '_ebay_order_processed_40001' ), '' );
	check( '  counted as a linked sale, not a reduction', logged( 'Processed 0 new line items; 1 eBay sales of linked products queued for a stock check.' ), true );
	run_queued();
	order_sync();
	run_queued();
	check( sprintf( '  red ends at eBay\'s 2 (WooCommerce had %d)', $red ), stock( 402 ), 2 );
	check( '  blue, 1 here and 1 left on eBay, not raised', stock( 403 ), 1 );
	check( '    nothing written to it', stock_writes( 403 ), array() );
	check( '  the parent lowered to the listing\'s 3', stock( 401 ), 3 );
	check( '  order sync again queues no second check: eBay asked once', $EBAY['get_items'], array( '140000000001' ) );
	check( '  nothing sent back to eBay', $UNGUARDED, array() );
	check( 2 === $red ? '  nothing lowered among the variations: the parent not re-synced' : '  the parent re-synced once, pushes held off', $SYNCED, 2 === $red ? array() : array( array( 401, true ) ) );
}

// The same sale with no item number on the order line: the parent's own listing.
fresh_shop( array( 'enable_order_sync' => '1' ) );
variable( 401, 'SET-401', 4, '140000000001' );
variation( 402, 401, 'SET-401-RED', 3, array( 'color' => 'Red' ) );
orders( array( sale( '40002', '', 'SET-401-RED' ) ) );
order_sync();
check( 'no item number on the line: the parent\'s listing is checked', checks_queued(), array( array( '140000000001' ) ) );
check( '  nothing taken off', stock_writes( 402 ), array() );

echo "\nA SALE OF A VARIATION WITH NO SKU\n" . str_repeat( '=', 124 ) . "\n";

// Most "pick your card" listings carry no SKUs. The order line has none, so it
// is looked up by the listing's number, which finds the parent. The check then
// pairs red by its name and value.
fresh_shop( array( 'enable_order_sync' => '1' ) );
variable( 501, '150000000001', 5, '150000000001' );
variation( 502, 501, '', 3, array( 'color' => 'Red' ) );
variation( 503, 501, '', 1, array( 'color' => 'Blue' ) );
variation_listing( '150000000001', 'Active', 5, 2, array(
	ev( '', 3, 1, array( 'Color' => 'Red' ) ),
	ev( '', 2, 1, array( 'Color' => 'Blue' ) ),
) );
orders( array( sale( '50001', '150000000001', '' ) ) );
order_sync();
check( 'a SKU-less line: a check for the parent\'s listing', checks_queued(), array( array( '150000000001' ) ) );
check( '  marked handled on the parent', '' !== (string) meta( 501, '_ebay_order_processed_50001' ), true );
check( '  nothing taken off by order sync', array_merge( stock_writes( 501 ), stock_writes( 502 ), stock_writes( 503 ) ), array() );
run_queued();
check( 'red found by its name and value, lowered from 3 to 2', stock( 502 ), 2 );
check( '  in one write', stock_writes( 502 ), array( 'stock 502 set 2' ) );
check( 'blue, 1 here and 1 on eBay, untouched', stock_writes( 503 ), array() );
check( 'the parent lowered to the listing\'s 3', stock( 501 ), 3 );
check( 'WooCommerce told to re-sync the parent once, pushes held off', $SYNCED, array( array( 501, true ) ) );
check( 'the variation logged with its figures', logged( 'Sale check: eBay item 150000000001 - variations of WC #501 lowered to eBay\'s figures: #502 3 to 2.' ), true );
check( 'nothing sent back to eBay', $UNGUARDED, array() );

echo "\nA VARIATION SKU THE IMPORT MADE UNIQUE\n" . str_repeat( '=', 124 ) . "\n";

// The shop sells card X itself, as product 701 with SKU CARD-X, and also lists
// a set on eBay with a variation whose eBay SKU is CARD-X. The import had to
// make that variation's SKU unique: CARD-X-711. eBay's order line says CARD-X,
// which finds the shop's own 701 - a product on no listing.
//
// Until 3.28.0 701 was reduced (4 to 3) for a sale it never made, and the
// listing that sold got no check.
fresh_shop( array( 'enable_order_sync' => '1' ) );
product( 701, 'CARD-X', 4 );
variable( 711, '170000000001', 2, '170000000001' );
variation( 712, 711, 'CARD-X-711', 2, array( 'card' => 'X' ) );
variation( 713, 711, 'CARD-Y', 1, array( 'card' => 'Y' ) );
variation_listing( '170000000001', 'Active', 3, 1, array(
	ev( 'CARD-X', 2, 1, array( 'Card' => 'X' ) ),
	ev( 'CARD-Y', 1, 0, array( 'Card' => 'Y' ) ),
) );
orders( array( sale( '70001', '170000000001', 'CARD-X' ) ) );
order_sync();
check( 'the shop\'s own CARD-X, on no listing, is not reduced', stock( 701 ), 4 );
check( '  only the line marked on it, so a later pass cannot take the sale off it', array_map( function ( $e ) { return preg_replace( '/=.*$/', '', $e ); }, events_for( 701 ) ), array( 'meta 701 _ebay_order_processed_70001' ) );
check( 'the listing that sold gets the check', checks_queued(), array( array( '170000000001' ) ) );
check( '  the line marked handled on the listing\'s product', '' !== (string) meta( 711, '_ebay_order_processed_70001' ), true );
run_queued();
check( 'its variation X (CARD-X-711) comes down from 2 to 1', stock( 712 ), 1 );
check( '  Y untouched', stock_writes( 713 ), array() );
check( '  nothing sent back to eBay', $UNGUARDED, array() );
order_sync();
check( 'order sync again: nothing queued, 701 still 4', array( checks_queued(), stock( 701 ) ), array( array(), 4 ) );

// 3.27.0 already took that sale off the shop's own CARD-X, and marked the line
// there. After the upgrade the line is the listing's: 701 is not reduced a
// second time, and the listing gets a check, which only ever lowers.
fresh_shop( array( 'enable_order_sync' => '1' ) );
product( 701, 'CARD-X', 3, '', array( 'meta' => array( '_ebay_order_processed_70001' => '2026-10-10 09:00:00' ) ) );
variable( 711, '170000000001', 1, '170000000001' );
variation( 712, 711, 'CARD-X-711', 1, array( 'card' => 'X' ) );
variation_listing( '170000000001', 'Active', 2, 1, array( ev( 'CARD-X', 2, 1, array( 'Card' => 'X' ) ) ) );
orders( array( sale( '70001', '170000000001', 'CARD-X' ) ) );
order_sync();
check( 'a line 3.27.0 took off CARD-X: not taken off again', stock_writes( 701 ), array() );
check( '  the listing that sold checked instead', checks_queued(), array( array( '170000000001' ) ) );
run_queued();
check( '  which finds X already at eBay\'s 1: nothing written', stock_writes( 712 ), array() );

// The listing that sold has only a product in the trash: deleted on purpose,
// so nothing is moved to it, and the line stays the SKU's.
fresh_shop( array( 'enable_order_sync' => '1' ) );
product( 701, 'CARD-X', 4 );
variable( 721, '170000000002', 2, '170000000002' );
$PRODUCTS[721]['post_status'] = 'trash';
orders( array( sale( '70002', '170000000002', 'CARD-X' ) ) );
order_sync();
check( 'the listing\'s product is in the trash: no check queued', checks_queued(), array() );
check( '  the sale stays the SKU\'s: 701 reduced once, as before', stock_writes( 701 ), array( 'stock 701 decrease 1' ) );

echo "\nORDER SYNC: WHAT WAS RIGHT BEFORE STAYS RIGHT\n" . str_repeat( '=', 124 ) . "\n";

// Sold on an eBay listing the plugin does not manage. Nothing else will ever
// take it off, so order sync does - once.
fresh_shop( array( 'enable_order_sync' => '1' ) );
product( 201, 'WEB-1', 5 );
orders( array( sale( '20001', '120000000001', 'WEB-1', 2 ) ) );
order_sync();
order_sync();
check( 'a product on no listing: reduced by 2, once, over two runs', stock_writes( 201 ), array( 'stock 201 decrease 2' ) );
check( '  now 3', stock( 201 ), 3 );
check( '  with the push back to eBay held off', $UNGUARDED, array() );
check( '  and no check queued', $QUEUE, array() );

// A variation of a variable product the shop has never put on eBay, sold on a
// listing the plugin does not manage: still the shop's to reduce.
fresh_shop( array( 'enable_order_sync' => '1' ) );
variable( 801, 'TEE', 6 );
variation( 802, 801, 'TEE-RED', 5, array( 'color' => 'Red' ) );
orders( array( sale( '80001', '180000000099', 'TEE-RED' ) ) );
order_sync();
order_sync();
check( 'a variation of a product on no listing: reduced once', stock_writes( 802 ), array( 'stock 802 decrease 1' ) );
check( '  no check queued', $QUEUE, array() );

// 3.27.0 already took this sale off the variation and marked the line.
fresh_shop( array( 'enable_order_sync' => '1' ) );
variable( 401, 'SET-401', 4, '140000000001' );
variation( 402, 401, 'SET-401-RED', 2, array( 'color' => 'Red' ), array( '_ebay_order_processed_40001' => '2026-10-10 09:00:00' ) );
orders( array( sale( '40001', '140000000001', 'SET-401-RED' ) ) );
order_sync();
check( 'a line 3.27.0 already handled: not queued again', checks_queued(), array() );
check( '  nothing written to the variation', events_for( 402 ), array() );
check( '  nor to the parent', events_for( 401 ), array() );

echo "\nTHE SALE CHECK ON A LIVE VARIATION LISTING\n" . str_repeat( '=', 124 ) . "\n";

// A set of five on a live listing. eBay sold one A; the website sold B's
// second, which eBay has not heard of; eBay did not say C's quantity; D does
// not track stock; and eBay has a variation E the shop has no variation for.
fresh_shop();
variable( 321, 'SET-321', 6, '130000000021' );
variation( 322, 321, 'SET-321-A', 3, array( 'card' => 'A' ) );
variation( 323, 321, 'SET-321-B', 1, array( 'card' => 'B' ) );
variation( 324, 321, 'SET-321-C', 2, array( 'card' => 'C' ) );
variation( 325, 321, 'SET-321-D', null, array( 'card' => 'D' ) );
variation_listing( '130000000021', 'Active', 7, 2, array(
	ev( 'SET-321-A', 3, 1 ),
	ev( 'SET-321-B', 2, 0 ),
	ev( 'SET-321-C', null, 0 ),
	ev( 'SET-321-D', 1, 1 ),
	ev( 'SET-321-E', 1, 0 ),
) );
sale_check( '130000000021' );
check( 'A, 3 listed and 1 sold on eBay: from 3 to 2', stock( 322 ), 2 );
check( '  in one write', stock_writes( 322 ), array( 'stock 322 set 2' ) );
check( 'B, 1 here against eBay\'s 2: left at 1, never raised', stock( 323 ), 1 );
check( '  nothing written to it', events_for( 323 ), array() );
check( 'C, no Quantity from eBay: not touched', events_for( 324 ), array() );
check( 'D, no quantity tracked here, but eBay sold its only one: out of stock', events_for( 325 ), array( 'status 325 outofstock' ) );
check( 'the parent lowered to the listing\'s 5 (7 listed, 2 sold)', stock( 321 ), 5 );
check( 'E, which the shop has no variation for: one warning naming it', times_logged( 'Sale check: eBay item 130000000021 has variations with no matching variation on WC #321 (SET-321-E). Their stock was not checked.', 'warning' ), 1 );
check( '  recorded on the parent', meta( 321, '_ebay_unmatched_variations' ), array( 'SET-321-E' ) );
check( 'the variation lowered, logged with its figures', logged( 'Sale check: eBay item 130000000021 - variations of WC #321 lowered to eBay\'s figures: #322 3 to 2, #325 out of stock.' ), true );
check( 'the parent re-synced once, pushes held off', $SYNCED, array( array( 321, true ) ) );
check( 'nothing sent back to eBay', $UNGUARDED, array() );
check( 'not marked settled: the listing is live', meta( 321, '_ebay_variations_settled_item' ), '' );
check( 'one call to eBay', $EBAY['get_items'], array( '130000000021' ) );

// The same check again, nothing new on eBay: nothing written and nothing said.
$written = count( $EVENTS );
sale_check( '130000000021' );
check( 'checked again: nothing written', array_slice( $EVENTS, $written ), array() );
check( '  E not warned about a second time', times_logged( 'no matching variation', 'warning' ), 1 );
check( '  the parent not re-synced again', count( $SYNCED ), 1 );

// The shop adds E. The list is empty now, and the record goes.
variation( 326, 321, 'SET-321-E', 1, array( 'card' => 'E' ) );
sale_check( '130000000021' );
check( 'the shop adds E: the record of unmatched variations goes', meta( 321, '_ebay_unmatched_variations' ), '' );
check( '  without a warning', times_logged( 'no matching variation', 'warning' ), 1 );
check( '  E, 1 here and 1 on eBay, not written', events_for( 326 ), array() );

// eBay adds F, with no SKU, and G. The list has changed, so it is said again.
variation_listing( '130000000021', 'Active', 9, 2, array(
	ev( 'SET-321-A', 3, 1 ),
	ev( 'SET-321-E', 1, 0 ),
	ev( '', 1, 0, array( 'Card' => 'F' ) ),
	ev( 'SET-321-G', 1, 0 ),
) );
sale_check( '130000000021' );
check( 'eBay adds F and G: said again, F named by its specifics', times_logged( 'no matching variation on WC #321 (Card=F; SET-321-G)', 'warning' ), 1 );

// eBay sends the same variations in another order: the same list.
variation_listing( '130000000021', 'Active', 9, 2, array(
	ev( 'SET-321-G', 1, 0 ),
	ev( 'SET-321-A', 3, 1 ),
	ev( '', 1, 0, array( 'Card' => 'F' ) ),
	ev( 'SET-321-E', 1, 0 ),
) );
sale_check( '130000000021' );
check( '  the same variations in another order: not said again', times_logged( 'no matching variation', 'warning' ), 2 );

echo "\nAN ENDED VARIATION LISTING\n" . str_repeat( '=', 124 ) . "\n";

// The set ended: Black's only one sold, White's two did not. The shop keeps
// sold products. Until 3.28.0 only the parent went down (3 to 2), and Black
// stayed in stock at 1 for the website to sell again.
fresh_shop( array( 'remove_sold_products' => '0' ) );
variable( 601, 'SET-601', 3, '160000000001' );
variation( 602, 601, 'SET-601-BLK', 1, array( 'color' => 'Black' ) );
variation( 603, 601, 'SET-601-WHT', 2, array( 'color' => 'White' ) );
variation_listing( '160000000001', 'Completed', 3, 1, array(
	ev( 'SET-601-BLK', 1, 1, array( 'Color' => 'Black' ) ),
	ev( 'SET-601-WHT', 2, 0, array( 'Color' => 'White' ) ),
) );
sale_check( '160000000001' );
check( 'Black, sold out on eBay: 0 and out of stock', stock( 602 ) . '/' . status( 602 ), '0/outofstock' );
check( 'White, 2 unsold: left at 2', stock( 603 ), 2 );
check( '  nothing written to it', events_for( 603 ), array() );
check( 'the parent from 3 to 2', stock( 601 ), 2 );
check( 'the variations settled first, the parent after', before( 'stock 602 set 0', 'stock 601 set 2' ), true );
check( 'the listing\'s variations marked settled', meta( 601, '_ebay_variations_settled_item' ), '160000000001' );
check( '  and the parent, as before', meta( 601, '_ebay_stock_settled_item' ), '160000000001' );
check( 'the variation logged with its figures', logged( 'Sale check: eBay item 160000000001 - variations of WC #601 lowered to eBay\'s figures: #602 1 to 0.' ), true );
check( 'the parent logged as ended', logged( 'WC #601 marked ended, stock now 2 (eBay: 3 listed, 1 sold).' ), true );
check( 'kept: the shop keeps sold products', trashed( 601 ), false );
check( 'one call to eBay', $EBAY['get_items'], array( '160000000001' ) );
check( 'the parent re-synced once, pushes held off', $SYNCED, array( array( 601, true ) ) );
check( 'nothing sent back to eBay', $UNGUARDED, array() );

// The shop restocks Black to 4, and the same ending reaches the plugin again:
// applying it again would take the four away.
$PRODUCTS[602]['stock']  = 4;
$PRODUCTS[602]['status'] = 'instock';
$EVENTS                  = array();
sale_check( '160000000001' );
check( 'the same ending again after Black was restocked: Black stays 4', stock( 602 ), 4 );
check( '  no stock or status written to anything', stock_events(), array() );
check( '  no settle mark rewritten', preg_grep( '/_ebay_(stock|variations)_settled/', $EVENTS ), array() );
check( '  and said so', logged( 'WC #601 marked ended; its sales were already in the stock' ), true );

// A relist or a push starts a new listing: everything recorded about the old
// one's ending goes, the variations' too.
TCGiant_Sync_Inventory::clear_settled_mark( 601 );
check( 'a new listing clears the variations\' settle mark', meta( 601, '_ebay_variations_settled_item' ), '' );

// 3.27.0 settled this one already, on the parent only. Its variations were
// never settled: Black still holds a unit eBay sold. eBay also lists White,
// which the shop has no variation for.
fresh_shop( array( 'remove_sold_products' => '0' ) );
variable( 611, 'SET-611', 2, '160000000011', array( '_ebay_stock_settled_at' => '2026-10-12 10:00:00', '_ebay_stock_settled_item' => '160000000011' ) );
variation( 612, 611, 'B', 1, array( 'color' => 'Black' ) );
variation_listing( '160000000011', 'Completed', 3, 1, array(
	ev( 'B', 1, 1, array( 'Color' => 'Black' ) ),
	ev( 'W', 2, 0, array( 'Color' => 'White' ) ),
) );
sale_check( '160000000011' );
check( 'settled by 3.27.0 on the parent only: Black now settled to 0', stock( 612 ), 0 );
check( '  the parent not settled a second time', stock_writes( 611 ), array() );
check( '  nor its figures rewritten', meta( 611, '_ebay_stock_settled_at' ), '2026-10-12 10:00:00' );
check( '  the variations now marked settled', meta( 611, '_ebay_variations_settled_item' ), '160000000011' );
check( '  White, with no variation here: said once', times_logged( 'eBay item 160000000011 has variations with no matching variation on WC #611 (W)', 'warning' ), 1 );
check( '  and recorded', meta( 611, '_ebay_unmatched_variations' ), array( 'W' ) );
$PRODUCTS[612]['stock'] = 3;
sale_check( '160000000011' );
check( 'checked again after Black was restocked: Black stays 3', stock( 612 ), 3 );
check( '  White not said again', times_logged( 'no matching variation', 'warning' ), 1 );
TCGiant_Sync_Inventory::clear_settled_mark( 611 );
check( 'a new listing clears the record of unmatched variations too', meta( 611, '_ebay_unmatched_variations' ), '' );
check( '  and the parent\'s own mark', meta( 611, '_ebay_stock_settled_item' ), '' );

// The opposite: the variations were settled but the parent's settle did not
// take. The delta's row has the item's figures; the variations need no GetItem.
fresh_shop( array( 'remove_sold_products' => '0' ) );
variable( 651, 'SET-651', 3, '160000000051', array( '_ebay_variations_settled_item' => '160000000051' ) );
variation( 652, 651, 'E', 2, array( 'color' => 'Black' ) );
variation_listing( '160000000051', 'Completed', 3, 1, array( ev( 'E', 2, 2, array( 'Color' => 'Black' ) ) ) );
$used = delta_settle( 651, compact_row( '160000000051', 3, 1 ) );
check( 'variations already settled: eBay not asked for them', array( $used, $EBAY['get_items'] ), array( false, array() ) );
check( '  the parent settled from the row, 3 to 2', stock( 651 ), 2 );
check( '  the variation left as the shop has it since', stock( 652 ), 2 );

echo "\nAN ENDED VARIATION LISTING, FROM THE DELTA'S ROW\n" . str_repeat( '=', 124 ) . "\n";

// The delta keeps only a marker that a listing has variations, not their
// figures. Settling the variations needs the listing from eBay: one GetItem,
// counted as the delta's fallback.
fresh_shop( array( 'remove_sold_products' => '0' ) );
variable( 621, 'SET-621', 3, '160000000021' );
variation( 622, 621, 'B2', 1, array( 'color' => 'Black' ) );
variation_listing( '160000000021', 'Completed', 3, 1, array( ev( 'B2', 1, 1, array( 'Color' => 'Black' ) ) ) );
$used = delta_settle( 621, compact_row( '160000000021', 3, 1 ) );
check( 'the delta\'s row: eBay asked once, and the call reported', array( $used, $EBAY['get_items'] ), array( true, array( '160000000021' ) ) );
check( '  the variation settled from eBay\'s answer, 1 to 0', stock( 622 ), 0 );
check( '  the parent, 3 to 2', stock( 621 ), 2 );
check( '  both marked settled', array( meta( 621, '_ebay_variations_settled_item' ), meta( 621, '_ebay_stock_settled_item' ) ), array( '160000000021', '160000000021' ) );
check( '  logged as the delta\'s', logged( 'Delta: eBay item 160000000021 - variations of WC #621 lowered to eBay\'s figures: #622 1 to 0.' ), true );

// A row that already carries the variations (a full answer): no GetItem.
fresh_shop( array( 'remove_sold_products' => '0' ) );
variable( 661, 'SET-661', 3, '160000000061' );
variation( 662, 661, 'G', 1, array( 'color' => 'Black' ) );
$row = compact_row( '160000000061', 3, 1 );
$row['Variations'] = array( 'Variation' => array( ev( 'G', 1, 1, array( 'Color' => 'Black' ) ) ) );
$used = delta_settle( 661, $row );
check( 'a row with eBay\'s variations: not asked again', array( $used, $EBAY['get_items'] ), array( false, array() ) );
check( '  the variation settled from it', stock( 662 ), 0 );

// A coin: simple products take none of this. The row has its figures, so eBay
// is not asked, exactly as before.
fresh_shop( array( 'remove_sold_products' => '0' ) );
product( 671, 'COIN-671', 1, '160000000071' );
$row = compact_row( '160000000071', 1, 1 );
unset( $row['Variations'] );
$used = delta_settle( 671, $row );
check( 'a coin from the delta\'s row: eBay not asked', array( $used, $EBAY['get_items'] ), array( false, array() ) );
check( '  settled to 0', stock( 671 ), 0 );
check( '  no word about variations', logged( 'variations' ), false );

// A coin whose ending is already in the stock, reached again by the delta from
// a partial row: left alone without asking eBay, exactly as before.
fresh_shop( array( 'remove_sold_products' => '0' ) );
product( 672, 'COIN-672', 2, '160000000072', array( 'meta' => array( '_ebay_stock_settled_at' => '2026-10-12 10:00:00', '_ebay_stock_settled_item' => '160000000072' ) ) );
listing( '160000000072', 'Completed', 1, 1 );
$used = delta_settle( 672, array( 'ItemID' => '160000000072', 'SellingStatus' => array( 'ListingStatus' => 'Completed' ) ) );
check( 'a coin already settled, from a partial row: eBay not asked', array( $used, $EBAY['get_items'] ), array( false, array() ) );
check( '  its restock kept', stock( 672 ), 2 );
check( '  and said so', logged( 'Delta: eBay item 160000000072 is Completed — WC #672 marked ended; its sales were already in the stock' ), true );

// Sold out, and the shop removes sold products: the variation is settled
// before the parent goes to the trash.
fresh_shop();
variable( 631, 'SET-631', 1, '160000000031' );
variation( 632, 631, 'C', 1, array( 'color' => 'Black' ) );
variation_listing( '160000000031', 'Completed', 1, 1, array( ev( 'C', 1, 1, array( 'Color' => 'Black' ) ) ) );
sale_check( '160000000031' );
check( 'sold out, removal on: the parent goes to the trash', trashed( 631 ), true );
check( '  its variation settled to 0 first', before( 'stock 632 set 0', 'trash 631' ), true );
check( '  and marked settled first', before( 'meta 631 _ebay_variations_settled_item=160000000031', 'trash 631' ), true );

echo "\nWHEN EBAY DOES NOT REPORT THE VARIATIONS\n" . str_repeat( '=', 124 ) . "\n";

$ENDED_READ_ERROR = new ReflectionProperty( 'TCGiant_Sync_Importer', 'ended_read_error' );
if ( PHP_VERSION_ID < 80100 ) {
	$ENDED_READ_ERROR->setAccessible( true );
}
$ENDED_READ_ERROR->setValue( null, null );

// The delta's row, and GetItem fails. The parent is settled from the row as it
// always was; the variations wait, and the shop is told.
fresh_shop( array( 'remove_sold_products' => '0' ) );
variable( 641, 'SET-641', 3, '160000000041' );
variation( 642, 641, 'D', 1, array( 'color' => 'Black' ) );
$used = delta_settle( 641, compact_row( '160000000041', 3, 1 ) );
check( 'GetItem fails: the parent still settled from the row, 3 to 2', stock( 641 ), 2 );
check( '  eBay was asked, and failed: not counted as an answer', array( $used, $EBAY['get_items'] ), array( false, array( '160000000041' ) ) );
check( '  the variation untouched', events_for( 642 ), array() );
check( '  a warning says the variations were not settled, and why', logged( 'Delta: eBay item 160000000041 is Completed — its variations could not be read from eBay (The item could not be found.), so the variations of WC #641 are not settled yet.', 'warning' ), true );
check( '  an error about the listing itself is not one the delta stops for', $ENDED_READ_ERROR->getValue(), null );
check( '  and they are not marked settled', meta( 641, '_ebay_variations_settled_item' ), '' );

// The next pass, eBay answers: the variations are settled then, and the parent,
// already settled, is not settled again.
variation_listing( '160000000041', 'Completed', 3, 1, array( ev( 'D', 1, 1, array( 'Color' => 'Black' ) ) ) );
$PRODUCTS[641]['stock'] = 5;
$used = delta_settle( 641, compact_row( '160000000041', 3, 1 ) );
check( 'the next pass, eBay answers: the variation settled, 1 to 0', stock( 642 ), 0 );
check( '  the parent, restocked to 5 since, left alone', stock( 641 ), 5 );
check( '  the call reported', $used, true );

// GetItem answers, but with no variations: nothing to settle them from.
fresh_shop( array( 'remove_sold_products' => '0' ) );
variable( 681, 'SET-681', 3, '160000000081' );
variation( 682, 681, 'H', 1, array( 'color' => 'Black' ) );
listing( '160000000081', 'Completed', 3, 1 );
sale_check( '160000000081' );
check( 'eBay\'s answer has no variations: the parent settled, 3 to 2', stock( 681 ), 2 );
check( '  the variation untouched', events_for( 682 ), array() );
check( '  said', logged( 'Sale check: eBay item 160000000081 is Completed — eBay did not report its variations, so the variations of WC #681 were not settled.', 'warning' ), true );
check( '  not marked settled', meta( 681, '_ebay_variations_settled_item' ), '' );

// Out of calls when the delta asks for the variations. The delta - which
// never reads a finished window again - is handed the failure, and stops on
// the listing to come back to it, as for any listing it could not read.
fresh_shop( array( 'remove_sold_products' => '0' ) );
variable( 643, 'SET-643', 3, '160000000043' );
variation( 644, 643, 'E', 1, array( 'color' => 'Black' ) );
$EBAY['items']['160000000043'] = new WP_Error( 'rate_limited', 'The daily call allowance is used up.' );
$ENDED_READ_ERROR->setValue( null, null );
delta_settle( 643, compact_row( '160000000043', 3, 1 ) );
$handed = $ENDED_READ_ERROR->getValue();
check( 'out of calls: the delta is handed the failure to stop on', is_wp_error( $handed ) ? $handed->get_error_code() : $handed, 'rate_limited' );
check( '  the warning gives the real reason', logged( 'Delta: eBay item 160000000043 is Completed — its variations could not be read from eBay (The daily call allowance is used up.), so the variations of WC #643 are not settled yet.', 'warning' ), true );
check( '  the variation untouched, not marked settled', array( events_for( 644 ), meta( 643, '_ebay_variations_settled_item' ) ), array( array(), '' ) );
$ENDED_READ_ERROR->setValue( null, null );
$delta_code = file_get_contents( $ROOT . '/includes/class-tcgiant-sync-importer.php' );
check( '  and the delta\'s ended branch stops on it', 1 === preg_match( '/self::\$ended_read_error\s*=\s*null;\s*if\s*\(\s*\$existing_id[^;]*settle_ended_listing\([^;]*\)\s*\)\s*\{\s*\$fallback_count\+\+;\s*\}.*?if\s*\(\s*null\s*!==\s*self::\$ended_read_error\s*\)\s*\{\s*\$read_stop\s*=\s*self::delta_read_stop\(\s*self::\$ended_read_error/s', $delta_code ), true );

echo "\nA SKU ON A RETIRED VARIATION, AND ITS PUBLISHED TWIN\n" . str_repeat( '=', 124 ) . "\n";

// The shop took the old Red (#922) off the website - it is private now - and
// added a new Red (#923) with no SKU: that is the one the website sells. The
// old one still holds eBay's SKU R1. eBay's Red sells out. Matched by SKU
// alone, the retired Red was lowered, and the new one stayed for sale.
fresh_shop( array( 'remove_sold_products' => '0' ) );
variable( 921, 'SET-921', 4, '160000000092' );
variation( 922, 921, 'R1', 2, array( 'color' => 'Red' ), array(), 'private' );
variation( 923, 921, '', 2, array( 'color' => 'Red' ) );
variation( 924, 921, 'B1', 2, array( 'color' => 'Blue' ) );
variation_listing( '160000000092', 'Active', 4, 2, array( ev( 'R1', 2, 2, array( 'Color' => 'Red' ) ), ev( 'B1', 2, 0, array( 'Color' => 'Blue' ) ) ) );
sale_check( '160000000092' );
check( 'eBay\'s SKU names only a retired Red: the published Red is lowered, 2 to 0', array( stock( 923 ), stock( 922 ) ), array( 0, 2 ) );
check( '  Blue untouched', stock_writes( 924 ), array() );

// The retired Red's SKU, with a published Red holding it: that one is used,
// and the twin with no SKU is left alone.
fresh_shop( array( 'remove_sold_products' => '0' ) );
variable( 925, 'SET-925', 4, '160000000093' );
variation( 926, 925, 'R1', 2, array( 'color' => 'Red' ) );
variation( 927, 925, '', 2, array( 'color' => 'Red' ) );
variation_listing( '160000000093', 'Active', 2, 2, array( ev( 'R1', 2, 2, array( 'Color' => 'Red' ) ) ) );
sale_check( '160000000093' );
check( 'a published Red holds the SKU: it is the one lowered', array( stock( 926 ), stock( 927 ) ), array( 0, 2 ) );

echo "\nA VARIATION SHARING ITS PARENT'S STOCK\n" . str_repeat( '=', 124 ) . "\n";

// A tee the shop pushed from WooCommerce, its stock kept on the parent: 5,
// shared by Red and Blue. The push sent each colour the shared 5. eBay sells
// one Red while the delta is behind. 3.27.2 took it off the shared 5 in order
// sync, with the setting on, and so did 3.28.0 at first, setting or not. The
// stock check now takes it off that shared stock itself (settle_pool()), and
// so did every other read of eBay after order sync: the sale came off twice.
// Since 3.28.0's release fix round 1 order sync leaves it to the check.
fresh_shop();
variable( 901, 'TEE', 5, '190000000001' );
variation( 902, 901, 'TEE-RED', null, array( 'color' => 'Red' ) );
variation( 903, 901, 'TEE-BLU', null, array( 'color' => 'Blue' ) );
$PRODUCTS[902]['manage'] = 'parent';
$PRODUCTS[903]['manage'] = 'parent';
variation_listing( '190000000001', 'Active', 10, 1, array( ev( 'TEE-RED', 5, 1, array( 'Color' => 'Red' ) ), ev( 'TEE-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
orders( array( sale( '90001', '190000000001', 'TEE-RED' ) ) );
order_sync();
check( 'a sale of Red, which shares the tee\'s stock: order sync leaves the shared 5 to the stock check', stock( 901 ), 5 );
check( '  said', logged( 'eBay order of 1 of variation #902, whose stock WC #901 shares with its variations: its stock check takes it off the shared stock.' ), true );
check( '  nothing sent back to eBay', $UNGUARDED, array() );
check( '  the listing still gets its check', checks_queued(), array( array( '190000000001' ) ) );
run_queued();
check( 'the check takes it off the shared stock: 4', stock( 901 ), 4 );
order_sync();
check( 'order sync again: nothing more off, nothing queued', array( stock( 901 ), checks_queued() ), array( 4, array() ) );

// Once the delta has given each colour its own stock, a sale is the stock
// check's, as for any variation: order sync takes nothing off.
fresh_shop();
variable( 905, 'TEE2', 9, '190000000002' );
variation( 906, 905, 'TEE2-RED', 4, array( 'color' => 'Red' ) );
variation( 907, 905, 'TEE2-BLU', 5, array( 'color' => 'Blue' ) );
variation_listing( '190000000002', 'Active', 10, 2, array( ev( 'TEE2-RED', 5, 2, array( 'Color' => 'Red' ) ), ev( 'TEE2-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
orders( array( sale( '90002', '190000000002', 'TEE2-RED' ) ) );
order_sync();
check( 'colours with their own stock: order sync takes nothing off', array( stock( 906 ), stock( 905 ) ), array( 4, 9 ) );
run_queued();
check( '  the check brings Red to eBay\'s 3', stock( 906 ), 3 );

echo "\nA VARIATION WHOSE STOCK COULD NOT BE SET\n" . str_repeat( '=', 124 ) . "\n";

// Another plugin holds Black's quantity, and the delta settles the ended
// listing. Black stays 1, and is not marked settled; the shop is told to set
// it by hand, not that it is tried again - an ended listing is not.
fresh_shop( array( 'remove_sold_products' => '0' ) );
variable( 931, 'SET-931', 3, '160000000094' );
variation( 932, 931, 'K', 1, array( 'color' => 'Black' ) );
variation( 933, 931, 'W', 2, array( 'color' => 'White' ) );
variation_listing( '160000000094', 'Completed', 3, 1, array( ev( 'K', 1, 1, array( 'Color' => 'Black' ) ), ev( 'W', 2, 0, array( 'Color' => 'White' ) ) ) );
$REFUSE_STOCK = array( 932 );
delta_settle( 931, compact_row( '160000000094', 3, 1 ) );
check( 'Black\'s write refused: still 1, and in stock', array( stock( 932 ), $PRODUCTS[932]['status'] ), array( 1, 'instock' ) );
check( '  the variations not marked settled', meta( 931, '_ebay_variations_settled_item' ), '' );
check( '  the shop is told to set it by hand', logged( 'Delta: eBay item 160000000094 has ended, but the stock of variation(s) #932 of WC #931 could not be set to eBay\'s figures - something else on this site may be holding it - so they may still be for sale here. Set their stock by hand; the listing is not relisted or pushed until it can be set.', 'warning' ), true );
check( '  not promised a retry', count( array_filter( $LOG, function ( $l ) { return false !== strpos( $l[1], 'tried again' ); } ) ), 0 );
$REFUSE_STOCK = array();
delta_settle( 931, compact_row( '160000000094', 3, 1 ) );
check( 'the write allowed again: Black to 0, and marked settled', array( stock( 932 ), meta( 931, '_ebay_variations_settled_item' ) ), array( 0, '160000000094' ) );

// A live listing's sale check: refused, it is tried again at the next check.
fresh_shop( array( 'remove_sold_products' => '0' ) );
variable( 935, 'SET-935', 3, '160000000095' );
variation( 936, 935, 'K', 2, array( 'color' => 'Black' ) );
variation_listing( '160000000095', 'Active', 3, 1, array( ev( 'K', 2, 1, array( 'Color' => 'Black' ) ) ) );
$REFUSE_STOCK = array( 936 );
sale_check( '160000000095' );
check( 'a live listing\'s write refused: tried again at the next stock check', array( stock( 936 ), logged( 'Sale check: eBay item 160000000095 - the stock of variation(s) #936 of WC #935 could not be set to eBay\'s figures; they are tried again at the next stock check.', 'warning' ) ), array( 2, true ) );

echo "\nRELEASE FIX ROUND 1: AN EBAY SALE OF A SHARED VARIATION COMES OFF ONCE, WHICHEVER READS EBAY FIRST\n" . str_repeat( '=', 124 ) . "\n";

// F1: the tee again. Here a read of eBay - a stock check, the import, a
// website sale's push - comes before order sync fetches the order. It takes
// the sale off the shared stock; order sync, which cannot tell, took it off
// again before this fix.
foreach ( array( 'with records' => true, 'with no records' => false ) as $label => $records ) {
	fresh_shop();
	variable( 901, 'TEE', 5, '190000000001' );
	variation( 902, 901, 'TEE-RED', null, array( 'color' => 'Red' ), $records ? array( '_ebay_sold_seen' => array( 'item' => '190000000001', 'sku' => 'TEE-RED', 'sold' => 0 ) ) : array() );
	variation( 903, 901, 'TEE-BLU', null, array( 'color' => 'Blue' ), $records ? array( '_ebay_sold_seen' => array( 'item' => '190000000001', 'sku' => 'TEE-BLU', 'sold' => 0 ) ) : array() );
	$PRODUCTS[902]['manage'] = 'parent';
	$PRODUCTS[903]['manage'] = 'parent';
	variation_listing( '190000000001', 'Active', 10, 1, array( ev( 'TEE-RED', 5, 1, array( 'Color' => 'Red' ) ), ev( 'TEE-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
	sale_check( '190000000001' );
	$after_read = stock( 901 );
	orders( array( sale( '90001', '190000000001', 'TEE-RED' ) ) );
	order_sync();
	run_queued();
	order_sync();
	run_queued();
	check( 'F1 ' . $label . ': the check reads eBay before order sync - the shared 5 goes to 4 once; order sync and its own check take nothing more, nor a second run', array( $after_read, stock( 901 ) ), array( 4, 4 ) );
}

// F3: a variation keeping its own stock, a website sale of it on its way
// (its push queued: 4 here), and eBay sells 2 of it (3 left there). With no
// record, eBay's 3 does not say which sales the 4 has: the push sorts them out.
foreach ( array( 'no record: left for the push' => array( array(), 4 ), 'a record: lowered as before' => array( array( '_ebay_sold_seen' => array( 'item' => '190000000003', 'sku' => 'MUG-RED', 'sold' => 0 ) ), 3 ) ) as $label => $case ) {
	fresh_shop();
	variable( 911, 'MUG', 9, '190000000003' );
	variation( 912, 911, 'MUG-RED', 4, array( 'color' => 'Red' ), $case[0] + array( '_ebay_push_pending' => array( 'sale-912' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) ) ) );
	variation( 913, 911, 'MUG-BLU', 5, array( 'color' => 'Blue' ) );
	variation_listing( '190000000003', 'Active', 10, 2, array( ev( 'MUG-RED', 5, 2, array( 'Color' => 'Red' ) ), ev( 'MUG-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
	sale_check( '190000000003' );
	check( 'F3 a variation with its own stock and a website sale on its way, ' . $label, array( stock( 912 ), stock( 913 ) ), array( $case[1], 5 ) );
}


echo "\nRELEASE FIX ROUND 2: ORDER SYNC TAKES A SHARED VARIATION'S SALE OFF AT ONCE, WHEN EBAY'S CLOCK SAYS NO READ HAS SEEN IT\n" . str_repeat( '=', 124 ) . "\n";

// The tee: shared 5, Red and Blue. eBay's clock: a read at 09:00, the sale's
// order at 09:30 (or before the read).
$rf2_tee = function ( array $record_extra ) {
	fresh_shop();
	variable( 901, 'TEE', 5, '190000000001' );
	variation( 902, 901, 'TEE-RED', null, array( 'color' => 'Red' ), array( '_ebay_sold_seen' => array( 'item' => '190000000001', 'sku' => 'TEE-RED', 'sold' => 0 ) + $record_extra ) );
	variation( 903, 901, 'TEE-BLU', null, array( 'color' => 'Blue' ), array( '_ebay_sold_seen' => array( 'item' => '190000000001', 'sku' => 'TEE-BLU', 'sold' => 0 ) + $record_extra ) );
	$GLOBALS['PRODUCTS'][902]['manage'] = 'parent';
	$GLOBALS['PRODUCTS'][903]['manage'] = 'parent';
};
$rf2_read  = gmmktime( 9, 0, 0, 10, 20, 2026 );
$rf2_order = function ( $created, $line = '90001' ) {
	$GLOBALS['EBAY']['orders'] = array( 'orders' => array( array( 'orderId' => '27-13001-00000', 'creationDate' => null === $created ? null : gmdate( 'Y-m-d\TH:i:s.000\Z', $created ), 'lineItems' => array( sale( $line, '190000000001', 'TEE-RED' ) ) ) ) );
};

// The stamp: a read writes eBay's own Timestamp on the records it makes.
$rf2_tee( array() );
$EBAY['time'] = $rf2_read;
variation_listing( '190000000001', 'Active', 10, 0, array( ev( 'TEE-RED', 5, 0, array( 'Color' => 'Red' ) ), ev( 'TEE-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
sale_check( '190000000001' );
check( 'F1 a read stamps each variation\'s record with eBay\'s Timestamp on its answer, never the server\'s clock', array( meta( 902, '_ebay_sold_seen' )['at'] ?? null, meta( 903, '_ebay_sold_seen' )['at'] ?? null ), array( $rf2_read, $rf2_read ) );

// The sale after that read: taken off at once, the record absorbing it.
$EBAY['time'] = 0;
variation_listing( '190000000001', 'Active', 10, 1, array( ev( 'TEE-RED', 5, 1, array( 'Color' => 'Red' ) ), ev( 'TEE-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
$rf2_order( $rf2_read + 1800 );
order_sync();
check( '  an eBay sale of Red after that read: order sync takes it off the shared 5 at once (4), Red\'s record absorbs it (sold 1, its stamp kept), the check queued (the pool\'s push: test-stock-push)', array( stock( 901 ), meta( 902, '_ebay_sold_seen' )['sold'] ?? null, meta( 902, '_ebay_sold_seen' )['at'] ?? null, checks_queued() ), array( 4, 1, $rf2_read, array( array( '190000000001' ) ) ) );
check( '  said', logged( 'Reduced the stock WC #901 shares with its variations by 1 (eBay order of variation #902), and queued the push that tells eBay\'s other variations.', 'success' ), true );
$GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) { return 'tcgiant_sync_settle_item' === $a['hook']; } ) );
run_queued();
order_sync();
run_queued();
check( '  its check, reading eBay\'s 1 sold, takes nothing more; order sync again takes nothing more', stock( 901 ), 4 );

// The skip: a read since the order has seen it (its stamp is not older).
$rf2_tee( array( 'at' => $rf2_read ) );
$PRODUCTS[901]['stock'] = 4;
$PRODUCTS[902]['meta']['_ebay_sold_seen']['sold'] = 1;  // that read took it off
variation_listing( '190000000001', 'Active', 10, 1, array( ev( 'TEE-RED', 5, 1, array( 'Color' => 'Red' ) ), ev( 'TEE-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
$rf2_order( $rf2_read - 600 );
order_sync();
check( 'F1 a read at 09:00 has seen an order made at 08:50: order sync skips it - 4, the record as it was', array( stock( 901 ), meta( 902, '_ebay_sold_seen' )['sold'] ?? null ), array( 4, 1 ) );
$rf2_tee( array( 'at' => $rf2_read ) );
variation_listing( '190000000001', 'Active', 10, 1, array( ev( 'TEE-RED', 5, 1, array( 'Color' => 'Red' ) ), ev( 'TEE-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
$rf2_order( $rf2_read );
order_sync();
check( '  the same second as that read: skipped too, left to the check (5)', stock( 901 ), 5 );
run_queued();
check( '  which takes it off: 4', stock( 901 ), 4 );

// RF4-1: the sale check's settle of the shared stock, its write throwing in
// the save with no stock action at all: the records are written on the way
// out of the lowering, so the next read takes nothing more.
$rf2_tee( array( 'at' => $rf2_read ) );
variation_listing( '190000000001', 'Active', 10, 1, array( ev( 'TEE-RED', 5, 1, array( 'Color' => 'Red' ) ), ev( 'TEE-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
$GLOBALS['THROW_AFTER_STOCK'] = true;
$GLOBALS['NO_STOCK_ACTIONS']  = true;
$rf4_died = '';
try {
	sale_check( '190000000001' );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$GLOBALS['NO_STOCK_ACTIONS'] = false;
$rf4_mid = array( $rf4_died, stock( 901 ), meta( 902, '_ebay_sold_seen' )['sold'] ?? null );
sale_check( '190000000001' );
check( 'RF4-1 the sale check\'s settle of the shared stock, a throw in its save and no stock action: 4, Red\'s record written on the way out; the next read takes nothing more', array( $rf4_mid, stock( 901 ) ), array( array( 'failed after the stock was written', 4, 1 ), 4 ) );

// RF3-L2 (release fix round 3): eBay's Trading clock (the stamp) and its
// Fulfillment clock (creationDate) are not known to agree to the second.
// Order sync takes the sale only when the stamp is older by more than
// ORDER_CLOCK_MARGIN; inside it, the check does.
$rf3_margin = array();
foreach ( array( TCGiant_Sync_Inventory::ORDER_CLOCK_MARGIN - 1, TCGiant_Sync_Inventory::ORDER_CLOCK_MARGIN, TCGiant_Sync_Inventory::ORDER_CLOCK_MARGIN + 1 ) as $rf3_after ) {
	$rf2_tee( array( 'at' => $rf2_read ) );
	variation_listing( '190000000001', 'Active', 10, 1, array( ev( 'TEE-RED', 5, 1, array( 'Color' => 'Red' ) ), ev( 'TEE-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
	$rf2_order( $rf2_read + $rf3_after );
	order_sync();
	$rf3_margin[ $rf3_after ] = stock( 901 );
	run_queued();
	$rf3_margin[ $rf3_after ] .= '/' . stock( 901 );
}
check( 'RF3-L2 an order 299 s or 300 s after the read: left to the check (5, then 4); 301 s: order sync takes it (4, and the check nothing more)', $rf3_margin, array( 299 => '5/4', 300 => '5/4', 301 => '4/4' ) );

// No stamp (a record from before this fix), or no creationDate: left to the check.
foreach ( array( 'a record with no stamp' => array( array(), $rf2_read + 1800 ), 'an order with no creationDate' => array( array( 'at' => $rf2_read ), null ) ) as $label => $case ) {
	$rf2_tee( $case[0] );
	variation_listing( '190000000001', 'Active', 10, 1, array( ev( 'TEE-RED', 5, 1, array( 'Color' => 'Red' ) ), ev( 'TEE-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
	$rf2_order( $case[1] );
	order_sync();
	$after_sync = stock( 901 );
	run_queued();
	check( 'F1 ' . $label . ': order sync leaves it to the check (5), which takes it off (4)', array( $after_sync, stock( 901 ) ), array( 5, 4 ) );
}

// The stock write fails: the record is not touched, so the check still takes it.
$rf2_tee( array( 'at' => $rf2_read ) );
variation_listing( '190000000001', 'Active', 10, 1, array( ev( 'TEE-RED', 5, 1, array( 'Color' => 'Red' ) ), ev( 'TEE-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
$rf2_order( $rf2_read + 1800 );
$GLOBALS['FAIL_STOCK'] = array( 901 );
order_sync();
$GLOBALS['FAIL_STOCK'] = array();
$rf2_after = array( stock( 901 ), meta( 902, '_ebay_sold_seen' )['sold'] ?? null, logged( 'its stock check takes it off the shared stock.' ) );
$GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) { return 'tcgiant_sync_settle_item' === $a['hook']; } ) );
run_queued();
check( 'F1 the stock write fails: the record not absorbed (5, sold 0), said; the check takes it off once (4)', array( $rf2_after[0], $rf2_after[1], $rf2_after[2], stock( 901 ) ), array( 5, 0, true, 4 ) );

// RF3-L1 / RF4-2: the write throws in WooCommerce's save, once the stock is
// taken: the record was written right after the stock SQL, from its data
// store's action - or, with no such action, on the way out - so the check
// takes nothing more.
foreach ( array( 'from woocommerce_updated_product_stock' => false, 'with no action: on the way out' => true ) as $rf4_how => $rf4_quiet ) {
	$rf2_tee( array( 'at' => $rf2_read ) );
	variation_listing( '190000000001', 'Active', 10, 1, array( ev( 'TEE-RED', 5, 1, array( 'Color' => 'Red' ) ), ev( 'TEE-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
	$rf2_order( $rf2_read + 1800 );
	$GLOBALS['THROW_AFTER_STOCK'] = true;
	$GLOBALS['NO_STOCK_ACTIONS']  = $rf4_quiet;
	$GLOBALS['RF4_AT_THROW']      = null;
	$rf3_died = '';
	try {
		order_sync();
	} catch ( Throwable $e ) {
		$rf3_died = $e->getMessage();
	}
	$GLOBALS['NO_STOCK_ACTIONS'] = false;
	$rf3_after = array( $rf3_died, stock( 901 ), meta( 902, '_ebay_sold_seen' )['sold'] ?? null, $GLOBALS['RF4_AT_THROW'] );
	$GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) { return 'tcgiant_sync_settle_item' === $a['hook']; } ) );
	run_queued();
	check( 'RF3-L1 the stock write throws in its save, once the stock is taken: 4, Red\'s record absorbs it ' . $rf4_how . ', and its check takes nothing more', array( $rf3_after, stock( 901 ) ), array( array( 'failed after the stock was written', 4, 1, $rf4_quiet ? 0 : 1 ), 4 ) );
}
// Another plugin holds the shared stock back: nothing taken, nothing recorded.
$rf2_tee( array( 'at' => $rf2_read ) );
variation_listing( '190000000001', 'Active', 10, 1, array( ev( 'TEE-RED', 5, 1, array( 'Color' => 'Red' ) ), ev( 'TEE-BLU', 5, 0, array( 'Color' => 'Blue' ) ) ) );
$rf2_order( $rf2_read + 1800 );
$GLOBALS['REFUSE_STOCK'] = array( 901 );
order_sync();
$GLOBALS['REFUSE_STOCK'] = array();
check( '  another plugin holding the shared stock back: 5, the record untouched, left to the check', array( stock( 901 ), meta( 902, '_ebay_sold_seen' )['sold'] ?? null, logged( 'its stock check takes it off the shared stock.' ) ), array( 5, 0, true ) );
printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
