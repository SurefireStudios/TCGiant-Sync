<?php
/**
 * Harness: the weekly full sync, turned away, is tried again hourly for a
 * day (3.30.1, the owner's ruling 2).
 *
 * The weekly event calls an unforced start_full_sync(), which a delta part
 * way through its window turns away ("already_running"). It was not tried
 * again until the next week, and the category memory, the full sync's
 * clean-up and its imports all waited with it. Now a refusal schedules one
 * retry an hour on, again after each refusal, for at most a day from the
 * first; a full sync that starts - the retry, the next weekly one, or a
 * forced Fetch Inventory - ends the retries. One retry waits at most.
 *
 * Fix round 2: only a retry carries on from the first refusal noted - the
 * weekly event's refusal is always a first one, so a note left behind
 * never makes it give up at once; Disabled and Emergency Stop end the
 * retries, and a retry that fires after Disabled starts nothing; a refusal
 * by a full sync already at work schedules none.
 *
 * Loaded for real: TCGiant_Sync_Importer, TCGiant_Sync_Inventory,
 * TCGiant_Sync_Sites and TCGiant_Sync_Runtime in the prune world
 * (support/prune-world.php), and the body of TCGiant_Sync_Cron::
 * run_weekly_full_sync() from the source, as test-full-sync-complete.php
 * runs it. WP-Cron is the prune world's list of events.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/prune-world.php';

TCGiant_Sync_Importer::$page_pause = 0;
$LOCK = sys_get_temp_dir() . '/tcgiant_sync.lock';

$cron_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-cron.php' );
preg_match( '/public function run_weekly_full_sync\(\) \{(.*?)\n\t\}/s', $cron_src, $weekly_body );
$weekly = eval( 'return function () {' . ( $weekly_body[1] ?? '' ) . '};' );
preg_match( '/public function run_weekly_full_retry\(\) \{(.*?)\n\t\}/s', $cron_src, $retry_body );
$retry = eval( 'return function () {' . ( $retry_body[1] ?? '' ) . '};' );
preg_match( '/public function schedule_events\(\) \{(.*?)\n\t\}/s', $cron_src, $schedule_body );
$schedule = eval( 'return function () {' . ( $schedule_body[1] ?? '' ) . '};' );
if ( ! function_exists( 'wp_get_schedule' ) ) {
	function wp_get_schedule( $hook ) { return wp_next_scheduled( $hook ) ? 'tcgiant_hourly' : false; }
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $when, $recurrence, $hook ) { $GLOBALS['CRON'][] = array( $when, $hook ); return true; }
}

/** A shop whose delta is part way through a window, its next run queued. */
function delta_at_work() {
	fresh_world( array() );
	@unlink( $GLOBALS['LOCK'] );
	update_option( TCGiant_Sync_Importer::STATE_OPTION, array(
		'status'                  => 'scanning',
		'sync_mode'               => 'delta',
		'last_successful_sync_at' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 3600 ),
		'delta_mod_from'          => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 3600 ),
		'last_activity'           => current_time( 'mysql' ),
		'last_activity_ts'        => time(),
	) );
	as_enqueue_async_action( 'tcgiant_sync_fetch_delta_events', array(), 'tcgiant_sync_group' );
	new_request();
}
/** The delta's window done. */
function delta_done() {
	update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'status' => 'complete' ) ) );
	$GLOBALS['QUEUE'] = array();
	@unlink( $GLOBALS['LOCK'] );
	new_request();
}
function retries() { return array_values( array_filter( $GLOBALS['CRON'], function ( $e ) { return TCGiant_Sync_Importer::WEEKLY_RETRY_HOOK === $e[1]; } ) ); }
function since() { return get_option( TCGiant_Sync_Importer::WEEKLY_RETRY_OPTION, 'none' ); }
function said( $needle ) { return count( array_filter( $GLOBALS['LOG'], function ( $l ) use ( $needle ) { return false !== strpos( $l[1], $needle ); } ) ); }
function state( $key ) { return TCGiant_Sync_Importer::get_sync_state()[ $key ] ?? null; }

