<?php
/**
 * Harness: what a long job hands back between items.
 *
 * A shop on WordPress.com had its delta sync die at 512 MB, twice, in two
 * places that are not the plugin's: first inside the memcached object cache
 * drop-in, then in wp_debug_backtrace_summary(). Both are records that grow
 * with every lookup until the request ends:
 *
 * - Automattic's memcached drop-in keeps a copy of every key, a record of
 *   every operation, and the call stack of every operation slower than 5 ms;
 * - with SAVEQUERIES on, wpdb keeps every query with the call stack that
 *   made it.
 *
 * 3.27.0 emptied the drop-in only where it says it supports flush_runtime,
 * and never touched the query log. 3.27.2 empties both, between items, in
 * the delta, the scan and the image batch. This loads the real
 * TCGiant_Sync_Runtime and plays the drop-ins.
 *
 * @package TCGiant_Sync
 */

$CHILD = in_array( '--without-savequeries', (array) ( $argv ?? array() ), true );

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
if ( ! $CHILD ) {
	define( 'SAVEQUERIES', true );
}

// --- WordPress, as far as the runtime asks ------------------------------------
$EXT      = true;  // wp_using_ext_object_cache()
$SUPPORTS = false; // wp_cache_supports( 'flush_runtime' )
$FLUSHED  = 0;     // wp_cache_flush_runtime() calls

function wp_using_ext_object_cache() { return $GLOBALS['EXT']; }
function wp_cache_supports( $feature ) { return 'flush_runtime' === $feature && $GLOBALS['SUPPORTS']; }
function wp_cache_flush_runtime() { $GLOBALS['FLUSHED']++; return true; }
function wp_debug_backtrace_summary() {
	// What the real one returns from inside a product save: a long string.
	return str_repeat( 'WC_Product_Data_Store_CPT->read_product_data, get_post_meta, get_metadata_raw, wp_cache_get, ', 12 );
}

class Fake_wpdb {
	public $queries;
	public $num_queries = 0;
	public function query( $sql ) {
		$this->num_queries++;
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$this->queries[] = array( $sql, 0.0004, wp_debug_backtrace_summary(), microtime( true ), array() );
		}
	}
}

// The public state of Automattic's wp-memcached object-cache.php, and what
// group_ops_stats() does with every lookup.
class Fake_Memcached_Cache {
	var $cache          = array();
	var $group_ops      = array();
	var $stats          = array( 'get' => 0 );
	var $flush_number   = array( 'wp_' => 1700000000 );
	var $memcache_debug = array();
	var $slow_op_microseconds = 0.005;

	public function get( $key, $group, $time ) {
		$this->stats['get']++;
		$this->cache[ "$group:$key" ] = str_repeat( 'v', 200 );
		if ( $time > $this->slow_op_microseconds ) {
			$this->group_ops['slow-ops'][] = array( 'get', $key, 200, $time, '', $group, wp_debug_backtrace_summary() );
		}
		$this->group_ops[ $group ][] = array( 'get', $key, 200, $time, '' );
	}
}

// WordPress's own cache keeps its store private.
class Private_Cache {
	private $cache = array( 'k' => 'v' );
	public function size() { return count( $this->cache ); }
	public function __isset( $name ) { return true; }
	public function __get( $name ) { return array( 'magic' ); }
}

