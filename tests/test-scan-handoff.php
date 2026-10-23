<?php
/**
 * Harness: a full sync's scan that carries itself on, from the listing it
 * stopped at (3.27.6).
 *
 * Wizify, about 40,000 listings: Action Scheduler marked the scan's action
 * failed five minutes in ("in-progress for at least 300 seconds"), the
 * imports it had queued ran, and nothing more happened. The screen stayed on
 * "Page N/M" and the lock was found 49 minutes stale. The shop pressed Fetch
 * Inventory again and again, and each time the scan began again from page 1.
 * On 3.27.5:
 *
 * - A batch read ten pages whatever they cost, saving the listings eBay sent
 *   in full as it went, with no time or memory budget. A host that ended the
 *   request part way left the scan "scanning" with nothing to carry it on: the
 *   next batch was queued only at the end of a batch, and the scan had no
 *   death handler.
 * - Its place was the page it had begun, so a batch that did carry on skipped
 *   the rest of that page, whose listings were then never on the list of live
 *   listings either.
 * - Resume Import refused a scan saying "scanning", and the totals were
 *   written only at the end of each page.
 *
 * Each request here is a PHP process of its own (support/scan-request.php),
 * running the real importer and runtime against a played shop kept in files,
 * so a host ending one - by a PHP fatal, or from outside with no shutdown
 * function run - leaves exactly what it had written. Action Scheduler's queue
 * and WP-Cron are run from here, as a site runs them, and nothing else
 * carries the scan on.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-92s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 200 ) );
}

define( 'DAY_IN_SECONDS', 86400 );

$ROOT    = dirname( __DIR__ );
$REQUEST = __DIR__ . '/support/scan-request.php';
$BASE    = str_replace( '\\', '/', sys_get_temp_dir() ) . '/tcg-scan-handoff-' . getmypid();
$WORLD   = '';
$N       = 0;

if ( ! is_dir( $BASE ) ) {
	mkdir( $BASE, 0777, true );
}

// --- The played shop ----------------------------------------------------------

/** A shop with eBay connected, nothing running, the last delta position a day old. */
function world( array $scenario, array $files = array() ) {
	$GLOBALS['N']++;
	$GLOBALS['WORLD'] = $GLOBALS['BASE'] . '/w' . $GLOBALS['N'];
	mkdir( $GLOBALS['WORLD'], 0777, true );
	ws( 'scenario', $scenario );
	ws( 'options', array( 'tcgiant_sync_state' => array( 'status' => 'idle', 'sync_mode' => 'full', 'last_successful_sync_at' => '2026-10-01T00:00:00.000Z' ) ) );
	foreach ( $files as $name => $value ) {
		ws( $name, $value );
	}
}
function wl( $name, $default = null ) {
	$path = $GLOBALS['WORLD'] . '/' . $name . '.ser';
	return is_file( $path ) ? unserialize( file_get_contents( $path ) ) : $default;
}
function ws( $name, $value ) { file_put_contents( $GLOBALS['WORLD'] . '/' . $name . '.ser', serialize( $value ) ); }
function lines( $name ) {
	$path = $GLOBALS['WORLD'] . '/' . $name . '.txt';
	return is_file( $path ) ? file( $path, FILE_IGNORE_NEW_LINES ) : array();
}
function option( $name ) { return wl( 'options', array() )[ $name ] ?? null; }
function state() { return (array) option( 'tcgiant_sync_state' ); }
function place() { return (array) option( 'tcgiant_sync_scan_progress' ); }
function set_option( $name, array $changes ) {
	$o          = wl( 'options', array() );
	$o[ $name ] = array_merge( (array) ( $o[ $name ] ?? array() ), $changes );
	ws( 'options', $o );
}
function logged( $words ) { return count( array_filter( lines( 'log' ), function ( $l ) use ( $words ) { return false !== strpos( $l, $words ); } ) ); }
function pending( $hook ) { return array_values( array_filter( wl( 'queue', array() ), function ( $a ) use ( $hook ) { return $a['hook'] === $hook && 'pending' === $a['status']; } ) ); }
function ever_queued( $hook ) { return array_values( array_filter( lines( 'actions' ), function ( $l ) use ( $hook ) { return 0 === strpos( $l, $hook . ' ' ); } ) ); }
function backstop_armed() { foreach ( wl( 'cron', array() ) as $e ) { if ( 'tcgiant_sync_scan_resume' === $e[0] ) { return $e[1]; } } return false; }
/** ItemIDs saved, in order, with the request that saved each. */
function saves() { return array_map( function ( $l ) { return explode( ' ', $l ); }, lines( 'saved' ) ); }
function saved_ids() { return array_map( function ( $s ) { return $s[1]; }, saves() ); }
function saved_in( $request ) { return array_values( array_map( function ( $s ) { return $s[1]; }, array_filter( saves(), function ( $s ) use ( $request ) { return (int) $s[0] === (int) $request; } ) ) ); }
function requests() { return (int) wl( 'requests', 0 ); }
function lock_held() { return is_file( $GLOBALS['WORLD'] . '/tcgiant_sync.lock' ); }

/** One request, in a process of its own. */
function request( $what, $arg = '' ) {
	$flags = (string) ( wl( 'scenario', array() )['php_flags'] ?? '' );
	$cmd   = escapeshellarg( PHP_BINARY ) . ' ' . $flags . ' ' . escapeshellarg( $GLOBALS['REQUEST'] ) . ' ' . escapeshellarg( $GLOBALS['WORLD'] ) . ' ' . escapeshellarg( $what ) . ' ' . escapeshellarg( '' === $arg ? '-' : $arg ) . ' 2>&1';
	$out = array();
	exec( $cmd, $out, $code );
	return array( $code, implode( "\n", $out ) );
}

/** Action Scheduler runs the next pending action of a hook: claimed, run, and gone from the queue however it ended. */
function as_run( $hook ) {
	$q  = wl( 'queue', array() );
	$id = null;
	foreach ( $q as $i => $a ) {
		if ( $a['hook'] === $hook && 'pending' === $a['status'] ) {
			$q[ $i ]['status'] = 'running';
			$id                = $a['id'];
			$args              = $a['args'];
			break;
		}
	}
	if ( null === $id ) {
		return null;
	}
	ws( 'queue', $q );
	$r = 'tcgiant_sync_scan_all_pages' === $hook
		? request( 'batch', (string) ( $args['scan'] ?? '' ) )
		: request( 'import', (string) ( $args['item_id'] ?? '' ) );
	ws( 'queue', array_values( array_filter( wl( 'queue', array() ), function ( $a ) use ( $id ) { return $a['id'] !== $id; } ) ) );
	return $r;
}

/** Time passes: everything the scan wrote is that much older, the lock too. */
function time_passes( $seconds ) {
	$o = wl( 'options', array() );
	if ( isset( $o['tcgiant_sync_scan_progress']['ts'] ) ) {
		$o['tcgiant_sync_scan_progress']['ts'] -= $seconds;
	}
	foreach ( array( 'scan_page_at', 'last_activity_ts' ) as $key ) {
		if ( isset( $o['tcgiant_sync_state'][ $key ] ) ) {
			$o['tcgiant_sync_state'][ $key ] -= $seconds;
		}
	}
	ws( 'options', $o );
	$lock = $GLOBALS['WORLD'] . '/tcgiant_sync.lock';
	if ( is_file( $lock ) ) {
		file_put_contents( $lock, (string) ( (int) file_get_contents( $lock ) - $seconds ) );
	}
	// WP-Cron's events come that much nearer.
	ws( 'cron', array_map( function ( $e ) use ( $seconds ) { return array( $e[0], (int) $e[1] - $seconds ); }, wl( 'cron', array() ) ) );
}

/** WP-Cron runs the scan's backstop, if it is armed. */
function backstop() {
	if ( false === backstop_armed() ) {
		return null;
	}
	ws( 'cron', array_values( array_filter( wl( 'cron', array() ), function ( $e ) { return 'tcgiant_sync_scan_resume' !== $e[0]; } ) ) );
	return request( 'backstop' );
}

/**
 * The site left to itself: Action Scheduler runs the scan's next batch when
 * one is queued, and the imports; when nothing is queued and the scan is
 * still going, WP-Cron's backstop when it comes due - five minutes on.
 */
function run_by_itself( $max = 120 ) {
	$steps = array();
	for ( $n = 0; $n < $max; $n++ ) {
		if ( pending( 'tcgiant_sync_scan_all_pages' ) ) {
			$steps[] = 'as';
			as_run( 'tcgiant_sync_scan_all_pages' );
		} elseif ( in_array( state()['status'] ?? '', array( 'scanning', 'rate_limited', 'limit_reached' ), true ) && false !== backstop_armed() ) {
			// Until it is due, as WP-Cron runs it.
			$steps[] = 'cron';
			time_passes( max( 1, (int) backstop_armed() - time() + 1 ) );
			backstop();
		} elseif ( pending( 'tcgiant_sync_process_item_import' ) ) {
			$steps[] = 'import';
			as_run( 'tcgiant_sync_process_item_import' );
		} else {
			break;
		}
	}
	return $steps;
}

/** The shop being looked at, to come back to after a look at another. */
function world_now() { return $GLOBALS['WORLD']; }
function world_back( $dir ) { $GLOBALS['WORLD'] = $dir; }

/** Fetch Inventory pressed: the request's answer. */
function fetch() { return request( 'fetch' )[1]; }

/** The listings a plain shop of $pages pages of $per has: inline (three in four) and queued for GetItem. */
function shop_ids( $pages, $per ) {
	$inline = array();
	$queued = array();
	for ( $p = 1; $p <= $pages; $p++ ) {
		for ( $i = 1; $i <= $per; $i++ ) {
			$id = sprintf( '%d%03d', $p, $i );
			if ( 0 === $i % 4 ) { $queued[] = $id; } else { $inline[] = $id; }
		}
	}
	return array( $inline, $queued );
}

