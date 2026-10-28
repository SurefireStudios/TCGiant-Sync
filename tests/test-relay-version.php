<?php
/**
 * Harness: the plugin's version goes to the relay, and nothing else new.
 *
 * So the relay's dashboard can show which version each shop runs, the token
 * refresh and the token claim post plugin_version, and the usage ping's JSON
 * body carries version, inside what it signs. They are ordinary fields beside
 * the others, so a relay that does not read them yet ignores them, as it
 * ignores any field it does not read.
 *
 * Loaded for real: TCGiant_Sync_OAuth (refresh_access_token(),
 * claim_tokens_from_relay(), post_to_relay() and its fallbacks) and
 * TCGiant_Sync_Telemetry (ping_telemetry()). The network is a recorder.
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
	if ( ! $ok ) {
		printf( "        want %s\n", substr( str_replace( "\n", ' ', var_export( $want, true ) ), 0, 400 ) );
	}
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );

// The version as the plugin file defines it, so this needs no change at a release.
preg_match( "/define\(\s*'TCGIANT_SYNC_VERSION',\s*'([^']+)'\s*\)/", (string) file_get_contents( dirname( __DIR__ ) . '/tcgiant-sync.php' ), $version );
define( 'TCGIANT_SYNC_VERSION', $version[1] );

// --- WordPress, as far as these two classes go ----------------------------------

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
$GLOBALS['options'] = array();
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
function set_transient( $name, $value, $expiry = 0 ) { return true; }
function get_site_url() { return 'https://numismax.example'; }
function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function add_action( ...$args ) { return true; }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function wp_remote_retrieve_body( $response ) { return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : ''; }
function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_headers( $response ) { return is_array( $response ) ? ( $response['headers'] ?? array() ) : array(); }
function wp_remote_retrieve_header( $response, $header ) { return ''; }

/**
 * The network. Every POST is recorded; each address answers from its own
 * queue in $GLOBALS['answers'], or with the relay's tokens.
 */
$GLOBALS['posts']   = array();
$GLOBALS['answers'] = array();
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['posts'][] = array( $url, $args );
	if ( ! empty( $GLOBALS['answers'][ $url ] ) ) {
		return array_shift( $GLOBALS['answers'][ $url ] );
	}
	return answer( 200, '{"access_token":"v^1.1#NEW-ACCESS","refresh_token":"v^1.1#REFRESH","expires_in":7200,"relay_key":"k"}' );
}
function answer( $code, $body ) { return array( 'response' => array( 'code' => $code ), 'body' => $body, 'headers' => array() ); }
$challenge = answer( 200, '<!DOCTYPE html><html><head><title>Checking your browser</title></head><body></body></html>' );

// --- the plugin's other classes, as far as these two go -----------------------------

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['log'][] = array( $level, $message ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
}
class TCGiant_Sync_API {
	public static function instance() { return new self(); }
	public function get_daily_call_count() { return 41; }
}
class TCGiant_Sync_License {
	public static function instance() { return new self(); }
	public function get_license_data() { return array( 'status' => 'active', 'variant' => 'pro' ); }
	public function get_active_product_count() { return 120; }
	public function get_pushed_product_count() { return 30; }
	public function get_pulled_product_count() { return 90; }
}
// 3.28.0: the refresh reads the connection from the settings' row and writes
// only its own two keys back onto it; the ping says the push switch's state.
class TCGiant_Sync_Inventory {
	public static function settings_row() { $v = get_option( 'tcgiant_sync_ebay_settings', array() ); return is_array( $v ) ? $v : array(); }
	public static function forget_settings_copy() {}
	public static function connected( $settings ) { return ! empty( $settings['access_token'] ) && ! empty( $settings['refresh_token'] ); }
	public static function save_settings_keys( array $keys ) { update_option( 'tcgiant_sync_ebay_settings', array_replace( self::settings_row(), $keys ) ); }
	public static function push_switch_state() { return 'on'; }
}

require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-oauth.php';
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-telemetry.php';

