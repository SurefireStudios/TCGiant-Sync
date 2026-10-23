<?php
/**
 * Harness: what a downloaded photo is called, and whose it is.
 *
 * Dobson Parts asked about moving their photos to bunny.net's Offloader to
 * free disk space. Every photo the localiser fetched from eBay was saved
 * under eBay's own file name - s-l1600.jpg, or 57.jpg for a $_57.JPG address -
 * and WordPress told them apart (s-l1600-1.jpg, -2 ...) only by looking in the
 * folder. An offloader moves the files to its own storage and deletes them
 * here, so the folder looks empty: the next photo is given a name an earlier
 * product's photo still has in storage, and is uploaded over it. A parts shop
 * then shows one part's photo on another.
 *
 * And the plugin's mark on a photo - the eBay address it came from - was
 * written only after WordPress had finished saving it. A request the host
 * ended while WordPress made the smaller copies left a photo with no mark: it
 * was fetched again next time, and Image Cleanup took it for one the shop had
 * uploaded itself, and offered to delete the product's real photos.
 *
 * 3.27.4 names each photo after the product and eBay's number for the picture,
 * checks the name against the Media Library's records as well as the folder,
 * and has WordPress write the mark with the photo's record. This loads the
 * real TCGiant_Sync_Image_Localizer with WordPress played: the folder is a
 * real directory, an offloader can be switched on that uploads to "storage"
 * and deletes the local files, and the Media Library's records answer SQL
 * through SQLite, so the plugin's own queries are the ones that run.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-74s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$ROOT  = dirname( __DIR__ );
$WORLD = str_replace( '\\', '/', sys_get_temp_dir() ) . '/tcg-photo-names-' . substr( md5( __FILE__ ), 0, 8 );

define( 'ABSPATH', $WORLD . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$HAS_SQL = class_exists( 'SQLite3' );

// --- the site ------------------------------------------------------------------
$POSTS     = array(); // ID => post_type, post_parent, post_author, post_status, post_title, post_name
$META      = array(); // ID => key => value
$TERMMETA  = array(); // term ID => key => value
$OPTIONS   = array();
$HOOKS     = array();
$LOG       = array();
$SCHEDULED = array();
$NEXT_ID   = 5000;
$USER      = 0;       // nobody: WP-Cron runs signed out
$DOWNLOADS = array(); // URLs fetched
$REMOTE    = array(); // the offloader's storage: path => attachment that wrote it
$OVERWRITTEN = array(); // storage paths written by a second attachment
$OFFLOADER = false;
$GENERATE  = null;    // called while WordPress makes the smaller sizes; may stop the request
$FINISHED  = 0;       // wp_update_image_subsizes() calls
$POST_DATA = array(); // what media_handle_sideload() was asked to write
$HOOK_SAW  = array(); // what add_attachment found on each new photo
$TMP_LEFT  = array();

function rrmdir( $dir ) {
	foreach ( (array) @scandir( $dir ) as $f ) {
		if ( '.' === $f || '..' === $f ) { continue; }
		is_dir( "$dir/$f" ) ? rrmdir( "$dir/$f" ) : @unlink( "$dir/$f" );
	}
	@rmdir( $dir );
}

function folder() { return $GLOBALS['WORLD'] . '/uploads/2026/10'; }

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function maybe_unserialize( $value ) { return is_string( $value ) && preg_match( '/^[aOs]:\d/', $value ) ? @unserialize( $value ) : $value; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['META'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['META'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['META'][ $id ][ $key ] ); return true; }
function has_post_thumbnail( $id ) { return ! empty( $GLOBALS['META'][ $id ]['_thumbnail_id'] ); }
function get_post_thumbnail_id( $id ) { return (int) ( $GLOBALS['META'][ $id ]['_thumbnail_id'] ?? 0 ); }
function set_post_thumbnail( $id, $thumb ) { return update_post_meta( $id, '_thumbnail_id', (int) $thumb ); }
function wc_get_product( $id ) { return isset( $GLOBALS['POSTS'][ $id ] ) && 'product' === $GLOBALS['POSTS'][ $id ]['post_type'] ? (object) array( 'id' => (int) $id ) : false; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_post( $id ) { return isset( $GLOBALS['POSTS'][ $id ] ) ? (object) ( array( 'ID' => (int) $id, 'post_date' => '2026-10-01 12:00:00' ) + $GLOBALS['POSTS'][ $id ] ) : null; }
function get_post_field( $field, $id ) { return $GLOBALS['POSTS'][ $id ][ $field ] ?? ''; }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_upload_dir( $time = null ) {
	@mkdir( folder(), 0777, true );
	return array( 'path' => folder(), 'url' => 'https://dobson.example/wp-content/uploads/2026/10', 'subdir' => '/2026/10', 'basedir' => $GLOBALS['WORLD'] . '/uploads', 'error' => false );
}
function remove_accents( $text ) { return strtr( $text, array( 'ü' => 'u', 'ö' => 'o', 'ä' => 'a', 'é' => 'e', 'ß' => 'ss', '–' => '-' ) ); }
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_single_event( $when, $hook ) { $GLOBALS['SCHEDULED'][] = (int) $when; return true; }
function wp_clear_scheduled_hook( $hook ) { return 0; }

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['HOOKS'][ $hook ][] = array( $callback, $accepted_args ); return true; }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return add_filter( $hook, $callback, $priority, $accepted_args ); }
function remove_filter( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $i => $h ) {
		if ( $h[0] === $callback ) { unset( $GLOBALS['HOOKS'][ $hook ][ $i ] ); return true; }
	}
	return false;
}
function apply_filters( $hook, $value, ...$more ) {
	$GLOBALS['RUNNING'][] = $hook;
	try {
		foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $h ) {
			$value = call_user_func_array( $h[0], array_slice( array_merge( array( $value ), $more ), 0, $h[1] ) );
		}
	} finally {
		array_pop( $GLOBALS['RUNNING'] );
	}
	return $value;
}
/** The filter being run, and whether a callback is on a hook, as WordPress says. */
function current_filter() { return empty( $GLOBALS['RUNNING'] ) ? false : end( $GLOBALS['RUNNING'] ); }
function has_filter( $hook, $callback = false ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $h ) {
		if ( $h[0] === $callback ) { return 10; }
	}
	return false;
}
function do_action( $hook, ...$args ) { apply_filters( $hook, ...( $args ?: array( null ) ) ); }

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = "[$level] $message"; }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function write_now( $message, $level = 'error' ) { self::log( $message, $level ); }
}

// --- posts -----------------------------------------------------------------------

