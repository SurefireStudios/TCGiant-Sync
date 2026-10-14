<?php
/**
 * Harness: a delta run that PHP kills part way through its batch.
 *
 * Two shops on 3.26.1 had a sync that restarted the same window every hour,
 * for ever. One sat on a fifteen-minute window from eleven days earlier; the
 * other re-read the same two days every hour. Both runs died AFTER eBay had
 * answered, while importing - and 3.26.0's note of a killed read only covered
 * the read. Nothing in the batch saved its place, and the hourly restart wiped
 * what an earlier batch had saved, so every restart began at the first
 * listing again.
 *
 * test-delta-window.php cannot show this. It models a kill as an exception,
 * and the batch's own catch swallows exceptions - which is exactly why its
 * assertions never saw the livelock. A real kill is a PHP fatal: no catch, no
 * finally, nothing after it in the request. So here every request is a PHP
 * process of its own, options and the queue live in files the way they live
 * in the database, and the fatal is real - a memory limit exhausted inside the
 * product save, as on the shops that wrote in.
 *
 * Some hosts end a long request without any PHP error - a limit of their own,
 * enforced from outside PHP. Action Scheduler then goes on counting the action
 * that request was running as scheduled, for five minutes, and on 3.27.1 the
 * dying run took that for its own successor and handed nothing on. The queue
 * here keeps a running action visible the way Action Scheduler does, and
 * forgets it only when its fatal-error monitor sees a PHP fatal.
 *
 * Loaded for real: TCGiant_Sync_Importer and TCGiant_Sync_Runtime.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-78s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$ROOT  = str_replace( '\\', '/', dirname( __DIR__ ) );
$WORLD = str_replace( '\\', '/', sys_get_temp_dir() ) . '/tcg-delta-livelock';

if ( ! is_dir( $WORLD ) ) {
	mkdir( $WORLD, 0777, true );
}

// --- the child: one request of a WordPress site, as far as the delta uses it --
$COMMON = <<<'PHP'
<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$DIR = __DIR__ . '/data';

function jload( $f, $d ) { $p = $GLOBALS['DIR'] . "/$f.json"; return is_file( $p ) ? json_decode( file_get_contents( $p ), true ) : $d; }
function jsave( $f, $v ) { file_put_contents( $GLOBALS['DIR'] . "/$f.json", json_encode( $v ) ); }
function scenario( $key, $default = null ) { $s = jload( 'scenario', array() ); return array_key_exists( $key, $s ) ? $s[ $key ] : $default; }

class WP_Error {
	private $c; private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
}
class Harness_Wpdb {
	public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts';
	public function prepare( $q ) { return $q; }
	public function get_var( $q ) { return null; }
}
$wpdb = new Harness_Wpdb();
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $t ) { return $t; }
function add_action() {}
function update_post_meta() {}
function wp_clear_scheduled_hook() {}
function get_option( $n, $d = false ) { $o = jload( 'options', array() ); return array_key_exists( $n, $o ) ? $o[ $n ] : $d; }
function update_option( $n, $v, $autoload = null ) { $o = jload( 'options', array() ); $o[ $n ] = $v; jsave( 'options', $o ); return true; }
function delete_option( $n ) { $o = jload( 'options', array() ); unset( $o[ $n ] ); jsave( 'options', $o ); }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['DIR'] ); }
function trailingslashit( $p ) { return rtrim( $p, '/\\' ) . '/'; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_gmt_from_date( $d, $f = 'Y-m-d H:i:s' ) { return gmdate( $f, strtotime( $d ) ); }

// Action Scheduler: a queue of [hook, args], and the action being run, which
// it counts as scheduled until the run is marked finished or failed.
function as_enqueue_async_action( $hook, $args = array() ) { $q = jload( 'queue', array() ); $q[] = array( $hook, $args ); jsave( 'queue', $q ); return 1; }
function as_schedule_single_action( $w, $hook, $args = array() ) { return as_enqueue_async_action( $hook, $args ); }
function as_next_scheduled_action( $hook, $args = null ) {
	$running = jload( 'running', null );
	if ( is_array( $running ) && $running[0] === $hook ) { return true; }
	foreach ( jload( 'queue', array() ) as $a ) {
		if ( $a[0] === $hook && ( null === $args || $a[1] === $args ) ) { return true; }
	}
	return false;
}
function as_get_scheduled_actions( $args, $return = 'objects' ) {
	$found = array();
	foreach ( jload( 'queue', array() ) as $i => $a ) {
		if ( $a[0] === ( $args['hook'] ?? '' ) ) { $found[] = $i + 1; }
	}
	return 'pending' === ( $args['status'] ?? '' ) ? $found : array();
}
function as_unschedule_all_actions( $hook ) { jsave( 'queue', array_values( array_filter( jload( 'queue', array() ), function ( $a ) use ( $hook ) { return $a[0] !== $hook; } ) ) ); }

// The log, as the real logger now writes it: warnings and errors at once,
// the rest on shutdown.
class TCGiant_Sync_Logger {
	public static $buf = array();
	public static function log( $m, $l = 'info' ) {
		if ( ! self::$buf ) { register_shutdown_function( array( __CLASS__, 'flush' ) ); }
		self::$buf[] = "[$l] $m";
		if ( 'warning' === $l || 'error' === $l ) { self::flush(); }
	}
	public static function error( $m ) { self::log( $m, 'error' ); }
	public static function warning( $m ) { self::log( $m, 'warning' ); }
	public static function write_now( $m, $l = 'error' ) { self::$buf[] = "[$l] $m"; self::flush(); }
	public static function flush() {
		if ( self::$buf ) { file_put_contents( $GLOBALS['DIR'] . '/activity.log', implode( "\n", self::$buf ) . "\n", FILE_APPEND ); }
		self::$buf = array();
	}
}
class TCGiant_Sync_OAuth { public static function instance() { static $i; return $i ?: ( $i = new self() ); } public function get_settings() { return array(); } }
class TCGiant_Sync_Entitlements { public static function instance() { static $i; return $i ?: ( $i = new self() ); } public function can_import() { return true; } public function get_active_product_count() { return 0; } public function get_free_limit() { return 0; } }
class TCGiant_Sync_Image_Localizer { public static function set_external_images() {} }
class TCGiant_Sync_Inventory {}

// A clock that moves only when work is done, for the 30-second host.
$FAKE_NOW = null;
if ( null !== scenario( 'fake_clock' ) ) {
	$FAKE_NOW = (float) scenario( 'fake_clock' );
	$_SERVER['REQUEST_TIME_FLOAT'] = $FAKE_NOW;
	$FAKE_NOW += (float) scenario( 'read_seconds', 0 );
}

class TCGiant_Sync_Mapper {
	public static $last_skipped = false;
	public static function instance() { static $i; return $i ?: ( $i = new self() ); }
	public function map_ebay_to_woo( $item ) { return array( 'id' => $item['ItemID'], 'price' => '1.00', 'stock_quantity' => 1, 'attributes' => array(), 'images' => array(), 'variations' => array() ); }
	public function save_as_product( &$data ) {
		$id = (string) $data['id'];

		$per = jload( 'per_request', array() );
		$per[ count( $per ) - 1 ]++;
		jsave( 'per_request', $per );

		$dies = in_array( $id, (array) scenario( 'kill_always', array() ), true );
		if ( ! $dies && in_array( $id, (array) scenario( 'kill_once', array() ), true ) ) {
			$done = jload( 'killed_once', array() );
			if ( ! in_array( $id, $done, true ) ) {
				$done[] = $id;
				jsave( 'killed_once', $done );
				$dies = true;
			}
		}

		if ( $dies ) {
			// A real, uncatchable fatal: what a host's memory_limit does.
			ini_set( 'memory_limit', '32M' );
			$x = str_repeat( 'x', 64 * 1048576 );
		}

		// A host that ends the request from outside PHP: no error at all,
		// just the end of the request, shutdown functions and all.
		if ( in_array( $id, (array) scenario( 'end_always', array() ), true ) ) {
			exit;
		}

		$imp = jload( 'imported', array() );
		$imp[ $id ] = ( $imp[ $id ] ?? 0 ) + 1;
		jsave( 'imported', $imp );

		if ( null !== $GLOBALS['FAKE_NOW'] ) {
			$GLOBALS['FAKE_NOW'] += (float) scenario( 'seconds_per_listing', 0 );
		}
		return 1000 + count( $imp );
	}
}

// eBay. GetSellerEvents sends what it really sends - no item specifics or
// pictures - so every listing costs a GetItem, as in the shops' logs.
class TCGiant_Sync_API {
	const DAILY_CALL_LIMIT = 50000; const DAILY_CALL_SOFT_CEILING = 0.80;
	public static function instance() { static $i; return $i ?: ( $i = new self() ); }
	public static function get_events_byte_limit() { return 26843545; }
	public function get_last_body_bytes() { return 4000 * (int) scenario( 'listings' ); }
	public function get_remaining_daily_budget() { return 40000; }
	public function get_item( $id ) {
		return array( 'Item' => array( 'ItemID' => $id, 'Title' => 'Part ' . $id, 'SellingStatus' => array( 'ListingStatus' => 'Active' ), 'ItemSpecifics' => array(), 'PictureDetails' => array( 'PictureURL' => 'x' ) ) );
	}
	public function get_seller_events( $from, $to, $max = 0 ) {
		$c = jload( 'calls', array() ); $c[] = array( $from, $to ); jsave( 'calls', $c );
		$items = array();
		// The listings belong to the stuck window only; the windows after it,
		// which the sync goes straight on to once it is through, are quiet.
		if ( $from !== scenario( 'stuck_from' ) ) {
			return array( 'Ack' => 'Success', 'ItemArray' => array() );
		}
		for ( $i = 0; $i < (int) scenario( 'listings' ); $i++ ) {
			$items[] = array( 'ItemID' => (string) ( 174260628275 + $i * 7919 ), 'Title' => 't', 'SellingStatus' => array( 'ListingStatus' => 'Active' ) );
		}
		shuffle( $items ); // eBay's order is not ours; the importer sorts.
		return array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $items ) );
	}
}

require '__ROOT__/includes/class-tcgiant-sync-runtime.php';
require '__ROOT__/includes/class-tcgiant-sync-importer.php';

if ( null !== $GLOBALS['FAKE_NOW'] ) {
	TCGiant_Sync_Runtime::$clock = function () { return $GLOBALS['FAKE_NOW']; };
}
PHP;

$POLL = <<<'PHP'
<?php
require __DIR__ . '/common.php';
TCGiant_Sync_Importer::instance()->start_delta_sync();
PHP;

$RUNQ = <<<'PHP'
<?php
require __DIR__ . '/common.php';
$q = jload( 'queue', array() );
$a = array_shift( $q );
jsave( 'queue', $q );
if ( $a && 'tcgiant_sync_fetch_delta_events' === $a[0] ) {
	// Marked running, and only marked failed on a PHP fatal - by Action
	// Scheduler's monitor on WordPress's shutdown, ahead of the plugin's own.
	jsave( 'running', $a );
	register_shutdown_function( function () {
		$e = error_get_last();
		if ( is_array( $e ) && in_array( (int) $e['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			jsave( 'running', null );
		}
	} );
	$per = jload( 'per_request', array() ); $per[] = 0; jsave( 'per_request', $per );
	TCGiant_Sync_Importer::instance()->fetch_delta_events();
	jsave( 'running', null );
} elseif ( $a && 'tcgiant_sync_settle_item' === $a[0] ) {
	$s = jload( 'settled', array() ); $s[] = $a[1][0]; jsave( 'settled', $s );
}
PHP;

file_put_contents( "$WORLD/common.php", str_replace( '__ROOT__', $ROOT, $COMMON ) );
file_put_contents( "$WORLD/poll.php", $POLL );
file_put_contents( "$WORLD/runq.php", $RUNQ );

// --- driving it --------------------------------------------------------------

function jget( $f, $d ) { $p = $GLOBALS['WORLD'] . "/data/$f.json"; return is_file( $p ) ? json_decode( file_get_contents( $p ), true ) : $d; }
function jput( $f, $v ) { file_put_contents( $GLOBALS['WORLD'] . "/data/$f.json", json_encode( $v ) ); }

/**
 * A site whose delta was left "scanning" by a run that died over an hour ago,
 * as both shops' were.
 */
