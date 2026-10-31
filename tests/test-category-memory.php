<?php
/**
 * Harness: the delta's memory of the listings' categories (3.28.4).
 *
 * eBay's list of changes sends most rows with no category. A shop that imports
 * only some categories had the delta ask GetItem for each such row only to
 * learn its category, and then mostly skip it: on the live-test shop, which
 * imports 2 store categories out of 5,466 listings, 2,212 GetItem calls in 5
 * days for about 10 imports. TCGiant_Sync_Category_Memory keeps each listing's
 * categories from the full sync's pages and from the GetItem answers the
 * plugin already gets, and the delta skips a bare row whose remembered
 * categories the filter does not take - with no GetItem, and otherwise
 * exactly as the filter skipped it after one.
 *
 * Loaded for real: TCGiant_Sync_Category_Memory, TCGiant_Sync_Importer (the
 * full sync, the delta, the import, the settles), TCGiant_Sync_Inventory,
 * TCGiant_Sync_Sites and TCGiant_Sync_Runtime, in the prune world
 * (support/prune-world.php): posts and post meta in SQLite, options as
 * WordPress keeps them. eBay is played here, and counted.
 *
 * "Today" below is the same run with nothing remembered, which is 3.28.3's
 * path: GetItem, then the filter.
 *
 * @package TCGiant_Sync
 */

/**
 * eBay: the prune world's GetItem and GetSellerList, with GetSellerEvents,
 * GetStore and the delta's few other questions. Every call is counted.
 */
class TCGiant_Sync_API {
	const DAILY_CALL_LIMIT        = 50000;
	const DAILY_CALL_SOFT_CEILING = 0.80;
	const MARKETPLACES = array(
		'EBAY_US' => array( 'label' => 'United States', 'site' => 'US' ),
		'EBAY_GB' => array( 'label' => 'United Kingdom', 'site' => 'UK' ),
	);
	public static function scalar_value( $value ) { return is_scalar( $value ) ? (string) $value : ''; }
	public static function instance() { static $i; return $i ?: ( $i = new self() ); }
	public static function get_events_byte_limit() { return 26843545; }
	public function get_last_body_bytes() { return 2048; }
	public function get_remaining_daily_budget() { return 40000; }
	public function with_stock_priority( callable $fn ) { return $fn(); }
	public function get_item( $item_id ) {
		$GLOBALS['EBAY']['calls'][]     = 'GetItem ' . $item_id;
		$GLOBALS['EBAY']['get_items'][] = (string) $item_id;
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
		$GLOBALS['EBAY']['calls'][]      = 'GetSellerList ' . $page;
		$GLOBALS['EBAY']['list_calls'][] = array( $page, $mod_from, $end_from );
		return call_user_func( $GLOBALS['EBAY']['lister'], $page, $per, $mod_from, $end_from );
	}
	public function get_seller_events( $from, $to, $max_body_bytes = 0 ) {
		$GLOBALS['EBAY']['calls'][] = 'GetSellerEvents';
		if ( ! empty( $GLOBALS['EBAY']['events_throw'] ) ) {
			throw new RuntimeException( 'killed while reading' );
		}
		$rows = $GLOBALS['EBAY']['events'] ?? array();
		return array( 'Ack' => 'Success', 'ItemArray' => empty( $rows ) ? array() : array( 'Item' => $rows ) );
	}
	public function get_store() {
		$GLOBALS['EBAY']['calls'][] = 'GetStore';
		return array( 'Store' => array( 'CustomCategories' => array( 'CustomCategory' => $GLOBALS['EBAY']['store'] ?? array() ) ) );
	}
}

// WordPress's record of options it found missing, which a fresh read must
// clear for the option it reads (the prune world has no object cache of its
// own beyond this request's copy of the options).
$NOTOPTIONS = array();
function wp_cache_get( $key, $group = '' ) { return 'notoptions' === $key && 'options' === $group ? $GLOBALS['NOTOPTIONS'] : false; }
function wp_cache_set( $key, $value, $group = '' ) { if ( 'notoptions' === $key && 'options' === $group ) { $GLOBALS['NOTOPTIONS'] = $value; } return true; }

require __DIR__ . '/support/prune-world.php';

TCGiant_Sync_Importer::$page_pause = 0;

$LOCK  = sys_get_temp_dir() . '/tcgiant_sync.lock';
$SHIFT = 0; // Seconds the memory's clock is ahead of the real one.
TCGiant_Sync_Category_Memory::$clock = function () { return time() + (int) $GLOBALS['SHIFT']; };

const DAY = 86400;

// The filters a shop sets.
$STORE_111 = array( 'category_ids' => '111' );                              // a store category, by its number
$BY_NAME   = array( 'category_ids' => 'Pokemon' );                          // the same, by its name
$STANDARD  = array( 'import_standard_category_ids' => array( '183454' ) );  // an eBay category
$BOTH      = $STORE_111 + $STANDARD;
$PRIMARY   = array( 'category_ids' => '261328' );                           // a custom number that is an eBay category

// The listings, and their categories: primary, store, store 2.
$LC = array(
	'110000000001' => array( '183454', '111', '0' ),   // A: in store 111, eBay 183454
	'110000000002' => array( '183454', '222', '0' ),   // B: in store 222, eBay 183454
	'110000000003' => array( '261328', '333', '111' ), // C: in store 111 as its second store category
	'110000000004' => array( '261328', '222', '0' ),   // D: in store 222, eBay 261328
	'110000000005' => array( '38292', '1', '0' ),      // E: in neither
);
const A = '110000000001';
const B = '110000000002';
const C = '110000000003';
const D = '110000000004';
const E = '110000000005';

/** What a static of the importer remembers for the length of a request: the free limit, said once. */
function forget_statics() {
	$p = new ReflectionProperty( 'TCGiant_Sync_Importer', 'limit_reported' );
	if ( PHP_VERSION_ID < 80100 ) {
		$p->setAccessible( true );
	}
	$p->setValue( null, false );
}

/** A shop with a filter: a fresh world, the store's categories, nothing remembered. */
function shop( array $settings ) {
	fresh_world( $settings );
	forget_statics();
	$GLOBALS['EBAY']['calls']  = array();
	$GLOBALS['EBAY']['events'] = array();
	$GLOBALS['EBAY']['store']  = array( array( 'Name' => 'Pokemon', 'CategoryID' => '111' ), array( 'Name' => 'Coins', 'CategoryID' => '222' ) );
	$GLOBALS['ON_UPDATE']  = null;
	$GLOBALS['CAN_IMPORT'] = true;
	$GLOBALS['SHIFT']      = 0;
	$GLOBALS['NOTOPTIONS'] = array();
	// A cache delete reads the table again, as WordPress's does.
	$GLOBALS['CACHE_READS_TABLE'] = true;
	@unlink( $GLOBALS['LOCK'] );
	foreach ( $GLOBALS['LC'] as $id => $cats ) {
		ebay_has( $id, $cats );
	}
}

/** Change the shop's settings, as saving the form does. */
function settings( array $settings ) {
	update_option( 'tcgiant_sync_ebay_settings', $settings );
}

/**
 * A listing as GetItem and GetSellerList's ReturnAll send it: whole, with its
 * categories. Also what GetItem answers for it from now on.
 */
function ebay_has( $id, $cats, $status = 'Active', $listed = 1, $sold = 0, array $extra = array() ) {
	$item = array_replace_recursive( array(
		'ItemID'          => $id,
		'Title'           => 'Item ' . $id,
		'Quantity'        => (string) $listed,
		'SellingStatus'   => array( 'ListingStatus' => $status, 'QuantitySold' => (string) $sold ),
		'ListingDetails'  => array( 'StartTime' => '2026-09-01T10:00:00.000Z', 'EndTime' => '2026-12-01T10:00:00.000Z' ),
		'ItemSpecifics'   => array( 'NameValueList' => array() ),
		'PictureDetails'  => array( 'PictureURL' => 'https://i.ebayimg.com/' . $id . '.jpg' ),
	), $extra );
	if ( null !== $cats ) {
		$item['PrimaryCategory'] = array( 'CategoryID' => $cats[0] );
		$item['Storefront']      = array( 'StoreCategoryID' => $cats[1], 'StoreCategory2ID' => $cats[2] );
	}
	$GLOBALS['EBAY']['items'][ $id ] = array( 'Ack' => 'Success', 'Item' => $item );
	return $item;
}

