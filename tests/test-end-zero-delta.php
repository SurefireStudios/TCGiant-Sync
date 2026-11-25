<?php
/**
 * Harness: "When you end a listing on eBay, set its website stock to 0?"
 * through the real delta sync (3.46.0 fix round 1).
 *
 * eBay's list of changes, GetSellerEvents, carries a listing's start and end
 * times and nothing of why it ended; GetItem's answer alone has EndingReason.
 * The delta is how most ends made in Seller Hub reach the shop, so with the
 * setting Yes its settle reads each listing the setting could set to 0 once
 * with GetItem - and with No, never. A shop may end many listings at once in
 * Seller Hub: 200 in one window cost 200 GetItems, one a listing, no more
 * than the delta's 40 listings that may need eBay a run (DELTA_ITEMS_PER_RUN)
 * in any one request, over as many requests as with No; a read eBay or the
 * day's ceiling refuses leaves that listing's stock and the delta goes on.
 *
 * The world is tests/support/renewal-world.php's: the real importer,
 * inventory, mapper, sites and cron, GetSellerEvents' rows cut to what eBay
 * documents (event_row()), GetItem answered from a script and counted.
 * Every time compared is made from time().
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-92s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

require __DIR__ . '/support/renewal-world.php';

function gets() { return count( $GLOBALS['EBAY']['get_items'] ); }
function stock( $pid ) { return isset( $GLOBALS['META'][ (int) $pid ]['_stock'] ) ? (int) $GLOBALS['META'][ (int) $pid ]['_stock'] : null; }
function said( $text ) { return count( array_filter( $GLOBALS['LOG'], function ( $l ) use ( $text ) { return false !== strpos( $l[1], $text ); } ) ); }

/**
 * A shop of $n coins, each 3 here on a listing of 3 imported a month ago; the
 * seller has since ended every one in Seller Hub, ten minutes ago, none
 * sold - all in the one window the next delta reads. The setting Yes
 * (switched on an hour ago) or No.
 *
 * @return int[] Item ID => product ID.
 */
function ended_shop( $n, $on, $first = 700000 ) {
	$ids  = array();
	$over = array();
	$then = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$id          = (string) ( $first + $i );
		$ids[]       = $id;
		$then[ $id ] = array( 'Quantity' => '3' );
		$over[ $id ] = array(
			'Quantity'       => '3',
			'SellingStatus'  => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0' ),
			'ListingDetails' => array( 'EndTime' => iso( time() - 600 ), 'EndingReason' => 'NotAvailable' ),
		);
	}
	$pids = shop( $ids, $over, $on ? array( 'end_zeroes_stock' => '1' ) : array(), $then );
	if ( $on ) {
		update_option( TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION, time() - HOUR_IN_SECONDS );
	}
	return $pids;
}
/** The delta through its window, request by request: the GetItems each request made. */
function delta_runs() {
	start_window( 1 );
	$per = array();
	do {
		$before = gets();
		delta_request();
		$per[] = gets() - $before;
	} while ( as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) && count( $per ) < 400 );
	return $per;
}

echo "\nONE SELLER END, THROUGH THE DELTA\n" . str_repeat( '=', 132 ) . "\n";

$tcg_one = array();
foreach ( array( true, false ) as $tcg_on ) {
	$pids = ended_shop( 1, $tcg_on );
	check( ( $tcg_on ? 'Yes' : 'No' ) . ': the coin imported with 3', stock( $pids['700000'] ), 3 );
	$tcg_runs = delta_runs();
	$tcg_one[ $tcg_on ? 'Yes' : 'No' ] = array( stock( $pids['700000'] ), $EBAY['get_items'], array_sum( $tcg_runs ), said( 'is on, so its website stock was set to 0' ) );
}
check( 'Yes: eBay\'s row carries no reason - one GetItem, then 0, said once', $tcg_one['Yes'], array( 0, array( '700000' ), 1, 1 ) );
check( 'No: no GetItem; the settle leaves the 3 (eBay: 3 unsold), as 3.45.0', $tcg_one['No'], array( 3, array(), 0, 0 ) );
check( '  the delta\'s rows as this world plays them: GetSellerEvents\' documented fields, no reason', array_keys( event_row( $EBAY['items']['700000'] )['ListingDetails'] ), array( 'StartTime', 'EndTime' ) );

