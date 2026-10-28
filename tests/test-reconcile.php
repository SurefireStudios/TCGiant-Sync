<?php
/**
 * Harness: the weekly stock check reads every page (3.28.0, item R).
 *
 * Until 3.28.0 every run of the weekly stock check began at page 1 and
 * stopped after 25 pages of 200 listings. A shop with about 9,000
 * listings - 47 pages - and every week the log said "Stopped at the 25-page
 * cap (47 pages available). Remaining listings were not checked." Pages 26 to
 * 47 were never checked.
 *
 * Now a pass reads every page, a slice at a time: at most 25 pages, fewer when
 * the request's time or memory runs short, then a follow-up (Action Scheduler)
 * carries it on from its place, which is kept with the totals so far. One pass
 * at a time; a pass gone quiet is carried on by a two-hour stall check
 * (WP-Cron), and a page that has ended its request three times running is
 * stepped over; one older than three days starts again. The day's call budget
 * refusing a page waits for 00:05:30 UTC with the place kept; a read that fails
 * for a reason that passes is tried again 15 minutes later, five tries at most.
 *
 * Loaded for real: TCGiant_Sync_Inventory (the pass), TCGiant_Sync_API down to
 * the HTTP call - its daily budget and its reading of eBay's errors - and
 * TCGiant_Sync_Runtime's time and memory budget, on support/settings-world.php's
 * WordPress (its own hooks; options as WordPress keeps them; a row written
 * straight to the table only where it still holds what was read). eBay's
 * GetSellerList is answered page by page from a played shop. Action Scheduler
 * and WP-Cron are played: an action runs through the hook the plugin
 * registered, with its arguments, each in a request of its own. A slice the
 * host kills outright is a process of its own (this file, --child=kill) that
 * exits part way; a staging copy runs in a child too, and uninstall.php in
 * support/uninstall-run.php.
 *
 * Time: the clock the time budget reads is played (TCGiant_Sync_Runtime::$clock);
 * hours and days passing are played by moving every stored time back (later()).
 *
 * @package TCGiant_Sync
 */

$CHILD = '';
$STATE = '';
$KILL  = array( 'page' => 30, 'run' => 'queue' );
foreach ( array_slice( (array) ( $argv ?? array() ), 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--child=' ) ) {
		$CHILD = substr( $arg, 8 );
	} elseif ( 0 === strpos( $arg, '--state=' ) ) {
		$STATE = substr( $arg, 8 );
	} elseif ( 0 === strpos( $arg, '--page=' ) ) {
		$KILL['page'] = (int) substr( $arg, 7 );
	} elseif ( 0 === strpos( $arg, '--run=' ) ) {
		$KILL['run'] = substr( $arg, 6 );
	}
}

if ( 'staging' === $CHILD ) {
	define( 'TCGIANT_SYNC_IS_STAGING', true );
}

// ─── the world ──────────────────────────────────────────────────────────────

require __DIR__ . '/support/settings-world.php';

$ROOT = dirname( __DIR__ );
require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-environment.php';
// eBay's 518 is waited on with item D's helpers (3.28.0 release fix round 2).
require $ROOT . '/includes/class-tcgiant-sync-importer.php';

$W = &$GLOBALS['W'];

const TOKENS = array(
	'access_token'  => 'v^1.1#i^1#AT-LARGE-SHOP',
	'refresh_token' => 'v^1.1#i^1#RT-LARGE-SHOP',
	'token_expiry'  => '2200000000',
	'marketplace'   => 'EBAY_US',
);
define( 'OPT', TCGiant_Sync_Inventory::RECONCILE_OPTION );
define( 'FOLLOW', TCGiant_Sync_Inventory::RECONCILE_HOOK );
define( 'STALL', TCGiant_Sync_Inventory::RECONCILE_STALL_HOOK );
define( 'OLD_RECORD', '2026-10-20 03:00:00' );

function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? ''; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function wp_remote_retrieve_header( $r, $h ) { return $r['headers'][ strtolower( $h ) ] ?? ''; }
function wp_rand( $min = 0, $max = 0 ) { return $min; }

// A host's time limit of 30 s, which each slice raises (to 300 s, for a
// budget of a minute): without that, a slice would hand on after 15 s.
ini_set( 'max_execution_time', '30' );

// The clock the time budget reads, and each request's start.
$GLOBALS['CLOCK']  = 1000.0;
$GLOBALS['MEMORY'] = 8388608;
ini_set( 'memory_limit', '256M' );
TCGiant_Sync_Runtime::$clock        = function () { return $GLOBALS['CLOCK']; };
TCGiant_Sync_Runtime::$memory_probe = function () { return $GLOBALS['MEMORY']; };
function new_request() {
	$_SERVER['REQUEST_TIME_FLOAT'] = $GLOBALS['CLOCK'];
}

/** The database: option rows (settings-world), the product an eBay listing is followed by, and the day's call count. */
class Reconcile_Wpdb extends Settings_World_DB {
	public $last_error = '';
	public function get_var( $query ) {
		if ( preg_match( "/pm\\.meta_key = '_ebay_item_id' AND pm\\.meta_value = '([^']*)'/", $query, $m ) ) {
			$id = $GLOBALS['W']['by_item'][ $m[1] ] ?? 0;
			return ( $id && 'trash' !== ( $GLOBALS['W']['posts'][ $id ]['status'] ?? '' ) ) ? (string) $id : null;
		}
		if ( false !== strpos( $query, "listing.meta_value = 'Ended'" ) ) {
			return (string) $GLOBALS['W']['unsettled'];
		}
		return parent::get_var( $query );
	}
	public function query( $query ) {
		// A statement the database refuses: false, and last_error set.
		if ( '' !== $GLOBALS['W']['refuse_sql'] && false !== strpos( $query, $GLOBALS['W']['refuse_sql'] ) ) {
			$this->last_error = 'The table is full';
			return false;
		}
		$this->last_error = '';
		// The day's eBay call count, one INSERT ... ON DUPLICATE KEY UPDATE a call.
		if ( false !== strpos( $query, 'ON DUPLICATE KEY UPDATE option_value = option_value + 1' ) && preg_match( "/VALUES \\( '([^']+)'/", $query, $m ) ) {
			$GLOBALS['W']['options'][ $m[1] ] = (int) ( $GLOBALS['W']['options'][ $m[1] ] ?? 0 ) + 1;
			return 1;
		}
		return parent::query( $query );
	}
	public function suppress_errors( $quiet = true ) { return false; }
}
$GLOBALS['wpdb'] = new Reconcile_Wpdb();

/** A product, as far as the check asks: whether it manages stock, and how much. */
class Harness_Product {
	private $id;
	public function __construct( $id ) { $this->id = (int) $id; }
	public function managing_stock() { return 'yes' === ( $GLOBALS['W']['meta'][ $this->id ]['_manage_stock'] ?? 'no' ); }
	public function get_stock_quantity() {
		if ( isset( $GLOBALS['W']['throw_on'][ $this->id ] ) ) {
			throw new RuntimeException( $GLOBALS['W']['throw_on'][ $this->id ] );
		}
		return (int) ( $GLOBALS['W']['meta'][ $this->id ]['_stock'] ?? 0 );
	}
}
function wc_get_product( $id ) { return isset( $GLOBALS['W']['posts'][ (int) $id ] ) ? new Harness_Product( $id ) : false; }

// ─── Action Scheduler and WP-Cron, played ───────────────────────────────────

function as_schedule_single_action( $when, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	$GLOBALS['W']['as_id']++;
	$GLOBALS['W']['queue'][ $GLOBALS['W']['as_id'] ] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'when' => (int) $when, 'status' => 'pending' );
	return $GLOBALS['W']['as_id'];
}
function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	return as_schedule_single_action( time(), $hook, $args, $group );
}
function as_match( $a, $hook, $args, $group ) {
	return $a['hook'] === $hook && ( null === $args || $a['args'] === $args ) && ( '' === (string) $group || $a['group'] === $group );
}
/** As Action Scheduler's: when the first waiting is due; true for one running; false for none. */
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['W']['queue'] as $a ) {
		if ( 'pending' === $a['status'] && as_match( $a, $hook, $args, $group ) ) {
			return $a['when'];
		}
	}
	foreach ( $GLOBALS['W']['queue'] as $a ) {
		if ( 'running' === $a['status'] && as_match( $a, $hook, $args, $group ) ) {
			return true;
		}
	}
	return false;
}
/** Only those waiting are cancelled, as Action Scheduler's. */
function as_unschedule_all_actions( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['W']['queue'] as $id => $a ) {
		if ( 'pending' === $a['status'] && as_match( $a, $hook, $args, $group ) ) {
			$GLOBALS['W']['queue'][ $id ]['status'] = 'canceled';
		}
	}
}
/** A queue run: each action due by now, in order, through its hook - each in a request of its own. */
function run_queue( $max = 50 ) {
	$ran = 0;
	while ( $ran < $max ) {
		$next = null;
		foreach ( $GLOBALS['W']['queue'] as $id => $a ) {
			if ( 'pending' === $a['status'] && $a['when'] <= time() && ( null === $next || $a['when'] < $GLOBALS['W']['queue'][ $next ]['when'] ) ) {
				$next = $id;
			}
		}
		if ( null === $next ) {
			break;
		}
		$GLOBALS['W']['queue'][ $next ]['status'] = 'running';
		new_request();
		do_action_ref_array( $GLOBALS['W']['queue'][ $next ]['hook'], array_values( $GLOBALS['W']['queue'][ $next ]['args'] ) );
		$GLOBALS['W']['queue'][ $next ]['status'] = 'complete';
		$ran++;
	}
	return $ran;
}
function pending( $hook = FOLLOW ) {
	return array_values( array_filter( $GLOBALS['W']['queue'], function ( $a ) use ( $hook ) { return 'pending' === $a['status'] && $a['hook'] === $hook; } ) );
}
function wp_schedule_single_event( $when, $hook, $args = array() ) { $GLOBALS['W']['cron'][ $hook ] = (int) $when; return true; }
function wp_schedule_event( $when, $recurrence, $hook, $args = array() ) { $GLOBALS['W']['cron'][ $hook ] = (int) $when; return true; }
function wp_clear_scheduled_hook( $hook, $args = array() ) { unset( $GLOBALS['W']['cron'][ $hook ] ); return 0; }
function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['W']['cron'][ $hook ] ?? false; }
/** WP-Cron running one event: it is taken off, then its hook runs, in a request of its own. */
function run_cron( $hook ) {
	unset( $GLOBALS['W']['cron'][ $hook ] );
	new_request();
	do_action( $hook );
}
function weekly() { run_cron( 'tcgiant_sync_reconcile_inventory' ); }
function stall_check() { run_cron( STALL ); }

