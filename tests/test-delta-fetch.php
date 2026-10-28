<?php
/**
 * Harness: reading eBay's GetSellerEvents answer without running out of memory.
 *
 * A shop on WordPress.com wrote in that its newest listings were never being
 * imported. The delta sync action "repeatedly starts but is marked failed
 * after several minutes", and the log said:
 *
 *   PHP Fatal error: Allowed memory size of 536870912 bytes exhausted
 *
 * GetSellerEvents has no pagination. It returns every listing changed in the
 * period asked for, in full, in one answer - and the answer was read through
 * four forms at once (raw text, XML tree, JSON string, array) plus a second
 * copy of the raw text inside the HTTP response, none of them let go until the
 * function returned. Nothing limited how large that answer could be.
 *
 * This drives the real TCGiant_Sync_API with WordPress stubbed out and eBay
 * played by a canned response.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-70s got %-16s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

// --- the world the API class runs in -----------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'GB_IN_BYTES', 1073741824 );

$OPTIONS  = array();
$REQUESTS = array();
$BODIES   = array();
$LOG      = array();

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

class Harness_Wpdb {
	public $options = 'wp_options';
	public function prepare( $query ) { return $query; }
	public function query( $query ) { return 1; }
	// The day's call count, read from its row since 3.28.0: none counted here.
	public function get_var( $query ) { return null; }
}
$wpdb = new Harness_Wpdb();

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function wp_rand( $min = 0, $max = 0 ) { return $min; }
function get_option( $name, $default = false ) {
	global $OPTIONS;
	return array_key_exists( $name, $OPTIONS ) ? $OPTIONS[ $name ] : $default;
}
function add_option( $name, $value = '' ) {
	global $OPTIONS;
	$OPTIONS[ $name ] = $value;
	return true;
}
function update_option( $name, $value, $autoload = null ) {
	global $OPTIONS;
	$OPTIONS[ $name ] = $value;
	return true;
}
function get_transient( $key ) { return false; }
function wp_cache_delete( $key, $group = '' ) { return true; }

// WordPress core, verbatim apart from str_contains (PHP 8) as strpos.
function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
}
function wp_convert_hr_to_bytes( $value ) {
	$value = strtolower( trim( $value ) );
	$bytes = (int) $value;
	if ( false !== strpos( $value, 'g' ) ) {
		$bytes *= GB_IN_BYTES;
	} elseif ( false !== strpos( $value, 'm' ) ) {
		$bytes *= MB_IN_BYTES;
	} elseif ( false !== strpos( $value, 'k' ) ) {
		$bytes *= KB_IN_BYTES;
	}
	return min( $bytes, PHP_INT_MAX );
}

// eBay. Each request takes the next canned body and, like WordPress's HTTP
// layer with limit_response_size set, keeps only that many bytes of it.
function wp_remote_request( $url, $args ) {
	global $REQUESTS, $BODIES;
	$REQUESTS[] = $args;
	$body = (string) array_shift( $BODIES );
	if ( ! empty( $args['limit_response_size'] ) ) {
		$body = substr( $body, 0, (int) $args['limit_response_size'] );
	}
	return array( 'body' => $body, 'response' => array( 'code' => 200 ), 'headers' => array() );
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
	public function get_settings() { return array( 'marketplace' => 'EBAY_DE' ); }
}

// The live shop. Since 3.28.0 the API class asks TCGiant_Sync_Environment
// before anything that changes eBay. GetSellerEvents only reads, so it is
// never asked here, but the class is loaded as it is on a real site.
function wp_load_alloptions() { return array( 'home' => 'https://numismax.com', 'siteurl' => 'https://numismax.com' ); }
function wp_get_environment_type() { return 'production'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-environment.php';
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-api.php';

$api = TCGiant_Sync_API::instance();

/** An answer as eBay sends it, holding the given <Item> elements. */
function events_answer( $items_xml ) {
	return '<?xml version="1.0" encoding="UTF-8"?><GetSellerEventsResponse xmlns="urn:ebay:apis:eBLBaseComponents">'
		. '<Timestamp>2026-10-09T10:00:00.000Z</Timestamp><Ack>Success</Ack><Version>1323</Version>'
		. '<TimeTo>2026-10-09T10:00:00.000Z</TimeTo><ItemArray>' . $items_xml . '</ItemArray></GetSellerEventsResponse>';
}