function stuck_site( array $scenario ) {
	$d = $GLOBALS['WORLD'] . '/data';
	if ( is_dir( $d ) ) {
		foreach ( glob( "$d/*" ) as $f ) { unlink( $f ); }
	} else {
		mkdir( $d, 0777, true );
	}

	$from_last = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 11 * 86400 );

	$scenario['stuck_from'] = gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( $from_last ) - 300 );
	jput( 'scenario', $scenario );
	jput( 'options', array( 'tcgiant_sync_state' => array(
		'status'                  => 'scanning',
		'sync_mode'               => 'delta',
		'last_successful_sync_at' => $from_last,
		'delta_mod_from'          => gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( $from_last ) - 300 ),
		'delta_window_minutes'    => 15,
		'delta_attempt'           => '',
		'last_activity_ts'        => time() - 3700,
	) ) );

	return $from_last;
}

/** The hourly poll, then every queued action, each in a process of its own. */
function hour( $flags = '' ) {
	$php = '"' . PHP_BINARY . '" ' . $flags;
	$w   = $GLOBALS['WORLD'];

	shell_exec( "$php \"$w/poll.php\" 2>&1" );

	for ( $r = 0; $r < 400; $r++ ) {
		if ( ! jget( 'queue', array() ) ) {
			break;
		}
		shell_exec( "$php -d display_errors=stderr \"$w/runq.php\" 2>&1" );
	}
}

