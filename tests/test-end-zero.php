<?php
/**
 * Harness: "When you end a listing on eBay, set its website stock to 0?"
 * (3.46.0).
 *
 * A website-led shop ends eBay listings that still have stock. The plugin
 * marked each Ended and, by design, took only eBay's sales off the stock - a
 * shop may end a listing only to relist it - so the item stayed for sale on
 * the website. The shop asked for an option. With it Yes, a listing the
 * seller ends on eBay (eBay's own EndingReason says so: EBAY_ENDS_BY_SHOP),
 * with units unsold and stock still held here, has its website stock set to
 * 0 at its first settle - once, only for ends after the setting was switched
 * on, never for the plugin's own ends, never by a push of its own. With it
 * No, everything is as 3.45.0 left it. Fix round 1: eBay's list of changes
 * and the full sync's pages never carry the reason, so the delta's and the
 * full sync's settle read the listing once with GetItem where the setting
 * could act, and only then. Fix round 2: once the 0 lands nothing is left
 * for Stock Review's "Use eBay's figure"; a stock the shop raised after the
 * end is never set to 0; every write of the settings records the switch;
 * a fixed-length listing the full sync's row shows ran its time is not read.
 * Fix round 3: a raise the plugin makes from eBay is not the shop's.
 *
 * The world is tests/test-stock-push.php's, taken as it stands up to its
 * first case, as tests/test-stock-buffer.php takes it: the real inventory,
 * API (down to the HTTP call, every request recorded), mapper, importer and
 * admin classes; WordPress's hooks, WooCommerce's stock functions and Action
 * Scheduler played. The hourly check, Stock Review's Settle, End Listing from
 * the Listings screen and the relist check are tests/test-end-zero-paths.php's.
 *
 * Every time compared is made from time(): the switch-on an hour ago, each
 * end ten minutes ago unless a case says otherwise.
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

// The import asks the plan whether it may create a product: never here,
// where every case's product exists.
class TCGiant_Sync_Entitlements {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function can_import() { return false; }
	public function is_pro() { return false; }
}
$TIMEOUT = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$MAPPER = TCGiant_Sync_Mapper::instance();
$SINCE  = time() - HOUR_IN_SECONDS;
$SAID   = '"When you end a listing on eBay, set its website stock to 0?" is on, so its website stock was set to 0';

/** The shop, the setting Yes (switched on an hour ago) or No. */
function zero_shop( $on = true, array $more = array() ) {
	fresh_shop( ( $on ? array( 'end_zeroes_stock' => '1' ) : array() ) + $more );
	if ( $on ) {
		$GLOBALS['OPTIONS'][ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] = $GLOBALS['SINCE'];
	}
}
/** eBay's answer for a listing the seller ended $ago seconds ago, eBay's reason $why (none for null). */
function seller_ended( $item_id, $listed, $sold, $why = 'NotAvailable', $ago = 600, array $extra = array() ) {
	$item = listing( $item_id, $listed, $sold, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed' ), 'ListingDetails' => array( 'EndTime' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - $ago ) ) ) );
	if ( null !== $why ) {
		$item['ListingDetails']['EndingReason'] = $why;
	}
	return array_replace_recursive( $item, $extra );
}
/** The settle every path ends in, handed eBay's answer. */
function settle_with( $id, array $answer ) {
	return TCGiant_Sync_Inventory::apply_ended_listing_stock( $id, $answer['Quantity'] ?? null, $answer['SellingStatus']['QuantitySold'] ?? null, $answer );
}
/** What the setting recorded, without its time. */
function zeroed( $id ) {
	$m = meta( $id, TCGiant_Sync_Inventory::END_ZERO_META );
	if ( ! is_array( $m ) ) {
		return null;
	}
	unset( $m['at'] );
	return $m;
}
function zero_lines() { return count_logged( $GLOBALS['SAID'] ); }
/** The record of eBay's sales on product 300: its listing and what it had sold. */
function rec300() { $r = meta( 300, '_ebay_sold_seen' ); return is_array( $r ) ? array( $r['item'] ?? null, $r['sold'] ?? null ) : null; }
/** Every stock write so far made as an eBay-origin one, which queues nothing. */
function writes_from_ebay() { return array_values( array_unique( array_column( $GLOBALS['WRITES'], 3 ) ) ); }
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
$SETTLE_ENDED = new ReflectionMethod( 'TCGiant_Sync_Importer', 'settle_ended_listing' );
$SETTLE_ENDED->setAccessible( true );
$COMPACT = new ReflectionMethod( 'TCGiant_Sync_Importer', 'compact_delta_item' );
$COMPACT->setAccessible( true );
/**
 * GetSellerEvents' cut of a listing, as eBay documents its ReturnAll
 * fields: ListingDetails only StartTime and EndTime - never a reason -
 * and Quantity only above 1 (fix round 1).
 */
function events_row( array $item ) {
	$row = array(
		'ItemID'         => $item['ItemID'],
		'ListingType'    => $item['ListingType'],
		'ListingDetails' => array_intersect_key( $item['ListingDetails'], array_flip( array( 'StartTime', 'EndTime' ) ) ),
		'SellingStatus'  => array_intersect_key( $item['SellingStatus'], array_flip( array( 'QuantitySold', 'ListingStatus' ) ) ),
	);
	if ( (int) $item['Quantity'] > 1 ) {
		$row['Quantity'] = $item['Quantity'];
	}
	return $row;
}
/** The delta's settle of an ended row, kept as the delta keeps it; $from_row as the delta gives it. */
function delta_settle( $id, array $row, $from_row = true ) {
	$row = $GLOBALS['COMPACT']->invoke( null, $row );
	return $GLOBALS['SETTLE_ENDED']->invoke( null, $id, $row, 'Completed', 'Delta', $from_row );
}
/** The full sync's settle of an ended listing on its page: GetSellerList gives no reason. */
function full_sync_settle( $id, array $item ) {
	unset( $item['ListingDetails']['EndingReason'] );
	return $GLOBALS['SETTLE_ENDED']->invoke( null, $id, $item, 'Completed', 'Full sync', true );
}
function item12( $id ) { return sprintf( '1200000%05d', $id ); }
/**
 * The settings written as WordPress writes an option (fix round 2): the row,
 * then update_option_{name} with the old and new values when they differ -
 * or, the first time, add_option_{name}. The world's own update_option()
 * fires neither.
 */
function write_settings( array $value ) {
	$name = 'tcgiant_sync_ebay_settings';
	if ( ! array_key_exists( $name, $GLOBALS['OPTIONS'] ) ) {
		$GLOBALS['OPTIONS'][ $name ] = $value;
		do_action( 'add_option_' . $name, $name, $value );
		return true;
	}
	$old = $GLOBALS['OPTIONS'][ $name ];
	if ( $old === $value ) {
		return false;
	}
	$GLOBALS['OPTIONS'][ $name ] = $value;
	do_action( 'update_option_' . $name, $old, $value, $name );
	return true;
}
/** Saved from the Settings screen: WordPress runs the form's sanitize, then writes the row. */
function save_settings_form( array $post ) {
	$saved = TCGiant_Sync_Admin::instance()->sanitize_settings( $post );
	write_settings( $saved );
	return $saved;
}
/** eBay's answer for a listing of $length ($days, or GTC) that ended ten minutes ago, $ran seconds after it started. */
function ran_for( $id, $length, $ran, $why = 'NotAvailable', $type = 'FixedPriceItem' ) {
	$item = seller_ended( item12( $id ), 3, 1, $why );
	$end  = strtotime( $item['ListingDetails']['EndTime'] );
	$item['ListingType']                 = $type;
	$item['ListingDuration']             = $length;
	$item['ListingDetails']['StartTime'] = gmdate( 'Y-m-d\TH:i:s.000\Z', $end - $ran );
	return $item;
}
$ENDED_READ_ERROR = new ReflectionProperty( 'TCGiant_Sync_Importer', 'ended_read_error' );
$ENDED_READ_ERROR->setAccessible( true );
/** The admin's End Listing on the product (AJAX), as the edit screen asks. */
function end_from_wordpress( $id ) {
	$_POST = array( 'product_id' => $id );
	try {
		TCGiant_Sync_Admin::instance()->ajax_end_listing();
	} catch ( Harness_Sent $s ) {
		return $s->payload['success'];
	}
	return null;
}

echo "\nTHE SETTLE ITSELF\n" . str_repeat( '=', 132 ) . "\n";

zero_shop();
product( 101, 2, '110000000101' );
$got = settle_with( 101, seller_ended( '110000000101', 3, 1 ) );
check( 'Yes: the seller ended it (NotAvailable) with 2 unsold, 2 in stock here: 0, out of stock', array( stock( 101 ), meta( 101, '_stock_status' ), $got ), array( 0, 'outofstock', 0 ) );
check( '  recorded: the listing, eBay\'s units unsold, what it held', zeroed( 101 ), array( 'item' => '110000000101', 'unsold' => 2, 'was' => array( 101 => 2 ) ) );
check( '  when: now', abs( (int) meta( 101, TCGiant_Sync_Inventory::END_ZERO_META )['at'] - time() ) <= 2, true );
check( '  one log line, its words', array( zero_lines(), logged( 'WC #101: you ended eBay listing 110000000101 with 2 unsold, and "When you end a listing on eBay, set its website stock to 0?" is on, so its website stock was set to 0 (it was 2).', 'info' ) ), array( 1, true ) );
check( '  nothing queued for eBay, nothing sent: no call at all', array( pushes(), calls() ), array( array(), array() ) );
check( '  every stock write an eBay-origin one (a push is never queued for it)', writes_from_ebay(), array( true ) );
check( '  the end recorded as the seller\'s, as 3.28.5 records it', array( meta( 101, '_ebay_end_reason' ), meta( 101, '_ebay_end_reason_item' ) ), array( 'by_shop', '110000000101' ) );
check( '  and settled, as ever', settled_on( 101 ), array( true, '110000000101' ) );

zero_shop( false );
product( 102, 2, '110000000102' );
$got = settle_with( 102, seller_ended( '110000000102', 3, 1 ) );
check( 'No: the stock stays as 3.45.0 leaves it (the lower of 2 here and 2 unsold)', array( stock( 102 ), meta( 102, '_stock_status' ), $got ), array( 2, 'instock', 2 ) );
check( '  nothing recorded, no line, nothing queued or sent', array( zeroed( 102 ), zero_lines(), pushes(), calls() ), array( null, 0, array(), array() ) );
check( '  no option written', array( array_key_exists( TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION, $OPTIONS ), array_key_exists( TCGiant_Sync_API::OWN_ENDS_OPTION, $OPTIONS ) ), array( false, false ) );
check( '  the end recorded as the seller\'s all the same (3.28.5)', meta( 102, '_ebay_end_reason' ), 'by_shop' );
zero_shop( false, array( 'end_zeroes_stock' => '0' ) );
product( 103, 2, '110000000103' );
settle_with( 103, seller_ended( '110000000103', 3, 1 ) );
check( '  "0" is No too', array( stock( 103 ), zeroed( 103 ) ), array( 2, null ) );
foreach ( array( 'yes', 'on', '2', 'true' ) as $i => $tcg_not ) {
	zero_shop( false, array( 'end_zeroes_stock' => $tcg_not ) );
	$OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] = $SINCE;
	product( 104 + $i, 2, '11000000010' . ( 4 + $i ) );
	settle_with( 104 + $i, seller_ended( '11000000010' . ( 4 + $i ), 3, 1 ) );
	check( '  "' . $tcg_not . '" is not Yes: only "1" is', stock( 104 + $i ), 2 );
}
zero_shop( false, array( 'end_zeroes_stock' => true ) );
$OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] = $SINCE;
product( 108, 2, '110000000108' );
settle_with( 108, seller_ended( '110000000108', 3, 1 ) );
check( '  a stored true reads as "1"', stock( 108 ), 0 );
zero_shop( false, array( 'end_zeroes_stock' => array( '1' ) ) );
$OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] = $SINCE;
product( 109, 2, '110000000109' );
settle_with( 109, seller_ended( '110000000109', 3, 1 ) );
check( '  a stored list is not Yes', stock( 109 ), 2 );