/** What eBay holds for a listing, as a GetSellerList row. */
function whole( $id ) {
	return $GLOBALS['EBAY']['items'][ $id ]['Item'];
}

/** A row of eBay's list of changes as it usually comes: no category, no specifics, no pictures. */
function bare( $id, $status = 'Active', array $over = array() ) {
	return array_replace_recursive( array(
		'ItemID'        => $id,
		'Quantity'      => '1',
		'SellingStatus' => array( 'ListingStatus' => $status, 'QuantitySold' => '0' ),
	), $over );
}

/** The full sync over one page of listings. */
function full_sync( array $items, array $on_page = array() ) {
	$GLOBALS['EBAY']['lister'] = function ( $page ) use ( $items, $on_page ) {
		if ( isset( $on_page[ $page ] ) ) {
			call_user_func( $on_page[ $page ] );
		}
		return 1 === $page && $items ? array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $items ), 'PaginationResult' => array( 'TotalNumberOfPages' => 1 ) ) : array( 'Ack' => 'Success' );
	};
	$GLOBALS['SAVED'] = array();
	@unlink( $GLOBALS['LOCK'] );
	// Two syncs begun in the same second are one to the importer, and the
	// second never reads a page: here they are a week apart.
	update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'scan_finished' => '' ) ) );
	new_request();
	$GLOBALS['IMP']->start_full_sync( true );
	run_queue( 'tcgiant_sync_scan_all_pages' );
}

/** One delta run over a window of changed listings. */
function delta( array $rows ) {
	$GLOBALS['EBAY']['events']    = $rows;
	$GLOBALS['EBAY']['calls']     = array();
	$GLOBALS['EBAY']['get_items'] = array();
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
	$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
	new_request();
	forget_statics();
	$GLOBALS['IMP']->fetch_delta_events();
}

function saved_ids() { return array_values( array_unique( array_map( function ( $s ) { return $s[0]; }, $GLOBALS['SAVED'] ) ) ); }
function get_items() { return $GLOBALS['EBAY']['get_items']; }
function calls_of( $kind ) { return count( array_filter( $GLOBALS['EBAY']['calls'], function ( $c ) use ( $kind ) { return 0 === strpos( $c, $kind ); } ) ); }
function summary() {
	foreach ( array_reverse( $GLOBALS['LOG'] ) as $l ) {
		if ( 0 === strpos( $l[1], 'Delta sync complete!' ) || 0 === strpos( $l[1], 'Delta batch done:' ) ) {
			return $l[1];
		}
	}
	return '';
}

/** What the memory says of a listing now: its three categories, or null. */
function remembered( $id ) {
	new_request();
	$r = TCGiant_Sync_Category_Memory::recall( array( $id ) );
	return $r[ $id ] ?? null;
}
/** The memory's options, as the table holds them. */
function memory_options() {
	return array_filter( $GLOBALS['TABLE'], function ( $k ) { return 0 === strpos( $k, TCGiant_Sync_Category_Memory::OPTION_PREFIX ); }, ARRAY_FILTER_USE_KEY );
}
function memory_lines() {
	$lines = array();
	foreach ( memory_options() as $v ) {
		$lines = array_merge( $lines, explode( "\n", (string) $v ) );
	}
	return $lines;
}
/** The line the memory holds for a listing, '' if none. */
function line_of( $id ) {
	foreach ( memory_lines() as $l ) {
		if ( 0 === strpos( $l, $id . ',' ) ) {
			return $l;
		}
	}
	return '';
}
/** Make everything remembered learnt $secs earlier than it was. */
function age_memory( $secs ) {
	foreach ( memory_options() as $name => $value ) {
		$lines = array();
		foreach ( explode( "
", (string) $value ) as $l ) {
			$at      = strrpos( $l, ',' );
			$lines[] = substr( $l, 0, $at + 1 ) . ( (int) substr( $l, $at + 1 ) - $secs );
		}
		update_option( $name, implode( "
", $lines ) );
	}
}
/** Write a memory option by hand, as another version or a corruption would leave it. */
function plant( $id, $line ) {
	$name = TCGiant_Sync_Category_Memory::option_name( TCGiant_Sync_Category_Memory::chunk_of( $id ) );
	$old  = (string) ( $GLOBALS['TABLE'][ $name ] ?? '' );
	update_option( $name, ( '' === $old ? '' : $old . "\n" ) . $line );
}
/** Every write of a memory option from here on: name, autoload, how many saved by then. */
function watch_memory_writes() {
	$GLOBALS['MEM_WRITES'] = array();
	$GLOBALS['ON_UPDATE']  = function ( $n, $v, $a ) {
		if ( 0 === strpos( $n, TCGiant_Sync_Category_Memory::OPTION_PREFIX ) ) {
			$GLOBALS['MEM_WRITES'][] = array( $n, $a, count( $GLOBALS['SAVED'] ) );
		}
	};
}

// ---------------------------------------------------------------------------
echo "\nM1. The full sync fills the memory: every listing on its pages, matched or not\n";
shop( $STORE_111 );
watch_memory_writes();
full_sync( array( whole( A ), whole( B ), whole( C ), whole( D ) ) );
check( 'the matched listings are imported, as before', saved_ids(), array( A, C ) );
check( 'every listing on the page is remembered, matched or not', array( remembered( A ), remembered( B ), remembered( C ), remembered( D ) ), array( $LC[ A ], $LC[ B ], $LC[ C ], $LC[ D ] ) );
check( '  one line a listing, four in all', count( memory_lines() ), 4 );
check( '  and a listing that was not on the page is not', remembered( E ), null );
check( 'no new calls: no GetItem, one GetSellerList', array( calls_of( 'GetItem' ), calls_of( 'GetSellerList' ) ), array( 0, 1 ) );
check( 'written as the batch ends, after its listings are saved', array_values( array_unique( array_column( $MEM_WRITES, 2 ) ) ), array( 2 ) );
check( '  once for each option the page\'s listings fall to', count( $MEM_WRITES ), count( array_unique( array_map( array( 'TCGiant_Sync_Category_Memory', 'chunk_of' ), array( A, B, C, D ) ) ) ) );

echo "\n   Two pages in one batch: each option written once, not once a page\n";
shop( $STORE_111 );
// Listings in the same few options, on two pages.
$tcg_two = array( 1 => array(), 2 => array() );
for ( $n = 700000000000; count( $tcg_two[2] ) < 6; $n++ ) {
	if ( TCGiant_Sync_Category_Memory::chunk_of( (string) $n ) < 2 ) {
		$tcg_two[ count( $tcg_two[1] ) < 6 ? 1 : 2 ][] = ebay_has( (string) $n, array( '261328', '222', '0' ) );
	}
}
watch_memory_writes();
$EBAY['lister'] = function ( $page ) use ( $tcg_two ) {
	return isset( $tcg_two[ $page ] ) ? array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $tcg_two[ $page ] ), 'PaginationResult' => array( 'TotalNumberOfPages' => 2 ) ) : array( 'Ack' => 'Success' );
};
@unlink( $LOCK );
update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'scan_finished' => '' ) ) );
new_request();
$IMP->start_full_sync( true );
run_queue( 'tcgiant_sync_scan_all_pages' );
check( 'both pages read in one batch, all twelve remembered', array( calls_of( 'GetSellerList' ), count( memory_lines() ) ), array( 2, 12 ) );
check( '  the two options each written once', array( count( $MEM_WRITES ), count( array_unique( array_column( $MEM_WRITES, 0 ) ) ) ), array( 2, 2 ) );

