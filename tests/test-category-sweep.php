<?php
/**
 * Harness: the delta learns the categories of changed listings it has none
 * for from eBay's list of listings, a page at a time (3.30.1).
 *
 * The category memory (3.28.4) held a listing for 8 days after a full sync's
 * page or a GetItem taught it (35 since 3.30.1). A monthly renewal comes
 * round every 30, so a renewal was skipped without a call only while the
 * weekly full sync was on time - and before the first full sync after the
 * update, never. On
 * 2026-11-03 the live-test shop, which imports 2 store categories, spent 4,627
 * GetItem calls on its monthly renewals, each only to learn a listing's
 * category, for 8 imports. eBay's list of changes says each listing's end,
 * and the listings renewed or listed together end together: the delta now
 * reads eBay's list of the listings ending then (GetSellerList, Coarse, 200 a
 * call) for their categories, and judges each by them as remembered.
 *
 * Loaded for real: TCGiant_Sync_Importer, TCGiant_Sync_Category_Memory,
 * TCGiant_Sync_Inventory, TCGiant_Sync_Sites and TCGiant_Sync_Runtime, in
 * the prune world (support/prune-world.php). eBay is played here, and every
 * call counted. "Today" is the same run with the list refused lately
 * (SWEEP_REFUSED_OPTION), which takes 3.30.0's path: a GetItem a row.
 *
 * @package TCGiant_Sync
 */

/** eBay: GetItem, GetSellerEvents, GetStore and the list by end time. */
class TCGiant_Sync_API {
	const DAILY_CALL_LIMIT        = 50000;
	const DAILY_CALL_SOFT_CEILING = 0.80;
	const MARKETPLACES = array(
		'EBAY_US' => array( 'label' => 'United States', 'site' => 'US' ),
		'EBAY_GB' => array( 'label' => 'United Kingdom', 'site' => 'UK' ),
	);
	public static function scalar_value( $value ) { return is_scalar( $value ) ? (string) $value : ''; }
	public static function count_push_marks( array $items ) { return 0; }
	public static function instance() { static $i; return $i ?: ( $i = new self() ); }
	public static function get_events_byte_limit() { return 26843545; }
	public function get_last_body_bytes() { return 2048; }
	public function get_remaining_daily_budget() { return 40000; }
	public function with_stock_priority( callable $fn ) { return $fn(); }
	public function get_item( $item_id ) {
		$GLOBALS['EBAY']['calls'][]     = 'GetItem ' . $item_id;
		$GLOBALS['EBAY']['get_items'][] = (string) $item_id;
		if ( isset( $GLOBALS['EBAY']['item_answer'] ) ) {
			return $GLOBALS['EBAY']['item_answer'];
		}
		$a = $GLOBALS['EBAY']['items'][ (string) $item_id ] ?? null;
		if ( null === $a ) {
			return new WP_Error( 'api_error', 'This item cannot be accessed because the listing has been deleted or you are not the seller.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '17' ) ) );
		}
		return $a;
	}
	public function get_active_listings( $page = 1, $per = 200, $mod_from = '', $end_from = '' ) {
		$GLOBALS['EBAY']['calls'][] = 'GetSellerList ' . $page;
		return call_user_func( $GLOBALS['EBAY']['lister'], $page, $per, $mod_from, $end_from );
	}
	public function get_seller_events( $from, $to, $max_body_bytes = 0 ) {
		$GLOBALS['EBAY']['calls'][] = 'GetSellerEvents';
		$rows = $GLOBALS['EBAY']['events'] ?? array();
		return array( 'Ack' => 'Success', 'ItemArray' => empty( $rows ) ? array() : array( 'Item' => $rows ) );
	}
	public function get_store() {
		$GLOBALS['EBAY']['calls'][] = 'GetStore';
		return array( 'Store' => array( 'CustomCategories' => array( 'CustomCategory' => array( array( 'Name' => 'Pokemon', 'CategoryID' => '111' ), array( 'Name' => 'Coins', 'CategoryID' => '222' ) ) ) ) );
	}
	/**
	 * The listings ending in the range, as Coarse sends them: ItemID,
	 * PrimaryCategory, Storefront, Site. $EBAY['per_page'] (200 as eBay's)
	 * makes pages small enough to count with a few listings.
	 */
	public function get_listing_categories( $from, $to, $page = 1 ) {
		$GLOBALS['EBAY']['calls'][]  = 'List ' . $page;
		$GLOBALS['EBAY']['ranges'][] = array( $from, $to, $page );
		if ( ! empty( $GLOBALS['EBAY']['on_list'] ) ) {
			call_user_func( $GLOBALS['EBAY']['on_list'], $page );
		}
		if ( isset( $GLOBALS['EBAY']['list_answer'] ) ) {
			return $GLOBALS['EBAY']['list_answer'];
		}
		$hits = array();
		foreach ( $GLOBALS['EBAY']['items'] as $id => $a ) {
			$end = strtotime( $a['Item']['ListingDetails']['EndTime'] ?? '' );
			if ( $end >= $from && $end <= $to ) {
				$hits[] = array_intersect_key( $a['Item'], array_flip( array( 'ItemID', 'PrimaryCategory', 'Storefront', 'Site' ) ) );
			}
		}
		$per   = (int) ( $GLOBALS['EBAY']['per_page'] ?? 200 );
		$pages = max( 1, (int) ceil( count( $hits ) / $per ) );
		$hits  = array_slice( $hits, ( $page - 1 ) * $per, $per );
		return array( 'Ack' => 'Success', 'ItemArray' => $hits ? array( 'Item' => 1 === count( $hits ) ? $hits[0] : $hits ) : array(), 'PaginationResult' => array( 'TotalNumberOfPages' => (string) $pages ) );
	}
}

$NOTOPTIONS = array();
function wp_cache_get( $key, $group = '' ) { return 'notoptions' === $key && 'options' === $group ? $GLOBALS['NOTOPTIONS'] : false; }
function wp_cache_set( $key, $value, $group = '' ) { if ( 'notoptions' === $key && 'options' === $group ) { $GLOBALS['NOTOPTIONS'] = $value; } return true; }

require __DIR__ . '/support/prune-world.php';

TCGiant_Sync_Importer::$page_pause = 0;

$LOCK  = sys_get_temp_dir() . '/tcgiant_sync.lock';
$SHIFT = 0;
TCGiant_Sync_Category_Memory::$clock = function () { return time() + (int) $GLOBALS['SHIFT']; };
$MEM_SHARE = 0.2;
$LATE      = 0;
TCGiant_Sync_Runtime::$memory_probe = function () { return (int) ( max( 1, TCGiant_Sync_Runtime::memory_limit() ) * $GLOBALS['MEM_SHARE'] ); };
if ( 0 === TCGiant_Sync_Runtime::memory_limit() ) {
	ini_set( 'memory_limit', '512M' );
}

const DAY = 86400;
$T = time() + 30 * DAY; // when the burst's listings end, renewed today

$STORE_111 = array( 'category_ids' => '111' );

function forget_statics() {
	$p = new ReflectionProperty( 'TCGiant_Sync_Importer', 'limit_reported' );
	if ( PHP_VERSION_ID < 80100 ) {
		$p->setAccessible( true );
	}
	$p->setValue( null, false );
}

function shop( array $settings ) {
	fresh_world( $settings );
	forget_statics();
	$GLOBALS['EBAY']['calls']  = array();
	$GLOBALS['EBAY']['ranges'] = array();
	$GLOBALS['EBAY']['events'] = array();
	$GLOBALS['ON_UPDATE']  = null;
	$GLOBALS['CAN_IMPORT'] = true;
	$GLOBALS['SHIFT']      = 0;
	$GLOBALS['MEM_SHARE']  = 0.2;
	$GLOBALS['NOTOPTIONS'] = array();
	$GLOBALS['CACHE_READS_TABLE'] = true;
	@unlink( $GLOBALS['LOCK'] );
}

/** Change the shop's settings, as saving the form does. */
function settings( array $settings ) {
	update_option( 'tcgiant_sync_ebay_settings', $settings );
}

/** A listing on eBay ending at $end: GetItem's whole answer, from now on. */
function ebay_has( $id, $store_cat, $end, array $extra = array() ) {
	$item = array_replace_recursive( array(
		'ItemID'          => (string) $id,
		'Title'           => 'Item ' . $id,
		'Quantity'        => '1',
		'ListingType'     => 'FixedPriceItem',
		'Site'            => 'US',
		'SellingStatus'   => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0' ),
		'ListingDetails'  => array( 'StartTime' => '2025-12-03T10:00:00.000Z', 'EndTime' => gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $end ) ),
		'ItemSpecifics'   => array( 'NameValueList' => array() ),
		'PictureDetails'  => array( 'PictureURL' => 'https://i.ebayimg.com/' . $id . '.jpg' ),
		'PrimaryCategory' => array( 'CategoryID' => '183454' ),
		'Storefront'      => array( 'StoreCategoryID' => (string) $store_cat, 'StoreCategory2ID' => '0' ),
	), $extra );
	$GLOBALS['EBAY']['items'][ (string) $id ] = array( 'Ack' => 'Success', 'Item' => $item );
	return $item;
}

/** The row eBay's list of changes sends: no category; its end, its site. */
function row( $id, array $over = array() ) {
	$i = $GLOBALS['EBAY']['items'][ (string) $id ]['Item'];
	return array_replace_recursive( array( 'ItemID' => (string) $id, 'Quantity' => '1', 'Site' => $i['Site'], 'SellingStatus' => $i['SellingStatus'], 'ListingDetails' => $i['ListingDetails'] ), $over );
}

/** $n listings in store category $cat ending $step seconds apart from $start. IDs from $base. */
function burst( $base, $n, $cat, $start, $step = 2 ) {
	$ids = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$ids[] = (string) ( $base + $i );
		ebay_has( end( $ids ), $cat, $start + $i * $step );
	}
	return $ids;
}

/** One delta run over a new window of these rows. */
function delta( array $rows ) {
	$GLOBALS['EBAY']['events']    = $rows;
	$GLOBALS['EBAY']['calls']     = array();
	$GLOBALS['EBAY']['get_items'] = array();
	$GLOBALS['EBAY']['ranges']    = array();
	$GLOBALS['SAVED']             = array();
	$GLOBALS['LOG']               = array();
	$GLOBALS['QUEUE']             = array();
	@unlink( $GLOBALS['LOCK'] );
	delete_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION );
	delete_option( TCGiant_Sync_Importer::DELTA_WINDOW_CACHE_OPTION );
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
	run_again();
	// A request already over half its memory hands the read of eBay to a
	// fresh one, once a window: that one is the window's first real run.
	if ( logs_with( 'so the read moves to a fresh request' ) && queued( 'tcgiant_sync_fetch_delta_events' ) ) {
		run_again();
	}
}

/** The window's next run, as Action Scheduler would start it. */
function run_again() {
	$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - $GLOBALS['LATE'];
	$GLOBALS['QUEUE'] = array();
	unset( $GLOBALS['HOOKS']['action_scheduler_memory_exceeded'] );
	@unlink( $GLOBALS['LOCK'] );
	new_request();
	forget_statics();
	$GLOBALS['IMP']->fetch_delta_events();
}

/** The window to its end, a run at a time; the runs it took. */
function to_the_end( $cap = 400 ) {
	$runs = 1;
	while ( queued( 'tcgiant_sync_fetch_delta_events' ) > 0 && 'complete' !== ( TCGiant_Sync_Importer::get_sync_state()['status'] ?? '' ) && $runs < $cap ) {
		run_again();
		$runs++;
	}
	return $runs;
}

/** Whether the last run told Action Scheduler to claim nothing more in its request. */
function queue_run_ended() { return ! empty( $GLOBALS['HOOKS']['action_scheduler_memory_exceeded'] ); }
function saved_ids() { $s = array_values( array_unique( array_map( function ( $s ) { return $s[0]; }, $GLOBALS['SAVED'] ) ) ); sort( $s ); return $s; }
function get_items() { return $GLOBALS['EBAY']['get_items']; }
function calls_of( $kind ) { return count( array_filter( $GLOBALS['EBAY']['calls'], function ( $c ) use ( $kind ) { return 0 === strpos( $c, $kind ); } ) ); }
function lists() { return calls_of( 'List ' ); }
function summary() {
	foreach ( array_reverse( $GLOBALS['LOG'] ) as $l ) {
		if ( 0 === strpos( $l[1], 'Delta sync complete!' ) || 0 === strpos( $l[1], 'Delta batch done:' ) ) {
			return $l[1];
		}
	}
	return '';
}
function logs_with( $needle ) { return count( array_filter( $GLOBALS['LOG'], function ( $l ) use ( $needle ) { return false !== strpos( $l[1], $needle ); } ) ); }
function remembered( $id ) {
	new_request();
	$r = TCGiant_Sync_Category_Memory::recall( array( $id ) );
	return $r[ $id ] ?? null;
}
function plan() {
	$p = get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION, array() );
	return is_array( $p ) && array_key_exists( 'sweep', $p ) ? $p['sweep'] : 'none';
}
/** One field of each range of the plan; 'none' when there is no plan. */
function plan_of( $key ) { $p = plan(); return is_array( $p ) ? array_column( $p, $key ) : $p; }
/** As 3.30.0 did it: the list refused a moment ago, so no window reads it. */
function as_today() { update_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, time() - 60 ); }
function ids_of( array $ids ) { return array_map( 'row', $ids ); }