echo "\nONLY THE SELLER'S OWN END, WITH UNITS UNSOLD\n" . str_repeat( '=', 132 ) . "\n";

$tcg_reasons = array();
foreach ( TCGiant_Sync_Inventory::EBAY_ENDS_BY_SHOP as $i => $tcg_why ) {
	zero_shop();
	product( 120 + $i, 3, '11000000012' . $i );
	settle_with( 120 + $i, seller_ended( '11000000012' . $i, 5, 1, $tcg_why ) );
	$tcg_reasons[ $tcg_why ] = array( stock( 120 + $i ), zero_lines() );
}
check( 'each reason eBay gives for the seller\'s end: 0, said once', $tcg_reasons, array( 'NotAvailable' => array( 0, 1 ), 'Incorrect' => array( 0, 1 ), 'LostOrBroken' => array( 0, 1 ), 'OtherListingError' => array( 0, 1 ), 'ProductDeleted' => array( 0, 1 ) ) );
check( '  the five 3.28.5 reads as the seller\'s', TCGiant_Sync_Inventory::EBAY_ENDS_BY_SHOP, array( 'NotAvailable', 'Incorrect', 'LostOrBroken', 'OtherListingError', 'ProductDeleted' ) );

zero_shop();
product( 130, 2, '110000000130' );
settle_with( 130, seller_ended( '110000000130', 3, 1, 'Sold' ) );
check( 'Sold, with units left: never - it was a sale; the stock stays 2', array( stock( 130 ), zeroed( 130 ), zero_lines() ), array( 2, null, 0 ) );
check( '  though 3.28.5 records it as the shop\'s end, as before', meta( 130, '_ebay_end_reason' ), 'by_shop' );
zero_shop();
product( 131, 2, '110000000131' );
settle_with( 131, seller_ended( '110000000131', 3, 1, 'SellToHighBidder' ) );
check( 'SellToHighBidder, with units left: never', array( stock( 131 ), zeroed( 131 ) ), array( 2, null ) );
zero_shop();
product( 132, 2, '110000000132' );
$got = settle_with( 132, seller_ended( '110000000132', 3, 3 ) );
check( 'sold out on eBay (3 of 3), NotAvailable: the settle takes it to 0 as ever; the setting does nothing', array( stock( 132 ), $got, zeroed( 132 ), zero_lines() ), array( 0, 0, null, 0 ) );
check( '  recorded as sold out', meta( 132, '_ebay_end_reason' ), 'sold_out' );
zero_shop();
product( 133, 4, '110000000133' );
settle_with( 133, seller_ended( '110000000133', 3, 4 ) );
check( 'eBay says more sold than listed: no units unsold - nothing', array( stock( 133 ), zeroed( 133 ) ), array( 0, null ) );
foreach ( array( 'CustomCode', 'SomethingNew', null ) as $i => $tcg_why ) {
	zero_shop();
	product( 135 + $i, 2, '11000000013' . ( 5 + $i ) );
	settle_with( 135 + $i, seller_ended( '11000000013' . ( 5 + $i ), 3, 1, $tcg_why ) );
	check( '  ' . ( null === $tcg_why ? 'no reason given' : $tcg_why ) . ': nothing', array( stock( 135 + $i ), zeroed( 135 + $i ) ), array( 2, null ) );
}
zero_shop();
product( 138, 2, '110000000138' );
settle_with( 138, seller_ended( '110000000138', 3, 1, 'NotAvailable', 600, array( 'SellingStatus' => array( 'ListingStatus' => 'Active' ) ) ) );
check( 'an answer saying the listing is Active: nothing', array( stock( 138 ), zeroed( 138 ) ), array( 2, null ) );
zero_shop();
product( 139, 0, '110000000139' );
settle_with( 139, seller_ended( '110000000139', 3, 1 ) );
check( 'nothing in stock here (sold on the website): nothing to set, nothing recorded', array( stock( 139 ), zeroed( 139 ), zero_lines() ), array( 0, null, 0 ) );

echo "\nNOT RETROACTIVE\n" . str_repeat( '=', 132 ) . "\n";

zero_shop();
product( 140, 2, '110000000140' );
settle_with( 140, seller_ended( '110000000140', 3, 1, 'NotAvailable', 2 * HOUR_IN_SECONDS ) );
check( 'ended before the setting was switched on: never', array( stock( 140 ), zeroed( 140 ), zero_lines() ), array( 2, null, 0 ) );
zero_shop();
product( 141, 2, '110000000141' );
settle_with( 141, seller_ended( '110000000141', 3, 1, 'NotAvailable', time() - $SINCE ) );
check( '  ended the very second it was switched on: never - only after', array( stock( 141 ), zeroed( 141 ) ), array( 2, null ) );
zero_shop();
product( 142, 2, '110000000142' );
settle_with( 142, seller_ended( '110000000142', 3, 1, 'NotAvailable', time() - $SINCE - 1 ) );
check( '  a second after: 0', stock( 142 ), 0 );
zero_shop();
product( 143, 2, '110000000143' );
$tcg_no_end = seller_ended( '110000000143', 3, 1 );
unset( $tcg_no_end['ListingDetails']['EndTime'] );
settle_with( 143, $tcg_no_end );
check( 'an answer without an EndTime: left as it is', array( stock( 143 ), zeroed( 143 ) ), array( 2, null ) );
zero_shop();
product( 144, 2, '110000000144' );
settle_with( 144, seller_ended( '110000000144', 3, 1, 'NotAvailable', 600, array( 'ListingDetails' => array( 'EndTime' => 'soon' ) ) ) );
check( '  one eBay never writes ("soon"): left', array( stock( 144 ), zeroed( 144 ) ), array( 2, null ) );
// Switched on with no write WordPress announces - the row edited in the
// database: no moment recorded. The first settle that asks records now, so
// nothing that ended before is changed. (WP-CLI and another plugin write
// through update_option(): fix round 2, below.)
zero_shop();
unset( $OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] );
product( 145, 2, '110000000145' );
settle_with( 145, seller_ended( '110000000145', 3, 1 ) );
check( 'switched on with no moment recorded: this end (10 minutes ago) is left', array( stock( 145 ), zeroed( 145 ) ), array( 2, null ) );
check( '  and now is recorded as the moment, for the ends after it', abs( (int) ( $OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] ?? 0 ) - time() ) <= 2, true );
$OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] = time() - 1200;
product( 146, 2, '110000000146' );
settle_with( 146, seller_ended( '110000000146', 3, 1 ) );
check( '  an end after it: 0', stock( 146 ), 0 );
zero_shop( false );
product( 147, 2, '110000000147' );
settle_with( 147, seller_ended( '110000000147', 3, 1 ) );
check( 'with No, a moment is never recorded', array_key_exists( TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION, $OPTIONS ), false );

echo "\nMANAGED AND UNMANAGED STOCK\n" . str_repeat( '=', 132 ) . "\n";

zero_shop();
product( 150, null, '110000000150' );
$got = settle_with( 150, seller_ended( '110000000150', 3, 1 ) );
check( 'a stock WooCommerce does not count, in stock: out of stock', array( meta( 150, '_stock_status' ), $got ), array( 'outofstock', 0 ) );
check( '  recorded "in stock"; its line says so', array( zeroed( 150 )['was'] ?? null, logged( 'WC #150: you ended eBay listing 110000000150 with 2 unsold, and ' . $SAID . ' (it was in stock).' ) ), array( array( 150 => 'in stock' ), true ) );
check( '  no quantity written, nothing queued or sent', array( $WRITES, pushes(), calls() ), array( array(), array(), array() ) );
zero_shop();
product( 151, null, '110000000151', array( '_stock_status' => 'outofstock' ) );
settle_with( 151, seller_ended( '110000000151', 3, 1 ) );
check( '  already out of stock: nothing to do, nothing recorded', array( zeroed( 151 ), zero_lines() ), array( null, 0 ) );
zero_shop();
product( 152, 9, '110000000152' );
$got = settle_with( 152, seller_ended( '110000000152', 5, 0 ) );
check( 'managed, 9 here and 5 unsold on eBay: the settle leaves 5, the setting sets 0', array( stock( 152 ), $got ), array( 0, 0 ) );
check( '  what it held before the settle is what is recorded and said (fix round 2)', array( zeroed( 152 )['was'], logged( 'WC #152: you ended eBay listing 110000000152 with 5 unsold, and ' . $SAID . ' (it was 9).' ) ), array( array( 152 => 9 ), true ) );
zero_shop();
product( 153, 4, '110000000153' );
$HELD_STOCK = array( 153 );
$got = settle_with( 153, seller_ended( '110000000153', 6, 0 ) );
$HELD_STOCK = array();
check( 'a stock something else on the site holds: left, the settle\'s figure returned', array( stock( 153 ), $got ), array( 4, 4 ) );
check( '  said as a warning, naming it, recorded all the same', array( logged( 'WC #153: you ended eBay listing 110000000153 with 6 unsold, and ' . $SAID . ' (it was 4) - but not #153: its stock did not change, as something else on this site is holding it. Set it to 0 by hand.', 'warning' ), zeroed( 153 )['was'] ), array( true, array( 153 => 4 ) ) );
check( '  never tried again by a later settle', array( TCGiant_Sync_Inventory::apply_ended_listing_stock( 153, '6', '0', seller_ended( '110000000153', 6, 0 ) ), count_logged( 'WC #153: you ended' ) ), array( 4, 1 ) );

echo "\nTHE PLUGIN'S OWN ENDS ARE NEVER ITS SELLER'S\n" . str_repeat( '=', 132 ) . "\n";

check( 'the API reads the same setting', TCGiant_Sync_API::END_ZERO_SETTING, TCGiant_Sync_Inventory::END_ZERO_SETTING );

// A stock push at 0 ending the listing (auto-end on), under "Keep back from
// eBay" 2: the stock is 2, eBay shows 1, a website sale leaves 1 here and 0
// for eBay - the plugin ends the listing with a unit still kept back here.
zero_shop( true, array( 'stock_keep_back' => '2' ) );
product( 160, 3, '110000000160' );
$META[160][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '110000000160', 'at' => time() );
seen( 160, '110000000160', 0 );
ebay_has( '110000000160', listing( '110000000160', 1, 0 ) );
website_order( 1600, array( 160 => 1 ) );
run_queue();
check( 'the plugin\'s end at 0 (a website sale, 1 kept back): EndItem sent, stock 2 here', array( calls(), stock( 160 ) ), array( array( 'GetItem', 'EndItem' ), 2 ) );
check( '  marked as the plugin\'s own before it was sent', TCGiant_Sync_API::own_end( '110000000160' ), true );
check( '  recorded as its end at 0, and settled there and then', array( meta( 160, '_ebay_end_reason' ), settled_on( 160 ) ), array( 'stock_zero', array( true, '110000000160' ) ) );
$WIRE = array();
TCGiant_Sync_Importer::settle_ended_from_item( 160, seller_ended( '110000000160', 1, 0 ), 'Delta' );
check( '  the delta reading eBay\'s NotAvailable for it: the kept-back unit stays', array( stock( 160 ), zeroed( 160 ), zero_lines(), calls() ), array( 2, null, 0, array() ) );
// Every guard alone: the settle and the reason taken away, the mark keeps it.
foreach ( array( '_ebay_stock_settled_at', '_ebay_stock_settled_item', '_ebay_end_reason', '_ebay_end_reason_item' ) as $tcg_key ) {
	unset( $META[160][ $tcg_key ] );
}
settle_with( 160, seller_ended( '110000000160', 1, 0 ) );
check( '  with only the mark to say so: still left', array( stock( 160 ), zeroed( 160 ) ), array( 2, null ) );

