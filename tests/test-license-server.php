<?php
/**
 * Harness: TCGiant's licence server (3.41.0).
 *
 * The licence is checked with tcgiant.com first, and Lemon Squeezy only when
 * the server gives no answer, or one that says nothing for sure, and the site
 * holds no signed answer still to be trusted offline (PROTOCOL.md 4.4, in the
 * website's licence/ folder). Only a signed answer from the server is
 * definitive: its signature is checked against the key built into the
 * plugin, and it must be about this key, instance, request and site.
 *
 * Loaded for real: TCGiant_Sync_Entitlements, TCGiant_Sync_License and
 * TCGiant_Sync_Limit_Held, in test-license-robustness.php's world, taken as
 * it stands up to its first case. The server is played at the HTTP call: its
 * answers are signed with the test key whose public half is built into this
 * version (TCGiant_Sync_License::SERVER_KEYS), its secret made here from the
 * phrase it was made from. Ed25519 is libsodium's where PHP has it, else
 * support/ed25519-shim.php, RFC 8032's reference implementation in pure PHP,
 * as WordPress's sodium_compat stands in on a site.
 *
 * @package TCGiant_Sync
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
// As WordPress sets it.
date_default_timezone_set( 'UTC' );

$ROOT = dirname( __DIR__ );

require __DIR__ . '/support/ed25519-shim.php';
// The test key is trusted only where this is defined (3.41.0 fix round 2):
// a release that slipped out with it trusts nothing signed with it.
define( 'TCGIANT_SYNC_LICENCE_TEST_KEYS', true );

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-96s got %-18s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}
/** Within a few seconds of what is wanted: the clock moves while a check runs. */
function near( $got, $want, $slack = 5 ) {
	return abs( (int) $got - (int) $want ) <= $slack;
}

// ---- WordPress, as far as the licence uses it -----------------------------------------

class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class Harness_Redirect extends Exception {}

$OPTIONS    = array();
$TABLE      = array(); // the options table; OPTIONS is this request's copy of it
$DURING     = null;    // run while a request to Lemon Squeezy is out: another request's write
$AUTOLOAD   = array();
$TRANSIENTS = array();
$HOOKS      = array();
$FILTERS    = array();
$QUEUE      = array();
$RUNNING    = array();
$REPLIES    = array();
$HTTP       = array();
$SERVER_HTTP    = array();
$SERVER_REPLIES = array();
$LOG        = array();
$IS_ADMIN   = true;
$ACTIVE     = 50;
$SCREEN     = null;
$SPECIFIC   = array();
$LINKED     = array();
$BUDGET     = array();
$CONNECTED  = true;
$SETTINGS   = array();
$SYNC_STATE = array();
$OVER_TIME  = '';

function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $t ) { return $t; }
function esc_html__( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_html_e( $t ) { echo esc_html__( $t ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_url( $t ) { return (string) $t; }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_site_url() { return 'https://shop.example'; }
function home_url() { return 'https://shop.example'; }
function wp_parse_url( $u ) { return parse_url( $u ); }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['HOOKS'][ $hook ][] = array( $cb, $args ); return true; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { return add_action( $hook, $cb, $prio, $args ); }
function apply_filters( $hook, $value ) { return isset( $GLOBALS['FILTERS'][ $hook ] ) ? call_user_func( $GLOBALS['FILTERS'][ $hook ], $value ) : $value; }
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $h ) {
		call_user_func_array( $h[0], array_slice( $args, 0, $h[1] ) );
	}
}
function is_admin() { return $GLOBALS['IS_ADMIN']; }
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['OPTIONS'][ $n ] = $v; $GLOBALS['TABLE'][ $n ] = $v; $GLOBALS['AUTOLOAD'][ $n ] = $a; return true; }
function delete_option( $n ) { unset( $GLOBALS['OPTIONS'][ $n ], $GLOBALS['TABLE'][ $n ] ); return true; }
/** Another request writes an option: the table changes, this request's copy does not. */
function elsewhere( $n, $v ) { $GLOBALS['TABLE'][ $n ] = $v; }
/** A new request: its copy of the options read fresh from the table. */
function new_request() { $GLOBALS['OPTIONS'] = $GLOBALS['TABLE']; }
function maybe_unserialize( $v ) { $u = @unserialize( $v ); return false === $u && 'b:0;' !== $v ? $v : $u; }
/** Dropping the licence from the cache, or every autoloaded option, reads it from the table again. */
function wp_cache_delete( $k, $g = '' ) {
	if ( 'options' === $g && in_array( $k, array( 'tcgiant_sync_license', 'alloptions' ), true ) ) {
		if ( array_key_exists( 'tcgiant_sync_license', $GLOBALS['TABLE'] ) ) {
			$GLOBALS['OPTIONS']['tcgiant_sync_license'] = $GLOBALS['TABLE']['tcgiant_sync_license'];
		} else {
			unset( $GLOBALS['OPTIONS']['tcgiant_sync_license'] );
		}
	}
	return true;
}
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function esc_attr_e( $t ) { echo esc_html( $t ); }
function get_transient( $k ) {
	$t = $GLOBALS['TRANSIENTS'][ $k ] ?? null;
	if ( null === $t || ( $t[1] > 0 && $t[1] < time() ) ) {
		return false;
	}
	return $t[0];
}
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $k ] = array( $v, $ttl > 0 ? time() + $ttl : 0 ); return true; }
function delete_transient( $k ) { unset( $GLOBALS['TRANSIENTS'][ $k ] ); return true; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function wp_date( $f, $ts ) { return gmdate( 'Y-m-d H:i', (int) $ts ); }
function get_current_screen() { return $GLOBALS['SCREEN']; }
function current_user_can( $c ) { return true; }
function check_admin_referer( $a ) { return true; }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . $a . '">'; }
function admin_url( $p = '' ) { return 'https://shop.example/wp-admin/' . $p; }
function add_query_arg( $k, $v, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function wp_safe_redirect( $url ) { throw new Harness_Redirect( $url ); }
function wp_die( $m ) { throw new RuntimeException( (string) $m ); }

/**
 * Every request recorded and answered from a script, in order: TCGiant's
 * licence server's apart from Lemon Squeezy's. A scripted answer may be a
 * closure, given the request's JSON, so the server can sign what it was
 * asked (its nonce). Nothing scripted for the server: it cannot be reached.
 */
function wp_remote_post( $url, $args ) {
	if ( 0 === strpos( $url, 'https://tcgiant.com/licence/' ) ) {
		$GLOBALS['SERVER_HTTP'][] = array( 'url' => $url, 'args' => $args );
		if ( is_callable( $GLOBALS['DURING'] ) ) {
			call_user_func( $GLOBALS['DURING'] );
		}
		if ( empty( $GLOBALS['SERVER_REPLIES'] ) ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to tcgiant.com port 443' );
		}
		$r = array_shift( $GLOBALS['SERVER_REPLIES'] );
		return $r instanceof Closure ? $r( json_decode( $args['body'], true ) ) : $r;
	}
	$GLOBALS['HTTP'][] = array( 'url' => $url, 'args' => $args );
	if ( is_callable( $GLOBALS['DURING'] ) ) {
		call_user_func( $GLOBALS['DURING'] );
	}
	if ( empty( $GLOBALS['REPLIES'] ) ) {
		return new WP_Error( 'harness', 'no answer scripted' );
	}
	return array_shift( $GLOBALS['REPLIES'] );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }

// Action Scheduler: pending actions with the time they are due.
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { $GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'when' => time(), 'async' => true ); return count( $GLOBALS['QUEUE'] ); }
function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) { $GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'when' => (int) $when, 'async' => false ); return count( $GLOBALS['QUEUE'] ); }
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	if ( in_array( $hook, $GLOBALS['RUNNING'], true ) ) {
		return true;
	}
	$next = false;
	foreach ( $GLOBALS['QUEUE'] as $a ) {
		if ( $a['hook'] === $hook && ( false === $next || $a['when'] < $next ) ) {
			$next = $a['when'];
		}
	}
	return $next;
}
function as_unschedule_all_actions( $hook, $args = null, $group = '' ) { $GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) use ( $hook ) { return $a['hook'] !== $hook; } ) ); }
/** Run what is due, as Action Scheduler would. */
function run_due( $only = null, $max = 20 ) {
	$ran = 0;
	while ( $ran < $max ) {
		$idx = null;
		foreach ( $GLOBALS['QUEUE'] as $i => $a ) {
			if ( $a['when'] <= time() && ( null === $only || $a['hook'] === $only ) ) {
				$idx = $i;
				break;
			}
		}
		if ( null === $idx ) {
			break;
		}
		$a = $GLOBALS['QUEUE'][ $idx ];
		array_splice( $GLOBALS['QUEUE'], $idx, 1 );
		$GLOBALS['RUNNING'] = array( $a['hook'] );
		do_action( $a['hook'], ...array_values( (array) $a['args'] ) );
		$GLOBALS['RUNNING'] = array();
		$ran++;
	}
	return $ran;
}
/** Pending actions of a hook. */
function queued( $hook ) { return array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) use ( $hook ) { return $a['hook'] === $hook; } ) ); }

class Harness_Wpdb {
	public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta';
	public $options = 'wp_options';
	public function prepare( $q, ...$args ) { foreach ( $args as $a ) { $q = preg_replace( '/%s/', "'" . addslashes( (string) $a ) . "'", $q, 1 ); } return $q; }
	/** An option, from the table; else the count of linked products. */
	public function get_var( $q ) {
		if ( preg_match( "/option_name = '([^']+)'/", $q, $m ) ) {
			return array_key_exists( $m[1], $GLOBALS['TABLE'] ) ? serialize( $GLOBALS['TABLE'][ $m[1] ] ) : null;
		}
		return (string) $GLOBALS['ACTIVE'];
	}
}
$wpdb = new Harness_Wpdb();

