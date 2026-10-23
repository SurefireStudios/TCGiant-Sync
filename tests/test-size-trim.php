<?php
/**
 * Harness: the old 1,536 px copies of downloaded photos, and their removal.
 *
 * Until 3.27.4 WordPress made a 1,536 px copy of every photo the plugin
 * downloaded at eBay's Original size: about a quarter of each photo's disk, on
 * a shop of 15,000 parts running out of it. 3.27.7 takes it off the photos
 * downloaded before, behind a check and a button on Image Cleanup.
 *
 * Deleting files cannot be undone, so most of this is about what must NOT go,
 * and what a stopped request leaves: the shop's own uploads, eBay stand-ins, a
 * GIF, a photo over 2,048 px, a photo another part of the plugin has in hand,
 * a photo whose files are not on this server, any file something else still
 * names - another size, the photo itself, another Media Library entry - and
 * nothing at all while an offload plugin is at work. A request killed at each
 * step - after the note, after the record, in the files - is finished by the
 * next, and a read the database fails, or a write it refuses, changes nothing
 * on its strength.
 *
 * This loads the real TCGiant_Sync_Size_Trim, with the photo cleanup, the image
 * batch's skip rule and the runtime, against a real SQL database (SQLite,
 * through the same SQL the plugin sends MySQL) and real files in a temporary
 * uploads folder with year and month folders. Photos are made the way
 * WordPress makes them: every registered size smaller than the photo, named
 * after it, in its folder, one file for sizes of the same dimensions. A host
 * killing a request is a PHP fatal, or the process killed outright with no
 * shutdown function run, so those parts run as processes of their own (this
 * file again, with --child), and the death is real.
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
	@set_time_limit( 0 ); // As the photo cleanup's harness: see there.
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-84s got %-16s want %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 220 ), substr( str_replace( "\n", ' ', var_export( $want, true ) ), 0, 220 ) );
}

$ROOT = getenv( 'TCG_ROOT' ) ?: dirname( __DIR__ );

if ( ! class_exists( 'SQLite3' ) ) {
	echo "  (SQLite3 is not available in this PHP: skipped)\n\n  0 passed, 0 failed\n";
	exit( 0 );
}

$WORLD = str_replace( '\\', '/', sys_get_temp_dir() ) . '/tcg-size-trim-' . substr( md5( __FILE__ ), 0, 8 );
@mkdir( $WORLD, 0777, true );

define( 'ABSPATH', $WORLD . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// ─── the database ─────────────────────────────────────────────────────────────

class Harness_Wpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $termmeta = 'wp_termmeta';
	public $options  = 'wp_options';
	public $prefix   = 'wp_';
	public $last_error = '';
	public $queries  = 0;
	/** INSERTs naming any of these fail, as on a database whose disk is full. */
	public $refuse   = array();
	/** A query containing any of these fails the way wpdb reports it: an empty result and last_error set. */
	public $silent   = array();
	/** Called with each query before it runs, when set. */
	public $on_query = null;
	/** Called with each query after it has run, when set. */
	public $after_query = null;
	/** As wpdb sets it on MySQL and MariaDB. Their BINARY is played by SQLite's COLLATE BINARY. */
	public $is_mysql = null;
	public $log      = null;
	private $quiet   = false;
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
			if ( 'd' === $m[1] ) {
				return (string) (int) $v;
			}
			if ( 'f' === $m[1] ) {
				return (string) (float) $v;
			}
			return "'" . SQLite3::escapeString( (string) $v ) . "'";
		}, $query );
	}

	private function run( $sql ) {
		$this->queries++;
		$this->last_error = '';
		if ( is_array( $this->log ) ) {
			$this->log[] = $sql;
		}
		if ( null !== $this->on_query ) {
			call_user_func( $this->on_query, $sql );
		}
		$sql = preg_replace( "/LIKE '((?:[^']|'')*)'/", "LIKE '$1' ESCAPE '\\\\'", $sql );
		if ( $this->is_mysql ) {
			$sql = preg_replace( '/BINARY (\w+) = /', '$1 COLLATE BINARY = ', $sql );
		} elseif ( false !== strpos( $sql, 'BINARY ' ) ) {
			// Not SQL SQLite knows: what a plugin sending MySQL's own to
			// another database would break.
			$this->last_error = 'near "BINARY": syntax error';
			return false;
		}
		foreach ( $this->silent as $needle ) {
			if ( false !== strpos( $sql, $needle ) ) {
				$this->last_error = 'Got error 28 from storage engine';
				return false;
			}
		}
		foreach ( $this->refuse as $needle ) {
			if ( 0 === stripos( ltrim( $sql ), 'INSERT' ) && false !== strpos( $sql, $needle ) ) {
				$this->last_error = 'The table \'wp_postmeta\' is full';
				return false;
			}
		}
		$res = @$this->db->query( $sql );
		if ( false === $res ) {
			$this->last_error = $this->db->lastErrorMsg();
			if ( ! $this->quiet ) {
				fwrite( STDERR, "SQL error: {$this->last_error}\n  in: {$sql}\n" );
			}
		}
		if ( null !== $this->after_query ) {
			call_user_func( $this->after_query, $sql );
		}
		return $res;
	}

	public function get_results( $sql ) {
		$res = $this->run( $sql );
		$out = array();
		if ( $res ) {
			while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
				$out[] = (object) $row;
			}
		}
		return $out;
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
		$res = $this->run( $sql );
		if ( false === $res ) {
			return false;
		}
		return $this->db->changes();
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function suppress_errors( $quiet = true ) {
		$was         = $this->quiet;
		$this->quiet = (bool) $quiet;
		return $was;
	}

	public function replace( $table, $data ) {
		$cols = implode( ',', array_keys( $data ) );
		$vals = implode( ',', array_map( function ( $v ) { return "'" . SQLite3::escapeString( (string) $v ) . "'"; }, $data ) );
		return $this->query( "INSERT OR REPLACE INTO {$table} ({$cols}) VALUES ({$vals})" );
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
		CREATE TABLE wp_posts ( ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_parent INTEGER NOT NULL DEFAULT 0, post_mime_type TEXT NOT NULL DEFAULT '' );
		CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT );
		CREATE INDEX pm_post ON wp_postmeta ( post_id );
		CREATE INDEX pm_key ON wp_postmeta ( meta_key, meta_value );
		CREATE TABLE wp_options ( option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT );
	" );

	$up = $WORLD . '/uploads';
	if ( is_dir( $up ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $up, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
	}
	@mkdir( $up . '/2024/05', 0777, true );
	@mkdir( $up . '/2025/11', 0777, true );

	$GLOBALS['NEXT_ID']  = 100;
	$GLOBALS['LOG']      = array();
	$GLOBALS['DENIED']   = array();
	$GLOBALS['HOOKS']    = array();
	$GLOBALS['wp_filter'] = array();
	$GLOBALS['BASEDIR']  = $up;
	$GLOBALS['DELETED_FILES'] = array();
	$GLOBALS['CACHE_LOG']     = array();
	@unlink( $WORLD . '/activity.log' );
	@set_time_limit( 0 );
}

function open_db() {
	global $wpdb, $WORLD;
	$wpdb = new Harness_Wpdb( $WORLD . '/site.sqlite' );
	$GLOBALS['NEXT_ID'] = (int) $wpdb->get_var( 'SELECT MAX(ID) FROM wp_posts' ) + 1;
}

function q( $sql ) { return $GLOBALS['wpdb']->query( $sql ); }
function esc( $v ) { return "'" . SQLite3::escapeString( (string) $v ) . "'"; }

// ─── WordPress, as far as this needs it ───────────────────────────────────────

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function esc_sql( $s ) { return SQLite3::escapeString( (string) $s ); }
function wp_basename( $path ) { return basename( str_replace( '\\', '/', (string) $path ) ); }
function wp_slash( $v ) { return $v; }
function wp_unslash( $v ) { return $v; }
function wp_get_upload_dir() { return array( 'basedir' => $GLOBALS['BASEDIR'] ); }
function size_format( $bytes, $decimals = 0 ) { return number_format( $bytes / 1024, $decimals ) . ' KB'; }
function wp_date( $format, $ts = null ) { return gmdate( 'Y-m-d H:i', $ts ?? time() ); }
function get_current_user_id() { return 1; }
function wp_get_current_user() { return (object) array( 'user_login' => 'haz' ); }
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function check_ajax_referer( $action ) { return true; }
function current_user_can( $cap ) { return ! in_array( $cap, $GLOBALS['DENIED'] ?? array(), true ); }
function is_multisite() { return false; }
$CACHE_DELETES = 0;
$CACHE_LOG     = array();
function wp_cache_delete( $key, $group = '' ) { $GLOBALS['CACHE_DELETES']++; $GLOBALS['CACHE_LOG'][] = $group . ':' . $key; return true; }
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
class Harness_Json extends Exception {
	public $reply;
	public function __construct( $reply ) { $this->reply = $reply; parent::__construct( 'json' ); }
}
function wp_send_json_success( $data = null ) { throw new Harness_Json( array( 'success' => true, 'data' => $data ) ); }
function wp_send_json_error( $data = null ) { throw new Harness_Json( array( 'success' => false, 'data' => $data ) ); }

// Options, in the table, so a child process sees what its parent wrote.
function get_option( $name, $default = false ) {
	$v = $GLOBALS['wpdb']->get_var( 'SELECT option_value FROM wp_options WHERE option_name = ' . esc( $name ) . ' ORDER BY option_id LIMIT 1' );
	return null === $v ? $default : maybe_unserialize( $v );
}
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( null !== $GLOBALS['wpdb']->get_var( 'SELECT option_id FROM wp_options WHERE option_name = ' . esc( $name ) ) ) {
		return false;
	}
	q( 'INSERT INTO wp_options ( option_name, option_value, autoload ) VALUES ( ' . esc( $name ) . ', ' . esc( maybe_serialize( $value ) ) . ', ' . esc( $autoload ) . ' )' );
	return true;
}
function update_option( $name, $value, $autoload = null ) {
	if ( ! add_option( $name, $value, '', 'no' ) ) {
		q( 'UPDATE wp_options SET option_value = ' . esc( maybe_serialize( $value ) ) . ' WHERE option_name = ' . esc( $name ) );
	}
	return true;
}
function delete_option( $name ) { q( 'DELETE FROM wp_options WHERE option_name = ' . esc( $name ) ); return true; }
function get_site_option( $name, $default = false ) { return $default; }
function get_transient( $name ) { return get_option( '_transient_' . $name ); }
function set_transient( $name, $value, $ttl = 0 ) { return update_option( '_transient_' . $name, $value ); }

/**
 * Post meta, as WordPress's meta API writes it. KILL says where a child
 * process dies: just after a write of the note, at the stage named.
 */