function new_post( array $fields ) {
	$id = ++$GLOBALS['NEXT_ID'];
	$GLOBALS['POSTS'][ $id ] = $fields + array( 'post_type' => 'post', 'post_parent' => 0, 'post_author' => $GLOBALS['USER'], 'post_status' => 'inherit', 'post_title' => '', 'post_name' => '' );
	$GLOBALS['META'][ $id ]  = $GLOBALS['META'][ $id ] ?? array();
	return $id;
}

function wp_insert_attachment( $args, $file = false, $parent = 0 ) {
	return new_post( array( 'post_type' => 'attachment', 'post_parent' => (int) $parent, 'post_title' => $args['post_title'] ?? '', 'post_name' => $args['post_name'] ?? '' ) );
}

/** What wp_delete_attachment() does: the record, the files, and - with an offloader - storage. */
function wp_delete_attachment( $id, $force = false ) {
	$file = $GLOBALS['META'][ $id ]['_wp_attached_file'] ?? '';
	if ( '' !== $file ) {
		foreach ( files_of( $id ) as $rel ) {
			@unlink( $GLOBALS['WORLD'] . '/uploads/' . $rel );
			if ( $GLOBALS['OFFLOADER'] ) {
				unset( $GLOBALS['REMOTE'][ $rel ] );
			}
		}
	}
	unset( $GLOBALS['POSTS'][ $id ], $GLOBALS['META'][ $id ] );
	return true;
}

function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['POSTS'] as $id => $p ) {
		if ( ( $args['post_type'] ?? 'post' ) !== $p['post_type'] ) { continue; }
		if ( isset( $args['post_parent'] ) && (int) $p['post_parent'] !== (int) $args['post_parent'] ) { continue; }
		$m = $GLOBALS['META'][ $id ] ?? array();
		if ( isset( $args['meta_key'] ) && ( $m[ $args['meta_key'] ] ?? null ) !== $args['meta_value'] ) { continue; }
		foreach ( $args['meta_query'] ?? array() as $q ) {
			$miss = ( 'NOT EXISTS' === ( $q['compare'] ?? '' ) ) ? isset( $m[ $q['key'] ] ) : ( ( $m[ $q['key'] ] ?? null ) !== $q['value'] );
			if ( $miss ) { continue 2; }
		}
		$out[] = $id;
	}
	if ( 'DESC' === ( $args['order'] ?? 'DESC' ) ) {
		rsort( $out );
	}
	return array_slice( $out, 0, $args['numberposts'] ?? 5 );
}

// --- WordPress's media code, as it behaves -------------------------------------

/** wp_unique_filename(): sanitize_file_name(), then numbered against the FOLDER ONLY. */
function core_unique_filename( $dir, $filename ) {
	$filename = trim( str_replace( array( '$', '~', '#', '%', '&', '*', '(', ')', '|', '!', '{', '}', '+', "'", '"' ), '', $filename ), '.-_' );
	$ext      = '.' . strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$name     = pathinfo( $filename, PATHINFO_FILENAME );
	$try      = $name . $ext;
	for ( $n = 1; file_exists( "$dir/$try" ); $n++ ) {
		$try = "$name-$n$ext";
	}
	return $try;
}

$SIZES = array( '300x300', '768x768' );

/** Every file a photo has, relative to uploads. */
function files_of( $id ) {
	$rel = $GLOBALS['META'][ $id ]['_wp_attached_file'] ?? '';
	if ( '' === $rel ) { return array(); }
	$out  = array( $rel );
	$meta = $GLOBALS['META'][ $id ]['_wp_attachment_metadata'] ?? array();
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
		$out[] = dirname( $rel ) . '/' . $size['file'];
	}
	return $out;
}

/** Make the smaller sizes WordPress makes, one at a time, saving the metadata after each. */
function make_sizes( $id, array $which ) {
	$rel  = $GLOBALS['META'][ $id ]['_wp_attached_file'];
	// As wp_create_image_subsizes() records it before making any size.
	$meta = $GLOBALS['META'][ $id ]['_wp_attachment_metadata'] ?? array( 'width' => 1600, 'height' => 1200, 'file' => $rel, 'sizes' => array() );
	foreach ( $which as $size ) {
		if ( $GLOBALS['GENERATE'] ) {
			call_user_func( $GLOBALS['GENERATE'], $id, $size );
		}
		$file = pathinfo( $rel, PATHINFO_FILENAME ) . '-' . $size . '.' . pathinfo( $rel, PATHINFO_EXTENSION );
		file_put_contents( folder() . '/' . $file, file_get_contents( $GLOBALS['WORLD'] . '/uploads/' . $rel ) . " at $size" );
		$meta['sizes'][ $size ] = array( 'file' => $file );
		$GLOBALS['META'][ $id ]['_wp_attachment_metadata'] = $meta;
	}
	return $meta;
}

/** An offloader: on the finished metadata, every file goes to storage and is deleted here. */
function offload( $meta, $id ) {
	if ( ! $GLOBALS['OFFLOADER'] ) { return $meta; }
	foreach ( files_of( $id ) as $rel ) {
		if ( isset( $GLOBALS['REMOTE'][ $rel ] ) && $GLOBALS['REMOTE'][ $rel ] !== $id ) {
			$GLOBALS['OVERWRITTEN'][] = $rel;
		}
		$GLOBALS['REMOTE'][ $rel ] = $id;
		@unlink( $GLOBALS['WORLD'] . '/uploads/' . $rel );
	}
	return $meta;
}
add_filter( 'wp_generate_attachment_metadata', 'offload', 10, 2 );

