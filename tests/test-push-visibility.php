<?php
/**
 * Harness: a push the host kills says so.
 *
 * Gadget Central pushed three products at once. The first push's request was
 * killed by the host after eBay's AddFixedPriceItem had created the listing,
 * but before process_single_push() saved the item number on the product. Its
 * Action Scheduler action sat "in progress" for ten minutes and held the
 * other two up; then the batch ended with "Export complete! 3 pushed, 0
 * errors out of 3 total" - two pushes, plus one added "for the current item",
 * which a clean batch of three reports as four. Nothing was logged about the
 * push that died. The product simply showed as never linked, while its
 * listing was live on eBay, and pushing it again would have listed it twice.
 *
 * Loaded for real: TCGiant_Sync_Exporter, TCGiant_Sync_Runtime,
 * TCGiant_Sync_Catalog, TCGiant_Sync_API (eBay played at its calls), the
 * admin class's push notice and Link, and the daily auto-relist and the
 * Listings screen's bulk jobs (TCGiant_Sync_Cron, TCGiant_Sync_Jobs).
 * WordPress, WooCommerce and Action Scheduler are played here, and keep what
 * they hold - posts, meta, options, actions, the log, eBay's listings - in a
 * file, as a database would, so that what a request wrote before it was
 * killed is all that survives it.
 *
 * The kill is real. The push runs in a child PHP process, and the parent
 * terminates it once eBay has created the listing, as a host's own time
 * limit or process reaper does: no shutdown function runs, nothing buffered
 * is written. Two more children die the ways PHP itself sees - out of memory
 * while saving the link, and exit() while waiting for eBay.
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__ );
$MODE = $argv[1] ?? '';

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'TCGIANT_SYNC_VERSION', '3.27.4' );
define( 'ARRAY_A', 'ARRAY_A' );

// --- What the database holds, kept in a file ----------------------------------

$WORLD_FILE = $argv[2] ?? ( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tcg-push-visibility-' . getmypid() . '.dat' );
$W          = array();

/** An empty shop: no products, no actions, nothing on eBay. */
function world_fresh() {
	$GLOBALS['W'] = array(
		'posts'       => array(),
		'meta'        => array(),
		'options'     => array(),
		'actions'     => array(),
		'next_action' => 1,
		'ebay'        => array(),
		'next_item'   => array(),
		'log'         => array(),
		'rows'        => array(),
		'ended'       => array(),
		'revised'     => array(),
	);
	world_save();
}
function world_save() { file_put_contents( $GLOBALS['WORLD_FILE'], serialize( $GLOBALS['W'] ), LOCK_EX ); }
function world_load() { $GLOBALS['W'] = unserialize( (string) file_get_contents( $GLOBALS['WORLD_FILE'] ) ); }

// --- WordPress ---------------------------------------------------------------

$HOOKS = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['HOOKS'][ $hook ][] = array( $priority, count( $GLOBALS['HOOKS'][ $hook ] ?? array() ), $callback, $accepted_args );
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return add_action( $hook, $callback, $priority, $accepted_args ); }
function remove_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['HOOKS'][ $hook ] = array_values( array_filter( $GLOBALS['HOOKS'][ $hook ] ?? array(), function ( $h ) use ( $callback ) { return $h[2] !== $callback; } ) );
	return true;
}
function remove_filter( $hook, $callback, $priority = 10 ) { return remove_action( $hook, $callback, $priority ); }
function hooks_on( $hook ) {
	$list = $GLOBALS['HOOKS'][ $hook ] ?? array();
	usort( $list, function ( $a, $b ) { return ( $a[0] <=> $b[0] ) ?: ( $a[1] <=> $b[1] ); } );
	return $list;
}
function do_action( $hook, ...$args ) {
	foreach ( hooks_on( $hook ) as $h ) {
		call_user_func_array( $h[2], array_slice( $args, 0, (int) $h[3] ) );
	}
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( hooks_on( $hook ) as $h ) {
		$value = call_user_func_array( $h[2], array_slice( array_merge( array( $value ), $args ), 0, (int) $h[3] ) );
	}
	return $value;
}

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['W']['options'] ) ? $GLOBALS['W']['options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['W']['options'][ $name ] = $value; world_save(); return true; }
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) { if ( ! array_key_exists( $name, $GLOBALS['W']['options'] ) ) { update_option( $name, $value ); } return true; }
function delete_option( $name ) { unset( $GLOBALS['W']['options'][ $name ] ); world_save(); return true; }
function get_transient( $name ) { return false; }
function set_transient( $name, $value, $ttl = 0 ) { return true; }
function delete_transient( $name ) { return true; }
function wp_cache_get( $key, $group = '' ) { return false; }
function wp_cache_set( $key, $value, $group = '' ) { return true; }
// What is dropped from the request's cache, so the harness can see a read
// made afresh rather than from the copy a request began with. ON_CACHE_DROP
// plays that copy: the options hold what the request had read, and when it is
// dropped it puts back what the database has. It stays for the next drop
// until it says it has done its part.
$CACHE_DROPPED = array();
function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['CACHE_DROPPED'][] = $group . '/' . $key;
	if ( isset( $GLOBALS['ON_CACHE_DROP'] ) ) {
		$on_drop                  = $GLOBALS['ON_CACHE_DROP'];
		$GLOBALS['ON_CACHE_DROP'] = null;
		if ( ! $on_drop( $group . '/' . $key ) ) {
			$GLOBALS['ON_CACHE_DROP'] = $on_drop;
		}
	}
	return true;
}
/**
 * The database, for what the plugin asks of it directly: which of a batch's
 * products still say a push is queued, under way or did not finish, which
 * products the coin finder starts from, which other products share a
 * product's item number, and which are linked to a listing that has ended,
 * for auto-relist. Answered from the same meta as
 * get_post_meta(), and each query kept, so the harness can count them. It has
 * no replace(), so the crash record still falls back to update_option().
 */
class Harness_wpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $asked    = array();
	public function prepare( $query, ...$args ) { return vsprintf( $query, $args ); }
	public function get_col( $sql ) {
		$this->asked[] = $sql;
		// The coin finder: a product that says pushed, or that was pushed
		// before and says queued, pushing or failed. Its WHERE is held to that
		// text at the end of this file.
		if ( false !== strpos( $sql, "lp.meta_key = '_ebay_export_last_pushed'" ) ) {
			$found = array();
			foreach ( array_keys( $GLOBALS['W']['posts'] ) as $id ) {
				$status = (string) ( $GLOBALS['W']['meta'][ $id ]['_ebay_export_status'] ?? "\0" );
				$pushed = (string) ( $GLOBALS['W']['meta'][ $id ]['_ebay_export_last_pushed'] ?? '' );
				if ( 'pushed' === $status || ( in_array( $status, array( 'queued', 'pushing', 'failed' ), true ) && '' !== $pushed ) ) {
					$found[] = (string) $id;
				}
			}
			return $found;
		}
		// TCGiant_Sync_Listing_Link::find_products_sharing_item_id().
		if ( preg_match( "/pm\.meta_key = '_ebay_item_id'\s+AND pm\.meta_value = '?(\w*)'?\s+AND pm\.post_id != (\d+)/", $sql, $m ) ) {
			$found = array();
			foreach ( $GLOBALS['W']['meta'] as $id => $meta ) {
				if ( (int) $id !== (int) $m[2] && (string) ( $meta['_ebay_item_id'] ?? '' ) === $m[1] ) {
					$found[] = (string) $id;
				}
			}
			return $found;
		}
		if ( ! preg_match( "/^SELECT DISTINCT post_id FROM wp_postmeta\s+WHERE meta_key = '(\w+)'\s+AND meta_value IN \(([^)]*)\)\s+AND post_id IN \(([\d,\s]+)\)$/", trim( $sql ), $m ) ) {
			throw new RuntimeException( 'harness wpdb: a query it does not know: ' . $sql );
		}
		$values = array_map( function ( $v ) { return trim( $v, " '" ); }, explode( ',', $m[2] ) );
		$found  = array();
		foreach ( array_map( 'intval', explode( ',', $m[3] ) ) as $id ) {
			if ( in_array( (string) ( $GLOBALS['W']['meta'][ $id ][ $m[1] ] ?? "\0" ), $values, true ) ) {
				$found[] = (string) $id;
			}
		}
		return $found;
	}
	/** Auto-relist's ended listings, from post meta: there is no Listings table here. */
	public function get_results( $sql, $output = 'OBJECT' ) {
		$this->asked[] = $sql;
		if ( false === strpos( $sql, "pm2.meta_value = 'Ended'" ) ) {
			throw new RuntimeException( 'harness wpdb: a query it does not know: ' . $sql );
		}
		$rows = array();
		foreach ( array_keys( $GLOBALS['W']['posts'] ) as $id ) {
			$meta = $GLOBALS['W']['meta'][ $id ] ?? array();
			if ( '' !== (string) ( $meta['_ebay_item_id'] ?? '' ) && 'Ended' === ( $meta['_ebay_listing_status'] ?? '' ) ) {
				$rows[] = array( 'post_id' => (string) $id, 'ebay_item_id' => (string) $meta['_ebay_item_id'] );
			}
		}
		return $rows;
	}
}
$wpdb = new Harness_wpdb();

function get_post_status( $id ) { return isset( $GLOBALS['W']['posts'][ (int) $id ] ) ? 'publish' : false; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['W']['meta'][ (int) $id ][ $key ] ?? ''; }
/**
 * Written straight to the table. ON_META plays another request at work
 * meanwhile: it runs once, after the write, and stays for the next write
 * until it says it has done its part.
 */
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['W']['meta'][ (int) $id ][ $key ] = $value;
	world_save();
	if ( isset( $GLOBALS['ON_META'] ) ) {
		$on_meta            = $GLOBALS['ON_META'];
		$GLOBALS['ON_META'] = null;
		if ( ! $on_meta( (int) $id, $key, $value ) ) {
			$GLOBALS['ON_META'] = $on_meta;
		}
	}
	return true;
}
function delete_post_meta( $id, $key ) { unset( $GLOBALS['W']['meta'][ (int) $id ][ $key ] ); world_save(); return true; }
function get_post_field( $field, $id ) { return 'post_title' === $field ? ( $GLOBALS['W']['posts'][ (int) $id ]['title'] ?? '' ) : ''; }
function get_the_title( $id ) { return get_post_field( 'post_title', $id ); }

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return null; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function __( $text, $domain = null ) { return $text; }
function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) { return 1; }
function current_user_can( $capability ) { return true; }
function absint( $n ) { return abs( (int) $n ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $text ) { return trim( (string) $text ); }
/** wp_send_json_*() end the request; here they end the handler. */
class Harness_Json_Answer extends Exception {
	public $success;
	public $data;
	public function __construct( $success, $data ) { parent::__construct( 'json' ); $this->success = $success; $this->data = $data; }
}
function wp_send_json_success( $data = null ) { throw new Harness_Json_Answer( true, $data ); }
function wp_send_json_error( $data = null ) { throw new Harness_Json_Answer( false, $data ); }
/** A redirect ends the request too; here it ends the handler, and says where it went. */
class Harness_Redirect extends Exception {}
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) { return 1; }
function wp_die( $message = '' ) { throw new RuntimeException( 'wp_die: ' . $message ); }
function admin_url( $path = '' ) { return 'https://gadget-central.example/wp-admin/' . $path; }
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( $args ); }
function wp_safe_redirect( $location, $status = 302 ) { throw new Harness_Redirect( $location ); }
function esc_html__( $text, $domain = null ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $text, $domain = null ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_xml( $text ) { return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return (string) $url; }
// What the coin-listing banner prints with.
function esc_js( $text ) { return addslashes( (string) $text ); }
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function _n( $single, $plural, $number, $domain = null ) { return 1 === (int) $number ? $single : $plural; }
function wp_nonce_field( $action = -1 ) { echo '<input type="hidden" name="_wpnonce" value="harness">'; }
function get_current_screen() { return (object) array( 'id' => 'edit-product' ); }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : gmdate( $type ); }
function wc_format_decimal( $number, $dp = false ) { return false === $dp ? (string) $number : number_format( (float) $number, (int) $dp, '.', '' ); }
function wc_attribute_label( $name, $product = null ) { return $name; }
/** WordPress's, as far as minutes go. */
function human_time_diff( $from, $to = 0 ) {
	$mins = max( 1, (int) round( abs( $to - $from ) / 60 ) );
	return 1 === $mins ? '1 min' : $mins . ' mins';
}
// Core's own, so the UUID eBay sees has the shape it would on a real site.
function wp_generate_uuid4() {
	return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
}

// --- WooCommerce -------------------------------------------------------------

/**
 * A product, as WooCommerce holds one: its meta read when it is loaded, kept
 * in the object, and written back - only what changed - when it is saved.
 */
