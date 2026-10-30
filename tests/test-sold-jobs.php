<?php
/**
 * Harness: what the Listings screen's and Stock Review's buttons do with the
 * Sold view, and Auto-Relist beside it.
 *
 * - End Listing records that the shop ended it, for the Sold view to say.
 * - Relist is not offered on the Sold view, and a Relist of "everything
 *   matching" - asked for on any view - leaves out what sold: each was
 *   refused for having no stock, after a place in the job.
 * - The sold-out cleanup moves a product to the trash only when eBay's own
 *   figures for the listing it follows say it sold out, checked as each
 *   product is reached - not on the strength of the list drawn when the
 *   button was pressed, nor of whatever ids the request named.
 * - Auto-Relist leaves out sold rows, so fifty of them no longer take every
 *   place in its daily fifty and starve the ones with stock.
 *
 * Loaded for real: TCGiant_Sync_Jobs, TCGiant_Sync_Cron, TCGiant_Sync_DB and
 * TCGiant_Sync_Inventory (with the runtime). eBay is played: EndItem answers
 * from a list, and Relist only records what it was handed. Everything else
 * is SQLite (tests/support/sold-world.php).
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/sold-world.php';

class TCGiant_Sync_Logger {
	public static $lines = array();
	public static function log( $message, $level = 'info' ) { self::$lines[] = $message; }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
}
function is_admin() { return true; }
function wp_doing_cron() { return false; }
function check_ajax_referer( $a = '' ) { return true; }
function current_user_can( $c ) { return true; }
class Sold_Json_Answer extends Exception {
	public $ok; public $data;
	public function __construct( $ok, $data ) { parent::__construct( 'json' ); $this->ok = $ok; $this->data = $data; }
}
function wp_send_json_success( $data = null ) { throw new Sold_Json_Answer( true, $data ); }
function wp_send_json_error( $data = null ) { throw new Sold_Json_Answer( false, $data ); }

// eBay, played: EndItem's answers by item, and every call it was asked.
class TCGiant_Sync_API {
	public static $ended = array();
	public static $calls = array();
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function end_item( $item_id ) {
		self::$calls[] = 'EndItem ' . $item_id;
		return self::$ended[ (string) $item_id ] ?? new WP_Error( 'ebay', 'The auction has been closed.' );
	}
	public static function scalar_value( $v ) { return is_array( $v ) ? (string) ( $v['value'] ?? '' ) : (string) $v; }
}
// Relist: only what it was handed. Its own checks are test-relist-guard.php's.
class TCGiant_Sync_Exporter {
	public static $relisted = array();
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function relist_ended_listing( $product_id, $source ) { self::$relisted[] = (int) $product_id; return array( 'item_id' => '1' ); }
	public static function push_progress( $product_id ) { return ''; }
	public static function relist_stock_state( $product ) { return ''; }
}
class TCGiant_Sync_Environment {
	public static function write_block() { return null; }
	public static function note_blocked( $what, $error ) {}
}
class TCGiant_Sync_Sites {
	public static function sql_keeps_out_other_sites( $column ) { return ''; }
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-db.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $ROOT . '/includes/class-tcgiant-sync-jobs.php';
require $ROOT . '/includes/class-tcgiant-sync-cron.php';
require $ROOT . '/admin/class-tcgiant-sync-admin.php';

$jobs = ( new ReflectionClass( 'TCGiant_Sync_Jobs' ) )->newInstanceWithoutConstructor();

/** A button's request to start a job: the answer, success or not, and its data. */
function start_job( array $post ) {
	$_POST = $post;
	try {
		$GLOBALS['jobs']->ajax_start_job();
	} catch ( Sold_Json_Answer $a ) {
		return array( $a->ok, $a->data );
	}
	return array( null, null );
}
/** The products a started job holds. */
function job_items( $job_id ) {
	return array_map( 'intval', (array) get_option( 'tcgiant_sync_job_items_' . $job_id, array() ) );
}
/** Run a job's batches to the end; the last answer. */
function run_job( $job_id ) {
	for ( $i = 0; $i < 50; $i++ ) {
		$_POST = array( 'job_id' => $job_id );
		try {
			$GLOBALS['jobs']->ajax_process_batch();
		} catch ( Sold_Json_Answer $a ) {
			if ( ! $a->ok || 'running' !== ( $a->data['status'] ?? '' ) ) {
				return $a->data;
			}
		}
	}
	return null;
}

