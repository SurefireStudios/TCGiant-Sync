<?php
/**
 * Harness: removing a product whose eBay listing sold out.
 *
 * A shop with over fifteen thousand products wrote in: "Items are not being
 * deleted after being sold, my last 10 items did not delete."
 *
 * The cause was structural rather than a broken condition. Removal had exactly
 * ONE call site - inside the delta sync's ended-listing branch - while THREE
 * paths notice that a listing has ended: that branch, the hourly ended-listing
 * check, and the Stock Review settle. The other two set the stock to zero and
 * left the product in the catalogue, because they had no way to remove it.
 *
 * So whichever path saw the ending first decided whether the product was ever
 * removed, and on a large catalogue the delta sync is the slowest of the three:
 * it handles forty changed items per run. Worse, the hourly check's query
 * excludes anything already marked Ended - so a product gets one pass, ever. If
 * the wrong path won that race, nothing would ever reconsider it.
 *
 * Every refusal was also a bare `return false`, and the line that WAS logged on
 * the way past reads like success, which is why this had to be diagnosed from
 * the source rather than from the merchant's log.
 *
 * Its last part loads the real removal rule, bulk push, Link, Unlink, Clear
 * and the record of a push that threw. 3.27.4 made a push say what it is doing - queued, pushing, failed -
 * where it used to say "pushed" from the first push on, and "pushed" was all
 * the rule asked about.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-68s got %-16s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$root      = dirname( __DIR__ );
$inventory = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-inventory.php' );
$importer  = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-importer.php' );
$cron      = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-cron.php' );
$jobs      = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-jobs.php' );
$settings  = (string) file_get_contents( $root . '/admin/views/settings.php' );
$review    = (string) file_get_contents( $root . '/admin/views/stock-review.php' );

echo "\nEVERY PATH THAT SEES A LISTING END CAN NOW ACT ON IT\n" . str_repeat( '=', 114 ) . "\n";

// The whole bug in one assertion: one decision, one caller, three observers.
$calls = substr_count( $importer, 'TCGiant_Sync_Inventory::maybe_remove_sold_out(' )
	+ substr_count( $cron, 'TCGiant_Sync_Inventory::maybe_remove_sold_out(' )
	+ substr_count( $jobs, 'TCGiant_Sync_Inventory::maybe_remove_sold_out(' );

check( 'removal is reachable from four places, not one', $calls, 4 );
check( '  the delta sync', false !== strpos( $importer, 'TCGiant_Sync_Inventory::maybe_remove_sold_out( (int) $existing_id, $listed, $sold )' ), true );
check( '  the hourly check, both its branches', substr_count( $cron, 'TCGiant_Sync_Inventory::maybe_remove_sold_out(' ), 2 );
check( '  and the Stock Review settle', false !== strpos( $jobs, 'TCGiant_Sync_Inventory::maybe_remove_sold_out(' ), true );

// One implementation, so the rules cannot drift between the four.
check( 'there is one implementation', substr_count( $inventory, 'public static function maybe_remove_sold_out(' ), 1 );
check( '  and the importer no longer keeps its own', false === strpos( $importer, 'function maybe_remove_sold_out_product' ), true );

// It lives beside the function that means "this listing is over".
check( 'it sits with the stock settler it belongs to', false !== strpos( $inventory, 'public static function apply_ended_listing_stock' ), true );

echo "\nAND SAYS WHY WHEN IT KEEPS ONE\n" . str_repeat( '=', 114 ) . "\n";

// Six bare returns and no log is why neither the merchant nor we could tell
// which condition had fired.
check( 'the setting being off is said out loud', false !== strpos( $inventory, 'the "Remove sold products" setting is off' ), true );
check( 'a pushed product says it is the shop\'s own', false !== strpos( $inventory, 'pushed to eBay from WooCommerce' ), true );
check( 'a preserved category says so', false !== strpos( $inventory, 'it is in a category set to be preserved' ), true );
check( 'the reason reaches the activity log', false !== strpos( $inventory, "'WC #%d sold out on eBay but was kept: %s.'" ), true );

// A shop whose whole catalogue is exempt would otherwise get a line per sale
// per path, on a catalogue of fifteen thousand.
check( '  once per product, not once per pass', false !== strpos( $inventory, "if ( \$because === get_post_meta( \$product_id, \$key, true ) ) {" ), true );

echo "\nTHE ARITHMETIC IS UNCHANGED\n" . str_repeat( '=', 114 ) . "\n";

/** Verbatim replica of the sold-out test. */
function sold_out( $listed, $sold ) {
	if ( null === $listed || null === $sold ) {
		return false;
	}

	return ! ( (int) $listed < 1 || (int) $sold < (int) $listed );
}

check( 'one listed, one sold is sold out', sold_out( 1, 1 ), true );
check( 'ten listed, ten sold is sold out', sold_out( 10, 10 ), true );
check( 'ten listed, nine sold is not', sold_out( 10, 9 ), false );
check( 'ended unsold is not', sold_out( 1, 0 ), false );
check( 'more sold than listed still counts', sold_out( 1, 5 ), true );

