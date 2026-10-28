<?php
/**
 * Harness: a listing relisted on eBay stays one product.
 *
 * A coin shop relisted a coin on eBay. The relist has a new item number, and
 * nothing tied that number to the product that sold the old listing, so the
 * next sync imported it as a second product. The first stayed in stock, still
 * pointing at the listing that had ended: a website sale of it was sent to the
 * ended listing - "already ended, skipping" - and the relist stayed up, so the
 * one coin could sell twice. The SKU match that is meant to catch relists only
 * worked for a seller with SKUs of their own. Without one the SKU is the item
 * number, which no relist shares; and a barcode SKU is refused on purpose,
 * because a barcode does not identify a listing.
 *
 * eBay puts the old listing's number on the relist, as RelistParentID. 3.28.0
 * reads it, and moves the product that followed the old listing on to the new
 * one - when it is safe to, and with a line in the log saying why not when it
 * is not. The same move is made from the delta sync, for a relist that ended
 * or sold before the sync ever saw it live, and from the per-sale stock check.
 *
 * Sell Similar names the old listing on the copy too, so before any move eBay
 * is asked about the old listing: its own record names the listing it was
 * relisted as, and only that tells a relist from a copy. A seller's SKU or a
 * barcode that is not the product's names other goods, and is not moved on to
 * either. When eBay cannot be asked - out of calls, no token - nothing is
 * decided: the import saves nothing and the delta sync waits.
 *
 * Loaded for real: TCGiant_Sync_Mapper, TCGiant_Sync_Inventory,
 * TCGiant_Sync_Importer, TCGiant_Sync_Runtime and TCGiant_Sync_API. WordPress
 * and WooCommerce are played here: products are rows of post meta, and the
 * database answers the plugin's queries from them. eBay is the real API class
 * with its calls answered from a script; what reaches the HTTP layer is
 * recorded and refused. Nothing is copied from the plugin.
 *
 * The first section fails on 3.27.2 on purpose: the relist becomes a second
 * product, and the first keeps the ended listing.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-80s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$ROOT = dirname( __DIR__ );

// --- the shop the plugin runs in ---------------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$UPLOADS = sys_get_temp_dir() . '/tcg-relist-adopt-harness';
if ( ! is_dir( $UPLOADS ) ) {
	mkdir( $UPLOADS, 0777, true );
}

$POSTS      = array(); // id => array( 'type', 'status', 'product_type' )
$META       = array(); // id => array( key => value ): WooCommerce's own fields too
$OPTIONS    = array();
$TRANSIENTS = array();
$UNSET_T    = array(); // every delete_transient(), in order
$LOG        = array();
$ROWS       = array(); // TCGiant_Sync_DB::upsert() calls
$QUERIES    = array(); // every query the plugin sent the database
$CREATED    = array(); // products made by save()
$WRITES     = array(); // every post meta and stock write: id, key
$QUEUE      = array();
$HOOKS      = array();
$EBAY       = array();
$ORDERS     = array(); // website orders: id => WC_Order
$ORDER_LINE = array(); // order id => product id
$KEPT       = array(); // every value each option was given, in order
$NEXT_ID    = 900;

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
 * The database, as far as these paths ask it anything. Each query is answered
 * from the posts and their meta, by what its SQL says: which meta key and
 * value, which post types and statuses, which product left out, how many.
 */
class Harness_Wpdb {
	public $options  = 'wp_options';
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $prefix   = 'wp_';
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
	/** Posts whose meta matches the query's "meta_key = ... AND meta_value = ...". */
	private function matching( $query ) {
		if ( ! preg_match( "/meta_key = '([^']+)' AND (?:pm\\.)?meta_value = '([^']*)'/", $query, $m ) ) {
			return null;
		}
		$types = array( 'product', 'product_variation' );
		if ( false !== strpos( $query, "post_type = 'product'" ) ) {
			$types = array( 'product' );
		}
		$out = array();
		foreach ( $GLOBALS['POSTS'] as $id => $post ) {
			if ( (string) ( $GLOBALS['META'][ $id ][ $m[1] ] ?? "\0" ) !== $m[2] || ! in_array( $post['type'], $types, true ) ) {
				continue;
			}
			$status = $post['status'];
			if ( false !== strpos( $query, "post_status != 'trash'" ) && 'trash' === $status ) {
				continue;
			}
			if ( false !== strpos( $query, "NOT IN ( 'trash', 'auto-draft' )" ) && in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
				continue;
			}
			if ( false !== strpos( $query, "AND p.post_status = 'trash'" ) && 'trash' !== $status ) {
				continue;
			}
			if ( preg_match( '/post_id != (\d+)/', $query, $not ) && (int) $not[1] === (int) $id ) {
				continue;
			}
			$out[] = $id;
		}
		// Step 1 of the import puts a trashed holder last.
		if ( false !== strpos( $query, "( p.post_status = 'trash' ) ASC" ) ) {
			usort( $out, function ( $a, $b ) {
				return (int) ( 'trash' === $GLOBALS['POSTS'][ $a ]['status'] ) - (int) ( 'trash' === $GLOBALS['POSTS'][ $b ]['status'] );
			} );
		}
		if ( preg_match( '/LIMIT (\d+)/', $query, $limit ) ) {
			$out = array_slice( $out, 0, (int) $limit[1] );
		}
		return $out;
	}
	public function get_row( $query ) {
		$GLOBALS['QUERIES'][] = $query;
		$ids = $this->matching( $query );
		if ( empty( $ids ) ) {
			return null;
		}
		return (object) array( 'post_id' => (string) $ids[0], 'post_status' => $GLOBALS['POSTS'][ $ids[0] ]['status'] );
	}
	public function get_var( $query ) {
		// The stock checks' joins (3.28.0 fix round 3): read by name.
		if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '(tcgiant_sync_settle_joined_[^']+)' LIMIT 1$/", trim( $query ), $jm ) ) {
			return array_key_exists( $jm[1], $GLOBALS['OPTIONS'] ) ? (string) $GLOBALS['OPTIONS'][ $jm[1] ] : null;
		}
		$GLOBALS['QUERIES'][] = $query;
		$ids = $this->matching( $query );
		return empty( $ids ) ? null : (string) $ids[0];
	}
	public function get_col( $query ) {
		$GLOBALS['QUERIES'][] = $query;
		// The pre-3.27.0 settle: ended, in stock, settled, with no record of
		// which listing, and not yet looked at.
		if ( false !== strpos( $query, 'sitem.meta_id IS NULL' ) ) {
			$out = array();
			foreach ( $GLOBALS['POSTS'] as $id => $post ) {
				$m = $GLOBALS['META'][ $id ] ?? array();
				if ( 'product' === $post['type'] && ! in_array( $post['status'], array( 'trash', 'auto-draft' ), true )
					&& 'Ended' === ( $m['_ebay_listing_status'] ?? '' ) && 'instock' === ( $m['_stock_status'] ?? '' )
					&& '' !== (string) ( $m['_ebay_stock_settled_at'] ?? '' ) && ! isset( $m['_ebay_stock_settled_item'] )
					&& ! isset( $m['_ebay_restock_reviewed'] )
					&& ( ! preg_match( '/p\.ID = (\d+)/', $query, $only ) || (int) $only[1] === (int) $id ) ) {
					$out[] = (string) $id;
				}
			}
			return $out;
		}
		$ids = $this->matching( $query );
		return null === $ids ? array() : array_map( 'strval', $ids );
	}
	/** The website's order lines, for the products the query names. */
	public function get_results( $query, $output = null ) {
		$GLOBALS['QUERIES'][] = $query;
		if ( false === strpos( $query, 'woocommerce_order_items' ) || ! preg_match( '/IN \( ([^)]*) \)\s*ORDER BY/', $query, $in ) ) {
			return array();
		}
		$wanted = array_map( function ( $v ) { return (int) trim( $v, " '" ); }, explode( ',', $in[1] ) );
		$rows   = array();
		foreach ( $GLOBALS['ORDER_LINE'] as $order_id => $product_id ) {
			if ( in_array( (int) $product_id, $wanted, true ) ) {
				$rows[] = array( 'product_id' => (string) $product_id, 'order_id' => (string) $order_id );
			}
		}
		return $rows;
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
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_xml( $text ) { return htmlspecialchars( (string) $text, ENT_XML1, 'UTF-8' ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['HOOKS'][ $hook ][] = array( $callback, $accepted_args );
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function did_action( $hook ) { return 0; }
function wp_clear_scheduled_hook() {}
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) {
	$ts = strtotime( $date . ' UTC' );
	return 'U' === $format ? (string) $ts : gmdate( $format, $ts );
}
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_convert_hr_to_bytes( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$bytes = (int) $value;
	if ( false !== strpos( $value, 'g' ) ) {
		$bytes *= 1073741824;
	} elseif ( false !== strpos( $value, 'm' ) ) {
		$bytes *= 1048576;
	} elseif ( false !== strpos( $value, 'k' ) ) {
		$bytes *= 1024;
	}
	return min( $bytes, PHP_INT_MAX );
}
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['UPLOADS'] ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function sanitize_title( $title ) { return strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $title ) ); }
function wc_attribute_label( $name ) { return $name; }
function wp_set_object_terms() { return array(); }
function has_term() { return false; }

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['OPTIONS'][ $name ] = $value;
	$GLOBALS['KEPT'][ $name ][]  = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['OPTIONS'][ $name ] );
	return true;
}
function get_transient( $name ) { return $GLOBALS['TRANSIENTS'][ $name ] ?? false; }
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $name ] = $value; return true; }
function delete_transient( $name ) {
	$GLOBALS['UNSET_T'][] = $name;
	unset( $GLOBALS['TRANSIENTS'][ $name ] );
	return true;
}

// Posts and their meta: one store, read by WooCommerce and the plugin alike.
function get_post_status( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['status'] ?? false; }
function get_post_type( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['type'] ?? false; }
function wp_get_post_parent_id( $id ) { return 0; }
function get_post_meta( $id, $key = '', $single = false ) {
	$meta = $GLOBALS['META'][ (int) $id ] ?? array();
	if ( ! array_key_exists( $key, $meta ) ) {
		return $single ? '' : array();
	}
	return $single ? $meta[ $key ] : array( $meta[ $key ] );
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['WRITES'][] = array( (int) $id, $key );
	$GLOBALS['META'][ (int) $id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	if ( isset( $GLOBALS['META'][ (int) $id ][ $key ] ) ) {
		$GLOBALS['WRITES'][] = array( (int) $id, '-' . $key );
	}
	unset( $GLOBALS['META'][ (int) $id ][ $key ] );
	return true;
}
function wp_trash_post( $id ) {
	$GLOBALS['WRITES'][] = array( (int) $id, 'trash' );
	$GLOBALS['POSTS'][ (int) $id ]['status'] = 'trash';
	return true;
}
function get_post_thumbnail_id( $id ) { return 0; }

// WooCommerce's product. Its fields live in the same meta the plugin reads
// directly; a set_*() is held until save(), and save() keeps the stock status
// in line with a managed quantity, as WooCommerce's does.
class WC_Data_Exception extends Exception {}
class WC_Product {
	protected $id      = 0;
	protected $changes = array();
	protected $kind    = 'simple';
	public function __construct( $id = 0 ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function is_type( $type ) { return $type === $this->kind; }
	/** Order sync asks, since 3.28.0: a variation's listing is its parent's. */
	public function get_parent_id() { return (int) ( $GLOBALS['POSTS'][ $this->id ]['parent'] ?? 0 ); }
	protected function field( $key, $default = '' ) {
		if ( array_key_exists( $key, $this->changes ) ) {
			return $this->changes[ $key ];
		}
		return $GLOBALS['META'][ $this->id ][ $key ] ?? $default;
	}
	public function get_meta( $key, $single = true ) { return $this->field( $key ); }
	public function update_meta_data( $key, $value ) { $this->changes[ $key ] = $value; }
	public function get_sku() { return (string) $this->field( '_sku' ); }
	public function set_sku( $sku ) {
		$holder = wc_get_product_id_by_sku( $sku );
		if ( '' !== (string) $sku && $holder && $holder !== $this->id ) {
			throw new WC_Data_Exception( 'Invalid or duplicated SKU.' );
		}
		$this->changes['_sku'] = (string) $sku;
	}
	public function get_name() { return (string) $this->field( '_name' ); }
	public function set_name( $name ) { $this->changes['_name'] = $name; }
	public function set_description( $text ) { $this->changes['_description'] = $text; }
	public function managing_stock() { return 'yes' === $this->field( '_manage_stock', 'no' ); }
	public function set_manage_stock( $on ) { $this->changes['_manage_stock'] = $on ? 'yes' : 'no'; }
	public function get_stock_quantity() { return $this->managing_stock() ? (int) $this->field( '_stock', 0 ) : null; }
	public function set_stock_quantity( $quantity ) { $this->changes['_stock'] = (int) $quantity; }
	public function get_stock_status() { return (string) $this->field( '_stock_status', 'instock' ); }
	public function is_in_stock() { return 'outofstock' !== $this->get_stock_status(); }
	public function set_regular_price( $price ) { $this->changes['_regular_price'] = $price; }
	public function set_sale_price( $price ) { $this->changes['_sale_price'] = $price; }
	public function set_weight( $v ) { $this->changes['_weight'] = $v; }
	public function set_length( $v ) { $this->changes['_length'] = $v; }
	public function set_width( $v ) { $this->changes['_width'] = $v; }
	public function set_height( $v ) { $this->changes['_height'] = $v; }
	public function set_category_ids( $ids ) {}
	public function set_tag_ids( $ids ) {}
	public function set_status( $status ) { $this->changes['__status'] = $status; }
	public function get_attributes() { $a = $this->field( '_product_attributes', array() ); return is_array( $a ) ? $a : array(); }
	public function set_attributes( $attributes ) { $this->changes['_product_attributes'] = $attributes; }
	public function save() {
		if ( ! $this->id ) {
			$this->id = $GLOBALS['NEXT_ID']++;
			$GLOBALS['POSTS'][ $this->id ] = array( 'type' => 'product', 'status' => 'publish', 'product_type' => $this->kind );
			$GLOBALS['CREATED'][] = $this->id;
		}
		foreach ( $this->changes as $key => $value ) {
			if ( '__status' === $key ) {
				$GLOBALS['POSTS'][ $this->id ]['status'] = $value;
				continue;
			}
			$GLOBALS['WRITES'][] = array( $this->id, $key );
			$GLOBALS['META'][ $this->id ][ $key ] = $value;
		}
		$this->changes = array();
		if ( $this->managing_stock() ) {
			$GLOBALS['META'][ $this->id ]['_stock_status'] = $this->get_stock_quantity() > 0 ? 'instock' : 'outofstock';
		}
		return $this->id;
	}
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variable extends WC_Product {
	protected $kind = 'variable';
}
class WC_Product_Attribute {
	private $d = array( 'id' => 0, 'name' => '', 'options' => array(), 'position' => 0, 'visible' => true, 'variation' => false );
	public function set_id( $v ) { $this->d['id'] = $v; }
	public function set_name( $v ) { $this->d['name'] = $v; }
	public function set_options( $v ) { $this->d['options'] = $v; }
	public function set_position( $v ) { $this->d['position'] = $v; }
	public function set_visible( $v ) { $this->d['visible'] = $v; }
	public function set_variation( $v ) { $this->d['variation'] = $v; }
	public function get_name() { return $this->d['name']; }
	public function get_options() { return $this->d['options']; }
	public function get_position() { return $this->d['position']; }
	public function get_variation() { return $this->d['variation']; }
	public function is_taxonomy() { return false; }
}
function wc_get_product( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['POSTS'][ $id ] ) ) {
		return false;
	}
	return 'variable' === ( $GLOBALS['POSTS'][ $id ]['product_type'] ?? 'simple' ) ? new WC_Product_Variable( $id ) : new WC_Product_Simple( $id );
}
/** WooCommerce's own lookup leaves the trash out. */
function wc_get_product_id_by_sku( $sku ) {
	if ( '' === (string) $sku ) {
		return 0;
	}
	foreach ( $GLOBALS['POSTS'] as $id => $post ) {
		if ( 'trash' !== $post['status'] && (string) ( $GLOBALS['META'][ $id ]['_sku'] ?? '' ) === (string) $sku ) {
			return $id;
		}
	}
	return 0;
}
function wc_update_product_stock( $product, $quantity = null, $operation = 'set' ) {
	$id = is_object( $product ) ? $product->get_id() : (int) $product;
	if ( null === $quantity || 'yes' !== ( $GLOBALS['META'][ $id ]['_manage_stock'] ?? '' ) ) {
		return null;
	}
	// As WooCommerce's: 'increase' and 'decrease' move the stock by $quantity.
	$held = (int) ( $GLOBALS['META'][ $id ]['_stock'] ?? 0 );
	if ( 'decrease' === $operation ) {
		$quantity = $held - (int) $quantity;
	} elseif ( 'increase' === $operation ) {
		$quantity = $held + (int) $quantity;
	}
	$GLOBALS['WRITES'][] = array( $id, '_stock' );
	$GLOBALS['META'][ $id ]['_stock']        = (int) $quantity;
	$GLOBALS['META'][ $id ]['_stock_status'] = (int) $quantity > 0 ? 'instock' : 'outofstock';
	return (int) $quantity;
}
function wc_update_product_stock_status( $id, $status ) {
	$GLOBALS['WRITES'][] = array( (int) $id, '_stock_status' );
	$GLOBALS['META'][ (int) $id ]['_stock_status'] = $status;
}

// WooCommerce's orders, for the pre-3.27.0 restock check.
class Harness_Date {
	private $ts;
	public function __construct( $ts ) { $this->ts = $ts; }
	public function getTimestamp() { return $this->ts; }
}
class WC_Order {
	private $created;
	public function __construct( $created ) { $this->created = $created; }
	public function get_type() { return 'shop_order'; }
	public function get_status() { return 'completed'; }
	public function get_meta( $key ) { return ''; }
	public function get_date_created() { return new Harness_Date( $this->created ); }
	public function get_total() { return 89.99; }
	public function get_total_refunded() { return 0; }
}
function wc_get_order( $id ) { return $GLOBALS['ORDERS'][ (int) $id ] ?? false; }

// Action Scheduler, as the stock check and the delta use it.
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
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['QUEUE'] as $action ) {
		if ( $action['hook'] === $hook && ( null === $args || $action['args'] === $args ) ) {
			return true;
		}
	}
	return false;
}
function as_get_scheduled_actions( $args ) { return array(); }
function as_unschedule_all_actions( $hook ) {
	$GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) use ( $hook ) { return $a['hook'] !== $hook; } ) );
}
class ActionScheduler_Store {
	const STATUS_PENDING = 'pending';
}

