<?php
/**
 * A staging copy, for test-imported-revise.php (3.28.8): an imported
 * listing's Update that would read the listing first (a category of its own,
 * its condition not read) makes no GetItem on a copy that sends nothing. What
 * was asked of eBay, as JSON. A process of its own: staging is set by a
 * constant before anything loads.
 *
 * usage: php tests/support/imported-revise-staging.php
 *
 * @package TCGiant_Sync
 */

define( 'TCGIANT_SYNC_IS_STAGING', true );

require __DIR__ . '/template-world.php';

class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS         = array();
	const AUCTION_LISTED_KEY  = '_ebay_auction_listed_item';
	const AUCTION_FIGURE_KEYS = array();
	public static function __callStatic( $name, $args ) { return null; }
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

TCGiant_Sync_OAuth::$settings = array( 'marketplace' => 'EBAY_US', 'export_category_id' => '183050', 'export_location' => 'Austin, TX', 'export_postal_code' => '78701', 'export_fulfillment_policy' => '1', 'export_return_policy' => '2', 'export_payment_policy' => '3' );
$product = new WC_Product( 901, array( 'name' => 'Old import', 'description' => 'x', 'regular_price' => '9.00', 'stock' => 1, 'image_id' => 9901 ) );
$GLOBALS['W']['images'][9901] = 'https://shop.example/wp-content/uploads/own-9901.jpg';
foreach ( array( '_ebay_item_id' => '256100000901', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_origin' => 'import', '_ebay_export_category_id' => '183454' ) as $key => $value ) {
	update_post_meta( 901, $key, $value );
}
$GLOBALS['API']->calls = array();
private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );

echo json_encode( array( 'staging' => null !== TCGiant_Sync_Environment::write_block(), 'calls' => array_column( $GLOBALS['API']->calls, 0 ) ) );
$GLOBALS['harness_done'] = true;