update_option( 'tcgiant_sync_ebay_settings', array(
	'access_token'  => 'v^1.1#OLD-ACCESS',
	'refresh_token' => 'v^1.1#REFRESH',
	'relay_secret'  => 'per-site-key',
) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE TOKEN REFRESH\n" . str_repeat( '=', 112 ) . "\n";

$GLOBALS['posts'] = array();
$token            = TCGiant_Sync_OAuth::instance()->refresh_access_token();
list( $url, $args ) = $GLOBALS['posts'][0];
check( 'the refresh still renews the token', $token, 'v^1.1#NEW-ACCESS' );
check( '  in one request, to the exempt hostname', array( count( $GLOBALS['posts'] ), $url ), array( 1, TCGiant_Sync_OAuth::API_RELAY_URL ) );
check( '  carrying plugin_version: this version', $args['body']['plugin_version'] ?? null, TCGIANT_SYNC_VERSION );
check( '  as an ordinary form field: the body is the array of fields, as before', array( is_array( $args['body'] ), $args['body']['action'] ?? null ), array( true, 'refresh' ) );
check( '  and nothing else new: 3.27.7\'s fields, then plugin_version', array_keys( $args['body'] ), array( 'action', 'refresh_token', 'site_url', 'api_calls_today', 'api_calls_date', 'plugin_version' ) );

// A security filter answers both form routes with a challenge page: the
// request goes as JSON to the other endpoint, with the same fields.
$GLOBALS['posts']   = array();
$GLOBALS['answers'] = array(
	TCGiant_Sync_OAuth::API_RELAY_URL => array( $challenge ),
	TCGiant_Sync_OAuth::RELAY_URL     => array( $challenge ),
);
$token = TCGiant_Sync_OAuth::instance()->refresh_access_token();
$sent  = array_column( $GLOBALS['posts'], 1, 0 );
check( 'challenged on both form routes: renewed through the JSON endpoint', array( $token, array_keys( $sent ) ), array( 'v^1.1#NEW-ACCESS', array( TCGiant_Sync_OAuth::API_RELAY_URL, TCGiant_Sync_OAuth::RELAY_URL, TCGiant_Sync_OAuth::RELAY_FALLBACK_URL ) ) );
check( '  the main site\'s form route carried plugin_version too', $sent[ TCGiant_Sync_OAuth::RELAY_URL ]['body']['plugin_version'] ?? null, TCGIANT_SYNC_VERSION );
check( '  and the JSON body carries it beside the same fields', json_decode( (string) $sent[ TCGiant_Sync_OAuth::RELAY_FALLBACK_URL ]['body'], true ), $sent[ TCGiant_Sync_OAuth::RELAY_URL ]['body'] );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE TOKEN CLAIM, WHEN A SHOP CONNECTS\n" . str_repeat( '=', 112 ) . "\n";

$GLOBALS['posts'] = array();
$claimed          = TCGiant_Sync_OAuth::instance()->claim_tokens_from_relay( 'one-time-code' );
list( $url, $args ) = $GLOBALS['posts'][0];
check( 'the claim still collects the tokens', array( $claimed['access_token'] ?? null, $claimed['refresh_token'] ?? null ), array( 'v^1.1#NEW-ACCESS', 'v^1.1#REFRESH' ) );
check( '  carrying plugin_version: this version', $args['body']['plugin_version'] ?? null, TCGIANT_SYNC_VERSION );
check( '  as an ordinary form field', array( is_array( $args['body'] ), $args['body']['action'] ?? null, $url ), array( true, 'claim', TCGiant_Sync_OAuth::API_RELAY_URL ) );
check( '  and nothing else new: 3.27.7\'s fields, then plugin_version', array_keys( $args['body'] ), array( 'action', 'code', 'site_url', 'plugin_version' ) );

$oauth = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-oauth.php' );
check( 'the claim and the refresh are the only two post_to_relay() calls', substr_count( $oauth, 'self::post_to_relay( array(' ), 2 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE USAGE PING\n" . str_repeat( '=', 112 ) . "\n";

$GLOBALS['posts'] = array();
TCGiant_Sync_Telemetry::instance()->ping_telemetry();
list( $url, $args ) = $GLOBALS['posts'][0];
$ping = json_decode( (string) $args['body'], true );
check( 'the ping goes as before', array( count( $GLOBALS['posts'] ), $url, $args['headers']['Content-Type'] ?? null ), array( 1, 'https://tcgiant.com/syncconnect/telemetry.php', 'application/json' ) );
check( '  carrying version: this version', $ping['version'] ?? null, TCGIANT_SYNC_VERSION );
// 3.28.0's stock_push (item 17) sits between them.
check( '  and nothing else new: 3.27.7\'s fields, 3.28.0\'s stock_push, then version', array_keys( (array) $ping ), array( 'site_url', 'synced_total', 'pushed_total', 'pulled_total', 'license_type', 'stock_push', 'version' ) );
check( '  signed over the body exactly as sent, the version inside it', hash_hmac( 'sha256', $args['body'] . $args['headers']['X-TCGiant-Timestamp'], 'per-site-key' ), $args['headers']['X-TCGiant-Signature'] );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