// The plugin's neighbours.
class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function write_now( $message, $level = 'error' ) { self::log( $message, $level ); }
	public static function flush() {}
}
class TCGiant_Sync_OAuth {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_settings() { return get_option( 'tcgiant_sync_ebay_settings', array() ); }
	public function get_access_token() { return 'harness-token'; }
	public function refresh_access_token() { return false; }
}
class TCGiant_Sync_DB {
	public static function upsert( $data ) { $GLOBALS['ROWS'][] = $data; return true; }
}
class TCGiant_Sync_Entitlements {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function can_import() { return true; }
	public function get_active_product_count() { return 0; }
	public function get_free_limit() { return 0; }
}
class TCGiant_Sync_Image_Localizer {
	public static function set_external_images() {}
}

// The live shop. Since 3.28.0 the API class and the stock push ask
// TCGiant_Sync_Environment before anything that changes eBay; at a live
// address it lets everything through. Staging copies are
// test-staging-detect.php's.
function wp_load_alloptions() { return array( 'home' => 'https://numismax.com', 'siteurl' => 'https://numismax.com' ); }
function wp_get_environment_type() { return 'production'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
// "Import listings from" (3.27.5), which the mapper and the importer ask.
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
// Link's helper, which the mapper's re-link by SKU asks (3.27.5).
require_once $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require $ROOT . '/includes/class-tcgiant-sync-mapper.php';
require $ROOT . '/includes/class-tcgiant-sync-importer.php';

/**
 * eBay: the real API class, with GetItem, GetSellerEvents and the day's orders
 * answered from a script and recorded. Anything else that would have gone to
 * eBay is recorded at the HTTP layer's door, and answered with a bare Success.
 */
class Scripted_eBay extends TCGiant_Sync_API {
	public function get_item( $item_id ) {
		$GLOBALS['EBAY']['get_items'][] = (string) $item_id;
		return $GLOBALS['EBAY']['items'][ (string) $item_id ] ?? new WP_Error( 'api_error', 'The item could not be found.' );
	}
	public function get_seller_events( $from, $to, $max_body_bytes = 0 ) {
		$GLOBALS['EBAY']['events_read'][] = array( $from, $to );
		return array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $GLOBALS['EBAY']['events'] ) );
	}
	public function get_official_time() { return array( 'Ack' => 'Success' ); }
	public function get_orders( $limit = 50, $offset = 0 ) { return $GLOBALS['EBAY']['orders']; }
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$GLOBALS['EBAY']['wire'][] = array( $call_name, $xml_body );
		return array( 'Ack' => 'Success' );
	}
}
$slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$slot->setAccessible( true );
}
$slot->setValue( null, new Scripted_eBay() );

$MAPPER = TCGiant_Sync_Mapper::instance();
$IMP    = TCGiant_Sync_Importer::instance();
$INV    = TCGiant_Sync_Inventory::instance();

// --- driving it --------------------------------------------------------------

// Three listings of one coin, as eBay numbers them: the one the shop imported,
// its relist, and a relist of that.
$OLD   = '110000000111';
$NEW   = '220000000222';
$THIRD = '330000000333';

const SETTLE_KEYS = array( '_ebay_stock_settled_at', '_ebay_stock_settled_item', '_ebay_unsold_at_end', '_ebay_quantity_listed', '_ebay_quantity_sold', '_ebay_restock_reviewed' );

/** A shop with nothing in it, and an eBay that has not been asked anything. */
function fresh_shop( array $settings = array() ) {
	$GLOBALS['POSTS']      = array();
	$GLOBALS['META']       = array();
	// Connected to eBay, as the OAuth stub's token says: a push is queued only
	// for a shop that holds both tokens (3.28.0).
	$GLOBALS['OPTIONS']    = array( 'tcgiant_sync_ebay_settings' => $settings + array( 'access_token' => 'harness-access', 'refresh_token' => 'harness-refresh' ) );
	$GLOBALS['TRANSIENTS'] = array();
	$GLOBALS['UNSET_T']    = array();
	$GLOBALS['LOG']        = array();
	$GLOBALS['ROWS']       = array();
	$GLOBALS['QUERIES']    = array();
	$GLOBALS['CREATED']    = array();
	$GLOBALS['WRITES']     = array();
	$GLOBALS['QUEUE']      = array();
	$GLOBALS['ORDERS']     = array();
	$GLOBALS['ORDER_LINE'] = array();
	$GLOBALS['KEPT']       = array();
	$GLOBALS['EBAY']       = array( 'items' => array(), 'get_items' => array(), 'events' => array(), 'events_read' => array(), 'orders' => array( 'orders' => array() ), 'wire' => array() );
}

/** A product that came from eBay listing $item_id, its SKU the item number. */
function coin( $id, $item_id, array $meta = array(), $status = 'publish' ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => 'product', 'status' => $status, 'product_type' => 'simple' );
	$GLOBALS['META'][ $id ]  = array_merge( array(
		'_name'                => 'Coin #' . $id,
		'_sku'                 => $item_id,
		'_ebay_sku'            => $item_id,
		'_ebay_item_id'        => $item_id,
		'_ebay_listing_status' => 'Ended',
		'_ebay_start_time'     => '2026-09-01T10:00:00.000Z',
		'_ebay_end_time'       => '2026-10-01T10:00:00.000Z',
		'_manage_stock'        => 'yes',
		'_stock'               => 1,
		'_stock_status'        => 'instock',
	), $meta );
}

/** What the plugin records once a listing's ending is in the stock. */
function settled( $id, $item_id, $listed, $sold ) {
	$GLOBALS['META'][ $id ]['_ebay_stock_settled_at']   = '2026-10-02 10:00:00';
	$GLOBALS['META'][ $id ]['_ebay_stock_settled_item'] = $item_id;
	$GLOBALS['META'][ $id ]['_ebay_quantity_listed']    = $listed;
	$GLOBALS['META'][ $id ]['_ebay_quantity_sold']      = $sold;
}

/** A listing as GetItem returns it - every figure a string. */
function listing( $item_id, array $over = array() ) {
	return array_replace_recursive( array(
		'ItemID'          => $item_id,
		'Title'           => '1921 Morgan Dollar MS63',
		'Quantity'        => '1',
		'ListingType'     => 'FixedPriceItem',
		'ListingDuration' => 'GTC',
		'SellingStatus'   => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0', 'CurrentPrice' => '89.99' ),
		'ListingDetails'  => array( 'StartTime' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 1800 ), 'EndTime' => '2026-12-31T10:00:00.000Z' ),
		'PictureDetails'  => array( 'PictureURL' => 'https://i.ebayimg.com/' . $item_id . '.jpg' ),
	), $over );
}

/** eBay's answer to GetItem for one listing. */
function ebay_has( $item_id, $item ) {
	$GLOBALS['EBAY']['items'][ $item_id ] = is_wp_error( $item ) ? $item : array( 'Ack' => 'Success', 'Item' => $item );
}

/**
 * eBay's answer for an old listing that ended, $listed listed and $sold sold,
 * and that the seller relisted as $relist. Since 3.28.0 eBay is asked this
 * for every relist before a product is moved on to it: only the old listing's
 * own record names the listing it was relisted as, and that is what tells a
 * relist from a Sell Similar copy of an ended listing.
 */
function relisted( $item_id, $relist, $listed = 1, $sold = 0 ) {
	ebay_has( $item_id, listing( $item_id, array(
		'Quantity'       => (string) $listed,
		'SellingStatus'  => array( 'ListingStatus' => 'Completed', 'QuantitySold' => (string) $sold ),
		'ListingDetails' => array( 'RelistedItemID' => $relist ),
	) ) );
}

/** Import one listing as every import path does: map it, then save it. */
function import( array $item ) {
	$data = $GLOBALS['MAPPER']->map_ebay_to_woo( $item );
	return $GLOBALS['MAPPER']->save_as_product( $data );
}

function meta( $id, $key ) { return $GLOBALS['META'][ $id ][ $key ] ?? null; }
function stock( $id ) { return isset( $GLOBALS['META'][ $id ]['_stock'] ) ? (int) $GLOBALS['META'][ $id ]['_stock'] : null; }
function trashed( $id ) { return 'trash' === ( $GLOBALS['POSTS'][ $id ]['status'] ?? '' ); }
function settle_keys_left( $id ) {
	return array_values( array_filter( SETTLE_KEYS, function ( $key ) use ( $id ) { return isset( $GLOBALS['META'][ $id ][ $key ] ); } ) );
}
function last_row() { return $GLOBALS['ROWS'] ? end( $GLOBALS['ROWS'] ) : array(); }
function logged( $needle, $level = null ) {
	foreach ( $GLOBALS['LOG'] as $line ) {
		if ( false !== strpos( $line[1], $needle ) && ( null === $level || $level === $line[0] ) ) {
			return true;
		}
	}
	return false;
}
function log_lines_naming( $needle ) {
	return array_values( array_filter( $GLOBALS['LOG'], function ( $line ) use ( $needle ) { return false !== strpos( $line[1], $needle ); } ) );
}
/** Writes to products other than the ones named. */
function writes_except( array $ids ) {
	return array_values( array_filter( $GLOBALS['WRITES'], function ( $w ) use ( $ids ) { return ! in_array( $w[0], $ids, true ); } ) );
}
function writes_to( $id ) {
	return array_values( array_filter( $GLOBALS['WRITES'], function ( $w ) use ( $id ) { return $w[0] === $id; } ) );
}
/** The product the import made for a listing, if it made one. */
function created_for( $item_id ) {
	foreach ( $GLOBALS['CREATED'] as $id ) {
		if ( (string) meta( $id, '_ebay_item_id' ) === (string) $item_id ) {
			return $id;
		}
	}
	return 0;
}

function iso( $ts ) { return gmdate( 'Y-m-d\TH:i:s.000\Z', $ts ); }

/** One run of the delta sync over a window of changed listings. */
function run_delta( array $rows, $from_ts ) {
	$GLOBALS['EBAY']['events'] = $rows;
	update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array(
		'status'               => 'scanning',
		'sync_mode'            => 'delta',
		'delta_mod_from'       => iso( $from_ts ),
		'delta_window_from'    => '',
		'delta_cursor'         => '',
		'delta_offset'         => 0,
		'delta_window_minutes' => 2880,
	) ) );
	$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
	$GLOBALS['IMP']->fetch_delta_events();
}

/** A row as GetSellerEvents sends it: the brief, with no specifics or pictures. */
function event_row( $item_id, $status, $listed, $sold, $started ) {
	return array(
		'ItemID'         => $item_id,
		'Quantity'       => (string) $listed,
		'SellingStatus'  => array( 'ListingStatus' => $status, 'QuantitySold' => (string) $sold ),
		'ListingDetails' => array( 'StartTime' => iso( $started ), 'EndTime' => iso( $started + 600 ) ),
	);
}

/** Run everything queued, as Action Scheduler would: through the hook. */
function run_queued() {
	while ( $GLOBALS['QUEUE'] ) {
		$action = array_shift( $GLOBALS['QUEUE'] );
		foreach ( $GLOBALS['HOOKS'][ $action['hook'] ] ?? array() as $handler ) {
			call_user_func_array( $handler[0], array_slice( array_values( (array) $action['args'] ), 0, $handler[1] ) );
		}
	}
}

echo "\nTHE FINDING: A COIN RELISTED ON EBAY STAYS ONE PRODUCT\n" . str_repeat( '=', 128 ) . "\n";

// These fail on 3.27.2, deliberately.
//
// A coin imported from eBay listing 111, with no SKU of its own - so its SKU
// is the item number. The listing ended unsold, and the sync recorded that:
// one listed, none sold, one in stock. The seller relists it on eBay as 222.
// GetItem for 222 names 111 as the listing it came from, and GetItem for 111
// names 222 as the listing it was relisted as. 3.27.2 found nothing that
// matched 222, made a second product for it, and left this one in stock on
// the ended listing - where a website sale of it would never reach eBay.
fresh_shop();
coin( 501, $OLD );
settled( 501, $OLD, 1, 0 );
$META[501]['_ebay_unsold_at_end']    = 1;
$META[501]['_ebay_restock_reviewed'] = 'kept';
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'the relist is saved to the product that sold the old listing', $got, 501 );
check( '  and no second product is made', $CREATED, array() );
check( '  which now follows the relist', meta( 501, '_ebay_item_id' ), $NEW );
check( '  remembering the listing it followed before', meta( 501, '_ebay_previous_item_id' ), $OLD );
check( '  its SKU, the old item number, is now the new one', meta( 501, '_sku' ), $NEW );
check( '  and so is the SKU it is known by to eBay', meta( 501, '_ebay_sku' ), $NEW );
check( '  it is Active', meta( 501, '_ebay_listing_status' ), 'Active' );
check( '  nothing settled about the old listing is left', settle_keys_left( 501 ), array() );
check( '  the stock is the relist\'s one', stock( 501 ), 1 );
check( '  the Listings row moved to the relist', array( last_row()['product_id'] ?? null, last_row()['ebay_item_id'] ?? null ), array( 501, $NEW ) );
check( '  the log says what happened', logged( 'is a relist of ' . $OLD ), true );
// The settle on record has the figures, but not which listing 111 was relisted
// as - and only that tells a relist from a Sell Similar copy of it.
check( '  eBay asked once, about the old listing, though its ending was on record', $EBAY['get_items'], array( $OLD ) );
check( '  the product\'s own history says so too', ( meta( 501, '_tcgiant_sync_log' )[0]['decisions'] ?? array() )[0] ?? null, 'Relisted on eBay: now follows listing ' . $NEW . ' (was ' . $OLD . ')' );
check( '  and records the listing it came from', meta( 501, '_ebay_relist_parent_id' ), $OLD );

echo "\nA SKU THAT NAMES THE LISTING FOLLOWS IT, AND SO DO STOCK CHANGES\n" . str_repeat( '=', 128 ) . "\n";

// A stock change queued, run as Action Scheduler would run it.
function run_stock_pushes() {
	$ran = 0;
	foreach ( $GLOBALS['QUEUE'] as $i => $action ) {
		if ( 'tcgiant_sync_push_stock' === $action['hook'] ) {
			unset( $GLOBALS['QUEUE'][ $i ] );
			$GLOBALS['INV']->process_stock_push( $action['args'][0] );
			$ran++;
		}
	}
	$GLOBALS['QUEUE'] = array_values( $GLOBALS['QUEUE'] );
	return $ran;
}
function queued_pushes() {
	$out = array();
	foreach ( $GLOBALS['QUEUE'] as $action ) {
		if ( 'tcgiant_sync_push_stock' === $action['hook'] ) {
			$out[] = array( $action['args'][0]['product_id'] ?? null, $action['args'][0]['reason'] ?? null );
		}
	}
	return $out;
}

// With the "EBAY-" prefix the SKU is read as an address: a stock change is sent
// straight to the listing it names. Left as EBAY-111, every stock change on
// the product would have gone to the listing that had ended.
fresh_shop( array( 'sku_prefix' => 'ebay' ) );
coin( 502, $OLD, array( '_sku' => 'EBAY-' . $OLD, '_ebay_sku' => 'EBAY-' . $OLD ) );
settled( 502, $OLD, 1, 0 );
relisted( $OLD, $NEW, 1, 0 );
import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'with the EBAY- prefix: EBAY-111 becomes EBAY-222', array( meta( 502, '_sku' ), meta( 502, '_ebay_sku' ) ), array( 'EBAY-' . $NEW, 'EBAY-' . $NEW ) );
check( '  no second product', $CREATED, array() );
// The shop restocks two more: WooCommerce tells the plugin, which queues a
// push and runs it - the real path, as far as eBay's door.
$META[502]['_stock'] = 3;
$INV->on_product_stock_change( wc_get_product( 502 ) );
check( '  a stock change is queued, for the product', queued_pushes(), array( array( 502, 'edit' ) ) );
run_stock_pushes();
$sent = $EBAY['wire'][0] ?? array( '', '' );
check( '  and reaches eBay as ReviseInventoryStatus', $sent[0], 'ReviseInventoryStatus' );
check( '  for the relist, not the ended listing', 1 === preg_match( '#<ItemID>' . $NEW . '</ItemID>#', $sent[1] ), true );