// Ended is not sold. A listing the seller pulled leaves goods the shop owns,
// and removing the product would contradict the stock just written.
check( 'nothing eBay would not answer for', sold_out( null, null ), false );
check( '  nor a half answer', sold_out( 1, null ), false );
check( 'a listing of nothing is not a sale', sold_out( 0, 0 ), false );

echo "\nTHE BACKLOG IS SHOWN, NOT SILENTLY TRASHED\n" . str_repeat( '=', 114 ) . "\n";

// Fixing the forward path does nothing for products already settled and left:
// the hourly query excludes anything marked Ended, so they get no second pass.
check( 'there is a finder for them', false !== strpos( $inventory, 'public static function find_sold_out_not_removed(' ), true );

// Found without asking eBay again: the settle recorded the judgement, and eBay
// drops old listings, which a backlog is made of.
check( '  it reads the settle mark', false !== strpos( $inventory, "settled.meta_key = '_ebay_stock_settled_at'" ), true );
check( '  and only out-of-stock ended products', false !== strpos( $inventory, "stock.meta_value = 'outofstock'" ), true );
check( '  never the trash', false !== strpos( $inventory, "p.post_status NOT IN ( 'trash', 'auto-draft' )" ), true );

check( 'the guards apply to the backlog too', false !== strpos( $inventory, 'public static function remove_if_allowed(' ), true );
check( '  which is what the arithmetic path calls', false !== strpos( $inventory, 'return self::remove_if_allowed( $product_id );' ), true );

check( 'it is a job the shop starts', false !== strpos( $jobs, "case 'bulk_remove_sold':" ), true );
check( '  an accepted type', false !== strpos( $jobs, "'bulk_set_format', 'bulk_remove_sold'," ), true );
check( '  resolved server-side for select-all', false !== strpos( $jobs, "TCGiant_Sync_Inventory::find_sold_out_not_removed();" ), true );

// Kept is neither a success nor a failure, and inflating either would misreport
// a run on a catalogue that is largely exempt.
check( 'products kept are counted separately', false !== strpos( $jobs, "'kept'      => \$kept," ), true );
check( '  and initialised, unlike the counter it replaces', false !== strpos( $jobs, "\$kept        = \$job['kept'] ?? 0;" ), true );

check( 'the shop is shown the list first', false !== strpos( $review, 'tc-remove-sold' ), true );
check( '  told the trash is not permanent', false !== strpos( $review, 'They go to the trash, not away for good' ), true );
check( '  and nothing runs on upgrade', false === strpos( $inventory, 'add_action' ) || false === strpos( $inventory, 'find_sold_out_not_removed();' ), true );

echo "\nTHE SETTINGS SCREEN AGREES WITH THE SCHEDULER\n" . str_repeat( '=', 114 ) . "\n";

// The screen said "Disabled" while the scheduler ran hourly, so a shop that had
// never touched the field and pressed Save switched off a sync that was going.
check( 'the scheduler still defaults to hourly', false !== strpos( $cron, "\$settings['sync_interval'] ?? 'tcgiant_hourly'" ), true );
// Comments stripped: the note explaining this very fault quotes the default it
// is about, and must not be allowed to answer for the markup.
$settings_code = php_strip_whitespace( $root . '/admin/views/settings.php' );

check( '  and so does the screen now, on all five options', substr_count( $settings_code, "\$settings['sync_interval'] ?? 'tcgiant_hourly'" ), 5 );
check( '  with none still claiming otherwise', substr_count( $settings_code, "\$settings['sync_interval'] ?? 'disabled'" ), 0 );
check( '  and the reason recorded', false !== strpos( $settings, 'switched off a sync that was running' ), true );

echo "\nA PRODUCT THE SHOP PUSHED IS KEPT, WHATEVER ITS LATEST PUSH IS DOING\n" . str_repeat( '=', 114 ) . "\n";