/** An hour passes. Action Scheduler has long since failed a dead action. */
function an_hour_passes() {
	jput( 'running', null );
	$o = jget( 'options', array() );
	$o['tcgiant_sync_state']['last_activity_ts'] = ( $o['tcgiant_sync_state']['last_activity_ts'] ?? time() ) - 3600;
	if ( isset( $o['tcgiant_sync_delta_progress']['ts'] ) ) {
		$o['tcgiant_sync_delta_progress']['ts'] -= 3600;
	}
	jput( 'options', $o );
	$lock = $GLOBALS['WORLD'] . '/data/tcgiant_sync.lock';
	if ( is_file( $lock ) ) {
		file_put_contents( $lock, (string) ( (int) file_get_contents( $lock ) - 3600 ) );
	}
}

function stuck_reads() {
	$from = jget( 'scenario', array() )['stuck_from'] ?? '';
	return count( array_filter( jget( 'calls', array() ), function ( $c ) use ( $from ) { return $c[0] === $from; } ) );
}
function st() { return jget( 'options', array() )['tcgiant_sync_state'] ?? array(); }
function log_has( $text ) { $p = $GLOBALS['WORLD'] . '/data/activity.log'; return is_file( $p ) && false !== strpos( file_get_contents( $p ), $text ); }
function ids( $n ) { $o = array(); for ( $i = 0; $i < $n; $i++ ) { $o[] = (string) ( 174260628275 + $i * 7919 ); } sort( $o, SORT_STRING ); return $o; }

