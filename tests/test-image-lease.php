<?php
/**
 * Harness: one product that stops the image batch, and the batches after it.
 *
 * Products come in from eBay showing eBay's own copies of their photos, and a
 * background batch replaces them with copies in the shop's media library -
 * eBay's copies stop working once a listing ends. On 3.26.1 one product whose
 * photos stopped a batch held the whole queue up:
 *
 * - a failed attempt was only counted once the product's work had finished,
 *   so a batch the host killed part way through never counted it. The product
 *   headed the next batch, and the one after that, and the queue never moved;
 * - an exception from one product ended the batch for every product after it,
 *   with the same result;
 * - the 30-second limit on photo downloads, set through http_request_timeout,
 *   never applied: media_sideload_image() passes 300 seconds of its own, so a
 *   single slow eBay photo could hold a batch until the host killed it.
 *
 * 3.27.0 charges the attempt, and takes a fifteen-minute lease on the
 * product, before any download; gives up on a product whose attempts all
 * ended that way; catches whatever one product throws; and caps each eBay
 * photo download through http_request_args. This loads the real
 * TCGiant_Sync_Image_Localizer and TCGiant_Sync_Runtime with WordPress
 * stubbed, plays eBay's photo servers, and drives localize_product_images()
 * (private, through reflection) and process_batch().
 *
 * A host killing a request is a PHP fatal - no catch, no finally, nothing
 * after it - so that part runs each batch as a PHP process of its own (this
 * file again, with --one-batch), with post meta kept in a file the way the
 * database keeps it, and the fatal is real: a memory limit exhausted inside
 * the download.
 *
 * @package TCGiant_Sync
 */

$CHILD = in_array( '--one-batch', (array) ( $argv ?? array() ), true );

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-74s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$ROOT  = dirname( __DIR__ );
$WORLD = str_replace( '\\', '/', sys_get_temp_dir() ) . '/tcg-image-lease-' . substr( md5( __FILE__ ), 0, 8 );

// --- the world the localiser runs in -----------------------------------------
define( 'ABSPATH', $WORLD . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

// process_batch() loads WordPress's media code; it is played here.
foreach ( array( 'media', 'file', 'image' ) as $f ) {
	if ( ! is_file( ABSPATH . "wp-admin/includes/$f.php" ) ) {
		@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
		file_put_contents( ABSPATH . "wp-admin/includes/$f.php", "<?php\n" );
	}
}

$META      = array(); // post ID => meta key => value. Attachments carry their parent as _parent.
$PRODUCTS  = array(); // published products
$OPTIONS   = array();
$DOWNLOADS = array(); // [ post ID, URL ] for every photo media_sideload_image() was asked for
$ARGS_SEEN = array(); // [ URL, what WP_Http would have sent for it ]
$SCHEDULED = array(); // single WP-Cron events, by time
$HOOKS     = array();
$LOG       = array();
$NEXT_ID   = 9000;
$KILLS     = array(); // photos whose download kills the request outright
$PHOTO     = function () { return 'ok'; }; // what eBay's photo server does with a request

// In a request of its own, every write goes straight to "the database".
function save_site() {
	file_put_contents( $GLOBALS['WORLD'] . '/site.json', json_encode( array(
		'meta'      => $GLOBALS['META'],
		'products'  => $GLOBALS['PRODUCTS'],
		'options'   => $GLOBALS['OPTIONS'],
		'downloads' => $GLOBALS['DOWNLOADS'],
		'next_id'   => $GLOBALS['NEXT_ID'],
		'kills'     => $GLOBALS['KILLS'],
	) ) );
}
function load_site() {
	$s = json_decode( file_get_contents( $GLOBALS['WORLD'] . '/site.json' ), true );
	$GLOBALS['META']      = $s['meta'];
	$GLOBALS['PRODUCTS']  = $s['products'];
	$GLOBALS['OPTIONS']   = $s['options'];
	$GLOBALS['DOWNLOADS'] = $s['downloads'];
	$GLOBALS['NEXT_ID']   = $s['next_id'];
	$GLOBALS['KILLS']     = $s['kills'];
}
function persist() {
	if ( $GLOBALS['CHILD'] ) {
		save_site();
	}
}

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

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; persist(); return true; }

function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['META'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['META'][ $id ][ $key ] = $value; persist(); return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['META'][ $id ][ $key ] ); persist(); return true; }
function has_post_thumbnail( $id ) { return ! empty( $GLOBALS['META'][ $id ]['_thumbnail_id'] ); }
function get_post_thumbnail_id( $id ) { return (int) ( $GLOBALS['META'][ $id ]['_thumbnail_id'] ?? 0 ); }
function set_post_thumbnail( $id, $thumb ) { return update_post_meta( $id, '_thumbnail_id', (int) $thumb ); }
function wc_get_product( $id ) { return in_array( (int) $id, $GLOBALS['PRODUCTS'], true ) ? (object) array( 'id' => (int) $id ) : false; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

function wp_insert_attachment( $args, $file = false, $parent = 0 ) {
	$id = ++$GLOBALS['NEXT_ID'];
	$GLOBALS['META'][ $id ] = array( '_parent' => (int) $parent );
	persist();
	return $id;
}
function wp_delete_attachment( $id, $force = false ) { unset( $GLOBALS['META'][ $id ] ); persist(); return true; }
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['META'] as $id => $m ) {
		if ( ! isset( $m['_parent'] ) ) {
			continue; // not an attachment
		}
		if ( isset( $args['post_parent'] ) && (int) $m['_parent'] !== (int) $args['post_parent'] ) {
			continue;
		}
		if ( isset( $args['meta_key'] ) && ( $m[ $args['meta_key'] ] ?? null ) !== $args['meta_value'] ) {
			continue;
		}
		foreach ( $args['meta_query'] ?? array() as $q ) {
			$miss = ( 'NOT EXISTS' === ( $q['compare'] ?? '' ) ) ? isset( $m[ $q['key'] ] ) : ( ( $m[ $q['key'] ] ?? null ) !== $q['value'] );
			if ( $miss ) {
				continue 2;
			}
		}
		$out[] = $id;
	}
	return array_slice( $out, 0, $args['numberposts'] ?? 5 );
}