// The same end, its answer lost on the way: tried again, the read finds the
// listing over and settles it before anything records whose end it was.
zero_shop( true, array( 'stock_keep_back' => '2' ) );
product( 161, 3, '110000000161' );
$META[161][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '110000000161', 'at' => time() );
seen( 161, '110000000161', 0 );
ebay_has( '110000000161', listing( '110000000161', 1, 0 ), seller_ended( '110000000161', 1, 0, 'NotAvailable', 5 ) );
ebay_answers( 'EndItem', $TIMEOUT, $TIMEOUT, $TIMEOUT );
website_order( 1610, array( 161 => 1 ) );
run_queue();
check( 'the plugin\'s end, its answer lost (each try timed out): tried again later', array( array_values( array_unique( calls() ) ), count( pushes() ) ), array( array( 'GetItem', 'EndItem' ), 1 ) );
$WIRE = array();
run_queue( true );
check( '  the try again reads it ended (NotAvailable) and settles it: the kept-back unit stays', array( calls(), stock( 161 ), zeroed( 161 ), zero_lines() ), array( array( 'GetItem' ), 2, null, 0 ) );
check( '  eBay\'s reason recorded as 3.45.0 records it, the shop still holding stock', meta( 161, '_ebay_end_reason' ), 'by_shop' );
// The control: the same, with no mark - which is how a seller's end reads.
zero_shop( true, array( 'stock_keep_back' => '2' ) );
product( 162, 3, '110000000162' );
$META[162][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '110000000162', 'at' => time() );
seen( 162, '110000000162', 0 );
ebay_has( '110000000162', listing( '110000000162', 1, 0 ), seller_ended( '110000000162', 1, 0, 'NotAvailable', 5 ) );
ebay_answers( 'EndItem', $TIMEOUT, $TIMEOUT, $TIMEOUT );
website_order( 1620, array( 162 => 1 ) );
run_queue();
TCGiant_Sync_API::forget_own_ends();
run_queue( true );
check( '  (the control: its mark taken away, it reads as the seller\'s end and is set to 0)', array( stock( 162 ), zeroed( 162 )['item'] ?? null ), array( 0, '110000000162' ) );

// End Listing on the product, from WordPress.
zero_shop();
product( 163, 2, '110000000163' );
check( 'End Listing on the product, from WordPress: ended', end_from_wordpress( 163 ), true );
check( '  marked as the plugin\'s own, recorded as the shop\'s end here', array( TCGiant_Sync_API::own_end( '110000000163' ), meta( 163, '_ebay_end_reason' ), calls() ), array( true, 'by_shop', array( 'EndItem' ) ) );
$WIRE = array();
TCGiant_Sync_Importer::settle_ended_from_item( 163, seller_ended( '110000000163', 3, 1 ), 'Delta' );
check( '  its settle, eBay saying NotAvailable: the stock stays 2 (End Listing here is out of scope)', array( stock( 163 ), zeroed( 163 ), zero_lines(), calls() ), array( 2, null, 0, array() ) );
// Each guard alone keeps it.
zero_shop();
product( 164, 2, '110000000164' );
end_from_wordpress( 164 );
TCGiant_Sync_API::forget_own_ends();
settle_with( 164, seller_ended( '110000000164', 3, 1 ) );
check( '  with the mark gone, the reason recorded here keeps it', array( stock( 164 ), zeroed( 164 ) ), array( 2, null ) );
zero_shop();
product( 165, 2, '110000000165' );
end_from_wordpress( 165 );
unset( $META[165]['_ebay_end_reason'], $META[165]['_ebay_end_reason_item'] );
settle_with( 165, seller_ended( '110000000165', 3, 1 ) );
check( '  with the reason gone, the mark keeps it', array( stock( 165 ), zeroed( 165 ) ), array( 2, null ) );
// A reason recorded here for this listing, whatever it is, comes first.
foreach ( array( 'stock_zero', 'by_shop', 'sold_out' ) as $i => $tcg_reason ) {
	zero_shop();
	product( 166 + $i, 2, '11000000016' . ( 6 + $i ), array( '_ebay_end_reason' => $tcg_reason, '_ebay_end_reason_item' => '11000000016' . ( 6 + $i ) ) );
	settle_with( 166 + $i, seller_ended( '11000000016' . ( 6 + $i ), 3, 1 ) );
	check( '  ' . $tcg_reason . ' recorded for this listing before the settle: left', array( stock( 166 + $i ), zeroed( 166 + $i ) ), array( 2, null ) );
}
zero_shop();
product( 169, 2, '110000000169', array( '_ebay_end_reason' => 'stock_zero', '_ebay_end_reason_item' => '110000000999' ) );
settle_with( 169, seller_ended( '110000000169', 3, 1 ) );
check( '  one an earlier listing left: not this one\'s - 0', stock( 169 ), 0 );

// An auction ended at 0, nobody bidding.
zero_shop();
product( 170, 1, '110000000170', array( '_ebay_listing_type' => 'Chinese' ) );
ebay_has( '110000000170', listing( '110000000170', 1, 0, array( 'ListingType' => 'Chinese', 'SellingStatus' => array( 'BidCount' => '0' ) ) ) );
website_order( 1700, array( 170 => 1 ) );
run_queue();
check( 'an auction the plugin ends at 0: EndItem, marked as its own', array( calls(), TCGiant_Sync_API::own_end( '110000000170' ) ), array( array( 'GetItem', 'EndItem' ), true ) );
// The deprecated end at 0 another plugin may still call.
zero_shop();
$API->update_trading_api_stock( '110000000171', 0 );
check( 'update_trading_api_stock() at 0 (deprecated, no caller here since 3.28.0): marked too', array( calls(), TCGiant_Sync_API::own_end( '110000000171' ) ), array( array( 'EndItem' ), true ) );
zero_shop( false );
product( 172, 2, '110000000172' );
end_from_wordpress( 172 );
check( 'with No, an end is never marked: no option written', array( calls(), array_key_exists( TCGiant_Sync_API::OWN_ENDS_OPTION, $OPTIONS ) ), array( array( 'EndItem' ), false ) );
check( '  a listing never marked is not the plugin\'s; nor is ""', array( TCGiant_Sync_API::own_end( '110000000999' ), TCGiant_Sync_API::own_end( '' ) ), array( false, false ) );
// The marks kept: the last 30 days', at most 1000.
zero_shop();
$tcg_ends = array();
for ( $i = 0; $i < 1005; $i++ ) {
	$tcg_ends[ (string) ( 120000000000 + $i ) ] = time() - 100000 + $i;
}
$tcg_ends['120000009999'] = time() - 31 * DAY_IN_SECONDS;
$OPTIONS[ TCGiant_Sync_API::OWN_ENDS_OPTION ] = $tcg_ends;
TCGiant_Sync_API::note_own_end( '130000000001' );
$tcg_kept = $OPTIONS[ TCGiant_Sync_API::OWN_ENDS_OPTION ];
check( 'the marks kept: at most 1000, the newest', array( count( $tcg_kept ), TCGiant_Sync_API::own_end( '130000000001' ), TCGiant_Sync_API::own_end( '120000001004' ), TCGiant_Sync_API::own_end( '120000000006' ), TCGiant_Sync_API::own_end( '120000000005' ) ), array( 1000, true, true, true, false ) );
check( '  none older than 30 days', TCGiant_Sync_API::own_end( '120000009999' ), false );
zero_shop();
$OPTIONS[ TCGiant_Sync_API::OWN_ENDS_OPTION ] = array( '120000009998' => time() - 31 * DAY_IN_SECONDS, '120000009997' => time() - 29 * DAY_IN_SECONDS );
TCGiant_Sync_API::note_own_end( '130000000002' );
check( '  a few marks: one of 31 days dropped, one of 29 kept', array( TCGiant_Sync_API::own_end( '120000009998' ), TCGiant_Sync_API::own_end( '120000009997' ), TCGiant_Sync_API::own_end( '130000000002' ) ), array( false, true, true ) );
check( '  kept apart from the autoloaded options', $OPTIONS['__autoload'][ TCGiant_Sync_API::OWN_ENDS_OPTION ] ?? 'not said', false );

echo "\nA PRODUCT THAT HAS MOVED ON\n" . str_repeat( '=', 132 ) . "\n";

// It follows a relist now: the old listing's answer is not about it.
zero_shop();
product( 180, 2, '110000000181', array( '_ebay_previous_item_id' => '110000000180' ) );
settle_with( 180, seller_ended( '110000000180', 3, 1 ) );
check( 'already following a relist: the old listing\'s answer leaves its stock alone', array( stock( 180 ), zeroed( 180 ), zero_lines() ), array( 2, null, 0 ) );
zero_shop();
product( 181, 2, '110000000182' );
settle_with( 181, seller_ended( '110000000183', 3, 1 ) );
check( '  linked to another listing (a relink, a Sell Similar copy matched): left', array( stock( 181 ), zeroed( 181 ) ), array( 2, null ) );
zero_shop();
product( 182, 2, '110000000184' );
settle_with( 182, seller_ended( '110000000184', 3, 1, 'NotAvailable', 600, array( 'ListingDetails' => array( 'RelistedItemID' => '110000000185' ) ) ) );
check( 'eBay says it was relisted (as 110000000185): left - the 0 never reaches the live relist', array( stock( 182 ), zeroed( 182 ), zero_lines() ), array( 2, null, 0 ) );
// Set to 0, then relisted on eBay and followed: the 0 is never sent there.
zero_shop();
product( 183, 2, '110000000186' );
settle_with( 183, seller_ended( '110000000186', 3, 1 ) );
check( 'set to 0, then relisted in Seller Hub: at 0', stock( 183 ), 0 );
TCGiant_Sync_Inventory::follow_relist( 183, '110000000186', '110000000187', array( 'status' => 'Active', 'listing_type' => 'FixedPriceItem' ), 'Delta' );
check( '  the product follows the relist; nothing queued for it, nothing sent', array( meta( 183, '_ebay_item_id' ), pushes(), calls() ), array( '110000000187', array(), array() ) );
$tcg_relist = listing( '110000000187', 2, 0, array( 'Title' => 'Coin 183', 'SKU' => 'SKU-183', 'RelistParentID' => '110000000186' ) );
import_listing( $tcg_relist );
check( '  its import brings eBay\'s 2 in, as for any live listing; still nothing queued or sent', array( stock( 183 ), pushes(), calls() ), array( 2, array(), array() ) );
check( '  the record of the 0 stays the old listing\'s', zeroed( 183 )['item'], '110000000186' );

echo "\nONCE A LISTING\n" . str_repeat( '=', 132 ) . "\n";

