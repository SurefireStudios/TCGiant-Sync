<?php
/**
 * Harness: licence robustness (3.36.0).
 *
 * A paying shop must never drop to the free tier because Lemon Squeezy gave
 * an unclear answer. Until 3.36.0 every reply to validate but "valid": true -
 * a 429, a 5xx, an HTML page, an empty body - marked the licence expired; a
 * stored renewal date ended Pro the moment it passed; the key was checked
 * only from admin pages, in the page, for 15 seconds, and on every admin
 * request once it read "expired"; and new eBay listings the free limit held
 * back during a lapse were never read again.
 *
 * Loaded for real: TCGiant_Sync_Entitlements, TCGiant_Sync_License and
 * TCGiant_Sync_Limit_Held. WordPress, Action Scheduler, Lemon Squeezy and the
 * importer's entry point are played here; every request to Lemon Squeezy is
 * recorded, and answered from a script. The importer's half - what the
 * delta, the full sync's scan and a queued import write down at the limit -
 * runs the real importer in support/limit-held-import.php.
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

/** Lemon Squeezy: every request recorded; answered from the script, in order. */
function wp_remote_post( $url, $args ) {
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
require $ROOT . '/includes/class-tcgiant-sync-license.php';

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

// The admin's own instance: built on an admin request.
$IS_ADMIN = true;
lic();

// =======================================================================================
echo "\nEVERY REPLY SHAPE: ONLY A DEFINITIVE ANSWER CHANGES WHAT IS STORED\n" . str_repeat( '=', 140 ) . "\n";

$keep = array( 'status', 'variant', 'expires_at', 'key', 'instance_id' );
$shapes = array(
	// label => array( reply, verdict wanted )
	'200 valid'                                   => array( reply( 200, valid_body() ), 'valid' ),
	'200 "valid": false (an expired key)'         => array( reply( 200, valid_body( array( 'valid' => false, 'error' => 'This license key has expired.', 'license_key' => array( 'status' => 'expired' ) ) ) ), 'invalid' ),
	'200 "valid": false, nothing more'            => array( reply( 200, array( 'valid' => false, 'error' => 'license_key is not valid.' ) ), 'invalid' ),
	'404 "valid": false (not found)'              => array( reply( 404, array( 'valid' => false, 'error' => 'license_key not found.', 'license_key' => null, 'instance' => null, 'meta' => null ) ), 'invalid' ),
	'400 "valid": false'                          => array( reply( 400, array( 'valid' => false, 'error' => 'This license key is disabled.' ) ), 'invalid' ),
	'422 with the key expired'                    => array( reply( 422, array( 'valid' => false, 'error' => 'Expired.', 'license_key' => array( 'status' => 'expired' ) ) ), 'invalid' ),
	'200 key disabled, even saying valid'         => array( reply( 200, valid_body( array( 'license_key' => array( 'status' => 'disabled' ) ) ) ), 'invalid' ),
	'422 "valid": false, a field of the request'  => array( reply( 422, array( 'valid' => false, 'error' => 'The license key field is required.' ) ), 'unclear' ),
	'429 Too Many Attempts'                       => array( reply( 429, array( 'error' => 'Too Many Attempts.' ) ), 'unclear' ),
	'429 even with "valid": false'                => array( reply( 429, array( 'valid' => false, 'error' => 'Too Many Attempts.' ) ), 'unclear' ),
	'500 JSON "valid": false'                     => array( reply( 500, array( 'valid' => false, 'error' => 'Server Error' ) ), 'unclear' ),
	'502 empty'                                   => array( reply( 502, '' ), 'unclear' ),
	'503 HTML page'                               => array( reply( 503, '<!DOCTYPE html><html><body>Service Unavailable</body></html>' ), 'unclear' ),
	'200 empty body'                              => array( reply( 200, '' ), 'unclear' ),
	'200 whitespace only'                         => array( reply( 200, "  \n" ), 'unclear' ),
	'200 not JSON'                                => array( reply( 200, 'OK' ), 'unclear' ),
	'200 an HTML page (a captive portal)'         => array( reply( 200, '<html><head><title>Login</title></head></html>' ), 'unclear' ),
	'200 JSON without "valid"'                    => array( reply( 200, array( 'error' => null, 'meta' => array() ) ), 'unclear' ),
	'200 an empty JSON array'                     => array( reply( 200, '[]' ), 'unclear' ),
	'200 a JSON string'                           => array( reply( 200, '"valid"' ), 'unclear' ),
	'200 "valid": "false" (a string)'             => array( reply( 200, array( 'valid' => 'false' ) ), 'unclear' ),
	'200 "valid": 0'                              => array( reply( 200, array( 'valid' => 0 ) ), 'unclear' ),
	'404 "valid": true'                           => array( reply( 404, array( 'valid' => true ) ), 'unclear' ),
	'WP_Error: could not resolve host'            => array( new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: api.lemonsqueezy.com' ), 'unclear' ),
	'WP_Error: a timeout'                         => array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 8001 milliseconds with 0 bytes received' ), 'unclear' ),
);
foreach ( $shapes as $label => $shape ) {
	list( $r, $want ) = $shape;
	check( sprintf( '%-44s read as', $label ), TCGiant_Sync_License::read_answer( $r )['verdict'], $want );
}

foreach ( $shapes as $label => $shape ) {
	list( $r, $want ) = $shape;
	fresh();
	$before = array_intersect_key( data(), array_flip( $keep ) );
	ask( $r );
	$after = array_intersect_key( data(), array_flip( $keep ) );
	if ( 'valid' === $want ) {
		check( sprintf( '%-44s Pro, active', $label ), array( lic()->is_pro(), data()['status'] ), array( true, 'active' ) );
	} elseif ( 'invalid' === $want ) {
		check( sprintf( '%-44s the free tier, "expired"', $label ), array( lic()->is_pro(), data()['status'], state() ), array( false, 'expired', 'expired' ) );
	} else {
		check( sprintf( '%-44s nothing stored changes, still Pro', $label ), array( $after === $before, lic()->is_pro(), state(), data()['unclear_count'] ), array( true, true, 'unclear', 1 ) );
	}
}

echo "\n   The request, as before but its timeout\n";
fresh();
ask( reply( 200, valid_body() ) );
$req = $HTTP[0];
check( 'validate: the same endpoint', $req['url'], 'https://api.lemonsqueezy.com/v1/licenses/validate' );
check( '  the same body, byte for byte', $req['args']['body'], '{"license_key":"' . KEY . '","instance_id":"' . INST . '"}' );
check( '  the same headers', $req['args']['headers'], array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ) );
check( '  waits 8 seconds, not 15', $req['args']['timeout'], 8 );
check( '  and nothing else in the request', array_keys( $req['args'] ), array( 'timeout', 'headers', 'body' ) );

echo "\n   What a valid answer re-reads, and keeps\n";
fresh( array( 'variant' => 'annual' ) );
ask( reply( 200, valid_body( array( 'meta' => array( 'variant_name' => 'Pro Monthly' ) ) ) ) );
check( 'the plan, re-read', data()['variant'], 'monthly' );
check( '  a renewal date not offered keeps the one stored', data()['expires_at'], iso( time() + 200 * DAY_IN_SECONDS ) );
$renews = iso( time() + 30 * DAY_IN_SECONDS );
ask( reply( 200, valid_body( array( 'license_key' => array( 'expires_at' => $renews ) ) ) ) );
check( '  one offered is stored', data()['expires_at'], $renews );
check( '  the next check a day on', near( data()['next_check'], time() + DAY_IN_SECONDS ), true );
check( '  its record: when, status, no run of unclear answers', array( near( data()['checked_at'], time() ), near( data()['valid_at'], time() ), data()['ls_status'], data()['unclear_count'] ), array( true, true, 'active', 0 ) );

echo "\n   What a definitive no records\n";
fresh();
ask( reply( 404, array( 'valid' => false, 'error' => 'license_key not found.', 'license_key' => null, 'meta' => null ) ) );
check( 'status "expired", Lemon Squeezy\'s words kept', array( data()['status'], data()['ls_error'] ), array( 'expired', 'license_key not found.' ) );
check( '  logged once, naming what it said', lines(), array( 'Licence: Lemon Squeezy says this licence is not valid (HTTP 404: license_key not found.), so the site is on the free tier: new eBay listings are held back from import, and products already imported keep syncing. It is checked again every 6 hours, so a renewal brings Pro back.' ) );
check( '  the plan and the date are left as they were', array( data()['variant'], data()['expires_at'] ), array( 'annual', iso( time() + 200 * DAY_IN_SECONDS ) ) );

// =======================================================================================
echo "\nUNCLEAR ANSWERS: AN HOUR, BACKING OFF TO 6, AND ONE LOG LINE A RUN\n" . str_repeat( '=', 140 ) . "\n";

check( 'the waits: 1, 2, 4, 6, 6, 6 hours', array_map( array( 'TCGiant_Sync_License', 'unclear_delay' ), array( 1, 2, 3, 4, 5, 50 ) ), array( 3600, 7200, 14400, 21600, 21600, 21600 ) );
check( '  never less than an hour', TCGiant_Sync_License::unclear_delay( 0 ), 3600 );

fresh();
$waits = array();
$start = time();
foreach ( array( 503, 429, 500, 502, 504 ) as $n => $code ) {
	ask( reply( $code, 'Service Unavailable' ) );
	$waits[] = (int) round( ( data()['next_check'] - time() ) / 60 );
	$q = queued( TCGiant_Sync_License::CHECK_HOOK );
	check( sprintf( '  answer %d: exactly one check queued, at the time it is due', $n + 1 ), array( count( $q ), $q ? $q[0]['when'] : null ), array( 1, data()['next_check'] ) );
}
check( 'five in a row: 60, 120, 240, 360 and 360 minutes', $waits, array( 60, 120, 240, 360, 360 ) );
check( '  still Pro throughout, and counted', array( lic()->is_pro(), data()['unclear_count'], near( data()['unclear_since'], $start ) ), array( true, 5, true ) );
check( '  one log line for the five', count( lines() ), 1 );
check( '  which says so', lines()[0] ?? null, 'Licence: Lemon Squeezy\'s answer said nothing for sure (HTTP 503, not a JSON object), so nothing changes: the site stays on Pro. It asks again in 60 minutes, then less often, up to every 6 hours, and says no more here until Lemon Squeezy answers.' );
check( '  the card says why, and since when', array( ui()['check']['unclear_reason'], ui()['check']['unclear_count'] ), array( 'HTTP 504, not a JSON object', 5 ) );
$LOG = array();
ask( reply( 200, valid_body() ) );
check( 'then a valid answer: the run ends, one line to say so', array( count( lines() ), 0 === strpos( lines()[0] ?? '', 'Licence: Lemon Squeezy answered again, after 5 checks since ' ) ), array( 1, true ) );
check( '  the count back to 0, the next check a day on', array( data()['unclear_count'], data()['unclear_since'], near( data()['next_check'], time() + DAY_IN_SECONDS ) ), array( 0, 0, true ) );
$LOG = array();
ask( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );
ask( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );
check( 'a new run: one line again, and an hour', array( count( lines() ), (int) round( ( data()['next_check'] - time() ) / 60 ) ), array( 1, 120 ) );
fresh( array( 'status' => 'expired' ) );
ask( reply( 503, '' ) );
check( 'a key already not valid: unclear answers wait 6 hours, not 1', (int) round( ( data()['next_check'] - time() ) / 60 ), 360 );
check( '  and it stays on the free tier', array( lic()->is_pro(), data()['status'] ), array( false, 'expired' ) );

// =======================================================================================
echo "\nAN EXPIRED LICENCE IS ASKED ABOUT AT MOST EVERY 6 HOURS\n" . str_repeat( '=', 140 ) . "\n";

fresh();
ask( reply( 200, array( 'valid' => false, 'error' => 'expired', 'license_key' => array( 'status' => 'expired' ) ) ) );
check( 'found not valid: the next check 6 hours on', near( data()['next_check'], time() + 6 * HOUR_IN_SECONDS ), true );
$HTTP = array();
$before_q = count( $QUEUE );
for ( $i = 0; $i < 40; $i++ ) {
	do_action( 'admin_init' );
	lic()->get_status_for_ui();
}
check( '40 admin requests, the dashboard\'s polls among them: no request to Lemon Squeezy', count( $HTTP ), 0 );
check( '  and nothing more queued', count( $QUEUE ), $before_q );
make_due();
$QUEUE = array();
for ( $i = 0; $i < 40; $i++ ) {
	do_action( 'admin_init' );
}
check( 'once 6 hours have passed: one check queued, however many pages load', count( queued( TCGiant_Sync_License::CHECK_HOOK ) ), 1 );
check( '  and still no request from the pages', count( $HTTP ), 0 );
$REPLIES = array( reply( 200, array( 'valid' => false, 'error' => 'expired', 'license_key' => array( 'status' => 'expired' ) ) ) );
$LOG     = array();
run_due();
check( 'the queued check asks once', count( $HTTP ), 1 );
check( '  a second "not valid" logs nothing new', lines(), array() );
check( '  and the next is 6 hours on again', near( data()['next_check'], time() + 6 * HOUR_IN_SECONDS ), true );

// =======================================================================================
echo "\nNO ADMIN PAGE WAITS ON LEMON SQUEEZY\n" . str_repeat( '=', 140 ) . "\n";

fresh();
make_due();
$HTTP = array();
$QUEUE = array();
do_action( 'admin_init' );
lic()->is_pro();
lic()->can_import();
lic()->get_status_for_ui();
check( 'a page load with a check due: no request to Lemon Squeezy', count( $HTTP ), 0 );
check( '  the check is queued in Action Scheduler instead', array_map( function ( $a ) { return array( $a['hook'], $a['async'], $a['group'] ); }, $QUEUE ), array( array( 'tcgiant_sync_license_check', true, 'tcgiant_sync_license' ) ) );
do_action( 'admin_init' );
check( '  and not queued twice', count( $QUEUE ), 1 );
delete_transient( TCGiant_Sync_License::QUEUED_TRANSIENT );
do_action( 'admin_init' );
check( '  nor after a quarter of an hour, while the queued one waits', count( $QUEUE ), 1 );
$waiting = $QUEUE;
$QUEUE   = array();
set_transient( TCGiant_Sync_License::QUEUED_TRANSIENT, time(), 15 * MINUTE_IN_SECONDS );
do_action( 'admin_init' );
check( '  and pages do not queue again within the quarter hour, whatever Action Scheduler says', count( $QUEUE ), 0 );
$QUEUE = $waiting;
check( 'admin_init has the queueing alone', array_map( function ( $h ) { return is_array( $h[0] ) ? $h[0][1] : '?'; }, $HOOKS['admin_init'] ?? array() ), array( 'maybe_revalidate' ) );
$REPLIES = array( reply( 200, valid_body() ) );
run_due();
check( 'Action Scheduler runs it: one request', count( $HTTP ), 1 );
check( '  and the next is queued a day on', array_map( function ( $a ) { return array( $a['hook'], near( $a['when'], time() + DAY_IN_SECONDS ) ); }, $QUEUE ), array( array( 'tcgiant_sync_license_check', true ) ) );
$src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-license.php' );
preg_match( '/function maybe_revalidate\(\)\s*\{(.*?)\n\t\}/s', $src, $m );
check( 'maybe_revalidate() itself never asks: no validate in it', array( false !== strpos( $m[1] ?? '', 'queue_check' ), false === strpos( $m[1] ?? '', 'validate_license' ), false === strpos( $m[1] ?? '', '$this->check()' ) ), array( true, true, true ) );

echo "\n   Not due: nothing at all\n";
fresh();
$HTTP = array();
$QUEUE = array();
do_action( 'admin_init' );
check( 'a key found valid an hour ago: nothing queued, nothing asked', array( count( $QUEUE ), count( $HTTP ) ), array( 0, 0 ) );
fresh( array( 'key' => '', 'status' => '' ) );
make_due();
$QUEUE = array();
do_action( 'admin_init' );
check( 'no key: nothing queued', count( $QUEUE ), 0 );

echo "\n   A licence 3.35.0 checked\n";
fresh();
$d = data();
unset( $d['next_check'], $d['asked_at'], $d['checked_at'], $d['valid_at'] );
update_option( OPT, $d );
set_transient( TCGiant_Sync_License::VALIDATION_TRANSIENT, 'valid', DAY_IN_SECONDS );
$QUEUE = array();
do_action( 'admin_init' );
check( 'checked by 3.35.0 within the day: not due yet', count( $QUEUE ), 0 );
delete_transient( TCGiant_Sync_License::VALIDATION_TRANSIENT );
do_action( 'admin_init' );
check( '  its day over: due, and queued', count( queued( TCGiant_Sync_License::CHECK_HOOK ) ), 1 );

// =======================================================================================
echo "\nTHE 14 DAYS PAST A RENEWAL DATE\n" . str_repeat( '=', 140 ) . "\n";

$now = time();
fresh( array( 'expires_at' => iso( $now - 13 * DAY_IN_SECONDS ), 'valid_at' => $now - 20 * DAY_IN_SECONDS, 'asked_at' => $now - 20 * DAY_IN_SECONDS, 'checked_at' => $now - 20 * DAY_IN_SECONDS ) );
check( 'renewal date 13 days past, not asked since: still Pro', array( lic()->is_pro(), state() ), array( true, 'renewing' ) );
check( '  until 14 days after the date', ui()['check']['grace_until'], $now - 13 * DAY_IN_SECONDS + 14 * DAY_IN_SECONDS );
$QUEUE = array();
do_action( 'admin_init' );
check( '  the date passed makes a check due, though next_check is a day off', count( queued( TCGiant_Sync_License::CHECK_HOOK ) ), 1 );
check( 'the old rule would have dropped it at the date: strtotime( expires_at ) < time()', strtotime( data()['expires_at'] ) < time(), true );

fresh( array( 'expires_at' => iso( $now - 14 * DAY_IN_SECONDS + 120 ), 'valid_at' => $now - 30 * DAY_IN_SECONDS ) );
check( '14 days less two minutes: Pro', lic()->is_pro(), true );
fresh( array( 'expires_at' => iso( $now - 14 * DAY_IN_SECONDS - 120 ), 'valid_at' => $now - 30 * DAY_IN_SECONDS ) );
check( '14 days and two minutes: the free tier', array( lic()->is_pro(), lic()->can_import(), state(), data()['status'] ), array( false, false, 'lapsed', 'active' ) );

echo "\n   Lapsed: said once, a notice, a Re-check\n";
fresh( array( 'expires_at' => iso( $now - 15 * DAY_IN_SECONDS ), 'valid_at' => $now - 45 * DAY_IN_SECONDS, 'asked_at' => $now - 45 * DAY_IN_SECONDS ) );
$QUEUE = array();
do_action( 'admin_init' );
$REPLIES = array( reply( 503, '' ) );
run_due();
check( 'an unclear answer leaves it on the free tier', array( lic()->is_pro(), data()['status'] ), array( false, 'active' ) );
$lapse = array_values( array_filter( lines(), function ( $l ) { return false !== strpos( $l, 'has not said this licence is valid in the 14 days' ); } ) );
check( '  the lapse said, once', count( $lapse ), 1 );
make_due();
$REPLIES = array( reply( 503, '' ) );
run_due();
$lapse = array_values( array_filter( lines(), function ( $l ) { return false !== strpos( $l, 'has not said this licence is valid in the 14 days' ); } ) );
check( '  and not again at the next check', count( $lapse ), 1 );
$SCREEN = (object) array( 'id' => 'tcgiant-sync_page_tcgiant-settings' );
ob_start();
do_action( 'admin_notices' );
$notice = ob_get_clean();
check( 'the notice: could not be checked, the free tier, a Re-check button', array(
	false !== strpos( $notice, 'your Pro licence could not be checked' ),
	false !== strpos( $notice, 'this site is on the free tier until it can be' ),
	false !== strpos( $notice, 'name="action" value="tcgiant_recheck_license"' ),
	false !== strpos( $notice, 'nonce-tcgiant_recheck_license' ),
	false !== strpos( $notice, 'Re-check now' ),
), array( true, true, true, true, true ) );
$SCREEN = (object) array( 'id' => 'dashboard' );
ob_start();
do_action( 'admin_notices' );
check( '  on this plugin\'s screens only', ob_get_clean(), '' );
$HELD_IDS = array( '410000000001', '410000000002' );
foreach ( $HELD_IDS as $id ) {
	TCGiant_Sync_Limit_Held::note( $id );
}
TCGiant_Sync_Limit_Held::flush();
$QUEUE = array();
$LOG   = array();
$REPLIES = array( reply( 200, valid_body() ) );
try {
	lic()->handle_recheck();
	$went = '';
} catch ( Harness_Redirect $e ) {
	$went = $e->getMessage();
}
check( 'Re-check: one request now, valid, back to Settings saying so', array( count( $HTTP ) > 0, $went ), array( true, 'https://shop.example/wp-admin/admin.php?page=tcgiant-settings&license_rechecked=valid#tc-license-section' ) );
check( '  Pro again, logged', array( lic()->is_pro(), in_array( 'Licence: Lemon Squeezy says this licence is valid, so the site is on Pro again.', lines(), true ) ), array( true, true ) );
check( '  and the listings held back meanwhile are queued for import', count( queued( TCGiant_Sync_Limit_Held::REPLAY_HOOK ) ), 1 );
$SCREEN = (object) array( 'id' => 'tcgiant-sync_page_tcgiant-settings' );
ob_start();
do_action( 'admin_notices' );
check( '  the notice gone', ob_get_clean(), '' );

echo "\n   Confirmed since the date\n";
fresh( array( 'expires_at' => iso( $now - 40 * DAY_IN_SECONDS ), 'valid_at' => $now - 13 * DAY_IN_SECONDS, 'asked_at' => $now - 13 * DAY_IN_SECONDS ) );
check( 'valid 13 days ago, its date 40 days past: Pro, nothing shown', array( lic()->is_pro(), state() ), array( true, 'ok' ) );
fresh( array( 'expires_at' => iso( $now - 40 * DAY_IN_SECONDS ), 'valid_at' => $now - 15 * DAY_IN_SECONDS, 'asked_at' => $now - 15 * DAY_IN_SECONDS ) );
check( '  valid 15 days ago, nothing definitive since: the free tier', lic()->is_pro(), false );

echo "\n   Never on unclear answers without a renewal date\n";
fresh( array( 'expires_at' => '', 'unclear_count' => 40, 'unclear_since' => $now - 60 * DAY_IN_SECONDS, 'valid_at' => $now - 61 * DAY_IN_SECONDS ) );
check( 'no renewal date, 60 days of unclear answers: Pro', array( lic()->is_pro(), state(), ui()['check']['grace_until'] ), array( true, 'unclear', 0 ) );
fresh( array( 'expires_at' => '' ) );
for ( $i = 0; $i < 12; $i++ ) {
	ask( reply( 500, '' ) );
}
check( '  twelve more: still Pro', lic()->is_pro(), true );
fresh( array( 'variant' => 'lifetime', 'expires_at' => iso( $now - 400 * DAY_IN_SECONDS ), 'valid_at' => $now - 400 * DAY_IN_SECONDS ) );
check( 'a lifetime plan with a date long past: Pro', lic()->is_pro(), true );
fresh( array( 'expires_at' => 'not a date' ) );
check( 'a date that cannot be read: Pro (it read as long past before)', array( lic()->is_pro(), false === strtotime( 'not a date' ) ), array( true, true ) );

echo "\n   The next check, just after a renewal date sooner than a day\n";
fresh();
$QUEUE = array();
$soon = time() + 2 * HOUR_IN_SECONDS;
ask( reply( 200, valid_body( array( 'license_key' => array( 'expires_at' => iso( $soon ) ) ) ) ) );
check( 'renewing in 2 hours: queued 5 minutes after it', array_map( function ( $a ) { return $a['when']; }, queued( TCGiant_Sync_License::CHECK_HOOK ) ), array( $soon + 300 ) );

// =======================================================================================
echo "\nA SITE NOBODY OPENS THE ADMIN OF IS CHECKED TOO\n" . str_repeat( '=', 140 ) . "\n";

$admin_hooks = $HOOKS;
$HOOKS       = array();
$IS_ADMIN    = false;
$cron        = new TCGiant_Sync_License();
check( 'a cron request: the hourly check and the check\'s own hook, no admin_init', array( isset( $HOOKS['tcgiant_sync_check_ended_listings'] ), isset( $HOOKS[ TCGiant_Sync_License::CHECK_HOOK ] ), isset( $HOOKS[ TCGiant_Sync_Limit_Held::REPLAY_HOOK ] ), isset( $HOOKS['admin_init'] ) ), array( true, true, true, false ) );
fresh();
make_due();
$QUEUE = array();
do_action( 'tcgiant_sync_check_ended_listings' );
check( 'the hourly check finds it due and queues it', count( queued( TCGiant_Sync_License::CHECK_HOOK ) ), 1 );
check( '  without asking itself', count( $HTTP ), 0 );
$REPLIES = array( reply( 200, valid_body() ) );
run_due();
check( 'Action Scheduler runs it', count( $HTTP ), 1 );
$q = queued( TCGiant_Sync_License::CHECK_HOOK );
check( '  the daily re-check queued in Action Scheduler', array( count( $q ), near( $q[0]['when'] ?? 0, time() + DAY_IN_SECONDS ) ), array( 1, true ) );
$REPLIES = array( reply( 200, valid_body() ) );
$cron->run_check();
$q = queued( TCGiant_Sync_License::CHECK_HOOK );
check( '  run early, or twice, it asks nothing, and the day\'s check stays queued', array( count( $HTTP ), count( $q ), near( $q[0]['when'] ?? 0, time() + DAY_IN_SECONDS ) ), array( 1, 1, true ) );
$REPLIES = array();
make_due();
$REPLIES = array( reply( 200, valid_body() ) );
run_due();
check( 'a day later it runs again, with no admin page and no hourly tick', count( $HTTP ), 2 );
$QUEUE = array();
$REPLIES = array( reply( 200, valid_body() ) );
do_action( 'tcgiant_sync_check_ended_listings' );
run_due();
check( 'not due: the hourly check asks nothing', count( $HTTP ), 2 );
$HOOKS    = $admin_hooks;
$IS_ADMIN = true;

// =======================================================================================
echo "\nTHE STORE AND THE PRODUCT A KEY BELONGS TO: RECORDED, AND ONLY LOGGED\n" . str_repeat( '=', 140 ) . "\n";

check( 'the owner\'s IDs are set: store 335114 ("TCGiant Sync"), product 958193', array( TCGiant_Sync_License::LS_STORE_ID, TCGiant_Sync_License::LS_PRODUCT_IDS ), array( 335114, array( 958193 ) ) );
check( '  and read through the filter, unfiltered', TCGiant_Sync_License::wanted_ids(), array( 'store_id' => 335114, 'product_ids' => array( 958193 ) ) );
fresh();
ask( reply( 200, valid_body() ) );
check( 'recorded from the answer', array( data()['store_id'], data()['product_id'], data()['variant_id'] ), array( '335114', '958193', '91011' ) );
check( '  shown in the card\'s details', array( ui()['check']['store_id'], ui()['check']['product_id'], ui()['check']['variant_id'] ), array( '335114', '958193', '91011' ) );
check( '  the owner\'s own store and product, by the shipped IDs: ok, nothing logged', array( ui()['check']['ids'], lines() ), array( 'ok', array() ) );
fresh();
ask( reply( 200, valid_body( array( 'meta' => array( 'store_id' => 1234, 'product_id' => 5678 ) ) ) ) );
check( 'a key of another store and product, by the shipped IDs: logged, Pro kept', array( lines(), lic()->is_pro(), data()['status'], ui()['check']['ids'] ), array( array( 'Licence: this key belongs to Lemon Squeezy store 1234, not 335114; product 5678, not 958193. Pro is kept on: this version only records it.' ), true, 'active', 'mismatch' ) );
// review4's H3: the same on activation.
fresh( array( 'key' => '', 'status' => '', 'instance_id' => '' ) );
$REPLIES = array( reply( 200, array( 'activated' => true, 'error' => null, 'license_key' => array( 'status' => 'active', 'expires_at' => null ), 'instance' => array( 'id' => 'other-store-instance' ), 'meta' => array( 'store_id' => 1234, 'product_id' => 5678, 'variant_id' => 1, 'variant_name' => 'Annual Plan', 'customer_name' => 'A', 'customer_email' => 'a@example.com' ) ) ) );
$res = lic()->activate_license( 'OTHER-STORE-KEY' );
check( 'activating a key of another store and product: activated, Pro, status active, one line', array( $res['success'] ?? null, lic()->is_pro(), data()['status'], lines(), ui()['check']['ids'] ), array( true, true, 'active', array( 'Licence: this key belongs to Lemon Squeezy store 1234, not 335114; product 5678, not 958193. Pro is kept on: this version only records it.' ), 'mismatch' ) );
fresh();
$FILTERS['tcgiant_sync_license_ids'] = function () { return array( 'store_id' => 0, 'product_ids' => array() ); };
ask( reply( 200, valid_body( array( 'meta' => array( 'store_id' => 1234, 'product_id' => 5678 ) ) ) ) );
check( 'none set (through the filter): the check is skipped, nothing logged, the IDs still recorded', array( ui()['check']['ids'], lines(), data()['store_id'] ), array( 'skipped', array(), '1234' ) );
fresh();
$FILTERS['tcgiant_sync_license_ids'] = function () { return array( 'store_id' => 335114, 'product_ids' => array( 999, 958193 ) ); };
ask( reply( 200, valid_body() ) );
check( 'the owner\'s store, one of the owner\'s products: ok, nothing logged', array( ui()['check']['ids'], lines() ), array( 'ok', array() ) );
$FILTERS['tcgiant_sync_license_ids'] = function () { return array( 'store_id' => 4321, 'product_ids' => array( 999 ) ); };
ask( reply( 200, valid_body() ) );
check( 'another store and product: logged, Pro kept', array( lines(), lic()->is_pro(), data()['status'] ), array( array( 'Licence: this key belongs to Lemon Squeezy store 335114, not 4321; product 958193, not 999. Pro is kept on: this version only records it.' ), true, 'active' ) );
check( '  the card says so', array( ui()['check']['ids'], ui()['check']['ids_noted'] ), array( 'mismatch', 'store 335114, not 4321; product 958193, not 999' ) );
ask( reply( 200, valid_body() ) );
check( '  the same again: not logged again', count( lines() ), 1 );
$FILTERS['tcgiant_sync_license_ids'] = function () { return array( 'store_id' => 0, 'product_ids' => array( 999 ) ); };
$LOG = array();
ask( reply( 200, valid_body() ) );
check( 'only the products set: the store is not compared', lines(), array( 'Licence: this key belongs to Lemon Squeezy product 958193, not 999. Pro is kept on: this version only records it.' ) );
ask( reply( 503, '' ) );
check( 'an unclear answer leaves the IDs as they were', array( data()['store_id'], data()['product_id'] ), array( '335114', '958193' ) );
ask( reply( 404, array( 'valid' => false, 'error' => 'license_key not found.', 'meta' => null ) ) );
check( '  so does an answer that names none', array( data()['store_id'], data()['product_id'], data()['variant_id'] ), array( '335114', '958193', '91011' ) );

echo "\n   The four plans of the owner's product, as its dashboard names them\n";
$detect = new ReflectionMethod( 'TCGiant_Sync_License', 'detect_variant' );
if ( PHP_VERSION_ID < 80100 ) {
	$detect->setAccessible( true );
}
$plans = array();
foreach ( array( 'TCGiant Sync Founder\'s Lifetime', 'Annual Plan', 'Monthly Plan', 'StackSocial' ) as $name ) {
	$plans[ $name ] = $detect->invoke( lic(), array( 'variant_name' => $name ), array( 'license_key' => array( 'expires_at' => null ) ) );
}
check( 'lifetime, annual, monthly - and StackSocial undecided (an open question: lifetime or not)', $plans, array( 'TCGiant Sync Founder\'s Lifetime' => 'lifetime', 'Annual Plan' => 'annual', 'Monthly Plan' => 'monthly', 'StackSocial' => '' ) );

// =======================================================================================
echo "\nLISTINGS THE FREE LIMIT HELD BACK: WRITTEN DOWN, CAPPED\n" . str_repeat( '=', 140 ) . "\n";

fresh( array( 'status' => 'expired' ) );
foreach ( array( '500000000001', 'not-an-id', '', '500000000002', ' 500000000003 ' ) as $id ) {
	TCGiant_Sync_Limit_Held::note( $id );
}
check( 'kept in the request until it ends (one shutdown write)', array( get_option( TCGiant_Sync_Limit_Held::LIMIT_HELD_OPTION, 'none' ), TCGiant_Sync_Limit_Held::count(), count( array_filter( $HOOKS['shutdown'] ?? array(), function ( $h ) { return array( 'TCGiant_Sync_Limit_Held', 'flush' ) === $h[0]; } ) ) ), array( 'none', 3, 1 ) );
TCGiant_Sync_Limit_Held::flush();
check( '  then written: eBay ItemIDs only, oldest first, not autoloaded', array( array_map( 'strval', array_keys( TCGiant_Sync_Limit_Held::all() ) ), $AUTOLOAD[ TCGiant_Sync_Limit_Held::LIMIT_HELD_OPTION ] ), array( array( '500000000001', '500000000002', '500000000003' ), false ) );
TCGiant_Sync_Limit_Held::note( '500000000001' );
TCGiant_Sync_Limit_Held::flush();
check( '  one held back again moves to the newest end', array_map( 'strval', array_keys( TCGiant_Sync_Limit_Held::all() ) ), array( '500000000002', '500000000003', '500000000001' ) );
$LOG = array();
for ( $n = 0; $n < 2050; $n++ ) {
	TCGiant_Sync_Limit_Held::note( (string) ( 600000000000 + $n ) );
}
TCGiant_Sync_Limit_Held::flush();
$all = array_map( 'strval', array_keys( TCGiant_Sync_Limit_Held::all() ) );
check( 'capped at 2,000, the newest kept', array( count( $all ), $all[0], end( $all ) ), array( 2000, '600000000050', '600000002049' ) );
check( '  the count dropped logged', $LOG, array( array( 'warning', 'Free licence limit: 53 of the oldest new eBay listings it held back were taken off the list kept for a move to Pro, which keeps the newest 2000. A full sync (Fetch Inventory) after the move imports every listing.' ) ) );

// =======================================================================================
echo "\n... AND IMPORTED ON A MOVE TO PRO, NOT WHEN A FREE SLOT FREES\n" . str_repeat( '=', 140 ) . "\n";

/** A free shop - its key found not valid - with 250 listings held back. */
function free_with_held( $n = 250 ) {
	fresh( array( 'status' => 'expired', 'next_check' => time() + 6 * HOUR_IN_SECONDS ) );
	for ( $i = 1; $i <= $n; $i++ ) {
		TCGiant_Sync_Limit_Held::note( (string) ( 700000000000 + $i ) );
	}
	TCGiant_Sync_Limit_Held::flush();
	$GLOBALS['LOG'] = array();
}
function held_count() { return count( TCGiant_Sync_Limit_Held::all() ); }

free_with_held();
$ACTIVE = 49;
for ( $i = 0; $i < 5; $i++ ) {
	do_action( 'admin_init' );
	do_action( 'tcgiant_sync_check_ended_listings' );
}
run_due();
check( 'a free shop at 49 of 50: can import, but nothing is replayed', array( lic()->can_import(), lic()->is_pro(), count( queued( TCGiant_Sync_Limit_Held::REPLAY_HOOK ) ), $SPECIFIC, held_count() ), array( true, false, 0, array(), 250 ) );
TCGiant_Sync_Limit_Held::replay();
TCGiant_Sync_Limit_Held::replay();
check( '  a replay run while free queues nothing, and says why once', array( $SPECIFIC, held_count(), count( lines() ) ), array( array(), 250, 1 ) );
ask( reply( 503, '' ) );
check( '  an unclear answer is no move to Pro', count( queued( TCGiant_Sync_Limit_Held::REPLAY_HOOK ) ), 0 );

free_with_held();
ask( reply( 200, valid_body() ) );
$q = queued( TCGiant_Sync_Limit_Held::REPLAY_HOOK );
check( 'a check that finds the key valid again: the replay queued, now', array( count( $q ), near( $q[0]['when'] ?? 0, time() ), $q[0]['group'] ?? '' ), array( 1, true, 'tcgiant_sync_license' ) );
check( '  and said', in_array( 'Licence: Pro (a licence check found it valid). The 250 new eBay listings the free limit held back are imported now, 100 at a time, within the day\'s eBay calls.', lines(), true ), true );
$LINKED = array( '700000000002', '700000000005' );
run_due( TCGiant_Sync_Limit_Held::REPLAY_HOOK, 1 );
$first = $SPECIFIC[0] ?? array();
check( 'the first run: 100 through start_specific_sync, oldest first, the linked left off', array( count( $SPECIFIC ), count( $first ), $first[0] ?? null, $first[1] ?? null, $first[99] ?? null, in_array( '700000000002', $first, true ) ), array( 1, 100, '700000000001', '700000000003', '700000000102', false ) );
check( '  the rest kept, the next run 15 minutes on', array( held_count(), near( queued( TCGiant_Sync_Limit_Held::REPLAY_HOOK )[0]['when'] ?? 0, time() + 900 ) ), array( 148, true ) );
check( '  logged', in_array( 'Licence: 100 new eBay listings the free limit held back queued for import (2 more already imported meanwhile, left off); 148 still to go.', lines(), true ), true );
make_due();
run_due( TCGiant_Sync_Limit_Held::REPLAY_HOOK, 1 );
make_due();
run_due( TCGiant_Sync_Limit_Held::REPLAY_HOOK, 1 );
check( 'three runs: all of them queued, nothing left, no run after', array( array_sum( array_map( 'count', $SPECIFIC ) ), held_count(), count( queued( TCGiant_Sync_Limit_Held::REPLAY_HOOK ) ) ), array( 248, 0, 0 ) );

free_with_held( 3 );
$REPLIES = array( reply( 200, array( 'activated' => true, 'error' => null, 'license_key' => array( 'status' => 'active', 'expires_at' => null ), 'instance' => array( 'id' => 'new-instance' ), 'meta' => array( 'store_id' => 335114, 'product_id' => 958193, 'variant_id' => 91011, 'variant_name' => 'Annual Plan', 'customer_name' => 'A', 'customer_email' => 'a@example.com' ) ) ) );
$HTTP = array();
lic()->activate_license( 'NEW-KEY-1234' );
check( 'activation: the same endpoint and body as before', array( $HTTP[0]['url'], $HTTP[0]['args']['body'], $HTTP[0]['args']['timeout'] ), array( 'https://api.lemonsqueezy.com/v1/licenses/activate', '{"license_key":"NEW-KEY-1234","instance_name":"shop-example"}', 15 ) );
check( '  a move to Pro: the replay queued', array( lic()->is_pro(), count( queued( TCGiant_Sync_Limit_Held::REPLAY_HOOK ) ) ), array( true, 1 ) );
check( '  its IDs recorded, its next check a day on', array( data()['store_id'], near( data()['next_check'], time() + DAY_IN_SECONDS ), count( queued( TCGiant_Sync_License::CHECK_HOOK ) ) ), array( '335114', true, 1 ) );
$QUEUE = array();
ask( reply( 200, valid_body() ) );
check( 'a valid answer while already Pro starts no replay', count( queued( TCGiant_Sync_Limit_Held::REPLAY_HOOK ) ), 0 );

echo "\n   Within the budget guards\n";
/** The replay, Pro, 150 held back, one run, under these conditions. */
function replay_under( array $budget, $over_time = '', $connected = true, $status = 'complete', $interval = 'tcgiant_hourly' ) {
	free_with_held( 150 );
	set( array( 'status' => 'active' ) );
	$GLOBALS['BUDGET']     = $budget;
	$GLOBALS['OVER_TIME']  = $over_time;
	$GLOBALS['CONNECTED']  = $connected;
	$GLOBALS['SYNC_STATE'] = array( 'status' => $status );
	$GLOBALS['SETTINGS']   = array( 'sync_interval' => $interval );
	$GLOBALS['QUEUE']      = array();
	TCGiant_Sync_Limit_Held::replay();
	$q = queued( TCGiant_Sync_Limit_Held::REPLAY_HOOK );
	return array( array_sum( array_map( 'count', $GLOBALS['SPECIFIC'] ) ), $q ? $q[0]['when'] - time() : null, count( lines() ) );
}
$tomorrow = ( intdiv( time(), DAY_IN_SECONDS ) + 1 ) * DAY_IN_SECONDS + 300 - time();
check( 'the day\'s calls used up: none queued, tomorrow 00:05 UTC, said once', replay_under( array( 'exhausted' => true ) ), array( 0, $tomorrow, 1 ) );
TCGiant_Sync_Limit_Held::replay();
check( '  a second run says nothing more', count( lines() ), 1 );
check( 'every shop under the delta\'s reserve (10,000): tomorrow', replay_under( array( 'remaining' => 9999 ) ), array( 0, $tomorrow, 1 ) );
check( '  at the reserve: it goes', replay_under( array( 'remaining' => 10000 ) )[0], 100 );
check( 'this site at half its own 1,000: tomorrow', replay_under( array( 'own' => 1000, 'count' => 500 ) ), array( 0, $tomorrow, 1 ) );
check( '  at 499: it goes', replay_under( array( 'own' => 1000, 'count' => 499 ) )[0], 100 );
check( 'the request over its time budget: none queued, a minute on', replay_under( array(), '40 s of this request used' ), array( 0, 60, 0 ) );
$GLOBALS['TICKS'] = 0;
check( '  over it after the first: that one queued, the rest a run later', replay_under( array(), function () { return ++$GLOBALS['TICKS'] > 1 ? 'over' : ''; } ), array( 1, 900, 1 ) );
check( 'not connected to eBay: an hour', replay_under( array(), '', false ), array( 0, 3600, 1 ) );
check( 'Emergency Stop pressed: an hour', replay_under( array(), '', true, 'stopped' ), array( 0, 3600, 1 ) );
check( 'automatic sync off: nothing, and no run after (Fetch Inventory imports them)', replay_under( array(), '', true, 'complete', 'disabled' ), array( 0, null, 1 ) );
check( '  the list kept for that', held_count(), 150 );

echo "\n   Deactivated mid-replay\n";
free_with_held( 150 );
set( array( 'status' => 'active' ) );
$REPLIES = array( reply( 200, array( 'deactivated' => true ) ) );
$HTTP = array();
lic()->deactivate_license();
check( 'deactivate: the same endpoint and body, the key and the checks cleared', array( $HTTP[0]['url'], $HTTP[0]['args']['body'], $HTTP[0]['args']['timeout'], data()['key'], data()['next_check'], data()['store_id'], count( queued( TCGiant_Sync_License::CHECK_HOOK ) ) ), array( 'https://api.lemonsqueezy.com/v1/licenses/deactivate', '{"license_key":"' . KEY . '","instance_id":"' . INST . '"}', 15, '', 0, '', 0 ) );
TCGiant_Sync_Limit_Held::replay();
check( '  the replay stops, the list kept for the next move to Pro', array( $SPECIFIC, held_count() ), array( array(), 150 ) );

// =======================================================================================
echo "\nTHE IMPORTER WRITES THEM DOWN (THE REAL IMPORTER, IN A PROCESS OF ITS OWN)\n" . str_repeat( '=', 140 ) . "\n";

$raw = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/limit-held-import.php' ) . ' 2>&1' );
$imp = json_decode( (string) $raw, true );
if ( ! is_array( $imp ) || ! isset( $imp['out'] ) ) {
	check( 'support/limit-held-import.php ran', substr( (string) $raw, 0, 400 ), 'JSON' );
	$imp = array( 'out' => array(), 'noise' => '' );
}
$o = $imp['out'];
check( 'it ran cleanly', $imp['noise'], '' );
check( 'the delta at the limit: each new listing written down, the linked one imported', array( $o['delta']['held'] ?? null, $o['delta']['saved'] ?? null ), array( array( '310000000002', '310000000003', '310000000004' ), array( '310000000001' ) ) );
check( '  no GetItem for those held back', $o['delta']['get_items'] ?? null, array( '310000000001' ) );
check( '  the log names the licence limit, not the category filter', $o['delta']['summary'] ?? null, 'Delta sync complete! 1 imported, 0 errors out of 4 modified items, 3 new held back by the free licence limit, 1 GetItem fallbacks.' );
check( '  the limit said once, as before', $o['delta']['limit_lines'] ?? null, 1 );
check( '  its status never "limit_reached": scanning, then complete', $o['delta']['status_writes'] ?? null, array( 'scanning', 'complete' ) );
check( '  the limit a flag of its own', $o['delta']['flag'] ?? null, true );
check( '  written once, as the request ends', $o['delta']['shutdown'] ?? null, true );
check( 'with a category filter: what the row or the memory puts in it, and nothing else', $o['delta_filter']['held'] ?? null, array( '320000000001', '320000000002' ) );
check( '  still no GetItem: the filter is asked at no call', $o['delta_filter']['get_items'] ?? null, array() );
check( '  all four held back, in the log', $o['delta_filter']['summary'] ?? null, 'Delta sync complete! 0 imported, 0 errors out of 4 modified items, 4 new held back by the free licence limit.' );
check( 'on Pro: imported, nothing held back or written down, no flag', array( $o['delta_pro']['saved'] ?? null, $o['delta_pro']['held'] ?? null, $o['delta_pro']['flag'] ?? null, $o['delta_pro']['shutdown'] ?? null ), array( array( '330000000001' ), array(), false, false ) );
check( 'the full sync\'s scan: written down after its category filter', $o['scan']['held'] ?? null, array( '340000000001', '340000000003' ) );
check( '  its own status "limit_reached", as before', in_array( 'limit_reached', $o['scan']['status_writes'] ?? array(), true ), true );
check( 'a queued import at the limit: written down, no GetItem', array( $o['import']['held'] ?? null, $o['import']['get_items'] ?? null ), array( array( '350000000001' ), array() ) );
check( 'a listing held back on two runs: once, at the newest end', $o['again']['held'] ?? null, array( '360000000002', '360000000001' ) );

// =======================================================================================
echo "\nTHE SCREENS SAY WHAT IS TRUE\n" . str_repeat( '=', 140 ) . "\n";

fresh();
check( 'the card\'s state: ok', state(), 'ok' );
ask( reply( 503, '' ) );
check( '  could not be checked, still Pro: "unclear"', array( state(), ui()['is_pro'] ), array( 'unclear', true ) );
fresh();
ask( reply( 200, array( 'valid' => false, 'license_key' => array( 'status' => 'expired' ) ) ) );
check( '  told it is not valid: "expired", the free tier', array( state(), ui()['is_pro'], ui()['plan'] ), array( 'expired', false, 'free' ) );
fresh( array( 'expires_at' => iso( time() - 20 * DAY_IN_SECONDS ), 'valid_at' => time() - 30 * DAY_IN_SECONDS ) );
check( '  14 days past its date unanswered: "lapsed"', state(), 'lapsed' );
fresh( array( 'key' => '', 'status' => '' ) );
check( '  no key: "none"', state(), 'none' );

$settings_view = (string) file_get_contents( $ROOT . '/admin/views/settings.php' );
check( 'the licence card: each state its own words', array(
	false !== strpos( $settings_view, "'unclear' === \$tc_lstate" ) && false !== strpos( $settings_view, 'Could not be checked since %1$s (%2$s), so it is still Pro. Next try: %3$s.' ),
	false !== strpos( $settings_view, "'renewing' === \$tc_lstate" ),
	false !== strpos( $settings_view, "'expired' === \$tc_lstate" ) && false !== strpos( $settings_view, 'Expired: Lemon Squeezy says this licence is not valid (%s).' ),
	false !== strpos( $settings_view, "'lapsed' === \$tc_lstate" ) && false !== strpos( $settings_view, 'Could not be checked: Lemon Squeezy has not confirmed this licence since its renewal date' ),
), array( true, true, true, true ) );
check( '  a Re-check button, with its nonce, when there is a key', array( false !== strpos( $settings_view, '<input type="hidden" name="action" value="tcgiant_recheck_license">' ), false !== strpos( $settings_view, "wp_nonce_field( 'tcgiant_recheck_license' )" ), false !== strpos( $settings_view, "! empty( \$license_ui['has_key'] ) && '' !== \$tc_lstate" ) ), array( true, true, true ) );
check( '  the details: the store, product and variant for support', false !== strpos( $settings_view, 'Lemon Squeezy store %1$s, product %2$s, variant %3$s.' ), true );
check( 'the Re-check handler is registered on admin requests', in_array( array( lic(), 'handle_recheck' ), array_map( function ( $h ) { return $h[0]; }, $HOOKS['admin_post_tcgiant_recheck_license'] ?? array() ), true ), true );

$import_view = (string) file_get_contents( $ROOT . '/admin/views/import.php' );
preg_match( '/<button[^>]*id="tc-fetch-btn"[^>]*>/', $import_view, $btn );
check( 'Fetch Inventory: not disabled by the limit', array( $btn[0] ?? '', false !== strpos( $btn[0] ?? '', 'disabled' ) ), array( '<button type="submit" class="tc-button full-width" id="tc-fetch-btn">', false ) );
$js = (string) file_get_contents( $ROOT . '/admin/assets/js/admin.js' );
check( '  nor by the dashboard\'s polling', array( false !== strpos( $js, "\$fetchBtn.prop('disabled', true)" ), false !== strpos( $js, '!lic.can_import' ) ), array( false, false ) );
check( '  the free-limit line says what Fetch Inventory still does', false !== strpos( $import_view, 'Free limit reached: Fetch Inventory still brings stock, prices and ended listings for the products you have.' ), true );
check( 'the listings held back are counted on the import screen', false !== strpos( $import_view, "(int) ( \$license_ui['held_back'] ?? 0 ) > 0" ), true );
check( '  and the card a delta at the limit leaves', false !== strpos( $import_view, "( ! empty( \$sync_state['limit_held'] ) && ! \$license_ui['can_import'] )" ), true );
$rest = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-rest.php' );
check( 'REST: the 402 no error could reach is gone, conflicts a 409', array( false !== strpos( $rest, '402 :' ), false !== strpos( $rest, "array( 'status' => 409 )" ) ), array( false, true ) );
$importer_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-importer.php' );
check( '  and no "limit_reached" error is made anywhere for it to read', preg_match( "/new WP_Error\(\s*'limit_reached'/", $importer_src ), 0 );
check( 'ui() keeps every key it had', array_values( array_diff( array( 'is_pro', 'plan', 'variant', 'active_count', 'limit', 'remaining', 'can_import', 'usage_pct', 'key_masked', 'customer_name', 'expires_at', 'has_key', 'status', 'upgrade_url', 'free_limit' ), array_keys( ui() ) ) ), array() );

// =======================================================================================
// =======================================================================================
echo "\nFIX ROUND 1: A KEY CHANGED WHILE LEMON SQUEEZY WAS ASKED (L1)\n" . str_repeat( '=', 140 ) . "\n";

/** Key B, as another request's activation leaves it: active, its own instance. */
function key_b() {
	return array_merge( data(), array(
		'key' => 'NEW-KEY-B', 'status' => 'active', 'instance_id' => 'instance-b',
		'variant' => 'annual', 'expires_at' => '', 'valid_at' => time(), 'checked_at' => time(), 'asked_at' => time(),
		'next_check' => time() + DAY_IN_SECONDS, 'unclear_count' => 0, 'unclear_since' => 0, 'ls_status' => 'active', 'ls_error' => '',
	) );
}
/** While the request is out, another request writes the record (the table only). */
function meanwhile( $record ) {
	$GLOBALS['DURING'] = function () use ( $record ) {
		$GLOBALS['DURING'] = null;
		elsewhere( OPT, $record );
	};
}
function table() { return $GLOBALS['TABLE'][ OPT ] ?? null; }

$not_found = reply( 404, array( 'valid' => false, 'error' => 'license_key not found.', 'license_key' => null, 'meta' => null ) );

fresh( array( 'status' => 'expired', 'next_check' => time() - 1 ) );
$b = key_b();
meanwhile( $b );
$LOG = array();
$got = ask( $not_found );
check( 'the old key\'s "no" lands after key B was activated: key B\'s record untouched', table(), $b );
check( '  nothing written, one line to say so; validate_license() answers for key B, on Pro', array( lines(), $got ), array( array( 'Licence: the key on this site changed while Lemon Squeezy was being asked about the one before, so that answer is dropped and nothing is written.' ), true ) );
check( '  this request\'s copy dropped too: it now reads key B, on Pro', array( data()['key'], lic()->is_pro() ), array( 'NEW-KEY-B', true ) );
new_request();
check( '  and the next request finds key B on Pro', array( data()['key'], data()['status'], lic()->is_pro() ), array( 'NEW-KEY-B', 'active', true ) );

fresh();
meanwhile( key_b() );
ask( reply( 503, '' ) );
check( 'an unclear answer about the old key: key B\'s count not touched', array( table()['key'], table()['unclear_count'], table()['next_check'] ), array( 'NEW-KEY-B', 0, $b['next_check'] ) );
fresh();
meanwhile( key_b() );
ask( reply( 200, valid_body( array( 'meta' => array( 'store_id' => 999 ) ) ) ) );
check( 'a valid answer about the old key: nothing of it written to key B', array( table()['key'], table()['store_id'] ?? '' ), array( 'NEW-KEY-B', '' ) );

fresh();
$OPTIONS[ OPT ] = array_merge( data(), array( 'key' => 'OLD-KEY-A', 'instance_id' => 'instance-a' ) );
$TABLE[ OPT ]   = key_b();
$LOG = array();
ask( $not_found );
check( 'a request whose own copy was older than the table from the start: dropped too', array( table()['key'], table()['status'], count( lines() ) ), array( 'NEW-KEY-B', 'active', 1 ) );

fresh();
meanwhile( array_merge( data(), array( 'key' => '', 'status' => '', 'instance_id' => '' ) ) );
ask( $not_found );
check( 'deactivated meanwhile: the answer dropped, the record left empty', array( table()['key'], table()['status'] ), array( '', '' ) );
fresh();
$DURING = function () { $GLOBALS['DURING'] = null; unset( $GLOBALS['TABLE'][ OPT ] ); };
ask( $not_found );
check( 'the record deleted meanwhile (an uninstall): dropped, nothing written back', array_key_exists( OPT, $TABLE ), false );

fresh( array( 'instance_id' => 'instance-a' ) );
meanwhile( array_merge( data(), array( 'key' => 'OTHER-KEY' ) ) );
ask( $not_found );
check( 'another key with the same instance ID: dropped (the key is compared too)', array( table()['key'], table()['status'] ), array( 'OTHER-KEY', 'active' ) );

fresh( array( 'instance_id' => 'instance-a' ) );
meanwhile( array_merge( data(), array( 'instance_id' => 'instance-a2' ) ) );
ask( $not_found );
check( 'the same key activated again (a new instance): dropped', array( table()['instance_id'], table()['status'] ), array( 'instance-a2', 'active' ) );

$now = time();
fresh( array( 'expires_at' => iso( $now - 20 * DAY_IN_SECONDS ), 'valid_at' => $now - 40 * DAY_IN_SECONDS, 'asked_at' => $now - 40 * DAY_IN_SECONDS ) );
// Key B lapsed as well, so a lapse note written after the drop would change it.
$b = array_merge( key_b(), array( 'expires_at' => iso( $now - 30 * DAY_IN_SECONDS ), 'valid_at' => $now - 30 * DAY_IN_SECONDS ) );
meanwhile( $b );
$QUEUE = array();
$LOG   = array();
$REPLIES = array( reply( 503, '' ) );
$cron->run_check();
check( 'the background check of a lapsed key, dropped: no lapse note written over key B', array( table(), count( array_filter( lines(), function ( $l ) { return false !== strpos( $l, 'in the 14 days' ); } ) ) ), array( $b, 0 ) );

fresh( array( 'expires_at' => iso( $now - 20 * DAY_IN_SECONDS ), 'valid_at' => $now - 40 * DAY_IN_SECONDS ) );
$b = array_merge( key_b(), array( 'expires_at' => iso( $now - 30 * DAY_IN_SECONDS ), 'valid_at' => $now - 30 * DAY_IN_SECONDS ) );
meanwhile( $b );
$REPLIES = array( $not_found );
try {
	lic()->handle_recheck();
	$went = '';
} catch ( Harness_Redirect $e ) {
	$went = $e->getMessage();
}
check( 'Re-check, dropped: back to Settings, key B untouched', array( $went, table() ), array( 'https://shop.example/wp-admin/admin.php?page=tcgiant-settings&license_rechecked=dropped#tc-license-section', $b ) );

echo "\n   Deactivate, the same guard\n";
fresh();
$b = key_b();
meanwhile( $b );
$QUEUE = array( array( 'hook' => TCGiant_Sync_License::CHECK_HOOK, 'args' => array(), 'group' => 'tcgiant_sync_license', 'when' => time() + DAY_IN_SECONDS, 'async' => false ) );
$REPLIES = array( reply( 200, array( 'deactivated' => true ) ) );
$LOG = array();
$res = lic()->deactivate_license();
check( 'key B activated while key A was being released: key B and its record kept', array( table(), $res['success'] ?? null, $res['message'] ?? null ), array( $b, true, 'That key was released. Another key activated on this site meanwhile is kept.' ) );
check( '  its next check still queued, one line said', array( count( queued( TCGiant_Sync_License::CHECK_HOOK ) ), lines() ), array( 1, array( 'Licence: the key was released at Lemon Squeezy, but another key was activated on this site while that was asked, so the new key and its record are kept.' ) ) );
check( 'activation reads nothing of the record before its request (its own key is what it writes)', 1 === preg_match( '/public function activate_license\(\$key\)\s*\{(?:(?!get_license_data).)*?wp_remote_post/s', (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-license.php' ) ), true );

// =======================================================================================
echo "\nFIX ROUND 1: A RELEASED ACTIVATION IS NOT AN EXPIRY (L2)\n" . str_repeat( '=', 140 ) . "\n";

$released = reply( 404, array( 'valid' => false, 'error' => 'license_key instance not found.', 'license_key' => array( 'id' => 1, 'status' => 'active', 'key' => KEY, 'expires_at' => null ), 'instance' => null, 'meta' => array( 'store_id' => 1234, 'product_id' => 5678, 'variant_id' => 91011 ) ) );
fresh();
$LOG = array();
ask( $released );
check( 'the key active, this site\'s activation gone: the free tier, "released"', array( lic()->is_pro(), data()['status'], state() ), array( false, 'expired', 'released' ) );
check( '  the log says so, and to enter the key again', lines(), array( 'Licence: Lemon Squeezy says this site\'s activation of the key is no longer valid (HTTP 404: license_key instance not found.), though the key itself is not expired or disabled, so the site is on the free tier: new eBay listings are held back from import, and products already imported keep syncing. Enter the key again on the Settings screen to activate it here.' ) );
fresh();
ask( reply( 404, array( 'valid' => false, 'error' => 'Not found.', 'license_key' => array( 'status' => 'inactive' ) ) ) );
check( 'the key "inactive" (no activations left), whatever the error says: "released"', state(), 'released' );
fresh();
ask( reply( 404, array( 'valid' => false, 'error' => 'license_key instance not found.', 'license_key' => null ) ) );
check( 'no key object, but the error names the instance: "released"', state(), 'released' );
fresh();
ask( reply( 200, array( 'valid' => false, 'error' => 'This license key instance has expired.', 'license_key' => array( 'status' => 'expired' ) ) ) );
check( 'a key Lemon Squeezy calls expired: "expired", whatever its error says', state(), 'expired' );
fresh();
ask( reply( 404, array( 'valid' => false, 'error' => 'license_key instance not found.', 'license_key' => array( 'status' => 'disabled' ) ) ) );
check( '  a key it calls disabled, its error naming only the instance: "expired"', state(), 'expired' );
fresh();
ask( reply( 400, array( 'valid' => false, 'error' => 'disabled', 'license_key' => array( 'status' => 'disabled' ) ) ) );
check( '  disabled: "expired"', state(), 'expired' );
fresh();
$LOG = array();
ask( $not_found );
check( 'an unknown key, no key object: "expired", the renewal wording', array( state(), false !== strpos( lines()[0] ?? '', 'so a renewal brings Pro back' ) ), array( 'expired', true ) );

/** The licence card from admin/views/settings.php, drawn alone as it is now. */
function card( array $get = array() ) {
	$src  = (string) file_get_contents( $GLOBALS['ROOT'] . '/admin/views/settings.php' );
	$a    = strpos( $src, '<!-- License -->' );
	$b    = strpos( $src, '<!-- ─── SETTINGS FORM ─── -->' );
	$_GET = $get;
	$license_ui = ui();
	$before = count( $GLOBALS['HTTP'] );
	ob_start();
	eval( '?>' . substr( $src, $a, $b - $a ) );
	$html = ob_get_clean();
	$_GET = array();
	$GLOBALS['CARD_ASKED'] = count( $GLOBALS['HTTP'] ) - $before;
	return trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $html ), ENT_QUOTES ) ) );
}
fresh();
ask( $released );
$c = card( array( 'license_rechecked' => 'invalid' ) );
check( 'the card, released: this site\'s activation, enter the key again, no "Renew it"', array(
	false !== strpos( $c, 'This site\'s activation of the key is no longer valid at Lemon Squeezy (license_key instance not found.), though the key itself has not expired.' ),
	false !== strpos( $c, 'Enter the key again, beside this, to activate it on this site.' ),
	false !== strpos( $c, 'Re-checked: Lemon Squeezy says this site\'s activation of the key is not valid.' ),
	false === strpos( $c, 'Renew it' ),
	false === strpos( $c, 'Expired:' ),
), array( true, true, true, true, true ) );
fresh();
ask( reply( 200, array( 'valid' => false, 'error' => 'This license key has expired.', 'license_key' => array( 'status' => 'expired' ) ) ) );
$c = card( array( 'license_rechecked' => 'invalid' ) );
check( 'the card, expired: "Expired ... Renew it", not the activation wording', array(
	false !== strpos( $c, 'Expired: Lemon Squeezy says this licence is not valid (This license key has expired.). Renew it, then press Re-check' ),
	false !== strpos( $c, 'Re-checked: Lemon Squeezy says this licence is not valid.' ),
	false === strpos( $c, 'Enter the key again' ),
), array( true, true, true ) );