// ---------------------------------------------------------------------------
echo "\nS1. A monthly renewal the memory does not hold: one list call, no GetItem for what the filter skips\n";
shop( $STORE_111 );
$out   = burst( 510000000000, 12, 222, $T );       // renewed, in store 222: skipped
$in    = burst( 520000000000, 2, 111, $T + 30 );   // renewed, in store 111: imported
$rows  = ids_of( array_merge( $out, $in ) );
delta( $rows );
check( 'one call to eBay\'s list, for the listings ending a minute either side of the rows', array( lists(), $EBAY['ranges'] ), array( 1, array( array( $T - 60, $T + 30 + 2 + 60, 1 ) ) ) );
check( '  a GetItem only for the two the filter takes', get_items(), $in );
check( '  which are imported, from GetItem\'s answer, as before', saved_ids(), $in );
check( '  the summary', summary(), 'Delta sync complete! 2 imported, 0 errors out of 14 modified items, 12 skipped (category filter, remembered), 2 GetItem fallbacks, 1 page of eBay\'s listings read for their categories (GetSellerList; 14 listings learnt).' );
check( '  the plan said once', logs_with( 'Delta: 14 of the changed listings still to do came with no category, are not remembered and have no product here; 14 of them end close enough together to read eBay\'s list of the listings that end when they do (1 range of end times, up to 200 listings a call)' ), 1 );
check( '  and every listing on the page is remembered, learnt now', array( remembered( $out[0] ), remembered( $in[1] ) ), array( array( '183454', '222', '0' ), array( '183454', '111', '0' ) ) );
check( '  the window done, its place and plan cleared with it', plan(), 'none' );
$with = $SAVED;

shop( $STORE_111 );
burst( 510000000000, 12, 222, $T );
burst( 520000000000, 2, 111, $T + 30 );
as_today();
delta( $rows );
check( 'today: no list, a GetItem a row', array( lists(), count( get_items() ) ), array( 0, 14 ) );
check( '  the same listings saved, the same way', $SAVED, $with );
check( '  the summary as 3.30.0\'s', summary(), 'Delta sync complete! 2 imported, 0 errors out of 14 modified items, 12 skipped (category filter), 14 GetItem fallbacks.' );

// ---------------------------------------------------------------------------
echo "\nS2. What is not planned for\n";
shop( $STORE_111 );
$b = burst( 530000000000, 5, 222, $T );
// One that came with its category, one remembered, one on another site, one with no end.
$GLOBALS['EBAY']['items'][ $b[4] ]['Item']['Site'] = 'UK';
TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ $b[1] ]['Item'] );
$rows = array(
	row( $b[0], array( 'PrimaryCategory' => array( 'CategoryID' => '183454' ), 'Storefront' => array( 'StoreCategoryID' => '222' ) ) ),
	row( $b[1] ),
	row( $b[2] ),
	row( $b[3], array( 'ListingDetails' => array( 'EndTime' => '' ) ) ),
	row( $b[4] ),
);
settings( $STORE_111 + array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' ) );
delta( $rows );
check( 'with a category, remembered, another site\'s, no end: one row left, under 3: no list', array( lists(), get_items() ), array( 0, array( $b[2], $b[3] ) ) );
check( '  and no plan said', logs_with( 'came with no category' ), 0 );
shop( $STORE_111 );
$b = burst( 531000000000, 3, 222, $T );
delta( array( row( $b[0], array( 'ListingDetails' => array( 'EndTime' => '' ) ) ), row( $b[1], array( 'ListingDetails' => array( 'EndTime' => 'soon' ) ) ), row( $b[2], array( 'ListingDetails' => array( 'EndTime' => '0' ) ) ) ) );
check( 'three rows with no end eBay gave that can be read: no list', array( lists(), count( get_items() ) ), array( 0, 3 ) );
shop( $STORE_111 );
$b = burst( 532000000000, 4, 222, $T );
TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ $b[0] ]['Item'] );
TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ $b[1] ]['Item'] );
delta( ids_of( $b ) );
check( 'four rows, two remembered: two left, no list', array( lists(), get_items() ), array( 0, array( $b[2], $b[3] ) ) );
shop( $STORE_111 );
$b = burst( 533000000000, 5, 222, $T );
delta( array( row( $b[0] ), row( $b[1] ), row( $b[2], array( 'PrimaryCategory' => array( 'CategoryID' => '183454' ) ) ), row( $b[3], array( 'Storefront' => array( 'StoreCategoryID' => '222' ) ) ), row( $b[4], array( 'Storefront' => array( 'StoreCategory2ID' => '0' ) ) ) ) );
check( 'five rows, three with a category of one kind or another: two left, no list', array( lists(), get_items() ), array( 0, array( $b[0], $b[1] ) ) );
shop( $STORE_111 + array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' ) );
$b = burst( 534000000000, 4, 222, $T );
$GLOBALS['EBAY']['items'][ $b[2] ]['Item']['Site'] = 'UK';
$GLOBALS['EBAY']['items'][ $b[3] ]['Item']['Site'] = 'UK';
delta( ids_of( $b ) );
check( 'four rows, two on another site the shop leaves out: two left, no list; the two left out with no call', array( lists(), get_items(), false !== strpos( summary(), '2 left out (on other eBay sites)' ) ), array( 0, array( $b[0], $b[1] ), true ) );

shop( $STORE_111 );
$b = burst( 540000000000, 2, 222, $T );
delta( ids_of( $b ) );
check( 'two rows: a GetItem each, no list', array( lists(), get_items() ), array( 0, $b ) );
shop( $STORE_111 );
$b = burst( 540000000000, 3, 222, $T );
delta( ids_of( $b ) );
check( 'three: the list, no GetItem', array( lists(), get_items() ), array( 1, array() ) );

shop( array() );
$b = burst( 550000000000, 6, 222, $T );
delta( ids_of( $b ) );
check( 'no filter: no list, each imported from its GetItem as before', array( lists(), count( get_items() ), count( saved_ids() ) ), array( 0, 6, 6 ) );

echo "\n   The free limit: nothing planned, and a new listing held back with no call at all\n";
shop( $STORE_111 );
$CAN_IMPORT = false;
$b   = burst( 560000000000, 6, 111, $T );
$own = burst( 561000000000, 1, 111, $T + 10 );
product( 701, $own[0], 1 );
delta( ids_of( array_merge( $b, $own ) ) );
check( 'at the limit: no list; GetItem only for the listing with a product, which it updates', array( lists(), get_items(), saved_ids() ), array( 0, $own, $own ) );
check( '  the six new ones held back before their category is asked', summary(), 'Delta sync complete! 1 imported, 0 errors out of 7 modified items, 6 skipped (category filter), 1 GetItem fallbacks.' );
check( '  and no plan made, or said', logs_with( 'came with no category' ), 0 );

echo "\n   Refused lately, by eBay\n";
shop( $STORE_111 );
$b = burst( 570000000000, 4, 222, $T );
update_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, time() - TCGiant_Sync_Importer::SWEEP_RETRY_SECONDS + 5 );
delta( ids_of( $b ) );
check( 'refused under 6 hours ago: no list, GetItem as before', array( lists(), count( get_items() ) ), array( 0, 4 ) );
update_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, time() - TCGiant_Sync_Importer::SWEEP_RETRY_SECONDS );
delta( ids_of( $b ) );
check( '  the four now remembered from their GetItem: nothing left to read', lists(), 0 );
shop( $STORE_111 );
$b = burst( 570000000000, 4, 222, $T );
update_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, time() - TCGiant_Sync_Importer::SWEEP_RETRY_SECONDS );
delta( ids_of( $b ) );
check( '  6 hours ago, nothing remembered: read', array( lists(), get_items() ), array( 1, array() ) );
shop( $STORE_111 );
$b = burst( 570000000000, 4, 222, $T );
update_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, time() + 600 );
delta( ids_of( $b ) );
check( '  a time ahead of the clock: read', array( lists(), get_items() ), array( 1, array() ) );

// ---------------------------------------------------------------------------
echo "\nS3. Ranges of end times\n";
shop( $STORE_111 );
$a = burst( 580000000000, 3, 222, $T );
$c = burst( 581000000000, 3, 222, $T + 3600 + 5 );          // more than an hour after $a's last
$d = burst( 582000000000, 3, 222, $T + 2 * 86400, 3000 );   // 50 minutes apart: one range of 100 minutes
delta( ids_of( array_merge( $a, $c, $d ) ) );
check( 'more than an hour apart: two ranges; 50 minutes apart: one', $EBAY['ranges'], array(
	array( $T - 60, $T + 4 + 60, 1 ),
	array( $T + 3605 - 60, $T + 3605 + 4 + 60, 1 ),
	array( $T + 2 * 86400 - 60, $T + 2 * 86400 + 6000 + 60, 1 ),
) );
check( '  every row learnt: no GetItem', get_items(), array() );
shop( $STORE_111 );
$e = burst( 583000000000, 3, 222, $T, 3600 );
delta( ids_of( $e ) );
check( 'exactly an hour apart: one range', $EBAY['ranges'], array( array( $T - 60, $T + 7200 + 60, 1 ) ) );

shop( $STORE_111 );
// 750 listings 59 minutes apart: a range never spans more than 30 days.
$long = burst( 590000000000, 750, 222, $T - 20 * 86400, 3540 );
delta( ids_of( $long ) );
$tcg_r = array_values( array_unique( array_map( function ( $r ) { return $r[0] . ',' . $r[1]; }, $EBAY['ranges'] ) ) );
$spans = array_map( function ( $r ) { list( $f, $t ) = explode( ',', $r ); return (int) $t - (int) $f - 120; }, $tcg_r );
check( '750 rows 59 minutes apart: two ranges, the first 30 days at most', array( count( $tcg_r ), max( $spans ) <= TCGiant_Sync_Importer::SWEEP_SPAN_SECONDS, $spans ), array( 2, true, array( 732 * 3540, 16 * 3540 ) ) );
check( '  its 733 rows\' 4 pages read first, then the other\'s one', array_column( $EBAY['ranges'], 2 ), array( 1, 2, 3, 4, 1 ) );
check( '  none asked about with GetItem', get_items(), array() );

shop( $STORE_111 );
// Twelve clusters, sized 3 to 14, two hours apart.
$all = array();
for ( $k = 0; $k < 12; $k++ ) {
	$all = array_merge( $all, burst( 600000000000 + $k * 1000, 3 + $k, 222, $T + $k * 7200 ) );
}
delta( ids_of( $all ) );
check( 'twelve ranges: the ten with the most rows, the most first', array_map( function ( $r ) { return (int) round( ( $r[0] + 60 - $GLOBALS['T'] ) / 7200 ); }, $EBAY['ranges'] ), array( 11, 10, 9, 8, 7, 6, 5, 4, 3, 2 ) );
check( '  the two smallest asked about with GetItem', count( get_items() ), 3 + 4 );

// ---------------------------------------------------------------------------
echo "\nS4. Pages\n";
shop( $STORE_111 );
$EBAY['per_page'] = 2;
$b     = burst( 610000000000, 7, 222, $T );
$extra = burst( 611000000000, 1, 222, $T + 4 );   // ends in the range, not changed
delta( ids_of( $b ) );
check( '7 rows, 8 listings in the range, 4 pages: more than half a row a page short - the first page only', array( lists(), count( get_items() ) ), array( 1, 5 ) );
check( '  so the rows on it skip their GetItem', count( get_items() ), 7 - 2 );
shop( $STORE_111 );
$EBAY['per_page'] = 2;
$b = burst( 610000000000, 6, 222, $T );
delta( ids_of( $b ) );
check( '6 rows, 3 pages: two rows a page - every page, no GetItem', array( lists(), get_items(), array_column( $EBAY['ranges'], 2 ) ), array( 3, array(), array( 1, 2, 3 ) ) );

shop( $STORE_111 );
$EBAY['per_page'] = 2;
$b = burst( 620000000000, 60, 222, $T );
delta( ids_of( $b ) );   // the window in one run
check( '60 rows, 30 pages: at most 25 in a run', lists(), TCGiant_Sync_Importer::SWEEP_PAGES_PER_RUN );
check( '  the 10 its pages did not reach wait for the next run, rather than a GetItem each (fix round 1)', array( count( get_items() ), logs_with( 'listing(s) done this run; the rest wait for eBay\'s list of listings' ) ), array( 0, 1 ) );
to_the_end();
check( '  which reads the last 5 pages first: 30 pages, no GetItem', array( lists(), count( get_items() ) ), array( 30, 0 ) );

