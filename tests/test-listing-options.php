<?php
/**
 * Harness: listing options on a push (3.38.0) - a subtitle, a condition
 * note, a second category, an auction's Buy It Now and reserve, and Brand
 * and MPN.
 *
 * Until 3.38.0 a push sent none of them. Now each is a field of the
 * product's own on its eBay tab, empty unless the shop fills it in, and an
 * empty field sends nothing: a product with none sends 3.37.0's requests,
 * byte for byte.
 *
 * Here the real TCGiant_Sync_Exporter, TCGiant_Sync_Listing_Options,
 * TCGiant_Sync_Motors and TCGiant_Sync_API push, Verify and relist, with eBay
 * played (support/motors-api.php, support/template-world.php).
 *
 * Proved:
 *  - each field sent where it should be - a new listing, Verify, an Update,
 *    a relist, Auto-Relist, a push's relist of an imported auction - and not
 *    where it should not: a live auction's Update sends none of them, Buy It
 *    Now and reserve never go with a fixed-price item or in a revise, the
 *    condition note not with a New condition or condition descriptors, the
 *    second category not as the primary nor for an eBay Motors vehicle;
 *  - eBay's lengths: 55, 1,000 and 65; a price as eBay takes one;
 *  - Brand and MPN as item specifics, in place of one of the same name, by
 *    the category's names, and never as a partial set on an imported
 *    listing's Update; a required Brand aspect met by the field;
 *  - an emptied field: DeletedField (or CategoryID 0 for the second
 *    category on an Update) only for a value this plugin sent to the
 *    listing, recorded by the push and the relist eBay took;
 *  - a Buy It Now or reserve not above the opening bid: refused before eBay
 *    for a new listing and Verify, left out of a relist;
 *  - a variation listing (item-level fields only), eBay Motors, and the
 *    template re-send (description only, unchanged);
 *  - byte-identity with every field empty;
 *  - the fields' save and the eBay tab's section, and uninstall.
 *
 * Fix round 1 (the review's findings, its probes A to F among the cases):
 *  - Brand and MPN on an Update only with the item specifics 3.37.0 would
 *    have sent it, pushed or imported, "send the shop's defaults" or not;
 *  - an auction's prices by eBay's rules: a Buy It Now not above the
 *    reserve refused before eBay; a relist leaving out a reserve above the
 *    ended listing's or where it had none, and a Buy It Now not above the
 *    reserve it carries, and going;
 *  - MPN as "Manufacturer Part Number" (eBay Motors tree 100 included);
 *  - nothing recorded that eBay's warning says it dropped; an Update
 *    refused with 10031 or 10039 while taking a field off drops it from
 *    the record, sending nothing again;
 *  - the template's specifics table as the item's ItemSpecifics; the cut
 *    to thirty said; one read of the category's item specifics a build;
 *  - entities never cut in two; the auction rows for a recorded auction;
 *    "not sent" said once; the review's four surviving mutants.
 *
 * Fix round 2 (review 2's findings, its probes P1 to P11 among the cases):
 *  - a relist eBay refuses while it carries listing options goes once more
 *    without them, as 3.37.0 sent it - Bulk Relist, Auto-Relist and a push's
 *    relist alike: plain and taken, plain refused too, the memo holding, a
 *    changed value tried again, the API's own budget guard refusing the
 *    second; and not for an ended listing, no answer, a request sent again,
 *    eBay's 488 or a call limit;
 *  - a relist leaves out a Buy It Now under its site's minimum share above
 *    eBay's opening bid (30% eBay US, eBay Motors and eBay Australia, 40%
 *    else), inclusive in cents, and one with no opening bid to judge it by;
 *  - an ended auction with a sale reaches the push's relist; a live
 *    auction's Update sends 3.37.0's item specifics exactly; the 10039 line
 *    says what to do once a pending Best Offer is answered, and that works;
 *    a new listing's Buy It Now held to the reserve typed;
 *  - the review's surviving mutants R01, R03, R04 and R20.
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
	// The product moves on to a relist made on eBay, as the import moves it
	// (fix round 2: the live revise of such a relist).
	public static function follow_relist( $product_id, $from, $to, $facts = null, $source = '' ) { update_post_meta( $product_id, '_ebay_item_id', (string) $to ); }
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

/**
 * eBay answering a call several ways in turn (fix round 2): each queued
 * answer once, in order, then as before. 'guard' sends the call down the
 * real API class's own way in, with every budget guard it has; a Closure is
 * called with the API first.
 */
class Opt_Queue_API extends Motors_API {
	public $queue = array();
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		if ( ! empty( $this->queue[ $call_name ] ) ) {
			$answer = array_shift( $this->queue[ $call_name ] );
			if ( 'guard' === $answer ) {
				return TCGiant_Sync_API::trading_api_request( $call_name, $xml_body, $allow_retry, $max_body_bytes );
			}
			$this->sites[] = $call_name . '@' . $this->request_site_id();
			$this->calls[] = array( $call_name, $xml_body );
			return $answer instanceof Closure ? $answer( $this ) : $answer;
		}
		return parent::trading_api_request( $call_name, $xml_body, $allow_retry, $max_body_bytes );
	}
	/** A request sent again after a timeout, as request_with_retry() counts one. */
	public function resent() {
		$this->resends++;
	}
}
$GLOBALS['API'] = new Opt_Queue_API();
$tcg_queue_slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_queue_slot->setAccessible( true );
}
$tcg_queue_slot->setValue( null, $GLOBALS['API'] );

// What the eBay tab draws with, played.
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) { return json_encode( $data ); }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) { return 'nonce'; }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $n, $domain = null ) { return 1 === (int) $n ? $single : $plural; }
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}

/** A variable product with its variations. */
class Opt_Variable extends WC_Product_Variable {
	public $children = array();
	public function get_children() { return $this->children; }
}
/** A variation, by its size. */
class Opt_Variation extends WC_Product_Variation {
	public function get_variation_attributes() { return array( 'attribute_size' => $this->data['size'] ); }
}

$API = $GLOBALS['API'];

// WooCommerce Brands, played (fix round 3): its taxonomy, while 'here', each
// brand by term ID with its parent, and each product's brands.
$GLOBALS['BRANDS'] = array( 'here' => false, 'terms' => array(), 'of' => array() );
function taxonomy_exists( $taxonomy ) {
	return 'product_brand' === $taxonomy && ! empty( $GLOBALS['BRANDS']['here'] );
}
function get_the_terms( $post_id, $taxonomy ) {
	if ( 'product_brand' !== $taxonomy || empty( $GLOBALS['BRANDS']['of'][ (int) $post_id ] ) ) {
		return false;
	}
	$out = array();
	foreach ( $GLOBALS['BRANDS']['of'][ (int) $post_id ] as $id ) {
		$out[] = $GLOBALS['BRANDS']['terms'][ $id ];
	}
	return $out;
}
function get_term( $id, $taxonomy = '' ) {
	return $GLOBALS['BRANDS']['terms'][ (int) $id ] ?? null;
}
/** A brand, as WordPress keeps its name ("&" as "&amp;"). */
function brand_term( $id, $name, $parent = 0 ) {
	$GLOBALS['BRANDS']['terms'][ $id ] = (object) array( 'term_id' => $id, 'name' => $name, 'parent' => $parent, 'taxonomy' => 'product_brand' );
}
/** A product's brands, by term ID. */
function branded( $product_id, array $ids ) {
	$GLOBALS['BRANDS']['of'][ $product_id ] = $ids;
}

