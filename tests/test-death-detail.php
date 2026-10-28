<?php
/**
 * Harness: what the record of a death says about where the memory went, and
 * the guard that keeps one listing from filling a request.
 *
 * Jon's WordPress.com shop: every delta run died inside the first listing it
 * started, about 100 s and 500 MB into the request. The plugin's own work on
 * a listing is well under a megabyte; the memcached drop-in was holding a copy
 * of thousands of posts something else on the site read while the product was
 * saved. 3.27.2 emptied those copies every tenth listing, so never in his runs.
 * And the log said only which listing - not the step, nor what was running.
 *
 * Loads the real TCGiant_Sync_Runtime, with WordPress played here. One case
 * runs in a child PHP process that really runs out of memory.
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ . '/' );

$OPTIONS   = array();
$LOGGED    = array();
$FILTERS   = array();
$ACTIONS   = array();
$EXT_CACHE = false;

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['ACTIONS'][] = array( $hook, $callback ); return true; }
function remove_action( $hook, $callback ) {
	$GLOBALS['ACTIONS'] = array_values( array_filter( $GLOBALS['ACTIONS'], function ( $a ) use ( $hook ) { return $a[0] !== $hook; } ) );
	return true;
}
function add_filter( $hook, $callback, $priority = 10 ) { $GLOBALS['FILTERS'][] = array( $hook, $callback ); return true; }
function remove_filter( $hook, $callback, $priority = 10 ) {
	$GLOBALS['FILTERS'] = array_values( array_filter( $GLOBALS['FILTERS'], function ( $f ) use ( $hook ) { return $f[0] !== $hook; } ) );
	return true;
}
function wp_using_ext_object_cache() { return $GLOBALS['EXT_CACHE']; }

class Harness_Wpdb {
	public $options = 'wp_options';
	public function replace( $table, $row, $format ) {
		$GLOBALS['OPTIONS'][ $row['option_name'] ] = unserialize( $row['option_value'] );
		return 1;
	}
}
$wpdb = null;

class TCGiant_Sync_Logger {
	public static function write_now( $m, $l = 'error' ) {
		$GLOBALS['LOGGED'][] = $m;
		if ( defined( 'HARNESS_CHILD' ) ) {
			echo 'LOGGED: ' . $m . "\n";
		}
	}
}

/** Automattic's memcached drop-in, as far as counting and emptying go. */
class Harness_Dropin {
	public $cache     = array();
	public $group_ops = array();
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';

// --- The child: a delta listing that really runs out of memory -------------
//
// WordPress's fatal error handler, and the shutdown action, run before the
// plugin's record of the death, and they read the cache. The guard must not
// empty it then: the record is about to count it.
if ( isset( $argv[1] ) && 'oom-child' === $argv[1] ) {
	define( 'HARNESS_CHILD', true );
	ini_set( 'memory_limit', '64M' );
	$EXT_CACHE       = true;
	$wp_object_cache = new Harness_Dropin();
	register_shutdown_function( function () {
		for ( $i = 0; $i < 600; $i++ ) {
			TCGiant_Sync_Runtime::on_cache_read( false );
		}
		// The shutdown action, as WordPress runs it before the plugin's record.
		TCGiant_Sync_Runtime::make_room();
	} );
	TCGiant_Sync_Runtime::watch( 'Delta sync' );
	register_shutdown_function( function () {
		echo 'LIMIT: ' . ini_get( 'memory_limit' ) . "\n";
	} );
	TCGiant_Sync_Runtime::guard_cache();
	TCGiant_Sync_Runtime::context( 'eBay item 267177202114 (53 of 357)' );
	TCGiant_Sync_Runtime::stage( 'WooCommerce saving the product, with everything hooked to that' );
	for ( $i = 0; $i < 400; $i++ ) {
		$wp_object_cache->cache[ 'posts:' . $i ] = array( 'value' => $i );
	}
	// Something holds what it reads where the guard cannot reach it.
	$held = array();
	for ( $i = 0; ; $i++ ) {
		$held[] = str_repeat( 'x', 65536 );
	}
}

// --- The parent -------------------------------------------------------------

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-74s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

ini_set( 'memory_limit', '512M' );
$_SERVER['REQUEST_TIME_FLOAT'] = 1000.0;
$NOW                           = 1000.0;
$USED                          = 0.1;
TCGiant_Sync_Runtime::$clock        = function () { return $GLOBALS['NOW']; };
$LIVE                          = false;
TCGiant_Sync_Runtime::$memory_probe = function () {
	// Live: what the drop-in's copies hold, read as the guard reads it.
	if ( $GLOBALS['LIVE'] ) {
		$GLOBALS['USED'] = $GLOBALS['BASE'] + count( $GLOBALS['wp_object_cache']->cache ) * $GLOBALS['COPY_SHARE'];
	}
	return (int) ( $GLOBALS['USED'] * TCGiant_Sync_Runtime::memory_limit() );
};

echo "\nA DEATH IN ONE LISTING SAYS WHERE IN IT, AND WHAT WAS RUNNING\n" . str_repeat( '=', 122 ) . "\n";

$wp_object_cache   = new Harness_Dropin();
$wp_current_filter = array();

$NOW = 1002.0;
TCGiant_Sync_Runtime::watch( 'Delta sync' );
$BEGAN_MB = (int) round( memory_get_usage() / 1048576 );
TCGiant_Sync_Runtime::context( 'eBay item 267177202114 (52 of 357 in the changes from 2026-10-08T20:13:02.000Z)' );
TCGiant_Sync_Runtime::stage( 'WooCommerce saving the product, with everything hooked to that' );

// Then, in that save, another plugin's work on every product - seven hooks deep.
$wp_current_filter = array( 'action_scheduler_run_queue', 'tcgiant_sync_fetch_delta_events', 'save_post', 'woocommerce_update_product', 'woocommerce_after_product_object_save', 'some_plugin_reindex', 'some_plugin_each_product' );
for ( $i = 0; $i < 3000; $i++ ) {
	$wp_object_cache->cache[ 'posts:' . $i ] = array( 'value' => 1 );
}
$wp_object_cache->group_ops = array( 'posts' => array_fill( 0, 3000, array( 'get' ) ), 'post_meta' => array_fill( 0, 500, array( 'get' ) ) );

$NOW = 1105.0;
TCGiant_Sync_Runtime::on_shutdown();
$line = $LOGGED[0] ?? '';

check( 'one line is written', count( $LOGGED ), 1 );
check( '  the memory peak PHP held, and how much of it was in use', 1 === preg_match( '/memory peak \d+ MB of 512M \(\d+ MB in use\); time limit/', $line ), true );
check( 'releasing memory hands emptied pages back to the system', 1 === preg_match( "/function release_memory\(\) \{\s*self::drop_copies\(\);.*?gc_mem_caches\(\);/s", file_get_contents( $ROOT . '/includes/class-tcgiant-sync-runtime.php' ) ), true );
check( '  the listing is named', false !== strpos( $line, 'while working on eBay item 267177202114 (52 of 357' ), true );
check( '  and the step it was on', false !== strpos( $line, ' - WooCommerce saving the product, with everything hooked to that. 105 s into the request' ), true );
check( '  when the listing began, and at what memory', false !== strpos( $line, '(the item began 2 s into the request, at ' . $BEGAN_MB . ' MB;' ), true );
check( '  the innermost six hooks WordPress was running, the outermost left off', false !== strpos( $line, 'WordPress hooks running: tcgiant_sync_fetch_delta_events > save_post > woocommerce_update_product > woocommerce_after_product_object_save > some_plugin_reindex > some_plugin_each_product' ) && false === strpos( $line, 'action_scheduler_run_queue' ), true );
check( '  what the object cache held', false !== strpos( $line, 'object cache holding 3000 keys, 3500 operations recorded' ), true );

// No item, no step, no hooks, WordPress's own cache: nothing made up.
$LOGGED            = array();
$wp_current_filter = array( 'shutdown' );
$wp_object_cache   = new stdClass();
TCGiant_Sync_Runtime::watch( 'Image localiser' );
TCGiant_Sync_Runtime::on_shutdown();
$line = $LOGGED[0] ?? '';
check( 'a job with no item, no step, no hooks: one line, none of them claimed', array( count( $LOGGED ), false !== strpos( $line, 'Image localiser ended without finishing' ), false !== strpos( $line, 'began' ), false !== strpos( $line, 'hooks' ), false !== strpos( $line, 'object cache' ), false !== strpos( $line, ' - ' ) ), array( 1, true, false, false, false, false ) );

// A new item starts with no step said.
$LOGGED = array();
TCGiant_Sync_Runtime::watch( 'Delta sync' );
TCGiant_Sync_Runtime::context( 'eBay item 1' );
TCGiant_Sync_Runtime::stage( 'saving the product' );
TCGiant_Sync_Runtime::context( 'eBay item 2' );
TCGiant_Sync_Runtime::on_shutdown();
check( 'the next item starts with no step', array( count( $LOGGED ), false !== strpos( $LOGGED[0] ?? '', 'while working on eBay item 2.' ), false !== strpos( $LOGGED[0] ?? '', 'saving the product' ) ), array( 1, true, false ) );

// After the loop the job names no item, and says no item began.
$LOGGED = array();
TCGiant_Sync_Runtime::watch( 'Delta sync' );
TCGiant_Sync_Runtime::context( 'eBay item 3' );
TCGiant_Sync_Runtime::context( '' );
TCGiant_Sync_Runtime::on_shutdown();
check( 'a death after the last item: no item named, none said to have begun', array( count( $LOGGED ), false !== strpos( $LOGGED[0] ?? '', 'while working on' ), false !== strpos( $LOGGED[0] ?? '', 'began' ) ), array( 1, false, false ) );

echo "\nA DEATH THAT REALLY RUNS OUT OF MEMORY (A CHILD PROCESS)\n" . str_repeat( '=', 122 ) . "\n";

$child = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=0 -d log_errors=0 ' . escapeshellarg( __FILE__ ) . ' oom-child 2>&1' );
preg_match( '/^LOGGED: (.*)$/m', $child, $m );
$line = $m[1] ?? '';
check( 'the death is recorded', false !== strpos( $line, 'Delta sync stopped by a PHP fatal error: Allowed memory size of 67108864 bytes exhausted' ), true );
check( '  with the limit that was reached, not the one raised to write the record', 1 === preg_match( '/memory peak (\d+) MB of 64M \((\d+) MB in use\);/', $line, $pk ), true );
check( '  the peak the limit is held against: what PHP had taken, at the limit', (int) ( $pk[1] ?? 0 ) >= 60, true );
preg_match( '/^LIMIT: (.*)$/m', $child, $lm );
check( '  raised once to write it, though make_room() ran twice', trim( $lm[1] ?? '' ), (string) ( 67108864 + TCGiant_Sync_Runtime::AFTER_DEATH_HEADROOM ) );
check( '  the cache still holding what it held when the request died', false !== strpos( $line, 'object cache holding 400 keys' ), true );
check( '  the guard did not empty it on the way out', 1 === preg_match( '/object-cache reads during the item, the cache emptied 0 time\(s\)/', $line ), true );
if ( '' === $line ) {
	echo $child;
}

echo "\nA JOB THAT STOPS FOR MEMORY TELLS THE QUEUE TO STOP, FOR THAT QUEUE RUN\n" . str_repeat( '=', 122 ) . "\n";

$FILTERS = array();
$ACTIONS = array();
TCGiant_Sync_Runtime::end_queue_runs();
check( 'Action Scheduler is told this queue run is spent', array( array_column( $FILTERS, 0 ), array_unique( array_column( $FILTERS, 1 ) ) ), array( array( 'action_scheduler_memory_exceeded', 'action_scheduler_maximum_execution_time_likely_to_be_exceeded' ), array( '__return_true' ) ) );
check( '  until that queue run is over', array_column( $ACTIONS, 0 ), array( 'action_scheduler_after_process_queue' ) );
TCGiant_Sync_Runtime::resume_queue_runs();
check( '  then a later one in the same process is not held to it', array( $FILTERS, $ACTIONS ), array( array(), array() ) );

echo "\nTHE CACHE GUARD EMPTIES THE CACHE'S COPIES WHEN ONE ITEM FILLS THE REQUEST\n" . str_repeat( '=', 122 ) . "\n";

/** Reads through the drop-in: the look comes first, then the read leaves its copy. */
function reads( $n, $grow = 0.0 ) {
	static $k = 0;
	for ( $i = 0; $i < $n; $i++ ) {
		TCGiant_Sync_Runtime::on_cache_read( false );
		$GLOBALS['wp_object_cache']->cache[ 'posts:' . ( $k++ ) ] = array( 'value' => 1 );
		$GLOBALS['USED'] += $grow;
	}
}

/** release_memory() empties the drop-in; the memory the copies held goes with them. */
$COPY_SHARE = 0.0;
function sync_used() {
	$GLOBALS['USED'] = $GLOBALS['BASE'] + count( $GLOBALS['wp_object_cache']->cache ) * $GLOBALS['COPY_SHARE'];
}

$EXT_CACHE = false;
$FILTERS   = array();
TCGiant_Sync_Runtime::guard_cache();
check( 'no persistent object cache: the guard stays off', $FILTERS, array() );

$EXT_CACHE       = true;
$wp_object_cache = new Harness_Dropin();
TCGiant_Sync_Runtime::guard_cache();
check( 'a persistent object cache: watched through pre_wp_cache_get', array_column( $FILTERS, 0 ), array( 'pre_wp_cache_get' ) );
check( '  a read\'s value passes through untouched', array( TCGiant_Sync_Runtime::on_cache_read( false ), TCGiant_Sync_Runtime::on_cache_read( 'kept' ) ), array( false, 'kept' ) );

// A listing whose save reads posts by the thousand, each copy 0.02% of the
// limit, from 12% at its start: Jon's. Emptied each time it passes 50%.
$BASE                   = 0.12;
$COPY_SHARE             = 0.0002;
$wp_object_cache->cache = array();
$LIVE                   = true;
TCGiant_Sync_Runtime::cache_guard_take();
TCGiant_Sync_Runtime::context( 'eBay item 1' );
$most = 0.0;
for ( $i = 0; $i < 6000; $i++ ) {
	TCGiant_Sync_Runtime::on_cache_read( false );
	$wp_object_cache->cache[ 'posts:' . $i ] = array( 'value' => 1 );
	sync_used();
	$most = max( $most, $USED );
}
$seen = TCGiant_Sync_Runtime::cache_guard_take();
$LIVE = false;
check( 'thousands of reads in one listing: memory never passes 55%', $most < 0.55, true );
check( '  the cache emptied each time it passed half', $seen['releases'] >= 2, true );
check( '  and each emptying gave memory back', $seen['freed'] > 0, true );

// A listing on a site that is simply heavy - 53% before it starts - making a
// few hundred reads that hold little: not the listing's doing, left alone.
$BASE                   = 0.53;
$COPY_SHARE             = 0.00001;
$wp_object_cache->cache = array();
sync_used();
TCGiant_Sync_Runtime::context( 'eBay item 2' );
for ( $i = 0; $i < 600; $i++ ) {
	TCGiant_Sync_Runtime::on_cache_read( false );
	$wp_object_cache->cache[ 'p2:' . $i ] = array( 'value' => 1 );
	sync_used();
}
check( 'a heavy site, an ordinary listing: the cache is left alone', array( count( $wp_object_cache->cache ), TCGiant_Sync_Runtime::cache_guard_take() ), array( 600, array( 'reads' => 600, 'releases' => 0, 'freed' => 0 ) ) );

// The listing grows memory, but not through the cache: emptying it gives
// nothing back. Done once, then not again until memory has grown a tenth more.
$BASE                   = 0.12;
$wp_object_cache->cache = array();
$USED                   = 0.12;
TCGiant_Sync_Runtime::context( 'eBay item 3' );
reads( 1000, 0.0004 );
$seen = TCGiant_Sync_Runtime::cache_guard_take();
check( 'memory held elsewhere: emptied once at most, counted as giving nothing back', array( $seen['releases'] <= 1, $seen['freed'] ), array( true, 0 ) );
$USED = 0.95;
reads( 250 );
check( '  but emptied again once memory has grown well past that', TCGiant_Sync_Runtime::cache_guard_take()['releases'], 1 );

// Emptying gives back a little - 2% of the limit - on a site at 53%: done,
// but not counted as having done any good, and nothing is said.
$BASE                   = 0.53;
$COPY_SHARE             = 0.0001;
$wp_object_cache->cache = array();
$LIVE                   = true;
TCGiant_Sync_Runtime::cache_guard_take();
TCGiant_Sync_Runtime::context( 'eBay item 6' );
$BASE = 0.61; // the listing's own growth, held where emptying cannot reach
for ( $i = 0; $i < 250; $i++ ) {
	TCGiant_Sync_Runtime::on_cache_read( false );
	$wp_object_cache->cache[ 'p6:' . $i ] = array( 'value' => 1 );
}
$seen = TCGiant_Sync_Runtime::cache_guard_take();
$LIVE = false;
check( 'emptying that gives back little: done once, not counted as doing good', array( $seen['releases'], $seen['freed'] ), array( 1, 0 ) );

// Memory held elsewhere, over the line for look after look: emptied once,
// not at every look.
$USED = 0.12;
TCGiant_Sync_Runtime::context( 'eBay item 7' );
$USED = 0.7;
reads( 2000 );
check( 'memory held elsewhere for many looks: emptied once, not at every look', TCGiant_Sync_Runtime::cache_guard_take()['releases'], 1 );

// The record of a death says what the guard saw.
$LOGGED = array();
$USED   = 0.1;
TCGiant_Sync_Runtime::watch( 'Delta sync' );
TCGiant_Sync_Runtime::cache_guard_take();
TCGiant_Sync_Runtime::context( 'eBay item 4' );
reads( 500 );
TCGiant_Sync_Runtime::on_shutdown();
check( 'a death says how many reads the item made and how often the cache was emptied', false !== strpos( $LOGGED[0] ?? '', '500 object-cache reads during the item, the cache emptied 0 time(s)' ), true );

// Once the request is on its way out, reads are neither counted nor acted on.
$USED = 0.99;
TCGiant_Sync_Runtime::watch( 'Delta sync' );
TCGiant_Sync_Runtime::context( 'eBay item 5' );
$wp_object_cache->cache = array( 'a' => 1, 'b' => 2 );
TCGiant_Sync_Runtime::cache_guard_take();
TCGiant_Sync_Runtime::make_room();
reads( 500 );
check( 'after make_room(): the cache is not emptied, the reads not counted', array( count( $wp_object_cache->cache ) >= 2, TCGiant_Sync_Runtime::cache_guard_take() ), array( true, array( 'reads' => 0, 'releases' => 0, 'freed' => 0 ) ) );
TCGiant_Sync_Runtime::unwatch();

// A job turning it on inside another's: off only when both are done.
TCGiant_Sync_Runtime::unguard_cache();
$FILTERS = array();
TCGiant_Sync_Runtime::guard_cache();
TCGiant_Sync_Runtime::guard_cache();
TCGiant_Sync_Runtime::unguard_cache();
check( 'turned on inside another job, and off again: still on for the outer one', array_column( $FILTERS, 0 ), array( 'pre_wp_cache_get' ) );
TCGiant_Sync_Runtime::unguard_cache();
check( 'off again: the filter removed', $FILTERS, array() );
check( '  and a read is not counted', array( TCGiant_Sync_Runtime::on_cache_read( 'x' ), TCGiant_Sync_Runtime::cache_guard_take() ), array( 'x', array( 'reads' => 0, 'releases' => 0, 'freed' => 0 ) ) );

echo "\nIN THE IMPORTER\n" . str_repeat( '=', 122 ) . "\n";

$importer = file_get_contents( $ROOT . '/includes/class-tcgiant-sync-importer.php' );
$mapper   = file_get_contents( $ROOT . '/includes/class-tcgiant-sync-mapper.php' );

/** The source of one method. */
function method_body( $code, $name ) {
	$start = strpos( $code, 'function ' . $name . '(' );
	if ( false === $start ) {
		return '';
	}
	$next = strpos( $code, "\n\t/**", $start );
	return substr( $code, $start, false === $next ? null : $next - $start );
}

$fetch  = method_body( $importer, 'fetch_delta_events' );
$delta  = method_body( $importer, 'run_delta_events' );
$scan   = method_body( $importer, 'process_page_items' );
$single = method_body( $importer, 'process_item_import' );
$settle = method_body( $importer, 'settle_ended_listing' );

check( 'the delta run is guarded, and the guard comes off in its finally', 1 === preg_match( '/Runtime::guard_cache\(\);\s*try \{\s*\$this->run_delta_events\(\);\s*\} finally \{\s*TCGiant_Sync_Runtime::unguard_cache\(\);/', $fetch ), true );
check( '  each listing is reported on, the last too', substr_count( $delta, 'self::report_cache_guard( $current );' ), 2 );
check( '  the cache is released before every listing, ahead of the budget check', false !== strpos( $delta, "TCGiant_Sync_Runtime::release_memory();\n\n\t\t\t// Stop starting listings" ) && false === strpos( $delta, '% 10' ), true );
check( '  no gate before the first listing: a heavy site is not sent round twice', false === strpos( $importer, 'delta_fresh_request_item' ), true );
$stop = substr( $delta, strpos( $delta, "if ( '' !== \$stopped_for ) {\n\t\t\tTCGiant_Sync_Logger::log" ), 900 );
check( '  the queue is told to stop only after a stop for memory', 1 === preg_match( '/if \( \$memory_stop \) \{\s*TCGiant_Sync_Runtime::end_queue_runs\(\);\s*\}/', $stop ) && 2 === substr_count( $importer, 'end_queue_runs()' ), true );
// The full sync's scan hands on the same way since 3.27.6, from the one place it does.
check( '  and the full sync scan, every time it hands on: its next batch would read its page again', 1 === preg_match( "/memory, or of time, if the site lets Action Scheduler run longer\s*(?:\/\/[^\n]*\s*)*TCGiant_Sync_Runtime::end_queue_runs\(\);\s*\}/", method_body( $importer, 'hand_off_scan' ) ), true );
check( '  and a stop is a stop for memory only when time is not up', false !== strpos( $delta, "\$memory_stop = '' === TCGiant_Sync_Runtime::over_time_budget();" ), true );
foreach ( array(
	'asking eBay for the listing (GetItem)',
	'reading eBay\\\'s answer into a product',
	'saving the product',
	'setting its photos',
) as $stage ) {
	check( 'the delta says when it is ' . stripslashes( $stage ), false !== strpos( $delta, "TCGiant_Sync_Runtime::stage( '" . $stage . "' )" ), true );
}
check( 'settling an ended listing says so itself, for every caller', 0 === strpos( trim( substr( $settle, strpos( $settle, '{' ) + 1 ) ), "TCGiant_Sync_Runtime::stage( 'settling the ended listing\\'s stock' );" ), true );
check( 'the mapper says when WooCommerce is saving the product', false !== strpos( $mapper, "TCGiant_Sync_Runtime::stage( 'WooCommerce saving the product, with everything hooked to that' );\n\t\t\t\$saved_id = \$product->save();" ), true );
check( 'the full sync makes each listing its own item, and reports on it', false !== strpos( $scan, "self::report_cache_guard( \$scan_last, 'Full sync' );\n\t\t\t\$scan_last = (string) \$item_id;" ) && 2 === substr_count( $scan, 'report_cache_guard(' ), true );
check( 'a single listing\'s import guards its save, and reports on it', 1 === preg_match( '/guard_cache\(\);\s*try \{\s*\$product_id = \$mapper->save_as_product\( \$product_data \);\s*\} finally \{\s*self::report_cache_guard\( \(string\) \$item_id, \'Import\' \);\s*TCGiant_Sync_Runtime::unguard_cache\(\);/', $single ), true );
check( 'the warning is given only when emptying the cache gave memory back', false !== strpos( method_body( $importer, 'report_cache_guard' ), "\$seen['freed'] <= 0" ), true );
check( 'Fetch Inventory releases the cache before each listing, as the delta does', false !== strpos( $scan, "\$scan_last = (string) \$item_id;\n\t\t\tTCGiant_Sync_Runtime::release_memory();\n\t\t\tTCGiant_Sync_Runtime::context(" ), true );
check( '  and gives the page back as its item after the last listing', 1 === preg_match( "/self::report_cache_guard\\( \\\$scan_last, 'Full sync' \\);\\s*TCGiant_Sync_Runtime::context\\( sprintf\\( 'page %d of %s'/", $scan ), true );
check( 'the delta does not count reading the window against the first listing', 1 === preg_match( '/TCGiant_Sync_Runtime::cache_guard_take\(\);\s*foreach \( \$items as \$ebay_item \) \{/', $delta ), true );
check( 'a single import starts from a released cache', 1 === preg_match( '/release_memory\(\);\s*TCGiant_Sync_Runtime::guard_cache\(\);\s*try \{\s*\$product_id = \$mapper->save_as_product/', $single ), true );

echo "\n3.28.0'S OWN LOOPS AND READS, WITH 3.27.3'S GUARD AND DEATH DETAIL\n" . str_repeat( '=', 122 ) . "\n";

// The pruner saves products as the delta does - settled, trashed when sold
// out, moved on to a relist - and the delta, the sale check and the prune ask
// eBay about a relist before moving a product on to it. A death in any of
// those names the step, and the prune's products are each an item of their own.
$prune  = method_body( $importer, 'prune_orphaned_items' );
$run    = method_body( $importer, 'run_prune' );
$cand   = method_body( $importer, 'check_prune_candidate' );
$unseen = method_body( $importer, 'adopt_unseen_relist' );
$parent = method_body( $mapper, 'relist_parent_state' );
check( 'the prune is watched and guarded, the guard off in its finally', 1 === preg_match( "/Runtime::watch\\( 'Inventory pruning', array\\( __CLASS__, 'on_prune_death' \\) \\);.*?Runtime::guard_cache\\(\\);\\s*try \\{\\s*\\\$this->run_prune\\([^;]*\\);\\s*\\} finally \\{\\s*TCGiant_Sync_Runtime::unguard_cache\\(\\);.*?TCGiant_Sync_Runtime::unwatch\\(\\);/s", $prune ), true );
check( '  each product it asks about is its own item, named, and reported on', false !== strpos( $run, "TCGiant_Sync_Runtime::context( sprintf( 'eBay item %s, WC #%d', \$item_id, \$product_id ) );" ) && 1 === substr_count( $run, "self::report_cache_guard( (string) \$item_id, 'Inventory pruning' );" ), true );
foreach ( array( 'asking eBay about the listing (GetItem)', 'asking eBay about its relist (GetItem)', 'moving the product on to its relist', 'bringing the stock down to what eBay has left' ) as $stage ) {
	check( 'the prune says when it is ' . $stage, false !== strpos( $cand, "TCGiant_Sync_Runtime::stage( '" . $stage . "' );" ), true );
}
check( 'the delta says when it asks eBay whether an ended listing is a relist, before the call', 1 === preg_match( "/Runtime::stage\\( 'asking eBay whether the ended listing is a relist \\(GetItem\\)' \\);\\s*\\\$detail = TCGiant_Sync_API::instance\\(\\)->get_item/", $unseen ), true );
check( '  and when it moves a product on to it', 1 === preg_match( "/Runtime::stage\\( 'moving a product on to it from the listing it was relisted from' \\);\\s*\\\$adopted = \\(int\\) \\\$mapper->adopt_relist\\(/", $unseen ), true );
check( 'the mapper says when it asks how a relist\'s old listing ended, before the call', 1 === preg_match( "/Runtime::stage\\( 'asking eBay how the listing it was relisted from ended \\(GetItem\\)' \\);\\s*\\\$response = TCGiant_Sync_API::instance\\(\\)->get_item\\( \\\$parent_id \\);/", $parent ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