echo "\n   A batch that learns PENDING_MAX listings writes them then, and goes on\n";
shop( $STORE_111 );
watch_memory_writes();
$tcg_rows = array();
for ( $i = 0; $i < TCGiant_Sync_Category_Memory::PENDING_MAX; $i++ ) {
	$tcg_rows[] = array( 'ItemID' => (string) ( 710000000000 + $i ), 'PrimaryCategory' => array( 'CategoryID' => '1' ) );
}
TCGiant_Sync_Category_Memory::begin();
TCGiant_Sync_Category_Memory::note_page( array_slice( $tcg_rows, 0, -1 ) );
$tcg_before = count( $MEM_WRITES );
TCGiant_Sync_Category_Memory::note_page( array_slice( $tcg_rows, -1 ) );
$tcg_at = count( $MEM_WRITES );
TCGiant_Sync_Category_Memory::end();
check( 'one short of PENDING_MAX: nothing written yet; at it, written', array( $tcg_before, $tcg_at > 0, count( memory_lines() ) ), array( 0, true, TCGiant_Sync_Category_Memory::PENDING_MAX ) );
check( '  and the batch\'s end has nothing left to write', count( $MEM_WRITES ), $tcg_at );
shop( $STORE_111 );
watch_memory_writes();
TCGiant_Sync_Category_Memory::begin();
for ( $i = 0; $i < TCGiant_Sync_Category_Memory::PENDING_MAX - 1; $i++ ) {
	TCGiant_Sync_Category_Memory::note( $tcg_rows[ $i ] );
}
$tcg_before = count( $MEM_WRITES );
TCGiant_Sync_Category_Memory::note( end( $tcg_rows ) );
$tcg_at = count( $MEM_WRITES );
TCGiant_Sync_Category_Memory::end();
check( 'the same for GetItem answers held by a delta batch', array( $tcg_before, $tcg_at > 0 ), array( 0, true ) );
check( '  never autoloaded', array_values( array_unique( array_column( $MEM_WRITES, 1 ) ) ), array( false ) );
check( '  in options of the plugin\'s own name, which uninstall sweeps', array_values( array_unique( array_map( function ( $n ) { return preg_replace( '/\d+$/', '', $n ); }, array_keys( memory_options() ) ) ) ), array( 'tcgiant_sync_category_memory_' ) );

echo "\n   A page read again, after a batch hands the scan on, writes only what is new on it\n";
shop( $STORE_111 );
watch_memory_writes();
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 100; // each batch past its time: one listing, then a hand-off
full_sync( array( whole( A ), whole( B ), whole( C ) ) );
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
check( 'the page took three batches, each reading it', array( calls_of( 'GetSellerList' ), saved_ids() ), array( 3, array( A, C ) ) );
check( '  its listings written once, on the first', count( $MEM_WRITES ), count( array_unique( array_map( array( 'TCGiant_Sync_Category_Memory', 'chunk_of' ), array( A, B, C ) ) ) ) );
check( '  and all of them remembered', array( remembered( A ), remembered( B ), remembered( C ) ), array( $LC[ A ], $LC[ B ], $LC[ C ] ) );

echo "\n   A shop that does not filter has no use for it\n";
shop( array() );
watch_memory_writes();
full_sync( array( whole( A ), whole( B ), whole( C ), whole( D ) ) );
check( 'everything imported, nothing remembered', array( saved_ids(), memory_options() ), array( array( A, B, C, D ), array() ) );
check( '  nothing even written along the way', $MEM_WRITES, array() );
TCGiant_Sync_Category_Memory::note( whole( A ) );
check( '  nor learnt from a GetItem answer', memory_options(), array() );

// ---------------------------------------------------------------------------
echo "\nM2. A changed listing the filter does not take, remembered: skipped with no GetItem\n";
shop( $STORE_111 );
full_sync( array( whole( A ), whole( B ), whole( C ), whole( D ) ) );
delta( array( bare( B ), bare( D ) ) );
check( 'no GetItem', get_items(), array() );
check( '  nothing imported', saved_ids(), array() );
check( '  the summary says so, and counts no GetItem fallbacks', summary(), 'Delta sync complete! 0 imported, 0 errors out of 2 modified items, 2 skipped (category filter, remembered).' );
check( '  no eBay call at all beyond the list of changes', $EBAY['calls'], array( 'GetSellerEvents', 'GetStore' ) );

// ---------------------------------------------------------------------------
echo "\nM3. Not remembered, or remembered too long ago: one GetItem, as before\n";
shop( $STORE_111 );
delta( array( bare( D ) ) );
check( 'not remembered: one GetItem', get_items(), array( D ) );
check( '  skipped by the filter after it, as before', summary(), 'Delta sync complete! 0 imported, 0 errors out of 1 modified items, 1 skipped (category filter), 1 GetItem fallbacks.' );
check( '  and its answer is remembered', remembered( D ), $LC[ D ] );
delta( array( bare( D ) ) );
check( '  so the next change to it costs no GetItem', array( get_items(), summary() ), array( array(), 'Delta sync complete! 0 imported, 0 errors out of 1 modified items, 1 skipped (category filter, remembered).' ) );

$SHIFT = 8 * DAY;
delta( array( bare( D ) ) );
check( 'learnt exactly 8 days ago: still fresh, no GetItem', get_items(), array() );
$SHIFT = 8 * DAY + 1;
delta( array( bare( D ) ) );
check( 'learnt 8 days and a second ago: stale, one GetItem', get_items(), array( D ) );
check( '  skipped after it as before', saved_ids(), array() );
delta( array( bare( D ) ) );
check( '  and learnt afresh by it', get_items(), array() );

echo "\n   A time learnt ahead of the clock\n";
shop( $STORE_111 );
$SHIFT = 2 * 3600;   // learnt while the clock read two hours on
TCGiant_Sync_Category_Memory::note( whole( D ) );
$SHIFT = 0;
delta( array( bare( D ) ) );
check( 'more than an hour ahead: not trusted, one GetItem', get_items(), array( D ) );
check( '  and its answer, the same categories, is written learnt now', (int) substr( line_of( D ), strrpos( line_of( D ), ',' ) + 1 ) <= time(), true );
delta( array( bare( D ) ) );
check( '  so it counts from then on', get_items(), array() );
shop( $STORE_111 );
$SHIFT = 1800;
TCGiant_Sync_Category_Memory::note( whole( D ) );
$SHIFT = 0;
delta( array( bare( D ) ) );
check( 'half an hour ahead: within the clocks\' leeway, no GetItem', get_items(), array() );

// ---------------------------------------------------------------------------
echo "\nM4. Remembered and taken by the filter: still one GetItem, for the whole listing\n";
shop( $STORE_111 );
full_sync( array( whole( A ), whole( B ), whole( C ), whole( D ) ) );
delta( array( bare( A ), bare( C ) ) );
check( 'one GetItem each', get_items(), array( A, C ) );
check( '  imported from eBay\'s whole answer', array_map( function ( $s ) { return $s[0] . ' ' . $s[1]; }, $SAVED ), array( A . ' Active', C . ' Active' ) );
check( '  the summary counts them as before', summary(), 'Delta sync complete! 2 imported, 0 errors out of 2 modified items, 2 GetItem fallbacks.' );

echo "\n   The seller moved it out of the category since: the GetItem says, as before\n";
ebay_has( A, array( '183454', '222', '0' ) );
delta( array( bare( A ) ) );
check( 'one GetItem, then skipped by the filter', array( get_items(), saved_ids() ), array( array( A ), array() ) );
check( '  and the move remembered', remembered( A ), array( '183454', '222', '0' ) );

echo "\n   Moved into the category since: missed until the memory learns it\n";
ebay_has( D, array( '261328', '111', '0' ) );
delta( array( bare( D ) ) );
check( 'skipped on what is remembered, with no GetItem: the risk taken', array( get_items(), saved_ids() ), array( array(), array() ) );
full_sync( array( whole( A ), whole( B ), whole( C ), whole( D ) ) );
check( '  the next full sync imports it, and learns the move', array( in_array( D, saved_ids(), true ), remembered( D ) ), array( true, array( '261328', '111', '0' ) ) );

