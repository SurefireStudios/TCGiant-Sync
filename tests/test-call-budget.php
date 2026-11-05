<?php
/**
 * Harness: the day's eBay calls are counted on every host, and the fleet's
 * ceiling counts this site's own calls between relay refreshes.
 *
 * Every shop using the plugin shares one eBay daily allowance, and the 80%
 * ceiling (TCGiant_Sync_API::DAILY_CALL_SOFT_CEILING) is the only brake. Two
 * things kept it from seeing calls:
 *
 * - On a host with a persistent object cache - Redis, Memcached, LiteSpeed's,
 *   WordPress.com - the counter read 0 all day. It was read with
 *   get_transient(), which there reads only the cache, while the increment
 *   writes the wp_options row and empties the cache key. Those shops reported
 *   0 to the relay at every token refresh, and their own 40,000 fallback never
 *   tripped.
 * - Between refreshes, up to two hours, the check compared the relay's
 *   snapshot of every shop's calls with the ceiling and nothing else, so a
 *   busy site could spend thousands of calls with nothing counting them.
 *
 * Loaded for real: TCGiant_Sync_API, down to the HTTP call, answered in
 * eBay's own envelope, and TCGiant_Sync_OAuth's token refresh, answered by a
 * played relay. The database is SQLite (wp_options), and WordPress's option,
 * transient and object cache functions follow core's own logic: with the
 * cache "persistent" (wp_using_ext_object_cache() true) a transient lives in
 * the cache only and the cache outlives the request, as with Redis; without
 * it the cache is the per-request one and a transient is two option rows.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-84s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

if ( ! class_exists( 'SQLite3' ) ) {
	echo "  (SQLite3 is not available in this PHP: skipped)\n\n  0 passed, 0 failed\n";
	exit( 0 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'TCGIANT_SYNC_VERSION', '3.28.0' );

// The counter is by the UTC day. Run in a timezone whose date is not UTC's
// today, so a count kept by the local date would be read as 0.
date_default_timezone_set( (int) gmdate( 'G' ) >= 12 ? 'Pacific/Kiritimati' : 'Etc/GMT+12' );

$WORLD = str_replace( '\\', '/', sys_get_temp_dir() ) . '/tcg-call-budget-' . substr( md5( __FILE__ ), 0, 8 );
@mkdir( $WORLD, 0777, true );

$EXT      = false;     // A persistent object cache?
$CACHE    = array();   // The object cache: group => key => value.
$EBAY     = array();   // Every request that reached eBay: its call name.
$RELAY    = array();   // Every body posted to the relay.
$REPLY    = array();   // What the relay answers a refresh with.
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
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function wp_rand( $min = 0, $max = 0 ) { return $min; }
function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
function get_site_url() { return 'https://shop.example'; }

// ─── the database ─────────────────────────────────────────────────────────────

class Harness_Wpdb {
	public $options = 'wp_options';
	public $queries = array();
	/** @var callable|null Run before each statement, with it: another request's doing, in between. */
	public $before = null;
	/** @var SQLite3 */
	public $db;
	public function __construct( $file ) {
		$this->db = new SQLite3( $file );
		$this->db->busyTimeout( 5000 );
		$this->db->exec( 'PRAGMA journal_mode = WAL; PRAGMA synchronous = OFF;' );
	}
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%(%|d|s|f)/', function ( $m ) use ( &$args, &$i ) {
			if ( '%' === $m[1] ) {
				return '%';
			}
			$v = $args[ $i++ ] ?? null;
			return 'd' === $m[1] ? (string) (int) $v : "'" . SQLite3::escapeString( (string) $v ) . "'";
		}, $query );
	}
	private function run( $sql ) {
		$this->queries[] = $sql;
		if ( $this->before ) {
			$before = $this->before;
			$before( $sql );
		}
		// MySQL's own, in SQLite's words: an upsert, and LIKE's escape character.
		if ( preg_match( '/^(.*)ON DUPLICATE KEY UPDATE(.*)$/s', $sql, $m ) ) {
			$sql = $m[1] . 'ON CONFLICT(option_name) DO UPDATE SET' . preg_replace( '/VALUES\(\s*(\w+)\s*\)/', 'excluded.$1', $m[2] );
		}
		$sql = preg_replace( "/LIKE '((?:[^']|'')*)'/", "LIKE '$1' ESCAPE '\\\\'", $sql );
		$res = @$this->db->query( $sql );
		if ( false === $res ) {
			fwrite( STDERR, 'SQL error: ' . $this->db->lastErrorMsg() . "\n  in: {$sql}\n" );
		}
		return $res;
	}
	public function get_var( $sql ) {
		$res = $this->run( $sql );
		$row = $res ? $res->fetchArray( SQLITE3_NUM ) : false;
		return $row && null !== $row[0] ? (string) $row[0] : null;
	}
	public function query( $sql ) {
		return false === $this->run( $sql ) ? false : $this->db->changes();
	}
}

function fresh_site( $ext ) {
	global $wpdb, $WORLD;
	if ( isset( $wpdb ) ) {
		$wpdb->db->close();
	}
	foreach ( array( '', '-wal', '-shm' ) as $tail ) {
		@unlink( $WORLD . '/site.sqlite' . $tail );
	}
	$wpdb = new Harness_Wpdb( $WORLD . '/site.sqlite' );
	$wpdb->db->exec( 'CREATE TABLE wp_options ( option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT );' );
	$GLOBALS['EXT']   = $ext;
	$GLOBALS['CACHE'] = array();
	$GLOBALS['EBAY']  = array();
	$GLOBALS['RELAY'] = array();
	$GLOBALS['LOG']   = array();
	$GLOBALS['REPLY'] = array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 50000, 'global_api_calls' => 0, 'daily_limit' => 50000 );
	// Connected, with a token good for an hour.
	update_option( 'tcgiant_sync_ebay_settings', array(
		'access_token'  => 'token',
		'refresh_token' => 'refresh',
		'token_expiry'  => time() + HOUR_IN_SECONDS,
		'marketplace'   => 'EBAY_US',
	) );
}

/** The next request: the per-request cache goes with the last one; Redis keeps its own. */
function new_request() {
	if ( ! $GLOBALS['EXT'] ) {
		$GLOBALS['CACHE'] = array();
	}
}

/** The counter's row for a UTC day (today by default), as the table has it. */
function counter_row( $date = null ) {
	return $GLOBALS['wpdb']->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'tcgiant_sync_api_calls_" . ( $date ?? gmdate( 'Y-m-d' ) ) . "'" );
}

function set_counter_row( $n, $name = null ) {
	global $wpdb;
	$name = $name ?? 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' );
	$wpdb->query( "DELETE FROM wp_options WHERE option_name = '{$name}'" );
	$wpdb->query( "INSERT INTO wp_options ( option_name, option_value, autoload ) VALUES ( '{$name}', '" . (int) $n . "', 'no' )" );
}

/** Every option name in the table that holds a count, old or new. */
function count_rows() {
	$res = $GLOBALS['wpdb']->db->query( "SELECT option_name FROM wp_options WHERE option_name LIKE '%api_calls%' AND option_name <> 'tcgiant_sync_call_count_tidied' ORDER BY option_name" );
	$out = array();
	while ( $res && ( $row = $res->fetchArray( SQLITE3_NUM ) ) ) {
		$out[] = $row[0];
	}
	return $out;
}

// ─── WordPress's options, transients and object cache, as core runs them ──────

function wp_using_ext_object_cache() { return $GLOBALS['EXT']; }
function wp_cache_get( $key, $group = '' ) { return $GLOBALS['CACHE'][ $group ][ $key ] ?? false; }
function wp_cache_set( $key, $value, $group = '', $expire = 0 ) { $GLOBALS['CACHE'][ $group ][ $key ] = $value; return true; }
function wp_cache_add( $key, $value, $group = '', $expire = 0 ) {
	if ( isset( $GLOBALS['CACHE'][ $group ][ $key ] ) ) {
		return false;
	}
	return wp_cache_set( $key, $value, $group, $expire );
}
function wp_cache_delete( $key, $group = '' ) { unset( $GLOBALS['CACHE'][ $group ][ $key ] ); return true; }
function maybe_serialize( $v ) { return ( is_array( $v ) || is_object( $v ) ) ? serialize( $v ) : $v; }
function maybe_unserialize( $v ) {
	if ( is_string( $v ) && preg_match( '/^(a|O|s|i|b|d):/', $v ) ) {
		$u = @unserialize( $v );
		if ( false !== $u || 'b:0;' === $v ) {
			return $u;
		}
	}
	return $v;
}
/** The cache first, then the table; what the table had is kept in the cache. */
function get_option( $name, $default = false ) {
	global $wpdb;
	$value = wp_cache_get( $name, 'options' );
	if ( false === $value ) {
		$row = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM wp_options WHERE option_name = %s LIMIT 1', $name ) );
		if ( null === $row ) {
			return $default;
		}
		$value = maybe_unserialize( $row );
		wp_cache_add( $name, $value, 'options' );
	}
	return $value;
}
/** Refused when the option exists; otherwise core's INSERT ... ON DUPLICATE KEY UPDATE. */
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	global $wpdb;
	if ( false !== get_option( $name ) ) {
		return false;
	}
	$wpdb->query( $wpdb->prepare( 'INSERT OR REPLACE INTO wp_options ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )', $name, maybe_serialize( $value ), $autoload ? 'yes' : 'no' ) );
	wp_cache_set( $name, $value, 'options' );
	return true;
}
function update_option( $name, $value, $autoload = null ) {
	global $wpdb;
	if ( false === get_option( $name ) ) {
		return add_option( $name, $value );
	}
	$wpdb->query( $wpdb->prepare( 'UPDATE wp_options SET option_value = %s WHERE option_name = %s', maybe_serialize( $value ), $name ) );
	wp_cache_set( $name, $value, 'options' );
	return true;
}
function delete_option( $name ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM wp_options WHERE option_name = %s', $name ) );
	wp_cache_delete( $name, 'options' );
	return true;
}
/** With a persistent cache, a transient is in the cache and nowhere else. */
function get_transient( $transient ) {
	if ( wp_using_ext_object_cache() ) {
		return wp_cache_get( $transient, 'transient' );
	}
	$timeout = get_option( '_transient_timeout_' . $transient );
	if ( false !== $timeout && $timeout < time() ) {
		delete_option( '_transient_' . $transient );
		delete_option( '_transient_timeout_' . $transient );
		return false;
	}
	return get_option( '_transient_' . $transient );
}
function set_transient( $transient, $value, $expiration = 0 ) {
	if ( wp_using_ext_object_cache() ) {
		return wp_cache_set( $transient, $value, 'transient', $expiration );
	}
	if ( $expiration ) {
		update_option( '_transient_timeout_' . $transient, time() + $expiration );
	}
	return update_option( '_transient_' . $transient, $value );
}

