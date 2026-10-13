<?php
/**
 * Harness: the delta sync's window, driven end to end.
 *
 * A shop on WordPress.com wrote in that its newest listings were never being
 * imported: the delta action "repeatedly starts but is marked failed after
 * several minutes", with PHP running out of its 512 MB. The answer to one
 * GetSellerEvents request was too large to read, and nothing ever made the
 * request smaller - a request that runs out of memory writes nothing, so the
 * next run asked for exactly the same window and died the same way.
 *
 * The fix touches the one loop every shop's sync depends on, so this does not
 * test pieces of it. It loads the real TCGiant_Sync_Importer with WordPress
 * stubbed out, plays eBay from a timeline of listing changes, runs the queued
 * actions the way Action Scheduler would and the hourly cron the way WP-Cron
 * would, and checks the outcome a shop would see: was every changed listing
 * imported, and did the sync catch up.
 *
 * A request killed for memory or time is modelled as an exception thrown from
 * the eBay call. The real thing runs no `finally`, so the harness puts the lock
 * file back afterwards, as a dead request would leave it.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-74s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

// --- the world the importer runs in ------------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$UPLOADS = sys_get_temp_dir() . '/tcg-delta-window-harness';
if ( ! is_dir( $UPLOADS ) ) {
	mkdir( $UPLOADS, 0777, true );
}

$OPTIONS  = array();
$QUEUE    = array();
$LOG      = array();
$IMPORTED = array();
$TZ       = 'UTC';
$CAP      = 26843545; // What a 512 MB site gets - see test-delta-fetch.php.
$EBAY     = array();

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

class Killed_Request extends Exception {}

class Harness_Wpdb {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public function prepare( $query ) { return $query; }
	public function get_var( $query ) { return null; }
}
$wpdb = new Harness_Wpdb();

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function add_action() {}
function update_post_meta() {}
function wp_clear_scheduled_hook() {}
function get_option( $name, $default = false ) {
	global $OPTIONS;
	return array_key_exists( $name, $OPTIONS ) ? $OPTIONS[ $name ] : $default;
}
function update_option( $name, $value ) {
	global $OPTIONS;
	$OPTIONS[ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	global $OPTIONS;
	unset( $OPTIONS[ $name ] );
}
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['UPLOADS'] ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }

// Time, as WordPress keeps it: current_time( 'mysql' ) is the site's local
// wall clock, and these three are core's own, verbatim.
function wp_timezone() { return new DateTimeZone( $GLOBALS['TZ'] ); }
function current_time( $type, $gmt = 0 ) {
	return ( new DateTime( 'now', $gmt ? new DateTimeZone( 'UTC' ) : wp_timezone() ) )->format( 'Y-m-d H:i:s' );
}
function get_gmt_from_date( $date_string, $format = 'Y-m-d H:i:s' ) {
	$datetime = date_create( $date_string, wp_timezone() );
	if ( false === $datetime ) {
		return gmdate( $format, 0 );
	}
	return $datetime->setTimezone( new DateTimeZone( 'UTC' ) )->format( $format );
}
function mysql2date( $format, $date, $translate = true ) {
	if ( empty( $date ) ) {
		return false;
	}
	$timezone = wp_timezone();
	$datetime = date_create( $date, $timezone );
	if ( false === $datetime ) {
		return false;
	}
	// Returns a sum of timestamp with timezone offset. Ideally should never be used.
	if ( 'G' === $format || 'U' === $format ) {
		return $datetime->getTimestamp() + $datetime->getOffset();
	}
	return $datetime->format( $format );
}

// Action Scheduler, as far as the delta sync uses it.
function as_enqueue_async_action( $hook ) { $GLOBALS['QUEUE'][] = $hook; return 1; }
function as_schedule_single_action( $when, $hook ) { $GLOBALS['QUEUE'][] = $hook; return 1; }
function as_next_scheduled_action( $hook ) { return in_array( $hook, $GLOBALS['QUEUE'], true ) ? true : false; }
function as_get_scheduled_actions( $args ) {
	return in_array( $args['hook'], $GLOBALS['QUEUE'], true ) ? array( 1 ) : array();
}
class ActionScheduler_Store {
	const STATUS_PENDING = 'pending';
}
function as_unschedule_all_actions( $hook ) {
	$GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $h ) use ( $hook ) { return $h !== $hook; } ) );
}

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
}

class TCGiant_Sync_OAuth {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_settings() { return array(); }
}

class TCGiant_Sync_Entitlements {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function can_import() { return true; }
	public function get_active_product_count() { return 0; }
	public function get_free_limit() { return 0; }
}

class TCGiant_Sync_Mapper {
	public static $last_skipped = false;
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function map_ebay_to_woo( $item ) {
		return array( 'id' => $item['ItemID'], 'price' => '1.00', 'stock_quantity' => 1, 'attributes' => array(), 'images' => array(), 'variations' => array() );
	}
	public function save_as_product( $data ) {
		$id = $data['id'];
		$GLOBALS['IMPORTED'][ $id ] = ( $GLOBALS['IMPORTED'][ $id ] ?? 0 ) + 1;
		// What the dashboard would see if it polled right now.
		$GLOBALS['STATUS_DURING'][] = TCGiant_Sync_Importer::get_sync_state()['status'];
		return 1000 + count( $GLOBALS['IMPORTED'] );
	}
}

class TCGiant_Sync_Image_Localizer {
	public static function set_external_images() {}
}

/**
 * eBay, played from a timeline of changes.
 *
 * Answers like GetSellerEvents: every listing changed inside the window, at
 * most 3000 of them and with no sign when it stopped there. A listing's size
 * is what its answer weighs. An answer over the ceiling the caller passed is
 * refused before it is read, as the real API layer does; an answer read
 * without one that weighs more than the host can survive kills the request.
 */
