<?php
/**
 * Harness: an eBay order with empty fields, imported into WooCommerce.
 *
 * eBay sends a field it holds nothing for as an empty element: a buyer with no
 * second address line comes with <Street2></Street2>, one who gave no phone
 * number with <Phone/>. The API layer reads eBay's XML into an array by way of
 * JSON, and that turns an empty element into an empty ARRAY, not an empty
 * string. 3.26.1 copied those straight into the WooCommerce order, so a shop's
 * orders said "Array" where the second line of the address belonged and its
 * log filled with "Array to string conversion".
 *
 * An empty <Name/> was worse. On PHP 8, explode() on an array is a TypeError,
 * which is an Error and not an Exception, so the import's catch ( \Exception )
 * let it through. It killed the whole order import, and every order after that
 * one in eBay's answer was never imported - again every fifteen minutes, for as
 * long as the order stayed inside the 24 hours the import asks eBay for.
 *
 * Loaded for real: TCGiant_Sync_API, whose trading_api_request turns a canned
 * GetOrders answer into the array exactly as it does eBay's own, and
 * TCGiant_Sync_Orders - fetch_orders, parse_address, create_wc_order and
 * import_recent_orders - reached by reflection on an instance made without its
 * constructor. WordPress and WooCommerce are stubbed as far as the order import
 * uses them. Nothing is sent anywhere.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-76s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$ROOT = dirname( __DIR__ );

// --- the world the order import runs in --------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$OPTIONS   = array();
$LOG       = array();
$CALLS     = array(); // Trading API calls eBay was asked.
$ANSWER    = '';      // What eBay answers them with.
$WC_ORDERS = array(); // Every order wc_create_order() made, saved or not.
$WC_THROW  = null;    // An Error the next set_address() throws, once.
$WARNINGS  = array(); // PHP warnings and notices raised by the plugin.

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

// No eBay order imported yet, and no product linked to any listing.
class Harness_Wpdb {
	public $options  = 'wp_options';
	public $postmeta = 'wp_postmeta';
	public function prepare( $query ) { return $query; }
	public function query( $query ) { return 1; }
	public function get_var( $query ) { return null; }
}
$wpdb = new Harness_Wpdb();

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function wp_rand( $min = 0, $max = 0 ) { return $min; }
function add_action() {}
function get_option( $name, $default = false ) {
	global $OPTIONS;
	return array_key_exists( $name, $OPTIONS ) ? $OPTIONS[ $name ] : $default;
}
function add_option( $name, $value = '' ) {
	global $OPTIONS;
	$OPTIONS[ $name ] = $value;
	return true;
}
function update_option( $name, $value ) {
	global $OPTIONS;
	$OPTIONS[ $name ] = $value;
	return true;
}
function get_transient( $key ) { return false; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function is_email( $email ) { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false; }
function email_exists( $email ) { return false; }
function wc_get_product( $id ) { return false; }

// WordPress core, apart from its fallback for invalid UTF-8, which no answer
// here needs.
function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
}

// eBay, answering every Trading API call with $ANSWER.
function wp_remote_request( $url, $args ) {
	$GLOBALS['CALLS'][] = $args['headers']['X-EBAY-API-CALL-NAME'] ?? '?';
	return array( 'body' => $GLOBALS['ANSWER'], 'response' => array( 'code' => 200 ), 'headers' => array() );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $header ) { return ''; }

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
}

class TCGiant_Sync_OAuth {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_access_token() { return 'token'; }
	public function refresh_access_token() { return 'fresh-token'; }
	public function get_settings() { return array( 'marketplace' => 'EBAY_US' ); }
}

class TCGiant_Sync_Inventory {
	public static function mark_ebay_origin( $product_id ) {}
}

// WooCommerce, as far as create_wc_order() builds an order. The order keeps
// what it is given exactly as given, so an array handed to it stays one.
class Harness_WC_Order {
	public $id;
	public $address = array();
	public $email   = null;
	public $items   = array();
	public $total   = null;
	public $status  = 'pending';
	public $meta    = array();
	public $notes   = array();
	public $saved   = false;
	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function set_address( $address, $type = 'billing' ) {
		if ( $GLOBALS['WC_THROW'] ) {
			$error              = $GLOBALS['WC_THROW'];
			$GLOBALS['WC_THROW'] = null;
			throw $error;
		}
		$this->address[ $type ] = $address;
	}
	public function set_billing_email( $email ) { $this->email = $email; }
	public function set_customer_id( $id ) {}
	public function add_item( $item ) { $this->items[] = $item; }
	public function set_total( $total ) { $this->total = $total; }
	public function set_status( $status ) { $this->status = $status; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() { $this->saved = true; return $this->id; }
}
class Harness_WC_Item {
	public $props = array();
	public function __call( $name, $args ) { $this->props[ $name ] = $args[0] ?? null; }
}
class WC_Order_Item_Product extends Harness_WC_Item {}
class WC_Order_Item_Shipping extends Harness_WC_Item {}

// Like WooCommerce's, it makes the order before anything is put in it.
function wc_create_order() {
	$order                  = new Harness_WC_Order( 9001 + count( $GLOBALS['WC_ORDERS'] ) );
	$GLOBALS['WC_ORDERS'][] = $order;
	return $order;
}

require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-orders.php';

set_error_handler( function ( $no, $message ) {
	if ( $no & ( E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE ) ) {
		$GLOBALS['WARNINGS'][] = $message;
		return true;
	}
	return false;
} );

$ORDERS = ( new ReflectionClass( 'TCGiant_Sync_Orders' ) )->newInstanceWithoutConstructor();

/** Call one of TCGiant_Sync_Orders' private methods. */
function call_private( $method, ...$args ) {
	$m = new ReflectionMethod( 'TCGiant_Sync_Orders', $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invokeArgs( $GLOBALS['ORDERS'], $args );
}

/** Run something the way the cron would, and say what, if anything, escaped it. */
function attempt( callable $fn ) {
	try {
		return array( $fn(), 'nothing' );
	} catch ( \Throwable $e ) {
		return array( null, get_class( $e ) . ': ' . $e->getMessage() );
	}
}

/** The keys of an address whose values are not strings. */
function non_strings( $address ) {
	if ( ! is_array( $address ) ) {
		return 'no address';
	}
	$bad = array();
	foreach ( $address as $key => $value ) {
		if ( ! is_string( $value ) ) {
			$bad[] = $key;
		}
	}
	return $bad;
}

/** Some fields of an address, or null when there is none. */
function pick( $address, $keys ) {
	if ( ! is_array( $address ) ) {
		return null;
	}
	$out = array();
	foreach ( $keys as $key ) {
		$out[ $key ] = $address[ $key ] ?? 'MISSING';
	}
	return $out;
}

/** eBay order numbers of the orders that were saved, in order. */
function saved_orders() {
	$ids = array();
	foreach ( $GLOBALS['WC_ORDERS'] as $order ) {
		if ( $order->saved ) {
			$ids[] = $order->meta['_ebay_order_id'] ?? '?';
		}
	}
	return $ids;
}

function half_built_orders() {
	return count( $GLOBALS['WC_ORDERS'] ) - count( saved_orders() );
}

function logged( $text ) {
	foreach ( $GLOBALS['LOG'] as $entry ) {
		if ( false !== strpos( $entry[1], $text ) ) {
			return true;
		}
	}
	return false;
}

function fresh_run() {
	$GLOBALS['OPTIONS']   = array( 'tcgiant_sync_ebay_settings' => array( 'enable_order_import' => 1 ) );
	$GLOBALS['LOG']       = array();
	$GLOBALS['CALLS']     = array();
	$GLOBALS['WC_ORDERS'] = array();
	$GLOBALS['WC_THROW']  = null;
}

// --- what eBay sends ---------------------------------------------------------
//
// Four orders, as GetOrders returns them:
//   A  the fragment the shops sent in: no name, no second line, no phone and
//      an empty email, with everything else filled in;
//   B  the everyday case: a named buyer with no second line and no phone;
//   C  every field filled;
//   D  an order with no address at all.
$ANSWER = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<GetOrdersResponse xmlns="urn:ebay:apis:eBLBaseComponents">
  <Timestamp>2026-10-12T10:00:00.000Z</Timestamp>
  <Ack>Success</Ack>
  <Version>1323</Version>
  <PaginationResult>
    <TotalNumberOfPages>1</TotalNumberOfPages>
    <TotalNumberOfEntries>4</TotalNumberOfEntries>
  </PaginationResult>
  <HasMoreOrders>false</HasMoreOrders>
  <OrderArray>
    <Order>
      <OrderID>11-00001-00001</OrderID>
      <OrderStatus>Completed</OrderStatus>
      <Total currencyID="USD">12.50</Total>
      <BuyerUserID>buyer_a</BuyerUserID>
      <ShippingAddress>
        <Name/>
        <Street1>1 Main St</Street1>
        <Street2></Street2>
        <CityName>Springfield</CityName>
        <StateOrProvince>IL</StateOrProvince>
        <Country>US</Country>
        <CountryName>United States</CountryName>
        <Phone/>
        <PostalCode>62701</PostalCode>
      </ShippingAddress>
      <ShippingServiceSelected>
        <ShippingService>USPSGroundAdvantage</ShippingService>
        <ShippingServiceCost currencyID="USD">0.0</ShippingServiceCost>
      </ShippingServiceSelected>
      <TransactionArray>
        <Transaction>
          <Buyer>
            <Email/>
          </Buyer>
          <Item>
            <ItemID>110000000001</ItemID>
            <Title>Charizard Holo 4/102</Title>
          </Item>
          <QuantityPurchased>1</QuantityPurchased>
          <TransactionID>0</TransactionID>
          <TransactionPrice currencyID="USD">12.50</TransactionPrice>
        </Transaction>
      </TransactionArray>
    </Order>
    <Order>
      <OrderID>11-00002-00002</OrderID>
      <OrderStatus>Completed</OrderStatus>
      <Total currencyID="USD">8.99</Total>
      <BuyerUserID>buyer_b</BuyerUserID>
      <ShippingAddress>
        <Name>Zoë Brontë</Name>
        <Street1>4 Rue de la Paix</Street1>
        <Street2/>
        <CityName>Leeds</CityName>
        <StateOrProvince>West Yorkshire</StateOrProvince>
        <Country>GB</Country>
        <CountryName>United Kingdom</CountryName>
        <Phone></Phone>
        <PostalCode>LS1 4AP</PostalCode>
      </ShippingAddress>
      <TransactionArray>
        <Transaction>
          <Buyer>
            <Email>zoe@example.co.uk</Email>
          </Buyer>
          <Item>
            <ItemID>110000000002</ItemID>
            <Title>Blastoise Holo 2/102</Title>
          </Item>
          <QuantityPurchased>1</QuantityPurchased>
          <TransactionID>0</TransactionID>
          <TransactionPrice currencyID="USD">8.99</TransactionPrice>
        </Transaction>
      </TransactionArray>
    </Order>
    <Order>
      <OrderID>11-00003-00003</OrderID>
      <OrderStatus>Completed</OrderStatus>
      <Total currencyID="USD">57.25</Total>
      <BuyerUserID>buyer_c</BuyerUserID>
      <ShippingAddress>
        <Name>Mary Ann Smith</Name>
        <Street1>12 High St</Street1>
        <Street2>Flat 3</Street2>
        <CityName>Boston</CityName>
        <StateOrProvince>MA</StateOrProvince>
        <Country>US</Country>
        <CountryName>United States</CountryName>
        <Phone>+1 617 555 0100</Phone>
        <PostalCode>02134</PostalCode>
      </ShippingAddress>
      <ShippingServiceSelected>
        <ShippingService>USPSPriority</ShippingService>
        <ShippingServiceCost currencyID="USD">4.25</ShippingServiceCost>
      </ShippingServiceSelected>
      <TransactionArray>
        <Transaction>
          <Buyer>
            <Email>mary@example.com</Email>
          </Buyer>
          <Item>
            <ItemID>110000000003</ItemID>
            <Title>Venusaur Holo 15/102</Title>
          </Item>
          <QuantityPurchased>1</QuantityPurchased>
          <TransactionID>0</TransactionID>
          <TransactionPrice currencyID="USD">53.00</TransactionPrice>
        </Transaction>
      </TransactionArray>
    </Order>
    <Order>
      <OrderID>11-00004-00004</OrderID>
      <OrderStatus>Completed</OrderStatus>
      <Total currencyID="USD">3.00</Total>
      <BuyerUserID>buyer_d</BuyerUserID>
      <ShippingAddress/>
      <TransactionArray>
        <Transaction>
          <Item>
            <ItemID>110000000004</ItemID>
            <Title>Energy card lot</Title>
          </Item>
          <QuantityPurchased>1</QuantityPurchased>
          <TransactionID>0</TransactionID>
          <TransactionPrice currencyID="USD">3.00</TransactionPrice>
        </Transaction>
      </TransactionArray>
    </Order>
  </OrderArray>
</GetOrdersResponse>
XML;

echo "\nWHAT eBay SENDS, AS THE PLUGIN READS IT\n" . str_repeat( '=', 124 ) . "\n";

fresh_run();
list( $orders, $threw ) = attempt( function () { return call_private( 'fetch_orders' ); } );
check( 'GetOrders is read through the real API layer', $CALLS, array( 'GetOrders' ) );
check( '  all four orders come out of it', is_array( $orders ) ? count( $orders ) : $threw, 4 );

$A = $orders[0] ?? array();
$B = $orders[1] ?? array();
$C = $orders[2] ?? array();
$D = $orders[3] ?? array();

// The premise: what the conversion makes of an empty element. If this ever
// changes the rest of this file is testing nothing.
check( 'an empty <Street2></Street2> arrives as an empty array, not a string', $A['ShippingAddress']['Street2'] ?? 'MISSING', array() );
check( '  as does <Phone/>', $A['ShippingAddress']['Phone'] ?? 'MISSING', array() );
check( '  and <Name/>', $A['ShippingAddress']['Name'] ?? 'MISSING', array() );
check( '  and an empty <Email/>', $A['TransactionArray']['Transaction']['Buyer']['Email'] ?? 'MISSING', array() );
check( '  while a filled one is a string', $C['ShippingAddress']['PostalCode'] ?? 'MISSING', '02134' );

echo "\nTHE ADDRESS\n" . str_repeat( '=', 124 ) . "\n";

// A: the fragment as sent in.
list( $address, $threw ) = attempt( function () use ( $A ) { return call_private( 'parse_address', $A ); } );
check( 'an empty <Name/> does not throw', $threw, 'nothing' );
check( '  every field it gives WooCommerce is a string', non_strings( $address ), array() );
check( '  the empty ones are empty strings', pick( $address, array( 'first_name', 'last_name', 'address_2', 'phone' ) ), array(
	'first_name' => '',
	'last_name'  => '',
	'address_2'  => '',
	'phone'      => '',
) );
check( '  and what eBay did send is kept', pick( $address, array( 'address_1', 'city', 'state', 'postcode', 'country' ) ), array(
	'address_1' => '1 Main St',
	'city'      => 'Springfield',
	'state'     => 'IL',
	'postcode'  => '62701',
	'country'   => 'US',
) );

// B: a named buyer with no second line and no phone - most of a shop's orders.
list( $address, $threw ) = attempt( function () use ( $B ) { return call_private( 'parse_address', $B ); } );
check( 'a buyer with no second line and no phone does not throw', $threw, 'nothing' );
check( '  every field is a string', non_strings( $address ), array() );
check( '  the second line and phone are empty, not "Array"', pick( $address, array( 'address_2', 'phone' ) ), array( 'address_2' => '', 'phone' => '' ) );
check( '  and the name splits, accents and all', pick( $address, array( 'first_name', 'last_name' ) ), array( 'first_name' => 'Zoë', 'last_name' => 'Brontë' ) );

// C: everything filled.
list( $address, $threw ) = attempt( function () use ( $C ) { return call_private( 'parse_address', $C ); } );
check( 'a full address maps field for field', $address, array(
	'first_name' => 'Mary',
	'last_name'  => 'Ann Smith',
	'address_1'  => '12 High St',
	'address_2'  => 'Flat 3',
	'city'       => 'Boston',
	'state'      => 'MA',
	'postcode'   => '02134',
	'country'    => 'US',
	'phone'      => '+1 617 555 0100',
) );

// D: no address at all.
list( $address, $threw ) = attempt( function () use ( $D ) { return call_private( 'parse_address', $D ); } );
check( 'an order with an empty <ShippingAddress/> gives nine empty strings', $address, array_fill_keys(
	array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ),
	''
) );

