<?php
/**
 * Harness: order follow-through (3.37.0) - positive feedback for eBay buyers,
 * and the refunds made on eBay recorded on the WooCommerce orders imported
 * from it - on support/followthrough-world.php.
 *
 * Feedback: each trigger, and Off sending nothing; once for each line, a
 * multi-line order a call a line, by its OrderLineItemID or its ItemID and
 * TransactionID; never for an order eBay says is unpaid, cancelled or
 * refunded, or that is cancelled, refunded, failed or unpaid here, or past
 * eBay's 60 days; eBay's "already left" taken as done; any other refusal
 * tried twice more on later runs, then left with one log line; a call that
 * never reached eBay not counted; no old order sent anything when the
 * setting is turned on; the budget guard, Emergency Stop, the priority, the
 * comment; a staging copy (support/followthrough-staging.php, a process of
 * its own).
 *
 * Refunds: full and partial, each once, by eBay's reference; pending or
 * failed ones left until complete; the cap at what is left; no order made,
 * no restock, no payment refunded, no customer email, no eBay call from the
 * status change; the window - two days the first time, the overlap, eBay's
 * 30 days - its pages, resume after a failure, the budget and Emergency
 * Stop.
 *
 * Byte-identity: the order import's GetOrders (CreateTime) and the tracking
 * push's CompleteSale, whatever the two settings say.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-96s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

require __DIR__ . '/support/followthrough-world.php';

$F = 'TCGiant_Sync_Order_Followthrough';

/** The feedback CompleteSale bodies sent, inner. */
function feedback_bodies() {
	$out = array();
	foreach ( $GLOBALS['BODIES']['CompleteSale'] ?? array() as $b ) {
		if ( false !== strpos( $b, '<FeedbackInfo>' ) ) {
			$out[] = inner( 'CompleteSale', $b );
		}
	}
	return $out;
}

/** The expected feedback body for a line. */
function fb_body( $line, $buyer, $comment = 'Great buyer, thank you!' ) {
	return "\n<FeedbackInfo>\n<CommentText>" . $comment . "</CommentText>\n<CommentType>Positive</CommentType>\n<TargetUser>" . $buyer . "</TargetUser>\n</FeedbackInfo>\n<OrderLineItemID>" . $line . '</OrderLineItemID>';
}

/** Play every pending feedback run, at most $max. */
function play_feedback( $max = 10 ) {
	$n = 0;
	while ( $n < $max && play( TCGiant_Sync_Order_Followthrough::FEEDBACK_HOOK ) ) {
		$n++;
	}
	return $n;
}

/** Make every waiting line and order due now, as later runs find them. */
function make_due() {
	$q = get_option( TCGiant_Sync_Order_Followthrough::QUEUE_OPTION, array() );
	foreach ( $q as $id => $e ) {
		$q[ $id ]['next'] = 0;
	}
	if ( $q ) {
		update_option( TCGiant_Sync_Order_Followthrough::QUEUE_OPTION, $q );
	}
	foreach ( $GLOBALS['ORDERS'] as $o ) {
		foreach ( $o->get_items() as $item ) {
			$r = $item->get_meta( TCGiant_Sync_Order_Followthrough::FEEDBACK_META );
			if ( is_array( $r ) && isset( $r['next'] ) ) {
				$r['next'] = 0;
				$item->update_meta_data( TCGiant_Sync_Order_Followthrough::FEEDBACK_META, $r );
			}
		}
	}
	if ( ! pending( TCGiant_Sync_Order_Followthrough::FEEDBACK_HOOK ) ) {
		as_schedule_single_action( time(), TCGiant_Sync_Order_Followthrough::FEEDBACK_HOOK, array(), TCGiant_Sync_Order_Followthrough::GROUP );
	}
}

/** The import's CreateTime body as 729b184 built it, from the request's own times. */
function import_body_729b184( $body ) {
	$from = tag( $body, 'CreateTimeFrom' );
	$to   = tag( $body, 'CreateTimeTo' );
	$page = tag( $body, 'PageNumber' );
	return '
<CreateTimeFrom>' . $from . '</CreateTimeFrom>
<CreateTimeTo>' . $to . '</CreateTimeTo>
<OrderRole>Seller</OrderRole>
<OrderStatus>All</OrderStatus>
<Pagination>
<EntriesPerPage>100</EntriesPerPage>
<PageNumber>' . (int) $page . '</PageNumber>
</Pagination>';
}

/** The tracking push's body as 729b184 built it. */
function tracking_body_729b184( $order_id, $number, $carrier ) {
	return '
<OrderID>' . $order_id . '</OrderID>
<Shipment>
<ShipmentTrackingDetails>
<ShipmentTrackingNumber>' . $number . '</ShipmentTrackingNumber>
<ShippingCarrierUsed>' . $carrier . '</ShippingCarrierUsed>
</ShipmentTrackingDetails>
</Shipment>
<Shipped>true</Shipped>';
}

/** The tracking bodies sent. */
function tracking_bodies() {
	$out = array();
	foreach ( $GLOBALS['BODIES']['CompleteSale'] ?? array() as $b ) {
		if ( false !== strpos( $b, '<Shipment>' ) ) {
			$out[] = inner( 'CompleteSale', $b );
		}
	}
	return $out;
}

echo "\n  Feedback: Off sends nothing\n";

shop( array( 'feedback_buyers' => 'off' ) );
ebay( array( 'id' => '01-00001-00001' ) );
run_import();
$o = wc_for( '01-00001-00001' );
check( 'Off: the paid order is imported, Processing', array( is_object( $o ), $o ? $o->get_status() : '' ), array( true, 'processing' ) );
check( '  nothing queued, nothing scheduled', array( get_option( $F::QUEUE_OPTION, null ), pending( $F::FEEDBACK_HOOK ) ), array( null, array() ) );
$o->update_status( 'completed' );
check( '  marked Completed: still nothing queued; no CompleteSale (no tracking either)', array( get_option( $F::QUEUE_OPTION, null ), calls( 'CompleteSale' ) ), array( null, 0 ) );
check( '  the import\'s GetOrders body is 729b184\'s', inner( 'GetOrders', $BODIES['GetOrders'][0] ), import_body_729b184( $BODIES['GetOrders'][0] ) );

shop();
ebay( array( 'id' => '01-00002-00001' ) );
run_import();
check( 'never chosen: Off, nothing queued', array( $F::feedback_mode(), get_option( $F::QUEUE_OPTION, null ) ), array( 'off', null ) );
shop( array( 'feedback_buyers' => 'paid', 'enable_order_import' => 0 ) );
check( 'order import off: feedback is Off, refunds are not recorded', array( $F::feedback_mode(), $F::records_refunds() ), array( 'off', false ) );
shop( array( 'feedback_buyers' => 'sometimes' ) );
check( 'an unknown choice is Off', $F::feedback_mode(), 'off' );

echo "\n  Feedback: When eBay says the order is paid\n";

shop( array( 'feedback_buyers' => 'paid' ) );
ebay( array( 'id' => '02-00001-00001', 'buyer' => 'happy_buyer' ) );
run_import();
$o = wc_for( '02-00001-00001' );
$q = get_option( $F::QUEUE_OPTION, array() );
check( 'paid as it arrives: queued by its trigger', array( $q[ $o->get_id() ]['ebay'] ?? '', $q[ $o->get_id() ]['why'] ?? '' ), array( '02-00001-00001', 'paid' ) );
$p = pending( $F::FEEDBACK_HOOK );
check( '  one run scheduled, in its group, after the stock pushes (priority 20)', array( count( $p ), $p[0]['group'] ?? '', $p[0]['priority'] ?? 0 ), array( 1, 'tcgiant_sync_orders', 20 ) );
check( '  nothing sent yet: the import made only its GetOrders', array( calls( 'CompleteSale' ), calls( 'GetOrders' ) ), array( 0, 1 ) );
reset_calls();
play_feedback();
$line = $EBAY['02-00001-00001']['lines'][0]['line'];
check( 'the run: one GetOrders by OrderID, one CompleteSale', array( calls( 'GetOrders' ), calls( 'CompleteSale' ) ), array( 1, 1 ) );
check( '  GetOrders asks for the order by its ID, as the follow-up does', inner( 'GetOrders', $BODIES['GetOrders'][0] ), "\n<OrderIDArray>\n<OrderID>02-00001-00001</OrderID>\n</OrderIDArray>\n<OrderRole>Seller</OrderRole>\n<Pagination>\n<EntriesPerPage>100</EntriesPerPage>\n<PageNumber>1</PageNumber>\n</Pagination>" );
check( '  CompleteSale carries FeedbackInfo and the line, nothing else', feedback_bodies(), array( fb_body( $line, 'happy_buyer' ) ) );
check( '  the line item records it: left, one try, the line', array( fb_state( $o ), fb( $o )['tries'] ?? 0, fb( $o )['line'] ?? '' ), array( 'left', 1, $line ) );
check( '  recorded before the call went (sending, then left)', array_map( function ( $w ) { return $w[0]['state'] ?? ''; }, $ITEM_WRITES ), array( 'sending', 'left' ) );
check( '  one log line, and an order note', array( log_count( 'Feedback: positive feedback left for eBay buyer happy_buyer on eBay order 02-00001-00001' ), false !== strpos( (string) end( $o->notes ), 'positive feedback on eBay for the buyer, happy_buyer' ) ), array( 1, true ) );
check( '  the queue is empty, and no run left', array( get_option( $F::QUEUE_OPTION, null ), pending( $F::FEEDBACK_HOOK ) ), array( null, array() ) );
reset_calls();
run_import();
play_feedback();
check( 'read again by the next import: nothing queued or sent again', array( get_option( $F::QUEUE_OPTION, null ), calls( 'CompleteSale' ) ), array( null, 0 ) );
$o->update_status( 'completed' );
play_feedback();
check( 'marked Completed after: no feedback (the trigger is paid), only the tracking push\'s rules', calls( 'CompleteSale' ), 0 );