// =======================================================================================
echo "\nFIX ROUND 1: THE RENEWAL DATE, AND CHECKS THAT HAVE STOPPED, ON THE CARD (L4)\n" . str_repeat( '=', 140 ) . "\n";

fresh();
$c = card();
check( 'the details: "Renews or ends:" and the stored date', false !== strpos( $c, 'Renews or ends: ' . gmdate( 'Y-m-d H:i', strtotime( data()['expires_at'] ) ) . '.' ), true );
fresh( array( 'expires_at' => '' ) );
check( '  none stored: "No end date."', array( false !== strpos( card(), 'No end date.' ), false !== strpos( card(), 'Renews or ends' ) ), array( true, false ) );
fresh( array( 'variant' => 'lifetime', 'expires_at' => iso( time() - 400 * DAY_IN_SECONDS ) ) );
check( '  a lifetime plan: "No end date."', false !== strpos( card(), 'No end date.' ), true );

fresh( array( 'asked_at' => time() - 2 * DAY_IN_SECONDS ) );
check( 'the last check 2 days ago: nothing said', array( ui()['check']['stale_since'], false !== strpos( card(), 'not running' ) ), array( 0, false ) );
$asked = time() - 3 * DAY_IN_SECONDS - 120;
fresh( array( 'asked_at' => $asked, 'next_check' => $asked + DAY_IN_SECONDS ) );
$c = card();
check( '3 days and two minutes: checks are not running, since when, and why', array(
	ui()['check']['stale_since'],
	false !== strpos( $c, 'Licence checks are not running: none has finished since ' . gmdate( 'Y-m-d H:i', $asked ) . '.' ),
	false !== strpos( $c, 'Action Scheduler, which WP-Cron starts' ),
	false !== strpos( $c, 'WooCommerce > Status > Scheduled Actions' ),
), array( $asked, true, true, true ) );
check( '  drawn only: the card asked Lemon Squeezy nothing', $CARD_ASKED, 0 );
fresh( array( 'status' => 'expired', 'asked_at' => $asked ) );
check( '  on the free tier too', false !== strpos( card(), 'Licence checks are not running' ), true );
fresh();
$d = data();
unset( $d['asked_at'], $d['checked_at'], $d['valid_at'], $d['next_check'] );
update_option( OPT, $d );
$QUEUE = array();
set_transient( TCGiant_Sync_License::VALIDATION_TRANSIENT, 'valid', DAY_IN_SECONDS );
check( 'a licence no check has finished for yet, nothing queued, checked by 3.34.0 within its day: nothing said', ui()['check']['stale_since'], 0 );
$waited = time() - 4 * DAY_IN_SECONDS;
$QUEUE = array( array( 'hook' => TCGiant_Sync_License::CHECK_HOOK, 'args' => array(), 'group' => 'tcgiant_sync_license', 'when' => $waited, 'async' => true ) );
check( '  its check queued 4 days ago and never run: not running, since then', array( ui()['check']['stale_since'], false !== strpos( card(), 'Licence checks are not running' ) ), array( $waited, true ) );
$QUEUE[0]['when'] = time() - DAY_IN_SECONDS;
check( '  queued a day ago: nothing said yet', ui()['check']['stale_since'], 0 );
fresh( array( 'key' => '', 'status' => '', 'asked_at' => time() - 30 * DAY_IN_SECONDS ) );
check( 'no key: nothing said', ui()['check']['stale_since'], 0 );

