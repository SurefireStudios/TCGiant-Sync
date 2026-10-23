<?php
/**
 * Harness: the copies WordPress makes of a photo downloaded from eBay.
 *
 * WordPress makes every registered size smaller than a photo when it is
 * added to the media library - WordPress's own, WooCommerce's, the theme's.
 * WordPress 5.3 added two for very large uploads, 1536x1536 and 2048x2048.
 * eBay serves its photos at 1,600 px at most, so 2048x2048 is never made from
 * one, and 1536x1536 is the same photo 4% smaller: on test photos about 264 KB
 * of the 980 KB each 1,600 px photo took with all its sizes. Dobson Parts,
 * fifteen thousand parts and running out of disk, was paying that on every
 * photo.
 *
 * 3.27.4 has WordPress skip those two for the photos the image batch
 * downloads, while it downloads each one; 3.27.7 keeps them off the plugin's
 * photos whenever their sizes are made again - a tool that regenerates
 * thumbnails brought them back - and never touches the shop's own uploads.
 * This loads the real
 * TCGiant_Sync_Image_Localizer with WordPress stubbed, plays
 * media_sideload_image() making sizes the way wp_create_image_subsizes() does
 * - the big-image limit, then intermediate_image_sizes_advanced with the
 * photo's metadata and attachment ID, then every size smaller than the
 * photo - and drives localize_product_images() through reflection.
 *
 * Hooks here keep WordPress's priorities, since the order the filters run in
 * is part of what is being checked.
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

$ROOT = dirname( __DIR__ );

define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$META     = array();
$OPTIONS  = array();
$HOOKS    = array();
$LOG      = array();
$NEXT_ID  = 9000;
$PHOTO    = null; // what the next download does: a [ width, height ] photo, a WP_Error, or a Throwable
$SEEN     = array(); // what intermediate_image_sizes_advanced was handed, per download

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
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['META'][ $id ][ $key ] ?? ''; }
/** The photo's type, as its row keeps it: here, what a test says, else a JPEG. */
function get_post_mime_type( $id = null ) { return $GLOBALS['META'][ (int) $id ]['_mime'] ?? 'image/jpeg'; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['META'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['META'][ $id ][ $key ] ); return true; }
function has_post_thumbnail( $id ) { return ! empty( $GLOBALS['META'][ $id ]['_thumbnail_id'] ); }
function get_post_thumbnail_id( $id ) { return (int) ( $GLOBALS['META'][ $id ]['_thumbnail_id'] ?? 0 ); }
function set_post_thumbnail( $id, $thumb ) { return update_post_meta( $id, '_thumbnail_id', (int) $thumb ); }
function wc_get_product( $id ) { return (object) array( 'id' => (int) $id ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_insert_attachment( $args, $file = false, $parent = 0 ) {
	$id = ++$GLOBALS['NEXT_ID'];
	$GLOBALS['META'][ $id ] = array( '_parent' => (int) $parent );
	return $id;
}
function wp_delete_attachment( $id, $force = false ) { unset( $GLOBALS['META'][ $id ] ); return true; }
function get_posts( $args ) { return array(); }
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_single_event( $when, $hook ) { return true; }
function wp_clear_scheduled_hook( $hook ) { return 0; }

// Hooks as WordPress keeps them: by priority, then in the order added.
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	// The same callback at the same priority is kept once, as WordPress keys it.
	foreach ( $GLOBALS['HOOKS'][ $hook ][ $priority ] ?? array() as $h ) {
		if ( $h[0] === $callback ) {
			return true;
		}
	}
	$GLOBALS['HOOKS'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	return true;
}
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return add_filter( $hook, $callback, $priority, $accepted_args ); }
function remove_filter( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ][ $priority ] ?? array() as $i => $h ) {
		if ( $h[0] === $callback ) {
			unset( $GLOBALS['HOOKS'][ $hook ][ $priority ][ $i ] );
			return true;
		}
	}
	return false;
}
function apply_filters( $hook, $value, ...$more ) {
	$by_priority = $GLOBALS['HOOKS'][ $hook ] ?? array();
	ksort( $by_priority );
	$GLOBALS['RUNNING'][] = $hook;
	try {
		foreach ( $by_priority as $callbacks ) {
			foreach ( $callbacks as $h ) {
				$value = call_user_func_array( $h[0], array_slice( array_merge( array( $value ), $more ), 0, $h[1] ) );
			}
		}
	} finally {
		array_pop( $GLOBALS['RUNNING'] );
	}
	return $value;
}
/** The filter being run, as WordPress keeps it. */
function current_filter() {
	return empty( $GLOBALS['RUNNING'] ) ? false : end( $GLOBALS['RUNNING'] );
}
/** The priority a callback is on a hook at, or false: has_filter(). */
function has_filter( $hook, $callback = false ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $priority => $callbacks ) {
		foreach ( $callbacks as $h ) {
			if ( $h[0] === $callback ) {
				return $priority;
			}
		}
	}
	return false;
}
function filters_on( $hook ) {
	$n = 0;
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $callbacks ) {
		$n += count( $callbacks );
	}
	return $n;
}
/** How many times a callback is on a hook. */
function hooked( $hook, $callback ) {
	$n = 0;
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $callbacks ) {
		foreach ( $callbacks as $h ) {
			$n += $h[0] === $callback ? 1 : 0;
		}
	}
	return $n;
}
$DOWNLOAD_HOOK = array( 'TCGiant_Sync_Image_Localizer', 'import_image_sizes' );
$GUARD         = array( 'TCGiant_Sync_Image_Localizer', 'guard_large_sizes' );

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = "[$level] $message"; }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function write_now( $message, $level = 'error' ) { self::log( $message, $level ); }
}

