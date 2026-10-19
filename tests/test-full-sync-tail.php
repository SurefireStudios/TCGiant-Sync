<?php
/**
 * Harness: the end of a full sync, and what the buttons and the hourly sync
 * do to one still running.
 *
 * Wizify's imports stopped whenever "Pending Jobs" reached 0, and pressing
 * Fetch Inventory every half hour started them again; the imported count
 * froze. Three things together:
 *
 * - The first GetItem import to finish after the scan marked the whole sync
 *   complete. check_sync_completion() compared every listing imported, the
 *   ones the scan imported inline included, against the count of GetItem
 *   imports alone. The next hourly run then started a delta sync, which
 *   cancelled every import still queued.
 * - Resume Import cancelled the imports it said it was resuming.
 * - The lock was written once, when the sync began, and read as stale ten
 *   minutes in. Fetch Inventory, which is forced past the "already running"
 *   check, then broke it and started a healthy scan again from page 1,
 *   cancelling its imports. Pressed once the scan was done, while its
 *   imports were still queued, it cancelled them all the same.
 *
 * The final recheck found four more, fixed here too:
 *
 * - Complete only once its last import has run, the full sync pruned hours
 *   after its scan, and trashed a product pushed, relisted or linked to a
 *   new listing meanwhile: the scan's list of active listings never saw it.
 * - Fetch Inventory was refused for as long as the queued imports kept the
 *   sync's activity fresh, long after the host had killed the scan.
 * - The dashboard's poll completed the sync while its last import was
 *   running, so neither the delta sync's position nor the pruner followed.
 * - Resume Import read its page again and queued that page's imports twice.
 *
 * A full sync that queued no GetItem import is left as 3.27.4 had it: it
 * says importing until the dashboard's poll finds nothing queued, and never
 * moves the delta sync's position or queues the pruner. Pruning for shops
 * that have never had it waits for 3.28.0.
 *
 * Loaded for real: TCGiant_Sync_Importer and TCGiant_Sync_Runtime. WordPress,
 * Action Scheduler, eBay and the product save are played here.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-84s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 160 ) );
}

$ROOT    = dirname( __DIR__ );
$UPLOADS = str_replace( '\\', '/', sys_get_temp_dir() ) . '/tcg-full-sync-tail-' . getmypid();
if ( ! is_dir( $UPLOADS ) ) {
	mkdir( $UPLOADS, 0777, true );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// --- WordPress ---------------------------------------------------------------

$OPTIONS = array();
$CRON    = array();
$QUEUE   = array();
$LOG     = array();

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
function add_action() { return true; }
function remove_action() { return true; }
function add_filter() { return true; }
function remove_filter() { return true; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function add_option( $name, $value, $deprecated = '', $autoload = null ) { return update_option( $name, $value ); }
function delete_option( $name ) { unset( $GLOBALS['OPTIONS'][ $name ] ); return true; }
function delete_transient( $name ) { return true; }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['UPLOADS'] ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) { return gmdate( $format, strtotime( $date . ' UTC' ) ); }
function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['CRON'][] = $hook; return true; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['CRON'] = array_values( array_diff( $GLOBALS['CRON'], array( $hook ) ) ); }
function wp_next_scheduled( $hook ) { return in_array( $hook, $GLOBALS['CRON'], true ) ? time() : false; }
function get_transient( $name ) { return array( 'synced_products' => 0, 'pending_jobs' => 0 ); }
function apply_filters( $hook, $value ) { return $value; }
function has_term() { return false; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['PMETA'][ (int) $id ][ $key ] ?? ''; }
function wp_trash_post( $id ) { $GLOBALS['TRASHED'][] = (int) $id; return true; }
function check_ajax_referer( $action ) { return 1; }
function current_user_can( $capability ) { return true; }
class Harness_Json_Answer extends Exception {}
function wp_send_json_success( $data = null ) { throw new Harness_Json_Answer( 'sent' ); }
function wp_send_json_error( $data = null ) { throw new Harness_Json_Answer( 'error' ); }

class Harness_wpdb {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public $options  = 'wp_options';
	public function prepare( $query ) { return $query; }
	public function get_var( $query ) { return null; }
	public function get_row( $query ) { return null; }
	/** The pruner's question: every product linked to a listing, by item number. */
	public function get_results( $query ) {
		$rows = array();
		foreach ( $GLOBALS['LINKED'] as $product_id => $item_id ) {
			if ( ! in_array( $product_id, $GLOBALS['TRASHED'], true ) ) {
				$rows[] = (object) array( 'post_id' => $product_id, 'ebay_id' => $item_id );
			}
		}
		return $rows;
	}
}
$wpdb = new Harness_wpdb();