function wp_next_scheduled( $hook ) { return $GLOBALS['SCHEDULED'] ? end( $GLOBALS['SCHEDULED'] ) : false; }
function wp_schedule_single_event( $when, $hook ) { $GLOBALS['SCHEDULED'][] = (int) $when; return true; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['SCHEDULED'] = array(); return 0; }

// Hooks, as WordPress keeps them.
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
function apply_filters( $hook, $value, ...$more ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $h ) {
		$value = call_user_func_array( $h[0], array_slice( array_merge( array( $value ), $more ), 0, $h[1] ) );
	}
	return $value;
}
function filters_on( $hook ) { return count( $GLOBALS['HOOKS'][ $hook ] ?? array() ); }

/**
 * What WP_Http::request() holds for a download_url() call - the request
 * media_sideload_image() makes - just before it applies http_request_args.
 */
function download_args( $timeout ) {
	return array( 'method' => 'GET', 'timeout' => $timeout, 'redirection' => 5, 'stream' => true, 'filename' => '/tmp/s-l1600-AbC1.tmp', 'limit_response_size' => null, 'reject_unsafe_urls' => true );
}

/** The product a photo address was queued for. */
function owner_of( $url ) {
	foreach ( $GLOBALS['PRODUCTS'] as $id ) {
		foreach ( (array) ( $GLOBALS['META'][ $id ]['_tcgiant_external_image_urls'] ?? array() ) as $entry ) {
			if ( ( is_array( $entry ) ? ( $entry[0] ?? '' ) : $entry ) === $url ) {
				return (int) $id;
			}
		}
	}
	return 0;
}

/**
 * download_url(), with eBay's photo server played by $PHOTO. Since 3.27.4 the
 * localiser fetches with this and media_handle_sideload(), not
 * media_sideload_image(), so that it can name the file and mark the photo.
 */
function download_url( $url, $timeout = 300 ) {
	$id = owner_of( $url );
	$GLOBALS['DOWNLOADS'][] = array( $id, $url );
	persist();

	$GLOBALS['ARGS_SEEN'][] = array( $url, apply_filters( 'http_request_args', download_args( $timeout ), $url ) );

	$outcome = call_user_func( $GLOBALS['PHOTO'], $url, $id );
	if ( is_wp_error( $outcome ) ) {
		return $outcome;
	}
	return $GLOBALS['WORLD'] . '/no-such-download.tmp';
}

/** media_handle_sideload(): the photo's record, with what it was asked to write on it. */
function media_handle_sideload( $file, $post_id, $desc = null, $post_data = array() ) {
	$id = wp_insert_attachment( $post_data, false, $post_id );
	foreach ( $post_data['meta_input'] ?? array() as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
}

// What the localiser looks up to name a photo and find its folder.
function get_post( $id ) { return (object) array( 'ID' => (int) $id, 'post_date' => '2026-10-01 12:00:00' ); }
function get_post_field( $field, $id ) { return 'post_title' === $field ? 'Product ' . (int) $id : ''; }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_upload_dir( $time = null ) { return array( 'path' => $GLOBALS['WORLD'] . '/uploads/2026/10', 'subdir' => '/2026/10', 'basedir' => $GLOBALS['WORLD'] . '/uploads', 'error' => false ); }

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) {
		$line = "[$level] $message";
		if ( $GLOBALS['CHILD'] ) {
			file_put_contents( $GLOBALS['WORLD'] . '/activity.log', $line . "\n", FILE_APPEND );
		} else {
			$GLOBALS['LOG'][] = $line;
		}
	}
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function write_now( $message, $level = 'error' ) { self::log( $message, $level ); }
}

/**
 * The products the batch's query would select: queued, not waiting out a
 * retry or a lease, lowest ID first.
 */
function due_products( $now ) {
	$due = array();
	foreach ( $GLOBALS['PRODUCTS'] as $id ) {
		$m = $GLOBALS['META'][ $id ] ?? array();
		if ( ! array_key_exists( '_tcgiant_external_image_urls', $m ) || ! array_key_exists( '_tcgiant_images_localized', $m ) ) {
			continue;
		}
		if ( '0' !== (string) $m['_tcgiant_images_localized'] ) {
			continue;
		}
		if ( isset( $m['_tcgiant_localize_retry_after'] ) && (int) $m['_tcgiant_localize_retry_after'] > $now ) {
			continue;
		}
		$due[] = $id;
	}
	sort( $due );
	return $due;
}

class Harness_Wpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return vsprintf( $query, $args );
	}
	public function get_col( $sql ) {
		preg_match( '/<= (\d+)/', $sql, $now );
		preg_match( '/LIMIT (\d+)/', $sql, $limit );
		return array_slice( due_products( (int) $now[1] ), 0, (int) $limit[1] );
	}
	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'MIN(' ) ) {
			preg_match( '/> (\d+)/', $sql, $now );
			$next = null;
			foreach ( $GLOBALS['PRODUCTS'] as $id ) {
				$m = $GLOBALS['META'][ $id ] ?? array();
				if ( '0' === (string) ( $m['_tcgiant_images_localized'] ?? '' ) && isset( $m['_tcgiant_localize_retry_after'] ) && (int) $m['_tcgiant_localize_retry_after'] > (int) $now[1] ) {
					$next = null === $next ? (int) $m['_tcgiant_localize_retry_after'] : min( $next, (int) $m['_tcgiant_localize_retry_after'] );
				}
			}
			return null === $next ? null : (string) $next;
		}
		if ( false !== strpos( $sql, 'COUNT(' ) ) {
			preg_match( '/<= (\d+)/', $sql, $now );
			return (string) count( due_products( (int) $now[1] ) );
		}
		return null;
	}
}
$wpdb = new Harness_Wpdb();

// --- the real classes --------------------------------------------------------
if ( is_file( $ROOT . '/includes/class-tcgiant-sync-runtime.php' ) ) {
	require $ROOT . '/includes/class-tcgiant-sync-runtime.php'; // new in 3.27.0
}
require $ROOT . '/includes/class-tcgiant-sync-image-localizer.php';

