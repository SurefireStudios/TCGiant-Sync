<?php
/**
 * Harness: each imported product holds its eBay listing's shipping, for a
 * shop's own code to read (3.27.6).
 *
 * Jon works out his WooCommerce checkout's shipping from his eBay Business
 * Policies. The import read Item.ShippingDetails, but kept only the first
 * domestic flat cost, and only to add it to the price when "Add eBay Shipping
 * Cost to Product Price" was on; SellerProfiles was never kept at all.
 *
 * Every product the import saves now holds _ebay_shipping_policy_id and
 * _ebay_shipping_policy_name (SellerProfiles.SellerShippingProfile),
 * _ebay_shipping_type, _ebay_shipping_service, _ebay_shipping_cost and
 * _ebay_shipping_currency (the domestic service eBay offers first), and
 * _ebay_shipping_services (every service), from the item in hand - eBay is
 * not asked again - and a listing read in brief wipes none of it.
 *
 * From 3.27.8 the listing's return and payment policies are kept beside its
 * shipping one, by the same rule (_ebay_return_policy_id and _name,
 * _ebay_payment_policy_id and _name), for 3.28.0's revise of imported
 * listings; nothing reads them yet.
 *
 * The listings are eBay's XML, turned into arrays exactly as the API class
 * turns its answers (SimpleXML, then JSON), so the shapes are the real ones:
 * one service or several, an amount whose currencyID is lost in the turning.
 *
 * Loaded for real: TCGiant_Sync_Mapper (map_ebay_to_woo() and
 * save_as_product()), TCGiant_Sync_API (its value helpers), TCGiant_Sync_Sites,
 * TCGiant_Sync_Runtime, TCGiant_Sync_Listing_Link and TCGiant_Sync_Exporter.
 * WordPress and WooCommerce are played here, from one array of post meta.
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ . '/' );
define( 'TCGIANT_SYNC_VERSION', '3.27.6' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-84s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 200 ) );
}

// --- WordPress ---------------------------------------------------------------

$META     = array();
$POSTS    = array();
$OPTIONS  = array();
$LOG      = array();
$SETTINGS = array();
$NEXT_ID  = 900;

function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['META'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['META'][ (int) $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['META'][ (int) $id ][ $key ] ); return true; }
function get_post_status( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['status'] ?? false; }
function get_post_type( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['type'] ?? false; }
function get_post_field( $field, $id ) { return 'post_title' === $field ? ( $GLOBALS['POSTS'][ (int) $id ]['title'] ?? '' ) : ''; }
function wp_get_post_parent_id( $id ) { return 0; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['OPTIONS'][ $name ] ); return true; }
function get_transient( $name ) { return false; }
function set_transient( $name, $value, $ttl = 0 ) { return true; }
function delete_transient( $name ) { return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function do_action() {}
function add_action() { return true; }
function add_filter() { return true; }
function __( $text, $domain = null ) { return $text; }
function sanitize_title( $text ) { return strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $text ) ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wc_attribute_label( $name, $product = null ) { return $name; }
function wc_format_decimal( $number, $dp = false ) { return false === $dp ? (string) $number : number_format( (float) $number, (int) $dp, '.', '' ); }
function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
function term_exists( $term, $taxonomy = '' ) { return array( 'term_id' => 7, 'term_taxonomy_id' => 7 ); }
function wp_insert_term( $term, $taxonomy = '' ) { return array( 'term_id' => 7, 'term_taxonomy_id' => 7 ); }
function get_term_by( $field, $value, $taxonomy = '' ) { return (object) array( 'term_id' => 7 ); }
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/** What save_as_product() asks of the database itself: the product holding an item number. */
class Harness_wpdb {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_row( $query ) {
		foreach ( $GLOBALS['META'] as $id => $meta ) {
			if ( (string) ( $meta['_ebay_item_id'] ?? '' ) === (string) ( $query[1][0] ?? '' ) && 'product' === get_post_type( $id ) ) {
				return (object) array( 'post_id' => (string) $id, 'post_status' => get_post_status( $id ) );
			}
		}
		return null;
	}
	public function get_var( $query ) { return null; }
	public function get_col( $query ) { return array(); }
}
$wpdb = new Harness_wpdb();

// --- WooCommerce -------------------------------------------------------------

