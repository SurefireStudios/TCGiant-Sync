<?php
/**
 * The world tests/test-delta-renewal.php runs in: a WordPress shop as far as
 * the delta, the import and the hourly ended-listing check use it, with the
 * plugin's own classes loaded for real. Shared with
 * tests/support/renewal-kill.php, which runs one request of it in a PHP
 * process of its own and kills it.
 *
 * Products are rows of post meta; the database answers the plugin's queries
 * from them by what each query's SQL says. eBay is the real API class with
 * GetItem and GetSellerEvents answered from a script cut to what eBay
 * documents: GetSellerEvents sends no specifics, pictures, SKU or category,
 * and no Quantity for a listing of one.
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__, 2 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$UPLOADS = sys_get_temp_dir() . '/tcg-renewal-harness-' . getmypid();
if ( ! is_dir( $UPLOADS ) ) {
	mkdir( $UPLOADS, 0777, true );
}

$POSTS      = array(); // id => array( 'type', 'status', 'product_type' )
$META       = array(); // id => array( key => value )
$ROWS       = array(); // the Listings table: product id => row
$OPTIONS    = array();
$TRANSIENTS = array();
$LOG        = array();
$WRITES     = array(); // every post meta write: id, key
$SAVES      = array(); // product id => WooCommerce saves
$QUEUE      = array();
$HOOKS      = array();
$EBAY       = array( 'connected' => true, 'at_ceiling' => false, 'oos' => 'false', 'prefs' => array() );
$AUTOLOAD   = array(); // option => the autoload it was written with
$NEXT_ID    = 5000;
$SAVE_FAILS = array(); // product ids whose next save returns 0
$SAVE_THROWS = array(); // product ids whose next save throws: an exception, or 'error' an Error
$KILL_ON    = null;     // renewal-kill.php: called before each meta and option write

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

/** The database, as far as these paths ask it anything. */
class Harness_Wpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $options  = 'wp_options';
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
		$out = array();
		foreach ( $GLOBALS['POSTS'] as $id => $post ) {
			if ( (string) ( $GLOBALS['META'][ $id ][ $m[1] ] ?? "\0" ) !== $m[2] || 'product' !== $post['type'] ) {
				continue;
			}
			if ( false !== strpos( $query, "post_status != 'trash'" ) && 'trash' === $post['status'] ) {
				continue;
			}
			$out[] = $id;
		}
		if ( false !== strpos( $query, "( p.post_status = 'trash' ) ASC" ) ) {
			usort( $out, function ( $a, $b ) {
				return (int) ( 'trash' === $GLOBALS['POSTS'][ $a ]['status'] ) - (int) ( 'trash' === $GLOBALS['POSTS'][ $b ]['status'] );
			} );
		}
		return $out;
	}
	public function get_row( $query ) {
		$ids = $this->matching( $query );
		return empty( $ids ) ? null : (object) array( 'post_id' => (string) $ids[0], 'post_status' => $GLOBALS['POSTS'][ $ids[0] ]['status'] );
	}
	public function get_var( $query ) {
		if ( false === strpos( $query, "'_ebay_item_id'" ) ) {
			return null;
		}
		$ids = $this->matching( $query );
		return empty( $ids ) ? null : (string) $ids[0];
	}
	public function get_col( $query ) { return array(); }
	/** The hourly ended-listing check's list: end time passed, not marked Ended. */
	public function get_results( $query, $output = null ) {
		if ( false === strpos( $query, "pm_end.meta_key = '_ebay_end_time'" ) || ! preg_match( "/pm_end\\.meta_value < '([^']+)'/", $query, $m ) ) {
			return array();
		}
		$rows = array();
		foreach ( $GLOBALS['POSTS'] as $id => $post ) {
			$meta = $GLOBALS['META'][ $id ] ?? array();
			$end  = (string) ( $meta['_ebay_end_time'] ?? '' );
			if ( 'product' === $post['type'] && 'trash' !== $post['status'] && '' !== $end && $end < $m[1]
				&& 'Ended' !== ( $meta['_ebay_listing_status'] ?? null ) ) {
				$rows[] = (object) array( 'ID' => (string) $id, 'duration' => $meta['_ebay_listing_duration'] ?? null );
			}
		}
		return $rows;
	}
	public function query( $query ) { return 0; }
	public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
	public function delete( $table, $where = array(), $format = null ) { return 0; }
}
$wpdb = new Harness_Wpdb();

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function _n( $single, $plural, $n ) { return 1 === (int) $n ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['HOOKS'][ $hook ][] = $callback; return true; }
function remove_action( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $i => $cb ) {
		if ( $cb === $callback ) {
			unset( $GLOBALS['HOOKS'][ $hook ][ $i ] );
		}
	}
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
// A filter a test puts in $FILTERS (hook => callable) is applied; any other
// hands back its value.
function apply_filters( $hook, $value, ...$args ) {
	return isset( $GLOBALS['FILTERS'][ $hook ] ) ? call_user_func_array( $GLOBALS['FILTERS'][ $hook ], array_merge( array( $value ), $args ) ) : $value;
}
function do_action( $hook, ...$args ) {}
function did_action( $hook ) { return 0; }
function wp_clear_scheduled_hook() {}
function wp_schedule_single_event() { return true; }
function current_time( $type, $gmt = 0 ) { return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ); }
function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) { return gmdate( $format, strtotime( $date . ' UTC' ) ); }
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
	return $bytes;
}
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['UPLOADS'] ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function sanitize_title( $title ) { return strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $title ) ); }
function wc_attribute_label( $name ) { return $name; }
function wc_format_decimal( $number, $dp = false ) { return false === $dp ? (string) $number : number_format( (float) $number, (int) $dp, '.', '' ); }
function wp_set_object_terms() { return array(); }
function has_term() { return false; }
function wp_cache_delete() { return true; }

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	if ( null !== $GLOBALS['KILL_ON'] ) {
		call_user_func( $GLOBALS['KILL_ON'], 'option', 0, $name );
	}
	$GLOBALS['AUTOLOAD'][ $name ] = $autoload;
	$GLOBALS['OPTIONS'][ $name ]  = $value;
	return true;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( ! array_key_exists( $name, $GLOBALS['OPTIONS'] ) ) {
		$GLOBALS['OPTIONS'][ $name ] = $value;
	}
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['OPTIONS'][ $name ] );
	return true;
}
function get_transient( $name ) { return $GLOBALS['TRANSIENTS'][ $name ] ?? false; }
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $name ] = $value; return true; }
function delete_transient( $name ) { unset( $GLOBALS['TRANSIENTS'][ $name ] ); return true; }

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
/**
 * As WordPress's, with the plugin's own listener on it: the Listings row
 * follows the product's status, item and end time (TCGiant_Sync_DB's
 * mirror_meta_to_row(), existing rows only).
 */
