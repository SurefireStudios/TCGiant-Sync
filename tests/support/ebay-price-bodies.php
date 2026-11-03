<?php
/**
 * Every price path a push has, for test-ebay-price.php (3.29.0): what each
 * sends eBay, printed as JSON - the item of every Trading call, its random
 * UUID taken out - for products with no eBay price of their own in a shop
 * with no eBay price rule. Run against this tree it is what 3.29.0 sends;
 * run against the tree 3.29.0 was built on (php this-file <that tree's
 * root>) it gave tests/support/ebay-price-before.json, which the harness
 * holds this tree's to, byte for byte: with no rule and no product price,
 * every request body is what it was.
 *
 * The paths: an Add, a Revise and a Verify of a simple product; an auction's
 * Add and a pushed live auction's Revise; an imported live auction's Revise
 * (no price at all, 3.28.8); a variable product's Add and Revise; Best
 * Offer's thresholds, which are fractions of the price; an ended listing's
 * new listing; bulk Relist of a fixed-price listing (its quantity only), of
 * a variation listing (eBay's own prices) and of an imported auction, and
 * the Update that relists an ended imported auction with its title,
 * description and photos (decision 16); a product with no price; a price of
 * more than two places.
 *
 * Each runs in a shop whose settings have none of 3.29.0's keys, and in two
 * with every 3.29.0 setting at a value that must change nothing - no rule,
 * but a percentage, an amount and a rounding left in their boxes; tax on
 * Auto, on eBay US; tax on Yes where WooCommerce does not calculate tax;
 * postage added on import, with none recorded on the product; and an eBay
 * price of 0 or of no number, which is none. (Postage recorded on a product
 * is taken off whatever the setting says, so it is not inert, and is not
 * here: no product has a record until a 3.29.0 import makes one.)
 *
 * With a mode, this tree only: 'rule' runs every path once in a shop whose
 * rule is plus 10%, rounded up to .99, and 'own' once with every product and
 * variation at an eBay price of its own, 99.00. The harness holds each body
 * to the one before 3.29.0 with its prices taken out: the price is all that
 * changes.
 *
 * usage: php tests/support/ebay-price-bodies.php [plugin root] [rule|own]
 *
 * @package TCGiant_Sync
 */

$tcg_root = isset( $argv[1] ) && '' !== $argv[1] ? rtrim( $argv[1], '/\\' ) : dirname( __DIR__, 2 );

require $tcg_root . '/tests/support/template-world.php';
require $tcg_root . '/includes/class-tcgiant-sync-variations.php';

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
	public static $rows = array();
	public static function upsert( $row ) { self::$rows[] = $row; return 1; }
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id ) { return 'publish'; }
}
// A shop whose WooCommerce does not calculate tax.
function wc_tax_enabled() { return false; }
function wc_prices_include_tax() { return false; }

/** A variable product with its variations. */
class Bodies_Variable extends WC_Product_Variable {
	public $children = array();
	public function get_children() { return $this->children; }
}
/** A variation, by its size. */
class Bodies_Variation extends WC_Product_Variation {
	public function get_variation_attributes() { return array( 'attribute_size' => $this->data['size'] ); }
	public function get_attributes() { return array( 'size' => $this->data['size'] ); }
}

/** A part shop's defaults. */
function part_shop( array $extra = array() ) {
	TCGiant_Sync_OAuth::$settings = array_merge( array(
		'marketplace'               => 'EBAY_US',
		'export_category_id'        => '33564',
		'export_condition_id'       => '3000',
		'export_location'           => 'Austin, TX',
		'export_postal_code'        => '78701',
		'export_fulfillment_policy' => '250000011',
		'export_return_policy'      => '250000012',
		'export_payment_policy'     => '250000013',
		'export_listing_type'       => 'FixedPriceItem',
		'export_listing_duration'   => 'GTC',
	), $extra );
}

/** A product of the shop's, with a photo on the website, and these meta. */
function shop_product( $id, array $data, array $meta, $class = 'WC_Product' ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$product = new $class( $id, array_merge( array( 'name' => 'Item ' . $id, 'description' => 'Described ' . $id . '.', 'regular_price' => '25.00', 'stock' => 2, 'sku' => 'SKU-' . $id, 'image_id' => 9000 + $id ), $data ) );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/item-' . $id . '.jpg';
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}

/** Every call made since the last, its item with no UUID. */
function calls_made() {
	$out = array();
	foreach ( $GLOBALS['API']->calls as $call ) {
		$out[] = array( $call[0], preg_replace( '#<UUID>[0-9a-fA-F]+</UUID>\n?#', '', (string) $call[1] ) );
	}
	$GLOBALS['API']->calls = array();
	return $out;
}