class WC_Product {
	protected $id;
	protected $meta    = array();
	protected $changed = array();
	public function __construct( $id ) { $this->id = (int) $id; $this->read_meta_data( true ); }
	public function read_meta_data( $force_read = false ) { $this->meta = $GLOBALS['W']['meta'][ $this->id ] ?? array(); }
	public function get_id() { return $this->id; }
	public function get_name() { return $GLOBALS['W']['posts'][ $this->id ]['title']; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; $this->changed[ $key ] = true; }
	// WooCommerce deletes only what it read: a key it never saw is left be.
	public function delete_meta_data( $key ) { if ( array_key_exists( $key, $this->meta ) ) { $this->meta[ $key ] = null; $this->changed[ $key ] = true; } }
	public function save() {
		// Where everything else hooked to a product save runs.
		if ( isset( $GLOBALS['ON_SAVE'] ) ) {
			call_user_func( $GLOBALS['ON_SAVE'], $this, array_keys( $this->changed ) );
		}
		foreach ( array_keys( $this->changed ) as $key ) {
			if ( null === $this->meta[ $key ] ) {
				unset( $GLOBALS['W']['meta'][ $this->id ][ $key ], $this->meta[ $key ] );
			} else {
				$GLOBALS['W']['meta'][ $this->id ][ $key ] = $this->meta[ $key ];
			}
		}
		$this->changed = array();
		world_save();
		return $this->id;
	}
	public function is_type( $type ) { return 'simple' === $type; }
	public function managing_stock() { return true; }
	public function get_stock_quantity() { return 4; }
	public function get_regular_price() { return '49.99'; }
	public function get_sku() { return 'GC-' . $this->id; }
	public function get_image_id() { return 0; }
	public function get_gallery_image_ids() { return array(); }
	public function get_description() { return 'Tested and working, boxed.'; }
	public function get_short_description() { return ''; }
	public function get_attributes() { return array(); }
	public function get_weight() { return ''; }
	public function get_status() { return 'publish'; }
	public function is_in_stock() { return true; }
}
class WC_Product_Variable extends WC_Product {}
class WC_Product_Variation extends WC_Product {}

function wc_get_product( $id ) { return isset( $GLOBALS['W']['posts'][ (int) $id ] ) ? new WC_Product( $id ) : false; }

// --- Action Scheduler (woocommerce/action-scheduler), as far as a push goes ----

class ActionScheduler_Store {
	const STATUS_COMPLETE = 'complete';
	const STATUS_PENDING  = 'pending';
	const STATUS_RUNNING  = 'in-progress';
	const STATUS_FAILED   = 'failed';
	const STATUS_CANCELED = 'canceled';
	public function fetch_action( $id ) { return new Harness_Action( $GLOBALS['W']['actions'][ (int) $id ] ?? array() ); }
	public function mark_failure( $id ) { $GLOBALS['W']['actions'][ (int) $id ]['status'] = self::STATUS_FAILED; world_save(); }
}
class ActionScheduler {
	private static $store = null;
	public static function store() { return self::$store ? self::$store : ( self::$store = new ActionScheduler_Store() ); }
}
class Harness_Action {
	private $a;
	public function __construct( array $a ) { $this->a = $a; }
	public function get_hook() { return $this->a['hook'] ?? ''; }
	public function get_args() { return $this->a['args'] ?? array(); }
	public function get_group() { return $this->a['group'] ?? ''; }
}
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
	$id = $GLOBALS['W']['next_action'];
	// A queue runner at work while a batch is still being scheduled.
	if ( isset( $GLOBALS['ON_SCHEDULE'] ) ) {
		call_user_func( $GLOBALS['ON_SCHEDULE'], $id, 'before' );
	}
	$GLOBALS['W']['next_action']++;
	$GLOBALS['W']['actions'][ $id ] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'status' => 'pending', 'date' => (int) $timestamp );
	world_save();
	if ( isset( $GLOBALS['ON_SCHEDULE'] ) ) {
		call_user_func( $GLOBALS['ON_SCHEDULE'], $id, 'after' );
	}
	return $id;
}
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { return as_schedule_single_action( time(), $hook, $args, $group ); }
function as_unschedule_all_actions( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['W']['actions'] as $id => $a ) {
		if ( $a['hook'] === $hook && 'pending' === $a['status'] && ( '' === $group || $a['group'] === $group ) ) {
			$GLOBALS['W']['actions'][ $id ]['status'] = 'canceled';
		}
	}
	world_save();
}
function as_get_scheduled_actions( $query = array(), $return_format = 'OBJECT' ) {
	$found = array();
	foreach ( $GLOBALS['W']['actions'] as $id => $a ) {
		if ( isset( $query['hook'] ) && $a['hook'] !== $query['hook'] ) { continue; }
		if ( isset( $query['group'] ) && $a['group'] !== $query['group'] ) { continue; }
		if ( isset( $query['status'] ) && $a['status'] !== $query['status'] ) { continue; }
		$found[ $id ] = 'ids' === $return_format ? $id : new Harness_Action( $a );
		if ( isset( $query['per_page'] ) && $query['per_page'] > 0 && count( $found ) >= $query['per_page'] ) { break; }
	}
	return $found;
}

/** The queue runner, for one action: claimed, run, and marked. */
function run_action( $id ) {
	$a = $GLOBALS['W']['actions'][ $id ];
	$GLOBALS['W']['actions'][ $id ]['status'] = 'in-progress';
	world_save();
	do_action( 'action_scheduler_before_execute', $id, 'WP Cron' );
	try {
		do_action( $a['hook'], ...array_values( $a['args'] ) );
		$GLOBALS['W']['actions'][ $id ]['status'] = 'complete';
		world_save();
		do_action( 'action_scheduler_after_execute', $id, null, 'WP Cron' );
	} catch ( Throwable $e ) {
		ActionScheduler::store()->mark_failure( $id );
		do_action( 'action_scheduler_failed_execution', $id, $e, 'WP Cron' );
	}
	return $id;
}
/** The next action due, as the runner claims them: by date, then by ID. */
function run_next() {
	$due = array();
	foreach ( $GLOBALS['W']['actions'] as $id => $a ) {
		if ( 'pending' === $a['status'] ) { $due[ $id ] = $a['date']; }
	}
	asort( $due );
	return $due ? run_action( key( $due ) ) : 0;
}
/** ActionScheduler_QueueCleaner::mark_failures(): what is still running five minutes on. */
function clean_queue() {
	foreach ( $GLOBALS['W']['actions'] as $id => $a ) {
		if ( 'in-progress' === $a['status'] ) {
			ActionScheduler::store()->mark_failure( $id );
			do_action( 'action_scheduler_failed_action', $id, 300 );
		}
	}
}
/** ActionScheduler_FatalErrorMonitor, as Action Scheduler has it. */
class Harness_Fatal_Error_Monitor {
	private $action_id = 0;
	public function attach() {
		add_action( 'shutdown', array( $this, 'handle_unexpected_shutdown' ) );
		add_action( 'action_scheduler_before_execute', array( $this, 'track_current_action' ), 0, 1 );
		add_action( 'action_scheduler_after_execute', array( $this, 'untrack_action' ), 0, 0 );
	}
	public function track_current_action( $action_id ) { $this->action_id = $action_id; }
	public function untrack_action() { $this->action_id = 0; }
	public function handle_unexpected_shutdown() {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) && ! empty( $this->action_id ) ) {
			ActionScheduler::store()->mark_failure( $this->action_id );
			do_action( 'action_scheduler_unexpected_shutdown', $this->action_id, $error );
		}
	}
}

// --- The plugin's neighbours -------------------------------------------------

/** The plugin's logger as it behaves: info held until shutdown, warnings and errors written at once. */
class TCGiant_Sync_Logger {
	private static $buffer     = array();
	private static $registered = false;
	public static function log( $message, $level = 'info' ) {
		self::$buffer[] = array( $level, (string) $message );
		if ( ! self::$registered ) {
			self::$registered = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 100 );
		}
		if ( in_array( $level, array( 'warning', 'error' ), true ) ) {
			self::flush();
		}
	}
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function write_now( $message, $level = 'error' ) { self::$buffer[] = array( $level, (string) $message ); self::flush(); }
	public static function flush() {
		if ( ! self::$buffer ) {
			return;
		}
		foreach ( self::$buffer as $line ) {
			$GLOBALS['W']['log'][] = $line;
		}
		self::$buffer = array();
		world_save();
	}
}
class TCGiant_Sync_OAuth {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_settings() {
		return array(
			'marketplace'               => 'EBAY_US',
			'export_category_id'        => '58058',
			'export_condition_id'       => '3000',
			'export_location'           => 'Leeds',
			'export_postal_code'        => 'LS1 4AP',
			'export_fulfillment_policy' => 'F-1',
			'export_return_policy'      => 'R-1',
			'export_payment_policy'     => 'P-1',
		);
	}
	public function get_access_token() { return 'harness-token'; }
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { $GLOBALS['W']['rows'][] = $row; world_save(); return true; }
	// No Listings table, so auto-relist reads its ended listings from post meta.
	public static function table_name() { return 'wp_tcgiant_sync_listings'; }
	public static function table_exists() { return false; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS = array( '_ebay_stock_settled_at', '_ebay_stock_settled_item' );
	// What a push asks before it lists an ended listing's product again: no
	// website sale was put back in stock, and the ended listing's sales are in.
	public static function restocked_after_website_sale( $product_id ) { return 0; }
	public static function listing_already_settled( $product_id, $item_id ) { return true; }
	// What a relist forgets about the listing it replaced.
	public static function clear_settled_mark( $product_id ) { return true; }
}
class TCGiant_Sync_Importer {
	const GROUP_SCAN = 'tcgiant_sync_group';
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
// The push, Link and Relist read the listing's eBay site through it.
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-exporter.php';
// Link completes a push that did not finish through it.
require $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require $ROOT . '/admin/class-tcgiant-sync-admin.php';
require $ROOT . '/includes/class-tcgiant-sync-cron.php';
require $ROOT . '/includes/class-tcgiant-sync-jobs.php';

/**
 * eBay, at the real API class's calls: it creates a listing for each
 * AddFixedPriceItem it is sent, and for each relist, numbered from the shop's
 * own, unless told to refuse one - or its answer never comes, and the call
 * throws.
 */
class Harness_eBay extends TCGiant_Sync_API {
	public function get_category_aspects( $category_id ) { return array(); }
	public function add_fixed_price_item( $item_xml ) { return $this->create( 'AddFixedPriceItem', $item_xml ); }
	public function add_item( $item_xml ) { return $this->create( 'AddItem', $item_xml ); }
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		preg_match( '#<ItemID>(\d+)</ItemID>#', $xml_body, $item );
		$item_id = (string) ( $item[1] ?? '' );

		if ( 'ReviseFixedPriceItem' === $call_name || 'ReviseItem' === $call_name ) {
			if ( isset( $GLOBALS['W']['ended'][ $item_id ] ) ) {
				return new WP_Error( 'ebay', 'You are not allowed to revise ended listings.' );
			}
			$GLOBALS['W']['revised'][] = $item_id;
			world_save();
			return array( 'Ack' => 'Success', 'ItemID' => $item_id );
		}

		if ( 'GetItem' === $call_name ) {
			if ( ! isset( $GLOBALS['W']['ebay'][ $item_id ] ) ) {
				return new WP_Error( 'ebay', 'Item not found.' );
			}
			preg_match( '#<Title>(.*?)</Title>#s', (string) $GLOBALS['W']['ebay'][ $item_id ]['xml'], $title );
			return array( 'Ack' => 'Success', 'Item' => array(
				'ItemID'        => $item_id,
				'Title'         => html_entity_decode( (string) ( $title[1] ?? '' ) ),
				'ListingType'   => 'FixedPriceItem',
				'Quantity'      => 4,
				'Seller'        => array( 'UserID' => 'gadget-central' ),
				'SellingStatus' => array( 'ListingStatus' => isset( $GLOBALS['W']['ended'][ $item_id ] ) ? 'Completed' : 'Active', 'QuantitySold' => 0, 'CurrentPrice' => '49.99' ),
			) );
		}

		// A relist is a new listing, of what the ended one was.
		if ( 'RelistFixedPriceItem' === $call_name || 'RelistItem' === $call_name ) {
			$old = $GLOBALS['W']['ebay'][ $item_id ] ?? array( 'product' => 0, 'xml' => '' );
			$new = (string) array_shift( $GLOBALS['W']['next_item'] );
			$GLOBALS['W']['ebay'][ $new ] = array( 'call' => $call_name, 'product' => $old['product'], 'xml' => $old['xml'] );
			world_save();
			return array( 'Ack' => 'Success', 'ItemID' => $new );
		}

		if ( 'GetUser' === $call_name ) {
			return array( 'Ack' => 'Success', 'User' => array( 'UserID' => 'gadget-central' ) );
		}