function update_post_meta( $id, $key, $value ) {
	$id = (int) $id;
	if ( null !== $GLOBALS['KILL_ON'] ) {
		call_user_func( $GLOBALS['KILL_ON'], 'meta', $id, $key );
	}
	$GLOBALS['WRITES'][] = array( $id, $key );
	$GLOBALS['META'][ $id ][ $key ] = $value;
	$columns = array( '_ebay_listing_status' => 'listing_status', '_ebay_item_id' => 'ebay_item_id', '_ebay_end_time' => 'ebay_end_time', '_ebay_start_time' => 'ebay_start_time', '_ebay_listing_type' => 'listing_type' );
	if ( isset( $columns[ $key ], $GLOBALS['ROWS'][ $id ] ) && ! is_array( $value ) && ! TCGiant_Sync_DB::$mirroring ) {
		$GLOBALS['ROWS'][ $id ][ $columns[ $key ] ] = (string) $value;
	}
	return true;
}
function delete_post_meta( $id, $key ) {
	if ( isset( $GLOBALS['META'][ (int) $id ][ $key ] ) ) {
		$GLOBALS['WRITES'][] = array( (int) $id, '-' . $key );
	}
	unset( $GLOBALS['META'][ (int) $id ][ $key ] );
	return true;
}
function wp_trash_post( $id ) { $GLOBALS['POSTS'][ (int) $id ]['status'] = 'trash'; return true; }
function get_post_thumbnail_id( $id ) { return (int) ( $GLOBALS['META'][ (int) $id ]['_thumbnail_id'] ?? 0 ); }
function has_post_thumbnail( $id ) { return get_post_thumbnail_id( $id ) > 0; }

