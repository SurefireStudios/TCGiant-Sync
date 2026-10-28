<?php
/**
 * Harness: the pruner, which settles the listings a full sync did not see.
 *
 * The weekly full sync ends by "pruning" the products whose listings it did
 * not see. Before 3.28.0 that trashed every one of them - products the shop
 * had pushed to eBay, products whose listing ended unsold, products when
 * "Remove products when their eBay listing sells out" was off - and zeroed
 * and unlinked products in Preserve categories. It also trashed live
 * listings the scan had simply missed: eBay sorts the scan by end time, and a
 * Good 'Til Cancelled listing that renews while it runs moves to the back.
 *
 * Now each product is asked about with GetItem first, at most 300 calls a
 * run and the rest an hour later (3.27.6; 3.28.0 alone asked 200 a full
 * sync), and what eBay says has ended is settled exactly as the delta settles
 * an ended listing. The pruner has no trash of its own.
 *
 * Loads the real importer, inventory and runtime; the pruner's SQL runs on
 * SQLite. See support/prune-world.php.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/prune-world.php';


function prune() { $GLOBALS['IMP']->prune_orphaned_items(); run_queue( 'tcgiant_sync_prune_orphans' ); }
function trashed( $id ) { return 'trash' === post_status( $id ); }
$REMOVE_ON  = array( 'remove_sold_products' => '1', 'preserve_woo_category_ids' => array( 77 ) );
$REMOVE_OFF = array( 'remove_sold_products' => '0' );

// Live filler so the 20% guard is not what decides these cases.
function filler( $n, $from = 5000 ) { $ids = array(); for ( $i = 0; $i < $n; $i++ ) { product( $from + $i, (string) ( 900000 + $from + $i ), 1 ); $ids[] = (string) ( 900000 + $from + $i ); } return $ids; }

// ---------------------------------------------------------------------------
echo "\n1. Pushed products (stock_8)\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 101, '9101', 3, array( '_ebay_export_status' => 'pushed' ) );
product( 102, '9102', 1, array( '_ebay_export_status' => 'pushed' ) );
listing( '9101', 'Completed', 3, 0 );
listing( '9102', 'Completed', 1, 1 );
scanned( $live );
prune();
check( 'pushed, ended unsold: not trashed', trashed( 101 ), false );
check( '  keeps its 3', stock( 101 ), 3 );
check( '  keeps its eBay link', meta( 101, '_ebay_item_id' ), '9101' );
check( '  marked Ended', meta( 101, '_ebay_listing_status' ), 'Ended' );
check( 'pushed, sold out on eBay: not trashed', trashed( 102 ), false );
check( '  stock comes down to what eBay left', stock( 102 ), 0 );
check( 'no stock write could travel back to eBay as a push', count( preg_grep( '/UNGUARDED/', $EVENTS ) ), 0 );

// ---------------------------------------------------------------------------
echo "\n2. Imported products, Remove sold products = Yes\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 201, '9201', 1 );
product( 202, '9202', 2 );
listing( '9201', 'Completed', 1, 1 );
listing( '9202', 'Completed', 2, 0 );
scanned( $live );
prune();
check( 'sold out on eBay: moved to the trash (as the delta would)', trashed( 201 ), true );
check( 'ended unsold: kept, as the Settings hint promises', trashed( 202 ), false );
check( '  with its unsold 2', stock( 202 ), 2 );

// ---------------------------------------------------------------------------
echo "\n3. Remove sold products = No\n";
fresh_world( $REMOVE_OFF );
$live = filler( 30 );
product( 301, '9301', 1 );
listing( '9301', 'Completed', 1, 1 );
scanned( $live );
prune();
check( 'sold out, setting off: nothing trashed', trashed( 301 ), false );
check( '  the sale still takes stock to 0', stock( 301 ), 0 );

// ---------------------------------------------------------------------------
echo "\n4. Preserve categories\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 401, '9401', 5, array(), 77 );
product( 402, '9402', 3, array(), 77 );
listing( '9401', 'Completed', 5, 0 );
listing( '9402', 'Completed', 3, 1 );
scanned( $live );
prune();
check( 'preserved, ended unsold: keeps its 5 (3.27.0 zeroed it)', stock( 401 ), 5 );
check( '  keeps its eBay link (3.27.0 removed it)', meta( 401, '_ebay_item_id' ), '9401' );
check( 'preserved, 1 of 3 sold: 2 left, not 0', stock( 402 ), 2 );
check( '  not trashed', trashed( 402 ), false );

// ---------------------------------------------------------------------------
echo "\n5. Live listings the scan missed (destructive_4)\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 501, '9501', 4 );
product( 502, '9502', 1 );
listing( '9501', 'Active', 10, 6, array( 'ListingDetails' => array( 'EndTime' => '2026-11-13T10:00:00.000Z' ) ) );
listing( '9502', 'Active', 1, 0 );
scanned( $live );
prune();
check( 'live listing missed by the scan: not trashed', trashed( 501 ) || trashed( 502 ), false );
check( '  stock untouched', stock( 501 ) . '/' . stock( 502 ), '4/1' );
check( '  status still Active', meta( 501, '_ebay_listing_status' ), 'Active' );
check( '  next renewal recorded', meta( 501, '_ebay_end_time' ), '2026-11-13T10:00:00.000Z' );
check( '  log counts them as missed by the scan', logged( '2 still live' ), true );

// ---------------------------------------------------------------------------
echo "\n6. Already recorded as ended\n";
fresh_world( $REMOVE_ON );
$live = filler( 10 );
for ( $i = 0; $i < 40; $i++ ) { product( 600 + $i, (string) ( 9600 + $i ), 2, array( '_ebay_listing_status' => 'Ended' ) ); }
product( 690, '9690', 1 );
listing( '9690', 'Completed', 1, 1 );
scanned( $live );
prune();
check( '40 known-ended products do not trip the 20% guard (would be 80%)', logged( 'ABORTED' ), false );
check( '  only the unrecorded one is asked about', $EBAY['get_items'], array( '9690' ) );
check( '  known-ended ones untouched', trashed( 600 ) . '/' . stock( 600 ), '/2' );

// ---------------------------------------------------------------------------
echo "\n7. eBay no longer has it\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 701, '9701', 2 );                                     // error 17
product( 702, '9702', 1 );                                     // >90 days: no figures
$EBAY['items']['9702'] = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '9702' ) );
scanned( $live );
prune();
check( 'error 17: kept', trashed( 701 ), false );
check( '  stock untouched', stock( 701 ), 2 );
check( '  marked Ended (so Stock Review lists it if in stock)', meta( 701, '_ebay_listing_status' ), 'Ended' );
check( 'no figures: kept, stock untouched', ( trashed( 702 ) ? 'trash' : 'kept' ) . '/' . stock( 702 ), 'kept/1' );
$EBAY['get_items'] = array();
scanned( $live );
prune();
check( 'the next prune does not ask about them again', $EBAY['get_items'], array() );
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 703, '9703', 2 );
$EBAY['items']['9703'] = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '9703', 'SellingStatus' => array( 'ListingStatus' => 'Completed' ) ) );
scanned( $live );
prune();
check( 'ended but no quantities: one call, kept, stock untouched', count( $EBAY['get_items'] ) . '/' . ( trashed( 703 ) ? 'trash' : 'kept' ) . '/' . stock( 703 ) . '/' . meta( 703, '_ebay_listing_status' ), '1/kept/2/Ended' );

// ---------------------------------------------------------------------------
echo "\n8. eBay down\n";
fresh_world( $REMOVE_ON );
$live = filler( 60 );
for ( $i = 0; $i < 10; $i++ ) { product( 800 + $i, (string) ( 9800 + $i ), 1 ); $EBAY['items'][ (string) ( 9800 + $i ) ] = new WP_Error( 'http_request_failed', 'cURL error 28' ); }
scanned( $live );
$IMP->prune_orphaned_items();
check( 'stops after 5 failed answers in a row', count( $EBAY['get_items'] ), 5 );
check( '  nothing changed', count( preg_grep( '/^(trash|stock)/', $EVENTS ) ), 0 );
// Since 3.27.6 what a run could not confirm is asked about again an hour on,
// up to 24 times; after that, by the next full sync's clean-up.
$later = array_values( array_filter( $QUEUE, function ( $a ) { return 'tcgiant_sync_prune_orphans' === $a['hook'] && $a['when'] >= time() + 3500 && ! empty( $a['args']['follow_up'] ); } ) );
check( '  all ten kept for a follow-up run an hour on', array( count( $later ), count( (array) ( get_option( TCGiant_Sync_Importer::PRUNE_QUEUE_OPTION, array() )['items'] ?? array() ) ) ), array( 1, 10 ) );
run_queue( 'tcgiant_sync_prune_orphans' );
check( '  each follow-up stops after 5 as well, and after the 24th the rest wait for the next full sync', array( count( $EBAY['get_items'] ), queued( 'tcgiant_sync_prune_orphans' ), get_option( TCGiant_Sync_Importer::PRUNE_QUEUE_OPTION, null ) ), array( 125, 0, null ) );
check( '  still nothing changed', count( preg_grep( '/^(trash|stock)/', $EVENTS ) ), 0 );

// ---------------------------------------------------------------------------
echo "\n9. Rate limited\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 901, '9901', 1 );
product( 902, '9902', 1 );
listing( '9901', 'Completed', 1, 1 );
listing( '9902', 'Completed', 1, 1 );
// eBay's own call limit, 518, as the API class reports it.
$EBAY['items']['9902'] = new WP_Error( 'rate_limited', 'Calls to this call have exceeded the call limit.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '518' ) ) );
scanned( $live );
$IMP->prune_orphaned_items();
$later = array_values( array_filter( $QUEUE, function ( $a ) { return 'tcgiant_sync_prune_orphans' === $a['hook'] && $a['when'] > time(); } ) );
check( 'a continuation is scheduled for later', count( $later ), 1 );
check( '  the first was settled already', trashed( 901 ), true );
listing( '9902', 'Completed', 1, 1 );
prune();
check( 'the continuation settles the throttled one', trashed( 902 ), true );
check( '  asking about it once more, and 9901 not again', $EBAY['get_items'], array( '9901', '9902', '9902' ) );

// eBay's 10007 comes back as a rate limit too, but it is an error inside eBay
// that one listing can keep hitting. Waiting for it held the prune on that
// listing, coming back every quarter of an hour until the next full sync.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 903, '9903', 1 );
product( 904, '9904', 1 );
listing( '9903', 'Completed', 1, 1 );
listing( '9904', 'Completed', 1, 1 );
$EBAY['items']['9903'] = new WP_Error( 'rate_limited', 'Internal error to the application.', array( 'Ack' => 'Failure', 'Errors' => array( array( 'ErrorCode' => '10007' ) ) ) );
scanned( $live );
prune();
$later = array_values( array_filter( $QUEUE, function ( $a ) { return 'tcgiant_sync_prune_orphans' === $a['hook'] && $a['when'] > time(); } ) );
check( "eBay's 10007 on one listing: no wait for it", count( $later ), 0 );
check( '  it is left alone, and the next one settled', array( trashed( 903 ), trashed( 904 ) ), array( false, true ) );

// ---------------------------------------------------------------------------
echo "\n10. Time budget (Runtime clock seam)\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
for ( $i = 0; $i < 4; $i++ ) { product( 1000 + $i, (string) ( 91000 + $i ), 1 ); listing( (string) ( 91000 + $i ), 'Completed', 1, 1 ); }
scanned( $live );
$_SERVER['REQUEST_TIME_FLOAT'] = 1000.0;
TCGiant_Sync_Runtime::$clock = function () { return 1000.0 + 999; };
$IMP->prune_orphaned_items();
check( 'over budget: nothing asked, a continuation queued', count( $EBAY['get_items'] ) . '/' . queued( 'tcgiant_sync_prune_orphans' ), '0/1' );
TCGiant_Sync_Runtime::$clock = null;
unset( $_SERVER['REQUEST_TIME_FLOAT'] );
prune();
check( 'the continuation asks about each once', count( $EBAY['get_items'] ), 4 );
check( '  and settles them', count( array_filter( array( 1000, 1001, 1002, 1003 ), 'trashed' ) ), 4 );

// ---------------------------------------------------------------------------
echo "\n11. A badly drifted list (budget guard)\n";
fresh_world( $REMOVE_ON );
$live = filler( 400 );
for ( $i = 0; $i < 70; $i++ ) { product( 1100 + $i, (string) ( 91100 + $i ), 1 ); listing( (string) ( 91100 + $i ), 'Active', 1, 0 ); }
scanned( $live );
prune();
check( 'stops after 40 checks when 90% or more are live', count( $EBAY['get_items'] ), 40 );
check( '  says why', logged( 'the rest are left for a later full sync' ), true );
// A large Good 'Til Cancelled store sees this most weeks. Not an error.
check( '  and not as an error', count( array_filter( $LOG, function ( $l ) { return 'error' === $l[0] && false !== stripos( $l[1], 'still live' ); } ) ), 0 );
check( '  trashes nothing', count( preg_grep( '/^trash/', $EVENTS ) ), 0 );

echo "\n12. Ordinary drift does not stop it\n";
fresh_world( $REMOVE_ON );
$live = filler( 400 );
for ( $i = 0; $i < 30; $i++ ) { product( 1200 + $i, (string) ( 91200 + $i ), 1 ); listing( (string) ( 91200 + $i ), 'Active', 1, 0 ); }
for ( $i = 30; $i < 60; $i++ ) { product( 1200 + $i, (string) ( 91200 + $i ), 1 ); listing( (string) ( 91200 + $i ), 'Completed', 1, 1 ); }
scanned( $live );
prune();
// The first 40 are 30 live and 10 sold out: 75%, under the 90% that stops it.
check( '75% live in the first 40 (a slow GTC scan): all 60 asked about', count( $EBAY['get_items'] ), 60 );
check( '  the 30 that sold out are settled', count( preg_grep( '/^trash/', $EVENTS ) ), 30 );

// ---------------------------------------------------------------------------
echo "\n13. Call cap\n";
// 3.28.0 asked about 200 a full sync, the rest waiting for the next one;
// 3.27.6 asks 300 a run, and the rest an hour on. Merged: 3.27.6's.
fresh_world( $REMOVE_ON );
$live = filler( 1500 );
for ( $i = 0; $i < 350; $i++ ) { product( 20000 + $i, (string) ( 920000 + $i ), 1 ); listing( (string) ( 920000 + $i ), 'Completed', 1, 1 ); }
scanned( $live );
$IMP->prune_orphaned_items();
check( 'stops at 300 calls a run', count( $EBAY['get_items'] ), 300 );
check( '  says how many wait, and for how long', array( logged( '50 are asked about again in an hour' ), logged( '50 products kept for now, their listings not yet confirmed as ended on eBay (eBay is asked about 300 a run). They are checked again in an hour.' ) ), array( true, true ) );
check( '  the 300 settled, the 50 not touched yet', array( count( array_filter( range( 20000, 20299 ), 'trashed' ) ), count( array_filter( range( 20300, 20349 ), 'trashed' ) ) ), array( 300, 0 ) );
run_queue( 'tcgiant_sync_prune_orphans' );
check( '  the follow-up asks about those 50, once each, and settles them', array( count( $EBAY['get_items'] ), count( array_unique( $EBAY['get_items'] ) ), count( array_filter( range( 20300, 20349 ), 'trashed' ) ), get_option( TCGiant_Sync_Importer::PRUNE_QUEUE_OPTION, null ) ), array( 350, 350, 50, null ) );

// ---------------------------------------------------------------------------
echo "\n14. The 20% guard\n";
fresh_world( $REMOVE_ON );
$live = filler( 7 );
for ( $i = 0; $i < 3; $i++ ) { product( 1400 + $i, (string) ( 91400 + $i ), 1 ); listing( (string) ( 91400 + $i ), 'Completed', 1, 1 ); }
scanned( $live );
prune();
check( 'a 30% gap is refused', logged( 'ABORTED' ), true );
check( '  without one eBay call', count( $EBAY['get_items'] ), 0 );

// ---------------------------------------------------------------------------
echo "\n15. Relisted on eBay\n";
// A single item that sold is never moved on to its relist - that is the next
// copy - so its own listing is settled like any other that ended, without a
// word to eBay about the relist. Before, it was left in stock "for the sync to
// move across", which the sync never does.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 1501, '91501', 1 );
listing( '91501', 'Completed', 1, 1, array( 'ListingDetails' => array( 'RelistedItemID' => '91599' ) ) );
scanned( $live );
prune();
check( 'a single item that sold, relisted: settled, and trashed as sold out', trashed( 1501 ) . '/' . meta( 1501, '_ebay_listing_status' ), '1/Ended' );
check( '  one call, and no adoption', array( $EBAY['get_items'], $ADOPTED ), array( array( '91501' ), array() ) );

fresh_world( $REMOVE_OFF );
$live = filler( 30 );
product( 1505, '91505', 1 );
listing( '91505', 'Completed', 1, 1, array( 'ListingDetails' => array( 'RelistedItemID' => '91596' ) ) );
listing( '91596', 'Active', 1, 0, array( 'RelistParentID' => '91505' ) );
scanned( $live );
prune();
check( '  "Remove sold products" off: stock 0, marked ended, still on its own listing', array( stock( 1505 ), meta( 1505, '_ebay_listing_status' ), meta( 1505, '_ebay_item_id' ) ), array( 0, 'Ended', '91505' ) );
check( '  the readable relist is not asked about', array( $EBAY['get_items'], $ADOPTED ), array( array( '91505' ), array() ) );

// The relist has no product yet: it is offered for adoption, as the delta
// would, with the new listing as eBay describes it - and the old listing's
// answer, so eBay is not asked about it twice.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 1502, '91502', 1 );
listing( '91502', 'Completed', 1, 0, array( 'ListingDetails' => array( 'RelistedItemID' => '91598' ) ) );
listing( '91598', 'Active', 1, 0, array( 'RelistParentID' => '91502' ) );
$ADOPT_RESULT = 1502;
scanned( $live );
prune();
check( 'the new listing is asked about and offered for adoption', array( $EBAY['get_items'], $ADOPTED ), array( array( '91502', '91598' ), array( array( '91598', 'Inventory pruning' ) ) ) );
check( '  handed the old listing\'s answer', $ADOPTED_PARENT, array( '91502' ) );
check( '  moved on, live, its stock of 1 kept', array( trashed( 1502 ), meta( 1502, '_ebay_item_id' ), meta( 1502, '_ebay_listing_status' ), stock( 1502 ) ), array( false, '91598', 'Active', 1 ) );
check( '  counted as relisted', logged( '1 relisted' ), true );

// Adopted on to a live relist that has sold some since: brought down to what
// it has left, never up, as the sale check does.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 1506, '91506', 5 );
listing( '91506', 'Completed', 5, 0, array( 'ListingDetails' => array( 'RelistedItemID' => '91595' ) ) );
listing( '91595', 'Active', 3, 1, array( 'RelistParentID' => '91506' ) );
$ADOPT_RESULT = 1506;
scanned( $live );
prune();
check( 'adopted on to a live relist that sold 1 of 3: stock 5 comes down to 2', array( meta( 1506, '_ebay_item_id' ), stock( 1506 ) ), array( '91595', 2 ) );

// The fast sale: the old listing ended unsold, and its relist sold before any
// sync saw it. The product moves on to the relist and is settled from it -
// before, it moved on, was marked ended, and kept its stock for a coin eBay
// had sold, with nothing ever looking at it again.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 1507, '91507', 1 );
listing( '91507', 'Completed', 1, 0, array( 'ListingDetails' => array( 'RelistedItemID' => '91594' ) ) );
listing( '91594', 'Completed', 1, 1, array( 'RelistParentID' => '91507' ) );
$ADOPT_RESULT = 1507;
scanned( $live );
prune();
check( 'adopted on to a relist that sold: settled from it, trashed as sold out', array( meta( 1507, '_ebay_item_id' ), meta( 1507, '_ebay_listing_status' ), trashed( 1507 ) ), array( '91594', 'Ended', true ) );
check( '  two calls: the old listing and the relist', $EBAY['get_items'], array( '91507', '91594' ) );

fresh_world( $REMOVE_OFF );
$live = filler( 30 );
product( 1508, '91508', 5 );
listing( '91508', 'Completed', 5, 0, array( 'ListingDetails' => array( 'RelistedItemID' => '91593' ) ) );
listing( '91593', 'Completed', 5, 3, array( 'RelistParentID' => '91508' ) );
$ADOPT_RESULT = 1508;
scanned( $live );
prune();
check( '  five, three sold on the relist: 2 left', array( meta( 1508, '_ebay_item_id' ), stock( 1508 ), trashed( 1508 ) ), array( '91593', 2, false ) );

// Adoption declined - another item, a Sell Similar copy, and so on. Nothing
// will ever move the product across, so its own listing is settled.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 1509, '91509', 1 );
listing( '91509', 'Completed', 1, 0, array( 'ListingDetails' => array( 'RelistedItemID' => '91592' ) ) );
listing( '91592', 'Active', 1, 0, array( 'RelistParentID' => '91509' ) );
scanned( $live );
prune();
check( 'adoption declined: settled from its own listing - ended unsold, its 1 kept', array( meta( 1509, '_ebay_item_id' ), meta( 1509, '_ebay_listing_status' ), stock( 1509 ), trashed( 1509 ) ), array( '91509', 'Ended', 1, false ) );

// Another product already follows the relist: nothing more is asked, and the
// old product's own listing is settled - an old duplicate, which the log names.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 1503, '91503', 1 );
listing( '91503', 'Completed', 1, 0, array( 'ListingDetails' => array( 'RelistedItemID' => '91597' ) ) );
product( 1504, '91597', 1 );
$live[] = '91597';
scanned( $live );
prune();
check( 'a relist that already has its product: no second call, no adoption', array( $EBAY['get_items'], $ADOPTED ), array( array( '91503' ), array() ) );
check( '  the old product is settled from its own listing, its 1 kept', array( meta( 1503, '_ebay_listing_status' ), stock( 1503 ) ), array( 'Ended', 1 ) );
check( '  the log names the product that has the relist', logged( 'relisted as 91597, which WC #1504 follows' ), true );

// The relist cannot be read: left as it is for the next full sync.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 1510, '91510', 1 );
listing( '91510', 'Completed', 1, 0, array( 'ListingDetails' => array( 'RelistedItemID' => '91591' ) ) );
$EBAY['items']['91591'] = new WP_Error( 'http_request_failed', 'Operation timed out' );
scanned( $live );
prune();
check( 'the relist could not be read: left as it is', array( meta( 1510, '_ebay_listing_status' ), stock( 1510 ), meta( 1510, '_ebay_item_id' ) ), array( 'Active', 1, '91510' ) );
check( '  counted as not read, and said why', array( logged( '1 could not be read' ), logged( 'which could not be read (Operation timed out)' ) ), array( true, true ) );

// Out of calls reading it: the prune pauses there, as for any listing.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 1511, '91511', 1 );
listing( '91511', 'Completed', 1, 0, array( 'ListingDetails' => array( 'RelistedItemID' => '91590' ) ) );
$EBAY['items']['91590'] = new WP_Error( 'rate_limited', 'Daily budget used', array( 'daily_budget' => true ) );
scanned( $live );
prune();
check( 'out of calls reading the relist: the prune pauses, the product untouched', array( logged( 'call limit reached' ), meta( 1511, '_ebay_listing_status' ), stock( 1511 ) ), array( true, 'Active', 1 ) );

// ---------------------------------------------------------------------------
echo "\n16. Things that changed while the list was worked\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 1601, '91601', 1 );
product( 1602, '91602', 1 );
listing( '91601', 'Completed', 1, 1 );
listing( '91602', 'Completed', 1, 1 );
scanned( $live );
$_SERVER['REQUEST_TIME_FLOAT'] = 1000.0;
TCGiant_Sync_Runtime::$clock = function () { return 1000.0 + 999; };
$IMP->prune_orphaned_items();            // builds the list, hands on
TCGiant_Sync_Runtime::$clock = null;
unset( $_SERVER['REQUEST_TIME_FLOAT'] );
update_post_meta( 1601, '_ebay_item_id', '91700' );   // relinked (relist adopted)
update_post_meta( 1602, '_ebay_listing_status', 'Ended' ); // settled by the delta
prune();
check( 'relinked or settled since: not asked about, not touched', count( $EBAY['get_items'] ) . '/' . ( trashed( 1601 ) || trashed( 1602 ) ? 'touched' : 'untouched' ), '0/untouched' );

// ---------------------------------------------------------------------------
echo "\n17. Emergency Stop\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 1701, '91701', 1 );
listing( '91701', 'Completed', 1, 1 );
scanned( $live );
$_SERVER['REQUEST_TIME_FLOAT'] = 1000.0;
TCGiant_Sync_Runtime::$clock = function () { return 1000.0 + 999; };
$IMP->prune_orphaned_items();
TCGiant_Sync_Runtime::$clock = null;
unset( $_SERVER['REQUEST_TIME_FLOAT'] );
$st = get_option( 'tcgiant_sync_state' ); $st['status'] = 'stopped'; update_option( 'tcgiant_sync_state', $st );
$GLOBALS['IMP']->prune_orphaned_items();
check( 'a prune continuing after Stop asks nothing', count( $EBAY['get_items'] ), 0 );
check( '  and trashes nothing', trashed( 1701 ), false );

// ---------------------------------------------------------------------------
echo "\n18. Daily budget\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 1801, '91801', 1 );
listing( '91801', 'Completed', 1, 1 );
$EBAY['budget'] = 5000;
scanned( $live );
prune();
check( 'below the reserve: nothing asked', count( $EBAY['get_items'] ), 0 );

// ---------------------------------------------------------------------------
echo "\n19. In stock first\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 1901, '91901', 0 );
product( 1902, '91902', 1 );
listing( '91901', 'Completed', 1, 1 );
listing( '91902', 'Completed', 1, 1 );
scanned( $live );
prune();
check( 'the in-stock product is asked about first', $EBAY['get_items'], array( '91902', '91901' ) );

// ---------------------------------------------------------------------------
echo "\n20. The pruner has no trash of its own (source)\n";
$src  = file_get_contents( $IMPORTER_FILE );
$from = strpos( $src, 'public function prune_orphaned_items' );
$body = substr( $src, $from );
check( 'no wp_trash_post / zeroing / unlink from the prune onward', array( substr_count( $body, 'wp_trash_post' ), substr_count( $body, "wc_update_product_stock( \$product_id, 0" ), substr_count( $body, "delete_post_meta( \$product_id, '_ebay_item_id' )" ) ), array( 0, 0, 0 ) );

// ---------------------------------------------------------------------------
echo "\n21. Every request over its budget: each still checks one\n";
// A prune that starts late in a busy cron run, or on a host already near its
// memory limit, used to hand on at once, and the next did the same - round
// and round, with nothing checked and a new queued action every time.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
for ( $i = 0; $i < 4; $i++ ) { product( 2100 + $i, (string) ( 92100 + $i ), 1 ); listing( (string) ( 92100 + $i ), 'Completed', 1, 1 ); }
scanned( $live );
$_SERVER['REQUEST_TIME_FLOAT'] = 1000.0;
TCGiant_Sync_Runtime::$clock   = function () { return 5000.0; };
$IMP->prune_orphaned_items();
$runs = run_queue( 'tcgiant_sync_prune_orphans' );
check( 'building the list hands on; each continuation checks one', count( $EBAY['get_items'] ) . '/' . $runs, '4/4' );
check( '  all settled, and nothing left queued', array( count( array_filter( array( 2100, 2101, 2102, 2103 ), 'trashed' ) ), queued( 'tcgiant_sync_prune_orphans' ) ), array( 4, 0 ) );
TCGiant_Sync_Runtime::$clock = null;
unset( $_SERVER['REQUEST_TIME_FLOAT'] );

// ---------------------------------------------------------------------------
echo "\n22. Missed scan after scan comes first\n";
// A listing the scan misses because it renewed mid-scan is seen next time;
// one that has really ended is missed every time. On a large GTC store the
// first kind fills the list, and the second used to wait its turn by post ID.
fresh_world( $REMOVE_ON );
$live = filler( 600 );
for ( $i = 0; $i < 45; $i++ ) { product( 2200 + $i, (string) ( 92200 + $i ), 1 ); listing( (string) ( 92200 + $i ), 'Active', 1, 0 ); }
product( 2299, '92299', 1, array( '_ebay_prune_missed' => time() - 7 * 86400 ) );
listing( '92299', 'Completed', 1, 1 );
scanned( $live );
prune();
check( 'missed last week too: asked about first', $EBAY['get_items'][0] ?? '', '92299' );
check( '  and settled, though 45 drift misses came before it by post ID', trashed( 2299 ), true );
check( '  the drift misses noted as missed from now', '' !== (string) get_post_meta( 2200, '_ebay_prune_missed', true ), true );
fresh_world( $REMOVE_ON );
$live   = filler( 30 );
product( 2298, '92298', 1, array( '_ebay_prune_missed' => time() - 7 * 86400 ) );
$live[] = '92298';
scanned( $live );
prune();
check( 'seen again: its miss is forgotten', (string) get_post_meta( 2298, '_ebay_prune_missed', true ), '' );

// ---------------------------------------------------------------------------
echo "\n23. A live listing the scan missed, with a sale the sync missed\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 2301, '92301', 1 );
listing( '92301', 'Active', 3, 3 );
product( 2302, '92302', 2 );
listing( '92302', 'Active', 5, 1 );
scanned( $live );
prune();
check( 'eBay has none left: stock lowered to 0, not trashed', array( stock( 2301 ), trashed( 2301 ) ), array( 0, false ) );
check( 'eBay has 4 left, the shop 2: never raised', stock( 2302 ), 2 );
// RF4-1 (release fix round 4): the record of eBay's sales is written with the
// stock - so a save that throws after the write leaves it moved on, and the
// next read takes nothing more.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 2303, '92303', 5 );
update_post_meta( 2303, '_ebay_sold_seen', array( 'item' => '92303', 'sku' => '', 'sold' => 0 ) );
listing( '92303', 'Active', 5, 2 );
scanned( $live );
$SAVE_THROWS = true;
$rf4_died    = '';
try {
	prune();
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$SAVE_THROWS = false;
check( 'RF4-1 eBay sold 2 of 5, a save that throws after the write: 3, and the record already moved on to eBay\'s 2 sold', array( $rf4_died, stock( 2303 ), get_post_meta( 2303, '_ebay_sold_seen', true )['sold'] ?? null ), array( 'another plugin\'s save hook failed', 3, 2 ) );
// Absorbed once: the shop's 9 lowered to eBay's 7 by 2 of eBay's 3 sales -
// the record moves to 2, not 3.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 2304, '92304', 9 );
update_post_meta( 2304, '_ebay_sold_seen', array( 'item' => '92304', 'sku' => '', 'sold' => 0 ) );
listing( '92304', 'Active', 10, 3 );
scanned( $live );
prune();
check( '  absorbed once: 9 lowered to 7, the record 2', array( stock( 2304 ), get_post_meta( 2304, '_ebay_sold_seen', true )['sold'] ?? null ), array( 7, 2 ) );

// ---------------------------------------------------------------------------
echo "\n24. A listing eBay no longer has is marked, for Auto-Relist\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 2401, '92401', 2 );
$EBAY['items']['92401'] = new WP_Error( 'api_error', 'Item cannot be accessed.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '17' ) ) );
scanned( $live );
prune();
check( 'marked as the listing eBay no longer has', (string) get_post_meta( 2401, '_ebay_unreadable_item', true ), '92401' );

// ---------------------------------------------------------------------------
echo "\n25. eBay never answered: the listing keeps its place\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 2501, '92501', 1 );
$EBAY['items']['92501'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
product( 2502, '92502', 1 );
$EBAY['items']['92502'] = new WP_Error( 'rate_limited', 'Internal error to the application.', array( 'Ack' => 'Failure', 'Errors' => array( array( 'ErrorCode' => '10007' ) ) ) );
scanned( $live );
prune();
check( 'a timeout is not counted as a check', (string) get_post_meta( 2501, '_ebay_prune_checked', true ), '' );
check( "eBay's own 10007 answer is", '' !== (string) get_post_meta( 2502, '_ebay_prune_checked', true ), true );

// ---------------------------------------------------------------------------
echo "\n26. A list from an earlier full sync is not carried on\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
product( 2601, '92601', 1 );
listing( '92601', 'Completed', 1, 1 );
product( 2602, '92602', 1 );
listing( '92602', 'Completed', 1, 1 );
scanned( $live );
add_option( TCGiant_Sync_Importer::PRUNE_QUEUE_OPTION, array( 'scan' => '2026-10-06T08:00:00.000Z', 'items' => array( array( 2602, '92602' ) ), 'pos' => 0, 'counts' => array(), 'unread_run' => 0, 'candidates' => 1, 'linked' => 31, 'known_ended' => 0 ), '', false );
prune();
check( "the new scan's list is made and worked through", array( trashed( 2601 ), trashed( 2602 ) ), array( true, true ) );
// A prune already running when a new full sync starts stops itself.
fresh_world( $REMOVE_ON );
$live = filler( 30 );
for ( $i = 0; $i < 3; $i++ ) { product( 2610 + $i, (string) ( 92610 + $i ), 1 ); listing( (string) ( 92610 + $i ), 'Completed', 1, 1 ); }
scanned( $live );
$EBAY['on_get_item'] = function () {
	elsewhere( 'tcgiant_sync_state', array_merge( $GLOBALS['TABLE']['tcgiant_sync_state'], array( 'full_started_at' => '2026-10-20T08:00:00.000Z', 'status' => 'scanning' ) ) );
};
$IMP->prune_orphaned_items();
check( 'a newer full sync starts: the running prune stops after the one in hand', count( $EBAY['get_items'] ), 1 );
check( '  saying so', logged( 'a newer full sync has started' ), true );

// ---------------------------------------------------------------------------
echo "\n27. Duplicate meta rows do not double a product\n";
fresh_world( $REMOVE_ON );
$live = filler( 30 );
for ( $i = 0; $i < 3; $i++ ) {
	product( 2700 + $i, (string) ( 92700 + $i ), 1 );
	listing( (string) ( 92700 + $i ), 'Completed', 1, 1 );
	$wpdb->db->exec( sprintf( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( %d, '_stock_status', 'instock' ), ( %d, '_ebay_listing_status', 'Active' )", 2700 + $i, 2700 + $i ) );
}
scanned( $live );
prune();
check( 'one GetItem per product', count( $EBAY['get_items'] ), 3 );
check( '  and the true count in the log', logged( 'did not see the listings of 3 of 33' ), true );

// ---------------------------------------------------------------------------
echo "\n28. A relist's GetItem counts toward the 300 a run (3.27.6 with 3.28.0)\n";
// 3.27.6 asks eBay about 300 a run and the rest an hour on; 3.28.0 asks once
// more for each candidate eBay says was relisted, to move the product on to
// the relist. The cap counts calls, not products: every one the pruner makes.
fresh_world( $REMOVE_ON );
$live = filler( 700 );
for ( $i = 0; $i < 160; $i++ ) {
	product( 2800 + $i, (string) ( 92800 + $i ), 1 );
	listing( (string) ( 92800 + $i ), 'Completed', 1, 0, array( 'ListingDetails' => array( 'RelistedItemID' => (string) ( 93800 + $i ) ) ) );
	listing( (string) ( 93800 + $i ), 'Active', 1, 0, array( 'RelistParentID' => (string) ( 92800 + $i ) ) );
}
scanned( $live );
$IMP->prune_orphaned_items();
$asked_products = array_values( array_filter( $EBAY['get_items'], function ( $id ) { return (int) $id < 93800; } ) );
check( '160 relisted products, a relist read each: 300 calls the first run', count( $EBAY['get_items'] ), 300 );
check( '  so 150 of them asked about, each with its relist', array( count( $asked_products ), count( array_unique( $EBAY['get_items'] ) ) ), array( 150, 300 ) );
check( '  every one handed to the adoption with its own answer, not asked for again', array( count( $ADOPTED ), count( array_filter( $ADOPTED_PARENT ) ) ), array( 150, 150 ) );
check( '  the other 10 kept for an hour on', array( count( (array) ( get_option( TCGiant_Sync_Importer::PRUNE_QUEUE_OPTION, array() )['items'] ?? array() ) ), logged( '10 products kept for now, their listings not yet confirmed as ended on eBay (eBay is asked about 300 a run). They are checked again in an hour.' ) ), array( 10, true ) );
run_queue( 'tcgiant_sync_prune_orphans' );
check( '  the follow-up asks about those 10 and their relists: 20 calls more', array( count( $EBAY['get_items'] ), count( array_unique( $EBAY['get_items'] ) ), get_option( TCGiant_Sync_Importer::PRUNE_QUEUE_OPTION, null ) ), array( 320, 320, null ) );

// ---------------------------------------------------------------------------
echo "\n29. Only the shop's own eBay site imported: an other site's relist is not read (3.27.5 with 3.28.0)\n";
fresh_world( $REMOVE_ON + array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' ) );
$live = filler( 30 );
product( 2901, '92901', 1, array( '_ebay_site' => 'Germany' ) );
listing( '92901', 'Completed', 1, 0, array( 'Site' => 'Germany', 'ListingDetails' => array( 'RelistedItemID' => '93901' ) ) );
listing( '93901', 'Active', 1, 0, array( 'Site' => 'Germany', 'RelistParentID' => '92901' ) );
product( 2902, '92902', 1, array( '_ebay_site' => 'US' ) );
listing( '92902', 'Completed', 1, 0, array( 'Site' => 'US', 'ListingDetails' => array( 'RelistedItemID' => '93902' ) ) );
listing( '93902', 'Active', 1, 0, array( 'Site' => 'US', 'RelistParentID' => '92902' ) );
scanned( $live );
prune();
check( 'a copy on eBay Germany relisted there: its relist is not asked about, nor adopted', array( in_array( '93901', $EBAY['get_items'], true ), in_array( '93901', array_column( $ADOPTED, 0 ), true ) ), array( false, false ) );
check( '  its own listing settled instead', meta( 2901, '_ebay_listing_status' ), 'Ended' );
check( '  one on eBay United States: its relist read and handed to the adoption, as before', array( in_array( '93902', $EBAY['get_items'], true ), in_array( '93902', array_column( $ADOPTED, 0 ), true ) ), array( true, true ) );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
