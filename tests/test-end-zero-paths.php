<?php
/**
 * Harness: "When you end a listing on eBay, set its website stock to 0?"
 * (3.46.0) on the paths tests/test-end-zero.php's world has not got: the
 * hourly ended-listing check (a fixed-length listing, and a Good 'Til
 * Cancelled one), Stock Review's Settle, End Listing from the Listings screen
 * (a bulk job) and the relist check every Relist, push and Auto-Relist makes
 * of an ended listing (TCGiant_Sync_Exporter::ended_listing_check()), with
 * the setting Yes and No.
 *
 * Loaded for real: TCGiant_Sync_Cron, TCGiant_Sync_Jobs, TCGiant_Sync_DB,
 * TCGiant_Sync_Inventory (with the runtime), TCGiant_Sync_Exporter,
 * TCGiant_Sync_Admin and TCGiant_Sync_API - its requests answered at
 * trading_api_request() from a script, every one recorded, so EndItem goes
 * through the real end_item() and its mark. Posts, meta and the plugin's
 * tables are SQLite (tests/support/sold-world.php), so the hourly check's
 * own query runs. Nothing reaches eBay.
 *
 * Every time compared is made from time(): the switch-on an hour ago, each
 * end ten minutes ago.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/sold-world.php';

class TCGiant_Sync_Logger {
	public static $lines = array();
	public static function log( $message, $level = 'info' ) { self::$lines[] = array( $level, $message ); }
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
function esc_xml( $t ) { return htmlspecialchars( (string) $t, ENT_XML1 | ENT_QUOTES, 'UTF-8' ); }
class TCGiant_Sync_Environment {
	public static function write_block() { return null; }
	public static function note_blocked( $what, $error ) {}
}
class TCGiant_Sync_Sites {
	public static function sql_keeps_out_other_sites( $column ) { return ''; }
	public static function record_site( $id, $site ) {}
	public static function site_of( $item ) { return ''; }
	public static function leaves_out( $site ) { return false; }
}
// The relist check takes a WooCommerce product: the world's, as one.
class WC_Product extends Sold_Product {}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-db.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-exporter.php';
require $ROOT . '/includes/class-tcgiant-sync-jobs.php';
require $ROOT . '/includes/class-tcgiant-sync-cron.php';
require $ROOT . '/admin/class-tcgiant-sync-admin.php';

/** eBay at the request: GetItem from $items, EndItem taken, each call recorded. */
class Paths_eBay extends TCGiant_Sync_API {
	public $calls = array();
	public $items = array();
	public function __construct() {}
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$item = preg_match( '#<ItemID>(\d+)</ItemID>#', (string) $xml_body, $m ) ? $m[1] : '';
		$this->calls[] = $call_name . ' ' . $item;
		if ( 'GetItem' === $call_name ) {
			return isset( $this->items[ $item ] ) ? array( 'Ack' => 'Success', 'Item' => $this->items[ $item ] ) : new WP_Error( '17', 'This item cannot be accessed.' );
		}
		if ( 'EndItem' === $call_name ) {
			return array( 'Ack' => 'Success', 'EndTime' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() ) );
		}
		return new WP_Error( 'not_played', $call_name . ' is not played here' );
	}
}
$EBAY = new Paths_eBay();
$slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$slot->setAccessible( true );
}
$slot->setValue( null, $EBAY );

$jobs     = ( new ReflectionClass( 'TCGiant_Sync_Jobs' ) )->newInstanceWithoutConstructor();
$cron     = ( new ReflectionClass( 'TCGiant_Sync_Cron' ) )->newInstanceWithoutConstructor();
$exporter = ( new ReflectionClass( 'TCGiant_Sync_Exporter' ) )->newInstanceWithoutConstructor();
$CHECK    = new ReflectionMethod( 'TCGiant_Sync_Exporter', 'ended_listing_check' );
if ( PHP_VERSION_ID < 80100 ) {
	$CHECK->setAccessible( true );
}
$SINCE = time() - HOUR_IN_SECONDS;
$SAID  = '"When you end a listing on eBay, set its website stock to 0?" is on, so its website stock was set to 0';