function wc_get_product_id_by_sku( $sku ) {
	foreach ( $GLOBALS['META'] as $id => $meta ) {
		if ( '' !== (string) $sku && (string) ( $meta['_sku'] ?? '' ) === (string) $sku ) {
			return (int) $id;
		}
	}
	return 0;
}

/** A product: its meta read when loaded, written back - only what changed - when saved. */
class WC_Product {
	protected $id;
	protected $meta    = array();
	protected $changed = array();
	public function __construct( $id = 0 ) { $this->id = (int) $id; $this->meta = $GLOBALS['META'][ $this->id ] ?? array(); }
	public function get_id() { return $this->id; }
	public function get_name() { return get_post_field( 'post_title', $this->id ); }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; $this->changed[ $key ] = true; }
	public function delete_meta_data( $key ) { if ( array_key_exists( $key, $this->meta ) ) { $this->meta[ $key ] = null; $this->changed[ $key ] = true; } }
	public function set_sku( $sku ) { $this->update_meta_data( '_sku', $sku ); }
	public function get_attributes() { return array(); }
	public function get_children() { return array(); }
	public function is_type( $type ) { return 'simple' === $type; }
	public function is_in_stock() { return true; }
	public function managing_stock() { return true; }
	public function get_stock_quantity() { return 1; }
	public function set_description( $description ) { $GLOBALS['SET_DESC'][ $this->id ] = $description; }
	public function save() {
		if ( 0 === $this->id ) {
			$this->id = ++$GLOBALS['NEXT_ID'];
			$GLOBALS['POSTS'][ $this->id ] = array( 'type' => 'product', 'status' => 'publish', 'title' => 'New' );
		}
		foreach ( array_keys( $this->changed ) as $key ) {
			if ( null === $this->meta[ $key ] ) {
				unset( $GLOBALS['META'][ $this->id ][ $key ], $this->meta[ $key ] );
			} else {
				$GLOBALS['META'][ $this->id ][ $key ] = $this->meta[ $key ];
			}
		}
		$this->changed = array();
		return $this->id;
	}
	public function __call( $name, $args ) { return null; }
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variable extends WC_Product {
	public function is_type( $type ) { return 'variable' === $type; }
}
/** A variation: its own meta, kept apart, and its parent. */
class WC_Product_Variation extends WC_Product {
	public function set_parent_id( $id ) { $this->update_meta_data( '_harness_parent', (int) $id ); }
	public function get_parent_id() { return (int) $this->get_meta( '_harness_parent' ); }
	public function save() {
		if ( 0 === $this->id ) {
			$this->id = ++$GLOBALS['NEXT_ID'];
			$GLOBALS['POSTS'][ $this->id ] = array( 'type' => 'product_variation', 'status' => 'publish', 'title' => 'Variation' );
		}
		return parent::save();
	}
}
class WC_Product_Attribute {
	public function __call( $name, $args ) { return null; }
}
function wc_get_product( $id ) {
	$type = $GLOBALS['POSTS'][ (int) $id ]['type'] ?? '';
	if ( '' === $type ) {
		return false;
	}
	return 'product_variation' === $type ? new WC_Product_Variation( $id ) : new WC_Product( $id );
}

// --- The plugin's other classes, as far as these paths go ----------------------

class TCGiant_Sync_OAuth {
	public static function instance() { return new self(); }
	public function get_settings() { return $GLOBALS['SETTINGS']; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS = array();
	public static function begin_ebay_origin() {}
	public static function end_ebay_origin() {}
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
}
class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = $message; }
	public static function warning( $message ) { $GLOBALS['LOG'][] = $message; }
	public static function error( $message ) { $GLOBALS['LOG'][] = $message; }
	public static function write_now( $message, $level = 'error' ) { $GLOBALS['LOG'][] = $message; }
}
class TCGiant_Sync_Image_Localizer {
	public static function set_external_images() {}
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-exporter.php';
require $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require $ROOT . '/includes/class-tcgiant-sync-mapper.php';

/**
 * A listing as eBay's GetItem answers, turned into an array the way
 * TCGiant_Sync_API::trading_api_request() turns every answer.
 */
function ebay_item( $inner ) {
	$xml = '<?xml version="1.0" encoding="UTF-8"?><GetItemResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack><Item>'
		. '<ItemID>110000000001</ItemID><Title>Charizard Holo 4/102</Title><Currency>USD</Currency><Site>US</Site>'
		. '<Quantity>1</Quantity><ListingType>FixedPriceItem</ListingType><ListingDuration>GTC</ListingDuration>'
		. '<SellingStatus><ListingStatus>Active</ListingStatus><QuantitySold>0</QuantitySold><CurrentPrice currencyID="USD">99.0</CurrentPrice></SellingStatus>'
		. '<ItemSpecifics><NameValueList><Name>Set</Name><Value>Base Set</Value></NameValueList></ItemSpecifics>'
		. '<PictureDetails><PictureURL>https://i.ebayimg.com/1.jpg</PictureURL></PictureDetails>'
		. $inner . '</Item></GetItemResponse>';
	$parsed = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA );
	$array  = json_decode( wp_json_encode( $parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), true );
	return $array['Item'];
}

