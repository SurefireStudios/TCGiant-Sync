<?php
/**
 * Harness: the shared-stock push and a disabled variation (3.43.0).
 *
 * A variable product whose variations share the stock it keeps sends that
 * one figure to each of the listing's variations taking it (push_pool()).
 * Until 3.43.0 it sent it to a variation the shop had disabled too
 * (WooCommerce's disabled variation is private; the exporter lists published
 * ones only): TCGiant_Sync_Variations::match() pairs it, so eBay went on
 * selling a variation the website no longer offers, at the shared figure.
 * Now such a variation is sent 0 while eBay shows it with stock, once, then
 * left out - never ending a listing whose enabled variations still have
 * stock, and kept no higher than the shared stock when it is the listing's
 * only stock. The import leaves it disabled. "Send stock to eBay" (3.40.0)
 * does what a stock change of the parent does.
 *
 * The world is tests/test-stock-push.php's, taken as it stands up to its
 * first case, as tests/test-send-stock.php takes it: WordPress's own WP_Hook,
 * WooCommerce's stock functions and deprecated-hook shim, Action Scheduler,
 * eBay at the HTTP call (GetItem answered from a script, in turn), and the
 * real inventory, variations, mapper and importer classes.
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

/**
 * The shop: a polo shirt keeping 5 for its three sizes, which share it; L
 * disabled (private) unless said.
 */
function polo_shop( $pool = 5, $l_status = 'private' ) {
	fresh_shop();
	variable( 7100, '710000007100', array( '_manage_stock' => 'yes', '_stock' => $pool, '_sku' => 'PO' ) );
	variation( 7101, 7100, 'PO-S', null, array( 'size' => 'S' ) );
	variation( 7102, 7100, 'PO-M', null, array( 'size' => 'M' ) );
	variation( 7103, 7100, 'PO-L', null, array( 'size' => 'L' ) );
	$GLOBALS['POSTS'][7103]['status'] = $l_status;
}
/** eBay's listing: what each size has available, and has sold (s, m, l); a size given as null is not on it. */
function polo( $s, $m, $l, array $sold = array() ) {
	$sold = $sold + array( 's' => 0, 'm' => 0, 'l' => 0 );
	$v    = array();
	foreach ( array( 's' => $s, 'm' => $m, 'l' => $l ) as $k => $avail ) {
		if ( null !== $avail ) {
			$v[] = ev( 'PO-' . strtoupper( $k ), $avail + $sold[ $k ], $sold[ $k ], array( 'size' => strtoupper( $k ) ) );
		}
	}
	return variation_listing( '710000007100', $v ) + array( 'Title' => 'Polo', 'SKU' => 'PO' );
}
function rec( $id ) { $r = meta( $id, '_ebay_sold_seen' ); return is_array( $r ) ? array( $r['sku'] ?? null, $r['sold'] ?? null ) : null; }
function revises() { return array_map( 'statuses', all_sent( 'ReviseInventoryStatus' ) ); }
function status_of( $id ) { return $GLOBALS['POSTS'][ $id ]['status'] ?? null; }
$ZERO_LINE = 'Stock push for WC #7100: disabled variation(s) #7103 (PO-L) of eBay listing 710000007100 sent 0, not the shared stock: they are not for sale on your website, so not on eBay either. While they are disabled the shared stock is not sent to them; enabled again, the next stock edit, or Send stock to eBay, sends it.';

echo "\nA DISABLED VARIATION STILL ON EBAY: 0 ONCE, THEN LEFT OUT\n" . str_repeat( '=', 132 ) . "\n";