// ---------------------------------------------------------------------------
echo "\nM5. The shop changes its filter: nothing to clear\n";
shop( $STORE_111 );
full_sync( array( whole( A ), whole( B ), whole( C ), whole( D ), whole( E ) ) );
$before = memory_options();
settings( array( 'category_ids' => '222' ) );
check( 'saving the filter leaves the memory as it was', memory_options(), $before );
delta( array( bare( A ), bare( B ), bare( C ), bare( D ), bare( E ) ) );
check( 'store 222 now: its two listings asked about and imported', array( get_items(), saved_ids() ), array( array( B, D ), array( B, D ) ) );
check( '  store 111\'s skipped as remembered', summary(), 'Delta sync complete! 2 imported, 0 errors out of 5 modified items, 3 skipped (category filter, remembered), 2 GetItem fallbacks.' );
settings( $STANDARD );
delta( array( bare( A ), bare( B ), bare( C ), bare( D ), bare( E ) ) );
check( 'eBay category 183454 now: A and B', array( get_items(), saved_ids() ), array( array( A, B ), array( A, B ) ) );
settings( $BY_NAME );
delta( array( bare( A ), bare( B ), bare( C ), bare( D ), bare( E ) ) );
check( 'store "Pokemon", by name: A and C', array( get_items(), saved_ids() ), array( array( A, C ), array( A, C ) ) );
settings( array() );
delta( array( bare( A ), bare( B ) ) );
check( 'no filter: nothing is looked up, both imported from GetItem as before', array( get_items(), saved_ids() ), array( array( A, B ), array( A, B ) ) );

// ---------------------------------------------------------------------------
echo "\nM6. What the delta does before the filter, and to linked and ended listings, is unchanged\n";

/**
 * One delta over linked, ended, other-site and new listings, with the memory
 * full or empty. Empty is today: GetItem, then the filter.
 */
function before_the_filter( $remember, $can_import = true ) {
	shop( array( 'category_ids' => '111', 'import_sites' => 'primary', 'marketplace' => 'EBAY_US', 'remove_sold_products' => '1' ) );
	$GLOBALS['CAN_IMPORT'] = $can_import;
	$live = array(
		'210000000001' => array( 'cats' => array( '183454', '111', '0' ), 'product' => 501, 'status' => 'Active' ),    // linked, in the filter
		'210000000002' => array( 'cats' => array( '183454', '222', '0' ), 'product' => 502, 'status' => 'Active' ),    // linked, outside it (imported before the filter)
		'210000000003' => array( 'cats' => array( '183454', '222', '0' ), 'product' => 503, 'status' => 'Completed' ), // linked, outside it, sold out
		'210000000004' => array( 'cats' => array( '183454', '111', '0' ), 'product' => 504, 'status' => 'Completed' ), // linked, in it, sold out
		'210000000005' => array( 'cats' => array( '183454', '222', '0' ), 'product' => 0, 'status' => 'Completed' ),   // ended, nothing follows it
		'210000000006' => array( 'cats' => array( '183454', '111', '0' ), 'product' => 0, 'status' => 'Active' ),      // new, in the filter
		'210000000007' => array( 'cats' => array( '183454', '222', '0' ), 'product' => 0, 'status' => 'Active' ),      // new, outside it
		'210000000008' => array( 'cats' => array( '183454', '111', '0' ), 'product' => 0, 'status' => 'Active', 'site' => 'UK' ),   // another site's
		'210000000009' => array( 'cats' => array( '183454', '222', '0' ), 'product' => 509, 'status' => 'Active', 'site' => 'UK' ), // another site's, linked, outside
	);
	$rows  = array();
	$known = array();
	foreach ( $live as $id => $l ) {
		$sold = 'Completed' === $l['status'] ? 2 : 0;
		$item = ebay_has( (string) $id, $l['cats'], $l['status'], 2, $sold, isset( $l['site'] ) ? array( 'Site' => $l['site'] ) : array( 'Site' => 'US' ) );
		if ( $l['product'] ) {
			product( $l['product'], (string) $id, 2 );
		}
		$known[] = $item;
		$rows[]  = bare( (string) $id, $l['status'], array_merge( array( 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => (string) $sold ) ), isset( $l['site'] ) ? array( 'Site' => $l['site'] ) : array() ) );
	}
	if ( $remember ) {
		TCGiant_Sync_Category_Memory::note_page( $known );
	}
	delta( $rows );
	$out = array( 'saved' => saved_ids(), 'get_items' => get_items(), 'summary' => summary() );
	foreach ( array( 501, 502, 503, 504, 509 ) as $p ) {
		$out['products'][ $p ] = array( stock( $p ), meta( $p, '_ebay_listing_status' ), post_status( $p ), meta( $p, '_ebay_site' ) );
	}
	$out['log'] = array_values( array_filter( array_map( function ( $l ) { return $l[1]; }, $GLOBALS['LOG'] ), function ( $m ) {
		return 0 !== strpos( $m, 'Delta sync complete!' ) && 0 !== strpos( $m, 'Delta fallback: Used GetItem' );
	} ) );
	return $out;
}

$today = before_the_filter( false );
$now   = before_the_filter( true );
check( 'the same listings saved', $now['saved'], $today['saved'] );
check( '  which are the linked one in the filter and the new one in it', $today['saved'], array( '210000000001', '210000000006' ) );
check( 'every linked product as today: stock, status, trash, site', $now['products'], $today['products'] );
check( '  the sold-out one in the filter settled (stock 0, ended, trashed)', $today['products'][504], array( 0, 'Ended', 'trash', 'US' ) );
check( '  the linked ones outside the filter left as they were, today and now', array( $today['products'][502], $today['products'][503] ), array( array( 2, 'Active', 'publish', '' ), array( 2, 'Active', 'publish', '' ) ) );
check( 'the same log, but for the summary', $now['log'], $today['log'] );
check( 'today: a GetItem for every row but the other site\'s with no product', $today['get_items'], array( '210000000001', '210000000002', '210000000003', '210000000004', '210000000005', '210000000006', '210000000007', '210000000009' ) );
check( 'now: only for the ones the filter takes', $now['get_items'], array( '210000000001', '210000000004', '210000000006' ) );
check( '  the summary, today', $today['summary'], 'Delta sync complete! 2 imported, 0 errors out of 9 modified items, 6 skipped (category filter), 1 left out (on other eBay sites), 8 GetItem fallbacks.' );
check( '  the summary, now', $now['summary'], 'Delta sync complete! 2 imported, 0 errors out of 9 modified items, 1 skipped (category filter), 5 skipped (category filter, remembered), 1 left out (on other eBay sites), 3 GetItem fallbacks.' );

echo "\n   At the free limit\n";
$today = before_the_filter( false, false );
$now   = before_the_filter( true, false );
check( 'the same listings saved: only linked ones', array( $now['saved'], $today['saved'] ), array( array( '210000000001' ), array( '210000000001' ) ) );
check( '  the same products', $now['products'], $today['products'] );
check( '  the same log, the limit said once', array( $now['log'] === $today['log'], count( array_filter( $now['log'], function ( $m ) { return 0 === strpos( $m, 'Import limit reached' ); } ) ) ), array( true, 1 ) );
check( '  a new listing is held back before the filter, with no GetItem, either way', array( in_array( '210000000006', $today['get_items'], true ), in_array( '210000000006', $now['get_items'], true ) ), array( false, false ) );

// ---------------------------------------------------------------------------
echo "\nM7. The size cap, and the pruning\n";
// 1,300 listings that all fall to one option.
$same = array();
for ( $n = 300000000000; count( $same ) < 1300; $n++ ) {
	if ( 0 === TCGiant_Sync_Category_Memory::chunk_of( (string) $n ) ) {
		$same[] = (string) $n;
	}
}
shop( $STORE_111 );
$SHIFT = -3600;
TCGiant_Sync_Category_Memory::note_page( array_map( function ( $id ) { return array( 'ItemID' => $id, 'PrimaryCategory' => array( 'CategoryID' => '183454' ) ); }, array_slice( $same, 0, 100 ) ) );
$SHIFT = 0;
TCGiant_Sync_Category_Memory::note_page( array_map( function ( $id ) { return array( 'ItemID' => $id, 'PrimaryCategory' => array( 'CategoryID' => '183454' ) ); }, array_slice( $same, 100 ) ) );
$held = explode( "\n", (string) get_option( TCGiant_Sync_Category_Memory::option_name( 0 ) ) );
check( 'one option holds at most CHUNK_MAX listings', count( $held ), TCGiant_Sync_Category_Memory::CHUNK_MAX );
check( '  the ones learnt longest ago went first', array( count( array_filter( array_slice( $same, 0, 100 ), 'line_of' ) ), count( array_filter( array_slice( $same, 100 ), 'line_of' ) ) ), array( 50, 1200 ) );
check( '  CHUNK_MAX x CHUNKS is 40,000 listings', TCGiant_Sync_Category_Memory::CHUNK_MAX * TCGiant_Sync_Category_Memory::CHUNKS, 40000 );

