<?php
/**
 * The world the pruner and the full sync's scan run in, for
 * test-prune-guards.php, test-prune-drift.php and test-scan-ended.php, and
 * the delta's too for test-category-memory.php, which plays eBay with an API
 * class of its own, declared before this file is loaded.
 *
 * Loads the real importer, inventory and runtime classes. WordPress,
 * WooCommerce, Action Scheduler and eBay are played here. Posts, post meta
 * and options live in SQLite, so the pruner's own SQL really runs; without
 * PHP's sqlite3 extension the harnesses say so and report nothing.
 *
 * @package TCGiant_Sync
 */

if ( ! class_exists( 'SQLite3' ) ) {
	echo "\n  PHP's sqlite3 extension is not loaded: these cases need it, and are skipped.\n\n  0 passed, 0 failed\n\n";
	exit( 0 );
}

$ROOT          = dirname( __DIR__, 2 );
$IMPORTER_FILE = $ROOT . '/includes/class-tcgiant-sync-importer.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-84s got %-16s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class ActionScheduler_Store { const STATUS_PENDING = 'pending'; const STATUS_RUNNING = 'in-progress'; }

class Harness_Sqlite_Wpdb {
	public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $options = 'wp_options';
	public $db; public $queries = array();
	public function __construct() { $this->reset(); }
	public function reset() {
		$this->db = new SQLite3( ':memory:' );
		$this->db->exec( 'CREATE TABLE wp_posts ( ID INTEGER PRIMARY KEY, post_type TEXT NOT NULL DEFAULT "product", post_status TEXT NOT NULL DEFAULT "publish" )' );
		$this->db->exec( 'CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT, meta_value TEXT )' );
		$this->db->exec( 'CREATE TABLE wp_options ( option_name TEXT PRIMARY KEY, option_value TEXT )' );
	}
	/** Writes of an option straight to the table, by prepare() and query(). */
	public $option_writes = array();
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		// An option written straight to the table - the full sync's place,
		// claimed by compare-and-swap (3.27.6) - goes to the options as this
		// world keeps them, in $TABLE, not to SQLite.
		if ( preg_match( '/^\s*(INSERT INTO wp_options|UPDATE wp_options SET option_value)/', $query ) ) {
			$this->option_writes[] = array( $query, $args );
			return '/*option-write:' . ( count( $this->option_writes ) - 1 ) . '*/';
		}
		$i = 0; $db = $this->db;
		return preg_replace_callback( '/%%|%[sdf]/', function ( $m ) use ( &$i, $args, $db ) {
			if ( '%%' === $m[0] ) { return '%'; }
			$v = $args[ $i++ ] ?? '';
			if ( '%d' === $m[0] ) { return (string) (int) $v; }
			if ( '%f' === $m[0] ) { return (string) (float) $v; }
			return "'" . $db->escapeString( (string) $v ) . "'";
		}, $query );
	}
	private function rows( $query ) {
		$this->queries[] = $query;
		// The raw option read stop_pressed() and state_from_table() use: the
		// table itself, not this request's copy.
		if ( preg_match( "/FROM wp_options WHERE option_name = '([^']+)'/", $query, $m ) ) {
			return array_key_exists( $m[1], $GLOBALS['TABLE'] ) ? array( array( 'option_value' => serialize( $GLOBALS['TABLE'][ $m[1] ] ) ) ) : array();
		}
		$res = @$this->db->query( $query );
		if ( false === $res ) { fwrite( STDERR, "SQL ERROR: " . $this->db->lastErrorMsg() . "\n$query\n" ); return array(); }
		$out = array();
		while ( $r = $res->fetchArray( SQLITE3_ASSOC ) ) { $out[] = $r; }
		return $out;
	}
	public function get_results( $q, $o = OBJECT ) { $r = $this->rows( $q ); return ARRAY_A === $o ? $r : array_map( function ( $x ) { return (object) $x; }, $r ); }
	public function get_var( $q ) { $r = $this->rows( $q ); if ( ! $r ) { return null; } $f = reset( $r[0] ); return null === $f ? null : (string) $f; }
	public function get_col( $q ) { return array_map( function ( $r ) { return (string) reset( $r ); }, $this->rows( $q ) ); }
	public function query( $q ) {
		$this->queries[] = $q;
		if ( preg_match( '#^/\*option-write:(\d+)\*/$#', $q, $m ) ) {
			list( $sql, $args ) = $this->option_writes[ (int) $m[1] ];
			if ( 0 === strpos( ltrim( $sql ), 'INSERT' ) ) {
				list( $name, $value ) = $args;
				if ( array_key_exists( $name, $GLOBALS['TABLE'] ) ) { return false; }
			} else {
				list( $value, $name, $raw ) = $args;
				if ( ! array_key_exists( $name, $GLOBALS['TABLE'] ) || serialize( $GLOBALS['TABLE'][ $name ] ) !== $raw ) { return 0; }
			}
			$GLOBALS['TABLE'][ $name ]   = maybe_unserialize( $value );
			$GLOBALS['OPTIONS'][ $name ] = $GLOBALS['TABLE'][ $name ];
			return 1;
		}
		return @$this->db->exec( $q ) ? $this->db->changes() : false;
	}
	public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }
}
$wpdb = new Harness_Sqlite_Wpdb();