// P1: the shared stock edited 5 to 4; eBay still shows L with the old 5.
polo_shop( 5 );
ebay_has( '710000007100', polo( 5, 5, 5 ), polo( 4, 4, 0 ) );
hand_edit( 7100, 4 );
run_queue();
check( 'P1 a stock edit, L disabled and still on eBay at 5: S and M sent 4, L sent 0 - before 3.43.0 L was sent 4', array( calls(), revises() ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), array( array( 'PO-S' => 4, 'PO-M' => 4, 'PO-L' => 0 ) ) ) );
check( '  the log: once, which disabled variation was sent 0, and the shared stock\'s line counting S and M', array( count_logged( 'sent 0, not the shared stock' ), logged( $ZERO_LINE ), logged( 'Stock push for WC #7100: the shared stock, 4, sent to 2 variation(s) of eBay listing 710000007100.', 'success' ) ), array( 1, true, true ) );
check( '  L\'s record of eBay\'s sales made before its 0 (it had none); S and M recorded as they landed; the shared stock 4; the listing live', array( rec( 7103 ), rec( 7101 ), rec( 7102 ), stock( 7100 ), meta( 7100, '_ebay_listing_status' ), status_of( 7103 ) ), array( array( 'PO-L', 0 ), array( 'PO-S', 0 ), array( 'PO-M', 0 ), 4, 'Active', 'private' ) );

// P2: the next change - L shows 0 now, and is left out.
next_request();
$WIRE = array();
ebay_has( '710000007100', polo( 4, 4, 0 ), polo( 3, 3, 0 ) );
hand_edit( 7100, 3 );
run_queue();
check( 'P2 the next stock edit: L at 0 on eBay is left out - only S and M, 3', array( calls(), revises(), count_logged( 'sent 0, not the shared stock' ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), array( array( 'PO-S' => 3, 'PO-M' => 3 ) ), 1 ) );

// P3: a website sale of S; L at 0 with its record: no sale of the shared stock.
next_request();
$WIRE = array();
ebay_has( '710000007100', polo( 3, 3, 0 ), polo( 2, 2, 0 ) );
website_order( 7150, array( 7101 => 1 ) );
run_queue();
check( 'P3 a website sale of S: the shared stock 3 to 2, S and M sent 2, L left out', array( stock( 7100 ), revises() ), array( 2, array( array( 'PO-S' => 2, 'PO-M' => 2 ) ) ) );

// P4: L had sold 2 on eBay, all in the shared stock, with no record of it:
// its record from before its 0 is what stops its 0 being read as a sale.
polo_shop( 5 );
ebay_has( '710000007100', polo( 5, 5, 5, array( 'l' => 2 ) ), polo( 4, 4, 0, array( 'l' => 2 ) ) );
hand_edit( 7100, 4 );
run_queue();
check( 'P4 L, with 2 sold on eBay and no record, sent 0: its record made at its 2 first', array( revises(), rec( 7103 ) ), array( array( array( 'PO-S' => 4, 'PO-M' => 4, 'PO-L' => 0 ) ), array( 'PO-L', 2 ) ) );
next_request();
$WIRE = array();
ebay_has( '710000007100', polo( 4, 4, 0, array( 'l' => 2 ) ), polo( 3, 3, 0, array( 'l' => 2 ) ) );
website_order( 7151, array( 7102 => 1 ) );
run_queue();
check( '  then a website sale: 4 to 3, the sale only - read with no record, L\'s 0 against the 4 before the sale would have taken 2 more (3.28.0\'s rule for a variation eBay sold out)', array( stock( 7100 ), revises() ), array( 3, array( array( 'PO-S' => 3, 'PO-M' => 3 ) ) ) );
next_request();
ebay_has( '710000007100', polo( 3, 3, 0, array( 'l' => 2 ) ) );
$IMP->settle_item( '710000007100' );
check( '  and order sync\'s settle reads L at 0 as no sale: 3', stock( 7100 ), 3 );

echo "\nAN EBAY SALE AND A WEBSITE SALE WITH A DISABLED VARIATION IN THE POOL\n" . str_repeat( '=', 132 ) . "\n";

// P5: eBay sells an S; L at 0 with its record.
polo_shop( 3 );
seen( 7101, '710000007100', 0, 'PO-S' );
seen( 7102, '710000007100', 0, 'PO-M' );
seen( 7103, '710000007100', 2, 'PO-L' );
ebay_has( '710000007100', polo( 2, 3, 0, array( 's' => 1, 'l' => 2 ) ), polo( 2, 3, 0, array( 's' => 1, 'l' => 2 ) ), polo( 2, 2, 0, array( 's' => 1, 'l' => 2 ) ) );
$IMP->settle_item( '710000007100' );
check( 'P5 eBay sells an S: the shared stock 3 to 2 - L\'s 0 is no sale', stock( 7100 ), 2 );
$WIRE = array();
run_queue();
check( '  the push after it: M sent 2 (S already shows it), L left out', array( calls(), revises() ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), array( array( 'PO-M' => 2 ) ) ) );

