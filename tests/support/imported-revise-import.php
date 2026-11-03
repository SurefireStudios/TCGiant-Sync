<?php
/**
 * The import half of test-imported-revise.php (3.28.8): eBay's answers about
 * four listings - a PSA 10 card, a used caliper, an auction with bids and a
 * graded coin - imported through the real TCGiant_Sync_Mapper
 * (map_ebay_to_woo() and save_as_product()), and what each product then
 * holds printed as JSON, for the push half to send to eBay. A process of its
 * own: the mapper's WooCommerce is not the exporter's.
 *
 * The answers are eBay's XML turned into arrays exactly as the API class
 * turns them (SimpleXML, then JSON), so the shapes are the real ones.
 *
 * Loaded for real: TCGiant_Sync_Mapper, TCGiant_Sync_Ebay_Live,
 * TCGiant_Sync_API (its value helpers), TCGiant_Sync_Sites,
 * TCGiant_Sync_Runtime, TCGiant_Sync_Listing_Link and TCGiant_Sync_Exporter.
 *
 * usage: php tests/support/imported-revise-import.php
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__, 2 );

define( 'ABSPATH', $ROOT . '/' );
define( 'TCGIANT_SYNC_VERSION', '3.28.8' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

$META     = array();
$POSTS    = array();
$OPTIONS  = array();
$LOG      = array();
$SETTINGS = array( 'import_auctions' => '1' ); // A shop that imports its auctions (3.28.0).
$NEXT_ID  = 900;

function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['META'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['META'][ (int) $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['META'][ (int) $id ][ $key ] ); return true; }
function get_post_status( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['status'] ?? false; }
function get_post_type( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['type'] ?? false; }
function get_post_field( $field, $id ) { return $GLOBALS['POSTS'][ (int) $id ][ 'post_title' === $field ? 'title' : $field ] ?? ''; }
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
	public function get_results( $query, $output = null ) { return array(); }
	public function query( $query ) { return 0; }
	public function esc_like( $text ) { return $text; }
}
$wpdb = new Harness_wpdb();

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
	public function get_sku() { return (string) ( $this->meta['_sku'] ?? '' ); }
	public function get_attributes() { return array(); }
	public function get_children() { return array(); }
	public function is_type( $type ) { return 'simple' === $type; }
	public function is_in_stock() { return true; }
	public function managing_stock() { return true; }
	public function get_stock_quantity() { return 1; }
	public function save() {
		if ( 0 === $this->id ) {
			$this->id = ++$GLOBALS['NEXT_ID'];
			// Made now: after its listing started.
			$GLOBALS['POSTS'][ $this->id ] = array( 'type' => 'product', 'status' => 'publish', 'title' => 'New', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ) );
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
class WC_Product_Variation extends WC_Product {}
class WC_Product_Attribute {
	public function __call( $name, $args ) { return null; }
}
function wc_get_product( $id ) {
	return '' === ( $GLOBALS['POSTS'][ (int) $id ]['type'] ?? '' ) ? false : new WC_Product( $id );
}

class TCGiant_Sync_OAuth {
	public static function instance() { return new self(); }
	public function get_settings() { return $GLOBALS['SETTINGS']; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS        = array();
	const AUCTION_LISTED_KEY = '_ebay_auction_listed_item';
	public static function arm_record( $product, $before, $record, $holds = null ) { return null; }
	public static function disarm_record( $armed ) { return false; }
	public static function begin_ebay_origin() {}
	public static function end_ebay_origin() {}
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
	public static function table_exists() { return false; }
	public static function get_by_product( $id ) { return null; }
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
// The site's address, for the push mark a listing may carry (3.28.7).
if ( ! defined( 'TCGIANT_SYNC_IS_STAGING' ) ) {
	define( 'TCGIANT_SYNC_IS_STAGING', false );
}
require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-exporter.php';
require $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require $ROOT . '/includes/class-tcgiant-sync-mapper.php';

/** A listing as eBay's GetItem answers, turned into an array as the API class does. */
function ebay_item( $inner ) {
	$xml    = '<?xml version="1.0" encoding="UTF-8"?><GetItemResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack><Item>' . $inner . '</Item></GetItemResponse>';
	$parsed = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA );
	$array  = json_decode( wp_json_encode( $parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), true );
	return $array['Item'];
}

