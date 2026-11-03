<?php
/**
 * Harness: the eBay price on eBay Motors (3.29.0 with 3.28.9).
 *
 * 3.28.9 lists eBay Motors Parts & Accessories through X-EBAY-API-SITEID 100
 * (TCGiant_Sync_Exporter::settle_motors(), on_listing_site()), and a revise or
 * relist eBay refuses on one site for the site goes once more to the other,
 * with the same body (TCGiant_Sync_Motors::listing_call()). Every one of those
 * bodies is built by build_item_xml(), so its prices come from
 * TCGiant_Sync_Ebay_Price::for_product() as on eBay US: the rule's price, or
 * the product's own, on an Add and a Revise on site 100, the same price in
 * the revise sent again on site 0, none on a Relist, and Best Offer's share
 * of the eBay price; with no rule, the price 3.28.9 sends.
 *
 * The world is test-motors.php's: template-world with
 * support/motors-api.php's eBay, which records each Trading call's site.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS         = array();
	const AUCTION_LISTED_KEY  = '_ebay_auction_listed_item';
	const AUCTION_FIGURE_KEYS = array();
	public static function __callStatic( $name, $args ) { return null; }
	public static function restocked_after_website_sale( $product_id ) { return 0; }
	public static function listing_already_settled( $product_id, $item_id ) { return false; }
	public static function ended_by_shop( $product_id, $item_id ) { return false; }
	public static function apply_ended_listing_stock( $product_id, $listed, $sold, $item = null ) { return (int) wc_get_product( $product_id )->get_stock_quantity(); }
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
	public static function table_exists() { return false; }
	public static function get_by_product( $id ) { return null; }
}
function get_post_status( $id ) { return 'publish'; }
function get_post_field( $field, $id ) { return ''; }
function get_attached_file( $id ) { return ''; }

require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-listing-link.php';
require __DIR__ . '/support/motors-api.php';

$API = $GLOBALS['API'];

/** The parts maker's shop on eBay US, its category 35560 on eBay Motors, and these. */
function parts_shop( array $more = array() ) {
	TCGiant_Sync_OAuth::$settings = $more + array(
		'marketplace'               => 'EBAY_US',
		'export_category_id'        => '35560',
		'export_category_typed'     => '35560',
		'export_condition_id'       => '1000',
		'export_location'           => 'Leeds, AL',
		'export_postal_code'        => '35094',
		'export_fulfillment_policy' => '250000021',
		'export_return_policy'      => '250000022',
		'export_payment_policy'     => '250000023',
		'export_listing_type'       => 'FixedPriceItem',
		'export_listing_duration'   => 'GTC',
	);
}
/** eBay as it answers for 35560: a Motors Parts & Accessories category. */
function motors_ebay() {
	$api = $GLOBALS['API'];
	$api->clear();
	$api->answers      = array();
	$api->site_answers = array();
	$api->tree_aspects = array(
		'0'   => array( '35560' => motors_error( 62005 ) ),
		'100' => array( '35560' => array( array( 'name' => 'Brand', 'required' => true ) ) ),
	);
	$api->nodes   = array( '35560' => motors_node( '35560', 'Vintage Motorcycle Parts', '10063' ) );
	$api->suggest = array( 'Vintage Motorcycle Parts' => array( array( 'id' => '35560', 'name' => 'eBay Motors > Parts & Accessories > Motorcycle & Scooter Parts > Vintage Motorcycle Parts', 'ancestors' => array( '10063', '6028', '6000' ) ) ) );
	$api->conds   = array( '35560' => motors_conditions( '35560', array( '1000' => 'New', '3000' => 'Used' ) ) );
	$GLOBALS['W']['transients'] = array();
	$GLOBALS['W']['log']        = array();
	delete_option( TCGiant_Sync_Motors::OPTION );
	delete_option( TCGiant_Sync_Motors::SITES_OPTION );
}
/** A part with a photo, a Brand, and these meta. */
function part( $id, array $meta = array(), array $data = array() ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/part-' . $id . '.jpg';
	$product = new WC_Product( $id, array_merge( array( 'name' => 'Rocker cover ' . $id, 'description' => 'A part.', 'regular_price' => '85.00', 'stock' => 3, 'sku' => 'RC-' . $id, 'image_id' => 9000 + $id, 'attributes' => array( new WC_Product_Attribute( 'Brand', array( 'Triumph' ) ) ) ), $data ) );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
/** A push: what it answered, each Trading call as Call@site, and the calls' bodies. */
function push( WC_Product $product ) {
	$GLOBALS['API']->clear();
	$res = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	return array( is_wp_error( $res ) ? $res->get_error_code() : ( $res['action'] ?? null ), $GLOBALS['API']->sites, $GLOBALS['API']->calls );
}
/** A body's StartPrices. */
function start_prices( $body ) {
	preg_match_all( '#<StartPrice>([^<]*)</StartPrice>#', (string) $body, $m );
	return $m[1];
}
/** A body's element, by name. */
function element( $body, $name ) {
	return preg_match( '#<' . $name . '>([^<]*)</' . $name . '>#', (string) $body, $m ) ? $m[1] : null;
}

$PUSHED = array( '_ebay_site' => 'eBayMotors', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-10-31 10:00:00', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' );
$PLUS10 = array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA NEW LISTING AND AN UPDATE ON EBAY MOTORS (SITE 100)\n" . str_repeat( '=', 124 ) . "\n";

parts_shop();
motors_ebay();
list( $code, $sites, $calls ) = push( part( 801 ) );
check( 'no rule: AddFixedPriceItem with 100, Site eBayMotors, at its regular price, 85.00, as 3.28.9 sends it', array( $code, $sites, element( $calls[0][1] ?? '', 'Site' ), start_prices( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@100' ), 'eBayMotors', array( '85.00' ) ) );
parts_shop( $PLUS10 );
list( $code, $sites, $calls ) = push( part( 802 ) );
check( 'plus 10%: AddFixedPriceItem with 100, at the rule\'s 93.50', array( $code, $sites, start_prices( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@100' ), array( '93.50' ) ) );
list( $code, $sites, $calls ) = push( part( 803, array( '_ebay_item_id' => '110000000803' ) + $PUSHED ) );
check( '  its Update: ReviseFixedPriceItem with 100, at 93.50', array( $code, $sites, start_prices( $calls[0][1] ?? '' ) ), array( 'updated', array( 'ReviseFixedPriceItem@100' ), array( '93.50' ) ) );
list( $code, $sites, $calls ) = push( part( 804, array( '_ebay_item_id' => '110000000804', '_ebay_export_price' => '99.00' ) + $PUSHED ) );
check( '  an eBay price of its own, 99.00: that, rule or no rule', array( $code, $sites, start_prices( $calls[0][1] ?? '' ) ), array( 'updated', array( 'ReviseFixedPriceItem@100' ), array( '99.00' ) ) );
list( $code, $sites, $calls ) = push( part( 805, array( '_ebay_export_price' => '0.50' ) ) );
check( '  an eBay price of its own of 0.50: refused before eBay is asked, on Motors as on eBay US', array( $code, $sites ), array( 'ebay_price_too_low', array() ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE REVISE SENT AGAIN ON THE OTHER SITE: THE SAME BODY, THE SAME PRICE\n" . str_repeat( '=', 124 ) . "\n";

motors_ebay();
$API->site_answers = array( 'ReviseFixedPriceItem@100' => trading_error( 94, 'This listing cannot be edited or relisted because it was not created on this eBay site.' ) );
list( $code, $sites, $calls ) = push( part( 811, array( '_ebay_item_id' => '110000000811' ) + $PUSHED ) );
check( 'plus 10%, eBay refusing 100 for the site: sent again with 0, the body byte for byte the first, at 93.50 both times', array( $code, $sites, ( $calls[0][1] ?? 'a' ) === ( $calls[1][1] ?? 'b' ), start_prices( $calls[0][1] ?? '' ), start_prices( $calls[1][1] ?? '' ) ), array( 'updated', array( 'ReviseFixedPriceItem@100', 'ReviseFixedPriceItem@0' ), true, array( '93.50' ), array( '93.50' ) ) );
part( 812, array( '_ebay_item_id' => '110000000812' ) + $PUSHED );
exporter()->process_single_push( 812 );
check( '  the price eBay took noted once, as for any push', array( get_post_meta( 812, '_ebay_price_seen', true ), get_post_meta( 812, '_ebay_price_marked', true ) ), array( '93.50', '1' ) );
$API->site_answers = array();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA RELIST ON EBAY MOTORS KEEPS EBAY'S PRICE\n" . str_repeat( '=', 124 ) . "\n";

motors_ebay();
$ended = array( 'ItemID' => '110000000821', 'Site' => 'eBayMotors', 'ListingType' => 'FixedPriceItem', 'Quantity' => '3', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0' ), 'PrimaryCategory' => array( 'CategoryID' => '35560', 'CategoryName' => 'eBay Motors:Parts & Accessories:Motorcycle & Scooter Parts:Vintage Motorcycle Parts' ) );
part( 821, array( '_ebay_item_id' => '110000000821', '_ebay_listing_status' => 'Ended' ) + $PUSHED );
$API->answers = array( 'GetItem' => array( 'Ack' => 'Success', 'Item' => $ended ), 'RelistFixedPriceItem' => array( 'Ack' => 'Success', 'ItemID' => '110000000921' ) );
$API->clear();
$relisted = exporter()->relist_ended_listing( 821, 'Relist' );
$relist   = end( $API->calls );
check( 'plus 10%, Relist: RelistFixedPriceItem with 100, and no StartPrice - eBay keeps its own, as on eBay US', array( is_array( $relisted ) ? $relisted['item_id'] : null, $API->sites, start_prices( $relist[1] ?? '' ) ), array( '110000000921', array( 'GetItem@0', 'RelistFixedPriceItem@100' ), array() ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nBEST OFFER (3.28.9'S ELEMENTS) ON A SHARE OF THE EBAY PRICE\n" . str_repeat( '=', 124 ) . "\n";

motors_ebay();
parts_shop( $PLUS10 + array( 'best_offer_enabled' => '1', 'best_offer_auto_accept' => '0.9', 'best_offer_auto_decline' => '50' ) );
list( $code, $sites, $calls ) = push( part( 831 ) );
$add = $calls[0][1] ?? '';
check( 'a new listing with Settings\' Best Offer, plus 10%: auto-accept at 0.9 of 93.50, 84.15; the floor of 50 an amount', array( $code, start_prices( $add ), element( $add, 'BestOfferAutoAcceptPrice' ), element( $add, 'MinimumBestOfferPrice' ) ), array( 'created', array( '93.50' ), '84.15', '50.00' ) );
parts_shop( array( 'best_offer_enabled' => '1', 'best_offer_auto_accept' => '0.9', 'best_offer_auto_decline' => '50' ) );
list( , , $calls ) = push( part( 832 ) );
check( '  no rule: 0.9 of 85.00, 76.50, as 3.28.9 sends it', array( start_prices( $calls[0][1] ?? '' ), element( $calls[0][1] ?? '', 'BestOfferAutoAcceptPrice' ) ), array( array( '85.00' ), '76.50' ) );
parts_shop( $PLUS10 + array( 'best_offer_enabled' => '1', 'best_offer_auto_accept' => '0.9' ) );
list( , , $calls ) = push( part( 833, array( '_ebay_item_id' => '110000000833' ) + $PUSHED ) );
check( '  Settings\' Best Offer on an Update: none, as 3.28.9 decided; the price still the rule\'s', array( start_prices( $calls[0][1] ?? '' ), false !== strpos( (string) ( $calls[0][1] ?? '' ), 'BestOffer' ) ), array( array( '93.50' ), false ) );
parts_shop();

done();
