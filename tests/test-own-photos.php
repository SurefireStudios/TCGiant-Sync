<?php
/**
 * Harness: a shop's own photos never come back from eBay as new ones (3.30.4).
 *
 * A coin shop imported its eBay listings, so every product's photos were the
 * plugin's downloads, each marked with the eBay address it came from
 * (_tcgiant_source_url). It then pushed with "Host listing images on eBay?"
 * on. Each push uploads the product's photos to eBay, which gives every
 * upload a picture number of its own, and the listing then shows those. The
 * next sync read the listing, found addresses it had never downloaded, and
 * downloaded every photo again as a new one: with "Overwrite Images" off the
 * copies were added to the gallery behind the photos they copy, the main
 * photo's copy among them; with it on they replaced the main photo and the
 * gallery. The copies had no eBay copy of their own, so the next push uploaded
 * them again, the sync brought those back, and so on, at a call a photo.
 *
 * And with Overwrite Images on, the product's main photo became whatever eBay
 * showed first. A new listing that went up without its main photo - left out
 * because it could not be put on eBay beside the others - showed a gallery
 * photo first, and the sync made that the product's main photo too. So did a
 * failed download of eBay's first picture: the next one took its place.
 *
 * 3.30.4 keeps every eBay picture number a local photo was put on eBay as
 * (_tcgiant_ebay_number), learns the numbers eBay gave a listing whose photos
 * a push sent from the website, by their places, at the first read after the
 * push alone and for that product alone, and matches what eBay sends back by
 * number: a photo of the product's own is used where it is, never fetched
 * again. A listing a push from the website made before 3.30.4, recording
 * nothing, is held rather than downloaded. Nothing a push sent moves a
 * product's main photo or its gallery's order, and the main photo of a
 * product with one is only ever eBay's first picture. Pushed products damaged
 * before are put right once, in batches, by showing each photo again in place
 * of its copy, its copy's numbers kept on it, so it stays put right; nothing
 * is deleted then. The copies, now unused, are Image Cleanup's to count and
 * remove (test-photo-cleanup.php).
 *
 * This loads the real TCGiant_Sync_Image_Localizer, TCGiant_Sync_Runtime and
 * (from 3.30.4) TCGiant_Sync_Own_Photos against a real SQL database (SQLite,
 * through the SQL the plugin sends MySQL), with WordPress played and eBay's
 * photo servers played: a download is recorded, and saved as a new
 * attachment the way media_handle_sideload() saves one. A push is played by
 * what it leaves on the photos; test-own-photos-push.php checks the real push
 * leaves exactly that. A run killed part way through the repair is a process
 * of its own (this file again, with --child=kill, or --child=killon:<product>
 * for one stopped on the same product each time) that really dies there.
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
	printf( "  %s  %-92s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 220 ), substr( str_replace( "\n", ' ', var_export( $want, true ) ), 0, 220 ) );
}

if ( ! class_exists( 'SQLite3' ) ) {
	echo "  (SQLite3 is not available in this PHP: skipped)\n\n  0 passed, 0 failed\n";
	exit( 0 );
}

$ROOT  = getenv( 'TCG_ROOT' ) ?: dirname( __DIR__ );
// test-relist-photos.php's shop (--child=relist-*) is a world of its own.
$WORLD = str_replace( '\\', '/', sys_get_temp_dir() ) . '/tcg-own-photos-' . substr( md5( __FILE__ ), 0, 8 ) . ( null !== $CHILD && 0 === strpos( $CHILD, 'relist-' ) ? '-relist' : '' );
@mkdir( $WORLD . '/uploads/2026/10', 0777, true );

define( 'ABSPATH', $WORLD . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// The image batch loads WordPress's media code; it is played here.
foreach ( array( 'media', 'file', 'image' ) as $f ) {
	if ( ! is_file( ABSPATH . "wp-admin/includes/$f.php" ) ) {
		@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
		file_put_contents( ABSPATH . "wp-admin/includes/$f.php", "<?php\n" );
	}
}

// ─── the database ─────────────────────────────────────────────────────────────

class Harness_Wpdb {
	public $posts      = 'wp_posts';
	public $postmeta   = 'wp_postmeta';
	public $options    = 'wp_options';
	public $termmeta   = 'wp_termmeta';
	public $last_error = '';
	/** Called with each query before it runs, when set. */
	public $on_query   = null;
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
	public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
	private function run( $sql ) {
		$this->last_error = '';
		if ( null !== $this->on_query ) {
			call_user_func( $this->on_query, $sql );
		}
		// MySQL's REGEXP, which SQLite leaves to the application.
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
	public function get_results( $sql, $output = 'OBJECT' ) {
		$res = $this->run( $sql );
		$out = array();
		if ( $res ) {
			while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
				$row   = array_map( function ( $v ) { return null === $v ? null : (string) $v; }, $row );
				$out[] = 'ARRAY_A' === $output ? $row : (object) $row;
			}
		}
		return $out;
	}
	public function get_row( $sql ) {
		$rows = $this->get_results( $sql );
		return $rows ? $rows[0] : null;
	}
	public function query( $sql ) {
		return false === $this->run( $sql ) ? false : $this->db->changes();
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
		return $this->query( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $cond ) );
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
	@unlink( $WORLD . '/activity.log' );
	$wpdb = new Harness_Wpdb( $WORLD . '/site.sqlite' );
	$wpdb->db->exec( "
		CREATE TABLE wp_posts ( ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_parent INTEGER NOT NULL DEFAULT 0, post_author INTEGER NOT NULL DEFAULT 0, post_title TEXT DEFAULT '', post_name TEXT DEFAULT '', post_date TEXT DEFAULT '2026-10-01 12:00:00' );
		CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT );
		CREATE INDEX pm_post ON wp_postmeta ( post_id );
		CREATE INDEX pm_key ON wp_postmeta ( meta_key, meta_value );
		CREATE TABLE wp_options ( option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT );
		CREATE TABLE wp_termmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER, meta_key TEXT, meta_value TEXT );
	" );
	// MySQL's REGEXP (SHOPS_OWN_SQL), which SQLite leaves to the application.
	$wpdb->db->createFunction( 'regexp', function ( $pattern, $value ) {
		return preg_match( '/' . str_replace( '/', '\\/', $pattern ) . '/', (string) $value ) ? 1 : 0;
	}, 2 );
	$GLOBALS['LOG']        = array();
	$GLOBALS['QUEUE']      = array();
	$GLOBALS['TRANSIENTS'] = array();
	$GLOBALS['DOWNLOADS']  = array();
	$GLOBALS['DELETED']    = array();
	$GLOBALS['REFUSE']     = array();
	$GLOBALS['NEXT_ID']    = 1000;
	$GLOBALS['LATER']      = array();
	$GLOBALS['CLEANED']    = array();

	$GLOBALS['TRANSIENTS_CLEARED'] = array();
}

function open_db() {
	global $wpdb, $WORLD;
	$wpdb = new Harness_Wpdb( $WORLD . '/site.sqlite' );
	$wpdb->db->createFunction( 'regexp', function ( $pattern, $value ) {
		return preg_match( '/' . str_replace( '/', '\\/', $pattern ) . '/', (string) $value ) ? 1 : 0;
	}, 2 );
}

function q( $sql ) { return $GLOBALS['wpdb']->query( $sql ); }
function esc( $v ) { return "'" . SQLite3::escapeString( (string) $v ) . "'"; }

// ─── WordPress, as far as the image batch needs it ────────────────────────────

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
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
	q( 'INSERT OR REPLACE INTO wp_options ( option_name, option_value, autoload ) VALUES ( ' . esc( $name ) . ', ' . esc( maybe_serialize( $value ) ) . ', ' . esc( false === $autoload ? 'no' : 'yes' ) . ' )' );
	return true;
}
function delete_option( $name ) { q( 'DELETE FROM wp_options WHERE option_name = ' . esc( $name ) ); return true; }
function get_transient( $name ) { return $GLOBALS['TRANSIENTS'][ $name ] ?? false; }
function set_transient( $name, $value, $expiration = 0 ) { $GLOBALS['TRANSIENTS'][ $name ] = $value; return true; }
function delete_transient( $name ) { unset( $GLOBALS['TRANSIENTS'][ $name ] ); return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }

/** WordPress's meta API, row for row: several rows of one key are several values. */
function get_post_meta( $id, $key = '', $single = false ) {
	if ( '' === $key ) {
		$out = array();
		foreach ( $GLOBALS['wpdb']->get_results( 'SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = ' . (int) $id . ' ORDER BY meta_id' ) as $row ) {
			$out[ $row->meta_key ][] = $row->meta_value;
		}
		return $out;
	}
	$rows = $GLOBALS['wpdb']->get_col( 'SELECT meta_value FROM wp_postmeta WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) . ' ORDER BY meta_id' );
	if ( $single ) {
		return $rows ? maybe_unserialize( $rows[0] ) : '';
	}
	return array_map( 'maybe_unserialize', $rows );
}
function add_post_meta( $id, $key, $value, $unique = false ) {
	if ( $unique && (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM wp_postmeta WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) ) ) {
		return false;
	}
	q( 'INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( ' . (int) $id . ', ' . esc( $key ) . ', ' . esc( maybe_serialize( $value ) ) . ' )' );
	return (int) $GLOBALS['wpdb']->db->lastInsertRowID();
}
function update_post_meta( $id, $key, $value, $prev_value = '' ) {
	$where = 'post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) . ( '' !== $prev_value ? ' AND meta_value = ' . esc( maybe_serialize( $prev_value ) ) : '' );
	if ( ! (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE {$where}" ) ) {
		if ( '' !== $prev_value ) {
			return false;
		}
		return (bool) add_post_meta( $id, $key, $value );
	}
	return q( 'UPDATE wp_postmeta SET meta_value = ' . esc( maybe_serialize( $value ) ) . " WHERE {$where}" ) > 0;
}
function delete_post_meta( $id, $key, $value = '' ) {
	q( 'DELETE FROM wp_postmeta WHERE post_id = ' . (int) $id . ' AND meta_key = ' . esc( $key ) . ( '' !== $value ? ' AND meta_value = ' . esc( maybe_serialize( $value ) ) : '' ) );
	return true;
}
function get_post_thumbnail_id( $post = null ) { return (int) get_post_meta( (int) $post, '_thumbnail_id', true ); }
function has_post_thumbnail( $post = null ) { return get_post_thumbnail_id( $post ) > 0; }
function set_post_thumbnail( $post, $thumb ) { return update_post_meta( (int) $post, '_thumbnail_id', (string) (int) $thumb ); }
function delete_post_thumbnail( $post ) { return delete_post_meta( (int) $post, '_thumbnail_id' ); }

function new_post( $type, $status = 'publish', $parent = 0, $title = '', $author = 0 ) {
	$id = ++$GLOBALS['NEXT_ID'];
	q( 'INSERT INTO wp_posts ( ID, post_type, post_status, post_parent, post_author, post_title ) VALUES ( ' . $id . ', ' . esc( $type ) . ', ' . esc( $status ) . ', ' . (int) $parent . ', ' . (int) $author . ', ' . esc( $title ) . ' )' );
	return $id;
}
function get_post( $id ) {
	$row = $GLOBALS['wpdb']->get_row( 'SELECT * FROM wp_posts WHERE ID = ' . (int) $id );
	return $row ? $row : null;
}
function get_post_field( $field, $id ) { $p = get_post( $id ); return $p ? (string) ( $p->$field ?? '' ) : ''; }
function get_post_status( $id ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
function wp_update_post( $data ) {
	$set = array();
	foreach ( $data as $k => $v ) {
		if ( 'ID' !== $k ) {
			$set[] = $k . ' = ' . esc( $v );
		}
	}
	q( 'UPDATE wp_posts SET ' . implode( ', ', $set ) . ' WHERE ID = ' . (int) $data['ID'] );
	return (int) $data['ID'];
}

/** get_posts(), as WP_Query answers the arguments the plugin gives it. Newest first. */
function get_posts( $args ) {
	$type   = $args['post_type'] ?? 'post';
	$where  = array( 'p.post_type = ' . esc( $type ) );
	$status = $args['post_status'] ?? ( 'attachment' === $type ? 'inherit' : 'publish' );
	if ( 'any' !== $status ) {
		$where[] = 'p.post_status IN ( ' . implode( ', ', array_map( 'esc', (array) $status ) ) . ' )';
	} else {
		$where[] = "p.post_status NOT IN ( 'trash', 'auto-draft' )";
	}
	if ( isset( $args['post_parent'] ) ) {
		$where[] = 'p.post_parent = ' . (int) $args['post_parent'];
	}
	if ( isset( $args['meta_key'] ) ) {
		$where[] = 'EXISTS ( SELECT 1 FROM wp_postmeta m WHERE m.post_id = p.ID AND m.meta_key = ' . esc( $args['meta_key'] ) . ( isset( $args['meta_value'] ) ? ' AND m.meta_value = ' . esc( $args['meta_value'] ) : '' ) . ' )';
	}
	foreach ( (array) ( $args['meta_query'] ?? array() ) as $clause ) {
		if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
			continue;
		}
		$compare = strtoupper( $clause['compare'] ?? '=' );
		if ( 'NOT EXISTS' === $compare ) {
			$where[] = 'NOT EXISTS ( SELECT 1 FROM wp_postmeta m WHERE m.post_id = p.ID AND m.meta_key = ' . esc( $clause['key'] ) . ' )';
		} elseif ( 'EXISTS' === $compare ) {
			$where[] = 'EXISTS ( SELECT 1 FROM wp_postmeta m WHERE m.post_id = p.ID AND m.meta_key = ' . esc( $clause['key'] ) . ' )';
		} elseif ( 'IN' === $compare ) {
			$where[] = 'EXISTS ( SELECT 1 FROM wp_postmeta m WHERE m.post_id = p.ID AND m.meta_key = ' . esc( $clause['key'] ) . ' AND m.meta_value IN ( ' . implode( ', ', array_map( 'esc', (array) $clause['value'] ) ) . ' ) )';
		} else {
			$where[] = 'EXISTS ( SELECT 1 FROM wp_postmeta m WHERE m.post_id = p.ID AND m.meta_key = ' . esc( $clause['key'] ) . ' AND m.meta_value = ' . esc( $clause['value'] ) . ' )';
		}
	}
	$n   = (int) ( $args['numberposts'] ?? 5 );
	$sql = 'SELECT p.ID FROM wp_posts p WHERE ' . implode( ' AND ', $where ) . ' ORDER BY p.post_date DESC, p.ID DESC' . ( $n > 0 ? ' LIMIT ' . $n : '' );
	$ids = array_map( 'intval', $GLOBALS['wpdb']->get_col( $sql ) );
	return 'ids' === ( $args['fields'] ?? '' ) ? $ids : array_map( 'get_post', $ids );
}

function wc_get_product( $id ) {
	$p = get_post( $id );
	return ( $p && in_array( $p->post_type, array( 'product', 'product_variation' ), true ) ) ? (object) array( 'id' => (int) $id ) : false;
}
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_upload_dir( $time = null ) { return array( 'path' => $GLOBALS['WORLD'] . '/uploads/2026/10', 'subdir' => '/2026/10', 'basedir' => $GLOBALS['WORLD'] . '/uploads', 'baseurl' => 'https://shop.example/wp-content/uploads', 'error' => false ); }
function remove_accents( $t ) { return $t; }

// Hooks.
$GLOBALS['HOOKS'] = array();
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['HOOKS'][ $hook ][] = array( $callback, $accepted_args ); return true; }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return add_filter( $hook, $callback, $priority, $accepted_args ); }
function remove_filter( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $i => $h ) {
		if ( $h[0] === $callback ) {
			unset( $GLOBALS['HOOKS'][ $hook ][ $i ] );
			return true;
		}
	}
	return false;
}
function has_filter( $hook, $callback = false ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $h ) {
		if ( false === $callback || $h[0] === $callback ) {
			return 10;
		}
	}
	return false;
}
function current_filter() { return ''; }
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

// WP-Cron, as far as the batch asks.
$GLOBALS['SCHEDULED'] = array();
function wp_next_scheduled( $hook ) { return $GLOBALS['SCHEDULED'][ $hook ] ?? false; }
function wp_schedule_single_event( $when, $hook ) { $GLOBALS['SCHEDULED'][ $hook ] = (int) $when; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['SCHEDULED'][ $hook ] ); return 0; }

// Action Scheduler: each action pending until run, running while it runs.
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	$GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'group' => $group, 'status' => 'pending' );
	return count( $GLOBALS['QUEUE'] );
}
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( array_merge( $GLOBALS['QUEUE'], $GLOBALS['LATER'] ?? array() ) as $a ) {
		if ( $a['hook'] === $hook && ( '' === $group || $a['group'] === $group ) && in_array( $a['status'], array( 'pending', 'running' ), true ) ) {
			return 'running' === $a['status'] ? true : time();
		}
	}
	return false;
}

// And its actions for later, held apart until a test lets their time come.
function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) {
	$GLOBALS['LATER'][] = array( 'hook' => $hook, 'group' => $group, 'status' => 'pending', 'when' => (int) $when );
	return count( $GLOBALS['LATER'] );
}
function later_comes() {
	foreach ( $GLOBALS['LATER'] as $a ) {
		$GLOBALS['QUEUE'][] = $a;
	}
	$GLOBALS['LATER'] = array();
}

// WordPress's and WooCommerce's caches of a product: recorded when cleared.
function clean_post_cache( $id ) { $GLOBALS['CLEANED'][] = (int) $id; }
function wc_delete_product_transients( $id = 0 ) { $GLOBALS['TRANSIENTS_CLEARED'][] = (int) $id; }

/**
 * eBay's photo servers, and WordPress saving a download.
 *
 * download_url() records each address asked for and answers with a file of
 * its own, or with what $REFUSE names for it; media_handle_sideload() makes
 * the attachment, attached to the product, with what it was asked to write on
 * it - as WordPress does, signed out (the batch runs from WP-Cron).
 */