class TCGiant_Sync_API {
	const DAILY_CALL_LIMIT        = 50000;
	const DAILY_CALL_SOFT_CEILING = 0.80;
	private $last_bytes = 0;
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public static function get_events_byte_limit() { return $GLOBALS['CAP']; }
	public function get_last_body_bytes() { return $this->last_bytes; }
	public function get_remaining_daily_budget() { return $GLOBALS['EBAY']['budget'] ?? 40000; }
	/**
	 * GetItem: the whole listing. The delta asks for it whenever what it holds
	 * lacks item specifics or pictures - which a window kept in brief always
	 * does, and which eBay's own events answer usually does too.
	 */
	public function get_item( $item_id ) {
		$GLOBALS['EBAY']['get_items'][] = (string) $item_id;
		if ( ! empty( $GLOBALS['EBAY']['on_get_item'] ) ) {
			call_user_func( $GLOBALS['EBAY']['on_get_item'], (string) $item_id );
		}
		return array( 'Ack' => 'Success', 'Item' => array(
			'ItemID'         => (string) $item_id,
			'Title'          => 'Card ' . $item_id,
			'SellingStatus'  => array( 'ListingStatus' => $GLOBALS['EBAY']['now_ended'][ (string) $item_id ] ?? 'Active' ),
			'ItemSpecifics'  => array( 'NameValueList' => array() ),
			'PictureDetails' => array( 'PictureURL' => 'https://i.ebayimg.com/' . $item_id . '.jpg' ),
		) );
	}

	public function get_seller_events( $from, $to, $max_body_bytes = 0 ) {
		$e  = &$GLOBALS['EBAY'];
		$f  = strtotime( $from );
		$t  = strtotime( $to );
		$in = array();
		foreach ( $e['events'] as $event ) {
			if ( $event['ts'] >= $f && $event['ts'] <= $t ) {
				$in[] = $event;
			}
		}
		$in    = array_slice( $in, 0, 3000 );
		$bytes = 0;
		foreach ( $in as $event ) {
			$bytes += $event['bytes'];
		}
		$e['calls'][] = array( 'from' => $from, 'to' => $to, 'max' => $max_body_bytes, 'count' => count( $in ), 'bytes' => $bytes );

		if ( ! empty( $e['on_call'] ) ) {
			call_user_func( $e['on_call'] );
		}
		if ( ! empty( $e['kill_next'] ) ) {
			$e['kill_next']--;
			$e['killed']++;
			throw new Killed_Request();
		}

		if ( $max_body_bytes > 0 && $bytes > $max_body_bytes ) {
			$this->last_bytes = $max_body_bytes + 1;
			return new WP_Error( 'response_too_large', 'too large', array( 'limit' => $max_body_bytes ) );
		}
		if ( ! empty( $e['refuse'] ) ) {
			$this->last_bytes = 300;
			return new WP_Error( 'api_error', 'Too many results. Please use a smaller complete result set.' );
		}
		if ( ! empty( $e['fail'] ) ) {
			$this->last_bytes = 0;
			return new WP_Error( 'http_request_failed', $e['fail'] );
		}
		if ( ! empty( $e['death_bytes'] ) && $bytes > $e['death_bytes'] ) {
			$e['killed']++;
			throw new Killed_Request();
		}

		$this->last_bytes = $bytes;
		$items = array();
		foreach ( $in as $event ) {
			$items[] = array(
				'ItemID'         => $event['id'],
				'Title'          => 'Card ' . $event['id'],
				'SellingStatus'  => array( 'ListingStatus' => 'Active' ),
				'ItemSpecifics'  => array( 'NameValueList' => array() ),
				'PictureDetails' => array( 'PictureURL' => 'https://i.ebayimg.com/' . $event['id'] . '.jpg' ),
			);
		}
		if ( 1 === count( $items ) ) {
			$items = $items[0];
		}
		return array( 'Ack' => 'Success', 'ItemArray' => empty( $items ) ? array() : array( 'Item' => $items ) );
	}
}

require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-runtime.php';
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-importer.php';

$IMP = TCGiant_Sync_Importer::instance();

// --- driving it --------------------------------------------------------------

function iso( $ts ) { return gmdate( 'Y-m-d\TH:i:s.000\Z', $ts ); }

function lock_file() { return trailingslashit( $GLOBALS['UPLOADS'] ) . 'tcgiant_sync.lock'; }

/** A fresh site: no state, no queue, nothing imported, eBay quiet. */
function fresh_site( $tz = 'UTC', array $state = array() ) {
	$GLOBALS['OPTIONS']  = array();
	$GLOBALS['QUEUE']    = array();
	$GLOBALS['LOG']      = array();
	$GLOBALS['IMPORTED'] = array();
	$GLOBALS['TZ']       = $tz;
	$GLOBALS['EBAY']     = array( 'events' => array(), 'calls' => array(), 'killed' => 0, 'death_bytes' => 0 );
	@unlink( lock_file() );
	if ( $state ) {
		// On top of the plugin's own defaults, as a real state always is.
		update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'status' => 'complete' ), $state ) );
	}
}

/** Listing changes spread evenly between two moments. */
function changes( $count, $from_ts, $to_ts, $bytes_each, $first_id ) {
	$out = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$out[] = array(
			'ts'    => (int) ( $from_ts + ( $to_ts - $from_ts ) * ( $count > 1 ? $i / ( $count - 1 ) : 0 ) ),
			'id'    => sprintf( '%012d', $first_id + $i ),
			'bytes' => $bytes_each,
		);
	}
	return $out;
}

function add_changes( array $changes ) {
	$GLOBALS['EBAY']['events'] = array_merge( $GLOBALS['EBAY']['events'], $changes );
	usort( $GLOBALS['EBAY']['events'], function ( $a, $b ) { return $a['ts'] - $b['ts']; } );
}

function state() { return TCGiant_Sync_Importer::get_sync_state(); }