// The time and memory budget is another harness's subject: here a batch is
// never cut short by it.
if ( class_exists( 'TCGiant_Sync_Runtime' ) ) {
	TCGiant_Sync_Runtime::$clock        = function () { return (float) $_SERVER['REQUEST_TIME_FLOAT']; };
	TCGiant_Sync_Runtime::$memory_probe = function () { return 0; };
}

// --- one batch, in a request of its own ---------------------------------------
if ( $CHILD ) {
	load_site();
	$PHOTO = function ( $url ) {
		if ( in_array( $url, $GLOBALS['KILLS'], true ) ) {
			// A real, uncatchable fatal: what a host's memory_limit does to a
			// request decoding a photo too large for it.
			ini_set( 'memory_limit', '32M' );
			$GLOBALS['TOO_BIG'] = str_repeat( 'x', 64 * 1048576 );
		}
		return 'ok';
	};
	TCGiant_Sync_Image_Localizer::instance()->process_batch();
	exit( 0 );
}

// --- driving it --------------------------------------------------------------

function photo( $n ) { return sprintf( 'https://i.ebayimg.com/images/g/P%03dAAOSwAbC/s-l1600.jpg', $n ); }
function timeout() { return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received' ); }

function fresh_site() {
	$GLOBALS['META']      = array();
	$GLOBALS['PRODUCTS']  = array();
	$GLOBALS['OPTIONS']   = array();
	$GLOBALS['DOWNLOADS'] = array();
	$GLOBALS['ARGS_SEEN'] = array();
	$GLOBALS['SCHEDULED'] = array();
	$GLOBALS['LOG']       = array();
	$GLOBALS['KILLS']     = array();
	$GLOBALS['PHOTO']     = function () { return 'ok'; };
	// Whatever an earlier batch left behind does not carry over to this one.
	unset( $GLOBALS['HOOKS']['http_request_args'], $GLOBALS['HOOKS']['http_request_timeout'], $GLOBALS['HOOKS']['wp_editor_set_quality'], $GLOBALS['HOOKS']['intermediate_image_sizes_advanced'] );
	@unlink( $GLOBALS['WORLD'] . '/activity.log' );
}

/**
 * A product as the importer leaves it: its eBay photo addresses stored, an
 * eBay-hosted placeholder as its main image, and queued for the batch.
 */
function product( $id, array $photos, array $meta = array() ) {
	$GLOBALS['PRODUCTS'][] = $id;
	$GLOBALS['META'][ $id ] = array();
	TCGiant_Sync_Image_Localizer::set_external_images( $id, $photos );
	foreach ( $meta as $key => $value ) {
		$GLOBALS['META'][ $id ][ $key ] = $value;
	}
}

/** The private per-product step, called the way process_batch() calls it. */
function localize( $id ) {
	$step = new ReflectionMethod( 'TCGiant_Sync_Image_Localizer', 'localize_product_images' );
	$step->setAccessible( true );
	return $step->invoke( TCGiant_Sync_Image_Localizer::instance(), $id );
}

/** A batch, as WP-Cron runs it. Returns whatever escaped it, or ''. */
function batch() {
	try {
		TCGiant_Sync_Image_Localizer::instance()->process_batch();
		return '';
	} catch ( \Throwable $e ) {
		return get_class( $e ) . ': ' . $e->getMessage();
	}
}

/** A batch in a PHP process of its own, so that a fatal is a real one. */
function one_batch() {
	save_site();
	shell_exec( '"' . PHP_BINARY . '" "' . __FILE__ . '" --one-batch 2>&1' );
	load_site();
}

/** Time passes: every retry and lease is that much nearer. */
function time_passes( $seconds ) {
	foreach ( $GLOBALS['META'] as $id => $m ) {
		if ( isset( $m['_tcgiant_localize_retry_after'] ) ) {
			$GLOBALS['META'][ $id ]['_tcgiant_localize_retry_after'] = (int) $m['_tcgiant_localize_retry_after'] - $seconds;
		}
	}
}

function meta( $id, $key ) { return $GLOBALS['META'][ $id ][ $key ] ?? ''; }
function has_meta( $id, $key ) { return isset( $GLOBALS['META'][ $id ] ) && array_key_exists( $key, $GLOBALS['META'][ $id ] ); }
function done( $id ) { return '1' === (string) meta( $id, '_tcgiant_images_localized' ); }
function own_photo( $id ) { return TCGiant_Sync_Image_Localizer::has_local_thumbnail( $id ); }
function downloads_of( $id ) { return count( array_filter( $GLOBALS['DOWNLOADS'], function ( $d ) use ( $id ) { return (int) $d[0] === $id; } ) ); }
/** Whether a timestamp is about this many seconds from now. */
function in_about( $ts, $seconds ) { return abs( (int) $ts - ( time() + $seconds ) ) <= 15; }
function log_has( $text ) {
	foreach ( $GLOBALS['LOG'] as $line ) {
		if ( false !== strpos( $line, $text ) ) {
			return true;
		}
	}
	return false;
}
function site_log_has( $text ) { $p = $GLOBALS['WORLD'] . '/activity.log'; return is_file( $p ) && false !== strpos( file_get_contents( $p ), $text ); }

echo "\nTHE ATTEMPT IS CHARGED BEFORE ANY DOWNLOAD\n" . str_repeat( '=', 122 ) . "\n";

fresh_site();
product( 101, array( photo( 1 ), photo( 2 ) ) );
$seen  = null;
$PHOTO = function ( $url, $id ) use ( &$seen ) {
	if ( null === $seen ) {
		$seen = array( (int) get_post_meta( $id, '_tcgiant_localize_attempts', true ), (int) get_post_meta( $id, '_tcgiant_localize_retry_after', true ) );
	}
	// From inside, a request killed mid-download is one that never comes back.
	throw new RuntimeException( 'killed mid-download' );
};
try {
	localize( 101 );
} catch ( RuntimeException $e ) {
	// As far as this product's code is concerned, the request is over.
}

check( 'when the first photo is asked for, the attempt is already counted', $seen[0] ?? null, 1 );
check( '  and the product leased for fifteen minutes', in_about( $seen[1] ?? 0, 900 ), true );
check( 'a run that never comes back leaves it counted', (int) meta( 101, '_tcgiant_localize_attempts' ), 1 );
check( '  and out of the queue until the lease runs out', in_array( 101, due_products( time() ), true ), false );
check( '  back in it once it has', in_array( 101, due_products( time() + 901 ), true ), true );
check( '  its second photo was never reached', downloads_of( 101 ), 1 );

echo "\nA PRODUCT THAT GETS FURTHER EACH TIME IS NOT GIVEN UP\n" . str_repeat( '=', 122 ) . "\n";

// Many photos on a host that allows a few per request: every attempt dies,
// but each fetched more, and what it fetched is kept. Three attempts charged,
// the last died having taken the count from 2 to 4 - progress, not failure.
fresh_site();
product( 105, array( photo( 11 ), photo( 12 ) ), array(
	'_tcgiant_localize_attempts'    => 3,
	'_tcgiant_localize_retry_after' => time() - 60,
	'_tcgiant_localize_inflight'    => 3,
	'_tcgiant_localize_progress'    => 4,
) );
localize( 105 );
check( 'it is tried again', downloads_of( 105 ) > 0, true );
check( '  not given up', log_has( 'WC #105 gave up after' ), false );

// The same, with no progress since: those deaths do count.
fresh_site();
product( 106, array( photo( 13 ), photo( 14 ) ), array(
	'_tcgiant_localize_attempts'    => 3,
	'_tcgiant_localize_retry_after' => time() - 60,
	'_tcgiant_localize_inflight'    => 5,
	'_tcgiant_localize_progress'    => 4,
) );
localize( 106 );
check( 'with nothing gained by the last death, it is given up', downloads_of( 106 ), 0 );

echo "\nA PRODUCT WHOSE ATTEMPTS ALL NEVER FINISHED IS GIVEN UP\n" . str_repeat( '=', 122 ) . "\n";

fresh_site();
// Three attempts charged and the last still marked in flight: none of them
// came back. (Attempts that FINISHED and failed are retried when the product
// is queued again - see the images-only sync case below.)
product( 102, array( photo( 3 ), photo( 4 ) ), array( '_tcgiant_localize_attempts' => 3, '_tcgiant_localize_retry_after' => time() - 60, '_tcgiant_localize_inflight' => 1 ) );
$placeholder = get_post_thumbnail_id( 102 );
$r = localize( 102 );

check( 'three attempts used up: no fourth download', downloads_of( 102 ), 0 );
check( '  it leaves the queue', done( 102 ), true );
check( '  with nothing pending for it', has_meta( 102, '_tcgiant_localize_retry_after' ), false );
check( '  its eBay photo stays in place', get_post_thumbnail_id( 102 ), $placeholder );
check( '  the batch counts its photos as failed', $r['failed'], 2 );
check( '  and the log says so, naming it', log_has( '[error] Image localizer: WC #102 gave up after 3 attempts' ), true );

// One short of the limit, the last attempt is still made.
fresh_site();
product( 105, array( photo( 5 ) ), array( '_tcgiant_localize_attempts' => 2, '_tcgiant_localize_retry_after' => time() - 60 ) );
$PHOTO = function () { return timeout(); };
localize( 105 );

check( 'two attempts used: the third is still made', downloads_of( 105 ), 1 );
check( '  and when it fails too, the product is given up', array( (int) meta( 105, '_tcgiant_localize_attempts' ), done( 105 ) ), array( 3, true ) );

echo "\nAN ORDINARY TIMEOUT IS COUNTED ONCE\n" . str_repeat( '=', 122 ) . "\n";

fresh_site();
product( 103, array( photo( 6 ) ) );
$PHOTO = function () { return timeout(); };
localize( 103 );

check( 'a timed-out photo is one attempt, not two', (int) meta( 103, '_tcgiant_localize_attempts' ), 1 );
check( '  the log calls it attempt 1 of 3', log_has( 'attempt 1 of 3' ), true );
check( '  ten minutes\' backoff replaces the lease', in_about( meta( 103, '_tcgiant_localize_retry_after' ), 600 ), true );
check( '  and it stays queued', done( 103 ), false );

time_passes( 601 );
localize( 103 );
check( 'the second is attempt 2, twenty minutes off', array( (int) meta( 103, '_tcgiant_localize_attempts' ), in_about( meta( 103, '_tcgiant_localize_retry_after' ), 1200 ) ), array( 2, true ) );

time_passes( 1201 );
localize( 103 );
check( 'the third gives it up', done( 103 ), true );
check( '  three downloads in all, as before', downloads_of( 103 ), 3 );

echo "\nA SUCCESS CLEARS THE SLATE\n" . str_repeat( '=', 122 ) . "\n";

fresh_site();
product( 104, array( photo( 7 ), photo( 8 ) ), array( '_tcgiant_localize_attempts' => 2, '_tcgiant_localize_retry_after' => time() - 60 ) );
$r = localize( 104 );

check( 'both photos downloaded', $r['downloaded'], 2 );
check( '  the product is done', done( 104 ), true );
check( '  its attempts are cleared', has_meta( 104, '_tcgiant_localize_attempts' ), false );
check( '  and so is its lease', has_meta( 104, '_tcgiant_localize_retry_after' ), false );
check( '  it shows the shop\'s own copy', own_photo( 104 ), true );

echo "\nONE PRODUCT THAT THROWS DOES NOT END THE BATCH\n" . str_repeat( '=', 122 ) . "\n";

fresh_site();
product( 201, array( photo( 21 ) ) );
product( 202, array( photo( 22 ) ) );
product( 203, array( photo( 23 ) ) );
$PHOTO = function ( $url ) {
	if ( photo( 22 ) === $url ) {
		// An Error, not an Exception: what a host without WebP support in GD
		// throws on an eBay .webp photo.
		throw new Error( 'Call to undefined function imagecreatefromwebp()' );
	}
	return 'ok';
};
$escaped = batch();

check( 'nothing one product throws escapes the batch', $escaped, '' );
check( '  the product before it is done', done( 201 ), true );
check( '  and so is the one after it', done( 203 ), true );
check( '  the one that threw is counted, and leased', array( (int) meta( 202, '_tcgiant_localize_attempts' ), in_about( meta( 202, '_tcgiant_localize_retry_after' ), 900 ) ), array( 1, true ) );
check( '  the log names it', log_has( '[error] Image localizer: WC #202 failed: Call to undefined function imagecreatefromwebp()' ), true );
check( '  and the batch line counts it', log_has( 'Batch complete. 2 downloaded, 0 skipped, 1 failed.' ), true );
check( 'the JPEG quality setting does not outlive the batch', filters_on( 'wp_editor_set_quality' ), 0 );
check( '  nor does the download cap', filters_on( 'http_request_args' ), 0 );
check( '  nor does skipping the two largest photo sizes, though WC #202 threw in its download', filters_on( 'intermediate_image_sizes_advanced' ), 0 );

$SCHEDULED = array();
batch();
check( 'the next batch, straight after, does not start with it again', downloads_of( 202 ), 1 );
check( '  nothing else is due, so it looks again when the lease is up', in_about( end( $SCHEDULED ), 900 ), true );

echo "\nONE SLOW EBAY PHOTO CANNOT HOLD A BATCH FOR FIVE MINUTES\n" . str_repeat( '=', 122 ) . "\n";

fresh_site();
product( 211, array( photo( 31 ) ) );
$asked = array();
$PHOTO = function () use ( &$asked ) {
	// Other requests made while the batch is downloading, and what WP_Http
	// would send for each.
	foreach ( array(
		'short'     => array( photo( 31 ), 10 ),
		'thumbs'    => array( 'https://thumbs.ebayimg.com/images/g/P031AAOSwAbC/s-l225.jpg', 300 ),
		'upper'     => array( 'https://I.EBAYIMG.COM/images/g/P031AAOSwAbC/s-l1600.jpg', 300 ),
		'api'       => array( 'https://api.ebay.com/ws/api.dll', 300 ),
		'other'     => array( 'https://images.example.com/photo.jpg', 300 ),
		'lookalike' => array( 'https://i.ebayimg.com.example.net/photo.jpg', 300 ),
		'suffix'    => array( 'https://notebayimg.com/photo.jpg', 300 ),
	) as $k => $req ) {
		$asked[ $k ] = apply_filters( 'http_request_args', download_args( $req[1] ), $req[0] );
	}
	return 'ok';
};
batch();

$sent = $ARGS_SEEN[0][1] ?? array();
$rest = function ( $args ) { unset( $args['timeout'], $args['limit_response_size'] ); return $args; };

check( 'an eBay photo download may take 30 s, not 300', (int) ( $sent['timeout'] ?? 0 ), 30 );
check( '  and fetch at most 15 MB', $sent['limit_response_size'] ?? null, 15728640 );
check( '  nothing else about the request is changed', $rest( $sent ) === $rest( download_args( 300 ) ), true );
check( 'a shorter timeout someone else set is kept', (int) ( $asked['short']['timeout'] ?? 0 ), 10 );
check( 'eBay\'s other photo hosts are capped too', (int) ( $asked['thumbs']['timeout'] ?? 0 ), 30 );
check( '  whatever case the address is written in', (int) ( $asked['upper']['timeout'] ?? 0 ), 30 );
check( 'eBay\'s API is not a photo host: untouched', ( $asked['api'] ?? null ) === download_args( 300 ), true );
check( 'another host\'s request is untouched', ( $asked['other'] ?? null ) === download_args( 300 ), true );
check( '  so is one that only starts i.ebayimg.com', ( $asked['lookalike'] ?? null ) === download_args( 300 ), true );
check( '  and one that only ends in ebayimg.com', ( $asked['suffix'] ?? null ) === download_args( 300 ), true );
check( 'after the batch the cap is gone', filters_on( 'http_request_args' ), 0 );
check( '  an eBay photo fetched later is sent as asked', apply_filters( 'http_request_args', download_args( 300 ), photo( 31 ) ) === download_args( 300 ), true );

echo "\nA PHOTO THAT GETS THE BATCH KILLED, EVERY TIME\n" . str_repeat( '=', 122 ) . "\n";

// Each batch is a request of its own, and WC #311's photo kills it outright.
fresh_site();
foreach ( array( 311, 312, 313, 314, 315 ) as $n => $id ) {
	product( $id, array( photo( 40 + $n ) ) );
}
$KILLS       = array( photo( 40 ) );
$placeholder = get_post_thumbnail_id( 311 );
one_batch();

check( 'the batch is killed on WC #311, first in the queue', array( downloads_of( 311 ), done( 312 ) ), array( 1, false ) );
check( '  the log says how, and on which product', site_log_has( 'Image localiser stopped by a PHP fatal error' ) && site_log_has( 'while working on WC #311' ), true );
check( '  its attempt was counted before it died', (int) meta( 311, '_tcgiant_localize_attempts' ), 1 );
check( '  and it is leased for fifteen minutes', in_about( meta( 311, '_tcgiant_localize_retry_after' ), 900 ), true );

one_batch();
check( 'the next batch, straight after, does not start with it again', downloads_of( 311 ), 1 );
check( '  and gets the rest of the queue done', array( done( 312 ), done( 313 ), done( 314 ), done( 315 ) ), array( true, true, true, true ) );

time_passes( 901 );
one_batch(); // killed again
time_passes( 901 );
one_batch(); // and again
time_passes( 901 );
one_batch();

check( 'WC #311 is tried three times in all, fifteen minutes apart', downloads_of( 311 ), 3 );
check( '  then given up on without a fourth download', done( 311 ), true );
check( '  keeping its eBay photo', get_post_thumbnail_id( 311 ), $placeholder );
check( '  and the log says why', site_log_has( 'WC #311 gave up after 3 attempts that never finished' ), true );
check( 'the queue is empty', due_products( time() + 86400 ), array() );

echo "\nWHAT THE GIVE-UP LINE TELLS THE SHOP TO DO STILL WORKS\n" . str_repeat( '=', 122 ) . "\n";

// eBay's photo server times out all day: three ordinary failures, an hour
// apart, and the product is given up on - in 3.26.1 and 3.27.0 alike.
fresh_site();
product( 501, array( photo( 51 ) ) );
$PHOTO = function () { return timeout(); };
for ( $i = 0; $i < 3; $i++ ) {
	batch();
	time_passes( 3600 );
}

check( 'three timed-out attempts: given up', array( (int) meta( 501, '_tcgiant_localize_attempts' ), done( 501 ) ), array( 3, true ) );
check( '  the log tells the shop to re-run an images-only sync', log_has( 'Re-run an images-only sync for this item to try again' ), true );

// The server is back, and the shop does as it was told. An images-only sync
// hands the product's eBay photos back unchanged - start_images_only_sync()
// calls set_external_images() without $force - and that queues it again.
$PHOTO = function () { return 'ok'; };
$LOG   = array();
TCGiant_Sync_Image_Localizer::set_external_images( 501, array( photo( 51 ) ) );
check( 'the re-run queues it again', done( 501 ), false );

batch();
check( '  and the next batch really tries its photo again', downloads_of( 501 ), 4 );
check( '  so the product ends with the shop\'s own copy', own_photo( 501 ), true );
check( '  and no line claims a request was stopped on it 3 times', log_has( 'attempts that never finished' ), false );

echo "\nA PRODUCT WITH MORE PHOTOS THAN ONE REQUEST HAS TIME FOR\n" . str_repeat( '=', 122 ) . "\n";

// Dobson Parts' host ends a request three minutes in, and one product's
// photos took longer than that: every batch started on it, died on it, and
// sixteen minutes later started on it again, with 454 products waiting
// behind. The batch only looked at the clock between products. Now it looks
// between photos too, and a product too big for one request is finished over
// several, each well inside the budget.
//
// A clock that moves only when a photo is fetched: 25 s a photo, 60 s budget.
$FAKE_NOW = 2000000.0;
TCGiant_Sync_Runtime::$clock = function () { return $GLOBALS['FAKE_NOW']; };
function new_request( $already = 0 ) {
	$_SERVER['REQUEST_TIME_FLOAT'] = $GLOBALS['FAKE_NOW'] - $already;
}
$slow = function ( $seconds ) {
	return function () use ( $seconds ) {
		$GLOBALS['FAKE_NOW'] += $seconds;
		return 'ok';
	};
};
function gallery( $id ) { return array_values( array_filter( explode( ',', (string) meta( $id, '_product_image_gallery' ) ) ) ); }
function stand_ins( $id ) {
	return array_values( array_filter( gallery( $id ), function ( $g ) { return '' !== (string) meta( (int) $g, '_tcgiant_external_url' ); } ) );
}
function stand_ins_left( $id ) {
	$n = 0;
	foreach ( $GLOBALS['META'] as $m ) {
		if ( (int) ( $m['_parent'] ?? 0 ) === $id && '' !== (string) ( $m['_tcgiant_external_url'] ?? '' ) ) {
			$n++;
		}
	}
	return $n;
}

fresh_site();
product( 601, array( photo( 61 ), photo( 62 ), photo( 63 ), photo( 64 ), photo( 65 ), photo( 66 ) ) );
$before_gallery = gallery( 601 );
$fallback_at    = null;
$PHOTO = function () use ( &$fallback_at ) {
	if ( null === $fallback_at ) {
		$fallback_at = end( $GLOBALS['SCHEDULED'] );
	}
	$GLOBALS['FAKE_NOW'] += 25;
	return 'ok';
};
new_request();
batch();

check( 'the batch fetches what fits, then stops between photos', downloads_of( 601 ), 3 );
check( '  saying where, and that it carries on', log_has( 'WC #601 paused at photo 4 of 6' ), true );
check( '  an ordinary end to the batch, not a death', log_has( 'Batch complete. 3 downloaded, 0 skipped, 0 failed.' ), true );
check( '  no warning or error', count( array_filter( $LOG, function ( $l ) { return 0 === strpos( $l, '[warning]' ) || 0 === strpos( $l, '[error]' ); } ) ), 0 );
check( '  not done yet', done( 601 ), false );
check( '  the attempt handed back: a pause is not a failure', (int) meta( 601, '_tcgiant_localize_attempts' ), 0 );
check( '  no mark of a death left', array( has_meta( 601, '_tcgiant_localize_inflight' ), has_meta( 601, '_tcgiant_localize_progress' ) ), array( false, false ) );
check( '  due again in a minute', in_about( meta( 601, '_tcgiant_localize_retry_after' ), 60 ), true );
check( '  and the next batch then', in_about( end( $SCHEDULED ), 60 ), true );
check( '  its main photo is the shop\'s own copy already', own_photo( 601 ), true );
check( '  its gallery untouched meanwhile: every photo still shows', gallery( 601 ), $before_gallery );
check( 'while it worked, the run after a death was set inside the lease', in_about( $fallback_at, 300 ), true );

time_passes( 61 );
$LOG = array();
new_request();
batch();

check( 'the next batch carries on from photo 4', downloads_of( 601 ), 6 );
check( '  fetching none twice', log_has( 'Batch complete. 3 downloaded, 3 skipped, 0 failed.' ), true );
check( '  and finishes the product', done( 601 ), true );
check( '  gallery: the five other photos, all the shop\'s own copies', array( count( gallery( 601 ) ), stand_ins( 601 ) ), array( 5, array() ) );
check( '  the main photo is not in it twice', in_array( (string) get_post_thumbnail_id( 601 ), gallery( 601 ), true ), false );
check( '  no eBay stand-in left behind', stand_ins_left( 601 ), 0 );
check( '  and its counts cleared', array( has_meta( 601, '_tcgiant_localize_attempts' ), has_meta( 601, '_tcgiant_localize_pauses' ) ), array( false, false ) );

// Two products: the one that paused waits its minute while the other goes.
fresh_site();
product( 611, array( photo( 71 ), photo( 72 ), photo( 73 ), photo( 74 ) ) );
product( 612, array( photo( 75 ) ) );
$PHOTO = $slow( 40 );
new_request();
batch();
check( 'a pause ends the batch there', array( downloads_of( 611 ), downloads_of( 612 ) ), array( 2, 0 ) );
check( '  with the next batch in 30 s, for the product behind it', in_about( end( $SCHEDULED ), 30 ), true );
time_passes( 31 );
new_request();
batch();
check( '  which that batch finishes, while the first waits its minute', array( downloads_of( 611 ), done( 612 ) ), array( 2, true ) );
time_passes( 31 );
new_request();
batch();
check( '  then the first carries on, and finishes', array( downloads_of( 611 ), done( 611 ) ), array( 4, true ) );

// A batch that starts with its time already gone - after other cron jobs in
// the same request - still fetches one photo each time, and is never given up.
fresh_site();
product( 621, array( photo( 81 ), photo( 82 ), photo( 83 ) ) );
$PHOTO = $slow( 5 );
for ( $r = 0; $r < 3; $r++ ) {
	new_request( 100 );
	batch();
	time_passes( 61 );
}
check( 'a request with its time used up: one photo each, three requests', array( downloads_of( 621 ), done( 621 ) ), array( 3, true ) );
check( '  never counted as a failed attempt', log_has( 'gave up' ), false );

// A product already near its last attempt is not pushed over it by pausing.
fresh_site();
product( 631, array( photo( 91 ), photo( 92 ), photo( 93 ) ), array( '_tcgiant_localize_attempts' => 2 ) );
$PHOTO = $slow( 40 );
new_request();
batch();
check( 'two attempts used, then a pause: still two', (int) meta( 631, '_tcgiant_localize_attempts' ), 2 );
time_passes( 61 );
new_request();
batch();
time_passes( 61 );
new_request();
batch();
check( '  and it finishes, not given up', array( done( 631 ), log_has( 'gave up' ) ), array( true, false ) );

// Overwrite Images on: eBay's photos replace the gallery once the product is
// done, and until then it is left alone.
fresh_site();
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'overwrite_images' => 1 );
product( 641, array( photo( 101 ), photo( 102 ), photo( 103 ), photo( 104 ) ) );
$before_gallery = gallery( 641 );
$PHOTO = $slow( 40 );
new_request();
batch();
check( 'Overwrite Images: the pause leaves the gallery as it was', gallery( 641 ), $before_gallery );
time_passes( 61 );
new_request();
batch();
check( '  finished: three copies, no stand-ins, main photo not repeated', array( count( gallery( 641 ) ), stand_ins( 641 ), in_array( (string) get_post_thumbnail_id( 641 ), gallery( 641 ), true ) ), array( 3, array(), false ) );
check( '  every photo fetched once', downloads_of( 641 ), 4 );

