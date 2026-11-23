<?php
/**
 * Harness: the stock buffer (3.45.0) on a new listing, an Update and a
 * relist - every quantity TCGiant_Sync_Exporter sends eBay of a tracked
 * stock: the stock less "Keep back from eBay", no more than "Show at most on
 * eBay", never below 0; never an auction's 1, never "Quantity When Stock Is
 * Not Tracked". A product a buffer leaves nothing of for eBay is refused as
 * out of stock, and not relisted. With neither set, every body is the one
 * 3.44.0 sent.
 *
 * The world is tests/test-ebay-price.php's, taken as it stands up to its
 * first case: tests/support/template-world.php, the real exporter, and eBay
 * at its Trading calls.
 *
 * @package TCGiant_Sync
 */

$WORLD_SRC = (string) file_get_contents( __DIR__ . '/test-ebay-price.php' );
$WORLD_CUT = strpos( $WORLD_SRC, 'echo "' . chr( 92 ) . 'nTHE RULE, ITS ROUNDING' );
if ( false === $WORLD_CUT ) {
	echo "the world of tests/test-ebay-price.php is not as this harness takes it\n\n  0 passed, 1 failed\n";
	exit( 1 );
}
$WORLD_SRC = substr( $WORLD_SRC, 0, $WORLD_CUT );
$WORLD_SRC = str_replace( '__DIR__', var_export( __DIR__, true ), $WORLD_SRC );
eval( '?>' . $WORLD_SRC );
unset( $WORLD_SRC );

/** The Quantity elements of a body, in order. */
function quantities( $body ) {
	preg_match_all( '#<Quantity>(-?\d+)</Quantity>#', (string) $body, $m );
	return array_map( 'intval', $m[1] );
}
/** The first call's body. */
function first_body( array $calls ) { return (string) ( $calls[0][1] ?? '' ); }
/** The buffer's two settings, over a part shop's. */
function buffer( $keep = '', $cap = '', array $extra = array() ) {
	part_shop( array( 'stock_keep_back' => (string) $keep, 'stock_show_at_most' => (string) $cap ) + $extra );
	$GLOBALS['W']['options']['tcgiant_sync_ebay_settings'] = TCGiant_Sync_OAuth::$settings;
}
/** A body with its quantities and UUID taken out: what must not change. */
function without_quantities( $body ) { return preg_replace( array( '#<Quantity>-?\d+</Quantity>#', '#<UUID>[0-9a-fA-F]+</UUID>#' ), array( '<Quantity/>', '<UUID/>' ), (string) $body ); }
/** A push's answer, and the calls it made (test-ebay-price's). */
function push_answer( WC_Product $product ) {
	$GLOBALS['API']->calls = array();
	$res                   = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	return array( is_wp_error( $res ) ? $res->get_error_code() : 'pushed', array_column( $GLOBALS['API']->calls, 0 ), is_wp_error( $res ) ? $res->get_error_message() : '' );
}
/** An ended listing as GetItem answers it (test-ebay-price's). */
function ended_answer( $item_id, $type, array $more = array() ) {
	return array( 'Ack' => 'Success', 'Item' => array_merge( array( 'ItemID' => $item_id, 'ListingType' => $type, 'Quantity' => '2', 'StartPrice' => '12.0', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ), $more ) );
}
/** Bulk Relist: its answer, and each call, its UUID as <UUID/> (test-ebay-price's). */
function relist_now( $id, array $answer ) {
	$GLOBALS['API']->calls              = array();
	$GLOBALS['API']->answers['GetItem'] = $answer;
	$res = exporter()->relist_ended_listing( $id, 'Bulk relist' );
	unset( $GLOBALS['API']->answers['GetItem'] );
	return array( $res, array_map( function ( $call ) { return array( $call[0], preg_replace( '#<UUID>[0-9a-fA-F]+</UUID>#', '<UUID/>', (string) $call[1] ) ); }, $GLOBALS['API']->calls ) );
}
/** A relist's body as RelistFixedPriceItem sends it (test-ebay-price's). */
function relist_body( $item_id, $quantity, $price = '' ) {
	return "\n<Item>\n<ItemID>" . $item_id . "</ItemID>\n<UUID/>\n<Quantity>" . $quantity . '</Quantity>' . ( '' !== $price ? "\n<StartPrice>" . $price . '</StartPrice>' : '' ) . "\n</Item>";
}