// P6: eBay sold an L while it was disabled but still on sale there: that
// sale comes off the shared stock, once; then L is sent 0.
polo_shop( 5 );
seen( 7101, '710000007100', 0, 'PO-S' );
seen( 7102, '710000007100', 0, 'PO-M' );
seen( 7103, '710000007100', 0, 'PO-L' );
ebay_has( '710000007100', polo( 5, 5, 4, array( 'l' => 1 ) ), polo( 5, 5, 4, array( 'l' => 1 ) ), polo( 4, 4, 0, array( 'l' => 1 ) ) );
$IMP->settle_item( '710000007100' );
check( 'P6 eBay sold an L before its 0: off the shared stock, 5 to 4, and L\'s record moves on', array( stock( 7100 ), rec( 7103 ) ), array( 4, array( 'PO-L', 1 ) ) );
$WIRE = array();
run_queue();
check( '  the push after it: S and M 4, L 0', revises(), array( array( 'PO-S' => 4, 'PO-M' => 4, 'PO-L' => 0 ) ) );

echo "\nENABLED AGAIN\n" . str_repeat( '=', 132 ) . "\n";

polo_shop( 3 );
seen( 7101, '710000007100', 0, 'PO-S' );
seen( 7102, '710000007100', 0, 'PO-M' );
seen( 7103, '710000007100', 0, 'PO-L' );
$POSTS[7103]['status'] = 'publish';
ebay_has( '710000007100', polo( 3, 3, 0 ), polo( 2, 2, 2 ) );
hand_edit( 7100, 2 );
run_queue();
check( 'L enabled again: the next stock edit sends it the shared stock, as before 3.43.0', array( revises(), count_logged( 'sent 0, not the shared stock' ) ), array( array( array( 'PO-S' => 2, 'PO-M' => 2, 'PO-L' => 2 ) ), 0 ) );
polo_shop( 3 );
seen( 7101, '710000007100', 0, 'PO-S' );
seen( 7102, '710000007100', 0, 'PO-M' );
seen( 7103, '710000007100', 0, 'PO-L' );
$POSTS[7103]['status'] = 'publish';
ebay_has( '710000007100', polo( 3, 3, 0 ), polo( 2, 2, 0 ) );
website_order( 7152, array( 7101 => 1 ) );
run_queue();
check( '  a website sale\'s push only lowers, as for any variation since 3.28.0: L stays 0 until a stock edit or Send stock to eBay', revises(), array( array( 'PO-S' => 2, 'PO-M' => 2 ) ) );

echo "\nNEVER THE END OF A LISTING WHOSE ENABLED VARIATIONS HAVE STOCK\n" . str_repeat( '=', 132 ) . "\n";

// P8: eBay has only L (S and M were removed at 0): L is the listing's only
// stock. Not set to 0: kept no higher than the shared stock, never raised.
polo_shop( 5 );
ebay_has( '710000007100', polo( null, null, 3 ) );
hand_edit( 7100, 4 );
run_queue();
check( 'P8 L the listing\'s only variation with stock (eBay 3, the shared stock 4): not set to 0, not raised - nothing sent; not ended', array( calls(), meta( 7100, '_ebay_listing_status' ) ), array( array( 'GetItem' ), 'Active' ) );
check( '  said, with what to do', logged( 'Stock push for WC #7100: disabled variation(s) #7103 (PO-L) are the only variations of eBay listing 710000007100 with stock, so they were not set to 0 - that would leave the listing nothing to sell, which eBay refuses without Out-of-Stock control. They are kept no higher than the shared stock, 4. To stop them selling on eBay, end the listing in Seller Hub, or enable another variation.', 'warning' ), true );
polo_shop( 5 );
ebay_has( '710000007100', polo( null, null, 6 ), polo( null, null, 4 ) );
hand_edit( 7100, 4 );
run_queue();
check( '  eBay showing more of it than the shared stock: lowered to it, 4 - never more than the pool', revises(), array( array( 'PO-L' => 4 ) ) );
// S and M on eBay at 0, a website sale (its push only lowers): the same.
polo_shop( 5 );
ebay_has( '710000007100', polo( 0, 0, 5 ), polo( 0, 0, 4 ) );
website_order( 7153, array( 7101 => 1 ) );
run_queue();
check( '  S and M at 0 on eBay and a website sale: L the only stock, lowered to the shared 4', revises(), array( array( 'PO-L' => 4 ) ) );