echo "\nONE LISTING KILLS EVERY RUN - THE STUCK SHOP\n" . str_repeat( '=', 126 ) . "\n";

// The second listing, in the importer's own order, dies every time it is
// imported: in the shop's log one listing was saved and then nothing.
$listings = ids( 120 );
$poison   = $listings[1];
$from     = stuck_site( array( 'listings' => 120, 'kill_always' => array( $poison ) ) );
hour();

$s   = st();
$imp = jget( 'imported', array() );

check( 'the window is finished, not restarted', $s['status'] ?? '', 'complete' );
check( '  the sync position moves past the stuck period', ( $s['last_successful_sync_at'] ?? '' ) !== $from, true );
check( '  every other listing in it imported', count( array_diff( $listings, array( $poison ) ) ) === count( array_intersect( array_keys( $imp ), array_diff( $listings, array( $poison ) ) ) ), true );
check( '  the first listing imported once, not once an hour', $imp[ $listings[0] ] ?? 0, 1 );
check( '  the killer tried twice, then stepped over', $imp[ $poison ] ?? 0, 0 );
check( 'the death is in the log, naming the listing', log_has( 'stopped by a PHP fatal error' ) && log_has( 'eBay item ' . $poison ), true );
check( '  first a retry', log_has( 'The last delta run stopped while importing eBay item ' . $poison ), true );
check( '  then an error that names it', log_has( 'stopped 2 times in a row while importing eBay item ' . $poison ), true );
check( 'it is queued for a stock check of its own', in_array( $poison, jget( 'settled', array() ), true ), true );
check( '  and listed for the shop', isset( jget( 'options', array() )['tcgiant_sync_delta_held'][ $poison ] ), true );
check( 'no stale lock left behind by the deaths', is_file( $WORLD . '/data/tcgiant_sync.lock' ), false );
check( 'eBay asked for the window once, not once per batch', stuck_reads(), 1 );
check( 'all of it inside the first hour - the death hands straight on', true, true );

echo "\nDEATHS AT DIFFERENT LISTINGS\n" . str_repeat( '=', 126 ) . "\n";

// Memory that builds up over a batch kills it at a different listing each time.
$listings = ids( 200 );
$kills    = array( $listings[3], $listings[45], $listings[46], $listings[90], $listings[133], $listings[199] );
stuck_site( array( 'listings' => 200, 'kill_once' => $kills ) );
hour();

$s   = st();
$imp = jget( 'imported', array() );

