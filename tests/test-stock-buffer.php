<?php
/**
 * Harness: a stock buffer for eBay (3.45.0) - "Keep back from eBay" and
 * "Show at most on eBay".
 *
 * A shop selling the same stock elsewhere wants eBay to show less than it
 * holds: eBay is sent the stock less N ("Keep back from eBay"), no more than
 * M ("Show at most on eBay"), never below 0. A product's eBay tab can set its
 * own (empty: the shop's; 0: none for that product). Every quantity a stock
 * push sends goes through it - a stock change, a website sale, Send stock to
 * eBay, a variation keeping its own stock, a stock its variations share
 * (buffered once) - never an auction's. With neither set, every push is the
 * one 3.44.0 made.
 *
 * The core risk is the other way: eBay's figure is then not the stock, and
 * every place that wrote it back (the import, order sync's settle, an ended
 * listing's settle) would bring the stock down by the buffer at each sync.
 * Once a buffered figure lands on a listing (TCGiant_Sync_Inventory::
 * BUFFERED_META) those take off eBay's own sales only, from the record.
 *
 * The world is tests/test-stock-push.php's, taken as it stands up to its
 * first case, as tests/test-pool-disabled.php takes it.
 *
 * @package TCGiant_Sync
 */

// --- The world: tests/test-stock-push.php's, up to its first case -------------

$WORLD_SRC = (string) file_get_contents( __DIR__ . '/test-stock-push.php' );
$WORLD_CUT = strpos( $WORLD_SRC, 'echo "' . chr( 92 ) . 'nTHE HOOKS' );
if ( false === $WORLD_CUT ) {
	echo "the world of tests/test-stock-push.php is not as this harness takes it\n\n  0 passed, 1 failed\n";
	exit( 1 );
}
$WORLD_SRC = substr( $WORLD_SRC, 0, $WORLD_CUT );
$WORLD_SRC = str_replace( '__DIR__', var_export( __DIR__, true ), $WORLD_SRC );
eval( '?>' . $WORLD_SRC );
unset( $WORLD_SRC );

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $text ) { return trim( (string) $text ); }
}
if ( ! function_exists( 'map_deep' ) ) {
	function map_deep( $value, $callback ) { return is_array( $value ) ? array_map( function ( $v ) use ( $callback ) { return map_deep( $v, $callback ); }, $value ) : call_user_func( $callback, $value ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) ); }
}

$MAPPER = TCGiant_Sync_Mapper::instance();
/** The import of one listing, as the delta and the full sync make it (test-stock-push's). */
function import_listing( array $item ) {
	TCGiant_Sync_Inventory::begin_ebay_origin();
	try {
		$data = $GLOBALS['MAPPER']->map_ebay_to_woo( $item );
		return $GLOBALS['MAPPER']->save_as_product( $data );
	} finally {
		TCGiant_Sync_Inventory::end_ebay_origin();
	}
}
/** The shop's two settings (and any others). */
function buffer_shop( $keep = '', $cap = '', array $more = array() ) {
	fresh_shop( array( 'stock_keep_back' => (string) $keep, 'stock_show_at_most' => (string) $cap ) + $more );
}
/** A product's own two, on its eBay tab. */
function own_buffer( $id, $keep = null, $cap = null ) {
	if ( null !== $keep ) {
		$GLOBALS['META'][ $id ][ TCGiant_Sync_Stock_Buffer::KEEP_META ] = (string) $keep;
	}
	if ( null !== $cap ) {
		$GLOBALS['META'][ $id ][ TCGiant_Sync_Stock_Buffer::CAP_META ] = (string) $cap;
	}
}
/** The quantities of the ReviseInventoryStatus calls sent, in order. */
function revised() {
	return array_map( function ( $b ) { $s = statuses( $b ); return 1 === count( $s ) && isset( $s[''] ) ? $s[''] : ( 1 === count( $s ) && null === key( $s ) ? reset( $s ) : $s ); }, all_sent( 'ReviseInventoryStatus' ) );
}
/** The plain quantity of each ReviseInventoryStatus sent for a listing without variations. */
function qtys() { return array_map( 'qty_sent', all_sent( 'ReviseInventoryStatus' ) ); }
function marked( $id ) { $m = meta( $id, TCGiant_Sync_Inventory::BUFFERED_META ); return is_array( $m ) ? $m['item'] : null; }
function rec( $id ) { $r = meta( $id, '_ebay_sold_seen' ); return is_array( $r ) ? array( $r['item'] ?? null, $r['sku'] ?? null, $r['sold'] ?? ( isset( $r['sent'] ) ? 'sent ' . $r['sent'] : null ) ) : null; }
/** A simple product for a listing, eBay's live listing showing $avail with $sold sold, the record at $seen. */
function coin( $id, $stock, $item, $avail, $sold = 0, $seen = null ) {
	product( $id, $stock, $item, array( '_name' => 'Coin ' . $id ) );
	if ( null !== $seen ) {
		seen( $id, $item, $seen );
	}
	ebay_has( $item, listing( $item, $avail + $sold, $sold ) + array( 'Title' => 'Coin ' . $id, 'SKU' => 'SKU-' . $id ) );
}
/** eBay's listing for a coin as the import reads it. */
function coin_item( $id, $item, $avail, $sold ) { return listing( $item, $avail + $sold, $sold ) + array( 'Title' => 'Coin ' . $id, 'SKU' => 'SKU-' . $id ); }
/** Each queued push's sale priority (fix round 2: a top-up has none). */
function top_up_sale_flags() { return array_map( function ( $a ) { return $a['args'][0]['ebay_sale'] ?? null; }, pushes() ); }
function push_now() { $GLOBALS['WIRE'] = array(); run_queue(); return calls(); }
$RECONCILE = new ReflectionMethod( 'TCGiant_Sync_Inventory', 'reconcile_items' );
$RECONCILE->setAccessible( true );

echo "\nTHE FIGURE\n" . str_repeat( '=', 132 ) . "\n";

buffer_shop();
product( 100, 10, '100000000100' );
check( 'neither set: the stock exactly as given, below 0 and as a string too', array( TCGiant_Sync_Stock_Buffer::figure( 100, 10 ), TCGiant_Sync_Stock_Buffer::figure( 100, -3 ), TCGiant_Sync_Stock_Buffer::figure( 100, '7' ), TCGiant_Sync_Stock_Buffer::applies( 100 ) ), array( 10, -3, '7', false ) );
buffer_shop( 2 );
product( 100, 10, '100000000100' );
check( 'Keep back 2: 10 is 8, 2 is 0, 1 is 0 - never below 0', array( TCGiant_Sync_Stock_Buffer::figure( 100, 10 ), TCGiant_Sync_Stock_Buffer::figure( 100, 2 ), TCGiant_Sync_Stock_Buffer::figure( 100, 1 ), TCGiant_Sync_Stock_Buffer::figure( 100, -1 ) ), array( 8, 0, 0, 0 ) );
buffer_shop( '', 5 );
product( 100, 10, '100000000100' );
check( 'Show at most 5: 10 is 5, 3 is 3', array( TCGiant_Sync_Stock_Buffer::figure( 100, 10 ), TCGiant_Sync_Stock_Buffer::figure( 100, 3 ) ), array( 5, 3 ) );
buffer_shop( 2, 5 );
product( 100, 10, '100000000100' );
check( 'both: min( stock - 2, 5 ) - 10 is 5, 6 is 4, 2 is 0', array( TCGiant_Sync_Stock_Buffer::figure( 100, 10 ), TCGiant_Sync_Stock_Buffer::figure( 100, 6 ), TCGiant_Sync_Stock_Buffer::figure( 100, 2 ) ), array( 5, 4, 0 ) );
buffer_shop( '0', '0' );
product( 100, 10, '100000000100' );
check( 'the shop\'s 0 is none, for both: never "show at most 0"', array( TCGiant_Sync_Stock_Buffer::figure( 100, 10 ), TCGiant_Sync_Stock_Buffer::applies( 100 ) ), array( 10, false ) );
buffer_shop( 'two', '-1' );
product( 100, 10, '100000000100' );
check( 'a figure that is not a whole number of 0 or more is none', TCGiant_Sync_Stock_Buffer::figure( 100, 10 ), 10 );
buffer_shop( 2 );
product( 100, 10, '100000000100' );
own_buffer( 100, '' );
check( 'the override empty: the shop\'s', TCGiant_Sync_Stock_Buffer::figure( 100, 10 ), 8 );
own_buffer( 100, '0' );
check( 'the override 0: none for this product', array( TCGiant_Sync_Stock_Buffer::figure( 100, 10 ), TCGiant_Sync_Stock_Buffer::applies( 100 ) ), array( 10, false ) );
own_buffer( 100, '3' );
check( 'the override 3: its own', TCGiant_Sync_Stock_Buffer::figure( 100, 10 ), 7 );
own_buffer( 100, '0', '4' );
check( 'its own cap with the shop\'s keep-back off for it', TCGiant_Sync_Stock_Buffer::figure( 100, 10 ), 4 );
variable( 110, '110000000110' );
variation( 111, 110, 'V-1', 9 );
own_buffer( 110, '1' );
check( 'a variation is sent under its product\'s', TCGiant_Sync_Stock_Buffer::figure( wc_get_product( 111 ), 9 ), 8 );
check( 'typed(): whole numbers of 0 or more, else none', array( TCGiant_Sync_Stock_Buffer::typed( ' 007 ' ), TCGiant_Sync_Stock_Buffer::typed( '0' ), TCGiant_Sync_Stock_Buffer::typed( '1.5' ), TCGiant_Sync_Stock_Buffer::typed( '-2' ), TCGiant_Sync_Stock_Buffer::typed( '' ), TCGiant_Sync_Stock_Buffer::typed( array() ) ), array( '7', '0', '', '', '', '' ) );
$PLAN = new ReflectionProperty( 'TCGiant_Sync_Stock_Buffer', 'every_plan' );
$PLAN->setAccessible( true );
check( 'on every plan: one switch, one check', array( $PLAN->getValue(), TCGiant_Sync_Stock_Buffer::available() ), array( true, true ) );
$PLAN->setValue( null, false );
check( '   switched to Pro\'s alone: the check asks the plan', TCGiant_Sync_Stock_Buffer::available(), class_exists( 'TCGiant_Sync_Entitlements' ) && TCGiant_Sync_Entitlements::instance()->is_pro() );
$PLAN->setValue( null, true );