echo "\nTHE ORDER\n" . str_repeat( '=', 124 ) . "\n";

fresh_run();
list( $order, $threw ) = attempt( function () use ( $A ) { return call_private( 'create_wc_order', $A ); } );
$order = $order instanceof Harness_WC_Order ? $order : null;
check( 'the order with empty fields is built without anything escaping', $threw, 'nothing' );
check( '  and saved', $order ? $order->saved : false, true );
check( '  its shipping address holds strings only', non_strings( $order ? ( $order->address['shipping'] ?? null ) : null ), array() );
check( '  and so does its billing address', non_strings( $order ? ( $order->address['billing'] ?? null ) : null ), array() );
check( '  an empty <Email/> sets no billing email', $order ? $order->email : 'no order', null );
check( '  it carries eBay\'s order number', $order ? ( $order->meta['_ebay_order_id'] ?? null ) : null, '11-00001-00001' );

// B never threw: it was the order that said "Array" where its second line
// belonged.
fresh_run();
list( $order, $threw ) = attempt( function () use ( $B ) { return call_private( 'create_wc_order', $B ); } );
$order = $order instanceof Harness_WC_Order ? $order : null;
check( 'the order with no second line and no phone is built', $threw, 'nothing' );
check( '  with no array anywhere in its shipping address', non_strings( $order ? ( $order->address['shipping'] ?? null ) : null ), array() );
check( '  its second line and phone left empty', pick( $order ? ( $order->address['shipping'] ?? null ) : null, array( 'address_2', 'phone' ) ), array( 'address_2' => '', 'phone' => '' ) );
check( '  and the buyer\'s email set', $order ? $order->email : null, 'zoe@example.co.uk' );