		return new WP_Error( 'harness', 'unexpected eBay call ' . $call_name );
	}
	private function create( $call, $xml ) {
		preg_match( '#<SKU>GC-(\d+)</SKU>#', $xml, $sku );
		$product_id = (int) ( $sku[1] ?? 0 );
		if ( isset( $GLOBALS['EBAY_REFUSES'][ $product_id ] ) ) {
			return new WP_Error( 'ebay', $GLOBALS['EBAY_REFUSES'][ $product_id ] );
		}
		if ( isset( $GLOBALS['EBAY_THROWS'][ $product_id ] ) ) {
			$thrown = $GLOBALS['EBAY_THROWS'][ $product_id ];
			throw $thrown instanceof Throwable ? $thrown : new RuntimeException( (string) $thrown );
		}
		$item_id = (string) array_shift( $GLOBALS['W']['next_item'] );
		$GLOBALS['W']['ebay'][ $item_id ] = array( 'call' => $call, 'product' => $product_id, 'xml' => $xml );
		world_save();
		if ( isset( $GLOBALS['AFTER_CREATE'] ) ) {
			call_user_func( $GLOBALS['AFTER_CREATE'], $item_id );
		}
		return array( 'Ack' => 'Success', 'ItemID' => $item_id );
	}
}

/** Put an eBay in place of the one TCGiant_Sync_API::instance() hands out. */
function use_ebay( $api ) {
	$slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
	if ( PHP_VERSION_ID < 80100 ) {
		$slot->setAccessible( true );
	}
	$slot->setValue( null, $api );
	return $api;
}

// --- The children: a push that dies ------------------------------------------

if ( in_array( $MODE, array( 'kill', 'oom', 'exit' ), true ) ) {
	// WordPress registers its shutdown action before any plugin code runs.
	register_shutdown_function( function () { do_action( 'shutdown' ); } );
	world_load();
	( new Harness_Fatal_Error_Monitor() )->attach();
	use_ebay( new Harness_eBay() );
	TCGiant_Sync_Exporter::instance();

	if ( 'kill' === $MODE ) {
		// eBay has made the listing; the host kills the request before the
		// answer is read. Waits to be killed - and gives up, rather than hang
		// the suite, if nobody does.
		$GLOBALS['AFTER_CREATE'] = function ( $item_id ) {
			echo 'CREATED ' . $item_id . "\n";
			fflush( STDOUT );
			sleep( 30 );
			exit( 3 );
		};
	} elseif ( 'oom' === $MODE ) {
		// Something hooked to the product save holds on to what it reads.
		ini_set( 'memory_limit', '64M' );
		$GLOBALS['ON_SAVE'] = function ( $product, $changed ) {
			if ( in_array( '_ebay_item_id', $changed, true ) ) {
				$held = array();
				for ( $i = 0; ; $i++ ) {
					$held[] = str_repeat( 'x', 65536 );
				}
			}
		};
	} else {
		// The request ends while eBay is still answering, with no PHP error to
		// say why: a host limit PHP is not told about, or exit() somewhere.
		$GLOBALS['AFTER_CREATE'] = function ( $item_id ) {
			exit;
		};
	}

	run_next();
	exit( 0 );
}

// --- The parent --------------------------------------------------------------

$pass = 0;
$fail = 0;

// The children leave the file to the parent; the parent leaves nothing.
register_shutdown_function( function () { @unlink( $GLOBALS['WORLD_FILE'] ); } );

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-78s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

/** A shop with these products, and eBay ready with these item numbers. */
function shop( array $products, array $item_numbers ) {
	world_fresh();
	foreach ( $products as $id => $title ) {
		$GLOBALS['W']['posts'][ $id ] = array( 'title' => $title );
	}
	$GLOBALS['W']['next_item'] = $item_numbers;
	$GLOBALS['EBAY_REFUSES']   = array();
	$GLOBALS['EBAY_THROWS']    = array();
	$GLOBALS['ON_SAVE']        = null;
	$GLOBALS['AFTER_CREATE']   = null;
	$GLOBALS['ON_SCHEDULE']    = null;
	$GLOBALS['ON_META']        = null;
	$GLOBALS['ON_CACHE_DROP']  = null;
	world_save();
}

/** Run one push in a child PHP process that dies, and read back what it left. */
function in_child( $mode ) {
	TCGiant_Sync_Logger::flush();
	world_save();
	$pipes = array();
	$proc  = proc_open(
		array( PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', __FILE__, $mode, $GLOBALS['WORLD_FILE'] ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
		$pipes
	);
	fclose( $pipes[0] );
	$said = '';

	if ( 'kill' === $mode ) {
		// Killed the moment eBay has the listing - as the host did.
		while ( false !== ( $line = fgets( $pipes[1] ) ) ) {
			$said .= $line;
			if ( 0 === strpos( $line, 'CREATED ' ) ) {
				proc_terminate( $proc, 9 );
				break;
			}
		}
	}

	$said .= (string) stream_get_contents( $pipes[1] );
	$said .= (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $proc );
	world_load();
	return $said;
}

function meta( $id, $key ) { return $GLOBALS['W']['meta'][ $id ][ $key ] ?? null; }
function lines() { TCGiant_Sync_Logger::flush(); return $GLOBALS['W']['log']; }
function said( $needle ) {
	$found = array();
	foreach ( lines() as $line ) {
		if ( false !== strpos( $line[1], $needle ) ) {
			$found[] = $line;
		}
	}
	return $found;
}
function action_status( $product_id ) {
	foreach ( $GLOBALS['W']['actions'] as $a ) {
		if ( TCGiant_Sync_Exporter::PUSH_ACTION === $a['hook'] && (int) $a['args']['product_id'] === $product_id ) {
			return $a['status'];
		}
	}
	return null;
}
function notice( $id, $where, $minutes_on = 0 ) {
	return TCGiant_Sync_Admin::push_state_notice( $id, (string) meta( $id, '_ebay_item_id' ), $where, time() + 60 * $minutes_on );
}
/** What 3.27.3 logged at the end of a batch, from the same figures - verbatim. */
function old_completion_line( array $state ) {
	return sprintf( 'Export complete! %d pushed, %d errors out of %d total.', $state['total_pushed'] + 1, $state['total_errors'], $state['total_queued'] );
}

use_ebay( new Harness_eBay() );
$exporter = TCGiant_Sync_Exporter::instance();

$GADGET = array(
	4717 => 'Logitech MX Keys Wireless Keyboard',
	4715 => 'Raspberry Pi 4 Model B 8GB',
	4712 => 'Sony WH-1000XM4 Headphones',
);
// Action Scheduler's cleaner goes by time alone, so it says what it saw. A
// death PHP saw says the server stopped it.
$DIED = 'The push of "Logitech MX Keys Wireless Keyboard" (WC #4717) did not finish - Action Scheduler found it still running 5 minutes on and gave up on it, so your server most likely stopped it. If the listing is on eBay, use Link on the product with its item number; pushing it again would list it twice.';

echo "\nGADGET CENTRAL'S BATCH: THREE PUSHED, THE FIRST KILLED AFTER EBAY HAD IT\n" . str_repeat( '=', 124 ) . "\n";

shop( $GADGET, array( '960068033342', '960068042852', '960068042881' ) );
check( 'the bulk push queues all three', $exporter->bulk_push_products( array_keys( $GADGET ) ), 3 );
check( '  each product says it is queued, as a push from its own screen does', array( meta( 4717, '_ebay_export_status' ), meta( 4715, '_ebay_export_status' ), meta( 4712, '_ebay_export_status' ) ), array( 'queued', 'queued', 'queued' ) );
check( '  and the batch knows which products are its own', get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['product_ids'], array( 4717, 4715, 4712 ) );

$child = in_child( 'kill' );
check( 'eBay created the first listing, and the request was killed there', 0 === strpos( $child, 'CREATED 960068033342' ) && isset( $W['ebay']['960068033342'] ), true );
check( '  the product never got the item number', meta( 4717, '_ebay_item_id' ), null );
check( '  but it says, in the database, that a push began', array( meta( 4717, '_ebay_export_status' ), meta( 4717, '_ebay_export_pushing_since' ) > time() - 120 ), array( 'pushing', true ) );
check( '  its action is left in progress, as the host left it', action_status( 4717 ), 'in-progress' );
check( '  nothing ran on the way out: the log holds only the bulk push being queued', array_column( lines(), 1 ), array( 'Export: Queued 3 products for bulk push to eBay.' ) );
check( 'the edit screen shows the push under way', false !== strpos( notice( 4717, 'panel' ), 'Pushing to eBay now - it started 1 min ago. Let it finish before pushing again.' ), true );
check( '  and so does the products list', notice( 4717, 'column' ), '<div class="tc-ebay-pushing">&#8987; Pushing to eBay...</div>' );
check( 'still pushing at 14 minutes: believed', false !== strpos( notice( 4717, 'panel', 14 ), 'Pushing to eBay now' ), true );
$stale = notice( 4717, 'panel', 16 );
check( 'at 16 minutes: did not finish - check eBay, then use Link', false !== strpos( $stale, 'Push did not finish - check eBay, then use Link.' ), true );
check( '  saying why, and that pushing again would list it twice', false !== strpos( $stale, 'It started 16 mins ago and never reported back, so your server most likely stopped it. If the listing is on eBay, give this product its item number with Link below; pushing it again would list it twice.' ), true );
check( '  with no Clear: Link clears it, or pushing again', false === strpos( $stale, 'id="tcgiant-dismiss-export-error"' ), true );
check( '  and the products list says the same', false !== strpos( notice( 4717, 'column', 16 ), '&#9888; Push did not finish - check eBay, then use Link.' ), true );

$SNAPSHOT = serialize( $W );

// A later queue run: Action Scheduler's cleaner gives up on the dead action
// first, then the two pushes that waited behind it run.
clean_queue();
check( 'Action Scheduler failing it says so: one line, naming the WC # and title', array_column( said( 'did not finish - Action Scheduler found it' ), 1 ), array( $DIED ) );
check( '  as an error', said( 'WC #4717) did not finish' )[0][0] ?? '', 'error' );
check( '  and on the product: failed, with the same words', array( meta( 4717, '_ebay_export_status' ), meta( 4717, '_ebay_export_error' ) ), array( 'failed', $DIED ) );
check( '  the batch is not over yet: two are still to run', said( 'Export finished' ) + said( 'Export complete' ), array() );

run_next();
run_next();
check( 'the other two are pushed and linked', array( meta( 4715, '_ebay_item_id' ), meta( 4712, '_ebay_item_id' ) ), array( '960068042852', '960068042881' ) );
check( '  with nothing left saying they are pushing', array( meta( 4715, '_ebay_export_pushing_since' ), meta( 4712, '_ebay_export_pushing_since' ) ), array( null, null ) );
$state = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION );
check( '3.27.3 called this batch complete, from these same figures', old_completion_line( $state ), 'Export complete! 3 pushed, 0 errors out of 3 total.' );
check( 'it is not called complete', said( 'Export complete' ), array() );
$end = said( 'Export finished' );
check( 'it says 2 of 3 pushed, 0 errors, 1 did not finish, and which one', 0 === strpos( $end[0][1] ?? '', 'Export finished: 2 of 3 pushed, 0 errors, 1 did not finish (WC #4717). A push that did not finish may still have reached eBay: check eBay, and where the listing is there, use Link on the product with its item number - pushing it again would list it twice.' ), true );
check( '  once, as a warning', array( count( $end ), $end[0][0] ?? '' ), array( 1, 'warning' ) );
check( '  and the export screen does not say Complete', $state['status'], 'incomplete' );
check( 'the product that died says so on its screen', false !== strpos( notice( 4717, 'panel' ), 'Push did not finish - check eBay, then use Link.' ) && false !== strpos( notice( 4717, 'panel' ), esc_html( $DIED ) ), true );
check( '  the list shows it, with the whole message to hover', false !== strpos( notice( 4717, 'column' ), 'title="' . esc_attr( $DIED ) . '">&#9888; Push did not finish - check eBay, then use Link.' ), true );
check( 'the two that finished show nothing of the kind', array( notice( 4715, 'panel' ), notice( 4712, 'column' ) ), array( '', '' ) );

echo "\nTHE SAME BATCH, THE OTHER TWO RUNNING WHILE THE DEAD PUSH STILL LOOKS ALIVE\n" . str_repeat( '=', 124 ) . "\n";

// A host running two queue runners at once: the pushes behind the dead one
// do not wait for Action Scheduler to give up on it.
$W = unserialize( $SNAPSHOT );
world_save();
run_next();
run_next();
check( 'both pushed', array( meta( 4715, '_ebay_export_status' ), meta( 4712, '_ebay_export_status' ) ), array( 'pushed', 'pushed' ) );
check( '  not called complete while a push is still in progress', array( said( 'Export complete' ), said( 'Export finished' ), get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['status'] ), array( array(), array(), 'pushing' ) );
check( '3.27.3 would have said complete here, with three pushed', old_completion_line( get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION ) ), 'Export complete! 3 pushed, 0 errors out of 3 total.' );
clean_queue();
$order = array_values( array_filter( array_map( function ( $l ) { return false !== strpos( $l[1], 'did not finish' ) ? substr( $l[1], 0, 30 ) : null; }, lines() ) ) );
check( 'once it is failed: the push that died, then the end of the batch', $order, array( 'The push of "Logitech MX Keys ', 'Export finished: 2 of 3 pushed' ) );
// The state is an autoloaded option, and nothing reads the batch's list once
// the batch is over: up to a whole products page of IDs, loaded on every
// request until the next bulk push.
$state = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION );
check( '  the batch is over, and lets its list of products go', array( $state['status'], array_key_exists( 'product_ids', $state ) ? $state['product_ids'] : 'never kept' ), array( 'incomplete', null ) );

echo "\nA CLEAN BATCH, AND ONE EBAY REFUSES PART OF\n" . str_repeat( '=', 124 ) . "\n";

shop( $GADGET, array( '960068100001', '960068100002', '960068100003' ) );
$exporter->bulk_push_products( array_keys( $GADGET ) );
run_next();
run_next();
run_next();
$state = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION );
check( 'a clean batch of three says three', array_column( said( 'Export complete' ), 1 ), array( 'Export complete! 3 pushed, 0 errors out of 3 total.' ) );
check( '  where 3.27.3 said four', old_completion_line( $state ), 'Export complete! 4 pushed, 0 errors out of 3 total.' );
check( '  complete', $state['status'], 'complete' );
check( '  and lets its list of products go', array_key_exists( 'product_ids', $state ) ? $state['product_ids'] : 'never kept', null );
check( '  every product pushed, linked, and no longer pushing', array( meta( 4717, '_ebay_export_status' ), meta( 4717, '_ebay_item_id' ), meta( 4717, '_ebay_export_pushing_since' ) ), array( 'pushed', '960068100001', null ) );
check( '  nothing said about a push not finishing', said( 'did not finish' ), array() );