// =======================================================================================
echo "\nFIX ROUND 2: THE KEY UNCHANGED, EVERY WRITE MERGES ONTO THE TABLE'S RECORD (N1)\n" . str_repeat( '=', 140 ) . "\n";

// S1: renewed; a Re-check in another request found the key valid; a check
// already running, its copy still "expired", gets a 503.
fresh( array( 'status' => 'expired', 'ls_status' => 'expired', 'checked_at' => time() - 3600, 'next_check' => time() - 1 ) );
$valid_now = array_merge( data(), array( 'status' => 'active', 'ls_status' => 'active', 'valid_at' => time(), 'checked_at' => time(), 'asked_at' => time(), 'next_check' => time() + DAY_IN_SECONDS ) );
meanwhile( $valid_now );
ask( reply( 503, '' ) );
new_request();
check( 'S1: found valid meanwhile, then a 503 in a check that read "expired": still Pro', array( table()['status'], lic()->is_pro() ), array( 'active', true ) );
check( '  the 503 counted on the table\'s record, the next try an hour on (an active key\'s wait)', array( table()['unclear_count'], (int) round( ( table()['next_check'] - time() ) / 60 ) ), array( 1, 60 ) );

fresh( array( 'status' => 'expired', 'ls_status' => 'expired' ) );
$OPTIONS[ OPT ] = data();
$TABLE[ OPT ]   = array_merge( data(), array( 'status' => 'active', 'ls_status' => 'active', 'valid_at' => time() ) );
ask( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );
check( '  a copy older than the table from the start: the same', table()['status'], 'active' );