// ─── eBay, at the HTTP call ─────────────────────────────────────────────────

/**
 * The shop: $n listings in eBay's order, each followed by a product whose
 * stock is what eBay has available, except: those in $differ (listing number
 * => WC stock); every 50th, which no product follows; every 70th, whose
 * product does not manage stock; and those in $ended, which ended after the
 * pass began. How many a pass checks, in all and on pages up to $upto.
 */
function shop( $n, array $differ = array(), array $ended = array(), $upto = 0 ) {
	$w = &$GLOBALS['W'];
	$w['shop']    = array();
	$w['posts']   = array();
	$w['meta']    = array();
	$w['by_item'] = array();
	$all          = 0;
	$first        = 0;
	for ( $i = 1; $i <= $n; $i++ ) {
		$item   = (string) ( 110000000000 + $i );
		$listed = 1 + $i % 5;
		$sold   = $i % 2;
		$w['shop'][] = array( 'ItemID' => $item, 'Title' => 'Tape ' . $i, 'Quantity' => $listed, 'QuantitySold' => $sold, 'ListingStatus' => in_array( $i, $ended, true ) ? 'Completed' : 'Active' );
		if ( 0 === $i % 50 ) {
			continue;
		}
		$id = 100000 + $i;
		$w['posts'][ $id ] = array( 'status' => 'publish' );
		$w['meta'][ $id ]  = array( '_ebay_item_id' => $item, '_manage_stock' => 0 === $i % 70 ? 'no' : 'yes', '_stock' => $differ[ $i ] ?? max( 0, $listed - $sold ) );
		$w['by_item'][ $item ] = $id;
		if ( 0 !== $i % 70 && ! in_array( $i, $ended, true ) ) {
			$all++;
			if ( $i <= $upto * 200 ) {
				$first++;
			}
		}
	}
	return array( $all, $first );
}

function ebay_xml( $call, $inner ) {
	return '<?xml version="1.0" encoding="UTF-8"?><' . $call . 'Response xmlns="urn:ebay:apis:eBLBaseComponents">' . $inner . '</' . $call . 'Response>';
}
function ebay_failure( $code, $message ) {
	$message = htmlspecialchars( $message, ENT_XML1 );
	return '<Ack>Failure</Ack><Errors><ShortMessage>' . $message . '</ShortMessage><LongMessage>' . $message . '</LongMessage><ErrorCode>' . $code . '</ErrorCode><SeverityCode>Error</SeverityCode></Errors>';
}
/** One page of GetSellerList, 200 listings, in eBay's envelope; past the last, none. */
function seller_list_page( $page ) {
	$shop  = $GLOBALS['W']['shop'];
	$total = (int) ceil( count( $shop ) / 200 );
	$inner = '<Ack>Success</Ack><PaginationResult><TotalNumberOfPages>' . $total . '</TotalNumberOfPages><TotalNumberOfEntries>' . count( $shop ) . '</TotalNumberOfEntries></PaginationResult>';
	$items = array_slice( $shop, ( $page - 1 ) * 200, 200 );
	if ( $items ) {
		$inner .= '<ItemArray>';
		foreach ( $items as $l ) {
			$varies = '';
			foreach ( $l['Variations'] ?? array() as $sku => $qty ) {
				$varies .= '<Variation><SKU>' . $sku . '</SKU><Quantity>' . $qty . '</Quantity><SellingStatus><QuantitySold>0</QuantitySold></SellingStatus></Variation>';
			}
			$inner .= '<Item><ItemID>' . $l['ItemID'] . '</ItemID><Title>' . $l['Title'] . '</Title><Quantity>' . $l['Quantity'] . '</Quantity><SellingStatus><QuantitySold>' . $l['QuantitySold'] . '</QuantitySold>' . ( '' !== $l['ListingStatus'] ? '<ListingStatus>' . $l['ListingStatus'] . '</ListingStatus>' : '' ) . '</SellingStatus>' . ( '' !== $varies ? '<Variations>' . $varies . '</Variations>' : '' ) . '</Item>';
		}
		$inner .= '</ItemArray>';
	}
	return ebay_xml( 'GetSellerList', $inner );
}
/**
 * Every request is recorded and answered. GetSellerList: the page asked for,
 * after $W['on_page'][page] runs (once) while the call is out; unless
 * $W['answers'][page] has something scripted for it, taken in turn: an eBay
 * refusal array( code, message ), array( 'http', code ), or 'kill' - the
 * request ends here, as a host ends one: nothing more of it runs.
 */
function wp_remote_request( $url, $args ) {
	$call = (string) ( $args['headers']['X-EBAY-API-CALL-NAME'] ?? '' );
	$body = (string) ( $args['body'] ?? '' );
	$GLOBALS['W']['wire'][] = array( $call, $body );

	if ( 'GeteBayOfficialTime' === $call && ! empty( $GLOBALS['W']['probe_518'] ) ) {
		$GLOBALS['W']['probe_518']--;
		return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => ebay_xml( $call, ebay_failure( '518', 'Calls to this call have exceeded the call limit.' ) ) );
	}
	if ( 'GetSellerList' !== $call ) {
		return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => ebay_xml( $call, '<Ack>Success</Ack>' ) );
	}

	preg_match( '#<PageNumber>(\d+)</PageNumber>#', $body, $m );
	$page                  = (int) $m[1];
	$GLOBALS['CLOCK']     += $GLOBALS['W']['secs_per_page'];

	if ( isset( $GLOBALS['W']['on_page'][ $page ] ) ) {
		$then = $GLOBALS['W']['on_page'][ $page ];
		unset( $GLOBALS['W']['on_page'][ $page ] );
		$then();
	}

	$answer = ! empty( $GLOBALS['W']['answers'][ $page ] ) ? array_shift( $GLOBALS['W']['answers'][ $page ] ) : 'ok';

	if ( 'kill' === $answer ) {
		save_world( $GLOBALS['STATE'] );
		$GLOBALS['harness_done'] = true;
		exit( 0 );
	}
	if ( is_array( $answer ) && 'http' === $answer[0] ) {
		return array( 'headers' => array(), 'response' => array( 'code' => (int) $answer[1] ), 'body' => '' );
	}
	if ( is_array( $answer ) ) {
		return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => ebay_xml( $call, ebay_failure( $answer[0], $answer[1] ) ) );
	}

	return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => seller_list_page( $page ) );
}

// ─── the world's state, for a child process ─────────────────────────────────

define( 'KEPT', 'options,autoload,transients,meta,posts,by_item,shop,log,queue,as_id,cron,wire,unsettled' );
function save_world( $file ) {
	$keep = array();
	foreach ( explode( ',', KEPT ) as $k ) {
		$keep[ $k ] = $GLOBALS['W'][ $k ];
	}
	$keep['CLOCK'] = $GLOBALS['CLOCK'];
	file_put_contents( $file, serialize( $keep ) );
}
function load_world( $file ) {
	$keep = unserialize( (string) file_get_contents( $file ) );
	foreach ( explode( ',', KEPT ) as $k ) {
		$GLOBALS['W'][ $k ] = $keep[ $k ];
	}
	$GLOBALS['CLOCK'] = $keep['CLOCK'];
}
/** uninstall.php, in a process and a world of its own (support/uninstall-run.php). */
function uninstall_run( $data ) {
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/uninstall-run.php' ) . ' ' . $data . ' 2>&1', $out, $code );
	return array( $code, implode( "\n", $out ) );
}
function child( $mode, $state = '', $more = '' ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child=' . $mode . ( '' !== $state ? ' --state=' . escapeshellarg( $state ) : '' ) . $more . ' 2>&1';
	exec( $cmd, $out, $code );
	return array( $code, implode( "\n", $out ) );
}
/** A run in a process of its own - the queue, the stall check or the weekly run - killed outright reading $page; the world as it left it. */
function kill_on( $page, $run ) {
	$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tcg-reconcile-kill-' . getmypid() . '.ser';
	save_world( $file );
	$got = child( 'kill', $file, ' --page=' . (int) $page . ' --run=' . $run );
	load_world( $file );
	@unlink( $file );
	return $got;
}
/** How many listings from $from to $to a pass checks: followed, managing stock. */
function checkable( $from, $to ) {
	$n = 0;
	for ( $i = $from; $i <= $to; $i++ ) {
		if ( 0 !== $i % 50 && 0 !== $i % 70 ) {
			$n++;
		}
	}
	return $n;
}