/** One ordinary listing of roughly the given size. */
function plain_item( $i, $desc_bytes, $specifics = 15 ) {
	$s = '';
	for ( $k = 0; $k < $specifics; $k++ ) {
		$s .= '<NameValueList><Name>Spec ' . $k . '</Name><Value>Value ' . $k . '</Value></NameValueList>';
	}
	return '<Item><ItemID>' . ( 110000000000 + $i ) . '</ItemID><Title>Card ' . $i . '</Title>'
		. '<Description>' . str_repeat( 'Near mint, sleeved. ', (int) ceil( $desc_bytes / 20 ) ) . '</Description>'
		. '<SellingStatus><ListingStatus>Active</ListingStatus><CurrentPrice currencyID="USD">12.50</CurrentPrice></SellingStatus>'
		. '<PictureDetails><PictureURL>https://i.ebayimg.com/images/g/' . $i . '/s-l1600.jpg</PictureURL></PictureDetails>'
		. '<ItemSpecifics>' . $s . '</ItemSpecifics></Item>';
}

/** What the old code did with an answer, verbatim. */
function old_parse( $body ) {
	$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA );
	return json_decode( wp_json_encode( $xml ), true );
}

echo "\nTHE CALL ASKS FOR ENGLISH ERRORS\n" . str_repeat( '=', 116 ) . "\n";

// The importer recognises eBay's "too many results" refusal by its wording,
// and eBay answers in the language of the seller's site. This harness runs as
// an eBay.de seller.
$BODIES   = array( events_answer( plain_item( 1, 100 ) ) );
$REQUESTS = array();
$api->get_seller_events( '2026-10-08T10:00:00.000Z', '2026-10-09T10:00:00.000Z' );
check( 'GetSellerEvents is sent ErrorLanguage en_US', false !== strpos( $REQUESTS[0]['body'], '<ErrorLanguage>en_US</ErrorLanguage>' ), true );
check( '  inside the request element, not before it', 1 === preg_match( '#<GetSellerEventsRequest[^>]*>\s*<ErrorLanguage>en_US</ErrorLanguage>#', $REQUESTS[0]['body'] ), true );
check( '  and to the German site, so the setting is what decides', $REQUESTS[0]['headers']['X-EBAY-API-SITEID'], '77' );
check( 'with no ceiling asked for, none is applied', array_key_exists( 'limit_response_size', $REQUESTS[0] ), false );

echo "\nAN ANSWER OVER THE CEILING IS REFUSED BEFORE IT IS READ\n" . str_repeat( '=', 116 ) . "\n";

$big = '';
for ( $i = 0; $i < 400; $i++ ) {
	$big .= plain_item( $i, 2000 );
}
$big = events_answer( $big );
$cap = 200000;

$BODIES   = array( $big );
$REQUESTS = array();
$LOG      = array();
$result   = $api->get_seller_events( '2026-10-08T10:00:00.000Z', '2026-10-09T10:00:00.000Z', $cap );

check( 'the answer used for this is genuinely over the ceiling', strlen( $big ) > $cap, true );
check( 'it comes back as response_too_large', is_wp_error( $result ) ? $result->get_error_code() : 'array', 'response_too_large' );
check( '  saying how large the ceiling was', is_wp_error( $result ) ? $result->get_error_data()['limit'] : null, $cap );
// The HTTP layer is told to stop keeping the body one byte past the ceiling,
// so even a very large answer is never held in full.
check( 'WordPress is told to keep no more than one byte past it', $REQUESTS[0]['limit_response_size'] ?? null, $cap + 1 );
check( '  and that is all that was kept', $api->get_last_body_bytes(), $cap + 1 );
// A cut-off body is not XML. Trying to parse it would have reported "Invalid
// XML" - the general error branch, which retries the same window for ever.
$parsed_anyway = false;
foreach ( $LOG as $entry ) {
	if ( false !== strpos( $entry[1], 'Invalid XML' ) ) {
		$parsed_anyway = true;
	}
}
check( '  and no attempt was made to read it', $parsed_anyway, false );