/** media_handle_sideload(), in the order WordPress does it. */
function media_handle_sideload( $file_array, $post_id, $desc = null, $post_data = array() ) {
	$GLOBALS['POST_DATA'][] = $post_data;
	if ( ! empty( $GLOBALS['SAVE_FAILS'] ) ) {
		return new WP_Error( 'upload_error', 'Sorry, you are not allowed to upload this file type.' );
	}
	$uploads = wp_upload_dir();
	$name    = core_unique_filename( $uploads['path'], $file_array['name'] );
	copy( $file_array['tmp_name'], $uploads['path'] . '/' . $name );
	unlink( $file_array['tmp_name'] );

	// wp_insert_post(): the row, then meta_input, then the file, then add_attachment.
	$id = new_post( array( 'post_type' => 'attachment', 'post_parent' => (int) $post_id, 'post_title' => pathinfo( $name, PATHINFO_FILENAME ), 'post_name' => $post_data['post_name'] ?? pathinfo( $name, PATHINFO_FILENAME ) ) );
	foreach ( $post_data['meta_input'] ?? array() as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	update_post_meta( $id, '_wp_attached_file', '2026/10/' . $name );
	do_action( 'add_attachment', $id );

	// wp_generate_attachment_metadata(): sizes, slowly; then the filter offloaders use.
	$meta = make_sizes( $id, $GLOBALS['SIZES'] );
	$meta = apply_filters( 'wp_generate_attachment_metadata', $meta, $id );
	$GLOBALS['META'][ $id ]['_wp_attachment_metadata'] = $meta;
	return $id;
}

/** wp_update_image_subsizes(): make what is missing from the file, if the file is there. */
/** Where a photo's file is, as its record says. */
function get_attached_file( $id ) {
	$rel = $GLOBALS['META'][ $id ]['_wp_attached_file'] ?? '';
	return '' === $rel ? false : $GLOBALS['WORLD'] . '/uploads/' . $rel;
}
function wp_update_image_subsizes( $id ) {
	$GLOBALS['FINISHED']++;
	$rel = $GLOBALS['META'][ $id ]['_wp_attached_file'] ?? '';
	$have = array_keys( (array) ( $GLOBALS['META'][ $id ]['_wp_attachment_metadata']['sizes'] ?? array() ) );
	$missing = array_values( array_diff( $GLOBALS['SIZES'], $have ) );
	// wp_get_missing_image_subsizes(): the sizes go through its own filter,
	// not intermediate_image_sizes_advanced.
	$keyed   = apply_filters( 'wp_get_missing_image_subsizes', array_fill_keys( $missing, array( 'width' => 0, 'height' => 0, 'crop' => false ) ), $GLOBALS['META'][ $id ]['_wp_attachment_metadata'] ?? array(), $id );
	$missing = array_keys( (array) $keyed );
	if ( ! $missing ) {
		return $GLOBALS['META'][ $id ]['_wp_attachment_metadata'];
	}
	if ( '' === $rel ) {
		return new WP_Error( 'invalid_attachment', 'The attached file cannot be found.' );
	}
	// As WordPress does: a file that is gone is not an error here - nothing
	// is made, and the record comes back as it was.
	if ( ! is_file( $GLOBALS['WORLD'] . '/uploads/' . $rel ) ) {
		return $GLOBALS['META'][ $id ]['_wp_attachment_metadata'] ?? array();
	}
	$meta = make_sizes( $id, $missing );
	$meta = apply_filters( 'wp_generate_attachment_metadata', $meta, $id );
	$GLOBALS['META'][ $id ]['_wp_attachment_metadata'] = $meta;
	return $meta;
}

/** download_url(): eBay's photo server answers with the picture its address names. */
function download_url( $url, $timeout = 300 ) {
	$GLOBALS['DOWNLOADS'][] = $url;
	@mkdir( $GLOBALS['WORLD'] . '/tmp', 0777, true );
	$tmp = $GLOBALS['WORLD'] . '/tmp/' . md5( $url . count( $GLOBALS['DOWNLOADS'] ) ) . '.tmp';
	preg_match( '#/(?:g|z)/([^/]+)/#', $url, $m );
	file_put_contents( $tmp, 'picture ' . ( $m[1] ?? $url ) );
	$GLOBALS['TMP_LEFT'][] = $tmp;
	return $tmp;
}

add_action( 'add_attachment', function ( $id ) {
	$GLOBALS['HOOK_SAW'][ $id ] = array( get_post_meta( $id, '_tcgiant_source_url' ), get_post_meta( $id, '_wp_attached_file' ) );
} );

// --- the Media Library's records answer SQL ---------------------------------------

/** The site's posts and post meta as tables, for the plugin's own queries. */
function sql_world() {
	$db = new SQLite3( ':memory:' );
	$db->exec( 'CREATE TABLE wp_posts ( ID INTEGER PRIMARY KEY, post_type TEXT, post_parent INTEGER, post_author INTEGER, post_status TEXT )' );
	$db->exec( 'CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT )' );
	$db->exec( 'CREATE TABLE wp_termmeta ( meta_id INTEGER PRIMARY KEY, term_id INTEGER, meta_key TEXT, meta_value TEXT )' );
	foreach ( $GLOBALS['TERMMETA'] as $term => $m ) {
		foreach ( $m as $k => $v ) {
			$db->exec( sprintf( "INSERT INTO wp_termmeta ( term_id, meta_key, meta_value ) VALUES ( %d, '%s', '%s' )", $term, SQLite3::escapeString( $k ), SQLite3::escapeString( (string) $v ) ) );
		}
	}
	foreach ( $GLOBALS['POSTS'] as $id => $p ) {
		$db->exec( sprintf( "INSERT INTO wp_posts VALUES ( %d, '%s', %d, %d, '%s' )", $id, $p['post_type'], $p['post_parent'], $p['post_author'], $p['post_status'] ) );
	}
	foreach ( $GLOBALS['META'] as $id => $m ) {
		foreach ( $m as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$db->exec( sprintf( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( %d, '%s', '%s' )", $id, SQLite3::escapeString( $k ), SQLite3::escapeString( (string) $v ) ) );
			}
		}
	}
	// MySQL's, as these queries use them: REGEXP ignores case under the
	// tables' collation, FIND_IN_SET finds a value in a comma-separated list.
	$db->createFunction( 'regexp', function ( $pattern, $value ) { return preg_match( '#' . str_replace( '#', '\#', $pattern ) . '#i', (string) $value ) ? 1 : 0; }, 2 );
	$db->createFunction( 'find_in_set', function ( $needle, $list ) { $at = array_search( (string) $needle, explode( ',', (string) $list ), true ); return false === $at ? 0 : $at + 1; }, 2 );
	return $db;
}

class Harness_Wpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $termmeta = 'wp_termmeta';
	public $last     = '';
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$query = str_replace( array( "'%s'", '%s', '%d' ), array( '%s', "'%s'", '%d' ), $query );
		return vsprintf( $query, array_map( function ( $a ) { return is_int( $a ) ? $a : SQLite3::escapeString( (string) $a ); }, $args ) );
	}
	public function get_col( $sql ) {
		$this->last = $sql;
		$r   = sql_world()->query( $sql );
		$out = array();
		while ( $r && ( $row = $r->fetchArray( SQLITE3_NUM ) ) ) {
			$out[] = (string) $row[0];
		}
		return $out;
	}
	public function get_var( $sql ) {
		$col = $this->get_col( $sql );
		return $col ? $col[0] : null;
	}
}
$wpdb = new Harness_Wpdb();

// --- the real classes ----------------------------------------------------------------
require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-image-localizer.php';
TCGiant_Sync_Runtime::$clock        = function () { return (float) $_SERVER['REQUEST_TIME_FLOAT']; };
TCGiant_Sync_Runtime::$memory_probe = function () { return 0; };

if ( ! $HAS_SQL ) {
	echo "\n  SQLite3 is not available to this PHP, so the Media Library's records cannot be played. Skipped.\n";
	printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
	exit( 0 );
}

