<?php
/**
 * Harness: "eBay Prices Include Tax" on Auto for new installs only (3.31.1,
 * the owner's answer to 3.29.0's question 1).
 *
 * 3.29.0 shipped the setting with No as its default: Auto would have raised
 * every VAT-registered GB, EU or AU shop's eBay prices by its VAT rate at the
 * next push. 3.31.1 makes Auto the default where nothing has been pushed yet,
 * and leaves every shop that ran an earlier version as it was.
 *
 * Proved, through the real TCGiant_Sync_Ebay_Price and TCGiant_Sync_Exporter
 * on support/template-world.php's WordPress, with WooCommerce's tax played:
 *
 *  - a new install - no settings saved at all when 3.31.1 first runs - is
 *    decided once, on init, as Auto: never saved, the setting reads Auto,
 *    the Settings screen shows it, and a push on eBay UK adds the shop's VAT
 *    (on eBay US nothing); nothing is offered; a value saved since stands;
 *  - a shop that ran an earlier version keeps No - its pushes as before -
 *    and, on eBay UK, Germany, France, Italy, Spain or Australia with
 *    WooCommerce's tax on and prices entered without it, still on No, is
 *    offered Auto once: the notice says its eBay prices rise by its VAT rate
 *    (20%) at the next push; Use Auto saves Auto and Keep No saves No, only
 *    that key, and put the notice away for good; a shop that chose in
 *    Settings meanwhile never sees it; nowhere else is it shown;
 *  - the hooks, the handler's checks, uninstall.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-variations.php';

class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
/** The settings' one writer here, as the real one: only the keys given, onto the row. */
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS         = array();
	const AUCTION_LISTED_KEY  = '_ebay_auction_listed_item';
	const AUCTION_FIGURE_KEYS = array();
	public static function __callStatic( $name, $args ) { return null; }
	public static function save_settings_keys( array $keys ) {
		TCGiant_Sync_OAuth::$settings = array_replace( TCGiant_Sync_OAuth::$settings, $keys );
		update_option( 'tcgiant_sync_ebay_settings', TCGiant_Sync_OAuth::$settings );
	}
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
	public static function table_exists() { return false; }
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}
function get_post_status( $id ) { return 'publish'; }
function get_attached_file( $id ) { return ''; }

// WooCommerce's tax, played: on, prices entered without it, 20% standard.
$GLOBALS['TAX'] = array( 'enabled' => true, 'inclusive' => false, 'rates' => array( '' => array( 1 => array( 'rate' => '20.0000' ) ) ) );
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

// What the admin draws with, played.
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function selected( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) { echo $out; } return $out; }
function checked( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; }
function wp_nonce_field( $action = -1 ) { echo '<input type="hidden" name="_wpnonce" value="nonce:' . esc_attr( $action ) . '">'; }
$GLOBALS['SCREEN'] = 'toplevel_page_tcgiant-sync';
function get_current_screen() { return null === $GLOBALS['SCREEN'] ? null : (object) array( 'id' => $GLOBALS['SCREEN'] ); }

/** A product that says whether it is taxed, and in which class. */
class Tax_Product extends WC_Product {
	public function is_taxable() { return true; }
	public function get_tax_class() { return ''; }
}

$ROOT = dirname( __DIR__ );

/** A shop on a marketplace, set up to push, and these settings. */
function tax_shop( $marketplace, array $extra = array() ) {
	TCGiant_Sync_OAuth::$settings = array_merge( array(
		'marketplace'               => $marketplace,
		'export_category_id'        => '261328',
		'export_condition_id'       => '1000',
		'export_location'           => 'Leeds',
		'export_postal_code'        => 'LS1 1AA',
		'export_fulfillment_policy' => 'FP1',
		'export_return_policy'      => 'RP1',
		'export_payment_policy'     => 'PP1',
		'export_listing_type'       => 'FixedPriceItem',
		'export_listing_duration'   => 'GTC',
	), $extra );
}
/** The StartPrice Verify's item would send for a product at 20.00. */
function price_sent() {
	unset( $GLOBALS['W']['meta'][ 700 ] );
	$product = new Tax_Product( 700, array( 'name' => 'Item 700', 'description' => 'An item.', 'regular_price' => '20.00', 'stock' => 2, 'sku' => 'TAX-700', 'image_id' => 9700 ) );
	$GLOBALS['W']['images'][9700] = 'https://shop.example/wp-content/uploads/item-700.jpg';
	preg_match_all( '#<StartPrice>([^<]*)</StartPrice>#', (string) item_xml( $product, exporter()->get_export_settings( 700 ) ), $m );
	return $m[1];
}
/** What a callable prints. */
function printed( callable $fn ) {
	ob_start();
	$fn();
	return (string) ob_get_clean();
}
function notice() {
	return printed( array( 'TCGiant_Sync_Ebay_Price', 'render_tax_notice' ) );
}
/**
 * The database as decide_tax_default() asks it (fix round 1): whether any
 * product is linked to an eBay listing - $GLOBALS['LINKED'], a post ID or
 * null - and whether the query failed ($GLOBALS['DB_ERROR']).
 */