/** A push of a product (do_push()). */
function push( WC_Product $product ) {
	$GLOBALS['API']->calls = array();
	private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	return calls_made();
}

/** Verify's item (build_item_xml_public()), as Verify and Check with eBay send it. */
function verify( WC_Product $product ) {
	return array( array( 'Verify', item_xml( $product, exporter()->get_export_settings( $product->get_id() ) ) ) );
}

/** Bulk Relist (relist_ended_listing()). */
function relist( $id, array $answer ) {
	$GLOBALS['API']->calls              = array();
	$GLOBALS['API']->answers['GetItem'] = $answer;
	exporter()->relist_ended_listing( $id, 'Bulk relist' );
	unset( $GLOBALS['API']->answers['GetItem'] );
	return calls_made();
}

/** An ended listing, as GetItem answers. */
function ended( $item_id, $type, array $more = array() ) {
	return array( 'Ack' => 'Success', 'Item' => array_merge( array( 'ItemID' => $item_id, 'ListingType' => $type, 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ), $more ) );
}

/**
 * Every path, for one shop.
 *
 * @param array $meta Meta every product gets besides its own.
 * @return array
 */
function every_path( array $meta ) {
	$out    = array();
	$pushed = array( '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-10-01 10:00:00' ) + $meta;

	$out['simple_add']    = push( shop_product( 901, array(), $meta ) );
	$out['simple_revise'] = push( shop_product( 902, array( 'regular_price' => '19.5' ), $pushed + array( '_ebay_item_id' => '110000000902' ) ) );
	$out['simple_verify'] = verify( shop_product( 903, array( 'regular_price' => '7.25' ), $meta ) );
	$out['no_price']      = verify( shop_product( 904, array( 'regular_price' => '' ), $meta ) );
	$out['long_price']    = verify( shop_product( 905, array( 'regular_price' => '9.999' ), $meta ) );

	// An auction the shop lists, and its live auction's Revise.
	$auction                = array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' );
	$out['auction_add']     = push( shop_product( 906, array( 'regular_price' => '37.00', 'stock' => 1 ), $auction + $meta ) );
	$out['auction_revise']  = push( shop_product( 907, array( 'regular_price' => '37.00', 'stock' => 1 ), $auction + array( '_ebay_listing_type' => 'Chinese' ) + $pushed + array( '_ebay_item_id' => '110000000907' ) ) );

	// An imported live auction: its revise sends no price (3.28.8).
	$imported              = array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_duration' => 'Days_7', '_ebay_listing_status' => 'Active', '_ebay_origin' => 'import', '_ebay_item_id' => '110000000908', '_ebay_live' => array( 'item_id' => '110000000908', 'category_id' => '33564', 'condition_id' => '3000', 'policies' => array( 'shipping' => '1', 'return' => '2', 'payment' => '3' ), 'location' => 'Columbus, OH' ) ) + $meta;
	$out['imported_auction_revise'] = push( shop_product( 908, array( 'regular_price' => '61.00', 'stock' => 1 ), $imported ) );

	// A variable product: an Add, then a Revise.
	$variable = shop_product( 910, array( 'type' => 'variable', 'sku' => 'VAR-910' ), $meta, 'Bodies_Variable' );
	$variable->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
	$variable->children             = array( 9101, 9102 );
	shop_product( 9101, array( 'sku' => 'VAR-910-S', 'size' => 'Small', 'regular_price' => '10', 'stock' => 3 ), $meta, 'Bodies_Variation' );
	shop_product( 9102, array( 'sku' => 'VAR-910-L', 'size' => 'Large', 'regular_price' => '12.5', 'stock' => 1 ), $meta, 'Bodies_Variation' );
	$out['variable_add'] = push( $variable );
	foreach ( $pushed + array( '_ebay_item_id' => '110000000910' ) as $key => $value ) {
		update_post_meta( 910, $key, $value );
	}
	$out['variable_revise'] = push( $variable );

	// Best Offer's thresholds: fractions of the price, and an amount.
	$out['best_offer'] = push( shop_product( 911, array( 'regular_price' => '40.00' ), $pushed + array( '_ebay_item_id' => '110000000911', '_tcgiant_best_offer_enabled' => '1', '_tcgiant_bo_auto_accept' => '0.9', '_tcgiant_bo_auto_decline' => '20' ) ) );

	// An ended pushed listing: a new listing in its place.
	$GLOBALS['API']->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
	$GLOBALS['API']->answers['GetItem']              = ended( '110000000912', 'FixedPriceItem' );
	$out['ended_new'] = push( shop_product( 912, array( 'regular_price' => '33.10' ), $pushed + array( '_ebay_item_id' => '110000000912' ) ) );
	unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'], $GLOBALS['API']->answers['GetItem'] );

	// Bulk Relist: a fixed-price listing, its quantity only.
	$ended_fixed = array( '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Ended', '_ebay_origin' => 'push', '_ebay_item_id' => '110000000913' ) + $meta;
	shop_product( 913, array( 'regular_price' => '15.00', 'stock' => 4 ), $ended_fixed );
	$out['relist_fixed'] = relist( 913, ended( '110000000913', 'FixedPriceItem', array( 'Quantity' => '4' ) ) );

	// Bulk Relist of a variation listing: at eBay's own prices.
	$relist_var = shop_product( 914, array( 'type' => 'variable', 'sku' => 'VAR-914' ), $ended_fixed + array( '_ebay_item_id' => '110000000914' ), 'Bodies_Variable' );
	$relist_var->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
	$relist_var->children             = array( 9141, 9142 );
	shop_product( 9141, array( 'sku' => 'VAR-914-S', 'size' => 'Small', 'regular_price' => '10', 'stock' => 2 ), $meta, 'Bodies_Variation' );
	shop_product( 9142, array( 'sku' => 'VAR-914-L', 'size' => 'Large', 'regular_price' => '12.5', 'stock' => 1 ), $meta, 'Bodies_Variation' );
	$variations = array( 'Variation' => array(
		array( 'SKU' => 'VAR-914-S', 'StartPrice' => '11.00', 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => '0' ), 'VariationSpecifics' => array( 'NameValueList' => array( 'Name' => 'size', 'Value' => 'Small' ) ) ),
		array( 'SKU' => 'VAR-914-L', 'StartPrice' => '13.75', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0' ), 'VariationSpecifics' => array( 'NameValueList' => array( 'Name' => 'size', 'Value' => 'Large' ) ) ),
	) );
	$out['relist_variations'] = relist( 914, ended( '110000000914', 'FixedPriceItem', array( 'Variations' => $variations ) ) );

	// An imported auction ended: bulk Relist, and the Update that relists it
	// with the shop's edits (decision 16).
	$ended_auction = array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_duration' => 'Days_7', '_ebay_listing_status' => 'Ended', '_ebay_origin' => 'import', '_ebay_item_id' => '110000000915', '_ebay_live' => array( 'item_id' => '110000000915', 'category_id' => '33564', 'condition_id' => '3000', 'policies' => array( 'shipping' => '1', 'return' => '2', 'payment' => '3' ) ) ) + $meta;
	shop_product( 915, array( 'regular_price' => '37.00', 'stock' => 1 ), $ended_auction );
	$out['relist_auction'] = relist( 915, ended( '110000000915', 'Chinese', array( 'StartPrice' => '37.0' ) ) );
	$ended_auction['_ebay_item_id']           = '110000000916';
	$ended_auction['_ebay_live']['item_id']   = '110000000916';
	$GLOBALS['API']->answers['ReviseItem']    = new WP_Error( 'api_error', 'The auction has already been closed.' );
	$GLOBALS['API']->answers['GetItem']       = ended( '110000000916', 'Chinese', array( 'StartPrice' => '37.0' ) );
	$out['update_relists_auction'] = push( shop_product( 916, array( 'regular_price' => '37.00', 'stock' => 1 ), $ended_auction ) );
	unset( $GLOBALS['API']->answers['ReviseItem'], $GLOBALS['API']->answers['GetItem'] );

	return $out;
}