echo "\nA NEW LISTING AND AN UPDATE\n" . str_repeat( '=', 124 ) . "\n";

foreach ( array(
	array( 'neither set', '', '', 10, 10 ),
	array( 'Keep back 2', 2, '', 10, 8 ),
	array( 'Show at most 5', '', 5, 10, 5 ),
	array( 'both, 2 and 5', 2, 5, 6, 4 ),
) as $case ) {
	list( $label, $keep, $cap, $stock, $want ) = $case;
	buffer( $keep, $cap );
	$new = push( product( 500, array( 'stock' => $stock ) ) );
	check( 'L1 ' . $label . ', stock ' . $stock . ': a new listing sends ' . $want, array( array_column( $new, 0 ), quantities( first_body( $new ) ) ), array( array( 'AddFixedPriceItem' ), array( $want ) ) );
	$bodies[ $label ] = without_quantities( first_body( $new ) );
}
check( '   each body as with neither set, but for its Quantity', array_unique( array_values( $bodies ) ), array( $bodies['neither set'] ) );
foreach ( array( array( '', '', 10 ), array( 2, '', 8 ) ) as $case ) {
	buffer( $case[0], $case[1] );
	$upd = push( product( 502, array( 'stock' => 10 ), PUSHED + array( '_ebay_item_id' => '110000000502' ) ) );
	check( 'L2 an Update, ' . ( '' === $case[0] ? 'neither set' : 'Keep back 2' ) . ': Quantity ' . $case[2], array( array_column( $upd, 0 ), quantities( first_body( $upd ) ) ), array( array( 'ReviseFixedPriceItem' ), array( $case[2] ) ) );
}
buffer( 2 );
product( 503, array( 'stock' => 10 ), array( TCGiant_Sync_Stock_Buffer::KEEP_META => '0' ) );
check( 'L3 the product\'s own 0: none for it - 10', quantities( first_body( push( wc_get_product( 503 ) ) ) ), array( 10 ) );
product( 504, array( 'stock' => 10 ), array( TCGiant_Sync_Stock_Buffer::KEEP_META => '4' ) );
check( '   its own 4: 6', quantities( first_body( push( wc_get_product( 504 ) ) ) ), array( 6 ) );

echo "\n0 FOR EBAY, AN AUCTION, NO STOCK TRACKED\n" . str_repeat( '=', 124 ) . "\n";

buffer( 2 );
list( $code, $made, $said ) = push_answer( product( 510, array( 'stock' => 2 ) ) );
check( 'Z1 stock 2, Keep back 2: refused as out of stock before eBay is asked, and why', array( $code, $made, $said ), array( 'out_of_stock', array(), 'Cannot list on eBay: its stock of 2, less "Keep back from eBay" (or no more than "Show at most on eBay"), leaves nothing for eBay. Change those on its eBay tab or in Settings, or add stock, then push again.' ) );
check( '   an Update of it too', push_answer( product( 511, array( 'stock' => 2 ), PUSHED + array( '_ebay_item_id' => '110000000511' ) ) )[0], 'out_of_stock' );
check( '   with neither set it goes', ( function () { buffer(); return push_answer( product( 512, array( 'stock' => 2 ) ) )[0]; } )(), 'pushed' );
buffer( 2 );
$auction = push( product( 513, array( 'stock' => 5 ), array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) ) );
check( 'A1 an auction: always 1', array( array_column( $auction, 0 ), quantities( first_body( $auction ) ) ), array( array( 'AddItem' ), array( 1 ) ) );
$untracked = push( product( 514, array( 'stock' => null ) ) );
check( 'U1 stock not tracked: "Quantity When Stock Is Not Tracked", untouched', quantities( first_body( $untracked ) ), array( 1 ) );

