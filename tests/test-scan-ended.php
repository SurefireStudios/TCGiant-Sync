<?php
/**
 * Harness: the full sync's scan - one window for every page, listings that
 * end while it runs, Emergency Stop, and which scans may authorise a prune.
 *
 * - Every page asks for listings ending from when the scan began. It asked
 *   from "now" on every page, so listings ending between two pages shifted
 *   eBay's end-time order under the scan.
 * - A listing that has ended is settled, never saved as live. Saved as live,
 *   a coin sold on the website went back up for sale at eBay's figure.
 * - Resume Import on a delta resumes the delta. It started a full scan that
 *   read only recent changes, and then authorised the pruner on that.
 * - Emergency Stop halts a running scan; a scan overtaken by a new one, or
 *   run for a delta, is never "clean".
 * - A page's live listings are on the active list before any is saved, so a
 *   request killed part way cannot leave them looking gone.
 *
 * Each multi-page scan waits two seconds between pages, as the real loop does.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/prune-world.php';


$LOCK = sys_get_temp_dir() . '/tcgiant_sync.lock';

/** A listing as GetSellerList ReturnAll sends it: complete enough to save inline. */
function row( $item_id, $status = 'Active', $listed = 1, $sold = 0 ) {
	return array(
		'ItemID' => $item_id, 'Title' => 'Card ' . $item_id, 'Quantity' => (string) $listed,
		'SellingStatus' => array( 'ListingStatus' => $status, 'QuantitySold' => (string) $sold ),
		'ItemSpecifics' => array( 'NameValueList' => array() ),
		'PictureDetails' => array( 'PictureURL' => 'https://i.ebayimg.com/x.jpg' ),
		'ListingDetails' => array( 'EndTime' => '2026-10-13T09:00:00.000Z' ),
	);
}
/** eBay's pages: $pages[n] = rows. $on_page[n] runs when page n is asked for. */
function ebay_pages( array $pages, array $on_page = array() ) {
	$GLOBALS['EBAY']['lister'] = function ( $page, $per, $mod_from, $end_from ) use ( $pages, $on_page ) {
		if ( isset( $on_page[ $page ] ) ) { call_user_func( $on_page[ $page ] ); }
		$rows = $pages[ $page ] ?? array();
		return $rows ? array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $rows ), 'PaginationResult' => array( 'TotalNumberOfPages' => count( $pages ) ) ) : array( 'Ack' => 'Success' );
	};
}
function full_sync() { @unlink( $GLOBALS['LOCK'] ); $GLOBALS['IMP']->start_full_sync( true ); run_queue( 'tcgiant_sync_scan_all_pages' ); }
function state( $k ) { $s = get_option( 'tcgiant_sync_state', array() ); return $s[ $k ] ?? null; }
function set_state( array $u ) { update_option( 'tcgiant_sync_state', array_merge( TCGiant_Sync_Importer::get_sync_state(), $u ) ); }
function saved_ids() { return array_map( function ( $s ) { return $s[0]; }, $GLOBALS['SAVED'] ); }

// ---------------------------------------------------------------------------
echo "\nS1. Every page asks from when the scan began\n";
fresh_world( array() );
ebay_pages( array( 1 => array( row( '1' ) ), 2 => array( row( '2' ) ) ) );
full_sync();
$froms = array_map( function ( $c ) { return $c[2]; }, $EBAY['list_calls'] );
check( 'both pages carry an EndTimeFrom', count( array_filter( $froms ) ), 2 );
check( '  the same one, the scan\'s start', array_values( array_unique( $froms ) ) === array( state( 'full_started_at' ) ), true );