// --- driving it ----------------------------------------------------------------------

function fresh_site() {
	rrmdir( $GLOBALS['WORLD'] );
	foreach ( array( 'POSTS', 'META', 'TERMMETA', 'OPTIONS', 'LOG', 'DOWNLOADS', 'REMOTE', 'OVERWRITTEN', 'POST_DATA', 'HOOK_SAW', 'TMP_LEFT', 'SCHEDULED' ) as $g ) {
		$GLOBALS[ $g ] = array();
	}
	$GLOBALS['OFFLOADER']  = false;
	$GLOBALS['GENERATE']   = null;
	$GLOBALS['FINISHED']   = 0;
	$GLOBALS['USER']       = 0;
	$GLOBALS['SAVE_FAILS'] = false;
	@mkdir( folder(), 0777, true );
}

function ebay( $number, $width = 1600 ) { return "https://i.ebayimg.com/images/g/$number/s-l$width.jpg"; }

function product( $title, array $photos, $status = 'publish' ) {
	$id = new_post( array( 'post_type' => 'product', 'post_status' => $status, 'post_title' => $title, 'post_author' => 1 ) );
	TCGiant_Sync_Image_Localizer::set_external_images( $id, $photos );
	return $id;
}

function localize( $id ) {
	$step = new ReflectionMethod( 'TCGiant_Sync_Image_Localizer', 'localize_product_images' );
	$step->setAccessible( true );
	return $step->invoke( TCGiant_Sync_Image_Localizer::instance(), $id );
}

/** Its main photo's file, relative to uploads. */
function file_of( $product_id ) { return get_post_meta( get_post_thumbnail_id( $product_id ), '_wp_attached_file' ); }
/** The picture a stored file holds, wherever it now lives. */
function picture_in( $rel ) {
	$here = $GLOBALS['WORLD'] . '/uploads/' . $rel;
	if ( is_file( $here ) ) { return file_get_contents( $here ); }
	return isset( $GLOBALS['REMOTE'][ $rel ] ) ? 'in storage, written by #' . $GLOBALS['REMOTE'][ $rel ] : 'nowhere';
}
function name_of( $product_id, $url, $download = null ) { return TCGiant_Sync_Image_Localizer::photo_file_name( $product_id, $download ?? $url, $url ); }
function log_has( $text ) { foreach ( $GLOBALS['LOG'] as $l ) { if ( false !== strpos( $l, $text ) ) { return true; } } return false; }
function attachments_for( $url ) { return array_keys( array_filter( $GLOBALS['META'], function ( $m ) use ( $url ) { return ( $m['_tcgiant_source_url'] ?? '' ) === $url && ! isset( $m['_tcgiant_external_url'] ); } ) ); }

echo "\nEVERY PHOTO IS NAMED FOR WHAT IT IS\n" . str_repeat( '=', 122 ) . "\n";

fresh_site();
$caliper = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Honda Civic Front Brake Caliper OEM' ) );
$headlamp = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Ford F-150 Headlamp Assembly (Left)' ) );

check( 'an eBay photo: product, eBay\'s number for it, width', name_of( $caliper, ebay( 'kP4AAOSw~1Zl3x9A' ) ), "honda-civic-front-brake-caliper-oem-$caliper-ebay-kp4aaosw1zl3x9a-1600.jpg" );
check( '  the older $_57.JPG address: no width in it, so none in the name', name_of( $caliper, 'https://i.ebayimg.com/00/s/MTYwMFgxMjAw/z/kP4AAOSw~1Zl3x9A/$_57.JPG' ), "honda-civic-front-brake-caliper-oem-$caliper-ebay-kp4aaosw1zl3x9a.jpg" );
check( '  fetched at 800 px: the width fetched', name_of( $caliper, ebay( 'kP4AAOSw~1Zl3x9A' ), ebay( 'kP4AAOSw~1Zl3x9A', 800 ) ), "honda-civic-front-brake-caliper-oem-$caliper-ebay-kp4aaosw1zl3x9a-800.jpg" );
check( 'the same photo for another product: another name', name_of( $headlamp, ebay( 'kP4AAOSw~1Zl3x9A' ) ) !== name_of( $caliper, ebay( 'kP4AAOSw~1Zl3x9A' ) ), true );
check( 'the same photo for the same product: always the same name', name_of( $caliper, ebay( 'kP4AAOSw~1Zl3x9A' ) ), name_of( $caliper, ebay( 'kP4AAOSw~1Zl3x9A' ) ) );
check( 'two photos of one product: two names', name_of( $caliper, ebay( 'kP4AAOSw~1Zl3x9A' ) ) !== name_of( $caliper, ebay( 'xYzAAOSwQ2Zl3x9B' ) ), true );
$own = name_of( $caliper, 'https://parts.example.com/photos/g/front123/caliper.jpg' );
check( 'a photo on the seller\'s own server: a fingerprint of its address', (bool) preg_match( "/^honda-civic-front-brake-caliper-oem-$caliper-photo-[0-9a-f]{10}\\.jpg$/", $own ), true );
check( 'a title in a German shop', name_of( new_post( array( 'post_type' => 'product', 'post_title' => 'Bremssattel für Mercedes-Benz W204 – vorne links' ) ), ebay( 'kP4AAOSw~1Zl3x9A' ) ) === 'bremssattel-fur-mercedes-benz-w204-vorne-' . $NEXT_ID . '-ebay-kp4aaosw1zl3x9a-1600.jpg', true );
check( 'a title in another alphabet: "product"', 0 === strpos( name_of( new_post( array( 'post_type' => 'product', 'post_title' => 'ブレーキキャリパー' ) ), ebay( 'kP4AAOSw~1Zl3x9A' ) ), 'product-' ), true );
$long = new_post( array( 'post_type' => 'product', 'post_title' => str_repeat( 'Genuine OEM Toyota Land Cruiser 200 Series Rear Differential Assembly ', 3 ) ) );
$n    = name_of( $long, ebay( 'kP4AAOSw~1Zl3x9A' ) );
check( 'a long title: cut at a word, the whole name under 100 characters', array( 0 === strpos( $n, 'genuine-oem-toyota-land-cruiser-200-' ), strlen( $n ) < 100 ), array( true, true ) );
check( '  and only a-z, 0-9, hyphens and the extension', (bool) preg_match( '/^[a-z0-9-]+\.jpg$/', $n ), true );
check( 'never ends like one of WordPress\'s resized copies', name_of( $caliper, 'https://i.ebayimg.com/00/s/MTYw/z/123456x789/$_57.JPG' ), "honda-civic-front-brake-caliper-oem-$caliper-ebay-123456x789-photo.jpg" );
$bad = name_of( $caliper, 'https://i.ebayimg.com/images/g/kP4AAOSw~1Zl3x9A/s-l1600' );
$permanent = new ReflectionMethod( 'TCGiant_Sync_Image_Localizer', 'is_permanent_image_failure' );
$permanent->setAccessible( true );
check( 'an address that is not a photo: refused, and not retried', array( is_wp_error( $bad ), is_wp_error( $bad ) && $permanent->invoke( null, $bad ) ), array( true, true ) );