// ─── each case begins with ──────────────────────────────────────────────────

function fresh( array $settings = array() ) {
	$w = &$GLOBALS['W'];
	foreach ( array( 'options', 'autoload', 'transients', 'log', 'queue', 'cron', 'wire', 'answers', 'on_page', 'stale', 'row_reads', 'throw_on', 'probe_518' ) as $k ) {
		$w[ $k ] = array();
	}
	$w['cache']         = null;
	$w['as_id']         = 0;
	$w['secs_per_page'] = 1;
	$w['unsettled']     = 0;
	$w['refuse_sql']    = '';
	$GLOBALS['CLOCK']   = 1000.0;
	$GLOBALS['MEMORY']  = 8388608;
	TCGiant_Sync_Runtime::resume_queue_runs();
	update_option( 'tcgiant_sync_ebay_settings', array_merge( TOKENS, array( 'last_reconciliation' => OLD_RECORD, 'last_reconciliation_mismatches' => 7 ), $settings ) );
}
function place() { $p = $GLOBALS['W']['options'][ OPT ] ?? null; return is_array( $p ) ? $p : null; }
function settings() { return get_option( 'tcgiant_sync_ebay_settings', array() ); }
function lines( $needle = 'Reconciliation' ) {
	$out = array();
	foreach ( $GLOBALS['W']['log'] as $entry ) {
		if ( false !== strpos( $entry[1], $needle ) ) {
			$out[] = $entry[1];
		}
	}
	return $out;
}
function levels( $needle ) {
	$out = array();
	foreach ( $GLOBALS['W']['log'] as $entry ) {
		if ( false !== strpos( $entry[1], $needle ) ) {
			$out[] = $entry[0];
		}
	}
	return $out;
}
function mark() { $GLOBALS['W']['log'] = array(); $GLOBALS['W']['wire'] = array(); }
/** The pages GetSellerList was asked for, in order. */
function pages_read() {
	$out = array();
	foreach ( $GLOBALS['W']['wire'] as $call ) {
		if ( 'GetSellerList' === $call[0] && preg_match( '#<PageNumber>(\d+)</PageNumber>#', $call[1], $m ) ) {
			$out[] = (int) $m[1];
		}
	}
	return $out;
}
function end_times() {
	$out = array();
	foreach ( $GLOBALS['W']['wire'] as $call ) {
		if ( preg_match( '#<EndTimeFrom>([^<]+)</EndTimeFrom>#', $call[1], $m ) ) {
			$out[] = $m[1];
		}
	}
	return array_values( array_unique( $out ) );
}
function stocks() {
	$out = array();
	foreach ( $GLOBALS['W']['meta'] as $id => $meta ) {
		$out[ $id ] = $meta['_stock'];
	}
	return $out;
}
/** Hours or days pass: every stored time moves back by $seconds. */
function later( $seconds ) {
	$w = &$GLOBALS['W'];
	if ( isset( $w['options'][ OPT ] ) && is_array( $w['options'][ OPT ] ) ) {
		foreach ( array( 'started_at', 'beat', 'wait_until' ) as $k ) {
			if ( ! empty( $w['options'][ OPT ][ $k ] ) ) {
				$w['options'][ OPT ][ $k ] -= $seconds;
			}
		}
	}
	if ( isset( $w['transients'][ TCGiant_Sync_Importer::EBAY_518_TRANSIENT ]['until'] ) ) {
		$w['transients'][ TCGiant_Sync_Importer::EBAY_518_TRANSIENT ]['until'] -= $seconds;
	}
	foreach ( $w['queue'] as $id => $a ) {
		$w['queue'][ $id ]['when'] -= $seconds;
		if ( FOLLOW === $a['hook'] && isset( $a['args']['pass'] ) ) {
			$w['queue'][ $id ]['args']['pass'] -= $seconds;
		}
	}
	foreach ( $w['cron'] as $hook => $at ) {
		$w['cron'][ $hook ] = $at - $seconds;
	}
}
function take_over() {
	$p          = place();
	$p['owner'] = 'another-run';
	$GLOBALS['W']['options'][ OPT ] = $p;
}
function next_reset() {
	$today = gmmktime( 0, 0, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ), (int) gmdate( 'Y' ) ) + 330;
	return $today > time() ? $today : $today + 86400;
}
function near( $got, $want, $by = 5 ) { return is_int( $got ) && abs( $got - $want ) <= $by; }
/** The calls sent since mark(), by name. */
function calls_sent() { return array_column( $GLOBALS['W']['wire'], 0 ); }
/** When the 518 is next asked about, as every check, push and pass share it. */
function slot_518() { return (int) ( $GLOBALS['W']['transients'][ TCGiant_Sync_Importer::EBAY_518_TRANSIENT ]['until'] ?? 0 ); }
function line_518( $done, $page, $when ) { return sprintf( 'Reconciliation: %seBay\'s call limit is reached (Calls to this call have exceeded the call limit.), so %s is read once it lifts - asked again at %s UTC.', $done, $page, gmdate( 'H:i', $when ) ); }
function calls_today() { return (int) ( $GLOBALS['W']['options'][ 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ) ] ?? 0 ); }

$INV = new TCGiant_Sync_Inventory();

// ─── children ───────────────────────────────────────────────────────────────

if ( 'kill' === $CHILD ) {
	// Run the queue, the stall check or the weekly run, and die reading the
	// page (30 unless --page= says).
	fresh();
	load_world( $STATE );
	$W['answers'] = array( $KILL['page'] => array( 'kill' ) );
	if ( 'stall' === $KILL['run'] ) {
		stall_check();
	} elseif ( 'weekly' === $KILL['run'] ) {
		weekly();
	} else {
		run_queue();
	}
	echo "the child was not killed\n";
	$GLOBALS['harness_done'] = true;
	exit( 1 );
}
if ( 'staging' === $CHILD ) {
	fresh();
	shop( 450, array( 7 => 99 ) );
	weekly();
	run_queue();
	$blocked = TCGiant_Sync_Environment::write_block();
	echo json_encode( array(
		'copy'     => is_wp_error( $blocked ) ? $blocked->get_error_code() : '',
		'calls'    => array_values( array_unique( array_map( function ( $c ) { return $c[0]; }, $W['wire'] ) ) ),
		'pages'    => pages_read(),
		'complete' => lines( 'Reconciliation: Complete.' ),
		'stock'    => $W['meta'][100007]['_stock'],
	) );
	$GLOBALS['harness_done'] = true;
	exit( 0 );
}

$LARGE_SHOP_PAGES = 47;
$DIFFER        = array( 251 => 0, 4851 => 9, 9301 => 4 );
list( $ALL, $FIRST ) = shop( 9321, $DIFFER, array(), 25 );

echo "\nA LARGE SHOP: 9,321 LISTINGS, 47 PAGES, EVERY ONE CHECKED IN TWO SLICES\n" . str_repeat( '=', 124 ) . "\n";

fresh();
$tcg_before = stocks();
weekly();
$tcg_place = place();
check( 'the weekly run says it starts, as before', lines( 'Starting' ), array( 'Reconciliation: Starting weekly inventory check...' ) );
check( '  it reads pages 1 to 25, and no more', pages_read(), range( 1, 25 ) );
check( '  and says so in one line', lines( 'pages' ), array( 'Reconciliation: pages 1-25 of 47 checked, continuing.' ) );
check( '  no "page cap" warning', lines( 'cap' ), array() );
check( '  its place kept: page 26 of 47, the totals so far, no slice at it', array( $tcg_place['page'], $tcg_place['total_pages'], $tcg_place['checked'], $tcg_place['mismatches'], $tcg_place['owner'], $tcg_place['wait_until'] ), array( 26, 47, $FIRST, 2, '', 0 ) );
check( '  and when the pass began', near( $tcg_place['started_at'], time() ), true );
check( '  the place is never autoloaded', $W['autoload'][ OPT ], 'no' );
check( '  the rest handed to a follow-up for this pass, due now', array( count( pending() ), pending()[0]['args'] ?? null, pending()[0]['group'] ?? null, near( pending()[0]['when'] ?? 0, time() ) ), array( 1, array( 'pass' => $tcg_place['started_at'] ), TCGiant_Sync_Inventory::RECONCILE_GROUP, true ) );
check( '  and the stall check armed two hours on', near( wp_next_scheduled( STALL ), time() + 7200 ), true );
check( '  nothing recorded yet', array( settings()['last_reconciliation'], settings()['last_reconciliation_mismatches'] ), array( OLD_RECORD, 7 ) );
check( '  two of the mismatches found so far logged, as before', lines( 'mismatch:' ), array(
	'Reconciliation mismatch: "Tape 251" (eBay #110000000251, WC #100251) — WC stock: 0, eBay available: 1',
	'Reconciliation mismatch: "Tape 4851" (eBay #110000004851, WC #104851) — WC stock: 9, eBay available: 1',
) );

