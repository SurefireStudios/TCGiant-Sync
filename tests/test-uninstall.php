<?php
/**
 * Harness: uninstall.php.
 *
 * This deletes a merchant's eBay connection and every product-to-listing link.
 * It runs unattended, with no confirmation and no undo, on a site whose owner
 * may only be moving between editions. So the cases that matter are the ones
 * where it must NOT delete.
 *
 * The sibling check is exercised against the real filesystem, because glob()
 * cannot be stubbed and a check that passes only against a fake is worthless.
 */

$PLUGINS = sys_get_temp_dir() . '/tcg-uninstall-harness/plugins';

// --- the world uninstall.php runs in -----------------------------------------
define( 'WP_UNINSTALL_PLUGIN', true );
define( 'WP_PLUGIN_DIR', $PLUGINS );

$OPTIONS = array();
$DELETED = array();
$CLEARED = array();
$UNSCHEDULED = array();
$QUERIES = array();

function get_option( $name, $default = false ) {
	global $OPTIONS;
	return array_key_exists( $name, $OPTIONS ) ? $OPTIONS[ $name ] : $default;
}
function delete_option( $name ) {
	global $DELETED;
	$DELETED[] = $name;
}
function wp_clear_scheduled_hook( $hook ) {
	global $CLEARED;
	$CLEARED[] = $hook;
}
function as_unschedule_all_actions( $hook ) {
	global $UNSCHEDULED;
	$UNSCHEDULED[] = $hook;
}

class FakeWpdb {
	public $options = 'wp_options';
	public $prefix  = 'wp_';
	public function query( $sql ) {
		global $QUERIES;
		$QUERIES[] = $sql;
	}
}
$wpdb = new FakeWpdb();

// --- scaffolding --------------------------------------------------------------
function plugin_dir( $slug, $exists ) {
	global $PLUGINS;
	$dir  = $PLUGINS . '/' . $slug;
	$file = $dir . '/tcgiant-sync.php';
	if ( $exists ) {
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $file, '<?php // stand-in' );
	} else {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	}
}

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-58s got %-7s want %s\n", $ok ? 'PASS' : 'FAIL', $label,
		var_export( $got, true ), var_export( $want, true ) );
}

// uninstall.php resolves relative to itself; use the real path instead.
$UNINSTALL = __DIR__ . '/../uninstall.php';
function run( $settings, $sibling ) {
	global $OPTIONS, $DELETED, $CLEARED, $UNSCHEDULED, $QUERIES, $UNINSTALL, $wpdb;
	$OPTIONS = array( 'tcgiant_sync_ebay_settings' => $settings );
	$DELETED = array();
	$CLEARED = array();
	$UNSCHEDULED = array();
	$QUERIES = array();

	plugin_dir( 'tcgiant-sync', true );
	plugin_dir( 'tcgiant-sync-lite', $sibling );

	include $UNINSTALL;
}

function dropped_table() {
	global $QUERIES;
	foreach ( $QUERIES as $q ) {
		if ( false !== strpos( $q, 'DROP TABLE' ) ) {
			return true;
		}
	}
	return false;
}

echo "\nDELETING THE PLUGIN\n" . str_repeat( '=', 92 ) . "\n";

// 1. Another edition is installed. The DATA is untouched — that is the whole
//    point, since the editions share it. The schedule still clears, because the
//    glob matches any folder merely starting with tcgiant-sync, so a kept backup
//    named tcgiant-sync-old reads as a sibling. Guarding the schedule behind
//    that check meant one leftover folder made a real uninstall clear nothing at
//    all. A genuine sibling reschedules its own events on its next load, since
//    schedule_events() runs on init.
run( array( 'delete_data_on_uninstall' => '1' ), true );
check( 'sibling present: no options deleted', $DELETED, array() );
check( 'sibling present: table survives', dropped_table(), false );
check( 'sibling present: schedule still cleared', count( $CLEARED ) > 0, true );
check( 'sibling present: queued jobs still cancelled', count( $UNSCHEDULED ) > 0, true );

echo "\n";

// 2. The ordinary case. Schedule goes, data stays.
run( array(), false );
check( 'default: hooks cleared', count( $CLEARED ) > 0, true );
check( 'default: queued jobs cancelled', count( $UNSCHEDULED ) > 0, true );
check( 'default: settings kept', $DELETED, array() );
check( 'default: table kept', dropped_table(), false );

echo "\n";

// 3. Explicitly turned off.
run( array( 'delete_data_on_uninstall' => '0' ), false );
check( 'answered No: settings kept', $DELETED, array() );
check( 'answered No: table kept', dropped_table(), false );

echo "\n";

// 4. Explicitly asked for.
run( array( 'delete_data_on_uninstall' => '1' ), false );
check( 'answered Yes: connection deleted', in_array( 'tcgiant_sync_ebay_settings', $DELETED, true ), true );
check( 'answered Yes: table dropped', dropped_table(), true );
check( 'answered Yes: hooks still cleared', count( $CLEARED ) > 0, true );

echo "\n  " . str_repeat( '-', 88 ) . "\n";

// --- the hooks it clears must be the hooks the plugin schedules ---------------
run( array(), false );

$src = '';
foreach ( glob( __DIR__ . '/../includes/*.php' ) as $f ) {
	$src .= file_get_contents( $f );
}

// The hook is the last argument, so read each call as far as its semicolon
// rather than letting the match run on into unrelated code.
$scheduled = array();
preg_match_all( "/wp_schedule_(?:single_)?event\s*\(/", $src, $m, PREG_OFFSET_CAPTURE );

foreach ( $m[0] as $hit ) {
	$chunk = substr( $src, $hit[1], 400 );
	$end   = strpos( $chunk, ';' );
	if ( false !== $end ) {
		$chunk = substr( $chunk, 0, $end );
	}
	if ( preg_match_all( "/'(tcgiant_[a-z_]+)'/", $chunk, $sm ) ) {
		$scheduled[] = end( $sm[1] );
	}
}

$scheduled = array_values( array_unique( $scheduled ) );
$missing   = array_values( array_diff( $scheduled, $CLEARED ) );

printf( "  (%d cron hook(s) found in source)\n", count( $scheduled ) );
check( 'every scheduled cron hook is cleared on uninstall', $missing, array() );

// Hooks named by class constant never appear as a literal at the call site,
// so they are the easiest to forget. PUSH_ACTION was forgotten for good.
// Only constants that name a hook. The class also holds option-name constants,
// which are data rather than schedule and are governed by the setting instead.
preg_match_all( "/const\s+\w*(?:ACTION|HOOK|CRON)\w*\s*=\s*'(tcgiant_[a-z_]+)'/", $src, $m2 );

$group_names   = array( 'tcgiant_sync_group', 'tcgiant_sync_imports' );
$const_hooks   = array_values( array_diff( array_unique( $m2[1] ), $group_names ) );
$handled       = array_merge( $CLEARED, $UNSCHEDULED );
$missing_const = array_values( array_diff( $const_hooks, $handled ) );

printf( "  (%d hook(s) named by constant)\n", count( $const_hooks ) );
check( 'hooks named by constant are cleared or cancelled', $missing_const, array() );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );

// tidy up
plugin_dir( 'tcgiant-sync', false );
plugin_dir( 'tcgiant-sync-lite', false );

exit( $fail > 0 ? 1 : 0 );
