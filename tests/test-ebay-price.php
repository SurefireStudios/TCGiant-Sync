<?php
/**
 * Harness: a separate eBay price (3.29.0).
 *
 * Until 3.29.0 every push sent eBay the product's WooCommerce regular price.
 * A shop wanting another price on eBay put it in Regular price and its
 * website price in Sale price, and its website then showed every product as
 * on sale. Now one helper, TCGiant_Sync_Ebay_Price::for_product(), gives
 * every price a push sends: the product's or variation's own eBay price
 * (_ebay_export_price) when it has one; otherwise its regular price, less
 * the postage an import added to it ("Add eBay Shipping Cost to Product
 * Price"), with tax where the shop wants it, then the shop's rule - a
 * percentage, an amount, or both, rounded up to .99 or a whole number.
 *
 * Proved here, through the real TCGiant_Sync_Exporter with eBay played at
 * TCGiant_Sync_API::trading_api_request():
 *
 *  - with no rule and no product price, every request body of every price
 *    path is byte for byte what it was (support/ebay-price-bodies.php,
 *    against support/ebay-price-before.json, captured from the tree 3.29.0
 *    was built on);
 *  - under a rule, or with a product price, each body differs from that by
 *    its prices alone: Add, Revise and Verify, an auction's opening bid,
 *    each variation's, Best Offer's thresholds that are fractions of the
 *    price - and never an imported live auction's revise (3.28.8), nor a
 *    relist, which keeps eBay's price;
 *  - the price is noted as eBay's once eBay takes it, for the import;
 *  - postage an import added is taken off, so a push and an import, round
 *    after round, never grow the price (the import half for real, in
 *    support/ebay-price-import-step.php);
 *  - tax for eBay UK, Germany and Australia, never for eBay US on Auto;
 *  - the eBay tab, a variation's field, the Settings screen and the
 *    Listings screen show and save it;
 *  - every place a price is put in a request goes through the one helper.
 *
 * The import half is test-ebay-price-import.php's.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-variations.php';

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
	public static function table_exists() { return false; }
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}
function get_post_status( $id ) { return 'publish'; }
function get_attached_file( $id ) { return ''; }

// WooCommerce's tax, played: off until a check turns it on.
$GLOBALS['TAX'] = array( 'enabled' => false, 'inclusive' => false, 'rates' => array() );
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

// What the admin fields draw with, played.
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function selected( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) { echo $out; } return $out; }
function checked( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; }
function wc_price( $price ) { return '<span class="amount">' . number_format( (float) $price, 2, '.', '' ) . '</span>'; }
function woocommerce_wp_text_input( $args ) {
	echo '<input type="text" id="' . esc_attr( $args['id'] ) . '" name="' . esc_attr( $args['name'] ?? $args['id'] ) . '" value="' . esc_attr( $args['value'] ?? '' ) . '" placeholder="' . esc_attr( $args['placeholder'] ?? '' ) . '" data-label="' . esc_attr( $args['label'] ?? '' ) . '">';
}

/** A product that says whether it is taxed, and in which class. */
class Price_Product extends WC_Product {
	public function is_taxable() { return $this->data['taxable'] ?? true; }
	public function get_tax_class() { return (string) ( $this->data['tax_class'] ?? '' ); }
}
/** A variable product with its variations. */
class Price_Variable extends WC_Product_Variable {
	public $children = array();
	public function get_children() { return $this->children; }
	public function is_taxable() { return true; }
	public function get_tax_class() { return ''; }
}
/** A variation, by its size. */
class Price_Variation extends WC_Product_Variation {
	public function get_variation_attributes() { return array( 'attribute_size' => $this->data['size'] ); }
	public function is_taxable() { return true; }
	public function get_tax_class() { return ''; }
}

$API = $GLOBALS['API'];
$ROOT = dirname( __DIR__ );

/** A part shop's defaults, and these. */
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
part_shop();

/** A product of the shop's, with a photo on the website, and these meta. */
function product( $id, array $data = array(), array $meta = array(), $class = 'Price_Product' ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$product = new $class( $id, array_merge( array( 'name' => 'Item ' . $id, 'description' => 'Described ' . $id . '.', 'regular_price' => '20.00', 'stock' => 2, 'sku' => 'SKU-' . $id, 'image_id' => 9000 + $id ), $data ) );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/item-' . $id . '.jpg';
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
const PUSHED = array( '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-10-01 10:00:00' );

/** A push (do_push()): every call it made. */
function push( WC_Product $product ) {
	$GLOBALS['API']->calls = array();
	private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	return $GLOBALS['API']->calls;
}
/** The StartPrices of a request body. */
function start_prices( $body ) {
	preg_match_all( '#<StartPrice>([^<]*)</StartPrice>#', (string) $body, $m );
	return $m[1];
}
/** The StartPrice of the last call. */
function sent_price( array $calls ) {
	$last = end( $calls );
	return start_prices( $last[1] ?? '' )[0] ?? null;
}
/** A body with its prices taken out: what must not change. */
function without_prices( $body ) {
	return preg_replace( '#<(StartPrice|BestOfferAutoAcceptPrice)>[^<]*</\1>\n?#', '', (string) $body );
}
/** Verify's item for a product. */
function verify_price( WC_Product $product ) {
	return start_prices( item_xml( $product, exporter()->get_export_settings( $product->get_id() ) ) );
}
/** A support process's JSON answer. */
function run_support( $script, array $args = array() ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/' . $script );
	foreach ( $args as $arg ) {
		$cmd .= ' ' . escapeshellarg( $arg );
	}
	$out = array();
	exec( $cmd . ' 2>&1', $out );
	return array( json_decode( (string) end( $out ), true ), implode( "\n", $out ) );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE RULE, ITS ROUNDING, AND ITS REVERSE\n" . str_repeat( '=', 124 ) . "\n";

$none = TCGiant_Sync_Ebay_Price::rule( array() );
check( 'no rule set: none', $none, array( 'active' => false, 'percent' => 0.0, 'fixed' => 0.0, 'rounding' => 'none' ) );
check( '  "none" with a percentage, an amount and a rounding left in their boxes: none', TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'none', 'ebay_price_percent' => '10', 'ebay_price_fixed' => '1', 'ebay_price_rounding' => '99' ) ), $none );
check( '  a rule that is not one of the four: none', TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'double', 'ebay_price_percent' => '10' ) ), $none );
check( 'a percentage: its figure, not the amount\'s', TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10', 'ebay_price_fixed' => '1' ) ), array( 'active' => true, 'percent' => 10.0, 'fixed' => 0.0, 'rounding' => 'none' ) );
check( '  an amount: its figure, not the percentage\'s', TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'fixed', 'ebay_price_percent' => '10', 'ebay_price_fixed' => '0.50' ) ), array( 'active' => true, 'percent' => 0.0, 'fixed' => 0.5, 'rounding' => 'none' ) );
check( '  both, a decimal comma taken as a point, a currency sign gone, and a rounding', TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'both', 'ebay_price_percent' => '12,5', 'ebay_price_fixed' => '$1', 'ebay_price_rounding' => 'whole' ) ), array( 'active' => true, 'percent' => 12.5, 'fixed' => 1.0, 'rounding' => 'whole' ) );
check( '  a minus percentage or amount: no rule at all (3.29.0 review: a rule is a markup)', array( TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '-5' ) ), TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'both', 'ebay_price_percent' => '5', 'ebay_price_fixed' => '-2' ) ) ), array( $none, $none ) );
check( '  a rounding that is not one of the three: none', TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '5', 'ebay_price_rounding' => '95' ) )['rounding'], 'none' );
check( '  a figure it cannot read, or none, for the rule that needs it: no rule at all, never a rule of 0', array( TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => 'ten' ) ), TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'fixed', 'ebay_price_fixed' => '' ) ), TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'fixed', 'ebay_price_percent' => 'x', 'ebay_price_fixed' => '2' ) )['active'] ), array( $none, $none, true ) );
check( '  a percentage of 0 is one: the rounding alone', TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '0', 'ebay_price_rounding' => '99' ) ), array( 'active' => true, 'percent' => 0.0, 'fixed' => 0.0, 'rounding' => '99' ) );
$figures = array( '10%' => array( true, 10.0 ), ' 12,5 % ' => array( true, 12.5 ), '$1' => array( false, 1.0 ), '1,50' => array( false, 1.5 ), '£2.5' => array( false, 2.5 ), '2 EUR' => array( false, 2.0 ), ' 1 000,50 ' => array( false, 1000.5 ), '1,000.50' => array( false, 1000.5 ), '1.000,50' => array( false, 1000.5 ), '1,000' => array( false, 1000.0 ), '+0.75' => array( false, 0.75 ), '-3' => array( false, -3.0 ), 'ten' => array( false, null ), '1-2' => array( false, null ), '1%' => array( false, null ), '%' => array( true, null ), '' => array( false, null ), '1,2,3' => array( false, 123.0 ), '1.000.000' => array( false, 1000000.0 ), '1.000' => array( false, 1.0 ) );
$figures_read = array();
foreach ( $figures as $typed => $f ) {
	$figures_read[ $typed ] = TCGiant_Sync_Ebay_Price::parse_figure( (string) $typed, $f[0] );
}
check( 'figures read as typed: 10%, 12,5 %, $1, 1,50, \u00a32.5, 2 EUR, 1 000,50, 1,000.50, 1.000,50, 1,000, 1.000.000, 1.000, +0.75, -3; ten, 1-2 and 1% (an amount) not read', $figures_read, array_map( function ( $f ) { return $f[1]; }, $figures ) );
check( '  a rule of a kind that is not one of the four, with both figures set: none', TCGiant_Sync_Ebay_Price::rule( array( 'ebay_price_rule' => 'double', 'ebay_price_percent' => '10', 'ebay_price_fixed' => '1' ) ), $none );
check( 'tax: never chosen, No; Auto and Yes as saved; anything else No', array( TCGiant_Sync_Ebay_Price::tax_mode( array() ), TCGiant_Sync_Ebay_Price::tax_mode( array( 'ebay_price_tax' => 'auto' ) ), TCGiant_Sync_Ebay_Price::tax_mode( array( 'ebay_price_tax' => 'yes' ) ), TCGiant_Sync_Ebay_Price::tax_mode( array( 'ebay_price_tax' => 'always' ) ) ), array( 'no', 'auto', 'yes', 'no' ) );