/** Every listing imported, inline or by GetItem, exactly once. */
function each_once( array $want ) {
	$got = saved_ids();
	sort( $got );
	sort( $want );
	return $got === $want;
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE REQUEST GETSELLERLIST SENDS: LISTINGS ENDING FROM WHEN THE SCAN BEGAN\n" . str_repeat( '=', 140 ) . "\n";

world( array() );
$xml = json_decode( request( 'apixml' )[1], true );
check( 'the real API class, given the scan\'s start: EndTimeFrom is that moment', 1 === preg_match( '#<EndTimeFrom>2026-10-20T08:00:00.000Z</EndTimeFrom>#', (string) ( $xml['fixed'] ?? '' ) ), true );
check( '  and EndTimeTo 120 days on from it', 1 === preg_match( '#<EndTimeTo>2027-02-17T08:00:00.000Z</EndTimeTo>#', (string) ( $xml['fixed'] ?? '' ) ), true );
preg_match( '#<EndTimeFrom>([^<]+)</EndTimeFrom>#', (string) ( $xml['now'] ?? '' ), $now_from );
check( 'given none, as Stock Review\'s reconciliation asks: from now, as before', isset( $now_from[1] ) && abs( strtotime( $now_from[1] ) - time() ) < 60, true );
check( 'a delta-mode scan still asks by ModTime, with no EndTime', array( false !== strpos( (string) $xml['delta'], '<ModTimeFrom>2026-10-21T00:00:00.000Z</ModTimeFrom>' ), false !== strpos( (string) $xml['delta'], 'EndTime' ) ), array( true, false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA BATCH KILLED BY A PHP FATAL PART WAY THROUGH A PAGE: THE SCAN CARRIES ON BY ITSELF, FROM THAT LISTING\n" . str_repeat( '=', 140 ) . "\n";

list( $inline, $queued ) = shop_ids( 3, 8 );
world( array( 'pages' => 3, 'per_page' => 8, 'die' => array( '2003' => array( 'how' => 'fatal', 'times' => 1 ) ) ) );
check( 'Fetch Inventory accepted', fetch(), 'OK' );
$scan = state()['full_started_at'];
$first = pending( 'tcgiant_sync_scan_all_pages' );
check( 'the scan is an Action Scheduler action for this scan, ahead of the imports, with the backstop armed', array( count( $first ), $first[0]['args'] ?? null, $first[0]['priority'] ?? null, false !== backstop_armed() ), array( 1, array( 'scan' => $scan ), 5, true ) );
list( $code, $said ) = as_run( 'tcgiant_sync_scan_all_pages' );
$died = requests();
check( 'the batch dies on eBay item 2003, page 2: a real fatal', array( 255 === $code || 0 !== $code, false !== strpos( $said, 'Allowed memory size' ) ), array( true, true ) );
check( '  the log names the listing and the page', logged( 'Full sync scan stopped by a PHP fatal error: Allowed memory size' ) && logged( 'while working on eBay item 2003 (page 2 of 3)' ), true );
check( '  it handed on at once: an action for this scan queued, the lock kept for it', array( count( pending( 'tcgiant_sync_scan_all_pages' ) ), pending( 'tcgiant_sync_scan_all_pages' )[0]['args'] ?? null, lock_held() ), array( 1, array( 'scan' => $scan ), true ) );
check( '  its place: page 2, at listing 3, 2003 in flight, let go of', array( place()['page'] ?? null, place()['next'] ?? null, place()['in_flight'] ?? null, place()['owner'] ?? null ), array( 2, 2, '2003', '' ) );
check( '  page 1 and the start of page 2 saved before it died, 2003 not', saved_in( $died ), array( '1001', '1002', '1003', '1005', '1006', '1007', '2001', '2002' ) );
$steps = run_by_itself();
check( 'left to itself, the next batch begins with the listing the last one died on', saved_in( $died + 1 )[0] ?? null, '2003' );
check( '  saying it is trying it again', logged( 'The last full sync batch stopped while importing eBay item 2003 (page 2). Trying it again (attempt 2 of 3)' ), 1 );
check( '  and the scan reads on to the end, its imports run: complete', array( state()['status'], state()['last_successful_sync_at'], count( pending( 'tcgiant_sync_prune_orphans' ) ) ), array( 'complete', $scan, 1 ) );
check( 'every listing imported exactly once - nothing lost, nothing twice', each_once( array_merge( $inline, $queued ) ), true );
check( '  each GetItem listing queued once', count( ever_queued( 'tcgiant_sync_process_item_import' ) ), count( $queued ) );
check( '  every listing on the list of live listings', count( array_intersect( array_merge( $inline, $queued ), (array) option( 'tcgiant_sync_active_ids' ) ) ), 24 );
check( '  the totals honest: 24 found, 6 queued, 24 imported, 18 of them by the scan', array( state()['total_found'], state()['total_queued'], state()['total_processed'], state()['scan_inline'], state()['total_errors'] ), array( 24, 6, 24, 18, 0 ) );
check( '  the scan clean, its place gone, no backstop left', array( state()['scan_complete_clean'], place(), backstop_armed() ), array( true, array(), false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SAME, KILLED FROM OUTSIDE WITH NOTHING RUN: THE BACKSTOP CARRIES IT ON\n" . str_repeat( '=', 140 ) . "\n";

world( array( 'pages' => 3, 'per_page' => 8, 'die' => array( '2003' => array( 'how' => 'hard', 'times' => 1 ) ) ) );
fetch();
$scan = state()['full_started_at'];
as_run( 'tcgiant_sync_scan_all_pages' );
$died = requests();
check( 'the batch is killed on 2003 with no shutdown function run: no line, nothing handed on', array( logged( 'Full sync scan stopped' ) + logged( 'Full sync scan ended' ), count( pending( 'tcgiant_sync_scan_all_pages' ) ), lines( 'died' ) ), array( 0, 0, array( $died . ' hard 2003' ) ) );
check( '  its place still held by it: page 2, 2003 in flight', array( place()['page'] ?? null, place()['in_flight'] ?? null, '' !== (string) ( place()['owner'] ?? '' ) ), array( 2, '2003', true ) );
check( '  the WP-Cron backstop it armed as it began is due five minutes on', ( function () { $at = backstop_armed(); return false !== $at && $at > time() + 240 && $at <= time() + 300; } )(), true );
backstop();
check( 'the backstop run at once finds the batch not yet gone: leaves it, saves nothing, re-arms', array( logged( 'another batch of it is still at work' ), saved_in( requests() ), false !== backstop_armed() ), array( 1, array(), true ) );
time_passes( max( 1, (int) backstop_armed() - time() + 1 ) );
backstop();
check( 'five minutes on, its request\'s own time limit (300 s, and 30 more) not yet run out: still left, nothing saved', array( logged( 'another batch of it is still at work' ), saved_in( requests() ) ), array( 2, array() ) );
check( '  the backstop comes back when it would be gone: a minute on, not five', ( function () { $in = (int) backstop_armed() - time(); return $in >= 55 && $in <= 65; } )(), true );
$steps = run_by_itself();
check( 'then the backstop takes the scan over, from 2003', array( $steps[0] ?? null, saved_in( $died + 3 )[0] ?? null ), array( 'cron', '2003' ) );
check( '  and it reads on to complete', array( state()['status'], state()['last_successful_sync_at'] ), array( 'complete', $scan ) );
check( 'every listing imported exactly once - nothing lost, nothing twice', each_once( array_merge( $inline, $queued ) ), true );
check( '  each GetItem listing queued once, all 24 live', array( count( ever_queued( 'tcgiant_sync_process_item_import' ) ), count( array_intersect( array_merge( $inline, $queued ), (array) option( 'tcgiant_sync_active_ids' ) ) ) ), array( 6, 24 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA LISTING THAT KILLS EVERY BATCH IS STEPPED OVER AFTER THE THIRD, AND NAMED\n" . str_repeat( '=', 140 ) . "\n";

foreach ( array( 'fatal', 'hard' ) as $how ) {
	world( array( 'pages' => 3, 'per_page' => 8, 'die' => array( '2006' => array( 'how' => $how, 'times' => 99 ) ) ) );
	fetch();
	run_by_itself();
	$want = array_values( array_diff( array_merge( $inline, $queued ), array( '2006' ) ) );
	check( "[$how] killed three times on 2006, then stepped over: the scan completes", array( count( lines( 'died' ) ), state()['status'] ), array( 3, 'complete' ) );
	check( "[$how]   the log names it", logged( 'The full sync was stopped 3 times in a row while importing eBay item 2006 (page 2). Moving past it' ), 1 );
	check( "[$how]   it is listed on Stock Review and queued for a stock check of its own", array( array_map( 'strval', array_keys( (array) option( 'tcgiant_sync_delta_held' ) ) ), ever_queued( 'tcgiant_sync_settle_item' ) ), array( array( '2006' ), array( 'tcgiant_sync_settle_item ["2006"] 10' ) ) );
	check( "[$how]   every other listing imported exactly once, and 2006 still counted live", array( each_once( $want ), in_array( '2006', (array) option( 'tcgiant_sync_active_ids' ), true ) ), array( true, true ) );
	check( "[$how]   and counted as an error", state()['total_errors'], 1 );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE BUDGET, NOT TEN PAGES, ENDS A BATCH: IT HANDS ON PART WAY THROUGH A PAGE\n" . str_repeat( '=', 140 ) . "\n";

// Each save takes 25 s on the played clock; the budget is 60 s.
world( array( 'pages' => 3, 'per_page' => 8, 'cost' => 25 ) );
fetch();
$scan = state()['full_started_at'];
as_run( 'tcgiant_sync_scan_all_pages' );
check( 'the first batch saves three listings and stops before the fourth', saved_in( 2 ), array( '1001', '1002', '1003' ) );
check( '  saying so, and where', logged( 'Full sync scan: stopped this batch on page 1, before listing 4, to stay within this host\'s limits (75 s of this request used, of a 60 s budget). The rest carries on in a fresh request.' ), 1 );
check( '  the next batch queued for this scan, the backstop armed, a loopback asked for', array( pending( 'tcgiant_sync_scan_all_pages' )[0]['args'] ?? null, false !== backstop_armed(), lines( 'dispatch' ) ), array( array( 'scan' => $scan ), true, array( '2' ) ) );
check( '  its totals written as it hands on, its place let go of', array( state()['total_processed'], state()['total_found'], place()['next'] ?? null, place()['owner'] ?? null ), array( 3, 3, 3, '' ) );
check( '  and tells Action Scheduler to run nothing more in this request: the next batch starts fresh', in_array( 'action_scheduler_memory_exceeded', lines( 'filters' ), true ), true );
as_run( 'tcgiant_sync_scan_all_pages' );
check( 'the next batch starts at listing 4 of page 1, not page 2', array( logged( 'Scan batch starting at page 1, listing 4.' ), saved_in( 3 )[0] ?? null ), array( 1, '1005' ) );
run_by_itself();
check( 'batch after batch, the scan completes', state()['status'], 'complete' );
check( '  every listing imported exactly once', each_once( array_merge( $inline, $queued ) ), true );
check( '  never more than three saves a request', max( array_count_values( array_map( function ( $s ) { return $s[0]; }, array_filter( saves(), function ( $s ) { return 0 !== strpos( $s[1], 'x' ); } ) ) ) ) <= 3, true );

// Out of memory rather than time.
world( array( 'pages' => 1, 'per_page' => 8, 'memory_after' => 2 ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
check( 'a batch that reaches 90% of the memory limit hands on too', array( saved_in( 2 ), logged( 'of the memory limit in use). The rest carries on in a fresh request.' ) ), array( array( '1001', '1002' ), 1 ) );
check( '  and tells Action Scheduler to claim nothing more in this request', in_array( 'action_scheduler_memory_exceeded', lines( 'filters' ), true ), true );

// A host that ends every request 30 s in, from outside, with nothing run -
// PHP can see no such limit, so the budget allows 60 s.
world( array( 'pages' => 3, 'per_page' => 8, 'cost' => 10, 'host_kills_after' => 3 ) );
fetch();
$steps = run_by_itself( 300 );
check( 'a host that ends every request at 30 s: killed twice, at about the same time, then each batch hands on before it', array( state()['status'], count( lines( 'died' ) ), count( array_filter( $steps, function ( $s ) { return 'cron' === $s; } ) ) ), array( 'complete', 2, 2 ) );
check( '  saying so, once it is twice: by 30 s, never less than three listings take', array( logged( 'Full sync scan: the last batch was ended from outside, with nothing run, about 30 s into its request, while importing eBay item 2001 - as an earlier one was at about the same time. The host does not let a request run longer than that, so from now on each batch hands on by 30 s.' ), logged( 'from now on each batch hands on by' ) ), array( 1, 1 ) );
check( '  every listing imported exactly once, none stepped over', array( each_once( array_merge( $inline, $queued ) ), logged( 'Moving past it' ) ), array( true, 0 ) );
// A request that holds 70% of the memory limit before any listing: what a
// batch adds is what counts, so it does not ask eBay for a page a listing.
world( array( 'pages' => 3, 'per_page' => 8, 'memory_base' => 0.7 ) );
fetch();
run_by_itself();
check( 'a site whose requests already use 70% of the memory limit: each page read once', array( count( lines( 'reads' ) ), state()['status'], each_once( array_merge( $inline, $queued ) ) ), array( 3, 'complete', true ) );
// From 75% to 82%: not much growth, but past 80% of the limit - it hands on.
world( array( 'pages' => 1, 'per_page' => 8, 'memory_base' => 0.75, 'memory_after' => 2, 'memory_to' => 0.82 ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
check( '  one that climbs from 75% to 82% of it hands on all the same, past 80%', array( count( saved_ids() ), logged( '82% of the memory limit in use' ) ), array( 2, 1 ) );
// A batch whose time runs out as its page ends asks eBay for no more.
world( array( 'pages' => 3, 'per_page' => 3, 'cost' => 25 ) );
fetch();
run_by_itself();
check( 'a batch out of time as its page ends reads no further page: each page read once', array( count( lines( 'reads' ) ), state()['status'], count( saved_ids() ) ), array( 3, 'importing', 9 ) );
// One that kills at 300 s never meets a batch: each hands on at 60 s.
world( array( 'pages' => 3, 'per_page' => 8, 'cost' => 10, 'host_kills_after' => 30 ) );
fetch();
run_by_itself();
check( 'a host that kills at 300 s: every batch hands on before then, none is killed', array( state()['status'], lines( 'died' ), each_once( array_merge( $inline, $queued ) ) ), array( 'complete', array(), true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE TOTALS ARE WRITTEN AS THE SCAN GOES, NOT ONLY AT THE END OF A PAGE\n" . str_repeat( '=', 140 ) . "\n";

world( array( 'pages' => 1, 'per_page' => 60 ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
$seen = array_map( function ( $l ) { return array_map( 'intval', explode( ' ', $l ) ); }, lines( 'seen' ) );
check( 'what the screens read while one page of 60 is saved: rising every 20 listings', array_values( array_unique( array_map( function ( $s ) { return $s[0]; }, $seen ) ) ), array( 0, 15, 30 ) );
check( '  listings found, the same', array_values( array_unique( array_map( function ( $s ) { return $s[1]; }, $seen ) ) ), array( 0, 20, 40 ) );
check( '  and the page\'s totals at its end: 60 found, 45 imported, 15 queued', array( state()['total_found'], state()['total_processed'], state()['total_queued'], state()['scan_inline'] ), array( 60, 45, 15, 45 ) );
// A batch killed between writing its counts to the state and noting that it
// had: the state holds them, and so does its place, unmarked.
world( array( 'pages' => 2, 'per_page' => 8, 'cost' => 25, 'rate_limit_pages' => array( 1 ) ) );
fetch();
$o    = wl( 'options', array() );
$scan = $o['tcgiant_sync_state']['full_started_at'];
$o['tcgiant_sync_state']['total_found']  = 5;
$o['tcgiant_sync_state']['scan_flushed'] = $scan . '#4';
$o['tcgiant_sync_scan_progress']         = array( 'scan' => $scan, 'page' => 1, 'next' => 0, 'in_flight' => '', 'owner' => '', 'ts' => time() - 400, 'add' => array( 'found' => 5 ), 'seq' => 4, 'tally' => array() );
ws( 'options', $o );
as_run( 'tcgiant_sync_scan_all_pages' );
check( 'counts a killed batch had written already are not added again by the next', array( state()['total_found'], place()['add'] ?? null, place()['seq'] ?? null ), array( 5, array(), 5 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT THE SCREENS ARE TOLD: THE QUEUED IMPORTS ON THEIR OWN, THE NEXT BATCH WAITING, RESUME IMPORT\n" . str_repeat( '=', 140 ) . "\n";

world( array( 'pages' => 2, 'per_page' => 8, 'cost' => 25 ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
$screen = json_decode( request( 'screen' )[1], true );
check( 'between batches: the next batch waiting since it was handed on, nothing to resume', array( $screen['waiting_since'] > 0 && abs( $screen['waiting_since'] - time() ) < 30, '' !== $screen['since_text'], $screen['stalled'], $screen['can_resume'] ), array( true, true, false, false ) );
$poll = json_decode( request( 'poll' )[1], true );
check( '  the dashboard\'s poll says the same', array( isset( $poll['progress']['waiting_since'] ), $poll['progress']['can_resume'] ?? null ), array( true, false ) );
run_by_itself();
world( array(), array( 'options' => array( 'tcgiant_sync_state' => array( 'status' => 'importing', 'sync_mode' => 'full', 'total_queued' => 400, 'total_processed' => 38100, 'total_errors' => 3, 'scan_inline' => 38000, 'scan_inline_errors' => 1 ) ) ) );
$screen = json_decode( request( 'screen' )[1], true );
check( 'importing: 102 of 400 queued listings done, not 38103 of 400', array( $screen['queued_done'], $screen['queued_pct'], $screen['inline'] ), array( 102, 26, 38000 ) );
world( array(), array( 'options' => array( 'tcgiant_sync_state' => array( 'status' => 'importing', 'sync_mode' => 'full', 'total_queued' => 2, 'total_processed' => 5, 'total_errors' => 0 ) ) ) );
check( 'a sync from before 3.27.6, with no count of its own: capped at what was queued', json_decode( request( 'screen' )[1], true )['queued_pct'], 100 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFETCH INVENTORY AND RESUME IMPORT: A LIVE SCAN BY ITS LAST LISTING, A STALLED ONE CARRIED ON\n" . str_repeat( '=', 140 ) . "\n";

world( array( 'pages' => 3, 'per_page' => 8, 'cost' => 25 ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
as_run( 'tcgiant_sync_scan_all_pages' );
// Many batches into one page: the page was begun 40 minutes ago, its last listing a minute ago.
set_option( 'tcgiant_sync_state', array( 'scan_page_at' => time() - 2400, 'last_activity_ts' => time() - 2400 ) );
set_option( 'tcgiant_sync_scan_progress', array( 'ts' => time() - 60 ) );
check( 'Fetch Inventory while the scan works through a long page: refused, judged by its last listing', explode( ':', fetch() )[1] ?? '', 'scan_running' );
check( 'Resume Import while it is live: refused', array( request( 'resume' )[1], logged( 'Cannot resume' ) ), array( 'RETURNED', 1 ) );
$delta = request( 'delta' );
check( 'the hourly sync leaves it alone, though the state was written 40 minutes ago', array( logged( 'Sync already in progress' ), state()['sync_mode'] ), array( 1, 'full' ) );

// Then nothing for fifteen minutes - neither the queue nor WP-Cron ran.
ws( 'queue', array_values( array_filter( wl( 'queue', array() ), function ( $a ) { return 'tcgiant_sync_scan_all_pages' !== $a['hook']; } ) ) );
ws( 'cron', array() );
$imports_waiting = count( pending( 'tcgiant_sync_process_item_import' ) );
time_passes( 900 );
$answer = fetch();
check( 'Fetch Inventory on a scan stalled 15 minutes: refused, pointing to Resume Import', array( explode( ':', $answer )[1] ?? '', false !== strpos( $answer, 'but has made no progress for 16 minutes' ) || false !== strpos( $answer, 'but has made no progress for 15 minutes' ), false !== strpos( $answer, 'Press Resume Import to carry it on from where it stopped.' ) ), array( 'scan_stalled', true, true ) );
$screen = json_decode( request( 'screen' )[1], true );
check( '  the screens offer Resume Import, saying since when nothing has happened', array( $screen['stalled'], $screen['can_resume'], '' !== $screen['since_text'] ), array( true, true, true ) );
$at   = place();
$read = count( lines( 'reads' ) );
request( 'resume' );
check( 'Resume Import carries it on from its listing, not page 1', logged( sprintf( 'Resuming sync from page 1/3, at listing %d of it.', $at['next'] + 1 ) ), 1 );
check( '  its queued imports kept, the next batch queued for this scan', array( count( pending( 'tcgiant_sync_process_item_import' ) ), count( pending( 'tcgiant_sync_scan_all_pages' ) ), state()['status'] ), array( $imports_waiting, 1, 'scanning' ) );
run_by_itself();
check( '  and it completes, carrying on with page 1 where it stopped', array( state()['status'], explode( ' ', lines( 'reads' )[ $read ] )[0] ), array( 'complete', '1' ) );
check( '  every listing imported exactly once', each_once( array_merge( $inline, $queued ) ), true );

// A scan begun by 3.27.5, which died part way through page 2: it kept only
// the page it had begun, and its listings were not yet on the live list.
world( array( 'pages' => 3, 'per_page' => 8 ), array( 'options' => array( 'tcgiant_sync_state' => array( 'status' => 'scanning', 'sync_mode' => 'full', 'current_page' => 2, 'total_pages' => 3, 'full_started_at' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 3600 ), 'scan_page_at' => time() - 900, 'last_activity_ts' => time() - 900, 'last_successful_sync_at' => '2026-10-01T00:00:00.000Z', 'total_found' => 8, 'total_queued' => 2, 'total_processed' => 6 ), 'tcgiant_sync_active_ids' => array( '1001', '1002', '1003', '1004', '1005', '1006', '1007', '1008' ) ) ) );
request( 'resume' );
run_by_itself();
check( 'a 3.27.5 scan stalled on page 2, resumed: page 2 read again from its first listing, not skipped', array( explode( ' ', lines( 'reads' )[0] )[0], saved_ids()[0] ?? null ), array( '2', '2001' ) );
check( '  so every listing on it is on the live list', count( array_intersect( array_merge( $inline, $queued ), (array) option( 'tcgiant_sync_active_ids' ) ) ), 24 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEMERGENCY STOP, A NEWER FETCH, AND TWO BATCHES AT ONCE\n" . str_repeat( '=', 140 ) . "\n";

world( array( 'pages' => 3, 'per_page' => 8, 'on_save' => array( '2003' => 'stop' ) ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
$last = lines( 'saved' );
check( 'Emergency Stop pressed while 2003 saves: the batch stops before the next listing', array( state()['status'], end( $last ) ), array( 'stopped', '2 2003' ) );
check( '  says so, queues nothing, and leaves no backstop', array( logged( 'Full sync scan stopped as requested, on page 2.' ), count( pending( 'tcgiant_sync_scan_all_pages' ) ), backstop_armed() ), array( 1, 0, false ) );
check( '  its place kept for Resume Import: page 2, the listing after 2003', array( place()['page'] ?? null, place()['next'] ?? null, place()['owner'] ?? null ), array( 2, 3, '' ) );
request( 'resume' );
run_by_itself();
check( 'Resume Import: on from 2005 (2004 is queued), to complete, nothing saved twice', array( state()['status'], count( saved_ids() ) === count( array_unique( saved_ids() ) ), in_array( '2005', saved_ids(), true ) ), array( 'complete', true, true ) );
// Pressed just as the batch finds its time used up: it stops; it does not
// hand the scan on to a batch of its own.
world( array( 'pages' => 2, 'per_page' => 8, 'cost' => 25, 'stop_as_time_runs_out' => true ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
check( 'Emergency Stop pressed as a batch runs out of time: stopped, nothing queued after it', array( state()['status'], count( saved_ids() ), count( pending( 'tcgiant_sync_scan_all_pages' ) ), backstop_armed(), logged( 'Full sync scan stopped as requested, on page 1.' ), logged( 'to stay within this host' ) ), array( 'stopped', 3, 0, false, 1, 0 ) );

world( array( 'pages' => 3, 'per_page' => 8, 'on_save' => array( '2003' => 'refetch' ) ) );
fetch();
$old = state()['full_started_at'];
as_run( 'tcgiant_sync_scan_all_pages' );
check( 'a newer full sync begun while 2003 saves: this batch stops, writing nothing of its own', array( logged( 'Full sync scan: a newer full sync has started, so this one stops on page 2.' ), place(), count( pending( 'tcgiant_sync_scan_all_pages' ) ) ), array( 1, array(), 0 ) );
$replaced = world_now();
world( array( 'pages' => 3, 'per_page' => 8, 'cost' => 25, 'on_save' => array( '1003' => 'refetch' ) ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
check( '  the same with its time used up as well: it does not hand the new scan on, nor count into it', array( logged( 'a newer full sync has started, so this one stops on page 1' ), place(), count( pending( 'tcgiant_sync_scan_all_pages' ) ), (int) ( state()['total_found'] ?? 0 ) ), array( 1, array(), 0, 0 ) );
world_back( $replaced );
// A batch queued for the old scan, left in the queue.
$q   = wl( 'queue', array() );
$q[] = array( 'id' => 900, 'hook' => 'tcgiant_sync_scan_all_pages', 'args' => array( 'scan' => $old ), 'group' => 'tcgiant_sync_group', 'status' => 'pending', 'priority' => 5 );
ws( 'queue', $q );
$before = count( saved_ids() );
as_run( 'tcgiant_sync_scan_all_pages' );
check( '  a batch queued for the old scan does nothing', array( logged( 'this batch was queued for a scan that has since been replaced' ), count( saved_ids() ), count( lines( 'reads' ) ) ), array( 1, $before, 2 ) );

world( array( 'pages' => 1, 'per_page' => 8, 'cost' => 25 ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
set_option( 'tcgiant_sync_scan_progress', array( 'owner' => 'another-batch', 'ts' => time() - 10 ) );
$before = count( saved_ids() );
as_run( 'tcgiant_sync_scan_all_pages' );
check( 'a batch finding another at work leaves it: nothing read, nothing saved, the backstop re-armed', array( logged( 'another batch of it is still at work' ), count( lines( 'reads' ) ), count( saved_ids() ), false !== backstop_armed() ), array( 1, 1, $before, true ) );
set_option( 'tcgiant_sync_scan_progress', array( 'owner' => '', 'ts' => time() - 10 ) );
$s = wl( 'scenario', array() );
ws( 'scenario', array_merge( $s, array( 'lose_claim' => true ) ) );
request( 'backstop' );
check( 'two starting together: the one whose claim the database refused leaves it', array( logged( 'another batch of it is still at work' ), count( lines( 'reads' ) ) ), array( 2, 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PAGE THAT CANNOT BE READ, AND EBAY'S RATE LIMIT\n" . str_repeat( '=', 140 ) . "\n";

world( array( 'pages' => 3, 'per_page' => 8, 'die_on_read' => array( 2 => array( 'how' => 'fatal', 'times' => 1 ) ) ) );
fetch();
run_by_itself();
check( 'a batch killed reading page 2: the next reads it again, and the scan completes', array( logged( 'the last attempt to read page 2 was stopped before it finished. Trying again (attempt 2 of 3).' ), state()['status'], each_once( array_merge( $inline, $queued ) ) ), array( 1, 'complete', true ) );

world( array( 'pages' => 3, 'per_page' => 8, 'die_on_read' => array( 2 => array( 'how' => 'hard', 'times' => 99 ) ) ) );
fetch();
run_by_itself();
check( 'killed reading page 2 every time: after three, the scan stops with an error, not for ever', array( count( lines( 'died' ) ), state()['status'], state()['last_error'] ?? '' ), array( 3, 'error', 'Stopped on page 2: each of the last 3 attempts to read it was stopped before it finished, usually by the host.' ) );
check( '  nothing left to carry it on, its place kept, Resume Import offered', array( count( pending( 'tcgiant_sync_scan_all_pages' ) ), backstop_armed(), place()['page'] ?? null, json_decode( request( 'screen' )[1], true )['can_resume'] ), array( 0, false, 2, true ) );

// Something throws past the scan, and Action Scheduler catches it.
world( array( 'pages' => 3, 'per_page' => 8, 'throw_on_read' => array( 2 => 1 ) ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
check( 'a batch something throws past lets go of the scan, keeping its place, the backstop armed', array( place()['owner'] ?? null, place()['page'] ?? null, place()['reading'] ?? null, false !== backstop_armed() ), array( '', 2, 2, true ) );
run_by_itself();
check( '  the backstop carries it on, page 2 read again, to complete', array( state()['status'], logged( 'the last attempt to read page 2 was stopped before it finished' ), each_once( array_merge( $inline, $queued ) ) ), array( 'complete', 1, true ) );

world( array( 'pages' => 3, 'per_page' => 8, 'rate_limit_pages' => array( 2 ) ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
check( 'eBay\'s rate limit on page 2: paused, its retry the only WP-Cron event, nothing in the queue', array( state()['status'], count( wl( 'cron', array() ) ), backstop_armed() > time() + 800, count( pending( 'tcgiant_sync_scan_all_pages' ) ) ), array( 'rate_limited', 1, true, 0 ) );
run_by_itself();
check( '  the retry reads page 2 and the scan completes, each listing once', array( state()['status'], each_once( array_merge( $inline, $queued ) ) ), array( 'complete', true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nLISTINGS THAT END WHILE THE SCAN RUNS DO NOT MOVE A LIVE ONE OUT OF ITS SIGHT\n" . str_repeat( '=', 140 ) . "\n";

/**
 * eBay pages a seller's listings by end time. Twelve, four to a page; after
 * the fourth save - page 1 done - 1001, on page 1 and saved, sells and ends.
 */
function model( $window, array $events, $cost = 0 ) {
	$listings = array();
	for ( $i = 1; $i <= 12; $i++ ) {
		$listings[ sprintf( '1%03d', $i ) ] = array( 'end' => time() + $i * DAY_IN_SECONDS, 'status' => 'Active' );
	}
	world( array( 'cost' => $cost ), array( 'model' => array( 'window' => $window, 'per_page' => 4, 'now' => time(), 'saves' => 0, 'listings' => $listings, 'events' => $events ) ) );
	fetch();
	run_by_itself();
}
$all = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$all[] = sprintf( '1%03d', $i );
}
model( 'now', array( 4 => array( '1001' ) ) );
check( 'asked from "now" on every page, as 3.27.5 asked: 1005 slips onto page 1 and is never seen', array( in_array( '1005', saved_ids(), true ), in_array( '1005', (array) option( 'tcgiant_sync_active_ids' ), true ) ), array( false, false ) );
model( 'fixed', array( 4 => array( '1001' ) ) );
check( 'asked from when the scan began: every listing read, each once', each_once( $all ), true );
check( '  every read asked from that moment', array_values( array_unique( array_map( function ( $l ) { return explode( ' ', $l )[1]; }, lines( 'reads' ) ) ) ), array( state()['full_started_at'] ) );
// Part way through page 1, after a hand-on, 1009 on page 3 ends and moves to
// the front: page 1 read again is not what it was. Done listings keep their
// place; the rest are worked through as eBay now sends them.
model( 'fixed', array( 2 => array( '1009' ) ), 25 );
check( 'a page read again after it changed: no listing lost, none saved twice, the ended one not saved', each_once( array_values( array_diff( $all, array( '1009' ) ) ) ), true );
check( '  and the ended listing not saved as live', in_array( '1009', saved_ids(), true ), false );
// Part way through page 2, 1008 - still to do on it - ends and moves to the
// front: read again, page 2 no longer has it, and has 1004 back from page 1.
model( 'fixed', array( 6 => array( '1008' ) ), 25 );
check( 'a listing still to do that ends and leaves the page: named in the log, kept on the live list', array( logged( 'page 2 has changed on eBay since this scan first read it, and 1 listing(s) it had still to do are no longer on it (1008)' ), in_array( '1008', (array) option( 'tcgiant_sync_active_ids' ), true ) ), array( 1, true ) );
check( '  every live listing saved, 1004 - back on the page it was read from - twice: the cost of a read again', array( count( array_unique( saved_ids() ) ), count( saved_ids() ), array_keys( array_filter( array_count_values( saved_ids() ), function ( $n ) { return $n > 1; } ) ) ), array( 11, 12, array( 1004 ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE REST OF WHAT 3.27.5 GUARANTEED\n" . str_repeat( '=', 140 ) . "\n";

// The site filter: another site's row with no product is left out before any work, part way through batches.
world( array( 'pages' => 2, 'per_page' => 8, 'cost' => 25, 'primary_only' => true, 'sites' => array( '1002' => 'UK', '1003' => 'UK', '1004' => 'Germany', '2001' => 'US' ) ), array( 'linked' => array( 501 => '1003' ) ) );
fetch();
run_by_itself();
check( 'other sites\' rows with no product: never saved or queued, still live for the pruner', array( in_array( '1002', saved_ids(), true ), in_array( '1004', saved_ids(), true ), count( array_intersect( array( '1002', '1004' ), (array) option( 'tcgiant_sync_active_ids' ) ) ) ), array( false, false, 2 ) );
check( '  one with a product keeps syncing, its site recorded', array( in_array( '1003', saved_ids(), true ), in_array( '501 UK', lines( 'record_site' ), true ) ), array( true, true ) );
check( '  counted for the warning a full sync gives', array( state()['site_other_seen'], state()['site_other_sites'], state()['site_primary_seen'] ), array( 3, array( 'UK', 'Germany' ), 1 ) );

// Resume Import on a delta stopped by hand reads pages of what changed since
// its window began (the scan of old): that list is not every live listing.
world( array( 'pages' => 1, 'per_page' => 8 ), array( 'options' => array( 'tcgiant_sync_state' => array( 'status' => 'stopped', 'sync_mode' => 'delta', 'delta_mod_from' => '2026-10-21T00:00:00.000Z', 'current_page' => 1, 'total_pages' => 0, 'last_successful_sync_at' => '2026-10-21T00:05:00.000Z', 'total_found' => 0, 'total_queued' => 0, 'total_processed' => 0, 'total_errors' => 0 ) ) ) );
request( 'resume' );
as_run( 'tcgiant_sync_scan_all_pages' );
check( 'Resume Import\'s page scan of a delta: read to its end, but never authorising the clean-up', array( state()['status'], state()['scan_complete_clean'] ), array( 'importing', false ) );

// The host ends a batch while it writes the list of live listings: the
// list is written over, never deleted first, so nothing on it is lost.
foreach ( array( 'fatal', 'hard' ) as $how ) {
	$linked = array();
	foreach ( array_merge( $inline, $queued ) as $n => $id ) {
		$linked[ 5001 + $n ] = $id;
	}
	world( array( 'pages' => 3, 'per_page' => 8, 'die_in_ids_write' => array( 'write' => 2, 'how' => $how ) ), array( 'linked' => $linked ) );
	fetch();
	run_by_itself();
	request( 'prune' );
	check( "[$how] killed writing the list of live listings: every listing still on it, nothing trashed", array( state()['status'], count( array_intersect( array_merge( $inline, $queued ), (array) option( 'tcgiant_sync_active_ids' ) ) ) + ( null === option( 'tcgiant_sync_active_ids' ) ? 24 : 0 ), lines( 'trashed' ), logged( 'Inventory Pruning: No orphaned products found.' ) ), array( 'complete', 24, array(), 1 ) );
}
// A write of the list the database refuses: no clean-up after that sync.
world( array( 'pages' => 3, 'per_page' => 8, 'ids_write_refused' => 2 ), array( 'linked' => array( 7001 => '1001', 7002 => '990000000001' ) ) );
fetch();
run_by_itself();
request( 'prune' );
check( 'the database refuses the list of live listings once: the sync completes, the clean-up does not run', array( state()['status'], state()['scan_complete_clean'], lines( 'trashed' ), logged( 'the list of live eBay listings could not be saved' ), logged( 'Inventory Pruning: Skipped' ) ), array( 'complete', false, array(), 1, 1 ) );

// Every page read, an import put off by eBay's rate limit, Resume Import
// pressed: back to importing, and the imports complete it as before.
world( array( 'pages' => 2, 'per_page' => 8 ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
$scan = state()['full_started_at'];
set_option( 'tcgiant_sync_state', array( 'status' => 'rate_limited' ) );
request( 'resume' );
check( 'Resume Import after the scan has read every page: importing again, no scan queued', array( state()['status'], count( pending( 'tcgiant_sync_scan_all_pages' ) ), backstop_armed(), logged( 'Resuming import phase: every page was read already' ) ), array( 'importing', 0, false, 1 ) );
run_by_itself();
check( '  and the last import completes it: position moved, the clean-up queued', array( state()['status'], state()['last_successful_sync_at'], count( pending( 'tcgiant_sync_prune_orphans' ) ) ), array( 'complete', $scan, 1 ) );
// A sync left "scanning" with every page read, and a batch that comes along.
world( array( 'pages' => 2, 'per_page' => 8 ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
set_option( 'tcgiant_sync_state', array( 'status' => 'scanning' ) );
request( 'backstop' );
check( 'a batch finding a sync "scanning" with every page read puts it back to importing', array( state()['status'], count( lines( 'reads' ) ) ), array( 'importing', 2 ) );

// Its place lost, a scan 3.27.6 began reads again the page it was on; one
// 3.27.5 began goes on from the next page, as 3.27.5's next batch did.
world( array( 'pages' => 3, 'per_page' => 8 ), array( 'options' => array( 'tcgiant_sync_state' => array( 'status' => 'scanning', 'sync_mode' => 'full', 'current_page' => 2, 'total_pages' => 3, 'full_started_at' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 ), 'scan_page_at' => time() - 60, 'scan_inline' => 6, 'total_found' => 8, 'total_queued' => 2, 'total_processed' => 6, 'last_successful_sync_at' => '2026-10-01T00:00:00.000Z' ) ) ) );
request( 'backstop' );
check( 'a 3.27.6 scan whose place was lost: the page it was on read again, not skipped', explode( ' ', lines( 'reads' )[0] ?? '' )[0], '2' );
world( array( 'pages' => 3, 'per_page' => 8 ), array( 'options' => array( 'tcgiant_sync_state' => array( 'status' => 'scanning', 'sync_mode' => 'full', 'current_page' => 2, 'total_pages' => 3, 'full_started_at' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 ), 'scan_page_at' => time() - 60, 'total_found' => 16, 'total_queued' => 4, 'total_processed' => 12, 'last_successful_sync_at' => '2026-10-01T00:00:00.000Z' ) ) ) );
request( 'backstop' );
check( '  a 3.27.5 scan, as 3.27.5 carried it on: from the next page', explode( ' ', lines( 'reads' )[0] ?? '' )[0], '3' );

// A listing that has ended since the scan began: not saved as live, still live for the pruner.
world( array( 'pages' => 1, 'per_page' => 8, 'ended' => array( '1002' => 'Completed' ) ) );
fetch();
run_by_itself();
check( 'a listing ended since the scan began: not saved, not counted, still on the live list', array( in_array( '1002', saved_ids(), true ), state()['total_found'], in_array( '1002', (array) option( 'tcgiant_sync_active_ids' ), true ) ), array( false, 7, true ) );

// Every listing imported inline: as 3.27.4 left it, importing, nothing moved.
world( array( 'pages' => 1, 'per_page' => 3, 'cost' => 25 ) );
fetch();
run_by_itself();
check( 'an all-inline full sync, however many batches: importing, no position moved, no pruner', array( state()['status'], state()['last_successful_sync_at'], count( pending( 'tcgiant_sync_prune_orphans' ) ), count( saved_ids() ) ), array( 'importing', '2026-10-01T00:00:00.000Z', 0, 3 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA SCAN AT WORK UNDER ANOTHER STATUS, OR AFTER A BATCH DEATH: NOTHING STARTS OVER IT (FAILS ON THE FIRST 3.27.6 CANDIDATE)\n" . str_repeat( '=', 140 ) . "\n";

// The recheck's probes: a PHP fatal let the lock go and nothing took it again;
// a GetItem import at eBay's rate limit said "rate_limited"; then Fetch
// Inventory began again from page 1, cancelling the queued imports, and the
// hourly sync took the scan over and left it unfinished.
list( $inline4, $queued4 ) = shop_ids( 4, 8 );
foreach ( array( 'after a PHP fatal' => true, 'no death' => false ) as $tag => $die ) {
	$sc = array( 'pages' => 4, 'per_page' => 8, 'cost' => 20, 'import_rate_limited' => array( '1004' ) );
	if ( $die ) {
		$sc['die'] = array( '1002' => array( 'how' => 'fatal', 'times' => 1 ) );
	}
	world( $sc );
	fetch();
	$scan = state()['full_started_at'];
	as_run( 'tcgiant_sync_scan_all_pages' );
	as_run( 'tcgiant_sync_scan_all_pages' );
	as_run( 'tcgiant_sync_scan_all_pages' );
	$waiting = count( pending( 'tcgiant_sync_process_item_import' ) );
	as_run( 'tcgiant_sync_process_item_import' );
	check( "[$tag] a GetItem import meets eBay's rate limit while the scan reads pages: put off, the sync still scanning, the lock held", array( logged( 'eBay API rate limit hit while importing item 1004' ), state()['status'], lock_held(), count( pending( 'tcgiant_sync_process_item_import' ) ) ), array( 1, 'scanning', true, $waiting ) );
	check( '  Fetch Inventory refused: no new scan, its imports kept', array( explode( ':', fetch() )[1] ?? '', state()['full_started_at'], count( pending( 'tcgiant_sync_process_item_import' ) ) ), array( 'scan_running', $scan, $waiting ) );
	request( 'delta' );
	check( '  the hourly sync leaves it to carry on: still the full sync, its next batch queued', array( state()['sync_mode'], count( pending( 'tcgiant_sync_scan_all_pages' ) ), logged( 'Starting delta sync' ) ), array( 'full', 1, 0 ) );
	run_by_itself();
	check( '  left to itself it completes: every listing once, its position moved, the clean-up queued', array( state()['status'], each_once( array_merge( $inline4, $queued4 ) ), state()['last_successful_sync_at'], count( pending( 'tcgiant_sync_prune_orphans' ) ) ), array( 'complete', true, $scan, 1 ) );
}

// Whatever it says, and with no lock to show, a scan is judged by its own
// signs: its progress, its next batch queued, its backstop due.
foreach ( array( 'rate_limited', 'limit_reached' ) as $status ) {
	world( array( 'pages' => 3, 'per_page' => 8, 'cost' => 20 ) );
	fetch();
	$scan = state()['full_started_at'];
	as_run( 'tcgiant_sync_scan_all_pages' );
	set_option( 'tcgiant_sync_state', array( 'status' => $status ) );
	@unlink( $GLOBALS['WORLD'] . '/tcgiant_sync.lock' );
	$answer = fetch();
	request( 'delta' );
	check( "a scan saying \"$status\" between batches, its lock gone: Fetch Inventory refused, the hourly sync leaves it", array( explode( ':', $answer )[1] ?? $answer, state()['full_started_at'], state()['sync_mode'], count( pending( 'tcgiant_sync_scan_all_pages' ) ) ), array( 'scan_running', $scan, 'full', 1 ) );	as_run( 'tcgiant_sync_scan_all_pages' );
	check( '  its next batch takes the lock again as it takes the scan', lock_held(), true );
}
// Paused on its own page read by eBay's rate limit: its retry is its
// backstop, due later. Nothing recorded for 45 minutes, and still waiting.
world( array( 'pages' => 3, 'per_page' => 8, 'rate_limit_pages' => array( 2 ) ) );
fetch();
$scan = state()['full_started_at'];
as_run( 'tcgiant_sync_scan_all_pages' );
set_option( 'tcgiant_sync_state', array( 'scan_page_at' => time() - 2700, 'last_activity_ts' => time() - 2700 ) );
set_option( 'tcgiant_sync_scan_progress', array( 'ts' => time() - 2700 ) );
$answer = fetch();
request( 'delta' );
check( 'a scan waiting out eBay\'s rate limit, quiet 45 minutes, its retry still to come: Fetch refused, pointing to Resume Import; no delta', array( explode( ':', $answer )[1] ?? $answer, false !== strpos( $answer, 'Press Resume Import' ), state()['full_started_at'], state()['sync_mode'] ), array( 'scan_waiting', true, $scan, 'full' ) );
// The same with its retry long overdue: WP-Cron is not running it. As
// before, after 30 minutes without progress it can be started again.
time_passes( 3000 );
$answer = fetch();
check( '  its retry 30 minutes overdue as well: Fetch Inventory starts it again, from page 1', array( $answer, logged( 'Starting full sync' ), state()['current_page'], state()['total_found'] ), array( 'OK', 2, 1, 0 ) );
// Emergency Stop pressed just as a batch, past the status it read, takes the
// scan's place - and with it the lock.
world( array( 'pages' => 3, 'per_page' => 8, 'stop_on_claim' => true ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
check( 'Emergency Stop pressed as a batch takes the scan: the batch stops, lets the lock go, and Fetch Inventory starts again', array( state()['status'], logged( 'Full sync scan stopped as requested' ), lock_held(), fetch() ), array( 'stopped', 1, false, 'OK' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE CLEAN-UP TRASHES NO PRODUCT WHOSE LISTING IS STILL LIVE: IT ASKS EBAY FIRST (FAILS ON 3.27.5 AND THE FIRST 3.27.6 CANDIDATE)\n" . str_repeat( '=', 140 ) . "\n";

/** GTC listings, $per to a page, one ending every $spacing s of eBay's clock (a save is 60 s of it). */
function renew_model( $count, $per, $spacing ) {
	$now      = time();
	$listings = array();
	for ( $n = 1; $n <= $count; $n++ ) {
		$listings[ sprintf( '7%04d', $n ) ] = array( 'end' => $now + 30 + $n * $spacing, 'status' => 'Active' );
	}
	return array( 'now' => $now, 'window' => 'fixed', 'per_page' => $per, 'listings' => $listings, 'saves' => 0, 'events' => array() );
}
/** GetItem calls made in requests after $after. */
function getitems_after( $after ) {
	return array_values( array_filter( lines( 'getitem' ), function ( $l ) use ( $after ) { return (int) $l > $after; } ) );
}

// The recheck's probe: GetSellerList pages by end time, and a GTC listing
// that renews while the scan runs moves to the back; the first listing still
// to be read slips onto a page already read and is never seen. Two listings
// that ended before the sync began, and one eBay no longer has, as well.
$model = renew_model( 200, 40, 600 );
$model['listings']['79001'] = array( 'end' => time() - DAY_IN_SECONDS, 'status' => 'Completed' );
$model['listings']['79002'] = array( 'end' => time() - DAY_IN_SECONDS, 'status' => 'Completed' );
$linked = array();
for ( $n = 1; $n <= 200; $n++ ) {
	$linked[ 9000 + $n ] = sprintf( '7%04d', $n );
}
$linked[9901] = '79001';
$linked[9902] = '79002';
$linked[9903] = '79003';
world( array( 'gtc_renew' => true, 'cost' => 0.5 ), array( 'model' => $model, 'linked' => $linked ) );
fetch();
run_by_itself( 400 );
$on_list = array_map( 'strval', (array) option( 'tcgiant_sync_active_ids' ) );
$missed  = array_values( array_diff( array_slice( $linked, 0, 200, true ), $on_list ) );
$before  = requests();
request( 'prune' );
$trashed = lines( 'trashed' );
sort( $trashed );
check( 'GTC listings renewing while a full sync ran: live listings missing from its pages', array( state()['scan_complete_clean'], count( $missed ) >= 10, count( lines( 'renewed' ) ) > 0 ), array( true, true, true ) );
check( '  the clean-up asks eBay first: those live listings\' products are not trashed', count( array_intersect( $trashed, array_map( 'strval', array_keys( array_intersect( $linked, $missed ) ) ) ) ), 0 );
check( '  saying so, with some of their item numbers', array( logged( count( $missed ) . ' listings missing from the scan are still live on eBay (' . implode( ', ', array_slice( $missed, 0, 5 ) ) ), logged( 'Inventory Pruning: Trashed 3 products' ) ), array( 1, 1 ) );
check( '  those that ended before the sync began, and the one eBay no longer has, trashed as before', $trashed, array( '9901', '9902', '9903' ) );
check( '  one GetItem call for each product it would have retired, and nothing left over', array( count( getitems_after( $before ) ), option( 'tcgiant_sync_prune_pending' ), option( 'tcgiant_sync_active_ids' ) ), array( count( $missed ) + 3, null, null ) );

// eBay does not answer about one, and its rate limit stops the rest: those are
// kept, and asked about again an hour on.
$linked = array();
for ( $p = 1; $p <= 5; $p++ ) {
	for ( $i = 1; $i <= 8; $i++ ) {
		$linked[ 5000 + $p * 10 + $i ] = sprintf( '%d%03d', $p, $i );
	}
}
foreach ( array( 6001, 6002, 6003, 6004, 6005 ) as $n => $pid ) {
	$linked[ $pid ] = '99000000000' . ( $n + 1 );
}
$clean_sync = array( 'status' => 'complete', 'sync_mode' => 'full', 'scan_complete_clean' => true, 'full_started_at' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 3600 ), 'last_successful_sync_at' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 3600 ) );
$clean_sync['scan_finished'] = $clean_sync['full_started_at'];
world(
	array( 'pages' => 5, 'per_page' => 8, 'getitem_fails' => array( '990000000002' => 'http_request_failed', '990000000004' => 'rate_limited' ), 'getitem_status' => array( '990000000003' => 'Active' ) ),
	array( 'linked' => $linked, 'options' => array( 'tcgiant_sync_state' => $clean_sync, 'tcgiant_sync_active_ids' => array_values( array_slice( $linked, 0, 40 ) ) ) )
);
request( 'prune' );
$pending_prune = (array) option( 'tcgiant_sync_prune_pending' );
check( 'one gone, one eBay will not answer about, one live, then eBay\'s rate limit: only the gone one trashed', array( lines( 'trashed' ), count( getitems_after( 0 ) ) ), array( array( '6001' ), 4 ) );
check( '  the live one kept and put on the list of live listings', in_array( '990000000003', (array) option( 'tcgiant_sync_active_ids' ), true ), true );
check( '  the three not confirmed kept for now, for this sync, to be asked about again an hour on', array( $pending_prune['scan'] ?? null, $pending_prune['rows'] ?? null, count( array_filter( pending( 'tcgiant_sync_prune_orphans' ), function ( $a ) { return ! empty( $a['args']['follow_up'] ); } ) ), logged( '3 products kept for now, their listings not yet confirmed as ended on eBay (eBay\'s call limit, or the day\'s API budget, was reached). They are checked again in an hour.' ) ), array( $clean_sync['full_started_at'], array( 6002 => '990000000002', 6004 => '990000000004', 6005 => '990000000005' ), 1, 1 ) );
// An hour on eBay answers; meanwhile 6005 was linked to another listing by hand.
ws( 'scenario', array( 'pages' => 5, 'per_page' => 8 ) );
$relinked         = wl( 'linked', array() );
$relinked[6005]   = '2001';
ws( 'linked', $relinked );
$before = requests();
request( 'prune_follow_up' );
check( 'the follow-up run: the two eBay no longer has trashed; the one linked since left alone', array( lines( 'trashed' ), count( getitems_after( $before ) ) ), array( array( '6001', '6002', '6004' ), 2 ) );
check( '  and nothing left waiting', array( option( 'tcgiant_sync_prune_pending' ), option( 'tcgiant_sync_active_ids' ), logged( 'checking again what an earlier run left (follow-up 1 of at most 24); 2 listings checked with eBay' ) ), array( null, null, 1 ) );

// No more than 300 GetItem calls a run: 310 to retire among 1,710 products.
$linked = array();
$active = array();
for ( $p = 1; $p <= 7; $p++ ) {
	for ( $i = 1; $i <= 200; $i++ ) {
		$id                         = sprintf( '%d%03d', $p, $i );
		$linked[ 10000 + count( $linked ) ] = $id;
		$active[]                   = $id;
	}
}
for ( $n = 1; $n <= 310; $n++ ) {
	$linked[ 20000 + $n ] = sprintf( '99%010d', $n );
}
world( array( 'pages' => 7, 'per_page' => 200 ), array( 'linked' => $linked, 'options' => array( 'tcgiant_sync_state' => $clean_sync, 'tcgiant_sync_active_ids' => $active ) ) );
request( 'prune' );
check( '310 products to retire: 300 asked about and trashed, the other 10 kept for the next run', array( count( lines( 'trashed' ) ), count( getitems_after( 0 ) ), count( (array) ( option( 'tcgiant_sync_prune_pending' )['rows'] ?? array() ) ), logged( '10 products kept for now, their listings not yet confirmed as ended on eBay (eBay is asked about 300 a run). They are checked again in an hour.' ) ), array( 300, 300, 10, 1 ) );
$before = requests();
request( 'prune_follow_up' );
check( '  the follow-up run asks about those 10, and trashes them', array( count( lines( 'trashed' ) ), count( getitems_after( $before ) ), option( 'tcgiant_sync_prune_pending' ) ), array( 310, 10, null ) );

// What a run left waiting is decided afresh by the next full sync: its own
// clean-up, even one skipped, and its start.
$left_over = array( 'rows' => array( 6001 => '990000000001' ), 'round' => 0, 'scan' => $clean_sync['full_started_at'] );
world( array( 'pages' => 1, 'per_page' => 8 ), array( 'linked' => array( 6001 => '990000000001' ), 'options' => array( 'tcgiant_sync_state' => array_merge( $clean_sync, array( 'scan_complete_clean' => false ) ), 'tcgiant_sync_prune_pending' => $left_over ) ) );
request( 'prune' );
request( 'prune_follow_up' );
check( 'a full sync\'s clean-up, skipped, takes what an earlier run left with it: the follow-up finds nothing to do', array( option( 'tcgiant_sync_prune_pending' ), lines( 'trashed' ), count( getitems_after( 0 ) ) ), array( null, array(), 0 ) );
world( array( 'pages' => 1, 'per_page' => 8 ), array( 'options' => array( 'tcgiant_sync_state' => $clean_sync, 'tcgiant_sync_prune_pending' => $left_over ) ) );
fetch();
check( '  and so does Fetch Inventory', option( 'tcgiant_sync_prune_pending' ), null );

// Round 2's probe: a follow-up still queued when the next full sync begins.
// Run during that sync, it deleted the new scan's list of live listings, and
// the new sync's clean-up gave up over the 20% guard.
$linked = array();
for ( $p = 1; $p <= 4; $p++ ) {
	for ( $i = 1; $i <= 8; $i++ ) {
		$linked[ 5000 + $p * 100 + $i ] = sprintf( '%d%03d', $p, $i );
	}
}
$linked[6999] = '9999';
world( array( 'pages' => 4, 'per_page' => 8, 'cost' => 20, 'getitem_fails' => array( '9999' => 'http_request_failed' ) ), array( 'linked' => $linked ) );
fetch();
run_by_itself( 200 );
ws( 'queue', array_values( array_filter( wl( 'queue', array() ), function ( $a ) { return 'tcgiant_sync_prune_orphans' !== $a['hook']; } ) ) );
request( 'prune' );
$first = state()['full_started_at'];
check( 'a clean-up that could not ask eBay about one listing: kept for a follow-up an hour on, for that sync', array( ( option( 'tcgiant_sync_prune_pending' ) ?? array() )['scan'] ?? null, count( pending( 'tcgiant_sync_prune_orphans' ) ) ), array( $first, 1 ) );
fetch();
check( '  Fetch Inventory within the hour: the follow-up goes with the list it would act on', array( count( pending( 'tcgiant_sync_prune_orphans' ) ), option( 'tcgiant_sync_prune_pending' ) ), array( 0, null ) );
as_run( 'tcgiant_sync_scan_all_pages' );
as_run( 'tcgiant_sync_scan_all_pages' );
as_run( 'tcgiant_sync_scan_all_pages' );
$live_so_far = count( (array) option( 'tcgiant_sync_active_ids' ) );
// One that ran all the same - queued before 3.27.6's fix, say - with the old
// sync's list still there, and again with nothing at all.
set_option( 'tcgiant_sync_prune_pending', array( 'rows' => array( 6999 => '9999' ), 'round' => 0, 'scan' => $first ) );
$before = requests();
request( 'prune_follow_up' );
check( '  a follow-up for the last sync, run during the new one: drops what it had, asks eBay nothing, leaves the new list', array( option( 'tcgiant_sync_prune_pending' ), count( getitems_after( $before ) ), count( (array) option( 'tcgiant_sync_active_ids' ) ), count( pending( 'tcgiant_sync_prune_orphans' ) ) ), array( null, 0, $live_so_far, 0 ) );
request( 'prune_follow_up' );
check( '  one with nothing waiting leaves the list alone too', count( (array) option( 'tcgiant_sync_active_ids' ) ), $live_so_far );
run_by_itself( 300 );
check( '  the new sync completes with every live listing on its list', array( state()['status'], state()['scan_complete_clean'], count( (array) option( 'tcgiant_sync_active_ids' ) ) ), array( 'complete', true, 32 ) );
ws( 'queue', array_values( array_filter( wl( 'queue', array() ), function ( $a ) { return 'tcgiant_sync_prune_orphans' !== $a['hook']; } ) ) );
request( 'prune' );
check( '  and its clean-up runs as it should: no abort over the 20% guard, nothing trashed', array( logged( 'ABORTED' ), lines( 'trashed' ) ), array( 0, array() ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT A SCAN COSTS IN PAGE READS: A CAP ONLY FROM KILLS AT ABOUT THE SAME TIME, RISING AGAIN; FIFTY LISTINGS A READ (FAILS ON THE FIRST 3.27.6 CANDIDATE)\n" . str_repeat( '=', 140 ) . "\n";

// One kill from outside, 12 s in - an FPM reload, the out-of-memory killer -
// on a host that otherwise allows the full budget: no cap.
world( array( 'pages' => 2, 'per_page' => 40, 'read_cost' => 3, 'cost' => 0.3, 'die' => array( '1031' => array( 'how' => 'hard', 'times' => 1 ) ) ) );
fetch();
run_by_itself();
check( 'one kill 12 s in, on a host that allows more: no cap, each batch its full budget, three page reads', array( count( lines( 'died' ) ), logged( 'from now on each batch hands on by' ), count( lines( 'reads' ) ), state()['status'] ), array( 1, 0, 3, 'complete' ) );
// Every request ended from outside at 30 s: the second kill teaches it.
world( array( 'pages' => 2, 'per_page' => 200, 'read_cost' => 3, 'cost' => 0.5, 'kill_at' => 30 ) );
fetch();
run_by_itself( 300 );
check( 'every request ended at 30 s: two kills, then each batch hands on by 23 s', array( count( lines( 'died' ) ), logged( 'so from now on each batch hands on by 23 s' ), state()['status'], each_once( array_merge( ...shop_ids( 2, 200 ) ) ) ), array( 2, 1, 'complete', true ) );
check( '  nine page reads for 400 listings', count( lines( 'reads' ) ), 9 );
// The same, a page taking 26 s to read: never less than a read and three listings.
world( array( 'pages' => 2, 'per_page' => 40, 'read_cost' => 26, 'cost' => 0.5, 'kill_at' => 30 ) );
fetch();
run_by_itself( 300 );
check( '  a page read taking 26 s of the 30: the cap is that and three listings, 28 s, never 23', array( logged( 'so from now on each batch hands on by 28 s' ) >= 1, logged( 'so from now on each batch hands on by 23 s' ), state()['status'], each_once( array_merge( ...shop_ids( 2, 40 ) ) ) ), array( true, 0, 'complete', true ) );
// A cap that clean batches show to be too low rises again.
world( array( 'pages' => 2, 'per_page' => 100, 'cost' => 1 ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
set_option( 'tcgiant_sync_scan_progress', array( 'cap' => 12 ) );
run_by_itself();
check( 'a cap of 12 s: after five batches that hand on cleanly, it rises to 15 s', array( logged( 'Full sync scan: 5 batches in a row have handed on in good time, so each now hands on by 15 s rather than 12 s.' ), count( array_filter( saves(), function ( $s ) { return 3 === (int) $s[0]; } ) ) ), array( 1, 12 ) );
world( array( 'pages' => 3, 'per_page' => 200, 'cost' => 1 ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
set_option( 'tcgiant_sync_scan_progress', array( 'cap' => 50 ) );
run_by_itself( 400 );
check( '  one of 50 s: past the time budget, it goes, and batches have their full minute again', array( logged( 'so the batches go back to the usual time budget' ), state()['status'] ), array( 1, 'complete' ) );
// A host whose PHP limit is 30 s, set_time_limit() switched off: the budget is
// 15 s, and a page takes 14 to read. A batch works through fifty listings of
// the page it read, or up to 24 s, before it hands on.
$host30 = '-d max_execution_time=30 -d disable_functions=set_time_limit';
world( array( 'pages' => 2, 'per_page' => 40, 'read_cost' => 14, 'cost' => 0.5, 'kill_at' => 30, 'php_flags' => $host30 ) );
fetch();
run_by_itself( 300 );
check( 'a 30 s host, a page read taking 14 s of its 15 s budget: about 20 listings a read, not 2, and none past 24 s', array( count( lines( 'reads' ) ) <= 6, count( lines( 'died' ) ), state()['status'], each_once( array_merge( ...shop_ids( 2, 40 ) ) ) ), array( true, 0, 'complete', true ) );
// Memory ends a batch all the same, however few listings it has done.
world( array( 'pages' => 1, 'per_page' => 40, 'read_cost' => 16, 'cost' => 0.5, 'memory_after' => 2, 'php_flags' => $host30 ) );
fetch();
as_run( 'tcgiant_sync_scan_all_pages' );
check( '  memory ends a batch two listings in all the same', array( count( saved_ids() ), logged( '90% of the memory limit in use' ) ), array( 2, 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA LISTING WHOSE SAVE OUTLIVES THE BACKSTOP IS NOT SAVED TWICE, NOR TAKEN FOR A DEATH (FAILS ON THE FIRST 3.27.6 CANDIDATE)\n" . str_repeat( '=', 140 ) . "\n";

list( $inline2, $queued2 ) = shop_ids( 2, 8 );
world( array( 'pages' => 2, 'per_page' => 8, 'on_save' => array( '1003' => 'slow_then_backstop' ) ) );
fetch();
run_by_itself();
check( 'a save of 1003 past four minutes: the backstop finds its batch at work, leaves it', array( count( array_keys( saved_ids(), '1003', true ) ), logged( 'Trying it again' ), logged( 'another batch of it is still at work' ), state()['status'], each_once( array_merge( $inline2, $queued2 ) ) ), array( 1, 0, 1, 'complete', true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SCREENS ON A SYNC 3.27.5 BEGAN COUNT ONLY THE QUEUED IMPORTS DONE (FAILS ON THE FIRST 3.27.6 CANDIDATE)\n" . str_repeat( '=', 140 ) . "\n";

$queue = array();
foreach ( array( '1004', '1008', '2004', '2008', '3004', '3008' ) as $n => $item ) {
	$queue[] = array( 'id' => $n + 1, 'hook' => 'tcgiant_sync_process_item_import', 'args' => array( 'item_id' => $item ), 'group' => 'tcgiant_sync_imports', 'status' => 'pending', 'priority' => 10 );
}
world( array( 'pages' => 3, 'per_page' => 8 ), array( 'queue' => $queue, 'next_action' => 7, 'options' => array( 'tcgiant_sync_state' => array( 'status' => 'importing', 'sync_mode' => 'full', 'full_started_at' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 600 ), 'current_page' => 3, 'total_pages' => 3, 'total_found' => 24, 'total_queued' => 6, 'total_processed' => 18, 'total_errors' => 0, 'last_successful_sync_at' => '2026-10-01T00:00:00.000Z' ) ) ) );
$screen = (array) json_decode( request( 'screen' )[1], true );
check( 'a sync 3.27.5 began, its six imports all still queued: 0/6, not 6/6 (100%)', array( $screen['queued_done'] ?? null, $screen['queued_pct'] ?? null ), array( 0, 0 ) );
as_run( 'tcgiant_sync_process_item_import' );
as_run( 'tcgiant_sync_process_item_import' );
$screen = (array) json_decode( request( 'screen' )[1], true );
check( '  two of them run: 2/6', array( $screen['queued_done'] ?? null, $screen['queued_pct'] ?? null ), array( 2, 33 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SCREENS AND THE CODE AROUND THEM\n" . str_repeat( '=', 140 ) . "\n";

$importer  = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-importer.php' );
$import    = (string) file_get_contents( $ROOT . '/admin/views/import.php' );
$dashboard = (string) file_get_contents( $ROOT . '/admin/views/dashboard.php' );
$js        = (string) file_get_contents( $ROOT . '/admin/assets/js/admin.js' );
$review    = (string) file_get_contents( $ROOT . '/admin/views/stock-review.php' );
$uninstall = (string) file_get_contents( $ROOT . '/uninstall.php' );

check( 'the scan is watched with a death handler', false !== strpos( $importer, "TCGiant_Sync_Runtime::watch( self::SCAN_JOB, array( __CLASS__, 'on_scan_death' ) );" ), true );
check( 'Dashboard "Total Imported" and the Pro "Import Usage" have ids the poll fills in', array( false !== strpos( $dashboard, 'id="tc-total-imported"' ), false !== strpos( $import, 'id="tc-usage-pro-count"' ), false !== strpos( $js, "$('#tc-total-imported').text(st.synced_products);" ), false !== strpos( $js, "$('#tc-usage-pro-count').text(lic.active_count);" ) ), array( true, true, true, true ) );
check( 'Resume Import on both screens, shown and hidden by the poll', array( false !== strpos( $import, 'id="tc-resume-card"' ), false !== strpos( $dashboard, 'id="tc-resume-form"' ), false !== strpos( $js, "$('#tc-resume-card, #tc-resume-form').toggle(!!(p.can_resume && s.status !== 'rate_limited'));" ) ), array( true, true, true ) );
check( 'no screen measures every import against the queued ones', array( substr_count( $import . $dashboard, "esc_html( \$sync_state['total_processed'] ) . '/' . esc_html( \$sync_state['total_queued'] )" ), substr_count( $import . $dashboard, "( \$sync_state['total_processed'] + \$sync_state['total_errors'] ) / \$sync_state['total_queued']" ), preg_match( '/s\.total_processed \+ s\.total_errors\) \/ s\.total_queued/', $js ), substr_count( $js, "s.total_processed + '/' + s.total_queued" ) ), array( 0, 0, 0, 0 ) );
check( 'the bars use the queued imports\' own share', array( false !== strpos( $import, "\$progress_pct = \$tc_screen['queued_pct'];" ), false !== strpos( $dashboard, "\$dash_pct = \$tc_screen['queued_pct'];" ), false !== strpos( $js, 'var pct = p.queued_pct || 0;' ) ), array( true, true, true ) );
check( 'Stock Review says the list fills in as a full sync reads the listings', false !== strpos( $review, 'This list fills in as a full sync reads your eBay listings and records which site each one is on' ), true );
check( 'deleting the plugin with its data deletes the scan\'s place, and what the clean-up has still to ask eBay', array( false !== strpos( $uninstall, "'tcgiant_sync_scan_progress'," ), false !== strpos( $uninstall, "'tcgiant_sync_scan_page'," ), false !== strpos( $uninstall, "'tcgiant_sync_prune_pending'," ) ), array( true, true, true ) );

// ─────────────────────────────────────────────────────────────────────────────

/** The played shops go with the run. */
function harness_remove( $dir ) {
	foreach ( (array) glob( $dir . '/*' ) as $path ) {
		is_dir( $path ) ? harness_remove( $path ) : @unlink( $path );
	}
	@rmdir( $dir );
}
harness_remove( $BASE );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