shop( $STORE_111 );
$EBAY['per_page'] = 2;
$b = burst( 630000000000, 8, 222, $T );
$LATE = 100;   // every run's time used up before it begins: one page, then one listing
delta( ids_of( $b ) );
check( 'out of time: one page, then one listing, remembered from it', array( lists(), get_items(), summary() ), array( 1, array(), 'Delta batch done: 0 imported, 0 errors out of 1 modified items, 1 skipped (category filter, remembered), 1 page of eBay\'s listings read for their categories (GetSellerList; 2 listings learnt).' ) );
check( '  the plan\'s place kept: page 2 next', plan_of( 'page' ), array( 2 ) );
$EBAY['ranges'] = array();
run_again();
check( '  the next run reads page 2, not page 1 again', array_column( $EBAY['ranges'], 2 ), array( 2 ) );
$EBAY['ranges'] = array();
$GLOBALS['LOG'] = array();
$runs = to_the_end();
check( '  and the rest, a page a run, with no plan made again', array( array_column( $EBAY['ranges'], 2 ), logs_with( 'came with no category' ) ), array( array( 3, 4 ), 0 ) );
check( '  all 8 rows in 8 runs, with no GetItem', array( $runs + 1, get_items() ), array( 8, array() ) );
$LATE = 0;

shop( $STORE_111 );
$EBAY['per_page'] = 2;
$b = burst( 640000000000, 8, 222, $T );
$LATE = 100;   // the run's time all used before it began
delta( ids_of( $b ) );
$LATE = 0;
check( 'out of time: one page, as at least one listing', array( lists(), plan_of( 'page' ) ), array( 1, array( 2 ) ) );

shop( $STORE_111 );
$b = burst( 650000000000, 4, 222, $T );
$EBAY['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $GLOBALS['EBAY']['items'][ $b[0] ]['Item'] ), 'PaginationResult' => array( 'TotalNumberOfPages' => '3' ) );
delta( ids_of( $b ) );
check( 'a page of one listing: read; 3 pages for 4 rows is too many', array( lists(), remembered( $b[0] ), count( get_items() ) ), array( 1, array( '183454', '222', '0' ), 3 ) );
shop( $STORE_111 );
$b = burst( 650000000000, 4, 222, $T );
$EBAY['list_answer'] = array( 'Ack' => 'Success', 'PaginationResult' => array( 'TotalNumberOfPages' => '2' ) );
delta( ids_of( $b ) );
check( '  a page with nothing on it, of 2 for 4 rows: the range ends there, no page 2, and no band noted: there is none', array( lists(), count( get_items() ), get_option( TCGiant_Sync_Importer::SWEEP_BANDS_OPTION, array() ) ), array( 1, 4, array() ) );
shop( $STORE_111 );
$b = burst( 650000000000, 4, 222, $T );
$EBAY['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => array( $GLOBALS['EBAY']['items'][ $b[0] ]['Item'], 'junk' ) ) );
delta( ids_of( $b ) );
check( '  no page count: one page; what is not a listing passed over', array( lists(), count( get_items() ), summary() ), array( 1, 3, 'Delta sync complete! 0 imported, 0 errors out of 4 modified items, 3 skipped (category filter), 1 skipped (category filter, remembered), 3 GetItem fallbacks, 1 page of eBay\'s listings read for their categories (GetSellerList; 1 listing learnt).' ) );

echo "\n   Only a listing the answer says all the filter judges by\n";
shop( $STORE_111 );
$EBAY['per_page'] = 2;
$b = burst( 655000000000, 4, 222, $T );
$tcg_no_store = array_map( function ( $id ) { $i = $GLOBALS['EBAY']['items'][ $id ]['Item']; return array_intersect_key( $i, array_flip( array( 'ItemID', 'PrimaryCategory', 'Site' ) ) ); }, $b );
$EBAY['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => array_slice( $tcg_no_store, 0, 2 ) ), 'PaginationResult' => array( 'TotalNumberOfPages' => '2' ) );
delta( ids_of( $b ) );
check( 'store categories filtered, the answer has no Storefront: nothing kept, the range ends, a GetItem each as before', array( lists(), count( get_items() ), remembered( $b[0] ) ), array( 1, 4, array( '183454', '222', '0' ) ) );
shop( array( 'import_standard_category_ids' => array( '261328' ) ) );
TCGiant_Sync_Category_Memory::note( array( 'ItemID' => '656000000099', 'PrimaryCategory' => array( 'CategoryID' => '1' ) ) );   // a seller with no store
$b = burst( 656000000000, 4, 222, $T );
$EBAY['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => array_map( function ( $id ) { $i = $GLOBALS['EBAY']['items'][ $id ]['Item']; return array( 'ItemID' => $id, 'PrimaryCategory' => $i['PrimaryCategory'] ); }, $b ) ) );
delta( ids_of( $b ) );
check( '  eBay categories filtered: no Storefront needed, each kept by its eBay category', array( lists(), count( get_items() ), remembered( $b[0] ) ), array( 1, 0, array( '183454', '', '' ) ) );
shop( $STORE_111 );
$b = burst( 657000000000, 4, 222, $T );
$EBAY['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => array_map( function ( $id ) { $i = $GLOBALS['EBAY']['items'][ $id ]['Item']; return array( 'ItemID' => $id, 'Storefront' => $i['Storefront'] ); }, $b ) ) );
delta( ids_of( $b ) );
check( '  no eBay category: nothing kept', array( lists(), count( get_items() ) ), array( 1, 4 ) );

// ---------------------------------------------------------------------------
echo "\nS5. Once a window\n";
shop( $STORE_111 );
$b = burst( 660000000000, 600, 222, $T );   // more rows than a run looks at
delta( ids_of( $b ) );
$first = lists();
$runs  = to_the_end();
check( 'a window of 600 rows: planned and read on its first run, three pages', array( $first, lists(), logs_with( 'came with no category' ) ), array( 3, 3, 1 ) );
check( '  in two runs, with no GetItem', array( $runs, count( get_items() ) ), array( 2, 0 ) );

shop( $STORE_111 );
$b = burst( 670000000000, 7, 222, $T );
$GLOBALS['LATE'] = 100;
delta( ids_of( $b ) );   // one listing, one page
$p = get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION );
unset( $p['sweep'] );
update_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION, $p );
foreach ( array_keys( $TABLE ) as $n ) {
	if ( 0 === strpos( $n, TCGiant_Sync_Category_Memory::OPTION_PREFIX ) ) {
		delete_option( $n );
	}
}
$EBAY['ranges'] = array();
$GLOBALS['LOG'] = array();
run_again();
$GLOBALS['LATE'] = 0;
check( 'part way through a window 3.30.0 began: planned on its next run, for the rows still to do', array( logs_with( 'Delta: 6 of the changed listings still to do' ), count( $EBAY['ranges'] ) ), array( 1, 1 ) );

// ---------------------------------------------------------------------------
echo "\nS6. When eBay will not answer\n";
shop( $STORE_111 );
$b = burst( 680000000000, 5, 222, $T );
$EBAY['list_answer'] = new WP_Error( 'rate_limited', 'Daily API call budget exhausted. Will resume tomorrow.', array( 'daily_budget' => true ) );
$GLOBALS['LATE'] = 100;
delta( ids_of( $b ) );
check( 'out of calls: the plan kept, page 1 next, nothing noted as refused', array( plan_of( 'page' ), get_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, 'none' ), logs_with( 'would not send its list' ) ), array( array( 1 ), 'none', 0 ) );
unset( $EBAY['list_answer'] );
$EBAY['ranges'] = array();
run_again();
$GLOBALS['LATE'] = 0;
check( '  the next run reads it', array_column( $EBAY['ranges'], 2 ), array( 1 ) );

foreach ( array( 'http_request_failed' => 'cURL error 28: Operation timed out', 'no_token' => 'No valid eBay token.' ) as $code => $msg ) {
	shop( $STORE_111 );
	$b = burst( 680000000000, 5, 222, $T );
	$EBAY['list_answer'] = new WP_Error( $code, $msg );
	$GLOBALS['LATE'] = 100;
	delta( ids_of( $b ) );
	$GLOBALS['LATE'] = 0;
	check( '  ' . $code . ': passing, the plan kept', array( plan_of( 'page' ), get_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, 'none' ) ), array( array( 1 ), 'none' ) );
}

shop( $STORE_111 );
$b = burst( 690000000000, 5, 222, $T );
$EBAY['list_answer'] = new WP_Error( 'api_error', 'Input data for tag <GranularityLevel> is invalid or missing.' );
$GLOBALS['LATE'] = 100;
$tcg_auto = array();
$ON_UPDATE = function ( $n, $v, $a ) use ( &$tcg_auto ) { if ( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION === $n ) { $tcg_auto[] = $a; } };
delta( ids_of( $b ) );
$ON_UPDATE = null;
$GLOBALS['LATE'] = 0;
check( 'refused by eBay: the plan goes, its time noted, said once', array( plan(), abs( (int) get_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION ) - time() ) <= 2, logs_with( 'Delta: eBay would not send its list of listings for their categories (Input data for tag <GranularityLevel> is invalid or missing.), so the changed listings are asked about one at a time with GetItem, as before. It is not asked again for 6 hours.' ) ), array( array(), true, 1 ) );
check( '  written once, never autoloaded', $tcg_auto, array( false ) );
to_the_end();
check( '  the rows asked about with GetItem, as before, with no list read again', array( count( get_items() ), lists() ), array( 5, 1 ) );
unset( $EBAY['list_answer'] );
$b2 = burst( 691000000000, 5, 222, $T + 99 );
delta( ids_of( $b2 ) );
check( '  the next window reads no list', array( lists(), count( get_items() ) ), array( 0, 5 ) );
shop( $STORE_111 );
$b = burst( 690000000000, 5, 222, $T );
$EBAY['list_answer'] = 'not an answer';
$GLOBALS['LATE'] = 100;
delta( ids_of( $b ) );
$GLOBALS['LATE'] = 0;
check( '  an answer that is not one: refused too', array( plan(), (int) get_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, 0 ) > 0 ), array( array(), true ) );

echo "\n   A run PHP kills while it reads a page\n";
shop( $STORE_111 );
$b = burst( 696000000000, 5, 222, $T );
$tcg_seen = array();
$EBAY['on_list'] = function ( $page ) use ( &$tcg_seen ) {
	$p = $GLOBALS['TABLE'][ TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION ] ?? array();
	$f = new ReflectionProperty( 'TCGiant_Sync_Importer', 'slice_in_flight' );
	if ( PHP_VERSION_ID < 80100 ) {
		$f->setAccessible( true );
	}
	$tcg_seen = array( $p['sweep_reading'] ?? null, $f->getValue(), count( $p['sweep'] ?? array() ) );
};
$GLOBALS['LATE'] = 100;
delta( ids_of( $b ) );
$GLOBALS['LATE'] = 0;
$EBAY['on_list'] = null;
check( 'while a page is read: noted in the saved place, with the plan, and the run marked busy for its death handler', $tcg_seen, array( 1, true, 1 ) );
$p = get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION );
check( '  and cleared once it is held', array_key_exists( 'sweep_reading', $p ), false );
/** What a death while busy does: hands the run on. */
function tcg_death_hands_on() {
	$GLOBALS['QUEUE'] = array();
	$f = new ReflectionProperty( 'TCGiant_Sync_Importer', 'slice_in_flight' );
	if ( PHP_VERSION_ID < 80100 ) {
		$f->setAccessible( true );
	}
	$f->setValue( null, true );
	TCGiant_Sync_Importer::on_delta_death( 'Allowed memory size exhausted' );
	$f->setValue( null, false );
	return queued( 'tcgiant_sync_fetch_delta_events' );
}
check( '  a death while busy hands on at once', tcg_death_hands_on(), 1 );

shop( $STORE_111 );
$EBAY['per_page'] = 2;
$b = burst( 697000000000, 8, 222, $T );
$GLOBALS['LATE'] = 100;
delta( ids_of( $b ) );   // page 1, then one listing
// The next run dies reading page 2: its mark stays in the saved place.
$p = get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION );
$p['sweep_reading'] = 1;
update_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION, $p );
$EBAY['ranges'] = array();
$GLOBALS['LOG'] = array();
$GLOBALS['EBAY']['get_items'] = array();
run_again();
$GLOBALS['LATE'] = 0;
check( 'the run after one killed reading a page: no page again, the plan gone, said once', array( count( $EBAY['ranges'] ), plan(), logs_with( 'Delta: the last run was stopped while it read eBay\'s list of listings for their categories' ) ), array( 0, array(), 1 ) );
to_the_end();
check( '  the rows page 1 did not teach asked about with GetItem, as before, no page read; nothing noted as refused', array( count( get_items() ), count( $EBAY['ranges'] ), get_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, 'none' ) ), array( 6, 0, 'none' ) );

echo "\n   Emergency Stop while the list is read\n";
shop( $STORE_111 );
$EBAY['per_page'] = 2;
$b = burst( 695000000000, 8, 222, $T );
$EBAY['on_list'] = function ( $page ) {
	elsewhere( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'status' => 'stopped' ) ) );
};
delta( ids_of( $b ) );
check( 'stopped after the first page: no second', lists(), 1 );
$EBAY['on_list'] = null;