$r = function ( $percent, $fixed = 0, $rounding = 'none' ) {
	return array( 'active' => true, 'percent' => (float) $percent, 'fixed' => (float) $fixed, 'rounding' => $rounding );
};
check( 'plus 10%: 20.00 is 22.00; 19.99 plus 15% is 22.99 (22.9885)', array( TCGiant_Sync_Ebay_Price::forward( 20, $r( 10 ) ), TCGiant_Sync_Ebay_Price::forward( 19.99, $r( 15 ) ) ), array( '22.00', '22.99' ) );
check( '  11.30 plus 15% is 12.995, which goes to 13.00 - half a cent up, though the float is a hair under', TCGiant_Sync_Ebay_Price::forward( 11.30, $r( 15 ) ), '13.00' );
check( 'plus 0.50; plus 10% and 0.50 (the percentage first)', array( TCGiant_Sync_Ebay_Price::forward( 20, $r( 0, 0.5 ) ), TCGiant_Sync_Ebay_Price::forward( 20, $r( 10, 0.5 ) ) ), array( '20.50', '22.50' ) );
check( 'less 5%; less 2.00', array( TCGiant_Sync_Ebay_Price::forward( 20, $r( -5 ) ), TCGiant_Sync_Ebay_Price::forward( 20, $r( 0, -2 ) ) ), array( '19.00', '18.00' ) );
check( '  a markdown that would leave nothing is not applied', array( TCGiant_Sync_Ebay_Price::forward( 20, $r( 0, -25 ) ), TCGiant_Sync_Ebay_Price::forward( 20, $r( 0, -20 ) ) ), array( '20.00', '20.00' ) );
check( 'rounded up to .99: 22.00 is 22.99, 22.99 stays, 22.01 is 22.99, 0.50 is 0.99', array( TCGiant_Sync_Ebay_Price::forward( 20, $r( 10, 0, '99' ) ), TCGiant_Sync_Ebay_Price::forward( 22.99, $r( 0, 0, '99' ) ), TCGiant_Sync_Ebay_Price::forward( 22.01, $r( 0, 0, '99' ) ), TCGiant_Sync_Ebay_Price::forward( 0.5, $r( 0, 0, '99' ) ) ), array( '22.99', '22.99', '22.99', '0.99' ) );
check( 'rounded up to a whole number: 22.01 is 23.00, 22.00 stays', array( TCGiant_Sync_Ebay_Price::forward( 22.01, $r( 0, 0, 'whole' ) ), TCGiant_Sync_Ebay_Price::forward( 22, $r( 0, 0, 'whole' ) ) ), array( '23.00', '22.00' ) );
check( 'never lower than the rule\'s own price, whatever the rounding (1,000 prices)', ( function () use ( $r ) {
	for ( $c = 1; $c <= 1000; $c++ ) {
		foreach ( array( 'none', '99', 'whole' ) as $rounding ) {
			if ( (float) TCGiant_Sync_Ebay_Price::forward( $c * 0.37, $r( 12.5, 0.3, $rounding ) ) + 0.00001 < round( $c * 0.37 * 1.125 + 0.3, 2 ) ) {
				return $c;
			}
		}
	}
	return 'none lower';
} )(), 'none lower' );
check( 'no rule, no tax: the price to the cent', TCGiant_Sync_Ebay_Price::forward( 9.999, $none ), '10.00' );
check( 'tax then the rule: 20.00 with 20% is 24.00, plus 10% 26.40, to .99 26.99', TCGiant_Sync_Ebay_Price::forward( 20, $r( 10, 0, '99' ), 0.2 ), '26.99' );