/** A shop on a marketplace, its default category, format and these settings. */
function shop( $category = '31387', $format = 'FixedPriceItem', array $more = array() ) {
	TCGiant_Sync_OAuth::$settings = $more + array(
		'marketplace'               => 'EBAY_US',
		'export_category_id'        => $category,
		'export_category_typed'     => $category,
		'export_condition_id'       => '3000',
		'export_location'           => 'Leeds, AL',
		'export_postal_code'        => '35094',
		'export_fulfillment_policy' => '250000021',
		'export_return_policy'      => '250000022',
		'export_payment_policy'     => '250000023',
		'export_listing_type'       => $format,
		'export_listing_duration'   => 'Chinese' === $format ? 'Days_7' : 'GTC',
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
	$api->aspects = array( array( 'name' => 'Brand', 'required' => false ), array( 'name' => 'MPN', 'required' => false ) );
	$api->nodes   = array( '33564' => motors_node( '33564', 'Brake Calipers', '33559' ) );
	$api->suggest = array( 'Brake Calipers' => array( array( 'id' => '33564', 'name' => 'eBay Motors > Parts & Accessories > Car & Truck Parts & Accessories > Brakes & Brake Parts > Brake Calipers', 'ancestors' => array( '33559', '6030', '6028', '6000' ) ) ) );
	$api->conds   = array( '33564' => motors_conditions( '33564', array( '1000' => 'New', '3000' => 'Used' ) ) );
	$GLOBALS['W']['transients'] = array();
	$GLOBALS['W']['log']        = array();
	delete_option( TCGiant_Sync_Motors::OPTION );
}
/** A product with a photo and these meta and attributes. */
function item( $id, array $meta = array(), array $data = array(), $class = 'WC_Product' ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/item-' . $id . '.jpg';
	$product = new $class( $id, array_merge( array( 'name' => 'Item ' . $id, 'description' => 'An item.', 'regular_price' => '85.00', 'stock' => 3, 'sku' => 'IT-' . $id, 'image_id' => 9000 + $id, 'attributes' => array( new WC_Product_Attribute( 'Colour', array( 'Red' ) ) ) ), $data ) );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
/** The listing options' meta, by field. */
function opts( array $fields ) {
	$out = array();
	foreach ( $fields as $field => $value ) {
		$out[ TCGiant_Sync_Listing_Options::METAS[ $field ] ] = $value;
	}
	return $out;
}
/** A push (do_push()): its answer, each listing call as Call@site, and the calls with their bodies. */
function push( WC_Product $product ) {
	$GLOBALS['API']->clear();
	$GLOBALS['API']->lookups = array();
	$res = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	return array( $res, $GLOBALS['API']->sites, $GLOBALS['API']->calls );
}
/** The whole push, as the queue runs it (process_single_push()): the calls, with what is recorded after. */
function push_whole( $product_id ) {
	$GLOBALS['API']->clear();
	exporter()->process_single_push( $product_id );
	return array( $GLOBALS['API']->sites, $GLOBALS['API']->calls );
}
/** Verify's item. */
function verify_item( WC_Product $product ) {
	$exporter = exporter();
	$settings = $exporter->settle_motors( $exporter->get_export_settings( $product->get_id() ), true );
	return $exporter->build_item_xml_public( $product, $settings );
}
/** What the exporter would record of the last build's listing options. */
function last_state() {
	$p = new ReflectionProperty( 'TCGiant_Sync_Exporter', 'options_sent' );
	if ( PHP_VERSION_ID < 80100 ) {
		$p->setAccessible( true );
	}
	return $p->getValue( exporter() );
}
/** An ended listing as GetItem answers. */
function ended_in( $item_id, $category, array $more = array() ) {
	return array( 'Ack' => 'Success', 'Item' => array_merge( array( 'ItemID' => $item_id, 'ListingType' => 'FixedPriceItem', 'Quantity' => '3', 'ConditionID' => '3000', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US', 'PrimaryCategory' => array( 'CategoryID' => $category ) ), $more ) );
}
/** A relist (relist_ended_listing()) from this source: its answer, its calls as Call@site, and their bodies. */
function relist_it( $id, array $answer, $source = 'Bulk relist' ) {
	$GLOBALS['API']->clear();
	$GLOBALS['API']->answers['GetItem'] = $answer;
	$res = exporter()->relist_ended_listing( $id, $source );
	unset( $GLOBALS['API']->answers['GetItem'] );
	return array( $res, $GLOBALS['API']->sites, $GLOBALS['API']->calls );
}
function code_of( $res ) {
	return is_wp_error( $res ) ? $res->get_error_code() : ( $res['action'] ?? ( $res['item_id'] ?? null ) );
}
function message_of( $res ) {
	return is_wp_error( $res ) ? $res->get_error_message() : '';
}
/** An element's text in a body, or null. */
function el( $body, $name ) {
	return preg_match( '#<' . $name . '>(.*?)</' . $name . '>#s', (string) $body, $m ) ? $m[1] : null;
}
/** The DeletedField values of a body, and whether all of them come after its Item. */
function deleted_of( $body ) {
	preg_match_all( '#<DeletedField>([^<]*)</DeletedField>#', (string) $body, $m );
	$after = ! $m[1] || strpos( (string) $body, '<DeletedField>' ) > strrpos( (string) $body, '</Item>' );
	return array( $m[1], $after );
}
/** The item specifics of a body, in order: name => value. */
function specifics_of( $body ) {
	if ( ! preg_match( '#<ItemSpecifics>(.*?)</ItemSpecifics>#s', (string) $body, $box ) ) {
		return null;
	}
	preg_match_all( '#<Name>([^<]*)</Name>\s*<Value>([^<]*)</Value>#', $box[1], $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $pair ) {
		$out[] = $pair[1] . '=' . $pair[2];
	}
	return $out;
}
/** Whether a body carries any of the listing options' elements. */
function carries_any( $body ) {
	return 1 === preg_match( '#<(SubTitle|ConditionDescription|SecondaryCategory|BuyItNowPrice|ReservePrice|DeletedField)>#', (string) $body );
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
function record_of( $id ) {
	return get_post_meta( $id, TCGiant_Sync_Listing_Options::SENT_META, true );
}

$FIXED = opts( array( 'subtitle' => 'Mint, boxed, first edition', 'condition_note' => 'A small scuff on the box.', 'second_category' => '261328', 'brand' => 'Zeta', 'mpn' => 'ZT-100' ) );
$PUSHED  = array( '_ebay_site' => 'US', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-10-31 10:00:00', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' );
$IMPORTED = array( '_ebay_site' => 'US', '_ebay_origin' => 'import', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEVERY FIELD EMPTY: THE REQUESTS AS 3.37.0 SENT THEM\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
$empty_bodies = array();
$ws_bodies    = array();
foreach ( array( 'new' => array(), 'pushed' => $PUSHED + array( '_ebay_item_id' => '110000000201' ), 'imported' => $IMPORTED + array( '_ebay_item_id' => '110000000202', '_ebay_live' => array( 'item_id' => '110000000202', 'category_id' => '31387', 'condition_id' => '3000' ) ) ) as $case => $meta ) {
	list( $res, , $calls ) = push( item( 210, $meta ) );
	$empty_bodies[ $case ] = no_uuid( $calls[0][1] ?? '' );
	check( $case . ': nothing of the listing options, no DeletedField, nothing to record', array( code_of( $res ), carries_any( $calls[0][1] ?? '' ), last_state() ), array( 'pushed' === $case || 'imported' === $case ? 'updated' : 'created', false, null ) );
	// The same, every field holding only spaces: nothing eBay could take.
	list( , , $calls ) = push( item( 210, $meta + opts( array( 'subtitle' => '   ', 'condition_note' => " \n ", 'second_category' => ' ', 'buy_it_now' => ' ', 'reserve' => '', 'brand' => '  ', 'mpn' => ' ' ) ) ) );
	$ws_bodies[ $case ] = no_uuid( $calls[0][1] ?? '' );
}
check( '  every field holding only spaces: the same bodies, byte for byte', $ws_bodies, $empty_bodies );
shop( '31387', 'Chinese' );
list( $res, $sites, $calls ) = push( item( 211 ) );
check( 'a new auction with none: AddItem, nothing of them', array( code_of( $res ), $sites, carries_any( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddItem@0' ), false ) );
shop();
check( 'Verify of a product with none: nothing of them', carries_any( verify_item( item( 212 ) ) ), false );
$plan = TCGiant_Sync_Listing_Options::plan( item( 213 ), array( 'mode' => 'revise', 'item_id' => '110000000213' ) );
check( 'the plan for a product with none: no XML, no DeletedField, nothing taken off or recorded', $plan, array( 'xml' => '', 'deleted' => array(), 'off' => array(), 'state' => null, 'refused' => '', 'notes' => array() ) );
$plan = TCGiant_Sync_Listing_Options::plan( item( 213, array( '_ebay_export_buy_it_now' => '0', '_ebay_export_second_category' => 'abc', '_ebay_export_reserve' => '-5' ) ), array( 'mode' => 'new', 'auction' => true, 'start_price' => '1.00' ) );
check( '  nor for one holding nothing eBay could take (a price of 0 or below, a category that is no ID)', array( $plan['xml'], $plan['state'] ), array( '', null ) );
check( 'an empty product\'s item specifics: the specifics as they were', TCGiant_Sync_Listing_Options::with_specifics( array( 'Brand' => 'Acme', 'Colour' => 'Red' ), item( 214 ) ), array( 'Brand' => 'Acme', 'Colour' => 'Red' ) );
list( , , $calls ) = push( item( 215, array(), array( 'attributes' => array( new WC_Product_Attribute( 'Brand', array( 'Acme' ) ) ) ) ) );
check( 'a product with a Brand attribute and an empty Brand field: its Brand as 3.37.0 sent it', specifics_of( $calls[0][1] ?? '' ), array( 'Brand=Acme' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA NEW LISTING AND VERIFY: EVERY FILLED FIELD\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
list( $res, $sites, $calls ) = push( item( 301, $FIXED + opts( array( 'buy_it_now' => '150', 'reserve' => '120' ) ) ) );
$body = (string) ( $calls[0][1] ?? '' );
check( 'a fixed-price product: listed, with its subtitle, condition note and second category', array( code_of( $res ), $sites, el( $body, 'SubTitle' ), el( $body, 'ConditionDescription' ), el( $body, 'SecondaryCategory' ) ), array( 'created', array( 'AddFixedPriceItem@0' ), 'Mint, boxed, first edition', 'A small scuff on the box.', '<CategoryID>261328</CategoryID>' ) );
check( '  never its Buy It Now or reserve: they are an auction\'s', array( el( $body, 'BuyItNowPrice' ), el( $body, 'ReservePrice' ) ), array( null, null ) );
check( '  Brand and MPN first in its item specifics, by the category\'s names', specifics_of( $body ), array( 'Brand=Zeta', 'MPN=ZT-100', 'Colour=Red' ) );
check( '  after the condition, before the item specifics', strpos( $body, '<ConditionID>' ) < strpos( $body, '<SubTitle>' ) && strpos( $body, '<ReservePrice>' ) === false && strpos( $body, '<SecondaryCategory>' ) < strpos( $body, '<ItemSpecifics>' ), true );
check( '  said in the log: Buy It Now and reserve not sent with a fixed-price item', logged( 'Push: WC #301 - the Buy It Now price is for an auction, and this listing is fixed-price: not sent; the reserve price is for an auction' ), 'info' );
check( '  to record once eBay takes it: the three sent, and (fix round 4) the Brand and MPN it sent, by name', last_state(), array( 'subtitle' => 'Mint, boxed, first edition', 'condition_note' => 'A small scuff on the box.', 'second_category' => '261328', 'specifics' => array( 'Brand' => 'Zeta', 'MPN' => 'ZT-100' ) ) );
$vbody = verify_item( item( 302, $FIXED ) );
check( 'Verify sends the same', array( el( $vbody, 'SubTitle' ), el( $vbody, 'ConditionDescription' ), el( $vbody, 'SecondaryCategory' ), specifics_of( $vbody ) ), array( 'Mint, boxed, first edition', 'A small scuff on the box.', '<CategoryID>261328</CategoryID>', array( 'Brand=Zeta', 'MPN=ZT-100', 'Colour=Red' ) ) );

// eBay's lengths, and what goes as typed.
$long = str_repeat( 'abcdefghij', 6 );
list( , , $calls ) = push( item( 303, opts( array( 'subtitle' => $long, 'condition_note' => str_repeat( 'x', 1200 ), 'brand' => str_repeat( 'B', 80 ), 'mpn' => str_repeat( 'M', 70 ) ) ) ) );
$body = (string) ( $calls[0][1] ?? '' );
check( 'the lengths: a subtitle cut to 55, a condition note to 1,000, Brand and MPN to 65', array( strlen( (string) el( $body, 'SubTitle' ) ), strlen( (string) el( $body, 'ConditionDescription' ) ), specifics_of( $body ) ), array( 55, 1000, array( 'Brand=' . str_repeat( 'B', 65 ), 'MPN=' . str_repeat( 'M', 65 ), 'Colour=Red' ) ) );
check( '  the constants: eBay\'s 55 and 1,000', array( TCGiant_Sync_Listing_Options::SUBTITLE_MAX, TCGiant_Sync_Listing_Options::CONDITION_NOTE_MAX, TCGiant_Sync_Listing_Options::SPECIFIC_MAX ), array( 55, 1000, 65 ) );
list( , , $calls ) = push( item( 304, opts( array( 'subtitle' => 'Café & "friends" <3', 'condition_note' => "Line one.\r\nLine two." ) ) ) );
check( '  escaped for XML; a condition note keeps its lines', array( el( $calls[0][1] ?? '', 'SubTitle' ), el( $calls[0][1] ?? '', 'ConditionDescription' ) ), array( 'Café &amp; &quot;friends&quot; &lt;3', "Line one.\nLine two." ) );
check( '  a multibyte subtitle cut by characters, not bytes', TCGiant_Sync_Listing_Options::clean( 'subtitle', str_repeat( 'é', 60 ) ), str_repeat( 'é', 55 ) );

// Where eBay ignores the condition note: not sent.
shop( '31387', 'FixedPriceItem', array( 'export_condition_id' => '1000' ) );
list( , , $calls ) = push( item( 311, $FIXED ) );
check( 'a New condition (1000): no condition note - eBay ignores it - the rest as filled', array( el( $calls[0][1] ?? '', 'ConditionDescription' ), el( $calls[0][1] ?? '', 'SubTitle' ) ), array( null, 'Mint, boxed, first edition' ) );
check( '  said', logged( 'the condition note is not sent with a New condition' ), 'info' );
shop( '31387', 'FixedPriceItem', array( 'export_condition_id' => '1500' ) );
list( , , $calls ) = push( item( 312, $FIXED ) );
check( '  New other (1500) takes it', el( $calls[0][1] ?? '', 'ConditionDescription' ), 'A small scuff on the box.' );
shop();
$card = item( 313, $FIXED + array( '_ebay_export_category_id' => '183454', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '10', '_ebay_export_item_type' => 'tcg' ) );
list( $res, , $calls ) = push( $card );
check( 'a graded card sent with condition descriptors: no condition note, eBay drops it there', array( code_of( $res ), false !== strpos( $calls[0][1] ?? '', '<ConditionDescriptors>' ), el( $calls[0][1] ?? '', 'ConditionDescription' ) ), array( 'created', true, null ) );
check( '  said', logged( 'the condition note is not sent with condition descriptors' ), 'info' );
list( , , $calls ) = push( item( 314, opts( array( 'second_category' => '31387' ) ) + $FIXED ) );
check( 'a second category that is the primary: not sent, and said', array( el( $calls[0][1] ?? '', 'SecondaryCategory' ), logged( 'the second category is not sent: it is the listing\'s primary category' ) ), array( null, 'info' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAN AUCTION: BUY IT NOW AND RESERVE\n" . str_repeat( '=', 124 ) . "\n";

shop( '31387', 'Chinese' );
fresh_ebay();
$AUCTION = $FIXED + opts( array( 'buy_it_now' => '149.5', 'reserve' => '120' ) );
list( $res, $sites, $calls ) = push( item( 401, $AUCTION ) );
$body = (string) ( $calls[0][1] ?? '' );
check( 'a new auction: AddItem with its Buy It Now and reserve, as eBay takes a price', array( code_of( $res ), $sites, el( $body, 'StartPrice' ), el( $body, 'BuyItNowPrice' ), el( $body, 'ReservePrice' ), el( $body, 'SubTitle' ) ), array( 'created', array( 'AddItem@0' ), '85.00', '149.50', '120.00', 'Mint, boxed, first edition' ) );
check( '  to record: all five, and the Brand and MPN sent', last_state(), array( 'subtitle' => 'Mint, boxed, first edition', 'condition_note' => 'A small scuff on the box.', 'second_category' => '261328', 'buy_it_now' => '149.50', 'reserve' => '120.00', 'specifics' => array( 'Brand' => 'Zeta', 'MPN' => 'ZT-100' ) ) );
check( 'Verify of it sends the same', array( el( verify_item( item( 402, $AUCTION ) ), 'BuyItNowPrice' ), el( verify_item( item( 402, $AUCTION ) ), 'ReservePrice' ) ), array( '149.50', '120.00' ) );
item( 403, opts( array( 'buy_it_now' => '85' ) ) );
list( $res, $sites ) = push( wc_get_product( 403 ) );
check( 'a Buy It Now not above the opening bid: refused before eBay, nothing sent', array( code_of( $res ), $sites ), array( 'missing_export_settings', array() ) );
check( '  in its own sentence (fix round 1): nothing is missing', message_of( $res ), 'The Buy It Now price 85.00 is not above the opening bid 85.00. eBay takes a Buy It Now price only above the opening bid and the reserve price, and a reserve price only above the opening bid: change it on the product\'s eBay tab (More listing options), or empty it, then push again. Nothing was sent to eBay.' );
item( 404, opts( array( 'reserve' => '50' ) ) );
list( $res, $sites ) = push( wc_get_product( 404 ) );
check( 'a reserve below it: refused, and said', array( code_of( $res ), $sites, 0 === strpos( message_of( $res ), 'The reserve price 50.00 is not above the opening bid 85.00. ' ) ), array( 'missing_export_settings', array(), true ) );
$x = exporter();
$x->build_item_xml_public( item( 405, opts( array( 'reserve' => '85.00' ) ) ), $x->get_export_settings( 405 ) );
check( '  and Verify refuses it, as the push does (description_too_long())', is_wp_error( $x->description_too_long() ) ? $x->description_too_long()->get_error_code() : null, 'missing_export_settings' );
$x->build_item_xml_public( item( 406, opts( array( 'reserve' => '85.01' ) ) ), $x->get_export_settings( 406 ) );
check( '  a cent above it goes', $x->description_too_long(), null );
// The product's own format decides, not the shop's.
shop();
list( $res, $sites, $calls ) = push( item( 407, $AUCTION + array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) ) );
check( 'a product set to Auction in a fixed-price shop: its Buy It Now and reserve go', array( $sites, el( $calls[0][1] ?? '', 'BuyItNowPrice' ), el( $calls[0][1] ?? '', 'ReservePrice' ) ), array( array( 'AddItem@0' ), '149.50', '120.00' ) );
shop( '31387', 'Chinese' );
list( $res, $sites, $calls ) = push( item( 408, $AUCTION + array( '_ebay_export_listing_type' => 'FixedPriceItem' ) ) );
check( '  one set to Fixed Price in an auction shop: neither', array( $sites, el( $calls[0][1] ?? '', 'BuyItNowPrice' ), el( $calls[0][1] ?? '', 'ReservePrice' ) ), array( array( 'AddFixedPriceItem@0' ), null, null ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAN UPDATE: EACH FILLED FIELD, NONE TO A LIVE AUCTION\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
list( $res, $sites, $calls ) = push( item( 501, $FIXED + $PUSHED + array( '_ebay_item_id' => '110000000501' ) + opts( array( 'buy_it_now' => '150' ) ) ) );
$body = (string) ( $calls[0][1] ?? '' );
check( 'a pushed fixed-price listing\'s Update: its subtitle, condition note, second category, Brand and MPN', array( code_of( $res ), $sites, el( $body, 'SubTitle' ), el( $body, 'ConditionDescription' ), el( $body, 'SecondaryCategory' ), specifics_of( $body ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), 'Mint, boxed, first edition', 'A small scuff on the box.', '<CategoryID>261328</CategoryID>', array( 'Brand=Zeta', 'MPN=ZT-100', 'Colour=Red' ) ) );
check( '  no Buy It Now, no DeletedField', array( el( $body, 'BuyItNowPrice' ), deleted_of( $body ) ), array( null, array( array(), true ) ) );
$live = array( 'item_id' => '110000000502', 'category_id' => '31387', 'condition_id' => '3000' );
list( $res, $sites, $calls ) = push( item( 502, $FIXED + $IMPORTED + array( '_ebay_item_id' => '110000000502', '_ebay_live' => $live ) ) );
$body = (string) ( $calls[0][1] ?? '' );
check( 'an imported listing\'s Update: the product\'s own fields go, as an override does', array( code_of( $res ), el( $body, 'SubTitle' ), el( $body, 'ConditionDescription' ), el( $body, 'SecondaryCategory' ) ), array( 'updated', 'Mint, boxed, first edition', 'A small scuff on the box.', '<CategoryID>261328</CategoryID>' ) );
list( , , $calls ) = push( item( 503, $FIXED + $IMPORTED + array( '_ebay_item_id' => '110000000503', '_ebay_live' => array( 'condition_id' => '1000', 'item_id' => '110000000503' ) + $live ) ) );
check( '  one whose condition on eBay is New, by the last read: no condition note', array( el( $calls[0][1] ?? '', 'ConditionDescription' ), el( $calls[0][1] ?? '', 'SubTitle' ) ), array( null, 'Mint, boxed, first edition' ) );

// A live auction: none of them, nor anything taken off.
shop( '31387', 'Chinese' );
$pushed_auction = array( '_ebay_item_id' => '110000000511', '_ebay_listing_type' => 'Chinese' ) + $PUSHED;
update_post_meta( 511, TCGiant_Sync_Listing_Options::SENT_META, array( 'item' => '110000000511', 'subtitle' => 'Old subtitle', 'condition_note' => 'Old note', 'second_category' => '261328', 'buy_it_now' => '149.50' ) );
list( $res, $sites, $calls ) = push( item( 511, $AUCTION + $pushed_auction + array( TCGiant_Sync_Listing_Options::SENT_META => get_post_meta( 511, TCGiant_Sync_Listing_Options::SENT_META, true ) ) ) );
$body = (string) ( $calls[0][1] ?? '' );
check( 'a pushed live auction\'s Update: ReviseItem with none of them (the 3.28.8 rule for its opening bid), the opening bid as 3.37.0 sent it', array( code_of( $res ), $sites, carries_any( $body ), el( $body, 'StartPrice' ) ), array( 'updated', array( 'ReviseItem@0' ), false, '85.00' ) );
check( '  its record kept as it was', last_state(), array( 'subtitle' => 'Old subtitle', 'condition_note' => 'Old note', 'second_category' => '261328', 'buy_it_now' => '149.50' ) );
check( '  said', logged( 'Push: WC #511 - the subtitle is not sent to a live auction, which eBay does not let change once it has a bid or is within 12 hours of its end' ), 'info' );
check( '  its item specifics exactly as 3.37.0 sent them: no Brand or MPN either (fix round 2, the review\'s probe P1)', specifics_of( $body ), array( 'Colour=Red' ) );
update_post_meta( 512, TCGiant_Sync_Listing_Options::SENT_META, array( 'item' => '110000000512', 'subtitle' => 'Old subtitle' ) );
list( $res, , $calls ) = push( item( 512, array( '_ebay_item_id' => '110000000512', '_ebay_listing_type' => 'Chinese', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000000512', 'subtitle' => 'Old subtitle' ) ) + $PUSHED ) );
check( '  one whose subtitle was emptied: no DeletedField either, and said it stays', array( code_of( $res ), carries_any( $calls[0][1] ?? '' ), logged( 'Push: WC #512 - the subtitle, emptied here, stays on the live auction: a relist takes it off.' ) ), array( 'updated', false, 'info' ) );
shop();
list( $res, $sites, $calls ) = push( item( 514, $FIXED + $pushed_auction + array( '_ebay_item_id' => '110000000514' ) ) );
check( 'a pushed auction whose shop now defaults to Fixed Price: still a live auction by its recorded format, sent none of them', array( code_of( $res ), carries_any( $calls[0][1] ?? '' ) ), array( 'updated', false ) );
list( $res, $sites, $calls ) = push( item( 513, $AUCTION + array( '_ebay_item_id' => '110000000513', '_ebay_listing_type' => 'Chinese', '_ebay_live' => array( 'item_id' => '110000000513', 'category_id' => '31387', 'condition_id' => '3000' ) ) + $IMPORTED ) );
check( 'an imported live auction\'s Update: none of them', array( code_of( $res ), $sites, carries_any( $calls[0][1] ?? '' ) ), array( 'updated', array( 'ReviseItem@0' ), false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nBRAND AND MPN: THE CATEGORY'S NAMES, NO NAME TWICE, NEVER A PARTIAL SET\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
$attrs = array( new WC_Product_Attribute( 'brand', array( 'Acme' ) ), new WC_Product_Attribute( 'M.P.N', array( 'AC-1' ) ), new WC_Product_Attribute( 'Colour', array( 'Red' ) ) );
list( , , $calls ) = push( item( 601, opts( array( 'brand' => 'Zeta', 'mpn' => 'ZT-100' ) ), array( 'attributes' => $attrs ) ) );
check( 'an attribute "brand" and one "M.P.N": the fields win, each name once (case and punctuation aside)', specifics_of( $calls[0][1] ?? '' ), array( 'Brand=Zeta', 'MPN=ZT-100', 'Colour=Red' ) );
list( , , $calls ) = push( item( 602, opts( array( 'mpn' => 'ZT-100' ) ), array( 'attributes' => $attrs ) ) );
check( '  MPN filled alone: the Brand attribute goes as it is', specifics_of( $calls[0][1] ?? '' ), array( 'MPN=ZT-100', 'Brand=Acme', 'Colour=Red' ) );
update_post_meta( 603, '_ebay_export_specifics', array( 'BRAND' => 'Typed' ) );
list( , , $calls ) = push( item( 603, opts( array( 'brand' => 'Zeta' ) ) + array( '_ebay_export_specifics' => array( 'BRAND' => 'Typed' ) ) ) );
check( '  one typed as an item specific on the tab: the field wins there too', specifics_of( $calls[0][1] ?? '' ), array( 'Brand=Zeta', 'Colour=Red' ) );
$API->aspects = array( array( 'name' => 'brand', 'required' => false ), array( 'name' => 'Mpn', 'required' => false ) );
list( , , $calls ) = push( item( 604, opts( array( 'brand' => 'Zeta', 'mpn' => 'ZT-100' ) ) ) );
check( 'named as the category\'s aspects name them', specifics_of( $calls[0][1] ?? '' ), array( 'brand=Zeta', 'Mpn=ZT-100', 'Colour=Red' ) );
$API->aspects = array( array( 'name' => 'Brand', 'required' => true ) );
list( $res, $sites ) = push( item( 605, array(), array( 'attributes' => array() ) ) );
check( 'a category requiring Brand, a product with none: refused for it, as before', array( code_of( $res ), $sites ), array( 'missing_required_aspects', array() ) );
list( $res, , $calls ) = push( item( 606, opts( array( 'brand' => 'Zeta' ) ), array( 'attributes' => array() ) ) );
check( '  with the Brand field filled: listed, the requirement met', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ) ), array( 'created', array( 'Brand=Zeta' ) ) );
$API->aspects = array( array( 'name' => 'Brand', 'required' => false ), array( 'name' => 'MPN', 'required' => false ) );

// An imported listing whose Update sends no item specifics: neither.
$no_attrs = array( 'attributes' => array() );
$live     = array( 'item_id' => '110000000611', 'category_id' => '31387', 'condition_id' => '3000' );
list( $res, , $calls ) = push( item( 611, opts( array( 'brand' => 'Zeta', 'mpn' => 'ZT-100', 'subtitle' => 'Sub' ) ) + $IMPORTED + array( '_ebay_item_id' => '110000000611', '_ebay_live' => $live ), $no_attrs ) );
check( 'an imported listing with no item specifics of its own: its Update sends no ItemSpecifics - Brand and MPN alone would wipe eBay\'s', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ), el( $calls[0][1] ?? '', 'SubTitle' ) ), array( 'updated', null, 'Sub' ) );
check( '  said', logged( 'Push: WC #611 - its Brand and MPN were not sent: this Update sends no other item specifics, and eBay replaces all of a listing\'s item specifics with those sent' ), 'info' );
list( $res, , $calls ) = push( item( 615, $IMPORTED + array( '_ebay_item_id' => '110000000615', '_ebay_live' => array( 'item_id' => '110000000615' ) + $live ), $no_attrs ) );
check( '  one with neither filled: no ItemSpecifics, as before, and nothing said of them', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ), logged( 'Push: WC #615 - its Brand and MPN' ) ), array( 'updated', null, null ) );
list( , , $calls ) = push( item( 612, opts( array( 'brand' => 'Zeta', 'mpn' => 'ZT-100' ) ) + $IMPORTED + array( '_ebay_item_id' => '110000000612', '_ebay_live' => array( 'item_id' => '110000000612' ) + $live ) ) );
check( '  one whose Update sends its item specifics: the two among them, the whole set', specifics_of( $calls[0][1] ?? '' ), array( 'Brand=Zeta', 'MPN=ZT-100', 'Colour=Red' ) );
list( , , $calls ) = push( item( 613, opts( array( 'brand' => 'Zeta' ) ) + $PUSHED + array( '_ebay_item_id' => '110000000613' ), $no_attrs ) );
check( 'a pushed listing with no other item specifics (fix round 1): neither - eBay\'s catalogue or Seller Hub may have given it some since', array( specifics_of( $calls[0][1] ?? '' ), logged( 'Push: WC #613 - its Brand and MPN were not sent: this Update sends no other item specifics' ) ), array( null, 'info' ) );
list( , , $calls ) = push( item( 614, opts( array( 'brand' => 'Zeta' ) ) + $IMPORTED + array( '_ebay_item_id' => '110000000614', '_ebay_live' => array( 'item_id' => '110000000614' ) + $live, TCGiant_Sync_Ebay_Live::FOLLOW_META => '1' ), $no_attrs ) );
check( '  nor an imported one set to send the shop\'s defaults (the review\'s probe D): never a partial set', array( specifics_of( $calls[0][1] ?? '' ), logged( 'Push: WC #614 - its Brand and MPN were not sent' ) ), array( null, 'info' ) );
check( 'specifics_go(): an Update needs another item specific with a value; a new listing does not', array( TCGiant_Sync_Listing_Options::specifics_go( true, array() ), TCGiant_Sync_Listing_Options::specifics_go( true, array( 'Colour' => ' ' ) ), TCGiant_Sync_Listing_Options::specifics_go( true, array( 'Colour' => 'Red' ) ), TCGiant_Sync_Listing_Options::specifics_go( false, array() ) ), array( false, false, true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAN EMPTIED FIELD: TAKEN OFF ONLY WHEN THIS PLUGIN SENT IT\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
item( 701, $FIXED + opts( array( 'buy_it_now' => '150' ) ) );
list( $sites ) = push_whole( 701 );
check( 'a whole push (process_single_push()): listed, and what was sent recorded against the listing', array( $sites, record_of( 701 ) ), array( array( 'AddFixedPriceItem@0' ), array( 'item' => '110000000001', 'subtitle' => 'Mint, boxed, first edition', 'condition_note' => 'A small scuff on the box.', 'second_category' => '261328', 'specifics' => array( 'Brand' => 'Zeta', 'MPN' => 'ZT-100' ) ) ) );
foreach ( array( TCGiant_Sync_Listing_Options::SUBTITLE_META, TCGiant_Sync_Listing_Options::CONDITION_NOTE_META, TCGiant_Sync_Listing_Options::SECOND_CATEGORY_META ) as $key ) {
	delete_post_meta( 701, $key );
}
list( $sites, $calls ) = push_whole( 701 );
$body = (string) ( $calls[0][1] ?? '' );
check( 'the three emptied, Updated: DeletedField for the subtitle and condition note, after the Item', array( $sites, deleted_of( $body ) ), array( array( 'ReviseFixedPriceItem@0' ), array( array( 'Item.SubTitle', 'Item.ConditionDescription' ), true ) ) );
check( '  the second category by CategoryID 0, eBay\'s way on a revise', el( $body, 'SecondaryCategory' ), '<CategoryID>0</CategoryID>' );
$tail = "</Item>\n<DeletedField>Item.SubTitle</DeletedField>\n<DeletedField>Item.ConditionDescription</DeletedField>";
check( '  the body\'s end, byte for byte', substr( $body, -strlen( $tail ) ), $tail );
check( '  the record emptied of them once eBay took it (fix round 4: the Brand and MPN the Update sent kept)', record_of( 701 ), array( 'item' => '110000000001', 'specifics' => array( 'Brand' => 'Zeta', 'MPN' => 'ZT-100' ) ) );
list( $sites, $calls ) = push_whole( 701 );
check( 'the next Update: nothing taken off again', carries_any( $calls[0][1] ?? '' ), false );

// eBay's own values are never taken off.
list( $res, , $calls ) = push( item( 711, $IMPORTED + array( '_ebay_item_id' => '110000000711', '_ebay_live' => array( 'item_id' => '110000000711', 'category_id' => '31387', 'condition_id' => '3000', 'condition_description' => 'eBay\'s own note.' ) ) ) );
check( 'an imported listing with eBay\'s own condition note, its field empty: nothing sent or taken off', array( code_of( $res ), carries_any( $calls[0][1] ?? '' ) ), array( 'updated', false ) );
update_post_meta( 712, TCGiant_Sync_Listing_Options::SENT_META, array( 'item' => '110000000999', 'subtitle' => 'Sent to another listing' ) );
list( $res, , $calls ) = push( item( 712, $PUSHED + array( '_ebay_item_id' => '110000000712', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000000999', 'subtitle' => 'Sent to another listing' ) ) ) );
check( 'a record of another listing: nothing taken off', array( code_of( $res ), carries_any( $calls[0][1] ?? '' ) ), array( 'updated', false ) );
list( $res, , $calls ) = push( item( 713, $PUSHED + array( '_ebay_item_id' => '110000000713', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000000713', 'buy_it_now' => '150.00', 'reserve' => '120.00' ) ) ) );
check( 'Buy It Now and reserve emptied: never taken off - an Update never reaches them', array( code_of( $res ), carries_any( $calls[0][1] ?? '' ), last_state() ), array( 'updated', false, array( 'buy_it_now' => '150.00', 'reserve' => '120.00' ) ) );
update_post_meta( 714, TCGiant_Sync_Listing_Options::SENT_META, array( 'item' => '110000000714', 'subtitle' => 'Old' ) );
list( , , $calls ) = push( item( 714, opts( array( 'subtitle' => 'New' ) ) + $PUSHED + array( '_ebay_item_id' => '110000000714', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000000714', 'subtitle' => 'Old' ) ) ) );
check( 'a changed subtitle: sent, nothing deleted', array( el( $calls[0][1] ?? '', 'SubTitle' ), deleted_of( $calls[0][1] ?? '' )[0], last_state() ), array( 'New', array(), array( 'subtitle' => 'New' ) ) );
item( 715, $PUSHED + array( '_ebay_item_id' => '110000000715', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000000715', 'subtitle' => 'Old' ) ) );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'Something went wrong.' );
push_whole( 715 );
unset( $API->answers['ReviseFixedPriceItem'] );
check( 'an Update eBay refused: the record stays, to be taken off next time', record_of( 715 ), array( 'item' => '110000000715', 'subtitle' => 'Old' ) );
// A new listing in place of an ended one starts its own record.
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = ended_in( '110000000721', '31387' );
item( 721, opts( array( 'subtitle' => 'Fresh' ) ) + $PUSHED + array( '_ebay_item_id' => '110000000721', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000000721', 'subtitle' => 'Old', 'condition_note' => 'Old note' ) ) );
list( $sites, $calls ) = push_whole( 721 );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
check( 'an ended listing: the Update, then a new listing with its fields, recorded against the new listing alone', array( $sites, el( $calls[2][1] ?? '', 'SubTitle' ), deleted_of( $calls[2][1] ?? '' )[0], record_of( 721 ) ), array( array( 'ReviseFixedPriceItem@0', 'GetItem@0', 'AddFixedPriceItem@0' ), 'Fresh', array(), array( 'item' => '110000000001', 'subtitle' => 'Fresh' ) ) );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = ended_in( '110000000724', '31387' );
item( 724, $PUSHED + array( '_ebay_item_id' => '110000000724', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000000724', 'subtitle' => 'Old' ) ) );
list( $sites, $calls ) = push_whole( 724 );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
check( '  and one with every field emptied: the Update takes the subtitle off, the new listing carries nothing, the old record written off', array( $sites, deleted_of( $calls[0][1] ?? '' )[0], carries_any( $calls[2][1] ?? '' ), record_of( 724 ) ), array( array( 'ReviseFixedPriceItem@0', 'GetItem@0', 'AddFixedPriceItem@0' ), array( 'Item.SubTitle' ), false, '' ) );
check( 'record(): null records nothing, an empty state takes it off', array( TCGiant_Sync_Listing_Options::record( 722, null, '1' ), get_post_meta( 722, TCGiant_Sync_Listing_Options::SENT_META, true ) ), array( null, '' ) );
update_post_meta( 723, TCGiant_Sync_Listing_Options::SENT_META, array( 'item' => '7', 'subtitle' => 'S' ) );
TCGiant_Sync_Listing_Options::record( 723, null, '8' );
$tcg_kept = get_post_meta( 723, TCGiant_Sync_Listing_Options::SENT_META, true );
TCGiant_Sync_Listing_Options::record( 723, array(), '8' );
check( '  an import\'s link (null) leaves a record as it is; an empty state takes it off', array( $tcg_kept, get_post_meta( 723, TCGiant_Sync_Listing_Options::SENT_META, true ) ), array( array( 'item' => '7', 'subtitle' => 'S' ), '' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA RELIST, AUTO-RELIST AND A PUSH'S RELIST: EVERY FILLED FIELD BUT BRAND AND MPN\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
$ended = $PUSHED + array( '_ebay_listing_status' => 'Ended' );
item( 801, $FIXED + $ended + array( '_ebay_item_id' => '110000000801' ) );
list( $res, $sites, $calls ) = relist_it( 801, ended_in( '110000000801', '31387' ) );
check( 'a fixed-price listing\'s Bulk Relist: RelistFixedPriceItem with its subtitle, condition note and second category, after the quantity', array( is_array( $res ) ? $res['item_id'] : code_of( $res ), $sites, preg_replace( '#<UUID>\w+</UUID>#', '<UUID/>', (string) ( $calls[1][1] ?? '' ) ) ), array( '110000000001', array( 'GetItem@0', 'RelistFixedPriceItem@0' ), "\n<Item>\n<ItemID>110000000801</ItemID>\n<UUID/>\n<Quantity>3</Quantity>\n<SubTitle>Mint, boxed, first edition</SubTitle>\n<ConditionDescription>A small scuff on the box.</ConditionDescription>\n<SecondaryCategory><CategoryID>261328</CategoryID></SecondaryCategory>\n</Item>" ) );
check( '  no item specifics: a relist keeps the listing\'s, and Brand and MPN alone would replace them', specifics_of( $calls[1][1] ?? '' ), null );
check( '  recorded against the new listing', record_of( 801 ), array( 'item' => '110000000001', 'subtitle' => 'Mint, boxed, first edition', 'condition_note' => 'A small scuff on the box.', 'second_category' => '261328' ) );
check( '  said in its line, by the tab\'s labels', logged( 'Bulk relist: WC #801 relisted on eBay as 110000000001 (was 110000000801), quantity 3, with its subtitle, condition note, second category.' ), 'success' );
item( 802, $ended + array( '_ebay_item_id' => '110000000802' ) );
list( $res, , $calls ) = relist_it( 802, ended_in( '110000000802', '31387' ), 'Auto-Relist' );
check( 'Auto-Relist of a product with none: its request as 3.37.0\'s', array( is_array( $res ) ? $res['item_id'] : code_of( $res ), preg_replace( '#<UUID>\w+</UUID>#', '<UUID/>', (string) ( $calls[1][1] ?? '' ) ), record_of( 802 ) ), array( '110000000001', "\n<Item>\n<ItemID>110000000802</ItemID>\n<UUID/>\n<Quantity>3</Quantity>\n</Item>", '' ) );
item( 803, opts( array( 'subtitle' => 'Kept' ) ) + $ended + array( '_ebay_item_id' => '110000000803', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000000803', 'subtitle' => 'Old', 'condition_note' => 'Old note', 'second_category' => '261328', 'buy_it_now' => '150.00' ) ) );
list( $res, , $calls ) = relist_it( 803, ended_in( '110000000803', '31387' ), 'Auto-Relist' );
check( 'Auto-Relist with the note and second category emptied: DeletedField for both after the Item, the subtitle sent', array( el( $calls[1][1] ?? '', 'SubTitle' ), deleted_of( $calls[1][1] ?? '' ) ), array( 'Kept', array( array( 'Item.ConditionDescription', 'Item.SecondaryCategory' ), true ) ) );
check( '  recorded: the subtitle, and Buy It Now as eBay keeps it', record_of( 803 ), array( 'item' => '110000000001', 'subtitle' => 'Kept', 'buy_it_now' => '150.00' ) );
item( 804, $FIXED + $ended + array( '_ebay_item_id' => '110000000804' ) );
list( $res, , $calls ) = relist_it( 804, ended_in( '110000000804', '31387', array( 'ConditionID' => '1000' ) ) );
check( 'a relist judged by eBay\'s answer: a New listing gets no condition note', array( el( $calls[1][1] ?? '', 'ConditionDescription' ), el( $calls[1][1] ?? '', 'SubTitle' ) ), array( null, 'Mint, boxed, first edition' ) );
item( 805, $FIXED + $ended + array( '_ebay_item_id' => '110000000805' ) );
list( , , $calls ) = relist_it( 805, ended_in( '110000000805', '261328' ) );
check( '  one in the second category as its primary: no second category', el( $calls[1][1] ?? '', 'SecondaryCategory' ), null );

// An auction's relist: Buy It Now and reserve, judged by its opening bid.
$ended_auction = array( '_ebay_listing_type' => 'Chinese' ) + $ended;
item( 811, $AUCTION + $ended_auction + array( '_ebay_item_id' => '110000000811' ) );
list( $res, $sites, $calls ) = relist_it( 811, ended_in( '110000000811', '31387', array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00', 'ReservePrice' => array( '@attributes' => array( 'currencyID' => 'USD' ), 0 => '120.0' ) ) ) );
$body = (string) ( $calls[1][1] ?? '' );
check( 'an auction\'s relist: RelistItem with its Buy It Now and reserve (at the ended listing\'s reserve, 120, as eBay\'s answer gives it)', array( $sites, el( $body, 'BuyItNowPrice' ), el( $body, 'ReservePrice' ), el( $body, 'SubTitle' ) ), array( array( 'GetItem@0', 'RelistItem@0' ), '149.50', '120.00', 'Mint, boxed, first edition' ) );
item( 812, $AUCTION + $ended_auction + array( '_ebay_item_id' => '110000000812' ) );
list( $res, $sites, $calls ) = relist_it( 812, ended_in( '110000000812', '31387', array( 'ListingType' => 'Chinese', 'StartPrice' => array( '@attributes' => array( 'currencyID' => 'USD' ), 0 => '130.0' ) ) ), 'Auto-Relist' );
check( '  its opening bid on eBay above the reserve: the reserve left out, said, and the relist goes (fix round 2: its Buy It Now 149.50, under 30% above 130.00, left out too)', array( code_of( $res ), el( $calls[1][1] ?? '', 'ReservePrice' ), el( $calls[1][1] ?? '', 'BuyItNowPrice' ), logged( 'Auto-Relist: WC #812 - the reserve price not sent: the reserve price 120.00 is not above the opening bid 130.00' ) ), array( '110000000001', null, null, 'info' ) );
item( 813, $AUCTION + $ended + array( '_ebay_item_id' => '110000000813' ) );
list( , $sites, $calls ) = relist_it( 813, ended_in( '110000000813', '31387' ) );
check( 'a fixed-price listing\'s relist: never Buy It Now or reserve', array( $sites, el( $calls[1][1] ?? '', 'BuyItNowPrice' ), el( $calls[1][1] ?? '', 'ReservePrice' ) ), array( array( 'GetItem@0', 'RelistFixedPriceItem@0' ), null, null ) );

// A push's relist of an imported auction: the push's changes, then its options.
shop( '31387', 'Chinese' );
$API->answers['ReviseItem'] = new WP_Error( 'api_error', 'Auction has already been closed.' );
$API->answers['GetItem']    = ended_in( '110000000821', '31387', array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00', 'ReservePrice' => '150.00' ) );
item( 821, $AUCTION + array( '_ebay_item_id' => '110000000821', '_ebay_listing_type' => 'Chinese', '_ebay_live' => array( 'item_id' => '110000000821', 'category_id' => '31387', 'condition_id' => '3000' ) ) + $IMPORTED );
list( $sites, $calls ) = push_whole( 821 );
unset( $API->answers['ReviseItem'], $API->answers['GetItem'] );
$body = (string) ( $calls[2][1] ?? '' );
check( 'a push that finds an imported auction ended: RelistItem with its title and description, then its options', array( $sites, el( $body, 'Title' ), el( $body, 'BuyItNowPrice' ), el( $body, 'SubTitle' ), strpos( $body, '</Description>' ) < strpos( $body, '<SubTitle>' ) ), array( array( 'ReviseItem@0', 'GetItem@0', 'RelistItem@0' ), 'Item 821', '149.50', 'Mint, boxed, first edition', true ) );
check( '  recorded once, against the relist', record_of( 821 ), array( 'item' => '110000000001', 'subtitle' => 'Mint, boxed, first edition', 'condition_note' => 'A small scuff on the box.', 'second_category' => '261328', 'buy_it_now' => '149.50', 'reserve' => '120.00' ) );
shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA VARIATION LISTING: THE ITEM'S FIELDS ONLY\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
$var = item( 900, $FIXED + opts( array( 'buy_it_now' => '150', 'reserve' => '120' ) ), array( 'type' => 'variable', 'sku' => 'VAR-900' ), 'Opt_Variable' );
$var->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
$var->children             = array( 9001, 9002 );
item( 9001, array( '_ebay_export_subtitle' => 'A variation\'s own' ), array( 'sku' => 'VAR-900-S', 'size' => 'Small', 'attributes' => array() ), 'Opt_Variation' );
item( 9002, array(), array( 'sku' => 'VAR-900-L', 'size' => 'Large', 'attributes' => array() ), 'Opt_Variation' );
list( $res, $sites, $calls ) = push( $var );
$body = (string) ( $calls[0][1] ?? '' );
$vars = substr( $body, (int) strpos( $body, '<Variations>' ) );
check( 'a variable product: the item\'s subtitle, condition note, second category, Brand and MPN, once, outside the variations', array( code_of( $res ), $sites, el( $body, 'SubTitle' ), substr_count( $body, '<SubTitle>' ), el( $body, 'SecondaryCategory' ), specifics_of( $body ), carries_any( $vars ) ), array( 'created', array( 'AddFixedPriceItem@0' ), 'Mint, boxed, first edition', 1, '<CategoryID>261328</CategoryID>', array( 'Brand=Zeta', 'MPN=ZT-100', 'Colour=Red' ), false ) );
check( '  no Buy It Now or reserve: a variation listing is fixed-price', array( el( $body, 'BuyItNowPrice' ), el( $body, 'ReservePrice' ) ), array( null, null ) );
shop( '31387', 'Chinese' );
list( $res, $sites, $calls ) = push( $var );
check( '  nor in a shop whose default format is Auction', array( $sites, el( $calls[0][1] ?? '', 'BuyItNowPrice' ), el( $calls[0][1] ?? '', 'ReservePrice' ), el( $calls[0][1] ?? '', 'SubTitle' ) ), array( array( 'AddFixedPriceItem@0' ), null, null, 'Mint, boxed, first edition' ) );
shop();
check( '  a variation\'s own meta is never read', false === strpos( $body, 'A variation\'s own' ), true );
ob_start();
TCGiant_Sync_Listing_Options::render_product_section( $var, 'Chinese' );
$html = (string) ob_get_clean();
check( '  its eBay tab: no Buy It Now or reserve fields at all', array( false !== strpos( $html, 'name="_ebay_export_buy_it_now"' ), false !== strpos( $html, 'name="_ebay_export_subtitle"' ) ), array( false, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEBAY MOTORS\n" . str_repeat( '=', 124 ) . "\n";

shop( '33564' );
fresh_ebay();
list( $res, $sites, $calls ) = push( item( 1001, $FIXED ) );
$body = (string) ( $calls[0][1] ?? '' );
check( 'a Parts & Accessories item on eBay Motors: listed there with its subtitle, note and second category', array( code_of( $res ), $sites, el( $body, 'SubTitle' ), el( $body, 'SecondaryCategory' ), el( $body, 'ConditionDescription' ) ), array( 'created', array( 'AddFixedPriceItem@100' ), 'Mint, boxed, first edition', '<CategoryID>261328</CategoryID>', 'A small scuff on the box.' ) );
check( '  Brand by eBay Motors\' own aspect, its requirement met', array_slice( (array) specifics_of( $body ), 0, 2 ), array( 'Brand=Zeta', 'MPN=ZT-100' ) );
$plan = TCGiant_Sync_Listing_Options::plan( item( 1002, $FIXED ), array( 'mode' => 'new', 'vehicle' => true, 'primary' => '6001', 'condition_id' => '3000' ) );
check( 'an eBay Motors vehicle: no second category ("You cannot list US eBay Motors vehicles in two categories")', array( false !== strpos( $plan['xml'], 'SecondaryCategory' ), $plan['notes'] ), array( false, array( 'second_category:vehicle' ) ) );
shop();
$exporter_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-exporter.php' );
check( '  the build and the relist say a vehicle by eBay Motors\' own branch', array( substr_count( $exporter_src, "'vehicle'      => TCGiant_Sync_Motors::BRANCH_VEHICLES === (string) ( \$settings['_listing_branch'] ?? '' )," ), substr_count( $exporter_src, "'vehicle'         => TCGiant_Sync_Motors::SITE === \$listed_on && ! \$motors_site," ) ), array( 1, 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE TEMPLATE RE-SEND SENDS THE DESCRIPTION ONLY, UNCHANGED\n" . str_repeat( '=', 124 ) . "\n";

$resend_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-template-resend.php' );
check( 'Re-send to eBay asks nothing of the listing options (the source)', false === strpos( $resend_src, 'Listing_Options' ), true );
$desc = exporter()->resend_description( item( 1101, $FIXED ), exporter()->get_export_settings( 1101 ) );
check( '  the description it sends is the push\'s, with no option in it', array( is_array( $desc ), false !== strpos( (string) ( $desc['html'] ?? '' ), 'Mint, boxed' ) ), array( true, false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PIECES\n" . str_repeat( '=', 124 ) . "\n";

check( 'clean(): prices as eBay takes one, a category its digits', array( TCGiant_Sync_Listing_Options::clean( 'buy_it_now', '12.5' ), TCGiant_Sync_Listing_Options::clean( 'reserve', '1,000' ), TCGiant_Sync_Listing_Options::clean( 'second_category', ' 0261328 ' ), TCGiant_Sync_Listing_Options::clean( 'second_category', '0' ), TCGiant_Sync_Listing_Options::clean( 'second_category', '12a' ) ), array( '12.50', '', '261328', '', '' ) );
check( 'same_name(): letters and digits, case aside', array( TCGiant_Sync_Listing_Options::same_name( 'M.P.N', 'MPN' ), TCGiant_Sync_Listing_Options::same_name( 'brand ', 'Brand' ), TCGiant_Sync_Listing_Options::same_name( 'Brand Name', 'Brand' ), TCGiant_Sync_Listing_Options::same_name( '', '' ) ), array( true, true, false, false ) );
check( 'deleted_xml(): each after the Item, on its line', TCGiant_Sync_Listing_Options::deleted_xml( array( 'Item.SubTitle', 'Item.ConditionDescription' ) ), "\n<DeletedField>Item.SubTitle</DeletedField>\n<DeletedField>Item.ConditionDescription</DeletedField>" );
$plan = TCGiant_Sync_Listing_Options::plan( item( 1201, opts( array( 'buy_it_now' => '150', 'reserve' => '120' ) ) ), array( 'mode' => 'revise', 'auction' => true, 'item_id' => '1' ) );
check( 'plan(): never Buy It Now or reserve in a revise, even were it not a live auction\'s', array( $plan['xml'], $plan['notes'] ), array( '', array( 'buy_it_now:live', 'reserve:live' ) ) );
$plan = TCGiant_Sync_Listing_Options::plan( item( 1202, opts( array( 'subtitle' => 'S' ) ) + array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '5', 'second_category' => '261328' ) ) ), array( 'mode' => 'relist', 'item_id' => '5' ) );
check( '  a relist takes the second category off by DeletedField', array( $plan['xml'], $plan['deleted'], $plan['state'] ), array( "<SubTitle>S</SubTitle>\n", array( 'Item.SecondaryCategory' ), array( 'subtitle' => 'S' ) ) );
check( '  a new listing reads no record: nothing deleted, whatever was sent before', TCGiant_Sync_Listing_Options::plan( item( 1203, array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '', 'subtitle' => 'S' ) ) ), array( 'mode' => 'new' ) )['deleted'], array() );
check( 'notes_said(): nothing worth saying, nothing said', TCGiant_Sync_Listing_Options::notes_said( 1, 'Push', array() ), '' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE FIELDS, THE EBAY TAB, VERIFY'S FEES AND UNINSTALL\n" . str_repeat( '=', 124 ) . "\n";

item( 1301 );
TCGiant_Sync_Listing_Options::save_posted( 1301, array(
	'_ebay_export_subtitle_shown'       => '1',
	'_ebay_export_subtitle'             => ' ' . str_repeat( 'S', 60 ) . ' ',
	'_ebay_export_condition_note'       => "One.\nTwo.",
	'_ebay_export_second_category'      => '261328',
	'_ebay_export_second_category_name' => 'Collectibles > Things',
	'_ebay_export_buy_it_now'           => '12.5',
	'_ebay_export_reserve'              => 'abc',
	'_ebay_export_brand'                => 'Zeta',
	'_ebay_export_mpn'                  => '',
) );
check( 'the eBay tab\'s save: each field as it would be sent; one holding nothing eBay could take not kept', array( get_post_meta( 1301, '_ebay_export_subtitle', true ), get_post_meta( 1301, '_ebay_export_condition_note', true ), get_post_meta( 1301, '_ebay_export_second_category', true ), get_post_meta( 1301, '_ebay_export_second_category_name', true ), get_post_meta( 1301, '_ebay_export_buy_it_now', true ), get_post_meta( 1301, '_ebay_export_reserve', true ), get_post_meta( 1301, '_ebay_export_brand', true ), get_post_meta( 1301, '_ebay_export_mpn', true ) ), array( str_repeat( 'S', 55 ), "One.\nTwo.", '261328', 'Collectibles > Things', '12.50', '', 'Zeta', '' ) );
TCGiant_Sync_Listing_Options::save_posted( 1301, array( '_ebay_export_subtitle' => '' ) );
check( '  a form without the section: nothing changed', get_post_meta( 1301, '_ebay_export_subtitle', true ), str_repeat( 'S', 55 ) );
TCGiant_Sync_Listing_Options::save_posted( 1301, array( '_ebay_export_subtitle_shown' => '1', '_ebay_export_subtitle' => '', '_ebay_export_second_category' => '' ) );
check( '  emptied: deleted, the category\'s name with it; a field not posted left', array( get_post_meta( 1301, '_ebay_export_subtitle', true ), get_post_meta( 1301, '_ebay_export_second_category_name', true ), get_post_meta( 1301, '_ebay_export_brand', true ) ), array( '', '', 'Zeta' ) );
check( '  never kept as \'\': the meta rows gone, and one eBay could not take (the reserve "abc") never written', array( array_key_exists( '_ebay_export_subtitle', $GLOBALS['W']['meta'][1301] ), array_key_exists( '_ebay_export_second_category', $GLOBALS['W']['meta'][1301] ), array_key_exists( '_ebay_export_reserve', $GLOBALS['W']['meta'][1301] ) ), array( false, false, false ) );
$GLOBALS['W']['caps']['edit_post'] = false;
$_POST = array( '_ebay_export_subtitle_shown' => '1', '_ebay_export_brand' => 'Other' );
TCGiant_Sync_Listing_Options::save_product_field( 1301 );
check( '  by someone who may not edit the product: nothing saved', get_post_meta( 1301, '_ebay_export_brand', true ), 'Zeta' );
unset( $GLOBALS['W']['caps']['edit_post'] );
$_POST = array();

ob_start();
TCGiant_Sync_Listing_Options::render_product_section( item( 1302, opts( array( 'subtitle' => 'Shown', 'buy_it_now' => '150.00' ) ) ), 'FixedPriceItem' );
$html = (string) ob_get_clean();
check( 'the eBay tab: the fields with their values, the marker, the auction box hidden for a fixed-price product', array( false !== strpos( $html, 'name="_ebay_export_subtitle" value="Shown" maxlength="55"' ), false !== strpos( $html, 'name="_ebay_export_subtitle_shown"' ), false !== strpos( $html, 'id="tc-auction-options" style="display:none;"' ), false !== strpos( $html, 'value="150.00"' ), substr_count( $html, 'class="tc-fee-line"' ) ), array( true, true, true, true, 4 ) );
$cats = array_filter( array_keys( TCGiant_Sync_Catalog::get_categories() ), function ( $id ) { return 1 === preg_match( '/^\d+$/', (string) $id ); } );
check( '  the second category chosen as the primary is: the same list (TCGiant_Sync_Catalog::get_categories()), and eBay\'s browser', array( count( $cats ) > 0, substr_count( $html, '<option value="' ) - 1 === count( $cats ), false !== strpos( $html, "action: 'tcgiant_browse_categories'" ) ), array( true, true, true ) );
check( '  the fee lines name no amount', 0 === preg_match( '#class="tc-fee-line"[^>]*>[^<]*[\$£€][0-9]#', $html ), true );
check( '  and the tab says empty keeps eBay\'s, and what an Update and a live auction do', array( false !== strpos( $html, 'Empty: the listing keeps what eBay has' ), false !== strpos( $html, 'except to a live auction' ), false !== strpos( $html, 'only a value TCGiant Sync sent' ), false !== strpos( $html, 'Not refunded' ) ), array( true, true, true, true ) );
ob_start();
TCGiant_Sync_Listing_Options::render_product_section( item( 1303, array( '_ebay_export_listing_type' => 'Chinese' ) ), 'FixedPriceItem' );
$html = (string) ob_get_clean();
check( '  an auction product: the box shown; none filled says the listing keeps eBay\'s', array( false !== strpos( $html, 'id="tc-auction-options" style=""' ), false !== strpos( $html, 'None: the listing keeps what eBay has' ) ), array( true, true ) );

$admin_src = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' );
$core_src  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync.php' );
check( 'the Push and Verify buttons save the section first; the tab draws it; the product save hooked', array( substr_count( $admin_src, 'TCGiant_Sync_Listing_Options::save_posted( $product_id, $_POST );' ), substr_count( $admin_src, 'TCGiant_Sync_Listing_Options::render_product_section( $product,' ), substr_count( $core_src, 'TCGiant_Sync_Listing_Options::register_admin();' ) ), array( 2, 1, 1 ) );
$verify_src = substr( $admin_src, (int) strpos( $admin_src, 'public function ajax_verify_product()' ), 9000 );
check( 'Verify shows every fee eBay gives above 0 by eBay\'s own name (SubtitleFee, ReserveFee, a second category\'s InsertionFee)', 1 === preg_match( '/foreach \( \$result\[\'fees\'\] as \$name => \$amount \) \{\s*if \( \$amount > 0 \) \{\s*\$fees\[\] = \$name \. \': \' \. self::fee_amount\( \$amount \);/', $verify_src ), true );
$uninstall_src = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
$named         = array();
foreach ( array_merge( array_values( TCGiant_Sync_Listing_Options::METAS ), array( TCGiant_Sync_Listing_Options::SECOND_CATEGORY_NAME_META, TCGiant_Sync_Listing_Options::SENT_META ) ) as $key ) {
	if ( preg_match( '/DELETE[^;]*\'' . preg_quote( $key, '/' ) . '\'/s', $uninstall_src ) ) {
		$named[] = $key;
	}
}
check( 'uninstall deletes none of the new _ebay_export_ keys: they stay with the product, as its other _ebay_export_ choices do', array( $named, false !== strpos( $uninstall_src, '_ebay_export_options_sent' ) ), array( array(), true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 1 - M1: BRAND AND MPN NEVER A PARTIAL SET ON AN UPDATE\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
$live = array( 'item_id' => '110000002001', 'category_id' => '31387', 'condition_id' => '3000' );
list( $res, , $calls ) = push( item( 2001, opts( array( 'brand' => 'Zeta' ) ) + $IMPORTED + array( '_ebay_item_id' => '110000002001', '_ebay_live' => $live, TCGiant_Sync_Ebay_Live::FOLLOW_META => '1' ) ) );
check( 'an imported listing set to send the shop\'s defaults, with item specifics of its own: Brand rides along the whole set', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ) ), array( 'updated', array( 'Brand=Zeta', 'Colour=Red' ) ) );
list( $res, , $calls ) = push( item( 2002, opts( array( 'mpn' => 'ZT-1' ) ) + $PUSHED + array( '_ebay_item_id' => '110000002002' ), array( 'attributes' => array() ) ) );
check( 'a pushed listing\'s Update with no other item specifics: MPN alone not sent either, said once', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ), logged( 'Push: WC #2002 - its Brand and MPN were not sent: this Update sends no other item specifics' ) ), array( 'updated', null, 'info' ) );
check( '  a new listing\'s Verify of the same: MPN alone, a new listing having nothing on eBay to lose', specifics_of( verify_item( item( 2003, opts( array( 'mpn' => 'ZT-1' ) ), array( 'attributes' => array() ) ) ) ), array( 'MPN=ZT-1' ) );
$plain = item( 2004, $PUSHED + array( '_ebay_item_id' => '110000002004' ), array( 'attributes' => array() ) );
list( , , $calls ) = push( $plain );
$no_fields = no_uuid( $calls[0][1] ?? '' );
list( , , $calls ) = push( item( 2004, opts( array( 'brand' => 'Zeta', 'mpn' => 'ZT-1' ) ) + $PUSHED + array( '_ebay_item_id' => '110000002004' ), array( 'attributes' => array() ) ) );
check( '  the Update with both filled is byte for byte the one with neither: 3.37.0\'s', no_uuid( $calls[0][1] ?? '' ), $no_fields );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 1 - M2: AN AUCTION'S BUY IT NOW AND RESERVE BY EBAY'S RULES\n" . str_repeat( '=', 124 ) . "\n";

shop( '31387', 'Chinese' );
fresh_ebay();
list( $res, $sites ) = push( item( 2101, opts( array( 'reserve' => '120', 'buy_it_now' => '100' ) ) ) );
check( 'a new auction with Buy It Now 100 below its reserve 120 (the review\'s probe B; errors 190 and 591): refused before eBay, nothing sent', array( code_of( $res ), $sites, 0 === strpos( message_of( $res ), 'The Buy It Now price 100.00 is not above the reserve price 120.00. eBay takes' ) ), array( 'missing_export_settings', array(), true ) );
list( $res, $sites ) = push( item( 2102, opts( array( 'reserve' => '120', 'buy_it_now' => '120' ) ) ) );
check( '  one equal to the reserve: refused', array( code_of( $res ), $sites ), array( 'missing_export_settings', array() ) );
list( $res, $sites, $calls ) = push( item( 2103, opts( array( 'reserve' => '120', 'buy_it_now' => '120.01' ) ) ) );
check( '  a cent above it: listed with both', array( code_of( $res ), $sites, el( $calls[0][1] ?? '', 'BuyItNowPrice' ), el( $calls[0][1] ?? '', 'ReservePrice' ) ), array( 'created', array( 'AddItem@0' ), '120.01', '120.00' ) );
$x = exporter();
$x->build_item_xml_public( item( 2104, opts( array( 'reserve' => '120', 'buy_it_now' => '110' ) ) ), $x->get_export_settings( 2104 ) );
check( '  Verify refuses it before eBay too', is_wp_error( $x->description_too_long() ) ? $x->description_too_long()->get_error_code() : null, 'missing_export_settings' );
list( $res ) = push( item( 2105, opts( array( 'reserve' => '50', 'buy_it_now' => '40' ) ) ) );
check( '  both below the opening bid: one refusal naming both', 0 === strpos( message_of( $res ), 'The reserve price 50.00 is not above the opening bid 85.00; the Buy It Now price 40.00 is not above the opening bid 85.00. ' ), true );

// Relists: never stopped by these fields.
shop();
$ended_auction = array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_listing_status' => 'Ended' );
$ended_with    = function ( $id, $reserve = null ) {
	$more = array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00', 'Quantity' => '1' );
	if ( null !== $reserve ) {
		$more['ReservePrice'] = $reserve;
	}
	return ended_in( $id, '31387', $more );
};
item( 2111, opts( array( 'reserve' => '150', 'buy_it_now' => '140' ) ) + $ended_auction + array( '_ebay_item_id' => '110000002111' ) );
list( $res, $sites, $calls ) = relist_it( 2111, $ended_with( '110000002111', '120.00' ), 'Auto-Relist' );
$body = (string) ( $calls[1][1] ?? '' );
check( 'Auto-Relist of an auction whose reserve was raised to 150, above the ended listing\'s 120, with Buy It Now 140 (the review\'s probe A): the relist goes', array( code_of( $res ), $sites ), array( '110000000001', array( 'GetItem@0', 'RelistItem@0' ) ) );
check( '  without the reserve (error 115), with the Buy It Now, above the 120 the relist keeps', array( el( $body, 'ReservePrice' ), el( $body, 'BuyItNowPrice' ) ), array( null, '140.00' ) );
check( '  said', logged( 'Auto-Relist: WC #2111 - the reserve price not sent: the reserve price 150.00 is above the ended listing\'s, 120.00, and eBay takes a relist\'s reserve only at or below that; the relist keeps 120.00.' ), 'info' );
check( '  recorded: the Buy It Now it sent', record_of( 2111 ), array( 'item' => '110000000001', 'buy_it_now' => '140.00' ) );
item( 2112, opts( array( 'reserve' => '150', 'buy_it_now' => '115' ) ) + $ended_auction + array( '_ebay_item_id' => '110000002112' ) );
list( $res, , $calls ) = relist_it( 2112, $ended_with( '110000002112', '120.00' ), 'Auto-Relist' );
check( 'a Buy It Now 115 not above the ended listing\'s reserve 120, which the relist keeps: both left out, the relist goes', array( code_of( $res ), el( $calls[1][1] ?? '', 'ReservePrice' ), el( $calls[1][1] ?? '', 'BuyItNowPrice' ) ), array( '110000000001', null, null ) );
check( '  said', logged( 'the Buy It Now price not sent: the Buy It Now price 115.00 is not above the ended listing\'s reserve price 120.00, which the relist keeps; the relist goes without one.' ), 'info' );
item( 2113, opts( array( 'reserve' => '100', 'buy_it_now' => '100' ) ) + $ended_auction + array( '_ebay_item_id' => '110000002113' ) );
list( $res, , $calls ) = relist_it( 2113, $ended_with( '110000002113', '120.00' ) );
check( 'a reserve 100 below the ended listing\'s 120 goes; a Buy It Now 100 not above it is left out', array( code_of( $res ), el( $calls[1][1] ?? '', 'ReservePrice' ), el( $calls[1][1] ?? '', 'BuyItNowPrice' ), logged( 'Bulk relist: WC #2113 - the Buy It Now price not sent: the Buy It Now price 100.00 is not above the reserve price 100.00; the relist goes without one.' ) ), array( '110000000001', '100.00', null, 'info' ) );
item( 2114, opts( array( 'reserve' => '120' ) ) + $ended_auction + array( '_ebay_item_id' => '110000002114' ) );
list( , , $calls ) = relist_it( 2114, $ended_with( '110000002114', array( '@attributes' => array( 'currencyID' => 'USD' ), 0 => '120.0' ) ) );
check( 'a reserve equal to the ended listing\'s: sent ("equal to or lower")', el( $calls[1][1] ?? '', 'ReservePrice' ), '120.00' );
item( 2115, opts( array( 'reserve' => '120', 'buy_it_now' => '150' ) ) + $ended_auction + array( '_ebay_item_id' => '110000002115' ) );
list( $res, , $calls ) = relist_it( 2115, $ended_with( '110000002115' ), 'Auto-Relist' );
check( 'an ended listing with no reserve in eBay\'s answer: the reserve left out (adding one on a relist is not documented), the Buy It Now sent, the relist goes', array( code_of( $res ), el( $calls[1][1] ?? '', 'ReservePrice' ), el( $calls[1][1] ?? '', 'BuyItNowPrice' ) ), array( '110000000001', null, '150.00' ) );
check( '  said', logged( 'Auto-Relist: WC #2115 - the reserve price not sent: the ended listing had no reserve price, as eBay\'s answer gives it, and eBay does not document adding one on a relist, so 120.00 waits for a new listing.' ), 'info' );
item( 2116, opts( array( 'reserve' => '120' ) ) + $ended_auction + array( '_ebay_item_id' => '110000002116' ) );
list( , , $calls ) = relist_it( 2116, $ended_with( '110000002116', array( '@attributes' => array( 'currencyID' => 'USD' ), 0 => '0.0' ) ) );
check( '  eBay\'s 0.0 is no reserve too', array( el( $calls[1][1] ?? '', 'ReservePrice' ), logged( 'Bulk relist: WC #2116 - the reserve price not sent: the ended listing had no reserve price' ) ), array( null, 'info' ) );
check( 'every one of these relisted: Auto-Relist is never stopped by them', array( get_post_meta( 2112, '_ebay_item_id', true ), get_post_meta( 2115, '_ebay_item_id', true ), get_post_meta( 2116, '_ebay_item_id', true ) ), array( '110000000001', '110000000001', '110000000001' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 1 - M3: MPN BY EBAY'S OTHER NAME, \"MANUFACTURER PART NUMBER\"\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
$API->aspects = array( array( 'name' => 'Brand', 'required' => false ), array( 'name' => 'Manufacturer Part Number', 'required' => true ) );
list( $res, $sites, $calls ) = push( item( 2201, opts( array( 'mpn' => 'ZT-100', 'brand' => 'Zeta' ) ), array( 'attributes' => array() ) ) );
check( 'a category requiring "Manufacturer Part Number", the MPN field filled (the review\'s probe C): listed, the field named as the category names it', array( code_of( $res ), $sites, specifics_of( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@0' ), array( 'Brand=Zeta', 'Manufacturer Part Number=ZT-100' ) ) );
$API->aspects = array( array( 'name' => 'Brand', 'required' => false ), array( 'name' => 'Manufacturer Part Number', 'required' => false ) );
list( , , $calls ) = push( item( 2202, opts( array( 'mpn' => 'ZT-100' ) ), array( 'attributes' => array( new WC_Product_Attribute( 'Manufacturer Part Number', array( 'OLD-1' ) ) ) ) ) );
check( '  an attribute "Manufacturer Part Number" beside the field (probe C2): the field wins, the name once', specifics_of( $calls[0][1] ?? '' ), array( 'Manufacturer Part Number=ZT-100' ) );
list( , , $calls ) = push( item( 2203, opts( array( 'mpn' => 'ZT-100' ) ), array( 'attributes' => array( new WC_Product_Attribute( 'MPN', array( 'OLD-2' ) ), new WC_Product_Attribute( 'Colour', array( 'Red' ) ) ) ) ) );
check( '  an attribute "MPN" there: one of the field\'s names too, so the field in its place', specifics_of( $calls[0][1] ?? '' ), array( 'Manufacturer Part Number=ZT-100', 'Colour=Red' ) );
list( , , $calls ) = push( item( 2204, array(), array( 'attributes' => array( new WC_Product_Attribute( 'MPN', array( 'OLD-3' ) ), new WC_Product_Attribute( 'Colour', array( 'Red' ) ) ) ) ) );
check( '  the field empty: the attribute "MPN" goes as 3.37.0 sent it, by its own name', specifics_of( $calls[0][1] ?? '' ), array( 'MPN=OLD-3', 'Colour=Red' ) );
$API->aspects = array( array( 'name' => 'Brand', 'required' => false ), array( 'name' => 'MPN', 'required' => false ) );
shop( '33564' );
fresh_ebay();
$API->tree_aspects['100']['33564'] = array( array( 'name' => 'Brand', 'required' => true ), array( 'name' => 'Manufacturer Part Number', 'required' => true ) );
list( $res, $sites, $calls ) = push( item( 2211, opts( array( 'brand' => 'Zeta', 'mpn' => 'ZT-100' ) ), array( 'attributes' => array() ) ) );
check( 'eBay Motors tree 100\'s "Manufacturer Part Number", required: listed on eBay Motors with the MPN field by that name', array( code_of( $res ), $sites, specifics_of( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@100' ), array( 'Brand=Zeta', 'Manufacturer Part Number=ZT-100' ) ) );
list( $res, $sites ) = push( item( 2212, opts( array( 'brand' => 'Zeta' ) ), array( 'attributes' => array() ) ) );
check( '  with MPN empty: refused before eBay for it, as before', array( code_of( $res ), $sites, false !== strpos( message_of( $res ), 'Manufacturer Part Number' ) ), array( 'missing_required_aspects', array(), true ) );
shop();
check( 'names_field(): MPN by either name, letters and digits, case aside; Brand by its own only', array( TCGiant_Sync_Listing_Options::names_field( 'mpn', 'Manufacturer Part Number' ), TCGiant_Sync_Listing_Options::names_field( 'mpn', 'M.P.N' ), TCGiant_Sync_Listing_Options::names_field( 'mpn', 'Part Number' ), TCGiant_Sync_Listing_Options::names_field( 'brand', 'BRAND' ), TCGiant_Sync_Listing_Options::names_field( 'brand', 'Brand Name' ), TCGiant_Sync_Listing_Options::names_field( 'mpn', '' ) ), array( true, true, false, true, false, false ) );
check( 'with_specifics(): the category\'s first aspect of the field\'s names, else the field\'s own', array( TCGiant_Sync_Listing_Options::with_specifics( array(), item( 2213, opts( array( 'mpn' => 'X' ) ) ), array( array( 'name' => 'Colour' ), array( 'name' => 'Manufacturer  part-number' ) ) ), TCGiant_Sync_Listing_Options::with_specifics( array(), item( 2213, opts( array( 'mpn' => 'X' ) ) ) ) ), array( array( 'Manufacturer  part-number' => 'X' ), array( 'MPN' => 'X' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 1 - L1: WHAT EBAY DROPPED IS NOT RECORDED; A REFUSED TAKE-OFF LEAVES THE RECORD\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
$warned = function ( $codes, $item_id = '110000000001' ) {
	$errors = array();
	foreach ( (array) $codes as $code ) {
		$errors[] = array( 'ErrorCode' => (string) $code, 'ShortMessage' => 'Dropped.', 'LongMessage' => 'Dropped.', 'SeverityCode' => 'Warning' );
	}
	return array( 'Ack' => 'Warning', 'ItemID' => $item_id, 'Errors' => 1 === count( $errors ) ? $errors[0] : $errors );
};
item( 2301, $FIXED );
$API->answers['AddFixedPriceItem'] = $warned( '12317' );
push_whole( 2301 );
unset( $API->answers['AddFixedPriceItem'] );
check( 'a new listing eBay took with Warning 12317, the subtitle removed: recorded without it', record_of( 2301 ), array( 'item' => '110000000001', 'condition_note' => 'A small scuff on the box.', 'second_category' => '261328', 'specifics' => array( 'Brand' => 'Zeta', 'MPN' => 'ZT-100' ) ) );
check( '  said', logged( 'Push: eBay took listing 110000000001 of WC #2301 without the subtitle (its warning 12317), which it says it removed or ignored there, so TCGiant Sync does not count it as sent.' ), 'info' );
item( 2302, $FIXED + $PUSHED + array( '_ebay_item_id' => '110000002302' ) );
$API->answers['ReviseFixedPriceItem'] = $warned( array( '21920381', '21917152' ), '110000002302' );
push_whole( 2302 );
unset( $API->answers['ReviseFixedPriceItem'] );
check( 'an Update eBay took with Warnings 21920381 and 21917152: neither the condition note nor the second category recorded', record_of( 2302 ), array( 'item' => '110000002302', 'subtitle' => 'Mint, boxed, first edition', 'specifics' => array( 'Brand' => 'Zeta', 'MPN' => 'ZT-100' ) ) );
delete_post_meta( 2302, TCGiant_Sync_Listing_Options::CONDITION_NOTE_META );
delete_post_meta( 2302, TCGiant_Sync_Listing_Options::SECOND_CATEGORY_META );
list( , $calls ) = push_whole( 2302 );
check( '  emptied later: nothing sent to take them off', array( deleted_of( $calls[0][1] ?? '' )[0], el( $calls[0][1] ?? '', 'SecondaryCategory' ) ), array( array(), null ) );
item( 2303, $FIXED + $PUSHED + array( '_ebay_listing_status' => 'Ended', '_ebay_item_id' => '110000002303' ) );
$API->answers['RelistFixedPriceItem'] = $warned( '21916361' );
relist_it( 2303, ended_in( '110000002303', '31387' ) );
unset( $API->answers['RelistFixedPriceItem'] );
check( 'a relist eBay took with Warning 21916361: the second category not recorded', record_of( 2303 ), array( 'item' => '110000000001', 'subtitle' => 'Mint, boxed, first edition', 'condition_note' => 'A small scuff on the box.' ) );
item( 2304, $FIXED );
$API->answers['AddFixedPriceItem'] = $warned( '21919028' );
push_whole( 2304 );
$API->answers['AddFixedPriceItem'] = array( 'Ack' => 'Warning', 'ItemID' => '110000000001', 'Errors' => array( 'ErrorCode' => '12317', 'SeverityCode' => 'Error', 'LongMessage' => 'Odd.' ) );
item( 2305, $FIXED );
push_whole( 2305 );
unset( $API->answers['AddFixedPriceItem'] );
check( '  a warning of another code, or the code as an Error: everything recorded', array( count( record_of( 2304 ) ), count( record_of( 2305 ) ) ), array( 5, 5 ) );

// An Update refused while taking a recorded field off.
item( 2311, $PUSHED + array( '_ebay_item_id' => '110000002311', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000002311', 'subtitle' => 'Old', 'second_category' => '261328' ) ) );
$API->answers['ReviseFixedPriceItem'] = trading_error( '10031', 'Second Category can not be removed from the item.' );
list( $sites ) = push_whole( 2311 );
unset( $API->answers['ReviseFixedPriceItem'] );
check( 'an Update refused with 10031 while taking the second category off: one call, nothing sent again', $sites, array( 'ReviseFixedPriceItem@0' ) );
check( '  the second category leaves the record; the subtitle stays in it', record_of( 2311 ), array( 'item' => '110000002311', 'subtitle' => 'Old' ) );
check( '  said once, in plain words', logged( 'Push: eBay would not take the second category off eBay listing 110000002311 of WC #2311 (its error 10031), so it stays on that listing, and TCGiant Sync no longer tries to take it off. Press Update again to send the product\'s other changes.' ), 'warning' );
list( , $calls ) = push_whole( 2311 );
check( '  the next Update takes the subtitle off, and nothing of the second category', array( deleted_of( $calls[0][1] ?? '' )[0], el( $calls[0][1] ?? '', 'SecondaryCategory' ), record_of( 2311 ) ), array( array( 'Item.SubTitle' ), null, '' ) );
item( 2312, $PUSHED + array( '_ebay_item_id' => '110000002312', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000002312', 'subtitle' => 'Old' ) ) );
$API->answers['ReviseFixedPriceItem'] = trading_error( '10039', 'The title or subtitle cannot be changed if an auction-style listing has a bid or ends within 12 hours, or a fixed price listing has a sale or a pending Best Offer.' );
list( $sites ) = push_whole( 2312 );
unset( $API->answers['ReviseFixedPriceItem'] );
check( 'an Update refused with 10039 while taking the subtitle off: one call, the subtitle leaves the record', array( $sites, record_of( 2312 ), logged( 'Push: eBay would not take the subtitle off eBay listing 110000002312 of WC #2312 (its error 10039)' ) ), array( array( 'ReviseFixedPriceItem@0' ), '', 'warning' ) );
item( 2313, opts( array( 'subtitle' => 'New' ) ) + $PUSHED + array( '_ebay_item_id' => '110000002313', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000002313', 'subtitle' => 'Old' ) ) );
$API->answers['ReviseFixedPriceItem'] = trading_error( '10039', 'The title or subtitle cannot be changed.' );
push_whole( 2313 );
item( 2314, $PUSHED + array( '_ebay_item_id' => '110000002314', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000002314', 'subtitle' => 'Old' ) ) );
$API->answers['ReviseFixedPriceItem'] = trading_error( '21916250', 'Something else.' );
push_whole( 2314 );
item( 2315, $PUSHED + array( '_ebay_item_id' => '110000002315', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000002315', 'subtitle' => 'Old' ) ) );
$API->answers['ReviseFixedPriceItem'] = trading_error( '10031', 'Second Category can not be removed from the item.' );
push_whole( 2315 );
item( 2316, opts( array( 'subtitle' => 'Old' ) ) + $PUSHED + array( '_ebay_item_id' => '110000002316', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000002316', 'subtitle' => 'Old', 'second_category' => '261328' ) ) );
$API->answers['ReviseFixedPriceItem'] = trading_error( '10039', 'The title or subtitle cannot be changed.' );
push_whole( 2316 );
unset( $API->answers['ReviseFixedPriceItem'] );
check( '  10039 while changing the subtitle, any other refusal while taking it off, 10031 with no second category taken off, 10039 while taking off only the second category: the record as it was', array( record_of( 2313 ), record_of( 2314 ), record_of( 2315 ), record_of( 2316 ) ), array( array( 'item' => '110000002313', 'subtitle' => 'Old' ), array( 'item' => '110000002314', 'subtitle' => 'Old' ), array( 'item' => '110000002315', 'subtitle' => 'Old' ), array( 'item' => '110000002316', 'subtitle' => 'Old', 'second_category' => '261328' ) ) );
check( 'refused_take_off(): a 10039 given as a Warning, a product\'s other listing\'s record: nothing done', array( TCGiant_Sync_Listing_Options::refused_take_off( 2315, '110000002315', array( 'subtitle' ), trading_error( '10039', 'w', array(), 'Warning' ), 'Push' ), TCGiant_Sync_Listing_Options::refused_take_off( 2315, '110000009999', array( 'subtitle' ), trading_error( '10039', 'e' ), 'Push' ), record_of( 2315 ) ), array( '', '', array( 'item' => '110000002315', 'subtitle' => 'Old' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 1 - L3: THE TEMPLATE'S SPECIFICS TABLE CARRIES BRAND AND MPN EXACTLY WHEN THE ITEM DOES\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
$table   = (string) make_template( array( 'name' => 'Table', 'html' => '{{{description}}}{{{specifics_table}}}' ) );
$in_desc = function ( $body ) {
	return false !== strpos( (string) el( $body, 'Description' ), 'Zeta' );
};
list( , , $calls ) = push( item( 2401, opts( array( 'brand' => 'Zeta' ) ) + array( '_tcgiant_tpl_override' => $table ) ) );
check( 'a new listing: Brand in its item specifics and in its description\'s table', array( specifics_of( $calls[0][1] ?? '' ), $in_desc( $calls[0][1] ?? '' ) ), array( array( 'Brand=Zeta', 'Colour=Red' ), true ) );
list( , , $calls ) = push( item( 2402, opts( array( 'brand' => 'Zeta' ) ) + $PUSHED + array( '_ebay_item_id' => '110000002402', '_tcgiant_tpl_override' => $table ) ) );
check( 'an Update whose item specifics go: in both', array( specifics_of( $calls[0][1] ?? '' ), $in_desc( $calls[0][1] ?? '' ) ), array( array( 'Brand=Zeta', 'Colour=Red' ), true ) );
list( , , $calls ) = push( item( 2403, opts( array( 'brand' => 'Zeta' ) ) + $PUSHED + array( '_ebay_item_id' => '110000002403', '_tcgiant_tpl_override' => $table ), array( 'attributes' => array() ) ) );
check( 'an Update whose item specifics do not go: in neither', array( specifics_of( $calls[0][1] ?? '' ), $in_desc( $calls[0][1] ?? '' ) ), array( null, false ) );
$resend = function ( $id ) {
	$product = wc_get_product( $id );
	return exporter()->resend_description( $product, exporter()->resend_settings( $product ) );
};
check( 'Re-send to eBay, as the product\'s Update would: with the specifics that go, and not without', array( false !== strpos( $resend( 2402 )['html'], 'Zeta' ), false !== strpos( $resend( 2403 )['html'], 'Zeta' ) ), array( true, false ) );
item( 2404, opts( array( 'brand' => 'Zeta' ) ) + array( '_tcgiant_tpl_override' => $table ), array( 'attributes' => array() ) );
check( '  a product with no listing: as a new listing, with them', false !== strpos( $resend( 2404 )['html'], 'Zeta' ), true );
$with_hash = $resend( 2403 )['content_hash'];
delete_post_meta( 2403, TCGiant_Sync_Listing_Options::BRAND_META );
check( 'the template content hash of a product whose Update sends no Brand: as with the field empty (it shows no change to re-send)', $resend( 2403 )['content_hash'], $with_hash );
$going_hash = $resend( 2402 )['content_hash'];
delete_post_meta( 2402, TCGiant_Sync_Listing_Options::BRAND_META );
check( '  one whose Update sends it: changed by it, so the product shows as needing a re-send', $resend( 2402 )['content_hash'] !== $going_hash, true );
shop( '31387', 'Chinese' );
$API->answers['ReviseItem'] = new WP_Error( 'api_error', 'Auction has already been closed.' );
$API->answers['GetItem']    = ended_in( '110000002405', '31387', array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00' ) );
item( 2405, $AUCTION + array( '_ebay_item_id' => '110000002405', '_ebay_listing_type' => 'Chinese', '_tcgiant_tpl_override' => $table, '_ebay_live' => array( 'item_id' => '110000002405', 'category_id' => '31387', 'condition_id' => '3000' ) ) + $IMPORTED );
list( $sites, $calls ) = push_whole( 2405 );
unset( $API->answers['ReviseItem'], $API->answers['GetItem'] );
check( 'a push\'s relist of an imported auction: RelistItem\'s description has no Brand in its table, as RelistItem sends no item specifics', array( $sites, $in_desc( $calls[2][1] ?? '' ), specifics_of( $calls[2][1] ?? '' ) ), array( array( 'ReviseItem@0', 'GetItem@0', 'RelistItem@0' ), false, null ) );
check( '  and its log has no "not sent to a live auction" line beside the relist\'s (L5 e)', array( logged( 'Push: WC #2405 - the subtitle is not sent to a live auction' ), logged( 'Push: WC #2405 relisted on eBay' ) ), array( null, 'success' ) );
shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 1 - L4 AND L6: EBAY'S THIRTY, AND ONE READ OF THE CATEGORY'S ITEM SPECIFICS\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
$attrs = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$attrs[] = new WC_Product_Attribute( 'Attr' . $i, array( 'V' . $i ) );
}
list( , , $calls ) = push( item( 2501, opts( array( 'brand' => 'Zeta', 'mpn' => 'ZT-1' ) ), array( 'attributes' => $attrs ) ) );
$sp = (array) specifics_of( $calls[0][1] ?? '' );
check( 'thirty attributes with Brand and MPN filled (the review\'s probe E): eBay\'s thirty, the two first', array( count( $sp ), array_slice( $sp, 0, 2 ), array_slice( $sp, -1 ) ), array( 30, array( 'Brand=Zeta', 'MPN=ZT-1' ), array( 'Attr28=V28' ) ) );
check( '  the two cut said once, by name', array( logged( 'Push: WC #2501 - its Brand and MPN fields go first among its item specifics, and eBay takes 30 at most, so these were left out: Attr29, Attr30.' ), count( preg_grep( '/WC #2501 - its Brand and MPN fields go first/', array_column( $GLOBALS['W']['log'], 1 ) ) ) ), array( 'warning', 1 ) );
$attrs[29] = new WC_Product_Attribute( 'Manufacturer Part Number', array( 'OLD' ) );
list( , , $calls ) = push( item( 2502, opts( array( 'mpn' => 'ZT-1' ) ), array( 'attributes' => $attrs ) ) );
check( '  a "Manufacturer Part Number" attribute the MPN field takes the place of is not counted as cut', array( count( (array) specifics_of( $calls[0][1] ?? '' ) ), array_slice( (array) specifics_of( $calls[0][1] ?? '' ), 0, 1 ), logged( 'Push: WC #2502 - its Brand and MPN fields go first' ) ), array( 30, array( 'MPN=ZT-1' ), null ) );

$API->aspects = new WP_Error( 'api_error', 'eBay did not answer.' );
$reads        = function () {
	return count( preg_grep( '/^aspects:/', $GLOBALS['API']->rest ) );
};
// eBay Motors' look at the category, once a day, is made first: what is
// counted below is the push's own reads.
push( item( 2510 ) );
$counted = array();
foreach ( array( 'update' => $PUSHED + array( '_ebay_item_id' => '110000002511' ), 'new' => array(), 'template' => array( '_tcgiant_tpl_override' => $table ), 'template-update' => $PUSHED + array( '_ebay_item_id' => '110000002511', '_tcgiant_tpl_override' => $table ) ) as $case => $meta ) {
	push( item( 2511, $meta ) );
	$plain = $reads();
	push( item( 2511, $meta + opts( array( 'brand' => 'Zeta', 'mpn' => 'ZT-1' ) ) ) );
	$counted[ $case ] = array( $reads(), $plain );
}
check( 'eBay\'s item specifics read failing (L6): an Update, a new listing and templated ones with Brand and MPN try it exactly as often as with neither, no second time in a build', $counted, array( 'update' => array( 2, 2 ), 'new' => array( 2, 2 ), 'template' => array( 2, 3 ), 'template-update' => array( 2, 3 ) ) );
$API->aspects = array( array( 'name' => 'Brand', 'required' => false ), array( 'name' => 'MPN', 'required' => false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 1 - L8 AND L7: ENTITIES, THE AUCTION ROWS, \"NOT SENT\" ONCE, AND THE REVIEW'S SURVIVING MUTANTS\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
list( , , $calls ) = push( item( 2601, opts( array( 'subtitle' => str_repeat( 'x', 52 ) . ' &lt;3 tail' ) ) ) );
check( 'a subtitle kept with an entity (sanitize_text_field() keeps a "<" as "&lt;"): cut by eBay\'s characters, never inside it', el( $calls[0][1] ?? '', 'SubTitle' ), str_repeat( 'x', 52 ) . ' &lt;3' );
check( '  clean(): entities decoded, then cut and escaped only as the request is made', array( TCGiant_Sync_Listing_Options::clean( 'subtitle', '&lt;b&gt; &amp; &quot;q&quot;' ), TCGiant_Sync_Listing_Options::clean( 'brand', 'A&amp;B' ), TCGiant_Sync_Listing_Options::clean( 'condition_note', "a &lt; b\nc" ), TCGiant_Sync_Listing_Options::clean( 'reserve', '1&amp;2' ) ), array( '<b> & "q"', 'A&B', "a < b\nc", '' ) );
check( '  runs of spaces, tabs and line breaks one space; a condition note keeps its lines', array( TCGiant_Sync_Listing_Options::clean( 'subtitle', "Mint,\t boxed\n\n first" ), TCGiant_Sync_Listing_Options::clean( 'mpn', "ZT  100" ), TCGiant_Sync_Listing_Options::clean( 'condition_note', "One.\n\nTwo." ) ), array( 'Mint, boxed first', 'ZT 100', "One.\n\nTwo." ) );

ob_start();
TCGiant_Sync_Listing_Options::render_product_section( item( 2602, array( '_ebay_listing_type' => 'Chinese' ) ), 'FixedPriceItem' );
$html = (string) ob_get_clean();
check( 'the eBay tab of a product whose listing is recorded as an auction, in a fixed-price shop: Buy It Now and reserve shown, the select\'s toggle told', array( false !== strpos( $html, 'id="tc-auction-options" style=""' ), false !== strpos( $html, 'data-recorded-format="Chinese"' ), false !== strpos( $html, "'Chinese' === box.data( 'recorded-format' )" ) ), array( true, true, true ) );
check( '  its words: Buy It Now goes once a bid meets the reserve; a relist\'s rules; GTC renewals "on some eBay sites"; a relist made on eBay keeps what it carried', array( false !== strpos( $html, 'Gone once a bid meets the reserve price, if there is one, or else at the first bid.' ), false !== strpos( $html, 'A relist leaves out a reserve above the ended listing' ), false !== strpos( $html, esc_html( 'On some eBay sites (eBay US and eBay Germany among them) a Good \'Til Cancelled listing' ) ), false !== strpos( $html, esc_html( 'A listing relisted on eBay itself keeps what it carried, so emptying a field won\'t take it off that listing.' ) ) ), array( true, true, true, true ) );

item( 2603, opts( array( 'buy_it_now' => '150' ) ) );
$said = array();
$once = function () use ( &$said ) {
	$GLOBALS['W']['log'] = array();
	push( wc_get_product( 2603 ) );
	$said[] = logged( 'Push: WC #2603 - the Buy It Now price is for an auction, and this listing is fixed-price: not sent' );
};
$once();
$once();
update_post_meta( 2603, TCGiant_Sync_Listing_Options::BUY_IT_NOW_META, '160.00' );
$once();
$once();
update_post_meta( 2603, '_ebay_export_listing_type', 'Chinese' );
update_post_meta( 2603, '_ebay_export_listing_duration', 'Days_7' );
$GLOBALS['W']['log'] = array();
list( , $sites ) = push( wc_get_product( 2603 ) );
delete_post_meta( 2603, '_ebay_export_listing_type' );
$once();
check( 'a filled Buy It Now on a fixed-price product: "not sent" said once, again when its value changes, and again after a push as an auction', array( $said, $sites ), array( array( 'info', null, 'info', null, 'info' ), array( 'AddItem@0' ) ) );

shop();
item( 2611, $FIXED + $PUSHED + array( '_ebay_listing_status' => 'Ended', '_ebay_item_id' => '110000002611' ) );
list( , , $calls ) = relist_it( 2611, ended_in( '110000002611', '183454', array( 'ConditionID' => '4000', 'ConditionDescriptors' => array( 'ConditionDescriptor' => array( 'Name' => '40001', 'Value' => '400010' ) ) ) ) );
check( 'a relist of a listing eBay\'s answer gives condition descriptors (an ungraded card): no condition note, the rest as filled', array( el( $calls[1][1] ?? '', 'ConditionDescription' ), el( $calls[1][1] ?? '', 'SubTitle' ), logged( 'Bulk relist: WC #2611 - the condition note is not sent with condition descriptors' ) ), array( null, 'Mint, boxed, first edition', 'info' ) );
list( , , $calls ) = push( item( 2612, $FIXED + $IMPORTED + array( '_ebay_item_id' => '110000002612', '_ebay_live' => array( 'item_id' => '110000002612', 'category_id' => '31387', 'condition_id' => '4000', 'descriptors' => array( array( 'name' => '40001', 'value' => '400010' ) ) ) ) ) );
check( 'an imported listing\'s Update that keeps eBay\'s condition, with descriptors as eBay last gave them: no condition note', array( el( $calls[0][1] ?? '', 'ConditionID' ), el( $calls[0][1] ?? '', 'ConditionDescription' ), el( $calls[0][1] ?? '', 'SubTitle' ) ), array( null, null, 'Mint, boxed, first edition' ) );
item( 2613 );
TCGiant_Sync_Listing_Options::save_posted( 2613, array(
	'_ebay_export_subtitle_shown' => '1',
	'_ebay_export_subtitle'       => "O\\'Brien <i>card</i>",
	'_ebay_export_brand'          => '<b>Zeta</b>',
	'_ebay_export_mpn'            => "ZT\\'100",
) );
check( 'the save: slashes taken off and tags stripped, as wp_unslash() and sanitize_text_field() do', array( get_post_meta( 2613, '_ebay_export_subtitle', true ), get_post_meta( 2613, '_ebay_export_brand', true ), get_post_meta( 2613, '_ebay_export_mpn', true ) ), array( "O'Brien card", 'Zeta', "ZT'100" ) );
check( 'uninstall leaves the "said once" memo too, an _ebay_export_ key, and its comment names it', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ), TCGiant_Sync_Listing_Options::SAID_META ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 2 - M-1: A RELIST EBAY REFUSES WITH ITS LISTING OPTIONS GOES ONCE MORE WITHOUT THEM\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
$not_leaf = trading_error( '87', 'The category selected is not a leaf category.' );
$other    = trading_error( '21916250', 'Something else about the listing.' );
$cat_xml  = '<SecondaryCategory><CategoryID>11116</CategoryID></SecondaryCategory>';
// The day's calls spent - every shop's together, as the relay last said - so
// the real API class's guard refuses whatever goes next.
$spend = function () {
	set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'remaining' => 0, 'global_calls' => 40000, 'daily_limit' => 50000, 'updated_at' => time() ), DAY_IN_SECONDS );
};
// An ended auction, as each relist's source finds one: pushed and Ended for
// Bulk Relist and Auto-Relist, imported for a push's relist. A memo of a
// refused relist is kept across it.
$m1_item = function ( $id, $source, array $fields, array $more = array() ) use ( $PUSHED, $IMPORTED ) {
	$ended = '11000000' . $id;
	$memo  = get_post_meta( $id, TCGiant_Sync_Listing_Options::RELIST_MEMO, true );
	$meta  = $more + opts( $fields ) + array( '_ebay_item_id' => $ended, '_ebay_listing_type' => 'Chinese' );
	$meta += 'Push' === $source
		? array( '_ebay_live' => array( 'item_id' => $ended, 'category_id' => '31387', 'condition_id' => '3000' ) ) + $IMPORTED
		: array( '_ebay_listing_status' => 'Ended' ) + $PUSHED;
	item( $id, $meta );
	if ( '' !== $memo ) {
		update_post_meta( $id, TCGiant_Sync_Listing_Options::RELIST_MEMO, $memo );
	}
	return $ended;
};
// Its relist from $source, eBay answering its RelistItem calls in turn with
// $answers: the new listing (or why not), the calls, the RelistItem bodies.
$m1_relist = function ( $id, $source, array $answers ) {
	$api        = $GLOBALS['API'];
	$ended      = (string) get_post_meta( $id, '_ebay_item_id', true );
	$answer     = ended_in( $ended, '31387', array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00', 'Quantity' => '1' ) );
	$api->queue = array( 'RelistItem' => $answers );
	$GLOBALS['W']['log'] = array();
	if ( 'Push' === $source ) {
		$api->answers['ReviseItem'] = new WP_Error( 'api_error', 'Auction has already been closed.' );
		$api->answers['GetItem']    = $answer;
		push_whole( $id );
		unset( $api->answers['ReviseItem'], $api->answers['GetItem'] );
		$now = (string) get_post_meta( $id, '_ebay_item_id', true );
		$res = $now !== $ended ? $now : 'not relisted';
	} else {
		list( $res ) = relist_it( $id, $answer, $source );
		$res = is_wp_error( $res ) ? $res->get_error_code() : $res['item_id'];
	}
	$api->queue = array();
	$bodies     = array();
	foreach ( $api->calls as $call ) {
		if ( 'RelistItem' === $call[0] ) {
			$bodies[] = no_uuid( $call[1] );
		}
	}
	return array( $res, $api->sites, $bodies );
};
$memo_of = function ( $id ) {
	return explode( '>', (string) get_post_meta( $id, TCGiant_Sync_Listing_Options::RELIST_MEMO, true ) )[0];
};

foreach ( array( 'Bulk relist' => 3111, 'Auto-Relist' => 3121, 'Push' => 3131 ) as $source => $id ) {
	$lead   = 'Push' === $source ? array( 'ReviseItem@0', 'GetItem@0' ) : array( 'GetItem@0' );
	$failed = 'Push' === $source ? 'not relisted' : 'api_error';

	// Refused for its second category, then sent as 3.37.0 sent it.
	$ended = $m1_item( $id, $source, array( 'second_category' => '11116' ) );
	list( $res, $sites, $bodies ) = $m1_relist( $id, $source, array( $not_leaf ) );
	check( "$source of an auction eBay refuses for its second category (87, the review's probe P3): once more in the same run, and relisted", array( $res, $sites ), array( '110000000001', array_merge( $lead, array( 'RelistItem@0', 'RelistItem@0' ) ) ) );
	check( '  the first with the second category, the second without it, exactly as 3.37.0 sent it', array( false !== strpos( $bodies[0] ?? '', "\n" . $cat_xml ), $bodies[1] ?? null, carries_any( $bodies[1] ?? '' ) ), array( true, str_replace( "\n" . $cat_xml, '', $bodies[0] ?? '' ), false ) );
	if ( 'Push' !== $source ) {
		check( '  byte for byte 3.37.0\'s relist of an auction', $bodies[1] ?? null, "\n<Item>\n<ItemID>$ended</ItemID>\n</Item>" );
	}
	check( '  nothing of the options recorded; the refusal remembered for those values', array( record_of( $id ), $memo_of( $id ) ), array( '', TCGiant_Sync_Listing_Options::relist_state( $cat_xml . "\n", array() ) ) );
	check( '  said in one line, by the tab\'s label, with eBay\'s words; the relist line names no option', array( logged( "$source: WC #$id relisted without its second category, which eBay refused: The category selected is not a leaf category; fix or empty it on the eBay tab. Until then, its relists go without it." ), logged( 'with its second category' ) ), array( 'warning', null ) );

	// The memo holds: the same values go without them from the start.
	$m1_item( $id, $source, array( 'second_category' => '11116' ) );
	list( $res, $sites, $bodies ) = $m1_relist( $id, $source, array() );
	check( '  its next relist, the values unchanged: without them from the start, one RelistItem', array( $res, $sites, carries_any( $bodies[0] ?? '' ), record_of( $id ) ), array( '110000000001', array_merge( $lead, array( 'RelistItem@0' ) ), false, '' ) );
	check( '  said', logged( "$source: WC #$id - its relist goes without its second category: eBay refused a relist with it as it is now. Fix or empty it on the eBay tab to send it again." ), 'warning' );

	// A changed value is tried again.
	$m1_item( $id, $source, array( 'second_category' => '22222' ) );
	list( $res, $sites, $bodies ) = $m1_relist( $id, $source, array() );
	check( '  the second category changed: sent again, taken, recorded, the memo gone', array( $res, count( $sites ), el( $bodies[0] ?? '', 'SecondaryCategory' ), record_of( $id )['second_category'] ?? null, get_post_meta( $id, TCGiant_Sync_Listing_Options::RELIST_MEMO, true ) ), array( '110000000001', count( $lead ) + 1, '<CategoryID>22222</CategoryID>', '22222', '' ) );

	// Refused without them too: not the options; the next relist costs what 3.37.0's did.
	$m1_item( $id + 1, $source, array( 'second_category' => '11116' ) );
	list( $res, $sites, $bodies ) = $m1_relist( $id + 1, $source, array( $not_leaf, $other ) );
	check( '  refused without them too: two RelistItem, not relisted', array( $res, $sites, carries_any( $bodies[1] ?? '' ) ), array( $failed, array_merge( $lead, array( 'RelistItem@0', 'RelistItem@0' ) ), false ) );
	check( '  said, with both answers', logged( "$source: eBay refused the relist of WC #" . ( $id + 1 ) . ' with its second category (The category selected is not a leaf category), and once more without it: Something else about the listing. Its relists go without it for a week, or until it is changed on the eBay tab, then it is sent again.' ), 'warning' );
	$m1_item( $id + 1, $source, array( 'second_category' => '11116' ) );
	list( $res, $sites ) = $m1_relist( $id + 1, $source, array( $other ) );
	check( '  its next relist, refused for that other reason: one RelistItem, as 3.37.0 spent', array( $res, $sites ), array( $failed, array_merge( $lead, array( 'RelistItem@0' ) ) ) );

	// A budget guard refusing the retry: nothing more goes.
	$m1_item( $id + 2, $source, array( 'second_category' => '11116' ) );
	list( $res, $sites ) = $m1_relist( $id + 2, $source, array(
		function () use ( $spend, $not_leaf ) {
			$spend();
			return $not_leaf;
		},
		'guard',
	) );
	delete_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ) );
	check( '  the day\'s calls spent before it would go once more: the API\'s own guard sends nothing, one RelistItem went', array( $res, $sites, logged( 'Blocking RelistItem' ) ), array( 'Push' === $source ? 'not relisted' : 'rate_limited', array_merge( $lead, array( 'RelistItem@0' ) ), 'warning' ) );
	check( '  said, in the guard\'s words', logged( "$source: eBay refused the relist of WC #" . ( $id + 2 ) . ' with its second category (The category selected is not a leaf category), and once more without it: Daily API call budget exhausted. Will resume tomorrow. Its relists go without it for a week, or until it is changed on the eBay tab, then it is sent again.' ), 'warning' );
	$m1_item( $id + 2, $source, array( 'second_category' => '11116' ) );
	list( $res, $sites, $bodies ) = $m1_relist( $id + 2, $source, array() );
	check( '  the next run, calls to spare: relisted without it, one RelistItem', array( $res, $sites, carries_any( $bodies[0] ?? '' ) ), array( '110000000001', array_merge( $lead, array( 'RelistItem@0' ) ), false ) );
}

// Not sent again: a refusal that cannot be the options', or a relist eBay may have made.
$m1_once = function ( $id, $answer ) use ( $m1_item, $m1_relist ) {
	$m1_item( $id, 'Auto-Relist', array( 'second_category' => '11116' ) );
	list( $res, $sites ) = $m1_relist( $id, 'Auto-Relist', array( $answer ) );
	return array( $res, count( $sites ), get_post_meta( $id, TCGiant_Sync_Listing_Options::RELIST_MEMO, true ) );
};
check( 'Auto-Relist refused as an ended listing: not sent again, nothing remembered', $m1_once( 3201, trading_error( '291', 'This listing has ended and cannot be relisted.' ) ), array( 'api_error', 2, '' ) );
check( '  no answer from eBay (a transport failure): not sent again', $m1_once( 3202, new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ), array( 'http_request_failed', 2, '' ) );
check( '  refused, but sent again after a timeout first, which eBay may have made: not sent again', $m1_once( 3203, function ( $api ) use ( $not_leaf ) {
	$api->resent();
	return $not_leaf;
} ), array( 'api_error', 2, '' ) );
check( '  eBay saying it was a second sending of a request it took (488): not sent again', $m1_once( 3204, trading_error( '488', 'Duplicate UUID used.' ) ), array( 'api_error', 2, '' ) );
check( '  eBay\'s call limit, or this site\'s own guard, on the first: not sent again', $m1_once( 3205, new WP_Error( 'rate_limited', 'Calls to this call have exceeded the call limit.' ) ), array( 'rate_limited', 2, '' ) );
item( 3206, array( '_ebay_item_id' => '110000003206', '_ebay_listing_type' => 'Chinese', '_ebay_listing_status' => 'Ended' ) + $PUSHED );
list( $res, $sites ) = $m1_relist( 3206, 'Auto-Relist', array( $not_leaf ) );
check( '  a product with no listing options, refused: one RelistItem, as 3.37.0', array( $res, $sites ), array( 'api_error', array( 'GetItem@0', 'RelistItem@0' ) ) );

// A relist that only took a field off: the same, and the old record left as it was.
$m1_item( 3207, 'Bulk relist', array(), array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000003207', 'condition_note' => 'Old note' ) ) );
list( $res, $sites, $bodies ) = $m1_relist( 3207, 'Bulk relist', array( $not_leaf ) );
check( 'a relist refused while taking off a condition note it sent: once more without the DeletedField, relisted', array( $res, deleted_of( $bodies[0] ?? '' )[0], $bodies[1] ?? null ), array( '110000000001', array( 'Item.ConditionDescription' ), "\n<Item>\n<ItemID>110000003207</ItemID>\n</Item>" ) );
check( '  said; the old listing\'s record left as it was: it names no listing the product follows', array( logged( 'Bulk relist: WC #3207 relisted without taking off its condition note, which eBay refused: The category selected is not a leaf category; fix or empty it on the eBay tab. Until then, its relists go without it.' ), record_of( 3207 ) ), array( 'warning', array( 'item' => '110000003207', 'condition_note' => 'Old note' ) ) );
check( 'relist_state(): its elements and DeletedField values, each value in them; nothing for none', array( TCGiant_Sync_Listing_Options::relist_state( '', array() ), TCGiant_Sync_Listing_Options::relist_state( '', array( 'Item.SubTitle' ) ) === TCGiant_Sync_Listing_Options::relist_state( '', array( 'Item.ConditionDescription' ) ), TCGiant_Sync_Listing_Options::relist_state( "<SubTitle>A</SubTitle>\n", array() ) === TCGiant_Sync_Listing_Options::relist_state( "<SubTitle>B</SubTitle>\n", array() ) ), array( '', false, false ) );
$m1_item( 3208, 'Auto-Relist', array( 'subtitle' => 'Sub', 'second_category' => '11116' ) );
$m1_relist( 3208, 'Auto-Relist', array( $not_leaf ) );
check( 'two fields: named together', logged( 'Auto-Relist: WC #3208 relisted without its subtitle and second category, which eBay refused: The category selected is not a leaf category; fix or empty them on the eBay tab. Until then, its relists go without them.' ), 'warning' );
$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-exporter.php' );
check( 'the second try is the first one\'s own way out ($attempt(): $send(), through TCGiant_Sync_API), never a call of its own', array( substr_count( $src, '$result    = $attempt();' ), substr_count( $src, '$result        = $attempt();' ) ), array( 1, 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 2 - ITEM 5: A RELIST LEAVES OUT A BUY IT NOW UNDER ITS SITE'S MINIMUM\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
$bin_relist = function ( $id, $bin, $site, array $more = array() ) use ( $PUSHED ) {
	$ended = '11000000' . $id;
	item( $id, opts( array( 'buy_it_now' => $bin ) ) + array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_status' => 'Ended', '_ebay_item_id' => $ended ) + $PUSHED );
	$GLOBALS['W']['log'] = array();
	list( $res, , $calls ) = relist_it( $id, ended_in( $ended, 'eBayMotors' === $site ? '33564' : '31387', array_merge( array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00', 'Quantity' => '1', 'Site' => $site ), $more ) ), 'Auto-Relist' );
	$relist = '';
	foreach ( $calls as $call ) {
		if ( 'RelistItem' === $call[0] ) {
			$relist = $call[1];
		}
	}
	return array( is_wp_error( $res ) ? $res->get_error_code() : $res['item_id'], el( $relist, 'BuyItNowPrice' ) );
};
$went = array( '110000000001', null );
check( 'eBay US, 30%: a Buy It Now of 110.50 against an opening bid of 85.00 goes ("at least", in cents)', $bin_relist( 3301, '110.50', 'US' ), array( '110000000001', '110.50' ) );
check( '  110.49 is left out, and the relist goes', $bin_relist( 3302, '110.49', 'US' ), $went );
check( '  100.00, 17.6% above (the review\'s probe P5): left out, the relist going without one, said', array( $bin_relist( 3303, '100', 'US' ), logged( 'Auto-Relist: WC #3303 - the Buy It Now price not sent: the Buy It Now price 100.00 is not 30% above the opening bid 85.00, the least eBay takes on the listing\'s site (US); the relist goes without one.' ) ), array( $went, 'info' ) );
check( '  the ended listing with a Buy It Now of its own: the relist keeps it, and says so', array( $bin_relist( 3304, '100', 'US', array( 'BuyItNowPrice' => array( '@attributes' => array( 'currencyID' => 'USD' ), 0 => '120.0' ) ) ), logged( 'the least eBay takes on the listing\'s site (US); the relist keeps the ended listing\'s Buy It Now 120.00.' ) ), array( $went, 'info' ) );
check( 'eBay Motors, 30%: 110.50 goes, 110.49 left out', array( $bin_relist( 3311, '110.50', 'eBayMotors' ), $bin_relist( 3312, '110.49', 'eBayMotors' ) ), array( array( '110000000001', '110.50' ), $went ) );
check( 'eBay Australia, 30%: 110.50 goes, 110.49 left out', array( $bin_relist( 3321, '110.50', 'Australia' ), $bin_relist( 3322, '110.49', 'Australia' ) ), array( array( '110000000001', '110.50' ), $went ) );
check( 'eBay Canada, 40%: 119.00 goes, 118.99 left out; eBay UK too', array( $bin_relist( 3331, '119', 'Canada' ), $bin_relist( 3332, '118.99', 'Canada' ), $bin_relist( 3333, '115', 'UK' ), $bin_relist( 3334, '119', 'UK' ) ), array( array( '110000000001', '119.00' ), $went, $went, array( '110000000001', '119.00' ) ) );
check( 'a site not in the table (eBay Austria): 40%', array( $bin_relist( 3341, '118.99', 'Austria' ), $bin_relist( 3342, '119', 'Austria' ) ), array( $went, array( '110000000001', '119.00' ) ) );
check( 'a site not known: 40%, and said so', array( $bin_relist( 3343, '118.99', '' ), logged( 'is not 40% above the opening bid 85.00, the least eBay takes on the listing\'s site (not known)' ), $bin_relist( 3344, '119', '' ) ), array( $went, 'info', array( '110000000001', '119.00' ) ) );
check( 'no opening bid in eBay\'s answer (the review\'s probe P6): the Buy It Now cannot be judged, and is left out; the relist goes', array( $bin_relist( 3351, '1', 'US', array( 'StartPrice' => null ) ), logged( 'Auto-Relist: WC #3351 - the Buy It Now price not sent: eBay\'s answer for the ended listing gives no opening bid to judge it by; the relist goes without one.' ) ), array( $went, 'info' ) );
check( 'buy_it_now_minimum(): eBay US, eBay Motors and eBay Australia 30; Canada, UK, Germany, any other and none 40', array_map( array( 'TCGiant_Sync_Listing_Options', 'buy_it_now_minimum' ), array( 'US', 'eBayMotors', 'Australia', 'Canada', 'UK', 'Germany', 'France', '', null, array() ) ), array( 30, 30, 30, 40, 40, 40, 40, 40, 40, 40 ) );
check( 'buy_it_now_clears(): at least the share, in cents, equal included', array( TCGiant_Sync_Listing_Options::buy_it_now_clears( '110.50', '85.00', 30 ), TCGiant_Sync_Listing_Options::buy_it_now_clears( '110.49', '85.00', 30 ), TCGiant_Sync_Listing_Options::buy_it_now_clears( '119.00', '85.00', 40 ), TCGiant_Sync_Listing_Options::buy_it_now_clears( '118.99', '85.00', 40 ), TCGiant_Sync_Listing_Options::buy_it_now_clears( '1.30', '1.00', 30 ), TCGiant_Sync_Listing_Options::buy_it_now_clears( '1.29', '1.00', 30 ) ), array( true, false, true, false, true, false ) );
shop( '31387', 'Chinese' );
list( $res, $sites, $calls ) = push( item( 3361, opts( array( 'buy_it_now' => '100' ) ) ) );
check( 'a new listing with a Buy It Now 17.6% above its opening bid: sent, eBay\'s own refusal for it shown, as before', array( code_of( $res ), $sites, el( $calls[0][1] ?? '', 'BuyItNowPrice' ) ), array( 'created', array( 'AddItem@0' ), '100.00' ) );
check( '  its Verify the same', el( verify_item( item( 3362, opts( array( 'buy_it_now' => '100' ) ) ) ), 'BuyItNowPrice' ), '100.00' );
shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 2 - L-2, L-3, L-4 AND L-6\n" . str_repeat( '=', 124 ) . "\n";

// L-2: an ended auction with a sale reaches the push's relist.
shop( '31387', 'Chinese' );
fresh_ebay();
$API->answers['ReviseItem'] = new WP_Error( 'api_error', 'Auction has already been closed.' );
$API->answers['GetItem']    = ended_in( '110000003401', '31387', array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00', 'ReservePrice' => '130.00', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '1', 'ListingStatus' => 'Completed' ) ) );
item( 3401, opts( array( 'reserve' => '120', 'buy_it_now' => '100' ) ) + array( '_ebay_item_id' => '110000003401', '_ebay_listing_type' => 'Chinese', '_ebay_live' => array( 'item_id' => '110000003401', 'category_id' => '31387', 'condition_id' => '3000' ) ) + $IMPORTED, array( 'stock' => 3 ) );
list( $res, $sites, $calls ) = push( wc_get_product( 3401 ) );
unset( $API->answers['ReviseItem'], $API->answers['GetItem'] );
check( 'L-2: a push of an imported auction that ended with a sale and stock left, reserve 120, Buy It Now 100 (the review\'s probe P2): relisted, not refused by the new-listing rule', array( code_of( $res ), message_of( $res ), $sites ), array( 'relisted', '', array( 'ReviseItem@0', 'GetItem@0', 'RelistItem@0' ) ) );
check( '  the reserve, under the ended listing\'s 130, goes; the Buy It Now, not above it, is left out as any relist leaves it', array( el( $calls[2][1] ?? '', 'ReservePrice' ), el( $calls[2][1] ?? '', 'BuyItNowPrice' ) ), array( '120.00', null ) );
list( $res, $sites ) = push( item( 3402, opts( array( 'reserve' => '120', 'buy_it_now' => '100' ) ) ) );
check( '  a new listing of the same is still refused before eBay', array( code_of( $res ), $sites ), array( 'missing_export_settings', array() ) );

// L-3: a live auction's Update sends 3.37.0's item specifics exactly.
$live_auction = array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000003501' );
list( , , $calls ) = push( item( 3501, $live_auction ) );
$plain = no_uuid( $calls[0][1] ?? '' );
$GLOBALS['W']['log'] = array();
list( $res, $sites, $calls ) = push( item( 3501, $live_auction + opts( array( 'brand' => 'Zeta', 'mpn' => 'ZT-1', 'subtitle' => 'Sub', 'buy_it_now' => '150' ) ) ) );
check( 'L-3: a pushed live auction\'s Update with Brand, MPN, a subtitle and Buy It Now (the review\'s probe P1): byte for byte the one with none', array( code_of( $res ), $sites, no_uuid( $calls[0][1] ?? '' ) ), array( 'updated', array( 'ReviseItem@0' ), $plain ) );
check( '  its item specifics as 3.37.0 sent them, and said with the rest once eBay answered, not as an Update with no other item specifics', array( specifics_of( $calls[0][1] ?? '' ), logged( 'Push: WC #3501 - the subtitle is not sent to a live auction' ), logged( '; the Brand and MPN fields are not sent to a live auction, whose item specifics eBay does not let change once it has a bid or is within 12 hours of its end: its item specifics go without them, as TCGiant Sync has no record of this listing being sent them (listed before 3.38.0, relisted on eBay, or linked by the import after an unfinished push), and a new listing takes the fields.' ), logged( 'Push: WC #3501 - its Brand and MPN were not sent' ) ), array( array( 'Colour=Red' ), 'info', 'info', null ) );
list( $res, , $calls ) = push( item( 3502, opts( array( 'brand' => 'Zeta', 'mpn' => 'ZT-1' ) ) + array( '_ebay_item_id' => '110000003502', '_ebay_listing_type' => 'Chinese', '_ebay_live' => array( 'item_id' => '110000003502', 'category_id' => '31387', 'condition_id' => '3000' ) ) + $IMPORTED ) );
check( '  an imported live auction\'s: its item specifics as 3.37.0 sent them, no Brand or MPN', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ) ), array( 'updated', array( 'Colour=Red' ) ) );
shop();
list( $res, , $calls ) = push( item( 3503, opts( array( 'brand' => 'Zeta' ) ) + array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000003503' ) ) );
check( '  a pushed auction revised while the shop defaults to Fixed Price: a live auction by its recorded format, no Brand', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ) ), array( 'updated', array( 'Colour=Red' ) ) );
list( , , $calls ) = push( item( 3504, opts( array( 'brand' => 'Zeta' ) ) + $PUSHED + array( '_ebay_item_id' => '110000003504' ), array( 'attributes' => array() ) ) );
check( '  the next product, a fixed-price Update with no other item specifics: held back for that, and said so, nothing of a live auction', array( specifics_of( $calls[0][1] ?? '' ), logged( 'Push: WC #3504 - its Brand and MPN were not sent: this Update sends no other item specifics' ), logged( 'Push: WC #3504 - the Brand and MPN fields are not sent to a live auction' ) ), array( null, 'info', null ) );
list( , , $calls ) = push( item( 3506, opts( array( 'brand' => 'Zeta' ) ) ) );
check( '  and a new listing: Brand among its item specifics, nothing of a live auction', array( specifics_of( $calls[0][1] ?? '' ), logged( 'Push: WC #3506 - the Brand and MPN fields' ) ), array( array( 'Brand=Zeta', 'Colour=Red' ), null ) );
shop( '31387', 'Chinese' );
list( , $sites, $calls ) = push( item( 3507, opts( array( 'brand' => 'Zeta' ) ) ) );
check( '  and a new auction: AddItem with Brand among its item specifics', array( $sites, specifics_of( $calls[0][1] ?? '' ) ), array( array( 'AddItem@0' ), array( 'Brand=Zeta', 'Colour=Red' ) ) );
shop();
item( 3505, opts( array( 'brand' => 'Zeta' ) ) + array( '_ebay_listing_type' => 'Chinese', '_tcgiant_tpl_override' => $table ) + $PUSHED + array( '_ebay_item_id' => '110000003505' ) );
check( '  Re-send to eBay of a live auction\'s description, as its Update would: no Brand in its table', false !== strpos( $resend( 3505 )['html'], 'Zeta' ), false );

// L-4: a subtitle eBay would not take off while a Best Offer was pending.
fresh_ebay();
item( 3601, $PUSHED + array( '_ebay_item_id' => '110000003601', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000003601', 'subtitle' => 'Old' ) ) );
$API->answers['ReviseFixedPriceItem'] = trading_error( '10039', 'The title or subtitle cannot be changed if an auction-style listing has a bid or ends within 12 hours, or a fixed price listing has a sale or a pending Best Offer.' );
push_whole( 3601 );
unset( $API->answers['ReviseFixedPriceItem'] );
check( 'L-4: an Update refused with 10039 while taking the subtitle off: the line says how to take it off once a pending Best Offer is answered', logged( 'Push: eBay would not take the subtitle off eBay listing 110000003601 of WC #3601 (its error 10039), so it stays on that listing, and TCGiant Sync no longer tries to take it off. Press Update again to send the product\'s other changes. If a Best Offer was pending, answer it, then type the subtitle in again, Update, empty it and Update.' ), 'warning' );
update_post_meta( 3601, TCGiant_Sync_Listing_Options::SUBTITLE_META, 'Old' );
$API->answers['ReviseFixedPriceItem'] = array( 'Ack' => 'Success', 'ItemID' => '110000003601' );
list( , $calls ) = push_whole( 3601 );
check( '  that sequence: the subtitle typed in again and Updated is sent, and recorded as sent', array( el( $calls[0][1] ?? '', 'SubTitle' ), record_of( 3601 ) ), array( 'Old', array( 'item' => '110000003601', 'subtitle' => 'Old' ) ) );
delete_post_meta( 3601, TCGiant_Sync_Listing_Options::SUBTITLE_META );
list( , $calls ) = push_whole( 3601 );
check( '  emptied and Updated: taken off by DeletedField, the record emptied', array( deleted_of( $calls[0][1] ?? '' )[0], record_of( 3601 ) ), array( array( 'Item.SubTitle' ), '' ) );
unset( $API->answers['ReviseFixedPriceItem'] );
update_post_meta( 3602, TCGiant_Sync_Listing_Options::SENT_META, array( 'item' => '110000003602', 'second_category' => '261328' ) );
check( '  10031, the second category: no Best Offer sentence', false !== strpos( TCGiant_Sync_Listing_Options::refused_take_off( 3602, '110000003602', array( 'second_category' ), trading_error( '10031', 'Second Category can not be removed from the item.' ), 'Push' ), 'Best Offer' ), false );

// L-6: a new listing's Buy It Now is held to the reserve typed.
shop( '31387', 'Chinese' );
list( $res ) = push( item( 3701, opts( array( 'reserve' => '80', 'buy_it_now' => '90' ) ) ) );
check( 'L-6: a new auction, reserve 80 not above its opening bid 85, Buy It Now 90 (the review\'s probe P11): one refusal, the reserve - the Buy It Now, above 85, is above the reserve typed too', message_of( $res ), 'The reserve price 80.00 is not above the opening bid 85.00. eBay takes a Buy It Now price only above the opening bid and the reserve price, and a reserve price only above the opening bid: change it on the product\'s eBay tab (More listing options), or empty it, then push again. Nothing was sent to eBay.' );
list( $res ) = push( item( 3702, opts( array( 'reserve' => '100', 'buy_it_now' => '90' ) ) ) );
check( '  the reserve raised to 100 (P11b): the Buy It Now 90 not above it', 0 === strpos( message_of( $res ), 'The Buy It Now price 90.00 is not above the reserve price 100.00. ' ), true );
$plan = TCGiant_Sync_Listing_Options::plan( item( 3703, opts( array( 'reserve' => '80', 'buy_it_now' => '80' ) ) ), array( 'mode' => 'new', 'auction' => true, 'start_price' => '85.00' ) );
check( '  every rule one breaks, in one refusal', 0 === strpos( $plan['refused'], 'The reserve price 80.00 is not above the opening bid 85.00; the Buy It Now price 80.00 is not above the opening bid 85.00. ' ), true );
shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 2 - L-7: THE REVIEW'S SURVIVING MUTANTS R01, R03, R04 AND R20\n" . str_repeat( '=', 124 ) . "\n";

// R01 and R04: the live revise of a relist made on eBay reads eBay's answer.
shop();
fresh_ebay();
$followed = function ( $id, array $fields, array $record = array() ) use ( $PUSHED ) {
	$old  = '11000000' . $id;
	$new  = '11000001' . $id;
	$meta = opts( $fields ) + $PUSHED + array( '_ebay_item_id' => $old );
	if ( $record ) {
		$meta[ TCGiant_Sync_Listing_Options::SENT_META ] = array( 'item' => $new ) + $record;
	}
	item( $id, $meta );
	$GLOBALS['API']->site_answers = array(
		'ReviseFixedPriceItem@0#' . $old => new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' ),
		'GetItem@0#' . $old              => ended_in( $old, '31387', array( 'ListingDetails' => array( 'RelistedItemID' => $new ) ) ),
		'GetItem@0#' . $new              => ended_in( $new, '31387', array( 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Active' ) ) ),
	);
	$GLOBALS['W']['log'] = array();
	return $new;
};
$new = $followed( 3801, array(), array( 'subtitle' => 'Old' ) );
$API->site_answers[ 'ReviseFixedPriceItem@0#' . $new ] = trading_error( '10039', 'The title or subtitle cannot be changed if an auction-style listing has a bid or ends within 12 hours, or a fixed price listing has a sale or a pending Best Offer.' );
list( $sites, $calls ) = push_whole( 3801 );
check( 'R01: a push that follows a relist made on eBay and revises it live, an emptied subtitle eBay refuses with 10039: the record drops it', array( $sites, deleted_of( $calls[3][1] ?? '' )[0], record_of( 3801 ), logged( 'Push: eBay would not take the subtitle off eBay listing ' . $new . ' of WC #3801 (its error 10039)' ) ), array( array( 'ReviseFixedPriceItem@0', 'GetItem@0', 'GetItem@0', 'ReviseFixedPriceItem@0' ), array( 'Item.SubTitle' ), '', 'warning' ) );
$new = $followed( 3802, array( 'subtitle' => 'Sub', 'condition_note' => 'A note.' ) );
$API->site_answers[ 'ReviseFixedPriceItem@0#' . $new ] = array( 'Ack' => 'Warning', 'ItemID' => $new, 'Errors' => array( 'ErrorCode' => '12317', 'ShortMessage' => 'Dropped.', 'LongMessage' => 'Dropped.', 'SeverityCode' => 'Warning' ) );
list( $sites, $calls ) = push_whole( 3802 );
check( '  a filled subtitle eBay answers with Warning 12317 there: recorded without it, said', array( el( $calls[3][1] ?? '', 'SubTitle' ), record_of( 3802 ), logged( 'Push: eBay took listing ' . $new . ' of WC #3802 without the subtitle (its warning 12317)' ) ), array( 'Sub', array( 'item' => $new, 'condition_note' => 'A note.' ), 'info' ) );
$new = $followed( 3803, array( 'buy_it_now' => '150' ) );
$API->site_answers[ 'ReviseFixedPriceItem@0#' . $new ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
push_whole( 3803 );
check( 'R04: that live revise answered as ended: what its build left out is not said', logged( 'Push: WC #3803 - the Buy It Now price is for an auction' ), null );
$API->site_answers = array();

// R03: a warning for a field that was not sent says nothing of it.
item( 3811, opts( array( 'condition_note' => 'A note.' ) ) );
$API->answers['AddFixedPriceItem'] = array( 'Ack' => 'Warning', 'ItemID' => '110000000001', 'Errors' => array( 'ErrorCode' => '12317', 'ShortMessage' => 'Dropped.', 'LongMessage' => 'Dropped.', 'SeverityCode' => 'Warning' ) );
$GLOBALS['W']['log'] = array();
push_whole( 3811 );
unset( $API->answers['AddFixedPriceItem'] );
check( 'R03: Warning 12317 on a listing sent no subtitle: nothing said of one, the note recorded', array( logged( 'without the subtitle' ), record_of( 3811 ) ), array( null, array( 'item' => '110000000001', 'condition_note' => 'A note.' ) ) );
check( '  accepted(): only a field the state holds', TCGiant_Sync_Listing_Options::accepted( array( 'condition_note' => 'A note.' ), array( 'Errors' => array( 'ErrorCode' => '12317', 'SeverityCode' => 'Warning' ) ) ), array( array( 'condition_note' => 'A note.' ), array() ) );

// R20: "not sent" said again when the reserve alone changes.
item( 3821, opts( array( 'reserve' => '120' ) ) );
$said = array();
foreach ( array( '120', '120', '130', '130' ) as $reserve ) {
	update_post_meta( 3821, TCGiant_Sync_Listing_Options::RESERVE_META, $reserve );
	$GLOBALS['W']['log'] = array();
	push( wc_get_product( 3821 ) );
	$said[] = logged( 'Push: WC #3821 - the reserve price is for an auction, and this listing is fixed-price: not sent' );
}
check( 'R20: a fixed-price product\'s reserve: "not sent" said once, and again when the reserve alone changes', $said, array( 'info', null, 'info', null ) );


// Two earlier mutants this round's rules met by another road (the build's
// A03; fix round 1's F503 and F504): checked by what only their own rule does.
shop();
fresh_ebay();
item( 3831, opts( array( 'buy_it_now' => '150', 'reserve' => '120' ) ) + array( '_ebay_listing_status' => 'Ended', '_ebay_item_id' => '110000003831' ) + $PUSHED );
list( $res, , $calls ) = relist_it( 3831, ended_in( '110000003831', '31387' ) );
check( 'a fixed-price listing\'s relist with Buy It Now and reserve filled: neither sent, said as a fixed-price listing\'s, never judged as an auction\'s', array( is_array( $res ) ? $res['item_id'] : code_of( $res ), el( $calls[1][1] ?? '', 'BuyItNowPrice' ), el( $calls[1][1] ?? '', 'ReservePrice' ), logged( 'Bulk relist: WC #3831 - the Buy It Now price is for an auction, and this listing is fixed-price: not sent; the reserve price is for an auction, and this listing is fixed-price: not sent.' ) ), array( '110000000001', null, null, 'info' ) );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = ended_in( '110000003841', '31387', array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00', 'Quantity' => '1' ) );
item( 3841, opts( array( 'brand' => 'Zeta' ) ) + array( '_ebay_item_id' => '110000003841', '_tcgiant_tpl_override' => $table, '_ebay_live' => array( 'item_id' => '110000003841', 'category_id' => '31387', 'condition_id' => '3000' ) ) + $IMPORTED );
list( $sites, $calls ) = push_whole( 3841 );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
check( 'a push\'s relist of an imported auction recorded here as fixed-price (eBay\'s answer says auction): RelistItem\'s description has no Brand in its table either', array( $sites, $in_desc( $calls[2][1] ?? '' ), specifics_of( $calls[2][1] ?? '' ) ), array( array( 'ReviseFixedPriceItem@0', 'GetItem@0', 'RelistItem@0' ), false, null ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 3 - F-1: A REFUSAL THE PLAIN RELIST WAS NOT TAKEN AFTER HOLDS THE OPTIONS FOR A WEEK\n" . str_repeat( '=', 124 ) . "\n";

shop();
fresh_ebay();
$limit = trading_error( '21919188', 'You have reached the number of items you can list.' );
$memo  = function ( $id ) {
	return explode( '>', (string) get_post_meta( $id, TCGiant_Sync_Listing_Options::RELIST_MEMO, true ) );
};
// The memo's time moved back by this many seconds, as if the refusal were older.
$older = function ( $id, $seconds ) use ( $memo ) {
	$parts    = $memo( $id );
	$parts[1] = (string) ( (int) $parts[1] - $seconds );
	update_post_meta( $id, TCGiant_Sync_Listing_Options::RELIST_MEMO, implode( '>', $parts ) );
};
$week = TCGiant_Sync_Exporter::RELIST_PHOTOS_MEMO_DAYS * DAY_IN_SECONDS;

// Review 3's probe C: refused with and without (the selling limit), then the limit lifts.
$m1_item( 3901, 'Auto-Relist', array( 'second_category' => '11116' ) );
list( $res, $sites ) = $m1_relist( 3901, 'Auto-Relist', array( $limit, $limit ) );
$m = $memo( 3901 );
check( 'F-1: refused with its second category and once more without (the selling limit, review 3\'s probe C): remembered, expiring', array( $res, $sites, $m[0], $m[2] ?? null, abs( (int) $m[1] - time() ) < 60 ), array( 'api_error', array( 'GetItem@0', 'RelistItem@0', 'RelistItem@0' ), TCGiant_Sync_Listing_Options::relist_state( $cat_xml . "\n", array() ), '0', true ) );
$m1_item( 3901, 'Auto-Relist', array( 'second_category' => '11116' ) );
list( $res, $sites, $bodies ) = $m1_relist( 3901, 'Auto-Relist', array() );
check( '  the limit lifted, within the week: held, one RelistItem without it, relisted; the memo still expiring', array( $res, $sites, carries_any( $bodies[0] ?? '' ), $memo( 3901 )[2] ?? null ), array( '110000000001', array( 'GetItem@0', 'RelistItem@0' ), false, '0' ) );
check( '  said, with until when, and that the refusal may not have been about it', logged( 'Auto-Relist: WC #3901 - its relist goes without its second category until ' . gmdate( 'Y-m-d H:i', (int) $memo( 3901 )[1] + $week ) . ' UTC: eBay refused a relist with it, and the relist without it did not go either, so the refusal may not have been about it. Changed on the eBay tab, it is sent again at once.' ), 'warning' );
$m1_item( 3901, 'Auto-Relist', array( 'second_category' => '11116' ) );
$older( 3901, $week + 1 );
list( $res, $sites, $bodies ) = $m1_relist( 3901, 'Auto-Relist', array() );
check( '  a week after the refusal: sent with it again, taken, recorded, the memo gone', array( $res, $sites, el( $bodies[0] ?? '', 'SecondaryCategory' ), record_of( 3901 )['second_category'] ?? null, get_post_meta( 3901, TCGiant_Sync_Listing_Options::RELIST_MEMO, true ) ), array( '110000000001', array( 'GetItem@0', 'RelistItem@0' ), '<CategoryID>11116</CategoryID>', '11116', '' ) );
$m1_item( 3902, 'Auto-Relist', array( 'second_category' => '11116' ) );
$m1_relist( 3902, 'Auto-Relist', array( $limit, $limit ) );
$older( 3902, $week - 60 );
$m1_item( 3902, 'Auto-Relist', array( 'second_category' => '11116' ) );
list( , $sites, $bodies ) = $m1_relist( 3902, 'Auto-Relist', array() );
check( '  a minute short of the week: still held, without it', array( $sites, carries_any( $bodies[0] ?? '' ) ), array( array( 'GetItem@0', 'RelistItem@0' ), false ) );
$m1_item( 3902, 'Auto-Relist', array( 'second_category' => '11116' ) );
$older( 3902, 120 );
list( , $sites ) = $m1_relist( 3902, 'Auto-Relist', array( $limit, $limit ) );
check( '  past it, eBay still refusing both: tried with it once more, then without, and remembered for another week', array( $sites, abs( (int) $memo( 3902 )[1] - time() ) < 60, $memo( 3902 )[2] ?? null ), array( array( 'GetItem@0', 'RelistItem@0', 'RelistItem@0' ), true, '0' ) );

// Taken without them: they were the cause, held until one changes, a week or not.
$m1_item( 3903, 'Auto-Relist', array( 'second_category' => '11116' ) );
$m1_relist( 3903, 'Auto-Relist', array( $not_leaf ) );
check( 'refused with it and taken without it: the memo lasting', $memo( 3903 )[2] ?? null, '1' );
$m1_item( 3903, 'Auto-Relist', array( 'second_category' => '11116' ) );
$older( 3903, 10 * DAY_IN_SECONDS );
list( , $sites, $bodies ) = $m1_relist( 3903, 'Auto-Relist', array() );
check( '  ten days on: still held, as the line says', array( $sites, carries_any( $bodies[0] ?? '' ), logged( 'Auto-Relist: WC #3903 - its relist goes without its second category: eBay refused a relist with it as it is now. Fix or empty it on the eBay tab to send it again.' ) ), array( array( 'GetItem@0', 'RelistItem@0' ), false, 'warning' ) );

// eBay's 931, which the API takes for an api_error (review 3's probe G).
$token = trading_error( '931', 'Auth token is invalid. Validation of the authentication token in API request failed.' );
$m1_item( 3904, 'Auto-Relist', array( 'second_category' => '11116' ) );
list( $res, $sites ) = $m1_relist( 3904, 'Auto-Relist', array( $token, $token ) );
check( 'eBay\'s 931 both times (review 3\'s probe G): sent again once, remembered for a week only', array( $res, $sites, $memo( 3904 )[2] ?? null ), array( 'api_error', array( 'GetItem@0', 'RelistItem@0', 'RelistItem@0' ), '0' ) );
$m1_item( 3904, 'Auto-Relist', array( 'second_category' => '11116' ) );
$older( 3904, $week + 1 );
list( $res, $sites, $bodies ) = $m1_relist( 3904, 'Auto-Relist', array() );
check( '  the token renewed, a week on: the second category sent again and taken', array( $res, $sites, el( $bodies[0] ?? '', 'SecondaryCategory' ) ), array( '110000000001', array( 'GetItem@0', 'RelistItem@0' ), '<CategoryID>11116</CategoryID>' ) );

// The eBay tab: emptied, then typed again, as the line says (probe C's run 4).
$m1_item( 3905, 'Auto-Relist', array( 'second_category' => '11116' ) );
$m1_relist( 3905, 'Auto-Relist', array( $not_leaf ) );
$tab = function ( $id, array $fields ) {
	$post = array( TCGiant_Sync_Listing_Options::SUBTITLE_META . '_shown' => '1' );
	foreach ( TCGiant_Sync_Listing_Options::METAS as $field => $meta ) {
		$post[ $meta ] = (string) ( $fields[ $field ] ?? get_post_meta( $id, $meta, true ) );
	}
	TCGiant_Sync_Listing_Options::save_posted( $id, $post );
};
$lasting = get_post_meta( 3905, TCGiant_Sync_Listing_Options::RELIST_MEMO, true );
$tab( 3905, array() );
check( 'the eBay tab saved with every value as it was: the memo kept', get_post_meta( 3905, TCGiant_Sync_Listing_Options::RELIST_MEMO, true ), $lasting );
$tab( 3905, array( 'brand' => 'Zeta', 'mpn' => 'ZT-1' ) );
check( '  Brand and MPN changed, which no relist carries: kept', get_post_meta( 3905, TCGiant_Sync_Listing_Options::RELIST_MEMO, true ), $lasting );
$tab( 3905, array( 'second_category' => '' ) );
check( '  the second category emptied: forgotten', get_post_meta( 3905, TCGiant_Sync_Listing_Options::RELIST_MEMO, true ), '' );
$m1_item( 3905, 'Auto-Relist', array() );
list( , $sites, $bodies ) = $m1_relist( 3905, 'Auto-Relist', array() );
check( '  its relist: nothing of the options to send, one RelistItem', array( $sites, carries_any( $bodies[0] ?? '' ) ), array( array( 'GetItem@0', 'RelistItem@0' ), false ) );
update_post_meta( 3905, TCGiant_Sync_Listing_Options::RELIST_MEMO, $lasting );
$tab( 3905, array( 'second_category' => '11116' ) );
check( '  typed in again (a memo of the same value there): forgotten', get_post_meta( 3905, TCGiant_Sync_Listing_Options::RELIST_MEMO, true ), '' );
$m1_item( 3905, 'Auto-Relist', array( 'second_category' => '11116' ) );
list( , $sites, $bodies ) = $m1_relist( 3905, 'Auto-Relist', array() );
check( '  its next relist sends it, as the line said it would', array( $sites, el( $bodies[0] ?? '', 'SecondaryCategory' ) ), array( array( 'GetItem@0', 'RelistItem@0' ), '<CategoryID>11116</CategoryID>' ) );
update_post_meta( 3905, TCGiant_Sync_Listing_Options::RELIST_MEMO, $lasting );
$tab( 3905, array( 'subtitle' => 'New' ) );
check( '  any of the relist\'s fields changed, the subtitle here: forgotten', get_post_meta( 3905, TCGiant_Sync_Listing_Options::RELIST_MEMO, true ), '' );
update_post_meta( 3905, TCGiant_Sync_Listing_Options::RELIST_MEMO, $lasting );
TCGiant_Sync_Listing_Options::save_posted( 3905, array( TCGiant_Sync_Listing_Options::SUBTITLE_META => 'Other' ) );
check( '  a form without the section changes nothing, the memo included', array( get_post_meta( 3905, TCGiant_Sync_Listing_Options::RELIST_MEMO, true ), get_post_meta( 3905, TCGiant_Sync_Listing_Options::SUBTITLE_META, true ) ), array( $lasting, 'New' ) );
check( 'relist_held(): an expiring memo within the week, and past it; a lasting one past it; another state', array(
	( function () {
		update_post_meta( 3906, TCGiant_Sync_Listing_Options::RELIST_MEMO, TCGiant_Sync_Listing_Options::relist_state( 'x', array() ) . '>' . ( time() - 3600 ) . '>0' );
		return TCGiant_Sync_Listing_Options::relist_held( 3906, 'x', array() );
	} )(),
	( function () use ( $week ) {
		update_post_meta( 3906, TCGiant_Sync_Listing_Options::RELIST_MEMO, TCGiant_Sync_Listing_Options::relist_state( 'x', array() ) . '>' . ( time() - $week - 1 ) . '>0' );
		return TCGiant_Sync_Listing_Options::relist_held( 3906, 'x', array() );
	} )(),
	( function () use ( $week ) {
		update_post_meta( 3906, TCGiant_Sync_Listing_Options::RELIST_MEMO, TCGiant_Sync_Listing_Options::relist_state( 'x', array() ) . '>' . ( time() - $week - 1 ) . '>1' );
		return TCGiant_Sync_Listing_Options::relist_held( 3906, 'x', array() );
	} )(),
	TCGiant_Sync_Listing_Options::relist_held( 3906, 'y', array() ),
), array( true, false, true, false ) );
update_post_meta( 3906, TCGiant_Sync_Listing_Options::RELIST_MEMO, '>' . time() . '>1' );
check( '  a relist with no options is never held, whatever the memo says', TCGiant_Sync_Listing_Options::relist_held( 3906, '', array() ), false );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 3 - F-2: A REFUSED RELIST THAT TOOK A FIELD OFF COSTS ONE EXTRA RELISTITEM, NOT TWO\n" . str_repeat( '=', 124 ) . "\n";

// Review 3's probe B: the record names the ended listing with a condition
// note, emptied since; the second category filled.
$m1_item( 3911, 'Auto-Relist', array( 'second_category' => '11116' ), array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000003911', 'condition_note' => 'Old note' ) ) );
list( $res, $sites, $bodies ) = $m1_relist( 3911, 'Auto-Relist', array( $not_leaf ) );
check( 'F-2: refused with the second category and the note\'s DeletedField, then taken without (review 3\'s probe B)', array( $res, $sites, deleted_of( $bodies[0] ?? '' )[0], carries_any( $bodies[1] ?? '' ) ), array( '110000000001', array( 'GetItem@0', 'RelistItem@0', 'RelistItem@0' ), array( 'Item.ConditionDescription' ), false ) );
check( '  remembered, lasting: the set refused, and the set the next relist carries (no DeletedField: the record names the old listing)', array( $memo( 3911 )[0], $memo( 3911 )[2] ?? null ), array( TCGiant_Sync_Listing_Options::relist_state( $cat_xml . "\n", array( 'Item.ConditionDescription' ) ) . ',' . TCGiant_Sync_Listing_Options::relist_state( $cat_xml . "\n", array() ), '1' ) );
update_post_meta( 3911, '_ebay_listing_status', 'Ended' );
$GLOBALS['API']->clear();
$GLOBALS['W']['log'] = array();
list( $res, $sites, $calls ) = relist_it( 3911, ended_in( '110000000001', '31387', array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00', 'Quantity' => '1' ) ), 'Auto-Relist' );
check( '  the new listing ended and relisted, the same values: held from the start, one RelistItem, nothing of the options', array( is_wp_error( $res ) ? $res->get_error_code() : $res['item_id'], $sites, carries_any( $calls[1][1] ?? '' ) ), array( '110000000001', array( 'GetItem@0', 'RelistItem@0' ), false ) );
$m1_item( 3912, 'Auto-Relist', array(), array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000003912', 'condition_note' => 'Old note' ) ) );
$m1_relist( 3912, 'Auto-Relist', array( $not_leaf ) );
check( '  a take-off alone, refused then taken: only its own set (a relist of the new listing carries nothing)', explode( ',', $memo( 3912 )[0] ), array( TCGiant_Sync_Listing_Options::relist_state( '', array( 'Item.ConditionDescription' ) ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 3 - F-3: THE MEMO FOLLOWS THE LISTING: UNLINK AND A DUPLICATE DROP IT\n" . str_repeat( '=', 124 ) . "\n";

check( 'F-3: among the keys a duplicate or an unlink drops', array( in_array( TCGiant_Sync_Listing_Options::RELIST_MEMO, TCGiant_Sync_Listing_Link::listing_identity_meta_keys(), true ), in_array( TCGiant_Sync_Listing_Options::RELIST_MEMO, TCGiant_Sync_Listing_Link::duplicate_exclude_meta( array() ), true ) ), array( true, true ) );
check( '  the options themselves are the product\'s: copied, and kept by an unlink', array_values( array_intersect( array_values( TCGiant_Sync_Listing_Options::METAS ), TCGiant_Sync_Listing_Link::duplicate_exclude_meta( array() ) ) ), array() );
// Review 3's probe D: refused, then the product is unlinked and linked to another listing.
$m1_item( 3921, 'Auto-Relist', array( 'second_category' => '11116' ) );
$m1_relist( 3921, 'Auto-Relist', array( $not_leaf ) );
foreach ( TCGiant_Sync_Listing_Link::listing_identity_meta_keys() as $key ) {
	delete_post_meta( 3921, $key );
}
check( '  probe D: an unlink takes it with the listing', get_post_meta( 3921, TCGiant_Sync_Listing_Options::RELIST_MEMO, true ), '' );
update_post_meta( 3921, '_ebay_item_id', '110000009999' );
update_post_meta( 3921, '_ebay_listing_type', 'Chinese' );
update_post_meta( 3921, '_ebay_listing_status', 'Ended' );
$GLOBALS['API']->clear();
list( , $sites, $calls ) = relist_it( 3921, ended_in( '110000009999', '261328', array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00', 'Quantity' => '1' ) ), 'Auto-Relist' );
check( '  linked to another listing: its relist judged afresh, the second category sent', array( $sites, el( $calls[1][1] ?? '', 'SecondaryCategory' ) ), array( array( 'GetItem@0', 'RelistItem@0' ), '<CategoryID>11116</CategoryID>' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 3 - F-4: REVIEW 3'S THREE CASES (Y04, Y06, Y07)\n" . str_repeat( '=', 124 ) . "\n";

// A relist carrying a take-off, refused with and without, then held.
$m1_item( 3931, 'Auto-Relist', array( 'subtitle' => 'Sub' ), array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000003931', 'condition_note' => 'Old note' ) ) );
list( $res, $sites, $bodies ) = $m1_relist( 3931, 'Auto-Relist', array( $not_leaf, $other ) );
check( 'F-4: a relist with a subtitle and a take-off, refused with and without: two RelistItem, the second plain', array( $res, $sites, el( $bodies[0] ?? '', 'SubTitle' ), deleted_of( $bodies[0] ?? '' )[0], $bodies[1] ?? null ), array( 'api_error', array( 'GetItem@0', 'RelistItem@0', 'RelistItem@0' ), 'Sub', array( 'Item.ConditionDescription' ), "\n<Item>\n<ItemID>110000003931</ItemID>\n</Item>" ) );
$m1_item( 3931, 'Auto-Relist', array( 'subtitle' => 'Sub' ), array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000003931', 'condition_note' => 'Old note' ) ) );
list( $res, $sites, $bodies ) = $m1_relist( 3931, 'Auto-Relist', array( $other ) );
check( '  then held: one RelistItem, no subtitle and no DeletedField - 3.37.0\'s body', array( $sites, deleted_of( $bodies[0] ?? '' )[0], $bodies[0] ?? null ), array( array( 'GetItem@0', 'RelistItem@0' ), array(), "\n<Item>\n<ItemID>110000003931</ItemID>\n</Item>" ) );
check( '  said by what it would carry', logged( 'Auto-Relist: WC #3931 - its relist goes without its subtitle, and taking off its condition note until ' ), 'warning' );
// The same, a take-off alone.
$m1_item( 3932, 'Auto-Relist', array(), array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000003932', 'condition_note' => 'Old note' ) ) );
list( $res, $sites, $bodies ) = $m1_relist( 3932, 'Auto-Relist', array( $not_leaf, $other ) );
check( '  a take-off alone, refused with and without: two RelistItem', array( $res, $sites, deleted_of( $bodies[0] ?? '' )[0] ), array( 'api_error', array( 'GetItem@0', 'RelistItem@0', 'RelistItem@0' ), array( 'Item.ConditionDescription' ) ) );
$m1_item( 3932, 'Auto-Relist', array(), array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000003932', 'condition_note' => 'Old note' ) ) );
list( $res, $sites, $bodies ) = $m1_relist( 3932, 'Auto-Relist', array( $other ) );
check( '  then held: one RelistItem, no DeletedField', array( $sites, deleted_of( $bodies[0] ?? '' )[0], $bodies[0] ?? null ), array( array( 'GetItem@0', 'RelistItem@0' ), array(), "\n<Item>\n<ItemID>110000003932</ItemID>\n</Item>" ) );
// A live auction's Update judged by its format alone (review 3's probe 5).
shop( '31387', 'Chinese' );
fresh_ebay();
$by_format = $PUSHED + array( '_ebay_item_id' => '110000003941' );
unset( $by_format['_ebay_listing_type'] );
list( $res, $sites, $calls ) = push( item( 3941, opts( array( 'brand' => 'Zeta', 'subtitle' => 'Sub' ) ) + $by_format ) );
check( 'a pushed listing with no recorded format, the shop\'s default Auction: a live auction\'s Update by its format alone - no Brand, no subtitle', array( code_of( $res ), $sites, specifics_of( $calls[0][1] ?? '' ), el( $calls[0][1] ?? '', 'SubTitle' ) ), array( 'updated', array( 'ReviseItem@0' ), array( 'Colour=Red' ), null ) );
shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 3 - WOOCOMMERCE BRANDS AS EBAY'S BRAND (OPT-IN)\n" . str_repeat( '=', 124 ) . "\n";

$on  = array( TCGiant_Sync_Listing_Options::WOO_BRAND_SETTING => '1' );
$off = array();
brand_term( 11, 'Wizards' );
brand_term( 12, 'Pokemon', 11 );
brand_term( 13, 'Base Set', 12 );
brand_term( 14, 'Magic', 11 );
brand_term( 21, 'Zeta' );
brand_term( 22, 'Acme' );
brand_term( 23, 'Zeta &amp; Co' );
brand_term( 24, str_repeat( 'Long brand ', 7 ) );
$GLOBALS['BRANDS']['here'] = true;

// Every request, the setting off, then on with no brand terms: as before.
$every = function ( $branded ) use ( $PUSHED, $IMPORTED ) {
	fresh_ebay();
	$out = array();
	foreach ( array( 'new' => array(), 'pushed' => $PUSHED + array( '_ebay_item_id' => '110000004001' ), 'bare' => $PUSHED + array( '_ebay_item_id' => '110000004002' ), 'imported' => $IMPORTED + array( '_ebay_item_id' => '110000004003', '_ebay_live' => array( 'item_id' => '110000004003', 'category_id' => '31387', 'condition_id' => '3000' ) ), 'auction' => array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000004004' ) ) as $case => $meta ) {
		branded( 4000, $branded );
		$product              = item( 4000, $meta, 'bare' === $case ? array( 'attributes' => array() ) : array() );
		$GLOBALS['W']['log']  = array();
		list( , , $calls )    = push( $product );
		$out[ $case ]         = array( no_uuid( $calls[0][1] ?? '' ), $GLOBALS['W']['log'] );
		$out[ $case . ':v' ]  = verify_item( $product );
	}
	item( 4000, array( '_ebay_listing_status' => 'Ended', '_ebay_item_id' => '110000004005', '_ebay_listing_type' => 'Chinese' ) + $PUSHED );
	list( , , $calls ) = relist_it( 4000, ended_in( '110000004005', '31387', array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00', 'Quantity' => '1' ) ), 'Auto-Relist' );
	$out['relist']     = no_uuid( $calls[1][1] ?? '' );
	branded( 4000, array() );
	return $out;
};
shop( '31387', 'FixedPriceItem', $off );
$plain = $every( array() );
check( 'the setting off (its default, never saved): a product with a WooCommerce brand sends what one without sends - new, Update, imported, auction, Verify, relist - and says nothing more', $every( array( 21 ) ), $plain );
shop( '31387', 'FixedPriceItem', array( TCGiant_Sync_Listing_Options::WOO_BRAND_SETTING => '0' ) );
check( '  saved as off', $every( array( 21 ) ), $plain );
shop( '31387', 'FixedPriceItem', array( TCGiant_Sync_Listing_Options::WOO_BRAND_SETTING => 'yes' ) );
check( '  anything but "1" is off', $every( array( 21 ) ), $plain );
shop( '31387', 'FixedPriceItem', $on );
check( 'on, products with no brand: every request as before', $every( array() ), $plain );
$GLOBALS['BRANDS']['here'] = false;
check( 'on, WooCommerce Brands not on the site (no product_brand taxonomy): every request as before, a brand recorded or not', $every( array( 21 ) ), $plain );
$GLOBALS['BRANDS']['here'] = true;
check( 'woo_brand_on(): only "1"', array( TCGiant_Sync_Listing_Options::woo_brand_on() ), array( true ) );

// The value.
$brand_of = function ( array $terms ) {
	branded( 4010, $terms );
	item( 4010 );
	return TCGiant_Sync_Listing_Options::woo_brand_read( 4010 );
};
check( 'one brand: its name', $brand_of( array( 21 ) ), array( 'name' => 'Zeta', 'several' => array() ) );
check( 'a brand and the one below it: the one below, either order', array( $brand_of( array( 11, 12 ) )['name'], $brand_of( array( 12, 11 ) )['name'] ), array( 'Pokemon', 'Pokemon' ) );
check( '  three in a line: the deepest; a level between them not ticked: still one line', array( $brand_of( array( 13, 11, 12 ) )['name'], $brand_of( array( 11, 13 ) )['name'] ), array( 'Base Set', 'Base Set' ) );
check( '  one below another, alone: its own name', $brand_of( array( 12 ) )['name'], 'Pokemon' );
check( 'several that are not one line: none, and their names', array( $brand_of( array( 21, 22 ) ), $brand_of( array( 12, 14 ) ) ), array( array( 'name' => '', 'several' => array( 'Zeta', 'Acme' ) ), array( 'name' => '', 'several' => array( 'Pokemon', 'Magic' ) ) ) );
check( 'the name decoded, and cut to 65 characters as the Brand field is', array( $brand_of( array( 23 ) )['name'], $brand_of( array( 24 ) )['name'] ), array( 'Zeta & Co', TCGiant_Sync_Listing_Options::clean( 'brand', str_repeat( 'Long brand ', 7 ) ) ) );
check( '  (65 characters)', mb_strlen( $brand_of( array( 24 ) )['name'] ), 65 );
check( 'none', $brand_of( array() ), array( 'name' => '', 'several' => array() ) );

// Where it goes, by the Brand field's rules.
fresh_ebay();
branded( 4021, array( 21 ) );
list( $res, $sites, $calls ) = push( item( 4021 ) );
check( 'a new listing: the WooCommerce brand as Brand, first among its item specifics', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ) ), array( 'created', array( 'Brand=Zeta', 'Colour=Red' ) ) );
check( '  Verify too', specifics_of( verify_item( wc_get_product( 4021 ) ) ), array( 'Brand=Zeta', 'Colour=Red' ) );
branded( 4022, array( 11, 12 ) );
list( , , $calls ) = push( item( 4022 ) );
check( '  a brand and the one below it: the one below', specifics_of( $calls[0][1] ?? '' ), array( 'Brand=Pokemon', 'Colour=Red' ) );
branded( 4023, array( 23 ) );
list( , , $calls ) = push( item( 4023 ) );
check( '  its name decoded, and escaped as the request\'s text', array( specifics_of( $calls[0][1] ?? '' ), false !== strpos( $calls[0][1] ?? '', '<Value>Zeta &amp; Co</Value>' ) ), array( array( 'Brand=Zeta &amp; Co', 'Colour=Red' ), true ) );
branded( 4024, array( 21, 22 ) );
$GLOBALS['W']['log'] = array();
list( , , $calls ) = push( item( 4024 ) );
check( 'several brands not one line: no Brand, said once', array( specifics_of( $calls[0][1] ?? '' ), logged( 'Push: WC #4024 - its WooCommerce brands (Zeta, Acme) are not one brand, nor one brand and those above it, so none was sent as eBay\'s Brand. Keep one brand on the product, or type the one to send in the Brand field on its eBay tab.' ), count( array_filter( $GLOBALS['W']['log'], function ( $row ) { return false !== strpos( $row[1], 'WooCommerce brands' ); } ) ) ), array( array( 'Colour=Red' ), 'info', 1 ) );
$several_said = function ( $id, array $meta, array $data = array() ) {
	branded( $id, array( 21, 22 ) );
	$GLOBALS['W']['log'] = array();
	list( , , $calls ) = push( item( $id, $meta, $data ) );
	return array( specifics_of( $calls[0][1] ?? '' ), logged( 'WooCommerce brands' ) );
};
check( '  nothing said where none would have gone: a Brand attribute; an Update with no other item specifics; a live auction\'s Update', array(
	$several_said( 4027, array(), array( 'attributes' => array( new WC_Product_Attribute( 'Colour', array( 'Red' ) ), new WC_Product_Attribute( 'Brand', array( 'Acme' ) ) ) ) ),
	$several_said( 4028, $PUSHED + array( '_ebay_item_id' => '110000004028' ), array( 'attributes' => array() ) ),
	$several_said( 4029, array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000004029' ) ),
), array( array( array( 'Colour=Red', 'Brand=Acme' ), null ), array( null, null ), array( array( 'Colour=Red' ), null ) ) );
check( '  and on an Update whose item specifics go: said', $several_said( 4030, $PUSHED + array( '_ebay_item_id' => '110000004030' ) ), array( array( 'Colour=Red' ), 'info' ) );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = ended_in( '110000004039', '31387' );
branded( 4039, array( 21, 22 ) );
item( 4039, $PUSHED + array( '_ebay_item_id' => '110000004039' ) );
$GLOBALS['W']['log'] = array();
list( $sites ) = push_whole( 4039 );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
check( '  a push that builds twice (its Update finds the listing ended, and a new listing goes in its place): said once', array( $sites, count( array_filter( $GLOBALS['W']['log'], function ( $row ) { return false !== strpos( $row[1], 'its WooCommerce brands' ); } ) ) ), array( array( 'ReviseFixedPriceItem@0', 'GetItem@0', 'AddFixedPriceItem@0' ), 1 ) );
branded( 4025, array( 21 ) );
list( , , $calls ) = push( item( 4025, array(), array( 'attributes' => array( new WC_Product_Attribute( 'Colour', array( 'Red' ) ), new WC_Product_Attribute( 'Brand', array( 'Acme' ) ) ) ) ) );
$with_attr = no_uuid( $calls[0][1] ?? '' );
shop( '31387', 'FixedPriceItem', $off );
list( , , $calls ) = push( item( 4025, array(), array( 'attributes' => array( new WC_Product_Attribute( 'Colour', array( 'Red' ) ), new WC_Product_Attribute( 'Brand', array( 'Acme' ) ) ) ) ) );
check( 'a Brand attribute: it stays, the WooCommerce brand is not sent - the item byte for byte the setting off\'s', array( specifics_of( $with_attr ), $with_attr ), array( array( 'Colour=Red', 'Brand=Acme' ), no_uuid( $calls[0][1] ?? '' ) ) );
shop( '31387', 'FixedPriceItem', $on );
list( , , $calls ) = push( item( 4025, array( '_ebay_export_specifics' => array( 'BRAND' => 'Typed' ) ) ) );
check( '  a Brand typed as an item specific on the tab, by the field\'s name comparison: it stays', specifics_of( $calls[0][1] ?? '' ), array( 'Colour=Red', 'Brand=Typed' ) );
list( , , $calls ) = push( item( 4025, opts( array( 'brand' => 'Field' ) ) ) );
check( 'the Brand field filled: the field wins', specifics_of( $calls[0][1] ?? '' ), array( 'Brand=Field', 'Colour=Red' ) );
list( , , $calls ) = push( item( 4025, opts( array( 'mpn' => 'ZT-1' ) ) ) );
check( '  the MPN field alone: the WooCommerce brand as Brand beside it', specifics_of( $calls[0][1] ?? '' ), array( 'Brand=Zeta', 'MPN=ZT-1', 'Colour=Red' ) );
$API->aspects = array( array( 'name' => 'brand', 'required' => true ) );
branded( 4026, array( 21 ) );
list( $res, , $calls ) = push( item( 4026, array(), array( 'attributes' => array() ) ) );
check( 'a category requiring Brand: met by the WooCommerce brand, by the category\'s own name', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ) ), array( 'created', array( 'brand=Zeta' ) ) );
branded( 4026, array() );
list( $res ) = push( item( 4026, array(), array( 'attributes' => array() ) ) );
check( '  without a brand: refused for it, as before', code_of( $res ), 'missing_required_aspects' );
$API->aspects = array( array( 'name' => 'Brand', 'required' => false ), array( 'name' => 'MPN', 'required' => false ) );

// An Update.
branded( 4031, array( 21 ) );
list( $res, , $calls ) = push( item( 4031, $PUSHED + array( '_ebay_item_id' => '110000004031' ) ) );
check( 'an Update whose item specifics go: the WooCommerce brand among them', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ) ), array( 'updated', array( 'Brand=Zeta', 'Colour=Red' ) ) );
branded( 4032, array( 21 ) );
$GLOBALS['W']['log'] = array();
list( $res, , $calls ) = push( item( 4032, $PUSHED + array( '_ebay_item_id' => '110000004032' ), array( 'attributes' => array() ) ) );
check( 'an Update with no other item specifics: not sent alone (it would take off any others the listing has), said once', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ), logged( 'Push: WC #4032 - its WooCommerce brand was not sent as eBay\'s Brand: this Update sends no other item specifics, and eBay replaces all of a listing\'s item specifics with those sent, so it alone would take off any others the listing has on eBay. It goes with a new listing, or an Update that sends other item specifics.' ), logged( 'its Brand and MPN were not sent' ) ), array( 'updated', null, 'info', null ) );
branded( 4033, array( 21 ) );
list( $res, , $calls ) = push( item( 4033, $IMPORTED + array( '_ebay_item_id' => '110000004033', '_ebay_live' => array( 'item_id' => '110000004033', 'category_id' => '31387', 'condition_id' => '3000' ) ) ) );
check( '  an imported listing\'s Update with its item specifics: among them', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ) ), array( 'updated', array( 'Brand=Zeta', 'Colour=Red' ) ) );
// A live auction.
branded( 4034, array( 21 ) );
$GLOBALS['W']['log'] = array();
list( $res, $sites, $calls ) = push( item( 4034, array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000004034' ) ) );
check( 'a live auction\'s Update: never, its item specifics as 3.37.0 sent them; said once eBay answered', array( code_of( $res ), $sites, specifics_of( $calls[0][1] ?? '' ), logged( 'Push: WC #4034 - its WooCommerce brand is not sent as eBay\'s Brand to a live auction, whose item specifics eBay does not let change once it has a bid or is within 12 hours of its end: its item specifics go without it, as TCGiant Sync has no record of this listing being sent it (listed before 3.38.0, relisted on eBay, or linked by the import after an unfinished push), and a new listing takes it.' ) ), array( 'updated', array( 'ReviseItem@0' ), array( 'Colour=Red' ), 'info' ) );
$GLOBALS['W']['log'] = array();
list( , , $calls ) = push( item( 4034, array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000004034' ), array( 'attributes' => array( new WC_Product_Attribute( 'Colour', array( 'Red' ) ), new WC_Product_Attribute( 'Brand', array( 'Acme' ) ) ) ) ) );
check( '  one whose item specifics carry a Brand: nothing said of the WooCommerce brand', array( specifics_of( $calls[0][1] ?? '' ), logged( 'WooCommerce brand' ) ), array( array( 'Colour=Red', 'Brand=Acme' ), null ) );
shop( '31387', 'Chinese', $on );
$by_format = $PUSHED + array( '_ebay_item_id' => '110000004035' );
unset( $by_format['_ebay_listing_type'] );
branded( 4035, array( 21 ) );
list( , , $calls ) = push( item( 4035, $by_format ) );
check( '  a live auction by its format alone: never', specifics_of( $calls[0][1] ?? '' ), array( 'Colour=Red' ) );
branded( 4036, array( 21 ) );
list( , $sites, $calls ) = push( item( 4036, array() ) );
check( '  a new auction: with it', array( $sites, specifics_of( $calls[0][1] ?? '' ) ), array( array( 'AddItem@0' ), array( 'Brand=Zeta', 'Colour=Red' ) ) );
shop( '31387', 'FixedPriceItem', $on );
// A relist.
branded( 4037, array( 21 ) );
item( 4037, array( '_ebay_listing_status' => 'Ended', '_ebay_item_id' => '110000004037', '_ebay_listing_type' => 'Chinese' ) + $PUSHED );
list( $res, $sites, $calls ) = relist_it( 4037, ended_in( '110000004037', '31387', array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00', 'Quantity' => '1' ) ), 'Auto-Relist' );
check( 'a relist: never (it keeps the listing\'s item specifics), 3.37.0\'s body', array( $sites, no_uuid( $calls[1][1] ?? '' ) ), array( array( 'GetItem@0', 'RelistItem@0' ), "\n<Item>\n<ItemID>110000004037</ItemID>\n</Item>" ) );
// The template's specifics table.
branded( 4041, array( 21 ) );
list( , , $calls ) = push( item( 4041, array( '_tcgiant_tpl_override' => $table ) ) );
check( 'the template\'s specifics table: a new listing\'s carries it', $in_desc( $calls[0][1] ?? '' ), true );
branded( 4042, array( 21 ) );
item( 4042, $PUSHED + array( '_ebay_item_id' => '110000004042', '_tcgiant_tpl_override' => $table ), array( 'attributes' => array() ) );
check( '  an Update\'s with no other item specifics, and Re-send\'s by the Update rule: not', array( $in_desc( push( wc_get_product( 4042 ) )[2][0][1] ?? '' ), false !== strpos( $resend( 4042 )['html'], 'Zeta' ) ), array( false, false ) );
branded( 4043, array( 21 ) );
item( 4043, $PUSHED + array( '_ebay_item_id' => '110000004043', '_tcgiant_tpl_override' => $table ) );
check( '  an Update\'s whose item specifics go, and Re-send\'s: with it', array( $in_desc( push( wc_get_product( 4043 ) )[2][0][1] ?? '' ), false !== strpos( $resend( 4043 )['html'], 'Zeta' ) ), array( true, true ) );
branded( 4044, array( 21 ) );
item( 4044, $PUSHED + array( '_ebay_item_id' => '110000004044', '_tcgiant_tpl_override' => $table ), array( 'attributes' => array( new WC_Product_Attribute( 'Brand', array( 'Acme' ) ) ) ) );
$hash_carried = $resend( 4044 )['content_hash'];
shop( '31387', 'FixedPriceItem', $off );
check( '  a product whose item specifics carry a Brand: its content hash as with the setting off (nothing to re-send)', $resend( 4044 )['content_hash'], $hash_carried );
shop( '31387', 'FixedPriceItem', $on );
// A variable product: the parent's brand, at item level.
$wvar = item( 4051, array(), array( 'type' => 'variable', 'sku' => 'VAR-4051' ), 'Opt_Variable' );
$wvar->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
$wvar->children             = array( 40511, 40512 );
item( 40511, array(), array( 'sku' => 'VAR-4051-S', 'size' => 'Small', 'attributes' => array() ), 'Opt_Variation' );
item( 40512, array(), array( 'sku' => 'VAR-4051-L', 'size' => 'Large', 'attributes' => array() ), 'Opt_Variation' );
branded( 4051, array( 21 ) );
branded( 40511, array( 22 ) );
list( $res, , $calls ) = push( $wvar );
$body = (string) ( $calls[0][1] ?? '' );
check( 'a variable product: the parent\'s brand, at item level, once; a variation\'s never read', array( code_of( $res ), specifics_of( $body ), substr_count( $body, 'Zeta' ), false !== strpos( $body, 'Acme' ) ), array( 'created', array( 'Brand=Zeta', 'Colour=Red' ), 1, false ) );
branded( 40511, array() );
// eBay Motors.
shop( '33564', 'FixedPriceItem', $on );
fresh_ebay();
branded( 4061, array( 21 ) );
list( $res, $sites, $calls ) = push( item( 4061 ) );
check( 'eBay Motors: a Parts & Accessories item listed there, its required Brand met by the WooCommerce brand', array( code_of( $res ), $sites, specifics_of( $calls[0][1] ?? '' ) ), array( 'created', array( 'AddFixedPriceItem@100' ), array( 'Brand=Zeta', 'Colour=Red' ) ) );
shop( '31387', 'FixedPriceItem', $on );
fresh_ebay();

// The setting and its words.
$settings_view = (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/settings.php' );
check( 'the setting: the push form\'s, drawn off when never saved, with what it changes, that Brand costs no fee, and whether WooCommerce Brands is here', array( 1 === preg_match( "/'export' => array\((?:(?!\),\n).)*'export_woo_brand',/s", (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' ) ), substr_count( $settings_view, "checked( \$settings['export_woo_brand'] ?? '0', " ), false !== strpos( $settings_view, 'Turning this on changes the item specifics of every product with a brand, at its next push or Update' ), false !== strpos( $settings_view, 'eBay charges no fee for Brand.' ), false !== strpos( $settings_view, 'WooCommerce Brands is not on this site' ) ), array( true, 2, true, true, true ) );
$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
check( 'uninstall: a key of the plugin\'s settings, deleted with them when the shop asks', array( false !== strpos( $uninstall, "'tcgiant_sync_ebay_settings',      // Settings + OAuth tokens + relay secret." ), false !== strpos( $uninstall, 'export_woo_brand' ) ), array( true, true ) );
ob_start();
TCGiant_Sync_Listing_Options::render_product_section( item( 4071 ), 'FixedPriceItem' );
$html = (string) ob_get_clean();
check( 'the eBay tab\'s Brand line says where an empty Brand comes from', false !== strpos( $html, 'WooCommerce brand when that setting is on (Settings, Push to eBay: WooCommerce Brands as eBay' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 4 - M-1: A LIVE AUCTION'S UPDATE SENDS AGAIN THE BRAND AND MPN IT WAS LISTED WITH\n" . str_repeat( '=', 124 ) . "\n";

// Review 4's probe auction4.php, each listed by a whole push (which records
// what eBay took) and then pushed again while live.
$life = function ( $id, array $settings, $format, array $meta ) {
	shop( '31387', $format, $settings );
	fresh_ebay();
	item( $id, $meta );
	list( $sites, $calls ) = push_whole( $id );
	$new = array( $sites, specifics_of( $calls[0][1] ?? '' ), record_of( $id ) );
	$GLOBALS['W']['log'] = array();
	list( $sites, $calls ) = push_whole( $id );
	return array( $new, array( $sites, specifics_of( $calls[0][1] ?? '' ), record_of( $id ), deleted_of( $calls[0][1] ?? '' )[0] ) );
};
$listed_acme = array( 'item' => '110000000001', 'specifics' => array( 'Brand' => 'Acme' ) );
$listed_zeta = array( 'item' => '110000000001', 'specifics' => array( 'Brand' => 'Zeta' ) );
branded( 4101, array( 22 ) );
check( 'M-1 A1, the setting on, the shop\'s default Auction: listed with Brand=Acme and recorded; its live Update sends Brand=Acme again, the record kept', $life( 4101, $on, 'Chinese', array() ), array( array( array( 'AddItem@0' ), array( 'Brand=Acme', 'Colour=Red' ), $listed_acme ), array( array( 'ReviseItem@0' ), array( 'Brand=Acme', 'Colour=Red' ), $listed_acme, array() ) ) );
check( '  nothing said of the brand: the Update sends what the brand gives', logged( 'WC #4101 - its WooCommerce brand' ), null );
check( 'M-1 A2, the setting off, the Brand field: listed with Brand=Zeta; its live Update sends Brand=Zeta again', $life( 4102, $off, 'Chinese', opts( array( 'brand' => 'Zeta' ) ) ), array( array( array( 'AddItem@0' ), array( 'Brand=Zeta', 'Colour=Red' ), $listed_zeta ), array( array( 'ReviseItem@0' ), array( 'Brand=Zeta', 'Colour=Red' ), $listed_zeta, array() ) ) );
check( '  nothing said of the fields', logged( 'WC #4102 - the Brand and MPN fields' ), null );
branded( 4103, array( 22 ) );
check( 'M-1 A3, the setting on, the product\'s own Listing Type Auction in a fixed-price shop: Brand=Acme both times', $life( 4103, $on, 'FixedPriceItem', array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) ), array( array( array( 'AddItem@0' ), array( 'Brand=Acme', 'Colour=Red' ), $listed_acme ), array( array( 'ReviseItem@0' ), array( 'Brand=Acme', 'Colour=Red' ), $listed_acme, array() ) ) );
branded( 4104, array( 22 ) );
check( 'M-1 A4, a fixed-price listing for contrast: Brand=Acme both times, as before', $life( 4104, $on, 'FixedPriceItem', array() ), array( array( array( 'AddFixedPriceItem@0' ), array( 'Brand=Acme', 'Colour=Red' ), $listed_acme ), array( array( 'ReviseFixedPriceItem@0' ), array( 'Brand=Acme', 'Colour=Red' ), $listed_acme, array() ) ) );

// A value typed since: the recorded one goes, said.
shop( '31387', 'Chinese', $off );
update_post_meta( 4102, TCGiant_Sync_Listing_Options::BRAND_META, 'Zeta Two' );
$GLOBALS['W']['log'] = array();
list( $sites, $calls ) = push_whole( 4102 );
check( 'a recorded Brand changed in the field since: the live auction\'s Update sends the recorded Zeta, not Zeta Two; the record kept', array( $sites, specifics_of( $calls[0][1] ?? '' ), record_of( 4102 ) ), array( array( 'ReviseItem@0' ), array( 'Brand=Zeta', 'Colour=Red' ), $listed_zeta ) );
check( '  said, with what it sends instead', logged( 'Push: WC #4102 - the Brand and MPN fields are not sent to a live auction, whose item specifics eBay does not let change once it has a bid or is within 12 hours of its end: its Update sends again what it was listed with (Brand Zeta), and a new listing takes the fields.' ), 'info' );
// The field emptied since: the recorded one still goes, said.
delete_post_meta( 4102, TCGiant_Sync_Listing_Options::BRAND_META );
$GLOBALS['W']['log'] = array();
list( $sites, $calls ) = push_whole( 4102 );
check( '  the field emptied since: still Brand=Zeta, no DeletedField, the record kept', array( $sites, specifics_of( $calls[0][1] ?? '' ), deleted_of( $calls[0][1] ?? '' )[0], record_of( 4102 ) ), array( array( 'ReviseItem@0' ), array( 'Brand=Zeta', 'Colour=Red' ), array(), $listed_zeta ) );
check( '    said', logged( 'Push: WC #4102 - its Update sends again what the live auction was listed with (Brand Zeta), whose item specifics eBay does not let change once it has a bid or is within 12 hours of its end: a new listing goes by the product as it is now.' ), 'info' );
// A WooCommerce brand that differs.
shop( '31387', 'Chinese', $on );
branded( 4101, array( 21 ) );
$GLOBALS['W']['log'] = array();
list( $sites, $calls ) = push_whole( 4101 );
check( 'a WooCommerce brand that differs (Zeta now): the live auction\'s Update sends the recorded Acme, said', array( $sites, specifics_of( $calls[0][1] ?? '' ), logged( 'Push: WC #4101 - its WooCommerce brand is not sent as eBay\'s Brand to a live auction, whose item specifics eBay does not let change once it has a bid or is within 12 hours of its end: its Update sends again what it was listed with (Brand Acme), and a new listing takes it.' ) ), array( array( 'ReviseItem@0' ), array( 'Brand=Acme', 'Colour=Red' ), 'info' ) );
branded( 4101, array() );
// Brand and MPN together.
shop( '31387', 'Chinese', $off );
fresh_ebay();
item( 4116, opts( array( 'brand' => 'Zeta', 'mpn' => 'ZT-1' ) ) );
push_whole( 4116 );
check( 'Brand and MPN both: recorded by the names sent', record_of( 4116 ), array( 'item' => '110000000001', 'specifics' => array( 'Brand' => 'Zeta', 'MPN' => 'ZT-1' ) ) );
update_post_meta( 4116, TCGiant_Sync_Listing_Options::MPN_META, 'ZT-2' );
list( , $calls ) = push_whole( 4116 );
check( '  the MPN typed again: the live Update sends both as listed', specifics_of( $calls[0][1] ?? '' ), array( 'Brand=Zeta', 'MPN=ZT-1', 'Colour=Red' ) );
// The template's table carries what the ItemSpecifics carries.
item( 4105, opts( array( 'brand' => 'Zeta' ) ) + array( '_tcgiant_tpl_override' => $table ) );
push_whole( 4105 );
update_post_meta( 4105, TCGiant_Sync_Listing_Options::BRAND_META, 'Ozone' );
list( , $calls ) = push_whole( 4105 );
$desc = (string) el( $calls[0][1] ?? '', 'Description' );
check( 'the template\'s table on the live auction\'s Update: the Brand it sends (Zeta), not the field\'s (Ozone); Re-send\'s too', array( specifics_of( $calls[0][1] ?? '' ), false !== strpos( $desc, 'Zeta' ), false !== strpos( $desc, 'Ozone' ), false !== strpos( $resend( 4105 )['html'], 'Zeta' ), false !== strpos( $resend( 4105 )['html'], 'Ozone' ) ), array( array( 'Brand=Zeta', 'Colour=Red' ), true, false, true, false ) );

// Nothing recorded: the set as it was.
$live_auction4 = array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000004106' );
list( , , $calls ) = push( item( 4106, $live_auction4 ) );
$plain = no_uuid( $calls[0][1] ?? '' );
$GLOBALS['W']['log'] = array();
list( $res, $sites, $calls ) = push( item( 4106, $live_auction4 + opts( array( 'brand' => 'Zeta' ) ) ) );
check( 'nothing recorded (a listing made before 3.38.0): the live auction\'s Update byte for byte the one with no Brand field, said so', array( $sites, no_uuid( $calls[0][1] ?? '' ), logged( 'Push: WC #4106 - the Brand and MPN fields are not sent to a live auction, whose item specifics eBay does not let change once it has a bid or is within 12 hours of its end: its item specifics go without them, as TCGiant Sync has no record of this listing being sent them (listed before 3.38.0, relisted on eBay, or linked by the import after an unfinished push), and a new listing takes the fields.' ) ), array( array( 'ReviseItem@0' ), $plain, 'info' ) );
// An eBay-made relist: the record names another listing.
list( $res, $sites, $calls ) = push( item( 4107, array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000004199', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000004107', 'specifics' => array( 'Brand' => 'Zeta' ) ) ) ) );
check( 'a relist made on eBay (the record names the ended listing): nothing recorded applies - Colour=Red alone, the record as it was', array( $sites, specifics_of( $calls[0][1] ?? '' ), record_of( 4107 ) ), array( array( 'ReviseItem@0' ), array( 'Colour=Red' ), array( 'item' => '110000004107', 'specifics' => array( 'Brand' => 'Zeta' ) ) ) );

// A relist the plugin makes carries the record to the new listing.
item( 4108, opts( array( 'brand' => 'Zeta' ) ) + array( '_ebay_listing_status' => 'Ended', '_ebay_item_id' => '110000004108', '_ebay_listing_type' => 'Chinese', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000004108', 'specifics' => array( 'Brand' => 'Zeta' ) ) ) + $PUSHED );
list( $res, $sites, $calls ) = relist_it( 4108, ended_in( '110000004108', '31387', array( 'ListingType' => 'Chinese', 'StartPrice' => '85.00', 'Quantity' => '1' ) ), 'Auto-Relist' );
check( 'a relist the plugin makes: RelistItem sends no item specifics, and the record goes to the new listing with them', array( is_array( $res ) ? $res['item_id'] : code_of( $res ), $sites, specifics_of( $calls[1][1] ?? '' ), record_of( 4108 ) ), array( '110000000001', array( 'GetItem@0', 'RelistItem@0' ), null, $listed_zeta ) );
update_post_meta( 4108, TCGiant_Sync_Listing_Options::BRAND_META, 'Zeta Two' );
update_post_meta( 4108, '_ebay_listing_status', 'Active' );
list( $sites, $calls ) = push_whole( 4108 );
check( '  its Update while live: the Brand it was listed with', array( $sites, specifics_of( $calls[0][1] ?? '' ) ), array( array( 'ReviseItem@0' ), array( 'Brand=Zeta', 'Colour=Red' ) ) );
// The relist that goes without its options, and one eBay refuses.
$m1_item( 4109, 'Auto-Relist', array( 'second_category' => '11116', 'brand' => 'Zeta' ), array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000004109', 'specifics' => array( 'Brand' => 'Zeta' ) ) ) );
list( $res ) = $m1_relist( 4109, 'Auto-Relist', array( $not_leaf ) );
check( '  a relist sent again without its options: the Brand still carried, nothing of the options', array( $res, record_of( 4109 ) ), array( '110000000001', $listed_zeta ) );
$m1_item( 4110, 'Auto-Relist', array( 'brand' => 'Zeta' ), array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000004110', 'specifics' => array( 'Brand' => 'Zeta' ) ) ) );
list( $res ) = $m1_relist( 4110, 'Auto-Relist', array( $not_leaf ) );
check( '  a relist eBay refuses: the record as it was', array( $res, record_of( 4110 ) ), array( 'api_error', array( 'item' => '110000004110', 'specifics' => array( 'Brand' => 'Zeta' ) ) ) );

// Updates of a fixed-price listing (the played eBay answers a revise with
// ItemID 110000000001, which the push records the listing as).
shop( '31387', 'FixedPriceItem', $off );
fresh_ebay();
$rec = function ( $id, array $more = array() ) {
	return array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '1100000' . $id ) + $more + array( 'specifics' => array( 'Brand' => 'Zeta' ) ), '_ebay_item_id' => '1100000' . $id );
};
item( 4111, opts( array( 'brand' => 'Zeta Two' ) ) + $rec( 4111 ) + $PUSHED );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'Something else.' );
push_whole( 4111 );
unset( $API->answers['ReviseFixedPriceItem'] );
check( 'a refused Update: the record as it was', record_of( 4111 ), array( 'item' => '11000004111', 'specifics' => array( 'Brand' => 'Zeta' ) ) );
item( 4112, opts( array( 'brand' => 'Zeta Two' ) ) + $rec( 4112 ) + $PUSHED, array( 'attributes' => array() ) );
list( , $calls ) = push_whole( 4112 );
check( 'an Update that sends no ItemSpecifics: the record as it was', array( specifics_of( $calls[0][1] ?? '' ), record_of( 4112 ) ), array( null, array( 'item' => '11000004112', 'specifics' => array( 'Brand' => 'Zeta' ) ) ) );
item( 4113, opts( array( 'brand' => 'Zeta Two' ) ) + $rec( 4113 ) + $PUSHED );
push_whole( 4113 );
check( 'an Update whose ItemSpecifics went with a changed Brand: recorded as sent', record_of( 4113 ), array( 'item' => '110000000001', 'specifics' => array( 'Brand' => 'Zeta Two' ) ) );
item( 4114, $rec( 4114 ) + $PUSHED );
list( , $calls ) = push_whole( 4114 );
check( 'an Update whose ItemSpecifics went without them (the field emptied): Colour=Red, no DeletedField, the record cleared', array( specifics_of( $calls[0][1] ?? '' ), deleted_of( $calls[0][1] ?? '' )[0], record_of( 4114 ) ), array( array( 'Colour=Red' ), array(), '' ) );
item( 4115, opts( array( 'subtitle' => 'Sub' ) ) + $rec( 4115, array( 'subtitle' => 'Sub' ) ) + $PUSHED );
push_whole( 4115 );
check( '  with another field recorded: only the Brand leaves the record', record_of( 4115 ), array( 'item' => '110000000001', 'subtitle' => 'Sub' ) );
item( 4117, opts( array( 'subtitle' => 'Sub' ) ) + $rec( 4117 ) + $PUSHED, array( 'attributes' => array() ) );
list( , $calls ) = push_whole( 4117 );
check( '  a subtitle sent and no ItemSpecifics: the Brand kept beside it', array( specifics_of( $calls[0][1] ?? '' ), record_of( 4117 ) ), array( null, array( 'item' => '110000000001', 'subtitle' => 'Sub', 'specifics' => array( 'Brand' => 'Zeta' ) ) ) );
// An Update refused with 10039 while it took the subtitle off: the Brand stays.
item( 4118, $rec( 4118, array( 'subtitle' => 'Old' ) ) + $PUSHED, array( 'attributes' => array() ) );
$API->answers['ReviseFixedPriceItem'] = trading_error( '10039', 'The title or subtitle cannot be changed.' );
push_whole( 4118 );
unset( $API->answers['ReviseFixedPriceItem'] );
check( 'an Update refused with 10039 taking the subtitle off: the subtitle leaves the record, the Brand stays', record_of( 4118 ), array( 'item' => '11000004118', 'specifics' => array( 'Brand' => 'Zeta' ) ) );
// Verify never records.
item( 4119, opts( array( 'brand' => 'Zeta' ) ) );
verify_item( wc_get_product( 4119 ) );
check( 'Verify: the build knows what it would send, and nothing is recorded', array( last_state(), record_of( 4119 ) ), array( array( 'specifics' => array( 'Brand' => 'Zeta' ) ), '' ) );
check( '  the admin\'s Verify records nothing either (no record in its handler)', 1 === preg_match( '/function ajax_verify_product\(.*?(?=\n\tpublic function )/s', (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' ), $vm ) && false === strpos( $vm[0], 'record' ), true );

// What the record keeps is the fields' own: not a Brand the item specifics carry.
shop( '31387', 'FixedPriceItem', $off );
fresh_ebay();
item( 4121, opts( array( 'mpn' => 'ZT-1' ) ), array( 'attributes' => array( new WC_Product_Attribute( 'Colour', array( 'Red' ) ), new WC_Product_Attribute( 'Brand', array( 'Attr' ) ) ) ) );
list( , $calls ) = push_whole( 4121 );
check( 'the MPN field beside a Brand attribute: both sent, only the MPN recorded (the attribute is the product\'s own, as 3.37.0 sent it)', array( specifics_of( $calls[0][1] ?? '' ), record_of( 4121 ) ), array( array( 'MPN=ZT-1', 'Colour=Red', 'Brand=Attr' ), array( 'item' => '110000000001', 'specifics' => array( 'MPN' => 'ZT-1' ) ) ) );
// The resend past eBay's thirty: said.
shop( '31387', 'Chinese', $off );
fresh_ebay();
$attrs30r = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$attrs30r[] = new WC_Product_Attribute( 'Attr' . $i, array( 'V' . $i ) );
}
$GLOBALS['W']['log'] = array();
list( , , $calls ) = push( item( 4122, array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000004122', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000004122', 'specifics' => array( 'Brand' => 'Zeta' ) ) ), array( 'attributes' => $attrs30r ) ) );
$sp = (array) specifics_of( $calls[0][1] ?? '' );
check( 'thirty attributes and the Brand a live auction was listed with: Brand first, Attr30 left out, said', array( count( $sp ), $sp[0] ?? null, logged( 'Push: WC #4122 - the Brand and MPN its live auction was listed with go first among its item specifics again, and eBay takes 30 at most, so these were left out: Attr30.' ) ), array( 30, 'Brand=Zeta', 'warning' ) );
shop();
fresh_ebay();

// The functions as units.
check( 'resent(): the recorded first, in place of one of the same name; a set that carries it is left as it is; nothing recorded, the set', array(
	TCGiant_Sync_Listing_Options::resent( array( 'Colour' => 'Red', 'brand' => 'Other' ), array( 'Brand' => 'Zeta' ) ),
	TCGiant_Sync_Listing_Options::resent( array( 'Colour' => 'Red', 'Brand' => 'Zeta' ), array( 'Brand' => 'Zeta' ) ),
	TCGiant_Sync_Listing_Options::resent( array( 'Colour' => 'Red', 'Manufacturer Part Number' => 'X' ), array( 'Brand' => 'Zeta', 'MPN' => 'ZT-1' ) ),
	TCGiant_Sync_Listing_Options::resent( array( 'Colour' => 'Red' ), array() ),
), array(
	array( 'Brand' => 'Zeta', 'Colour' => 'Red' ),
	array( 'Colour' => 'Red', 'Brand' => 'Zeta' ),
	array( 'Brand' => 'Zeta', 'MPN' => 'ZT-1', 'Colour' => 'Red' ),
	array( 'Colour' => 'Red' ),
) );
check( 'listed(): only the listing the product follows', array( TCGiant_Sync_Listing_Options::listed( 4107, '110000004107' ), TCGiant_Sync_Listing_Options::listed( 4107, '110000004199' ), TCGiant_Sync_Listing_Options::listed( 4107, '' ) ), array( array( 'Brand' => 'Zeta' ), array(), array() ) );

// A required Brand met by the record on the live auction's Update (L-3 with M-1).
shop( '31387', 'Chinese', $off );
fresh_ebay();
$API->aspects = array( array( 'name' => 'Brand', 'required' => true ) );
list( $res, $sites, $calls ) = push( item( 4120, array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000004120', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000004120', 'specifics' => array( 'Brand' => 'Zeta' ) ) ) ) );
check( 'a category requiring Brand, the field emptied since: the live auction\'s Update met by the Brand it sends again', array( code_of( $res ), $sites, specifics_of( $calls[0][1] ?? '' ) ), array( 'updated', array( 'ReviseItem@0' ), array( 'Brand=Zeta', 'Colour=Red' ) ) );
shop();
fresh_ebay();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 4 - L-1: A RELIST REFUSED WITH AND WITHOUT A TAKE-OFF HOLDS THE ELEMENTS ALONE TOO\n" . str_repeat( '=', 124 ) . "\n";

// Review 4's probe memo4.php T.
$t_record = array( TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000004201', 'condition_note' => 'Old note' ) );
$m1_item( 4201, 'Auto-Relist', array( 'second_category' => '11116' ), $t_record );
list( $res, $sites ) = $m1_relist( 4201, 'Auto-Relist', array( $limit, $limit ) );
check( 'L-1: a second category and a take-off, refused with and without (the selling limit): two RelistItem; the memo holds both sets, expiring', array( $res, $sites, $memo( 4201 )[0], $memo( 4201 )[2] ?? null ), array( 'api_error', array( 'GetItem@0', 'RelistItem@0', 'RelistItem@0' ), TCGiant_Sync_Listing_Options::relist_state( $cat_xml . "\n", array( 'Item.ConditionDescription' ) ) . ',' . TCGiant_Sync_Listing_Options::relist_state( $cat_xml . "\n", array() ), '0' ) );
$m1_item( 4201, 'Auto-Relist', array( 'second_category' => '11116' ), $t_record );
list( $res, $sites, $bodies ) = $m1_relist( 4201, 'Auto-Relist', array() );
check( '  the limit lifted: held, one plain RelistItem, taken', array( $res, $sites, carries_any( $bodies[0] ?? '' ) ), array( '110000000001', array( 'GetItem@0', 'RelistItem@0' ), false ) );
$m1_item( 4201, 'Auto-Relist', array( 'second_category' => '11116' ), array( '_ebay_item_id' => '110000000001', TCGiant_Sync_Listing_Options::SENT_META => record_of( 4201 ) ) );
list( $res, $sites, $bodies ) = $m1_relist( 4201, 'Auto-Relist', array() );
check( '  the new listing ended and relisted with the same values: held from the start - one RelistItem, plain', array( $res, $sites, carries_any( $bodies[0] ?? '' ) ), array( '110000000001', array( 'GetItem@0', 'RelistItem@0' ), false ) );
check( 'relist_refused() with no take-off: its one set', ( function () use ( $memo, $cat_xml ) {
	TCGiant_Sync_Listing_Options::relist_refused( 4202, $cat_xml . "\n", array() );
	return $memo( 4202 )[0];
} )(), TCGiant_Sync_Listing_Options::relist_state( $cat_xml . "\n", array() ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 4 - L-2: BRAND AS A VARIATION AXIS IS NEVER SENT AT ITEM LEVEL TOO\n" . str_repeat( '=', 124 ) . "\n";

// Review 4's probe brand4.php P6, and the typed field.
$by_brand = function ( $id, array $meta ) {
	$var = item( $id, $meta, array( 'type' => 'variable', 'sku' => 'VAR-' . $id, 'attributes' => array( new WC_Product_Attribute( 'Colour', array( 'Red' ) ), new WC_Product_Attribute( 'Brand', array( 'Acme', 'Other' ), false, true ) ) ), 'Opt_Variable' );
	$var->variation_attributes = array( 'Brand' => array( 'Acme', 'Other' ) );
	$var->children             = array( $id * 10 + 1, $id * 10 + 2 );
	item( $id * 10 + 1, array(), array( 'sku' => 'VAR-' . $id . '-A', 'size' => 'Acme', 'attributes' => array() ), 'Opt_Variation' );
	item( $id * 10 + 2, array(), array( 'sku' => 'VAR-' . $id . '-O', 'size' => 'Other', 'attributes' => array() ), 'Opt_Variation' );
	$GLOBALS['W']['log'] = array();
	list( $res, , $calls ) = push( $var );
	$body = (string) ( $calls[0][1] ?? '' );
	preg_match( '#<VariationSpecificsSet>(.*?)</VariationSpecificsSet>#s', $body, $vss );
	return array( code_of( $res ), specifics_of( $body ), preg_match_all( '#<Name>([^<]*)</Name>#', $vss[1] ?? '', $mm ) ? $mm[1] : array() );
};
shop( '31387', 'FixedPriceItem', $off );
fresh_ebay();
$var_off = $by_brand( 4301, array() );
shop( '31387', 'FixedPriceItem', $on );
branded( 4302, array( 22 ) );
check( 'L-2 (review 4\'s probe P6): a variable product varying by Brand, with a brand, the setting on: no Brand at item level - its item specifics as the setting off\'s', array( $by_brand( 4302, array() ), $var_off ), array( array( 'created', array( 'Colour=Red' ), array( 'Brand' ) ), array( 'created', array( 'Colour=Red' ), array( 'Brand' ) ) ) );
check( '  said once', array( logged( 'Push: WC #4302 - Brand is one of its variations\' options, which eBay takes in each variation and refuses among the item\'s own item specifics too, so its WooCommerce brand is not sent as one.' ), count( preg_grep( '/variations\' options/', array_column( $GLOBALS['W']['log'], 1 ) ) ) ), array( 'info', 1 ) );
check( 'the Brand field typed: not at item level either, said', array( $by_brand( 4303, opts( array( 'brand' => 'Field' ) ) ), logged( 'Push: WC #4303 - Brand is one of its variations\' options, which eBay takes in each variation and refuses among the item\'s own item specifics too, so its Brand field is not sent as one.' ) ), array( array( 'created', array( 'Colour=Red' ), array( 'Brand' ) ), 'info' ) );
check( '  the MPN field beside it still goes', $by_brand( 4304, opts( array( 'brand' => 'Field', 'mpn' => 'ZT-1' ) ) )[1], array( 'MPN=ZT-1', 'Colour=Red' ) );
// A Brand attribute that is not a variation axis: the field still takes its place.
$var5 = item( 4306, opts( array( 'brand' => 'Field' ) ), array( 'type' => 'variable', 'sku' => 'VAR-4306', 'attributes' => array( new WC_Product_Attribute( 'Brand', array( 'Attr' ) ), new WC_Product_Attribute( 'Size', array( 'Small', 'Large' ), false, true ) ) ), 'Opt_Variable' );
$var5->variation_attributes = array( 'Size' => array( 'Small', 'Large' ) );
$var5->children             = array( 43061, 43062 );
item( 43061, array(), array( 'sku' => 'VAR-4306-S', 'size' => 'Small', 'attributes' => array() ), 'Opt_Variation' );
item( 43062, array(), array( 'sku' => 'VAR-4306-L', 'size' => 'Large', 'attributes' => array() ), 'Opt_Variation' );
list( $res, , $calls ) = push( $var5 );
check( '  a variable product varying by Size, with a Brand attribute and the field: the field at item level, in the attribute\'s place', array( code_of( $res ), specifics_of( $calls[0][1] ?? '' ) ), array( 'created', array( 'Brand=Field' ) ) );
// A push that builds twice says it once.
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = ended_in( '110000004307', '31387' );
$twice = item( 4307, opts( array( 'brand' => 'Field' ) ) + $PUSHED + array( '_ebay_item_id' => '110000004307' ), array( 'type' => 'variable', 'sku' => 'VAR-4307', 'attributes' => array( new WC_Product_Attribute( 'Colour', array( 'Red' ) ), new WC_Product_Attribute( 'Brand', array( 'Acme', 'Other' ), false, true ) ) ), 'Opt_Variable' );
$twice->variation_attributes = array( 'Brand' => array( 'Acme', 'Other' ) );
$twice->children             = array( 43071, 43072 );
item( 43071, array(), array( 'sku' => 'VAR-4307-A', 'size' => 'Acme', 'attributes' => array() ), 'Opt_Variation' );
item( 43072, array(), array( 'sku' => 'VAR-4307-O', 'size' => 'Other', 'attributes' => array() ), 'Opt_Variation' );
$GLOBALS['W']['log'] = array();
list( , $sites ) = push( $twice );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
check( '  a push that builds twice (its Update finds the listing ended): said once', array( count( $sites ) > 1, count( preg_grep( '/variations\' options/', array_column( $GLOBALS['W']['log'], 1 ) ) ) ), array( true, 1 ) );
check( 'fills_brand() and adds(): a Brand axis is a Brand carried; a simple product\'s brand fills in', array( TCGiant_Sync_Listing_Options::fills_brand( array(), wc_get_product( 4302 ) ), TCGiant_Sync_Listing_Options::adds( array(), wc_get_product( 4303 ) ), ( function () {
	branded( 4305, array( 22 ) );
	return TCGiant_Sync_Listing_Options::fills_brand( array(), item( 4305 ) );
} )() ), array( '', array(), 'Acme' ) );
shop();
fresh_ebay();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 4 - L-3: A REQUIRED BRAND JUDGED BY WHAT THE BUILD SENDS\n" . str_repeat( '=', 124 ) . "\n";

// Review 4's probe brand4.php P7, the setting off and on.
$p7 = array();
foreach ( array( 'off' => $off, 'on' => $on ) as $k => $s ) {
	shop( '31387', 'FixedPriceItem', $s );
	fresh_ebay();
	$API->aspects = array( array( 'name' => 'Brand', 'required' => true ) );
	foreach ( array( 4401, 4402, 4403 ) as $id ) {
		branded( $id, array( 22 ) );
	}
	$p7[ $k ] = array(
		code_of( push( item( 4401, array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000004401' ) ) )[0] ),
		code_of( push( item( 4402, $PUSHED + array( '_ebay_item_id' => '110000004402' ), array( 'attributes' => array() ) ) )[0] ),
		code_of( push( item( 4403 ) )[0] ),
	);
}
check( 'L-3 (review 4\'s probe P7), a category requiring Brand - a live auction\'s Update, an Update with no other item specifics, a new listing: the setting off refuses all three before eBay; on, only the new listing goes', $p7, array( 'off' => array( 'missing_required_aspects', 'missing_required_aspects', 'missing_required_aspects' ), 'on' => array( 'missing_required_aspects', 'missing_required_aspects', 'created' ) ) );
shop();
fresh_ebay();
$API->aspects = array( array( 'name' => 'Brand', 'required' => true ) );
check( '  the Brand field typed: an Update with no other item specifics refused as the build sends no Brand; an Update whose item specifics go, met', array( code_of( push( item( 4404, opts( array( 'brand' => 'Zeta' ) ) + $PUSHED + array( '_ebay_item_id' => '110000004404' ), array( 'attributes' => array() ) ) )[0] ), code_of( push( item( 4405, opts( array( 'brand' => 'Zeta' ) ) + $PUSHED + array( '_ebay_item_id' => '110000004405' ) ) )[0] ) ), array( 'missing_required_aspects', 'updated' ) );
check( '  Verify judges a new listing: met by the field', exporter()->find_missing_required_aspects( item( 4406, opts( array( 'brand' => 'Zeta' ) ) + $PUSHED + array( '_ebay_item_id' => '110000004406' ), array( 'attributes' => array() ) ), exporter()->get_export_settings( 4406 ) ), array() );
shop();
fresh_ebay();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 4 - L-4: REVIEW 4'S SURVIVING MUTANTS (Z01, Z04, Z05, Z06, Z08)\n" . str_repeat( '=', 124 ) . "\n";

// Z01: the memo is written, expiring, before the second RelistItem goes.
$m1_item( 4501, 'Auto-Relist', array( 'second_category' => '11116' ) );
$seen = null;
list( $res ) = $m1_relist( 4501, 'Auto-Relist', array( $not_leaf, function ( $api ) use ( &$seen, $memo ) {
	$seen = $memo( 4501 );
	return array( 'Ack' => 'Success', 'ItemID' => '110000000001' );
} ) );
check( 'Z01: as the second RelistItem goes, the memo is already there, expiring; once taken, lasting', array( $res, $seen[0] ?? null, $seen[2] ?? null, $memo( 4501 )[2] ?? null ), array( '110000000001', TCGiant_Sync_Listing_Options::relist_state( $cat_xml . "\n", array() ), '0', '1' ) );
$m1_item( 4502, 'Auto-Relist', array( 'second_category' => '11116' ) );
$died = '';
try {
	$m1_relist( 4502, 'Auto-Relist', array( $not_leaf, function ( $api ) {
		throw new RuntimeException( 'request killed' );
	} ) );
} catch ( RuntimeException $e ) {
	$died = $e->getMessage();
}
$API->queue = array();
check( '  a request killed while the second RelistItem is out: the memo kept, expiring', array( $died, $memo( 4502 )[0], $memo( 4502 )[2] ?? null, abs( (int) ( $memo( 4502 )[1] ?? 0 ) - time() ) < 60 ), array( 'request killed', TCGiant_Sync_Listing_Options::relist_state( $cat_xml . "\n", array() ), '0', true ) );
// Z04: the MPN field alone, on a live auction's Update, said as the fields.
shop( '31387', 'Chinese', $on );
fresh_ebay();
$GLOBALS['W']['log'] = array();
push( item( 4503, opts( array( 'mpn' => 'ZT-1' ) ) + array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000004503' ) ) );
check( 'Z04: the MPN field alone, a live auction\'s Update: said as the fields, not as a WooCommerce brand', array( logged( 'Push: WC #4503 - the Brand and MPN fields are not sent to a live auction' ), logged( 'WC #4503 - its WooCommerce brand' ) ), array( 'info', null ) );
// Z05: the cut to thirty by the WooCommerce brand, in its own words.
shop( '31387', 'FixedPriceItem', $on );
fresh_ebay();
branded( 4504, array( 21 ) );
$attrs30 = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$attrs30[] = new WC_Product_Attribute( 'Attr' . $i, array( 'V' . $i ) );
}
push( item( 4504, array(), array( 'attributes' => $attrs30 ) ) );
check( 'Z05: the cut to thirty made by the WooCommerce brand, said in its words', logged( 'Push: WC #4504 - its WooCommerce brand goes first among its item specifics as eBay\'s Brand, and eBay takes 30 at most, so these were left out: Attr30.' ), 'warning' );
// Z06: several brands named as WooCommerce shows them, decoded.
branded( 4505, array( 23, 22 ) );
$GLOBALS['W']['log'] = array();
push( item( 4505 ) );
check( 'Z06: several brands named decoded ("Zeta & Co", not "Zeta &amp; Co")', logged( 'Push: WC #4505 - its WooCommerce brands (Zeta & Co, Acme) are not one brand' ), 'info' );
shop();
// Z08: the settings screen's line, drawn with WooCommerce Brands here and not.
$brands_line = '';
foreach ( explode( "\n", $settings_view ) as $line ) {
	if ( false !== strpos( $line, 'echo $tc_brands_here ?' ) ) {
		$brands_line = $line;
	}
}
$draw_brands = function ( $here ) use ( $brands_line ) {
	$tc_brands_here = $here;
	ob_start();
	eval( '?>' . $brands_line ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	return trim( strip_tags( (string) ob_get_clean() ) );
};
check( 'Z08: the setting\'s line says WooCommerce Brands is on this site only where it is', array( $draw_brands( true ), $draw_brands( false ) ), array( 'WooCommerce Brands is on this site.', 'WooCommerce Brands is not on this site (it is part of WooCommerce from 9.6), so this does nothing here until it is.' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 4 - THE WORDS\n" . str_repeat( '=', 124 ) . "\n";

check( 'item 3: the Filterable item specifics screen says to keep the Brand attribute', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/filterable.php' ), 'Keep the Brand attribute on your products: an Update sends a product\\\'s item specifics as a whole, so one without Brand takes eBay\\\'s Brand off its listing (unless \\\'WooCommerce Brands as eBay\\\'s Brand\\\' is on; a live auction\\\'s Update still takes it off).' ), true );
check( 'the setting\'s description: a live auction\'s Update keeps the Brand it was listed with', false !== strpos( $settings_view, 'an Update of a live auction keeps the Brand it was listed with' ), true );
ob_start();
TCGiant_Sync_Listing_Options::render_product_section( item( 4601 ), 'FixedPriceItem' );
$html = (string) ob_get_clean();
check( 'the eBay tab: a live auction\'s Update keeps the Brand and MPN it was listed with', false !== strpos( $html, 'A live auction&#039;s Update keeps the Brand and MPN it was listed with' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 5 - R5-1: AN UPDATE THAT SENDS NO ITEMSPECIFICS JUDGED BY WHAT ITS LISTING CARRIES\n" . str_repeat( '=', 124 ) . "\n";

// Review 5's probe life5.php L6: a category requiring Brand, the Brand field
// alone, no other item specifics - listed by a whole push (which records
// what eBay took), then its Update.
shop( '31387', 'FixedPriceItem', $off );
fresh_ebay();
$API->aspects = array( array( 'name' => 'Brand', 'required' => true ) );
item( 4701, opts( array( 'brand' => 'Zeta' ) ), array( 'attributes' => array() ) );
list( $sites, $calls ) = push_whole( 4701 );
check( 'R5-1 (review 5\'s probe L6): the Brand field alone, a category requiring Brand - listed with Brand=Zeta, recorded', array( $sites, specifics_of( $calls[0][1] ?? '' ), record_of( 4701 ) ), array( array( 'AddFixedPriceItem@0' ), array( 'Brand=Zeta' ), $listed_zeta ) );
list( $res, $sites, $calls ) = push( wc_get_product( 4701 ) );
check( '  its Update, which sends no ItemSpecifics (eBay keeps the Brand it has): goes, judged by the Brand recorded on the listing', array( code_of( $res ), $sites, specifics_of( $calls[0][1] ?? '' ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), null ) );
list( $sites, $calls ) = push_whole( 4701 );
check( '  and as the queue runs it: the record left as it is', array( $sites, specifics_of( $calls[0][1] ?? '' ), record_of( 4701 ) ), array( array( 'ReviseFixedPriceItem@0' ), null, $listed_zeta ) );
// The same with nothing recorded: 3.37.0's refusal, its words.
list( $res, $sites ) = push( item( 4702, opts( array( 'brand' => 'Zeta' ) ) + $PUSHED + array( '_ebay_item_id' => '110000004702' ), array( 'attributes' => array() ) ) );
$refusal = 'eBay requires these item specifics for the selected category and they are empty: Brand. Add them as product attributes (Product data → Attributes), then push again.';
check( '  nothing recorded (listed before 3.38.0): refused before eBay, as before', array( code_of( $res ), message_of( $res ), $sites ), array( 'missing_required_aspects', $refusal, array() ) );
list( $res, $sites ) = push( item( 4703, $PUSHED + array( '_ebay_item_id' => '110000004703' ), array( 'attributes' => array() ) ) );
check( '  the field empty (3.37.0\'s case): refused before eBay, with 3.37.0\'s words', array( code_of( $res ), message_of( $res ), $sites ), array( 'missing_required_aspects', $refusal, array() ) );
list( $res, $sites ) = push( item( 4704, $PUSHED + array( '_ebay_item_id' => '110000004704', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000004799', 'specifics' => array( 'Brand' => 'Zeta' ) ) ), array( 'attributes' => array() ) ) );
check( '  a record of another listing (a relist made on eBay): refused, as nothing recorded applies', array( code_of( $res ), $sites ), array( 'missing_required_aspects', array() ) );
// An Update whose ItemSpecifics go replaces eBay's whole set: judged by what it sends.
list( $res, $sites ) = push( item( 4705, $PUSHED + array( '_ebay_item_id' => '110000004705', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000004705', 'specifics' => array( 'Brand' => 'Zeta' ) ) ) ) );
check( '  the field emptied since and the Update sending Colour=Red alone: refused, as its ItemSpecifics would take the recorded Brand off', array( code_of( $res ), $sites ), array( 'missing_required_aspects', array() ) );
list( $res, $sites, $calls ) = push( item( 4706, opts( array( 'brand' => 'Zeta' ) ) + $PUSHED + array( '_ebay_item_id' => '110000004706', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000004706', 'specifics' => array( 'Brand' => 'Zeta' ) ) ) ) );
check( '  the field with Colour=Red: met by the Brand it sends', array( code_of( $res ), $sites, specifics_of( $calls[0][1] ?? '' ) ), array( 'updated', array( 'ReviseFixedPriceItem@0' ), array( 'Brand=Zeta', 'Colour=Red' ) ) );
check( '  find_missing_required_aspects() as an Update: met by the record, not without one', array(
	exporter()->find_missing_required_aspects( wc_get_product( 4701 ), exporter()->get_export_settings( 4701 ), true ),
	exporter()->find_missing_required_aspects( wc_get_product( 4702 ), exporter()->get_export_settings( 4702 ), true ),
), array( array(), array( 'Brand' ) ) );
// Review 5's L6, its step 4: the product held Ended, a push that would make a
// new listing in its place - judged as that new listing, which sends the field.
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = ended_in( '110000004707', '31387' );
list( $res, $sites, $calls ) = push( item( 4707, opts( array( 'brand' => 'Zeta' ) ) + array( '_ebay_listing_status' => 'Ended', '_ebay_item_id' => '110000004707' ) + $PUSHED, array( 'attributes' => array() ) ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
check( '  held Ended, nothing recorded: not refused before eBay - the revise eBay refuses as ended, then the new listing in its place with Brand=Zeta', array( code_of( $res ), $sites, specifics_of( end( $calls )[1] ?? '' ) ), array( 'relisted', array( 'ReviseFixedPriceItem@0', 'GetItem@0', 'AddFixedPriceItem@0' ), array( 'Brand=Zeta' ) ) );
item( 4708, array( '_ebay_listing_status' => 'Ended', '_ebay_item_id' => '110000004708' ) + $PUSHED, array( 'attributes' => array() ) );
list( $res, $sites ) = push( wc_get_product( 4708 ) );
check( '  held Ended, the field empty: refused before eBay, as 3.37.0', array( code_of( $res ), message_of( $res ), $sites ), array( 'missing_required_aspects', $refusal, array() ) );
shop();
fresh_ebay();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 5 - R5-3: REVIEW 5'S SURVIVING MUTANTS (V02, V04, V05, V07; V01 IN test-template-resend.php)\n" . str_repeat( '=', 124 ) . "\n";

// V02: a set carrying the recorded Brand but another MPN is not carried.
check( 'V02: resent() with Brand=Acme and MPN=ZT-1 recorded, the set carrying Brand=Acme and MPN=ZT-2 - both recorded go, ZT-1 in place of ZT-2', TCGiant_Sync_Listing_Options::resent( array( 'Brand' => 'Acme', 'MPN' => 'ZT-2', 'Colour' => 'Red' ), array( 'Brand' => 'Acme', 'MPN' => 'ZT-1' ) ), array( 'Brand' => 'Acme', 'MPN' => 'ZT-1', 'Colour' => 'Red' ) );
// V04: a fixed-price Update's table carries the Brand its ItemSpecifics sends.
shop( '31387', 'FixedPriceItem', $off );
fresh_ebay();
item( 4801, opts( array( 'brand' => 'Zeta' ) ) + array( '_tcgiant_tpl_override' => $table ) );
push_whole( 4801 );
update_post_meta( 4801, TCGiant_Sync_Listing_Options::BRAND_META, 'Ozone' );
list( $sites, $calls ) = push_whole( 4801 );
$desc = (string) el( $calls[0][1] ?? '', 'Description' );
check( 'V04: the template\'s table on a fixed-price Update: the Brand it sends (Ozone, typed since), not the recorded Zeta; Re-send\'s too', array( $sites, specifics_of( $calls[0][1] ?? '' ), false !== strpos( $desc, 'Ozone' ), false !== strpos( $desc, 'Zeta' ), false !== strpos( $resend( 4801 )['html'], 'Ozone' ), false !== strpos( $resend( 4801 )['html'], 'Zeta' ) ), array( array( 'ReviseFixedPriceItem@0' ), array( 'Brand=Ozone', 'Colour=Red' ), true, false, true, false ) );
// V05: MPN as a variation axis, the MPN field filled.
$by_mpn = item( 4802, opts( array( 'mpn' => 'ZT-1' ) ), array( 'type' => 'variable', 'sku' => 'VAR-4802', 'attributes' => array( new WC_Product_Attribute( 'Colour', array( 'Red' ) ), new WC_Product_Attribute( 'MPN', array( 'A1', 'B2' ), false, true ) ) ), 'Opt_Variable' );
$by_mpn->variation_attributes = array( 'MPN' => array( 'A1', 'B2' ) );
$by_mpn->children             = array( 48021, 48022 );
item( 48021, array(), array( 'sku' => 'VAR-4802-A', 'size' => 'A1', 'attributes' => array() ), 'Opt_Variation' );
item( 48022, array(), array( 'sku' => 'VAR-4802-B', 'size' => 'B2', 'attributes' => array() ), 'Opt_Variation' );
$GLOBALS['W']['log'] = array();
list( $res, , $calls ) = push( $by_mpn );
$body = (string) ( $calls[0][1] ?? '' );
preg_match( '#<VariationSpecificsSet>(.*?)</VariationSpecificsSet>#s', $body, $vss );
check( 'V05: a variable product varying by MPN, the MPN field filled: no MPN at item level, MPN in each variation; said', array( code_of( $res ), specifics_of( $body ), preg_match_all( '#<Name>([^<]*)</Name>#', $vss[1] ?? '', $mm ) ? $mm[1] : array(), logged( 'Push: WC #4802 - MPN is one of its variations\' options, which eBay takes in each variation and refuses among the item\'s own item specifics too, so its MPN field is not sent as one.' ) ), array( 'created', array( 'Colour=Red' ), array( 'MPN' ), 'info' ) );
// V07: nothing said where the live auction's set carries the recorded Brand already.
shop( '31387', 'Chinese', $off );
fresh_ebay();
$GLOBALS['W']['log'] = array();
list( $res, $sites, $calls ) = push( item( 4803, array( '_ebay_listing_type' => 'Chinese' ) + $PUSHED + array( '_ebay_item_id' => '110000004803', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000004803', 'specifics' => array( 'Brand' => 'Zeta' ) ) ), array( 'attributes' => array( new WC_Product_Attribute( 'Brand', array( 'Zeta' ) ), new WC_Product_Attribute( 'Colour', array( 'Red' ) ) ) ) ) );
check( 'V07: a live auction recorded with Brand=Zeta, its Brand attribute Zeta, the field empty: the set as it is, and nothing said', array( code_of( $res ), $sites, specifics_of( $calls[0][1] ?? '' ), logged( 'WC #4803 - its Update sends again' ), logged( 'WC #4803 - the Brand and MPN' ) ), array( 'updated', array( 'ReviseItem@0' ), array( 'Brand=Zeta', 'Colour=Red' ), null, null ) );
shop();
fresh_ebay();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 5 - R5-2 AND R5-4: THE WORDS\n" . str_repeat( '=', 124 ) . "\n";

check( 'R5-2: a live auction\'s Update with nothing recorded says why, by the record, naming the cases', array( TCGiant_Sync_Listing_Options::notes_said( 1, 'Push', array( 'specifics:live' ) ), TCGiant_Sync_Listing_Options::notes_said( 1, 'Push', array( 'woo_brand:live' ) ) ), array( 'Push: WC #1 - the Brand and MPN fields are not sent to a live auction, whose item specifics eBay does not let change once it has a bid or is within 12 hours of its end: its item specifics go without them, as TCGiant Sync has no record of this listing being sent them (listed before 3.38.0, relisted on eBay, or linked by the import after an unfinished push), and a new listing takes the fields.', 'Push: WC #1 - its WooCommerce brand is not sent as eBay\'s Brand to a live auction, whose item specifics eBay does not let change once it has a bid or is within 12 hours of its end: its item specifics go without it, as TCGiant Sync has no record of this listing being sent it (listed before 3.38.0, relisted on eBay, or linked by the import after an unfinished push), and a new listing takes it.' ) );
check( 'R5-4: uninstall.php says the record holds the Brand and MPN a live auction\'s Update sends again', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ), "what a reinstalled plugin may take off eBay\n// again, and the Brand and MPN a live auction's Update sends again;" ), true );

done();