// Read-only properties exist from PHP 8.1; written so 7.4 can still parse this.
if ( PHP_VERSION_ID >= 80100 ) {
	eval( 'class Readonly_Cache { public function __construct( public readonly array $cache = array( \'k\' => \'v\' ) ) {} }' );
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';

$wpdb = new Fake_wpdb();

if ( $CHILD ) {
	// SAVEQUERIES off: the query log is someone else's.
	$wpdb->queries = array( array( 'SELECT 1' ) );
	$EXT           = false;
	TCGiant_Sync_Runtime::release_memory();
	echo 'KEPT ' . count( $wpdb->queries ) . "\n";
	exit( 0 );
}

/**
 * One listing's worth of work: queries and cache lookups, some slow.
 */
function one_listing( $n ) {
	global $wpdb, $wp_object_cache;
	for ( $q = 0; $q < 40; $q++ ) {
		$wpdb->query( "SELECT meta_value FROM wp_postmeta WHERE post_id = $n AND meta_key = 'k$q'" );
	}
	for ( $g = 0; $g < 60; $g++ ) {
		$wp_object_cache->get( "$n-$g", 'post_meta', 0 === $g % 4 ? 0.009 : 0.001 );
	}
}

echo "\nA MEMCACHED DROP-IN THAT CANNOT FLUSH ITSELF, SAVEQUERIES ON\n" . str_repeat( '=', 124 ) . "\n";

$wp_object_cache = new Fake_Memcached_Cache();
$EXT             = true;
$SUPPORTS        = false;
$FLUSHED         = 0;

for ( $n = 1; $n <= 5; $n++ ) {
	one_listing( $n );
}
check( 'five listings in: the query log holds every query', count( $wpdb->queries ), 200 );
check( '  the drop-in a copy of every key', count( $wp_object_cache->cache ), 300 );
check( '  and a call stack for every slow lookup', count( $wp_object_cache->group_ops['slow-ops'] ), 75 );

TCGiant_Sync_Runtime::release_memory();
check( 'released: the query log is empty', $wpdb->queries, array() );
check( '  the copies of keys are gone', $wp_object_cache->cache, array() );
check( '  the operation records and their call stacks are gone', $wp_object_cache->group_ops, array() );
check( '  what the drop-in needs to reach memcached is kept', $wp_object_cache->flush_number, array( 'wp_' => 1700000000 ) );
check( '  its counters are kept', $wp_object_cache->stats['get'], 300 );
check( '  it was not asked to flush, as it cannot', $FLUSHED, 0 );

$wp_object_cache->get( 'after', 'options', 0.001 );
check( 'the drop-in carries on after', count( $wp_object_cache->cache ), 1 );

echo "\nWHAT IT SAVES OVER A LONG RUN\n" . str_repeat( '=', 124 ) . "\n";

// The delta releases every ten listings. 3.27.0 did nothing here at all.
function grow( $listings, $release_every ) {
	global $wpdb, $wp_object_cache;
	$wpdb            = new Fake_wpdb();
	$wp_object_cache = new Fake_Memcached_Cache();
	gc_collect_cycles();
	$start = memory_get_usage();
	$peak  = 0;
	for ( $n = 1; $n <= $listings; $n++ ) {
		if ( $release_every && 0 === $n % $release_every ) {
			TCGiant_Sync_Runtime::release_memory();
		}
		one_listing( $n );
		$peak = max( $peak, memory_get_usage() - $start );
	}
	return $peak;
}
$kept     = grow( 600, 0 );
$released = grow( 600, 10 );
printf( "      600 listings: %.1f MB held without release, %.1f MB with\n", $kept / 1048576, $released / 1048576 );
check( 'six hundred listings hold over 20 MB without release', $kept > 20 * 1048576, true );
check( '  and under a tenth of that with it', $released < $kept / 10, true );

echo "\nA DROP-IN THAT CAN FLUSH ITSELF\n" . str_repeat( '=', 124 ) . "\n";

$wp_object_cache = new Fake_Memcached_Cache();
$wpdb            = new Fake_wpdb();
$SUPPORTS        = true;
$FLUSHED         = 0;
one_listing( 1 );
TCGiant_Sync_Runtime::release_memory();
check( 'flush_runtime is used', $FLUSHED, 1 );
check( '  and the drop-in is otherwise left to do it', count( $wp_object_cache->cache ), 60 );
check( '  the query log is emptied either way', $wpdb->queries, array() );

echo "\nWHAT IS NEVER TOUCHED\n" . str_repeat( '=', 124 ) . "\n";

// WordPress's built-in cache is the only copy of what it holds.
$EXT             = false;
$SUPPORTS        = false;
$FLUSHED         = 0;
$wp_object_cache = new Fake_Memcached_Cache();
one_listing( 2 );
TCGiant_Sync_Runtime::release_memory();
check( 'no persistent cache: the cache is left whole', count( $wp_object_cache->cache ), 60 );
check( '  and not flushed', $FLUSHED, 0 );

// A drop-in that keeps its store private, with magic that claims otherwise.
$EXT             = true;
$wp_object_cache = new Private_Cache();
$died            = '';
try {
	TCGiant_Sync_Runtime::release_memory();
} catch ( \Throwable $e ) {
	$died = get_class( $e ) . ': ' . $e->getMessage();
}
check( 'a private store: no error', $died, '' );
check( '  and left whole', $wp_object_cache->size(), 1 );

if ( class_exists( 'Readonly_Cache', false ) ) {
	$wp_object_cache = new Readonly_Cache();
	$died            = '';
	try {
		TCGiant_Sync_Runtime::release_memory();
	} catch ( \Throwable $e ) {
		$died = get_class( $e ) . ': ' . $e->getMessage();
	}
	check( 'a read-only store: no error', $died, '' );
	check( '  and left whole', $wp_object_cache->cache, array( 'k' => 'v' ) );
}

$wp_object_cache = null;
$wpdb            = null;
$died            = '';
try {
	TCGiant_Sync_Runtime::release_memory();
} catch ( \Throwable $e ) {
	$died = get_class( $e ) . ': ' . $e->getMessage();
}
check( 'no cache object and no wpdb: no error', $died, '' );

$out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --without-savequeries 2>&1', $out );
check( 'SAVEQUERIES off: the query log is left alone', trim( implode( "\n", $out ) ), 'KEPT 1' );

echo "\nEVERY LONG JOB RELEASES BETWEEN ITEMS\n" . str_repeat( '=', 124 ) . "\n";

$importer  = file_get_contents( $ROOT . '/includes/class-tcgiant-sync-importer.php' );
$localizer = file_get_contents( $ROOT . '/includes/class-tcgiant-sync-image-localizer.php' );

// Before every listing, the first included: Jon's runs died on the first
// listing each, so a release every tenth never ran (3.27.3).
$loop  = substr( $importer, strpos( $importer, 'foreach ( $items as $ebay_item ) {' ), 2600 );
$first = strpos( $loop, 'TCGiant_Sync_Runtime::release_memory();' );
check( 'the delta, before every listing', false !== $first && $first < strpos( $loop, 'if ( $handled > 0 ) {' ), true );
check( '  not only every tenth', false === strpos( $importer, '0 === $handled % 10' ), true );
check( '  and its old flush-only helper is gone', false === strpos( $importer, 'release_request_cache' ), true );

$scan = substr( $importer, strpos( $importer, 'for ( $current_page = $start_page;' ), 400 );
check( 'the scan, between pages', false !== strpos( $scan, "if ( \$pages_this_batch > 0 ) {\n\t\t\t\tTCGiant_Sync_Runtime::release_memory();" ), true );

$batch = substr( $localizer, strpos( $localizer, 'TCGiant_Sync_Runtime::over_budget();' ) - 200, 300 );
check( 'the image batch, between products, before it measures', false !== strpos( $batch, "TCGiant_Sync_Runtime::release_memory();\n\t\t\t\t\$stopped_for = TCGiant_Sync_Runtime::over_budget();" ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