// --- Action Scheduler: what is in the queue is pending; a job it runs is
// taken out first, and is in progress while it runs -------------------------

class ActionScheduler_Store {
	const STATUS_PENDING = 'pending';
	const STATUS_RUNNING = 'in-progress';
}
$RUNNING = array();
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { $GLOBALS['QUEUE'][] = array( $hook, $args, $group ); return count( $GLOBALS['QUEUE'] ); }
function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) { return as_enqueue_async_action( $hook, $args, $group ); }
/** As Action Scheduler has it: matched on the hook, and on the args when given. */
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['QUEUE'] as $action ) {
		if ( $action[0] === $hook && ( null === $args || $action[1] == $args ) ) {
			return time();
		}
	}
	return false;
}
function as_get_scheduled_actions( $args, $return_format = 'objects' ) {
	if ( 'in-progress' === ( $args['status'] ?? '' ) ) {
		return array_keys( array_intersect( $GLOBALS['RUNNING'], array( $args['hook'] ?? '' ) ) );
	}
	$found = array();
	foreach ( $GLOBALS['QUEUE'] as $i => $action ) {
		if ( $action[0] === ( $args['hook'] ?? '' ) && 'pending' === ( $args['status'] ?? 'pending' ) ) {
			$found[] = $i + 1;
		}
	}
	return $found;
}
function as_unschedule_all_actions( $hook, $args = null, $group = '' ) {
	$GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $action ) use ( $hook ) { return $action[0] !== $hook; } ) );
}
function count_queued( $hook ) {
	return count( array_filter( $GLOBALS['QUEUE'], function ( $action ) use ( $hook ) { return $action[0] === $hook; } ) );
}
/** Action Scheduler runs this item's import: claimed, then run. */
function run_import( $item_id ) {
	foreach ( $GLOBALS['QUEUE'] as $i => $action ) {
		if ( 'tcgiant_sync_process_item_import' === $action[0] && (string) $action[1]['item_id'] === (string) $item_id ) {
			unset( $GLOBALS['QUEUE'][ $i ] );
		}
	}
	$GLOBALS['QUEUE'] = array_values( $GLOBALS['QUEUE'] );
	TCGiant_Sync_Importer::instance()->process_item_import( $item_id );
}

// --- The plugin's other classes, as far as these paths go ----------------------

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = $message; }
	public static function error( $message ) { $GLOBALS['LOG'][] = $message; }
	public static function warning( $message ) { $GLOBALS['LOG'][] = $message; }
	public static function write_now( $message, $level = 'error' ) { $GLOBALS['LOG'][] = $message; }
}
class TCGiant_Sync_OAuth {
	public static function instance() { return new self(); }
	public function get_settings() { return array(); }
}
class TCGiant_Sync_Entitlements {
	public static function instance() { return new self(); }
	public function can_import() { return true; }
	public function get_active_product_count() { return 0; }
	public function get_free_limit() { return 0; }
	public function get_status_for_ui() { return array(); }
}
class TCGiant_Sync_Image_Localizer {
	public static function set_external_images() {}
}
class TCGiant_Sync_Cron {
	public static function request_dispatch() {}
}
/** The site filter, off: every listing is imported. */
class TCGiant_Sync_Sites {
	const SKIP_TRANSIENT = 'tcgiant_sync_site_skip_';
	public static function primary_only() { return false; }
	public static function site_of( $item ) { return ''; }
	public static function note( $site ) {}
	public static function after_full_scan() {}
	public static function record_site() {}
	public static function leaves_out( $site ) { return false; }
}
class TCGiant_Sync_Mapper {
	public static $last_skipped = false;
	public static function instance() { return new self(); }
	public function map_ebay_to_woo( $item ) { return array( 'id' => $item['ItemID'], 'price' => '1.00', 'stock_quantity' => 1, 'attributes' => array(), 'images' => array(), 'variations' => array() ); }
	public function save_as_product( &$data ) { $GLOBALS['SAVED'][] = (string) $data['id']; return 1000 + count( $GLOBALS['SAVED'] ); }
}
/**
 * eBay: $PAGES pages of $PER_PAGE listings. Every fourth listing comes without
 * its item specifics, so the scan queues a GetItem import for it; the rest are
 * imported inline. Each page read notes how old the lock was then.
 */