// Unpaid, then paid: queued when the import sees it paid.
shop( array( 'feedback_buyers' => 'paid' ) );
ebay( array( 'id' => '02-00002-00001', 'status' => 'Active' ) );
run_import();
$o = wc_for( '02-00002-00001' );
check( 'unpaid as it arrives: Pending payment, not queued', array( $o->get_status(), get_option( $F::QUEUE_OPTION, null ) ), array( 'pending', null ) );
$EBAY['02-00002-00001']['status'] = 'Completed';
reset_calls();
run_import();
check( 'paid on eBay, read again: Processing, and queued', array( $o->get_status(), isset( get_option( $F::QUEUE_OPTION, array() )[ $o->get_id() ] ) ), array( 'processing', true ) );
play_feedback();
check( '  and sent its feedback', array( fb_state( $o ), calls( 'CompleteSale' ) ), array( 'left', 1 ) );

// No backfill: orders paid before the setting was on get nothing.
shop( array( 'feedback_buyers' => 'off' ) );
ebay( array( 'id' => '02-00003-00001' ) );
run_import();
$o = wc_for( '02-00003-00001' );
$OPTIONS['tcgiant_sync_ebay_settings']['feedback_buyers'] = 'paid';
reset_calls();
run_import();
play_feedback();
check( 'turned on after: an order paid before is read again but not queued, nothing sent', array( get_option( $F::QUEUE_OPTION, null ), calls( 'CompleteSale' ), fb_state( $o ) ), array( null, 0, '' ) );

// Action Scheduler refusing the run: the order import and WooCommerce's
// status change go on as before; only feedback is lost, and said.
shop( array( 'feedback_buyers' => 'paid' ) );
ebay( array( 'id' => '02-00004-00001', 'status' => 'Active' ) );
run_import();
$o = wc_for( '02-00004-00001' );
$EBAY['02-00004-00001']['status'] = 'Completed';
$AS_THROWS = true;
run_import();
$AS_THROWS = false;
check( 'Action Scheduler refusing the run as eBay says paid: the order still goes to Processing, the error logged once', array( $o->get_status(), log_count( 'could not be queued for feedback' ), logged( 'could not be brought up to date' ) ), array( 'processing', 1, false ) );
shop( array( 'feedback_buyers' => 'completed' ) );
$o = stored_order( ebay( array( 'id' => '02-00005-00001' ) ) );
$o->update_meta_data( '_tracking_number', '1Z5' );
$AS_THROWS = true;
$o->update_status( 'completed' );
$AS_THROWS = false;
check( '  and as an order is completed: Completed, its tracking pushed, the error logged', array( $o->get_status(), count( tracking_bodies() ), log_count( 'could not be queued for feedback' ) ), array( 'completed', 1, 1 ) );

echo "\n  Feedback: When the order is marked Completed here\n";

shop( array( 'feedback_buyers' => 'completed' ) );
ebay( array( 'id' => '03-00001-00001', 'buyer' => 'buyer_three' ) );
run_import();
$o = wc_for( '03-00001-00001' );
check( 'paid as it arrives: not queued (the trigger is Completed)', get_option( $F::QUEUE_OPTION, null ), null );
$o->update_meta_data( '_tracking_number', '1Z999AA10123456784' );
$o->update_meta_data( '_tracking_provider', 'UPS' );
reset_calls();
$o->update_status( 'completed' );
check( 'marked Completed: the tracking push goes as before, its body 729b184\'s', tracking_bodies(), array( tracking_body_729b184( '03-00001-00001', '1Z999AA10123456784', 'UPS' ) ) );
check( '  and the order is queued for feedback, a call of its own later', array( isset( get_option( $F::QUEUE_OPTION, array() )[ $o->get_id() ] ), count( feedback_bodies() ) ), array( true, 0 ) );
play_feedback();
check( '  the run sends one CompleteSale of feedback alone', array( count( feedback_bodies() ), count( tracking_bodies() ), fb_state( $o ) ), array( 1, 1, 'left' ) );
$o->update_status( 'processing' );
$o->update_status( 'completed' );
play_feedback();
check( 'Completed again: queued again, but the line has its feedback - no second feedback call, not even a read', array( count( feedback_bodies() ), calls( 'GetOrders' ), get_option( $F::QUEUE_OPTION, null ) ), array( 1, 1, null ) );
$web = wc_create_order();
$web->update_status( 'completed' );
check( 'a website order marked Completed: nothing queued', get_option( $F::QUEUE_OPTION, null ), null );
$web = wc_create_order();
$web->update_meta_data( '_ebay_order_id', '03-99999-00001' );
$web->update_status( 'completed' );
check( '  nor one another plugin gave an eBay order ID, not imported from eBay', get_option( $F::QUEUE_OPTION, null ), null );

// An order imported long before, completed now: it reaches the trigger now.
shop( array( 'feedback_buyers' => 'off' ) );
$e = ebay( array( 'id' => '03-00002-00001', 'created' => time() - 10 * DAY_IN_SECONDS ) );
$o = stored_order( $e );
$OPTIONS['tcgiant_sync_ebay_settings']['feedback_buyers'] = 'completed';
$o->update_status( 'completed' );
play_feedback();
check( 'imported 10 days ago, marked Completed after the setting is on: feedback', array( fb_state( $o ), calls( 'CompleteSale' ) ), array( 'left', 1 ) );

echo "\n  Feedback: a line at a time\n";

shop( array( 'feedback_buyers' => 'paid' ) );
ebay( array( 'id' => '04-00001-00001', 'total' => 30, 'lines' => array( array( 'item' => '110000000001' ), array( 'item' => '110000000002' ), array( 'item' => '110000000001' ) ) ) );
run_import();
$o = wc_for( '04-00001-00001' );
play_feedback();
$L = array_column( $EBAY['04-00001-00001']['lines'], 'line' );
check( 'three lines (one item twice): three CompleteSale calls, one GetOrders', array( calls( 'CompleteSale' ), calls( 'GetOrders' ) - 1 ), array( 3, 1 ) );
check( '  each with its own line', feedback_bodies(), array( fb_body( $L[0], 'buyer_04-00001-00001' ), fb_body( $L[1], 'buyer_04-00001-00001' ), fb_body( $L[2], 'buyer_04-00001-00001' ) ) );
check( '  each line item records its own line', array( fb( $o, 0 )['line'] ?? '', fb( $o, 1 )['line'] ?? '', fb( $o, 2 )['line'] ?? '' ), $L );
check( '  three log lines, one order note', array( log_count( 'Feedback: positive feedback left' ), log_count( 'Feedback: positive feedback left' ) > 0 ? substr_count( implode( '|', $o->notes ), 'positive feedback on eBay' ) : 0 ), array( 3, 1 ) );

shop( array( 'feedback_buyers' => 'paid' ) );
ebay( array( 'id' => '04-00002-00001', 'lines' => array( array( 'item' => '110000000003', 'tx' => '0', 'line' => '' ) ) ) );
run_import();
play_feedback();
check( 'a line without an OrderLineItemID: by its ItemID and TransactionID', feedback_bodies(), array( "\n<FeedbackInfo>\n<CommentText>Great buyer, thank you!</CommentText>\n<CommentType>Positive</CommentType>\n<TargetUser>buyer_04-00002-00001</TargetUser>\n</FeedbackInfo>\n<ItemID>110000000003</ItemID>\n<TransactionID>0</TransactionID>" ) );

// The same item twice, its second line recorded on the first line item: the
// first line goes on the other line item, the recorded one is done.
shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '04-00004-00001', 'lines' => array( array( 'item' => '110000000001' ), array( 'item' => '110000000001' ) ) ) );
$o = stored_order( $e );
$items = $o->get_items();
$items[0]->update_meta_data( $F::FEEDBACK_META, array( 'state' => 'left', 'tries' => 1, 'at' => time(), 'line' => $e['lines'][1]['line'] ) );
$o->update_status( 'completed' );
play_feedback();
check( 'one item twice, the second line recorded on the first line item: only the first line sent, on the other line item', array( feedback_bodies(), fb( $o, 1 )['line'] ?? '' ), array( array( fb_body( $e['lines'][0]['line'], 'buyer_04-00004-00001' ) ), $e['lines'][0]['line'] ) );

// One line already done: the rest only.
shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '04-00003-00001', 'lines' => array( array( 'item' => '110000000001' ), array( 'item' => '110000000002' ) ) ) );
$o = stored_order( $e );
$items = $o->get_items();
$items[0]->update_meta_data( $F::FEEDBACK_META, array( 'state' => 'left', 'tries' => 1, 'at' => time(), 'line' => $e['lines'][0]['line'] ) );
$o->update_status( 'completed' );
play_feedback();
check( 'one line already left: only the other is sent', feedback_bodies(), array( fb_body( $e['lines'][1]['line'], 'buyer_04-00003-00001' ) ) );

echo "\n  Feedback: never\n";

$never = array(
	'eBay says Cancelled'               => array( array( 'status' => 'Cancelled' ), 'processing', 'eBay says it is Cancelled, not paid', 1 ),
	'eBay says Active (unpaid)'         => array( array( 'status' => 'Active' ), 'processing', 'eBay says it is Active, not paid', 1 ),
	'eBay records a cancellation'       => array( array( 'cancel' => 'CancelComplete' ), 'processing', 'eBay records a cancellation (CancelComplete)', 1 ),
	'eBay records a refund'             => array( array( 'refunds' => array( array( 'amount' => 5, 'time' => time() - 60 ) ) ), 'processing', 'eBay records a refund', 1 ),
	'cancelled here'                    => array( array(), 'cancelled', 'it is Cancelled here', 0 ),
	'refunded here'                     => array( array(), 'refunded', 'it is Refunded here', 0 ),
	'failed here'                       => array( array(), 'failed', 'it is Failed here', 0 ),
	'unpaid here'                       => array( array(), 'pending', 'it is Pending payment here', 0 ),
);
foreach ( $never as $label => $case ) {
	shop( array( 'feedback_buyers' => 'completed' ) );
	$e = ebay( array_merge( array( 'id' => '05-' . substr( md5( $label ), 0, 5 ) . '-00001' ), $case[0] ) );
	$o = stored_order( $e, 'processing' );
	$o->update_status( 'completed' );
	if ( 'processing' !== $case[1] ) {
		$o->status = $case[1];
		$o->saved_status = $case[1];
	}
	play_feedback();
	check( 'never - ' . $label . ': no CompleteSale, the reason logged, the order let go', array( calls( 'CompleteSale' ), calls( 'GetOrders' ), logged( $case[2] ), get_option( $F::QUEUE_OPTION, null ) ), array( 0, $case[3], true, null ) );
}

shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '05-00009-00001' ) );
$o = stored_order( $e );
$o->update_status( 'completed' );
$o->refunds[] = (object) array( 'amount' => 2.0 );
play_feedback();
check( 'never - a refund here: no call at all', array( calls( 'GetOrders' ), calls( 'CompleteSale' ), logged( 'it has a refund' ) ), array( 0, 0, true ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '05-00010-00001', 'created' => time() - 61 * DAY_IN_SECONDS ) );
$o = stored_order( $e );
$o->created = time() - 61 * DAY_IN_SECONDS;
$o->update_status( 'completed' );
play_feedback();
check( 'never - made 61 days ago here: no call at all', array( calls( 'GetOrders' ), calls( 'CompleteSale' ), logged( 'past eBay\'s time for feedback' ) ), array( 0, 0, true ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '05-00011-00001', 'created' => time() - 61 * DAY_IN_SECONDS ) );
$o = stored_order( $e );
$o->update_status( 'completed' );
play_feedback();
check( 'never - eBay\'s sale 61 days ago (the order here newer): late, no CompleteSale', array( calls( 'GetOrders' ), calls( 'CompleteSale' ), fb_state( $o ) ), array( 1, 0, 'late' ) );
check( '  59 days 23 hours is late too (an hour kept inside the window)', ( function () {
	shop( array( 'feedback_buyers' => 'completed' ) );
	$e = ebay( array( 'id' => '05-00012-00001', 'created' => time() - 60 * DAY_IN_SECONDS + 1800 ) );
	$o = stored_order( $e );
	$o->update_status( 'completed' );
	play_feedback();
	return array( calls( 'CompleteSale' ), fb_state( $o ) );
} )(), array( 0, 'late' ) );
check( '  59 days: sent', ( function () {
	shop( array( 'feedback_buyers' => 'completed' ) );
	$e = ebay( array( 'id' => '05-00013-00001', 'created' => time() - 59 * DAY_IN_SECONDS ) );
	$o = stored_order( $e );
	$o->update_status( 'completed' );
	play_feedback();
	return array( calls( 'CompleteSale' ), fb_state( $o ) );
} )(), array( 1, 'left' ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '05-00014-00001', 'buyer' => '' ) );
$o = stored_order( $e );
$o->update_status( 'completed' );
play_feedback();
check( 'never - eBay names no buyer', array( calls( 'CompleteSale' ), logged( 'eBay did not say who the buyer is' ) ), array( 0, true ) );

echo "\n  Feedback: eBay's answers\n";

foreach ( array( '834' => 'Adding feedback failed: duplicate feedback.', '55' => 'Adding feedback failed: invalid item number or invalid transaction or feedback already left.' ) as $code => $words ) {
	shop( array( 'feedback_buyers' => 'completed' ) );
	$e = ebay( array( 'id' => '06-000' . $code . '-00001' ) );
	$o = stored_order( $e );
	$COMPLETE = array( array( $code, $words ) );
	$o->update_status( 'completed' );
	play_feedback();
	make_due();
	play_feedback();
	$o->update_status( 'processing' );
	$o->update_status( 'completed' );
	play_feedback();
	check( 'eBay ' . $code . ' (already left): taken as done, said once, never sent again, queued again or not', array( fb_state( $o ), calls( 'CompleteSale' ), log_count( 'was already left' ), get_option( $F::QUEUE_OPTION, null ) ), array( 'already', 1, 1, null ) );
}

shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '06-00003-00001' ) );
$o = stored_order( $e );
$COMPLETE = array( array( '21916062', 'Cannot leave feedback after 60 days.' ) );
$o->update_status( 'completed' );
play_feedback();
check( 'eBay 21916062 (after 60 days): late, not sent again', array( fb_state( $o ), calls( 'CompleteSale' ), get_option( $F::QUEUE_OPTION, null ) ), array( 'late', 1, null ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '06-00004-00001' ) );
$o = stored_order( $e );
$COMPLETE = array( array( '368', 'Feedback function is temporarily unavailable.' ), array( '368', 'Feedback function is temporarily unavailable.' ), array( '368', 'Feedback function is temporarily unavailable.' ), 'ok' );
$o->update_status( 'completed' );
play_feedback();
check( 'another refusal: refused, one try, logged once', array( fb_state( $o ), fb( $o )['tries'] ?? 0, log_count( 'eBay refused feedback for eBay order 06-00004-00001' ) ), array( 'refused', 1, 1 ) );
$p = pending( $F::FEEDBACK_HOOK );
check( '  a run again when it is due, an hour on', array( count( $p ), ( $p[0]['when'] ?? 0 ) >= time() + 3500 ), array( 1, true ) );
play_feedback();
check( '  a run before then sends nothing', calls( 'CompleteSale' ), 1 );
$o->update_status( 'processing' );
$o->update_status( 'completed' );
check( '  completed again meanwhile: the order keeps its wait', ( get_option( $F::QUEUE_OPTION, array() )[ $o->get_id() ]['next'] ?? 0 ) > time() + 3500, true );
$tcg_q = get_option( $F::QUEUE_OPTION, array() );
$tcg_q[ $o->get_id() ]['next'] = 0;
update_option( $F::QUEUE_OPTION, $tcg_q );
play_feedback( 1 );
check( '  the order due but its line not: nothing sent', calls( 'CompleteSale' ), 1 );
make_due();
play_feedback();
check( '  the second try: refused again, not logged again', array( fb_state( $o ), fb( $o )['tries'] ?? 0, log_count( 'eBay refused feedback for eBay order 06-00004-00001' ), calls( 'CompleteSale' ) ), array( 'refused', 2, 1, 2 ) );
make_due();
play_feedback();
check( '  the third: given up, one line saying so', array( fb_state( $o ), fb( $o )['tries'] ?? 0, log_count( 'times — Feedback function is temporarily unavailable.. It is not tried again' ), calls( 'CompleteSale' ) ), array( 'gave_up', 3, 1, 3 ) );
make_due();
play_feedback();
$o->update_status( 'processing' );
$o->update_status( 'completed' );
play_feedback();
check( '  never a fourth call, queued again or not, and nothing more said', array( calls( 'CompleteSale' ), get_option( $F::QUEUE_OPTION, null ), logged( 'without an answer' ), log_count( 'It is not tried again' ) ), array( 3, null, false, 1 ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '06-00008-00001' ) );
$o = stored_order( $e );
$COMPLETE = array( 'partial' );
$o->update_status( 'completed' );
play_feedback( 1 );
check( 'eBay answers PartialFailure: a refusal, not left', array( fb_state( $o ), fb( $o )['tries'] ?? 0 ), array( 'refused', 1 ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '06-00005-00001' ) );
$o = stored_order( $e );
$COMPLETE = array( array( '368', 'Feedback function is temporarily unavailable.' ), 'ok' );
$o->update_status( 'completed' );
play_feedback();
make_due();
play_feedback();
check( 'refused, then taken: left at the second try', array( fb_state( $o ), fb( $o )['tries'] ?? 0, calls( 'CompleteSale' ) ), array( 'left', 2, 2 ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '06-00006-00001' ) );
$o = stored_order( $e );
$COMPLETE = array( array( '518', 'Calls to this call have exceeded the call limit.' ) );
$o->update_status( 'completed' );
play( $F::FEEDBACK_HOOK );
$p = pending( $F::FEEDBACK_HOOK );
check( 'eBay\'s call limit (518): not a try - the line as it was, the order waits, a run later', array( fb_state( $o ), fb( $o )['tries'] ?? 0, isset( get_option( $F::QUEUE_OPTION, array() )[ $o->get_id() ] ), count( $p ), ( $p[0]['when'] ?? 0 ) > time() + 1000 ), array( '', 0, true, 1, true ) );
make_due();
play_feedback();
check( '  and goes next time', array( fb_state( $o ), fb( $o )['tries'] ?? 0 ), array( 'left', 1 ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '06-00007-00001' ) );
$o = stored_order( $e );
$items = $o->get_items();
$items[0]->update_meta_data( $F::FEEDBACK_META, array( 'state' => 'sending', 'tries' => 1, 'at' => time() - 100, 'line' => $e['lines'][0]['line'] ) );
$o->update_status( 'completed' );
play_feedback();
check( 'a request killed during its call: the next run tries again, the try counted', array( fb_state( $o ), fb( $o )['tries'] ?? 0 ), array( 'left', 2 ) );
$items[0]->update_meta_data( $F::FEEDBACK_META, array( 'state' => 'sending', 'tries' => 3, 'at' => time() - 100, 'line' => $e['lines'][0]['line'] ) );
reset_calls();
$o->update_status( 'processing' );
$o->update_status( 'completed' );
play_feedback();
check( '  killed at its third: given up, no call', array( fb_state( $o ), calls( 'CompleteSale' ), logged( 'without an answer' ) ), array( 'gave_up', 0, true ) );

echo "\n  Feedback: reads, the comment and the run's size\n";

shop( array( 'feedback_buyers' => 'completed', 'feedback_comment' => 'Fast payment & a pleasure <3 - thank you!' ) );
$e = ebay( array( 'id' => '07-00001-00001' ) );
$o = stored_order( $e );
$o->update_status( 'completed' );
play_feedback();
check( 'the shop\'s comment, escaped', feedback_bodies(), array( fb_body( $e['lines'][0]['line'], 'buyer_07-00001-00001', 'Fast payment &amp; a pleasure &lt;3 - thank you!' ) ) );
check( 'a comment of 100 characters is cut to 80; an empty one is the default', array( mb_strlen( $F::feedback_comment( array( 'feedback_comment' => str_repeat( 'é', 100 ) ) ) ), $F::feedback_comment( array( 'feedback_comment' => '   ' ) ) ), array( 80, 'Great buyer, thank you!' ) );