$BODIES = array( $big );
$result = $api->get_seller_events( '2026-10-08T10:00:00.000Z', '2026-10-09T10:00:00.000Z', strlen( $big ) );
check( 'an answer exactly at the ceiling is read', is_array( $result ) && isset( $result['ItemArray']['Item'][399] ), true );
check( '  and its size is reported for the window to be judged by', $api->get_last_body_bytes(), strlen( $big ) );

$BODIES = array( $big );
$result = $api->get_seller_events( '2026-10-08T10:00:00.000Z', '2026-10-09T10:00:00.000Z', 0 );
check( 'with no ceiling, the same answer is read in full', is_array( $result ) && 400 === count( $result['ItemArray']['Item'] ), true );

// The token-refresh retry is a second request, and must carry the ceiling.
$expired = '<?xml version="1.0" encoding="UTF-8"?><GetSellerEventsResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Failure</Ack>'
	. '<Errors><ShortMessage>Expired</ShortMessage><LongMessage>IAF token supplied is expired.</LongMessage><ErrorCode>21917053</ErrorCode></Errors></GetSellerEventsResponse>';
$BODIES   = array( $expired, $big );
$REQUESTS = array();
$result   = $api->get_seller_events( '2026-10-08T10:00:00.000Z', '2026-10-09T10:00:00.000Z', $cap );
check( 'after a token refresh the retry is held to the same ceiling', is_wp_error( $result ) ? $result->get_error_code() : 'array', 'response_too_large' );
check( '  on the second request as well as the first', $REQUESTS[1]['limit_response_size'] ?? null, $cap + 1 );

// Nothing else is given a ceiling. GetSellerList pages are fixed at 200 and
// have no narrowing to fall back on, so refusing one would stop a full sync.
$BODIES   = array( '<?xml version="1.0"?><GetItemResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack>' . plain_item( 7, 100 ) . '</GetItemResponse>' );
$REQUESTS = array();
$api->get_item( '110000000007' );
check( 'GetItem has no ceiling', array_key_exists( 'limit_response_size', $REQUESTS[0] ), false );

echo "\nTHE SAME ARRAY COMES BACK\n" . str_repeat( '=', 116 ) . "\n";

// Everything the importer reads must be exactly what it read before: the
// ceiling and the freeing are about memory, never about content.
$tricky = events_answer(
	'<Item><ItemID>110000000001</ItemID><Title>Pokémon Glurak – 1. Auflage, sehr gut</Title>'
	. '<Description><![CDATA[<div class="tpl"><a href="https://shop.example/a/b?c=1&d=2">Карта</a> ポケモン 🃏 back\\slash ' . "\u{2028}" . ' end</div>]]></Description>'
	. '<SellingStatus><CurrentPrice currencyID="EUR">0012.50</CurrentPrice><ListingStatus>Active</ListingStatus></SellingStatus>'
	. '<PictureDetails><PictureURL>https://i.ebayimg.com/00/s/MTYwMFgxMjAw/z/a/$_57.JPG</PictureURL></PictureDetails>'
	. '<ItemSpecifics><NameValueList><Name>Sprache</Name><Value>Deutsch</Value><Value>Englisch</Value></NameValueList></ItemSpecifics>'
	. '<Storefront><StoreCategoryID>0</StoreCategoryID></Storefront><SKU/></Item>'
);
$BODIES = array( $tricky );
$new    = $api->get_seller_events( '2026-10-08T10:00:00.000Z', '2026-10-09T10:00:00.000Z', 0 );
check( 'a single listing in German, Russian, Japanese, emoji and HTML', $new === old_parse( $tricky ), true );
check( '  keeps its title character for character', $new['ItemArray']['Item']['Title'], 'Pokémon Glurak – 1. Auflage, sehr gut' );
check( '  and a price written with a leading zero stays a string', $new['ItemArray']['Item']['SellingStatus']['CurrentPrice'], '0012.50' );