// A photo kept on the seller's own server is held to the same 30 s as one on
// eBay's - it had all of media_sideload_image()'s 300 s, longer than some
// hosts let a request run - but not cut short in size.
fresh_site();
product( 651, array( 'https://parts.example.com/photos/651-front.jpg' ) );
$PHOTO = function () { return 'ok'; };
new_request();
batch();
$sent = $ARGS_SEEN[0][1] ?? array();
check( 'a photo on another server: 30 s', (int) ( $sent['timeout'] ?? 0 ), 30 );
check( '  and no size limit', $sent['limit_response_size'] ?? null, null );
check( '  done', done( 651 ), true );

// A new photo set starts the pause count again, as it does the attempts: a
// count left from the old set would otherwise charge the new one's pauses.
fresh_site();
product( 661, array( photo( 111 ), photo( 112 ) ), array( '_tcgiant_localize_pauses' => 9, '_tcgiant_localize_attempts' => 2 ) );
TCGiant_Sync_Image_Localizer::set_external_images( 661, array( photo( 113 ) ) );
check( 'a new photo set: pauses and attempts start again', array( has_meta( 661, '_tcgiant_localize_pauses' ), has_meta( 661, '_tcgiant_localize_attempts' ) ), array( false, false ) );

// Giving up clears the pause count with the rest.
fresh_site();
product( 671, array( photo( 121 ) ), array( '_tcgiant_localize_pauses' => 1, '_tcgiant_localize_attempts' => 2 ) );
$PHOTO = function () { return timeout(); };
new_request();
batch();
check( 'given up: the pause count goes too', array( done( 671 ), has_meta( 671, '_tcgiant_localize_pauses' ) ), array( true, false ) );