check( 'the reverse of plus 10%: 22.00 is 20.00, exactly', TCGiant_Sync_Ebay_Price::reverse( '22.00', $r( 10 ) ), array( '20.00', 'exact' ) );
check( '  of plus 2.00: 22.00 is 20.00', TCGiant_Sync_Ebay_Price::reverse( '22', $r( 0, 2 ) ), array( '20.00', 'exact' ) );
check( '  of 20% tax: 24.00 is 20.00', TCGiant_Sync_Ebay_Price::reverse( '24', $none, 0.2 ), array( '20.00', 'exact' ) );
check( '  of 20% tax, 10.05, which no price in whole cents gives (its steps are 1.2 cents): 8.38, within half a cent of 8.375, and "near" - it goes back as 10.06', array( TCGiant_Sync_Ebay_Price::reverse( '10.05', $none, 0.2 ), TCGiant_Sync_Ebay_Price::forward( 8.38, $none, 0.2 ) ), array( array( '8.38', 'near' ), '10.06' ) );
check( '  of plus 1.00: 0.99, 1.00, 1.01 and 1.98 have no website price of 0.99 or more, "none" (3.29.0 re-verify: a reverse below 0.99 is no answer); 1.99 is 0.99', array( TCGiant_Sync_Ebay_Price::reverse( '0.99', $r( 0, 1 ) ), TCGiant_Sync_Ebay_Price::reverse( '1.00', $r( 0, 1 ) ), TCGiant_Sync_Ebay_Price::reverse( '1.01', $r( 0, 1 ) ), TCGiant_Sync_Ebay_Price::reverse( '1.98', $r( 0, 1 ) ), TCGiant_Sync_Ebay_Price::reverse( '1.99', $r( 0, 1 ) ) ), array( array( '', 'none' ), array( '', 'none' ), array( '', 'none' ), array( '', 'none' ), array( '0.99', 'exact' ) ) );
check( '  the floor is a fixed amount\'s only (3.29.0 spot check, S1): 0.99 and 1.10 under 20% tax alone are 0.83 ("near", it goes back as 1.00) and 0.92; under plus 10% alone 0.90 and 1.00; under plus 1.00 none and none', array(
	TCGiant_Sync_Ebay_Price::reverse( '0.99', $none, 0.2 ), TCGiant_Sync_Ebay_Price::reverse( '1.10', $none, 0.2 ),
	TCGiant_Sync_Ebay_Price::reverse( '0.99', $r( 10 ) ), TCGiant_Sync_Ebay_Price::reverse( '1.10', $r( 10 ) ),
	TCGiant_Sync_Ebay_Price::reverse( '0.99', $r( 0, 1 ) ), TCGiant_Sync_Ebay_Price::reverse( '1.10', $r( 0, 1 ) ),
), array( array( '0.83', 'near' ), array( '0.92', 'exact' ), array( '0.90', 'exact' ), array( '1.00', 'exact' ), array( '', 'none' ), array( '', 'none' ) ) );
check( '  plus 10% and 0.50: 1.10 is none, 1.59 is 0.99 - any fixed amount above 0 has the floor', array( TCGiant_Sync_Ebay_Price::reverse( '1.10', $r( 10, 0.5 ) ), TCGiant_Sync_Ebay_Price::reverse( '1.59', $r( 10, 0.5 ) ) ), array( array( '', 'none' ), array( '0.99', 'exact' ) ) );
check( '  the floor itself: 0.99 under a fixed amount, one cent otherwise', array( TCGiant_Sync_Ebay_Price::reverse_floor( $r( 0, 1 ) ), TCGiant_Sync_Ebay_Price::reverse_floor( $r( 10, 0.01 ) ), TCGiant_Sync_Ebay_Price::reverse_floor( $r( 10 ) ), TCGiant_Sync_Ebay_Price::reverse_floor( $none ) ), array( 99, 99, 1, 1 ) );
check( '  of plus 1.50 to a whole number: 3.00 is 1.50, the exact reverse - 0.99 or more', TCGiant_Sync_Ebay_Price::reverse( '3.00', $r( 0, 1.5, 'whole' ) ), array( '1.50', 'exact' ) );
check( '  of plus 1.50 to a whole number: 2.00 is none - the cents below 0.99 that give it are no answer', TCGiant_Sync_Ebay_Price::reverse( '2.00', $r( 0, 1.5, 'whole' ) ), array( '', 'none' ) );
check( '  of plus 1.00 to .99: 1.99 is 0.99; 0.99 none', array( TCGiant_Sync_Ebay_Price::reverse( '1.99', $r( 0, 1, '99' ) ), TCGiant_Sync_Ebay_Price::reverse( '0.99', $r( 0, 1, '99' ) ) ), array( array( '0.99', 'exact' ), array( '', 'none' ) ) );
check( '  of plus 10% to .99, 27.99: 25.44, the nearest to 25.445 that gives 27.99', TCGiant_Sync_Ebay_Price::reverse( '27.99', $r( 10, 0, '99' ) ), array( '25.44', 'exact' ) );
check( '  27.50 under .99: no website price gives it - the exact reverse, 25.00, "off" (it goes back as 27.99)', TCGiant_Sync_Ebay_Price::reverse( '27.50', $r( 10, 0, '99' ) ), array( '25.00', 'off' ) );
check( '  4.99 under plus 10% to a whole number, with 20% tax: "off", the rounding\'s - never "near", though the exact reverse comes back a cent away as 5.00 (3.29.0 re-verify)', array( TCGiant_Sync_Ebay_Price::reverse( '4.99', $r( 10, 0, 'whole' ), 0.2 ), TCGiant_Sync_Ebay_Price::forward( 3.78, $r( 10, 0, 'whole' ), 0.2 ) ), array( array( '3.78', 'off' ), '5.00' ) );
check( '  every price from 1.00 to 50.00 under plus 10% to a whole number with 20% tax: "exact" or "off", never "near" (and with no fixed amount, never "none")', ( function () use ( $r ) {
	$seen = array();
	for ( $c = 100; $c <= 5000; $c++ ) {
		list( , $how ) = TCGiant_Sync_Ebay_Price::reverse( $c / 100, $r( 10, 0, 'whole' ), 0.2 );
		$seen[ $how ] = true;
	}
	ksort( $seen );
	return array_keys( $seen );
} )(), array( 'exact', 'off' ) );
check( 'every reverse, sent forward again, gives the eBay price it came from (2,000 prices, three roundings)', ( function () use ( $r ) {
	foreach ( array( 'none', '99', 'whole' ) as $rounding ) {
		$rule = $r( 7.5, 0.25, $rounding );
		for ( $c = 100; $c <= 2100; $c++ ) {
			$ebay = TCGiant_Sync_Ebay_Price::forward( $c / 100, $rule );
			list( $back, $how ) = TCGiant_Sync_Ebay_Price::reverse( $ebay, $rule );
			if ( 'exact' !== $how || TCGiant_Sync_Ebay_Price::forward( (float) $back, $rule ) !== $ebay ) {
				return array( $rounding, $c, $ebay, $back );
			}
		}
	}
	return 'all';
} )(), 'all' );
check( 'cents: half a cent up; money: two places and a point', array( TCGiant_Sync_Ebay_Price::cents( '12.345' ), TCGiant_Sync_Ebay_Price::cents( 12.344 ), TCGiant_Sync_Ebay_Price::money( 1205 ) ), array( 1235, 1234, '12.05' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PRODUCT'S OWN EBAY PRICE, AND ITS FIELD\n" . str_repeat( '=', 124 ) . "\n";

product( 300, array(), array( '_ebay_export_price' => '12.5' ) );
check( 'its own eBay price, to two places', TCGiant_Sync_Ebay_Price::own_price( 300 ), '12.50' );
update_post_meta( 300, '_ebay_export_price', '12,50 ' );
check( '  "12,50 " as WooCommerce\'s CSV import writes it raw: 12.50', TCGiant_Sync_Ebay_Price::own_price( 300 ), '12.50' );
foreach ( array( '0', '-3', 'abc', '' ) as $none_price ) {
	update_post_meta( 300, '_ebay_export_price', $none_price );
	check( '  "' . $none_price . '" is none: the rule\'s', TCGiant_Sync_Ebay_Price::own_price( 300 ), '' );
}
TCGiant_Sync_Ebay_Price::set_own( 300, ' 7 ' );
check( 'typed " 7 ": kept as 7.00', get_post_meta( 300, '_ebay_export_price', true ), '7.00' );
TCGiant_Sync_Ebay_Price::set_own( 300, '12.345' );
check( 'typed 12.345: 12.35', get_post_meta( 300, '_ebay_export_price', true ), '12.35' );
foreach ( array( '', '0', 'abc', '-4' ) as $typed ) {
	TCGiant_Sync_Ebay_Price::set_own( 300, '12' );
	TCGiant_Sync_Ebay_Price::set_own( 300, $typed );
	check( '  typed "' . $typed . '": cleared, the rule\'s again', get_post_meta( 300, '_ebay_export_price', true ), '' );
}
TCGiant_Sync_Ebay_Price::save_posted( 300, array( 'override_ebay_price' => '15' ), 'override_ebay_price' );
check( 'posted without the field on screen: nothing saved', get_post_meta( 300, '_ebay_export_price', true ), '' );
TCGiant_Sync_Ebay_Price::save_posted( 300, array( 'override_ebay_price_shown' => '1', 'override_ebay_price' => '15' ), 'override_ebay_price' );
check( '  with it (the Push and Verify buttons): saved', get_post_meta( 300, '_ebay_export_price', true ), '15.00' );
TCGiant_Sync_Ebay_Price::save_posted( 300, array( 'override_ebay_price_shown' => '1', 'override_ebay_price' => array( 'x' ) ), 'override_ebay_price' );
check( '  not a string: nothing saved', get_post_meta( 300, '_ebay_export_price', true ), '15.00' );
$_POST = array( '_ebay_export_price_shown' => '1', '_ebay_export_price' => '18.40' );
TCGiant_Sync_Ebay_Price::save_product_field( 300 );
check( 'the product screen\'s save: saved', get_post_meta( 300, '_ebay_export_price', true ), '18.40' );
$GLOBALS['W']['caps']['edit_post'] = false;
$_POST['_ebay_export_price'] = '1';
TCGiant_Sync_Ebay_Price::save_product_field( 300 );
check( '  by someone who may not edit the product: nothing saved', get_post_meta( 300, '_ebay_export_price', true ), '18.40' );
unset( $GLOBALS['W']['caps']['edit_post'] );
$_POST = array( '_ebay_export_price_shown' => array( 3 => '1' ), '_ebay_export_price' => array( 3 => '9.95' ) );
TCGiant_Sync_Ebay_Price::save_variation_field( 3011, 3 );
check( 'a variation\'s field, by its place in the form: saved', get_post_meta( 3011, '_ebay_export_price', true ), '9.95' );
TCGiant_Sync_Ebay_Price::save_variation_field( 3012, 4 );
check( '  another place not posted: nothing', get_post_meta( 3012, '_ebay_export_price', true ), '' );
$_POST = array( '_ebay_export_price' => array( 5 => '9.95' ) );
TCGiant_Sync_Ebay_Price::save_variation_field( 3013, 5 );
check( '  a value posted without its field on screen: nothing', get_post_meta( 3013, '_ebay_export_price', true ), '' );
$_POST = array();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNO RULE, NO PRODUCT PRICE: EVERY BODY BYTE FOR BYTE AS BEFORE\n" . str_repeat( '=', 124 ) . "\n";

$before_file = __DIR__ . '/support/ebay-price-before.json';
$before      = json_decode( (string) file_get_contents( $before_file ), true );
list( $now, $raw ) = run_support( 'ebay-price-bodies.php' );
$paths = array_keys( $before['plain'] ?? array() );
check( 'every price path ran, before and now: 16 of them, three shops', array( count( $paths ), array_keys( (array) $now ) ), array( 16, array( 'plain', 'inert', 'inert_bake_off' ) ) );
foreach ( array( 'plain', 'inert', 'inert_bake_off' ) as $shop ) {
	foreach ( $paths as $path ) {
		check( '  ' . $shop . ' / ' . $path . ': the same calls, byte for byte', $now[ $shop ][ $path ] ?? null, $before[ $shop ][ $path ] );
	}
}
check( 'and the whole of it, as printed', json_encode( $now ), (string) file_get_contents( $before_file ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nUNDER A RULE, OR WITH A PRODUCT PRICE: ONLY THE PRICES CHANGE\n" . str_repeat( '=', 124 ) . "\n";

$want = array(
	'rule' => array(
		'simple_add' => array( array( '27.99' ) ), 'simple_revise' => array( array( '21.99' ) ), 'simple_verify' => array( array( '7.99' ) ),
		'no_price' => array( array( '0.00' ) ), 'long_price' => array( array( '11.99' ) ),
		'auction_add' => array( array( '40.99' ) ), 'auction_revise' => array( array() ), 'imported_auction_revise' => array( array() ),
		'variable_add' => array( array( '11.99', '13.99' ) ), 'variable_revise' => array( array( '11.99', '13.99' ) ),
		'best_offer' => array( array( '44.99' ) ), 'ended_new' => array( array( '36.99' ), array(), array( '36.99' ) ),
		'relist_fixed' => array( array(), array() ), 'relist_variations' => array( array(), array( '11.00', '13.75' ) ),
		'relist_auction' => array( array(), array() ), 'update_relists_auction' => array( array(), array(), array() ),
	),
	'own'  => array(
		'simple_add' => array( array( '99.00' ) ), 'simple_revise' => array( array( '99.00' ) ), 'simple_verify' => array( array( '99.00' ) ),
		'no_price' => array( array( '99.00' ) ), 'long_price' => array( array( '99.00' ) ),
		'auction_add' => array( array( '99.00' ) ), 'auction_revise' => array( array() ), 'imported_auction_revise' => array( array() ),
		'variable_add' => array( array( '99.00', '99.00' ) ), 'variable_revise' => array( array( '99.00', '99.00' ) ),
		'best_offer' => array( array( '99.00' ) ), 'ended_new' => array( array( '99.00' ), array(), array( '99.00' ) ),
		'relist_fixed' => array( array(), array() ), 'relist_variations' => array( array(), array( '11.00', '13.75' ) ),
		'relist_auction' => array( array(), array() ), 'update_relists_auction' => array( array(), array(), array() ),
	),
);
$labels = array(
	'rule' => 'plus 10%, to .99',
	'own'  => 'its own eBay price, 99.00',
);
foreach ( array( 'rule', 'own' ) as $mode ) {
	list( $bodies ) = run_support( 'ebay-price-bodies.php', array( '', $mode ) );
	foreach ( $paths as $path ) {
		$calls  = (array) ( $bodies['plain'][ $path ] ?? array() );
		$prices = array_map( function ( $call ) { return start_prices( $call[1] ); }, $calls );
		$rest   = array_map( function ( $call ) { return array( $call[0], without_prices( $call[1] ) ); }, $calls );
		$was    = array_map( function ( $call ) { return array( $call[0], without_prices( $call[1] ) ); }, $before['plain'][ $path ] );
		check( '  ' . $labels[ $mode ] . ' / ' . $path . ': its prices ' . json_encode( $want[ $mode ][ $path ] ) . ', the rest as before', array( $prices, $rest === $was ), array( $want[ $mode ][ $path ], true ) );
	}
	$offer = (string) ( $bodies['plain']['best_offer'][0][1] ?? '' );
	// 3.28.9's element names: the auto-accept price in BestOfferAutoAcceptPrice,
	// the auto-decline floor in MinimumBestOfferPrice.
	preg_match( '#<BestOfferAutoAcceptPrice>([^<]*)<#', $offer, $accept );
	preg_match( '#<MinimumBestOfferPrice>([^<]*)<#', $offer, $decline );
	check( '  ' . $labels[ $mode ] . ': Best Offer\'s 0.9 is of the eBay price; its amount, 20, stays', array( $accept[1] ?? null, $decline[1] ?? null ), 'rule' === $mode ? array( '40.49', '20.00' ) : array( '89.10', '20.00' ) );
}

// One product by its own price, the next by the rule, in one variable listing.
part_shop( array( 'ebay_price_rule' => 'both', 'ebay_price_percent' => '10', 'ebay_price_fixed' => '0.25' ) );
$variable = product( 320, array( 'type' => 'variable', 'sku' => 'VAR-320' ), array(), 'Price_Variable' );
$variable->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
$variable->children             = array( 3201, 3202 );
product( 3201, array( 'sku' => 'VAR-320-S', 'size' => 'Small', 'regular_price' => '10', 'stock' => 3 ), array( '_ebay_export_price' => '14.00' ), 'Price_Variation' );
product( 3202, array( 'sku' => 'VAR-320-L', 'size' => 'Large', 'regular_price' => '12.5', 'stock' => 1 ), array(), 'Price_Variation' );
$calls = push( $variable );
check( 'a variable listing: one variation by its own eBay price, 14.00; the other by the rule, 12.50 plus 10% and 0.25', start_prices( $calls[0][1] ?? '' ), array( '14.00', '14.00' ) );
update_post_meta( 3202, '_ebay_export_price', '15.00' );
check( '  the second given its own: 15.00', start_prices( push( $variable )[0][1] ?? '' ), array( '14.00', '15.00' ) );
part_shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PRICE EBAY TOOK IS NOTED, FOR THE IMPORT\n" . str_repeat( '=', 124 ) . "\n";

part_shop( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10' ) );
product( 330, array(), PUSHED + array( '_ebay_item_id' => '110000000330' ) );
TCGiant_Sync_DB::$rows = array();
exporter()->process_single_push( 330 );
$row = end( TCGiant_Sync_DB::$rows );
check( 'a push eBay took: 22.00 noted as eBay\'s price, and the Listings row\'s price is the one sent', array( get_post_meta( 330, '_ebay_price_seen', true ), $row['ebay_price'] ?? null, get_post_meta( 330, '_ebay_export_status', true ) ), array( '22.00', 22.0, 'pushed' ) );
check( '  marked as one the rule made', get_post_meta( 330, '_ebay_price_marked', true ), '1' );
part_shop();
exporter()->process_single_push( 330 );
check( '  pushed again with no rule: 20.00 noted, and no longer marked', array( get_post_meta( 330, '_ebay_price_seen', true ), get_post_meta( 330, '_ebay_price_marked', true ) ), array( '20.00', '' ) );
$TAX = array( 'enabled' => true, 'inclusive' => false, 'rates' => array( '' => array( 1 => array( 'rate' => '20.0000' ) ) ) );
part_shop( array( 'marketplace' => 'EBAY_GB', 'ebay_price_tax' => 'auto' ) );
exporter()->process_single_push( 330 );
check( '  with tax alone, 20% on eBay UK: 24.00 noted, and marked as one tax made', array( get_post_meta( 330, '_ebay_price_seen', true ), get_post_meta( 330, '_ebay_price_marked', true ) ), array( '24.00', '1' ) );
$TAX = array( 'enabled' => false, 'inclusive' => false, 'rates' => array() );
part_shop();
part_shop( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10' ) );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'Something eBay did not like.' );
update_post_meta( 330, '_regular_price_was', '1' );
product( 331, array(), PUSHED + array( '_ebay_item_id' => '110000000331', '_ebay_price_seen' => '19.00' ) );
exporter()->process_single_push( 331 );
check( '  one eBay refused: nothing noted', get_post_meta( 331, '_ebay_price_seen', true ), '19.00' );
unset( $API->answers['ReviseFixedPriceItem'] );
$variable = product( 332, array( 'type' => 'variable', 'sku' => 'VAR-332' ), PUSHED + array( '_ebay_item_id' => '110000000332' ), 'Price_Variable' );
$variable->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
$variable->children             = array( 3321, 3322 );
product( 3321, array( 'sku' => 'VAR-332-S', 'size' => 'Small', 'regular_price' => '10', 'stock' => 3 ), array(), 'Price_Variation' );
product( 3322, array( 'sku' => 'VAR-332-L', 'size' => 'Large', 'regular_price' => '12.5', 'stock' => 1 ), array( '_ebay_export_price' => '15.00' ), 'Price_Variation' );
exporter()->process_single_push( 332 );
check( '  a variable product: each variation\'s, on the variation', array( get_post_meta( 3321, '_ebay_price_seen', true ), get_post_meta( 3322, '_ebay_price_seen', true ), get_post_meta( 332, '_ebay_price_seen', true ) ), array( '11.00', '15.00', '' ) );
$imported = array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_duration' => 'Days_7', '_ebay_listing_status' => 'Active', '_ebay_origin' => 'import', '_ebay_item_id' => '110000000333', '_ebay_live' => array( 'item_id' => '110000000333', 'category_id' => '33564', 'condition_id' => '3000', 'policies' => array( 'shipping' => '1', 'return' => '2', 'payment' => '3' ) ), '_ebay_price_seen' => '37.00' );
product( 333, array( 'regular_price' => '61.00', 'stock' => 1 ), $imported + array( '_ebay_export_price' => '45.00' ) );
// Verify, a new listing's check, sends it its price first: none of that is
// the revise's.
check( '  Verify of it, as a new listing, sends its own 45.00', verify_price( wc_get_product( 333 ) ), array( '45.00' ) );
TCGiant_Sync_DB::$rows = array();
exporter()->process_single_push( 333 );
$call = end( $API->calls );
check( 'an imported live auction with an eBay price of its own and a rule: its revise sends no price (3.28.8), and nothing is noted', array( $call[0] ?? null, start_prices( $call[1] ?? '' ), get_post_meta( 333, '_ebay_price_seen', true ) ), array( 'ReviseItem', array(), '37.00' ) );
check( '  its Listings row has its regular price, as before 3.29.0', ( end( TCGiant_Sync_DB::$rows )['ebay_price'] ?? null ), 61.0 );
part_shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nPOSTAGE ADDED ON IMPORT IS TAKEN OFF AGAIN: A PUSH NEVER GROWS THE PRICE\n" . str_repeat( '=', 124 ) . "\n";

part_shop( array( 'bake_shipping_into_price' => '1' ) );
check( 'postage added on import, 4.50 of it in 24.50: eBay is sent 20.00', verify_price( product( 340, array( 'regular_price' => '24.50' ), array( '_ebay_baked_shipping' => '4.50' ) ) ), array( '20.00' ) );
check( '  none recorded: the price whole, as before', verify_price( product( 341, array( 'regular_price' => '24.50' ) ) ), array( '24.50' ) );
check( '  "postage" more than the price is not postage taken off', verify_price( product( 342, array( 'regular_price' => '4.00' ), array( '_ebay_baked_shipping' => '4.50' ) ) ), array( '4.00' ) );
part_shop();
check( 'the shop no longer adds postage, 4.50 still recorded in the website price: still taken off (3.29.0 review), 20.00', verify_price( product( 343, array( 'regular_price' => '24.50' ), array( '_ebay_baked_shipping' => '4.50' ) ) ), array( '20.00' ) );

// Push and import, round after round: the import for real in its own process.
$FLAT = '<ShippingDetails><ShippingType>Flat</ShippingType><ShippingServiceOptions><ShippingService>USPSFirstClass</ShippingService><ShippingServiceCost currencyID="USD">4.5</ShippingServiceCost><ShippingServicePriority>1</ShippingServicePriority></ShippingServiceOptions></ShippingDetails>';
/** One import of listing 110000000350 at this price, onto the product as it is, through the real mapper; its postage $FLAT's, or the one given. */
function import_round( array $settings, $price, $product_id, $ship = null ) {
	global $FLAT;
	$ship  = null === $ship ? $FLAT : $ship;
	$state = array(
		'settings' => $settings,
		'product'  => $product_id ? array( 'id' => $product_id, 'meta' => $GLOBALS['W']['meta'][ $product_id ] ?? array() ) : null,
		'item'     => '<ItemID>110000000350</ItemID><Title>Brake caliper</Title><SKU>BRK-350</SKU><Currency>USD</Currency><Site>US</Site><Quantity>2</Quantity><ListingType>FixedPriceItem</ListingType><ListingDuration>GTC</ListingDuration>'
			. '<ListingDetails><StartTime>2026-01-05T10:00:00.000Z</StartTime><EndTime>2026-12-05T10:00:00.000Z</EndTime></ListingDetails>'
			. '<SellingStatus><ListingStatus>Active</ListingStatus><QuantitySold>0</QuantitySold><CurrentPrice currencyID="USD">' . $price . '</CurrentPrice></SellingStatus>' . $ship,
	);
	$file = tempnam( sys_get_temp_dir(), 'tcg-price-' );
	file_put_contents( $file, json_encode( $state ) );
	list( $out ) = run_support( 'ebay-price-import-step.php', array( $file ) );
	unlink( $file );
	return (array) $out;
}
/** The product as the import left it, pushed: its price, and the StartPrice eBay was sent. */
function push_round( $id, array $imported ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	foreach ( (array) $imported['meta'] as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	foreach ( PUSHED as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	$product = new Price_Product( $id, array( 'name' => 'Brake caliper', 'description' => 'Used.', 'regular_price' => (string) ( $imported['meta']['_regular_price'] ?? '' ), 'stock' => 2, 'sku' => 'BRK-350', 'image_id' => 9350 ) );
	$GLOBALS['W']['images'][9350] = 'https://shop.example/wp-content/uploads/item-350.jpg';
	return sent_price( push( $product ) );
}
/** Rounds of import, then push: the website price after each import and the price each push sent. */
function rounds( array $settings, $first_price, $n, $product_id = 0, array $legacy_meta = array() ) {
	$price   = $first_price;
	$website = array();
	$sent    = array();
	if ( $legacy_meta ) {
		$GLOBALS['W']['meta'][ $product_id ] = $legacy_meta;
	}
	for ( $i = 0; $i < $n; $i++ ) {
		$imported   = import_round( $settings, $price, $product_id );
		$product_id = (int) ( $imported['id'] ?? 0 );
		$website[]  = $imported['meta']['_regular_price'] ?? null;
		part_shop( $settings );
		$price      = push_round( $product_id, $imported );
		$sent[]     = $price;
		$GLOBALS['W']['meta'][ $product_id ]['_ebay_item_id'] = '110000000350';
	}
	part_shop();
	return array( $website, $sent );
}
$bake = array( 'bake_shipping_into_price' => '1' );
check( 'eBay 20.00 with 4.50 postage, four rounds of import and push: the website 24.50 each time, eBay sent 20.00 each time', rounds( $bake, '20.0', 4 ), array( array( '24.50', '24.50', '24.50', '24.50' ), array( '20.00', '20.00', '20.00', '20.00' ) ) );
check( 'a product imported before 3.29.0, its 24.50 with no postage recorded: one round grows it once, as before, then never again', rounds( $bake, '24.5', 4, 351, array( '_regular_price' => '24.50', '_sku' => 'BRK-350', '_ebay_item_id' => '110000000350' ) ), array( array( '29.00', '29.00', '29.00', '29.00' ), array( '24.50', '24.50', '24.50', '24.50' ) ) );
check( 'with the rule too, plus 10%: 22.00 on eBay, 24.50 on the website, round after round', rounds( $bake + array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10' ), '22.0', 3 ), array( array( '24.50', '24.50', '24.50' ), array( '22.00', '22.00', '22.00' ) ) );
check( 'no postage added, no rule: 20.00 and 20.00, as before', rounds( array(), '20.0', 3 ), array( array( '20.0', '20.00', '20.00' ), array( '20.00', '20.00', '20.00' ) ) );

/**
 * Rounds of import then push, each under its own settings: the website price
 * after each import, the postage recorded, the price each push sent, and the
 * price a second push straight after sent.
 */
function toggled_rounds( array $plan, $price ) {
	$product_id = 0;
	$out        = array();
	foreach ( $plan as $settings ) {
		$imported   = import_round( $settings, $price, $product_id );
		$product_id = (int) ( $imported['id'] ?? 0 );
		part_shop( $settings );
		$sent       = push_round( $product_id, $imported );
		$out[]      = array( $imported['meta']['_regular_price'] ?? null, $imported['meta']['_ebay_baked_shipping'] ?? null, $sent );
		$price      = $sent;
		$GLOBALS['W']['meta'][ $product_id ]['_ebay_item_id'] = '110000000350';
	}
	part_shop();
	return $out;
}
check( '"Add eBay Shipping Cost" on, then off, then on again: off, the import writes eBay\'s 20.00 without postage and the record goes; eBay is sent 20.00 every time, never 24.50', toggled_rounds( array( $bake, array(), $bake ), '20.0' ), array( array( '24.50', '4.50', '20.00' ), array( '20.00', null, '20.00' ), array( '24.50', '4.50', '20.00' ) ) );
part_shop();
check( '  pushed with the setting off before any import since: the 4.50 still in its website price is taken off, 20.00 (3.28.8 sent 24.50)', verify_price( product( 352, array( 'regular_price' => '24.50' ), array( '_ebay_baked_shipping' => '4.50' ) ) ), array( '20.00' ) );

// A shop that only adds postage (3.29.0 re-verify, N1): a push notes the
// price eBay took but never marks it, so each import writes eBay's price
// and the postage it adds now - or none once the setting is off - into the
// website price, as 753c689 did. Pushed here as the sync pushes,
// process_single_push(), which notes what eBay took (do_push() alone does
// not).
/** The product as the import left it, pushed by process_single_push(): the StartPrice sent, and its mark after. */
function noted_push_round( $id, array $imported ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	foreach ( (array) $imported['meta'] as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	foreach ( PUSHED as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	new Price_Product( $id, array( 'name' => 'Brake caliper', 'description' => 'Used.', 'regular_price' => (string) ( $imported['meta']['_regular_price'] ?? '' ), 'stock' => 2, 'sku' => 'BRK-350', 'image_id' => 9350 ) );
	$GLOBALS['W']['images'][9350] = 'https://shop.example/wp-content/uploads/item-350.jpg';
	$GLOBALS['API']->calls        = array();
	exporter()->process_single_push( $id );
	return array( sent_price( $GLOBALS['API']->calls ), get_post_meta( $id, '_ebay_price_marked', true ) );
}
/**
 * Rounds of import then push, each step (settings, postage, a website price
 * typed here before the import): the website price and postage the import
 * left, the price the push sent, and its mark.
 */
function postage_rounds( array $plan, $price ) {
	$product_id = 0;
	$out        = array();
	foreach ( $plan as $step ) {
		list( $settings, $ship, $typed ) = $step + array( array(), null, null );
		if ( null !== $typed ) {
			$GLOBALS['W']['meta'][ $product_id ]['_regular_price'] = $typed;
		}
		$imported   = import_round( $settings, $price, $product_id, $ship );
		$product_id = (int) ( $imported['id'] ?? 0 );
		part_shop( $settings );
		list( $sent, $marked ) = noted_push_round( $product_id, $imported );
		$out[]      = array( $imported['meta']['_regular_price'] ?? null, $imported['meta']['_ebay_baked_shipping'] ?? null, $sent, $marked );
		$price      = (string) $sent;
		$GLOBALS['W']['meta'][ $product_id ]['_ebay_item_id'] = '110000000350';
	}
	part_shop();
	return $out;
}
$FLAT55 = str_replace( '>4.5<', '>5.5<', $FLAT );
check( 'only "Add eBay Shipping Cost", pushed as the sync pushes: never marked, eBay sent 20.00; postage changed on eBay to 5.50: the website 25.50; a website price typed as 30.00: eBay\'s 20.00 and its postage back (eBay won, as before); the setting turned off: 20.00, no postage kept (9cbec99 kept 24.50)', postage_rounds( array( array( $bake ), array( $bake, $FLAT55 ), array( $bake, $FLAT55, '30.00' ), array( array() ) ), '20.0' ), array( array( '24.50', '4.50', '20.00', '' ), array( '25.50', '5.50', '20.00', '' ), array( '25.50', '5.50', '20.00', '' ), array( '20.00', null, '20.00', '' ) ) );
check( '  under a rule as well: marked, so the rule switched off keeps 24.50 - the rule\'s markup is not taken for the website\'s', postage_rounds( array( array( $bake + array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10' ) ), array( $bake ) ), '22.0' ), array( array( '24.50', '4.50', '22.00', '1' ), array( '24.50', '4.50', '20.00', '' ) ) );
part_shop( $bake );
$variable = product( 353, array( 'type' => 'variable', 'sku' => 'VAR-353' ), PUSHED + array( '_ebay_item_id' => '110000000353' ), 'Price_Variable' );
$variable->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
$variable->children             = array( 3531, 3532 );
product( 3531, array( 'sku' => 'VAR-353-S', 'size' => 'Small', 'regular_price' => '14.50', 'stock' => 3 ), array( '_ebay_baked_shipping' => '4.50' ), 'Price_Variation' );
product( 3532, array( 'sku' => 'VAR-353-L', 'size' => 'Large', 'regular_price' => '16.50', 'stock' => 1 ), array( '_ebay_baked_shipping' => '4.50' ), 'Price_Variation' );
exporter()->process_single_push( 353 );
check( '  its variations: 10.00 and 12.00 noted, neither marked', array( get_post_meta( 3531, '_ebay_price_seen', true ), get_post_meta( 3532, '_ebay_price_seen', true ), get_post_meta( 3531, '_ebay_price_marked', true ), get_post_meta( 3532, '_ebay_price_marked', true ) ), array( '10.00', '12.00', '', '' ) );
part_shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTAX IN EBAY PRICES\n" . str_repeat( '=', 124 ) . "\n";

$TAX = array( 'enabled' => true, 'inclusive' => false, 'rates' => array( '' => array( 1 => array( 'rate' => '20.0000' ) ), 'reduced-rate' => array( 2 => array( 'rate' => '5.0000' ) ) ) );
foreach ( array( array( 'EBAY_GB', '20.0000', '24.00' ), array( 'EBAY_DE', '19.0000', '23.80' ), array( 'EBAY_AU', '10.0000', '22.00' ), array( 'EBAY_FR', '20.0000', '24.00' ), array( 'EBAY_IT', '22.0000', '24.40' ), array( 'EBAY_ES', '21.0000', '24.20' ) ) as $site ) {
	$TAX['rates'][''] = array( 1 => array( 'rate' => $site[1] ) );
	part_shop( array( 'marketplace' => $site[0], 'ebay_price_tax' => 'auto' ) );
	check( $site[0] . ', Auto, prices entered without tax, ' . (float) $site[1] . '%: 20.00 is sent as ' . $site[2], verify_price( product( 360, array( 'regular_price' => '20.00' ) ) ), array( $site[2] ) );
}
$TAX['rates'][''] = array( 1 => array( 'rate' => '20.0000' ) );
part_shop( array( 'marketplace' => 'EBAY_US', 'ebay_price_tax' => 'auto' ) );
check( 'EBAY_US on Auto: no tax, 20.00 - US bodies unchanged', verify_price( product( 361 ) ), array( '20.00' ) );
part_shop( array( 'marketplace' => 'EBAY_US', 'ebay_price_tax' => 'yes' ) );
check( '  on Yes: no tax either - eBay adds sales tax at checkout in the US (3.29.0 re-verify)', verify_price( product( 361 ) ), array( '20.00' ) );
part_shop( array( 'marketplace' => 'EBAY_CA', 'ebay_price_tax' => 'yes' ) );
check( '  nor on eBay Canada', verify_price( product( 361 ) ), array( '20.00' ) );
part_shop( array( 'marketplace' => 'EBAY_GB', 'ebay_price_tax' => 'yes' ) );
check( '  eBay UK on Yes: the shop\'s own rate, 24.00', verify_price( product( 361 ) ), array( '24.00' ) );
part_shop( array( 'marketplace' => 'EBAY_GB' ) );
check( 'EBAY_GB with the setting never chosen (No): 20.00, as before', verify_price( product( 362 ) ), array( '20.00' ) );
part_shop( array( 'marketplace' => 'EBAY_GB', 'ebay_price_tax' => 'no' ) );
check( '  on No: 20.00', verify_price( product( 362 ) ), array( '20.00' ) );
part_shop( array( 'marketplace' => 'EBAY_GB', 'ebay_price_tax' => 'auto' ) );
$TAX['inclusive'] = true;
check( 'prices entered with tax: nothing added', verify_price( product( 363 ) ), array( '20.00' ) );
$TAX['inclusive'] = false;
$TAX['enabled']   = false;
check( 'WooCommerce not calculating tax: nothing added', verify_price( product( 363 ) ), array( '20.00' ) );
$TAX['enabled'] = true;
check( 'a product that is not taxable: nothing added', verify_price( product( 364, array( 'taxable' => false ) ) ), array( '20.00' ) );
check( 'one in the reduced-rate class: 5%, 21.00', verify_price( product( 365, array( 'tax_class' => 'reduced-rate' ) ) ), array( '21.00' ) );
check( 'one in a class with no rate at the shop\'s address: nothing added', verify_price( product( 366, array( 'tax_class' => 'zero-rate' ) ) ), array( '20.00' ) );
check( 'its own eBay price: that, with no tax added', verify_price( product( 367, array(), array( '_ebay_export_price' => '30.00' ) ) ), array( '30.00' ) );
part_shop( array( 'marketplace' => 'EBAY_GB', 'ebay_price_tax' => 'auto', 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10', 'ebay_price_rounding' => '99' ) );
check( 'tax, then the rule: 20.00 with 20% is 24.00, plus 10% 26.40, to .99 26.99', verify_price( product( 368 ) ), array( '26.99' ) );
$TAX = array( 'enabled' => false, 'inclusive' => false, 'rates' => array() );
part_shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT THE ADMIN SHOWS AND SAVES\n" . str_repeat( '=', 124 ) . "\n";

/** What a function printed. */
function printed( callable $fn ) {
	ob_start();
	$fn();
	return (string) ob_get_clean();
}
part_shop( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10' ) );
$tab = printed( function () { TCGiant_Sync_Ebay_Price::render_product_section( product( 370, array(), array( '_ebay_export_price' => '12.50' ) ) ); } );
check( 'the eBay tab: the field, its marker, its own price in it, the rule\'s greyed', array( false !== strpos( $tab, 'name="_ebay_export_price" value="12.50" placeholder="22.00"' ), false !== strpos( $tab, 'name="_ebay_export_price_shown" value="1"' ) ), array( true, true ) );
check( '  "Sent to eBay: 12.50", and why', array( false !== strpos( $tab, 'Sent to eBay: 12.50' ), false !== strpos( $tab, 'This product&#039;s own eBay price.' ) ), array( true, true ) );
check( '  the Push and Verify buttons carry the field', array( false !== strpos( $tab, 'override_ebay_price_shown=1&override_ebay_price=' ), false !== strpos( $tab, 'tcgiant_(push|verify)_product' ) ), array( true, true ) );
$tab = printed( function () { TCGiant_Sync_Ebay_Price::render_product_section( product( 371 ) ); } );
check( 'none of its own: empty, the rule\'s 22.00 greyed and sent, and why', array( false !== strpos( $tab, 'name="_ebay_export_price" value="" placeholder="22.00"' ), false !== strpos( $tab, 'Sent to eBay: 22.00' ), false !== strpos( $tab, 'The regular price, plus 10%.' ) ), array( true, true, true ) );
$tab = printed( function () { TCGiant_Sync_Ebay_Price::render_product_section( product( 372, array( 'regular_price' => '61.00' ), array( '_ebay_listing_type' => 'Chinese', '_ebay_origin' => 'import', '_ebay_item_id' => '1' ) ) ); } );
check( 'an imported auction: its Update leaves the opening bid as it is, said', false !== strpos( $tab, 'an Update leaves its opening bid as it is on eBay' ), true );
$tab = printed( function () { $v = product( 373, array( 'type' => 'variable' ), array(), 'Price_Variable' ); $v->children = array( 3731, 3732 ); product( 3731, array( 'regular_price' => '10' ), array(), 'Price_Variation' ); product( 3732, array( 'regular_price' => '20' ), array( '_ebay_export_price' => '30' ), 'Price_Variation' ); TCGiant_Sync_Ebay_Price::render_product_section( $v ); } );
check( 'a variable product: no field of its own, its variations\' range sent, and where to set them', array( false !== strpos( $tab, 'name="_ebay_export_price"' ), false !== strpos( $tab, 'Sent to eBay: 11.00 - 30.00' ), false !== strpos( $tab, 'under Variations' ), false !== strpos( $tab, 'ajaxPrefilter' ) ), array( false, true, true, false ) );
$field = printed( function () { TCGiant_Sync_Ebay_Price::render_variation_field( 3, array(), (object) array( 'ID' => 3732 ) ); } );
check( 'a variation\'s field: by its place, its own price, the rule\'s greyed, and its marker', array( false !== strpos( $field, 'name="_ebay_export_price[3]" value="30.00" placeholder="22.00"' ), false !== strpos( $field, 'name="_ebay_export_price_shown[3]"' ) ), array( true, true ) );

$settings_html = printed( function () { TCGiant_Sync_Ebay_Price::render_settings_fields( array( 'ebay_price_rule' => 'both', 'ebay_price_percent' => '12', 'ebay_price_fixed' => '0.50', 'ebay_price_rounding' => 'whole', 'ebay_price_tax' => 'auto' ) ); } );
check( 'Settings: every setting on the push form, as saved', array(
	false !== strpos( $settings_html, '<option value="both"  selected="selected">' ),
	false !== strpos( $settings_html, 'name="tcgiant_sync_ebay_settings[ebay_price_percent]" value="12"' ),
	false !== strpos( $settings_html, 'name="tcgiant_sync_ebay_settings[ebay_price_fixed]" value="0.50"' ),
	false !== strpos( $settings_html, '<option value="whole"  selected="selected">' ),
	false !== strpos( $settings_html, 'value="auto"  checked="checked"' ),
), array( true, true, true, true, true ) );
$settings_html = printed( function () { TCGiant_Sync_Ebay_Price::render_settings_fields( array() ); } );
check( '  never chosen: no rule, no rounding, tax No', array( false !== strpos( $settings_html, '<option value="none"  selected="selected">The regular price (no rule)' ), false !== strpos( $settings_html, 'value="no"  checked="checked"' ) ), array( true, true ) );
$admin_src = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
preg_match( "/'export' => array\((.*?)\),\s*\);/s", $admin_src, $export_form );
check( '  each is one the push form saves (TCGiant_Sync_Admin::SETTINGS_FORMS)', array_values( array_filter( TCGiant_Sync_Ebay_Price::SETTING_KEYS, function ( $key ) use ( $export_form ) { return false === strpos( $export_form[1] ?? '', "'" . $key . "'" ); } ) ), array() );
check( '  and the push form draws them', false !== strpos( (string) file_get_contents( $ROOT . '/admin/views/settings.php' ), 'TCGiant_Sync_Ebay_Price::render_settings_fields(' ), true );

part_shop();
product( 380, array( 'regular_price' => '20.00' ) );
check( 'the Listings screen, nothing in use: the figure it showed before', TCGiant_Sync_Ebay_Price::listing_cell( 380, '18.00' ), '<span class="amount">18.00</span>' );
check( '  no product: the same', TCGiant_Sync_Ebay_Price::listing_cell( 389, '18.00' ), '<span class="amount">18.00</span>' );
product( 381, array( 'regular_price' => '20.00' ), array( '_ebay_export_price' => '25.00' ) );
check( '  its own eBay price: that, and why', TCGiant_Sync_Ebay_Price::listing_cell( 381, '20.00' ), '<span title="Sent to eBay. This product&#039;s own eBay price."><span class="amount">25.00</span></span>' );
product( 382, array( 'regular_price' => '24.50' ), array( '_ebay_baked_shipping' => '4.50' ) );
check( '  postage recorded in its website price, no rule: the figure it showed before 3.29.0 (3.29.0 review, finding 9)', TCGiant_Sync_Ebay_Price::listing_cell( 382, '24.50' ), '<span class="amount">24.50</span>' );
$tab = printed( function () { TCGiant_Sync_Ebay_Price::render_product_section( wc_get_product( 382 ) ); } );
check( '  its eBay tab still says the 20.00 sent, and why', array( false !== strpos( $tab, 'Sent to eBay: 20.00' ), false !== strpos( $tab, 'less the 4.50 postage the import added to it' ) ), array( true, true ) );
part_shop( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10' ) );
check( '  under the rule: the rule\'s', TCGiant_Sync_Ebay_Price::listing_cell( 380, '20.00' ), '<span title="Sent to eBay. The regular price, plus 10%."><span class="amount">22.00</span></span>' );
check( '  a variable product: its variations\' range', TCGiant_Sync_Ebay_Price::listing_cell( 373, '10.00' ), '<span title="Sent to eBay. Each variation&#039;s, set under Variations: its own eBay price, or its regular price under the rule in Settings."><span class="amount">11.00</span> &ndash; <span class="amount">30.00</span></span>' );
product( 383, array( 'regular_price' => '37.00' ), array( '_ebay_listing_type' => 'Chinese' ) );
check( '  an auction: the opening bid a new listing is sent, and that an Update leaves a running one\'s', TCGiant_Sync_Ebay_Price::listing_cell( 383, '37.00' ), '<span title="The opening bid a new listing is sent; an Update leaves a running auction&#039;s as it is. The regular price, plus 10%."><span class="amount">40.70</span></span>' );
part_shop();
check( '  the Listings screen draws its price cell with it', false !== strpos( (string) file_get_contents( $ROOT . '/admin/views/listings.php' ), 'TCGiant_Sync_Ebay_Price::listing_cell( $product_id,' ), true );
check( 'the eBay tab draws its section, and the Push and Verify handlers both save the field', array( substr_count( $admin_src, 'TCGiant_Sync_Ebay_Price::render_product_section( $product );' ), substr_count( $admin_src, "TCGiant_Sync_Ebay_Price::save_posted( \$product_id, \$_POST, 'override_ebay_price' );" ) ), array( 1, 2 ) );
check( 'the fields\' hooks are registered in the admin', false !== strpos( (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync.php' ), 'TCGiant_Sync_Ebay_Price::register_admin();' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PUSHED LIVE AUCTION KEEPS ITS OPENING BID UNDER A RULE\n" . str_repeat( '=', 124 ) . "\n";

$pushed_auction = array( '_ebay_listing_type' => 'Chinese', '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) + PUSHED;
part_shop( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10' ) );
product( 390, array( 'regular_price' => '37.00', 'stock' => 1 ), $pushed_auction + array( '_ebay_item_id' => '110000000390', '_ebay_price_seen' => '37.00' ) );
$calls = push( wc_get_product( 390 ) );
check( 'a live auction the shop pushed, revised under plus 10%: no StartPrice (eBay refuses a new opening bid once there are bids), its quantity as before', array( $calls[0][0] ?? null, start_prices( $calls[0][1] ?? '' ), false !== strpos( (string) ( $calls[0][1] ?? '' ), '<Quantity>1</Quantity>' ) ), array( 'ReviseItem', array(), true ) );
exporter()->process_single_push( 390 );
check( '  and nothing noted as eBay\'s price', get_post_meta( 390, '_ebay_price_seen', true ), '37.00' );
update_post_meta( 390, '_ebay_export_price', '45.00' );
check( '  with an eBay price of its own: no StartPrice either', start_prices( push( wc_get_product( 390 ) )[0][1] ?? '' ), array() );
delete_post_meta( 390, '_ebay_export_price' );
check( '  a new listing of it - ended, made again: the rule\'s 40.70', ( function () {
	$GLOBALS['API']->answers['ReviseItem'] = new WP_Error( 'api_error', 'The auction has already been closed.' );
	$GLOBALS['API']->answers['GetItem']    = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000390', 'ListingType' => 'Chinese', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ) );
	$calls = push( wc_get_product( 390 ) );
	unset( $GLOBALS['API']->answers['ReviseItem'], $GLOBALS['API']->answers['GetItem'] );
	return array( array_column( $calls, 0 ), sent_price( $calls ) );
} )(), array( array( 'ReviseItem', 'GetItem', 'AddItem' ), '40.70' ) );
part_shop();
check( 'no rule: its revise sends the opening bid as 3.28.8 did, 37.00', start_prices( push( wc_get_product( 390 ) )[0][1] ?? '' ), array( '37.00' ) );
part_shop( array( 'bake_shipping_into_price' => '1' ) );
product( 391, array( 'regular_price' => '41.50', 'stock' => 1 ), $pushed_auction + array( '_ebay_item_id' => '110000000391', '_ebay_price_seen' => '37.00', '_ebay_baked_shipping' => '4.50' ) );
check( '  a shop that only adds postage: the revise sends 37.00, its regular price less the 4.50, as 753c689 did (3.28.8 sent 41.50)', start_prices( push( wc_get_product( 391 ) )[0][1] ?? '' ), array( '37.00' ) );
part_shop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNEVER BELOW 0.99 FROM THE RULE OR AN EBAY PRICE OF ITS OWN\n" . str_repeat( '=', 124 ) . "\n";

/** A push's answer, and the calls it made. */
function push_answer( WC_Product $product ) {
	$GLOBALS['API']->calls = array();
	$res                   = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	return array( is_wp_error( $res ) ? $res->get_error_code() : 'pushed', array_column( $GLOBALS['API']->calls, 0 ), is_wp_error( $res ) ? $res->get_error_message() : '' );
}
part_shop();
list( $code, $made, $said ) = push_answer( product( 400, array( 'regular_price' => '5.00' ), array( '_ebay_export_price' => '0.50' ) ) );
check( 'an eBay price of its own of 0.50: the push refused before eBay is asked, and why', array( $code, $made, $said ), array( 'ebay_price_too_low', array(), 'The eBay price of WC #400 would be 0.50, below 0.99, the lowest TCGiant Sync sends eBay from the eBay price rule, tax or an eBay price of its own. Raise its price or its eBay price, then push again. Nothing was sent to eBay.' ) );
check( '  0.99 itself goes', push_answer( product( 401, array( 'regular_price' => '5.00' ), array( '_ebay_export_price' => '0.99' ) ) )[0], 'pushed' );
part_shop( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10' ) );
check( 'plus 10% on 0.50: 0.55, refused', push_answer( product( 402, array( 'regular_price' => '0.50' ) ) )[0], 'ebay_price_too_low' );
part_shop();
check( 'no rule, a regular price of 0.50: sent as 3.28.8 sent it, for eBay to judge', array( push_answer( product( 403, array( 'regular_price' => '0.50' ) ) )[0], start_prices( $GLOBALS['API']->calls[0][1] ?? '' ) ), array( 'pushed', array( '0.50' ) ) );
check( '  nor an eBay price of its own that is the regular price', push_answer( product( 404, array( 'regular_price' => '0.50' ), array( '_ebay_export_price' => '0.50' ) ) )[0], 'pushed' );
part_shop( array( 'bake_shipping_into_price' => '1' ) );
check( '  nor, in a shop that only adds postage, 0.80 with 0.30 of postage in it: sent as 0.50, as 753c689 sent it (3.29.0 re-verify)', array( push_answer( product( 409, array( 'regular_price' => '0.80' ), array( '_ebay_baked_shipping' => '0.30' ) ) )[0], start_prices( $GLOBALS['API']->calls[0][1] ?? '' ) ), array( 'pushed', array( '0.50' ) ) );
$TAX = array( 'enabled' => true, 'inclusive' => false, 'rates' => array( '' => array( 1 => array( 'rate' => '20.0000' ) ) ) );
part_shop( array( 'marketplace' => 'EBAY_GB', 'ebay_price_tax' => 'auto' ) );
check( 'tax alone, 20% on 0.50: 0.60, refused', push_answer( product( 410, array( 'regular_price' => '0.50' ) ) )[0], 'ebay_price_too_low' );
$TAX = array( 'enabled' => false, 'inclusive' => false, 'rates' => array() );
part_shop();
$variable = product( 405, array( 'type' => 'variable', 'sku' => 'VAR-405' ), array(), 'Price_Variable' );
$variable->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
$variable->children             = array( 4051, 4052 );
product( 4051, array( 'sku' => 'VAR-405-S', 'size' => 'Small', 'regular_price' => '10', 'stock' => 3 ), array(), 'Price_Variation' );
product( 4052, array( 'sku' => 'VAR-405-L', 'size' => 'Large', 'regular_price' => '12.5', 'stock' => 1 ), array( '_ebay_export_price' => '0.80' ), 'Price_Variation' );
list( $code, $made, $said ) = push_answer( $variable );
check( 'a variation at an eBay price of its own of 0.80: the push refused, naming it', array( $code, $made, false !== strpos( $said, 'WC #4052 would be 0.80' ) ), array( 'ebay_price_too_low', array(), true ) );
item_xml( product( 406, array( 'regular_price' => '5.00' ), array( '_ebay_export_price' => '0.50' ) ), exporter()->get_export_settings( 406 ) );
check( 'Verify and Check with eBay ask the same after their build: refused', exporter()->description_too_long() ? exporter()->description_too_long()->get_error_code() : null, 'ebay_price_too_low' );
item_xml( product( 407 ), exporter()->get_export_settings( 407 ) );
check( '  and the next build, at 20.00, is not', exporter()->description_too_long(), null );
item_xml( wc_get_product( 406 ), exporter()->get_export_settings( 406 ) );
item_xml( wc_get_product( 320 ), exporter()->get_export_settings( 320 ) );
check( '  nor a variable product\'s build after a refused one: each build is judged afresh', exporter()->description_too_long(), null );
$tab = printed( function () { TCGiant_Sync_Ebay_Price::render_product_section( product( 408, array( 'regular_price' => '5.00' ), array( '_ebay_export_price' => '0.50' ) ) ); } );
check( 'the eBay tab says so before a push', false !== strpos( $tab, 'would be 0.50, below 0.99' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SETTINGS SCREEN: A MARKUP, READ AS TYPED, OR NOT SAVED AND SAID\n" . str_repeat( '=', 124 ) . "\n";

$GLOBALS['SETTINGS_ERRORS'] = array();
function add_settings_error( $setting, $code, $message, $type = 'error' ) { $GLOBALS['SETTINGS_ERRORS'][] = array( $setting, $code, $message, $type ); }
function settings_errors( $setting = '' ) { echo '<div class="settings-errors-shown" data-setting="' . esc_attr( $setting ) . '"></div>'; }
/** check_settings() on a save: what it would store, and what it said. */
function saved( array $posted, array $stored = array() ) {
	$GLOBALS['SETTINGS_ERRORS'] = array();
	$out = TCGiant_Sync_Ebay_Price::check_settings( $posted + array( 'marketplace' => 'EBAY_US' ), $stored );
	$rule = array_intersect_key( $out, array_flip( array( 'ebay_price_rule', 'ebay_price_percent', 'ebay_price_fixed', 'ebay_price_rounding' ) ) );
	ksort( $rule );
	return array( $rule, array_column( $GLOBALS['SETTINGS_ERRORS'], 2 ) );
}
check( '"10%", "$1", rounded to .99: stored as 10 and 1.00, nothing said', saved( array( 'ebay_price_rule' => 'both', 'ebay_price_percent' => '10%', 'ebay_price_fixed' => '$1', 'ebay_price_rounding' => '99' ) ), array( array( 'ebay_price_fixed' => '1.00', 'ebay_price_percent' => '10', 'ebay_price_rounding' => '99', 'ebay_price_rule' => 'both' ), array() ) );
check( '"1,50" and " 12,5 % ": 1.50 and 12.5', saved( array( 'ebay_price_rule' => 'both', 'ebay_price_percent' => ' 12,5 % ', 'ebay_price_fixed' => '1,50' ) )[0], array( 'ebay_price_fixed' => '1.50', 'ebay_price_percent' => '12.5', 'ebay_price_rounding' => 'none', 'ebay_price_rule' => 'both' ) );
$before = array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '8', 'ebay_price_rounding' => 'whole' );
check( 'a minus amount: not saved - the rule stays as it was - and said', saved( array( 'ebay_price_rule' => 'fixed', 'ebay_price_fixed' => '-5', 'ebay_price_rounding' => '99' ), $before ), array( array( 'ebay_price_percent' => '8', 'ebay_price_rounding' => 'whole', 'ebay_price_rule' => 'percent' ), array( 'The eBay price rule was not saved, and stays as it was: the fixed amount must be zero or more, not -5: the rule adds to the price.' ) ) );
check( '  a figure it cannot read: not saved, and said', saved( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => 'ten' ), $before ), array( array( 'ebay_price_percent' => '8', 'ebay_price_rounding' => 'whole', 'ebay_price_rule' => 'percent' ), array( 'The eBay price rule was not saved, and stays as it was: "ten" is not a percentage TCGiant Sync can read.' ) ) );
check( '  a rule without its figure: not saved, never a rule of 0', saved( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '' ) ), array( array(), array( 'The eBay price rule was not saved, and stays as it was: the rule needs a percentage.' ) ) );
check( '  a box the chosen rule does not use is not checked: a minus amount under a percentage rule saves, kept as typed (3.29.0 re-verify)', saved( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '5', 'ebay_price_fixed' => '-1' ) ), array( array( 'ebay_price_fixed' => '-1', 'ebay_price_percent' => '5', 'ebay_price_rounding' => 'none', 'ebay_price_rule' => 'percent' ), array() ) );
check( '  "12,5" as the percentage, "x" in the unused amount: saved as 12.5', saved( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '12,5', 'ebay_price_fixed' => 'x' ) ), array( array( 'ebay_price_fixed' => 'x', 'ebay_price_percent' => '12.5', 'ebay_price_rounding' => 'none', 'ebay_price_rule' => 'percent' ), array() ) );
check( '  the rule switched to none with "abc" left in the percentage: saved, always', saved( array( 'ebay_price_rule' => 'none', 'ebay_price_percent' => 'abc', 'ebay_price_fixed' => '-2' ), array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10', 'ebay_price_rounding' => '99' ) ), array( array( 'ebay_price_fixed' => '-2', 'ebay_price_percent' => 'abc', 'ebay_price_rounding' => 'none', 'ebay_price_rule' => 'none' ), array() ) );
check( '  a usable figure in an unused box is stored plain, as a used one is', saved( array( 'ebay_price_rule' => 'fixed', 'ebay_price_percent' => '10%', 'ebay_price_fixed' => '$2' ) )[0], array( 'ebay_price_fixed' => '2.00', 'ebay_price_percent' => '10', 'ebay_price_rounding' => 'none', 'ebay_price_rule' => 'fixed' ) );
check( '  a minus figure in a box the rule uses is still refused', saved( array( 'ebay_price_rule' => 'both', 'ebay_price_percent' => '5', 'ebay_price_fixed' => '-1' ) )[1], array( 'The eBay price rule was not saved, and stays as it was: the fixed amount must be zero or more, not -1: the rule adds to the price.' ) );
check( '  the percentage box no longer suggests a minus figure', array( false !== strpos( printed( function () { TCGiant_Sync_Ebay_Price::render_settings_fields( array() ); } ), 'placeholder="e.g. 12"' ), false !== strpos( printed( function () { TCGiant_Sync_Ebay_Price::render_settings_fields( array() ); } ), '-5' ) ), array( true, false ) );
check( 'no rule, boxes empty, a rounding that is not one: saved as none, nothing said', saved( array( 'ebay_price_rule' => 'none', 'ebay_price_percent' => '', 'ebay_price_fixed' => '', 'ebay_price_rounding' => '95' ) ), array( array( 'ebay_price_fixed' => '', 'ebay_price_percent' => '', 'ebay_price_rounding' => 'none', 'ebay_price_rule' => 'none' ), array() ) );
check( 'a save with none of the rule\'s settings in it: left alone', TCGiant_Sync_Ebay_Price::check_settings( array( 'marketplace' => 'EBAY_GB', 'ebay_price_tax' => 'auto' ), array() ), array( 'marketplace' => 'EBAY_GB', 'ebay_price_tax' => 'auto' ) );
check( 'the push form\'s save checks them, and the form shows what was said', array( false !== strpos( $admin_src, '$sanitized = TCGiant_Sync_Ebay_Price::check_settings( $sanitized, $kept );' ), false !== strpos( printed( function () { TCGiant_Sync_Ebay_Price::render_settings_fields( array() ); } ), 'settings-errors-shown' ) ), array( true, true ) );

echo "\nEVERY PRICE A REQUEST CARRIES COMES FROM THE ONE HELPER\n" . str_repeat( '=', 124 ) . "\n";

$builders = array();
$regular  = array();
foreach ( array_merge( glob( $ROOT . '/includes/*.php' ), glob( $ROOT . '/admin/*.php' ), glob( $ROOT . '/admin/views/*.php' ) ) as $file ) {
	$src = (string) file_get_contents( $file );
	if ( preg_match_all( '#.*<StartPrice>.*#', $src, $lines ) ) {
		foreach ( $lines[0] as $line ) {
			if ( false === strpos( ltrim( $line ), '//' ) && false === strpos( ltrim( $line ), '*' ) ) {
				$builders[] = basename( $file ) . ': ' . trim( $line );
			}
		}
	}
	if ( preg_match( '#<(BuyItNowPrice|ReservePrice)>#', $src ) ) {
		$builders[] = basename( $file ) . ': sends a ' . $file;
	}
}
check( 'three places put a StartPrice in a request: the item\'s, each variation\'s, and a relist\'s variations at eBay\'s own', $builders, array(
	'class-tcgiant-sync-exporter.php: . \'<StartPrice>\' . esc_xml( $ev[\'start_price\'] ) . \'</StartPrice>\'',
	'class-tcgiant-sync-exporter.php: $xml .= \'<StartPrice>\' . esc_attr( $price ) . \'</StartPrice>\' . "\n";',
	'class-tcgiant-sync-exporter.php: $xml        .= "\t\t<StartPrice>" . esc_attr( $child_price ) . "</StartPrice>\n";',
) );
$exporter_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-exporter.php' );
check( '  the item\'s and each variation\'s from TCGiant_Sync_Ebay_Price::for_product(), and no regular price read for a body', array( substr_count( $exporter_src, '$price               = TCGiant_Sync_Ebay_Price::for_product( $product );' ), substr_count( $exporter_src, '$child_price = TCGiant_Sync_Ebay_Price::for_product( $child );' ), substr_count( $exporter_src, 'get_regular_price()' ) ), array( 1, 1, 1 ) );
check( '  the one regular price read left is the Listings row\'s, where no price was sent', false !== strpos( $exporter_src, '$price = $result[\'prices\'][ $product_id ] ?? $product->get_regular_price();' ), true );

done();