// S2: a definitive "no" written by a Re-check meanwhile; a check whose copy
// said active gets a 429.
fresh();
$no_now = array_merge( data(), array( 'status' => 'expired', 'ls_status' => 'expired', 'checked_at' => time(), 'asked_at' => time(), 'next_check' => time() + 6 * HOUR_IN_SECONDS ) );
meanwhile( $no_now );
ask( reply( 429, array( 'error' => 'Too Many Attempts.' ) ) );
new_request();
check( 'S2: a definitive "no" meanwhile, then a 429 in a check that read "active": still not Pro', array( table()['status'], lic()->is_pro() ), array( 'expired', false ) );
check( '  the next try 6 hours on (a key not valid)', (int) round( ( table()['next_check'] - time() ) / 60 ), 360 );

fresh( array( 'variant' => 'annual' ) );
$OPTIONS[ OPT ] = array_merge( data(), array( 'variant' => 'monthly', 'expires_at' => iso( time() + 20 * DAY_IN_SECONDS ) ) );
ask( reply( 500, '' ) );
check( 'an unclear answer never changes the plan or the date', array( table()['variant'], table()['expires_at'], table()['status'] ), array( 'annual', iso( time() + 200 * DAY_IN_SECONDS ), 'active' ) );

fresh( array( 'status' => 'expired', 'ls_status' => 'expired' ) );
$OPTIONS[ OPT ] = data();
$TABLE[ OPT ]   = array_merge( data(), array( 'status' => 'active', 'ls_status' => 'active', 'valid_at' => time() ) );
$LOG = array();
ask( reply( 200, array( 'valid' => false, 'error' => 'This license key has expired.', 'license_key' => array( 'status' => 'expired' ) ) ) );
check( 'a definitive "no" on a copy that read "expired": said, as the table was active', count( array_filter( lines(), function ( $l ) { return 0 === strpos( $l, 'Licence: Lemon Squeezy says this licence is not valid' ); } ) ), 1 );