// ─── eBay and the relay ───────────────────────────────────────────────────────

/**
 * eBay: every Trading call answered Success, in its own envelope - unless
 * $ANSWERS holds the next answer (3.31.0): 503, 429, 'transport' (nothing
 * came back) or 'token' (eBay refusing an expired token). A REST call is
 * answered in JSON. $DURING runs while a request is out.
 */
$ANSWERS = array();
$DURING  = null;
function wp_remote_request( $url, $args ) {
	$call             = $args['headers']['X-EBAY-API-CALL-NAME'] ?? '';
	$GLOBALS['EBAY'][] = '' !== $call ? $call : strtoupper( (string) ( $args['method'] ?? 'GET' ) ) . ' ' . preg_replace( '#^https://api\.ebay\.com/#', '', (string) strtok( $url, '?' ) );
	if ( is_callable( $GLOBALS['DURING'] ) ) {
		$during = $GLOBALS['DURING'];
		$during();
	}
	$answer = array_shift( $GLOBALS['ANSWERS'] );
	if ( 'transport' === $answer ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 45001 milliseconds' );
	}
	if ( 503 === $answer || 429 === $answer ) {
		return array( 'body' => '', 'response' => array( 'code' => $answer ), 'headers' => array() );
	}
	if ( '' === $call ) {
		$get = 'GET' === strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		return array( 'body' => $get ? '{"sku":"SKU-1"}' : '', 'response' => array( 'code' => $get ? 200 : 204 ), 'headers' => array() );
	}
	if ( 'token' === $answer ) {
		return array( 'body' => '<?xml version="1.0" encoding="UTF-8"?><' . $call . 'Response xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Failure</Ack><Errors><ShortMessage>Expired IAF token.</ShortMessage><LongMessage>IAF token supplied is expired.</LongMessage><ErrorCode>21917053</ErrorCode></Errors></' . $call . 'Response>', 'response' => array( 'code' => 200 ), 'headers' => array() );
	}
	$body = '<?xml version="1.0" encoding="UTF-8"?><' . $call . 'Response xmlns="urn:ebay:apis:eBLBaseComponents"><Timestamp>' . ( $GLOBALS['EBAY_TS'] ?? '2026-10-25T10:00:00.000Z' ) . '</Timestamp><Ack>Success</Ack>'
		. ( 'GetItem' === $call ? '<Item><ItemID>110000000001</ItemID><Quantity>3</Quantity></Item>' : '' )
		. '</' . $call . 'Response>';
	return array( 'body' => $body, 'response' => array( 'code' => 200 ), 'headers' => array() );
}
/** The relay: the refresh is answered with a token and the fleet's figures. */
function wp_remote_post( $url, $args ) {
	$GLOBALS['RELAY'][] = $args['body'];
	// The relay's answer taking its time: the clock moves on while it is out.
	if ( null !== $GLOBALS['OAUTH_NOW'] ) {
		$GLOBALS['OAUTH_NOW'] += $GLOBALS['RELAY_TAKES'];
	}
	return array( 'body' => json_encode( $GLOBALS['REPLY'] ), 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'application/json' ) );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $header ) { return ''; }
