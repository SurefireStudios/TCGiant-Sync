<?php
/**
 * Harness: Verify shows eBay's fees in the currency of the shop's eBay site.
 *
 * Every fee Verify reported was printed with "$", whatever the site, so a
 * shop on eBay.co.uk or eBay.de read its fees as dollars. From 3.27.5 the
 * currency comes from the Primary eBay Marketplace, as the push's own
 * <Currency> does.
 *
 * Loaded for real: the Verify button's handler, TCGiant_Sync_Admin::
 * ajax_verify_product(), and TCGiant_Sync_API::get_marketplace_config(). The
 * exporter, eBay's answer and WordPress are played here.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-84s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 160 ) );
}

$ROOT = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

// --- WordPress ---------------------------------------------------------------

$SETTINGS = array();
$META     = array();

class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
function check_ajax_referer( $action ) { return true; }
function current_user_can( $capability ) { return true; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['META'][ $id ][ $key ] = $value; return true; }

/** wp_send_json_*() end the request: here they hand back what was sent. */
class Harness_Json_Answer extends Exception {
	public $success;
	public $data;
	public function __construct( $success, $data ) { parent::__construct( 'json' ); $this->success = $success; $this->data = $data; }
}
function wp_send_json_success( $data = null ) { throw new Harness_Json_Answer( true, $data ); }
function wp_send_json_error( $data = null ) { throw new Harness_Json_Answer( false, $data ); }

// --- WooCommerce and the plugin's other classes, as far as Verify goes ----------

class WC_Product {
	public function get_id() { return 7; }
}
function wc_get_product( $id ) { return new WC_Product(); }

class TCGiant_Sync_OAuth {
	public static function instance() { return new self(); }
	public function get_settings() { return $GLOBALS['SETTINGS']; }
}
/** A product that passes every check of its own. */
class TCGiant_Sync_Exporter {
	public static function instance() { return new self(); }
	public function get_export_settings( $product_id ) { return array( 'category_id' => '261328' ); }
	public function validate_export_settings( $settings ) { return true; }
	public function find_missing_required_aspects( $product, $settings ) { return array(); }
	public function build_item_xml_public( $product, $settings ) { return '<Title>1921 Morgan Dollar</Title>'; }
	public function description_too_long() { return null; }
	public function uses_fixed_price_calls( $product, $settings = array(), $ebay_item_id = '' ) { return false; }
}

require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/admin/class-tcgiant-sync-admin.php';

/** eBay's VerifyAddItem answer, as the API class hands it on: fee name => amount. */
class Harness_API extends TCGiant_Sync_API {
	public $config = null;
	public function verify_add_item( $item_xml ) { return array( 'fees' => array( 'InsertionFee' => 0.35, 'FeaturedFee' => 0.0, 'ProPackBundleFee' => 1234.5 ) ); }
	public function get_marketplace_config() { return null === $this->config ? parent::get_marketplace_config() : $this->config; }
}
$API  = new Harness_API();
$slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$slot->setAccessible( true );
}
$slot->setValue( null, $API );

/** Verify pressed on the product, on a shop whose Primary eBay Marketplace is $marketplace. */
function verify( $marketplace ) {
	$GLOBALS['SETTINGS'] = array( 'marketplace' => $marketplace );
	$_POST               = array( 'product_id' => '7' );
	try {
		( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor()->ajax_verify_product();
	} catch ( Harness_Json_Answer $answer ) {
		return $answer->success ? $answer->data['fees'] : 'refused: ' . ( $answer->data['message'] ?? '' );
	}
	return 'no answer';
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEACH SITE'S FEES IN ITS OWN CURRENCY\n" . str_repeat( '=', 124 ) . "\n";

check( 'eBay United States: dollars, as before', verify( 'EBAY_US' ), array( 'InsertionFee: $0.35', 'ProPackBundleFee: $1,234.50' ) );
check( 'eBay United Kingdom: pounds', verify( 'EBAY_GB' ), array( 'InsertionFee: £0.35', 'ProPackBundleFee: £1,234.50' ) );
check( 'eBay Germany: euros', verify( 'EBAY_DE' ), array( 'InsertionFee: €0.35', 'ProPackBundleFee: €1,234.50' ) );
check( 'eBay France, Italy and Spain: euros', array( verify( 'EBAY_FR' )[0], verify( 'EBAY_IT' )[0], verify( 'EBAY_ES' )[0] ), array( 'InsertionFee: €0.35', 'InsertionFee: €0.35', 'InsertionFee: €0.35' ) );
check( 'eBay Canada: Canadian dollars', verify( 'EBAY_CA' )[0], 'InsertionFee: C$0.35' );
check( 'eBay Australia: Australian dollars', verify( 'EBAY_AU' )[0], 'InsertionFee: A$0.35' );
check( 'a fee of nothing is still left out', count( verify( 'EBAY_GB' ) ), 2 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA CURRENCY IT CANNOT NAME IS NEVER GUESSED\n" . str_repeat( '=', 124 ) . "\n";

check( 'no marketplace saved: eBay United States, as the push itself assumes', verify( '' )[0], 'InsertionFee: $0.35' );
$API->config = array( 'currency' => 'CHF' );
check( 'a currency it has no sign for: the amount, then its code', verify( 'EBAY_US' )[0], 'InsertionFee: 0.35 CHF' );
$API->config = array();
check( 'no currency at all: the amount alone', verify( 'EBAY_US' )[0], 'InsertionFee: 0.35' );
$API->config = null;

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
