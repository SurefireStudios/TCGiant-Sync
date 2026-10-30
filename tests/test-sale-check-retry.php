<?php
/**
 * Harness: a sale check that cannot read eBay tries again, later each time.
 *
 * Every eBay sale of a linked product queues a stock check,
 * tcgiant_sync_settle_item (since 3.27.0). When eBay could not be read for it
 * - a timeout, no token, the day's calls used up, an answer that was not
 * eBay's - the check said "the delta sync will settle it when it reaches the
 * sale" and stopped. A delta that is behind does not reach it - one shop's
 * was eleven days behind - and the sold item stayed for sale on the website.
 *
 * Since 3.28.0 such a check is queued again: 15 minutes on, then an hour, 4
 * hours and 12 hours, and given up after the fifth try with a warning naming
 * the product and the listing, and the listing on Stock Review. Stopped by
 * the day's own budget, it waits for the new UTC day and the try is not
 * counted. Never two checks of one listing waiting at once. A read eBay
 * answers - even "not found" - is left as before.
 *
 * Loaded for real: TCGiant_Sync_Importer, TCGiant_Sync_Inventory,
 * TCGiant_Sync_Variations, TCGiant_Sync_Sites and TCGiant_Sync_Runtime.
 * WordPress and WooCommerce are played as in test-sale-check.php; Action
 * Scheduler keeps each action's time and status; eBay answers from a script.
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
		if ( preg_match( "/^INSERT INTO wp_options \\( option_name, option_value, autoload \\) VALUES \\( '(tcgiant_sync_settle_joined_[^']+)', '?(\\d+)'?, '(\\w+)' \\)\\s*ON DUPLICATE KEY UPDATE option_value = GREATEST\\( option_value \\+ 0, '?(\\d+)'? \\)$/", trim( $query ), $jm ) ) {
			if ( isset( $GLOBALS['ON_JOIN_QUERY'] ) ) {
				call_user_func( $GLOBALS['ON_JOIN_QUERY'], $jm[1], (int) $jm[2] );
			}
			$GLOBALS['OPTIONS'][ $jm[1] ] = (string) ( array_key_exists( $jm[1], $GLOBALS['OPTIONS'] ) ? max( (int) $GLOBALS['OPTIONS'][ $jm[1] ], (int) $jm[4] ) : (int) $jm[2] );
			$GLOBALS['JOIN_AUTOLOAD'][ $jm[1] ] = 'no' === $jm[3] ? false : true;
			return 1;
		}
		// Played as MySQL would play any other upsert of it: the value given, written over.
		if ( preg_match( "/^INSERT INTO wp_options \( option_name, option_value, autoload \) VALUES \( '(tcgiant_sync_settle_joined_[^']+)', '?(\d+)'?, '(\w+)' \)\s*ON DUPLICATE KEY UPDATE option_value = '?(\d+)'?$/", trim( $query ), $jm ) ) {
			if ( isset( $GLOBALS['ON_JOIN_QUERY'] ) ) {
				call_user_func( $GLOBALS['ON_JOIN_QUERY'], $jm[1], (int) $jm[2] );
			}
			$GLOBALS['OPTIONS'][ $jm[1] ] = (string) ( array_key_exists( $jm[1], $GLOBALS['OPTIONS'] ) ? (int) $jm[4] : (int) $jm[2] );
			$GLOBALS['JOIN_AUTOLOAD'][ $jm[1] ] = 'no' === $jm[3] ? false : true;
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
	$GLOBALS['OPTIONS'][ $name ]  = $value;
	$GLOBALS['AUTOLOAD'][ $name ] = $autoload;
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

// The clock: the real one, unless the harness stands the shop at $NOW. The
// importer is loaded with its time() and one-argument gmdate() reading it
// (see below); Action Scheduler here reads it too.
$NOW = null;
function now() { return null === $GLOBALS['NOW'] ? time() : (int) $GLOBALS['NOW']; }
function harness_gmdate( $format ) { return gmdate( $format, now() ); }

// Action Scheduler, with each action's time and status: pending until it is
// run, running while it runs, then done. A check runs when the harness says
// its time has come - see run_next().
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	return as_schedule_single_action( now(), $hook, $args, $group );
}
function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) {
	$GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'when' => (int) $when, 'status' => 'pending' );
	return count( $GLOBALS['QUEUE'] );
}
/** Pending or running, as Action Scheduler's answers. */
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['QUEUE'] as $action ) {
		if ( $action['hook'] === $hook && in_array( $action['status'], array( 'pending', 'running' ), true )
			&& ( null === $args || $action['args'] === $args ) && ( '' === $group || $action['group'] === $group ) ) {
			return 'running' === $action['status'] ? true : $action['when'];
		}
	}
	return false;
}
/** The query the plugin makes: by hook, exact arguments, group and status. */
function as_get_scheduled_actions( $query, $return = 'objects' ) {
	$out = array();
	foreach ( $GLOBALS['QUEUE'] as $i => $action ) {
		if ( isset( $query['hook'] ) && $action['hook'] !== $query['hook'] ) { continue; }
		if ( isset( $query['args'] ) && $action['args'] !== $query['args'] ) { continue; }
		if ( isset( $query['group'] ) && $action['group'] !== $query['group'] ) { continue; }
		if ( isset( $query['status'] ) && $action['status'] !== $query['status'] ) { continue; }
		$out[] = $i + 1;
	}
	return isset( $query['per_page'] ) && $query['per_page'] > 0 ? array_slice( $out, 0, $query['per_page'] ) : $out;
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

/**
 * eBay's GetItem, answering each listing from a script: an error, until the
 * script runs out, and then the listing as it stands.
 */
class TCGiant_Sync_API {
	const MARKETPLACES = array(
		'EBAY_US' => array( 'label' => 'United States', 'site' => 'US' ),
	);
	public static function scalar_value( $raw ) { return is_scalar( $raw ) ? (string) $raw : ''; }
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	private $priority = 0;
	public function get_item( $item_id ) {
		$GLOBALS['EBAY']['get_items'][] = (string) $item_id;
		// Something that happens while this read is out: once.
		if ( ! empty( $GLOBALS['EBAY']['during'] ) ) {
			$during = $GLOBALS['EBAY']['during'];
			unset( $GLOBALS['EBAY']['during'] );
			$during();
		}
		// Whether it was asked past the shared ceiling, as a sale's push asks.
		$GLOBALS['EBAY']['priority'][] = $this->priority > 0;
		if ( ! empty( $GLOBALS['EBAY']['fails'][ (string) $item_id ] ) ) {
			return array_shift( $GLOBALS['EBAY']['fails'][ (string) $item_id ] );
		}
		return $GLOBALS['EBAY']['items'][ (string) $item_id ] ?? new WP_Error( 'api_error', 'The item could not be found.' );
	}
	public function with_stock_priority( callable $fn ) {
		$this->priority++;
		try {
			return $fn();
		} finally {
			$this->priority--;
		}
	}
	public function in_priority() { return $this->priority > 0; }
	/** GeteBayOfficialTime: does eBay answer at all. Scripted in $EBAY['clock']: an error, or an answer. */
	public function get_official_time() {
		$GLOBALS['EBAY']['clock_asked'] = ( $GLOBALS['EBAY']['clock_asked'] ?? 0 ) + 1;
		// Whether it was asked past the shared ceiling (3.28.0 release fix round 1, F5).
		$GLOBALS['EBAY']['clock_priority'][] = $this->priority > 0;
		return $GLOBALS['EBAY']['clock'] ?? array( 'Ack' => 'Success', 'Timestamp' => gmdate( 'Y-m-d\TH:i:s.000\Z' ) );
	}
}

/**
 * A listing a product follows was relisted: the product moves on to the
 * relist, as the real mapper's adopt_relist() does - unless the old listing
 * cannot be read ($EBAY['parent_fails']), when it says why and returns 0.
 */
class TCGiant_Sync_Mapper {
	public $relist_parent_error = null;
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function adopt_relist( array $item, $source, $parent = null ) {
		$this->relist_parent_error = null;
		$GLOBALS['ADOPTED'][]                = (string) $item['ItemID'];
		$GLOBALS['EBAY']['adopt_priority'][] = TCGiant_Sync_API::instance()->in_priority();
		if ( ! empty( $GLOBALS['EBAY']['parent_fails'] ) ) {
			$this->relist_parent_error = array_shift( $GLOBALS['EBAY']['parent_fails'] );
			return 0;
		}
		foreach ( $GLOBALS['PRODUCTS'] as $id => $p ) {
			if ( ( $p['meta']['_ebay_item_id'] ?? '' ) === (string) $item['RelistParentID'] ) {
				update_post_meta( $id, '_ebay_item_id', (string) $item['ItemID'] );
				return $id;
			}
		}
		return 0;
	}
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-variations.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
// Which the importer loads from beside itself (3.28.4), and its copy has no
// such neighbour.
require $ROOT . '/includes/class-tcgiant-sync-category-memory.php';

// The real importer, its clock the harness's: time() and one-argument gmdate()
// rewritten to read $NOW, as the 3.28.0 review's simulator loads it.
$IMPORTER_COPY = sys_get_temp_dir() . '/tcg-sale-check-retry-' . getmypid() . '-importer.php';
file_put_contents( $IMPORTER_COPY, preg_replace(
	array( '/(?<![\w>:$])time\(\s*\)/', "/(?<![\\w>:$])gmdate\\(\\s*('[^']*'|\"[^\"]*\")\\s*\\)/" ),
	array( 'now()', 'harness_gmdate($1)' ),
	(string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-importer.php' )
) );
require $IMPORTER_COPY;
@unlink( $IMPORTER_COPY );

$IMP = TCGiant_Sync_Importer::instance();

// --- driving it --------------------------------------------------------------

function fresh_shop() {
	$GLOBALS['NOW']       = null;
	$GLOBALS['OPTIONS']   = array();
	$GLOBALS['AUTOLOAD']  = array();
	$GLOBALS['TRANSIENTS'] = array();
	$GLOBALS['PRODUCTS']  = array();
	$GLOBALS['QUEUE']     = array();
	$GLOBALS['LOG']       = array();
	$GLOBALS['EVENTS']    = array();
	$GLOBALS['UNGUARDED'] = array();
	$GLOBALS['SYNCED']    = array();
	$GLOBALS['ADOPTED']   = array();
	$GLOBALS['EBAY']      = array( 'items' => array(), 'fails' => array(), 'get_items' => array(), 'priority' => array(), 'adopt_priority' => array(), 'parent_fails' => array() );
}

function product( $id, $sku, $stock, $item_id = '', array $meta = array() ) {
	$GLOBALS['PRODUCTS'][ $id ] = array(
		'type'        => 'simple',
		'sku'         => $sku,
		'stock'       => $stock,
		'manage'      => null !== $stock,
		'status'      => ( null === $stock || $stock > 0 ) ? 'instock' : 'outofstock',
		'post_status' => 'publish',
		'parent'      => 0,
		'meta'        => array_merge( '' !== $item_id ? array( '_ebay_item_id' => $item_id ) : array(), $meta ),
	);
}

function listing( $item_id, $status, $listed, $sold, array $extra = array() ) {
	$GLOBALS['EBAY']['items'][ $item_id ] = array( 'Ack' => 'Success', 'Item' => array_merge( array(
		'ItemID'         => $item_id,
		'Quantity'       => (string) $listed,
		'SellingStatus'  => array( 'ListingStatus' => $status, 'QuantitySold' => (string) $sold ),
		'ListingDetails' => array( 'StartTime' => '2026-09-01T10:00:00.000Z', 'EndTime' => '2026-10-11T10:00:00.000Z' ),
	), $extra ) );
}

/** eBay will fail the next reads of this listing, in this order. */
function fails( $item_id, WP_Error ...$errors ) {
	$GLOBALS['EBAY']['fails'][ $item_id ] = $errors;
}

function timeout() { return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 45001 milliseconds' ); }
function out_of_calls() { return new WP_Error( 'rate_limited', 'Daily API call budget exhausted. Will resume tomorrow.', array( 'daily_budget' => true ) ); }
/** HTTP 429: eBay throttling for a moment, as the API class returns it. */
function http_429() { return new WP_Error( 'rate_limited', 'eBay returned HTTP 429 (too many requests).', array( 'retry_after' => 0 ) ); }
function ebay_518() { return new WP_Error( 'rate_limited', 'Calls to this call have exceeded the call limit.', array( 'Errors' => array( 'ErrorCode' => '518' ) ) ); }

/** The checks waiting to run, as [args, seconds from now]. */
function waiting() {
	$out = array();
	foreach ( $GLOBALS['QUEUE'] as $action ) {
		if ( 'tcgiant_sync_settle_item' === $action['hook'] && 'pending' === $action['status'] ) {
			$out[] = array( $action['args'], $action['when'] - now() );
		}
	}
	return $out;
}

/**
 * Run the pending check due first - Action Scheduler runs them in order of
 * their time - once that time comes: through the hook. With $at, the clock
 * moves to the action's time first.
 */
function run_next( $at = false ) {
	$next = null;
	foreach ( $GLOBALS['QUEUE'] as $i => $action ) {
		if ( 'pending' === $action['status'] && ( null === $next || $action['when'] < $GLOBALS['QUEUE'][ $next ]['when'] ) ) {
			$next = $i;
		}
	}
	if ( null !== $next ) {
		$i      = $next;
		$action = $GLOBALS['QUEUE'][ $i ];
		if ( $at ) {
			$GLOBALS['NOW'] = max( now(), $action['when'] );
		}
		$GLOBALS['QUEUE'][ $i ]['status'] = 'running';
		foreach ( $GLOBALS['HOOKS'][ $action['hook'] ] ?? array() as $handler ) {
			call_user_func_array( $handler[0], array_slice( array_values( (array) $action['args'] ), 0, $handler[1] ) );
		}
		$GLOBALS['QUEUE'][ $i ]['status'] = 'done';
		return true;
	}
	return false;
}

function stock( $id ) { return $GLOBALS['PRODUCTS'][ $id ]['stock']; }
function meta( $id, $key ) { return get_post_meta( $id, $key, true ); }

function logged( $needle, $level = null ) {
	foreach ( $GLOBALS['LOG'] as $entry ) {
		if ( false !== strpos( $entry[1], $needle ) && ( null === $level || $level === $entry[0] ) ) {
			return true;
		}
	}
	return false;
}

function count_logged( $needle ) {
	return count( array_filter( $GLOBALS['LOG'], function ( $entry ) use ( $needle ) { return false !== strpos( $entry[1], $needle ); } ) );
}

/** About: within a few seconds, for the harness's own clock. */
function about( $got, $want ) { return abs( $got - $want ) <= 5; }

/** The next 00:05:30 UTC after $ts (now by default). */
function next_0005( $ts = null ) {
	$ts = null === $ts ? now() : $ts;
	$at = gmmktime( 0, 5, 30, (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ), (int) gmdate( 'Y', $ts ) );
	return $at > $ts ? $at : $at + DAY_IN_SECONDS;
}

/** When a check that met eBay's 518 is tried again (release fix round 2): 15 minutes in the hour after 00:05:30 UTC, hourly otherwise, never past the next 00:05:30. */
function next_518( $ts = null ) {
	$ts    = null === $ts ? now() : $ts;
	$reset = gmmktime( 0, 5, 30, (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ), (int) gmdate( 'Y', $ts ) );
	if ( $ts >= $reset && $ts < $reset + HOUR_IN_SECONDS ) {
		return $ts + 900;
	}
	return min( $ts + HOUR_IN_SECONDS, next_0005( $ts ) );
}

function held() { return TCGiant_Sync_Importer::held_back_listings(); }

$ITEM = '140000000001';

echo "\nA SALE CHECK EBAY CANNOT ANSWER IS TRIED AGAIN, LATER EACH TIME\n" . str_repeat( '=', 124 ) . "\n";

// One coin of three sold on eBay. The delta is eleven days behind, so if the
// check gives up, nothing else will tell the website.
fresh_shop();
product( 401, 'COIN-401', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, timeout(), timeout(), timeout(), timeout(), timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
check( 'the check is queued as 3.27 queued it', array_column( waiting(), 0 ), array( array( $ITEM ) ) );

run_next();
check( 'eBay times out: nothing changes', stock( 401 ), 3 );
check( '  the check is queued again for 15 minutes on, as try 2', array( array_column( waiting(), 0 ), about( waiting()[0][1] ?? 0, 900 ) ), array( array( array( $ITEM, 2 ) ), true ) );
check( '  and says so, naming the listing, the product and why', logged( 'Sale check: could not read eBay item 140000000001 for WC #401 (cURL error 28: Operation timed out after 45001 milliseconds). Trying again in 15 minutes (try 2 of 5).', 'warning' ), true );
check( '  not that the delta will settle it', logged( 'The delta sync will settle it' ), false );
check( '  read past the shared ceiling, as a sale\'s push reads', $EBAY['priority'], array( true ) );

$delays = array();
for ( $try = 2; $try <= 4; $try++ ) {
	run_next();
	$delays[] = array( waiting()[0][0][1] ?? null, round( ( waiting()[0][1] ?? 0 ) / 60 ) );
}
check( 'then an hour, 4 hours and 12 hours on: tries 3, 4 and 5', $delays, array( array( 3, 60.0 ), array( 4, 240.0 ), array( 5, 720.0 ) ) );
check( '  one check waiting at a time', count( waiting() ), 1 );
check( '  each said so', array( logged( 'Trying again in 1 hour (try 3 of 5).' ), logged( 'Trying again in 4 hours (try 4 of 5).' ), logged( 'Trying again in 12 hours (try 5 of 5).' ) ), array( true, true, true ) );
check( '  eBay not asked whether it answers at all, before the last', $EBAY['clock_asked'] ?? 0, 0 );

run_next();
check( 'the fifth fails too, and eBay answers other calls: it gives up', array( waiting(), $EBAY['clock_asked'] ?? 0 ), array( array(), 1 ) );
check( '  with a warning naming the listing and the product', logged( 'Sale check: gave up on eBay item 140000000001 for WC #401 after 5 tries: eBay could not be read for it (cURL error 28', 'warning' ), true );
check( '  saying the stock is unchanged and where to look', logged( 'The stock here is unchanged. It is listed on the Stock Review page; check the listing on eBay.' ), true );
check( '  listed on Stock Review, with why', held()[ $ITEM ]['why'] ?? null, TCGiant_Sync_Importer::HELD_SALE_CHECK );
check( '  as a listing the delta stepped over is', array_keys( held()[ $ITEM ] ?? array() ), array( 'item_id', 'from', 'at', 'why' ) );
check( '  stock still unchanged', stock( 401 ), 3 );
check( '  eBay asked five times in all, each past the ceiling', array( count( $EBAY['get_items'] ), array_unique( $EBAY['priority'] ) ), array( 5, array( true ) ) );
check( '  nothing left of the chain, nor its join', array( get_option( TCGiant_Sync_Importer::SETTLE_CHAINS_OPTION, null ), get_option( TCGiant_Sync_Importer::SETTLE_JOINED_PREFIX . $ITEM, null ) ), array( null, null ) );

echo "\nEBAY NOT ANSWERING AT ALL: THE LAST TRY WAITS, FOR UP TO THREE DAYS\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
product( 414, 'COIN-414', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, ...array_fill( 0, 12, timeout() ) );
$EBAY['clock'] = timeout();
TCGiant_Sync_Importer::queue_settle( $ITEM );
for ( $i = 0; $i < 5; $i++ ) {
	run_next( true );
}
check( 'the fifth fails, and so does eBay\'s clock: the fifth again, 12 hours on, not given up', array( array_column( waiting(), 0 ), round( ( waiting()[0][1] ?? 0 ) / 3600 ), held() ), array( array( array( $ITEM, 5 ) ), 12.0, array() ) );
check( '  said so', logged( 'Trying again in 12 hours: eBay is not answering other calls either.' ), true );
$waits = 0;
while ( waiting() && $waits < 10 ) {
	run_next( true );
	$waits++;
}
check( 'still not answering: given up once three days have passed since the first try', array( $waits, isset( held()[ $ITEM ] ), now() - gmmktime( 9, 0, 0, 10, 20, 2026 ) >= 3 * DAY_IN_SECONDS ), array( 5, true, true ) );
check( '  each wait asked eBay\'s clock first; the last, past three days, did not need to', $EBAY['clock_asked'], 5 );
check( 'F5 eBay\'s clock asked past the shared 80% ceiling every time, as a sale\'s push reads', array_values( array_unique( $EBAY['clock_priority'] ?? array() ) ), array( true ) );

// GeteBayOfficialTime refused by this plugin's own 80% ceiling: the question
// was never put to eBay, so that is not eBay down - the fifth try is given
// up on as it would have been, 17.25 hours from the first.
fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
product( 426, 'COIN-426', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, timeout(), timeout(), timeout(), timeout(), timeout() );
$EBAY['clock'] = out_of_calls();
TCGiant_Sync_Importer::queue_settle( $ITEM );
while ( run_next( true ) ) {}
check( 'eBay\'s clock refused by our own ceiling: not "eBay down" - given up at the fifth, 17.25 hours on', array( isset( held()[ $ITEM ] ), round( ( now() - gmmktime( 9, 0, 0, 10, 20, 2026 ) ) / 3600, 2 ), $EBAY['clock_asked'] ?? 0 ), array( true, 17.25, 1 ) );

// What eBay's clock said is kept five minutes, for every check: three sales'
// fifth tries at once ask it once; one five minutes later asks again.
fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
$EBAY['clock'] = timeout();
foreach ( array( '140000000011', '140000000012', '140000000013' ) as $i => $id ) {
	product( 430 + $i, 'COIN-43' . $i, 3, $id );
	listing( $id, 'Active', 3, 1 );
	fails( $id, ...array_fill( 0, 6, timeout() ) );
	TCGiant_Sync_Importer::queue_settle( $id );
}
while ( ( $EBAY['clock_asked'] ?? 0 ) < 1 && run_next( true ) ) {}
run_next( true );
run_next( true );
check( 'three fifth tries at once, eBay not answering: its clock asked once for all three, each the fifth again', array( $EBAY['clock_asked'] ?? 0, array_column( waiting(), 0 ) ), array( 1, array( array( '140000000011', 5 ), array( '140000000012', 5 ), array( '140000000013', 5 ) ) ) );
run_next( true );
run_next( true );
run_next( true );
check( '  12 hours on, long past its five minutes: asked again, once', $EBAY['clock_asked'] ?? 0, 2 );

// Only "not answering" is kept (fix round 3). X's fifth try fails while eBay
// answers: it asks, and is given up on. Y's fifth try a minute later, as all
// of Trading goes down, asks again, rather than going by X's answer: it
// waits. (Keeping "answering", Y was given up on at 17.25 hours.)
fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
$X = '140000000021';
$Y = '140000000022';
product( 434, 'COIN-434', 3, $X );
listing( $X, 'Active', 3, 1 );
fails( $X, ...array_fill( 0, 5, timeout() ) );
product( 435, 'COIN-435', 3, $Y );
listing( $Y, 'Active', 3, 1 );
fails( $Y, ...array_fill( 0, 6, timeout() ) );
TCGiant_Sync_Importer::queue_settle( $X );
run_next();
$NOW += 60;
TCGiant_Sync_Importer::queue_settle( $Y );
run_next();
while ( ! isset( held()[ $X ] ) && run_next( true ) ) {}
$kept_after_x = get_transient( TCGiant_Sync_Importer::EBAY_ANSWERS_TRANSIENT );
$EBAY['clock'] = timeout();                      // Trading goes down
run_next( true );                                // Y's fifth try
check( 'X given up as eBay answered; Y\'s fifth try a minute later, eBay down: asked again, and waits', array( isset( held()[ $X ] ), isset( held()[ $Y ] ), array_column( waiting(), 0 ), $EBAY['clock_asked'] ?? 0 ), array( true, false, array( array( $Y, 5 ) ), 2 ) );
check( '  eBay answering for X is not kept; not answering, for Y, is', array( $kept_after_x, get_transient( TCGiant_Sync_Importer::EBAY_ANSWERS_TRANSIENT ) ), array( false, array( 'at' => now(), 'down' => true ) ) );

echo "\nOUT OF CALLS - THE DAY'S BUDGET WAITS FOR THE NEW UTC DAY; EBAY'S 518 IS TRIED HOURLY - UNCOUNTED\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop();
product( 402, 'COIN-402', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, out_of_calls(), timeout(), out_of_calls() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
check( 'the day\'s budget used up: queued for just after 00:05 UTC', about( ( waiting()[0][1] ?? 0 ) + now(), next_0005() ), true );
check( '  still try 1', waiting()[0][0] ?? null, array( $ITEM, 1 ) );
check( '  said so', logged( 'Trying again just after 00:05 UTC, once the day\'s eBay calls start again.' ), true );
run_next( true );
check( 'then a timeout: try 2, 15 minutes on', array( waiting()[0][0] ?? null, about( waiting()[0][1] ?? 0, 900 ) ), array( array( $ITEM, 2 ), true ) );
$NOW += 3 * HOUR_IN_SECONDS;
run_next();
check( 'out of calls again on try 2, hours later: still try 2, the next 00:05:30 UTC', array( waiting()[0][0] ?? null, about( ( waiting()[0][1] ?? 0 ) + now(), next_0005() ) ), array( array( $ITEM, 2 ), true ) );
run_next( true );
check( 'then eBay answers: settled', array( stock( 402 ), waiting() ), array( 2, array() ) );

// HTTP 429 is eBay throttling for a moment: tried again in 15 minutes, as
// try 2, like any other failure - not the next day. (Fix round 1 sent it to
// 00:05:30, uncounted.)
fresh_shop();
$NOW = gmmktime( 0, 10, 0, 10, 20, 2026 );
product( 427, 'COIN-427', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, http_429() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
check( 'HTTP 429 at 00:10 UTC: try 2, 15 minutes on', array( waiting()[0][0] ?? null, waiting()[0][1] ?? null ), array( array( $ITEM, 2 ), 900 ) );

// eBay's own limit, 518, lifts when it lifts: tried again within the hour,
// uncounted (release fix round 2; it waited for 00:05:30 UTC before).
fresh_shop();
product( 404, 'COIN-404', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, ebay_518() );
$IMP->settle_queued( $ITEM, 3 );
check( 'eBay\'s 518 on try 3: still try 3, tried again within the hour', array( waiting()[0][0] ?? null, about( ( waiting()[0][1] ?? 0 ) + now(), next_518() ) ), array( array( $ITEM, 3 ), true ) );

// Out of calls on the last try is not the end of it either.
fresh_shop();
product( 403, 'COIN-403', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, out_of_calls() );
$IMP->settle_queued( $ITEM, 5 );
check( 'out of calls on the fifth try: not given up, still the fifth', array( waiting()[0][0] ?? null, isset( held()[ $ITEM ] ) ), array( array( $ITEM, 5 ), false ) );

// Three days running, and blocked again just after the day's calls start:
// the plugin's own day budget, which resets at 00:05:30 UTC.
fresh_shop();
$NOW = gmmktime( 15, 0, 0, 10, 20, 2026 );
product( 415, 'COIN-415', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, ...array_fill( 0, 20, out_of_calls() ) );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
check( 'out of calls at 15:00: waits for 00:05:30 tomorrow', waiting()[0][1] + now() ?? null, gmmktime( 0, 5, 30, 10, 21, 2026 ) );
run_next( true );
check( '  blocked again at 00:05:30: 15 minutes, not a day', array( waiting()[0][0] ?? null, waiting()[0][1] ?? null ), array( array( $ITEM, 1 ), 900 ) );
run_next( true );
run_next( true );
run_next( true );
run_next( true );
check( '  and again each 15 minutes for the hour after it, then the next day\'s 00:05:30', array( now() - gmmktime( 0, 5, 30, 10, 21, 2026 ), ( waiting()[0][1] ?? 0 ) + now() ), array( 3600, gmmktime( 0, 5, 30, 10, 22, 2026 ) ) );
while ( waiting() ) {
	run_next( true );
}
check( 'out of calls three days running: given up', array( isset( held()[ $ITEM ] ), held()[ $ITEM ]['why'] ?? null ), array( true, TCGiant_Sync_Importer::HELD_NO_CALLS ) );
check( '  saying so', logged( 'Sale check: gave up on eBay item 140000000001 for WC #415 after the day\'s eBay calls ran out 3 days running (Daily API call budget exhausted. Will resume tomorrow.). The stock here is unchanged.', 'warning' ), true );
check( '  after the third day\'s first hour', now() >= gmmktime( 1, 5, 30, 10, 23, 2026 ) && now() < gmmktime( 0, 5, 30, 10, 24, 2026 ), true );

// N2 (release fix round 2): eBay's 518 from 15:00, lifting at 19:00. Tried
// again hourly, each try asking eBay's clock first; while the clock meets the
// 518 too, nothing else is sent.
fresh_shop();
$NOW = gmmktime( 15, 0, 0, 10, 20, 2026 );
product( 416, 'COIN-416', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, ebay_518() );
$EBAY['clock'] = ebay_518();
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
check( 'N2 eBay\'s 518 at 15:00: tried again at 16:00, still try 1', array( waiting()[0][0] ?? null, ( waiting()[0][1] ?? 0 ) + now() ), array( array( $ITEM, 1 ), gmmktime( 16, 0, 0, 10, 20, 2026 ) ) );
check( '  the 518 kept till then for every check, push and pass', (int) ( get_transient( TCGiant_Sync_Importer::EBAY_518_TRANSIENT )['until'] ?? 0 ), gmmktime( 16, 0, 0, 10, 20, 2026 ) );
$reads = count( $EBAY['get_items'] );
run_next( true );
run_next( true );
check( '  16:00 and 17:00, its limit not lifted: eBay\'s clock asked once each hour, the listing not read, still try 1', array( count( $EBAY['get_items'] ) - $reads, $EBAY['clock_asked'] ?? 0, waiting()[0][0] ?? null, ( waiting()[0][1] ?? 0 ) + now() ), array( 0, 2, array( $ITEM, 1 ), gmmktime( 18, 0, 0, 10, 20, 2026 ) ) );
check( '  each time asked past the shared 80% ceiling, as the outage question is', array_values( array_unique( $EBAY['clock_priority'] ?? array() ) ), array( true ) );
run_next( true );
unset( $EBAY['clock'] );
run_next( true );
check( '  lifted at 19:00: read at once, settled - an hour after it lifts at most, not the next 00:05:30', array( stock( 416 ), waiting(), now() ), array( 2, array(), gmmktime( 19, 0, 0, 10, 20, 2026 ) ) );

// A 518, then a timeout once it has lifted: a try counted, and no longer
// waiting on the 518 - the next try reads without asking the clock first.
fresh_shop();
$NOW = gmmktime( 15, 0, 0, 10, 20, 2026 );
product( 418, 'COIN-418', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, ebay_518(), timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
run_next( true );
$tcg_asked = array( $EBAY['clock_asked'] ?? 0, waiting()[0][0] ?? null );
run_next( true );
check( 'N2 a 518, then a timeout once it lifted: try 2, and that try reads without asking eBay\'s clock again', array( $tcg_asked, $EBAY['clock_asked'] ?? 0, stock( 418 ), waiting() ), array( array( 1, array( $ITEM, 2 ) ), 1, 2, array() ) );

// A new sale while the check waits on the 518: its own first check reads
// eBay at once, as every first check does, and met by the 518 too, joins the
// waiting try. Only the tries waiting on it ask the shared question.
fresh_shop();
$NOW = gmmktime( 15, 0, 0, 10, 20, 2026 );
product( 420, 'COIN-420', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, ebay_518(), ebay_518() );
$EBAY['clock'] = ebay_518();
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
$NOW += 600;
TCGiant_Sync_Importer::queue_settle( $ITEM );
$reads = count( $EBAY['get_items'] );
run_next();
check( 'N2 a new sale\'s first check while one waits on the 518: it reads eBay itself, meets the 518, and joins the waiting try', array( count( $EBAY['get_items'] ) - $reads, $EBAY['clock_asked'] ?? 0, array_column( waiting(), 0 ) ), array( 1, 0, array( array( $ITEM, 1 ) ) ) );
unset( $EBAY['clock'] );

// RF3-L4 (release fix round 3): two checks waiting on the 518 in the same
// slot, and it has lifted: the first asks, and the second goes on its answer.
fresh_shop();
$NOW = gmmktime( 15, 0, 0, 10, 20, 2026 );
product( 421, 'COIN-421', 3, '140000000021' );
listing( '140000000021', 'Active', 3, 1 );
fails( '140000000021', ebay_518() );
product( 422, 'COIN-422', 3, '140000000022' );
listing( '140000000022', 'Active', 3, 1 );
fails( '140000000022', ebay_518() );
TCGiant_Sync_Importer::queue_settle( '140000000021' );
TCGiant_Sync_Importer::queue_settle( '140000000022' );
run_next();
run_next();
$tcg_waiting = array_column( waiting(), 0 );
run_next( true );
run_next( true );
check( 'RF3-L4 two checks waiting on the 518 in one slot, the limit lifted: eBay\'s clock asked once for both, and both settled', array( $tcg_waiting, $EBAY['clock_asked'] ?? 0, stock( 421 ), stock( 422 ), waiting() ), array( array( array( '140000000021', 1 ), array( '140000000022', 1 ) ), 1, 2, 2, array() ) );
// The question timing out is no answer, and is not kept: each asks.
fresh_shop();
$NOW = gmmktime( 15, 0, 0, 10, 20, 2026 );
product( 421, 'COIN-421', 3, '140000000021' );
listing( '140000000021', 'Active', 3, 1 );
fails( '140000000021', ebay_518() );
product( 422, 'COIN-422', 3, '140000000022' );
listing( '140000000022', 'Active', 3, 1 );
fails( '140000000022', ebay_518() );
TCGiant_Sync_Importer::queue_settle( '140000000021' );
TCGiant_Sync_Importer::queue_settle( '140000000022' );
run_next();
run_next();
$EBAY['clock'] = timeout();
run_next( true );
run_next( true );
unset( $EBAY['clock'] );
check( '  the question timing out: not kept - each asks', $EBAY['clock_asked'] ?? 0, 2 );

// A try whose time comes while another one's 518 is kept: put off to that
// kept slot, which it leaves where it is - not a slot of its own an hour on,
// which moved every waiting one's question later.
fresh_shop();
$NOW = gmmktime( 14, 30, 0, 10, 20, 2026 );
product( 423, 'COIN-423', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, ebay_518() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
set_transient( TCGiant_Sync_Importer::EBAY_518_TRANSIENT, array( 'until' => gmmktime( 16, 0, 0, 10, 20, 2026 ) ), 1800 );
run_next( true );
check( 'N2 its try at 15:30 while a 518 is kept till 16:00: put off to 16:00, the kept slot unmoved, eBay not asked', array( now(), ( waiting()[0][1] ?? 0 ) + now(), (int) ( get_transient( TCGiant_Sync_Importer::EBAY_518_TRANSIENT )['until'] ?? 0 ), $EBAY['clock_asked'] ?? 0 ), array( gmmktime( 15, 30, 0, 10, 20, 2026 ), gmmktime( 16, 0, 0, 10, 20, 2026 ), gmmktime( 16, 0, 0, 10, 20, 2026 ), 0 ) );

// RF3-L6: just before 00:05:30 the next slot is 30 s, or 1 s, away. What is
// kept for everyone is never less than a minute: the try at 00:05:30 asks
// nothing, and is put off to the kept time.
foreach ( array( array( 0, 5, 0 ), array( 0, 5, 29 ) ) as $tcg_hms ) {
	fresh_shop();
	$NOW = gmmktime( $tcg_hms[0], $tcg_hms[1], $tcg_hms[2], 10, 21, 2026 );
	$tcg_from = $NOW;
	product( 424, 'COIN-424', 3, $ITEM );
	listing( $ITEM, 'Active', 3, 1 );
	fails( $ITEM, ebay_518() );
	TCGiant_Sync_Importer::queue_settle( $ITEM );
	run_next();
	$tcg_first = array( ( waiting()[0][1] ?? 0 ) + now(), (int) ( get_transient( TCGiant_Sync_Importer::EBAY_518_TRANSIENT )['until'] ?? 0 ) );
	run_next( true );
	check( sprintf( 'RF3-L6 a 518 at %s UTC: tried at 00:05:30, the slot kept a minute on; that try asks nothing and waits for it', gmdate( 'H:i:s', $tcg_from ) ), array( $tcg_first, $EBAY['clock_asked'] ?? 0, ( waiting()[0][1] ?? 0 ) + now() ), array( array( gmmktime( 0, 5, 30, 10, 21, 2026 ), $tcg_from + 60 ), 0, $tcg_from + 60 ) );
}

// Late in the UTC day: never later than 00:05:30, when eBay's day starts
// again; in the hour after it, every 15 minutes.
fresh_shop();
$NOW = gmmktime( 23, 30, 0, 10, 20, 2026 );
product( 419, 'COIN-419', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, ebay_518(), ebay_518() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
$tcg_first = ( waiting()[0][1] ?? 0 ) + now();
run_next( true );
check( 'N2 a 518 at 23:30 UTC: tried again at 00:05:30, not 00:30; met again then, every 15 minutes', array( $tcg_first, ( waiting()[0][1] ?? 0 ) + now() ), array( gmmktime( 0, 5, 30, 10, 21, 2026 ), gmmktime( 0, 20, 30, 10, 21, 2026 ) ) );

// eBay's 518 for three days: given up, as for the day's calls.
fresh_shop();
$NOW = gmmktime( 15, 0, 0, 10, 20, 2026 );
product( 417, 'COIN-417', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, ...array_fill( 0, 200, ebay_518() ) );
$EBAY['clock'] = ebay_518();
TCGiant_Sync_Importer::queue_settle( $ITEM );
$runs = 0;
while ( waiting() && $runs < 400 ) {
	run_next( true );
	$runs++;
}
check( 'N2 eBay\'s 518 for three days: given up three days on, listed on Stock Review', array( isset( held()[ $ITEM ] ), held()[ $ITEM ]['why'] ?? null, now() >= gmmktime( 15, 0, 0, 10, 23, 2026 ), now() < gmmktime( 17, 0, 0, 10, 23, 2026 ) ), array( true, TCGiant_Sync_Importer::HELD_NO_CALLS, true, true ) );
check( '  saying so', logged( 'after eBay\'s call limit held for 3 days' ), true );
check( '  the listing read only at the start and, three days on, once more; the clock about once an hour, and every 15 minutes after 00:05:30', array( count( $EBAY['get_items'] ), ( $EBAY['clock_asked'] ?? 0 ) >= 70 && ( $EBAY['clock_asked'] ?? 0 ) <= 95 ), array( 2, true ) );

echo "\nNEVER A SECOND CHECK OF THE SAME LISTING WHILE ONE WAITS\n" . str_repeat( '=', 124 ) . "\n";

// Three eBay sales of one listing, while eBay is down.
fresh_shop();
product( 405, 'COIN-405', 5, $ITEM );
listing( $ITEM, 'Active', 5, 3 );
fails( $ITEM, timeout(), timeout(), timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
check( 'the first fails: try 2 waits', array_column( waiting(), 0 ), array( array( $ITEM, 2 ) ) );
TCGiant_Sync_Importer::queue_settle( $ITEM );
check( 'a new sale queues its own check, as 3.27 did', array_column( waiting(), 0 ), array( array( $ITEM, 2 ), array( $ITEM ) ) );
run_next();
check( '  which fails too: no second try 2 beside the first', array_column( waiting(), 0 ), array( array( $ITEM, 2 ) ) );
check( '  said so', logged( 'Sale check: could not read eBay item 140000000001 for WC #405 (cURL error 28: Operation timed out after 45001 milliseconds). A check of it is already waiting.' ), true );
// The running check is not a waiting one: a check that fails always queues its retry.
check( '  (the running check never counts as waiting: the first one did queue)', count( array_filter( $QUEUE, function ( $a ) { return 'done' === $a['status']; } ) ), 2 );
run_next();
check( 'try 2 fails: try 3, one hour on', array_column( waiting(), 0 ), array( array( $ITEM, 3 ) ) );
run_next();
check( 'then eBay answers: settled once, nothing left waiting', array( stock( 405 ), waiting() ), array( 2, array() ) );
check( '  and its chain is forgotten, its join too', array( get_option( TCGiant_Sync_Importer::SETTLE_CHAINS_OPTION, null ), get_option( TCGiant_Sync_Importer::SETTLE_JOINED_PREFIX . $ITEM, null ) ), array( null, null ) );

echo "\nA CHECK THAT SUCCEEDED WHILE AN OLDER CHAIN WAITS: THE CHAIN ENDS QUIETLY\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
product( 416, 'COIN-416', 5, $ITEM );
listing( $ITEM, 'Active', 5, 1 );
fails( $ITEM, timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
$NOW += 60;
listing( $ITEM, 'Active', 5, 2 );               // a second eBay sale
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();                                    // read: stock 3
fails( $ITEM, timeout(), timeout(), timeout(), timeout() );
$before = count( $EBAY['get_items'] );
$LOG    = array();
while ( run_next( true ) ) {}
check( 'sale 2\'s check settled the stock to 3', stock( 416 ), 3 );
check( '  the waiting try of sale 1\'s chain then asks eBay nothing', count( $EBAY['get_items'] ) - $before, 0 );
check( '  gives up nothing, lists nothing, and says why it is not needed', array( count_logged( 'gave up' ), held(), logged( 'Sale check: eBay item 140000000001 was read and settled by a later check, so the one waiting to try again is not needed.' ) ), array( 0, array(), true ) );
check( '  and its chain is forgotten, its join too', array( get_option( TCGiant_Sync_Importer::SETTLE_CHAINS_OPTION, null ), get_option( TCGiant_Sync_Importer::SETTLE_JOINED_PREFIX . $ITEM, null ) ), array( null, null ) );

// The read sent in the same second as the failure: not known to be after
// the sale, so the try asks.
fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
product( 422, 'COIN-422', 5, $ITEM );
listing( $ITEM, 'Active', 5, 1 );
fails( $ITEM, timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
$before = count( $EBAY['get_items'] );
while ( run_next( true ) ) {}
check( 'a read sent in the very second of the failure: the waiting try still asks', count( $EBAY['get_items'] ) - $before, 1 );

// The same for a listing no product follows: its check read eBay, found no
// relist of a followed product, and that answers the chain too.
fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
listing( $ITEM, 'Active', 5, 1 );
fails( $ITEM, timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
check( 'a listing no product follows, not read: try 2 waits', array_column( waiting(), 0 ), array( array( $ITEM, 2 ) ) );
$NOW += 60;
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();                                    // read: not a relist
fails( $ITEM, timeout(), timeout(), timeout(), timeout() );
$before = count( $EBAY['get_items'] );
while ( run_next( true ) ) {}
check( '  read by a later check: the waiting try asks eBay nothing, and its chain is forgotten', array( count( $EBAY['get_items'] ) - $before, held(), get_option( TCGiant_Sync_Importer::SETTLE_CHAINS_OPTION, null ) ), array( 0, array(), null ) );

// A chain whose waiting try is gone - deleted with Action Scheduler's
// history, say - is forgotten once it is five days old, with its join. One
// whose try still waits is kept however old: its try ends it. (3.28.0 fix
// round 1 forgot any chain three days old, so another listing's check
// deleted a live one, which then started its three days again.)
fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
update_option( TCGiant_Sync_Importer::SETTLE_CHAINS_OPTION, array(
	'140000000999' => array( 'since' => now() - 5 * DAY_IN_SECONDS - 60, 'out' => 0, 'out_day' => '' ),
	'140000000998' => array( 'since' => now() - 4 * DAY_IN_SECONDS, 'out' => 0, 'out_day' => '' ),
	'140000000997' => array( 'since' => now() - 6 * DAY_IN_SECONDS, 'out' => 0, 'out_day' => '' ),
) );
update_option( TCGiant_Sync_Importer::SETTLE_JOINED_PREFIX . '140000000999', now() - 5 * DAY_IN_SECONDS - 60 );
as_schedule_single_action( now() + HOUR_IN_SECONDS, 'tcgiant_sync_settle_item', array( '140000000997', 5 ), 'tcgiant_sync_settle' );
product( 419, 'COIN-419', 5, $ITEM );
listing( $ITEM, 'Active', 5, 1 );
fails( $ITEM, timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
check( 'chains at the next write: one over five days old with nothing waiting forgotten, with its join; four days old, or six with its try waiting, kept', array( array_map( 'strval', array_keys( get_option( TCGiant_Sync_Importer::SETTLE_CHAINS_OPTION, array() ) ) ), get_option( TCGiant_Sync_Importer::SETTLE_JOINED_PREFIX . '140000000999', null ) ), array( array( '140000000998', '140000000997', $ITEM ), null ) );
check( '  the chains are not autoloaded', $AUTOLOAD[ TCGiant_Sync_Importer::SETTLE_CHAINS_OPTION ] ?? null, false );
check( '  nor the join', $JOIN_AUTOLOAD[ TCGiant_Sync_Importer::SETTLE_JOINED_PREFIX . $ITEM ] ?? null, false );

echo "\nA SALE WHOSE CHECK FINDS A TRY WAITING: THAT TRY STILL READS EBAY FOR IT\n" . str_repeat( '=', 124 ) . "\n";

// Sale 1's check fails and its try 2 waits. A later check reads eBay. Then
// another sale's check fails, finds try 2 waiting and queues nothing: try 2
// must read eBay for it. Fix round 1 let it end quietly on the earlier read,
// and that sale never reached the website.
foreach ( array( 'the read after sale 2, then sale 3' => 3, 'the read before sale 2' => 2 ) as $how => $sales ) {
	fresh_shop();
	$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
	product( 423, 'COIN-423', 5, $ITEM );
	listing( $ITEM, 'Active', 5, 1 );            // sale 1
	fails( $ITEM, timeout() );
	TCGiant_Sync_Importer::queue_settle( $ITEM );
	run_next();
	$NOW += 60;
	if ( 3 === $sales ) {
		listing( $ITEM, 'Active', 5, 2 );        // sale 2
	}
	TCGiant_Sync_Importer::queue_settle( $ITEM );
	run_next();                                  // reads eBay
	$NOW += 60;
	listing( $ITEM, 'Active', 5, $sales );       // the last sale
	fails( $ITEM, timeout() );
	TCGiant_Sync_Importer::queue_settle( $ITEM );
	run_next();                                  // fails, finds try 2 waiting
	$queued = array_column( waiting(), 0 );
	while ( run_next( true ) ) {}
	check( "$how: the last sale's check queued nothing beside try 2, and try 2 took its sale off", array( $queued, stock( 423 ), count_logged( 'is not needed' ) ), array( array( array( $ITEM, 2 ) ), 5 - $sales, 0 ) );
}

// The chains option put back from an older copy - another listing's check
// read it before the last sale's check wrote, and wrote after - still
// holding the earlier read: the join, in its own row, is not lost with it.
fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
product( 424, 'COIN-424', 5, $ITEM );
listing( $ITEM, 'Active', 5, 1 );
fails( $ITEM, timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
$NOW += 60;
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();                                      // reads eBay: the chain says answered
$older = get_option( TCGiant_Sync_Importer::SETTLE_CHAINS_OPTION );
$NOW  += 60;
listing( $ITEM, 'Active', 5, 2 );
fails( $ITEM, timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();                                      // fails, finds try 2 waiting
update_option( TCGiant_Sync_Importer::SETTLE_CHAINS_OPTION, $older );   // the older copy written back
while ( run_next( true ) ) {}
check( 'the chains written back from an older copy: try 2 still reads eBay, and the sale comes off', array( ! empty( $older[ $ITEM ]['answered'] ), stock( 424 ), count_logged( 'is not needed' ) ), array( true, 3, 0 ) );

// A sale's check fails while another check's read is out: the read was
// sent before that sale's check gave up, so it does not answer for it.
fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
product( 428, 'COIN-428', 5, $ITEM );
listing( $ITEM, 'Active', 5, 1 );
fails( $ITEM, timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
$NOW += 60;
TCGiant_Sync_Importer::queue_settle( $ITEM );
$EBAY['during'] = function () use ( $ITEM ) {
	// The read takes two minutes; a minute in, sale 2's check fails, finds try 2 waiting.
	$GLOBALS['NOW'] += 60;
	$join = new ReflectionMethod( 'TCGiant_Sync_Importer', 'note_settle_join' );
	$join->setAccessible( true );
	$join->invoke( null, $ITEM );
	$GLOBALS['NOW'] += 60;
};
run_next();                                      // the read, answered with sale 1 only
$after_read = stock( 428 );
listing( $ITEM, 'Active', 5, 2 );                // sale 2, as eBay has it now
while ( run_next( true ) ) {}
check( 'a sale\'s check failing while another\'s read is out: that read does not answer for it - try 2 reads, sale 2 comes off', array( $after_read, stock( 428 ), count_logged( 'is not needed' ) ), array( 4, 3, 0 ) );

// No join noted at all - its row lost - and the chain answered: the try asks.
fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
product( 429, 'COIN-429', 5, $ITEM );
listing( $ITEM, 'Active', 5, 1 );
fails( $ITEM, timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
$NOW += 60;
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
delete_option( TCGiant_Sync_Importer::SETTLE_JOINED_PREFIX . $ITEM );
$before = count( $EBAY['get_items'] );
while ( run_next( true ) ) {}
check( 'a chain answered with no join noted: the try still asks', count( $EBAY['get_items'] ) - $before, 1 );

// The join kept as the latest: a clock put back does not move it earlier.
fresh_shop();
$NOW  = gmmktime( 9, 0, 0, 10, 20, 2026 );
$join = new ReflectionMethod( 'TCGiant_Sync_Importer', 'note_settle_join' );
$join->setAccessible( true );
$join->invoke( null, $ITEM );
$NOW -= 600;
$join->invoke( null, $ITEM );
check( 'the join noted again with the clock put back: still the later time', (int) get_option( TCGiant_Sync_Importer::SETTLE_JOINED_PREFIX . $ITEM, 0 ), gmmktime( 9, 0, 0, 10, 20, 2026 ) );

// Two failed checks of one listing at once (fix round 3): check A notes its
// join, but a third check's read and then check C's failure land before A's
// statement does. One statement keeping the later time leaves C's. (Read and
// then written, A wrote its older time over C's, and the waiting try ended
// quietly on the read between them: sale C never reached the website.)
fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
product( 432, 'COIN-432', 6, $ITEM );
listing( $ITEM, 'Active', 6, 1 );                // sale 1
fails( $ITEM, timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();                                      // its chain, try 2 waiting
$NOW += 60;
listing( $ITEM, 'Active', 6, 2 );                // sale A
fails( $ITEM, timeout() );
$ON_JOIN_QUERY = function () use ( $ITEM ) {
	unset( $GLOBALS['ON_JOIN_QUERY'] );
	$GLOBALS['NOW'] += 10;
	TCGiant_Sync_Importer::instance()->settle_item( $ITEM, 'Ended listing check' );   // B reads eBay: sales 1 and A
	$GLOBALS['NOW'] += 10;
	listing( $ITEM, 'Active', 6, 3 );            // sale C, after B's read
	fails( $ITEM, timeout() );
	TCGiant_Sync_Importer::instance()->settle_item( $ITEM, 'Ended listing check' );   // C fails, finds try 2 waiting, notes its join
};
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();                                      // A fails; its join lands last, with the older time
unset( $ON_JOIN_QUERY );
$joined = (int) get_option( TCGiant_Sync_Importer::SETTLE_JOINED_PREFIX . $ITEM, 0 );
while ( run_next( true ) ) {}
check( 'two failed checks at once, a read between them: the later join kept, and try 2 takes sale C off', array( $joined, stock( 432 ), count_logged( 'is not needed' ) ), array( gmmktime( 9, 1, 20, 10, 20, 2026 ), 3, 0 ) );

// A sale seen while its listing's check is running, after that check's read
// was answered (pre-existing since 3.27): its own check is queued - only a
// pending check stands in for it - and takes the sale off.
fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
product( 433, 'COIN-433', 5, $ITEM );
listing( $ITEM, 'Active', 5, 1 );
$EBAY['during'] = function () use ( $ITEM ) {
	$GLOBALS['QUEUED_MEANWHILE'] = TCGiant_Sync_Importer::queue_settle( $ITEM );   // order sync, in another request
};
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();                                      // running: its read answers with 1 sold
listing( $ITEM, 'Active', 5, 2 );                // sale 2, on eBay
while ( run_next( true ) ) {}
check( 'a sale seen while its listing\'s check runs: queued, and settled', array( $QUEUED_MEANWHILE ?? null, stock( 433 ) ), array( true, 3 ) );
TCGiant_Sync_Importer::queue_settle( $ITEM );
check( '  while one is pending, still never a second', TCGiant_Sync_Importer::queue_settle( $ITEM ), false );

// The chain lost altogether while its try waits: the try reads eBay, and
// failing, starts the chain again.
fresh_shop();
$NOW = gmmktime( 9, 0, 0, 10, 20, 2026 );
product( 425, 'COIN-425', 5, $ITEM );
listing( $ITEM, 'Active', 5, 1 );
fails( $ITEM, timeout(), timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
delete_option( TCGiant_Sync_Importer::SETTLE_CHAINS_OPTION );
$before = count( $EBAY['get_items'] );
run_next( true );
check( 'the chain lost while its try waits: the try reads eBay, and fails into a new chain from now, try 3', array( count( $EBAY['get_items'] ) - $before, array_column( waiting(), 0 ), ( get_option( TCGiant_Sync_Importer::SETTLE_CHAINS_OPTION, array() )[ $ITEM ]['since'] ?? 0 ) === now() ), array( 1, array( array( $ITEM, 3 ) ), true ) );

echo "\nMANY GIVE-UPS: THE DELTA'S OWN ENTRIES STAY ON STOCK REVIEW\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop();
$hold = new ReflectionMethod( 'TCGiant_Sync_Importer', 'hold_back_listing' );
$hold->setAccessible( true );
$hold->invoke( null, '150000000777', '2026-10-24T00:00:00.000Z', TCGiant_Sync_Importer::HELD_KILLED );
for ( $i = 0; $i < 60; $i++ ) {
	$id = (string) ( 140000001000 + $i );
	product( 1000 + $i, 'MANY-' . $i, 2, $id );
	listing( $id, 'Active', 2, 1 );
	fails( $id, timeout(), timeout(), timeout(), timeout(), timeout() );
	TCGiant_Sync_Importer::queue_settle( $id );
}
while ( run_next() ) {}
$h = held();
check( 'after 60 sale checks gave up: the listing that stopped the delta is still listed', isset( $h['150000000777'] ), true );
check( '  and the newest 50 give-ups, kept apart from it', array( count( $h ), count( array_filter( $h, function ( $e ) { return TCGiant_Sync_Importer::HELD_SALE_CHECK === $e['why']; } ) ), isset( $h['140000001059'] ), isset( $h['140000001009'] ) ), array( 51, 50, true, false ) );
// And the other way: 60 step-overs leave the sale checks' entries alone.
for ( $i = 0; $i < 60; $i++ ) {
	$hold->invoke( null, (string) ( 150000002000 + $i ), '2026-10-24T00:00:00.000Z', TCGiant_Sync_Importer::HELD_UNREAD );
}
$h = held();
check( '  60 step-overs later: 50 of each', array( count( array_filter( $h, function ( $e ) { return TCGiant_Sync_Importer::is_check_reason( $e['why'] ); } ) ), count( $h ) ), array( 50, 100 ) );

echo "\nA LISTING THE DELTA STEPPED OVER WHOSE CHECK GIVES UP: ITS ENTRY KEPT, THE CHECK ADDED\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop();
$hold->invoke( null, $ITEM, '2026-10-24T00:00:00.000Z', TCGiant_Sync_Importer::HELD_KILLED );
$was = held()[ $ITEM ];
product( 417, 'COIN-417', 2, $ITEM );
listing( $ITEM, 'Active', 2, 1 );
fails( $ITEM, timeout(), timeout(), timeout(), timeout(), timeout() );
TCGiant_Sync_Importer::queue_settle( $ITEM );
while ( run_next() ) {}
$now_entry = held()[ $ITEM ] ?? array();
check( 'still listed as stopping the sync, in its window, since then', array( $now_entry['why'] ?? null, $now_entry['from'] ?? null, $now_entry['at'] ?? null ), array( TCGiant_Sync_Importer::HELD_KILLED, '2026-10-24T00:00:00.000Z', $was['at'] ) );
check( '  with its check giving up too', $now_entry['check'] ?? null, TCGiant_Sync_Importer::HELD_SALE_CHECK );
// The same for one given up when the day's calls ran out.
fresh_shop();
$hold->invoke( null, $ITEM, '2026-10-24T00:00:00.000Z', TCGiant_Sync_Importer::HELD_UNREAD );
$hold->invoke( null, $ITEM, '', TCGiant_Sync_Importer::HELD_NO_CALLS );
check( 'one given up when the day\'s calls ran out: the same', array( held()[ $ITEM ]['why'] ?? null, held()[ $ITEM ]['check'] ?? null ), array( TCGiant_Sync_Importer::HELD_UNREAD, TCGiant_Sync_Importer::HELD_NO_CALLS ) );

echo "\nA LATER SUCCESS SETTLES EXACTLY AS A FIRST-TIME ONE\n" . str_repeat( '=', 124 ) . "\n";

/**
 * A product whose stock already took off one of eBay's sales (the stock
 * push's ledger, _ebay_sold_seen), and two more sold since: eBay 5 listed, 3
 * sold, 2 left; WooCommerce 4.
 */
function ledger_shop( array $failures ) {
	fresh_shop();
	product( 406, 'COIN-406', 4, $GLOBALS['ITEM'], array( TCGiant_Sync_Inventory::SOLD_SEEN_KEY => array( 'item' => $GLOBALS['ITEM'], 'sku' => '', 'sold' => 1 ) ) );
	listing( $GLOBALS['ITEM'], 'Active', 5, 3 );
	fails( $GLOBALS['ITEM'], ...$failures );
	TCGiant_Sync_Importer::queue_settle( $GLOBALS['ITEM'] );
	while ( run_next( true ) ) {}
	$row = $GLOBALS['PRODUCTS'][406];
	return array( $row['stock'], $row['status'], $row['meta'], TCGiant_Sync_Inventory::sold_seen( 406, $GLOBALS['ITEM'] ), $GLOBALS['UNGUARDED'] );
}
$first = ledger_shop( array() );
$later = ledger_shop( array( timeout(), out_of_calls(), timeout(), new WP_Error( 'no_token', 'No valid eBay token.' ), new WP_Error( 'invalid_xml', 'Invalid XML from eBay Trading API.' ) ) );
check( 'first time: stock 4 lowered to eBay\'s 2', $first[0], 2 );
check( '  eBay\'s 3 sales recorded as taken off, so a push does not take them off again', $first[3], 3 );
check( 'after a timeout, the day\'s calls, a timeout, no token and a bad answer: the same stock', $later[0], $first[0] );
check( '  the same product, meta and all', array( $later[1], $later[2] ), array( $first[1], $first[2] ) );
check( '  the same ledger', $later[3], $first[3] );
check( '  nothing sent back to eBay either way', array( $first[4], $later[4] ), array( array(), array() ) );
check( '  and it said it settled', logged( 'Sale check: eBay item 140000000001 - WC #406 stock now 2 (eBay: 5 listed, 3 sold).' ), true );

// An ended listing, settled after two failures, exactly as at once.
function ended_shop( array $failures ) {
	fresh_shop();
	$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = array( 'remove_sold_products' => '0' );
	product( 407, 'COIN-407', 1, $GLOBALS['ITEM'] );
	listing( $GLOBALS['ITEM'], 'Completed', 1, 1 );
	fails( $GLOBALS['ITEM'], ...$failures );
	TCGiant_Sync_Importer::queue_settle( $GLOBALS['ITEM'] );
	while ( run_next() ) {}
	return $GLOBALS['PRODUCTS'][407];
}
check( 'an ended listing settled after two timeouts: as at once', ended_shop( array( timeout(), timeout() ) ), ended_shop( array() ) );

echo "\nA LISTING NO PRODUCT FOLLOWS, WHICH MAY BE A RELIST OF ONE THAT DOES\n" . str_repeat( '=', 124 ) . "\n";

// The order's listing is not the one the product follows: it may be its
// relist, which only reading it can tell.
$RELIST = '140000000099';
fresh_shop();
product( 408, 'COIN-408', 2, $ITEM );
listing( $RELIST, 'Active', 2, 1, array( 'RelistParentID' => $ITEM ) );
fails( $RELIST, timeout() );
TCGiant_Sync_Importer::queue_settle( $RELIST );
run_next();
check( 'the relist cannot be read: tried again, 15 minutes on', array( array_column( waiting(), 0 ), about( waiting()[0][1] ?? 0, 900 ) ), array( array( array( $RELIST, 2 ) ), true ) );
check( '  said so', logged( 'Sale check: could not read eBay item 140000000099 for the product it may be a relist of (cURL error 28' ), true );
check( '  nothing moved on yet', array( meta( 408, '_ebay_item_id' ), $ADOPTED ), array( $ITEM, array() ) );
run_next();
check( 'read the second time: the product moves on to the relist', array( meta( 408, '_ebay_item_id' ), $ADOPTED ), array( $RELIST, array( $RELIST ) ) );
check( '  and takes its sale off', stock( 408 ), 1 );
check( '  both reads, and the old listing\'s, past the shared ceiling', array( array_unique( $EBAY['priority'] ), $EBAY['adopt_priority'] ), array( array( true ), array( true ) ) );

fresh_shop();
product( 409, 'COIN-409', 2, $ITEM );
fails( $RELIST, timeout(), timeout(), timeout(), timeout(), timeout() );
TCGiant_Sync_Importer::queue_settle( $RELIST );
while ( run_next() ) {}
check( 'never readable: given up after five tries, saying so', logged( 'Sale check: gave up on eBay item 140000000099 for the product it may be a relist of after 5 tries' ), true );
check( '  and listed on Stock Review', held()[ $RELIST ]['why'] ?? null, TCGiant_Sync_Importer::HELD_SALE_CHECK );

// The relist is read, but the listing it was relisted from cannot be, just now.
foreach ( array( 'a timeout' => 'timeout', 'an error page' => 'xml', 'eBay\'s 518' => 'e518' ) as $how => $kind ) {
	fresh_shop();
	product( 418, 'COIN-418', 2, $ITEM );
	listing( $RELIST, 'Active', 2, 1, array( 'RelistParentID' => $ITEM ) );
	$err = 'timeout' === $kind ? timeout() : ( 'xml' === $kind ? new WP_Error( 'invalid_xml', 'Invalid XML from eBay Trading API.' ) : ebay_518() );
	$EBAY['parent_fails'] = array( $err );
	TCGiant_Sync_Importer::queue_settle( $RELIST );
	run_next();
	$w = waiting();
	if ( 'e518' === $kind ) {
		check( "the old listing unreadable, $how: the check is tried again within the hour, uncounted", array( $w[0][0] ?? null, about( ( $w[0][1] ?? 0 ) + now(), next_518() ) ), array( array( $RELIST, 1 ), true ) );
	} else {
		check( "the old listing unreadable, $how: the check is tried again, as try 2", array( $w[0][0] ?? null, about( $w[0][1] ?? 0, 900 ) ), array( array( $RELIST, 2 ), true ) );
	}
	check( '  and nothing said yet of a sale', logged( 'could not be read to confirm' ), false );
	run_next( true );
	check( '  read the next time: moved on to the relist, its sale taken off', array( meta( 418, '_ebay_item_id' ), stock( 418 ) ), array( $RELIST, 1 ) );
}
// Never readable: the shop is told at the give-up, naming the product.
fresh_shop();
product( 419, 'COIN-419', 2, $ITEM );
listing( $RELIST, 'Active', 2, 1, array( 'RelistParentID' => $ITEM ) );
$EBAY['parent_fails'] = array( timeout(), timeout(), timeout(), timeout(), timeout() );
TCGiant_Sync_Importer::queue_settle( $RELIST );
while ( run_next() ) {}
check( 'the old listing never readable: the shop told at the give-up, once', array( count_logged( 'could not be read to confirm' ), logged( 'Sale check: eBay listing 140000000099 sold 1. It was relisted from 140000000001, which WC #419 follows, but 140000000001 could not be read to confirm they are the same item (cURL error 28: Operation timed out after 45001 milliseconds), so the stock of WC #419 is unchanged. If they are, set that stock on the website. It is listed on the Stock Review page.', 'warning' ) ), array( 1, true ) );
check( '  listed on Stock Review with that product, and the listing it follows', array( held()[ $RELIST ]['why'] ?? null, held()[ $RELIST ]['product'] ?? null, held()[ $RELIST ]['parent'] ?? null ), array( TCGiant_Sync_Importer::HELD_SALE_CHECK, 419, $ITEM ) );

echo "\nAN ANSWER FROM EBAY IS NOT TRIED AGAIN\n" . str_repeat( '=', 124 ) . "\n";

// eBay answered: the listing is not there, or not this seller's. Waiting
// would not change that, so it is as before 3.28.0.
fresh_shop();
product( 410, 'COIN-410', 2, $ITEM );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
check( 'eBay says the item cannot be found: no retry', waiting(), array() );
check( '  the warning as before', logged( 'Sale check: could not read eBay item 140000000001 for WC #410 (The item could not be found.). The delta sync will settle it when it reaches the sale.', 'warning' ), true );
check( '  not held back', held(), array() );

fresh_shop();
product( 411, 'COIN-411', 2, $ITEM );
$EBAY['items'][ $ITEM ] = array( 'Ack' => 'Success' );
TCGiant_Sync_Importer::queue_settle( $ITEM );
run_next();
check( 'eBay answers without the listing: no retry, as before', array( waiting(), logged( '(eBay returned nothing). The delta sync will settle it' ) ), array( array(), true ) );

fresh_shop();
listing( $RELIST, 'Active', 2, 1 );
TCGiant_Sync_Importer::queue_settle( $RELIST );
run_next();
check( 'a listing no product follows, read, not a relist: left alone, nothing queued', array( waiting(), $LOG ), array( array(), array() ) );

echo "\nTHE HOOK TAKES THE TRY; A 3.27 CHECK IS THE FIRST\n" . str_repeat( '=', 124 ) . "\n";

check( 'Action Scheduler is given the try as the second argument', array( is_array( $HOOKS['tcgiant_sync_settle_item'][0][0] ?? null ) ? $HOOKS['tcgiant_sync_settle_item'][0][0][1] : null, $HOOKS['tcgiant_sync_settle_item'][0][1] ?? null ), array( 'settle_queued', 2 ) );
fresh_shop();
product( 412, 'COIN-412', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, timeout() );
$QUEUE[] = array( 'hook' => 'tcgiant_sync_settle_item', 'args' => array( $ITEM ), 'group' => 'tcgiant_sync_settle', 'when' => now(), 'status' => 'pending' );
run_next();
check( 'a check queued by 3.27, with no try: try 1, so try 2 next', array_column( waiting(), 0 ), array( array( $ITEM, 2 ) ) );
check( '  in the stock checks\' own group', $QUEUE[1]['group'] ?? null, 'tcgiant_sync_settle' );
fresh_shop();
product( 413, 'COIN-413', 3, $ITEM );
listing( $ITEM, 'Active', 3, 1 );
fails( $ITEM, timeout() );
$IMP->settle_item( $ITEM, 'Ended listing check' );
check( 'called directly, from the ended-listing check: retried as a sale check', array( array_column( waiting(), 0 ), logged( 'Ended listing check: could not read eBay item 140000000001 for WC #413' ) ), array( array( array( $ITEM, 2 ) ), true ) );
run_next();
check( '  which settles it', stock( 413 ), 2 );
// Without Action Scheduler a check cannot be queued again, and does not say one waits.
$imp_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-importer.php' );
$no_as   = strpos( $imp_src, "if ( ! function_exists( 'as_schedule_single_action' ) ) {" );
check( 'no Action Scheduler: said so, before any word of a check waiting', false !== $no_as && $no_as < strpos( $imp_src, 'A check of it is already waiting.' ) && false !== strpos( $imp_src, 'and without Action Scheduler it cannot be tried again by itself.' ), true );

echo "\nSTOCK REVIEW SAYS WHY EACH LISTING IS THERE, AND WHAT HAPPENS NEXT\n" . str_repeat( '=', 124 ) . "\n";

// The real Stock Review page, with nothing else on it to review.
class Render_Wpdb extends Harness_Wpdb {
	public function get_col( $query ) { return array(); }
	public function get_results( $query, $output = null ) { return array(); }
}
class TCGiant_Sync_Admin {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function render_tabs( $tab ) {}
}
define( 'ARRAY_A', 'ARRAY_A' );
function get_transient( $name ) { return 'tcgiant_restocked_after_sale' === $name ? array() : ( $GLOBALS['TRANSIENTS'][ $name ] ?? false ); }
function set_transient( $name, $value, $expiration = 0 ) { $GLOBALS['TRANSIENTS'][ $name ] = $value; return true; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $url ) { return esc_html( $url ); }
function esc_js( $text ) { return addslashes( (string) $text ); }
function esc_html__( $text ) { return esc_html( $text ); }
function esc_attr__( $text ) { return esc_html( $text ); }
function esc_html_e( $text ) { echo esc_html( $text ); }
function _n( $one, $many, $n ) { return 1 === (int) $n ? $one : $many; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function admin_url( $path = '' ) { return 'https://shop.example/wp-admin/' . $path; }
function wp_create_nonce( $action = '' ) { return 'nonce'; }
function wp_nonce_field( $action = '' ) { echo '<input type="hidden" name="_wpnonce" value="nonce" />'; }
function get_edit_post_link( $id ) { return 'https://shop.example/wp-admin/post.php?post=' . (int) $id; }
function wp_date( $format, $ts = null ) { return gmdate( 'Y-m-d H:i', (int) $ts ); }
function wp_kses_post( $html ) { return $html; }
function paginate_links( $args = array() ) { return ''; }
function human_time_diff( $from, $to = 0 ) { return '1 hour'; }

fresh_shop();
product( 420, 'COIN-420', 1, '150000000001' );
product( 421, 'COIN-421', 1, '150000000006' );
$hold = new ReflectionMethod( 'TCGiant_Sync_Importer', 'hold_back_listing' );
$hold->setAccessible( true );
// Moved past by 3.27: no reason kept.
$OPTIONS[ TCGiant_Sync_Importer::DELTA_HELD_OPTION ] = array( '150000000004' => array( 'item_id' => '150000000004', 'from' => '2026-10-01T00:00:00.000Z', 'at' => time() - DAY_IN_SECONDS ) );
$hold->invoke( null, '150000000001', '2026-10-20T00:00:00.000Z', TCGiant_Sync_Importer::HELD_KILLED );
$hold->invoke( null, '150000000002', '2026-10-20T00:15:00.000Z', TCGiant_Sync_Importer::HELD_UNREAD );
$hold->invoke( null, '150000000002', '', TCGiant_Sync_Importer::HELD_SALE_CHECK );         // its check gave up too
$hold->invoke( null, '150000000003', '', TCGiant_Sync_Importer::HELD_SALE_CHECK );
$hold->invoke( null, '150000000005', '', TCGiant_Sync_Importer::HELD_NO_CALLS );
$hold->invoke( null, '150000000007', '', TCGiant_Sync_Importer::HELD_SALE_CHECK, array( 'product' => 421, 'parent' => '150000000006' ) );   // a relist

$keep_db = $wpdb;
$wpdb    = new Render_Wpdb();
ob_start();
require $ROOT . '/admin/views/stock-review.php';
$html = ob_get_clean();
$wpdb = $keep_db;

/** The table row for one listing, as text. */
function row_for( $html, $item_id ) {
	foreach ( (array) preg_split( '#</tr>#', $html ) as $row ) {
		if ( false !== strpos( $row, '<td>' . $item_id . '</td>' ) ) {
			return trim( preg_replace( '/\s+/', ' ', strip_tags( $row ) ) );
		}
	}
	return '';
}
$why = function ( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); };
$at  = function ( $id ) { return wp_date( '', $GLOBALS['OPTIONS'][ TCGiant_Sync_Importer::DELTA_HELD_OPTION ][ $id ]['at'] ); };

check( 'the heading is true of every row: listings to check by hand', false !== strpos( $html, '6 eBay listings to check by hand.' ), true );
check( '  a line for those the sync moved past whose own checks are still to come', false !== strpos( $html, $why( 'The sync moved past 2 of them so it could carry on with the rest. Each was given a stock check of its own, which lowers the stock here when eBay sells it.' ) ), true );
check( '  one for the sale checks that could not read eBay - with the one moved past whose own check gave up too', false !== strpos( $html, $why( 'For 3, the stock check after an eBay sale could not read eBay, so their stock here is unchanged.' ) ), true );
check( '  one for those the day\'s calls ran out on', false !== strpos( $html, $why( 'For 1, the day\'s eBay calls ran out before the stock check after an eBay sale could read it, so its stock here is unchanged.' ) ), true );
check( '  and what to do', false !== strpos( $html, $why( 'Check each listing on eBay, and its stock here.' ) ), true );
check( '  no longer that every one stopped the sync, nor "takes a sale off your shop"', array( false !== strpos( $html, 'stopped the sync every time they were imported' ), false !== strpos( $html, 'takes a sale off your shop' ) ), array( false, false ) );
check( '  the columns: eBay item, Product, Why, Since', false !== strpos( $html, '<th>eBay item</th><th>Product</th><th>Why</th><th>Since</th>' ), true );
check( 'read three runs in a row, its check giving up too: both said', row_for( $html, '150000000002' ), '150000000002 Not imported ' . $why( 'eBay could not be read for it, three runs in a row, while it answered other calls. Its stock is checked on its own. Its stock check after an eBay sale could not read it either.' ) . ' ' . $at( '150000000002' ) );
check( '  "three runs" is the delta\'s own rule', TCGiant_Sync_Importer::DELTA_READ_STOPS, 3 );
check( 'a sale check that gave up: its stock unchanged, tried for up to three days', false !== strpos( row_for( $html, '150000000003' ), $why( 'Its stock check after an eBay sale could not read it from eBay, though it tried for up to three days. Its stock here is unchanged.' ) ), true );
check( '  "three days" is the check\'s own rule', TCGiant_Sync_Importer::SETTLE_CHAIN_DAYS, 3 );
check( '  no "five tries" any more', false === strpos( $html, 'five tries' ), true );
check( 'the day\'s calls ran out: worded on its own', false !== strpos( row_for( $html, '150000000005' ), $why( 'The day\'s eBay calls ran out before its stock check after an eBay sale could read it, three days running. Its stock here is unchanged.' ) ), true );
check( '  "three days" is the check\'s own rule', TCGiant_Sync_Importer::SETTLE_OUT_DAYS, 3 );
check( 'a relist no product follows: the product of the listing it was relisted from', false !== strpos( row_for( $html, '150000000007' ), '150000000007 #421 follows 150000000006, which this was relisted from' ), true );
check( '  and it was that listing which could not be read', false !== strpos( row_for( $html, '150000000007' ), $why( 'Its stock check after an eBay sale could not read the listing it was relisted from, though it tried for up to three days. Its stock here is unchanged.' ) ), true );
check( 'one that stopped the sync: says so, with its product', row_for( $html, '150000000001' ), '150000000001 #420 ' . $why( 'It stopped the sync every time it was imported. Its other details may be out of date.' ) . ' ' . $at( '150000000001' ) );
check( 'one moved past by 3.27: the log says why', false !== strpos( row_for( $html, '150000000004' ), 'The Activity Log from that day says why.' ), true );
$pos    = array_map( function ( $id ) use ( $html ) { return strpos( $html, '<td>' . $id . '</td>' ); }, array( '150000000007', '150000000005', '150000000003', '150000000002', '150000000001', '150000000004' ) );
$sorted = $pos;
sort( $sorted );
check( '  newest first, as before', ! in_array( false, $pos, true ) && $pos === $sorted, true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
