<?php
/**
 * Harness: Suggest Category's title, from the product's name to eBay (3.29.2).
 *
 * The eBay tab's "Suggest Category from Title" button wrote the product's
 * name into its script with esc_js() inside quotes, which writes & and " as
 * &amp; and &quot;, and ajax_suggest_category() read it back with
 * sanitize_text_field(), which keeps those entities and deletes a "%" with
 * two hex digits after it. So "Pokemon & Yu-Gi-Oh! Lot" reached eBay as
 * "Pokemon &amp; Yu-Gi-Oh! Lot", however well get_suggested_categories()
 * encoded it.
 *
 * Here the whole way runs on WordPress 7.1.3's own escaping
 * (tests/wordpress/escaping.php, utf8.php, compat-utf8.php, with plugin.php
 * and kses.php) - esc_js(), wp_json_encode(), add_magic_quotes(),
 * wp_unslash(), wp_specialchars_decode(), wp_strip_all_tags(),
 * wp_pre_kses_less_than(), sanitize_text_field() - never a stand-in:
 * - the tab's literal (TCGiant_Sync_Admin::suggest_title_js()) is safe in a
 *   script and the browser reads the name from it exactly;
 * - the page posts it, WordPress slashes it, and the real
 *   ajax_suggest_category() hands it to the real TCGiant_Sync_API, whose
 *   tree-0 and tree-100 reads are taken at wp_remote_request() and read as
 *   eBay's server reads them;
 * - eBay is asked about the product's own words: "&", quotes, "%20", "%"
 *   alone, "+", non-ASCII, a name stored with entities, a lone "<"; tags and
 *   a script go, control characters are spaces, at most 300 characters;
 * - a script in a name is never sent back unescaped, nor sent to eBay;
 * - a page drawn before 3.29.2 (esc_js()) still asks about the real words.
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__ );
$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-96s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 160 ) );
	if ( ! $ok ) {
		printf( "        want %s\n", substr( str_replace( "\n", ' ', var_export( $want, true ) ), 0, 400 ) );
	}
}

define( 'ABSPATH', $ROOT . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'TCGIANT_SYNC_IS_STAGING', false );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
function wp_rand( $min = 0, $max = 0 ) { return $min; }
function get_option( $name, $default = false ) {
	if ( 'blog_charset' === $name ) {
		return 'UTF-8';
	}
	return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['OPTIONS'][ $name ] ); return true; }
function get_transient( $name ) { return $GLOBALS['TRANSIENTS'][ $name ] ?? false; }
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $name ] = $value; return true; }
function delete_transient( $name ) { unset( $GLOBALS['TRANSIENTS'][ $name ] ); return true; }
// WordPress's own: the values go into the URL as they are given.
function add_query_arg( $args, $url ) {
	$pairs = array();
	foreach ( $args as $k => $v ) {
		$pairs[] = $k . '=' . $v;
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $pairs );
}
function wp_load_alloptions() { return array( 'home' => 'https://shop.example', 'siteurl' => 'https://shop.example' ); }
function wp_get_environment_type() { return 'production'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function check_ajax_referer( $action = -1, $arg = false, $die = true ) { return 1; }
function current_user_can( $cap, ...$args ) { return true; }

/** The day's call counter, as the options table keeps it. */
class Title_Wpdb {
	public $options = 'wp_options';
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_var( $q ) { return '0'; }
	public function query( $q ) { return 1; }
	public function get_results( $q ) { return array(); }
}
$GLOBALS['wpdb'] = new Title_Wpdb();

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
}
class TCGiant_Sync_OAuth {
	public static function instance() { return new self(); }
	public function get_settings() { return $GLOBALS['SETTINGS']; }
	public function is_authenticated() { return true; }
	public function get_access_token() { return 'title-token'; }
	public function refresh_access_token() { return false; }
}

/** eBay, played at the wire: every URL recorded, each read answered by the first key its URL contains. */
function wp_remote_request( $url, $args ) {
	$GLOBALS['WIRE'][] = $url;
	foreach ( $GLOBALS['REPLIES'] as $needle => $reply ) {
		if ( false !== strpos( $url, $needle ) ) {
			return array( 'headers' => array(), 'response' => array( 'code' => $reply[0] ), 'body' => json_encode( $reply[1] ) );
		}
	}
	return array( 'headers' => array(), 'response' => array( 'code' => 404 ), 'body' => '{"errors":[{"errorId":1}]}' );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? ''; }
function wp_remote_retrieve_header( $response, $header ) { return $response['headers'][ $header ] ?? ''; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }

/** wp_send_json_*(), which end the request. */
class Title_Answer extends Exception {
	public $ok;
	public $data;
	public function __construct( $ok, $data ) { parent::__construct( 'json' ); $this->ok = $ok; $this->data = $data; }
}
function wp_send_json_success( $data = null ) { throw new Title_Answer( true, $data ); }
function wp_send_json_error( $data = null ) { throw new Title_Answer( false, $data ); }