// ---------------------------------------------------------------------------
echo "\nS7. The filter judges what the list says as the full sync and GetItem do\n";
$filters = array(
	'store 111'   => array( 'category_ids' => '111' ),
	'by name'     => array( 'category_ids' => 'Pokemon' ),
	'eBay 261328' => array( 'import_standard_category_ids' => array( '261328' ) ),
	'both'        => array( 'category_ids' => '111', 'import_standard_category_ids' => array( '261328' ) ),
);
foreach ( $filters as $label => $filter ) {
	$results = array();
	foreach ( array( 'list', 'today' ) as $way ) {
		shop( $filter );
		ebay_has( '700000000001', 111, $T );
		ebay_has( '700000000002', 222, $T + 5 );
		ebay_has( '700000000003', 222, $T + 9, array( 'PrimaryCategory' => array( 'CategoryID' => '261328' ) ) );
		ebay_has( '700000000004', 333, $T + 12, array( 'Storefront' => array( 'StoreCategory2ID' => '111' ) ) );
		ebay_has( '700000000005', 1, $T + 15 );
		if ( 'today' === $way ) {
			as_today();
		}
		delta( ids_of( array( '700000000001', '700000000002', '700000000003', '700000000004', '700000000005' ) ) );
		$results[ $way ] = array( saved_ids(), lists() );
	}
	check( $label . ': the same listings imported, by the list and by GetItem', $results['list'][0], $results['today'][0] );
	check( '  the list read once', $results['list'][1], 1 );
}

echo "\n   A listing remembered 9 days ago, moved into the filter since\n";
shop( $STORE_111 );
$b = burst( 710000000000, 4, 222, $T );
$SHIFT = -9 * DAY;
TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ $b[0] ]['Item'] );
$SHIFT = 0;
ebay_has( $b[0], 111, $T );
delta( ids_of( $b ) );
check( 'no longer fresh: the list says where it is now, and it is imported', array( saved_ids(), get_items(), remembered( $b[0] ) ), array( array( $b[0] ), array( $b[0] ), array( '183454', '111', '0' ) ) );

// ---------------------------------------------------------------------------
echo "\nS8. The free limit holds back a new listing with no call: the delta, the full sync, items synced by hand\n";
shop( $STORE_111 );
$CAN_IMPORT = false;
ebay_has( '720000000001', 111, $T, array( 'ItemSpecifics' => null ) );   // in the filter, short of detail
ebay_has( '720000000002', 111, $T + 5 );
product( 801, '720000000002', 1 );
$GLOBALS['EBAY']['lister'] = function ( $page ) {
	return 1 === $page ? array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => array( $GLOBALS['EBAY']['items']['720000000001']['Item'] ) ), 'PaginationResult' => array( 'TotalNumberOfPages' => 1 ) ) : array( 'Ack' => 'Success' );
};
$EBAY['calls'] = array();
@unlink( $LOCK );
update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'scan_finished' => '' ) ) );
new_request();
$IMP->start_full_sync( true );
run_queue( 'tcgiant_sync_scan_all_pages' );
check( 'the full sync: the new listing held back, no import queued, no GetItem', array( queued( 'tcgiant_sync_process_item_import' ), calls_of( 'GetItem' ) ), array( 0, 0 ) );
$EBAY['calls']     = array();
$EBAY['get_items'] = array();
update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'status' => 'idle', 'sync_mode' => 'full' ) ) );
@unlink( $LOCK );
new_request();
forget_statics();
$IMP->start_specific_sync( array( '720000000001', '720000000002' ) );
run_queue( 'tcgiant_sync_process_item_import' );
check( 'items synced by hand: no GetItem for the new one; the one with a product read', $EBAY['get_items'] ?? array(), array( '720000000002' ) );
delta( array( row( '720000000001' ) ) );
check( 'the delta: no GetItem, no list', $EBAY['calls'], array( 'GetSellerEvents', 'GetStore' ) );

// ---------------------------------------------------------------------------
echo "\nS9. Fix round 1: rows wait for their pages; what a page costs is weighed by what the filter leaves out\n";

/** Per run: [list calls, GetItem calls, rows done], the window to its end. */
function per_run( $cap = 50 ) {
	$out  = array();
	$last = array( lists(), count( get_items() ) );
	$runs = 0;
	do {
		if ( $runs > 0 ) {
			run_again();
		}
		$runs++;
		$s     = summary();
		$rows  = preg_match( '/out of (\d+) modified items/', $s, $m ) ? (int) $m[1] : 0;
		$now   = array( lists(), count( get_items() ) );
		$out[] = array( $now[0] - $last[0], $now[1] - $last[1], $rows );
		$last  = $now;
		$GLOBALS['LOG'] = array();
	} while ( queued( 'tcgiant_sync_fetch_delta_events' ) > 0 && 'complete' !== ( TCGiant_Sync_Importer::get_sync_state()['status'] ?? '' ) && $runs < $cap );
	return $out;
}

shop( $STORE_111 );
$EBAY['per_page'] = 4;
$b = burst( 740000000000, 120, 222, $T );
product( 902, $b[100], 1 );   // the first row the run's 25 pages do not reach has a product here
delta( ids_of( $b ) );
check( 'a row with a product here is not planned (fix round 2), and does not wait: its GetItem now, as before; the next one waits', array( get_items(), logs_with( 'the rest wait for eBay' ), lists(), plan_of( 'rows' ) ), array( array( $b[100] ), 1, 25, array( 119 ) ) );
shop( $STORE_111 );
$b = burst( 741000000000, 5, 222, $T );
$EBAY['list_answer'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
delta( ids_of( $b ) );
unset( $EBAY['list_answer'] );
check( 'no page read this run: nothing waits, a GetItem each as before', array( count( get_items() ), logs_with( 'the rest wait' ) ), array( 5, 0 ) );

echo "\n   The filter takes most of a band: its first page only, and the band remembered for a day\n";
shop( $STORE_111 );
$EBAY['per_page'] = 4;
$band = array();
for ( $i = 0; $i < 40; $i++ ) {
	$band[] = (string) ( 742000000000 + $i );
	ebay_has( end( $band ), 0 === $i % 10 ? 222 : 111, $T + $i * 2 );   // 36 the filter takes, 4 it leaves out
}
delta( ids_of( $band ) );
to_the_end();
check( '40 rows, 10 pages, 1 in 4 on page 1 left out: page 1 only - a GetItem for the rest, 40 calls in all, as 3.30.0', array( lists(), count( get_items() ), lists() + count( get_items() ) ), array( 1, 39, 40 ) );
$tcg_bands = get_option( TCGiant_Sync_Importer::SWEEP_BANDS_OPTION, array() );
check( '  the band noted, with its pages', array( count( $tcg_bands ), $tcg_bands[0][0] ?? null, $tcg_bands[0][1] ?? null, $tcg_bands[0][2] ?? null ), array( 1, $T - 60, $T + 78 + 60, 10 ) );
$tcg_near = array();
for ( $i = 0; $i < 3; $i++ ) {
	$tcg_near[] = (string) ( 743000000000 + $i );
	ebay_has( end( $tcg_near ), 222, $T + 1800 + $i );   // half an hour after the band's end
}
delta( ids_of( $tcg_near ) );
check( '  the next window, 3 rows within the hour after it: no page, a GetItem each', array( lists(), count( get_items() ) ), array( 0, 3 ) );
$tcg_far = array();
for ( $i = 0; $i < 3; $i++ ) {
	$tcg_far[] = (string) ( 744000000000 + $i );
	ebay_has( end( $tcg_far ), 222, $T + 7200 + $i );
}
delta( ids_of( $tcg_far ) );
check( '  3 rows two hours after it: read', lists(), 1 );
$tcg_bands = get_option( TCGiant_Sync_Importer::SWEEP_BANDS_OPTION );
$tcg_bands[0][3] = time() - TCGiant_Sync_Importer::SWEEP_BAND_SECONDS;
update_option( TCGiant_Sync_Importer::SWEEP_BANDS_OPTION, $tcg_bands );
foreach ( $tcg_near as $id ) {
	TCGiant_Sync_Category_Memory::forget( array( $id ) );
}
delta( ids_of( $tcg_near ) );
check( '  a day on: read again', lists(), 1 );
shop( $STORE_111 );
$EBAY['per_page'] = 4;
update_option( TCGiant_Sync_Importer::SWEEP_BANDS_OPTION, array( array( $T - 60, $T + 78 + 60, 10, time() ), array( 1, 2, 3 ), 'junk', array( $T, $T, 1, time() + 600 ) ) );
$tcg_many = burst( 745000000000, 24, 222, $T + 10 );
delta( ids_of( $tcg_many ) );
check( '  rows enough for its pages, even inside it: read (what is not a band, or dated ahead, passed over)', array( lists() > 0, count( get_items() ) ), array( true, 0 ) );

echo "\n   What the window's pages say the filter leaves out decides before a range's first page\n";
foreach ( array( 'the filter takes them' => 111, 'the filter leaves them out' => 222 ) as $label => $cat ) {
	shop( $STORE_111 );
	$tcg_a = burst( 746000000000, 3, $cat, $T );
	$tcg_b = burst( 747000000000, 3, $cat, $T + 7200 );
	delta( ids_of( array_merge( $tcg_a, $tcg_b ) ) );
	check( '  two ranges, ' . $label . ': ' . ( 111 === $cat ? 'the second not read' : 'both read' ), lists(), 111 === $cat ? 1 : 2 );
}

echo "\n   A coin parent ticked (3.28.5): a listing is kept only with eBay's path, or a parent already learnt\n";
const PATH_US = 'Coins & Paper Money:Coins: US:Small Cents:Lincoln (1959-Now)';
/** A coin shop's window: 2 Lincoln cents (under 253), a Canadian dollar, a card. */
function coin_window( $answer ) {
	shop( array( 'import_standard_category_ids' => array( '253' ) ) );
	$paths = array(
		'748000000001' => array( '11980', PATH_US ),
		'748000000002' => array( '11980', PATH_US ),
		'748000000003' => array( '3390', 'Coins & Paper Money:Coins: Canada:Dollars' ),
		'748000000004' => array( '183454', 'Toys & Hobbies:Collectible Card Games:CCG Individual Cards' ),
	);
	$items = array();
	$n     = 0;
	foreach ( $paths as $id => $c ) {
		ebay_has( $id, 0, $GLOBALS['T'] + 2 * $n++, array( 'PrimaryCategory' => array( 'CategoryID' => $c[0], 'CategoryName' => $c[1] ), 'Storefront' => array( 'StoreCategoryID' => '0' ) ) );
		$i   = $GLOBALS['EBAY']['items'][ $id ]['Item'];
		$row = array( 'ItemID' => $id, 'PrimaryCategory' => array( 'CategoryID' => $c[0] ), 'Storefront' => $i['Storefront'] );
		if ( 'full' === $answer ) {
			$row['PrimaryCategory']['CategoryName'] = $c[1];
		} elseif ( 'short' === $answer ) {
			$row['PrimaryCategory']['CategoryName'] = substr( $c[1], strrpos( $c[1], ':' ) + 1 );
		}
		$items[] = $row;
	}
	$GLOBALS['EBAY']['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $items ) );
	return array_map( 'strval', array_keys( $paths ) );
}
$c = coin_window( 'full' );
delta( ids_of( $c ) );
check( 'with eBay\'s path: the cents imported (their GetItem, as the filter takes them); the dollar and the card skipped, no GetItem', array( saved_ids(), get_items() ), array( array( $c[0], $c[1] ), array( $c[0], $c[1] ) ) );
$c = coin_window( 'none' );
delta( ids_of( $c ) );
check( 'no path, nothing learnt: none kept - a GetItem each, the cents imported, as 3.30.0', array( saved_ids(), count( get_items() ), remembered( $c[0] ) ), array( array( $c[0], $c[1] ), 4, array( '11980', '0', '0' ) ) );
$c = coin_window( 'short' );
delta( ids_of( $c ) );
check( 'a short name, not a path: the same', array( saved_ids(), count( get_items() ) ), array( array( $c[0], $c[1] ), 4 ) );
$c = coin_window( 'none' );
TCGiant_Sync_Category_Memory::note( array( 'ItemID' => '748000000099', 'PrimaryCategory' => array( 'CategoryID' => '11980', 'CategoryName' => PATH_US ) ) );   // 11980 learnt under 253
delta( ids_of( $c ) );
check( 'no path, 11980\'s parent learnt before: nothing kept all the same (fix round 2) - a GetItem each, the cents imported, as 3.30.0', array( saved_ids(), get_items(), false !== strpos( summary(), '(GetSellerList; 0 listings learnt)' ), (int) get_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, 0 ) > 0 ), array( array( $c[0], $c[1] ), $c, true, true ) );
unset( $EBAY['list_answer'] );

echo "\n   eBay out of reach for the list twice in a row: the plan goes\n";
shop( $STORE_111 );
$b = burst( 749000000000, 6, 222, $T );
$EBAY['list_answer'] = new WP_Error( 'rate_limited', 'Internal error to the application.', array( 'Errors' => array( 'ErrorCode' => '10007' ) ) );
$LATE = 100;
delta( ids_of( $b ) );
$tcg_first = array( lists(), plan_of( 'page' ) );
run_again();
$tcg_second = array( lists(), plan(), logs_with( 'could not be read 2 times in a row (Internal error to the application.)' ) );
run_again();
$LATE = 0;
unset( $EBAY['list_answer'] );
check( 'once: the plan kept, page 1 next', $tcg_first, array( 1, array( 1 ) ) );
check( '  twice: the plan gone, said once', $tcg_second, array( 2, array(), 1 ) );
check( '  the next run reads no list; nothing noted as refused', array( lists(), get_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, 'none' ) ), array( 2, 'none' ) );
shop( $STORE_111 );
$EBAY['per_page'] = 2;
$b = burst( 750000000000, 8, 222, $T );
$EBAY['list_answer'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$LATE = 100;
delta( ids_of( $b ) );
unset( $EBAY['list_answer'] );
run_again();
$p = get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION );
$LATE = 0;
check( '  a failure, then a page read: the count starts again', array( $p['sweep_fails'] ?? 0, array_column( $p['sweep'] ?? array(), 'page' ) ), array( 0, array( 2 ) ) );