function maybe_serialize( $v ) { return ( is_array( $v ) || is_object( $v ) ) ? serialize( $v ) : $v; }
function maybe_unserialize( $v ) { $u = @unserialize( $v ); return false === $u && 'b:0;' !== $v ? $v : $u; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $t ) { return $t; }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['HOOKS'][ $hook ][] = array( $cb, $args ); return true; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { return add_action( $hook, $cb, $prio, $args ); }
function apply_filters( $hook, $value ) { return $value; }
function do_action() {}
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_gmt_from_date( $d, $f = 'Y-m-d H:i:s' ) { return gmdate( $f, strtotime( $d ) ); }
// Dropping an option from the cache makes the next read the table's - played
// only where a harness asks (CACHE_READS_TABLE), as the others were written
// against a cache that kept this request's copy.
function wp_cache_delete( $key = '', $group = '' ) {
	if ( 'options' === $group && ! empty( $GLOBALS['CACHE_READS_TABLE'] ) ) {
		if ( array_key_exists( $key, $GLOBALS['TABLE'] ) ) {
			$GLOBALS['OPTIONS'][ $key ] = $GLOBALS['TABLE'][ $key ];
		} else {
			unset( $GLOBALS['OPTIONS'][ $key ] );
		}
	}
	return true;
}
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function delete_transient( $k ) { return true; }
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() ); }
function trailingslashit( $p ) { return rtrim( $p, '/\\' ) . '/'; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['CRON'] = array_values( array_filter( $GLOBALS['CRON'], function ( $e ) use ( $hook ) { return $e[1] !== $hook; } ) ); }
function wp_schedule_single_event( $when, $hook ) { $GLOBALS['CRON'][] = array( $when, $hook ); return true; }
function wp_next_scheduled( $hook ) { foreach ( $GLOBALS['CRON'] as $e ) { if ( $e[1] === $hook ) { return $e[0]; } } return false; }

// Options, as WordPress keeps them: the table, and this request's copy of
// it, which get_option() serves. A write goes to both. Another request's
// write goes to the table only - elsewhere() - and this request sees it only
// through a raw read, or once a new request starts.
$TABLE = array();
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { if ( ! empty( $GLOBALS['ON_UPDATE'] ) ) { call_user_func( $GLOBALS['ON_UPDATE'], $n, $v, $a ); } $GLOBALS['OPTIONS'][ $n ] = $v; $GLOBALS['TABLE'][ $n ] = $v; return true; }
function add_option( $n, $v = '', $dep = '', $a = 'yes' ) { if ( ! array_key_exists( $n, $GLOBALS['OPTIONS'] ) ) { $GLOBALS['OPTIONS'][ $n ] = $v; $GLOBALS['TABLE'][ $n ] = $v; $GLOBALS['AUTOLOAD'][ $n ] = $a; return true; } return false; }
function delete_option( $n ) { unset( $GLOBALS['OPTIONS'][ $n ], $GLOBALS['TABLE'][ $n ] ); return true; }
/** Another request writes an option: the table changes, this request's copy does not. */
function elsewhere( $n, $v ) { $GLOBALS['TABLE'][ $n ] = $v; }
/** A new request: its copy of the options is read fresh from the table. */
function new_request() { $GLOBALS['OPTIONS'] = $GLOBALS['TABLE']; }