function get_post_meta( $id, $key = '', $single = false ) {
	$vals = $GLOBALS['wpdb']->get_col( 'SELECT meta_value FROM wp_postmeta WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) . ' ORDER BY meta_id' );
	if ( $single ) {
		return $vals ? maybe_unserialize( $vals[0] ) : '';
	}
	return array_map( 'maybe_unserialize', $vals );
}
function add_post_meta( $id, $key, $value, $unique = false ) {
	if ( $unique && null !== $GLOBALS['wpdb']->get_var( 'SELECT meta_id FROM wp_postmeta WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) ) ) {
		return false;
	}
	$ok = q( 'INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( ' . (int) $id . ', ' . esc( $key ) . ', ' . esc( maybe_serialize( $value ) ) . ' )' );
	maybe_die( 'note', $key, $value );
	return false === $ok ? false : (int) $GLOBALS['wpdb']->db->lastInsertRowID();
}
function update_post_meta( $id, $key, $value ) {
	if ( null === $GLOBALS['wpdb']->get_var( 'SELECT meta_id FROM wp_postmeta WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) ) ) {
		return add_post_meta( $id, $key, $value );
	}
	q( 'UPDATE wp_postmeta SET meta_value = ' . esc( maybe_serialize( $value ) ) . ' WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) );
	maybe_die( 'note_' . ( is_array( $value ) ? ( $value['stage'] ?? '' ) : '' ), $key, $value );
	return true;
}
function delete_post_meta( $id, $key ) {
	maybe_die( 'before_note_goes', $key, null );
	q( 'DELETE FROM wp_postmeta WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) );
	return true;
}

/** Die here, if this child was told to. */
function maybe_die( $where, $key, $value ) {
	if ( empty( $GLOBALS['KILL'] ) || '_tcgiant_trim_pending' !== $key || $GLOBALS['KILL'] !== $where ) {
		return;
	}
	'hard' === ( $GLOBALS['HOW'] ?? 'fatal' ) ? kill_for_real() : die_for_real();
}

// Posts.
function get_post( $id ) {
	$rows = $GLOBALS['wpdb']->get_results( 'SELECT * FROM wp_posts WHERE ID = ' . (int) $id );
	return $rows ? $rows[0] : null;
}
function exists( $id ) { return null !== get_post( $id ); }
function new_post( $type, $status = 'inherit', $parent = 0, $mime = '' ) {
	$id = $GLOBALS['NEXT_ID']++;
	q( "INSERT INTO wp_posts ( ID, post_type, post_status, post_parent, post_mime_type ) VALUES ( {$id}, " . esc( $type ) . ', ' . esc( $status ) . ', ' . (int) $parent . ', ' . esc( $mime ) . ' )' );
	return $id;
}

// Hooks, as WordPress keeps them, and its record of the callbacks on each.
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['HOOKS'][ $hook ][] = array( $cb, $args ); return true; }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return add_filter( $hook, $cb, $priority, $args ); }
function apply_filters( $hook, $value, ...$more ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $h ) {
		$value = call_user_func_array( $h[0], array_slice( array_merge( array( $value ), $more ), 0, $h[1] ) );
	}
	return $value;
}
function has_action( $hook, $cb = false ) { return ! empty( $GLOBALS['HOOKS'][ $hook ] ); }
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $h ) {
		call_user_func_array( $h[0], array_slice( $args, 0, $h[1] ) );
	}
}
/** A callback on a hook as WP_Hook keeps it, for what looks at $wp_filter. */
function register_in_wp_filter( $hook, $callback ) {
	if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
		$GLOBALS['wp_filter'][ $hook ] = (object) array( 'callbacks' => array() );
	}
	$GLOBALS['wp_filter'][ $hook ]->callbacks[10][ 'cb' . count( $GLOBALS['wp_filter'][ $hook ]->callbacks[10] ?? array() ) ] = array( 'function' => $callback, 'accepted_args' => 2 );
}

// WP-Cron, in an option, so a child's schedule outlives it.
function wp_next_scheduled( $hook ) {
	$cron = get_option( 'cron', array() );
	return empty( $cron[ $hook ] ) ? false : min( $cron[ $hook ] );
}
function wp_schedule_single_event( $when, $hook ) {
	$cron            = get_option( 'cron', array() );
	$cron[ $hook ][] = (int) $when;
	update_option( 'cron', $cron );
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	$cron = get_option( 'cron', array() );
	unset( $cron[ $hook ] );
	update_option( 'cron', $cron );
	return 0;
}

/** wp_delete_file(): what WordPress unlinks with, and a plugin may veto. */
function wp_delete_file( $file ) {
	$delete = apply_filters( 'wp_delete_file', $file );
	if ( ! empty( $delete ) ) {
		$GLOBALS['DELETED_FILES'][] = str_replace( $GLOBALS['WORLD'] . '/uploads/', '', str_replace( '\\', '/', $delete ) );
		@unlink( $delete );
	}
}
/** wp_delete_file_from_directory(), as WordPress 5.4 has it: the file must be inside the folder named. */
function wp_delete_file_from_directory( $file, $directory ) {
	$real_file      = realpath( $file );
	$real_directory = realpath( $directory );
	if ( false !== $real_file ) {
		$real_file = str_replace( '\\', '/', $real_file );
	}
	if ( false !== $real_directory ) {
		$real_directory = rtrim( str_replace( '\\', '/', $real_directory ), '/' ) . '/';
	}
	if ( false === $real_file || false === $real_directory || 0 !== strpos( $real_file, $real_directory ) ) {
		return false;
	}
	wp_delete_file( $file );
	return true;
}

/**
 * wp_delete_attachment(), in WordPress's order: the record of the files read
 * first; the delete_attachment action; its meta; the row; and only then the
 * files its record names.
 */
function wp_delete_attachment( $id, $force = false ) {
	$post = get_post( $id );
	if ( ! $post ) {
		return null;
	}
	$file = (string) get_post_meta( $id, '_wp_attached_file', true );
	$meta = get_post_meta( $id, '_wp_attachment_metadata', true );

	do_action( 'delete_attachment', $id, $post );

	q( 'DELETE FROM wp_postmeta WHERE post_id = ' . (int) $id );
	q( 'DELETE FROM wp_posts WHERE ID = ' . (int) $id );

	if ( '' !== $file ) {
		$dir = dirname( $GLOBALS['BASEDIR'] . '/' . $file );
		foreach ( (array) ( is_array( $meta ) ? ( $meta['sizes'] ?? array() ) : array() ) as $size ) {
			wp_delete_file( $dir . '/' . $size['file'] );
		}
		wp_delete_file( $GLOBALS['BASEDIR'] . '/' . $file );
	}
	return $post;
}

// The plugin's own neighbours.
class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) {
		$line = "[$level] $message";
		$GLOBALS['LOG'][] = $line;
		file_put_contents( $GLOBALS['WORLD'] . '/activity.log', $line . "\n", FILE_APPEND );
	}
	public static function error( $m ) { self::log( $m, 'error' ); }
	public static function warning( $m ) { self::log( $m, 'warning' ); }
	public static function write_now( $m, $level = 'error' ) { self::log( $m, $level ); }
}
class TCGiant_Sync_Cron {
	public static $dispatched = 0;
	public static function request_dispatch() { self::$dispatched++; }
}

function die_for_real() {
	// A real, uncatchable fatal - what a host's memory limit does.
	ini_set( 'memory_limit', '32M' );
	$GLOBALS['TOO_BIG'] = str_repeat( 'x', 64 * 1048576 );
}

function kill_for_real() {
	// The process ended from outside, with no shutdown function run.
	if ( function_exists( 'posix_kill' ) ) {
		posix_kill( getmypid(), 9 );
	} elseif ( '\\' === DIRECTORY_SEPARATOR ) {
		exec( 'taskkill /F /PID ' . getmypid() . ' 2>NUL' );
	} else {
		exec( 'kill -9 ' . getmypid() );
	}
	sleep( 30 ); // Never reached.
}

// ─── the real classes ─────────────────────────────────────────────────────────

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-image-localizer.php';
require $ROOT . '/includes/class-tcgiant-sync-size-trim.php';

$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
$CLOCK_OFFSET = 0.0;
TCGiant_Sync_Runtime::$clock        = function () { return (float) $_SERVER['REQUEST_TIME_FLOAT'] + $GLOBALS['CLOCK_OFFSET']; };
TCGiant_Sync_Runtime::$memory_probe = function () { return 0; };

function boot() {
	$GLOBALS['HOOKS'] = array();
	foreach ( array( '_instance' => null, 'held' => array(), 'with_photo' => array( 'photos' => 0, 'files' => 0, 'bytes' => 0 ) ) as $name => $value ) {
		$p = new ReflectionProperty( 'TCGiant_Sync_Size_Trim', $name );
		$p->setAccessible( true );
		$p->setValue( null, $value );
	}
	$GLOBALS['CLOCK_OFFSET'] = 0.0;
	TCGiant_Sync_Size_Trim::instance();
}

// ─── the shop ─────────────────────────────────────────────────────────────────

/**
 * The sizes a WooCommerce shop has registered - WordPress's own, the two
 * WordPress 5.3 added, WooCommerce's three - and any a theme adds.
 */
function registered_sizes() {
	return array_merge( array(
		'thumbnail'                     => array( 'width' => 150, 'height' => 150, 'crop' => true ),
		'medium'                        => array( 'width' => 300, 'height' => 300, 'crop' => false ),
		'medium_large'                  => array( 'width' => 768, 'height' => 0, 'crop' => false ),
		'large'                         => array( 'width' => 1024, 'height' => 1024, 'crop' => false ),
		'1536x1536'                     => array( 'width' => 1536, 'height' => 1536, 'crop' => false ),
		'2048x2048'                     => array( 'width' => 2048, 'height' => 2048, 'crop' => false ),
		'woocommerce_thumbnail'         => array( 'width' => 300, 'height' => 300, 'crop' => true ),
		'woocommerce_single'            => array( 'width' => 600, 'height' => 0, 'crop' => false ),
		'woocommerce_gallery_thumbnail' => array( 'width' => 100, 'height' => 100, 'crop' => true ),
	), $GLOBALS['THEME_SIZES'] ?? array() );
}

/** image_resize_dimensions(): the size WordPress makes, or null - it never enlarges. */
function size_of( $w, $h, array $size ) {
	if ( $size['crop'] ) {
		if ( $w <= $size['width'] && $h <= $size['height'] ) {
			return null;
		}
		return array( min( $w, $size['width'] ), min( $h, $size['height'] ) );
	}
	$scale = min( $size['width'] ? $size['width'] / $w : INF, $size['height'] ? $size['height'] / $h : INF );
	if ( $scale >= 1 ) {
		return null;
	}
	return array( (int) round( $w * $scale ), (int) round( $h * $scale ) );
}

/** The bytes a file of a given width is given here: different for every size. */
function bytes_for( $width, $id ) { return 3 * (int) $width + (int) $id; }

/**
 * A photo as WordPress keeps one: its row, its file and the record of its
 * sizes, and their files on disk - one file for sizes of the same dimensions,
 * named after the photo, in its folder. A photo over 2,560 px is scaled, the
 * original kept beside it, and its sizes named after the original.
 *
 * kind:      ours (downloaded by the plugin), theirs (the shop's own) or
 *            standin (an eBay stand-in: no file).
 * dir:       its year and month folder, or '' for the top of uploads.
 * here:      false when no file of it is on this server; 'main' when only the
 *            1,536 px copy has gone.
 * filesize:  whether the record of each size says its bytes (WordPress 6.0+).
 */
