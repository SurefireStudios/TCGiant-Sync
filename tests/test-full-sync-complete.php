<?php
/**
 * Harness: a full sync completes (3.30.0, the audit's item A).
 *
 * Until 3.30.0 a full sync was completed only by its last queued GetItem
 * import. One whose every listing was imported as the scan read the pages -
 * most of them - said "importing" until the dashboard's poll marked it
 * complete, and neither moved the delta sync's position nor queued the
 * clean-up (delta-state_3, admin-ui_5): the pruner never ran on those shops,
 * and a new shop's first full sync never handed on to the delta - the hourly
 * sync started a full sync again instead. Under "limit_reached" or
 * "rate_limited" in its import phase it was never completed at all, and the
 * hourly sync replaced a rate-limited one, cancelling the import eBay had put
 * off (delta-state_6). The weekly full sync was refused, week after week,
 * by a delta that had died saying "scanning" (delta-state_4).
 *
 * Now one method completes a sync, TCGiant_Sync_Importer::complete_sync(),
 * called by everything that can finish one, and it completes a full sync
 * once however many finish it together.
 *
 * Loads the real importer, inventory, sites and runtime classes; the
 * pruner's SQL runs on SQLite. See support/prune-world.php. Options are kept
 * as WordPress keeps them: the table, and each request's own copy.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/prune-world.php';

/**
 * The database, with a seam for a second request to run between this one
 * reading which full sync last completed and its compare-and-swap of it.
 */
class Racing_Wpdb extends Harness_Sqlite_Wpdb {
	/** Runs once, just before the next write of the completion's claim. */
	public $before_claim = null;
	/** Runs once, just after it. */
	public $after_claim = null;
	/** Runs once, as finalize_scan() reads the state to write it (3.30.0 fix round 1). */
	public $on_finalize_write = null;
	public function get_var( $q ) {
		if ( null !== $this->on_finalize_write && false !== strpos( (string) $q, "'tcgiant_sync_state'" ) ) {
			$fns = array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'function' );
			if ( in_array( 'finalize_scan', $fns, true ) && in_array( 'update_sync_state', $fns, true ) ) {
				$run                     = $this->on_finalize_write;
				$this->on_finalize_write = null;
				$run();
			}
		}
		return parent::get_var( $q );
	}
	public function query( $q ) {
		$claim = preg_match( '#^/\*option-write:(\d+)\*/$#', $q, $m )
			&& in_array( 'tcgiant_sync_full_completed', (array) $this->option_writes[ (int) $m[1] ][1], true );
		if ( $claim && null !== $this->before_claim ) {
			$run                = $this->before_claim;
			$this->before_claim = null;
			$run();
		}
		$took = parent::query( $q );
		if ( $claim && null !== $this->after_claim ) {
			$run               = $this->after_claim;
			$this->after_claim = null;
			$run();
		}
		return $took;
	}
}
$wpdb = new Racing_Wpdb();

TCGiant_Sync_Importer::$page_pause = 0;

const P0 = '2026-10-01T00:00:00.000Z';

/** A listing on a page: complete enough to import inline, or (bare) to be queued for GetItem. */
function row( $item_id, $bare = false, $status = 'Active' ) {
	$row = array(
		'ItemID' => (string) $item_id, 'Title' => 'Card ' . $item_id, 'Quantity' => '1',
		'SellingStatus' => array( 'ListingStatus' => $status, 'QuantitySold' => '0' ),
		'PictureDetails' => array( 'PictureURL' => 'https://i.ebayimg.com/x.jpg' ),
		'ListingDetails' => array( 'EndTime' => '2026-12-13T09:00:00.000Z' ),
	);
	if ( ! $bare ) {
		$row['ItemSpecifics'] = array( 'NameValueList' => array() );
	}
	// What GetItem says of it, for an import queued for it.
	listing( (string) $item_id, $status, 1, 0 );
	return $row;
}
/** eBay's pages: $pages[n] = rows. $on_page[n] runs when page n is asked for. */
function ebay_pages( array $pages, array $on_page = array() ) {
	$GLOBALS['EBAY']['lister'] = function ( $page, $per, $mod_from, $end_from ) use ( $pages, $on_page ) {
		if ( isset( $on_page[ $page ] ) ) { call_user_func( $on_page[ $page ] ); }
		$rows = $pages[ $page ] ?? array();
		return $rows ? array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $rows ), 'PaginationResult' => array( 'TotalNumberOfPages' => count( $pages ) ) ) : array( 'Ack' => 'Success' );
	};
}
function lock_file() { return sys_get_temp_dir() . '/tcgiant_sync.lock'; }
/** A shop: no sync running, the delta's position P0 (none at all for a new one). */
function shop( array $state = array(), array $settings = array(), $new = false ) {
	fresh_world( $settings );
	@unlink( lock_file() );
	$GLOBALS['RUNNING']    = array();
	$GLOBALS['CAN_IMPORT'] = true;
	// A new request each: the free limit is said once a request.
	$said = new ReflectionProperty( 'TCGiant_Sync_Importer', 'limit_reported' );
	$said->setAccessible( true );
	$said->setValue( null, false );
	$base = TCGiant_Sync_Importer::get_sync_state();
	if ( ! $new ) {
		$base['last_successful_sync_at'] = P0;
	}
	update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( $base, $state ) );
}
/** Fetch Inventory, and Action Scheduler running the scan's batches. */
function full_sync() {
	@unlink( lock_file() );
	$r = $GLOBALS['IMP']->start_full_sync( true );
	run_queue( 'tcgiant_sync_scan_all_pages' );
	return $r;
}
function state( $k ) { $s = $GLOBALS['TABLE'][ TCGiant_Sync_Importer::STATE_OPTION ] ?? array(); return $s[ $k ] ?? null; }
function set_state( array $u ) { update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( (array) get_option( TCGiant_Sync_Importer::STATE_OPTION, array() ), $u ) ); }
function prunes() { return queued( 'tcgiant_sync_prune_orphans' ); }
function said( $needle ) { $n = 0; foreach ( $GLOBALS['LOG'] as $l ) { if ( false !== stripos( $l[1], $needle ) ) { $n++; } } return $n; }
function claim() { return $GLOBALS['TABLE'][ 'tcgiant_sync_full_completed' ] ?? null; }
/** The sync quiet for $seconds: its last write, and its scan's place, that long ago. */
function quiet_for( $seconds ) {
	$s = $GLOBALS['TABLE'][ TCGiant_Sync_Importer::STATE_OPTION ];
	$s['last_activity_ts'] = time() - $seconds;
	$s['scan_page_at']     = time() - $seconds;
	update_option( TCGiant_Sync_Importer::STATE_OPTION, $s );
	delete_option( TCGiant_Sync_Importer::SCAN_PROGRESS_OPTION );
}
function complete_by( $who ) { return method_exists( 'TCGiant_Sync_Importer', 'complete_sync' ) ? TCGiant_Sync_Importer::complete_sync( $who ) : null; } // None before 3.30.0.
/** Action Scheduler runs the next action of $hook, in a request of its own. */
function run_one( $hook ) {
	foreach ( $GLOBALS['QUEUE'] as $i => $a ) {
		if ( $a['hook'] === $hook ) {
			array_splice( $GLOBALS['QUEUE'], $i, 1 );
			new_request();
			foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $h ) {
				call_user_func_array( $h[0], array_slice( array_values( (array) $a['args'] ), 0, $h[1] ) );
			}
			return true;
		}
	}
	return false;
}
/** Live products for the 20% guard, each on a page row. */
function filler( $n, $from = 5000 ) {
	$rows = array();
	for ( $i = 0; $i < $n; $i++ ) {
		product( $from + $i, (string) ( 900000 + $from + $i ), 1 );
		$rows[] = row( 900000 + $from + $i );
	}
	return $rows;
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEVERY LISTING IMPORTED AS THE SCAN READ IT: COMPLETE, FROM THE SCAN'S START, ONE CLEAN-UP\n" . str_repeat( '=', 140 ) . "\n";

shop();
$rows = filler( 20 );
// A product whose listing sold out on eBay, unseen: the clean-up's to settle.
product( 61, '6100', 1 );
listing( '6100', 'Completed', 1, 1 );
ebay_pages( array( 1 => array_slice( $rows, 0, 10 ), 2 => array_slice( $rows, 10 ) ) );
check( 'Fetch Inventory: two pages, every listing imported as it was read', array( full_sync(), state( 'total_processed' ), state( 'total_queued' ) ), array( true, 20, 0 ) );
$began = state( 'full_started_at' );
check( '  complete as its last page is read', state( 'status' ), 'complete' );
check( '  the delta sync carries on from when it began, not from now', state( 'last_successful_sync_at' ), $began );
check( '  the clean-up queued, once', prunes(), 1 );
check( '  its completion recorded, by the scan it was', claim(), $began );
check( '  last_completed written', '' !== (string) state( 'last_completed' ), true );
check( '  and the log says so, not that pruning did not start', array( said( 'Sync complete!' ), said( 'Inventory pruning: not started' ), said( 'Inventory pruning: queued' ) ), array( 1, 0, 1 ) );
run_queue( 'tcgiant_sync_prune_orphans' );
check( 'the clean-up runs: the sold-out listing is settled, nothing live asked about', array( meta( 61, '_ebay_listing_status' ), stock( 61 ), $EBAY['get_items'] ), array( 'Ended', 0, array( '6100' ) ) );
check( '  nothing else changed: the 20 live products in stock', array_sum( array_map( 'stock', range( 5000, 5019 ) ) ), 20 );
// The hourly sync then starts the delta from there: the clean-up has run.
$IMP->start_delta_sync();
check( 'the hourly sync then runs a delta, from five minutes before the full sync began', array( state( 'sync_mode' ), state( 'delta_mod_from' ) ), array( 'delta', gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( $began ) - 300 ) ) );