function download_url( $url, $timeout = 300 ) {
	$GLOBALS['DOWNLOADS'][] = $url;
	foreach ( $GLOBALS['REFUSE'] as $needle => $error ) {
		if ( false !== strpos( $url, $needle ) ) {
			return $error;
		}
	}
	$tmp = $GLOBALS['WORLD'] . '/dl-' . count( $GLOBALS['DOWNLOADS'] ) . '.tmp';
	file_put_contents( $tmp, 'JPEG ' . $url );
	return $tmp;
}
function media_handle_sideload( $file, $post_id, $desc = null, $post_data = array() ) {
	$id = new_post( 'attachment', 'inherit', (int) $post_id, (string) ( $post_data['post_name'] ?? '' ), 0 );
	foreach ( $post_data['meta_input'] ?? array() as $key => $value ) {
		add_post_meta( $id, $key, $value );
	}
	$dir = $GLOBALS['WORLD'] . '/uploads/2026/10/';
	@rename( $file['tmp_name'], $dir . $file['name'] );
	add_post_meta( $id, '_wp_attached_file', '2026/10/' . $file['name'] );
	return $id;
}
function wp_insert_attachment( $args, $file = false, $parent = 0 ) {
	return new_post( 'attachment', 'inherit', (int) $parent, (string) ( $args['post_title'] ?? '' ), 0 );
}
/** Recorded, and done: the batch deletes its own eBay stand-ins once a product's photos are in. */
function wp_delete_attachment( $id, $force = false ) {
	$GLOBALS['DELETED'][] = (int) $id;
	q( 'DELETE FROM wp_postmeta WHERE post_id = ' . (int) $id );
	q( 'DELETE FROM wp_posts WHERE ID = ' . (int) $id );
	return true;
}
function wp_get_original_image_path( $id ) { return $GLOBALS['WORLD'] . '/uploads/' . get_post_meta( $id, '_wp_attached_file', true ); }
function get_attached_file( $id ) { return wp_get_original_image_path( $id ); }
function wp_update_image_subsizes( $id ) { return array( 'width' => 1600, 'file' => get_post_meta( $id, '_wp_attached_file', true ) ); }

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) {
		$GLOBALS['LOG'][] = array( $level, $message );
		@file_put_contents( $GLOBALS['WORLD'] . '/activity.log', $level . "\t" . $message . "\n", FILE_APPEND );
	}
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function write_now( $message, $level = 'error' ) { self::log( $message, $level ); }
}
class TCGiant_Sync_Cron {
	public static function request_dispatch() {}
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-image-localizer.php';
$HAS_OWN = class_exists( 'TCGiant_Sync_Own_Photos' );

// The time and memory budget is another harness's subject: here a batch is
// never cut short by it.
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
TCGiant_Sync_Runtime::$clock        = function () { return (float) $_SERVER['REQUEST_TIME_FLOAT']; };
TCGiant_Sync_Runtime::$memory_probe = function () { return 0; };

const NUMBER_META = '_tcgiant_ebay_number';
const PUSHED_META = '_tcgiant_pushed_photos';

// ─── the shop and eBay ────────────────────────────────────────────────────────

/** eBay's number for a picture, as eBay makes them. */
function num( $tag, $n ) { return sprintf( '%sAAOSw%05d', $tag, $n ); }
/** The address GetItem gives a picture, in the form the Trading API still sends, and in the current one. */
function ebay_z( $number ) { return 'https://i.ebayimg.com/00/s/MTYwMFgxMjAw/z/' . $number . '/$_57.JPG?set_id=8800005007'; }
function ebay_g( $number, $width = 1600 ) { return 'https://i.ebayimg.com/images/g/' . $number . '/s-l' . $width . '.jpg'; }
/** What UploadSiteHostedPictures answers an upload with (FullURL). */
function eps_full( $number ) { return 'https://i.ebayimg.com/00/s/MTYwMFgxMjAw/z/' . $number . '/$_1.JPG?set_id=8800005007'; }
/** A photo's address on the website. */
function site_url_of( $id ) { return 'https://shop.example/wp-content/uploads/' . get_post_meta( $id, '_wp_attached_file', true ); }

function settings( array $s ) { update_option( 'tcgiant_sync_ebay_settings', $s ); }

/** Run the image batch until nothing is due, time passing between runs. */
function run_localizer( $runs = 6 ) {
	for ( $i = 0; $i < $runs; $i++ ) {
		q( "UPDATE wp_postmeta SET meta_value = '0' WHERE meta_key = '_tcgiant_localize_retry_after'" );
		$due = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_tcgiant_images_localized' AND meta_value = '0'" );
		if ( $due < 1 ) {
			return;
		}
		TCGiant_Sync_Image_Localizer::instance()->process_batch();
	}
}

/**
 * A product the import made from an eBay listing: its photos downloaded by
 * the image batch, in eBay's order, the first its main photo.
 *
 * @return array [ product ID, photo IDs in order ]
 */
function imported( $title, $tag, $count ) {
	$pid  = new_post( 'product', 'publish', 0, $title );
	$urls = array();
	for ( $i = 1; $i <= $count; $i++ ) {
		$urls[] = ebay_z( num( $tag, $i ) );
	}
	update_post_meta( $pid, '_ebay_item_id', '1100' . $pid );
	sync( $pid, $urls );
	return array( $pid, shown( $pid ) );
}

/** The photos a product shows: its main photo, then its gallery. */
function shown( $pid ) {
	$gallery = (string) get_post_meta( $pid, '_product_image_gallery', true );
	return array_values( array_filter( array_merge( array( get_post_thumbnail_id( $pid ) ), array_map( 'intval', '' === $gallery ? array() : explode( ',', $gallery ) ) ) ) );
}
function gallery_of( $pid ) { return (string) get_post_meta( $pid, '_product_image_gallery', true ); }
function attachments() { return (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'attachment'" ); }

/**
 * A sync of the listing: what the import does with a listing eBay sends -
 * eBay's values recorded (_ebay_live, without the query strings, as
 * TCGiant_Sync_Ebay_Live::from_item() keeps them), then its photos handed to
 * the image batch - and the batch run.
 */
function sync( $pid, array $urls ) {
	$pictures = array_map( function ( $u ) { return preg_replace( '/\?.*$/', '', $u ); }, $urls );
	update_post_meta( $pid, '_ebay_live', array( 'item_id' => (string) get_post_meta( $pid, '_ebay_item_id', true ), 'pictures' => $pictures ) );
	TCGiant_Sync_Image_Localizer::set_external_images( $pid, $urls );
	run_localizer();
}

/**
 * A push with "Host listing images on eBay?" on, as the real push leaves it
 * (test-own-photos-push.php): each photo the product shows uploaded and its
 * copy's address cached on it (_tcgiant_eps_url, 3.27.7), the copy's number
 * kept on it (_tcgiant_ebay_number, 3.30.4), and what was sent recorded on the
 * product (_tcgiant_pushed_photos, 3.30.4). $only names the photos sent, when
 * not all (a photo left out); $record false leaves off what only 3.30.4 keeps.
 *
 * @return string[] The addresses GetItem gives the listing's pictures afterwards.
 */
function push_eps( $pid, $tag, $only = null, $record = true, $item = null ) {
	$ids   = null === $only ? shown( $pid ) : $only;
	$sent  = array();
	$after = array();
	foreach ( $ids as $n => $id ) {
		$number = num( $tag, $id );
		// 3.30.4's push keeps the number of the copy cached before, as it
		// uploads the photo again (TCGiant_Sync_Exporter::eps_picture_url()).
		$cached = (string) get_post_meta( $id, '_tcgiant_eps_url', true );
		if ( $record && $GLOBALS['HAS_OWN'] && '' !== $cached ) {
			TCGiant_Sync_Own_Photos::remember( $id, $cached );
		}
		update_post_meta( $id, '_tcgiant_eps_url', eps_full( $number ) );
		update_post_meta( $id, '_tcgiant_eps_key', '0' );
		update_post_meta( $id, '_tcgiant_eps_time', time() );
		if ( $record ) {
			add_post_meta( $id, NUMBER_META, $number );
		}
		$sent[]  = eps_full( $number );
		$after[] = ebay_g( $number );
	}
	if ( null !== $item ) {
		update_post_meta( $pid, '_ebay_item_id', $item );
	}
	if ( $record ) {
		record( $pid, array_values( $ids ), $sent );
	}
	update_post_meta( $pid, '_ebay_export_last_pushed', pushed_on() );
	return $after;
}

/** When a push was made, as _ebay_export_last_pushed keeps it: $days ago (yesterday by default). */
function pushed_on( $days = 1 ) { return gmdate( 'Y-m-d H:i:s', time() - (int) round( $days * 86400 ) ); }

/** What a 3.30.4 push records once eBay has taken the item (TCGiant_Sync_Own_Photos::record_push()). */
function record( $pid, array $ids, array $sent ) {
	if ( $GLOBALS['HAS_OWN'] ) {
		TCGiant_Sync_Own_Photos::record_push( $pid, (string) get_post_meta( $pid, '_ebay_item_id', true ), array( 'ids' => $ids, 'urls' => $sent, 'shown' => shown( $pid ) ) );
		return;
	}
	update_post_meta( $pid, PUSHED_META, array( 'item' => (string) get_post_meta( $pid, '_ebay_item_id', true ), 'ids' => $ids, 'urls' => $sent, 'shown' => shown( $pid ), 'time' => time() ) );
}

/** The numbers a photo is known by, learnt by place or not. */
function numbers_of( $id ) { return $GLOBALS['HAS_OWN'] ? TCGiant_Sync_Own_Photos::numbers_of( $id ) : array(); }

/**
 * A push with the setting off: the photos' own addresses sent, which eBay
 * copies to its servers under numbers of its own - GetItem gives only those.
 */
function push_site( $pid, $tag ) {
	$ids  = shown( $pid );
	$sent = array_map( 'site_url_of', $ids );
	record( $pid, $ids, $sent );
	update_post_meta( $pid, '_ebay_export_last_pushed', pushed_on() );
	$after = array();
	foreach ( $ids as $id ) {
		$after[] = ebay_z( num( $tag, $id ) );
	}
	return $after;
}

/** Every attachment row and every meta row on an attachment, to tell that none changed. */
function attachment_state() {
	return array(
		$GLOBALS['wpdb']->get_results( "SELECT * FROM wp_posts WHERE post_type = 'attachment' ORDER BY ID", 'ARRAY_A' ),
		$GLOBALS['wpdb']->get_results( "SELECT m.* FROM wp_postmeta m JOIN wp_posts p ON p.ID = m.post_id WHERE p.post_type = 'attachment' ORDER BY m.meta_id", 'ARRAY_A' ),
	);
}
function files_on_disk() { return count( (array) glob( $GLOBALS['WORLD'] . '/uploads/2026/10/*' ) ); }
function logged( $needle, $level = null ) {
	$out = array();
	foreach ( $GLOBALS['LOG'] as $row ) {
		if ( false !== strpos( $row[1], $needle ) && ( null === $level || $level === $row[0] ) ) {
			$out[] = $row[1];
		}
	}
	return $out;
}

/** Run what Action Scheduler holds, through its hooks, until nothing is pending. */
function run_queue( $limit = 200 ) {
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
		do_action( $GLOBALS['QUEUE'][ $i ]['hook'] );
		$GLOBALS['QUEUE'][ $i ]['status'] = 'done';
		$runs++;
	}
	return $runs;
}

/** An admin page: what admin_init runs, with any throttle passed. */
function admin_page() {
	$GLOBALS['TRANSIENTS'] = array();
	do_action( 'admin_init' );
}

/** The once-only repair, start to end, when this version has it. */
function repair() {
	admin_page();
	run_queue();
}

// ─── test-relist-photos.php's shop, in this world (3.32.1) ────────────────────

/**
 * The shop's half of test-relist-photos.php's loop (--child=relist-shop:<in>):
 * a product imported from its eBay listing, the import and the image batch
 * for real; the coin sold, the listing ended, and the shop's photos of a new
 * coin uploaded to the product and made its main photo and gallery, the sold
 * coin's still attached to it. Printed as JSON for the relist: the product,
 * the photos, the listing's pictures, and every meta row of the product and
 * its photos. The database stays, for relist_import().
 *
 * @param array $in settings, tag, old (how many pictures the listing had), new (how many new photos),
 *                  pushed (the old listing made since by a push with eBay hosting).
 */
function relist_shop( array $in ) {
	fresh_db();
	foreach ( (array) glob( $GLOBALS['WORLD'] . '/uploads/2026/10/*' ) as $file ) {
		@unlink( $file );
	}
	TCGiant_Sync_Image_Localizer::instance();
	settings( (array) ( $in['settings'] ?? array() ) );
	$tag = (string) ( $in['tag'] ?? 'sold' );
	list( $pid, $old ) = imported( '1921 Morgan Dollar', $tag, (int) ( $in['old'] ?? 2 ) );
	$listing = array();
	for ( $i = 1; $i <= (int) ( $in['old'] ?? 2 ); $i++ ) {
		$listing[] = ebay_z( num( $tag, $i ) );
	}
	// Pushed since with eBay hosting, as a 3.30.4 push leaves it: the
	// listing's pictures are the old photos' uploads.
	if ( ! empty( $in['pushed'] ) ) {
		foreach ( $old as $n => $id ) {
			add_post_meta( $id, NUMBER_META, num( $tag, $n + 1 ) );
		}
		record( $pid, $old, array_map( function ( $url ) { return preg_replace( '#\$_57\.JPG#', '$_1.JPG', $url ); }, $listing ) );
		update_post_meta( $pid, '_ebay_export_last_pushed', pushed_on() );
	}
	$new = array();
	for ( $i = 1; $i <= (int) ( $in['new'] ?? 2 ); $i++ ) {
		$id = new_post( 'attachment', 'inherit', $pid, 'New coin ' . $i );
		add_post_meta( $id, '_wp_attached_file', '2026/10/new-coin-' . $id . '.jpg' );
		file_put_contents( $GLOBALS['WORLD'] . '/uploads/2026/10/new-coin-' . $id . '.jpg', 'JPEG new ' . $id );
		$new[] = $id;
	}
	set_post_thumbnail( $pid, $new[0] );
	update_post_meta( $pid, '_product_image_gallery', implode( ',', array_slice( $new, 1 ) ) );
	update_post_meta( $pid, '_ebay_listing_status', 'Ended' );
	update_post_meta( $pid, '_ebay_listing_type', 'FixedPriceItem' );

	$meta = array();
	foreach ( array_merge( array( $pid ), $old, $new ) as $id ) {
		$meta[ $id ] = array_map( function ( $rows ) {
			return array_map( 'maybe_unserialize', $rows );
		}, get_post_meta( $id ) );
	}
	echo json_encode( array( 'product' => $pid, 'old' => $old, 'new' => $new, 'listing' => $listing, 'attachments' => attachments(), 'downloads' => count( $GLOBALS['DOWNLOADS'] ), 'meta' => $meta ) );
}

/**
 * The import's half (--child=relist-import:<in>): relist_shop()'s shop, with
 * what the relist wrote applied to it (delta: per post, 'set' and 'delete'
 * as update_post_meta() and delete_post_meta(), 'add' as a row each, as the
 * numbers are kept), read by the import once for each answer eBay gives the
 * new listing, the image batch run after each. Printed as JSON: what each
 * read left.
 *
 * @param array $in settings, product, delta, reads (each a list of addresses).
 */
function relist_import( array $in ) {
	open_db();
	$GLOBALS['NEXT_ID'] = max( 1000, (int) $GLOBALS['wpdb']->get_var( 'SELECT MAX(ID) FROM wp_posts' ) );
	TCGiant_Sync_Image_Localizer::instance();
	settings( (array) ( $in['settings'] ?? array() ) );
	$pid = (int) $in['product'];
	foreach ( (array) ( $in['delta'] ?? array() ) as $id => $d ) {
		foreach ( (array) ( $d['set'] ?? array() ) as $key => $value ) {
			update_post_meta( (int) $id, $key, $value );
		}
		foreach ( (array) ( $d['add'] ?? array() ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				if ( ! in_array( (string) $value, array_map( 'strval', get_post_meta( (int) $id, $key ) ), true ) ) {
					add_post_meta( (int) $id, $key, $value );
				}
			}
		}
		foreach ( (array) ( $d['delete'] ?? array() ) as $key ) {
			delete_post_meta( (int) $id, $key );
		}
		// The import following a relist eBay made whose answer was lost
		// (3.32.1 fix round 1): what TCGiant_Sync_Inventory::follow_relist()
		// writes of it here - test-relist-adopt.php runs the real one. It
		// moves no record of photos sent (fix round 2).
		if ( ! empty( $d['follow'] ) ) {
			list( $old_item, $new_item ) = $d['follow'];
			update_post_meta( (int) $id, '_ebay_item_id', $new_item );
			update_post_meta( (int) $id, '_ebay_previous_item_id', $old_item );
			update_post_meta( (int) $id, '_ebay_listing_status', 'Active' );
		}
	}

	$out = array( 'before' => attachments(), 'files' => files_on_disk(), 'reads' => array() );
	foreach ( (array) $in['reads'] as $urls ) {
		$GLOBALS['DOWNLOADS'] = array();
		sync( $pid, (array) $urls );
		$numbers = array();
		foreach ( shown( $pid ) as $id ) {
			$numbers[ $id ] = get_post_meta( $id, NUMBER_META );
		}
		$record          = get_post_meta( $pid, PUSHED_META, true );
		$out['reads'][] = array(
			'downloads'   => $GLOBALS['DOWNLOADS'],
			'attachments' => attachments(),
			'files'       => files_on_disk(),
			'shown'       => shown( $pid ),
			'held'        => in_array( $pid, held(), true ),
			'numbers'     => $numbers,
			'read'        => is_array( $record ) && ! empty( $record['read'] ),
			'learnt'      => is_array( $record ) ? (int) ( $record['learnt'] ?? 0 ) : 0,
		);
	}
	echo json_encode( $out );
}

if ( null !== $CHILD && 0 === strpos( $CHILD, 'relist-' ) ) {
	foreach ( array( 'LOG', 'QUEUE', 'TRANSIENTS', 'DOWNLOADS', 'DELETED', 'REFUSE', 'LATER', 'CLEANED', 'TRANSIENTS_CLEARED' ) as $tcg_global ) {
		$GLOBALS[ $tcg_global ] = array();
	}
	$GLOBALS['NEXT_ID'] = 1000;
	$tcg_in             = json_decode( (string) file_get_contents( substr( $CHILD, strpos( $CHILD, ':' ) + 1 ) ), true );
	if ( 0 === strpos( $CHILD, 'relist-shop:' ) ) {
		relist_shop( (array) $tcg_in );
	} else {
		relist_import( (array) $tcg_in );
	}
	exit( 0 );
}

// ─── in a request of its own ──────────────────────────────────────────────────

if ( null !== $CHILD ) {
	open_db();
	$GLOBALS['LOG']        = array();
	$GLOBALS['QUEUE']      = json_decode( (string) file_get_contents( $WORLD . '/queue.json' ), true );
	$GLOBALS['TRANSIENTS'] = array();
	TCGiant_Sync_Image_Localizer::instance();
	if ( 'kill' === $CHILD ) {
		// Dies outright at the third product list written: the batch it is
		// in is part done.
		$writes = 0;
		$GLOBALS['wpdb']->on_query = function ( $sql ) use ( &$writes ) {
			if ( 0 === strpos( ltrim( $sql ), 'UPDATE wp_postmeta' ) ) {
				if ( ++$writes >= 3 ) {
					file_put_contents( $GLOBALS['WORLD'] . '/queue.json', json_encode( $GLOBALS['QUEUE'] ) );
					exit( 9 );
				}
			}
		};
		run_queue();
	}
	if ( 0 === strpos( $CHILD, 'killon:' ) ) {
		// Dies as the pass works on this product, each time: just after
		// it says it has started on it.
		$target = substr( $CHILD, 7 );
		$armed  = false;
		$GLOBALS['wpdb']->on_query = function ( $sql ) use ( $target, &$armed ) {
			if ( $armed ) {
				file_put_contents( $GLOBALS['WORLD'] . '/queue.json', json_encode( $GLOBALS['QUEUE'] ) );
				exit( 9 );
			}
			if ( false !== strpos( $sql, "'tcgiant_sync_own_photos_heal_open', '" . $target . "'" ) ) {
				$armed = true;
			}
		};
		run_queue();
	}
	exit( 0 );
}

// ─────────────────────────────────────────────────────────────────────────────
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );

echo "\nA PUSH WITH \"HOST LISTING IMAGES ON EBAY?\" ON, THEN A SYNC\n" . str_repeat( '=', 124 ) . "\n";

list( $A, $a ) = imported( '1921 Morgan Dollar MS63', 'im', 3 );
list( $m, $g1, $g2 ) = $a;
check( 'the import downloaded the listing\'s three photos, the first its main photo', array( count( $GLOBALS['DOWNLOADS'] ), get_post_thumbnail_id( $A ), gallery_of( $A ) ), array( 3, $m, "{$g1},{$g2}" ) );
$GLOBALS['DOWNLOADS'] = array();
$before = attachments();

$after_push = push_eps( $A, 'up' );
sync( $A, $after_push );
check( 'pushed with eBay hosting, then synced (Overwrite Images off): nothing is downloaded', $GLOBALS['DOWNLOADS'], array() );
check( '  no new attachment', attachments(), $before );
check( '  the same main photo, the same gallery, in its order', array( get_post_thumbnail_id( $A ), gallery_of( $A ) ), array( $m, "{$g1},{$g2}" ) );
sync( $A, $after_push );
check( '  synced again: still nothing', array( $GLOBALS['DOWNLOADS'], attachments(), shown( $A ) ), array( array(), $before, $a ) );

// eBay's address in another size form, with a query string: the same picture.
sync( $A, array( ebay_z( num( 'up', $m ) ) . '&x=1', ebay_g( num( 'up', $g1 ), 500 ), ebay_g( num( 'up', $g2 ), 225 ) . '?t=9' ) );
check( '  the size form ($_57, s-l500, s-l225) and query strings do not matter', array( $GLOBALS['DOWNLOADS'], shown( $A ) ), array( array(), $a ) );

// The same push uploaded again after the 20-day cache: new numbers. The old
// ones are still the photos'.
$second = push_eps( $A, 'up2' );
sync( $A, $second );
check( 'pushed again past the 20-day cache (new numbers), synced: nothing downloaded', array( $GLOBALS['DOWNLOADS'], attachments(), shown( $A ) ), array( array(), $before, $a ) );
check( '  each photo keeps both numbers it was put on eBay as', get_post_meta( $m, NUMBER_META ), array( num( 'up', $m ), num( 'up2', $m ) ) );
sync( $A, $after_push );
check( '  and a listing still showing the first numbers is matched too', array( $GLOBALS['DOWNLOADS'], shown( $A ) ), array( array(), $a ) );

echo "\nOVERWRITE IMAGES ON\n" . str_repeat( '=', 124 ) . "\n";

settings( array( 'overwrite_images' => '1' ) );
list( $B, $b ) = imported( '1881-S Morgan Dollar', 'ib', 3 );
$GLOBALS['DOWNLOADS'] = array();
$before = attachments();
sync( $B, push_eps( $B, 'ub' ) );
check( 'pushed with eBay hosting, synced, Overwrite Images on: nothing downloaded', array( $GLOBALS['DOWNLOADS'], attachments() ), array( array(), $before ) );
check( '  the main photo and the gallery are the shop\'s own, in order', array( get_post_thumbnail_id( $B ), gallery_of( $B ) ), array( $b[0], $b[1] . ',' . $b[2] ) );

// A new listing that went up without its main photo: eBay shows a gallery photo first.
list( $C, $c ) = imported( '1878 Morgan 8TF', 'ic', 3 );
$GLOBALS['DOWNLOADS'] = array();
$before = attachments();
sync( $C, push_eps( $C, 'uc', array( $c[1], $c[2] ) ) );
check( 'a listing that went up without its main photo, synced with Overwrite Images on: nothing downloaded', array( $GLOBALS['DOWNLOADS'], attachments() ), array( array(), $before ) );
check( '  the main photo stays the main photo: eBay\'s first is a gallery photo of the shop\'s own', get_post_thumbnail_id( $C ), $c[0] );
check( '  and the gallery keeps its order, with no duplicate', gallery_of( $C ), $c[1] . ',' . $c[2] );

// Photos new on eBay still come in, behind the shop's own.
$pushed_c = push_eps( $C, 'uc2' );
$new_one  = ebay_z( num( 'seller', 1 ) );
sync( $C, array_merge( $pushed_c, array( $new_one ) ) );
check( 'a photo added on eBay (Seller Hub) to a pushed listing, Overwrite on: that one alone is downloaded', $GLOBALS['DOWNLOADS'], array( $new_one ) );
$new_c = (int) $GLOBALS['wpdb']->get_var( 'SELECT MAX(ID) FROM wp_posts WHERE post_type = \'attachment\'' );
check( '  added to the end of the gallery; the main photo and the others as they were', array( get_post_thumbnail_id( $C ), gallery_of( $C ) ), array( $c[0], $c[1] . ',' . $c[2] . ',' . $new_c ) );

settings( array() );
$GLOBALS['DOWNLOADS'] = array();
$new_two = ebay_z( num( 'seller', 2 ) );
sync( $A, array_merge( $after_push, array( $new_two ) ) );
$new_a = (int) $GLOBALS['wpdb']->get_var( 'SELECT MAX(ID) FROM wp_posts WHERE post_type = \'attachment\'' );
check( '  the same with Overwrite Images off', array( $GLOBALS['DOWNLOADS'], get_post_thumbnail_id( $A ), gallery_of( $A ) ), array( array( $new_two ), $m, "{$g1},{$g2},{$new_a}" ) );

// A product never pushed: Overwrite Images is eBay's set and order, as before,
// with nothing fetched it already has.
settings( array( 'overwrite_images' => '1' ) );
list( $D, $d ) = imported( '1904-O Morgan', 'id', 3 );
$GLOBALS['DOWNLOADS'] = array();
sync( $D, array( ebay_z( num( 'id', 2 ) ), ebay_z( num( 'id', 1 ) ), ebay_z( num( 'id', 3 ) ) ) );
check( 'never pushed, its photos reordered on eBay alone: no new set of photos, left as it was (as before)', array( $GLOBALS['DOWNLOADS'], get_post_thumbnail_id( $D ), gallery_of( $D ) ), array( array(), $d[0], $d[1] . ',' . $d[2] ) );
$fresh = ebay_z( num( 'seller', 3 ) );
sync( $D, array( ebay_z( num( 'id', 2 ) ), ebay_z( num( 'id', 1 ) ), ebay_z( num( 'id', 3 ) ), $fresh ) );
$new_d = (int) $GLOBALS['wpdb']->get_var( 'SELECT MAX(ID) FROM wp_posts WHERE post_type = \'attachment\'' );
check( '  reordered with a photo new on eBay, Overwrite on: eBay\'s set and order, only the new one fetched (as before)', array( $GLOBALS['DOWNLOADS'], get_post_thumbnail_id( $D ), gallery_of( $D ) ), array( array( $fresh ), $d[1], $d[0] . ',' . $d[2] . ',' . $new_d ) );

// eBay's first picture cannot be fetched: the next one does not take its place.
list( $E, $e ) = imported( '1896 Morgan', 'ie', 2 );
$GLOBALS['DOWNLOADS'] = array();
$GLOBALS['REFUSE']    = array( num( 'new', 1 ) => new WP_Error( 'http_404', 'Forbidden', array( 'code' => 403 ) ) );
sync( $E, array( ebay_z( num( 'new', 1 ) ), ebay_z( num( 'new', 2 ) ) ) );
check( 'never pushed, Overwrite on, eBay\'s new first picture refused (403): the second does not become the main photo', get_post_thumbnail_id( $E ), $e[0] );
$GLOBALS['REFUSE'] = array();
settings( array() );

echo "\nAFTER THE UPGRADE: PHOTOS PUSHED BEFORE 3.30.4\n" . str_repeat( '=', 124 ) . "\n";

// 3.30.3's push kept only the copy's address in the 20-day cache.
list( $F, $f ) = imported( '1889-CC Morgan', 'if', 3 );
$pushed_f = push_eps( $F, 'uf', null, false );
$GLOBALS['DOWNLOADS'] = array();
$before = attachments();
repair();
sync( $F, $pushed_f );
check( 'pushed by 3.30.3 (only _tcgiant_eps_url), synced after the upgrade: nothing downloaded', array( $GLOBALS['DOWNLOADS'], attachments(), shown( $F ) ), array( array(), $before, $f ) );
check( '  the cached copy\'s number is now the photo\'s', $HAS_OWN ? get_post_meta( $f[0], NUMBER_META ) : null, array( num( 'uf', $f[0] ) ) );

echo "\nA PUSH WITH THE SETTING OFF: EBAY'S OWN COPIES, UNDER NUMBERS OF ITS OWN\n" . str_repeat( '=', 124 ) . "\n";

list( $G, $gp ) = imported( '1884-O Morgan', 'ig', 3 );
$GLOBALS['DOWNLOADS'] = array();
$before = attachments();
$copied = push_site( $G, 'cp' );
sync( $G, $copied );
check( 'pushed from the website (eBay copies each picture), synced: nothing downloaded', array( $GLOBALS['DOWNLOADS'], attachments(), shown( $G ) ), array( array(), $before, $gp ) );
check( '  eBay\'s numbers learnt by place, from what the push sent - marked as learnt so', array( get_post_meta( $gp[0], NUMBER_META ), get_post_meta( $gp[2], NUMBER_META ) ), array( array( 'place:' . num( 'cp', $gp[0] ) ), array( 'place:' . num( 'cp', $gp[2] ) ) ) );
check( '  by the first read after the push, which spends the record', ! empty( get_post_meta( $G, PUSHED_META, true )['read'] ), true );
sync( $G, array_merge( array_slice( $copied, 0, 2 ), array( ebay_g( num( 'cp', $gp[2] ), 300 ) ) ) );
check( '  later syncs match by number', array( $GLOBALS['DOWNLOADS'], shown( $G ) ), array( array(), $gp ) );
// What is learnt by place is the product's alone.
$T0 = new_post( 'product', 'publish', 0, '1884-O Morgan, sold similar' );
update_post_meta( $T0, '_ebay_item_id', '1100' . $T0 );
sync( $T0, array( $copied[0] ) );
check( '  another product\'s listing showing one of those pictures downloads it: never handed this product\'s photo', array( $GLOBALS['DOWNLOADS'], get_post_thumbnail_id( $T0 ) === $gp[0] ), array( array( $copied[0] ), false ) );
$GLOBALS['DOWNLOADS'] = array();

// Pushed from the website again; eBay's copies are new again, and one is added on eBay.
list( $H, $hp ) = imported( '1885 Morgan', 'ih', 2 );
$GLOBALS['DOWNLOADS'] = array();
$copied = push_site( $H, 'ch' );
sync( $H, array_merge( $copied, array( ebay_z( num( 'seller', 9 ) ) ) ) );
check( '  eBay showing one more than the push sent: not matched by place - and, its pictures all unknown, held: nothing downloaded', array( count( $GLOBALS['DOWNLOADS'] ), in_array( $H, held(), true ) ), array( 0, true ) );

// The product's photos changed after the push: not matched by place either.
list( $I, $ip ) = imported( '1886 Morgan', 'ii', 2 );
$copied = push_site( $I, 'ci' );
set_post_thumbnail( $I, $ip[1] );
update_post_meta( $I, '_product_image_gallery', (string) $ip[0] );
$GLOBALS['DOWNLOADS'] = array();
sync( $I, $copied );
check( '  its photos swapped round since the push: not matched by place - and, its pictures all unknown, held: nothing downloaded', array( count( $GLOBALS['DOWNLOADS'] ), numbers_of( $ip[0] ), in_array( $I, held(), true ) ), array( 0, array(), true ) );

echo "\nWHAT IS, AND IS NOT, ONE OF THE SHOP'S OWN PHOTOS\n" . str_repeat( '=', 124 ) . "\n";

settings( array() );

// Pushed by 3.30.3, synced before the upgrade's pass has run.
list( $T1, $t1 ) = imported( 'Liberty Nickel 1883', 't1', 2 );
$after_t1 = push_eps( $T1, 'v1', null, false );
$GLOBALS['DOWNLOADS'] = array();
sync( $T1, $after_t1 );
check( 'pushed by 3.30.3, synced before the upgrade\'s pass has run: known by its cached copy, nothing downloaded', array( $GLOBALS['DOWNLOADS'], shown( $T1 ) ), array( array(), $t1 ) );

// Never pushed: eBay gives the same pictures in another address form.
list( $T2, $t2 ) = imported( 'Indian Head Cent 1909', 't2', 2 );
$GLOBALS['DOWNLOADS'] = array();
sync( $T2, array( ebay_g( num( 't2', 1 ) ), ebay_g( num( 't2', 2 ), 500 ) ) );
check( 'never pushed, eBay gives its pictures in another address form: the photos it has, nothing downloaded', array( $GLOBALS['DOWNLOADS'], shown( $T2 ) ), array( array(), $t2 ) );

// A picture on another server whose address looks like eBay's.
$T3 = new_post( 'product', 'publish', 0, 'Buffalo Nickel 1913' );
update_post_meta( $T3, '_ebay_item_id', '1100' . $T3 );
$lookalike = 'https://cdn.seller.example/images/g/' . num( 'up', $m ) . '/s-l1600.jpg';
$GLOBALS['DOWNLOADS'] = array();
sync( $T3, array( $lookalike ) );
check( 'a picture on another server whose address merely looks like eBay\'s, with a number this site put on eBay: downloaded', $GLOBALS['DOWNLOADS'], array( $lookalike ) );

// Another product's listing showing a photo this site put on eBay.
$T4 = new_post( 'product', 'publish', 0, 'Walking Liberty 1916' );
update_post_meta( $T4, '_ebay_item_id', '1100' . $T4 );
$GLOBALS['DOWNLOADS'] = array();
sync( $T4, array( ebay_g( num( 'up', $g1 ) ) ) );
check( 'another product\'s listing showing a photo this site put on eBay: that photo, in place of its stand-in, not downloaded', array( $GLOBALS['DOWNLOADS'], get_post_thumbnail_id( $T4 ) ), array( array(), $g1 ) );
add_post_meta( $g2, '_tcgiant_delete_after', (string) ( time() + 900 ) );
$T5 = new_post( 'product', 'publish', 0, 'Mercury Dime 1916-D' );
update_post_meta( $T5, '_ebay_item_id', '1100' . $T5 );
$GLOBALS['DOWNLOADS'] = array();
sync( $T5, array( ebay_g( num( 'up', $g2 ) ) ) );
check( '  one waiting to be removed is not handed to it: downloaded instead', $GLOBALS['DOWNLOADS'], array( ebay_g( num( 'up', $g2 ) ) ) );
delete_post_meta( $g2, '_tcgiant_delete_after' );

// Pushed by 3.30.3 (no record of what it sent), its new listing without its main photo, Overwrite on.
settings( array( 'overwrite_images' => '1' ) );
list( $T7, $t7 ) = imported( 'Seated Liberty Dime 1875', 't7', 3 );
$GLOBALS['DOWNLOADS'] = array();
sync( $T7, push_eps( $T7, 'v7', array( $t7[1], $t7[2] ), false ) );
check( 'pushed by 3.30.3 (no record), a listing without its main photo, Overwrite on: the main photo stays', array( $GLOBALS['DOWNLOADS'], get_post_thumbnail_id( $T7 ), gallery_of( $T7 ) ), array( array(), $t7[0], $t7[1] . ',' . $t7[2] ) );

// Never pushed, Overwrite on: eBay's new first picture refused, its second one the product has.
list( $T8, $t8 ) = imported( 'Barber Dime 1900', 't8', 2 );
$GLOBALS['REFUSE'] = array( num( 'new8', 1 ) => new WP_Error( 'http_404', 'Forbidden', array( 'code' => 403 ) ) );
sync( $T8, array( ebay_z( num( 'new8', 1 ) ), ebay_z( num( 't8', 2 ) ) ) );
check( 'never pushed, Overwrite on, eBay\'s new first picture refused, the second one it has: not made the main photo', get_post_thumbnail_id( $T8 ), $t8[0] );
$GLOBALS['REFUSE'] = array();
settings( array() );

// A product showing a stand-in as its main photo, and in its gallery a copy
// of a photo this site put on eBay before that photo itself.
$T9  = new_post( 'product', 'publish', 0, 'Peace Dollar 1921' );
$s9  = new_post( 'attachment', 'inherit', $T9 );
add_post_meta( $s9, '_tcgiant_external_url', ebay_g( num( 'up', $g1 ) ) );
add_post_meta( $s9, '_tcgiant_source_url', ebay_g( num( 'up', $g1 ) ) );
$c9  = new_post( 'attachment', 'inherit', $T9 );
add_post_meta( $c9, '_tcgiant_source_url', ebay_g( num( 'up', $g1 ) ) );
add_post_meta( $c9, '_wp_attached_file', '2026/10/copy-c9.jpg' );
set_post_thumbnail( $T9, $s9 );
update_post_meta( $T9, '_product_image_gallery', "{$c9},{$g1}" );
update_post_meta( $T9, '_ebay_item_id', '1100' . $T9 );
$GLOBALS['DOWNLOADS'] = array();
sync( $T9, array( ebay_g( num( 'up', $g1 ) ) ) );
check( 'a stand-in main photo, a copy shown before the photo itself: the photo itself becomes the main photo', array( $GLOBALS['DOWNLOADS'], get_post_thumbnail_id( $T9 ) ), array( array(), $g1 ) );

echo "\nA PUSH WITH THE SETTING OFF: WHEN THE PLACES CANNOT BE TRUSTED\n" . str_repeat( '=', 124 ) . "\n";

list( $T10, $t10 ) = imported( 'Morgan Dollar 1880-S', 't10', 2 );
$copied = push_site( $T10, 'c10' );
update_post_meta( $T10, '_ebay_item_id', '2200' . $T10 );
$GLOBALS['DOWNLOADS'] = array();
sync( $T10, $copied );
check( 'the push recorded is of another listing than the one the product follows now (a relist made on eBay, say): not matched by place', array( numbers_of( $t10[0] ), numbers_of( $t10[1] ) ), array( array(), array() ) );
check( '  its pictures, none known, no more than its photos: held - nothing downloaded, its photos as they were, the product named', array( $GLOBALS['DOWNLOADS'], shown( $T10 ), in_array( $T10, held(), true ), (string) get_post_meta( $T10, '_tcgiant_images_localized', true ) ), array( array(), $t10, true, '1' ) );
list( $T11, $t11 ) = imported( 'Morgan Dollar 1881-O', 't11', 3 );
$copied = push_site( $T11, 'c11' );
$GLOBALS['DOWNLOADS'] = array();
sync( $T11, array_slice( $copied, 1 ) );
check( 'eBay showing one fewer than the push sent (one removed on eBay, or GetItem\'s other shape): not matched by place - held, nothing downloaded', array( count( $GLOBALS['DOWNLOADS'] ), shown( $T11 ), in_array( $T11, held(), true ) ), array( 0, $t11, true ) );
list( $T12, $t12 ) = imported( 'Morgan Dollar 1882-CC', 't12', 2 );
push_eps( $T12, 'v12' );
$replaced = array( ebay_g( num( 'sh12', 1 ) ), ebay_g( num( 'sh12', 2 ) ) );
$GLOBALS['DOWNLOADS'] = array();
sync( $T12, $replaced );
check( 'pushed with eBay hosting, then every photo replaced on eBay (as many): new photos, downloaded - not matched by place', $GLOBALS['DOWNLOADS'], $replaced );

echo "\nPUTTING RIGHT PRODUCTS DAMAGED BEFORE 3.30.4\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );

/**
 * A copy the old sync downloaded of a photo a push had put on eBay as
 * $number: downloaded now, as the sync after the push (yesterday) did.
 */
function copy_of( $pid, $number ) {
	$id = new_post( 'attachment', 'inherit', $pid, 'copy', 0 );
	made_on( $id, time() );
	add_post_meta( $id, '_tcgiant_source_url', preg_replace( '/\?.*$/', '', ebay_g( $number ) ) );
	add_post_meta( $id, '_wp_attached_file', '2026/10/copy-' . $id . '.jpg' );
	file_put_contents( $GLOBALS['WORLD'] . '/uploads/2026/10/copy-' . $id . '.jpg', 'JPEG' );
	return $id;
}
/** When a photo was made (post_date; the harness keeps no post_date_gmt). */
function made_on( $id, $ts ) { q( "UPDATE wp_posts SET post_date = '" . gmdate( 'Y-m-d H:i:s', (int) $ts ) . "' WHERE ID = " . (int) $id ); }
function set_shown( $pid, $main, array $gallery ) {
	set_post_thumbnail( $pid, $main );
	update_post_meta( $pid, '_product_image_gallery', implode( ',', $gallery ) );
}
/** As on a shop already on 3.28.0 or later: its gallery pass and backfill long done. */
function old_passes_done() {
	update_option( 'tcgiant_sync_gallery_heal', array( 'done' => time() ) );
	update_option( 'tcgiant_gallery_backfill_done', 1 );
}
old_passes_done();

// Overwrite Images off: the copies were added behind the photos they copy.
list( $P1, $p1 ) = imported( 'Peace Dollar 1922', 'p1', 3 );
push_eps( $P1, 'q1', null, false );
$p1c = array();
foreach ( $p1 as $id ) {
	$p1c[ $id ] = copy_of( $P1, num( 'q1', $id ) );
}
set_shown( $P1, $p1[0], array( $p1[1], $p1[2], $p1c[ $p1[0] ], $p1c[ $p1[1] ], $p1c[ $p1[2] ] ) );

// Overwrite Images on: the copies replaced the main photo and the gallery.
list( $P2, $p2 ) = imported( 'Peace Dollar 1923', 'p2', 3 );
push_eps( $P2, 'q2', null, false );
$p2c = array();
foreach ( $p2 as $id ) {
	$p2c[ $id ] = copy_of( $P2, num( 'q2', $id ) );
}
set_shown( $P2, $p2c[ $p2[0] ], array( $p2c[ $p2[1] ], $p2c[ $p2[2] ] ) );

// Overwrite on, and a new listing that went without its main photo: the
// main photo became a gallery photo's copy. Which photo was the main one is
// not certain from what is left.
list( $P3, $p3 ) = imported( 'Peace Dollar 1924', 'p3', 3 );
push_eps( $P3, 'q3', array( $p3[1], $p3[2] ), false );
update_post_meta( $P3, '_ebay_listing_status', 'Active' );
$p3c = array( $p3[1] => copy_of( $P3, num( 'q3', $p3[1] ) ), $p3[2] => copy_of( $P3, num( 'q3', $p3[2] ) ) );
set_shown( $P3, $p3c[ $p3[1] ], array( $p3c[ $p3[2] ] ) );
update_post_meta( $P3, '_ebay_live', array( 'item_id' => (string) get_post_meta( $P3, '_ebay_item_id', true ), 'pictures' => array( ebay_g( num( 'q3', $p3[1] ) ), ebay_g( num( 'q3', $p3[2] ) ) ) ) );

// A copy of a copy: a push uploaded the copy, and the sync brought that back too.
list( $P4, $p4 ) = imported( 'Peace Dollar 1925', 'p4', 2 );
push_eps( $P4, 'q4', null, false );
$p4c  = copy_of( $P4, num( 'q4', $p4[1] ) );
update_post_meta( $p4c, '_tcgiant_eps_url', eps_full( num( 'q4b', $p4c ) ) );
$p4cc = copy_of( $P4, num( 'q4b', $p4c ) );
set_shown( $P4, $p4[0], array( $p4[1], $p4c, $p4cc ) );
update_post_meta( $P4, '_ebay_listing_status', 'Active' );
update_post_meta( $P4, '_ebay_live', array( 'item_id' => (string) get_post_meta( $P4, '_ebay_item_id', true ), 'pictures' => array( ebay_g( num( 'q4', $p4[1] ) ), ebay_g( num( 'q4', $p4[0] ) ) ) ) );

// A product untouched by any of it, and one with a photo the shop added itself.
list( $P5, $p5 ) = imported( 'Peace Dollar 1926', 'p5', 2 );
$own = new_post( 'attachment', 'inherit', $P5, 'shop photo', 1 );
add_post_meta( $own, '_wp_attached_file', '2026/10/shop-photo.jpg' );
set_shown( $P5, $p5[0], array( $p5[1], $own ) );

// A product showing eBay stand-ins only - imported while "Disable background
// image localization?" was Yes - whose new listing went up without its main
// photo: eBay shows its gallery picture first.
$P6 = new_post( 'product', 'publish', 0, 'Peace Dollar 1927' );
$s6 = array();
foreach ( array( 1, 2 ) as $n ) {
	$sid = new_post( 'attachment', 'inherit', $P6 );
	add_post_meta( $sid, '_tcgiant_external_url', ebay_z( num( 'p6', $n ) ) );
	add_post_meta( $sid, '_tcgiant_source_url', preg_replace( '/\?.*$/', '', ebay_z( num( 'p6', $n ) ) ) );
	$s6[] = $sid;
}
add_post_meta( $P6, '_tcgiant_external_image_urls', array( ebay_z( num( 'p6', 2 ) ) ) );
set_shown( $P6, $s6[0], array( $s6[1] ) );
update_post_meta( $P6, '_ebay_item_id', '1100' . $P6 );
update_post_meta( $P6, '_ebay_export_last_pushed', pushed_on() );
update_post_meta( $P6, '_ebay_listing_status', 'Active' );
update_post_meta( $P6, '_ebay_live', array( 'item_id' => '1100' . $P6, 'pictures' => array( preg_replace( '/\?.*$/', '', ebay_z( num( 'p6', 2 ) ) ) ) ) );

// A product whose listing has ended, showing another photo first: not named,
// as there is nothing live to push again. And an undamaged product with a
// photo of its own taken out of its gallery: not named for the shop to check.
list( $P7, $p7 ) = imported( 'Peace Dollar 1928', 'p7', 2 );
push_eps( $P7, 'q7', null, false );
update_post_meta( $P7, '_ebay_listing_status', 'Ended' );
update_post_meta( $P7, '_ebay_live', array( 'item_id' => (string) get_post_meta( $P7, '_ebay_item_id', true ), 'pictures' => array( ebay_g( num( 'q7', $p7[1] ) ) ) ) );
list( $P8, $p8 ) = imported( 'Peace Dollar 1934', 'p8', 2 );
update_post_meta( $P8, '_product_image_gallery', '' );
update_post_meta( $P8, '_ebay_export_last_pushed', pushed_on() );

// Many more, so the repair takes more than one batch, every other one
// damaged: made as the import and the old sync left them.
$many = array();
for ( $k = 0; $k < 130; $k++ ) {
	$pk = new_post( 'product', 'publish', 0, 'Walking Liberty ' . $k );
	$mk = new_post( 'attachment', 'inherit', $pk );
	$gk = new_post( 'attachment', 'inherit', $pk );
	update_post_meta( $pk, '_ebay_export_last_pushed', pushed_on() );
	foreach ( array( $mk, $gk ) as $ak ) {
		add_post_meta( $ak, '_wp_attached_file', '2026/10/wl-' . $ak . '.jpg' );
		file_put_contents( $GLOBALS['WORLD'] . '/uploads/2026/10/wl-' . $ak . '.jpg', 'JPEG' );
	}
	add_post_meta( $mk, '_tcgiant_source_url', ebay_g( num( 'wm' . $k, 1 ) ) );
	add_post_meta( $gk, '_tcgiant_source_url', ebay_g( num( 'wm' . $k, 2 ) ) );
	add_post_meta( $pk, '_tcgiant_external_image_urls', array( ebay_g( num( 'wm' . $k, 1 ) ), ebay_g( num( 'wm' . $k, 2 ) ) ) );
	set_shown( $pk, $mk, array( $gk ) );
	if ( 0 === $k % 2 ) {
		add_post_meta( $gk, '_tcgiant_eps_url', eps_full( num( 'wx' . $k, 1 ) ) );
		set_shown( $pk, $mk, array( $gk, copy_of( $pk, num( 'wx' . $k, 1 ) ) ) );
	}
	$many[ $pk ] = array( $mk, $gk );
}
$damaged_count = 4 + 65;
$checked_count = 8 + 130;

$GLOBALS['DOWNLOADS'] = array();
$GLOBALS['DELETED']   = array();
$GLOBALS['LOG']       = array();
$GLOBALS['CLEANED']   = array();
$state = attachment_state();
$files = files_on_disk();
$p3_main_copy = get_post_thumbnail_id( $P3 );
repair();

check( 'Overwrite Images off damage: each copy gone from the gallery, the shop\'s order kept', array( get_post_thumbnail_id( $P1 ), gallery_of( $P1 ) ), array( $p1[0], $p1[1] . ',' . $p1[2] ) );
check( 'Overwrite on damage: the main photo is the shop\'s own again (its copy was certain evidence)', array( get_post_thumbnail_id( $P2 ), gallery_of( $P2 ) ), array( $p2[0], $p2[1] . ',' . $p2[2] ) );
check( 'main photo lost to a gallery copy: the copy is shown as its own photo, which stays the main photo', array( get_post_thumbnail_id( $P3 ), gallery_of( $P3 ) ), array( $p3[1], (string) $p3[2] ) );
check( 'a copy of a copy is the photo it copies', array( get_post_thumbnail_id( $P4 ), gallery_of( $P4 ) ), array( $p4[0], (string) $p4[1] ) );
check( 'the shop\'s own photo and an undamaged product are left as they are', array( shown( $P5 ), get_post_thumbnail_id( $P5 ) ), array( array( $p5[0], $p5[1], $own ), $p5[0] ) );
$all_right = true;
foreach ( $many as $pk => $pp ) {
	$all_right = $all_right && shown( $pk ) === $pp;
}
check( 'every product of the batches put right', $all_right, true );
$added = $GLOBALS['wpdb']->get_results( "SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE meta_key = '" . NUMBER_META . "' ORDER BY meta_id", 'ARRAY_A' );
q( "DELETE FROM wp_postmeta WHERE meta_key = '" . NUMBER_META . "'" );
check( 'no file, attachment or attachment record changed or deleted: only the products\' lists written', array( attachment_state() === $state, files_on_disk(), $GLOBALS['DELETED'] ), array( true, $files, array() ) );
$cached = $GLOBALS['wpdb']->get_results( "SELECT post_id, meta_value FROM wp_postmeta WHERE meta_key = '_tcgiant_eps_url' ORDER BY meta_id", 'ARRAY_A' );
$want = array_map( function ( $r ) { return array( $r['post_id'], NUMBER_META, preg_replace( '#^.*/z/([^/]+)/.*$#', '$1', $r['meta_value'] ) ); }, $cached );
// And the copy's own upload, once it is shown no more, on the photo it copies.
$want[] = array( (string) $p4[1], NUMBER_META, num( 'q4b', $p4c ) );
check( '  but for the number of each upload cached before 3.30.4, added once to its photo, and a copy\'s own number added to the photo it copies', array_map( function ( $r ) { return array( $r['post_id'], NUMBER_META, $r['meta_value'] ); }, $added ), $want );
foreach ( $added as $r ) {
	add_post_meta( (int) $r['post_id'], NUMBER_META, $r['meta_value'] );
}
check( 'nothing downloaded and nothing asked of eBay', $GLOBALS['DOWNLOADS'], array() );
$summary = logged( 'copy' );
check( 'one summary line', count( $summary ), 1 );
check( '  saying what it did', $summary[0] ?? '', sprintf( 'Own photos: %d of %d product(s) showed a copy that came back from eBay of a photo of their own, %d of them as their main photo; each shows its own photo again, in its place. No photo was deleted: the copies, now unused, can be removed with Image Cleanup\'s check.', $damaged_count, $checked_count, 2 ) );
check( '  each product written has its caches cleared, which pushes nothing', array( count( array_unique( $GLOBALS['CLEANED'] ) ), count( array_unique( $GLOBALS['TRANSIENTS_CLEARED'] ) ) ), array( $damaged_count, $damaged_count ) );
$listed = logged( 'eBay listing shows' );
check( 'the products whose live listing shows another photo first are named, for pushing again - one showing stand-ins too', $listed, array( sprintf( 'Own photos: the eBay listing shows another photo first for 2 product(s), whose main photo here is their own: WC #%d, WC #%d. Nothing changes that by itself: push them again, on a day their photos can be put on eBay, to put their main photo first.', $P4, $P6 ) ) );
check( '  the stand-ins\' product left as it is', shown( $P6 ), $s6 );
$review = logged( 'no longer show ' );
check( '  and those whose main photo may have been replaced before, for the shop to look at', $review, array( sprintf( 'Own photos: 1 product(s) no longer show a photo of their own still attached to them, which may have been their main photo before a new listing went up without it: WC #%d (photo #%d). Check them, and choose their main photo again if it is not the right one.', $P3, $p3[0] ) ) );

// Idempotent: nothing more is done.
$GLOBALS['LOG'] = array();
$snapshot = array( shown( $P1 ), shown( $P2 ), shown( $P3 ), shown( $P4 ), shown( $P5 ) );
repair();
check( 'once done, it does not run again', array( $GLOBALS['LOG'], $GLOBALS['QUEUE'] ? end( $GLOBALS['QUEUE'] )['status'] : 'none' ), array( array(), 'done' ) );
if ( $HAS_OWN ) {
	delete_option( TCGiant_Sync_Own_Photos::HEAL_OPTION );
}
repair();
check( 'run again from the start, it changes nothing', array( array( shown( $P1 ), shown( $P2 ), shown( $P3 ), shown( $P4 ), shown( $P5 ) ), logged( 'copy' ) ), array( $snapshot, array( sprintf( 'Own photos: 0 of %d product(s) showed a copy that came back from eBay of a photo of their own, 0 of them as their main photo; each shows its own photo again, in its place. No photo was deleted: the copies, now unused, can be removed with Image Cleanup\'s check.', $checked_count ) ) ) );

// The sync after the repair keeps it so.
$GLOBALS['DOWNLOADS'] = array();
sync( $P2, array( ebay_g( num( 'q2', $p2[0] ) ), ebay_g( num( 'q2', $p2[1] ) ), ebay_g( num( 'q2', $p2[2] ) ) ) );
check( 'synced after the repair, Overwrite off: the copies are not taken back, nothing downloaded', array( shown( $P2 ), $GLOBALS['DOWNLOADS'] ), array( $p2, array() ) );
settings( array( 'overwrite_images' => '1' ) );
sync( $P2, array( ebay_g( num( 'q2', $p2[0] ) ) . '?a=1', ebay_g( num( 'q2', $p2[1] ) ), ebay_g( num( 'q2', $p2[2] ) ) ) );
check( '  and with Overwrite on', array( shown( $P2 ), $GLOBALS['DOWNLOADS'] ), array( $p2, array() ) );
settings( array() );

echo "\nA REPAIR RUN KILLED PART WAY\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
$damaged = array();
for ( $k = 0; $k < 7; $k++ ) {
	list( $pk, $pp ) = imported( 'Seated Liberty ' . $k, 's' . $k, 2 );
	push_eps( $pk, 'z' . $k, null, false );
	$ck = copy_of( $pk, num( 'z' . $k, $pp[0] ) );
	set_shown( $pk, $pp[0], array( $pp[1], $ck ) );
	$damaged[ $pk ] = $pp;
}
$GLOBALS['LOG'] = array();
admin_page();
file_put_contents( $WORLD . '/queue.json', json_encode( $GLOBALS['QUEUE'] ) );
$GLOBALS['wpdb']->db->close();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child=kill 2>&1', $out, $code );
open_db();
$GLOBALS['QUEUE'] = json_decode( (string) file_get_contents( $WORLD . '/queue.json' ), true );
// Action Scheduler marks the killed action failed; the next admin request
// queues the pass again.
foreach ( $GLOBALS['QUEUE'] as $k => $a ) {
	if ( 'running' === $a['status'] ) {
		$GLOBALS['QUEUE'][ $k ]['status'] = 'failed';
	}
}
$done_before = 0;
foreach ( $damaged as $pk => $pp ) {
	$done_before += shown( $pk ) === $pp ? 1 : 0;
}
check( 'the run died part way through a batch (exit 9), some products put right, not all', array( $code, $done_before > 0 && $done_before < count( $damaged ) ), array( $HAS_OWN ? 9 : 0, $HAS_OWN ) );
$heal_record = $HAS_OWN ? get_option( TCGiant_Sync_Own_Photos::HEAL_OPTION ) : array();
check( '  where it had got to kept after each product it finished, not only at the end of the batch', (int) ( $heal_record['cursor'] ?? 0 ) > 0, true );
$GLOBALS['LOG'] = array();
admin_page();
run_queue();
$all_right = true;
foreach ( $damaged as $pk => $pp ) {
	$all_right = $all_right && shown( $pk ) === $pp;
}
check( 'queued again by the next admin page, it carries on and finishes', $all_right, true );
$line = logged( 'copy' );
check( '  saying a run was stopped part way', (bool) preg_match( '/A run was stopped part way 1 time\(s\)/', $line[0] ?? '' ), true );

echo "\nA PRODUCT CHANGED WHILE THE REPAIR LOOKS AT IT\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
list( $Q, $qp ) = imported( 'Barber Half 1892', 'qb', 2 );
push_eps( $Q, 'qz', null, false );
$cq = copy_of( $Q, num( 'qz', $qp[1] ) );
set_shown( $Q, $qp[0], array( $qp[1], $cq ) );
// The shop saves the product, its gallery now the copy alone, as the pass
// is about to write it: the pass's write is over the row it read.
$GLOBALS['wpdb']->on_query = function ( $sql ) use ( $Q, $cq ) {
	if ( 0 === strpos( ltrim( $sql ), 'UPDATE wp_postmeta' ) && false === strpos( $sql, 'meta_key' ) ) {
		$GLOBALS['wpdb']->on_query = null;
		q( "UPDATE wp_postmeta SET meta_value = '{$cq}' WHERE post_id = {$Q} AND meta_key = '_product_image_gallery'" );
	}
};
repair();
$GLOBALS['wpdb']->on_query = null;
check( 'a gallery the shop saved as the pass was writing it is not written over', array( gallery_of( $Q ), get_post_thumbnail_id( $Q ), $HAS_OWN && ! empty( get_option( TCGiant_Sync_Own_Photos::HEAL_OPTION )['done'] ) ), array( (string) $cq, $qp[0], true ) );
check( '  and, not put right, it is not named as one that may have lost its main photo', $HAS_OWN ? ( get_option( TCGiant_Sync_Own_Photos::HEAL_OPTION )['review'] ?? null ) : null, array() );

// The same for the main photo: the shop chooses another as the pass writes it.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
list( $Q2, $q2 ) = imported( 'Barber Half 1893', 'qc', 2 );
push_eps( $Q2, 'qy', null, false );
$cq2 = copy_of( $Q2, num( 'qy', $q2[0] ) );
set_shown( $Q2, $cq2, array( $q2[1] ) );
$GLOBALS['wpdb']->on_query = function ( $sql ) use ( $Q2, $q2 ) {
	if ( 0 === strpos( ltrim( $sql ), 'UPDATE wp_postmeta' ) && false === strpos( $sql, 'meta_key' ) ) {
		$GLOBALS['wpdb']->on_query = null;
		q( "UPDATE wp_postmeta SET meta_value = '{$q2[1]}' WHERE post_id = {$Q2} AND meta_key = '_thumbnail_id'" );
	}
};
repair();
$GLOBALS['wpdb']->on_query = null;
check( 'a main photo the shop chose as the pass was writing it is not written over', array( get_post_thumbnail_id( $Q2 ), gallery_of( $Q2 ) ), array( $q2[1], (string) $q2[1] ) );

echo "\n\"DISABLE BACKGROUND IMAGE LOCALIZATION?\" YES, AND BACK TO NO\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array( 'overwrite_images' => '1' ) );
list( $J, $jp ) = imported( 'Trade Dollar 1875', 'ij', 3 );
settings( array( 'overwrite_images' => '1', 'disable_image_localization' => '1' ) );
$GLOBALS['DOWNLOADS'] = array();
$before = attachments();
$pushed_j = push_eps( $J, 'uj', array( $jp[1], $jp[2] ) );
sync( $J, $pushed_j );
check( 'localization off: a sync of a pushed listing downloads nothing and changes no photo', array( $GLOBALS['DOWNLOADS'], attachments(), shown( $J ) ), array( array(), $before, $jp ) );
check( '  the product waits in the queue', (string) get_post_meta( $J, '_tcgiant_images_localized', true ), '0' );
list( $K ) = array( new_post( 'product', 'publish', 0, 'New import' ) );
update_post_meta( $K, '_ebay_item_id', '1100' . $K );
sync( $K, array( ebay_z( num( 'nk', 1 ) ), ebay_z( num( 'nk', 2 ) ) ) );
check( '  a new import shows eBay-hosted stand-ins', array( $GLOBALS['DOWNLOADS'], (string) get_post_meta( get_post_thumbnail_id( $K ), '_tcgiant_external_url', true ) ), array( array(), ebay_z( num( 'nk', 1 ) ) ) );
settings( array( 'overwrite_images' => '1', 'disable_image_localization' => '0' ) );
run_localizer();
check( 'turned back to No: the pushed product is matched, not downloaded again, its photos as they were', array( shown( $J ), (string) get_post_meta( $J, '_tcgiant_images_localized', true ) ), array( $jp, '1' ) );
check( '  only the new import\'s photos are downloaded', $GLOBALS['DOWNLOADS'], array( ebay_z( num( 'nk', 1 ) ), ebay_z( num( 'nk', 2 ) ) ) );

echo "\nTHE REPAIR STAYS DONE, WHATEVER LOOKS AT THE PRODUCT AGAIN\n" . str_repeat( '=', 124 ) . "\n";

/**
 * A product the old sync damaged twice over: pushed by 3.30.3, its photos
 * downloaded again as copies, then pushed by 3.30.3 again while it showed
 * them, so the copies were uploaded too, under numbers of their own, which
 * its listing shows now. With Overwrite Images off the copies went behind
 * its photos; with it on they took their places.
 *
 * @return array [ product, its photos, photo => its copy, the listing's pictures, the same in GetItem's other address form ]
 */
function damaged_twice( $title, $tag, $overwrite_damage ) {
	list( $P, $pp ) = imported( $title, $tag, 3 );
	push_eps( $P, 'q' . $tag, null, false );
	$c = array();
	foreach ( $pp as $id ) {
		$c[ $id ] = copy_of( $P, num( 'q' . $tag, $id ) );
	}
	if ( $overwrite_damage ) {
		set_shown( $P, $c[ $pp[0] ], array( $c[ $pp[1] ], $c[ $pp[2] ] ) );
	} else {
		set_shown( $P, $pp[0], array( $pp[1], $pp[2], $c[ $pp[0] ], $c[ $pp[1] ], $c[ $pp[2] ] ) );
	}
	push_eps( $P, 'n' . $tag, array_values( $c ), false );
	$listing = array();
	$other   = array();
	foreach ( $overwrite_damage ? array() : $pp as $id ) {
		$listing[] = ebay_g( num( 'q' . $tag, $id ) );
		$other[]   = ebay_z( num( 'q' . $tag, $id ) );
	}
	foreach ( $c as $cid ) {
		$listing[] = ebay_g( num( 'n' . $tag, $cid ) );
		$other[]   = ebay_z( num( 'n' . $tag, $cid ) );
	}
	return array( $P, $pp, $c, $listing, $other );
}

$triggers = array(
	'the backlog queued before the update, run after the repair',
	'localization off (the interim advice), then back to No',
	'Overwrite Images turned on',
	'Overwrite Images turned off',
	'an images-only sync (GetItem\'s address form)',
	'what was stored queued again',
	'a photo added to the listing on eBay',
	'the copies removed by Image Cleanup, then a photo added on eBay',
	'the copies removed by Image Cleanup, then localization back to No',
);
foreach ( array( 'Overwrite off damage' => false, 'Overwrite on damage' => true ) as $shape => $overwrite_damage ) {
	foreach ( $triggers as $k => $trigger ) {
		fresh_db();
		TCGiant_Sync_Image_Localizer::instance();
		settings( 3 === $k ? array( 'overwrite_images' => '1' ) : array() );
		old_passes_done();
		list( $P, $pp, $c, $listing, $other ) = damaged_twice( 'Stays ' . $k, 'st' . $k . ( $overwrite_damage ? 'o' : 'f' ), $overwrite_damage );
		$hub = in_array( $k, array( 6, 7 ), true ) ? ebay_z( num( 'hub' . $k, 1 ) ) : null;
		if ( 0 === $k ) {
			TCGiant_Sync_Image_Localizer::set_external_images( $P, $listing );
			repair();
		} elseif ( in_array( $k, array( 1, 8 ), true ) ) {
			settings( array( 'disable_image_localization' => '1' ) );
			sync( $P, $listing );
			repair();
		} else {
			sync( $P, $listing );
			repair();
		}
		$repaired = shown( $P );
		if ( in_array( $k, array( 7, 8 ), true ) ) {
			// Image Cleanup's button, once the copies' quarantine is over.
			foreach ( $c as $cid ) {
				wp_delete_attachment( $cid, true );
			}
		}
		$GLOBALS['DOWNLOADS'] = array();
		$before               = attachments();
		switch ( $k ) {
			case 0:
				run_localizer();
				break;
			case 1:
			case 8:
				settings( array() );
				run_localizer();
				break;
			case 2:
				settings( array( 'overwrite_images' => '1' ) );
				sync( $P, $listing );
				break;
			case 3:
				settings( array() );
				sync( $P, $listing );
				break;
			case 4:
				TCGiant_Sync_Image_Localizer::set_external_images( $P, $other );
				run_localizer();
				break;
			case 5:
				TCGiant_Sync_Image_Localizer::set_external_images( $P, $listing, true );
				run_localizer();
				break;
			default:
				sync( $P, array_merge( $listing, array( $hub ) ) );
		}
		$new  = attachments() - $before;
		$want = $pp;
		if ( null !== $hub ) {
			$want[] = (int) $GLOBALS['wpdb']->get_var( 'SELECT MAX(ID) FROM wp_posts WHERE post_type = \'attachment\'' );
		}
		check( sprintf( '%s, %s: its own photos, nothing taken back or fetched again', $shape, $trigger ), array( $repaired, shown( $P ), $GLOBALS['DOWNLOADS'], $new ), array( $pp, $want, null === $hub ? array() : array( $hub ), null === $hub ? 0 : 1 ) );
	}
}
settings( array() );

// The shop took the copies out of the gallery itself, before the repair
// (nothing carried): a listing still showing a copy's own number is the
// photo the copy copies, and the copy is never taken back.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
list( $P, $pp, $c, $listing, $other ) = damaged_twice( 'Taken out by hand', 'th', false );
foreach ( array_merge( $pp, array_values( $c ) ) as $id ) {
	// The upgrade's first part: each cached upload's number kept on its photo.
	if ( $HAS_OWN ) {
		TCGiant_Sync_Own_Photos::remember( $id, (string) get_post_meta( $id, '_tcgiant_eps_url', true ) );
	}
}
set_shown( $P, $pp[0], array( $pp[1], $pp[2] ) );
$GLOBALS['DOWNLOADS'] = array();
$before               = attachments();
TCGiant_Sync_Image_Localizer::set_external_images( $P, $other );
run_localizer();
check( 'the copies taken out of the gallery by hand, then a sync: the photos they copy found, no copy taken back, nothing downloaded', array( shown( $P ), $GLOBALS['DOWNLOADS'], attachments() - $before ), array( $pp, array(), 0 ) );

// A 3.30.4 push before the repair reached the product, the sync after it.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
list( $P, $pp ) = imported( 'B1b Morgan', 'b2', 2 );
push_eps( $P, 'q2', null, false );
$cm = copy_of( $P, num( 'q2', $pp[0] ) );
set_shown( $P, $pp[0], array( $pp[1], $cm ) );
$after = push_eps( $P, 'r2' );
repair();
$GLOBALS['DOWNLOADS'] = array();
sync( $P, $after );
check( 'pushed by 3.30.4 before the repair reached it (the copy uploaded), synced after: its own photos, nothing downloaded', array( shown( $P ), $GLOBALS['DOWNLOADS'] ), array( $pp, array() ) );

echo "\nA PUSH FROM THE WEBSITE THE PLUGIN HAS NO RECORD OF (BEFORE 3.30.4, AND 2026-11-03)\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();

/** A push from the website before 3.30.4: nothing recorded, and eBay's copies under numbers nobody here knows. */
function pushed_unrecorded( $pid, $tag ) {
	update_post_meta( $pid, '_ebay_export_last_pushed', pushed_on() );
	$after = array();
	foreach ( shown( $pid ) as $id ) {
		$after[] = ebay_z( num( $tag, $id ) );
	}
	return $after;
}
function held() {
	if ( ! $GLOBALS['HAS_OWN'] ) {
		return array();
	}
	// Named on the product since fix round 2; in an option before it.
	return method_exists( 'TCGiant_Sync_Own_Photos', 'held_products' ) ? TCGiant_Sync_Own_Photos::held_products( 100000 )[0] : array_map( 'intval', array_keys( (array) get_option( 'tcgiant_sync_own_photos_held', array() ) ) );
}

foreach ( array( 'off' => array(), 'on' => array( 'overwrite_images' => '1' ) ) as $ow => $s ) {
	settings( $s );
	list( $H1, $h1 ) = imported( 'Held ' . $ow, 'h1' . $ow, 3 );
	$after = pushed_unrecorded( $H1, 'w1' . $ow );
	$GLOBALS['DOWNLOADS'] = array();
	$GLOBALS['LOG']       = array();
	$before               = attachments();
	sync( $H1, $after );
	check( "Overwrite $ow: every picture unknown, as many as its photos: held - nothing downloaded, its photos as they were", array( $GLOBALS['DOWNLOADS'], attachments() - $before, shown( $H1 ) ), array( array(), 0, $h1 ) );
	check( '  named for the shop, said in the log, and not queued again', array( in_array( $H1, held(), true ), count( logged( 'may be eBay\'s copies of its own photos' ) ), (string) get_post_meta( $H1, '_tcgiant_images_localized', true ) ), array( true, 1, '1' ) );
	check( '  nothing learnt from it', array( numbers_of( $h1[0] ), numbers_of( $h1[2] ) ), array( array(), array() ) );
	sync( $H1, $after );
	check( '  the next sync of the same listing: nothing', $GLOBALS['DOWNLOADS'], array() );
}
settings( array() );

list( $H2, $h2 ) = imported( 'Held n-1', 'h2', 3 );
$after = pushed_unrecorded( $H2, 'w2' );
$GLOBALS['DOWNLOADS'] = array();
sync( $H2, array_slice( $after, 1 ) );
check( 'one picture fewer than its photos, should GetItem leave out the first picture\'s copy: held too', array( $GLOBALS['DOWNLOADS'], shown( $H2 ), in_array( $H2, held(), true ) ), array( array(), $h2, true ) );

list( $H3, $h3 ) = imported( 'Held plus one', 'h3', 2 );
$after   = pushed_unrecorded( $H3, 'w3' );
$after[] = ebay_z( num( 'hub3', 1 ) );
$GLOBALS['DOWNLOADS'] = array();
sync( $H3, $after );
check( 'one more picture than its photos (one added on eBay): not held - downloaded, as before', array( count( $GLOBALS['DOWNLOADS'] ), in_array( $H3, held(), true ) ), array( 3, false ) );

list( $H4, $h4 ) = imported( 'Held one known', 'h4', 2 );
$after    = pushed_unrecorded( $H4, 'w4' );
$after[0] = ebay_g( num( 'h4', 1 ) );
$GLOBALS['DOWNLOADS'] = array();
sync( $H4, $after );
check( 'one of the pictures one it has: not held - only the one it does not know downloaded', $GLOBALS['DOWNLOADS'], array( $after[1] ) );

list( $H5, $h5 ) = imported( 'Never pushed', 'h5', 2 );
$GLOBALS['DOWNLOADS'] = array();
sync( $H5, array( ebay_z( num( 'w5', 1 ) ), ebay_z( num( 'w5', 2 ) ) ) );
check( 'never pushed, every picture replaced on eBay: downloaded, as before', count( $GLOBALS['DOWNLOADS'] ), 2 );

$H6 = new_post( 'product', 'publish', 0, 'Held stand-ins' );
update_post_meta( $H6, '_ebay_item_id', '1100' . $H6 );
settings( array( 'disable_image_localization' => '1' ) );
sync( $H6, array( ebay_z( num( 'h6', 1 ) ), ebay_z( num( 'h6', 2 ) ) ) );
settings( array() );
$after = pushed_unrecorded( $H6, 'w6' );
$GLOBALS['DOWNLOADS'] = array();
sync( $H6, $after );
check( 'eBay stand-ins for its photos, none of its own: not held - downloaded', count( $GLOBALS['DOWNLOADS'] ), 2 );

list( $H7, $h7 ) = imported( 'Held variations', 'h7', 2 );
$v7    = new_post( 'product_variation', 'publish', $H7, 'Held variation' );
$after = pushed_unrecorded( $H7, 'w7' );
$GLOBALS['DOWNLOADS'] = array();
sync( $H7, array_merge( $after, array( array( ebay_z( num( 'h7v', 1 ) ), $v7 ) ) ) );
check( 'a listing with a variation\'s picture: not held - downloaded, as before', count( $GLOBALS['DOWNLOADS'] ), 3 );

$after = push_site( $H2, 'w2b' );
$GLOBALS['DOWNLOADS'] = array();
sync( $H2, $after );
check( 'pushed again under 3.30.4: off the list, its listing learnt on the next read, nothing downloaded', array( in_array( $H2, held(), true ), $GLOBALS['DOWNLOADS'], shown( $H2 ), numbers_of( $h2[1] ) ), array( false, array(), $h2, array( num( 'w2b', $h2[1] ) ) ) );

// Copies such a listing's sync already downloaded (Overwrite off): nothing
// knows their numbers, so nothing is changed - the product is named.
list( $TL, $tl ) = imported( 'Tail of copies', 'tl', 2 );
$after = pushed_unrecorded( $TL, 'wt' );
$tlc   = array();
foreach ( $tl as $id ) {
	$tlc[] = copy_of( $TL, num( 'wt', $id ) );
}
set_shown( $TL, $tl[0], array_merge( array( $tl[1] ), $tlc ) );
update_post_meta( $TL, '_ebay_live', array( 'item_id' => (string) get_post_meta( $TL, '_ebay_item_id', true ), 'pictures' => array_map( function ( $u ) { return preg_replace( '/\?.*$/', '', $u ); }, $after ) ) );
list( $TN, $tn ) = imported( 'Tail of its own photos', 'tn', 2 );
update_post_meta( $TN, '_ebay_export_last_pushed', pushed_on() );
$own_tn = array();
foreach ( array( 1, 2 ) as $n ) {
	$own_tn[] = copy_of( $TN, num( 'zz' . $n, 1 ) );
}
set_shown( $TN, $tn[0], array_merge( array( $tn[1] ), $own_tn ) );
$GLOBALS['LOG'] = array();
$before_tl      = array( shown( $TL ), shown( $TN ) );
repair();
$heal_record = $HAS_OWN ? get_option( TCGiant_Sync_Own_Photos::HEAL_OPTION ) : array();
check( 'a gallery ending with downloads of its listing\'s pictures, as many as the photos before them: named for a look, nothing changed', array( array_keys( (array) ( $heal_record['tails'] ?? array() ) ), array( shown( $TL ), shown( $TN ) ), count( logged( 'may show copies of their own photos that a sync downloaded from eBay' ) ) ), array( array( $TL ), $before_tl, 1 ) );

echo "\nA PUSH FROM THE WEBSITE: ITS PLACES ARE READ ONCE, NEVER OVER A NUMBER KNOWN\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );

list( $S, $sp ) = imported( 'B2 Morgan', 's2', 3 );
$copied = push_site( $S, 'cs' );
$GLOBALS['DOWNLOADS'] = array();
sync( $S, $copied );
check( 'the first read after the push learns the places: nothing downloaded', $GLOBALS['DOWNLOADS'], array() );
$newpic = ebay_g( num( 'hub', 7 ) );
sync( $S, array( $copied[0], $copied[1], $newpic ) );
check( 'a picture replaced in Seller Hub after that read, as many pictures: the new one downloaded', $GLOBALS['DOWNLOADS'], array( $newpic ) );
check( '  its number never kept on the shop\'s photo in that place', in_array( num( 'hub', 7 ), numbers_of( $sp[2] ), true ), false );

// What was stored queued again before the first read is no read of the
// listing: the record stays for the sync that reads it.
list( $RS, $rs ) = imported( 'Stored re-run', 's5', 2 );
$stored = (array) get_post_meta( $RS, '_tcgiant_external_image_urls', true );
$copied = push_site( $RS, 'cq' );
TCGiant_Sync_Image_Localizer::set_external_images( $RS, $stored, true );
run_localizer();
$GLOBALS['DOWNLOADS'] = array();
sync( $RS, $copied );
check( 'what was stored queued again before the first read spends nothing: that read learns the places', array( $GLOBALS['DOWNLOADS'], numbers_of( $rs[1] ) ), array( array(), array( num( 'cq', $rs[1] ) ) ) );

list( $R, $rp ) = imported( 'B3 Morgan', 's4', 3 );
$copied = push_site( $R, 'cr' );
sync( $R, $copied );
$GLOBALS['DOWNLOADS'] = array();
sync( $R, array( $copied[1], $copied[0], $copied[2] ) );
check( 'reordered in Seller Hub after that read: nothing downloaded, no photo given another\'s number', array( $GLOBALS['DOWNLOADS'], shown( $R ), in_array( num( 'cr', $rp[1] ), numbers_of( $rp[0] ), true ) ), array( array(), $rp, false ) );
TCGiant_Sync_Image_Localizer::set_external_images( $R, array( $copied[1], $copied[0], $copied[2] ), true );
run_localizer();
settings( array( 'overwrite_images' => '1' ) );
sync( $R, array( $copied[2], $copied[0], $copied[1] ) );
settings( array() );
check( '  queued again as it was stored, or with Overwrite Images changed: the same', array( $GLOBALS['DOWNLOADS'], shown( $R ), in_array( num( 'cr', $rp[1] ), numbers_of( $rp[0] ), true ), in_array( num( 'cr', $rp[2] ), numbers_of( $rp[0] ), true ) ), array( array(), $rp, false, false ) );
$Y = new_post( 'product', 'publish', 0, 'B3 other listing' );
update_post_meta( $Y, '_ebay_item_id', '1100' . $Y );
sync( $Y, array( $copied[1] ) );
check( '  another product showing photo 2\'s eBay copy is never handed photo 1: what is learnt by place is this product\'s', get_post_thumbnail_id( $Y ) === $rp[0], false );

// Before that first read: nothing can tell a picture replaced in Seller Hub.
list( $S2, $sp2 ) = imported( 'B2b Morgan', 's3', 3 );
$copied = push_site( $S2, 'ct' );
$newpic = ebay_g( num( 'hub', 8 ) );
$GLOBALS['DOWNLOADS'] = array();
sync( $S2, array( $copied[0], $copied[1], $newpic ) );
check( 'known limit: a picture replaced in Seller Hub before the first read is taken for the photo in its place', array( $GLOBALS['DOWNLOADS'], in_array( num( 'hub', 8 ), numbers_of( $sp2[2] ), true ) ), array( array(), true ) );
$X = new_post( 'product', 'publish', 0, 'B2b sell similar' );
update_post_meta( $X, '_ebay_item_id', '1100' . $X );
sync( $X, array( $newpic ) );
check( '  for that product alone: another listing showing the picture downloads it, never handed the shop\'s photo', array( $GLOBALS['DOWNLOADS'], get_post_thumbnail_id( $X ) === $sp2[2] ), array( array( $newpic ), false ) );

// A number known on another photo, at the first read: the places are not trusted.
list( $K1, $k1 ) = imported( 'Known elsewhere', 'k1', 3 );
push_eps( $K1, 'ek' );
$sent = push_site( $K1, 'ck' );
$GLOBALS['DOWNLOADS'] = array();
sync( $K1, array( ebay_g( num( 'ek', $k1[1] ) ), ebay_g( num( 'ek', $k1[0] ) ), $sent[2] ) );
check( 'a place showing a number another of its photos is on eBay as: nothing learnt, the unknown one downloaded', array( in_array( num( 'ek', $k1[1] ), numbers_of( $k1[0] ), true ), numbers_of( $k1[2] ) === array( num( 'ek', $k1[2] ) ), $GLOBALS['DOWNLOADS'] ), array( false, true, array( $sent[2] ) ) );
list( $K2, $k2 ) = imported( 'Known by another product', 'k2', 2 );
$sent = push_site( $K2, 'cl' );
$GLOBALS['DOWNLOADS'] = array();
sync( $K2, array( $sent[0], ebay_z( num( 'cs', $sp[0] ) ) ) );
check( '  one learnt by place on another product\'s photo: nothing learnt - and, nothing it knows among them, held', array( numbers_of( $k2[0] ), numbers_of( $k2[1] ), count( $GLOBALS['DOWNLOADS'] ), in_array( $K2, held(), true ) ), array( array(), array(), 0, true ) );

// A variation's picture is not one a push sends: its places are the others'.
list( $V, $vp ) = imported( 'Variations', 'v1', 2 );
$vv   = new_post( 'product_variation', 'publish', $V, 'Variation' );
$sent = push_site( $V, 'cv' );
$GLOBALS['DOWNLOADS'] = array();
$vpic = ebay_z( num( 'vv', 1 ) );
sync( $V, array_merge( $sent, array( array( $vpic, $vv ) ) ) );
check( 'a listing with a variation\'s picture after the photos sent: their places learnt, the variation\'s downloaded', array( $GLOBALS['DOWNLOADS'], numbers_of( $vp[1] ) ), array( array( $vpic ), array( num( 'cv', $vp[1] ) ) ) );

// An eBay stand-in among the photos sent: the places are not trusted.
$W1 = new_post( 'product', 'publish', 0, 'Stand-in sent' );
update_post_meta( $W1, '_ebay_item_id', '1100' . $W1 );
settings( array( 'disable_image_localization' => '1' ) );
sync( $W1, array( ebay_z( num( 'ws', 1 ) ), ebay_z( num( 'ws', 2 ) ) ) );
settings( array() );
$wi = shown( $W1 );
record( $W1, $wi, array( ebay_z( num( 'ws', 1 ) ), 'https://shop.example/wp-content/uploads/2026/10/stand-in.jpg' ) );
update_post_meta( $W1, '_ebay_export_last_pushed', pushed_on() );
$GLOBALS['DOWNLOADS'] = array();
sync( $W1, array( ebay_z( num( 'ws', 1 ) ), ebay_z( num( 'wc', 2 ) ) ) );
check( 'an eBay stand-in among the photos the push sent: nothing learnt by place - its pictures downloaded', array( numbers_of( $wi[1] ), count( $GLOBALS['DOWNLOADS'] ) ), array( array(), 2 ) );

// Push and sync, again and again, eBay hosting on and off between pushes.
foreach ( array( 'off' => array(), 'on' => array( 'overwrite_images' => '1' ) ) as $ow => $s ) {
	fresh_db();
	TCGiant_Sync_Image_Localizer::instance();
	settings( $s );
	list( $C, $cp ) = imported( 'C1 ' . $ow, 'c1', 3 );
	$GLOBALS['DOWNLOADS'] = array();
	sync( $C, push_site( $C, 'n1' ) );
	sync( $C, push_site( $C, 'n2' ) );
	sync( $C, push_eps( $C, 'e1' ) );
	sync( $C, push_site( $C, 'n3' ) );
	sync( $C, push_eps( $C, 'e2' ) );
	check( "Overwrite $ow: five pushes and syncs, eBay hosting on and off: nothing downloaded, its photos as they were", array( $GLOBALS['DOWNLOADS'], shown( $C ) ), array( array(), $cp ) );
	set_post_thumbnail( $C, $cp[1] );
	update_post_meta( $C, '_product_image_gallery', $cp[0] . ',' . $cp[2] );
	sync( $C, push_site( $C, 'n4' ) );
	check( '  its photos reordered on the website, pushed from it, synced: nothing downloaded, the shop\'s order kept, each number on its own photo', array( $GLOBALS['DOWNLOADS'], shown( $C ), in_array( num( 'n4', $cp[1] ), numbers_of( $cp[1] ), true ), in_array( num( 'n4', $cp[1] ), numbers_of( $cp[0] ), true ) ), array( array(), array( $cp[1], $cp[0], $cp[2] ), true, false ) );
}
settings( array() );

echo "\nUNDAMAGED PRODUCTS, AND ANOTHER PRODUCT'S PHOTOS\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
$kept = array();
foreach ( array( false, true ) as $pushed ) {
	list( $U, $u ) = imported( 'Main in gallery', 'u' . (int) $pushed, 3 );
	set_shown( $U, $u[0], array( $u[0], $u[1], $u[2] ) );
	list( $V, $v ) = imported( 'Spaced gallery', 'v' . (int) $pushed, 3 );
	update_post_meta( $V, '_product_image_gallery', $v[1] . ', ' . $v[2] . ',' );
	list( $W2, $w ) = imported( 'Own upload main', 'w' . (int) $pushed, 2 );
	$own = new_post( 'attachment', 'inherit', $W2, 'shop photo', 1 );
	add_post_meta( $own, '_wp_attached_file', '2026/10/shop-own-' . $own . '.jpg' );
	set_shown( $W2, $own, array( $own, $w[0], $w[1], $w[0] ) );
	foreach ( array( $U, $V, $W2 ) as $pid ) {
		if ( $pushed ) {
			push_eps( $pid, 'z' . $pid, null, false );
		}
		$kept[ $pid ] = array( (string) get_post_meta( $pid, '_thumbnail_id', true ), gallery_of( $pid ) );
	}
}
$GLOBALS['LOG'] = array();
repair();
$now = array();
foreach ( $kept as $pid => $was ) {
	$now[ $pid ] = array( (string) get_post_meta( $pid, '_thumbnail_id', true ), gallery_of( $pid ) );
}
check( 'pushed or not, a gallery with no copy in it is never written: main photo in it, a repeat, spaces, a trailing comma', $now, $kept );
check( '  and none counted as put right', (bool) preg_match( '/^Own photos: 0 of 6 /', logged( 'copy that came back' )[0] ?? '' ), true );

// Another product's upload: a listing made on eBay with "Sell similar".
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
list( $A, $a ) = imported( 'Product A', 'ia', 1 );
push_eps( $A, 'ua', null, false );
$B2 = array();
foreach ( array( false, true ) as $pushed ) {
	$B  = new_post( 'product', 'publish', 0, 'Sold similar' );
	update_post_meta( $B, '_ebay_item_id', '2200' . $B );
	$db = copy_of( $B, num( 'ua', $a[0] ) );
	add_post_meta( $B, '_tcgiant_external_image_urls', array( ebay_g( num( 'ua', $a[0] ) ) ) );
	set_shown( $B, $db, array() );
	if ( $pushed ) {
		update_post_meta( $B, '_ebay_export_last_pushed', pushed_on() );
	}
	$B2[ $B ] = $db;
}
repair();
$mains = array();
foreach ( $B2 as $B => $db ) {
	$mains[] = get_post_thumbnail_id( $B ) === $db;
}
check( 'a product showing its own download of another product\'s upload, pushed or not: kept, never given that product\'s photo', $mains, array( true, true ) );
check( '  nor is its download a copy Image Cleanup may take', $HAS_OWN ? TCGiant_Sync_Own_Photos::copies_among( array_values( $B2 ) ) : null, array() );

// A copy shown as the main photo and in the gallery too, as the shop had its
// main photo: the photo itself in both places, once in the gallery.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
list( $MG, $mg ) = imported( 'Main in gallery, a copy', 'mg', 2 );
push_eps( $MG, 'qm', null, false );
$cmg = copy_of( $MG, num( 'qm', $mg[0] ) );
set_shown( $MG, $cmg, array( $cmg, $mg[1] ) );
list( $MS, $ms ) = imported( 'A copy as the main photo, a gallery saved with spaces', 'ms', 3 );
push_eps( $MS, 'qs', null, false );
$cms = copy_of( $MS, num( 'qs', $ms[0] ) );
set_post_thumbnail( $MS, $cms );
update_post_meta( $MS, '_product_image_gallery', $ms[1] . ', ' . $ms[2] );
list( $MH, $mh ) = imported( 'Main in gallery, a copy, the photo too', 'mh', 2 );
push_eps( $MH, 'qh', null, false );
$cmh = copy_of( $MH, num( 'qh', $mh[0] ) );
set_shown( $MH, $cmh, array( $cmh, $mh[0], $mh[1], $mh[1] ) );
repair();
check( 'a copy as the main photo and in the gallery: the photo itself in both', array( get_post_thumbnail_id( $MG ), gallery_of( $MG ) ), array( $mg[0], $mg[0] . ',' . $mg[1] ) );
check( '  the photo itself in the gallery as well: there once; a repeat the shop made stays', array( get_post_thumbnail_id( $MH ), gallery_of( $MH ) ), array( $mh[0], $mh[0] . ',' . $mh[1] . ',' . $mh[1] ) );
check( '  only the main photo a copy: the gallery, with no copy in it, not written, as it was saved', array( get_post_thumbnail_id( $MS ), gallery_of( $MS ) ), array( $ms[0], $ms[1] . ', ' . $ms[2] ) );

// A photo that could not stand in its copy's place.
foreach ( array( 'has no file on record', 'has lost its file on this server', 'is in the media trash' ) as $n => $why ) {
	fresh_db();
	TCGiant_Sync_Image_Localizer::instance();
	settings( array() );
	old_passes_done();
	list( $F, $f ) = imported( 'Original ' . $why, 'if' . $n, 2 );
	push_eps( $F, 'nf' . $n, null, false );
	$cf = copy_of( $F, num( 'nf' . $n, $f[0] ) );
	set_shown( $F, $cf, array( $f[1] ) );
	if ( 0 === $n ) {
		delete_post_meta( $f[0], '_wp_attached_file' );
	} elseif ( 1 === $n ) {
		unlink( $GLOBALS['WORLD'] . '/uploads/' . get_post_meta( $f[0], '_wp_attached_file', true ) );
	} else {
		q( "UPDATE wp_posts SET post_status = 'trash' WHERE ID = {$f[0]}" );
	}
	repair();
	check( 'its photo ' . $why . ': the copy stays its main photo, and is no copy Image Cleanup may take', array( get_post_thumbnail_id( $F ), $HAS_OWN ? TCGiant_Sync_Own_Photos::copies_among( array( $cf ) ) : null ), array( $cf, array() ) );
}

echo "\nA PRODUCT WITH NO MAIN PHOTO OF ITS OWN TAKES THE FIRST IT GETS, AS BEFORE\n" . str_repeat( '=', 124 ) . "\n";

foreach ( array( 'off' => array(), 'on' => array( 'overwrite_images' => '1' ) ) as $ow => $s ) {
	fresh_db();
	TCGiant_Sync_Image_Localizer::instance();
	settings( array_merge( $s, array( 'disable_image_localization' => '1' ) ) );
	$N = new_post( 'product', 'publish', 0, 'Stand-ins ' . $ow );
	update_post_meta( $N, '_ebay_item_id', '1100' . $N );
	sync( $N, array( ebay_z( num( 'n4', 1 ) ), ebay_z( num( 'n4', 2 ) ) ) );
	settings( $s );
	$GLOBALS['REFUSE'] = array( num( 'n4', 1 ) => new WP_Error( 'http_404', 'Not Found', array( 'code' => 404 ) ) );
	run_localizer();
	$main = get_post_thumbnail_id( $N );
	check( "Overwrite $ow: eBay stand-ins, the first picture gone (404): the second becomes its main photo", array( (string) get_post_meta( $main, '_tcgiant_external_url', true ), (string) get_post_meta( $main, '_tcgiant_source_url', true ) ), array( '', preg_replace( '/\?.*$/', '', ebay_z( num( 'n4', 2 ) ) ) ) );
	$M = new_post( 'product', 'publish', 0, 'No photo ' . $ow );
	update_post_meta( $M, '_ebay_item_id', '1100' . $M );
	sync( $M, array( ebay_z( num( 'n4', 1 ) ), ebay_z( num( 'n5', 2 ) ) ) );
	check( '  a new product, its first picture gone: the second its main photo', (string) get_post_meta( get_post_thumbnail_id( $M ), '_tcgiant_source_url', true ), preg_replace( '/\?.*$/', '', ebay_z( num( 'n5', 2 ) ) ) );
	$GLOBALS['REFUSE'] = array();
}
settings( array() );

echo "\nA PRODUCT THE IMAGE BATCH IS WORKING ON\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
list( $L, $lp ) = imported( 'Being localized', 'lz', 2 );
push_eps( $L, 'lq', null, false );
$cl = copy_of( $L, num( 'lq', $lp[0] ) );
set_shown( $L, $lp[0], array( $lp[1], $cl ) );
update_post_meta( $L, '_tcgiant_localize_inflight', 1 );
update_post_meta( $L, '_tcgiant_localize_retry_after', time() + 600 );
repair();
$heal_record = $HAS_OWN ? get_option( TCGiant_Sync_Own_Photos::HEAL_OPTION ) : array();
check( 'left until the batch has finished with it, the pass waiting for it', array( shown( $L ), empty( $heal_record['done'] ), count( $GLOBALS['LATER'] ) ), array( array( $lp[0], $lp[1], $cl ), true, 1 ) );
delete_post_meta( $L, '_tcgiant_localize_inflight' );
later_comes();
run_queue();
$heal_record = $HAS_OWN ? get_option( TCGiant_Sync_Own_Photos::HEAL_OPTION ) : array();
check( '  then put right, and the pass done', array( shown( $L ), ! empty( $heal_record['done'] ) ), array( $lp, true ) );

echo "\nA PRODUCT THE HOST STOPS THE PASS ON, EVERY TIME\n" . str_repeat( '=', 124 ) . "\n";

fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
$damaged = array();
$copies  = array();
for ( $k = 0; $k < 5; $k++ ) {
	list( $pk, $pp ) = imported( 'Standing Liberty ' . $k, 'sl' . $k, 2 );
	push_eps( $pk, 'zl' . $k, null, false );
	$copies[ $pk ] = copy_of( $pk, num( 'zl' . $k, $pp[0] ) );
	set_shown( $pk, $pp[0], array( $pp[1], $copies[ $pk ] ) );
	$damaged[ $pk ] = $pp;
}
$target = array_keys( $damaged )[2];
admin_page();
$codes = array();
for ( $round = 0; $round < 2; $round++ ) {
	file_put_contents( $WORLD . '/queue.json', json_encode( $GLOBALS['QUEUE'] ) );
	$GLOBALS['wpdb']->db->close();
	$out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child=killon:' . $target . ' 2>&1', $out, $code );
	$codes[] = $code;
	open_db();
	$GLOBALS['QUEUE'] = json_decode( (string) file_get_contents( $WORLD . '/queue.json' ), true );
	foreach ( $GLOBALS['QUEUE'] as $q => $a ) {
		if ( 'running' === $a['status'] ) {
			$GLOBALS['QUEUE'][ $q ]['status'] = 'failed';
		}
	}
	admin_page();
}
$GLOBALS['LOG'] = array();
run_queue();
$right = array();
foreach ( $damaged as $pk => $pp ) {
	$right[] = shown( $pk ) === ( $pk === $target ? array( $pp[0], $pp[1], $copies[ $pk ] ) : $pp );
}
$heal_record = $HAS_OWN ? get_option( TCGiant_Sync_Own_Photos::HEAL_OPTION ) : array();
check( 'stopped twice on the same product: the pass goes on past it, finishes, and every other product is put right', array( $codes, $right, ! empty( $heal_record['done'] ), $heal_record['skipped'] ?? null ), array( array( 9, 9 ), array_fill( 0, 5, true ), true, array( $target ) ) );
check( '  and names it for the shop', count( logged( sprintf( 'were passed over, as the host stopped the pass twice while it was working on each: WC #%d.', $target ) ) ), 1 );

// The same for a product it waited for, the image batch done with it.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
$damaged = array();
$copies  = array();
for ( $k = 0; $k < 2; $k++ ) {
	list( $pk, $pp ) = imported( 'Barber Quarter ' . $k, 'bq' . $k, 2 );
	push_eps( $pk, 'zq' . $k, null, false );
	$copies[ $pk ] = copy_of( $pk, num( 'zq' . $k, $pp[0] ) );
	set_shown( $pk, $pp[0], array( $pp[1], $copies[ $pk ] ) );
	$damaged[ $pk ] = $pp;
}
$target = array_keys( $damaged )[1];
update_post_meta( $target, '_tcgiant_localize_inflight', 1 );
update_post_meta( $target, '_tcgiant_localize_retry_after', time() + 600 );
repair();
delete_post_meta( $target, '_tcgiant_localize_inflight' );
later_comes();
$codes = array();
for ( $round = 0; $round < 2; $round++ ) {
	file_put_contents( $WORLD . '/queue.json', json_encode( $GLOBALS['QUEUE'] ) );
	$GLOBALS['wpdb']->db->close();
	$out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child=killon:' . $target . ' 2>&1', $out, $code );
	$codes[] = $code;
	open_db();
	$GLOBALS['QUEUE'] = json_decode( (string) file_get_contents( $WORLD . '/queue.json' ), true );
	foreach ( $GLOBALS['QUEUE'] as $q => $a ) {
		if ( 'running' === $a['status'] ) {
			$GLOBALS['QUEUE'][ $q ]['status'] = 'failed';
		}
	}
	admin_page();
}
run_queue();
$heal_record = $HAS_OWN ? get_option( TCGiant_Sync_Own_Photos::HEAL_OPTION ) : array();
$first       = array_keys( $damaged )[0];
check( '  the same for a product it waited for: passed after two stops there, the pass done', array( $codes, shown( $first ) === $damaged[ $first ], shown( $target ), ! empty( $heal_record['done'] ), $heal_record['skipped'] ?? null ), array( array( 9, 9 ), true, array( $damaged[ $target ][0], $damaged[ $target ][1], $copies[ $target ] ), true, array( $target ) ) );

echo "\nGETITEM'S TWO SHAPES FOR A LISTING PUSHED FROM THE WEBSITE\n" . str_repeat( '=', 124 ) . "\n";

// GetItem gives eBay's copy of each picture ("one per picture"), or of every
// picture but the first, whose original it gives in ExternalPictureURL, which
// the import does not read ("one fewer"). Either way, a shop's own photos
// never come back.
foreach ( array( 'one per picture' => 0, 'one fewer' => 1 ) as $shape => $skip ) {
	foreach ( array( 'off' => array(), 'on' => array( 'overwrite_images' => '1' ) ) as $ow => $s ) {
		fresh_db();
		TCGiant_Sync_Image_Localizer::instance();
		settings( $s );
		list( $F, $fp ) = imported( "Five cycles $shape $ow", 'fc' . $skip . $ow, 3 );
		$GLOBALS['DOWNLOADS'] = array();
		$shows                = array();
		for ( $cycle = 1; $cycle <= 5; $cycle++ ) {
			$sent = push_site( $F, 'wc' . $skip . $cycle );
			sync( $F, array_slice( $sent, $skip ) );
			$shows[] = count( shown( $F ) );
		}
		check( "$shape, Overwrite $ow: five pushes from the website, each synced - nothing downloaded, its 3 photos throughout", array( $GLOBALS['DOWNLOADS'], $shows, shown( $F ) ), array( array(), array( 3, 3, 3, 3, 3 ), $fp ) );
		check( '  each push\'s first read ' . ( $skip ? 'teaches nothing, and the listing is held' : 'learns its places' ), $skip ? in_array( $F, held(), true ) : in_array( num( 'wc' . $skip . '5', $fp[1] ), numbers_of( $fp[1] ), true ), true );
	}
}

// The held product pushed again from the website (the push falling back to
// it, past the ceiling), then synced.
foreach ( array( 'one per picture' => 0, 'one fewer' => 1 ) as $shape => $skip ) {
	fresh_db();
	TCGiant_Sync_Image_Localizer::instance();
	settings( array() );
	list( $Q, $qp ) = imported( 'Held, pushed again ' . $shape, 'q7' . $skip, 3 );
	sync( $Q, array_slice( pushed_unrecorded( $Q, 'y7' . $skip ), $skip ) );
	$was_held             = in_array( $Q, held(), true );
	$GLOBALS['DOWNLOADS'] = array();
	sync( $Q, array_slice( push_site( $Q, 'z7' . $skip ), $skip ) );
	check( "$shape: a held product pushed again from the website and synced - nothing downloaded, its 3 photos", array( $was_held, $GLOBALS['DOWNLOADS'], shown( $Q ) ), array( true, array(), $qp ) );
}

// The 2026-11-03 damage already downloaded, both shapes: named for a look,
// never as "another photo first", whose push would send the copies.
foreach ( array( 'one per picture' => 0, 'one fewer' => 1 ) as $shape => $skip ) {
	foreach ( array( 'behind its photos' => false, 'in their place' => true ) as $where => $in_place ) {
		fresh_db();
		TCGiant_Sync_Image_Localizer::instance();
		settings( $in_place ? array( 'overwrite_images' => '1' ) : array() );
		old_passes_done();
		list( $T5, $t5 ) = imported( "Tail $shape $where", 't5' . $skip . (int) $in_place, 3 );
		$after = array_slice( pushed_unrecorded( $T5, 'x5' . $skip . (int) $in_place ), $skip );
		$cps   = array();
		foreach ( $after as $u ) {
			$cps[] = copy_of( $T5, preg_match( '#/z/([^/]+)/#', $u, $mm ) ? $mm[1] : '' );
		}
		if ( $in_place ) {
			set_shown( $T5, $cps[0], array_slice( $cps, 1 ) );
		} else {
			set_shown( $T5, $t5[0], array_merge( array_slice( $t5, 1 ), $cps ) );
		}
		update_post_meta( $T5, '_ebay_live', array( 'item_id' => (string) get_post_meta( $T5, '_ebay_item_id', true ), 'pictures' => array_map( function ( $u ) { return preg_replace( '/\?.*$/', '', $u ); }, $after ) ) );
		update_post_meta( $T5, '_ebay_listing_status', 'Active' );
		update_post_meta( $T5, '_tcgiant_external_image_urls', $after );
		$before = shown( $T5 );
		repair();
		$rec = $HAS_OWN ? get_option( TCGiant_Sync_Own_Photos::HEAL_OPTION ) : array();
		check( "$shape, copies $where: named for a look, never as showing another photo first; nothing changed", array( in_array( $T5, array_keys( (array) ( $rec['tails'] ?? array() ) ), true ), in_array( $T5, (array) ( $rec['wrong'] ?? array() ), true ), shown( $T5 ) ), array( true, false, $before ) );
	}
}

// A photo added in Seller Hub before the first read, under "one fewer": the
// counts then match, and each place is taken one off - for this product alone
// (the known limit until place 1 can be anchored on ExternalPictureURL).
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
list( $E, $ep ) = imported( 'Added before the read, one fewer', 'e13', 3 );
$sent  = push_site( $E, 'c13' );
$added = ebay_g( num( 'hub13', 1 ) );
$GLOBALS['DOWNLOADS'] = array();
sync( $E, array_merge( array_slice( $sent, 1 ), array( $added ) ) );
check( 'known limit: one fewer, a photo added in Seller Hub before the first read - nothing downloaded, each place taken one off', array( $GLOBALS['DOWNLOADS'], in_array( num( 'c13', $ep[1] ), numbers_of( $ep[0] ), true ), in_array( num( 'hub13', 1 ), numbers_of( $ep[2] ), true ) ), array( array(), true, true ) );
$X13 = new_post( 'product', 'publish', 0, 'Another listing showing the added photo' );
update_post_meta( $X13, '_ebay_item_id', '1100' . $X13 );
sync( $X13, array( $added ) );
check( '  for that product alone: another listing showing the added photo downloads it', array( $GLOBALS['DOWNLOADS'], get_post_thumbnail_id( $X13 ) === $ep[2] ), array( array( $added ), false ) );

// A page read before a push, saved after it, is no read after the push.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
list( $G6, $g6 ) = imported( 'A stale read', 'g6', 3 );
$first = push_site( $G6, 'a6' );
sync( $G6, $first );
$GLOBALS['DOWNLOADS'] = array();
$second = push_site( $G6, 'b6' );
TCGiant_Sync_Image_Localizer::set_external_images( $G6, $first );
run_localizer();
check( 'the listing as the import already holds it, read after a push: the record is not spent', empty( get_post_meta( $G6, PUSHED_META, true )['read'] ), true );
sync( $G6, $second );
check( '  the read after the push learns its places: nothing downloaded, its 3 photos', array( $GLOBALS['DOWNLOADS'], shown( $G6 ), in_array( num( 'b6', $g6[2] ), numbers_of( $g6[2] ), true ) ), array( array(), $g6, true ) );

// A gallery holding a repeat and a stale 0, pushed from the website.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
list( $L3, $l3 ) = imported( 'A repeat and a 0', 'l3', 3 );
update_post_meta( $L3, '_product_image_gallery', $l3[1] . ',0,' . $l3[2] . ',' . $l3[1] );
$sent = push_site( $L3, 'c3' );
$GLOBALS['DOWNLOADS'] = array();
sync( $L3, $sent );
check( 'a gallery with a repeat and a stale 0, pushed from the website: its places learnt, nothing downloaded', array( $GLOBALS['DOWNLOADS'], in_array( num( 'c3', $l3[2] ), numbers_of( $l3[2] ), true ) ), array( array(), true ) );

// Every picture replaced in Seller Hub after the first read taught the
// places: genuinely new, they come in, and nothing is taken by place again.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
list( $AR, $ar ) = imported( 'All replaced after the read', 'ar', 2 );
$sent = push_site( $AR, 'cw' );
sync( $AR, $sent );
$new = array( ebay_g( num( 'hubar', 1 ) ), ebay_g( num( 'hubar', 2 ) ) );
$GLOBALS['DOWNLOADS'] = array();
sync( $AR, $new );
check( 'every picture replaced in Seller Hub after the first read: downloaded, none taken for a photo by place', array( $GLOBALS['DOWNLOADS'], in_array( num( 'hubar', 1 ), numbers_of( $ar[0] ), true ), in_array( $AR, held(), true ) ), array( $new, false, false ) );

// Under "one fewer", held; then a photo added on eBay, the count now the
// photos': the held pictures are known, and the added one comes in.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
list( $HA, $ha ) = imported( 'Held one fewer, then one added', 'ha', 3 );
$sent = push_site( $HA, 'cy' );
sync( $HA, array_slice( $sent, 1 ) );
$added = ebay_g( num( 'hubha', 1 ) );
$GLOBALS['DOWNLOADS'] = array();
sync( $HA, array_merge( array_slice( $sent, 1 ), array( $added ) ) );
check( 'held under "one fewer", then a photo added on eBay: the pictures held are known, the added one comes in', array( $GLOBALS['DOWNLOADS'], count( shown( $HA ) ) ), array( array( $added ), 4 ) );

// A download nothing recognises of the listing's first picture, not in the
// shape of copies: not taken for one of the product's photos shown first.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
list( $UF, $uf ) = imported( 'An odd download first', 'uf', 2 );
$extra = new_post( 'attachment', 'inherit', $UF, 'the shop\'s own', 1 );
add_post_meta( $extra, '_wp_attached_file', '2026/10/uf-extra.jpg' );
file_put_contents( $GLOBALS['WORLD'] . '/uploads/2026/10/uf-extra.jpg', 'JPEG' );
$afterf = pushed_unrecorded( $UF, 'xf' );
$cf1    = copy_of( $UF, preg_match( '#/z/([^/]+)/#', $afterf[0], $mm ) ? $mm[1] : '' );
set_shown( $UF, $uf[0], array( $uf[1], $extra, $cf1 ) );
update_post_meta( $UF, '_ebay_live', array( 'item_id' => (string) get_post_meta( $UF, '_ebay_item_id', true ), 'pictures' => array_map( function ( $u ) { return preg_replace( '/\?.*$/', '', $u ); }, $afterf ) ) );
update_post_meta( $UF, '_ebay_listing_status', 'Active' );
repair();
$rec = $HAS_OWN ? get_option( TCGiant_Sync_Own_Photos::HEAL_OPTION ) : array();
check( 'a download nothing recognises of the listing\'s first picture is not taken for a photo of its own shown first', in_array( $UF, (array) ( $rec['wrong'] ?? array() ), true ), false );

echo "\nTHE HOLD DOES NOT TRAP EBAY'S OWN PHOTOS\n" . str_repeat( '=', 124 ) . "\n";

foreach ( array( 'off' => array(), 'on' => array( 'overwrite_images' => '1' ) ) as $ow => $s ) {
	foreach ( array( 1, 3 ) as $count ) {
		fresh_db();
		TCGiant_Sync_Image_Localizer::instance();
		settings( $s );
		// An imported product Updated with its eBay photos kept, under 3.30.4.
		list( $U, $up ) = imported( "Updated, photos kept, $ow $count", 'uk' . $count, $count );
		update_post_meta( $U, '_ebay_export_last_pushed', pushed_on() );
		if ( $HAS_OWN ) {
			TCGiant_Sync_Own_Photos::record_push( $U, (string) get_post_meta( $U, '_ebay_item_id', true ), array( 'kept' => true ) );
		}
		$new = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$new[] = ebay_g( num( 'sk' . $count, $i ) );
		}
		$GLOBALS['DOWNLOADS'] = array();
		sync( $U, $new );
		$got  = (int) $GLOBALS['wpdb']->get_var( 'SELECT MAX(ID) FROM wp_posts WHERE post_type = \'attachment\'' );
		$want = $s ? $got - $count + 1 : $up[0];
		check( "Overwrite $ow, Updated with its eBay photos kept, its $count photo(s) replaced on eBay: they come in" . ( $s ? ', the first its main photo, as before' : ', to the end of the gallery, as before' ), array( count( $GLOBALS['DOWNLOADS'] ), get_post_thumbnail_id( $U ), in_array( $U, held(), true ) ), array( $count, $want, false ) );
	}