echo "\nA STOCK CHANGE'S PUSH\n" . str_repeat( '=', 132 ) . "\n";

foreach ( array(
	array( 'neither set (3.44.0\'s push)', '', '', 9, 9 ),
	array( 'Keep back 2', 2, '', 9, 7 ),
	array( 'Show at most 5', '', 5, 9, 5 ),
	array( 'both, 2 and 5', 2, 5, 9, 5 ),
	array( 'both, 2 and 5, the stock 6', 2, 5, 6, 4 ),
) as $case ) {
	list( $label, $keep, $cap, $to, $want ) = $case;
	buffer_shop( $keep, $cap );
	coin( 120, 10, '120000000120', 10 );
	hand_edit( 120, $to );
	check( 'S1 ' . $label . ': stock ' . $to . ', eBay sent ' . $want . ', no read first', array( push_now(), qtys(), stock( 120 ) ), array( array( 'ReviseInventoryStatus' ), array( $want ), $to ) );
	check( '   the mark that eBay was sent less than the stock', marked( 120 ), $want < $to ? '120000000120' : null );
}
check( '   the log says what the buffer left', logged( 'Stock push for WC #120: eBay listing 120000000120 set to 4 available (its stock is 6: "Keep back from eBay" and "Show at most on eBay" leave 4 for eBay).', 'success' ), true );
buffer_shop();
coin( 120, 10, '120000000120', 10 );
hand_edit( 120, 9 );
push_now();
check( '   with neither set, the line as 3.44.0 wrote it', logged( 'Stock push for WC #120: eBay listing 120000000120 set to 9 available.', 'success' ), true );

// The override, through the push.
foreach ( array( array( 'empty: the shop\'s 2', '', 7 ), array( '0: none for it', '0', 9 ), array( '3: its own', '3', 6 ) ) as $case ) {
	buffer_shop( 2 );
	coin( 121, 10, '121000000121', 10 );
	own_buffer( 121, $case[1] );
	hand_edit( 121, 9 );
	push_now();
	check( 'S2 the override ' . $case[0] . ': eBay sent ' . $case[2], qtys(), array( $case[2] ) );
}

echo "\nA WEBSITE SALE AND AN EBAY SALE\n" . str_repeat( '=', 132 ) . "\n";

// W1: eBay already shows the buffered 8 of 10, recorded; a website sale of 1.
buffer_shop( 2 );
coin( 130, 10, '130000000130', 8, 0, 0 );
$GLOBALS['META'][130][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '130000000130', 'at' => time() );
website_order( 1300, array( 130 => 1 ) );
check( 'W1 a website sale, stock 10 to 9, eBay at 8: read first, then 7 - the sale, never the buffer', array( push_now(), qtys(), stock( 130 ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), array( 7 ), 9 ) );

// W2: the first push with the buffer: eBay shows the shop's own 10, nothing recorded.
buffer_shop( 2 );
coin( 131, 10, '131000000131', 10, 4 );
website_order( 1310, array( 131 => 1 ) );
check( 'W2 the first sale with a buffer, eBay at its own 10 (4 sold before, no record): 7 sent, the stock 9 - eBay\'s old sales not taken', array( push_now(), qtys(), stock( 131 ), marked( 131 ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), array( 7 ), 9, '131000000131' ) );

// E1: an eBay sale: order sync's settle reads 7 of 8, 1 sold since the record.
buffer_shop( 2 );
coin( 132, 10, '132000000132', 7, 1, 0 );
$GLOBALS['META'][132][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '132000000132', 'at' => time() );
$IMP->settle_item( '132000000132' );
check( 'E1 an eBay sale of 1, eBay at 7 of the 8 sent: the stock 10 to 9 - not to eBay\'s 7', array( stock( 132 ), rec( 132 ) ), array( 9, array( '132000000132', '', 1 ) ) );
for ( $i = 0; $i < 5; $i++ ) {
	next_request();
	ebay_has( '132000000132', coin_item( 132, '132000000132', 7, 1 ) );
	$IMP->settle_item( '132000000132' );
}
check( '   five settles more, eBay unchanged: still 9 - no ratchet', stock( 132 ), 9 );
check( '   with neither set it is 3.44.0\'s: eBay\'s figure', ( function () { buffer_shop(); coin( 133, 10, '133000000133', 7, 1, 0 ); $GLOBALS['IMP']->settle_item( '133000000133' ); return stock( 133 ); } )(), 7 );

echo "\nNO RATCHET: EVERY READ OF EBAY'S FIGURE, MANY TIMES\n" . str_repeat( '=', 132 ) . "\n";

// R1: the buffered push, then imports, settles and the weekly check.
buffer_shop( 2 );
coin( 140, 11, '140000000140', 11, 0, 0 );
hand_edit( 140, 10 );
push_now();
check( 'R1 the push: 8 of 10', array( qtys(), marked( 140 ) ), array( array( 8 ), '140000000140' ) );
for ( $i = 0; $i < 6; $i++ ) {
	next_request();
	import_listing( coin_item( 140, '140000000140', 8, 0 ) );
	ebay_has( '140000000140', coin_item( 140, '140000000140', 8, 0 ) );
	$IMP->settle_item( '140000000140' );
}
check( '   six imports and six settles, eBay at 8: the stock still 10', array( stock( 140 ), $GLOBALS['QUEUE'] ), array( 10, array() ) );
check( '   the import says why', logged( 'Stock kept at 10: eBay\'s 8 is what "Keep back from eBay" or "Show at most on eBay" left of it, not the stock' ) || in_array( 'Stock kept at 10: eBay\'s 8 is what "Keep back from eBay" or "Show at most on eBay" left of it, not the stock', (array) ( meta( 140, '_tcgiant_sync_log' )[0]['decisions'] ?? array() ), true ), true );
// An eBay sale, then imports.
import_listing( coin_item( 140, '140000000140', 6, 2 ) );
check( '   eBay sells 2 (6 left): the import takes the 2 - 8, not 6', array( stock( 140 ), rec( 140 ) ), array( 8, array( '140000000140', '', 2 ) ) );
for ( $i = 0; $i < 4; $i++ ) {
	import_listing( coin_item( 140, '140000000140', 6, 2 ) );
}
check( '   four imports more: still 8', stock( 140 ), 8 );
// The weekly check.
$GLOBALS['LOG'] = array();
$RECONCILE->invoke( null, array( coin_item( 140, '140000000140', 6, 2 ) ) );
check( 'R2 the weekly check, eBay at 6 = what is sent (8 less 2): no mismatch, nothing written', array( count_logged( 'Reconciliation mismatch' ), stock( 140 ) ), array( 0, 8 ) );
$RECONCILE->invoke( null, array( coin_item( 140, '140000000140', 5, 3 ) ) );
check( '   eBay at 5: a mismatch against what is sent, said so', logged( 'Reconciliation mismatch: "Coin 140" (eBay #140000000140, WC #140) — WC stock: 8, eBay available: 5, sent to eBay: 6 ("Keep back from eBay" and "Show at most on eBay")' ), true );
buffer_shop();
coin( 141, 10, '141000000141', 10 );
$GLOBALS['LOG'] = array();
$RECONCILE->invoke( null, array( coin_item( 141, '141000000141', 9, 1 ) ) );
check( '   with neither set, 3.44.0\'s line', logged( 'Reconciliation mismatch: "Coin 141" (eBay #141000000141, WC #141) — WC stock: 10, eBay available: 9' ) && ! logged( 'sent to eBay' ), true );

// R3: an ended listing, ended in Seller Hub with the buffered 8 unsold.
buffer_shop( 2 );
coin( 142, 10, '142000000142', 8, 0, 0 );
$GLOBALS['META'][142][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '142000000142', 'at' => time() );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 142, 8, 0, ended( '142000000142', 8, 0 ) );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 142, 8, 0, ended( '142000000142', 8, 0 ) );
check( 'R3 an ended listing with the buffered 8 unsold, settled twice: still 10, nothing noted as unsold', array( stock( 142 ), meta( 142, '_ebay_unsold_at_end' ) ), array( 10, null ) );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 142, 8, 3, ended( '142000000142', 8, 3 ) );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 142, 8, 3, ended( '142000000142', 8, 3 ) );
check( '   eBay sold 3 before it ended, settled twice: 7, once', array( stock( 142 ), rec( 142 ) ), array( 7, array( '142000000142', '', 3 ) ) );
buffer_shop();
product( 143, 10, '143000000143' );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 143, 8, 0, ended( '143000000143', 8, 0 ) );
check( '   with no buffered figure sent, 3.44.0\'s: the lower, 8', stock( 143 ), 8 );

// R4: sold out on eBay, with stock kept back: not trashed.
buffer_shop( 2 );
coin( 144, 10, '144000000144', 0, 8, 0 );
$GLOBALS['META'][144][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '144000000144', 'at' => time() );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 144, 8, 8, ended( '144000000144', 8, 8 ) );
check( 'R4 every one of the 8 sent sold: the stock 2, and the product not trashed', array( stock( 144 ), TCGiant_Sync_Inventory::maybe_remove_sold_out( 144, 8, 8 ), get_post_status( 144 ) ), array( 2, false, 'publish' ) );
check( '   and said why', logged( 'kept 2 of its stock back here, still for sale' ), true );

// R5: a relist on eBay that the product follows: the mark and a record of 0 go with it.
buffer_shop( 2 );
coin( 145, 10, '145000000145', 8, 1, 1 );
$GLOBALS['META'][145][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '145000000145', 'at' => time() );
TCGiant_Sync_Inventory::follow_relist( 145, '145000000145', '245000000145', array( 'status' => 'Active', 'listing_type' => 'FixedPriceItem', 'start_time' => '2026-11-20T12:00:00.000Z', 'end_time' => '2026-12-20T12:00:00.000Z' ), 'Delta sync' );
check( 'R5 followed to its relist: the mark on the relist, its record at 0', array( marked( 145 ), rec( 145 ) ), array( '245000000145', array( '245000000145', '', 0 ) ) );
for ( $i = 0; $i < 3; $i++ ) {
	import_listing( coin_item( 145, '245000000145', 7, 1 ) );
}
check( '   three imports of the relist, eBay at 7 with 1 sold on it: 9 - its sale only', stock( 145 ), 9 );