shop( $GADGET, array( '960068200001', '960068200002' ) );
$EBAY_REFUSES = array( 4715 => 'The item specific Type is missing.' );
$exporter->bulk_push_products( array_keys( $GADGET ) );
run_next();
run_next();
run_next();
check( 'one refused by eBay: 2 pushed, 1 errors, out of 3 - complete', array_column( said( 'Export complete' ), 1 ), array( 'Export complete! 2 pushed, 1 errors out of 3 total.' ) );
check( '  the refused one keeps eBay\'s reason, and is not left pushing', array( meta( 4715, '_ebay_export_status' ), meta( 4715, '_ebay_export_error' ), meta( 4715, '_ebay_export_pushing_since' ) ), array( 'error', 'The item specific Type is missing.', null ) );
check( '  it is not a push that did not finish', array( said( 'did not finish' ), notice( 4715, 'panel' ) ), array( array(), '' ) );

echo "\nA PUSH FROM A PRODUCT'S OWN SCREEN DURING A BULK PUSH IS NOT COUNTED INTO IT\n" . str_repeat( '=', 124 ) . "\n";

shop( $GADGET + array( 4800 => 'USB-C Hub 7-in-1' ), array( '960068300001', '960068300002', '960068300003' ) );
$exporter->bulk_push_products( array( 4717, 4715 ) );
$exporter->push_product( 4800 );
$own = 0;
foreach ( $W['actions'] as $id => $a ) {
	if ( 4800 === (int) $a['args']['product_id'] ) { $own = $id; }
}
run_action( $own );
run_next();
check( 'the product\'s own push and one of the batch\'s two: not over yet', said( 'Export complete' ), array() );
run_next();
check( 'the batch of two says two, once both are done', array_column( said( 'Export complete' ), 1 ), array( 'Export complete! 2 pushed, 0 errors out of 2 total.' ) );
check( '  the other push went through all the same', meta( 4800, '_ebay_export_status' ), 'pushed' );

// One of the batch's own products, pushed again from its screen: counted
// twice, so the figures add up with the batch's last push still waiting.
shop( $GADGET, array( '960068310001', '960068310002', '960068310003' ) );
$exporter->bulk_push_products( array( 4717, 4715 ) );
$exporter->push_product( 4717 );
run_action( 3 );
run_next();
check( 'a batch product pushed twice: the figures add up, the batch is not over', array( get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['total_pushed'], said( 'Export complete' ), said( 'Export finished' ) ), array( 2, array(), array() ) );
run_next();
check( '  it ends when its last push has run: two pushed', array_column( array_merge( said( 'Export complete' ), said( 'Export finished' ) ), 1 ), array( 'Export complete! 2 pushed, 0 errors out of 2 total.' ) );

echo "\nTWO QUEUE RUNNERS, EACH WRITING BACK ITS OWN COPY OF THE FIGURES\n" . str_repeat( '=', 124 ) . "\n";

// Runner B began before runner A counted its push. WordPress keeps an
// autoloaded option for the length of a request, so B adds its push to the
// figures as they were, and writes them back over A's.
shop( array( 4760 => 'Anker 735 Charger', 4761 => 'Belkin BoostCharge Pro Pad' ), array( '960068700001', '960068700002' ) );
$exporter->bulk_push_products( array( 4760, 4761 ) );
$as_b_saw_it = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION );
run_next();
check( 'runner A counts its push', get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['total_pushed'], 1 );
// Runner B adds its own to the copy it read before that, and writes it back.
$W['options'][ TCGiant_Sync_Exporter::EXPORT_STATE_OPTION ] = $as_b_saw_it;
run_next();
check( 'both pushed and linked', array( meta( 4760, '_ebay_export_status' ), meta( 4761, '_ebay_export_status' ), meta( 4760, '_ebay_item_id' ), meta( 4761, '_ebay_item_id' ) ), array( 'pushed', 'pushed', '960068700001', '960068700002' ) );
check( 'the end of the batch goes by the products: complete, two pushed', array_column( array_merge( said( 'Export complete' ), said( 'Export finished' ) ), 1 ), array( 'Export complete! 2 pushed, 0 errors out of 2 total.' ) );
check( '  and the Export screen agrees', array( get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['status'], get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['total_pushed'] ), array( 'complete', 2 ) );

echo "\nA PUSH THAT RUNS WHILE THE REST OF ITS BATCH IS STILL BEING SCHEDULED\n" . str_repeat( '=', 124 ) . "\n";

// The first push is due at once, and a thousand take seconds to schedule: a
// queue runner can claim and finish it before the bulk push has finished.
shop( $GADGET, array( '960068710001', '960068710002', '960068710003' ) );
$ON_SCHEDULE = function ( $id, $when ) {
	if ( 2 === $id && 'after' === $when ) {
		run_action( 1 );
		// What that push dropped is its own count's: the bulk push's is after.
		$GLOBALS['CACHE_DROPPED'] = array();
	}
};
$CACHE_DROPPED = array();
$exporter->bulk_push_products( array_keys( $GADGET ) );
$ON_SCHEDULE = null;
$state = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION );
check( 'it is counted in the batch, not wiped by the batch being set up', array( $state['status'], $state['total_queued'], $state['total_pushed'] ), array( 'pushing', 3, 1 ) );
check( '  the batch is opened from the state as the database has it, not this request\'s copy', array_values( array_intersect( $CACHE_DROPPED, array( 'options/alloptions', 'options/' . TCGiant_Sync_Exporter::EXPORT_STATE_OPTION ) ) ), array( 'options/alloptions', 'options/' . TCGiant_Sync_Exporter::EXPORT_STATE_OPTION ) );
check( '  its product says pushed, not queued', array( meta( 4717, '_ebay_export_status' ), meta( 4717, '_ebay_item_id' ) ), array( 'pushed', '960068710001' ) );
check( '  the batch is not over', array( said( 'Export complete' ), said( 'Export finished' ) ), array( array(), array() ) );
run_next();
run_next();
check( 'it ends: three pushed', array_column( array_merge( said( 'Export complete' ), said( 'Export finished' ) ), 1 ), array( 'Export complete! 3 pushed, 0 errors out of 3 total.' ) );

// Every push of the batch run before it is done being scheduled: none of
// them could end it, so it ends itself.
shop( array( 4730 => 'Anker 737 Power Bank' ), array( '960068790001' ) );
$ON_SCHEDULE = function ( $id, $when ) {
	if ( 1 === $id && 'after' === $when ) {
		run_action( 1 );
	}
};
$exporter->bulk_push_products( array( 4730 ) );
$ON_SCHEDULE = null;
check( 'a batch whose every push ran while it was scheduled ends once that is done', array( array_column( said( 'Export complete' ), 1 ), get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['status'] ), array( array( 'Export complete! 1 pushed, 0 errors out of 1 total.' ), 'complete' ) );

echo "\nA QUEUE RUNNER THAT BEGAN WHILE ITS BATCH WAS STILL BEING SCHEDULED\n" . str_repeat( '=', 124 ) . "\n";

// WordPress keeps an autoloaded option for the length of a request, and a
// queue runner that starts while a bulk push is still being scheduled holds
// the state as "scheduling". Each push it runs writes the whole state back:
// written from that copy, the batch said it was being scheduled again, and
// nothing ended it until it was fifteen minutes old.
shop( $GADGET, array( '960068740001', '960068740002', '960068740003' ) );
$runner_copy = null;
$ON_SCHEDULE = function ( $id, $when ) {
	if ( 1 === $id && 'before' === $when ) {
		$GLOBALS['runner_copy'] = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION );
	}
};
$exporter->bulk_push_products( array_keys( $GADGET ) );
$ON_SCHEDULE = null;
$in_database = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION );
check( 'the runner read the batch as being scheduled; it has been opened since', array( $runner_copy['status'], $in_database['status'] ), array( 'scheduling', 'pushing' ) );
// The runner's copy, until it is dropped and the next read is the database's.
$W['options'][ TCGiant_Sync_Exporter::EXPORT_STATE_OPTION ] = $runner_copy;
$ON_CACHE_DROP = function ( $key ) use ( $in_database ) {
	if ( 'options/alloptions' !== $key ) {
		return false;
	}
	$GLOBALS['W']['options'][ TCGiant_Sync_Exporter::EXPORT_STATE_OPTION ] = $in_database;
	world_save();
	return true;
};
$CACHE_DROPPED = array();
run_next();
$ON_CACHE_DROP = null;
$state = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION );
check( 'its first push is counted from the state as the database has it', in_array( 'options/alloptions', $CACHE_DROPPED, true ), true );
check( '  and leaves the batch open, not being scheduled again', array( $state['status'], $state['total_pushed'] ), array( 'pushing', 1 ) );
run_next();
run_next();
check( 'the batch ends when its three are done, not fifteen minutes on', array_column( array_merge( said( 'Export complete' ), said( 'Export finished' ) ), 1 ), array( 'Export complete! 3 pushed, 0 errors out of 3 total.' ) );

echo "\nA PUSH LEFT OVER FROM THE BATCH BEFORE DOES NOT END THE NEXT ONE\n" . str_repeat( '=', 124 ) . "\n";

shop( $GADGET + array( 4800 => 'USB-C Hub 7-in-1' ), array( '960068720001', '960068720002', '960068720003' ) );
$exporter->bulk_push_products( array( 4800 ) );
$W['actions'][1]['status'] = 'in-progress'; // claimed, and still waiting for eBay
// It comes back after the next batch's figures are written, before any of
// that batch's pushes is scheduled.
$ON_SCHEDULE = function ( $id, $when ) {
	if ( 2 === $id && 'before' === $when ) {
		do_action( TCGiant_Sync_Exporter::PUSH_ACTION, 4800 );
		$GLOBALS['W']['actions'][1]['status'] = 'complete';
	}
};
$exporter->bulk_push_products( array( 4717, 4715 ) );
$ON_SCHEDULE = null;
$state = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION );
check( 'the push from the batch before went through', array( meta( 4800, '_ebay_export_status' ), meta( 4800, '_ebay_item_id' ) ), array( 'pushed', '960068720001' ) );
check( '  without ending the new batch before it began, or counting in it', array( said( 'Export complete' ), said( 'Export finished' ), $state['status'], $state['total_pushed'] ), array( array(), array(), 'pushing', 0 ) );
run_next();
run_next();
check( 'the new batch ends when its own two are done', array_column( array_merge( said( 'Export complete' ), said( 'Export finished' ) ), 1 ), array( 'Export complete! 2 pushed, 0 errors out of 2 total.' ) );

echo "\nTHE SAME BULK PUSH STARTED AGAIN WHILE ONE OF ITS PUSHES IS STILL RUNNING\n" . str_repeat( '=', 124 ) . "\n";

// A second press of the coin-refresh button - from a page loaded before the
// first - starts the same bulk push again. The new batch marks its products
// queued before it schedules a push, and #4717's push from the first batch
// came back while that was going on: it found nothing waiting to run and the
// other two saying queued, ended the new batch there and then with both as
// pushes that did not finish, and nothing was said when they had pushed.
shop( $GADGET, array( '960068730001', '960068730002', '960068730003' ) );
$exporter->bulk_push_products( array_keys( $GADGET ) );
$W['actions'][1]['status'] = 'in-progress'; // #4717's push, waiting for eBay
world_save();
$while_marking = null;
$ON_META       = function ( $id, $key, $value ) {
	if ( 4712 === $id && '_ebay_export_status' === $key && 'queued' === $value ) {
		$GLOBALS['while_marking'] = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['status'];
		do_action( TCGiant_Sync_Exporter::PUSH_ACTION, 4717 );
		$GLOBALS['W']['actions'][1]['status'] = 'complete';
		world_save();
		return true;
	}
	return false;
};
$exporter->bulk_push_products( array_keys( $GADGET ) );
$ON_META = null;
check( 'while the new batch marks its products, it says it is being scheduled', $while_marking, 'scheduling' );
check( '  the push from before goes through, and does not end the new batch', array( meta( 4717, '_ebay_export_status' ), said( 'Export complete' ), said( 'Export finished' ) ), array( 'pushed', array(), array() ) );
run_next();
run_next();
run_next();
check( 'it ends when its own three have run: complete, three pushed', array_column( array_merge( said( 'Export complete' ), said( 'Export finished' ) ), 1 ), array( 'Export complete! 3 pushed, 0 errors out of 3 total.' ) );
check( '  and the Export screen says Complete', get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['status'], 'complete' );

echo "\nA PUSH THAT IS NOT THE BATCH'S IS THE LAST TO RUN, AFTER THE BATCH'S DEAD PUSH IS FAILED\n" . str_repeat( '=', 124 ) . "\n";

