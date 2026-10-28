<?php
/**
 * Harness: the one pass over galleries already showing the main photo twice.
 *
 * A product whose photos were fetched over more than one image batch showed
 * its main photo twice: as the main image and again in its gallery. 3.27.2
 * fixed the cause, and put right a product already showing it the next time
 * its photos were fetched - for most products, never. 3.28.0 applies 3.27.2's
 * rule once to every product the plugin imported photos for, in batches
 * through Action Scheduler: a gallery entry that is the product's main photo,
 * and this plugin's copy of it (_tcgiant_source_url), comes out of the
 * gallery. Nothing else: no photo is deleted (Image Cleanup sees to photos
 * nothing uses, and the Bunny offload shops depend on that), the shop's own
 * uploads and order are left alone, and eBay is not asked anything.
 *
 * This loads the real TCGiant_Sync_Image_Localizer against a real SQL
 * database (SQLite, through the SQL the plugin sends MySQL). A run killed part
 * way through a batch is a process of its own (this file again, with
 * --child=kill) that dies there, and the next run carries on from the
 * database it left.
 *
 * @package TCGiant_Sync
 */

$CHILD = null;
foreach ( (array) ( $argv ?? array() ) as $arg ) {
	if ( 0 === strpos( $arg, '--child=' ) ) {
		$CHILD = substr( $arg, 8 );
	}
}

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-84s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 200 ), substr( str_replace( "\n", ' ', var_export( $want, true ) ), 0, 200 ) );
}

if ( ! class_exists( 'SQLite3' ) ) {
	echo "  (SQLite3 is not available in this PHP: skipped)\n\n  0 passed, 0 failed\n";
	exit( 0 );
}

$WORLD = str_replace( '\\', '/', sys_get_temp_dir() ) . '/tcg-gallery-heal-' . substr( md5( __FILE__ ), 0, 8 );
@mkdir( $WORLD, 0777, true );

define( 'ABSPATH', $WORLD . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// ─── the database ─────────────────────────────────────────────────────────────

class Harness_Wpdb {
	public $posts      = 'wp_posts';
	public $postmeta   = 'wp_postmeta';
	public $options    = 'wp_options';
	public $last_error = '';
	/** Called with each query before it runs, when set. */
	public $on_query   = null;
	/** A query containing this fails the way wpdb reports it: an empty result and last_error set. */
	public $silent     = '';
	/** @var SQLite3 */
	public $db;
	public function __construct( $file ) {
		$this->db = new SQLite3( $file );
		$this->db->busyTimeout( 5000 );
		$this->db->exec( 'PRAGMA journal_mode = WAL; PRAGMA synchronous = OFF;' );
	}
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%(%|d|s|f)/', function ( $m ) use ( &$args, &$i ) {
			if ( '%' === $m[1] ) {
				return '%';
			}
			$v = $args[ $i++ ] ?? null;
			return 'd' === $m[1] ? (string) (int) $v : "'" . SQLite3::escapeString( (string) $v ) . "'";
		}, $query );
	}
	private function run( $sql ) {
		$this->last_error = '';
		if ( null !== $this->on_query ) {
			call_user_func( $this->on_query, $sql );
		}
		if ( '' !== $this->silent && false !== strpos( $sql, $this->silent ) ) {
			$this->last_error = 'Lost connection to MySQL server during query';
			return false;
		}
		$res = @$this->db->query( $sql );
		if ( false === $res ) {
			$this->last_error = $this->db->lastErrorMsg();
			fwrite( STDERR, "SQL error: {$this->last_error}\n  in: {$sql}\n" );
		}
		return $res;
	}
	public function get_col( $sql ) {
		$res = $this->run( $sql );
		$out = array();
		if ( $res ) {
			while ( $row = $res->fetchArray( SQLITE3_NUM ) ) {
				$out[] = null === $row[0] ? null : (string) $row[0];
			}
		}
		return $out;
	}
	public function get_var( $sql ) {
		$col = $this->get_col( $sql );
		return $col ? $col[0] : null;
	}
	public function query( $sql ) {
		return false === $this->run( $sql ) ? false : $this->db->changes();
	}
	public function get_row( $sql ) {
		$res = $this->run( $sql );
		$row = $res ? $res->fetchArray( SQLITE3_ASSOC ) : false;
		return $row ? (object) $row : null;
	}
	/** wpdb::update(): one UPDATE, WHERE every column given equals its value; how many rows it changed. */
	public function update( $table, $data, $where ) {
		$set  = array();
		$cond = array();
		foreach ( $data as $col => $value ) {
			$set[] = $col . ' = ' . esc( $value );
		}
		foreach ( $where as $col => $value ) {
			$cond[] = $col . ' = ' . esc( $value );
		}
		$changed = $this->query( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $cond ) );
		if ( $changed > 0 && 'wp_postmeta' === $table ) {
			$GLOBALS['WRITES'][] = array( $where, $data );
		}
		return $changed;
	}
}

