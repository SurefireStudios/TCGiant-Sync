<?php
/**
 * A played WordPress and WooCommerce for the import half of the eBay price
 * harnesses (3.29.0): tests/test-ebay-price-import.php, and the one import a
 * time support/ebay-price-import-step.php makes for test-ebay-price.php.
 *
 * Loaded for real: TCGiant_Sync_Mapper (map_ebay_to_woo(), save_as_product()
 * and its save_variations()), TCGiant_Sync_Ebay_Price, TCGiant_Sync_API (its
 * value helpers), TCGiant_Sync_Sites, TCGiant_Sync_Runtime,
 * TCGiant_Sync_Listing_Link and TCGiant_Sync_Exporter. Products and
 * variations are played from one array of post meta, their prices in
 * _regular_price and _sale_price as WooCommerce keeps them; WooCommerce's
 * tax is played from $TAX.
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__, 2 );

define( 'ABSPATH', $ROOT . '/' );
define( 'TCGIANT_SYNC_VERSION', '3.29.0' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

$META     = array();
$POSTS    = array();
$OPTIONS  = array();
$LOG      = array();
$SETTINGS = array( 'import_auctions' => '1' );
$NEXT_ID  = 900;
// WooCommerce's tax: off, prices without it, and the base address's rates by class.
$TAX      = array( 'enabled' => false, 'inclusive' => false, 'rates' => array() );

function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['META'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['META'][ (int) $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['META'][ (int) $id ][ $key ] ); return true; }
function get_post_status( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['status'] ?? false; }
function get_post_type( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['type'] ?? false; }
function get_post_field( $field, $id ) { return 'post_title' === $field ? ( $GLOBALS['POSTS'][ (int) $id ]['title'] ?? '' ) : ( $GLOBALS['POSTS'][ (int) $id ][ $field ] ?? '' ); }
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
function taxonomy_exists( $taxonomy ) { return false; }
function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) { return array(); }
function wc_tax_enabled() { return (bool) $GLOBALS['TAX']['enabled']; }
function wc_prices_include_tax() { return wc_tax_enabled() && (bool) $GLOBALS['TAX']['inclusive']; }
class WC_Tax {
	public static function get_base_tax_rates( $tax_class = '' ) { return $GLOBALS['TAX']['rates'][ (string) $tax_class ] ?? array(); }
	public static function calc_tax( $price, $rates, $price_includes_tax = false ) {
		$out = array();
		foreach ( $rates as $id => $rate ) {
			$out[ $id ] = $price * (float) $rate['rate'] / 100;
		}
		return $out;
	}
}
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
	public function get_sku( $context = 'view' ) { return (string) ( $this->meta['_sku'] ?? '' ); }
	public function set_regular_price( $price ) { $this->update_meta_data( '_regular_price', (string) $price ); }
	public function get_regular_price( $context = 'view' ) { return (string) ( $this->meta['_regular_price'] ?? '' ); }
	public function set_sale_price( $price ) { $this->update_meta_data( '_sale_price', (string) $price ); }
	public function get_sale_price( $context = 'view' ) { return (string) ( $this->meta['_sale_price'] ?? '' ); }
	public function is_taxable() { return 'none' !== ( $this->meta['_tax_status'] ?? 'taxable' ); }
	public function get_tax_class( $context = 'view' ) { return (string) ( $this->meta['_tax_class'] ?? '' ); }
	public function get_attributes() { return (array) ( $this->meta['_harness_attributes'] ?? array() ); }
	public function set_attributes( $attributes ) { $this->update_meta_data( '_harness_attributes', $attributes ); }
	public function get_children() {
		$out = array();
		foreach ( $GLOBALS['META'] as $id => $meta ) {
			if ( (int) ( $meta['_harness_parent'] ?? 0 ) === $this->id && 'product_variation' === get_post_type( $id ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}
	public function is_type( $type ) { return 'simple' === $type; }
	public function is_in_stock() { return true; }
	public function managing_stock() { return true; }
	public function get_stock_quantity() { return 1; }
	public function get_status() { return (string) get_post_status( $this->id ); }
	public function save() {
		if ( 0 === $this->id ) {
			$this->id = ++$GLOBALS['NEXT_ID'];
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
	if ( 'product_variation' === $type ) {
		return new WC_Product_Variation( $id );
	}
	return ! empty( $GLOBALS['POSTS'][ (int) $id ]['variable'] ) ? new WC_Product_Variable( $id ) : new WC_Product( $id );
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

/**
 * A fixed-price listing: its number, price, and anything else.
 *
 * @param string $item_id Item number.
 * @param string $price   CurrentPrice.
 * @param string $more    More of the Item's XML.
 * @return array
 */
function listing( $item_id, $price, $more = '' ) {
	return ebay_item( '<ItemID>' . $item_id . '</ItemID><Title>Item ' . $item_id . '</Title><SKU>SKU-' . $item_id . '</SKU><Currency>USD</Currency><Site>US</Site>'
		. '<Quantity>3</Quantity><ListingType>FixedPriceItem</ListingType><ListingDuration>GTC</ListingDuration>'
		. '<ListingDetails><StartTime>2026-01-05T10:00:00.000Z</StartTime><EndTime>2026-12-05T10:00:00.000Z</EndTime></ListingDetails>'
		. '<SellingStatus><ListingStatus>Active</ListingStatus><QuantitySold>0</QuantitySold><CurrentPrice currencyID="USD">' . $price . '</CurrentPrice></SellingStatus>'
		. $more );
}

/** The import saves the listing: mapped, then saved, as every import path does. */
function import( array $item ) {
	$mapper = new TCGiant_Sync_Mapper();
	$data   = $mapper->map_ebay_to_woo( $item );
	return $mapper->save_as_product( $data );
}

/** A product of the shop's, linked to a listing, holding this meta. */
function linked_product( $id, $item_id, array $meta = array(), $variable = false ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => 'product', 'status' => 'publish', 'title' => 'Item ' . $item_id, 'post_date_gmt' => '2026-01-01 09:00:00', 'variable' => $variable );
	$GLOBALS['META'][ $id ]  = array_merge( array( '_sku' => 'SKU-' . $item_id, '_ebay_item_id' => $item_id, '_ebay_listing_status' => 'Active' ), $meta );
	return $id;
}

/** A variation of a product, by SKU. */
function variation_of( $id, $parent, $sku, array $meta = array() ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => 'product_variation', 'status' => 'publish', 'title' => 'Variation' );
	$GLOBALS['META'][ $id ]  = array_merge( array( '_sku' => $sku, '_harness_parent' => $parent ), $meta );
	return $id;
}

function meta( $id, $key ) { return $GLOBALS['META'][ (int) $id ][ $key ] ?? null; }

/** The product's website prices: regular, sale. */
function prices( $id ) { return array( meta( $id, '_regular_price' ), meta( $id, '_sale_price' ) ); }

/** The product's sync log's newest entry: what the import decided. */
function decided( $id ) {
	$log = meta( $id, '_tcgiant_sync_log' );
	if ( ! is_array( $log ) || ! $log ) {
		return array();
	}
	return (array) ( $log[0]['decisions'] ?? array() );
}