// R6: the link-time read of a listing a push made.
buffer_shop( 2 );
product( 146, 10, '', array( '_name' => 'Coin 146' ) );
$LINK = new ReflectionMethod( 'TCGiant_Sync_Mapper', 'link_stock' );
$LINK->setAccessible( true );
$said = $LINK->invoke( $MAPPER, wc_get_product( 146 ), array( 'stock_quantity' => 8, 'quantity_sold' => 0, 'meta' => array( '_ebay_listing_status' => 'Active', '_ebay_listing_type' => 'FixedPriceItem' ) ), '146000000146' );
check( 'R6 the link of a listing showing the buffered 8 of 10: kept at 10, nothing to send', array( stock( 146 ), $said['push'] ), array( 10, false ) );
$said = $LINK->invoke( $MAPPER, wc_get_product( 146 ), array( 'stock_quantity' => 10, 'quantity_sold' => 0, 'meta' => array( '_ebay_listing_status' => 'Active', '_ebay_listing_type' => 'FixedPriceItem' ) ), '146000000146' );
check( '   one showing the whole 10: sent the buffered figure', array( stock( 146 ), $said['push'] ), array( 10, true ) );

echo "\nA SETTING CHANGED SINCE THE LAST PUSH\n" . str_repeat( '=', 132 ) . "\n";

// C1: the buffer taken off; eBay still shows the old 8: an import does not take 2.
buffer_shop();
coin( 150, 10, '150000000150', 8, 0, 0 );
$GLOBALS['META'][150][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '150000000150', 'at' => time() );
import_listing( coin_item( 150, '150000000150', 8, 0 ) );
ebay_has( '150000000150', coin_item( 150, '150000000150', 8, 0 ) );
$IMP->settle_item( '150000000150' );
check( 'C1 the buffer taken off, eBay still at the 8 sent: import and settle leave 10', stock( 150 ), 10 );
TCGiant_Sync_Inventory::send_stock_to_ebay( array( 150 ) );
push_now();
check( '   the next push sends the whole 10, and the mark goes', array( qtys(), marked( 150 ) ), array( array( 10 ), null ) );
// C2: a buffer set, nothing pushed since: eBay's figure is still the stock.
buffer_shop( 2 );
coin( 151, 10, '151000000151', 9, 1, 0 );
import_listing( coin_item( 151, '151000000151', 9, 1 ) );
check( 'C2 a buffer set, nothing sent since: eBay\'s 9 (1 sold) is still the stock', stock( 151 ), 9 );

echo "\nVARIATIONS: THEIR OWN STOCK, AND A STOCK THEY SHARE\n" . str_repeat( '=', 132 ) . "\n";

// V1: own stock.
buffer_shop( 2 );
variable( 160, '160000000160', array( '_sku' => 'TEE' ) );
variation( 161, 160, 'TEE-S', 10, array( 'size' => 'S' ) );
variation( 162, 160, 'TEE-M', 4, array( 'size' => 'M' ) );
$tee = function ( $s, $m, $s_sold = 0, $m_sold = 0 ) {
	return variation_listing( '160000000160', array( ev( 'TEE-S', $s + $s_sold, $s_sold, array( 'size' => 'S' ) ), ev( 'TEE-M', $m + $m_sold, $m_sold, array( 'size' => 'M' ) ) ) ) + array( 'Title' => 'Tee', 'SKU' => 'TEE' );
};
ebay_has( '160000000160', $tee( 10, 4 ) );
hand_edit( 161, 9 );
push_now();
check( 'V1 a variation keeping its own 10, edited to 9: eBay sent 7', array( revised(), marked( 161 ) ), array( array( array( 'TEE-S' => 7 ) ), '160000000160' ) );
seen( 161, '160000000160', 0, 'TEE-S' );
seen( 162, '160000000160', 0, 'TEE-M' );
for ( $i = 0; $i < 4; $i++ ) {
	import_listing( $tee( 7, 4 ) );
	ebay_has( '160000000160', $tee( 7, 4 ) );
	$IMP->settle_item( '160000000160' );
}
check( '   four imports and settles, eBay at 7: still 9 - and M, never sent buffered, takes eBay\'s 4 as ever', array( stock( 161 ), stock( 162 ) ), array( 9, 4 ) );
import_listing( $tee( 6, 4, 1, 0 ) );
check( '   eBay sells an S: 8, not 6', stock( 161 ), 8 );
// V2: a stock they share: buffered once, each sent that.
buffer_shop( 2 );
variable( 170, '170000000170', array( '_manage_stock' => 'yes', '_stock' => 10, '_sku' => 'SOCK' ) );
variation( 171, 170, 'SOCK-S', null, array( 'size' => 'S' ) );
variation( 172, 170, 'SOCK-M', null, array( 'size' => 'M' ) );
$sock = function ( $s, $m, $s_sold = 0, $m_sold = 0 ) {
	return variation_listing( '170000000170', array( ev( 'SOCK-S', $s + $s_sold, $s_sold, array( 'size' => 'S' ) ), ev( 'SOCK-M', $m + $m_sold, $m_sold, array( 'size' => 'M' ) ) ) ) + array( 'Title' => 'Socks', 'SKU' => 'SOCK' );
};
ebay_has( '170000000170', $sock( 10, 10 ), $sock( 7, 7 ) );
hand_edit( 170, 9 );
push_now();
check( 'V2 a shared stock of 10, edited to 9: buffered once - each variation sent 7, not 9 less 2 each', array( revised(), marked( 170 ) ), array( array( array( 'SOCK-S' => 7, 'SOCK-M' => 7 ) ), '170000000170' ) );
check( '   the log says the shared figure and the buffer', logged( 'Stock push for WC #170: the shared stock, 7, sent to 2 variation(s) of eBay listing 170000000170 (its stock is 9: "Keep back from eBay" and "Show at most on eBay" leave 7 for eBay).', 'success' ), true );
for ( $i = 0; $i < 4; $i++ ) {
	import_listing( $sock( 7, 7 ) );
	ebay_has( '170000000170', $sock( 7, 7 ) );
	$IMP->settle_item( '170000000170' );
}
check( '   four imports and settles, eBay at 7 for each: the shared stock still 9', stock( 170 ), 9 );
import_listing( $sock( 6, 7, 1, 0 ) );
check( '   eBay sells an S: 8', stock( 170 ), 8 );
// V3: a shared stock with no record, under the mark: no sale read from the figure.
buffer_shop( 2 );
variable( 175, '175000000175', array( '_manage_stock' => 'yes', '_stock' => 10, '_sku' => 'CAP' ) );
variation( 176, 175, 'CAP-S', null, array( 'size' => 'S' ) );
$GLOBALS['META'][175][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '175000000175', 'at' => time() );
TCGiant_Sync_Inventory::settle_pool( 175, TCGiant_Sync_Variations::from_item( variation_listing( '175000000175', array( ev( 'CAP-S', 8 + 3, 3, array( 'size' => 'S' ) ) ) ) ), '175000000175' );
check( 'V3 a shared stock under the mark, a variation with no record, eBay at 8 with 3 sold: not read as 2 sales - 10, recorded from now', array( stock( 175 ), rec( 176 ) ), array( 10, array( '175000000175', 'CAP-S', 3 ) ) );

echo "\nAUCTIONS: UNTOUCHED\n" . str_repeat( '=', 132 ) . "\n";

foreach ( array( '', 2 ) as $keep ) {
	buffer_shop( $keep );
	product( 180, 1, '180000000180', array( '_ebay_listing_type' => 'Chinese' ) );
	ebay_has( '180000000180', listing( '180000000180', 1, 0, array( 'ListingType' => 'Chinese', 'SellingStatus' => array( 'BidCount' => '0' ) ) ) );
	hand_edit( 180, 3 );
	$up = push_now();
	hand_edit( 180, 0 );
	$down = push_now();
	$runs[] = array( $up, $down, all_sent( 'EndItem' ) ? 1 : 0 );
}
check( 'A1 an auction raised, then to 0: the same calls with Keep back 2 as with none', $runs[0], $runs[1] );

echo "\n0 FOR EBAY WITH STOCK STILL HERE\n" . str_repeat( '=', 132 ) . "\n";

buffer_shop( 2 );
coin( 190, 3, '190000000190', 1, 0, 0 );
hand_edit( 190, 2 );
check( 'Z1 Keep back 2, the stock 2, auto-end on: eBay\'s 0 - the listing ended, as at 0', array( push_now(), stock( 190 ), marked( 190 ) ), array( array( 'EndItem' ), 2, '190000000190' ) );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 190, 1, 0, ended( '190000000190', 1, 0 ) );
check( '   the ended listing\'s settle keeps the 2 here', stock( 190 ), 2 );
buffer_shop( 2, '', array( 'auto_end_zero_stock' => '0' ) );
coin( 191, 3, '191000000191', 1, 0, 0 );
hand_edit( 191, 2 );
check( 'Z2 auto-end off (Out-of-Stock control): 0 sent, the listing live', array( push_now(), qtys(), stock( 191 ) ), array( array( 'ReviseInventoryStatus' ), array( 0 ), 2 ) );

echo "\nSEND STOCK TO EBAY\n" . str_repeat( '=', 132 ) . "\n";

buffer_shop( 2 );
coin( 200, 10, '200000000200', 10, 0, 0 );
coin( 201, 2, '201000000201', 2, 0, 0 );
$said = TCGiant_Sync_Inventory::send_stock_to_ebay( array( 200, 201 ) );
check( 'X1 Send stock to eBay: 10 goes as a quantity, 2 as 0 - listed to end', array( $said['revise']['ids'] ?? null, $said['end']['ids'] ?? null ), array( array( 200 ), array( 201 ) ) );
$GLOBALS['WIRE'] = array();
run_queue();
check( '   the pushes: 8, and 2 kept back, so the listing read and ended', array( calls(), qtys() ), array( array( 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), array( 8 ) ) );

echo "\nA SETTING CHANGED SENDS NOTHING; THE TAB'S SAVE QUEUES ONE PUSH; A LISTING'S QUANTITIES NOTED\n" . str_repeat( '=', 132 ) . "\n";