$tcg_end1 = end_times();
check( '  the queue runner left to carry on: a slice that stops at 25 pages is not short of time or memory', has_filter( 'action_scheduler_memory_exceeded', '__return_true' ), false );
mark();
later( 3600 );   // The queue reaches it an hour on.
check( 'the follow-up runs, an hour on', run_queue(), 1 );
check( '  and reads pages 26 to 47', pages_read(), range( 26, 47 ) );
check( '  the end said in one line, with the totals across both slices', lines( 'Complete' ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 47 pages, found 3 mismatch(es).', $ALL ) ) );
check( '    at warning, for the mismatches', levels( 'Complete' ), array( 'warning' ) );
check( '  no slice line for the last slice, no start line', array( lines( 'continuing' ), lines( 'Starting' ) ), array( array(), array() ) );
check( '  the third mismatch logged', lines( 'mismatch:' ), array( 'Reconciliation mismatch: "Tape 9301" (eBay #110000009301, WC #109301) — WC stock: 4, eBay available: 1' ) );
check( '  recorded at the end: the time and the mismatches', array( settings()['last_reconciliation'] !== OLD_RECORD, settings()['last_reconciliation_mismatches'] ), array( true, 3 ) );
check( '  the tokens untouched', array( settings()['access_token'], settings()['refresh_token'] ), array( TOKENS['access_token'], TOKENS['refresh_token'] ) );
check( '  the place gone, no follow-up left, the stall check cleared', array( place(), pending(), wp_next_scheduled( STALL ) ), array( null, array(), false ) );
check( 'one call a page: 47 for the pass', calls_today(), 47 );
check( 'every page of both slices asked for the listings ending from when the pass began', array( $tcg_end1, end_times() ), array( array( gmdate( 'Y-m-d\TH:i:s.000\Z', $tcg_place['started_at'] ) ), array( gmdate( 'Y-m-d\TH:i:s.000\Z', $tcg_place['started_at'] - 3600 ) ) ) );
check( 'report only: no stock changed, nothing but GetSellerList sent', array( stocks() === $tcg_before, array_values( array_unique( array_map( function ( $c ) { return $c[0]; }, $W['wire'] ) ) ) ), array( true, array( 'GetSellerList' ) ) );

// The hooks, as registered: the follow-up gets the pass it was queued for.
check( 'the follow-up and the stall check are hooked, the follow-up with its argument', array(
	has_action( FOLLOW, array( $INV, 'continue_reconciliation' ) ),
	has_action( STALL, array( $INV, 'reconcile_stall_check' ) ),
), array( 10, 10 ) );

// A shop of fewer than 25 pages: one slice, as before.
fresh();
list( $tcg_all ) = shop( 450, array( 7 => 99 ) );
weekly();
check( 'a shop of three pages: one run, the end said, nothing handed on', array( pages_read(), lines( 'Complete' ), pending(), place(), wp_next_scheduled( STALL ) ), array( array( 1, 2, 3 ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 3 pages, found 1 mismatch(es).', $tcg_all ) ), array(), null, false ) );
fresh();
shop( 0 );
weekly();
check( 'a shop with no listings: done after one read', array( pages_read(), lines( 'Complete' ), settings()['last_reconciliation_mismatches'] ), array( array( 1 ), array( 'Reconciliation: Complete. Checked 0 items across 0 pages, found 0 mismatch(es).' ), 0 ) );
check( '  at success', levels( 'Complete' ), array( 'success' ) );
fresh();
list( $tcg_all ) = shop( 200 );
weekly();
check( 'one page exactly', lines( 'Complete' ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 1 page, found 0 mismatch(es).', $tcg_all ) ) );
// A listing with variations: GetSellerList's Quantity is the total of its
// variations, which no one product's stock is - a pool's shared stock shows
// on each of them. Never compared; counted in the end line (3.28.0 release
// fix round 2).
fresh();
list( $tcg_all ) = shop( 200 );
$W['shop'][6]['Quantity']   = 9;
$W['shop'][6]['Variations'] = array( 'PL-A' => 3, 'PL-B' => 3, 'PL-C' => 3 );
$W['shop'][7]['Quantity']   = 4;
$W['shop'][7]['Variations'] = array( 'SOLO' => 4 );
weekly();
check( 'listings with variations: not compared with the parent, and said so in the end line', array( lines( 'Complete' ), lines( 'mismatch:' ) ), array( array( sprintf( 'Reconciliation: Complete. Checked %d items across 1 page, found 0 mismatch(es). 2 listings with variations were skipped. This check compares single listings only.', $tcg_all - 2 ) ), array() ) );
fresh();
list( $tcg_all ) = shop( 200 );
$W['shop'][6]['Variations']    = array( 'PL-A' => 3 );
$W['shop'][6]['ListingStatus'] = 'Completed';
$W['shop'][7]['Variations']    = array( 'SOLO' => 4 );
weekly();
check( '  one of them ended since the pass began: left out as any ended listing is', lines( 'Complete' ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 1 page, found 0 mismatch(es). 1 listing with variations was skipped. This check compares single listings only.', $tcg_all - 2 ) ) );
fresh();
list( $tcg_all ) = shop( 201 );
weekly();
check( 'a last page of one listing: it is checked too', lines( 'Complete' ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 2 pages, found 0 mismatch(es).', $tcg_all ) ) );
// The ended listings still waiting to be settled are counted at the end, as before.
fresh();
shop( 450 );
$W['unsettled'] = 4;
weekly();
check( 'at the end, the ended listings still showing stock are counted, as before', lines( 'still showing stock' ), array( 'Reconciliation: 4 product(s) whose eBay listing has ended are still showing stock. Review them under TCGiant Sync, Stock Review.' ) );

echo "\nA LISTING THAT ENDS WHILE THE PASS RUNS IS PASSED OVER\n" . str_repeat( '=', 124 ) . "\n";

fresh();
list( $tcg_all ) = shop( 450, array( 3 => 50, 301 => 50 ), array( 301 ) );
weekly();
check( 'one ended since the pass began, its stock off: not checked, not a mismatch', lines( 'Complete' ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 3 pages, found 1 mismatch(es).', $tcg_all ) ) );
check( '  the one live mismatch logged, the ended listing\'s not', lines( 'mismatch:' ), array( 'Reconciliation mismatch: "Tape 3" (eBay #110000000003, WC #100003) — WC stock: 50, eBay available: 3' ) );
fresh();
list( $tcg_all ) = shop( 450, array( 3 => 50 ) );
$W['shop'][2]['ListingStatus'] = '';
weekly();
check( 'one eBay sends no ListingStatus for is checked, as before', lines( 'Complete' ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 3 pages, found 1 mismatch(es).', $tcg_all ) ) );

echo "\nA SLICE STOPS EARLY FOR TIME, OR MEMORY - AFTER ONE PAGE AT LEAST\n" . str_repeat( '=', 124 ) . "\n";

fresh();
shop( 9321, $DIFFER );
$W['secs_per_page'] = 10;
weekly();
check( '10 s a page: the slice stops after six, within the minute\'s budget', array( pages_read(), lines( 'pages' ) ), array( range( 1, 6 ), array( 'Reconciliation: pages 1-6 of 47 checked, continuing.' ) ) );
check( '  the queue runner told to claim nothing more in this request', array( has_filter( 'action_scheduler_memory_exceeded', '__return_true' ), has_filter( 'action_scheduler_maximum_execution_time_likely_to_be_exceeded', '__return_true' ) ), array( 10, 10 ) );
check( '  the follow-up due now, the place at page 7', array( count( pending() ), place()['page'], place()['wait_until'] ), array( 1, 7, 0 ) );
TCGiant_Sync_Runtime::resume_queue_runs();
mark();
run_queue( 1 );
check( '  the next slice, a request of its own, reads six more', array( pages_read(), lines( 'pages' ) ), array( range( 7, 12 ), array( 'Reconciliation: pages 7-12 of 47 checked, continuing.' ) ) );
// Memory: past the runtime's 60% after page 14.
mark();
$W['on_page'][14] = function () { $GLOBALS['MEMORY'] = (int) ( 0.65 * 268435456 ); };
$W['secs_per_page'] = 1;
run_queue( 1 );
check( 'memory past the budget: the slice stops after the page that took it there', array( pages_read(), lines( 'pages' ) ), array( array( 13, 14 ), array( 'Reconciliation: pages 13-14 of 47 checked, continuing.' ) ) );
check( '  and the queue runner told too', has_filter( 'action_scheduler_memory_exceeded', '__return_true' ), 10 );
TCGiant_Sync_Runtime::resume_queue_runs();
// A request that has used its budget before the slice begins still reads one page.
mark();
foreach ( $W['queue'] as $tcg_id => $tcg_a ) {
	if ( 'pending' === $tcg_a['status'] ) {
		$W['queue'][ $tcg_id ]['status'] = 'running';
		$_SERVER['REQUEST_TIME_FLOAT'] = $GLOBALS['CLOCK'] - 100;   // 100 s into its request already.
		do_action_ref_array( FOLLOW, array_values( $tcg_a['args'] ) );
		$W['queue'][ $tcg_id ]['status'] = 'complete';
		break;
	}
}
check( 'a slice begun in a request already past its budget reads one page, then hands on', array( pages_read(), lines( 'page' ) ), array( array( 15 ), array( 'Reconciliation: page 15 of 47 checked, continuing.' ) ) );
TCGiant_Sync_Runtime::resume_queue_runs();
$GLOBALS['MEMORY'] = 8388608;
mark();
run_queue();
check( '  carried on to the end, the totals counting each page once', array( pages_read(), lines( 'Complete' ) ), array( range( 16, 47 ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 47 pages, found 3 mismatch(es).', $ALL ) ) ) );
check( '  47 calls in all', calls_today(), 47 );

echo "\nTHE DAY'S CALL BUDGET REFUSES A PAGE: THE PASS WAITS FOR 00:05:30 UTC, ITS PLACE KEPT\n" . str_repeat( '=', 124 ) . "\n";

fresh();
shop( 9321, $DIFFER );
weekly();
mark();
// The shared ceiling reached while page 30 is read: page 31 is refused before it is sent.
$W['on_page'][30] = function () { $GLOBALS['W']['options'][ 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ) ] = 40000; };
run_queue();
$tcg_place = place();
check( 'pages 26 to 30 read; page 31 refused, never sent', pages_read(), range( 26, 30 ) );
check( '  one line for the slice: what it read, and when it carries on', lines( 'Reconciliation:' ), array( 'Reconciliation: pages 26-30 of 47 checked; the day\'s eBay calls are used up (Daily API call budget exhausted. Will resume tomorrow.), so page 31 of 47 is read just after 00:05 UTC.' ) );
check( '    at warning', levels( 'calls are used up' ), array( 'warning' ) );
check( '  the place kept: page 31, the totals of pages 1-30, its try not counted', array( $tcg_place['page'], $tcg_place['tries'], $tcg_place['mismatches'], $tcg_place['owner'] ), array( 31, 0, 2, '' ) );
check( '  due at the next 00:05:30 UTC: the place, the follow-up', array( $tcg_place['wait_until'], pending()[0]['when'] ?? 0, count( pending() ) ), array( next_reset(), next_reset(), 1 ) );
check( '  the stall check two hours after that', wp_next_scheduled( STALL ), next_reset() + 7200 );
mark();
run_queue();
check( 'before then the queue runs nothing', array( pages_read(), $W['log'] ), array( array(), array() ) );
weekly();
check( 'a weekly run meanwhile: still under way, no second pass, nothing read', array( lines( 'Reconciliation' ), pages_read(), place()['started_at'], place()['page'] ), array( array( sprintf( 'Reconciliation: the check begun %s is still under way (pages 1-30 of 47 checked; page 31 is due at 00:05 UTC), so a second one does not start.', gmdate( 'Y-m-d H:i', $tcg_place['started_at'] ) . ' UTC' ) ), array(), $tcg_place['started_at'], 31 ) );
mark();
stall_check();
check( 'the stall check meanwhile: leaves it, armed again for two hours after it is due', array( pages_read(), $W['log'], wp_next_scheduled( STALL ) ), array( array(), array(), next_reset() + 7200 ) );
// A follow-up that runs before its time - one left over - does nothing.
mark();
$INV->continue_reconciliation( $tcg_place['started_at'] );
check( 'a follow-up early does nothing', array( pages_read(), $W['log'], place()['page'] ), array( array(), array(), 31 ) );
// 00:05:30 comes: a new UTC day, a new count.
later( next_reset() - time() + 1 );
$W['options'] = array_filter( $W['options'], function ( $k ) { return 0 !== strpos( $k, 'tcgiant_sync_api_calls_' ); }, ARRAY_FILTER_USE_KEY );
mark();
run_queue();
check( 'just after 00:05:30 the pass carries on from page 31, never from page 1', array( pages_read(), lines( 'Complete' ) ), array( range( 31, 47 ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 47 pages, found 3 mismatch(es).', $ALL ) ) ) );
// eBay's own daily limit, 518, lifts when it lifts: the page is asked about
// hourly - every 15 minutes in the hour after 00:05:30 - with the question
// every check, push and pass share (3.28.0 release fix round 2). Its HTTP 429
// is a failure that passes.
fresh();
shop( 900 );
$W['answers'][3] = array( array( '518', 'Calls to this call have exceeded the call limit.' ) );
weekly();
$tcg_when = place()['wait_until'];
check( 'eBay\'s 518: page 3 waits for the next slot, an hour at most, and the slot is shared', array( place()['page'], near( $tcg_when, TCGiant_Sync_Importer::after_ebay_518() ), $tcg_when <= time() + 3600, slot_518(), place()['e518'], place()['tries'], lines( 'call limit' ) ), array( 3, true, true, $tcg_when, 1, 0, array( line_518( 'pages 1-2 of 5 checked; ', 'page 3 of 5', $tcg_when ) ) ) );
as_unschedule_all_actions( FOLLOW );
mark();
stall_check();
weekly();
check( '  its follow-up lost meanwhile: the stall check and a weekly run still wait for its time', array( pages_read(), lines( 'still under way' ) !== array(), wp_next_scheduled( STALL ) ), array( array(), true, $tcg_when + 7200 ) );
// Its time comes, but a sale check met the 518 again meanwhile: nothing is sent.
later( $tcg_when - time() + 1 );
$tcg_slot = time() + 1800;
set_transient( TCGiant_Sync_Importer::EBAY_518_TRANSIENT, array( 'until' => $tcg_slot ), 1800 );
mark();
stall_check();
check( '  its time come, with the 518 met again meanwhile: nothing sent, asked again at that slot', array( calls_sent(), place()['page'], place()['wait_until'], lines( 'not lifted' ) ), array( array(), 3, $tcg_slot, array( sprintf( 'Reconciliation: eBay\'s call limit (518) has not lifted yet, so page 3 of 5 is read later - asked again at %s UTC.', gmdate( 'H:i', $tcg_slot ) ) ) ) );
// The slot comes; the question itself meets the 518: one call, and no page.
later( 1801 );
$W['probe_518'] = 1;
mark();
run_queue();
check( '  the question meets a 518 too: one call that hour, and no page read', array( calls_sent(), place()['page'], near( place()['wait_until'], TCGiant_Sync_Importer::after_ebay_518() ), slot_518() === place()['wait_until'] ), array( array( 'GeteBayOfficialTime' ), 3, true, true ) );
// It lifts: the next slot reads on from page 3.
later( place()['wait_until'] - time() + 1 );
mark();
run_queue();
check( '  it lifts: asked once, and the pass carries on from page 3 to the end', array( calls_sent(), pages_read(), lines( 'Complete' ) !== array(), place() ), array( array( 'GeteBayOfficialTime', 'GetSellerList', 'GetSellerList', 'GetSellerList' ), array( 3, 4, 5 ), true, null ) );
// It lifts in a pass longer than a slice: once a page is read it waits on
// the 518 no more, so the next slice asks nothing first.
fresh();
shop( 9321 );
$W['answers'][3] = array( array( '518', 'Calls to this call have exceeded the call limit.' ) );
weekly();
later( place()['wait_until'] - time() + 1 );
mark();
run_queue();
check( '  lifted in a pass of 47 pages: asked once, then every page read in two slices', array( count( array_keys( calls_sent(), 'GeteBayOfficialTime', true ) ), pages_read(), lines( 'Complete' ) !== array() ), array( 1, range( 3, 47 ), true ) );
// A 518, then a failure that passes once it has lifted: a try counted, and no
// longer waiting on the 518 - the next slice reads without asking first.
fresh();
shop( 900 );
$W['answers'][3] = array( array( '518', 'Calls to this call have exceeded the call limit.' ), array( '10007', 'Internal error to the application.' ) );
weekly();
later( place()['wait_until'] - time() + 1 );
mark();
run_queue();
check( '  a 518, then a failure that passes: tried again in 15 minutes, a try counted, no longer waiting on the 518', array( calls_sent(), place()['tries'], place()['e518'] ), array( array( 'GeteBayOfficialTime', 'GetSellerList' ), 1, 0 ) );
later( 901 );
mark();
run_queue();
check( '  so its next slice asks nothing first', array( calls_sent()[0] ?? '', pages_read() ), array( 'GetSellerList', array( 3, 4, 5 ) ) );
// Held for days: asked hourly, within the pass's three days; past them, the
// 518 still holding, the pass stops, and the next weekly run starts afresh
// (release fix round 3, L5).
fresh();
shop( 900 );
$W['answers'][3] = array_fill( 0, 4, array( '518', 'Calls to this call have exceeded the call limit.' ) );
weekly();
later( DAY_IN_SECONDS );
mark();
run_queue();
check( 'a 518 a day later: still asked about hourly, two calls that hour', array( calls_sent(), place()['page'], place()['e518'], near( place()['wait_until'], TCGiant_Sync_Importer::after_ebay_518() ), lines( 'call limit' ) ), array( array( 'GeteBayOfficialTime', 'GetSellerList' ), 3, 1, true, array( line_518( '', 'page 3 of 5', place()['wait_until'] ) ) ) );
later( 2 * DAY_IN_SECONDS + 1 );
$tcg_begun = place()['started_at'];
$tcg_two   = place()['checked'];
mark();
run_queue();
check( 'RF3-L5 past the pass\'s three days, the 518 still holding: it stops, in one line, sending nothing - not started again', array( lines( 'still holding, so it stops' ), calls_sent(), place(), pending(), wp_next_scheduled( STALL ) ), array( array( sprintf( 'Reconciliation: the check begun %s had not finished after 3 days, with eBay\'s call limit (518) still holding, so it stops. Pages 1-2 of 5 had been checked: %d item(s), 0 mismatch(es). The next weekly check starts again from page 1.', gmdate( 'Y-m-d H:i', $tcg_begun ) . ' UTC', $tcg_two ) ), array(), null, array(), false ) );
mark();
stall_check();
run_queue();
check( '  nothing more until the next weekly run', array( calls_sent(), place() ), array( array(), null ) );
weekly();
check( '  which starts a fresh pass from page 1', array( lines( 'Starting weekly' ) !== array(), array_slice( pages_read(), 0, 2 ) ), array( true, array( 1, 2 ) ) );
// Found out of date by the weekly run itself: that is the next weekly run,
// so it starts afresh, as any pass gone out of date does.
fresh();
shop( 900 );
$W['answers'][3] = array_fill( 0, 2, array( '518', 'Calls to this call have exceeded the call limit.' ) );
weekly();
as_unschedule_all_actions( FOLLOW );
later( 3 * DAY_IN_SECONDS + 1 );
mark();
weekly();
check( '  out of date under the 518, found by the weekly run: it starts again from page 1', array( count( lines( 'had not finished after 3 days, so it starts again from page 1' ) ), lines( 'still holding, so it stops' ), array_slice( pages_read(), 0, 2 ) ), array( 1, array(), array( 1, 2 ) ) );
// The plugin's own budget, used up, still waits for 00:05:30 - when it resets.
fresh();
shop( 900 );
$W['on_page'][2] = function () { $GLOBALS['W']['options'][ 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ) ] = 40000; };
weekly();
check( 'the plugin\'s own budget used up: waits for 00:05:30 UTC, not the hourly slot', array( place()['page'], place()['wait_until'], place()['e518'], slot_518() ), array( 3, next_reset(), 0, 0 ) );
fresh();
shop( 900 );
$W['answers'][3] = array( array( 'http', 429 ) );
weekly();
check( 'eBay\'s HTTP 429: tried again in 15 minutes, a try counted', array( place()['page'], near( place()['wait_until'], time() + 900 ), place()['tries'], lines( 'tried again' ) ), array( 3, true, 1, array( 'Reconciliation: pages 1-2 of 5 checked; page 3 of 5 could not be read (eBay returned HTTP 429 (too many requests).), so it is tried again in 15 minutes (try 2 of 5).' ) ) );
// Refused on the first page: nothing read yet, nothing known of the pages.
fresh();
shop( 900 );
$W['options'][ 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ) ] = 40000;
weekly();
check( 'refused on page 1: the pass waits, with nothing read', array( pages_read(), place()['page'], place()['wait_until'], lines( 'used up' ) ), array( array(), 1, next_reset(), array( 'Reconciliation: the day\'s eBay calls are used up (Daily API call budget exhausted. Will resume tomorrow.), so page 1 is read just after 00:05 UTC.' ) ) );

echo "\nA READ THAT FAILS FOR A REASON THAT PASSES: AGAIN IN 15 MINUTES, FIVE TRIES AT MOST\n" . str_repeat( '=', 124 ) . "\n";

$TCG_BUSY = array( '10007', 'Internal error to the application.' );
fresh();
list( $tcg_all ) = shop( 600 );
$W['answers'][2] = array( $TCG_BUSY, $TCG_BUSY );
$W['answers'][3] = array( $TCG_BUSY );
weekly();
check( 'page 2 fails: the slice says what it read, and tries again in 15 minutes', array( lines( 'Reconciliation:' ), place()['page'], place()['tries'], near( place()['wait_until'], time() + 900 ), near( pending()[0]['when'] ?? 0, time() + 900 ) ), array( array( 'Reconciliation: Starting weekly inventory check...', 'Reconciliation: page 1 of 3 checked; page 2 of 3 could not be read (Internal error to the application.), so it is tried again in 15 minutes (try 2 of 5).' ), 2, 1, true, true ) );
check( '  the stall check two hours after the try', near( wp_next_scheduled( STALL ), time() + 900 + 7200 ), true );
later( 900 );
mark();
run_queue();
check( '  15 minutes on it fails again: try 3', array( pages_read(), lines( 'Reconciliation:' ), place()['tries'] ), array( array( 2 ), array( 'Reconciliation: page 2 of 3 could not be read (Internal error to the application.), so it is tried again in 15 minutes (try 3 of 5).' ), 2 ) );
later( 900 );
mark();
run_queue();
check( '  then it reads; page 3 failing has a count of its own', array( pages_read(), lines( 'Reconciliation:' ), place()['tries'] ), array( array( 2, 3 ), array( 'Reconciliation: page 2 of 3 checked; page 3 of 3 could not be read (Internal error to the application.), so it is tried again in 15 minutes (try 2 of 5).' ), 1 ) );
later( 900 );
mark();
run_queue();
check( '  and the pass ends with every page counted once', array( pages_read(), lines( 'Complete' ) ), array( array( 3 ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 3 pages, found 0 mismatch(es).', $tcg_all ) ) ) );
// Five tries, then the pass stops.
fresh();
list( , $tcg_first ) = shop( 600, array(), array(), 1 );
$W['answers'][2] = array_fill( 0, 5, $TCG_BUSY );
weekly();
for ( $tcg_i = 0; $tcg_i < 4; $tcg_i++ ) {
	later( 900 );
	run_queue();
}
check( 'page 2 fails five times: tried five times, then the pass stops', array( pages_read(), place(), pending(), wp_next_scheduled( STALL ) ), array( array( 1, 2, 2, 2, 2, 2 ), null, array(), false ) );
check( '  in one line, at error, with what it had found', array( lines( 'stops' ), levels( 'stops' ) ), array( array( sprintf( 'Reconciliation: page 2 of 3 could not be read after 5 tries (Internal error to the application.), so this check stops. Page 1 of 3 had been checked: %d item(s), 0 mismatch(es). The next weekly check starts again from page 1.', $tcg_first ) ), array( 'error' ) ) );
check( '  nothing recorded', array( settings()['last_reconciliation'], settings()['last_reconciliation_mismatches'] ), array( OLD_RECORD, 7 ) );
mark();
weekly();
check( '  the next weekly run starts again at page 1', array( lines( 'Starting' ), pages_read() ), array( array( 'Reconciliation: Starting weekly inventory check...' ), array( 1, 2, 3 ) ) );
// No token for a while: a failure that passes.
fresh();
shop( 600 );
TCGiant_Sync_OAuth::$connected = false;
weekly();
TCGiant_Sync_OAuth::$connected = null;
check( 'no eBay token: tried again in 15 minutes', array( place()['page'], place()['tries'], count( lines( 'tried again in 15 minutes (try 2 of 5)' ) ) ), array( 1, 1, 1 ) );
// An answer from eBay about the request itself stops it at once.
fresh();
shop( 600 );
$W['answers'][2] = array( array( '37', 'Input data for tag <Pagination> is invalid or missing.' ) );
weekly();
check( 'an error from eBay that will not pass: the pass stops at once', array( place(), lines( 'stops' ), pages_read() ), array( null, array( sprintf( 'Reconciliation: page 2 of 3 could not be read (Input data for tag <Pagination> is invalid or missing.), so this check stops. Page 1 of 3 had been checked: %d item(s), 0 mismatch(es). The next weekly check starts again from page 1.', $tcg_first ) ), array( 1, 2 ) ) );

echo "\nONE PASS AT A TIME\n" . str_repeat( '=', 124 ) . "\n";

fresh();
shop( 9321, $DIFFER );
weekly();
$tcg_place = place();
mark();
weekly();
check( 'a weekly run while the follow-up waits: still under way, no second pass', array( lines( 'Reconciliation' ), pages_read(), place()['started_at'], place()['page'], count( pending() ) ), array( array( sprintf( 'Reconciliation: the check begun %s is still under way (pages 1-25 of 47 checked), so a second one does not start.', gmdate( 'Y-m-d H:i', $tcg_place['started_at'] ) . ' UTC' ) ), array(), $tcg_place['started_at'], 26, 1 ) );
// A slice at work on it in another request: it holds the pass, and wrote a
// minute ago - the follow-up it ran as is running, not waiting.
as_unschedule_all_actions( FOLLOW );
$tcg_live          = place();
$tcg_live['owner'] = 'another-request';
$tcg_live['beat']  = time() - 60;
$W['options'][ OPT ] = $tcg_live;
mark();
weekly();
stall_check();
$INV->continue_reconciliation( $tcg_live['started_at'] );
check( 'a weekly run, the stall check, a follow-up, while a slice is at work: each leaves it', array( pages_read(), lines( 'Reconciliation' ), place() ), array( array(), array( sprintf( 'Reconciliation: the check begun %s is still under way (pages 1-25 of 47 checked), so a second one does not start.', gmdate( 'Y-m-d H:i', $tcg_live['started_at'] ) . ' UTC' ) ), $tcg_live ) );
$W['options'][ OPT ]['beat'] = time() - 601;
mark();
stall_check();
check( '  ten minutes without a word, it is taken to be gone: the stall check carries on, each page read once', array( pages_read(), lines( 'Complete' ) ), array( range( 26, 47 ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 47 pages, found 3 mismatch(es).', $ALL ) ) ) );
// A follow-up for a pass another has replaced, or one that has ended, does nothing.
fresh();
shop( 9321 );
weekly();
as_unschedule_all_actions( FOLLOW );   // Its own lost: nothing else in hand.
mark();
$INV->continue_reconciliation( 12345 );
check( 'a follow-up for another pass does nothing', array( pages_read(), $W['log'], place()['page'] ), array( array(), array(), 26 ) );
fresh();
shop( 450 );
$INV->continue_reconciliation( time() );
stall_check();
check( '  nor one, nor the stall check, with no pass', array( pages_read(), $W['log'], place() ), array( array(), array(), null ) );
// Another run takes the pass over part way through a slice: this one stops.
fresh();
shop( 9321 );
$W['on_page'][3] = function () {
	$p          = place();
	$p['owner'] = 'another-run';
	$GLOBALS['W']['options'][ OPT ] = $p;
};
weekly();
check( 'a slice whose pass another run took over stops at its next page', array( pages_read(), lines( 'taken' ), place()['owner'], pending() ), array( array( 1, 2, 3 ), array( 'Reconciliation: another run has taken this check over, so this one stops.' ), 'another-run', array() ) );
fresh();
shop( 900 );
$W['answers'][3] = array( array( 'http', 429 ) );
$W['on_page'][3] = 'take_over';
weekly();
check( 'taken over as it hands on: it says so, and queues nothing', array( lines( 'taken' ), lines( 'tried again' ), pending(), place()['owner'] ), array( array( 'Reconciliation: another run has taken this check over, so this one stops.' ), array(), array(), 'another-run' ) );
fresh();
shop( 0 );
$W['on_page'][1] = 'take_over';
weekly();
check( 'taken over as it finishes: it says so, and records nothing', array( lines( 'taken' ), lines( 'Complete' ), settings()['last_reconciliation'], place()['owner'] ), array( array( 'Reconciliation: another run has taken this check over, so this one stops.' ), array(), OLD_RECORD, 'another-run' ) );
// Two weekly runs starting together: only one gets the pass.
fresh();
shop( 450 );
$W['options'][ OPT ] = array( 'started_at' => time(), 'page' => 1, 'total_pages' => 0, 'checked' => 0, 'mismatches' => 0, 'tries' => 0, 'wait_until' => 0, 'owner' => 'the-other', 'beat' => time() );
$tcg_raw = maybe_serialize( $W['options'][ OPT ] );
unset( $W['options'][ OPT ] );
// The other's row lands between this one's read and its write.
$W['before_insert'] = $tcg_raw;
$GLOBALS['wpdb'] = new class() extends Reconcile_Wpdb {
	public function query( $query ) {
		if ( isset( $GLOBALS['W']['before_insert'] ) && false !== strpos( $query, 'INSERT INTO wp_options' ) && false !== strpos( $query, OPT ) ) {
			$GLOBALS['W']['options'][ OPT ] = maybe_unserialize( $GLOBALS['W']['before_insert'] );
			unset( $GLOBALS['W']['before_insert'] );
		}
		return parent::query( $query );
	}
};
weekly();
$GLOBALS['wpdb'] = new Reconcile_Wpdb();
check( 'two weekly runs at once: the one whose write came second reads nothing', array( pages_read(), place()['owner'], lines( 'could not start' ) ), array( array(), 'the-other', array() ) );
// The database refusing the place: nothing read, and said.
fresh();
shop( 450 );
$W['refuse_sql'] = 'INSERT INTO wp_options';
weekly();
$W['refuse_sql'] = '';
check( 'the database will not record the place: nothing read, and said', array( pages_read(), lines( 'could not start' ), levels( 'could not start' ) ), array( array(), array( 'Reconciliation: could not start - the database would not record where the check had got to. It tries again next week.' ), array( 'error' ) ) );

echo "\nA PASS GONE QUIET IS CARRIED ON BY THE STALL CHECK (OR THE NEXT WEEKLY RUN)\n" . str_repeat( '=', 124 ) . "\n";

// Its follow-up lost.
fresh();
shop( 9321, $DIFFER );
weekly();
$tcg_place = place();
as_unschedule_all_actions( FOLLOW );
later( 7200 );
mark();
stall_check();
check( 'the follow-up lost: two hours on the stall check carries the pass on from page 26', array( pages_read(), lines( 'carries on from there' ) ), array( range( 26, 47 ), array( sprintf( 'Reconciliation: the check begun %s had stopped at page 26 of 47 without carrying on, so it carries on from there.', gmdate( 'Y-m-d H:i', $tcg_place['started_at'] - 7200 ) . ' UTC' ) ) ) );
check( '  to the end, the totals counting each page once', lines( 'Complete' ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 47 pages, found 3 mismatch(es).', $ALL ) ) );
// Its follow-up still waiting, more than two hours overdue: the queue is not running it.
fresh();
shop( 9321, $DIFFER );
weekly();
later( 7201 );
mark();
stall_check();
check( 'the follow-up two hours overdue: the stall check carries the pass on', array( pages_read(), lines( 'Complete' ) !== array() ), array( range( 26, 47 ), true ) );
check( '  and the overdue follow-up goes, never to run', array( pending(), run_queue() ), array( array(), 0 ) );
fresh();
shop( 9321, $DIFFER );
$W['secs_per_page'] = 10;
weekly();
later( 7201 );
mark();
stall_check();
check( '  one the stall check carried on and that hands on again leaves one follow-up, due now', array( pages_read(), count( pending() ), near( pending()[0]['when'] ?? 0, time() ) ), array( range( 7, 12 ), 1, true ) );
// Its follow-up due, not long overdue: left to it.
fresh();
shop( 9321, $DIFFER );
weekly();
later( 3600 );
mark();
stall_check();
check( 'the follow-up an hour overdue: the stall check leaves it, armed again two hours on', array( pages_read(), $W['log'], near( wp_next_scheduled( STALL ), time() + 7200 ), count( pending() ) ), array( array(), array(), true, 1 ) );
// The weekly run finds it gone quiet: carries it on, starts no new one.
fresh();
shop( 9321, $DIFFER );
weekly();
$tcg_place = place();
as_unschedule_all_actions( FOLLOW );
mark();
weekly();
check( 'the weekly run finds it gone quiet: carries it on from its place, no new pass', array( pages_read(), lines( 'Starting' ), place(), count( lines( 'carries on from there' ) ) ), array( range( 26, 47 ), array(), null, 1 ) );
// A slice that threw: it lets go, its place kept as its last page left it.
fresh();
shop( 9321, $DIFFER );
weekly();
$W['throw_on'][105451] = 'Lock wait timeout exceeded';   // Listing 5,451: page 28.
later( 3600 );   // The queue reaches the follow-up an hour on.
mark();
run_queue();
$tcg_after = place();
check( 'a slice that throws on page 28: said, as before', lines( 'Exception' ), array( 'Reconciliation: Exception — Lock wait timeout exceeded' ) );
check( '  its place as page 27 left it, let go of', array( $tcg_after['page'], $tcg_after['owner'], pending() ), array( 28, '', array() ) );
check( '  the stall check armed as the slice began', near( wp_next_scheduled( STALL ), time() + 7200 ), true );
unset( $W['throw_on'][105451] );
later( 7200 );
mark();
stall_check();
check( '  which carries it on from page 28 to the end, each page once', array( pages_read(), lines( 'Complete' ) ), array( range( 28, 47 ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 47 pages, found 3 mismatch(es).', $ALL ) ) ) );
// The host kills the slice outright on page 30: nothing in its request runs.
fresh();
shop( 9321, $DIFFER );
weekly();
$tcg_place = place();
$tcg_file  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tcg-reconcile-kill-' . getmypid() . '.ser';
later( 3600 );   // The queue reaches the follow-up an hour on.
mark();
save_world( $tcg_file );
list( $tcg_code, $tcg_out ) = child( 'kill', $tcg_file );
load_world( $tcg_file );
@unlink( $tcg_file );
$tcg_after = place();
check( 'killed while reading page 30 (a process of its own, ended there)', array( $tcg_code, $tcg_out, pages_read() ), array( 0, '', range( 26, 30 ) ) );
check( '  its place as page 29 left it, still marked as its, no line for the slice', array( $tcg_after['page'], '' !== $tcg_after['owner'], lines( 'continuing' ), lines( 'Complete' ) ), array( 30, true, array(), array() ) );
check( '  the stall check it armed as it began', near( wp_next_scheduled( STALL ), time() + 7200 ), true );
mark();
weekly();
check( '  a weekly run within ten minutes takes it to be at work: nothing read', array( pages_read(), count( lines( 'still under way' ) ) ), array( array(), 1 ) );
later( 7200 );
mark();
stall_check();
check( '  two hours on, the stall check carries it on from page 30, though the queue still has it running', array( pages_read(), lines( 'carries on from there' ) !== array() ), array( range( 30, 47 ), true ) );
check( '  to the end, each page counted once', array( lines( 'Complete' ), settings()['last_reconciliation_mismatches'] ), array( array( sprintf( 'Reconciliation: Complete. Checked %d items across 47 pages, found 3 mismatch(es).', $ALL ) ), 3 ) );

echo "\nA PAGE THAT ENDS ITS REQUEST THREE TIMES RUNNING IS STEPPED OVER\n" . str_repeat( '=', 124 ) . "\n";

// Killed on page 30 three times running: the follow-up, then the stall check twice.
fresh();
shop( 9321, $DIFFER );
weekly();
later( 3600 );
$tcg_kills = array();
$tcg_got   = array();
list( $tcg_code ) = kill_on( 30, 'queue' );
$tcg_got[]   = $tcg_code;
$tcg_kills[] = array( place()['page'], place()['kills'] );
foreach ( array( 1, 2 ) as $tcg_i ) {
	later( 7200 );
	list( $tcg_code ) = kill_on( 30, 'stall' );
	$tcg_got[]   = $tcg_code;
	$tcg_kills[] = array( place()['page'], place()['kills'] );
}
check( 'page 30 kills the follow-up, then the stall check twice: counted on the place, page 30 kept', array( $tcg_got, $tcg_kills, lines( 'stepped over' ) ), array( array( 0, 0, 0 ), array( array( 30, 0 ), array( 30, 1 ), array( 30, 2 ) ), array() ) );
// The run that steps over it is killed on page 31 in its turn.
later( 7200 );
mark();
kill_on( 31, 'stall' );
check( 'the third kill counted: the next run steps over page 30, in one line', array( lines( 'stepped over' ), levels( 'stepped over' ) ), array( array( 'Reconciliation: page 30 of 47 ended its request 3 times running, so it is stepped over: its listings are not checked in this pass.' ), array( 'warning' ) ) );
check( '  page 31 next, page 30 never read again, its count begun again', array( pages_read(), place()['page'], place()['kills'], place()['skipped'] ), array( array( 31 ), 31, 0, 1 ) );
later( 7200 );
mark();
stall_check();
check( '  one kill on page 31 is one: it is read, and the rest after it', array( lines( 'stepped over' ), pages_read() ), array( array(), range( 31, 47 ) ) );
check( '  the end line counts the page skipped, at warning', array( lines( 'Complete' ), levels( 'Complete' ) ), array( array( sprintf( 'Reconciliation: Complete. Checked %d items across 46 pages, found 3 mismatch(es), 1 page skipped.', $ALL - checkable( 5801, 6000 ) ) ), array( 'warning' ) ) );
check( '  recorded at the end, as ever', array( settings()['last_reconciliation_mismatches'], place() ), array( 3, null ) );
// Killed on page 30, which then reads; killed twice on page 31: never three in a row on one page.
fresh();
shop( 9321, $DIFFER );
weekly();
later( 3600 );
kill_on( 30, 'queue' );
later( 7200 );
kill_on( 31, 'stall' );
$tcg_after = place();
later( 7200 );
kill_on( 31, 'stall' );
check( 'killed on page 30, which then reads: the count starts again; killed on page 31 twice', array( $tcg_after['page'], $tcg_after['kills'], place()['page'], place()['kills'] ), array( 31, 0, 31, 1 ) );
later( 7200 );
mark();
stall_check();
check( '  three kills, but not three on one page in a row: nothing stepped over, every page read', array( lines( 'stepped over' ), pages_read(), lines( 'Complete' ) ), array( array(), range( 31, 47 ), array( sprintf( 'Reconciliation: Complete. Checked %d items across 47 pages, found 3 mismatch(es).', $ALL ) ) ) );
// The last page stepped over: the pass ends there, with nothing more read.
fresh();
list( $tcg_all ) = shop( 900 );
kill_on( 5, 'weekly' );
foreach ( array( 1, 2 ) as $tcg_i ) {
	later( 7200 );
	kill_on( 5, 'stall' );
}
later( 7200 );
mark();
stall_check();
check( 'the last page stepped over: the pass ends with nothing more read, at warning though nothing differed', array( pages_read(), lines( 'Complete' ), levels( 'Complete' ), count( lines( 'stepped over' ) ) ), array( array(), array( sprintf( 'Reconciliation: Complete. Checked %d items across 4 pages, found 0 mismatch(es), 1 page skipped.', $tcg_all - checkable( 801, 900 ) ) ), array( 'warning' ), 1 ) );

echo "\nA PASS OLDER THAN THREE DAYS STARTS AGAIN\n" . str_repeat( '=', 124 ) . "\n";

fresh();
shop( 9321, $DIFFER );
weekly();
$W['options'][ 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ) ] = 40000;
mark();
run_queue();
$tcg_place = place();
$W['options'] = array_filter( $W['options'], function ( $k ) { return 0 !== strpos( $k, 'tcgiant_sync_api_calls_' ); }, ARRAY_FILTER_USE_KEY );
later( 3 * 86400 - 60 );
mark();
run_queue();
check( 'three days less a minute after it began: it carries on', array( pages_read(), lines( 'starts again' ) ), array( range( 26, 47 ), array() ) );
fresh();
shop( 9321, $DIFFER );
weekly();
$tcg_place = place();
later( 3 * 86400 + 1 );
mark();
run_queue( 1 );
check( 'past three days: its follow-up starts it again from page 1, saying so', array( lines( 'starts again' ), lines( 'Starting' ), pages_read() ), array( array( sprintf( 'Reconciliation: the check begun %s had not finished after 3 days, so it starts again from page 1. Pages 1-25 of 47 had been checked: %d item(s), 2 mismatch(es).', gmdate( 'Y-m-d H:i', $tcg_place['started_at'] - 3 * 86400 - 1 ) . ' UTC', $FIRST ) ), array( 'Reconciliation: Starting weekly inventory check...' ), range( 1, 25 ) ) );
check( '  a new pass, begun now', array( near( place()['started_at'], time() ), place()['page'], place()['checked'] ), array( true, 26, $FIRST ) );
fresh();
shop( 9321, $DIFFER );
weekly();
later( 4 * 86400 );
mark();
weekly();
check( '  the weekly run the same', array( count( lines( 'starts again' ) ), pages_read() ), array( 1, range( 1, 25 ) ) );

echo "\n\"CHECK STOCK WEEKLY\" TURNED OFF STOPS A PASS AT ITS NEXT SLICE\n" . str_repeat( '=', 124 ) . "\n";

fresh();
shop( 9321, $DIFFER );
weekly();
other_request_saves( 'enable_reconciliation', '0' );
mark();
run_queue();
check( 'turned off after the first slice: the next stops it, in one line', array( $W['log'], pages_read() ), array( array( array( 'info', sprintf( 'Reconciliation: turned off in Settings, so the check in progress stops. Pages 1-25 of 47 had been checked: %d item(s), 2 mismatch(es).', $FIRST ) ) ), array() ) );
check( '  the place, the follow-up and the stall check gone; nothing recorded', array( place(), pending(), wp_next_scheduled( STALL ), settings()['last_reconciliation'] ), array( null, array(), false, OLD_RECORD ) );
fresh( array( 'enable_reconciliation' => '0' ) );
shop( 450 );
weekly();
check( 'off, with no pass: the weekly run says so, as before, and reads nothing', array( lines( 'Reconciliation' ), pages_read() ), array( array( 'Reconciliation: Starting weekly inventory check...', 'Reconciliation: Disabled in settings. Skipping.' ), array() ) );
// Turned off while the pass waits for the day's calls.
fresh();
shop( 900 );
$W['answers'][3] = array( array( '518', 'Calls to this call have exceeded the call limit.' ) );
weekly();
other_request_saves( 'enable_reconciliation', '0' );
later( next_reset() - time() + 1 );
mark();
run_queue();
check( '  and while it waited for 00:05:30: stopped when it comes', array( count( lines( 'turned off' ) ), place(), pages_read() ), array( 1, null, array() ) );

echo "\nTHE RECORD IS WRITTEN ONTO THE SETTINGS AS THE DATABASE HOLDS THEM (save_settings_keys())\n" . str_repeat( '=', 124 ) . "\n";

fresh( array( 'log_level' => 'info' ) );
shop( 9321, $DIFFER );
weekly();
// The last slice, in a request whose copy of the settings is taken as it
// begins; another request disconnects and saves a setting while page 47 is read.
$W['on_page'][47] = function () {
	other_request_saves( 'access_token', null );
	other_request_saves( 'refresh_token', null );
	other_request_saves( 'log_level', 'error' );
};
in_one_request( function () { run_queue(); } );
check( 'the record saved; the Disconnect and the setting another request saved stand', array( settings()['last_reconciliation_mismatches'] ?? null, settings()['last_reconciliation'] !== OLD_RECORD, array_key_exists( 'access_token', settings() ), array_key_exists( 'refresh_token', settings() ), settings()['log_level'] ), array( 3, true, false, false, 'error' ) );
// A token refreshed meanwhile is kept, not put back.
fresh();
shop( 450 );
$W['on_page'][3] = function () { other_request_saves( 'access_token', 'v^1.1#i^1#AT-REFRESHED' ); };
in_one_request( function () { weekly(); } );
check( '  a token refreshed while the pass ran is kept', array( settings()['access_token'], settings()['last_reconciliation_mismatches'] ), array( 'v^1.1#i^1#AT-REFRESHED', 0 ) );

echo "\nA STAGING COPY READS AS BEFORE, AND WRITES NOTHING TO EBAY\n" . str_repeat( '=', 124 ) . "\n";

list( $tcg_all ) = shop( 450, array( 7 => 99 ) );
list( $tcg_code, $tcg_out ) = child( 'staging' );
$tcg_staging = json_decode( $tcg_out, true );
check( 'on a staging copy the pass reads every page and ends, and sends nothing but GetSellerList', $tcg_staging, array( 'copy' => 'staging_blocked', 'calls' => array( 'GetSellerList' ), 'pages' => array( 1, 2, 3 ), 'complete' => array( sprintf( 'Reconciliation: Complete. Checked %d items across 3 pages, found 1 mismatch(es).', $tcg_all ) ), 'stock' => 99 ) );

echo "\nUNINSTALL AND DEACTIVATION\n" . str_repeat( '=', 124 ) . "\n";

list( , $tcg_out ) = uninstall_run( '1' );
$tcg_u = json_decode( $tcg_out, true );
check( 'uninstall with its data: the place deleted, the follow-up unscheduled, the stall check cleared', array( in_array( OPT, $tcg_u['deleted'] ?? array(), true ), in_array( FOLLOW, $tcg_u['unscheduled'] ?? array(), true ), in_array( STALL, $tcg_u['cleared'] ?? array(), true ) ), array( true, true, true ) );
list( , $tcg_out ) = uninstall_run( '0' );
$tcg_u = json_decode( $tcg_out, true );
check( 'without its data: the place kept, the follow-up and the stall check still go', array( in_array( OPT, $tcg_u['deleted'] ?? array( OPT ), true ), in_array( FOLLOW, $tcg_u['unscheduled'] ?? array(), true ), in_array( STALL, $tcg_u['cleared'] ?? array(), true ) ), array( false, true, true ) );
preg_match( '/public static function deactivate\(\) \{(.*?)\n\t\}/s', (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-cron.php' ), $tcg_body );
check( 'deactivation clears the stall check', false !== strpos( $tcg_body[1] ?? '', "wp_clear_scheduled_hook( '" . STALL . "' )" ), true );

done();