$POLICIES = '<SellerProfiles><SellerShippingProfile><ShippingProfileID>212345678011</ShippingProfileID><ShippingProfileName>Cards - First Class</ShippingProfileName></SellerShippingProfile>'
	. '<SellerReturnProfile><ReturnProfileID>212345678022</ReturnProfileID><ReturnProfileName>30 days</ReturnProfileName></SellerReturnProfile>'
	. '<SellerPaymentProfile><PaymentProfileID>212345678033</PaymentProfileID><PaymentProfileName>eBay Payments</PaymentProfileName></SellerPaymentProfile></SellerProfiles>';

$ONE = '<ShippingDetails><ShippingType>Flat</ShippingType><ShippingServiceOptions><ShippingService>USPSFirstClass</ShippingService>'
	. '<ShippingServiceCost currencyID="USD">4.5</ShippingServiceCost><ShippingServiceAdditionalCost currencyID="USD">1.0</ShippingServiceAdditionalCost>'
	. '<ShippingServicePriority>1</ShippingServicePriority><ExpeditedService>false</ExpeditedService></ShippingServiceOptions></ShippingDetails>';

// Several, the one offered first not first in the answer.
$SEVERAL = '<ShippingDetails><ShippingType>Flat</ShippingType>'
	. '<ShippingServiceOptions><ShippingService>USPSPriority</ShippingService><ShippingServiceCost currencyID="USD">9.25</ShippingServiceCost><ShippingServiceAdditionalCost currencyID="USD">2.0</ShippingServiceAdditionalCost><ShippingServicePriority>2</ShippingServicePriority></ShippingServiceOptions>'
	. '<ShippingServiceOptions><ShippingService>USPSFirstClass</ShippingService><ShippingServiceCost currencyID="USD">4.5</ShippingServiceCost><ShippingServicePriority>1</ShippingServicePriority></ShippingServiceOptions>'
	. '<InternationalShippingServiceOption><ShippingService>USPSFirstClassMailInternational</ShippingService><ShippingServiceCost currencyID="USD">16.0</ShippingServiceCost><ShippingServicePriority>1</ShippingServicePriority><ShipToLocation>Worldwide</ShipToLocation></InternationalShippingServiceOption>'
	. '<InternationalShippingServiceOption><ShippingService>USPSPriorityMailInternational</ShippingService><ShippingServiceCost currencyID="USD">32.5</ShippingServiceCost><ShippingServicePriority>2</ShippingServicePriority><ShipToLocation>CA</ShipToLocation></InternationalShippingServiceOption>'
	. '</ShippingDetails>';

$FREE = '<ShippingDetails><ShippingType>Flat</ShippingType><ShippingServiceOptions><ShippingService>USPSGroundAdvantage</ShippingService>'
	. '<ShippingServiceCost currencyID="USD">0.0</ShippingServiceCost><ShippingServicePriority>1</ShippingServicePriority><FreeShipping>true</FreeShipping></ShippingServiceOptions></ShippingDetails>';

$CALCULATED = '<ShippingDetails><ShippingType>Calculated</ShippingType><ShippingServiceOptions><ShippingService>USPSPriority</ShippingService>'
	. '<ShippingServicePriority>1</ShippingServicePriority></ShippingServiceOptions>'
	. '<CalculatedShippingRate><OriginatingPostalCode>95125</OriginatingPostalCode></CalculatedShippingRate></ShippingDetails>';

/** The meta map_ebay_to_woo() gives the product for these keys, and what it says to delete. */
function mapped( array $item ) {
	$data = ( new TCGiant_Sync_Mapper() )->map_ebay_to_woo( $item );
	$meta = array_intersect_key( $data['meta'], array_flip( TCGiant_Sync_Mapper::SHIPPING_META_KEYS ) );
	ksort( $meta );
	return array( $meta, $data['meta_delete'] );
}