fresh_run();
list( $order, $threw ) = attempt( function () use ( $C ) { return call_private( 'create_wc_order', $C ); } );
$order = $order instanceof Harness_WC_Order ? $order : null;
check( 'the full order is built', $threw, 'nothing' );
check( '  with its address as eBay gave it', $order ? ( $order->address['shipping']['address_2'] ?? null ) : null, 'Flat 3' );
check( '  the buyer\'s email', $order ? $order->email : null, 'mary@example.com' );
check( '  and its total', $order ? $order->total : null, '57.25' );

echo "\nAN IMPORT RUN\n" . str_repeat( '=', 124 ) . "\n";

// What the fifteen-minute cron does: all four orders, the empty one first.
fresh_run();
list( , $threw ) = attempt( function () use ( $ORDERS ) { $ORDERS->import_recent_orders(); } );
check( 'a run over all four orders is not killed by the empty one', $threw, 'nothing' );
check( '  every order is imported', saved_orders(), array( '11-00001-00001', '11-00002-00002', '11-00003-00003', '11-00004-00004' ) );
check( '  none is left half built', half_built_orders(), 0 );
check( '  the run says so', logged( 'Order Import: Complete. 4 created, 0 skipped' ), true );
check( '  and records that it ran', isset( $OPTIONS['tcgiant_sync_ebay_settings']['last_order_import'] ), true );
check( '  no order was given an array for a field', array_values( array_filter( array_map( function ( $o ) {
	return array_merge( non_strings( $o->address['shipping'] ?? array() ), non_strings( $o->address['billing'] ?? array() ) );
}, $WC_ORDERS ) ) ), array() );