zero_shop();
product( 190, 2, '110000000190' );
TCGiant_Sync_Importer::settle_ended_from_item( 190, seller_ended( '110000000190', 3, 1 ), 'Delta' );
check( 'the delta sets it to 0', stock( 190 ), 0 );
hand_edit( 190, 2 );
$QUEUE = array();
foreach ( array( 'Delta', 'Import', 'Sale check', 'Stock push' ) as $tcg_src ) {
	TCGiant_Sync_Importer::settle_ended_from_item( 190, seller_ended( '110000000190', 3, 1 ), $tcg_src );
}
check( 'the shop raises it to 2; the delta, the import, the sale check and a push read it again: 2 stays', array( stock( 190 ), zero_lines() ), array( 2, 1 ) );
$WIRE = array();
ebay_has( '110000000190', seller_ended( '110000000190', 3, 1 ) );
$IMP->settle_item( '110000000190' );
check( '  the sale check asking eBay again: 2 stays', array( stock( 190 ), zero_lines(), calls() ), array( 2, 1, array( 'GetItem' ) ) );
// The record alone keeps it: the settle and its reason forgotten, and
// the mark of the shop's raise (fix round 2) - a restock WooCommerce did
// not announce leaves none.
foreach ( TCGiant_Sync_Inventory::SETTLE_KEYS as $tcg_key ) {
	unset( $META[190][ $tcg_key ] );
}
unset( $META[190][ TCGiant_Sync_Inventory::STOCK_RAISED_META ] );
settle_with( 190, seller_ended( '110000000190', 3, 1 ) );
check( '  with only its record of the 0 to go on: 2 stays', array( stock( 190 ), zero_lines() ), array( 2, 1 ) );
// A later listing of the same product is another listing.
zero_shop();
product( 191, 2, '110000000192', array( TCGiant_Sync_Inventory::END_ZERO_META => array( 'item' => '110000000191', 'at' => time() - DAY_IN_SECONDS, 'unsold' => 1, 'was' => array( 191 => 1 ) ) ) );
settle_with( 191, seller_ended( '110000000192', 3, 1 ) );
check( 'its next listing ended the same way: 0 again, the record now that one\'s', array( stock( 191 ), zeroed( 191 )['item'] ), array( 0, '110000000192' ) );
// Only the first settle: one from an answer with no reason, then a later
// one with it (Stock Review's Settle calls the settle again): left.
zero_shop();
product( 192, 2, '110000000193' );
settle_with( 192, seller_ended( '110000000193', 3, 1, null ) );
settle_with( 192, seller_ended( '110000000193', 3, 1 ) );
check( 'settled first from an answer with no reason, then again with eBay\'s NotAvailable: 2 stays', array( stock( 192 ), zeroed( 192 ), zero_lines() ), array( 2, null, 0 ) );

echo "\nVARIABLE PRODUCTS\n" . str_repeat( '=', 132 ) . "\n";

// Variations keeping their own stock: S and M on the listing, L not on it,
// XL on it but disabled.
zero_shop();
variable( 200, '110000000200' );
variation( 201, 200, 'TEE-S', 2, array( 'size' => 'S' ) );
variation( 202, 200, 'TEE-M', 1, array( 'size' => 'M' ) );
variation( 203, 200, 'TEE-L', 5, array( 'size' => 'L' ) );
variation( 204, 200, 'TEE-XL', 3, array( 'size' => 'XL' ) );
$POSTS[204]['status'] = 'private';
$tcg_over = variation_listing( '110000000200', array( ev( 'TEE-S', 3, 1, array( 'size' => 'S' ) ), ev( 'TEE-M', 1, 0, array( 'size' => 'M' ) ), ev( 'TEE-XL', 3, 0, array( 'size' => 'XL' ) ) ) );
$tcg_over['SellingStatus']['ListingStatus']    = 'Completed';
$tcg_over['ListingDetails']['EndTime']        = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 );
$tcg_over['ListingDetails']['EndingReason']   = 'NotAvailable';
TCGiant_Sync_Importer::settle_ended_from_item( 200, $tcg_over, 'Delta' );
check( 'own stock: each enabled variation on the listing to 0, one not on it and a disabled one left', array( stock( 201 ), stock( 202 ), stock( 203 ), stock( 204 ) ), array( 0, 0, 5, 3 ) );
check( '  recorded on the variable product: each stock it held', zeroed( 200 ), array( 'item' => '110000000200', 'unsold' => 6, 'was' => array( 201 => 2, 202 => 1 ) ) );
check( '  one line, naming each', logged( 'WC #200: you ended eBay listing 110000000200 with 6 unsold, and ' . $SAID . ' (it was #201 2; #202 1).' ), true );
check( '  nothing queued or sent', array( pushes(), calls() ), array( array(), array() ) );
zero_shop( false );
variable( 200, '110000000200' );
variation( 201, 200, 'TEE-S', 2, array( 'size' => 'S' ) );
variation( 202, 200, 'TEE-M', 1, array( 'size' => 'M' ) );
TCGiant_Sync_Importer::settle_ended_from_item( 200, $tcg_over, 'Delta' );
check( '  No: as 3.45.0 - each variation to eBay\'s figure only', array( stock( 201 ), stock( 202 ), zeroed( 200 ) ), array( 2, 1, null ) );

// A stock the variations share.
zero_shop();
variable( 210, '110000000210', array( '_manage_stock' => 'yes', '_stock' => 6, '_stock_status' => 'instock' ) );
variation( 211, 210, 'MUG-R', null, array( 'colour' => 'Red' ) );
variation( 212, 210, 'MUG-B', null, array( 'colour' => 'Blue' ) );
$META[211]['_manage_stock'] = 'no';
$META[212]['_manage_stock'] = 'no';
$tcg_over = variation_listing( '110000000210', array( ev( 'MUG-R', 6, 0, array( 'colour' => 'Red' ) ), ev( 'MUG-B', 6, 0, array( 'colour' => 'Blue' ) ) ) );
$tcg_over['SellingStatus']['ListingStatus']  = 'Completed';
$tcg_over['ListingDetails']['EndTime']      = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 );
$tcg_over['ListingDetails']['EndingReason'] = 'NotAvailable';
TCGiant_Sync_Importer::settle_ended_from_item( 210, $tcg_over, 'Delta' );
check( 'a shared stock: 0', array( stock( 210 ), meta( 210, '_stock_status' ) ), array( 0, 'outofstock' ) );
check( '  recorded and said as the shared stock', array( zeroed( 210 )['was'], logged( 'WC #210: you ended eBay listing 110000000210 with 12 unsold, and ' . $SAID . ' (it was the shared stock 6).' ) ), array( array( 210 => 6 ), true ) );
check( '  nothing queued or sent', array( pushes(), calls() ), array( array(), array() ) );

// eBay's answer without its variations (the parent's settle handed a brief
// row): which variations were on it cannot be told, so none is touched.
zero_shop();
variable( 220, '110000000220' );
variation( 221, 220, 'CAP-S', 2, array( 'size' => 'S' ) );
settle_with( 220, seller_ended( '110000000220', 3, 1 ) );
check( 'an answer naming no variation: a variation keeping its own stock is left', array( stock( 221 ), zeroed( 220 ) ), array( 2, null ) );

echo "\nUNDER \"KEEP BACK FROM eBAY\"\n" . str_repeat( '=', 132 ) . "\n";

// Keep back 2: 5 here, 3 on eBay, the listing's record at 0 sold.
zero_shop( true, array( 'stock_keep_back' => '2' ) );
product( 300, 5, '110000000300' );
$META[300][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '110000000300', 'at' => time() );
seen( 300, '110000000300', 0 );
TCGiant_Sync_Importer::settle_ended_from_item( 300, seller_ended( '110000000300', 3, 0 ), 'Delta' );
check( 'the seller ends it with 3 unsold, 5 here (2 kept back): the whole 5 goes to 0', array( stock( 300 ), zeroed( 300 )['was'] ), array( 0, array( 300 => 5 ) ) );
check( '  the mark and the record of eBay\'s sales stay the ended listing\'s', array( meta( 300, TCGiant_Sync_Inventory::BUFFERED_META )['item'] ?? null, rec300() ), array( '110000000300', array( '110000000300', 0 ) ) );
check( '  nothing queued or sent', array( pushes(), calls() ), array( array(), array() ) );
TCGiant_Sync_Importer::settle_ended_from_item( 300, seller_ended( '110000000300', 3, 0 ), 'Delta' );
hand_edit( 300, 4 );
$QUEUE = array();
TCGiant_Sync_Importer::settle_ended_from_item( 300, seller_ended( '110000000300', 3, 0 ), 'Delta' );
check( '  settled again, restocked to 4, settled again: 4 stays', array( stock( 300 ), zero_lines() ), array( 4, 1 ) );
zero_shop( false, array( 'stock_keep_back' => '2' ) );
product( 301, 5, '110000000301' );
$META[301][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '110000000301', 'at' => time() );
seen( 301, '110000000301', 0 );
TCGiant_Sync_Importer::settle_ended_from_item( 301, seller_ended( '110000000301', 3, 0 ), 'Delta' );
check( '  No: the 5 stays, as 3.45.0\'s settle under the mark leaves it', stock( 301 ), 5 );
// Every unit eBay showed sold (3 of 3), the listing then ended: no units
// unsold, so the 2 kept back here stay, as 3.45.0 keeps them.
zero_shop( true, array( 'stock_keep_back' => '2' ) );
product( 302, 5, '110000000302' );
$META[302][ TCGiant_Sync_Inventory::BUFFERED_META ] = array( 'item' => '110000000302', 'at' => time() );
seen( 302, '110000000302', 0 );
TCGiant_Sync_Importer::settle_ended_from_item( 302, seller_ended( '110000000302', 3, 3 ), 'Delta' );
check( '  sold out on eBay (3 of 3), NotAvailable: the 2 kept back stay - nothing was unsold', array( stock( 302 ), zeroed( 302 ) ), array( 2, null ) );

echo "\nTHE DELTA AND THE FULL SYNC READ eBAY'S REASON, ONCE (FIX ROUND 1)\n" . str_repeat( '=', 132 ) . "\n";

// eBay's list of changes (GetSellerEvents) gives a listing's start and end
// times and nothing of why it ended; nor does the full sync's GetSellerList.
// GetItem's answer alone carries EndingReason. With the setting Yes the
// settle of such a row reads the listing once, when the setting could act.
check( 'the delta\'s row, as eBay sends it, keeps its end time and has no reason', array_keys( $COMPACT->invoke( null, events_row( seller_ended( item12( 409 ), 3, 1 ) ) )['ListingDetails'] ), array( 'StartTime', 'EndTime' ) );
$tcg_rows = array();
foreach ( array( true, false ) as $tcg_on ) {
	$tcg_tag = $tcg_on ? 'Yes' : 'No';
	zero_shop( $tcg_on );
	product( 410, 2, item12( 410 ) );
	ebay_has( item12( 410 ), seller_ended( item12( 410 ), 3, 1 ) );
	delta_settle( 410, events_row( seller_ended( item12( 410 ), 3, 1 ) ) );
	$tcg_rows[ $tcg_tag ]['delta'] = array( stock( 410 ), calls(), pushes(), $CALLS_COUNTED, zero_lines() );
	zero_shop( $tcg_on );
	product( 411, 2, item12( 411 ) );
	ebay_has( item12( 411 ), seller_ended( item12( 411 ), 3, 1 ) );
	full_sync_settle( 411, seller_ended( item12( 411 ), 3, 1 ) );
	$tcg_rows[ $tcg_tag ]['full sync'] = array( stock( 411 ), calls(), pushes(), $CALLS_COUNTED, zero_lines() );
}
check( 'Yes: the delta\'s row and the full sync\'s - one GetItem each, counted as one call, then 0', $tcg_rows['Yes'], array( 'delta' => array( 0, array( 'GetItem' ), array(), 1, 1 ), 'full sync' => array( 0, array( 'GetItem' ), array(), 1, 1 ) ) );
$tcg_imp_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-importer.php' );
check( '  as the delta and the full sync call it: the delta\'s row unless GetItem\'s answer replaced it, the full sync\'s always; no other caller', array(
	substr_count( $tcg_imp_src, "self::settle_ended_listing( \$existing_id, \$ebay_item, \$listing_status, 'Delta', ! \$whole_item && \$asked_before === \$fallback_count )" ),
	substr_count( $tcg_imp_src, "self::settle_ended_listing( \$existing_id, \$item, (string) \$item['SellingStatus']['ListingStatus'], 'Full sync', true );" ),
	preg_match_all( '/self::settle_ended_listing\([^;]*,\s*true\s*\)/', $tcg_imp_src ),
), array( 1, 1, 1 ) );
check( 'No: no call at all, the stock the settle leaves - as 3.45.0', $tcg_rows['No'], array( 'delta' => array( 2, array(), array(), 0, 0 ), 'full sync' => array( 2, array(), array(), 0, 0 ) ) );
zero_shop();
product( 412, 2, item12( 412 ) );
ebay_has( item12( 412 ), seller_ended( item12( 412 ), 3, 1 ) );
delta_settle( 412, events_row( seller_ended( item12( 412 ), 3, 1 ) ) );
check( '  the listing marked as read for its reason', meta( 412, TCGiant_Sync_Inventory::END_ZERO_ASKED_META ), item12( 412 ) );