// WooCommerce's product: its fields live in the same meta the plugin reads
// directly; a set_*() is held until save().
class WC_Product {
	protected $id      = 0;
	protected $changes = array();
	protected $deleted = array();
	protected $kind    = 'simple';
	public function __construct( $id = 0 ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_type() { return $this->kind; }
	public function is_type( $type ) { return $type === $this->kind; }
	protected function field( $key, $default = '' ) {
		if ( array_key_exists( $key, $this->changes ) ) {
			return $this->changes[ $key ];
		}
		if ( isset( $this->deleted[ $key ] ) ) {
			return $default;
		}
		return $GLOBALS['META'][ $this->id ][ $key ] ?? $default;
	}
	public function get_meta( $key, $single = true ) { return $this->field( $key ); }
	public function update_meta_data( $key, $value ) { unset( $this->deleted[ $key ] ); $this->changes[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->changes[ $key ] ); $this->deleted[ $key ] = true; }
	public function get_sku() { return (string) $this->field( '_sku' ); }
	public function set_sku( $sku ) { $this->changes['_sku'] = (string) $sku; }
	public function get_name() { return (string) $this->field( '_name' ); }
	public function set_name( $name ) { $this->changes['_name'] = $name; }
	public function set_description( $text ) { $this->changes['_description'] = $text; }
	public function managing_stock() { return 'yes' === $this->field( '_manage_stock', 'no' ); }
	public function set_manage_stock( $on ) { $this->changes['_manage_stock'] = $on ? 'yes' : 'no'; }
	public function get_stock_quantity() { return $this->managing_stock() ? (int) $this->field( '_stock', 0 ) : null; }
	public function set_stock_quantity( $quantity ) { $this->changes['_stock'] = (int) $quantity; }
	public function get_stock_status() { return (string) $this->field( '_stock_status', 'instock' ); }
	public function is_in_stock() { return 'outofstock' !== $this->get_stock_status(); }
	public function get_regular_price() { return (string) $this->field( '_regular_price' ); }
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
	public function get_children() { return array(); }
	public function get_parent_id() { return 0; }
	public function save() {
		if ( ! $this->id ) {
			$this->id = $GLOBALS['NEXT_ID']++;
			$GLOBALS['POSTS'][ $this->id ] = array( 'type' => 'product', 'status' => 'publish', 'product_type' => $this->kind );
		}
		if ( ! empty( $GLOBALS['SAVE_THROWS'][ $this->id ] ) ) {
			$kind = $GLOBALS['SAVE_THROWS'][ $this->id ];
			unset( $GLOBALS['SAVE_THROWS'][ $this->id ] );
			if ( 'error' === $kind ) {
				throw new Error( 'Call to undefined function in another plugin\'s save hook' );
			}
			throw new RuntimeException( 'another plugin\'s save hook failed' );
		}
		if ( ! empty( $GLOBALS['SAVE_FAILS'][ $this->id ] ) ) {
			unset( $GLOBALS['SAVE_FAILS'][ $this->id ] );
			return 0;
		}
		$GLOBALS['SAVES'][ $this->id ] = ( $GLOBALS['SAVES'][ $this->id ] ?? 0 ) + 1;
		foreach ( $this->changes as $key => $value ) {
			if ( '__status' === $key ) {
				$GLOBALS['POSTS'][ $this->id ]['status'] = $value;
				continue;
			}
			$GLOBALS['WRITES'][] = array( $this->id, $key );
			$GLOBALS['META'][ $this->id ][ $key ] = $value;
		}
		foreach ( array_keys( $this->deleted ) as $key ) {
			unset( $GLOBALS['META'][ $this->id ][ $key ] );
		}
		$this->changes = array();
		$this->deleted = array();
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
// WooCommerce's exception for a value a product of its type refuses.
class WC_Data_Exception extends Exception {}
// An external and a grouped product: neither holds stock, and WooCommerce
// throws when asked to manage it, as WC_Product_External and
// WC_Product_Grouped do.
class WC_Product_External extends WC_Product {
	protected $kind = 'external';
	public function set_manage_stock( $on ) {
		if ( $on ) {
			throw new WC_Data_Exception( 'External products cannot be stock managed.' );
		}
	}
}
class WC_Product_Grouped extends WC_Product {
	protected $kind = 'grouped';
	public function set_manage_stock( $on ) {
		if ( $on ) {
			throw new WC_Data_Exception( 'Grouped products cannot be stock managed.' );
		}
	}
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
	public function get_position() { return $this->d['position']; }
	public function is_taxonomy() { return false; }
}
function wc_get_product( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['POSTS'][ $id ] ) ) {
		return false;
	}
	$classes = array( 'variable' => 'WC_Product_Variable', 'external' => 'WC_Product_External', 'grouped' => 'WC_Product_Grouped' );
	$class   = $classes[ $GLOBALS['POSTS'][ $id ]['product_type'] ?? 'simple' ] ?? 'WC_Product_Simple';
	return new $class( $id );
}
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
	$now = (int) ( $GLOBALS['META'][ $id ]['_stock'] ?? 0 );
	$now = 'decrease' === $operation ? $now - (int) $quantity : ( 'increase' === $operation ? $now + (int) $quantity : (int) $quantity );
	$GLOBALS['WRITES'][] = array( $id, '_stock' );
	$GLOBALS['META'][ $id ]['_stock']        = $now;
	$GLOBALS['META'][ $id ]['_stock_status'] = $now > 0 ? 'instock' : 'outofstock';
	return $now;
}
function wc_update_product_stock_status( $id, $status ) {
	$GLOBALS['META'][ (int) $id ]['_stock_status'] = $status;
}