echo "\n   15,000 listings\n";
shop( $STORE_111 );
$t0 = microtime( true );
for ( $page = 0; $page < 75; $page++ ) {
	$rows = array();
	for ( $i = 0; $i < 200; $i++ ) {
		$id     = (string) ( 260000000000 + $page * 200 + $i );
		$rows[] = array( 'ItemID' => $id, 'PrimaryCategory' => array( 'CategoryID' => '183454' ), 'Storefront' => array( 'StoreCategoryID' => (string) ( 35470714014 + $i % 7 ), 'StoreCategory2ID' => '0' ) );
	}
	TCGiant_Sync_Category_Memory::note_page( $rows );
}
$secs  = microtime( true ) - $t0;
$sizes = array_map( 'strlen', memory_options() );
check( 'all 15,000 remembered', count( memory_lines() ), 15000 );
check( '  under 1 MB in all', array_sum( $sizes ) < 1048576, true );
check( '  and no option over 40 KB', max( $sizes ) < 40960, true );
$t0  = microtime( true );
$ids = array();
for ( $i = 0; $i < 40; $i++ ) {
	$ids[] = (string) ( 260000000000 + $i * 373 );
}
$got = TCGiant_Sync_Category_Memory::recall( $ids );
check( 'a delta batch of 40 looked up', count( $got ), 40 );
printf( "      (15,000 listings: %d KB in %d options, largest %d KB, %.1f s to write 75 pages, %.1f ms for 40 lookups)\n", array_sum( $sizes ) / 1024, count( $sizes ), max( $sizes ) / 1024, $secs, ( microtime( true ) - $t0 ) * 1000 );

echo "\n   A full sync that read every page drops what it did not see\n";
shop( $STORE_111 );
full_sync( array( whole( A ), whole( B ), whole( C ), whole( D ) ) );
age_memory( 3 * 3600 ); // the last full sync, a while ago
$SHIFT = -2 * 3600;      // and a GetItem since, of a listing that ended before this one
TCGiant_Sync_Category_Memory::note( ebay_has( '110000000006', array( '183454', '222', '0' ) ) );
$SHIFT = 0;
ebay_has( '110000000007', array( '183454', '222', '0' ) );
full_sync( array( whole( A ), whole( B ), whole( C ) ), array( 1 => function () {
	// A GetItem while this sync runs, of a listing not on its pages.
	TCGiant_Sync_Category_Memory::note( whole( '110000000007' ) );
} ) );
check( 'what it read stays', array( remembered( A ), remembered( B ), remembered( C ) ), array( $LC[ A ], $LC[ B ], $LC[ C ] ) );
check( 'a listing it did not read goes', array( remembered( D ), line_of( D ) ), array( null, '' ) );
check( '  and one learnt from a GetItem before it began', line_of( '110000000006' ), '' );
check( '  but not one learnt from a GetItem since it began', remembered( '110000000007' ), array( '183454', '222', '0' ) );

echo "\n   One learnt from a GetItem lately, the same as the sync reads: kept\n";
shop( $STORE_111 );
$SHIFT = -2 * 3600;   // a GetItem before the sync began, which agrees with it
TCGiant_Sync_Category_Memory::note( whole( D ) );
$SHIFT = 0;
full_sync( array( whole( D ) ) );
check( 'written learnt now by the page, so the sync\'s end keeps it', remembered( D ), $LC[ D ] );

echo "\n   A server whose clock is a little behind\n";
shop( $STORE_111 );
$SHIFT = -30;   // the requests reading the pages, 30 s behind the one that began the sync
full_sync( array( whole( A ), whole( B ) ) );
$SHIFT = 0;
check( 'what it read, learnt 30 s "before" the sync began, stays', array( remembered( A ), remembered( B ) ), array( $LC[ A ], $LC[ B ] ) );
shop( $STORE_111 );
$SHIFT = -30 * 60;
TCGiant_Sync_Category_Memory::note( whole( D ) );   // half an hour before, by a GetItem
$SHIFT = -2 * 3600;
TCGiant_Sync_Category_Memory::note( whole( C ) );   // two hours before
$SHIFT = 0;
full_sync( array( whole( A ) ) );
check( '  one learnt within the hour before it, not seen: kept (still true of a listing that ended)', remembered( D ), $LC[ D ] );
check( '  one learnt longer before: gone', line_of( C ), '' );

echo "\n   Only a full sync that read every page\n";
shop( $STORE_111 );
full_sync( array( whole( A ), whole( D ) ) );
age_memory( 3 * 3600 );
// Resume Import's page scan of a delta reads only what changed: never clean.
update_option( 'tcgiant_sync_state', array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'status' => 'rate_limited', 'sync_mode' => 'delta', 'delta_mod_from' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 ), 'total_pages' => 0, 'current_page' => 1 ) ) );
$EBAY['lister'] = function ( $page ) { return 1 === $page ? array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => array( whole( A ) ) ), 'PaginationResult' => array( 'TotalNumberOfPages' => 1 ) ) : array( 'Ack' => 'Success' ); };
@unlink( $LOCK );
new_request();
TCGiant_Sync_Importer::instance()->scan_all_pages();
check( 'a delta\'s page scan prunes nothing', remembered( D ), $LC[ D ] );
// Emergency Stop pressed while the page is read.
full_sync( array( whole( A ) ), array( 1 => function () { elsewhere( 'tcgiant_sync_state', array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'status' => 'stopped' ) ) ); } ) );
check( 'nor one stopped part way', remembered( D ), $LC[ D ] );

echo "\n   The filter turned off\n";
settings( array() );
full_sync( array( whole( A ), whole( D ) ) );
check( 'the next full sync deletes the memory', memory_options(), array() );

echo "\n   What is no longer fresh goes when its option is next written\n";
shop( $STORE_111 );
$SHIFT = -9 * DAY;
TCGiant_Sync_Category_Memory::note( whole( D ) );
$SHIFT = 0;
$stale_chunk = TCGiant_Sync_Category_Memory::chunk_of( D );
$fresh_id    = '';
for ( $n = 500000000000; '' === $fresh_id; $n++ ) {
	if ( TCGiant_Sync_Category_Memory::chunk_of( (string) $n ) === $stale_chunk ) {
		$fresh_id = (string) $n;
	}
}
check( 'held, though stale', '' !== line_of( D ), true );
TCGiant_Sync_Category_Memory::note( array( 'ItemID' => $fresh_id, 'PrimaryCategory' => array( 'CategoryID' => '1' ) ) );
check( '  gone once its option is written', array( line_of( D ), '' !== line_of( $fresh_id ) ), array( '', true ) );
plant( D, D . ',261328,222,0,' . ( time() - 9 * DAY ) );
TCGiant_Sync_Category_Memory::note( array( 'ItemID' => $fresh_id, 'PrimaryCategory' => array( 'CategoryID' => '1' ) ) );
check( '  even by an answer that changes nothing else in it', array( line_of( D ), '' !== line_of( $fresh_id ) ), array( '', true ) );
// An option left with nothing is deleted, not kept empty.
shop( $STORE_111 );
$SHIFT = -2 * 3600;
TCGiant_Sync_Category_Memory::note( whole( D ) );
$SHIFT = 0;
TCGiant_Sync_Category_Memory::after_full_scan( gmdate( 'Y-m-d\TH:i:s.000\Z' ) );
check( 'an option emptied by the pruning is deleted', array_key_exists( TCGiant_Sync_Category_Memory::option_name( TCGiant_Sync_Category_Memory::chunk_of( D ) ), $TABLE ), false );
TCGiant_Sync_Category_Memory::note( whole( D ) );
check( 'a time that cannot be read prunes nothing', array( TCGiant_Sync_Category_Memory::after_full_scan( '' ), remembered( D ) ), array( 0, $LC[ D ] ) );