echo "\nAN OFFLOADER CAN NO LONGER PUT ONE PRODUCT'S PHOTO ON ANOTHER\n" . str_repeat( '=', 122 ) . "\n";

// 3.27.3, as media_sideload_image() named photos: after its URL.
fresh_site();
$OFFLOADER = true;
$a = new_post( array( 'post_type' => 'product', 'post_title' => 'Caliper' ) );
$b = new_post( array( 'post_type' => 'product', 'post_title' => 'Headlamp' ) );
$old_a = media_handle_sideload( array( 'name' => 's-l1600.jpg', 'tmp_name' => download_url( ebay( 'CALIPERxAAOSw' ) ) ), $a );
$old_b = media_handle_sideload( array( 'name' => 's-l1600.jpg', 'tmp_name' => download_url( ebay( 'HEADLAMPAAOSw' ) ) ), $b );
check( '3.27.3: the second product\'s photo is given the first one\'s name', get_post_meta( $old_b, '_wp_attached_file' ), get_post_meta( $old_a, '_wp_attached_file' ) );
check( '  and its files are sent over the first product\'s in storage', array( count( $OVERWRITTEN ) > 0, picture_in( '2026/10/s-l1600-300x300.jpg' ) ), array( true, "in storage, written by #$old_b" ) );

// 3.27.4.
fresh_site();
$OFFLOADER = true;
$a = product( 'Honda Civic Front Brake Caliper', array( ebay( 'CALIPERxAAOSw' ) ) );
$b = product( 'Ford F-150 Headlamp Assembly', array( ebay( 'HEADLAMPAAOSw' ) ) );
localize( $a );
localize( $b );
check( 'now: two products, two names', file_of( $a ) !== file_of( $b ), true );
check( '  nothing in storage was written twice', $OVERWRITTEN, array() );
check( '  each product\'s files are its own', array( picture_in( file_of( $a ) ), picture_in( file_of( $b ) ) ), array( 'in storage, written by #' . get_post_thumbnail_id( $a ), 'in storage, written by #' . get_post_thumbnail_id( $b ) ) );
check( '  and the folder here is empty, as the offloader leaves it', array_values( array_diff( scandir( folder() ), array( '.', '..' ) ) ), array() );

echo "\nA NAME WHOSE FILE IS GONE FROM THE FOLDER, BUT NOT FROM THE LIBRARY, IS NOT REUSED\n" . str_repeat( '=', 122 ) . "\n";

// The one case where two photos start out with the same name: the same eBay
// picture fetched twice for one product, from two addresses. eBay's photo
// set for the listing changes to the same picture on another of its hosts,
// so the copy already made is not recognised (the address is the key).
fresh_site();
$OFFLOADER = true;
$p     = product( 'Honda Civic Front Brake Caliper', array( ebay( 'CALIPERxAAOSw' ) ) );
localize( $p );
$first = get_post_thumbnail_id( $p );
$name  = get_post_meta( $first, '_wp_attached_file' );
check( 'the first copy is offloaded: its file is no longer in the folder', is_file( "$WORLD/uploads/$name" ), false );
check( '  but the Media Library still has it', get_post_meta( $first, '_wp_attached_file' ), $name );

$moved = 'https://i.ebayimg.com/thumbs/images/g/CALIPERxAAOSw/s-l1600.jpg';
check( 'the second address makes the same name to start with', name_of( $p, $moved ), basename( $name ) );
TCGiant_Sync_Image_Localizer::set_external_images( $p, array( $moved ), true );
localize( $p );
$second = attachments_for( $moved )[0] ?? 0;
$second_name = get_post_meta( $second, '_wp_attached_file' );
check( 'the new download is not given the freed name', $second_name !== $name, true );
check( '  it is that name with its own address\'s fingerprint', $second_name, '2026/10/' . pathinfo( $name, PATHINFO_FILENAME ) . '-' . substr( md5( $moved ), 0, 8 ) . '.jpg' );
check( '  nothing in storage was written over', $OVERWRITTEN, array() );
check( '  the first copy\'s files are still the first copy\'s', picture_in( $name ), "in storage, written by #$first" );

// A file a stopped request left in the folder with no record of its own.
fresh_site();
$p    = product( 'Ford F-150 Headlamp Assembly', array( ebay( 'HEADLAMPAAOSw' ) ) );
$left = name_of( $p, ebay( 'HEADLAMPAAOSw' ) );
file_put_contents( folder() . '/' . $left, 'half a photo' );
localize( $p );
$got = basename( file_of( $p ) );
check( 'a leftover file in the folder: stepped past, not numbered by WordPress', array( $got !== $left, false === strpos( $got, '-1.jpg' ), false !== strpos( $got, substr( md5( ebay( 'HEADLAMPAAOSw' ) ), 0, 8 ) ) ), array( true, true, true ) );
check( '  the name WordPress used is the one checked', get_post_meta( get_post_thumbnail_id( $p ), '_wp_attached_file' ), '2026/10/' . $got );

// A photo really deleted - record and all - leaves its name free again.
fresh_site();
$OFFLOADER = true;
$p    = product( 'Honda Civic Front Brake Caliper', array( ebay( 'CALIPERxAAOSw' ) ) );
localize( $p );
$name = file_of( $p );
wp_delete_attachment( get_post_thumbnail_id( $p ), true );
delete_post_meta( $p, '_thumbnail_id' );
TCGiant_Sync_Image_Localizer::set_external_images( $p, array( ebay( 'CALIPERxAAOSw' ) ), true );
localize( $p );
check( 'deleted outright, then fetched again: it takes its own name back', file_of( $p ), $name );

echo "\nA PHOTO IS MARKED AS OURS FROM THE MOMENT IT EXISTS\n" . str_repeat( '=', 122 ) . "\n";

fresh_site();
$p = product( 'Honda Civic Front Brake Caliper', array( ebay( 'CALIPERxAAOSw' ) ) );
localize( $p );
$id = get_post_thumbnail_id( $p );
check( 'when other plugins hear a photo was added, it already carries our mark', $HOOK_SAW[ $id ] ?? null, array( ebay( 'CALIPERxAAOSw' ), file_of( $p ) ) );
check( '  its record is named as its file is (no s-l1600-2, -3 ... search)', $POSTS[ $id ]['post_name'], pathinfo( file_of( $p ), PATHINFO_FILENAME ) );
check( '  and once every size is made, it is not marked unfinished', get_post_meta( $id, '_tcgiant_download_unfinished' ), '' );