// ---- the plugin's classes the licence talks to, played --------------------------------

class TCGiant_Sync_Logger {
	public static function log( $m, $l = 'info' ) { $GLOBALS['LOG'][] = array( $l, $m ); }
	public static function warning( $m ) { self::log( $m, 'warning' ); }
	public static function error( $m ) { self::log( $m, 'error' ); }
}
class TCGiant_Sync_Importer {
	const DELTA_CHASE_BUDGET_SHARE = 0.25;
	public static function instance() { static $i; return $i ?: ( $i = new self() ); }
	public function start_specific_sync( $ids ) { $GLOBALS['SPECIFIC'][] = array_values( $ids ); }
	public static function find_live_product_for_item( $id ) { return in_array( (string) $id, $GLOBALS['LINKED'], true ) ? 7 : 0; }
	public static function get_sync_state() { return $GLOBALS['SYNC_STATE']; }
}
class TCGiant_Sync_API {
	const DAILY_CALL_LIMIT        = 50000;
	const DAILY_CALL_SOFT_CEILING = 0.80;
	public static function instance() { static $i; return $i ?: ( $i = new self() ); }
	public function is_daily_budget_exhausted() { return ! empty( $GLOBALS['BUDGET']['exhausted'] ); }
	public function get_remaining_daily_budget() { return (int) ( $GLOBALS['BUDGET']['remaining'] ?? 40000 ); }
	public function site_daily_allowance() { return (int) ( $GLOBALS['BUDGET']['own'] ?? 0 ); }
	public function get_daily_call_count() { return (int) ( $GLOBALS['BUDGET']['count'] ?? 0 ); }
}
class TCGiant_Sync_OAuth {
	public static function instance() { static $i; return $i ?: ( $i = new self() ); }
	public function is_authenticated() { return $GLOBALS['CONNECTED']; }
	public function get_settings() { return $GLOBALS['SETTINGS']; }
}
class TCGiant_Sync_Runtime {
	public static function over_time_budget() { return is_callable( $GLOBALS['OVER_TIME'] ) ? (string) call_user_func( $GLOBALS['OVER_TIME'] ) : (string) $GLOBALS['OVER_TIME']; }
}

require $ROOT . '/includes/class-tcgiant-sync-entitlements.php';
require $ROOT . '/includes/class-tcgiant-sync-limit-held.php';
// The licence class with the test key built in, as it is while 3.41.0 is a
// candidate. Once the owner's real key has replaced it, a copy with the test
// key added beside his is loaded instead, so the harness, which signs with
// the test key's secret, runs the same before and after.
$LIC_SRC   = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-license.php' );
$TEST_PUB  = '6rMKh3pRb2fjwWb8NYSNrOwhT2i+SDeHGJmymftnha0=';
$TEST_COPY = false === strpos( $LIC_SRC, "'test-2026-11' => '" . $TEST_PUB . "'" );
if ( $TEST_COPY ) {
	$copy = sys_get_temp_dir() . '/tcg-licence-test-key-' . getmypid() . '.php';
	file_put_contents( $copy, preg_replace( '/const SERVER_KEYS = array\(/', "const SERVER_KEYS = array(\n\t\t'test-2026-11' => '" . $TEST_PUB . "',", $LIC_SRC, 1 ) );
	require $copy;
	@unlink( $copy );
} else {
	require $ROOT . '/includes/class-tcgiant-sync-license.php';
}

const KEY  = '38b1460a-5104-4067-a91d-77b872934d51';
const INST = '47596ad9-a811-4ebf-ac8a-03fc7b6d2a17';
const OPT  = 'tcgiant_sync_license';

function lic() { return TCGiant_Sync_License::instance(); }
function data() { return get_option( OPT, array() ); }
function ui() { return lic()->get_status_for_ui(); }
function state() { return ui()['check']['state']; }
function iso( $ts ) { return gmdate( 'Y-m-d\TH:i:s.000000\Z', $ts ); }

/** A Pro shop, its key last found valid an hour ago, renewing in 200 days, not due. */
function fresh( array $over = array() ) {
	$now = time();
	$GLOBALS['OPTIONS']    = array();
	$GLOBALS['TABLE']      = array();
	$GLOBALS['DURING']     = null;
	$GLOBALS['AUTOLOAD']   = array();
	$GLOBALS['TRANSIENTS'] = array();
	$GLOBALS['QUEUE']      = array();
	$GLOBALS['REPLIES']    = array();
	$GLOBALS['HTTP']       = array();
	$GLOBALS['SERVER_HTTP']    = array();
	$GLOBALS['SERVER_REPLIES'] = array();
	$GLOBALS['LOG']        = array();
	$GLOBALS['FILTERS']    = array();
	$GLOBALS['SPECIFIC']   = array();
	$GLOBALS['LINKED']     = array();
	$GLOBALS['BUDGET']     = array();
	$GLOBALS['CONNECTED']  = true;
	$GLOBALS['SETTINGS']   = array( 'sync_interval' => 'tcgiant_hourly' );
	$GLOBALS['SYNC_STATE'] = array( 'status' => 'complete' );
	$GLOBALS['OVER_TIME']  = '';
	$GLOBALS['ACTIVE']     = 50;
	unset( $GLOBALS['HOOKS']['shutdown'] );
	$h = new ReflectionProperty( 'TCGiant_Sync_Limit_Held', 'noted' );
	if ( PHP_VERSION_ID < 80100 ) {
		$h->setAccessible( true );
	}
	$h->setValue( null, array() );
	update_option( OPT, array_merge( array(
		'key' => KEY, 'status' => 'active', 'instance_id' => INST,
		'customer_name' => 'A Customer', 'customer_email' => 'a@example.com',
		'variant' => 'annual', 'expires_at' => iso( $now + 200 * DAY_IN_SECONDS ),
		'activated_at' => '2026-01-01 00:00:00',
		'checked_at' => $now - 3600, 'valid_at' => $now - 3600, 'asked_at' => $now - 3600, 'next_check' => $now + 23 * 3600,
	), $over ) );
	$GLOBALS['HTTP'] = array();
}

/** A reply from Lemon Squeezy. */
function reply( $code, $body ) {
	return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => is_string( $body ) ? $body : json_encode( $body ) );
}
/** validate's answer for a valid key, as the docs show it. */
function valid_body( array $over = array() ) {
	return array_replace_recursive( array(
		'valid' => true, 'error' => null,
		'license_key' => array( 'id' => 1, 'status' => 'active', 'key' => KEY, 'activation_limit' => 1, 'activation_usage' => 1, 'created_at' => '2026-01-01T00:00:00.000000Z', 'expires_at' => null ),
		'instance' => array( 'id' => INST, 'name' => 'shop-example', 'created_at' => '2026-01-01T00:00:00.000000Z' ),
		'meta' => array( 'store_id' => 335114, 'order_id' => 2345, 'order_item_id' => 3456, 'product_id' => 958193, 'product_name' => 'TCGiant Sync', 'variant_id' => 91011, 'variant_name' => 'Annual Plan', 'customer_id' => 4567, 'customer_name' => 'A Customer', 'customer_email' => 'a@example.com' ),
	), $over );
}
/** One check now, answered so. */
function ask( $reply ) {
	$GLOBALS['REPLIES'] = array( $reply );
	return lic()->validate_license();
}
/** The log lines the licence wrote, that begin so. */
function lines( $start = 'Licence:' ) {
	return array_values( array_map( function ( $l ) { return $l[1]; }, array_filter( $GLOBALS['LOG'], function ( $l ) use ( $start ) { return 0 === strpos( $l[1], $start ); } ) ) );
}
function make_due() {
	$d = data();
	$d['next_check'] = time() - 1;
	update_option( OPT, $d );
	delete_transient( TCGiant_Sync_License::QUEUED_TRANSIENT );
	foreach ( $GLOBALS['QUEUE'] as $i => $a ) {
		$GLOBALS['QUEUE'][ $i ]['when'] = min( $a['when'], time() - 1 );
	}
}
function set( array $over ) { update_option( OPT, array_merge( data(), $over ) ); }

/** The card, drawn from the view alone (the robustness harness's). */
function card( array $get = array() ) {
	$src  = (string) file_get_contents( $GLOBALS['ROOT'] . '/admin/views/settings.php' );
	$a    = strpos( $src, '<!-- License -->' );
	$b    = strpos( $src, '<!-- ─── SETTINGS FORM ─── -->' );
	$_GET = $get;
	$license_ui = ui();
	ob_start();
	eval( '?>' . substr( $src, $a, $b - $a ) );
	$html = ob_get_clean();
	$_GET = array();
	return trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $html ), ENT_QUOTES ) ) );
}
function table() { return $GLOBALS['TABLE'][ OPT ] ?? null; }

// ---- the licence server, played: its answers signed with the test key ------------------

const KID  = 'test-2026-11';
// The test key's secret, from the phrase the built-in test public key was made from.
$KP = sodium_crypto_sign_seed_keypair( hash( 'sha256', 'TCGiant Sync licence server: TEST signing key 2026-11, never used in production', true ) );
$SK = sodium_crypto_sign_secretkey( $KP );
// Another key, never built in.
$OTHER_SK = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( str_repeat( "\x07", 32 ) ) );

function b64u( $s ) { return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); }