	// Pushed long ago, or lately, with no 3.30.4 record (a 3.30.3 Update, or a
	// 3.30.3 push with eBay hosting): every photo replaced on eBay.
	foreach ( array( 'Updated by 3.30.3, its photos kept' => false, 'pushed by 3.30.3 with eBay hosting' => true ) as $what => $eps ) {
		foreach ( array( 40, 1 ) as $days ) {
			fresh_db();
			TCGiant_Sync_Image_Localizer::instance();
			settings( $s );
			list( $U, $up ) = imported( "$what $ow $days", 'ul' . (int) $eps . $days, 2 );
			if ( $eps ) {
				sync( $U, push_eps( $U, 'eb' . $days, null, false ) );
			}
			update_post_meta( $U, '_ebay_export_last_pushed', pushed_on( $days ) );
			$GLOBALS['DOWNLOADS'] = array();
			$GLOBALS['LOG']       = array();
			sync( $U, array( ebay_g( num( 'sl' . $days, 1 ) ), ebay_g( num( 'sl' . $days, 2 ) ) ) );
			if ( $days > 30 ) {
				check( "Overwrite $ow, $what $days days ago, both photos replaced on eBay: they come in, as before", array( count( $GLOBALS['DOWNLOADS'] ), in_array( $U, held(), true ) ), array( 2, false ) );
			} else {
				check( "Overwrite $ow, $what yesterday, both photos replaced on eBay: held within " . ( defined( 'TCGiant_Sync_Own_Photos::HOLD_DAYS' ) ? TCGiant_Sync_Own_Photos::HOLD_DAYS : 30 ) . ' days of a push, the shop told to check the listing first', array( count( $GLOBALS['DOWNLOADS'] ), shown( $U ), in_array( $U, held(), true ), count( logged( 'or photos changed on eBay. None was downloaded, and its photos are as they were. Check the listing on eBay first. If they are photos you changed on eBay, run an images-only sync for its item to bring them in. Push it only if eBay shows the product\'s photos wrongly: a push replaces eBay\'s photos with the product\'s.' ) ) ), array( 0, $up, true, 1 ) );
				// The way out: an images-only sync of the item (start_images_only_sync()).
				$GLOBALS['DOWNLOADS'] = array();
				if ( method_exists( 'TCGiant_Sync_Own_Photos', 'release' ) ) {
					TCGiant_Sync_Own_Photos::release( $U );
				}
				TCGiant_Sync_Image_Localizer::set_external_images( $U, array( ebay_g( num( 'sl' . $days, 1 ) ), ebay_g( num( 'sl' . $days, 2 ) ) ) );
				run_localizer();
				check( '  an images-only sync of its item then brings them in, and they stay in', array( count( $GLOBALS['DOWNLOADS'] ), in_array( $U, held(), true ), count( shown( $U ) ) ), array( 2, false, 4 ) );
			}
		}
	}
}

