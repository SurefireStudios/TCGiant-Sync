<?php
/**
 * Harness: Verify checks what a push would send, through the call it would
 * send it with; and only a send eBay accepted is recorded.
 *
 * Verify always called VerifyAddItem, so a variation listing - which can only
 * be made through AddFixedPriceItem - was checked through a call that ignores
 * its variations. It now chooses as the push does, by uses_fixed_price_calls(),
 * and refuses a description over eBay's ceiling before any call.
 *
 * What a product records about its description (_tcgiant_tpl_id and the
 * hashes) is written in the push's success block only - never by Verify,
 * which builds the very same item, nor by a push eBay refused.
 *
 * Loaded for real: the Verify button's handler (TCGiant_Sync_Admin::
 * ajax_verify_product()), the API class's Verify calls, the exporter's push
 * (process_single_push() and do_push()), and the templates.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

function check_ajax_referer( $action ) { return true; }
class Harness_Json_Answer extends Exception {
	public $success;
	public $data;
	public function __construct( $success, $data ) { parent::__construct( 'json' ); $this->success = $success; $this->data = $data; }
}
function wp_send_json_success( $data = null ) { throw new Harness_Json_Answer( true, $data ); }
function wp_send_json_error( $data = null ) { throw new Harness_Json_Answer( false, $data ); }
function sanitize_textarea_field( $text ) { return trim( (string) $text ); }

// What a push needs of the plugin's other classes, played.
class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS = array();
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}

require dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php';
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-template-admin.php';

$API = $GLOBALS['API'];
TCGiant_Sync_OAuth::$settings['export_category_id'] = '29223';
$API->answers['VerifyAddItem']           = array( 'Ack' => 'Success', 'Fees' => array( 'Fee' => array( array( 'Name' => 'InsertionFee', 'Fee' => '0.35' ) ) ) );
$API->answers['VerifyAddFixedPriceItem'] = array( 'Ack' => 'Success', 'Fees' => array( 'Fee' => array( array( 'Name' => 'InsertionFee', 'Fee' => '0.30' ) ) ) );

/** Verify pressed on a product's eBay tab: the eBay call it made, and what it answered. */
function verify( $product_id ) {
	$GLOBALS['API']->calls = array();
	$_POST                 = array( 'product_id' => (string) $product_id );
	try {
		( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor()->ajax_verify_product();
	} catch ( Harness_Json_Answer $answer ) {
		return array( array_column( $GLOBALS['API']->calls, 0 ), $answer->success, $answer->data['message'] ?? '' );
	}
	return array( array(), null, 'no answer' );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nVERIFY CHOOSES ITS CALL AS THE PUSH DOES\n" . str_repeat( '=', 124 ) . "\n";

$GLOBALS['W']['term_names']['pa_size|small'] = 'Small';
$var = new WC_Product_Variable( 81, array( 'name' => 'Sleeves', 'description' => 'Box of 100.', 'type' => 'variable' ) );
$var->variation_attributes = array( 'pa_size' => array( 'small' ) );
list( $calls, $ok ) = verify( 81 );
check( 'a variation product: VerifyAddFixedPriceItem', array( $calls, $ok ), array( array( 'VerifyAddFixedPriceItem' ), true ) );
check( '  with its variations in the item checked', false !== strpos( $API->calls[0][1], '<Variations>' ), true );

$simple = new WC_Product( 82, array( 'name' => 'Card', 'description' => 'x' ) );
check( 'a fixed-price product: VerifyAddFixedPriceItem, as its push uses AddFixedPriceItem', verify( 82 )[0], array( 'VerifyAddFixedPriceItem' ) );

update_post_meta( 82, '_ebay_export_listing_type', 'Chinese' );
update_post_meta( 82, '_ebay_export_listing_duration', 'Days_7' );
check( 'an auction: VerifyAddItem', verify( 82 )[0], array( 'VerifyAddItem' ) );
update_post_meta( 82, '_ebay_listing_type', 'FixedPriceItem' );
update_post_meta( 82, '_ebay_item_id', '110000000082' );
check( '  even linked to a fixed-price listing: Verify checks the new listing it would make', verify( 82 )[0], array( 'VerifyAddItem' ) );
delete_post_meta( 82, '_ebay_item_id' );
delete_post_meta( 82, '_ebay_export_listing_type' );

$do_push = private_method( 'TCGiant_Sync_Exporter', 'do_push' );
$API->calls = array();
$do_push->invoke( exporter(), $var );
check( 'and the variation product\'s push is AddFixedPriceItem', array_column( $API->calls, 0 ), array( 'AddFixedPriceItem' ) );

check( 'the fees eBay quotes for that call are shown', verify( 81 )[2], "✅ Listing verified! No errors.\nEstimated fees: InsertionFee: \$0.30" );

$big = new WC_Product( 83, array( 'name' => 'Big', 'description' => str_repeat( 'a', 500001 ) ) );
update_post_meta( 83, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array( 'html' => '{{{description}}}' ) ) );
list( $calls, $ok, $message ) = verify( 83 );
check( 'a templated description over 500,000 characters: refused before any call', array( $calls, $ok ), array( array(), false ) );
check( '  saying why', false !== strpos( $message, 'characters long, and eBay takes up to 500,000' ), true );
delete_post_meta( 83, TCGiant_Sync_Templates::OVERRIDE_META );
check( 'with no template, Verify asks eBay about it, as 3.27.7 did', count( verify( 83 )[0] ), 1 );

$src = file_get_contents( dirname( __DIR__ ) . '/admin/views/listings.php' );
check( '"Check push settings only" still asks eBay nothing, as its label says', false !== strpos( $src, 'Check push settings only (eBay is not asked)' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nONLY A SEND EBAY ACCEPTED IS RECORDED\n" . str_repeat( '=', 124 ) . "\n";

$sent_meta = function ( $id ) {
	return array_intersect_key( $GLOBALS['W']['meta'][ $id ] ?? array(), array_flip( TCGiant_Sync_Templates::SENT_META ) );
};

$tpl  = make_template( array( 'name' => 'Clean', 'html' => '<div>{{{description}}}</div>' ) );
$card = new WC_Product( 84, array( 'name' => 'Charizard', 'description' => 'Holo.' ) );
update_post_meta( 84, TCGiant_Sync_Templates::OVERRIDE_META, (string) $tpl );
verify( 84 );
check( 'Verify builds the same item and records nothing', $sent_meta( 84 ), array() );

// A push eBay refuses.
$api_slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$api_slot->setAccessible( true );
}
class Refusing_API extends Template_World_API {
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$this->calls[] = array( $call_name, $xml_body );
		return new WP_Error( 'api_error', 'eBay refused it.' );
	}
}
$api_slot->setValue( null, new Refusing_API() );
exporter()->process_single_push( 84 );
check( 'a push eBay refused records nothing', $sent_meta( 84 ), array() );
check( '  and says it was refused', get_post_meta( 84, '_ebay_export_status', true ), 'error' );
$api_slot->setValue( null, $API );

$API->calls = array();
exporter()->process_single_push( 84 );
$xml = $API->calls[0][1] ?? '';
preg_match( '#<Description><!\[CDATA\[(.*)\]\]></Description>#s', $xml, $d );
$m = $sent_meta( 84 );
check( 'a push eBay accepted records the template and its version', array( $m['_tcgiant_tpl_id'] ?? null, $m['_tcgiant_tpl_ver'] ?? null ), array( (string) $tpl, '1' ) );
check( '  the hash of exactly the bytes sent', $m['_tcgiant_desc_hash'] ?? null, sha1( str_replace( ']]]]><![CDATA[>', ']]>', $d[1] ?? '' ) ) );
check( '  the template and content hashes, and when', array( strlen( (string) ( $m['_tcgiant_tpl_hash_sent'] ?? '' ) ), strlen( (string) ( $m['_tcgiant_content_hash'] ?? '' ) ), ( $m['_tcgiant_desc_sent_at'] ?? 0 ) > 0 ), array( 40, 40, true ) );
check( '  and the hash of its content region, as a re-import reads it back', $m['_tcgiant_region_hash'] ?? null, sha1( TCGiant_Sync_Templates::content_region( str_replace( ']]]]><![CDATA[>', ']]>', $d[1] ?? '' ), true ) ) );
check( '  and the listing it went to', $m['_tcgiant_tpl_item'] ?? null, (string) get_post_meta( 84, '_ebay_item_id', true ) );

// Then a revise of the same listing, after the template is saved again.
TCGiant_Sync_Templates::save( $tpl, array( 'html' => '<section>{{{description}}}</section>' ) );
$before = $m['_tcgiant_desc_hash'];
exporter()->process_single_push( 84 );
$m = $sent_meta( 84 );
check( 'a revise eBay accepted records the new version and bytes', array( $m['_tcgiant_tpl_ver'], $m['_tcgiant_desc_hash'] !== $before, get_post_meta( 84, '_ebay_export_status', true ) ), array( '2', true, 'pushed' ) );

// One exporter, two products in one run: the second records its own.
$plain = new WC_Product( 85, array( 'name' => 'Plain', 'description' => 'No template.' ) );
exporter()->process_single_push( 85 );
check( 'the next product pushed by the same exporter records its own description, not the last one\'s', array( $sent_meta( 85 )['_tcgiant_tpl_id'] ?? null, $sent_meta( 85 )['_tcgiant_desc_hash'] ?? null ), array( '0', sha1( described( $plain )['html'] ) ) );

$broken = new WC_Product( 86, array( 'name' => 'Broken', 'description' => 'x' ) );
update_post_meta( 86, '_ebay_export_listing_type', 'Chinese' );
update_post_meta( 86, '_ebay_export_listing_duration', 'Days_30' );
exporter()->process_single_push( 86 );
check( 'a push refused before its item was built records nothing of the product before it', $sent_meta( 86 ), array() );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nLOAD EBAY CATEGORIES, ON THE SETTINGS SCREEN (3.33.0)\n" . str_repeat( '=', 124 ) . "\n";

/** The button pressed: whether it worked, what it answered, and how often eBay was asked. */
function load_categories() {
	$GLOBALS['API']->calls = array();
	try {
		( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor()->ajax_get_store_categories();
	} catch ( Harness_Json_Answer $answer ) {
		return array( $answer->success, $answer->data, count( array_keys( array_column( $GLOBALS['API']->calls, 0 ), 'GetStore', true ) ) );
	}
	return array( null, null, 0 );
}

$store = array( 'Ack' => 'Success', 'Store' => array( 'CustomCategories' => array( 'CustomCategory' => array(
	array( 'CategoryID' => '1', 'Name' => 'Other' ),
	array( 'CategoryID' => '9001', 'Name' => 'Cards', 'ChildCategory' => array( 'CategoryID' => '9011', 'Name' => 'Holo', 'ChildCategory' => array( 'CategoryID' => '9051', 'Name' => 'Rare' ) ) ),
) ) ) );

$API->answers['GetStore'] = $store;
list( $ok, $data, $asked ) = load_categories();
check( 'the shop\'s categories, each level indented, the plain name beside it', array( $ok, $data['categories'] ), array( true, array(
	array( 'id' => '1', 'name' => 'Other', 'raw' => 'Other' ),
	array( 'id' => '9001', 'name' => 'Cards', 'raw' => 'Cards' ),
	array( 'id' => '9011', 'name' => '- Holo', 'raw' => 'Holo' ),
	array( 'id' => '9051', 'name' => '- - Rare', 'raw' => 'Rare' ),
) ) );
check( 'one GetStore', $asked, 1 );
check( 'each press reads eBay, as a person pressing it means', load_categories()[2], 1 );

$API->answers['GetStore'] = new WP_Error( 'http_request_failed', 'eBay could not be reached.' );
list( $ok, $data ) = load_categories();
check( 'eBay not answering: said, though a good tree is held', array( $ok, $data['message'] ?? '' ), array( false, 'eBay could not be reached.' ) );
check( '  and the good tree stays', isset( TCGiant_Sync_Shop_Categories::tree()['categories']['9011'] ), true );

TCGiant_Sync_Shop_Categories::forget_tree();
list( $ok, $data ) = load_categories();
check( 'eBay not answering, with no tree held: said', array( $ok, $data['message'] ?? '' ), array( false, 'eBay could not be reached.' ) );

$API->answers['GetStore'] = array( 'Ack' => 'Success', 'Store' => array( 'Name' => 'Shop' ) );
TCGiant_Sync_Shop_Categories::forget_tree();
list( $ok, $data ) = load_categories();
check( 'a Store with no categories: an empty list, for the screen to say so', array( $ok, $data['categories'] ), array( true, array() ) );

TCGiant_Sync_OAuth::$connected = false;
list( $ok, $data, $asked ) = load_categories();
check( 'not connected: eBay is not asked', array( $ok, $asked ), array( false, 0 ) );
TCGiant_Sync_OAuth::$connected = true;

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT VERIFY AND CHECK WITH EBAY SAY OF A SHOP CATEGORY (3.33.0)\n" . str_repeat( '=', 124 ) . "\n";

/** Check with eBay pressed for a product: the calls it made, and what it answered. */
function check_with_ebay( $product_id ) {
	$GLOBALS['API']->calls = array();
	$_POST                 = array( 'product_id' => (string) $product_id, 'template_id' => '0' );
	try {
		( new ReflectionClass( 'TCGiant_Sync_Template_Admin' ) )->newInstanceWithoutConstructor()->ajax_check_ebay();
	} catch ( Harness_Json_Answer $answer ) {
		return array( array_column( $GLOBALS['API']->calls, 0 ), $answer->success, $answer->data['message'] ?? '' );
	}
	return array( array(), null, 'no answer' );
}

$API->answers['GetStore'] = $store;
TCGiant_Sync_Shop_Categories::forget_tree();
$GLOBALS['W']['terms'][501] = array( 'parent' => 0, 'name' => 'Holo' );
update_option( TCGiant_Sync_Shop_Categories::MAP_OPTION, array( 501 => '9051' ), false );
new WC_Product( 87, array( 'name' => 'Mapped', 'description' => 'x', 'categories' => array( 501 ) ) );

$warning = array( 'ShortMessage' => 'Invalid store category.', 'LongMessage' => 'Invalid store category ID, 9051, since it is non-leaf. So item has been listed to the Other store category.', 'ErrorCode' => '20169', 'SeverityCode' => 'Warning' );
$other   = array( 'ShortMessage' => 'Funds may be held.', 'LongMessage' => 'Funds from your sales may be unavailable.', 'ErrorCode' => '21917236', 'SeverityCode' => 'Warning' );
$fees    = array( 'Fee' => array( array( 'Name' => 'InsertionFee', 'Fee' => '0.30' ) ) );
$API->answers['VerifyAddFixedPriceItem'] = array( 'Ack' => 'Warning', 'Errors' => $warning, 'Fees' => $fees );

list( $calls, $ok, $said ) = verify( 87 );
check( 'Verify: eBay\'s Warning shown, in eBay\'s words, not "No errors"', array( $ok, $said ), array( true, "⚠️ eBay warned about the shop category: " . $warning['LongMessage'] . "\nEstimated fees: InsertionFee: \$0.30" ) );
check( '  the item it checked carried the Storefront', false !== strpos( end( $API->calls )[1], '<Storefront><StoreCategoryID>9051</StoreCategoryID></Storefront>' ), true );

$API->answers['VerifyAddFixedPriceItem'] = array( 'Ack' => 'Warning', 'Errors' => array( $other, $warning ) );
list( , , $said ) = verify( 87 );
check( '  in a list too, and only that one', $said, '⚠️ eBay warned about the shop category: ' . $warning['LongMessage'] );

$API->answers['VerifyAddFixedPriceItem'] = array( 'Ack' => 'Warning', 'Errors' => $other );
check( '  another Warning does not spoil "No errors", as before', verify( 87 )[2], '✅ Listing verified! No errors.' );

$API->answers['VerifyAddFixedPriceItem'] = array( 'Ack' => 'Warning', 'Errors' => $warning );
list( $calls, $ok, $said ) = check_with_ebay( 87 );
check( 'Check with eBay: the same, after its own words', array( $ok, $said ), array( true, 'eBay accepts this listing, description included. Nothing was listed. eBay warned about the shop category: ' . $warning['LongMessage'] ) );

$API->answers['VerifyAddFixedPriceItem'] = array( 'Ack' => 'Success' );
check( '  with no Warning, as before', check_with_ebay( 87 )[2], 'eBay accepts this listing, description included. Nothing was listed.' );

// eBay refusing: shown, never sent again, nothing held.
$words   = 'Input data for tag <Item.Storefront.StoreCategoryID> is invalid or missing.';
$refused = new WP_Error( 'api_error', $words, array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '37', 'ShortMessage' => 'Invalid.', 'LongMessage' => $words, 'SeverityCode' => 'Error' ) ) );
$API->answers['VerifyAddFixedPriceItem'] = $refused;

list( $calls, $ok, $said ) = verify( 87 );
check( 'Verify refused for the Storefront: one call, eBay\'s words', array( array_count_values( $calls )['VerifyAddFixedPriceItem'] ?? 0, $ok, $said ), array( 1, false, $words ) );
list( $calls, $ok, $said ) = check_with_ebay( 87 );
check( 'Check with eBay: the same', array( array_count_values( $calls )['VerifyAddFixedPriceItem'] ?? 0, $ok, false !== strpos( $said, $words ) ), array( 1, false, true ) );
check( 'neither holds the id back', get_transient( TCGiant_Sync_Shop_Categories::HELD_TRANSIENT . '9051' ), false );

TCGiant_Sync_Shop_Categories::hold( array( '9051' ) );
$API->answers['VerifyAddFixedPriceItem'] = array( 'Ack' => 'Success' );
verify( 87 );
check( 'an id a push had refused: Verify asks eBay all the same', false !== strpos( end( $API->calls )[1], '<StoreCategoryID>9051</StoreCategoryID>' ), true );
check_with_ebay( 87 );
check( '  and Check with eBay', false !== strpos( end( $API->calls )[1], '<StoreCategoryID>9051</StoreCategoryID>' ), true );

done();