// ---------------------------------------------------------------------------
echo "\nM8. The full sync and the delta judge a listing the same way\n";
$parity = array();
foreach ( array( 'store 111' => $STORE_111, 'store "Pokemon"' => $BY_NAME, 'eBay 183454' => $STANDARD, 'both' => $BOTH, 'custom 261328' => $PRIMARY ) as $label => $filter ) {
	$all = array( A, B, C, D, E );
	// The full sync, which judges each listing from its page.
	shop( $filter );
	full_sync( array_map( 'whole', $all ) );
	$by_scan = saved_ids();
	// The delta on what it remembered.
	delta( array_map( 'bare', $all ) );
	$by_memory = array( saved_ids(), get_items() );
	// The delta with nothing remembered: GetItem, then the filter.
	shop( $filter );
	delta( array_map( 'bare', $all ) );
	$by_get = array( saved_ids(), get_items() );
	check( $label . ': the delta takes what the full sync takes, remembered or not', array( $by_memory[0], $by_get[0] ), array( $by_scan, $by_scan ) );
	check( '  and asks GetItem, remembering, only of those', $by_memory[1], $by_scan );
	$parity[ $label ] = $by_scan;
}
check( 'the five filters take different listings', $parity, array( 'store 111' => array( A, C ), 'store "Pokemon"' => array( A, C ), 'eBay 183454' => array( A, B ), 'both' => array( A, B, C ), 'custom 261328' => array( C, D ) ) );

// ---------------------------------------------------------------------------
echo "\nM9. Learnt from the GetItem answers the plugin already gets, with no call of its own\n";
shop( $STORE_111 );
update_option( 'tcgiant_sync_state', array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'status' => 'importing', 'sync_mode' => 'specific', 'total_queued' => 1 ) ) );
$EBAY['calls'] = array();
$IMP->process_item_import( D );
check( 'an import: its one GetItem, and the listing remembered', array( $EBAY['calls'], remembered( D ) ), array( array( 'GetItem ' . D ), $LC[ D ] ) );

shop( $STORE_111 );
product( 601, B, 1 );
ebay_has( B, $LC[ B ], 'Completed', 1, 1 );
$EBAY['calls'] = array();
$IMP->settle_item( B );
check( 'a sale check: its one GetItem, and the listing remembered', array( $EBAY['calls'], remembered( B ) ), array( array( 'GetItem ' . B ), $LC[ B ] ) );
shop( $STORE_111 );
$EBAY['calls'] = array();
$IMP->settle_item( D );   // nothing follows it
check( '  one nothing follows too', array( $EBAY['calls'], remembered( D ) ), array( array( 'GetItem ' . D ), $LC[ D ] ) );

shop( $STORE_111 );
// A row that came with its primary category, so the filter needs no GetItem,
// but without the listing's specifics: the delta's other GetItem.
delta( array( bare( A, 'Active', array( 'PrimaryCategory' => array( 'CategoryID' => '183454' ), 'Storefront' => array( 'StoreCategoryID' => '111' ) ) ) ) );
check( 'the delta\'s GetItem for the whole listing', array( get_items(), remembered( A ) ), array( array( A ), $LC[ A ] ) );

shop( $STORE_111 );
product( 602, C, 1 );
ebay_has( C, $LC[ C ], 'Completed', 1, 1 );
// Ended, with its categories but not its figures: the settle's GetItem.
delta( array( array( 'ItemID' => C, 'SellingStatus' => array( 'ListingStatus' => 'Completed' ), 'PrimaryCategory' => array( 'CategoryID' => '261328' ), 'Storefront' => array( 'StoreCategoryID' => '333', 'StoreCategory2ID' => '111' ) ) ) );
check( 'the settle of an ended listing', array( get_items(), remembered( C ), stock( 602 ) ), array( array( C ), $LC[ C ], 0 ) );

shop( $STORE_111 );
// Ended, nothing follows it, started an hour ago: is it a relist?
ebay_has( E, array( '38292', '111', '0' ), 'Completed', 1, 1, array( 'ListingDetails' => array( 'StartTime' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 3600 ) ) ) );
delta( array( array( 'ItemID' => E, 'Quantity' => '1', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ), 'ListingDetails' => array( 'StartTime' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 3600 ) ), 'Storefront' => array( 'StoreCategoryID' => '111' ) ) ) );
check( 'the relist check of an ended listing', array( get_items(), remembered( E ) ), array( array( E ), array( '38292', '111', '0' ) ) );

echo "\n   A GetItem that says what is remembered writes nothing for a day\n";
shop( $STORE_111 );
TCGiant_Sync_Category_Memory::note( whole( D ) );
$first = line_of( D );
watch_memory_writes();
$SHIFT = DAY - 1;
TCGiant_Sync_Category_Memory::note( whole( D ) );
check( 'within the day: no write', array( count( $MEM_WRITES ), line_of( D ) ), array( 0, $first ) );
$SHIFT = DAY;
TCGiant_Sync_Category_Memory::note( whole( D ) );
check( 'a day on: written, learnt then', array( count( $MEM_WRITES ), line_of( D ) !== $first ), array( 1, true ) );
$SHIFT = DAY + 8 * DAY;
check( '  so it is fresh for 8 days from then', remembered( D ), $LC[ D ] );
$SHIFT = 2 * 3600;
TCGiant_Sync_Category_Memory::note( ebay_has( E, $LC[ E ] ) );
$SHIFT = 0;
TCGiant_Sync_Category_Memory::note( whole( E ) );
check( 'the same, learnt ahead of the clock: written learnt now', remembered( E ), $LC[ E ] );
ebay_has( D, array( '261328', '111', '0' ) );
$SHIFT = DAY + 60;
TCGiant_Sync_Category_Memory::note( whole( D ) );
check( 'different categories: written at once', remembered( D ), array( '261328', '111', '0' ) );

// ---------------------------------------------------------------------------
echo "\nM10. A delta batch writes once, at its end, however it ends\n";
shop( $STORE_111 );
ebay_has( '110000000008', array( '183454', '222', '0' ) );
$MID = null;
$EBAY['on_get_item'] = function ( $id ) {
	if ( '110000000008' === $id ) {
		$GLOBALS['MID'] = line_of( D );
	}
};
delta( array( bare( D ), bare( '110000000008' ) ) );
check( 'the first GetItem\'s answer is not written while the batch runs', $MID, '' );
check( '  but is when it ends', array( remembered( D ), remembered( '110000000008' ) ), array( $LC[ D ], array( '183454', '222', '0' ) ) );

shop( $STORE_111 );
$EBAY['on_get_item'] = null;
$writes = 0;
$ON_UPDATE = function ( $n ) use ( &$writes ) {
	if ( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION === $n && ++$writes > 2 ) {
		throw new RuntimeException( 'the database went away' );
	}
};
$threw = '';
try {
	delta( array( bare( D ), bare( B ) ) );
} catch ( RuntimeException $e ) {
	$threw = $e->getMessage();
}
$ON_UPDATE = null;
check( 'a run thrown out of part way, on its second listing', array( $threw, get_items() ), array( 'the database went away', array( B ) ) );
check( '  still writes what it learnt from its first', remembered( B ), $LC[ B ] );
TCGiant_Sync_Category_Memory::note( whole( A ) );
check( '  and leaves nothing held for what comes after it in the request', line_of( A ) !== '', true );