// Action Scheduler, as the delta uses it.
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	$GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group );
	return count( $GLOBALS['QUEUE'] );
}
function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) { return as_enqueue_async_action( $hook, $args, $group ); }
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['QUEUE'] as $action ) {
		if ( $action['hook'] === $hook && ( null === $args || $action['args'] === $args ) ) {
			return true;
		}
	}
	return false;
}
function as_get_scheduled_actions( $args, $return = 'objects' ) { return array(); }
function as_unschedule_all_actions( $hook ) {
	$GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) use ( $hook ) { return $a['hook'] !== $hook; } ) );
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
	public function get_access_token() { return $GLOBALS['EBAY']['connected'] ? 'harness-token' : ''; }
	public function is_authenticated() { return $GLOBALS['EBAY']['connected']; }
}
/** The Listings table: one row per product, written column by column. */
class TCGiant_Sync_DB {
	public static $mirroring = false;
	public static function table_exists() { return true; }
	public static function table_name() { return 'wp_tcgiant_listings'; }
	public static function upsert( $data ) {
		$id = (int) ( $data['product_id'] ?? 0 );
		if ( ! $id ) {
			return false;
		}
		unset( $data['product_id'] );
		$GLOBALS['ROWS'][ $id ] = array_merge( $GLOBALS['ROWS'][ $id ] ?? array( 'product_id' => $id, 'listing_status' => 'Active' ), $data );
		self::$mirroring = true;
		foreach ( array( 'ebay_item_id' => '_ebay_item_id', 'listing_type' => '_ebay_listing_type', 'listing_status' => '_ebay_listing_status' ) as $column => $key ) {
			if ( ! empty( $data[ $column ] ) ) {
				update_post_meta( $id, $key, $data[ $column ] );
			}
		}
		self::$mirroring = false;
		return $id;
	}
	public static function get_by_product( $id ) { return $GLOBALS['ROWS'][ (int) $id ] ?? null; }
}
class TCGiant_Sync_Entitlements {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function can_import() { return true; }
	public function get_active_product_count() { return 0; }
	public function get_free_limit() { return 0; }
}
/**
 * The image localiser: a product's photos are downloaded when it has a real
 * featured image and the localiser has marked it done - which these products
 * are, unless a test says otherwise.
 */
