<?php
/**
 * Products the plugin pushed, pushed again, for test-imported-revise.php
 * (3.28.8): what each push sends eBay, printed as JSON - the item of every
 * Trading call, its random UUID taken out. Run against this tree it is what
 * 3.28.8 sends; run against 3.28.6's tree (php this-file <that tree's root>)
 * it gave tests/support/imported-revise-3286.json, which the harness holds
 * this tree's to, byte for byte: a product the plugin pushed is sent exactly
 * what it was before.
 *
 * Each product carries _ebay_origin 'push', which 3.28.6 never reads, and a
 * last-pushed date, as a pushed product does. Nothing here is an auction:
 * a live auction's revise changes by decision 16, and the harness says so.
 * Then 3.28.8's changes to a pushed product's body, each held to 3.28.6's
 * with exactly that change and no other: a card's default condition sent as
 * its pair (decision 17), and a fixed-price listing in a shop whose default
 * is now an auction, its format recorded (its own quantity, and its own Best
 * Offer) or not (3.28.6's bytes). And a pushed live auction's revise, which
 * is 3.28.6's (decision 16 is for imported auctions), with the auction format
 * chosen for it or the shop's default Fixed Price.
 *
 * usage: php tests/support/imported-revise-pushed.php [plugin root]
 *
 * @package TCGiant_Sync
 */

$tcg_root = isset( $argv[1] ) && '' !== $argv[1] ? rtrim( $argv[1], '/\\' ) : dirname( __DIR__, 2 );

require $tcg_root . '/tests/support/template-world.php';

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
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id ) { return 'publish'; }
}

// A card shop's defaults.
TCGiant_Sync_OAuth::$settings = array(
	'marketplace'               => 'EBAY_US',
	'export_category_id'        => '183050',
	'export_condition_id'       => '1000',
	'export_condition_type'     => 'ungraded',
	'export_ungraded_condition' => '400010',
	'export_location'           => 'Austin, TX',
	'export_postal_code'        => '78701',
	'export_fulfillment_policy' => '250000011',
	'export_return_policy'      => '250000012',
	'export_payment_policy'     => '250000013',
	'export_listing_type'       => 'FixedPriceItem',
	'export_listing_duration'   => 'GTC',
);