// The batch's last event is often the queue cleaner failing its dead push,
// five minutes on. A push from a product's own screen waiting just then
// left the end to that push - which was not allowed to end the batch.
shop( $GADGET + array( 4800 => 'USB-C Hub 7-in-1' ), array( '960068760001', '960068760002' ) );
$exporter->bulk_push_products( array( 4717, 4715 ) );
$W['actions'][1]['status'] = 'in-progress'; // #4717's push, killed
update_post_meta( 4717, '_ebay_export_status', 'pushing' );
update_post_meta( 4717, '_ebay_export_pushing_since', time() - 600 );
run_action( 2 );
$exporter->push_product( 4800 );
clean_queue(); // the next queue run begins with its cleanup, #4800 waiting
check( 'the dead push is failed with another push waiting: not over yet', array( meta( 4717, '_ebay_export_status' ), said( 'Export finished' ), said( 'Export complete' ) ), array( 'failed', array(), array() ) );
run_next(); // then runs what is due: #4800
check( 'that push ends it: 1 of 2 pushed, 1 did not finish, naming it', 0 === strpos( said( 'Export finished' )[0][1] ?? '', 'Export finished: 1 of 2 pushed, 0 errors, 1 did not finish (WC #4717).' ), true );
check( '  once, and the Export screen agrees', array( count( said( 'Export finished' ) ) + count( said( 'Export complete' ) ), get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['status'] ), array( 1, 'incomplete' ) );
check( '  and the push that ended it is not counted in it', meta( 4800, '_ebay_export_status' ), 'pushed' );

echo "\nTWO QUEUE RUNNERS FINISHING THE BATCH'S LAST TWO PUSHES TOGETHER\n" . str_repeat( '=', 124 ) . "\n";

// Each push asks whether its batch is over while Action Scheduler still
// counts it as running. Two at once each see the other's still running - and
// with one count written over the other's, the figures do not add up either
// - so neither ends the batch, and nothing used to ask again.
shop( array( 4760 => 'Anker 735 Charger', 4761 => 'Belkin BoostCharge Pro Pad' ), array( '960068770001', '960068770002' ) );
$exporter->bulk_push_products( array( 4760, 4761 ) );
$W['actions'][1]['status'] = 'in-progress';
$W['actions'][2]['status'] = 'in-progress';
world_save();
$as_b_saw_it = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION );
do_action( TCGiant_Sync_Exporter::PUSH_ACTION, 4760 ); // runner A
$W['options'][ TCGiant_Sync_Exporter::EXPORT_STATE_OPTION ] = $as_b_saw_it;
do_action( TCGiant_Sync_Exporter::PUSH_ACTION, 4761 ); // runner B, from its own copy
$W['actions'][1]['status'] = 'complete';
$W['actions'][2]['status'] = 'complete';
world_save();
check( 'both pushed, and neither push ended the batch', array( meta( 4760, '_ebay_export_status' ), meta( 4761, '_ebay_export_status' ), said( 'Export complete' ), said( 'Export finished' ), get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['status'] ), array( 'pushed', 'pushed', array(), array(), 'pushing' ) );
$CACHE_DROPPED = array();
do_action( 'action_scheduler_after_process_queue' ); // runner A's queue run is over
check( 'once a queue run is over it is asked again: complete, two pushed', array_column( said( 'Export complete' ), 1 ), array( 'Export complete! 2 pushed, 0 errors out of 2 total.' ) );
check( '  of the database, which the other runner may have written since', in_array( 'options/alloptions', $CACHE_DROPPED, true ), true );
do_action( 'action_scheduler_after_process_queue' ); // and runner B's
check( '  said once, though both runners ask', count( said( 'Export complete' ) ) + count( said( 'Export finished' ) ), 1 );

echo "\nA BULK PUSH STOPPED PART WAY THROUGH SCHEDULING\n" . str_repeat( '=', 124 ) . "\n";

// Nothing ends a batch while it is being scheduled - so one whose request
// was stopped part way would say so for good, were it not looked at again.
shop( $GADGET, array( '960068780001' ) );
$ON_SCHEDULE = function ( $id, $when ) {
	if ( 2 === $id && 'before' === $when ) {
		throw new RuntimeException( 'the host stopped the request' );
	}
};
try {
	$exporter->bulk_push_products( array_keys( $GADGET ) );
} catch ( RuntimeException $stopped ) {
	unset( $stopped );
}
$ON_SCHEDULE = null;
run_next(); // the one push it scheduled
do_action( 'action_scheduler_after_process_queue' );
check( 'the push it scheduled runs; the batch still says it is being scheduled', array( meta( 4717, '_ebay_export_status' ), get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['status'], said( 'Export complete' ), said( 'Export finished' ) ), array( 'pushed', 'scheduling', array(), array() ) );
// Fifteen minutes on, whatever was scheduling it is long gone.
$W['options'][ TCGiant_Sync_Exporter::EXPORT_STATE_OPTION ]['started_at'] = gmdate( 'Y-m-d H:i:s', time() - 20 * 60 );
world_save();
do_action( 'action_scheduler_after_process_queue' );
check( 'a queue run after that ends it, naming the two it never scheduled', 0 === strpos( said( 'Export finished' )[0][1] ?? '', 'Export finished: 1 of 3 pushed, 0 errors, 2 did not finish (WC #4715, #4712).' ), true );
check( '  and the Export screen says so', get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['status'], 'incomplete' );

// Two bulk pushes at once: a second, in another request, begins while the
// first is still scheduling. The first, done, leaves the second's alone.
shop( $GADGET + array( 4800 => 'USB-C Hub 7-in-1' ), array() );
$ON_SCHEDULE = function ( $id, $when ) {
	if ( 3 === $id && 'after' === $when ) {
		$state                = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION );
		$state['product_ids'] = array( 4800 );
		update_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION, $state );
	}
};
$exporter->bulk_push_products( array_keys( $GADGET ) );
$ON_SCHEDULE = null;
$state = get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION );
check( 'a bulk push done scheduling leaves one begun since still scheduling', array( $state['status'], $state['product_ids'] ), array( 'scheduling', array( 4800 ) ) );

echo "\nA PUSH CLAIMED, AND KILLED BEFORE IT SAID IT WAS PUSHING\n" . str_repeat( '=', 124 ) . "\n";

// The request can die between Action Scheduler claiming the push and the
// push marking its product pushing - or a newer bulk push of the same
// product can have marked it queued meanwhile. Either way it still says
// queued when Action Scheduler gives up on it.
shop( array( 4722 => 'TP-Link Archer AX55 Router' ), array() );
$exporter->bulk_push_products( array( 4722 ) );
$W['actions'][1]['status'] = 'in-progress';
world_save();
clean_queue();
$early = 'The push of "TP-Link Archer AX55 Router" (WC #4722) did not finish - Action Scheduler found it still running 5 minutes on and gave up on it, so your server most likely stopped it. If the listing is on eBay, use Link on the product with its item number; pushing it again would list it twice.';
check( 'Action Scheduler failing it says so, though the product still said queued', array_column( said( 'did not finish - ' ), 1 ), array( $early ) );
check( '  on the product: failed, with the same words', array( meta( 4722, '_ebay_export_status' ), meta( 4722, '_ebay_export_error' ) ), array( 'failed', $early ) );
check( '  and the batch ends: 0 of 1 pushed, 1 did not finish', 0 === strpos( said( 'Export finished' )[0][1] ?? '', 'Export finished: 0 of 1 pushed, 0 errors, 1 did not finish (WC #4722).' ), true );

echo "\nWHICH OF A BATCH DID NOT FINISH, ASKED OF THE DATABASE\n" . str_repeat( '=', 124 ) . "\n";

$unfinished = new ReflectionMethod( 'TCGiant_Sync_Exporter', 'unfinished_in_batch' );
if ( PHP_VERSION_ID < 80100 ) {
	$unfinished->setAccessible( true );
}
shop( array(), array() );
$coins = range( 20001, 21200 );
foreach ( $coins as $coin ) {
	$W['meta'][ $coin ]['_ebay_export_status'] = 'pushed';
}
$W['meta'][21150]['_ebay_export_status'] = 'failed';
$W['meta'][20002]['_ebay_export_status'] = 'queued';
$W['meta'][20600]['_ebay_export_status'] = 'pushing';
$W['meta'][20601]['_ebay_export_status'] = 'error';
$wpdb->asked = array();
check( 'a batch of 1,200 is read in three queries, not 1,200 product loads', array( $unfinished->invoke( null, $coins ), count( $wpdb->asked ) ), array( array( 20002, 20600, 21150 ), 3 ) );

echo "\nA PUSH THAT THROWS AFTER EBAY MADE THE LISTING, AND ONE THAT THROWS BEFORE\n" . str_repeat( '=', 124 ) . "\n";

shop( array( 4730 => 'Anker 737 Power Bank' ), array( '960068400001' ) );
$exporter->bulk_push_products( array( 4730 ) );
$ON_SAVE = function ( $product, $changed ) {
	if ( in_array( '_ebay_item_id', $changed, true ) ) {
		throw new RuntimeException( 'Deadlock found when trying to get lock' );
	}
};
run_next();
$ON_SAVE = null;
$threw   = 'Export exception for "Anker 737 Power Bank" (WC #4730): Deadlock found when trying to get lock eBay had already created listing 960068400001 for it: use Link on the product with that item number; pushing it again would list it twice.';
check( 'the exception is logged, with the listing eBay had made', array_column( said( 'Export exception' ), 1 ), array( $threw ) );
// A push that did not finish, as one the host killed is. Said to be an error,
// it read as one eBay had refused: Link left it so, Clear was offered on it,
// and "Remove sold products" trashed it when its listing sold out.
check( '  the product says its push did not finish, with the same words, not pushing', array( meta( 4730, '_ebay_export_status' ), meta( 4730, '_ebay_export_error' ), meta( 4730, '_ebay_export_pushing_since' ) ), array( 'failed', $threw, null ) );
check( '  the batch names it as not finished, and does not count it as an error too', array( 0 === strpos( said( 'Export finished' )[0][1] ?? '', 'Export finished: 0 of 1 pushed, 0 errors, 1 did not finish (WC #4730).' ), said( 'Export complete' ) ), array( true, array() ) );
$panel = notice( 4730, 'panel' );
check( '  its screen: check eBay, then use Link, in the same words', false !== strpos( $panel, 'Push did not finish - check eBay, then use Link.' ) && false !== strpos( $panel, esc_html( $threw ) ), true );
$answer = link_product( 4730, '960068400001' );
check( 'Link, as it says: pushed, dated as pushed, and the warning gone', array( $answer ? $answer->success : null, meta( 4730, '_ebay_item_id' ), meta( 4730, '_ebay_export_status' ), meta( 4730, '_ebay_export_error' ), '' !== (string) meta( 4730, '_ebay_export_last_pushed' ) ), array( true, '960068400001', 'pushed', '', true ) );

// The same from PHP's own errors, which the push catches apart.
shop( array( 4733 => 'Anker 533 USB-C Hub' ), array( '960068430001' ) );
$exporter->bulk_push_products( array( 4733 ) );
$ON_SAVE = function ( $product, $changed ) {
	if ( in_array( '_ebay_item_id', $changed, true ) ) {
		throw new TypeError( 'Argument #1 ($post) must be of type WP_Post, null given' );
	}
};
run_next();
$ON_SAVE = null;
check( 'a fatal error once eBay made the listing: the same', array( meta( 4733, '_ebay_export_status' ), 0 === strpos( (string) meta( 4733, '_ebay_export_error' ), 'Export fatal error for "Anker 533 USB-C Hub" (WC #4733)' ), 0 === strpos( said( 'Export finished' )[0][1] ?? '', 'Export finished: 0 of 1 pushed, 0 errors, 1 did not finish (WC #4733).' ) ), array( 'failed', true, true ) );

// Nothing made on eBay: an error, counted as one, as it always was.
shop( array( 4731 => 'Anker 313 Wall Charger' ), array( '960068410001' ) );
$EBAY_THROWS = array( 4731 => 'cURL error 28: Operation timed out' );
$exporter->bulk_push_products( array( 4731 ) );
run_next();
check( 'a push that throws before eBay answers: an error, naming no listing', array( meta( 4731, '_ebay_export_status' ), meta( 4731, '_ebay_export_error' ), $W['ebay'] ), array( 'error', 'Export exception for "Anker 313 Wall Charger" (WC #4731): cURL error 28: Operation timed out', array() ) );
check( '  counted as an error, so the batch adds up', array_column( array_merge( said( 'Export complete' ), said( 'Export finished' ) ), 1 ), array( 'Export complete! 0 pushed, 1 errors out of 1 total.' ) );
shop( array( 4734 => 'Anker 341 USB-C Hub' ), array() );
$EBAY_THROWS = array( 4734 => new TypeError( 'array_key_exists(): Argument #2 ($array) must be of type array, null given' ) );
$exporter->bulk_push_products( array( 4734 ) );
run_next();
check( '  the same from a PHP error, counted as one', array( meta( 4734, '_ebay_export_status' ), array_column( array_merge( said( 'Export complete' ), said( 'Export finished' ) ), 1 ) ), array( 'error', array( 'Export complete! 0 pushed, 1 errors out of 1 total.' ) ) );

shop( array( 4732 => 'Anker 621 Magnetic Battery' ), array() );
update_post_meta( 4732, '_ebay_item_id', '960068420001' );
update_post_meta( 4732, '_ebay_listing_status', 'Active' );
$exporter->bulk_push_products( array( 4732 ) );
$ON_SAVE = function ( $product, $changed ) {
	if ( in_array( '_ebay_item_id', $changed, true ) ) {
		throw new RuntimeException( 'Deadlock found when trying to get lock' );
	}
};
run_next();
$ON_SAVE = null;
check( 'a revise that throws saving: eBay listed nothing new, so it is an error', array( $W['revised'], $W['ebay'], meta( 4732, '_ebay_export_status' ), meta( 4732, '_ebay_export_error' ) ), array( array( '960068420001' ), array(), 'error', 'Export exception for "Anker 621 Magnetic Battery" (WC #4732): Deadlock found when trying to get lock' ) );
check( '  counted as one', array_column( array_merge( said( 'Export complete' ), said( 'Export finished' ) ), 1 ), array( 'Export complete! 0 pushed, 1 errors out of 1 total.' ) );