echo "\n   The free limit reached part way through a window\n";
shop( $STORE_111 );
$EBAY['per_page'] = 2;
$b = burst( 751000000000, 8, 222, $T );
$LATE = 100;
delta( ids_of( $b ) );
$CAN_IMPORT = false;
$EBAY['get_items'] = array();
run_again();
$LATE = 0;
check( 'the plan made under the limit, the limit reached since: no more pages, the row held back with no call', array( lists(), plan(), get_items() ), array( 1, array(), array() ) );

echo "\n   A listing with no store category, from a seller with an eBay store\n";
/** An eBay-category shop's window, the list leaving Storefront out of $without. */
function store_window( array $without, $remember_store ) {
	shop( array( 'import_standard_category_ids' => array( '261328' ) ) );
	$ids = burst( 752000000000, 4, 222, $GLOBALS['T'] );
	if ( true === $remember_store ) {
		TCGiant_Sync_Category_Memory::note( ebay_has( '752000000099', 333, $GLOBALS['T'] + 99999 ) );
	} elseif ( 'none' === $remember_store ) {
		// Remembered: a listing with no store category - a seller with no store, by the memory.
		TCGiant_Sync_Category_Memory::note( array( 'ItemID' => '752000000097', 'PrimaryCategory' => array( 'CategoryID' => '1' ) ) );
	}
	$items = array();
	foreach ( $ids as $n => $id ) {
		$i       = $GLOBALS['EBAY']['items'][ $id ]['Item'];
		$items[] = in_array( $n, $without, true ) ? array( 'ItemID' => $id, 'PrimaryCategory' => $i['PrimaryCategory'] ) : array( 'ItemID' => $id, 'PrimaryCategory' => $i['PrimaryCategory'], 'Storefront' => $i['Storefront'] );
	}
	$GLOBALS['EBAY']['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $items ) );
	delta( ids_of( $ids ) );
	unset( $GLOBALS['EBAY']['list_answer'] );
	return $ids;
}
$ids = store_window( array( 0, 1, 2, 3 ), true );
check( 'the memory holds a store category, the list none: nothing kept, a GetItem each', array( count( get_items() ), remembered( $ids[0] ) ), array( 4, array( '183454', '222', '0' ) ) );
$ids = store_window( array( 0, 1 ), 'none' );
check( 'the memory says no store, but the page shows store categories: the two without one not kept', array( get_items(), remembered( $ids[2] ) ), array( array( $ids[0], $ids[1] ), array( '183454', '222', '0' ) ) );
settings( $STORE_111 );
delta( ids_of( $ids ) );
check( '  so a store filter set later judges them by what eBay says: none taken, nothing asked', array( saved_ids(), count( get_items() ) ), array( array(), 0 ) );
$ids = store_window( array( 0, 1, 2, 3 ), false );
check( 'nothing remembered, the list says no store category: nothing kept, a GetItem each', array( count( get_items() ), remembered( $ids[0] ) ), array( 4, array( '183454', '222', '0' ) ) );
settings( $STORE_111 );
delta( ids_of( $ids ) );
check( '  so a store filter set later judges them by what eBay says (the review\'s P4)', array( saved_ids(), count( get_items() ) ), array( array(), 0 ) );
shop( array( 'import_standard_category_ids' => array( '261328' ) ) );
TCGiant_Sync_Category_Memory::note( array( 'ItemID' => '752000000098', 'PrimaryCategory' => array( 'CategoryID' => '1' ) ) );
$ids = burst( 752100000000, 4, 222, $T );
$EBAY['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => array_map( function ( $id ) { $i = $GLOBALS['EBAY']['items'][ $id ]['Item']; return array( 'ItemID' => $id, 'PrimaryCategory' => $i['PrimaryCategory'] ); }, $ids ) ) );
delta( ids_of( $ids ) );
unset( $EBAY['list_answer'] );
check( 'listings remembered, not one with a store category - a seller with no store: kept by their eBay category', array( count( get_items() ), remembered( $ids[0] ), TCGiant_Sync_Category_Memory::store_evidence() ), array( 0, array( '183454', '', '' ), 'none' ) );

echo "\n   For the review's mutants\n";
shop( $STORE_111 );
$b = burst( 770000000000, 4, 222, $T );
$tcg_lr = new ReflectionProperty( 'TCGiant_Sync_Importer', 'limit_reported' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_lr->setAccessible( true );
}
$EBAY['events'] = ids_of( $b );
update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'status' => 'scanning', 'sync_mode' => 'delta', 'delta_mod_from' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 3600 ), 'delta_window_from' => '', 'delta_cursor' => '', 'delta_offset' => 0, 'delta_window_minutes' => 2880, 'delta_attempt' => '' ) ) );
@unlink( $LOCK );
new_request();
$tcg_lr->setValue( null, true );   // an earlier run in this request met the free limit
$IMP->fetch_delta_events();
$tcg_lr->setValue( null, false );
check( 'the free limit already met in this request (a run before, the same request): nothing planned', array( lists(), logs_with( 'came with no category' ) ), array( 0, 0 ) );
shop( $STORE_111 );
$EBAY['per_page'] = 2;
$b = burst( 771000000000, 8, 222, $T );
$LATE = 100;
delta( ids_of( $b ) );
$p = get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION );
$p['sweep'] = 'junk';
update_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION, $p );
$EBAY['ranges'] = array();
run_again();
$LATE = 0;
check( 'a plan that is not a list: planned again', count( $EBAY['ranges'] ), 1 );
shop( $STORE_111 );
$b = burst( 772000000000, 4, 222, $T );
$tcg_busy = array();
$ON_UPDATE = function ( $n ) use ( &$tcg_busy ) {
	if ( 0 === strpos( $n, TCGiant_Sync_Category_Memory::OPTION_PREFIX . '' ) && preg_match( '/_\d+$/', $n ) ) {
		$f = new ReflectionProperty( 'TCGiant_Sync_Importer', 'slice_in_flight' );
		if ( PHP_VERSION_ID < 80100 ) {
			$f->setAccessible( true );
		}
		$tcg_busy[] = $f->getValue();
	}
};
delta( ids_of( $b ) );
$ON_UPDATE = null;
check( 'the pages read and written before the first listing: the run no longer marked busy', array_values( array_unique( $tcg_busy ) ), array( false ) );
shop( $STORE_111 );
$EBAY['per_page'] = 4;
update_option( TCGiant_Sync_Importer::SWEEP_BANDS_OPTION, array( array( $T - 60, $T + 200, 10, time() + 600 ) ) );
$b = burst( 773000000000, 3, 222, $T );
delta( ids_of( $b ) );
check( 'a band dated ahead of the clock: not trusted, the range read', lists(), 1 );
shop( array( 'import_standard_category_ids' => array( '261328' ) ) );
TCGiant_Sync_Category_Memory::note( array( 'ItemID' => '774000000098', 'PrimaryCategory' => array( 'CategoryID' => '1' ) ) );   // a seller with no store
$b = burst( 774000000000, 4, 222, $T );
$EBAY['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => array_map( function ( $id, $n ) {
	$i = $GLOBALS['EBAY']['items'][ $id ]['Item'];
	return $n < 2 ? array( 'ItemID' => $id, 'PrimaryCategory' => $i['PrimaryCategory'], 'Storefront' => array( 'StoreCategoryID' => '0' ) ) : array( 'ItemID' => $id, 'PrimaryCategory' => $i['PrimaryCategory'] );
}, $b, array_keys( $b ) ) ) );
delta( ids_of( $b ) );
unset( $EBAY['list_answer'] );
check( 'a store category of 0 is no sign of a store: all four kept', count( get_items() ), 0 );
shop( array( 'import_standard_category_ids' => array( '261328' ) ) );
TCGiant_Sync_Category_Memory::note( ebay_has( '774000000099', 0, $T + 99999 ) );   // remembered with store category 0
$b = burst( 775000000000, 4, 222, $T );
$EBAY['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => array_map( function ( $id ) { $i = $GLOBALS['EBAY']['items'][ $id ]['Item']; return array( 'ItemID' => $id, 'PrimaryCategory' => $i['PrimaryCategory'] ); }, $b ) ) );
delta( ids_of( $b ) );
unset( $EBAY['list_answer'] );
check( '  nor one remembered with store category 0', array( count( get_items() ), TCGiant_Sync_Category_Memory::store_evidence() ), array( 0, 'none' ) );
shop( $STORE_111 );
$b = burst( 776000000000, 6, 222, $T, 7200 );
TCGiant_Sync_Category_Memory::note( ebay_has( $b[2], 111, $T + 2 * 7200 ) );   // remembered, and the filter takes it
foreach ( array( 0, 1, 3, 4, 5 ) as $n ) {
	TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ $b[ $n ] ]['Item'] );
}
$MEM_SHARE = 0.61;
delta( ids_of( $b ) );
$MEM_SHARE = 0.2;
check( 'at 61%, a remembered row the filter takes needs its GetItem: it stops the run at the guard', array( preg_match( '/out of (\d+) modified/', summary(), $m ) ? (int) $m[1] : -1, count( get_items() ) ), array( 2, 0 ) );

shop( $STORE_111 );
$b   = burst( 777000000000, 4, 222, $T );
$far = burst( 777100000000, 2, 222, $T + 5 * DAY, 9000 );   // ends far from the rest, and from each other
delta( ids_of( array_merge( $b, $far ) ) );
check( 'the plan line: all the rows not remembered, and the ones it reads for', logs_with( 'Delta: 6 of the changed listings still to do came with no category, are not remembered and have no product here; 4 of them end close enough together' ), 1 );
foreach ( array( 'the filter takes them' => 111 ) as $label => $cat ) {
	shop( $STORE_111 );
	$tcg_a = burst( 778000000000, 3, $cat, $T );
	$tcg_b = burst( 778100000000, 3, $cat, $T + 7200 );
	$LATE  = 100;
	delta( ids_of( array_merge( $tcg_a, $tcg_b ) ) );
	to_the_end();
	$LATE = 0;
	check( 'a page a run, ' . $label . ': the share kept between runs, the second range not begun', lists(), 1 );
}
shop( $STORE_111 );
$EBAY['per_page'] = 2;
$tcg_alone = burst( 779000000000, 1, 222, $T + 9 * DAY );   // the lowest ItemID, in no range
$b         = burst( 779100000000, 8, 222, $T );
$LATE      = 100;
delta( ids_of( array_merge( $tcg_alone, $b ) ) );
$LATE = 0;
check( 'a row in no range of the plan does not wait: its GetItem now', get_items(), $tcg_alone );
shop( $STORE_111 );
$b = burst( 779200000000, 6, 222, $T, 7200 );
foreach ( array( 0, 1, 3, 4, 5 ) as $n ) {
	TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ $b[ $n ] ]['Item'] );
}
TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ $b[2] ]['Item'] );   // remembered in store 222...
$tcg_rows      = ids_of( $b );
$tcg_rows[2]   = row( $b[2], array( 'PrimaryCategory' => array( 'CategoryID' => '183454' ), 'Storefront' => array( 'StoreCategoryID' => '111' ) ) );   // ...but its row says 111
ebay_has( $b[2], 111, $T + 2 * 7200 );
$MEM_SHARE = 0.61;
delta( $tcg_rows );
$MEM_SHARE = 0.2;
check( 'at 61%, a row that came with a category the filter takes is judged by it: it stops the run at the guard', array( preg_match( '/out of (\d+) modified/', summary(), $m ) ? (int) $m[1] : -1, count( get_items() ) ), array( 2, 0 ) );