echo "\nVARIATIONS\n" . str_repeat( '=', 124 ) . "\n";

buffer( 2 );
$variable = product( 520, array( 'type' => 'variable', 'sku' => 'VAR-520', 'stock' => null ), array(), 'Price_Variable' );
$variable->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
$variable->children             = array( 5201, 5202 );
product( 5201, array( 'sku' => 'VAR-520-S', 'size' => 'Small', 'regular_price' => '10', 'stock' => 6 ), array(), 'Price_Variation' );
product( 5202, array( 'sku' => 'VAR-520-L', 'size' => 'Large', 'regular_price' => '12', 'stock' => 1 ), array(), 'Price_Variation' );
check( 'V1 two variations keeping 6 and 1, Keep back 2: 4 and 0', quantities( first_body( push( $variable ) ) ), array( 4, 0 ) );
buffer();
check( '   with neither set: 6 and 1', quantities( first_body( push( $variable ) ) ), array( 6, 1 ) );

echo "\nA RELIST\n" . str_repeat( '=', 124 ) . "\n";

$ENDED = array( '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Ended', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed' );
foreach ( array( array( '', 10 ), array( 2, 8 ) ) as $case ) {
	buffer( $case[0] );
	product( 530, array( 'stock' => 10 ), $ENDED + array( '_ebay_item_id' => '110000000530' ) );
	list( $res, $calls ) = relist_now( 530, ended_answer( '110000000530', 'FixedPriceItem' ) );
	check( 'R1 a fixed-price relist, ' . ( '' === $case[0] ? 'neither set' : 'Keep back 2' ) . ': quantity ' . $case[1], array( array_column( $calls, 0 ), $calls[1][1] ?? null ), array( array( 'GetItem', 'RelistFixedPriceItem' ), relist_body( '110000000530', $case[1] ) ) );
}
buffer( 2 );
product( 531, array( 'stock' => 2 ), $ENDED + array( '_ebay_item_id' => '110000000531' ) );
list( $res, $calls ) = relist_now( 531, ended_answer( '110000000531', 'FixedPriceItem' ) );
check( 'R2 stock 2, Keep back 2: not relisted - the relist would send its floor of 1 - and no call made', array( is_wp_error( $res ) ? $res->get_error_message() : $res, array_column( $calls, 0 ) ), array( 'what "Keep back from eBay" and "Show at most on eBay" leave of its stock for eBay is 0', array() ) );
product( 532, array( 'stock' => 1 ), array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_status' => 'Ended', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_item_id' => '110000000532' ) );
list( $res, $calls ) = relist_now( 532, ended_answer( '110000000532', 'Chinese' ) );
check( 'R3 an auction with stock 1, Keep back 2: relisted as ever - the buffer is never an auction\'s', array_column( $calls, 0 ), array( 'GetItem', 'RelistItem' ) );
product( 533, array( 'stock' => 2 ), array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_status' => 'Ended', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_item_id' => '110000000533' ) );
list( $res, $calls ) = relist_now( 533, ended_answer( '110000000533', 'FixedPriceItem' ) );
check( 'R4 recorded as an auction, eBay\'s answer fixed-price, stock 2, Keep back 2: refused once eBay\'s answer says so', array( is_wp_error( $res ) ? $res->get_error_message() : $res, array_column( $calls, 0 ) ), array( 'what "Keep back from eBay" and "Show at most on eBay" leave of its stock for eBay is 0', array( 'GetItem' ) ) );

echo "\nFIX ROUND 1 (REVIEW 1)\n" . str_repeat( '=', 124 ) . "\n";