/** Run one queued action, as Action Scheduler would. */
function run_one() {
	global $IMP;
	$hook = array_shift( $GLOBALS['QUEUE'] );
	if ( 'tcgiant_sync_fetch_delta_events' !== $hook ) {
		return;
	}
	// Each action is a request of its own, with its own clock.
	$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
	try {
		$IMP->fetch_delta_events();
	} catch ( Killed_Request $e ) {
		// A fatal runs no `finally`, so the lock stays behind.
		file_put_contents( lock_file(), (string) time() );
	}
}

/** Run everything queued, and everything that queues. */
function drain( $limit = 5000 ) {
	$runs = 0;
	while ( $GLOBALS['QUEUE'] && $runs < $limit ) {
		run_one();
		$runs++;
	}
	return $runs;
}

/** The scheduled poll, an hour after the last - for the state and the lock. */
function hourly_tick() {
	global $IMP;
	$s = state();
	if ( ! empty( $s['last_activity_ts'] ) ) {
		$s['last_activity_ts'] -= HOUR_IN_SECONDS;
		update_option( TCGiant_Sync_Importer::STATE_OPTION, $s );
	}
	if ( file_exists( lock_file() ) ) {
		file_put_contents( lock_file(), (string) ( (int) file_get_contents( lock_file() ) - HOUR_IN_SECONDS ) );
	}
	$IMP->start_delta_sync();
	drain();
}

function caught_up() {
	$s = state();
	return 'complete' === $s['status'] && strtotime( $s['last_successful_sync_at'] ) > time() - 10 * MINUTE_IN_SECONDS;
}

function logged( $needle ) {
	foreach ( $GLOBALS['LOG'] as $entry ) {
		if ( false !== strpos( $entry[1], $needle ) ) {
			return true;
		}
	}
	return false;
}

function imported_ids( array $changes ) {
	$ids = array();
	foreach ( $changes as $c ) {
		$ids[] = $c['id'];
	}
	return count( array_intersect( $ids, array_keys( $GLOBALS['IMPORTED'] ) ) );
}

function largest_read() {
	$max = 0;
	foreach ( $GLOBALS['EBAY']['calls'] as $call ) {
		if ( 0 === $call['max'] || $call['bytes'] <= $call['max'] ) {
			$max = max( $max, $call['bytes'] );
		}
	}
	return $max;
}

$MB  = 1048576;
$NOW = time();

echo "\nA HEALTHY SITE SEES NO DIFFERENCE\n" . str_repeat( '=', 122 ) . "\n";

// An hour since the last sync, a hundred ordinary changes. This is most shops,
// most of the time, and nothing about their sync may change.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ) ) );
$healthy = changes( 100, $NOW - 50 * MINUTE_IN_SECONDS, $NOW - 60, 5000, 1 );
add_changes( $healthy );
hourly_tick();

$calls = $EBAY['calls'];
check( 'every changed listing imported', imported_ids( $healthy ), 100 );
check( '  each exactly once', max( $IMPORTED ), 1 );
// One read of eBay for the window. Its later runs work from the listings
// kept in brief, where every run used to download the whole window again.
check( 'one read of eBay for the whole window', count( $calls ), 1 );
check( 'all three asked for the same window', count( array_unique( array_map( function ( $c ) { return $c['from'] . $c['to']; }, $calls ) ) ), 1 );
check( '  under the size ceiling', $calls[0]['max'], $CAP );
check( 'finished, caught up', caught_up(), true );
check( '  at the end of the window it read', state()['last_successful_sync_at'], $calls[0]['to'] );
check( 'the window is still the widest', state()['delta_window_minutes'], 2880 );
check( 'nothing narrowed', logged( 'Asking for' ), false );
check( 'nothing chased', (int) state()['delta_chain'], 0 );
check( 'no note of a read in progress left behind', state()['delta_attempt'], '' );
check( 'nothing left queued', $QUEUE, array() );

echo "\nTHE SHOP THAT WROTE IN\n" . str_repeat( '=', 122 ) . "\n";

// Three days behind, four thousand changes, listings with HTML descriptions of
// about 30 KB. A 48-hour window weighs 80 MB; in this model a host dies reading
// anything over 60 MB without a ceiling, which is what the report describes.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - 3 * DAY_IN_SECONDS ) ) );
$EBAY['death_bytes'] = 60 * $MB;
$jon = changes( 4000, $NOW - 3 * DAY_IN_SECONDS, $NOW - 60, 30 * 1024, 100000 );
add_changes( $jon );

$first_window = 0;
foreach ( $jon as $c ) {
	if ( $c['ts'] <= $NOW - 3 * DAY_IN_SECONDS + 2 * DAY_IN_SECONDS ) {
		$first_window += $c['bytes'];
	}
}
check( 'the 48 hour window the old code asked for is past the host\'s limit', $first_window > $EBAY['death_bytes'], true );

for ( $tick = 0; $tick < 6 && ! caught_up(); $tick++ ) {
	hourly_tick();
}

check( 'no request was killed', $EBAY['killed'], 0 );
check( 'every one of the 4000 changes imported', imported_ids( $jon ), 4000 );
check( 'caught up with the present', caught_up(), true );
check( '  in a single scheduled run', $tick, 1 );
check( 'nothing read was over the ceiling', largest_read() <= $CAP, true );
check( 'it said why it was asking for less', logged( 'more than this site can safely read at once' ), true );
check( 'and went back to the widest window once caught up', state()['delta_window_minutes'], 2880 );
printf( "        %d GetSellerEvents calls for 4000 changes; largest read %.1f MB\n", count( $EBAY['calls'] ), largest_read() / $MB );
check( '  at a sensible cost in calls', count( $EBAY['calls'] ) < 4000 / 40 + 60, true );

echo "\nA REQUEST KILLED WHILE READING DOES NOT REPEAT FOR EVER\n" . str_repeat( '=', 122 ) . "\n";