class Tax_DB extends Template_World_DB {
	public $last_error = '';
	public $asked      = array();
	public function get_var( $query ) {
		$this->asked[]    = $query;
		$this->last_error = (string) ( $GLOBALS['DB_ERROR'] ?? '' );
		return '' !== $this->last_error ? null : ( $GLOBALS['LINKED'] ?? null );
	}
}
$GLOBALS['wpdb'] = new Tax_DB();
/** A site as 3.31.1 finds it at its first request: these options, and its settings row or none. */
function first_request( $settings_row ) {
	$GLOBALS['W']['options'] = array();
	$GLOBALS['W']['log']     = array();
	$GLOBALS['wpdb']->asked  = array();
	if ( null !== $settings_row ) {
		update_option( 'tcgiant_sync_ebay_settings', $settings_row );
	}
	TCGiant_Sync_Ebay_Price::decide_tax_default();
	return get_option( 'tcgiant_sync_tax_default' );
}
function said( $words ) {
	foreach ( $GLOBALS['W']['log'] as $row ) {
		if ( false !== strpos( $row[1], $words ) ) {
			return true;
		}
	}
	return false;
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA NEW INSTALL: AUTO, NOTHING OFFERED\n" . str_repeat( '=', 124 ) . "\n";

tax_shop( 'EBAY_GB' );
$GLOBALS['W']['options'] = array();
check( 'before 3.31.1 has decided (the first request, before init): never saved reads No', TCGiant_Sync_Ebay_Price::tax_mode( array() ), 'no' );
check( 'no settings saved at all when 3.31.1 first runs: decided a new install - Auto, nothing to offer', first_request( null ), array( 'default' => 'auto', 'since' => '3.31.1', 'notice' => 'none' ) );
check( '  asked once whether any product is linked to an eBay listing (fix round 1)', $GLOBALS['wpdb']->asked, array( array( "SELECT post_id FROM wp_postmeta WHERE meta_key = %s AND meta_value <> '' LIMIT 1", array( '_ebay_item_id' ) ) ) );
$GLOBALS['LINKED'] = '512';
check( '  no settings row, but a product linked to eBay: a shop that ran before - No, the notice due', first_request( null ), array( 'default' => 'no', 'since' => '3.31.1', 'notice' => 'pending' ) );
$GLOBALS['LINKED']   = null;
$GLOBALS['DB_ERROR'] = 'MySQL server has gone away';
check( '  no settings row, and the database cannot say: No, as for a shop with listings', first_request( null )['default'] ?? null, 'no' );
$GLOBALS['DB_ERROR'] = '';
first_request( null );
check( '  the setting never saved reads Auto; a value saved stands', array( TCGiant_Sync_Ebay_Price::tax_mode( array() ), TCGiant_Sync_Ebay_Price::tax_mode(), TCGiant_Sync_Ebay_Price::tax_mode( array( 'ebay_price_tax' => 'no' ) ), TCGiant_Sync_Ebay_Price::tax_mode( array( 'ebay_price_tax' => 'yes' ) ), TCGiant_Sync_Ebay_Price::tax_mode( array( 'ebay_price_tax' => '' ) ) ), array( 'auto', 'auto', 'no', 'yes', 'no' ) );
check( '  the Settings screen shows Auto chosen', ( function () {
	$html = printed( function () { TCGiant_Sync_Ebay_Price::render_settings_fields( array() ); } );
	return array( false !== strpos( $html, 'value="auto"  checked="checked"' ), false !== strpos( $html, 'value="no"  checked="checked"' ), false !== strpos( $html, 'id="tc-ebay-price-tax"' ) );
} )(), array( true, false, true ) );
check( '  a push on eBay UK, VAT 20%, prices entered without it: 20.00 goes as 24.00', price_sent(), array( '24.00' ) );
tax_shop( 'EBAY_US' );
check( '  on eBay US: 20.00, nothing added', price_sent(), array( '20.00' ) );
tax_shop( 'EBAY_GB' );
check( '  no notice: there is nothing to offer', array( TCGiant_Sync_Ebay_Price::tax_notice_due(), notice() ), array( false, '' ) );
update_option( 'tcgiant_sync_ebay_settings', array( 'access_token' => 't', 'refresh_token' => 'r' ) );
TCGiant_Sync_Ebay_Price::decide_tax_default();
check( '  decided once: connecting eBay afterwards changes nothing', get_option( 'tcgiant_sync_tax_default' ), array( 'default' => 'auto', 'since' => '3.31.1', 'notice' => 'none' ) );
tax_shop( 'EBAY_GB', array( 'ebay_price_tax' => 'no' ) );
check( '  the shop saves No: No, 20.00', array( TCGiant_Sync_Ebay_Price::tax_mode(), price_sent() ), array( 'no', array( '20.00' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA SHOP THAT RAN AN EARLIER VERSION: NO, AS IT PUSHED, AND AUTO OFFERED ONCE\n" . str_repeat( '=', 124 ) . "\n";

tax_shop( 'EBAY_GB' );
check( 'its settings saved before 3.31.1, the setting never among them: decided an existing shop - No, the notice due', first_request( array( 'access_token' => 't', 'refresh_token' => 'r', 'marketplace' => 'EBAY_GB' ) ), array( 'default' => 'no', 'since' => '3.31.1', 'notice' => 'pending' ) );
check( '  even a settings row with nothing in it is a shop that ran before', first_request( array() )['default'] ?? null, 'no' );
first_request( array( 'marketplace' => 'EBAY_GB' ) );
check( '  never saved reads No; the push as before 3.31.1: 20.00', array( TCGiant_Sync_Ebay_Price::tax_mode( array() ), price_sent() ), array( 'no', array( '20.00' ) ) );
check( '  the Settings screen shows No chosen', false !== strpos( printed( function () { TCGiant_Sync_Ebay_Price::render_settings_fields( array() ); } ), 'value="no"  checked="checked"' ), true );

$html = notice();
check( 'eBay UK, tax on, prices without it, on No: the notice, offering Auto', array( TCGiant_Sync_Ebay_Price::tax_notice_due(), false !== strpos( $html, 'id="tc-tax-notice"' ) ), array( true, true ) );
check( '  it says what Auto changes: the eBay prices rise by the VAT rate, 20%, at the next push', array(
	false !== strpos( $html, 'TCGiant Sync - should your eBay prices include VAT?' ),
	false !== strpos( $html, 'Buyers on eBay United Kingdom see prices with VAT in them.' ),
	false !== strpos( $html, 'your eBay prices rise by your VAT rate (20%, the standard rate at your shop&#039;s address; each product&#039;s own tax class decides its rate) at each listing&#039;s next Push, Update or relist.' ),
	false !== strpos( $html, 'A product with an eBay price of its own keeps it, a running auction keeps its opening bid' ),
), array( true, true, true, true ) );
check( '  Use Auto and Keep No, posted with a nonce to its own action; the setting linked', array(
	false !== strpos( $html, '<input type="hidden" name="action" value="tcgiant_tax_notice">' ),
	false !== strpos( $html, 'value="nonce:tcgiant_tax_notice"' ),
	false !== strpos( $html, 'name="tcgiant_tax_choice" value="auto">Use Auto</button>' ),
	false !== strpos( $html, 'name="tcgiant_tax_choice" value="no">Keep No</button>' ),
	false !== strpos( $html, 'page=tcgiant-settings#tc-ebay-price-tax' ),
), array( true, true, true, true, true ) );
$GLOBALS['TAX']['rates'][''] = array( 1 => array( 'rate' => '19.0000' ) );
check( '  Germany at 19%: 19%', false !== strpos( ( function () { tax_shop( 'EBAY_DE' ); return notice(); } )(), 'your eBay prices rise by your VAT rate (19%, ' ), true );
$GLOBALS['TAX']['rates'][''] = array( 1 => array( 'rate' => '10.0000' ) );
tax_shop( 'EBAY_AU' );
$html = notice();
check( '  Australia: GST, 10%', array( false !== strpos( $html, 'should your eBay prices include GST?' ), false !== strpos( $html, 'Buyers on eBay Australia see prices with GST in them.' ), false !== strpos( $html, 'rise by your GST rate (10%, ' ) ), array( true, true, true ) );
$GLOBALS['TAX']['rates'][''] = array();
check( '  no rate at the shop\'s address: said without a figure', false !== strpos( ( function () { tax_shop( 'EBAY_GB' ); return notice(); } )(), 'rise by your VAT rate (each product&#039;s, from its tax class, at your shop&#039;s address)' ), true );
$GLOBALS['TAX']['rates'][''] = array( 1 => array( 'rate' => '20.0000' ) );

$due = array();
foreach ( array( 'EBAY_GB', 'EBAY_DE', 'EBAY_FR', 'EBAY_IT', 'EBAY_ES', 'EBAY_AU', 'EBAY_US', 'EBAY_CA' ) as $site ) {
	tax_shop( $site );
	$due[ $site ] = TCGiant_Sync_Ebay_Price::tax_notice_due();
}
check( 'due on eBay UK, Germany, France, Italy, Spain and Australia; never on eBay US or Canada', $due, array( 'EBAY_GB' => true, 'EBAY_DE' => true, 'EBAY_FR' => true, 'EBAY_IT' => true, 'EBAY_ES' => true, 'EBAY_AU' => true, 'EBAY_US' => false, 'EBAY_CA' => false ) );
tax_shop( 'EBAY_US' );
check( '  and on eBay US it is not drawn', notice(), '' );
tax_shop( 'EBAY_GB' );
$GLOBALS['TAX']['enabled'] = false;
check( 'not where WooCommerce does not calculate tax', array( TCGiant_Sync_Ebay_Price::tax_notice_due(), notice() ), array( false, '' ) );
$GLOBALS['TAX']['enabled']   = true;
$GLOBALS['TAX']['inclusive'] = true;
check( '  nor where prices are entered with tax', array( TCGiant_Sync_Ebay_Price::tax_notice_due(), notice() ), array( false, '' ) );
$GLOBALS['TAX']['inclusive'] = false;
check( '  nor once Auto or Yes is saved; No saved by hand is still No', array( TCGiant_Sync_Ebay_Price::tax_notice_due( array( 'ebay_price_tax' => 'auto' ) ), TCGiant_Sync_Ebay_Price::tax_notice_due( array( 'ebay_price_tax' => 'yes' ) ), TCGiant_Sync_Ebay_Price::tax_notice_due( array( 'ebay_price_tax' => 'no' ) ) ), array( false, false, true ) );
$GLOBALS['W']['user_can'] = false;
check( '  not for someone who may not manage options', notice(), '' );
$GLOBALS['W']['user_can'] = true;
$shown = array();
foreach ( array( 'dashboard', 'edit-product', 'product', 'tcgiant-sync_page_tcgiant-settings', 'edit-post', 'plugins' ) as $screen ) {
	$GLOBALS['SCREEN'] = $screen;
	$shown[ $screen ]  = '' !== notice();
}
$GLOBALS['SCREEN'] = 'toplevel_page_tcgiant-sync';
check( '  on the dashboard and TCGiant Sync\'s screens, nowhere else - not the product screens (fix round 1)', $shown, array( 'dashboard' => true, 'edit-product' => false, 'product' => false, 'tcgiant-sync_page_tcgiant-settings' => true, 'edit-post' => false, 'plugins' => false ) );

echo "\n  answered\n";

tax_shop( 'EBAY_GB', array( 'access_token' => 't', 'refresh_token' => 'r' ) );
first_request( TCGiant_Sync_OAuth::$settings );
check( 'Use Auto: Auto saved - that key only, onto the settings as they are - and the notice put away for good', array( TCGiant_Sync_Ebay_Price::apply_tax_choice( 'auto' ), TCGiant_Sync_OAuth::$settings['ebay_price_tax'] ?? null, TCGiant_Sync_OAuth::$settings['access_token'] ?? null, get_option( 'tcgiant_sync_ebay_settings' )['ebay_price_tax'] ?? null, get_option( 'tcgiant_sync_tax_default' )['notice'] ?? null ), array( true, 'auto', 't', 'auto', 'auto' ) );
check( '  said in the log; nothing pushed', array( said( '"eBay Prices Include Tax" set to Auto from the notice' ), $GLOBALS['API']->calls ), array( true, array() ) );
check( '  the next push sends 24.00; the notice is gone', array( price_sent(), TCGiant_Sync_Ebay_Price::tax_notice_due(), notice() ), array( array( '24.00' ), false, '' ) );
TCGiant_Sync_Ebay_Price::apply_tax_choice( 'no' );
check( '  switched back to No later: not offered again', array( TCGiant_Sync_Ebay_Price::tax_mode(), TCGiant_Sync_Ebay_Price::tax_notice_due(), notice() ), array( 'no', false, '' ) );

tax_shop( 'EBAY_GB' );
first_request( TCGiant_Sync_OAuth::$settings );
check( 'Keep No: No saved as the shop\'s own, the notice put away for good, prices as before', array( TCGiant_Sync_Ebay_Price::apply_tax_choice( 'no' ), TCGiant_Sync_OAuth::$settings['ebay_price_tax'] ?? null, get_option( 'tcgiant_sync_tax_default' )['notice'] ?? null, TCGiant_Sync_Ebay_Price::tax_notice_due(), notice(), price_sent(), said( 'kept at No from the notice' ) ), array( true, 'no', 'kept', false, '', array( '20.00' ), true ) );

tax_shop( 'EBAY_GB' );
first_request( TCGiant_Sync_OAuth::$settings );
check( 'an answer that is neither: nothing saved, still due', array( TCGiant_Sync_Ebay_Price::apply_tax_choice( 'yes' ), TCGiant_Sync_Ebay_Price::apply_tax_choice( '' ), isset( TCGiant_Sync_OAuth::$settings['ebay_price_tax'] ), TCGiant_Sync_Ebay_Price::tax_notice_due() ), array( false, false, false, true ) );
tax_shop( 'EBAY_GB', array( 'ebay_price_tax' => 'yes' ) );
check( 'Yes chosen in Settings meanwhile: answered - the notice not drawn, and put away', array( notice(), get_option( 'tcgiant_sync_tax_default' )['notice'] ?? null ), array( '', 'chosen' ) );
tax_shop( 'EBAY_GB', array( 'ebay_price_tax' => 'no' ) );
check( '  then No again: not offered again', array( TCGiant_Sync_Ebay_Price::tax_notice_due(), notice() ), array( false, '' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHERE IT IS WIRED\n" . str_repeat( '=', 124 ) . "\n";

$core_src  = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync.php' );
$price_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-ebay-price.php' );
check( 'decided on init, early, on every request - before any admin screen, push or import reads it', substr_count( $core_src, "add_action( 'init', array( 'TCGiant_Sync_Ebay_Price', 'decide_tax_default' ), 1 );" ), 1 );
check( '  the notice and its answer hooked with the price fields', array( false !== strpos( $price_src, "add_action( 'admin_notices', array( __CLASS__, 'render_tax_notice' ) );" ), false !== strpos( $price_src, "add_action( 'admin_post_tcgiant_tax_notice', array( __CLASS__, 'handle_tax_notice' ) );" ) ), array( true, true ) );
$handler = substr( $price_src, (int) strpos( $price_src, 'public static function handle_tax_notice()' ), 900 );
$at = array( strpos( $handler, "check_admin_referer( 'tcgiant_tax_notice' );" ), strpos( $handler, "current_user_can( 'manage_options' )" ), strpos( $handler, 'self::apply_tax_choice(' ) );
check( '  the answer checks its nonce, then manage_options, before it saves', array( ! in_array( false, $at, true ), $at[0] < $at[1], $at[1] < $at[2] ), array( true, true, true ) );
check( '  its record deleted with the plugin\'s data', false !== strpos( (string) file_get_contents( $ROOT . '/uninstall.php' ), "'tcgiant_sync_tax_default'," ), true );

done();