// Post meta in SQLite.
function get_post_meta( $id, $key = '', $single = false ) {
	global $wpdb;
	$v = $wpdb->db->querySingle( sprintf( "SELECT meta_value FROM wp_postmeta WHERE post_id = %d AND meta_key = '%s' ORDER BY meta_id ASC LIMIT 1", (int) $id, SQLite3::escapeString( $key ) ) );
	// Stored serialised, as WordPress stores an array (release fix round 4).
	if ( is_string( $v ) && 0 === strpos( $v, 'a:' ) && false !== ( $u = @unserialize( $v ) ) ) {
		$v = $u;
	}
	return null === $v ? ( $single ? '' : array() ) : ( $single ? $v : array( $v ) );
}
function update_post_meta( $id, $key, $value ) {
	global $wpdb;
	$GLOBALS['EVENTS'][] = sprintf( 'meta %d %s=%s', $id, $key, is_scalar( $value ) ? $value : json_encode( $value ) );
	$wpdb->db->exec( sprintf( "DELETE FROM wp_postmeta WHERE post_id = %d AND meta_key = '%s'", (int) $id, SQLite3::escapeString( $key ) ) );
	$wpdb->db->exec( sprintf( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( %d, '%s', '%s' )", (int) $id, SQLite3::escapeString( $key ), SQLite3::escapeString( is_array( $value ) ? serialize( $value ) : (string) $value ) ) );
	return true;
}
function delete_post_meta( $id, $key ) {
	global $wpdb;
	$GLOBALS['EVENTS'][] = sprintf( 'unmeta %d %s', $id, $key );
	$wpdb->db->exec( sprintf( "DELETE FROM wp_postmeta WHERE post_id = %d AND meta_key = '%s'", (int) $id, SQLite3::escapeString( $key ) ) );
	return true;
}
function get_post_status( $id ) { $s = post_status( $id ); return $s ? $s : false; }
function post_status( $id ) { global $wpdb; return $wpdb->db->querySingle( 'SELECT post_status FROM wp_posts WHERE ID = ' . (int) $id ); }
function wp_trash_post( $id ) {
	global $wpdb;
	$GLOBALS['EVENTS'][] = 'trash ' . (int) $id;
	$wpdb->db->exec( "UPDATE wp_posts SET post_status = 'trash' WHERE ID = " . (int) $id );
	return true;
}
function has_term( $terms, $tax, $id ) { return in_array( (int) ( $GLOBALS['CATS'][ (int) $id ] ?? 0 ), array_map( 'intval', (array) $terms ), true ); }

// WooCommerce, reading meta afresh.
class Harness_Product {
	private $id;
	public function __construct( $id ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function managing_stock() { return 'yes' === get_post_meta( $this->id, '_manage_stock', true ); }
	public function get_stock_quantity() { return $this->managing_stock() ? (int) get_post_meta( $this->id, '_stock', true ) : null; }
	public function get_stock_status() { return get_post_meta( $this->id, '_stock_status', true ) ?: 'instock'; }
	public function is_in_stock() { return 'outofstock' !== $this->get_stock_status(); }
	public function is_type( $t ) { return 'simple' === $t; }
	public function get_meta( $k, $s = true ) { return get_post_meta( $this->id, $k, true ); }
}
function wc_get_product( $id ) { return post_status( $id ) ? new Harness_Product( $id ) : false; }
function wc_update_product_stock( $product, $qty = null, $op = 'set' ) {
	$id = is_object( $product ) ? $product->get_id() : (int) $product;
	$GLOBALS['EVENTS'][] = sprintf( 'stock %d %s %d%s', $id, $op, $qty, TCGiant_Sync_Inventory::is_push_suppressed() ? '' : ' UNGUARDED' );
	update_post_meta( $id, '_stock', (int) $qty );
	update_post_meta( $id, '_stock_status', (int) $qty > 0 ? 'instock' : 'outofstock' );
	// WooCommerce's data store, right after its stock SQL; then its save of
	// the product, which another plugin's hook may make throw.
	foreach ( $GLOBALS['HOOKS']['woocommerce_updated_product_stock'] ?? array() as $l ) {
		call_user_func( $l[0], (int) $id );
	}
	if ( ! empty( $GLOBALS['SAVE_THROWS'] ) ) {
		$GLOBALS['SAVE_THROWS'] = false;
		throw new RuntimeException( 'another plugin\'s save hook failed' );
	}
	return (int) $qty;
}
function wc_update_product_stock_status( $id, $status ) { update_post_meta( $id, '_stock_status', $status ); }

// Action Scheduler.
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { $GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'when' => 0 ); return count( $GLOBALS['QUEUE'] ); }
function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) { $GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'when' => $when ); return count( $GLOBALS['QUEUE'] ); }
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['QUEUE'] as $a ) { if ( $a['hook'] === $hook && ( null === $args || $a['args'] === $args ) ) { return true; } }
	return false;
}
function as_unschedule_all_actions( $hook, $args = null, $group = '' ) { $GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) use ( $hook ) { return $a['hook'] !== $hook; } ) ); }
function as_get_scheduled_actions( $q ) { return array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) use ( $q ) { return $a['hook'] === $q['hook']; } ) ); }
function queued( $hook ) { return count( array_filter( $GLOBALS['QUEUE'], function ( $a ) use ( $hook ) { return $a['hook'] === $hook; } ) ); }
/** Run what is queued, through the registered hooks, as Action Scheduler would. */
function run_queue( $only = null, $max = 50 ) {
	$ran = 0;
	while ( $ran < $max ) {
		$idx = null;
		foreach ( $GLOBALS['QUEUE'] as $i => $a ) { if ( null === $only || $a['hook'] === $only ) { $idx = $i; break; } }
		if ( null === $idx ) { break; }
		$a = $GLOBALS['QUEUE'][ $idx ];
		array_splice( $GLOBALS['QUEUE'], $idx, 1 );
		new_request();
		foreach ( $GLOBALS['HOOKS'][ $a['hook'] ] ?? array() as $h ) {
			call_user_func_array( $h[0], array_slice( array_values( (array) $a['args'] ), 0, $h[1] ) );
		}
		$ran++;
	}
	if ( $ran >= $max ) {
		fwrite( STDOUT, "      (run_queue stopped at its cap of $max actions)\n" );
	}
	return $ran;
}