echo "\n200 SELLER ENDS IN ONE WINDOW\n" . str_repeat( '=', 132 ) . "\n";

$tcg_200 = array();
foreach ( array( true, false ) as $tcg_on ) {
	$tcg_tag  = $tcg_on ? 'Yes' : 'No';
	$pids     = ended_shop( 200, $tcg_on );
	$tcg_runs = delta_runs();
	$tcg_left = array_count_values( array_map( 'stock', array_values( $pids ) ) );
	ksort( $tcg_left );
	$tcg_200[ $tcg_tag ] = array(
		'GetItems'                => gets(),
		'each listing read'       => array_values( array_unique( array_count_values( $EBAY['get_items'] ) ) ),
		'most in one request'     => max( $tcg_runs ),
		'requests'                => count( $tcg_runs ),
		'stock after (count)'     => $tcg_left,
		'lines'                   => said( 'is on, so its website stock was set to 0' ),
		'pushes queued'           => count( array_filter( $QUEUE, function ( $a ) { return TCGiant_Sync_Inventory::PUSH_HOOK === $a['hook']; } ) ),
		'other calls'             => $EBAY['wire'],
	);
}
check( 'Yes: 200 GetItems, each listing read once, never more than 40 in a request, every coin to 0, nothing pushed', $tcg_200['Yes'], array(
	'GetItems'            => 200,
	'each listing read'   => array( 1 ),
	'most in one request' => 40,
	'requests'            => $tcg_200['No']['requests'],
	'stock after (count)' => array( 0 => 200 ),
	'lines'               => 200,
	'pushes queued'       => 0,
	'other calls'         => array(),
) );
check( 'No: no GetItem, every coin keeps its 3, in the same number of requests', $tcg_200['No'], array(
	'GetItems'            => 0,
	'each listing read'   => array(),
	'most in one request' => 0,
	'requests'            => $tcg_200['No']['requests'],
	'stock after (count)' => array( 3 => 200 ),
	'lines'               => 0,
	'pushes queued'       => 0,
	'other calls'         => array(),
) );
check( '  the delta\'s cap of listings that may need eBay a run, as before', TCGiant_Sync_Importer::DELTA_ITEMS_PER_RUN, 40 );
check( '  the requests: at least 200 / 40', $tcg_200['No']['requests'] >= 5, true );

echo "\nREFUSED: THE DAY'S CEILING, OR eBAY\n" . str_repeat( '=', 132 ) . "\n";

// The day's ceiling reached part way: the last 50 reads refused as
// TCGiant_Sync_API refuses one past it.
$pids = ended_shop( 200, true );
foreach ( array_slice( array_keys( $pids ), 150 ) as $tcg_id ) {
	$EBAY['answers'][ $tcg_id ] = new WP_Error( 'rate_limited', 'Daily API call budget exhausted. Will resume tomorrow.', array( 'daily_budget' => true ) );
}
$tcg_runs = delta_runs();
$tcg_left = array_count_values( array_map( 'stock', array_values( $pids ) ) );
ksort( $tcg_left );
check( '150 read and set to 0; the 50 refused left at 3, one warning each; every listing asked once', array( $tcg_left, said( 'eBay could not be asked why it ended (Daily API call budget exhausted. Will resume tomorrow.)' ), array_values( array_unique( array_count_values( $EBAY['get_items'] ) ) ) ), array( array( 0 => 150, 3 => 50 ), 50, array( 1 ) ) );
check( '  the delta went through its window all the same: settled, marked Ended', array( count( array_filter( array_values( $pids ), function ( $pid ) { return 'Ended' === ( $GLOBALS['META'][ $pid ]['_ebay_listing_status'] ?? '' ); } ) ), as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) ), array( 200, false ) );
$EBAY['get_items'] = array();
$EBAY['answers']   = array();
start_window( 1 );
delta_request();
check( '  the same window read again: no read for any of them - settled, and asked once', gets(), 0 );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