echo "\nA PUSH THAT RUNS OUT OF MEMORY SAVING THE LINK (A CHILD PROCESS)\n" . str_repeat( '=', 124 ) . "\n";

shop( array( 4720 => 'Netgear GS308 8-Port Switch' ), array( '960068500001' ) );
$exporter->bulk_push_products( array( 4720 ) );
$child = in_child( 'oom' );
$death = said( 'Push stopped by a PHP fatal error' );
check( 'the death is recorded, naming the product and the step', 1 === count( $death ) && false !== strpos( $death[0][1], 'Push stopped by a PHP fatal error: Allowed memory size of 67108864 bytes exhausted' ) && false !== strpos( $death[0][1], 'while working on WC #4720 "Netgear GS308 8-Port Switch" - saving the link.' ), true );
$oom = 'The push of "Netgear GS308 8-Port Switch" (WC #4720) did not finish - your server stopped it after eBay created listing 960068500001. Use Link on the product with that item number; pushing it again would list it twice.';
check( '  then once what to do about it, with the item number eBay gave', array_column( said( 'did not finish - your server' ), 1 ), array( $oom ) );
check( '  on the product: failed, with the same words', array( meta( 4720, '_ebay_export_status' ), meta( 4720, '_ebay_export_error' ) ), array( 'failed', $oom ) );
check( '  Action Scheduler marked the action failed', action_status( 4720 ), 'failed' );
$order = array_map( function ( $l ) { return substr( $l[1], 0, 20 ); }, array_slice( lines(), -3 ) );
check( '  in order: the death, what to do, the end of the batch', $order, array( 'Push stopped by a PH', 'The push of "Netgear', 'Export finished: 0 o' ) );
check( '  the batch ends: 0 of 1 pushed, 1 did not finish, naming it', 0 === strpos( said( 'Export finished' )[0][1] ?? '', 'Export finished: 0 of 1 pushed, 0 errors, 1 did not finish (WC #4720).' ), true );
check( '  the death is kept as the push\'s own', 0 === strpos( (string) ( get_option( 'tcgiant_sync_last_crash_push' )['message'] ?? '' ), 'Push stopped by a PHP fatal error' ), true );
check( '  which uninstall removes', false !== strpos( (string) file_get_contents( $ROOT . '/uninstall.php' ), "'tcgiant_sync_last_crash_push'," ), true );
if ( 1 !== count( $death ) ) {
	echo $child;
}

echo "\nA PUSH WHOSE REQUEST ENDS WITH NO ERROR WHILE EBAY ANSWERS (A CHILD PROCESS)\n" . str_repeat( '=', 124 ) . "\n";

shop( array( 4721 => 'TP-Link Deco X20 Mesh' ), array( '960068600001' ) );
$exporter->bulk_push_products( array( 4721 ) );
in_child( 'exit' );
$ended = 'The push of "TP-Link Deco X20 Mesh" (WC #4721) did not finish - your server stopped it. If the listing is on eBay, use Link on the product with its item number; pushing it again would list it twice.';
check( 'the death is recorded, waiting for eBay', 1 === count( said( 'Push ended without finishing, by exit() or a host limit while working on WC #4721 "TP-Link Deco X20 Mesh" - sending to eBay.' ) ), true );
check( '  then what to do about it', array_column( said( 'did not finish - your server' ), 1 ), array( $ended ) );
check( '  on the product: failed', array( meta( 4721, '_ebay_export_status' ), meta( 4721, '_ebay_export_error' ) ), array( 'failed', $ended ) );
check( '  Action Scheduler still thinks it is running; the batch waits', array( action_status( 4721 ), said( 'Export finished' ) ), array( 'in-progress', array() ) );
clean_queue();
check( 'Action Scheduler failing it later says nothing twice', count( said( '(WC #4721) did not finish - ' ) ), 1 );
check( '  and ends the batch', 0 === strpos( said( 'Export finished' )[0][1] ?? '', 'Export finished: 0 of 1 pushed, 0 errors, 1 did not finish (WC #4721).' ), true );

echo "\nWHAT ACTION SCHEDULER IS ASKED, AND WHAT IS LEFT ALONE\n" . str_repeat( '=', 124 ) . "\n";

$bound = array();
foreach ( array( 'action_scheduler_failed_action', 'action_scheduler_unexpected_shutdown', 'action_scheduler_failed_execution', 'action_scheduler_failed_old_action' ) as $hook ) {
	foreach ( $HOOKS[ $hook ] ?? array() as $h ) {
		if ( is_array( $h[2] ) && $h[2][0] === $exporter ) {
			$bound[ $hook ] = array( $h[2][1], $h[3] );
		}
	}
}
check( 'the push listens for each way Action Scheduler sees an action end unfinished', $bound, array(
	'action_scheduler_failed_action'       => array( 'on_push_action_failed', 2 ),
	'action_scheduler_unexpected_shutdown' => array( 'on_push_action_shutdown', 2 ),
	'action_scheduler_failed_execution'    => array( 'on_push_action_threw', 2 ),
) );

shop( array( 4740 => 'Elgato Stream Deck MK.2' ), array() );
as_schedule_single_action( time(), 'tcgiant_sync_fetch_delta_events', array(), 'tcgiant_sync_group' );
$W['actions'][1]['status'] = 'in-progress';
update_post_meta( 4740, '_ebay_export_status', 'pushing' );
clean_queue();
check( 'another job failing says nothing about pushes', array( said( 'did not finish' ), meta( 4740, '_ebay_export_status' ) ), array( array(), 'pushing' ) );

$id = as_schedule_single_action( time(), TCGiant_Sync_Exporter::PUSH_ACTION, array( 'product_id' => 4740 ), 'tcgiant_sync_group' );
$W['actions'][ $id ]['status'] = 'failed';
do_action( 'action_scheduler_failed_execution', $id, new RuntimeException( 'Call to a member function on null' ), 'WP Cron' );
check( 'a push that threw past the push says so, with the error', array_column( said( 'did not finish' ), 1 ), array( 'The push of "Elgato Stream Deck MK.2" (WC #4740) did not finish - it stopped with an error (Call to a member function on null). If the listing is on eBay, use Link on the product with its item number; pushing it again would list it twice.' ) );

update_post_meta( 4740, '_ebay_export_status', 'pushed' );
$id = as_schedule_single_action( time(), TCGiant_Sync_Exporter::PUSH_ACTION, array( 'product_id' => 4740 ), 'tcgiant_sync_group' );
$W['actions'][ $id ]['status'] = 'in-progress';
clean_queue();
check( 'a push that finished before its action was failed is left alone', array( count( said( 'did not finish' ) ), meta( 4740, '_ebay_export_status' ) ), array( 1, 'pushed' ) );

echo "\nA LINKED PRODUCT: THERE IS NO LINK TO USE, AND THE OLD LISTING LOOKS FINE\n" . str_repeat( '=', 124 ) . "\n";

// Linked, a push revises the listing - or, if eBay says it has ended, makes a
// new one in its place. Told to check the old listing before pushing again,
// the shop finds it ended, exactly as expected, pushes, and lists it twice.
// And Link, which a push that made a listing points to, is offered only to a
// product with no listing.
$IF_ENDED = 'If that listing had ended, this push was making a new one in its place: look on eBay for it, and if it is there, Unlink this product and Link it to the new item number - pushing it again would list it twice.';
$WAS_ENDED = 'which had ended, so this push was making a new one in its place: look on eBay for it, and if it is there, Unlink this product and Link it to the new item number - pushing it again would list it twice.';

shop( array( 4750 => 'Ubiquiti UniFi U6 Lite' ), array() );
update_post_meta( 4750, '_ebay_item_id', '960060000001' );
update_post_meta( 4750, '_ebay_export_status', 'pushing' );
update_post_meta( 4750, '_ebay_export_pushing_since', time() - 40 * 60 );
$panel = notice( 4750, 'panel' );
check( 'a linked product\'s stale push: look on eBay for a new listing', false !== strpos( $panel, 'Push did not finish - check eBay for a new listing.' ), true );
check( '  if its listing had ended: Unlink, then Link the new item number', false !== strpos( $panel, 'It is linked to eBay listing 960060000001. ' . esc_html( $IF_ENDED ) ), true );
check( '  and never to check the old listing and push again', false === strpos( $panel, 'before pushing again' ), true );
$id = as_schedule_single_action( time(), TCGiant_Sync_Exporter::PUSH_ACTION, array( 'product_id' => 4750 ), 'tcgiant_sync_group' );
$W['actions'][ $id ]['status'] = 'in-progress';
clean_queue();
check( 'Action Scheduler failing it says the same in the log', array_column( said( 'did not finish' ), 1 ), array( 'The push of "Ubiquiti UniFi U6 Lite" (WC #4750) did not finish - Action Scheduler found it still running 5 minutes on and gave up on it, so your server most likely stopped it. It is linked to eBay listing 960060000001. ' . $IF_ENDED ) );

shop( array( 4751 => 'Ubiquiti UniFi U6 Pro' ), array() );
update_post_meta( 4751, '_ebay_item_id', '960060000002' );
update_post_meta( 4751, '_ebay_listing_status', 'Ended' );
update_post_meta( 4751, '_ebay_export_status', 'pushing' );
update_post_meta( 4751, '_ebay_export_pushing_since', time() - 40 * 60 );
check( 'one whose listing the shop has as ended: the push was making a new one', false !== strpos( notice( 4751, 'panel' ), 'It is linked to eBay listing 960060000002, ' . esc_html( $WAS_ENDED ) ), true );
$id = as_schedule_single_action( time(), TCGiant_Sync_Exporter::PUSH_ACTION, array( 'product_id' => 4751 ), 'tcgiant_sync_group' );
$W['actions'][ $id ]['status'] = 'in-progress';
clean_queue();
check( '  and the log says so', array_column( said( 'did not finish' ), 1 ), array( 'The push of "Ubiquiti UniFi U6 Pro" (WC #4751) did not finish - Action Scheduler found it still running 5 minutes on and gave up on it, so your server most likely stopped it. It is linked to eBay listing 960060000002, ' . $WAS_ENDED ) );
check( '  as the stored error the screen shows', false !== strpos( notice( 4751, 'panel' ), esc_html( $WAS_ENDED ) ) && false !== strpos( notice( 4751, 'column' ), 'Push did not finish - check eBay for a new listing.' ), true );

echo "\nA LINKED PRODUCT WHOSE LISTING HAD ENDED, DYING ON THE RELIST\n" . str_repeat( '=', 124 ) . "\n";

// eBay ended the listing; the shop's own records still say Active, so only
// eBay's refusal to revise it tells the push to list it afresh.
shop( array( 4770 => 'Elgato Cam Link 4K' ), array( '960068900002' ) );
update_post_meta( 4770, '_ebay_item_id', '960068900001' );
update_post_meta( 4770, '_ebay_listing_status', 'Active' );
$W['ended']['960068900001'] = true;
$exporter->bulk_push_products( array( 4770 ) );
$ON_SAVE = function ( $product, $changed ) {
	if ( in_array( '_ebay_item_id', $changed, true ) ) {
		throw new RuntimeException( 'Deadlock found when trying to get lock' );
	}
};
run_next();
$ON_SAVE = null;
$threw   = 'Export exception for "Elgato Cam Link 4K" (WC #4770): Deadlock found when trying to get lock eBay had already created listing 960068900002 for it in place of ended listing 960068900001: Unlink it from listing 960068900001, then use Link with item number 960068900002; pushing it again would list it twice.';
check( 'a relist that throws once eBay made the new listing: Unlink, then Link the new number', array_column( said( 'Export exception' ), 1 ), array( $threw ) );
check( '  the product says its push did not finish, still linked to the ended listing', array( meta( 4770, '_ebay_export_status' ), meta( 4770, '_ebay_export_error' ), meta( 4770, '_ebay_item_id' ) ), array( 'failed', $threw, '960068900001' ) );
check( '  and the batch names it as not finished', 0 === strpos( said( 'Export finished' )[0][1] ?? '', 'Export finished: 0 of 1 pushed, 0 errors, 1 did not finish (WC #4770).' ), true );
$panel = notice( 4770, 'panel' );
check( '  its screen: look on eBay for the new listing, with those words', false !== strpos( $panel, 'Push did not finish - check eBay for a new listing.' ) && false !== strpos( $panel, esc_html( $threw ) ), true );