echo "\nAN ERROR WHILE AN ORDER IS BUILT\n" . str_repeat( '=', 124 ) . "\n";

// In the source: the catch around building an order. A TypeError or any other
// Error is not an Exception, so catch ( \Exception ) let one end the run.
$method = new ReflectionMethod( 'TCGiant_Sync_Orders', 'create_wc_order' );
$lines  = file( $method->getFileName() );
$body   = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
$tokens = token_get_all( '<?php ' . $body );
$caught = array();
for ( $i = 0, $n = count( $tokens ); $i < $n; $i++ ) {
	if ( ! is_array( $tokens[ $i ] ) || T_CATCH !== $tokens[ $i ][0] ) {
		continue;
	}
	$name = '';
	for ( $j = $i + 1; $j < $n && ')' !== $tokens[ $j ]; $j++ ) {
		if ( is_array( $tokens[ $j ] ) && T_VARIABLE === $tokens[ $j ][0] ) {
			break;
		}
		if ( is_array( $tokens[ $j ] ) && T_WHITESPACE !== $tokens[ $j ][0] ) {
			$name .= $tokens[ $j ][1];
		} elseif ( '|' === $tokens[ $j ] ) {
			$name .= '|';
		}
	}
	$caught[] = $name;
}
check( 'create_wc_order catches \Throwable, not only \Exception', in_array( '\Throwable', $caught, true ) ? '\Throwable' : implode( ' ', $caught ), '\Throwable' );