// ---------------------------------------------------------------------------
echo "\nM11. Only what can be kept exactly\n";
shop( $STORE_111 );
TCGiant_Sync_Category_Memory::note( ebay_has( D, array( 'x261328', '222', '0' ) ) );
check( 'a category that is not digits: not remembered, nor written', array( remembered( D ), line_of( D ) ), array( null, '' ) );
ebay_has( D, $LC[ D ] );
TCGiant_Sync_Category_Memory::note( array( 'ItemID' => 'D-1', 'PrimaryCategory' => array( 'CategoryID' => '1' ) ) );
check( 'an ItemID that is not digits: not remembered', memory_options(), array() );
TCGiant_Sync_Category_Memory::note( array( 'ItemID' => D, 'PrimaryCategory' => array( 'CategoryID' => '' ), 'Storefront' => array( 'StoreCategoryID' => '', 'StoreCategory2ID' => '' ) ) );
check( 'no category at all: not remembered, nor written', array( remembered( D ), line_of( D ) ), array( null, '' ) );
TCGiant_Sync_Category_Memory::note( 'not an item' );
check( 'an answer that is not a listing: nothing', memory_options(), array() );
// A primary category that puts "ItemID,primary" in the same option as the
// ItemID itself, so the lookup would find the line if it looked.
for ( $p = 1; TCGiant_Sync_Category_Memory::chunk_of( E . ',' . $p ) !== TCGiant_Sync_Category_Memory::chunk_of( E ); $p++ );
TCGiant_Sync_Category_Memory::note( ebay_has( E, array( (string) $p, '1', '0' ) ) );
check( 'a lookup by something that is not an ItemID finds nothing, not the line it begins', array( remembered( E . ',' . $p ), remembered( E ) ), array( null, array( (string) $p, '1', '0' ) ) );
ebay_has( E, $LC[ E ] );
// eBay gives no category: the delta imports it, as it always has.
ebay_has( D, null );
delta( array( bare( D ) ) );
check( '  and the delta imports a listing eBay gives no category for, as before', array( get_items(), saved_ids() ), array( array( D ), array( D ) ) );
delta( array( bare( D ) ) );
check( '  asking each time', get_items(), array( D ) );

echo "\n   A line that is not one\n";
shop( $STORE_111 );
plant( D, 'garbage' );
plant( D, D . ',,,,' . time() );
plant( D, D . ',261328,222,0' );
check( 'a line with no time, or no category: not used', remembered( D ), null );
delta( array( bare( D ) ) );
check( '  so the delta asks, as before', get_items(), array( D ) );
check( '  and the option is written clean', explode( "\n", (string) get_option( TCGiant_Sync_Category_Memory::option_name( TCGiant_Sync_Category_Memory::chunk_of( D ) ) ) ), array( line_of( D ) ) );

// ---------------------------------------------------------------------------
echo "\nM12. Each write merges what the database holds, not this request's old copy\n";
shop( $STORE_111 );
// Three listings in D's option.
$tcg_same = array();
for ( $n = 720000000000; count( $tcg_same ) < 2; $n++ ) {
	if ( TCGiant_Sync_Category_Memory::chunk_of( (string) $n ) === TCGiant_Sync_Category_Memory::chunk_of( D ) ) {
		$tcg_same[] = (string) $n;
	}
}
$tcg_name = TCGiant_Sync_Category_Memory::option_name( TCGiant_Sync_Category_Memory::chunk_of( D ) );
TCGiant_Sync_Category_Memory::note( whole( D ) );
get_option( $tcg_name );   // this request's copy, as a long batch holds it
// Another request - a sale check, a queued import - learns a listing meanwhile.
elsewhere( $tcg_name, $TABLE[ $tcg_name ] . "\n" . $tcg_same[0] . ',261328,222,0,' . time() );
TCGiant_Sync_Category_Memory::note( array( 'ItemID' => $tcg_same[1], 'PrimaryCategory' => array( 'CategoryID' => '1' ) ) );
check( 'what another request wrote meanwhile is kept', array( '' !== line_of( D ), '' !== line_of( $tcg_same[0] ), '' !== line_of( $tcg_same[1] ) ), array( true, true, true ) );
// An option this request found missing, and another request has written since.
shop( $STORE_111 );
$NOTOPTIONS = array( $tcg_name => true, 'some_other_option' => true );
TCGiant_Sync_Category_Memory::note( whole( D ) );
check( '  and WordPress\'s record of it as missing is cleared, for that option only', $NOTOPTIONS, array( 'some_other_option' => true ) );

// ---------------------------------------------------------------------------
echo "\nM13. forget(): what a push makes out of date\n";
shop( $STORE_111 );
TCGiant_Sync_Category_Memory::note( whole( D ) );
TCGiant_Sync_Category_Memory::note( whole( '110000000003' ) );
$tcg_d_name = TCGiant_Sync_Category_Memory::option_name( TCGiant_Sync_Category_Memory::chunk_of( D ) );
watch_memory_writes();
TCGiant_Sync_Category_Memory::forget( array( D, '', 'not-an-id' ) );
check( 'the listing is forgotten, another kept', array( remembered( D ), remembered( C ) ), array( null, $LC[ C ] ) );
check( '  its option, left empty, deleted', array_key_exists( $tcg_d_name, $TABLE ) || TCGiant_Sync_Category_Memory::chunk_of( D ) === TCGiant_Sync_Category_Memory::chunk_of( C ), false );
// One not held, in the option that still holds C.
for ( $tcg_absent = 730000000000; TCGiant_Sync_Category_Memory::chunk_of( (string) $tcg_absent ) !== TCGiant_Sync_Category_Memory::chunk_of( C ); $tcg_absent++ );
TCGiant_Sync_Category_Memory::forget( array( D, (string) $tcg_absent ) );
check( '  forgetting ones not held writes nothing', count( $MEM_WRITES ), 0 );
TCGiant_Sync_Category_Memory::begin();
TCGiant_Sync_Category_Memory::note( whole( E ) );
TCGiant_Sync_Category_Memory::forget( array( E ) );
TCGiant_Sync_Category_Memory::end();
check( '  and one learnt in a batch, then forgotten in it, is not written at its end', remembered( E ), null );

// ---------------------------------------------------------------------------
echo "\nM14. A ticked coin parent takes the listings in its subcategories (3.28.5)\n";
// eBay files no listing under the five coin categories the plugin's list
// offers: each is a parent. Ticked, one matched nothing. eBay's path for a
// listing's category names the parent second, and the full sync's pages and
// GetItem carry it; the memory keeps which parent each coin category is
// under, so a remembered listing is decided without a GetItem.
const F = '110000000006'; // a Lincoln cent, under Coins: US
const G = '110000000007'; // a Canadian dollar, under Coins: Canada
const H = '110000000008'; // a card
const I = '110000000009'; // another Lincoln cent, never on a page
const PATH_US = 'Coins & Paper Money:Coins: US:Small Cents:Lincoln (1959-Now)';
$COINS_US = array( 'import_standard_category_ids' => array( '253' ) );
/** A listing with eBay's path for its category, as GetItem and the full sync's pages give it. */
function ebay_has_path( $id, $cats, $path ) {
	ebay_has( $id, $cats );
	$GLOBALS['EBAY']['items'][ $id ]['Item']['PrimaryCategory']['CategoryName'] = $path;
	return whole( $id );
}
function coin_shop( array $settings ) {
	shop( $settings );
	ebay_has_path( F, array( '11980', '0', '0' ), PATH_US );
	ebay_has_path( G, array( '3390', '0', '0' ), 'Coins & Paper Money:Coins: Canada:Dollars' );
	ebay_has_path( H, array( '183454', '0', '0' ), 'Toys & Hobbies:Collectible Card Games:CCG Individual Cards' );
	ebay_has_path( I, array( '11980', '0', '0' ), PATH_US );
}
coin_shop( $COINS_US );
watch_memory_writes();
full_sync( array( whole( F ), whole( G ), whole( H ) ) );
check( 'Coins: US (253) ticked, the full sync: the Lincoln cent imported, by its path; the Canadian dollar and the card not', saved_ids(), array( F ) );
check( '  and which parent each coin category is under kept, the one not ticked too; the card\'s not', array( TCGiant_Sync_Category_Memory::coin_parent( '11980' ), TCGiant_Sync_Category_Memory::coin_parent( '3390' ), TCGiant_Sync_Category_Memory::coin_parent( '183454' ) ), array( '253', '3377', '' ) );
check( '  in one option of the memory\'s name, not autoloaded, written once', array( count( array_filter( $MEM_WRITES, function ( $w ) { return TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION === $w[0]; } ) ), array_values( array_unique( array_column( $MEM_WRITES, 1 ) ) ), $TABLE[ TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION ] ?? null ), array( 1, array( false ), array( '11980' => '253', '3390' => '3377' ) ) );
check( '  still no GetItem', calls_of( 'GetItem' ), 0 );
delta( array( bare( G ), bare( H ) ) );
check( 'the delta, remembered and under no ticked parent: skipped with no GetItem', array( get_items(), saved_ids(), summary() ), array( array(), array(), 'Delta sync complete! 0 imported, 0 errors out of 2 modified items, 2 skipped (category filter, remembered).' ) );
delta( array( bare( F ) ) );
check( '  remembered under the ticked one: its GetItem, as for any listing the filter takes, and imported from it', array( get_items(), saved_ids() ), array( array( F ), array( F ) ) );
delta( array( bare( I ) ) );
check( 'not remembered: one GetItem, whose path the filter takes', array( get_items(), saved_ids() ), array( array( I ), array( I ) ) );
settings( array( 'import_standard_category_ids' => array( '253', '3377' ) ) );
delta( array( bare( G ) ) );
check( 'Coins: Canada ticked as well: the Canadian dollar, remembered, now taken', array( get_items(), saved_ids() ), array( array( G ), array( G ) ) );
settings( array( 'import_standard_category_ids' => array( '11980' ) ) );
delta( array( bare( F ), bare( G ) ) );
check( 'the leaf itself ticked: taken by its ID, as before; the other skipped', array( get_items(), saved_ids() ), array( array( F ), array( F ) ) );