/** An entitlement: the payload as the server builds it, signed so. */
function signed( array $p, $sk = null, $kid = KID, $tamper = null ) {
	$payload = b64u( json_encode( $p, JSON_UNESCAPED_SLASHES ) );
	$sig     = sodium_crypto_sign_detached( 'tcgiant-ent-v1.' . $payload, $sk ?: $GLOBALS['SK'] );
	if ( is_callable( $tamper ) ) {
		$payload = $tamper( $payload );
	}
	return array( 'v' => 1, 'kid' => $kid, 'payload' => $payload, 'sig' => b64u( $sig ) );
}
/** The payload the server would send for this request, then $over. */
function payload_for( $req, array $over = array() ) {
	return array_merge( array(
		'v' => 1, 'kid' => KID, 'hint' => strtolower( substr( (string) ( $req['key'] ?? KEY ), -4 ) ), 'status' => 'active', 'reason' => 'ok',
		'plan' => 'annual', 'paid_through' => null, 'instance' => (string) ( $req['instance'] ?? INST ), 'site' => 'shop.example',
		'iat' => time(), 'valid_until' => time() + 7 * DAY_IN_SECONDS, 'source' => 'ls', 'fallback' => 'ls',
		'nonce' => (string) ( $req['nonce'] ?? '' ), 'msg' => 'This licence is active.',
	), $over );
}
/** The server's answer to a validate: a closure, given the request as sent. */
function srv( array $over = array(), array $opts = array() ) {
	return function ( $req ) use ( $over, $opts ) {
		$p = payload_for( $req, $over );
		$e = signed( $p, $opts['sk'] ?? null, $opts['kid'] ?? KID, $opts['tamper'] ?? null );
		return reply( $opts['code'] ?? 200, array_merge( array( 'ok' => true, 'entitlement' => $e ), $opts['outer'] ?? array() ) );
	};
}
/** activate's answer: an instance issued. */
function srv_activated( $instance = 'aaaa1111-2222-4333-8444-555566667777', array $over = array() ) {
	return srv( array_merge( array( 'instance' => $instance ), $over ), array( 'outer' => array( 'instance' => $instance, 'activated' => true ) ) );
}
/** A held token: one the server signed earlier for this key, instance and site. */
function held_token( array $over = array() ) {
	$p = payload_for( array( 'key' => KEY, 'instance' => INST, 'nonce' => 'feedfacefeedfacefeedfacefeedface' ), $over );
	return array( 'token' => signed( $p ), 'token_until' => $p['valid_until'], 'answered_by' => 'tcgiant', 'fallback' => $p['fallback'] );
}
function srv_req( $i = 0 ) { return json_decode( $GLOBALS['SERVER_HTTP'][ $i ]['args']['body'] ?? 'null', true ); }
function unreachable() { return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to tcgiant.com port 443' ); }
/** One check now: the server answers so, then Lemon Squeezy, if asked. */
function ask2( $server, $ls = null ) {
	$GLOBALS['SERVER_REPLIES'] = array( $server );
	$GLOBALS['REPLIES']        = null === $ls ? array() : array( $ls );
	return lic()->validate_license();
}

$IS_ADMIN = true;
lic();

// =======================================================================================
echo "\nTHE TEST KEY, AND THE ONE BUILT IN\n" . str_repeat( '=', 140 ) . "\n";

check( 'the test key the harness signs with is built in (a copy with it added, once the owner\'s key replaced it)', TCGiant_Sync_License::SERVER_KEYS[ KID ] ?? null, base64_encode( sodium_crypto_sign_publickey( $KP ) ) );
check( 'the endpoints', TCGiant_Sync_License::SERVER_BASE, 'https://tcgiant.com/licence/v1' );
/** The real tools/check-version.php on a copy of this tree's files, dated, the key IDs as given. */
function check_version_with( $from, $to ) {
	$dir = sys_get_temp_dir() . '/tcg-cv-' . getmypid() . '-' . mt_rand();
	@mkdir( $dir . '/tools', 0777, true );
	@mkdir( $dir . '/includes', 0777, true );
	copy( $GLOBALS['ROOT'] . '/tools/check-version.php', $dir . '/tools/check-version.php' );
	foreach ( array( 'tcgiant-sync.php', 'readme.txt', 'changelog.txt' ) as $f ) {
		file_put_contents( $dir . '/' . $f, str_replace( 'YYYY-MM-DD', '2026-11-20', (string) file_get_contents( $GLOBALS['ROOT'] . '/' . $f ) ) );
	}
	// The class as a candidate is: the test key its only key. Once the
	// owner's real key has replaced it, the array is put back to that shape,
	// so every case below starts from the same place before and after.
	$lic = $GLOBALS['TEST_COPY'] ? preg_replace( '/const SERVER_KEYS = array\(.*?\);/s', "const SERVER_KEYS = array(\n\t\t'test-2026-11' => '" . $GLOBALS['TEST_PUB'] . "',\n\t);", $GLOBALS['LIC_SRC'], 1 ) : $GLOBALS['LIC_SRC'];
	file_put_contents( $dir . '/includes/class-tcgiant-sync-license.php', str_replace( $from, $to, $lic ) );
	$out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $dir . '/tools/check-version.php' ) . ' 2>&1', $out, $code );
	foreach ( array( 'tools/check-version.php', 'tcgiant-sync.php', 'readme.txt', 'changelog.txt', 'includes/class-tcgiant-sync-license.php' ) as $f ) {
		@unlink( $dir . '/' . $f );
	}
	@rmdir( $dir . '/tools' );
	@rmdir( $dir . '/includes' );
	@rmdir( $dir );
	return array( $code, implode( "\n", $out ) );
}
$cv = check_version_with( '@@none@@', '@@none@@' );
check( 'tools/check-version.php stops a release while a test- key is built in', array( $cv[0], false !== strpos( $cv[1], 'still the test key' ) ), array( 1, true ) );
$cv = check_version_with( "'test-2026-11' =>", "'k2026a' =>" );
check( '  and passes once the key is the owner\'s own', array( $cv[0], 0 === strpos( $cv[1], 'OK - version' ) ), array( 0, true ) );

// =======================================================================================
echo "\nA SIGNED ANSWER: VERIFIED, THEN ACTED ON\n" . str_repeat( '=', 140 ) . "\n";

fresh( array( 'variant' => 'monthly', 'expires_at' => iso( time() + 20 * DAY_IN_SECONDS ) ) );
$got = ask2( srv( array( 'plan' => 'lifetime', 'paid_through' => null ) ) );
$req = srv_req();
check( 'validate: tcgiant.com first, one request, Lemon Squeezy not asked', array( count( $SERVER_HTTP ), $SERVER_HTTP[0]['url'], count( $HTTP ) ), array( 1, 'https://tcgiant.com/licence/v1/validate.php', 0 ) );
check( '  JSON: the key and Lemon Squeezy\'s instance_id as stored, the home URL, version, edition, a nonce', array( array_keys( $req ), $req['key'], $req['instance'], $req['site'], $req['edition'], 1 === preg_match( '/^[0-9a-f]{32}$/', $req['nonce'] ) ), array( array( 'key', 'instance', 'site', 'version', 'edition', 'nonce' ), KEY, INST, 'https://shop.example', 'pro', true ) );
check( '  the headers and the 8 s timeout', array( $SERVER_HTTP[0]['args']['headers'], $SERVER_HTTP[0]['args']['timeout'] ), array( array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), 8 ) );
check( 'valid: Pro, the plan and the date from the answer, a day to the next check', array( $got, lic()->is_pro(), data()['status'], data()['variant'], data()['expires_at'], near( data()['next_check'], time() + DAY_IN_SECONDS ) ), array( true, true, 'active', 'lifetime', '', true ) );
check( '  recorded: who answered, its words, the signed answer and until when', array( data()['answered_by'], data()['server_status'], data()['server_reason'], data()['key_source'], data()['fallback'], array_keys( data()['token'] ), near( data()['token_until'], time() + 7 * DAY_IN_SECONDS ) ), array( 'tcgiant', 'active', 'ok', 'ls', 'ls', array( 'kid', 'payload', 'sig' ), true ) );
check( '  the instance stays Lemon Squeezy\'s', array( data()['instance_id'], data()['instance_from'] ?? '' ), array( INST, '' ) );
check( 'telemetry\'s license_type reads the same fields: active, the plan', array( data()['status'], data()['variant'] ), array( 'active', 'lifetime' ) );
$c = card();
check( '  the card names the server\'s status for the key, "active", not its reason "ok"', array( false !== strpos( $c, 'Its status for the key: active.' ), false === strpos( $c, 'Its status for the key: ok' ) ), array( true, true ) );

fresh();
ask2( srv( array( 'plan' => 'monthly', 'paid_through' => '2027-01-31T00:00:00Z' ) ) );
check( 'a monthly key: its plan and paid-through date', array( data()['variant'], data()['expires_at'], lic()->is_pro() ), array( 'monthly', '2027-01-31T00:00:00Z', true ) );
fresh( array( 'variant' => 'annual' ) );
ask2( srv( array( 'plan' => null ) ) );
check( 'no plan named: the stored one kept', data()['variant'], 'annual' );