shop( array( 'feedback_buyers' => 'completed' ) );
for ( $i = 1; $i <= 120; $i++ ) {
	$o = stored_order( ebay( array( 'id' => sprintf( '08-%05d-00001', $i ) ) ) );
	$o->update_status( 'completed' );
}
check( '120 orders completed: one run waiting', count( pending( $F::FEEDBACK_HOOK ) ), 1 );
play( $F::FEEDBACK_HOOK );
$p = pending( $F::FEEDBACK_HOOK );
check( 'the first run: 50 sent from a read of 100, the rest a minute on', array( calls( 'CompleteSale' ), calls( 'GetOrders' ), substr_count( $BODIES['GetOrders'][0], '<OrderID>' ), count( $p ), ( $p[0]['when'] ?? 0 ) >= time() + 59 ), array( 50, 1, 100, 1, true ) );
check( '  the queue never autoloaded', $AUTOLOAD[ $F::QUEUE_OPTION ] ?? null, false );
play_feedback();
check( 'then the rest: 120 sent in all, none twice, in 3 reads, the queue empty', array( calls( 'CompleteSale' ), count( array_unique( feedback_bodies() ) ), calls( 'GetOrders' ), get_option( $F::QUEUE_OPTION, null ) ), array( 120, 120, 3, null ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$a = stored_order( ebay( array( 'id' => '09-00001-00001' ) ) );
$b = stored_order( ebay( array( 'id' => '09-00002-00001' ) ) );
$a->update_status( 'completed' );
$b->update_status( 'completed' );
$GETORDERS_FAIL = array( 'refuse' );
play( $F::FEEDBACK_HOOK );
$q = get_option( $F::QUEUE_OPTION, array() );
check( 'eBay refuses the read: both wait, marked', array( $q[ $a->get_id() ]['unread'] ?? 0, $q[ $b->get_id() ]['unread'] ?? 0, calls( 'CompleteSale' ) ), array( 1, 1, 0 ) );
reset_calls();
play( $F::FEEDBACK_HOOK );
check( '  next run: one asked about alone', array( calls( 'GetOrders' ), substr_count( $BODIES['GetOrders'][0] ?? '', '<OrderID>' ), calls( 'CompleteSale' ) ), array( 1, 1, 1 ) );
play_feedback();
check( '  and the other the run after: both sent', array( fb_state( $a ), fb_state( $b ) ), array( 'left', 'left' ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$a = stored_order( ebay( array( 'id' => '09-00003-00001' ) ) );
$EBAY_HIDE['09-00003-00001'] = true;
$a->update_status( 'completed' );
play_feedback();
make_due();
play_feedback();
make_due();
play_feedback();
check( 'an order eBay never returns: let go after 3 reads, with one line, nothing sent', array( calls( 'GetOrders' ), calls( 'CompleteSale' ), get_option( $F::QUEUE_OPTION, null ), log_count( 'did not return eBay order 09-00003-00001' ) ), array( 3, 0, null, 1 ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '09-00004-00002' ) );
$EBAY_WAS['09-00004-00001'] = '09-00004-00002';
$o = stored_order( array_merge( $e, array( 'id' => '09-00004-00001' ) ) );
$o->update_status( 'completed' );
play_feedback();
check( 'held here under the unpaid ID: eBay answers with the paid one, matched by its item, sent', array( fb_state( $o ), calls( 'CompleteSale' ) ), array( 'left', 1 ) );

// Two buyers of one listing, read in one call, the second under a new ID:
// each order gets its own buyer's line, never the other's.
shop( array( 'feedback_buyers' => 'completed' ) );
$ea = ebay( array( 'id' => '09-00007-00001', 'buyer' => 'first_buyer' ) );
$eb = ebay( array( 'id' => '09-00008-00002', 'buyer' => 'second_buyer' ) );
$EBAY_WAS['09-00008-00001'] = '09-00008-00002';
$a = stored_order( $ea );
$b = stored_order( array_merge( $eb, array( 'id' => '09-00008-00001' ) ) );
$a->update_status( 'completed' );
$b->update_status( 'completed' );
play_feedback();
check( 'two buyers of one listing in one read, one under a new ID: each its own line, its own buyer', array( fb( $a )['line'] ?? '', fb( $b )['line'] ?? '', feedback_bodies() ), array( $ea['lines'][0]['line'], $eb['lines'][0]['line'], array( fb_body( $ea['lines'][0]['line'], 'first_buyer' ), fb_body( $eb['lines'][0]['line'], 'second_buyer' ) ) ) );
shop( array( 'feedback_buyers' => 'completed' ) );
$ea = ebay( array( 'id' => '09-00010-00001', 'buyer' => 'same_buyer' ) );
$eb = ebay( array( 'id' => '09-00011-00002', 'buyer' => 'same_buyer' ) );
$EBAY_WAS['09-00011-00001'] = '09-00011-00002';
$a = stored_order( $ea );
$b = stored_order( array_merge( $eb, array( 'id' => '09-00011-00001' ) ) );
$a->update_status( 'completed' );
$b->update_status( 'completed' );
play_feedback();
check( '  the same buyer twice: still each order its own line', array( fb( $a )['line'] ?? '', fb( $b )['line'] ?? '' ), array( $ea['lines'][0]['line'], $eb['lines'][0]['line'] ) );
shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '09-00009-00002', 'buyer' => 'someone_else' ) );
$EBAY_WAS['09-00009-00001'] = '09-00009-00002';
$o = stored_order( array_merge( $e, array( 'id' => '09-00009-00001', 'buyer' => 'the_buyer' ) ) );
$o->update_status( 'completed' );
play_feedback( 1 );
check( '  an answer under another ID from another buyer is not this order: nothing sent', array( calls( 'CompleteSale' ), fb_state( $o ) ), array( 0, '' ) );
shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '09-00012-00002', 'buyer' => 'whoever' ) );
$EBAY_WAS['09-00012-00001'] = '09-00012-00002';
$o = stored_order( array_merge( $e, array( 'id' => '09-00012-00001' ) ) );
$o->update_meta_data( '_ebay_buyer_user_id', '' );
$o->update_status( 'completed' );
play_feedback();
make_due();
play_feedback();
make_due();
play_feedback();
check( '  no buyer recorded here: an answer under another ID cannot be confirmed - nothing sent, let go after three reads', array( calls( 'CompleteSale' ), fb_state( $o ), get_option( $F::QUEUE_OPTION, null ), log_count( 'did not return eBay order 09-00012-00001' ) ), array( 0, '', null, 1 ) );
shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '09-00013-00002', 'buyer' => '' ) );
$EBAY_WAS['09-00013-00001'] = '09-00013-00002';
$o2 = stored_order( array_merge( $e, array( 'id' => '09-00013-00001' ) ) );
$o2->update_status( 'completed' );
play_feedback( 1 );
check( '  nor when eBay\'s answer names no buyer either: not matched at all (asked again, not judged)', array( logged( 'eBay did not say who the buyer is' ), get_option( $F::QUEUE_OPTION, array() )[ $o2->get_id() ]['unread'] ?? 0 ), array( false, 1 ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$o = stored_order( ebay( array( 'id' => '09-00005-00001' ) ) );
$o->update_status( 'completed' );
$OPTIONS['tcgiant_sync_ebay_settings']['feedback_buyers'] = 'off';
play_feedback();
check( 'turned Off with orders waiting: let go, nothing sent', array( calls( 'GetOrders' ), calls( 'CompleteSale' ), get_option( $F::QUEUE_OPTION, null ), logged( 'is off, so the 1 order(s) waiting' ) ), array( 0, 0, null, true ) );
shop( array( 'feedback_buyers' => 'completed' ) );
$o = stored_order( ebay( array( 'id' => '09-00006-00001' ) ) );
$o->update_status( 'completed' );
$OPTIONS['tcgiant_sync_ebay_settings']['feedback_buyers'] = 'paid';
play_feedback();
check( 'switched to the other trigger: what the old one queued is let go', array( calls( 'CompleteSale' ), get_option( $F::QUEUE_OPTION, null ) ), array( 0, null ) );

echo "\n  Feedback: the budget, Emergency Stop\n";

$budget = array(
	'this site at half its own allowance' => array( function () { $GLOBALS['OPTIONS'][ 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ) ] = 500; }, 'own_half' ),
	'every shop under a quarter left'     => array( function () { roomy( array( 'global_calls' => 31000 ) ); }, 'fleet_low' ),
	'the day\'s budget spent'             => array( function () { roomy( array( 'global_calls' => 40000 ) ); }, 'budget' ),
	'no figure for today'                 => array( function () { $GLOBALS['TRANSIENTS'] = array(); }, 'fleet_unknown' ),
	'not connected'                       => array( function () { TCGiant_Sync_OAuth::$connected = false; }, 'not_connected' ),
);
foreach ( $budget as $label => $case ) {
	shop( array( 'feedback_buyers' => 'completed' ) );
	$o = stored_order( ebay( array( 'id' => '10-' . substr( md5( $label ), 0, 5 ) . '-00001' ) ) );
	$o->update_status( 'completed' );
	call_user_func( $case[0] );
	play_feedback( 1 );
	play_feedback( 1 );
	$p = pending( $F::FEEDBACK_HOOK );
	check( 'waits - ' . $label . ': no call, the order kept, a run later', array( $HTTP, isset( get_option( $F::QUEUE_OPTION, array() )[ $o->get_id() ] ), count( $p ), ( $p[0]['when'] ?? 0 ) > time() + 600 ), array( 0, true, 1, true ) );
	check( '  said once a day', array( $F::pause( true )['why'] ?? '', log_count( 'Feedback for eBay buyers waits until' ) ), array( $case[1], 1 ) );
}
shop( array( 'feedback_buyers' => 'completed' ) );
$o = stored_order( ebay( array( 'id' => '10-00099-00001' ) ) );
$o->update_meta_data( '_tracking_number', '1Z1' );
roomy( array( 'global_calls' => 40000 ) );
$o->update_status( 'completed' );
play_feedback( 1 );
check( 'past the soft ceiling: the tracking push still goes, feedback does not', array( count( tracking_bodies() ), count( feedback_bodies() ) ), array( 1, 0 ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$a = stored_order( ebay( array( 'id' => '11-00011-00001' ) ) );
$b = stored_order( ebay( array( 'id' => '11-00012-00001' ) ) );
$a->update_status( 'completed' );
$b->update_status( 'completed' );
$ON_HTTP = function ( $call ) { if ( 'CompleteSale' === $call ) { $GLOBALS['ON_HTTP'] = null; $GLOBALS['OPTIONS'][ 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ) ] = 600; } };
play_feedback( 1 );
check( 'this site reaches half its allowance during a run: the next call waits for the next day', array( calls( 'CompleteSale' ), fb_state( $b ), ( pending( $F::FEEDBACK_HOOK )[0]['when'] ?? 0 ) > time() + 600 ), array( 1, '', true ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$a = stored_order( ebay( array( 'id' => '11-00001-00001' ) ) );
$b = stored_order( ebay( array( 'id' => '11-00002-00001' ) ) );
$a->update_status( 'completed' );
$b->update_status( 'completed' );
$ON_HTTP = function ( $call ) { if ( 'CompleteSale' === $call ) { $GLOBALS['ON_HTTP'] = null; TCGiant_Sync_Order_Followthrough::stop(); } };
play_feedback();
check( 'Emergency Stop during a run: the run stops before its next call', array( calls( 'CompleteSale' ), fb_state( $a ), fb_state( $b ) ), array( 1, 'left', '' ) );
check( '  the other order kept, no run left', array( isset( get_option( $F::QUEUE_OPTION, array() )[ $b->get_id() ] ), pending( $F::FEEDBACK_HOOK ) ), array( true, array() ) );
as_schedule_single_action( time() + 60, $F::FEEDBACK_HOOK, array(), $F::GROUP );
$F::stop();
check( '  Emergency Stop cancels a feedback run waiting', pending( $F::FEEDBACK_HOOK ), array() );
$c = stored_order( ebay( array( 'id' => '11-00003-00001' ) ) );
$c->update_status( 'completed' );
check( '  an order completed after: queued, but no run scheduled (held until the shop resumes)', array( isset( get_option( $F::QUEUE_OPTION, array() )[ $c->get_id() ] ), pending( $F::FEEDBACK_HOOK ), $F::feedback_held() ), array( true, array(), true ) );
as_schedule_single_action( time(), $F::FEEDBACK_HOOK, array(), $F::GROUP );
play_feedback();
check( '  a run while held sends nothing, reads nothing, and schedules nothing', array( calls( 'CompleteSale' ), calls( 'GetOrders' ), pending( $F::FEEDBACK_HOOK ) ), array( 1, 1, array() ) );
$OPTIONS[ $F::STOP_OPTION ]          = time() - 21601;
$OPTIONS[ $F::FEEDBACK_HELD_OPTION ] = time() - 21601;
run_import();
$d = stored_order( ebay( array( 'id' => '11-00004-00001' ) ) );
$d->update_status( 'completed' );
as_schedule_single_action( time(), $F::FEEDBACK_HOOK, array(), $F::GROUP );
play_feedback();
check( '  six hours on, feedback is still held: nothing sent', array( calls( 'CompleteSale' ), fb_state( $c ), pending( $F::FEEDBACK_HOOK ) ), array( 1, '', array() ) );
check( '  while the refund sweep, which sends eBay nothing, carries on after six hours', count( pending( $F::SWEEP_HOOK ) ), 1 );
// The Import page's line (fix round 2), drawn from the stored hold alone.
$tcg_held_view = function () {
	ob_start();
	include dirname( __DIR__ ) . '/admin/views/feedback-held.php';
	return trim( (string) ob_get_clean() );
};
$tcg_line = $tcg_held_view();
check( '  the Import page says it is held, since when, and the three ways to resume', array(
	false !== strpos( $tcg_line, 'Feedback for eBay buyers is held since Emergency Stop (' . gmdate( 'Y-m-d H:i', (int) $OPTIONS[ $F::FEEDBACK_HELD_OPTION ] ) . ' UTC)' ),
	false !== strpos( $tcg_line, 'Fetch Inventory' ) && false !== strpos( $tcg_line, 'Resume Import' ) && false !== strpos( $tcg_line, 'save the order settings' ),
	0 === strpos( $tcg_line, '<div class="notice notice-warning"><p>' ),
), array( true, true, true ) );
$OPTIONS[ $F::LOCK_PREFIX . 'feedback' ] = ( time() + 600 ) . '|another-request';
do_action( $F::FEEDBACK_HOOK );
unset( $OPTIONS[ $F::LOCK_PREFIX . 'feedback' ] );
check( '  a run finding another request sending while held: nothing scheduled after it', pending( $F::FEEDBACK_HOOK ), array() );
$F::resume( 'Resume Import was pressed' );
check( '  the shop resumes: held no more, said once, a run asked for', array( $F::feedback_held(), log_count( 'Feedback for eBay buyers carries on after Emergency Stop: Resume Import was pressed.' ), count( pending( $F::FEEDBACK_HOOK ) ) ), array( false, 1, 1 ) );
play_feedback();
check( '  and the orders that waited go', array( fb_state( $b ), fb_state( $c ), fb_state( $d ), calls( 'CompleteSale' ) ), array( 'left', 'left', 'left', 4 ) );
$F::resume( 'Resume Import was pressed' );
check( '  resuming again says and does nothing', array( log_count( 'carries on after Emergency Stop' ), pending( $F::FEEDBACK_HOOK ) ), array( 1, array() ) );
check( '  resumed: the Import page\'s line is gone', $tcg_held_view(), '' );
$tcg_import = (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/import.php' );
$tcg_at     = strpos( $tcg_import, "include TCGIANT_SYNC_PATH . 'admin/views/feedback-held.php';" );
check( '  the Import page draws it above everything else, connected to eBay or not', array( false !== $tcg_at, false !== $tcg_at && $tcg_at < (int) strpos( $tcg_import, '<?php if ( ! $is_authenticated ) : ?>' ) ), array( true, true ) );
$tcg_calls = array();
$tcg_toks  = token_get_all( (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/feedback-held.php' ) );
foreach ( $tcg_toks as $i => $tok ) {
	if ( is_array( $tok ) && T_STRING === $tok[0] && '(' === ( is_array( $tcg_toks[ $i + 1 ] ?? null ) ? '' : ( $tcg_toks[ $i + 1 ] ?? '' ) ) ) {
		$tcg_calls[] = strtolower( $tok[1] );
	}
}
check( '  from stored data only: it calls get_option and the escaping and formatting functions, nothing else', array_values( array_diff( array_unique( $tcg_calls ), array( 'defined', 'get_option', 'esc_html', 'sprintf', '__', 'gmdate' ) ) ), array() );
$tcg_admin = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' );
$tcg_body = function ( $name ) use ( $tcg_admin ) { $at = strpos( $tcg_admin, 'function ' . $name . '(' ); return false === $at ? '' : substr( $tcg_admin, $at, strpos( $tcg_admin, 'function ', $at + 10 ) - $at ); };
$tcg_save = strpos( $tcg_admin, "if ( 'import' === $" . "form ) {" );
check( '  the shop\'s three ways to resume call it: Resume Import, Fetch Inventory, the order settings saved', array(
	false !== strpos( $tcg_body( 'handle_resume_sync' ), "TCGiant_Sync_Order_Followthrough::resume( 'Resume Import was pressed' );" ),
	false !== strpos( $tcg_body( 'handle_manual_sync' ), "TCGiant_Sync_Order_Followthrough::resume( 'Fetch Inventory was pressed' );" ),
	false !== $tcg_save && false !== strpos( substr( $tcg_admin, $tcg_save, 600 ), "TCGiant_Sync_Order_Followthrough::resume( 'the order settings were saved' );" ),
), array( true, true, true ) );

shop();
check( 'never stopped: the Import page has no such line', $tcg_held_view(), '' );

echo "\n  Feedback: on a staging copy\n";

$tcg_copy  = rtrim( (string) getenv( 'TMP' ), '\/' ) . '/followthrough-copy-' . getmypid() . '.ser';
$stage = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/followthrough-staging.php' ) . ' ' . escapeshellarg( $tcg_copy ) ), true );
check( 'staging: two orders completed there queue nothing, said once a day', array( $stage['queued'] ?? null, $stage['noted'] ?? null ), array( 0, 1 ) );
check( '  a feedback run there sends nothing, reads nothing, and no run spins', array( $stage['complete'] ?? null, $stage['feedback_reads'] ?? null, $stage['pending'] ?? null ), array( 0, 0, 0 ) );
check( '  a queue copied there from the live shop: no call, kept, no run spins', $stage['live_queue'] ?? null, array( 0, 1, 0 ) );
check( '  the refund sweep reads eBay there, and records the refund, creating nothing', array( $stage['sweep_reads'] ?? null, $stage['refunded'] ?? null, $stage['created'] ?? null ), array( 1, 'refunded', 0 ) );

// The copy's database pushed to the live shop: nothing it completed is sent;
// an order completed on the live shop from then on is.
shop( array( 'feedback_buyers' => 'completed' ) );
$tcg_db = is_file( $tcg_copy ) ? unserialize( (string) file_get_contents( $tcg_copy ) ) : array();
@unlink( $tcg_copy );
foreach ( array( 'OPTIONS', 'ORDERS', 'IDX', 'EBAY' ) as $tcg_k ) {
	$GLOBALS[ $tcg_k ] = $tcg_db[ $tcg_k ] ?? array();
}
roomy();
$tcg_old = count( $ORDERS );
$n = stored_order( ebay( array( 'id' => '40-00009-00001' ) ) );
$n->update_status( 'completed' );
play_feedback();
check( '  pushed to live: the copy\'s two completed orders get nothing; one completed live from then on does', array( $tcg_old, array_keys( get_option( $F::QUEUE_OPTION, array() ) ), count( feedback_bodies() ), fb_state( $n ) ), array( 2, array(), 1, 'left' ) );

echo "\n  Refunds: recorded once\n";

shop();
$e = ebay( array( 'id' => '20-00001-00001', 'total' => 25.00, 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 3600 ) ) ) );
$o = stored_order( $e );
$stock = $STOCK;
run_import();
reset_calls();
play( $F::SWEEP_HOOK );
$args = $REFUND_ARGS[0] ?? array();
check( 'a full refund: wc_create_refund with eBay\'s amount, no line items, no restock, no payment', array( $args['amount'] ?? null, $args['line_items'] ?? null, $args['restock_items'] ?? null, $args['refund_payment'] ?? null ), array( 25.0, array(), false, false ) );
check( '  the reason says eBay, with the refund\'s time', $args['reason'] ?? '', 'Refunded on eBay (refund of ' . gmdate( 'Y-m-d H:i', $e['refunds'][0]['time'] ) . ' UTC)' );
check( '  WooCommerce set the order to Refunded', array( $o->get_status(), $o->get_total_refunded() ), array( 'refunded', 25.0 ) );
check( '  no customer email, no restock, no stock change', array( $EMAILS, $RESTOCKED, $STOCK === $stock ), array( array(), 0, true ) );
check( '  no eBay call but the sweep\'s one GetOrders: nothing from the status change', array( $HTTP, calls( 'GetOrders' ) ), array( 1, 1 ) );
$key = key( $o->get_meta( $F::REFUNDS_META ) );
check( '  recorded on the order and on the refund, by eBay\'s reference', array( $key, $o->refunds[0]->get_meta( $F::REFUND_KEY_META ) ), array( 't:' . ebay_time( $e['refunds'][0]['time'] ) . '|25.00#1', 't:' . ebay_time( $e['refunds'][0]['time'] ) . '|25.00#1' ) );
check( '  an order note, and a log line', array( false !== strpos( (string) end( $o->notes ), 'recorded a refund of 25.00 made on eBay' ), logged( 'recorded on WC Order #' . $o->get_id() . ' as a refund of 25.00; the order is now Refunded' ) ), array( true, true ) );
check( '  one line for the sweep: read, here, recorded, calls', logged( 'Refund sweep: 1 eBay order(s) changed between' ) && logged( 'read, 1 of them imported here; 1 refund(s) recorded; 1 eBay call(s).' ), true );
$EBAY['20-00001-00001']['mod'] = time() - 120;
$OPTIONS[ $F::SWEEP_OPTION ]['last_end'] = time() - 7 * HOUR_IN_SECONDS;
reset_calls();
play( $F::SWEEP_HOOK );
check( 'read again by the next sweep: not recorded again', array( count( $o->refunds ), count( $REFUND_ARGS ), calls( 'GetOrders' ) ), array( 1, 1, 1 ) );

shop();
$e = ebay( array( 'id' => '20-00002-00001', 'total' => 25.00, 'refunds' => array( array( 'amount' => 10.00, 'time' => time() - 3600, 'ref' => 'R-778' ) ) ) );
$o = stored_order( $e );
run_import();
play( $F::SWEEP_HOOK );
check( 'a partial refund: 10 of 25, the order stays Processing, no partial-refund email', array( $o->get_total_refunded(), $o->get_status(), $EMAILS ), array( 10.0, 'processing', array() ) );
check( '  eBay\'s ReferenceID, when it gives one, is the reference', array( key( $o->get_meta( $F::REFUNDS_META ) ), $REFUND_ARGS[0]['reason'] ?? '' ), array( 'ref:R-778', 'Refunded on eBay (reference R-778)' ) );
$EBAY['20-00002-00001']['mod'] = time() - 120;
unset( $o->meta[ $F::REFUNDS_META ] );
$OPTIONS[ $F::SWEEP_OPTION ]['last_end'] = time() - 7 * HOUR_IN_SECONDS;
play( $F::SWEEP_HOOK );
check( '  the order\'s record lost (a request killed after the refund): read again, the refund\'s own key stops it', array( count( $o->refunds ), $o->get_total_refunded(), count( $REFUND_ARGS ) ), array( 1, 10.0, 1 ) );
$EBAY['20-00002-00001']['refunds'][] = array( 'amount' => 15.00, 'time' => time() - 60, 'ref' => 'R-779' );
$EBAY['20-00002-00001']['mod'] = time() - 120;
$OPTIONS[ $F::SWEEP_OPTION ]['last_end'] = time() - 7 * HOUR_IN_SECONDS;
play( $F::SWEEP_HOOK );
check( 'a second refund later: only it recorded; now Refunded', array( $o->get_total_refunded(), count( $o->refunds ), $o->get_status() ), array( 25.0, 2, 'refunded' ) );

shop();
$t = time() - 7200;
$e = ebay( array( 'id' => '20-00003-00001', 'total' => 30.00, 'refunds' => array( array( 'amount' => 5.00, 'time' => $t ), array( 'amount' => 5.00, 'time' => $t ) ) ) );
$o = stored_order( $e );
run_import();
play( $F::SWEEP_HOOK );
check( 'two refunds of the same amount at the same second: two recorded', array( count( $o->refunds ), $o->get_total_refunded(), array_keys( $o->get_meta( $F::REFUNDS_META ) ) ), array( 2, 10.0, array( 't:' . ebay_time( $t ) . '|5.00#1', 't:' . ebay_time( $t ) . '|5.00#2' ) ) );

echo "\n  Refunds: not complete, the cap\n";

shop();
$e = ebay( array( 'id' => '21-00001-00001', 'refunds' => array( array( 'amount' => 25.00, 'time' => null ) ) ) );
$o = stored_order( $e );
run_import();
play( $F::SWEEP_HOOK );
check( 'a refund with no RefundTime (not complete): not recorded, counted', array( count( $o->refunds ), logged( '0 refund(s) recorded, 1 not complete on eBay yet' ) ), array( 0, true ) );
$EBAY['21-00001-00001']['refunds'][0]['time'] = time() - 60;
$EBAY['21-00001-00001']['mod'] = time() - 120;
$OPTIONS[ $F::SWEEP_OPTION ]['last_end'] = time() - 7 * HOUR_IN_SECONDS;
play( $F::SWEEP_HOOK );
check( '  complete at the next sweep: recorded', array( count( $o->refunds ), $o->get_status() ), array( 1, 'refunded' ) );

foreach ( array( 'Failed' => array( 'status' => 'Failed' ), 'Pending' => array( 'status' => 'Pending' ), 'of no amount' => array( 'amount' => 0 ), 'with no amount' => array( 'amount' => null ) ) as $label => $over ) {
	shop();
	$r = array_merge( array( 'amount' => 25.00, 'time' => time() - 60 ), $over );
	if ( array_key_exists( 'amount', $over ) && null === $over['amount'] ) {
		unset( $r['amount'] );
	}
	$e = ebay( array( 'id' => '21-' . substr( md5( $label ), 0, 5 ) . '-00001', 'refunds' => array( $r ) ) );
	$o = stored_order( $e );
	run_import();
	play( $F::SWEEP_HOOK );
	check( 'a refund ' . $label . ': not recorded', array( count( $o->refunds ), $o->get_status() ), array( 0, 'processing' ) );
}
shop();
$e = ebay( array( 'id' => '21-00006-00001', 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 60, 'status' => 'Successful' ) ) ) );
$o = stored_order( $e );
run_import();
play( $F::SWEEP_HOOK );
check( 'a refund eBay says is Successful: recorded', count( $o->refunds ), 1 );

shop();
$e = ebay( array( 'id' => '22-00001-00001', 'total' => 25.00, 'refunds' => array( array( 'amount' => 30.00, 'time' => time() - 600 ) ) ) );
$o = stored_order( $e, 'processing', 20.00 );
run_import();
play( $F::SWEEP_HOOK );
check( 'eBay refunded more than the order here: capped at what was left, said so', array( $o->get_total_refunded(), $o->get_status(), false !== strpos( (string) end( $o->notes ), 'capped at what was left to refund here' ), logged( '(capped at what was left to refund)' ) ), array( 20.0, 'refunded', true, true ) );

shop();
$e = ebay( array( 'id' => '22-00002-00001', 'total' => 25.00, 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 600 ) ) ) );
$o = stored_order( $e );
$o->refunds[] = new WC_Order_Refund( 60001 );
$o->refunds[0]->amount = 10.0;
run_import();
play( $F::SWEEP_HOOK );
check( 'part refunded by hand here: eBay\'s full refund capped at the rest', array( $o->get_total_refunded(), count( $REFUND_ARGS ), $REFUND_ARGS[0]['amount'] ?? null ), array( 25.0, 1, 15.0 ) );

shop();
$e = ebay( array( 'id' => '22-00003-00001', 'total' => 25.00, 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 600 ) ) ) );
$o = stored_order( $e );
$o->refunds[] = new WC_Order_Refund( 60002 );
$o->refunds[0]->amount = 25.0;
run_import();
play( $F::SWEEP_HOOK );
check( 'refunded in full here already: nothing recorded, said once, the reference kept', array( count( $REFUND_ARGS ), log_count( 'nothing is left to refund on the order here' ), count( $o->get_meta( $F::REFUNDS_META ) ) ), array( 0, 1, 1 ) );
$EBAY['22-00003-00001']['mod'] = time() - 120;
$OPTIONS[ $F::SWEEP_OPTION ]['last_end'] = time() - 7 * HOUR_IN_SECONDS;
play( $F::SWEEP_HOOK );
check( '  and not said again by the next sweep', log_count( 'nothing is left to refund on the order here' ), 1 );