buffer_shop();
coin( 210, 10, '210000000210', 10, 0, 0 );
coin( 211, 5, '211000000211', 5, 0, 0 );
$GLOBALS['WIRE'] = array();
$saved = TCGiant_Sync_Admin::instance()->sanitize_settings( array( '_tcg_form' => 'import', 'stock_keep_back' => ' 2 ', 'stock_show_at_most' => 'x' ) );
check( 'T1 the settings saved: 2 and none, nothing queued or sent, said once', array( $saved['stock_keep_back'] ?? null, $saved['stock_show_at_most'] ?? null, $GLOBALS['QUEUE'], $GLOBALS['WIRE'], (bool) get_transient( TCGiant_Sync_Admin::BUFFER_SAID ), count_logged( 'Nothing was sent to eBay: each listing takes the new figure at its next stock change' ) ), array( '2', '', array(), array(), true, 1 ) );
$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = $saved;
delete_transient( TCGiant_Sync_Admin::BUFFER_SAID );
TCGiant_Sync_Admin::instance()->sanitize_settings( array( '_tcg_form' => 'import', 'stock_keep_back' => '2', 'stock_show_at_most' => '' ) );
check( '   saved again unchanged: nothing said', get_transient( TCGiant_Sync_Admin::BUFFER_SAID ), false );
$_POST = array( TCGiant_Sync_Stock_Buffer::KEEP_META . '_shown' => '1', TCGiant_Sync_Stock_Buffer::KEEP_META => '3', TCGiant_Sync_Stock_Buffer::CAP_META => '' );
TCGiant_Sync_Stock_Buffer::save_product_field( 210 );
check( 'T2 the tab saves Keep back 3 on #210: one push queued, said', array( push_args(), meta( 210, TCGiant_Sync_Stock_Buffer::KEEP_META ), logged( 'WC #210: "Keep back from eBay" or "Show at most on eBay" changed on its eBay tab - its stock push is queued' ) ), array( array( array( 210, 'edit', null ) ), '3', true ) );
check( '   the push: 7', array( push_now(), qtys() ), array( array( 'ReviseInventoryStatus' ), array( 7 ) ) );
TCGiant_Sync_Stock_Buffer::save_product_field( 210 );
check( '   saved again unchanged: nothing queued', $GLOBALS['QUEUE'], array() );
$_POST = array( TCGiant_Sync_Stock_Buffer::KEEP_META . '_shown' => '1', TCGiant_Sync_Stock_Buffer::KEEP_META => 'abc' );
check( '   a figure that is not a whole number: left as it was', array( TCGiant_Sync_Stock_Buffer::save_posted( 210, $_POST ), meta( 210, TCGiant_Sync_Stock_Buffer::KEEP_META ) ), array( false, '3' ) );
$_POST = array( TCGiant_Sync_Stock_Buffer::KEEP_META . '_shown' => '1', TCGiant_Sync_Stock_Buffer::KEEP_META => '' );
TCGiant_Sync_Stock_Buffer::save_product_field( 210 );
check( '   emptied: the shop\'s again, a push queued', array( meta( 210, TCGiant_Sync_Stock_Buffer::KEEP_META ), count( push_args() ) ), array( null, 1 ) );
$_POST = array();
check( '   the tab not drawn: nothing saved', TCGiant_Sync_Stock_Buffer::save_posted( 210, array( TCGiant_Sync_Stock_Buffer::KEEP_META => '5' ) ), false );

// N1: a new listing's quantities noted.
buffer_shop( 2 );
product( 220, 10, '220000000220' );
TCGiant_Sync_Inventory::note_listing_sent( '220000000220', 'created', array( array( 'holder' => 220, 'record' => 220, 'sku' => '', 'sent' => 8, 'stock' => 10 ) ) );
check( 'N1 a new listing sent 8 of 10: marked, its record at 0', array( marked( 220 ), rec( 220 ) ), array( '220000000220', array( '220000000220', '', 0 ) ) );
import_listing( coin_item( 220, '220000000220', 7, 1 ) );
check( '   its first import, 1 sold: 9 - never eBay\'s 7', stock( 220 ), 9 );
product( 221, 10, '221000000221' );
TCGiant_Sync_Inventory::note_listing_sent( '221000000221', 'updated', array( array( 'holder' => 221, 'record' => 221, 'sku' => '', 'sent' => 8, 'stock' => 10 ) ) );
check( '   an Update: marked, no record made', array( marked( 221 ), rec( 221 ) ), array( '221000000221', null ) );
buffer_shop();
product( 222, 10, '222000000222' );
TCGiant_Sync_Inventory::note_listing_sent( '222000000222', 'created', array( array( 'holder' => 222, 'record' => 222, 'sku' => '', 'sent' => 10, 'stock' => 10 ) ) );
check( '   with neither set: no mark, no record - as 3.44.0', array( marked( 222 ), rec( 222 ), meta( 222, TCGiant_Sync_Inventory::BUFFERED_META ) ), array( null, null, null ) );

echo "\nMORE OF EACH PATH\n" . str_repeat( '=', 132 ) . "\n";

// W3: a website sale under the mark with no record: eBay's figure against the
// stock before the sale is no sale (3.28.0's rule reads the buffer as 2).
buffer_shop( 2 );
coin( 230, 10, '230000000230', 8, 3 );
$GLOBALS['META'][230][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '230000000230', 'at' => time() );
website_order( 2300, array( 230 => 1 ) );
check( 'W3 a website sale, eBay at the buffered 8 (3 sold), no record: the stock 9, eBay sent 7 - the buffer not read as sales', array( push_now(), qtys(), stock( 230 ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), array( 7 ), 9 ) );

// C3: eBay's answer to the revise shows a sale since the record: taken off,
// and what the buffer leaves of the rest sent again.
buffer_shop( 2 );
coin( 231, 11, '231000000231', 9, 0, 0 );
$GLOBALS['EBAY']['sold'] = array( '231000000231' => 1 );
hand_edit( 231, 10 );
check( 'C3 an edit 11 to 10 sends 8, eBay\'s answer shows 1 sold since the record: the stock 9, eBay sent 7', array( push_now(), qtys(), stock( 231 ) ), array( array( 'ReviseInventoryStatus', 'ReviseInventoryStatus' ), array( 8, 7 ), 9 ) );
unset( $GLOBALS['EBAY']['sold'] );

// P1: past the fleet's 80% ceiling, going to 0 on eBay stops an oversell.
buffer_shop( 2 );
fleet_used( 0.85 );
coin( 232, 3, '232000000232', 1, 0, 0 );
hand_edit( 232, 2 );
check( 'P1 the fleet at 85%: the stock 3 to 2, Keep back 2 - 0 for eBay goes today, as any 0', push_now(), array( 'EndItem' ) );
buffer_shop( 2 );
fleet_used( 0.85 );
coin( 233, 10, '233000000233', 8, 0, 0 );
hand_edit( 233, 9 );
check( '   a raise or lowering above 0 for eBay waits for tomorrow, as before', push_now(), array() );

// E2: order sync's settle under the mark with no record: nothing read from
// eBay's figure, the record started; eBay's next sale then comes off.
buffer_shop( 2 );
coin( 234, 10, '234000000234', 8, 3 );
$GLOBALS['META'][234][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '234000000234', 'at' => time() );
$IMP->settle_item( '234000000234' );
check( 'E2 a sale check under the mark, no record, eBay at 8 with 3 sold: the stock 10, its record started at 3', array( stock( 234 ), rec( 234 ) ), array( 10, array( '234000000234', '', 3 ) ) );
next_request();
ebay_has( '234000000234', coin_item( 234, '234000000234', 7, 4 ) );
$IMP->settle_item( '234000000234' );
check( '   eBay sells 1 more: 9', stock( 234 ), 9 );

// V4: a shared stock eBay already shows at the buffered figure: nothing
// sent, and marked all the same.
buffer_shop( 2 );
variable( 178, '178000000178', array( '_manage_stock' => 'yes', '_stock' => 11, '_sku' => 'HAT' ) );
variation( 179, 178, 'HAT-S', null, array( 'size' => 'S' ) );
seen( 179, '178000000178', 0, 'HAT-S' );
ebay_has( '178000000178', variation_listing( '178000000178', array( ev( 'HAT-S', 8, 0, array( 'size' => 'S' ) ) ) ) + array( 'Title' => 'Hat', 'SKU' => 'HAT' ) );
hand_edit( 178, 10 );
check( 'V4 a shared stock 11 to 10, eBay already at the buffered 8: read, nothing sent, marked', array( push_now(), marked( 178 ) ), array( array( 'GetItem' ), '178000000178' ) );

// D1/D2: a disabled variation keeping its own stock (3.44.0) under the buffer.
buffer_shop( 2 );
variable( 240, '240000000240', array( '_sku' => 'CUP' ) );
variation( 241, 240, 'CUP-S', 10, array( 'size' => 'S' ) );
variation( 242, 240, 'CUP-M', 4, array( 'size' => 'M' ) );
$GLOBALS['POSTS'][241]['status'] = 'private';
seen( 241, '240000000240', 0, 'CUP-S' );
ebay_has( '240000000240', variation_listing( '240000000240', array( ev( 'CUP-S', 10, 0, array( 'size' => 'S' ) ), ev( 'CUP-M', 4, 0, array( 'size' => 'M' ) ) ) ) + array( 'Title' => 'Cup', 'SKU' => 'CUP' ) );
hand_edit( 241, 9 );
push_now();
check( 'D1 a disabled variation keeping its own stock, another with stock on eBay: 0, as 3.44.0 sends it', revised(), array( array( 'CUP-S' => 0 ) ) );
buffer_shop( 2 );
variable( 240, '240000000240', array( '_sku' => 'CUP' ) );
variation( 241, 240, 'CUP-S', 10, array( 'size' => 'S' ) );
variation( 242, 240, 'CUP-M', 0, array( 'size' => 'M' ) );
$GLOBALS['POSTS'][241]['status'] = 'private';
seen( 241, '240000000240', 0, 'CUP-S' );
ebay_has( '240000000240', variation_listing( '240000000240', array( ev( 'CUP-S', 10, 0, array( 'size' => 'S' ) ), ev( 'CUP-M', 0, 0, array( 'size' => 'M' ) ) ) ) + array( 'Title' => 'Cup', 'SKU' => 'CUP' ) );
hand_edit( 241, 9 );
push_now();
check( 'D2 the same, the listing\'s only stock: never 0, kept no higher than what the buffer leaves of its 9 - 7', array( revised(), logged( 'It is kept no higher than its own stock, 7 (its stock is 9: "Keep back from eBay" and "Show at most on eBay" leave 7 for eBay).' ) ), array( array( array( 'CUP-S' => 7 ) ), true ) );