// ---------------------------------------------------------------------------
echo "\nS2. A listing that ended while the scan ran (the website-sale coin)\n";
fresh_world( array( 'remove_sold_products' => '1' ) );
product( 11, '111', 0 );                     // sold on the website; plugin ended the listing
product( 14, '114', 1 );                     // sold out on eBay while the scan ran
ebay_pages( array( 1 => array( row( '111', 'Completed', 1, 0 ), row( '112' ), row( '113', 'Completed', 1, 1 ), row( '114', 'Completed', 1, 1 ) ) ) );
full_sync();
check( 'the ended listing is not saved as live', in_array( '111', saved_ids(), true ), false );
check( '  the website sale stands at 0', stock( 11 ), 0 );
check( '  marked Ended', meta( 11, '_ebay_listing_status' ), 'Ended' );
check( 'an ended listing with no product creates none', in_array( '113', saved_ids(), true ), false );
check( 'a sold-out one is settled as the delta would', stock( 14 ) . '/' . post_status( 14 ), '0/trash' );
check( 'the live one is saved as before', in_array( '112', saved_ids(), true ), true );

// ---------------------------------------------------------------------------
echo "\nS3. Resume Import Now on a rate-limited delta (delta-state_7)\n";
fresh_world( array() );
product( 31, '311', 1 );
product( 32, '312', 1 );
ebay_pages( array( 1 => array( row( '311' ) ) ) );   // ModTime answer: only what changed
set_state( array( 'status' => 'rate_limited', 'sync_mode' => 'delta', 'delta_mod_from' => '2026-10-11T00:00:00.000Z', 'total_pages' => 0, 'current_page' => 1, 'last_successful_sync_at' => '2026-10-11T00:05:00.000Z' ) );
@unlink( $LOCK );
$IMP->resume_sync();
check( 'resumes the delta, not a scan', queued( 'tcgiant_sync_fetch_delta_events' ) . '/' . queued( 'tcgiant_sync_scan_all_pages' ), '1/0' );
run_queue( 'tcgiant_sync_scan_all_pages' );
check( '  the pruner is not authorised', (bool) state( 'scan_complete_clean' ), false );
check( '  no prune queued', queued( 'tcgiant_sync_prune_orphans' ), 0 );

echo "\nS3b. A scan that finds the shared state in delta mode at the end\n";
fresh_world( array() );
ebay_pages( array( 1 => array( row( '1' ) ) ), array( 1 => function () { set_state( array( 'sync_mode' => 'delta' ) ); } ) );
full_sync();
check( 'not marked clean', (bool) state( 'scan_complete_clean' ), false );

// ---------------------------------------------------------------------------
echo "\nS4. Emergency Stop pressed during page 1 (delta-state_9)\n";
fresh_world( array() );
ebay_pages( array( 1 => array( row( '41' ) ), 2 => array( row( '42' ) ) ), array( 1 => function () { set_state( array( 'status' => 'stopped', 'scan_complete_clean' => false ) ); } ) );
full_sync();
check( 'page 2 is never asked for', count( $EBAY['list_calls'] ), 1 );
check( '  status stays stopped', state( 'status' ), 'stopped' );
check( '  not authorised, no prune queued', ( state( 'scan_complete_clean' ) ? 'clean' : 'not' ) . '/' . queued( 'tcgiant_sync_prune_orphans' ), 'not/0' );

// ---------------------------------------------------------------------------
echo "\nS5. A page's IDs are listed before its products are saved (scale_1)\n";
fresh_world( array() );
$IDS_AT_SAVE = array();
ebay_pages( array( 1 => array( row( '51' ), row( '52' ) ) ) );
full_sync();
check( 'each listing was on the active list when it was saved', $IDS_AT_SAVE, array( true, true ) );

// ---------------------------------------------------------------------------
echo "\nS6. Fetch Inventory pressed again while a scan runs\n";
fresh_world( array() );
ebay_pages( array( 1 => array( row( '61' ) ), 2 => array( row( '62' ) ) ), array( 2 => function () { set_state( array( 'full_started_at' => '2026-10-13T23:59:00.000Z' ) ); } ) );
full_sync();
check( 'the overtaken scan does not authorise the pruner', (bool) state( 'scan_complete_clean' ), false );