function wp_remote_retrieve_headers( $response ) { return $response['headers']; }
function wp_generate_password( $length = 12, $special = true ) { return str_repeat( 'x', $length ); }
function esc_xml( $text ) { return htmlspecialchars( (string) $text, ENT_XML1 ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
}

// The live shop: nothing is held back as from a staging copy.
class TCGiant_Sync_Environment {
	public static function write_block() { return null; }
	public static function note_blocked() {}
}

// The token refresh's clock: the real one, unless the harness stands it at
// $OAUTH_NOW - for a refresh sent just before UTC midnight and answered just
// after. Loaded with its one-argument gmdate() reading it.
$OAUTH_NOW   = null;
$RELAY_TAKES = 0;
function oauth_gmdate( $format ) { return gmdate( $format, null === $GLOBALS['OAUTH_NOW'] ? time() : (int) $GLOBALS['OAUTH_NOW'] ); }
// The token refresh reads and writes the settings through the inventory
// class (3.28.0, item 17): loaded for real.
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-inventory.php';
$OAUTH_COPY = $WORLD . '/oauth-' . getmypid() . '.php';
file_put_contents( $OAUTH_COPY, preg_replace(
	"/(?<![\\w>:$])gmdate\\(\\s*('[^']*'|\"[^\"]*\")\\s*\\)/",
	'oauth_gmdate($1)',
	(string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-oauth.php' )
) );
require $OAUTH_COPY;
@unlink( $OAUTH_COPY );
// The API class's clock likewise: the real one unless $API_NOW stands it at
// a time - for a server clock that jumps ahead for one call.
$API_NOW = null;
function api_now() { return null === $GLOBALS['API_NOW'] ? time() : (int) $GLOBALS['API_NOW']; }
function api_gmdate( $format ) { return gmdate( $format, api_now() ); }
$API_COPY = $WORLD . '/api-' . getmypid() . '.php';
file_put_contents( $API_COPY, preg_replace(
	array( '/(?<![\w>:$])time\(\s*\)/', "/(?<![\w>:$])gmdate\(\s*('[^']*'|\"[^\"]*\")\s*\)/" ),
	array( 'api_now()', 'api_gmdate($1)' ),
	(string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-api.php' )
) );
require $API_COPY;
@unlink( $API_COPY );

$api = TCGiant_Sync_API::instance();

/** $n GetItem calls, each in a request of its own. */
function calls( $n ) {
	for ( $i = 0; $i < $n; $i++ ) {
		new_request();
		TCGiant_Sync_API::instance()->get_item( '110000000001' );
	}
}

/** The relay's snapshot as this site holds it. */
function snapshot() {
	return get_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ) );
}

function refresh() {
	new_request();
	return TCGiant_Sync_OAuth::instance()->refresh_access_token();
}

/** A GetItem the ceiling stops comes back as the plugin's own budget refusal, unsent. */
function blocked( $result ) {
	return is_wp_error( $result ) && 'rate_limited' === $result->get_error_code() && ! empty( $result->get_error_data()['daily_budget'] );
}

function logged( $needle ) {
	foreach ( $GLOBALS['LOG'] as $entry ) {
		if ( false !== strpos( $entry[1], $needle ) ) {
			return true;
		}
	}
	return false;
}

// One of several PHP processes counting into the same database at once, as a
// host's cron, Action Scheduler and web requests do (3.31.0). Run by the
// concurrency check below, never on its own.
if ( isset( $argv[1] ) && 'worker' === $argv[1] ) {
	$wpdb = new Harness_Wpdb( $WORLD . '/site.sqlite' );
	$EXT  = true;
	$api  = TCGiant_Sync_API::instance();
	for ( $i = 0; $i < (int) $argv[2]; $i++ ) {
		$api->get_item( '110000000001' );
	}
	echo '@@SENT@@' . count( $EBAY );
	exit( 0 );
}

foreach ( array( 'Redis, Memcached, LiteSpeed, WordPress.com' => true, 'no persistent object cache' => false ) as $host => $ext ) {
	echo "\nTHE COUNT, ON A HOST WITH " . strtoupper( $ext ? 'a persistent object cache (' . $host . ')' : $host ) . "\n" . str_repeat( '=', 124 ) . "\n";

	fresh_site( $ext );
	check( 'a new day: 0 calls', $api->get_daily_call_count(), 0 );
	calls( 25 );
	check( '25 calls reach eBay', count( $EBAY ), 25 );
	check( '  the table holds 25, in a row of its own that is not a transient', array( counter_row(), $wpdb->get_var( "SELECT autoload FROM wp_options WHERE option_name = 'tcgiant_sync_api_calls_" . gmdate( 'Y-m-d' ) . "'" ) ), array( '25', 'no' ) );
	check( '  and the count is 25', $api->get_daily_call_count(), 25 );
	check( '  read inside the same request as a call, it is still exact', ( function () use ( $api ) { $api->get_item( '1' ); return $api->get_daily_call_count(); } )(), 26 );
	check( '  the day is the UTC day, here a different date from the local one', array( null !== counter_row(), date( 'Y-m-d' ) !== gmdate( 'Y-m-d' ) ), array( true, true ) );

	// The token refresh reports it.
	$sent = refresh();
	check( 'the token refresh goes through', $sent, 'fresh-token' );
	check( '  telling the relay 26 calls today', $RELAY[0]['api_calls_today'] ?? null, 26 );
	check( '  for the UTC day', $RELAY[0]['api_calls_date'] ?? null, gmdate( 'Y-m-d' ) );
	check( '  and keeps that 26 with the relay\'s figures, under the day it sent', ( get_transient( 'tcgiant_relay_budget_' . ( $RELAY[0]['api_calls_date'] ?? '' ) ) ?: array() )['local_calls'] ?? null, 26 );

	// Sent at 23:59:59 UTC and answered after midnight: the count and the
	// relay's figures are the day it was sent, and kept under that day - not
	// yesterday's figures standing as the new day's.
	$sent_day = gmdate( 'Y-m-d' );
	$RELAY    = array();
	$OAUTH_NOW   = gmmktime( 23, 59, 59, (int) gmdate( 'n' ), (int) gmdate( 'j' ), (int) gmdate( 'Y' ) );
	$RELAY_TAKES = 2;
	refresh();
	$after_midnight = gmdate( 'Y-m-d', (int) $OAUTH_NOW );
	$OAUTH_NOW   = null;
	$RELAY_TAKES = 0;
	check( '  a refresh sent at 23:59:59 and answered after midnight: the day it was sent, sent and kept', array( $RELAY[0]['api_calls_date'] ?? null, null !== ( get_transient( 'tcgiant_relay_budget_' . $sent_day ) ?: null ), false !== get_transient( 'tcgiant_relay_budget_' . $after_midnight ), $after_midnight !== $sent_day ), array( $sent_day, true, false, true ) );

	// The site's own ceiling, with no relay figures to go on.
	fresh_site( $ext );
	set_counter_row( 39998 );
	calls( 1 );
	check( 'no relay figures, 39,999 calls today: not stopped', $api->is_daily_budget_exhausted(), false );
	check( '  1 left before the ceiling', $api->get_remaining_daily_budget(), 1 );
	new_request();
	$r = $api->get_item( '110000000001' );
	check( '  the 40,000th goes to eBay', array( is_wp_error( $r ), count( $EBAY ) ), array( false, 2 ) );
	check( '  40,000 calls: the site\'s own ceiling is reached', $api->is_daily_budget_exhausted(), true );
	new_request();
	$r = $api->get_item( '110000000001' );
	check( '  the next GetItem is refused before it is sent', array( blocked( $r ), count( $EBAY ) ), array( true, 2 ) );
	check( '  and the log gives this site\'s count, against its own ceiling', logged( 'eBay daily API budget reached (40000/50000 calls used today by this site, 80% ceiling). Blocking GetItem' ), true );
	check( '  0 left', $api->get_remaining_daily_budget(), 0 );

	echo "\n  a transient clean-up, a row deleted, the first call of the day\n";

	// WP Rocket's "All transients", LiteSpeed's "Clean all transients",
	// WP-Optimize, `wp transient delete --all`: every _transient_ row goes.
	// The count was one of them, and on this host add_option() then found the
	// value it had cached and would not add it again: 0 until midnight.
	fresh_site( $ext );
	calls( 100 );
	$wpdb->query( "DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_%'" );
	calls( 500 );
	check( 'a transient clean-up mid-day: every call still counted, before and after', $api->get_daily_call_count(), 600 );
	// The row itself deleted - a restore, a hand clean-up - with the value
	// still cached where add_option() would have looked.
	wp_cache_set( 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ), '600', 'options' );
	$wpdb->query( "DELETE FROM wp_options WHERE option_name = 'tcgiant_sync_api_calls_" . gmdate( 'Y-m-d' ) . "'" );
	calls( 500 );
	check( '  the row itself deleted, its value still cached: the next 500 counted', $api->get_daily_call_count(), 500 );
	// The first call of the day is one statement: nothing between finding no
	// row and adding one for a second worker to fall into.
	$q = array_values( array_filter( $wpdb->queries, function ( $sql ) { return false !== strpos( $sql, 'tcgiant_sync_api_calls_' ) && 0 === strpos( ltrim( $sql ), 'INSERT' ); } ) );
	check( '  each call counted in one INSERT ... ON DUPLICATE KEY UPDATE', array( count( $q ) >= 1100, false !== strpos( $q[0] ?? '', 'ON DUPLICATE KEY UPDATE option_value = option_value + 1' ) ), array( true, true ) );
}

echo "\nTHE DAY OF THE UPDATE, AND OLD COUNTS\n" . str_repeat( '=', 124 ) . "\n";