/**
 * The sizes a WooCommerce shop has registered: WordPress's own, the two
 * WordPress 5.3 added, and WooCommerce's three at their defaults - what
 * wp_get_registered_image_subsizes() returns on a shop with no theme sizes.
 */
function registered_sizes() {
	return array(
		'thumbnail'                     => array( 'width' => 150, 'height' => 150, 'crop' => true ),
		'medium'                        => array( 'width' => 300, 'height' => 300, 'crop' => false ),
		'medium_large'                  => array( 'width' => 768, 'height' => 0, 'crop' => false ),
		'large'                         => array( 'width' => 1024, 'height' => 1024, 'crop' => false ),
		'1536x1536'                     => array( 'width' => 1536, 'height' => 1536, 'crop' => false ),
		'2048x2048'                     => array( 'width' => 2048, 'height' => 2048, 'crop' => false ),
		'woocommerce_thumbnail'         => array( 'width' => 300, 'height' => 300, 'crop' => true ),
		'woocommerce_single'            => array( 'width' => 600, 'height' => 0, 'crop' => false ),
		'woocommerce_gallery_thumbnail' => array( 'width' => 100, 'height' => 100, 'crop' => true ),
	);
}

/**
 * The dimensions WordPress gives one size of a photo, or null where it makes
 * none - image_resize_dimensions(), which never enlarges.
 */
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

/**
 * The sizes made for a [ width, height ] photo, the way
 * wp_create_image_subsizes() makes them: a photo over the big-image limit is
 * scaled to it first and that becomes the full size, then the sizes to make
 * go through intermediate_image_sizes_advanced with the metadata so far and
 * the attachment ID, then each one smaller than the photo is made.
 */