// A host that kills the request below our ceiling: 10 MB, against a 25 MB
// ceiling. A 12-hour window passes the ceiling and still dies. Before, nothing
// was written, and the next run asked for the same window and died again.
fresh_site( 'UTC', array(
	'last_successful_sync_at' => iso( $NOW - 2 * DAY_IN_SECONDS ),
	'delta_window_minutes'    => 720,
) );
$EBAY['death_bytes'] = 10 * $MB;
$killed_site = changes( 2000, $NOW - 2 * DAY_IN_SECONDS, $NOW - 60, 30 * 1024, 200000 );
add_changes( $killed_site );

$IMP->start_delta_sync();
drain();
$attempt_note = state()['delta_attempt'];
check( 'the first read is killed', $EBAY['killed'], 1 );
check( '  leaving the note of what it was reading', $attempt_note, state()['delta_mod_from'] . '|720' );
check( '  and the position where it was', state()['last_successful_sync_at'], iso( $NOW - 2 * DAY_IN_SECONDS ) );

// The next poll comes within the half hour: the sync is presumed alive.
$IMP->start_delta_sync();
check( 'a poll within thirty minutes leaves it be', logged( 'Sync already in progress' ) && array() === $QUEUE, true );

hourly_tick();
check( 'the next hourly poll restarts it', logged( 'presumed to have failed' ), true );
check( '  and the restart halves the window before asking', logged( 'Asking for 360 minutes instead' ), true );
for ( $tick = 0; $tick < 6 && ! caught_up(); $tick++ ) {
	hourly_tick();
}
check( 'it is killed only the once', $EBAY['killed'], 1 );
check( 'every change imported', imported_ids( $killed_site ), 2000 );
check( 'caught up', caught_up(), true );

echo "\nA BURST TOO LARGE EVEN FOR FIFTEEN MINUTES\n" . str_repeat( '=', 122 ) . "\n";

// 2,500 listings revised inside ten minutes, 30 KB each: 75 MB in a window
// that can be narrowed no further. The shortest window is read without a
// ceiling, because the alternative is to skip it.
foreach ( array( 'can read it' => 0, 'cannot read it' => 20 * $MB ) as $label => $death ) {
	fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - 6 * HOUR_IN_SECONDS ) ) );
	$EBAY['death_bytes'] = $death;
	$steady = changes( 300, $NOW - 6 * HOUR_IN_SECONDS, $NOW - 60, 30 * 1024, 300000 );
	$burst  = changes( 2500, $NOW - 3 * HOUR_IN_SECONDS, $NOW - 3 * HOUR_IN_SECONDS + 10 * MINUTE_IN_SECONDS, 30 * 1024, 400000 );
	add_changes( $steady );
	add_changes( $burst );

	for ( $tick = 0; $tick < 30 && ! caught_up(); $tick++ ) {
		hourly_tick();
	}

	$floor_reads = 0;
	foreach ( $EBAY['calls'] as $call ) {
		if ( 0 === $call['max'] ) {
			$floor_reads++;
		}
	}
	check( "a host that $label: the shortest window was read without a ceiling", $floor_reads > 0, true );
	check( '  and the sync caught up', caught_up(), true );

	if ( 0 === $death ) {
		check( '  every change in the burst imported', imported_ids( $burst ), 2500 );
		check( '  and every change around it', imported_ids( $steady ), 300 );
		check( '  nothing stepped over', logged( 'Moving past them' ), false );
	} else {
		// Trying again would only die again, for ever, and stop every later
		// change with it. So after three tries fifteen minutes is stepped
		// over, out loud.
		$skipped = array();
		foreach ( $LOG as $entry ) {
			if ( preg_match( '/The changes between (\S+) and (\S+) could not be read/', $entry[1], $m ) ) {
				$skipped[] = array( strtotime( $m[1] ), strtotime( $m[2] ) );
			}
		}
		$all_fifteen = ! empty( $skipped );
		foreach ( $skipped as $period ) {
			$all_fifteen = $all_fifteen && 15 * MINUTE_IN_SECONDS === $period[1] - $period[0];
		}
		check( '  the window that could not survive was stepped over, saying so', count( $skipped ) > 0, true );
		check( '  each after exactly three tries, not one and not for ever', $EBAY['killed'], 3 * count( $skipped ) );
		check( '  each fifteen minutes long', $all_fifteen, true );
		check( '  pointing to Fetch Inventory', logged( 'run Fetch Inventory to pick up anything altered during that period' ), true );

		// What was lost is exactly what the log names, and nothing else.
		$outside = array_filter( array_merge( $steady, $burst ), function ( $c ) use ( $skipped ) {
			foreach ( $skipped as $period ) {
				if ( $c['ts'] >= $period[0] && $c['ts'] <= $period[1] ) {
					return false;
				}
			}
			return true;
		} );
		check( '  and every change outside those minutes was imported', imported_ids( $outside ), count( $outside ) );
		printf( "        %d stepped over; %d of the 2,500 in the burst imported, the rest in the minutes named in the log\n", count( $skipped ), imported_ids( $burst ) );
	}
}

echo "\nEBAY'S SILENT 3000 CEILING\n" . str_repeat( '=', 122 ) . "\n";

// 5,000 small changes in 47 hours. eBay sends the first 3,000 and nothing to
// say there were more; the old code marked the window read and the other
// 2,000 were never seen.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - 47 * HOUR_IN_SECONDS ) ) );
$many = changes( 5000, $NOW - 47 * HOUR_IN_SECONDS, $NOW - 60, 2048, 500000 );
add_changes( $many );
check( 'the first window holds more than eBay will send', count( array_filter( $many, function ( $c ) use ( $NOW ) { return $c['ts'] >= $NOW - 47 * HOUR_IN_SECONDS - 300; } ) ) > 3000, true );