echo "\n   Every way a 200 can fail its checks: unclear, nothing stored changes (a token held, so Lemon Squeezy is not asked)\n";
$bad = array(
	'signed with a key not built in'           => srv( array(), array( 'sk' => $OTHER_SK ) ),
	'a key ID not built in'                    => srv( array( 'kid' => 'k2099' ), array( 'kid' => 'k2099' ) ),
	'the payload changed after signing'        => srv( array( 'status' => 'expired' ), array( 'tamper' => function ( $p ) { return b64u( str_replace( '"expired"', '"active"', base64_decode( strtr( $p, '-_', '+/' ) ) ) ); } ) ),
	'the signature of another payload'         => function ( $req ) { $e = signed( payload_for( $req ) ); $o = signed( payload_for( $req, array( 'status' => 'expired' ) ) ); $o['sig'] = $e['sig']; return reply( 200, array( 'ok' => true, 'entitlement' => $o ) ); },
	'a signature cut short'                    => function ( $req ) { $e = signed( payload_for( $req, array( 'status' => 'expired' ) ) ); $e['sig'] = substr( $e['sig'], 0, 40 ); return reply( 200, array( 'ok' => true, 'entitlement' => $e ) ); },
	'protocol 2'                               => srv( array( 'v' => 2, 'status' => 'expired' ) ),
	'an inner key ID not the outer one'        => srv( array( 'kid' => 'other', 'status' => 'expired' ) ),
	'another key\'s last 4'                    => srv( array( 'hint' => 'ffff', 'status' => 'expired' ) ),
	'another instance'                         => srv( array( 'instance' => 'not-this-one', 'status' => 'expired' ) ),
	'another request\'s nonce (a replay)'      => srv( array( 'nonce' => 'feedfacefeedfacefeedfacefeedface', 'status' => 'expired' ) ),
	'another site'                             => srv( array( 'site' => 'other.example', 'status' => 'expired' ) ),
	'issued two days ahead of this clock'      => srv( array( 'iat' => time() + 2 * DAY_IN_SECONDS, 'status' => 'expired' ) ),
	'a status not in the protocol'             => srv( array( 'status' => 'revoked' ) ),
	'no entitlement'                           => reply( 200, array( 'ok' => true ) ),
	'the entitlement a string'                 => reply( 200, array( 'ok' => true, 'entitlement' => 'yes' ) ),
	'429 rate_limited'                         => reply( 429, array( 'ok' => false, 'error' => 'rate_limited' ) ),
	'500 unavailable'                          => reply( 500, array( 'ok' => false, 'error' => 'unavailable' ) ),
	'503 upstream'                             => reply( 503, array( 'ok' => false, 'error' => 'upstream' ) ),
	'400 bad_request'                          => reply( 400, array( 'ok' => false, 'error' => 'bad_request' ) ),
	'a 503 even with a signed "expired"'       => srv( array( 'status' => 'expired' ), array( 'code' => 503 ) ),
	'an HTML page'                             => reply( 200, '<html><body>Captive portal</body></html>' ),
	'an empty body'                            => reply( 200, '' ),
	'no answer'                                => unreachable(),
);
foreach ( $bad as $label => $r ) {
	fresh( held_token() );
	$before = array_intersect_key( data(), array_flip( array( 'status', 'variant', 'expires_at', 'key', 'instance_id', 'token' ) ) );
	ask2( $r );
	$after = array_intersect_key( data(), array_flip( array( 'status', 'variant', 'expires_at', 'key', 'instance_id', 'token' ) ) );
	check( sprintf( '%-40s unclear: unchanged, Pro, LS not asked', $label ), array( $after === $before, lic()->is_pro(), state(), data()['unclear_count'], count( $HTTP ) ), array( true, true, 'unclear', 1, 0 ) );
}
fresh( held_token() );
ask2( srv( array( 'hint' => 'ffff', 'status' => 'expired' ) ) );
check( 'the reason says which check failed', ui()['check']['unclear_reason'], 'TCGiant\'s licence server: HTTP 200, an answer about another key' );
fresh( held_token() );
ask2( srv( array(), array( 'sk' => $OTHER_SK ) ) );
check( '  a bad signature', ui()['check']['unclear_reason'], 'TCGiant\'s licence server: HTTP 200, a bad signature' );
check( '  logged once, naming the server', 0 === strpos( lines()[0] ?? '', 'Licence: the licence check said nothing for sure (TCGiant\'s licence server: HTTP 200, a bad signature), so nothing changes: the site stays on Pro.' ), true );

echo "\n   read_server_answer() and verify_entitlement() alone\n";
$expect = array( 'key' => KEY, 'instance' => INST, 'nonce' => 'n1', 'site' => 'shop.example' );
$good   = signed( payload_for( array( 'key' => KEY, 'instance' => INST, 'nonce' => 'n1' ) ) );
check( 'a good one: its payload', TCGiant_Sync_License::verify_entitlement( $good, $expect )['payload']['status'] ?? null, 'active' );
$flip = $good;
$flip['sig'] = b64u( substr_replace( base64_decode( strtr( $flip['sig'], '-_', '+/' ) ), chr( ord( base64_decode( strtr( $flip['sig'], '-_', '+/' ) )[10] ) ^ 1 ), 10, 1 ) );
check( 'one bit of the signature flipped: refused', TCGiant_Sync_License::verify_entitlement( $flip, $expect ), array( 'payload' => null, 'why' => 'a bad signature' ) );
check( 'the instance not compared when not given (activate)', TCGiant_Sync_License::verify_entitlement( signed( payload_for( array( 'key' => KEY, 'instance' => 'x', 'nonce' => 'n1' ) ) ), array_merge( $expect, array( 'instance' => null ) ) )['why'], '' );
check( 'the key compared without case', TCGiant_Sync_License::verify_entitlement( $good, array_merge( $expect, array( 'key' => strtoupper( KEY ) ) ) )['why'], '' );
check( 'the site as both sides compare it', array( TCGiant_Sync_License::norm_site( 'https://Shop.Example.com/store/' ), TCGiant_Sync_License::norm_site( 'http://shop.example:8080' ), TCGiant_Sync_License::norm_site( 'https://shop.example/' ) ), array( 'shop.example.com/store', 'shop.example:8080', 'shop.example' ) );
check( 'a signed "unknown" is definitive', TCGiant_Sync_License::read_server_answer( reply( 200, array( 'entitlement' => signed( payload_for( array( 'key' => KEY, 'instance' => INST, 'nonce' => 'n1' ), array( 'status' => 'unknown', 'reason' => 'key_unknown' ) ) ) ) ), $expect )['verdict'], 'invalid' );

// =======================================================================================
echo "\nA DEFINITIVE \"NO\" FROM EACH SOURCE\n" . str_repeat( '=', 140 ) . "\n";

foreach ( array( 'expired' => 'expired', 'disabled' => 'disabled', 'unknown' => 'key_unknown', 'unknown ' => 'foreign' ) as $status => $reason ) {
	fresh( held_token() );
	$got = ask2( srv( array( 'status' => trim( $status ), 'reason' => $reason, 'msg' => 'Not valid: ' . $reason . '.' ) ) );
	check( sprintf( 'the server says %-9s (%-11s): the free tier, "expired"', trim( $status ), $reason ), array( $got, lic()->is_pro(), data()['status'], state(), count( $HTTP ), near( data()['next_check'], time() + 6 * 3600 ) ), array( false, false, 'expired', 'expired', 0, true ) );
}
check( '  logged naming the server; a key it does not know: "check the key", no renewal (fix round 1, L7)', lines()[0] ?? null, 'Licence: TCGiant\'s licence server does not know this key as a TCGiant Sync licence (HTTP 200: unknown, foreign), so the site is on the free tier: new eBay listings are held back from import, and products already imported keep syncing. Check the key on the Settings screen.' );
$c = card( array( 'license_rechecked' => 'invalid' ) );
check( '  the card: the server\'s words, "Check the key", no "Renew it", no Lemon Squeezy', array( false !== strpos( $c, 'Not valid: TCGiant\'s licence server does not know this key as a TCGiant Sync licence (Not valid: foreign.). Check the key, then press Re-check.' ), false !== strpos( $c, 'Re-checked: TCGiant\'s licence server says this licence is not valid.' ), false === strpos( $c, 'Lemon Squeezy' ), false === strpos( $c, 'Renew it' ) ), array( true, true, true, true ) );
fresh( held_token() );
ask2( srv( array( 'status' => 'expired', 'reason' => 'expired', 'msg' => 'This licence has expired.' ) ) );
check( '  an expired key: "Renew it", the renewal line', array( false !== strpos( card(), 'Expired: TCGiant\'s licence server says this licence is not valid (This licence has expired.). Renew it' ), false !== strpos( lines()[0] ?? '', 'so a renewal brings Pro back' ) ), array( true, true ) );

foreach ( array( 'instance_unknown', 'instance_released' ) as $reason ) {
	fresh( held_token() );
	ask2( srv( array( 'status' => 'unknown', 'reason' => $reason, 'msg' => 'This site is not activated for the key.' ) ) );
	check( sprintf( 'the server says %s: "released", enter the key again', $reason ), array( lic()->is_pro(), state() ), array( false, 'released' ) );
}
check( '  logged as this site\'s activation', 0 === strpos( lines()[0] ?? '', 'Licence: TCGiant\'s licence server says this site\'s activation of the key is not valid (HTTP 200: unknown, instance_released)' ), true );
$c = card();
check( '  the card says so', false !== strpos( $c, 'This site\'s activation of the key is no longer valid at TCGiant\'s licence server (This site is not activated for the key.)' ), true );