function make_sizes( $attachment_id, $w, $h ) {
	$meta      = array( 'width' => $w, 'height' => $h, 'file' => "2026/10/photo-$attachment_id." . ( $GLOBALS['PHOTO_EXT'] ?? 'jpg' ), 'sizes' => array() );
	$threshold = (int) apply_filters( 'big_image_size_threshold', 2560, array( $w, $h ), 'photo.jpg', $attachment_id );
	if ( $threshold && max( $w, $h ) > $threshold ) {
		$k                      = $threshold / max( $w, $h );
		$meta['width']          = (int) round( $w * $k );
		$meta['height']         = (int) round( $h * $k );
		$meta['original_image'] = "photo-$attachment_id.jpg";
	}

	$sizes = apply_filters( 'intermediate_image_sizes_advanced', registered_sizes(), $meta, $attachment_id );
	$GLOBALS['SEEN'][] = array( $attachment_id, is_array( $sizes ) ? array_keys( $sizes ) : $sizes );
	if ( ! is_array( $sizes ) ) {
		// _wp_make_subsizes(): nothing to make for an empty value; anything else
		// goes to array_merge(), a TypeError on PHP 8.
		if ( empty( $sizes ) ) {
			return $meta;
		}
		throw new TypeError( 'array_merge(): Argument #2 must be of type array' );
	}

	foreach ( $sizes as $name => $size ) {
		// The sizes come from the original, as WordPress makes them.
		$made = size_of( $w, $h, $size );
		if ( $made ) {
			$meta['sizes'][ $name ] = array( 'width' => $made[0], 'height' => $made[1] );
		}
	}
	return $meta;
}

/** A photo the shop uploads itself, in a request with no download running. */
function shop_upload( $w, $h ) {
	$id = wp_insert_attachment( array() );
	$GLOBALS['META'][ $id ]['_wp_attachment_metadata'] = make_sizes( $id, $w, $h );
	return $id;
}

/**
 * download_url(), with eBay's photo server played by $PHOTO. Since 3.27.4 the
 * localizer fetches with this and media_handle_sideload(), not
 * media_sideload_image(), so that it can name the file and mark the photo.
 */
function download_url( $url, $timeout = 300 ) {
	$photo = $GLOBALS['PHOTO'];
	if ( is_wp_error( $photo ) ) {
		return $photo;
	}
	return sys_get_temp_dir() . '/tcg-import-sizes-no-such-download.tmp';
}