// The same shop, but the plugin had pushed 111 to eBay with that SKU, so eBay
// copies "EBAY-111" on to the relist as a SKU of the seller's own - and the
// import writes eBay's SKU back. The product follows 222 under a SKU that
// names 111. Its stock changes go to the listing it follows, not the one its
// SKU was made from: sent to 111, a website sale would never reach 222.
fresh_shop( array( 'sku_prefix' => 'ebay' ) );
coin( 522, $OLD, array( '_sku' => 'EBAY-' . $OLD, '_ebay_sku' => 'EBAY-' . $OLD ) );
settled( 522, $OLD, 1, 0 );
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'EBAY-' . $OLD ) ) );
check( 'a relist that carries EBAY-111 as its SKU: the same product, following 222', array( $got, $CREATED, meta( 522, '_ebay_item_id' ) ), array( 522, array(), $NEW ) );
check( '  under eBay\'s SKU, which still names 111', array( meta( 522, '_sku' ), meta( 522, '_ebay_sku' ) ), array( 'EBAY-' . $OLD, 'EBAY-' . $OLD ) );
$META[522]['_stock'] = 4;
$INV->on_product_stock_change( wc_get_product( 522 ) );
check( '  a stock change is queued, for the product - not for its SKU', queued_pushes(), array( array( 522, 'edit' ) ) );
run_stock_pushes();
$sent = $EBAY['wire'][0] ?? array( '', '' );
check( '  and reaches eBay as ReviseInventoryStatus for 222, the listing it follows', array( $sent[0], 1 === preg_match( '#<ItemID>' . $NEW . '</ItemID>#', $sent[1] ) ), array( 'ReviseInventoryStatus', true ) );
check( '  never for 111', false !== strpos( $sent[1], $OLD ), false );
check( '  the product still Active', meta( 522, '_ebay_listing_status' ), 'Active' );
// Sold out on the website: 222 is ended, not 111. Through the push queued by
// SKU before the update, which finds the product by that SKU too.
$EBAY['wire']        = array();
$META[522]['_stock'] = 0;
$INV->process_ebay_stock_update( 'EBAY-' . $OLD, 0 );
$sent = $EBAY['wire'][0] ?? array( '', '' );
check( '  stock 0 sends EndItem for 222', array( $sent[0], 1 === preg_match( '#<ItemID>' . $NEW . '</ItemID>#', $sent[1] ) ), array( 'EndItem', true ) );

// The new item number is already some other product's SKU. Never take it:
// two products with one SKU would send one's stock to the other's listing.
fresh_shop();
coin( 506, $OLD );
settled( 506, $OLD, 1, 0 );
coin( 599, '', array( '_sku' => $NEW, '_ebay_sku' => '', '_ebay_item_id' => '', '_ebay_listing_status' => '' ) );
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'the new number is another product\'s SKU: the relist still goes to its product', $got, 506 );
check( '  without taking that SKU', array( wc_get_product_id_by_sku( $NEW ), meta( 599, '_sku' ) ), array( 599, $NEW ) );
check( '  and the log says so, naming the other product', logged( 'is already the SKU of WC #599', 'warning' ), true );

echo "\nA BARCODE SHOP\n" . str_repeat( '=', 128 ) . "\n";

// SKUs from barcodes. One part number is on many listings, so a barcode match
// is refused on purpose - which is also why every relist became a duplicate.
// 503 sells the part on listing 111, its SKU made unique with the item number;
// 504 holds the bare barcode for another live listing of the same part.
fresh_shop();
coin( 503, $OLD, array( '_sku' => '0123456789012-' . $OLD, '_ebay_sku' => '0123456789012', '_stock' => 2 ) );
settled( 503, $OLD, 3, 1 );
coin( 504, '550000000555', array( '_sku' => '0123456789012', '_ebay_sku' => '0123456789012', '_ebay_listing_status' => 'Active', '_stock' => 4 ) );
relisted( $OLD, $NEW, 3, 1 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'Quantity' => '2', 'ProductListingDetails' => array( 'UPC' => '0123456789012' ) ) ) );
check( 'the relist is saved to the product that sold the old listing', $got, 503 );
check( '  no second product', $CREATED, array() );
check( '  it follows the relist', meta( 503, '_ebay_item_id' ), $NEW );
check( '  the other listing\'s product keeps its listing', meta( 504, '_ebay_item_id' ), '550000000555' );
check( '  and its barcode SKU', meta( 504, '_sku' ), '0123456789012' );
check( '  and its stock', stock( 504 ), 4 );

// 605 sold out its five on 111 - relisted with five more, a restock that is
// moved. But 222 carries a different UPC: another part, listed with 111 as
// the template. It is a product of its own, and 605 stays sold out on 111.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 605, $OLD, array( '_sku' => '0123456789012', '_ebay_sku' => '0123456789012', '_stock' => 0, '_stock_status' => 'outofstock' ) );
settled( 605, $OLD, 5, 5 );
relisted( $OLD, $NEW, 5, 5 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'Quantity' => '5', 'ProductListingDetails' => array( 'UPC' => '0999999999999' ) ) ) );
check( 'a relist with another barcode is a product of its own', $got > 0 && $got !== 605 && created_for( $NEW ) === $got, true );
check( '  the sold-out product stays on its listing, at 0', array( meta( 605, '_ebay_item_id' ), stock( 605 ), writes_to( 605 ) ), array( $OLD, 0, array() ) );
check( '  and the log names both barcodes', logged( 'its barcode "0999999999999" is not WC #605\'s "0123456789012", so it is another item' ), true );

// The same barcode: the restock goes to the product.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 605, $OLD, array( '_sku' => '0123456789012', '_ebay_sku' => '0123456789012', '_stock' => 0, '_stock_status' => 'outofstock' ) );
settled( 605, $OLD, 5, 5 );
relisted( $OLD, $NEW, 5, 5 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'Quantity' => '5', 'ProductListingDetails' => array( 'UPC' => '0123456789012' ) ) ) );
check( 'a relist with the same barcode: the same product, holding the five', array( $got, $CREATED, meta( 605, '_ebay_item_id' ), stock( 605 ) ), array( 605, array(), $NEW, 5 ) );

// The other part again, listed and sold out between two syncs, so the delta
// sync only ever sees it ended. Its barcode is worked out as the import would
// work it out, and decides the same way: 605 is not moved on to it.
fresh_shop( array( 'remove_sold_products' => '0' ) );
$from = time() - HOUR_IN_SECONDS;
coin( 605, $OLD, array( '_sku' => '0123456789012', '_ebay_sku' => '0123456789012', '_stock' => 0, '_stock_status' => 'outofstock' ) );
settled( 605, $OLD, 5, 5 );
relisted( $OLD, $NEW, 5, 5 );
ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD, 'Quantity' => '5', 'ProductListingDetails' => array( 'UPC' => '0999999999999' ), 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '5' ) ) ) );
run_delta( array( event_row( $NEW, 'Completed', 5, 5, $from + 600 ) ), $from );
check( 'the delta meets it ended: the sold-out product stays on its listing', array( meta( 605, '_ebay_item_id' ), writes_to( 605 ), $CREATED ), array( $OLD, array(), array() ) );
check( '  and the log says so', logged( 'its barcode "0999999999999" is not WC #605\'s "0123456789012", so it is another item: WC #605 is not moved on to it.' ), true );

// An auto-parts shop. 620 was imported from 111, which had a UPC and no
// custom label, so the UPC became its SKU. The seller relisted 111 and typed
// a custom label into eBay's Relist form; the relist keeps the UPC. Compared
// by the label alone, it looked like other goods: 222 became a second product
// for the same three parts, and 620 stayed in stock on the ended listing,
// where a website sale of it never reached eBay. Any SKU or barcode the
// relist carries now counts.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 620, $OLD, array( '_sku' => '0123456789012', '_ebay_sku' => '0123456789012', '_stock' => 3 ) );
settled( 620, $OLD, 3, 0 );
relisted( $OLD, $NEW, 3, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'BRK-PAD-1', 'Quantity' => '3', 'ProductListingDetails' => array( 'UPC' => '0123456789012' ) ) ) );
check( 'a relist that gains a label of the seller\'s and keeps the UPC: the same product', array( $got, $CREATED, meta( 620, '_ebay_item_id' ) ), array( 620, array(), $NEW ) );
check( '  holding the three', stock( 620 ), 3 );
check( '  not called another item', logged( 'so it is another item' ), false );
check( '  eBay asked once, about the old listing', $EBAY['get_items'], array( $OLD ) );

// The same, with the UPC given as an item specific rather than in the
// product details: it counts all the same.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 621, $OLD, array( '_sku' => '0123456789012', '_ebay_sku' => '0123456789012', '_stock' => 3 ) );
settled( 621, $OLD, 3, 0 );
relisted( $OLD, $NEW, 3, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'BRK-PAD-1', 'Quantity' => '3', 'ItemSpecifics' => array( 'NameValueList' => array(
	array( 'Name' => 'Brand', 'Value' => 'Acme' ),
	array( 'Name' => 'UPC', 'Value' => '0123456789012' ),
) ) ) ) );
check( 'the UPC as an item specific: the same product', array( $got, $CREATED, meta( 621, '_ebay_item_id' ) ), array( 621, array(), $NEW ) );

// A stand-in in the barcode field is no barcode. 625 was imported by an older
// version, which took "Does not apply" from the UPC field as its SKU. A relist
// with a label of the seller's and the same stand-in for a UPC shares nothing
// with it that names goods, so it is not tied to the product by that.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 625, $OLD, array( '_sku' => 'Does not apply', '_ebay_sku' => 'Does not apply' ) );
settled( 625, $OLD, 1, 0 );
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'BRK-PAD-1', 'ItemSpecifics' => array( 'NameValueList' => array( 'Name' => 'UPC', 'Value' => 'Does not apply' ) ) ) ) );
check( 'a UPC of "Does not apply" ties nothing: a product of its own', array( $got > 0 && $got !== 625 && created_for( $NEW ) === $got, meta( 625, '_ebay_item_id' ) ), array( true, $OLD ) );
check( '  the log names the label it was compared by', logged( 'its SKU "BRK-PAD-1" is not WC #625\'s "Does not apply", so it is another item' ), true );

// The reverse: a new label and another UPC. Nothing the relist carries is the
// barcode the part was imported under - 111 used as a template for another
// part - so it is still a product of its own, decided before eBay is asked.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 622, $OLD, array( '_sku' => '0123456789012', '_ebay_sku' => '0123456789012', '_stock' => 3 ) );
settled( 622, $OLD, 3, 0 );
relisted( $OLD, $NEW, 3, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'BRK-PAD-2', 'Quantity' => '3', 'ProductListingDetails' => array( 'UPC' => '0999999999999' ) ) ) );
check( 'a new label and another UPC: still a product of its own', $got > 0 && $got !== 622 && created_for( $NEW ) === $got, true );
check( '  the part stays on its listing, untouched', array( meta( 622, '_ebay_item_id' ), writes_to( 622 ) ), array( $OLD, array() ) );
check( '  the log names the label and the barcode the part has', logged( 'its SKU "BRK-PAD-2" is not WC #622\'s "0123456789012", so it is another item: ' . $NEW . ' is imported as a product of its own' ), true );
check( '  eBay not asked', $EBAY['get_items'], array() );

// The relist with the label and the UPC again, listed and sold out between
// two syncs, so the delta only ever sees it ended. Decided the same way: the
// part moves on to it and is settled from it.
fresh_shop( array( 'remove_sold_products' => '0' ) );
$from = time() - HOUR_IN_SECONDS;
coin( 623, $OLD, array( '_sku' => '0123456789012', '_ebay_sku' => '0123456789012', '_stock' => 3 ) );
settled( 623, $OLD, 3, 0 );
relisted( $OLD, $NEW, 3, 0 );
ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'BRK-PAD-1', 'Quantity' => '3', 'ProductListingDetails' => array( 'UPC' => '0123456789012' ), 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '3' ) ) ) );
run_delta( array( event_row( $NEW, 'Completed', 3, 3, $from + 600 ) ), $from );
check( 'met by the delta, sold out: the part follows the relist, settled to 0', array( meta( 623, '_ebay_item_id' ), stock( 623 ), meta( 623, '_stock_status' ), $CREATED ), array( $NEW, 0, 'outofstock', array() ) );
check( '  not called another item', logged( 'so it is another item' ), false );

// A shop that keeps its own SKUs in the bin location. 624 was listed by the
// shop itself as ABC-1, and has no SKU on record from eBay; its listing has a
// UPC. The relist carries ABC-1 too, but in this shop that goes to the bin
// location, so the SKU the relist would be imported with is its UPC - which is
// not ABC-1. The SKU the seller gave it is ABC-1 all the same.
fresh_shop( array( 'sku_maps_to' => 'bin_location' ) );
coin( 624, $OLD, array( '_sku' => 'ABC-1', '_ebay_sku' => '' ) );
settled( 624, $OLD, 1, 0 );
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'ABC-1', 'ProductListingDetails' => array( 'UPC' => '0123456789012' ) ) ) );
check( 'SKUs kept in the bin location: a relist under the same SKU is the same product', array( $got, $CREATED, meta( 624, '_ebay_item_id' ) ), array( 624, array(), $NEW ) );
check( '  not called another item', logged( 'so it is another item' ), false );

echo "\nSELL SIMILAR FROM A LISTING THAT IS STILL LIVE IS A COPY, NOT A RELIST\n" . str_repeat( '=', 128 ) . "\n";

fresh_shop();
coin( 505, $OLD, array( '_ebay_listing_status' => 'Active' ) );
ebay_has( $OLD, listing( $OLD ) );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'the copy becomes a product of its own', $got > 0 && $got !== 505 && created_for( $NEW ) === $got, true );
check( '  the original keeps its listing', meta( 505, '_ebay_item_id' ), $OLD );
check( '  the log says the old listing is still live', logged( 'is still live', 'warning' ), true );
check( '  eBay was asked once, about the old listing', $EBAY['get_items'], array( $OLD ) );

echo "\nSELL SIMILAR FROM A LISTING THAT HAS ENDED IS A COPY TOO\n" . str_repeat( '=', 128 ) . "\n";

