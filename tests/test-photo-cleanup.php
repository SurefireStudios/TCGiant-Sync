<?php
/**
 * Harness: photos of deleted products, and the ones already left behind.
 *
 * Up to 3.27.3 nothing ever deleted a photo the plugin downloaded. A product
 * that sold went to the Trash, WordPress emptied the Trash thirty days later,
 * and every photo stayed on disk with its resized copies. A shop selling one
 * of each of fifteen thousand parts ran out of space.
 *
 * 3.27.4 marks a product's downloaded photos when the product is deleted for
 * good, and deletes them in the background fifteen minutes later after
 * checking again; and Image Cleanup counts the ones left behind before - and
 * those of products already in the Trash when 3.27.4 arrived - and removes
 * them when the shop presses a button.
 *
 * Deleting photographs cannot be undone, so most of this is about what must
 * NOT go: the shop's own uploads, eBay stand-ins, anything a product,
 * variation, post, category, widget or the site still shows - in the Trash
 * too, by ID or by its file's address - a file another Media Library entry
 * shares, and a photo a deletion was started on and given up, which must not
 * be handed to another product either. And nothing goes on the strength of a
 * read the database failed: wpdb answers one with an empty result, which
 * would otherwise read as "nothing uses it".
 *
 * This loads the real TCGiant_Sync_Photo_Cleanup and TCGiant_Sync_Runtime,
 * and the image batch for its reuse of downloads, against a real SQL
 * database (SQLite, through the same SQL the plugin sends MySQL) and real
 * files in a temporary uploads folder. WordPress's own deletion is played as
 * WordPress does it, in the same order, because that order is what the design
 * leans on. A host killing a request is a PHP fatal, or the process killed
 * outright with no shutdown function run, so those parts run as processes of
 * their own (this file again, with --child), and the death is real.
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
	// The plugin raises PHP's time limit, as it does on a host, and on Windows
	// that limit counts time on the clock. This file runs far longer than any
	// request - slower still beside other suites - so each assertion lifts it
	// again. What the plugin believes it was given is its own, and unchanged.
	@set_time_limit( 0 );
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-82s got %-16s want %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 200 ), substr( str_replace( "\n", ' ', var_export( $want, true ) ), 0, 200 ) );
}

$ROOT      = getenv( 'TCG_ROOT' ) ?: dirname( __DIR__ );
$CLEANUP   = $ROOT . '/includes/class-tcgiant-sync-photo-cleanup.php';
$LOCALIZER = $ROOT . '/includes/class-tcgiant-sync-image-localizer.php';

$WORLD = str_replace( '\\', '/', sys_get_temp_dir() ) . '/tcg-photo-cleanup-' . substr( md5( __FILE__ ), 0, 8 );
@mkdir( $WORLD, 0777, true );

define( 'ABSPATH', $WORLD . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'EMPTY_TRASH_DAYS', 30 );

// ─── the database ─────────────────────────────────────────────────────────────

class Harness_Wpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $termmeta = 'wp_termmeta';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $options  = 'wp_options';
	public $prefix   = 'wp_';
	public $last_error = '';
	public $queries  = 0;
	/** INSERTs naming any of these fail, as on a database whose disk is full. */
	public $refuse   = array();
	/** A query containing any of these throws, as a database driver set to throw does. */
	public $explode  = array();
	/** A query containing any of these fails the way wpdb reports it: an empty result and last_error set. No exception. */
	public $silent   = array();
	/** Indexes SHOW INDEX finds on wp_postmeta. */
	public $indexes  = array( 'tcgiant_key_value' );
	/** Every query run, when this is an array. */
	public $log      = null;
	/** Called with each query before it runs, when set: time passing in the middle of a reading, say. */
	public $on_query = null;
	private $quiet   = false;
	/** @var SQLite3 */
	public $db;

	public function __construct( $file ) {
		$this->db = new SQLite3( $file );
		$this->db->busyTimeout( 5000 );
		// Written ahead to a log and not flushed to disk on every statement:
		// as safe as before against a process killed outright, which is all
		// this asks of it, and seven times faster on Windows.
		$this->db->exec( 'PRAGMA journal_mode = WAL; PRAGMA synchronous = OFF;' );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i   = 0;
		$out = preg_replace_callback( '/%(%|d|s|f)/', function ( $m ) use ( &$args, &$i ) {
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
		return $out;
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
		foreach ( $this->explode as $needle ) {
			if ( false !== strpos( $sql, $needle ) ) {
				throw new RuntimeException( 'MySQL server has gone away' );
			}
		}
		foreach ( $this->silent as $needle ) {
			if ( false !== strpos( $sql, $needle ) ) {
				$this->last_error = 'Got error 28 from storage engine';
				return false;
			}
		}
		foreach ( $this->refuse as $needle ) {
			if ( 0 === stripos( ltrim( $sql ), 'INSERT' ) && false !== strpos( $sql, $needle ) ) {
				$this->last_error = 'The table \'wp_options\' is full';
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
		return $res;
	}

	public function get_results( $sql ) {
		if ( preg_match( "/^SHOW INDEX FROM wp_postmeta WHERE Key_name = '(\\w+)'$/", trim( $sql ), $m ) ) {
			$this->queries++;
			$this->last_error = '';
			return in_array( $m[1], $this->indexes, true ) ? array( (object) array( 'Key_name' => $m[1] ) ) : array();
		}
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

	/** How many times wpdb was asked to let go of its last answer. */
	public $flushes = 0;

	/** wpdb::flush(): its last answer, and its last error, let go of. */
	public function flush() {
		$this->flushes++;
		$this->last_error = '';
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

function fresh_db( $unique_options = true ) {
	global $wpdb, $WORLD;
	if ( isset( $wpdb ) ) {
		$wpdb->db->close();
	}
	foreach ( array( '', '-wal', '-shm' ) as $tail ) {
		@unlink( $WORLD . '/site.sqlite' . $tail );
	}
	$wpdb = new Harness_Wpdb( $WORLD . '/site.sqlite' );
	$wpdb->db->exec( "
		CREATE TABLE wp_posts ( ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_parent INTEGER NOT NULL DEFAULT 0, post_title TEXT DEFAULT '', post_content TEXT NOT NULL DEFAULT '', post_excerpt TEXT NOT NULL DEFAULT '' );
		CREATE INDEX post_parent ON wp_posts ( post_parent );
		CREATE INDEX type_status ON wp_posts ( post_type, post_status );
		CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT );
		CREATE INDEX pm_post ON wp_postmeta ( post_id );
		CREATE INDEX pm_key ON wp_postmeta ( meta_key, meta_value );
		CREATE TABLE wp_termmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER, meta_key TEXT, meta_value TEXT );
		CREATE TABLE wp_term_taxonomy ( term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER, taxonomy TEXT, description TEXT NOT NULL DEFAULT '', parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0 );
		CREATE TABLE wp_options ( option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT " . ( $unique_options ? 'UNIQUE' : '' ) . ", option_value TEXT, autoload TEXT );
	" );

	// The uploads folder, empty.
	$up = $WORLD . '/uploads';
	if ( is_dir( $up ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $up, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
	}
	@mkdir( $up . '/2026/10', 0777, true );

	$GLOBALS['NEXT_ID'] = 100;
	$GLOBALS['LOG']     = array();
	$GLOBALS['DENIED']  = array();
	@unlink( $WORLD . '/activity.log' );
	@set_time_limit( 0 ); // See check().
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
function wp_basename( $path ) { return basename( str_replace( '\\', '/', (string) $path ) ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_get_upload_dir() { return array( 'basedir' => $GLOBALS['WORLD'] . '/uploads' ); }
function size_format( $bytes, $decimals = 0 ) { return number_format( $bytes / 1024, $decimals ) . ' KB'; }
function wp_date( $format, $ts = null ) { return gmdate( $format, $ts ?? time() ); }
function get_current_user_id() { return 1; }
function wp_get_current_user() { return (object) array( 'user_login' => 'haz' ); }
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
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
function wp_parse_id_list( $list ) {
	$list = is_array( $list ) ? $list : preg_split( '/[\s,]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY );
	return array_unique( array_map( 'absint', $list ) );
}
function absint( $v ) { return abs( (int) $v ); }
function check_ajax_referer( $action ) { return true; }
/** Whoever the request is, allowed everything but what $DENIED lists. WP-Cron and WP-CLI run as nobody. */
function current_user_can( $cap ) { return ! in_array( $cap, $GLOBALS['DENIED'] ?? array(), true ); }
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
	// A host killing the request just after a given option is written.
	if ( ! empty( $GLOBALS['KILL_AFTER_OPTION'] ) && $GLOBALS['KILL_AFTER_OPTION'] === $name ) {
		die_for_real();
	}
	return true;
}
function delete_option( $name ) { q( 'DELETE FROM wp_options WHERE option_name = ' . esc( $name ) ); return true; }
function get_transient( $name ) { return get_option( '_transient_' . $name ); }
function set_transient( $name, $value, $ttl = 0 ) { return update_option( '_transient_' . $name, $value ); }
/**
 * Post types, as registered: whether each is public, has a screen of its own
 * in the admin (show_ui), and opens in the block editor (show_in_rest).
 * WooCommerce's orders and coupons have a screen and are not public;
 * GeneratePress's Elements have a screen and are not public; another plugin's
 * log of sent emails has neither. Core's and WooCommerce's as they register
 * them.
 */
$POST_TYPES = array(
	'post'              => array( true, true, true ),
	'page'              => array( true, true, true ),
	'attachment'        => array( true, true, true ),
	'product'           => array( true, true, true ),
	'revision'          => array( false, false, false ),
	'nav_menu_item'     => array( false, false, true ),
	'wp_block'          => array( false, true, true ),
	'wp_template'       => array( false, false, true ),
	'wp_template_part'  => array( false, false, true ),
	'wp_navigation'     => array( false, true, true ),
	'wp_global_styles'  => array( false, false, false ),
	'custom_css'        => array( false, false, false ),
	'customize_changeset' => array( false, false, false ),
	'product_variation' => array( false, false, false ),
	'shop_order'        => array( false, true, false ),
	'shop_order_refund' => array( false, false, false ),
	'shop_coupon'       => array( false, true, false ),
	'scheduled-action'  => array( false, false, false ),
	'postman_sent_mail' => array( false, false, false ),
	'gp_elements'       => array( false, true, false ),
	'harness_layout'    => array( false, false, true ),
);
function get_post_types( $args = array() ) {
	$types = array();
	foreach ( $GLOBALS['POST_TYPES'] as $type => $flags ) {
		$has = array_combine( array( 'public', 'show_ui', 'show_in_rest' ), array_map( 'boolval', (array) $flags ) );
		foreach ( $args as $key => $want ) {
			if ( ! isset( $has[ $key ] ) || $has[ $key ] !== (bool) $want ) {
				continue 2;
			}
		}
		$types[ $type ] = $type;
	}
	return $types;
}
/** WooCommerce's order types, as wc_get_order_types() names them. */
function wc_get_order_types( $for = '' ) {
	return array( 'shop_order', 'shop_order_refund' );
}
function get_stylesheet() { return 'harness'; }

// Post meta.
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
	q( 'INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( ' . (int) $id . ', ' . esc( $key ) . ', ' . esc( maybe_serialize( $value ) ) . ' )' );
	return (int) $GLOBALS['wpdb']->db->lastInsertRowID();
}
function update_post_meta( $id, $key, $value ) {
	if ( null === $GLOBALS['wpdb']->get_var( 'SELECT meta_id FROM wp_postmeta WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) ) ) {
		return add_post_meta( $id, $key, $value );
	}
	q( 'UPDATE wp_postmeta SET meta_value = ' . esc( maybe_serialize( $value ) ) . ' WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) );
	return true;
}
function delete_post_meta( $id, $key ) { q( 'DELETE FROM wp_postmeta WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) ); return true; }
function set_post_thumbnail( $id, $thumb ) { return update_post_meta( $id, '_thumbnail_id', (string) (int) $thumb ); }
function get_post_thumbnail_id( $id ) { return (int) get_post_meta( $id, '_thumbnail_id', true ); }

// Posts.
function get_post( $id ) {
	$rows = $GLOBALS['wpdb']->get_results( 'SELECT * FROM wp_posts WHERE ID = ' . (int) $id );
	return $rows ? $rows[0] : null;
}
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function exists( $id ) { return null !== get_post( $id ); }
function new_post( $type, $status = 'publish', $parent = 0, $content = '' ) {
	$id = $GLOBALS['NEXT_ID']++;
	q( "INSERT INTO wp_posts ( ID, post_type, post_status, post_parent, post_content ) VALUES ( {$id}, " . esc( $type ) . ', ' . esc( $status ) . ', ' . (int) $parent . ', ' . esc( $content ) . ' )' );
	return $id;
}

// Hooks, as WordPress keeps them.
$HOOKS = array();
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['HOOKS'][ $hook ][] = array( $cb, $args ); return true; }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return add_filter( $hook, $cb, $priority, $args ); }
function remove_all( $hook ) { unset( $GLOBALS['HOOKS'][ $hook ] ); }
function apply_filters( $hook, $value, ...$more ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $h ) {
		$value = call_user_func_array( $h[0], array_slice( array_merge( array( $value ), $more ), 0, $h[1] ) );
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $h ) {
		call_user_func_array( $h[0], array_slice( $args, 0, $h[1] ) );
	}
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
		@unlink( $delete );
	}
}

/**
 * wp_delete_attachment(), in the order WordPress 6.6 does it: the record of
 * the files is read first; the delete_attachment action; every featured-image
 * pointer to it; its meta, one row at a time; the delete_post action; the row;
 * and only then the files - none of which checks whether another attachment
 * uses the same file, or says whether a file could be deleted.
 */
function wp_delete_attachment( $id, $force = false ) {
	$post = get_post( $id );
	if ( ! $post ) {
		return null;
	}
	$check = apply_filters( 'pre_delete_attachment', null, $post, $force );
	if ( null !== $check ) {
		return $check;
	}
	$file   = (string) get_post_meta( $id, '_wp_attached_file', true );
	$meta   = get_post_meta( $id, '_wp_attachment_metadata', true );
	$backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );

	do_action( 'delete_attachment', $id, $post );

	q( "DELETE FROM wp_postmeta WHERE meta_key = '_thumbnail_id' AND meta_value = " . esc( $id ) );
	foreach ( $GLOBALS['wpdb']->get_col( 'SELECT meta_id FROM wp_postmeta WHERE post_id = ' . (int) $id ) as $mid ) {
		q( 'DELETE FROM wp_postmeta WHERE meta_id = ' . (int) $mid );
	}
	do_action( 'delete_post', $id, $post );
	q( 'DELETE FROM wp_posts WHERE ID = ' . (int) $id );

	if ( '' !== $file ) {
		$base = $GLOBALS['WORLD'] . '/uploads/';
		$dir  = dirname( $base . $file );
		foreach ( (array) ( is_array( $meta ) ? ( $meta['sizes'] ?? array() ) : array() ) as $size ) {
			wp_delete_file( $dir . '/' . $size['file'] );
		}
		if ( is_array( $backup ) ) {
			foreach ( $backup as $size ) {
				wp_delete_file( $dir . '/' . $size['file'] );
			}
		}
		wp_delete_file( $base . $file );
	}
	return $post;
}

/**
 * wp_delete_post() for a product or variation, in WordPress's order:
 * before_delete_post with everything still in place; attachments moved up
 * to the deleted post's own parent; its meta; delete_post - where WooCommerce
 * deletes a product's variations; and the row.
 */
function wp_delete_post( $id, $force = false ) {
	$post = get_post( $id );
	if ( ! $post ) {
		return null;
	}
	if ( 'attachment' === $post->post_type ) {
		return wp_delete_attachment( $id, $force );
	}
	do_action( 'before_delete_post', $id, $post );
	q( 'UPDATE wp_posts SET post_parent = ' . (int) $post->post_parent . " WHERE post_parent = {$id} AND post_type = 'attachment'" );
	q( 'DELETE FROM wp_postmeta WHERE post_id = ' . (int) $id );
	do_action( 'delete_post', $id, $post );
	q( 'DELETE FROM wp_posts WHERE ID = ' . (int) $id );
	return $post;
}

/** wp_trash_post(), in WordPress's order: the action first, then the record of when. It hands back the post, as WordPress does. */
function wp_trash_post( $id ) {
	do_action( 'wp_trash_post', $id );
	update_post_meta( $id, '_wp_trash_meta_status', 'publish' );
	update_post_meta( $id, '_wp_trash_meta_time', time() );
	q( "UPDATE wp_posts SET post_status = 'trash' WHERE ID = " . (int) $id );
	return get_post( $id );
}

/** wp_scheduled_delete(): the daily emptying of the Trash - every trashed post on its own record. */
function wp_scheduled_delete() {
	$cutoff = time() - DAY_IN_SECONDS * EMPTY_TRASH_DAYS;
	foreach ( $GLOBALS['wpdb']->get_col( "SELECT post_id FROM wp_postmeta WHERE meta_key = '_wp_trash_meta_time' AND CAST( meta_value AS SIGNED ) < {$cutoff} ORDER BY meta_id" ) as $id ) {
		$p = get_post( $id );
		if ( $p && 'trash' === $p->post_status ) {
			wp_delete_post( (int) $id );
		}
	}
}

/** Everything in the Trash trashed this long ago, as if it had been. */
function trashed_days_ago( $days ) {
	q( "UPDATE wp_postmeta SET meta_value = '" . ( time() - (int) $days * DAY_IN_SECONDS ) . "' WHERE meta_key = '_wp_trash_meta_time'" );
}

// WooCommerce: a product's variations go to the Trash with it, and are
// deleted with it - by someone allowed to delete posts. WC_Post_Data's
// delete_post returns early for anyone else, which is how WP-Cron and WP-CLI
// without a user run.
function wc_hooks() {
	add_action( 'wp_trash_post', function ( $id ) {
		if ( 'product' === get_post_type( $id ) ) {
			foreach ( $GLOBALS['wpdb']->get_col( "SELECT ID FROM wp_posts WHERE post_type = 'product_variation' AND post_parent = " . (int) $id ) as $v ) {
				wp_trash_post( (int) $v );
			}
		}
	} );
	add_action( 'delete_post', function ( $id ) {
		if ( ! current_user_can( 'delete_posts' ) ) {
			return;
		}
		if ( 'product' === get_post_type( $id ) ) {
			foreach ( $GLOBALS['wpdb']->get_col( "SELECT ID FROM wp_posts WHERE post_type = 'product_variation' AND post_parent = " . (int) $id ) as $v ) {
				wp_delete_post( (int) $v, true );
			}
		}
	} );
}

/** get_posts(), with the meta_query shapes the image batch uses. */
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['wpdb']->get_col( 'SELECT ID FROM wp_posts WHERE post_type = ' . esc( $args['post_type'] ?? 'post' ) . ' ORDER BY ID' ) as $id ) {
		$id = (int) $id;
		if ( isset( $args['post_parent'] ) && (int) get_post( $id )->post_parent !== (int) $args['post_parent'] ) {
			continue;
		}
		if ( isset( $args['meta_key'] ) && get_post_meta( $id, $args['meta_key'], true ) !== $args['meta_value'] ) {
			continue;
		}
		foreach ( $args['meta_query'] ?? array() as $mq ) {
			$have = $GLOBALS['wpdb']->get_col( 'SELECT meta_value FROM wp_postmeta WHERE post_id = ' . $id . ' AND meta_key = ' . esc( $mq['key'] ) );
			if ( 'NOT EXISTS' === ( $mq['compare'] ?? '' ) ? ! empty( $have ) : ! in_array( (string) $mq['value'], $have, true ) ) {
				continue 2;
			}
		}
		$out[] = $id;
	}
	$limit = (int) ( $args['numberposts'] ?? 5 );
	return $limit < 0 ? $out : array_slice( $out, 0, $limit );
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
	public static function request_dispatch() {}
}

function die_for_real() {
	// A real, uncatchable fatal - what a host's memory limit does.
	ini_set( 'memory_limit', '32M' );
	$GLOBALS['TOO_BIG'] = str_repeat( 'x', 64 * 1048576 );
}

function kill_for_real() {
	// The process ended from outside, with no shutdown function run - what
	// PHP-FPM's request_terminate_timeout, LiteSpeed or the out-of-memory
	// killer does.
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
require $CLEANUP;
require $LOCALIZER;

$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
$CLOCK_OFFSET = 0.0;
TCGiant_Sync_Runtime::$clock        = function () { return (float) $_SERVER['REQUEST_TIME_FLOAT'] + $GLOBALS['CLOCK_OFFSET']; };
TCGiant_Sync_Runtime::$memory_probe = function () { return 0; };

function boot() {
	$GLOBALS['HOOKS'] = array();
	wc_hooks();
	$r = new ReflectionProperty( 'TCGiant_Sync_Photo_Cleanup', '_instance' );
	$r->setAccessible( true );
	$r->setValue( null, null );
	foreach ( array( 'held_back' => array(), 'held' => array(), 'uses' => null, 'charged' => 0, 'context' => '', 'marked_now' => array( 'photos' => 0, 'products' => 0, 'held_back' => 0 ) ) as $name => $value ) {
		$p = new ReflectionProperty( 'TCGiant_Sync_Photo_Cleanup', $name );
		$p->setAccessible( true );
		$p->setValue( null, $value );
	}
	TCGiant_Sync_Photo_Cleanup::instance();
}

/** This version was installed this many days ago. */
function installed_days_ago( $days ) {
	update_option( TCGiant_Sync_Photo_Cleanup::SINCE_OPTION, time() - (int) $days * DAY_IN_SECONDS );
}

// ─── the shop ─────────────────────────────────────────────────────────────────

/**
 * A photo with its file and three resized copies on disk, as the image batch
 * (ours) or the shop (theirs) leaves one.
 *
 * @param int    $parent  Post it is attached to.
 * @param string $kind    'ours', 'theirs', 'standin' (an eBay stand-in, no file) or 'offloaded' (ours, files gone).
 * @param string $file    File name to use, or '' for one of its own.
 */
function photo( $parent, $kind = 'ours', $file = '' ) {
	$id   = new_post( 'attachment', 'inherit', $parent );
	$name = '' !== $file ? $file : sprintf( 'part-%d-%s.jpg', $id, substr( md5( (string) $id ), 0, 8 ) );
	$base = pathinfo( $name, PATHINFO_FILENAME );
	$url  = sprintf( 'https://i.ebayimg.com/images/g/P%dAAOSw/s-l1600.jpg', $id );

	if ( 'standin' === $kind ) {
		update_post_meta( $id, '_tcgiant_external_url', $url );
		update_post_meta( $id, '_tcgiant_source_url', $url );
		update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 800, 'height' => 800, 'sizes' => array() ) );
		return $id;
	}

	$sizes = array();
	foreach ( array( '150x150', '300x300', '768x768' ) as $dims ) {
		$sizes[ $dims ] = array( 'file' => "{$base}-{$dims}.jpg" );
	}
	if ( 'theirs' !== $kind ) {
		update_post_meta( $id, '_tcgiant_source_url', $url );
	}
	update_post_meta( $id, '_wp_attached_file', '2026/10/' . $name );
	update_post_meta( $id, '_wp_attachment_metadata', array( 'file' => '2026/10/' . $name, 'sizes' => $sizes ) );

	if ( 'offloaded' !== $kind && '' === $file ) {
		$dir = $GLOBALS['WORLD'] . '/uploads/2026/10/';
		file_put_contents( $dir . $name, str_repeat( 'J', 1000 + $id ) );
		foreach ( $sizes as $s ) {
			file_put_contents( $dir . $s['file'], str_repeat( 'j', 100 ) );
		}
	}
	return $id;
}

/** Bytes of a photo's files on disk. */
function bytes_of( $id ) {
	$r = TCGiant_Sync_Photo_Cleanup::file_records( array( $id ) );
	return TCGiant_Sync_Photo_Cleanup::bytes_here( $r[ $id ] )[0];
}

/** Whether every file a photo had is still on disk. */
function files_intact( array $paths ) {
	foreach ( $paths as $p ) {
		if ( ! is_file( $p ) ) {
			return false;
		}
	}
	return (bool) $paths;
}
function paths_of( $id ) {
	$r = TCGiant_Sync_Photo_Cleanup::file_records( array( $id ) );
	return TCGiant_Sync_Photo_Cleanup::local_files( $r[ $id ] );
}

function product( $status = 'publish', $thumb = 0, array $gallery = array() ) {
	$id = new_post( 'product', $status );
	if ( $thumb ) {
		set_post_thumbnail( $id, $thumb );
	}
	if ( $gallery ) {
		update_post_meta( $id, '_product_image_gallery', implode( ',', $gallery ) );
	}
	if ( 'trash' === $status ) {
		update_post_meta( $id, '_wp_trash_meta_time', time() );
	}
	return $id;
}

function variation( $parent, $status = 'publish' ) {
	$id = new_post( 'product_variation', $status, $parent );
	if ( 'trash' === $status ) {
		update_post_meta( $id, '_wp_trash_meta_time', time() );
	}
	return $id;
}

/**
 * A category, tag, brand or any other term, with its description, as
 * WordPress keeps one in wp_term_taxonomy. Its term_id is its own
 * term_taxonomy_id here, as on most sites.
 */
function term( $taxonomy, $description = '' ) {
	q( 'INSERT INTO wp_term_taxonomy ( taxonomy, description, count ) VALUES ( ' . esc( $taxonomy ) . ', ' . esc( $description ) . ', 1 )' );
	$id = (int) $GLOBALS['wpdb']->db->lastInsertRowID();
	q( "UPDATE wp_term_taxonomy SET term_id = {$id} WHERE term_taxonomy_id = {$id}" );
	return $id;
}
function add_term_meta( $term_id, $key, $value ) {
	q( 'INSERT INTO wp_termmeta ( term_id, meta_key, meta_value ) VALUES ( ' . (int) $term_id . ', ' . esc( $key ) . ', ' . esc( maybe_serialize( $value ) ) . ' )' );
}

function marked( $id ) { return '' !== get_post_meta( $id, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true ); }
function given_up( $id ) { return '' !== get_post_meta( $id, TCGiant_Sync_Photo_Cleanup::FAILED_KEY, true ); }

/**
 * Time goes by: every time the plugin and WordPress have kept is moved back
 * by as much, so that what is due, lapsed or long ago is so.
 */
function time_passes( $seconds = 1000 ) {
	$s = (int) $seconds;
	q( "UPDATE wp_postmeta SET meta_value = CAST( meta_value AS INTEGER ) - {$s} WHERE meta_key IN ( '_tcgiant_delete_after', '_wp_trash_meta_time' )" );

	$scan = get_option( 'tcgiant_sync_photo_scan', array() );
	if ( ! empty( $scan['finished_at'] ) ) {
		$scan['finished_at'] -= $s;
		update_option( 'tcgiant_sync_photo_scan', $scan );
	}
	foreach ( array( 'tcgiant_sync_last_crash', 'tcgiant_sync_last_crash_photo_cleanup' ) as $name ) {
		$crash = get_option( $name, array() );
		if ( ! empty( $crash['at'] ) ) {
			$crash['at'] -= $s;
			update_option( $name, $crash );
		}
	}
	$pause = get_option( TCGiant_Sync_Photo_Cleanup::PAUSE_OPTION, array() );
	if ( ! empty( $pause ) ) {
		$pause['until']    -= $s;
		$pause['crash_at'] -= $s;
		update_option( TCGiant_Sync_Photo_Cleanup::PAUSE_OPTION, $pause );
	}
	foreach ( array( TCGiant_Sync_Photo_Cleanup::DELETING_OPTION, TCGiant_Sync_Photo_Cleanup::READING_OPTION, TCGiant_Sync_Photo_Cleanup::CHECK_READING_OPTION ) as $name ) {
		$note = get_option( $name, array() );
		if ( ! empty( $note['at'] ) ) {
			$note['at'] -= $s;
			update_option( $name, $note );
		}
	}
	$stats = get_option( TCGiant_Sync_Photo_Cleanup::STATS_OPTION, array() );
	if ( ! empty( $stats['last_run'] ) ) {
		$stats['last_run'] -= $s;
		update_option( TCGiant_Sync_Photo_Cleanup::STATS_OPTION, $stats );
	}
	$since = (int) get_option( TCGiant_Sync_Photo_Cleanup::SINCE_OPTION, 0 );
	if ( $since > 0 ) {
		update_option( TCGiant_Sync_Photo_Cleanup::SINCE_OPTION, $since - $s );
	}
}

/** The pause, over - without the time for anything else to lapse. */
function end_pause() {
	$pause = get_option( TCGiant_Sync_Photo_Cleanup::PAUSE_OPTION, array() );
	if ( ! empty( $pause ) ) {
		$pause['until'] = time() - 1;
		update_option( TCGiant_Sync_Photo_Cleanup::PAUSE_OPTION, $pause );
	}
}

function pause() {
	return array_merge( array( 'until' => 0, 'seconds' => 0 ), (array) get_option( TCGiant_Sync_Photo_Cleanup::PAUSE_OPTION, array() ) );
}

function run_queue() {
	TCGiant_Sync_Photo_Cleanup::instance()->process_queue();
	@set_time_limit( 0 ); // See check().
}

function logged( $needle ) {
	foreach ( $GLOBALS['LOG'] as $line ) {
		if ( false !== strpos( $line, $needle ) ) {
			return true;
		}
	}
	return false;
}

function times_logged( $needle ) {
	return count( array_filter( $GLOBALS['LOG'], function ( $l ) use ( $needle ) { return false !== strpos( $l, $needle ); } ) );
}

function in_child( $what ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( '--child=' . $what ) . ' 2>&1';
	exec( $cmd, $out, $code );
	open_db();
	$GLOBALS['LOG'] = is_file( $GLOBALS['WORLD'] . '/activity.log' ) ? file( $GLOBALS['WORLD'] . '/activity.log', FILE_IGNORE_NEW_LINES ) : array();
	return $code;
}

/** The image batch's reuse of a download, as it asks. */
$FINDER = new ReflectionMethod( 'TCGiant_Sync_Image_Localizer', 'find_existing_local_attachment' );
$FINDER->setAccessible( true );
$BATCH = ( new ReflectionClass( 'TCGiant_Sync_Image_Localizer' ) )->newInstanceWithoutConstructor();
function reused_for( $product_id, $url ) {
	return $GLOBALS['FINDER']->invoke( $GLOBALS['BATCH'], $product_id, $url );
}

// ─── in a request of its own ──────────────────────────────────────────────────

if ( null !== $CHILD ) {
	open_db();
	boot();
	$parts = explode( ':', $CHILD );
	if ( 'queue' === $parts[0] ) {
		// Kill the request when WordPress starts deleting this photo - inside
		// the delete_attachment action, where an offload plugin removes its
		// remote copies and where a host's limit is most likely to land.
		$kill = (int) ( $parts[1] ?? 0 );
		add_action( 'delete_attachment', function ( $id ) use ( $kill ) {
			if ( $kill && (int) $id === $kill ) {
				die_for_real();
			}
		} );
		run_queue();
	} elseif ( 'hardkill' === $parts[0] ) {
		// The same, with the process ended from outside: no shutdown function,
		// so no record of the death but what the run itself wrote first.
		// With no photo named, every deletion this run starts is killed.
		$kill = (int) ( $parts[1] ?? 0 );
		add_action( 'delete_attachment', function ( $id ) use ( $kill ) {
			if ( ! $kill || (int) $id === $kill ) {
				file_put_contents( $GLOBALS['WORLD'] . '/killed-on.txt', (int) $id . "\n", FILE_APPEND );
				kill_for_real();
			}
		} );
		run_queue();
	} elseif ( 'scan' === $parts[0] ) {
		// Kill the request just after a page's finds are written and before
		// the state that counts them is.
		$GLOBALS['KILL_AFTER_OPTION'] = $parts[1] ?? '';
		TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
	} elseif ( 'reading' === $parts[0] ) {
		// Die while reading where photos are used on the site - what a host's
		// limit does on a shop too big to read in time: with a real fatal, or
		// ended from outside. Then a run, or a step of the check.
		$how = $parts[1] ?? 'fatal';
		add_filter( 'tcgiant_sync_photo_content_types', function ( $types ) use ( $how ) {
			'kill' === $how ? kill_for_real() : die_for_real();
			return $types;
		} );
		if ( 'check' === ( $parts[2] ?? '' ) ) {
			$_POST = array();
			try {
				TCGiant_Sync_Photo_Cleanup::ajax_scan();
			} catch ( Harness_Json $answer ) {
				unset( $answer );
			}
		} else {
			run_queue();
		}
	}
	exit( 0 );
}

// ═════════════════════════════════════════════════════════════════════════════

echo "\nA PRODUCT DELETED FOR GOOD TAKES ITS DOWNLOADED PHOTOS WITH IT\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
boot();
installed_days_ago( 40 );

$P = new_post( 'product', 'publish' );            // the product that sells
$L = product( 'publish' );                         // a live product
$T1 = photo( $P );                                  // its main photo
$G1 = photo( $P );
$G2 = photo( $P );
$G3 = photo( $P, 'theirs' );                        // one the shop uploaded
$PH = photo( $P, 'standin' );                       // an eBay stand-in
$S  = photo( $P );                                  // shared: the live product shows it too
$C  = photo( $P );                                  // a category's picture
$D  = photo( $P, 'ours', 'legacy.jpg' );            // an old duplicate record...
$D2 = photo( $L, 'ours', 'legacy.jpg' );            // ...of the live product's main photo
file_put_contents( $WORLD . '/uploads/2026/10/legacy.jpg', str_repeat( 'L', 4000 ) );
$V  = variation( $P );
$VT = photo( $P );                                  // the variation's photo

set_post_thumbnail( $P, $T1 );
update_post_meta( $P, '_product_image_gallery', implode( ',', array( $G1, $G2, $G3, $PH, $S, $C, $D ) ) );
set_post_thumbnail( $V, $VT );
update_post_meta( $L, '_product_image_gallery', (string) $S );
set_post_thumbnail( $L, $D2 );
q( "INSERT INTO wp_termmeta ( term_id, meta_key, meta_value ) VALUES ( 7, 'thumbnail_id', " . esc( $C ) . ' )' );

$paths = array();
foreach ( array( $T1, $G1, $G2, $G3, $S, $C, $D, $D2, $VT ) as $id ) {
	$paths[ $id ] = paths_of( $id );
}
$expect_bytes = bytes_of( $T1 ) + bytes_of( $G1 ) + bytes_of( $G2 ) + bytes_of( $VT );

wp_trash_post( $P );
check( 'sold: moved to the Trash - nothing marked', TCGiant_Sync_Photo_Cleanup::waiting(), 0 );

// Thirty days on, WordPress empties the Trash - from WP-Cron, as nobody.
trashed_days_ago( 31 );
$DENIED = array( 'delete_posts' );
wp_scheduled_delete();
$DENIED = array();
do_action( 'shutdown' );

check( 'Trash emptied: product and variation gone', array( exists( $P ), exists( $V ) ), array( false, false ) );
check( '  every downloaded photo it showed or held is marked', array_map( 'marked', array( $T1, $G1, $G2, $S, $C, $D, $VT ) ), array_fill( 0, 7, true ) );
check( '  the shop\'s upload is not', marked( $G3 ), false );
check( '  nor the eBay stand-in', marked( $PH ), false );
check( '  WordPress moved its attachments to no parent', (int) get_post( $T1 )->post_parent, 0 );
check( '  one line for the request', times_logged( 'will be removed in about 15 minutes' ), 1 );
check( '  the next run is set for after the quarantine', wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) >= time() + 900, true );

run_queue();
check( 'a run before the quarantine is over deletes nothing', array( exists( $T1 ), files_intact( $paths[ $T1 ] ), TCGiant_Sync_Photo_Cleanup::waiting() ), array( true, true, 7 ) );

time_passes();
run_queue();

check( 'after it: the main photo, gallery photos and the variation\'s photo are gone', array_map( 'exists', array( $T1, $G1, $G2, $VT ) ), array( false, false, false, false ) );
check( '  and their files, every size', files_intact( $paths[ $T1 ] ) || files_intact( $paths[ $VT ] ) || is_file( $paths[ $G1 ][1] ), false );
check( 'kept: the photo a live product\'s gallery still shows', array( exists( $S ), files_intact( $paths[ $S ] ) ), array( true, true ) );
check( 'kept: the photo a category shows', array( exists( $C ), files_intact( $paths[ $C ] ) ), array( true, true ) );
check( 'kept: the record sharing a live photo\'s file', array( exists( $D ), is_file( $WORLD . '/uploads/2026/10/legacy.jpg' ) ), array( true, true ) );
check( '  and the live product still has its picture', array( exists( $D2 ), get_post_thumbnail_id( $L ) ), array( true, $D2 ) );
check( 'never touched: the shop\'s own upload', array( exists( $G3 ), files_intact( $paths[ $G3 ] ) ), array( true, true ) );
check( 'never touched: the eBay stand-in', exists( $PH ), true );
check( 'nothing left marked', TCGiant_Sync_Photo_Cleanup::waiting(), 0 );
check( 'the kept ones can be reused by the image batch again', array( given_up( $S ), given_up( $C ), given_up( $D ) ), array( false, false, false ) );
$stats = TCGiant_Sync_Photo_Cleanup::stats();
check( 'counted: 4 removed, 3 kept', array( $stats['removed'], $stats['kept'], $stats['failed'] ), array( 4, 3, 0 ) );
check( '  and the bytes freed are the bytes those four had on disk', $stats['bytes'], $expect_bytes );
check( 'the log says what it did, in words', logged( 'removed 4 photo(s) of deleted products' ), true );
check( '  and why it kept the shared file', logged( 'another Media Library entry points at the same file (legacy.jpg)' ), true );
check( 'nothing scheduled once there is nothing to do', wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ), false );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT WAS IN THE TRASH BEFORE 3.27.4 IS LEFT FOR THE SHOP TO SEE FIRST\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
boot();
$since = (int) get_option( TCGiant_Sync_Photo_Cleanup::SINCE_OPTION, 0 );
check( 'the first request of this version notes when it arrived', abs( $since - time() ) <= 2, true );
check( '  autoloaded, so reading it costs nothing', $wpdb->get_var( "SELECT autoload FROM wp_options WHERE option_name = 'tcgiant_sync_photo_cleanup_since'" ), 'yes' );
update_option( TCGiant_Sync_Photo_Cleanup::SINCE_OPTION, $since - 50 );
boot();
check( '  and a later request does not move it', (int) get_option( TCGiant_Sync_Photo_Cleanup::SINCE_OPTION ), $since - 50 );
installed_days_ago( 5 );

// Sold and trashed ten days before the update, with a variation.
$OLD  = product( 'publish' );
$o1   = photo( $OLD );
$o2   = photo( $OLD );
$own  = photo( $OLD, 'theirs' );
set_post_thumbnail( $OLD, $o1 );
update_post_meta( $OLD, '_product_image_gallery', "{$o2},{$own}" );
$OV   = variation( $OLD );
$ov   = photo( $OLD );
set_post_thumbnail( $OV, $ov );
wp_trash_post( $OLD );
q( "UPDATE wp_postmeta SET meta_value = '" . ( time() - 15 * DAY_IN_SECONDS ) . "' WHERE meta_key = '_wp_trash_meta_time'" );
// A variation added to it afterwards, never trashed itself.
$OV2  = variation( $OLD );
$ov2  = photo( $OLD );
set_post_thumbnail( $OV2, $ov2 );

// In the Trash with no record of when it got there.
$NONE = new_post( 'product', 'trash' );
$x1   = photo( $NONE );
set_post_thumbnail( $NONE, $x1 );

// Trashed since the update.
$NEW  = product( 'publish' );
$n1   = photo( $NEW );
set_post_thumbnail( $NEW, $n1 );
wp_trash_post( $NEW );

$st = TCGiant_Sync_Photo_Cleanup::status();
check( 'the screen counts the Trash, and what in it came before this version', array( $st['trashed'], $st['trashed_before'] ), array( 3, 2 ) );

$held_paths = array();
foreach ( array( $o1, $o2, $own, $ov, $ov2, $x1 ) as $id ) {
	$held_paths[ $id ] = paths_of( $id );
}

// The shop empties the Trash.
foreach ( array( $OLD, $NONE, $NEW ) as $p ) {
	wp_delete_post( $p, true );
}
do_action( 'shutdown' );

check( 'Trash emptied: all three products and the variations are gone', array_map( 'exists', array( $OLD, $OV, $OV2, $NONE, $NEW ) ), array_fill( 0, 5, false ) );
check( '  the one trashed before the update: nothing marked', array_map( 'marked', array( $o1, $o2, $ov ) ), array( false, false, false ) );
check( '  nor its variation added later, which goes with it', marked( $ov2 ), false );
check( '  nor the one with no record of when it was trashed', marked( $x1 ), false );
check( '  the one trashed since: marked', marked( $n1 ), true );
check( '  the log says what was held back, and where to find it', logged( '2 product(s) deleted for good had been in the Trash since before this version of the plugin' ), true );

time_passes();
run_queue();
check( 'after the quarantine: the photo of the one trashed since is removed', exists( $n1 ), false );
$intact = true;
foreach ( $held_paths as $id => $p ) {
	$intact = $intact && exists( $id ) && files_intact( $p );
}
check( '  every photo held back is still there, every file of it', $intact, true );

TCGiant_Sync_Photo_Cleanup::start_scan();
$scan = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
check( 'the check finds them: what was held back, less the shop\'s own upload', array( $scan['found'], $scan['bytes'] ), array( 5, bytes_of( $o1 ) + bytes_of( $o2 ) + bytes_of( $ov ) + bytes_of( $ov2 ) + bytes_of( $x1 ) ) );
TCGiant_Sync_Photo_Cleanup::approve( $scan['id'] );
TCGiant_Sync_Photo_Cleanup::queue_approved( 9999 );
time_passes();
run_queue();
check( 'and once the shop has seen them and pressed the button, they go', array_map( 'exists', array( $o1, $o2, $ov, $ov2, $x1 ) ), array_fill( 0, 5, false ) );
check( '  the shop\'s own upload stays', array( exists( $own ), files_intact( $held_paths[ $own ] ) ), array( true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHEN WOOCOMMERCE DOES NOT DELETE THE VARIATIONS - NOBODY SIGNED IN\n" . str_repeat( '=', 124 ) . "\n";

// WC_Post_Data::delete_post returns early unless the request may delete
// posts. WP-Cron's emptying of the Trash still reaches each variation, which
// WooCommerce trashed with its product; a product deleted straight from live
// by WP-CLI with no user leaves its variations behind.
fresh_db();
boot();
installed_days_ago( 60 );

$P   = product( 'publish' );
$p1  = photo( $P );
set_post_thumbnail( $P, $p1 );
$V   = variation( $P );
$vt  = photo( $P );
set_post_thumbnail( $V, $vt );
wp_trash_post( $P );
trashed_days_ago( 31 );

$DENIED = array( 'delete_posts' );
wp_scheduled_delete();
$DENIED = array();
time_passes();
run_queue();
check( 'WP-Cron, as nobody: WordPress deletes the trashed variation on its own record', exists( $V ), false );
check( '  and so its photo is removed with the product\'s', array( exists( $p1 ), exists( $vt ) ), array( false, false ) );

$Q   = product( 'publish' );
$q1  = photo( $Q );
set_post_thumbnail( $Q, $q1 );
$QV  = variation( $Q );
$qv  = photo( $Q );
set_post_thumbnail( $QV, $qv );
$qv_paths = paths_of( $qv );

$DENIED = array( 'delete_posts' );
wp_delete_post( $Q, true ); // wp post delete --force, no --user
$DENIED = array();
time_passes();
run_queue();
check( 'deleted straight from live with nobody signed in: the variation is left behind', array( exists( $Q ), exists( $QV ) ), array( false, true ) );
check( '  the product\'s own photo goes', exists( $q1 ), false );
check( '  the variation still shows its photo, so it stays, files and all', array( exists( $qv ), files_intact( $qv_paths ), get_post_thumbnail_id( $QV ) ), array( true, true, $qv ) );
TCGiant_Sync_Photo_Cleanup::start_scan();
check( '  and the check does not offer it', TCGiant_Sync_Photo_Cleanup::scan_step( 9999 )['found'], 0 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHATEVER IS IN THE TRASH KEEPS ITS PHOTOS\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
boot();

$TR  = product( 'trash' );
$X   = photo( $TR );                    // attached to a trashed product
set_post_thumbnail( $TR, $X );
$Y   = photo( 0 );                      // unattached, in a trashed product's gallery
update_post_meta( $TR, '_product_image_gallery', (string) $Y );
$TV  = variation( $TR, 'trash' );
$Z   = photo( 0 );                      // unattached, a trashed variation's photo
set_post_thumbnail( $TV, $Z );
$DR  = product( 'draft' );
$W   = photo( 0 );
set_post_thumbnail( $DR, $W );
$PR  = product( 'private' );
$K   = photo( 0 );
update_post_meta( $PR, '_product_image_gallery', '9999,' . $K );
$O   = photo( 0 );                      // truly orphaned
$OT  = photo( 0, 'theirs' );            // the shop's own, unattached and unused

$v = TCGiant_Sync_Photo_Cleanup::verdicts( array( $X, $Y, $Z, $W, $K, $O, $OT ) );
check( 'attached to a product in the Trash', $v[ $X ], 'attached' );
check( 'in a trashed product\'s gallery', $v[ $Y ], 'in_use' );
check( 'a trashed variation\'s photo', $v[ $Z ], 'in_use' );
check( 'a draft\'s photo', $v[ $W ], 'in_use' );
check( 'in a private product\'s gallery', $v[ $K ], 'in_use' );
check( 'unattached and unused: may go', $v[ $O ], '' );
check( 'the shop\'s own, unattached and unused: never', $v[ $OT ], 'not_ours' );

TCGiant_Sync_Photo_Cleanup::start_scan();
$scan = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
check( 'the check counts only the orphan', array( $scan['status'], $scan['found'] ), array( 'found', 1 ) );
check( '  and says how many products are in the Trash', TCGiant_Sync_Photo_Cleanup::status()['trashed'], 1 );

// A relist adopts the trashed product's photos (mapper.php migrate_images_from_orphan),
// and the old product is later deleted for good.
$NEW = product( 'publish' );
q( "UPDATE wp_posts SET post_parent = {$NEW} WHERE ID = {$X}" );
set_post_thumbnail( $NEW, $X );
update_post_meta( $NEW, '_product_image_gallery', (string) $Y );
delete_post_meta( $TR, '_product_image_gallery' );
delete_post_meta( $TR, '_thumbnail_id' );
wp_delete_post( $TR, true );
time_passes();
run_queue();
check( 'relist adopted the photos, then the old product was deleted: they stay', array( exists( $X ), exists( $Y ), get_post_thumbnail_id( $NEW ) ), array( true, true, $X ) );
check( '  the trashed variation\'s photo went with it', exists( $Z ), false );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PHOTO SHOWN ANYWHERE ELSE IS KEPT: POSTS, PAGES, THE SITE ITSELF\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
boot();

$A = array();
for ( $i = 0; $i < 12; $i++ ) {
	$A[] = photo( 0 );
}
new_post( 'post', 'publish', 0, '<p>Before.</p><img class="alignnone size-medium wp-image-' . $A[0] . '" src="x.jpg" />' );
new_post( 'page', 'draft', 0, '<!-- wp:image {"id":' . $A[1] . ',"sizeSlug":"large"} --><figure class="wp-block-image"><img src="y.jpg"/></figure><!-- /wp:image -->' );
new_post( 'post', 'publish', 0, '[gallery ids="' . $A[2] . ',' . $A[3] . '"]' );
new_post( 'wp_block', 'publish', 0, '<!-- wp:gallery {"ids":[' . $A[4] . ', 77]} /-->' );
new_post( 'wp_template_part', 'publish', 0, '<!-- wp:media-text {"mediaId":' . $A[5] . ',"mediaType":"image"} -->' );
update_option( 'site_logo', (string) $A[6] );
update_option( 'site_icon', (string) $A[7] );
update_option( 'woocommerce_placeholder_image', (string) $A[8] );
update_option( 'theme_mods_harness', array( 'custom_logo' => $A[9] ) );
new_post( 'revision', 'inherit', 0, 'wp-image-' . $A[10] );       // an old revision only
new_post( 'wp_global_styles', 'publish', 0, '{"styles":{"background":{"backgroundImage":{"url":"x.jpg","id":' . $A[11] . ',"source":"file"}}}}' );
$A[] = photo( 0 );
new_post( 'post', 'publish', 0, 'Part number 100' . $A[12] . ' in stock' ); // a number, not a photo
new_post( 'shop_order', 'wc-completed', 0, 'wp-image-' . $A[12] );  // an order is no page of the shop's

$v = TCGiant_Sync_Photo_Cleanup::verdicts( $A );
check( 'placed in a post (the wp-image class)', $v[ $A[0] ], 'in_use' );
check( 'an image block\'s "id" in a draft page', $v[ $A[1] ], 'in_use' );
check( 'a [gallery ids="..."] shortcode, both photos', array( $v[ $A[2] ], $v[ $A[3] ] ), array( 'in_use', 'in_use' ) );
check( 'a gallery block\'s "ids" list in a reusable block', $v[ $A[4] ], 'in_use' );
check( 'a media-and-text block\'s "mediaId" in a template part', $v[ $A[5] ], 'in_use' );
check( 'the site logo, and the site icon', array( $v[ $A[6] ], $v[ $A[7] ] ), array( 'in_use', 'in_use' ) );
check( 'WooCommerce\'s picture for a product without one', $v[ $A[8] ], 'in_use' );
check( 'the theme\'s logo', $v[ $A[9] ], 'in_use' );
check( 'named only by an old revision: may go', $v[ $A[10] ], '' );
check( 'the site\'s background picture, in its global styles', $v[ $A[11] ], 'in_use' );
check( 'a number in a post that is no photo\'s, or one an order names: may go', $v[ $A[12] ], '' );

// And the same through a deletion: a product's photo a blog post shows.
$P  = product( 'publish' );
$BP = photo( $P );
$BQ = photo( $P );
set_post_thumbnail( $P, $BP );
update_post_meta( $P, '_product_image_gallery', (string) $BQ );
new_post( 'post', 'publish', 0, 'Our best part: <img class="wp-image-' . $BP . '" />' );
wp_delete_post( $P, true );
time_passes();
run_queue();
check( 'a deleted product\'s photo a blog post shows is kept; its other photo goes', array( exists( $BP ), exists( $BQ ) ), array( true, false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PHOTO IN A PRODUCT'S OR A VARIATION'S DESCRIPTION IS KEPT\n" . str_repeat( '=', 124 ) . "\n";

// A kit's description showing a part's photo, picked from the Media Library
// while the part was still for sale - and so still attached to it, which is
// why the editor did not attach it to the kit. The part sells.
fresh_db();
boot();
$P = product( 'publish' );
$A = photo( $P );
$B = photo( $P );
$C = photo( $P );
set_post_thumbnail( $P, $A );
update_post_meta( $P, '_product_image_gallery', "{$B},{$C}" );
$KIT = new_post( 'product', 'publish', 0, '<p>This kit includes:</p><img class="alignnone size-medium wp-image-' . $A . '" src="x-300x300.jpg" />' );
$KV  = variation( $KIT );
update_post_meta( $KV, '_variation_description', '<p>In blue:</p><img class="wp-image-' . $B . '" src="y.jpg" />' );
// Another plugin's record of an email it sent, kept as a post of a kind that is not public.
new_post( 'postman_sent_mail', 'private', 0, '<img class="wp-image-' . $C . '" />' );
// A kit's short description - the post's excerpt - showing a part's photo:
// by the editor's class and its address, and by its address alone.
$SD  = photo( $P );
$SDA = photo( $P );
update_post_meta( $P, '_product_image_gallery', "{$B},{$C},{$SD},{$SDA}" );
$sd_paths = paths_of( $SD );
$up       = 'https://shop.example/wp-content/uploads/';
q( 'UPDATE wp_posts SET post_excerpt = ' . esc( '<p>Includes:</p><img class="alignnone size-medium wp-image-' . $SD . '" src="' . $up . get_post_meta( $SD, '_wp_attached_file', true ) . '" />' ) . " WHERE ID = {$KIT}" );
q( 'UPDATE wp_posts SET post_excerpt = ' . esc( '<img src="' . $up . preg_replace( '/\.jpg$/', '-300x300.jpg', get_post_meta( $SDA, '_wp_attached_file', true ) ) . '" />' ) . ' WHERE ID = ' . new_post( 'product', 'draft' ) );
wp_delete_post( $P, true );
time_passes();
run_queue();
check( 'placed in a live product\'s description: kept', exists( $A ), true );
check( 'placed in a live variation\'s description (WooCommerce keeps it in post meta): kept', exists( $B ), true );
check( 'named only in another plugin\'s records, a kind of post that is not public: may go', exists( $C ), false );
check( 'placed in a live product\'s short description (its excerpt): kept, every file of it', array( exists( $SD ), files_intact( $sd_paths ) ), array( true, true ) );
check( '  and one shown there by its address alone, in a draft: kept', exists( $SDA ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PHOTO IN A THEME'S OR PAGE BUILDER'S OWN LAYOUTS, OR IN ADDITIONAL CSS, IS KEPT\n" . str_repeat( '=', 124 ) . "\n";

// Kinds of post that are not public but are shown on every page: a theme's
// header, footer or hook Elements (GeneratePress's have a screen of their own
// in the admin; another kind here only opens in the block editor), Divi's
// Theme Builder layouts (edited in an app of its own, registered with
// neither), and the Customizer's Additional CSS. Not orders, which have a
// screen but show nothing on the site.
fresh_db();
boot();
$P   = product( 'publish' );
$lay = array();
foreach ( array( 'GP', 'REST', 'DIVI', 'CSS', 'ORDER', 'COUPON' ) as $k ) {
	$lay[ $k ] = photo( $P );
}
update_post_meta( $P, '_product_image_gallery', implode( ',', $lay ) );
$up = 'https://shop.example/wp-content/uploads/';
new_post( 'gp_elements', 'publish', 0, '<!-- wp:image {"id":' . $lay['GP'] . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="' . $up . get_post_meta( $lay['GP'], '_wp_attached_file', true ) . '" class="wp-image-' . $lay['GP'] . '"/></figure><!-- /wp:image -->' );
new_post( 'harness_layout', 'publish', 0, '<!-- wp:cover {"url":"x.jpg","id":' . $lay['REST'] . '} /-->' );
new_post( 'et_header_layout', 'publish', 0, '[et_pb_section][et_pb_image src="' . $up . get_post_meta( $lay['DIVI'], '_wp_attached_file', true ) . '"][/et_pb_section]' );
new_post( 'custom_css', 'publish', 0, '.hero { background-image: url(' . $up . preg_replace( '/\.jpg$/', '-768x768.jpg', get_post_meta( $lay['CSS'], '_wp_attached_file', true ) ) . '); }' );
new_post( 'shop_order', 'wc-completed', 0, '<img class="wp-image-' . $lay['ORDER'] . '" />' );
new_post( 'shop_coupon', 'publish', 0, '<img class="wp-image-' . $lay['COUPON'] . '" />' );
wp_delete_post( $P, true );
time_passes();
run_queue();
check( 'in a theme\'s Element - not public, with a screen in the admin: kept', exists( $lay['GP'] ), true );
check( 'in a kind of post the block editor opens, not public, with no screen: kept', exists( $lay['REST'] ), true );
check( 'in a layout of Divi\'s Theme Builder, by its address: kept', exists( $lay['DIVI'] ), true );
check( 'in the Customizer\'s Additional CSS, a resized copy by its address: kept', exists( $lay['CSS'] ), true );
check( 'in a coupon, which has a screen in the admin: kept', exists( $lay['COUPON'] ), true );
check( 'named only by an order, which has a screen but shows nothing on the site: may go', exists( $lay['ORDER'] ), false );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nPLACED BY ITS ADDRESS, OR BY A PAGE BUILDER'S OWN WAY OF NAMING IT\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
boot();
$P     = product( 'publish' );
$named = array();
foreach ( array( 'U', 'W', 'G', 'I', 'IM', 'M', 'ESC', 'BB', 'OPT', 'TR', 'RV', 'SELF', 'NONE' ) as $k ) {
	$named[ $k ] = photo( $P );
}
$named['BIG'] = photo( $P, 'ours', 'bigpart-scaled.jpg' ); // a large upload WordPress scaled down
update_post_meta( $P, '_product_image_gallery', implode( ',', $named ) );
$base = 'https://shop.example/wp-content/uploads/';
$file = function ( $id, $suffix = '' ) {
	return preg_replace( '/\.jpg$/', $suffix . '.jpg', (string) get_post_meta( $id, '_wp_attached_file', true ) );
};
// By address alone - pasted HTML, a slider, Divi - and only in a resized copy.
new_post( 'page', 'publish', 0, '<div class="hero"><img src="' . $base . $file( $named['U'], '-300x300' ) . '" srcset="' . $base . $file( $named['U'], '-768x768' ) . ' 768w" alt="banner" /></div>' );
// Page builders' and galleries' shortcodes.
new_post( 'page', 'publish', 0, '[vc_row][vc_column][vc_single_image image="' . $named['W'] . '" img_size="large"][/vc_column][/vc_row]' );
new_post( 'post', 'publish', 0, '[gallery include="77,' . $named['G'] . '" columns="3"]' );
new_post( 'page', 'draft', 0, '[product_badge image_id="' . $named['I'] . '"][slider images="88, ' . $named['IM'] . '"]' );
// Elementor's data, as it keeps it: JSON in post meta, its slashes escaped.
update_post_meta( new_post( 'page', 'publish', 0, '' ), '_elementor_data', '[{"elType":"widget","settings":{"image":{"url":"' . str_replace( '/', '\\/', $base . $file( $named['M'] ) ) . '","id":' . $named['M'] . '}}}]' );
// A builder keeping only the address, as JSON.
update_post_meta( new_post( 'page', 'publish', 0, '' ), '_harness_layout', '{"section":{"background":"' . str_replace( '/', '\\/', $base . $file( $named['ESC'], '-768x768' ) ) . '"}}' );
// Another builder's, serialized, by address alone.
update_post_meta( new_post( 'page', 'publish', 0, '' ), '_fl_builder_data', array( 'node1' => array( 'photo_src' => $base . $file( $named['BB'], '-1024x1024' ) ) ) );
// A theme's setting.
update_option( 'harness_theme_options', array( 'hero_image' => $base . $file( $named['OPT'] ) ) );
// The original of a large upload, which WordPress keeps on record as -scaled.
new_post( 'page', 'publish', 0, '<img src="' . $base . '2026/10/bigpart.jpg" />' );
// Not uses: a transient (a copy of something kept elsewhere), an old
// revision's builder data, and a photo's own record.
update_option( '_transient_harness_cache', '<img src="' . $base . $file( $named['TR'] ) . '">' );
update_post_meta( new_post( 'revision', 'inherit', 0, '' ), '_elementor_data', '[{"url":"' . $base . $file( $named['RV'] ) . '"}]' );
update_post_meta( $named['SELF'], '_harness_note', $base . $file( $named['SELF'] ) );
// A photo whose name only begins like another's.
new_post( 'page', 'publish', 0, '<img src="' . $base . $file( $named['NONE'], 'x-150x150' ) . '" />' );
wp_delete_post( $P, true );
time_passes();
run_queue();
check( 'placed in a page by its address only, a resized copy: kept', exists( $named['U'] ), true );
check( 'a WPBakery [vc_single_image image="N"]: kept', exists( $named['W'] ), true );
check( 'a [gallery include="..."]: kept', exists( $named['G'] ), true );
check( 'image_id="N", and images="..." in a draft: kept', array( exists( $named['I'] ), exists( $named['IM'] ) ), array( true, true ) );
check( 'an Elementor page (_elementor_data, slashes escaped): kept', exists( $named['M'] ), true );
check( 'a builder\'s JSON with only the address, slashes escaped: kept', exists( $named['ESC'] ), true );
check( 'another builder\'s serialized data, by address alone: kept', exists( $named['BB'] ), true );
check( 'a theme\'s setting pointing at its file: kept', exists( $named['OPT'] ), true );
check( 'a page showing the original of a photo kept on record as -scaled: kept', exists( $named['BIG'] ), true );
check( 'named only by a transient, an old revision\'s data, or its own record: may go', array( exists( $named['TR'] ), exists( $named['RV'] ), exists( $named['SELF'] ) ), array( false, false, false ) );
check( 'a file whose name only begins like the photo\'s: may go', exists( $named['NONE'] ), false );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PHOTO PLACED WITH FLATSOME'S UX BUILDER, WHICH NAMES IT BY ITS NUMBER ALONE, IS KEPT\n" . str_repeat( '=', 124 ) . "\n";

// Flatsome, the best-selling WooCommerce theme, writes a page built with its
// UX Builder as shortcodes of its own, and names each photo in them by its
// attachment ID alone: the Image element as [ux_image id="N"], a section's or
// a banner's background as bg="N", an image box's picture as img="N" - and
// its content for the top of a category's page the same way, in term meta.
// None of it carries an address, and none of it was read: a sold part's
// photo on the home page went, with every file, while the screen said a
// photo placed in a page is kept.
fresh_db();
boot();
$P  = product( 'publish' );
$ux = array();
foreach ( array( 'IMAGE', 'SECTION', 'BANNER', 'BOX', 'TOP', 'FOOT', 'FORM', 'HTML', 'NONE' ) as $k ) {
	$ux[ $k ] = photo( $P );
}
update_post_meta( $P, '_product_image_gallery', implode( ',', $ux ) );
$ux_paths = paths_of( $ux['IMAGE'] );
// Each on a page of its own, so that each is found for what it is.
new_post( 'page', 'publish', 0, '[row][col span="6" span__sm="12"][ux_image id="' . $ux['IMAGE'] . '" image_size="original" lightbox="true"][/col][/row]' );
new_post( 'page', 'publish', 0, '[section bg="' . $ux['SECTION'] . '" bg_size="original" bg_overlay="rgba(0,0,0,.4)" padding="60px"][row][col][/col][/row][/section]' );
new_post( 'page', 'draft', 0, '[ux_slider][ux_banner height="500px" bg="' . $ux['BANNER'] . '" bg_pos="51% 37%"][text_box][/text_box][/ux_banner][/ux_slider]' );
new_post( 'page', 'publish', 0, '[featured_box img="' . $ux['BOX'] . '" img_width="60" pos="left"]Free shipping on every order[/featured_box]' );
add_term_meta( term( 'product_cat' ), 'cat_meta', array( 'cat_header' => '[ux_image id="' . $ux['TOP'] . '" width="100"]' ) );
add_term_meta( term( 'product_cat' ), 'cat_meta', array( 'cat_footer' => '[section bg="' . $ux['FOOT'] . '" bg_size="medium"][/section]' ) );
// The same numbers outside Flatsome's own shortcodes, on a page that is read:
// another plugin's shortcode, and HTML.
new_post( 'page', 'publish', 0, '[ux_text]Write to us[/ux_text][contact-form-7 id="' . $ux['FORM'] . '" title="Contact"]<div id="' . $ux['HTML'] . '" class="row"></div>' );
wp_delete_post( $P, true );
time_passes();
run_queue();
check( 'the Image element, [ux_image id="N"]: kept, with every file', array( exists( $ux['IMAGE'] ), files_intact( $ux_paths ) ), array( true, true ) );
check( 'a section\'s background, [section bg="N"]: kept', exists( $ux['SECTION'] ), true );
check( 'a banner\'s, [ux_banner bg="N"], on a draft: kept', exists( $ux['BANNER'] ), true );
check( 'an image box\'s picture, [featured_box img="N"]: kept', exists( $ux['BOX'] ), true );
check( 'in Flatsome\'s content for the top and the foot of a category\'s page, in term meta: kept', array( exists( $ux['TOP'] ), exists( $ux['FOOT'] ) ), array( true, true ) );
check( 'id="N" in another plugin\'s shortcode, or in HTML, names no photo: may go', array( exists( $ux['FORM'] ), exists( $ux['HTML'] ) ), array( false, false ) );
check( 'none of those: may go', exists( $ux['NONE'] ), false );

// What the shop is told. The readme and the changelog said such a photo was
// not seen, and the screen said a photo placed in a page was kept; now that
// it is seen, nothing may say it is not.
$said = array(
	'the screen'   => (string) file_get_contents( $ROOT . '/admin/views/image-cleanup.php' ),
	'readme.txt'   => (string) file_get_contents( $ROOT . '/readme.txt' ),
	'changelog.txt' => (string) file_get_contents( $ROOT . '/changelog.txt' ),
);
$unseen = array();
foreach ( $said as $where => $text ) {
	if ( preg_match_all( '/Not seen[^\n]*/', $text, $lines ) ) {
		foreach ( $lines[0] as $line ) {
			if ( false !== stripos( $line, 'UX Builder' ) ) {
				$unseen[] = $where;
			}
		}
	}
}
check( 'no "Not seen" line, on the screen, in the readme or the changelog, names the UX Builder of Flatsome', $unseen, array() );

// Nor may they say only that the shop should check before pressing the
// button. The removal that follows a deletion is on for every shop, judges a
// photo as the button does, and takes one used only where nothing looks,
// with nobody checking anything.
$also = array();
foreach ( $said as $where => $text ) {
	preg_match( '/Not seen[^\n]*/', $text, $line );
	$also[ $where ] = false !== strpos( $line[0] ?? '', '15 minutes after its product is deleted for good' );
}
check( '  each says such a photo is also removed about 15 minutes after its product is deleted for good', $also, array( 'the screen' => true, 'readme.txt' => true, 'changelog.txt' => true ) );
preg_match( '/Not seen[^\n]*/', $said['changelog.txt'], $line );
check( '  and the changelog names the filters that keep such photos, and that turn that removal off', array( false !== strpos( $line[0], 'tcgiant_sync_photos_in_use' ), false !== strpos( $line[0], 'tcgiant_sync_remove_photos_of_deleted_products' ) ), array( true, true ) );
$filter = new ReflectionMethod( 'TCGiant_Sync_Photo_Cleanup', 'on_before_delete_post' );
check( '  which is the filter the removal asks', false !== strpos( implode( '', array_slice( file( $filter->getFileName() ), $filter->getStartLine() - 1, $filter->getEndLine() - $filter->getStartLine() + 1 ) ), "apply_filters( 'tcgiant_sync_remove_photos_of_deleted_products'" ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PHOTO IN A CATEGORY'S, TAG'S OR BRAND'S DESCRIPTION, OR IN ITS SETTINGS BY ITS ADDRESS, IS KEPT\n" . str_repeat( '=', 124 ) . "\n";

// WooCommerce shows a category's description at the top of the category's
// page, and an editor for descriptions places a photo there as the post
// editor does. A theme keeps its own content for the top of a category's page
// in term meta, and an SEO plugin its picture for sharing the page - by
// address. A sold part's photo, still shown on a live category page, used to
// go with every resized copy, while the same address in post meta was kept.
fresh_db();
boot();
$tm = array();
foreach ( array( 'CATID', 'CATADDR', 'TAG', 'BRAND', 'TOP', 'SEO', 'BYID', 'SWATCH', 'NONE' ) as $k ) {
	$tm[ $k ] = photo( 0 );
}
$up   = 'https://shop.example/wp-content/uploads/';
$file = function ( $id, $suffix = '' ) {
	return preg_replace( '/\.jpg$/', $suffix . '.jpg', (string) get_post_meta( $id, '_wp_attached_file', true ) );
};
// The editor's picture, served from a CDN's address: named by its ID alone.
term( 'product_cat', '<p>Our newest cards.</p><img class="alignnone size-medium wp-image-' . $tm['CATID'] . '" src="https://cdn.example/k-300x300.jpg" width="300" height="300" />' );
// By its address alone, a resized copy.
term( 'product_cat', '<img src="' . $up . $file( $tm['CATADDR'], '-300x300' ) . '" alt="" />' );
term( 'product_tag', '<img class="wp-image-' . $tm['TAG'] . '" src="' . $up . $file( $tm['TAG'] ) . '" />' );
term( 'product_brand', '[gallery ids="77,' . $tm['BRAND'] . '"]' );
$cat = term( 'product_cat' );
add_term_meta( $cat, 'cat_top_content', '<div class="banner"><img src="' . $up . $file( $tm['TOP'], '-768x768' ) . '" /></div>' );
add_term_meta( $cat, 'rank_math_facebook_image', $up . $file( $tm['SEO'] ) );
// A builder's data for a category: this photo by its ID, beside another's address.
add_term_meta( $cat, '_harness_term_layout', '{"background":{"id":' . $tm['BYID'] . '},"logo":{"url":"' . str_replace( '/', '\\/', $up . '2026/10/brand-logo.png' ) . '"}}' );
// A swatch plugin's picture, by its ID alone: not seen, as the screen says.
add_term_meta( term( 'pa_colour' ), 'pa_colour_swatch_image', (string) $tm['SWATCH'] );
$v = TCGiant_Sync_Photo_Cleanup::verdicts( $tm );
check( 'a category\'s description: the editor\'s picture by its ID, and one by its address alone', array( $v[ $tm['CATID'] ], $v[ $tm['CATADDR'] ] ), array( 'in_use', 'in_use' ) );
check( 'a tag\'s description, and a brand\'s gallery shortcode', array( $v[ $tm['TAG'] ], $v[ $tm['BRAND'] ] ), array( 'in_use', 'in_use' ) );
check( 'a theme\'s content for a category\'s page, and an SEO plugin\'s picture for it, in term meta by address', array( $v[ $tm['TOP'] ], $v[ $tm['SEO'] ] ), array( 'in_use', 'in_use' ) );
check( '  and a photo named by its ID in term meta that holds an address', $v[ $tm['BYID'] ], 'in_use' );
check( 'term meta with only a number in it, a swatch plugin\'s: not seen, as the screen says - may go', $v[ $tm['SWATCH'] ], '' );
check( 'none of those: may go', $v[ $tm['NONE'] ], '' );

// And through a deletion: the sold part's photo a live category page shows,
// in its description and in its theme content, is kept with every file.
foreach ( array( 'description' => true, 'term meta' => false ) as $where => $described ) {
	fresh_db();
	boot();
	$P  = product( 'publish' );
	$K  = photo( $P );
	$Q  = photo( $P );
	$kp = paths_of( $K );
	set_post_thumbnail( $P, $K );
	update_post_meta( $P, '_product_image_gallery', (string) $Q );
	$address = 'https://shop.example/wp-content/uploads/' . get_post_meta( $K, '_wp_attached_file', true );
	if ( $described ) {
		term( 'product_cat', '<img class="alignnone size-medium wp-image-' . $K . '" src="' . str_replace( '.jpg', '-300x300.jpg', $address ) . '" />' );
	} else {
		add_term_meta( term( 'product_cat' ), 'cat_top_content', '<img src="' . $address . '">' );
	}
	wp_delete_post( $P, true );
	time_passes();
	run_queue();
	check( "the deleted product's photo a live category shows in its {$where} is kept, every file of it; its other photo goes", array( exists( $K ), files_intact( $kp ), marked( $K ), exists( $Q ) ), array( true, true, false, false ) );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWIDGETS, AND THE THEME'S HEADER AND BACKGROUND\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
boot();
$wid = array();
foreach ( array( 'IMG', 'BLOCK', 'GAL', 'TEXT', 'HTML', 'HEAD', 'BACK', 'FREE' ) as $k ) {
	$wid[ $k ] = photo( 0 );
}
update_option( 'widget_media_image', array( 2 => array( 'attachment_id' => $wid['IMG'], 'url' => 'https://shop.example/x.jpg' ), '_multiwidget' => 1 ) );
update_option( 'widget_block', array( 3 => array( 'content' => '<!-- wp:image {"id":' . $wid['BLOCK'] . '} --><figure class="wp-block-image"><img src="y.jpg" class="wp-image-' . $wid['BLOCK'] . '"/></figure><!-- /wp:image -->' ), '_multiwidget' => 1 ) );
update_option( 'widget_media_gallery', array( 4 => array( 'ids' => array( 77, $wid['GAL'] ) ), '_multiwidget' => 1 ) );
update_option( 'widget_text', array( 5 => array( 'title' => 'Hello', 'text' => '<img class="wp-image-' . $wid['TEXT'] . '" />' ), '_multiwidget' => 1 ) );
update_option( 'widget_custom_html', array( 6 => array( 'content' => '<img class="wp-image-' . $wid['HTML'] . '" />' ), '_multiwidget' => 1 ) );
update_option( 'theme_mods_harness', array(
	'header_image_data' => (object) array( 'attachment_id' => $wid['HEAD'], 'url' => 'https://shop.example/h.jpg' ),
	'background_image'  => 'https://shop.example/wp-content/uploads/' . get_post_meta( $wid['BACK'], '_wp_attached_file', true ),
) );
$v = TCGiant_Sync_Photo_Cleanup::verdicts( $wid );
check( 'the sidebar\'s Image widget', $v[ $wid['IMG'] ], 'in_use' );
check( 'an image block in a block widget', $v[ $wid['BLOCK'] ], 'in_use' );
check( 'a Gallery widget', $v[ $wid['GAL'] ], 'in_use' );
check( 'a Text widget, and a Custom HTML one', array( $v[ $wid['TEXT'] ], $v[ $wid['HTML'] ] ), array( 'in_use', 'in_use' ) );
check( 'the theme\'s header image, and its background', array( $v[ $wid['HEAD'] ], $v[ $wid['BACK'] ] ), array( 'in_use', 'in_use' ) );
check( 'none of those: may go', $v[ $wid['FREE'] ], '' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA GALLERY KEPT AS A SERIALIZED ARRAY\n" . str_repeat( '=', 124 ) . "\n";

// Variation Images Gallery for WooCommerce keeps a variation's gallery as an
// array, which WordPress stores serialized. Split on commas, it named nothing.
fresh_db();
boot();
$P  = product( 'publish' );
$V1 = photo( $P );
update_post_meta( $P, '_product_image_gallery', (string) $V1 );
update_post_meta( variation( product( 'publish' ) ), 'woo_variation_gallery_images', array( $V1 ) );
wp_delete_post( $P, true );
time_passes();
run_queue();
check( 'in a live variation\'s gallery kept as an array: kept, with no filter needed', exists( $V1 ), true );

add_filter( 'tcgiant_sync_photo_list_keys', function ( $keys ) {
	$keys[] = '_harness_gallery';
	return $keys;
} );
$X = photo( 0 );
update_post_meta( product( 'publish' ), '_harness_gallery', array( 'main' => array( 5, (string) $X ) ) );
check( 'one under a key added through tcgiant_sync_photo_list_keys, nested: kept', TCGiant_Sync_Photo_Cleanup::verdicts( array( $X ) )[ $X ], 'in_use' );
remove_all( 'tcgiant_sync_photo_list_keys' );

// And the photos of such a gallery go with the variation it belonged to.
$Q  = product( 'publish' );
$QV = variation( $Q );
$q1 = photo( 0 ); // shown only in the variation's gallery
update_post_meta( $QV, 'woo_variation_gallery_images', array( $q1 ) );
wp_delete_post( $Q, true );
check( 'a variation deleted for good: the photos of its gallery kept as an array are marked', marked( $q1 ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PHOTO TAKEN UP AGAIN DURING THE QUARANTINE IS KEPT\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
boot();

$P  = product( 'publish' );
$A  = photo( $P );
$B  = photo( $P );
set_post_thumbnail( $P, $A );
update_post_meta( $P, '_product_image_gallery', (string) $B );
$url_a = get_post_meta( $A, '_tcgiant_source_url', true );
$url_b = get_post_meta( $B, '_tcgiant_source_url', true );

check( 'before: the image batch would reuse the download', reused_for( 555, $url_b ), $B );

// The image batch, working on another product showing the same eBay photo,
// found A a moment before the product was deleted, and writes it a minute later.
$picked = reused_for( 556, $url_a );
wp_delete_post( $P, true );

check( 'marked: the image batch no longer reuses it', reused_for( 557, $url_b ), false );
$N = product( 'publish' );
set_post_thumbnail( $N, $picked );          // ... and writes what it found

time_passes();
run_queue();
check( 'the photo the batch took up before it was marked is kept', array( exists( $A ), get_post_thumbnail_id( $N ), marked( $A ) ), array( true, $A, false ) );
check( 'the other went', exists( $B ), false );
check( 'the image batch can reuse the kept one again', reused_for( 558, $url_a ), $A );

// No other product's, though: a photo waiting is still its own product's,
// and still the photo of a product that shows it - that product's showing it
// is what keeps it. Before 3.27.4 was put right, such a product fetched
// again inside the quarantine downloaded every photo it showed a second time.
fresh_db();
boot();
$X    = product( 'publish' );
$XA   = photo( $X );                       // X's own download...
$xurl = get_post_meta( $XA, '_tcgiant_source_url', true );
$D    = product( 'publish', $XA );         // ...which D shows too, reused
wp_delete_post( $D, true );                // D sells and is deleted: XA waits
check( 'waiting because another product that showed it was deleted: its own product still reuses it', array( marked( $XA ), reused_for( $X, $xurl ) ), array( true, $XA ) );
check( '  and no other product does', reused_for( 561, $xurl ), false );

$P    = product( 'publish' );
$PA   = photo( $P );
$PB   = photo( $P );
$aurl = get_post_meta( $PA, '_tcgiant_source_url', true );
$burl = get_post_meta( $PB, '_tcgiant_source_url', true );
$R    = product( 'publish', $PA );         // a relist showing P's photos
$RV   = variation( $R );
set_post_thumbnail( $RV, $PB );
wp_delete_post( $P, true );                // P goes: both wait, attached to nothing
check( 'one a product shows as its main photo, or as a variation\'s: that product reuses it', array( reused_for( $R, $aurl ), reused_for( $R, $burl ) ), array( $PA, $PB ) );
check( '  another product does not', array( reused_for( 562, $aurl ), reused_for( 562, $burl ) ), array( false, false ) );
add_post_meta( $PA, TCGiant_Sync_Photo_Cleanup::FAILED_KEY, (string) time(), true );
check( 'one given up on is never reused, even by a product that shows it', reused_for( $R, $aurl ), false );

$loc = file_get_contents( $LOCALIZER );
check( 'the image batch names the same mark the cleanup uses', false !== strpos( $loc, "'" . TCGiant_Sync_Photo_Cleanup::MARK_KEY . "'" ), true );
check( '  and the mark of a photo given up on', false !== strpos( $loc, "'" . TCGiant_Sync_Photo_Cleanup::FAILED_KEY . "'" ), true );
check( 'the quarantine is no shorter than the image batch\'s lease', TCGiant_Sync_Photo_Cleanup::QUARANTINE_SECONDS >= TCGiant_Sync_Image_Localizer::LEASE_SECONDS, true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nRE-CHECKED AT THE LAST MOMENT\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
boot();

$P  = product( 'publish' );
$A  = photo( $P );
$B  = photo( $P );
$Cx = photo( $P );
update_post_meta( $P, '_product_image_gallery', "{$A},{$B},{$Cx}" );
$url_b = get_post_meta( $B, '_tcgiant_source_url', true );
wp_delete_post( $P, true );
// After marking: the shop takes one over as its own, and another plugin vetoes one.
delete_post_meta( $A, '_tcgiant_source_url' );
add_filter( 'pre_delete_attachment', function ( $check, $post ) use ( $B ) {
	return (int) $post->ID === $B ? false : $check;
}, 10, 2 );
time_passes();
run_queue();
remove_all( 'pre_delete_attachment' );
check( 'no longer marked as downloaded by the time it is due: kept', array( exists( $A ), marked( $A ) ), array( true, false ) );
check( 'another plugin refuses the deletion: kept, and not asked again', array( exists( $B ), marked( $B ) ), array( true, false ) );
check( '  but given up on for good: never handed to another product', array( given_up( $B ), reused_for( 600, $url_b ) ), array( true, false ) );
check( '  and the log says so', logged( 'WordPress did not delete photo #' . $B ), true );
check( 'the third went', exists( $Cx ), false );
check( 'counted apart from the photos kept', array( TCGiant_Sync_Photo_Cleanup::stats()['failed'], TCGiant_Sync_Photo_Cleanup::stats()['kept'] ), array( 1, 1 ) );
TCGiant_Sync_Photo_Cleanup::start_scan();
$scan = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
check( 'the check counts it apart and does not offer it again', array( $scan['found'], $scan['failed'] ), array( 0, 1 ) );
check( '  and says what to do about it', logged( '1 could not be removed before and have been left for you to delete by hand' ), true );
wp_delete_post( product( 'publish', $B ), true );
check( 'a product deleted later that showed it does not put it back in the queue', marked( $B ), false );

// Within one round: changes seconds after the round judged them, while the
// photo before is being deleted.
fresh_db();
boot();
$P  = product( 'publish' );
$E1 = photo( $P );
$E2 = photo( $P );
$E3 = photo( $P );
$E4 = photo( $P );
update_post_meta( $P, '_product_image_gallery', "{$E1},{$E2},{$E3},{$E4}" );
wp_delete_post( $P, true );
$LIVE = product( 'publish' );
add_action( 'delete_attachment', function ( $id ) use ( $E1, $E2, $E3, $LIVE ) {
	if ( (int) $id === $E1 ) {
		delete_post_meta( $E2, '_tcgiant_source_url' );                // the shop takes it over
		set_post_thumbnail( $LIVE, $E3 );                              // someone sets it as a featured image
		q( "INSERT INTO wp_termmeta ( term_id, meta_key, meta_value ) VALUES ( 9, 'thumbnail_id', " . esc( $E3 + 1 ) . ' )' ); // and a category picture
	}
} );
time_passes();
run_queue();
remove_all( 'delete_attachment' );
check( 'changed after the round judged it, just before its turn: re-read and kept', array( exists( $E1 ), exists( $E2 ), marked( $E2 ) ), array( false, true, false ) );
check( 'made a featured image after the round was judged: kept, still the picture', array( exists( $E3 ), get_post_thumbnail_id( $LIVE ), marked( $E3 ), given_up( $E3 ) ), array( true, $E3, false, false ) );
check( 'made a category\'s picture after the round was judged: kept', array( exists( $E4 ), marked( $E4 ) ), array( true, false ) );


// Galleries are lists, read once for the run rather than for each photo; the
// run reads them again before a photo whose turn comes more than a few
// seconds after that. An offload plugin takes twenty seconds over E1, and
// meanwhile the shop puts E2 in a live product's gallery.
fresh_db();
boot();
$P  = product( 'publish' );
$E1 = photo( $P );
$E2 = photo( $P );
update_post_meta( $P, '_product_image_gallery', "{$E1},{$E2}" );
wp_delete_post( $P, true );
$LIVE = product( 'publish' );
add_action( 'delete_attachment', function ( $id ) use ( $E1, $E2, $LIVE ) {
	if ( (int) $id === $E1 ) {
		$GLOBALS['CLOCK_OFFSET'] += 20;
		update_post_meta( $LIVE, '_product_image_gallery', '5,' . $E2 );
	}
} );
time_passes();
run_queue();
remove_all( 'delete_attachment' );
$CLOCK_OFFSET = 0.0;
check( 'put in a live gallery while the photo before it was slowly being deleted: seen in time, kept', array( exists( $E1 ), exists( $E2 ), marked( $E2 ) ), array( false, true, false ) );
check( '  and the gallery still shows it', exists( (int) explode( ',', get_post_meta( $LIVE, '_product_image_gallery', true ) )[1] ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA RUN THE HOST KILLS PART WAY THROUGH\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
boot();

$P   = product( 'publish' );
$ids = array();
for ( $i = 0; $i < 5; $i++ ) {
	$ids[] = photo( $P );
}
update_post_meta( $P, '_product_image_gallery', implode( ',', $ids ) );
$killer = $ids[1];
$kpaths = paths_of( $killer );
$kurl   = get_post_meta( $killer, '_tcgiant_source_url', true );
wp_delete_post( $P, true );
time_passes();

$code = in_child( 'queue:' . $killer );
check( 'killed while WordPress was deleting the second photo (a real fatal)', 0 !== $code, true );
check( '  the first had gone already', exists( $ids[0] ), false );
check( '  the second is untouched: its record, its files, still queued', array( exists( $killer ), files_intact( $kpaths ), marked( $killer ) ), array( true, true, true ) );
check( '  but held for a lease, not first in line again', (int) get_post_meta( $killer, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true ) > time() + 600, true );
check( '  the rest wait their turn', array_map( 'marked', array_slice( $ids, 2 ) ), array( true, true, true ) );
check( '  the death is on record, naming the photo', false !== strpos( (string) ( get_option( 'tcgiant_sync_last_crash_photo_cleanup', array() )['message'] ?? '' ), 'while working on photo #' . $killer ), true );
check( '  its hold on the queue was let go', get_option( 'tcgiant_sync_photo_cleanup_lock_queue', 'none' ), 'none' );
check( '  and a run is scheduled to carry on', (bool) wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ), true );

// The fallback, five minutes on: it does not start on another photo straight away.
$code = in_child( 'queue:0' );
check( 'the run after a death in a deletion pauses the whole removal instead', array( $code, exists( $ids[2] ), exists( $ids[3] ), exists( $ids[4] ) ), array( 0, true, true, true ) );
check( '  for half an hour', pause()['seconds'], 1800 );
check( '  says so once, in words, naming the photo', array( times_logged( 'Photo cleanup: paused for 30 minutes' ), logged( 'deleting photo #' . $killer . ' - another plugin' ) ), array( 1, true ) );
check( '  and comes back when the pause is over', wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) >= time() + 1800, true );
$code = in_child( 'queue:0' );
check( '  a run during the pause does nothing either, and does not pause again', array( exists( $ids[2] ), times_logged( 'paused for' ) ), array( true, 1 ) );

end_pause();
$code = in_child( 'queue:0' );
check( 'after the pause: the run carries on behind it', array( $code, exists( $ids[2] ), exists( $ids[3] ), exists( $ids[4] ) ), array( 0, false, false, false ) );
check( '  leaving the one it died on for its lease', exists( $killer ), true );

time_passes();
$code = in_child( 'queue:' . $killer );          // dies on it again
time_passes();
$code = in_child( 'queue:' . $killer );          // the run after: paused, twice as long
check( 'it dies again straight after the pause: paused twice as long', pause()['seconds'], 3600 );
end_pause();
$code = in_child( 'queue:' . $killer );          // dies on it a third time
time_passes();
$code = in_child( 'queue:' . $killer );          // paused
end_pause();
$code = in_child( 'queue:' . $killer );          // gives up
check( 'killed on the same photo every time: left in place after three', array( $code, exists( $killer ), marked( $killer ), files_intact( $kpaths ) ), array( 0, true, false, true ) );
check( '  and the log says why, and what to do', logged( 'left in place - its deletion was begun and did not finish, 3 times' ), true );
check( '  given up on for good: the image batch will not hand it to a new product', array( given_up( $killer ), reused_for( 700, $kurl ) ), array( true, false ) );
check( '  and no other mark of the queue is left on it', array( attempts_of( $killer ), started( $killer ), get_post_meta( $killer, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true ) ), array( 0, false, '' ) );

// The second attempt survives, on a photo killed once.
fresh_db();
boot();
$P  = product( 'publish' );
$Q1 = photo( $P );
set_post_thumbnail( $P, $Q1 );
wp_delete_post( $P, true );
time_passes();
in_child( 'queue:' . $Q1 );
time_passes();
in_child( 'queue:0' );                            // paused
end_pause();
in_child( 'queue:0' );
check( 'killed once, deleted on the next try after its lease and the pause', exists( $Q1 ), false );

// A death long ago, before the last run that finished, pauses nothing.
fresh_db();
boot();
$P  = product( 'publish' );
$R1 = photo( $P );
$R2 = photo( $P );
update_post_meta( $P, '_product_image_gallery', "{$R1},{$R2}" );
wp_delete_post( $P, true );
time_passes();
in_child( 'queue:' . $R1 );
time_passes();
in_child( 'queue:0' );                            // paused
end_pause();
in_child( 'queue:0' );                            // finishes: R2 goes
time_passes();
in_child( 'queue:0' );
check( 'a death the removal has already paused for, and run on from, pauses nothing more', array( exists( $R1 ), times_logged( 'paused for' ) ), array( false, 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA RUN THE HOST ENDS FROM OUTSIDE, WITH NO SHUTDOWN FUNCTION RUN\n" . str_repeat( '=', 124 ) . "\n";

// PHP-FPM's request_terminate_timeout, LiteSpeed, the out-of-memory killer, a
// container's limit: the process simply ends. No shutdown function runs, so
// no record of the death is written but what the run wrote itself first -
// the photo it had asked WordPress to delete. Without that, every run would
// start on a fresh photo and be killed again, taking its cron request with
// it, every five minutes, for good.
function killed_on() {
	$f = $GLOBALS['WORLD'] . '/killed-on.txt';
	return is_file( $f ) ? array_map( 'intval', file( $f, FILE_IGNORE_NEW_LINES ) ) : array();
}
function expire_queue_hold() {
	// The hold of a run killed this way is never let go; it lapses.
	q( "UPDATE wp_options SET option_value = '1|lapsed' WHERE option_name = 'tcgiant_sync_photo_cleanup_lock_queue'" );
}

fresh_db();
boot();
@unlink( $WORLD . '/killed-on.txt' );
$P   = product( 'publish' );
$ids = array();
for ( $i = 0; $i < 5; $i++ ) {
	$ids[] = photo( $P );
}
update_post_meta( $P, '_product_image_gallery', implode( ',', $ids ) );
$kpaths = paths_of( $ids[0] );
wp_delete_post( $P, true );
time_passes();

$code = in_child( 'hardkill' );
check( 'killed from outside while WordPress was deleting the first photo', array( 0 !== $code, killed_on() ), array( true, array( $ids[0] ) ) );
check( '  no shutdown function ran: no record of the death but the run\'s own', array( get_option( 'tcgiant_sync_last_crash_photo_cleanup', 'none' ), (int) ( get_option( TCGiant_Sync_Photo_Cleanup::DELETING_OPTION, array() )['photo'] ?? 0 ) ), array( 'none', $ids[0] ) );
check( '  the photo is untouched, still queued, under its lease', array( exists( $ids[0] ), files_intact( $kpaths ), (int) get_post_meta( $ids[0], TCGiant_Sync_Photo_Cleanup::MARK_KEY, true ) > time() + 600 ), array( true, true, true ) );

$codes = array();
for ( $run = 0; $run < 3; $run++ ) {
	expire_queue_hold();
	$codes[] = in_child( 'hardkill' ); // every deletion it would start is killed too
}
check( 'the runs after it start on no other photo: the removal pauses instead', array( $codes, killed_on(), array_map( 'exists', array_slice( $ids, 1 ) ) ), array( array( 0, 0, 0 ), array( $ids[0] ), array( true, true, true, true ) ) );
check( '  for half an hour', pause()['seconds'], 1800 );
check( '  said once, in the same words as for a fatal, naming the photo', array( times_logged( 'Photo cleanup: paused for 30 minutes' ), logged( 'deleting photo #' . $ids[0] . ' - another plugin' ) ), array( 1, true ) );

end_pause();
$code = in_child( 'queue:0' );
check( 'after the pause the run carries on behind it, the killed one under its lease', array( $code, exists( $ids[0] ), array_map( 'exists', array_slice( $ids, 1 ) ) ), array( 0, true, array( false, false, false, false ) ) );
check( '  and a run that finishes leaves no deletion on record', get_option( TCGiant_Sync_Photo_Cleanup::DELETING_OPTION, 'none' ), 'none' );
time_passes();
in_child( 'queue:0' );
check( '  the killed one goes on its next try', exists( $ids[0] ), false );

// A run still hung in another request, its hold lapsed: the next run must not
// start beside it, deleting another photo while that one has not finished.
fresh_db();
boot();
$P  = product( 'publish' );
$H1 = photo( $P );
$H2 = photo( $P );
update_post_meta( $P, '_product_image_gallery', "{$H1},{$H2}" );
wp_delete_post( $P, true );
time_passes();
update_option( TCGiant_Sync_Photo_Cleanup::DELETING_OPTION, array( 'photo' => $H1, 'at' => time() - 300 ) );
run_queue();
check( 'a run still hung in another request: the next pauses rather than start beside it', array( exists( $H1 ), exists( $H2 ), pause()['seconds'] ), array( true, true, 1800 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA RUN THE HOST ENDS WHILE IT READS WHERE PHOTOS ARE USED\n" . str_repeat( '=', 124 ) . "\n";

// Before a run deletes anything it reads where photos are used on the site -
// every product's description, builders' data, the settings. On a big shop
// that is the longest part of a run, and the likeliest place for a host to
// end the request. A run that died there used to be started again five
// minutes later, the same way, for good - taking its WP-Cron request, and
// the eBay sync in it, down with it each time.
foreach ( array( 'fatal' => 'a real fatal', 'kill' => 'ended from outside, no shutdown function' ) as $how => $what ) {
	fresh_db();
	boot();
	$P   = product( 'publish' );
	$ids = array( photo( $P ), photo( $P ), photo( $P ) );
	update_post_meta( $P, '_product_image_gallery', implode( ',', $ids ) );
	wp_delete_post( $P, true );
	time_passes();
	$marks = array_map( function ( $id ) { return get_post_meta( $id, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true ); }, $ids );

	$code = in_child( 'reading:' . $how );
	check( "killed while reading where photos are used ({$what})", array( 0 !== $code, array_map( 'exists', $ids ), array_map( 'attempts_of', $ids ) ), array( true, array( true, true, true ), array( 0, 0, 0 ) ) );
	check( '  every photo keeps its place', array_map( function ( $id ) { return get_post_meta( $id, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true ); }, $ids ), $marks );
	check( '  its note of the reading is left behind', (int) ( get_option( TCGiant_Sync_Photo_Cleanup::READING_OPTION, array() )['at'] ?? 0 ) > 0, true );
	if ( 'fatal' === $how ) {
		check( '  the record of the death says what it was doing', false !== strpos( (string) ( get_option( 'tcgiant_sync_last_crash_photo_cleanup', array() )['message'] ?? '' ), 'while working on the reading of where photos are used on the site' ), true );
	} else {
		check( '  no record of the death but its own note', get_option( 'tcgiant_sync_last_crash_photo_cleanup', 'none' ), 'none' );
		expire_queue_hold();
	}

	$code = in_child( 'queue:0' );                   // the fallback, five minutes on
	check( '  the run after it does not read again: the removal pauses instead', array( $code, array_map( 'exists', $ids ), pause()['seconds'], pause()['why'] ), array( 0, array( true, true, true ), 1800, 'reading' ) );
	check( '  says so once, in words', times_logged( 'Photo cleanup: paused for 30 minutes. The last run was stopped while it was reading where photos are used on the site' ), 1 );
	check( '  comes back when the pause is over', wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) >= time() + 1800, true );
	check( '  and the screen says why', TCGiant_Sync_Photo_Cleanup::status()['paused_why'], 'reading' );
	in_child( 'queue:0' );
	check( '  a run during the pause does nothing, and does not pause again', array( array_map( 'exists', $ids ), times_logged( 'paused for' ) ), array( array( true, true, true ), 1 ) );
	end_pause();
	in_child( 'queue:0' );
	check( '  after the pause, the removal carries on', array( array_map( 'exists', $ids ), get_option( TCGiant_Sync_Photo_Cleanup::READING_OPTION, 'none' ) ), array( array( false, false, false ), 'none' ) );
}

// Known from the record PHP leaves alone, when the note could not be written.
fresh_db();
boot();
$P = product( 'publish' );
$R = photo( $P );
set_post_thumbnail( $P, $R );
wp_delete_post( $P, true );
time_passes();
in_child( 'reading:fatal' );
delete_option( TCGiant_Sync_Photo_Cleanup::READING_OPTION );
in_child( 'queue:0' );
check( 'a fatal in the reading, known from PHP\'s record of it alone: paused all the same', array( pause()['seconds'], pause()['why'], exists( $R ) ), array( 1800, 'reading', true ) );

// It dies there again straight after the pause: twice as long.
fresh_db();
boot();
$P = product( 'publish' );
$R = photo( $P );
set_post_thumbnail( $P, $R );
wp_delete_post( $P, true );
time_passes();
in_child( 'reading:fatal' );
in_child( 'queue:0' );                               // paused
end_pause();
time_passes( 60 );
in_child( 'reading:fatal' );                         // dies again
in_child( 'queue:0' );
check( 'it dies in the reading again straight after the pause: paused twice as long', array( pause()['seconds'], exists( $R ) ), array( 3600, true ) );

// A step of the check that dies there is not asked for again, the same way.
foreach ( array( 'fatal', 'kill' ) as $how ) {
	fresh_db();
	boot();
	$O = photo( 0 );
	TCGiant_Sync_Photo_Cleanup::start_scan();
	$code = in_child( 'reading:' . $how . ':check' );
	check( "a step of the check killed while reading ({$how})", array( 0 !== $code, TCGiant_Sync_Photo_Cleanup::scan()['status'], TCGiant_Sync_Photo_Cleanup::scan()['found'] ), array( true, 'scanning', 0 ) );
	if ( 'kill' === $how ) {
		q( "UPDATE wp_options SET option_value = '1|lapsed' WHERE option_name = 'tcgiant_sync_photo_cleanup_lock_scan'" );
	}
	$reply = ajax( 'ajax_scan', array() );          // the screen asks again
	check( '  the step the screen asks for next does not read again: it says why', array( $reply['success'], false !== strpos( (string) ( $reply['data']['message'] ?? '' ), 'was stopped while it was reading where photos are used on this site' ) ), array( false, true ) );
	check( '  and counts nothing, holding nothing', array( TCGiant_Sync_Photo_Cleanup::scan()['found'], get_option( 'tcgiant_sync_photo_cleanup_lock_scan', 'none' ) ), array( 0, 'none' ) );
	run_queue();
	check( '  the removal is not paused for it', pause()['until'], 0 );
	$done = ajax( 'ajax_scan', array() );            // the button, pressed again
	check( '  pressed again, the check carries on', array( $done['success'], $done['data']['scan']['status'], $done['data']['scan']['found'] ), array( true, 'found', 1 ) );
}

// One the shop's button left behind, or one of a check since replaced, stops
// no step of the check.
fresh_db();
boot();
photo( 0 );
$old = TCGiant_Sync_Photo_Cleanup::start_scan();
update_option( TCGiant_Sync_Photo_Cleanup::CHECK_READING_OPTION, array( 'at' => time() - 60, 'scan' => '' ) );
$a = ajax( 'ajax_scan', array() );
TCGiant_Sync_Photo_Cleanup::start_scan();
update_option( TCGiant_Sync_Photo_Cleanup::CHECK_READING_OPTION, array( 'at' => time() - 60, 'scan' => $old['id'] ) );
$b = ajax( 'ajax_scan', array() );
check( 'a note left by the button, or by a check since replaced, stops no step of the check', array( $a['success'], $a['data']['scan']['found'], $b['success'], $b['data']['scan']['found'] ), array( true, 1, true, 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA READING OF THE SITE THAT TAKES AS LONG AS A RUN MAY IS GIVEN UP\n" . str_repeat( '=', 124 ) . "\n";

// Nothing judged on half of it can stand, and a run that went on reading
// past its budget would only have the host end it - and with it the rest of
// its WP-Cron request. Each page of the reading takes 61 seconds here.
fresh_db();
boot();
$P = product( 'publish' );
$A = photo( $P );
$B = photo( $P );
update_post_meta( $P, '_product_image_gallery', "{$A},{$B}" );
$pa = paths_of( $A );
wp_delete_post( $P, true );
time_passes();
$ma = get_post_meta( $A, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true );
add_filter( 'tcgiant_sync_photo_content_types', function ( $types ) { $GLOBALS['CLOCK_OFFSET'] += 61; return $types; } );
run_queue();
$CLOCK_OFFSET = 0.0;
check( 'a reading that took longer than the run\'s budget: nothing removed, nothing charged, every place kept', array( untouched( $A, $pa, $ma ), exists( $B ), marked( $B ) ), array( array( true, true, $ma, 0, false ), true, true ) );
check( '  the removal pauses, and says why, once', array( pause()['seconds'], pause()['why'], times_logged( 'Photo cleanup: paused for 30 minutes. Reading where photos are used on the site' ), logged( 'took longer than a run may (61 s, of a 60 s budget)' ) ), array( 1800, 'slow', 1, true ) );
check( '  and not that anything was removed', logged( 'Photo cleanup: removed' ), false );
check( '  its hold and its note are let go; it comes back after the pause', array( get_option( 'tcgiant_sync_photo_cleanup_lock_queue', 'none' ), get_option( TCGiant_Sync_Photo_Cleanup::READING_OPTION, 'none' ), wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) >= time() + 1800 ), array( 'none', 'none', true ) );
check( '  the screen says why', TCGiant_Sync_Photo_Cleanup::status()['paused_why'], 'slow' );
$wpdb->log = array();
run_queue();
check( '  a run during the pause reads nothing', count( array_filter( $wpdb->log, function ( $q ) { return false !== strpos( $q, 'LIKE' ); } ) ), 0 );
$wpdb->log = null;
end_pause();
run_queue();
$CLOCK_OFFSET = 0.0;
check( '  slow again straight after the pause: paused twice as long', array( pause()['seconds'], exists( $A ) ), array( 3600, true ) );
remove_all( 'tcgiant_sync_photo_content_types' );
end_pause();
run_queue();
check( '  once it reads in time, the removal carries on', array( exists( $A ), exists( $B ) ), array( false, false ) );

// The check: the step says so, and counts nothing.
fresh_db();
boot();
photo( 0 );
TCGiant_Sync_Photo_Cleanup::start_scan();
add_filter( 'tcgiant_sync_photo_content_types', function ( $types ) { $GLOBALS['CLOCK_OFFSET'] += 61; return $types; } );
$reply = ajax( 'ajax_scan', array() );
remove_all( 'tcgiant_sync_photo_content_types' );
$CLOCK_OFFSET = 0.0;
check( 'a step of the check whose reading takes too long says so, and counts nothing', array( $reply['success'], false !== strpos( (string) ( $reply['data']['message'] ?? '' ), 'took longer than one step of the check may (61 s' ), TCGiant_Sync_Photo_Cleanup::scan()['status'], TCGiant_Sync_Photo_Cleanup::scan()['found'] ), array( false, true, 'scanning', 0 ) );
check( '  leaving no note of a reading behind', get_option( TCGiant_Sync_Photo_Cleanup::CHECK_READING_OPTION, 'none' ), 'none' );

// The reading of the terms stops at the page, or the window, it runs out of
// time on, as every other part of the reading does: categories walked a page
// at a time with no description naming a photo, and term meta a window at a
// time with no address in it - so that only the clock can stop it there.
foreach ( array( 'the terms' => 'SELECT term_taxonomy_id FROM wp_term_taxonomy', 'term meta' => 'AS bytes FROM wp_termmeta' ) as $what => $slow ) {
	fresh_db();
	boot();
	$P = product( 'publish' );
	$A = photo( $P );
	set_post_thumbnail( $P, $A );
	$pa = paths_of( $A );
	$wpdb->db->exec( 'BEGIN' );
	for ( $i = 1; $i <= TCGiant_Sync_Photo_Cleanup::KEY_PAGE + 10; $i++ ) {
		term( 'product_tag', 'Trading cards' );
	}
	for ( $i = 1; $i <= TCGiant_Sync_Photo_Cleanup::WINDOW + 10; $i++ ) {
		q( "INSERT INTO wp_termmeta ( term_id, meta_key, meta_value ) VALUES ( 1, 'order', '0' )" );
	}
	$wpdb->db->exec( 'COMMIT' );
	wp_delete_post( $P, true );
	time_passes();
	$ma     = get_post_meta( $A, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true );
	$jumped = false;
	$wpdb->log      = array();
	$wpdb->on_query = function ( $sql ) use ( $slow, &$jumped ) {
		if ( ! $jumped && false !== strpos( $sql, $slow ) ) {
			$jumped = true;
			$GLOBALS['CLOCK_OFFSET'] += 61; // This page of it takes a minute.
		}
	};
	run_queue();
	$wpdb->on_query = null;
	$CLOCK_OFFSET   = 0.0;
	check( "the reading of {$what} runs out of time on its first page: nothing removed or charged, and the removal pauses", array( untouched( $A, $pa, $ma ), pause()['why'] ), array( array( true, true, $ma, 0, false ), 'slow' ) );
	check( '  read no further than that page', array( queries_with( $slow ), 'the terms' === $what ? queries_with( 'FROM wp_termmeta WHERE meta_id >' ) : 0 ), array( 1, 0 ) );
	$wpdb->log = null;
}

// During any pause the shop's approved photos are not got ready either:
// that reads the site too.
fresh_db();
boot();
$O = photo( 0 );
TCGiant_Sync_Photo_Cleanup::start_scan();
$s = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
TCGiant_Sync_Photo_Cleanup::approve( $s['id'] );
update_option( TCGiant_Sync_Photo_Cleanup::PAUSE_OPTION, array( 'until' => time() + 1800, 'seconds' => 1800, 'crash_at' => time() - 60, 'why' => 'deleting' ) );
run_queue();
check( 'paused, with a check approved: nothing is got ready, and it waits for the pause', array( TCGiant_Sync_Photo_Cleanup::waiting(), TCGiant_Sync_Photo_Cleanup::scan()['status'], wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) >= time() + 1800 ), array( 0, 'approved', true ) );
end_pause();
run_queue();
check( '  after it, they are got ready', array( TCGiant_Sync_Photo_Cleanup::waiting(), TCGiant_Sync_Photo_Cleanup::scan()['status'] ), array( 1, 'queued' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA READING OF THE SITE FETCHES A FEW MEGABYTES AT A TIME, AND STOPS BEFORE MEMORY RUNS OUT\n" . str_repeat( '=', 124 ) . "\n";

// A page builder keeps a page's data in one row of post meta, a megabyte or
// more of it. Fetched fifty rows at a time, sixty such pages raised a run's
// memory by fifty megabytes, on top of WordPress and WooCommerce in a cron
// request. Values are fetched by what they come to, a few megabytes at a
// time, each group let go of before the next.

/** The reading's fetches of values in a query log, by table: the rows and the bytes of each. */
function value_fetches( array $log ) {
	$sum = array(
		'wp_posts'         => 'LENGTH( post_content ) + LENGTH( post_excerpt )',
		'wp_postmeta'      => 'LENGTH( meta_value )',
		'wp_options'       => 'LENGTH( option_value )',
		'wp_term_taxonomy' => 'LENGTH( description )',
		'wp_termmeta'      => 'LENGTH( meta_value )',
	);
	$out = array();
	foreach ( $log as $one ) {
		if ( preg_match( '/^SELECT [\w, ]+ FROM (wp_\w+) WHERE (\w+) IN \( ([\d,]+) \)$/', $one, $f ) && isset( $sum[ $f[1] ] ) ) {
			$out[ $f[1] ][] = array(
				'rows'  => substr_count( $f[3], ',' ) + 1,
				'bytes' => (int) $GLOBALS['wpdb']->get_var( "SELECT SUM( {$sum[ $f[1] ]} ) FROM {$f[1]} WHERE {$f[2]} IN ( {$f[3]} )" ),
			);
		}
	}
	return $out;
}
function uploads_address( $id ) { return 'https://shop.example/wp-content/uploads/' . get_post_meta( $id, '_wp_attached_file', true ); }
/** A page whose page builder's data comes to $mb megabytes, naming a file by its address. */
function builder_page( $mb, $address ) {
	update_post_meta( new_post( 'page', 'publish' ), '_elementor_data', '{"url":"' . $address . '","pad":"' . str_repeat( 'x', (int) ( $mb * 1048576 ) ) . '"}' );
}

fresh_db();
boot();
$BIG = array();
foreach ( array( 'BUILDER', 'ALONE', 'CONTENT', 'VARIATION', 'OPTION', 'CATEGORY', 'TERMMETA', 'NONE' ) as $k ) {
	$BIG[ $k ] = photo( 0 );
}
$elsewhere = 'https://shop.example/wp-content/uploads/2026/10/elsewhere.jpg';
$wpdb->db->exec( 'BEGIN' );
for ( $i = 1; $i <= 24; $i++ ) {
	builder_page( 1, 20 === $i ? uploads_address( $BIG['BUILDER'] ) : $elsewhere );
}
builder_page( 6, uploads_address( $BIG['ALONE'] ) ); // bigger than a whole group
for ( $i = 1; $i <= 8; $i++ ) {
	$pad = str_repeat( 'x', 1048576 );
	$pid = new_post( 'product', 'publish', 0, '<p>' . $pad . '</p><img src="' . ( 7 === $i ? uploads_address( $BIG['CONTENT'] ) : $elsewhere ) . '">' );
	if ( 3 === $i ) {
		// A short description that is not short: with it, more than a group.
		q( 'UPDATE wp_posts SET post_excerpt = ' . esc( str_repeat( $pad, 3 ) ) . ' WHERE ID = ' . $pid );
	}
	update_post_meta( new_post( 'product_variation', 'publish' ), '_variation_description', '<p>' . $pad . '</p><img class="wp-image-' . ( 7 === $i ? $BIG['VARIATION'] : 9999995 ) . '">' );
	update_option( 'harness_big_slider_' . $i, array( 'pad' => $pad, 'slide' => 7 === $i ? uploads_address( $BIG['OPTION'] ) : $elsewhere ) );
	$cat = term( 'product_cat', '<p>' . $pad . '</p><img class="wp-image-' . ( 7 === $i ? $BIG['CATEGORY'] : 9999997 ) . '">' );
	add_term_meta( $cat, 'cat_top_content', '<p>' . $pad . '</p><img src="' . ( 7 === $i ? uploads_address( $BIG['TERMMETA'] ) : $elsewhere ) . '">' );
}
unset( $pad );
$wpdb->db->exec( 'COMMIT' );
$wpdb->log     = array();
$wpdb->flushes = 0;
$measured      = function_exists( 'memory_reset_peak_usage' );
if ( $measured ) {
	memory_reset_peak_usage();
}
$floor   = memory_get_usage();
$v       = TCGiant_Sync_Photo_Cleanup::verdicts( array_values( $BIG ) );
$rose    = memory_get_peak_usage() - $floor;
$log     = $wpdb->log;
$flushes = $wpdb->flushes;
$wpdb->log = null;
$fetches = value_fetches( $log );
$most    = array();
$alone   = array();
$rows    = array();
$groups  = 0;
foreach ( $fetches as $table => $list ) {
	foreach ( $list as $g ) {
		$groups++;
		$rows[ $table ] = ( $rows[ $table ] ?? 0 ) + $g['rows'];
		if ( $g['rows'] > 1 ) {
			$most[ $table ] = max( $most[ $table ] ?? 0, $g['bytes'] );
		} elseif ( $g['bytes'] > TCGiant_Sync_Photo_Cleanup::READ_BYTES ) {
			$alone[] = $table;
		}
	}
}
ksort( $most );
printf( "  (%d fetches; the most fetched at once %.1f MB; memory rose by %s while reading)\n", $groups, ( $most ? max( $most ) : 0 ) / 1048576, $measured ? sprintf( '%.1f MB', $rose / 1048576 ) : 'an unmeasured amount' );
check( 'content, descriptions, builder data, settings and terms: never more than ' . ( TCGiant_Sync_Photo_Cleanup::READ_BYTES / 1048576 ) . ' MB fetched at once', array( array_keys( $most ), ( $most ? max( $most ) : PHP_INT_MAX ) <= TCGiant_Sync_Photo_Cleanup::READ_BYTES ), array( array( 'wp_options', 'wp_postmeta', 'wp_posts', 'wp_term_taxonomy', 'wp_termmeta' ), true ) );
check( '  a value bigger than that is fetched on its own - a description with its excerpt, a builder\'s data', $alone, array( 'wp_posts', 'wp_postmeta' ) );
check( '  every big one fetched', array( ( $rows['wp_posts'] ?? 0 ) >= 8, ( $rows['wp_postmeta'] ?? 0 ) >= 33, ( $rows['wp_options'] ?? 0 ) >= 8, ( $rows['wp_term_taxonomy'] ?? 0 ) >= 8, ( $rows['wp_termmeta'] ?? 0 ) >= 8 ), array( true, true, true, true, true ) );
check( '  each group let go of before the next is fetched', $flushes >= $groups, true );
check( '  and what each names is seen, the one fetched on its own too', array( $v[ $BIG['BUILDER'] ], $v[ $BIG['ALONE'] ], $v[ $BIG['CONTENT'] ], $v[ $BIG['VARIATION'] ], $v[ $BIG['OPTION'] ], $v[ $BIG['CATEGORY'] ], $v[ $BIG['TERMMETA'] ], $v[ $BIG['NONE'] ] ), array( 'in_use', 'in_use', 'in_use', 'in_use', 'in_use', 'in_use', 'in_use', '' ) );
check( '  memory rises by a few megabytes, not by all that was read', $measured ? $rose < 3 * TCGiant_Sync_Photo_Cleanup::READ_BYTES : true, true );

// Not the memory left for the next group: a run stops there rather than run
// out of it, which would end its request, and the eBay sync in it, and have
// the next run read as far and die the same way.
$LIMIT = (string) ini_get( 'memory_limit' );
ini_set( 'memory_limit', '1024M' );
$short = function () { return 1014 * 1048576; }; // 10 MB left
$ample = function () { return 0; };
fresh_db();
boot();
$P = product( 'publish' );
$A = photo( $P );
$X = photo( $P );
update_post_meta( $P, '_product_image_gallery', "{$A},{$X}" );
$pa = paths_of( $A );
$px = paths_of( $X );
$wpdb->db->exec( 'BEGIN' );
for ( $i = 1; $i <= 6; $i++ ) {
	builder_page( 1, 5 === $i ? uploads_address( $A ) : $elsewhere );
}
$wpdb->db->exec( 'COMMIT' );
wp_delete_post( $P, true );
time_passes();
$ma = get_post_meta( $A, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true );
$mx = get_post_meta( $X, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true );
TCGiant_Sync_Runtime::$memory_probe = $short;
run_queue();
TCGiant_Sync_Runtime::$memory_probe = $ample;
check( 'not the memory left to fetch the next part of the reading: nothing removed, nothing charged, every place kept', array( untouched( $A, $pa, $ma ), untouched( $X, $px, $mx ) ), array( array( true, true, $ma, 0, false ), array( true, true, $mx, 0, false ) ) );
check( '  the removal pauses, and says why, once', array( pause()['seconds'], pause()['why'], times_logged( 'Photo cleanup: paused for 30 minutes. Reading where photos are used on the site' ), logged( 'needed more memory than this run had left (10 MB of a 1024 MB memory limit left, and the next part of it needs about ' ) ), array( 1800, 'memory', 1, true ) );
check( '  and not that anything was removed', logged( 'Photo cleanup: removed' ), false );
check( '  its hold and its note let go; back after the pause; the screen says why', array( get_option( 'tcgiant_sync_photo_cleanup_lock_queue', 'none' ), get_option( TCGiant_Sync_Photo_Cleanup::READING_OPTION, 'none' ), wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) >= time() + 1800, TCGiant_Sync_Photo_Cleanup::status()['paused_why'] ), array( 'none', 'none', true, 'memory' ) );
end_pause();
TCGiant_Sync_Runtime::$memory_probe = $short;
run_queue();
TCGiant_Sync_Runtime::$memory_probe = $ample;
check( '  short again straight after the pause: paused twice as long', array( pause()['seconds'], exists( $X ) ), array( 3600, true ) );
end_pause();
run_queue();
check( '  with the memory there, the removal carries on: the builder\'s photo kept, the other gone', array( exists( $A ), marked( $A ), exists( $X ) ), array( true, false, false ) );

// A step of the check, and the button, say so instead.
fresh_db();
boot();
$O = photo( 0 );
$wpdb->db->exec( 'BEGIN' );
for ( $i = 1; $i <= 6; $i++ ) {
	builder_page( 1, $elsewhere );
}
$wpdb->db->exec( 'COMMIT' );
TCGiant_Sync_Photo_Cleanup::start_scan();
TCGiant_Sync_Runtime::$memory_probe = $short;
$reply = ajax( 'ajax_scan', array() );
TCGiant_Sync_Runtime::$memory_probe = $ample;
check( 'a step of the check without the memory for its reading says so, and counts nothing', array( $reply['success'], false !== strpos( (string) ( $reply['data']['message'] ?? '' ), 'needed more memory than this step of the check had left (10 MB of a 1024 MB memory limit left' ), TCGiant_Sync_Photo_Cleanup::scan()['status'], TCGiant_Sync_Photo_Cleanup::scan()['found'] ), array( false, true, 'scanning', 0 ) );
check( '  leaving no note of a reading, and the removal not paused', array( get_option( TCGiant_Sync_Photo_Cleanup::CHECK_READING_OPTION, 'none' ), pause()['until'] ), array( 'none', 0 ) );
$s = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
TCGiant_Sync_Runtime::$memory_probe = $short;
$reply = ajax( 'ajax_remove', array( 'scan_id' => $s['id'] ) );
TCGiant_Sync_Runtime::$memory_probe = $ample;
check( 'the button without the memory to get its photos ready: told so, nothing marked, approved all the same', array( $s['found'], $reply['success'], false !== strpos( (string) ( $reply['data']['message'] ?? '' ), 'needed more memory than this request had left' ), TCGiant_Sync_Photo_Cleanup::waiting(), TCGiant_Sync_Photo_Cleanup::scan()['status'] ), array( 1, false, true, 0, 'approved' ) );
ini_set( 'memory_limit', $LIMIT );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nANOTHER PLUGIN THROWS WHILE A PHOTO IS BEING DELETED\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
boot();
$P   = product( 'publish' );
$ids = array();
for ( $i = 0; $i < 4; $i++ ) {
	$ids[] = photo( $P );
}
update_post_meta( $P, '_product_image_gallery', implode( ',', $ids ) );
$thrower = $ids[1];
$turl    = get_post_meta( $thrower, '_tcgiant_source_url', true );
wp_delete_post( $P, true );
add_action( 'delete_attachment', function ( $id ) use ( $thrower ) {
	if ( (int) $id === $thrower ) {
		throw new RuntimeException( 'Bunny storage API: 503 Service Unavailable' );
	}
} );
time_passes();
$escaped = null;
try {
	run_queue();
} catch ( \Throwable $e ) {
	$escaped = $e->getMessage();
}
check( 'nothing escapes into the rest of the cron request', $escaped, null );
check( 'the others are removed', array( exists( $ids[0] ), exists( $ids[2] ), exists( $ids[3] ) ), array( false, false, false ) );
check( 'the one it threw on is left where it is, under its lease', array( exists( $thrower ), marked( $thrower ), (int) get_post_meta( $thrower, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true ) > time() + 600 ), array( true, true, true ) );
check( '  charged an attempt', (int) get_post_meta( $thrower, TCGiant_Sync_Photo_Cleanup::ATTEMPTS_KEY, true ), 1 );
check( '  the log says what was thrown', logged( 'deleting photo #' . $thrower . ' ran into an error in WordPress or another plugin (Bunny storage API: 503 Service Unavailable' ), true );
check( '  counted as an error, and the run finishes and schedules the next', array( TCGiant_Sync_Photo_Cleanup::stats()['errors'], get_option( 'tcgiant_sync_photo_cleanup_lock_queue', 'none' ), (bool) wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) ), array( 1, 'none', true ) );
for ( $i = 0; $i < 3; $i++ ) {
	time_passes();
	run_queue();
}
remove_all( 'delete_attachment' );
check( 'thrown every time: given up on after three, and never reused', array( exists( $thrower ), marked( $thrower ), given_up( $thrower ), reused_for( 800, $turl ) ), array( true, false, true, false ) );

// Thrown before the deletion is charged - the last look at it, here - is
// charged all the same, so that it cannot be tried for ever either.
fresh_db();
boot();
$P  = product( 'publish' );
$H1 = photo( $P );
$H2 = photo( $P );
update_post_meta( $P, '_product_image_gallery', "{$H1},{$H2}" );
wp_delete_post( $P, true );
time_passes();
$wpdb->explode = array( "meta_key = '_thumbnail_id' AND meta_value = '{$H1}' LIMIT 1" );
run_queue();
$wpdb->explode = array();
check( 'thrown before its attempt was charged: charged, and under its lease', array( exists( $H1 ), (int) get_post_meta( $H1, TCGiant_Sync_Photo_Cleanup::ATTEMPTS_KEY, true ), (int) get_post_meta( $H1, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true ) > time() + 600 ), array( true, 1, true ) );
check( '  and the next one goes', exists( $H2 ), false );

// Its deletion started once, then something began showing it. It stays, and
// shows - but part of it may already be gone, so it is not put back for the
// image batch to hand to a new product.
fresh_db();
boot();
$P  = product( 'publish' );
$J1 = photo( $P );
set_post_thumbnail( $P, $J1 );
$jurl = get_post_meta( $J1, '_tcgiant_source_url', true );
wp_delete_post( $P, true );
add_action( 'delete_attachment', function ( $id ) use ( $J1 ) {
	if ( (int) $id === $J1 ) {
		throw new RuntimeException( 'Bunny storage API: 503 Service Unavailable' );
	}
} );
time_passes();
run_queue();
remove_all( 'delete_attachment' );
$SHOWS = product( 'publish', $J1 );
time_passes();
run_queue();
check( 'started once, then shown again: kept and still shown', array( exists( $J1 ), get_post_thumbnail_id( $SHOWS ), marked( $J1 ) ), array( true, $J1, false ) );
check( '  but given up on for good: never handed to another product', array( given_up( $J1 ), reused_for( 900, $jurl ) ), array( true, false ) );
check( '  and the log says what to check', logged( 'a deletion of photo #' . $J1 . ' was started before and did not finish - the request was stopped, or ran into an error - and now something shows it again' ), true );

// An error outside any one deletion still ends only this run.
fresh_db();
boot();
$P  = product( 'publish' );
$Y1 = photo( $P );
set_post_thumbnail( $P, $Y1 );
wp_delete_post( $P, true );
add_filter( 'tcgiant_sync_photos_in_use', function () { throw new LogicException( 'a filter that broke' ); } );
time_passes();
$escaped = null;
try {
	run_queue();
} catch ( \Throwable $e ) {
	$escaped = $e->getMessage();
}
remove_all( 'tcgiant_sync_photos_in_use' );
check( 'an error anywhere in a run: caught, logged, the hold let go, a run scheduled', array( $escaped, logged( 'Photo cleanup stopped on an error (a filter that broke' ), get_option( 'tcgiant_sync_photo_cleanup_lock_queue', 'none' ), (bool) wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ), exists( $Y1 ) ), array( null, true, 'none', true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAN ERROR BEFORE ANY DELETION BEGAN LEAVES THE PHOTO WHOLE, AND REUSABLE\n" . str_repeat( '=', 124 ) . "\n";

function attempts_of( $id ) { return (int) get_post_meta( $id, TCGiant_Sync_Photo_Cleanup::ATTEMPTS_KEY, true ); }
function started( $id ) { return '' !== get_post_meta( $id, TCGiant_Sync_Photo_Cleanup::STARTED_KEY, true ); }

// The database driver throws ("MySQL server has gone away") at the last look,
// before WordPress is asked to delete anything. The attempt is counted, so
// that one this happens to every time is let go of - but nothing of the
// photo was touched, and if it is used again it is an ordinary photo.
fresh_db();
boot();
$P     = product( 'publish' );
$A     = photo( $P );
$apath = paths_of( $A );
$aurl  = get_post_meta( $A, '_tcgiant_source_url', true );
set_post_thumbnail( $P, $A );
wp_delete_post( $P, true );
time_passes();
$wpdb->explode = array( "WHERE meta_key = '_thumbnail_id' AND meta_value = '{$A}' LIMIT 1" );
run_queue();
$wpdb->explode = array();
check( 'a throw at the last look, before any deletion began: nothing deleted, an attempt counted', array( exists( $A ), files_intact( $apath ), attempts_of( $A ), started( $A ) ), array( true, true, 1, false ) );
$L = product( 'publish', $A );
time_passes();
run_queue();
check( '  used again before its next try: kept, still shown', array( exists( $A ), get_post_thumbnail_id( $L ), marked( $A ) ), array( true, $A, false ) );
check( '  and put back as an ordinary photo - not given up on, reusable', array( given_up( $A ), reused_for( 778, $aurl ) ), array( false, $A ) );
check( '  the log does not say a deletion was started', logged( 'a deletion of photo #' . $A . ' was started' ), false );

// The same every time: let go of after three tries, whole.
fresh_db();
boot();
$P = product( 'publish' );
$H = photo( $P );
$hpath = paths_of( $H );
set_post_thumbnail( $P, $H );
wp_delete_post( $P, true );
$wpdb->explode = array( "WHERE meta_key = '_thumbnail_id' AND meta_value = '{$H}' LIMIT 1" );
for ( $i = 0; $i < 4; $i++ ) {
	time_passes();
	run_queue();
}
$wpdb->explode = array();
check( 'a throw before every try: let go of after three, whole, and not given up on', array( exists( $H ), files_intact( $hpath ), marked( $H ), attempts_of( $H ), given_up( $H ) ), array( true, true, false, 0, false ) );
check( '  the log says nothing of it was deleted', logged( 'photo #' . $H . ' left in place - each of the last 3 tries to remove it ran into an error before its deletion began' ), true );
check( '  counted as one that could not be removed', TCGiant_Sync_Photo_Cleanup::stats()['failed'], 1 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA READ THE DATABASE FAILS IS NEVER TAKEN FOR 'NOTHING USES IT'\n" . str_repeat( '=', 124 ) . "\n";

// wpdb does not throw when a query fails: it answers with no rows, or null,
// and keeps what the database said in last_error. MySQL 2013 (a connection
// lost mid-query; wpdb only reconnects on 2006), 3024 (a query over the
// server's time limit), 1038 (out of sort memory), error 28 or a full
// temporary table on a full disk. Each read a judgement rests on is failed in
// turn here, with the only use of the photo it guards behind it.

/** A run with the reads containing $needle failing as wpdb's do. */
function run_failing( $needle ) {
	$GLOBALS['wpdb']->silent = (array) $needle;
	run_queue();
	$GLOBALS['wpdb']->silent = array();
}
/** The removal's view of a photo: there, its files there, still queued, as it was. */
function untouched( $id, array $paths, $mark ) {
	return array( exists( $id ), files_intact( $paths ), get_post_meta( $id, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true ), attempts_of( $id ), started( $id ) );
}

// 1a. The gallery read: a photo a live product's gallery shows, and a photo
// nothing uses beside it.
fresh_db();
boot();
$P = product( 'publish' );
$A = photo( $P );
$B = photo( $P );
set_post_thumbnail( $P, $A );
update_post_meta( $P, '_product_image_gallery', (string) $B );
$LIVE = product( 'publish', 0, array( $B ) );
$pa   = paths_of( $A );
$pb   = paths_of( $B );
wp_delete_post( $P, true );
time_passes();
$ma = get_post_meta( $A, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true );
$mb = get_post_meta( $B, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true );
run_failing( "meta_key = '_product_image_gallery' AND meta_id > " );
check( 'the gallery read fails: the photo a live gallery shows is kept, files and all', array( untouched( $B, $pb, $mb ), exists( (int) get_post_meta( $LIVE, '_product_image_gallery', true ) ) ), array( array( true, true, $mb, 0, false ), true ) );
check( '  and nothing else in the run goes on a failed read: no attempt, its place as it was', untouched( $A, $pa, $ma ), array( true, true, $ma, 0, false ) );
check( '  the log says the database did not answer, and what it said', logged( 'Photo cleanup stopped: the database did not answer while checking whether photos are still in use (Got error 28 from storage engine)' ), true );
check( '  and not that anything was removed', logged( 'Photo cleanup: removed' ), false );
check( '  the hold is let go, and the run set for after it carries on', array( get_option( 'tcgiant_sync_photo_cleanup_lock_queue', 'none' ), wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) >= time() + 290 ), array( 'none', true ) );
run_queue();
check( 'once the database answers: the unused one goes, the shown one stays', array( exists( $A ), exists( $B ) ), array( false, true ) );

// 1b. The shared-file read: its GROUP BY needs a temporary table, which a
// full disk - this shop's very problem - refuses.
fresh_db();
boot();
$P  = product( 'publish' );
$D  = photo( $P, 'ours', 'legacy.jpg' );
$L  = product( 'publish' );
$D2 = photo( $L, 'ours', 'legacy.jpg' );
file_put_contents( $WORLD . '/uploads/2026/10/legacy.jpg', str_repeat( 'L', 4000 ) );
set_post_thumbnail( $L, $D2 );
update_post_meta( $P, '_product_image_gallery', (string) $D );
wp_delete_post( $P, true );
time_passes();
run_failing( 'HAVING COUNT(*) > 1' );
check( 'the shared-file read fails: the record sharing a live photo\'s file is kept, and the file', array( exists( $D ), marked( $D ), is_file( $WORLD . '/uploads/2026/10/legacy.jpg' ) ), array( true, true, true ) );

// 1c-1h. The reads of the site's content and settings, each with the only use
// of its photo behind it.
fresh_db();
boot();
$P    = product( 'publish' );
$site = array();
foreach ( array( 'PAGE', 'VAR', 'META', 'OPTION', 'LOGO', 'ADDRESS', 'CAT', 'CATMETA', 'THUMB', 'TERM' ) as $k ) {
	$site[ $k ] = photo( $P );
}
update_post_meta( $P, '_product_image_gallery', implode( ',', $site ) );
$base = 'https://shop.example/wp-content/uploads/';
new_post( 'page', 'publish', 0, '<p>Our best part:</p><img class="alignnone size-large wp-image-' . $site['PAGE'] . '" src="x.jpg" />' );
update_post_meta( variation( product( 'publish' ) ), '_variation_description', '<img class="wp-image-' . $site['VAR'] . '" />' );
update_post_meta( new_post( 'page', 'publish', 0, '' ), '_harness_builder', $base . get_post_meta( $site['META'], '_wp_attached_file', true ) );
update_option( 'harness_slider', array( 'slide' => $base . get_post_meta( $site['OPTION'], '_wp_attached_file', true ) ) );
update_option( 'site_logo', (string) $site['LOGO'] );
new_post( 'post', 'publish', 0, '<img src="' . $base . get_post_meta( $site['ADDRESS'], '_wp_attached_file', true ) . '" />' );
term( 'product_cat', '<img class="wp-image-' . $site['CAT'] . '" src="https://cdn.example/cat.jpg" />' );
add_term_meta( term( 'product_cat' ), 'cat_top_content', '<img src="' . $base . get_post_meta( $site['CATMETA'], '_wp_attached_file', true ) . '" />' );
wp_delete_post( $P, true );
time_passes();
$reads = array(
	array( 'PAGE', 'post_content LIKE', 'the content of posts and pages (the slowest read; a query time limit)' ),
	array( 'PAGE', 'WHERE post_type IN (', 'which posts there are, walked by their key' ),
	array( 'PAGE', 'SELECT post_content, post_excerpt FROM wp_posts WHERE ID IN (', 'the content of the posts that mention a photo' ),
	array( 'VAR', "meta_key = '_variation_description'", 'which variations\' descriptions there are' ),
	array( 'VAR', 'SELECT meta_id AS id, LENGTH( meta_value ) AS bytes FROM wp_postmeta', 'which variations\' descriptions mention a photo' ),
	array( 'VAR', 'SELECT meta_value FROM wp_postmeta WHERE meta_id IN (', 'the post meta that mentions a photo' ),
	array( 'META', 'm.meta_value LIKE', 'other plugins\' post meta' ),
	array( 'META', 'FROM wp_postmeta WHERE meta_id > 0 ORDER BY meta_id ASC LIMIT 1 OFFSET', 'where a window of post meta ends' ),
	array( 'OPTION', 'option_value LIKE', 'the site\'s settings' ),
	array( 'OPTION', 'SELECT option_value FROM wp_options WHERE option_id IN (', 'the settings that hold an address' ),
	array( 'OPTION', 'FROM wp_options WHERE option_id > 0 ORDER BY option_id ASC LIMIT 1 OFFSET', 'where a window of the settings ends' ),
	array( 'CAT', 'SELECT term_taxonomy_id FROM wp_term_taxonomy', 'which categories, tags and other terms there are, walked by their key' ),
	array( 'CAT', 'description LIKE', 'which of their descriptions mention a photo' ),
	array( 'CAT', 'SELECT description FROM wp_term_taxonomy WHERE term_taxonomy_id IN (', 'the descriptions that mention a photo' ),
	array( 'CATMETA', 'AS bytes FROM wp_termmeta', 'term meta (a theme\'s content for a category)' ),
	array( 'CATMETA', 'FROM wp_termmeta WHERE meta_id > 0 ORDER BY meta_id ASC LIMIT 1 OFFSET', 'where a window of term meta ends' ),
	array( 'CATMETA', 'SELECT meta_value FROM wp_termmeta WHERE meta_id IN (', 'the term meta that holds an address' ),
	array( 'LOGO', 'WHERE option_name IN', 'the site\'s own pictures and its widgets' ),
	array( 'ADDRESS', "AND meta_key = '_wp_attached_file'", 'the files the photos have on record' ),
);
foreach ( $reads as $r ) {
	run_failing( $r[1] );
	check( "the read of {$r[2]} fails: everything stays queued", array( exists( $site[ $r[0] ] ), TCGiant_Sync_Photo_Cleanup::waiting(), get_option( TCGiant_Sync_Photo_Cleanup::READING_OPTION, 'none' ) ), array( true, count( $site ), 'none' ) );
}
run_queue();
check( 'once the database answers: each of those uses is seen, and what they guard is kept', array_map( 'exists', array_values( array_slice( $site, 0, 8 ) ) ), array_fill( 0, 8, true ) );

// 1f. The read of the photos' own records: every one would be judged "gone"
// and taken off the queue - one whose deletion had already begun among them,
// handed back to the image batch half deleted.
fresh_db();
boot();
$P    = product( 'publish' );
$K    = photo( $P );
$Q    = photo( $P );
$kurl = get_post_meta( $K, '_tcgiant_source_url', true );
update_post_meta( $P, '_product_image_gallery', "{$K},{$Q}" );
wp_delete_post( $P, true );
update_post_meta( $K, TCGiant_Sync_Photo_Cleanup::ATTEMPTS_KEY, 1 );        // a deletion was begun,
update_post_meta( $K, TCGiant_Sync_Photo_Cleanup::STARTED_KEY, time() );    // and the request killed in it
time_passes();
run_failing( 'AS has_failed' );
check( 'the read of the photos\' own records fails: both stay queued', array( marked( $K ), marked( $Q ), attempts_of( $K ), attempts_of( $Q ) ), array( true, true, 1, 0 ) );
check( '  and the one whose deletion had begun is still never reused', reused_for( 990, $kurl ), false );

// 1p. The read of a photo's own marks at its turn - where it came from, and
// whether a deletion of it began - for one whose deletion had begun: an
// offload plugin had removed its remote copies, and the host ended the
// request. Unused, it is about to be deleted again; shown again, it is about
// to be kept. Either way a failed read must not make it look untouched.
foreach ( array( 'unused' => false, 'shown again' => true ) as $what => $shown ) {
	fresh_db();
	boot();
	$P    = product( 'publish' );
	$K    = photo( $P );
	$kp   = paths_of( $K );
	$kurl = get_post_meta( $K, '_tcgiant_source_url', true );
	set_post_thumbnail( $P, $K );
	wp_delete_post( $P, true );
	update_post_meta( $K, TCGiant_Sync_Photo_Cleanup::ATTEMPTS_KEY, 1 );
	update_post_meta( $K, TCGiant_Sync_Photo_Cleanup::STARTED_KEY, (string) time() );
	if ( $shown ) {
		product( 'publish', 0, array( $K ) );
	}
	time_passes();
	$mk = get_post_meta( $K, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true );
	run_failing( "WHERE post_id = {$K} AND meta_key IN (" );
	check( "the read of a half-deleted photo's own marks fails ({$what}): it keeps every mark, no attempt charged", untouched( $K, $kp, $mk ), array( true, true, $mk, 1, true ) );
	check( '  never handed to a new product, and the log says the database failed', array( reused_for( 990, $kurl ), logged( 'Got error 28' ) ), array( false, true ) );
}

// 1q. The same photo, with WordPress's own read of its meta failed instead:
// get_post_meta() reads all of a post's meta once a request, and keeps a
// failed read as "none" for the rest of it. Judged on that, it looked like a
// photo no deletion had touched, or not the plugin's, and was put back for
// the image batch.
foreach ( array( 'unused' => false, 'shown again' => true ) as $what => $shown ) {
	fresh_db();
	boot();
	$P    = product( 'publish' );
	$K    = photo( $P );
	$kurl = get_post_meta( $K, '_tcgiant_source_url', true );
	set_post_thumbnail( $P, $K );
	wp_delete_post( $P, true );
	update_post_meta( $K, TCGiant_Sync_Photo_Cleanup::ATTEMPTS_KEY, 1 );
	update_post_meta( $K, TCGiant_Sync_Photo_Cleanup::STARTED_KEY, (string) time() );
	if ( $shown ) {
		$LIVE = product( 'publish', 0, array( $K ) );
	}
	time_passes();
	run_failing( "SELECT meta_value FROM wp_postmeta WHERE post_id = {$K} AND" );
	if ( $shown ) {
		check( 'WordPress\'s read of its meta answers nothing, and it is shown again: kept, but given up on', array( exists( $K ), given_up( $K ), reused_for( 990, $kurl ) ), array( true, true, false ) );
	} else {
		check( 'WordPress\'s read of its meta answers nothing, and nothing uses it: deleted, not put back', array( exists( $K ), reused_for( 990, $kurl ) ), array( false, false ) );
	}
}

// 1i. The read of the photos' files: every one would be "no file" and taken
// off the queue for good.
fresh_db();
boot();
$P = product( 'publish' );
$F = photo( $P );
set_post_thumbnail( $P, $F );
wp_delete_post( $P, true );
time_passes();
run_failing( "'_wp_attachment_backup_sizes' )" );
check( 'the read of the photos\' files fails: still queued', array( exists( $F ), marked( $F ) ), array( true, true ) );

// 1j. The last look, just before a deletion: a photo made a live product's
// picture, or a category's, after its round was judged, while the photo
// before it was being deleted.
foreach ( array( 'post' => "meta_key = '_thumbnail_id' AND meta_value = '%d' LIMIT 1", 'term' => "meta_key = 'thumbnail_id' AND meta_value = '%d' LIMIT 1" ) as $what => $needle ) {
	fresh_db();
	boot();
	$P  = product( 'publish' );
	$E1 = photo( $P );
	$E2 = photo( $P );
	update_post_meta( $P, '_product_image_gallery', "{$E1},{$E2}" );
	wp_delete_post( $P, true );
	$LIVE = product( 'publish' );
	$p2   = paths_of( $E2 );
	add_action( 'delete_attachment', function ( $id ) use ( $E1, $E2, $LIVE, $what, $needle ) {
		if ( (int) $id === $E1 ) {
			if ( 'post' === $what ) {
				set_post_thumbnail( $LIVE, $E2 );
			} else {
				q( "INSERT INTO wp_termmeta ( term_id, meta_key, meta_value ) VALUES ( 9, 'thumbnail_id', " . esc( $E2 ) . ' )' );
			}
			$GLOBALS['wpdb']->silent = array( sprintf( $needle, $E2 ) );
		}
	} );
	time_passes();
	$m2 = get_post_meta( $E2, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true );
	run_queue();
	$wpdb->silent = array();
	remove_all( 'delete_attachment' );
	check( "the last look at {$what} pictures fails: the photo just made one is kept, no attempt charged", untouched( $E2, $p2, $m2 ), array( true, true, $m2, 0, false ) );
	check( '  the one deleted before the read failed is counted', TCGiant_Sync_Photo_Cleanup::stats()['removed'], 1 );
}

// 1k. The check, which judges without that last look: a photo shown only as a
// featured image, or a category's picture, must not be offered on a failed read.
fresh_db();
boot();
$T  = photo( 0 );
$TC = photo( 0 );
set_post_thumbnail( product( 'publish' ), $T );
q( "INSERT INTO wp_termmeta ( term_id, meta_key, meta_value ) VALUES ( 4, 'thumbnail_id', " . esc( $TC ) . ' )' );
foreach ( array( "meta_key = '_thumbnail_id' AND meta_value IN" => 'featured images', "meta_key = 'thumbnail_id' AND meta_value IN" => 'category pictures', 'post_content LIKE' => 'content' ) as $needle => $what ) {
	TCGiant_Sync_Photo_Cleanup::start_scan();
	$wpdb->silent = array( $needle );
	$reply = ajax( 'ajax_scan', array() );
	$wpdb->silent = array();
	check( "the check's read of {$what} fails: the step answers with what the database said", array( $reply['success'], false !== strpos( (string) ( $reply['data']['message'] ?? '' ), 'Got error 28' ) ), array( false, true ) );
	check( '  and counts nothing: the check is not finished, nothing is offered', array( TCGiant_Sync_Photo_Cleanup::scan()['status'], TCGiant_Sync_Photo_Cleanup::scan()['found'] ), array( 'scanning', 0 ) );
}
check( '  its hold is let go each time', get_option( 'tcgiant_sync_photo_cleanup_lock_scan', 'none' ), 'none' );
$done = ajax( 'ajax_scan', array() );
check( '  once the database answers, the check carries on and finds nothing to offer', array( $done['data']['scan']['status'], $done['data']['scan']['found'] ), array( 'found', 0 ) );

// 1l. A read that fails every time - a site whose content query always runs
// past the host's query time limit: the check never finishes, so nothing is
// ever offered, and a photo a page shows is never removed.
fresh_db();
boot();
$A = photo( 0 );
new_post( 'page', 'publish', 0, '<img class="wp-image-' . $A . '" />' );
$wpdb->silent = array( 'post_content LIKE' );
ajax( 'ajax_scan', array( 'restart' => 1 ) );
ajax( 'ajax_scan', array() );
$approve = ajax( 'ajax_remove', array( 'scan_id' => TCGiant_Sync_Photo_Cleanup::scan()['id'] ) );
time_passes();
run_queue();
$wpdb->silent = array();
check( 'a read that always fails: the check never finishes, the button refuses, the photo stays', array( TCGiant_Sync_Photo_Cleanup::scan()['status'], $approve['success'], exists( $A ) ), array( 'scanning', false, true ) );

// 1m. The check's own reads: its page of photos, and what is already queued.
fresh_db();
boot();
$O = photo( 0 );
$W = photo( 0 );
add_post_meta( $W, TCGiant_Sync_Photo_Cleanup::MARK_KEY, (string) ( time() + 900 ), true );
TCGiant_Sync_Photo_Cleanup::start_scan();
$wpdb->silent = array( "src.meta_key = '_tcgiant_source_url'" );
ajax( 'ajax_scan', array() );
$wpdb->silent = array( "AND meta_key = '_tcgiant_delete_after'" );
ajax( 'ajax_scan', array() );
$wpdb->silent = array();
check( 'the check\'s page read fails, or its read of what is queued: not finished, nothing counted', array( TCGiant_Sync_Photo_Cleanup::scan()['status'], TCGiant_Sync_Photo_Cleanup::scan()['found'], TCGiant_Sync_Photo_Cleanup::scan()['examined'] ), array( 'scanning', 0, 0 ) );
$done = ajax( 'ajax_scan', array() );
check( '  then it carries on: the queued one counted apart, the other offered', array( $done['data']['scan']['found'], $done['data']['scan']['waiting'] ), array( 1, 1 ) );

// 1n. The button: the check approved, and a read fails while its photos are
// being got ready. Nothing goes; the background removal carries on with it.
fresh_db();
boot();
$O = photo( 0 );
new_post( 'page', 'publish', 0, '<img class="wp-image-' . ( $O + 1 ) . '" />' );
TCGiant_Sync_Photo_Cleanup::start_scan();
$s = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
$wpdb->silent = array( 'post_content LIKE' );
$reply = ajax( 'ajax_remove', array( 'scan_id' => $s['id'] ) );
$wpdb->silent = array();
check( 'a read fails while the button gets the photos ready: told so, nothing marked', array( $reply['success'], false !== strpos( (string) ( $reply['data']['message'] ?? '' ), 'Got error 28' ), TCGiant_Sync_Photo_Cleanup::waiting() ), array( false, true, 0 ) );
check( '  the removal is approved all the same, and the background run is set', array( TCGiant_Sync_Photo_Cleanup::scan()['status'], (bool) wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) ), array( 'approved', true ) );
run_queue();
time_passes();
run_queue();
check( '  which gets them ready, and removes them, once the database answers', exists( $O ), false );

// 1o. The read of which photos the button queued fails: the run stops there
// too, rather than lose count of what became of them.
fresh_db();
boot();
$O = photo( 0 );
TCGiant_Sync_Photo_Cleanup::start_scan();
$s = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
TCGiant_Sync_Photo_Cleanup::approve( $s['id'] );
TCGiant_Sync_Photo_Cleanup::queue_approved( 9999 );
time_passes();
run_failing( "AND meta_key = '_tcgiant_delete_approved'" );
check( 'the read of which photos the button queued fails: nothing removed uncounted', array( exists( $O ), TCGiant_Sync_Photo_Cleanup::check_stats()['removed'] ), array( true, 0 ) );
run_queue();
check( '  and once it answers, removed and counted', array( exists( $O ), TCGiant_Sync_Photo_Cleanup::check_stats()['removed'] ), array( false, 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA MARK THE DATABASE DID NOT KEEP IS NEVER TAKEN AS KEPT\n" . str_repeat( '=', 124 ) . "\n";

// wpdb does not throw when a write fails either, nor try it again: a
// deadlock, a lock waited on too long, a full disk - which refuses new rows
// while it still updates and deletes the rest. The mark that keeps a photo
// whose deletion began from ever being reused was written and not looked at:
// the queue's marks came off after it all the same, and the image batch
// handed the photo, part of it perhaps gone, to the next product showing
// that eBay photo. Here the database refuses that one new row.
$refusals = array(
	'stopped'       => 'its deletion begun and stopped three times',
	'after_attempt' => 'its deletion begun and stopped once, and shown again since',
	'refused'       => 'its deletion refused by another plugin',
);
foreach ( $refusals as $how => $what ) {
	fresh_db();
	boot();
	$P    = product( 'publish' );
	$K    = photo( $P );
	$kp   = paths_of( $K );
	$kurl = get_post_meta( $K, '_tcgiant_source_url', true );
	set_post_thumbnail( $P, $K );
	wp_delete_post( $P, true );
	if ( 'refused' === $how ) {
		add_filter( 'pre_delete_attachment', function ( $check, $post ) use ( $K ) {
			return (int) $post->ID === $K ? false : $check;
		}, 10, 2 );
	} else {
		update_post_meta( $K, TCGiant_Sync_Photo_Cleanup::ATTEMPTS_KEY, 'stopped' === $how ? 3 : 1 );
		update_post_meta( $K, TCGiant_Sync_Photo_Cleanup::STARTED_KEY, (string) time() );
	}
	if ( 'after_attempt' === $how ) {
		product( 'publish', 0, array( $K ) );
	}
	time_passes();
	$wpdb->refuse = array( TCGiant_Sync_Photo_Cleanup::FAILED_KEY );
	run_queue();
	$wpdb->refuse = array();
	check( "giving up on a photo ({$what}), its mark refused: it keeps its place, and its mark of a deletion begun", array( exists( $K ), files_intact( $kp ), marked( $K ), started( $K ), given_up( $K ) ), array( true, true, true, true, false ) );
	check( '  never handed to a new product', reused_for( 990, $kurl ), false );
	check( '  the log says the database did not keep it - not that it was given up on', array( logged( 'the database did not keep a mark the removal relies on (the mark that photo #' . $K . ' is never to be given to another product was not kept: The table \'wp_options\' is full)' ), logged( 'will not be given to another product' ), TCGiant_Sync_Photo_Cleanup::stats()['failed'] ), array( true, false, 0 ) );
	time_passes();
	run_queue();
	remove_all( 'pre_delete_attachment' );
	check( '  once the database keeps it: given up on, and nothing else of the queue left on it', array( exists( $K ), given_up( $K ), marked( $K ), started( $K ), reused_for( 991, $kurl ) ), array( true, true, false, false, false ) );
}

// A deletion about to begin: its mark refused. WordPress is never asked, so
// nothing of the photo can go without the mark that says it may have.
fresh_db();
boot();
$P    = product( 'publish' );
$K    = photo( $P );
$kp   = paths_of( $K );
$kurl = get_post_meta( $K, '_tcgiant_source_url', true );
set_post_thumbnail( $P, $K );
wp_delete_post( $P, true );
$ASKED = array();
add_action( 'delete_attachment', function ( $id ) { $GLOBALS['ASKED'][] = (int) $id; } );
time_passes();
$wpdb->refuse = array( TCGiant_Sync_Photo_Cleanup::STARTED_KEY );
run_queue();
$wpdb->refuse = array();
check( 'the mark of a deletion beginning refused: WordPress is never asked to delete it', array( $ASKED, exists( $K ), files_intact( $kp ), get_option( TCGiant_Sync_Photo_Cleanup::DELETING_OPTION, 'none' ) ), array( array(), true, true, 'none' ) );
check( '  it keeps its place, its try counted, and no mark of a deletion begun', array( marked( $K ), attempts_of( $K ), started( $K ), given_up( $K ) ), array( true, 1, false, false ) );
check( '  never handed to a new product meanwhile, and the log says why it stopped', array( reused_for( 990, $kurl ), logged( 'the mark that a deletion of photo #' . $K . ' had begun was not kept' ) ), array( false, true ) );
time_passes();
run_queue();
remove_all( 'delete_attachment' );
check( '  once the database keeps it, the photo goes', array( exists( $K ), $ASKED ), array( false, array( $K ) ) );

// The count of its tries refused: nothing is asked of WordPress, and no mark
// of a deletion begun is written either - one was not.
fresh_db();
boot();
$P  = product( 'publish' );
$K  = photo( $P );
$kp = paths_of( $K );
set_post_thumbnail( $P, $K );
wp_delete_post( $P, true );
time_passes();
$wpdb->refuse = array( TCGiant_Sync_Photo_Cleanup::ATTEMPTS_KEY );
run_queue();
$wpdb->refuse = array();
check( 'the count of its tries refused: nothing deleted, no mark of a deletion begun, still queued', array( exists( $K ), files_intact( $kp ), started( $K ), marked( $K ), logged( 'the count of tries to delete photo #' . $K . ' was not kept' ) ), array( true, true, false, true, true ) );
time_passes();
run_queue();
check( '  once the database keeps it, the photo goes', exists( $K ), false );

// One try counted already, and the update of the count lost - a lock waited
// on too long: still the old count, which is not this try's.
fresh_db();
boot();
$P  = product( 'publish' );
$K  = photo( $P );
$kp = paths_of( $K );
set_post_thumbnail( $P, $K );
wp_delete_post( $P, true );
update_post_meta( $K, TCGiant_Sync_Photo_Cleanup::ATTEMPTS_KEY, 1 );
time_passes();
$wpdb->silent = array( "SET meta_value = '2' WHERE post_id = {$K} AND meta_key = '_tcgiant_delete_attempts'" );
run_queue();
$wpdb->silent = array();
check( 'the update of its count of tries lost: nothing deleted, the count as it was', array( exists( $K ), files_intact( $kp ), attempts_of( $K ), started( $K ), marked( $K ) ), array( true, true, 1, false, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SPACE FREED IS WHAT REALLY WENT\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
boot();
$P  = product( 'publish' );
$F1 = photo( $P );
$F2 = photo( $P );
update_post_meta( $P, '_product_image_gallery', "{$F1},{$F2}" );
$f1_paths = paths_of( $F1 );
$stuck    = $f1_paths[2];
$expect   = bytes_of( $F1 ) + bytes_of( $F2 ) - filesize( $stuck );
wp_delete_post( $P, true );
// A file WordPress cannot delete - the wrong owner, say. It says nothing.
add_filter( 'wp_delete_file', function ( $file ) use ( $stuck ) {
	return str_replace( '\\', '/', $file ) === str_replace( '\\', '/', $stuck ) ? '' : $file;
} );
time_passes();
run_queue();
remove_all( 'wp_delete_file' );
$stats = TCGiant_Sync_Photo_Cleanup::stats();
check( 'both photos are gone from the Media Library', array( exists( $F1 ), exists( $F2 ) ), array( false, false ) );
check( '  but one file is still on disk', is_file( $stuck ), true );
check( 'the space freed counts only the files that went', $stats['bytes'], $expect );
check( '  the file left is counted for the screen', array( $stats['left_files'], $stats['left_bytes'] ), array( 1, filesize( $stuck ) ) );
check( '  and named in the log, with what to check', logged( 'is gone from the Media Library, but 1 of its file(s) could not be deleted from the uploads folder (' . wp_basename( $stuck ) . ')' ), true );
check( '  the screen has it', TCGiant_Sync_Photo_Cleanup::status()['left_files'], 1 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA RUN STOPS IN TIME\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
boot();
$P   = product( 'publish' );
$ids = array();
for ( $i = 0; $i < 12; $i++ ) {
	$ids[] = photo( $P );
}
update_post_meta( $P, '_product_image_gallery', implode( ',', $ids ) );
wp_delete_post( $P, true );
time_passes();

// Each deletion takes twenty seconds - an offload plugin's remote delete.
$CLOCK_OFFSET = 0.0;
add_action( 'delete_attachment', function () { $GLOBALS['CLOCK_OFFSET'] += 20; } );
run_queue();
$CLOCK_OFFSET = 0.0;
$gone = count( array_filter( $ids, function ( $id ) { return ! exists( $id ); } ) );
check( 'stopped within its sixty-second budget', $gone, 3 );
check( '  said so', logged( 'stopped to stay within this host\'s limits' ), true );
check( '  and the rest are still waiting, with the next run in 30 s', array( TCGiant_Sync_Photo_Cleanup::waiting(), wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) <= time() + 31 ), array( 9, true ) );
remove_all( 'delete_attachment' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA USE PAST THE FIRST PAGE OF A READ IS STILL SEEN\n" . str_repeat( '=', 124 ) . "\n";

// Galleries are read 2,000 rows at a time; content, descriptions, builder
// data and settings fetched 50 rows at a time at most. Each photo here is
// used once, on a page past the first, among rows that name other photos.
// Built in one transaction, so that this takes a second rather than a minute.
fresh_db();
boot();
$pg = array();
foreach ( array( 'GALLERY', 'CONTENT', 'VARIATION', 'BUILDER', 'OPTION', 'CATEGORY', 'TERMMETA', 'NONE' ) as $k ) {
	$pg[ $k ] = photo( 0 );
}
$addr = function ( $id ) { return 'https://shop.example/wp-content/uploads/' . get_post_meta( $id, '_wp_attached_file', true ); };
$wpdb->db->exec( 'BEGIN' );
for ( $i = 1; $i <= 2500; $i++ ) {
	update_post_meta( new_post( 'product', 'publish' ), '_product_image_gallery', 2400 === $i ? "9999991,{$pg['GALLERY']}" : '9999991,9999992' );
}
for ( $i = 1; $i <= 250; $i++ ) {
	new_post( 'post', 'publish', 0, '<img class="wp-image-' . ( 240 === $i ? $pg['CONTENT'] : 9999993 ) . '" />' );
}
// Categories past the first page of terms, every description naming a photo.
for ( $i = 1; $i <= 600; $i++ ) {
	term( 'product_cat', '<img class="wp-image-' . ( 560 === $i ? $pg['CATEGORY'] : 9999996 ) . '" />' );
}
// Rows enough ahead of them that the uses below are past the first window of
// post meta, of settings and of term meta, that one query looks through.
for ( $i = 1; $i <= TCGiant_Sync_Photo_Cleanup::WINDOW + 200; $i++ ) {
	q( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( 1, '_harness_filler', 'x' )" );
	q( "INSERT INTO wp_options ( option_name, option_value, autoload ) VALUES ( 'harness_filler_{$i}', 'x', 'no' )" );
	q( "INSERT INTO wp_termmeta ( term_id, meta_key, meta_value ) VALUES ( 1, 'order', '0' )" );
}
$parent = new_post( 'product', 'publish' );
for ( $i = 1; $i <= 150; $i++ ) {
	update_post_meta( new_post( 'product_variation', 'publish', $parent ), '_variation_description', '<img class="wp-image-' . ( 140 === $i ? $pg['VARIATION'] : 9999994 ) . '" />' );
	update_post_meta( new_post( 'page', 'publish' ), '_harness_builder', 140 === $i ? $addr( $pg['BUILDER'] ) : 'https://shop.example/wp-content/uploads/2026/10/elsewhere.jpg' );
	update_option( 'harness_slide_' . $i, 140 === $i ? $addr( $pg['OPTION'] ) : 'https://shop.example/wp-content/uploads/2026/10/elsewhere.jpg' );
	add_term_meta( $i, 'cat_top_content', '<img src="' . ( 140 === $i ? $addr( $pg['TERMMETA'] ) : 'https://shop.example/wp-content/uploads/2026/10/elsewhere.jpg' ) . '" />' );
}
$wpdb->db->exec( 'COMMIT' );
$wpdb->log = array();
$v = TCGiant_Sync_Photo_Cleanup::verdicts( $pg );
$windows = array( 'm.meta_id' => array(), 'option_id' => array(), 'termmeta' => array() );
$widest  = 0;
$terms   = 0;
foreach ( $wpdb->log as $one ) {
	if ( preg_match( '/(m\.meta_id|option_id) <= (\d+)/', $one, $w ) ) {
		$windows[ $w[1] ][] = $w[2];
	} elseif ( false !== strpos( $one, 'AS bytes FROM wp_termmeta' ) && preg_match( '/meta_id <= (\d+)/', $one, $w ) ) {
		$windows['termmeta'][] = $w[1];
	}
	if ( preg_match( '/^SELECT [\w, ]+ FROM wp_\w+ WHERE \w+ IN \( ([\d,]+) \)$/', $one, $f ) ) {
		$widest = max( $widest, substr_count( $f[1], ',' ) + 1 );
	}
	if ( 0 === strpos( $one, 'SELECT term_taxonomy_id FROM wp_term_taxonomy' ) ) {
		$terms++;
	}
}
$wpdb->log = null;
check( 'small values are fetched ' . TCGiant_Sync_Photo_Cleanup::READ_PAGE . ' rows at a time at most', $widest, TCGiant_Sync_Photo_Cleanup::READ_PAGE );
check( 'post meta, settings and term meta are looked through a window at a time, the last open-ended', array( count( $windows['m.meta_id'] ) > 1, (string) PHP_INT_MAX === end( $windows['m.meta_id'] ), count( $windows['option_id'] ) > 1, (string) PHP_INT_MAX === end( $windows['option_id'] ), count( $windows['termmeta'] ) > 1, (string) PHP_INT_MAX === end( $windows['termmeta'] ) ), array( true, true, true, true, true, true ) );
check( '  each no wider than ' . TCGiant_Sync_Photo_Cleanup::WINDOW . ' rows', array( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_postmeta WHERE meta_id <= ' . (int) ( $windows['m.meta_id'][0] ?? 0 ) ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_termmeta WHERE meta_id <= ' . (int) ( $windows['termmeta'][0] ?? 0 ) ) ), array( TCGiant_Sync_Photo_Cleanup::WINDOW, TCGiant_Sync_Photo_Cleanup::WINDOW ) );
check( '  and the terms walked ' . TCGiant_Sync_Photo_Cleanup::KEY_PAGE . ' at a time: 600 of them in two pages', $terms, 2 );
check( 'in gallery 2,400 of 2,500', $v[ $pg['GALLERY'] ], 'in_use' );
check( 'in post 240 of 250 that mention a photo', $v[ $pg['CONTENT'] ], 'in_use' );
check( 'in variation description 140 of 150', $v[ $pg['VARIATION'] ], 'in_use' );
check( 'in builder data 140 of 150, by address, past the first window of post meta', $v[ $pg['BUILDER'] ], 'in_use' );
check( 'in setting 140 of 150, by address, past the first window of settings', $v[ $pg['OPTION'] ], 'in_use' );
check( 'in category description 560 of 600, past the first page of terms', $v[ $pg['CATEGORY'] ], 'in_use' );
check( 'in term meta 140 of 150, by address, past the first window of term meta', $v[ $pg['TERMMETA'] ], 'in_use' );
check( 'the photo none of them uses may go', $v[ $pg['NONE'] ], '' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT CAN ONLY BE READ FOR THE WHOLE SITE IS READ ONCE A RUN, NOT ONCE A ROUND\n" . str_repeat( '=', 124 ) . "\n";

// Every gallery on the site, and every post that mentions a photo, used to be
// read again for each round of fifty photos: some 18 million gallery rows for
// a removal of sixty thousand photos on a shop of fifteen thousand products.
function queries_with( $needle ) {
	return count( array_filter( (array) $GLOBALS['wpdb']->log, function ( $q ) use ( $needle ) { return false !== strpos( $q, $needle ); } ) );
}
/** How many times the content of the site was read: each reading walks the posts from the first. */
function readings_of_content() {
	return count( array_filter( (array) $GLOBALS['wpdb']->log, function ( $q ) { return false !== strpos( $q, 'WHERE post_type IN (' ) && 1 === preg_match( '/AND ID > 0\s/', $q ); } ) );
}
fresh_db();
boot();
$P   = product( 'publish' );
$ids = array();
$wpdb->db->exec( 'BEGIN' );
for ( $i = 0; $i < 130; $i++ ) {
	$ids[] = photo( $P, 'offloaded' );
}
update_post_meta( $P, '_product_image_gallery', implode( ',', $ids ) );
for ( $i = 0; $i < 5; $i++ ) {
	product( 'publish', 0, array( 9999990 + $i ) );
	new_post( 'post', 'publish', 0, '<img class="wp-image-' . ( 9999980 + $i ) . '" />' );
	term( 'product_cat', '<img class="wp-image-' . ( 9999970 + $i ) . '" />' );
}
$wpdb->db->exec( 'COMMIT' );
wp_delete_post( $P, true );
time_passes();
$wpdb->log = array();
run_queue();
$log = $wpdb->log;
$wpdb->log = null;
check( 'one run removes 130 photos, in three rounds', array( TCGiant_Sync_Photo_Cleanup::waiting(), TCGiant_Sync_Photo_Cleanup::stats()['removed'] ), array( 0, 130 ) );
$wpdb->log = $log;
check( '  reading the galleries once, not once a round', queries_with( "WHERE meta_key = '_product_image_gallery' AND meta_id > 0" ), 1 );
check( '  and the content of posts and pages once', readings_of_content(), 1 );
check( '  and other plugins\' post meta, and the settings, once each', array( queries_with( 'm.meta_value LIKE' ), queries_with( 'option_value LIKE' ) ), array( 1, 1 ) );
check( '  and the descriptions of categories, and term meta, once each', array( queries_with( 'description LIKE' ), queries_with( 'AS bytes FROM wp_termmeta' ) ), array( 1, 1 ) );
$wpdb->log = null;

// The check: one step over several pages reads the site's content once.
fresh_db();
boot();
$wpdb->db->exec( 'BEGIN' );
for ( $i = 0; $i < 1200; $i++ ) {
	photo( 0, 'offloaded' );
}
$wpdb->db->exec( 'COMMIT' );
TCGiant_Sync_Photo_Cleanup::start_scan();
$wpdb->log = array();
$s = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
check( 'a step of the check over three pages finds them all', array( $s['status'], $s['found'] ), array( 'found', 1200 ) );
check( '  reading the content of posts and pages once', readings_of_content(), 1 );
$wpdb->log = null;

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEACH DESCRIPTION IS LOOKED AT ONCE, WHATEVER PLAN THE DATABASE PICKS\n" . str_repeat( '=', 124 ) . "\n";

// A shop whose eBay template carries a banner hosted on the shop itself,
// under /wp-content/uploads/: every description mentions it. Asked for in one
// query a page, the database may answer from its index on the kind of post -
// SQLite does here, MySQL and MariaDB may - and then look through every
// remaining description again for each page: fifteen thousand took ten
// minutes, a run's whole budget ten times over. LIKE is counted here as the
// database works through it, on the descriptions alone.
function harness_like( $pattern, $value, $escape = null ) {
	$re = '';
	for ( $i = 0, $n = strlen( (string) $pattern ); $i < $n; $i++ ) {
		$c = $pattern[ $i ];
		if ( null !== $escape && $c === $escape && $i + 1 < $n ) {
			$re .= preg_quote( $pattern[ ++$i ], '/' );
		} elseif ( '%' === $c ) {
			$re .= '.*';
		} elseif ( '_' === $c ) {
			$re .= '.';
		} else {
			$re .= preg_quote( $c, '/' );
		}
	}
	return (int) preg_match( '/^' . $re . '$/is', (string) $value );
}
fresh_db();
boot();
$LIKED = array( 'product' => 0, 'variation' => 0 );
$wpdb->db->createFunction( 'like', function ( $pattern, $value, $escape = null ) {
	if ( false !== strpos( (string) $value, 'harness-description' ) ) {
		$GLOBALS['LIKED']['product']++;
	} elseif ( false !== strpos( (string) $value, 'harness-variation' ) ) {
		$GLOBALS['LIKED']['variation']++;
	}
	return harness_like( $pattern, $value, $escape );
} );
$banner = '<img src="https://shop.example/wp-content/uploads/2023/05/header-banner.jpg">';
$wpdb->db->exec( 'BEGIN' );
for ( $i = 0; $i < 1500; $i++ ) {
	// Two of them, far down, also name a photo by its ID.
	$pid = new_post( 'product', 'publish', 0, '<div class="harness-description">' . $banner . '<p>Part ' . $i . '</p>' . ( 1490 === $i ? '<img class="wp-image-88888" />' : '' ) . '</div>' );
	if ( $i < 600 ) {
		update_post_meta( new_post( 'product_variation', 'publish', $pid ), '_variation_description', '<p class="harness-variation">' . $banner . ( 590 === $i ? '<img class="wp-image-77777" />' : '' ) . '</p>' );
	}
}
$wpdb->db->exec( 'COMMIT' );
$site = new ReflectionMethod( 'TCGiant_Sync_Photo_Cleanup', 'site_uses' );
$site->setAccessible( true );
$took = microtime( true );
$got  = $site->invoke( null );
$took = microtime( true ) - $took;
$needles = new ReflectionMethod( 'TCGiant_Sync_Photo_Cleanup', 'needles' );
$needles->setAccessible( true );
$per  = count( $needles->invoke( null ) ) * 2;
printf( "  (1,500 descriptions and 600 variation descriptions read in %.1f s; LIKE worked through %d and %d times)\n", $took, $LIKED['product'], $LIKED['variation'] );
check( 'the banner they all show is seen, and the photos named in description 1,490 and variation 590', array( isset( $got['files']['2023/05/header-banner'] ), isset( $got['ids'][88888] ), isset( $got['ids'][77777] ) ), array( true, true, true ) );
check( 'each product\'s description is looked through once, not again for every page', $LIKED['product'] <= 1500 * $per, true );
check( '  and each variation\'s', $LIKED['variation'] <= 600 * ( $per + 1 ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWITHOUT THE PLUGIN'S INDEX ON wp_postmeta\n" . str_repeat( '=', 124 ) . "\n";

// TCGiant_Sync_DB records its attempt to add the index as done even when the
// database refused it. Without it, each photo's last look reads every
// featured image on the site, and each round every photo's file record.
fresh_db();
boot();
$P   = product( 'publish' );
$ids = array();
$wpdb->db->exec( 'BEGIN' );
for ( $i = 0; $i < 150; $i++ ) {
	$ids[] = photo( $P, 'offloaded' );
}
$wpdb->db->exec( 'COMMIT' );
update_post_meta( $P, '_product_image_gallery', implode( ',', $ids ) );
wp_delete_post( $P, true );
time_passes();
$wpdb->indexes = array();
run_queue();
check( 'a run takes on at most 100 photos', TCGiant_Sync_Photo_Cleanup::waiting(), 50 );
check( '  says why, and how to put it right, in words', array( logged( 'is missing the index the plugin adds to it (tcgiant_key_value)' ), logged( 'ALTER TABLE wp_postmeta ADD INDEX tcgiant_key_value' ) ), array( true, true ) );
check( '  and comes back for the rest in thirty seconds', wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) <= time() + 31, true );
run_queue();
check( '  the next run takes the rest; the warning is not repeated within the day', array( TCGiant_Sync_Photo_Cleanup::waiting(), times_logged( 'is missing the index the plugin adds' ) ), array( 0, 1 ) );
$wpdb->indexes = array( 'tcgiant_key_value' );

fresh_db();
boot();
$P   = product( 'publish' );
$ids = array();
$wpdb->db->exec( 'BEGIN' );
for ( $i = 0; $i < 150; $i++ ) {
	$ids[] = photo( $P, 'offloaded' );
}
$wpdb->db->exec( 'COMMIT' );
update_post_meta( $P, '_product_image_gallery', implode( ',', $ids ) );
wp_delete_post( $P, true );
time_passes();
run_queue();
check( 'with the index: all 150 in one run, and no warning', array( TCGiant_Sync_Photo_Cleanup::waiting(), logged( 'is missing the index' ) ), array( 0, false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE CHECK COUNTS EXACTLY WHAT THE REMOVAL REMOVES\n" . str_repeat( '=', 124 ) . "\n";

/**
 * A shop of a few hundred products in every state, built from a seed, with
 * its history: products sold and deleted for good before this version (their
 * photos left behind), photos shared, the shop's own uploads, stand-ins,
 * photos already offloaded, category pictures, old duplicate records, photos
 * in blog posts and the site's icon.
 */
function build_shop( $seed ) {
	fresh_db();
	boot();
	mt_srand( $seed );

	$all  = array();
	$live = array();

	// Products deleted before this version: their photos are left unattached.
	// WordPress moved them to no parent when the products went. Half now and
	// half after the live products, so the check finds some on every page.
	$leftovers = function ( $products ) use ( &$all ) {
		for ( $i = 0; $i < $products; $i++ ) {
			$n = mt_rand( 1, 9 );
			for ( $j = 0; $j < $n; $j++ ) {
				$all[] = photo( 0, mt_rand( 0, 9 ) ? 'ours' : ( mt_rand( 0, 1 ) ? 'offloaded' : 'theirs' ) );
			}
		}
	};
	$leftovers( 45 );

	// Photos still attached to a product that was deleted without WordPress
	// (a parent that no longer exists).
	for ( $i = 0; $i < 20; $i++ ) {
		$all[] = photo( 900000 + $i );
	}

	// Products in every state, each with its own photos.
	foreach ( array( 'publish', 'publish', 'publish', 'draft', 'private', 'trash' ) as $status ) {
		for ( $i = 0; $i < 12; $i++ ) {
			$p     = product( $status );
			$mine  = array();
			for ( $j = 0, $n = mt_rand( 1, 6 ); $j < $n; $j++ ) {
				$mine[] = photo( $p, mt_rand( 0, 7 ) ? 'ours' : 'theirs' );
			}
			// Some show photos of the deleted products' too (a reused download).
			if ( $all && 0 === mt_rand( 0, 2 ) ) {
				$mine[] = $all[ mt_rand( 0, count( $all ) - 1 ) ];
			}
			set_post_thumbnail( $p, $mine[0] );
			update_post_meta( $p, '_product_image_gallery', implode( ',', array_slice( $mine, 1 ) ) );
			$all   = array_merge( $all, $mine );
			$live[] = $p;
		}
	}

	$leftovers( 45 );

	// Variations whose photo is one of the leftovers.
	for ( $i = 0; $i < 10; $i++ ) {
		$v = variation( $live[ array_rand( $live ) ], mt_rand( 0, 1 ) ? 'publish' : 'trash' );
		set_post_thumbnail( $v, $all[ mt_rand( 0, 400 ) ] );
	}

	// A blog post featuring one, one placed in a page, two category pictures,
	// one placed in a category's description, and the site's icon.
	set_post_thumbnail( new_post( 'post', 'publish' ), $all[3] );
	new_post( 'page', 'publish', 0, '<img class="wp-image-' . $all[14] . '" />' );
	q( "INSERT INTO wp_termmeta ( term_id, meta_key, meta_value ) VALUES ( 3, 'thumbnail_id', " . esc( $all[5] ) . " ), ( 4, 'thumbnail_id', " . esc( $all[8] ) . ' )' );
	term( 'product_cat', '<img class="alignnone wp-image-' . $all[18] . '" />' );
	update_option( 'site_icon', (string) $all[16] );

	// eBay stand-ins.
	for ( $i = 0; $i < 15; $i++ ) {
		$all[] = photo( 0, 'standin' );
	}

	// An old duplicate record of a leftover's file.
	$twin = new_post( 'attachment', 'inherit', 0 );
	update_post_meta( $twin, '_wp_attached_file', get_post_meta( $all[12], '_wp_attached_file', true ) );
	update_post_meta( $twin, '_tcgiant_source_url', 'https://i.ebayimg.com/twin.jpg' );
	$all[] = $twin;

	return $all;
}

/** Every photo with its files, before. */
function snapshot( array $ids ) {
	$out = array();
	foreach ( $ids as $id ) {
		$out[ $id ] = array( 'paths' => paths_of( $id ), 'ours' => '' !== get_post_meta( $id, '_tcgiant_source_url', true ) && '' === get_post_meta( $id, '_tcgiant_external_url', true ) );
	}
	return $out;
}

function found_ids() {
	$scan = TCGiant_Sync_Photo_Cleanup::scan();
	$ids  = array();
	for ( $i = 0; $i < (int) $scan['chunks']; $i++ ) {
		$csv = get_option( TCGiant_Sync_Photo_Cleanup::SCAN_IDS_PREFIX . $i, '' );
		$ids = array_merge( $ids, '' === $csv ? array() : array_map( 'intval', explode( ',', $csv ) ) );
	}
	return $ids;
}

foreach ( array( 11, 29 ) as $seed ) {
	$all    = build_shop( $seed );
	$before = snapshot( $all );
	$photos = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_tcgiant_source_url'" );

	TCGiant_Sync_Photo_Cleanup::start_scan();
	$steps = 0;
	do {
		$scan = TCGiant_Sync_Photo_Cleanup::scan_step( 0 ); // a short piece a step
		$steps++;
	} while ( 'scanning' === $scan['status'] && $steps < 50 );

	$found = found_ids();
	check( "seed {$seed}: the check went over several pages ({$photos} downloaded photos, {$steps} steps)", $steps > 2 && $photos > TCGiant_Sync_Photo_Cleanup::SCAN_PAGE, true );
	check( '  each find once', count( $found ), count( array_unique( $found ) ) );
	check( '  the count is the list', $scan['found'], count( $found ) );
	check( '  every photo looked at once', $scan['examined'], $photos );
	check( '  not the photo a page shows, nor the site\'s icon, nor the one in a category\'s description', array( in_array( $all[14], $found, true ), in_array( $all[16], $found, true ), in_array( $all[18], $found, true ) ), array( false, false, false ) );

	$expected_bytes = 0;
	foreach ( $found as $id ) {
		$expected_bytes += bytes_of( $id );
	}
	check( '  the bytes are those files\' on disk', $scan['bytes'], $expected_bytes );

	$status = TCGiant_Sync_Photo_Cleanup::status();
	$bad    = TCGiant_Sync_Photo_Cleanup::approve( 'not-this-one' );
	check( '  a page from another check cannot approve this one', is_wp_error( $bad ), true );
	TCGiant_Sync_Photo_Cleanup::approve( $status['scan']['id'] );
	check( '  a new check is refused while its photos are being queued', is_wp_error( TCGiant_Sync_Photo_Cleanup::start_scan() ), true );

	$runs = 0;
	do {
		run_queue();
		time_passes();
		$runs++;
	} while ( ( TCGiant_Sync_Photo_Cleanup::waiting() > 0 || 'approved' === TCGiant_Sync_Photo_Cleanup::scan()['status'] ) && $runs < 40 );

	$removed = array();
	$kept_ok = true;
	$theirs_ok = true;
	foreach ( $before as $id => $b ) {
		if ( ! exists( $id ) ) {
			$removed[] = $id;
			if ( $b['paths'] && ( is_file( $b['paths'][0] ) && 1 === count( array_filter( $before, function ( $o ) use ( $b ) { return $o['paths'] && $o['paths'][0] === $b['paths'][0]; } ) ) ) ) {
				$kept_ok = false; // files left behind by a removal
			}
		} elseif ( $b['paths'] && ! files_intact( array_filter( $b['paths'], 'is_file' ) ) && files_intact( $b['paths'] ) ) {
			$kept_ok = false;
		}
		if ( ! $b['ours'] && ! exists( $id ) ) {
			$theirs_ok = false;
		}
	}
	sort( $removed );
	sort( $found );
	check( '  the removal removed exactly what the check found', $removed, $found );
	check( '  and freed exactly what it said', TCGiant_Sync_Photo_Cleanup::stats()['bytes'], $expected_bytes );
	check( '  no file left behind by a removal; no kept photo lost one', $kept_ok, true );
	check( '  nothing the shop uploaded, and no stand-in, was touched', $theirs_ok, true );

	$intact = true;
	foreach ( $GLOBALS['wpdb']->get_col( "SELECT meta_value FROM wp_postmeta WHERE meta_key IN ( '_thumbnail_id' )" ) as $t ) {
		$intact = $intact && exists( (int) $t );
	}
	foreach ( $GLOBALS['wpdb']->get_col( "SELECT meta_value FROM wp_postmeta WHERE meta_key = '_product_image_gallery'" ) as $g ) {
		foreach ( array_filter( explode( ',', $g ) ) as $gid ) {
			$intact = $intact && exists( (int) $gid );
		}
	}
	foreach ( $GLOBALS['wpdb']->get_col( "SELECT meta_value FROM wp_termmeta WHERE meta_key = 'thumbnail_id'" ) as $t ) {
		$intact = $intact && exists( (int) $t );
	}
	$intact = $intact && exists( $all[14] ) && exists( $all[16] ) && exists( $all[18] );
	check( '  every product, variation, post, page, category and the site still has every picture', $intact, true );
	check( '  the check\'s list is cleared away', $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'tcgiant_sync_photo_scan_ids_%'" ), '0' );

	// The same shop, checked again: nothing left to find.
	TCGiant_Sync_Photo_Cleanup::start_scan();
	$again = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
	check( '  checked again afterwards: nothing left', $again['found'], 0 );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA CHECK COUNTS WHAT IS ALREADY WAITING, AND WHAT WAS GIVEN UP ON, APART\n" . str_repeat( '=', 124 ) . "\n";

build_shop( 11 );
TCGiant_Sync_Photo_Cleanup::start_scan();
$first = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
$finds = found_ids();
// Between two checks: a few are queued for removal - the button, or a product
// deleted since - and one was given up on.
foreach ( array_slice( $finds, 0, 3 ) as $id ) {
	add_post_meta( $id, TCGiant_Sync_Photo_Cleanup::MARK_KEY, (string) ( time() + 900 ), true );
}
add_post_meta( $finds[5], TCGiant_Sync_Photo_Cleanup::FAILED_KEY, (string) time(), true );
TCGiant_Sync_Photo_Cleanup::start_scan();
$second = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
check( 'checked again during a removal: the queued ones are not counted again', array( $second['found'], $second['waiting'] ), array( $first['found'] - 4, 3 ) );
check( '  nor their bytes', $second['bytes'], $first['bytes'] - bytes_of( $finds[0] ) - bytes_of( $finds[1] ) - bytes_of( $finds[2] ) - bytes_of( $finds[5] ) );
check( '  the one given up on is counted apart, not offered', array( $second['failed'], in_array( $finds[5], found_ids(), true ) ), array( 1, false ) );
check( '  and the screen has both', array( TCGiant_Sync_Photo_Cleanup::status()['scan']['waiting'], TCGiant_Sync_Photo_Cleanup::status()['scan']['failed'] ), array( 3, 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA CHECK THE HOST KILLS PART WAY THROUGH COUNTS NOTHING TWICE\n" . str_repeat( '=', 124 ) . "\n";

build_shop( 11 );
TCGiant_Sync_Photo_Cleanup::start_scan();
$clean = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
$clean_found = found_ids();

function all_finds_on_record() {
	$ids = array();
	foreach ( $GLOBALS['wpdb']->get_col( "SELECT option_value FROM wp_options WHERE option_name LIKE 'tcgiant_sync_photo_scan_ids_%'" ) as $csv ) {
		$ids = array_merge( $ids, '' === $csv ? array() : explode( ',', $csv ) );
	}
	return count( $ids );
}

// Killed on the first page: its finds written, nothing counted yet.
TCGiant_Sync_Photo_Cleanup::start_scan();
$code = in_child( 'scan:' . TCGiant_Sync_Photo_Cleanup::SCAN_IDS_PREFIX . '0' );
$mid  = TCGiant_Sync_Photo_Cleanup::scan();
check( 'killed on the first page, after its finds were written', array( 0 !== $code, $mid['status'], $mid['found'], all_finds_on_record() > 0 ), array( true, 'scanning', 0, true ) );
boot();
TCGiant_Sync_Photo_Cleanup::scan_step( 0 ); // the first piece again, counted this time
$mid = TCGiant_Sync_Photo_Cleanup::scan();
check( '  carried on: the first piece counted once', count( found_ids() ), $mid['found'] );

// Killed on the next page, after its finds were added to the list.
$code = in_child( 'scan:' . TCGiant_Sync_Photo_Cleanup::SCAN_IDS_PREFIX . '0' );
$mid2 = TCGiant_Sync_Photo_Cleanup::scan();
check( 'killed on the next page, after its finds were added', array( 0 !== $code, $mid2['status'], $mid2['found'] === $mid['found'] ), array( true, 'scanning', true ) );
check( '  the list then holds finds the count does not', all_finds_on_record() > $mid2['found'], true );
boot();
$done    = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
$resumed = found_ids();
check( 'carried on: the same finds as a check never killed', $resumed, $clean_found );
check( '  the same count and bytes', array( $done['found'], $done['bytes'] ), array( $clean['found'], $clean['bytes'] ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTWO STEPS OF ONE CHECK AT ONCE\n" . str_repeat( '=', 124 ) . "\n";

build_shop( 29 );
$hold = new ReflectionMethod( 'TCGiant_Sync_Photo_Cleanup', 'scan_hold_seconds' );
$hold->setAccessible( true );
TCGiant_Sync_Runtime::raise_time_limit( 120 );
check( 'the check\'s hold outlives any request: past a three-minute host limit, and PHP\'s own', $hold->invoke( null ) >= 210 && $hold->invoke( null ) > TCGiant_Sync_Runtime::effective_time_limit(), true );

// A step the browser stopped waiting for is still running when the one it
// asked for instead moves the check on.
TCGiant_Sync_Photo_Cleanup::start_scan();
$s0 = TCGiant_Sync_Photo_Cleanup::scan_step( 0 );
add_filter( 'tcgiant_sync_photos_in_use', function ( $keep ) {
	static $once = false;
	if ( ! $once ) {
		$once = true;
		$s = get_option( 'tcgiant_sync_photo_scan' );
		$s['cursor'] += 1000; // the other request saved its step meanwhile
		update_option( 'tcgiant_sync_photo_scan', $s );
	}
	return $keep;
} );
$on_record = all_finds_on_record();
$late      = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
remove_all( 'tcgiant_sync_photos_in_use' );
check( 'the step that was overtaken writes nothing, and gives way', array( all_finds_on_record(), $late['cursor'], $late['found'] ), array( $on_record, $s0['cursor'] + 1000, $s0['found'] ) );

// A page slower than the screen's request is meant to last.
TCGiant_Sync_Photo_Cleanup::start_scan();
add_filter( 'tcgiant_sync_photos_in_use', function ( $keep ) { $GLOBALS['CLOCK_OFFSET'] += 30; return $keep; } );
$slow = TCGiant_Sync_Photo_Cleanup::scan_step( TCGiant_Sync_Runtime::elapsed() + 20 );
remove_all( 'tcgiant_sync_photos_in_use' );
$CLOCK_OFFSET = 0.0;
check( 'a step that runs out of time part way through a page stops there, and saves how far it got', array( $slow['examined'], $slow['status'] ), array( TCGiant_Sync_Photo_Cleanup::SCAN_TIME_EVERY, 'scanning' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SHOP'S BUTTON, AND ITS GUARDS\n" . str_repeat( '=', 124 ) . "\n";

build_shop( 29 );
TCGiant_Sync_Photo_Cleanup::start_scan();
$s = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
time_passes( 8 * DAY_IN_SECONDS );
check( 'a check more than a week old cannot be acted on', is_wp_error( TCGiant_Sync_Photo_Cleanup::approve( $s['id'] ) ), true );
check( '  nothing was marked', TCGiant_Sync_Photo_Cleanup::waiting(), 0 );
check( '  and the screen hides the button', TCGiant_Sync_Photo_Cleanup::status()['scan']['stale'], true );

TCGiant_Sync_Photo_Cleanup::start_scan();
$s = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
$found = found_ids();
// Between the check and the button, a product starts showing one of the finds,
// and two others are queued by a product deleted meanwhile.
$adopter = product( 'publish' );
set_post_thumbnail( $adopter, $found[0] );
add_post_meta( $found[1], TCGiant_Sync_Photo_Cleanup::MARK_KEY, (string) ( time() + 900 ), true );
add_post_meta( $found[2], TCGiant_Sync_Photo_Cleanup::MARK_KEY, (string) ( time() + 900 ), true );
TCGiant_Sync_Photo_Cleanup::approve( $s['id'] );
TCGiant_Sync_Photo_Cleanup::queue_approved( 9999 );
$after = TCGiant_Sync_Photo_Cleanup::scan();
check( 'used again after the check: not queued, and counted as kept', array( marked( $found[0] ), $after['kept'] ), array( false, 1 ) );
check( 'queued already: counted as waiting, not queued a second time', array( $after['queued'], $after['already'] ), array( $s['found'] - 3, 2 ) );
check( '  so what the screen counts down from is what is really waiting', TCGiant_Sync_Photo_Cleanup::status()['scan']['queued'], TCGiant_Sync_Photo_Cleanup::waiting() );
check( '  queued photos are marked for the quarantine, not for now', TCGiant_Sync_Photo_Cleanup::due(), 0 );
check( '  the log says what was queued', logged( 'from the check are waiting to be removed. 1 kept: something started using them after the check.' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT THE SCREEN SAYS THE BUTTON REMOVED\n" . str_repeat( '=', 124 ) . "\n";

// The screen counts what became of the check's own photos. It used to count
// every removal since the button was pressed: a check that found three, then
// a product sold since, and it said "Finished: 8 photos removed".
fresh_db();
boot();
installed_days_ago( 40 );
$left = array( photo( 0 ), photo( 0 ), photo( 0 ) );
$freed = bytes_of( $left[0] ) + bytes_of( $left[1] );
TCGiant_Sync_Photo_Cleanup::start_scan();
$s = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
TCGiant_Sync_Photo_Cleanup::approve( $s['id'] );
TCGiant_Sync_Photo_Cleanup::queue_approved( 9999 );
// A product sold since, with five photos; and another plugin will not let
// one of the check's photos be deleted.
$P   = product( 'publish' );
$own = array();
for ( $i = 0; $i < 5; $i++ ) {
	$own[] = photo( $P );
}
update_post_meta( $P, '_product_image_gallery', implode( ',', $own ) );
wp_trash_post( $P );
trashed_days_ago( 31 );
wp_scheduled_delete();
do_action( 'shutdown' );
$st = TCGiant_Sync_Photo_Cleanup::status();
check( 'while they wait: the check\'s three are counted, and the five of the product sold since apart', array( $st['scan']['found'], $st['check']['waiting'], $st['auto_waiting'] ), array( 3, 3, 5 ) );
add_filter( 'pre_delete_attachment', function ( $check, $post ) use ( $left ) {
	return (int) $post->ID === $left[2] ? false : $check;
}, 10, 2 );
time_passes();
run_queue();
remove_all( 'pre_delete_attachment' );
$st = TCGiant_Sync_Photo_Cleanup::status();
check( 'finished: two of the check\'s removed, one that could not be, none waiting', array( $st['check']['removed'], $st['check']['failed'], $st['check']['waiting'] ), array( 2, 1, 0 ) );
check( '  not the photos of the product sold since', TCGiant_Sync_Photo_Cleanup::stats()['removed'], 7 );
check( '  and the space freed is the check\'s photos\' own', $st['check']['freed'], size_format( $freed, 1 ) );

$Q = product( 'publish' );
$q = array( photo( $Q ), photo( $Q ) );
update_post_meta( $Q, '_product_image_gallery', implode( ',', $q ) );
wp_delete_post( $Q, true );
$st = TCGiant_Sync_Photo_Cleanup::status();
check( 'a product deleted afterwards does not reopen the check\'s count; it is counted apart', array( $st['check']['removed'], $st['check']['waiting'], $st['auto_waiting'] ), array( 2, 0, 2 ) );

// A new check starts its count afresh.
$r = photo( 0 );
TCGiant_Sync_Photo_Cleanup::start_scan();
$s2 = TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
TCGiant_Sync_Photo_Cleanup::approve( $s2['id'] );
TCGiant_Sync_Photo_Cleanup::queue_approved( 9999 );
$st = TCGiant_Sync_Photo_Cleanup::status();
check( 'the next check approved: its own count, from nothing', array( $s2['found'], $st['check']['removed'], $st['check']['waiting'], $st['auto_waiting'] ), array( 1, 0, 1, 2 ) );
$view = file_get_contents( $ROOT . '/admin/views/image-cleanup.php' );
check( 'the screen counts down from the check\'s own photos, and shows what could not be removed', array( false !== strpos( $view, 'c.removed' ), false !== strpos( $view, 'c.failed' ), false !== strpos( $view, 'removed_since' ) ), array( true, true, false ) );
check( '  and says why the removal is paused: a deletion, the reading, a reading too slow, or too big for the memory left', array( false !== strpos( $view, "'reading' === \$tc_photo['paused_why']" ), false !== strpos( $view, "'slow' === \$tc_photo['paused_why']" ), false !== strpos( $view, "'memory' === \$tc_photo['paused_why']" ) ), array( true, true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTWO REQUESTS CANNOT HOLD THE SAME WORK\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
$take    = new ReflectionMethod( 'TCGiant_Sync_Photo_Cleanup', 'take_lock' );
$release = new ReflectionMethod( 'TCGiant_Sync_Photo_Cleanup', 'release_lock' );
$take->setAccessible( true );
$release->setAccessible( true );
$held = new ReflectionProperty( 'TCGiant_Sync_Photo_Cleanup', 'held' );
$held->setAccessible( true );
$held->setValue( null, array() );

check( 'first request takes the hold', $take->invoke( null, 'queue', 60 ), true );
$held->setValue( null, array() ); // as seen from a second request
check( 'a second is refused while it lasts', $take->invoke( null, 'queue', 60 ), false );
q( "UPDATE wp_options SET option_value = '1|old' WHERE option_name = 'tcgiant_sync_photo_cleanup_lock_queue'" );
check( 'a hold past its time (a killed request) is taken over', $take->invoke( null, 'queue', 60 ), true );
$theirs = $GLOBALS['wpdb']->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'tcgiant_sync_photo_cleanup_lock_queue'" );
$held->setValue( null, array( 'queue' => '1|old' ) ); // the killed request, waking
$release->invoke( null, 'queue' );
check( 'a request whose hold was taken over cannot release the new one', $GLOBALS['wpdb']->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'tcgiant_sync_photo_cleanup_lock_queue'" ), $theirs );

// A database that will not take the write: nothing holds it, and it says why.
fresh_db();
boot();
$held->setValue( null, array() );
$wpdb->refuse = array( 'tcgiant_sync_photo_cleanup_lock_' );
check( 'the database refuses the hold: not taken', $take->invoke( null, 'queue', 60 ), false );
check( '  and the log says what the database said, in words', logged( 'Photo cleanup: could not start - the database would not record that it was working (The table \'wp_options\' is full)' ), true );
$take->invoke( null, 'queue', 60 );
run_queue();
check( '  once an hour, not on every try', times_logged( 'could not start - the database would not record' ), 1 );
check( '  the removal comes back to try again', (bool) wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ), true );
$wpdb->refuse = array();

// The options table has lost its unique key in a bad migration.
fresh_db( false );
$held->setValue( null, array() );
check( 'with no unique key on the table: the first request takes the hold', $take->invoke( null, 'queue', 60 ), true );
$held->setValue( null, array() );
check( '  a second, whose insert also goes in, sees it came second and is refused', $take->invoke( null, 'queue', 60 ), false );
check( '  and takes its own row back out', (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_options WHERE option_name = 'tcgiant_sync_photo_cleanup_lock_queue'" ), 1 );
q( "UPDATE wp_options SET option_value = '1|old' WHERE option_name = 'tcgiant_sync_photo_cleanup_lock_queue'" );
check( '  a lapsed hold is still taken over', $take->invoke( null, 'queue', 60 ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SCREEN'S REQUESTS\n" . str_repeat( '=', 124 ) . "\n";

function ajax( $method, array $post ) {
	$_POST = $post;
	try {
		call_user_func( array( 'TCGiant_Sync_Photo_Cleanup', $method ) );
	} catch ( Harness_Json $answer ) {
		return $answer->reply;
	} catch ( Throwable $e ) {
		return array( 'success' => null, 'escaped' => get_class( $e ) . ': ' . $e->getMessage() );
	}
	return null;
}

build_shop( 11 );
$DENIED = array( 'manage_options' );
$nope   = ajax( 'ajax_scan', array( 'restart' => 1 ) );
$DENIED = array();
check( 'someone who may not manage the site cannot start a check', array( $nope['success'], TCGiant_Sync_Photo_Cleanup::scan() ), array( false, array() ) );

$first = ajax( 'ajax_scan', array( 'restart' => 1 ) );
check( 'Check: the first step starts a check and answers with its progress', array( $first['success'], $first['data']['scan']['status'] ), array( true, 'found' ) );
check( '  and lets go of its hold', get_option( 'tcgiant_sync_photo_cleanup_lock_scan', 'none' ), 'none' );

$held->setValue( null, array() );
$take->invoke( null, 'scan', 60 ); // another tab is part way through a step
$held->setValue( null, array() );
$busy = ajax( 'ajax_scan', array( 'restart' => 1 ) );
check( 'Check pressed again while another tab is stepping: told it is busy, nothing reset', array( $busy['data']['busy'] ?? false, TCGiant_Sync_Photo_Cleanup::scan()['status'] ), array( true, 'found' ) );
$no = ajax( 'ajax_remove', array( 'scan_id' => $first['data']['scan']['id'] ) );
check( 'Remove pressed while a check is stepping: refused', array( $no['success'], TCGiant_Sync_Photo_Cleanup::scan()['status'] ), array( false, 'found' ) );
q( "DELETE FROM wp_options WHERE option_name = 'tcgiant_sync_photo_cleanup_lock_scan'" ); // that tab's step ends

$stale = ajax( 'ajax_remove', array( 'scan_id' => 'an-older-check' ) );
check( 'Remove from a page showing an older check: refused, and says why', array( $stale['success'], false !== strpos( $stale['data']['message'], 'no longer the latest' ) ), array( false, true ) );
$go = ajax( 'ajax_remove', array( 'scan_id' => $first['data']['scan']['id'] ) );
check( 'Remove: queues what the check found, schedules the background removal', array( $go['success'], $go['data']['scan']['status'], $go['data']['waiting'], (bool) wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) ), array( true, 'queued', $first['data']['scan']['found'], true ) );
check( '  and holds nothing afterwards', array( get_option( 'tcgiant_sync_photo_cleanup_lock_scan', 'none' ), get_option( 'tcgiant_sync_photo_cleanup_lock_queue', 'none' ) ), array( 'none', 'none' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT THE SQL ASKS THE DATABASE FOR\n" . str_repeat( '=', 124 ) . "\n";

// Read from the source, so a change that drops an index-friendly shape fails here.
$src = file_get_contents( $CLEANUP );
check( 'featured images are matched as strings (the plugin\'s meta_key/meta_value index)', 1 === preg_match( "/meta_key = '_thumbnail_id' AND meta_value IN \( \{\\\$marks\} \)/", $src ) && false !== strpos( $src, "array_map( 'strval', \$ids )" ), true );
check( 'the check walks the posts table by its key', 1 === preg_match( '/WHERE a\.ID > %d AND a\.ID <= %d.*ORDER BY a\.ID ASC\s+LIMIT %d/s', $src ), true );
check( 'galleries are read in pages by meta_id, never once per photo', 1 === preg_match( '/WHERE meta_key = %s AND meta_id > %d\s+ORDER BY meta_id ASC/', $src ), true );
preg_match_all( '/([\w.]+)\s+(?:NOT\s+)?LIKE\s+%s/', $src, $like );
$like = array_values( array_unique( $like[1] ) );
sort( $like );
check( 'LIKE only on content, excerpts, descriptions, meta values and settings - never a gallery, never FIND_IN_SET', array( $like, stripos( $src, 'FIND_IN_SET' ) ), array( array( 'description', 'm.meta_value', 'meta_value', 'option_name', 'option_value', 'post_content', 'post_excerpt' ), false ) );
preg_match_all( '/"(SELECT[^"]*)"/', $src, $sql );
$unbounded = array();
foreach ( $sql[1] as $one ) {
	if ( false !== strpos( $one, 'LIKE' ) || false !== strpos( $one, '{$mention}' ) ) {
		if ( false === strpos( $one, 'IN ( {$in} )' ) && 1 !== preg_match( '/_id <= %d/', $one ) ) {
			$unbounded[] = preg_replace( '/\s+/', ' ', substr( $one, 0, 60 ) );
		}
	}
}
check( '  every query with a LIKE looks through a bounded set of rows: those of a page of keys, or a window', array( count( $sql[1] ) > 20, $unbounded ), array( true, array() ) );
check( '  content and descriptions are walked by their key alone first', 1 === preg_match( '/SELECT ID FROM \{\$wpdb->posts\}\s+WHERE post_type IN \( \{\$kinds\} \)\s+AND ID > %d\s+ORDER BY ID ASC\s+LIMIT %d"/', $src ) && 1 === preg_match( "/SELECT meta_id FROM \{\\\$wpdb->postmeta\}\s+WHERE meta_key = '_variation_description'\s+AND meta_id > %d\s+ORDER BY meta_id ASC\s+LIMIT %d\"/", $src ) && 1 === preg_match( '/SELECT term_taxonomy_id FROM \{\$wpdb->term_taxonomy\}\s+WHERE term_taxonomy_id > %d\s+ORDER BY term_taxonomy_id ASC\s+LIMIT %d"/', $src ), true );
check( '  and term meta a window at a time', 1 === preg_match( '/FROM \{\$wpdb->termmeta\}\s+WHERE meta_id > %d AND meta_id <= %d\s+AND \( \{\$holds\} \)/', $src ), true );
check( 'other plugins\' post meta: never a photo\'s own record, nor an old revision\'s', 1 === preg_match( "/p\.post_type NOT IN \( 'attachment', 'revision' \)/", $src ), true );
check( 'settings: never a transient', substr_count( $src, "esc_like( '_transient_' )" ) + substr_count( $src, "esc_like( '_site_transient_' )" ), 2 );
check( 'post content is read by the kind of post, in pages by ID', 1 === preg_match( '/WHERE post_type IN \( \{\$kinds\} \)\s+AND ID > %d/', $src ), true );
$types = new ReflectionMethod( 'TCGiant_Sync_Photo_Cleanup', 'content_types' );
$types->setAccessible( true );
$read_types = $types->invoke( null );
sort( $read_types );
$want_types = array( 'post', 'page', 'product', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'nav_menu_item', 'shop_coupon', 'gp_elements', 'harness_layout', 'custom_css', 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout' );
sort( $want_types );
check( '  what the site shows, has a screen in the admin or opens in the editor - products, layouts, Additional CSS - and nothing else', $read_types, $want_types );
check( '  never photos, revisions, orders, variations, or another plugin\'s records with neither', array_values( array_intersect( $read_types, array( 'attachment', 'revision', 'shop_order', 'shop_order_refund', 'product_variation', 'postman_sent_mail', 'scheduled-action', 'customize_changeset' ) ) ), array() );
add_filter( 'tcgiant_sync_photo_content_types', function ( $types ) { $types[] = 'postman_sent_mail'; return $types; } );
check( '  and the filter can add to it', in_array( 'postman_sent_mail', $types->invoke( null ), true ), true );
remove_all( 'tcgiant_sync_photo_content_types' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA RUN THAT BEGINS WITH ITS REQUEST'S TIME ALREADY SPENT\n" . str_repeat( '=', 124 ) . "\n";

// WP-Cron runs every due event in one request, so the removal can begin after
// the eBay sync's queue run has used the request's budget. Its reading of the
// site is held to a run's budget of its own: begun then, it ran the request
// on for as long again - 70 s in, a 55 s reading, ended 125 s in, where every
// other job had stopped at 60.
fresh_db();
boot();
$P = product( 'publish' );
$A = photo( $P );
$B = photo( $P );
update_post_meta( $P, '_product_image_gallery', "{$A},{$B}" );
wp_delete_post( $P, true );
time_passes();
$CLOCK_OFFSET = TCGiant_Sync_Runtime::time_budget() + 10;
add_filter( 'tcgiant_sync_photo_content_types', function ( $types ) { $GLOBALS['CLOCK_OFFSET'] += 55; return $types; } );
$wpdb->log = array();
run_queue();
$ended = TCGiant_Sync_Runtime::elapsed();
check( 'begun past the request\'s budget, with photos due: reads nothing and removes nothing', array( count( array_filter( $wpdb->log, function ( $q ) { return false !== strpos( $q, 'LIKE' ); } ) ), exists( $A ), exists( $B ) ), array( 0, true, true ) );
$wpdb->log = null;
check( '  so the request ends where it was', (int) round( $ended ), (int) TCGiant_Sync_Runtime::time_budget() + 10 );
check( '  not a pause, and comes back soon, in another request', array( pause()['seconds'], wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) <= time() + TCGiant_Sync_Photo_Cleanup::NEXT_RUN_SECONDS ), array( 0, true ) );
$CLOCK_OFFSET = 0.0;
run_queue();
$CLOCK_OFFSET = 0.0;
check( '  where it removes them', array( exists( $A ), exists( $B ) ), array( false, false ) );
remove_all( 'tcgiant_sync_photo_content_types' );

// Getting the shop's approved photos ready reads the site as well.
fresh_db();
boot();
$L    = photo( 0 );
$scan = ( function () {
	TCGiant_Sync_Photo_Cleanup::start_scan();
	return TCGiant_Sync_Photo_Cleanup::scan_step( 9999 );
} )();
TCGiant_Sync_Photo_Cleanup::approve( $scan['id'] );
$CLOCK_OFFSET = TCGiant_Sync_Runtime::time_budget() + 10;
$wpdb->log    = array();
run_queue();
check( 'begun late with the shop\'s approved photos to get ready: reads nothing, readies nothing', array( $scan['found'], count( array_filter( $wpdb->log, function ( $q ) { return false !== strpos( $q, 'LIKE' ); } ) ), TCGiant_Sync_Photo_Cleanup::scan()['status'], marked( $L ) ), array( 1, 0, 'approved', false ) );
$wpdb->log    = null;
$CLOCK_OFFSET = 0.0;
run_queue();
check( '  a run in time gets them ready', array( TCGiant_Sync_Photo_Cleanup::scan()['status'], marked( $L ) ), array( 'queued', true ) );

// With nothing due, a late run reads nothing anyway, and plans the next one
// for when the queue says - not every half minute.
fresh_db();
boot();
$P = product( 'publish' );
$A = photo( $P );
set_post_thumbnail( $P, $A );
wp_delete_post( $P, true );
$CLOCK_OFFSET = TCGiant_Sync_Runtime::time_budget() + 10;
run_queue();
$CLOCK_OFFSET = 0.0;
check( 'begun late with nothing due yet: the next run is when the photo is due', wp_next_scheduled( TCGiant_Sync_Photo_Cleanup::CLEANUP_HOOK ) >= (int) get_post_meta( $A, TCGiant_Sync_Photo_Cleanup::MARK_KEY, true ), true );

echo "\nA COPY FROM ANOTHER EBAY SITE, MOVED TO THE TRASH BY STOCK REVIEW, THEN THE TRASH EMPTIED (3.27.5)\n" . str_repeat( '=', 124 ) . "\n";

// A Web Interpret shop: every eBay.com listing has copies on other eBay sites
// showing the same photos from the same eBay addresses, and the copies came in
// as products of their own before "Import listings from" was set. The image
// batch downloads each photo once and hands it to every product showing it,
// so the eBay.com product and its eBay.co.uk copy share downloads both ways;
// the copy also has one of its own. Stock Review moves the copy to the trash,
// and thirty days on WordPress empties it. The copy's own photo is for the
// removal; what the shop's own product shows is not.
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
function get_post_status( $id ) { $p = get_post( $id ); return $p ? $p->post_status : false; }

fresh_db();
boot();
installed_days_ago( 40 );
update_option( 'tcgiant_sync_ebay_settings', array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' ) );
$US = product( 'publish' );
$UK = product( 'publish' );
$WIP = product( 'publish' );
foreach ( array( $US => array( '110000000001', 'US' ), $UK => array( '220000000001', 'UK' ), $WIP => array( '220000000002', 'UK' ) ) as $id => $listing ) {
	update_post_meta( $id, '_ebay_item_id', $listing[0] );
	update_post_meta( $id, '_ebay_site', $listing[1] );
	update_post_meta( $id, '_ebay_listing_status', 'Active' );
}
// Another copy, whose first push from WooCommerce did not finish: eBay may
// hold a listing that only this product knows of (3.27.4).
update_post_meta( $WIP, '_ebay_export_status', 'failed' );

$A  = photo( $US );  // the eBay.com product's download, which the copy shows too
$S  = photo( $UK );  // the copy's download, which the eBay.com product shows too
$X  = photo( $UK );  // the copy's own: nothing else shows it
$W  = photo( $WIP );
$url_a = get_post_meta( $A, '_tcgiant_source_url', true );
$url_s = get_post_meta( $S, '_tcgiant_source_url', true );
check( 'the image batch hands each product the other\'s download of the same eBay photo', array( reused_for( $UK, $url_a ), reused_for( $US, $url_s ) ), array( $A, $S ) );
set_post_thumbnail( $US, $A );
update_post_meta( $US, '_product_image_gallery', (string) $S );
set_post_thumbnail( $UK, $S );
update_post_meta( $UK, '_product_image_gallery', "{$A},{$X}" );
set_post_thumbnail( $WIP, $W );
$paths = array();
foreach ( array( $A, $S, $X, $W ) as $id ) {
	$paths[ $id ] = paths_of( $id );
}

check( 'Stock Review lists the copy, not the shop\'s own product, nor the copy whose push did not finish', TCGiant_Sync_Sites::find_other_site_products(), array( $UK ) );
check( '  its button moves the copy to the trash, and leaves the other two', array( TCGiant_Sync_Sites::trash_other_site_product( $UK ), TCGiant_Sync_Sites::trash_other_site_product( $US ), TCGiant_Sync_Sites::trash_other_site_product( $WIP ) ), array( 'trashed', 'kept', 'kept' ) );
check( '  in the Trash, nothing is marked yet', array( get_post_status( $UK ), TCGiant_Sync_Photo_Cleanup::waiting() ), array( 'trash', 0 ) );

trashed_days_ago( 31 );
$DENIED = array( 'delete_posts' );
wp_scheduled_delete();
$DENIED = array();
do_action( 'shutdown' );
check( 'Trash emptied: the copy is gone, and every downloaded photo it showed or held is marked', array( exists( $UK ), marked( $A ), marked( $S ), marked( $X ), marked( $W ) ), array( false, true, true, true, false ) );

time_passes();
run_queue();
check( 'after the quarantine: the copy\'s own photo is removed, with its files', array( exists( $X ), files_intact( $paths[ $X ] ) ), array( false, false ) );
check( '  the eBay.com product\'s photos are kept, with their files: one attached to it, one it shows', array( exists( $A ), files_intact( $paths[ $A ] ), exists( $S ), files_intact( $paths[ $S ] ) ), array( true, true, true, true ) );
check( '  and it still shows them', array( get_post_thumbnail_id( $US ), get_post_meta( $US, '_product_image_gallery', true ) ), array( $A, (string) $S ) );
check( '  no longer waiting, so the image batch may hand them out again', array( marked( $A ), marked( $S ), reused_for( $US, $url_s ) ), array( false, false, $S ) );
check( '  the copy whose push did not finish keeps its photo', array( exists( $WIP ), exists( $W ), files_intact( $paths[ $W ] ) ), array( true, true, true ) );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