shop( array( 4771 => 'Elgato Wave:3 Microphone' ), array( '960069000002' ) );
update_post_meta( 4771, '_ebay_item_id', '960069000001' );
update_post_meta( 4771, '_ebay_listing_status', 'Active' );
$W['ended']['960069000001'] = true;
$exporter->bulk_push_products( array( 4771 ) );
in_child( 'oom' );
$relist = 'The push of "Elgato Wave:3 Microphone" (WC #4771) did not finish - your server stopped it after eBay created listing 960069000002 in place of ended listing 960069000001. Unlink it from listing 960069000001, then use Link with item number 960069000002; pushing it again would list it twice.';
check( 'a relist that runs out of memory saving the link (a child process)', isset( $W['ebay']['960069000002'] ) && 1 === count( said( 'while working on WC #4771 "Elgato Wave:3 Microphone" - saving the link.' ) ), true );
check( '  says to Unlink from the ended listing, then Link the new one', array_column( said( 'did not finish - your server' ), 1 ), array( $relist ) );
check( '  on the product, still linked to the ended listing', array( meta( 4771, '_ebay_export_status' ), meta( 4771, '_ebay_export_error' ), meta( 4771, '_ebay_item_id' ) ), array( 'failed', $relist, '960069000001' ) );
$panel = notice( 4771, 'panel' );
check( '  its screen: look on eBay for the new listing, with those words', false !== strpos( $panel, 'Push did not finish - check eBay for a new listing.' ) && false !== strpos( $panel, esc_html( $relist ) ), true );

// What the shop's records already said: the ended-listing check had seen it.
shop( array( 4772 => 'Elgato Key Light Air' ), array( '960069100002' ) );
update_post_meta( 4772, '_ebay_item_id', '960069100001' );
update_post_meta( 4772, '_ebay_listing_status', 'Ended' );
$W['ended']['960069100001'] = true;
$exporter->bulk_push_products( array( 4772 ) );
in_child( 'exit' );
$gone = 'The push of "Elgato Key Light Air" (WC #4772) did not finish - your server stopped it. It is linked to eBay listing 960069100001, ' . $WAS_ENDED;
check( 'a relist whose request ends while eBay answers (a child process)', isset( $W['ebay']['960069100002'] ) && 1 === count( said( 'while working on WC #4772 "Elgato Key Light Air" - sending to eBay.' ) ), true );
check( '  the push knew the listing had ended: look on eBay for the new one, Unlink, Link', array_column( said( 'did not finish - your server' ), 1 ), array( $gone ) );
check( '  on the product', array( meta( 4772, '_ebay_export_status' ), meta( 4772, '_ebay_export_error' ) ), array( 'failed', $gone ) );

// A live listing is revised: nothing is created, and nothing must say it was.
shop( array( 4773 => 'Elgato Stream Deck Mini' ), array() );
update_post_meta( 4773, '_ebay_item_id', '960069200001' );
update_post_meta( 4773, '_ebay_listing_status', 'Active' );
$exporter->bulk_push_products( array( 4773 ) );
in_child( 'oom' );
check( 'a revise that runs out of memory saving (a child process): eBay revised it and listed nothing', array( $W['revised'], $W['ebay'] ), array( array( '960069200001' ), array() ) );
check( '  so no listing is named as created', array_column( said( 'did not finish - your server' ), 1 ), array( 'The push of "Elgato Stream Deck Mini" (WC #4773) did not finish - your server stopped it. It is linked to eBay listing 960069200001. ' . $IF_ENDED ) );

echo "\nLINK, RUN AS THE PRODUCT SCREEN RUNS IT\n" . str_repeat( '=', 124 ) . "\n";