function photo( array $o = array() ) {
	$o += array( 'kind' => 'ours', 'dir' => '2024/05', 'w' => 1600, 'h' => 1200, 'ext' => 'jpg', 'mime' => '', 'name' => '', 'here' => true, 'filesize' => true );

	$mime = '' !== $o['mime'] ? $o['mime'] : ( 'gif' === $o['ext'] ? 'image/gif' : 'image/jpeg' );
	$id   = new_post( 'attachment', 'inherit', 0, $mime );
	$stem = '' !== $o['name'] ? $o['name'] : sprintf( 'brake-caliper-%d-ebay-p%dmhaaosw-1600', $id, $id );
	$pre  = '' !== $o['dir'] ? $o['dir'] . '/' : '';
	$url  = sprintf( 'https://i.ebayimg.com/images/g/P%dAAOSw/s-l1600.jpg', $id );

	if ( 'standin' === $o['kind'] ) {
		update_post_meta( $id, '_tcgiant_external_url', $url );
		update_post_meta( $id, '_tcgiant_source_url', $url );
		update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 1600, 'height' => 1200, 'sizes' => array( '1536x1536' => array( 'file' => 'x-1536x1152.jpg', 'width' => 1536, 'height' => 1152 ) ) ) );
		return $id;
	}

	$w        = (int) $o['w'];
	$h        = (int) $o['h'];
	$file     = $pre . $stem . '.' . $o['ext'];
	$meta     = array( 'width' => $w, 'height' => $h, 'file' => $file );
	$on_disk  = array( $stem . '.' . $o['ext'] => bytes_for( $w, $id ) );

	if ( max( $w, $h ) > 2560 ) {
		$k                      = 2560 / max( $w, $h );
		$meta['width']          = (int) round( $w * $k );
		$meta['height']         = (int) round( $h * $k );
		$file                   = $pre . $stem . '-scaled.' . $o['ext'];
		$meta['file']           = $file;
		$meta['original_image'] = $stem . '.' . $o['ext'];
		$on_disk[ $stem . '-scaled.' . $o['ext'] ] = bytes_for( $meta['width'], $id );
	}

	$meta['filesize'] = bytes_for( $meta['width'], $id );
	$meta['sizes']    = array();
	foreach ( registered_sizes() as $name => $size ) {
		$made = size_of( $w, $h, $size );
		if ( ! $made ) {
			continue;
		}
		$name_on_disk            = "{$stem}-{$made[0]}x{$made[1]}." . $o['ext'];
		$meta['sizes'][ $name ]  = array( 'file' => $name_on_disk, 'width' => $made[0], 'height' => $made[1], 'mime-type' => $mime );
		if ( $o['filesize'] ) {
			$meta['sizes'][ $name ]['filesize'] = bytes_for( $made[0], $id );
		}
		$on_disk[ $name_on_disk ] = bytes_for( $made[0], $id );
	}
	$meta['image_meta'] = array( 'aperture' => '0', 'caption' => '', 'title' => '', 'orientation' => '0' );

	if ( 'theirs' !== $o['kind'] ) {
		update_post_meta( $id, '_tcgiant_source_url', $url );
	}
	update_post_meta( $id, '_wp_attached_file', $file );
	update_post_meta( $id, '_wp_attachment_metadata', $meta );

	if ( false !== $o['here'] ) {
		$folder = $GLOBALS['BASEDIR'] . '/' . ( '' !== $o['dir'] ? $o['dir'] . '/' : '' );
		@mkdir( $folder, 0777, true );
		foreach ( $on_disk as $name => $bytes ) {
			if ( 'main' === $o['here'] && $name === ( $meta['sizes']['1536x1536']['file'] ?? '' ) ) {
				continue;
			}
			file_put_contents( $folder . $name, str_repeat( 'J', $bytes ) );
		}
	}

	return $id;
}

function meta_of( $id ) { $m = get_post_meta( $id, '_wp_attachment_metadata', true ); return is_array( $m ) ? $m : array(); }
function sizes_of( $id ) { return array_keys( meta_of( $id )['sizes'] ?? array() ); }
function has_copy( $id ) { return in_array( '1536x1536', sizes_of( $id ), true ); }
function folder_of( $id ) { $f = (string) get_post_meta( $id, '_wp_attached_file', true ); $d = dirname( $f ); return $GLOBALS['BASEDIR'] . ( '.' === $d ? '' : '/' . $d ); }
function copy_path( $id ) { return folder_of( $id ) . '/' . ( meta_of( $id )['sizes']['1536x1536']['file'] ?? 'none' ); }
function note_of( $id ) { $n = get_post_meta( $id, TCGiant_Sync_Size_Trim::PENDING_KEY, true ); return is_array( $n ) ? $n : null; }
function notes() { return (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_tcgiant_trim_pending'" ); }

/** Every file under uploads, by its path there, with its bytes. */
function uploads_now() {
	$out = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['BASEDIR'], FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		$out[ str_replace( '\\', '/', substr( $f->getPathname(), strlen( $GLOBALS['BASEDIR'] ) + 1 ) ) ] = $f->getSize();
	}
	ksort( $out );
	return $out;
}
/** Every row of post meta, as it stands. */
function meta_now() {
	return $GLOBALS['wpdb']->get_results( 'SELECT post_id, meta_key, meta_value FROM wp_postmeta ORDER BY meta_id' );
}

/** The files' day comes: every note's, and the removal's. */
function files_due_now() {
	foreach ( $GLOBALS['wpdb']->get_results( "SELECT meta_id, meta_value FROM wp_postmeta WHERE meta_key = '_tcgiant_trim_pending'" ) as $row ) {
		$note = unserialize( $row->meta_value );
		if ( isset( $note['due'] ) ) {
			$note['due'] = time() - 1;
			q( 'UPDATE wp_postmeta SET meta_value = ' . esc( serialize( $note ) ) . ' WHERE meta_id = ' . (int) $row->meta_id );
		}
	}
	$run = TCGiant_Sync_Size_Trim::run_state();
	if ( $run ) {
		$run['files_due'] = time() - 1;
		update_option( TCGiant_Sync_Size_Trim::RUN_OPTION, $run );
	}
}

/** The pause, over. */
/** The hold of a request killed outright has lapsed: it is four minutes on. */
function hold_lapses() {
	q( "UPDATE wp_options SET option_value = '1|lapsed' WHERE option_name LIKE 'tcgiant_sync_size_trim_lock_%'" );
}

function end_pause() {
	$pause = get_option( TCGiant_Sync_Size_Trim::PAUSE_OPTION, array() );
	if ( ! empty( $pause ) ) {
		$pause['until'] = time() - 1;
		update_option( TCGiant_Sync_Size_Trim::PAUSE_OPTION, $pause );
	}
}

function run_trim() {
	TCGiant_Sync_Size_Trim::instance()->process_run();
	@set_time_limit( 0 );
}

/** Runs until the removal is done or waiting for the files' day, at most $most. */
function run_until_settled( $most = 20 ) {
	for ( $i = 0; $i < $most; $i++ ) {
		run_trim();
		$status = TCGiant_Sync_Size_Trim::run_state()['status'] ?? '';
		if ( 'running' !== $status ) {
			return $i + 1;
		}
	}
	return $most;
}

/** The whole removal: records, then the day, then the files. */
function remove_all() {
	run_until_settled();
	files_due_now();
	run_until_settled();
}

function ajax( $what, array $post = array() ) {
	$_POST = $post;
	try {
		call_user_func( array( 'TCGiant_Sync_Size_Trim', 'ajax_' . $what ) );
	} catch ( Harness_Json $answer ) {
		return $answer->reply;
	}
	return null;
}

/** The check, run to its end from the screen. */
function check_all() {
	$r = ajax( 'check', array( 'restart' => 1 ) );
	for ( $i = 0; $i < 50 && $r && $r['success'] && 'scanning' === $r['data']['check']['status']; $i++ ) {
		$r = ajax( 'check' );
	}
	return $r;
}

/** The check, then the button. */
function check_and_start() {
	$r = check_all();
	return ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ?? '' ) );
}

function logged( $needle ) {
	foreach ( $GLOBALS['LOG'] as $line ) {
		if ( false !== strpos( $line, $needle ) ) {
			return true;
		}
	}
	return false;
}

function in_child( $what ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( '--child=' . $what ) . ' 2>&1';
	exec( $cmd, $out, $code );
	open_db();
	$GLOBALS['LOG'] = is_file( $GLOBALS['WORLD'] . '/activity.log' ) ? file( $GLOBALS['WORLD'] . '/activity.log', FILE_IGNORE_NEW_LINES ) : array();
	return $code;
}

// ─── in a request of its own ──────────────────────────────────────────────────

if ( null !== $CHILD ) {
	open_db();
	$GLOBALS['BASEDIR'] = $WORLD . '/uploads';
	$GLOBALS['HOOKS']   = array();
	boot();
	$parts = explode( ':', $CHILD );
	// run:<where>:<fatal|hard>
	$GLOBALS['KILL'] = $parts[1] ?? '';
	$GLOBALS['HOW']  = $parts[2] ?? 'fatal';
	if ( 'meta' === $GLOBALS['KILL'] ) {
		// Just after the record of sizes is written.
		$GLOBALS['wpdb']->after_query = function ( $sql ) {
			if ( 0 === strpos( $sql, 'UPDATE wp_postmeta SET meta_value' ) && false !== strpos( $sql, 'AND meta_value' ) ) {
				'hard' === $GLOBALS['HOW'] ? kill_for_real() : die_for_real();
			}
		};
	} elseif ( 'files' === $GLOBALS['KILL'] ) {
		// Part way through the files: after the first is deleted.
		add_filter( 'wp_delete_file', function ( $file ) {
			if ( ! empty( $GLOBALS['DELETED_FILES'] ) ) {
				'hard' === $GLOBALS['HOW'] ? kill_for_real() : die_for_real();
			}
			return $file;
		} );
	} elseif ( 'premeta' === $GLOBALS['KILL'] ) {
		// Just before one photo's record of sizes is written, every time.
		$GLOBALS['ON_PHOTO'] = (int) ( $parts[3] ?? 0 );
		$GLOBALS['wpdb']->on_query = function ( $sql ) {
			if ( 0 === strpos( $sql, 'UPDATE wp_postmeta SET meta_value' ) && false !== strpos( $sql, 'AND meta_value' ) && false !== strpos( $sql, 'brake-caliper-' . $GLOBALS['ON_PHOTO'] . '-' ) ) {
				'hard' === $GLOBALS['HOW'] ? kill_for_real() : die_for_real();
			}
		};
	} elseif ( 'reading' === $GLOBALS['KILL'] ) {
		// While the photos of a page are read: before any note is written.
		$GLOBALS['wpdb']->on_query = function ( $sql ) {
			if ( false !== strpos( $sql, 'SELECT ID, post_status, post_mime_type' ) ) {
				'hard' === $GLOBALS['HOW'] ? kill_for_real() : die_for_real();
			}
		};
	}
	run_trim();
	exit( 0 );
}

// ═════════════════════════════════════════════════════════════════════════════

echo "\nWHICH PHOTOS: THE CHECK, AND NOTHING CHANGED BY IT\n" . str_repeat( '=', 126 ) . "\n";

fresh_db();
boot();

$A  = photo();                                                  // Original size, May 2024
$B  = photo( array( 'dir' => '2025/11', 'filesize' => false ) ); // November 2025, no filesize in its record
$C  = photo( array( 'dir' => '', 'w' => 1600, 'h' => 1600 ) );  // the top of uploads, square
$E  = photo( array( 'w' => 2048, 'h' => 1536 ) );                // 2,048 px: still the rule's
$S  = photo( array( 'w' => 1200, 'h' => 900 ) );                 // 1,200 px: never had the copy
$G  = photo( array( 'ext' => 'gif' ) );                          // a GIF
$GJ = photo( array( 'mime' => 'image/gif' ) );                   // a GIF named .jpg
$L  = photo( array( 'w' => 2049, 'h' => 1537 ) );                // over 2,048 px
$X  = photo( array( 'w' => 3000, 'h' => 2000 ) );                // scaled from 3,000 px
$T  = photo( array( 'kind' => 'theirs' ) );                      // the shop's own upload
$PH = photo( array( 'kind' => 'standin' ) );                     // an eBay stand-in
$O  = photo( array( 'here' => false ) );                         // offloaded: no file here
$O2 = photo( array( 'here' => 'main' ) );                        // its copy gone, the photo here
$M  = photo(); update_post_meta( $M, TCGiant_Sync_Photo_Cleanup::MARK_KEY, (string) ( time() + 900 ) );   // waiting to be deleted
$F  = photo(); update_post_meta( $F, TCGiant_Sync_Photo_Cleanup::FAILED_KEY, (string) time() );          // a deletion given up on
$U  = photo(); update_post_meta( $U, TCGiant_Sync_Image_Localizer::UNFINISHED_META, time() );            // half saved
$ST = photo(); update_post_meta( $ST, TCGiant_Sync_Photo_Cleanup::STARTED_KEY, (string) time() );        // being deleted
$TR = photo(); q( "UPDATE wp_posts SET post_status = 'trash' WHERE ID = {$TR}" );                        // in the Media Library's Trash