// The coin on 111 did not sell, and its listing ended. The seller used Sell
// Similar on it to list the next coin - another grade, the same title - as
// 222, and eBay names 111 on that too. 111 is over and sold nothing, so every
// other check passes; only 111's own record tells the two apart. It names no
// relist, or another one.
foreach ( array( 'no relist' => '', 'another relist' => $THIRD ) as $what => $relisted_as ) {
	fresh_shop();
	coin( 607, $OLD );
	settled( 607, $OLD, 1, 0 );
	ebay_has( $OLD, listing( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0' ), 'ListingDetails' => array( 'RelistedItemID' => $relisted_as ) ) ) );
	$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
	check( sprintf( 'eBay\'s record of the ended listing names %s: the copy is a product of its own', $what ), $got > 0 && $got !== 607 && created_for( $NEW ) === $got, true );
	check( '  the unsold coin keeps its listing and its stock', array( meta( 607, '_ebay_item_id' ), stock( 607 ), writes_to( 607 ) ), array( $OLD, 1, array() ) );
	check( '  and the warning says eBay does not record it as the relist', logged( 'eBay does not record ' . $OLD . ' as relisted as ' . $NEW, 'warning' ), true );
}

// The same from a shop with SKUs of its own, the copy keeping the coin's SKU.
// Not moved on as a relist - but a seller's SKU names the goods, so the SKU
// match decides, as it did before 3.28.0. That needs to know whether 111 is
// over, which the record here says it is not: eBay has just said, and is not
// asked a second time.
fresh_shop();
coin( 608, $OLD, array( '_sku' => 'COIN-A', '_ebay_sku' => 'COIN-A', '_ebay_listing_status' => 'Active' ) );
ebay_has( $OLD, listing( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed' ) ) ) );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'COIN-A' ) ) );
check( 'a copy under the coin\'s own SKU: the SKU match takes the product on, as before', array( $got, $CREATED, meta( 608, '_ebay_item_id' ) ), array( 608, array(), $NEW ) );
check( '  the log says the SKU match decides', logged( 'it is not moved on as a relist. Its SKU "COIN-A" is WC #608\'s own, so the SKU match decides, as before', 'warning' ), true );
check( '  and the SKU match says the old listing has ended', logged( 'SKU "COIN-A" matched WC #608, whose listing ' . $OLD . ' has ended' ), true );
check( '  eBay asked once, about the old listing', $EBAY['get_items'], array( $OLD ) );

echo "\nA LOCAL RECORD THAT LAGS IS CHECKED WITH EBAY\n" . str_repeat( '=', 128 ) . "\n";

// Still marked Active here, but eBay has ended it: the sync had not got there.
fresh_shop();
coin( 506, $OLD, array( '_ebay_listing_status' => 'Active' ) );
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'eBay says the old listing ended: the relist goes to its product', $got, 506 );
check( '  no second product', $CREATED, array() );
check( '  eBay asked once, about the old listing', $EBAY['get_items'], array( $OLD ) );

fresh_shop();
coin( 507, $OLD, array( '_ebay_listing_status' => 'Active' ) );
ebay_has( $OLD, new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'eBay cannot be asked: the relist becomes a product of its own', $got > 0 && created_for( $NEW ) === $got, true );
check( '  the original keeps its listing', meta( 507, '_ebay_item_id' ), $OLD );
check( '  and the log says it could not be confirmed, and why', logged( 'could not confirm how ' . $OLD . ' ended (cURL error 28', 'warning' ), true );

echo "\nOUT OF CALLS: THE RELIST WAITS\n" . str_repeat( '=', 128 ) . "\n";

// The day's calls are used up when 222 is imported, so eBay cannot be asked
// how 111 ended. Made a product of its own now, 222 would stay one for good -
// every later import finds it by its item number - beside the coin that
// should have moved on to it. So nothing is saved, and the caller tries again
// later, as for any rate limit.
fresh_shop();
coin( 609, $OLD );
settled( 609, $OLD, 1, 0 );
$out = new WP_Error( 'rate_limited', 'Daily API call budget exhausted. Will resume tomorrow.', array( 'daily_budget' => true ) );
ebay_has( $OLD, $out );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'out of calls asking about the old listing: nothing saved for now', array( $got, TCGiant_Sync_Mapper::$last_deferred === $out ), array( false, true ) );
check( '  no product made', $CREATED, array() );
check( '  the coin left on its listing, untouched', array( meta( 609, '_ebay_item_id' ), writes_to( 609 ) ), array( $OLD, array() ) );
check( '  not counted as a deliberate skip', TCGiant_Sync_Mapper::$last_skipped, false );
check( '  and the log says it is looked at again', logged( 'eBay could not be asked how ' . $OLD . ' ended (Daily API call budget exhausted. Will resume tomorrow.). It is looked at again', 'warning' ), true );
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( '  once eBay answers, the next import moves the coin on to the relist', array( $got, $CREATED, meta( 609, '_ebay_item_id' ) ), array( 609, array(), $NEW ) );
check( '  with nothing left deferred', TCGiant_Sync_Mapper::$last_deferred, null );

// Every other way of being out of calls, and no token to ask with: the same.
foreach ( array(
	'eBay\'s 429'   => new WP_Error( 'rate_limited', 'Too many requests.', array( 'retry_after' => 60 ) ),
	'eBay\'s 518'   => new WP_Error( 'rate_limited', 'Call usage limit has been reached.', array( 'Errors' => array( 'ErrorCode' => '518' ) ) ),
	'no token'      => new WP_Error( 'no_token', 'No valid eBay token.' ),
) as $what => $error ) {
	fresh_shop();
	coin( 609, $OLD );
	ebay_has( $OLD, $error );
	$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
	check( sprintf( '%s: nothing saved, the relist waits', $what ), array( $got, TCGiant_Sync_Mapper::$last_deferred === $error, $CREATED, meta( 609, '_ebay_item_id' ) ), array( false, true, array(), $OLD ) );
}

// eBay's 10007 comes back as a rate limit too, but it is an error inside eBay
// that one listing can keep hitting, not the calls running out. Waited on, it
// could hold the relist back for good - so, as for any answer that could not
// be had, the relist is imported on its own.
fresh_shop();
coin( 611, $OLD );
settled( 611, $OLD, 1, 0 );
ebay_has( $OLD, new WP_Error( 'rate_limited', 'Internal error to the application.', array( 'Errors' => array( 'ErrorCode' => '10007' ) ) ) );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'eBay\'s 10007: not waited on - the relist is a product of its own', array( $got > 0 && created_for( $NEW ) === $got, TCGiant_Sync_Mapper::$last_deferred ), array( true, null ) );
check( '  the coin keeps its listing', meta( 611, '_ebay_item_id' ), $OLD );
check( '  and the log says it could not be confirmed', logged( 'could not confirm how ' . $OLD . ' ended (Internal error to the application.)', 'warning' ), true );

echo "\nA SINGLE ITEM THAT SOLD IS NOT BROUGHT BACK BY THE NEXT COPY\n" . str_repeat( '=', 128 ) . "\n";

// One coin, sold on eBay. Relisting a sold single is nearly always the next
// copy - another coin, another grade - and moving the sold product on to it
// would put the sold item back up under its old title.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 508, $OLD, array( '_stock' => 0, '_stock_status' => 'outofstock' ) );
settled( 508, $OLD, 1, 1 );
relisted( $OLD, $NEW, 1, 1 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'a relist of a sold single is a product of its own', $got > 0 && created_for( $NEW ) === $got, true );
check( '  the sold product is left as it was: 0, ended, on its listing', array( stock( 508 ), meta( 508, '_ebay_listing_status' ), meta( 508, '_ebay_item_id' ) ), array( 0, 'Ended', $OLD ) );
check( '  nothing written to it', writes_to( 508 ), array() );
check( '  and the log says why', logged( 'which sold its only item', 'info' ), true );

// Five listed and five sold, relisted with five more: a restock.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 509, $OLD, array( '_stock' => 0, '_stock_status' => 'outofstock' ) );
settled( 509, $OLD, 5, 5 );
relisted( $OLD, $NEW, 5, 5 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'Quantity' => '5' ) ) );
check( 'five that sold out, relisted with five: the same product', $got, 509 );
check( '  holding the five', stock( 509 ), 5 );
check( '  in stock', meta( 509, '_stock_status' ), 'instock' );
check( '  and no warning: eBay\'s own sales account for the 0', log_lines_naming( 'WooCommerce held' ), array() );

echo "\nOTHER REASONS TO LEAVE A RELIST ALONE\n" . str_repeat( '=', 128 ) . "\n";

fresh_shop();
coin( 510, $OLD, array(), 'trash' );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'the old listing\'s product is in the trash: a new product', $got > 0 && created_for( $NEW ) === $got, true );
check( '  the trashed one is not touched', array( writes_to( 510 ), meta( 510, '_ebay_item_id' ), trashed( 510 ) ), array( array(), $OLD, true ) );

fresh_shop();
coin( 511, $OLD );
coin( 512, $OLD );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'two products on the old listing: neither is moved', array( meta( 511, '_ebay_item_id' ), meta( 512, '_ebay_item_id' ) ), array( $OLD, $OLD ) );
check( '  the relist is a product of its own', $got > 0 && created_for( $NEW ) === $got, true );
check( '  and the warning names both', logged( 'WC #511 and WC #512 are both linked to ' . $OLD, 'warning' ), true );

foreach ( array( 'its own item number' => $NEW, '"0"' => '0', 'not a number' => 'N/A', 'a number with letters' => '1100000x0111' ) as $what => $parent ) {
	fresh_shop();
	coin( 513, $OLD );
	settled( 513, $OLD, 1, 0 );
	$got = import( listing( $NEW, array( 'RelistParentID' => $parent ) ) );
	check( sprintf( 'a RelistParentID of %s is ignored', $what ), array( $got !== 513, meta( 513, '_ebay_item_id' ) ), array( true, $OLD ) );
	check( '  without a lookup', count( array_filter( $QUERIES, function ( $q ) { return false !== strpos( $q, 'LIMIT 2' ); } ) ), 0 );
}

echo "\nA SELLER'S OWN SKU DECIDES\n" . str_repeat( '=', 128 ) . "\n";

// The relist carries a SKU the seller gave another product. The seller's SKU
// names the goods, so the SKU match decides, exactly as before.
fresh_shop();
coin( 514, $OLD, array( '_sku' => 'COIN-A', '_ebay_sku' => 'COIN-A' ) );
settled( 514, $OLD, 1, 0 );
coin( 515, '', array( '_sku' => 'COIN-B', '_ebay_sku' => '', '_ebay_item_id' => '', '_ebay_listing_status' => '' ) );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'COIN-B' ) ) );
check( 'the relist\'s SKU is another product\'s: that product gets it', $got, 515 );
check( '  linked to the relist', meta( 515, '_ebay_item_id' ), $NEW );
check( '  the old listing\'s product keeps its listing', meta( 514, '_ebay_item_id' ), $OLD );
check( '  and the log says why', logged( 'its SKU "COIN-B" belongs to WC #515' ), true );
check( '  the SKU match comes next, so the log says it decides', logged( 'eBay listing ' . $NEW . ' is a relist of ' . $OLD . ' (WC #514), but its SKU "COIN-B" belongs to WC #515, so the SKU decides which product it is.' ), true );

// The same relist met by the delta instead, ended and sold, with WC #524 on
// 111 and WC #525 holding COIN-B on a live listing of its own. On the delta
// no SKU match comes after this, so neither product is moved on to it. Told
// the SKU decides, the shop would believe 525 now followed 222 and that its
// sale had been dealt with.
fresh_shop( array( 'remove_sold_products' => '0' ) );
$from = time() - HOUR_IN_SECONDS;
coin( 524, $OLD, array( '_sku' => 'COIN-A', '_ebay_sku' => 'COIN-A' ) );
settled( 524, $OLD, 1, 0 );
coin( 525, '440000000444', array( '_sku' => 'COIN-B', '_ebay_sku' => 'COIN-B', '_ebay_listing_status' => 'Active' ) );
relisted( $OLD, $NEW, 1, 0 );
ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'COIN-B', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ) ) ) );
run_delta( array( event_row( $NEW, 'Completed', 1, 1, $from + 600 ) ), $from );
check( 'the delta meets it: neither product is moved on to it, nor written to', array( meta( 524, '_ebay_item_id' ), meta( 525, '_ebay_item_id' ), writes_to( 524 ), writes_to( 525 ), $CREATED ), array( $OLD, '440000000444', array(), array(), array() ) );
check( '  and the log says so, naming both', logged( 'eBay listing ' . $NEW . ' is a relist of ' . $OLD . ' (WC #524), but its SKU "COIN-B" belongs to WC #525, so neither WC #524 nor WC #525 is moved on to it.' ), true );
check( '  not that the SKU decides', logged( 'the SKU decides' ), false );

fresh_shop();
coin( 516, $OLD, array( '_sku' => 'COIN-A', '_ebay_sku' => 'COIN-A' ) );
settled( 516, $OLD, 1, 0 );
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'COIN-A' ) ) );
check( 'the relist keeps the old listing\'s own SKU: that product', $got, 516 );
check( '  following the relist', meta( 516, '_ebay_item_id' ), $NEW );
check( '  its SKU untouched', meta( 516, '_sku' ), 'COIN-A' );
check( '  moved on as a relist', logged( 'Import: eBay listing ' . $NEW . ' is a relist of ' . $OLD, 'success' ), true );
check( '  eBay asked once, about the old listing', $EBAY['get_items'], array( $OLD ) );

// The old listing used as a template for another coin: the new listing's SKU
// is the seller's own, but not the one 601 was imported with, and no product
// holds it. COIN-B names other goods. Even with eBay recording 111 as relisted
// as 222, 601 stays on 111 and 222 is a product of its own.
fresh_shop();
coin( 601, $OLD, array( '_sku' => 'COIN-A', '_ebay_sku' => 'COIN-A' ) );
settled( 601, $OLD, 1, 0 );
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'COIN-B' ) ) );
check( 'a different SKU of the seller\'s own: the relist is a product of its own', $got > 0 && $got !== 601 && created_for( $NEW ) === $got, true );
check( '  under that SKU', meta( $got, '_sku' ), 'COIN-B' );
check( '  the old listing\'s product stays on it, untouched', array( meta( 601, '_ebay_item_id' ), writes_to( 601 ) ), array( $OLD, array() ) );
check( '  the log says it is another item, naming both SKUs', logged( 'its SKU "COIN-B" is not WC #601\'s "COIN-A", so it is another item: ' . $NEW . ' is imported as a product of its own; WC #601 stays on listing ' . $OLD . '.' ), true );
check( '  decided before eBay is asked anything', $EBAY['get_items'], array() );

// The same SKU in other letters is the same SKU.
fresh_shop();
coin( 602, $OLD, array( '_sku' => 'COIN-A', '_ebay_sku' => 'COIN-A' ) );
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'coin-a' ) ) );
check( 'the same SKU in lower case: the relist goes to the product', array( $got, $CREATED, meta( 602, '_ebay_item_id' ) ), array( 602, array(), $NEW ) );

// A product with no SKU on record from eBay - one the shop listed itself - is
// compared by its WooCommerce SKU.
fresh_shop();
coin( 603, $OLD, array( '_sku' => 'COIN-A', '_ebay_sku' => '' ) );
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'COIN-B' ) ) );
check( 'no eBay SKU on record: the WooCommerce SKU is compared instead', array( $got !== 603, meta( 603, '_ebay_item_id' ), logged( 'is not WC #603\'s "COIN-A", so it is another item' ) ), array( true, $OLD, true ) );

// A coin imported with no SKU of its own - its SKU made from an item number -
// relisted with one the seller has given it since. An item number names no
// goods, so there is nothing to compare: it is the same coin. Whether made
// from the old listing's number, with or without "EBAY-", or from the number
// of the listing it followed before that.
foreach ( array( 'the item number' => $OLD, 'EBAY- and the item number' => 'EBAY-' . $OLD, 'ebay- in lower case' => 'ebay-' . $OLD, 'the listing it followed before' => '990000000999' ) as $what => $sku ) {
	fresh_shop();
	coin( 604, $OLD, array( '_sku' => $sku, '_ebay_sku' => $sku, '_ebay_previous_item_id' => '990000000999' ) );
	relisted( $OLD, $NEW, 1, 0 );
	$got = import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'COIN-NEW' ) ) );
	check( sprintf( 'a SKU made from %s, relisted with a SKU of the seller\'s: the same product', $what ), array( $got, $CREATED, meta( 604, '_ebay_item_id' ) ), array( 604, array(), $NEW ) );
}
check( '  which takes the seller\'s SKU', meta( 604, '_sku' ), 'COIN-NEW' );

echo "\nA SECOND RELIST OF THE SAME LISTING\n" . str_repeat( '=', 128 ) . "\n";

// eBay lets one listing be relisted more than once. Only one relist can have
// the product; the next is a product of its own, and the log says which.
fresh_shop();
coin( 517, $OLD );
settled( 517, $OLD, 1, 0 );
relisted( $OLD, $NEW, 1, 0 );
import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
$got = import( listing( $THIRD, array( 'RelistParentID' => $OLD ) ) );
check( 'the first relist has the product', meta( 517, '_ebay_item_id' ), $NEW );
check( 'the second is a product of its own', $got > 0 && $got !== 517 && created_for( $THIRD ) === $got, true );
check( '  and the warning names the product and the listing it moved on to', logged( 'WC #517 moved on from it to listing ' . $NEW, 'warning' ), true );
check( '  eBay asked about the old listing for the first relist only', $EBAY['get_items'], array( $OLD ) );

echo "\nA COIN SOLD ON THE WEBSITE, THEN RELISTED FROM EBAY'S UNSOLD LIST\n" . str_repeat( '=', 128 ) . "\n";

// The coin sold on the website. WooCommerce took it to 0 and the plugin ended
// the eBay listing, which eBay records as one listed, none sold. The seller
// then relisted everything on eBay's Unsold list, this coin with it. The relist
// offers eBay's 1, as any live listing would - and the shop is told how to
// take it back off.
fresh_shop();
coin( 518, $OLD, array( '_stock' => 0, '_stock_status' => 'outofstock', '_ebay_stock_settled_at' => '2026-10-02 10:00:00', '_ebay_stock_settled_item' => $OLD ) );
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'the relist goes to the product', $got, 518 );
check( '  at eBay\'s 1', stock( 518 ), 1 );
check( '  eBay asked once, about the old listing: its 1 listed, 0 sold is what the warning weighs', $EBAY['get_items'], array( $OLD ) );
check( '  a warning says WooCommerce held 0', logged( 'WooCommerce held 0', 'warning' ), true );
check( '  and that setting 0 ends the relist', logged( 'which ends listing ' . $NEW, 'warning' ), true );

echo "\nA COIN THE OLD SETTLE PUT BACK IN STOCK AFTER A WEBSITE SALE\n" . str_repeat( '=', 128 ) . "\n";

// Settled before 3.27.0 - no record of which listing - ended, in stock, and
// sold on the website since its listing started. Stock Review lists it; once
// it follows a live relist it leaves that list, so the warning names the order.
fresh_shop();
coin( 519, $OLD, array( '_ebay_stock_settled_at' => '2026-10-02 10:00:00' ) );
$ORDERS[4711]     = new WC_Order( strtotime( '2026-09-20 12:00:00 UTC' ) );
$ORDER_LINE[4711] = 519;
relisted( $OLD, $NEW, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'the relist goes to the product', $got, 519 );
check( '  the warning names the website order', logged( 'order #4711', 'warning' ), true );
check( '  and that setting 0 ends the relist', logged( 'which ends listing ' . $NEW, 'warning' ), true );
check( '  the cached Stock Review list is dropped', in_array( 'tcgiant_restocked_after_sale', $UNSET_T, true ), true );