function fresh_db() {
	global $wpdb, $WORLD;
	if ( isset( $wpdb ) ) {
		$wpdb->db->close();
	}
	foreach ( array( '', '-wal', '-shm' ) as $tail ) {
		@unlink( $WORLD . '/site.sqlite' . $tail );
	}
	$wpdb = new Harness_Wpdb( $WORLD . '/site.sqlite' );
	$wpdb->db->exec( "
		CREATE TABLE wp_posts ( ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_parent INTEGER NOT NULL DEFAULT 0 );
		CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT );
		CREATE INDEX pm_post ON wp_postmeta ( post_id );
		CREATE INDEX pm_key ON wp_postmeta ( meta_key, meta_value );
		CREATE TABLE wp_options ( option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT );
	" );
	$GLOBALS['LOG']     = array();
	$GLOBALS['QUEUE']   = array();
	$GLOBALS['WRITES']  = array();
	$GLOBALS['OUTSIDE'] = array();
	$GLOBALS['TRANSIENTS'] = array();
	$GLOBALS['OPTION_WRITES'] = array();
	$GLOBALS['OPTION_AUTOLOADS'] = array();
	$GLOBALS['CACHE_DELETES'] = array();
}

function open_db() {
	global $wpdb, $WORLD;
	$wpdb = new Harness_Wpdb( $WORLD . '/site.sqlite' );
}

function q( $sql ) { return $GLOBALS['wpdb']->query( $sql ); }
function esc( $v ) { return "'" . SQLite3::escapeString( (string) $v ) . "'"; }

// ─── WordPress, as far as the pass needs it ───────────────────────────────────

