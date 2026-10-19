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
	public $options    = 'wp_options';
	public $prefix     = 'wp_';
	public $last_error = '';
	/** Post meta, as the key of each row: what the photo cleanup's marks come to. */
	public $meta       = array();
	/** A query containing this fails the way wpdb reports one: false or null, and last_error set. */
	public $refuse     = '';
	/** Rows another request adds just after the UPDATE - a run of the removal still going. */
	public $meanwhile  = array();
	public function query( $sql ) {
		global $QUERIES;
		$QUERIES[] = $sql;
		if ( $this->refused( $sql ) ) {
			return false;
		}
		if ( preg_match( "/UPDATE wp_postmeta SET meta_key = '(\w+)'\s+WHERE meta_key = '(\w+)'/", $sql, $m ) ) {
			foreach ( $this->meta as $i => $key ) {
				if ( $key === $m[2] ) {
					$this->meta[ $i ] = $m[1];
				}
			}
			$this->meta = array_merge( $this->meta, $this->meanwhile );
		} elseif ( preg_match( '/DELETE FROM wp_postmeta\s+WHERE meta_key IN \(([^)]*)\)/', $sql, $m ) ) {
			preg_match_all( "/'(\w+)'/", $m[1], $keys );
			$this->meta = array_values( array_diff( $this->meta, $keys[1] ) );
		}
		return 0;
	}
	public function get_var( $sql ) {
		global $QUERIES;
		$QUERIES[] = $sql;
		if ( $this->refused( $sql ) ) {
			return null;
		}
		if ( preg_match( "/SELECT COUNT\(\*\) FROM wp_postmeta WHERE meta_key = '(\w+)'/", $sql, $m ) ) {
			return (string) count( array_keys( $this->meta, $m[1], true ) );
		}
		return null;
	}
	private function refused( $sql ) {
		$this->last_error = ( '' !== $this->refuse && false !== strpos( $sql, $this->refuse ) ) ? 'Lock wait timeout exceeded; try restarting transaction' : '';
		return '' !== $this->last_error;
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
function run( $settings, $sibling, array $meta = array(), $refuse = '', array $meanwhile = array() ) {
	global $OPTIONS, $DELETED, $CLEARED, $UNSCHEDULED, $QUERIES, $UNINSTALL, $wpdb;
	$OPTIONS = array( 'tcgiant_sync_ebay_settings' => $settings );
	$DELETED = array();
	$CLEARED = array();
	$UNSCHEDULED = array();
	$QUERIES = array();
	$wpdb->meta       = $meta;
	$wpdb->refuse     = $refuse;
	$wpdb->meanwhile  = $meanwhile;
	$wpdb->last_error = '';

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
check( 'default: photos waiting to be removed keep their place', count( array_filter( $QUERIES, function ( $q ) { return false !== strpos( $q, '_tcgiant_delete_' ); } ) ), 0 );

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

// Photos waiting to be removed (3.27.4): unmarked, so they stay where they
// are - but one WordPress had been asked to delete keeps the mark that stops
// the image batch reusing it, and that comes first. One that only had an
// attempt counted, by a run that threw before any deletion began, is whole.
$tcg_to_failed = null;
$tcg_unmarked  = null;
$tcg_counted   = false;
foreach ( $QUERIES as $tcg_i => $tcg_q ) {
	if ( false !== strpos( $tcg_q, "SET meta_key = '_tcgiant_delete_failed'" ) && false !== strpos( $tcg_q, "meta_key = '_tcgiant_delete_started'" ) ) {
		$tcg_to_failed = $tcg_i;
	}
	if ( false !== strpos( $tcg_q, "SET meta_key = '_tcgiant_delete_failed'" ) && false !== strpos( $tcg_q, '_tcgiant_delete_attempts' ) ) {
		$tcg_counted = true;
	}
	if ( false !== strpos( $tcg_q, 'DELETE' ) && false !== strpos( $tcg_q, "'_tcgiant_delete_after'" ) && false !== strpos( $tcg_q, "'_tcgiant_delete_attempts'" ) && false !== strpos( $tcg_q, "'_tcgiant_delete_approved'" ) ) {
		$tcg_unmarked = $tcg_i;
	}
}
check( 'answered Yes: photos waiting to be removed are unmarked, attempts and all', null !== $tcg_unmarked, true );
check( 'answered Yes: one WordPress was asked to delete is never reused', null !== $tcg_to_failed && $tcg_to_failed < $tcg_unmarked, true );
check( 'answered Yes: one that only had an attempt counted is not given up on', $tcg_counted, false );
check( 'answered Yes: a photo given up on keeps its mark', count( array_filter( $QUERIES, function ( $q ) { return false !== strpos( $q, 'DELETE' ) && false !== strpos( $q, '_tcgiant_delete_failed' ); } ) ), 0 );

// The same on a site with photos in every state of the queue: waiting, an
// attempt counted, a deletion begun, queued by the button, and one given up
// on before.
$tcg_marks = array( '_tcgiant_delete_after', '_tcgiant_delete_attempts', '_tcgiant_delete_started', '_tcgiant_delete_approved', '_tcgiant_delete_after', '_tcgiant_delete_failed' );
run( array( 'delete_data_on_uninstall' => '1' ), false, $tcg_marks );
check( 'answered Yes: the queue goes; the one whose deletion began is given up on', $wpdb->meta, array( '_tcgiant_delete_failed', '_tcgiant_delete_failed' ) );

// The database refuses the change to given up on - a lock waited on too long,
// a full disk. Taking the other marks off would leave that photo unmarked,
// for the image batch to hand to a new product with part of it gone.
run( array( 'delete_data_on_uninstall' => '1' ), false, $tcg_marks, "SET meta_key = '_tcgiant_delete_failed'" );
check( 'answered Yes, the change to given up on refused: every mark stays', $wpdb->meta, $tcg_marks );
run( array( 'delete_data_on_uninstall' => '1' ), false, $tcg_marks, "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_tcgiant_delete_started'" );
check( '  and when it cannot say whether the change went in', $wpdb->meta, array( '_tcgiant_delete_after', '_tcgiant_delete_attempts', '_tcgiant_delete_failed', '_tcgiant_delete_approved', '_tcgiant_delete_after', '_tcgiant_delete_failed' ) );
// A deletion still going in another request writes its mark just after the
// change: that photo is not left with no mark either.
run( array( 'delete_data_on_uninstall' => '1' ), false, $tcg_marks, '', array( '_tcgiant_delete_started' ) );
check( '  and when a deletion begins just after it', $wpdb->meta, array( '_tcgiant_delete_after', '_tcgiant_delete_attempts', '_tcgiant_delete_failed', '_tcgiant_delete_approved', '_tcgiant_delete_after', '_tcgiant_delete_failed', '_tcgiant_delete_started' ) );
check( '  the rest of the clean slate goes on all the same', array( dropped_table(), in_array( 'tcgiant_sync_ebay_settings', $DELETED, true ) ), array( true, true ) );

// Every option the photo cleanup keeps goes - the notes of a reading or a
// deletion in progress among them - read from the class, so that one added
// later and forgotten here fails.
preg_match_all( "/const \w+_OPTION = '(tcgiant_[a-z_]+)'/", file_get_contents( __DIR__ . '/../includes/class-tcgiant-sync-photo-cleanup.php' ), $tcg_opts );
check( 'answered Yes: every option of the photo cleanup is deleted', array( count( $tcg_opts[1] ) >= 8, array_values( array_diff( $tcg_opts[1], $DELETED ) ) ), array( true, array() ) );

// The same for the site filter's three options (3.27.5), written out by hand
// in uninstall.php: all three could go from its list and nothing failed. Its
// transients have no list of their own - they go because their names fall
// under the plugin's transient sweep, so each is matched against the sweep's
// own LIKE patterns, both the value and its timeout.
$tcg_sites = file_get_contents( __DIR__ . '/../includes/class-tcgiant-sync-sites.php' );
preg_match_all( "/const \w+_OPTION = '(tcgiant_[a-z_]+)'/", $tcg_sites, $tcg_opts );
check( 'answered Yes: every option of the site filter is deleted', array( count( $tcg_opts[1] ), array_values( array_diff( $tcg_opts[1], $DELETED ) ) ), array( 3, array() ) );
preg_match_all( "/const \w+_TRANSIENT = '([a-z_]+)'/", $tcg_sites, $tcg_prefixes );
$tcg_likes = array();
foreach ( $QUERIES as $tcg_q ) {
	if ( false !== strpos( $tcg_q, '_transient' ) && preg_match_all( "/LIKE '([^']+)'/", $tcg_q, $tcg_m ) ) {
		$tcg_likes = array_merge( $tcg_likes, $tcg_m[1] );
	}
}
$tcg_swept = function ( $option_name ) use ( $tcg_likes ) {
	foreach ( $tcg_likes as $tcg_like ) {
		// LIKE to a pattern: \_ is a literal underscore, _ any one character, % anything.
		$tcg_re = '';
		foreach ( preg_split( '/(\\\\_|_|%)/', $tcg_like, -1, PREG_SPLIT_DELIM_CAPTURE ) as $tcg_part ) {
			$tcg_re .= '\\_' === $tcg_part ? '_' : ( '_' === $tcg_part ? '.' : ( '%' === $tcg_part ? '.*' : preg_quote( $tcg_part, '/' ) ) );
		}
		if ( preg_match( '/^' . $tcg_re . '$/s', $option_name ) ) {
			return true;
		}
	}
	return false;
};
$tcg_left = array();
foreach ( $tcg_prefixes[1] as $tcg_prefix ) {
	foreach ( array( '_transient_', '_transient_timeout_' ) as $tcg_kind ) {
		if ( ! $tcg_swept( $tcg_kind . $tcg_prefix . '110000000001' ) ) {
			$tcg_left[] = $tcg_kind . $tcg_prefix;
		}
	}
}
check( '  and its transients, by the plugin\'s transient sweep', array( count( $tcg_prefixes[1] ), $tcg_left ), array( 3, array() ) );

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