foreach ( array( 'persistent object cache' => true, 'no persistent object cache' => false ) as $host => $ext ) {
	fresh_site( $ext );
	$today = gmdate( 'Y-m-d' );
	$day   = function ( $n ) { return gmdate( 'Y-m-d', strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00 UTC' ) - $n * DAY_IN_SECONDS ); };
	// 3.27 counted 7,000 today before the update, as a transient.
	set_counter_row( 7000, '_transient_tcgiant_api_calls_' . $today );
	set_counter_row( time() + 3600, '_transient_timeout_tcgiant_api_calls_' . $today );
	set_counter_row( 41000, '_transient_tcgiant_api_calls_' . $day( 1 ) );
	set_counter_row( time() - 3600, '_transient_timeout_tcgiant_api_calls_' . $day( 1 ) );
	foreach ( array( 1, 2, 3, 7, 8, 10 ) as $n ) {
		set_counter_row( 100 * $n, 'tcgiant_sync_api_calls_' . $day( $n ) );
	}
	calls( 1 );
	check( "[$host] the first call after the update: today's 7,000 carried over", $api->get_daily_call_count(), 7001 );
	check( '  3.27\'s rows gone, and counts over a week old', count_rows(), array( 'tcgiant_sync_api_calls_' . $day( 7 ), 'tcgiant_sync_api_calls_' . $day( 3 ), 'tcgiant_sync_api_calls_' . $day( 2 ), 'tcgiant_sync_api_calls_' . $day( 1 ), 'tcgiant_sync_api_calls_' . $today ) );
	check( '  the day noted, so the next call does nothing more', get_option( 'tcgiant_sync_call_count_tidied' ), $today );
	set_counter_row( 5, '_transient_tcgiant_api_calls_' . $today );
	calls( 2 );
	check( '  the next calls only count', array( $api->get_daily_call_count(), in_array( '_transient_tcgiant_api_calls_' . $today, count_rows(), true ) ), array( 7003, true ) );
	// Tidying again, with nothing of 3.27's left: nothing is added.
	$wpdb->query( "DELETE FROM wp_options WHERE option_name = '_transient_tcgiant_api_calls_" . $today . "'" );
	update_option( 'tcgiant_sync_call_count_tidied', '' );
	calls( 1 );
	check( '  with nothing of 3.27\'s left, tidying again adds nothing', $api->get_daily_call_count(), 7004 );

	// The server's clock three days ahead for one call, then back: that
	// call tidies as of three days on, which must not take today's count.
	$API_NOW = time() + 3 * DAY_IN_SECONDS;
	calls( 1 );
	$API_NOW = null;
	calls( 1 );
	check( '  a clock three days ahead for one call, then back: today\'s count kept, and counting', array( $api->get_daily_call_count(), counter_row() ), array( 7005, '7005' ) );
}

// Two requests at the first call of the day after the update, both tidying.
// The other one gets between this one's read of 3.27's count and its carry:
// it carries the 7,000 over and deletes the row - and a 3.27 request still
// running from before the update may even add the row again, at 1.
foreach ( array( 'the row gone' => false, 'the row back, at 1' => true ) as $what => $back ) {
	fresh_site( true );
	$today = gmdate( 'Y-m-d' );
	$old   = '_transient_tcgiant_api_calls_' . $today;
	set_counter_row( 7000, $old );
	$wpdb->before = function ( $sql ) use ( $wpdb, $old, $today, $back ) {
		if ( 0 === strpos( $sql, 'SELECT' ) || false === strpos( $sql, "option_name = '{$old}'" ) ) {
			return;
		}
		$wpdb->before = null;
		$wpdb->query( "UPDATE wp_options SET option_value = option_value + 7000 WHERE option_name = 'tcgiant_sync_api_calls_{$today}'" );
		$wpdb->query( "DELETE FROM wp_options WHERE option_name = '{$old}'" );
		if ( $back ) {
			$wpdb->query( "INSERT INTO wp_options ( option_name, option_value, autoload ) VALUES ( '{$old}', '1', 'no' )" );
		}
	};
	calls( 1 );
	$wpdb->before = null;
	check( "two requests tidying at once, $what: 3.27's 7,000 carried over once", $api->get_daily_call_count(), 7001 );
}

// A 3.27 job still counting when the update's first call tidies: it adds a
// call to 3.27's row between this request's read and its carry. The 7,000
// read are carried all the same; only that job's own call is left in the
// row, and goes with it. (Fix round 1's carry matched the row by the value
// read, so it carried nothing, and the 7,000 went with the row.)
fresh_site( true );
$today = gmdate( 'Y-m-d' );
$old   = '_transient_tcgiant_api_calls_' . $today;
set_counter_row( 7000, $old );
$wpdb->before = function ( $sql ) use ( $wpdb, $old ) {
	if ( 0 === strpos( $sql, 'SELECT' ) || false === strpos( $sql, "option_name = '{$old}'" ) ) {
		return;
	}
	$wpdb->before = null;
	$wpdb->query( "UPDATE wp_options SET option_value = option_value + 1 WHERE option_name = '{$old}'" );
};
calls( 1 );
$wpdb->before = null;
check( 'a 3.27 job counting into its row as the update tidies: the 7,000 carried, only its own call left behind', array( $api->get_daily_call_count(), in_array( $old, count_rows(), true ) ), array( 7001, false ) );

echo "\nBETWEEN REFRESHES, THIS SITE'S OWN CALLS COUNT AGAINST THE FLEET'S CEILING\n" . str_repeat( '=', 124 ) . "\n";

foreach ( array( 'persistent object cache' => true, 'no persistent object cache' => false ) as $host => $ext ) {
	fresh_site( $ext );
	set_counter_row( 900 );
	$REPLY['global_api_calls'] = 39000;
	$REPLY['remaining_budget'] = 11000;
	refresh();
	check( "[$host] the refresh at 900 of our own, 39,000 for the fleet", array( $RELAY[0]['api_calls_today'] ?? null, snapshot()['global_calls'] ?? null, snapshot()['local_calls'] ?? null ), array( 900, 39000, 900 ) );
	check( '  1,000 left', $api->get_remaining_daily_budget(), 1000 );

	calls( 999 );
	check( '  999 more of our own: 39,999, not stopped', array( $api->is_daily_budget_exhausted(), $api->get_remaining_daily_budget() ), array( false, 1 ) );
	calls( 101 );
	check( '  101 more: the first goes, the other 100 are refused unsent', count( $EBAY ), 1000 );
	check( '  the fleet is at the ceiling: stopped', $api->is_daily_budget_exhausted(), true );
	check( '  0 left', $api->get_remaining_daily_budget(), 0 );
	new_request();
	check( '  a GetItem of the delta, say: refused', blocked( $api->get_item( '110000000001' ) ), true );
	check( '  the log gives the figure that stopped it: every shop\'s, against the relay\'s limit', logged( 'eBay daily API budget reached (40000/50000 calls used today by every shop together, 80% ceiling). Blocking GetItem' ), true );

	// What stops an oversell goes past the ceiling: a sale's push reads and
	// revises inside with_stock_priority() (TCGiant_Sync_API::STOCK_PRIORITY_CALLS).
	$before = count( $EBAY );
	new_request();
	$read = $api->with_stock_priority( function () use ( $api ) { return $api->get_item( '110000000001' ); } );
	check( '  a sale\'s stock push: its GetItem still goes', array( is_wp_error( $read ), count( $EBAY ) - $before ), array( false, 1 ) );
	$revise = $api->with_stock_priority( function () use ( $api ) { return $api->trading_api_request( 'ReviseInventoryStatus', '<InventoryStatus><ItemID>110000000001</ItemID><Quantity>0</Quantity></InventoryStatus>' ); } );
	check( '  and so does its ReviseInventoryStatus', array( is_wp_error( $revise ), end( $EBAY ) ), array( false, 'ReviseInventoryStatus' ) );
	check( '  as does CompleteSale', is_wp_error( $api->trading_api_request( 'CompleteSale', '<OrderID>1</OrderID>' ) ), false );
	check( '  and outside the push the same GetItem is refused again', blocked( $api->get_item( '110000000001' ) ), true );

	// The next refresh: the relay's figure now includes this site's calls.
	$REPLY['global_api_calls'] = 39000 + 1003;
	$RELAY = array();
	refresh();
	check( '  the next refresh reports every call this site made', $RELAY[0]['api_calls_today'] ?? null, 1903 );
	check( '  and nothing is counted twice: still 40,003 in all', array( snapshot()['local_calls'] ?? null, $api->get_remaining_daily_budget() ), array( 1903, 0 ) );
	$REPLY['global_api_calls'] = 30000;
	refresh();
	check( '  a later figure of 30,000 with our 1,903 in it: 10,000 left', $api->get_remaining_daily_budget(), 10000 );

	// The outage question a sale check or a push asks before giving up
	// (3.28.0 release fix round 1, F5): one call per five minutes, shared.
	calls( 10000 );
	new_request();
	$clock = $api->with_stock_priority( function () use ( $api ) { return $api->get_official_time(); } );
	check( 'F5 at the ceiling again: GeteBayOfficialTime, inside with_stock_priority(), still goes', array( $api->is_daily_budget_exhausted(), is_wp_error( $clock ), end( $EBAY ) ), array( true, false, 'GeteBayOfficialTime' ) );
	check( '  and outside it, refused', blocked( $api->get_official_time() ), true );
}

// The case as the fleet meets it: 900 of our own in the relay's 39,000, then
// 1,100 more, here a run of sales' stock pushes, which go past the ceiling.
fresh_site( true );
set_counter_row( 900 );
$REPLY['global_api_calls'] = 39000;
refresh();
for ( $i = 0; $i < 1100; $i++ ) {
	new_request();
	$api->with_stock_priority( function () use ( $api ) { return $api->get_item( '110000000001' ); } );
}
check( 'snapshot 39,000 with 900 of ours, then 1,100 more of ours: all sent', array( count( $EBAY ), $api->get_daily_call_count() ), array( 1100, 2000 ) );
check( '  40,100 in all: stopped', array( $api->is_daily_budget_exhausted(), $api->get_remaining_daily_budget() ), array( true, 0 ) );
new_request();
check( '  an ordinary GetItem is refused, unsent', array( blocked( $api->get_item( '110000000001' ) ), count( $EBAY ) ), array( true, 1100 ) );
new_request();
check( '  a sale\'s push still goes', array( is_wp_error( $api->with_stock_priority( function () use ( $api ) { return $api->get_item( '110000000001' ); } ) ), count( $EBAY ) ), array( false, 1101 ) );
check( '  the snapshot alone, as before 3.28.0, would have said 1,000 left', 40000 - (int) snapshot()['global_calls'], 1000 );

echo "\nA SNAPSHOT WITH NO FIGURE OF OUR OWN, AND A STALE ONE\n" . str_repeat( '=', 124 ) . "\n";

// Kept by 3.27, before the refresh stored this site's count: as before.
fresh_site( true );
set_counter_row( 2000 );
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'remaining' => 11000, 'global_calls' => 39000, 'daily_limit' => 50000, 'updated_at' => time() ), DAY_IN_SECONDS );
check( '3.27\'s snapshot, 39,000, and 2,000 of our own: the fleet\'s 39,000 alone, as before', array( $api->is_daily_budget_exhausted(), $api->get_remaining_daily_budget() ), array( false, 1000 ) );
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'remaining' => 11000, 'global_calls' => 39999, 'daily_limit' => 50000, 'updated_at' => time() ), DAY_IN_SECONDS );
check( '  at 39,999: still not stopped', $api->is_daily_budget_exhausted(), false );

// More than two hours old: the site's own count against its own ceiling.
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'remaining' => 11000, 'global_calls' => 39000, 'daily_limit' => 50000, 'updated_at' => time() - 2 * HOUR_IN_SECONDS - 60, 'local_calls' => 900 ), DAY_IN_SECONDS );
check( 'a snapshot over two hours old is not used', $api->get_relay_budget(), false );
check( '  the site\'s own 2,000 against its own 40,000: not stopped', array( $api->is_daily_budget_exhausted(), $api->get_remaining_daily_budget() ), array( false, 38000 ) );
set_counter_row( 40000 );
check( '  at 40,000 of its own: stopped', $api->is_daily_budget_exhausted(), true );

// Our own count below the snapshot's - a new UTC day began between the
// refresh and now, say: nothing is taken off the fleet's figure.
set_counter_row( 100 );
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'remaining' => 11000, 'global_calls' => 39000, 'daily_limit' => 50000, 'updated_at' => time(), 'local_calls' => 900 ), DAY_IN_SECONDS );
check( 'our own count below the snapshot\'s: the fleet\'s figure as it is', $api->get_remaining_daily_budget(), 1000 );