// WordPress 7.1.3's own.
require $ROOT . '/tests/wordpress/plugin.php';
require $ROOT . '/tests/wordpress/kses.php';
require $ROOT . '/tests/wordpress/utf8.php';
require $ROOT . '/tests/wordpress/compat-utf8.php';
require $ROOT . '/tests/wordpress/escaping.php';

require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-motors.php';
require $ROOT . '/admin/class-tcgiant-sync-admin.php';

$ADMIN = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();

/** A shop with nothing on the wire. */
function fresh( $marketplace = 'EBAY_US' ) {
	$GLOBALS['OPTIONS']    = array();
	$GLOBALS['TRANSIENTS'] = array();
	$GLOBALS['WIRE']       = array();
	$GLOBALS['LOG']        = array();
	$GLOBALS['SETTINGS']   = array( 'marketplace' => $marketplace );
	$GLOBALS['REPLIES']    = array(
		'category_tree/100/get_category_suggestions' => array( 200, array( 'categorySuggestions' => array() ) ),
		'get_category_suggestions'                   => array( 200, array( 'categorySuggestions' => array( array( 'category' => array( 'categoryId' => '183454', 'categoryName' => 'CCG Individual Cards' ), 'categoryTreeNodeAncestors' => array( array( 'categoryId' => '2536', 'categoryName' => 'Collectible Card Games' ) ) ) ) ) ),
	);
}
/** What the browser holds once it has run a JavaScript string literal the page wrote. */
function browser_reads( $literal ) {
	if ( "'" === substr( $literal, 0, 1 ) ) {
		// esc_js() inside single quotes, as the tab wrote it before 3.29.2.
		return stripcslashes( substr( $literal, 1, -1 ) );
	}
	return json_decode( $literal );
}
/** Suggest Category, posted by the page with this value, as WordPress hands $_POST on (slashed): its answer, and the q of each read. */
function suggest( $value ) {
	fresh( $GLOBALS['MARKET'] ?? 'EBAY_US' );
	$_POST  = add_magic_quotes( array( 'action' => 'tcgiant_suggest_category', '_ajax_nonce' => 'n', 'title' => $value ) );
	$answer = null;
	try {
		$GLOBALS['ADMIN']->ajax_suggest_category();
	} catch ( Title_Answer $a ) {
		$answer = $a;
	}
	$_POST = array();
	$asked = array();
	foreach ( $GLOBALS['WIRE'] as $url ) {
		$asked[] = asked( $url );
	}
	return array( $answer, $asked );
}
/** What eBay's server reads as q: nothing after "#" goes on the wire, and the query is parsed as a form. */
function asked( $url ) {
	$url   = explode( '#', (string) $url, 2 )[0];
	$query = (string) ( explode( '?', $url, 2 )[1] ?? '' );
	parse_str( $query, $args );
	return $args['q'] ?? null;
}
/** The answer as wp_send_json() would print it. */
function printed( $answer ) {
	return $answer ? (string) wp_json_encode( array( 'success' => $answer->ok, 'data' => $answer->data ) ) : '';
}

echo "\nFROM THE PRODUCT'S NAME TO EBAY, ON WORDPRESS'S OWN ESCAPING\n" . str_repeat( '=', 124 ) . "\n";

$long  = rtrim( str_repeat( 'Charizard ', 40 ) );
$cases = array(
	array( 'Pokemon & Yu-Gi-Oh! 6" Lot', 'Pokemon & Yu-Gi-Oh! 6" Lot' ),
	array( "Charizard 'Base Set' #4/102", "Charizard 'Base Set' #4/102" ),
	array( 'Card sleeves 100%20pack', 'Card sleeves 100%20pack' ),
	array( '100% Authentic PSA 10 Pikachu', '100% Authentic PSA 10 Pikachu' ),
	array( 'Tin %AB edition, 50%off', 'Tin %AB edition, 50%off' ),
	array( 'Magic + Tokens bundle', 'Magic + Tokens bundle' ),
	array( 'Pokémon Évoli – Édition 1, ポケモンカード', 'Pokémon Évoli – Édition 1, ポケモンカード' ),
	array( 'Booster box <10 left & sealed', 'Booster box <10 left & sealed' ),
	array( 'Path C:\\cards\\holo Pikachu', 'Path C:\\cards\\holo Pikachu' ),
	array( 'Pokemon &amp; Yu-Gi-Oh! &#039;Lot&#039; &quot;6&quot;', 'Pokemon & Yu-Gi-Oh! \'Lot\' "6"' ),
	array( 'Charizard <script>alert(1)</script> Holo', 'Charizard Holo' ),
	array( '<b>Bold</b> Pikachu <img src=x onerror=alert(1)>', 'Bold Pikachu' ),
	array( "Two\tlines\nand   spaces\r\n", 'Two lines and spaces' ),
	array( $long, rtrim( mb_substr( $long, 0, 300 ) ) ),
);
foreach ( $cases as $case ) {
	list( $name, $want ) = $case;
	$literal = TCGiant_Sync_Admin::suggest_title_js( $name );
	$label   = mb_strimwidth( str_replace( array( "\t", "\n", "\r" ), array( '\t', '\n', '\r' ), $name ), 0, 60, '...' );
	check( sprintf( '"%s": the tab\'s literal is safe in a script, and the browser reads the name exactly', $label ), array( (bool) preg_match( '/[<>&\']/', $literal ), substr_count( $literal, '"' ), browser_reads( $literal ) ), array( false, 2, $name ) );
	list( $answer, $asked ) = suggest( browser_reads( $literal ) );
	check( '  eBay is asked, in tree 0 and tree 100, about its own words', $asked, array( $want, $want ) );
	check( '  the answer is the suggestions alone, and never carries a script', array( $answer ? $answer->ok : null, array_keys( (array) ( $answer ? $answer->data : array() ) ), false !== stripos( printed( $answer ), '<script' ) || false !== stripos( printed( $answer ), 'alert(' ) ), array( true, array( 'suggestions' ), false ) );
}

