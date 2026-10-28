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

/** eBay: every Trading call answered Success, in its own envelope. */
function wp_remote_request( $url, $args ) {
	$call             = $args['headers']['X-EBAY-API-CALL-NAME'] ?? '';
	$GLOBALS['EBAY'][] = $call;
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

check( 'the counter is named once, and read and written by that name', array( substr_count( $src, "'tcgiant_sync_api_calls_'" ), substr_count( $api_src, 'self::CALL_COUNT_PREFIX . gmdate( \'Y-m-d\' )' ), substr_count( $api_src, 'self::CALL_COUNT_PREFIX . $date' ) ), array( 1, 1, 2 ) );
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
