<?php
/**
 * Order follow-through on a staging copy, for tests/test-order-followthrough.php:
 * the copy's constant is set as wp-config sets it, before anything loads, so
 * it runs in a process of its own. Prints one line of JSON: what was queued
 * and said as orders were completed on the copy, what a feedback run there
 * sent and read, whether a run spins; what the refund sweep read, the
 * order's status after it, and the orders it made.
 *
 * Given a file name, it also writes the copy's database there as it is
 * left - options, orders, eBay's orders - for the harness to carry to a
 * live site, as a copy pushed to live is (fix round 1, L2).
 *
 * @package TCGiant_Sync
 */

define( 'TCGIANT_SYNC_IS_STAGING', true );

require __DIR__ . '/followthrough-world.php';

shop( array( 'feedback_buyers' => 'completed' ) );
$a = stored_order( ebay( array( 'id' => '40-00001-00001' ) ) );
$b = stored_order( ebay( array( 'id' => '40-00004-00001' ) ) );
$a->update_status( 'completed' );
$b->update_status( 'completed' );
reset_calls();
as_schedule_single_action( time(), TCGiant_Sync_Order_Followthrough::FEEDBACK_HOOK, array(), TCGiant_Sync_Order_Followthrough::GROUP );
play( TCGiant_Sync_Order_Followthrough::FEEDBACK_HOOK );
$feedback = array(
	'complete'       => calls( 'CompleteSale' ),
	'feedback_reads' => calls( 'GetOrders' ),
	'noted'          => log_count( 'Feedback for eBay buyers is off on this site: it looks like a staging or development copy' ),
	'queued'         => count( get_option( TCGiant_Sync_Order_Followthrough::QUEUE_OPTION, array() ) ),
	'pending'        => count( pending( TCGiant_Sync_Order_Followthrough::FEEDBACK_HOOK ) ),
);
$copy = array(
	'OPTIONS' => $GLOBALS['OPTIONS'],
	'ORDERS'  => $GLOBALS['ORDERS'],
	'IDX'     => $GLOBALS['IDX'],
	'EBAY'    => $GLOBALS['EBAY'],
);

// A queue the live shop made, copied here with its database: a run on the
// copy sends none of it, reads nothing, keeps it, and does not spin.
$c = stored_order( ebay( array( 'id' => '40-00005-00001' ) ) );
update_option( TCGiant_Sync_Order_Followthrough::QUEUE_OPTION, array( $c->get_id() => array( 'ebay' => '40-00005-00001', 'why' => 'completed', 'at' => time() - 60 ) ) );
reset_calls();
as_schedule_single_action( time(), TCGiant_Sync_Order_Followthrough::FEEDBACK_HOOK, array(), TCGiant_Sync_Order_Followthrough::GROUP );
play( TCGiant_Sync_Order_Followthrough::FEEDBACK_HOOK );
$feedback['live_queue'] = array(
	calls( 'CompleteSale' ) + calls( 'GetOrders' ),
	count( get_option( TCGiant_Sync_Order_Followthrough::QUEUE_OPTION, array() ) ),
	count( pending( TCGiant_Sync_Order_Followthrough::FEEDBACK_HOOK ) ),
);

shop();
$e = ebay( array( 'id' => '40-00002-00001', 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 600 ) ) ) );
$o = stored_order( $e );
ebay( array( 'id' => '40-00003-00001', 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 600 ) ) ) );
$GLOBALS['EBAY']['40-00003-00001']['created'] = time() - 5 * DAY_IN_SECONDS;
reset_calls();
do_action( TCGiant_Sync_Order_Followthrough::SWEEP_HOOK );

if ( ! empty( $argv[1] ) ) {
	file_put_contents( $argv[1], serialize( $copy ) );
}

echo wp_json_encode( array_merge( $feedback, array(
	'sweep_reads' => calls( 'GetOrders' ),
	'refunded'    => $o->get_status(),
	'created'     => $GLOBALS['CREATED'],
) ) ), "\n";