// Every case the setting could not act on: no call.
$tcg_none = array();
$tcg_case = function ( $label, $id, $stock, array $row, array $meta = array(), $from_row = true ) use ( &$tcg_none ) {
	zero_shop();
	product( $id, $stock, item12( $id ), $meta );
	ebay_has( item12( $id ), seller_ended( item12( $id ), 3, 1 ) );
	delta_settle( $id, $row, $from_row );
	$tcg_none[ $label ] = array( stock( $id ), calls() );
};
$tcg_case( 'sold out on eBay (3 of 3)', 420, 2, events_row( seller_ended( item12( 420 ), 3, 3 ) ) );
$tcg_case( 'more sold than listed', 421, 2, events_row( seller_ended( item12( 421 ), 3, 4 ) ) );
$tcg_case( 'nothing in stock here', 422, 0, events_row( seller_ended( item12( 422 ), 3, 1 ) ) );
$tcg_case( 'ended before the switch to Yes', 423, 2, events_row( seller_ended( item12( 423 ), 3, 1, 'NotAvailable', 2 * HOUR_IN_SECONDS ) ) );
$tcg_case( 'a reason recorded for it (End Listing here)', 424, 2, events_row( seller_ended( item12( 424 ), 3, 1 ) ), array( '_ebay_end_reason' => 'by_shop', '_ebay_end_reason_item' => item12( 424 ) ) );
$tcg_case( 'the plugin\'s own end at 0, settled there and then', 425, 2, events_row( seller_ended( item12( 425 ), 3, 1 ) ), array( '_ebay_end_reason' => 'stock_zero', '_ebay_end_reason_item' => item12( 425 ), '_ebay_stock_settled_at' => '2026-11-24 10:00:00', '_ebay_stock_settled_item' => item12( 425 ) ) );
$tcg_case( 'a later settle (settled already)', 426, 2, events_row( seller_ended( item12( 426 ), 3, 1 ) ), array( '_ebay_stock_settled_at' => '2026-11-24 10:00:00', '_ebay_stock_settled_item' => item12( 426 ) ) );
$tcg_case( 'read for its reason before', 427, 2, events_row( seller_ended( item12( 427 ), 3, 1 ) ), array( TCGiant_Sync_Inventory::END_ZERO_ASKED_META => item12( 427 ) ) );
$tcg_case( 'the product follows another listing now', 428, 2, events_row( seller_ended( item12( 999 ), 3, 1 ) ) );
check( 'Yes, and the setting could not act: no call - and the settle as 3.45.0\'s', $tcg_none, array(
	'sold out on eBay (3 of 3)'                         => array( 0, array() ),
	'more sold than listed'                             => array( 0, array() ),
	'nothing in stock here'                             => array( 0, array() ),
	'ended before the switch to Yes'                    => array( 2, array() ),
	'a reason recorded for it (End Listing here)'       => array( 2, array() ),
	'the plugin\'s own end at 0, settled there and then' => array( 2, array() ),
	'a later settle (settled already)'                  => array( 2, array() ),
	'read for its reason before'                        => array( 2, array() ),
	'the product follows another listing now'           => array( 2, array() ),
) );
zero_shop();
product( 429, 2, item12( 429 ) );
$OPTIONS[ TCGiant_Sync_API::OWN_ENDS_OPTION ] = array( item12( 429 ) => time() - 600 );
ebay_has( item12( 429 ), seller_ended( item12( 429 ), 3, 1 ) );
delta_settle( 429, events_row( seller_ended( item12( 429 ), 3, 1 ) ) );
check( '  the plugin\'s own end, its answer lost (only the mark to say so): no call', array( stock( 429 ), calls() ), array( 2, array() ) );
zero_shop();
product( 430, 2, item12( 430 ) );
delta_settle( 430, seller_ended( item12( 430 ), 3, 1 ) );
check( '  a row that does carry the reason (were eBay to send one): no call, decided from it', array( stock( 430 ), calls() ), array( 0, array() ) );
zero_shop();
product( 431, 2, item12( 431 ) );
delta_settle( 431, seller_ended( item12( 431 ), 3, 1 ), false );
check( '  GetItem\'s answer already in hand (the delta\'s category check, or a relist check, read it): no second call', array( stock( 431 ), calls() ), array( 0, array() ) );
zero_shop();
product( 432, 2, item12( 432 ) );
ebay_has( item12( 432 ), seller_ended( item12( 432 ), 3, 1, null ) );
$IMP->settle_item( item12( 432 ) );
check( '  the sale check, its own GetItem saying no reason (the listing ran its course): that call only', array( stock( 432 ), calls() ), array( 2, array( 'GetItem' ) ) );
// A row that needs GetItem for its figures anyway: that read is the one it
// always was - one that fails still stops the delta for the listing.
zero_shop();
variable( 433, item12( 433 ) );
variation( 434, 433, 'CUP-S', 2, array( 'size' => 'S' ) );
$tcg_var_row = events_row( seller_ended( item12( 433 ), 3, 1 ) );
$tcg_var_row['Variations'] = array( 'Variation' => array() );
ebay_has( item12( 433 ), $TIMEOUT, $TIMEOUT, $TIMEOUT );
$ENDED_READ_ERROR->setValue( null, null );
delta_settle( 433, $tcg_var_row );
check( '  a variation listing\'s row, its GetItem timing out: the delta stops for it as before, and no reason line', array( is_wp_error( $ENDED_READ_ERROR->getValue() ), count_logged( 'eBay could not be asked why it ended' ), stock( 434 ), meta( 433, TCGiant_Sync_Inventory::END_ZERO_ASKED_META ) ), array( true, 0, 2, null ) );
$ENDED_READ_ERROR->setValue( null, null );

// Read: what GetItem says decides.
zero_shop();
product( 440, 2, item12( 440 ) );
$tcg_no_end = seller_ended( item12( 440 ), 3, 1 );
ebay_has( item12( 440 ), $tcg_no_end );
$tcg_row = events_row( $tcg_no_end );
unset( $tcg_row['ListingDetails']['EndTime'] );
delta_settle( 440, $tcg_row );
check( 'a row with no EndTime: read, and GetItem\'s EndTime decides (after the switch: 0)', array( stock( 440 ), calls() ), array( 0, array( 'GetItem' ) ) );
zero_shop();
product( 441, 1, item12( 441 ) );
ebay_has( item12( 441 ), seller_ended( item12( 441 ), 1, 0 ) );
delta_settle( 441, events_row( seller_ended( item12( 441 ), 1, 0 ) ) );
check( 'a listing of 1 (eBay\'s row leaves Quantity out): the figures\' own GetItem, one call, not two', array( stock( 441 ), calls() ), array( 0, array( 'GetItem' ) ) );
zero_shop();
product( 442, null, item12( 442 ) );
ebay_has( item12( 442 ), seller_ended( item12( 442 ), 3, 1 ) );
delta_settle( 442, events_row( seller_ended( item12( 442 ), 3, 1 ) ) );
check( 'a stock WooCommerce does not count, in stock: read, then out of stock', array( meta( 442, '_stock_status' ), calls() ), array( 'outofstock', array( 'GetItem' ) ) );
zero_shop();
product( 443, 2, item12( 443 ) );
ebay_has( item12( 443 ), seller_ended( item12( 443 ), 3, 1, 'Sold' ) );
delta_settle( 443, events_row( seller_ended( item12( 443 ), 3, 1, 'Sold' ) ) );
check( 'read, and eBay says Sold (a sale, units left): left at 2, one call', array( stock( 443 ), calls(), zero_lines() ), array( 2, array( 'GetItem' ), 0 ) );
zero_shop();
product( 444, 2, item12( 444 ) );
ebay_has( item12( 444 ), seller_ended( item12( 444 ), 3, 1, null ) );
delta_settle( 444, events_row( seller_ended( item12( 444 ), 3, 1, null ) ) );
check( 'read, and eBay gives no reason (it ran its course): left, one call', array( stock( 444 ), calls() ), array( 2, array( 'GetItem' ) ) );
zero_shop();
product( 445, 2, item12( 445 ) );
ebay_has( item12( 445 ), seller_ended( item12( 445 ), 3, 1, 'NotAvailable', 600, array( 'ListingDetails' => array( 'RelistedItemID' => item12( 446 ) ) ) ) );
delta_settle( 445, events_row( seller_ended( item12( 445 ), 3, 1 ) ) );
check( 'read, and eBay says you relisted it: left, one call', array( stock( 445 ), calls() ), array( 2, array( 'GetItem' ) ) );

// Refused or failed: settled from the row, as before - nothing set to 0.
zero_shop();
fleet_used( 0.85 );
product( 450, 2, item12( 450 ) );
ebay_has( item12( 450 ), seller_ended( item12( 450 ), 3, 1 ) );
$tcg_used = delta_settle( 450, events_row( seller_ended( item12( 450 ), 3, 1 ) ) );
check( 'the fleet at 85% of its day: the read refused before it is sent - no call; the stock left, settled from the row', array( calls(), stock( 450 ), settled_on( 450 ), $tcg_used ), array( array(), 2, array( true, item12( 450 ) ), false ) );
check( '  said once, as a warning', array( count_logged( 'eBay could not be asked why it ended' ), logged( 'Delta: eBay item ' . item12( 450 ) . ' is Completed — eBay could not be asked why it ended (Daily API call budget exhausted. Will resume tomorrow.), so the website stock of WC #450 was left as it is: "When you end a listing on eBay, set its website stock to 0?" acts only on eBay\'s word that you ended it.', 'warning' ) ), array( 1, true ) );
check( '  the delta not stopped for it', $ENDED_READ_ERROR->getValue(), null );
fleet_used( 0.10 );
delta_settle( 450, events_row( seller_ended( item12( 450 ), 3, 1 ) ) );
check( '  never asked again, once the fleet has room: no call, no second line', array( calls(), count_logged( 'eBay could not be asked why it ended' ), stock( 450 ) ), array( array(), 1, 2 ) );
zero_shop();
product( 451, 2, item12( 451 ) );
delta_settle( 451, events_row( seller_ended( item12( 451 ), 3, 1 ) ) );
check( 'eBay refusing the read (17, not found): one call, the stock left, said once', array( calls(), stock( 451 ), count_logged( 'eBay could not be asked why it ended' ) ), array( array( 'GetItem' ), 2, 1 ) );