echo "\n   A page with listings and none kept: the answer leaves out what the filter needs, on every page\n";
shop( array( 'import_standard_category_ids' => array( '253' ) ) );
$tcg_one = burst( 780000000000, 3, 0, $T );
$tcg_two = burst( 780100000000, 3, 0, $T + 7200 );
$EBAY['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => array_map( function ( $id ) { return array( 'ItemID' => $id, 'PrimaryCategory' => array( 'CategoryID' => '11980' ), 'Storefront' => array( 'StoreCategoryID' => '0' ) ); }, $tcg_one ) ) );
delta( ids_of( array_merge( $tcg_one, $tcg_two ) ) );
unset( $EBAY['list_answer'] );
check( 'two ranges, a coin parent ticked, no path: one page, not two; the plan gone, noted as refused, said once', array( lists(), count( get_items() ), (int) get_option( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, 0 ) > 0, logs_with( 'did not say everything your import settings judge a listing by (3 listings on the page, none kept)' ) ), array( 1, 6, true, 1 ) );
$tcg_three = burst( 780200000000, 3, 0, $T + 14400 );
delta( ids_of( $tcg_three ) );
check( '  the next window reads no list', array( lists(), count( get_items() ) ), array( 0, 3 ) );

// ---------------------------------------------------------------------------
echo "\nS10. Rows the memory settles go on past the memory stop (the owner's ruling 6)\n";
shop( $STORE_111 );
$b = burst( 760000000000, 30, 222, $T );
foreach ( $b as $id ) {
	TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ $id ]['Item'] );
}
$MEM_SHARE = 0.61;
delta( ids_of( $b ) );
check( 'at 61% of the memory limit, 30 rows the memory settles: all in one run (after the run that hands the read on), no call', array( summary(), count( get_items() ), lists() ), array( 'Delta sync complete! 0 imported, 0 errors out of 30 modified items, 30 skipped (category filter, remembered).', 0, 0 ) );
$MEM_SHARE = 0.2;

shop( $STORE_111 );
$b = burst( 761000000000, 30, 222, $T, 7200 );   // two hours apart: no range, nothing planned
foreach ( $b as $n => $id ) {
	if ( ! in_array( $n, array( 5, 12, 20 ), true ) ) {
		TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ $id ]['Item'] );
	}
}
$MEM_SHARE = 0.61;
delta( ids_of( $b ) );
$tcg_runs = array_merge( array( array( 0, count( get_items() ), preg_match( '/out of (\d+) modified/', summary(), $m ) ? (int) $m[1] : 0 ) ), array_slice( per_run(), 1 ) );
$MEM_SHARE = 0.2;
check( '  3 rows not remembered among them: each stops a run at the guard, as before - never more than one GetItem a run', array( max( array_column( $tcg_runs, 1 ) ), array_sum( array_column( $tcg_runs, 1 ) ), array_sum( array_column( $tcg_runs, 2 ) ) ), array( 1, 3, 30 ) );
check( '  in 4 runs: 5 rows, 7, 8, then the last 10', array_column( $tcg_runs, 2 ), array( 5, 7, 8, 10 ) );

shop( $STORE_111 );
$b = array();
for ( $i = 0; $i < 450; $i++ ) {
	$b[] = (string) ( 762000000000 + $i );
	TCGiant_Sync_Category_Memory::note( ebay_has( end( $b ), 222, $T + $i * 7200 ) );
}
$MEM_SHARE = 0.61;
delta( ids_of( $b ) );
$tcg_runs = array_merge( array( array( 0, 0, preg_match( '/out of (\d+) modified/', summary(), $m ) ? (int) $m[1] : 0 ) ), array_slice( per_run(), 1 ) );
$MEM_SHARE = 0.2;
check( '  at most DELTA_PAST_GUARD_MAX past it a run: 401 rows, then the other 49', array_column( $tcg_runs, 2 ), array( 1 + TCGiant_Sync_Importer::DELTA_PAST_GUARD_MAX, 450 - 1 - TCGiant_Sync_Importer::DELTA_PAST_GUARD_MAX ) );

shop( $STORE_111 );
$b = burst( 763000000000, 30, 222, $T, 7200 );
foreach ( $b as $id ) {
	TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ $id ]['Item'] );
}
$MEM_SHARE = 0.61;
$LATE      = 100;
delta( ids_of( $b ) );
check( '  out of time as well: one row a run, as before', summary(), 'Delta batch done: 0 imported, 0 errors out of 1 modified items, 1 skipped (category filter, remembered).' );
$LATE = 0;
shop( $STORE_111 );
$b = burst( 764000000000, 30, 222, $T, 7200 );
foreach ( $b as $id ) {
	TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ $id ]['Item'] );
}
$MEM_SHARE   = 0.61;
$tcg_stopped = false;
$ON_UPDATE   = function ( $n ) use ( &$tcg_stopped ) {
	if ( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION === $n && ! $tcg_stopped && ! empty( $GLOBALS['TABLE'][ TCGiant_Sync_Importer::DELTA_WINDOW_CACHE_OPTION ] ) ) {
		$tcg_stopped = true;
		elsewhere( TCGiant_Sync_Importer::STATE_OPTION, array_merge( $GLOBALS['TABLE'][ TCGiant_Sync_Importer::STATE_OPTION ], array( 'status' => 'stopped' ) ) );
	}
};
delta( ids_of( $b ) );
$ON_UPDATE = null;
$MEM_SHARE = 0.2;
check( '  Emergency Stop pressed: no row past the guard', preg_match( '/out of (\d+) modified/', summary(), $m ) ? (int) $m[1] : -1, 1 );

// ---------------------------------------------------------------------------
echo "\nS11. Fix round 2: what a page saves is counted by the window's rows it carries\n";

echo "\n   A listing with a product here is not planned: its GetItem is paid whatever a page says\n";
shop( $STORE_111 );
$b = burst( 790000000000, 5, 111, $T );
foreach ( array( 0, 1, 2 ) as $n ) {
	product( 910 + $n, $b[ $n ], 1 );
}
delta( ids_of( $b ) );
check( 'five rows, three with a product here: two left, under 3 - no list, a GetItem each, as 3.30.0', array( lists(), count( get_items() ), logs_with( 'came with no category' ) ), array( 0, 5, 0 ) );

/**
 * 140 listings ending together, 2 in 7 in store 111 (the filter takes them)
 * and changed, the rest in 222 and not changed; 20 a page, 7 pages. With
 * $own, the changed ones have a product here. The changed ones' IDs.
 */
function mixed_band( $base, $own ) {
	$ids = array();
	for ( $i = 0; $i < 140; $i++ ) {
		$id = (string) ( $base + $i );
		ebay_has( $id, $i % 7 < 2 ? 111 : 222, $GLOBALS['T'] + 2 * $i );
		if ( $i % 7 < 2 ) {
			$ids[] = $id;
			if ( $own ) {
				product( 930 + $i, $id, 1 );
			}
		}
	}
	return $ids;
}
shop( $STORE_111 );
$EBAY['per_page'] = 20;
$mine = mixed_band( 791000000000, true );
delta( ids_of( $mine ) );
check( 'imported listings revised inside a band the filter mostly leaves out (the spot check\'s M1-M3): not planned - no list, a GetItem each, as 3.30.0', array( lists(), count( get_items() ) ), array( 0, 40 ) );
shop( $STORE_111 );
$EBAY['per_page'] = 20;
$new = mixed_band( 792000000000, false );
delta( ids_of( $new ) );
to_the_end();
check( 'new listings the filter takes inside that band (M4): its rows on page 1 all taken - page 1 only, one call over 3.30.0, every one imported', array( lists(), count( get_items() ), saved_ids() ), array( 1, 40, $new ) );

shop( $STORE_111 );
$EBAY['per_page'] = 4;
$tcg_a = burst( 793000000000, 24, 111, $T );            // range A: the filter takes them
$tcg_b = burst( 793100000000, 16, 222, $T + 7200 );     // range B, two hours on: it leaves them out
delta( ids_of( array_merge( $tcg_a, $tcg_b ) ) );
to_the_end();
check( 'one window, range A all taken, range B all left out (M5): A\'s first page only; B begun all the same, and read whole on its own rows', array( array_column( $EBAY['ranges'], 2 ), count( get_items() ) ), array( array( 1, 1, 2, 3, 4 ), 24 ) );

shop( $STORE_111 );
$EBAY['per_page'] = 4;
burst( 794000000000, 4, 222, $T + 1 );            // not changed, and first on the pages
$tcg_r = burst( 794100000000, 12, 222, $T );
delta( ids_of( $tcg_r ) );
to_the_end();
check( 'a range whose first page carries none of its rows: no second page - a range never costs more than a page over 3.30.0\'s GetItem calls', array( lists(), count( get_items() ) ), array( 1, 12 ) );

shop( $STORE_111 );
$EBAY['per_page'] = 10;
$tcg_r = burst( 795000000000, 10, 222, $T );        // every row on page 1
burst( 795100000000, 40, 222, $T + 30 );            // not changed: the range's other pages
delta( ids_of( $tcg_r ) );
check( 'every row of a range answered by its first page: none of its other pages read', array( lists(), count( get_items() ) ), array( 1, 0 ) );

shop( $STORE_111 );
$EBAY['per_page'] = 4;
// 8 rows the plan reads for (222) between 8 imported listings the filter takes (111), changed too.
$tcg_r = array();
for ( $i = 0; $i < 16; $i++ ) {
	$tcg_r[] = (string) ( 795200000000 + $i );
	ebay_has( end( $tcg_r ), 0 === $i % 2 ? 222 : 111, $T + 2 * $i );
	if ( 1 === $i % 2 ) {
		product( 950 + $i, end( $tcg_r ), 1 );
	}
}
delta( ids_of( $tcg_r ) );
check( 'listings with a product here on a range\'s pages do not count against it: every page read, a GetItem only for those 8', array( lists(), count( get_items() ) ), array( 4, 8 ) );
shop( $STORE_111 );
$EBAY['per_page'] = 4;
// Page 1: 3 rows remembered before the window, which the filter leaves out, and 1 the plan reads for; then 19 more of those. The filter takes all 20.
$tcg_r = array();
for ( $i = 0; $i < 23; $i++ ) {
	$tcg_r[] = (string) ( 795300000000 + $i );
	ebay_has( end( $tcg_r ), $i < 3 ? 222 : 111, $T + 2 * $i );
	if ( $i < 3 ) {
		TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ end( $tcg_r ) ]['Item'] );
	}
}
delta( ids_of( $tcg_r ) );
check( 'rows remembered before the window are not counted as the plan\'s: page 1 only', lists(), 1 );

echo "\n   Out of calls, or no token, is not the list failing alone: the plan kept, however long it lasts\n";
foreach ( array(
	'the daily budget' => new WP_Error( 'rate_limited', 'Daily API call budget exhausted. Will resume tomorrow.', array( 'daily_budget' => true ) ),
	'eBay\'s 518'      => new WP_Error( 'rate_limited', 'Call usage limit has been reached.', array( 'Errors' => array( 'ErrorCode' => '518' ) ) ),
	'HTTP 429'         => new WP_Error( 'rate_limited', 'Too many requests.', array( 'retry_after' => 60 ) ),
	'no token'         => new WP_Error( 'no_token', 'No valid eBay token.' ),
) as $label => $tcg_err ) {
	foreach ( array( 'GetItem answers' => null, 'GetItem refused too' => $tcg_err ) as $how => $tcg_item ) {
		shop( $STORE_111 );
		$b = burst( 796000000000, 6, 222, $T );
		$EBAY['list_answer'] = $tcg_err;
		if ( null !== $tcg_item ) {
			$EBAY['item_answer'] = $tcg_item;
		}
		$LATE = 100;
		delta( ids_of( $b ) );
		run_again();
		run_again();
		$p        = get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION );
		$tcg_kept = array( lists(), plan_of( 'page' ), $p['sweep_fails'] ?? 0, logs_with( 'for their categories could not be read' ) );
		unset( $EBAY['list_answer'], $EBAY['item_answer'] );
		$EBAY['get_items'] = array();
		to_the_end();
		$LATE = 0;
		check( '  ' . $label . ', ' . $how . ': three runs, the plan kept, nothing counted', $tcg_kept, array( 3, array( 1 ), 0, 0 ) );
		check( '    then read, once calls come back: the rest with no GetItem', array( lists(), get_items() ), array( 4, array() ) );
	}
}
shop( $STORE_111 );
$b = burst( 797000000000, 6, 222, $T );
$EBAY['list_answer'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$EBAY['item_answer'] = $EBAY['list_answer'];
delta( ids_of( $b ) );
run_again();
run_again();
$tcg_kept = array( lists(), plan_of( 'page' ), logs_with( 'for their categories could not be read' ), logs_with( 'Delta sync: paused at eBay item' ) );
unset( $EBAY['list_answer'], $EBAY['item_answer'] );
check( 'eBay out of reach for the list and GetItem alike, three runs: each run\'s listings met the same failure, so it is not counted - the plan kept, as in 3.30.1 (3.30.2 fix round 1)', $tcg_kept, array( 3, array( 1 ), 0, 3 ) );

echo "\n   A run that waits for a page, or goes on past the memory stop, ends Action Scheduler's run in its request\n";
shop( $STORE_111 );
$EBAY['per_page'] = 20;
// IDs against end times: the lowest ItemID is on the last page.
$b = array();
for ( $i = 59; $i >= 0; $i-- ) {
	$b[] = (string) ( 798000000000 + $i );
	ebay_has( end( $b ), 222, $T + 2 * ( 59 - $i ) );
}
$MEM_SHARE = 0.61;
delta( ids_of( $b ) );
$MEM_SHARE = 0.2;
check( 'at 61%, a run whose first row waits for a page: Action Scheduler told to claim nothing more in this request', array( lists(), logs_with( 'the rest wait for eBay' ), logs_with( 'to stay within this host' ), queue_run_ended() ), array( 1, 1, 0, true ) );
shop( $STORE_111 );
$EBAY['per_page'] = 20;
$b = array();
for ( $i = 59; $i >= 0; $i-- ) {
	$b[] = (string) ( 798100000000 + $i );
	ebay_has( end( $b ), 222, $T + 2 * ( 59 - $i ) );
}
delta( ids_of( $b ) );
check( '  at 20%, one that waits after 25 pages leaves the queue runner be', array( lists() > 0, queue_run_ended() ), array( true, false ) );
shop( $STORE_111 );
$b = array();
for ( $i = 0; $i < 520; $i++ ) {
	$b[] = (string) ( 798200000000 + $i );
	TCGiant_Sync_Category_Memory::note( ebay_has( end( $b ), 222, $T + $i * 7200 ) );
}
$tcg_probe = TCGiant_Sync_Runtime::$memory_probe;
$GLOBALS['PROBES'] = 0;
TCGiant_Sync_Runtime::$memory_probe = function () { $GLOBALS['PROBES']++; return (int) ( max( 1, TCGiant_Sync_Runtime::memory_limit() ) * ( $GLOBALS['PROBES'] > 150 ? 0.61 : 0.2 ) ); };
delta( ids_of( $b ) );
TCGiant_Sync_Runtime::$memory_probe = $tcg_probe;
check( 'memory over the line part way, the rest of the 500-row slice settled past it, more to come: Action Scheduler told too', array( preg_match( '/out of (\d+) modified/', summary(), $m ) ? (int) $m[1] : -1, queued( 'tcgiant_sync_fetch_delta_events' ), queue_run_ended() ), array( 500, 1, true ) );

echo "\n   At the memory stop, a row that came with a category of its own the filter takes is judged by it: one row for each\n";
foreach ( array(
	'PrimaryCategory' => array( 'PrimaryCategory' => array( 'CategoryID' => '261328' ) ),
	'StoreCategoryID' => array( 'Storefront' => array( 'StoreCategoryID' => '111' ) ),
	'StoreCategory2ID' => array( 'Storefront' => array( 'StoreCategory2ID' => '111' ) ),
) as $field => $own ) {
	shop( array( 'category_ids' => '111', 'import_standard_category_ids' => array( '261328' ) ) );
	$b = burst( 799000000000, 6, 222, $T, 7200 );
	foreach ( $b as $id ) {
		TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ $id ]['Item'] );   // remembered in 222: not taken
	}
	$tcg_rows    = ids_of( $b );
	$tcg_rows[2] = row( $b[2], $own );
	$MEM_SHARE   = 0.61;
	delta( $tcg_rows );
	$MEM_SHARE = 0.2;
	check( '  ' . $field . ' alone: it stops the run at the guard, with no GetItem past it', array( preg_match( '/out of (\d+) modified/', summary(), $m ) ? (int) $m[1] : -1, count( get_items() ) ), array( 2, 0 ) );
}