// The same coin, but the relist had already ended, unsold, before anything
// imported it; the per-sale stock check meets it first. Moving the product on
// would forget the website sale and drop it off Stock Review, with nothing
// left to stop the coin being relisted yet again. It stays on 111, on Stock
// Review, and the log names the order.
function restocked_coin( $id, $stock = 1 ) {
	coin( $id, $GLOBALS['OLD'], array( '_ebay_stock_settled_at' => '2026-10-02 10:00:00', '_stock' => $stock ) );
	$GLOBALS['ORDERS'][4712]     = new WC_Order( strtotime( '2026-09-20 12:00:00 UTC' ) );
	$GLOBALS['ORDER_LINE'][4712] = $id;
}
$unsold_relist = listing( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0' ) ) );
fresh_shop();
restocked_coin( 614 );
relisted( $OLD, $NEW, 1, 0 );
$got = $MAPPER->adopt_relist( $unsold_relist, 'Sale check' );
check( 'a relist that ended unsold: the product is not moved on to it', array( $got, meta( 614, '_ebay_item_id' ) ), array( 0, $OLD ) );
check( '  nothing written to it: still 1, in stock', array( writes_to( 614 ), stock( 614 ), meta( 614, '_stock_status' ) ), array( array(), 1, 'instock' ) );
check( '  still on Stock Review', TCGiant_Sync_Inventory::restocked_after_website_sale( 614 ), 4712 );
check( '  and the warning names the website order', logged( 'Sale check: eBay listing ' . $NEW . ' is a relist of ' . $OLD . ' and has ended, but WC #614 looks as though it sold on your website (order #4712)', 'warning' ), true );
check( '  saying it stays on 111 and on Stock Review, and nothing about a sale on eBay', array( logged( 'It stays on listing ' . $OLD . ', and on the Stock Review page', 'warning' ), logged( 'sold on eBay' ) ), array( true, false ) );

// Met by the delta sync instead: the same, and the window is read - there is
// nothing to wait for.
fresh_shop( array( 'remove_sold_products' => '0' ) );
$from = time() - HOUR_IN_SECONDS;
restocked_coin( 614 );
relisted( $OLD, $NEW, 1, 0 );
ebay_has( $NEW, $unsold_relist );
run_delta( array( event_row( $NEW, 'Completed', 1, 0, $from + 600 ) ), $from );
check( 'the delta meets it: the product stays on its listing, in stock', array( meta( 614, '_ebay_item_id' ), stock( 614 ), meta( 614, '_stock_status' ) ), array( $OLD, 1, 'instock' ) );
check( '  the warning names the order', logged( 'Delta: eBay listing ' . $NEW . ' is a relist of ' . $OLD . ' and has ended, but WC #614 looks as though it sold on your website (order #4712)', 'warning' ), true );
check( '  and the window is read', TCGiant_Sync_Importer::get_sync_state()['status'], 'complete' );

// The relist sold out on eBay before anything imported it. Kept on 111, the
// coin stayed in stock here for an item eBay had sold - and the warning said
// nothing of that sale, so a shop that found order #4712 cancelled kept it on
// sale. A relist that sold out leaves nothing to relist, so the product is
// moved on to it and settled to 0, as any ended relist is, and the warning
// names both sales: the item may have sold twice. The per-sale stock check
// meets it first.
$sold_relist = listing( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ) ) );
fresh_shop( array( 'remove_sold_products' => '0' ) );
restocked_coin( 614 );
relisted( $OLD, $NEW, 1, 0 );
ebay_has( $NEW, $sold_relist );
$IMP->settle_item( $NEW );
check( 'a relist that sold out: the sale check moves the product on to it', array( meta( 614, '_ebay_item_id' ), $CREATED ), array( $NEW, array() ) );
check( '  and settles it to 0 from the relist', array( stock( 614 ), meta( 614, '_stock_status' ), meta( 614, '_ebay_listing_status' ), meta( 614, '_ebay_stock_settled_item' ) ), array( 0, 'outofstock', 'Ended', $NEW ) );
check( '  off Stock Review: nothing is left to sell', TCGiant_Sync_Inventory::restocked_after_website_sale( 614 ), 0 );
check( '  the warning names the eBay sale', logged( 'Sale check: WC #614 now follows eBay listing ' . $NEW . ', a relist of ' . $OLD . ', which sold out on eBay (1 listed, 1 sold), so its stock is settled from that.', 'warning' ), true );
check( '  and the website order, in case it sold twice', logged( 'It had looked as though it sold on your website too (order #4712) - check that order, in case the item sold twice.', 'warning' ), true );
check( '  not the warning that keeps it on 111', logged( 'and on the Stock Review page' ), false );

// Met by the delta sync instead: the same.
fresh_shop( array( 'remove_sold_products' => '0' ) );
$from = time() - HOUR_IN_SECONDS;
restocked_coin( 614 );
relisted( $OLD, $NEW, 1, 0 );
ebay_has( $NEW, $sold_relist );
run_delta( array( event_row( $NEW, 'Completed', 1, 1, $from + 600 ) ), $from );
check( 'the delta meets it: the product follows the relist, settled to 0', array( meta( 614, '_ebay_item_id' ), stock( 614 ), meta( 614, '_stock_status' ), $CREATED ), array( $NEW, 0, 'outofstock', array() ) );
check( '  the warning names the eBay sale and the order', logged( 'Delta: WC #614 now follows eBay listing ' . $NEW . ', a relist of ' . $OLD . ', which sold out on eBay (1 listed, 1 sold), so its stock is settled from that. It had looked as though it sold on your website too (order #4712)', 'warning' ), true );
check( '  and the window is read', TCGiant_Sync_Importer::get_sync_state()['status'], 'complete' );

// Three on the relist, one sold on eBay, then it ended. Two are left to
// relist, so the product stays on 111 and on Stock Review - but the one eBay
// sold is gone whichever way order #4712 is read, so the stock comes down to
// the two the relist left. Lowered, not settled: 111's record from before
// 3.27.0 stays as it was.
$part_relist = listing( $NEW, array( 'RelistParentID' => $OLD, 'Quantity' => '3', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ) ) );
fresh_shop( array( 'remove_sold_products' => '0' ) );
restocked_coin( 614, 3 );
relisted( $OLD, $NEW, 3, 0 );
$got = $MAPPER->adopt_relist( $part_relist, 'Sale check' );
check( 'a relist that ended with 1 of 3 sold: the product is not moved on to it', array( $got, meta( 614, '_ebay_item_id' ) ), array( 0, $OLD ) );
check( '  its stock lowered from 3 to the 2 the relist left', array( stock( 614 ), meta( 614, '_stock_status' ) ), array( 2, 'instock' ) );
check( '  the stock the only thing written: no settle marks', array( writes_to( 614 ), meta( 614, '_ebay_stock_settled_item' ) ), array( array( array( 614, '_stock' ) ), null ) );
check( '  still on Stock Review', TCGiant_Sync_Inventory::restocked_after_website_sale( 614 ), 4712 );
check( '  the cached Stock Review list is dropped', in_array( 'tcgiant_restocked_after_sale', $UNSET_T, true ), true );
check( '  the warning says what eBay sold', logged( 'Sale check: eBay listing ' . $NEW . ' is a relist of ' . $OLD . ' and has ended with 1 of 3 sold on eBay, but WC #614 looks as though it sold on your website (order #4712)', 'warning' ), true );
check( '  and the lowering', logged( 'It stays on listing ' . $OLD . ', and on the Stock Review page, until its stock is set there. Its stock is lowered from 3 to 2, what that relist has left.', 'warning' ), true );

// The same relist, the product holding 1: fewer than the relist left, so
// nothing is lowered, and the warning says only what eBay sold.
fresh_shop( array( 'remove_sold_products' => '0' ) );
restocked_coin( 614, 1 );
relisted( $OLD, $NEW, 3, 0 );
$got = $MAPPER->adopt_relist( $part_relist, 'Sale check' );
check( 'holding 1, with 2 left on the relist: not moved, and nothing written', array( $got, meta( 614, '_ebay_item_id' ), stock( 614 ), writes_to( 614 ) ), array( 0, $OLD, 1, array() ) );
check( '  the warning says what eBay sold, and lowers nothing', array( logged( 'and has ended with 1 of 3 sold on eBay, but WC #614', 'warning' ), logged( 'Its stock is lowered' ), in_array( 'tcgiant_restocked_after_sale', $UNSET_T, true ) ), array( true, false, false ) );

// A relist still live is taken on, as the import takes one on, and the
// warning naming the order is given there and then: the move forgets the sale.
fresh_shop();
restocked_coin( 614 );
relisted( $OLD, $NEW, 1, 0 );
$got = $MAPPER->adopt_relist( listing( $NEW, array( 'RelistParentID' => $OLD ) ), 'Sale check' );
check( 'a relist still live: the product is moved on to it', array( $got, meta( 614, '_ebay_item_id' ) ), array( 614, $NEW ) );
check( '  the warning names the website order at once', logged( 'It looks as though it sold on your website (order #4712)', 'warning' ), true );
check( '  and leaves no mark for the import: it has been said', meta( 614, '_ebay_relist_stock_check' ), null );

echo "\nA LISTING THAT ALREADY HAS ITS PRODUCT\n" . str_repeat( '=', 128 ) . "\n";

fresh_shop();
coin( 520, $NEW, array( '_ebay_listing_status' => 'Active' ) );
coin( 521, $OLD );
settled( 521, $OLD, 1, 0 );
$got = import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'the product already on the relist keeps it', $got, 520 );
check( '  the old listing\'s product is not touched', array( writes_to( 521 ), meta( 521, '_ebay_item_id' ) ), array( array(), $OLD ) );
check( '  and nothing looked for it', count( array_filter( $QUERIES, function ( $q ) { return false !== strpos( $q, 'LIMIT 2' ) || false !== strpos( $q, '_ebay_previous_item_id' ); } ) ), 0 );

echo "\nTHE DELTA SYNC, THE RELIST STILL LIVE\n" . str_repeat( '=', 128 ) . "\n";

// The real importer reading a window that holds both: 111 ended unsold, 222
// live. eBay's list of changes does not say what 222 was relisted from;
// GetItem does, and the delta asks it for every live listing anyway. Then
// eBay is asked once about 111, whose record names 222 as its relist.
fresh_shop();
$from = time() - HOUR_IN_SECONDS;
coin( 530, $OLD, array( '_ebay_listing_status' => 'Active' ) );
ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
relisted( $OLD, $NEW, 1, 0 );
run_delta( array( event_row( $NEW, 'Active', 1, 0, $from + 600 ), event_row( $OLD, 'Completed', 1, 0, $from - 30 * DAY_IN_SECONDS ) ), $from );
check( 'one product, following the relist', array( count( $CREATED ), meta( 530, '_ebay_item_id' ) ), array( 0, $NEW ) );
check( '  Active', meta( 530, '_ebay_listing_status' ), 'Active' );
check( '  with nothing left of the old listing\'s settle', settle_keys_left( 530 ), array() );
check( '  holding 1', stock( 530 ), 1 );
check( '  eBay asked about the relist, then once about the old listing', $EBAY['get_items'], array( $NEW, $OLD ) );

echo "\nTHE DELTA SYNC, THE RELIST ALREADY SOLD (THE NEW HOLE)\n" . str_repeat( '=', 128 ) . "\n";

// Relisted and sold between two syncs, so the sync only ever sees 222 ended.
// No product follows it, and 3.27.2 passed it over: 530 stayed in stock for a
// coin eBay had sold.
foreach ( array( 'kept' => '0', 'removed' => '1' ) as $outcome => $remove ) {
	fresh_shop( array( 'remove_sold_products' => $remove ) );
	$from = time() - HOUR_IN_SECONDS;
	coin( 531, $OLD );
	settled( 531, $OLD, 1, 0 );
	ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ) ) ) );
	relisted( $OLD, $NEW, 1, 0 );
	run_delta( array( event_row( $NEW, 'Completed', 1, 1, $from + 600 ) ), $from );
	if ( 'kept' === $outcome ) {
		check( 'eBay asked once about the relist, and once about the old listing', $EBAY['get_items'], array( $NEW, $OLD ) );
		check( '  the product follows it', meta( 531, '_ebay_item_id' ), $NEW );
		check( '  its stock settled to 0', array( stock( 531 ), meta( 531, '_stock_status' ) ), array( 0, 'outofstock' ) );
		check( '  marked ended', meta( 531, '_ebay_listing_status' ), 'Ended' );
		check( '  and the settle is on record against the relist', meta( 531, '_ebay_stock_settled_item' ), $NEW );
		// Nothing imports the relist here, so nothing else would move a SKU
		// made from the item number.
		check( '  its SKU, the old item number, is now the relist\'s', array( meta( 531, '_sku' ), meta( 531, '_ebay_sku' ) ), array( $NEW, $NEW ) );
		check( '  no second product', $CREATED, array() );
	} else {
		check( 'with "Remove sold products" on, it is removed, as any sold-out product is', trashed( 531 ), true );
		check( '  after its stock was settled to 0', stock( 531 ), 0 );
	}
}

// Live when the window was read, sold by the time the sync reached it: what
// eBay says now decides, so it is settled, not saved as live - and the answer
// already in hand is used, not asked for again.
fresh_shop( array( 'remove_sold_products' => '0' ) );
$from = time() - HOUR_IN_SECONDS;
coin( 534, $OLD );
settled( 534, $OLD, 1, 0 );
ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ) ) ) );
relisted( $OLD, $NEW, 1, 0 );
run_delta( array( event_row( $NEW, 'Active', 1, 0, $from + 600 ) ), $from );
check( 'live in the window, sold since: the product follows it', meta( 534, '_ebay_item_id' ), $NEW );
check( '  settled to 0 from what eBay says now', array( stock( 534 ), meta( 534, '_ebay_listing_status' ) ), array( 0, 'Ended' ) );
check( '  eBay asked once about the relist, and once about the old listing', $EBAY['get_items'], array( $NEW, $OLD ) );
check( '  no second product', $CREATED, array() );

echo "\nWHAT THE DELTA SYNC SPENDS ON IT\n" . str_repeat( '=', 128 ) . "\n";

// An ended listing nothing follows, which started long before the window: it
// cannot be a relist made since the last sync. Not asked about.
fresh_shop();
$from = time() - HOUR_IN_SECONDS;
coin( 532, $OLD );
run_delta( array( event_row( '440000000444', 'Completed', 1, 1, $from - 40 * DAY_IN_SECONDS ) ), $from );
check( 'an old ended listing nothing follows: eBay not asked', $EBAY['get_items'], array() );
check( '  nothing written to any product', $WRITES, array() );

// Started inside the window, but GetItem says it is no relist.
fresh_shop();
$from = time() - HOUR_IN_SECONDS;
coin( 533, $OLD );
ebay_has( '440000000444', listing( '440000000444', array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ) ) ) );
run_delta( array( event_row( '440000000444', 'Completed', 1, 1, $from + 600 ) ), $from );
check( 'a new ended listing that is no relist: one GetItem', $EBAY['get_items'], array( '440000000444' ) );
check( '  nothing written to any product', $WRITES, array() );
check( '  and nothing logged about it', log_lines_naming( '440000000444' ), array() );

// eBay's list of changes does not document RelistParentID. Should a row carry
// it all the same, it is believed whatever the start time - and kept in the
// brief copy of the window a later run reads instead of asking eBay again.
fresh_shop( array( 'remove_sold_products' => '0' ) );
$from = time() - HOUR_IN_SECONDS;
coin( 535, $OLD );
settled( 535, $OLD, 1, 0 );
ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ) ) ) );
relisted( $OLD, $NEW, 1, 0 );
run_delta( array( event_row( $NEW, 'Completed', 1, 1, $from - 40 * DAY_IN_SECONDS ) + array( 'RelistParentID' => $OLD ) ), $from );
$kept = (array) ( $KEPT[ TCGiant_Sync_Importer::DELTA_WINDOW_CACHE_OPTION ][0]['rows'][0] ?? array() );
check( 'a row that names its parent is looked at, however long ago it started', array( $EBAY['get_items'], meta( 535, '_ebay_item_id' ) ), array( array( $NEW, $OLD ), $NEW ) );
check( '  and the window kept for a later run still names it', $kept['RelistParentID'] ?? null, $OLD );

echo "\nTHE DELTA SYNC WAITS WHEN EBAY CANNOT SAY\n" . str_repeat( '=', 128 ) . "\n";