class TCGiant_Sync_API {
	const DAILY_CALL_LIMIT = 50000;
	public static function instance() { return new self(); }
	public function get_remaining_daily_budget() { return 40000; }
	public function get_item( $item_id ) { return array( 'Item' => array( 'ItemID' => $item_id, 'Title' => 'GetItem ' . $item_id, 'SellingStatus' => array( 'ListingStatus' => 'Active' ), 'ItemSpecifics' => array(), 'PictureDetails' => array( 'PictureURL' => 'u' ) ) ); }
	public function get_active_listings( $page, $per_page = 200, $mod_from = '', $end_from = '' ) {
		$GLOBALS['LOCK_AGE_AT_READ'][] = lock_age();
		if ( isset( $GLOBALS['ON_PAGE'] ) ) {
			call_user_func( $GLOBALS['ON_PAGE'], $page );
		}
		if ( $page > $GLOBALS['PAGES'] ) {
			return array( 'Ack' => 'Success' );
		}
		$items = array();
		for ( $i = 1; $i <= $GLOBALS['PER_PAGE']; $i++ ) {
			$item = array( 'ItemID' => sprintf( '%d%03d', $page, $i ), 'Title' => "p$page i$i", 'SellingStatus' => array( 'ListingStatus' => 'Active' ), 'PictureDetails' => array( 'PictureURL' => 'u' ) );
			if ( 0 !== $i % 4 ) {
				$item['ItemSpecifics'] = array();
			}
			$items[] = $item;
		}
		return array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $items ), 'PaginationResult' => array( 'TotalNumberOfPages' => $GLOBALS['PAGES'] ) );
	}
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-importer.php';
require $ROOT . '/admin/class-tcgiant-sync-admin.php';

$IMP = TCGiant_Sync_Importer::instance();

function lock_file() { return $GLOBALS['UPLOADS'] . '/tcgiant_sync.lock'; }
function lock_age() { return is_file( lock_file() ) ? time() - (int) file_get_contents( lock_file() ) : null; }
function age_lock( $seconds ) { file_put_contents( lock_file(), (string) ( time() - $seconds ) ); }
function state() { return TCGiant_Sync_Importer::get_sync_state(); }
function logged( $words ) { return count( preg_grep( '/' . preg_quote( $words, '/' ) . '/', $GLOBALS['LOG'] ) ); }

/** A site with eBay ready, nothing queued, no lock, and the last delta position a day old. */
function fresh_site( $pages, $per_page, array $state = array() ) {
	$GLOBALS['OPTIONS']          = array();
	$GLOBALS['CRON']             = array();
	$GLOBALS['QUEUE']            = array();
	$GLOBALS['LOG']              = array();
	$GLOBALS['SAVED']            = array();
	$GLOBALS['LOCK_AGE_AT_READ'] = array();
	$GLOBALS['LINKED']           = array();
	$GLOBALS['PMETA']            = array();
	$GLOBALS['TRASHED']          = array();
	$GLOBALS['RUNNING']          = array();
	$GLOBALS['PAGES']            = $pages;
	$GLOBALS['PER_PAGE']         = $per_page;
	if ( is_file( lock_file() ) ) {
		unlink( lock_file() );
	}
	update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'last_successful_sync_at' => '2026-10-01T00:00:00.000Z' ), $state ) );
}