/** The import saves the listing: mapped, then saved, as every import path does. */
function import( array $item ) {
	$mapper = new TCGiant_Sync_Mapper();
	$data   = $mapper->map_ebay_to_woo( $item );
	return $mapper->save_as_product( $data );
}

function policies( $shipping, $return, $payment ) {
	return '<SellerProfiles><SellerShippingProfile><ShippingProfileID>' . $shipping . '</ShippingProfileID><ShippingProfileName>Ship</ShippingProfileName></SellerShippingProfile>'
		. '<SellerReturnProfile><ReturnProfileID>' . $return . '</ReturnProfileID><ReturnProfileName>Returns</ReturnProfileName></SellerReturnProfile>'
		. '<SellerPaymentProfile><PaymentProfileID>' . $payment . '</PaymentProfileID><PaymentProfileName>Pay</PaymentProfileName></SellerPaymentProfile></SellerProfiles>';
}

$common = '<Currency>USD</Currency><Site>US</Site><ListingDetails><StartTime>2026-01-05T10:00:00.000Z</StartTime><EndTime>2026-12-05T10:00:00.000Z</EndTime></ListingDetails>';

$CARD = '<ItemID>256100000001</ItemID><Title>Charizard Holo PSA 10</Title><SKU>CHZ-PSA10</SKU>' . $common
	. '<Quantity>1</Quantity><ListingType>FixedPriceItem</ListingType><ListingDuration>GTC</ListingDuration>'
	. '<SellingStatus><ListingStatus>Active</ListingStatus><QuantitySold>0</QuantitySold><CurrentPrice currencyID="USD">950.0</CurrentPrice></SellingStatus>'
	. '<PrimaryCategory><CategoryID>183454</CategoryID><CategoryName>Toys &amp; Hobbies:Collectible Card Games:CCG Individual Cards</CategoryName></PrimaryCategory>'
	. '<ConditionID>2750</ConditionID><ConditionDisplayName>Graded</ConditionDisplayName>'
	. '<ConditionDescriptors><ConditionDescriptor><Name>27501</Name><Value>275010</Value></ConditionDescriptor><ConditionDescriptor><Name>27502</Name><Value>275020</Value></ConditionDescriptor><ConditionDescriptor><Name>27503</Name><AdditionalInfo>81234567</AdditionalInfo></ConditionDescriptor></ConditionDescriptors>'
	. policies( '240000011', '240000012', '240000013' )
	. '<Location>Torrance, CA</Location><PostalCode>90501</PostalCode><Country>US</Country><DispatchTimeMax>1</DispatchTimeMax>'
	. '<PictureDetails><PictureURL>https://i.ebayimg.com/images/g/AAA/s-l1600.jpg?set_id=8800005007</PictureURL><PictureURL>https://i.ebayimg.com/images/g/BBB/s-l1600.jpg</PictureURL></PictureDetails>';

$PART = '<ItemID>256100000002</ItemID><Title>Brembo caliper, used</Title><SKU>BRM-CAL-7</SKU>' . $common
	. '<Quantity>2</Quantity><ListingType>FixedPriceItem</ListingType><ListingDuration>GTC</ListingDuration>'
	. '<SellingStatus><ListingStatus>Active</ListingStatus><QuantitySold>0</QuantitySold><CurrentPrice currencyID="USD">120.0</CurrentPrice></SellingStatus>'
	. '<PrimaryCategory><CategoryID>33564</CategoryID><CategoryName>eBay Motors:Parts &amp; Accessories:Car &amp; Truck Parts:Brakes:Calipers</CategoryName></PrimaryCategory>'
	. '<ConditionID>3000</ConditionID><ConditionDisplayName>Used</ConditionDisplayName><ConditionDescription>Light wear on the bracket.</ConditionDescription>'
	. policies( '240000021', '240000022', '240000023' )
	. '<Location>Columbus, OH</Location><PostalCode>43215</PostalCode><Country>US</Country><DispatchTimeMax>1</DispatchTimeMax>'
	. '<PictureDetails><PictureURL>https://i.ebayimg.com/images/g/CCC/s-l1600.jpg</PictureURL></PictureDetails>';

