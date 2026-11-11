<?php
/**
 * The importer's half of tests/test-license-robustness.php (3.36.0): what the
 * delta, the full sync's scan and a queued import write down when the free
 * limit holds back a new listing, what the delta's status and log say, and
 * that a Pro shop writes nothing. Run in a PHP process of its own, since it
 * loads the real importer in the prune world (support/prune-world.php), with
 * eBay played as test-category-memory.php plays it. Prints its findings as
 * JSON, one object.
 *
 * @package TCGiant_Sync
 */

/** eBay: GetSellerEvents, GetItem and GetSellerList, every call counted. */
class TCGiant_Sync_API {
	const DAILY_CALL_LIMIT        = 50000;
	const DAILY_CALL_SOFT_CEILING = 0.80;
	const MARKETPLACES = array(
		'EBAY_US' => array( 'label' => 'United States', 'site' => 'US' ),
	);
	public static function scalar_value( $value ) { return is_scalar( $value ) ? (string) $value : ''; }
	public static function count_push_marks( array $items ) { return 0; }
	public static function instance() { static $i; return $i ?: ( $i = new self() ); }
	public static function get_events_byte_limit() { return 26843545; }
	public function get_last_body_bytes() { return 2048; }
	public function get_remaining_daily_budget() { return 40000; }
	public function get_remaining_today() { return 40000; }
	public function get_today_limit() { return 40000; }
	public function with_stock_priority( callable $fn ) { return $fn(); }
	public function get_item( $item_id ) {
		$GLOBALS['EBAY']['get_items'][] = (string) $item_id;
		$a = $GLOBALS['EBAY']['items'][ (string) $item_id ] ?? null;
		return null === $a ? new WP_Error( 'api_error', 'No such listing.' ) : $a;
	}
	public function get_active_listings( $page = 1, $per = 200, $mod_from = '', $end_from = '' ) {
		return call_user_func( $GLOBALS['EBAY']['lister'], $page, $per, $mod_from, $end_from );
	}
	public function get_seller_events( $from, $to, $max_body_bytes = 0 ) {
		$rows = $GLOBALS['EBAY']['events'] ?? array();
		return array( 'Ack' => 'Success', 'ItemArray' => empty( $rows ) ? array() : array( 'Item' => $rows ) );
	}
	public function get_store() {
		return array( 'Store' => array( 'CustomCategories' => array( 'CustomCategory' => array( array( 'Name' => 'Pokemon', 'CategoryID' => '111' ), array( 'Name' => 'Coins', 'CategoryID' => '222' ) ) ) ) );
	}
}

$NOTOPTIONS = array();
function wp_cache_get( $key, $group = '' ) { return 'notoptions' === $key && 'options' === $group ? $GLOBALS['NOTOPTIONS'] : false; }
function wp_cache_set( $key, $value, $group = '' ) { if ( 'notoptions' === $key && 'options' === $group ) { $GLOBALS['NOTOPTIONS'] = $value; } return true; }

ob_start();
require __DIR__ . '/prune-world.php';
TCGiant_Sync_Importer::$page_pause = 0;
$LOCK = sys_get_temp_dir() . '/tcgiant_sync.lock';

/** A fresh shop with these settings, at the free limit or not. */
function shop( array $settings, $can_import ) {
	fresh_world( $settings );
	$p = new ReflectionProperty( 'TCGiant_Sync_Importer', 'limit_reported' );
	if ( PHP_VERSION_ID < 80100 ) {
		$p->setAccessible( true );
	}
	$p->setValue( null, false );
	$h = new ReflectionProperty( 'TCGiant_Sync_Limit_Held', 'noted' );
	if ( PHP_VERSION_ID < 80100 ) {
		$h->setAccessible( true );
	}
	$h->setValue( null, array() );
	$GLOBALS['CAN_IMPORT']        = $can_import;
	$GLOBALS['NOTOPTIONS']        = array();
	$GLOBALS['CACHE_READS_TABLE'] = true;
	$GLOBALS['STATUS_WRITES']     = array();
	unset( $GLOBALS['HOOKS']['shutdown'] );
	$GLOBALS['ON_UPDATE']         = function ( $n, $v ) {
		if ( TCGiant_Sync_Importer::STATE_OPTION === $n && is_array( $v ) ) {
			$GLOBALS['STATUS_WRITES'][] = (string) ( $v['status'] ?? '' );
		}
	};
	@unlink( $GLOBALS['LOCK'] );
}

/** A listing as GetItem and GetSellerList send it, with its categories. */
function whole( $id, array $cats ) {
	$item = array(
		'ItemID'         => $id,
		'Title'          => 'Item ' . $id,
		'Quantity'       => '1',
		'SellingStatus'  => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0' ),
		'ListingDetails' => array( 'StartTime' => '2026-09-01T10:00:00.000Z', 'EndTime' => '2026-12-01T10:00:00.000Z' ),
		'ItemSpecifics'  => array( 'NameValueList' => array() ),
		'PictureDetails' => array( 'PictureURL' => 'https://i.ebayimg.com/' . $id . '.jpg' ),
		'PrimaryCategory' => array( 'CategoryID' => $cats[0] ),
		'Storefront'     => array( 'StoreCategoryID' => $cats[1], 'StoreCategory2ID' => $cats[2] ),
	);
	$GLOBALS['EBAY']['items'][ $id ] = array( 'Ack' => 'Success', 'Item' => $item );
	return $item;
}