$out  = array();
$mode = (string) ( $argv[2] ?? '' );

if ( 'rule' === $mode ) {
	part_shop( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10', 'ebay_price_rounding' => '99' ) );
	echo json_encode( array( 'plain' => every_path( array() ) ) );
	$GLOBALS['harness_done'] = true;
	return;
}
if ( 'own' === $mode ) {
	part_shop();
	echo json_encode( array( 'plain' => every_path( array( '_ebay_export_price' => '99.00' ) ) ) );
	$GLOBALS['harness_done'] = true;
	return;
}

// A shop with none of 3.29.0's settings.
part_shop();
$out['plain'] = every_path( array() );

// Every 3.29.0 setting at a value that changes nothing.
part_shop( array( 'ebay_price_rule' => 'none', 'ebay_price_percent' => '15', 'ebay_price_fixed' => '1.00', 'ebay_price_rounding' => '99', 'ebay_price_tax' => 'auto', 'bake_shipping_into_price' => '1' ) );
$out['inert'] = every_path( array( '_ebay_export_price' => '0' ) );
part_shop( array( 'ebay_price_tax' => 'yes', 'bake_shipping_into_price' => '0' ) );
$out['inert_bake_off'] = every_path( array( '_ebay_export_price' => 'none' ) );

echo json_encode( $out );
$GLOBALS['harness_done'] = true;