class TCGiant_Sync_Image_Localizer {
	public static function set_external_images( $product_id, $images ) { $GLOBALS['PHOTO_SETS'][] = (int) $product_id; }
	public static function has_local_thumbnail( $product_id ) {
		$meta = $GLOBALS['META'][ (int) $product_id ] ?? array();
		return ! empty( $meta['_thumbnail_id'] ) && empty( $meta['_harness_placeholder'] );
	}
}

define( 'TCGIANT_SYNC_IS_STAGING', false );
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $ROOT . '/includes/class-tcgiant-sync-mapper.php';
require $ROOT . '/includes/class-tcgiant-sync-importer.php';
require $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require $ROOT . '/includes/class-tcgiant-sync-cron.php';

/**
 * eBay: the real API class, with GetItem and GetSellerEvents answered from a
 * script. GetSellerEvents answers for the window that starts at $EBAY['from'],
 * and that window only; later ones are quiet.
 */
class Scripted_eBay extends TCGiant_Sync_API {
	public function get_item( $item_id ) {
		$GLOBALS['EBAY']['get_items'][] = (string) $item_id;
		$answer = $GLOBALS['EBAY']['answers'][ (string) $item_id ] ?? null;
		if ( null !== $answer ) {
			return $answer;
		}
		$item = $GLOBALS['EBAY']['items'][ (string) $item_id ] ?? null;
		return null === $item ? new WP_Error( 'api_error', 'The item could not be found.' ) : array( 'Ack' => 'Success', 'Item' => $item );
	}
	public function get_seller_events( $from, $to, $max_body_bytes = 0 ) {
		$GLOBALS['EBAY']['event_reads']++;
		if ( isset( $GLOBALS['EBAY']['events_answer'] ) ) {
			return $GLOBALS['EBAY']['events_answer'];
		}
		// When each listing last changed, when a test says: every window
		// holds the listings whose last change falls in it, as eBay's does.
		if ( ! empty( $GLOBALS['EBAY']['mod'] ) ) {
			$GLOBALS['EBAY']['windows'][] = array( $from, $to );
			$rows = array();
			foreach ( $GLOBALS['EBAY']['mod'] as $id => $at ) {
				if ( strtotime( $from ) <= $at && $at <= strtotime( $to ) ) {
					$rows[] = event_row( $GLOBALS['EBAY']['items'][ (string) $id ] );
				}
			}
			return array( 'Ack' => 'Success', 'ItemArray' => empty( $rows ) ? array() : array( 'Item' => $rows ) );
		}
		if ( $from !== ( $GLOBALS['EBAY']['from'] ?? '' ) ) {
			return array( 'Ack' => 'Success', 'ItemArray' => array() );
		}
		$rows = array();
		foreach ( $GLOBALS['EBAY']['window'] as $id ) {
			$rows[] = array_replace( event_row( $GLOBALS['EBAY']['items'][ $id ] ), $GLOBALS['EBAY']['event_over'][ $id ] ?? array() );
		}
		return array( 'Ack' => 'Success', 'ItemArray' => empty( $rows ) ? array() : array( 'Item' => $rows ) );
	}
	public function get_official_time() { return array( 'Ack' => 'Success' ); }
	public function get_remaining_daily_budget() { return 40000; }
	public function is_daily_budget_exhausted() { return $GLOBALS['EBAY']['at_ceiling']; }
	/**
	 * Everything else that would go out: GetUserPreferences is answered as
	 * $EBAY['oos'] says - 'true', 'false', '' (eBay leaves the field out) or
	 * a WP_Error - and recorded with its request.
	 */
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$GLOBALS['EBAY']['wire'][] = $call_name;
		if ( 'GetUserPreferences' === $call_name ) {
			$GLOBALS['EBAY']['prefs'][] = $xml_body;
			$oos = $GLOBALS['EBAY']['oos'];
			if ( is_wp_error( $oos ) ) {
				return $oos;
			}
			return '' === $oos ? array( 'Ack' => 'Success' ) : array( 'Ack' => 'Success', 'OutOfStockControlPreference' => $oos );
		}
		return new WP_Error( 'harness', 'unexpected eBay call ' . $call_name );
	}
}