class TCGiant_Sync_Logger {
	public static function log( $m, $l = 'info' ) { $GLOBALS['LOG'][] = array( $l, $m ); }
	public static function error( $m ) { self::log( $m, 'error' ); }
	public static function warning( $m ) { self::log( $m, 'warning' ); }
	public static function write_now( $m, $l = 'error' ) { self::log( $m, $l ); }
	public static function flush() {}
}
function logged( $needle ) { foreach ( $GLOBALS['LOG'] as $l ) { if ( false !== stripos( $l[1], $needle ) ) { return true; } } return false; }
function dump_log() { foreach ( $GLOBALS['LOG'] as $l ) { echo "      [{$l[0]}] {$l[1]}\n"; } }

class TCGiant_Sync_OAuth {
	public static function instance() { static $i; return $i ?: ( $i = new self() ); }
	public function get_settings() { return get_option( 'tcgiant_sync_ebay_settings', array() ); }
}
class TCGiant_Sync_Entitlements {
	public static function instance() { static $i; return $i ?: ( $i = new self() ); }
	public function can_import() { return $GLOBALS['CAN_IMPORT'] ?? true; }
	public function get_active_product_count() { return 0; }
	public function get_free_limit() { return 0; }
}
class TCGiant_Sync_Image_Localizer { public static function set_external_images() {} }
class TCGiant_Sync_Cron { public static function request_dispatch() {} }