shop( $STORE_111 );
$EBAY['per_page'] = 4;
$b     = burst( 799100000000, 120, 222, $T );                                       // 30 pages: 25 this run
$early = '799000000001';                                                             // the lowest ItemID...
TCGiant_Sync_Category_Memory::note( ebay_has( $early, 111, $T + 2 * 119 + 1 ) );   // ...remembered, the filter takes it, on the last page
delta( ids_of( array_merge( array( $early ), $b ) ) );
check( 'a remembered row the filter takes, its end on a page still to read: it does not wait - its GetItem, and its import, this run', array( get_items(), saved_ids(), logs_with( 'the rest wait for eBay' ) ), array( array( $early ), array( $early ), 1 ) );

// ---------------------------------------------------------------------------
echo "\nS12. 3.30.2: ranges of new listings judged by the last day's; the failure count; another site's rows\n";

/** $n new listings (started ten minutes ago) in store category $cat, ending $step seconds apart from $start. */
function new_burst( $base, $n, $cat, $start, $step = 2 ) {
	$ids = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$ids[] = (string) ( $base + $i );
		ebay_has( end( $ids ), $cat, $start + $i * $step, array( 'ListingDetails' => array( 'StartTime' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 ) ) ) );
	}
	return $ids;
}
function tally() { return get_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, 'none' ); }

echo "\n   A shop that keeps listing in the categories it imports: one page a day, not one a window\n";
shop( $STORE_111 );
$tcg_auto  = array();
$ON_UPDATE = function ( $n, $v, $a ) use ( &$tcg_auto ) { if ( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION === $n ) { $tcg_auto[] = $a; } };
$tcg_w     = array();
for ( $w = 0; $w < 3; $w++ ) {
	$b = new_burst( 800000000000 + $w * 1000, 3, 111, $T + $w * 3600 );
	delta( ids_of( $b ) );
	$tcg_w[] = array( lists(), count( get_items() ), count( saved_ids() ) );
}
check( 'three windows of 3 new listings the filter takes: the first reads its page, the next two none - a GetItem each, all imported', $tcg_w, array( array( 1, 3, 3 ), array( 0, 3, 3 ), array( 0, 3, 3 ) ) );
$ON_UPDATE = null;
$tcg_t     = tally();
check( '  the tally: 9 answered (3 by the page, 6 by GetItem), none left out, since now; never autoloaded', array( array_slice( (array) $tcg_t, 0, 2 ), abs( (int) ( $tcg_t[2] ?? 0 ) - time() ) <= 2, array_values( array_unique( $tcg_auto ) ) ), array( array( 9, 0 ), true, array( false ) ) );
check( '  under the memory\'s prefix, which uninstall sweeps', 0 === strpos( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, TCGiant_Sync_Category_Memory::OPTION_PREFIX ), true );
$b = burst( 801000000000, 5, 222, $T + 5 * 3600 );
delta( ids_of( $b ) );
check( '  then a renewal of 5 the filter leaves out: not judged by the tally - read, no GetItem', array( lists(), count( get_items() ) ), array( 1, 0 ) );
$b = new_burst( 802000000000, 5, 222, $T + 7 * 3600 );
delta( ids_of( $b ) );
$tcg_one = array( lists(), get_items(), logs_with( 'Delta: of 1 new listings asked about with GetItem, the filter left out 1, so the other 4 that end with them wait for eBay\'s list of listings, read next.' ), logs_with( 'listing(s) done this run; the rest wait' ) );
to_the_end();
check( '  5 new listings it leaves out: held by it (5 x 1 < 2 x 11); the first one\'s GetItem says left out, so the other 4 wait for the page (3.30.2 fix round 1)', $tcg_one, array( 0, array( $b[0] ), 1, 1 ) );
check( '    which the next run reads: one page and one GetItem in all, the tally learning from both', array( lists(), count( get_items() ), array_slice( (array) tally(), 0, 2 ) ), array( 1, 1, array( 14, 5 ) ) );
$b = new_burst( 803000000000, 10, 222, $T + 9 * 3600 );
delta( ids_of( $b ) );
check( '  10 more: read at once, on what the tally has learnt (10 x 6 >= 2 x 16)', array( lists(), count( get_items() ), array_slice( (array) tally(), 0, 2 ) ), array( 1, 0, array( 24, 15 ) ) );
$b = array_merge( new_burst( 804000000000, 2, 111, $T + 11 * 3600 ), burst( 804100000000, 1, 111, $T + 11 * 3600 + 10 ) );
update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 30, 0, time() - 60 ) );
delta( ids_of( $b ) );
check( '  a range that is not all new listings is not judged by the tally: read', lists(), 1 );
$b = array_merge( burst( 804200000000, 1, 111, $T + 12 * 3600 ), new_burst( 804300000000, 2, 111, $T + 12 * 3600 + 10 ) );
update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 30, 0, time() - 60 ) );
delta( ids_of( $b ) );
check( '  nor one whose renewal ends first: read', lists(), 1 );
foreach ( array( 4 => 1, 2 => 0 ) as $days => $want ) {
	$b = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$b[] = (string) ( 804400000000 + $days * 100 + $i );
		ebay_has( end( $b ), 111, $T + 13 * 3600 + $days * 600 + 2 * $i, array( 'ListingDetails' => array( 'StartTime' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - $days * DAY_IN_SECONDS ) ) ) );
	}
	update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 30, 0, time() - 60 ) );
	delta( ids_of( $b ) );
	check( '  listings started ' . $days . ' days ago: ' . ( $want ? 'not new listings - read' : 'new listings - held by the tally, no page' ), lists(), $want );
}

shop( $STORE_111 );
update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 300, 0, time() - 60 ) );
$b = new_burst( 805000000000, 43, 111, $T );
delta( ids_of( $b ) );
to_the_end();
check( 'the tally counts as at most 20 answered rows: 43 new listings it takes, left to GetItem (43 < 2 x 22)', array( lists(), count( get_items() ) ), array( 0, 43 ) );
shop( $STORE_111 );
update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 300, 0, time() - 60 ) );
$b = new_burst( 806000000000, 44, 111, $T );
delta( ids_of( $b ) );
check( '  44: its first page tried, whatever the day said', lists(), 1 );
shop( $STORE_111 );
update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 300, 150, time() - 60 ) );
$b = new_burst( 807000000000, 4, 111, $T );
delta( ids_of( $b ) );
check( '  its share kept: 300 answered, 150 left out, counts as 20 and 10 - 4 rows tried (4 x 11 = 2 x 22)', lists(), 1 );
foreach ( array( 'a day old' => time() - TCGiant_Sync_Importer::SWEEP_TALLY_SECONDS, 'dated ahead' => time() + 600 ) as $label => $since ) {
	shop( $STORE_111 );
	update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 30, 0, $since ) );
	$b = new_burst( 808000000000, 3, 111, $T );
	delta( ids_of( $b ) );
	$tcg_t = tally();
	check( 'a tally ' . $label . ': not heeded - the page read; the tally begun again from now', array( lists(), array_slice( (array) $tcg_t, 0, 2 ), abs( (int) ( $tcg_t[2] ?? 0 ) - time() ) <= 2 ), array( 1, array( 3, 0 ), true ) );
}
shop( $STORE_111 );
update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 30, 0, time() - TCGiant_Sync_Importer::SWEEP_TALLY_SECONDS + 60 ) );
$b = new_burst( 809000000000, 3, 111, $T );
delta( ids_of( $b ) );
check( '  a minute short of a day: heeded', lists(), 0 );
foreach ( array( 'not a list' => 'junk', 'two values' => array( 1, 2 ) ) as $label => $junk ) {
	shop( $STORE_111 );
	update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, $junk );
	$b = new_burst( 809100000000, 3, 111, $T );
	delta( ids_of( $b ) );
	check( '  a tally that is ' . $label . ': passed over, the page read', lists(), 1 );
}
shop( $STORE_111 );
$tcg_w = array();
for ( $w = 0; $w < 3; $w++ ) {
	$b = new_burst( 810000000000 + $w * 1000, 3, 222, $T + $w * 3600 );
	delta( ids_of( $b ) );
	$tcg_w[] = array( lists(), count( get_items() ) );
}
check( 'a shop whose new listings the filter leaves out: every window reads its page, as before', $tcg_w, array( array( 1, 0 ), array( 1, 0 ), array( 1, 0 ) ) );
shop( $STORE_111 );
$EBAY['per_page'] = 2;
update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 1, 0, time() - 60 ) );
$b = new_burst( 811000000000, 8, 222, $T );
$LATE = 100;   // a page a run
delta( ids_of( $b ) );
run_again();
$LATE = 0;
$tcg_t = tally();
check( 'a page a run: each run adds what its page answered to the tally', array( lists(), array_slice( (array) $tcg_t, 0, 2 ) ), array( 2, array( 5, 4 ) ) );