// A picture held is never downloaded for the product, whatever comes later;
// a photo added on eBay afterwards does come in.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
list( $HH, $hh ) = imported( 'Held, then a photo added on eBay', 'hh', 3 );
$after = pushed_unrecorded( $HH, 'wh' );
sync( $HH, $after );
$added = ebay_g( num( 'hubh', 1 ) );
$GLOBALS['DOWNLOADS'] = array();
sync( $HH, array_merge( $after, array( $added ) ) );
check( 'held, then a photo added on eBay: that one comes in, the pictures held do not', array( $GLOBALS['DOWNLOADS'], count( shown( $HH ) ) ), array( array( $added ), 4 ) );
update_post_meta( $HH, '_ebay_export_last_pushed', pushed_on( 40 ) );
$GLOBALS['DOWNLOADS'] = array();
TCGiant_Sync_Image_Localizer::set_external_images( $HH, array_merge( $after, array( $added ) ), true );
run_localizer();
check( '  and past the hold\'s days, an images-only sync still downloads none of the pictures held', array( $GLOBALS['DOWNLOADS'], count( shown( $HH ) ) ), array( array(), 4 ) );

// Stand-ins in the gallery are photos still to come, not copies.
foreach ( array( 'off' => array(), 'on' => array( 'overwrite_images' => '1' ) ) as $ow => $s ) {
	fresh_db();
	TCGiant_Sync_Image_Localizer::instance();
	$Z = new_post( 'product', 'publish', 0, 'Gallery stand-ins ' . $ow );
	update_post_meta( $Z, '_ebay_item_id', '1100' . $Z );
	settings( $s + array( 'disable_image_localization' => '1' ) );
	$orig = array( ebay_z( num( 'z3', 1 ) ), ebay_z( num( 'z3', 2 ) ), ebay_z( num( 'z3', 3 ) ) );
	sync( $Z, $orig );
	$main_local = new_post( 'attachment', 'inherit', $Z, 'main', 0 );
	add_post_meta( $main_local, '_tcgiant_source_url', preg_replace( '/\?.*$/', '', $orig[0] ) );
	add_post_meta( $main_local, '_wp_attached_file', '2026/10/z3-main-' . $ow . '.jpg' );
	file_put_contents( $GLOBALS['WORLD'] . '/uploads/2026/10/z3-main-' . $ow . '.jpg', 'JPEG' );
	set_post_thumbnail( $Z, $main_local );
	update_post_meta( $Z, '_tcgiant_images_localized', 1 );
	settings( $s );
	update_post_meta( $Z, '_ebay_export_last_pushed', pushed_on() );
	update_post_meta( $Z, '_ebay_item_id', '2200' . $Z );
	$GLOBALS['DOWNLOADS'] = array();
	sync( $Z, array( ebay_g( num( 'z3', 2 ) ), ebay_g( num( 'z3', 3 ) ) ) );
	$left = 0;
	foreach ( array_filter( explode( ',', gallery_of( $Z ) ) ) as $gid ) {
		$left += get_post_meta( (int) $gid, '_tcgiant_external_url', true ) ? 1 : 0;
	}
	check( "Overwrite $ow: a local main photo and stand-ins in the gallery, a listing of the stand-ins' pictures - not held: they are downloaded", array( count( $GLOBALS['DOWNLOADS'] ), get_post_thumbnail_id( $Z ), $left, in_array( $Z, held(), true ) ), array( 2, $main_local, 0, false ) );
}