/** media_handle_sideload(): the record, its marks, then its sizes - where a throw comes from. */
function media_handle_sideload( $file, $post_id, $desc = null, $post_data = array() ) {
	$photo = $GLOBALS['PHOTO'];
	$id    = wp_insert_attachment( $post_data, false, $post_id );
	foreach ( $post_data['meta_input'] ?? array() as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	if ( $photo instanceof Throwable ) {
		throw $photo;
	}
	$GLOBALS['META'][ $id ]['_wp_attachment_metadata'] = make_sizes( $id, $photo[0], $photo[1] );
	return $id;
}

// What the localizer looks up to name a photo and find its folder.
function get_post( $id ) { return (object) array( 'ID' => (int) $id, 'post_date' => '2026-10-01 12:00:00' ); }
function get_post_field( $field, $id ) { return 'post_title' === $field ? 'Brake caliper ' . (int) $id : ''; }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_upload_dir( $time = null ) {
	$base = sys_get_temp_dir() . '/tcg-import-sizes-uploads';
	return array( 'path' => $base . '/2026/10', 'subdir' => '/2026/10', 'basedir' => $base, 'error' => false );
}
/** wpdb, as far as naming a photo goes: no name is held by any record. */
class Harness_Sizes_Wpdb {
	public $postmeta = 'wp_postmeta';
	public function prepare( $query, ...$args ) { return $query; }
	public function get_var( $query ) { return null; }
}
$wpdb = new Harness_Sizes_Wpdb();

// --- the real classes --------------------------------------------------------
require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-image-localizer.php';
TCGiant_Sync_Runtime::$clock        = function () { return (float) $_SERVER['REQUEST_TIME_FLOAT']; };
TCGiant_Sync_Runtime::$memory_probe = function () { return 0; };

function photo( $n ) { return sprintf( 'https://i.ebayimg.com/images/g/P%03dAAOSwAbC/s-l1600.jpg', $n ); }

function fresh_site() {
	$GLOBALS['META']    = array();
	$GLOBALS['OPTIONS'] = array();
	$GLOBALS['LOG']     = array();
	$GLOBALS['SEEN']    = array();
	$GLOBALS['PHOTO']   = array( 1600, 1200 );
	$GLOBALS['PHOTO_EXT'] = 'jpg';
	foreach ( array( 'intermediate_image_sizes_advanced', 'wp_get_missing_image_subsizes', 'tcgiant_sync_import_image_sizes', 'big_image_size_threshold', 'max_srcset_image_width' ) as $hook ) {
		unset( $GLOBALS['HOOKS'][ $hook ] );
	}
	// The localizer's own, as its constructor registers them on every request.
	add_filter( 'intermediate_image_sizes_advanced', $GLOBALS['GUARD'], 99, 3 );
	add_filter( 'wp_get_missing_image_subsizes', $GLOBALS['GUARD'], 99, 3 );
}

function product( $id, array $photos ) {
	$GLOBALS['META'][ $id ] = array();
	TCGiant_Sync_Image_Localizer::set_external_images( $id, $photos );
}

function localize( $id ) {
	$step = new ReflectionMethod( 'TCGiant_Sync_Image_Localizer', 'localize_product_images' );
	$step->setAccessible( true );
	return $step->invoke( TCGiant_Sync_Image_Localizer::instance(), $id );
}

function downloading() {
	$p = new ReflectionProperty( 'TCGiant_Sync_Image_Localizer', 'downloading' );
	$p->setAccessible( true );
	return $p->getValue();
}

/** The sizes the product's main photo was given, by name. */
function main_sizes( $product_id ) {
	$meta = get_post_meta( get_post_thumbnail_id( $product_id ), '_wp_attachment_metadata' );
	return is_array( $meta ) ? array_keys( $meta['sizes'] ) : array();
}
function sizes_of( $attachment_id ) {
	return array_keys( $GLOBALS['META'][ $attachment_id ]['_wp_attachment_metadata']['sizes'] ?? array() );
}

/**
 * The widths WordPress offers the browser in srcset for a photo shown at one
 * of its uncropped sizes - wp_calculate_image_srcset(): every size of the same
 * shape, and the photo itself, leaving out anything wider than 2,048 px
 * (max_srcset_image_width) unless it is the one shown.
 */
function srcset_widths( array $meta, $shown_width ) {
	$out = array();
	$all = $meta['sizes'];
	$all['full'] = array( 'width' => $meta['width'], 'height' => $meta['height'] );
	foreach ( $all as $size ) {
		$same_shape = abs( $size['width'] / $size['height'] - $meta['width'] / $meta['height'] ) < 0.01;
		if ( ! $same_shape ) {
			continue;
		}
		if ( $size['width'] > 2048 && $size['width'] !== $shown_width ) {
			continue;
		}
		$out[] = $size['width'];
	}
	sort( $out );
	return array_values( array_unique( $out ) );
}

$EVERY  = array( 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', 'woocommerce_thumbnail', 'woocommerce_single', 'woocommerce_gallery_thumbnail' );
$KEPT   = array( 'thumbnail', 'medium', 'medium_large', 'large', 'woocommerce_thumbnail', 'woocommerce_single', 'woocommerce_gallery_thumbnail' );

echo "\nWHAT 3.27.3 MADE OF A 1,600 PX EBAY PHOTO\n" . str_repeat( '=', 122 ) . "\n";

fresh_site();
$before = shop_upload( 1600, 1200 ); // no filter of ours in place: what every download got
check( 'eight sizes, the 1,536 px near-copy among them', sizes_of( $before ), $EVERY );
check( '  1,536 x 1,152: the photo 4% smaller', $META[ $before ]['_wp_attachment_metadata']['sizes']['1536x1536'], array( 'width' => 1536, 'height' => 1152 ) );
check( '  no 2048x2048: WordPress never enlarges, and eBay stops at 1,600', in_array( '2048x2048', sizes_of( $before ), true ), false );
$before_meta = $META[ $before ]['_wp_attachment_metadata'];

echo "\nA PHOTO THE IMAGE BATCH DOWNLOADS\n" . str_repeat( '=', 122 ) . "\n";

fresh_site();
product( 101, array( photo( 1 ), photo( 2 ) ) );
$r = localize( 101 );
$gallery = array_values( array_filter( explode( ',', (string) get_post_meta( 101, '_product_image_gallery' ) ) ) );

check( 'both photos downloaded', $r['downloaded'], 2 );
check( 'the main photo: every size but the 1,536 px copy', main_sizes( 101 ), $KEPT );
check( '  the gallery photo too', isset( $gallery[0] ) ? sizes_of( (int) $gallery[0] ) : null, $KEPT );
check( '  WordPress was asked for neither large size', in_array( '1536x1536', $SEEN[0][1], true ) || in_array( '2048x2048', $SEEN[0][1], true ), false );
check( '  WooCommerce\'s three sizes all made', array_values( array_intersect( main_sizes( 101 ), array( 'woocommerce_thumbnail', 'woocommerce_single', 'woocommerce_gallery_thumbnail' ) ) ), array( 'woocommerce_thumbnail', 'woocommerce_single', 'woocommerce_gallery_thumbnail' ) );

// What a shopper is offered is the same: the photo itself stands where its
// 1,536 px copy stood.
$main_meta = get_post_meta( get_post_thumbnail_id( 101 ), '_wp_attachment_metadata' );
check( 'srcset before: up to the 1,536 copy and the photo', srcset_widths( $before_meta, 600 ), array( 300, 600, 768, 1024, 1536, 1600 ) );
check( '  now: the photo itself is still the largest on offer', srcset_widths( $main_meta, 600 ), array( 300, 600, 768, 1024, 1600 ) );

check( 'smaller photos are as they were', array( 'lost' => array_values( array_diff( sizes_of( shop_upload( 1200, 900 ) ), $KEPT ) ) ), array( 'lost' => array() ) );

echo "\nTHE DOWNLOAD'S OWN HOOK, ONLY WHILE IT DOWNLOADS\n" . str_repeat( '=', 122 ) . "\n";

check( 'after the download, its own hook is gone; the lasting guard stays', array( hooked( 'intermediate_image_sizes_advanced', $DOWNLOAD_HOOK ), hooked( 'intermediate_image_sizes_advanced', $GUARD ) ), array( 0, 1 ) );
$own = shop_upload( 1600, 1200 );
check( '  so a photo the shop uploads gets every size, as before', sizes_of( $own ), $EVERY );

fresh_site();
product( 111, array( photo( 11 ) ) );
$PHOTO = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received' );
localize( 111 );
check( 'a download that fails: its hook gone too', hooked( 'intermediate_image_sizes_advanced', $DOWNLOAD_HOOK ), 0 );

fresh_site();
product( 112, array( photo( 12 ) ) );
// What a host without WebP support in GD throws on an eBay .webp photo.
$PHOTO   = new Error( 'Call to undefined function imagecreatefromwebp()' );
$escaped = '';
try {
	localize( 112 );
} catch ( \Throwable $e ) {
	$escaped = $e->getMessage();
}
check( 'a download that throws: the throw still reaches the batch', $escaped, 'Call to undefined function imagecreatefromwebp()' );
check( '  and its hook is not left on', hooked( 'intermediate_image_sizes_advanced', $DOWNLOAD_HOOK ), 0 );
check( '  nor the photo it was downloading', downloading(), '' );
check( '  the next upload gets every size', sizes_of( shop_upload( 1600, 1200 ) ), $EVERY );

echo "\nA PHOTO LARGER THAN ANY EBAY SERVES\n" . str_repeat( '=', 122 ) . "\n";

// A listing photo kept on the seller's own server can be any size. Past 2,048
// px WordPress leaves the photo itself out of srcset, so its two large copies
// are what a large screen is given; without them it would get 1,024 px.
foreach ( array(
	'2,048 px: no larger than srcset allows - skipped'        => array( array( 2048, 1536 ), false ),
	'2,049 px: larger - kept'                                 => array( array( 2049, 1537 ), true ),
	'4,000 px, scaled by WordPress to 2,560 - kept'           => array( array( 4000, 3000 ), true ),
) as $label => $case ) {
	fresh_site();
	product( 121, array( 'https://parts.example.com/photos/121-front.jpg' ) );
	$PHOTO = $case[0];
	localize( 121 );
	check( $label, in_array( '1536x1536', main_sizes( 121 ), true ), $case[1] );
}
check( '  the 4,000 px photo keeps its 2,048 px copy too', in_array( '2048x2048', main_sizes( 121 ), true ), true );
$big = get_post_meta( get_post_thumbnail_id( 121 ), '_wp_attachment_metadata' );
check( '  so a large screen is offered 2,048 px, not 1,024', max( srcset_widths( $big, 600 ) ), 2048 );
check( 'the big-image limit is left to WordPress: 2,560 px', array( $big['width'], $big['height'] ), array( 2560, 1920 ) );

echo "\nWHAT A SHOP CAN CHANGE\n" . str_repeat( '=', 122 ) . "\n";

fresh_site();
$given = null;
add_filter( 'tcgiant_sync_import_image_sizes', function ( $sizes, $all, $attachment_id ) use ( &$given ) {
	$given = array( array_keys( $sizes ), array_keys( $all ), $attachment_id );
	return $all;
}, 10, 3 );
product( 131, array( photo( 31 ) ) );
localize( 131 );
check( 'the filter is given the list without the two', $given[0] ?? null, array( 'thumbnail', 'medium', 'medium_large', 'large', 'woocommerce_thumbnail', 'woocommerce_single', 'woocommerce_gallery_thumbnail' ) );
check( '  and every size WordPress would have made', $given[1] ?? null, array_keys( registered_sizes() ) );
check( '  and the photo it is for', $given[2] ?? null, get_post_thumbnail_id( 131 ) );
check( '  returning them all: the 1,536 px copy is made again', main_sizes( 131 ), $EVERY );

fresh_site();
add_filter( 'tcgiant_sync_import_image_sizes', function ( $sizes ) { unset( $sizes['medium_large'] ); return $sizes; } );
product( 132, array( photo( 32 ) ) );
localize( 132 );
check( 'a shop can skip more', main_sizes( 132 ), array( 'thumbnail', 'medium', 'large', 'woocommerce_thumbnail', 'woocommerce_single', 'woocommerce_gallery_thumbnail' ) );

fresh_site();
add_filter( 'tcgiant_sync_import_image_sizes', '__return_false_stand_in' );
function __return_false_stand_in() { return false; }
product( 133, array( photo( 33 ) ) );
try {
	$r = localize( 133 );
} catch ( \Throwable $e ) {
	$r = array( 'downloaded' => get_class( $e ) );
}
check( 'a filter that returns no list is ignored: the photo still arrives', array( $r['downloaded'], main_sizes( 133 ) ), array( 1, $KEPT ) );

fresh_site();
add_filter( 'tcgiant_sync_import_image_sizes', function () { return 'x'; } );
product( 135, array( photo( 35 ) ) );
try {
	$r = localize( 135 );
} catch ( \Throwable $e ) {
	$r = array( 'downloaded' => get_class( $e ) );
}
check( 'nor one that returns something else, which would stop WordPress part way', array( $r['downloaded'], main_sizes( 135 ) ), array( 1, $KEPT ) );

echo "\nWHERE THE PHOTO ITSELF CANNOT STAND IN\n" . str_repeat( '=', 122 ) . "\n";

// srcset never offers a GIF whole, so its copies are what a large screen gets.
fresh_site();
$PHOTO_EXT = 'gif';
product( 141, array( 'https://parts.example.com/photos/141-front.gif' ) );
localize( 141 );
check( 'a GIF keeps its 1,536 px copy', in_array( '1536x1536', main_sizes( 141 ), true ), true );

// A theme that caps srcset below the photo's width leaves the photo itself out.
fresh_site();
add_filter( 'max_srcset_image_width', function () { return 1536; } );
product( 142, array( photo( 42 ) ) );
localize( 142 );
check( 'a theme that caps srcset at 1,536 px: the copy is kept', in_array( '1536x1536', main_sizes( 142 ), true ), true );
fresh_site();
add_filter( 'max_srcset_image_width', function () { return 1600; } );
product( 143, array( photo( 43 ) ) );
localize( 143 );
check( '  one that caps it at the photo\'s width: skipped', in_array( '1536x1536', main_sizes( 143 ), true ), false );

// Another plugin's own sizes, added through the same WordPress filter - at 20
// here, later than most: ours runs after it, so they are kept, and a plugin
// that puts the 1,536 px size back is overruled.
fresh_site();
add_filter( 'intermediate_image_sizes_advanced', function ( $sizes ) {
	$sizes['theme-hero']  = array( 'width' => 1400, 'height' => 0, 'crop' => false );
	$sizes['1536x1536']   = array( 'width' => 1536, 'height' => 1536, 'crop' => false );
	return $sizes;
}, 20 );
product( 134, array( photo( 34 ) ) );
localize( 134 );
check( 'another plugin\'s size is still made', in_array( 'theme-hero', main_sizes( 134 ), true ), true );
check( '  one that puts the 1,536 px size back is overruled', in_array( '1536x1536', main_sizes( 134 ), true ), false );
check( '  and its filter stays where it was, beside the lasting guard', array( filters_on( 'intermediate_image_sizes_advanced' ), hooked( 'intermediate_image_sizes_advanced', $GUARD ) ), array( 2, 1 ) );

echo "\nA TOOL THAT REGENERATES THUMBNAILS LATER (3.27.7)\n" . str_repeat( '=', 122 ) . "\n";

/**
 * wp_get_missing_image_subsizes(): the registered sizes smaller than the
 * photo that its record does not have, through the filter, with its record
 * and ID - what wp_update_image_subsizes() and WP-CLI's --only-missing make.
 */
function missing_sizes( $attachment_id ) {
	$meta     = $GLOBALS['META'][ $attachment_id ]['_wp_attachment_metadata'];
	$possible = array();
	foreach ( registered_sizes() as $name => $size ) {
		if ( size_of( $meta['width'], $meta['height'], $size ) ) {
			$possible[ $name ] = $size;
		}
	}
	$missing = array_diff_key( $possible, $meta['sizes'] );
	return array_keys( apply_filters( 'wp_get_missing_image_subsizes', $missing, $meta, $attachment_id ) );
}

/** A photo downloaded before 3.27.4: the plugin's mark, and every size. */
function old_download( $w, $h ) {
	$id = shop_upload( $w, $h );
	update_post_meta( $id, '_tcgiant_source_url', photo( $id ) );
	return $id;
}

fresh_site();
$old = old_download( 1600, 1200 );
check( 'a photo downloaded before 3.27.4 has the copy', in_array( '1536x1536', sizes_of( $old ), true ), true );
// Regenerate Thumbnails, or "wp media regenerate": every size made again
// from the file, through intermediate_image_sizes_advanced.
$GLOBALS['META'][ $old ]['_wp_attachment_metadata'] = make_sizes( $old, 1600, 1200 );
check( '  regenerated: every size but the copy', sizes_of( $old ), $KEPT );
// --only-missing, or an optimiser filling in what is missing.
check( '  and nothing missing brings it back', missing_sizes( $old ), array() );

fresh_site();
$theirs = shop_upload( 1600, 1200 );
$GLOBALS['META'][ $theirs ]['_wp_attachment_metadata'] = make_sizes( $theirs, 1600, 1200 );
check( 'the shop\'s own upload, regenerated: every size, as WordPress makes them', sizes_of( $theirs ), $EVERY );
unset( $GLOBALS['META'][ $theirs ]['_wp_attachment_metadata']['sizes']['1536x1536'] );
check( '  and its missing copy is made again', missing_sizes( $theirs ), array( '1536x1536' ) );

fresh_site();
$trimmed = old_download( 1600, 1200 );
unset( $GLOBALS['META'][ $trimmed ]['_wp_attachment_metadata']['sizes']['1536x1536'] );
check( 'a downloaded photo whose copy Image Cleanup took off: not made again', missing_sizes( $trimmed ), array() );

foreach ( array(
	'a downloaded photo over 2,048 px keeps both when regenerated' => array( array( 3000, 2000 ), 'jpg', array( '1536x1536', '2048x2048' ) ),
	'a downloaded GIF keeps its copy when regenerated'             => array( array( 1600, 1200 ), 'gif', array( '1536x1536' ) ),
) as $label => $case ) {
	fresh_site();
	$PHOTO_EXT = $case[1];
	$big       = old_download( $case[0][0], $case[0][1] );
	$GLOBALS['META'][ $big ]['_wp_attachment_metadata'] = make_sizes( $big, $case[0][0], $case[0][1] );
	check( $label, array_values( array_intersect( sizes_of( $big ), array( '1536x1536', '2048x2048' ) ) ), $case[2] );
}
$PHOTO_EXT = 'jpg';

// A GIF saved under a .jpg name: its type, not its name, says what it is - as
// the removal of the old copies judges it.
fresh_site();
$gj = old_download( 1600, 1200 );
$GLOBALS['META'][ $gj ]['_mime'] = 'image/gif';
$GLOBALS['META'][ $gj ]['_wp_attachment_metadata'] = make_sizes( $gj, 1600, 1200 );
check( 'a downloaded GIF named .jpg keeps its copy when regenerated, as the removal keeps it', in_array( '1536x1536', sizes_of( $gj ), true ), true );

// The shop's filter is the way back, for a regenerate too.
fresh_site();
$calls = 0;
add_filter( 'tcgiant_sync_import_image_sizes', function ( $sizes, $all ) use ( &$calls ) {
	$calls++;
	return $all;
}, 10, 3 );
$back = old_download( 1600, 1200 );
$calls = 0;
$GLOBALS['META'][ $back ]['_wp_attachment_metadata'] = make_sizes( $back, 1600, 1200 );
check( 'tcgiant_sync_import_image_sizes returning $all: a regenerate makes the copy again', in_array( '1536x1536', sizes_of( $back ), true ), true );
check( '  asked once', $calls, 1 );

// Once, not twice, while the download's own hook is on as well.
$calls = 0;
product( 151, array( photo( 51 ) ) );
localize( 151 );
check( 'during a download, the shop\'s filter is asked once a photo', $calls, 1 );

fresh_site();
$guard_alone = TCGiant_Sync_Image_Localizer::guard_large_sizes( registered_sizes(), array( 'width' => 1600, 'height' => 1200, 'file' => '2026/10/x.jpg' ), 0 );
check( 'no photo named (WordPress before 5.3): left as it is', array_keys( $guard_alone ), array_keys( registered_sizes() ) );
check( 'anything but a list is handed back untouched', TCGiant_Sync_Image_Localizer::guard_large_sizes( 'x', array(), $old ), 'x' );

echo "\nIN THE SOURCE\n" . str_repeat( '=', 122 ) . "\n";

$source = '';
foreach ( glob( $ROOT . '/includes/*.php' ) as $f ) {
	$source .= file_get_contents( $f );
}
$source .= implode( '', array_map( 'file_get_contents', glob( $ROOT . '/admin/*.php' ) ) );
check( 'the plugin never sets the big-image limit', preg_match( "/add_filter\(\s*'big_image_size_threshold'/", $source ), 0 );
check( 'intermediate_image_sizes_advanced: hooked for a download and for the lasting guard; the download\'s unhooked', array(
	preg_match_all( "/add_filter\(\s*'intermediate_image_sizes_advanced'/", $source ),
	preg_match_all( "/add_filter\(\s*'intermediate_image_sizes_advanced', array\( __CLASS__, 'guard_large_sizes' \), 99, 3 \)/", $source ),
	preg_match_all( "/remove_filter\(\s*'intermediate_image_sizes_advanced'/", $source ),
), array( 2, 1, 1 ) );
$localizer = file_get_contents( $ROOT . '/includes/class-tcgiant-sync-image-localizer.php' );
check( '  the unhooking is in a finally', 1 === preg_match( "/finally\s*\{\s*remove_filter\(\s*'intermediate_image_sizes_advanced'/", $localizer ), true );
check( '  the guard is on both filters from the constructor, on every request', 1 === preg_match( "/function __construct\(\) \{.*add_filter\( 'intermediate_image_sizes_advanced', array\( __CLASS__, 'guard_large_sizes' \), 99, 3 \);\s*add_filter\( 'wp_get_missing_image_subsizes', array\( __CLASS__, 'guard_large_sizes' \), 99, 3 \);.*public static function maybe_rearm/s", $localizer ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