// The relay's own limit, when it is not 50,000: the log gives the relay's.
set_counter_row( 0 );
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'remaining' => 0, 'global_calls' => 36000, 'daily_limit' => 45000, 'updated_at' => time(), 'local_calls' => 0 ), DAY_IN_SECONDS );
$LOG = array();
check( 'a relay limit of 45,000, the fleet at its 36,000: refused, the log giving 36000/45000', array( blocked( $api->get_item( '110000000001' ) ), logged( 'eBay daily API budget reached (36000/45000 calls used today by every shop together, 80% ceiling).' ) ), array( true, true ) );

echo "\n3.31.0: EVERY CALL THAT REACHES EBAY IS COUNTED ONCE, ON THE DAY IT WAS SENT\n" . str_repeat( '=', 124 ) . "\n";

$GLOBALS['RELAY_TAKES'] = 0;
foreach ( array( 'Redis, Memcached, LiteSpeed, WordPress.com' => true, 'no persistent object cache' => false ) as $host => $ext ) {
	echo "  -- $host\n";
	fresh_site( $ext );
	$api = TCGiant_Sync_API::instance();

	// What eBay is meant to count against the 50,000: Trading calls, each
	// time one is sent.
	new_request();
	$ANSWERS = array( 503 );
	$api->get_item( '110000000001' );
	check( "[$host] a GetItem answered 503 and sent again: two requests reached eBay, two counted", array( count( $EBAY ), $api->get_daily_call_count() ), array( 2, 2 ) );
	$ANSWERS = array( 503, 503, 503 );
	$api->get_item( '110000000001' );
	check( '  answered 503 three times: three sent, three counted', array( count( $EBAY ), $api->get_daily_call_count() ), array( 5, 5 ) );
	$ANSWERS = array( 'transport' );
	$api->get_item( '110000000001' );
	check( '  a request that timed out, then went through: both counted - eBay may have had the first', array( count( $EBAY ), $api->get_daily_call_count() ), array( 7, 7 ) );
	$ANSWERS = array( 429 );
	$api->get_item( '110000000001' );
	check( '  turned away with 429: one sent, one counted', array( count( $EBAY ), $api->get_daily_call_count() ), array( 8, 8 ) );

	// An expired token: eBay refuses the call, the relay renews the token,
	// and the call goes again. Two calls reached eBay; the refresh itself
	// went to the relay.
	$RELAY   = array();
	$ANSWERS = array( 'token' );
	$api->get_item( '110000000001' );
	check( '  a call eBay refused for an expired token, the token renewed, the call sent again: 2 more', array( count( $EBAY ), $api->get_daily_call_count(), count( $RELAY ) ), array( 10, 10, 1 ) );
	check( '    and the renewal reported the count as it stood when it was sent', $RELAY[0]['api_calls_today'] ?? null, 9 );

	// A picture upload is a Trading call too.
	$picture = $WORLD . '/picture.jpg';
	file_put_contents( $picture, 'not really a jpeg' );
	$api->upload_picture( $picture );
	check( '  UploadSiteHostedPictures: counted once', array( end( $EBAY ), $api->get_daily_call_count() ), array( 'UploadSiteHostedPictures', 11 ) );
	$ANSWERS = array( 503 );
	$api->upload_picture( $picture );
	check( '    sent twice after a 503: counted twice', $api->get_daily_call_count(), 13 );
	@unlink( $picture );

	// What eBay does not count against it: the REST APIs, which have limits
	// of their own, and the token refresh, which goes to the relay.
	$sent = count( $EBAY );
	$api->get_inventory_item( 'SKU-1' );
	$api->request( 'sell/inventory/v1/inventory_item/SKU-1', 'PUT', array( 'availability' => array() ) );
	check( '  a REST GET and a REST PUT reach eBay and are not counted', array( count( $EBAY ) - $sent, $api->get_daily_call_count() ), array( 2, 13 ) );
	$RELAY = array();
	refresh();
	check( '  nor is a token refresh, sent to the relay and never to eBay', array( count( $RELAY ), count( $EBAY ) - $sent, $api->get_daily_call_count() ), array( 1, 2, 13 ) );
	check( '    which reports the count itself', array( $RELAY[0]['api_calls_today'] ?? null, $RELAY[0]['api_calls_date'] ?? null ), array( 13, gmdate( 'Y-m-d' ) ) );

	// A stale copy of the row in the object cache - the options cache holds
	// what get_option() last saw, a persistent one across requests - is
	// never what is read or reported.
	$GLOBALS['CACHE']['options'][ 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ) ] = '3';
	$GLOBALS['CACHE']['options']['notoptions'] = array( 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ) => true );
	new_request();
	$RELAY = array();
	refresh();
	check( '  a stale 3 cached for the row: the count read, and reported, is the table\'s 13', array( $api->get_daily_call_count(), $RELAY[0]['api_calls_today'] ?? null ), array( 13, 13 ) );
}

// The UTC day boundary.
fresh_site( true );
$api     = TCGiant_Sync_API::instance();
$day     = gmmktime( 0, 0, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ), (int) gmdate( 'Y' ) );
$before  = gmdate( 'Y-m-d', $day - 1 );
$API_NOW = $day - 1; // 23:59:59
$DURING  = function () { $GLOBALS['API_NOW'] += 2; }; // answered after midnight
$api->get_item( '110000000001' );
$DURING  = null;
check( 'a call sent at 23:59:59 and answered after midnight is counted on the day it was sent', array( counter_row( $before ), counter_row( gmdate( 'Y-m-d', $day ) ) ), array( '1', null ) );
$API_NOW = null;

// A refresh whose count is read at 23:59:59 and whose date at 00:00:00 sent
// the old day's whole count as the new day's - the relay held the site to
// it, against its allowance, until the next refresh.
fresh_site( true );
set_counter_row( 1200, 'tcgiant_sync_api_calls_' . $before );
set_counter_row( 3, 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d', $day ) );
$API_NOW   = $day - 1;
$OAUTH_NOW = $day;
$RELAY     = array();
refresh();
check( 'a refresh across midnight sends the count of the day it names: 3 for the new day, not yesterday\'s 1,200', array( $RELAY[0]['api_calls_today'] ?? null, $RELAY[0]['api_calls_date'] ?? null ), array( 3, gmdate( 'Y-m-d', $day ) ) );
$API_NOW   = null;
$OAUTH_NOW = null;

// Several PHP processes counting at once - cron, Action Scheduler, web
// requests - each with its own persistent object cache.
fresh_site( true );
$workers = 4;
$each    = 150;
$procs   = array();
$pipes   = array();
for ( $w = 0; $w < $workers; $w++ ) {
	$procs[ $w ] = proc_open( array( PHP_BINARY, __FILE__, 'worker', (string) $each ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes[ $w ] );
}
$sent = 0;
foreach ( $procs as $w => $proc ) {
	$out = stream_get_contents( $pipes[ $w ][1] );
	stream_get_contents( $pipes[ $w ][2] );
	fclose( $pipes[ $w ][1] );
	fclose( $pipes[ $w ][2] );
	proc_close( $proc );
	$sent += preg_match( '/@@SENT@@(\d+)/', (string) $out, $m ) ? (int) $m[1] : 0;
}
check( sprintf( '%d processes making %d calls each, all at once: %d sent, %d counted', $workers, $each, $workers * $each, $workers * $each ), array( $sent, $api->get_daily_call_count() ), array( $workers * $each, $workers * $each ) );

echo "\nTHE RELAY SAYS THIS SITE HAS USED ITS OWN ALLOWANCE (FIX ROUND 1)\n" . str_repeat( '=', 124 ) . "\n";

// The relay holds each site to its own daily allowance (1,000 calls free,
// 10,000 paid). Past it, a refresh says the fleet's budget is spent, and
// says why: site_daily_cap and site_capped.
fresh_site( true );
$api = TCGiant_Sync_API::instance();
set_counter_row( 1004 );
$REPLY = array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 0, 'global_api_calls' => 50000, 'daily_limit' => 50000, 'site_daily_cap' => 1000, 'site_capped' => true );
refresh();
check( 'a refresh that says this site is capped: kept with the relay\'s figures', array( snapshot()['site_cap'] ?? null, snapshot()['site_capped'] ?? null ), array( 1000, true ) );
$LOG  = array();
$sent = count( $EBAY );
check( '  an ordinary GetItem is refused, unsent', array( blocked( $api->get_item( '110000000001' ) ), count( $EBAY ) - $sent ), array( true, 0 ) );
check( '  and the log says it is this site\'s own allowance, until 00:00 UTC', logged( 'eBay daily API budget reached: this site has used its own daily allowance of 1000 eBay calls (1004 calls today). Blocking GetItem until 00:00 UTC.' ), true );
check( '  not that every shop together has used theirs', logged( 'by every shop together' ), false );
check( '  a sale\'s stock push still goes', is_wp_error( $api->with_stock_priority( function () use ( $api ) { return $api->get_item( '110000000001' ); } ) ), false );

// Not capped, or a relay that does not say: the message as it was.
fresh_site( true );
$api = TCGiant_Sync_API::instance();
$REPLY = array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 9000, 'global_api_calls' => 41000, 'daily_limit' => 50000, 'site_daily_cap' => 10000, 'site_capped' => false );
refresh();
$LOG = array();
$api->get_item( '110000000001' );
check( 'the fleet past its ceiling, this site within its allowance: every shop together, as before', array( snapshot()['site_capped'] ?? null, logged( 'used today by every shop together, 80% ceiling). Blocking GetItem' ), logged( 'its own daily allowance' ) ), array( false, true, false ) );