echo "\nAFTER THE 0, NOTHING FOR STOCK REVIEW TO PUT BACK (FIX ROUND 2)\n" . str_repeat( '=', 132 ) . "\n";

// eBay saying more unsold than the website holds - a website sale not yet on
// eBay, a hand edit down - is 3.28's record for Stock Review's "Use eBay's
// figure", and its line. Once the setting sets the stock to 0, neither: the
// shop chose 0 for this listing.
$tcg_back = array();
foreach ( array( true, false ) as $tcg_on ) {
	$tcg_tag = $tcg_on ? 'Yes' : 'No';
	zero_shop( $tcg_on );
	product( 460, 2, item12( 460 ) );
	ebay_has( item12( 460 ), seller_ended( item12( 460 ), 5, 1 ) );
	delta_settle( 460, events_row( seller_ended( item12( 460 ), 5, 1 ) ) );
	$tcg_back[ $tcg_tag ]['the delta, eBay 4 unsold, 2 here'] = array( stock( 460 ), meta( 460, '_ebay_unsold_at_end' ), count_logged( 'WooCommerce\'s figure stands' ), zero_lines(), calls(), TCGiant_Sync_Inventory::use_ebay_figure( 460 ), stock( 460 ) );
	// A website sale after the seller's end: its push reads the listing.
	zero_shop( $tcg_on );
	product( 461, 2, item12( 461 ) );
	seen( 461, item12( 461 ), 1 );
	ebay_has( item12( 461 ), seller_ended( item12( 461 ), 3, 1 ) );
	website_order( 4610, array( 461 => 1 ) );
	run_queue( true );
	$tcg_back[ $tcg_tag ]['a website sale 2 to 1 after the end'] = array( stock( 461 ), meta( 461, '_ebay_unsold_at_end' ), count_logged( 'WooCommerce\'s figure stands' ), zero_lines(), calls(), TCGiant_Sync_Inventory::use_ebay_figure( 461 ), stock( 461 ) );
}
check( 'Yes: 0 - eBay\'s figure neither kept nor said, one line, and "Use eBay\'s figure" puts nothing back', $tcg_back['Yes'], array(
	'the delta, eBay 4 unsold, 2 here'    => array( 0, null, 0, 1, array( 'GetItem' ), null, 0 ),
	'a website sale 2 to 1 after the end' => array( 0, null, 0, 1, array( 'GetItem' ), null, 0 ),
) );
check( 'No: as 3.45.0 - kept and said, and "Use eBay\'s figure" takes it', $tcg_back['No'], array(
	'the delta, eBay 4 unsold, 2 here'    => array( 2, '4', 1, 0, array(), 4, 4 ),
	'a website sale 2 to 1 after the end' => array( 1, '2', 1, 0, array( 'GetItem' ), 2, 2 ),
) );
// A record an earlier listing of the product left: it goes with the 0 too.
zero_shop();
product( 463, 2, item12( 463 ), array( '_ebay_unsold_at_end' => '7' ) );
ebay_has( item12( 463 ), seller_ended( item12( 463 ), 5, 1 ) );
delta_settle( 463, events_row( seller_ended( item12( 463 ), 5, 1 ) ) );
check( '  a record an earlier listing left: deleted with the 0', array( stock( 463 ), meta( 463, '_ebay_unsold_at_end' ), TCGiant_Sync_Inventory::use_ebay_figure( 463 ) ), array( 0, null, null ) );
// Not set to 0 - a sale (Sold, units left): the record and its line, as with No.
zero_shop();
product( 462, 2, item12( 462 ) );
settle_with( 462, seller_ended( item12( 462 ), 5, 1, 'Sold' ) );
check( '  Yes, not set to 0 (a sale with units left): kept and said, as 3.45.0', array( stock( 462 ), meta( 462, '_ebay_unsold_at_end' ), count_logged( 'WooCommerce\'s figure stands' ) ), array( 2, '4', 1 ) );

echo "\nA STOCK THE SHOP RAISES AFTER THE END STAYS (FIX ROUND 2)\n" . str_repeat( '=', 132 ) . "\n";

// 2 here; the seller ends the listing; before any sync the shop raises the
// website stock to 5. Its push is refused (the listing is over) and reads the
// listing: the first settle. Or the delta comes first. The restock is the
// shop's own, after the end: never 0.
$tcg_raise = array();
foreach ( array( true, false ) as $tcg_on ) {
	$tcg_tag = $tcg_on ? 'Yes' : 'No';
	zero_shop( $tcg_on );
	product( 470, 2, item12( 470 ) );
	seen( 470, item12( 470 ), 1 );
	ebay_has( item12( 470 ), seller_ended( item12( 470 ), 3, 1 ) );
	ebay_answers( 'ReviseInventoryStatus', refused( 291, 'You are not allowed to revise ended listings.' ) );
	hand_edit( 470, 5 );
	$tcg_marked = null !== meta( 470, TCGiant_Sync_Inventory::STOCK_RAISED_META );
	run_queue( true );
	$tcg_raise[ $tcg_tag ]['its own push'] = array( stock( 470 ), zeroed( 470 ), zero_lines(), calls(), $tcg_marked, count_logged( 'WC #470: you ended eBay listing ' . item12( 470 ) . ', but its website stock was raised after that (#470), so "When you end a listing on eBay, set its website stock to 0?" left it as it is.' ) );
	zero_shop( $tcg_on );
	product( 471, 2, item12( 471 ) );
	hand_edit( 471, 5 );
	$QUEUE = array();
	ebay_has( item12( 471 ), seller_ended( item12( 471 ), 3, 1 ) );
	delta_settle( 471, events_row( seller_ended( item12( 471 ), 3, 1 ) ) );
	$tcg_raise[ $tcg_tag ]['the delta first'] = array( stock( 471 ), zeroed( 471 ), zero_lines(), calls(), null !== meta( 471, TCGiant_Sync_Inventory::STOCK_RAISED_META ), count_logged( 'but its website stock was raised after that (#471)' ) );
}
check( 'Yes: restocked 2 to 5 after the end - never 0, settled as 3.45.0 settles it, and said', $tcg_raise['Yes'], array(
	'its own push'    => array( 2, null, 0, $tcg_raise['No']['its own push'][3], true, 1 ),
	'the delta first' => array( 2, null, 0, array( 'GetItem' ), true, 1 ),
) );
check( 'No: as 3.45.0, the same calls; no mark written', $tcg_raise['No'], array(
	'its own push'    => array( 2, null, 0, array( 'ReviseInventoryStatus', 'GetItem' ), false, 0 ),
	'the delta first' => array( 2, null, 0, array(), false, 0 ),
) );
// The mark against eBay's EndTime: a raise before the end, or in its very
// second, is not after it.
$tcg_marks = array();
foreach ( array( 'an hour before the end' => 3600, 'in the second it ended' => 0, 'a second after it' => -1 ) as $tcg_label => $tcg_before ) {
	zero_shop();
	product( 472, 2, item12( 472 ) );
	$tcg_end = seller_ended( item12( 472 ), 3, 1 );
	$META[472][ TCGiant_Sync_Inventory::STOCK_RAISED_META ] = strtotime( $tcg_end['ListingDetails']['EndTime'] ) - $tcg_before;
	settle_with( 472, $tcg_end );
	$tcg_marks[ $tcg_label ] = stock( 472 );
}
check( '  raised before the end or in its second: 0; a second after it: left', $tcg_marks, array( 'an hour before the end' => 0, 'in the second it ended' => 0, 'a second after it' => 2 ) );
zero_shop();
product( 473, 5, item12( 473 ) );
hand_edit( 473, 3 );
$tcg_down = meta( 473, TCGiant_Sync_Inventory::STOCK_RAISED_META );
zero_shop();
product( 474, 2, item12( 474 ) );
website_order( 4740, array( 474 => 1 ) );
$tcg_sale = meta( 474, TCGiant_Sync_Inventory::STOCK_RAISED_META );
zero_shop();
product( 477, 2, item12( 477 ) );
hand_edit( 477, 2 );
$tcg_same = meta( 477, TCGiant_Sync_Inventory::STOCK_RAISED_META );
zero_shop( false );
product( 475, 2, item12( 475 ) );
hand_edit( 475, 5 );
check( '  only a raise is marked, only with Yes: a hand edit down, a website sale, the same figure written again, a raise with No - no mark', array( $tcg_down, $tcg_sale, $tcg_same, meta( 475, TCGiant_Sync_Inventory::STOCK_RAISED_META ) ), array( null, null, null, null ) );
zero_shop();
product( 476, 2, item12( 476 ) );
$META[476]['_stock'] = null;
unset( $META[476]['_stock'] );
hand_edit( 476, 2 );
check( '  a stock with no row before counts from 0', null !== meta( 476, TCGiant_Sync_Inventory::STOCK_RAISED_META ), true );
// A variation restocked after the end: the product is left as 3.45.0 leaves it.
zero_shop();
variable( 480, item12( 480 ) );
variation( 481, 480, 'TEE-S', 2, array( 'size' => 'S' ) );
variation( 482, 480, 'TEE-M', 1, array( 'size' => 'M' ) );
$tcg_over = variation_listing( item12( 480 ), array( ev( 'TEE-S', 3, 1, array( 'size' => 'S' ) ), ev( 'TEE-M', 1, 0, array( 'size' => 'M' ) ) ) );
$tcg_over['SellingStatus']['ListingStatus']  = 'Completed';
$tcg_over['ListingDetails']['EndTime']      = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 );
$tcg_over['ListingDetails']['EndingReason'] = 'NotAvailable';
hand_edit( 481, 4 );
$QUEUE = array();
TCGiant_Sync_Importer::settle_ended_from_item( 480, $tcg_over, 'Delta' );
check( '  a variation restocked after the end: the product left as 3.45.0 settles it, and said', array( stock( 481 ), stock( 482 ), zeroed( 480 ), count_logged( 'but its website stock was raised after that (#481)' ) ), array( 2, 1, null, 1 ) );
// Set to 0, then restocked: its push and a later delta leave it.
zero_shop();
product( 485, 2, item12( 485 ) );
settle_with( 485, seller_ended( item12( 485 ), 3, 1 ) );
hand_edit( 485, 4 );
ebay_has( item12( 485 ), seller_ended( item12( 485 ), 3, 1 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 291, 'You are not allowed to revise ended listings.' ) );
run_queue( true );
delta_settle( 485, events_row( seller_ended( item12( 485 ), 3, 1 ) ) );
check( 'set to 0, then restocked to 4: its push and a later delta leave the 4', array( stock( 485 ), zero_lines() ), array( 4, 1 ) );

echo "\nA RAISE FROM eBAY IS NOT THE SHOP'S (FIX ROUND 3)\n" . str_repeat( '=', 132 ) . "\n";