// The request is ended while WordPress makes the smaller sizes.
class Request_Ended extends Exception {}
$stop_at_768 = function ( $id, $size ) { if ( '768x768' === $size ) { throw new Request_Ended(); } };

// 3.27.3: the mark was written after media_sideload_image() returned.
fresh_site();
$p = new_post( array( 'post_type' => 'product', 'post_title' => 'Caliper' ) );
$GENERATE = $stop_at_768;
try {
	$old = media_handle_sideload( array( 'name' => 's-l1600.jpg', 'tmp_name' => download_url( ebay( 'CALIPERxAAOSw' ) ) ), $p );
	update_post_meta( $old, '_tcgiant_source_url', ebay( 'CALIPERxAAOSw' ) );
} catch ( Request_Ended $e ) {
	// Nothing after this ran.
}
$stopped = max( array_keys( $POSTS ) );
check( '3.27.3: the photo a stopped request leaves has no mark', array( get_post_meta( $stopped, '_wp_attached_file' ) !== '', get_post_meta( $stopped, '_tcgiant_source_url' ) ), array( true, '' ) );

// 3.27.4.
fresh_site();
$p = product( 'Honda Civic Front Brake Caliper', array( ebay( 'CALIPERxAAOSw' ) ) );
$GENERATE = $stop_at_768;
try {
	localize( $p );
} catch ( Request_Ended $e ) {
	// As far as the localiser is concerned, the request is over.
}
$half = attachments_for( ebay( 'CALIPERxAAOSw' ) )[0] ?? 0;
check( 'now: the photo it leaves is ours, and marked unfinished, with when', array( $half > 0, abs( (int) get_post_meta( $half, '_tcgiant_download_unfinished' ) - time() ) < 60 ), array( true, true ) );

// Another product with the same eBay photo, while the first request may still
// be making it: shared as it is - not finished under that request, and not
// fetched a second time.
$GENERATE = null;
$twin     = product( 'Honda Civic Front Brake Caliper (pair)', array( ebay( 'CALIPERxAAOSw' ) ) );
$fetched  = count( $DOWNLOADS );
$LOG      = array();
localize( $twin );
check( 'a photo still being made: shared, not finished or fetched again', array( count( $DOWNLOADS ) - $fetched, $FINISHED, get_post_thumbnail_id( $twin ), (int) get_post_meta( $half, '_tcgiant_download_unfinished' ) > 0 ), array( 0, 0, $half, true ) );

// A quarter of an hour on, nothing has come back for it: the request stopped.
// This WordPress also makes a 1,536 px copy: finishing must skip it, as the
// download does.
update_post_meta( $half, '_tcgiant_download_unfinished', time() - TCGiant_Sync_Image_Localizer::LEASE_SECONDS - 1 );
$SIZES    = array( '300x300', '768x768', '1536x1536' );
$fetched  = count( $DOWNLOADS );
$LOG      = array();
$r        = localize( $p );
$SIZES    = array( '300x300', '768x768' );
check( 'the next run finishes it instead of downloading it again', array( count( $DOWNLOADS ) - $fetched, $FINISHED, $r['skipped'] ), array( 0, 1, 1 ) );
check( '  every size made, the mark gone', array( array_keys( $META[ $half ]['_wp_attachment_metadata']['sizes'] ), get_post_meta( $half, '_tcgiant_download_unfinished' ) ), array( array( '300x300', '768x768' ), '' ) );
check( '  the product shows it', get_post_thumbnail_id( $p ), $half );
check( '  one copy of the photo, not two', count( attachments_for( ebay( 'CALIPERxAAOSw' ) ) ), 1 );
check( '  and the log says what happened', log_has( "finished photo #$half, which a stopped request had left half saved" ), true );

// Stopped before the file had a record of its own place, or the file is
// damaged: it cannot be finished, so it is fetched again - under a name of
// its own, and the newer copy is the one used from then on.
fresh_site();
$OFFLOADER = false;
$p = product( 'Ford F-150 Headlamp Assembly', array( ebay( 'HEADLAMPAAOSw' ) ) );
$GENERATE = function ( $id, $size ) { if ( '300x300' === $size ) { throw new Request_Ended(); } };
try { localize( $p ); } catch ( Request_Ended $e ) {}
$half = attachments_for( ebay( 'HEADLAMPAAOSw' ) )[0];
@unlink( "$WORLD/uploads/" . get_post_meta( $half, '_wp_attached_file' ) );
update_post_meta( $half, '_tcgiant_download_unfinished', time() - TCGiant_Sync_Image_Localizer::LEASE_SECONDS - 1 );
$GENERATE = null;
$LOG = array();
localize( $p );
$copies = attachments_for( ebay( 'HEADLAMPAAOSw' ) );
check( 'a half-saved photo that cannot be finished: fetched again', count( $copies ), 2 );
check( '  under a name of its own', get_post_meta( max( $copies ), '_wp_attached_file' ) !== get_post_meta( $half, '_wp_attached_file' ), true );
check( '  the new copy is the product\'s photo', get_post_thumbnail_id( $p ), max( $copies ) );
check( '  the log names the one left behind', log_has( "photo #$half was left half saved" ), true );
$fetched = count( $DOWNLOADS );
TCGiant_Sync_Image_Localizer::set_external_images( $p, array( ebay( 'HEADLAMPAAOSw' ) ), true );
localize( $p );
check( '  and from then on the finished copy is found first', array( count( $DOWNLOADS ) - $fetched, get_post_thumbnail_id( $p ) ), array( 0, max( $copies ) ) );

// Stopped after its record was complete - width, file - while the smaller
// sizes were being made, and the file has gone since. WordPress would report
// that as finished, with nothing made: it is fetched again instead.
fresh_site();
$OFFLOADER = false;
$p = product( 'Ford F-150 Tail Lamp', array( ebay( 'TAILLAMPAAOSw' ) ) );
$GENERATE = $stop_at_768;
try { localize( $p ); } catch ( Request_Ended $e ) {}
$half = attachments_for( ebay( 'TAILLAMPAAOSw' ) )[0];
@unlink( "$WORLD/uploads/" . get_post_meta( $half, '_wp_attached_file' ) );
update_post_meta( $half, '_tcgiant_download_unfinished', time() - TCGiant_Sync_Image_Localizer::LEASE_SECONDS - 1 );
$GENERATE = null;
localize( $p );
check( 'a half-saved photo with its record whose file has gone: fetched again', array( count( attachments_for( ebay( 'TAILLAMPAAOSw' ) ) ), get_post_thumbnail_id( $p ) !== $half ), array( 2, true ) );

