<?php
/**
 * Harness: product identifiers on a push (3.30.3).
 *
 * A shop on eBay US pushing into an eBay Motors Parts & Accessories
 * category was refused by AddFixedPriceItem: "The UPC field is missing.
 * Please add UPC to the listing and try again." The plugin sent no product
 * identifier at all. Now a product (or each variation) sends its UPC, EAN or
 * ISBN - its own field, or WooCommerce's GTIN - and one with none, in a
 * category that requires one, sends eBay's own "Does not apply" for the
 * listing's site when the shop's setting or the product's own choice says
 * so, and is refused before eBay otherwise.
 *
 * Here the real TCGiant_Sync_Exporter, TCGiant_Sync_Identifiers,
 * TCGiant_Sync_Motors and TCGiant_Sync_API push, with eBay played
 * (support/motors-api.php): each listing call with the site header the real
 * API class would send it with, and the metadata read this adds -
 * GeteBayDetails - recorded apart, as "Call@site", with its body, and
 * answered by site (support/template-world.php).
 *
 * 3.31.1: GetCategoryFeatures, which 3.30.3 asked too, answered eBay's live
 * API with no XML at all on 2026-11-04, and is asked no more. A category's
 * requirement is its item specifics' (an aspect named UPC, EAN or ISBN that
 * eBay requires) and what eBay's own refusals taught; what 3.30.3 kept of
 * GetCategoryFeatures' answers is deleted once, and nothing logs it.
 *
 * Proved:
 *  - request bodies on eBay US (a UPC; "Does not apply"), eBay Motors (the
 *    shop's own case), eBay Germany and the UK (an EAN; "Nicht zutreffend"
 *    from a GeteBayDetails answer kept from before), a book (an ISBN), a
 *    variation listing, a category that requires none, and one whose
 *    requirement eBay could not give (nothing sent);
 *  - the Updates send none, pushed or imported (fix round 1), and nor does a
 *    relist (fix round 2): its request is 0a8765d's, refused or not;
 *  - eBay's own refusal for a missing UPC, EAN or ISBN (fix round 1), by the
 *    shop's words for it: learnt for the category, the item sent once more
 *    with "Does not apply" - an Add, Verify and Check with eBay - and only
 *    for that refusal, once, and never with the setting off; said with each
 *    error's severity (fix round 2);
 *  - fix round 2: a variation's own WooCommerce GTIN only, never its
 *    parent's; a 14-digit GTIN not sent, the product taken as having none,
 *    said once; Check with eBay's words for the push's own refusal;
 *  - the refusal before eBay, and a product's own choice over the setting;
 *  - GeteBayDetails: its body, its site, kept a week, an hour when it
 *    fails, eBay's documented text when it cannot answer;
 *  - 3.31.1: GetCategoryFeatures asked by no push, Verify, Update or relist
 *    here, nor anywhere in the plugin; its kept answers deleted once, by
 *    their own LIKE patterns, and never read; its log line gone;
 *  - the item specifics: a UPC there is the product's; a required UPC
 *    aspect is the identifier's, not refused as a missing attribute;
 *  - the fields and the setting save; the import still reads an identifier,
 *    and takes eBay's localised texts for none.
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
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-variations.php';
require __DIR__ . '/support/motors-api.php';
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-mapper.php';

// What the eBay tab draws with, played.
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function selected( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) { echo $out; } return $out; }
function checked( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; }
function woocommerce_wp_text_input( $args ) {
	echo '<input type="text" id="' . esc_attr( $args['id'] ) . '" name="' . esc_attr( $args['name'] ?? $args['id'] ) . '" value="' . esc_attr( $args['value'] ?? '' ) . '" placeholder="' . esc_attr( $args['placeholder'] ?? '' ) . '">';
}

/** A product with WooCommerce 9.2's GTIN field. */
class Gtin_Product extends WC_Product {
	public function get_global_unique_id() { return (string) ( $this->data['gtin'] ?? '' ); }
}
/** A variable product with its variations. */
class Id_Variable extends WC_Product_Variable {
	public $children = array();
	public function get_children() { return $this->children; }
}
/** A variation, by its size. */
class Id_Variation extends WC_Product_Variation {
	public function get_variation_attributes() { return array( 'attribute_size' => $this->data['size'] ); }
	/**
	 * Its own GTIN in 'edit'; in 'view', its parent's where it has none - as a
	 * filter, or a WooCommerce that did as get_sku() does, would give it.
	 */
	public function get_global_unique_id( $context = 'view' ) {
		$own = (string) ( $this->data['gtin'] ?? '' );
		return ( 'view' === $context && '' === $own ) ? (string) ( $this->data['parent_gtin'] ?? '' ) : $own;
	}
}

/** eBay, played as motors-api.php's, answering a listing call from $queue in turn when it holds any. */
class Queue_API extends Motors_API {
	/** @var array Call name => answers, the next one first. */
	public $queue = array();
	/** @var string[] Every Trading call this harness saw made, all along (3.31.1). */
	public $asked = array();
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$this->asked[] = $call_name;
		if ( ! empty( $this->queue[ $call_name ] ) ) {
			$this->sites[] = $call_name . '@' . $this->request_site_id();
			$this->calls[] = array( $call_name, $xml_body );
			return array_shift( $this->queue[ $call_name ] );
		}
		return parent::trading_api_request( $call_name, $xml_body, $allow_retry, $max_body_bytes );
	}
}
$GLOBALS['API'] = new Queue_API();
$tcg_queue_slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_queue_slot->setAccessible( true );
}
$tcg_queue_slot->setValue( null, $GLOBALS['API'] );
$API = $GLOBALS['API'];

/**
 * eBay's refusal as trading_api_request() hands it back: each message an
 * Error (or a Warning), with no ErrorCode - eBay's error reference lists none
 * for these, and none is read.
 */
function ebay_refusal( array $messages, array $warnings = array() ) {
	$errors = array();
	foreach ( $messages as $m ) {
		$errors[] = array( 'ShortMessage' => $m, 'LongMessage' => $m, 'SeverityCode' => 'Error' );
	}
	foreach ( $warnings as $m ) {
		$errors[] = array( 'ShortMessage' => $m, 'LongMessage' => $m, 'SeverityCode' => 'Warning' );
	}
	return new WP_Error( 'api_error', implode( ' | ', array_merge( $messages, $warnings ) ), array( 'Ack' => 'Failure', 'Errors' => 1 === count( $errors ) ? $errors[0] : $errors ) );
}
$CUSTOMER = 'The UPC field is missing. Please add UPC to the listing and try again.';