shop( $STORE_111 );
$EBAY['per_page'] = 4;
update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 1, 0, time() - 60 ) );
$tcg_a = new_burst( 815000000000, 20, 111, $T );          // range A, read first: the filter takes them
$tcg_b = new_burst( 815100000000, 16, 222, $T + 7200 );   // range B: it leaves them out
$LATE  = 100;   // a page a run
delta( ids_of( array_merge( $tcg_a, $tcg_b ) ) );
$tcg_one = lists();
run_again();
$LATE = 0;
check( 'the tally taken once a window: range B judged by it as the window began (16 x 1 >= 2 x 7), not again with A\'s answers in it - begun', array( $tcg_one, lists(), $EBAY['ranges'][1][0] ?? null ), array( 1, 2, $T + 7200 - 60 ) );
shop( $STORE_111 );
$tcg_r = new_burst( 816000000000, 20, 222, $T );
$EBAY['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => array_map( function ( $id ) { $i = $GLOBALS['EBAY']['items'][ $id ]['Item']; return array( 'ItemID' => $id, 'PrimaryCategory' => $i['PrimaryCategory'], 'Storefront' => $i['Storefront'] ); }, array_slice( $tcg_r, 0, 4 ) ) ), 'PaginationResult' => array( 'TotalNumberOfPages' => '3' ) );
delta( ids_of( $tcg_r ) );
unset( $EBAY['list_answer'] );
check( 'the same listings on every page (eBay\'s pages shifting): each counted once - the tally says 4, not 12', array( lists(), array_slice( (array) tally(), 0, 2 ) ), array( 3, array( 4, 4 ) ) );

shop( $STORE_111 );
update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 30, 0, time() - 60 ) );
$tcg_b = new_burst( 817000000000, 20, 222, $T );          // new listings the filter leaves out: held by the tally
$tcg_a = burst( 817100000000, 5, 222, $T + 7200 );        // a renewal after it, read - and refused
$EBAY['list_answer'] = new WP_Error( 'api_error', 'Input data for tag <GranularityLevel> is invalid or missing.' );
delta( ids_of( array_merge( $tcg_b, $tcg_a ) ) );
unset( $EBAY['list_answer'] );
to_the_end();
check( 'a held range when eBay then refuses the list: let go with the plan - its rows\' GetItem answers bring no page back', array( lists(), count( get_items() ) ), array( 1, 25 ) );
shop( $STORE_111 );
update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 30, 0, time() - 60 ) );
$tcg_r = array();
for ( $i = 0; $i < 10; $i++ ) {   // 8 the filter takes, then 2 it leaves out, by ItemID
	$tcg_r[] = (string) ( 818000000000 + $i );
	ebay_has( end( $tcg_r ), $i < 8 ? 111 : 222, $T + 2 * $i, array( 'ListingDetails' => array( 'StartTime' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 ) ) ) );
}
delta( ids_of( $tcg_r ) );
to_the_end();
check( 'a held range whose rows the filter mostly takes, its 2 left out last: never let go - a GetItem each, no page', array( lists(), count( get_items() ), array_slice( (array) tally(), 0, 2 ) ), array( 0, 10, array( 40, 2 ) ) );

shop( $STORE_111 );
update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 30, 0, time() - 60 ) );
$tcg_r = array();
foreach ( array( 'own', 'own', 'known', 'new', 'new', 'new' ) as $i => $kind ) {   // by ItemID
	$tcg_r[] = (string) ( 819000000000 + $i );
	ebay_has( end( $tcg_r ), 'new' === $kind ? 222 : 111, $T + 2 * $i, 'new' === $kind ? array( 'ListingDetails' => array( 'StartTime' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 ) ) ) : array() );
	if ( 'own' === $kind ) {
		product( 960 + $i, end( $tcg_r ), 1 );
	} elseif ( 'known' === $kind ) {
		TCGiant_Sync_Category_Memory::note( $GLOBALS['EBAY']['items'][ end( $tcg_r ) ]['Item'] );
	}
}
delta( ids_of( $tcg_r ) );
to_the_end();
check( 'a held range of 3 new listings it leaves out, behind 2 with a product here and 1 remembered, all taken: those 3 not counted - the first new one lets the other 2 wait for the page', array( lists(), count( get_items() ) ), array( 1, 4 ) );

shop( $STORE_111 );
$EBAY['per_page'] = 4;
update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 30, 0, time() - 60 ) );
$tcg_r = new_burst( 820000000000, 9, 222, $T );   // 3 pages: 4, 4 and 1
delta( ids_of( $tcg_r ) );
to_the_end();
check( 'a held range of 9 let go after its first row: read for the other 8 only - after 2 pages, 1 row left is not worth the third', array( lists(), get_items() ), array( 2, array( $tcg_r[0], $tcg_r[8] ) ) );

echo "\n   A plan dropped drops its held ranges, whichever way (3.30.2 fix round 2)\n";
/**
 * A window of $ren renewals the filter leaves out (lower ItemIDs, read: the
 * plan), then 20 new listings it leaves out two hours on (held by a tally
 * of 30 taken). The ItemIDs of the renewals come first, so the held rows
 * are asked about only after the plan is dropped.
 */
function held_window( $base, $ren = 5, $per = 200 ) {
	shop( $GLOBALS['STORE_111'] );
	$GLOBALS['EBAY']['per_page'] = $per;
	update_option( TCGiant_Sync_Importer::SWEEP_TALLY_OPTION, array( 30, 0, time() - 60 ) );
	$r = burst( $base, $ren, 222, $GLOBALS['T'] );
	$n = new_burst( $base + 1000, 20, 222, $GLOBALS['T'] + 7200 );
	return array_merge( $r, $n );
}
function held_now() { $p = get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION ); return is_array( $p ) ? count( (array) ( $p['sweep_held'] ?? array() ) ) : -1; }

$b = held_window( 821000000000, 5, 4 );   // the renewals' range: 2 pages
$LATE = 100;   // a page a run
delta( ids_of( $b ) );
$tcg_was = held_now();
$p = get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION );
$p['sweep_reading'] = 1;   // the next run killed reading a page
update_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION, $p );
run_again();
$tcg_now = held_now();
$LATE = 0;
to_the_end();
check( 'killed reading a page: the plan dropped, its held range with it - no page for the new listings after', array( $tcg_was, $tcg_now, lists() ), array( 1, 0, 1 ) );

$b = held_window( 822000000000 );
$EBAY['list_answer'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$LATE = 100;
delta( ids_of( $b ) );
$tcg_was = held_now();
run_again();
$tcg_now = held_now();
unset( $EBAY['list_answer'] );
$LATE = 0;
to_the_end();
check( 'two failures of the list alone: the plan dropped, its held range with it - no page after', array( $tcg_was, $tcg_now, lists() ), array( 1, 0, 2 ) );

$b = held_window( 823000000000, 9, 4 );   // the renewals' range: 3 pages
$LATE = 100;
delta( ids_of( $b ) );
$tcg_was = held_now();
$CAN_IMPORT = false;
run_again();
$tcg_now = held_now();
$LATE = 0;
check( 'the free limit reached: the plan dropped, its held range with it', array( $tcg_was, $tcg_now ), array( 1, 0 ) );
$CAN_IMPORT = true;

$b = held_window( 824000000000 );
$EBAY['list_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => array_map( function ( $id ) { return array( 'ItemID' => $id, 'Storefront' => array( 'StoreCategoryID' => '222' ) ); }, array_slice( $b, 0, 5 ) ) ) );
$LATE = 100;
delta( ids_of( $b ) );
$tcg_now = held_now();
$LATE = 0;
to_the_end();
unset( $EBAY['list_answer'] );
check( 'a page that keeps nothing: the plan dropped, its held range with it - no page after', array( $tcg_now, lists() ), array( 0, 1 ) );

shop( $STORE_111 );
$tcg_a = burst( 825000000000, 20, 111, $T );          // renewals the filter takes, read first
$tcg_b = burst( 825100000000, 3, 222, $T + 7200 );    // renewals it leaves out, skipped by the window rule
delta( ids_of( array_merge( $tcg_a, $tcg_b ) ) );
to_the_end();
check( 'a range of renewals the window rule skips is not held: its rows go to GetItem, and their answers bring no page', array( lists(), count( get_items() ) ), array( 1, 23 ) );

echo "\n   The failure count after a pause\n";
shop( $STORE_111 );
$b = burst( 812000000000, 6, 222, $T );
$EBAY['list_answer'] = new WP_Error( 'rate_limited', 'Internal error to the application.', array( 'Errors' => array( 'ErrorCode' => '10007' ) ) );
$EBAY['item_answer'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
delta( ids_of( $b ) );
run_again();
run_again();
$tcg_kept = array( lists(), plan_of( 'page' ), logs_with( 'could not be read 2 times in a row (Internal error to the application.)' ) );
unset( $EBAY['list_answer'], $EBAY['item_answer'] );
check( 'the list failing (10007) while GetItem times out and pauses the window, every run: the run\'s listings failed too - not counted, the plan kept (the coordinator\'s ruling)', $tcg_kept, array( 3, array( 1 ), 0 ) );
foreach ( array(
	'calls'     => new WP_Error( 'rate_limited', 'Daily API call budget exhausted. Will resume tomorrow.', array( 'daily_budget' => true ) ),
	'the token' => new WP_Error( 'no_token', 'No valid eBay token.' ),
) as $label => $tcg_err ) {
	shop( $STORE_111 );
	$b = burst( 813000000000, 6, 222, $T );
	$EBAY['list_answer'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	$LATE = 100;
	delta( ids_of( $b ) );   // the list times out: one; GetItem answers
	$EBAY['list_answer'] = $EBAY['item_answer'] = $tcg_err;
	run_again();   // not counted, and the window pauses for it
	$tcg_mid = ( get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION )['sweep_fails'] ?? 0 );
	$EBAY['list_answer'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	unset( $EBAY['item_answer'] );
	run_again();   // a timeout again: one, not two
	$LATE = 0;
	unset( $EBAY['list_answer'] );
	check( 'a timeout, then a pause for ' . $label . ', then a timeout: the pause started the count again - the plan kept', array( $tcg_mid, plan_of( 'page' ), get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION )['sweep_fails'] ?? 0 ), array( 0, array( 1 ), 1 ) );
}
foreach ( array(
	'invalid XML' => new WP_Error( 'invalid_xml', 'eBay sent an answer that could not be read.' ),
	'eBay\'s 10007' => new WP_Error( 'rate_limited', 'Internal error to the application.', array( 'Errors' => array( 'ErrorCode' => '10007' ) ) ),
) as $label => $tcg_err ) {
	shop( $STORE_111 );
	$b = burst( 813100000000, 6, 222, $T );
	$EBAY['list_answer'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	$LATE = 100;
	delta( ids_of( $b ) );   // the list times out: one; GetItem answers
	$EBAY['item_answer'] = $tcg_err;
	run_again();   // the list times out again, and GetItem fails otherwise: the list's own, so two
	$LATE = 0;
	unset( $EBAY['list_answer'], $EBAY['item_answer'] );
	check( 'a list timeout, then a list timeout with GetItem pausing the window for ' . $label . ': an outage of any passing kind for both - the second not counted, the plan kept (the coordinator\'s ruling)', array( lists(), plan_of( 'page' ), get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION )['sweep_fails'] ?? 0, logs_with( 'could not be read 2 times in a row' ) ), array( 2, array( 1 ), 1, 0 ) );
}
foreach ( array(
	'invalid XML' => new WP_Error( 'invalid_xml', 'eBay sent an answer that could not be read.' ),
	'eBay\'s 10007' => new WP_Error( 'rate_limited', 'Internal error to the application.', array( 'Errors' => array( 'ErrorCode' => '10007' ) ) ),
) as $label => $tcg_err ) {
	shop( $STORE_111 );
	$b = burst( 813200000000, 6, 222, $T );
	$EBAY['list_answer'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	$LATE = 100;
	delta( ids_of( $b ) );   // the list times out: one; GetItem answers
	$EBAY['list_answer'] = new WP_Error( 'rate_limited', 'Daily API call budget exhausted. Will resume tomorrow.', array( 'daily_budget' => true ) );
	$EBAY['item_answer'] = $tcg_err;
	run_again();   // the list out of calls: not counted; GetItem pauses the window for something else
	$tcg_mid = ( get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION )['sweep_fails'] ?? 0 );
	$EBAY['list_answer'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	unset( $EBAY['item_answer'] );
	run_again();   // a timeout again: two
	$LATE = 0;
	unset( $EBAY['list_answer'] );
	check( 'a timeout, a pause for ' . $label . ', a timeout: that pause does not start the count again - two, and the plan goes', array( $tcg_mid, plan_of( 'page' ) ), array( 1, array() ) );
}

echo "\n   Another site's rows on a range's pages are not counted as the plan's\n";
shop( $STORE_111 + array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' ) );
$EBAY['per_page'] = 4;
$tcg_r = array();
$tcg_n = 0;
foreach ( array( 'UK', 'UK', 'UK', 'US', 'UK', 'UK', 'UK', 'US' ) as $site ) {
	$tcg_r[] = (string) ( 814000000000 + $tcg_n );
	ebay_has( end( $tcg_r ), 'UK' === $site ? 222 : 111, $T + 2 * $tcg_n++, array( 'Site' => $site ) );
}
for ( $i = 0; $i < 18; $i++ ) {
	$tcg_r[] = (string) ( 814000000000 + $tcg_n );
	ebay_has( end( $tcg_r ), 111, $T + 2 * $tcg_n++ );
}
delta( ids_of( $tcg_r ) );
check( 'a range of 20 rows the filter takes, its first pages 3 in 4 another site\'s, left out: page 1 only - those not counted as saved', array( lists(), false !== strpos( summary(), '6 left out (on other eBay sites)' ) ), array( 1, true ) );

// ---------------------------------------------------------------------------
echo "\nTHE CODE\n";
$imp_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-importer.php' );
check( 'the refused note is written not autoloaded', 1 === preg_match( '/update_option\( self::SWEEP_REFUSED_OPTION, time\(\), false \);/', $imp_src ), true );
check( '  under the memory\'s prefix, which uninstall sweeps', 0 === strpos( TCGiant_Sync_Importer::SWEEP_REFUSED_OPTION, TCGiant_Sync_Category_Memory::OPTION_PREFIX ), true );
check( 'a range spans under 120 days, as eBay requires', TCGiant_Sync_Importer::SWEEP_SPAN_SECONDS + 120 < 120 * DAY, true );

echo "\n  $pass passed, $fail failed\n\n";
exit( $fail > 0 ? 1 : 0 );