echo "\nFIX ROUND 1 (REVIEW 1)\n" . str_repeat( '=', 132 ) . "\n";

// H2 (probe p_var_removed): a variation the buffer sent 0, removed by eBay.
buffer_shop( 2 );
variable( 370, '370000000370', array( '_sku' => 'HAT' ) );
variation( 371, 370, 'HAT-S', 3, array( 'size' => 'S' ) );
variation( 372, 370, 'HAT-M', 9, array( 'size' => 'M' ) );
$hat = function ( array $evs ) { return variation_listing( '370000000370', $evs ) + array( 'Title' => 'Hat', 'SKU' => 'HAT' ); };
seen( 371, '370000000370', 0, 'HAT-S' );
seen( 372, '370000000370', 0, 'HAT-M' );
ebay_has( '370000000370', $hat( array( ev( 'HAT-S', 1, 0, array( 'size' => 'S' ) ), ev( 'HAT-M', 7, 0, array( 'size' => 'M' ) ) ) ) );
hand_edit( 371, 2 );
check( 'H2 (probe) S keeping its own 3 edited to 2, Keep back 2: sent 0', array( push_now(), revised() ), array( array( 'ReviseInventoryStatus' ), array( array( 'HAT-S' => 0 ) ) ) );
next_request();
import_listing( $hat( array( ev( 'HAT-M', 7, 0, array( 'size' => 'M' ) ) ) ) );
import_listing( $hat( array( ev( 'HAT-M', 7, 0, array( 'size' => 'M' ) ) ) ) );
$z = meta( 371, TCGiant_Sync_Mapper::ZEROED_META );
check( '   eBay removed it (never sold, Out-of-Stock control off); two imports: its 2 kept, still on the website, not retired - marked as eBay\'s removal at 0, said once', array( stock( 371 ), get_post_status( 371 ), meta( 371, TCGiant_Sync_Mapper::RETIRED_META ), $z['buffer'] ?? null, isset( $z['gone'] ), count_logged( 'eBay removed it when "Keep back from eBay" or "Show at most on eBay" left it 0 before it had ever sold' ) ), array( 2, 'publish', null, 1, true, 1 ) );
check( '   the edit screen says how to add it back', TCGiant_Sync_Inventory::zeroed_variation_lines( 370 ), array( 'eBay no longer lists the variation Product #371; press Update eBay Listing to add it back.' ) );
$GLOBALS['QUEUE'] = array();
ebay_has( '370000000370', $hat( array( ev( 'HAT-M', 7, 0, array( 'size' => 'M' ) ) ) ) );
hand_edit( 371, 6 );
check( '   restocked to 6 (4 for eBay): its push reads eBay once (fix round 2), finds it gone, sends nothing - no error, no try again, the mark kept', array( push_now(), $GLOBALS['QUEUE'], logged( 'Stock push for WC #371: variation HAT-S is not on eBay listing 370000000370 - eBay removed it' ), is_array( meta( 371, TCGiant_Sync_Mapper::ZEROED_META ) ) ), array( array( 'GetItem' ), array(), true, true ) );
import_listing( $hat( array( ev( 'HAT-S', 4, 0, array( 'size' => 'S' ) ), ev( 'HAT-M', 7, 0, array( 'size' => 'M' ) ) ) ) );
check( '   an Update put it back: the next import finds it, the removal mark goes, the stock stays 6', array( meta( 371, TCGiant_Sync_Mapper::ZEROED_META ), stock( 371 ) ), array( null, 6 ) );
// The same for variations sharing a stock the buffer leaves at 0.
buffer_shop( 2 );
variable( 380, '380000000380', array( '_manage_stock' => 'yes', '_stock' => 2, '_sku' => 'BAG' ) );
variation( 381, 380, 'BAG-S', null, array( 'size' => 'S' ) );
variation( 382, 380, 'BAG-M', null, array( 'size' => 'M' ) );
seen( 381, '380000000380', 0, 'BAG-S' );
seen( 382, '380000000380', 0, 'BAG-M' );
$GLOBALS['META'][380][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '380000000380', 'at' => time() );
import_listing( variation_listing( '380000000380', array( ev( 'BAG-M', 0, 0, array( 'size' => 'M' ) ) ) ) + array( 'Title' => 'Bag', 'SKU' => 'BAG' ) );
check( '   a shared stock of 2 the buffer leaves at 0, S removed by eBay: S stays on the website, the shared 2 kept', array( get_post_status( 381 ), meta( 381, TCGiant_Sync_Mapper::RETIRED_META ), ( meta( 381, TCGiant_Sync_Mapper::ZEROED_META )['buffer'] ?? null ), stock( 380 ) ), array( 'publish', null, 1, 2 ) );
buffer_shop();
variable( 390, '390000000390', array( '_sku' => 'CAP2' ) );
variation( 391, 390, 'CAP2-S', 0, array( 'size' => 'S' ) );
variation( 392, 390, 'CAP2-M', 4, array( 'size' => 'M' ) );
import_listing( variation_listing( '390000000390', array( ev( 'CAP2-M', 4, 0, array( 'size' => 'M' ) ) ) ) + array( 'Title' => 'Cap', 'SKU' => 'CAP2' ) );
check( '   with no buffer sent, 3.44.0\'s retire as before', array( get_post_status( 391 ), null !== meta( 391, TCGiant_Sync_Mapper::RETIRED_META ) ), array( 'private', true ) );

// H3 (probe p_cap_refill): "Show at most" topped up after eBay's sales.
buffer_shop( '', 3 );
coin( 340, 11, '340000000340', 11, 0, 0 );
hand_edit( 340, 10 );
check( 'H3 (probe) Show at most 3, stock 10: eBay sent 3', array( push_now(), qtys(), marked( 340 ) ), array( array( 'ReviseInventoryStatus' ), array( 3 ), '340000000340' ) );
$refill = array();
for ( $s = 1; $s <= 3; $s++ ) {
	next_request();
	ebay_has( '340000000340', coin_item( 340, '340000000340', 3 - $s + ( $s - 1 ), $s ) );
	$IMP->settle_item( '340000000340' );
	$queued = count( pushes() );
	$WIRE   = array();
	run_queue();
	$refill[] = array( stock( 340 ), $queued, calls(), qtys() );
}
check( '   three eBay sales, each sale check: the stock down by 1, one push queued, one call, eBay topped up to 3', $refill, array( array( 9, 1, array( 'ReviseInventoryStatus' ), array( 3 ) ), array( 8, 1, array( 'ReviseInventoryStatus' ), array( 3 ) ), array( 7, 1, array( 'ReviseInventoryStatus' ), array( 3 ) ) ) );
next_request();
ebay_has( '340000000340', coin_item( 340, '340000000340', 3, 3 ) );
$IMP->settle_item( '340000000340' );
check( '   a sale check after, eBay at 3: nothing queued - never twice for one sale', array( stock( 340 ), count( pushes() ) ), array( 7, 0 ) );
buffer_shop( 2 );
coin( 341, 10, '341000000341', 8, 0, 0 );
$GLOBALS['META'][341][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '341000000341', 'at' => time() );
ebay_has( '341000000341', coin_item( 341, '341000000341', 7, 1 ) );
$IMP->settle_item( '341000000341' );
check( '   Keep back alone, an eBay sale: 9, eBay at 7 is what is sent - nothing queued', array( stock( 341 ), count( pushes() ) ), array( 9, 0 ) );
buffer_shop( '', 3 );
coin( 342, 10, '342000000342', 3, 0, 0 );
$GLOBALS['META'][342][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '342000000342', 'at' => time() );
import_listing( coin_item( 342, '342000000342', 2, 1 ) );
check( '   the import, an eBay sale under a cap of 3: 9, one push queued - a raise, not a sale\'s (fix round 2)', array( stock( 342 ), count( pushes() ), top_up_sale_flags() ), array( 9, 1, array( null ) ) );
buffer_shop( '', 3 );
variable( 343, '343000000343', array( '_sku' => 'SOX' ) );
variation( 344, 343, 'SOX-S', 10, array( 'size' => 'S' ) );
seen( 344, '343000000343', 0, 'SOX-S' );
$GLOBALS['META'][344][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '343000000343', 'at' => time() );
ebay_has( '343000000343', variation_listing( '343000000343', array( ev( 'SOX-S', 3, 1, array( 'size' => 'S' ) ) ) ) + array( 'Title' => 'Sox', 'SKU' => 'SOX' ) );
$IMP->settle_item( '343000000343' );
check( '   a variation keeping its own stock, the sale check: 9, its push queued - a raise', array( stock( 344 ), array_column( push_args(), 0 ), top_up_sale_flags() ), array( 9, array( 344 ), array( null ) ) );
push_now();
next_request();
$GLOBALS['QUEUE']      = array();
$GLOBALS['TRANSIENTS'] = array();
import_listing( variation_listing( '343000000343', array( ev( 'SOX-S', 2, 2, array( 'size' => 'S' ) ) ) ) + array( 'Title' => 'Sox', 'SKU' => 'SOX' ) );
check( '   and the import, another eBay sale: 8, its push queued - a raise', array( stock( 344 ), array_column( push_args(), 0 ), top_up_sale_flags() ), array( 8, array( 344 ), array( null ) ) );