$before_files = uploads_now();
$before_meta  = meta_now();

$want_bytes = 0;
foreach ( array( $A, $B, $C, $E ) as $id ) {
	$want_bytes += filesize( copy_path( $id ) );
}

$r = check_all();
$c = $r['data']['check'] ?? array();

check( 'the check finishes', array( $r['success'] ?? null, $c['status'] ?? null ), array( true, 'found' ) );
check( '  four photos have a copy that may go', $c['photos'] ?? null, 4 );
check( '  a file each', $c['files'] ?? null, 4 );
check( '  their bytes: the record\'s own, else the file\'s', $c['bytes'] ?? null, $want_bytes );
check( '  two left as they are, their files not on this server', $c['not_here'] ?? null, 2 );
check( '  the GIFs and the photos over 2,048 px keep theirs', $c['kept_whole'] ?? null, 4 );
check( 'nothing was changed by checking: every file', uploads_now(), $before_files );
check( '  and every record', meta_now() == $before_meta, true );
check( '  no notes', notes(), 0 );
check( 'the log says what it found', logged( 'Large photo copies: checked the photos TCGiant downloaded. 4 still have WordPress\'s old 1,536 px copy: 4 file(s)' ), true );

// The same judgement, photo by photo.
$plans = TCGiant_Sync_Size_Trim::judge( array( $A, $B, $C, $E, $S, $G, $GJ, $L, $X, $T, $PH, $O, $O2, $M, $F, $U, $ST, $TR ) );
$why   = array_map( function ( $p ) { return $p['why']; }, $plans );
check( 'judged: Original-size photos in 2024/05, 2025/11 and the top of uploads, and one of 2,048 px', array( $why[ $A ], $why[ $B ], $why[ $C ], $why[ $E ] ), array( '', '', '', '' ) );
check( '  a 1,200 px photo has no copy', $why[ $S ], 'none' );
check( '  a GIF, by its name or by its type', array( $why[ $G ], $why[ $GJ ] ), array( 'gif', 'gif' ) );
check( '  over 2,048 px, or scaled from larger', array( $why[ $L ], $why[ $X ] ), array( 'large', 'large' ) );
check( '  the shop\'s own upload, a stand-in, one in the Media Library\'s Trash: not ours', array( $why[ $T ], $why[ $PH ], $why[ $TR ] ), array( 'not_ours', 'not_ours', 'not_ours' ) );
check( '  files not on this server: the photo, or only its copy', array( $why[ $O ], $why[ $O2 ] ), array( 'not_here', 'not_here' ) );
check( '  in the photo cleanup\'s hands, given up on, half saved, being deleted', array( $why[ $M ], $why[ $F ], $why[ $U ], $why[ $ST ] ), array( 'marked', 'marked', 'marked', 'marked' ) );
check( '  the size going and its file, in the photo\'s own folder', array( $plans[ $B ]['sizes'], array_keys( $plans[ $B ]['files'] ), $plans[ $B ]['rel_dir'], $plans[ $C ]['rel_dir'] ), array( array( '1536x1536' ), array( wp_basename( copy_path( $B ) ) ), '2025/11', '' ) );