fresh( array( 'unclear_count' => 3, 'unclear_since' => time() - 5 * HOUR_IN_SECONDS ) );
$OPTIONS[ OPT ] = array_merge( data(), array( 'unclear_count' => 0, 'unclear_since' => 0 ) );
$LOG = array();
ask( reply( 200, valid_body() ) );
check( 'Lemon Squeezy answering again is told from the table\'s count, not the copy\'s', count( array_filter( lines(), function ( $l ) { return 0 === strpos( $l, 'Licence: Lemon Squeezy answered again, after 3 checks' ); } ) ), 1 );

free_with_held( 3 );
$OPTIONS[ OPT ] = data();
$TABLE[ OPT ]   = array_merge( data(), array( 'status' => 'active', 'valid_at' => time() ) );
$QUEUE = array();
$LOG   = array();
ask( reply( 200, valid_body() ) );
check( 'already Pro in the table (a Re-check meanwhile): a valid answer is no move to Pro, nothing replayed again', array( count( queued( TCGiant_Sync_Limit_Held::REPLAY_HOOK ) ), in_array( 'Licence: Lemon Squeezy says this licence is valid, so the site is on Pro again.', lines(), true ) ), array( 0, false ) );

fresh( array( 'key' => '', 'status' => '', 'ids_noted' => '' ) );
$OPTIONS[ OPT ] = array_merge( data(), array( 'ids_noted' => 'store 1, not 2' ) );
// The IDs unset, so check_ids() writes nothing and only the merge decides.
$FILTERS['tcgiant_sync_license_ids'] = function () { return array( 'store_id' => 0, 'product_ids' => array() ); };
$REPLIES = array( reply( 200, array( 'activated' => true, 'error' => null, 'license_key' => array( 'status' => 'active' ), 'instance' => array( 'id' => 'i-new' ), 'meta' => array( 'variant_name' => 'Pro Annual' ) ) ) );
lic()->activate_license( 'KEY-AFTER' );
check( 'activation merges onto the table\'s record, not an older copy', array( table()['key'], table()['ids_noted'] ), array( 'KEY-AFTER', '' ) );