// The real removal rule, with the real bulk push, the real report of a push
// Action Scheduler gave up on, and the real Link writing what they write.
//
// 3.27.4 made a push say what it is doing: a bulk push marks each product
// queued, a push marks it pushing before it asks eBay, and one that dies is
// marked failed. A push used to say "pushed" from the first one on, and
// "pushed" was the only thing the removal rule asked about - so a product
// pushed months ago that sold out while queued for a re-push, or after the
// re-push died or was cancelled, was trashed.

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$DB      = array( 'meta' => array(), 'options' => array(), 'actions' => array(), 'titles' => array() );
$HOOKS   = array();
$TRASHED = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['HOOKS'][ $hook ][] = array( $callback, $accepted_args ); return true; }
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $h ) {
		call_user_func_array( $h[0], array_slice( $args, 0, (int) $h[1] ) );
	}
}
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['DB']['options'] ) ? $GLOBALS['DB']['options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['DB']['options'][ $name ] = $value; return true; }
function get_transient( $name ) { return false; }
function set_transient( $name, $value, $ttl = 0 ) { return true; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['DB']['meta'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['DB']['meta'][ (int) $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['DB']['meta'][ (int) $id ][ $key ] ); return true; }
function get_post_field( $field, $id ) { return $GLOBALS['DB']['titles'][ (int) $id ] ?? ''; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function has_term( $terms, $taxonomy, $id ) { return false; }
function wp_trash_post( $id ) { $GLOBALS['TRASHED'][] = (int) $id; return true; }
function __( $text, $domain = null ) { return $text; }
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) { return 1; }
function current_user_can( $capability ) { return true; }
function absint( $n ) { return abs( (int) $n ); }
function wp_unslash( $value ) { return $value; }
function get_post_type( $id ) { return isset( $GLOBALS['DB']['titles'][ (int) $id ] ) ? 'product' : false; }
function delete_transient( $name ) { return true; }
class Sold_Out_Json_Answer extends Exception {}
function wp_send_json_success( $data = null ) { throw new Sold_Out_Json_Answer( 'ok' ); }
function wp_send_json_error( $data = null ) { throw new Sold_Out_Json_Answer( (string) ( $data['message'] ?? 'error' ) ); }

/**
 * The one query the end of a batch asks of the database directly. Any other -
 * Link asking which other products hold the listing, Unlink clearing order
 * bookkeeping - finds nothing.
 */
class Sold_Out_wpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public function prepare( $query, ...$args ) { return $query; }
	public function esc_like( $text ) { return $text; }
	public function query( $sql ) { return 0; }
	public function delete( $table, $where, $format = null ) { return 0; }
	public function get_col( $sql ) {
		if ( ! preg_match( '/post_id IN \(([\d,\s]+)\)/', $sql, $m ) ) {
			return array();
		}
		$found = array();
		foreach ( array_map( 'intval', explode( ',', $m[1] ) ) as $id ) {
			if ( in_array( get_post_meta( $id, '_ebay_export_status' ), array( 'queued', 'pushing', 'failed' ), true ) ) {
				$found[] = $id;
			}
		}
		return $found;
	}
}
$wpdb = new Sold_Out_wpdb();

class WC_Product {
	private $id;
	private $meta;
	public function __construct( $id ) { $this->id = (int) $id; $this->meta = $GLOBALS['DB']['meta'][ $this->id ] ?? array(); }
	public function get_id() { return $this->id; }
	public function get_name() { return $GLOBALS['DB']['titles'][ $this->id ]; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function get_regular_price() { return '25.00'; }
	public function save() { $GLOBALS['DB']['meta'][ $this->id ] = $this->meta; return $this->id; }
	public function read_meta_data( $force = false ) { $this->meta = $GLOBALS['DB']['meta'][ $this->id ] ?? array(); }
}
function wc_get_product( $id ) { return isset( $GLOBALS['DB']['titles'][ (int) $id ] ) ? new WC_Product( $id ) : false; }

class ActionScheduler_Store {
	const STATUS_PENDING = 'pending';
	const STATUS_RUNNING = 'in-progress';
	public function fetch_action( $id ) { return new Sold_Out_Action( $GLOBALS['DB']['actions'][ (int) $id ] ); }
}
class ActionScheduler {
	public static function store() { return new ActionScheduler_Store(); }
}
class Sold_Out_Action {
	private $a;
	public function __construct( array $a ) { $this->a = $a; }
	public function get_hook() { return $this->a['hook']; }
	public function get_args() { return $this->a['args']; }
}
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
	$id = count( $GLOBALS['DB']['actions'] ) + 1;
	$GLOBALS['DB']['actions'][ $id ] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'status' => 'pending' );
	return $id;
}
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { return as_schedule_single_action( time(), $hook, $args, $group ); }
function as_unschedule_all_actions( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['DB']['actions'] as $id => $a ) {
		if ( $a['hook'] === $hook && 'pending' === $a['status'] ) {
			$GLOBALS['DB']['actions'][ $id ]['status'] = 'canceled';
		}
	}
}
function as_get_scheduled_actions( $query = array(), $return_format = 'OBJECT' ) {
	$found = array();
	foreach ( $GLOBALS['DB']['actions'] as $id => $a ) {
		if ( $a['hook'] === ( $query['hook'] ?? $a['hook'] ) && $a['status'] === ( $query['status'] ?? $a['status'] ) ) {
			$found[ $id ] = new Sold_Out_Action( $a );
		}
	}
	return $found;
}