/** A button's request to start a job, then its batches to the end; the last answer. */
function job( array $post ) {
	$_POST = $post;
	try {
		$GLOBALS['jobs']->ajax_start_job();
	} catch ( Sold_Json_Answer $a ) {
		$job_id = $a->data['job_id'] ?? '';
	}
	for ( $i = 0; $i < 50; $i++ ) {
		$_POST = array( 'job_id' => $job_id ?? '' );
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
/** The shop, the setting Yes (switched on an hour ago) or No. */
function paths_shop( $on ) {
	global $wpdb;
	$wpdb->reset();
	sold_forget_tables();
	$GLOBALS['OPTIONS']       = array( 'tcgiant_sync_ebay_settings' => $on ? array( 'end_zeroes_stock' => '1' ) : array() );
	$GLOBALS['TRANSIENTS']    = array();
	$GLOBALS['EVENTS']        = array();
	$GLOBALS['EBAY']->calls   = array();
	$GLOBALS['EBAY']->items   = array();
	TCGiant_Sync_Logger::$lines = array();
	if ( $on ) {
		$GLOBALS['OPTIONS'][ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] = $GLOBALS['SINCE'];
	}
}
/** A coin with $stock here on listing 9<id>, recorded live, ending as $meta says. */
function coin( $id, $stock, array $meta = array() ) {
	sold_product( $id, $meta + array( '_ebay_item_id' => '9' . $id, '_ebay_listing_status' => 'Active', '_manage_stock' => 'yes', '_stock' => $stock, '_stock_status' => $stock > 0 ? 'instock' : 'outofstock' ) );
}
/** eBay's answer for listing 9<id>, the seller having ended it ten minutes ago. */
function seller_ended( $id, $listed, $sold, $why = 'NotAvailable', array $more = array() ) {
	$details = array( 'EndTime' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 ) );
	if ( null !== $why ) {
		$details['EndingReason'] = $why;
	}
	return array_replace_recursive( array(
		'ItemID'         => '9' . $id,
		'Quantity'       => (string) $listed,
		'ListingType'    => 'FixedPriceItem',
		'SellingStatus'  => array( 'ListingStatus' => 'Completed', 'QuantitySold' => (string) $sold ),
		'ListingDetails' => $details,
	), $more );
}
function stock( $id ) { return (int) get_post_meta( $id, '_stock', true ); }
function zeroed( $id ) { $m = get_post_meta( $id, TCGiant_Sync_Inventory::END_ZERO_META, true ); return is_array( $m ) ? $m['item'] : null; }
function said( $needle ) { return count( array_filter( TCGiant_Sync_Logger::$lines, function ( $l ) use ( $needle ) { return false !== strpos( $l[1], $needle ); } ) ); }
function zero_lines() { return said( $GLOBALS['SAID'] ); }
/** Stock writes and status writes this shop made: none queues a push here (do_action is a no-op), so they are counted instead. */
function writes() { return count( array_filter( $GLOBALS['EVENTS'] ?? array(), function ( $e ) { return 0 === strpos( (string) $e, 'stock ' ); } ) ); }

echo "\nTHE HOURLY ENDED-LISTING CHECK\n" . str_repeat( '=', 140 ) . "\n";

$tcg_hourly = array();
foreach ( array( true, false ) as $tcg_on ) {
	$tcg_tag = $tcg_on ? 'Yes' : 'No';
	paths_shop( $tcg_on );
	$tcg_past = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 );
	// A seven-day listing whose end has passed, still in stock here: asked once.
	coin( 501, 2, array( '_ebay_end_time' => $tcg_past, '_ebay_listing_duration' => 'Days_7' ) );
	// A Good 'Til Cancelled one past its renewal date: asked whether it ended.
	coin( 502, 3, array( '_ebay_end_time' => $tcg_past, '_ebay_listing_duration' => 'GTC' ) );
	$EBAY->items['9501'] = seller_ended( 501, 3, 1 );
	$EBAY->items['9502'] = seller_ended( 502, 4, 0 );
	$cron->check_ended_listings();
	$tcg_hourly[ $tcg_tag ] = array( stock( 501 ), stock( 502 ), zeroed( 501 ), zeroed( 502 ), zero_lines(), $EBAY->calls );
	if ( $tcg_on ) {
		check( '  each said in one line, with what it held', array( said( 'WC #501: you ended eBay listing 9501 with 2 unsold, and ' . $SAID . ' (it was 2).' ), said( 'WC #502: you ended eBay listing 9502 with 4 unsold, and ' . $SAID . ' (it was 3).' ) ), array( 1, 1 ) );
		check( '  and the check\'s own line says 0', array( said( 'Ended listing check: WC #501 stock now 0' ), said( 'Ended listing check: WC #502 stock now 0' ) ), array( 1, 1 ) );
		check( '  both marked Ended, so the check never reads them again', array( get_post_meta( 501, '_ebay_listing_status', true ), get_post_meta( 502, '_ebay_listing_status', true ) ), array( 'Ended', 'Ended' ) );
		$EBAY->calls = array();
		$cron->check_ended_listings();
		check( '  the next hour: no call, nothing more', array( $EBAY->calls, zero_lines() ), array( array(), 2 ) );
	}
}
check( 'Yes: a fixed-length listing and a Good \'Til Cancelled one the seller ended - both 0, one GetItem each', $tcg_hourly['Yes'], array( 0, 0, '9501', '9502', 2, array( 'GetItem 9501', 'GetItem 9502' ) ) );
check( 'No: as 3.45.0 - the settle\'s figures (2 of 2, 3 of 4 unsold), the same calls', $tcg_hourly['No'], array( 2, 3, null, null, 0, array( 'GetItem 9501', 'GetItem 9502' ) ) );