echo "\nTHIS SITE STOPS AT ITS OWN ALLOWANCE BETWEEN REFRESHES (3.31.1)\n" . str_repeat( '=', 124 ) . "\n";

// Until 3.31.1 only the relay held a site to its allowance, at the site's
// next token refresh - up to two hours on. A site at 990 of its 1,000 could
// spend thousands more in that time. Now the plugin stops it there itself.
foreach ( array( 'persistent object cache' => true, 'no persistent object cache' => false ) as $host => $ext ) {
	fresh_site( $ext );
	$api = TCGiant_Sync_API::instance();
	set_counter_row( 990 );
	$REPLY = array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 30000, 'global_api_calls' => 20000, 'daily_limit' => 50000, 'site_daily_cap' => 1000, 'site_capped' => false );
	refresh();
	check( "[$host] the refresh at 990 of this site's 1,000, the fleet at 20,000: kept, not capped", array( snapshot()['site_cap'] ?? null, snapshot()['site_capped'] ?? null, $api->site_daily_allowance(), $api->is_daily_budget_exhausted() ), array( 1000, false, 1000, false ) );
	calls( 9 );
	check( '  9 more: 999 of its own, not stopped', array( $api->get_daily_call_count(), $api->is_daily_budget_exhausted(), count( $EBAY ) ), array( 999, false, 9 ) );
	$LOG = array();
	calls( 5 );
	check( '  5 more, mid-day: the 1,000th goes, the other 4 are refused unsent', array( count( $EBAY ), $api->get_daily_call_count() ), array( 10, 1000 ) );
	check( '  stopped at 1,000, the fleet still at 20,000 and the relay not yet asked again', array( $api->is_daily_budget_exhausted(), count( $RELAY ), $api->get_remaining_daily_budget() ), array( true, 1, 19990 ) );
	check( '  the log says it is this site\'s own allowance, until 00:00 UTC', logged( 'eBay daily API budget reached: this site has used its own daily allowance of 1000 eBay calls (1000 calls today). Blocking GetItem until 00:00 UTC.' ), true );
	check( '  not that every shop together has used theirs', logged( 'by every shop together' ), false );
	new_request();
	check( '  a GetItem of the delta, say: refused', blocked( $api->get_item( '110000000001' ) ), true );
	check( '  so is GetSellerList', blocked( $api->trading_api_request( 'GetSellerList', '<Pagination><EntriesPerPage>200</EntriesPerPage></Pagination>' ) ), true );

	// What stops an oversell goes past it, as past the fleet's ceiling.
	$before = count( $EBAY );
	new_request();
	$read   = $api->with_stock_priority( function () use ( $api ) { return $api->get_item( '110000000001' ); } );
	$revise = $api->with_stock_priority( function () use ( $api ) { return $api->trading_api_request( 'ReviseInventoryStatus', '<InventoryStatus><ItemID>110000000001</ItemID><Quantity>0</Quantity></InventoryStatus>' ); } );
	$end    = $api->with_stock_priority( function () use ( $api ) { return $api->trading_api_request( 'EndItem', '<ItemID>110000000001</ItemID><EndingReason>NotAvailable</EndingReason>' ); } );
	$sale   = $api->trading_api_request( 'CompleteSale', '<OrderID>1</OrderID>' );
	check( '  a sale\'s stock push - GetItem, ReviseInventoryStatus, EndItem - and CompleteSale still go', array( is_wp_error( $read ), is_wp_error( $revise ), is_wp_error( $end ), is_wp_error( $sale ), count( $EBAY ) - $before ), array( false, false, false, false, 4 ) );
	check( '  counted, as every call is', $api->get_daily_call_count(), 1004 );
	check( '  outside the push, the same GetItem is refused again', blocked( $api->get_item( '110000000001' ) ), true );
	check( '  a picture upload too', ( function () use ( $api ) {
		$file = $GLOBALS['WORLD'] . '/photo.jpg';
		file_put_contents( $file, 'jpeg' );
		$r = $api->upload_picture( $file );
		@unlink( $file );
		return array( is_wp_error( $r ) ? $r->get_error_code() : 'sent', end( $GLOBALS['EBAY'] ) );
	} )(), array( 'rate_limited', 'CompleteSale' ) );

	// The next refresh: the relay now says so too. The same message.
	$REPLY['site_capped']      = true;
	$REPLY['global_api_calls'] = 50000;
	$REPLY['remaining_budget'] = 0;
	refresh();
	$LOG = array();
	check( '  the next refresh, the relay saying so: the same refusal, the same words', array( blocked( $api->get_item( '110000000001' ) ), logged( 'this site has used its own daily allowance of 1000 eBay calls (1004 calls today). Blocking GetItem until 00:00 UTC.' ) ), array( true, true ) );
}

// The UTC day turning over: the count is the new day's, and the allowance
// the relay gave just before midnight still holds until the next refresh.
fresh_site( true );
$api      = TCGiant_Sync_API::instance();
$day1_end = gmmktime( 23, 50, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ), (int) gmdate( 'Y' ) );
$day1     = gmdate( 'Y-m-d', $day1_end );
$day2     = gmdate( 'Y-m-d', $day1_end + DAY_IN_SECONDS );
set_transient( 'tcgiant_relay_budget_' . $day1, array( 'remaining' => 30000, 'global_calls' => 20000, 'daily_limit' => 50000, 'updated_at' => $day1_end - 600, 'local_calls' => 900, 'site_cap' => 1000, 'site_capped' => false ), DAY_IN_SECONDS );
set_counter_row( 1000, 'tcgiant_sync_api_calls_' . $day1 );
$API_NOW = $day1_end;
$LOG     = array();
new_request();
check( 'the UTC day turning over: at 23:50, 1,000 of 1,000 - stopped', array( $api->is_daily_budget_exhausted(), blocked( $api->get_item( '110000000001' ) ), count( $EBAY ), logged( 'own daily allowance of 1000 eBay calls (1000 calls today)' ) ), array( true, true, 0, true ) );
$API_NOW = gmmktime( 0, 0, 30, (int) gmdate( 'n', $day1_end + DAY_IN_SECONDS ), (int) gmdate( 'j', $day1_end + DAY_IN_SECONDS ), (int) gmdate( 'Y', $day1_end + DAY_IN_SECONDS ) );
new_request();
$r = $api->get_item( '110000000001' );
check( '  at 00:00:30 UTC the new day\'s count is 0: the GetItem goes, counted on the new day', array( is_wp_error( $r ), count( $EBAY ), counter_row( $day2 ), counter_row( $day1 ) ), array( false, 1, '1', '1000' ) );
check( '  the allowance from 23:40\'s refresh still known, against the new day\'s count', array( $api->site_daily_allowance(), $api->is_daily_budget_exhausted() ), array( 1000, false ) );
set_counter_row( 999, 'tcgiant_sync_api_calls_' . $day2 );
$LOG = array();
calls( 2 );
check( '  999 of the new day\'s, before any refresh: the 1,000th goes, the next is refused', array( count( $EBAY ), counter_row( $day2 ), $api->is_daily_budget_exhausted() ), array( 2, '1000', true ) );
check( '  the log gives the allowance from 23:40\'s answer, and the new day\'s count', logged( 'this site has used its own daily allowance of 1000 eBay calls (1000 calls today). Blocking GetItem until 00:00 UTC.' ), true );
$API_NOW = $day1_end - 600 + 2 * HOUR_IN_SECONDS + 1;
new_request();
check( '  two hours after that refresh with none since: not known, today\'s behaviour - the call goes, and a real one refreshes the token', array( $api->site_daily_allowance(), $api->is_daily_budget_exhausted(), is_wp_error( $api->get_item( '110000000001' ) ) ), array( 0, false, false ) );
// A refresh after midnight answers again: today's answer, not yesterday's
// (fix round 1, the review's M1) - here the dashboard has raised it to 5,000.
set_transient( 'tcgiant_relay_budget_' . $day2, array( 'remaining' => 30000, 'global_calls' => 1000, 'daily_limit' => 50000, 'updated_at' => $day1_end + 1800, 'local_calls' => 1000, 'site_cap' => 5000, 'site_capped' => false ), DAY_IN_SECONDS );
$API_NOW = $day1_end + 2400;
check( '  both answers under two hours old after midnight: today\'s 5,000, not yesterday\'s 1,000', array( $api->site_daily_allowance(), $api->is_daily_budget_exhausted() ), array( 5000, false ) );
$API_NOW = null;