class TCGiant_Sync_Logger {
	public static $lines = array();
	public static function log( $message, $level = 'info' ) { self::$lines[] = (string) $message; }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
}
class TCGiant_Sync_Importer {
	const GROUP_SCAN = 'tcgiant_sync_group';
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return true; }
	public static function table_exists() { return false; }
}
/** eBay, as Link asks it: the listing is there, and it is the shop's. */
class TCGiant_Sync_API {
	public static function instance() { return new self(); }
	public function get_item( $item_id ) {
		return array( 'Item' => array( 'Title' => 'Listing ' . $item_id, 'Seller' => array( 'UserID' => 'the-shop' ), 'SellingStatus' => array( 'ListingStatus' => 'Active', 'QuantitySold' => 1 ), 'Quantity' => 1 ) );
	}
	public function get_seller_user_id() { return 'the-shop'; }
}

/** The shop's settings: none, so a push is refused before it reaches eBay. */
class TCGiant_Sync_OAuth {
	public static function instance() { return new self(); }
	public function get_settings() { return array(); }
}

// A live shop, by wp-config, for TCGiant_Sync_Environment (3.28.0), which
// the push, Relist and auto-relist ask before anything goes to eBay.
// Staging copies are test-staging-detect.php's.
define( 'TCGIANT_SYNC_IS_STAGING', false );
require $root . '/includes/class-tcgiant-sync-environment.php';
require $root . '/includes/class-tcgiant-sync-catalog.php';
// Removal, the push and Link and Clear save the product, which turns on the
// save-loop breaker, which lives in the runtime.
require $root . '/includes/class-tcgiant-sync-runtime.php';
require $root . '/includes/class-tcgiant-sync-inventory.php';
// The push and Link read the listing's eBay site through it.
require $root . '/includes/class-tcgiant-sync-sites.php';
require $root . '/includes/class-tcgiant-sync-exporter.php';
require $root . '/admin/class-tcgiant-sync-admin.php';
require $root . '/includes/class-tcgiant-sync-listing-link.php';

$exporter = TCGiant_Sync_Exporter::instance();

/** A shop with these products, pushed earlier from WooCommerce where so. */
function sold_out_shop( array $products ) {
	$GLOBALS['DB'] = array( 'meta' => array(), 'options' => array(), 'actions' => array(), 'titles' => array() );
	foreach ( $products as $id => $pushed_before ) {
		$GLOBALS['DB']['titles'][ $id ] = 'Product ' . $id;
		if ( $pushed_before ) {
			update_post_meta( $id, '_ebay_item_id', '96007' . $id );
			update_post_meta( $id, '_ebay_export_status', 'pushed' );
			update_post_meta( $id, '_ebay_export_last_pushed', '2026-09-01 10:00:00' );
		}
	}
}
/** Its listing sells out: would "Remove sold products" trash it? */
function sells_out( $id ) {
	$GLOBALS['TRASHED'] = array();
	TCGiant_Sync_Inventory::remove_if_allowed( $id );
	return in_array( (int) $id, $GLOBALS['TRASHED'], true ) ? 'trashed' : 'kept';
}
/** The rule as it stood: kept only while the product says "pushed". */
function sells_out_before( $id ) {
	return 'pushed' === get_post_meta( $id, '_ebay_export_status', true ) ? 'kept' : 'trashed';
}
function status( $id ) { return get_post_meta( $id, '_ebay_export_status', true ); }

sold_out_shop( array( 5001 => true, 5002 => true ) );
$exporter->bulk_push_products( array( 5001, 5002 ) );
check( 'sells out while its bulk re-push is queued: kept', array( status( 5001 ), sells_out( 5001 ) ), array( 'queued', 'kept' ) );
check( '  where the rule as it stood trashed it', sells_out_before( 5001 ), 'trashed' );

sold_out_shop( array( 5003 => true ) );
$exporter->bulk_push_products( array( 5003 ) );
// The push begins - in progress, saying pushing - and the host kills it.
$DB['actions'][1]['status'] = 'in-progress';
update_post_meta( 5003, '_ebay_export_status', 'pushing' );
check( 'sells out while its re-push is under way: kept', sells_out( 5003 ), 'kept' );
// Action Scheduler gives up on it, and says so.
$DB['actions'][1]['status'] = 'failed';
do_action( 'action_scheduler_failed_action', 1, 300 );
check( 'sells out after its re-push died: kept', array( status( 5003 ), sells_out( 5003 ) ), array( 'failed', 'kept' ) );
check( '  where the rule as it stood trashed it', sells_out_before( 5003 ), 'trashed' );

sold_out_shop( array( 5005 => true, 5006 => false ) );
$exporter->bulk_push_products( array( 5005 ) );
$exporter->bulk_push_products( array( 5006 ) ); // a newer bulk push cancels the pending one
check( 'its pending push cancelled by a newer bulk push', array( $DB['actions'][1]['status'], status( 5005 ) ), array( 'canceled', 'queued' ) );
check( '  sells out later: kept', sells_out( 5005 ), 'kept' );
check( '  where the rule as it stood trashed it', sells_out_before( 5005 ), 'trashed' );