// A picture with no number is no copy of a photo pushed from the website.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
list( $R1, $r1 ) = imported( 'A picture with no number', 'r1', 2 );
update_post_meta( $R1, '_ebay_export_last_pushed', pushed_on() );
$plain = 'https://i.ebayimg.com/images/i/123456789012-0-1/s-l1600.jpg';
$GLOBALS['DOWNLOADS'] = array();
sync( $R1, array( ebay_g( num( 'rx', 1 ) ), $plain ) );
check( 'a listing with a picture whose address carries no number: not held, downloaded as before', array( count( $GLOBALS['DOWNLOADS'] ), in_array( $R1, held(), true ) ), array( 2, false ) );

// More than 500 held: every one counted, the first 500 named.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
if ( defined( 'TCGiant_Sync_Own_Photos::HELD_META' ) ) {
	for ( $i = 1; $i <= 500; $i++ ) {
		update_post_meta( 900000 + $i, TCGiant_Sync_Own_Photos::HELD_META, array( 'at' => time(), 'numbers' => array() ) );
	}
}
list( $H501, $h501 ) = imported( 'The 501st held', 'h501', 2 );
sync( $H501, pushed_unrecorded( $H501, 'w501' ) );
list( $first500, $all_held ) = method_exists( 'TCGiant_Sync_Own_Photos', 'held_products' ) ? TCGiant_Sync_Own_Photos::held_products( TCGiant_Sync_Own_Photos::LIST_KEEP ) : array( array(), 0 );
check( 'the 501st product held is held and counted: Image Cleanup names 500 and says how many more', array( in_array( $H501, held(), true ), count( $first500 ), $all_held ), array( true, 500, 501 ) );

