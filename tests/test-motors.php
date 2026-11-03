<?php
/**
 * Harness: eBay Motors Parts & Accessories, for a shop on eBay US (3.28.9).
 *
 * A shop making parts for vintage motorcycles could not push anything into an
 * eBay Motors category: every push went to site 0 and looked its category up
 * in tree 0, and eBay keeps Motors in tree 100 and lists it through site 100.
 * Its log for 35560: the Taxonomy API's 62005, then "The category is not
 * valid, select another category" and "Condition is not applicable for this
 * category" from AddFixedPriceItem.
 *
 * Here the real TCGiant_Sync_Exporter, TCGiant_Sync_Motors, TCGiant_Sync_Sites
 * and TCGiant_Sync_Catalog push, with eBay played (support/motors-api.php):
 * each Trading call with the site header the real API class would send it
 * with, each Taxonomy and Metadata read by tree. Listings on eBay Motors are
 * imported through the real TCGiant_Sync_Mapper (support/motors-import.php).
 * The header itself, the reads' URLs and what each is kept under are
 * test-motors-wire.php's; the Best Offer and GPSR defaults are
 * test-offer-gpsr.php's; the eBay tab, Verify and the category browser are
 * support/motors-admin.php's, below. Since 3.29.2 a business policy for
 * motor vehicles only refuses an ordinary push from eBay US as well.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

// What a push needs of the plugin's other classes, played.
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

/** The parts maker's shop: eBay US, its default category the 35560 of its log, typed in. */
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
/** eBay as it answers for the shop's categories. */
function motors_ebay() {
	$api = $GLOBALS['API'];
	$api->clear();
	$api->answers      = array();
	$api->tree_aspects = array(
		'0'   => array( '35560' => motors_error( 62005 ), '35561' => motors_error( 62005 ), '6472' => motors_error( 62005 ), '35999' => motors_error( 62005 ) ),
		'100' => array( '35560' => array( array( 'name' => 'Brand', 'required' => true ) ) ),
	);
	$api->nodes = array(
		'35560' => motors_node( '35560', 'Vintage Motorcycle Parts', '10063' ),
		'35561' => motors_node( '35561', 'Seats', '10063' ),
		'6472'  => motors_node( '6472', 'Triumph', '6024' ),
		'35999' => motors_node( '35999', 'Other', '99' ),
	);
	$api->suggest = array(
		'Vintage Motorcycle Parts' => array( array( 'id' => '35560', 'name' => 'eBay Motors > Parts & Accessories > Motorcycle & Scooter Parts > Vintage Motorcycle Parts', 'ancestors' => array( '10063', '6028', '6000' ) ) ),
		'Other'                    => array( array( 'id' => '35998', 'name' => 'eBay Motors > Other Vehicles & Trailers > Other', 'ancestors' => array( '6038', '6000' ) ) ),
	);
	$api->conds = array(
		'35560' => motors_conditions( '35560', array( '1000' => 'New', '3000' => 'Used', '7000' => 'For parts or not working' ) ),
		'35561' => motors_conditions( '35561', array( '1000' => 'New', '3000' => 'Used' ) ),
	);
	$GLOBALS['W']['transients'] = array();
	$GLOBALS['W']['log']        = array();
	delete_option( TCGiant_Sync_Motors::OPTION );
}
/** A product with a photo, a Brand and these meta. */
function part( $id, array $meta = array(), array $data = array() ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/part-' . $id . '.jpg';
	$product = new WC_Product( $id, array_merge( array( 'name' => 'Rocker cover ' . $id, 'description' => 'A part.', 'regular_price' => '85.00', 'stock' => 3, 'sku' => 'RC-' . $id, 'image_id' => 9000 + $id, 'attributes' => array( new WC_Product_Attribute( 'Brand', array( 'Triumph' ) ) ) ), $data ) );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
/** A push (do_push()): its answer, each Trading call as Call@site, each read, and its calls' bodies. */
function push( WC_Product $product ) {
	$GLOBALS['API']->clear();
	$res = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	return array( $res, $GLOBALS['API']->sites, $GLOBALS['API']->rest, $GLOBALS['API']->calls );
}
/** The site a push's answer says, null for a refusal. */
function answer_site( $res ) {
	return is_array( $res ) ? ( $res['site'] ?? null ) : null;
}
function code_of( $res ) {
	return is_wp_error( $res ) ? $res->get_error_code() : ( $res['action'] ?? null );
}
function says( $res, $words ) {
	return is_wp_error( $res ) && false !== strpos( $res->get_error_message(), $words );
}
function as_xml( $body ) {
	$doc = new DOMDocument();
	if ( ! @$doc->loadXML( '<Request xmlns="urn:ebay:apis:eBLBaseComponents">' . $body . '</Request>' ) ) {
		return null;
	}
	$xp = new DOMXPath( $doc );
	$xp->registerNamespace( 'e', 'urn:ebay:apis:eBLBaseComponents' );
	return $xp;
}
function value_of( $body, $path ) {
	$xp = as_xml( $body );
	return $xp ? $xp->evaluate( 'string(/e:Request/e:Item/' . $path . ')' ) : null;
}
function fields_of( $body ) {
	$xp  = as_xml( $body );
	$out = array();
	foreach ( $xp ? $xp->query( '/e:Request/e:Item/*' ) : array() as $node ) {
		$out[] = $node->localName;
	}
	return $out;
}
function logged( $words ) {
	foreach ( $GLOBALS['W']['log'] as $row ) {
		if ( false !== strpos( $row[1], $words ) ) {
			return $row[0];
		}
	}
	return null;
}

parts_shop();
motors_ebay();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE CUSTOMER'S PUSH: A PART IN 35560, ON EBAY MOTORS\n" . str_repeat( '=', 124 ) . "\n";

list( $res, $sites, $rest, $calls ) = push( part( 501, array(), array( 'attributes' => array() ) ) );
check( 'with no Brand: refused for the item specifics of tree 100, where 35560 is - no listing call', array( code_of( $res ), says( $res, 'Brand' ), $sites ), array( 'missing_required_aspects', true, array() ) );
check( '  tree 0 asked once (62005), then tree 100: its node, its suggestions, its conditions, its item specifics', $rest, array( 'aspects:0:35560', 'node:100:35560', 'suggest:100:Vintage Motorcycle Parts', 'conditions:EBAY_MOTORS_US:35560', 'aspects:100:35560' ) );
check( '  said once, as an eBay Motors category', logged( 'Category 35560 is an eBay Motors category (eBay Motors Parts & Accessories)' ), 'info' );

list( $res, $sites, $rest, $calls ) = push( part( 501 ) );
$add = $calls[0][1] ?? '';
check( 'with its Brand: listed, through AddFixedPriceItem with X-EBAY-API-SITEID 100', array( code_of( $res ), $sites ), array( 'created', array( 'AddFixedPriceItem@100' ) ) );
check( '  Item.Site eBayMotors, consistent with the header, as eBay requires', value_of( $add, 'e:Site' ), 'eBayMotors' );
check( '  in 35560, at its price, in dollars, located in the US', array( value_of( $add, 'e:PrimaryCategory/e:CategoryID' ), value_of( $add, 'e:StartPrice' ), value_of( $add, 'e:Currency' ), value_of( $add, 'e:Country' ) ), array( '35560', '85.00', 'USD', 'US' ) );
check( '  the shop\'s condition, 1000, one eBay takes there', value_of( $add, 'e:ConditionID' ), '1000' );
check( '  the item specifics by tree 100\'s name for them', value_of( $add, 'e:ItemSpecifics/e:NameValueList[e:Name="Brand"]/e:Value' ), 'Triumph' );
check( '  no ConditionDescriptors', in_array( 'ConditionDescriptors', fields_of( $add ), true ), false );
check( '  asked nothing it had learnt: no tree 0, node, suggestions or conditions again - only tree 100\'s item specifics, which the API keeps', array_values( array_unique( $rest ) ), array( 'aspects:100:35560' ) );
check( '  the push\'s answer says eBay Motors, for the record', answer_site( $res ), 'eBayMotors' );
TCGiant_Sync_Exporter::record_listing_made( wc_get_product( 501 ), $res );
check( '  recorded: the listing is on eBayMotors', array( get_post_meta( 501, '_ebay_site', true ), get_post_meta( 501, '_ebay_item_id', true ) ), array( 'eBayMotors', '110000000001' ) );

list( $res, $sites ) = push( part( 502 ) );
check( 'a second product in 35560: the same, and no read of the tree', array( code_of( $res ), $sites, array_values( array_unique( $GLOBALS['API']->rest ) ) ), array( 'created', array( 'AddFixedPriceItem@100' ), array( 'aspects:100:35560' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nREVISE, VERIFY, RELIST AND END A LISTING ON EBAY MOTORS\n" . str_repeat( '=', 124 ) . "\n";

$pushed = array( '_ebay_item_id' => '110000000501', '_ebay_site' => 'eBayMotors', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-10-31 10:00:00', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' );
list( $res, $sites, , $calls ) = push( part( 503, $pushed, array( 'regular_price' => '79.00' ) ) );
$revise = $calls[0][1] ?? '';
check( 'the price Update of the listing pushed there: ReviseFixedPriceItem with X-EBAY-API-SITEID 100', array( code_of( $res ), $sites ), array( 'updated', array( 'ReviseFixedPriceItem@100' ) ) );
check( '  Site eBayMotors, its category and condition, its new price', array( value_of( $revise, 'e:Site' ), value_of( $revise, 'e:PrimaryCategory/e:CategoryID' ), value_of( $revise, 'e:ConditionID' ), value_of( $revise, 'e:StartPrice' ) ), array( 'eBayMotors', '35560', '1000', '79.00' ) );

$exporter = exporter();
$settings = $exporter->settle_motors( $exporter->get_export_settings( 501 ), true );
$GLOBALS['API']->clear();
$verified = TCGiant_Sync_Exporter::on_listing_site( $settings, function () use ( $exporter, $settings ) {
	return TCGiant_Sync_API::instance()->verify_add_fixed_price_item( '<Item>' . "\n" . $exporter->build_item_xml_public( wc_get_product( 501 ), $settings ) . "\n" . '</Item>' );
} );
check( 'Verify, settled as a new listing: VerifyAddFixedPriceItem with 100, Site eBayMotors', array( $GLOBALS['API']->sites, value_of( $GLOBALS['API']->calls[0][1] ?? '', 'e:Site' ) ), array( array( 'VerifyAddFixedPriceItem@100' ), 'eBayMotors' ) );

$ended = array( 'ItemID' => '110000000504', 'Site' => 'eBayMotors', 'ListingType' => 'FixedPriceItem', 'Quantity' => '3', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0' ), 'PrimaryCategory' => array( 'CategoryID' => '35560', 'CategoryName' => 'eBay Motors:Parts & Accessories:Motorcycle & Scooter Parts:Vintage Motorcycle Parts' ) );
part( 504, array( '_ebay_listing_status' => 'Ended' ) + $pushed );
update_post_meta( 504, '_ebay_item_id', '110000000504' );
$GLOBALS['API']->clear();
$GLOBALS['API']->answers = array( 'GetItem' => array( 'Ack' => 'Success', 'Item' => $ended ), 'RelistFixedPriceItem' => array( 'Ack' => 'Success', 'ItemID' => '110000000604' ) );
$relisted = exporter()->relist_ended_listing( 504, 'Relist' );
check( 'Relist of an ended one: GetItem as before, RelistFixedPriceItem with 100', array( is_array( $relisted ) ? ( $relisted['item_id'] ?? null ) : ( is_wp_error( $relisted ) ? $relisted->get_error_message() : null ), $GLOBALS['API']->sites ), array( '110000000604', array( 'GetItem@0', 'RelistFixedPriceItem@100' ) ) );
check( '  still recorded on eBayMotors', get_post_meta( 504, '_ebay_site', true ), 'eBayMotors' );

part( 505, array( '_ebay_listing_status' => 'Ended' ) + $pushed );
update_post_meta( 505, '_ebay_item_id', '110000000505' );
$GLOBALS['API']->clear();
$GLOBALS['API']->answers = array( 'GetItem' => array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000505', 'PrimaryCategory' => array( 'CategoryID' => '6472', 'CategoryName' => 'eBay Motors:Motorcycles:Triumph' ) ) + $ended ), 'RelistFixedPriceItem' => array( 'Ack' => 'Success', 'ItemID' => '110000000605' ) );
$GLOBALS['W']['log'] = array();
$vehicle = exporter()->relist_ended_listing( 505, 'Relist' );
check( '  a whole motorcycle\'s: relisted as before 3.28.9, with the shop\'s own site, and said', array( is_array( $vehicle ) ? ( $vehicle['item_id'] ?? null ) : ( is_wp_error( $vehicle ) ? $vehicle->get_error_message() : null ), $GLOBALS['API']->sites, logged( 'is on eBay Motors and is a vehicle listing, so it is relisted as before 3.28.9' ) ), array( '110000000605', array( 'GetItem@0', 'RelistFixedPriceItem@0' ), 'info' ) );

// An imported auction on eBay Motors that has ended, pushed: relisted as itself
// (3.28.8's decision 16), on eBay Motors, and recorded there.
part( 506, array( '_ebay_item_id' => '110000000506', '_ebay_site' => 'eBayMotors', '_ebay_origin' => 'import', '_ebay_listing_type' => 'Chinese', '_ebay_listing_status' => 'Active' ) );
$GLOBALS['API']->clear();
$GLOBALS['API']->answers = array(
	'ReviseItem' => new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' ),
	'GetItem'    => array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000506', 'ListingType' => 'Chinese', 'Quantity' => '1' ) + $ended ),
	'RelistItem' => array( 'Ack' => 'Success', 'ItemID' => '110000000606' ),
);
$res = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), wc_get_product( 506 ) );
check( 'an imported Motors auction that ended, its part of eBay Motors not recorded: read first, then relisted as itself with 100, and said to be on eBayMotors', array( code_of( $res ), answer_site( $res ), $GLOBALS['API']->sites ), array( 'relisted', 'eBayMotors', array( 'GetItem@0', 'RelistItem@100' ) ) );

$GLOBALS['API']->clear();
$GLOBALS['API']->answers = array();
TCGiant_Sync_API::instance()->end_item( '110000000501' );
check( 'End Listing (EndItem, by its ItemID): as before, with the shop\'s own site', $GLOBALS['API']->sites, array( 'EndItem@0' ) );

// A pushed listing on eBay Motors found ended by its Update: the new listing
// in its place goes to eBay Motors too.
part( 507, $pushed );
update_post_meta( 507, '_ebay_item_id', '110000000507' );
$GLOBALS['API']->clear();
$GLOBALS['API']->answers = array(
	'ReviseFixedPriceItem' => new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' ),
	'GetItem'              => array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000507' ) + $ended ),
);
list( $res, $sites ) = array( private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), wc_get_product( 507 ) ), $GLOBALS['API']->sites );
check( 'a Motors listing found ended: the Add in its place goes with 100 too', array( code_of( $res ), $sites, answer_site( $res ) ), array( 'relisted', array( 'ReviseFixedPriceItem@100', 'GetItem@0', 'AddFixedPriceItem@100' ), 'eBayMotors' ) );
$GLOBALS['API']->answers = array();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nREFUSED HERE, WITH WHAT TO DO\n" . str_repeat( '=', 124 ) . "\n";

motors_ebay();
list( $res, $sites, $rest ) = push( part( 511, array( '_ebay_export_category_id' => '6472', '_ebay_export_category_typed' => '6472' ) ) );
check( 'a whole motorcycle\'s category (under 6024): refused, no listing call, no conditions read', array( code_of( $res ), says( $res, 'eBay Category — 6472 is an eBay Motors vehicle category' ), $sites, in_array( 'conditions:EBAY_MOTORS_US:6472', $rest, true ) ), array( 'missing_export_settings', true, array(), false ) );
check( '  it says what to do', says( $res, 'Choose a Parts & Accessories category' ), true );
TCGiant_Sync_Motors::learn( array( '6473' => array( TCGiant_Sync_Motors::BRANCH_VEHICLES, '6001' ) ) );
list( $res, $sites, $rest ) = push( part( 512, array( '_ebay_export_category_id' => '6473', '_ebay_export_category_name' => 'Ford' ) ) );
check( 'one learnt as a vehicle category (6473, under Cars & Trucks): refused without asking eBay anything', array( code_of( $res ), says( $res, '6473 is an eBay Motors vehicle category' ), $rest ), array( 'missing_export_settings', true, array() ) );
list( $res, $sites ) = push( part( 513, array( '_ebay_export_category_id' => '35999' ) ) );
check( 'a Motors category its node and suggestions do not place: refused as not confirmed', array( code_of( $res ), says( $res, '35999 is an eBay Motors category that could not be confirmed as Parts & Accessories' ), $sites ), array( 'missing_export_settings', true, array() ) );
list( $res, $sites ) = push( part( 514, array( '_ebay_export_condition_id' => '2500' ) ) );
check( 'a condition 35560 does not take (2500): refused, naming the ones it does', array( code_of( $res ), says( $res, 'Condition — 2500 is not a condition eBay takes in eBay Motors category 35560. It takes: 1000 New, 3000 Used, 7000 For parts or not working' ), $sites ), array( 'missing_export_settings', true, array() ) );
list( $res, $sites ) = push( part( 515, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '10' ) ) );
check( 'a trading card\'s condition in a Motors category: refused, set Item Type to All Other', array( code_of( $res ), says( $res, 'set Item Type to All Other' ), $sites ), array( 'missing_export_settings', true, array() ) );
$GLOBALS['API']->conds['35560'] = motors_error( 500, 500 );
$GLOBALS['W']['transients']     = array();
list( $res, $sites ) = push( part( 516 ) );
check( 'its conditions not read (eBay\'s error): refused, push again later - never sent unchecked', array( code_of( $res ), says( $res, 'could not read which item conditions eBay takes in eBay Motors category 35560' ), $sites ), array( 'missing_export_settings', true, array() ) );
$GLOBALS['API']->conds['35560'] = array( 'itemConditionPolicies' => array( array( 'categoryId' => '35560', 'itemConditions' => array() ) ) );
$GLOBALS['W']['transients']     = array();
list( $res ) = push( part( 517 ) );
check( 'a category that takes no condition: refused, a push always sends one', says( $res, 'eBay takes no item condition in eBay Motors category 35560' ), true );

motors_ebay();
update_option( 'tcgiant_export_policies_fulfillment', array( array( 'id' => '250000021', 'name' => 'Truck freight', 'category_types' => array( 'MOTORS_VEHICLES' ) ) ) );
list( $res, $sites ) = push( part( 518 ) );
check( 'a shipping policy for motor vehicles only: refused, naming it', array( code_of( $res ), says( $res, 'Business Policies — Truck freight is a business policy for motor vehicles only' ), $sites ), array( 'missing_export_settings', true, array() ) );
update_option( 'tcgiant_export_policies_fulfillment', array( array( 'id' => '250000021', 'name' => 'Parcel', 'category_types' => array( 'ALL_EXCLUDING_MOTORS_VEHICLES', 'MOTORS_VEHICLES' ) ) ) );
check( '  one for everything but vehicles (and vehicles too): listed', code_of( push( part( 519 ) )[0] ), 'created' );
update_option( 'tcgiant_export_policies_fulfillment', array( array( 'id' => '250000021', 'name' => 'Old' ) ) );
check( '  one fetched before 3.28.9, its types not kept: listed, for eBay to judge', code_of( push( part( 520 ) )[0] ), 'created' );
delete_option( 'tcgiant_export_policies_fulfillment' );
$GLOBALS['API']->policies['fulfillment'] = array( array( 'fulfillmentPolicyId' => 'F1', 'name' => 'Parcel', 'categoryTypes' => array( array( 'name' => 'ALL_EXCLUDING_MOTORS_VEHICLES', 'default' => true ) ) ) );
check( '"Fetch eBay Policies" keeps each policy\'s category types', TCGiant_Sync_Exporter::get_policies( 'fulfillment' )[0]['category_types'] ?? null, array( 'ALL_EXCLUDING_MOTORS_VEHICLES' ) );
delete_option( 'tcgiant_export_policies_fulfillment' );

// eBay does not move a listing between eBay Motors and eBay US.
motors_ebay();
list( $res, $sites ) = push( part( 521, array( '_ebay_site' => 'US' ) + $pushed ) );
check( 'a listing on eBay US whose category is now 35560: refused - end it and push again', array( code_of( $res ), says( $res, 'this listing is not on eBay Motors and 35560 is an eBay Motors category' ), $sites ), array( 'missing_export_settings', true, array() ) );
list( $res, $sites ) = push( part( 522, array( '_ebay_export_category_id' => '183454', '_ebay_export_item_type' => 'other' ) + $pushed ) );
check( 'a listing on eBay Motors whose category is now 183454: refused the same way', array( code_of( $res ), says( $res, 'this listing is on eBay Motors and 183454 is not an eBay Motors category' ), $sites ), array( 'missing_export_settings', true, array() ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAN ORDINARY US PUSH IS AS IT WAS\n" . str_repeat( '=', 124 ) . "\n";

motors_ebay();
parts_shop( array( 'export_category_id' => '33564', 'export_category_typed' => '33564' ) );
list( $res, $sites, $rest, $calls ) = push( part( 531 ) );
check( 'a category of tree 0 (33564): AddFixedPriceItem with 0, Site US', array( code_of( $res ), $sites, value_of( $calls[0][1] ?? '', 'e:Site' ) ), array( 'created', array( 'AddFixedPriceItem@0' ), 'US' ) );
check( '  tree 0\'s item specifics, which the push reads anyway; nothing of tree 100, no conditions', array_values( array_unique( $rest ) ), array( 'aspects:0:33564' ) );
check( '  nothing learnt, nothing said, no site in the answer', array( get_option( TCGiant_Sync_Motors::OPTION, 'none' ), logged( 'eBay Motors' ), null !== answer_site( $res ) ), array( 'none', null, false ) );
parts_shop( array( 'export_category_id' => '183454' ) );
list( $res, $sites, $rest ) = push( part( 532, array( '_ebay_export_item_type' => 'other' ) ) );
check( 'one of the plugin\'s own (183454): not asked about at all', array( $sites, array_values( array_unique( $rest ) ) ), array( array( 'AddFixedPriceItem@0' ), array( 'aspects:0:183454' ) ) );
parts_shop( array( 'marketplace' => 'EBAY_GB' ) );
list( $res, $sites, $rest, $calls ) = push( part( 533 ) );
check( 'a shop on eBay UK with 35560 typed in: as before - no Motors, its own site 3, Site UK', array( $sites, value_of( $calls[0][1] ?? '', 'e:Site' ), in_array( 'node:100:35560', $rest, true ) ), array( array( 'AddFixedPriceItem@3' ), 'UK', false ) );
// An imported listing on eBay US with no category read, the shop's default
// one nothing knows: its price Update sends no category, so the default's tree
// is not asked about - only the one read the build makes, as before.
parts_shop( array( 'export_category_id' => '33564', 'export_category_typed' => '33564' ) );
list( $res, $sites, $rest ) = push( part( 534, array( '_ebay_item_id' => '110000000534', '_ebay_site' => 'US', '_ebay_origin' => 'import', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' ) ) );
check( 'an imported US listing\'s price Update, its category not read: site 0, and the default category not probed', array( code_of( $res ), $sites, $rest ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), array( 'aspects:0:33564' ) ) );
parts_shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA LISTING IMPORTED FROM EBAY MOTORS\n" . str_repeat( '=', 124 ) . "\n";

exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/motors-import.php' ) . ' 2>&1', $tcg_out );
$MOTORS = json_decode( (string) end( $tcg_out ), true );
check( 'imported for real: the part, on eBayMotors, in 35560 with eBay\'s path', array( $MOTORS['imported']['motors']['meta']['_ebay_site'] ?? null, $MOTORS['imported']['motors']['meta']['_ebay_live']['category_name'] ?? null, $MOTORS['imported']['motors']['meta']['_ebay_origin'] ?? null ), array( 'eBayMotors', 'eBay Motors:Parts & Accessories:Motorcycle & Scooter Parts:Vintage Motorcycle Parts', 'import' ) );
/** A product the import made from one of those listings. */
function from_motors( $name, $id, array $data = array(), array $extra = array() ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$product = new WC_Product( $id, array_merge( array( 'name' => (string) $GLOBALS['MOTORS']['answers'][ $name ]['Title'], 'description' => 'From eBay.', 'regular_price' => '85.00', 'stock' => 3, 'image_id' => 9000 + $id, 'attributes' => array( new WC_Product_Attribute( 'Brand', array( 'Triumph' ) ) ) ), $data ) );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/m-' . $id . '.jpg';
	foreach ( $extra + $GLOBALS['MOTORS']['imported'][ $name ]['meta'] as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
motors_ebay();
list( $res, $sites, $rest, $calls ) = push( from_motors( 'motors', 541, array( 'regular_price' => '79.00' ) ) );
$body = $calls[0][1] ?? '';
check( 'its price Update: ReviseFixedPriceItem with 100, Site eBayMotors', array( code_of( $res ), $sites, value_of( $body, 'e:Site' ) ), array( 'updated', array( 'ReviseFixedPriceItem@100' ), 'eBayMotors' ) );
check( '  as 3.28.8 revises an imported listing: no category, condition, policies, location or format - eBay\'s stay', array_values( array_intersect( fields_of( $body ), array( 'PrimaryCategory', 'ConditionID', 'SellerProfiles', 'Location', 'PostalCode', 'ListingType', 'ListingDuration', 'BestOfferDetails', 'Regulatory' ) ) ), array() );
check( '  nothing asked of its tree, nor its conditions: none is sent', array_values( array_filter( $rest, function ( $r ) { return 0 !== strpos( $r, 'aspects:100:' ); } ) ), array() );
list( $res, $sites, $rest, $calls ) = push( from_motors( 'motors', 542, array(), array( '_ebay_export_item_type' => 'other', '_ebay_export_condition_id' => '1000' ) ) );
check( 'a whole condition of its own, New: its conditions read from EBAY_MOTORS_US, and sent', array( code_of( $res ), in_array( 'conditions:EBAY_MOTORS_US:35560', $rest, true ), value_of( $calls[0][1] ?? '', 'e:ConditionID' ), $sites ), array( 'updated', true, '1000', array( 'ReviseFixedPriceItem@100' ) ) );
list( $res, $sites ) = push( from_motors( 'motors', 543, array(), array( '_ebay_export_item_type' => 'other', '_ebay_export_condition_id' => '2500' ) ) );
check( '  one it does not take, 2500: refused, nothing sent', array( code_of( $res ), says( $res, '2500 is not a condition eBay takes in eBay Motors category 35560' ), $sites ), array( 'missing_export_settings', true, array() ) );
// A whole vehicle already on eBay Motors goes as it did in 3.28.6 and 3.28.8:
// its Update with the shop's own site, Item.Site US, no Motors check, and
// said; only a new listing (Add, Verify) in a vehicle category is refused.
$GLOBALS['W']['log'] = array();
list( $res, $sites, $rest, $calls ) = push( from_motors( 'motorcycle', 544, array( 'regular_price' => '13900.00' ) ) );
check( 'the imported whole motorcycle\'s price Update: as before 3.28.9 - ReviseFixedPriceItem with 0, Site US, no Motors conditions read', array( code_of( $res ), $sites, value_of( $calls[0][1] ?? '', 'e:Site' ), (bool) preg_grep( '/^conditions:/', $rest ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), 'US', false ) );
check( '  and said: vehicles are not otherwise supported', logged( 'is on eBay Motors and is a vehicle listing, so its Update goes as before 3.28.9, with the shop\'s own site. TCGiant Sync lists only eBay Motors Parts & Accessories and does not otherwise support vehicles.' ), 'info' );
$moto_set = exporter()->settle_motors( exporter()->get_export_settings( 544 ), false );
check( '  its settings: on eBayMotors, a vehicle listing, revised with the shop\'s own site, nothing refused', array( $moto_set['_listing_site'], $moto_set['_listing_branch'], $moto_set['_request_site'], TCGiant_Sync_Motors::problems( $moto_set, false ) ), array( 'eBayMotors', 'vehicles', '', array() ) );
list( $res, $sites, , $calls ) = push( from_motors( 'motorcycle', 546, array(), array( '_ebay_export_item_type' => 'other', '_ebay_export_condition_id' => '1000' ) ) );
check( '  with a whole condition of its own: sent as before, unchecked against eBay Motors', array( code_of( $res ), $sites, value_of( $calls[0][1] ?? '', 'e:ConditionID' ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), '1000' ) );
$GLOBALS['API']->tree_aspects['0']['11450'] = array();
list( $res, $sites, , $calls ) = push( from_motors( 'motorcycle', 548, array(), array( '_ebay_export_category_id' => '11450', '_ebay_export_item_type' => 'other' ) ) );
check( '  one whose Update sends an ordinary category of its own (11450): as 3.28.8 sent it - with 0, Site US, the category sent, no move refused', array( code_of( $res ), $sites, value_of( $calls[0][1] ?? '', 'e:Site' ), value_of( $calls[0][1] ?? '', 'e:PrimaryCategory/e:CategoryID' ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), 'US', '11450' ) );
parts_shop( array( 'export_category_id' => '11450', 'export_category_typed' => '11450' ) );
list( $res, $sites, , $calls ) = push( from_motors( 'motorcycle', 549, array(), array( '_ebay_export_follow_defaults' => '1' ) ) );
check( '  one with "Send my shop defaults" ticked, its price Update sending the shop\'s 11450: the same', array( code_of( $res ), $sites, value_of( $calls[0][1] ?? '', 'e:PrimaryCategory/e:CategoryID' ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), '11450' ) );
parts_shop();
check( '  a Parts & Accessories listing on eBay Motors moved to 11450 is still refused', isset( TCGiant_Sync_Motors::problems( array( '_motors_applies' => true, '_listing_site' => 'eBayMotors', '_listing_branch' => 'parts', 'category_id' => '11450', 'category_tree' => '', '_send_all' => true, '_source' => array( 'category' => 'default' ) ), false )['category'] ), true );
$verify_set = exporter()->settle_motors( exporter()->get_export_settings( part( 547, array( '_ebay_export_category_id' => '6472' ) )->get_id() ), true );
check( '  a new listing in a vehicle category (Verify, Add): refused', isset( TCGiant_Sync_Motors::problems( $verify_set, true )['category'] ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA LISTING'S SITE IS THE LISTING'S, NEVER ITS CATEGORY'S\n" . str_repeat( '=', 124 ) . "\n";

check( 'listing_site(): recorded; eBay\'s path in what was read; a pushed listing; a shop that never met eBay Motors; else not known', array(
	TCGiant_Sync_Motors::listing_site( 'US', array( 'category_id' => '35560', 'category_name' => 'eBay Motors:Parts & Accessories:Seats' ), 'import', 'US' ),
	TCGiant_Sync_Motors::listing_site( '', array( 'category_id' => '35560', 'category_name' => 'eBay Motors:Parts & Accessories:Seats' ), 'import', 'US' ),
	TCGiant_Sync_Motors::listing_site( '', array( 'category_id' => '183454', 'category_name' => 'Toys & Hobbies:CCG Individual Cards' ), 'import', 'US' ),
	TCGiant_Sync_Motors::listing_site( '', array(), 'push', 'US' ),
	TCGiant_Sync_Motors::listing_site( '', array(), 'import', 'US', false ),
	TCGiant_Sync_Motors::listing_site( '', array(), 'import', 'US', true ),
	TCGiant_Sync_Motors::listing_site( '', array( 'category_id' => '35560' ), 'import', 'US', true ),
), array( 'US', 'eBayMotors', 'US', 'US', 'US', '', '' ) );
TCGiant_Sync_Motors::learn( array( '6473' => array( TCGiant_Sync_Motors::BRANCH_VEHICLES, '6001' ) ) );
check( 'listing_branch(): its category\'s ID learnt; eBay\'s path; a pushed one is Parts & Accessories; else not known', array(
	TCGiant_Sync_Motors::listing_branch( array( 'category_id' => '6473' ), 'import' ),
	TCGiant_Sync_Motors::listing_branch( array( 'category_id' => '6472', 'category_name' => 'eBay Motors:Motorcycles:Triumph' ), 'push' ),
	TCGiant_Sync_Motors::listing_branch( array(), 'push' ),
	TCGiant_Sync_Motors::listing_branch( array( 'category_id' => '35999' ), 'import' ),
), array( 'vehicles', 'vehicles', 'parts', '' ) );

// The reviewer's case: a shop whose default is a Motors category, a listing
// imported before 3.27.5 recorded its site, no read of it since.
motors_ebay();
TCGiant_Sync_Motors::learn( array( '35560' => array( TCGiant_Sync_Motors::BRANCH_PARTS, '10063' ) ) );
$unrecorded = array( '_ebay_item_id' => '110000000561', '_ebay_origin' => 'import', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' );
list( $res, $sites, $rest, $calls ) = push( part( 561, $unrecorded, array( 'regular_price' => '70.00' ) ) );
check( 'a Motors default, an imported listing with no site recorded, a shop that never met eBay Motors: its Update with 0, Site US - not the default\'s eBay Motors', array( code_of( $res ), $sites, value_of( $calls[0][1] ?? '', 'e:Site' ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), 'US' ) );
update_option( 'tcgiant_sync_sites_seen', array( 'US' => time(), 'eBayMotors' => time() ) );
$GLOBALS['API']->answers = array( 'GetItem' => array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000562', 'Site' => 'US', 'ListingType' => 'FixedPriceItem', 'SellingStatus' => array( 'ListingStatus' => 'Active' ), 'PrimaryCategory' => array( 'CategoryID' => '183454', 'CategoryName' => 'Toys & Hobbies:CCG Individual Cards' ) ) ) );
list( $res, $sites, $rest, $calls ) = push( part( 562, array( '_ebay_item_id' => '110000000562' ) + $unrecorded, array( 'regular_price' => '70.00' ) ) );
check( '  in a shop that has met eBay Motors: read first (GetItem, which records it), on US, revised with 0', array( code_of( $res ), $sites, get_post_meta( 562, '_ebay_site', true ), value_of( end( $calls )[1] ?? '', 'e:Site' ) ), array( 'updated', array( 'GetItem@0', 'ReviseFixedPriceItem@0' ), 'US', 'US' ) );
$GLOBALS['API']->answers = array( 'GetItem' => array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000563', 'Site' => 'eBayMotors', 'ListingType' => 'FixedPriceItem', 'SellingStatus' => array( 'ListingStatus' => 'Active' ), 'PrimaryCategory' => array( 'CategoryID' => '35560', 'CategoryName' => 'eBay Motors:Parts & Accessories:Motorcycle & Scooter Parts:Vintage Motorcycle Parts' ) ) ) );
list( $res, $sites, $rest, $calls ) = push( part( 563, array( '_ebay_item_id' => '110000000563' ) + $unrecorded, array( 'regular_price' => '70.00' ) ) );
check( '  one the read finds on eBay Motors in Parts & Accessories: recorded, revised with 100, Site eBayMotors', array( code_of( $res ), $sites, get_post_meta( 563, '_ebay_site', true ), value_of( end( $calls )[1] ?? '', 'e:Site' ) ), array( 'updated', array( 'GetItem@0', 'ReviseFixedPriceItem@100' ), 'eBayMotors', 'eBayMotors' ) );
list( $res, $sites ) = push( wc_get_product( 563 ) );
check( '  and not read again', array( code_of( $res ), $sites ), array( 'updated', array( 'ReviseFixedPriceItem@100' ) ) );
$GLOBALS['API']->answers = array( 'GetItem' => new WP_Error( 'http', 'eBay did not answer' ) );
$GLOBALS['W']['log'] = array();
// The shop's default a Motors category nothing has learnt: a revise that
// does not send it never asks about it.
parts_shop( array( 'export_category_id' => '35561', 'export_category_typed' => '35561' ) );
list( $res, $sites, $rest, $calls ) = push( part( 564, array( '_ebay_item_id' => '110000000564' ) + $unrecorded, array( 'regular_price' => '70.00' ) ) );
check( '  the read failing: the Update with the shop\'s own site, as before, and said', array( code_of( $res ), $sites, value_of( end( $calls )[1] ?? '', 'e:Site' ), logged( 'so which eBay site it is on is not known: the Update went with the shop\'s own site, as before 3.28.9' ) ), array( 'updated', array( 'GetItem@0', 'ReviseFixedPriceItem@0' ), 'US', 'warning' ) );
check( '  and the default category it does not send not asked about: only the item specifics read the build makes, as before', array_values( preg_grep( '/35561/', $rest ) ), array( 'aspects:0:35561' ) );
parts_shop();
list( $res, $sites ) = push( wc_get_product( 564 ) );
check( '  pushed again within the hour: not read again', array( code_of( $res ), $sites ), array( 'updated', array( 'ReviseFixedPriceItem@0' ) ) );
$GLOBALS['API']->answers = array();
delete_option( 'tcgiant_sync_sites_seen' );

// Who made the listing, as 3.28.8 judges it (14e2b0b): only 'push', on its
// evidence, says the shop's own site; 'import' and UNKNOWN never do.
check( 'listing_site(): an origin of import or not known is never "made by a push"', array(
	TCGiant_Sync_Motors::listing_site( '', array(), 'import', 'US', true ),
	TCGiant_Sync_Motors::listing_site( '', array(), TCGiant_Sync_Ebay_Live::UNKNOWN, 'US', true ),
	TCGiant_Sync_Motors::listing_site( '', array(), 'push', 'US', true ),
), array( '', '', 'US' ) );
check( '  nor Parts & Accessories for its part of eBay Motors', array( TCGiant_Sync_Motors::listing_branch( array(), 'import' ), TCGiant_Sync_Motors::listing_branch( array(), TCGiant_Sync_Ebay_Live::UNKNOWN ) ), array( '', '' ) );
check( 'needs_origin(): the origin worked out only where the site or part turns on it', array(
	TCGiant_Sync_Motors::needs_origin( 'US', array() ),
	TCGiant_Sync_Motors::needs_origin( '', array( 'category_id' => '35560', 'category_name' => 'eBay Motors:Parts & Accessories:Seats' ) ),
	TCGiant_Sync_Motors::needs_origin( '', array() ),
	TCGiant_Sync_Motors::needs_origin( '', array( 'category_id' => '35560' ) ),
	TCGiant_Sync_Motors::needs_origin( 'eBayMotors', array() ),
	TCGiant_Sync_Motors::needs_origin( 'eBayMotors', array( 'category_id' => '6472', 'category_name' => 'eBay Motors:Motorcycles:Triumph' ) ),
), array( false, false, true, true, true, false ) );
// A listing whose site and origin are both not known, in a shop that has met
// eBay Motors: one GetItem judges who made it and records where it is.
update_option( 'tcgiant_sync_sites_seen', array( 'US' => time(), 'eBayMotors' => time() ) );
$both = part( 566, array( '_ebay_item_id' => '110000000566', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_export_status' => 'pushed' ), array( 'regular_price' => '70.00' ) );
check( '  a product nothing tells the origin of: UNKNOWN, and its site not known', array( TCGiant_Sync_Ebay_Live::origin( 566 ), exporter()->get_export_settings( 566 )['_listing_site'] ), array( TCGiant_Sync_Ebay_Live::UNKNOWN, '' ) );
$GLOBALS['API']->answers = array( 'GetItem' => array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000566', 'Site' => 'eBayMotors', 'ListingType' => 'FixedPriceItem', 'ListingDetails' => array( 'StartTime' => '2026-01-05T10:00:00.000Z' ), 'SellingStatus' => array( 'ListingStatus' => 'Active' ), 'PrimaryCategory' => array( 'CategoryID' => '35560', 'CategoryName' => 'eBay Motors:Parts & Accessories:Motorcycle & Scooter Parts:Vintage Motorcycle Parts' ) ) ) );
$GLOBALS['W']['log'] = array();
list( $res, $sites, $rest, $calls ) = push( $both );
check( '  its Update: one GetItem for both, the origin judged and kept, the site recorded, revised with 100', array( code_of( $res ), $sites, '' !== (string) get_post_meta( 566, '_ebay_origin', true ), get_post_meta( 566, '_ebay_site', true ), value_of( end( $calls )[1] ?? '', 'e:Site' ) ), array( 'updated', array( 'GetItem@0', 'ReviseFixedPriceItem@100' ), true, 'eBayMotors', 'eBayMotors' ) );
check( '  the read said for both', logged( 'as its start time, site had not been read' ), 'info' );
$GLOBALS['API']->answers = array();
delete_option( 'tcgiant_sync_sites_seen' );// A listing the product holds ended: its probing revise goes where the
// listing is, not where its new category would list.
list( $res, $sites ) = push( part( 565, array( '_ebay_item_id' => '110000000565', '_ebay_site' => 'US', '_ebay_origin' => 'push', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Ended' ) ) );
check( 'a listing held ended on eBay US, its product now in 35560: the revise that checks it goes with 0, the new listing in its place with 100', array( code_of( $res ), $sites ), array( 'updated', array( 'ReviseFixedPriceItem@0' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA NAME NEVER DECIDES: THE CATEGORY'S ID DOES\n" . str_repeat( '=', 124 ) . "\n";

// The reviewer's repro: the shop's default is a Motors category chosen with
// Browse (its path saved), a product has its own ordinary category typed in.
motors_ebay();
parts_shop( array( 'export_category_name' => 'eBay Motors > Parts & Accessories > Motorcycle & Scooter Parts > Vintage Motorcycle Parts' ) );
$GLOBALS['API']->tree_aspects['0']['11450'] = array();
list( $res, $sites, $rest, $calls ) = push( part( 571, array( '_ebay_export_category_id' => '11450', '_ebay_export_item_type' => 'other' ) ) );
check( 'a Motors default with its path, a product\'s own 11450 typed in: AddFixedPriceItem with 0, Site US, nothing asked of tree 100', array( code_of( $res ), $sites, value_of( $calls[0][1] ?? '', 'e:Site' ), (bool) preg_grep( '/:100:|MOTORS/', $rest ) ), array( 'created', array( 'AddFixedPriceItem@0' ), 'US', false ) );
$own = exporter()->get_export_settings( 571 );
check( '  its own ID never paired with the default\'s name', array( $own['category_id'], $own['category_name'], $own['category_tree'] ), array( '11450', '', 'unknown' ) );
list( $res, $sites, $rest ) = push( part( 572, array( '_ebay_export_category_id' => '183454', '_ebay_export_category_name' => 'eBay Motors > Parts & Accessories > Motorcycle & Scooter Parts > Vintage Motorcycle Parts', '_ebay_export_item_type' => 'other' ) ) );
check( 'an old eBay Motors name left on a product now in 183454: eBay US, and not asked about at all', array( code_of( $res ), $sites, array_values( array_unique( $rest ) ) ), array( 'created', array( 'AddFixedPriceItem@0' ), array( 'aspects:0:183454' ) ) );
parts_shop();
$GLOBALS['API']->tree_aspects['0']['11450'] = array();
list( $res, $sites ) = push( part( 573, array( '_ebay_export_category_id' => '11450', '_ebay_export_category_name' => 'eBay Motors:Parts & Accessories:Seats', '_ebay_export_item_type' => 'other' ) ) );
check( 'the same with the Motors name on the product itself: eBay US', array( code_of( $res ), $sites ), array( 'created', array( 'AddFixedPriceItem@0' ) ) );
parts_shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE REVISE'S AND RELIST'S SITE, 100 OR 0: EBAY DECIDES, ONCE A LISTING\n" . str_repeat( '=', 124 ) . "\n";

motors_ebay();
delete_option( TCGiant_Sync_Motors::SITES_OPTION );
$not_this_site = trading_error( 94, 'This listing cannot be edited or relisted because it was not created on this eBay site.' );
/** A listing pushed to eBay Motors, its own ID. */
function on_motors( $id ) {
	return part( $id, array( '_ebay_item_id' => '1100000' . str_pad( (string) $id, 5, '0', STR_PAD_LEFT ) ) + $GLOBALS['pushed_motors'], array( 'regular_price' => '75.00' ) );
}
$GLOBALS['pushed_motors'] = $pushed;
$GLOBALS['API']->site_answers = array( 'ReviseFixedPriceItem@100' => $not_this_site );
$GLOBALS['W']['log']          = array();
list( $res, $sites, , $calls ) = push( on_motors( 581 ) );
check( 'a revise eBay refuses with 100 for the site (94): sent once more with 0, the body as it was, and taken', array( code_of( $res ), $sites, value_of( $calls[0][1] ?? '', 'e:Site' ), ( $calls[0][1] ?? 'a' ) === ( $calls[1][1] ?? 'b' ) ), array( 'updated', array( 'ReviseFixedPriceItem@100', 'ReviseFixedPriceItem@0' ), 'eBayMotors', true ) );
check( '  kept on the listing\'s product, the first guess for listings with none, and said', array( get_post_meta( 581, '_ebay_motors_revise_site', true ), get_option( TCGiant_Sync_Motors::SITES_OPTION ), logged( 'and took it with 0, so that listing\'s revise goes with 0 first from now on, as does that of any eBay Motors listing eBay has not yet taken one with' ) ), array( '0', array( 'revise' => '0' ), 'warning' ) );
list( $res, $sites ) = push( wc_get_product( 581 ) );
check( '  its next revise: with 0, one call', array( code_of( $res ), $sites ), array( 'updated', array( 'ReviseFixedPriceItem@0' ) ) );
list( $res, $sites ) = push( on_motors( 582 ) );
check( '  another listing with none yet: the first guess, 0, taken - and kept on it', array( code_of( $res ), $sites, get_post_meta( 582, '_ebay_motors_revise_site', true ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), '0' ) );

// Listings that need different sites, pushed in turn: each costs one call
// more, once - the shop-wide guess never flips one that eBay has answered.
delete_option( TCGiant_Sync_Motors::SITES_OPTION );
$GLOBALS['API']->site_answers = array( 'ReviseFixedPriceItem@100#110000000591' => $not_this_site, 'ReviseFixedPriceItem@0#110000000592' => $not_this_site );
$all = array();
foreach ( array( 591, 592, 591, 592, 591, 592 ) as $n => $id ) {
	list( $res, $sites ) = push( $n < 2 ? on_motors( $id ) : wc_get_product( $id ) );
	$all[] = $id . ':' . implode( ',', $sites ) . ':' . code_of( $res );
}
check( 'two listings, one taken only with 0, the other only with 100, pushed in turn three times each: one call more for each, once', $all, array(
	'591:ReviseFixedPriceItem@100,ReviseFixedPriceItem@0:updated',
	'592:ReviseFixedPriceItem@0,ReviseFixedPriceItem@100:updated',
	'591:ReviseFixedPriceItem@0:updated',
	'592:ReviseFixedPriceItem@100:updated',
	'591:ReviseFixedPriceItem@0:updated',
	'592:ReviseFixedPriceItem@100:updated',
) );
check( '  each kept on its own product', array( get_post_meta( 591, '_ebay_motors_revise_site', true ), get_post_meta( 592, '_ebay_motors_revise_site', true ) ), array( '0', '100' ) );
check( '  dropped with the listing\'s other identity keys (Unlink, a copy)', array_values( array_intersect( array( '_ebay_motors_revise_site', '_ebay_motors_relist_site' ), TCGiant_Sync_Listing_Link::listing_identity_meta_keys() ) ), array( '_ebay_motors_revise_site', '_ebay_motors_relist_site' ) );
$kept_bad = on_motors( 593 );
update_post_meta( 593, '_ebay_motors_revise_site', '3' );
$GLOBALS['API']->site_answers = array();
list( $res, $sites ) = push( $kept_bad );
check( '  a kept site eBay does not have: the first guess instead, and what eBay took kept', array( $sites, get_post_meta( 593, '_ebay_motors_revise_site', true ) ), array( array( 'ReviseFixedPriceItem@100' ), '100' ) );

// What counts as refused for the site.
delete_option( TCGiant_Sync_Motors::SITES_OPTION );
$GLOBALS['API']->site_answers = array( 'ReviseFixedPriceItem@100' => trading_error( 107, 'The category is not valid, select another category.', array( '35560' ) ) );
list( $res, $sites ) = push( on_motors( 594 ) );
check( '107 naming the listing\'s category: refused for the site, 0 taken', array( code_of( $res ), $sites ), array( 'updated', array( 'ReviseFixedPriceItem@100', 'ReviseFixedPriceItem@0' ) ) );
delete_option( TCGiant_Sync_Motors::SITES_OPTION );
$GLOBALS['API']->site_answers = array( 'ReviseFixedPriceItem@100' => trading_error( 107, 'The category is not valid, select another category.', array( 'Site' ) ) );
list( $res, $sites ) = push( on_motors( 596 ) );
check( '107 whose parameter names the site: refused for the site, 0 taken', array( code_of( $res ), $sites ), array( 'updated', array( 'ReviseFixedPriceItem@100', 'ReviseFixedPriceItem@0' ) ) );
delete_option( TCGiant_Sync_Motors::SITES_OPTION );
$GLOBALS['API']->site_answers = array( 'ReviseFixedPriceItem@100' => trading_error( 86, 'The category selected is not a valid category for this site.' ) );
list( $res, $sites ) = push( on_motors( 595 ) );
check( '86 saying "for this site": refused for the site, 0 taken', array( code_of( $res ), $sites ), array( 'updated', array( 'ReviseFixedPriceItem@100', 'ReviseFixedPriceItem@0' ) ) );
delete_option( TCGiant_Sync_Motors::SITES_OPTION );
$plain = array(
	'107 neither naming the site nor the listing\'s category'    => trading_error( 107, 'The category is not valid, select another category.' ),
	'107 naming another category (a store category, say)'       => trading_error( 107, 'The category is not valid, select another category.', array( '99999' ) ),
	'21670 a warning beside the Failure\'s own error (21919137)' => trading_error( 21919137, 'Item could not be revised.', array(), 'Error', array( array( 'ErrorCode' => '21670', 'ShortMessage' => 'Invalid Category ID.', 'LongMessage' => 'The category ID does not correspond with a category on the selected site.', 'SeverityCode' => 'Warning' ) ) ),
	'94 as a warning'                                            => trading_error( 94, 'This listing cannot be edited or relisted because it was not created on this eBay site.', array(), 'Warning' ),
	'94 in an answer that is not a Failure'                     => new WP_Error( 'api_error', 'x', array( 'Ack' => 'Warning', 'Errors' => array( 'ErrorCode' => '94', 'SeverityCode' => 'Error', 'LongMessage' => 'not created on this eBay site' ) ) ),
	'another error (21919028)'                                   => trading_error( 21919028, 'Item specific Brand is missing.' ),
);
$id = 600;
foreach ( $plain as $what => $answer ) {
	$GLOBALS['API']->site_answers = array( 'ReviseFixedPriceItem@100' => $answer );
	list( $res, $sites ) = push( on_motors( ++$id ) );
	check( 'not refused for the site, ' . $what . ': not sent again, nothing kept', array( code_of( $res ), $sites, get_post_meta( $id, '_ebay_motors_revise_site', true ), get_option( TCGiant_Sync_Motors::SITES_OPTION, 'none' ) ), array( 'api_error', array( 'ReviseFixedPriceItem@100' ), '', 'none' ) );
}

$GLOBALS['API']->site_answers = array( 'ReviseFixedPriceItem@100' => $not_this_site, 'ReviseFixedPriceItem@0' => trading_error( 86, 'The category selected is not a valid category for this site.' ) );
$GLOBALS['W']['log']          = array();
list( $res, $sites ) = push( on_motors( 584 ) );
check( 'refused with both: eBay\'s second answer, nothing kept, and said', array( is_wp_error( $res ) ? $res->get_error_message() : null, $sites, get_post_meta( 584, '_ebay_motors_revise_site', true ), get_option( TCGiant_Sync_Motors::SITES_OPTION, 'none' ), logged( 'eBay refused the revise of eBay listing 110000000584 of WC #584 with X-EBAY-API-SITEID 100 (This listing cannot be edited or relisted because it was not created on this eBay site.), and with 0' ) ), array( 'The category selected is not a valid category for this site.', array( 'ReviseFixedPriceItem@100', 'ReviseFixedPriceItem@0' ), '', 'none', 'warning' ) );
$GLOBALS['API']->site_answers = array( 'AddFixedPriceItem@100' => trading_error( 107, 'The category is not valid, select another category.', array( '35560' ) ) );
list( $res, $sites ) = push( part( 586 ) );
check( 'a new listing is never tried with 0: eBay\'s guide says 100', array( code_of( $res ), $sites ), array( 'api_error', array( 'AddFixedPriceItem@100' ) ) );
$GLOBALS['API']->site_answers = array( 'RelistFixedPriceItem@100' => $not_this_site );
$GLOBALS['API']->answers      = array( 'GetItem' => array( 'Ack' => 'Success', 'Item' => $ended ), 'RelistFixedPriceItem' => array( 'Ack' => 'Success', 'ItemID' => '110000000687' ) );
part( 587, array( '_ebay_listing_status' => 'Ended' ) + $pushed );
update_post_meta( 587, '_ebay_item_id', '110000000504' );
$GLOBALS['API']->clear();
$relisted = exporter()->relist_ended_listing( 587, 'Relist' );
check( 'a relist the same: 100 refused for the site, 0 taken, kept for relists apart', array( is_array( $relisted ) ? ( $relisted['item_id'] ?? null ) : $relisted->get_error_message(), $GLOBALS['API']->sites, get_post_meta( 587, '_ebay_motors_relist_site', true ), get_post_meta( 587, '_ebay_motors_revise_site', true ), get_option( TCGiant_Sync_Motors::SITES_OPTION ) ), array( '110000000687', array( 'GetItem@0', 'RelistFixedPriceItem@100', 'RelistFixedPriceItem@0' ), '0', '', array( 'relist' => '0' ) ) );
$GLOBALS['API']->site_answers = array( 'RelistFixedPriceItem@0' => trading_error( 21201, 'You can only relist this when you are on eBay Motors.' ) );
update_post_meta( 587, '_ebay_item_id', '110000000504' );
$GLOBALS['API']->clear();
$relisted = exporter()->relist_ended_listing( 587, 'Relist' );
check( '  and the other order (21201 with 0, the site it kept): 100, kept', array( is_array( $relisted ) ? ( $relisted['item_id'] ?? null ) : $relisted->get_error_message(), $GLOBALS['API']->sites, get_post_meta( 587, '_ebay_motors_relist_site', true ), get_option( TCGiant_Sync_Motors::SITES_OPTION ) ), array( '110000000687', array( 'GetItem@0', 'RelistFixedPriceItem@0', 'RelistFixedPriceItem@100' ), '100', array( 'relist' => '100' ) ) );
check( 'call_site(): a stored site eBay does not have is 100', array( update_option( TCGiant_Sync_Motors::SITES_OPTION, array( 'revise' => '3' ) ) && false, TCGiant_Sync_Motors::call_site( 'revise' ), TCGiant_Sync_Motors::call_site( 'relist' ) ), array( false, '100', '100' ) );
$GLOBALS['API']->site_answers = array();
$GLOBALS['API']->answers      = array();
delete_option( TCGiant_Sync_Motors::SITES_OPTION );
check( '  deleting the plugin with its data forgets it', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ), "'" . TCGiant_Sync_Motors::SITES_OPTION . "'," ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEBAY NOT ANSWERING: HELD FOR AN HOUR, AND SAID\n" . str_repeat( '=', 124 ) . "\n";

motors_ebay();
parts_shop( array( 'export_category_id' => '35562', 'export_category_typed' => '35562' ) );
$GLOBALS['API']->tree_aspects['0']['35562'] = motors_error( 62005 );
$GLOBALS['API']->nodes['35562']             = motors_error( 99999, 500 );
list( $res, $sites, $rest ) = push( part( 591 ) );
check( 'not in tree 0, tree 100 answering 500: refused, try again in an hour - nothing sent', array( code_of( $res ), says( $res, 'eBay did not say whether 35562 is an eBay Motors Parts & Accessories category (eBay API Error 500), so nothing was sent: try again in an hour' ), $sites, $rest ), array( 'missing_export_settings', true, array(), array( 'aspects:0:35562', 'node:100:35562' ) ) );
list( $res, $sites, $rest ) = push( part( 592 ) );
check( '  the next push within the hour: refused the same, and nothing asked', array( code_of( $res ), says( $res, 'try again in an hour' ), $rest ), array( 'missing_export_settings', true, array() ) );
unset( $GLOBALS['W']['transients'][ TCGiant_Sync_Motors::HOLD_TRANSIENT . '35562' ] );
$GLOBALS['API']->nodes['35562'] = motors_node( '35562', 'Seats', '10063' );
$GLOBALS['API']->suggest['Seats'] = motors_error( 99999, 503 );
list( $res, $sites, $rest ) = push( part( 593 ) );
check( '  after the hour: asked again; its suggestions failing: held the same, nothing learnt', array( says( $res, 'try again in an hour' ), $rest, TCGiant_Sync_Motors::record( '35562' ) ), array( true, array( 'aspects:0:35562', 'node:100:35562', 'suggest:100:Seats' ), null ) );
parts_shop( array( 'export_category_id' => '11450', 'export_category_typed' => '11450' ) );
$GLOBALS['API']->tree_aspects['0']['11450'] = motors_error( 99999, 500 );
list( $res, $sites, $rest ) = push( part( 594 ) );
$first = count( $rest );
list( $res, $sites, $rest ) = push( part( 595 ) );
check( 'an ordinary category whose item specifics eBay does not give: pushed as before, and the probe\'s read made once in the hour, not on every push', array( $sites, $first - count( $rest ), get_transient( TCGiant_Sync_Motors::HOLD_TRANSIENT . '11450' )['step'] ?? null ), array( array( 'AddFixedPriceItem@0' ), 1, 'ordinary' ) );
parts_shop();
// One imported before 3.28.8 recorded eBay's values or its site: the push's
// read (3.28.8 round 5) records eBayMotors, and the Update then goes there.
$old_import = from_motors( 'motors', 545, array(), array( '_ebay_export_item_type' => 'other', '_ebay_export_condition_id' => '1000' ) );
delete_post_meta( 545, '_ebay_live' );
delete_post_meta( 545, '_ebay_site' );
$GLOBALS['API']->answers = array( 'GetItem' => array( 'Ack' => 'Success', 'Item' => $GLOBALS['MOTORS']['answers']['motors'] ) );
list( $res, $sites, $rest, $calls ) = push( $old_import );
$GLOBALS['API']->answers = array();
check( 'one imported before its site was recorded, its category unread: read first, recorded on eBayMotors, revised with 100', array( code_of( $res ), $sites, get_post_meta( 545, '_ebay_site', true ), value_of( end( $calls )[1] ?? '', 'e:ConditionID' ) ), array( 'updated', array( 'GetItem@0', 'ReviseFixedPriceItem@100' ), 'eBayMotors', '1000' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE TREES CANNOT BE MIXED UP\n" . str_repeat( '=', 124 ) . "\n";

motors_ebay();
TCGiant_Sync_Motors::learn( array( '2536' => array( TCGiant_Sync_Motors::BRANCH_PARTS, '6028' ) ) );
parts_shop( array( 'export_category_id' => '2536' ) );
$motors_set = exporter()->get_export_settings( part( 551 )->get_id() );
check( 'an ID 3.28.1 retired (2536) learnt as eBay Motors\': not mapped as eBay US\'s retired ID, tree 100', array( $motors_set['category_id'], isset( $motors_set['category_retired'] ), $motors_set['category_tree'] ), array( '2536', false, '100' ) );
delete_option( TCGiant_Sync_Motors::OPTION );
$us_set = exporter()->get_export_settings( 551 );
check( '  the same ID not learnt: eBay US\'s, mapped as 3.28.1 maps it', array( $us_set['category_id'], $us_set['category_retired']['from'] ?? null, $us_set['category_tree'] ), array( '183454', '2536', '' ) );
parts_shop();
update_option( TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION, array( '35560' => '253' ) );
check( 'the import\'s coin parents never answer for a category on eBay Motors\' path, whatever was learnt under its ID', array( TCGiant_Sync_Category_Memory::coin_parent( '35560', 'eBay Motors:Parts & Accessories:Other' ), TCGiant_Sync_Category_Memory::coin_parent( '35560', '' ) ), array( '', '253' ) );
delete_option( TCGiant_Sync_Category_Memory::COIN_LEAVES_OPTION );
// One of eBay US's coin parents' IDs (253) learnt as eBay Motors': listed on
// eBay Motors, not refused as a coin parent.
motors_ebay();
TCGiant_Sync_Motors::learn( array( '253' => array( TCGiant_Sync_Motors::BRANCH_PARTS, '6028' ) ) );
$GLOBALS['API']->conds['253'] = motors_conditions( '253', array( '1000' => 'New' ) );
list( $res, $sites ) = push( part( 553, array( '_ebay_export_category_id' => '253', '_ebay_export_item_type' => 'other' ) ) );
check( '  an ID in the plugin\'s own list (253, a coin parent) learnt as eBay Motors\': eBay US\'s, as the list says, and refused as the coin parent it is', array( code_of( $res ), $sites, in_array( 'conditions:EBAY_MOTORS_US:253', $GLOBALS['API']->rest, true ) ), array( 'missing_export_settings', array(), false ) );
delete_option( TCGiant_Sync_Motors::OPTION );
$us_listing = from_motors( 'motors', 552, array(), array( '_ebay_site' => 'US' ) );
$us_live    = exporter()->get_export_settings( 552 );
check( 'eBay\'s path says Motors but the listing is recorded on US (3.28.8\'s caliper): its own site decides', array( $us_live['category_tree'], TCGiant_Sync_Motors::request_site( $us_live, true ) ), array( '', '' ) );
$rec = TCGiant_Sync_Motors::record( '35560' );
check( 'what is learnt is kept by tree: "100:<ID>"', array( null === $rec, array_keys( (array) get_option( TCGiant_Sync_Motors::OPTION, array() ) ) ), array( true, array() ) );
$GLOBALS['API']->clear();
TCGiant_Sync_Motors::learn( array( 'x35560' => array( 'parts', '6028' ), '' => array( 'parts', '6028' ), '35560<b>' => array( 'parts', '6028' ) ) );
check( '  an ID not in eBay\'s form is never learnt, nor looked up', array( get_option( TCGiant_Sync_Motors::OPTION, 'none' ), TCGiant_Sync_Motors::record( 'x35560' ), TCGiant_Sync_Motors::resolve( 'x35560' ), $GLOBALS['API']->rest ), array( 'none', null, array( 'tree' => '', 'branch' => '', 'said' => '', 'wait' => '' ), array() ) );
TCGiant_Sync_Motors::learn( array( '35560' => array( 'parts', '10063' ) ) );
check( '  learnt',array( array_keys( (array) get_option( TCGiant_Sync_Motors::OPTION ) ), TCGiant_Sync_Motors::record( '35560' ) ), array( array( '100:35560' ), array( 'b' => 'parts', 'p' => '10063' ) ) );
TCGiant_Sync_Motors::$clock = function () { return time() + TCGiant_Sync_Motors::KEEP_SECONDS + 10; };
check( '  and forgotten after 90 days', TCGiant_Sync_Motors::record( '35560' ), null );
TCGiant_Sync_Motors::$clock = null;
$many = array();
for ( $i = 1; $i <= TCGiant_Sync_Motors::MAX + 5; $i++ ) {
	$many[ (string) ( 700000 + $i ) ] = array( 'parts', '6028' );
}
TCGiant_Sync_Motors::learn( $many );
$kept = (array) get_option( TCGiant_Sync_Motors::OPTION );
check( '  never more than MAX: the ones learnt longest ago go', array( count( $kept ), isset( $kept['100:35560'] ), isset( $kept[ '100:' . ( 700000 + TCGiant_Sync_Motors::MAX + 5 ) ] ) ), array( TCGiant_Sync_Motors::MAX, false, true ) );
delete_option( TCGiant_Sync_Motors::OPTION );
check( 'paths: Parts & Accessories, a vehicle branch, another, and not eBay Motors\'', array( TCGiant_Sync_Motors::branch_of_path( 'eBay Motors:Parts & Accessories:Brakes' ), TCGiant_Sync_Motors::branch_of_path( 'eBay Motors > Motorcycles > Triumph' ), TCGiant_Sync_Motors::branch_of_path( 'eBay Motors: Parts & Accessories > Seats' ), TCGiant_Sync_Motors::branch_of_path( 'eBay Motors:Other Motors' ), TCGiant_Sync_Motors::branch_of_path( 'Toys & Hobbies:Collectible Card Games' ), TCGiant_Sync_Motors::branch_of_path( 'eBay Motors' ), TCGiant_Sync_Motors::branch_of_path( 'eBay Motors:Auto Parts and Accessories:Brakes' ) ), array( 'parts', 'vehicles', 'parts', 'other', '', '', 'parts' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA POLICY FOR MOTOR VEHICLES ONLY, ON AN ORDINARY PUSH (3.29.2)\n" . str_repeat( '=', 124 ) . "\n";

// eBay US lists every motor vehicle on eBay Motors, so a shipping or payment
// policy eBay keeps for motor vehicles only is one it refuses on eBay US too.
// An ordinary push is refused here for one, in words of its own that name the
// policy and say nothing of eBay Motors (a Motors push keeps 3.28.9's), and
// nothing is sent - only where the policy's category types are known
// ("Fetch / Refresh Policies" since 3.28.9), and only for a push that sends
// business policies.

/** The shop's cached policies: shipping 250000021 and payment 250000023 with these types (null: fetched before 3.28.9), and an ordinary 240000071. */
function policy_types( $shipping, $payment = array( 'ALL_EXCLUDING_MOTORS_VEHICLES' ) ) {
	$row = function ( $id, $name, $types ) {
		return array( 'id' => $id, 'name' => $name ) + ( null === $types ? array() : array( 'category_types' => $types ) );
	};
	update_option( 'tcgiant_export_policies_fulfillment', array( $row( '250000021', 'Truck freight', $shipping ), $row( '240000071', 'Parcel', array( 'ALL_EXCLUDING_MOTORS_VEHICLES' ) ) ) );
	update_option( 'tcgiant_export_policies_payment', array( $row( '250000023', 'Vehicle deposit', $payment ), $row( '240000073', 'Pay', array( 'ALL_EXCLUDING_MOTORS_VEHICLES' ) ) ) );
}
function no_policy_types() {
	delete_option( 'tcgiant_export_policies_fulfillment' );
	delete_option( 'tcgiant_export_policies_payment' );
}
function without_uuid( $body ) {
	return preg_replace( '#<UUID>[^<]*</UUID>#', '', (string) $body );
}
function profile_ids( $body ) {
	return array( value_of( $body, 'e:SellerProfiles/e:SellerShippingProfile/e:ShippingProfileID' ), value_of( $body, 'e:SellerProfiles/e:SellerReturnProfile/e:ReturnProfileID' ), value_of( $body, 'e:SellerProfiles/e:SellerPaymentProfile/e:PaymentProfileID' ) );
}
$vehicles     = array( 'MOTORS_VEHICLES' );
$everything   = array( 'ALL_EXCLUDING_MOTORS_VEHICLES' );
$motors_words = 'Truck freight is a business policy for motor vehicles only, and eBay Motors Parts & Accessories take one for everything except motor vehicles: choose another';
$own_words    = 'This product\'s shipping policy "Truck freight" is for motor vehicles only on eBay, so eBay would refuse this listing. Choose another business policy for it, or press Fetch / Refresh Policies from eBay in Settings if you\'ve changed it on eBay';
$own_payment  = 'This product\'s payment policy "Vehicle deposit" is for motor vehicles only on eBay, so eBay would refuse this listing. Choose another business policy for it, or press Fetch / Refresh Policies from eBay in Settings if you\'ve changed it on eBay';

motors_ebay();
parts_shop( array( 'export_category_id' => '33564', 'export_category_typed' => '33564' ) );

// A new listing in an ordinary category.
no_policy_types();
list( $res, $sites, , $calls ) = push( part( 561 ) );
$plain_add = without_uuid( $calls[0][1] ?? '' );
check( 'an ordinary new listing (33564), no policy types known: listed with the shop\'s policies, as before', array( code_of( $res ), $sites, profile_ids( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@0' ), array( '250000021', '250000022', '250000023' ) ) );
policy_types( $vehicles );
list( $res, $sites ) = push( part( 561 ) );
check( '  its shipping policy for motor vehicles only: refused in its own words, naming the shipping policy - nothing sent', array( code_of( $res ), says( $res, 'Business Policies — ' . $own_words ), $sites ), array( 'missing_export_settings', true, array() ) );
policy_types( $everything, $vehicles );
list( $res, $sites ) = push( part( 561 ) );
check( '  its payment policy for motor vehicles only: refused, naming the payment policy', array( code_of( $res ), says( $res, 'Business Policies — ' . $own_payment ), $sites ), array( 'missing_export_settings', true, array() ) );
policy_types( array( 'MOTORS_VEHICLES', 'ALL_EXCLUDING_MOTORS_VEHICLES' ) );
list( $res, $sites, , $calls ) = push( part( 561 ) );
check( '  for everything but motor vehicles (one for vehicles too): listed, the body byte for byte as with no types known', array( code_of( $res ), $sites, without_uuid( $calls[0][1] ?? '' ) === $plain_add ), array( 'created', array( 'AddFixedPriceItem@0' ), true ) );
policy_types( null, null );
list( $res, $sites, , $calls ) = push( part( 561 ) );
check( '  fetched before 3.28.9, no types kept: listed, for eBay to judge - byte for byte as before', array( code_of( $res ), $sites, without_uuid( $calls[0][1] ?? '' ) === $plain_add ), array( 'created', array( 'AddFixedPriceItem@0' ), true ) );
update_option( 'tcgiant_export_policies_fulfillment', array( array( 'id' => '240000071', 'name' => 'Parcel', 'category_types' => $vehicles ) ) );
list( $res, $sites, , $calls ) = push( part( 561 ) );
check( '  the shop\'s policy not among those fetched (another for vehicles is): listed, byte for byte as before', array( code_of( $res ), $sites, without_uuid( $calls[0][1] ?? '' ) === $plain_add ), array( 'created', array( 'AddFixedPriceItem@0' ), true ) );

// Verify, the eBay tab and bulk Verify judge it as the push does.
policy_types( $vehicles );
$tcg_set   = exporter()->get_export_settings( part( 562 )->get_id() );
$tcg_valid = exporter()->validate_export_settings( exporter()->settle_motors( $tcg_set, true ), true );
check( 'Verify of an ordinary new listing (settled as one): refused the same way', array( code_of( $tcg_valid ), says( $tcg_valid, 'Business Policies — ' . $own_words ) ), array( 'missing_export_settings', true ) );
$tcg_valid = exporter()->validate_export_settings( $tcg_set, true );
check( '  and unsettled, as the eBay tab and bulk Verify check it: the same', array( code_of( $tcg_valid ), says( $tcg_valid, 'Business Policies — ' . $own_words ) ), array( 'missing_export_settings', true ) );
parts_shop();
$tcg_motors = exporter()->settle_motors( exporter()->get_export_settings( part( 563 )->get_id() ), true );
parts_shop( array( 'export_category_id' => '33564', 'export_category_typed' => '33564' ) );
$tcg_ordinary = exporter()->settle_motors( exporter()->get_export_settings( part( 562 )->get_id() ), true );
check( '  a Motors push in 35560 with the same policy: 3.28.9\'s words; the ordinary push its own, which say nothing of eBay Motors', array( $tcg_motors['_request_site'], TCGiant_Sync_Motors::problems( $tcg_motors, true )['policies'] ?? null, $tcg_ordinary['_request_site'], TCGiant_Sync_Motors::problems( $tcg_ordinary, true )['policies'] ?? null, false !== strpos( $own_words . $own_payment, 'eBay Motors' ) ), array( 'eBayMotors', $motors_words, '', $own_words, false ) );
policy_types( $everything, $vehicles );
check( '  its payment policy for vehicles only: the Motors push 3.28.9\'s words naming it, the ordinary push its own', array( TCGiant_Sync_Motors::problems( $tcg_motors, true )['policies'] ?? null, TCGiant_Sync_Motors::problems( $tcg_ordinary, true )['policies'] ?? null ), array( 'Vehicle deposit is a business policy for motor vehicles only, and eBay Motors Parts & Accessories take one for everything except motor vehicles: choose another', $own_payment ) );

// A revise of a pushed listing on eBay US: it sends the shop's policies.
$us_pushed = array( '_ebay_item_id' => '110000000564', '_ebay_site' => 'US', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-10-31 10:00:00', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' );
no_policy_types();
list( $res, $sites, , $calls ) = push( part( 564, $us_pushed ) );
$plain_revise = without_uuid( $calls[0][1] ?? '' );
check( 'a pushed listing on eBay US, no policy types known: its Update sends the shop\'s policies, as before', array( code_of( $res ), $sites, profile_ids( $calls[0][1] ?? '' ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), array( '250000021', '250000022', '250000023' ) ) );
policy_types( $vehicles );
list( $res, $sites ) = push( part( 564, $us_pushed ) );
check( '  its shipping policy for motor vehicles only: refused the same way, no ReviseFixedPriceItem', array( code_of( $res ), says( $res, 'Business Policies — ' . $own_words ), $sites ), array( 'missing_export_settings', true, array() ) );
policy_types( $everything );
list( $res, $sites, , $calls ) = push( part( 564, $us_pushed ) );
check( '  for everything but motor vehicles: updated, byte for byte as with no types known', array( code_of( $res ), $sites, without_uuid( $calls[0][1] ?? '' ) === $plain_revise ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), true ) );

// An imported listing on eBay US: its price Update sends no business
// policies, so nothing about them is held against it.
$us_import = array( '_ebay_item_id' => '110000000565', '_ebay_site' => 'US', '_ebay_origin' => 'import', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_live' => array( 'item_id' => '110000000565', 'policies' => array( 'shipping' => '240000071', 'return' => '240000072', 'payment' => '240000073' ) ) );
no_policy_types();
list( $res, $sites, , $calls ) = push( part( 565, $us_import ) );
$plain_import = without_uuid( $calls[0][1] ?? '' );
check( 'an imported listing on eBay US, its price Update: no SellerProfiles, as 3.28.8 sends it', array( code_of( $res ), $sites, in_array( 'SellerProfiles', fields_of( $calls[0][1] ?? '' ), true ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), false ) );
policy_types( $vehicles, $vehicles );
update_option( 'tcgiant_export_policies_fulfillment', array_merge( get_option( 'tcgiant_export_policies_fulfillment' ), array( array( 'id' => '240000071', 'name' => 'Its own', 'category_types' => $vehicles ) ) ) );
list( $res, $sites, , $calls ) = push( part( 565, $us_import ) );
check( '  every policy fetched, its own and the shop\'s, for motor vehicles only: updated all the same, byte for byte', array( code_of( $res ), $sites, without_uuid( $calls[0][1] ?? '' ) === $plain_import ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), true ) );
policy_types( $vehicles );
list( $res, $sites ) = push( part( 565, array( '_ebay_export_fulfillment_policy' => '250000021' ) + $us_import ) );
check( '  with a shipping policy of its own for motor vehicles only, which its Update sends: refused', array( code_of( $res ), says( $res, 'Business Policies — ' . $own_words ), $sites ), array( 'missing_export_settings', true, array() ) );
policy_types( null, null );
list( $res, $sites, , $calls ) = push( part( 565, array( '_ebay_export_fulfillment_policy' => '250000021' ) + $us_import ) );
check( '  the same, its types not known: sent with eBay\'s own two, as before', array( code_of( $res ), $sites, profile_ids( $calls[0][1] ?? '' ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), array( '250000021', '240000072', '240000073' ) ) );

// A whole vehicle already on eBay Motors is revised as before 3.28.9: its own
// policy may well be for vehicles only.
no_policy_types();
list( $res, $sites, , $calls ) = push( from_motors( 'motorcycle', 566, array(), array( '_ebay_export_fulfillment_policy' => '250000021' ) ) );
$plain_bike = without_uuid( $calls[0][1] ?? '' );
check( 'the imported whole motorcycle with a shipping policy of its own: sent with its own two, with 0, as before', array( code_of( $res ), $sites, profile_ids( $calls[0][1] ?? '' ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), array( '250000021', '240000092', '240000093' ) ) );
policy_types( $vehicles );
update_option( 'tcgiant_export_policies_payment', array( array( 'id' => '240000093', 'name' => 'Bike deposit', 'category_types' => $vehicles ) ) );
list( $res, $sites, , $calls ) = push( from_motors( 'motorcycle', 566, array(), array( '_ebay_export_fulfillment_policy' => '250000021' ) ) );
check( '  both for motor vehicles only: not refused - updated byte for byte as before', array( code_of( $res ), $sites, without_uuid( $calls[0][1] ?? '' ) === $plain_bike ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), true ) );

// The rest, by the settings problems() is given.
policy_types( $vehicles );
$tcg_base  = array( '_motors_applies' => true, 'category_id' => '33564', 'category_tree' => '', 'fulfillment_policy_id' => '250000021', 'return_policy_id' => '250000022', 'payment_policy_id' => '250000023' );
$tcg_parts = array( 'category_id' => '35560', 'category_tree' => '100', '_motors_branch' => 'parts' );
$tcg_cases = array(
	'a new listing on eBay US'                                              => array( $tcg_base, true, $own_words ),
	'a revise of a listing on eBay US'                                      => array( array( '_listing_site' => 'US' ) + $tcg_base, false, $own_words ),
	'  an imported one\'s price Update, which sends no policies'            => array( array( '_listing_site' => 'US', '_source' => array( 'policies' => 'default' ) ) + $tcg_base, false, false ),
	'  one with a shipping policy of its own'                               => array( array( '_listing_site' => 'US', '_source' => array( 'policies' => 'override' ) ) + $tcg_base, false, $own_words ),
	'a new listing on eBay Motors (Parts & Accessories)'                    => array( $tcg_parts + $tcg_base, true, $motors_words ),
	'a revise of a Parts & Accessories listing on eBay Motors'              => array( array( '_listing_site' => 'eBayMotors', '_listing_branch' => 'parts' ) + $tcg_parts + $tcg_base, false, $motors_words ),
	'a revise of a whole vehicle on eBay Motors (as before 3.28.9)'         => array( array( '_listing_site' => 'eBayMotors', '_listing_branch' => 'vehicles' ) + $tcg_base, false, false ),
	'a revise of one on eBay Motors whose part is not known (as before)'    => array( array( '_listing_site' => 'eBayMotors', '_listing_branch' => '' ) + $tcg_base, false, false ),
	'a revise of a listing whose site is not known'                         => array( array( '_listing_site' => '' ) + $tcg_base, false, false ),
	'a revise of a listing on another site (eBay UK)'                       => array( array( '_listing_site' => 'UK' ) + $tcg_base, false, false ),
	'a shop on eBay UK: a new listing'                                      => array( array( '_motors_applies' => false ) + $tcg_base, true, false ),
	'  and a revise'                                                        => array( array( '_motors_applies' => false, '_listing_site' => 'UK' ) + $tcg_base, false, false ),
);
foreach ( $tcg_cases as $tcg_label => $tcg_case ) {
	$tcg_found = TCGiant_Sync_Motors::problems( $tcg_case[0], $tcg_case[1] );
	check( sprintf( '%s: %s', $tcg_label, false === $tcg_case[2] ? 'not refused for it, as before' : ( $motors_words === $tcg_case[2] ? 'refused for it, in 3.28.9\'s eBay Motors words' : 'refused for it, in the ordinary push\'s own words' ) ), false !== $tcg_case[2] ? ( $tcg_found['policies'] ?? null ) : isset( $tcg_found['policies'] ), $tcg_case[2] );
}
policy_types( null, null );
$tcg_none = array();
foreach ( $tcg_cases as $tcg_label => $tcg_case ) {
	$tcg_none[] = isset( TCGiant_Sync_Motors::problems( $tcg_case[0], $tcg_case[1] )['policies'] );
}
check( 'none of them refused for policies whose types are not known', array_unique( $tcg_none ), array( false ) );
policy_types( $vehicles );
check( 'a listing on eBay US whose Update moves it to 35560: refused for the move alone, as before', TCGiant_Sync_Motors::problems( array( '_listing_site' => 'US' ) + $tcg_parts + $tcg_base, false ), array( 'category' => 'this listing is not on eBay Motors and 35560 is an eBay Motors category: eBay does not move a listing to another site. End the listing and push again to list it on eBay Motors' ) );
parts_shop( array( 'marketplace' => 'EBAY_GB', 'export_category_id' => '33564', 'export_category_typed' => '33564' ) );
list( $res, $sites ) = push( part( 567 ) );
check( 'a shop on eBay UK with a policy for motor vehicles only: listed as before, with its own site', array( code_of( $res ), $sites ), array( 'created', array( 'AddFixedPriceItem@3' ) ) );
no_policy_types();
parts_shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE EBAY TAB, VERIFY AND THE CATEGORY BROWSER (support/motors-admin.php)\n" . str_repeat( '=', 124 ) . "\n";

$tcg_out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/motors-admin.php' ) . ' 2>&1', $tcg_out );
$ADMIN = json_decode( (string) end( $tcg_out ), true );
check( 'it ran', is_array( $ADMIN ), true );
check( 'Browse eBay Categories, for a shop on eBay US: its root, and eBay Motors: Parts & Accessories', $ADMIN['root'] ?? null, array( array( 'id' => '1', 'name' => 'Collectibles', 'leaf' => false ), array( 'id' => '6028', 'name' => 'eBay Motors: Parts & Accessories', 'leaf' => false, 'tree' => '100' ) ) );
check( '  for a shop on eBay UK: its root alone', $ADMIN['root_uk'] ?? null, array( array( 'id' => '1', 'name' => 'Collectibles', 'leaf' => false ) ) );
check( '  opened: its children in tree 100, each marked so, and learnt as Parts & Accessories at no call of its own', array( $ADMIN['open'] ?? null, $ADMIN['open_rest'] ?? null, $ADMIN['learnt'] ?? null ), array( array( array( 'id' => '10063', 'name' => 'Motorcycle & Scooter Parts', 'leaf' => false, 'tree' => '100' ), array( 'id' => '33559', 'name' => 'Brakes', 'leaf' => false, 'tree' => '100' ) ), array( 'children:100:6028' ), array( '100:10063', '100:33559' ) ) );
check( '  a parent not known to be under Parts & Accessories: nothing listed, nothing asked', array( $ADMIN['outside'] ?? null, $ADMIN['outside_rest'] ?? null ), array( array( false, 'Only eBay Motors Parts & Accessories can be browsed here: open it from the top of the list.' ), array() ) );
check( 'Suggest Category: eBay US\'s, then eBay Motors\' Parts & Accessories by their ancestors, never a vehicle category', $ADMIN['suggest'] ?? null, array( array( 'id' => '183454', 'name' => 'Toys & Hobbies > CCG Individual Cards' ), array( 'id' => '35560', 'name' => 'eBay Motors > Parts & Accessories > Motorcycle & Scooter Parts > Vintage Motorcycle Parts', 'tree' => '100' ) ) );
check( '  learnt as it is shown', $ADMIN['suggest_learnt'] ?? null, array( '100:35560' ) );
check( '  the button\'s title on the tab (3.29.2): suggest_title_js()\'s literal, not esc_js() in quotes, and a script in the name never raw on the page', $ADMIN['suggest_js'] ?? null, array( true, false, false ) );
check( 'Verify on the eBay tab: VerifyAddFixedPriceItem with 100', array( $ADMIN['verify']['ok'] ?? null, $ADMIN['verify']['sites'] ?? null ), array( true, array( 'VerifyAddFixedPriceItem@100' ) ) );
check( 'the eBay tab: the category line says eBay Motors, and it is ready', array( $ADMIN['ready'][0] ?? null, in_array( '✔ Category: Vintage Motorcycle Parts (35560) - on eBay Motors', (array) ( $ADMIN['ready'] ?? array() ), true ) ), array( '✔ Ready to push', true ) );
check( '  the label under the category says so', $ADMIN['label'] ?? null, true );
check( '  a whole motorcycle\'s, learnt as one: not ready, in the push\'s words', array( $ADMIN['vehicle'][0] ?? null, (bool) preg_grep( '/^✘ Category: 6473 is an eBay Motors vehicle category/u', (array) ( $ADMIN['vehicle'] ?? array() ) ) ), array( '⚠ Not ready - fix issues above', true ) );
check( 'a category\'s name is kept only with its own ID: none for a new ID sent without one, or with the old one; the new one sent; kept for the same ID; none without an ID', $ADMIN['name_keep'] ?? null, array( '', '', 'Clothing > Shirts', 'eBay Motors > Parts & Accessories > Motorcycle & Scooter Parts > Vintage Motorcycle Parts', '' ) );
check( '  Verify on the eBay tab with 183454 chosen over a saved Motors category: the old path is not kept with it', $ADMIN['verify_name'] ?? null, array( '183454', '' ) );
check( '  the tab: the list and the custom box clear the browser\'s name; Push and Verify send it', $ADMIN['tab_js'] ?? null, array( true, true, 2 ) );
check( '  183454 with an old eBay Motors name left on the product: ready, on eBay US, never said to be on eBay Motors', array( $ADMIN['stale_name'][0] ?? null, (bool) preg_grep( '/on eBay Motors/u', (array) ( $ADMIN['stale_name'] ?? array() ) ) ), array( '✔ Ready to push', false ) );
check( '  its Item Specifics box asks tree 100 for 35560', array( $ADMIN['aspects']['status'] ?? null, $ADMIN['aspects_rest'] ?? null ), array( 'ok', array( 'aspects:100:35560' ) ) );
check( '  a category on the screen, not saved yet, judged afresh: 35560 over a saved 183454 from tree 100, 183454 over a saved 35560 from tree 0', array( $ADMIN['screen_motors'] ?? null, $ADMIN['screen_us'] ?? null ), array( array( 'aspects:100:35560' ), array( 'aspects:0:183454' ) ) );
check( '  a shop on eBay UK with that category learnt as eBay Motors\': its own tree, and no Motors conditions', $ADMIN['uk_aspects'] ?? null, array( 'aspects:0:35560' ) );
check( 'Check with eBay, from a template\'s preview: VerifyAddFixedPriceItem with 100', $ADMIN['template_check'] ?? null, array( true, array( 'VerifyAddFixedPriceItem@100' ) ) );
check( 'an ordinary product (183454) whose shipping policy is for motor vehicles only: the tab says so in the push\'s own words (3.29.2)', array( $ADMIN['vehicle_policy'][0] ?? null, in_array( '✘ Business Policies: ' . $own_words, (array) ( $ADMIN['vehicle_policy'] ?? array() ), true ) ), array( '⚠ Not ready - fix issues above', true ) );
check( '  Verify on the tab: refused, nothing sent', array( $ADMIN['vehicle_policy_verify'][0] ?? null, false !== strpos( (string) ( $ADMIN['vehicle_policy_verify'][1] ?? '' ), 'Business Policies — ' . $own_words ), $ADMIN['vehicle_policy_verify'][2] ?? null ), array( false, true, array() ) );
check( '  one for everything but motor vehicles: ready', $ADMIN['vehicle_policy_not'][0] ?? null, '✔ Ready to push' );

done();