check( 'the window is finished', $s['status'] ?? '', 'complete' );
check( '  every listing imported', count( array_intersect( $listings, array_keys( $imp ) ) ), 200 );
check( '  none more than once', max( $imp ), 1 );
check( '  nothing stepped over - each died only once', jget( 'settled', array() ), array() );
check( '  every death recorded', substr_count( (string) @file_get_contents( $WORLD . '/data/activity.log' ), 'stopped by a PHP fatal error' ), 6 );

echo "\nA HOST THAT ENDS THE REQUEST WITHOUT AN ERROR\n" . str_repeat( '=', 126 ) . "\n";

// What Dobson Parts' host does to a request three minutes in: shutdown runs,
// error_get_last() is empty, and Action Scheduler still has the action down
// as running. 3.27.1 saw that as a run already queued and handed nothing on,
// so each death cost the sync the hour until the next poll.
$listings = ids( 120 );
$poison   = $listings[1];
$from     = stuck_site( array( 'listings' => 120, 'end_always' => array( $poison ) ) );
hour();

$s   = st();
$imp = jget( 'imported', array() );

check( 'the window is finished within the hour', $s['status'] ?? '', 'complete' );
check( '  every other listing imported once', count( array_intersect( array_keys( $imp ), array_diff( $listings, array( $poison ) ) ) ) === 119 && 1 === max( $imp ), true );
check( '  the listing the host keeps ending is tried twice, then stepped over', log_has( 'stopped 2 times in a row while importing eBay item ' . $poison ), true );
check( '  each ending is in the log, naming the listing', substr_count( (string) @file_get_contents( $WORLD . '/data/activity.log' ), 'ended without finishing, by exit() or a host limit while working on eBay item ' . $poison ), 2 );
check( '  it is queued for a stock check of its own', in_array( $poison, jget( 'settled', array() ), true ), true );
check( '  eBay asked for the window once', stuck_reads(), 1 );

echo "\nA HOST WITH A 30 SECOND LIMIT THAT CANNOT BE RAISED\n" . str_repeat( '=', 126 ) . "\n";

// set_time_limit() disabled, 30 seconds, and ten seconds of work per listing.
// A run used to try for forty and be killed on the third.
$listings = ids( 60 );
stuck_site( array( 'listings' => 60, 'fake_clock' => 1000000.0, 'read_seconds' => 2, 'seconds_per_listing' => 10 ) );
hour( '-d disable_functions=set_time_limit -d max_execution_time=30' );

$s   = st();
$imp = jget( 'imported', array() );
$per = array_values( array_filter( jget( 'per_request', array() ) ) );

check( 'the window is finished', $s['status'] ?? '', 'complete' );
check( '  every listing imported once', count( $imp ) === 60 && 1 === max( $imp ), true );
check( '  no request goes past its 15 second budget', max( $per ), 2 );
check( '  every request moves the window on', min( $per ) >= 1, true );
check( '  and says why it stopped early', log_has( 'to stay within this host\'s limits' ), true );
check( 'still one read of eBay for the window', stuck_reads(), 1 );

echo "\nAND A WINDOW LEFT HALF DONE IS NOT STARTED AGAIN BY THE HOURLY RESTART\n" . str_repeat( '=', 126 ) . "\n";

// Stopped by Emergency Stop or a dead queue part way through: the next poll
// restarts the same window and must carry on from its place.
$listings = ids( 100 );
stuck_site( array( 'listings' => 100, 'fake_clock' => 1000000.0, 'read_seconds' => 2, 'seconds_per_listing' => 10 ) );
shell_exec( '"' . PHP_BINARY . "\" -d disable_functions=set_time_limit -d max_execution_time=30 \"$WORLD/poll.php\"" );
for ( $r = 0; $r < 5; $r++ ) {
	shell_exec( '"' . PHP_BINARY . "\" -d disable_functions=set_time_limit -d max_execution_time=30 \"$WORLD/runq.php\"" );
}
jput( 'queue', array() ); // the queue is lost
$before = count( jget( 'imported', array() ) );
an_hour_passes();
hour( '-d disable_functions=set_time_limit -d max_execution_time=30' );
$imp = jget( 'imported', array() );

check( 'some of the window was done before the queue was lost', $before > 0 && $before < 100, true );
check( '  the restart carries on, importing nothing twice', count( $imp ) === 100 && 1 === max( $imp ), true );
check( '  and finishes the window', st()['status'] ?? '', 'complete' );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