// ---------------------------------------------------------------------------
echo "\nS7. A queued GetItem import that finds the listing ended\n";
fresh_world( array() );
product( 71, '711', 0 );
listing( '711', 'Completed', 1, 0 );
set_state( array( 'status' => 'importing', 'sync_mode' => 'full', 'total_queued' => 1, 'total_processed' => 0 ) );
$IMP->process_item_import( '711' );
check( 'not saved as live', in_array( '711', saved_ids(), true ), false );
check( '  the website sale stands at 0', stock( 71 ), 0 );
check( '  counted, so the sync completes', state( 'status' ), 'complete' );
// And it is the count that completes it, not an empty queue.
fresh_world( array() );
listing( '712', 'Completed', 1, 0 );
set_state( array( 'status' => 'importing', 'sync_mode' => 'full', 'total_queued' => 1, 'total_processed' => 0 ) );
as_enqueue_async_action( 'tcgiant_sync_process_item_import', array( 'item_id' => '799' ), 'tcgiant_sync_imports' );
$IMP->process_item_import( '712' );
check( 'an ended listing with no product: counted', state( 'total_processed' ), 1 );
check( '  and said why nothing was made', logged( 'eBay item 712' ) && logged( 'so no product was created' ), true );

// ---------------------------------------------------------------------------
echo "\nS8. Emergency Stop pressed in another request while a page is read\n";
// The real thing: the scan's own copy of the state still says scanning, and
// the table says stopped. Every write the scan made merged into its copy and
// put "scanning" back over the Stop.
fresh_world( array() );
ebay_pages( array( 1 => array( row( '81' ) ), 2 => array( row( '82' ) ) ), array( 1 => function () {
	elsewhere( 'tcgiant_sync_state', array_merge( $GLOBALS['TABLE']['tcgiant_sync_state'], array( 'status' => 'stopped', 'scan_complete_clean' => false ) ) );
} ) );
full_sync();
check( 'Stop elsewhere during page 1: page 2 never asked for', count( $EBAY['list_calls'] ), 1 );
check( '  the table still says stopped', $TABLE['tcgiant_sync_state']['status'] ?? '', 'stopped' );
check( '  not authorised, no prune', ( ! empty( $TABLE['tcgiant_sync_state']['scan_complete_clean'] ) ? 'clean' : 'not' ) . '/' . queued( 'tcgiant_sync_prune_orphans' ), 'not/0' );

// A queued import finishing after the Stop writes its own copy too.
fresh_world( array() );
product( 83, '831', 1 );
listing( '831', 'Active', 1, 0 );
set_state( array( 'status' => 'importing', 'sync_mode' => 'full', 'total_queued' => 5, 'total_processed' => 0 ) );
$EBAY['on_get_item'] = function () {
	elsewhere( 'tcgiant_sync_state', array_merge( $GLOBALS['TABLE']['tcgiant_sync_state'], array( 'status' => 'stopped' ) ) );
};
$IMP->process_item_import( '831' );
check( 'an import finishing after a Stop elsewhere keeps the Stop', $TABLE['tcgiant_sync_state']['status'] ?? '', 'stopped' );
$EBAY['on_get_item'] = null;

// ---------------------------------------------------------------------------
echo "\nS9. A scan overtaken by a newer one, in another request\n";
fresh_world( array() );
ebay_pages( array( 1 => array( row( '91' ) ), 2 => array( row( '92' ) ) ), array( 1 => function () {
	elsewhere( 'tcgiant_sync_state', array_merge( $GLOBALS['TABLE']['tcgiant_sync_state'], array( 'full_started_at' => '2026-10-20T08:00:00.000Z', 'current_page' => 1 ) ) );
} ) );
full_sync();
check( 'the old scan stops, writing nothing over the new one', $TABLE['tcgiant_sync_state']['full_started_at'] ?? '', '2026-10-20T08:00:00.000Z' );
check( '  and authorises nothing', ( ! empty( $TABLE['tcgiant_sync_state']['scan_complete_clean'] ) ? 'clean' : 'not' ) . '/' . queued( 'tcgiant_sync_prune_orphans' ), 'not/0' );

@unlink( $LOCK );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