// M1 (probes p_lost_answer, p_link): the mark before the call.
buffer_shop( 2 );
coin( 320, 10, '320000000320', 10, 0, 0 );
hand_edit( 320, 9 );
$timeout = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds' );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout, $timeout );
$GLOBALS['ON_CALL']['ReviseInventoryStatus'] = function () { ebay_has( '320000000320', coin_item( 320, '320000000320', 7, 0 ) ); };
push_now();
check( 'M1 (probe) eBay takes 7 of 9, every answer lost: marked before the call', array( stock( 320 ), marked( 320 ) ), array( 9, '320000000320' ) );
next_request();
import_listing( coin_item( 320, '320000000320', 7, 0 ) );
ebay_has( '320000000320', coin_item( 320, '320000000320', 7, 0 ) );
$IMP->settle_item( '320000000320' );
check( '   an import and a sale check before the try again: 9 - before, 7', stock( 320 ), 9 );
buffer_shop( 2 );
coin( 321, 10, '321000000321', 10, 0, 0 );
hand_edit( 321, 9 );
ebay_answers( 'ReviseInventoryStatus', refused( 21916635, 'Invalid Multi-SKU item id supplied with variations.' ) );
push_now();
next_request();
import_listing( coin_item( 321, '321000000321', 10, 0 ) );
check( '   eBay refused the 7 for good, the mark stays: an import of eBay\'s unchanged 10 leaves 9 - only a quantity changed in Seller Hub is not brought in', array( marked( 321 ), stock( 321 ) ), array( '321000000321', 9 ) );
import_listing( coin_item( 321, '321000000321', 9, 1 ) );
check( '   and eBay\'s next sale still comes off: 8', stock( 321 ), 8 );
buffer_shop( 2 );
coin( 322, 10, '322000000322', 10 );
hand_edit( 322, 9 );
$GLOBALS['ON_CALL']['ReviseInventoryStatus'] = function () { ebay_has( '322000000322', coin_item( 322, '322000000322', 7, 0 ) ); };
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout, $timeout );
$no_record_calls = push_now();
check( '   no record for the listing: nothing marked before the call; the call tried three times, unanswered, then read back: eBay shows the 7 - landed, recorded, marked', array( $no_record_calls, marked( 322 ), null !== rec( 322 ) ), array( array( 'ReviseInventoryStatus', 'ReviseInventoryStatus', 'ReviseInventoryStatus', 'GetItem' ), '322000000322', true ) );
buffer_shop( 2 );
product( 350, 10, '', array( '_name' => 'Coin 350', '_sku' => 'SKU-350' ) );
$said = $LINK->invoke( $MAPPER, wc_get_product( 350 ), array( 'stock_quantity' => 8, 'quantity_sold' => 0, 'meta' => array( '_ebay_listing_status' => 'Active', '_ebay_listing_type' => 'FixedPriceItem' ) ), '350000000350' );
update_post_meta( 350, '_ebay_item_id', '350000000350' );
import_listing( coin_item( 350, '350000000350', 8, 0 ) );
check( '   (probe p_link) the link of a listing showing the buffered 8: marked; the next import leaves 10 - before, 8', array( $said['push'], marked( 350 ), stock( 350 ) ), array( false, '350000000350', 10 ) );
buffer_shop( 2 );
coin( 323, 10, '323000000323', 10 );
hand_edit( 323, 9 );
ebay_answers( 'ReviseInventoryStatus', refused( 21916635, 'Invalid Multi-SKU item id supplied with variations.' ) );
push_now();
check( '   no record for the listing, eBay refuses the 7 for good: never marked - eBay still shows the stock', marked( 323 ), null );
buffer_shop( 2 );
variable( 325, '325000000325', array( '_manage_stock' => 'yes', '_stock' => 10, '_sku' => 'BELT' ) );
variation( 326, 325, 'BELT-S', null, array( 'size' => 'S' ) );
variation( 327, 325, 'BELT-M', null, array( 'size' => 'M' ) );
seen( 326, '325000000325', 0, 'BELT-S' );
seen( 327, '325000000325', 0, 'BELT-M' );
$belt = variation_listing( '325000000325', array( ev( 'BELT-S', 10, 0, array( 'size' => 'S' ) ), ev( 'BELT-M', 10, 0, array( 'size' => 'M' ) ) ) ) + array( 'Title' => 'Belt', 'SKU' => 'BELT' );
ebay_has( '325000000325', $belt );
hand_edit( 325, 9 );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout, $timeout );
push_now();
check( '   a shared stock of 10 edited to 9, its records there, every answer lost: marked before the call', marked( 325 ), '325000000325' );

// M2 (probe p_pool_relist): a shared stock followed to a relist.
buffer_shop( 2 );
variable( 300, '300000000300', array( '_manage_stock' => 'yes', '_stock' => 10, '_sku' => 'POOL' ) );
variation( 301, 300, 'POOL-S', null, array( 'size' => 'S' ) );
variation( 302, 300, 'POOL-M', null, array( 'size' => 'M' ) );
seen( 301, '300000000300', 0, 'POOL-S' );
seen( 302, '300000000300', 0, 'POOL-M' );
$GLOBALS['META'][300][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '300000000300', 'at' => time() );
TCGiant_Sync_Inventory::follow_relist( 300, '300000000300', '400000000300', array( 'status' => 'Active', 'listing_type' => 'FixedPriceItem', 'start_time' => '2026-11-20T12:00:00.000Z', 'end_time' => '2026-12-20T12:00:00.000Z' ), 'Delta sync' );
check( 'M2 (probe) followed to a relist: the parent\'s mark, each variation\'s record of the relist at 0', array( marked( 300 ), rec( 301 ), rec( 302 ) ), array( '400000000300', array( '400000000300', 'POOL-S', 0 ), array( '400000000300', 'POOL-M', 0 ) ) );
$pool_relist = variation_listing( '400000000300', array( ev( 'POOL-S', 8, 1, array( 'size' => 'S' ) ), ev( 'POOL-M', 8, 0, array( 'size' => 'M' ) ) ) ) + array( 'Title' => 'Pool', 'SKU' => 'POOL' );
ebay_has( '400000000300', $pool_relist );
$IMP->settle_item( '400000000300' );
next_request();
import_listing( $pool_relist );
check( '   the relist sells an S: the sale check and an import take it once - 9 (before, 10)', stock( 300 ), 9 );

// M3 (probe p_prune_relist): the prune's move to a relist records what it takes.
$CHECK = new ReflectionMethod( 'TCGiant_Sync_Importer', 'check_prune_candidate' );
$CHECK->setAccessible( true );
buffer_shop( 2 );
product( 310, 10, '310000000310', array( '_name' => 'Coin 310', '_sku' => 'SKU-310' ) );
seen( 310, '310000000310', 0 );
$GLOBALS['META'][310][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '310000000310', 'at' => time() );
$old_310 = listing( '310000000310', 8, 0, array( 'Title' => 'Coin 310', 'SKU' => 'SKU-310' ) );
$old_310['SellingStatus']['ListingStatus'] = 'Completed';
$old_310['ListingDetails'] = array( 'RelistedItemID' => '410000000310', 'StartTime' => '2026-10-20T12:00:00.000Z', 'EndTime' => '2026-11-19T12:00:00.000Z' );
$new_310 = listing( '410000000310', 8, 1, array( 'Title' => 'Coin 310', 'SKU' => 'SKU-310', 'RelistParentID' => '310000000310' ) );
$new_310['ListingDetails'] = array( 'StartTime' => '2026-11-19T12:00:00.000Z', 'EndTime' => '2026-12-19T12:00:00.000Z' );
ebay_has( '310000000310', $old_310 );
ebay_has( '410000000310', $new_310 );
$prune_calls = 0;
$CHECK->invokeArgs( $IMP, array( 310, '310000000310', &$prune_calls ) );
check( 'M3 (probe) the prune moves it to a relist that sold 1: 9, the record at 1', array( stock( 310 ), rec( 310 ) ), array( 9, array( '410000000310', '', 1 ) ) );
next_request();
ebay_has( '410000000310', $new_310 );
$IMP->settle_item( '410000000310' );
import_listing( $new_310 );
check( '   the sale check and an import after it: still 9 - before, 8', stock( 310 ), 9 );
buffer_shop( '', 3 );
product( 311, 10, '311000000311', array( '_name' => 'Coin 311', '_sku' => 'SKU-311' ) );
seen( 311, '311000000311', 0 );
$GLOBALS['META'][311][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '311000000311', 'at' => time() );
ebay_has( '311000000311', listing( '311000000311', 3, 1, array( 'Title' => 'Coin 311', 'SKU' => 'SKU-311' ) ) );
next_request();
$GLOBALS['QUEUE']      = array();
$GLOBALS['TRANSIENTS'] = array();
$prune_calls           = 0;
$CHECK->invokeArgs( $IMP, array( 311, '311000000311', &$prune_calls ) );
check( 'H3 the pruning check, a live listing under a cap of 3 that sold 1: 9, its push queued - a raise', array( stock( 311 ), array_column( push_args(), 0 ), top_up_sale_flags() ), array( 9, array( 311 ), array( null ) ) );

// M4 (probe p_norecord): an Update leaves the mark with no record.
buffer_shop();
coin( 360, 10, '360000000360', 10, 0 );
ebay_has( '360000000360', coin_item( 360, '360000000360', 9, 1 ) );
$IMP->settle_item( '360000000360' );
next_request();
ebay_has( '360000000360', coin_item( 360, '360000000360', 8, 2 ) );
$IMP->settle_item( '360000000360' );
check( 'M4 (probe) a listing only sale checks read: 8, no record', array( stock( 360 ), rec( 360 ) ), array( 8, null ) );
$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings']['stock_keep_back'] = '2';
$GLOBALS['TRANSIENTS'] = array();
TCGiant_Sync_Inventory::note_listing_sent( '360000000360', 'updated', array( array( 'holder' => 360, 'record' => 360, 'sku' => '', 'sent' => 6, 'stock' => 8 ) ) );
check( '   Keep back 2, an Update sends 6 of 8: marked, no record - its stock\'s push queued, once', array( marked( 360 ), rec( 360 ), push_args() ), array( '360000000360', null, array( array( 360, 'edit', null ) ) ) );
$GLOBALS['EBAY']['sold'] = array( '360000000360' => 2 );
check( '   the push: one call, which starts the record from eBay\'s answer', array( push_now(), rec( 360 ) ), array( array( 'ReviseInventoryStatus' ), array( '360000000360', '', 2 ) ) );
unset( $GLOBALS['EBAY']['sold'] );
next_request();
ebay_has( '360000000360', coin_item( 360, '360000000360', 5, 3 ) );
$IMP->settle_item( '360000000360' );
check( '   eBay sells 1 more: 7 - before, 8', stock( 360 ), 7 );