echo "\nW1. Turned away: tried again in an hour, one retry at most\n";
delta_at_work();
$tcg_auto  = array();
$ON_UPDATE = function ( $n, $v, $a ) use ( &$tcg_auto ) { if ( TCGiant_Sync_Importer::WEEKLY_RETRY_OPTION === $n ) { $tcg_auto[] = $a; } };
$weekly();
$ON_UPDATE = null;
$r = retries();
check( 'the weekly full sync turned away by a delta at work, as before', array( said( 'Weekly full sync did not start: A sync is already running.' ), state( 'sync_mode' ) ), array( 1, 'delta' ) );
check( '  one retry, an hour on', array( count( $r ), abs( ( $r[0][0] ?? 0 ) - ( time() + HOUR_IN_SECONDS ) ) <= 2 ), array( 1, true ) );
check( '  the first refusal noted, once, not autoloaded', array( abs( (int) since() - time() ) <= 2, $tcg_auto ), array( true, array( false ) ) );
check( '  and said', said( 'Weekly full sync: tries again in an hour.' ), 1 );
$weekly();   // the weekly event again, its retry still waiting
check( 'turned away again with a retry waiting: still one retry, not two', count( retries() ), 1 );
update_option( TCGiant_Sync_Importer::WEEKLY_RETRY_OPTION, time() - 600 );   // the first refusal, ten minutes ago
$first = since();
$CRON  = array();   // WP-Cron takes the retry off its list as it runs it
$LOG   = array();
$retry();   // the retry, turned away again
check( 'the retry turned away again: still one retry, the first refusal kept', array( count( retries() ), since(), said( 'Weekly full sync: trying again, as it was turned away.' ) ), array( 1, $first, 1 ) );

echo "\nW2. The retry starts it once the delta is done; that ends the retries\n";
delta_done();
$CRON = array();
update_option( TCGiant_Sync_Importer::WEEKLY_RETRY_OPTION, time() - 3600 );
$retry();
check( 'the full sync starts', array( state( 'sync_mode' ), state( 'status' ), queued( 'tcgiant_sync_scan_all_pages' ) ), array( 'full', 'scanning', 1 ) );
check( '  no retry left, the note gone', array( retries(), since() ), array( array(), 'none' ) );

echo "\nW3. At most a day\n";
delta_at_work();
update_option( TCGiant_Sync_Importer::WEEKLY_RETRY_OPTION, time() - TCGiant_Sync_Importer::WEEKLY_RETRY_FOR + 60 );
$retry();
check( 'a minute short of a day since the first refusal: one more retry', count( retries() ), 1 );
$CRON = array();
update_option( TCGiant_Sync_Importer::WEEKLY_RETRY_OPTION, time() - TCGiant_Sync_Importer::WEEKLY_RETRY_FOR );
$LOG = array();
$retry();
check( 'a day since: no retry, the note gone, said', array( retries(), since(), said( 'Weekly full sync: tried hourly for a day and never started, so it waits for next week.' ) ), array( array(), 'none', 1 ) );
check( '  and no "tries again" line', said( 'tries again in an hour' ), 0 );
delta_at_work();
update_option( TCGiant_Sync_Importer::WEEKLY_RETRY_OPTION, time() + 3 * DAY_IN_SECONDS );
$retry();
check( 'a first refusal dated ahead of the clock: taken as now', array( count( retries() ), abs( (int) since() - time() ) <= 2 ), array( 1, true ) );
delta_at_work();
$retry();
check( 'a retry with no first refusal noted: taken as now', array( count( retries() ), abs( (int) since() - time() ) <= 2 ), array( 1, true ) );

echo "\nW4. Fetch Inventory ends the retries\n";
delta_at_work();
$weekly();
delta_done();
$started = $IMP->start_full_sync( true );
check( 'a forced full sync that starts: no retry left, the note gone', array( $started, retries(), since() ), array( true, array(), 'none' ) );
delta_at_work();
$weekly();
$IMP->start_full_sync( true );   // forced, over a delta at work
check( '  even over the delta, as Fetch Inventory always could', array( state( 'sync_mode' ), retries(), since() ), array( 'full', array(), 'none' ) );

echo "\nW5. A note left behind does not make a later week's refusal give up at once (fix round 2)\n";
delta_at_work();
update_option( TCGiant_Sync_Importer::WEEKLY_RETRY_OPTION, time() - 3 * DAY_IN_SECONDS );   // its retry cleared by a cron clean-up, say
$LOG = array();
$weekly();
check( 'the weekly event turned away, an old first refusal noted, no retry waiting: one retry, noted as now', array( count( retries() ), abs( (int) since() - time() ) <= 2 ), array( 1, true ) );
check( '  and not "tried hourly for a day"', array( said( 'tried hourly for a day' ), said( 'Weekly full sync: tries again in an hour.' ) ), array( 0, 1 ) );
delta_at_work();
update_option( TCGiant_Sync_Importer::WEEKLY_RETRY_OPTION, time() - TCGiant_Sync_Importer::WEEKLY_RETRY_FOR - 60 );
$weekly();
check( '  even one a day and a minute old', array( count( retries() ), abs( (int) since() - time() ) <= 2 ), array( 1, true ) );