// S and M at 0 on eBay, the shared stock edited up (an edit's push sends
// the figure as it is): S and M raised, so L is not the only stock - 0.
polo_shop( 2 );
ebay_has( '710000007100', polo( 0, 0, 2 ), polo( 3, 3, 0 ) );
hand_edit( 7100, 3 );
run_queue();
check( '  S and M at 0 on eBay, the shared stock edited up to 3: S and M raised to 3, so L, not the only stock now, sent 0', revises(), array( array( 'PO-S' => 3, 'PO-M' => 3, 'PO-L' => 0 ) ) );

// An enabled S on eBay with no SKU (the shared stock cannot be sent to it),
// at 0 there: it keeps nothing on sale, so L is the only stock.
polo_shop( 5 );
ebay_has( '710000007100', variation_listing( '710000007100', array( ev( '', 0, 0, array( 'size' => 'S' ) ), ev( 'PO-L', 5, 0, array( 'size' => 'L' ) ) ) ), variation_listing( '710000007100', array( ev( '', 0, 0, array( 'size' => 'S' ) ), ev( 'PO-L', 4, 0, array( 'size' => 'L' ) ) ) ) );
hand_edit( 7100, 4 );
run_queue();
check( '  an enabled S with no SKU on eBay, at 0 there: not counted as keeping stock - L the only stock, lowered to 4, not 0', array( revises(), logged( 'have no SKU, so the shared stock was not sent to them' ) ), array( array( array( 'PO-L' => 4 ) ), true ) );