// And in what it does.
fresh_run();
$WC_THROW = new TypeError( 'set_address(): Argument #1 ($address) must be of type array' );
list( $result, $threw ) = attempt( function () use ( $C ) { return call_private( 'create_wc_order', $C ); } );
check( 'an Error while an order is built does not escape it', $threw, 'nothing' );
check( '  the order is reported as not created', $result, false );
check( '  and why is logged', logged( 'Order Import: Exception — set_address()' ), true );

// A run without order A, so that the only thing to go wrong is the Error:
// the first order, B, fails while it is built, and C and D come after it.
fresh_run();
$ANSWER   = preg_replace( '#<Order>.*?</Order>#s', '', $ANSWER, 1 );
$WC_THROW = new Error( 'Call to a member function get_id() on null' );
list( , $threw ) = attempt( function () use ( $ORDERS ) { $ORDERS->import_recent_orders(); } );
check( 'an import run whose first order fails is not killed', $threw, 'nothing' );
check( '  the orders after it are imported', saved_orders(), array( '11-00003-00003', '11-00004-00004' ) );
check( '  and the failure is logged, not fatal', logged( 'Order Import: Exception — Call to a member function' ), true );

echo "\nORDERS FOR LISTINGS ON OTHER EBAY SITES (3.27.5)\n" . str_repeat( '=', 124 ) . "\n";