for ( $tick = 0; $tick < 4 && ! caught_up(); $tick++ ) {
	hourly_tick();
}
check( 'a full answer is read again in halves', logged( 'eBay sent its maximum of 3000 changed listings for those' ), true );
check( 'every one of the 5000 imported', imported_ids( $many ), 5000 );
check( 'caught up', caught_up(), true );

echo "\nTHE ESTABLISHED PATHS ARE UNCHANGED\n" . str_repeat( '=', 122 ) . "\n";

// A network failure holds the position and leaves the window as it was, and
// the next scheduled sync reads it again from the top: a repeat, not a hole.
fresh_site( 'UTC', array(
	'status'                  => 'scanning',
	'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ),
	'delta_mod_from'          => iso( $NOW - HOUR_IN_SECONDS - 300 ),
	'delta_offset'            => 40,
	'delta_cursor'            => '000000600039',
	'delta_window_from'       => iso( $NOW - HOUR_IN_SECONDS - 300 ),
	'delta_window_to'         => iso( $NOW - 120 ),
) );
$net = changes( 100, $NOW - 50 * MINUTE_IN_SECONDS, $NOW - 180, 5000, 600000 );
add_changes( $net );
$EBAY['fail'] = 'cURL error 6: Could not resolve host: api.ebay.com';
$QUEUE[]      = 'tcgiant_sync_fetch_delta_events';
drain();
check( 'a network failure keeps the position', state()['last_successful_sync_at'], iso( $NOW - HOUR_IN_SECONDS ) );
check( '  asked for the window it was in', $EBAY['calls'][0]['to'], iso( $NOW - 120 ) );
check( '  and did not narrow it', state()['delta_window_minutes'] ?? 2880, 2880 );
$EBAY['fail'] = false;
hourly_tick();
check( '  the next scheduled sync imports every change', imported_ids( $net ), 100 );

// A timeout used to be handled the same way - and a window so large that eBay
// could never send it in time was asked for again, identically, for ever.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - 3 * HOUR_IN_SECONDS ) ) );
$slow = changes( 100, $NOW - 3 * HOUR_IN_SECONDS, $NOW - 60, 5000, 650000 );
add_changes( $slow );
$EBAY['fail'] = 'cURL error 28: Operation timed out after 45001 milliseconds with 0 bytes received';
$IMP->start_delta_sync();
run_one();
// Half the three hours and five minutes asked for - or a minute more if the
// clock ticked over while this ran.
check( 'a timeout narrows the window instead', in_array( state()['delta_window_minutes'], array( 92, 93 ), true ), true );
check( '  holding the position', state()['last_successful_sync_at'], iso( $NOW - 3 * HOUR_IN_SECONDS ) );
check( '  and tries again straight away', $QUEUE, array( 'tcgiant_sync_fetch_delta_events' ) );
$EBAY['fail'] = false;
drain();
check( '  every change imported once eBay answers', imported_ids( $slow ), 100 );

// eBay refusing outright still narrows, and at the shortest window still
// steps over, as it did.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ), 'delta_window_minutes' => 30 ) );
$EBAY['refuse'] = true;
$IMP->start_delta_sync();
run_one();
check( 'eBay\'s refusal still halves the window', state()['delta_window_minutes'], 15 );
run_one();
check( '  and at fifteen minutes still steps over, as before', state()['last_successful_sync_at'], iso( strtotime( state()['delta_mod_from'] ) + 15 * MINUTE_IN_SECONDS ) );

echo "\nA WINDOW, ONCE NARROWED, WIDENS AGAIN\n" . str_repeat( '=', 122 ) . "\n";

// Narrowed by some earlier burst, twenty hours behind, and now quiet. It used
// to stay narrow until it caught up - and being narrow was what stopped it
// catching up.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - 20 * HOUR_IN_SECONDS ), 'delta_window_minutes' => 90 ) );
add_changes( changes( 50, $NOW - 20 * HOUR_IN_SECONDS, $NOW - 60, 5000, 700000 ) );
hourly_tick();
$widths = array_map( function ( $c ) { return (int) ( ( strtotime( $c['to'] ) - strtotime( $c['from'] ) ) / 60 ); }, $EBAY['calls'] );
check( 'each quiet window asks for twice the last', array_slice( $widths, 0, 3 ), array( 90, 180, 360 ) );
check( 'caught up in the one scheduled run', caught_up(), true );
check( '  and back to the widest window', state()['delta_window_minutes'], 2880 );

// A site months behind reads a bounded run of windows, then waits.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - 90 * DAY_IN_SECONDS ) ) );
hourly_tick();
check( 'a long backlog is read at most 25 windows per run', count( $EBAY['calls'] ), 25 );
check( '  and says so', logged( 'Read 25 windows of changes in a row' ), true );
check( '  leaving nothing queued until the next run', $QUEUE, array() );
$first_run = count( $EBAY['calls'] );
hourly_tick();
check( '  and the next run carries on from there', count( $EBAY['calls'] ) > $first_run, true );
check( '  until, ninety days in two runs, it has caught up', caught_up(), true );

// The allowance is shared by every shop on the plugin, so one with a long
// backlog leaves the rest of it for the next scheduled run.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - 10 * DAY_IN_SECONDS ) ) );
$EBAY['budget'] = 5000;
hourly_tick();
check( 'with most of the day\'s eBay allowance gone, a backlog waits', count( $EBAY['calls'] ), 1 );
check( '  and says so', logged( 'Most of today\'s eBay allowance is used' ), true );

echo "\nA LISTING THAT CHANGES AGAIN PART WAY THROUGH ITS WINDOW\n" . str_repeat( '=', 122 ) . "\n";