echo "\n   In one delta batch, and from a row that carries its path\n";
coin_shop( $COINS_US );
TCGiant_Sync_Category_Memory::note( whole( H ) ); // the memory in use
const J = '110000000010'; // a third Lincoln cent, remembered by its category alone
ebay_has_path( J, array( '11980', '0', '0' ), PATH_US );
plant( J, J . ',11980,0,0,' . time() );
delta( array( bare( I ), bare( J ) ) );
check( 'a GetItem earlier in the batch teaches the category: a listing remembered by it alone is then decided by it', array( get_items(), saved_ids() ), array( array( I, J ), array( I, J ) ) );
coin_shop( $COINS_US );
TCGiant_Sync_Category_Memory::note( whole( H ) );
$tcg_row = bare( F, 'Active', array( 'PrimaryCategory' => array( 'CategoryID' => '11980', 'CategoryName' => PATH_US ), 'Storefront' => array( 'StoreCategoryID' => '0', 'StoreCategory2ID' => '0' ) ) );
delta( array( $tcg_row ) );
check( 'a row of the list of changes that carries its category and path: taken by the path, nothing remembered needed', saved_ids(), array( F ) );

echo "\n   Only eBay's full path decides over what was learnt (the review's L4)\n";
coin_shop( $COINS_US );
TCGiant_Sync_Category_Memory::note( whole( F ) ); // 11980 learnt under 253
check( 'a name that is not the full path: what was learnt stands', array( TCGiant_Sync_Category_Memory::coin_parent( '11980', 'Lincoln (1959-Now)' ), TCGiant_Sync_Category_Memory::coin_parent( '11980', 'Small Cents:Lincoln (1959-Now)' ) ), array( '253', '253' ) );
check( '  the full path decides, even against it', array( TCGiant_Sync_Category_Memory::coin_parent( '11980', PATH_US ), TCGiant_Sync_Category_Memory::coin_parent( '11980', 'Coins & Paper Money:Coins: Canada:Dollars' ), TCGiant_Sync_Category_Memory::coin_parent( '11980', 'Coins & Paper Money:Paper Money: US' ) ), array( '253', '3377', '' ) );
$tcg_row = bare( F, 'Active', array( 'PrimaryCategory' => array( 'CategoryID' => '11980', 'CategoryName' => 'Lincoln (1959-Now)' ), 'Storefront' => array( 'StoreCategoryID' => '0', 'StoreCategory2ID' => '0' ) ) );
delta( array( $tcg_row ) );
check( '  a row of the list of changes with a short name: taken by what was learnt, as the full sync takes it', saved_ids(), array( F ) );

echo "\n   Each write merges what the database holds\n";
coin_shop( $COINS_US );
TCGiant_Sync_Category_Memory::note( whole( H ) );
TCGiant_Sync_Category_Memory::begin();
TCGiant_Sync_Category_Memory::note( whole( F ) );
get_option( TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION ); // this request\'s copy: none
elsewhere( TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION, array( '3390' => '3377' ) );
TCGiant_Sync_Category_Memory::end();
check( 'what another request learnt meanwhile is kept', $TABLE[ TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION ] ?? null, array( '3390' => '3377', '11980' => '253' ) );

echo "\n   Learnt before 3.28.5, remembered with no parent known: skipped until the next full sync\n";
coin_shop( $COINS_US );
TCGiant_Sync_Category_Memory::note( whole( H ) ); // the memory in use
plant( I, I . ',11980,0,0,' . time() );
delta( array( bare( I ) ) );
check( 'a Lincoln cent remembered by 3.28.4 (its category, no parent): skipped as remembered, no GetItem', array( get_items(), saved_ids() ), array( array(), array() ) );
full_sync( array( whole( I ) ) );
check( '  the next full sync imports it, by its path, and learns its parent', array( saved_ids(), TCGiant_Sync_Category_Memory::coin_parent( '11980' ) ), array( array( I ), '253' ) );

echo "\n   Kept only while the shop filters; bounded\n";
shop( array() );
TCGiant_Sync_Category_Memory::note( ebay_has_path( F, array( '11980', '0', '0' ), PATH_US ) );
check( 'no filter: nothing kept', array( TCGiant_Sync_Category_Memory::coin_parent( '11980' ), isset( $TABLE[ TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION ] ) ), array( '', false ) );
coin_shop( $COINS_US );
TCGiant_Sync_Category_Memory::note( whole( F ) );
check( '  learnt from a GetItem answer, written at once', $TABLE[ TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION ] ?? null, array( '11980' => '253' ) );
watch_memory_writes();
TCGiant_Sync_Category_Memory::note( whole( I ) );
check( '  another listing in the same category: its line written, the coin categories not', in_array( TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION, array_column( $MEM_WRITES, 0 ), true ), false );
settings( array() );
full_sync( array( whole( H ) ) );
check( 'a full sync with the filter off deletes it, with the rest', isset( $TABLE[ TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION ] ), false );
coin_shop( $COINS_US );
$tcg_page = array();
for ( $i = 0; $i <= TCGiant_Sync_Category_Memory::COIN_LEAVES_MAX; $i++ ) {
	$tcg_page[] = array( 'ItemID' => (string) ( 720000000000 + $i ), 'PrimaryCategory' => array( 'CategoryID' => (string) ( 900000 + $i ), 'CategoryName' => 'Coins & Paper Money:Coins: World:Europe:' . $i ) );
}
TCGiant_Sync_Category_Memory::note_page( $tcg_page );
$tcg_kept = $TABLE[ TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION ] ?? array();
check( 'at most COIN_LEAVES_MAX coin categories: past it, the one learnt first goes', array( count( $tcg_kept ), isset( $tcg_kept['900000'] ), $tcg_kept[ (string) ( 900000 + TCGiant_Sync_Category_Memory::COIN_LEAVES_MAX ) ] ?? null ), array( TCGiant_Sync_Category_Memory::COIN_LEAVES_MAX, false, '256' ) );
check( '  uninstall sweeps it with the memory\'s options', 0 === strpos( TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION, TCGiant_Sync_Category_Memory::OPTION_PREFIX ), true );
check( 'not an ID, not a path: nothing', array( TCGiant_Sync_Category_Memory::coin_parent( '', PATH_US ), TCGiant_Sync_Category_Memory::coin_parent( array( '11980' ) ), TCGiant_Sync_Category_Memory::coin_parent( '11980', array( PATH_US ) ) === TCGiant_Sync_Category_Memory::coin_parent( '11980' ) ), array( '', '', true ) );

// ---------------------------------------------------------------------------
echo "\nTHE CODE\n";
$memory_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-category-memory.php' );
check( 'every write of an option is not autoloaded: the listings\' and the coin categories\'', array( substr_count( $memory_src, 'update_option(' ), preg_match_all( '/update_option\( [^;]*, false \);/', $memory_src ) ), array( 2, 2 ) );
check( 'it asks eBay nothing', 0 === preg_match( '/TCGiant_Sync_API|wp_remote_|get_item\(/', $memory_src ), true );

echo "\n  $pass passed, $fail failed\n\n";
exit( $fail > 0 ? 1 : 0 );