// Rabbi Ent lists on eBay.com and has every listing copied onto eBay.co.uk,
// eBay.de and the rest. With "Import listings from" set to eBay United States
// only, an order for copies alone is left out: it would arrive in pounds or
// euros as though they were the shop's own currency. Each transaction's
// Item.Site - where the listing is - decides; TransactionSiteID, where the
// buyer was, does not.
//
//   E  two eBay.co.uk copies, nothing else            left out
//   F  an eBay.com listing and an eBay.co.uk copy     imported whole
//   G  an eBay.com listing bought from eBay.co.uk     imported
//   H  a line eBay gave no site for                   imported
//   I  a line with no <Item> at all, and an empty <Item/>, beside an eBay.de copy
//                                                     imported, and nothing breaks
//   J  an eBay Motors listing, part of eBay.com       imported
$SITES_ANSWER = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<GetOrdersResponse xmlns="urn:ebay:apis:eBLBaseComponents">
  <Ack>Success</Ack>
  <PaginationResult><TotalNumberOfPages>1</TotalNumberOfPages></PaginationResult>
  <OrderArray>
    <Order>
      <OrderID>21-00005-00005</OrderID><OrderStatus>Completed</OrderStatus><Total currencyID="GBP">20.00</Total><BuyerUserID>buyer_e</BuyerUserID>
      <TransactionArray>
        <Transaction><Item><ItemID>310000000001</ItemID><Site>UK</Site><Title>Robot arm (UK copy)</Title></Item><QuantityPurchased>1</QuantityPurchased><TransactionID>1</TransactionID><TransactionSiteID>UK</TransactionSiteID><TransactionPrice currencyID="GBP">10.00</TransactionPrice></Transaction>
        <Transaction><Item><ItemID>310000000002</ItemID><Site>UK</Site><Title>Gripper (UK copy)</Title></Item><QuantityPurchased>1</QuantityPurchased><TransactionID>2</TransactionID><TransactionSiteID>UK</TransactionSiteID><TransactionPrice currencyID="GBP">10.00</TransactionPrice></Transaction>
      </TransactionArray>
    </Order>
    <Order>
      <OrderID>21-00006-00006</OrderID><OrderStatus>Completed</OrderStatus><Total currencyID="USD">30.00</Total><BuyerUserID>buyer_f</BuyerUserID>
      <TransactionArray>
        <Transaction><Item><ItemID>320000000001</ItemID><Site>US</Site><Title>Robot arm</Title></Item><QuantityPurchased>1</QuantityPurchased><TransactionID>3</TransactionID><TransactionPrice currencyID="USD">15.00</TransactionPrice></Transaction>
        <Transaction><Item><ItemID>310000000003</ItemID><Site>UK</Site><Title>Gripper (UK copy)</Title></Item><QuantityPurchased>1</QuantityPurchased><TransactionID>4</TransactionID><TransactionPrice currencyID="USD">15.00</TransactionPrice></Transaction>
      </TransactionArray>
    </Order>
    <Order>
      <OrderID>21-00007-00007</OrderID><OrderStatus>Completed</OrderStatus><Total currencyID="USD">15.00</Total><BuyerUserID>buyer_g</BuyerUserID>
      <TransactionArray>
        <Transaction><Item><ItemID>320000000002</ItemID><Site>US</Site><Title>Servo</Title></Item><QuantityPurchased>1</QuantityPurchased><TransactionID>5</TransactionID><TransactionSiteID>UK</TransactionSiteID><TransactionPrice currencyID="USD">15.00</TransactionPrice></Transaction>
      </TransactionArray>
    </Order>
    <Order>
      <OrderID>21-00008-00008</OrderID><OrderStatus>Completed</OrderStatus><Total currencyID="USD">5.00</Total><BuyerUserID>buyer_h</BuyerUserID>
      <TransactionArray>
        <Transaction><Item><ItemID>320000000003</ItemID><Title>Cable</Title></Item><QuantityPurchased>1</QuantityPurchased><TransactionID>6</TransactionID><TransactionPrice currencyID="USD">5.00</TransactionPrice></Transaction>
      </TransactionArray>
    </Order>
    <Order>
      <OrderID>21-00009-00009</OrderID><OrderStatus>Completed</OrderStatus><Total currencyID="EUR">9.00</Total><BuyerUserID>buyer_i</BuyerUserID>
      <TransactionArray>
        <Transaction><QuantityPurchased>1</QuantityPurchased><TransactionID>10</TransactionID><TransactionPrice currencyID="EUR">0.00</TransactionPrice></Transaction>
        <Transaction><Item/><QuantityPurchased>1</QuantityPurchased><TransactionID>7</TransactionID><TransactionPrice currencyID="EUR">4.00</TransactionPrice></Transaction>
        <Transaction><Item><ItemID>330000000001</ItemID><Site>Germany</Site><Title>Motor (DE copy)</Title></Item><QuantityPurchased>1</QuantityPurchased><TransactionID>8</TransactionID><TransactionPrice currencyID="EUR">5.00</TransactionPrice></Transaction>
      </TransactionArray>
    </Order>
    <Order>
      <OrderID>21-00010-00010</OrderID><OrderStatus>Completed</OrderStatus><Total currencyID="USD">40.00</Total><BuyerUserID>buyer_j</BuyerUserID>
      <TransactionArray>
        <Transaction><Item><ItemID>340000000001</ItemID><Site>eBayMotors</Site><Title>Wiper motor</Title></Item><QuantityPurchased>1</QuantityPurchased><TransactionID>9</TransactionID><TransactionPrice currencyID="USD">40.00</TransactionPrice></Transaction>
      </TransactionArray>
    </Order>
  </OrderArray>