// 222 was relisted and sold between two syncs, so the sync only ever sees it
// ended, with no product. Whether it is a relist, and how 111 ended, only eBay
// can say - and here it cannot, just now. Passed by, 222's sale would never
// reach the coin: nothing reads a finished window again, and 615 would stay in
// stock for a coin eBay has sold. So the delta stops on 222 and keeps its
// place, as for any listing it could not read.
$timeout     = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$budget      = new WP_Error( 'rate_limited', 'Daily API call budget exhausted. Will resume tomorrow.', array( 'daily_budget' => true ) );
$relist_sold = listing( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ) ) );
$relist_live = listing( $NEW, array( 'RelistParentID' => $OLD ) );
foreach ( array(
	// What happened: the row's status, eBay's answers for 222 and 111, the
	// reason the pause is kept under, and what eBay was asked.
	'222 ended, and cannot be read'                      => array( 'Completed', $timeout, null, 'http_request_failed', array( $NEW ) ),
	'222 ended, out of calls asking about 111'           => array( 'Completed', $relist_sold, $budget, 'out_of_calls', array( $NEW, $OLD ) ),
	'222 live in the window, sold since, 111 timing out' => array( 'Active', $relist_sold, $timeout, 'http_request_failed', array( $NEW, $OLD ) ),
	'222 live, out of calls asking about 111'            => array( 'Active', $relist_live, $budget, 'out_of_calls', array( $NEW, $OLD ) ),
) as $what => $case ) {
	list( $row_status, $relist_answer, $old_answer, $code, $asked ) = $case;
	fresh_shop( array( 'remove_sold_products' => '0' ) );
	$from = time() - HOUR_IN_SECONDS;
	update_option( TCGiant_Sync_Importer::STATE_OPTION, array( 'last_successful_sync_at' => iso( $from ) ) );
	coin( 615, $OLD );
	settled( 615, $OLD, 1, 0 );
	ebay_has( $NEW, $relist_answer );
	if ( null !== $old_answer ) {
		ebay_has( $OLD, $old_answer );
	}
	run_delta( array( event_row( $NEW, $row_status, 1, 'Active' === $row_status ? 0 : 1, $from + 600 ) ), $from );
	$state    = TCGiant_Sync_Importer::get_sync_state();
	$progress = get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION, array() );
	check( $what . ': the delta stops on it', array( $progress['stop_item'] ?? '', $state['status'], $state['delta_paused_code'] ?? '' ), array( $NEW, 'scanning', $code ) );
	check( '  the window not marked read', $state['last_successful_sync_at'] ?? '', iso( $from ) );
	check( '  the coin left on its listing, in stock, and no product made', array( meta( 615, '_ebay_item_id' ), stock( 615 ), $CREATED ), array( $OLD, 1, array() ) );
	check( '  what eBay was asked', $EBAY['get_items'], $asked );
}

// The next run, once eBay answers, picks up where the last one stopped: the
// coin moves on to the live relist.
relisted( $OLD, $NEW, 1, 0 );
$IMP->fetch_delta_events();
check( 'once eBay answers, the next run moves the coin on to the relist', array( meta( 615, '_ebay_item_id' ), meta( 615, '_ebay_listing_status' ), $CREATED ), array( $NEW, 'Active', array() ) );
check( '  and the window is read', TCGiant_Sync_Importer::get_sync_state()['status'], 'complete' );

echo "\nTHE OLD LISTING CANNOT BE READ, RUN AFTER RUN\n" . str_repeat( '=', 128 ) . "\n";

// 222 was relisted from 111 and sold before any sync saw it. eBay answers for
// 222, and for everything else, but times out on 111 run after run. After
// DELTA_READ_STOPS runs the delta moves past 222 so the rest of the window can
// be read. The log used to say 222 could not be read, though it was read every
// time, and the stock check queued for 222 met the same wall without a word
// that 222 had sold: the coin eBay sold stayed on sale here, and nothing in
// the log named it. The same whether the window's row had 222 ended, or live
// and sold since it was read.
foreach ( array( 'Completed' => 'ended in the window', 'Active' => 'live in the window, sold since' ) as $row_status => $what ) {
	fresh_shop( array( 'remove_sold_products' => '0' ) );
	$from = time() - HOUR_IN_SECONDS;
	update_option( TCGiant_Sync_Importer::STATE_OPTION, array( 'last_successful_sync_at' => iso( $from ) ) );
	coin( 616, $OLD );
	settled( 616, $OLD, 1, 0 );
	ebay_has( $NEW, $relist_sold );
	ebay_has( $OLD, $timeout );
	run_delta( array( event_row( $NEW, $row_status, 1, 'Active' === $row_status ? 0 : 1, $from + 600 ) ), $from );
	for ( $run = 2; $run <= TCGiant_Sync_Importer::DELTA_READ_STOPS; $run++ ) {
		check( sprintf( '222 %s, run %d of %d: the delta still stops on it', $what, $run - 1, TCGiant_Sync_Importer::DELTA_READ_STOPS ), get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION, array() )['stop_item'] ?? '', $NEW );
		$IMP->fetch_delta_events();
	}
	check( sprintf( '  run %d: the delta moves past 222, naming 111 as the listing that could not be read', TCGiant_Sync_Importer::DELTA_READ_STOPS ), logged( 'Delta: eBay item ' . $NEW . ' is a relist of ' . $OLD . ', and ' . $OLD . ' could not be read ' . TCGiant_Sync_Importer::DELTA_READ_STOPS . ' runs in a row (cURL error 28: Operation timed out). Moving past ' . $NEW . ' so the rest can be read.', 'error' ), true );
	check( '  not 222, which was read every time', logged( 'eBay item ' . $NEW . ' could not be read' ), false );
	check( '  the window is read', TCGiant_Sync_Importer::get_sync_state()['status'], 'complete' );
	$settles = array_values( array_filter( $QUEUE, function ( $action ) { return 'tcgiant_sync_settle_item' === $action['hook']; } ) );
	check( '  and 222 is queued for a stock check of its own', array_column( $settles, 'args' ), array( array( $NEW ) ) );
	$QUEUE = $settles;
	$EBAY['get_items'] = array();
	$told = 'Sale check: eBay listing ' . $NEW . ' sold 1. It was relisted from ' . $OLD . ', which WC #616 follows, but ' . $OLD . ' could not be read to confirm they are the same item (cURL error 28: Operation timed out), so the stock of WC #616 is unchanged. If they are, set that stock on the website.';
	// Since 3.28.0 a wall that passes is tried again, as for the listing itself,
	// and the shop told only once the check gives up.
	$action = array_shift( $QUEUE );
	foreach ( $HOOKS[ $action['hook'] ] as $handler ) {
		call_user_func_array( $handler[0], array_slice( array_values( (array) $action['args'] ), 0, $handler[1] ) );
	}
	check( '  the stock check meets the same wall: tried again, as try 2, and nothing said yet', array( array_column( $QUEUE, 'args' ), log_lines_naming( 'could not be read to confirm' ) ), array( array( array( $NEW, 2 ) ), array() ) );
	run_queued();
	check( '  given up after the fifth: the coin stays on 111, at 1', array( meta( 616, '_ebay_item_id' ), stock( 616 ), $CREATED ), array( $OLD, 1, array() ) );
	check( '  eBay asked about 222, then 111, five times', $EBAY['get_items'], array_merge( ...array_fill( 0, 5, array( $NEW, $OLD ) ) ) );
	check( '  and then the shop is told 222 sold, naming the product, once', array( logged( $told, 'warning' ), count( log_lines_naming( 'could not be read to confirm' ) ) ), array( true, 1 ) );
	$entry = TCGiant_Sync_Importer::held_back_listings()[ $NEW ] ?? array();
	check( '  222 stays on Stock Review as the delta left it, its check giving up too, with the product of 111', array( $entry['why'] ?? null, $entry['check'] ?? null, $entry['product'] ?? null, $entry['parent'] ?? null ), array( TCGiant_Sync_Importer::HELD_UNREAD, TCGiant_Sync_Importer::HELD_SALE_CHECK, 616, $OLD ) );
}

// The same wall, but 222 ended with nothing sold: there is no sale for the
// shop to hear of, and the stock check says nothing more.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 616, $OLD );
settled( 616, $OLD, 1, 0 );
ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0' ) ) ) );
ebay_has( $OLD, $timeout );
$IMP->settle_item( $NEW );
check( 'a relist that sold nothing, 111 unreadable: the coin stays on 111, at 1', array( meta( 616, '_ebay_item_id' ), stock( 616 ), writes_to( 616 ) ), array( $OLD, 1, array() ) );
check( '  and no word of a sale', log_lines_naming( 'could not be read to confirm' ), array() );

// 222 sold, but 111 was read, and says it was not relisted as 222: a Sell
// Similar copy, not this coin. That is eBay's answer, not a wall, so there is
// nothing for the shop to check.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 616, $OLD );
settled( 616, $OLD, 1, 0 );
ebay_has( $NEW, $relist_sold );
ebay_has( $OLD, listing( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0' ) ) ) );
$IMP->settle_item( $NEW );
check( 'a copy that sold, 111 read: the coin stays on 111, with no word of a sale', array( meta( 616, '_ebay_item_id' ), stock( 616 ), log_lines_naming( 'could not be read to confirm' ) ), array( $OLD, 1, array() ) );

echo "\nSYNCED BY HAND ONCE THE OLD LISTING CAN BE READ\n" . str_repeat( '=', 128 ) . "\n";

// The coin above again: the delta moved past 222, which sold, because 111
// timed out run after run, and the stock check queued for 222 met the same
// wall. WC #616 is still on 111, in stock, for a coin eBay sold. Stock Review
// says syncing 222 by hand from the Import page tries it again - but that
// import found no product on 222, said none was made for an ended listing,
// and left the coin on sale. While 111 still times out, the hand sync can do
// no more than the delta did: nothing moves, and the log says why.
fresh_shop( array( 'remove_sold_products' => '0' ) );
$from = time() - HOUR_IN_SECONDS;
update_option( TCGiant_Sync_Importer::STATE_OPTION, array( 'last_successful_sync_at' => iso( $from ) ) );
coin( 616, $OLD );
settled( 616, $OLD, 1, 0 );
ebay_has( $NEW, $relist_sold );
ebay_has( $OLD, $timeout );
run_delta( array( event_row( $NEW, 'Completed', 1, 1, $from + 600 ) ), $from );
for ( $run = 2; $run <= TCGiant_Sync_Importer::DELTA_READ_STOPS; $run++ ) {
	$IMP->fetch_delta_events();
}
$QUEUE = array_values( array_filter( $QUEUE, function ( $action ) { return 'tcgiant_sync_settle_item' === $action['hook']; } ) );
run_queued();
check( 'the delta moved past 222 and its stock check met the wall: 616 on 111, at 1', array( meta( 616, '_ebay_item_id' ), stock( 616 ), TCGiant_Sync_Importer::get_sync_state()['status'] ), array( $OLD, 1, 'complete' ) );
$EBAY['get_items'] = array();
$LOG               = array();
$IMP->start_specific_sync( array( $NEW ) );
run_queued();
check( '111 still unreadable, 222 synced by hand: the coin stays on 111, in stock', array( meta( 616, '_ebay_item_id' ), stock( 616 ), meta( 616, '_stock_status' ), $CREATED ), array( $OLD, 1, 'instock', array() ) );
check( '  eBay asked about 222, then 111', $EBAY['get_items'], array( $NEW, $OLD ) );
check( '  the log says 111 could not be confirmed, so 616 is not moved', logged( 'eBay listing ' . $NEW . ' is a relist of ' . $OLD . ' (WC #616), but eBay could not confirm how ' . $OLD . ' ended (cURL error 28: Operation timed out): WC #616 is not moved on to it.', 'warning' ), true );
check( '  and that no product was made for the ended listing', logged( 'Import: eBay item ' . $NEW . ' ("1921 Morgan Dollar MS63") has ended (Completed), so no product was created for it.', 'warning' ), true );

// 111 reads again. The same hand sync now moves 616 on to 222 and settles it
// from eBay's answer for 222, already in hand: 0, out of stock.
relisted( $OLD, $NEW, 1, 0 );
$EBAY['get_items'] = array();
$LOG               = array();
$IMP->start_specific_sync( array( $NEW ) );
run_queued();
check( 'once 111 reads, the hand sync moves 616 on to 222', array( meta( 616, '_ebay_item_id' ), meta( 616, '_ebay_previous_item_id' ), $CREATED ), array( $NEW, $OLD, array() ) );
check( '  and settles it to 0 from 222', array( stock( 616 ), meta( 616, '_stock_status' ), meta( 616, '_ebay_listing_status' ), meta( 616, '_ebay_stock_settled_item' ) ), array( 0, 'outofstock', 'Ended', $NEW ) );
check( '  eBay asked about 222, then 111: nothing asked twice', $EBAY['get_items'], array( $NEW, $OLD ) );
check( '  the log names the import as what moved it', logged( 'Import: eBay listing ' . $NEW . ' is a relist of ' . $OLD . '; WC #616 now follows the new listing', 'success' ), true );
check( '  not that no product was made', logged( 'so no product was created' ), false );

// Synced by hand straight away, with no delta before it: the same.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 616, $OLD );
settled( 616, $OLD, 1, 0 );
ebay_has( $NEW, $relist_sold );
relisted( $OLD, $NEW, 1, 0 );
$IMP->process_item_import( $NEW );
check( 'an ended relist that sold, imported by hand: 616 follows it, settled to 0', array( meta( 616, '_ebay_item_id' ), stock( 616 ), meta( 616, '_stock_status' ), $CREATED ), array( $NEW, 0, 'outofstock', array() ) );

// 111 reads, but eBay's record of it names no relist as 222: a Sell Similar
// copy that sold, not this coin. The hand sync refuses it, as the delta does.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 616, $OLD );
settled( 616, $OLD, 1, 0 );
ebay_has( $NEW, $relist_sold );
relisted( $OLD, $THIRD, 1, 0 );
$IMP->process_item_import( $NEW );
check( 'a Sell Similar copy that sold, imported by hand: 616 stays on 111, at 1', array( meta( 616, '_ebay_item_id' ), stock( 616 ), writes_to( 616 ), $CREATED ), array( $OLD, 1, array(), array() ) );
check( '  the warning says eBay does not record it as the relist', logged( 'eBay does not record ' . $OLD . ' as relisted as ' . $NEW, 'warning' ), true );

// A listing that ended and is no relist: as before - one GetItem, no product
// made, nothing written, and the log says why.
fresh_shop();
coin( 533, $OLD );
ebay_has( '440000000444', listing( '440000000444', array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ) ) ) );
$IMP->process_item_import( '440000000444' );
check( 'a plain ended listing imported by hand: eBay asked about it only', $EBAY['get_items'], array( '440000000444' ) );
check( '  no product made, nothing written to any product', array( $CREATED, $WRITES ), array( array(), array() ) );
check( '  and the log says no product was made', logged( 'Import: eBay item 440000000444 ("1921 Morgan Dollar MS63") has ended (Completed), so no product was created for it.', 'warning' ), true );

echo "\nTHE PER-SALE STOCK CHECK\n" . str_repeat( '=', 128 ) . "\n";

// Order sync ties an eBay sale of 222 to 540 by the seller's SKU - the product
// that sold 111 - and queues a stock check for 222, the listing that sold.
// 3.27.2's check found no product on 222 and did nothing.
fresh_shop();
coin( 540, $OLD, array( '_sku' => 'C-540', '_ebay_sku' => 'C-540', '_stock' => 2 ) );
settled( 540, $OLD, 2, 0 );
ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'C-540', 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => '1' ) ) ) );
relisted( $OLD, $NEW, 2, 0 );
$EBAY['orders'] = array( 'orders' => array( array( 'orderId' => '27-14000-00001', 'lineItems' => array( array( 'lineItemId' => '10001', 'legacyItemId' => $NEW, 'sku' => 'C-540', 'quantity' => 1 ) ) ) ) );
$IMP->sync_recent_orders();
check( 'order sync queues a check of the listing that sold', array_column( $QUEUE, 'args' ), array( array( $NEW ) ) );
run_queued();
check( 'the check moves the product on to the relist', meta( 540, '_ebay_item_id' ), $NEW );
check( '  which is live', meta( 540, '_ebay_listing_status' ), 'Active' );
check( '  and brings its stock down to the 1 eBay has left', stock( 540 ), 1 );
check( '  eBay asked once about the relist, and once about the old listing', $EBAY['get_items'], array( $NEW, $OLD ) );
// The check takes the product on without importing the relist, so the import
// that next applies eBay's quantity is told what WooCommerce held before.
check( '  and the product is marked for the import that follows', meta( 540, '_ebay_relist_stock_check' ), array( 'item' => $NEW, 'parent' => $OLD, 'held' => 2, 'listed' => 2, 'sold' => 0 ) );
import( $EBAY['items'][ $NEW ]['Item'] );
check( '  which takes the mark off, with no warning: WooCommerce held more than the 1 it offers', array( meta( 540, '_ebay_relist_stock_check' ), log_lines_naming( 'WooCommerce held' ) ), array( null, array() ) );

echo "\nAN EBAY SALE WHOSE SKU THE SHOP ALSO GAVE A PRODUCT OF ITS OWN\n" . str_repeat( '=', 128 ) . "\n";

/** The shop's own product, never on eBay, under the SKU given. */
function shop_own( $id, $sku, $stock ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => 'product', 'status' => 'publish', 'product_type' => 'simple' );
	$GLOBALS['META'][ $id ]  = array( '_name' => 'Proof set #' . $id, '_sku' => $sku, '_manage_stock' => 'yes', '_stock' => $stock, '_stock_status' => 'instock' );
}
/** One eBay order, one line: a sale of listing $item_id under seller SKU $sku. */
function ebay_sale( $line_id, $item_id, $sku ) {
	$GLOBALS['EBAY']['orders'] = array( 'orders' => array( array( 'orderId' => '27-14000-' . $line_id, 'lineItems' => array( array( 'lineItemId' => $line_id, 'legacyItemId' => $item_id, 'sku' => $sku, 'quantity' => 1 ) ) ) ) );
}