/** The mapper: records what the scan asks it to save as a live listing. */
class TCGiant_Sync_Mapper {
	public static $last_skipped  = false;
	public static $last_deferred = null;
	public $relist_parent_error  = null;
	public static function instance() { static $i; return $i ?: ( $i = new self() ); }
	/**
	 * Item 8's adoption, recorded with the old listing's answer it was handed;
	 * its own checks are test-relist-adopt.php's subject. An adoption moves the
	 * product on to the relist and forgets the old listing's ending, as
	 * TCGiant_Sync_Inventory::follow_relist() does.
	 */
	public function adopt_relist( array $item, $source, $parent_item = null ) {
		$GLOBALS['ADOPTED'][]        = array( (string) ( $item['ItemID'] ?? '' ), $source );
		$GLOBALS['ADOPTED_PARENT'][] = is_array( $parent_item ) ? (string) ( $parent_item['ItemID'] ?? '' ) : null;
		$id = (int) ( $GLOBALS['ADOPT_RESULT'] ?? 0 );
		if ( $id ) {
			$live = in_array( (string) ( $item['SellingStatus']['ListingStatus'] ?? '' ), array( '', 'Active' ), true );
			update_post_meta( $id, '_ebay_previous_item_id', (string) get_post_meta( $id, '_ebay_item_id', true ) );
			update_post_meta( $id, '_ebay_item_id', (string) $item['ItemID'] );
			update_post_meta( $id, '_ebay_listing_status', $live ? 'Active' : 'Ended' );
			foreach ( TCGiant_Sync_Inventory::SETTLE_KEYS as $key ) {
				delete_post_meta( $id, $key );
			}
		}
		return $id;
	}
	public function map_ebay_to_woo( $item ) {
		return array( 'item' => $item, 'images' => array(), 'variations' => array(), 'attributes' => array(), 'price' => '1', 'weight' => '', 'stock_quantity' => max( 0, (int) ( $item['Quantity'] ?? 1 ) - (int) ( $item['SellingStatus']['QuantitySold'] ?? 0 ) ) );
	}
	/** As the real one: writes eBay's listed-minus-sold over the stock, and eBay's status. */
	public function save_as_product( $data ) {
		$item = $data['item'];
		$GLOBALS['SAVED'][] = array( (string) $item['ItemID'], (string) ( $item['SellingStatus']['ListingStatus'] ?? '' ) );
		$GLOBALS['IDS_AT_SAVE'][] = in_array( (string) $item['ItemID'], array_map( 'strval', (array) get_option( 'tcgiant_sync_active_ids', array() ) ), true );
		$id = TCGiant_Sync_Importer::find_live_product_for_item( $item['ItemID'] );
		if ( $id ) {
			update_post_meta( $id, '_stock', $data['stock_quantity'] );
			update_post_meta( $id, '_stock_status', $data['stock_quantity'] > 0 ? 'instock' : 'outofstock' );
			update_post_meta( $id, '_ebay_listing_status', $item['SellingStatus']['ListingStatus'] ?? 'Active' );
		}
		return $id ?: 9999;
	}
}