$AUCTION = '<ItemID>256100000003</ItemID><Title>Pikachu Illustrator raw</Title>' . $common
	. '<Quantity>1</Quantity><ListingType>Chinese</ListingType><ListingDuration>Days_7</ListingDuration><StartPrice currencyID="USD">37.0</StartPrice>'
	. '<SellingStatus><ListingStatus>Active</ListingStatus><QuantitySold>0</QuantitySold><BidCount>4</BidCount><CurrentPrice currencyID="USD">61.0</CurrentPrice></SellingStatus>'
	. '<PrimaryCategory><CategoryID>183454</CategoryID><CategoryName>Toys &amp; Hobbies:Collectible Card Games:CCG Individual Cards</CategoryName></PrimaryCategory>'
	. '<ConditionID>4000</ConditionID><ConditionDescriptors><ConditionDescriptor><Name>40001</Name><Value>400010</Value></ConditionDescriptor></ConditionDescriptors>'
	. policies( '240000031', '240000032', '240000033' )
	. '<Location>Torrance, CA</Location><PostalCode>90501</PostalCode><Country>US</Country><DispatchTimeMax>2</DispatchTimeMax>';

$COIN = '<ItemID>256100000004</ItemID><Title>1909-S VDB Lincoln cent NGC MS65</Title>' . $common
	. '<Quantity>1</Quantity><ListingType>FixedPriceItem</ListingType><ListingDuration>GTC</ListingDuration>'
	. '<SellingStatus><ListingStatus>Active</ListingStatus><QuantitySold>0</QuantitySold><CurrentPrice currencyID="USD">2400.0</CurrentPrice></SellingStatus>'
	. '<PrimaryCategory><CategoryID>11980</CategoryID><CategoryName>Coins &amp; Paper Money:Coins: US:Small Cents:Lincoln (1909-58)</CategoryName></PrimaryCategory>'
	. '<ConditionID>2750</ConditionID><ConditionDescriptors><ConditionDescriptor><Name>1</Name><Value>15</Value></ConditionDescriptor><ConditionDescriptor><Name>3</Name><Value>18</Value></ConditionDescriptor><ConditionDescriptor><Name>4</Name><Value>55</Value></ConditionDescriptor><ConditionDescriptor><Name>5</Name><AdditionalInfo>6123456-001</AdditionalInfo></ConditionDescriptor></ConditionDescriptors>'
	. policies( '240000041', '240000042', '240000043' )
	. '<Location>Dallas, TX</Location><PostalCode>75201</PostalCode><Country>US</Country><DispatchTimeMax>1</DispatchTimeMax>';

$out = array( 'answers' => array( 'card' => ebay_item( $CARD ), 'part' => ebay_item( $PART ), 'auction' => ebay_item( $AUCTION ), 'coin' => ebay_item( $COIN ) ) );

// The four imported, each a product the import makes.
foreach ( array( 'card' => $CARD, 'part' => $PART, 'auction' => $AUCTION, 'coin' => $COIN ) as $name => $xml ) {
	$pid                     = import( ebay_item( $xml ) );
	$out['imported'][ $name ] = array( 'id' => $pid, 'meta' => $META[ $pid ] );
}

// Read again from an answer without ConditionID, descriptors or SellerProfiles,
// as a list of changes may give it, and with a location of its own.
$card_id = $out['imported']['card']['id'];
$brief   = '<ItemID>256100000001</ItemID><Title>Charizard Holo PSA 10</Title><SKU>CHZ-PSA10</SKU>' . $common
	. '<Quantity>1</Quantity><ListingType>FixedPriceItem</ListingType><ListingDuration>GTC</ListingDuration>'
	. '<SellingStatus><ListingStatus>Active</ListingStatus><QuantitySold>0</QuantitySold><CurrentPrice currencyID="USD">950.0</CurrentPrice></SellingStatus>'
	. '<Location>Gardena, CA</Location><SellerProfiles></SellerProfiles>';
import( ebay_item( $brief ) );
$out['reread'] = $META[ $card_id ][ TCGiant_Sync_Ebay_Live::META ] ?? null;

// A product of the shop's, with the card's SKU and no listing: the import
// links it by its SKU to a listing made on eBay - imported, as one linked by
// hand is (3.28.8).
$GLOBALS['POSTS'][ 700 ] = array( 'type' => 'product', 'status' => 'publish', 'title' => 'Mine', 'post_date_gmt' => '2025-12-01 09:00:00' );
$META[ 700 ]             = array( '_sku' => 'BLS-1' );
$sku_answer              = ebay_item( str_replace( array( '256100000001', 'CHZ-PSA10' ), array( '256100000009', 'BLS-1' ), $CARD ) );
$linked                  = import( $sku_answer );
$out['by_sku']           = array( 'id' => $linked, 'origin' => $META[700][ TCGiant_Sync_Ebay_Live::ORIGIN_META ] ?? null, 'item' => $META[700]['_ebay_item_id'] ?? null, 'live_item' => $META[700][ TCGiant_Sync_Ebay_Live::META ]['item_id'] ?? null );