// WordPress refusing the file: the downloaded copy is not left in the temp folder.
fresh_site();
$p = product( 'Caliper', array( ebay( 'CALIPERxAAOSw' ) ) );
$SAVE_FAILS = true;
localize( $p );
check( 'a photo WordPress will not save leaves no temporary file behind', array_values( array_filter( $TMP_LEFT, 'is_file' ) ), array() );

echo "\nIMAGE CLEANUP TELLS A STOPPED DOWNLOAD FROM THE SHOP'S OWN PHOTO\n" . str_repeat( '=', 122 ) . "\n";

/** A photo as some earlier version, or the shop, left it. */
function photo_on( $product_id, $file, $author, array $meta = array() ) {
	$id = new_post( array( 'post_type' => 'attachment', 'post_parent' => $product_id, 'post_author' => $author ) );
	if ( null !== $file ) {
		$meta['_wp_attached_file'] = '2025/03/' . $file;
	}
	foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
	return $id;
}
function ours_on( $product_id, $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$out[] = photo_on( $product_id, "s-l1600-$product_id$i.jpg", 0, array( '_tcgiant_source_url' => ebay( "P{$product_id}N$i" ) ) );
	}
	update_post_meta( $product_id, '_thumbnail_id', $out[0] );
	update_post_meta( $product_id, '_product_image_gallery', implode( ',', array_slice( $out, 1 ) ) );
	return $out;
}

fresh_site();
// The case Image Cleanup is for: before 3.9.5 a pushed product's photos came
// back from eBay and replaced the shop's own, which are still attached.
$pushed  = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Pushed' ) );
$upload  = photo_on( $pushed, 'caliper-front.jpg', 1 );
$copies1 = ours_on( $pushed, 3 );

// A product of eight photos whose download was stopped on the third, before
// 3.27.4: an unmarked copy, saved in the background, eBay's name.
$stopped = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Stopped' ) );
$left    = photo_on( $stopped, 's-l1600-7.jpg', 0 );
$copies2 = ours_on( $stopped, 8 );

// The same, on a site whose cron runs inside a signed-in page view.
$signed  = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Signed in' ) );
photo_on( $signed, '57-3.jpg', 1 );
ours_on( $signed, 2 );

// A record with no file at all, and a photo the shop imported from the command line.
$bare = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Bare' ) );
photo_on( $bare, null, 0 );
ours_on( $bare, 2 );
$cli = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Imported with WP-CLI' ) );
$cli_photo = photo_on( $cli, 'front-left.jpg', 0 );
ours_on( $cli, 2 );

// 3.27.3's query, verbatim, for the comparison.
$old_sql = "SELECT DISTINCT mine.post_parent
	 FROM wp_posts mine
	 INNER JOIN wp_postmeta src ON src.post_id = mine.ID AND src.meta_key = '_tcgiant_source_url'
	 LEFT JOIN  wp_postmeta ext ON ext.post_id = mine.ID AND ext.meta_key = '_tcgiant_external_url'
	 INNER JOIN wp_posts prod ON prod.ID = mine.post_parent
	 WHERE mine.post_type = 'attachment' AND ext.post_id IS NULL AND prod.post_type = 'product'
	   AND prod.post_status NOT IN ( 'trash', 'auto-draft' )
	   AND EXISTS ( SELECT 1 FROM wp_posts theirs
		   LEFT JOIN wp_postmeta tsrc ON tsrc.post_id = theirs.ID AND tsrc.meta_key = '_tcgiant_source_url'
		   LEFT JOIN wp_postmeta text ON text.post_id = theirs.ID AND text.meta_key = '_tcgiant_external_url'
		   WHERE theirs.post_type = 'attachment' AND theirs.post_parent = mine.post_parent
			 AND tsrc.post_id IS NULL AND text.post_id IS NULL )
	 ORDER BY mine.post_parent DESC";
$before = array_map( 'intval', $wpdb->get_col( $old_sql ) );
check( '3.27.3 offered the stopped download\'s product for cleanup', in_array( $stopped, $before, true ), true );

$listed = TCGiant_Sync_Image_Localizer::find_products_with_duplicate_images();
check( 'now the pushed product is still offered', in_array( $pushed, $listed, true ), true );
check( '  with the shop\'s upload to keep and our copies to delete', array( TCGiant_Sync_Image_Localizer::attachments_by_origin( $pushed, false ), TCGiant_Sync_Image_Localizer::attachments_by_origin( $pushed, true ) ), array( array( $upload ), $copies1 ) );
check( 'the stopped download\'s product is not', in_array( $stopped, $listed, true ), false );
check( '  nor one whose cron ran signed in', in_array( $signed, $listed, true ), false );
check( '  nor one with a record and no file', in_array( $bare, $listed, true ), false );
check( '  nor one whose photo came in signed out, as nobody', in_array( $cli, $listed, true ), false );

$r = TCGiant_Sync_Image_Localizer::restore_own_images( $stopped );
check( 'asked to restore it anyway: refused', is_wp_error( $r ) ? $r->get_error_code() : $r, 'no_own_images' );
check( '  all eight photos still there', count( array_filter( $copies2, function ( $id ) { return isset( $GLOBALS['POSTS'][ $id ] ); } ) ), 8 );
check( '  the unmarked one too: never deleted on a guess', isset( $POSTS[ $left ] ), true );
check( '  and the shop\'s command-line import, likewise', ( TCGiant_Sync_Image_Localizer::restore_own_images( $cli ) instanceof WP_Error ) && isset( $POSTS[ $cli_photo ] ), true );

// One download serves every product needing that photo: a copy attached to
// the pushed product is also the main photo of a trashed relist donor.
$donor = new_post( array( 'post_type' => 'product', 'post_status' => 'trash', 'post_title' => 'Donor' ) );
update_post_meta( $donor, '_thumbnail_id', $copies1[2] );
$r = TCGiant_Sync_Image_Localizer::restore_own_images( $pushed );
check( 'restoring the pushed product deletes the copies only it shows', array( $r, isset( $POSTS[ $copies1[0] ] ), isset( $POSTS[ $copies1[1] ] ) ), array( 2, false, false ) );
check( '  and keeps the one a trashed product still shows', isset( $POSTS[ $copies1[2] ] ), true );
check( '  its own photo restored as its main image', get_post_thumbnail_id( $pushed ), $upload );