paths_shop( true );
coin( 503, 2, array( '_ebay_end_time' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 ), '_ebay_listing_duration' => 'Days_7' ) );
$EBAY->items['9503'] = seller_ended( 503, 3, 1, 'Sold' );
$cron->check_ended_listings();
check( '  a sale that ended it with units left (Sold): never', array( stock( 503 ), zeroed( 503 ) ), array( 2, null ) );

echo "\nSTOCK REVIEW'S SETTLE\n" . str_repeat( '=', 140 ) . "\n";

$tcg_review = array();
foreach ( array( true, false ) as $tcg_on ) {
	$tcg_tag = $tcg_on ? 'Yes' : 'No';
	paths_shop( $tcg_on );
	coin( 510, 2, array( '_ebay_listing_status' => 'Ended' ) );
	$EBAY->items['9510'] = seller_ended( 510, 3, 1 );
	$tcg_done = job( array( 'type' => 'bulk_settle', 'product_ids' => array( 510 ) ) );
	$tcg_review[ $tcg_tag ] = array( $tcg_done['succeeded'] ?? null, stock( 510 ), zeroed( 510 ), zero_lines(), $EBAY->calls );
	if ( $tcg_on ) {
		check( '  its own line says 0', said( 'Stock review: WC #510 stock now 0 after ended eBay listing 9510.' ), 1 );
		update_post_meta( 510, '_stock', 2 );
		update_post_meta( 510, '_stock_status', 'instock' );
		$EBAY->calls = array();
		job( array( 'type' => 'bulk_settle', 'product_ids' => array( 510 ) ) );
		check( '  the shop raises it to 2 and presses Settle again: never 0 again', array( stock( 510 ), zero_lines() ), array( 2, 1 ) );
	}
}
check( 'Yes: Settle, the seller having ended it - 0, its one GetItem', $tcg_review['Yes'], array( 1, 0, '9510', 1, array( 'GetItem 9510' ) ) );
check( 'No: as 3.45.0', $tcg_review['No'], array( 1, 2, null, 0, array( 'GetItem 9510' ) ) );

echo "\nEND LISTING FROM THE LISTINGS SCREEN\n" . str_repeat( '=', 140 ) . "\n";

paths_shop( true );
coin( 520, 2, array( '_ebay_end_time' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() + 3 * DAY_IN_SECONDS ), '_ebay_listing_duration' => 'Days_7' ) );
$tcg_done = job( array( 'type' => 'bulk_end', 'product_ids' => array( 520 ) ) );
check( 'the Listings screen\'s End: ended, marked as the plugin\'s own, the shop\'s end recorded', array( $tcg_done['succeeded'] ?? null, $EBAY->calls, TCGiant_Sync_API::own_end( '9520' ), get_post_meta( 520, '_ebay_end_reason', true ) ), array( 1, array( 'EndItem 9520' ), true, 'by_shop' ) );
// Its settle: Stock Review's Settle, eBay saying NotAvailable for it.
$EBAY->items['9520'] = seller_ended( 520, 3, 1 );
job( array( 'type' => 'bulk_settle', 'product_ids' => array( 520 ) ) );
check( '  its settle: the stock stays 2 - End Listing here is not the setting\'s', array( stock( 520 ), zeroed( 520 ), zero_lines() ), array( 2, null, 0 ) );

echo "\nTHE RELIST CHECK: A RELIST, A PUSH, AUTO-RELIST\n" . str_repeat( '=', 140 ) . "\n";