/** A product of the shop's, with a photo on the website, and these meta. */
function shop_product( $id, array $data, array $meta ) {
	$product = new WC_Product( $id, array_merge( array( 'name' => 'Item ' . $id, 'description' => 'Described ' . $id . '.', 'regular_price' => '25.00', 'stock' => 2, 'sku' => 'SKU-' . $id, 'image_id' => 9000 + $id ), $data ) );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/item-' . $id . '.jpg';
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
/** A push of it: every call's name, and its item with no UUID. */
function push( WC_Product $product ) {
	$GLOBALS['API']->calls = array();
	private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	$out = array();
	foreach ( $GLOBALS['API']->calls as $call ) {
		$out[] = array( $call[0], preg_replace( '#<UUID>[0-9a-fA-F]+</UUID>\n?#', '', (string) $call[1] ) );
	}
	return $out;
}

$pushed = array( '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-10-01 10:00:00' );
$card   = array( '_ebay_export_item_type' => 'tcg', '_ebay_export_category_id' => '183454', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '9', '_ebay_export_cert_number' => '44556677' );

$out = array();

// A graded card the shop pushed, revised: its own category and condition, the
// shop's policies, location, handling time and format.
$out['card_revise'] = push( shop_product( 801, array(), $card + $pushed + array( '_ebay_item_id' => '110000000801' ) ) );

// A used part the shop pushed, with nothing of its own: the shop's defaults.
TCGiant_Sync_OAuth::$settings['export_category_id'] = '33564';
$out['part_revise'] = push( shop_product( 802, array( 'stock' => 5 ), array( '_ebay_export_item_type' => 'other', '_ebay_export_condition_id' => '3000' ) + $pushed + array( '_ebay_item_id' => '110000000802' ) ) );

// The same part with its own shipping policy.
$out['part_policy'] = push( shop_product( 803, array(), array( '_ebay_export_item_type' => 'other', '_ebay_export_fulfillment_policy' => '250000099' ) + $pushed + array( '_ebay_item_id' => '110000000803' ) ) );
TCGiant_Sync_OAuth::$settings['export_category_id'] = '183050';

// A new product's first push: an Add.
$out['card_add'] = push( shop_product( 804, array(), $card ) );

// A pushed card whose listing ended: the revise refused, eBay asked, a new
// listing in its place.
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$GLOBALS['API']->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000805', 'ListingType' => 'FixedPriceItem', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ) );
$out['card_ended'] = push( shop_product( 805, array(), $card + $pushed + array( '_ebay_item_id' => '110000000805' ) ) );
unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'], $GLOBALS['API']->answers['GetItem'] );

// Decision 17: a card in 183454 the shop pushed, its condition the shop's
// default Excellent (400011), which eBay's table lists for 183454 only as
// Lightly Played (Excellent), 400015.
TCGiant_Sync_OAuth::$settings['export_ungraded_condition'] = '400011';
$out['card_pair'] = push( shop_product( 806, array(), array( '_ebay_export_item_type' => 'tcg', '_ebay_export_category_id' => '183454', '_ebay_export_condition_type' => 'ungraded' ) + $pushed + array( '_ebay_item_id' => '110000000806' ) ) );
TCGiant_Sync_OAuth::$settings['export_ungraded_condition'] = '400010';

// A shop whose default format is now a 7-day auction: a fixed-price listing
// it pushed, of 4, with its format recorded, and one with none recorded.
TCGiant_Sync_OAuth::$settings['export_category_id']      = '33564';
TCGiant_Sync_OAuth::$settings['export_listing_type']     = 'Chinese';
TCGiant_Sync_OAuth::$settings['export_listing_duration'] = 'Days_7';
$part = array( '_ebay_export_item_type' => 'other', '_ebay_export_condition_id' => '3000' );
$out['fixed_under_auction'] = push( shop_product( 807, array( 'stock' => 4 ), $part + $pushed + array( '_ebay_item_id' => '110000000807' ) ) );
$unrecorded = $pushed;
unset( $unrecorded['_ebay_listing_type'] );
$out['unrecorded_under_auction'] = push( shop_product( 808, array( 'stock' => 4 ), $part + $unrecorded + array( '_ebay_item_id' => '110000000808' ) ) );
$out['fixed_under_auction_bo'] = push( shop_product( 809, array( 'stock' => 4 ), $part + $pushed + array( '_ebay_item_id' => '110000000809', '_tcgiant_best_offer_enabled' => '1' ) ) );
TCGiant_Sync_OAuth::$settings['export_listing_type']     = 'FixedPriceItem';
TCGiant_Sync_OAuth::$settings['export_listing_duration'] = 'GTC';

// A live auction the shop pushed, revised: with the auction format chosen
// for it, and with none, the shop's default Fixed Price (and its own Best
// Offer).
$auction = array( '_ebay_listing_type' => 'Chinese' ) + $pushed;
$out['auction_revise']        = push( shop_product( 810, array( 'stock' => 1 ), $part + $auction + array( '_ebay_item_id' => '110000000810', '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) ) );
$out['auction_default_fixed'] = push( shop_product( 811, array( 'stock' => 1 ), $part + $auction + array( '_ebay_item_id' => '110000000811', '_tcgiant_best_offer_enabled' => '1' ) ) );

// A listing recorded as StoresFixedPrice, with the product's own Best Offer;
// and one recorded as fixed-price whose product chose an auction format of
// its own (with its own Best Offer, the listing's status not recorded):
// both as 3.28.6 sent them.
$out['stores_fixed_bo']   = push( shop_product( 812, array( 'stock' => 3 ), $part + array( '_ebay_listing_type' => 'StoresFixedPrice' ) + $pushed + array( '_ebay_item_id' => '110000000812', '_tcgiant_best_offer_enabled' => '1' ) ) );
$own_auction              = $pushed;
unset( $own_auction['_ebay_listing_status'] );
$out['fixed_own_auction'] = push( shop_product( 813, array( 'stock' => 3 ), $part + $own_auction + array( '_ebay_item_id' => '110000000813', '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7', '_tcgiant_best_offer_enabled' => '1' ) ) );

// 3.28.9: the shop's Best Offer and GPSR defaults in Settings, which 3.28.6
// never sent (get_export_settings() did not copy them). A new product's Add,
// a pushed listing's revise, and one whose product has its own Best Offer.
TCGiant_Sync_OAuth::$settings += array(
	'best_offer_enabled'        => '1',
	'best_offer_auto_accept'    => '0.90',
	'best_offer_auto_decline'   => '0.70',
	'gpsr_enabled'              => '1',
	'gpsr_manufacturer_name'    => 'ACME Parts Ltd',
	'gpsr_manufacturer_address' => '1 Mill Road, Leeds LS1 1AA, United Kingdom',
	'gpsr_eu_responsible'       => 'EU Rep GmbH, Berlin',
);
TCGiant_Sync_OAuth::$settings['export_category_id'] = '33564';
$out['defaults_add']        = push( shop_product( 814, array( 'regular_price' => '100.00' ), $part ) );
$out['defaults_revise']     = push( shop_product( 815, array( 'regular_price' => '100.00' ), $part + $pushed + array( '_ebay_item_id' => '110000000815' ) ) );
$out['defaults_revise_bo']  = push( shop_product( 816, array( 'regular_price' => '100.00' ), $part + $pushed + array( '_ebay_item_id' => '110000000816', '_tcgiant_best_offer_enabled' => '1' ) ) );

echo json_encode( $out );
$GLOBALS['harness_done'] = true;