// The plugin's own writes from eBay go through begin_ebay_origin(): the
// import of a live listing (WooCommerce's data store, as it saves a stock,
// played), Stock Review's "Use eBay's figure". They write no mark.
zero_shop();
$SAVE_FIRES = true;
product( 486, 2, item12( 486 ), array( '_sku' => 'COIN-486' ) );
import_listing( listing( item12( 486 ), 4, 0, array( 'Title' => 'Coin 486', 'SKU' => 'COIN-486' ) ) );
$SAVE_FIRES = false;
$tcg_import = array( stock( 486 ), meta( 486, TCGiant_Sync_Inventory::STOCK_RAISED_META ) );
zero_shop();
product( 487, 1, item12( 487 ), array( '_ebay_listing_status' => 'Ended', '_ebay_unsold_at_end' => '3' ) );
TCGiant_Sync_Inventory::use_ebay_figure( 487 );
check( 'an import raising 2 to 4 from eBay, and "Use eBay\'s figure" raising 1 to 3: no mark - not the shop\'s restock', array( $tcg_import, array( stock( 487 ), meta( 487, TCGiant_Sync_Inventory::STOCK_RAISED_META ) ) ), array( array( 4, null ), array( 3, null ) ) );
// That import after the seller's end (a full sync's page read while the
// listing was live, or a relist adopted), then the delta's row of the end.
zero_shop();
$SAVE_FIRES = true;
product( 488, 2, item12( 488 ), array( '_sku' => 'COIN-488' ) );
import_listing( listing( item12( 488 ), 4, 0, array( 'Title' => 'Coin 488', 'SKU' => 'COIN-488' ) ) );
$SAVE_FIRES = false;
$WIRE = array();
$QUEUE = array();
ebay_has( item12( 488 ), seller_ended( item12( 488 ), 4, 0, 'NotAvailable', 30 ) );
delta_settle( 488, events_row( seller_ended( item12( 488 ), 4, 0, 'NotAvailable', 30 ) ) );
check( '  that import after the seller\'s end, then the delta: 0, as for any end', array( stock( 488 ), zeroed( 488 )['was'] ?? null, count_logged( 'was raised after that' ), calls() ), array( 0, array( 488 => 4 ), 0, array( 'GetItem' ) ) );
// WooCommerce's own restock of an order after the end - cancelled, failed,
// refunded - is a raise like any other: the stock stays (the safe way),
// and the readme says so.
zero_shop();
product( 489, 3, item12( 489 ) );
seen( 489, item12( 489 ), 0 );
$tcg_order = website_order( 4890, array( 489 => 1 ) );
run_queue( true );
$WIRE = array();
wc_increase_stock_levels( $tcg_order );
$QUEUE = array();
ebay_has( item12( 489 ), seller_ended( item12( 489 ), 6, 0, 'NotAvailable', 300 ) );
delta_settle( 489, events_row( seller_ended( item12( 489 ), 6, 0, 'NotAvailable', 300 ) ) );
check( 'a website order cancelled after the end (2 to 3, WooCommerce\'s own restock): 3 stays, said - not special-cased', array( stock( 489 ), zeroed( 489 ), count_logged( 'but its website stock was raised after that (#489)' ) ), array( 3, null, 1 ) );

echo "\nA LISTING THAT RAN ITS TIME IS NOT READ (FIX ROUND 2)\n" . str_repeat( '=', 132 ) . "\n";

// The full sync's rows (GetSellerList, ReturnAll) carry StartTime and
// ListingDuration: a fixed-length listing whose EndTime is its scheduled end
// ran its time - nobody ended it early - so there is nothing to read.
$tcg_ran = array();
foreach ( array(
	'7 days, ran 7'                       => array( 'Days_7', 7 * DAY_IN_SECONDS, null, 'FixedPriceItem' ),
	'an auction of 7 days, ran 7'         => array( 'Days_7', 7 * DAY_IN_SECONDS, null, 'Chinese' ),
	'30 days, ran 30 less 30 seconds'     => array( 'Days_30', 30 * DAY_IN_SECONDS - 30, null, 'FixedPriceItem' ),
	'7 days, ended after 3'               => array( 'Days_7', 3 * DAY_IN_SECONDS, 'NotAvailable', 'FixedPriceItem' ),
	'7 days, ended 2 minutes early'       => array( 'Days_7', 7 * DAY_IN_SECONDS - 120, 'NotAvailable', 'FixedPriceItem' ),
	'Good \'Til Cancelled'                => array( 'GTC', 40 * DAY_IN_SECONDS, 'NotAvailable', 'FixedPriceItem' ),
	'no length given'                     => array( '', 7 * DAY_IN_SECONDS, 'NotAvailable', 'FixedPriceItem' ),
) as $tcg_label => $tcg_c ) {
	zero_shop();
	product( 490, 2, item12( 490 ) );
	$tcg_item = ran_for( 490, $tcg_c[0], $tcg_c[1], $tcg_c[2], $tcg_c[3] );
	ebay_has( item12( 490 ), $tcg_item );
	full_sync_settle( 490, $tcg_item );
	$tcg_ran[ $tcg_label ] = array( stock( 490 ), calls() );
}
zero_shop();
product( 490, 2, item12( 490 ) );
$tcg_item = ran_for( 490, 'Days_7', 7 * DAY_IN_SECONDS, 'NotAvailable' );
unset( $tcg_item['ListingDetails']['StartTime'] );
ebay_has( item12( 490 ), $tcg_item );
full_sync_settle( 490, $tcg_item );
$tcg_ran['no StartTime'] = array( stock( 490 ), calls() );
check( 'the full sync\'s rows: one that ran its time, no read; one ended early, Good \'Til Cancelled, or without its length or start, read', $tcg_ran, array(
	'7 days, ran 7'                   => array( 2, array() ),
	'an auction of 7 days, ran 7'     => array( 2, array() ),
	'30 days, ran 30 less 30 seconds' => array( 2, array() ),
	'7 days, ended after 3'           => array( 0, array( 'GetItem' ) ),
	'7 days, ended 2 minutes early'   => array( 0, array( 'GetItem' ) ),
	'Good \'Til Cancelled'            => array( 0, array( 'GetItem' ) ),
	'no length given'                 => array( 0, array( 'GetItem' ) ),
	'no StartTime'                    => array( 0, array( 'GetItem' ) ),
) );
// The saving: 20 listings that ran their time on the full sync's pages.
zero_shop();
for ( $tcg_i = 0; $tcg_i < 20; $tcg_i++ ) {
	product( 600 + $tcg_i, 2, item12( 600 + $tcg_i ) );
	$tcg_item = ran_for( 600 + $tcg_i, 'Days_10', 10 * DAY_IN_SECONDS, null );
	ebay_has( item12( 600 + $tcg_i ), $tcg_item );
	full_sync_settle( 600 + $tcg_i, $tcg_item );
}
$tcg_full_calls = count( calls() );
zero_shop();
for ( $tcg_i = 0; $tcg_i < 20; $tcg_i++ ) {
	product( 600 + $tcg_i, 2, item12( 600 + $tcg_i ) );
	$tcg_item = ran_for( 600 + $tcg_i, 'Days_10', 10 * DAY_IN_SECONDS, null );
	ebay_has( item12( 600 + $tcg_i ), $tcg_item );
	delta_settle( 600 + $tcg_i, events_row( $tcg_item ) );
}
check( '  20 that ran their time: the full sync reads none (fix round 1 read all 20); the delta\'s rows carry no length, so it still reads each', array( $tcg_full_calls, count( calls() ) ), array( 0, 20 ) );

echo "\nEACH SETTLE PATH, YES AND NO\n" . str_repeat( '=', 132 ) . "\n";

// The answer each path has, and the calls it makes either way: the setting
// adds one GetItem only to the delta's and the full sync's rows (above).
$tcg_paths = array();
foreach ( array( true, false ) as $tcg_on ) {
	$tcg_tag = $tcg_on ? 'Yes' : 'No';

	// The delta: its row, as eBay's list of changes (GetSellerEvents) gives it.
	zero_shop( $tcg_on );
	product( 400, 2, '110000000400' );
	ebay_has( '110000000400', seller_ended( '110000000400', 3, 1 ) );
	delta_settle( 400, events_row( seller_ended( '110000000400', 3, 1 ) ) );
	$tcg_paths[ $tcg_tag ]['delta'] = array( stock( 400 ), calls(), pushes() );

	// The sale check (order sync's settle): its own GetItem.
	zero_shop( $tcg_on );
	product( 401, 2, '110000000401' );
	ebay_has( '110000000401', seller_ended( '110000000401', 3, 1 ) );
	$IMP->settle_item( '110000000401' );
	$tcg_paths[ $tcg_tag ]['sale check'] = array( stock( 401 ), calls(), pushes() );

	// A website sale's stock push, which reads its listing first, finding it over.
	zero_shop( $tcg_on );
	product( 402, 3, '110000000402' );
	ebay_has( '110000000402', seller_ended( '110000000402', 3, 0 ) );
	website_order( 4020, array( 402 => 1 ) );
	run_queue();
	$tcg_paths[ $tcg_tag ]['stock push'] = array( stock( 402 ), calls(), pushes() );

	// The import of a listing found ended (Sync specific items, a queued import).
	zero_shop( $tcg_on );
	product( 403, 2, '110000000403' );
	ebay_has( '110000000403', seller_ended( '110000000403', 3, 1 ) + array( 'Title' => 'Coin 403' ) );
	$IMP->process_item_import( '110000000403' );
	$tcg_paths[ $tcg_tag ]['import'] = array( stock( 403 ), calls(), pushes() );

	// The delta's row, then the sale check: the delta's settle was the first.
	zero_shop( $tcg_on );
	product( 404, 2, '110000000404' );
	ebay_has( '110000000404', seller_ended( '110000000404', 3, 1, null ), seller_ended( '110000000404', 3, 1 ) );
	delta_settle( 404, events_row( seller_ended( '110000000404', 3, 1 ) ) );
	$IMP->settle_item( '110000000404' );
	$tcg_paths[ $tcg_tag ]['delta (eBay then gives no reason), then the sale check'] = array( stock( 404 ), calls(), pushes() );
}
check( 'Yes: the delta, the sale check, a stock push\'s read, the import - each to 0, each with one GetItem', $tcg_paths['Yes'], array(
	'delta'                                                   => array( 0, array( 'GetItem' ), array() ),
	'sale check'                                              => array( 0, array( 'GetItem' ), array() ),
	'stock push'                                              => array( 0, array( 'GetItem' ), array() ),
	'import'                                                  => array( 0, array( 'GetItem' ), array() ),
	'delta (eBay then gives no reason), then the sale check' => array( 2, array( 'GetItem', 'GetItem' ), array() ),
) );
check( 'No: each as 3.45.0 - the stock the settle leaves; the delta no call', $tcg_paths['No'], array(
	'delta'                                                   => array( 2, array(), array() ),
	'sale check'                                              => array( 2, array( 'GetItem' ), array() ),
	'stock push'                                              => array( 2, array( 'GetItem' ), array() ),
	'import'                                                  => array( 2, array( 'GetItem' ), array() ),
	'delta (eBay then gives no reason), then the sale check' => array( 2, array( 'GetItem' ), array() ),
) );

echo "\nTHE SETTING, SAVED\n" . str_repeat( '=', 132 ) . "\n";