shop();
$e = ebay( array( 'id' => '22-00004-00001', 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 600 ) ) ) );
$o = stored_order( $e );
run_import();
$REFUND_THROWS = true;
play( $F::SWEEP_HOOK );
check( 'WooCommerce refuses the refund: an error to record it by hand, nothing kept', array( logged( 'could not be recorded here — A hook on the refund failed.. Record it by hand.' ), $o->get_meta( $F::REFUNDS_META ) ), array( true, '' ) );
$REFUND_THROWS = false;
$EBAY['22-00004-00001']['mod'] = time() - 120;
$OPTIONS[ $F::SWEEP_OPTION ]['last_end'] = time() - 7 * HOUR_IN_SECONDS;
play( $F::SWEEP_HOOK );
check( '  read again later: recorded', count( $o->refunds ), 1 );

echo "\n  Refunds: what the sweep touches\n";

shop();
ebay( array( 'id' => '23-00001-00001', 'created' => time() - 5 * DAY_IN_SECONDS, 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 600 ) ) ) );
$e = ebay( array( 'id' => '23-00002-00001', 'created' => time() - 5 * DAY_IN_SECONDS, 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 600 ) ) ) );
$o = stored_order( $e );
$o->status = 'trash';
run_import();
$before = array( count( $ORDERS ), $CREATED );
reset_calls();
play( $F::SWEEP_HOOK );
check( 'an eBay order not here, and one in the Trash: nothing made, nothing recorded', array( array( count( $ORDERS ), $CREATED ), count( $REFUND_ARGS ) ), array( $before, 0 ) );
check( '  the sweep read both, none here', logged( '2 eBay order(s) changed between' ) && logged( 'read, 0 of them imported here; 0 refund(s) recorded' ), true );
$web = wc_create_order();
$web->total = 10;
wc_create_refund( array( 'amount' => 10, 'order_id' => $web->get_id() ) );
check( 'a website order\'s refund still emails its customer', $EMAILS, array( array( 'customer_refunded_order', $web->get_id() ) ) );
check( 'the email filters: an eBay order\'s refunds, whole or partial, send none; a website order\'s are left as they are', array( $F::no_refund_email( true, $o ), $F::no_refund_email( true, $web ), $F::no_refund_email( false, $web ) ), array( false, true, false ) );