echo "\nA PLACE-LEARNT NUMBER STAYS THE PRODUCT'S WHEN THE REPAIR CARRIES IT\n" . str_repeat( '=', 124 ) . "\n";

// A 3.30.4 push from the website before the repair reached a damaged product:
// its first read learnt places on the copy too. The repair carries those to
// the photo as learnt by place, never as an upload's.
fresh_db();
TCGiant_Sync_Image_Localizer::instance();
settings( array() );
old_passes_done();
list( $RP, $rp ) = imported( 'Carried by place', 'rp', 2 );
push_eps( $RP, 'qp', null, false );
$crp = copy_of( $RP, num( 'qp', $rp[0] ) );
set_shown( $RP, $rp[0], array( $rp[1], $crp ) );
$sent = push_site( $RP, 'pl' );
sync( $RP, $sent );
repair();
check( 'the copy\'s place-learnt number goes to the photo as learnt by place', array( shown( $RP ), in_array( 'place:' . num( 'pl', $crp ), get_post_meta( $rp[0], NUMBER_META ), true ), in_array( num( 'pl', $crp ), get_post_meta( $rp[0], NUMBER_META ), true ) ), array( $rp, true, false ) );
$XP = new_post( 'product', 'publish', 0, 'Another listing showing that picture' );
update_post_meta( $XP, '_ebay_item_id', '1100' . $XP );
$GLOBALS['DOWNLOADS'] = array();
sync( $XP, array( $sent[2] ) );
check( '  so another listing showing that picture downloads it, never handed the photo', array( count( $GLOBALS['DOWNLOADS'] ), get_post_thumbnail_id( $XP ) === $rp[0] ), array( 1, false ) );