/** eBay. GetItem from a script; GetSellerList pages from a function of time. */
if ( ! class_exists( 'TCGiant_Sync_API', false ) ) {
class TCGiant_Sync_API {
	const DAILY_CALL_LIMIT = 50000;
	const DAILY_CALL_SOFT_CEILING = 0.80;
	// What "Import listings from" (3.27.5) reads the shop's own site from.
	const MARKETPLACES = array( 'EBAY_US' => array( 'label' => 'United States', 'site' => 'US' ) );
	public static function scalar_value( $value ) { return is_scalar( $value ) ? (string) $value : ''; }
	public static function instance() { static $i; return $i ?: ( $i = new self() ); }
	public function get_remaining_daily_budget() { return (int) ( $GLOBALS['EBAY']['budget'] ?? 40000 ); }
	public function get_item( $item_id ) {
		$GLOBALS['EBAY']['get_items'][] = (string) $item_id;
		// Something another request does while eBay is being asked.
		if ( ! empty( $GLOBALS['EBAY']['on_get_item'] ) ) {
			call_user_func( $GLOBALS['EBAY']['on_get_item'], (string) $item_id );
		}
		$a = $GLOBALS['EBAY']['items'][ (string) $item_id ] ?? null;
		if ( null === $a ) {
			return new WP_Error( 'api_error', 'This item cannot be accessed because the listing has been deleted or you are not the seller.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '17' ) ) );
		}
		return $a;
	}
	public function get_active_listings( $page = 1, $per = 200, $mod_from = '', $end_from = '' ) {
		$GLOBALS['EBAY']['list_calls'][] = array( $page, $mod_from, $end_from );
		return call_user_func( $GLOBALS['EBAY']['lister'], $page, $per, $mod_from, $end_from );
	}
}
}

function fresh_world( array $settings = array() ) {
	global $wpdb;
	$wpdb->reset();
	$GLOBALS['OPTIONS']  = array( 'tcgiant_sync_ebay_settings' => $settings );
	$GLOBALS['TABLE']    = $GLOBALS['OPTIONS'];
	$GLOBALS['AUTOLOAD'] = array();
	$GLOBALS['QUEUE']    = array();
	$GLOBALS['ADOPTED']  = array();
	$GLOBALS['ADOPTED_PARENT'] = array();
	$GLOBALS['ADOPT_RESULT'] = 0;
	$GLOBALS['CRON']     = array();
	$GLOBALS['LOG']      = array();
	$GLOBALS['EVENTS']   = array();
	$GLOBALS['SAVED']    = array();
	$GLOBALS['CATS']     = array();
	$GLOBALS['EBAY']     = array( 'items' => array(), 'get_items' => array(), 'list_calls' => array(), 'budget' => 40000 );
}

/** A product linked to a listing. */
function product( $id, $item_id, $stock, array $meta = array(), $cat = 0 ) {
	global $wpdb;
	$wpdb->db->exec( sprintf( "INSERT INTO wp_posts ( ID ) VALUES ( %d )", $id ) );
	$all = array_merge( array( '_ebay_item_id' => $item_id, '_manage_stock' => 'yes', '_stock' => $stock, '_stock_status' => $stock > 0 ? 'instock' : 'outofstock', '_ebay_listing_status' => 'Active' ), $meta );
	foreach ( $all as $k => $v ) { if ( null !== $v ) { update_post_meta( $id, $k, $v ); } }
	if ( $cat ) { $GLOBALS['CATS'][ $id ] = $cat; }
	$GLOBALS['EVENTS'] = array();
}
function stock( $id ) { return (int) get_post_meta( $id, '_stock', true ); }
function meta( $id, $k ) { return get_post_meta( $id, $k, true ); }

/** A listing as GetItem returns it. */
function listing( $item_id, $status, $listed, $sold, array $extra = array() ) {
	$GLOBALS['EBAY']['items'][ $item_id ] = array( 'Ack' => 'Success', 'Item' => array_replace_recursive( array(
		'ItemID' => $item_id, 'Title' => 'Item ' . $item_id, 'Quantity' => (string) $listed,
		'SellingStatus' => array( 'ListingStatus' => $status, 'QuantitySold' => (string) $sold ),
		'ListingDetails' => array( 'StartTime' => '2026-09-01T10:00:00.000Z', 'EndTime' => '2026-10-11T10:00:00.000Z' ),
	), $extra ) );
}

/** Authorise a prune: a clean full scan that saw $active. */
function scanned( array $active ) {
	update_option( 'tcgiant_sync_state', array_merge( TCGiant_Sync_Importer::get_sync_state(), array(
		'status' => 'complete', 'sync_mode' => 'full', 'scan_complete_clean' => true, 'full_started_at' => '2026-10-13T08:00:00.000Z',
	) ) );
	delete_option( TCGiant_Sync_Importer::ACTIVE_IDS_OPTION );
	add_option( TCGiant_Sync_Importer::ACTIVE_IDS_OPTION, array_values( $active ), '', false );
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
// "Import listings from" (3.27.5), which the importer asks.
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $IMPORTER_FILE;
$IMP = TCGiant_Sync_Importer::instance();