shop( array( 'feedback_buyers' => 'completed' ) );
$e = ebay( array( 'id' => '23-00003-00001', 'refunds' => array( array( 'amount' => 5.00, 'time' => time() - 600 ) ) ) );
$o = stored_order( $e );
run_import();
play( $F::SWEEP_HOOK );
reset_calls();
$o->update_status( 'completed' );
play_feedback();
check( 'feedback after a refund recorded: none, and no call', array( calls( 'GetOrders' ), calls( 'CompleteSale' ), logged( 'it has a refund' ) ), array( 0, 0, true ) );

echo "\n  Refunds: the window\n";

shop();
run_import();
$p = pending( $F::SWEEP_HOOK );
check( 'the order import schedules the first sweep, now, after the stock pushes', array( count( $p ), ( $p[0]['when'] ?? 0 ) <= time(), $p[0]['priority'] ?? 0 ), array( 1, true, 20 ) );
reset_calls();
$t0 = time();
play( $F::SWEEP_HOOK );
$body = inner( 'GetOrders', $BODIES['GetOrders'][0] ?? '' );
$from = strtotime( tag( $body, 'ModTimeFrom' ) );
$to   = strtotime( tag( $body, 'ModTimeTo' ) );
check( 'the first window: two days back, to a minute ago', array( abs( $from - ( $t0 - 2 * DAY_IN_SECONDS ) ) <= 2, abs( $to - ( $t0 - 60 ) ) <= 2 ), array( true, true ) );
check( '  the body: ModTime, every order, 100 a page, only the order\'s ID and money details', $body, "\n<ModTimeFrom>" . tag( $body, 'ModTimeFrom' ) . "</ModTimeFrom>\n<ModTimeTo>" . tag( $body, 'ModTimeTo' ) . "</ModTimeTo>\n<OrderRole>Seller</OrderRole>\n<OrderStatus>All</OrderStatus>\n<Pagination>\n<EntriesPerPage>100</EntriesPerPage>\n<PageNumber>1</PageNumber>\n</Pagination>\n<OutputSelector>OrderArray.Order.OrderID</OutputSelector>\n<OutputSelector>OrderArray.Order.MonetaryDetails</OutputSelector>\n<OutputSelector>PaginationResult</OutputSelector>\n<OutputSelector>HasMoreOrders</OutputSelector>" );
$p = pending( $F::SWEEP_HOOK );
check( '  done: the next six hours on', array( count( $p ), abs( ( $p[0]['when'] ?? 0 ) - ( time() + 21600 ) ) <= 2, $OPTIONS[ $F::SWEEP_OPTION ]['last_to'] ?? 0 ), array( 1, true, $to ) );
check( '  its state never autoloaded', $AUTOLOAD[ $F::SWEEP_OPTION ] ?? null, false );
reset_calls();
play( $F::SWEEP_HOOK );
check( 'a sweep run before its time (by hand) reads nothing, and schedules nothing', array( calls( 'GetOrders' ), count( pending( $F::SWEEP_HOOK ) ) ), array( 0, 0 ) );
run_import();
$p = pending( $F::SWEEP_HOOK );
check( '  the order import puts the next back, six hours after the last ended', array( count( $p ), abs( ( $p[0]['when'] ?? 0 ) - ( $OPTIONS[ $F::SWEEP_OPTION ]['last_end'] + 21600 ) ) <= 1 ), array( 1, true ) );
$OPTIONS[ $F::SWEEP_OPTION ]['last_end'] = time() - 6 * HOUR_IN_SECONDS - 1;
reset_calls();
do_action( $F::SWEEP_HOOK );
$body = inner( 'GetOrders', $BODIES['GetOrders'][0] ?? '' );
check( 'the next window starts 15 minutes before the last one ended', strtotime( tag( $body, 'ModTimeFrom' ) ), $to - 900 );
$OPTIONS[ $F::SWEEP_OPTION ] = array( 'last_to' => time() - 40 * DAY_IN_SECONDS, 'last_end' => time() - 40 * DAY_IN_SECONDS );
reset_calls();
$t0 = time();
do_action( $F::SWEEP_HOOK );
$body = inner( 'GetOrders', $BODIES['GetOrders'][0] ?? '' );
check( 'last read 40 days ago: the window starts 29 days back, said once', array( abs( strtotime( tag( $body, 'ModTimeFrom' ) ) - ( $t0 - 29 * DAY_IN_SECONDS ) ) <= 2, log_count( 'it reads only the last 29 days (eBay allows 30)' ) ), array( true, 1 ) );
$OPTIONS[ $F::SWEEP_OPTION ] = array( 'last_to' => time() - 50 * DAY_IN_SECONDS, 'last_end' => time() - 50 * DAY_IN_SECONDS, 'doing' => array( 'from' => time() - 40 * DAY_IN_SECONDS, 'to' => time() - 39 * DAY_IN_SECONDS, 'page' => 3, 'read' => 200, 'here' => 0, 'recorded' => 0, 'waiting' => 0, 'calls' => 2 ) );
reset_calls();
$t0 = time();
do_action( $F::SWEEP_HOOK );
$body = inner( 'GetOrders', $BODIES['GetOrders'][0] ?? '' );
check( 'a window left part way 40 days ago, resumed: a new window from 29 days back, page 1, to now, said once', array( abs( strtotime( tag( $body, 'ModTimeFrom' ) ) - ( $t0 - 29 * DAY_IN_SECONDS ) ) <= 2, tag( $body, 'PageNumber' ), abs( strtotime( tag( $body, 'ModTimeTo' ) ) - ( $t0 - 60 ) ) <= 2, log_count( 'it reads only the last 29 days (eBay allows 30)' ) ), array( true, '1', true, 2 ) );