// Before any sync settled it: eBay sold 1 of 3 and the seller ended it.
$tcg_relist = array();
foreach ( array( true, false ) as $tcg_on ) {
	$tcg_tag = $tcg_on ? 'Yes' : 'No';
	paths_shop( $tcg_on );
	coin( 530, 4, array( '_ebay_listing_status' => 'Ended' ) );
	$tcg_got = $CHECK->invoke( $exporter, new WC_Product( 530 ), '9530', seller_ended( 530, 3, 1 ) );
	$tcg_relist[ $tcg_tag ] = array( $tcg_got, stock( 530 ), zeroed( 530 ), zero_lines(), get_post_meta( 530, '_ebay_end_reason', true ), $EBAY->calls );
}
check( 'Yes: the check before a relist or push settles it (2 unsold) and never sets it to 0 - the relist goes on', $tcg_relist['Yes'], array( true, 2, null, 0, 'by_shop', array() ) );
check( 'No: the same', $tcg_relist['No'], array( true, 2, null, 0, 'by_shop', array() ) );
// Nothing sold: the check settles nothing, and a later settle of it - the
// hourly check - finds the reason it recorded first: left as it is.
paths_shop( true );
coin( 531, 2, array( '_ebay_listing_status' => 'Ended', '_ebay_end_time' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 ), '_ebay_listing_duration' => 'Days_7' ) );
$tcg_got = $CHECK->invoke( $exporter, new WC_Product( 531 ), '9531', seller_ended( 531, 3, 0 ) );
check( 'nothing sold: the check settles nothing, records the seller\'s end', array( $tcg_got, stock( 531 ), get_post_meta( 531, '_ebay_end_reason', true ), get_post_meta( 531, '_ebay_stock_settled_item', true ) ), array( false, 2, 'by_shop', '' ) );
$EBAY->items['9531'] = seller_ended( 531, 3, 0 );
job( array( 'type' => 'bulk_settle', 'product_ids' => array( 531 ) ) );
check( '  a later settle of it (Stock Review\'s): the reason came first - left at 2', array( stock( 531 ), zeroed( 531 ) ), array( 2, null ) );
$tcg_exporter_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-exporter.php' );
$tcg_check_at     = strpos( $tcg_exporter_src, 'private function ended_listing_check(' );
$tcg_check_src    = substr( $tcg_exporter_src, $tcg_check_at, strpos( $tcg_exporter_src, "\n\t}\n", $tcg_check_at ) - $tcg_check_at );
check( 'the check notes eBay\'s reason before its settle, every time it has an answer', array(
	strpos( $tcg_check_src, 'TCGiant_Sync_Inventory::note_ebay_end( $product_id, $item );' ) < strpos( $tcg_check_src, 'apply_ended_listing_stock(' ),
	substr_count( $tcg_check_src, 'TCGiant_Sync_Inventory::note_ebay_end( $product_id, $item );' ),
), array( true, 2 ) );
check( '  and a relist, a push and Auto-Relist all go through it (relist_ended_listing() twice, the push twice)', substr_count( $tcg_exporter_src, '$this->ended_listing_check( $product, ' ), 4 );

echo "\nSTOCK REVIEW'S \"USE eBAY'S FIGURE\" AFTER THE 0 (FIX ROUND 2)\n" . str_repeat( '=', 140 ) . "\n";

// eBay said 4 unsold, the website held 2: 3.28 keeps eBay's figure for Stock
// Review's panel. Once the setting has set the stock to 0, the panel's own
// query no longer finds it, and its button puts nothing back.
$tcg_figure = array();
foreach ( array( true, false ) as $tcg_on ) {
	$tcg_tag = $tcg_on ? 'Yes' : 'No';
	paths_shop( $tcg_on );
	coin( 540, 2, array( '_ebay_listing_status' => 'Ended' ) );
	$EBAY->items['9540'] = seller_ended( 540, 5, 1 );
	job( array( 'type' => 'bulk_settle', 'product_ids' => array( 540 ) ) );
	$tcg_listed = array_column( TCGiant_Sync_Inventory::find_unsold_at_end(), 'ebay_remaining', 'product_id' );
	$tcg_figure[ $tcg_tag ] = array( stock( 540 ), $tcg_listed[540] ?? null, said( 'WooCommerce\'s figure stands' ), zero_lines(), TCGiant_Sync_Inventory::use_ebay_figure( 540 ), stock( 540 ) );
}
check( 'Yes: Settle sets 0 - Stock Review does not list it, "Use eBay\'s figure" puts nothing back, nothing says WooCommerce\'s figure stands', $tcg_figure['Yes'], array( 0, null, 0, 1, null, 0 ) );
check( 'No: as 3.45.0 - listed with eBay\'s 4, said, and taken on request', $tcg_figure['No'], array( 2, 4, 1, 0, 4, 4 ) );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