// A product whose own photos the plugin can place only some of: one the
// shop uploaded signed in, one named by its part number, one imported
// signed out - and our three copies. Restoring would show the first alone,
// drop the other two from the product, and delete the copies standing in for
// them. Left alone instead: not offered, and refused if asked.
$mixed     = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Mixed' ) );
$front     = photo_on( $mixed, 'front.jpg', 1 );
$part_no   = photo_on( $mixed, '04465-02220.jpg', 1 );
$rear      = photo_on( $mixed, 'caliper-rear.jpg', 0 );
$copies3   = ours_on( $mixed, 3 );
check( 'a product with photos the plugin cannot place is not offered', in_array( $mixed, TCGiant_Sync_Image_Localizer::find_products_with_duplicate_images(), true ), false );
$r = TCGiant_Sync_Image_Localizer::restore_own_images( $mixed );
check( '  asked anyway: refused, and nothing deleted or changed', array( is_wp_error( $r ) ? $r->get_error_code() : $r, count( array_filter( array_merge( array( $front, $part_no, $rear ), $copies3 ), function ( $id ) { return isset( $GLOBALS['POSTS'][ $id ] ); } ) ), get_post_thumbnail_id( $mixed ) ), array( 'uncertain_photos', 6, $copies3[0] ) );

// Its photos being fetched right now: what is attached may be about to
// become its pictures.
$busy = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Busy' ) );
photo_on( $busy, 'busy-front.jpg', 1 );
$copies4 = ours_on( $busy, 2 );
update_post_meta( $busy, '_tcgiant_localize_inflight', 1 );
$r = TCGiant_Sync_Image_Localizer::restore_own_images( $busy );
check( 'a product whose photos are being downloaded: refused, nothing deleted', array( is_wp_error( $r ) ? $r->get_error_code() : $r, isset( $POSTS[ $copies4[0] ], $POSTS[ $copies4[1] ] ) ), array( 'photos_in_progress', true ) );


echo "\nIMAGE CLEANUP KEEPS A COPY ANYTHING ELSE STILL SHOWS\n" . str_repeat( '=', 122 ) . "\n";

// Restoring a product's own photos deletes the copies the plugin downloaded
// for it. One download serves every product that needs the same eBay photo,
// so a copy attached here may be what something else shows - and the removal
// of deleted products' photos, in the same release, counts more of those
// than the main photo and WooCommerce's gallery.
fresh_site();
$x      = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'X' ) );
$upload = photo_on( $x, 'x-front.jpg', 1 );
$copies = ours_on( $x, 6 );
// Another product's variation shows the second, through WooCommerce's
// Additional Variation Images (a list).
$y = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Y' ) );
$v = new_post( array( 'post_type' => 'product_variation', 'post_parent' => $y, 'post_status' => 'publish' ) );
update_post_meta( $v, '_wc_additional_variation_images', '17,' . $copies[1] );
// A third's, through Variation Images Gallery for WooCommerce (an array).
$z = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Z' ) );
$w = new_post( array( 'post_type' => 'product_variation', 'post_parent' => $z, 'post_status' => 'publish' ) );
update_post_meta( $w, 'woo_variation_gallery_images', serialize( array( (string) $copies[2] ) ) );
// A category shows the fourth as its picture.
$TERMMETA[12] = array( 'thumbnail_id' => (string) $copies[3] );
// And before 3.1.4 the image batch gave each product reusing a photo a record
// of its own pointing at the same file: product Q shows a twin of the fifth.
$q    = new_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Q' ) );
$twin = photo_on( $q, null, 0, array( '_wp_attached_file' => get_post_meta( $copies[4], '_wp_attached_file' ), '_tcgiant_source_url' => get_post_meta( $copies[4], '_tcgiant_source_url' ) ) );
update_post_meta( $q, '_thumbnail_id', $twin );
// A gallery that only mentions the number inside another one is no use of it.
update_post_meta( $q, '_wc_additional_variation_images', '9' . $copies[0] . ',1' . $copies[0] );
// A list another plugin keeps, named to the photo cleanup by its filter.
$swatches = function ( $keys ) { $keys[] = 'swatch_photos'; return $keys; };
add_filter( 'tcgiant_sync_photo_list_keys', $swatches );
update_post_meta( $y, 'swatch_photos', serialize( array( 'red' => array( (string) $copies[5] ) ) ) );

$r = TCGiant_Sync_Image_Localizer::restore_own_images( $x );
remove_filter( 'tcgiant_sync_photo_list_keys', $swatches );
check( 'restored: the copy only it showed is removed', array( $r, isset( $POSTS[ $copies[0] ] ) ), array( 1, false ) );
check( '  kept: one another variation lists (Additional Variation Images)', isset( $POSTS[ $copies[1] ] ), true );
check( '  kept: one another variation lists (Variation Images Gallery)', isset( $POSTS[ $copies[2] ] ), true );
check( '  kept: a category\'s picture', isset( $POSTS[ $copies[3] ] ), true );
check( '  kept: one whose file another entry, shown by a product, points at', isset( $POSTS[ $copies[4] ] ), true );
check( '  kept: one in a list named by tcgiant_sync_photo_list_keys', isset( $POSTS[ $copies[5] ] ), true );
check( '  its own photo restored as its main image', get_post_thumbnail_id( $x ), $upload );

echo "\nA NAME HELD BY A SCALED OR ROTATED PHOTO\n" . str_repeat( '=', 122 ) . "\n";

// WordPress's record of a photo it scaled names the copy it serves
// (-scaled); the original, under the plain name, sits beside it.
fresh_site();
$p     = product( 'Big photo', array( ebay( 'BIGxAAOSw' ) ) );
$held  = new_post( array( 'post_type' => 'attachment', 'post_parent' => $p, 'post_author' => 0 ) );
update_post_meta( $held, '_wp_attached_file', '2026/10/big-photo-' . $p . '-ebay-bigxaaosw-1600-scaled.jpg' );
$pick = new ReflectionMethod( 'TCGiant_Sync_Image_Localizer', 'unclaimed_file_name' );
$pick->setAccessible( true );
$name = $pick->invoke( null, $p, 'big-photo-' . $p . '-ebay-bigxaaosw-1600.jpg', ebay( 'BIGxAAOSw' ) );
check( 'a name whose -scaled record exists is not given out again', $name !== 'big-photo-' . $p . '-ebay-bigxaaosw-1600.jpg', true );

// A file nothing holds, left by a stopped request: stepped past, and said.
file_put_contents( folder() . '/left-' . $p . '-ebay-x.jpg', 'x' );
$LOG  = array();
$name = $pick->invoke( null, $p, 'left-' . $p . '-ebay-x.jpg', ebay( 'Xx' ) );
check( 'a leftover file nothing holds is stepped past', $name !== 'left-' . $p . '-ebay-x.jpg', true );
check( '  and the log says so', log_has( 'a file left by a stopped request is in the way' ), true );

rrmdir( $WORLD );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