/** The product screen's Link button: its request, and Link's answer. */
function link_product( $product_id, $item_id ) {
	$_POST = array( 'product_id' => (string) $product_id, 'ebay_item_id' => (string) $item_id );
	try {
		( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor()->ajax_link_ebay();
	} catch ( Harness_Json_Answer $answer ) {
		return $answer;
	}
	return null;
}

// Gadget Central's keyboard: its push died after eBay had listed it.
shop( array( 4717 => 'Logitech MX Keys Wireless Keyboard' ), array() );
$W['ebay']['960068033342'] = array( 'call' => 'AddFixedPriceItem', 'product' => 4717, 'xml' => '<Title>Logitech MX Keys Wireless Keyboard</Title>' );
update_post_meta( 4717, '_ebay_export_status', 'failed' );
update_post_meta( 4717, '_ebay_export_error', $DIED );
update_post_meta( 4717, '_ebay_export_pushing_since', time() - 20 * 60 );
$answer = link_product( 4717, '960068033342' );
check( 'Link gives the product the listing its push made', array( $answer ? $answer->success : null, meta( 4717, '_ebay_item_id' ) ), array( true, '960068033342' ) );
check( '  and it says pushed - the shop\'s own product, pushed from here - with no error', array( meta( 4717, '_ebay_export_status' ), meta( 4717, '_ebay_export_error' ), meta( 4717, '_ebay_export_pushing_since' ) ), array( 'pushed', '', null ) );
check( '  so the screens stop warning about it', array( notice( 4717, 'panel' ), notice( 4717, 'column' ) ), array( '', '' ) );

shop( array( 4790 => 'Netgear Nighthawk M6' ), array() );
$W['ebay']['960060009999'] = array( 'call' => 'AddFixedPriceItem', 'product' => 0, 'xml' => '<Title>Netgear Nighthawk M6</Title>' );
link_product( 4790, '960060009999' );
check( 'Link on a product no push of ours touched leaves its status alone, undated', array( meta( 4790, '_ebay_item_id' ), meta( 4790, '_ebay_export_status' ), meta( 4790, '_ebay_export_last_pushed' ) ), array( '960060009999', null, null ) );

$admin = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
check( 'the panel shows the push state with the error a refused push leaves', false !== strpos( $admin, "echo self::push_state_notice( \$product_id, \$ebay_item_id, 'panel' );" ), true );
check( 'the products list shows it beside its own error', false !== strpos( $admin, "echo self::push_state_notice( \$post_id, \$ebay_item_id, 'column' );" ), true );
check( 'a duplicated product does not inherit a push in progress', in_array( '_ebay_export_pushing_since', (array) ( function () use ( $ROOT ) {
	$src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-listing-link.php' );
	preg_match( '/function listing_identity_meta_keys\(\) \{(.*?)\n\t\}/s', $src, $m );
	preg_match_all( "/'(_[a-z_]+)'/", $m[1] ?? '', $keys );
	return $keys[1];
} )(), true ), true );

echo "\nWHAT ELSE READS A PRODUCT'S PUSH STATUS\n" . str_repeat( '=', 124 ) . "\n";

// A push says "pushed" only between pushes now: queued, pushing and failed
// replace it while one is under way. Only a push that succeeded writes
// _ebay_export_last_pushed, so a product with it is still one the shop pushed.
$flat = function ( $path ) use ( $ROOT ) { return preg_replace( '/\s+/', ' ', (string) file_get_contents( $ROOT . $path ) ); };
$exp  = $flat( '/includes/class-tcgiant-sync-exporter.php' );
$ent  = $flat( '/includes/class-tcgiant-sync-entitlements.php' );
check( 'the coin finder still returns a coin listing while its refresh is queued, under way or died', false !== strpos( $exp, "LEFT JOIN {\$wpdb->postmeta} lp ON p.ID = lp.post_id AND lp.meta_key = '_ebay_export_last_pushed' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND ( st.meta_value = 'pushed' OR ( st.meta_value IN ( 'queued', 'pushing', 'failed' ) AND lp.meta_value != '' ) )" ), true );
check( 'the pushed count sent home counts them as pushed', false !== strpos( $ent, "LEFT JOIN {\$wpdb->postmeta} pm3 ON pm3.post_id = p.ID AND pm3.meta_key = '_ebay_export_last_pushed' WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft') AND pm1.meta_key = '_ebay_item_id' AND pm1.meta_value != '' AND pm2.meta_key = '_ebay_export_status' AND (pm2.meta_value = 'pushed' OR (pm2.meta_value IN ('queued','pushing','failed') AND pm3.meta_value != ''))" ), true );
check( 'the Export screen calls a batch still being scheduled Queued', false !== strpos( $flat( '/admin/views/export.php' ), "'scheduling' => array( 'label' => 'Queued'," ), true );
check( '  and the pulled count is every linked product it leaves out', false !== strpos( $ent, "AND (pm2.meta_value IS NULL OR (pm2.meta_value != 'pushed' AND (pm2.meta_value NOT IN ('queued','pushing','failed') OR pm3.meta_value IS NULL OR pm3.meta_value = '')))" ), true );

echo "\nTHE COIN-REFRESH BUTTON DOES NOT PUSH AGAIN WHAT IT WAS TOLD NOT TO\n" . str_repeat( '=', 124 ) . "\n";

// The finder returns a coin listing while its refresh is queued, under way or
// died, and the button sent all of them again. One linked to a listing that
// had ended, whose relist the host killed after eBay made the new listing,
// had been told that pushing it again would list it twice - and a second
// press relisted it again.
//
// The banner counted all of them too, so it came straight back after a press
// with the same count, inviting that second press; and one whose push did not
// finish, which the button leaves out, kept it up for good.

/** The coin-refresh banner, as the products screen prints it. */
function coin_banner() {
	ob_start();
	( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor()->stale_coin_listings_notice();
	return trim( (string) ob_get_clean() );
}

shop( array(
	7101 => '1921 Morgan Dollar MS63',
	7102 => '1964 Kennedy Half Dollar PR67',
	7103 => '1881-S Morgan Dollar MS64',
	7104 => '1909-S VDB Lincoln Cent VF20',
	7105 => '1916-D Mercury Dime G4',
	7106 => '2021 American Silver Eagle MS70',
), array() );
foreach ( array( 7101, 7102, 7103, 7104, 7105, 7106 ) as $coin ) {
	update_post_meta( $coin, '_ebay_item_id', '96007' . $coin );
	update_post_meta( $coin, '_ebay_export_category_id', '526' );
	update_post_meta( $coin, '_ebay_export_category_name', 'Coins & Paper Money > Coins: US > Mint Sets' );
	update_post_meta( $coin, '_ebay_export_status', 'pushed' );
	update_post_meta( $coin, '_ebay_export_last_pushed', '2025-01-01 10:00:00' );
	update_post_meta( $coin, '_ebay_export_pushed_version', '3.5.0' );
}
update_post_meta( 7101, '_ebay_listing_status', 'Ended' );
update_post_meta( 7101, '_ebay_export_status', 'failed' );
update_post_meta( 7101, '_ebay_export_error', 'The push of "1921 Morgan Dollar MS63" (WC #7101) did not finish - your server stopped it after eBay created listing 960079101 in place of ended listing 960077101. Unlink it from listing 960077101, then use Link with item number 960079101; pushing it again would list it twice.' );
update_post_meta( 7103, '_ebay_export_status', 'pushing' ); // still saying so twenty minutes on
update_post_meta( 7103, '_ebay_export_pushing_since', time() - 20 * 60 );
update_post_meta( 7104, '_ebay_export_status', 'pushing' ); // under way now
update_post_meta( 7104, '_ebay_export_pushing_since', time() - 30 );
update_post_meta( 7105, '_ebay_export_status', 'queued' ); // queued by the first press, not yet run
update_post_meta( 7106, '_ebay_export_pushed_version', '3.27.4' ); // already sent with its coin details

check( 'the finder returns every coin listing sent without its details, whatever its push is doing', TCGiant_Sync_Exporter::find_coin_listings_needing_refresh(), array( 7101, 7102, 7103, 7104, 7105 ) );
check( 'the banner counts only the one still saying pushed', false !== strpos( coin_banner(), '1 listing was sent to eBay before the plugin recognised its category as a coin category' ), true );
$went = null;
try {
	( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor()->handle_refresh_coin_listings();
} catch ( Harness_Redirect $redirect ) {
	$went = $redirect->getMessage();
}
check( 'its button sends again only those whose last push finished', get_option( TCGiant_Sync_Exporter::EXPORT_STATE_OPTION )['product_ids'] ?? null, array( 7102, 7105 ) );
check( '  not the one told that pushing it again would list it twice', array( action_status( 7101 ), meta( 7101, '_ebay_export_status' ) ), array( null, 'failed' ) );
check( '  nor one saying it is pushing, stale or under way', array( action_status( 7103 ), action_status( 7104 ), meta( 7103, '_ebay_export_status' ), meta( 7104, '_ebay_export_status' ) ), array( null, null, 'pushing', 'pushing' ) );
check( '  and the log says which it left out, and why', array_column( said( 'Left out' ), 1 ), array( 'Left out 3 coin listing(s) whose last push is still under way or did not finish (WC #7101, #7103, #7104): pushing one of those again could list it twice. Each product\'s eBay tab says what to do.' ) );
check( '  then back to the products, with two queued', $went, 'https://gadget-central.example/wp-admin/edit.php?tcgiant_pushed=2&post_type=product' );
check( 'after the press the banner is gone: the one whose push did not finish no longer keeps it up', array( coin_banner(), in_array( 7101, TCGiant_Sync_Exporter::find_coin_listings_needing_refresh(), true ) ), array( '', true ) );

echo "\nAUTO-RELIST, AND RELIST AND PUSH ON THE LISTINGS SCREEN, LEAVE ALONE A PUSH THAT DID NOT FINISH\n" . str_repeat( '=', 124 ) . "\n";

// A push of a product linked to a listing that had ended makes a new one in
// its place. One that died after eBay made it leaves the product linked to
// the ended listing, which still says Ended - so the daily auto-relist, with
// nobody pressing anything, relisted that one as well, and so did Relist on
// the Listings screen: the product was live on eBay twice. It was then
// linked to the relist, while its notice still said to Unlink the ended
// listing and Link the first new one, which left the relist live with nothing
// pointing at it. Push on the Listings screen made a second new listing the
// same way, and that screen shows no warning.

/** The listings eBay has live for a product. */
function live_on_ebay( $product_id ) {
	$live = array();
	foreach ( $GLOBALS['W']['ebay'] as $item_id => $listing ) {
		if ( (int) $listing['product'] === (int) $product_id && ! isset( $GLOBALS['W']['ended'][ (string) $item_id ] ) ) {
			$live[] = (string) $item_id;
		}
	}
	return $live;
}

/** A product pushed before, and linked to its listing, which has ended. */
function linked_to_ended( $product_id, $item_id ) {
	update_post_meta( $product_id, '_ebay_item_id', $item_id );
	update_post_meta( $product_id, '_ebay_listing_status', 'Ended' );
	update_post_meta( $product_id, '_ebay_export_status', 'pushed' );
	update_post_meta( $product_id, '_ebay_export_last_pushed', '2026-09-01 10:00:00' );
	$GLOBALS['W']['ebay'][ $item_id ]  = array( 'call' => 'AddFixedPriceItem', 'product' => $product_id, 'xml' => '<Title>' . $GLOBALS['W']['posts'][ $product_id ]['title'] . '</Title>' );
	$GLOBALS['W']['ended'][ $item_id ] = true;
	world_save();
}

/** A Listings-screen job, one batch through the real admin-ajax handler: what it answers. */
function listings_job( $type, array $product_ids ) {
	$job = TCGiant_Sync_Jobs::create_job( $type, $product_ids );
	TCGiant_Sync_Jobs::update_job( $job, array( 'status' => 'running' ) );
	$_POST = array( 'job_id' => $job );
	try {
		( new ReflectionClass( 'TCGiant_Sync_Jobs' ) )->newInstanceWithoutConstructor()->ajax_process_batch();
	} catch ( Harness_Json_Answer $answer ) {
		return $answer->data;
	}
	return null;
}

/** Pushes of a product waiting to run. */
function pushes_waiting( $product_id ) {
	$waiting = 0;
	foreach ( $GLOBALS['W']['actions'] as $a ) {
		if ( TCGiant_Sync_Exporter::PUSH_ACTION === $a['hook'] && 'pending' === $a['status'] && (int) $a['args']['product_id'] === (int) $product_id ) {
			$waiting++;
		}
	}
	return $waiting;
}

// The daily auto-relist, switched on: one whose relist threw once eBay had
// made the new listing, and one whose last push finished.
shop( array( 7201 => '1893-S Morgan Dollar VF20', 7202 => '1889-CC Morgan Dollar F12' ), array( '960072010002', '960072020002' ) );
linked_to_ended( 7201, '960072010001' );
linked_to_ended( 7202, '960072020001' );
$exporter->bulk_push_products( array( 7201 ) );
$ON_SAVE = function ( $product, $changed ) {
	if ( in_array( '_ebay_item_id', $changed, true ) ) {
		throw new RuntimeException( 'Deadlock found when trying to get lock' );
	}
};
run_next();
$ON_SAVE = null;
check( 'a relist that throws once eBay made the new listing: failed, still linked to the ended one', array( meta( 7201, '_ebay_export_status' ), meta( 7201, '_ebay_item_id' ), meta( 7201, '_ebay_listing_status' ), live_on_ebay( 7201 ) ), array( 'failed', '960072010001', 'Ended', array( '960072010002' ) ) );
update_option( 'tcgiant_sync_ebay_settings', array( 'auto_relist_enabled' => 1 ) );
( new ReflectionClass( 'TCGiant_Sync_Cron' ) )->newInstanceWithoutConstructor()->auto_relist_ended_items();
check( 'the daily auto-relist does not relist its ended listing as well', array( live_on_ebay( 7201 ), meta( 7201, '_ebay_item_id' ) ), array( array( '960072010002' ), '960072010001' ) );
check( '  so what it was told still holds: Unlink the ended listing, Link the new one', array( meta( 7201, '_ebay_export_status' ), false !== strpos( (string) meta( 7201, '_ebay_export_error' ), 'Unlink it from listing 960072010001, then use Link with item number 960072010002;' ) ), array( 'failed', true ) );
check( 'the one whose last push finished is relisted, as before', array( live_on_ebay( 7202 ), meta( 7202, '_ebay_item_id' ) ), array( array( '960072020002' ), '960072020002' ) );
check( '  and the run says why the other may not have been', array_column( said( 'Auto-Relist: Complete' ), 1 ), array( 'Auto-Relist: Complete. 1 relisted, 1 not relisted (no stock, sold out on eBay, already live, on another eBay site, or its last push is still under way or did not finish).' ) );

// Relist, then Push, on the Listings screen: one whose relist the host killed
// once eBay had made the new listing, beside one whose last push finished.
shop( array( 7203 => '1878-CC Morgan Dollar VF30', 7204 => '1881-S Morgan Dollar MS64' ), array( '960072030002', '960072040002' ) );
linked_to_ended( 7203, '960072030001' );
linked_to_ended( 7204, '960072040001' );
$exporter->bulk_push_products( array( 7203 ) );
in_child( 'kill' );
clean_queue();
check( 'a relist the host killed once eBay made the new listing (a child process): failed, still linked to the ended one', array( meta( 7203, '_ebay_export_status' ), meta( 7203, '_ebay_item_id' ), live_on_ebay( 7203 ) ), array( 'failed', '960072030001', array( '960072030002' ) ) );
$sent = listings_job( 'bulk_relist', array( 7203, 7204 ) );
check( 'Relist on the Listings screen keeps it, and relists the other', array( live_on_ebay( 7203 ), meta( 7203, '_ebay_item_id' ), live_on_ebay( 7204 ), $sent['succeeded'] ?? null, $sent['kept'] ?? null, $sent['failed'] ?? null ), array( array( '960072030002' ), '960072030001', array( '960072040002' ), 1, 1, 0 ) );
check( '  and says why it kept it', $sent['errors'] ?? null, array( '#7203: not relisted - its last push is still under way or did not finish, and may already have made a new listing in place of this one - its eBay tab says what to do.' ) );
$sent = listings_job( 'bulk_push', array( 7203, 7204 ) );
check( 'Push to eBay on the Listings screen leaves it out too, and queues the other', array( pushes_waiting( 7203 ), meta( 7203, '_ebay_export_status' ), pushes_waiting( 7204 ), $sent['queued'] ?? null, $sent['kept'] ?? null, $sent['failed'] ?? null ), array( 0, 'failed', 1, 1, 1, 0 ) );
check( '  and says why it left it out', $sent['errors'] ?? null, array( '#7203: not pushed - its last push is still under way or did not finish, and may already have made its listing on eBay. Its eBay tab says what to do.' ) );
while ( run_next() ) {
	continue;
}
check( '  so eBay holds one listing for it, the one its push made', live_on_ebay( 7203 ), array( '960072030002' ) );

// One whose push is under way now is left alone as well: that push is doing
// what these would.
shop( array( 7205 => '1921-D Morgan Dollar AU55' ), array( '960072050002' ) );
linked_to_ended( 7205, '960072050001' );
update_post_meta( 7205, '_ebay_export_status', 'pushing' );
update_post_meta( 7205, '_ebay_export_pushing_since', time() - 30 );
$relisted = listings_job( 'bulk_relist', array( 7205 ) );
$pushed   = listings_job( 'bulk_push', array( 7205 ) );
check( 'one whose push is under way now is neither relisted nor pushed again', array( live_on_ebay( 7205 ), $relisted['kept'] ?? null, pushes_waiting( 7205 ), $pushed['kept'] ?? null ), array( array(), 1, 0, 1 ) );


// Pushed again from its own screen - what its notice says not to do, but the
// button is there - and that push cancelled before it ran, as every bulk push
// cancels the pushes still waiting. Queuing it wrote queued over failed, so
// nothing said its relist had died: the daily auto-relist relisted the ended
// listing beside the one that relist had made.
shop( array( 7206 => '1883-O Morgan Dollar MS63', 7207 => '1885 Morgan Dollar MS62' ), array( '960072060002', '960072060003', '960072070002' ) );
linked_to_ended( 7206, '960072060001' );
$exporter->bulk_push_products( array( 7206 ) );
$ON_SAVE = function ( $product, $changed ) {
	if ( in_array( '_ebay_item_id', $changed, true ) ) {
		throw new RuntimeException( 'Deadlock found when trying to get lock' );
	}
};
run_next();
$ON_SAVE = null;
$told    = meta( 7206, '_ebay_export_error' );
$exporter->push_product( 7206 );
$exporter->bulk_push_products( array( 7207 ) );
check( 'its relist died, its push again was cancelled: it still says its relist did not finish', array( meta( 7206, '_ebay_export_status' ), meta( 7206, '_ebay_export_error' ) === $told, pushes_waiting( 7206 ) ), array( 'failed', true, 0 ) );
update_option( 'tcgiant_sync_ebay_settings', array( 'auto_relist_enabled' => 1 ) );
( new ReflectionClass( 'TCGiant_Sync_Cron' ) )->newInstanceWithoutConstructor()->auto_relist_ended_items();
check( '  the daily auto-relist does not relist its ended listing as well', live_on_ebay( 7206 ), array( '960072060002' ) );

// Pushed again and not cancelled: the push runs, and one that finishes is
// what ends what the product said.
shop( array( 7208 => '1883-CC Morgan Dollar MS64' ), array( '960072080002', '960072080003' ) );
linked_to_ended( 7208, '960072080001' );
$exporter->bulk_push_products( array( 7208 ) );
$ON_SAVE = function ( $product, $changed ) {
	if ( in_array( '_ebay_item_id', $changed, true ) ) {
		throw new RuntimeException( 'Deadlock found when trying to get lock' );
	}
};
run_next();
$ON_SAVE = null;
$told    = meta( 7208, '_ebay_export_error' );
$exporter->push_product( 7208 );
check( 'its relist died, it is pushed again: it says so while that push waits', array( meta( 7208, '_ebay_export_status' ), meta( 7208, '_ebay_export_error' ) === $told, pushes_waiting( 7208 ) ), array( 'failed', true, 1 ) );
while ( run_next() ) {
	continue;
}
check( '  that push finishes: pushed, its warning gone, linked to what it made', array( meta( 7208, '_ebay_export_status' ), meta( 7208, '_ebay_export_error' ), meta( 7208, '_ebay_item_id' ) ), array( 'pushed', '', '960072080003' ) );

// Pushed again in a bulk push - the products list's, which shows the warning
// but does not leave the product out - and refused by eBay: it still says its
// relist did not finish, and the end of the batch names it as a push that did
// not finish, not as an error as well.
shop( array( 7209 => '1884-CC Morgan Dollar MS65' ), array( '960072090002' ) );
linked_to_ended( 7209, '960072090001' );
$exporter->bulk_push_products( array( 7209 ) );
$ON_SAVE = function ( $product, $changed ) {
	if ( in_array( '_ebay_item_id', $changed, true ) ) {
		throw new RuntimeException( 'Deadlock found when trying to get lock' );
	}
};
run_next();
$ON_SAVE      = null;
$told         = meta( 7209, '_ebay_export_error' );
$EBAY_REFUSES = array( 7209 => 'The item specific Type is missing.' );
$exporter->bulk_push_products( array( 7209 ) );
while ( run_next() ) {
	continue;
}
$EBAY_REFUSES = array();
check( 'its relist died, a bulk push again is refused by eBay: it still says its relist did not finish', array( meta( 7209, '_ebay_export_status' ), 0 === strpos( (string) meta( 7209, '_ebay_export_error' ), $told ) ), array( 'failed', true ) );
// Said in the log alone, the product looked just as it had before it was
// pushed again, and nothing on it said why.
check( '  and then why eBay refused the push after it', false !== strpos( (string) meta( 7209, '_ebay_export_error' ), $told . ' The push after it was refused: The item specific Type is missing.' ), true );
check( '  which its eBay tab and the products list both show', array( false !== strpos( notice( 7209, 'panel' ), 'The item specific Type is missing.' ), false !== strpos( notice( 7209, 'column' ), 'The item specific Type is missing.' ) ), array( true, true ) );
$ended = said( 'Export finished' );
check( '  and the batch ends naming it as not finished, not as an error as well', strtok( (string) ( end( $ended )[1] ?? '' ), '.' ), 'Export finished: 0 of 1 pushed, 0 errors, 1 did not finish (WC #7209)' );
check( '  the log says why that push was refused', count( said( 'Export failed for "1884-CC Morgan Dollar MS65" (WC #7209): The item specific Type is missing. Its earlier push did not finish, and it still says so' ) ), 1 );
$EBAY_REFUSES = array( 7209 => 'The item specific Year is missing.' );
$exporter->bulk_push_products( array( 7209 ) );
while ( run_next() ) {
	continue;
}
$EBAY_REFUSES = array();
check( 'refused again: its relist\'s words, then this refusal in place of the last, not added to it', array( meta( 7209, '_ebay_export_status' ), meta( 7209, '_ebay_export_error' ) ), array( 'failed', $told . ' The push after it was refused: The item specific Year is missing.' ) );
$EBAY_THROWS = array( 7209 => 'cURL error 28: Operation timed out' );
$exporter->bulk_push_products( array( 7209 ) );
while ( run_next() ) {
	continue;
}
$EBAY_THROWS = array();
check( '  then a push that throws before eBay makes anything: the same, with what stopped it', array( meta( 7209, '_ebay_export_status' ), meta( 7209, '_ebay_export_error' ) ), array( 'failed', $told . ' The push after it was refused: Export exception for "1884-CC Morgan Dollar MS65" (WC #7209): cURL error 28: Operation timed out' ) );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