echo "\nTHE DATA-SAFETY RE-REVIEW'S CASES\n" . str_repeat( '=', 124 ) . "\n";

/** A product's main photo and gallery rows, as the table holds them. */
function raw_lists( $pid ) {
	return array(
		$GLOBALS['wpdb']->get_col( 'SELECT meta_value FROM wp_postmeta WHERE post_id = ' . (int) $pid . " AND meta_key = '_thumbnail_id' ORDER BY meta_id" ),
		$GLOBALS['wpdb']->get_col( 'SELECT meta_value FROM wp_postmeta WHERE post_id = ' . (int) $pid . " AND meta_key = '_product_image_gallery' ORDER BY meta_id" ),
	);
}
function rows_of( $id ) { return $GLOBALS['wpdb']->get_col( 'SELECT meta_value FROM wp_postmeta WHERE post_id = ' . (int) $id . " AND meta_key = '" . NUMBER_META . "' ORDER BY meta_id" ); }
function begin() {
	fresh_db();
	TCGiant_Sync_Image_Localizer::instance();
	settings( array() );
	old_passes_done();
}

// Pushed, undamaged products in odd shapes: nothing written.
begin();
$odd = array();
list( $a, $ai ) = imported( 'Main in gallery, pushed', 'ra', 3 );
set_shown( $a, $ai[0], array( $ai[0], $ai[1], $ai[2] ) );
push_eps( $a, 'xa', null, false );
$odd[] = $a;
list( $b, $bi ) = imported( 'Odd gallery, pushed', 'rb', 3 );
update_post_meta( $b, '_product_image_gallery', $bi[1] . ', ' . $bi[2] . ',0,99999,' . $bi[1] . ',' );
push_eps( $b, 'xb', null, false );
$odd[] = $b;
list( $c, $ci ) = imported( 'Own upload main, repeated', 'rc', 2 );
$own = new_post( 'attachment', 'inherit', $c, 'shop photo', 1 );
add_post_meta( $own, '_wp_attached_file', '2026/10/shop-own-rc.jpg' );
file_put_contents( $GLOBALS['WORLD'] . '/uploads/2026/10/shop-own-rc.jpg', 'JPEG' );
set_shown( $c, $own, array( $own, $ci[0], $own, $ci[1] ) );
push_eps( $c, 'xc', null, false );
$odd[] = $c;
list( $d, $di ) = imported( 'Two gallery rows', 'rd', 2 );
add_post_meta( $d, '_product_image_gallery', (string) $di[0] );
push_eps( $d, 'xd', null, false );
$odd[] = $d;
$before = array();
foreach ( $odd as $pk ) {
	$before[ $pk ] = raw_lists( $pk );
}
$GLOBALS['LOG'] = array();
repair();
$after = array();
foreach ( $odd as $pk ) {
	$after[ $pk ] = raw_lists( $pk );
}
check( 'pushed, undamaged, in odd shapes (main in gallery; spaces, a 0, a stale ID, a repeat; the shop\'s upload repeated; two gallery rows): no row written, 0 of 4', array( $after, (bool) preg_match( '/^Own photos: 0 of 4 /', logged( 'copy that came back' )[0] ?? '' ) ), array( $before, true ) );

// A copy shared with other pushed products.
begin();
list( $A3, $a3 ) = imported( 'Shared copy A', 'r3a', 2 );
push_eps( $A3, 'x3', null, false );
$CA = copy_of( $A3, num( 'x3', $a3[0] ) );
set_shown( $A3, $a3[0], array( $a3[1], $CA ) );
list( $B3, $b3 ) = imported( 'Shared copy B', 'r3b', 1 );
set_shown( $B3, $CA, array( $b3[0] ) );
update_post_meta( $B3, '_ebay_export_last_pushed', pushed_on() );
list( $B4, $b4 ) = imported( 'Shared copy B2', 'r3c', 1 );
set_shown( $B4, $b4[0], array( $a3[0], $CA ) );
update_post_meta( $B4, '_ebay_export_last_pushed', pushed_on() );
repair();
check( 'a copy shared by products: put right on its own; kept by one that never showed the photo; dropped by one that shows the photo too', array( shown( $A3 ), shown( $B3 ), shown( $B4 ), $HAS_OWN ? TCGiant_Sync_Own_Photos::copies_among( array( $CA ) ) : null ), array( array( $a3[0], $a3[1] ), array( $CA, $b3[0] ), array( $b4[0], $a3[0] ), array( $CA => $a3[0] ) ) );

// The photo sent by its push, though neither attached to it nor shown now.
begin();
list( $Z4, $z4 ) = imported( 'Where the photo was uploaded', 'z4', 1 );
list( $P4b, $p4b ) = imported( 'Sent, not attached', 'p4b', 2 );
$m4 = new_post( 'attachment', 'inherit', $Z4, 'the shop\'s photo, uploaded on another product', 1 );
add_post_meta( $m4, '_wp_attached_file', '2026/10/m4.jpg' );
file_put_contents( $GLOBALS['WORLD'] . '/uploads/2026/10/m4.jpg', 'JPEG' );
set_shown( $P4b, $m4, array( $p4b[1] ) );
push_eps( $P4b, 'm4' );
$cm4 = copy_of( $P4b, num( 'm4', $m4 ) );
set_shown( $P4b, $cm4, array( $p4b[1] ) );
repair();
check( 'a photo its push sent, attached to another product and not shown now: its copy shown as it again', get_post_thumbnail_id( $P4b ), $m4 );

// Original in the media trash, attached to the product; offloaded files.
begin();
list( $T2, $t2 ) = imported( 'Original in the trash, pushed', 'r2', 2 );
push_eps( $T2, 'x2', null, false );
$C2 = copy_of( $T2, num( 'x2', $t2[0] ) );
set_shown( $T2, $C2, array( $t2[1] ) );
q( "UPDATE wp_posts SET post_status = 'trash' WHERE ID = " . (int) $t2[0] );
list( $O5, $o5 ) = imported( 'Original offloaded', 'r5', 2 );
push_eps( $O5, 'x5', null, false );
$C5 = copy_of( $O5, num( 'x5', $o5[0] ) );
set_shown( $O5, $C5, array( $o5[1] ) );
unlink( $GLOBALS['WORLD'] . '/uploads/' . get_post_meta( $o5[0], '_wp_attached_file', true ) );
list( $Q5, $q5 ) = imported( 'Both offloaded', 'r5b', 2 );
push_eps( $Q5, 'x5b', null, false );
$CQ = copy_of( $Q5, num( 'x5b', $q5[0] ) );
set_shown( $Q5, $CQ, array( $q5[1] ) );
unlink( $GLOBALS['WORLD'] . '/uploads/' . get_post_meta( $q5[0], '_wp_attached_file', true ) );
unlink( $GLOBALS['WORLD'] . '/uploads/' . get_post_meta( $CQ, '_wp_attached_file', true ) );
repair();
check( 'pushed: an original in the trash or offloaded beside a copy here is not swapped in; both offloaded, it is', array( get_post_thumbnail_id( $T2 ), get_post_thumbnail_id( $O5 ), get_post_thumbnail_id( $Q5 ) ), array( $C2, $C5, $q5[0] ) );

// A variation showing a copy, and two copies of the main photo, one as the main.
begin();
list( $V6, $v6 ) = imported( 'A variation shows a copy', 'r6', 2 );
push_eps( $V6, 'x6', null, false );
$C6 = copy_of( $V6, num( 'x6', $v6[1] ) );
$var6 = new_post( 'product_variation', 'publish', $V6, 'var' );
set_post_thumbnail( $var6, $C6 );
set_shown( $V6, $v6[0], array( $v6[1], $C6 ) );
list( $E11, $e11 ) = imported( 'Two copies of the main photo', 'r11', 2 );
push_eps( $E11, 'x11', null, false );
$C11  = copy_of( $E11, num( 'x11', $e11[0] ) );
$C11b = copy_of( $E11, num( 'x11', $e11[0] ) );
set_shown( $E11, $C11, array( $C11b, $e11[1], $C11 ) );
repair();
check( 'a variation\'s photo is left as it is; two copies of the main photo, one the main: the photo, once in the gallery where the main\'s copy stood', array( shown( $V6 ), get_post_thumbnail_id( $var6 ), get_post_thumbnail_id( $E11 ), gallery_of( $E11 ) ), array( array( $v6[0], $v6[1] ), $C6, $e11[0], $e11[1] . ',' . $e11[0] ) );