/** The dashboard's status poll, for real: TCGiant_Sync_Admin::ajax_sync_status(). */
function dashboard_polls() {
	try {
		( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor()->ajax_sync_status();
	} catch ( Harness_Json_Answer $answer ) {
		unset( $answer );
	}
}

/** Fetch Inventory pressed, and Action Scheduler running the scan it queued. */
function fetch_and_scan() {
	$started = TCGiant_Sync_Importer::instance()->start_full_sync( true );
	as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages' ); // Claimed by the queue runner.
	TCGiant_Sync_Importer::instance()->scan_all_pages();
	return $started;
}

/**
 * What a full sync part way through its scan leaves, on page 2 of 4: its last
 * page begun $idle_seconds ago, and its last progress of any kind - the imports
 * it queued record theirs - $activity_seconds ago, the same unless given.
 */
function scan_under_way( $idle_seconds, $activity_seconds = null ) {
	fresh_site( 4, 8 );
	TCGiant_Sync_Importer::instance()->start_full_sync( true );
	TCGiant_Sync_Importer::update_sync_state( array( 'current_page' => 2, 'total_pages' => 4, 'total_found' => 8, 'total_queued' => 2, 'total_processed' => 6 ) );
	$state = state();
	$state['scan_page_at']     = time() - $idle_seconds;
	$state['last_activity_ts'] = time() - ( null === $activity_seconds ? $idle_seconds : $activity_seconds );
	update_option( TCGiant_Sync_Importer::STATE_OPTION, $state );
	$GLOBALS['QUEUE'] = array( array( 'tcgiant_sync_process_item_import', array( 'item_id' => '1004' ), 'tcgiant_sync_imports' ) );
	$GLOBALS['LOG']   = array();
	age_lock( $idle_seconds + 30 );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA FULL SYNC THAT QUEUED IMPORTS IS COMPLETE ONCE NONE IS LEFT\n" . str_repeat( '=', 124 ) . "\n";

fresh_site( 1, 8 );
check( 'Fetch Inventory: one page of 8, six imported inline, two queued for GetItem', array( fetch_and_scan(), state()['status'], state()['total_processed'], state()['total_queued'], count_queued( 'tcgiant_sync_process_item_import' ) ), array( true, 'importing', 6, 2, 2 ) );
$full_started = state()['full_started_at'];

run_import( '1004' );
check( 'the first GetItem import finishes, the other still queued: still importing', array( state()['status'], count_queued( 'tcgiant_sync_process_item_import' ) ), array( 'importing', 1 ) );
check( '  not said to be complete, and nothing pruned yet', array( logged( 'Sync complete!' ), count_queued( 'tcgiant_sync_prune_orphans' ) ), array( 0, 0 ) );
check( '  and the delta sync\'s position not moved', state()['last_successful_sync_at'], '2026-10-01T00:00:00.000Z' );

$IMP->start_delta_sync();
check( 'the hourly sync meanwhile leaves it to finish: no delta, the import still queued', array( logged( 'Sync already in progress' ), logged( 'Starting delta sync' ), count_queued( 'tcgiant_sync_process_item_import' ) ), array( 1, 0, 1 ) );

run_import( '1008' );
check( 'the last import finishes: complete', state()['status'], 'complete' );
check( '  the delta sync picks up from when the full sync began', state()['last_successful_sync_at'], $full_started );
check( '  and the pruner is queued, once', count_queued( 'tcgiant_sync_prune_orphans' ), 1 );

// Every listing imported inline: as in 3.27.4, nothing in the sync finishes
// it. It says importing, the delta sync's position stays where it was, and no
// pruner is queued - pruning for shops that have never had it is 3.28.0's.
fresh_site( 1, 3 );
check( 'a scan that queues no GetItem import: still importing at its last page, as in 3.27.4', array( fetch_and_scan(), state()['status'], state()['total_processed'], state()['total_queued'] ), array( true, 'importing', 3, 0 ) );
check( '  its position not moved, no pruner queued, nothing said to be complete', array( state()['last_successful_sync_at'], count_queued( 'tcgiant_sync_prune_orphans' ), logged( 'Sync complete!' ) ), array( '2026-10-01T00:00:00.000Z', 0, 0 ) );
// The dashboard's poll is what ends it, as in 3.27.4: complete, and nothing more.
$admin = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
preg_match( '/public function ajax_sync_status\(\) \{(.*?)\n\t\}/s', $admin, $poll );
check( '  until the dashboard\'s poll marks it complete: no position moved, no pruner', array( 1 === preg_match( "/'importing' === \\\$state\\['status'\\].*'status'\\s*=> 'complete'/s", $poll[1] ?? '' ), false !== strpos( $poll[1] ?? '', 'last_successful_sync_at' ), false !== strpos( $poll[1] ?? '', 'tcgiant_sync_prune_orphans' ) ), array( true, false, false ) );

// The dashboard polls while the last import is running: no longer pending, it
// is still not done, and the import's own check is what finishes the sync.
fresh_site( 1, 8 );
fetch_and_scan();
$full_started = state()['full_started_at'];
run_import( '1004' );
$QUEUE   = array_values( array_filter( $QUEUE, function ( $a ) { return '1008' !== (string) ( $a[1]['item_id'] ?? '' ); } ) );
$RUNNING = array( 'tcgiant_sync_process_item_import' );
dashboard_polls();
check( 'the dashboard polls while the last import runs: still importing', state()['status'], 'importing' );
TCGiant_Sync_Importer::instance()->process_item_import( '1008' );
$RUNNING = array();
check( '  then that import finishes it: complete, its position moved, the pruner queued', array( state()['status'], state()['last_successful_sync_at'], count_queued( 'tcgiant_sync_prune_orphans' ) ), array( 'complete', $full_started, 1 ) );
dashboard_polls();
check( 'a poll with no import pending or running still completes one, as in 3.27.4', ( function () { fresh_site( 1, 3 ); fetch_and_scan(); dashboard_polls(); return state()['status']; } )(), 'complete' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PRUNER LEAVES ALONE A PRODUCT GIVEN ITS LISTING SINCE THE FULL SYNC BEGAN\n" . str_repeat( '=', 124 ) . "\n";

// One page of 32: 24 imported inline, 8 queued for GetItem, and every one of
// the 32 listings has its product, 2001 on.
fresh_site( 1, 32 );
fetch_and_scan();
foreach ( (array) get_option( TCGiant_Sync_Importer::ACTIVE_IDS_OPTION, array() ) as $i => $item ) {
	$LINKED[ 2001 + $i ] = (string) $item;
}
$started_local = state()['started_at'];
// Products whose listings have gone from eBay: the pruner's work.
$LINKED[880] = '990000000880';
$PMETA[880]  = array( '_ebay_start_time' => '2026-01-05T10:00:00.000Z', '_ebay_export_last_pushed' => '2026-01-05 10:00:00' );
$LINKED[881] = '990000000881';
$PMETA[881]  = array( '_ebay_export_last_pushed' => gmdate( 'Y-m-d H:i:s', strtotime( $started_local ) - 2 * HOUR_IN_SECONDS ) );
check( 'the scan has read every page: importing, 8 imports queued, 32 listings on its list', array( state()['status'], count_queued( 'tcgiant_sync_process_item_import' ), count( (array) get_option( TCGiant_Sync_Importer::ACTIVE_IDS_OPTION, array() ) ) ), array( 'importing', 8, 32 ) );
run_import( '1004' );
// Meanwhile: 777 pushed from WooCommerce, which links it to its new listing;
// 778 relisted, by Relist or Auto-Relist; 779 pushed half an hour before the
// sync began, an hour a clock going back can repeat.
$LINKED[777] = '990000000777';
$PMETA[777]  = array( '_ebay_export_last_pushed' => current_time( 'mysql' ) );
$LINKED[778] = '990000000778';
$PMETA[778]  = array( '_ebay_start_time' => gmdate( 'Y-m-d\TH:i:s.000\Z' ) );
$LINKED[779] = '990000000779';
$PMETA[779]  = array( '_ebay_export_last_pushed' => gmdate( 'Y-m-d H:i:s', strtotime( $started_local ) - 30 * MINUTE_IN_SECONDS ) );
foreach ( array( '1008', '1012', '1016', '1020', '1024', '1028', '1032' ) as $id ) {
	run_import( $id );
}
check( 'every import done: complete, the pruner queued', array( state()['status'], count_queued( 'tcgiant_sync_prune_orphans' ) ), array( 'complete', 1 ) );
as_unschedule_all_actions( 'tcgiant_sync_prune_orphans' );
$IMP->prune_orphaned_items();
check( '  the pruner keeps the product pushed meanwhile, and the one relisted', array( in_array( 777, $TRASHED, true ), in_array( 778, $TRASHED, true ) ), array( false, false ) );
check( '  and the one pushed in the hour before the sync began', in_array( 779, $TRASHED, true ), false );
check( '  but still trashes those whose listings have gone', array( in_array( 880, $TRASHED, true ), in_array( 881, $TRASHED, true ), count( $TRASHED ) ), array( true, true, 2 ) );
check( '  and says what it kept', logged( 'Kept 3 listed or pushed since the full sync began, which its scan could not have seen.' ), 1 );

// The same while the scan itself is reading pages, as 3.27.4 already could.
fresh_site( 1, 12 );
$IMP->start_full_sync( true );
as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages' );
$ON_PAGE = function ( $page ) {
	$GLOBALS['LINKED'][777] = '990000000777';
	$GLOBALS['PMETA'][777]  = array( '_ebay_export_last_pushed' => current_time( 'mysql' ) );
};
$IMP->scan_all_pages();
$ON_PAGE = null;
foreach ( (array) get_option( TCGiant_Sync_Importer::ACTIVE_IDS_OPTION, array() ) as $i => $item ) {
	$LINKED[ 3001 + $i ] = (string) $item;
}
$IMP->prune_orphaned_items();
check( 'a product pushed while the scan read its pages: kept too', array( count( $LINKED ), in_array( 777, $TRASHED, true ), $TRASHED ), array( 13, false, array() ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE DELTA SYNC AND RESUME IMPORT LEAVE THE QUEUED IMPORTS TO RUN\n" . str_repeat( '=', 124 ) . "\n";

fresh_site( 1, 8, array( 'status' => 'complete' ) );
$QUEUE = array( array( 'tcgiant_sync_process_item_import', array( 'item_id' => '1004' ), 'tcgiant_sync_imports' ) );
$IMP->start_delta_sync();
check( 'a delta sync starts with an import queued: the import stays', array( logged( 'Starting delta sync' ), count_queued( 'tcgiant_sync_process_item_import' ), count_queued( 'tcgiant_sync_fetch_delta_events' ) ), array( 1, 1, 1 ) );

// One the hourly sync takes for dead: its scan stops, its imports do not.
scan_under_way( 45 * MINUTE_IN_SECONDS );
$CRON = array( 'tcgiant_sync_scan_resume' );
$IMP->start_delta_sync();
check( 'a full sync idle 45 minutes, presumed dead: replaced by a delta sync', array( logged( 'presumed to have failed' ), state()['sync_mode'], $CRON ), array( 1, 'delta', array() ) );
check( '  its queued import left to run', count_queued( 'tcgiant_sync_process_item_import' ), 1 );

// Its scan done, one import put off by eBay's rate limit: nothing recorded for
// 45 minutes, but that import is what completes the sync, so the hourly sync
// waits for it rather than taking the full sync for dead.
fresh_site( 1, 8 );
fetch_and_scan();
$full_started = state()['full_started_at'];
run_import( '1004' );
$state = state();
$state['last_activity_ts'] = time() - 45 * MINUTE_IN_SECONDS;
update_option( TCGiant_Sync_Importer::STATE_OPTION, $state );
$LOG = array();
$IMP->start_delta_sync();
check( 'importing, its last import put off 45 minutes by a rate limit: the hourly sync waits', array( logged( 'Sync already in progress' ), logged( 'presumed to have failed' ), state()['status'], state()['sync_mode'] ), array( 1, 0, 'importing', 'full' ) );
run_import( '1008' );
check( '  then that import completes it: position moved, pruner queued', array( state()['status'], state()['last_successful_sync_at'], count_queued( 'tcgiant_sync_prune_orphans' ) ), array( 'complete', $full_started, 1 ) );

// With nothing left queued it is not waited for: replaced after 30 minutes idle, as in 3.27.4.
fresh_site( 1, 3 );
fetch_and_scan();
$state = state();
$state['last_activity_ts'] = time() - 45 * MINUTE_IN_SECONDS;
update_option( TCGiant_Sync_Importer::STATE_OPTION, $state );
$LOG = array();
$IMP->start_delta_sync();
check( 'importing with nothing queued, idle 45 minutes: replaced by a delta sync, as in 3.27.4', array( logged( 'presumed to have failed' ), state()['sync_mode'] ), array( 1, 'delta' ) );

// Resume Import after eBay's rate limit stopped the scan on page 2 of 3.
fresh_site( 3, 8, array( 'status' => 'rate_limited', 'sync_mode' => 'full', 'current_page' => 1, 'total_pages' => 3, 'total_queued' => 2, 'total_processed' => 6 ) );
$QUEUE = array( array( 'tcgiant_sync_process_item_import', array( 'item_id' => '1004' ), 'tcgiant_sync_imports' ), array( 'tcgiant_sync_process_item_import', array( 'item_id' => '1008' ), 'tcgiant_sync_imports' ) );
$IMP->resume_sync();
check( 'Resume Import mid-scan: scanning again from where it stopped, its two imports kept', array( state()['status'], state()['current_page'], count_queued( 'tcgiant_sync_scan_all_pages' ), count_queued( 'tcgiant_sync_process_item_import' ) ), array( 'scanning', 1, 1, 2 ) );
// It reads that page again: an import still waiting is not queued a second
// time, and one that has run since is queued again.
$GLOBALS['PAGES'] = 1;
$QUEUE = array_values( array_filter( $QUEUE, function ( $a ) { return '1008' !== (string) ( $a[1]['item_id'] ?? '' ); } ) );
as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages' );
$IMP->scan_all_pages();
$queued_ids = array_map( function ( $a ) { return (string) $a[1]['item_id']; }, array_values( array_filter( $QUEUE, function ( $a ) { return 'tcgiant_sync_process_item_import' === $a[0]; } ) ) );
sort( $queued_ids );
check( '  reading that page again: each listing queued once, the one already run queued again', $queued_ids, array( '1004', '1008' ) );
check( '  and counted once more, not twice', state()['total_queued'], 3 );

// The scan had read every page; eBay's rate limit stopped the imports. Its
// completion is decided as 3.27.4 decided it, and moves nothing: no position,
// no pruner. Its import is kept, and runs.
fresh_site( 3, 8, array( 'status' => 'rate_limited', 'sync_mode' => 'full', 'current_page' => 4, 'total_pages' => 3, 'total_queued' => 2, 'total_processed' => 21, 'scan_complete_clean' => true, 'full_started_at' => '2026-10-19T08:00:00.000Z' ) );
$QUEUE = array( array( 'tcgiant_sync_process_item_import', array( 'item_id' => '3008' ), 'tcgiant_sync_imports' ) );
$IMP->resume_sync();
check( 'Resume Import after the scan: its import still queued, no position moved, no pruner', array( count_queued( 'tcgiant_sync_process_item_import' ), state()['last_successful_sync_at'], count_queued( 'tcgiant_sync_prune_orphans' ) ), array( 1, '2026-10-01T00:00:00.000Z', 0 ) );
run_import( '3008' );
check( '  and the import runs, still moving no position', array( in_array( '3008', $SAVED, true ), state()['last_successful_sync_at'] ), array( true, '2026-10-01T00:00:00.000Z' ) );
// The same with every listing imported inline: complete, as 3.27.4 said, and nothing more.
fresh_site( 3, 8, array( 'status' => 'rate_limited', 'sync_mode' => 'full', 'current_page' => 4, 'total_pages' => 3, 'total_queued' => 0, 'total_processed' => 24, 'scan_complete_clean' => true, 'full_started_at' => '2026-10-19T08:00:00.000Z' ) );
$IMP->resume_sync();
check( 'Resume Import after an all-inline scan: complete as in 3.27.4, no position moved, no pruner', array( state()['status'], state()['last_successful_sync_at'], count_queued( 'tcgiant_sync_prune_orphans' ) ), array( 'complete', '2026-10-01T00:00:00.000Z', 0 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFETCH INVENTORY DOES NOT START AGAIN A SYNC STILL SCANNING, OR STILL IMPORTING WHAT IT QUEUED\n" . str_repeat( '=', 124 ) . "\n";

fresh_site( 1, 8 );
$IMP->start_full_sync( true );
age_lock( 700 );
as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages' );
$IMP->scan_all_pages();
check( 'each page the scan reads, it reads with the lock fresh', array_map( function ( $age ) { return null !== $age && $age < 5; }, $LOCK_AGE_AT_READ ), array( true ) );

// Each page it begins is its heartbeat: pressed while it reads a page, an hour
// after the one before, Fetch Inventory is refused.
fresh_site( 1, 8 );
$IMP->start_full_sync( true );
as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages' );
update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( state(), array( 'scan_page_at' => time() - HOUR_IN_SECONDS ) ) );
@unlink( lock_file() );
$pressed = null;
$ON_PAGE = function ( $page ) use ( &$pressed ) {
	$answer  = TCGiant_Sync_Importer::instance()->start_full_sync( true );
	$pressed = is_wp_error( $answer ) ? $answer->get_error_code() : $answer;
};
$IMP->scan_all_pages();
$ON_PAGE = null;
check( 'Fetch Inventory pressed while the scan reads a page: refused, by the heartbeat of that page', $pressed, 'scan_running' );

// Fetch Inventory pressed before the scan has read its first page, or just
// after Resume Import: the scan is starting, not dead.
fresh_site( 1, 8 );
$IMP->start_full_sync( true );
@unlink( lock_file() );
$answer = $IMP->start_full_sync( true );
check( 'Fetch Inventory pressed again before the first page: refused, the scan is starting', is_wp_error( $answer ) ? $answer->get_error_code() : $answer, 'scan_running' );
fresh_site( 3, 8, array( 'status' => 'rate_limited', 'sync_mode' => 'full', 'current_page' => 1, 'total_pages' => 3, 'scan_page_at' => time() - 2 * HOUR_IN_SECONDS ) );
$IMP->resume_sync();
$answer = $IMP->start_full_sync( true );
check( 'Fetch Inventory pressed just after Resume Import: refused the same way', is_wp_error( $answer ) ? $answer->get_error_code() : $answer, 'scan_running' );

// Two minutes since its last page, its lock already looking stale.
scan_under_way( 2 * MINUTE_IN_SECONDS );
$answer = $IMP->start_full_sync( true );
check( 'Fetch Inventory pressed while a full sync is scanning: refused', is_wp_error( $answer ) ? $answer->get_error_code() : $answer, 'scan_running' );
check( '  saying where the scan is, and naming Process Queue', is_wp_error( $answer ) ? $answer->get_error_message() : '', 'A full sync is still reading your eBay listings (page 2 so far), so it was not started again from page 1. Press Process Queue to run what it has waiting. If it shows no progress for 30 minutes, you can start it again.' );
check( '  the scan left as it was: page 2, its counts, its import queued, no new scan', array( state()['current_page'], state()['total_processed'], count_queued( 'tcgiant_sync_process_item_import' ), count_queued( 'tcgiant_sync_scan_all_pages' ) ), array( 2, 6, 1, 0 ) );
check( '  and the log says so', logged( 'Full sync not started again: the one running is still scanning (page 2' ), 1 );

// Prune Inventory goes the same way, and the weekly full sync, which is not
// forced, is still told a sync is running.
check( 'the weekly full sync meanwhile: "already running", as before', ( function () use ( $IMP ) { $r = $IMP->start_full_sync(); return is_wp_error( $r ) ? $r->get_error_code() : $r; } )(), 'already_running' );

// The host killed the scan two hours ago; the imports it queued go on running,
// and each records its progress. It reads no page, so it is started again.
scan_under_way( 2 * HOUR_IN_SECONDS, 0 );
$answer = $IMP->start_full_sync( true );
check( 'a scan dead two hours, its imports still running: Fetch Inventory starts it again', array( $answer, state()['status'], state()['current_page'] ), array( true, 'scanning', 1 ) );

// A scan with nothing recorded for longer than the hourly sync waits.
scan_under_way( 31 * MINUTE_IN_SECONDS );
$answer = $IMP->start_full_sync( true );
check( 'a scan idle 31 minutes, its lock stale: Fetch Inventory starts it again, from page 1', array( $answer, state()['status'], state()['current_page'], state()['total_processed'], count_queued( 'tcgiant_sync_scan_all_pages' ) ), array( true, 'scanning', 1, 0, 1 ) );

// The scan done, its imports still queued: starting again would cancel them.
fresh_site( 1, 8, array( 'status' => 'importing', 'sync_mode' => 'full', 'current_page' => 1, 'total_pages' => 1, 'total_queued' => 2, 'total_processed' => 7, 'last_activity_ts' => time() - 2 * MINUTE_IN_SECONDS ) );
$QUEUE  = array( array( 'tcgiant_sync_process_item_import', array( 'item_id' => '1008' ), 'tcgiant_sync_imports' ) );
$answer = $IMP->start_full_sync( true );
check( 'Fetch Inventory pressed while the last sync is still importing what it queued: refused', is_wp_error( $answer ) ? $answer->get_error_code() : $answer, 'imports_running' );
check( '  saying why, and naming Process Queue', is_wp_error( $answer ) ? $answer->get_error_message() : '', 'The last sync is still importing the listings it queued, so a new one was not started: that would cancel them. Press Process Queue to run what it has waiting. If it shows no progress for 30 minutes, you can start it again.' );
check( '  its import still queued, still importing, no new scan', array( count_queued( 'tcgiant_sync_process_item_import' ), state()['status'], state()['total_processed'], count_queued( 'tcgiant_sync_scan_all_pages' ) ), array( 1, 'importing', 7, 0 ) );
check( '  and the log says so', logged( 'Full sync not started again: the last sync is still importing the listings it queued' ), 1 );
// Nothing left waiting - an all-inline sync still saying importing - it starts.
fresh_site( 1, 8, array( 'status' => 'importing', 'sync_mode' => 'full', 'total_processed' => 3, 'last_activity_ts' => time() - 2 * MINUTE_IN_SECONDS ) );
check( 'still importing with no import queued: Fetch Inventory starts a full sync, from page 1', array( $IMP->start_full_sync( true ), state()['status'], state()['current_page'], count_queued( 'tcgiant_sync_scan_all_pages' ) ), array( true, 'scanning', 1, 1 ) );
// Imports queued, but nothing recorded for 31 minutes: it starts, as before.
fresh_site( 1, 8, array( 'status' => 'importing', 'sync_mode' => 'full', 'total_processed' => 7, 'last_activity_ts' => time() - 31 * MINUTE_IN_SECONDS ) );
$QUEUE = array( array( 'tcgiant_sync_process_item_import', array( 'item_id' => '1008' ), 'tcgiant_sync_imports' ) );
check( 'still importing but idle 31 minutes: Fetch Inventory starts it again, as before', array( $IMP->start_full_sync( true ), state()['status'], state()['current_page'] ), array( true, 'scanning', 1 ) );

// A delta sync is not a scan Fetch Inventory leaves alone: a full sync covers it.
fresh_site( 1, 8, array( 'status' => 'scanning', 'sync_mode' => 'delta', 'last_activity_ts' => time() - 60, 'scan_page_at' => time() - 60 ) );
check( 'a delta sync running: Fetch Inventory starts the full sync', $IMP->start_full_sync( true ), true );
// Nor one that has finished its scan.
fresh_site( 1, 8, array( 'status' => 'complete', 'sync_mode' => 'full' ) );
check( 'a full sync complete: Fetch Inventory starts another', $IMP->start_full_sync( true ), true );

// The two buttons hand the refusal to the Import screen's notice.
check( 'Fetch Inventory and Prune Inventory both show the refusal as the screen\'s notice', preg_match_all( "/start_full_sync\\( true \\);\\s*\\\$args = is_wp_error\\( \\\$result \\)\\s*\\? array\\( 'sync_failed' => rawurlencode\\( \\\$result->get_error_message\\(\\) \\) \\)/", $admin ), 2 );

if ( is_file( lock_file() ) ) {
	unlink( lock_file() );
}
@rmdir( $UPLOADS );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