fresh();
$got = ask2( unreachable(), reply( 200, array( 'valid' => false, 'error' => 'This license key has expired.', 'license_key' => array( 'status' => 'expired' ) ) ) );
check( 'Lemon Squeezy (the fallback) says expired: the free tier, as in 3.36.0', array( $got, lic()->is_pro(), data()['status'], data()['answered_by'], count( $SERVER_HTTP ), count( $HTTP ) ), array( false, false, 'expired', 'ls', 1, 1 ) );
$c = card();
check( '  the card names Lemon Squeezy', false !== strpos( $c, 'Expired: Lemon Squeezy says this licence is not valid (This license key has expired.).' ), true );

// =======================================================================================
echo "\nTCGIANT.COM UNREACHABLE: A TOKEN HELD, OR NOT\n" . str_repeat( '=', 140 ) . "\n";

fresh( held_token() );
$got = ask2( unreachable(), reply( 200, array( 'valid' => false, 'error' => 'expired', 'license_key' => array( 'status' => 'expired' ) ) ) );
check( 'a token held, unexpired: Pro holds, Lemon Squeezy NOT asked', array( $got, lic()->is_pro(), data()['status'], count( $HTTP ), state() ), array( true, true, 'active', 0, 'unclear' ) );
check( '  backs off an hour', near( data()['next_check'], time() + 3600 ), true );

fresh( held_token( array( 'valid_until' => time() - 60 ) ) );
$got = ask2( unreachable(), reply( 200, valid_body() ) );
$lsreq = $HTTP[0] ?? array();
check( 'its token expired offline: Lemon Squeezy asked, as 3.36.0 asks it', array( $got, count( $HTTP ), $lsreq['url'] ?? '', $lsreq['args']['body'] ?? '' ), array( true, 1, 'https://api.lemonsqueezy.com/v1/licenses/validate', '{"license_key":"' . KEY . '","instance_id":"' . INST . '"}' ) );
check( '  and its valid answer kept: Pro, answered by Lemon Squeezy', array( lic()->is_pro(), data()['answered_by'] ), array( true, 'ls' ) );

fresh( held_token( array( 'valid_until' => time() - 60 ) ) );
$got = ask2( unreachable(), reply( 503, '' ) );
check( '  both unclear: nothing changes, still Pro, both reasons kept', array( $got, lic()->is_pro(), ui()['check']['unclear_reason'] ), array( true, true, 'TCGiant\'s licence server: no answer: cURL error 7: Failed to connect to tcgiant.com port 443; Lemon Squeezy: HTTP 503, an empty reply' ) );

fresh( array_merge( held_token(), array( 'token' => array( 'kid' => KID, 'payload' => 'x', 'sig' => 'y' ), 'token_until' => time() + 9 * DAY_IN_SECONDS ) ) );
ask2( unreachable(), reply( 200, valid_body() ) );
check( 'a stored token that does not verify, its date ahead: not trusted, Lemon Squeezy asked', count( $HTTP ), 1 );

fresh( held_token( array( 'site' => 'old-domain.example' ) ) );
ask2( unreachable(), reply( 200, valid_body() ) );
check( 'a token for another site (the site moved): not trusted, Lemon Squeezy asked', count( $HTTP ), 1 );

fresh();
$got = ask2( unreachable(), reply( 200, valid_body() ) );
check( 'no token at all (an upgrade before its first answer): Lemon Squeezy asked', array( $got, count( $HTTP ), lic()->is_pro() ), array( true, 1, true ) );

fresh( held_token( array( 'valid_until' => time() - 60, 'fallback' => 'none' ) ) );
$got = ask2( unreachable(), reply( 200, array( 'valid' => false, 'license_key' => array( 'status' => 'expired' ) ) ) );
check( 'the server said fallback "none" (billing moved): Lemon Squeezy never asked, Pro holds', array( $got, count( $HTTP ), lic()->is_pro(), state() ), array( true, 0, true, 'unclear' ) );
fresh();
ask2( srv( array( 'fallback' => 'none', 'valid_until' => time() - 60 ) ) );
check( '  "none" in a signed answer is kept with it', array( data()['fallback'], data()['answered_by'], lic()->is_pro() ), array( 'none', 'tcgiant', true ) );
$got = ask2( unreachable(), reply( 200, array( 'valid' => false, 'license_key' => array( 'status' => 'expired' ) ) ) );
check( '  so, that answer past its date and the server down, Lemon Squeezy still not asked', array( count( $HTTP ), lic()->is_pro() ), array( 0, true ) );

fresh( array( 'key' => 'TCG1-ABCD-EFGH', 'instance_id' => 'aaaa1111-2222-4333-8444-555566667777', 'instance_from' => 'tcgiant' ) );
ask2( unreachable(), reply( 200, valid_body() ) );
check( 'a key that is not Lemon Squeezy\'s: never asked there', array( count( $HTTP ), lic()->is_pro() ), array( 0, true ) );

fresh( array( 'instance_id' => 'aaaa1111-2222-4333-8444-555566667777', 'instance_from' => 'tcgiant' ) );
ask2( unreachable(), reply( 200, valid_body() ) );
check( 'an instance the server issued: Lemon Squeezy asked with the key alone', $HTTP[0]['args']['body'] ?? '', '{"license_key":"' . KEY . '"}' );

// =======================================================================================
echo "\nAN UPGRADE FROM 3.36.0's RECORD: THE SAME KEY AND INSTANCE, NOTHING ASKED OF ANYBODY\n" . str_repeat( '=', 140 ) . "\n";

// The record exactly as 3.36.0 to 3.40.0 store it: none of 3.41.0's fields.
$GLOBALS['OPTIONS'] = $GLOBALS['TABLE'] = array();
fresh();
$old = array(
	'key' => KEY, 'status' => 'active', 'instance_id' => INST, 'customer_name' => 'A Customer', 'customer_email' => 'a@example.com',
	'variant' => 'lifetime', 'expires_at' => '', 'activated_at' => '2026-03-01 10:00:00',
	'checked_at' => time() - DAY_IN_SECONDS - 60, 'valid_at' => time() - DAY_IN_SECONDS - 60, 'asked_at' => time() - DAY_IN_SECONDS - 60, 'next_check' => time() - 60,
	'unclear_count' => 0, 'unclear_since' => 0, 'unclear_reason' => '', 'ls_status' => 'active', 'ls_error' => '',
	'store_id' => '335114', 'product_id' => '958193', 'variant_id' => '1505430', 'ids_noted' => '', 'lapse_noted' => 0,
);
update_option( OPT, $old );
check( 'before: Pro, on the stored record alone', lic()->is_pro(), true );
$SERVER_REPLIES = array( srv( array( 'plan' => 'lifetime' ) ) );
make_due();
do_action( 'tcgiant_sync_check_ended_listings' );
run_due( TCGiant_Sync_License::CHECK_HOOK );
$req = srv_req();
check( 'the background check asks tcgiant.com with the stored key and Lemon Squeezy\'s instance_id', array( count( $SERVER_HTTP ), $req['key'] ?? null, $req['instance'] ?? null ), array( 1, KEY, INST ) );
check( '  Pro, and nothing the customer typed or pressed', array( lic()->is_pro(), data()['status'], data()['key'], data()['instance_id'], data()['answered_by'], count( $HTTP ) ), array( true, 'active', KEY, INST, 'tcgiant', 0 ) );
check( '  its Lemon Squeezy fields kept for support', array( data()['store_id'], data()['variant_id'], data()['customer_name'] ), array( '335114', '1505430', 'A Customer' ) );
check( '  the next check a day on', count( queued( TCGiant_Sync_License::CHECK_HOOK ) ), 1 );

// =======================================================================================
echo "\nA KEY CHANGED WHILE THE SERVER WAS ASKED (changed_meanwhile())\n" . str_repeat( '=', 140 ) . "\n";

fresh( held_token() );
$b = array_merge( data(), array( 'key' => 'bbbbbbbb-1111-4222-8333-444455556666', 'instance_id' => 'instance-b', 'status' => 'active', 'token' => array() ) );
$DURING = function () use ( $b ) { $GLOBALS['DURING'] = null; elsewhere( OPT, $b ); };
$got = ask2( srv( array( 'status' => 'expired', 'reason' => 'expired' ) ) );
check( 'the old key\'s "expired" lands after key B: dropped, key B untouched', array( table(), $got ), array( $b, true ) );
check( '  one line, nothing asked of Lemon Squeezy', array( lines(), count( $HTTP ) ), array( array( 'Licence: the key on this site changed while TCGiant\'s licence server was being asked about the one before, so that answer is dropped and nothing is written.' ), 0 ) );
fresh( held_token() );
$DURING = function () { $GLOBALS['DURING'] = null; unset( $GLOBALS['TABLE'][ OPT ] ); };
ask2( srv() );
check( 'deactivated meanwhile: nothing written back', table(), null );

// =======================================================================================
echo "\nACTIVATION AND DEACTIVATION GO TO TCGIANT.COM\n" . str_repeat( '=', 140 ) . "\n";