// =======================================================================================
echo "\nFIX ROUND 2: THE SMALL ITEMS\n" . str_repeat( '=', 140 ) . "\n";

fresh();
$LOG = array();
ask( reply( 404, array( 'valid' => false, 'error' => 'This license key has expired.', 'license_key' => array( 'status' => 'active' ) ) ) );
check( 'the error says the key expired, its status "active": "expired", and the renewal wording', array( state(), false !== strpos( lines()[0] ?? '', 'so a renewal brings Pro back' ) ), array( 'expired', true ) );
fresh();
ask( reply( 400, array( 'valid' => false, 'error' => 'This license key is disabled.', 'license_key' => array( 'status' => 'inactive' ) ) ) );
check( '  disabled, its status "inactive": "expired"', state(), 'expired' );

/** A licence 3.34.0 left: its eight keys and no more. */
function legacy( array $over = array() ) {
	fresh();
	update_option( OPT, array_merge( array(
		'key' => KEY, 'status' => 'active', 'instance_id' => INST, 'customer_name' => 'A', 'customer_email' => 'a@example.com',
		'variant' => 'annual', 'expires_at' => iso( time() + 200 * DAY_IN_SECONDS ), 'activated_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ),
	), $over ) );
	$GLOBALS['QUEUE'] = array();
}
legacy();
$activated = strtotime( data()['activated_at'] ); // read back, so a second ticking over between the two cannot fail it
check( 'never checked by 3.36.0, nothing queued, activated 4 days ago: checks are not running, since the activation', array( ui()['check']['stale_since'], false !== strpos( card(), 'Licence checks are not running: none has finished since ' . gmdate( 'Y-m-d H:i', $activated ) ) ), array( $activated, true ) );
set_transient( TCGiant_Sync_License::VALIDATION_TRANSIENT, 'valid', DAY_IN_SECONDS );
check( '  checked by 3.34.0 within its day: nothing said', ui()['check']['stale_since'], 0 );
legacy( array( 'activated_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
check( '  activated 2 days ago: nothing said', ui()['check']['stale_since'], 0 );
legacy();
$QUEUE = array( array( 'hook' => TCGiant_Sync_License::CHECK_HOOK, 'args' => array(), 'group' => 'tcgiant_sync_license', 'when' => time() - HOUR_IN_SECONDS, 'async' => true ) );
check( '  a check queued an hour ago: nothing said (it counts from that)', ui()['check']['stale_since'], 0 );
legacy( array( 'activated_at' => '' ) );
check( '  no activation date stored: nothing said', ui()['check']['stale_since'], 0 );

legacy( array( 'status' => 'expired' ) );
$c = card();
check( 'marked expired by 3.34.0, no reason stored: not checked since the update, a check queued', array(
	ui()['check']['reason_known'],
	false !== strpos( $c, 'Not checked since this version was installed: an earlier version marked this licence expired, which it also did when Lemon Squeezy could not be reached. A check is queued, or press Re-check.' ),
	false === strpos( $c, 'no reason given' ),
	false === strpos( $c, 'Expired:' ),
), array( false, true, true, true ) );
fresh();
ask( reply( 200, array( 'valid' => false, 'error' => 'This license key has expired.', 'license_key' => array( 'status' => 'expired' ) ) ) );
check( '  once Lemon Squeezy has said so: "Expired:", with its words', array( ui()['check']['reason_known'], false !== strpos( card(), 'Expired: Lemon Squeezy says this licence is not valid (This license key has expired.)' ) ), array( true, true ) );

// =======================================================================================
echo "\nFIX ROUND 3: THE CHECK ACTION READS THE TABLE'S RECORD; LEMON SQUEEZY'S BARE \"NO\"\n" . str_repeat( '=', 140 ) . "\n";

// review3's S5: this request's copy says lapsed, the lapse not noted, the
// check not due; a Re-check in another request has just found the key valid.
// The check action then runs in this, older, request.
$now = time();
fresh( array( 'expires_at' => iso( $now - 30 * DAY_IN_SECONDS ), 'valid_at' => $now - 20 * DAY_IN_SECONDS, 'asked_at' => $now - 3 * HOUR_IN_SECONDS, 'checked_at' => $now - 20 * DAY_IN_SECONDS, 'next_check' => $now + 3 * HOUR_IN_SECONDS, 'unclear_count' => 3, 'lapse_noted' => 0 ) );
new_request();
check( 'S5: this request\'s copy is lapsed, not due', array( lic()->is_pro(), $OPTIONS[ OPT ]['next_check'] > $now ), array( false, true ) );
$rechecked = array_merge( table(), array( 'valid_at' => $now, 'checked_at' => $now, 'asked_at' => $now, 'next_check' => $now + DAY_IN_SECONDS, 'unclear_count' => 0 ) );
elsewhere( OPT, $rechecked );
$HTTP = array();
$LOG  = array();
do_action( TCGiant_Sync_License::CHECK_HOOK );
check( '  the check action keeps the Re-check\'s record: no lapse noted onto the older copy, nothing asked', array( table(), count( $HTTP ), count( array_filter( lines(), function ( $l ) { return false !== strpos( $l, 'in the 14 days' ); } ) ) ), array( $rechecked, 0, 0 ) );
new_request();
check( '  and the site is on Pro', lic()->is_pro(), true );

// review3's F4: 3.36.0's own definitive "no" with neither status nor error.
fresh();
ask( reply( 400, array( 'valid' => false ) ) );
$c = card();
check( 'a bare {"valid": false} on a 400: "Expired" (Lemon Squeezy answered), not "not checked since the update"', array(
	ui()['check']['reason_known'],
	data()['ls_status'] . '|' . data()['ls_error'],
	false !== strpos( $c, 'Expired: Lemon Squeezy says this licence is not valid (no reason given).' ),
	false === strpos( $c, 'Not checked since this version was installed' ),
), array( true, '|', true, true ) );

echo "\nLITE AND STANDARD: UNCHANGED\n" . str_repeat( '=', 140 ) . "\n";

$base = new TCGiant_Sync_Entitlements();
$ACTIVE = 5000;
check( 'without a licence: may always import, unrestricted, no limit', array( $base->can_import(), $base->is_pro(), $base->get_free_limit() ), array( true, true, 0 ) );
check( '  its screen data: the same 15 keys, "unlimited", nothing of the checks', array( array_keys( $base->get_status_for_ui() ), $base->get_status_for_ui()['plan'] ), array( array( 'is_pro', 'plan', 'variant', 'active_count', 'limit', 'remaining', 'can_import', 'usage_pct', 'key_masked', 'customer_name', 'expires_at', 'has_key', 'status', 'upgrade_url', 'free_limit' ), 'unlimited' ) );
$ent_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-entitlements.php' );
check( '  and the class holds nothing of 3.36.0', array( false === strpos( $ent_src, 'Limit_Held' ), false === strpos( $ent_src, '3.36.0' ) ), array( true, true ) );
$tel = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-telemetry.php' );
check( 'telemetry\'s license_type as before: free, or the stored plan of an active key', array( false !== strpos( $tel, "\$license_type = 'free';" ), false !== strpos( $tel, "if ( ! empty( \$license_data['status'] ) && 'active' === \$license_data['status'] ) {" ), false !== strpos( $tel, "\$license_type = ! empty( \$license_data['variant'] ) ? \$license_data['variant'] : 'pro';" ) ), array( true, true, true ) );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