// A new shop's first full sync hands on to the delta.
shop( array(), array(), true );
ebay_pages( array( 1 => array( row( '7001' ), row( '7002' ) ) ) );
full_sync();
$began = state( 'full_started_at' );
check( 'a new shop\'s first full sync: complete, with a position to hand on from', array( state( 'status' ), state( 'last_successful_sync_at' ) ), array( 'complete', $began ) );
$LOG = array();
$IMP->start_delta_sync();
check( '  the hourly sync waits while its clean-up is queued: no delta, no full sync, and it says why', array( said( 'Delta sync not started yet' ), said( 'Starting delta sync' ), said( 'No previous sync timestamp found' ), state( 'sync_mode' ), prunes() ), array( 1, 0, 0, 'full', 1 ) );
run_queue( 'tcgiant_sync_prune_orphans' );
$LOG = array();
$IMP->start_delta_sync();
check( '  once the clean-up has run, it starts a delta, not another full sync', array( said( 'Starting delta sync' ), said( 'No previous sync timestamp found' ), state( 'sync_mode' ), state( 'delta_mod_from' ) ), array( 1, 0, 'delta', gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( $began ) - 300 ) ) );

// A scan that found nothing at all completes as it always did.
shop();
ebay_pages( array() );
full_sync();
check( 'a scan that finds no listing: complete, its position moved, the clean-up queued', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes(), said( 'No matching items found' ) ), array( 'complete', state( 'full_started_at' ), 1, 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA FULL SYNC THAT QUEUED IMPORTS COMPLETES WHEN THE LAST HAS RUN\n" . str_repeat( '=', 140 ) . "\n";

shop();
ebay_pages( array( 1 => array( row( '8001' ), row( '8002', true ), row( '8003', true ) ) ) );
full_sync();
$began = state( 'full_started_at' );
check( 'the scan done, two GetItem imports queued: importing, no position, no clean-up', array( state( 'status' ), queued( 'tcgiant_sync_process_item_import' ), state( 'last_successful_sync_at' ), prunes(), claim() ), array( 'importing', 2, P0, 0, null ) );
run_one( 'tcgiant_sync_process_item_import' );
check( '  the first import runs: still importing', array( state( 'status' ), prunes() ), array( 'importing', 0 ) );
run_one( 'tcgiant_sync_process_item_import' );
check( '  the last: complete, from the scan\'s start, one clean-up', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes(), claim() ), array( 'complete', $began, 1, $began ) );