$NEWK = 'cccccccc-1111-4222-8333-444455556666';
fresh( array( 'key' => '', 'status' => '', 'instance_id' => '' ) );
$SERVER_REPLIES = array( srv_activated( 'aaaa1111-2222-4333-8444-555566667777', array( 'plan' => 'annual', 'paid_through' => '2027-11-16T00:00:00Z' ) ) );
$REPLIES = array();
$res = lic()->activate_license( $NEWK );
$req = srv_req();
check( 'activate: tcgiant.com, the key, the home URL, version, edition, nonce, instance name', array( $SERVER_HTTP[0]['url'], array_keys( $req ), $req['key'], $req['site'], $req['instance_name'], $SERVER_HTTP[0]['args']['timeout'] ), array( 'https://tcgiant.com/licence/v1/activate.php', array( 'key', 'site', 'version', 'edition', 'nonce', 'instance_name' ), $NEWK, 'https://shop.example', 'shop-example', 15 ) );
check( '  activated: Pro, the server\'s instance, plan and date; Lemon Squeezy not asked', array( $res['success'] ?? null, lic()->is_pro(), data()['instance_id'], data()['instance_from'], data()['variant'], data()['expires_at'], count( $HTTP ) ), array( true, true, 'aaaa1111-2222-4333-8444-555566667777', 'tcgiant', 'annual', '2027-11-16T00:00:00Z', 0 ) );
check( '  its signed answer held, the next check queued a day on', array( ! empty( data()['token'] ), near( data()['next_check'], time() + DAY_IN_SECONDS ), count( queued( TCGiant_Sync_License::CHECK_HOOK ) ) ), array( true, true, 1 ) );

fresh( array( 'key' => '', 'status' => '', 'instance_id' => '' ) );
$SERVER_REPLIES = array( srv( array( 'status' => 'unknown', 'reason' => 'key_unknown', 'instance' => '', 'msg' => 'This key is not a TCGiant Sync licence.' ), array( 'outer' => array( 'instance' => '', 'activated' => false, 'error' => 'key_unknown', 'message' => 'This key is not a TCGiant Sync licence.' ) ) ) );
$res = lic()->activate_license( $NEWK );
check( 'a signed refusal: its words, nothing stored, Lemon Squeezy not asked', array( is_wp_error( $res ) ? $res->get_error_message() : null, data()['key'], count( $HTTP ) ), array( 'This key is not a TCGiant Sync licence.', '', 0 ) );

fresh( array( 'key' => '', 'status' => '', 'instance_id' => '' ) );
$SERVER_REPLIES = array( reply( 409, array( 'ok' => false, 'error' => 'activation_limit', 'message' => 'This key is already active on 1 site. <b>Deactivate</b> it there first.' ) ) );
$res = lic()->activate_license( $NEWK );
check( 'every seat taken (409): the server\'s words as text, nothing stored, Lemon Squeezy not asked', array( is_wp_error( $res ) ? $res->get_error_message() : null, data()['key'], count( $HTTP ) ), array( 'This key is already active on 1 site. Deactivate it there first.', '', 0 ) );

fresh( array( 'key' => '', 'status' => '', 'instance_id' => '' ) );
$SERVER_REPLIES = array( srv_activated( 'aaaa1111-2222-4333-8444-555566667777', array( 'instance' => 'another-one' ) ) );
$res = lic()->activate_license( $NEWK );
check( 'an activation answer about another instance than it returns: refused, nothing stored', array( is_wp_error( $res ), data()['key'] ), array( true, '' ) );

fresh( array( 'key' => '', 'status' => '', 'instance_id' => '' ) );
$SERVER_REPLIES = array( unreachable() );
$REPLIES = array( reply( 200, array( 'activated' => true, 'error' => null, 'license_key' => array( 'status' => 'active', 'expires_at' => null ), 'instance' => array( 'id' => 'ls-instance-1' ), 'meta' => array( 'store_id' => 335114, 'product_id' => 958193, 'variant_id' => 1505430, 'variant_name' => 'Founder\'s Lifetime' ) ) ) );
$res = lic()->activate_license( $NEWK );
check( 'the server unreachable, a Lemon Squeezy key: activated with Lemon Squeezy, as before', array( $res['success'] ?? null, $HTTP[0]['url'] ?? '', data()['instance_id'], data()['instance_from'], data()['variant'], data()['answered_by'] ), array( true, 'https://api.lemonsqueezy.com/v1/licenses/activate', 'ls-instance-1', 'ls', 'lifetime', 'ls' ) );
$SERVER_REPLIES = array( srv( array( 'plan' => 'lifetime' ) ) );
lic()->validate_license();
check( '  its next check moves it to the server, with that instance', array( srv_req( 1 )['instance'] ?? null, data()['answered_by'], lic()->is_pro() ), array( 'ls-instance-1', 'tcgiant', true ) );

fresh( array( 'key' => '', 'status' => '', 'instance_id' => '' ) );
$SERVER_REPLIES = array( unreachable() );
$res = lic()->activate_license( 'TCG1-ABCD-EFGH' );
check( 'the server unreachable, a key not Lemon Squeezy\'s: an error, Lemon Squeezy not asked', array( is_wp_error( $res ) ? $res->get_error_code() : null, count( $HTTP ) ), array( 'api_error', 0 ) );

fresh( array_merge( held_token(), array( 'instance_from' => '', 'server_reason' => 'ok', 'key_source' => 'ls' ) ) );
$SERVER_REPLIES = array( reply( 200, array( 'ok' => true, 'deactivated' => true ) ) );
$res = lic()->deactivate_license();
$req = srv_req();
check( 'deactivate: tcgiant.com with the key and instance; answered, so Lemon Squeezy not asked', array( $SERVER_HTTP[0]['url'], $req['key'], $req['instance'], count( $HTTP ), $res['success'] ?? null ), array( 'https://tcgiant.com/licence/v1/deactivate.php', KEY, INST, 0, true ) );
check( '  everything new cleared', array( data()['key'], data()['token'], data()['token_until'], data()['answered_by'], data()['instance_from'], data()['server_reason'], data()['key_source'], data()['fallback'] ), array( '', array(), 0, '', '', '', '', '' ) );
fresh();
$SERVER_REPLIES = array( unreachable() );
$REPLIES = array( reply( 200, array( 'deactivated' => true ) ) );
lic()->deactivate_license();
check( 'the server unreachable, Lemon Squeezy\'s instance: released there too, as before', array( $HTTP[0]['url'] ?? '', $HTTP[0]['args']['body'] ?? '', data()['key'] ), array( 'https://api.lemonsqueezy.com/v1/licenses/deactivate', '{"license_key":"' . KEY . '","instance_id":"' . INST . '"}', '' ) );
fresh( array( 'instance_id' => 'aaaa1111-2222-4333-8444-555566667777', 'instance_from' => 'tcgiant' ) );
$SERVER_REPLIES = array( unreachable() );
lic()->deactivate_license();
check( '  the server\'s own instance: Lemon Squeezy not asked, cleared here anyway', array( count( $HTTP ), data()['key'] ), array( 0, '' ) );

// =======================================================================================
echo "\nTHE WORDS\n" . str_repeat( '=', 140 ) . "\n";

fresh( held_token() );
ask2( srv( array( 'plan' => 'lifetime' ) ) );
$c = card( array( 'license_rechecked' => 'valid' ) );
check( 'the card after the server\'s answer: names it, says until when it holds offline, no Lemon Squeezy', array(
	false !== strpos( $c, 'Re-checked: TCGiant\'s licence server says this licence is valid.' ),
	false !== strpos( $c, 'Last answer from TCGiant\'s licence server:' ),
	false !== strpos( $c, 'Its signed answer holds offline until' ),
	false === strpos( $c, 'Lemon Squeezy' ),
), array( true, true, true, true ) );
$c = card( array( 'license_rechecked' => 'unclear' ) );
check( '  a Re-check with no answer', false !== strpos( $c, 'Re-checked: TCGiant\'s licence server could not be asked, or its answer said nothing for sure, so nothing changed.' ), true );

$src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-license.php' );
check( 'the lapse notice names the server', false !== strpos( $src, 'TCGiant\\\'s licence server, which checks TCGiant Sync Pro licences, has not confirmed this licence' ), true );

// =======================================================================================
echo "\nUNINSTALL\n" . str_repeat( '=', 140 ) . "\n";

$un = (string) file_get_contents( $ROOT . '/uninstall.php' );
check( 'the licence option and its check hook are on the uninstall list (3.41.0 stores nothing else)', array( false !== strpos( $un, "'tcgiant_sync_license'," ), false !== strpos( $un, "'tcgiant_sync_license_check'," ) ), array( true, true ) );

// =======================================================================================
echo "\nFIX ROUND 1 (H1, THE OWNER'S DECISION): ON THE FALLBACK, ONLY LEMON SQUEEZY'S EXPIRED OR DISABLED TAKES PRO AWAY\n" . str_repeat( '=', 140 ) . "\n";

