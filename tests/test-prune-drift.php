<?php
/**
 * Harness: the real full sync's scan, then the real pruner, against an eBay
 * whose Good 'Til Cancelled listings renew between pages.
 *
 * A renewal moves a listing's end time on a month, and eBay sorts the scan by
 * end time, so a listing the scan had not reached slides onto a page it has
 * already read and is never seen. On a 600-listing store whose eight
 * soonest-ending listings renew between each pair of pages, 3.27.x trashed
 * all sixteen of the live listings its scan missed.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/prune-world.php';

fresh_world( array( 'remove_sold_products' => '1' ) );
$N = 600; $RENEW_PER_PAGE = 8; $DAY = 86400;
$T0 = time();
$END = array();
mt_srand( 3 );
for ( $i = 0; $i < $N; $i++ ) {
	$id = (string) ( 700000 + $i );
	$END[ $id ] = $T0 + mt_rand( 60, 30 * $DAY );
	product( 30000 + $i, $id, 1 );
	listing( $id, 'Active', 1, 0 );
}
$CLOCK = $T0;
$EBAY['lister'] = function ( $page, $per, $mod_from, $end_from ) {
	global $END, $CLOCK, $RENEW_PER_PAGE, $DAY;
	if ( $page > 1 ) {
		// Time passes between pages; the soonest-ending listings renew.
		$CLOCK += 600;
		asort( $END );
		foreach ( array_slice( array_keys( $END ), 0, $RENEW_PER_PAGE ) as $id ) { $END[ $id ] += 30 * $DAY; }
	}
	$from = '' !== $end_from ? strtotime( $end_from ) : $CLOCK;
	$set  = array_filter( $END, function ( $e ) use ( $from ) { return $e >= $from; } );
	asort( $set );
	$ids  = array_keys( $set );
	$rows = array();
	foreach ( array_slice( $ids, ( $page - 1 ) * $per, $per ) as $id ) {
		$rows[] = array( 'ItemID' => (string) $id, 'Title' => 'Part ' . $id, 'Quantity' => '1', 'SellingStatus' => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0' ), 'ItemSpecifics' => array(), 'PictureDetails' => array( 'PictureURL' => 'x' ) );
	}
	return array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $rows ), 'PaginationResult' => array( 'TotalNumberOfPages' => (int) ceil( count( $ids ) / $per ) ) );
};

@unlink( sys_get_temp_dir() . '/tcgiant_sync.lock' );
$IMP->start_full_sync( true );
run_queue( 'tcgiant_sync_scan_all_pages' );
$seen   = count( (array) get_option( 'tcgiant_sync_active_ids', array() ) );
$missed = $N - $seen;
printf( "  scan saw %d of %d live listings; %d missed by drift; clean=%s\n", $seen, $N, $missed, var_export( (bool) TCGiant_Sync_Importer::get_sync_state()['scan_complete_clean'], true ) );

// As check_sync_completion() does once a scan with a queued import finishes.
$IMP->prune_orphaned_items();
run_queue( 'tcgiant_sync_prune_orphans' );

$trashed = count( preg_grep( '/^trash/', $EVENTS ) );
check( 'the scan missed some live listings (precondition)', $missed > 0, true );
check( 'no live listing is trashed', $trashed, 0 );
check( 'each missed one was asked about once', count( $EBAY['get_items'] ), $missed );
check( 'the log gives the drift count', logged( $missed . ' still live' ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