// A timeout is never taken for a missing photo. The numbers in a timeout's
// message used to be matched on their own: "30403 milliseconds" read as a 403,
// and the photo was dropped for good.
fresh_site();
product( 681, array( photo( 131 ) ) );
$PHOTO = function () { return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30403 milliseconds with 0 out of 0 bytes received' ); };
new_request();
batch();
check( 'a timeout "after 30403 milliseconds": tried again, not dropped', array( done( 681 ), log_has( '[PERMANENT]' ) ), array( false, false ) );
fresh_site();
product( 682, array( photo( 132 ) ) );
// What download_url() really returns: "http_404" for every status but 200,
// with the status itself in the data.
$PHOTO = function () { return new WP_Error( 'http_404', 'Not Found', array( 'code' => 404 ) ); };
new_request();
batch();
check( 'a real 404: dropped, and said so', array( done( 682 ), log_has( '[PERMANENT]' ) ), array( true, true ) );
foreach ( array( 503 => 'Service Unavailable', 429 => 'Too Many Requests', 502 => 'Bad Gateway' ) as $status => $phrase ) {
	fresh_site();
	product( 683, array( photo( 133 ) ) );
	$PHOTO = function () use ( $status, $phrase ) { return new WP_Error( 'http_404', $phrase, array( 'code' => $status ) ); };
	new_request();
	batch();
	check( "a $status from the photo server: tried again, not dropped", array( done( 683 ), log_has( '[PERMANENT]' ) ), array( false, false ) );
}
fresh_site();
product( 684, array( photo( 134 ) ) );
$PHOTO = function () { return new WP_Error( 'http_404', 'Gone', array( 'code' => 410 ) ); };
new_request();
batch();
check( 'a 410: dropped', done( 684 ), true );

// A product already showing its main photo twice, from an earlier version, is
// put right the next time its photos are fetched; a photo the shop put in the
// gallery itself is left alone.
fresh_site();
product( 691, array( photo( 141 ), photo( 142 ) ) );
$PHOTO = function () { return 'ok'; };
new_request();
batch();
$main = get_post_thumbnail_id( 691 );
$own  = wp_insert_attachment( array(), false, 691 ); // the shop's own upload
update_post_meta( 691, '_product_image_gallery', implode( ',', array_merge( array( $main ), gallery( 691 ), array( $own ) ) ) );
TCGiant_Sync_Image_Localizer::set_external_images( 691, array( photo( 141 ), photo( 142 ) ), true );
new_request();
batch();
check( 'shown twice before: the main photo leaves the gallery', in_array( (string) $main, gallery( 691 ), true ), false );
check( '  the shop\'s own photo stays', in_array( (string) $own, gallery( 691 ), true ), true );

// Back to the frozen clock the earlier sections use.
TCGiant_Sync_Runtime::$clock = function () { return (float) $_SERVER['REQUEST_TIME_FLOAT']; };
$OPTIONS = array();

echo "\nA PHOTO WAITING TO BE REMOVED IS STILL THE PHOTO OF THE PRODUCT THAT SHOWS IT\n" . str_repeat( '=', 122 ) . "\n";

// 3.27.4 removes the photos of a product deleted for good, fifteen minutes
// on, and while a photo waits the batch reuses it for no other product: it
// downloads a fresh copy instead, so that nothing is left pointing at a photo
// about to go. A product that already shows the photo, or that the photo is
// attached to, is no other product - the removal keeps a photo either shows.
// Before this was put right such a product, fetched again inside those
// fifteen minutes, downloaded every photo it showed again, showed each twice,
// and kept both copies for good.

/** What a product shows: its main photo, then its gallery. */
function shown( $id ) { return array_merge( array( (string) get_post_thumbnail_id( $id ) ), gallery( $id ) ); }
function sources_of( array $ids ) { return array_map( function ( $g ) { return meta( (int) $g, '_tcgiant_source_url' ); }, $ids ); }
/** A product deleted for good: its photos attached to nothing, and every photo it showed waiting to be removed. */
function deleted_for_good( $id ) {
	$showed = shown( $id );
	unset( $GLOBALS['META'][ $id ] );
	$GLOBALS['PRODUCTS'] = array_values( array_diff( $GLOBALS['PRODUCTS'], array( $id ) ) );
	foreach ( $GLOBALS['META'] as $att => $m ) {
		if ( isset( $m['_parent'] ) && (int) $m['_parent'] === $id ) {
			$GLOBALS['META'][ $att ]['_parent'] = 0;
		}
	}
	foreach ( $showed as $att ) {
		$GLOBALS['META'][ (int) $att ]['_tcgiant_delete_after'] = (string) ( time() + 900 );
	}
}

// D, the listing that sold, downloaded its photos. X, its relist, shows them.
fresh_site();
$urls = array( photo( 201 ), photo( 202 ), photo( 203 ) );
product( 400, $urls );
localize( 400 );
$d = shown( 400 );
product( 500, $urls );
localize( 500 );
check( 'the relist shows the sold listing\'s downloads', array( shown( 500 ), count( $DOWNLOADS ) ), array( $d, 3 ) );

deleted_for_good( 400 );
// Inside the fifteen minutes the seller adds a photo on eBay; X is fetched again.
TCGiant_Sync_Image_Localizer::set_external_images( 500, array_merge( $urls, array( photo( 204 ) ) ) );
localize( 500 );
$x = shown( 500 );
check( 'X fetched again while the photos it shows wait: it keeps them, downloading only the new one', array( array_slice( $x, 0, 3 ), count( $x ), count( $DOWNLOADS ) ), array( $d, 4, 4 ) );
check( '  each eBay photo shown once', count( array_unique( sources_of( $x ) ) ), 4 );
product( 700, array( photo( 201 ) ) );
localize( 700 );
check( 'another product gets a fresh copy, never a photo waiting to be removed', array( count( $DOWNLOADS ), in_array( (string) get_post_thumbnail_id( 700 ), $d, true ) ), array( 5, false ) );

// X downloaded its photos itself; D, another listing showing them, is deleted.
fresh_site();
$urls = array( photo( 211 ), photo( 212 ) );
product( 600, $urls );
localize( 600 );
$own = shown( 600 );
product( 601, $urls );
localize( 601 );
check( 'a second listing reuses X\'s downloads', array( shown( 601 ), count( $DOWNLOADS ) ), array( $own, 2 ) );
deleted_for_good( 601 ); // which leaves X's own photos waiting
TCGiant_Sync_Image_Localizer::set_external_images( 600, $urls, true ); // an images-only sync
localize( 600 );
check( 'X\'s own photos, waiting because a product showing them was deleted: reused, not fetched again', array( count( $DOWNLOADS ), shown( 600 ) ), array( 2, $own ) );

// One given up on is never reused, by anyone: part of it may already be gone.
$META[ (int) $own[1] ]['_tcgiant_delete_failed'] = (string) time();
TCGiant_Sync_Image_Localizer::set_external_images( 600, $urls, true );
localize( 600 );
check( 'one whose removal was given up on: fetched again, even for its own product', count( $DOWNLOADS ), 3 );

// A product left showing two copies of one eBay photo - by the version before
// this was put right, say - shows it once the next time its photos are
// fetched. The copy it showed first stays; the shop's own photos, which carry
// no record of where they came from, are not compared.
fresh_site();
$urls = array( photo( 221 ), photo( 222 ) );
product( 610, $urls );
localize( 610 );
$first = shown( 610 );
$twice = array();
foreach ( array( 222, 221 ) as $n ) {
	$copy = wp_insert_attachment( array(), false, 0 );
	update_post_meta( $copy, '_tcgiant_source_url', photo( $n ) );
	$twice[] = (string) $copy;
}
$mine = array( (string) wp_insert_attachment( array(), false, 610 ), (string) wp_insert_attachment( array(), false, 610 ) );
update_post_meta( 610, '_product_image_gallery', implode( ',', array_merge( array( $first[1] ), $twice, $mine ) ) );
TCGiant_Sync_Image_Localizer::set_external_images( 610, $urls, true );
localize( 610 );
check( 'two copies of one eBay photo shown: shown once, the first kept; the shop\'s own left alone', shown( 610 ), array_merge( $first, $mine ) );

foreach ( array( 'site.json', 'activity.log', 'wp-admin/includes/media.php', 'wp-admin/includes/file.php', 'wp-admin/includes/image.php' ) as $f ) {
	@unlink( "$WORLD/$f" );
}
@rmdir( "$WORLD/wp-admin/includes" );
@rmdir( "$WORLD/wp-admin" );
@rmdir( $WORLD );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