$ls_shapes = array(
	// label => array( reply, Pro after )
	'404 "license_key not found", no key'          => array( reply( 404, array( 'valid' => false, 'error' => 'license_key not found.', 'license_key' => null, 'instance' => null, 'meta' => null ) ), true ),
	'400 a bare "valid": false'                    => array( reply( 400, array( 'valid' => false ) ), true ),
	'200 a bare "valid": false'                    => array( reply( 200, array( 'valid' => false ) ), true ),
	'404 "valid": false, license_key null'         => array( reply( 404, array( 'valid' => false, 'license_key' => null ) ), true ),
	'404 the instance not found, the key active'   => array( reply( 404, array( 'valid' => false, 'error' => 'license_key instance not found.', 'license_key' => array( 'status' => 'active' ) ) ), true ),
	'400 "expired" in its words, the key inactive' => array( reply( 400, array( 'valid' => false, 'error' => 'This license key has expired.', 'license_key' => array( 'status' => 'inactive' ) ) ), true ),
	'500 the key expired (not an answer code)'     => array( reply( 500, array( 'valid' => false, 'license_key' => array( 'status' => 'expired' ) ) ), true ),
	'429 the key disabled (not an answer code)'    => array( reply( 429, array( 'valid' => false, 'license_key' => array( 'status' => 'disabled' ) ) ), true ),
	'422 the key expired'                          => array( reply( 422, array( 'valid' => false, 'license_key' => array( 'status' => 'expired' ) ) ), false ),
	'200 the key expired'                          => array( reply( 200, array( 'valid' => false, 'error' => 'This license key has expired.', 'license_key' => array( 'status' => 'expired' ) ) ), false ),
	'400 the key disabled'                         => array( reply( 400, array( 'valid' => false, 'license_key' => array( 'status' => 'disabled' ) ) ), false ),
	'404 the key disabled'                         => array( reply( 404, array( 'valid' => false, 'error' => 'license_key not found.', 'license_key' => array( 'status' => 'disabled' ) ) ), false ),
	'200 "valid": true, the key disabled'          => array( reply( 200, valid_body( array( 'license_key' => array( 'status' => 'disabled' ) ) ) ), false ),
);
foreach ( $ls_shapes as $label => $shape ) {
	list( $r, $pro ) = $shape;
	fresh();
	ask2( unreachable(), $r );
	check( sprintf( 'the fallback, %-46s %s', $label, $pro ? 'unclear, still Pro' : 'definitive, the free tier' ), array( count( $HTTP ), lic()->is_pro(), data()['status'], state() ), $pro ? array( 1, true, 'active', 'unclear' ) : array( 1, false, 'expired', 'expired' ) );
}
fresh();
ask2( unreachable(), $ls_shapes['404 "license_key not found", no key'][0] );
check( '  "not found": both reasons kept, the key\'s record untouched', array( ui()['check']['unclear_reason'], data()['ls_status'] ?? '', data()['ls_error'] ?? '' ), array( 'TCGiant\'s licence server: no answer: cURL error 7: Failed to connect to tcgiant.com port 443; Lemon Squeezy: HTTP 404: license_key not found., "valid": false without the key expired or disabled', '', '' ) );
$c = card( array( 'license_rechecked' => 'unclear' ) );
check( '  a Re-check then names both', false !== strpos( $c, 'Re-checked: neither TCGiant\'s licence server nor Lemon Squeezy could be asked, or said anything for sure, so nothing changed.' ), true );

echo "\n   Activation with Lemon Squeezy (the server unreachable) still needs a real activation\n";
foreach ( array(
	'404 not found'              => array( reply( 404, array( 'activated' => false, 'error' => 'license_key not found.' ) ), 'Not activated: Lemon Squeezy says "license_key not found.". Check the key and try again.' ),
	'200 "activated": false'     => array( reply( 200, array( 'activated' => false, 'error' => 'This license key has reached the activation limit.' ) ), 'Not activated: Lemon Squeezy says "This license key has reached the activation limit.". Check the key and try again.' ),
	'200 "activated": "true"'    => array( reply( 200, array( 'activated' => 'true', 'instance' => array( 'id' => 'x' ) ) ), 'Not activated: the key could not be confirmed with Lemon Squeezy. Check the key and try again.' ),
	'503 HTML'                   => array( reply( 503, '<html>busy</html>' ), 'Not activated: the key could not be confirmed with Lemon Squeezy. Check the key and try again.' ),
) as $label => $case ) {
	fresh( array( 'key' => '', 'status' => '', 'instance_id' => '' ) );
	$SERVER_REPLIES = array( unreachable() );
	$REPLIES        = array( $case[0] );
	$res            = lic()->activate_license( 'cccccccc-1111-4222-8333-444455556666' );
	check( sprintf( '  %-26s not activated, its words, nothing stored', $label ), array( is_wp_error( $res ) ? $res->get_error_message() : null, data()['key'], lic()->is_pro() ), array( $case[1], '', false ) );
}

echo "\n   The upgrade path and changed_meanwhile() on the fallback\n";
// A record from before 3.36.0: the eight keys 3.34.0 kept.
$GLOBALS['OPTIONS'] = $GLOBALS['TABLE'] = array();
fresh();
update_option( OPT, array( 'key' => KEY, 'status' => 'active', 'instance_id' => INST, 'customer_name' => '', 'customer_email' => '', 'variant' => 'annual', 'expires_at' => '', 'activated_at' => '2025-12-01 10:00:00' ) );
$got = ask2( unreachable(), reply( 404, array( 'valid' => false, 'error' => 'license_key not found.', 'license_key' => null ) ) );
check( 'a pre-3.36.0 record, the server down, Lemon Squeezy "not found": still Pro, its key and instance kept', array( $got, lic()->is_pro(), data()['key'], data()['instance_id'], json_decode( $HTTP[0]['args']['body'], true ) ), array( true, true, KEY, INST, array( 'license_key' => KEY, 'instance_id' => INST ) ) );
$SERVER_REPLIES = array( srv( array( 'plan' => 'annual' ) ) );
lic()->validate_license();
check( '  and its next check, the server up: Pro, answered by the server, nothing typed', array( lic()->is_pro(), data()['answered_by'], data()['instance_id'] ), array( true, 'tcgiant', INST ) );
fresh();
$b = array_merge( data(), array( 'key' => 'bbbbbbbb-1111-4222-8333-444455556666', 'instance_id' => 'instance-b' ) );
$DURING = function () use ( $b ) {
	if ( 2 === count( $GLOBALS['HTTP'] ) + count( $GLOBALS['SERVER_HTTP'] ) ) {
		$GLOBALS['DURING'] = null;
		elsewhere( OPT, $b );
	}
};
ask2( unreachable(), reply( 200, array( 'valid' => false, 'license_key' => array( 'status' => 'expired' ) ) ) );
check( 'a key changed while Lemon Squeezy was asked (the fallback): its "expired" dropped, key B untouched', array( table(), in_array( 'Licence: the key on this site changed while Lemon Squeezy was being asked about the one before, so that answer is dropped and nothing is written.', lines(), true ) ), array( $b, true ) );

echo "\n   Deactivation and the instance's origin (L5)\n";
fresh();
$SERVER_REPLIES = array( reply( 200, array( 'ok' => true, 'deactivated' => false, 'error' => 'not_found' ) ) );
$REPLIES        = array( reply( 200, array( 'deactivated' => true ) ) );
lic()->deactivate_license();
check( 'the server does not hold it ("not_found"), Lemon Squeezy\'s instance: released at Lemon Squeezy too', array( $HTTP[0]['url'] ?? '', $HTTP[0]['args']['body'] ?? '', data()['key'] ), array( 'https://api.lemonsqueezy.com/v1/licenses/deactivate', '{"license_key":"' . KEY . '","instance_id":"' . INST . '"}', '' ) );
fresh( array( 'instance_id' => 'aaaa1111-2222-4333-8444-555566667777', 'instance_from' => 'tcgiant' ) );
$SERVER_REPLIES = array( reply( 200, array( 'ok' => true, 'deactivated' => false, 'error' => 'not_found' ) ) );
lic()->deactivate_license();
check( '  the server\'s own instance: Lemon Squeezy never asked', count( $HTTP ), 0 );
fresh( array( 'key' => '', 'status' => '', 'instance_id' => '' ) );
$SERVER_REPLIES = array( srv( array( 'instance' => INST ), array( 'outer' => array( 'instance' => INST, 'activated' => true, 'instance_origin' => 'ls' ) ) ) );
lic()->activate_license( KEY );
check( 'activation re-using this site\'s Lemon Squeezy instance: kept as Lemon Squeezy\'s', array( data()['instance_id'], data()['instance_from'] ), array( INST, 'ls' ) );
fresh( array( 'key' => '', 'status' => '', 'instance_id' => '' ) );
$SERVER_REPLIES = array( srv_activated() );
lic()->activate_license( KEY );
check( '  a new one the server issued (origin not said, or "tcg"): the server\'s', data()['instance_from'], 'tcgiant' );

echo "\n   The released card for the server's answer (L7)\n";
fresh( held_token() );
ask2( srv( array( 'status' => 'unknown', 'reason' => 'instance_released', 'msg' => 'This site was released.' ) ) );
$c = card();
check( 'the server released it: "from another site, or by TCGiant support", not "your order page"', array( false !== strpos( $c, 'It may have been released from another site, or by TCGiant support.' ), false === strpos( $c, 'order page' ) ), array( true, true ) );

echo "\n   tools/check-version.php: every key a real one (L6)\n";
foreach ( array(
	'no key at all'              => array( "'test-2026-11' => '" . $TEST_PUB . "',", '', 1, 'There is none.' ),
	'a key pasted short'         => array( "'test-2026-11' => '" . $TEST_PUB . "',", "'k2026a' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',", 1, 'k2026a' ),
	'not base64'                 => array( "'test-2026-11' => '" . $TEST_PUB . "',", "'k2026a' => 'not a key!',", 1, 'k2026a' ),
	'a TEST- key, any case'      => array( "'test-2026-11' =>", "'TEST-2026-11' =>", 1, 'still the test key' ),
	'an empty key ID'            => array( "'test-2026-11' =>", "'' =>", 1, '(no key ID)' ),
	'two good keys'              => array( "'test-2026-11' => '" . $TEST_PUB . "',", "'k2026a' => '" . $TEST_PUB . "',\n\t\t'k2027a' => '" . base64_encode( str_repeat( 'k', 32 ) ) . "',", 0, 'OK - version' ),
) as $label => $case ) {
	$cv = check_version_with( $case[0], $case[1] );
	check( sprintf( '  %-24s exit %d', $label, $case[2] ), array( $cv[0], false !== strpos( $cv[1], $case[3] ) ), array( $case[2], true ) );
}