// A coin shop with "Reduce WooCommerce stock on eBay sale" on, for its own
// products. WC #616 follows live listing 222, three coins, under the seller's
// SKU COIN-A. The seller changes 222's SKU on eBay to COIN-B - which the shop
// had already given WC #701, a proof set of its own that was never on eBay.
// The import keeps 616 on 222 and, since WooCommerce allows a SKU once, makes
// it COIN-B-222. Then 222 sells one: eBay's order line names SKU COIN-B and
// listing 222. 3.27.2 found 701 by that SKU, saw no listing on it, and took a
// proof set off 701 for a coin it never sold - and 616 was not checked at all.
// The sale is 222's: the stock check goes to 616, and 701 is left alone.
fresh_shop( array( 'enable_order_sync' => '1', 'remove_sold_products' => '0' ) );
shop_own( 701, 'COIN-B', 4 );
coin( 616, $NEW, array( '_sku' => 'COIN-A', '_ebay_sku' => 'COIN-A', '_ebay_listing_status' => 'Active', '_stock' => 3 ) );
$got = import( listing( $NEW, array( 'SKU' => 'COIN-B', 'Quantity' => '3' ) ) );
check( 'the import keeps 616 on 222, its SKU made unique; 701 untouched', array( $got, meta( 616, '_sku' ), stock( 616 ), $CREATED, writes_to( 701 ) ), array( 616, 'COIN-B-' . $NEW, 3, array(), array() ) );
ebay_has( $NEW, listing( $NEW, array( 'SKU' => 'COIN-B', 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '1' ) ) ) );
ebay_sale( '10002', $NEW, 'COIN-B' );
$WRITES = array();
$LOG    = array();
$IMP->sync_recent_orders();
check( 'a sale of 222 under SKU COIN-B: order sync queues a check of 222', array_column( $QUEUE, 'args' ), array( array( $NEW ) ) );
check( '  the shop\'s own 701 is not reduced; only the line is marked on it', array( stock( 701 ), writes_to( 701 ) ), array( 4, array( array( 701, '_ebay_order_processed_10002' ) ) ) );
check( '  and order sync takes nothing off 616 either', stock( 616 ), 3 );
check( '  the line is marked as handled on 616, and on 701 whose SKU it named', array( null !== meta( 616, '_ebay_order_processed_10002' ), null !== meta( 701, '_ebay_order_processed_10002' ) ), array( true, true ) );
check( '  the log counts a stock check, not a reduction', array( logged( 'Order sync complete. Processed 0 new line items; 1 eBay sales of linked products queued for a stock check.' ), logged( 'Reduced stock for WC Product' ) ), array( true, false ) );
run_queued();
check( 'the check brings 616 down to the 2 eBay has left', array( stock( 616 ), meta( 616, '_ebay_item_id' ) ), array( 2, $NEW ) );
check( '  eBay asked once, about 222', $EBAY['get_items'], array( $NEW ) );
check( '  701 still holds its 4', stock( 701 ), 4 );
$EBAY['get_items'] = array();
$WRITES            = array();
$IMP->sync_recent_orders();
check( 'the same order read again: nothing queued, neither product written', array( $QUEUE, stock( 616 ), stock( 701 ), writes_to( 701 ) ), array( array(), 2, 4, array() ) );

// Within the 24 hours order sync reads again, 616 moves on to a relist, or
// goes to the trash when 222 sells out: the line no longer goes to it. The
// mark on 701 keeps the sale from coming off 701 then.
$GLOBALS['META'][616]['_ebay_item_id'] = '160000000999';
$WRITES                                = array();
$IMP->sync_recent_orders();
check( '616 moved on to another listing: the line is not taken off 701', array( $QUEUE, stock( 701 ), writes_to( 701 ) ), array( array(), 4, array() ) );
$GLOBALS['META'][616]['_ebay_item_id'] = $NEW;
$GLOBALS['POSTS'][616]['status']       = 'trash';
$IMP->sync_recent_orders();
check( '616 in the trash: the line is not taken off 701 either', array( $QUEUE, stock( 701 ), writes_to( 701 ) ), array( array(), 4, array() ) );
$GLOBALS['POSTS'][616]['status'] = 'publish';

// What the setting is for: the shop listed something on eBay by hand under
// its own product's SKU, and no product follows that listing. The sale is
// the shop's own product's, and comes off it - once.
fresh_shop( array( 'enable_order_sync' => '1', 'remove_sold_products' => '0' ) );
shop_own( 701, 'COIN-B', 4 );
ebay_sale( '10003', $NEW, 'COIN-B' );
$IMP->sync_recent_orders();
check( 'no product follows 222: the shop\'s own 701 is reduced by the 1 sold', array( stock( 701 ), $QUEUE ), array( 3, array() ) );
check( '  and the log says so', logged( 'Reduced stock for WC Product 701 by 1 (eBay order).', 'success' ), true );
$IMP->sync_recent_orders();
check( '  read again, it is not reduced a second time', stock( 701 ), 3 );

echo "\nA STOCK CHECK HANDED THE OLD LISTING'S ANSWER\n" . str_repeat( '=', 128 ) . "\n";

// The hourly check of ended listings reads 111 and finds eBay relisted it as
// 222, which no product follows. It queues nothing: it runs the stock check of
// 222 there and then, handing on 111's answer, so eBay is not asked about 111
// a second time - here it could not have said - and the log names the check.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 617, $OLD );
settled( 617, $OLD, 1, 0 );
relisted( $OLD, $NEW, 1, 0 );
$answer = $EBAY['items'][ $OLD ]['Item'];
ebay_has( $OLD, $timeout );
ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
$IMP->settle_item( $NEW, 'Ended listing check', $answer );
check( 'handed 111\'s answer: the product moves on to the relist', array( meta( 617, '_ebay_item_id' ), meta( 617, '_ebay_listing_status' ), $CREATED ), array( $NEW, 'Active', array() ) );
check( '  eBay asked about the relist only', $EBAY['get_items'], array( $NEW ) );
check( '  and the log names the check that moved it', logged( 'Ended listing check: eBay listing ' . $NEW . ' is a relist of ' . $OLD . '; WC #617 now follows the new listing' ), true );
check( '  never the sale check', logged( 'Sale check' ), false );

// An answer about some other listing says nothing about 111: eBay is asked.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 617, $OLD );
settled( 617, $OLD, 1, 0 );
relisted( $OLD, $NEW, 1, 0 );
ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
$IMP->settle_item( $NEW, 'Ended listing check', listing( $THIRD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed' ), 'ListingDetails' => array( 'RelistedItemID' => $NEW ) ) ) );
check( 'handed an answer for another listing: eBay is asked about 111 itself', array( $EBAY['get_items'], meta( 617, '_ebay_item_id' ) ), array( array( $NEW, $OLD ), $NEW ) );

// Nothing handed on - the per-sale stock check, from the queue: eBay is asked
// about 111, and the log names the sale check.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 617, $OLD );
settled( 617, $OLD, 1, 0 );
relisted( $OLD, $NEW, 1, 0 );
ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
TCGiant_Sync_Importer::queue_settle( $NEW );
run_queued();
check( 'from the queue: eBay asked about the relist, then 111', array( $EBAY['get_items'], meta( 617, '_ebay_item_id' ) ), array( array( $NEW, $OLD ), $NEW ) );
check( '  and the log names the sale check', logged( 'Sale check: eBay listing ' . $NEW . ' is a relist of ' . $OLD . '; WC #617 now follows the new listing' ), true );

echo "\nMOVED ON WITHOUT AN IMPORT: THE IMPORT THAT FOLLOWS GIVES THE WARNING\n" . str_repeat( '=', 128 ) . "\n";

// As in the website sale above: the coin sold on the website, the plugin
// ended 111 - one listed, none sold - and the seller relisted it from eBay's
// Unsold list as 222. This time the per-sale stock check, or the inventory
// prune, meets 222 first and moves the product on to it without importing
// it. The import that later applies eBay's 1 is the one that says WooCommerce
// held 0 - once.
fresh_shop();
coin( 612, $OLD, array( '_stock' => 0, '_stock_status' => 'outofstock', '_ebay_stock_settled_at' => '2026-10-02 10:00:00', '_ebay_stock_settled_item' => $OLD ) );
relisted( $OLD, $NEW, 1, 0 );
$relist = listing( $NEW, array( 'RelistParentID' => $OLD ) );
$got    = $MAPPER->adopt_relist( $relist, 'Sale check' );
check( 'the product is moved on to the live relist', array( $got, meta( 612, '_ebay_item_id' ), meta( 612, '_ebay_listing_status' ) ), array( 612, $NEW, 'Active' ) );
check( '  marked with what WooCommerce held and how the old listing ended', meta( 612, '_ebay_relist_stock_check' ), array( 'item' => $NEW, 'parent' => $OLD, 'held' => 0, 'listed' => 1, 'sold' => 0 ) );
check( '  its stock not raised yet', stock( 612 ), 0 );
check( '  and no warning yet: eBay\'s figure has not been applied', log_lines_naming( 'WooCommerce held' ), array() );
$got = import( $relist );
check( 'the import that follows finds it by its item number', array( $got, $CREATED ), array( 612, array() ) );
check( '  applies eBay\'s 1', stock( 612 ), 1 );
check( '  warns that WooCommerce held 0', logged( 'WC #612 now follows eBay listing ' . $NEW . ', a relist of ' . $OLD . ', which offers 1; WooCommerce held 0', 'warning' ), true );
check( '  and that setting 0 ends the relist', logged( 'which ends listing ' . $NEW, 'warning' ), true );
check( '  its history says it moved on as a relist', ( meta( 612, '_tcgiant_sync_log' )[0]['decisions'] ?? array() )[0] ?? null, 'Relisted on eBay: now follows listing ' . $NEW . ' (was ' . $OLD . ')' );
check( '  the mark is taken off', meta( 612, '_ebay_relist_stock_check' ), null );
check( '  eBay asked once in all, about the old listing', $EBAY['get_items'], array( $OLD ) );
import( $relist );
check( 'a second import does not warn again', count( log_lines_naming( 'WooCommerce held' ) ), 1 );

// Five listed and five sold on 111, relisted with five more: a restock. The
// mark carries 111's figures, so the import that follows says nothing.
fresh_shop( array( 'remove_sold_products' => '0' ) );
coin( 613, $OLD, array( '_stock' => 0, '_stock_status' => 'outofstock' ) );
settled( 613, $OLD, 5, 5 );
relisted( $OLD, $NEW, 5, 5 );
$relist = listing( $NEW, array( 'RelistParentID' => $OLD, 'Quantity' => '5' ) );
$MAPPER->adopt_relist( $relist, 'Inventory pruning' );
check( 'a restock moved on by the prune: marked with 5 listed, 5 sold', meta( 613, '_ebay_relist_stock_check' ), array( 'item' => $NEW, 'parent' => $OLD, 'held' => 0, 'listed' => 5, 'sold' => 5 ) );
import( $relist );
check( '  the import that follows applies the five, with no warning, and takes the mark off', array( stock( 613 ), log_lines_naming( 'WooCommerce held' ), meta( 613, '_ebay_relist_stock_check' ) ), array( 5, array(), null ) );

// Relisted again before anything imported 222: the mark for 222 goes with the
// move on to 333, whose import gives the warning for its own move.
fresh_shop();
coin( 612, $OLD, array( '_stock' => 0, '_stock_status' => 'outofstock', '_ebay_stock_settled_at' => '2026-10-02 10:00:00', '_ebay_stock_settled_item' => $OLD ) );
relisted( $OLD, $NEW, 1, 0 );
$MAPPER->adopt_relist( listing( $NEW, array( 'RelistParentID' => $OLD ) ), 'Sale check' );
relisted( $NEW, $THIRD, 1, 0 );
$got = import( listing( $THIRD, array( 'RelistParentID' => $NEW ) ) );
check( 'moved on again, to 333: the mark for 222 is gone', array( $got, meta( 612, '_ebay_item_id' ), meta( 612, '_ebay_relist_stock_check' ) ), array( 612, $THIRD, null ) );
check( '  and one warning, for the move to 333', array( count( log_lines_naming( 'WooCommerce held' ) ), logged( 'now follows eBay listing ' . $THIRD . ', a relist of ' . $NEW . ', which offers 1; WooCommerce held 0' ) ), array( 1, true ) );

echo "\nA STOCK THE SHOP SETS AFTER THE MOVE IS THE SHOP'S OWN\n" . str_repeat( '=', 128 ) . "\n";

// Five listed on 111 and eBay sold two; the website sold the other three, so
// WooCommerce held 0 and the plugin ended 111. The seller relisted it as 222
// with three, and one sold: the per-sale stock check moves 541 on to it, and
// the mark records the 0 it held. The shop then takes in ten more and sets the
// stock to 10, which goes to 222. The import of 222 that follows offers those
// 10 - the shop's own figure. Told "WooCommerce held 0 ... set the stock to
// 0", the shop would zero ten real coins and end a live listing.
function moved_by_sale_check( $id, $held, $listed, $sold, $offered, $sold_since ) {
	coin( $id, $GLOBALS['OLD'], array( '_sku' => 'C-' . $id, '_ebay_sku' => 'C-' . $id, '_stock' => $held, '_stock_status' => $held > 0 ? 'instock' : 'outofstock' ) );
	settled( $id, $GLOBALS['OLD'], $listed, $sold );
	relisted( $GLOBALS['OLD'], $GLOBALS['NEW'], $listed, $sold );
	ebay_has( $GLOBALS['NEW'], listing( $GLOBALS['NEW'], array( 'RelistParentID' => $GLOBALS['OLD'], 'SKU' => 'C-' . $id, 'Quantity' => (string) $offered, 'SellingStatus' => array( 'QuantitySold' => (string) $sold_since ) ) ) );
	$GLOBALS['IMP']->settle_item( $GLOBALS['NEW'] );
}
fresh_shop();
moved_by_sale_check( 541, 0, 5, 2, 3, 1 );
check( 'moved on by the sale check, marked with the 0 WooCommerce held', array( meta( 541, '_ebay_item_id' ), stock( 541 ), meta( 541, '_ebay_relist_stock_check' ) ), array( $NEW, 0, array( 'item' => $NEW, 'parent' => $OLD, 'held' => 0, 'listed' => 5, 'sold' => 2 ) ) );
$META[541]['_stock']        = 10;
$META[541]['_stock_status'] = 'instock';
import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'C-541', 'Quantity' => '11', 'SellingStatus' => array( 'QuantitySold' => '1' ) ) ) );
check( 'the shop sets 10, which reaches the relist: its import offers 10, with no warning', array( stock( 541 ), log_lines_naming( 'WooCommerce held' ) ), array( 10, array() ) );
check( '  and the mark is taken off', meta( 541, '_ebay_relist_stock_check' ), null );

// Nothing changed between the move and the import: the 0 is still the stock,
// so the import offering the relist's 2 says WooCommerce held 0, as before.
fresh_shop();
moved_by_sale_check( 541, 0, 5, 2, 3, 1 );
import( $EBAY['items'][ $NEW ]['Item'] );
check( 'nothing changed in between: the import offering 2 warns that WooCommerce held 0', array( stock( 541 ), logged( 'WC #541 now follows eBay listing ' . $NEW . ', a relist of ' . $OLD . ', which offers 2; WooCommerce held 0', 'warning' ) ), array( 2, true ) );

// The other way round: the stock went down, on eBay's side. 542 held 2 on
// 111, two listed and none sold, and the relist sold one before anything
// imported it; the sale check moves 542 on and lowers it to the 1 eBay has
// left. The seller then raises 222 to offer four, on eBay. The 2 held before
// the move is what is weighed, not the 1 the check left: those were 111's two
// unsold, so nothing went missing, and nothing is said.
fresh_shop();
moved_by_sale_check( 542, 2, 2, 0, 2, 1 );
check( 'moved on by the sale check, held 2, lowered to the 1 eBay has left', array( meta( 542, '_ebay_item_id' ), stock( 542 ), meta( 542, '_ebay_relist_stock_check' )['held'] ?? null ), array( $NEW, 1, 2 ) );
import( listing( $NEW, array( 'RelistParentID' => $OLD, 'SKU' => 'C-542', 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '1' ) ) ) );
check( 'the seller raises the relist to 4 on eBay: applied, with no warning', array( stock( 542 ), log_lines_naming( 'WooCommerce held' ) ), array( 4, array() ) );

echo "\nTHE WARNING SAYS HOW MANY TO TAKE OFF\n" . str_repeat( '=', 128 ) . "\n";

// An auto-parts shop. WC #630 followed 111, which listed five and sold two;
// the seller ended it with three left. One more then sold on the website,
// after the end, so the stock push skipped the ended listing and WooCommerce
// holds 2. The seller relists 111 from Unsold as 222, offering eBay's three.
// One went missing, not all of them: told to set the stock to 0, the shop
// would zero two parts it still has and end a live listing. The units lost
// are min(left on 111, offered) - held, and that is what it is told to take
// off.
function relist_warned( $id, $held, $listed, $sold, $offered ) {
	fresh_shop( array( 'remove_sold_products' => '0' ) );
	coin( $id, $GLOBALS['OLD'], array( '_sku' => 'BRK-' . $id, '_ebay_sku' => 'BRK-' . $id, '_stock' => $held, '_stock_status' => $held > 0 ? 'instock' : 'outofstock' ) );
	settled( $id, $GLOBALS['OLD'], $listed, $sold );
	relisted( $GLOBALS['OLD'], $GLOBALS['NEW'], $listed, $sold );
	import( listing( $GLOBALS['NEW'], array( 'RelistParentID' => $GLOBALS['OLD'], 'SKU' => 'BRK-' . $id, 'Quantity' => (string) $offered ) ) );
}
function relist_warning( $id, $offers, $held, $advice ) {
	return 'WC #' . $id . ' now follows eBay listing ' . $GLOBALS['NEW'] . ', a relist of ' . $GLOBALS['OLD'] . ', which offers ' . $offers . '; WooCommerce held ' . $held . '. eBay\'s figure applies, as for any live listing. ' . $advice;
}
relist_warned( 630, 2, 5, 2, 3 );
check( 'held 2, 3 left on 111, the relist offers 3: eBay\'s 3 applies', array( meta( 630, '_ebay_item_id' ), stock( 630 ) ), array( $NEW, 3 ) );
check( '  the warning says to lower the stock by the 1 that went missing', logged( relist_warning( 630, 3, 2, 'If 1 of them sold on your website, lower the stock by 1; listing ' . $NEW . ' follows it.' ), 'warning' ), true );
check( '  never to set it to 0', logged( 'set the stock to 0' ), false );