</GetOrdersResponse>
XML;

$ANSWER = $SITES_ANSWER;
fresh_run();
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'enable_order_import' => 1, 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' );
list( , $threw ) = attempt( function () use ( $ORDERS ) { $ORDERS->import_recent_orders(); } );
check( 'eBay United States only: the run completes', $threw, 'nothing' );
check( '  the order for two eBay.co.uk copies alone is left out', in_array( '21-00005-00005', saved_orders(), true ), false );
check( '  not even half built', half_built_orders(), 0 );
check( '  every other order is imported', saved_orders(), array( '21-00006-00006', '21-00007-00007', '21-00008-00008', '21-00009-00009', '21-00010-00010' ) );
$mixed = null;
foreach ( $WC_ORDERS as $o ) {
	if ( '21-00006-00006' === ( $o->meta['_ebay_order_id'] ?? '' ) ) {
		$mixed = $o;
	}
}
check( '  the mixed order whole, both its lines', $mixed ? count( $mixed->items ) >= 2 : false, true );
check( '  the summary counts the one left out', logged( 'Order Import: Complete. 5 created, 0 skipped (already imported), 1 left out (listed on other eBay sites).' ), true );

foreach ( array( 'never chosen' => array( 'enable_order_import' => 1, 'marketplace' => 'EBAY_US' ), 'All eBay sites' => array( 'enable_order_import' => 1, 'marketplace' => 'EBAY_US', 'import_sites' => 'all' ) ) as $how => $settings ) {
	fresh_run();
	$OPTIONS['tcgiant_sync_ebay_settings'] = $settings;
	attempt( function () use ( $ORDERS ) { $ORDERS->import_recent_orders(); } );
	check( sprintf( 'import listings from: %s - all six orders are imported, as in 3.27.4', $how ), count( saved_orders() ), 6 );
	check( '  and nothing is said about other sites', logged( 'left out' ), false );
}

restore_error_handler();
check( 'nothing above raised a PHP warning or notice', $WARNINGS, array() );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