// P9: eBay refuses L's 0 (any refusal): tried again, never ended.
polo_shop( 5 );
ebay_has( '710000007100', polo( 5, 5, 5 ), polo( 5, 5, 5 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
hand_edit( 7100, 4 );
run_queue();
check( 'P9 eBay refusing the call with L\'s 0: not ended - tried again', array( in_array( 'EndItem', calls(), true ), meta( 7100, '_ebay_listing_status' ), count( pushes() ), logged( 'Trying again' ) ), array( false, 'Active', 1, true ) );

// P10: the shared stock runs out: every variation to 0, L too, as before.
polo_shop( 1 );
ebay_has( '710000007100', polo( 1, 0, 1 ), polo( 0, 0, 0 ) );
hand_edit( 7100, 0 );
run_queue();
check( 'P10 the shared stock to 0 (Out-of-Stock control on): S and L sent 0 as before 3.43.0, no line on the disabled one', array( revises(), count_logged( 'disabled variation' ), logged( 'the shared stock, 0, sent to 2 variation(s)' ) ), array( array( array( 'PO-S' => 0, 'PO-L' => 0 ) ), 0, true ) );
polo_shop( 1 );
ebay_has( '710000007100', polo( 1, 0, 1 ), polo( 0, 0, 1 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
hand_edit( 7100, 0 );
run_queue();
check( '  Out-of-Stock control off, eBay keeping L, the last with stock: the listing ended, as before - the shared stock has run out', array( calls(), meta( 7100, '_ebay_listing_status' ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), 'Ended' ) );

// P12: a variation keeping its own stock keeps the listing selling: L 0.
fresh_shop();
variable( 7200, '720000007200', array( '_manage_stock' => 'yes', '_stock' => 5 ) );
variation( 7201, 7200, 'OW-A', 2, array( 'size' => 'S' ), array( '_ebay_variation_sku' => 'OW-A' ) );
variation( 7202, 7200, 'OW-B', null, array( 'size' => 'M' ) );
$POSTS[7202]['status'] = 'private';
$tcg_ow = function ( $a, $b ) { return variation_listing( '720000007200', array( ev( 'OW-A', $a, 0, array( 'size' => 'S' ) ), ev( 'OW-B', $b, 0, array( 'size' => 'M' ) ) ) ); };
ebay_has( '720000007200', $tcg_ow( 2, 5 ), $tcg_ow( 2, 0 ) );
hand_edit( 7200, 4 );
run_queue();
check( 'P12 a variation keeping its own stock (2 on eBay) keeps the listing selling: the disabled one taking the shared stock sent 0', array( revises(), logged( 'Stock push for WC #7200: eBay listing 720000007200 already showed the shared stock, 4, for each enabled variation.', 'success' ) ), array( array( array( 'OW-B' => 0 ) ), true ) );
// That variation then sells out on the website: eBay will not take 0 for
// the last with stock (Out-of-Stock control off), and no enabled variation
// has stock here - the listing ends, as since 3.28.0.
next_request();
$WIRE = array();
ebay_has( '720000007200', $tcg_ow( 2, 0 ), $tcg_ow( 2, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21916799, 'You cannot set the quantity of the last variation with stock to 0.' ) );
website_order( 7250, array( 7201 => 2 ) );
run_queue( true );
check( '  then it sells out here, eBay will not take its 0, and no enabled variation has stock: ended (the disabled one cannot keep it alive)', array( in_array( 'EndItem', calls(), true ), meta( 7200, '_ebay_listing_status' ) ), array( true, 'Ended' ) );

echo "\nSEND STOCK TO EBAY (3.40.0): AS A STOCK CHANGE OF THE PARENT\n" . str_repeat( '=', 132 ) . "\n";

polo_shop( 4 );
ebay_has( '710000007100', polo( 5, 5, 5 ), polo( 4, 4, 0 ) );
$tcg_said = TCGiant_Sync_Inventory::send_stock_to_ebay( array( 7100 ) );
check( 'Send stock to eBay on the polo: the shared stock\'s push queued, L named as the push\'s to set to 0, nothing said skipped', array( count( pushes() ), $tcg_said['pool']['ids'], $tcg_said['off_pool']['ids'], $tcg_said['not_linked']['n'], TCGiant_Sync_Inventory::send_stock_queued( $tcg_said ), TCGiant_Sync_Inventory::send_stock_skipped( $tcg_said ) ), array( 1, array( 7100 ), array( 7103 ), 0, 1, 0 ) );
run_queue();
check( '  run: S and M 4, L 0', revises(), array( array( 'PO-S' => 4, 'PO-M' => 4, 'PO-L' => 0 ) ) );
TCGiant_Sync_Inventory::log_send_stock( $tcg_said, 'products list', 1 );
check( '  its log line', logged( 'Send stock to eBay (products list, user #1): 1 queued (shared stock (GetItem, ReviseInventoryStatus, GetItem) 1: #7100; disabled, sent 0 by the shared stock\'s push unless the listing\'s only stock 1: #7103); skipped 0.' ), true );
check( '  its notice line, not a warning', array( TCGiant_Sync_Inventory::send_stock_lines( $tcg_said )[2] ?? null, TCGiant_Sync_Inventory::send_stock_warns( $tcg_said ) ), array( '1 disabled variation taking a shared stock: the shared stock\'s push sets it to 0 on eBay if eBay still shows it with stock, unless it is the listing\'s last variation with stock, when the log says so: #7103.', false ) );
// The shared stock's push not queued this time (one already on its way):
// L is not named - that push sends what it sends.
polo_shop( 4 );
set_transient( 'tcgiant_stock_pending_7100', 1, 60 );
$tcg_said = TCGiant_Sync_Inventory::send_stock_to_ebay( array( 7100 ) );
check( '  a push of the shared stock already on its way: L not named, the parent said as on its way', array( $tcg_said['off_pool']['n'], $tcg_said['on_way']['ids'] ), array( 0, array( 7100 ) ) );
// A disabled variation keeping its own stock: as 3.40.0, not on eBay.
fresh_shop();
variable( 7300, '730000007300' );
variation( 7301, 7300, 'KO-A', 2, array( 'size' => 'S' ), array( '_ebay_variation_sku' => 'KO-A' ) );
variation( 7302, 7300, 'KO-B', 3, array( 'size' => 'M' ), array( '_ebay_variation_sku' => 'KO-B' ) );
$POSTS[7302]['status'] = 'private';
$tcg_said = TCGiant_Sync_Inventory::send_stock_to_ebay( array( 7300 ) );
check( '  a disabled variation keeping its own stock: as 3.40.0 - named as not on eBay, not sent', array( array_column( array_map( function ( $a ) { return $a['args'][0]; }, pushes() ), 'product_id' ), $tcg_said['not_linked']['ids'], $tcg_said['off_pool']['n'] ), array( array( 7301 ), array( 7302 ), 0 ) );
check( '  merged batches keep the new list (the Listings page\'s job)', TCGiant_Sync_Inventory::merge_send_stock( null, TCGiant_Sync_Inventory::send_stock_said() )['off_pool'], array( 'n' => 0, 'ids' => array() ) );

echo "\nTHE IMPORT AFTERWARDS: L STAYS DISABLED, AND ITS 0 IS LEFT ALONE\n" . str_repeat( '=', 132 ) . "\n";

// I1: after P1 (L sent 0, its record made), the import of the listing.
polo_shop( 5 );
ebay_has( '710000007100', polo( 5, 5, 5 ), polo( 4, 4, 0 ) );
hand_edit( 7100, 4 );
run_queue();
next_request();
$WIRE = array();
import_listing( polo( 4, 4, 0 ) );
check( 'I1 the import after L\'s 0: L still disabled, the shared stock 4, no push queued, L\'s record as it was', array( status_of( 7103 ), stock( 7100 ), count( pushes() ), rec( 7103 ), calls() ), array( 'private', 4, 0, array( 'PO-L', 0 ), array() ) );
// I2: L disabled, eBay still showing it with stock, no push yet.
polo_shop( 5 );
import_listing( polo( 5, 5, 5 ) );
check( 'I2 the import of a listing still showing a disabled L with stock: L left disabled (until 3.43.0 the import published it again)', array( status_of( 7103 ), status_of( 7101 ), stock( 7100 ) ), array( 'private', 'publish', 5 ) );
// I3: a variation the import retired (eBay dropped it), back on eBay.
polo_shop( 5, 'publish' );
import_listing( polo( 5, 5, null ) );
check( 'I3 eBay drops L: the import retires it, as before (private, out of stock), and marks it as its own doing', array( status_of( 7103 ), meta( 7103, '_stock_status' ), null !== meta( 7103, TCGiant_Sync_Mapper::RETIRED_META ) ), array( 'private', 'outofstock', true ) );
import_listing( polo( 5, 5, 5 ) );
check( '  eBay lists L again: published again, as before - the mark taken off', array( status_of( 7103 ), meta( 7103, TCGiant_Sync_Mapper::RETIRED_META ) ), array( 'publish', null ) );
// I4: enabled variations stay enabled; a variation keeping its own stock,
// disabled, is published again as before (3.32.0's rule, kept).
polo_shop( 5, 'publish' );
import_listing( polo( 5, 5, 5 ) );
check( 'I4 enabled variations stay enabled through an import', array( status_of( 7101 ), status_of( 7102 ), status_of( 7103 ) ), array( 'publish', 'publish', 'publish' ) );
fresh_shop();
variable( 7400, '740000007400', array( '_sku' => 'KS' ) );
variation( 7401, 7400, 'KS-S', 2, array( 'size' => 'S' ), array( '_ebay_variation_sku' => 'KS-S' ) );
variation( 7402, 7400, 'KS-M', 3, array( 'size' => 'M' ), array( '_ebay_variation_sku' => 'KS-M' ) );
$POSTS[7402]['status'] = 'private';
import_listing( variation_listing( '740000007400', array( ev( 'KS-S', 2, 0, array( 'size' => 'S' ) ), ev( 'KS-M', 3, 0, array( 'size' => 'M' ) ) ) ) + array( 'Title' => 'Kit', 'SKU' => 'KS' ) );
check( '  a disabled variation keeping its own stock: published again by the import, as before 3.43.0 (open question)', status_of( 7402 ), 'publish' );

echo "\nFIX ROUND 1 (REVIEW 1'S M3): A NEVER-SOLD DISABLED VARIATION, OUT-OF-STOCK CONTROL OFF\n" . str_repeat( '=', 132 ) . "\n";

$REMOVED_LINE = ' #7103 (PO-L) never sold on eBay: with Out-of-Stock control off, eBay removes a variation set to 0 before it has sold. To sell it again, enable it and Update the listing (push the product to eBay), which adds it back.';
$GONE_LINE    = 'Variation #7103 of WC #7100 is not on eBay listing 710000007100 any more: eBay removed it when it was set to 0 before it had ever sold (Out-of-Stock control off). It stays enabled here; Update the listing to add it back.';

// N1: L never sold; eBay's preference not known (the importer never asked).
// It is sent 0 and, the control off, eBay removes it: its read-back has no L.
polo_shop( 5 );
ebay_has( '710000007100', polo( 5, 5, 5 ), polo( 4, 4, null ) );
hand_edit( 7100, 4 );
run_queue();
check( 'N1 L never sold, sent 0 with Out-of-Stock control not known on: still 0 (never above the shared stock), marked, and the line says eBay removes it and an Update adds it back', array( revises(), meta( 7103, TCGiant_Sync_Mapper::ZEROED_META )['item'] ?? null, logged( $ZERO_LINE . $REMOVED_LINE ) ), array( array( array( 'PO-S' => 4, 'PO-M' => 4, 'PO-L' => 0 ) ), '710000007100', true ) );
// N2: the import, L gone from eBay and still disabled: left as the shop set it.
next_request();
$WIRE = array();
import_listing( polo( 4, 4, null ) );
check( '  the import, L gone from eBay and still disabled: left as the shop set it - not retired by the import, no warning yet', array( status_of( 7103 ), meta( 7103, TCGiant_Sync_Mapper::RETIRED_META ), count_logged( 'is not on eBay listing' ) ), array( 'private', null, 0 ) );
// N3: the shop enables L. No push can reach it; the import leaves it enabled.
$POSTS[7103]['status'] = 'publish';
next_request();
$WIRE = array();
ebay_has( '710000007100', polo( 4, 4, null ), polo( 3, 3, null ) );
hand_edit( 7100, 3 );
run_queue();
check( '  the shop enables L, then edits the stock: the push reaches S and M only (eBay has no L to send to)', revises(), array( array( 'PO-S' => 3, 'PO-M' => 3 ) ) );
next_request();
import_listing( polo( 3, 3, null ) );
import_listing( polo( 3, 3, null ) );
check( '  the imports after: L stays enabled - never disabled again - and it is said once: Update the listing to add it back', array( status_of( 7103 ), meta( 7103, TCGiant_Sync_Mapper::RETIRED_META ), count_logged( $GONE_LINE ), logged( $GONE_LINE, 'warning' ) ), array( 'publish', null, 1, true ) );
// N4: the shop Updates the listing; eBay lists L again: the import finds it.
import_listing( polo( 3, 3, 3 ) );
check( '  eBay lists L again (an Update added it): the import finds it, enabled, its mark gone', array( status_of( 7103 ), meta( 7103, TCGiant_Sync_Mapper::ZEROED_META ) ), array( 'publish', null ) );

// N5: L had sold on eBay: eBay keeps it at 0 - no mark, no such words.
polo_shop( 5 );
ebay_has( '710000007100', polo( 5, 5, 5, array( 'l' => 1 ) ), polo( 4, 4, 0, array( 'l' => 1 ) ) );
hand_edit( 7100, 4 );
run_queue();
check( 'N5 L had sold on eBay: sent 0, kept by eBay - no mark, the line as before', array( revises(), meta( 7103, TCGiant_Sync_Mapper::ZEROED_META ), count_logged( 'never sold on eBay' ), logged( $ZERO_LINE ) ), array( array( array( 'PO-S' => 4, 'PO-M' => 4, 'PO-L' => 0 ) ), null, 0, true ) );
// N6: eBay said its Out-of-Stock control is on: eBay keeps it at 0 too.
polo_shop( 5 );
$OPTIONS[ TCGiant_Sync_Importer::OOS_PREF_OPTION ] = array( 'pref' => 'true', 'ok' => true, 'at' => time() - 3 * DAY_IN_SECONDS );
ebay_has( '710000007100', polo( 5, 5, 5 ), polo( 4, 4, 0 ) );
hand_edit( 7100, 4 );
run_queue();
check( 'N6 Out-of-Stock control on, as the importer last heard: marked all the same (fix round 2), the line as before - no word of eBay removing it', array( null !== meta( 7103, TCGiant_Sync_Mapper::ZEROED_META ), count_logged( 'never sold on eBay' ) ), array( true, 0 ) );
polo_shop( 5 );
$OPTIONS[ TCGiant_Sync_Importer::OOS_PREF_OPTION ] = array( 'pref' => 'false', 'ok' => true, 'at' => time() );
ebay_has( '710000007100', polo( 5, 5, 5 ), polo( 4, 4, null ) );
hand_edit( 7100, 4 );
run_queue();
check( '  off, as it last heard: marked and said', array( null !== meta( 7103, TCGiant_Sync_Mapper::ZEROED_META ), count_logged( 'never sold on eBay' ) ), array( true, 1 ) );
// N7: eBay refused the call with the 0: nothing marked (eBay still has L).
polo_shop( 5 );
ebay_has( '710000007100', polo( 5, 5, 5 ), polo( 5, 5, 5 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
hand_edit( 7100, 4 );
run_queue();
check( 'N7 eBay refusing the call with the 0: not marked, not said', array( meta( 7103, TCGiant_Sync_Mapper::ZEROED_META ), count_logged( 'never sold on eBay' ) ), array( null, 0 ) );
// Review 2's probe D1: L sells 1 on eBay between the push's read (sold 0)
// and its 0 - marked from the read, though eBay keeps it (it has sold); the
// next import, eBay still listing L, takes the mark off.
polo_shop( 5 );
ebay_has( '710000007100', polo( 5, 5, 5 ), polo( 4, 4, 0, array( 'l' => 1 ) ) );
hand_edit( 7100, 4 );
run_queue();
$tcg_d1 = null !== meta( 7103, TCGiant_Sync_Mapper::ZEROED_META );
next_request();
$WIRE = array();
import_listing( polo( 4, 4, 0, array( 'l' => 1 ) ) );
check( 'D1 (fix round 2) a sale landing between the read and the 0: marked; the next import, eBay still listing L, takes the mark off and leaves L disabled', array( $tcg_d1, meta( 7103, TCGiant_Sync_Mapper::ZEROED_META ), status_of( 7103 ) ), array( true, null, 'private' ) );
// Review 2's probe D2: the cached preference says on, 200 days old, but the
// control is off now - eBay removes L. Marked whatever the preference
// says, so the shop's enabling holds through the import.
polo_shop( 5 );
$OPTIONS[ TCGiant_Sync_Importer::OOS_PREF_OPTION ] = array( 'pref' => 'true', 'ok' => true, 'at' => time() - 200 * DAY_IN_SECONDS );
ebay_has( '710000007100', polo( 5, 5, 5 ), polo( 4, 4, null ) );
hand_edit( 7100, 4 );
run_queue();
next_request();
$WIRE = array();
import_listing( polo( 4, 4, null ) );
$POSTS[7103]['status'] = 'publish';
import_listing( polo( 4, 4, null ) );
check( 'D2 (fix round 2) a stale "on" (200 days), eBay removed L, the shop enabled it: the import leaves it enabled, still marked, and says so', array( status_of( 7103 ), null !== meta( 7103, TCGiant_Sync_Mapper::ZEROED_META ), count_logged( 'is not on eBay listing 710000007100 any more' ) ), array( 'publish', true, 1 ) );
unset( $OPTIONS[ TCGiant_Sync_Importer::OOS_PREF_OPTION ] );

// N8: a variation eBay drops with no such mark is retired as before (I3).
polo_shop( 5, 'publish' );
import_listing( polo( 5, 5, null ) );
check( 'N8 an enabled variation eBay drops, never sent 0 by this push: retired by the import as before', array( status_of( 7103 ), null !== meta( 7103, TCGiant_Sync_Mapper::RETIRED_META ) ), array( 'private', true ) );

echo "\n  $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