// The imports it queued all ran while the scan read on: nothing is left to finish it, so the scan does.
shop();
ebay_pages(
	array( 1 => array( row( '8101', true ), row( '8102' ) ), 2 => array( row( '8103' ) ) ),
	array( 2 => function () { run_queue( 'tcgiant_sync_process_item_import' ); } )
);
full_sync();
check( 'every queued import ran during the scan: complete as its last page is read', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', state( 'full_started_at' ), 1 ) );
check( '  the import that ran mid-scan did not complete it early', said( 'Sync complete!' ), 1 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA FULL SYNC WHOSE LIST OF LIVE LISTINGS IS NOT KNOWN TO BE WHOLE COMPLETES WITH NO CLEAN-UP\n" . str_repeat( '=', 140 ) . "\n";

// The database would not keep its list of live listings in full.
shop();
ebay_pages( array( 1 => array( row( '8201' ) ), 2 => array( row( '8202' ) ) ), array( 2 => function () {
	set_state( array( 'scan_ids_lost' => state( 'full_started_at' ) ) );
} ) );
full_sync();
check( 'its list of live listings not saved in full: complete, position moved', array( state( 'status' ), state( 'last_successful_sync_at' ) ), array( 'complete', state( 'full_started_at' ) ) );
check( '  but no clean-up, and the log says why', array( prunes(), (bool) state( 'scan_complete_clean' ), said( 'will not run after this full sync' ) ), array( 0, false, 1 ) );

// The completion checks it again: a state saying clean, but with its list lost.
last_import_waiting();
set_state( array( 'scan_ids_lost' => state( 'full_started_at' ) ) );
run_queue( 'tcgiant_sync_process_item_import' );
check( 'clean by its flag, but its list of live listings lost: complete, no clean-up', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', state( 'full_started_at' ), 0 ) );

// Items synced by hand joined it while it imported.
shop();
ebay_pages( array( 1 => array( row( '8301' ), row( '8302', true ) ) ) );
full_sync();
$IMP->start_specific_sync( array( '8303' ) );
listing( '8303', 'Active', 1, 0 );
run_queue( 'tcgiant_sync_process_item_import' );
check( 'items synced by hand joined it: complete as a full sync, position moved, no clean-up', array( state( 'status' ), state( 'sync_mode' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', 'full', state( 'full_started_at' ), 0 ) );
check( '  and the log says the clean-up does not run', said( 'Inventory pruning: not run after this full sync' ), 1 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTWO FINISHERS AT ONCE COMPLETE IT ONCE\n" . str_repeat( '=', 140 ) . "\n";

/** A full sync whose scan is done, with one import, 9002, still queued. */
function last_import_waiting( $older_claim = null ) {
	shop();
	if ( null !== $older_claim ) {
		update_option( 'tcgiant_sync_full_completed', $older_claim );
	}
	ebay_pages( array( 1 => array( row( '9001' ), row( '9002', true ) ) ) );
	full_sync();
}

// Its last import ends as Resume Import is pressed: Resume gets in between
// the import reading which full sync last completed and its claim.
foreach ( array( 'first ever' => null, 'after an earlier one' => '2026-09-01T00:00:00.000Z' ) as $when => $older ) {
	last_import_waiting( $older );
	$began = state( 'full_started_at' );
	$wpdb->before_claim = function () {
		complete_by( 'resume' );
	};
	run_queue( 'tcgiant_sync_process_item_import' );
	check( sprintf( 'the last import and Resume Import finish it together (%s): Resume came between', $when ), $wpdb->before_claim, null );
	check( '  complete', array( state( 'status' ), state( 'last_successful_sync_at' ), claim() ), array( 'complete', $began, $began ) );
	check( '  one clean-up, one "complete" in the log', array( prunes(), said( 'Sync complete!' ) ), array( 1, 1 ) );
}

// Two imports end together, each the last as it sees it.
last_import_waiting();
$began = state( 'full_started_at' );
$QUEUE = array();
$wpdb->before_claim = function () { TCGiant_Sync_Importer::instance()->process_item_import( '9001' ); };
$IMP->process_item_import( '9002' );
check( 'two imports finishing together: the second came between', array( $wpdb->before_claim, in_array( '9001', $EBAY['get_items'], true ) ), array( null, true ) );
check( '  complete once, one clean-up', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes(), said( 'Sync complete!' ) ), array( 'complete', $began, 1, 1 ) );

// The other way round: the import claims it, and Resume comes between its
// claim and its write - finds it claimed, and the sync still importing.
last_import_waiting();
$began = state( 'full_started_at' );
$wpdb->after_claim = function () {
	$GLOBALS['RESUME_WROTE'] = complete_by( 'resume' );
};
run_queue( 'tcgiant_sync_process_item_import' );
check( 'the import claims it first, Resume comes before its write: it puts back, queues nothing', array( $wpdb->after_claim, $RESUME_WROTE ), array( null, true ) );
check( '  and the import still completes it: one clean-up, one "complete"', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes(), said( 'Sync complete!' ) ), array( 'complete', $began, 1, 1 ) );

// A newer full sync started in another request as the last import of the old
// one claims the completion: the new one is left alone.
last_import_waiting();
$wpdb->before_claim = function () {
	elsewhere( TCGiant_Sync_Importer::STATE_OPTION, array_merge( $GLOBALS['TABLE'][ TCGiant_Sync_Importer::STATE_OPTION ], array( 'status' => 'scanning', 'full_started_at' => '2099-01-01T00:00:00.000Z', 'scan_complete_clean' => false ) ) );
};
run_queue( 'tcgiant_sync_process_item_import' );
check( 'a newer full sync started as the old one\'s last import completes it: the new one scans on', array( $wpdb->before_claim, state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( null, 'scanning', P0, 0 ) );

// A request that read the state before the sync completed, and writes its
// copy back after: the status and the position are not put back.
last_import_waiting();
$began  = state( 'full_started_at' );
$before = $OPTIONS;                       // That request's copy, from before.
run_queue( 'tcgiant_sync_process_item_import' );
check( 'complete, one clean-up', array( state( 'status' ), prunes() ), array( 'complete', 1 ) );
$OPTIONS = $before;                       // It ends its own import now.
$IMP->process_item_import( '9001' );
check( 'an import from a request with a copy from before it completed: still complete, the position kept', array( state( 'status' ), state( 'last_successful_sync_at' ) ), array( 'complete', $began ) );
check( '  its count added to the table\'s, not to its old copy', state( 'total_processed' ), 3 );
check( '  and no second clean-up', array( prunes(), said( 'Sync complete!' ) ), array( 1, 1 ) );
// One that writes its old copy back whole, status and all: the next finisher puts it right.
set_state( array( 'status' => 'importing', 'last_successful_sync_at' => P0 ) );
quiet_for( 600 );
check( 'a full sync put back to "importing" after it completed: the dashboard puts it back', array( complete_by( 'dashboard' ), state( 'status' ), state( 'last_successful_sync_at' ) ), array( true, 'complete', $began ) );
check( '  with no second clean-up, and nothing more in the log', array( prunes(), said( 'Sync complete!' ) ), array( 1, 1 ) );
// A clean-up already waiting is not queued a second time.
last_import_waiting();
as_enqueue_async_action( 'tcgiant_sync_prune_orphans', array(), 'tcgiant_sync_group' );
run_queue( 'tcgiant_sync_process_item_import' );
check( 'a clean-up already waiting: not queued again', prunes(), 1 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA DELTA, AND ITEMS SYNCED BY HAND, ARE NOT COMPLETED AS A FULL SYNC\n" . str_repeat( '=', 140 ) . "\n";

shop( array( 'status' => 'scanning', 'sync_mode' => 'delta', 'delta_mod_from' => '2026-10-02T00:00:00.000Z', 'scan_complete_clean' => true, 'full_started_at' => '2026-09-30T00:00:00.000Z' ) );
listing( '9101', 'Active', 1, 0 );
as_enqueue_async_action( 'tcgiant_sync_process_item_import', array( 'item_id' => '9101' ), 'tcgiant_sync_imports' );
run_queue( 'tcgiant_sync_process_item_import' );
check( 'a delta at work, an import left from a full sync ends: the delta goes on', array( state( 'status' ), state( 'sync_mode' ), state( 'last_successful_sync_at' ), prunes(), claim() ), array( 'scanning', 'delta', P0, 0, null ) );
quiet_for( 3600 );
check( '  the dashboard and the hourly sync\'s completion leave it alone', array( complete_by( 'dashboard' ), complete_by( 'poll' ), state( 'status' ) ), array( false, false, 'scanning' ) );
set_state( array( 'status' => 'limit_reached' ) );
quiet_for( 3600 );
check( 'a delta the free limit held back a listing in: not completed by them either', array( complete_by( 'dashboard' ), complete_by( 'poll' ), complete_by( 'limit' ), state( 'status' ), prunes() ), array( false, false, false, 'limit_reached', 0 ) );
set_state( array( 'status' => 'rate_limited' ) );
check( '  nor one eBay\'s rate limit paused', array( complete_by( 'import' ), state( 'status' ) ), array( false, 'rate_limited' ) );
// A delta from before 3.27 that said "importing": complete as a delta.
set_state( array( 'status' => 'importing' ) );
$LOG = array();
check( 'a delta from before 3.27 saying "importing": completed as a delta', array( complete_by( 'import' ), state( 'status' ), state( 'last_successful_sync_at' ), prunes(), claim(), said( 'Delta sync complete!' ) ), array( true, 'complete', P0, 0, null, 1 ) );

// Items synced by hand on their own.
shop( array( 'status' => 'complete', 'sync_mode' => 'delta', 'scan_complete_clean' => true, 'full_started_at' => '2026-09-30T00:00:00.000Z' ) );
listing( '9201', 'Active', 1, 0 );
$IMP->start_specific_sync( array( '9201' ) );
run_queue( 'tcgiant_sync_process_item_import' );
check( 'items synced by hand on their own: complete, no position moved, no clean-up', array( state( 'status' ), state( 'sync_mode' ), state( 'last_successful_sync_at' ), prunes(), claim() ), array( 'complete', 'specific', P0, 0, null ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA STOPPED SYNC STAYS STOPPED\n" . str_repeat( '=', 140 ) . "\n";

last_import_waiting();
TCGiant_Sync_Importer::stop_all();
TCGiant_Sync_Importer::update_sync_state( array( 'status' => 'stopped', 'scan_complete_clean' => false ) );
$IMP->process_item_import( '9002' );      // It was already running.
quiet_for( 3600 );
check( 'Emergency Stop in the import phase, its last import still running ends: stopped', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes(), claim() ), array( 'stopped', P0, 0, null ) );
check( '  the dashboard and the hourly sync leave it stopped', array( complete_by( 'dashboard' ), complete_by( 'poll' ), state( 'status' ) ), array( false, false, 'stopped' ) );
// Resume Import then: every page was read, nothing is left to run: complete, with no clean-up (Stop withdrew it).
$IMP->resume_sync();
check( '  Resume Import: complete, from the scan\'s start, no clean-up', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', state( 'full_started_at' ), 0 ) );

// Stop pressed in another request just as the last import claims the completion.
last_import_waiting();
$wpdb->before_claim = function () {
	elsewhere( TCGiant_Sync_Importer::STATE_OPTION, array_merge( $GLOBALS['TABLE'][ TCGiant_Sync_Importer::STATE_OPTION ], array( 'status' => 'stopped', 'scan_complete_clean' => false ) ) );
};
run_queue( 'tcgiant_sync_process_item_import' );
check( 'Stop pressed as the last import claims the completion (it came between)', $wpdb->before_claim, null );
check( '  stopped, no position, no clean-up', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'stopped', P0, 0 ) );

// Stopped during the scan: finalize_scan() says nothing is finished.
shop();
ebay_pages( array( 1 => array( row( '9301' ) ), 2 => array( row( '9302' ) ) ), array( 2 => function () {
	set_state( array( 'status' => 'stopped', 'scan_complete_clean' => false ) );
} ) );
full_sync();
check( 'Stop pressed while the scan reads its last page: stopped, nothing completed', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes(), claim() ), array( 'stopped', P0, 0, null ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE FREE LIMIT AND EBAY'S RATE LIMIT IN THE IMPORT PHASE\n" . str_repeat( '=', 140 ) . "\n";

// Over the free limit: the queued imports of new listings are held back, and
// the last of them completes the sync.
shop();
product( 71, '9401', 1 );                  // Already imported: updated, limit or not.
ebay_pages( array( 1 => array( row( '9401', true ), row( '9402', true ), row( '9403', true ) ) ) );
full_sync();
$CAN_IMPORT = false;                       // Reached since the scan queued them.
run_one( 'tcgiant_sync_process_item_import' );
check( 'over the free limit, the first import an update: still importing', state( 'status' ), 'importing' );
run_one( 'tcgiant_sync_process_item_import' );
check( '  the next held back: "limit_reached", not complete with one left', array( state( 'status' ), queued( 'tcgiant_sync_process_item_import' ) ), array( 'limit_reached', 1 ) );
run_one( 'tcgiant_sync_process_item_import' );
check( '  the last held back too: complete, position moved, the clean-up queued', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', state( 'full_started_at' ), 1 ) );
$CAN_IMPORT = true;

// Held back while the scan still has pages to read: not complete until it has read them.
shop();
ebay_pages(
	array( 1 => array( row( '9501', true ) ), 2 => array( row( '9502' ) ) ),
	array( 2 => function () { $GLOBALS['CAN_IMPORT'] = false; run_queue( 'tcgiant_sync_process_item_import' ); $GLOBALS['MID_SCAN'] = state( 'status' ); } )
);
full_sync();
$CAN_IMPORT = true;
check( 'an import held back while the scan reads on: "limit_reached", not completed mid-scan', $MID_SCAN, 'limit_reached' );
check( '  completed once the last page is read', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', state( 'full_started_at' ), 1 ) );

// eBay's rate limit puts the last import off: the hourly sync, the weekly full
// sync and a new shop's hourly sync leave it alone, and the import completes it.
foreach ( array( 'a shop' => false, 'a new shop' => true ) as $who => $new ) {
	shop( array(), array(), $new );
	ebay_pages( array( 1 => array( row( '9601' ), row( '9602', true ) ) ) );
	full_sync();
	$began = state( 'full_started_at' );
	$EBAY['items']['9602'] = new WP_Error( 'rate_limited', 'eBay API rate limit reached.', array( 'retry_after' => 60 ) );
	run_one( 'tcgiant_sync_process_item_import' );
	check( sprintf( '%s: its last import put off by eBay\'s rate limit: "rate_limited", the import queued again', $who ), array( state( 'status' ), queued( 'tcgiant_sync_process_item_import' ) ), array( 'rate_limited', 1 ) );
	quiet_for( 45 * MINUTE_IN_SECONDS );
	$LOG = array();
	$IMP->start_delta_sync();
	check( '  the hourly sync leaves it: no delta, no full sync, the import still queued', array( said( 'Starting delta sync' ), said( 'Starting full sync' ), state( 'sync_mode' ), state( 'status' ), queued( 'tcgiant_sync_process_item_import' ) ), array( 0, 0, 'full', 'rate_limited', 1 ) );
	$weekly = $IMP->start_full_sync();
	check( '  and so does the weekly full sync', array( is_wp_error( $weekly ) ? $weekly->get_error_code() : $weekly, queued( 'tcgiant_sync_process_item_import' ) ), array( 'already_running', 1 ) );
	listing( '9602', 'Active', 1, 0 );
	run_queue( 'tcgiant_sync_process_item_import' );
	check( '  the import then runs: complete, position moved, one clean-up', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', $began, 1 ) );
}

// Items synced by hand while a rate-limited full sync waits join it.
shop();
ebay_pages( array( 1 => array( row( '9701' ), row( '9702', true ) ) ) );
full_sync();
$EBAY['items']['9702'] = new WP_Error( 'rate_limited', 'eBay API rate limit reached.', array( 'retry_after' => 60 ) );
run_one( 'tcgiant_sync_process_item_import' );
$IMP->start_specific_sync( array( '9703' ) );
check( 'items synced by hand while it waits on the rate limit: they join it, still a full sync', state( 'sync_mode' ), 'full' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE DASHBOARD AND THE HOURLY SYNC COMPLETE ONE NOTHING ELSE DID\n" . str_repeat( '=', 140 ) . "\n";

/** Every page read, its last import's request killed outright: "importing", nothing queued. */
function import_request_killed( $new = false ) {
	shop( array(), array(), $new );
	ebay_pages( array( 1 => array( row( '9801' ), row( '9802', true ) ) ) );
	full_sync();
	$GLOBALS['QUEUE'] = array();
}
import_request_killed();
quiet_for( 2 * MINUTE_IN_SECONDS );
check( 'its last import killed outright, two minutes on: the dashboard waits', array( complete_by( 'dashboard' ), state( 'status' ) ), array( false, 'importing' ) );
quiet_for( 6 * MINUTE_IN_SECONDS );
$RUNNING = array( 'tcgiant_sync_process_item_import' );
check( '  six minutes on, but an import running: it waits', array( complete_by( 'dashboard' ), state( 'status' ) ), array( false, 'importing' ) );
$RUNNING = array();
as_enqueue_async_action( 'tcgiant_sync_process_item_import', array( 'item_id' => '9802' ), 'tcgiant_sync_imports' );
check( '  one pending: it waits', array( complete_by( 'dashboard' ), state( 'status' ) ), array( false, 'importing' ) );
$QUEUE = array();
check( '  none pending or running, six minutes quiet: complete, from the scan\'s start', array( complete_by( 'dashboard' ), state( 'status' ), state( 'last_successful_sync_at' ), '' !== (string) state( 'last_completed' ) ), array( true, 'complete', state( 'full_started_at' ), true ) );
check( '  with no clean-up: it runs after the next full sync that finishes on its own, and the log says so', array( prunes(), said( 'Inventory pruning: runs after the next full sync that finishes on its own' ), said( 'Inventory pruning: queued' ) ), array( 0, 1, 0 ) );

// The hourly sync does the same, then starts the delta from there.
foreach ( array( 'a shop' => false, 'a new shop' => true ) as $who => $new ) {
	import_request_killed( $new );
	$began = state( 'full_started_at' );
	quiet_for( 45 * MINUTE_IN_SECONDS );
	$LOG = array();
	$IMP->start_delta_sync();
	check( sprintf( '%s, its last import killed: the hourly sync completes it, then runs a delta from its start', $who ), array( said( 'Sync complete!' ), said( 'presumed to have failed' ), state( 'sync_mode' ), state( 'delta_mod_from' ) ), array( 1, 0, 'delta', gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( $began ) - 300 ) ) );
	check( '  with no clean-up, saying it runs after the next full sync that finishes on its own', array( prunes(), said( 'Inventory pruning: runs after the next full sync that finishes on its own' ), said( 'did not complete cleanly' ) ), array( 0, 1, 0 ) );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE WEEKLY FULL SYNC TAKES OVER A DELTA THAT HAS DIED (delta-state_4)\n" . str_repeat( '=', 140 ) . "\n";

$PLACE = array( 'from' => '2026-10-02T00:00:00.000Z', 'to' => '2026-10-04T00:00:00.000Z', 'cursor' => '110000000040', 'done' => 40, 'ts' => time() - 45 * MINUTE_IN_SECONDS );
function dead_delta( $idle, $queued = false ) {
	shop( array( 'status' => 'scanning', 'sync_mode' => 'delta', 'delta_mod_from' => '2026-10-02T00:00:00.000Z', 'last_activity_ts' => time() - $idle ) );
	$GLOBALS['PLACE']['ts'] = time() - $idle;
	update_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION, $GLOBALS['PLACE'] );
	if ( $queued ) {
		as_enqueue_async_action( 'tcgiant_sync_fetch_delta_events', array(), 'tcgiant_sync_group' );
	}
}
dead_delta( 45 * MINUTE_IN_SECONDS );
$weekly = $IMP->start_full_sync();
check( 'a delta dead 45 minutes, nothing of it queued: the weekly full sync starts', array( $weekly, state( 'sync_mode' ), state( 'status' ), queued( 'tcgiant_sync_scan_all_pages' ) ), array( true, 'full', 'scanning', 1 ) );
check( '  the delta\'s place in its window kept, for a delta that carries on after it', get_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION ), $PLACE );
check( '  and the log says so', said( 'takes it over' ), 1 );
dead_delta( 5 * MINUTE_IN_SECONDS );
$weekly = $IMP->start_full_sync();
check( 'a delta at work: the weekly full sync is refused, as before', array( is_wp_error( $weekly ) ? $weekly->get_error_code() : $weekly, state( 'sync_mode' ) ), array( 'already_running', 'delta' ) );
dead_delta( 45 * MINUTE_IN_SECONDS, true );
$weekly = $IMP->start_full_sync();
check( 'a delta quiet 45 minutes with its next run queued: refused, as the hourly sync waits on it', array( is_wp_error( $weekly ) ? $weekly->get_error_code() : $weekly, state( 'sync_mode' ), queued( 'tcgiant_sync_fetch_delta_events' ) ), array( 'already_running', 'delta', 1 ) );
// Nor a full sync's scan quiet as long.
shop( array( 'status' => 'scanning', 'sync_mode' => 'full', 'full_started_at' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 3600 ), 'last_activity_ts' => time() - 45 * MINUTE_IN_SECONDS, 'scan_page_at' => time() - 45 * MINUTE_IN_SECONDS ) );
$weekly = $IMP->start_full_sync();
check( 'a full sync\'s scan quiet 45 minutes: still refused, as before', is_wp_error( $weekly ) ? $weekly->get_error_code() : $weekly, 'already_running' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nRESUME IMPORT IN THE IMPORT PHASE\n" . str_repeat( '=', 140 ) . "\n";

shop();
ebay_pages( array( 1 => array( row( '9901' ), row( '9902', true ) ) ) );
full_sync();
$EBAY['items']['9902'] = new WP_Error( 'rate_limited', 'eBay API rate limit reached.', array( 'retry_after' => 60 ) );
run_one( 'tcgiant_sync_process_item_import' );
$IMP->resume_sync();
check( 'Resume Import with the put-off import still queued: importing, waiting for it', array( state( 'status' ), prunes() ), array( 'importing', 0 ) );
listing( '9902', 'Active', 1, 0 );
run_queue( 'tcgiant_sync_process_item_import' );
check( '  which completes it', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', state( 'full_started_at' ), 1 ) );
// The import ran while it said "rate_limited", and nothing completed it: Resume does.
shop();
ebay_pages( array( 1 => array( row( '9911' ), row( '9912', true ) ) ) );
full_sync();
$QUEUE = array();
set_state( array( 'status' => 'rate_limited' ) );
$IMP->resume_sync();
check( 'Resume Import with nothing left to run: complete at once, one clean-up', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', state( 'full_started_at' ), 1 ) );

// A sync put back to "scanning" with every page read, nothing left queued: the
// next batch to come along puts it back, and completes it.
shop();
ebay_pages( array( 1 => array( row( '9921' ), row( '9922', true ) ) ) );
full_sync();
$QUEUE = array();
set_state( array( 'status' => 'scanning' ) );
$IMP->scan_all_pages();
check( 'a sync left "scanning" with every page read and nothing queued: a batch completes it', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', state( 'full_started_at' ), 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 1: A LISTING EBAY KEEPS ANSWERING WITH AN ERROR THAT IS NOT ITS CALL LIMIT\n" . str_repeat( '=', 140 ) . "\n";

/** The arguments of the import waiting in the queue, or null. */
function import_args() {
	foreach ( $GLOBALS['QUEUE'] as $a ) {
		if ( 'tcgiant_sync_process_item_import' === $a['hook'] ) {
			return $a['args'];
		}
	}
	return null;
}
/** eBay's 10007, "Internal error to the application": a rate limit to the API layer, not a call allowance. */
function ebay_10007() { return new WP_Error( 'rate_limited', 'Internal error to the application.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '10007' ) ) ); }
function ebay_out_of_calls() { return new WP_Error( 'rate_limited', 'eBay API rate limit reached.', array( 'retry_after' => 60 ) ); }

foreach ( array( 'a shop' => false, 'a new shop' => true ) as $who => $new ) {
	shop( array(), array(), $new );
	ebay_pages( array( 1 => array( row( '9611' ), row( '9612', true ) ) ) );
	full_sync();
	$began = state( 'full_started_at' );
	$EBAY['items']['9612'] = ebay_10007();
	check( sprintf( '%s: eBay\'s 10007 is not a call allowance', $who ), TCGiant_Sync_Importer::is_out_of_calls( $EBAY['items']['9612'] ), false );
	run_one( 'tcgiant_sync_process_item_import' );
	check( '  its last import answered 10007: tried again later, the try counted in its arguments', array( state( 'status' ), import_args() ), array( 'rate_limited', array( 'item_id' => '9612', 'soft_tries' => 1 ) ) );
	quiet_for( 3 * HOUR_IN_SECONDS );
	$LOG = array();
	$IMP->start_delta_sync();
	check( '  meanwhile the hourly sync leaves it, its import waiting', array( said( 'Starting delta sync' ), state( 'sync_mode' ), queued( 'tcgiant_sync_process_item_import' ) ), array( 0, 'full', 1 ) );
	run_one( 'tcgiant_sync_process_item_import' );
	check( '  10007 again: tried again, twice counted', import_args(), array( 'item_id' => '9612', 'soft_tries' => 2 ) );
	$LOG = array();
	run_one( 'tcgiant_sync_process_item_import' );
	check( '  the third 10007: counted as an error, nothing queued, three calls in all', array( state( 'total_errors' ), queued( 'tcgiant_sync_process_item_import' ), count( array_keys( $EBAY['get_items'], '9612', true ) ), said( 'eBay answered 3 times with an error that is not its call limit (last: Internal error to the application.), so it is counted as an error and the sync goes on without it. The next full sync tries it again.' ) ), array( 1, 0, 3, 1 ) );
	check( '  and the sync completes, from when it began, with its clean-up', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', $began, 1 ) );
	run_queue( 'tcgiant_sync_prune_orphans' );
	$LOG = array();
	$IMP->start_delta_sync();
	check( '  the hourly sync then runs the delta', array( said( 'Starting delta sync' ), state( 'sync_mode' ) ), array( 1, 'delta' ) );
}

// eBay's call limit is not one listing's: its import goes on waiting, as before.
shop();
ebay_pages( array( 1 => array( row( '9621' ), row( '9622', true ) ) ) );
full_sync();
$EBAY['items']['9622'] = ebay_out_of_calls();
for ( $try = 1; $try <= 5; $try++ ) {
	run_one( 'tcgiant_sync_process_item_import' );
}
check( 'eBay\'s call limit five times over: still waiting, its arguments the listing alone, no error counted', array( state( 'status' ), import_args(), state( 'total_errors' ) ), array( 'rate_limited', array( 'item_id' => '9622' ), 0 ) );
// A 10007 after an out-of-calls retry keeps its count; an out-of-calls answer after a 10007 keeps it too.
$EBAY['items']['9622'] = ebay_10007();
run_one( 'tcgiant_sync_process_item_import' );
$EBAY['items']['9622'] = ebay_out_of_calls();
run_one( 'tcgiant_sync_process_item_import' );
check( '  a 10007, then the call limit again: the count of 10007s kept', import_args(), array( 'item_id' => '9622', 'soft_tries' => 1 ) );

// The hourly sync and the weekly full sync wait on its imports no more than a
// day with nothing recorded: an import that never runs cannot hold them off.
foreach ( array( 'the hourly sync' => 'delta', 'the weekly full sync' => 'weekly' ) as $who => $what ) {
	shop();
	ebay_pages( array( 1 => array( row( '9631' ), row( '9632', true ) ) ) );
	full_sync();
	$EBAY['items']['9632'] = ebay_out_of_calls();
	run_one( 'tcgiant_sync_process_item_import' );
	quiet_for( 23 * HOUR_IN_SECONDS );
	$LOG = array();
	$r = 'delta' === $what ? $IMP->start_delta_sync() : $IMP->start_full_sync();
	check( sprintf( '%s, 23 hours quiet, an import waiting: it leaves the full sync', $who ), array( state( 'sync_mode' ), state( 'status' ), queued( 'tcgiant_sync_process_item_import' ) ), array( 'full', 'rate_limited', 1 ) );
	quiet_for( 25 * HOUR_IN_SECONDS );
	$old = state( 'full_started_at' );
	$r = 'delta' === $what ? $IMP->start_delta_sync() : $IMP->start_full_sync();
	check( sprintf( '  25 hours quiet: %s starts over it, as 3.29.2 did', $who ), 'delta' === $what ? array( state( 'sync_mode' ), said( 'Starting delta sync' ) ) : array( $r, state( 'status' ), said( 'Starting full sync' ) ), 'delta' === $what ? array( 'delta', 1 ) : array( true, 'scanning', 1 ) );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 1: THE CLEAN-UP A COMPLETION QUEUES RUNS BEFORE THE DELTA STARTS\n" . str_repeat( '=', 140 ) . "\n";

foreach ( array( 'its last page' => 'scan', 'its last import' => 'import' ) as $how => $by ) {
	shop();
	product( 63, '6300', 1 );
	listing( '6300', 'Completed', 1, 1 );
	$rows = filler( 20, 6000 );
	if ( 'import' === $by ) {
		$rows[] = row( '6399', true );
	}
	ebay_pages( array( 1 => $rows ) );
	full_sync();
	run_queue( 'tcgiant_sync_process_item_import' );
	$began = state( 'full_started_at' );
	check( sprintf( 'completed by %s: complete, the clean-up queued', $how ), array( state( 'status' ), prunes() ), array( 'complete', 1 ) );
	$LOG = array();
	$IMP->start_delta_sync();
	check( '  the hourly sync before the clean-up has run: no delta, and it says why', array( said( 'Delta sync not started yet' ), said( 'Starting delta sync' ), state( 'sync_mode' ), (bool) state( 'scan_complete_clean' ) ), array( 1, 0, 'full', true ) );
	$RUNNING = array( 'tcgiant_sync_prune_orphans' );
	$QUEUE   = array_values( array_filter( $QUEUE, function ( $a ) { return 'tcgiant_sync_prune_orphans' !== $a['hook']; } ) );
	$LOG     = array();
	$IMP->start_delta_sync();
	check( '  nor while its first run is at work', array( said( 'Delta sync not started yet' ), state( 'sync_mode' ) ), array( 1, 'full' ) );
	$RUNNING = array();
	as_enqueue_async_action( 'tcgiant_sync_prune_orphans', array(), 'tcgiant_sync_group' );
	$LOG = array();
	run_queue( 'tcgiant_sync_prune_orphans' );
	check( '  the clean-up then runs and settles the sold-out listing', array( meta( 63, '_ebay_listing_status' ), stock( 63 ), said( 'did not complete cleanly' ) ), array( 'Ended', 0, 0 ) );
	$LOG = array();
	$IMP->start_delta_sync();
	check( '  and the hourly sync then runs the delta, from when the full sync began', array( said( 'Starting delta sync' ), state( 'delta_mod_from' ) ), array( 1, gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( $began ) - 300 ) ) );
}

// Not for longer than two hours after the completion: Action Scheduler not
// running must not hold the delta off.
shop();
ebay_pages( array( 1 => array( row( '6401' ) ) ) );
full_sync();
set_state( array( 'last_completed_ts' => time() - 2 * HOUR_IN_SECONDS - 60 ) );
$LOG = array();
$IMP->start_delta_sync();
check( 'the clean-up still queued two hours after the completion: the delta starts all the same', array( said( 'Delta sync not started yet' ), said( 'Starting delta sync' ), state( 'sync_mode' ) ), array( 0, 1, 'delta' ) );
// Nor for a follow-up run of the clean-up.
shop();
ebay_pages( array( 1 => array( row( '6411' ) ) ) );
full_sync();
$QUEUE = array( array( 'hook' => 'tcgiant_sync_prune_orphans', 'args' => array( 'follow_up' => true ), 'group' => 'tcgiant_sync_group', 'when' => time() + 3600 ) );
$LOG   = array();
$IMP->start_delta_sync();
check( 'only a follow-up run of the clean-up waiting: the delta starts', array( said( 'Delta sync not started yet' ), state( 'sync_mode' ) ), array( 0, 'delta' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 1: AN OLD LIST, NO START, STOP AS THE LAST PAGE IS READ, A LATE LOSER, THE WEEKLY SWEEP\n" . str_repeat( '=', 140 ) . "\n";

// Pages read more than eight days ago: complete, but no clean-up against that list.
last_import_waiting();
$began = state( 'full_started_at' );
set_state( array( 'scan_finished_at' => time() - 9 * DAY_IN_SECONDS ) );
$LOG = array();
run_queue( 'tcgiant_sync_process_item_import' );
check( 'its pages read nine days ago: complete, position moved, no clean-up, and it says why', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes(), said( 'too old to prune against' ) ), array( 'complete', $began, 0, 1 ) );
// One from before scan_finished_at was kept: judged by when it began.
last_import_waiting();
$old_start = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 9 * DAY_IN_SECONDS );
$s = $TABLE[ TCGiant_Sync_Importer::STATE_OPTION ];
unset( $s['scan_finished_at'] );
$s['full_started_at'] = $old_start;
$s['scan_finished']   = $old_start;
update_option( TCGiant_Sync_Importer::STATE_OPTION, $s );
run_queue( 'tcgiant_sync_process_item_import' );
check( '  one with no record of when, begun nine days ago: complete, no clean-up', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', $old_start, 0 ) );
// Begun nine days ago, its pages read just now (a long pause): judged by
// when its pages were read, so pruned as any.
shop();
ebay_pages( array( 1 => array( row( '9931' ) ) ) );
@unlink( lock_file() );
$IMP->start_full_sync( true );
$QUEUE = array();
$s     = $TABLE[ TCGiant_Sync_Importer::STATE_OPTION ];
$s['full_started_at'] = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 9 * DAY_IN_SECONDS );
update_option( TCGiant_Sync_Importer::STATE_OPTION, $s );
$IMP->scan_all_pages();
check( '  begun nine days ago, its pages read just now: complete, the clean-up queued', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', $s['full_started_at'], 1 ) );
// Seven days: pruned as any.
last_import_waiting();
set_state( array( 'scan_finished_at' => time() - 7 * DAY_IN_SECONDS ) );
run_queue( 'tcgiant_sync_process_item_import' );
check( '  its pages read seven days ago: the clean-up queued', array( state( 'status' ), prunes() ), array( 'complete', 1 ) );

// A full sync begun before 3.27, with no full_started_at: the position stays where the delta has it.
shop( array( 'status' => 'importing', 'sync_mode' => 'full', 'total_queued' => 1, 'total_processed' => 1, 'started_at' => '2026-09-01 10:00:00', 'scan_complete_clean' => true ) );
check( 'a full sync with no start recorded completes, the position kept, not moved to now', array( complete_by( 'import' ), state( 'status' ), state( 'last_successful_sync_at' ) ), array( true, 'complete', P0 ) );
shop( array( 'status' => 'importing', 'sync_mode' => 'full', 'total_queued' => 1, 'total_processed' => 1, 'started_at' => '2026-09-01 10:00:00' ), array(), true );
complete_by( 'import' );
check( '  with no position at all: now, as before', abs( strtotime( (string) state( 'last_successful_sync_at' ) ) - time() ) <= 5, true );

// Emergency Stop pressed as finalize_scan() writes its last state (probe4).
shop();
product( 64, '6500', 1 );
listing( '6500', 'Completed', 1, 1 );
ebay_pages( array( 1 => filler( 20, 6100 ) ) );
$wpdb->on_finalize_write = function () {
	// The Stop button's request: stop_all() and the stopped write, as the scan writes.
	TCGiant_Sync_Importer::stop_all();
	elsewhere( TCGiant_Sync_Importer::STATE_OPTION, array_merge( $GLOBALS['TABLE'][ TCGiant_Sync_Importer::STATE_OPTION ], array( 'status' => 'stopped', 'scan_complete_clean' => false ) ) );
};
full_sync();
check( 'Stop pressed as the scan writes its last page: it came then', $wpdb->on_finalize_write, null );
check( '  stopped, not complete, no clean-up, not marked clean', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes(), (bool) state( 'scan_complete_clean' ), claim() ), array( 'stopped', P0, 0, false, null ) );
run_queue( 'tcgiant_sync_prune_orphans' );
check( '  nothing pruned', array( meta( 64, '_ebay_listing_status' ), $EBAY['get_items'] ), array( 'Active', array() ) );
check( '  and the scan stops there: no "Scan complete", its place kept for Resume', array( said( 'Scan complete' ), is_array( get_option( TCGiant_Sync_Importer::SCAN_PROGRESS_OPTION, null ) ) ), array( 0, true ) );
$IMP->resume_sync();
check( '  Resume Import then completes it, from when it began, with no clean-up', array( state( 'status' ), state( 'last_successful_sync_at' ), prunes() ), array( 'complete', state( 'full_started_at' ), 0 ) );
// The Stop written by another request alone (its stop_all() not run here):
// the scan lets go of the lock itself, so Fetch Inventory is not refused.
shop();
ebay_pages( array( 1 => filler( 5, 6200 ) ) );
$wpdb->on_finalize_write = function () {
	elsewhere( TCGiant_Sync_Importer::STATE_OPTION, array_merge( $GLOBALS['TABLE'][ TCGiant_Sync_Importer::STATE_OPTION ], array( 'status' => 'stopped', 'scan_complete_clean' => false ) ) );
};
full_sync();
check( '  a Stop the lock was not let go for: the scan lets go of it', array( $wpdb->on_finalize_write, state( 'status' ), is_file( lock_file() ) ), array( null, 'stopped', false ) );

// A loser that finds the sync moved on since, with the same key: it writes nothing.
foreach ( array( 'idle' => 'daily maintenance reset it', 'scanning' => 'it was put back to scanning' ) as $now => $why ) {
	last_import_waiting();
	$wpdb->before_claim = function () use ( $now ) {
		complete_by( 'resume' );
		elsewhere( TCGiant_Sync_Importer::STATE_OPTION, array_merge( $GLOBALS['TABLE'][ TCGiant_Sync_Importer::STATE_OPTION ], array( 'status' => $now ) ) );
	};
	run_queue( 'tcgiant_sync_process_item_import' );
	check( sprintf( 'Resume completes it as the last import claims, then %s: the import leaves it "%s"', $why, $now ), array( $wpdb->before_claim, state( 'status' ), prunes(), said( 'Sync complete!' ) ), array( null, $now, 1, 1 ) );
}

// The weekly full sync finishes a full sync nothing finished, then starts its own.
$cron_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-cron.php' );
preg_match( '/public function run_weekly_full_sync\(\) \{(.*?)\n\t\}/s', $cron_src, $weekly_body );
$weekly = eval( 'return function () {' . ( $weekly_body[1] ?? '' ) . '};' );
import_request_killed();
$old = state( 'full_started_at' );
quiet_for( 6 * MINUTE_IN_SECONDS );
$LOG = array();
$weekly();
check( 'the weekly full sync over a full sync whose last import was killed: completes it first, with no clean-up of its own', array( said( 'Sync complete!' ), said( 'Inventory pruning: runs after the next full sync that finishes on its own' ), said( 'Weekly full sync did not start' ) ), array( 1, 1, 0 ) );
check( '  then starts its own full sync', array( state( 'status' ), said( 'Starting full sync' ), queued( 'tcgiant_sync_scan_all_pages' ) ), array( 'scanning', 1, 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 2: A 10007 RETRY SEEN AS QUEUED, THE DELTA'S WAIT, STOP OVER A RETRY, THE SPOT CHECK'S MUTANTS\n" . str_repeat( '=', 140 ) . "\n";

/** The arguments of every import queued for $id. */
function imports_for( $id ) {
	$out = array();
	foreach ( $GLOBALS['QUEUE'] as $a ) {
		if ( 'tcgiant_sync_process_item_import' === $a['hook'] && (string) ( $a['args']['item_id'] ?? '' ) === $id ) {
			$out[] = $a['args'];
		}
	}
	return $out;
}
/** A retry of $id's import waiting, as retry_import_later() queues it. */
function retry_waiting( $id, $soft = 0 ) {
	$GLOBALS['QUEUE'][] = array( 'hook' => 'tcgiant_sync_process_item_import', 'args' => $soft > 0 ? array( 'item_id' => $id, 'soft_tries' => $soft ) : array( 'item_id' => $id ), 'group' => 'tcgiant_sync_imports', 'when' => time() + 900 );
}

// probe1-dup: a page read again while a listing's 10007 retry waits queues
// nothing new - at the GetItem queue, and where a relist is put off.
foreach ( array( 0, 1, 2 ) as $n ) {
	shop();
	ebay_pages( array( 1 => array( row( '9711', true ), row( '9712' ) ) ) );
	@unlink( lock_file() );
	$IMP->start_full_sync( true );
	retry_waiting( '9711', $n );
	run_queue( 'tcgiant_sync_scan_all_pages' );
	$want = $n > 0 ? array( 'item_id' => '9711', 'soft_tries' => $n ) : array( 'item_id' => '9711' );
	check( sprintf( 'its page read while its retry waits (%s): nothing new queued', $n > 0 ? 'after ' . $n . ' 10007' . ( $n > 1 ? 's' : '' ) : 'the call limit' ), array( imports_for( '9711' ), state( 'total_queued' ) ), array( array( $want ), 0 ) );
}
foreach ( array( 'none' => -1, 'a 10007 retry' => 1 ) as $what => $n ) {
	shop();
	ebay_pages( array( 1 => array( row( '9721' ) ) ) );
	@unlink( lock_file() );
	$IMP->start_full_sync( true );
	if ( $n > 0 ) {
		retry_waiting( '9721', $n );
	}
	$MAPPER_DEFERS = true;
	run_queue( 'tcgiant_sync_scan_all_pages' );
	$MAPPER_DEFERS = false;
	check( sprintf( 'a relist put off as the scan reads it, %s waiting: queued %s', $what, $n > 0 ? 'nothing new' : 'once' ), array( count( imports_for( '9721' ) ), state( 'total_queued' ) ), $n > 0 ? array( 1, 0 ) : array( 1, 1 ) );
}

// The count rides a relist put off as well (X04).
shop();
ebay_pages( array( 1 => array( row( '9741', true ) ) ) );
full_sync();
$EBAY['items']['9741'] = ebay_10007();
run_one( 'tcgiant_sync_process_item_import' );
listing( '9741', 'Active', 1, 0 );
$MAPPER_DEFERS = true;
run_one( 'tcgiant_sync_process_item_import' );
$MAPPER_DEFERS = false;
check( 'a 10007, then the relist put off: its retry keeps the count', import_args(), array( 'item_id' => '9741', 'soft_tries' => 1 ) );

// The delta's wait: a completion stamped in the future (the clock put back) holds nothing.
shop();
ebay_pages( array( 1 => array( row( '9751' ) ) ) );
full_sync();
set_state( array( 'last_completed_ts' => time() + HOUR_IN_SECONDS ) );
$LOG = array();
$IMP->start_delta_sync();
check( 'a completion stamped an hour ahead, its clean-up queued: the delta starts', array( said( 'Delta sync not started yet' ), state( 'sync_mode' ), prunes() ), array( 0, 'delta', 1 ) );

// Nor does the delta wait when what is complete is not that full sync (X01, X02).
// Items synced by hand completed after it (their prune refuses anyway):
shop();
ebay_pages( array( 1 => array( row( '9761' ) ) ) );
full_sync();
listing( '9762', 'Active', 1, 0 );
$IMP->start_specific_sync( array( '9762' ) );
run_queue( 'tcgiant_sync_process_item_import' );
$LOG = array();
$IMP->start_delta_sync();
check( 'items synced by hand completed after the full sync, its clean-up still queued: the delta starts', array( state( 'sync_mode' ), said( 'Delta sync not started yet' ), said( 'Starting delta sync' ) ), array( 'delta', 0, 1 ) );
// Emergency Stop pressed while the clean-up's first run is at work:
shop();
ebay_pages( array( 1 => array( row( '9771' ) ) ) );
full_sync();
$QUEUE   = array();
$RUNNING = array( 'tcgiant_sync_prune_orphans' );
TCGiant_Sync_Importer::stop_all();
TCGiant_Sync_Importer::update_sync_state( array( 'status' => 'stopped', 'scan_complete_clean' => false ) );
$LOG = array();
$IMP->start_delta_sync();
$RUNNING = array();
check( 'Emergency Stop while the clean-up runs: the hourly sync does not wait on it', array( state( 'sync_mode' ), said( 'Delta sync not started yet' ) ), array( 'delta', 0 ) );

// A state with no activity recorded at all: the hourly sync does not wait on its imports (X03).
shop( array( 'status' => 'rate_limited', 'sync_mode' => 'full', 'full_started_at' => '2026-10-30T00:00:00.000Z', 'scan_finished' => '2026-10-30T00:00:00.000Z', 'total_pages' => 1, 'current_page' => 1 ) );
retry_waiting( '9781' );
check( 'a full sync with no activity recorded at all (precondition)', TCGiant_Sync_Importer::sync_idle_seconds( (array) get_option( TCGiant_Sync_Importer::STATE_OPTION ) ), null );
$LOG = array();
$IMP->start_delta_sync();
check( '  an import waiting: the hourly sync starts over it, as 3.29.2 did', array( state( 'sync_mode' ), said( 'Starting delta sync' ) ), array( 'delta', 1 ) );

// probe3-stop-retry: an import running when Emergency Stop is pressed, put off by eBay.
foreach ( array( '10007' => 'ebay_10007', 'the call limit' => 'ebay_out_of_calls' ) as $what => $fn ) {
	shop();
	ebay_pages( array( 1 => array( row( '9791' ), row( '9792', true ) ) ) );
	full_sync();
	$EBAY['items']['9792'] = $fn();
	TCGiant_Sync_Importer::stop_all();
	TCGiant_Sync_Importer::update_sync_state( array( 'status' => 'stopped', 'scan_complete_clean' => false ) );
	$LOG = array();
	$IMP->process_item_import( '9792' );      // It was already running.
	check( sprintf( 'Stop, then the running import answered %s: still stopped, not queued again, no error counted', $what ), array( state( 'status' ), queued( 'tcgiant_sync_process_item_import' ), state( 'total_errors' ), said( 'Emergency Stop has been pressed, so it is not tried again' ) ), array( 'stopped', 0, 0, 1 ) );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE CODE AROUND IT\n" . str_repeat( '=', 140 ) . "\n";

$admin     = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
$uninstall = (string) file_get_contents( $ROOT . '/uninstall.php' );
preg_match( '/public function ajax_sync_status\(\) \{(.*?)\n\t\}/s', $admin, $poll );
check( 'the dashboard\'s poll completes through the importer, and writes no "complete" of its own', array( substr_count( $poll[1] ?? '', "TCGiant_Sync_Importer::complete_sync( 'dashboard' )" ), substr_count( $poll[1] ?? '', "=> 'complete'" ) ), array( 1, 0 ) );
check( 'deleting the plugin with its data deletes the record of the last completion', false !== strpos( $uninstall, "'tcgiant_sync_full_completed'," ), true );

@unlink( lock_file() );
printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