echo "\n   WordPress's sodium_compat, loaded when PHP has no sodium (L8)\n";
$fake = sys_get_temp_dir() . '/tcg-wp-' . getmypid();
@mkdir( $fake . '/wp-includes/sodium_compat', 0777, true );
$case = $fake . '/case.json';
file_put_contents( $case, json_encode( array( 'ent' => signed( payload_for( array( 'key' => KEY, 'instance' => INST, 'nonce' => 'n2' ) ) ), 'expect' => array( 'key' => KEY, 'instance' => INST, 'nonce' => 'n2', 'site' => 'shop.example' ) ) ) );
$child = function () use ( $fake, $case, $ROOT ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( __DIR__ . '/support/licence-sodium-load.php' ) . ' ' . escapeshellarg( $ROOT ) . ' ' . escapeshellarg( $fake ) . ' ' . escapeshellarg( $case );
	return json_decode( (string) shell_exec( $cmd ), true );
};
$r = $child();
check( 'no sodium and no sodium_compat: unclear, "cannot check signatures"', array( $r['sodium_before'] ?? null, is_array( $r ) && array_key_exists( 'status', $r ) ? $r['status'] : 'x', $r['why'] ?? null ), array( false, null, 'this site cannot check signatures (no sodium)' ) );
// The stand-in for WordPress's autoload.php: the sodium functions, from the test shim.
file_put_contents( $fake . '/wp-includes/sodium_compat/autoload.php', "<?php\nrequire " . var_export( __DIR__ . '/support/ed25519-shim.php', true ) . ";\n" );
$r = $child();
check( 'no sodium, WordPress\'s wp-includes/sodium_compat/autoload.php there: loaded, the signature checked', array( $r['sodium_before'] ?? null, $r['loaded'] ?? null, $r['sodium_after'] ?? null, $r['status'] ?? null, $r['why'] ?? null ), array( false, true, true, 'active', '' ) );
@unlink( $fake . '/wp-includes/sodium_compat/autoload.php' );
@unlink( $case );
@rmdir( $fake . '/wp-includes/sodium_compat' );
@rmdir( $fake . '/wp-includes' );
@rmdir( $fake );

// =======================================================================================
echo "\nFIX ROUND 2\n" . str_repeat( '=', 140 ) . "\n";

echo "\n   L10: a test- key is trusted only in the tests\n";
check( 'here (the tests define TCGIANT_SYNC_LICENCE_TEST_KEYS): the test key is trusted', isset( TCGiant_Sync_License::server_keys()[ KID ] ), true );
$fake = sys_get_temp_dir() . '/tcg-rel-' . getmypid();
@mkdir( $fake . '/wp-includes/sodium_compat', 0777, true );
// Ed25519 for the child, as WordPress's sodium_compat would give it.
file_put_contents( $fake . '/wp-includes/sodium_compat/autoload.php', "<?php
require " . var_export( __DIR__ . '/support/ed25519-shim.php', true ) . ";
" );
$case = $fake . '/case.json';
file_put_contents( $case, json_encode( array( 'ent' => signed( payload_for( array( 'key' => KEY, 'instance' => INST, 'nonce' => 'n3' ) ) ), 'expect' => array( 'key' => KEY, 'instance' => INST, 'nonce' => 'n3', 'site' => 'shop.example' ) ) ) );
$r = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/licence-sodium-load.php' ) . ' ' . escapeshellarg( $ROOT ) . ' ' . escapeshellarg( $fake ) . ' ' . escapeshellarg( $case ) . ' release' ), true );
check( 'a release that slipped out with the test key: its answers are not trusted (unclear), never Pro', array( is_array( $r ) && array_key_exists( 'status', $r ) ? $r['status'] : 'x', $r['why'] ?? null ), array( null, 'signed with a key this version does not know (test-2026-11)' ) );
$r = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/licence-sodium-load.php' ) . ' ' . escapeshellarg( $ROOT ) . ' ' . escapeshellarg( $fake ) . ' ' . escapeshellarg( $case ) ), true );
check( '  the same answer where the tests trust the test key: verified', $r['status'] ?? null, 'active' );
@unlink( $case );
@unlink( $fake . '/wp-includes/sodium_compat/autoload.php' );
@rmdir( $fake . '/wp-includes/sodium_compat' );
@rmdir( $fake . '/wp-includes' );
@rmdir( $fake );

echo "\n   M5: Re-check asks the server to ask Lemon Squeezy again now\n";
fresh( held_token() );
$SERVER_REPLIES = array( srv() );
$went = null;
try {
	lic()->handle_recheck();
} catch ( Harness_Redirect $e ) {
	$went = $e->getMessage();
}
check( 'Re-check: the request carries recheck 1', array( srv_req()['recheck'] ?? null, false !== strpos( (string) $went, 'license_rechecked=valid' ) ), array( 1, true ) );
fresh( held_token() );
ask2( srv() );
check( '  the daily check does not', array_key_exists( 'recheck', srv_req() ), false );

echo "\n   L13: the plugin's own Lemon Squeezy sunset, 2027-04-01\n";
check( 'open until 2027-03-31 23:59:59 UTC, closed from 2027-04-01 00:00:00 UTC', array( TCGiant_Sync_License::ls_fallback_open( gmmktime( 23, 59, 59, 3, 31, 2027 ) ), TCGiant_Sync_License::ls_fallback_open( gmmktime( 0, 0, 0, 4, 1, 2027 ) ) ), array( true, false ) );
$until = new ReflectionProperty( 'TCGiant_Sync_License', 'ls_fallback_until' );
if ( PHP_VERSION_ID < 80100 ) {
	$until->setAccessible( true );
}
$until->setValue( null, time() - 1 );
fresh();
$got = ask2( unreachable(), reply( 200, array( 'valid' => false, 'license_key' => array( 'status' => 'expired' ) ) ) );
check( 'past it: the server down and no token, Lemon Squeezy NOT asked; unclear, Pro holds', array( $got, count( $HTTP ), lic()->is_pro(), state() ), array( true, 0, true, 'unclear' ) );
fresh( array( 'key' => '', 'status' => '', 'instance_id' => '' ) );
$SERVER_REPLIES = array( unreachable() );
$REPLIES        = array( reply( 200, array( 'activated' => true, 'instance' => array( 'id' => 'x' ) ) ) );
$res            = lic()->activate_license( 'cccccccc-1111-4222-8333-444455556666' );
check( '  and activation never goes to Lemon Squeezy: "could not reach the licence server"', array( is_wp_error( $res ) ? $res->get_error_code() : null, count( $HTTP ) ), array( 'api_error', 0 ) );
$until->setValue( null, null );

echo "\n   L11: a lifetime key on the fallback changes only on \"disabled\"\n";
fresh( array( 'variant' => 'lifetime', 'expires_at' => '' ) );
$got = ask2( unreachable(), reply( 200, array( 'valid' => false, 'license_key' => array( 'status' => 'expired' ) ) ) );
check( 'a lifetime key, Lemon Squeezy says expired: unclear, still Pro', array( $got, lic()->is_pro(), state() ), array( true, true, 'unclear' ) );
fresh( array( 'variant' => '', 'expires_at' => '' ) );
ask2( unreachable(), reply( 200, array( 'valid' => false, 'license_key' => array( 'status' => 'expired' ), 'meta' => array( 'variant_name' => 'Founder\'s Lifetime' ) ) ) );
check( '  its plan not stored, the answer names Founder\'s Lifetime: unclear', array( lic()->is_pro(), state() ), array( true, 'unclear' ) );
fresh( array( 'variant' => 'lifetime', 'expires_at' => '' ) );
ask2( unreachable(), reply( 200, array( 'valid' => false, 'license_key' => array( 'status' => 'disabled' ) ) ) );
check( '  disabled (a refund): the free tier', array( lic()->is_pro(), data()['status'] ), array( false, 'expired' ) );
fresh( array( 'variant' => 'monthly' ) );
ask2( unreachable(), reply( 200, array( 'valid' => false, 'license_key' => array( 'status' => 'expired' ) ) ) );
check( '  a monthly key, expired: the free tier, as before', lic()->is_pro(), false );

echo "\n   L17: check-version reads every real key, \"//\" and \"+\" in its base64 too\n";
$slash = '';
$plus  = '';
for ( $i = 0; $i < 20000 && ( '' === $slash || '' === $plus ); $i++ ) {
	$b = base64_encode( hash( 'sha256', 'k' . $i, true ) );
	if ( '' === $slash && false !== strpos( $b, '//' ) ) {
		$slash = $b;
	}
	if ( '' === $plus && false !== strpos( $b, '+' ) && false !== strpos( $b, '/' ) ) {
		$plus = $b;
	}
}
check( 'keys found with "//", and with "+" and "/"', array( false !== strpos( $slash, '//' ), false !== strpos( $plus, '+' ) ), array( true, true ) );
$cv = check_version_with( "'test-2026-11' => '" . $TEST_PUB . "',", "'k2026a' => '" . $slash . "',\n\t\t'k2026b' => '" . $plus . "', // the next one" );
check( '  both pass, with a comment after the comma', array( $cv[0], 0 === strpos( $cv[1], 'OK - version' ) ), array( 0, true ) );
$many = '';
for ( $i = 0; $i < 300; $i++ ) {
	$many .= "\t\t'k" . $i . "' => '" . base64_encode( hash( 'sha256', 'many' . $i, true ) ) . "',\n";
}
$cv = check_version_with( "'test-2026-11' => '" . $TEST_PUB . "',", "// the owner's keys\n" . $many );
check( '  300 generated keys at once, a comment line before them: all read', array( $cv[0], 0 === strpos( $cv[1], 'OK - version' ) ), array( 0, true ) );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