/** A row of eBay's list of changes as it usually comes: no category. */
function bare( $id ) {
	return array( 'ItemID' => $id, 'Quantity' => '1', 'SellingStatus' => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0' ) );
}

/** One delta run over these rows. */
function delta( array $rows ) {
	$GLOBALS['EBAY']['events']    = $rows;
	$GLOBALS['EBAY']['get_items'] = array();
	$GLOBALS['LOG']               = array();
	update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array(
		'status'               => 'scanning',
		'sync_mode'            => 'delta',
		'delta_mod_from'       => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 3600 ),
		'delta_window_from'    => '',
		'delta_cursor'         => '',
		'delta_offset'         => 0,
		'delta_window_minutes' => 2880,
		'delta_attempt'        => '',
	) ) );
	$GLOBALS['STATUS_WRITES']      = array();
	$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
	new_request();
	$GLOBALS['IMP']->fetch_delta_events();
}

/** The delta's last summary line. */
function summary() {
	foreach ( array_reverse( $GLOBALS['LOG'] ) as $l ) {
		if ( 0 === strpos( $l[1], 'Delta sync complete!' ) || 0 === strpos( $l[1], 'Delta batch done:' ) ) {
			return $l[1];
		}
	}
	return '';
}

/** What is written down, once the request ends. */
function held() {
	TCGiant_Sync_Limit_Held::flush();
	return array_map( 'strval', array_keys( TCGiant_Sync_Limit_Held::all() ) );
}

/** What the run left: written down, the summary, every status written, the flag, GetItem calls, the limit lines. */
function outcome() {
	$state = TCGiant_Sync_Importer::get_sync_state();
	return array(
		'held'          => held(),
		'summary'       => summary(),
		'status_writes' => array_values( array_unique( $GLOBALS['STATUS_WRITES'] ) ),
		'status'        => (string) ( $state['status'] ?? '' ),
		'flag'          => ! empty( $state['limit_held'] ),
		'get_items'     => $GLOBALS['EBAY']['get_items'],
		'saved'         => array_values( array_unique( array_map( function ( $s ) { return $s[0]; }, $GLOBALS['SAVED'] ) ) ),
		'limit_lines'   => count( array_filter( $GLOBALS['LOG'], function ( $l ) { return 0 === strpos( $l[1], 'Import limit reached' ); } ) ),
		'shutdown'      => count( array_filter( $GLOBALS['HOOKS']['shutdown'] ?? array(), function ( $h ) { return array( 'TCGiant_Sync_Limit_Held', 'flush' ) === $h[0]; } ) ) > 0,
	);
}

$out = array();

// The delta at the limit, no category filter: every new listing written down.
shop( array(), false );
product( 501, '310000000001', 1 );
whole( '310000000001', array( '183454', '111', '0' ) );
delta( array( bare( '310000000001' ), bare( '310000000002' ), bare( '310000000003' ), bare( '310000000004' ) ) );
$out['delta'] = outcome();

// With a store category filter: one the row's own categories put in it, one
// the memory remembers in it, one remembered outside it, one not known.
shop( array( 'category_ids' => '111' ), false );
TCGiant_Sync_Category_Memory::note_page( array(
	whole( '320000000002', array( '183454', '111', '0' ) ),
	whole( '320000000003', array( '183454', '222', '0' ) ),
) );
unset( $GLOBALS['EBAY']['items']['320000000002'], $GLOBALS['EBAY']['items']['320000000003'] );
$with_cats = bare( '320000000001' ) + array( 'PrimaryCategory' => array( 'CategoryID' => '261328' ), 'Storefront' => array( 'StoreCategoryID' => '333', 'StoreCategory2ID' => '111' ) );
delta( array( $with_cats, bare( '320000000002' ), bare( '320000000003' ), bare( '320000000004' ) ) );
$out['delta_filter'] = outcome();

// The same shop on Pro: nothing held back, nothing written down.
shop( array(), true );
whole( '330000000001', array( '183454', '111', '0' ) );
delta( array( bare( '330000000001' ) ) );
$out['delta_pro'] = outcome();

// The full sync's scan at the limit, after its category filter.
shop( array( 'category_ids' => '111' ), false );
$items = array( whole( '340000000001', array( '183454', '111', '0' ) ), whole( '340000000002', array( '183454', '222', '0' ) ), whole( '340000000003', array( '183454', '111', '0' ) ) );
$GLOBALS['EBAY']['lister'] = function ( $page ) use ( $items ) {
	return 1 === $page ? array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $items ), 'PaginationResult' => array( 'TotalNumberOfPages' => 1 ) ) : array( 'Ack' => 'Success' );
};
new_request();
$IMP->start_full_sync( true );
run_queue( 'tcgiant_sync_scan_all_pages' );
$out['scan'] = outcome();

// A queued import at the limit.
shop( array(), false );
whole( '350000000001', array( '183454', '111', '0' ) );
new_request();
$IMP->process_item_import( '350000000001' );
$out['import'] = outcome();

// Run twice over the same listing: written down once, at its newest.
shop( array(), false );
delta( array( bare( '360000000001' ), bare( '360000000002' ) ) );
held();
$p = new ReflectionProperty( 'TCGiant_Sync_Importer', 'limit_reported' );
if ( PHP_VERSION_ID < 80100 ) {
	$p->setAccessible( true );
}
$p->setValue( null, false );
delta( array( bare( '360000000001' ) ) );
$out['again'] = outcome();

$noise = ob_get_clean();
echo json_encode( array( 'out' => $out, 'noise' => $noise ) );