echo "\nA PAGE DRAWN BEFORE 3.29.2, AND WHAT IT ASKED THEN\n" . str_repeat( '=', 124 ) . "\n";

foreach ( array( array( 'Pokemon & Yu-Gi-Oh! 6" Lot', 'Pokemon &amp; Yu-Gi-Oh! 6&quot; Lot' ), array( 'Card sleeves 100%20pack', 'Card sleeves 100pack' ), array( 'Tin %AB edition, 50%off', 'Tin edition, 50%off' ) ) as $case ) {
	list( $name, $then ) = $case;
	$old = "'" . esc_js( $name ) . "'";
	check( sprintf( '"%s": 3.29.1\'s tab (esc_js()) and sanitize_text_field() asked eBay about "%s"', $name, $then ), sanitize_text_field( wp_unslash( add_magic_quotes( array( browser_reads( $old ) ) ) )[0] ), $then );
	list( , $asked ) = suggest( browser_reads( $old ) );
	check( '  that page, posted now: eBay is asked about the real words', $asked, array( $name, $name ) );
}

echo "\nNOTHING TO ASK ABOUT\n" . str_repeat( '=', 124 ) . "\n";

foreach ( array(
	'an empty name'                       => '',
	'spaces alone'                        => "  \t ",
	'a script alone'                      => '<script>alert(1)</script>',
	'invalid UTF-8 (posted as such)'      => "Pok\xE9mon",
	'a list instead of a title'           => array( 'Pikachu' ),
) as $what => $value ) {
	list( $answer, $asked ) = suggest( $value );
	check( sprintf( '%s: "No title provided.", nothing asked of eBay, nothing of it sent back', $what ), array( $answer ? $answer->ok : null, $answer ? $answer->data : null, $asked ), array( false, array( 'message' => 'No title provided.' ), array() ) );
}
check( 'a name in another encoding: the tab still writes a string literal (wp_json_encode() repairs it), and eBay is asked about what the browser reads', ( function () {
	$literal = TCGiant_Sync_Admin::suggest_title_js( "Pok\xE9mon Evoli" );
	$read    = browser_reads( $literal );
	return array( is_string( $read ), (bool) preg_match( '/[<>&\']/', $literal ), is_string( $read ) && mb_check_encoding( $read, 'UTF-8' ), suggest( $read )[1] === array( $read, $read ) );
} )(), array( true, false, true, true ) );

$GLOBALS['MARKET'] = 'EBAY_GB';
list( , $asked ) = suggest( TCGiant_Sync_Admin::suggest_title_js( 'Pokemon & Yu-Gi-Oh!' ) === '"Pokemon \u0026 Yu-Gi-Oh!"' ? 'Pokemon & Yu-Gi-Oh!' : 'wrong' );
check( 'a shop on eBay UK: its own tree alone, asked about the real words', array( $asked, explode( '?', $GLOBALS['WIRE'][0] ?? '', 2 )[0] ), array( array( 'Pokemon & Yu-Gi-Oh!' ), 'https://api.ebay.com/commerce/taxonomy/v1/category_tree/3/get_category_suggestions' ) );
unset( $GLOBALS['MARKET'] );

check( 'TCGiant_Sync_Admin::suggest_title() caps a title at SUGGEST_TITLE_MAX (300) characters, counted as characters', array( TCGiant_Sync_Admin::SUGGEST_TITLE_MAX, mb_strlen( TCGiant_Sync_Admin::suggest_title( str_repeat( 'é', 400 ) ) ) ), array( 300, 300 ) );

echo "\n  $pass passed, $fail failed\n\n";
exit( $fail > 0 ? 1 : 0 );