/** The shop: ended rows sold and not, live ones, and the Sold view's own. */
function job_shop() {
	global $wpdb;
	$wpdb->reset();
	sold_forget_tables();
	$GLOBALS['OPTIONS'] = array();
	TCGiant_Sync_API::$calls    = array();
	TCGiant_Sync_Exporter::$relisted = array();
	$GLOBALS['EVENTS'] = array();
	$sold = array( '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 0, '_stock_status' => 'outofstock' );
	$left = array( '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 2, '_stock_status' => 'instock' );
	sold_product( 501, array( '_ebay_item_id' => '9501', '_ebay_listing_status' => 'Active', '_manage_stock' => 'yes', '_stock' => 1, '_stock_status' => 'instock' ) );
	sold_product( 502, array( '_ebay_item_id' => '9502', '_ebay_listing_status' => 'Active', '_manage_stock' => 'yes', '_stock' => 1, '_stock_status' => 'instock' ) );
	sold_product( 503, array_merge( $sold, array( '_ebay_item_id' => '9503' ) ) );
	sold_product( 504, array_merge( $sold, array( '_ebay_item_id' => '9504' ) ) );
	sold_product( 505, array_merge( $left, array( '_ebay_item_id' => '9505' ) ) );
	sold_product( 506, array_merge( $left, array( '_ebay_item_id' => '9506', '_stock_status' => 'onbackorder', '_stock' => 0 ) ) );
}

echo "\nEND LISTING SAYS THE SHOP ENDED IT\n" . str_repeat( '=', 140 ) . "\n";

job_shop();
TCGiant_Sync_API::$ended = array( '9501' => array( 'Ack' => 'Success', 'EndTime' => '2026-10-29T10:00:00.000Z' ) );
list( $ok, $data ) = start_job( array( 'type' => 'bulk_end', 'product_ids' => array( 501, 502 ) ) );
$done = run_job( $data['job_id'] );
check( 'two ended from the Listings screen: one taken, one refused by eBay', array( $done['succeeded'], $done['failed'] ), array( 1, 1 ) );
check( '  the one eBay took: Ended, eBay\'s end time, ended by the shop, for that listing', array( get_post_meta( 501, '_ebay_listing_status', true ), get_post_meta( 501, '_ebay_end_time', true ), get_post_meta( 501, '_ebay_end_reason', true ), get_post_meta( 501, '_ebay_end_reason_item', true ) ), array( 'Ended', '2026-10-29T10:00:00.000Z', 'by_shop', '9501' ) );
check( '  the listings table follows, and its row says so', array( $wpdb->get_var( 'SELECT listing_status FROM wp_tcgiant_listings WHERE product_id = 501' ), TCGiant_Sync_Inventory::end_note( 501, '9501', 'Ended' ) ), array( 'Ended', 'Ended by you' ) );
check( '  the one eBay refused: still live, no reason', array( get_post_meta( 502, '_ebay_listing_status', true ), get_post_meta( 502, '_ebay_end_reason', true ) ), array( 'Active', '' ) );
check( '  eBay was asked once for each, as before', TCGiant_Sync_API::$calls, array( 'EndItem 9501', 'EndItem 9502' ) );

// End Listing on the product's own screen.
job_shop();
TCGiant_Sync_API::$ended = array( '9501' => array( 'Ack' => 'Success', 'EndTime' => '2026-10-29T11:00:00.000Z' ) );
$admin = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();
foreach ( array( 501, 502 ) as $id ) {
	$_POST = array( 'product_id' => (string) $id );
	try {
		$admin->ajax_end_listing();
	} catch ( Sold_Json_Answer $a ) {
		$answers[ $id ] = $a->ok;
	}
}
check( 'End Listing on the product: eBay took one, refused the other', array( $answers[501], $answers[502] ), array( true, false ) );
check( '  the one taken: Ended, eBay\'s end time, ended by the shop', array( get_post_meta( 501, '_ebay_listing_status', true ), get_post_meta( 501, '_ebay_end_time', true ), get_post_meta( 501, '_ebay_end_reason', true ), get_post_meta( 501, '_ebay_end_reason_item', true ) ), array( 'Ended', '2026-10-29T11:00:00.000Z', 'by_shop', '9501' ) );
check( '  the one refused: unchanged', array( get_post_meta( 502, '_ebay_listing_status', true ), get_post_meta( 502, '_ebay_end_reason', true ) ), array( 'Active', '' ) );

echo "\nNO RELIST OF WHAT SOLD FROM \"EVERYTHING MATCHING\"\n" . str_repeat( '=', 140 ) . "\n";

job_shop();
list( $ok, $data ) = start_job( array( 'type' => 'bulk_relist', 'select_all' => 1, 'listing_status' => 'Sold' ) );
check( 'Relist of the Sold view is refused whole', array( $ok, false !== strpos( (string) ( $data['message'] ?? '' ), 'Nothing was relisted' ) ), array( false, true ) );
check( '  no job, and eBay not asked', array( get_option( TCGiant_Sync_Jobs::OPTION_KEY, array() ), TCGiant_Sync_API::$calls ), array( array(), array() ) );
list( $ok, $data ) = start_job( array( 'type' => 'bulk_relist', 'select_all' => 1, 'listing_status' => '' ) );
check( 'Relist of All takes in the ended rows with stock: not the sold ones, nor the live ones', job_items( $data['job_id'] ), array( 505, 506 ) );
list( $ok, $data ) = start_job( array( 'type' => 'bulk_relist', 'select_all' => 1, 'listing_status' => 'Active' ) );
check( 'Relist of the Active view is refused whole', array( $ok, false !== strpos( (string) ( $data['message'] ?? '' ), 'live eBay listings' ) ), array( false, true ) );
list( $ok, $data ) = start_job( array( 'type' => 'bulk_relist', 'select_all' => 1, 'listing_status' => 'Ended' ) );
check( 'Relist of the Ended view: its own rows', job_items( $data['job_id'] ), array( 505, 506 ) );
list( $ok, $data ) = start_job( array( 'type' => 'bulk_end', 'select_all' => 1, 'listing_status' => 'Sold' ) );
check( 'another job on the Sold view still takes its rows', job_items( $data['job_id'] ), array( 503, 504 ) );
list( $ok, $data ) = start_job( array( 'type' => 'bulk_push', 'select_all' => 1, 'listing_status' => '' ) );
check( '  and a Push of All takes every row, sold or not', job_items( $data['job_id'] ), array( 501, 502, 503, 504, 505, 506 ) );

echo "\nTHE SOLD-OUT CLEANUP TRASHES ONLY WHAT EBAY SAYS SOLD OUT, AS IT REACHES IT\n" . str_repeat( '=', 140 ) . "\n";

/** Ended, out of stock, and eBay's figures for this listing say it sold out. */
function verified( $id ) {
	sold_product( $id, array(
		'_ebay_item_id' => '96' . $id, '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 0, '_stock_status' => 'outofstock',
		'_ebay_stock_settled_at' => '2026-10-20 10:00:00', '_ebay_stock_settled_item' => '96' . $id, '_ebay_quantity_listed' => 1, '_ebay_quantity_sold' => 1,
	), false );
}

job_shop();
verified( 601 );
verified( 602 );
verified( 603 );
// Settled before 3.27.0: no figures. It used to be on the list and trashed.
sold_product( 604, array( '_ebay_item_id' => '96604', '_ebay_listing_status' => 'Ended', '_stock_status' => 'outofstock', '_ebay_stock_settled_at' => '2026-01-20 10:00:00' ), false );
list( $ok, $data ) = start_job( array( 'type' => 'bulk_remove_sold', 'select_all' => 1 ) );
check( 'the job takes the list Stock Review showed', job_items( $data['job_id'] ), array( 603, 602, 601 ) );
// Between pressing the button and the job reaching them: 602 is restocked,
// 603 is relisted, live again.
update_post_meta( 602, '_stock', 1 );
update_post_meta( 602, '_stock_status', 'instock' );
update_post_meta( 603, '_ebay_listing_status', 'Active' );
TCGiant_Sync_Inventory::clear_settled_mark( 603 );
$done = run_job( $data['job_id'] );
check( 'only the one still sold out is moved', array( get_post_status( 601 ), get_post_status( 602 ), get_post_status( 603 ), get_post_status( 604 ) ), array( 'trash', 'publish', 'publish', 'publish' ) );
check( '  the others kept, counted as kept', array( $done['succeeded'], $done['kept'], $done['failed'] ), array( 1, 2, 0 ) );
check( '  each kept one is said, in the log', count( preg_grep( '/^Sold-out cleanup: WC #60[23] was kept - eBay\'s figures for the listing it follows do not say it sold out\.$/', TCGiant_Sync_Logger::$lines ) ), 2 );

// A request naming its own products: an imported product, ended and in
// stock, that the old job would have trashed - remove_if_allowed() only
// asks whether the shop allows removal.
job_shop();
verified( 611 );
sold_product( 612, array( '_ebay_item_id' => '96612', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 3, '_stock_status' => 'instock' ), false );
sold_product( 613, array( '_ebay_item_id' => '96613', '_ebay_listing_status' => 'Ended', '_stock_status' => 'outofstock', '_ebay_stock_settled_at' => '2026-01-20 10:00:00' ), false );
list( $ok, $data ) = start_job( array( 'type' => 'bulk_remove_sold', 'product_ids' => array( 611, 612, 613 ) ) );
run_job( $data['job_id'] );
check( 'ids named by the request: only the one eBay sold out is moved', array( get_post_status( 611 ), get_post_status( 612 ), get_post_status( 613 ) ), array( 'trash', 'publish', 'publish' ) );
check( '  and nothing was asked of eBay', TCGiant_Sync_API::$calls, array() );

echo "\nAUTO-RELIST LEAVES OUT WHAT SOLD, WITH THE REAL QUERY\n" . str_repeat( '=', 140 ) . "\n";

$cron = ( new ReflectionClass( 'TCGiant_Sync_Cron' ) )->newInstanceWithoutConstructor();
/** What Auto-Relist hands to Relist, sorted. */
function auto_relisted() {
	TCGiant_Sync_Exporter::$relisted = array();
	$GLOBALS['cron']->auto_relist_ended_items();
	$got = TCGiant_Sync_Exporter::$relisted;
	sort( $got );
	return $got;
}

job_shop();
$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = array( 'auto_relist_enabled' => '1' );
// Sixty sold first, in the table's own order; then one with stock.
for ( $id = 700; $id < 760; $id++ ) {
	sold_product( $id, array( '_ebay_item_id' => '97' . $id, '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 0, '_stock_status' => 'outofstock' ) );
}
sold_product( 760, array( '_ebay_item_id' => '97760', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 1, '_stock_status' => 'instock' ) );
// No lookup row: the product's own status decides, either way.
sold_product( 761, array( '_ebay_item_id' => '97761', '_ebay_listing_status' => 'Ended', '_stock_status' => 'instock' ), array(), 'publish', false );
sold_product( 762, array( '_ebay_item_id' => '97762', '_ebay_listing_status' => 'Ended', '_stock_status' => 'outofstock' ), array(), 'publish', false );
check( 'the ended ones with stock or on backorder are relisted; the sold ones are not', auto_relisted(), array( 505, 506, 760, 761 ) );
check( '  however many sold ones are ahead of them', array_values( array_intersect( range( 700, 759 ), auto_relisted() ) ), array() );
check( '  no query was refused', $wpdb->errors, array() );

// The query compares no string across two tables: the stock as a 1/0 flag
// (TCGiant_Sync_DB::out_of_stock_sql()), and the listing a shop's End was
// for within wp_postmeta, never against the listings table's column.
$relist_sql = (string) current( array_filter( $wpdb->queries, function ( $q ) { return false !== strpos( $q, 'LIMIT 50' ); } ) );
check( '  its stock is a flag compared with a number', false !== strpos( $relist_sql, "COALESCE(lk.stock_status = 'outofstock', (SELECT ss.meta_value = 'outofstock'" ) && false !== strpos( $relist_sql, ', 0) = 0' ), true );
check( '  and the shop\'s End is matched within post meta', false !== strpos( $relist_sql, 'eci.meta_value = eri.meta_value' ) && false === strpos( $relist_sql, 'l.ebay_item_id =' ) && false === strpos( $relist_sql, '= l.ebay_item_id' ), true );

echo "\nAUTO-RELIST LEAVES ALONE A LISTING THE SHOP ENDED ITSELF\n" . str_repeat( '=', 140 ) . "\n";

// The review's probe: a product with stock, ended from the Listings screen,
// then Auto-Relist's next run. It was handed to Relist, which would have
// asked eBay and put it back up.
job_shop();
$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = array( 'auto_relist_enabled' => '1' );
TCGiant_Sync_API::$ended = array( '9501' => array( 'Ack' => 'Success', 'EndTime' => '2026-10-29T10:00:00.000Z' ) );
list( $ok, $data ) = start_job( array( 'type' => 'bulk_end', 'product_ids' => array( 501 ) ) );
run_job( $data['job_id'] );
check( 'ended from the Listings screen, with stock: Ended view, ended by the shop', array( get_post_meta( 501, '_ebay_listing_status', true ), get_post_meta( 501, '_stock', true ), get_post_meta( 501, '_ebay_end_reason', true ) ), array( 'Ended', '1', 'by_shop' ) );
check( '  Auto-Relist leaves it ended; the others with stock are relisted', auto_relisted(), array( 505, 506 ) );
// Fifty ended by the shop ahead of one that simply ran out: they take no place.
for ( $id = 800; $id < 860; $id++ ) {
	sold_product( $id, array( '_ebay_item_id' => '98' . $id, '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 2, '_stock_status' => 'instock', '_ebay_end_reason' => 'by_shop', '_ebay_end_reason_item' => '98' . $id ) );
}
sold_product( 860, array( '_ebay_item_id' => '98860', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 2, '_stock_status' => 'instock' ) );
check( '  sixty the shop ended ahead of one that ran out: that one is reached', in_array( 860, auto_relisted(), true ), true );
check( '  and none of the sixty', array_values( array_intersect( range( 800, 859 ), auto_relisted() ) ), array() );
// The shop's End of an earlier listing says nothing of this one.
sold_product( 861, array( '_ebay_item_id' => '98861', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 1, '_stock_status' => 'instock', '_ebay_end_reason' => 'by_shop', '_ebay_end_reason_item' => '97861' ) );
// Nor does an end the plugin made at 0, restocked since.
sold_product( 862, array( '_ebay_item_id' => '98862', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 1, '_stock_status' => 'instock', '_ebay_end_reason' => 'stock_zero', '_ebay_end_reason_item' => '98862' ) );
check( '  an End of an earlier listing, or an end at 0 since restocked: relisted', array( in_array( 861, auto_relisted(), true ), in_array( 862, auto_relisted(), true ) ), array( true, true ) );
// A new listing spends the mark.
TCGiant_Sync_Inventory::clear_settled_mark( 800 );
check( '  the mark spent by a new listing: relisted again', in_array( 800, auto_relisted(), true ), true );
check( '  no query was refused', $wpdb->errors, array() );
// A Relist the shop asks for by hand still takes it.
list( $ok, $data ) = start_job( array( 'type' => 'bulk_relist', 'select_all' => 1, 'listing_status' => 'Ended' ) );
check( 'a Relist of the Ended view by hand still takes in what the shop ended', in_array( 501, job_items( $data['job_id'] ), true ), true );
// Only an End recorded from this version on carries the mark. One made
// with an earlier version wrote the status alone, so it is relisted - and
// the shop is told so wherever it is told its ends stay ended.
sold_product( 863, array( '_ebay_item_id' => '98863', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 2, '_stock_status' => 'instock', '_ebay_end_time' => '2026-09-01T10:00:00.000Z' ) );
check( '  an End made with an earlier version (no mark): relisted', in_array( 863, auto_relisted(), true ), true );
$readme   = (string) file_get_contents( $ROOT . '/readme.txt' );
$notice   = substr( $readme, (int) strpos( $readme, "== Upgrade Notice ==\n\n= 3.28.3 =" ), 1600 );
$entry    = substr( $readme, (int) strpos( $readme, '= 3.28.3 - ' ), 9000 );
$settings = (string) file_get_contents( $ROOT . '/admin/views/settings.php' );
$devlog   = (string) file_get_contents( $ROOT . '/changelog.txt' );
check( '  the upgrade notice says an End made before this version may be relisted', array( false !== strpos( $notice, 'from this version on' ), false !== strpos( $notice, 'A listing you ended before this version, with End Listing or in Seller Hub, carries no such mark and may be relisted' ) ), array( true, true ) );
check( '  so does the readme entry', false !== strpos( $entry, 'A listing you ended before this version, or in Seller Hub, carries no such mark and may be relisted' ), true );
check( '  and the setting\'s own hint', false !== strpos( $settings, 'from version 3.28.3 on stays ended. One you ended before then, or in Seller Hub, is not known here and may be relisted' ), true );
check( '  and the changelog', false !== strpos( $devlog, 'Only ends recorded from 3.28.3 on carry by_shop' ), true );
check( '  nowhere is an unqualified promise left', array( false === strpos( $readme, 'listings you ended with End Listing in TCGiant Sync, stay ended' ), false === strpos( $settings, 'A listing you ended with End Listing in TCGiant Sync stays ended' ) ), array( true, true ) );
$wpdb->db->exec( 'DROP TABLE wp_tcgiant_listings' );
sold_forget_tables();
check( 'without the listings table: the same', array( in_array( 501, auto_relisted(), true ), in_array( 860, auto_relisted(), true ), array_values( array_intersect( range( 801, 859 ), auto_relisted() ) ) ), array( false, true, array() ) );
check( '  no query was refused', $wpdb->errors, array() );

job_shop();
$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = array( 'auto_relist_enabled' => '1' );
for ( $id = 700; $id < 760; $id++ ) {
	sold_product( $id, array( '_ebay_item_id' => '97' . $id, '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 0, '_stock_status' => 'outofstock' ) );
}
sold_product( 760, array( '_ebay_item_id' => '97760', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 1, '_stock_status' => 'instock' ) );
sold_product( 761, array( '_ebay_item_id' => '97761', '_ebay_listing_status' => 'Ended', '_stock_status' => 'instock' ), array(), 'publish', false );
sold_product( 762, array( '_ebay_item_id' => '97762', '_ebay_listing_status' => 'Ended', '_stock_status' => 'outofstock' ), array(), 'publish', false );

// A site whose listings table was never made: the post meta query.
$wpdb->db->exec( 'DROP TABLE wp_tcgiant_listings' );
sold_forget_tables();
check( 'without the listings table: the same', auto_relisted(), array( 505, 506, 760, 761 ) );
check( '  no query was refused', $wpdb->errors, array() );
// Nor WooCommerce's lookup table: every product's own status.
$wpdb->db->exec( 'DROP TABLE wp_wc_product_meta_lookup' );
sold_forget_tables();
check( 'nor the lookup table: the same', auto_relisted(), array( 505, 506, 760, 761 ) );
check( '  no query was refused', $wpdb->errors, array() );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
