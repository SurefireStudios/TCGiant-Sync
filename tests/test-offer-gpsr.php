<?php
/**
 * Harness: the Best Offer and GPSR defaults in Settings reach eBay (3.28.9).
 *
 * Settings has offered "Enable Best Offer on new listings", auto-accept and
 * auto-decline thresholds, and GPSR's manufacturer, address and EU
 * Responsible Person since 3.x, and none of it left Settings:
 * TCGiant_Sync_Exporter::get_export_settings() never copied the keys. Only a
 * product's own _tcgiant_best_offer_enabled worked. And what the build would
 * have sent was not eBay's: the auto-accept went as MinimumBestOfferPrice
 * (eBay's auto-DECLINE floor), the auto-decline as BestOfferAutoDeclinePrice
 * (no such field), and the GPSR as a ProductSafety of Component Type/Value
 * pairs (eBay's ProductSafety holds pictograms and statements).
 *
 * Now, checked against AddFixedPriceItem's reference:
 * - a product that follows the shop's defaults (none of its own listing, or
 *   one the plugin pushed) carries them; an imported one does not (decision
 *   15), unless it sets its own Best Offer;
 * - Best Offer goes to new listings and Verify only, as its label says;
 *   auto-accept as ListingDetails.BestOfferAutoAcceptPrice, auto-decline as
 *   ListingDetails.MinimumBestOfferPrice; a threshold not above 0 and below
 *   the price, or an auto-decline not below the auto-accept, is left out and
 *   said, since eBay refuses the whole listing for one;
 * - GPSR goes to new listings and to a pushed listing's revise (Item.Regulatory
 *   is in the Revise calls), as Regulatory.Manufacturer (CompanyName,
 *   Street1) and ResponsiblePersons.ResponsiblePerson (CompanyName,
 *   Types.Type EUResponsiblePerson), cut to eBay's lengths.
 *
 * The pushed bodies are pinned byte for byte against 3.28.6's in
 * test-imported-revise.php (the exceptions); this is the rest.
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
	public static function apply_ended_listing_stock( $product_id, $listed, $sold, $item = null ) { return (int) wc_get_product( $product_id )->get_stock_quantity(); }
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
	public static function get_by_product( $id ) { return null; }
}
function get_post_status( $id ) { return 'publish'; }
function get_post_field( $field, $id ) { return ''; }
function get_attached_file( $id ) { return ''; }
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-listing-link.php';

$API = $GLOBALS['API'];

/** A shop on eBay US selling parts, with the defaults set in Settings. */
function shop( array $more = array() ) {
	TCGiant_Sync_OAuth::$settings = $more + array(
		'marketplace'               => 'EBAY_US',
		'export_category_id'        => '183454',
		'export_condition_id'       => '1000',
		'export_location'           => 'Austin, TX',
		'export_postal_code'        => '78701',
		'export_fulfillment_policy' => 'FP1',
		'export_return_policy'      => 'RP1',
		'export_payment_policy'     => 'PP1',
		'best_offer_enabled'        => '1',
		'best_offer_auto_accept'    => '0.90',
		'best_offer_auto_decline'   => '0.70',
		'gpsr_enabled'              => '1',
		'gpsr_manufacturer_name'    => 'ACME Cards Ltd',
		'gpsr_manufacturer_address' => '1 Road, Leeds',
		'gpsr_eu_responsible'       => 'EU Rep GmbH',
	);
}
function item( $id, array $meta = array(), array $data = array() ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/i' . $id . '.jpg';
	$product = new WC_Product( $id, $data + array( 'name' => 'Item ' . $id, 'regular_price' => '100.00', 'stock' => 2, 'image_id' => 9000 + $id ) );
	foreach ( $meta + array( '_ebay_export_item_type' => 'other' ) as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
function push( WC_Product $product ) {
	$GLOBALS['API']->calls = array();
	$from = count( $GLOBALS['W']['log'] );
	$res  = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	$said = array();
	foreach ( array_slice( $GLOBALS['W']['log'], $from ) as $row ) {
		$said[] = $row[1];
	}
	return array( $res, (string) ( $GLOBALS['API']->calls[0][1] ?? '' ), $said );
}
function xp( $body ) {
	$doc = new DOMDocument();
	@$doc->loadXML( '<R xmlns="urn:ebay:apis:eBLBaseComponents">' . $body . '</R>' );
	$xp = new DOMXPath( $doc );
	$xp->registerNamespace( 'e', 'urn:ebay:apis:eBLBaseComponents' );
	return $xp;
}
function val( $body, $path ) {
	return xp( $body )->evaluate( 'string(/e:R/e:Item/' . $path . ')' );
}
function has( $body, $path ) {
	return xp( $body )->query( '/e:R/e:Item/' . $path )->length > 0;
}
$pushed = array( '_ebay_item_id' => '110000000700', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-10-01 10:00:00', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHO CARRIES THE DEFAULTS\n" . str_repeat( '=', 124 ) . "\n";

shop();
$new  = exporter()->get_export_settings( item( 701 )->get_id() );
$push = exporter()->get_export_settings( item( 702, $pushed )->get_id() );
$imp  = exporter()->get_export_settings( item( 703, array( '_ebay_origin' => 'import' ) + $pushed )->get_id() );
$keys = array_flip( TCGiant_Sync_Exporter::LISTING_DEFAULT_KEYS );
check( 'a product with no listing carries all seven', count( array_intersect_key( $new, $keys ) ), 7 );
check( 'a product the plugin pushed, the same', array_intersect_key( $push, $keys ), array_intersect_key( $new, $keys ) );
check( 'an imported one, none', array_intersect_key( $imp, $keys ), array() );
shop( array( 'best_offer_enabled' => '0', 'gpsr_enabled' => '0', 'best_offer_auto_accept' => '', 'gpsr_eu_responsible' => ' ' ) );
check( 'what is off or blank: as Settings has it, blanks left out', array_intersect_key( exporter()->get_export_settings( 701 ), $keys ), array( 'best_offer_enabled' => '0', 'best_offer_auto_decline' => '0.70', 'gpsr_enabled' => '0', 'gpsr_manufacturer_name' => 'ACME Cards Ltd', 'gpsr_manufacturer_address' => '1 Road, Leeds' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nBEST OFFER: NEW LISTINGS, EBAY'S NAMES, THRESHOLDS EBAY TAKES\n" . str_repeat( '=', 124 ) . "\n";

shop();
list( $res, $body ) = push( item( 711 ) );
check( 'a new listing: Best Offer on, 90.00 auto-accept and 70.00 auto-decline under eBay\'s names', array( $res['action'] ?? null, val( $body, 'e:BestOfferDetails/e:BestOfferEnabled' ), val( $body, 'e:ListingDetails/e:BestOfferAutoAcceptPrice' ), val( $body, 'e:ListingDetails/e:MinimumBestOfferPrice' ) ), array( 'created', 'true', '90.00', '70.00' ) );
check( '  no field eBay does not have', array( false !== strpos( $body, 'BestOfferAutoDeclinePrice' ), substr_count( $body, 'MinimumBestOfferPrice' ) ), array( false, 2 ) );
list( , $body ) = push( item( 712, $pushed ) );
check( 'a pushed listing\'s revise: no Best Offer from Settings - its label says new listings', has( $body, 'e:BestOfferDetails' ), false );
list( , $body ) = push( item( 713, $pushed + array( '_tcgiant_best_offer_enabled' => '1', '_tcgiant_bo_auto_accept' => '95.00' ) ) );
check( '  one whose product has its own: its own, as before, with its own threshold and none of Settings\'', array( val( $body, 'e:BestOfferDetails/e:BestOfferEnabled' ), val( $body, 'e:ListingDetails/e:BestOfferAutoAcceptPrice' ), has( $body, 'e:ListingDetails/e:MinimumBestOfferPrice' ) ), array( 'true', '95.00', false ) );
list( , $body ) = push( item( 714, array( '_tcgiant_best_offer_enabled' => '0' ) ) );
check( 'a new listing whose product says no: none', has( $body, 'e:BestOfferDetails' ), false );
list( , $body ) = push( item( 715, array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) ) );
check( 'an auction: none (fixed price only, as before)', has( $body, 'e:BestOfferDetails' ), false );
list( , $body ) = push( item( 716, array( '_tcgiant_bo_auto_accept' => '49.99', '_tcgiant_bo_auto_decline' => '30' ) ) );
check( 'amounts above 1 are prices: 49.99 and 30.00', array( val( $body, 'e:ListingDetails/e:BestOfferAutoAcceptPrice' ), val( $body, 'e:ListingDetails/e:MinimumBestOfferPrice' ) ), array( '49.99', '30.00' ) );

list( , $body, $said ) = push( item( 717, array( '_tcgiant_bo_auto_accept' => '1' ) ) );
check( 'an auto-accept at the price (1 = 100%): left out, the auto-decline kept, and said once', array( has( $body, 'e:ListingDetails/e:BestOfferAutoAcceptPrice' ), val( $body, 'e:ListingDetails/e:MinimumBestOfferPrice' ), count( preg_grep( '/goes with Best Offer but without one of its thresholds, which eBay would refuse: the auto-accept price 100.00 is not below the price 100.00/', $said ) ) ), array( false, '70.00', 1 ) );
list( , $body, $said ) = push( item( 718, array( '_tcgiant_bo_auto_accept' => '0.60' ) ) );
check( 'an auto-decline not below the auto-accept (70 over 60): the decline left out, and said', array( val( $body, 'e:ListingDetails/e:BestOfferAutoAcceptPrice' ), has( $body, 'e:ListingDetails/e:MinimumBestOfferPrice' ), count( preg_grep( '/the auto-decline price 70.00 is not below the auto-accept price 60.00/', $said ) ) ), array( '60.00', false, 1 ) );
list( , $body, $said ) = push( item( 719, array( '_tcgiant_bo_auto_accept' => '150', '_tcgiant_bo_auto_decline' => '-5' ) ) );
check( 'both out of range: Best Offer on, no thresholds, one line naming both', array( val( $body, 'e:BestOfferDetails/e:BestOfferEnabled' ), has( $body, 'e:ListingDetails/*' ), count( preg_grep( '/without its thresholds/', $said ) ) ), array( 'true', false, 1 ) );

// Verify is a new listing's check, and gets them.
$set = exporter()->settle_motors( exporter()->get_export_settings( item( 720 )->get_id() ), true );
$xml = exporter()->build_item_xml_public( wc_get_product( 720 ), $set );
check( 'Verify\'s item: Best Offer and its thresholds, and the GPSR', array( false !== strpos( $xml, '<BestOfferAutoAcceptPrice>90.00</BestOfferAutoAcceptPrice>' ), false !== strpos( $xml, '<Regulatory>' ) ), array( true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nGPSR: ITEM.REGULATORY, NEW LISTINGS AND PUSHED LISTINGS' REVISES\n" . str_repeat( '=', 124 ) . "\n";

shop();
list( , $body ) = push( item( 731 ) );
check( 'a new listing: the manufacturer and the EU Responsible Person, in eBay\'s elements', array( val( $body, 'e:Regulatory/e:Manufacturer/e:CompanyName' ), val( $body, 'e:Regulatory/e:Manufacturer/e:Street1' ), val( $body, 'e:Regulatory/e:ResponsiblePersons/e:ResponsiblePerson/e:CompanyName' ), val( $body, 'e:Regulatory/e:ResponsiblePersons/e:ResponsiblePerson/e:Types/e:Type' ) ), array( 'ACME Cards Ltd', '1 Road, Leeds', 'EU Rep GmbH', 'EUResponsiblePerson' ) );
check( '  no ProductSafety, and no Component', array( false !== strpos( $body, 'ProductSafety' ), false !== strpos( $body, '<Component>' ) ), array( false, false ) );
list( , $body ) = push( item( 732, $pushed ) );
check( 'a pushed listing\'s revise: the same Regulatory', val( $body, 'e:Regulatory/e:Manufacturer/e:CompanyName' ), 'ACME Cards Ltd' );
list( , $body ) = push( item( 733, array( '_ebay_origin' => 'import' ) + $pushed ) );
check( 'an imported listing\'s revise: none (it keeps eBay\'s)', array( has( $body, 'e:Regulatory' ), has( $body, 'e:BestOfferDetails' ) ), array( false, false ) );
shop( array( 'gpsr_eu_responsible' => '' ) );
list( , $body ) = push( item( 734 ) );
check( 'no Responsible Person set: the manufacturer alone', array( has( $body, 'e:Regulatory/e:Manufacturer' ), has( $body, 'e:Regulatory/e:ResponsiblePersons' ) ), array( true, false ) );
shop( array( 'gpsr_manufacturer_name' => '', 'gpsr_manufacturer_address' => '' ) );
list( , $body ) = push( item( 735 ) );
check( 'the Responsible Person alone', array( has( $body, 'e:Regulatory/e:Manufacturer' ), val( $body, 'e:Regulatory/e:ResponsiblePersons/e:ResponsiblePerson/e:CompanyName' ) ), array( false, 'EU Rep GmbH' ) );
shop( array( 'gpsr_manufacturer_name' => '', 'gpsr_manufacturer_address' => '', 'gpsr_eu_responsible' => '' ) );
list( , $body ) = push( item( 736 ) );
check( 'GPSR on with nothing filled in: no Regulatory at all', has( $body, 'e:Regulatory' ), false );
shop( array( 'gpsr_enabled' => '0' ) );
list( , $body ) = push( item( 737 ) );
check( 'GPSR off: none', has( $body, 'e:Regulatory' ), false );
shop( array( 'gpsr_manufacturer_name' => str_repeat( 'Ä', 120 ), 'gpsr_manufacturer_address' => str_repeat( 'b', 200 ) . ' & <c>' ) );
list( , $body ) = push( item( 738 ) );
check( 'cut to eBay\'s lengths, by character: name 100, street 180; escaped', array( mb_strlen( val( $body, 'e:Regulatory/e:Manufacturer/e:CompanyName' ), 'UTF-8' ), strlen( val( $body, 'e:Regulatory/e:Manufacturer/e:Street1' ) ), false !== strpos( $body, '<c>' ) ), array( 100, 180, false ) );
check( 'TCGiant_Sync_Exporter::regulatory_xml() with nothing: nothing', TCGiant_Sync_Exporter::regulatory_xml( array() ), '' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNO BEST OFFER WITH VARIATIONS\n" . str_repeat( '=', 124 ) . "\n";

// eBay's reference for Item.BestOfferDetails: "Best Offer is not available
// for multi-variation listings."
/** A product with variations. */
function variable( $id, array $meta = array() ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/v' . $id . '.jpg';
	$product = new WC_Product_Variable( $id, array( 'name' => 'Shirt ' . $id, 'regular_price' => '', 'stock' => null, 'image_id' => 9000 + $id, 'type' => 'variable' ) );
	foreach ( $meta + array( '_ebay_export_item_type' => 'other' ) as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
function said_offer( $words ) {
	return count( array_filter( $GLOBALS['W']['log'], function ( $row ) use ( $words ) { return false !== strpos( $row[1], $words ); } ) );
}
shop();
$GLOBALS['W']['log'] = array();
$set = exporter()->get_export_settings( variable( 801 )->get_id() );
$xml = '<Item>' . exporter()->build_item_xml_public( wc_get_product( 801 ), $set ) . '</Item>';
check( 'a new listing with variations, Settings\' Best Offer on: its Variations, and no BestOfferDetails or ListingDetails', array( has( $xml, 'e:Variations' ), has( $xml, 'e:BestOfferDetails' ), has( $xml, 'e:ListingDetails' ) ), array( true, false, false ) );
check( '  said once, in those words, and no threshold measured against a price of 0.00', array( said_offer( 'Verify: WC #801 goes without Best Offer: it is a listing with variations, and eBay does not allow Best Offer on those.' ), said_offer( 'threshold' ) ), array( 1, 0 ) );
check( '  its GPSR still goes', has( $xml, 'e:Regulatory/e:Manufacturer/e:CompanyName' ), true );
$GLOBALS['W']['log'] = array();
$set = exporter()->get_export_settings( variable( 807 )->get_id() );
private_method( 'TCGiant_Sync_Exporter', 'build_item_xml' )->invoke( exporter(), wc_get_product( 807 ), $set );
private_method( 'TCGiant_Sync_Exporter', 'build_item_xml' )->invoke( exporter(), wc_get_product( 807 ), $set );
check( '  built twice for one push (a relist builds twice): said once', said_offer( 'goes without Best Offer' ), 1 );
$GLOBALS['W']['log'] = array();
$set = exporter()->get_export_settings( variable( 802, array( '_tcgiant_best_offer_enabled' => '1', '_tcgiant_bo_auto_accept' => '20' ) )->get_id() );
$xml = '<Item>' . exporter()->build_item_xml_public( wc_get_product( 802 ), $set ) . '</Item>';
check( 'the product\'s own Best Offer, with variations: not sent either, and said', array( has( $xml, 'e:Variations' ), has( $xml, 'e:BestOfferDetails' ), has( $xml, 'e:ListingDetails' ), said_offer( 'WC #802 goes without Best Offer' ) ), array( true, false, false, 1 ) );
$GLOBALS['W']['log'] = array();
$set = exporter()->get_export_settings( variable( 803, array( '_tcgiant_best_offer_enabled' => '1' ) + $pushed )->get_id() );
$xml = '<Item>' . private_method( 'TCGiant_Sync_Exporter', 'build_item_xml' )->invoke( exporter(), wc_get_product( 803 ), $set, true ) . '</Item>';
check( '  nor on a revise of a pushed listing with variations', array( has( $xml, 'e:Variations' ), has( $xml, 'e:BestOfferDetails' ) ), array( true, false ) );
$GLOBALS['W']['log'] = array();
$set = exporter()->get_export_settings( variable( 804, array( '_tcgiant_best_offer_enabled' => '0' ) )->get_id() );
exporter()->build_item_xml_public( wc_get_product( 804 ), $set );
shop( array( 'best_offer_enabled' => '0' ) );
$set = exporter()->get_export_settings( variable( 805 )->get_id() );
exporter()->build_item_xml_public( wc_get_product( 805 ), $set );
check( '  with no Best Offer asked for (its own off, or Settings\' off): nothing said', said_offer( 'goes without Best Offer' ), 0 );
shop();
$set = exporter()->get_export_settings( item( 806 )->get_id() );
$xml = '<Item>' . exporter()->build_item_xml_public( wc_get_product( 806 ), $set ) . '</Item>';
check( 'a product without variations: Best Offer as before', array( val( $xml, 'e:BestOfferDetails/e:BestOfferEnabled' ), val( $xml, 'e:ListingDetails/e:BestOfferAutoAcceptPrice' ) ), array( 'true', '90.00' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSETTINGS SAYS SO\n" . str_repeat( '=', 124 ) . "\n";

$view = (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/settings.php' );
check( 'no "not sent to eBay yet" left on either field', substr_count( $view, 'not sent to eBay yet' ) + substr_count( $view, 'Not sent to eBay yet' ), 0 );
check( 'Best Offer says new listings, and imported listings left alone', false !== strpos( $view, 'Turns Best Offer on for new fixed-price listings of the products that use your defaults: not for listings imported from eBay' ), true );
check( '  nor listings with variations, which eBay does not allow it on', false !== strpos( $view, 'nor for listings with variations, which eBay does not allow Best Offer on' ), true );
check( 'GPSR says where it goes and eBay\'s lengths', false !== strpos( $view, 'on new listings and on Updates of listings you pushed. Listings imported from eBay keep their own. eBay takes up to 100 characters for a name and 180 for the address.' ), true );

done();