// A hundred changes, worked through forty at a time. Between the first run and
// the second, one of the first forty sells again, which moves it on to a later
// window. Counting from the top then jumped over the forty-first listing, and
// no later window would ever have included it.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - 3 * DAY_IN_SECONDS ) ) );
$moving = changes( 100, $NOW - 3 * DAY_IN_SECONDS + HOUR_IN_SECONDS, $NOW - 2 * DAY_IN_SECONDS, 5000, 960000 );
add_changes( $moving );
$IMP->start_delta_sync();
run_one();
check( 'the first run imports the first forty', count( $IMPORTED ), 40 );
foreach ( $EBAY['events'] as $i => $event ) {
	if ( '000000960004' === $event['id'] ) {
		$EBAY['events'][ $i ]['ts'] = $NOW - HOUR_IN_SECONDS;
	}
}
add_changes( array() );
drain();
check( 'after the fifth sells again, the forty-first is still imported', isset( $IMPORTED['000000960040'] ), true );
check( '  and every one of the hundred', imported_ids( $moving ), 100 );
check( '  the one that sold again is read again, in its new window', $IMPORTED['000000960004'], 2 );

echo "\nREQUESTS STOPPED BY THE HOST\n" . str_repeat( '=', 122 ) . "\n";

// At the shortest window, one stopped request is not enough to give up fifteen
// minutes of changes: hosts recycle workers and sites are redeployed.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ), 'delta_window_minutes' => 15 ) );
$floor = changes( 30, $NOW - 55 * MINUTE_IN_SECONDS, $NOW - 60, 5000, 970000 );
add_changes( $floor );
$EBAY['kill_next'] = 1;
for ( $tick = 0; $tick < 8 && ! caught_up(); $tick++ ) {
	hourly_tick();
}
check( 'one stopped request at the shortest window is tried again', logged( 'Trying again (attempt 2 of 3)' ), true );
check( '  nothing stepped over', logged( 'Moving past them' ), false );
check( '  every change imported', imported_ids( $floor ), 30 );
check( '  caught up', caught_up(), true );

// A host that stops requests far below the ceiling - at 8 MB against 25 - on a
// shop that is caught up and polled hourly, when an 18 MB hour arrives. The
// window halved, then doubled straight back into the same wall, again and
// again, half an hour lost each time.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ) ) );
$EBAY['death_bytes'] = 8 * $MB;
$wall = changes( 600, $NOW - 55 * MINUTE_IN_SECONDS, $NOW - 60, 30 * 1024, 1100000 );
add_changes( $wall );
for ( $tick = 0; $tick < 10 && ! caught_up(); $tick++ ) {
	hourly_tick();
}
printf( "        stopped %d time(s); ceiling now %.1f MB\n", $EBAY['killed'], (int) ( state()['delta_byte_ceiling'] ?? 0 ) / $MB );
check( 'a host that stops requests below the ceiling lowers it', (int) ( state()['delta_byte_ceiling'] ?? 0 ) < $CAP && (int) ( state()['delta_byte_ceiling'] ?? 0 ) > 0, true );
check( '  for a week, not for good', abs( (int) state()['delta_byte_ceiling_until'] - ( time() + 7 * DAY_IN_SECONDS ) ) < 60, true );
check( '  stopped no more than three times', $EBAY['killed'] <= 3, true );
check( '  every change imported', imported_ids( $wall ), 600 );
check( '  caught up', caught_up(), true );

// An hour of changes over the ceiling on a caught-up shop. The window is 48
// hours, but the request only covers the hour: halving the 48 hours took six
// refusals, each downloaded in full, before it got below the hour.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ) ) );
$hour = changes( 1000, $NOW - 55 * MINUTE_IN_SECONDS, $NOW - 60, 30 * 1024, 1200000 );
add_changes( $hour );
hourly_tick();
$refused = 0;
foreach ( $EBAY['calls'] as $call ) {
	if ( $call['max'] > 0 && $call['bytes'] > $call['max'] ) {
		$refused++;
	}
}
check( 'an hour over the ceiling is refused once, not six times', $refused, 1 );
check( '  every change imported', imported_ids( $hour ), 1000 );

echo "\nEMERGENCY STOP\n" . str_repeat( '=', 122 ) . "\n";

$press_stop = function () {
	TCGiant_Sync_Importer::stop_all();
	TCGiant_Sync_Importer::update_sync_state( array( 'status' => 'stopped' ) );
	$GLOBALS['EBAY']['on_call'] = null;
};

// Pressed while a run is reading eBay. The run finishes what it has and goes
// no further, rather than queueing the next window behind the button's back.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - 10 * DAY_IN_SECONDS ) ) );
$EBAY['on_call'] = $press_stop;
$IMP->start_delta_sync();
drain();
check( 'Stop during a backlog: no further windows', count( $EBAY['calls'] ), 1 );
check( '  nothing left queued', $QUEUE, array() );

fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ) ) );
$halfway = changes( 100, $NOW - 50 * MINUTE_IN_SECONDS, $NOW - 60, 5000, 980000 );
add_changes( $halfway );
$EBAY['on_call'] = $press_stop;
$IMP->start_delta_sync();
drain();
check( 'Stop part way through a window: no further runs', count( $EBAY['calls'] ), 1 );
check( '  and it still says stopped', state()['status'], 'stopped' );
hourly_tick();
check( '  the next scheduled sync carries on, and gets everything', imported_ids( $halfway ), 100 );

echo "\nA SLOW QUEUE\n" . str_repeat( '=', 122 ) . "\n";

// A live sync whose next run has waited 45 minutes in the queue - a quiet site
// whose tasks only run when someone visits. Thirty minutes of silence is not
// death while the next run is still there to come.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ) ) );
add_changes( changes( 100, $NOW - 50 * MINUTE_IN_SECONDS, $NOW - 60, 5000, 990000 ) );
$IMP->start_delta_sync();
run_one();
$s = state();
$s['last_activity_ts'] -= 45 * MINUTE_IN_SECONDS;
update_option( TCGiant_Sync_Importer::STATE_OPTION, $s );
$IMP->start_delta_sync();
check( 'a sync whose next run has queued for 45 minutes is left alone', logged( 'presumed to have failed' ), false );
check( '  its place kept', state()['delta_offset'], 40 );
check( '  and its next run still queued, once', $QUEUE, array( 'tcgiant_sync_fetch_delta_events' ) );