// No allowance known: as before 3.31.1.
foreach ( array(
	'a relay that sends no allowance'      => array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 30000, 'global_api_calls' => 20000, 'daily_limit' => 50000 ),
	'an allowance of 0 (no row at the relay)' => array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 30000, 'global_api_calls' => 20000, 'daily_limit' => 50000, 'site_daily_cap' => 0, 'site_capped' => false ),
) as $label => $reply ) {
	fresh_site( true );
	$api = TCGiant_Sync_API::instance();
	set_counter_row( 5000 );
	$REPLY = $reply;
	refresh();
	calls( 3 );
	check( $label . ': 5,003 of its own calls, the fleet under its ceiling - not stopped, every call sent', array( $api->site_daily_allowance(), $api->is_daily_budget_exhausted(), count( $EBAY ) ), array( 0, false, 3 ) );
}
fresh_site( true );
$api = TCGiant_Sync_API::instance();
set_counter_row( 5000 );
check( 'no refresh at all: none known, not stopped', array( $api->site_daily_allowance(), $api->is_daily_budget_exhausted() ), array( 0, false ) );
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'remaining' => 30000, 'global_calls' => 20000, 'daily_limit' => 50000, 'updated_at' => time() - 2 * HOUR_IN_SECONDS - 60, 'local_calls' => 900, 'site_cap' => 1000, 'site_capped' => false ), DAY_IN_SECONDS );
check( '  an answer over two hours old: its allowance not used either', array( $api->site_daily_allowance(), $api->is_daily_budget_exhausted() ), array( 0, false ) );
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'remaining' => 30000, 'global_calls' => 20000, 'daily_limit' => 50000, 'updated_at' => time(), 'local_calls' => 900, 'site_cap' => 10000, 'site_capped' => false ), DAY_IN_SECONDS );
check( '  a fresh answer of 10,000 (paid): 5,000 is within it', array( $api->site_daily_allowance(), $api->is_daily_budget_exhausted() ), array( 10000, false ) );
set_counter_row( 9999 );
check( '  9,999: still within it', $api->is_daily_budget_exhausted(), false );
set_counter_row( 10000 );
check( '  10,000: stopped', $api->is_daily_budget_exhausted(), true );

echo "\nFIX ROUND 1: EBAY'S SALES STILL COME IN PAST THE SITE'S OWN ALLOWANCE, WITHIN A MARGIN\n" . str_repeat( '=', 124 ) . "\n";

// The review's M1: at its own allowance a site stopped reading eBay's orders
// and changes, so an item sold on eBay stayed for sale on the website until
// 00:00 UTC. GetOrders and GetSellerEvents now go past it, up to a margin,
// while the fleet is under its ceiling; nothing else does.
function sales_read( $call ) {
	new_request();
	return 'GetOrders' === $call
		? TCGiant_Sync_API::instance()->trading_api_request( 'GetOrders', '<NumberOfDays>1</NumberOfDays>' )
		: TCGiant_Sync_API::instance()->get_seller_events( '2026-11-01T00:00:00.000Z', '2026-11-01T01:00:00.000Z' );
}
function said_count( $needle ) {
	return count( array_filter( $GLOBALS['LOG'], function ( $e ) use ( $needle ) { return false !== strpos( $e[1], $needle ); } ) );
}
$SALES_SAID = 'Until 00:00 UTC only the reads that bring eBay\'s orders and sales into WooCommerce go (GetOrders, GetSellerEvents), up to 200 calls past the allowance';

fresh_site( true );
$api = TCGiant_Sync_API::instance();
set_counter_row( 990 );
$REPLY = array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 30000, 'global_api_calls' => 20000, 'daily_limit' => 50000, 'site_daily_cap' => 1000, 'site_capped' => false );
refresh();
calls( 10 );
$LOG    = array();
$before = count( $EBAY );
new_request();
check( 'at 1,000 of 1,000, the fleet at 20,010: a GetItem is refused, GetOrders and GetSellerEvents go', array( blocked( $api->get_item( '110000000001' ) ), is_wp_error( sales_read( 'GetOrders' ) ), is_wp_error( sales_read( 'GetSellerEvents' ) ), array_slice( $EBAY, $before ) ), array( true, false, false, array( 'GetOrders', 'GetSellerEvents' ) ) );
check( '  counted, as every call is', $api->get_daily_call_count(), 1002 );
check( '  still refused: GetSellerList, a picture upload; get_remaining_today() 0, the importer\'s reserves on the fleet\'s 19,988', array( blocked( $api->trading_api_request( 'GetSellerList', '<Pagination/>' ) ), ( function () use ( $api ) { $f = $GLOBALS['WORLD'] . '/p.jpg'; file_put_contents( $f, 'x' ); $r = $api->upload_picture( $f ); @unlink( $f ); return is_wp_error( $r ) ? $r->get_error_code() : 'sent'; } )(), $api->get_remaining_today(), $api->get_remaining_daily_budget() ), array( true, 'rate_limited', 0, 19988 ) );
sales_read( 'GetOrders' );
sales_read( 'GetSellerEvents' );
check( '  said once that day, however many go', array( said_count( $SALES_SAID ), said_count( 'this site has used its own daily allowance of 1000 eBay calls (1000 calls today). Until 00:00 UTC' ) ), array( 1, 1 ) );
check( '  the margin: a tenth of the allowance, never under 200 - 1,000 free 200, 10,000 paid 1,000, 25,000 2,500', array( TCGiant_Sync_API::sales_reads_margin( 1000 ), TCGiant_Sync_API::sales_reads_margin( 10000 ), TCGiant_Sync_API::sales_reads_margin( 500 ), TCGiant_Sync_API::sales_reads_margin( 25000 ) ), array( 200, 1000, 200, 2500 ) );
set_counter_row( 1199 );
$before = count( $EBAY );
check( '  at 1,199: the 1,200th call, a GetOrders, goes; the next is refused - the worst case 1,200 a day', array( is_wp_error( sales_read( 'GetOrders' ) ), blocked( sales_read( 'GetOrders' ) ), blocked( sales_read( 'GetSellerEvents' ) ), count( $EBAY ) - $before ), array( false, true, true, 1 ) );
check( '  refused in this site\'s own words', logged( 'this site has used its own daily allowance of 1000 eBay calls (1200 calls today). Blocking GetOrders until 00:00 UTC.' ), true );

// The fleet's ceiling still holds them.
fresh_site( true );
$api = TCGiant_Sync_API::instance();
set_counter_row( 990 );
$REPLY = array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 11000, 'global_api_calls' => 39990, 'daily_limit' => 50000, 'site_daily_cap' => 1000, 'site_capped' => false );
refresh();
calls( 10 );
check( 'past its allowance with the fleet at 40,000: GetOrders refused too', blocked( sales_read( 'GetOrders' ) ), true );

// A refresh that says this site is capped reports the fleet as spent; the
// sales reads judge the fleet by the last true figure that day.
fresh_site( true );
$api = TCGiant_Sync_API::instance();
set_counter_row( 900 );
$REPLY = array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 30000, 'global_api_calls' => 20000, 'daily_limit' => 50000, 'site_daily_cap' => 1000, 'site_capped' => false );
refresh();
set_counter_row( 1050 );
$REPLY = array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 0, 'global_api_calls' => 50000, 'daily_limit' => 50000, 'site_daily_cap' => 1000, 'site_capped' => true );
refresh();
check( 'the next refresh says capped (the fleet "50,000"): the true 20,000 at 900 of ours kept beside it', array( snapshot()['site_capped'] ?? null, snapshot()['fleet_calls'] ?? null, snapshot()['fleet_local'] ?? null, snapshot()['global_calls'] ?? null ), array( true, 20000, 900, 50000 ) );
check( '  GetOrders still goes (20,000 + 150 of ours since); a GetItem does not', array( is_wp_error( sales_read( 'GetOrders' ) ), blocked( $api->get_item( '110000000001' ) ) ), array( false, true ) );
refresh();
check( '  a third refresh, capped again: the true figure carried on', array( snapshot()['fleet_calls'] ?? null, snapshot()['fleet_local'] ?? null, is_wp_error( sales_read( 'GetSellerEvents' ) ) ), array( 20000, 900, false ) );
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array_merge( snapshot(), array( 'fleet_calls' => 39000, 'fleet_local' => 0 ) ), DAY_IN_SECONDS );
check( '  the carried figure with our calls since at 40,000 or more: refused', blocked( sales_read( 'GetOrders' ) ), true );
// Fix round 2: the true figure is carried for the rest of the UTC day,
// however old. A refresh comes only once the two-hour token has expired and
// a call needs it, so two refreshes are a little over two hours apart: an
// age limit of two hours dropped it at the second capped refresh, and the
// sales reads stopped two to four hours after the site capped.
$CAPPED = array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 0, 'global_api_calls' => 50000, 'daily_limit' => 50000, 'site_daily_cap' => 1000, 'site_capped' => true );
fresh_site( true );
$api = TCGiant_Sync_API::instance();
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'remaining' => 0, 'global_calls' => 50000, 'daily_limit' => 50000, 'updated_at' => time() - 2 * HOUR_IN_SECONDS - 600, 'local_calls' => 1050, 'site_cap' => 1000, 'site_capped' => true, 'fleet_calls' => 20000, 'fleet_local' => 900 ), DAY_IN_SECONDS );
set_counter_row( 1100 );
$REPLY = $CAPPED;
refresh();
check( 'fix round 2: a capped refresh 2 h 10 min after the last capped one keeps the true figure it carried; GetOrders goes', array( snapshot()['fleet_calls'] ?? null, snapshot()['fleet_local'] ?? null, is_wp_error( sales_read( 'GetOrders' ) ) ), array( 20000, 900, false ) );
fresh_site( true );
$api = TCGiant_Sync_API::instance();
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'remaining' => 30000, 'global_calls' => 20000, 'daily_limit' => 50000, 'updated_at' => time() - 20 * HOUR_IN_SECONDS, 'local_calls' => 900, 'site_cap' => 1000, 'site_capped' => false ), DAY_IN_SECONDS );
set_counter_row( 1050 );
$REPLY = $CAPPED;
refresh();
check( '  and a true answer twenty hours old, kept under the same UTC day (03:00 seen at 23:00): carried too', array( snapshot()['fleet_calls'] ?? null, snapshot()['fleet_local'] ?? null ), array( 20000, 900 ) );