// M5 (probe p_cancel): a cancelled eBay sale under the mark.
buffer_shop( 2 );
coin( 330, 10, '330000000330', 8, 0, 0 );
$GLOBALS['META'][330][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '330000000330', 'at' => time() );
import_listing( coin_item( 330, '330000000330', 7, 1 ) );
next_request();
import_listing( coin_item( 330, '330000000330', 8, 0 ) );
check( 'M5 (probe) an eBay sale (9), then cancelled: not put back - 9, said plainly; the record follows eBay\'s count down to 0', array( stock( 330 ), rec( 330 ), logged( 'a cancelled eBay sale is not put back on the stock here: add the unit back by hand if you have it.' ) ), array( 9, array( '330000000330', '', 0 ), true ) );
next_request();
import_listing( coin_item( 330, '330000000330', 7, 1 ) );
check( '   eBay\'s next sale after it comes off: 8 - WooCommerce never keeps a unit eBay sold', array( stock( 330 ), rec( 330 ) ), array( 8, array( '330000000330', '', 1 ) ) );
buffer_shop( 2 );
coin( 331, 9, '331000000331', 8, 1, 1 );
$GLOBALS['META'][331][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '331000000331', 'at' => time() );
ebay_has( '331000000331', coin_item( 331, '331000000331', 8, 0 ) );
$IMP->settle_item( '331000000331' );
next_request();
ebay_has( '331000000331', coin_item( 331, '331000000331', 7, 1 ) );
$IMP->settle_item( '331000000331' );
check( '   by the sale check too: 9 kept, then the next sale 8', array( stock( 331 ), rec( 331 ) ), array( 8, array( '331000000331', '', 1 ) ) );

// M1: with no buffer, nothing marked before the call.
buffer_shop();
coin( 324, 10, '324000000324', 10, 0, 0 );
hand_edit( 324, 9 );
$GLOBALS['ON_CALL']['ReviseInventoryStatus'] = function () { ebay_has( '324000000324', coin_item( 324, '324000000324', 9, 0 ) ); };
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout, $timeout );
push_now();
check( 'M1 neither set, every answer lost: never marked - the call sends the stock itself', marked( 324 ), null );

// L2: an ended listing's settle writes the record with the stock, armed.
buffer_shop( 2 );
coin( 146, 10, '146000000146', 8, 0, 0 );
$GLOBALS['META'][146][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '146000000146', 'at' => time() );
add_action( 'woocommerce_updated_product_stock', function () {
	if ( ! empty( $GLOBALS['DIE_ON_STOCK'] ) ) {
		$GLOBALS['DIE_ON_STOCK'] = false;
		throw new RuntimeException( 'a listener that dies' );
	}
}, 10 );
$GLOBALS['DIE_ON_STOCK'] = true;
$died = false;
try {
	TCGiant_Sync_Inventory::apply_ended_listing_stock( 146, 8, 3, ended( '146000000146', 8, 3 ) );
} catch ( RuntimeException $e ) {
	$died = true;
}
check( 'L2 an ended listing that sold 3, a listener dying after the stock is written: 7, the record of the 3 written with it', array( $died, stock( 146 ), rec( 146 ) ), array( true, 7, array( '146000000146', '', 3 ) ) );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 146, 8, 3, ended( '146000000146', 8, 3 ) );
check( '   settled again: still 7 - the 3 not taken twice', stock( 146 ), 7 );

// L5: the relist warning under the mark says nothing of eBay's figure.
$WARN_RELIST = new ReflectionMethod( 'TCGiant_Sync_Mapper', 'warn_about_relist_stock' );
$WARN_RELIST->setAccessible( true );
$RELIST_FIGURES = new ReflectionProperty( 'TCGiant_Sync_Mapper', 'relist_parent_figures' );
$RELIST_FIGURES->setAccessible( true );
buffer_shop( 2 );
product( 347, 5, '347000000347', array( '_name' => 'Coin 347' ) );
$GLOBALS['META'][347][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '347000000347', 'at' => time() );
$RELIST_FIGURES->setValue( $MAPPER, array( 'listed' => 10, 'sold' => 0 ) );
$GLOBALS['LOG'] = array();
$WARN_RELIST->invoke( $MAPPER, 347, '347000000347', '247000000347', 8, 5, 0 );
check( 'L5 a relist followed under the mark: no "eBay\'s figure applies"', count_logged( 'eBay\'s figure applies' ), 0 );
$RELIST_FIGURES->setValue( $MAPPER, null );

// L3: the weekly check takes an auction as one item.
buffer_shop( 2 );
product( 345, 1, '345000000345', array( '_name' => 'Coin 345', '_ebay_listing_type' => 'Chinese' ) );
$GLOBALS['LOG'] = array();
$RECONCILE->invoke( null, array( listing( '345000000345', 1, 0, array( 'Title' => 'Coin 345', 'ListingType' => 'Chinese' ) ) ) );
check( 'L3 an auction of 1, Keep back 2: the weekly check sees no mismatch', count_logged( 'Reconciliation mismatch' ), 0 );

// L6: a figure too long is refused, and said.
buffer_shop( '3' );
$GLOBALS['LOG'] = array();
$saved = TCGiant_Sync_Admin::instance()->sanitize_settings( array( '_tcg_form' => 'import', 'stock_keep_back' => '1234567890', 'stock_show_at_most' => '' ) );
check( 'L6 Keep back typed as 1234567890: not saved, stays 3, and said', array( $saved['stock_keep_back'] ?? null, logged( 'Settings: "Keep back from eBay" was not saved: "1234567890" is more than 999999999, the most it takes. It stays as it was.' ) ), array( '3', true ) );
$saved = TCGiant_Sync_Admin::instance()->sanitize_settings( array( '_tcg_form' => 'import', 'stock_keep_back' => ' 007 ', 'stock_show_at_most' => '0' ) );
check( '   typed as " 007 " and "0": saved as the whole numbers 7 and 0', array( $saved['stock_keep_back'] ?? null, $saved['stock_show_at_most'] ?? null ), array( '7', '0' ) );

echo "\nFIX ROUND 2 (REVIEW 2)\n" . str_repeat( '=', 132 ) . "\n";

// R2-H1 (probe v_h2_partial): a variation marked as removed at the buffer's
// 0, which eBay still lists, is read once and pushed as any.
buffer_shop( 2 );
variable( 480, '480000000480', array( '_sku' => 'CAP' ) );
variation( 481, 480, 'CAP-S', 5, array( 'size' => 'S' ) );
variation( 482, 480, 'CAP-M', 9, array( 'size' => 'M' ) );
seen( 481, '480000000480', 0, 'CAP-S' );
seen( 482, '480000000480', 0, 'CAP-M' );
foreach ( array( 481, 482 ) as $v ) {
	$GLOBALS['META'][ $v ][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '480000000480', 'at' => time() );
}
$cap2      = function ( array $evs ) { return variation_listing( '480000000480', $evs ) + array( 'Title' => 'Cap', 'SKU' => 'CAP' ); };
$cap2_both = $cap2( array( ev( 'CAP-S', 3, 0, array( 'size' => 'S' ) ), ev( 'CAP-M', 7, 0, array( 'size' => 'M' ) ) ) );
import_listing( $cap2( array( ev( 'CAP-M', 7, 0, array( 'size' => 'M' ) ) ) ) );
$z = meta( 481, TCGiant_Sync_Mapper::ZEROED_META );
check( 'R2-H1 (probe v_h2_partial) an answer leaving S out, eBay still listing it: S 5, published, marked as removed at the buffer\'s 0', array( stock( 481 ), get_post_status( 481 ), $z['buffer'] ?? null ), array( 5, 'publish', 1 ) );
next_request();
ebay_has( '480000000480', $cap2_both, $cap2_both, $cap2_both );
website_order( 4810, array( 481 => 1 ) );
check( '   a website sale of S: eBay read once for the mark, S found - the mark goes, and the sale\'s push sends what the buffer leaves of 4', array( push_now(), revised(), stock( 481 ), meta( 481, TCGiant_Sync_Mapper::ZEROED_META ), logged( 'Stock push for WC #481: variation CAP-S is on eBay listing 480000000480, so the mark of its removal by eBay was taken off and its stock is sent.' ) ), array( array( 'GetItem', 'GetItem', 'ReviseInventoryStatus' ), array( array( 'CAP-S' => 2 ) ), 4, null, true ) );
next_request();
ebay_has( '480000000480', $cap2_both );
hand_edit( 481, 1 );
check( '   then S edited to 1: the buffer leaves 0, sent at once - no read for a mark that is gone', array( push_now(), revised() ), array( array( 'ReviseInventoryStatus' ), array( array( 'CAP-S' => 0 ) ) ) );
// The shop's Update: the variation sent some, the mark goes with it.
$GLOBALS['META'][481]['_stock'] = 5;
update_post_meta( 481, TCGiant_Sync_Mapper::ZEROED_META, array( 'item' => '480000000480', 'at' => time(), 'gone' => time(), 'said' => time(), 'buffer' => 1 ) );
TCGiant_Sync_Inventory::note_listing_sent( '480000000480', 'updated', array( array( 'holder' => 481, 'record' => 481, 'sku' => 'CAP-S', 'sent' => 3, 'stock' => 5 ) ) );
check( '   the shop presses Update, S sent 3: the mark of its removal goes', meta( 481, TCGiant_Sync_Mapper::ZEROED_META ), null );
next_request();
ebay_has( '480000000480', $cap2_both, $cap2_both );
website_order( 4811, array( 481 => 1 ) );
check( '   a website sale of S at once: sent as any sale\'s push - one read, no read for a mark', array( push_now(), revised() ), array( array( 'GetItem', 'ReviseInventoryStatus' ), array( array( 'CAP-S' => 2 ) ) ) );
update_post_meta( 481, TCGiant_Sync_Mapper::ZEROED_META, array( 'item' => '480000000480', 'at' => time(), 'gone' => time(), 'said' => time(), 'buffer' => 1 ) );
TCGiant_Sync_Inventory::note_listing_sent( '480000000480', 'updated', array( array( 'holder' => 481, 'record' => 481, 'sku' => 'CAP-S', 'sent' => 0, 'stock' => 2 ) ) );
check( '   an Update that sent S 0: the mark kept - its next push reads eBay first', is_array( meta( 481, TCGiant_Sync_Mapper::ZEROED_META ) ), true );