echo "\nTHE DASHBOARD, AND ITEMS SYNCED BY HAND\n" . str_repeat( '=', 122 ) . "\n";

// The 15,000-product shop's log: a delta batch of 40 began, three items were
// imported, the run died - and 35 minutes later the dashboard wrote "Sync
// complete! 120 imported, 0 errors out of 0 total". The dashboard finishes any
// sync showing "importing" whose queue of item imports is empty, and a delta
// batch showed exactly that for as long as it ran, alive or dead.
$admin = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' );
check( 'the dashboard still finishes only what says "importing"', false !== strpos( $admin, "if ( 'importing' === \$state['status'] && function_exists( 'as_get_scheduled_actions' ) ) {" ), true );

fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ) ) );
add_changes( changes( 100, $NOW - 50 * MINUTE_IN_SECONDS, $NOW - 60, 5000, 1300000 ) );
$STATUS_DURING = array();
hourly_tick();
check( 'which a delta batch never says now, from the first item to the last', array_unique( $STATUS_DURING ), array( 'scanning' ) );

// A batch that died leaves "scanning" behind, which the dashboard leaves
// alone, and the stall check picks up after thirty minutes as it should.
fresh_site( 'UTC', array(
	'status'                  => 'scanning',
	'sync_mode'               => 'delta',
	'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ),
	'delta_mod_from'          => iso( $NOW - HOUR_IN_SECONDS - 300 ),
	'total_found'             => 100,
) );
$died = changes( 100, $NOW - 50 * MINUTE_IN_SECONDS, $NOW - 60, 5000, 1310000 );
add_changes( $died );
hourly_tick();
check( 'a batch that died is restarted by the next scheduled run', logged( 'presumed to have failed' ), true );
check( '  and every change imported', imported_ids( $died ), 100 );

$complete = new ReflectionMethod( 'TCGiant_Sync_Importer', 'check_sync_completion' );
$complete->setAccessible( true );

// Items synced by hand while a delta sync is part way through a window join
// it rather than take it over.
fresh_site( 'UTC', array(
	'status'                  => 'scanning',
	'sync_mode'               => 'delta',
	'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ),
) );
$IMP->start_specific_sync( array( '110000000001' ) );
check( 'items synced by hand mid-delta leave it scanning', state()['status'], 'scanning' );
check( '  and still a delta sync', state()['sync_mode'], 'delta' );
$QUEUE = array();
$complete->invoke( $IMP );
check( '  so finishing them cannot finish the delta sync', state()['status'], 'scanning' );

// On their own they are their own kind of sync, and finishing them does not
// move the position the delta sync reads from - which skipped every change
// on eBay since its last window.
fresh_site( 'UTC', array(
	'status'                  => 'complete',
	'sync_mode'               => 'delta',
	'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ),
) );
$IMP->start_specific_sync( array( '110000000001' ) );
check( 'items synced by hand on their own say so', state()['sync_mode'], 'specific' );
$QUEUE = array();
$complete->invoke( $IMP );
check( '  and are finished', state()['status'], 'complete' );
check( '  without moving the delta sync\'s position', state()['last_successful_sync_at'], iso( $NOW - HOUR_IN_SECONDS ) );

// A full sync, which has read everything, still does.
fresh_site( 'UTC', array(
	'status'                  => 'importing',
	'sync_mode'               => 'full',
	'total_queued'            => 10,
	'total_processed'         => 10,
	'last_successful_sync_at' => iso( $NOW - DAY_IN_SECONDS ),
) );
$complete->invoke( $IMP );
check( 'a full sync from before 3.27 still moves it to now', strtotime( state()['last_successful_sync_at'] ) >= $NOW - 5, true );

// But to when it STARTED. A sale on a page already read, made before the
// scan finished, lies between the two - and recording the finish skipped it.
fresh_site( 'UTC', array(
	'status'                  => 'importing',
	'sync_mode'               => 'full',
	'total_queued'            => 10,
	'total_processed'         => 10,
	'full_started_at'         => iso( $NOW - 40 * MINUTE_IN_SECONDS ),
	'last_successful_sync_at' => iso( $NOW - DAY_IN_SECONDS ),
) );
$complete->invoke( $IMP );
check( 'a full sync finishing moves it to when the sync started', state()['last_successful_sync_at'], iso( $NOW - 40 * MINUTE_IN_SECONDS ) );