shop( array( 'record_ebay_refunds' => '0' ) );
as_schedule_single_action( time(), $F::SWEEP_HOOK, array(), $F::GROUP );
ebay( array( 'id' => '24-00001-00001', 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 600 ) ) ) );
run_import();
check( 'turned off: the order import cancels the sweep', pending( $F::SWEEP_HOOK ), array() );
reset_calls();
do_action( $F::SWEEP_HOOK );
check( '  and a sweep run anyway reads nothing', array( calls( 'GetOrders' ), count( $REFUND_ARGS ) ), array( 0, 0 ) );

echo "\n  Refunds: pages, resume, the budget, Emergency Stop\n";

shop();
for ( $i = 1; $i <= 250; $i++ ) {
	$e = ebay( array( 'id' => sprintf( '25-%05d-00001', $i ), 'created' => time() - 3 * DAY_IN_SECONDS, 'mod' => time() - 3600 + $i, 'refunds' => array( array( 'amount' => 1.00, 'time' => time() - 4000 ) ) ) );
	stored_order( $e );
}
$GETORDERS_FAIL = array();
$ON_HTTP = function ( $call, $body ) { if ( '2' === tag( $body, 'PageNumber' ) && empty( $GLOBALS['FAILED_ONCE'] ) ) { $GLOBALS['FAILED_ONCE'] = true; $GLOBALS['GETORDERS_FAIL'] = array( 'refuse' ); } };
$FAILED_ONCE = false;
reset_calls();
do_action( $F::SWEEP_HOOK );
$st = $OPTIONS[ $F::SWEEP_OPTION ];
check( '250 orders changed, page 2 refused: page 1 recorded, kept at page 2, a run in 30 minutes', array( count( $REFUND_ARGS ), $st['doing']['page'] ?? 0, abs( ( pending( $F::SWEEP_HOOK )[0]['when'] ?? 0 ) - ( time() + 1800 ) ) <= 2 ), array( 100, 2, true ) );
reset_calls();
do_action( $F::SWEEP_HOOK );
check( '  a run by hand inside the 30 minutes reads nothing', calls( 'GetOrders' ), 0 );
$OPTIONS[ $F::SWEEP_OPTION ]['wait_until'] = time() - 1;
reset_calls();
do_action( $F::SWEEP_HOOK );
$pages = array_map( function ( $b ) { return tag( $b, 'PageNumber' ); }, $BODIES['GetOrders'] ?? array() );
check( '  resumed: pages 2 and 3 only, the same window', array( $pages, tag( $BODIES['GetOrders'][0], 'ModTimeFrom' ) === gmdate( 'Y-m-d\TH:i:s.000\Z', $st['doing']['from'] ) ), array( array( '2', '3' ), true ) );
check( '  read again from page 2: said once, though page 3 follows', log_count( 'were read again (page 2), after 1 failed tries' ) + log_count( 'were read again (page 3)' ), 1 );
check( '  every refund once: 250, and one line for the window, 4 calls', array( count( $REFUND_ARGS ), logged( '250 eBay order(s) changed between' ), logged( '250 of them imported here; 250 refund(s) recorded; 4 eBay call(s).' ) ), array( 250, true, true ) );