// Held 1, 8 left of 10 on 111, the relist offers the 8: 7 went missing.
relist_warned( 631, 1, 10, 2, 8 );
check( 'held 1, 8 left on 111, the relist offers 8: lower the stock by 7', logged( relist_warning( 631, 8, 1, 'If 7 of them sold on your website, lower the stock by 7; listing ' . $NEW . ' follows it.' ), 'warning' ), true );

// Relisted with fewer than 111 had left: held 1, 8 left on 111, the relist
// offers 5. Only 4 of those 5 can be ones WooCommerce lost; lowering by 4
// leaves the 1 it held, never less.
relist_warned( 637, 1, 10, 2, 5 );
check( 'held 1, 8 left on 111, the relist offers only 5: lower the stock by 4', logged( relist_warning( 637, 5, 1, 'If 4 of them sold on your website, lower the stock by 4; listing ' . $NEW . ' follows it.' ), 'warning' ), true );

// The seller added five while relisting: the relist offers 8, and still only
// the 1 lost from the three left on 111 comes off, leaving the 7.
relist_warned( 632, 2, 5, 2, 8 );
check( 'held 2, 3 left on 111, the seller added 5 at the relist: still lower by 1', logged( relist_warning( 632, 8, 2, 'If 1 of them sold on your website, lower the stock by 1; listing ' . $NEW . ' follows it.' ), 'warning' ), true );

// Held 0, the relist offering the three left on 111: all three went missing,
// and lowering by three leaves 0, so the shop is told to set 0, as before.
relist_warned( 633, 0, 5, 2, 3 );
check( 'held 0, the relist offers the 3 left on 111: set the stock to 0, which ends it', logged( relist_warning( 633, 3, 0, 'If it sold on your website, set the stock to 0, which ends listing ' . $NEW . '.' ), 'warning' ), true );
check( '  not lower it by 3', logged( 'lower the stock by' ), false );

// Held 0, but the seller added two at the relist: the three lost come off,
// the two new ones stay.
relist_warned( 634, 0, 5, 2, 5 );
check( 'held 0, 3 left on 111, the relist offers 5: lower the stock by 3', logged( relist_warning( 634, 5, 0, 'If 3 of them sold on your website, lower the stock by 3; listing ' . $NEW . ' follows it.' ), 'warning' ), true );

// A coin: one listed, none sold, sold on the website, so WooCommerce held 0.
// Lowering by one is setting 0, so the advice is the one it always was.
fresh_shop();
coin( 635, $OLD, array( '_stock' => 0, '_stock_status' => 'outofstock', '_ebay_stock_settled_at' => '2026-10-02 10:00:00', '_ebay_stock_settled_item' => $OLD ) );
relisted( $OLD, $NEW, 1, 0 );
import( listing( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'a coin, held 0, the relist offers 1: set the stock to 0, which ends it', logged( relist_warning( 635, 1, 0, 'If it sold on your website, set the stock to 0, which ends listing ' . $NEW . '.' ), 'warning' ), true );
check( '  not lower it by 1', logged( 'lower the stock by' ), false );

// The part again, moved on by the sale check before any import: the mark
// keeps the 2 it held, and the import that follows gives the same advice.
fresh_shop();
moved_by_sale_check( 636, 2, 5, 2, 3, 0 );
check( 'moved on by the sale check, held 2: marked with the 2', meta( 636, '_ebay_relist_stock_check' )['held'] ?? null, 2 );
import( $EBAY['items'][ $NEW ]['Item'] );
check( '  the import that follows says to lower the stock by 1', logged( relist_warning( 636, 3, 2, 'If 1 of them sold on your website, lower the stock by 1; listing ' . $NEW . ' follows it.' ), 'warning' ), true );

echo "\nAN AUCTION IMPORTED FROM EBAY, RELISTED IN ANOTHER FORMAT\n" . str_repeat( '=', 128 ) . "\n";

// A card shop with Import eBay auctions on. WC #640 was imported from auction
// 111, so its price is the opening bid, $0.99, and once 111 ended unsold it
// stayed off sale here. The seller relisted 111 as a Buy It Now listing at
// $50, 222, and it ended unsold before any import saw it. The per-sale stock
// check meets 222 first. Moved on to a fixed-price listing, the card was no
// longer an auction, so it could be bought here at $0.99: only an import of
// 222 writes 222's price. It stays on 111, off sale.
function auction_card( $id ) {
	coin( $id, $GLOBALS['OLD'], array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_duration' => 'Days_7', '_regular_price' => '0.99' ) );
	settled( $id, $GLOBALS['OLD'], 1, 0 );
	relisted( $GLOBALS['OLD'], $GLOBALS['NEW'], 1, 0 );
	$GLOBALS['EBAY']['items'][ $GLOBALS['OLD'] ]['Item']['ListingType'] = 'Chinese';
}
function buy_it_now_relist( $status, $sold ) {
	return listing( $GLOBALS['NEW'], array( 'RelistParentID' => $GLOBALS['OLD'], 'SellingStatus' => array( 'ListingStatus' => $status, 'QuantitySold' => (string) $sold, 'CurrentPrice' => '50.00' ) ) );
}
fresh_shop( array( 'remove_sold_products' => '0', 'import_auctions' => '1' ) );
auction_card( 640 );
ebay_has( $NEW, buy_it_now_relist( 'Completed', 0 ) );
$IMP->settle_item( $NEW );
check( 'relisted at a fixed price, ended unsold: the sale check leaves the card on 111', array( meta( 640, '_ebay_item_id' ), meta( 640, '_ebay_listing_type' ), $CREATED ), array( $OLD, 'Chinese', array() ) );
check( '  nothing written to it', writes_to( 640 ), array() );
check( '  still off sale', TCGiant_Sync_Inventory::filter_purchasable( true, wc_get_product( 640 ) ), false );
check( '  eBay asked about 222, then 111', $EBAY['get_items'], array( $NEW, $OLD ) );
check( '  the log says it stays on its auction, off sale here, and how to sell it', logged( 'Sale check: eBay listing ' . $NEW . ' is a relist of auction ' . $OLD . ' in another format, and has ended. WC #640, imported from that auction, stays on it, off sale here' ), true );

// The same relist sold out: nothing is left to sell at any price, so the card
// is moved on and settled to 0 from 222, like any relist that sold out.
fresh_shop( array( 'remove_sold_products' => '0', 'import_auctions' => '1' ) );
auction_card( 641 );
ebay_has( $NEW, buy_it_now_relist( 'Completed', 1 ) );
$IMP->settle_item( $NEW );
check( 'the fixed-price relist sold out: the sale check moves the card on to it', array( meta( 641, '_ebay_item_id' ), meta( 641, '_ebay_listing_type' ), $CREATED ), array( $NEW, 'FixedPriceItem', array() ) );
check( '  settled to 0 from 222', array( stock( 641 ), meta( 641, '_stock_status' ), meta( 641, '_ebay_listing_status' ), meta( 641, '_ebay_stock_settled_item' ) ), array( 0, 'outofstock', 'Ended', $NEW ) );
check( '  not left for an import', logged( 'in another format' ), false );

// Still live at $50: left on 111 and off sale until the import of 222 moves
// it, and writes the $50 in the same save.
fresh_shop( array( 'remove_sold_products' => '0', 'import_auctions' => '1' ) );
auction_card( 642 );
$live_fixed = buy_it_now_relist( 'Active', 0 );
ebay_has( $NEW, $live_fixed );
$IMP->settle_item( $NEW );
check( 'relisted at a fixed price, still live: the sale check leaves the card on 111, off sale', array( meta( 642, '_ebay_item_id' ), writes_to( 642 ), TCGiant_Sync_Inventory::filter_purchasable( true, wc_get_product( 642 ) ) ), array( $OLD, array(), false ) );
import( $live_fixed );
check( '  the import of 222 then moves it, at 222\'s own price', array( meta( 642, '_ebay_item_id' ), meta( 642, '_ebay_listing_type' ), (float) meta( 642, '_regular_price' ) ), array( $NEW, 'FixedPriceItem', 50.0 ) );

// The shop's own auction, listed from WooCommerce - the mark names 111 - put
// up again at a fixed price: its price is the shop's, so it is moved on.
fresh_shop( array( 'remove_sold_products' => '0', 'import_auctions' => '1' ) );
auction_card( 643 );
$META[643][ TCGiant_Sync_Inventory::AUCTION_LISTED_KEY ] = $OLD;
ebay_has( $NEW, $live_fixed );
$IMP->settle_item( $NEW );
check( 'the shop\'s own auction relisted at a fixed price: moved on to it', array( meta( 643, '_ebay_item_id' ), meta( 643, '_ebay_listing_type' ) ), array( $NEW, 'FixedPriceItem' ) );

// An imported auction relisted as an auction: still an auction, still off
// sale while it runs, so it is moved on as before.
fresh_shop( array( 'remove_sold_products' => '0', 'import_auctions' => '1' ) );
auction_card( 644 );
ebay_has( $NEW, listing( $NEW, array( 'RelistParentID' => $OLD, 'ListingType' => 'Chinese', 'ListingDuration' => 'Days_7' ) ) );
$IMP->settle_item( $NEW );
check( 'an imported auction relisted as an auction: moved on to it', array( meta( 644, '_ebay_item_id' ), meta( 644, '_ebay_listing_type' ) ), array( $NEW, 'Chinese' ) );
check( '  not left for an import', logged( 'in another format' ), false );

echo "\nA STOCK LOWERED AFTER A MOVE: KEPT ON AN AUCTION ONLY\n" . str_repeat( '=', 128 ) . "\n";

// The sale check (or the hourly check, or the prune) moved WC #650 on to live
// relist 222 while it held its one item, and the shop then lowered the stock
// to 0. On an auction with bids the stock push could not reach 222, so the
// import that follows keeps the 0 rather than put the item back on sale. A
// fixed-price relist is not an auction: the push reaches it, and eBay's
// figure applies at the import, as for any listing.
foreach ( array(
	'Chinese'        => array( 0, 'outofstock', 'Stock kept at 0: WooCommerce held less than the auction, which is one item', 'an auction' ),
	'FixedPriceItem' => array( 1, 'instock', 'Stock synced to 1 (eBay won)', 'a fixed-price relist' ),
) as $format => $want ) {
	list( $want_stock, $want_status, $want_line, $what ) = $want;
	fresh_shop( array( 'remove_sold_products' => '0', 'import_auctions' => '1' ) );
	coin( 650, $OLD, array( '_ebay_listing_type' => $format, TCGiant_Sync_Inventory::AUCTION_LISTED_KEY => $OLD ) );
	settled( 650, $OLD, 1, 0 );
	relisted( $OLD, $NEW, 1, 0 );
	$relist = listing( $NEW, array( 'RelistParentID' => $OLD, 'ListingType' => $format, 'ListingDuration' => 'Chinese' === $format ? 'Days_7' : 'GTC' ) );
	ebay_has( $NEW, $relist );
	$IMP->settle_item( $NEW );
	check( sprintf( '%s: moved on by the sale check, marked with the 1 it held', $what ), array( meta( 650, '_ebay_item_id' ), meta( 650, '_ebay_relist_stock_check' )['held'] ?? null ), array( $NEW, 1 ) );
	$META[650]['_stock']        = 0;
	$META[650]['_stock_status'] = 'outofstock';
	import( $relist );
	$decisions = ( meta( 650, '_tcgiant_sync_log' )[0]['decisions'] ?? array() );
	check( sprintf( '  the shop lowers it to 0; the import that follows leaves %d', $want_stock ), array( stock( 650 ), meta( 650, '_stock_status' ) ), array( $want_stock, $want_status ) );
	check( '  and its history says so', in_array( $want_line, $decisions, true ), true );
	check( '  with no warning about what WooCommerce held', log_lines_naming( 'WooCommerce held' ), array() );
}

echo "\nA RELIST ON ANOTHER EBAY SITE MOVES NO PRODUCT, WHEN THE SHOP IMPORTS ONLY ITS OWN (3.27.5 WITH 3.28.0)\n" . str_repeat( '=', 128 ) . "\n";

// "Import listings from: only my Primary eBay Marketplace" (3.27.5) on a shop
// whose coin was imported from a Web Interpret copy before the setting was
// chosen: the copy keeps syncing, but 3.28.0's relist adoption must not take
// it on to that copy's relist on eBay Germany, by any of its paths.
$ONLY_US = array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' );
$DE_NEW  = '220000000777';

// The import (save_as_product): left out before eBay is asked about the old listing.
fresh_shop( $ONLY_US );
coin( 591, $OLD, array( '_ebay_site' => 'Germany' ) );
settled( 591, $OLD, 1, 0 );
relisted( $OLD, $DE_NEW, 1, 0 );
$got = import( listing( $DE_NEW, array( 'RelistParentID' => $OLD, 'Site' => 'Germany' ) ) );
check( 'imported: a relist on eBay Germany is left out', array( $got, $CREATED ), array( false, array() ) );
check( '  the product stays on the listing it was on', meta( 591, '_ebay_item_id' ), $OLD );
check( '  and eBay is not asked about the old listing - the site decides first', $EBAY['get_items'], array() );
check( '  said as any other site\'s listing is', logged( 'Skipped eBay item ' . $DE_NEW ), true );
// The shop's own site, the same relist: moved on, as before.
fresh_shop( $ONLY_US );
coin( 592, $OLD, array( '_ebay_site' => 'US' ) );
settled( 592, $OLD, 1, 0 );
relisted( $OLD, $NEW, 1, 0 );
check( '  a relist on eBay United States: moved on, as before', array( import( listing( $NEW, array( 'RelistParentID' => $OLD, 'Site' => 'US' ) ) ), meta( 592, '_ebay_item_id' ) ), array( 592, $NEW ) );

// The delta, meeting the relist ended (adopt_unseen_relist): a row with no
// site, so eBay is asked about it, and its answer says eBay Germany.
fresh_shop( $ONLY_US );
coin( 593, $OLD, array( '_ebay_site' => 'Germany' ) );
settled( 593, $OLD, 1, 0 );
relisted( $OLD, $DE_NEW, 1, 0 );
ebay_has( $DE_NEW, listing( $DE_NEW, array( 'RelistParentID' => $OLD, 'Site' => 'Germany', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ) ) ) );
$from = time() - 3600;
run_delta( array( event_row( $DE_NEW, 'Completed', 1, 1, $from + 600 ) ), $from );
check( 'the delta: an ended relist on eBay Germany moves no product', array( meta( 593, '_ebay_item_id' ), stock( 593 ), $CREATED ), array( $OLD, 1, array() ) );
check( '  saying why', logged( 'a relist of ' . $OLD . ', is on eBay Germany, and Import listings from is set to eBay United States only, so no product is moved on to it' ), true );

// The per-sale stock check (settle_item), and the hourly check's call of it.
fresh_shop( $ONLY_US );
coin( 594, $OLD, array( '_ebay_site' => 'Germany' ) );
settled( 594, $OLD, 1, 0 );
relisted( $OLD, $DE_NEW, 1, 0 );
ebay_has( $DE_NEW, listing( $DE_NEW, array( 'RelistParentID' => $OLD, 'Site' => 'Germany', 'SellingStatus' => array( 'QuantitySold' => '1' ) ) ) );
$IMP->settle_item( $DE_NEW );
check( 'the sale check: a live relist on eBay Germany moves no product', array( meta( 594, '_ebay_item_id' ), stock( 594 ) ), array( $OLD, 1 ) );
check( '  the old listing not asked about either', in_array( $OLD, $EBAY['get_items'], true ), false );
$IMP->settle_item( $DE_NEW, 'Ended listing check', listing( $OLD, array( 'Site' => 'Germany', 'SellingStatus' => array( 'ListingStatus' => 'Completed' ), 'ListingDetails' => array( 'RelistedItemID' => $DE_NEW ) ) ) );
check( '  nor when the hourly check hands over the old listing\'s answer', meta( 594, '_ebay_item_id' ), $OLD );
// All eBay sites, as every shop has by default: the copy's relist is followed.
fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
coin( 595, $OLD, array( '_ebay_site' => 'Germany' ) );
settled( 595, $OLD, 1, 0 );
relisted( $OLD, $DE_NEW, 1, 0 );
ebay_has( $DE_NEW, listing( $DE_NEW, array( 'RelistParentID' => $OLD, 'Site' => 'Germany' ) ) );
$IMP->settle_item( $DE_NEW );
check( 'all eBay sites: the same relist is followed, as in 3.28.0 before the merge', meta( 595, '_ebay_item_id' ), $DE_NEW );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
