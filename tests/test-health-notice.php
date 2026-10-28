<?php
/**
 * Harness: what the "eBay changes are not reaching your shop" notice says.
 *
 * Dobson Parts updated from 3.26.1 with their sync two weeks behind, and got
 * this, in red:
 *
 *   The sync last finished reading eBay 2 weeks ago. ...
 *   The last run was stopped by the server: Image localiser ended without
 *   finishing ... while working on WC #279336 ...
 *
 * The photo download had nothing to do with the sync being behind. 3.27.0
 * kept one record of the last death for every job, and the notice printed
 * whichever it was. And a sync working through its backlog - which theirs
 * was about to be - read exactly like one that had stopped.
 *
 * Loads the real TCGiant_Sync_Runtime, and the notice's own code out of the
 * admin class, with WordPress and the importer played here.
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

$ROOT = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// --- WordPress ---------------------------------------------------------------
$OPTIONS = array();
$CACHE   = array();
$LOGGED  = array();

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function wp_cache_delete( $key, $group = '' ) { unset( $GLOBALS['CACHE'][ "$group:$key" ] ); return true; }
function wp_cache_get( $key, $group = '' ) { return $GLOBALS['CACHE'][ "$group:$key" ] ?? false; }
function wp_cache_set( $key, $value, $group = '' ) { $GLOBALS['CACHE'][ "$group:$key" ] = $value; return true; }
function add_action() {}

/** wpdb, as far as the crash record uses it: whole-row writes to options. */
class Harness_Wpdb {
	public $options = 'wp_options';
	public $writes  = 0;
	public function replace( $table, $row, $format ) {
		$this->writes++;
		$GLOBALS['OPTIONS'][ $row['option_name'] ] = unserialize( $row['option_value'] );
		return 1;
	}
}
$wpdb = null;