/** GetSellerEvents' cut of a listing: eBay's documented ReturnAll fields only. */
function event_row( array $item ) {
	$row = array(
		'ItemID'         => $item['ItemID'],
		'Title'          => $item['Title'],
		'ListingType'    => $item['ListingType'],
		'ListingDetails' => array_intersect_key( $item['ListingDetails'], array_flip( array( 'StartTime', 'EndTime' ) ) ),
		'SellingStatus'  => array_intersect_key( $item['SellingStatus'], array_flip( array( 'CurrentPrice', 'QuantitySold', 'ListingStatus' ) ) ),
	);
	// "Quantity is only returned for listings where item quantity is greater than 1."
	if ( (int) $item['Quantity'] > 1 ) {
		$row['Quantity'] = $item['Quantity'];
	}
	if ( isset( $item['Variations'] ) ) {
		$row['Variations'] = $item['Variations'];
	}
	return $row;
}

function use_ebay( $api ) {
	$slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
	if ( PHP_VERSION_ID < 80100 ) {
		$slot->setAccessible( true );
	}
	$slot->setValue( null, $api );
	return $api;
}
use_ebay( new Scripted_eBay() );

function call_private( $object_or_class, $method, ...$args ) {
	$m = new ReflectionMethod( $object_or_class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invokeArgs( is_object( $object_or_class ) ? $object_or_class : null, $args );
}

/** Forget what a static remembered for the length of a request. */
function new_request() {
	$p = new ReflectionProperty( 'TCGiant_Sync_Importer', 'limit_reported' );
	if ( PHP_VERSION_ID < 80100 ) {
		$p->setAccessible( true );
	}
	$p->setValue( null, false );
	// And what the importer read once a request about pushes that did not
	// finish (3.28.7).
	foreach ( array( 'push_since' => null, 'mark_reads' => 0 ) as $name => $value ) {
		if ( property_exists( 'TCGiant_Sync_Importer', $name ) ) {
			$p = new ReflectionProperty( 'TCGiant_Sync_Importer', $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$p->setAccessible( true );
			}
			$p->setValue( null, $value );
		}
	}
	$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
}

function iso( $ts ) { return gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $ts ); }

// The renewal: the listing's end a month ago - which eBay has just renewed
// it at - and its end now. The import a month ago saw the first.
$E1 = iso( time() - 1800 );
$E2 = iso( time() - 1800 + 30 * DAY_IN_SECONDS );
$E3 = iso( time() - 1800 + 61 * DAY_IN_SECONDS );

/** A listing as GetItem returns it: everything a full import needs. */
function item( $id, array $over = array() ) {
	return array_replace_recursive( array(
		'ItemID'          => (string) $id,
		'Title'           => 'Brake caliper ' . $id,
		'SKU'             => 'BC-' . $id,
		'Quantity'        => '1',
		'ListingType'     => 'FixedPriceItem',
		'ListingDuration' => 'GTC',
		'StartPrice'      => '24.99',
		'Site'            => 'US',
		'PrimaryCategory' => array( 'CategoryID' => '33563' ),
		'SellingStatus'   => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0', 'CurrentPrice' => '24.99' ),
		'ListingDetails'  => array( 'StartTime' => '2026-03-30T16:50:00.000Z', 'EndTime' => $GLOBALS['E2'] ),
		'ItemSpecifics'   => array( 'NameValueList' => array( array( 'Name' => 'Brand', 'Value' => 'Acme' ) ) ),
		'PictureDetails'  => array( 'PictureURL' => 'https://i.ebayimg.com/' . $id . '.jpg' ),
		'Description'     => 'Fits 2004-2008.',
	), $over );
}