shop();
for ( $i = 1; $i <= 250; $i++ ) {
	stored_order( ebay( array( 'id' => sprintf( '29-%05d-00001', $i ), 'created' => time() - 3 * DAY_IN_SECONDS, 'mod' => time() - 3600 + $i, 'refunds' => array( array( 'amount' => 1.00, 'time' => time() - 4000 ) ) ) ) );
}
$ON_HTTP = function ( $call, $body ) { if ( '2' === tag( $body, 'PageNumber' ) ) { $GLOBALS['ON_HTTP'] = null; throw new RuntimeException( 'the host ended the request' ); } };
reset_calls();
try {
	do_action( $F::SWEEP_HOOK );
} catch ( RuntimeException $e ) {
	// The request ends here.
}
check( 'a request ended by the host during page 2: page 1 recorded, its place kept at page 2', array( count( $REFUND_ARGS ), $OPTIONS[ $F::SWEEP_OPTION ]['doing']['page'] ?? 0 ), array( 100, 2 ) );
reset_calls();
do_action( $F::SWEEP_HOOK );
check( '  the next run carries on from page 2: 250 recorded, none twice', array( count( $REFUND_ARGS ), tag( $BODIES['GetOrders'][0] ?? '', 'PageNumber' ) ), array( 250, '2' ) );

shop();
for ( $i = 1; $i <= 1150; $i++ ) {
	$EBAY[ sprintf( '26-%05d-00001', $i ) ] = array( 'id' => sprintf( '26-%05d-00001', $i ), 'status' => 'Completed', 'buyer' => 'b', 'created' => time() - 3 * DAY_IN_SECONDS, 'mod' => time() - 3600 + $i, 'cancel' => '', 'lines' => array(), 'refunds' => array(), 'total' => 1.0 );
}
reset_calls();
do_action( $F::SWEEP_HOOK );
$p = pending( $F::SWEEP_HOOK );
check( '1,150 changed (12 pages): 10 pages a run, the rest a minute on', array( calls( 'GetOrders' ), count( $p ), ( $p[0]['when'] ?? 0 ) >= time() + 59 && ( $p[0]['when'] ?? 0 ) <= time() + 61 ), array( 10, 1, true ) );
play( $F::SWEEP_HOOK );
check( '  then 2 more: 1,150 read, 12 calls', array( calls( 'GetOrders' ), logged( '1150 eBay order(s) changed between' ), logged( '12 eBay call(s)' ) ), array( 12, true, true ) );

$import_reads = array();
// eBay refusing every read, ten times in a row: backed off 30 minutes to
// 6 hours, one line; a run by hand meanwhile reads nothing; a read that
// works says so once.
shop();
ebay( array( 'id' => '27-00009-00001', 'created' => time() - 3 * DAY_IN_SECONDS, 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 600 ) ) ) );
$tcg_waits = array();
$tcg_calls = 0;
for ( $i = 1; $i <= 10; $i++ ) {
	$GETORDERS_FAIL = array( 'refuse' );
	reset_calls();
	do_action( $F::SWEEP_HOOK );
	$tcg_calls += calls( 'GetOrders' );
	$tcg_waits[] = (int) round( ( $OPTIONS[ $F::SWEEP_OPTION ]['wait_until'] - time() ) / 60 );
	reset_calls();
	do_action( $F::SWEEP_HOOK );
	$tcg_calls += calls( 'GetOrders' );
	$OPTIONS[ $F::SWEEP_OPTION ]['wait_until'] = time() - 1;
}
check( 'refused ten times in a row: ten calls, one for each wait, none from the runs between', $tcg_calls, 10 );
check( '  the waits: 30 minutes, 1, 2, 4 hours, then 6 hours each', $tcg_waits, array( 30, 60, 120, 240, 360, 360, 360, 360, 360, 360 ) );
check( '  in a day of it, 7 calls where every 30 minutes made 48', count( array_filter( array_map( function ( $k ) use ( $tcg_waits ) { return array_sum( array_slice( $tcg_waits, 0, $k ) ); }, range( 0, 9 ) ), function ( $m ) { return $m < 1440; } ) ), 7 );
check( '  one line said it', log_count( 'could not be read (page 1)' ), 1 );
reset_calls();
do_action( $F::SWEEP_HOOK );
check( '  read again: one call, one line said it, the back-off cleared', array( calls( 'GetOrders' ), log_count( 'were read again (page 1), after 10 failed tries' ), isset( $OPTIONS[ $F::SWEEP_OPTION ]['fails'] ) ), array( 1, 1, false ) );

// An order that carries an eBay order ID but was not imported from eBay.
shop();
$e = ebay( array( 'id' => '27-00010-00001', 'created' => time() - 3 * DAY_IN_SECONDS, 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 600 ) ) ) );
$o = stored_order( $e );
unset( $o->meta['_ebay_order_source'] );
do_action( $F::SWEEP_HOOK );
check( 'an order carrying an eBay order ID, not imported from eBay: the sweep leaves it untouched', array( count( $o->refunds ), $o->get_status(), count( $REFUND_ARGS ), logged( 'read, 0 of them imported here' ) ), array( 0, 'processing', 0, true ) );

$sbudget = array(
	'this site at half its own allowance' => function () { $GLOBALS['OPTIONS'][ 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ) ] = 500; },
	'this site at its own allowance (the sales reads\' margin is not the sweep\'s)' => function () { $GLOBALS['OPTIONS'][ 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ) ] = 1000; },
	'the day\'s budget spent' => function () { roomy( array( 'global_calls' => 40000 ) ); },
);
foreach ( $sbudget as $label => $set ) {
	shop();
	ebay( array( 'id' => '27-00001-00001', 'refunds' => array( array( 'amount' => 25.00, 'time' => time() - 600 ) ) ) );
	$set();
	reset_calls();
	run_import();
	$import_reads[] = calls( 'GetOrders' );
	play( $F::SWEEP_HOOK );
	check( 'the sweep waits - ' . $label . ': no GetOrders of its own, until the next day', array( calls( 'GetOrders' ) - end( $import_reads ), ( $OPTIONS[ $F::SWEEP_OPTION ]['wait_until'] ?? 0 ) > time() + 60, logged( 'The eBay refund sweep waits until' ) ), array( 0, true, true ) );
}
check( '  while the order import\'s own GetOrders still goes at its allowance (the sales reads\' margin), and not once the day\'s budget is spent', $import_reads, array( 1, 1, 0 ) );
run_import();
check( '  and the import\'s 15 minutes do not bring it forward', ( pending( $F::SWEEP_HOOK )[0]['when'] ?? 0 ) > time() + 60, true );

shop();
for ( $i = 1; $i <= 250; $i++ ) {
	$EBAY[ sprintf( '28-%05d-00001', $i ) ] = array( 'id' => sprintf( '28-%05d-00001', $i ), 'status' => 'Completed', 'buyer' => 'b', 'created' => time() - 3 * DAY_IN_SECONDS, 'mod' => time() - 3600 + $i, 'cancel' => '', 'lines' => array(), 'refunds' => array(), 'total' => 1.0 );
}
$ON_HTTP = function ( $call ) { if ( 'GetOrders' === $call ) { $GLOBALS['ON_HTTP'] = null; TCGiant_Sync_Order_Followthrough::stop(); } };
reset_calls();
do_action( $F::SWEEP_HOOK );
check( 'Emergency Stop during a sweep: it stops before its next page, its place kept', array( calls( 'GetOrders' ), $OPTIONS[ $F::SWEEP_OPTION ]['doing']['page'] ?? 0, pending( $F::SWEEP_HOOK ) ), array( 1, 2, array() ) );
run_import();
$p = pending( $F::SWEEP_HOOK );
check( '  the import puts it back six hours after the stop', array( count( $p ), ( $p[0]['when'] ?? 0 ) >= time() + 21590 ), array( 1, true ) );
do_action( $F::SWEEP_HOOK );
check( '  a run inside the hold reads nothing', calls( 'GetOrders' ), 2 );

echo "\n  Byte-identity\n";

foreach ( array( array( 'off', '1' ), array( 'paid', '1' ), array( 'completed', '0' ), array( 'paid', '0' ) ) as $combo ) {
	shop( array( 'feedback_buyers' => $combo[0], 'record_ebay_refunds' => $combo[1] ) );
	ebay( array( 'id' => '30-00001-00001' ) );
	run_import();
	$o = wc_for( '30-00001-00001' );
	$o->update_meta_data( '_tracking_number', '9400100000000000000000' );
	$o->update_meta_data( '_tracking_provider', 'USPS' );
	$o->update_status( 'completed' );
	check( 'feedback ' . $combo[0] . ', refunds ' . ( '1' === $combo[1] ? 'on' : 'off' ) . ': the import\'s GetOrders and the tracking CompleteSale are 729b184\'s', array( inner( 'GetOrders', $BODIES['GetOrders'][0] ) === import_body_729b184( $BODIES['GetOrders'][0] ), tracking_bodies() ), array( true, array( tracking_body_729b184( '30-00001-00001', '9400100000000000000000', 'USPS' ) ) ) );
}

echo "\n  Leftovers\n";

check( 'no holds left behind', array_values( array_filter( array_keys( $OPTIONS ), function ( $k ) { return 0 === strpos( $k, 'tcgiant_sync_followthrough_lock_' ); } ) ), array() );
check( 'no PHP warnings', $WARNINGS, array() );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