class TCGiant_Sync_Logger {
	public static function write_now( $m, $l = 'error' ) { $GLOBALS['LOGGED'][] = $m; }
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';

/** A death, as on_shutdown() records one. */
function dies( $job, $context = '' ) {
	TCGiant_Sync_Runtime::watch( $job );
	TCGiant_Sync_Runtime::context( $context );
	TCGiant_Sync_Runtime::on_shutdown();
}

$DOBSON = 'Image localiser ended without finishing, by exit() or a host limit while working on WC #279336 (1 of 50 in this batch). 181 s into the request; memory peak 177 MB of 3072M; time limit 300 s.';

echo "\nEACH JOB'S DEATH IS ITS OWN\n" . str_repeat( '=', 122 ) . "\n";

// What 3.27.1 left on Dobson's site: one record, no job in it.
$OPTIONS = array( 'tcgiant_sync_last_crash' => array( 'at' => time() - 600, 'message' => $DOBSON ) );
check( 'a 3.27.1 record of the photo download is not the sync\'s', TCGiant_Sync_Runtime::last_crash( 'Delta sync' ), null );
check( '  it is the photo download\'s', TCGiant_Sync_Runtime::last_crash( 'Image localiser' )['message'] ?? '', $DOBSON );

$OPTIONS = array( 'tcgiant_sync_last_crash' => array( 'at' => time() - 600, 'message' => 'Delta sync stopped by a PHP fatal error: Allowed memory size ... while working on eBay item 1' ) );
check( 'a 3.27.1 record of the sync is still found', 0 === strpos( TCGiant_Sync_Runtime::last_crash( 'Delta sync' )['message'] ?? '', 'Delta sync stopped' ), true );
check( '  and not taken for a full sync\'s', TCGiant_Sync_Runtime::last_crash( 'Full sync scan' ), null );

check( 'where each job\'s is kept', array( TCGiant_Sync_Runtime::crash_option( 'Delta sync' ), TCGiant_Sync_Runtime::crash_option( 'Full sync scan' ), TCGiant_Sync_Runtime::crash_option( 'Image localiser' ) ), array( 'tcgiant_sync_last_crash_delta_sync', 'tcgiant_sync_last_crash_full_sync_scan', 'tcgiant_sync_last_crash_image_localiser' ) );

// The sync dies, then the photo download dies every quarter of an hour.
foreach ( array( 'without wpdb' => null, 'through wpdb' => new Harness_Wpdb() ) as $how => $db ) {
	$wpdb    = $db;
	$OPTIONS = array();
	$CACHE   = array( 'options:notoptions' => array( 'tcgiant_sync_last_crash_delta_sync' => true, 'siteurl' => true ) );
	error_clear_last();
	dies( 'Delta sync', 'eBay item 174260628275 (3 of 1554 in the changes from 2026-09-30T16:47:27.000Z)' );
	dies( 'Image localiser', 'WC #279336 (1 of 50 in this batch)' );
	dies( 'Image localiser', 'WC #279336 (1 of 50 in this batch)' );

	check( "$how: the sync's death survives the photo download's", 0 === strpos( TCGiant_Sync_Runtime::last_crash( 'Delta sync' )['message'] ?? '', 'Delta sync ended without finishing' ), true );
	check( '  the photo download has its own', 0 === strpos( TCGiant_Sync_Runtime::last_crash( 'Image localiser' )['message'] ?? '', 'Image localiser ended without finishing' ), true );
	check( '  the last death of any job is still kept, as 3.27.1 reads it', array( $OPTIONS['tcgiant_sync_last_crash']['job'] ?? '', isset( $OPTIONS['tcgiant_sync_last_crash']['at'], $OPTIONS['tcgiant_sync_last_crash']['message'] ) ), array( 'Image localiser', true ) );
	if ( $db ) {
		check( '  WordPress no longer thinks the record is missing', isset( $CACHE['options:notoptions']['tcgiant_sync_last_crash_delta_sync'] ), false );
		check( '  without forgetting what else is', isset( $CACHE['options:notoptions']['siteurl'] ), true );
	}
}

// A death with no PHP error behind it used to raise a warning of its own,
// reading the type of an error that was not there.
$wpdb     = null;
$warnings = array();
set_error_handler( function ( $no, $str ) use ( &$warnings ) { $warnings[] = $str; return true; } );
error_clear_last();
dies( 'Delta sync' );
restore_error_handler();
check( 'a death with no PHP error raises no warning of its own', $warnings, array() );

echo "\nTHE NOTICE\n" . str_repeat( '=', 122 ) . "\n";

// What the notice asks of the importer, played here.
class TCGiant_Sync_Importer {
	const DELTA_JOB = 'Delta sync';
	public static $state    = array();
	public static $behind   = 0;
	public static $held     = array();
	public static $catch_up = null;
	public static function get_sync_state() { return self::$state; }
	public static function held_back_listings() { return self::$held; }
	public static function delta_behind_seconds() { return self::$behind; }
	public static function delta_behind_threshold() { return DAY_IN_SECONDS; }
	public static function delta_catch_up() { return self::$catch_up; }
	// The real rule, verbatim (3.28.0): a stock check's own reasons.
	public static function is_check_reason( $why ) { return in_array( $why, array( 'sale_check', 'no_calls' ), true ); }
}

function get_current_screen() { return (object) array( 'id' => 'toplevel_page_tcgiant-sync' ); }
function current_user_can() { return true; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_html__( $t ) { return esc_html( $t ); }
function esc_html_e( $t ) { echo esc_html( $t ); }
function esc_url( $u ) { return $u; }
function admin_url( $p = '' ) { return 'https://shop.example/wp-admin/' . $p; }
function _n( $one, $many, $n ) { return 1 === $n ? $one : $many; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function human_time_diff( $from, $to ) { return round( ( $to - $from ) / DAY_IN_SECONDS ) . ' days'; }
function wp_date( $format, $ts ) { return gmdate( 'j M Y H:i', (int) $ts ); }

// The notice's own code, lifted out of the admin class, which needs far more
// of WordPress than this to load.
$admin = file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
$start = strpos( $admin, 'public function sync_health_notice() {' );
$depth = 0;
$end   = $start;
for ( $i = strpos( $admin, '{', $start ); $i < strlen( $admin ); $i++ ) {
	if ( '{' === $admin[ $i ] ) { $depth++; }
	if ( '}' === $admin[ $i ] ) { $depth--; if ( 0 === $depth ) { $end = $i; break; } }
}
eval( 'class Notice_Host { ' . substr( $admin, $start, $end - $start + 1 ) . ' }' );

function notice() {
	ob_start();
	( new Notice_Host() )->sync_health_notice();
	return (string) ob_get_clean();
}
function says( $html, $text ) { return false !== strpos( $html, $text ); }

// Dobson, just after updating: two weeks behind, the 3.26.1 run dead, the
// 3.27 sync not yet started, and only the photo download's death on record.
$OPTIONS = array(
	'date_format'             => 'j M Y',
	'time_format'             => 'H:i',
	'tcgiant_sync_last_crash' => array( 'at' => time() - 600, 'message' => $DOBSON ),
);
TCGiant_Sync_Importer::$state    = array( 'status' => 'scanning', 'sync_mode' => 'delta' );
TCGiant_Sync_Importer::$behind   = 13 * DAY_IN_SECONDS;
TCGiant_Sync_Importer::$catch_up = null;
$html = notice();
check( 'not yet moving: still the red notice', says( $html, 'notice-error' ) && says( $html, 'eBay changes are not reaching your shop' ), true );
check( '  saying how far behind, as a date', says( $html, 'Your shop has eBay&#039;s changes up to' ) && says( $html, '13 days ago' ), true );
check( '  and not blaming the photo download', says( $html, 'Image localiser' ) || says( $html, 'stopped by the server' ), false );

// The first 3.27 runs: working through the window it was stuck on.
TCGiant_Sync_Importer::$catch_up = array( 'done' => 40, 'total' => 1554, 'from' => '2026-09-30T16:47:27.000Z', 'paused_for' => '', 'paused_until' => 0 );
$html = notice();
check( 'working through the backlog: a yellow notice that says so', says( $html, 'notice-warning' ) && says( $html, 'catching up with eBay' ), true );
check( '  not "not reaching your shop"', says( $html, 'not reaching your shop' ), false );
check( '  how far into the window it is on', says( $html, 'Now on the changes from 30 Sep 2026 16:47: 40 of 1,554 listings done.' ), true );
check( '  no pause to report', says( $html, 'Paused' ), false );

TCGiant_Sync_Importer::$catch_up = array( 'done' => 0, 'total' => 0, 'from' => '', 'paused_for' => '', 'paused_until' => 0 );
check( 'between windows: no count to give', says( notice(), 'Now on the changes' ), false );

TCGiant_Sync_Importer::$catch_up = array( 'done' => 10, 'total' => 100, 'from' => '2026-09-30T16:47:27.000Z', 'paused_for' => 'Daily API call budget exhausted. Will resume tomorrow.', 'paused_until' => time() + 900 );
$html = notice();
check( 'paused for eBay: why, and when it tries again', says( $html, 'Paused, because eBay could not be read just now (Daily API call budget exhausted. Will resume tomorrow.). It tries again by itself at' ), true );

// The sync itself died, and is not moving: that is the reason given.
$OPTIONS['tcgiant_sync_last_crash_delta_sync'] = array( 'at' => time() - 300, 'message' => 'Delta sync ended without finishing, by exit() or a host limit while working on eBay item 174260628275', 'job' => 'Delta sync' );
TCGiant_Sync_Importer::$catch_up = null;
$html = notice();
check( 'the sync\'s own death, when it is not moving', says( $html, 'The last run was stopped by the server:' ) && says( $html, 'eBay item 174260628275' ), true );
check( '  still not the photo download\'s', says( $html, 'WC #279336' ), false );

$OPTIONS['tcgiant_sync_last_crash_delta_sync']['at'] = time() - 2 * DAY_IN_SECONDS;
check( '  not once it is a day old', says( notice(), 'stopped by the server' ), false );

// After going back to 3.27.1 for a while: its newer, shared record wins over
// this version's older one of the same job.
$OPTIONS['tcgiant_sync_last_crash'] = array( 'at' => time() - 600, 'message' => 'Delta sync stopped by a PHP fatal error: Allowed memory size of 536870912 bytes exhausted' );
check( 'a newer record from 3.27.1 wins over an older one from now', TCGiant_Sync_Runtime::last_crash( 'Delta sync' )['at'] ?? 0, time() - 600 );
$OPTIONS['tcgiant_sync_last_crash'] = array( 'at' => time() - 600, 'message' => $DOBSON );

// Paused again and again because eBay will not take the shop's token: red,
// with the reason, and what to do about it.
TCGiant_Sync_Importer::$state = array( 'status' => 'scanning', 'sync_mode' => 'delta', 'delta_paused_for' => 'No valid eBay token.', 'delta_paused_code' => 'no_token' );
$html = notice();
check( 'a pause that will not clear: red, saying why', says( $html, 'notice-error' ) && says( $html, 'It keeps pausing because eBay cannot be read (No valid eBay token.).' ), true );
check( '  and to reconnect, with the way there', says( $html, 'reconnect your eBay account on the' ) && says( $html, 'page=tcgiant-settings' ), true );
TCGiant_Sync_Importer::$state['delta_paused_code'] = 'http_request_failed';
check( '  a connection that will not clear: no reconnect advice', says( notice(), 'reconnect' ), false );
TCGiant_Sync_Importer::$state = array( 'status' => 'complete', 'sync_mode' => 'delta', 'delta_paused_for' => 'Daily API call budget exhausted. Will resume tomorrow.', 'delta_paused_code' => 'out_of_calls' );
check( 'red for some other reason: an old wait for calls is not given as it', says( notice(), 'keeps pausing' ), false );
TCGiant_Sync_Importer::$state = array( 'status' => 'importing', 'sync_mode' => 'full', 'delta_paused_for' => 'No valid eBay token.', 'delta_paused_code' => 'no_token' );
check( '  nor the delta\'s pause during a full sync', says( notice(), 'keeps pausing' ), false );
TCGiant_Sync_Importer::$state = array( 'status' => 'scanning', 'sync_mode' => 'delta' );

// A full Fetch Inventory blocks the delta while it runs; its death is the one.
TCGiant_Sync_Importer::$state = array( 'status' => 'importing', 'sync_mode' => 'full' );
$OPTIONS['tcgiant_sync_last_crash_full_sync_scan'] = array( 'at' => time() - 300, 'message' => 'Full sync scan ended without finishing, by exit() or a host limit while working on page 12 of 80', 'job' => 'Full sync scan' );
check( 'during a full sync, its death is the reason', says( notice(), 'page 12 of 80' ), true );

TCGiant_Sync_Importer::$behind = HOUR_IN_SECONDS;
check( 'an hour behind, nothing held back: no notice', notice(), '' );

TCGiant_Sync_Importer::$held = array( '123' => array( 'item_id' => '123' ) );
$html = notice();
check( 'listings stepped over still show, without the lateness', says( $html, 'listings to check by hand' ) && ! says( $html, 'reaching your shop' ) && ! says( $html, 'catching up' ), true );
check( '  one the sync moved past: queued for a stock check of its own', says( $html, '1 eBay listing could not be imported, so the sync moved past it. It was queued for a stock check of its own' ), true );
check( '  and nothing said of a check that gave up', says( $html, 'could not reach eBay' ), false );

// Stock checks after an eBay sale that gave up (3.28.0): never said to be queued.
TCGiant_Sync_Importer::$held = array(
	'124' => array( 'item_id' => '124', 'why' => 'sale_check' ),
	'125' => array( 'item_id' => '125', 'why' => 'no_calls' ),
);
$html = notice();
check( 'two sale checks that gave up: their stock unchanged, check them on Stock Review', says( $html, '2 stock checks after an eBay sale could not reach eBay, so their stock here is unchanged; check them on Stock Review.' ), true );
check( '  not that they stopped the sync, nor that a check is queued', says( $html, 'stopped the sync' ) || says( $html, 'queued' ), false );
TCGiant_Sync_Importer::$held = array(
	'126' => array( 'item_id' => '126', 'why' => 'killed' ),
	'127' => array( 'item_id' => '127', 'why' => 'unread', 'check' => 'sale_check' ),
	'128' => array( 'item_id' => '128', 'why' => 'sale_check' ),
);
$html = notice();
// 127: the sync moved past it, and then its own check gave up too - counted
// with the checks that gave up, never as queued.
check( 'both kinds: each said once, with its own count', array( says( $html, '1 eBay listing could not be imported, so the sync moved past it. It was queued' ), says( $html, '2 stock checks after an eBay sale could not reach eBay, so their stock here is unchanged; check them on Stock Review.' ) ), array( true, true ) );
TCGiant_Sync_Importer::$held = array(
	'129' => array( 'item_id' => '129', 'why' => 'killed', 'check' => 'no_calls' ),
);
$html = notice();
check( 'one the sync moved past whose own check then gave up: not said to be queued', array( says( $html, 'queued' ), says( $html, '1 stock check after an eBay sale could not reach eBay, so its stock here is unchanged; check it on Stock Review.' ) ), array( false, true ) );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
