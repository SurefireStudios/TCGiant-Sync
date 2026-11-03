<?php
/**
 * The push side of tests/test-push-mark.php (3.28.7), in a process of its
 * own: the exporter needs more of WordPress than the import's world plays.
 *
 * Loaded for real, in template-world.php: the exporter's settings, item build
 * and push (do_push()), the API class, TCGiant_Sync_Environment and the site
 * filter. eBay is played at the API class's trading_api_request(), which
 * records every call with its body. Prints what each push sent as JSON.
 *
 * usage: php tests/support/push-mark-export.php
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/template-world.php';

// What a push needs of the plugin's other classes, played, as in
// test-category-ids.php.
class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS         = array();
	const AUCTION_LISTED_KEY  = '_tcgiant_auction_listed';
	const AUCTION_FIGURE_KEYS = array();
	public static function __callStatic( $name, $args ) { return null; }
	public static function restocked_after_website_sale( $product_id ) { return 0; }
	public static function listing_already_settled( $product_id, $item_id ) { return true; }
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

/** eBay as template-world.php plays it, able to look at the product as an AddItem or AddFixedPriceItem arrives. */
class Push_Mark_API extends Template_World_API {
	public $on_add      = null;
	public $seen_at_add = null;
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		if ( null !== $this->on_add && in_array( $call_name, array( 'AddFixedPriceItem', 'AddItem' ), true ) ) {
			$this->seen_at_add = call_user_func( $this->on_add );
		}
		return parent::trading_api_request( $call_name, $xml_body, $allow_retry, $max_body_bytes );
	}
}
$GLOBALS['API'] = new Push_Mark_API();
$tcg_slot->setValue( null, $GLOBALS['API'] );

/**
 * The site's own address, as WordPress keeps it: TCGiant_Sync_Environment
 * reads the home option from here.
 */
function wp_load_alloptions() {
	return array( 'home' => $GLOBALS['W']['home'] );
}

/** Product $id, with a photo and these meta. */
function product( $id, array $data = array(), array $meta = array() ) {
	$product = new WC_Product( $id, array_merge( array( 'name' => 'Brake caliper ' . $id, 'description' => 'Fits 2004-2008.', 'stock' => 3, 'image_id' => 9000 + $id ), $data ) );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/p' . $id . '.jpg';
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}

/** A push: every eBay call it made, by name, with its body; and its result. */
function push( WC_Product $product ) {
	$GLOBALS['API']->calls = array();
	$result                = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	return array(
		'calls'  => array_map( function ( $c ) { return array( 'name' => $c[0], 'body' => $c[1] ); }, $GLOBALS['API']->calls ),
		'result' => is_wp_error( $result ) ? 'error: ' . $result->get_error_message() : $result['action'] . ' ' . $result['item_id'],
	);
}

// A parts shop: a category that asks for no card condition.
TCGiant_Sync_OAuth::$settings['export_category_id']  = '33563';
TCGiant_Sync_OAuth::$settings['export_condition_id'] = '3000';

$out = array();

// A new fixed-price listing.
$out['new'] = push( product( 701 ) );

// A new auction, through AddItem.
$out['auction'] = push( product( 702, array(), array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) ) );