function __( $text ) { return $text; }
function is_wp_error( $thing ) { return false; }
function maybe_serialize( $v ) { return ( is_array( $v ) || is_object( $v ) ) ? serialize( $v ) : $v; }
function maybe_unserialize( $v ) {
	if ( is_string( $v ) && preg_match( '/^(a|O|s|i|b|d):/', $v ) ) {
		$u = @unserialize( $v );
		if ( false !== $u || 'b:0;' === $v ) {
			return $u;
		}
	}
	return $v;
}
function get_option( $name, $default = false ) {
	$v = $GLOBALS['wpdb']->get_var( 'SELECT option_value FROM wp_options WHERE option_name = ' . esc( $name ) );
	return null === $v ? $default : maybe_unserialize( $v );
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['OPTION_WRITES'][ $name ] = ( $GLOBALS['OPTION_WRITES'][ $name ] ?? 0 ) + 1;
	$GLOBALS['OPTION_AUTOLOADS'][ $name ][] = $autoload;
	q( 'INSERT OR REPLACE INTO wp_options ( option_name, option_value, autoload ) VALUES ( ' . esc( $name ) . ', ' . esc( maybe_serialize( $value ) ) . ', ' . esc( false === $autoload ? 'no' : 'yes' ) . ' )' );
	return true;
}
function delete_option( $name ) { q( 'DELETE FROM wp_options WHERE option_name = ' . esc( $name ) ); return true; }
function wp_cache_delete( $key, $group = '' ) { $GLOBALS['CACHE_DELETES'][] = $group . ':' . $key; return true; }
function get_transient( $name ) { return $GLOBALS['TRANSIENTS'][ $name ] ?? false; }
function set_transient( $name, $value, $expiration = 0 ) { $GLOBALS['TRANSIENTS'][ $name ] = $value; return true; }
function get_post_meta( $id, $key = '', $single = false ) {
	$v = $GLOBALS['wpdb']->get_var( 'SELECT meta_value FROM wp_postmeta WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) . ' ORDER BY meta_id LIMIT 1' );
	return null === $v ? ( $single ? '' : array() ) : ( $single ? maybe_unserialize( $v ) : array( maybe_unserialize( $v ) ) );
}
/** WordPress's own rule: with $prev_value, only a row still holding it is changed. */
function update_post_meta( $id, $key, $value, $prev_value = '' ) {
	$GLOBALS['WRITES'][] = array( (int) $id, $key, $value );
	if ( ! empty( $GLOBALS['ON_WRITE'] ) ) {
		call_user_func( $GLOBALS['ON_WRITE'], (int) $id, $key, $value );
	}
	$where = 'post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) . ( '' !== $prev_value ? ' AND meta_value = ' . esc( $prev_value ) : '' );
	$has   = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE {$where}" );
	if ( ! $has ) {
		if ( '' !== $prev_value ) {
			return false;
		}
		q( 'INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( ' . (int) $id . ', ' . esc( $key ) . ', ' . esc( maybe_serialize( $value ) ) . ' )' );
		return true;
	}
	return q( 'UPDATE wp_postmeta SET meta_value = ' . esc( maybe_serialize( $value ) ) . " WHERE {$where}" ) > 0;
}
function delete_post_meta( $id, $key ) { q( 'DELETE FROM wp_postmeta WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) ); return true; }
function get_post_thumbnail_id( $post = null ) { return (int) get_post_meta( (int) $post, '_thumbnail_id', true ); }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['HOOKS'][ $hook ][] = $callback; return true; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return true; }

// What would leave the site: none of it may be called.
function wp_remote_request( $url, $args = array() ) { $GLOBALS['OUTSIDE'][] = $url; return array(); }
function wp_remote_post( $url, $args = array() ) { $GLOBALS['OUTSIDE'][] = $url; return array(); }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['OUTSIDE'][] = $url; return array(); }
function download_url( $url ) { $GLOBALS['OUTSIDE'][] = $url; return ''; }
function media_sideload_image( $url ) { $GLOBALS['OUTSIDE'][] = $url; return 0; }
function wp_delete_attachment( $id, $force = false ) { $GLOBALS['OUTSIDE'][] = 'delete attachment ' . $id; return false; }

// Action Scheduler: each action pending until run, running while it runs.
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	$GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'group' => $group, 'status' => 'pending' );
	return count( $GLOBALS['QUEUE'] );
}
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['QUEUE'] as $a ) {
		if ( $a['hook'] === $hook && ( '' === $group || $a['group'] === $group ) && in_array( $a['status'], array( 'pending', 'running' ), true ) ) {
			return 'running' === $a['status'] ? true : time();
		}
	}
	return false;
}

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) {
		$GLOBALS['LOG'][] = array( $level, $message );
		@file_put_contents( $GLOBALS['WORLD'] . '/activity.log', $level . ': ' . $message . "\n", FILE_APPEND );
	}
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
}

require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-image-localizer.php';

// ─── the shop ─────────────────────────────────────────────────────────────────