$src = php_strip_whitespace( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-importer.php' );
check( '  as does a full sync that found nothing', substr_count( $src, "'last_successful_sync_at' => self::full_sync_position( \$state )" ), 1 );
check( '  and the start is recorded when it begins', false !== strpos( $src, "'full_started_at' => gmdate(" ), true );

echo "\nTIMEZONES\n" . str_repeat( '=', 122 ) . "\n";

// The stall check used to read a local time as if it were UTC, so its idea of
// "thirty minutes" was off by the site's whole UTC offset.
foreach ( array( 'UTC', 'Europe/London', 'Australia/Sydney', 'Pacific/Auckland', 'America/New_York', 'America/Los_Angeles' ) as $zone ) {
	fresh_site( $zone );
	TCGiant_Sync_Importer::update_sync_state( array( 'status' => 'scanning' ) );
	$idle   = TCGiant_Sync_Importer::sync_idle_seconds( state() );
	$old    = time() - (int) mysql2date( 'U', state()['last_activity'], false );
	$offset = ( new DateTime( 'now', new DateTimeZone( $zone ) ) )->getOffset();
	check( sprintf( '%-20s a sync that just wrote has been idle 0s (was %+ds)', $zone, $old ), $idle <= 1, true );
	check( '  and the old reading was off by exactly the offset', abs( $old + $offset ) <= 1, true );
}

// A state written by the old version has no timestamp. Its local time is now
// converted properly rather than taken for UTC.
foreach ( array( 'Australia/Sydney', 'America/Los_Angeles' ) as $zone ) {
	fresh_site( $zone );
	update_option( TCGiant_Sync_Importer::STATE_OPTION, array(
		'status'        => 'scanning',
		'last_activity' => ( new DateTime( '-31 minutes', new DateTimeZone( $zone ) ) )->format( 'Y-m-d H:i:s' ),
	) );
	$idle = TCGiant_Sync_Importer::sync_idle_seconds( state() );
	check( sprintf( '%-20s a state from before the update, 31 minutes old, reads 31', $zone ), abs( $idle - 31 * 60 ) <= 2, true );
}

// The bug as a shop in the Americas lived it: every hourly poll found the sync
// "stalled" and began its window again from the top, so a window needing more
// than an hour never finished.
fresh_site( 'America/Los_Angeles', array( 'last_successful_sync_at' => iso( $NOW - 30 * MINUTE_IN_SECONDS ) ) );
$la = changes( 300, $NOW - 25 * MINUTE_IN_SECONDS, $NOW - 60, 5000, 800000 );
add_changes( $la );
$IMP->start_delta_sync();
run_one();
run_one();
$place = state()['delta_offset'];
$IMP->start_delta_sync();
check( 'Los Angeles: a poll in the middle of a window leaves it running', logged( 'presumed to have failed' ), false );
check( '  two runs in, at 80 of 300', $place, 80 );
check( '  and still there after the poll', state()['delta_offset'], 80 );
check( '  with its next run still queued', $QUEUE, array( 'tcgiant_sync_fetch_delta_events' ) );
drain();
check( '  every change imported', imported_ids( $la ), 300 );
check( '  each once', max( $IMPORTED ), 1 );

// And in Sydney a sync that has died is released after thirty minutes, not
// eleven and a half hours.
fresh_site( 'Australia/Sydney', array( 'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ) ) );
TCGiant_Sync_Importer::update_sync_state( array( 'status' => 'scanning' ) );
$s = state();
$s['last_activity_ts'] -= 31 * MINUTE_IN_SECONDS;
update_option( TCGiant_Sync_Importer::STATE_OPTION, $s );
$IMP->start_delta_sync();
check( 'Sydney: a sync silent for 31 minutes is presumed dead', logged( 'presumed to have failed' ), true );

$cron = php_strip_whitespace( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-cron.php' );
check( 'the daily 24-hour reset uses the same clock', false !== strpos( $cron, 'TCGiant_Sync_Importer::sync_idle_seconds( $state )' ), true );
check( '  and not the old one', false === strpos( $cron, "strtotime( \$state['last_activity'] )" ), true );

echo "\nA LISTING THAT ENDS WHILE ITS WINDOW IS BEING WORKED THROUGH\n" . str_repeat( '=', 122 ) . "\n";

// A big window takes many runs, and later runs work from the listings kept in
// brief when it was first read. A coin that was live then may sell on the
// website meanwhile - the plugin ends its eBay listing - before the delta
// reaches it. eBay's answer to GetItem says so; what the window held does not.
// Saved as live, its stock would be set from eBay's figures and the sold coin
// put back on sale, which is the bug this release exists to fix.
fresh_site( 'UTC', array( 'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ) ) );
$late = changes( 100, $NOW - 50 * MINUTE_IN_SECONDS, $NOW - 60, 5000, 950000 );
add_changes( $late );
$EBAY['now_ended'] = array( '000000950070' => 'Completed' );
hourly_tick();

check( 'a listing that ended since the window was read is not saved as live', isset( $IMPORTED['000000950070'] ), false );
check( '  the rest of the window is', imported_ids( $late ), 99 );
check( '  and the window still finishes', state()['status'], 'complete' );

echo "\nA SITE UPDATED PART WAY THROUGH A WINDOW\n" . str_repeat( '=', 122 ) . "\n";

// The state 3.25.0 leaves mid-window: a place in it, and no record of which
// window. It must carry on, not start over and not skip.
fresh_site( 'UTC', array(
	'status'                  => 'scanning',
	'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ),
	'delta_mod_from'          => iso( $NOW - HOUR_IN_SECONDS - 300 ),
	'delta_offset'            => 40,
	'last_activity'           => current_time( 'mysql' ),
) );
$mid = changes( 100, $NOW - 50 * MINUTE_IN_SECONDS, $NOW - 180, 5000, 900000 );
add_changes( $mid );
$QUEUE[] = 'tcgiant_sync_fetch_delta_events';
run_one();
check( 'it carries on from 41', isset( $IMPORTED['000000900040'] ) && ! isset( $IMPORTED['000000900000'] ), true );
check( '  and from then on knows its window', state()['delta_window_from'], iso( $NOW - HOUR_IN_SECONDS - 300 ) );

// A place left over from some other window is not applied to this one.
fresh_site( 'UTC', array(
	'status'                  => 'scanning',
	'last_successful_sync_at' => iso( $NOW - HOUR_IN_SECONDS ),
	'delta_mod_from'          => iso( $NOW - HOUR_IN_SECONDS - 300 ),
	'delta_offset'            => 40,
	'delta_window_from'       => iso( $NOW - 5 * HOUR_IN_SECONDS ),
	'delta_window_to'         => iso( $NOW - 4 * HOUR_IN_SECONDS ),
) );
add_changes( $mid );
$QUEUE[] = 'tcgiant_sync_fetch_delta_events';
run_one();
check( 'a place from another window starts this one at 1', isset( $IMPORTED['000000900000'] ), true );

@unlink( lock_file() );
@rmdir( $UPLOADS );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