// A revise of a live listing.
$out['revise'] = push( product( 703, array(), array( '_ebay_item_id' => '110000000703', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' ) ) );

// A revise eBay refuses because the listing has ended: a new listing in its
// place, through AddFixedPriceItem.
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000704', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'ListingDetails' => array( 'EndTime' => '2026-10-30T08:00:00.000Z' ), 'Site' => 'US' ) );
// What the product says of the ended listing when eBay is asked for the new
// one (3.28.7): read at the AddFixedPriceItem itself.
$API->on_add = function () { return array( get_post_meta( 704, '_ebay_listing_status', true ), get_post_meta( 704, '_ebay_end_time', true ) ); };
$out['ended'] = push( product( 704, array(), array( '_ebay_item_id' => '110000000704', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' ) ) );
$out['ended_at_add'] = $API->seen_at_add;
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
$API->on_add = null;

// Verify and Check with eBay build the item a new listing would have, and
// make nothing.
$out['verify'] = item_xml( product( 705 ), push_settings( product( 705 ) ) );

// The relists send only the ended listing's number, a UUID and the stock.
$API->calls = array();
$API->relist_fixed_price_item( '110000000706', 2 );
$API->relist_item( '110000000707', 1 );
$out['relists'] = array_map( function ( $c ) { return array( 'name' => $c[0], 'body' => $c[1] ); }, $API->calls );

// The mark, worked out at each address, and for IDs at the edge of its 32
// characters.
$out['marks'] = array();
foreach ( array( 'https://shop.example', 'http://www.shop.example/', 'https://SHOP.example', 'https://staging.shop.example', 'https://shop.example/store', '' ) as $home ) {
	$GLOBALS['W']['home']    = $home;
	$out['marks'][ $home ] = array( TCGiant_Sync_API::push_site_key(), TCGiant_Sync_API::push_mark( 4717 ) );
}
$GLOBALS['W']['home'] = 'https://shop.example';
$out['edge'] = array(
	'14 digits' => TCGiant_Sync_API::push_mark( 99999999999999 ),
	'15 digits' => TCGiant_Sync_API::push_mark( 100000000000000 ),
	'0'         => TCGiant_Sync_API::push_mark( 0 ),
	'-5'        => TCGiant_Sync_API::push_mark( -5 ),
);

// No address to make a key from: the push goes without a mark, as before.
$GLOBALS['W']['home'] = '';
$out['no_address'] = push( product( 708 ) );
$GLOBALS['W']['home'] = 'https://shop.example';

// process_single_push(): when a push began, kept until a push finishes or its
// listing is linked, and an earlier unfinished push's time standing (3.28.7).
$API->answers['AddFixedPriceItem'] = new WP_Error( 'api_error', 'eBay refused it.' );
product( 711 );
exporter()->process_single_push( 711 );
$out['attempt_refused'] = array( get_post_meta( 711, '_ebay_export_status', true ), (int) get_post_meta( 711, '_ebay_push_attempt', true ) > time() - 60 );
product( 712, array(), array( '_ebay_export_status' => 'failed', '_ebay_export_error' => 'The push of "Brake caliper 712" (WC #712) did not finish.', '_ebay_push_attempt' => 1000 ) );
exporter()->process_single_push( 712 );
$out['attempt_kept'] = array( get_post_meta( 712, '_ebay_export_status', true ), (int) get_post_meta( 712, '_ebay_push_attempt', true ) );
unset( $API->answers['AddFixedPriceItem'] );
product( 713, array(), array( '_ebay_push_attempt' => 1000, '_ebay_push_attempt_last' => 2000, '_ebay_unlinked_sold' => array( '110000000999' => 1 ) ) );
exporter()->process_single_push( 713 );
$out['attempt_done'] = array( get_post_meta( 713, '_ebay_export_status', true ), get_post_meta( 713, '_ebay_push_attempt', true ), get_post_meta( 713, '_ebay_push_attempt_last', true ), get_post_meta( 713, '_ebay_unlinked_sold', true ) );

// A product whose push died ten days ago, pushed again: what it says as eBay
// makes the new listing, which is all a death then leaves behind - the first
// push's time, and this one's as the latest.
$given = time() - 10 * DAY_IN_SECONDS;
product( 717, array(), array( '_ebay_export_status' => 'failed', '_ebay_export_error' => 'The push of "Brake caliper 717" (WC #717) did not finish.', '_ebay_push_attempt' => $given ) );
$API->on_add = function () { return array( get_post_meta( 717, '_ebay_export_status', true ), (int) get_post_meta( 717, '_ebay_push_attempt', true ), (int) get_post_meta( 717, '_ebay_push_attempt_last', true ) ); };
$began       = time();
exporter()->process_single_push( 717 );
$out['repush_at_add'] = array( 'seen' => $API->seen_at_add, 'given' => $given, 'began' => $began );
$API->on_add          = null;

/**
 * A product that keeps its meta as WooCommerce's does (WC_Data): read once,
 * and saved only where it differs from what was read. A key it did not read
 * is added as a row beside one written since, which get_post_meta() does not
 * return; a delete of a key it did not read deletes nothing.
 */
class WC_Like_Product extends WC_Product {
	private $read    = array();
	private $pending = array();
	public function __construct( $id, array $data = array() ) {
		parent::__construct( $id, $data );
		$this->read_meta_data( true );
	}
	public function read_meta_data( $force = false ) {
		$this->read    = $GLOBALS['W']['meta'][ $this->id ] ?? array();
		$this->pending = array();
	}
	public function get_meta( $key, $single = true ) {
		if ( array_key_exists( $key, $this->pending ) ) {
			return null === $this->pending[ $key ] ? '' : $this->pending[ $key ];
		}
		return $this->read[ $key ] ?? '';
	}
	public function update_meta_data( $key, $value ) { $this->pending[ $key ] = $value; }
	public function delete_meta_data( $key ) { $this->pending[ $key ] = null; }
	public function save() {
		foreach ( $this->pending as $key => $value ) {
			if ( ! array_key_exists( $key, $this->read ) ) {
				if ( null !== $value && ! array_key_exists( $key, $GLOBALS['W']['meta'][ $this->id ] ?? array() ) ) {
					$GLOBALS['W']['meta'][ $this->id ][ $key ] = $value;
				}
			} elseif ( null === $value ) {
				unset( $GLOBALS['W']['meta'][ $this->id ][ $key ] );
			} elseif ( $value !== $this->read[ $key ] ) {
				$GLOBALS['W']['meta'][ $this->id ][ $key ] = $value;
			}
			if ( null === $value ) {
				unset( $this->read[ $key ] );
			} else {
				$this->read[ $key ] = $value;
			}
		}
		$this->pending = array();
		return $this->id;
	}
}

// process_single_push() in place of a listing that has ended, the product
// saving as WooCommerce's does: do_push() records the ended listing straight
// to the table, after the product read "Active" and no end, and the push's
// own save must still leave the new listing Active, with no end.
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000716', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'ListingDetails' => array( 'EndTime' => '2026-10-30T08:00:00.000Z' ), 'Site' => 'US' ) );
foreach ( array( '_ebay_item_id' => '110000000716', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' ) as $key => $value ) {
	update_post_meta( 716, $key, $value );
}
$GLOBALS['W']['images'][9716] = 'https://shop.example/wp-content/uploads/p716.jpg';
new WC_Like_Product( 716, array( 'name' => 'Brake caliper 716', 'description' => 'Fits 2004-2008.', 'stock' => 3, 'image_id' => 9716 ) );
exporter()->process_single_push( 716 );
$out['ended_saved'] = array( get_post_meta( 716, '_ebay_item_id', true ), get_post_meta( 716, '_ebay_listing_status', true ), get_post_meta( 716, '_ebay_end_time', true ), get_post_meta( 716, '_ebay_previous_item_id', true ), get_post_meta( 716, '_ebay_export_status', true ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );

// The description record a link writes, from the listing's own description
// (3.28.7): the template its comment names, or, with the comment gone, the
// one the product is set to; the hashes of what the listing holds.
$tpl_id = make_template( array( 'name' => 'Parts', 'html' => '<div>{{{description}}}</div>' ) );
$tpl_id = is_numeric( $tpl_id ) ? (int) $tpl_id : 0;
$held   = '<div class="tcg-desc" data-tcg="content"><p>Rebuilt.</p></div>';
$out['record'] = array(
	'tpl'        => $tpl_id,
	'marker'     => TCGiant_Sync_Exporter::description_record_from( product( 714, array(), array( TCGiant_Sync_Templates::OVERRIDE_META => (string) $tpl_id ) ), $held . '<!--tcg:tpl=12.3-->' ),
	'no_marker'  => TCGiant_Sync_Exporter::description_record_from( product( 714 ), $held ),
	'plain'      => TCGiant_Sync_Exporter::description_record_from( product( 715 ), '<p>Plain.</p>' ),
	'empty'      => TCGiant_Sync_Exporter::description_record_from( product( 715 ), '' ),
	'held'       => $held,
);

echo json_encode( $out );
$GLOBALS['harness_done'] = true;