// A product the shop pushed, its listing ended, re-linked by its SKU to the
// listing the seller put up on eBay in its place: imported now, whatever its
// old listing was (3.28.8). And one re-linked to a listing that carries its
// own push mark: still the shop's.
$OPTIONS['siteurl'] = 'https://shop.example';
foreach ( array( 703 => '', 704 => TCGiant_Sync_API::push_mark( 704 ) ) as $pid => $mark ) {
	$GLOBALS['POSTS'][ $pid ] = array( 'type' => 'product', 'status' => 'publish', 'title' => 'Relinked', 'post_date_gmt' => '2025-12-01 09:00:00' );
	$META[ $pid ]             = array( '_sku' => 'REL-' . $pid, '_ebay_item_id' => '2561000005' . substr( (string) $pid, -2 ), '_ebay_listing_status' => 'Ended', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-06-01 10:00:00' );
	$relink                   = str_replace( array( '256100000001', 'CHZ-PSA10' ), array( '2561000006' . substr( (string) $pid, -2 ), 'REL-' . $pid ), $CARD ) . ( '' !== $mark ? '<ApplicationData>' . $mark . '</ApplicationData>' : '' );
	import( ebay_item( $relink ) );
	$out['relinked'][ $pid ]  = array( 'mark' => $mark, 'item' => $META[ $pid ]['_ebay_item_id'] ?? null, 'origin' => $META[ $pid ][ TCGiant_Sync_Ebay_Live::ORIGIN_META ] ?? null );
}
unset( $OPTIONS['siteurl'] );

// A product moved on to a relist (as the stock check moves one, without an
// import), still holding the old listing's values: the relist's read
// replaces them, never merges.
$GLOBALS['POSTS'][ 701 ] = array( 'type' => 'product', 'status' => 'publish', 'title' => 'Moved', 'post_date_gmt' => '2026-02-01 09:00:00' );
$META[ 701 ]             = array( '_sku' => 'MOV-1', '_ebay_item_id' => '256100000077', TCGiant_Sync_Ebay_Live::META => array( 'item_id' => '256100000066', 'condition_id' => '1000', 'location' => 'Old Town', 'policies' => array( 'shipping' => '1' ) ) );
import( ebay_item( str_replace( array( '256100000002', 'BRM-CAL-7' ), array( '256100000077', 'MOV-1' ), $PART ) ) );
$out['replaced'] = $META[701][ TCGiant_Sync_Ebay_Live::META ] ?? null;

// The light path's record: the same signature and fingerprint whatever eBay's
// own values in the answer, which the record does not hold.
$plain         = ebay_item( $brief );
$out['sig']    = array(
	TCGiant_Sync_Mapper::event_signature( ebay_item( $CARD ) ),
	TCGiant_Sync_Mapper::event_signature( $plain ),
);
$fp_before     = TCGiant_Sync_Mapper::settings_fingerprint();
$out['record'] = $META[ $card_id ][ TCGiant_Sync_Mapper::EVENT_RECORD_META ] ?? null;
$out['fp']     = array( $fp_before, $out['record']['fp'] ?? null );
$out['keys']   = TCGiant_Sync_Listing_Link::listing_identity_meta_keys();

// A row read in brief, for a product with no record yet: nothing recorded.
$GLOBALS['POSTS'][ 702 ] = array( 'type' => 'product', 'status' => 'publish', 'title' => 'Brief', 'post_date_gmt' => '2026-02-01 09:00:00' );
$META[ 702 ]             = array( '_sku' => 'BRF-1', '_ebay_item_id' => '256100000088' );
import( array( 'ItemID' => '256100000088', 'Quantity' => '1', 'SellingStatus' => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0' ), 'Title' => 'Brief', 'Site' => 'US' ) );
$out['brief_live'] = array_key_exists( TCGiant_Sync_Ebay_Live::META, $META[702] );

echo json_encode( $out );