// H1: the buffer is never an auction's - not on its quantity, not as a refusal.
$AUCTION = array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' );
foreach ( array(
	array( 'Keep back 2, stock 2', 2, '', 2, array() ),
	array( 'Keep back 2, stock 1', 2, '', 1, array() ),
	array( 'Keep back 2 and Show at most 3, stock 2', 2, 3, 2, array() ),
	array( 'the product\'s own Keep back 5, stock 3', '', '', 3, array( TCGiant_Sync_Stock_Buffer::KEEP_META => '5' ) ),
	array( 'the product\'s own Keep back 2 and Show at most 1, stock 2', '', '', 2, array( TCGiant_Sync_Stock_Buffer::KEEP_META => '2', TCGiant_Sync_Stock_Buffer::CAP_META => '1' ) ),
) as $case ) {
	list( $label, $keep, $cap, $stock, $own ) = $case;
	buffer( $keep, $cap );
	$new = push_answer( product( 540, array( 'stock' => $stock ), $AUCTION + $own ) );
	$sent = quantities( (string) ( $GLOBALS['API']->calls[0][1] ?? '' ) );
	check( 'H1 a new auction, ' . $label . ': listed, Quantity 1', array( $new[0], $new[1], $sent ), array( 'pushed', array( 'AddItem' ), array( 1 ) ) );
	$upd = push_answer( product( 541, array( 'stock' => $stock ), array( '_ebay_item_id' => '110000000541', '_ebay_listing_type' => 'Chinese' ) + $AUCTION + $own + PUSHED ) );
	check( '   an Update of a live auction, ' . $label . ': sent', array( $upd[0], $upd[2] ), array( 'pushed', '' ) );
	check( '   and not refused before eBay is asked', private_method( 'TCGiant_Sync_Exporter', 'refused_without_a_read' )->invoke( exporter(), wc_get_product( 541 ), TCGiant_Sync_OAuth::$settings ), false );
	product( 542, array( 'stock' => $stock ), $own + array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_status' => 'Ended', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_item_id' => '110000000542' ) );
	list( $res, $calls ) = relist_now( 542, ended_answer( '110000000542', 'Chinese' ) );
	check( '   a relist of an ended auction, ' . $label . ': relisted', array( is_wp_error( $res ) ? $res->get_error_message() : 'relisted', array_column( $calls, 0 ) ), array( 'relisted', array( 'GetItem', 'RelistItem' ) ) );
}
buffer( 2 );
product( 543, array( 'stock' => 2 ), PUSHED + array( '_ebay_item_id' => '110000000543', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' ) );
$live_only = push_answer( product( 544, array( 'stock' => 2 ), array( '_ebay_item_id' => '110000000544', '_ebay_listing_type' => 'Chinese' ) + PUSHED ) );
check( '   an Update of a live auction the product\'s own format does not name, stock 2, Keep back 2: sent', array( $live_only[0], $live_only[2] ), array( 'pushed', '' ) );
$was_auction = push_answer( product( 545, array( 'stock' => 2 ), array( '_ebay_listing_type' => 'Chinese' ) ) );
check( '   a product whose last listing was an auction, listed again as fixed price, stock 2, Keep back 2: refused, nothing asked', array( $was_auction[0], $was_auction[1] ), array( 'out_of_stock', array() ) );
check( '   (control) a fixed-price listing, stock 2, Keep back 2: refused before eBay is asked', private_method( 'TCGiant_Sync_Exporter', 'refused_without_a_read' )->invoke( exporter(), wc_get_product( 543 ), TCGiant_Sync_OAuth::$settings ), true );
check( 'L5 an Update a buffer leaves 0 of: refused in an Update\'s words', push_answer( wc_get_product( 543 ) ), array( 'out_of_stock', array(), 'Not updated on eBay: its stock of 2, less "Keep back from eBay" (or no more than "Show at most on eBay"), leaves nothing for eBay. eBay still shows its last figure; Send stock to eBay sets it to 0 (or ends it). Change those on its eBay tab or in Settings, or add stock, then update it again.' ) );

// L1: a figure WooCommerce does not manage stock for is never buffered.
/** A product with a stored stock whose stock WooCommerce does not manage. */
class Unmanaged_Product extends Price_Product {
	public function managing_stock() { return false; }
}
buffer( 2 );
check( 'L1 stock management off, a stored 10, Keep back 2: a new listing sends 10', quantities( first_body( push( product( 550, array( 'stock' => 10 ), array(), 'Unmanaged_Product' ) ) ) ), array( 10 ) );
check( '   an Update sends 10', quantities( first_body( push( product( 551, array( 'stock' => 10 ), PUSHED + array( '_ebay_item_id' => '110000000551' ), 'Unmanaged_Product' ) ) ) ), array( 10 ) );
check( '   a stored 2 is not refused', push_answer( product( 552, array( 'stock' => 2 ), array(), 'Unmanaged_Product' ) )[0], 'pushed' );
product( 553, array( 'stock' => 10 ), $ENDED + array( '_ebay_item_id' => '110000000553' ), 'Unmanaged_Product' );
list( $res, $calls ) = relist_now( 553, ended_answer( '110000000553', 'FixedPriceItem' ) );
check( '   a relist sends 10', array( array_column( $calls, 0 ), $calls[1][1] ?? null ), array( array( 'GetItem', 'RelistFixedPriceItem' ), relist_body( '110000000553', 10 ) ) );
check( '   (control) the same, stock managed: 8', quantities( first_body( push( product( 554, array( 'stock' => 10 ) ) ) ) ), array( 8 ) );

echo "\nFIX ROUND 2 (REVIEW 2)\n" . str_repeat( '=', 124 ) . "\n";

// R2-L1: stock pushes an Update queued to start eBay's record of sales, said
// in one line - at once for one product's Update, once in a bulk push's summary.
$NOTE_RP = new ReflectionMethod( 'TCGiant_Sync_Exporter', 'note_record_pushes' );
$NOTE_RP->setAccessible( true );
$SAY_RP = new ReflectionMethod( 'TCGiant_Sync_Exporter', 'say_record_pushes' );
$SAY_RP->setAccessible( true );
$rp_lines = function () { return array_values( array_filter( array_column( $GLOBALS['W']['log'] ?? array(), 1 ), function ( $l ) { return false !== strpos( $l, 'start eBay\'s record of sales' ); } ) ); };
$GLOBALS['W']['log'] = array();
TCGiant_Sync_Exporter::update_export_state( array( 'status' => 'idle' ) );
$NOTE_RP->invoke( null, 1, 560 );
$NOTE_RP->invoke( null, 0, 561 );
check( 'R2-L1 one product\'s Update that queued its record push: one line, at once; one that queued none: nothing', $rp_lines(), array( 'Update of WC #560: its stock push was queued to start eBay\'s record of sales for the listing, which "Keep back from eBay" or "Show at most on eBay" sends less than the stock (one call).' ) );
$GLOBALS['W']['log'] = array();
TCGiant_Sync_Exporter::update_export_state( array( 'status' => 'pushing' ) );
foreach ( array( 562, 563, 564 ) as $id ) {
	$NOTE_RP->invoke( null, 1, $id );
}
$during = $rp_lines();
$SAY_RP->invoke( null );
$SAY_RP->invoke( null );
check( '   a bulk push of three such Updates: nothing per product, one line in its summary, said once', array( $during, $rp_lines(), get_option( TCGiant_Sync_Exporter::RECORD_PUSHES_OPTION, null ) ), array( array(), array( 'Export: 3 stock push(es) queued to start eBay\'s record of sales, for listings "Keep back from eBay" or "Show at most on eBay" sends less than the stock (one call each).' ), null ) );
TCGiant_Sync_Exporter::update_export_state( array( 'status' => 'idle' ) );

done();