/** One of the shop's products, imported from item 110000000001, holding this meta. */
function shop_product( $id, array $meta = array() ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => 'product', 'status' => 'publish', 'title' => 'Charizard ' . $id );
	$GLOBALS['META'][ $id ]  = array_merge( array( '_sku' => 'CHZ-' . $id, '_ebay_item_id' => '110000000001' ), $meta );
}

/** The import saves the listing: mapped, then saved, as every import path does. */
function import( array $item ) {
	$mapper = new TCGiant_Sync_Mapper();
	$data   = $mapper->map_ebay_to_woo( $item );
	return $mapper->save_as_product( $data );
}

function meta( $id, $key ) { return $GLOBALS['META'][ (int) $id ][ $key ] ?? null; }

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT EBAY'S XML LOOKS LIKE ONCE THE API CLASS HAS TURNED IT INTO AN ARRAY\n" . str_repeat( '=', 130 ) . "\n";

$item = ebay_item( $ONE );
check( 'one service: the service itself, not a list of one', array_keys( $item['ShippingDetails']['ShippingServiceOptions'] ), array( 'ShippingService', 'ShippingServiceCost', 'ShippingServiceAdditionalCost', 'ShippingServicePriority', 'ExpeditedService' ) );
check( '  its cost a bare string: the currencyID is gone', $item['ShippingDetails']['ShippingServiceOptions']['ShippingServiceCost'], '4.5' );
$item = ebay_item( $SEVERAL );
check( 'several: a list', array( count( $item['ShippingDetails']['ShippingServiceOptions'] ), count( $item['ShippingDetails']['InternationalShippingServiceOption'] ) ), array( 2, 2 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE KEYS A LISTING GIVES ITS PRODUCT\n" . str_repeat( '=', 130 ) . "\n";

list( $meta, $delete ) = mapped( ebay_item( $ONE . $POLICIES ) );
check( 'one service, with its policy', $meta, array(
	'_ebay_shipping_cost'        => '4.50',
	'_ebay_shipping_currency'    => 'USD',
	'_ebay_shipping_policy_id'   => '212345678011',
	'_ebay_shipping_policy_name' => 'Cards - First Class',
	'_ebay_shipping_service'     => 'USPSFirstClass',
	'_ebay_shipping_services'    => array( array( 'service' => 'USPSFirstClass', 'cost' => '4.50', 'additional_cost' => '1.00', 'priority' => 1, 'free' => false, 'international' => false ) ),
	'_ebay_shipping_type'        => 'Flat',
) );
check( '  nothing to delete', $delete, array() );

list( $meta, $delete ) = mapped( ebay_item( $SEVERAL . $POLICIES ) );
check( 'several: the domestic service offered first, by its priority, not by where it is in the answer', array( $meta['_ebay_shipping_service'], $meta['_ebay_shipping_cost'] ), array( 'USPSFirstClass', '4.50' ) );
check( '  every service, domestic and international, in the order eBay sent them', $meta['_ebay_shipping_services'], array(
	array( 'service' => 'USPSPriority', 'cost' => '9.25', 'additional_cost' => '2.00', 'priority' => 2, 'free' => false, 'international' => false ),
	array( 'service' => 'USPSFirstClass', 'cost' => '4.50', 'additional_cost' => '', 'priority' => 1, 'free' => false, 'international' => false ),
	array( 'service' => 'USPSFirstClassMailInternational', 'cost' => '16.00', 'additional_cost' => '', 'priority' => 1, 'free' => false, 'international' => true ),
	array( 'service' => 'USPSPriorityMailInternational', 'cost' => '32.50', 'additional_cost' => '', 'priority' => 2, 'free' => false, 'international' => true ),
) );

list( $meta ) = mapped( ebay_item( $FREE ) );
check( 'free shipping: cost 0.00, free', array( $meta['_ebay_shipping_cost'], $meta['_ebay_shipping_services'][0]['free'], $meta['_ebay_shipping_services'][0]['cost'] ), array( '0.00', true, '0.00' ) );

list( $meta ) = mapped( ebay_item( $CALCULATED ) );
check( 'calculated: its type, its service, and no cost of its own', array( $meta['_ebay_shipping_type'], $meta['_ebay_shipping_service'], $meta['_ebay_shipping_cost'], $meta['_ebay_shipping_services'][0]['cost'] ), array( 'Calculated', 'USPSPriority', '', '' ) );

list( $meta ) = mapped( ebay_item( '<ShippingDetails><ShippingType>Flat</ShippingType><ShippingServiceOptions><ShippingService>USPSFirstClass</ShippingService><ShippingServicePriority>1</ShippingServicePriority></ShippingServiceOptions></ShippingDetails>' ) );
check( 'a flat service whose cost the answer left out: its service, and no cost to wipe the one stored', array( $meta['_ebay_shipping_service'], array_key_exists( '_ebay_shipping_cost', $meta ) ), array( 'USPSFirstClass', false ) );

list( $meta, $delete ) = mapped( ebay_item( $ONE ) );
check( 'no SellerProfiles in the answer: no policy key written, none deleted', array( isset( $meta['_ebay_shipping_policy_id'] ), isset( $meta['_ebay_shipping_policy_name'] ), $delete ), array( false, false, array() ) );

list( $meta, $delete ) = mapped( ebay_item( $ONE . '<SellerProfiles><SellerReturnProfile><ReturnProfileID>1</ReturnProfileID><ReturnProfileName>30 days</ReturnProfileName></SellerReturnProfile></SellerProfiles>' ) );
check( 'SellerProfiles in the answer, with no shipping policy: the policy keys to go', array_values( array_intersect( $delete, TCGiant_Sync_Mapper::SHIPPING_META_KEYS ) ), array( '_ebay_shipping_policy_id', '_ebay_shipping_policy_name' ) );

// The currency where a converter keeps the cost's currencyID, and where it is the listing's own.
$kept = ebay_item( $ONE );
$kept['Currency'] = 'USD';
$kept['ShippingDetails']['ShippingServiceOptions']['ShippingServiceCost'] = array( '@attributes' => array( 'currencyID' => 'CAD' ), '#text' => '4.5' );
list( $meta ) = mapped( $kept );
check( 'a currencyID that survived is the currency; the cost read from it all the same', array( $meta['_ebay_shipping_currency'], $meta['_ebay_shipping_cost'] ), array( 'CAD', '4.50' ) );
$gb = ebay_item( $ONE );
$gb['Currency'] = 'GBP';
check( 'one that did not: the listing\'s own currency', mapped( $gb )[0]['_ebay_shipping_currency'], 'GBP' );

// A delta row kept in brief (compact_delta_item()): no shipping at all in it.
$brief = array( 'ItemID' => '110000000001', 'Quantity' => '1', 'SellingStatus' => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0' ), 'Title' => 'Charizard', 'Site' => 'US' );
list( $meta, $delete ) = mapped( $brief );
check( 'a row read in brief: no shipping key written or deleted', array( $meta, $delete ), array( array(), array() ) );

// The price, with "Add eBay Shipping Cost to Product Price" on: as it was -
// the first service eBay sent, whatever its priority.
$SETTINGS = array( 'bake_shipping_into_price' => '1' );
$data     = ( new TCGiant_Sync_Mapper() )->map_ebay_to_woo( ebay_item( $SEVERAL ) );
$SETTINGS = array();
check( 'the shipping added to the price is still the first in the answer (99.00 + 9.25)', $data['regular_price'], '108.25' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SAVE: WRITTEN ON THE PRODUCT, NEVER WIPED BY A ROW WITHOUT THEM\n" . str_repeat( '=', 130 ) . "\n";

shop_product( 501, array( '_ebay_export_fulfillment_policy' => '987654321', '_ebay_export_status' => 'pushed' ) );
import( ebay_item( $SEVERAL . $POLICIES ) );
check( 'a full read: the policy, the service and every option on the product', array( meta( 501, '_ebay_shipping_policy_name' ), meta( 501, '_ebay_shipping_policy_id' ), meta( 501, '_ebay_shipping_service' ), meta( 501, '_ebay_shipping_cost' ), meta( 501, '_ebay_shipping_currency' ), meta( 501, '_ebay_shipping_type' ), count( (array) meta( 501, '_ebay_shipping_services' ) ) ), array( 'Cards - First Class', '212345678011', 'USPSFirstClass', '4.50', 'USD', 'Flat', 4 ) );
check( '  the shop\'s own policy for pushing left as it was', meta( 501, '_ebay_export_fulfillment_policy' ), '987654321' );
check( '  as a shop\'s own code reads it', get_post_meta( 501, '_ebay_shipping_policy_name', true ), 'Cards - First Class' );

$before = $META[501];
import( $brief );
check( 'a row read in brief, saved: every shipping key as it was', array_intersect_key( $META[501], array_flip( TCGiant_Sync_Mapper::SHIPPING_META_KEYS ) ), array_intersect_key( $before, array_flip( TCGiant_Sync_Mapper::SHIPPING_META_KEYS ) ) );
import( ebay_item( $ONE ) );
check( 'a read with shipping but no SellerProfiles: the shipping updated, the policy kept', array( meta( 501, '_ebay_shipping_services' )[0]['service'] ?? null, count( (array) meta( 501, '_ebay_shipping_services' ) ), meta( 501, '_ebay_shipping_policy_name' ) ), array( 'USPSFirstClass', 1, 'Cards - First Class' ) );
import( ebay_item( $ONE . '<SellerProfiles><SellerPaymentProfile><PaymentProfileID>2</PaymentProfileID><PaymentProfileName>eBay Payments</PaymentProfileName></SellerPaymentProfile></SellerProfiles>' ) );
check( 'a read whose SellerProfiles hold no shipping policy: the policy goes, nothing else', array( meta( 501, '_ebay_shipping_policy_id' ), meta( 501, '_ebay_shipping_policy_name' ), meta( 501, '_ebay_shipping_service' ), meta( 501, '_ebay_export_fulfillment_policy' ) ), array( null, null, 'USPSFirstClass', '987654321' ) );

// A variable listing: on the parent, and on no variation.
$variable = ebay_item( $ONE . $POLICIES
	. '<Variations><Variation><SKU>CHZ-NM</SKU><StartPrice currencyID="USD">99.0</StartPrice><Quantity>1</Quantity><SellingStatus><QuantitySold>0</QuantitySold></SellingStatus>'
	. '<VariationSpecifics><NameValueList><Name>Condition</Name><Value>Near Mint</Value></NameValueList></VariationSpecifics></Variation>'
	. '<Variation><SKU>CHZ-LP</SKU><StartPrice currencyID="USD">79.0</StartPrice><Quantity>2</Quantity><SellingStatus><QuantitySold>0</QuantitySold></SellingStatus>'
	. '<VariationSpecifics><NameValueList><Name>Condition</Name><Value>Lightly Played</Value></NameValueList></VariationSpecifics></Variation></Variations>' );
$variable['ItemID'] = '110000000077';
$parent = import( $variable );
$children = array_keys( array_filter( $POSTS, function ( $p ) { return 'product_variation' === $p['type']; } ) );
check( 'a variable listing: the keys on the parent product', array( 'product' === get_post_type( $parent ), meta( $parent, '_ebay_shipping_policy_name' ), meta( $parent, '_ebay_shipping_cost' ) ), array( true, 'Cards - First Class', '4.50' ) );
check( '  and on none of its variations', array( count( $children ) > 0, array_filter( array_map( function ( $v ) { return meta( $v, '_ebay_shipping_policy_name' ); }, $children ) ) ), array( true, array() ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHERE THE SHOP SEES IT, AND WHAT GOES WITH THE PLUGIN\n" . str_repeat( '=', 130 ) . "\n";

shop_product( 601, array( '_ebay_shipping_policy_name' => 'Cards - First Class', '_ebay_shipping_service' => 'USPSFirstClass', '_ebay_shipping_cost' => '4.50', '_ebay_shipping_currency' => 'USD', '_ebay_shipping_type' => 'Flat' ) );
check( 'the eBay tab\'s line', TCGiant_Sync_Mapper::stored_shipping_line( 601 ), 'Cards - First Class - USPSFirstClass 4.50 USD' );
shop_product( 602, array( '_ebay_shipping_service' => 'USPSGroundAdvantage', '_ebay_shipping_cost' => '0.00' ) );
check( '  free, with no policy', TCGiant_Sync_Mapper::stored_shipping_line( 602 ), 'USPSGroundAdvantage free' );
shop_product( 603, array( '_ebay_shipping_service' => 'USPSPriority', '_ebay_shipping_cost' => '', '_ebay_shipping_type' => 'Calculated' ) );
check( '  calculated', TCGiant_Sync_Mapper::stored_shipping_line( 603 ), 'USPSPriority (Calculated)' );
shop_product( 604 );
check( '  nothing recorded: no line', TCGiant_Sync_Mapper::stored_shipping_line( 604 ), '' );

$admin     = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
$uninstall = (string) file_get_contents( $ROOT . '/uninstall.php' );
$mapper    = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-mapper.php' );
$importer  = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-importer.php' );
check( 'the product\'s eBay tab shows the line, read only', array( false !== strpos( $admin, '$tc_shipping = TCGiant_Sync_Mapper::stored_shipping_line( $product_id );' ), false !== strpos( $admin, "esc_html__( 'eBay shipping: %s', 'tcgiant-sync' )" ) ), array( true, true ) );
preg_match( "/DELETE FROM \{\\\$wpdb->prefix\}postmeta\s+WHERE meta_key IN \( ('_ebay_shipping[^)]*) \)/", $uninstall, $gone );
$gone = isset( $gone[1] ) ? array_map( function ( $k ) { return trim( $k, " '" ); }, explode( ',', $gone[1] ) ) : array();
check( 'deleting the plugin with its data deletes every one of the keys', array( array_values( array_diff( TCGiant_Sync_Mapper::SHIPPING_META_KEYS, $gone ) ), array_values( array_diff( $gone, TCGiant_Sync_Mapper::SHIPPING_META_KEYS ) ) ), array( array(), array() ) );
check( 'the import never writes the shop\'s policy for pushing', array( substr_count( $mapper, '_ebay_export_fulfillment_policy' ) <= 1, substr_count( $importer, '_ebay_export_fulfillment_policy' ) ), array( true, 0 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE LISTING'S OWN RETURN AND PAYMENT POLICIES (3.27.8)\n" . str_repeat( '=', 130 ) . "\n";

/** The return and payment keys map_ebay_to_woo() gives, and those it deletes. */
function policies_mapped( array $item ) {
	$data = ( new TCGiant_Sync_Mapper() )->map_ebay_to_woo( $item );
	$meta = array_intersect_key( $data['meta'], array_flip( TCGiant_Sync_Mapper::POLICY_META_KEYS ) );
	ksort( $meta );
	return array( $meta, array_values( array_intersect( $data['meta_delete'], TCGiant_Sync_Mapper::POLICY_META_KEYS ) ) );
}

check( 'all three policies in the answer: the return and payment ones, ID and name', policies_mapped( ebay_item( $ONE . $POLICIES ) ), array( array(
	'_ebay_payment_policy_id'   => '212345678033',
	'_ebay_payment_policy_name' => 'eBay Payments',
	'_ebay_return_policy_id'    => '212345678022',
	'_ebay_return_policy_name'  => '30 days',
), array() ) );
check( 'no SellerProfiles: none written, none deleted', policies_mapped( ebay_item( $ONE ) ), array( array(), array() ) );
check( 'a row read in brief: the same', policies_mapped( $brief ), array( array(), array() ) );
check( 'SellerProfiles with a return policy only: the payment keys to go', policies_mapped( ebay_item( $ONE . '<SellerProfiles><SellerReturnProfile><ReturnProfileID>1</ReturnProfileID><ReturnProfileName>30 days</ReturnProfileName></SellerReturnProfile></SellerProfiles>' ) ), array( array( '_ebay_return_policy_id' => '1', '_ebay_return_policy_name' => '30 days' ), array( '_ebay_payment_policy_id', '_ebay_payment_policy_name' ) ) );
check( 'a policy with no name: its ID, and no name written over the one stored', policies_mapped( ebay_item( $ONE . '<SellerProfiles><SellerPaymentProfile><PaymentProfileID>9</PaymentProfileID></SellerPaymentProfile></SellerProfiles>' ) )[0], array( '_ebay_payment_policy_id' => '9' ) );

shop_product( 701, array( '_ebay_export_status' => 'pushed', '_ebay_item_id' => '110000000701' ) );
$own = function ( array $item ) {
	$item['ItemID'] = '110000000701';
	$item['SKU']    = 'CHZ-701';
	return $item;
};
import( $own( ebay_item( $ONE . $POLICIES ) ) );
check( 'saved on the product', array( meta( 701, '_ebay_return_policy_id' ), meta( 701, '_ebay_return_policy_name' ), meta( 701, '_ebay_payment_policy_id' ), meta( 701, '_ebay_payment_policy_name' ) ), array( '212345678022', '30 days', '212345678033', 'eBay Payments' ) );
import( $own( $brief ) );
check( '  a row in brief wipes none of it', meta( 701, '_ebay_return_policy_id' ), '212345678022' );
import( $own( ebay_item( $ONE . '<SellerProfiles><SellerShippingProfile><ShippingProfileID>5</ShippingProfileID></SellerShippingProfile></SellerProfiles>' ) ) );
check( '  SellerProfiles with neither: both go', array( meta( 701, '_ebay_return_policy_id' ), meta( 701, '_ebay_return_policy_name' ), meta( 701, '_ebay_payment_policy_id' ) ), array( null, null, null ) );

preg_match( "/WHERE meta_key IN \( ('_ebay_return_policy_id[^)]*) \)/", $uninstall, $gone2 );
$gone2 = isset( $gone2[1] ) ? array_map( function ( $k ) { return trim( $k, " '" ); }, explode( ',', $gone2[1] ) ) : array();
check( 'deleting the plugin with its data deletes these four too, and only these', array( array_values( array_diff( TCGiant_Sync_Mapper::POLICY_META_KEYS, $gone2 ) ), array_values( array_diff( $gone2, TCGiant_Sync_Mapper::POLICY_META_KEYS ) ) ), array( array(), array() ) );
check( 'the import never writes the shop\'s own policies for pushing', array( substr_count( $mapper, '_ebay_export_return_policy' ), substr_count( $mapper, "'export_return_policy'" ) ), array( 0, 0 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA LISTING TCGIANT SENT, READ BACK WITH OVERWRITE DESCRIPTION ON (3.27.8)\n" . str_repeat( '=', 130 ) . "\n";

// What comes back from a templated listing is only what the template's
// cleaning kept of the product's description. While the listing holds what
// TCGiant sent it, the product's own description stays (push-path review, M3).
$GLOBALS['SETTINGS']['overwrite_desc'] = '1';
$read_back = function ( $id, $description ) {
	unset( $GLOBALS['SET_DESC'][ $id ] );
	$item           = ebay_item( $GLOBALS['ONE'] . '<Description><![CDATA[' . $description . ']]></Description>' );
	$item['ItemID'] = '11000000' . $id;
	$item['SKU']    = 'CHZ-' . $id;
	import( $item );
	return array_key_exists( $id, $GLOBALS['SET_DESC'] ?? array() ) ? $GLOBALS['SET_DESC'][ $id ] : null;
};

shop_product( 7021, array( '_ebay_item_id' => '110000007021', '_tcgiant_tpl_id' => '3', '_tcgiant_region_hash' => sha1( '<p>Near mint.</p>' ), '_tcgiant_desc_hash' => sha1( '<div>whole</div>' ) ) );
check( 'sent with a template, and it comes back as sent: the website description stays', $read_back( 7021, '<p>Near mint.</p>' ), null );
check( '  changed on eBay since: eBay wins, as the setting says', $read_back( 7021, '<p>Near mint, edited on eBay.</p>' ), '<p>Near mint, edited on eBay.</p>' );

shop_product( 7022, array( '_ebay_item_id' => '110000007022', '_tcgiant_tpl_id' => '0', '_tcgiant_ebay_description' => '<p>For eBay.</p>', '_tcgiant_desc_hash' => sha1( '<p>For eBay.</p>' ) ) );
check( 'sent its eBay-only description, which comes back whole: the website description stays', $read_back( 7022, '<p>For eBay.</p>' ), null );

shop_product( 7023, array( '_ebay_item_id' => '110000007023', '_tcgiant_desc_hash' => sha1( '<p>Plain.</p>' ) ) );
check( 'sent the plain description, as every shop before 3.27.8: written back, as 3.27.7 did', $read_back( 7023, '<p>Plain.</p>' ), '<p>Plain.</p>' );
shop_product( 7024, array( '_ebay_item_id' => '110000007024', '_tcgiant_tpl_id' => '0', '_tcgiant_region_hash' => '', '_tcgiant_desc_hash' => sha1( '<p>None.</p>' ) ) );
check( '  and one 3.27.8 sent with None: the same', $read_back( 7024, '<p>None.</p>' ), '<p>None.</p>' );

$GLOBALS['SETTINGS']['overwrite_desc'] = '0';
check( 'with Overwrite Description off, nothing is written either way', $read_back( 7021, '<p>Anything.</p>' ), null );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