// More shapes, judged on their own.
$PT  = photo( array( 'w' => 1200, 'h' => 2400 ) );               // taller than 2,048 px, narrower
$O3  = photo();                                                  // its own file gone, its copy here
@unlink( $GLOBALS['BASEDIR'] . '/' . get_post_meta( $O3, '_wp_attached_file', true ) );
$DM  = photo();                                                  // two records of sizes: not clear which
q( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id = {$DM} AND meta_key = '_wp_attachment_metadata'" );
$AF  = photo();                                                  // its record says other bytes than the file has
$m   = meta_of( $AF );
$m['sizes']['1536x1536']['filesize'] += 7;
update_post_meta( $AF, '_wp_attachment_metadata', $m );
$more = TCGiant_Sync_Size_Trim::judge( array( $PT, $O3, $DM, $AF, $B ) );
check( '  taller than 2,048 px though narrower: keeps its copies', $more[ $PT ]['why'], 'large' );
check( '  its own file not on this server, its copy here: left as it is', $more[ $O3 ]['why'], 'not_here' );
check( '  two records of its sizes: left as it is', $more[ $DM ]['why'], 'odd' );
check( '  bytes: the size\'s own record of them first, else the file\'s', array( $more[ $AF ]['files'][ wp_basename( copy_path( $AF ) ) ], $more[ $B ]['files'][ wp_basename( copy_path( $B ) ) ] ), array( $m['sizes']['1536x1536']['filesize'], filesize( copy_path( $B ) ) ) );

// A check stepped twice at once - the screen asking again for a step the
// browser stopped waiting for, still running - counts nothing twice.
fresh_db();
boot();
for ( $i = 0; $i < 3; $i++ ) {
	photo();
}
ajax( 'check', array( 'restart' => 1 ) );
$first = TCGiant_Sync_Size_Trim::check();
check( 'one step does the whole of a small shop', array( $first['status'], $first['photos'] ), array( 'found', 3 ) );
$first['status'] = 'scanning';
$first['cursor'] = 0;
$first['photos'] = 0;
$first['files']  = 0;
$first['bytes']  = 0;
update_option( TCGiant_Sync_Size_Trim::CHECK_OPTION, $first );
$moved = false;
$wpdb->on_query = function ( $sql ) use ( &$moved, $first ) {
	// Another step moves the check on while this one reads its page.
	if ( ! $moved && false !== strpos( $sql, 'SELECT ID, post_status, post_mime_type' ) ) {
		$moved           = true;
		$other           = $first;
		$other['cursor'] = 99999;
		$GLOBALS['wpdb']->on_query = null;
		update_option( TCGiant_Sync_Size_Trim::CHECK_OPTION, $other );
	}
};
TCGiant_Sync_Size_Trim::check_step( 9999 );
$wpdb->on_query = null;
check( '  a step that finds another moved the check on adds nothing', array( TCGiant_Sync_Size_Trim::check()['cursor'], TCGiant_Sync_Size_Trim::check()['photos'] ), array( 99999, 0 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE REMOVAL: THE RECORD FIRST, THE FILES A DAY LATER\n" . str_repeat( '=', 126 ) . "\n";

fresh_db();
boot();
$A  = photo();
$B  = photo( array( 'dir' => '2025/11', 'filesize' => false ) );
$C  = photo( array( 'dir' => '', 'w' => 1600, 'h' => 1600 ) );
$G  = photo( array( 'ext' => 'gif' ) );
$L  = photo( array( 'w' => 2049, 'h' => 1537 ) );
$T  = photo( array( 'kind' => 'theirs' ) );
$O  = photo( array( 'here' => false ) );
// The same name in another month's folder: a different photo's file.
$TWIN = photo( array( 'dir' => '2025/11', 'name' => 'brake-caliper-' . $A . '-ebay-p' . $A . 'mhaaosw-1600' ) );

$copies = array();
foreach ( array( $A, $B, $C, $G, $L, $T, $O, $TWIN ) as $id ) {
	$copies[ $id ] = copy_path( $id );
}
$keep_meta = array();
foreach ( array( $G, $L, $T, $O ) as $id ) {
	$keep_meta[ $id ] = meta_of( $id );
}
$other_sizes = array();
foreach ( array( $A, $B, $C ) as $id ) {
	$other_sizes[ $id ] = array_values( array_diff( sizes_of( $id ), array( '1536x1536' ) ) );
}
$expect_bytes = filesize( $copies[ $A ] ) + filesize( $copies[ $B ] ) + filesize( $copies[ $C ] ) + filesize( $copies[ $TWIN ] );

$s = check_and_start();
check( 'the button starts the removal', array( $s['success'] ?? null, $s['data']['run']['status'] ?? null ), array( true, 'running' ) );
check( '  scheduled to begin in seconds, and WP-Cron asked to run', array( wp_next_scheduled( TCGiant_Sync_Size_Trim::TRIM_HOOK ) <= time() + 5, TCGiant_Sync_Cron::$dispatched > 0 ), array( true, true ) );
check( '  the log names who asked, and what', logged( 'Large photo copies: haz asked for the old 1,536 px copies found on' ), true );

$runs = run_until_settled();
$run  = TCGiant_Sync_Size_Trim::run_state();
check( 'first part: the copy is out of each record', array( has_copy( $A ), has_copy( $B ), has_copy( $C ), has_copy( $TWIN ) ), array( false, false, false, false ) );
check( '  every other size is still in it', array( array_values( sizes_of( $A ) ), array_values( sizes_of( $B ) ), array_values( sizes_of( $C ) ) ), array( $other_sizes[ $A ], $other_sizes[ $B ], $other_sizes[ $C ] ) );
check( '  but the files are all still on disk', array_map( 'is_file', array( $copies[ $A ], $copies[ $B ], $copies[ $C ], $copies[ $TWIN ] ) ), array( true, true, true, true ) );
check( '  each photo has its note, at the files\' stage, due a day on', array( note_of( $A )['stage'] ?? null, ( note_of( $A )['due'] ?? 0 ) >= time() + 86000, note_of( $A )['files'] ?? null ), array( 'files', true, array( wp_basename( $copies[ $A ] ) ) ) );
check( '  the removal waits for the files\' day', array( $run['status'], $run['photos'], $run['files_waiting'] ), array( 'waiting', 4, 4 ) );
check( '  the next run is set for that day', wp_next_scheduled( TCGiant_Sync_Size_Trim::TRIM_HOOK ) >= (int) $run['files_due'], true );
check( '  the log says when the files go', logged( 'took the old 1,536 px copy out of the records of 4 photo(s)' ), true );
check( 'left alone: the GIF, the photo over 2,048 px, the shop\'s own, the offloaded one', array( meta_of( $G ) === $keep_meta[ $G ], meta_of( $L ) === $keep_meta[ $L ], meta_of( $T ) === $keep_meta[ $T ], meta_of( $O ) === $keep_meta[ $O ] ), array( true, true, true, true ) );
check( '  with no note', array( note_of( $G ), note_of( $L ), note_of( $T ), note_of( $O ) ), array( null, null, null, null ) );

run_trim();
check( 'a run before the day deletes nothing', array_map( 'is_file', array( $copies[ $A ], $copies[ $B ], $copies[ $C ] ) ), array( true, true, true ) );

files_due_now();
$CACHE_LOG = array();
run_until_settled();
$run = TCGiant_Sync_Size_Trim::run_state();
check( 'on the day: each photo\'s cached record is let go again before its copy\'s file goes', array( in_array( 'post_meta:' . $A, $CACHE_LOG, true ), in_array( 'post_meta:' . $C, $CACHE_LOG, true ) ), array( true, true ) );
check( 'on the day: the copies\' files are deleted', array_map( 'is_file', array( $copies[ $A ], $copies[ $B ], $copies[ $C ], $copies[ $TWIN ] ) ), array( false, false, false, false ) );
check( '  each from its own folder, by its own name', array_values( array_intersect( $GLOBALS['DELETED_FILES'], array( '2024/05/' . wp_basename( $copies[ $A ] ), '2025/11/' . wp_basename( $copies[ $B ] ), wp_basename( $copies[ $C ] ), '2025/11/' . wp_basename( $copies[ $TWIN ] ) ) ) ), array( '2024/05/' . wp_basename( $copies[ $A ] ), '2025/11/' . wp_basename( $copies[ $B ] ), wp_basename( $copies[ $C ] ), '2025/11/' . wp_basename( $copies[ $TWIN ] ) ) );
check( '  and nothing else', count( $GLOBALS['DELETED_FILES'] ), 4 );
check( '  every other file of every photo is still there', array_map( 'is_file', array( $copies[ $G ], $copies[ $L ], $copies[ $T ], folder_of( $A ) . '/' . meta_of( $A )['sizes']['large']['file'], $GLOBALS['BASEDIR'] . '/' . get_post_meta( $C, '_wp_attached_file', true ) ) ), array( true, true, true, true, true ) );
check( '  the notes are gone', notes(), 0 );
check( '  the removal is done, and freed what the files took', array( $run['status'], $run['deleted'], $run['bytes'] ), array( 'done', 4, $expect_bytes ) );
check( '  the log has the totals', logged( 'Large photo copies: finished. 4 file(s) deleted from 4 photo(s)' ), true );
check( '  nothing more scheduled', wp_next_scheduled( TCGiant_Sync_Size_Trim::TRIM_HOOK ), false );
$st = TCGiant_Sync_Size_Trim::status();
check( 'the screen shows the totals', array( $st['run']['status'], $st['run']['deleted'], $st['run']['photos'], $st['run']['bytes'] ), array( 'done', 4, 4, $expect_bytes ) );

// The check after: nothing left to do.
$r = check_all();
check( 'a check after finds nothing more', $r['data']['check']['photos'] ?? null, 0 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE CHECK COUNTS WHAT THE REMOVAL FREES, BYTE FOR BYTE\n" . str_repeat( '=', 126 ) . "\n";

fresh_db();
boot();
for ( $i = 0; $i < 7; $i++ ) {
	photo( array( 'dir' => 0 === $i % 2 ? '2024/05' : '2025/11', 'filesize' => 0 === $i % 3 ) );
}
$r       = check_all();
$counted = $r['data']['check']['bytes'];
$before  = array_sum( uploads_now() );
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
remove_all();
$run = TCGiant_Sync_Size_Trim::run_state();
check( 'the check\'s bytes are the bytes the removal freed', array( $counted, $run['bytes'], $before - array_sum( uploads_now() ) ), array( $run['bytes'], $counted, $counted ) );
check( '  file for file', array( $r['data']['check']['files'], $run['deleted'] ), array( 7, 7 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA FILE ANYTHING ELSE STILL NAMES IS KEPT\n" . str_repeat( '=', 126 ) . "\n";

fresh_db();
boot();
// Another size of the same dimensions: WordPress makes one file for both.
$THEME_SIZES = array( 'hero' => array( 'width' => 1536, 'height' => 0, 'crop' => false ) );
$IN   = photo();
$THEME_SIZES = array();
// Two records of one photo, as the image batch made before 3.1.4.
$D1   = photo( array( 'name' => 'dup-part' ) );
$D2   = new_post( 'attachment', 'inherit', 0, 'image/jpeg' );
update_post_meta( $D2, '_tcgiant_source_url', 'https://i.ebayimg.com/images/g/DUP/s-l1600.jpg' );
update_post_meta( $D2, '_wp_attached_file', get_post_meta( $D1, '_wp_attached_file', true ) );
update_post_meta( $D2, '_wp_attachment_metadata', meta_of( $D1 ) );
// A record the shop's translation plugin made: not the plugin's, so never trimmed.
$K1   = photo( array( 'name' => 'kept-part' ) );
$K2   = new_post( 'attachment', 'inherit', 0, 'image/jpeg' );
update_post_meta( $K2, '_wp_attached_file', get_post_meta( $K1, '_wp_attached_file', true ) );
update_post_meta( $K2, '_wp_attachment_metadata', meta_of( $K1 ) );
// The shop's own upload whose file is named like the copy.
$NM   = photo( array( 'name' => 'named-part' ) );
$UP   = photo( array( 'kind' => 'theirs', 'w' => 1536, 'h' => 1152, 'name' => 'named-part-1536x1152' ) );
// An entry edited in the Media Library, whose kept original copies include the file.
$ED   = photo( array( 'name' => 'edited-part' ) );
$ED2  = new_post( 'attachment', 'inherit', 0, 'image/jpeg' );
update_post_meta( $ED2, '_wp_attached_file', '2024/05/edited-part-e1700000000000.jpg' );
update_post_meta( $ED2, '_wp_attachment_metadata', array( 'width' => 1600, 'height' => 1200, 'file' => '2024/05/edited-part-e1700000000000.jpg', 'sizes' => array() ) );
update_post_meta( $ED2, '_wp_attachment_backup_sizes', array( '1536x1536-orig' => array( 'file' => 'edited-part-1536x1152.jpg', 'width' => 1536, 'height' => 1152 ) ) );
// The photo's own original, when its record names it as the copy (a record
// another plugin rewrote).
$OR   = photo( array( 'name' => 'own-part' ) );
$m    = meta_of( $OR );
$m['original_image'] = $m['sizes']['1536x1536']['file'];
update_post_meta( $OR, '_wp_attachment_metadata', $m );

$paths = array();
foreach ( array( $IN, $D1, $K1, $NM, $ED, $OR ) as $id ) {
	$paths[ $id ] = copy_path( $id );
}
check( 'set up: the theme\'s size shares the copy\'s file', meta_of( $IN )['sizes']['hero']['file'], meta_of( $IN )['sizes']['1536x1536']['file'] );

$r = check_all();
check( 'the check counts only files nothing else names', array( $r['data']['check']['photos'], $r['data']['check']['files'], $r['data']['check']['shared'] ), array( 7, 0, 5 ) );
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
remove_all();

check( 'another size of the same photo: the copy\'s entry goes, the file stays for it', array( has_copy( $IN ), isset( meta_of( $IN )['sizes']['hero'] ), is_file( $paths[ $IN ] ) ), array( false, true, true ) );
check( 'two records of one photo, both the plugin\'s: both trimmed, the file deleted once neither names it', array( has_copy( $D1 ), has_copy( $D2 ), is_file( $paths[ $D1 ] ) ), array( false, false, false ) );
check( 'a record that is not the plugin\'s keeps naming it: the file stays', array( has_copy( $K1 ), has_copy( $K2 ), is_file( $paths[ $K1 ] ) ), array( false, true, true ) );
check( 'a file that is another entry\'s own photo stays', array( has_copy( $NM ), is_file( $paths[ $NM ] ), exists( $UP ) ), array( false, true, true ) );
check( 'a file an edited entry keeps as its original stays', array( has_copy( $ED ), is_file( $paths[ $ED ] ) ), array( false, true ) );
check( 'a file the photo\'s own record names as its original stays', array( has_copy( $OR ), is_file( $paths[ $OR ] ) ), array( false, true ) );
check( 'the removal counts what it kept for another entry', TCGiant_Sync_Size_Trim::run_state()['shared'] >= 3, true );
check( 'no note left', notes(), 0 );

// A size made again by something on the copy's day keeps its file.
fresh_db();
boot();
$RE = photo();
$rp = copy_path( $RE );
$r  = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
run_until_settled();
$m = meta_of( $RE );
$m['sizes']['1536x1536'] = array( 'file' => wp_basename( $rp ), 'width' => 1536, 'height' => 1152 );
update_post_meta( $RE, '_wp_attachment_metadata', $m );
files_due_now();
run_until_settled();
check( 'a copy named again before its day (a tool made it again) is kept', array( has_copy( $RE ), is_file( $rp ), notes() ), array( true, true, 0 ) );

// A record whose copy is named with a path is only ever looked for in the
// photo's own folder.
fresh_db();
boot();
$VIC  = photo( array( 'kind' => 'theirs', 'dir' => '2025/11', 'name' => 'victim' ) );
$PATH = photo( array( 'name' => 'pathy' ) );
$m    = meta_of( $PATH );
@unlink( copy_path( $PATH ) );
$m['sizes']['1536x1536']['file'] = '../../2025/11/' . wp_basename( copy_path( $VIC ) );
update_post_meta( $PATH, '_wp_attachment_metadata', $m );
$vp = copy_path( $VIC );
$r  = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
remove_all();
check( 'a copy named with a path to another folder: that folder\'s file is never touched', array( is_file( $vp ), has_copy( $VIC ), $GLOBALS['DELETED_FILES'] ), array( true, true, array() ) );
check( '  the photo is looked for in its own folder only, found missing there, and left as it is', array( has_copy( $PATH ), note_of( $PATH ) ), array( true, null ) );

/** Recreate wp_postmeta comparing text without regard to case, as MySQL's usual collations (utf8mb4_unicode_ci) do. */
function postmeta_ci() {
	$GLOBALS['wpdb']->db->exec( "
		DROP TABLE wp_postmeta;
		CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT COLLATE NOCASE, meta_value TEXT COLLATE NOCASE );
		CREATE INDEX pm_post ON wp_postmeta ( post_id );
		CREATE INDEX pm_key ON wp_postmeta ( meta_key, meta_value );
	" );
}

/** Another Media Library entry, not the plugin's, whose own file is $file and whose record names $names. */
function other_entry( $file, array $names ) {
	$id    = new_post( 'attachment', 'inherit', 0, 'image/jpeg' );
	$sizes = array();
	foreach ( $names as $i => $n ) {
		$sizes[ 'size' . $i ] = array( 'file' => $n, 'width' => 1536, 'height' => 1152 );
	}
	update_post_meta( $id, '_wp_attached_file', $file );
	update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 1600, 'height' => 1200, 'file' => $file, 'sizes' => $sizes ) );
	if ( ! is_file( $GLOBALS['BASEDIR'] . '/' . $file ) ) {
		file_put_contents( $GLOBALS['BASEDIR'] . '/' . $file, 'x' );
	}
	return $id;
}

// The names another entry's own file can have and still name the copy: the
// photo scaled or rotated by WordPress, or saved as another type of image.
foreach ( array( '-scaled.jpg', '-rotated.jpg', '.webp', '.png', '.jpeg', '.avif' ) as $tail ) {
	fresh_db();
	boot();
	$P    = photo( array( 'name' => 'part' ) );
	$copy = copy_path( $P );
	other_entry( '2024/05/part' . $tail, array( wp_basename( $copy ) ) );
	$r = check_all();
	ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
	remove_all();
	check( "another entry whose file is 'part{$tail}' names the copy: the copy is kept", array( has_copy( $P ), is_file( $copy ) ), array( false, true ) );
}

// On MySQL, whose usual collations ignore case, the lookup of other entries
// finds one whose file differs from the name asked for by case alone.
fresh_db();
postmeta_ci();
boot();
$P    = photo( array( 'name' => 'part' ) );
$copy = copy_path( $P );
other_entry( '2024/05/PART.jpg', array( wp_basename( $copy ) ) );
$r = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
remove_all();
check( 'another entry found by its file\'s name in another case (MySQL\'s collation): the copy is kept', array( has_copy( $P ), is_file( $copy ) ), array( false, true ) );

// The record written byte for byte on MySQL: another request's change by case
// alone, between the read and the write, is not written over.
foreach ( array( 'SQLite, its own binary comparison' => false, 'MySQL, a case-blind collation' => true ) as $label => $mysql ) {
	fresh_db();
	if ( $mysql ) {
		postmeta_ci();
		$wpdb->is_mysql = true;
	}
	boot();
	$P = photo( array( 'name' => 'Brake-Caliper' ) );
	$r = check_all();
	ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
	$raced = false;
	$wpdb->on_query = function ( $sql ) use ( &$raced, $P ) {
		if ( $raced || 0 !== strpos( $sql, 'UPDATE wp_postmeta SET meta_value' ) || false === strpos( $sql, 'meta_value = ' . "'" . 'a:' ) || false === strpos( $sql, 'WHERE meta_id' ) ) {
			return;
		}
		$raced = true;
		$row   = $GLOBALS['wpdb']->db->querySingle( "SELECT meta_id, meta_value FROM wp_postmeta WHERE post_id = {$P} AND meta_key = '_wp_attachment_metadata'", true );
		$m     = unserialize( $row['meta_value'] );
		$m['sizes']['medium']['file'] = strtolower( $m['sizes']['medium']['file'] );
		$GLOBALS['wpdb']->db->exec( 'UPDATE wp_postmeta SET meta_value = ' . esc( serialize( $m ) ) . ' WHERE meta_id = ' . (int) $row['meta_id'] );
	};
	run_until_settled();
	$wpdb->on_query = null;
	check( "a change by case alone meanwhile ({$label}): kept, and the copy still taken out", array( $raced, meta_of( $P )['sizes']['medium']['file'] ?? '', has_copy( $P ) ), array( true, 'brake-caliper-300x225.jpg', false ) );
}

// The photo's file moves to another folder while its copy waits, and that
// folder holds a file of the copy's name: neither is touched.
fresh_db();
boot();
$P    = photo( array( 'name' => 'mover' ) );
$copy = copy_path( $P );
$name = wp_basename( $copy );
$r    = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
run_until_settled();
foreach ( glob( $GLOBALS['BASEDIR'] . '/2024/05/mover*' ) as $f ) {
	if ( wp_basename( $f ) !== $name ) {
		rename( $f, $GLOBALS['BASEDIR'] . '/2025/11/' . wp_basename( $f ) );
	}
}
update_post_meta( $P, '_wp_attached_file', '2025/11/mover.jpg' );
$m         = meta_of( $P );
$m['file'] = '2025/11/mover.jpg';
update_post_meta( $P, '_wp_attachment_metadata', $m );
file_put_contents( $GLOBALS['BASEDIR'] . '/2025/11/' . $name, 'somebody else' );
files_due_now();
run_until_settled();
check( 'moved to another folder while its copy waited: left as it is, the other folder\'s file of that name untouched', array( is_file( $GLOBALS['BASEDIR'] . '/2025/11/' . $name ), is_file( $copy ), note_of( $P )['stage'] ?? null, note_of( $P )['why'] ?? null ), array( true, true, 'given_up', 'moved' ) );

// A copy the server will not let go of - the wrong owner, say - is not
// counted as space freed.
fresh_db();
boot();
$P    = photo();
$copy = copy_path( $P );
$r    = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
run_until_settled();
add_filter( 'wp_delete_file', function ( $f ) { return ''; } );
files_due_now();
run_until_settled();
$run = TCGiant_Sync_Size_Trim::run_state();
check( 'a copy that could not be deleted: counted as left, not as freed, and named in the log', array( is_file( $copy ), (int) $run['deleted'], (int) $run['bytes'], (int) $run['left_files'], logged( 'could not be deleted from the uploads folder (' . wp_basename( $copy ) ) ), array( true, 0, 0, 1, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA REQUEST KILLED AT EACH STEP IS FINISHED BY THE NEXT\n" . str_repeat( '=', 126 ) . "\n";

foreach ( array(
	'after the note, before the record'             => 'note',
	'after the record, before the note moves on'    => 'meta',
	'after the note moves on to the files\' stage' => 'note_files',
	'part way through the files'                    => 'files',
	'after the files, before the note goes'         => 'before_note_goes',
) as $label => $where ) {
	foreach ( array( 'fatal', 'hard' ) as $how ) {
		fresh_db();
		boot();
		$K  = photo();
		$K2 = photo( array( 'dir' => '2025/11' ) );
		$kp = array( copy_path( $K ), copy_path( $K2 ) );
		$keep_files = array_diff_key( uploads_now(), array_flip( array( '2024/05/' . wp_basename( $kp[0] ), '2025/11/' . wp_basename( $kp[1] ) ) ) );
		$r = check_all();
		ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
		if ( in_array( $where, array( 'files', 'before_note_goes' ), true ) ) {
			run_until_settled();
			files_due_now();
		}

		$code = in_child( 'run:' . $where . ':' . $how );
		boot();
		$mid_meta = array( has_copy( $K ), has_copy( $K2 ) );
		$held     = get_option( TCGiant_Sync_Size_Trim::LOCK_PREFIX . 'run', 'none' );
		if ( 'fatal' === $how ) {
			check( sprintf( 'killed %s by a fatal: its hold is let go as it dies', $label ), $held, 'none' );
		} else {
			// Killed from outside, nothing ran: its hold lapses, four minutes on.
			check( sprintf( 'killed %s from outside: its hold is still there', $label ), 'none' !== $held, true );
			hold_lapses();
		}

		// The next run sees the death and pauses; after the pause it finishes.
		run_trim();
		$paused = TCGiant_Sync_Size_Trim::status()['paused_until'] > time();
		end_pause();
		run_trim();
		if ( in_array( $where, array( 'note', 'meta' ), true ) ) {
			// Its record changed or not, the photo it died on is finished
			// first, not left for the second part to find.
			check( '  the first run after the pause finishes that photo first', array( note_of( $K )['stage'] ?? null, has_copy( $K ) ), array( 'files', false ) );
		}
		for ( $i = 0; $i < 8 && 'done' !== ( TCGiant_Sync_Size_Trim::run_state()['status'] ?? '' ); $i++ ) {
			end_pause();
			run_until_settled();
			files_due_now();
		}

		$run = TCGiant_Sync_Size_Trim::run_state();
		check( sprintf( 'killed %s (%s): the child died', $label, $how ), 0 !== $code, true );
		check( '  the next run pauses, then the removal finishes', array( $paused, $run['status'] ), array( true, 'done' ) );
		check( '  both copies out of their records, their files deleted, no note left', array( has_copy( $K ), has_copy( $K2 ), is_file( $kp[0] ), is_file( $kp[1] ), notes() ), array( false, false, false, false, 0 ) );
		check( '  every other file still there', array_intersect_key( uploads_now(), $keep_files ), $keep_files );
		if ( 'note' === $where ) {
			check( '  (killed after the note: the record was untouched)', $mid_meta, array( true, true ) );
		}
	}
}

// A photo that stops the removal every time is left as it is after three.
fresh_db();
boot();
$K  = photo();
$K2 = photo( array( 'dir' => '2025/11' ) );
$r  = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
$kp = copy_path( $K );
for ( $i = 0; $i < 3; $i++ ) {
	in_child( 'run:premeta:fatal:' . $K );
	boot();
	run_trim(); // Sees the death, and pauses.
	end_pause();
}
run_until_settled();
check( 'a photo that kills every run: left as it is after three tries', array( note_of( $K )['stage'] ?? null, note_of( $K )['why'] ?? null ), array( 'given_up', 'stopped' ) );
check( '  its record and its file stay', array( has_copy( $K ), is_file( $kp ) ), array( true, true ) );
check( '  and the log names it', logged( 'Large photo copies: photo #' . $K . ' left as it is' ), true );
check( '  the rest still go', has_copy( $K2 ), false );
files_due_now();
run_until_settled();
check( '  and the removal still finishes', TCGiant_Sync_Size_Trim::run_state()['status'] ?? null, 'done' );
$r = check_all();
check( '  a check after does not offer it again', array( $r['data']['check']['photos'] ?? null, has_copy( $K ) ), array( 0, true ) );

// A photo that kills the run before its note is written is stepped past.
fresh_db();
boot();
$K = photo();
$r = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
for ( $i = 0; $i < 2; $i++ ) {
	in_child( 'run:reading:hard' );
	boot();
	hold_lapses();
	run_trim(); // Sees the death, and pauses.
	end_pause();
}
run_until_settled();
check( 'killed twice while reading a photo, before its note: stepped past, left as it is', array( has_copy( $K ), note_of( $K ), TCGiant_Sync_Size_Trim::run_state()['status'] ?? null ), array( true, null, 'waiting' ) );
check( '  named in the log', logged( 'photo #' . $K . ' stopped the removal twice before anything of it was changed' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA READ THE DATABASE FAILS, OR A WRITE IT REFUSES, CHANGES NOTHING\n" . str_repeat( '=', 126 ) . "\n";

foreach ( array(
	'the walk through the photos'       => "md.meta_key = '_wp_attachment_metadata'",
	'the photos\' own records'          => 'SELECT post_id, meta_key, meta_value FROM wp_postmeta',
	'what other entries\' files are'    => "meta_key = '_wp_attached_file' AND meta_value IN",
	'their records'                     => "meta_key IN ( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' )",
) as $label => $needle ) {
	fresh_db();
	boot();
	$K = photo();
	$D = new_post( 'attachment', 'inherit', 0, 'image/jpeg' ); // another record of it, so that its records are read
	update_post_meta( $D, '_wp_attached_file', get_post_meta( $K, '_wp_attached_file', true ) );
	update_post_meta( $D, '_wp_attachment_metadata', meta_of( $K ) );
	$files = uploads_now();
	$meta  = meta_now();

	$wpdb->silent = array( $needle );
	$r = ajax( 'check', array( 'restart' => 1 ) );
	$wpdb->silent = array();
	check( sprintf( 'the check, when %s cannot be read: refused, nothing counted', $label ), array( $r['success'], TCGiant_Sync_Size_Trim::check()['photos'], TCGiant_Sync_Size_Trim::check()['cursor'] ), array( false, 0, 0 ) );

	$r = check_all();
	ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
	$wpdb->silent = array( $needle );
	run_trim();
	$wpdb->silent = array();
	check( '  the removal: stops with nothing changed', array( uploads_now() === $files, meta_now() == $meta, notes() ), array( true, true, 0 ) );
	check( '  says so, and carries on in five minutes', array( logged( 'Large photo copies stopped: the database did not answer' ), wp_next_scheduled( TCGiant_Sync_Size_Trim::TRIM_HOOK ) > time() + 200 ), array( true, true ) );
}

// The files' day, when the reading of other entries fails: nothing deleted.
fresh_db();
boot();
$K  = photo();
$kp = copy_path( $K );
$r  = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
run_until_settled();
files_due_now();
$wpdb->silent = array( "meta_key = '_wp_attached_file' AND meta_value IN" );
run_trim();
$wpdb->silent = array();
check( 'on the files\' day, a failed read of other entries deletes nothing', array( is_file( $kp ), note_of( $K )['stage'] ?? null ), array( true, 'files' ) );
run_until_settled();
check( '  and the next run deletes it', array( is_file( $kp ), notes() ), array( false, 0 ) );

// A note the database refuses to take: the record is not touched.
fresh_db();
boot();
$K  = photo();
$kp = copy_path( $K );
$r  = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
$wpdb->refuse = array( '_tcgiant_trim_pending' );
run_trim();
$wpdb->refuse = array();
check( 'the note refused (a full disk): the record is not touched, nothing deleted', array( has_copy( $K ), notes(), is_file( $kp ) ), array( true, 0, true ) );
check( '  the log says the database did not keep it', logged( 'the note of which large copies of photo #' . $K . ' are going was not kept' ), true );
run_until_settled();
check( '  the next run goes on', array( has_copy( $K ), note_of( $K )['stage'] ?? null ), array( false, 'files' ) );

// The record refused: the note stays at its first stage, and is finished.
fresh_db();
boot();
$K  = photo();
$K2 = photo();
$r  = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
$wpdb->silent = array( 'UPDATE wp_postmeta SET meta_value' );
run_trim();
$wpdb->silent = array();
check( 'the record\'s change refused: the copy still in it, the note at its first stage', array( has_copy( $K ), note_of( $K )['stage'] ?? null, has_copy( $K2 ) ), array( true, 'meta', true ) );
check( '  the log says so', logged( 'the change to the record of photo #' . $K . '\'s sizes was not kept' ), true );
run_until_settled();
check( '  the next run finishes it first, then the rest', array( has_copy( $K ), note_of( $K )['stage'] ?? null, note_of( $K )['tries'] ?? null, has_copy( $K2 ) ), array( false, 'files', 2, false ) );

// The note's move to the files' stage refused.
fresh_db();
boot();
$K  = photo();
$kp = copy_path( $K );
$r = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
$wpdb->on_query = function ( $sql ) {
	if ( 0 === strpos( $sql, "UPDATE wp_postmeta SET meta_value = 'a:7:{s:5:\"stage\";s:5:\"files\"" ) ) {
		$GLOBALS['wpdb']->silent = array( 'stage";s:5:"files"' );
	}
};
run_trim();
$wpdb->on_query = null;
$wpdb->silent   = array();
check( 'the note\'s move on refused: the record changed, the note still says so, nothing deleted', array( has_copy( $K ), note_of( $K )['stage'] ?? null, is_file( $kp ) ), array( false, 'meta', true ) );
run_until_settled();
check( '  the next run moves it on', note_of( $K )['stage'] ?? null, 'files' );

// Another request writes the record between the read and the write.
fresh_db();
boot();
$K = photo();
$r = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
$raced = false;
$wpdb->on_query = function ( $sql ) use ( &$raced, $K ) {
	if ( ! $raced && 0 === strpos( $sql, 'UPDATE wp_postmeta SET meta_value' ) && false !== strpos( $sql, 'AND meta_value' ) ) {
		$raced = true;
		$m     = unserialize( $GLOBALS['wpdb']->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = {$K} AND meta_key = '_wp_attachment_metadata'" ) );
		$m['sizes']['woocommerce_archive'] = array( 'file' => 'made-on-the-fly-400x300.jpg', 'width' => 400, 'height' => 300 );
		$GLOBALS['wpdb']->db->exec( 'UPDATE wp_postmeta SET meta_value = ' . esc( serialize( $m ) ) . " WHERE post_id = {$K} AND meta_key = '_wp_attachment_metadata'" );
	}
};
run_trim();
$wpdb->on_query = null;
check( 'a size another request adds meanwhile is not written over: read again, changed again', array( $raced, has_copy( $K ), isset( meta_of( $K )['sizes']['woocommerce_archive'] ) ), array( true, false, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNOTHING WHILE AN OFFLOAD PLUGIN IS AT WORK\n" . str_repeat( '=', 126 ) . "\n";

eval( 'namespace Bunny\Wordpress; class Offloader { public function delete_attachment() {} public function wp_delete_file( $f ) { return $f; } }' );
eval( 'class Some_Media_Offload_Tool { public function on_delete() {} }' );

foreach ( array(
	'WP Offload Media'          => function () { update_option( 'active_plugins', array( 'woocommerce/woocommerce.php', 'amazon-s3-and-cloudfront/wordpress-s3.php' ) ); },
	'Media Cloud'               => function () { update_option( 'active_plugins', array( 'ilab-media-tools/ilab-media-tools.php' ) ); },
	'Advanced Media Offloader'  => function () { update_option( 'active_plugins', array( 'advanced-media-offloader/advanced-media-offloader.php' ) ); },
	'bunny.net\'s Offloader'    => function () { update_option( 'active_plugins', array( 'bunnycdn/bunnycdn.php' ) ); register_in_wp_filter( 'delete_attachment', array( new \Bunny\Wordpress\Offloader(), 'delete_attachment' ) ); },
	'my-offload-plugin'         => function () { update_option( 'active_plugins', array( 'my-offload-plugin/plugin.php' ) ); },
	'Some_Media_Offload_Tool::on_delete (on wp_delete_file)' => function () { register_in_wp_filter( 'wp_delete_file', array( new Some_Media_Offload_Tool(), 'on_delete' ) ); },
	'an uploads folder kept at s3://' => function () { $GLOBALS['BASEDIR_SHOWN'] = 's3://shop-bucket/uploads'; },
) as $name => $switch_on ) {
	fresh_db();
	boot();
	$K  = photo();
	$kp = copy_path( $K );
	$switch_on();
	$shown = $GLOBALS['BASEDIR_SHOWN'] ?? null;
	if ( null !== $shown ) {
		$GLOBALS['BASEDIR_REAL'] = $GLOBALS['BASEDIR'];
		$GLOBALS['BASEDIR']      = $shown;
	}
	$found = TCGiant_Sync_Size_Trim::offloaders();
	$r     = ajax( 'check', array( 'restart' => 1 ) );
	if ( null !== $shown ) {
		$GLOBALS['BASEDIR'] = $GLOBALS['BASEDIR_REAL'];
		unset( $GLOBALS['BASEDIR_SHOWN'] );
	}
	check( sprintf( '%s: found, and the check refused, naming it', $name ), array( in_array( $name, $found, true ), $r['success'], false !== strpos( (string) ( $r['data']['message'] ?? '' ), $name ) ), array( true, false, true ) );
}

// A removal under way when one is switched on: it waits, and carries on.
fresh_db();
boot();
$K  = photo();
$kp = copy_path( $K );
$r  = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
update_option( 'active_plugins', array( 'amazon-s3-and-cloudfront/wordpress-s3.php' ) );
$meta = meta_now();
run_trim();
check( 'switched on after the button: nothing changed', array( meta_now() == $meta, notes() ), array( true, 0 ) );
check( '  the log says why, once', array( logged( 'Large photo copies: waiting. An offload plugin is at work on this site (WP Offload Media)' ), count( array_filter( $LOG, function ( $l ) { return false !== strpos( $l, 'waiting. An offload plugin' ); } ) ) ), array( true, 1 ) );
check( '  and looks again in an hour', wp_next_scheduled( TCGiant_Sync_Size_Trim::TRIM_HOOK ) >= time() + 3500, true );
run_trim();
check( '  a second run says nothing new', count( array_filter( $LOG, function ( $l ) { return false !== strpos( $l, 'waiting. An offload plugin' ); } ) ), 1 );
check( '  the button is refused too', ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) )['success'], false );
update_option( 'active_plugins', array() );
run_until_settled();
check( '  switched off: it carries on', array( has_copy( $K ), note_of( $K )['stage'] ?? null ), array( false, 'files' ) );
update_option( 'active_plugins', array( 'amazon-s3-and-cloudfront/wordpress-s3.php' ) );
files_due_now();
run_trim();
check( '  switched on again before the files\' day: no file deleted', is_file( $kp ), true );
update_option( 'active_plugins', array() );
run_until_settled();
check( '  off again: the file goes', array( is_file( $kp ), TCGiant_Sync_Size_Trim::run_state()['status'] ), array( false, 'done' ) );

// Switched on after the check, before the button.
fresh_db();
boot();
photo();
$r = check_all();
update_option( 'active_plugins', array( 'wp-stateless/wp-stateless-media.php' ) );
$s = ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
check( 'switched on between the check and the button: the button is refused, saying why', array( $s['success'], false !== strpos( (string) ( $s['data']['message'] ?? '' ), 'WP-Stateless' ), TCGiant_Sync_Size_Trim::run_state() ), array( false, true, array() ) );

// WP Offload SES sends a site's e-mail: "offload" in its name, no media moved.
fresh_db();
boot();
photo();
update_option( 'active_plugins', array( 'woocommerce/woocommerce.php', 'wp-offload-ses/wp-offload-ses.php' ) );
eval( 'namespace DeliciousBrains\WP_Offload_SES; class Mail_Queue { public function on_delete() {} }' );
register_in_wp_filter( 'delete_attachment', array( new \DeliciousBrains\WP_Offload_SES\Mail_Queue(), 'on_delete' ) );
$r = check_all();
check( 'WP Offload SES (e-mail), by its folder or a callback of its own: not an offload plugin, the check runs', array( TCGiant_Sync_Size_Trim::offloaders(), $r['success'] ?? null, $r['data']['check']['photos'] ?? null ), array( array(), true, 1 ) );
update_option( 'active_plugins', array( 'wp-offload-ses/wp-offload-ses.php', 'my-media-offload/plugin.php' ) );
check( '  a media offloader beside it still is', TCGiant_Sync_Size_Trim::offloaders(), array( 'my-media-offload' ) );

// bunny.net's CDN alone, with its Offloader off, is not one.
fresh_db();
boot();
update_option( 'active_plugins', array( 'bunnycdn/bunnycdn.php' ) );
check( 'bunny.net\'s plugin with only its CDN on: not an offloader', TCGiant_Sync_Size_Trim::offloaders(), array() );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PHOTO DELETED WHILE ITS COPY WAITS FOR ITS DAY\n" . str_repeat( '=', 126 ) . "\n";

fresh_db();
boot();
$K  = photo();
$K2 = photo();
$kp = copy_path( $K );
$r  = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
run_until_settled();
$k2files = array_filter( array_keys( uploads_now() ), function ( $p ) use ( $K2 ) { return false !== strpos( $p, '-' . $K2 . '-' ); } );
wp_delete_attachment( $K, true );
do_action( 'shutdown' );
check( 'deleted for good before its day: its waiting copy goes with it', array( exists( $K ), is_file( $kp ) ), array( false, false ) );
check( '  one line for the request', logged( 'Large photo copies: 1 photo(s) were deleted while their old 1,536 px copies waited to be removed; 1 copy file(s)' ), true );
check( '  another photo is not touched', count( array_filter( array_keys( uploads_now() ), function ( $p ) use ( $K2 ) { return false !== strpos( $p, '-' . $K2 . '-' ); } ) ), count( $k2files ) );
files_due_now();
run_until_settled();
check( '  and the removal still finishes', TCGiant_Sync_Size_Trim::run_state()['status'], 'done' );

// The same, while an offload plugin is at work: its copy is left alone.
fresh_db();
boot();
$K  = photo();
$kp = copy_path( $K );
$r  = check_all();
ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
run_until_settled();
update_option( 'active_plugins', array( 'amazon-s3-and-cloudfront/wordpress-s3.php' ) );
wp_delete_attachment( $K, true );
check( '  deleted while an offload plugin is active: its waiting copy is not touched here', array( exists( $K ), is_file( $kp ) ), array( false, true ) );
update_option( 'active_plugins', array() );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SCREEN\n" . str_repeat( '=', 126 ) . "\n";

fresh_db();
boot();
photo();
$r = check_all();
check( 'the button with a check the screen did not show is refused', ajax( 'start', array( 'check_id' => 'not-this-one' ) )['success'], false );
$old = TCGiant_Sync_Size_Trim::check();
$old['finished_at'] -= 8 * DAY_IN_SECONDS;
update_option( TCGiant_Sync_Size_Trim::CHECK_OPTION, $old );
check( 'a check more than a week old is refused', ajax( 'start', array( 'check_id' => $old['id'] ) )['success'], false );
$r = check_all();
check( 'the button with the latest check starts it', ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) )['success'], true );
check( '  a second press is refused', ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) )['success'], false );
check( '  and a new check while it runs', ajax( 'check', array( 'restart' => 1 ) )['success'], false );
$DENIED = array( 'manage_options' );
check( 'someone who may not manage the shop is refused', ajax( 'status' )['success'], false );
$DENIED = array();
wp_clear_scheduled_hook( TCGiant_Sync_Size_Trim::TRIM_HOOK );
TCGiant_Sync_Size_Trim::maybe_rearm_trim();
check( 'a lost schedule is put back from an admin page', wp_next_scheduled( TCGiant_Sync_Size_Trim::TRIM_HOOK ) > 0, true );
TCGiant_Sync_Size_Trim::deactivate();
check( 'deactivating clears it', wp_next_scheduled( TCGiant_Sync_Size_Trim::TRIM_HOOK ), false );

// The view, rendered.
fresh_db();
boot();
photo();
check_all();
foreach ( array( 'esc_html_e' => function ( $t ) { echo $t; }, 'esc_attr' => function ( $t ) { return (string) $t; }, 'esc_html' => function ( $t ) { return (string) $t; }, 'esc_html__' => function ( $t ) { return $t; }, 'esc_url' => function ( $t ) { return $t; }, 'esc_js' => function ( $t ) { return $t; } ) as $fn => $body ) {
	if ( ! function_exists( $fn ) ) {
		$GLOBALS[ 'FN_' . $fn ] = $body;
		eval( "function {$fn}( \$t ) { return call_user_func( \$GLOBALS['FN_{$fn}'], \$t ); }" );
	}
}
function _n( $one, $many, $n ) { return 1 === (int) $n ? $one : $many; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function admin_url( $p = '' ) { return 'https://shop.example/wp-admin/' . $p; }
function wp_create_nonce( $a ) { return 'nonce'; }
$tc_format = 'Y-m-d H:i';
ob_start();
$src = file_get_contents( $ROOT . '/admin/views/image-cleanup.php' );
$from = strpos( $src, '<?php' . "\n\t" . '// The 1,536 px copies WordPress made before 3.27.4.' );
$to   = strpos( $src, '<h2 style="margin:24px 0 0;font-size:15px;"><?php esc_html_e( \'Your own photographs' );
eval( '?>' . substr( $src, $from, $to - $from ) );
$html = ob_get_clean();
check( 'the view shows the count and the button', array( false !== strpos( $html, '1 photo still has the old 1,536 px copy' ), false !== strpos( $html, 'id="tc-trim-start"' ) ), array( true, true ) );
update_option( 'active_plugins', array( 'amazon-s3-and-cloudfront/wordpress-s3.php' ) );
ob_start();
eval( '?>' . substr( $src, $from, $to - $from ) );
$html = ob_get_clean();
check( '  with an offload plugin active: why, and no button', array( false !== strpos( $html, 'An offload plugin is at work on this site (WP Offload Media)' ), false !== strpos( $html, 'id="tc-trim-start"' ), false !== strpos( $html, 'id="tc-trim-check"' ) ), array( true, false, false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nPAGE CACHES: PURGED WHEN THE RECORDS ARE DONE, AND WAITED OUT\n" . str_repeat( '=', 126 ) . "\n";

// Last in this file: a page cache plugin is a constant once defined.

/** A removal's first part, run to its end; the note of the first photo. */
function records_phase( &$photo ) {
	$photo = photo();
	$r     = check_all();
	ajax( 'start', array( 'check_id' => $r['data']['check']['id'] ) );
	run_until_settled();
	return note_of( $photo );
}
function waits( array $note ) { return (int) $note['due'] - (int) $note['at']; }
/** The files' day of every note brought to $seconds after it was noted, or now if sooner. */
function files_due_now_but( $seconds ) {
	foreach ( $GLOBALS['wpdb']->get_results( "SELECT meta_id, meta_value FROM wp_postmeta WHERE meta_key = '_tcgiant_trim_pending'" ) as $row ) {
		$note = unserialize( $row->meta_value );
		$note['at']  = time() - (int) $seconds;
		q( 'UPDATE wp_postmeta SET meta_value = ' . esc( serialize( $note ) ) . ' WHERE meta_id = ' . (int) $row->meta_id );
	}
	$run = TCGiant_Sync_Size_Trim::run_state();
	$run['files_due'] = time() - 1;
	update_option( TCGiant_Sync_Size_Trim::RUN_OPTION, $run );
}

fresh_db();
boot();
$n = records_phase( $P );
check( 'no page cache: the files wait a day', array( waits( $n ), TCGiant_Sync_Size_Trim::files_delay() ), array( 86400, 86400 ) );
check( '  the log says the records are done, nothing could be purged, and to purge a CDN before the day', array( logged( 'every photo\'s record is done' ), logged( 'No page cache plugin TCGiant can purge was found.' ), logged( 'If your site is behind a CDN or another cache that keeps whole pages, purge it before then' ) ), array( true, true, true ) );

define( 'CLOUDFLARE_PLUGIN_DIR', '/cloudflare/' );
fresh_db();
boot();
$n = records_phase( $P );
$u = TCGiant_Sync_Size_Trim::run_state();
check( 'Cloudflare, which TCGiant cannot purge: a day, and a warning to purge it by hand', array( waits( $n ), $u['unpurged'], logged( '[warning] Large photo copies: Cloudflare cannot be purged by TCGiant. Purge it by hand now' ) ), array( 86400, array( 'Cloudflare' ), true ) );

define( 'LSCWP_V', '7.0' );
fresh_db();
boot();
$LS = 0;
add_action( 'litespeed_purge_all', function () { $GLOBALS['LS']++; } );
$n = records_phase( $P );
$u = TCGiant_Sync_Size_Trim::run_state();
check( 'LiteSpeed Cache: purged once, when the records are done', array( $LS, $u['purged'], logged( 'Purged the page cache of LiteSpeed Cache.' ) ), array( 1, array( 'LiteSpeed Cache' ), true ) );
check( '  and the files wait out its page lifetime, a week by default, and an hour more', array( waits( $n ), (int) $u['files_due'] >= time() + 604800 + 3600 - 5 ), array( 604800 + 3600, true ) );
files_due_now_but( 86400 + 60 );
run_until_settled();
check( '  a day on, the file is still there', array( is_file( folder_of( $P ) . '/' . $n['files'][0] ), note_of( $P )['stage'] ?? null ), array( true, 'files' ) );
foreach ( array( '3600' => 86400, '2592000' => 691200, 'week or so' => 604800 + 3600 ) as $ttl => $want ) {
	update_option( 'litespeed.conf.cache-ttl_pub', $ttl );
	check( sprintf( '  its lifetime set to %s: the files wait %d s (at least a day, at most 8 days)', $ttl, $want ), TCGiant_Sync_Size_Trim::files_delay(), $want );
}


// Every page cache TCGiant can purge, each its own way, one of them throwing.
define( 'WP_ROCKET_VERSION', '3.17' );
$PURGED = array();
eval( 'function rocket_clean_domain() { $GLOBALS["PURGED"][] = "rocket"; }' );
eval( 'function w3tc_flush_all() { $GLOBALS["PURGED"][] = "w3tc"; }' );
eval( 'function wp_cache_clear_cache() { $GLOBALS["PURGED"][] = "supercache"; }' );
fresh_db();
boot();
update_option( 'litespeed.conf.cache-ttl_pub', 3600 );
update_option( 'wp_rocket_settings', array( 'purge_cron_interval' => 2, 'purge_cron_unit' => 'DAY_IN_SECONDS' ) );
add_action( 'litespeed_purge_all', function () { $GLOBALS['PURGED'][] = 'litespeed'; } );
add_action( 'breeze_clear_all_cache', function () { throw new RuntimeException( 'Breeze is not configured' ); } );
add_action( 'wpfc_clear_all_cache', function () { $GLOBALS['PURGED'][] = 'wpfc'; } );
$n = records_phase( $P );
$u = TCGiant_Sync_Size_Trim::run_state();
check( 'all six asked their own way; one that throws is named, the rest still purged', array( $PURGED, $u['purged'], logged( 'asking Breeze to purge its page cache ran into an error (Breeze is not configured)' ) ), array( array( 'litespeed', 'rocket', 'w3tc', 'supercache', 'wpfc' ), array( 'LiteSpeed Cache', 'WP Rocket', 'W3 Total Cache', 'WP Super Cache', 'WP Fastest Cache' ), true ) );
check( '  the longest lifetime that says so is waited out: WP Rocket\'s two days, and an hour', waits( $n ), 2 * 86400 + 3600 );
check( '  and Cloudflare, still active, is still to purge by hand', array( $u['unpurged'], TCGiant_Sync_Size_Trim::status()['run']['unpurged'] ), array( array( 'Breeze', 'Cloudflare' ), array( 'Breeze', 'Cloudflare' ) ) );
update_option( 'wp_rocket_settings', array( 'purge_cron_interval' => 0, 'purge_cron_unit' => 'HOUR_IN_SECONDS' ) );
check( '  WP Rocket never clearing its cache: the longest wait, 8 days', TCGiant_Sync_Size_Trim::files_delay(), 691200 );

// The screen, waiting: what was purged, what to purge by hand; and the
// button's question says to purge.
ob_start();
eval( '?>' . substr( $src, $from, $to - $from ) );
$html = ob_get_clean();
check( 'the screen, waiting: what was purged, and what to purge by hand', array( false !== strpos( $html, 'Purged: LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache.' ), false !== strpos( $html, 'Breeze, Cloudflare cannot be purged by TCGiant: purge it by hand before the files go.' ) ), array( true, true ) );
check( '  and the button\'s question says what is purged, and to purge any other cache or CDN', false !== strpos( $html, 'When every record is done, TCGiant purges LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, Breeze or WP Fastest Cache; purge any other page cache or CDN, such as Cloudflare, yourself then' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nIN THE SOURCE\n" . str_repeat( '=', 126 ) . "\n";

$trim = file_get_contents( $ROOT . '/includes/class-tcgiant-sync-size-trim.php' );
check( 'the record is changed through its own row, over what was read, never wp_update_attachment_metadata()', array( 1 === preg_match( '/UPDATE \{\$wpdb->postmeta\} SET meta_value = %s WHERE meta_id = %d AND \{\$exact\}meta_value = %s/', $trim ), false !== strpos( $trim, 'wp_update_attachment_metadata(' . ' $' ) ), array( true, false ) );
check( '  byte for byte on MySQL and MariaDB only', substr_count( $trim, "\$exact = ! empty( \$wpdb->is_mysql ) ? 'BINARY ' : '';" ), 1 );
check( 'files are deleted with wp_delete_file_from_directory() and the photo\'s folder only', array( substr_count( $trim, 'wp_delete_file_from_directory( $file, $dir )' ), substr_count( $trim, 'unlink(' ) ), array( 1, 0 ) );
check( 'the files wait a day', TCGiant_Sync_Size_Trim::FILES_AFTER_SECONDS, 86400 );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