/** A shop on a marketplace, its default category, and these settings. */
function shop( $marketplace, $category, array $more = array() ) {
	TCGiant_Sync_OAuth::$settings = $more + array(
		'marketplace'               => $marketplace,
		'export_category_id'        => $category,
		'export_category_typed'     => $category,
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
/** eBay as it answers, nothing kept from before. */
function fresh_ebay() {
	$api = $GLOBALS['API'];
	$api->clear();
	$api->answers        = array();
	$api->site_answers   = array();
	$api->lookup_answers = array();
	$api->queue          = array();
	$api->tree_aspects   = array(
		'0'   => array( '33564' => motors_error( 62005 ) ),
		'100' => array( '33564' => array( array( 'name' => 'Brand', 'required' => true ) ) ),
	);
	$api->aspects = array();
	$api->nodes   = array( '33564' => motors_node( '33564', 'Brake Calipers', '33559' ) );
	$api->suggest = array( 'Brake Calipers' => array( array( 'id' => '33564', 'name' => 'eBay Motors > Parts & Accessories > Car & Truck Parts & Accessories > Brakes & Brake Parts > Brake Calipers', 'ancestors' => array( '33559', '6030', '6028', '6000' ) ) ) );
	$api->conds   = array( '33564' => motors_conditions( '33564', array( '1000' => 'New', '3000' => 'Used' ) ) );
	$GLOBALS['W']['transients'] = array();
	$GLOBALS['W']['log']        = array();
	delete_option( TCGiant_Sync_Motors::OPTION );
}
/**
 * The category's item specifics, as getItemAspectsForCategory gives them:
 * these identifiers as aspects eBay requires (3.31.1: what a category's
 * requirement is, with eBay's own refusals), beside the Brand every item
 * here has.
 */
function requires( array $types ) {
	$aspects = array( array( 'name' => 'Brand', 'required' => true ) );
	foreach ( $types as $type ) {
		$aspects[] = array( 'name' => $type, 'required' => true );
	}
	return $aspects;
}
/** GeteBayDetails' ProductDetails answer. */
function details( $text ) {
	return array( 'Ack' => 'Success', 'ProductDetails' => array( 'ProductIdentifierUnavailableText' => $text ) );
}
/** A product with a photo, a Brand and these meta. */
function item( $id, array $meta = array(), array $data = array(), $class = 'Gtin_Product' ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/item-' . $id . '.jpg';
	$product = new $class( $id, array_merge( array( 'name' => 'Item ' . $id, 'description' => 'An item.', 'regular_price' => '85.00', 'stock' => 3, 'sku' => 'IT-' . $id, 'image_id' => 9000 + $id, 'attributes' => array( new WC_Product_Attribute( 'Brand', array( 'Acme' ) ) ) ), $data ) );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
/** A push (do_push()): its answer, each listing call as Call@site, each lookup as Call@site, and the listing calls' bodies. */
function push( WC_Product $product ) {
	$GLOBALS['API']->clear();
	$GLOBALS['API']->lookups = array();
	$res = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	return array( $res, $GLOBALS['API']->sites, array_map( function ( $l ) { return $l[0]; }, $GLOBALS['API']->lookups ), $GLOBALS['API']->calls );
}
/** Verify's item, as the eBay tab's Verify builds it - in a request of its own, with no push's item specifics read before it. */
function verify_item( WC_Product $product ) {
	$GLOBALS['API']->lookups = array();
	$exporter = exporter();
	$read     = new ReflectionProperty( 'TCGiant_Sync_Exporter', 'aspects_read' );
	if ( PHP_VERSION_ID < 80100 ) {
		$read->setAccessible( true );
	}
	$read->setValue( $exporter, array() );
	$settings = $exporter->settle_motors( $exporter->get_export_settings( $product->get_id() ), true );
	return $exporter->build_item_xml_public( $product, $settings );
}
function code_of( $res ) {
	return is_wp_error( $res ) ? $res->get_error_code() : ( $res['action'] ?? null );
}
function message_of( $res ) {
	return is_wp_error( $res ) ? $res->get_error_message() : '';
}
/** The ProductListingDetails of an item's body, or null. */
function pld( $body ) {
	return preg_match( '#<ProductListingDetails>\n(.*?)</ProductListingDetails>\n#s', (string) $body, $m ) ? $m[1] : null;
}
/** Each variation's VariationProductListingDetails, by its SKU. */
function vpld( $body ) {
	preg_match_all( '#<Variation>\s*<SKU>([^<]*)</SKU>(.*?)</Variation>#s', (string) $body, $all, PREG_SET_ORDER );
	$out = array();
	foreach ( $all as $v ) {
		$out[ $v[1] ] = preg_match( '#<VariationProductListingDetails>\n(.*?)\t\t</VariationProductListingDetails>#s', $v[2], $m ) ? $m[1] : null;
	}
	return $out;
}
/** The body with its ProductListingDetails taken out. */
function without_pld( $body ) {
	return preg_replace( '#<ProductListingDetails>\n.*?</ProductListingDetails>\n#s', '', (string) $body );
}
/** A body without its random UUID. */
function no_uuid( $body ) {
	return preg_replace( '#<UUID>[0-9a-fA-F]+</UUID>\n?#', '', (string) $body );
}
function logged( $words ) {
	foreach ( $GLOBALS['W']['log'] as $row ) {
		if ( false !== strpos( $row[1], $words ) ) {
			return $row[0];
		}
	}
	return null;
}

$UPC_PLD = "\t<UPC>036000291452</UPC>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n";
$DNA_PLD = "\t<UPC>Does not apply</UPC>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEBAY US: A UPC, AND EBAY'S \"DOES NOT APPLY\" WHERE ONE IS REQUIRED\n" . str_repeat( '=', 124 ) . "\n";

shop( 'EBAY_US', '31387' );
fresh_ebay();
$API->aspects                            = requires( array( 'UPC' ) );
$API->lookup_answers['GeteBayDetails@0'] = details( 'Does not apply' );

list( $res, $sites, $looked, $calls ) = push( item( 101, array( '_ebay_export_identifier' => '036000291452' ) ) );
check( 'a product with its UPC: listed, the UPC in ProductListingDetails, with IncludeeBayProductDetails false', array( code_of( $res ), $sites, pld( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@0' ), $UPC_PLD ) );
check( '  nothing asked of eBay for it: a product with an identifier needs no requirement', $looked, array() );
check( '  placed after the item specifics', strpos( $calls[0][1], '</ItemSpecifics>' ) < strpos( $calls[0][1], '<ProductListingDetails>' ), true );

list( $res, $sites, $looked, $calls ) = push( item( 102 ) );
check( 'one with none, in a category whose item specifics require a UPC: eBay asked only its text for "none" - never GetCategoryFeatures (3.31.1)', array( code_of( $res ), $sites, $looked ), array( 'created', array( 'AddFixedPriceItem@0' ), array( 'GeteBayDetails@0' ) ) );
check( '  sent eBay\'s "Does not apply" for the UPC, and nothing for EAN or ISBN', pld( $calls[0][1] ?? '' ), $DNA_PLD );
check( '  GeteBayDetails\' request, byte for byte', $API->lookups[0][1], '<DetailName>ProductDetails</DetailName>' );
check( '  said once, as info', logged( 'Push: WC #102 has no UPC, EAN or ISBN, and eBay requires UPC in category 31387, so eBay\'s own "Does not apply" was sent for it.' ), 'info' );
check( '  and no GetCategoryFeatures line: 3.30.3\'s "eBay requires ... (GetCategoryFeatures), kept a week." is gone', logged( '(GetCategoryFeatures)' ), null );
$dna_body = (string) ( $calls[0][1] ?? '' );

list( $res, $sites, $looked, $calls ) = push( item( 103 ) );
check( 'a second in the same category: nothing asked again, eBay\'s text kept a week', array( code_of( $res ), $looked, pld( $calls[0][1] ?? '' ) ), array( 'created', array(), $DNA_PLD ) );
check( '  nothing kept of GetCategoryFeatures, the text under the site', array( isset( $GLOBALS['W']['transients']['tcgiant_gtin_req_0_31387'] ), $GLOBALS['W']['transients']['tcgiant_gtin_text_0']['text'] ?? null ), array( false, 'Does not apply' ) );
$API->aspects = requires( array() );
list( $res, , , $calls ) = push( item( 102 ) );
check( '  the same product where the item specifics require none: the same body, byte for byte, without ProductListingDetails', array( pld( $calls[0][1] ?? '' ), no_uuid( $calls[0][1] ?? '' ) === no_uuid( without_pld( $dna_body ) ) ), array( null, true ) );
$API->aspects = requires( array( 'UPC' ) );

$body = verify_item( item( 104 ) );
check( 'Verify sends the same as a new listing', pld( $body ), $DNA_PLD );

list( $res, , , $calls ) = push( item( 105, array( '_ebay_export_identifier' => ' 0360-0029 1452 ' ) ) );
check( 'a UPC typed with spaces and hyphens: sent as its digits', pld( $calls[0][1] ?? '' ), $UPC_PLD );

list( $res, , , $calls ) = push( item( 106, array(), array( 'gtin' => '036000291452' ) ) );
check( 'WooCommerce\'s GTIN field, with the plugin\'s empty: sent', pld( $calls[0][1] ?? '' ), $UPC_PLD );
list( $res, , , $calls ) = push( item( 107, array( '_ebay_export_identifier' => '012345678905' ), array( 'gtin' => '036000291452' ) ) );
check( '  the plugin\'s own field beats it', pld( $calls[0][1] ?? '' ), "\t<UPC>012345678905</UPC>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n" );
list( $res, , , $calls ) = push( item( 108, array( '_ebay_export_identifier' => 'N/A' ), array( 'gtin' => 'Does not apply' ) ) );
check( 'stand-ins for none in either field are none: eBay\'s own text is sent', pld( $calls[0][1] ?? '' ), $DNA_PLD );

list( $res, , , $calls ) = push( item( 109, array( '_ebay_export_identifier' => 'ABC-123' ) ) );
check( 'a value that is no UPC, EAN or ISBN by its digits: not sent, and no "Does not apply" over it', array( code_of( $res ), pld( $calls[0][1] ?? '' ) ), array( 'created', null ) );
check( '  said, as a warning', logged( 'the product identifier "ABC123" of WC #109 is not a UPC, EAN or ISBN by its digits, so it was not sent to eBay' ), 'warning' );
list( $res, , , $calls ) = push( item( 110, array( '_ebay_export_identifier' => 'ABC-123', '_ebay_export_identifier_type' => 'UPC' ) ) );
check( '  with its type chosen: sent as typed, for eBay to judge', pld( $calls[0][1] ?? '' ), "\t<UPC>ABC123</UPC>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n" );
list( $res, , , $calls ) = push( item( 111, array( '_ebay_export_identifier' => '036000291452', '_ebay_export_identifier_type' => 'EAN' ) ) );
check( '  twelve digits declared an EAN: sent as an EAN', pld( $calls[0][1] ?? '' ), "\t<EAN>036000291452</EAN>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n" );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SHOP'S CASE: EBAY US, AN EBAY MOTORS PARTS & ACCESSORIES CATEGORY\n" . str_repeat( '=', 124 ) . "\n";

shop( 'EBAY_US', '33564' );
fresh_ebay();
$API->tree_aspects['100']['33564']         = requires( array( 'UPC' ) );
$API->lookup_answers['GeteBayDetails@100'] = details( 'Does not apply' );
list( $res, $sites, $looked, $calls ) = push( item( 201 ) );
check( 'a caliper with no UPC in 33564, eBay Motors\' item specifics requiring one: listed on eBay Motors with "Does not apply" for the UPC', array( code_of( $res ), $sites, pld( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@100' ), $DNA_PLD ) );
check( '  eBay Motors\' text asked of its site, 100, where it is; nothing else', $looked, array( 'GeteBayDetails@100' ) );
list( $res, $sites, $looked, $calls ) = push( item( 202, array( '_ebay_export_identifier' => '036000291452' ) ) );
check( '  one with its UPC: the UPC', array( code_of( $res ), pld( $calls[0][1] ?? '' ), $looked ), array( 'created', $UPC_PLD, array() ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEBAY GERMANY AND THE UK: AN EAN, AND EBAY'S OWN WORDS FOR NONE\n" . str_repeat( '=', 124 ) . "\n";

shop( 'EBAY_DE', '9355', array( 'export_location' => 'Berlin', 'export_postal_code' => '10115' ) );
fresh_ebay();
$API->aspects = requires( array( 'EAN' ) );
// GeteBayDetails' answer for eBay Germany kept from an earlier push.
set_transient( 'tcgiant_gtin_text_77', array( 'text' => 'Nicht zutreffend' ), 604800 );
list( $res, $sites, $looked, $calls ) = push( item( 301, array( '_ebay_export_identifier' => '4006381333931' ) ) );
check( 'eBay Germany, a product with its EAN: AddFixedPriceItem with 77, the EAN', array( code_of( $res ), $sites, pld( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@77' ), "\t<EAN>4006381333931</EAN>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n" ) );
list( $res, $sites, $looked, $calls ) = push( item( 302 ) );
check( '  one with none: eBay Germany\'s own "Nicht zutreffend" for the EAN, from the GeteBayDetails answer kept', array( code_of( $res ), pld( $calls[0][1] ?? '' ) ), array( 'created', "\t<EAN>Nicht zutreffend</EAN>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n" ) );
check( '  nothing asked of site 77: the text kept, the requirement the item specifics\'', array( $looked, isset( $GLOBALS['W']['transients']['tcgiant_gtin_req_77_9355'] ) ), array( array(), false ) );

shop( 'EBAY_GB', '9355', array( 'export_location' => 'Leeds', 'export_postal_code' => 'LS1 1AA' ) );
fresh_ebay();
$API->aspects                            = requires( array( 'EAN' ) );
$API->lookup_answers['GeteBayDetails@3'] = details( 'Does not apply' );
list( $res, $sites, $looked, $calls ) = push( item( 311, array( '_ebay_export_identifier' => '5012345678900' ) ) );
check( 'eBay UK, an EAN: listed with 3, the EAN', array( code_of( $res ), $sites, pld( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@3' ), "\t<EAN>5012345678900</EAN>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n" ) );
list( $res, , $looked, $calls ) = push( item( 312 ) );
check( '  none: "Does not apply" for the EAN, eBay UK\'s words, asked of site 3', array( pld( $calls[0][1] ?? '' ), $looked ), array( "\t<EAN>Does not apply</EAN>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n", array( 'GeteBayDetails@3' ) ) );

shop( 'EBAY_IT', '9355', array( 'export_location' => 'Roma', 'export_postal_code' => '00100' ) );
fresh_ebay();
$API->aspects                              = requires( array( 'EAN' ) );
$API->lookup_answers['GeteBayDetails@101'] = new WP_Error( 'api_error', 'GeteBayDetails has been decommissioned.' );
list( $res, , $looked, $calls ) = push( item( 321 ) );
check( 'eBay Italy with GeteBayDetails gone: eBay\'s documented "Non applicabile"', array( code_of( $res ), pld( $calls[0][1] ?? '' ), $looked ), array( 'created', "\t<EAN>Non applicabile</EAN>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n", array( 'GeteBayDetails@101' ) ) );
list( , , $looked ) = push( item( 322 ) );
check( '  not asked again within the hour', $looked, array() );
unset( $GLOBALS['W']['transients']['tcgiant_gtin_text_101'] );
$API->lookup_answers['GeteBayDetails@101'] = details( 'Non applicabile' );
list( , , $looked ) = push( item( 323 ) );
check( '  asked again once the hour is up', $looked, array( 'GeteBayDetails@101' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA BOOK, A VARIATION LISTING, AND CATEGORIES THAT REQUIRE NONE OR DO NOT SAY\n" . str_repeat( '=', 124 ) . "\n";

shop( 'EBAY_US', '261186' );
fresh_ebay();
$API->aspects = requires( array( 'ISBN' ) );
list( $res, , $looked, $calls ) = push( item( 401, array( '_ebay_export_identifier' => '978-0-306-40615-7' ) ) );
check( 'a book with its ISBN-13 (978...): sent as the ISBN', array( code_of( $res ), pld( $calls[0][1] ?? '' ), $looked ), array( 'created', "\t<ISBN>9780306406157</ISBN>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n", array() ) );
list( $res, , , $calls ) = push( item( 402, array( '_ebay_export_identifier' => '0-306-40615-2' ) ) );
check( '  an ISBN-10: the ISBN', pld( $calls[0][1] ?? '' ), "\t<ISBN>0306406152</ISBN>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n" );
list( $res, , , $calls ) = push( item( 403 ) );
check( '  a book with none: "Does not apply" for the ISBN alone', pld( $calls[0][1] ?? '' ), "\t<ISBN>Does not apply</ISBN>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n" );

// A variation listing: each variation its own, in its Variation.
shop( 'EBAY_US', '31387' );
fresh_ebay();
$API->aspects                            = requires( array( 'UPC' ) );
$API->lookup_answers['GeteBayDetails@0'] = details( 'Does not apply' );
$var = item( 500, array(), array( 'type' => 'variable', 'sku' => 'VAR-500' ), 'Id_Variable' );
$var->variation_attributes = array( 'size' => array( 'Small', 'Large', 'Huge' ) );
$var->children             = array( 5001, 5002, 5003 );
item( 5001, array( '_ebay_export_identifier' => '036000291452' ), array( 'sku' => 'VAR-500-S', 'size' => 'Small', 'attributes' => array() ), 'Id_Variation' );
item( 5002, array(), array( 'sku' => 'VAR-500-L', 'size' => 'Large', 'gtin' => '012345678905', 'attributes' => array() ), 'Id_Variation' );
item( 5003, array(), array( 'sku' => 'VAR-500-H', 'size' => 'Huge', 'attributes' => array() ), 'Id_Variation' );
list( $res, , $looked, $calls ) = push( $var );
check( 'a variation listing: no ProductListingDetails for the item, which eBay refuses for one', array( code_of( $res ), pld( $calls[0][1] ?? '' ) ), array( 'created', null ) );
check( '  each variation its own UPC (the plugin\'s, WooCommerce\'s), and "Does not apply" for the one with none', vpld( $calls[0][1] ?? '' ), array( 'VAR-500-S' => "\t\t\t<UPC>036000291452</UPC>\n", 'VAR-500-L' => "\t\t\t<UPC>012345678905</UPC>\n", 'VAR-500-H' => "\t\t\t<UPC>Does not apply</UPC>\n" ) );
check( '  said for the variation', logged( 'Push: variation #5003 of WC #500 has no UPC, EAN or ISBN, and eBay requires UPC in category 31387' ), 'info' );
update_post_meta( 5003, '_ebay_export_identifier', '9780306406157' );
list( $res, , $looked, $calls ) = push( $var );
check( '  every variation with one: each its own, nothing asked', array( vpld( $calls[0][1] ?? '' ), $looked ), array( array( 'VAR-500-S' => "\t\t\t<UPC>036000291452</UPC>\n", 'VAR-500-L' => "\t\t\t<UPC>012345678905</UPC>\n", 'VAR-500-H' => "\t\t\t<ISBN>9780306406157</ISBN>\n" ), array() ) );

// Variations with EANs in a category that requires a UPC: eBay takes one
// type for every variation, so the one with none is sent no UPC.
$var = item( 510, array(), array( 'type' => 'variable', 'sku' => 'VAR-510' ), 'Id_Variable' );
$var->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
$var->children             = array( 5101, 5102 );
item( 5101, array( '_ebay_export_identifier' => '4006381333931' ), array( 'sku' => 'VAR-510-S', 'size' => 'Small', 'attributes' => array() ), 'Id_Variation' );
item( 5102, array(), array( 'sku' => 'VAR-510-L', 'size' => 'Large', 'attributes' => array() ), 'Id_Variation' );
list( $res, , , $calls ) = push( $var );
check( 'variations with EANs where a UPC is required: the EAN, and nothing for the one with none - never a UPC beside EANs', array( code_of( $res ), vpld( $calls[0][1] ?? '' ) ), array( 'created', array( 'VAR-510-S' => "\t\t\t<EAN>4006381333931</EAN>\n", 'VAR-510-L' => null ) ) );

// A category that requires none, and one whose item specifics cannot be read.
shop( 'EBAY_US', '31388' );
fresh_ebay();
$API->aspects = requires( array() );
list( $res, , $looked, $calls ) = push( item( 601 ) );
check( 'a category whose item specifics require none: nothing sent, nothing asked', array( code_of( $res ), pld( $calls[0][1] ?? '' ), $looked ), array( 'created', null, array() ) );
list( $res, , $looked, $calls ) = push( item( 602, array( '_ebay_export_identifier' => '036000291452' ) ) );
check( '  one with a UPC there: the UPC still goes', pld( $calls[0][1] ?? '' ), $UPC_PLD );

shop( 'EBAY_US', '31389' );
fresh_ebay();
$API->tree_aspects['0']['31389'] = motors_error( 62004 );
list( $res, , $looked, $calls ) = push( item( 611 ) );
check( 'the item specifics cannot be read, nothing learnt: none known - nothing sent, nothing refused, nothing asked; eBay judges, as before 3.30.3', array( code_of( $res ), pld( $calls[0][1] ?? '' ), $looked ), array( 'created', null, array() ) );

// The category's item specifics say UPC is required.
shop( 'EBAY_US', '31390' );
fresh_ebay();
$API->aspects = array( array( 'name' => 'UPC', 'required' => true ), array( 'name' => 'Brand', 'required' => true ) );
list( $res, , , $calls ) = push( item( 621 ) );
check( 'a required UPC aspect: not refused as a missing attribute, and "Does not apply" sent', array( code_of( $res ), pld( $calls[0][1] ?? '' ) ), array( 'created', $DNA_PLD ) );
$reads_none = count( array_keys( $API->rest, 'aspects:0:31390', true ) );
list( $res ) = push( item( 624, array( '_ebay_export_identifier' => '036000291452' ) ) );
check( '  the item specifics read no more often for it than for a product with its UPC: the push\'s own read kept', array( code_of( $res ), $reads_none ), array( 'created', count( array_keys( $API->rest, 'aspects:0:31390', true ) ) ) );
list( $res, , , $calls ) = push( item( 622, array(), array( 'attributes' => array( new WC_Product_Attribute( 'Brand', array( 'Acme' ) ), new WC_Product_Attribute( 'UPC', array( '036000291452' ) ) ) ) ) );
check( 'a UPC as an item specific: it goes as one, as before, and nothing is added for it', array( code_of( $res ), pld( $calls[0][1] ?? '' ), false !== strpos( $calls[0][1] ?? '', '<Name>UPC</Name>' ) ), array( 'created', null, true ) );
list( $res, , , $calls ) = push( item( 623, array( '_ebay_export_specifics' => array( 'UPC' => 'Does not apply' ) ) ) );
check( '  "Does not apply" typed as an item specific: the same', array( code_of( $res ), pld( $calls[0][1] ?? '' ) ), array( 'created', null ) );
shop( 'EBAY_US', '31391' );
fresh_ebay();
$API->aspects = array( array( 'name' => 'UPC', 'required' => false, 'selection' => 'RECOMMENDED' ), array( 'name' => 'Brand', 'required' => true ) );
list( $res, , , $calls ) = push( item( 625 ) );
check( 'a UPC aspect eBay recommends, not requires: nothing sent', array( code_of( $res ), pld( $calls[0][1] ?? '' ) ), array( 'created', null ) );
$API->aspects = array();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SETTING OFF: REFUSED BEFORE EBAY, UNLESS THE PRODUCT SAYS IT HAS NONE\n" . str_repeat( '=', 124 ) . "\n";

shop( 'EBAY_US', '31387', array( 'export_identifier_unavailable' => '0' ) );
fresh_ebay();
$API->aspects                            = requires( array( 'UPC' ) );
$API->lookup_answers['GeteBayDetails@0'] = details( 'Does not apply' );
list( $res, $sites, $looked ) = push( item( 701 ) );
check( 'none, required, the setting off: refused before eBay, in 3.28.5\'s words, nothing asked', array( code_of( $res ), $sites, $looked ), array( 'missing_export_settings', array(), array() ) );
check( '  saying what to do', message_of( $res ), 'Cannot push to eBay — missing required settings: Product Identifier — eBay requires UPC in category 31387, and this product has none: enter it in Product Identifier on the product\'s eBay tab, or tick "This product has no UPC, EAN or ISBN" there, or choose to send eBay\'s "Does not apply" in Settings, Push to eBay Settings' );
$exporter = exporter();
$exporter->build_item_xml_public( item( 702 ), $exporter->settle_motors( $exporter->get_export_settings( 702 ), true ) );
check( '  Verify and Check with eBay refuse it too, before eBay (description_too_long())', code_of( $exporter->description_too_long() ), 'missing_export_settings' );
list( $res, $sites, , $calls ) = push( item( 703, array( '_ebay_export_identifier_none' => '1' ) ) );
check( 'the product ticked as having none: "Does not apply", whatever Settings says', array( code_of( $res ), $sites, pld( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@0' ), $DNA_PLD ) );
list( $res, , , $calls ) = push( item( 704, array( '_ebay_export_identifier' => '036000291452' ) ) );
check( 'one with its UPC: listed with it', array( code_of( $res ), pld( $calls[0][1] ?? '' ) ), array( 'created', $UPC_PLD ) );
$API->aspects = requires( array() );
list( $res, , , $calls ) = push( item( 705 ) );
check( 'none, not required: listed with nothing, never refused', array( code_of( $res ), pld( $calls[0][1] ?? '' ) ), array( 'created', null ) );

$var = item( 710, array(), array( 'type' => 'variable', 'sku' => 'VAR-710' ), 'Id_Variable' );
$var->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
$var->children             = array( 7101, 7102 );
item( 7101, array( '_ebay_export_identifier' => '036000291452' ), array( 'sku' => 'VAR-710-S', 'size' => 'Small', 'attributes' => array() ), 'Id_Variation' );
item( 7102, array(), array( 'sku' => 'VAR-710-L', 'size' => 'Large', 'attributes' => array() ), 'Id_Variation' );
$API->aspects = requires( array( 'UPC' ) );
list( $res, $sites ) = push( $var );
check( 'a variation with none, the setting off: refused, naming it', array( code_of( $res ), $sites, false !== strpos( message_of( $res ), 'variation #7102 has none' ) ), array( 'missing_export_settings', array(), true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nUPDATES SEND NONE, PUSHED OR IMPORTED (FIX ROUND 1)\n" . str_repeat( '=', 124 ) . "\n";

shop( 'EBAY_US', '31387' );
fresh_ebay();
$API->aspects = requires( array( 'UPC' ) );
$pushed = array( '_ebay_item_id' => '110000000801', '_ebay_site' => 'US', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-10-31 10:00:00', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' );
list( $res, $sites, $looked, $calls ) = push( item( 801, $pushed + array( '_ebay_export_identifier' => '036000291452' ) ) );
check( 'a pushed listing\'s Update: no identifier, even its own UPC - as before 3.30.3', array( code_of( $res ), $sites, pld( $calls[0][1] ?? '' ), $looked ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), null, array() ) );
list( $res, $sites, $looked, $calls ) = push( item( 802, $pushed ) );
check( '  one with none: nothing - never "Does not apply" over what eBay has - nothing asked, nothing refused', array( code_of( $res ), pld( $calls[0][1] ?? '' ), $looked ), array( 'updated', null, array() ) );
shop( 'EBAY_US', '31387', array( 'export_identifier_unavailable' => '0' ) );
list( $res ) = push( item( 803, $pushed ) );
check( '  nor with the setting off', code_of( $res ), 'updated' );
shop( 'EBAY_US', '31387' );

$imported = array( '_ebay_item_id' => '110000000811', '_ebay_site' => 'US', '_ebay_origin' => 'import', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_live' => array( 'item_id' => '110000000811', 'category_id' => '31387', 'condition_id' => '1000', 'policies' => array( 'shipping' => '1', 'return' => '2', 'payment' => '3' ) ) );
list( $res, $sites, $looked, $calls ) = push( item( 811, $imported + array( '_ebay_export_identifier' => '036000291452' ) ) );
check( 'an imported listing\'s Update: no identifier, even the product\'s own (3.28.8)', array( code_of( $res ), $sites, pld( $calls[0][1] ?? '' ), $looked ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), null, array() ) );
list( $res, , , $calls ) = push( item( 812, $imported + array( '_ebay_export_identifier' => '036000291452', TCGiant_Sync_Ebay_Live::FOLLOW_META => '1' ) ) );
check( '  one set to send the shop\'s defaults: none either', array( code_of( $res ), pld( $calls[0][1] ?? '' ) ), array( 'updated', null ) );

// An ended pushed listing: a new listing in its place is a new listing.
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000821', 'ListingType' => 'FixedPriceItem', 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ) );
list( $res, $sites, , $calls ) = push( item( 821, array( '_ebay_item_id' => '110000000821' ) + $pushed ) );
check( 'an ended pushed listing: the Update sends nothing, the new listing in its place "Does not apply"', array( code_of( $res ), $sites, pld( $calls[0][1] ?? '' ), pld( $calls[2][1] ?? '' ) ), array( 'relisted', array( 'ReviseFixedPriceItem@0', 'GetItem@0', 'AddFixedPriceItem@0' ), null, "\t<UPC>Does not apply</UPC>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n" ) );
$API->answers = array();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEBAY'S OWN REFUSAL FOR A MISSING UPC, EAN OR ISBN: LEARNT, AND SENT ONCE MORE (FIX ROUND 1)\n" . str_repeat( '=', 124 ) . "\n";

// The item specifics say nothing of it: not known.
shop( 'EBAY_US', '31392' );
fresh_ebay();
$API->lookup_answers['GeteBayDetails@0'] = details( 'Does not apply' );
$API->queue['AddFixedPriceItem']         = array( ebay_refusal( array( $CUSTOMER ) ) );
list( $res, $sites, $looked, $calls ) = push( item( 1001 ) );
check( 'the shop\'s own error, "' . $CUSTOMER . '": listed by a second Add', array( code_of( $res ), $sites ), array( 'created', array( 'AddFixedPriceItem@0', 'AddFixedPriceItem@0' ) ) );
check( '  the first sent none, the second eBay\'s "Does not apply" for the UPC', array( pld( $calls[0][1] ?? '' ), pld( $calls[1][1] ?? '' ) ), array( null, $DNA_PLD ) );
check( '  the second otherwise the first, byte for byte, with a UUID of its own', array( no_uuid( without_pld( $calls[1][1] ?? '' ) ) === no_uuid( $calls[0][1] ?? '' ), preg_match( '#<UUID>(\w+)</UUID>#', $calls[0][1] ?? '', $u1 ) && preg_match( '#<UUID>(\w+)</UUID>#', $calls[1][1] ?? '', $u2 ) && $u1[1] !== $u2[1] ), array( true, true ) );
check( '  eBay asked only its text for none, after the refusal', $looked, array( 'GeteBayDetails@0' ) );
check( '  learnt for the category on site 0, a week', $GLOBALS['W']['transients']['tcgiant_gtin_learnt_0_31392'] ?? null, array( 'UPC' ) );
check( '  said: what eBay taught, and the second Add', array( logged( 'Product identifiers: eBay refused a listing in category 31392 on site 0 for a missing UPC; kept a week' ), logged( 'Push: eBay refused WC #1001 for a missing UPC (Error: The UPC field is missing. Please add UPC to the listing and try again.), so it was sent once more with eBay\'s own "Does not apply" for it.' ) ), array( 'info', 'info' ) );
list( $res, $sites, $looked, $calls ) = push( item( 1002 ) );
check( 'the next product in the category: "Does not apply" the first time, one Add, nothing asked', array( code_of( $res ), $sites, pld( $calls[0][1] ?? '' ), $looked ), array( 'created', array( 'AddFixedPriceItem@0' ), $DNA_PLD, array() ) );

// The shop's case: eBay Motors, whose item specifics for 33564 name Brand
// alone (fresh_ebay()).
shop( 'EBAY_US', '33564' );
fresh_ebay();
$API->lookup_answers['GeteBayDetails@100'] = details( 'Does not apply' );
$API->queue['AddFixedPriceItem']           = array( ebay_refusal( array( $CUSTOMER ) ) );
list( $res, $sites, $looked, $calls ) = push( item( 1011 ) );
check( 'the shop\'s case, 33564 on eBay Motors, its item specifics silent on the UPC: refused, then listed with "Does not apply", learnt', array( code_of( $res ), $sites, pld( $calls[0][1] ?? '' ), pld( $calls[1][1] ?? '' ), $GLOBALS['W']['transients']['tcgiant_gtin_learnt_100_33564'] ?? null, $looked ), array( 'created', array( 'AddFixedPriceItem@100', 'AddFixedPriceItem@100' ), null, $DNA_PLD, array( 'UPC' ), array( 'GeteBayDetails@100' ) ) );

// Only that refusal, only once.
shop( 'EBAY_US', '31393' );
fresh_ebay();
$API->queue['AddFixedPriceItem'] = array( new WP_Error( 'api_error', $CUSTOMER ) );
list( $res, $sites ) = push( item( 1021 ) );
check( 'the refusal with its words alone (no Errors with it): the same', array( code_of( $res ), $sites ), array( 'created', array( 'AddFixedPriceItem@0', 'AddFixedPriceItem@0' ) ) );
foreach ( array(
	'an item specific eBay requires'          => ebay_refusal( array( 'The item specific Brand is missing. Add Brand to this listing, enter a valid value, and then try again.' ) ),
	'the UPC beside another error'            => ebay_refusal( array( $CUSTOMER, 'The category is not valid, select another category.' ) ),
	'a refusal that is no eBay answer'        => new WP_Error( 'http_request_failed', $CUSTOMER ),
	'eBay\'s words in another language'       => ebay_refusal( array( 'Das Feld UPC fehlt.' ) ),
) as $label => $refusal ) {
	shop( 'EBAY_US', '31394' );
	fresh_ebay();
	$API->queue['AddFixedPriceItem'] = array( $refusal );
	list( $res, $sites ) = push( item( 1031 ) );
	check( 'no second Add for ' . $label . ': eBay\'s answer stands, nothing learnt', array( code_of( $res ), $sites, $GLOBALS['W']['transients']['tcgiant_gtin_learnt_0_31394'] ?? null ), array( $refusal->get_error_code(), array( 'AddFixedPriceItem@0' ), null ) );
}
shop( 'EBAY_US', '31394' );
fresh_ebay();
$API->queue['AddFixedPriceItem'] = array( ebay_refusal( array( $CUSTOMER ), array( 'Shipping service Other (see description) is not available.' ) ) );
list( $res, $sites ) = push( item( 1032 ) );
check( 'a warning beside the refusal is not an error: sent once more', array( code_of( $res ), $sites ), array( 'created', array( 'AddFixedPriceItem@0', 'AddFixedPriceItem@0' ) ) );
fresh_ebay();
$API->queue['AddFixedPriceItem'] = array( ebay_refusal( array( $CUSTOMER ) ), ebay_refusal( array( $CUSTOMER ) ) );
list( $res, $sites ) = push( item( 1033 ) );
check( 'refused again for it: sent once more only, and eBay\'s second answer given', array( code_of( $res ), message_of( $res ), $sites ), array( 'api_error', $CUSTOMER, array( 'AddFixedPriceItem@0', 'AddFixedPriceItem@0' ) ) );
fresh_ebay();
$API->queue['AddFixedPriceItem'] = array( ebay_refusal( array( $CUSTOMER ) ) );
list( $res, $sites ) = push( item( 1034, array( '_ebay_export_identifier' => '4006381333931' ) ) );
check( 'a product with an EAN eBay says lacks a UPC: no "Does not apply" beside its own, no second Add, learnt', array( code_of( $res ), $sites, $GLOBALS['W']['transients']['tcgiant_gtin_learnt_0_31394'] ?? null ), array( 'api_error', array( 'AddFixedPriceItem@0' ), array( 'UPC' ) ) );
fresh_ebay();
$API->queue['AddFixedPriceItem'] = array( ebay_refusal( array( 'The EAN field is missing.', 'The UPC field is missing.' ) ) );
list( $res, $sites, , $calls ) = push( item( 1035 ) );
check( 'two identifiers named: "Does not apply" for both', array( code_of( $res ), pld( $calls[1][1] ?? '' ) ), array( 'created', "\t<EAN>Does not apply</EAN>\n\t<UPC>Does not apply</UPC>\n\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n" ) );

// The setting off.
shop( 'EBAY_US', '31395', array( 'export_identifier_unavailable' => '0' ) );
fresh_ebay();
$API->queue['AddFixedPriceItem'] = array( ebay_refusal( array( $CUSTOMER ) ) );
list( $res, $sites ) = push( item( 1041 ) );
check( 'the setting off: no second Add; the push\'s own words instead of eBay\'s, and learnt', array( code_of( $res ), $sites, 0 === strpos( message_of( $res ), 'Cannot push to eBay — missing required settings: Product Identifier — eBay requires UPC in category 31395, and this product has none' ), $GLOBALS['W']['transients']['tcgiant_gtin_learnt_0_31395'] ?? null ), array( 'missing_export_settings', array( 'AddFixedPriceItem@0' ), true, array( 'UPC' ) ) );
list( $res, $sites ) = push( item( 1042 ) );
check( '  the next product with none in the category: refused before eBay', array( code_of( $res ), $sites ), array( 'missing_export_settings', array() ) );
fresh_ebay();
$API->queue['AddFixedPriceItem'] = array( ebay_refusal( array( $CUSTOMER ) ) );
list( $res, $sites, , $calls ) = push( item( 1043, array( '_ebay_export_identifier_none' => '1' ) ) );
check( '  a product ticked as having none: sent once more with "Does not apply"', array( code_of( $res ), $sites, pld( $calls[1][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@0', 'AddFixedPriceItem@0' ), $DNA_PLD ) );

// A variation listing.
shop( 'EBAY_US', '31396' );
fresh_ebay();
$API->queue['AddFixedPriceItem'] = array( ebay_refusal( array( $CUSTOMER ) ) );
$var = item( 1050, array(), array( 'type' => 'variable', 'sku' => 'VAR-1050' ), 'Id_Variable' );
$var->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
$var->children             = array( 10501, 10502 );
item( 10501, array( '_ebay_export_identifier' => '036000291452' ), array( 'sku' => 'VAR-1050-S', 'size' => 'Small', 'attributes' => array() ), 'Id_Variation' );
item( 10502, array(), array( 'sku' => 'VAR-1050-L', 'size' => 'Large', 'attributes' => array() ), 'Id_Variation' );
list( $res, $sites, , $calls ) = push( $var );
check( 'a variation listing refused for it: sent once more, "Does not apply" for the variation with none', array( code_of( $res ), $sites, vpld( $calls[1][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@0', 'AddFixedPriceItem@0' ), array( 'VAR-1050-S' => "\t\t\t<UPC>036000291452</UPC>\n", 'VAR-1050-L' => "\t\t\t<UPC>Does not apply</UPC>\n" ) ) );

// Verify, through the method the eBay tab's Verify and Check with eBay use.
shop( 'EBAY_US', '31397' );
fresh_ebay();
$exporter = exporter();
$settings = $exporter->settle_motors( $exporter->get_export_settings( item( 1061 )->get_id() ), true );
$first    = $exporter->build_item_xml_public( wc_get_product( 1061 ), $settings );
$again    = $exporter->identifier_resend( wc_get_product( 1061 ), $settings, ebay_refusal( array( $CUSTOMER ) ) );
check( 'Verify refused for it: the item to check once more carries "Does not apply"; the first did not', array( pld( $first ), pld( (string) $again ) ), array( null, $DNA_PLD ) );
check( '  said as Verify', logged( 'Verify: eBay refused WC #1061 for a missing UPC (Error: ' ), 'info' );
check( '  asked again with the same refusal: nothing more to send', $exporter->identifier_resend( wc_get_product( 1061 ), $settings, ebay_refusal( array( $CUSTOMER ) ) ), null );
check( '  another refusal: nothing', $exporter->identifier_resend( wc_get_product( 1061 ), $settings, ebay_refusal( array( 'Item.Country is missing.' ) ) ), null );
$admin_src = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' );
$tpl_src   = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-template-admin.php' );
check( '  the eBay tab\'s Verify and Check with eBay ask it after their call, and check once more with what it gives', array( substr_count( $admin_src, '$resend = $exporter->identifier_resend( $product, $settings, $result );' ), substr_count( $tpl_src, '$resend = $exporter->identifier_resend( $product, $settings, $result );' ) ), array( 1, 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA RELIST SENDS NONE, AS BEFORE (FIX ROUND 2)\n" . str_repeat( '=', 124 ) . "\n";

/** An ended listing as GetItem answers, in a category. */
function ended_in( $item_id, $category, array $more = array() ) {
	return array( 'Ack' => 'Success', 'Item' => array_merge( array( 'ItemID' => $item_id, 'ListingType' => 'FixedPriceItem', 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US', 'PrimaryCategory' => array( 'CategoryID' => $category ) ), $more ) );
}
/** A bulk Relist (relist_ended_listing()): its answer, its calls as Call@site, and their bodies. */
function relist_it( $id, array $answer ) {
	$GLOBALS['API']->clear();
	$GLOBALS['API']->lookups            = array();
	$GLOBALS['API']->answers['GetItem'] = $answer;
	$res = exporter()->relist_ended_listing( $id, 'Bulk relist' );
	unset( $GLOBALS['API']->answers['GetItem'] );
	return array( $res, $GLOBALS['API']->sites, $GLOBALS['API']->calls );
}

shop( 'EBAY_US', '31398' );
fresh_ebay();
$ended_pushed = array( '_ebay_item_id' => '110000001101', '_ebay_site' => 'US', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Ended', '_ebay_export_identifier' => '036000291452' );
item( 1101, $ended_pushed, array( 'gtin' => '012345678905' ) );
list( $res, $sites, $calls ) = relist_it( 1101, ended_in( '110000001101', '31398' ) );
check( 'a pushed listing whose product has a UPC, relisted: its request as on 0a8765d - ItemID, UUID, Quantity, nothing else', array( is_array( $res ) ? $res['item_id'] : code_of( $res ), $sites, preg_replace( '#<UUID>\w+</UUID>#', '<UUID/>', (string) ( $calls[1][1] ?? '' ) ), $API->lookups ), array( '110000000001', array( 'GetItem@0', 'RelistFixedPriceItem@0' ), "\n<Item>\n<ItemID>110000001101</ItemID>\n<UUID/>\n<Quantity>3</Quantity>\n</Item>", array() ) );
item( 1104, array( '_ebay_item_id' => '110000001104' ) + $ended_pushed );
$API->queue['RelistFixedPriceItem'] = array( ebay_refusal( array( $CUSTOMER ) ) );
list( $res, $sites ) = relist_it( 1104, ended_in( '110000001104', '31398' ) );
check( '  refused by eBay for a missing UPC: not relisted again, eBay\'s answer given, nothing learnt', array( code_of( $res ), message_of( $res ), $sites, $GLOBALS['W']['transients']['tcgiant_gtin_learnt_0_31398'] ?? null ), array( 'api_error', $CUSTOMER, array( 'GetItem@0', 'RelistFixedPriceItem@0' ), null ) );
$var = item( 1110, array( '_ebay_item_id' => '110000001110' ) + $ended_pushed, array( 'type' => 'variable', 'sku' => 'VAR-1110' ), 'Id_Variable' );
$var->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
$var->children             = array( 11101, 11102 );
item( 11101, array( '_ebay_export_identifier' => '036000291452' ), array( 'sku' => 'VAR-1110-S', 'size' => 'Small', 'attributes' => array() ), 'Id_Variation' );
item( 11102, array(), array( 'sku' => 'VAR-1110-L', 'size' => 'Large', 'attributes' => array() ), 'Id_Variation' );
$ebay_vars = array( 'Variation' => array(
	array( 'SKU' => 'VAR-1110-S', 'StartPrice' => '11.00', 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => '0' ), 'VariationSpecifics' => array( 'NameValueList' => array( 'Name' => 'size', 'Value' => 'Small' ) ) ),
	array( 'SKU' => 'VAR-1110-L', 'StartPrice' => '13.75', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0' ), 'VariationSpecifics' => array( 'NameValueList' => array( 'Name' => 'size', 'Value' => 'Large' ) ) ),
) );
list( $res, $sites, $calls ) = relist_it( 1110, ended_in( '110000001110', '31398', array( 'Variations' => $ebay_vars ) ) );
check( '  a variation listing\'s relist: no VariationProductListingDetails, no ProductListingDetails', array( $sites, false !== strpos( (string) ( $calls[1][1] ?? '' ), 'ProductListingDetails' ) ), array( array( 'GetItem@0', 'RelistFixedPriceItem@0' ), false ) );
$exporter_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-exporter.php' );
check( '  relist_ended_listing() asks nothing of the identifiers (the source)', 0 === preg_match( '/Identifiers::/', substr( $exporter_src, strpos( $exporter_src, 'public function relist_ended_listing(' ), 20000 ) ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 2: A VARIATION'S OWN GTIN, A REFUSAL TEACHES THE CATEGORY, A 14-DIGIT GTIN, THE ERRORS SAID\n" . str_repeat( '=', 124 ) . "\n";

// M1: the parent's GTIN, which a variation's getter could give it in 'view'.
shop( 'EBAY_US', '31399' );
fresh_ebay();
$API->aspects = requires( array( 'UPC' ) );
$var = item( 1200, array(), array( 'type' => 'variable', 'sku' => 'VAR-1200', 'gtin' => '036000291452' ), 'Id_Variable' );
$var->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
$var->children             = array( 12001, 12002 );
item( 12001, array(), array( 'sku' => 'VAR-1200-S', 'size' => 'Small', 'attributes' => array(), 'gtin' => '012345678905', 'parent_gtin' => '036000291452' ), 'Id_Variation' );
item( 12002, array(), array( 'sku' => 'VAR-1200-L', 'size' => 'Large', 'attributes' => array(), 'parent_gtin' => '036000291452' ), 'Id_Variation' );
check( 'a variation\'s getter offers its parent\'s GTIN in "view" (as played)', wc_get_product( 12002 )->get_global_unique_id(), '036000291452' );
list( $res, , , $calls ) = push( $var );
check( '  a variation with none of its own: "Does not apply", never its parent\'s code; one with its own: its own', array( code_of( $res ), vpld( $calls[0][1] ?? '' ) ), array( 'created', array( 'VAR-1200-S' => "\t\t\t<UPC>012345678905</UPC>\n", 'VAR-1200-L' => "\t\t\t<UPC>Does not apply</UPC>\n" ) ) );

// L1 (3.31.1): GetCategoryFeatures is never asked; eBay's refusal teaches
// the category.
shop( 'EBAY_US', '31400' );
fresh_ebay();
list( , , $looked ) = push( item( 1210 ) );
check( 'a product with none where the item specifics say nothing: nothing asked, nothing kept for the category', array( $looked, isset( $GLOBALS['W']['transients']['tcgiant_gtin_req_0_31400'] ) ), array( array(), false ) );
$API->queue['AddFixedPriceItem'] = array( ebay_refusal( array( $CUSTOMER ) ) );
list( $res, $sites, $looked ) = push( item( 1212 ) );
check( '  eBay\'s refusal teaches the category: its text asked, the Add sent once more', array( code_of( $res ), $sites, $looked ), array( 'created', array( 'AddFixedPriceItem@0', 'AddFixedPriceItem@0' ), array( 'GeteBayDetails@0' ) ) );
list( $res, $sites, $looked, $calls ) = push( item( 1213 ) );
check( '  learnt: "Does not apply" the first time, nothing asked', array( code_of( $res ), $sites, $looked, pld( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@0' ), array(), $DNA_PLD ) );
check( '  GeteBayDetails\' failure is still kept an hour', ( function () {
	fresh_ebay();
	$GLOBALS['API']->lookup_answers['GeteBayDetails@0'] = new WP_Error( 'api_error', 'GeteBayDetails has been decommissioned.' );
	TCGiant_Sync_Identifiers::unavailable_text( '0' );
	return $GLOBALS['W']['expiry']['tcgiant_gtin_text_0'] ?? null;
} )(), 3600 );

// L5: a 14-digit GTIN is none of eBay's identifier types.
shop( 'EBAY_US', '31401' );
fresh_ebay();
$API->aspects = requires( array( 'UPC' ) );
list( $res, , , $calls ) = push( item( 1220, array(), array( 'gtin' => '00036000291452' ) ) );
check( 'a 14-digit GTIN in WooCommerce\'s field: not sent, the product taken as having none - "Does not apply"', array( code_of( $res ), pld( $calls[0][1] ?? '' ) ), array( 'created', $DNA_PLD ) );
$gtin14_said = function () {
	return count( array_filter( $GLOBALS['W']['log'], function ( $row ) {
		return false !== strpos( $row[1], 'the GTIN "00036000291452" of WC #1220 has 14 digits' ) && 'warning' === $row[0];
	} ) );
};
check( '  said, as a warning', $gtin14_said(), 1 );
push( item( 1220, array(), array( 'gtin' => '00036000291452' ) ) );
check( '  once a product: not said again at its next push', $gtin14_said(), 1 );
list( $res, , , $calls ) = push( item( 1221, array( '_ebay_export_identifier' => '10036000291459' ), array( 'gtin' => '036000291452' ) ) );
check( '  one in the plugin\'s field, WooCommerce\'s a UPC: WooCommerce\'s goes', pld( $calls[0][1] ?? '' ), $UPC_PLD );
list( $res, , , $calls ) = push( item( 1222, array( '_ebay_export_identifier' => '00036000291452', '_ebay_export_identifier_type' => 'UPC' ) ) );
check( '  declared a UPC: still not sent - "Does not apply"', pld( $calls[0][1] ?? '' ), $DNA_PLD );
shop( 'EBAY_US', '31401', array( 'export_identifier_unavailable' => '0' ) );
list( $res, $sites ) = push( item( 1223, array(), array( 'gtin' => '00036000291452' ) ) );
check( '  the setting off: refused before eBay, as a product with none', array( code_of( $res ), $sites ), array( 'missing_export_settings', array() ) );
shop( 'EBAY_US', '31401' );

// L7: eBay's errors, each with its severity and code, in the log.
shop( 'EBAY_US', '31402' );
fresh_ebay();
$coded = new WP_Error( 'api_error', $CUSTOMER . ' | Your payments are on hold.', array( 'Ack' => 'Failure', 'Errors' => array(
	array( 'ErrorCode' => '21919301', 'ShortMessage' => 'Missing UPC.', 'LongMessage' => $CUSTOMER, 'SeverityCode' => 'Error' ),
	array( 'ErrorCode' => '21920200', 'ShortMessage' => 'Payments on hold.', 'LongMessage' => 'Your payments are on hold.', 'SeverityCode' => 'Warning' ),
) ) );
$API->queue['AddFixedPriceItem'] = array( $coded );
list( $res ) = push( item( 1230 ) );
check( 'the line that leads to the second Add names each error\'s severity and code', array( code_of( $res ), logged( 'Push: eBay refused WC #1230 for a missing UPC (Error 21919301: ' . $CUSTOMER . '; Warning 21920200: Your payments are on hold.), so it was sent once more' ) ), array( 'created', 'info' ) );
fresh_ebay();
$API->queue['AddFixedPriceItem'] = array( ebay_refusal( array( $CUSTOMER, 'Your payments are on hold.' ) ) );
list( $res, $sites ) = push( item( 1231 ) );
check( '  the hold an Error beside it: not sent again, and both said with their severity', array( code_of( $res ), $sites, logged( 'Push: eBay refused WC #1231 (Error: ' . $CUSTOMER . '; Error: Your payments are on hold.), and not only for a missing UPC, EAN or ISBN, so it was not sent again.' ) ), array( 'api_error', array( 'AddFixedPriceItem@0' ), 'info' ) );
check( '  errors_said() of eBay\'s words alone, and of nothing', array( TCGiant_Sync_Identifiers::errors_said( new WP_Error( 'api_error', 'x' ) ), TCGiant_Sync_Identifiers::errors_said( 'no' ) ), array( 'x', '' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PIECES: TYPES, STAND-INS, THE SETTING, eBAY'S TABLE\n" . str_repeat( '=', 124 ) . "\n";

check( 'eBay\'s refusal read by its words: the field, an item specific, EAN and ISBN, any case; nothing else', array_map( array( 'TCGiant_Sync_Identifiers', 'refused_for' ), array( ebay_refusal( array( $CUSTOMER ) ), ebay_refusal( array( 'The item specific UPC is missing. Add UPC to this listing.' ) ), ebay_refusal( array( 'the ean field is missing.' ) ), ebay_refusal( array( 'The ISBN field is missing.' ) ), ebay_refusal( array( 'The UPC field is invalid.' ) ), ebay_refusal( array( 'UPC missing' ) ), 'not an error' ) ), array( array( 'UPC' ), array( 'UPC' ), array( 'EAN' ), array( 'ISBN' ), array(), array(), array() ) );

check( 'types by digits: 12 UPC, 13 EAN, 978/979 ISBN, 8 EAN, 10 ISBN, 9+X ISBN; other none', array_map( array( 'TCGiant_Sync_Identifiers', 'type_of' ), array( '036000291452', '4006381333931', '9780306406157', '9791234567896', '96385074', '0306406152', '080442957X', '1234567', '12345678901234', 'ABC' ) ), array( 'UPC', 'EAN', 'ISBN', 'ISBN', 'EAN', 'ISBN', 'ISBN', '', '', '' ) );
check( '  a declared type wins; an unknown one does not', array( TCGiant_Sync_Identifiers::type_of( '4006381333931', 'UPC' ), TCGiant_Sync_Identifiers::type_of( '036000291452', 'GTIN' ), TCGiant_Sync_Identifiers::type_of( '', 'UPC' ) ), array( 'UPC', 'UPC', '' ) );
check( 'stand-ins for none, any case, eBay\'s localised ones too', array_map( array( 'TCGiant_Sync_Identifiers', 'is_placeholder' ), array( 'Does not apply', 'N/A', ' none ', 'Nicht zutreffend', 'NON APPLICABILE', 'No aplicable', 'Non applicable', 'Niet van toepassing', 'Nie dotyczy', '-', '', '036000291452' ) ), array( true, true, true, true, true, true, true, true, true, true, true, false ) );
check( 'the setting: on when never saved, off only at "0"', array( TCGiant_Sync_Identifiers::sends_unavailable( array() ), TCGiant_Sync_Identifiers::sends_unavailable( array( 'export_identifier_unavailable' => '1' ) ), TCGiant_Sync_Identifiers::sends_unavailable( array( 'export_identifier_unavailable' => '0' ) ) ), array( true, true, false ) );
check( 'eBay\'s documented text for every site the plugin lists on, and eBay Motors', array_map( function ( $id ) { return TCGiant_Sync_Identifiers::UNAVAILABLE_TEXT[ $id ] ?? null; }, array( '0', '2', '3', '15', '77', '71', '101', '186', '100' ) ), array( 'Does not apply', 'Does not apply', 'Does not apply', 'Does not apply', 'Nicht zutreffend', 'Non applicable', 'Non applicabile', 'No aplicable', 'Does not apply' ) );
check( 'the requirement: the item specifics\' required aspects and what eBay\'s refusals taught, nothing else (3.31.1)', ( function () {
	fresh_ebay();
	$GLOBALS['API']->lookups = array();
	$settings                = array( 'category_id' => '31405' );
	$one      = TCGiant_Sync_Identifiers::required_types( $settings, requires( array( 'ISBN' ) ) );
	$none     = TCGiant_Sync_Identifiers::required_types( $settings, null );
	$error    = TCGiant_Sync_Identifiers::required_types( $settings, motors_error( 62004 ) );
	set_transient( 'tcgiant_gtin_learnt_0_31405', array( 'UPC' ), 604800 );
	$both = TCGiant_Sync_Identifiers::required_types( $settings, requires( array( 'ISBN' ) ) );
	return array( $one, $none, $error, $both, $GLOBALS['API']->lookups );
} )(), array( array( 'ISBN' ), array(), array(), array( 'ISBN', 'UPC' ), array() ) );

// The import still reads an identifier, and takes eBay's own texts for none.
$placeholder = new ReflectionMethod( 'TCGiant_Sync_Mapper', 'is_placeholder_identifier' );
if ( PHP_VERSION_ID < 80100 ) {
	$placeholder->setAccessible( true );
}
check( 'the import: a real UPC, EAN or ISBN is still one', array( $placeholder->invoke( null, '036000291452' ), $placeholder->invoke( null, '4006381333931' ), $placeholder->invoke( null, '9780306406157' ) ), array( false, false, false ) );
check( '  eBay\'s "Does not apply" in every language a push sends is none, never a SKU', array_map( function ( $t ) use ( $placeholder ) { return $placeholder->invoke( null, $t ); }, array_values( array_unique( TCGiant_Sync_Identifiers::UNAVAILABLE_TEXT ) ) ), array( true, true, true, true, true, true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE FIELDS AND THE SETTING\n" . str_repeat( '=', 124 ) . "\n";

item( 901 );
TCGiant_Sync_Identifiers::save_posted( 901, array( '_ebay_export_identifier_shown' => '1', '_ebay_export_identifier' => ' 036000291452 ', '_ebay_export_identifier_type' => 'upc', '_ebay_export_identifier_none' => '1' ) );
check( 'the eBay tab\'s save: the value, its type, the choice', array( get_post_meta( 901, '_ebay_export_identifier', true ), get_post_meta( 901, '_ebay_export_identifier_type', true ), get_post_meta( 901, '_ebay_export_identifier_none', true ) ), array( '036000291452', 'UPC', '1' ) );
TCGiant_Sync_Identifiers::save_posted( 901, array( '_ebay_export_identifier_shown' => '1', '_ebay_export_identifier' => '', '_ebay_export_identifier_type' => 'GTIN' ) );
check( '  cleared: none of them kept', array( get_post_meta( 901, '_ebay_export_identifier', true ), get_post_meta( 901, '_ebay_export_identifier_type', true ), get_post_meta( 901, '_ebay_export_identifier_none', true ) ), array( '', '', '' ) );
update_post_meta( 901, '_ebay_export_identifier', '1' );
TCGiant_Sync_Identifiers::save_posted( 901, array( '_ebay_export_identifier' => '' ) );
check( '  a form without the section: nothing changed', get_post_meta( 901, '_ebay_export_identifier', true ), '1' );
$_POST = array( '_ebay_export_identifier_var_shown' => array( 3 => '1' ), '_ebay_export_identifier_var' => array( 3 => '012345678905' ), '_ebay_export_identifier_shown' => '1', '_ebay_export_identifier' => '999' );
TCGiant_Sync_Identifiers::save_variation_field( 9011, 3 );
TCGiant_Sync_Identifiers::save_variation_field( 9012, 4 );
check( 'a variation\'s field, by its place in the form, under its own name beside the eBay tab\'s; another place not posted: nothing', array( get_post_meta( 9011, '_ebay_export_identifier', true ), get_post_meta( 9012, '_ebay_export_identifier', true ) ), array( '012345678905', '' ) );
$GLOBALS['W']['caps']['edit_post'] = false;
$_POST = array( '_ebay_export_identifier_shown' => '1', '_ebay_export_identifier' => '999' );
TCGiant_Sync_Identifiers::save_product_field( 901 );
check( '  by someone who may not edit the product: nothing saved', get_post_meta( 901, '_ebay_export_identifier', true ), '1' );
unset( $GLOBALS['W']['caps']['edit_post'] );
$_POST = array();

ob_start();
TCGiant_Sync_Identifiers::render_product_section( item( 902, array( '_ebay_export_identifier_type' => 'EAN' ), array( 'gtin' => '4006381333931' ) ) );
$html = (string) ob_get_clean();
check( 'the eBay tab: the field, WooCommerce\'s GTIN shown greyed, the type chosen, the box, the marker', array( false !== strpos( $html, 'name="_ebay_export_identifier" value="" placeholder="4006381333931"' ), false !== strpos( $html, '<option value="EAN"  selected="selected">' ), false !== strpos( $html, 'name="_ebay_export_identifier_none" value="1"' ), false !== strpos( $html, 'name="_ebay_export_identifier_shown"' ) ), array( true, true, true, true ) );
ob_start();
TCGiant_Sync_Identifiers::render_variation_field( 2, array(), (object) array( 'ID' => 9011 ) );
$html = (string) ob_get_clean();
check( '  a variation\'s field, with its value, named apart from the eBay tab\'s', array( false !== strpos( $html, 'name="_ebay_export_identifier_var[2]" value="012345678905"' ), false !== strpos( $html, 'name="_ebay_export_identifier_var_shown[2]"' ) ), array( true, true ) );

$admin_src    = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' );
$settings_src = (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/settings.php' );
check( 'the setting is the push form\'s, drawn on it with "on" when never saved', array( false !== strpos( $admin_src, "'export_identifier_unavailable'," ), substr_count( $settings_src, "checked( \$settings['export_identifier_unavailable'] ?? '1', " ) ), array( true, 2 ) );
check( '  the Push and Verify buttons save the section first', substr_count( $admin_src, 'TCGiant_Sync_Identifiers::save_posted( $product_id, $_POST );' ), 2 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nCHECK WITH EBAY, THE SETTING OFF: THE PUSH'S OWN WORDS (FIX ROUND 2)\n" . str_repeat( '=', 124 ) . "\n";

class Check_Answer extends Exception {
	public $ok;
	public $data;
	public function __construct( $ok, $data ) { parent::__construct( 'json' ); $this->ok = $ok; $this->data = $data; }
}
function check_ajax_referer( $action = -1, $arg = false, $die = true ) { return 1; }
function wp_send_json_success( $data = null ) { throw new Check_Answer( true, $data ); }
function wp_send_json_error( $data = null ) { throw new Check_Answer( false, $data ); }
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-template-admin.php';
/** Check with eBay pressed for a product: its answer, and the calls it made. */
function check_with_ebay( $product_id ) {
	$GLOBALS['API']->clear();
	$_POST = array( 'product_id' => (string) $product_id, 'template_id' => '0' );
	try {
		( new ReflectionClass( 'TCGiant_Sync_Template_Admin' ) )->newInstanceWithoutConstructor()->ajax_check_ebay();
	} catch ( Check_Answer $a ) {
		$_POST = array();
		return array( $a->ok, (string) ( $a->data['message'] ?? '' ), $GLOBALS['API']->sites );
	}
	$_POST = array();
	return array( null, '', $GLOBALS['API']->sites );
}
shop( 'EBAY_US', '31403', array( 'export_identifier_unavailable' => '0' ) );
fresh_ebay();
item( 1240 );
$API->queue['VerifyAddFixedPriceItem'] = array( ebay_refusal( array( $CUSTOMER ) ) );
list( $ok, $said, $sites ) = check_with_ebay( 1240 );
check( 'refused by eBay for the UPC, the setting off: the push\'s own words, not "The check did not get an answer from eBay"', array( $ok, 0 === strpos( $said, 'Cannot push to eBay — missing required settings: Product Identifier — eBay requires UPC in category 31403' ), $sites ), array( false, true, array( 'VerifyAddFixedPriceItem@0' ) ) );
shop( 'EBAY_US', '31403' );
fresh_ebay();
item( 1241 );
$API->queue['VerifyAddFixedPriceItem'] = array( ebay_refusal( array( $CUSTOMER ) ) );
list( $ok, $said, $sites ) = check_with_ebay( 1241 );
check( '  the setting on: checked once more with "Does not apply", and accepted', array( $ok, $sites ), array( true, array( 'VerifyAddFixedPriceItem@0', 'VerifyAddFixedPriceItem@0' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nGETCATEGORYFEATURES IS ASKED NO MORE, AND WHAT 3.30.3 KEPT OF IT GOES (3.31.1)\n" . str_repeat( '=', 124 ) . "\n";

// On 2026-11-04, on eBay's live API, it answered with no XML at all, every
// time: "eBay Trading API Error: Invalid XML Response.".
check( 'in every push, Verify, Update, relist and check above: GetCategoryFeatures never asked; GeteBayDetails still is', array( count( $API->asked ) > 100, in_array( 'GetCategoryFeatures', $API->asked, true ), in_array( 'GeteBayDetails', $API->asked, true ) ), array( true, false, true ) );
$plugin_src = '';
foreach ( array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ), glob( dirname( __DIR__ ) . '/admin/*.php' ), glob( dirname( __DIR__ ) . '/admin/views/*.php' ), array( dirname( __DIR__ ) . '/tcgiant-sync.php', dirname( __DIR__ ) . '/uninstall.php' ) ) as $tcg_file ) {
	$plugin_src .= (string) file_get_contents( $tcg_file );
}
check( '  nor anywhere in the plugin: no request names it; get_identifier_features() and features() are gone', array( preg_match( '/[\'"]GetCategoryFeatures[\'"]/', $plugin_src ), method_exists( 'TCGiant_Sync_API', 'get_identifier_features' ), method_exists( 'TCGiant_Sync_Identifiers', 'features' ) ), array( 0, false, false ) );
check( '  and its log line, "... (GetCategoryFeatures), kept a week.", is gone', strpos( $plugin_src, '(GetCategoryFeatures), kept a week.' ), false );

/** LIKE as MySQL reads it: % any run, _ any one character, \ the next as itself. */
function sql_like( $pattern, $subject ) {
	$re = '';
	for ( $i = 0, $n = strlen( $pattern ); $i < $n; $i++ ) {
		$c = $pattern[ $i ];
		if ( '\\' === $c && $i + 1 < $n ) {
			$re .= preg_quote( $pattern[ ++$i ], '/' );
		} elseif ( '%' === $c ) {
			$re .= '.*';
		} elseif ( '_' === $c ) {
			$re .= '.';
		} else {
			$re .= preg_quote( $c, '/' );
		}
	}
	return 1 === preg_match( '/^' . $re . '$/s', $subject );
}
/** The database as the clean-up asks it: each statement recorded. */
class Forget_DB extends Template_World_DB {
	public $queries = array();
	public function query( $query ) {
		$this->queries[] = $query;
		return 4;
	}
}
$tcg_was_db      = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new Forget_DB();
delete_option( 'tcgiant_sync_gtin_req_forgotten' );
TCGiant_Sync_Identifiers::forget_category_features();
$first = $GLOBALS['wpdb']->queries;
check( 'at the update: one DELETE of the options table, recorded as done', array( count( $first ), $first[0][0] ?? null, get_option( 'tcgiant_sync_gtin_req_forgotten' ) ), array( 1, 'DELETE FROM wp_options WHERE option_name LIKE %s OR option_name LIKE %s', '3.31.1' ) );
$names = array( '_transient_tcgiant_gtin_req_0_31387', '_transient_timeout_tcgiant_gtin_req_0_31387', '_transient_tcgiant_gtin_req_100_33564', '_transient_timeout_tcgiant_gtin_req_77_9355', '_transient_tcgiant_gtin_text_0', '_transient_timeout_tcgiant_gtin_text_0', '_transient_tcgiant_gtin_learnt_0_31392', '_transient_tcgiant_gtin14_1220', '_transient_tcgiantxgtinxreqx0_1', 'tcgiant_gtin_req_0_1', '_site_transient_tcgiant_gtin_req_0_1' );
$gone  = array();
foreach ( $names as $name ) {
	if ( sql_like( (string) ( $first[0][1][0] ?? '' ), $name ) || sql_like( (string) ( $first[0][1][1] ?? '' ), $name ) ) {
		$gone[] = $name;
	}
}
check( '  by its LIKE patterns, only 3.30.3\'s kept answers and their timeouts: never eBay\'s text, what refusals taught, a 14-digit GTIN\'s note, or a name its underscores would match', $gone, array( '_transient_tcgiant_gtin_req_0_31387', '_transient_timeout_tcgiant_gtin_req_0_31387', '_transient_tcgiant_gtin_req_100_33564', '_transient_timeout_tcgiant_gtin_req_77_9355' ) );
TCGiant_Sync_Identifiers::forget_category_features();
check( '  never again', count( $GLOBALS['wpdb']->queries ), 1 );
$GLOBALS['wpdb'] = $tcg_was_db;
$core_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync.php' );
check( '  run on init, on every request (a shop whose admin is never opened too); its record deleted with the plugin\'s data', array( substr_count( $core_src, "add_action( 'init', array( 'TCGiant_Sync_Identifiers', 'forget_category_features' ) );" ), false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ), "'tcgiant_sync_gtin_req_forgotten'," ) ), array( 1, true ) );

done();