// Imported and linked, never pushed before: its push from its own screen may
// be making the listing that sells - relisting one that had ended - so it is
// kept while that push is under way, or did not finish.
sold_out_shop( array( 5007 => false ) );
update_post_meta( 5007, '_ebay_item_id', '960075007' );
$exporter->push_product( 5007 );
check( 'an imported product queued from its own screen is still removable', array( status( 5007 ), sells_out( 5007 ) ), array( 'queued', 'trashed' ) );
$DB['actions'][1]['status'] = 'in-progress';
update_post_meta( 5007, '_ebay_export_status', 'pushing' );
check( '  while its push is under way: kept', sells_out( 5007 ), 'kept' );
$DB['actions'][1]['status'] = 'failed';
do_action( 'action_scheduler_failed_action', 1, 300 );
check( '  once that push did not finish: kept', array( status( 5007 ), sells_out( 5007 ) ), array( 'failed', 'kept' ) );

// Gadget Central's case: the first push died after eBay had made the
// listing, before the product was given its number, and the shop did as it
// was told and linked it.
sold_out_shop( array( 5004 => false ) );
update_post_meta( 5004, '_ebay_export_status', 'failed' );
$_POST = array( 'product_id' => '5004', 'ebay_item_id' => '960069999999' );
try {
	( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor()->ajax_link_ebay();
} catch ( Sold_Out_Json_Answer $answer ) {
	$linked = $answer->getMessage();
}
check( 'the shop links the listing its dead push made: it says pushed', array( $linked ?? null, get_post_meta( 5004, '_ebay_item_id', true ), status( 5004 ) ), array( 'ok', '960069999999', 'pushed' ) );
check( '  dated as pushed: the link completes the push that made the listing', '' !== get_post_meta( 5004, '_ebay_export_last_pushed', true ), true );
check( '  that listing sells out: kept, as the shop\'s own product', sells_out( 5004 ), 'kept' );
$linked_db = $DB;
// Pushed again later. Only a push that finishes writes the date, and this
// product's first never did - so without the date from Link, what kept it
// was "pushed" alone, which the next push replaces.
$exporter->bulk_push_products( array( 5004 ) );
check( '  sells out while a later re-push is queued: kept', array( status( 5004 ), sells_out( 5004 ) ), array( 'queued', 'kept' ) );
// What process_single_push() leaves when eBay refuses the push, until the
// next one.
update_post_meta( 5004, '_ebay_export_status', 'error' );
update_post_meta( 5004, '_ebay_export_error', 'The item specific Type is missing.' );
check( '  sells out after eBay refused a later re-push: kept', array( status( 5004 ), sells_out( 5004 ) ), array( 'error', 'kept' ) );
$DB = $linked_db;
update_post_meta( 5004, '_ebay_export_status', '' );
delete_post_meta( 5004, '_ebay_export_last_pushed' );
check( '  where Link as it first was - blanked, undated - had it trashed', sells_out( 5004 ), 'trashed' );

// What was removable stays removable.
sold_out_shop( array( 5010 => false, 5011 => false ) );
check( 'an imported product, never pushed: trashed', sells_out( 5010 ), 'trashed' );
update_post_meta( 5011, '_ebay_export_status', 'queued' );
check( '  and one queued for a push from its own screen, as before', sells_out( 5011 ), 'trashed' );
update_post_meta( 5011, '_ebay_export_status', 'error' );
check( '  or whose first push eBay refused, as before', sells_out( 5011 ), 'trashed' );
check( 'every one kept says why, once', count( preg_grep( '/^WC #50\d\d sold out on eBay but was kept: this product was pushed to eBay from WooCommerce/', TCGiant_Sync_Logger::$lines ) ), 5 );

echo "\nAND STILL KEPT WHEN THE SHOP DOES WHAT A PUSH THAT DID NOT FINISH TELLS IT\n" . str_repeat( '=', 114 ) . "\n";

$admin = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();

/** A button on the product's eBay tab: its request, and the answer. */
function sold_out_ajax( $admin, $method, array $post ) {
	$_POST = $post;
	try {
		$admin->$method();
	} catch ( Sold_Out_Json_Answer $answer ) {
		return $answer->getMessage();
	}
	return null;
}

// Pushed from WooCommerce, linked to a listing that had ended; the bulk push
// relisting it died. Still linked to the old listing, it has no Link to use,
// so it is told to Unlink, then Link the new one - and Unlink took what the
// push said with the rest of the link, so the Link found nothing to say the
// new listing was the push's, and the product was trashed when it sold.
sold_out_shop( array( 5020 => true ) );
update_post_meta( 5020, '_ebay_listing_status', 'Ended' );
$exporter->bulk_push_products( array( 5020 ) );
$DB['actions'][1]['status'] = 'in-progress';
update_post_meta( 5020, '_ebay_export_status', 'pushing' );
$DB['actions'][1]['status'] = 'failed';
do_action( 'action_scheduler_failed_action', 1, 300 );
$told = get_post_meta( 5020, '_ebay_export_error', true );
check( 'its relist died: it is told to Unlink, then Link the new listing', false !== strpos( $told, 'Unlink this product and Link it to the new item number' ), true );
check( '  the shop unlinks it', sold_out_ajax( $admin, 'ajax_unlink_ebay', array( 'product_id' => '5020' ) ), 'ok' );
check( '  unlinked, it still says its push did not finish, in the same words', array( get_post_meta( 5020, '_ebay_item_id', true ), status( 5020 ), get_post_meta( 5020, '_ebay_export_error', true ) === $told, get_post_meta( 5020, '_ebay_export_last_pushed', true ) ), array( '', 'failed', true, '2026-09-01 10:00:00' ) );
check( '  then links the new listing: it says pushed', array( sold_out_ajax( $admin, 'ajax_link_ebay', array( 'product_id' => '5020', 'ebay_item_id' => '960070000222' ) ), get_post_meta( 5020, '_ebay_item_id', true ), status( 5020 ) ), array( 'ok', '960070000222', 'pushed' ) );
check( '  that listing sells out: kept', sells_out( 5020 ), 'kept' );

// The same, from the products list's warning before Action Scheduler has
// given up on the push: it still says pushing, and the time it began goes
// with the link.
sold_out_shop( array( 5021 => true ) );
update_post_meta( 5021, '_ebay_listing_status', 'Ended' );
update_post_meta( 5021, '_ebay_export_status', 'pushing' );
update_post_meta( 5021, '_ebay_export_pushing_since', time() - 20 * 60 );
sold_out_ajax( $admin, 'ajax_unlink_ebay', array( 'product_id' => '5021' ) );
check( 'unlinked while its dead push still says pushing: failed, not under way for good', array( status( 5021 ), get_post_meta( 5021, '_ebay_export_pushing_since', true ) ), array( 'failed', '' ) );
sold_out_ajax( $admin, 'ajax_link_ebay', array( 'product_id' => '5021', 'ebay_item_id' => '960070000223' ) );
check( '  then linked: pushed, and kept when it sells out', array( status( 5021 ), sells_out( 5021 ) ), array( 'pushed', 'kept' ) );

// Unlink means what it always did for a product whose push finished.
sold_out_shop( array( 5022 => true ) );
sold_out_ajax( $admin, 'ajax_unlink_ebay', array( 'product_id' => '5022' ) );
check( 'unlinking a product whose push finished still leaves it not yet listed', array( get_post_meta( 5022, '_ebay_item_id', true ), status( 5022 ), get_post_meta( 5022, '_ebay_export_last_pushed', true ) ), array( '', '', '' ) );

// Gadget Central's case again, with Clear pressed on the warning before Link.
// Clear blanked the status, so Link left it blank and the product was
// trashed when the listing its push had made sold out.
sold_out_shop( array( 5030 => false ) );
update_post_meta( 5030, '_ebay_export_status', 'failed' );
update_post_meta( 5030, '_ebay_export_error', 'The push of "Product 5030" (WC #5030) did not finish - your server stopped it. If the listing is on eBay, use Link on the product with its item number; pushing it again would list it twice.' );
check( 'Clear on a push that did not finish is refused', sold_out_ajax( $admin, 'ajax_clear_export_error', array( 'product_id' => '5030' ) ), 'This push did not finish, so its notice stays until the product is linked to its listing or a push of it finishes.' );
check( '  and it still says so', status( 5030 ), 'failed' );
sold_out_ajax( $admin, 'ajax_link_ebay', array( 'product_id' => '5030', 'ebay_item_id' => '960070000230' ) );
check( '  Link then marks it pushed: kept when that listing sells out', array( status( 5030 ), sells_out( 5030 ) ), array( 'pushed', 'kept' ) );

sold_out_shop( array( 5031 => false ) );
update_post_meta( 5031, '_ebay_export_status', 'pushing' );
update_post_meta( 5031, '_ebay_export_pushing_since', time() - 20 * 60 );
sold_out_ajax( $admin, 'ajax_clear_export_error', array( 'product_id' => '5031' ) );
check( 'nor is Clear on one still saying pushing long after', array( status( 5031 ), get_post_meta( 5031, '_ebay_export_pushing_since', true ) > 0 ), array( 'pushing', true ) );

// A push eBay refused is cleared as before, and anything left behind saying
// a push was under way goes with it.
sold_out_shop( array( 5032 => false ) );
update_post_meta( 5032, '_ebay_export_status', 'error' );
update_post_meta( 5032, '_ebay_export_error', 'The item specific Type is missing.' );
update_post_meta( 5032, '_ebay_export_pushing_since', time() - 60 );
check( 'Clear on a push eBay refused clears it, and the time a push began', array( sold_out_ajax( $admin, 'ajax_clear_export_error', array( 'product_id' => '5032' ) ), status( 5032 ), get_post_meta( 5032, '_ebay_export_error', true ), get_post_meta( 5032, '_ebay_export_pushing_since', true ) ), array( 'ok', '', '', '' ) );

// A push that threw once eBay had made the listing - a save hook throwing,
// say - is told the same: Link the new listing, Unlinking first from the
// ended one it replaced. It was marked as an error, as if eBay had refused
// it, so Unlink dropped it, Link left it, and Clear was offered on it: doing
// as it said left the product looking imported. The real record of the throw
// writes what it writes here.
$record_throw = new ReflectionMethod( 'TCGiant_Sync_Exporter', 'record_push_exception' );
$in_flight    = new ReflectionProperty( 'TCGiant_Sync_Exporter', 'in_flight' );
if ( PHP_VERSION_ID < 80100 ) {
	$record_throw->setAccessible( true );
	$in_flight->setAccessible( true );
}
/** The product's push throws - after eBay made listing $created, if it did. */
function push_throws( $id, $created ) {
	global $record_throw, $in_flight;
	update_post_meta( $id, '_ebay_export_status', 'pushing' );
	$in_flight->setValue( null, array(
		'product_id' => $id,
		'title'      => 'Product ' . $id,
		'linked'     => (string) get_post_meta( $id, '_ebay_item_id', true ),
		'ended'      => TCGiant_Sync_Exporter::linked_listing_ended( $id ),
		'created'    => $created,
	) );
	$made = $record_throw->invoke( null, sprintf( 'Export exception for "Product %1$d" (WC #%1$d): Deadlock found when trying to get lock', $id ) );
	$in_flight->setValue( null, null );
	return $made;
}

sold_out_shop( array( 5040 => true ) );
update_post_meta( 5040, '_ebay_listing_status', 'Ended' );
check( 'a relist that threw once eBay made the new listing did not finish', array( push_throws( 5040, '960070000240' ), status( 5040 ) ), array( true, 'failed' ) );
$told = get_post_meta( 5040, '_ebay_export_error', true );
check( '  it is told to Unlink, then Link the new listing', false !== strpos( $told, 'Unlink it from listing 960075040, then use Link with item number 960070000240' ), true );
sold_out_ajax( $admin, 'ajax_unlink_ebay', array( 'product_id' => '5040' ) );
check( '  unlinked, it still says so, and when it was last pushed', array( status( 5040 ), get_post_meta( 5040, '_ebay_export_error', true ) === $told, get_post_meta( 5040, '_ebay_export_last_pushed', true ) ), array( 'failed', true, '2026-09-01 10:00:00' ) );
sold_out_ajax( $admin, 'ajax_link_ebay', array( 'product_id' => '5040', 'ebay_item_id' => '960070000240' ) );
check( '  then linked to the new listing: pushed, kept when it sells out', array( status( 5040 ), sells_out( 5040 ) ), array( 'pushed', 'kept' ) );

sold_out_shop( array( 5041 => false ) );
check( 'a first push that threw once eBay made the listing did not finish', array( push_throws( 5041, '960070000241' ), status( 5041 ) ), array( true, 'failed' ) );
check( '  Clear is refused on it', sold_out_ajax( $admin, 'ajax_clear_export_error', array( 'product_id' => '5041' ) ), 'This push did not finish, so its notice stays until the product is linked to its listing or a push of it finishes.' );
sold_out_ajax( $admin, 'ajax_link_ebay', array( 'product_id' => '5041', 'ebay_item_id' => '960070000241' ) );
check( '  Link, as it says: pushed, dated, kept when that listing sells out', array( status( 5041 ), '' !== get_post_meta( 5041, '_ebay_export_last_pushed', true ), sells_out( 5041 ) ), array( 'pushed', true, 'kept' ) );

// Nothing made on eBay: an error, as it was, and the product as removable.
sold_out_shop( array( 5042 => false ) );
check( 'one that threw before eBay made a listing is an error, removable as before', array( push_throws( 5042, '' ), status( 5042 ), sells_out( 5042 ) ), array( false, 'error', 'trashed' ) );


echo "\nA PUSH THAT DID NOT FINISH STAYS SAYING SO UNTIL ONE FINISHES\n" . str_repeat( '=', 114 ) . "\n";

// Pushing again is what the notice says not to do - but the button is there,
// and so is the products list's bulk push. A push queued after one that did
// not finish wiped what that one said: queuing wrote queued over it, and a
// push refused before it reached eBay - settings, no photo, no stock - wrote
// error. Clear was then offered, Link left the product as it was, and the
// product was trashed as an imported one when the listing the dead push had
// made sold out. A queued push any later bulk push cancelled left it saying
// queued for good, with no warning and none of the protections.

/** The product's first push died after eBay made listing $listing. */
function died_after_listing( $id, $listing ) {
	sold_out_shop( array( $id => false ) );
	update_post_meta( $id, '_ebay_export_status', 'failed' );
	update_post_meta( $id, '_ebay_export_error', sprintf( 'The push of "Product %1$d" (WC #%1$d) did not finish - your server stopped it after eBay created listing %2$s. Use Link on the product with that item number; pushing it again would list it twice.', $id, $listing ) );
	return get_post_meta( $id, '_ebay_export_error', true );
}

$said = died_after_listing( 5050, '960070000250' );
check( 'Push on its screen, refused before eBay: answered', 0 === strpos( (string) sold_out_ajax( $admin, 'ajax_push_product', array( 'product_id' => '5050' ) ), 'Cannot push to eBay' ), true );
check( '  it still says its push did not finish, in the same words', array( status( 5050 ), get_post_meta( 5050, '_ebay_export_error', true ) === $said ), array( 'failed', true ) );
check( '  Clear is still refused', sold_out_ajax( $admin, 'ajax_clear_export_error', array( 'product_id' => '5050' ) ), 'This push did not finish, so its notice stays until the product is linked to its listing or a push of it finishes.' );
sold_out_ajax( $admin, 'ajax_link_ebay', array( 'product_id' => '5050', 'ebay_item_id' => '960070000250' ) );
check( '  Link then completes it: kept when that listing sells out', array( status( 5050 ), sells_out( 5050 ) ), array( 'pushed', 'kept' ) );

$said = died_after_listing( 5051, '960070000251' );
$exporter->push_product( 5051 );
check( 'queued again from its screen: still says so while queued', array( status( 5051 ), get_post_meta( 5051, '_ebay_export_error', true ) === $said, TCGiant_Sync_Exporter::push_progress( 5051 ) ), array( 'failed', true, 'failed' ) );
TCGiant_Sync_Logger::$lines = array();
$exporter->process_single_push( 5051 );
check( '  that push refused before eBay: still says so, in the same words, then why it was refused', array( status( 5051 ), 0 === strpos( get_post_meta( 5051, '_ebay_export_error', true ), $said . ' The push after it was refused: Cannot push to eBay ' ) ), array( 'failed', true ) );
check( '  and the log says why it was refused, and that it still says so', count( preg_grep( '/^Export failed for "Product 5051" \(WC #5051\): Cannot push to eBay .* Its earlier push did not finish, and it still says so/', TCGiant_Sync_Logger::$lines ) ), 1 );
sold_out_ajax( $admin, 'ajax_link_ebay', array( 'product_id' => '5051', 'ebay_item_id' => '960070000251' ) );
check( '  Link then completes it: kept when that listing sells out', array( status( 5051 ), sells_out( 5051 ) ), array( 'pushed', 'kept' ) );

$said = died_after_listing( 5052, '960070000252' );
$DB['titles'][5053] = 'Product 5053';
$exporter->push_product( 5052 );
$exporter->bulk_push_products( array( 5053 ) ); // cancels every push still waiting
check( 'its push cancelled by a later bulk push: still says so', array( $DB['actions'][1]['status'], status( 5052 ), get_post_meta( 5052, '_ebay_export_error', true ) === $said, TCGiant_Sync_Exporter::push_progress( 5052 ) ), array( 'canceled', 'failed', true, 'failed' ) );
sold_out_ajax( $admin, 'ajax_link_ebay', array( 'product_id' => '5052', 'ebay_item_id' => '960070000252' ) );
check( '  Link then completes it: kept when that listing sells out', array( status( 5052 ), sells_out( 5052 ) ), array( 'pushed', 'kept' ) );

$said = died_after_listing( 5054, '960070000254' );
$exporter->bulk_push_products( array( 5054 ) );
check( 'in a bulk push: still says so while queued', array( status( 5054 ), get_post_meta( 5054, '_ebay_export_error', true ) === $said ), array( 'failed', true ) );

// A push that throws before eBay makes anything, after one that did not finish.
died_after_listing( 5055, '960070000255' );
$said = get_post_meta( 5055, '_ebay_export_error', true );
$in_flight->setValue( null, array( 'product_id' => 5055, 'title' => 'Product 5055', 'linked' => '', 'ended' => false, 'created' => '', 'unfinished' => $said ) );
update_post_meta( 5055, '_ebay_export_status', 'pushing' );
check( 'a push that throws before eBay, after one that did not finish: still says so, then why it stopped', array( $record_throw->invoke( null, 'Export exception for "Product 5055" (WC #5055): Deadlock found when trying to get lock' ), status( 5055 ), get_post_meta( 5055, '_ebay_export_error', true ) ), array( true, 'failed', $said . ' The push after it was refused: Export exception for "Product 5055" (WC #5055): Deadlock found when trying to get lock' ) );
$in_flight->setValue( null, null );

// A push that finishes is what ends it, as before: test-push-visibility.php
// runs one.

// And an ordinary refusal is an error, as before.
sold_out_shop( array( 5057 => false ) );
sold_out_ajax( $admin, 'ajax_push_product', array( 'product_id' => '5057' ) );
check( 'a first push refused before eBay: an error, removable as before', array( status( 5057 ), sells_out( 5057 ) ), array( 'error', 'trashed' ) );
$exporter->push_product( 5057 );
$exporter->process_single_push( 5057 );
check( '  and refused in the background: an error', status( 5057 ), 'error' );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