// The pass stopped after a product's gallery was written, before its main photo.
begin();
list( $R7, $r7 ) = imported( 'Stopped between the writes', 'r7b', 2 );
push_eps( $R7, 'x7b', null, false );
$C70 = copy_of( $R7, num( 'x7b', $r7[0] ) );
$C71 = copy_of( $R7, num( 'x7b', $r7[1] ) );
set_shown( $R7, $C70, array( $C70, $C71 ) );
list( $T7b, $t7b ) = imported( 'Not stopped', 'r7t', 2 );
push_eps( $T7b, 'x7t', null, false );
$T70 = copy_of( $T7b, num( 'x7t', $t7b[0] ) );
$T71 = copy_of( $T7b, num( 'x7t', $t7b[1] ) );
set_shown( $T7b, $T70, array( $T70, $T71 ) );
q( "UPDATE wp_postmeta SET meta_value = '" . $r7[0] . ',' . $r7[1] . "' WHERE post_id = " . (int) $R7 . " AND meta_key = '_product_image_gallery'" );
repair();
check( 'the pass stopped between a product\'s two writes - the gallery written first - ends where one not stopped does', array( array( get_post_thumbnail_id( $R7 ), gallery_of( $R7 ) ), array( get_post_thumbnail_id( $T7b ), gallery_of( $T7b ) ) ), array( array( $r7[0], $r7[0] . ',' . $r7[1] ), array( $t7b[0], $t7b[0] . ',' . $t7b[1] ) ) );
// And the order itself: the gallery's row is written before the main photo's.
begin();
list( $W7, $w7 ) = imported( 'The write order', 'w7', 2 );
push_eps( $W7, 'x7w', null, false );
$W70 = copy_of( $W7, num( 'x7w', $w7[0] ) );
$W71 = copy_of( $W7, num( 'x7w', $w7[1] ) );
set_shown( $W7, $W70, array( $W71 ) );
$writes = array();
$GLOBALS['wpdb']->on_query = function ( $sql ) use ( &$writes, $W7 ) {
	if ( 0 === strpos( ltrim( $sql ), 'UPDATE wp_postmeta' ) && false === strpos( $sql, 'meta_key' ) ) {
		$writes[] = false !== strpos( $sql, (string) $GLOBALS['W7_MAIN'] . "' WHERE" ) ? 'main' : 'gallery';
	}
};
$GLOBALS['W7_MAIN'] = $w7[0];
repair();
$GLOBALS['wpdb']->on_query = null;
check( '  the gallery is written before the main photo', $writes, array( 'gallery', 'main' ) );

// A number learnt by place on a photo two products show counts for the one
// that learnt it.
begin();
list( $A8, $a8 ) = imported( 'Learnt by place', 'r8a', 2 );
$after8    = push_site( $A8, 'e8' );
$after8[0] = ebay_z( num( 'sellerhub', 1 ) );
sync( $A8, $after8 );
list( $B8, $b8 ) = imported( 'Shares its photo', 'r8b', 1 );
set_shown( $B8, $b8[0], array( $a8[0] ) );
$GLOBALS['DOWNLOADS'] = array();
sync( $B8, array( ebay_z( num( 'r8b', 1 ) ), ebay_z( num( 'sellerhub', 1 ) ) ) );
check( 'a number learnt by place on a photo two products show: the other product downloads that picture, not taking the photo for it', array( in_array( 'place:' . num( 'sellerhub', 1 ), rows_of( $a8[0] ), true ), count( $GLOBALS['DOWNLOADS'] ) ), array( true, 1 ) );

// A copy carrying a place-learnt number: carried as learnt by place.
begin();
list( $P9, $p9 ) = imported( 'A copy with a place number', 'r9', 2 );
push_eps( $P9, 'x9', null, false );
$C9 = copy_of( $P9, num( 'x9', $p9[1] ) );
add_post_meta( $C9, NUMBER_META, 'place:' . num( 'pl9', 1 ) );
set_shown( $P9, $p9[0], array( $p9[1], $C9 ) );
repair();
list( $O9, $o9 ) = imported( 'Another listing', 'r9o', 1 );
$GLOBALS['DOWNLOADS'] = array();
sync( $O9, array( ebay_z( num( 'r9o', 1 ) ), ebay_z( num( 'pl9', 1 ) ) ) );
check( 'a copy\'s place-learnt number goes to its photo as learnt by place, and another listing showing it downloads it', array( rows_of( $p9[1] ), count( $GLOBALS['DOWNLOADS'] ) ), array( array( num( 'x9', $p9[1] ), 'place:' . num( 'pl9', 1 ) ), 1 ) );

// Image Cleanup removing a leftover download of another product's upload.
begin();
list( $A12, $a12 ) = imported( 'Its upload', 'r12', 1 );
push_eps( $A12, 'x12', null, false );
add_post_meta( $a12[0], NUMBER_META, num( 'x12', $a12[0] ) );
$D12 = copy_of( 0, num( 'x12', $a12[0] ) );
add_post_meta( $D12, NUMBER_META, 'place:' . num( 'dl12', 1 ) );
add_post_meta( $D12, '_tcgiant_eps_url', eps_full( num( 'du12', 1 ) ) );
$root12 = $HAS_OWN ? TCGiant_Sync_Own_Photos::root_of( $D12 ) : 0;
if ( $HAS_OWN ) {
	TCGiant_Sync_Own_Photos::carry_numbers( $D12, $root12 );
}
check( 'a leftover\'s numbers, before Image Cleanup removes it, go to the photo it copies, each as it was kept', array( $root12, rows_of( $a12[0] ) ), array( $a12[0], array( num( 'x12', $a12[0] ), 'place:' . num( 'dl12', 1 ), num( 'du12', 1 ) ) ) );

// The main photo downloaded, the gallery still eBay stand-ins, and a new
// listing that went up without the main photo (3.30.3).
begin();
$P13 = new_post( 'product', 'publish', 0, 'Half downloaded' );
update_post_meta( $P13, '_ebay_item_id', '1100' . $P13 );
$X13 = new_post( 'attachment', 'inherit', $P13, 'main', 0 );
add_post_meta( $X13, '_tcgiant_source_url', preg_replace( '/\?.*$/', '', ebay_z( num( 'r13', 1 ) ) ) );
add_post_meta( $X13, '_wp_attached_file', '2026/10/r13-main.jpg' );
file_put_contents( $GLOBALS['WORLD'] . '/uploads/2026/10/r13-main.jpg', 'JPEG' );
$S13 = array();
foreach ( array( 2, 3 ) as $n ) {
	$sid = new_post( 'attachment', 'inherit', $P13, 'stand-in', 0 );
	add_post_meta( $sid, '_tcgiant_external_url', ebay_z( num( 'r13', $n ) ) );
	add_post_meta( $sid, '_tcgiant_source_url', preg_replace( '/\?.*$/', '', ebay_z( num( 'r13', $n ) ) ) );
	$S13[] = $sid;
}
set_shown( $P13, $X13, $S13 );
update_post_meta( $P13, '_tcgiant_external_image_urls', array( ebay_z( num( 'r13', 1 ) ), ebay_z( num( 'r13', 2 ) ), ebay_z( num( 'r13', 3 ) ) ) );
update_post_meta( $P13, '_tcgiant_images_localized', 1 );
update_post_meta( $P13, '_ebay_export_last_pushed', pushed_on() );
$GLOBALS['DOWNLOADS'] = array();
sync( $P13, array( ebay_z( num( 'r13', 2 ) ), ebay_z( num( 'r13', 3 ) ) ) );
check( 'its own stand-ins\' pictures are no unknown copies: not held, downloaded, the download finished', array( count( $GLOBALS['DOWNLOADS'] ), in_array( $P13, held(), true ), get_post_thumbnail_id( $P13 ) ), array( 2, false, $X13 ) );

// A damaged product pushed from the website before the pass reached it.
foreach ( array( 'read after the pass', 'read before the pass' ) as $order ) {
	begin();
	list( $R15, $r15 ) = imported( 'Pushed before the pass, ' . $order, 'r15', 2 );
	push_eps( $R15, 'n15', null, false );
	$c15 = array();
	foreach ( $r15 as $id ) {
		$c15[] = copy_of( $R15, num( 'n15', $id ) );
	}
	set_shown( $R15, $r15[0], array( $r15[1], $c15[0], $c15[1] ) );
	$after15 = push_site( $R15, 's15' );
	if ( 'read before the pass' === $order ) {
		sync( $R15, $after15 );
	}
	repair();
	$GLOBALS['DOWNLOADS'] = array();
	sync( $R15, $after15 );
	check( "damaged, pushed from the website before the pass reached it, $order: nothing downloaded, its own photos", array( count( $GLOBALS['DOWNLOADS'] ), shown( $R15 ) ), array( 0, $r15 ) );
}

echo "\nFIX ROUND 3: THE REVIEWS' CASES\n" . str_repeat( '=', 124 ) . "\n";

/** The pass's lists for a product: [ named as may show copies, named as another photo first ]. */
function listed( $pid ) {
	$rec = $GLOBALS['HAS_OWN'] ? get_option( TCGiant_Sync_Own_Photos::HEAL_OPTION ) : array();
	return array( array_key_exists( $pid, (array) ( $rec['tails'] ?? array() ) ), in_array( $pid, (array) ( $rec['wrong'] ?? array() ), true ) );
}
/** What the import keeps of a listing read before the pass: eBay's pictures and the listing's state. */
function live( $pid, array $urls ) {
	update_post_meta( $pid, '_ebay_live', array( 'item_id' => (string) get_post_meta( $pid, '_ebay_item_id', true ), 'pictures' => array_map( function ( $u ) { return preg_replace( '/\?.*$/', '', $u ); }, $urls ) ) );
	update_post_meta( $pid, '_ebay_listing_status', 'Active' );
	update_post_meta( $pid, '_tcgiant_external_image_urls', $urls );
	update_post_meta( $pid, '_tcgiant_images_localized', 1 );
}

// Overwrite-on damage pushed from the website under 3.30.4 and read before
// the pass: the pass puts its own photos back and maps the read record
// through the copies, so the place numbers its read left count for them.
foreach ( array( 'an images-only sync', 'Overwrite turned on and off', 'a photo added on eBay' ) as $how ) {
	begin();
	list( $R, $r ) = imported( 'Overwrite-on damage, read before the pass: ' . $how, 'r16', 2 );
	push_eps( $R, 'n16', null, false );
	$c = array();
	foreach ( $r as $id ) {
		$c[] = copy_of( $R, num( 'n16', $id ) );
	}
	set_shown( $R, $c[0], array( $c[1] ) );
	$after = push_site( $R, 's16' );
	sync( $R, $after );
	repair();
	$GLOBALS['DOWNLOADS'] = array();
	if ( 'an images-only sync' === $how ) {
		if ( method_exists( 'TCGiant_Sync_Own_Photos', 'release' ) ) {
			TCGiant_Sync_Own_Photos::release( $R );
		}
		TCGiant_Sync_Image_Localizer::set_external_images( $R, $after, ! method_exists( 'TCGiant_Sync_Own_Photos', 'release' ) );
		run_localizer();
	} elseif ( 'Overwrite turned on and off' === $how ) {
		settings( array( 'overwrite_images' => '1' ) );
		sync( $R, $after );
		settings( array() );
	} else {
		sync( $R, array_merge( $after, array( ebay_g( num( 'hub16', 1 ) ) ) ) );
	}
	$want = 'a photo added on eBay' === $how ? 1 : 0;
	check( "Overwrite-on damage, pushed from the website and read before the pass, then $how: " . ( $want ? 'only the added photo' : 'nothing' ) . ' downloaded, its own photos first', array( count( $GLOBALS['DOWNLOADS'] ), array_slice( shown( $R ), 0, 2 ) ), array( $want, $r ) );
}

// Photos of the listing's own, not copies: never named as copies.
begin();
list( $Q, $qp ) = imported( 'First photo removed on eBay, pushed 60 days ago', 'q20', 2 );
update_post_meta( $Q, '_ebay_export_last_pushed', pushed_on( 60 ) );
live( $Q, array( ebay_z( num( 'q20', 2 ) ) ) );
list( $Q2, $qp2 ) = imported( 'First photo removed on eBay, pushed two days ago', 'q2b', 2 );
update_post_meta( $Q2, '_ebay_export_last_pushed', pushed_on( 2 ) );
live( $Q2, array( ebay_z( num( 'q2b', 2 ) ) ) );
repair();
check( 'a listing whose first photo was removed on eBay, its photos the import\'s: never named, nothing changed', array( listed( $Q ), listed( $Q2 ), shown( $Q ), shown( $Q2 ) ), array( array( false, false ), array( false, false ), $qp, $qp2 ) );

foreach ( array( 'behind its photos' => false, 'in their place' => true ) as $where => $in_place ) {
	begin();
	list( $Q, $qp ) = imported( 'Photos replaced on eBay weeks after a push, ' . $where, 'q14', 2 );
	update_post_meta( $Q, '_ebay_export_last_pushed', pushed_on( 60 ) );
	$new = array( ebay_z( num( 'fresh', 1 ) ), ebay_z( num( 'fresh', 2 ) ) );
	$dl  = array( copy_of( $Q, num( 'fresh', 1 ) ), copy_of( $Q, num( 'fresh', 2 ) ) );
	if ( $in_place ) {
		set_shown( $Q, $dl[0], array( $dl[1] ) );
	} else {
		set_shown( $Q, $qp[0], array( $qp[1], $dl[0], $dl[1] ) );
	}
	live( $Q, $new );
	$before = shown( $Q );
	repair();
	check( "both photos replaced on eBay 60 days after the push, downloaded $where: not named as copies, nothing changed", array( listed( $Q ), shown( $Q ) ), array( array( false, false ), $before ) );
}

// The week after the push: downloads 6 days after it are named, 8 days after not.
begin();
foreach ( array( 6 => true, 8 => false ) as $days => $named ) {
	list( $T, $t ) = imported( "Downloaded $days days after the push", 'tw' . $days, 2 );
	update_post_meta( $T, '_ebay_export_last_pushed', pushed_on( 10 ) );
	$after = array();
	$cps   = array();
	foreach ( $t as $id ) {
		$after[] = ebay_z( num( 'xw' . $days, $id ) );
		$cps[]   = copy_of( $T, num( 'xw' . $days, $id ) );
		made_on( end( $cps ), time() - ( 10 - $days ) * 86400 );
	}
	set_shown( $T, $t[0], array_merge( array( $t[1] ), $cps ) );
	live( $T, $after );
	${'T' . $days} = $T;
}
repair();
check( 'copies downloaded 6 days after the push are named; 8 days after, they are taken for the listing\'s own', array( listed( $T6 )[0], listed( $T8 )[0] ), array( true, false ) );

// A download that carries numbers of its own is recognised: not named.
begin();
list( $T, $t ) = imported( 'Downloads known by number', 'tk', 2 );
$after = pushed_unrecorded( $T, 'xk' );
$cps   = array();
foreach ( $t as $id ) {
	$cps[] = copy_of( $T, num( 'xk', $id ) );
	add_post_meta( end( $cps ), NUMBER_META, num( 'xk', $id ) );
}
set_shown( $T, $t[0], array_merge( array( $t[1] ), $cps ) );
live( $T, $after );
repair();
check( 'downloads behind its photos that are known by number: not named as copies', listed( $T )[0], false );

// A push from the website this version recorded, whose first read shows more
// pictures than the product now shows: held, nothing downloaded.
begin();
list( $A8, $a8 ) = imported( 'A photo added on eBay before the first read', 'a8', 3 );
$sent = push_site( $A8, 'c8' );
$GLOBALS['DOWNLOADS'] = array();
sync( $A8, array_merge( $sent, array( ebay_g( num( 'hub8', 1 ) ) ) ) );
check( 'a recorded push from the website, a photo added on eBay before the first read: held, nothing downloaded', array( count( $GLOBALS['DOWNLOADS'] ), in_array( $A8, held(), true ), shown( $A8 ) ), array( 0, true, $a8 ) );
foreach ( array( 'one per picture' => 0, 'one fewer' => 1 ) as $shape => $skip ) {
	begin();
	list( $G, $gp ) = imported( 'A photo taken off on the website before the first read, ' . $shape, 'g22' . $skip, 3 );
	$sent = push_site( $G, 'a22' . $skip );
	update_post_meta( $G, '_product_image_gallery', (string) $gp[1] );
	$GLOBALS['DOWNLOADS'] = array();
	sync( $G, array_slice( $sent, $skip ) );
	check( "$shape: a recorded push from the website, a photo taken off the product before the first read: held, nothing downloaded", array( count( $GLOBALS['DOWNLOADS'] ), in_array( $G, held(), true ), shown( $G ) ), array( 0, true, array( $gp[0], $gp[1] ) ) );
}

// eBay keeping its copy of an unchanged address: the number already learnt on
// the photo in its place is that photo's.
begin();
list( $G, $gp ) = imported( 'One photo changed, pushed from the website again', 'g21', 3 );
$first = push_site( $G, 'a21' );
sync( $G, $first );
$new3 = new_post( 'attachment', 'inherit', $G, 'new photo', 1 );
add_post_meta( $new3, '_wp_attached_file', '2026/10/new3.jpg' );
update_post_meta( $G, '_product_image_gallery', $gp[1] . ',' . $new3 );
push_site( $G, 'b21' );
$GLOBALS['DOWNLOADS'] = array();
sync( $G, array( $first[0], $first[1], ebay_z( num( 'b21', $new3 ) ) ) );
check( 'pushed from the website again with one photo changed, eBay keeping its copies of the others: nothing downloaded, its 3 photos', array( count( $GLOBALS['DOWNLOADS'] ), shown( $G ), in_array( num( 'b21', $new3 ), numbers_of( $new3 ), true ) ), array( 0, array( $gp[0], $gp[1], $new3 ), true ) );
// The same number on another photo: the places moved, nothing learnt.
begin();
list( $G, $gp ) = imported( 'Places moved', 'g23', 2 );
$first = push_site( $G, 'a23' );
sync( $G, $first );
push_site( $G, 'b23' );
$GLOBALS['DOWNLOADS'] = array();
sync( $G, array( $first[1], $first[0] ) );
check( 'pushed from the website again, eBay showing the copies swapped: nothing learnt for the swap', array( in_array( num( 'a23', $gp[1] ), numbers_of( $gp[0] ), true ), in_array( num( 'a23', $gp[0] ), numbers_of( $gp[1] ), true ) ), array( false, false ) );

// A photos-kept revise lets go of a product's held pictures.
begin();
list( $H, $hp ) = imported( 'Held, then a photos-kept revise', 'mb1', 2 );
sync( $H, pushed_unrecorded( $H, 'wmb1' ) );
$was = in_array( $H, held(), true );
if ( $HAS_OWN ) {
	TCGiant_Sync_Own_Photos::record_push( $H, (string) get_post_meta( $H, '_ebay_item_id', true ), array( 'kept' => true ) );
}
check( 'a held product revised with its listing\'s photos kept: no longer held', array( $was, in_array( $H, held(), true ) ), array( true, false ) );

// A photos-kept record of a listing the product is no longer on.
begin();
settings( array( 'overwrite_images' => '1' ) );
list( $K, $kp ) = imported( 'Photos kept, then relisted on eBay', 'mb2', 2 );
update_post_meta( $K, '_ebay_export_last_pushed', pushed_on( 40 ) );
if ( $HAS_OWN ) {
	TCGiant_Sync_Own_Photos::record_push( $K, (string) get_post_meta( $K, '_ebay_item_id', true ), array( 'kept' => true ) );
	$kr         = get_post_meta( $K, PUSHED_META, true );
	$kr['time'] = time() - 40 * 86400;
	update_post_meta( $K, PUSHED_META, $kr );
}
update_post_meta( $K, '_ebay_item_id', '2200' . $K );
$GLOBALS['DOWNLOADS'] = array();
sync( $K, array( ebay_g( num( 'mb2n', 1 ) ), ebay_g( num( 'mb2n', 2 ) ) ) );
check( 'Overwrite on, a photos-kept record of the listing before a relist on eBay: the new listing\'s photos come in behind its own', array( count( $GLOBALS['DOWNLOADS'] ), array_slice( shown( $K ), 0, 2 ), count( shown( $K ) ) ), array( 2, $kp, 4 ) );
// The same within the 30 days: that record does not exempt the new listing from the hold.
begin();
list( $K, $kp ) = imported( 'Photos kept yesterday, then relisted on eBay', 'mb4', 2 );
update_post_meta( $K, '_ebay_export_last_pushed', pushed_on( 1 ) );
if ( $HAS_OWN ) {
	TCGiant_Sync_Own_Photos::record_push( $K, (string) get_post_meta( $K, '_ebay_item_id', true ), array( 'kept' => true ) );
}
update_post_meta( $K, '_ebay_item_id', '2200' . $K );
$GLOBALS['DOWNLOADS'] = array();
sync( $K, array( ebay_g( num( 'mb4n', 1 ) ), ebay_g( num( 'mb4n', 2 ) ) ) );
check( '  within 30 days of the push, its pictures all unknown: held, as for any listing with no record of its own', array( count( $GLOBALS['DOWNLOADS'] ), in_array( $K, held(), true ), shown( $K ) ), array( 0, true, $kp ) );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