// R2-M1 (probe v_h3): the top-up is a raise.
buffer_shop( '', 3 );
coin( 490, 10, '490000000490', 3, 0, 0 );
$GLOBALS['META'][490][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '490000000490', 'at' => time() );
$GLOBALS['QUEUE'] = array();
ebay_has( '490000000490', coin_item( 490, '490000000490', 2, 1 ) );
$IMP->settle_item( '490000000490' );
$after_check = array_column( push_args(), 1 );
delete_transient( 'tcgiant_stock_pending_490' );
next_request();
import_listing( coin_item( 490, '490000000490', 2, 1 ) );
check( 'R2-M1 (probe v_h3) one eBay sale, the sale check then the import: 9, one push - an edit\'s, not a sale\'s', array( stock( 490 ), $after_check, count( pushes() ), pushes()[0]['args'][0]['ebay_sale'] ?? null ), array( 9, array( 'edit' ), 1, null ) );
buffer_shop( '', 3 );
fleet_used( 0.85 );
coin( 491, 10, '491000000491', 3, 0, 0 );
$GLOBALS['META'][491][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '491000000491', 'at' => time() );
$GLOBALS['QUEUE'] = array();
ebay_has( '491000000491', coin_item( 491, '491000000491', 2, 1 ) );
$IMP->settle_item( '491000000491' );
check( '   the fleet at 85%: the top-up waits below the ceiling, as a raise by hand does', push_now(), array() );
fleet_used( 0 );
buffer_shop( '', 3 );
coin( 492, 10, '492000000492', 3, 0, 0 );
$GLOBALS['META'][492][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '492000000492', 'at' => time() );
$GLOBALS['QUEUE'] = array();
ebay_has( '492000000492', coin_item( 492, '492000000492', 2, 1 ) );
$IMP->settle_item( '492000000492' );
delete_transient( 'tcgiant_stock_pending_492' );
next_request();
ebay_has( '492000000492', coin_item( 492, '492000000492', 1, 2 ) );
$IMP->settle_item( '492000000492' );
$two_sales = array( stock( 492 ), count( pushes() ) );
$GLOBALS['WIRE'] = array();
run_queue( true );
check( '   two eBay sales read a minute apart before the queue runs: 8, one push - the second waits for the first - and one call', array( $two_sales, calls() ), array( array( 8, 1 ), array( 'ReviseInventoryStatus' ) ) );

// R2-M2 (probe v_m2_m4): a variation keeping its own stock, marked, with no
// record of the old listing, followed to a relist.
buffer_shop( 2 );
variable( 495, '495000000495', array( '_sku' => 'BAG2' ) );
variation( 496, 495, 'BAG2-S', 10, array( 'size' => 'S' ) );
update_post_meta( 496, '_ebay_variation_sku', 'BAG2-S' );
$GLOBALS['META'][496][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '495000000495', 'at' => time() );
TCGiant_Sync_Inventory::follow_relist( 495, '495000000495', '595000000495', array( 'status' => 'Active', 'listing_type' => 'FixedPriceItem' ), 'Delta sync' );
check( 'R2-M2 (probe v_m2_m4) followed to the relist: S marked, its record of the relist at 0 by its eBay SKU', array( marked( 496 ), rec( 496 ) ), array( '595000000495', array( '595000000495', 'BAG2-S', 0 ) ) );
ebay_has( '595000000495', variation_listing( '595000000495', array( ev( 'BAG2-S', 8, 1, array( 'size' => 'S' ) ) ) ) + array( 'Title' => 'Bag', 'SKU' => 'BAG2' ) );
$IMP->settle_item( '595000000495' );
check( '   the relist sells 1 S; its sale check: 9', array( stock( 496 ), rec( 496 ) ), array( 9, array( '595000000495', 'BAG2-S', 1 ) ) );

// R2-L1: note_listing_sent() says how many record pushes it queued.
buffer_shop( 2 );
$GLOBALS['QUEUE'] = array();
$m4 = array();
foreach ( array( 497, 498 ) as $id ) {
	product( $id, 10, '49' . $id . '0000' . $id );
	$GLOBALS['META'][ $id ][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '49' . $id . '0000' . $id, 'at' => time() );
	$m4[] = TCGiant_Sync_Inventory::note_listing_sent( '49' . $id . '0000' . $id, 'updated', array( array( 'holder' => $id, 'record' => $id, 'sku' => '', 'sent' => 8, 'stock' => 10 ) ) );
}
check( 'R2-L1 two Updates of listings with no record: each queues one push and says so to its caller', array( $m4, count( pushes() ) ), array( array( 1, 1 ), 2 ) );

// R2-L2: a stock WooCommerce does not manage is never marked.
buffer_shop( 2 );
product( 499, 10, '499000000499', array( '_manage_stock' => 'no' ) );
TCGiant_Sync_Inventory::note_landed( 499, '499000000499', 8, 10 );
check( 'R2-L2 stock management off, eBay sent 8 of a stored 10: never marked', marked( 499 ), null );

echo "\nFIX ROUND 3 (REVIEW 3)\n" . str_repeat( '=', 132 ) . "\n";

// R3-M1 (probe r3_m1 Q2): a website sale's push on its way is no reason to
// skip the top-up - it only lowers eBay.
buffer_shop( '', 5 );
coin( 440, 40, '440000000440', 5, 0, 0 );
$GLOBALS['META'][440][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '440000000440', 'at' => time() );
$GLOBALS['QUEUE']      = array();
$GLOBALS['TRANSIENTS'] = array();
website_order( 4400, array( 440 => 1 ) );
ebay_has( '440000000440', coin_item( 440, '440000000440', 4, 1 ), coin_item( 440, '440000000440', 4, 1 ), coin_item( 440, '440000000440', 4, 1 ) );
$IMP->settle_item( '440000000440' );
$q2 = array( stock( 440 ), array_column( push_args(), 1 ) );
$GLOBALS['WIRE'] = array();
run_queue( true );
check( 'R3-M1 (probe r3_m1 Q2) cap 5, stock 40: a website sale, then an eBay sale read in that minute - 38, the top-up queued beside the sale\'s push; eBay ends at 5, not 4', array( $q2, calls(), qtys() ), array( array( 38, array( 'order', 'edit' ) ), array( 'GetItem', 'ReviseInventoryStatus' ), array( 5 ) ) );
// The drain: a cap of 3, the sale's push tried again all the while.
buffer_shop( '', 3 );
coin( 446, 40, '446000000446', 3, 0, 0 );
$GLOBALS['META'][446][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '446000000446', 'at' => time() );
$GLOBALS['QUEUE']      = array();
$GLOBALS['TRANSIENTS'] = array();
website_order( 4460, array( 446 => 1 ) );
$drain = array();
for ( $s = 1; $s <= 3; $s++ ) {
	next_request();
	delete_transient( 'tcgiant_stock_pending_446' );
	ebay_has( '446000000446', coin_item( 446, '446000000446', 2, $s ) );
	$IMP->settle_item( '446000000446' );
	ebay_answers( 'GetItem', $timeout, $timeout, $timeout, $timeout );
	$drain[] = array( push_now(), qtys() );
}
check( '   the drain: cap 3, three eBay sales read while the website sale\'s push is tried again - each topped up to 3, eBay never sent 0, the stock 36', array( $drain, stock( 446 ), in_array( 'EndItem', array_merge( ...array_column( $drain, 0 ) ), true ) ), array( array( array( array( 'GetItem', 'ReviseInventoryStatus' ), array( 3 ) ), array( array( 'ReviseInventoryStatus' ), array( 3 ) ), array( array( 'ReviseInventoryStatus' ), array( 3 ) ) ), 36, false ) );
// A stock edit's push on its way still holds the top-up back: it sends the figure itself.
buffer_shop( '', 3 );
coin( 447, 10, '447000000447', 3, 0, 0 );
$GLOBALS['META'][447][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '447000000447', 'at' => time() );
$GLOBALS['QUEUE']      = array();
$GLOBALS['TRANSIENTS'] = array();
hand_edit( 447, 12 );
delete_transient( 'tcgiant_stock_pending_447' );
next_request();
ebay_has( '447000000447', coin_item( 447, '447000000447', 2, 1 ) );
$IMP->settle_item( '447000000447' );
check( '   a stock edit\'s push queued, then an eBay sale read: 11, no top-up beside it - the edit\'s push sends the figure', array( stock( 447 ), array_column( push_args(), 1 ) ), array( 11, array( 'edit' ) ) );

echo "\nTHE WORDS\n" . str_repeat( '=', 132 ) . "\n";

$settings_view = (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/settings.php' );
$buffer_src    = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-stock-buffer.php' );
check( 'the settings: both fields with the stock-push settings, each with a hint; the out-of-stock words; how to apply it now', array( false !== strpos( $settings_view, "esc_html_e( 'Keep back from eBay', 'tcgiant-sync' )" ), false !== strpos( $settings_view, "esc_html_e( 'Show at most on eBay', 'tcgiant-sync' )" ), false !== strpos( $settings_view, 'If this leaves 0 for eBay, the listing is treated as out of stock' ), false !== strpos( $settings_view, 'To apply it now, use Send stock to eBay (Bulk actions)' ) ), array( true, true, true, true ) );
check( 'the tab: the same words', array( false !== strpos( $buffer_src, 'If this leaves 0 for eBay, the listing is treated as out of stock' ), false !== strpos( $buffer_src, '0: none for this product.' ) ), array( true, true ) );
$readme    = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
$changelog = (string) file_get_contents( dirname( __DIR__ ) . '/changelog.txt' );
check( 'the readme and the changelog have 3.45.0, dated or not', array( 1 === preg_match( '/= 3\.45\.0 - (YYYY-MM-DD|\d{4}-\d{2}-\d{2}) =/', $readme ), 1 === preg_match( '/(YYYY-MM-DD|\d{4}-\d{2}-\d{2}) - version 3\.45\.0/', $changelog ) ), array( true, true ) );

done_or_exit();
function done_or_exit() {
	global $pass, $fail;
	echo "\n  {$pass} passed, {$fail} failed\n";
	exit( $fail ? 1 : 0 );
}