// A new UTC day starts with no carry: the answer is kept under its day.
fresh_site( true );
$api      = TCGiant_Sync_API::instance();
$day1_end = gmmktime( 23, 50, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ), (int) gmdate( 'Y' ) );
$carry_d1 = gmdate( 'Y-m-d', $day1_end );
$carry_d2 = gmdate( 'Y-m-d', $day1_end + DAY_IN_SECONDS );
set_transient( 'tcgiant_relay_budget_' . $carry_d1, array( 'remaining' => 0, 'global_calls' => 50000, 'daily_limit' => 50000, 'updated_at' => time(), 'local_calls' => 1050, 'site_cap' => 1000, 'site_capped' => true, 'fleet_calls' => 20000, 'fleet_local' => 900 ), DAY_IN_SECONDS );
set_counter_row( 1050, 'tcgiant_sync_api_calls_' . $carry_d2 );
$OAUTH_NOW = gmmktime( 0, 10, 0, (int) gmdate( 'n', $day1_end + DAY_IN_SECONDS ), (int) gmdate( 'j', $day1_end + DAY_IN_SECONDS ), (int) gmdate( 'Y', $day1_end + DAY_IN_SECONDS ) );
$REPLY     = $CAPPED;
refresh();
$OAUTH_NOW = null;
$carry_new = get_transient( 'tcgiant_relay_budget_' . $carry_d2 );
check( 'a new UTC day starts with no carry: its first answer, capped, keeps none - yesterday\'s stays yesterday\'s', array( is_array( $carry_new ), isset( $carry_new['fleet_calls'] ), ( get_transient( 'tcgiant_relay_budget_' . $carry_d1 ) ?: array() )['fleet_calls'] ?? null ), array( true, false, 20000 ) );

// An answer that is not capped replaces the carry with its true figure:
// here the dashboard has raised this site's allowance to 5,000.
fresh_site( true );
$api = TCGiant_Sync_API::instance();
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'remaining' => 0, 'global_calls' => 50000, 'daily_limit' => 50000, 'updated_at' => time() - 600, 'local_calls' => 1050, 'site_cap' => 1000, 'site_capped' => true, 'fleet_calls' => 20000, 'fleet_local' => 900 ), DAY_IN_SECONDS );
set_counter_row( 1050 );
$REPLY = array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 10000, 'global_api_calls' => 40000, 'daily_limit' => 50000, 'site_daily_cap' => 5000, 'site_capped' => false );
refresh();
check( 'an answer not capped replaces the carry: its true 40,000 kept, no carried figure beside it', array( isset( snapshot()['fleet_calls'] ), snapshot()['global_calls'] ?? null, snapshot()['site_capped'] ?? null, $api->site_daily_allowance() ), array( false, 40000, false, 5000 ) );
check( '  and the fleet is judged by it: at its 80% ceiling, GetOrders is refused - the carried 20,000 would have let it go', blocked( sales_read( 'GetOrders' ) ), true );
fresh_site( true );
$api   = TCGiant_Sync_API::instance();
$REPLY = array( 'access_token' => 'fresh-token', 'expires_in' => 7200, 'remaining_budget' => 0, 'global_api_calls' => 50000, 'daily_limit' => 50000, 'site_daily_cap' => 1000, 'site_capped' => true );
set_counter_row( 1050 );
refresh();
check( 'the day\'s first refresh already capped, no true figure known: the sales reads wait as well', array( isset( snapshot()['fleet_calls'] ), blocked( sales_read( 'GetOrders' ) ) ), array( false, true ) );

// After UTC midnight, on the allowance of the refresh before it: no fleet
// figure yet today, so the site's own 40,000, as for every call.
fresh_site( true );
$api      = TCGiant_Sync_API::instance();
$day1_end = gmmktime( 23, 50, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ), (int) gmdate( 'Y' ) );
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d', $day1_end ), array( 'remaining' => 30000, 'global_calls' => 20000, 'daily_limit' => 50000, 'updated_at' => $day1_end - 600, 'local_calls' => 900, 'site_cap' => 1000, 'site_capped' => false ), DAY_IN_SECONDS );
$API_NOW = $day1_end + 1800;
set_counter_row( 1000, 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d', $API_NOW ) );
check( 'just after UTC midnight at 1,000 of the new day: GetOrders goes, a GetItem is refused', array( is_wp_error( sales_read( 'GetOrders' ) ), blocked( $api->get_item( '110000000001' ) ) ), array( false, true ) );
$API_NOW = null;

// No allowance: GetOrders as every call, as before.
fresh_site( true );
$api = TCGiant_Sync_API::instance();
set_counter_row( 39999 );
calls( 1 );
check( 'no allowance known, at the site\'s 40,000: GetOrders refused, as before', blocked( sales_read( 'GetOrders' ) ), true );
$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
check( 'the day\'s note deleted with the plugin\'s data', false !== strpos( $uninstall, "'tcgiant_sync_allowance_noted'," ), true );

echo "\nWHAT READS THE COUNTER, AND WHAT IS LEFT OF IT\n" . str_repeat( '=', 124 ) . "\n";

$src = '';
foreach ( array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ), glob( dirname( __DIR__ ) . '/admin/*.php' ), glob( dirname( __DIR__ ) . '/admin/views/*.php' ) ) as $f ) {
	$src .= file_get_contents( $f );
}
$api_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-api.php' );
check( 'no get_transient() of the counter anywhere', preg_match( "/get_transient\\(\\s*['\"](_transient_)?tcgiant(_sync)?_api_calls_/", $src ) + preg_match( '/get_transient\(\s*\$key\s*\)/', $api_src ), 0 );
// eBay's own Timestamp on its answers, kept for the records a read writes
// (3.28.0 release fix round 2): the latest in the request, never the server's.
new_request();
$api->with_stock_priority( function () use ( $api ) { return $api->get_item( '110000000001' ); } );
$tcg_first = $api->ebay_time();
$GLOBALS['EBAY_TS'] = '2026-10-25T09:00:00.000Z';
$api->with_stock_priority( function () use ( $api ) { return $api->get_item( '110000000001' ); } );
$tcg_earlier = $api->ebay_time();
$GLOBALS['EBAY_TS'] = '2026-10-25T11:30:00.250Z';
$api->with_stock_priority( function () use ( $api ) { return $api->get_item( '110000000001' ); } );
check( 'F1 eBay\'s Timestamp on an answer is kept, the latest in the request: an earlier one never moves it back', array( $tcg_first, $tcg_earlier, $api->ebay_time() ), array( gmmktime( 10, 0, 0, 10, 25, 2026 ), gmmktime( 10, 0, 0, 10, 25, 2026 ), gmmktime( 11, 30, 0, 10, 25, 2026 ) ) );
unset( $GLOBALS['EBAY_TS'] );

// Read for a day it is given since 3.31.0 - the token refresh names the day it
// reports - so every use goes by $date.
check( 'the counter is named once, and read and written by that name', array( substr_count( $src, "'tcgiant_sync_api_calls_'" ), substr_count( $api_src, 'self::CALL_COUNT_PREFIX . gmdate( \'Y-m-d\' )' ), substr_count( $api_src, 'self::CALL_COUNT_PREFIX . $date' ) ), array( 1, 0, 3 ) );
// The daily summary and the REST status read a setting nothing ever wrote,
// and said 0 (3.28.0).
check( 'the daily summary and GET /sync/status give the day\'s count itself', array( substr_count( $src, "settings['api_calls_today']" ), substr_count( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-cron.php' ), '$api_calls = TCGiant_Sync_API::instance()->get_daily_call_count();' ), substr_count( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-rest.php' ), "'api_calls_today' => TCGiant_Sync_API::instance()->get_daily_call_count()," ) ), array( 0, 1, 1 ) );
$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
check( 'deleting the plugin with its data deletes the counts, both names, and the note of the last tidy', array( false !== strpos( $uninstall, "OR option_name LIKE 'tcgiant_sync_api_calls_%'" ), false !== strpos( $uninstall, "OR option_name LIKE '_transient_tcgiant_api_calls_%'" ), false !== strpos( $uninstall, "OR option_name LIKE '_transient_timeout_tcgiant_api_calls_%'" ), false !== strpos( $uninstall, "'tcgiant_sync_call_count_tidied'," ) ), array( true, true, true, true ) );

$wpdb->db->close();
foreach ( array( '', '-wal', '-shm' ) as $tail ) {
	@unlink( $WORLD . '/site.sqlite' . $tail );
}
@rmdir( $WORLD );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