/** A shop with nothing in it, settings as given. */
function fresh_shop( array $settings = array() ) {
	$GLOBALS['POSTS']       = array();
	$GLOBALS['META']        = array();
	$GLOBALS['ROWS']        = array();
	$GLOBALS['OPTIONS']     = array( 'tcgiant_sync_ebay_settings' => $settings );
	$GLOBALS['TRANSIENTS']  = array();
	$GLOBALS['LOG']         = array();
	$GLOBALS['WRITES']      = array();
	$GLOBALS['SAVES']       = array();
	$GLOBALS['QUEUE']       = array();
	$GLOBALS['PHOTO_SETS']  = array();
	$GLOBALS['SAVE_FAILS']  = array();
	$GLOBALS['SAVE_THROWS'] = array();
	$GLOBALS['NEXT_ID']     = 5000;
	$GLOBALS['AUTOLOAD']    = array();
	// Out-of-Stock control off, as eBay says it, unless a test says otherwise.
	$GLOBALS['EBAY']        = array( 'items' => array(), 'answers' => array(), 'get_items' => array(), 'window' => array(), 'event_over' => array(), 'event_reads' => 0, 'wire' => array(), 'from' => '', 'oos' => 'false', 'prefs' => array(), 'at_ceiling' => false, 'connected' => true, 'mod' => array(), 'windows' => array() );
	@unlink( trailingslashit( $GLOBALS['UPLOADS'] ) . 'tcgiant_sync.lock' );
	new_request();
}

/**
 * A shop whose listings were imported in full a month ago, through the real
 * mapper's save, and whose photos have been downloaded since. eBay now has
 * each listing renewed, with whatever else $over says changed, and all of
 * them in the window the next delta reads.
 *
 * @return int[] Item ID => product ID.
 */
function shop( array $ids, array $over = array(), array $settings = array(), array $then = array() ) {
	fresh_shop( $settings );
	$mapper = TCGiant_Sync_Mapper::instance();
	$pids   = array();
	TCGiant_Sync_Category_Memory::begin();
	foreach ( $ids as $id ) {
		$was  = item( $id, array_replace_recursive( array( 'ListingDetails' => array( 'EndTime' => $GLOBALS['E1'] ) ), $then[ $id ] ?? array() ) );
		$data = $mapper->map_ebay_to_woo( $was );
		$pid  = (int) $mapper->save_as_product( $data );
		TCGiant_Sync_Category_Memory::note( $was );
		$GLOBALS['META'][ $pid ]['_thumbnail_id']             = 77;
		$GLOBALS['META'][ $pid ]['_tcgiant_images_localized'] = 1;
		$GLOBALS['EBAY']['items'][ (string) $id ]             = item( $id, $over[ $id ] ?? array() );
		$pids[ (string) $id ] = $pid;
	}
	TCGiant_Sync_Category_Memory::end();
	$GLOBALS['EBAY']['window'] = array_map( 'strval', $ids );
	$GLOBALS['SAVES']          = array();
	$GLOBALS['WRITES']         = array();
	$GLOBALS['LOG']            = array();
	$GLOBALS['PHOTO_SETS']     = array();
	return $pids;
}

/** One request of the delta, as Action Scheduler runs it. */
function delta_request() {
	$GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) { return 'tcgiant_sync_fetch_delta_events' !== $a['hook']; } ) );
	new_request();
	TCGiant_Sync_Importer::instance()->fetch_delta_events();
}

/** Point the delta at a window starting $lag_hours ago. */
function start_window( $lag_hours = 1, $minutes = 2880 ) {
	$from = iso( time() - (int) round( $lag_hours * 3600 ) );
	$GLOBALS['EBAY']['from'] = $from;
	update_option( 'tcgiant_sync_state', array(
		'status'                  => 'scanning',
		'sync_mode'               => 'delta',
		'last_successful_sync_at' => $from,
		'delta_mod_from'          => $from,
		'delta_window_minutes'    => $minutes,
		'delta_attempt'           => '',
		'last_activity_ts'        => time(),
	) );
	return $from;
}

/** The delta through one window and everything it hands on. Returns the requests. */
function delta( $lag_hours = 1 ) {
	start_window( $lag_hours );
	$runs = 0;
	do {
		delta_request();
		$runs++;
	} while ( as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) && $runs < 400 );
	return $runs;
}