$many = '';
for ( $i = 0; $i < 25; $i++ ) {
	$many .= plain_item( $i, 300 );
}
$many   = events_answer( $many );
$BODIES = array( $many );
check( 'twenty-five ordinary listings', $api->get_seller_events( '2026-10-08T10:00:00.000Z', '2026-10-09T10:00:00.000Z', 0 ) === old_parse( $many ), true );

echo "\nHOW LARGE AN ANSWER EACH SITE WILL READ\n" . str_repeat( '=', 116 ) . "\n";

$restore = ini_get( 'memory_limit' );
$limits  = array();
foreach ( array( '512M', '256M', '128M', '64M', '2G', '-1' ) as $setting ) {
	ini_set( 'memory_limit', $setting );
	$limits[ $setting ] = TCGiant_Sync_API::get_events_byte_limit();
}
ini_set( 'memory_limit', $restore );

check( '512 MB, the limit in the report: a twentieth, about 25 MB', $limits['512M'], (int) floor( 512 * 1048576 / 20 ) );
check( '256 MB: about 12.5 MB', $limits['256M'], (int) floor( 256 * 1048576 / 20 ) );
check( '128 MB: about 6 MB', $limits['128M'], (int) floor( 128 * 1048576 / 20 ) );
check( '64 MB: held up at 4 MB, or nothing could be read at all', $limits['64M'], 4194304 );
check( '2 GB: capped at 32 MB', $limits['2G'], 33554432 );
// No PHP limit is not no limit: the host's container still has one.
check( 'no PHP limit at all: still 32 MB', $limits['-1'], 33554432 );

// So most shops never meet it. eBay stops at 3000 listings, and 3000 ordinary
// listings - a short description, fifteen specifics, a picture - fit at 512 MB.
$ordinary = '';
for ( $i = 0; $i < 3000; $i++ ) {
	$ordinary .= plain_item( $i, 5000 );
}
$ordinary = events_answer( $ordinary );
check( 'eBay\'s full 3000 ordinary listings fit under the 512 MB figure', strlen( $ordinary ) < $limits['512M'], true );
unset( $ordinary );

echo "\nAND READING ONE COSTS LESS\n" . str_repeat( '=', 116 ) . "\n";

// The measurement needs memory_reset_peak_usage(), which arrived in PHP 8.2.
// On older PHP the assertions above still prove the content is unchanged.
// Bytes in use rather than the allocator's total: the old path runs first, and
// the new one would otherwise be measured partly in space the old had freed.
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	// Many small fields per listing: the shape where reading costs the most
	// for its size, and where the old code was at its worst.
	$dense = '';
	for ( $i = 0; $i < 1500; $i++ ) {
		$dense .= plain_item( $i, 200, 30 );
	}
	$dense = events_answer( $dense );

	$old_peak = function () use ( $dense ) {
		// The old path, as it ran: the response still holding its copy of the body.
		$response = array( 'body' => $dense );
		gc_collect_cycles();
		$base = memory_get_usage();
		memory_reset_peak_usage();
		$body  = $response['body'];
		$xml   = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA );
		$json  = wp_json_encode( $xml );
		$array = json_decode( $json, true );
		return memory_get_peak_usage() - $base;
	};
	$old = $old_peak();

	$BODIES = array( $dense );
	unset( $dense );
	gc_collect_cycles();
	$base = memory_get_usage();
	memory_reset_peak_usage();
	$result = $api->get_seller_events( '2026-10-08T10:00:00.000Z', '2026-10-09T10:00:00.000Z', 0 );
	$new    = memory_get_peak_usage() - $base;

	printf( "        1500 listings: old path peaked %.0f MB above the body, new path %.0f MB\n", $old / 1048576, $new / 1048576 );
	check( 'the new path peaks at under 70% of the old one', $new < 0.7 * $old, true );
	check( '  and still returns every listing', count( $result['ItemArray']['Item'] ), 1500 );
} else {
	echo "        (PHP " . PHP_VERSION . " has no memory_reset_peak_usage(); measurement skipped)\n";
}

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