zero_shop( false );
$saved = TCGiant_Sync_Admin::instance()->sanitize_settings( array( '_tcg_form' => 'import', 'end_zeroes_stock' => '1' ) );
check( 'the form\'s sanitize alone records nothing: the write that follows does (fix round 2)', array( $saved['end_zeroes_stock'] ?? null, array_key_exists( TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION, $OPTIONS ), count_logged( 'is now Yes' ) ), array( '1', false, 0 ) );
write_settings( $saved );
check( 'saved Yes from Settings: stored "1"', $OPTIONS['tcgiant_sync_ebay_settings']['end_zeroes_stock'] ?? null, '1' );
check( '  the moment it was switched on recorded, now', abs( (int) ( $OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] ?? 0 ) - time() ) <= 2, true );
check( '  kept apart from the autoloaded options', $OPTIONS['__autoload'][ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] ?? 'not said', false );
check( '  one log line', array( count_logged( 'Settings: "When you end a listing on eBay, set its website stock to 0?" is now Yes.' ), logged( 'Settings: "When you end a listing on eBay, set its website stock to 0?" is now Yes. A listing you end on eBay from now on - in Seller Hub, or with another tool - has its website stock set to 0 when the sync brings its end in. Nothing that ended before now is changed, and nothing is sent to eBay.' ) ), array( 1, true ) );
check( '  nothing queued or sent, no stock written', array( pushes(), calls(), $WRITES ), array( array(), array(), array() ) );
$OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] = $SINCE;
$OPTIONS[ TCGiant_Sync_API::OWN_ENDS_OPTION ] = array( '110000000500' => time() );
save_settings_form( array( '_tcg_form' => 'import', 'end_zeroes_stock' => '1', 'sync_interval' => 'hourly' ) );
check( 'saved again, still Yes: the moment stands, no line', array( $OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ], count_logged( 'is now Yes' ) ), array( $SINCE, 1 ) );
$saved = save_settings_form( array( '_tcg_form' => 'import', 'end_zeroes_stock' => '0' ) );
check( 'saved No: the moment and the plugin\'s own ends forgotten', array( $saved['end_zeroes_stock'] ?? null, array_key_exists( TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION, $OPTIONS ), array_key_exists( TCGiant_Sync_API::OWN_ENDS_OPTION, $OPTIONS ) ), array( '0', false, false ) );
check( '  one line', array( count_logged( 'is now No' ), logged( 'Settings: "When you end a listing on eBay, set its website stock to 0?" is now No: a listing you end on eBay keeps its website stock, as before.' ) ), array( 1, true ) );
save_settings_form( array( '_tcg_form' => 'import', 'end_zeroes_stock' => '1' ) );
check( 'Yes again: a new moment, so ends while it was No are never set to 0', abs( (int) ( $OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] ?? 0 ) - time() ) <= 2, true );
zero_shop( false );
save_settings_form( array( '_tcg_form' => 'import', 'sync_interval' => 'hourly' ) );
save_settings_form( array( '_tcg_form' => 'import', 'end_zeroes_stock' => '0' ) );
check( 'saved without it, or No from No: nothing recorded, no line', array( array_key_exists( TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION, $OPTIONS ), count_logged( 'set its website stock to 0?" is now' ) ), array( false, 0 ) );
zero_shop( false );
save_settings_form( array( '_tcg_form' => 'export', 'end_zeroes_stock' => '1' ) );
check( 'posted from the listing settings\' form: not taken, nothing recorded', array_key_exists( TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION, $OPTIONS ), false );
check( 'one of the stock settings\' form\'s own', in_array( 'end_zeroes_stock', TCGiant_Sync_Admin::SETTINGS_FORMS['import'], true ), true );

echo "\nSWITCHED WITHOUT THE SETTINGS SCREEN (FIX ROUND 2)\n" . str_repeat( '=', 132 ) . "\n";

check( 'every write of the settings is heard - update_option and add_option, with both values', array(
	in_array( array( array( 'TCGiant_Sync_Inventory', 'end_zero_option_updated' ), 2, 10 ), harness_listeners( 'update_option_tcgiant_sync_ebay_settings' ), true ),
	in_array( array( array( 'TCGiant_Sync_Inventory', 'end_zero_option_added' ), 2, 10 ), harness_listeners( 'add_option_tcgiant_sync_ebay_settings' ), true ),
), array( true, true ) );
check( '  and the Settings form no longer records it itself, so a save says it once', substr_count( (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' ), 'end_zero_saved' ), 0 );
// WP-CLI (wp option patch), another plugin, the REST settings route: No, then Yes.
zero_shop();
$OPTIONS[ TCGiant_Sync_API::OWN_ENDS_OPTION ] = array( item12( 700 ) => time() );
$tcg_stored = $OPTIONS['tcgiant_sync_ebay_settings'];
write_settings( array( 'end_zeroes_stock' => '0' ) + $tcg_stored );
check( 'written No outside Settings: the moment and the plugin\'s own ends forgotten, said once', array( array_key_exists( TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION, $OPTIONS ), array_key_exists( TCGiant_Sync_API::OWN_ENDS_OPTION, $OPTIONS ), count_logged( 'is now No' ) ), array( false, false, 1 ) );
write_settings( array( 'end_zeroes_stock' => '1' ) + $tcg_stored );
check( '  then Yes: a new moment, now, said once', array( abs( (int) ( $OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] ?? 0 ) - time() ) <= 2, count_logged( 'is now Yes' ) ), array( true, 1 ) );
// Switched on three hours ago, then off and on again: a listing that ended
// ten minutes ago - before the switch back - is left, and not read.
zero_shop();
$OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] = time() - 3 * HOUR_IN_SECONDS;
$tcg_stored = $OPTIONS['tcgiant_sync_ebay_settings'];
write_settings( array( 'end_zeroes_stock' => '0' ) + $tcg_stored );
write_settings( array( 'end_zeroes_stock' => '1' ) + $tcg_stored );
product( 701, 2, item12( 701 ) );
ebay_has( item12( 701 ), seller_ended( item12( 701 ), 3, 1 ) );
delta_settle( 701, events_row( seller_ended( item12( 701 ), 3, 1 ) ) );
check( '  on, off and on again outside Settings: an end before the switch back is left, no call', array( stock( 701 ), zeroed( 701 ), calls() ), array( 2, null, array() ) );
zero_shop();
write_settings( array( 'access_token' => 'harness-access-2' ) + $OPTIONS['tcgiant_sync_ebay_settings'] );
check( 'a write that leaves it as it was (a token refresh): the moment stands, no line', array( $OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ], count_logged( 'set its website stock to 0?" is now' ) ), array( $SINCE, 0 ) );
zero_shop( false );
unset( $OPTIONS['tcgiant_sync_ebay_settings'] );
write_settings( array( 'end_zeroes_stock' => '1' ) );
check( 'the settings written for the first time, Yes (add_option): the moment, now, said', array( abs( (int) ( $OPTIONS[ TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION ] ?? 0 ) - time() ) <= 2, count_logged( 'is now Yes' ) ), array( true, 1 ) );
zero_shop( false );
unset( $OPTIONS['tcgiant_sync_ebay_settings'] );
write_settings( array( 'end_zeroes_stock' => '0' ) );
check( '  No: nothing recorded, no line', array( array_key_exists( TCGiant_Sync_Inventory::END_ZERO_SINCE_OPTION, $OPTIONS ), count_logged( 'set its website stock to 0?" is now' ) ), array( false, 0 ) );

echo "\nTHE WORDS\n" . str_repeat( '=', 132 ) . "\n";

$tcg_view = (string) file_get_contents( $ROOT . '/admin/views/settings.php' );
check( 'the field, beside "Remove products when their eBay listing sells out?", No unless chosen', array(
	false !== strpos( $tcg_view, "esc_html_e( 'When you end a listing on eBay, set its website stock to 0?', 'tcgiant-sync' )" ),
	strpos( $tcg_view, 'When you end a listing on eBay, set its website stock to 0?' ) > strpos( $tcg_view, 'Remove products when their eBay listing sells out?' ),
	strpos( $tcg_view, 'When you end a listing on eBay, set its website stock to 0?' ) < strpos( $tcg_view, 'Auto-relist ended listings?' ),
	substr_count( $tcg_view, "checked( \$settings['end_zeroes_stock'] ?? '0', '1' )" ),
	substr_count( $tcg_view, "checked( \$settings['end_zeroes_stock'] ?? '0', '0' )" ),
), array( true, true, true, 1, 1 ) );
check( '  its hint: Seller Hub or another tool, never a sale, the plugin\'s own end or a restock, from Yes on, the read, a listing listed again', false !== strpos( $tcg_view, "For a listing you end yourself on eBay, in Seller Hub or another tool, while your website still has stock: once the sync brings the end in, the website stock is set to 0, so it stops selling here too. Never for an eBay sale, a listing TCGiant Sync ends, or stock you add back after the end. Only listings you end after choosing Yes. While your website has stock, the sync reads an ended listing with unsold units once from eBay, to see whether you ended it: one eBay call each, eBay\\'s own ends and relists included. If you end a listing to list it again (Sell Similar, say), its website stock may go to 0 until the sync imports the new listing." ), true );
check( '  "Remove products when their eBay listing sells out?" and Stock Review\'s Settle no longer promise an end of yours keeps its stock (fix round 2)', array(
	false !== strpos( $tcg_view, 'one that ran out keeps its product and whatever stock was unsold, and so does one you ended yourself - unless you chose Yes for "When you end a listing on eBay, set its website stock to 0?" below, which sets that stock to 0.' ),
	false !== strpos( (string) file_get_contents( $ROOT . '/admin/views/stock-review.php' ), 'one you ended yourself with items unsold keeps them, because they are still yours to sell - unless you chose Yes for "When you end a listing on eBay, set its website stock to 0?" (Settings) before you ended it, which sets that stock to 0.' ),
), array( true, true ) );
$tcg_readme    = (string) file_get_contents( $ROOT . '/readme.txt' );
$tcg_changelog = (string) file_get_contents( $ROOT . '/changelog.txt' );
check( 'the readme\'s 3.46.0 entry (dated or not) and its upgrade notice', array( 1 === preg_match( '/^= 3\.46\.0 - (?:YYYY-MM-DD|\d{4}-\d\d-\d\d) =$/m', $tcg_readme ), 1 === preg_match( '/^= 3\.46\.0 =$/m', $tcg_readme ) ), array( true, true ) );
check( '  the changelog\'s (dated or not)', 1 === preg_match( '/^(?:YYYY-MM-DD|\d{4}-\d\d-\d\d) - version 3\.46\.0$/m', $tcg_changelog ), true );
check( '  each names the setting', array( false !== strpos( $tcg_readme, '"When you end a listing on eBay, set its website stock to 0?"' ), false !== strpos( $tcg_changelog, 'end_zeroes_stock' ) ), array( true, true ) );
check( '  the readme: a restock stays, a shared stock, the read for each ended listing with units unsold, a Relist, Push to eBay or Auto-Relist, the email (fix round 2)', array(
	false !== strpos( $tcg_readme, 'Once a listing, and never stock added back after you end it: raise the stock after the end and it is not set to 0 (when the sync brings the end in it still lowers the stock to what eBay had left unsold, as before); raise it after the sync set it to 0 and it stays.' ),
	false !== strpos( $tcg_readme, 'its shared stock, with every variation that sells from it; a variation that keeps its own stock and is not on the listing, or that you disabled, keeps it.' ),
	false !== strpos( $tcg_readme, 'one eBay call for each listing that ends with units unsold while your website has stock - your own ends, eBay\'s and relists alike' ),
	false !== strpos( $tcg_readme, 'A Relist, Push to eBay or Auto-Relist that reaches the ended listing first leaves the stock as before too, and so does' ),
	false !== strpos( $tcg_readme, 'WooCommerce sends its own out-of-stock email for each, as for any stock that reaches 0.' ),
	false === strpos( $tcg_readme, 'one eBay call per listing you end' ),
), array( true, true, true, true, true, true ) );
check( '  and fix round 3\'s: none for a listing that sold out (a Sold end with units left is read); an order\'s own restock keeps the stock; a variable product\'s raise keeps all its stocks from 0', array(
	false !== strpos( $tcg_readme, 'none for a listing that sold out, one TCGiant Sync ended,' ),
	false === strpos( $tcg_readme, 'none for a sale,' ),
	false !== strpos( $tcg_readme, 'and so does any raise of the stock after the end - WooCommerce\'s own restock of a cancelled, failed or refunded order included.' ),
	false !== strpos( $tcg_readme, 'For a variable product, a raise of any one of its stocks on the listing keeps all of them from being set to 0.' ),
), array( true, true, true, true ) );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