echo "\nW6. Automatic sync set to Disabled: the retry ends, and one that fires starts nothing (fix round 2)\n";
delta_at_work();
$weekly();
update_option( 'tcgiant_sync_ebay_settings', array( 'sync_interval' => 'disabled' ) );
$schedule();
check( 'Disabled saved, its schedule looked at: no retry left, the note gone', array( retries(), since() ), array( array(), 'none' ) );
delta_done();
update_option( TCGiant_Sync_Importer::WEEKLY_RETRY_OPTION, time() - 3600 );
$LOG = array();
$retry();   // one that fired all the same
check( 'a retry that fires once Disabled is saved: no full sync, no retry, the note gone, nothing said', array( state( 'sync_mode' ), queued( 'tcgiant_sync_scan_all_pages' ), retries(), since(), said( 'Weekly full sync' ) ), array( 'delta', 0, array(), 'none', 0 ) );
update_option( 'tcgiant_sync_ebay_settings', array() );
delta_at_work();
$weekly();
$schedule();
check( '  automatic sync on: the retry left waiting', count( retries() ), 1 );

echo "\nW7. Emergency Stop ends the retries (fix round 2)\n";
delta_at_work();
$weekly();
TCGiant_Sync_Importer::stop_all();
check( 'Stop pressed: no retry left, the note gone', array( retries(), since() ), array( array(), 'none' ) );

echo "\nW8. Turned away by a full sync already at work: no retry - that one is the week's (fix round 2)\n";
foreach ( array( 'scanning', 'importing' ) as $status ) {
	fresh_world( array() );
	@unlink( $GLOBALS['LOCK'] );
	update_option( TCGiant_Sync_Importer::STATE_OPTION, array( 'status' => $status, 'sync_mode' => 'full', 'last_activity' => current_time( 'mysql' ), 'last_activity_ts' => time() ) );
	update_option( TCGiant_Sync_Importer::WEEKLY_RETRY_OPTION, time() - 600 );
	new_request();
	$LOG = array();
	$weekly();
	check( 'a full sync ' . $status . ': turned away as before; no retry, the note gone, said once', array( said( 'Weekly full sync did not start: A sync is already running.' ), retries(), since(), said( 'Weekly full sync: a full sync is already at work, and stands for this week\'s; it is not tried again.' ) ), array( 1, array(), 'none', 1 ) );
}

echo "\nTHE CODE\n";
check( 'the weekly event\'s refusal is what schedules the retry', 1 === preg_match( "/is_wp_error\( \\\$result \) \) \{\s*TCGiant_Sync_Logger::warning\( 'Weekly full sync did not start: ' \. \\\$result->get_error_message\(\) \);\s*TCGiant_Sync_Importer::weekly_full_refused\( \\\$result \);/", $weekly_body[1] ?? '' ), true );
check( '  the retry\'s refusal carries on from the first', 1 === preg_match( "/TCGiant_Sync_Importer::weekly_full_refused\( \\\$result, true \);/", $retry_body[1] ?? '' ), true );
check( '  the retry runs its own callback', false !== strpos( $cron_src, "add_action( 'tcgiant_sync_weekly_full_retry', array( \$this, 'run_weekly_full_retry' ) );" ), true );
check( '  the hook is the importer\'s constant', TCGiant_Sync_Importer::WEEKLY_RETRY_HOOK, 'tcgiant_sync_weekly_full_retry' );
check( '  deactivating clears it, and deletes the note', array( false !== strpos( $cron_src, "wp_clear_scheduled_hook( 'tcgiant_sync_weekly_full_retry' );" ), 1 === preg_match( "/public static function deactivate\(\) \{[^}]*delete_option\( 'tcgiant_sync_weekly_retry_since' \);/s", $cron_src ) ), array( true, true ) );
$uninstall = (string) file_get_contents( $ROOT . '/uninstall.php' );
check( '  uninstall clears it and deletes the note', array( false !== strpos( $uninstall, "'tcgiant_sync_weekly_full_retry'," ), false !== strpos( $uninstall, "'tcgiant_sync_weekly_retry_since'," ) ), array( true, true ) );

echo "\n  $pass passed, $fail failed\n\n";
exit( $fail > 0 ? 1 : 0 );