/** A photo: the plugin's copy of an eBay photo, or the shop's own upload. */
function photo( $id, $ours = true ) {
	q( "INSERT INTO wp_posts ( ID, post_type, post_status ) VALUES ( {$id}, 'attachment', 'inherit' )" );
	if ( $ours ) {
		q( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( {$id}, '_tcgiant_source_url', 'https://i.ebayimg.com/images/g/{$id}/s-l1600.jpg' )" );
	}
}

/** A product: its main photo and gallery, and whether the plugin imported its photos. */
function product( $id, $main, $gallery, $imported = true ) {
	q( "INSERT INTO wp_posts ( ID, post_type, post_status ) VALUES ( {$id}, 'product', 'publish' )" );
	q( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( {$id}, '_thumbnail_id', '{$main}' )" );
	q( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( {$id}, '_product_image_gallery', " . esc( $gallery ) . ' )' );
	if ( $imported ) {
		q( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( {$id}, '_tcgiant_external_image_urls', " . esc( serialize( array( 'https://i.ebayimg.com/images/g/' . $main . '/s-l1600.jpg' ) ) ) . ' )' );
	}
}

function gallery( $id ) { return (string) get_post_meta( $id, '_product_image_gallery', true ); }
function record() { return get_option( TCGiant_Sync_Image_Localizer::GALLERY_HEAL_OPTION, array() ); }
function photos_left() { return (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'attachment'" ); }
function gallery_writes() { return count( $GLOBALS['WRITES'] ); }
/** Called before each UPDATE of a gallery row, with its SQL. */
function before_gallery_write( $fn ) {
	$GLOBALS['wpdb']->on_query = null === $fn ? null : function ( $sql ) use ( $fn ) {
		if ( 0 === strpos( ltrim( $sql ), 'UPDATE wp_postmeta' ) ) {
			$fn( $sql );
		}
	};
}

/** Run what is queued, as Action Scheduler does, until nothing is: through the hook. */
function run_queue( $limit = 100 ) {
	$runs = 0;
	while ( $runs < $limit ) {
		$i = null;
		foreach ( $GLOBALS['QUEUE'] as $k => $a ) {
			if ( 'pending' === $a['status'] ) {
				$i = $k;
				break;
			}
		}
		if ( null === $i ) {
			break;
		}
		$GLOBALS['QUEUE'][ $i ]['status'] = 'running';
		foreach ( $GLOBALS['HOOKS'][ $GLOBALS['QUEUE'][ $i ]['hook'] ] ?? array() as $cb ) {
			call_user_func( $cb );
		}
		$GLOBALS['QUEUE'][ $i ]['status'] = 'done';
		$runs++;
	}
	return $runs;
}

/** An admin page, ten minutes after the last. */
function admin_page() {
	unset( $GLOBALS['TRANSIENTS']['tcgiant_gallery_heal_tick'] );
	foreach ( $GLOBALS['HOOKS']['admin_init'] ?? array() as $cb ) {
		if ( array( 'TCGiant_Sync_Image_Localizer', 'maybe_queue_gallery_heal' ) === $cb ) {
			call_user_func( $cb );
		}
	}
}

/** A shop of $n imported products, every third showing its main photo twice. */
function big_shop( $n ) {
	global $wpdb;
	$wpdb->db->exec( 'BEGIN' );
	for ( $i = 0; $i < $n; $i++ ) {
		$pid  = 10000 + $i * 10;
		$main = $pid + 1;
		photo( $main );
		photo( $pid + 2 );
		photo( $pid + 3 );
		product( $pid, $main, 0 === $i % 3 ? "{$main}," . ( $pid + 2 ) . ',' . ( $pid + 3 ) : ( $pid + 2 ) . ',' . ( $pid + 3 ) );
	}
	$wpdb->db->exec( 'COMMIT' );
}

// ─── a run killed part way through a batch, in a process of its own ──────────

if ( 'kill' === $CHILD ) {
	open_db();
	$GLOBALS['QUEUE'] = array();
	$GLOBALS['HOOKS'] = array();
	$healed = 0;
	// The host ends the request as the fifth product's gallery is about to be written.
	before_gallery_write( function () use ( &$healed ) {
		if ( ++$healed === 5 ) {
			exit( 9 );
		}
	} );
	TCGiant_Sync_Image_Localizer::run_gallery_heal();
	exit( 0 );
}

$HOOKS = array();
TCGiant_Sync_Image_Localizer::instance();

echo "\nTHE MAIN PHOTO COMES OUT OF THE GALLERY, AND NOTHING ELSE CHANGES\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
foreach ( array( 101, 102, 103, 111, 112, 121, 122, 123, 131, 132, 141, 142, 151, 152 ) as $a ) {
	photo( $a );
}
photo( 133, false );             // the shop's own upload
photo( 143, false );
product( 100, 101, '101,102,103' );       // showing its main photo twice
product( 110, 111, '112' );               // fine
product( 120, 121, '123,121,122' );       // twice, arranged by hand: the rest keep their order
product( 130, 131, '133,132' );           // the shop's own photo first, no duplicate
product( 140, 143, '143,141,142' );       // the main photo is the shop's own upload, put in the gallery too: theirs to keep
product( 150, 151, '151,152', false );    // not photos the plugin imported
product( 160, 161, '161,161,162' );       // the plugin's copy twice over in the gallery
photo( 161 );
photo( 162 );
$before = photos_left();

check( 'the pass is registered: an admin request queues it, Action Scheduler runs it', array( in_array( array( 'TCGiant_Sync_Image_Localizer', 'maybe_queue_gallery_heal' ), $HOOKS['admin_init'] ?? array(), true ), $HOOKS[ TCGiant_Sync_Image_Localizer::GALLERY_HEAL_HOOK ] ?? null ), array( true, array( array( 'TCGiant_Sync_Image_Localizer', 'run_gallery_heal' ) ) ) );
admin_page();
check( 'the first admin request after the update queues the pass', array_column( $QUEUE, 'hook' ), array( TCGiant_Sync_Image_Localizer::GALLERY_HEAL_HOOK ) );
admin_page();
check( '  once: not again while it waits', count( $QUEUE ), 1 );
$kept  = $QUEUE;
$QUEUE = array();
TCGiant_Sync_Image_Localizer::maybe_queue_gallery_heal();
check( '  an admin request within ten minutes of the last does not even look', $QUEUE, array() );
$QUEUE = $kept;
run_queue();

check( 'a product showing its main photo twice: it comes out of the gallery', gallery( 100 ), '102,103' );
check( '  the main photo is still the main photo', get_post_thumbnail_id( 100 ), 101 );
check( 'one that does not: untouched', gallery( 110 ), '112' );
check( 'one the shop arranged by hand: the duplicate out, the rest in their order', gallery( 120 ), '123,122' );
check( 'the shop\'s own arrangement with no duplicate: untouched', gallery( 130 ), '133,132' );
check( 'the shop\'s own main photo, put in the gallery too: left alone', gallery( 140 ), '143,141,142' );
check( 'a product whose photos the plugin did not import: left alone', gallery( 150 ), '151,152' );
check( 'the plugin\'s copy of the main photo twice over: both out', gallery( 160 ), '162' );
check( 'only the changed galleries were written', gallery_writes(), 3 );
check( 'no photo deleted', array( photos_left(), $before ), array( $before, $before ) );
check( 'nothing asked of eBay or anywhere else, nothing downloaded or deleted', $OUTSIDE, array() );
check( 'done, and recorded', array( ! empty( record()['done'] ), record()['healed'] ?? null, record()['checked'] ?? null ), array( true, 3, 6 ) );
check( 'one line in the log, with the count', array( count( $LOG ), $LOG[0][1] ?? '' ), array( 1, 'Gallery check: 3 of 6 imported product(s) showed their main photo again in the gallery; it was taken out of the gallery, and no photo was deleted.' ) );

echo "\nIT RUNS ONCE\n" . str_repeat( '=', 124 ) . "\n";

// A duplicate made after the pass is the image batch's to put right, as it
// has since 3.27.2: the pass does not come back for it.
q( "UPDATE wp_postmeta SET meta_value = '111,112' WHERE post_id = 110 AND meta_key = '_product_image_gallery'" );
$QUEUE = array();
admin_page();
check( 'an admin request after it is done: nothing queued', $QUEUE, array() );
TCGiant_Sync_Image_Localizer::run_gallery_heal();
check( 'run again by hand: does nothing', array( gallery( 110 ), count( $LOG ) ), array( '111,112', 1 ) );

echo "\nIN BATCHES, EACH QUEUING THE NEXT; A RUN KILLED PART WAY CARRIES ON\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
big_shop( 450 );
$twice = 150;
admin_page();
$runs = run_queue();
check( sprintf( '450 products: %d batches of up to %d, and one to find the end', $runs - 1, TCGiant_Sync_Image_Localizer::GALLERY_HEAL_BATCH ), $runs, 4 );
check( '  every third put right', array( record()['healed'] ?? null, record()['checked'] ?? null ), array( $twice, 450 ) );
check( '  the others untouched', gallery_writes(), $twice );
check( '  still one line in the log', count( $LOG ), 1 );
check( '  its record written once a batch, not after each product, and never autoloaded', array( $OPTION_WRITES[ TCGiant_Sync_Image_Localizer::GALLERY_HEAL_OPTION ] ?? 0, array_values( array_unique( $OPTION_AUTOLOADS[ TCGiant_Sync_Image_Localizer::GALLERY_HEAL_OPTION ] ?? array() ) ), $wpdb->get_var( "SELECT autoload FROM wp_options WHERE option_name = 'tcgiant_sync_gallery_heal'" ) ), array( 4, array( false ), 'no' ) );
check( '  each gallery written by its own row, the cache for it emptied', array( count( array_filter( $WRITES, function ( $w ) { return isset( $w[0]['meta_id'], $w[0]['meta_value'] ); } ) ), count( array_filter( $CACHE_DELETES, function ( $k ) { return 0 === strpos( $k, 'post_meta:' ); } ) ) ), array( $twice, $twice ) );

// The same shop, its run killed as the fifth gallery is written.
fresh_db();
big_shop( 450 );
admin_page();
$QUEUE[0]['status'] = 'running';
$out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child=kill 2>&1', $out, $code );
open_db();
check( 'a run killed as it was about to write the fifth gallery: it died', $code, 9 );
$fixed = 0;
foreach ( array( 0, 3, 6, 9, 12 ) as $i ) {
	$fixed += 0 === strpos( gallery( 10000 + $i * 10 ), ( 10000 + $i * 10 + 1 ) . ',' ) ? 0 : 1;
}
check( '  the four before it are put right, the fifth not', $fixed, 4 );
check( '  its record is kept once a batch: none yet, and the batch noted as under way', array( record(), false !== get_option( TCGiant_Sync_Image_Localizer::GALLERY_HEAL_OPEN_OPTION, false ) ), array( array(), true ) );
// Action Scheduler marks the killed action failed; the next admin request
// queues the pass again.
$QUEUE[0]['status'] = 'failed';
admin_page();
check( 'the next admin request queues it again', count( array_filter( $QUEUE, function ( $a ) { return 'pending' === $a['status']; } ) ), 1 );
$WRITES = array();
run_queue();
check( '  it carries on from the batch it was in, and finishes', ! empty( record()['done'] ), true );
check( '  the four put right before the kill are not in the count; the rest are, once', record()['healed'] ?? null, $twice - 4 );
check( '  every product checked', record()['checked'] ?? null, 450 );
check( '  the log line says a run was stopped part way, and by how many it may be short', $LOG[0][1] ?? '', 'Gallery check: 146 of 450 imported product(s) showed their main photo again in the gallery; it was taken out of the gallery, and no photo was deleted. A run was stopped part way 1 time(s), so up to 200 more may have been put right than counted.' );
check( '  the note of a batch under way gone', get_option( TCGiant_Sync_Image_Localizer::GALLERY_HEAL_OPEN_OPTION, false ), false );
$left = 0;
for ( $i = 0; $i < 450; $i += 3 ) {
	$pid = 10000 + $i * 10;
	if ( 0 === strpos( gallery( $pid ), ( $pid + 1 ) . ',' ) ) {
		$left++;
	}
}
check( '  no gallery left showing the main photo twice', $left, 0 );
check( '  and not one written twice', count( $WRITES ), $twice - 4 );
check( '  no photo deleted', photos_left(), 1350 );

echo "\nA GALLERY CHANGED WHILE IT WORKS, AND A DATABASE THAT FAILS A READ\n" . str_repeat( '=', 124 ) . "\n";

// The image batch rewrites a product's gallery between the pass reading it
// and writing it. The batch's own write stands: it applies the same rule.
fresh_db();
photo( 201 );
photo( 202 );
photo( 203 );
product( 200, 201, '201,202' );
before_gallery_write( function () {
	$GLOBALS['wpdb']->db->exec( "UPDATE wp_postmeta SET meta_value = '202,203' WHERE post_id = 200 AND meta_key = '_product_image_gallery'" );
} );
TCGiant_Sync_Image_Localizer::run_gallery_heal();
before_gallery_write( null );
check( 'a gallery rewritten meanwhile is not written over', gallery( 200 ), '202,203' );
check( '  nor counted', record()['healed'] ?? null, 0 );

// The mapper moves a trashed product's photos to the product that replaces
// it, and deletes the trashed one's gallery, while the pass holds it. It is
// not put back.
fresh_db();
photo( 301 );
photo( 302 );
photo( 303 );
product( 300, 301, '301,302,303' );
q( "UPDATE wp_posts SET post_status = 'trash' WHERE ID = 300" );
before_gallery_write( function () {
	$GLOBALS['wpdb']->db->exec( "DELETE FROM wp_postmeta WHERE post_id = 300 AND meta_key = '_product_image_gallery'" );
} );
TCGiant_Sync_Image_Localizer::run_gallery_heal();
before_gallery_write( null );
check( 'a gallery deleted while the pass held it: not put back', array( (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE post_id = 300 AND meta_key = '_product_image_gallery'" ), record()['healed'] ?? null ), array( 0, 0 ) );

// The main photo changes after the pass read it, before it writes: the
// gallery is left for that product's next gallery write.
fresh_db();
photo( 401 );
photo( 402 );
photo( 403 );
product( 400, 401, '401,402,403' );
$wpdb->on_query = function ( $sql ) {
	static $done = false;
	if ( ! $done && false !== strpos( $sql, "meta_key = '_product_image_gallery'" ) && 0 === strpos( ltrim( $sql ), 'SELECT meta_id' ) ) {
		$done = true;
		$GLOBALS['wpdb']->db->exec( "UPDATE wp_postmeta SET meta_value = '402' WHERE post_id = 400 AND meta_key = '_thumbnail_id'" );
	}
};
TCGiant_Sync_Image_Localizer::run_gallery_heal();
$wpdb->on_query = null;
check( 'the main photo changed while the pass held the gallery: the gallery left as it was', array( gallery( 400 ), record()['healed'] ?? null, gallery_writes() ), array( '401,402,403', 0, 0 ) );

// The list of products cannot be read: nothing is marked done on the strength of it.
fresh_db();
photo( 211 );
product( 210, 211, '211' );
$wpdb->silent = "meta_key = '_tcgiant_external_image_urls'";
TCGiant_Sync_Image_Localizer::run_gallery_heal();
$wpdb->silent = '';
check( 'a read the database fails: not done, nothing queued, nothing changed', array( empty( record()['done'] ), $QUEUE, gallery( 210 ) ), array( true, array(), '211' ) );
admin_page();
run_queue();
check( '  the next admin request queues it again, and it finishes', array( ! empty( record()['done'] ), gallery( 210 ) ), array( true, '' ) );

echo "\nTHE IMAGE BATCH KEEPS THE SAME RULE\n" . str_repeat( '=', 124 ) . "\n";

$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-image-localizer.php' );
check( 'the batch and the pass ask the same question', substr_count( $src, 'self::is_main_photo_copy( $gid, $main_photo )' ) + substr_count( $src, 'self::is_main_photo_copy( $gid, (int) $main_photo )' ), 2 );
check( '  3.27.2\'s rule, word for word', false !== strpos( $src, "return (int) \$gid === (int) \$main_photo && get_post_meta( (int) \$gid, '_tcgiant_source_url', true );" ), true );
preg_match_all( '/function (?:maybe_queue_gallery_heal|run_gallery_heal|heal_gallery)\(.*?\n\t}\n/s', $src, $bodies );
check( 'the pass deletes nothing: no delete call in any of its three functions, but its own note of a batch under way', array( count( $bodies[0] ), preg_match( '/wp_delete|delete_post|unlink/', implode( '', $bodies[0] ) ), preg_match_all( '/delete_option\( ([^)]*) \)/', implode( '', $bodies[0] ), $deleted ), array_unique( $deleted[1] ) ), array( 3, 0, 3, array( 'self::GALLERY_HEAL_OPEN_OPTION' ) ) );

$wpdb->db->close();
foreach ( array( '', '-wal', '-shm' ) as $tail ) {
	@unlink( $WORLD . '/site.sqlite' . $tail );
}
@unlink( $WORLD . '/activity.log' );
@rmdir( $WORLD );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
