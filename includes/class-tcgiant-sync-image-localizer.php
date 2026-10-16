<?php
/**
 * Background Image Localizer
 *
 * Downloads eBay-hosted images to the local WordPress media library in the background.
 * During sync, products get eBay image URLs immediately (instant visual results).
 * This class then runs in the background to replace them with local copies.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Image_Localizer class
 */
class TCGiant_Sync_Image_Localizer {

	/**
	 * Number of products to process per WP-Cron tick.
	 * Each product can have 1-12 images. Higher batch sizes reduce
	 * total localization time for large stores (10k+ items).
	 */
	const PRODUCTS_PER_BATCH = 50;

	/**
	 * How many times a product may fail localization before we stop retrying.
	 *
	 * Without a cap, a permanently unreachable host would keep the product in
	 * the pending query forever and the batch would never drain.
	 */
	const MAX_LOCALIZE_ATTEMPTS = 3;

	/**
	 * Base retry backoff in seconds, multiplied by the attempt number.
	 */
	const RETRY_BACKOFF_SECONDS = 600;

	/**
	 * WP-Cron hook name.
	 */
	const CRON_HOOK = 'tcgiant_sync_localize_images';

	/**
	 * How long a product is left alone after a batch starts on it.
	 *
	 * An attempt is charged BEFORE the work, with this as a lease, so that a
	 * product whose batch is killed part way through is not first in line
	 * again the moment the next batch starts. It used to be: the failure was
	 * only ever counted by code that a killed batch never reached, so one
	 * product could head every batch for ever and the queue never moved.
	 */
	const LEASE_SECONDS = 900;

	/**
	 * When the next batch runs if this one is killed.
	 *
	 * Inside the lease, so the products behind the one it died on get their
	 * turn while that one waits. It ran at the end of the lease, which put
	 * the same product first in every batch, a quarter of an hour apart,
	 * while the rest of the queue waited behind it.
	 */
	const FALLBACK_SECONDS = 300;

	/**
	 * How soon a product that stopped part way through comes round again.
	 */
	const PAUSE_SECONDS = 60;

	/**
	 * Longest one eBay photo download may take, and its largest size.
	 *
	 * media_sideload_image(), which fetched photos until 3.27.4, passed a
	 * 300 s timeout of its own, which is why the http_request_timeout filter
	 * used before 3.27.0 never had any effect. Photos are now fetched with
	 * download_url() and this limit; the http_request_args cap set up in
	 * run_batch() stays, for the size and for anything else fetched then.
	 */
	const DOWNLOAD_TIMEOUT = 30;
	const DOWNLOAD_MAX_BYTES = 15728640;

	/**
	 * Most characters of the product's name a photo's file name carries.
	 *
	 * Enough to tell a caliper from a headlamp in a folder listing, short
	 * enough that the photo's address - kept by WordPress in a column 255
	 * characters wide - has room for the site's own address and the year and
	 * month folders in front of it.
	 */
	const FILE_LABEL_LENGTH = 40;

	/**
	 * Marks a photo whose record exists but whose smaller sizes may not.
	 *
	 * Making the smaller copies is the slowest part of a download, so it is
	 * where a host ending the request usually lands. A photo still carrying
	 * this mark is finished, not fetched again, the next time it is needed.
	 */
	const UNFINISHED_META = '_tcgiant_download_unfinished';

	/**
	 * What a photo without our mark must also be to count as the shop's own.
	 *
	 * See attachments_by_origin(). Added by someone signed in, with a file,
	 * and not named the way eBay's photos used to be saved. SQL, with the
	 * attachment as "a" and its _wp_attached_file row as "att_file".
	 */
	const SHOPS_OWN_SQL = 'a.post_author <> 0 AND att_file.post_id IS NOT NULL AND att_file.meta_value NOT REGEXP \'(^|/)(s-l[0-9]+|[0-9]+)(-[0-9]+)*[.](jpe?g|jpe|png|gif|webp)$\'';

	/**
	 * The photo being downloaded now, so its download is held to the same
	 * time limit wherever it is hosted.
	 *
	 * @var string
	 */
	private static $downloading = '';

	/**
	 * What the batch is working on, for the line a killed request leaves.
	 *
	 * @var string
	 */
	private $context = '';

	/**
	 * Image widths eBay will serve, offered as import options.
	 *
	 * eBay hosts every size behind the same URL — the "s-l{width}" segment
	 * selects which one is returned. Asking for a smaller variant saves the
	 * bytes before they are ever transferred, which cuts download time and
	 * disk use together, and shrinks every derivative WordPress generates from
	 * it as well.
	 */
	const EBAY_IMAGE_WIDTHS = array( 1600, 1200, 800, 600 );

	/**
	 * Rewrite an eBay image URL to the configured maximum width.
	 *
	 * Only the URL used for downloading is changed. The original address is
	 * still what gets recorded for deduplication, so switching this setting
	 * never orphans images that were already downloaded.
	 *
	 * @param string $url Original eBay image URL.
	 * @return string URL to download, unchanged if it is not a recognised eBay image.
	 */
	public static function apply_image_size_preference( $url ) {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$width    = isset( $settings['import_image_width'] ) ? (int) $settings['import_image_width'] : 0;

		// 0 or an unrecognised value means "leave eBay's own URL alone".
		if ( ! in_array( $width, self::EBAY_IMAGE_WIDTHS, true ) ) {
			return $url;
		}

		// eBay image URLs look like .../s-l1600.jpg, sometimes with a query
		// string appended. Anything that does not match is left untouched.
		$pattern = '#/s-l\d+(\.(?:jpe?g|png|webp|gif))(\?.*)?$#i';

		if ( ! preg_match( $pattern, $url ) ) {
			return $url;
		}

		$rewritten = preg_replace( $pattern, '/s-l' . $width . '$1$2', $url );

		return $rewritten ? $rewritten : $url;
	}

	/**
	 * WordPress sizes not made for a photo downloaded from eBay.
	 *
	 * WordPress 5.3 added both for very large uploads, and makes every size
	 * smaller than the photo it is given. eBay serves its photos at 1,600 px
	 * at most, so 2048x2048 is never made from one, and 1536x1536 is the same
	 * photo 4% smaller: about a quarter of the disk each downloaded photo
	 * takes with all its sizes, and the slowest of its resizes, done while
	 * the host's clock runs. Nothing a shopper sees needs it. WordPress lists
	 * the photo itself in srcset where it would have listed this copy, and a
	 * theme that asks for the size by name is given the photo itself.
	 * WooCommerce never asks for either: it uses its own three sizes, and the
	 * photo itself for zoom.
	 *
	 * The shop's own uploads still get both.
	 */
	const SKIPPED_IMPORT_SIZES = array( '1536x1536', '2048x2048' );

	/**
	 * The sizes WordPress makes for a photo the localizer downloads.
	 *
	 * Hooked to intermediate_image_sizes_advanced for the length of one
	 * download only (see localize_product_images()), so it never sees an image
	 * the shop uploads, or one another plugin works on later in the request.
	 *
	 * The two are skipped only where the photo itself takes their place in
	 * srcset. A photo larger than 2,048 px keeps both: WordPress leaves anything
	 * wider than that out of srcset, so without them a large screen would be
	 * given the 1,024 px copy. No eBay photo is that large; one kept on a
	 * seller's own server can be. So does a GIF, which srcset never offers
	 * whole, and any photo wider than a theme's own srcset limit
	 * (max_srcset_image_width) where a theme has lowered it.
	 *
	 * WordPress's "big image" limit (big_image_size_threshold, 2,560 px) is
	 * left as it is. It never applies to an eBay photo. For a larger one,
	 * lowering it would add a scaled copy and still keep the original, and
	 * raising it would hand the whole original to every shopper who zooms.
	 *
	 * @param array $sizes         Sizes WordPress is about to make, keyed by name: width, height, crop.
	 * @param array $image_meta    The photo's metadata so far: its width and height as WordPress will serve it.
	 * @param int   $attachment_id The photo's attachment ID.
	 * @return array Sizes to make.
	 */
	public static function import_image_sizes( $sizes, $image_meta = array(), $attachment_id = 0 ) {
		if ( ! is_array( $sizes ) ) {
			return $sizes;
		}

		$all     = $sizes;
		$width   = (int) ( $image_meta['width'] ?? 0 );
		$height  = (int) ( $image_meta['height'] ?? 0 );
		$is_gif  = 'gif' === strtolower( pathinfo( (string) ( $image_meta['file'] ?? '' ), PATHINFO_EXTENSION ) );
		$in_set  = (int) apply_filters( 'max_srcset_image_width', 2048, array( $width, $height ) );

		if ( ! $is_gif && max( $width, $height ) <= 2048 && ( $in_set <= 0 || $width <= $in_set ) ) {
			foreach ( self::SKIPPED_IMPORT_SIZES as $name ) {
				unset( $sizes[ $name ] );
			}
		}

		/**
		 * Image sizes made for a photo downloaded from eBay.
		 *
		 * Applies only while TCGiant Sync is downloading a listing's photo,
		 * never to images the shop uploads. Return $all to have every size
		 * made, as for the shop's own uploads.
		 *
		 * @since 3.27.4
		 *
		 * @param array $sizes         Sizes to make, keyed by name: WordPress's list without 1536x1536 and 2048x2048.
		 * @param array $all           Every size WordPress would make for one of the shop's own uploads.
		 * @param int   $attachment_id The downloaded photo's attachment ID.
		 */
		$filtered = apply_filters( 'tcgiant_sync_import_image_sizes', $sizes, $all, (int) $attachment_id );

		// Anything but a list is ignored. false or an empty value would leave
		// the photo with no sizes at all; anything else stops WordPress part
		// way through the photo on PHP 8.
		return is_array( $filtered ) ? $filtered : $sizes;
	}

	/**
	 * The file name a photo downloaded for a product is saved under.
	 *
	 * eBay's photo addresses all end in the same few names - s-l1600.jpg, or
	 * $_57.JPG, which WordPress saves as 57.jpg - so every photo was saved as
	 * s-l1600.jpg, and WordPress told them apart as s-l1600-1.jpg,
	 * s-l1600-2.jpg and so on by looking at the files already in the folder.
	 * A media offloader moves those files to its own storage and deletes them
	 * here, and then there is nothing in the folder to see: the next photo was
	 * given a name an earlier one still had in the offloader's storage, and
	 * its copies were sent over the earlier product's. The shop then showed
	 * one product's photo on another.
	 *
	 * The name is now made from what the photo is - the product it was
	 * fetched for, eBay's own number for the picture and the width asked for:
	 *
	 *     honda-civic-front-brake-caliper-4521-ebay-kp4aaosw1zl3x9a-1600.jpg
	 *
	 * so two different photos do not start out with the same name, whatever is
	 * or is not in the folder. A photo kept anywhere but eBay's servers, or
	 * whose address does not carry eBay's number, is named from a short
	 * fingerprint of its address instead. The same photo for the same product
	 * is always given the same name.
	 *
	 * Only a-z, 0-9 and hyphens, so nothing WordPress or a storage service does
	 * to a name changes it, and it never ends in something WordPress takes for
	 * one of its own resized copies (-300x300, -scaled, -rotated).
	 *
	 * @param int    $product_id   Product (or variation) the photo is for.
	 * @param string $download_url Address being fetched, after the size setting.
	 * @param string $clean_url    The original address, without its query string.
	 * @return string|WP_Error File name with its extension, or why it is not a photo.
	 */
	public static function photo_file_name( $product_id, $download_url, $clean_url ) {
		// The test media_sideload_image() made: an address that does not end in
		// a photo's file type is not one WordPress will save.
		$types = apply_filters( 'image_sideload_extensions', array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp' ), $download_url );
		$types = array_map( 'preg_quote', (array) $types );

		if ( empty( $types ) || ! preg_match( '/[^\?]+\.(' . implode( '|', $types ) . ')\b/i', $download_url, $type ) ) {
			return new WP_Error( 'tcgiant_not_a_photo', 'Invalid image URL: the address does not end in a photo file type WordPress accepts.' );
		}

		// eBay's number for the picture: /images/g/<number>/s-l1600.jpg, or
		// /z/<number>/$_57.JPG in the older form the Trading API still sends.
		$key = '';
		if ( self::is_ebay_photo_host( $clean_url ) && preg_match( '#/(?:g|z)/([A-Za-z0-9~_-]{6,40})/#', $clean_url, $number ) ) {
			$number = substr( strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $number[1] ) ), 0, 24 );
			if ( strlen( $number ) >= 6 ) {
				$key = 'ebay-' . $number;
				if ( preg_match( '#/s-l(\d{2,4})\.[a-z]+(?:\?.*)?$#i', $download_url, $width ) ) {
					$key .= '-' . $width[1];
				}
			}
		}
		if ( '' === $key ) {
			$key = 'photo-' . substr( md5( $clean_url ), 0, 10 );
		}

		$base = self::file_label( $product_id ) . '-' . (int) $product_id . '-' . $key;

		// WordPress always numbers a name that looks like one of its resized
		// copies, and a number added there is one this plugin did not check.
		if ( preg_match( '/-(?:\d+x\d+|scaled|rotated)$/', $base ) ) {
			$base .= '-photo';
		}

		return $base . '.' . strtolower( $type[1] );
	}

	/**
	 * The start of a photo's file name: the product's name, made safe.
	 *
	 * From the title rather than the product's address, which is empty for a
	 * draft and encoded beyond reading for a name in another alphabet.
	 *
	 * @param int $product_id Product (or variation).
	 * @return string a-z, 0-9 and hyphens; "product" if nothing of the title is left.
	 */
	private static function file_label( $product_id ) {
		$title = (string) get_post_field( 'post_title', $product_id );
		if ( function_exists( 'remove_accents' ) ) {
			$title = remove_accents( $title );
		}

		$label = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' );

		if ( strlen( $label ) > self::FILE_LABEL_LENGTH ) {
			// Stop at the end of a word where there is one: the last hyphen
			// within the limit, counting one that falls just after it.
			$cut = strrpos( substr( $label, 0, self::FILE_LABEL_LENGTH + 1 ), '-' );
			$label = rtrim( substr( $label, 0, ( false !== $cut && $cut >= 10 ) ? $cut : self::FILE_LABEL_LENGTH ), '-' );
		}

		return '' !== $label ? $label : 'product';
	}

	/**
	 * Whether an address is on one of eBay's photo servers.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	private static function is_ebay_photo_host( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 'ebayimg.com' === $host || '.ebayimg.com' === substr( $host, -12 );
	}

	/**
	 * The first name for a photo that no photo on the site holds.
	 *
	 * WordPress only looks in the folder, which an offloader empties. This
	 * also looks in the Media Library's own record of every photo's file -
	 * which an offloader never empties - so a name another photo still has is
	 * not handed out again, wherever that photo's files now live. It also
	 * steps past a file a stopped request left in the folder with no record,
	 * instead of leaving WordPress to add a number this has not checked.
	 *
	 * The name photo_file_name() made is free almost every time. The one thing
	 * that finds it held is the same eBay picture fetched a second time for
	 * the same product from a different address, and that photo gets a short
	 * fingerprint of its own address added.
	 *
	 * @param int    $product_id Product the photo is attached to.
	 * @param string $name       File name from photo_file_name().
	 * @param string $clean_url  The photo's address, for the fingerprint.
	 * @return string File name to save it under.
	 */
	private static function unclaimed_file_name( $product_id, $name, $clean_url ) {
		global $wpdb;

		// The folder media_handle_sideload() will use: the month the product
		// was created in, as WordPress files every photo attached to a post.
		$post    = get_post( $product_id );
		$time    = ( $post && substr( (string) $post->post_date, 0, 4 ) > 0 ) ? $post->post_date : current_time( 'mysql' );
		$uploads = wp_upload_dir( $time );
		$subdir  = trim( (string) $uploads['subdir'], '/' );
		$folder  = rtrim( (string) $uploads['path'], '/' ) . '/';

		$type  = pathinfo( $name, PATHINFO_EXTENSION );
		$base  = pathinfo( $name, PATHINFO_FILENAME );
		$print = substr( md5( $clean_url ), 0, 8 );

		// WordPress renames a file whose contents are another type of photo
		// than its name says, so a name is free only if it is free as each.
		$kinds = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'heic' );

		for ( $n = 0; $n < 20; $n++ ) {
			$try     = 0 === $n ? $base : $base . '-' . $print . ( $n > 1 ? '-' . $n : '' );
			$paths   = array();
			$on_disk = false;

			// The record of a photo WordPress scaled or rotated names the
			// copy it serves (-scaled, -rotated); the name it was given is
			// still held, by the original kept beside it.
			foreach ( $kinds as $kind ) {
				foreach ( array( '', '-scaled', '-rotated' ) as $suffix ) {
					$paths[] = ( '' !== $subdir ? $subdir . '/' : '' ) . $try . $suffix . '.' . $kind;
					if ( file_exists( $folder . $try . $suffix . '.' . $kind ) ) {
						$on_disk = true;
					}
				}
			}

			// Exact paths, so the plugin's own index on post meta answers it.
			$in = implode( ', ', array_fill( 0, count( $paths ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$recorded = (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ( {$in} ) LIMIT 1",
				$paths
			) );

			if ( ! $on_disk && ! $recorded ) {
				return $try . '.' . $type;
			}

			// A file under this name that no photo in the Media Library holds:
			// left by a request stopped after the download was saved and
			// before its record was. Stepped past, and said, so support can
			// find it.
			if ( $on_disk && ! $recorded ) {
				TCGiant_Sync_Logger::log( sprintf(
					'Image localizer: WC #%d - a file left by a stopped request is in the way (%s); this photo is saved under another name.',
					(int) $product_id,
					( '' !== $subdir ? $subdir . '/' : '' ) . $try . '.' . $type
				) );
			}
		}

		// Twenty held in a row does not happen. A name with the time in it
		// cannot have been given out before.
		return $base . '-' . $print . '-' . time() . '.' . $type;
	}

	/**
	 * Download one photo into the Media Library, marked as ours from the start.
	 *
	 * media_sideload_image() did this until 3.27.4. It names the file after
	 * the address, and nothing can be recorded on the photo until it returns -
	 * and the plugin's mark, the address the photo came from, was written only
	 * then. A request the host ended while WordPress was making the smaller
	 * copies left a photo with no mark: fetched all over again next time, and
	 * taken by Image Cleanup for one the shop had uploaded itself.
	 *
	 * The same two steps it took - download_url(), then
	 * media_handle_sideload() - with the name chosen above, and the mark handed
	 * to WordPress to write with the photo's record. wp_insert_post() writes
	 * meta_input before it records the photo's file and before any other
	 * plugin hears a photo was added, so no photo of ours ever has a file
	 * without the mark. The unfinished mark comes off once every size is made.
	 *
	 * @param int    $product_id   Product (or variation) to attach it to.
	 * @param string $download_url Address to fetch, after the size setting.
	 * @param string $clean_url    The original address: what it is recorded as.
	 * @return int|WP_Error Attachment ID.
	 */
	private static function sideload_photo( $product_id, $download_url, $clean_url ) {
		$name = self::photo_file_name( $product_id, $download_url, $clean_url );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		$name = self::unclaimed_file_name( $product_id, $name, $clean_url );

		$tmp = download_url( $download_url, self::DOWNLOAD_TIMEOUT );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			$product_id,
			null,
			array(
				// The record's own name too. Every photo used to be called
				// "s-l1600", and WordPress found a free name for the next one
				// by trying s-l1600-2, s-l1600-3 and so on, one lookup each,
				// up to however many the shop already had.
				'post_name'  => pathinfo( $name, PATHINFO_FILENAME ),
				// In this order: unfinished, then ours. A request stopped
				// between the two leaves a record with neither a file nor
				// our mark, which nothing here will reuse or delete.
				'meta_input' => array(
					self::UNFINISHED_META => time(),
					'_tcgiant_source_url' => $clean_url,
					'_source_url'         => $download_url, // As media_sideload_image() kept it.
				),
			)
		);

		if ( is_wp_error( $id ) ) {
			// Whatever WordPress did not move into the library.
			if ( is_file( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return $id;
		}

		delete_post_meta( $id, self::UNFINISHED_META );

		return (int) $id;
	}

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * Main instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'process_batch' ) );

		// Make placeholder attachments resolve to their real eBay URL.
		//
		// A placeholder stores the eBay address in _wp_attached_file, but
		// WordPress treats that value as a path relative to the uploads folder
		// and prepends the uploads base URL, producing
		//   https://site.com/wp-content/uploads/https://i.ebayimg.com/...
		// Every placeholder image was therefore broken, which is why the
		// "keep eBay-hosted URLs" mode showed no images at all. These filters
		// return the stored URL instead.
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_image_src' ), 10, 2 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_srcset' ), 10, 5 );
		add_filter( 'wp_get_attachment_metadata', array( __CLASS__, 'filter_metadata' ), 10, 2 );

		add_action( 'admin_init', array( __CLASS__, 'maybe_clean_placeholder_paths' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_rearm' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_backfill_galleries' ) );
	}

	/**
	 * Re-arm the localizer when it has work but nothing scheduled.
	 *
	 * process_batch() bails at the "localization disabled" check before it
	 * reaches the code that schedules the next run, so the single event fires
	 * once, exits, and is never replaced. Turning the setting back on therefore
	 * did nothing at all — there was no event left to run, and "Process Queue"
	 * could not help either because it only runs events that are scheduled.
	 *
	 * This also covers any other way the schedule can be lost: a cleared cron
	 * table, a migration, or a host that wipes scheduled events.
	 *
	 * @return void
	 */
	public static function maybe_rearm() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		if ( ! empty( $settings['disable_image_localization'] ) ) {
			return; // Deliberately off.
		}

		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return; // Already queued.
		}

		if ( get_transient( 'tcgiant_localizer_rearm_check' ) ) {
			return;
		}
		set_transient( 'tcgiant_localizer_rearm_check', 1, 5 * MINUTE_IN_SECONDS );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		//
		// Counted the way the batch selects, so that what it says is waiting is
		// what a batch can actually do. It used to count every flag left at 0,
		// including trashed products and ones waiting out a retry, so it re-armed
		// for work no batch would ever pick up.
		$pending = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_ext ON p.ID = pm_ext.post_id AND pm_ext.meta_key = '_tcgiant_external_image_urls'
			 INNER JOIN {$wpdb->postmeta} pm_loc ON p.ID = pm_loc.post_id AND pm_loc.meta_key = '_tcgiant_images_localized'
			 LEFT JOIN {$wpdb->postmeta} pm_retry ON p.ID = pm_retry.post_id AND pm_retry.meta_key = '_tcgiant_localize_retry_after'
			 WHERE p.post_type IN ('product', 'product_variation')
			   AND p.post_status IN ('publish', 'draft', 'private')
			   AND pm_loc.meta_value = '0'
			   AND ( pm_retry.meta_value IS NULL OR CAST( pm_retry.meta_value AS UNSIGNED ) <= %d )",
			time()
		) );

		if ( $pending < 1 ) {
			return;
		}

		wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		TCGiant_Sync_Cron::request_dispatch();

		TCGiant_Sync_Logger::log( sprintf(
			'Image localization re-armed: %d product(s) waiting and nothing was scheduled.',
			$pending
		) );
	}

	/**
	 * Strip the bogus _wp_attached_file value from existing placeholders.
	 *
	 * Placeholders created before this fix stored a full eBay URL there. The
	 * filters above already correct what is displayed, but leaving a URL in a
	 * field every other plugin reads as a file path invites breakage elsewhere
	 * — media tools, backup plugins and CDN integrations all consult it.
	 *
	 * Runs in small batches on admin requests until nothing is left.
	 *
	 * @return void
	 */
	public static function maybe_clean_placeholder_paths() {
		if ( get_option( 'tcgiant_placeholder_paths_cleaned' ) ) {
			return;
		}

		global $wpdb;

		// Placeholders whose _wp_attached_file still looks like a URL.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			"SELECT pm_file.post_id
			 FROM {$wpdb->postmeta} pm_file
			 INNER JOIN {$wpdb->postmeta} pm_ext
			         ON pm_file.post_id = pm_ext.post_id
			        AND pm_ext.meta_key = '_tcgiant_external_url'
			 WHERE pm_file.meta_key = '_wp_attached_file'
			   AND pm_file.meta_value LIKE 'http%'
			 LIMIT 200"
		);

		if ( empty( $ids ) ) {
			update_option( 'tcgiant_placeholder_paths_cleaned', 1, false );
			return;
		}

		foreach ( $ids as $id ) {
			delete_post_meta( (int) $id, '_wp_attached_file' );
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Cleaned %d placeholder attachment(s) that stored an eBay URL as a file path.',
			count( $ids )
		) );
	}

	/**
	 * Restore the full photo set on products left showing a single image.
	 *
	 * Until now only the first eBay photo was ever represented before an image
	 * was downloaded, so a product waiting in the queue — or one whose queue
	 * had stalled or given up — showed exactly one picture no matter how many
	 * the listing carried. The remaining addresses were stored all along, so
	 * repairing this needs no eBay calls at all: it is purely local.
	 *
	 * Walks every product with stored image URLs once, in ID order, and only
	 * touches those actually showing the fault (two or more photos expected,
	 * at most one present). A merchant who has curated a gallery down by hand
	 * is left alone.
	 *
	 * @return void
	 */
	public static function maybe_backfill_galleries() {
		if ( get_option( 'tcgiant_gallery_backfill_done' ) ) {
			return;
		}

		// admin_init fires on every page load and every admin AJAX request.
		if ( get_transient( 'tcgiant_gallery_backfill_tick' ) ) {
			return;
		}
		set_transient( 'tcgiant_gallery_backfill_tick', 1, MINUTE_IN_SECONDS );

		global $wpdb;

		$cursor = (int) get_option( 'tcgiant_gallery_backfill_cursor', 0 );

		// A cursor rather than a self-draining filter: the repair does not
		// always change what the query matches, so walking by ID is the only
		// way to guarantee this terminates.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$product_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = '_tcgiant_external_image_urls'
			   AND post_id > %d
			 ORDER BY post_id ASC
			 LIMIT 100",
			$cursor
		) );

		if ( empty( $product_ids ) ) {
			update_option( 'tcgiant_gallery_backfill_done', 1, false );
			delete_option( 'tcgiant_gallery_backfill_cursor' );
			TCGiant_Sync_Logger::log( 'Image backfill: finished checking every product.' );
			return;
		}

		$repaired = 0;

		foreach ( $product_ids as $product_id ) {
			$product_id = (int) $product_id;
			$cursor     = max( $cursor, $product_id );

			$entries = get_post_meta( $product_id, '_tcgiant_external_image_urls', true );
			if ( empty( $entries ) || ! is_array( $entries ) ) {
				continue;
			}

			// Count main images only — variation photos live on the variations.
			$expected = array();
			foreach ( $entries as $entry ) {
				if ( is_array( $entry ) ) {
					$var_id = $entry[1] ?? false;
					if ( $var_id && '__retry' !== $var_id ) {
						continue;
					}
					$entry = $entry[0] ?? '';
				}
				if ( ! empty( $entry ) ) {
					$expected[ $entry ] = true;
				}
			}

			if ( count( $expected ) < 2 ) {
				continue;
			}

			$gallery = array_filter( explode( ',', (string) get_post_meta( $product_id, '_product_image_gallery', true ) ) );
			$present = ( has_post_thumbnail( $product_id ) ? 1 : 0 ) + count( $gallery );

			// Deliberately narrow: only the reported symptom, not every
			// product whose gallery is merely shorter than eBay's set.
			if ( $present > 1 ) {
				continue;
			}

			self::set_external_images( $product_id, $entries, true );
			$repaired++;
		}

		update_option( 'tcgiant_gallery_backfill_cursor', $cursor, false );

		if ( $repaired > 0 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Image backfill: restored the full photo set on %d product(s).',
				$repaired
			) );
		}
	}

	/**
	 * Get the external URL stored on a placeholder attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Empty string when this is a normal local attachment.
	 */
	private static function external_url_for( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return '';
		}
		$url = get_post_meta( $attachment_id, '_tcgiant_external_url', true );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Return the eBay URL for placeholder attachments.
	 *
	 * @param string $url           URL WordPress calculated.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public static function filter_attachment_url( $url, $attachment_id ) {
		$external = self::external_url_for( $attachment_id );
		return $external ? $external : $url;
	}

	/**
	 * Return the eBay URL for placeholder attachments in image contexts.
	 *
	 * Covers wp_get_attachment_image(), which is what WooCommerce and the
	 * products list use to render thumbnails.
	 *
	 * @param array|false $image         Array of [url, width, height, is_intermediate].
	 * @param int         $attachment_id Attachment ID.
	 * @return array|false
	 */
	public static function filter_image_src( $image, $attachment_id ) {
		$external = self::external_url_for( $attachment_id );
		if ( ! $external ) {
			return $image;
		}

		// eBay serves whatever size the URL asks for; report the nominal
		// dimensions and flag it as a full-size (non-intermediate) image.
		return array( $external, 800, 800, false );
	}

	/**
	 * Suppress srcset for placeholder attachments.
	 *
	 * There are no generated sizes behind them, so any calculated srcset would
	 * point at files that do not exist.
	 *
	 * @param array $sources       Calculated srcset sources.
	 * @param array $size_array    Requested size.
	 * @param string $image_src    Image source.
	 * @param array $image_meta    Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public static function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		return self::external_url_for( $attachment_id ) ? array() : $sources;
	}

	/**
	 * Keep placeholder metadata from pointing at a non-existent local file.
	 *
	 * @param array $data          Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public static function filter_metadata( $data, $attachment_id ) {
		if ( ! self::external_url_for( $attachment_id ) || ! is_array( $data ) ) {
			return $data;
		}
		// No intermediate sizes exist for a placeholder.
		$data['sizes'] = array();
		return $data;
	}

	/**
	 * Set external eBay image URLs directly on a WooCommerce product.
	 *
	 * This makes the product immediately visible with images hosted on eBay.
	 * The images will be localized (downloaded to WP media library) in the background.
	 *
	 * @param int   $product_id    WooCommerce product ID.
	 * @param array $image_entries  Array of image URLs (strings or [url, variation_id] arrays).
	 * @param bool  $force          Re-queue even when the URLs are unchanged. Used by
	 *                              the images-only tool, whose entire purpose is to
	 *                              re-download images that are wrong or missing.
	 */
	/**
	 * Attachments belonging to a product, split by who put them there.
	 *
	 * Anything we downloaded carries _tcgiant_source_url. Anything we attached
	 * as a stand-in carries _tcgiant_external_url as well, and those are left
	 * alone entirely — they are not files and deleting them fixes nothing.
	 *
	 * So an attachment with a source address and no external address is one we
	 * fetched. That is a matter of record rather than inference, which is the
	 * only basis on which anything here is allowed to delete a photograph.
	 *
	 * The shop's own is the other way round, and asks more than the absence
	 * of our mark, because until 3.27.4 the mark was written only after a
	 * download had finished. A request the host stopped part way left a copy
	 * of ours without it - on a product that also has our finished copies,
	 * which is exactly what this looks for - and restoring that "original"
	 * would delete the product's real photos. So a photo only counts as the
	 * shop's own if it was added by someone signed in (the plugin's downloads
	 * run in the background, as nobody), it has a file, and its file is not
	 * named the way eBay's photos were saved (s-l1600.jpg, 57.jpg, and their
	 * -1, -2 copies). A photo that fails any of those is in neither list: never
	 * put back, never deleted, never shown here. Leaving one out can only mean
	 * a product is not offered for cleanup; it cannot mean a deletion.
	 *
	 * @param int  $product_id
	 * @param bool $ours True for images we downloaded, false for the shop's own.
	 * @return int[] Attachment IDs, oldest first.
	 */
	public static function attachments_by_origin( $product_id, $ours ) {
		global $wpdb;

		$having = $ours ? 'src.post_id IS NOT NULL' : 'src.post_id IS NULL AND ' . self::SHOPS_OWN_SQL;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT a.ID FROM {$wpdb->posts} a
			 LEFT JOIN {$wpdb->postmeta} src ON src.post_id = a.ID AND src.meta_key = '_tcgiant_source_url'
			 LEFT JOIN {$wpdb->postmeta} ext ON ext.post_id = a.ID AND ext.meta_key = '_tcgiant_external_url'
			 LEFT JOIN {$wpdb->postmeta} att_file ON att_file.post_id = a.ID AND att_file.meta_key = '_wp_attached_file'
			 WHERE a.post_type = 'attachment'
			   AND a.post_parent = %d
			   AND ext.post_id IS NULL
			   AND {$having}
			 ORDER BY a.ID ASC",
			(int) $product_id
		) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * SQL: the product has a photo that is neither marked as ours nor sure
	 * to be the shop's own.
	 *
	 * Such a photo is in neither of attachments_by_origin()'s lists. Restoring
	 * the rest would drop it from the product - and delete our copies of the
	 * very photos it stands for - so a product that has one is not offered
	 * for cleanup, and is refused if asked.
	 *
	 * @param string $parent SQL for the product ID.
	 * @return string An EXISTS clause.
	 */
	private static function uncertain_photo_sql( $parent ) {
		global $wpdb;

		return "EXISTS (
			SELECT 1 FROM {$wpdb->posts} a
			LEFT JOIN {$wpdb->postmeta} usrc ON usrc.post_id = a.ID AND usrc.meta_key = '_tcgiant_source_url'
			LEFT JOIN {$wpdb->postmeta} uext ON uext.post_id = a.ID AND uext.meta_key = '_tcgiant_external_url'
			LEFT JOIN {$wpdb->postmeta} uunf ON uunf.post_id = a.ID AND uunf.meta_key = '" . self::UNFINISHED_META . "'
			LEFT JOIN {$wpdb->postmeta} att_file ON att_file.post_id = a.ID AND att_file.meta_key = '_wp_attached_file'
			WHERE a.post_type = 'attachment'
			  AND a.post_parent = {$parent}
			  AND usrc.post_id IS NULL
			  AND uext.post_id IS NULL
			  AND uunf.post_id IS NULL
			  AND NOT ( " . self::SHOPS_OWN_SQL . " )
		)";
	}

	/**
	 * Products holding both their own photographs and copies we downloaded.
	 *
	 * The shape the duplication bug leaves behind. Before 3.9.5, pushing a
	 * product meant eBay served its pictures back and we fetched them as though
	 * they were new, then made our copies the product\'s images — so the shop\'s
	 * originals are still attached but no longer shown anywhere.
	 *
	 * @return int[] Product IDs.
	 */
	public static function find_products_with_duplicate_images() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			"SELECT DISTINCT mine.post_parent
			 FROM {$wpdb->posts} mine
			 INNER JOIN {$wpdb->postmeta} src ON src.post_id = mine.ID AND src.meta_key = '_tcgiant_source_url'
			 LEFT JOIN  {$wpdb->postmeta} ext ON ext.post_id = mine.ID AND ext.meta_key = '_tcgiant_external_url'
			 INNER JOIN {$wpdb->posts} prod ON prod.ID = mine.post_parent
			 WHERE mine.post_type = 'attachment'
			   AND ext.post_id IS NULL
			   AND prod.post_type = 'product'
			   AND prod.post_status NOT IN ( 'trash', 'auto-draft' )
			   AND EXISTS (
				   SELECT 1 FROM {$wpdb->posts} a
				   LEFT JOIN {$wpdb->postmeta} tsrc ON tsrc.post_id = a.ID AND tsrc.meta_key = '_tcgiant_source_url'
				   LEFT JOIN {$wpdb->postmeta} text ON text.post_id = a.ID AND text.meta_key = '_tcgiant_external_url'
				   LEFT JOIN {$wpdb->postmeta} att_file ON att_file.post_id = a.ID AND att_file.meta_key = '_wp_attached_file'
				   WHERE a.post_type = 'attachment'
					 AND a.post_parent = mine.post_parent
					 AND tsrc.post_id IS NULL
					 AND text.post_id IS NULL
					 AND " . self::SHOPS_OWN_SQL . "
			   )
			   AND NOT " . self::uncertain_photo_sql( 'mine.post_parent' ) . "
			 ORDER BY mine.post_parent DESC"
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Put a shop\'s own photographs back, and remove the copies we fetched.
	 *
	 * Order matters. The shop\'s images are restored as the product\'s pictures
	 * BEFORE anything is deleted, so there is no moment at which the product
	 * has none. And every deletion re-checks the record of where that file came
	 * from immediately beforehand, because the list was gathered a moment
	 * earlier and this cannot be undone.
	 *
	 * @param int $product_id
	 * @return int|WP_Error Number of copies removed.
	 */
	public static function restore_own_images( $product_id ) {
		$product_id = (int) $product_id;
		$theirs     = self::attachments_by_origin( $product_id, false );
		$ours       = self::attachments_by_origin( $product_id, true );

		// Never strip a product bare. If there is nothing of the shop\'s own to
		// put back, the pictures we fetched are the only ones it has, and they
		// stay whatever else is true.
		if ( empty( $theirs ) ) {
			return new WP_Error(
				'no_own_images',
				__( 'This product has no images of its own to restore, so nothing was removed.', 'tcgiant-sync' )
			);
		}

		if ( empty( $ours ) ) {
			return 0;
		}

		// A photo the plugin cannot place - neither marked as its own nor
		// sure to be the shop's - would drop out of the product. Not offered
		// for cleanup either; refused here too, because a job's list of
		// products is made when it starts.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		if ( (int) $wpdb->get_var( 'SELECT ' . self::uncertain_photo_sql( (string) $product_id ) ) ) {
			return new WP_Error(
				'uncertain_photos',
				__( 'This product has a photo the plugin cannot tell is yours or one it downloaded, so nothing was changed.', 'tcgiant-sync' )
			);
		}

		// Its photos are being fetched right now: what is attached may be
		// about to become its pictures.
		if ( get_post_meta( $product_id, '_tcgiant_localize_inflight', true )
			|| (int) get_post_meta( $product_id, '_tcgiant_localize_retry_after', true ) > time() ) {
			return new WP_Error(
				'photos_in_progress',
				__( 'This product\'s photos are being downloaded right now, so nothing was changed. Try again in a quarter of an hour.', 'tcgiant-sync' )
			);
		}

		// Restore first.
		set_post_thumbnail( $product_id, $theirs[0] );
		update_post_meta( $product_id, '_product_image_gallery', implode( ',', array_slice( $theirs, 1 ) ) );

		$removed = 0;

		foreach ( $ours as $attachment_id ) {
			// Re-read the record rather than trusting the list.
			if ( ! get_post_meta( $attachment_id, '_tcgiant_source_url', true ) ) {
				continue;
			}
			if ( get_post_meta( $attachment_id, '_tcgiant_external_url', true ) ) {
				continue;
			}
			if ( in_array( $attachment_id, $theirs, true ) ) {
				continue;
			}
			// One download serves every product that needs the same eBay
			// photo, so a copy attached here may be another product's or a
			// variation's picture - in the trash too, where a relisted item
			// takes its photos back from.
			if ( self::used_elsewhere( $attachment_id, $product_id ) ) {
				continue;
			}

			wp_delete_attachment( $attachment_id, true );
			$removed++;
		}

		// Forget that anything was ever imported for this product, which is the
		// truth of it now and is what stops 3.9.5 fetching them all over again.
		delete_post_meta( $product_id, '_tcgiant_external_image_urls' );
		delete_post_meta( $product_id, '_tcgiant_image_urls_hash' );
		update_post_meta( $product_id, '_tcgiant_images_localized', 1 );

		TCGiant_Sync_Logger::log( sprintf(
			'Image cleanup: WC #%d restored to its own %d image(s); %d downloaded copy(ies) removed.',
			$product_id,
			count( $theirs ),
			$removed
		) );

		return $removed;
	}

	/**
	 * Whether anything but this product shows a photo.
	 *
	 * As a main image (_thumbnail_id, which variations use too), or in any of
	 * the gallery lists the removal of deleted products' photos counts -
	 * WooCommerce's, and those of Additional Variation Images and Variation
	 * Images Gallery for WooCommerce, by the same tcgiant_sync_photo_list_keys
	 * filter - whatever that post's status, trashed products included. As the
	 * picture of a category, a brand or any other term. Or through another
	 * Media Library record pointing at the same file, as the image batch made
	 * one for each product reusing a photo before 3.1.4: deleting this one
	 * deletes that one's file with it.
	 *
	 * @param int $attachment_id Photo.
	 * @param int $product_id    Product whose own use of it does not count.
	 * @return bool
	 */
	private static function used_elsewhere( $attachment_id, $product_id ) {
		global $wpdb;

		$id = (string) (int) $attachment_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1",
			(int) $product_id,
			$id
		) ) ) {
			return true;
		}

		/** This filter is documented in includes/class-tcgiant-sync-photo-cleanup.php */
		$keys = (array) apply_filters( 'tcgiant_sync_photo_list_keys', array( '_product_image_gallery', '_wc_additional_variation_images', 'woo_variation_gallery_images' ) );

		// Lists, which no index can search: the rows that mention the number
		// at all, then each list read exactly, so that 95083 is not 5083.
		foreach ( array_unique( array_filter( array_map( 'strval', $keys ) ) ) as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$lists = (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key = %s AND meta_value LIKE %s",
				(int) $product_id,
				$key,
				'%' . $wpdb->esc_like( $id ) . '%'
			) );
			foreach ( $lists as $list ) {
				// Comma-separated, as WooCommerce keeps them, or a serialized
				// array, as some gallery plugins do.
				$list = maybe_unserialize( $list );
				if ( is_object( $list ) ) {
					$list = get_object_vars( $list );
				}
				if ( is_array( $list ) ) {
					$flat = array();
					array_walk_recursive( $list, function ( $item ) use ( &$flat ) {
						if ( is_scalar( $item ) ) {
							$flat[] = (string) $item;
						}
					} );
					$list = implode( ',', $flat );
				}
				if ( is_scalar( $list ) && in_array( $id, preg_split( '/[\s,]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY ), true ) ) {
					return true;
				}
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->termmeta} WHERE meta_key = 'thumbnail_id' AND meta_value = %s LIMIT 1",
			$id
		) ) ) {
			return true;
		}

		$file = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return '' !== $file && (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d LIMIT 1",
			$file,
			(int) $attachment_id
		) );
	}

	public static function set_external_images( $product_id, $image_entries, $force = false ) {
		if ( empty( $image_entries ) || ! is_array( $image_entries ) ) {
			return;
		}

		// Separate main product images from variation images.
		$main_urls     = array();
		$variation_map = array(); // variation_id => url

		foreach ( $image_entries as $entry ) {
			if ( is_array( $entry ) ) {
				$url = $entry[0] ?? '';
				$var_id = $entry[1] ?? false;
				if ( ! empty( $url ) && $var_id && '__retry' !== $var_id ) {
					$variation_map[ $var_id ] = $url;
				} elseif ( ! empty( $url ) ) {
					$main_urls[] = $url;
				}
			} else {
				if ( ! empty( $entry ) ) {
					$main_urls[] = $entry;
				}
			}
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		// Check if this product already has locally-downloaded images with matching hash.
		// If so, skip — no need to overwrite local images with external URLs.
		$plain_urls = array_merge( $main_urls, array_values( $variation_map ) );
		sort( $plain_urls );

		// "Overwrite Images" decides what the product ends up showing, so it is
		// part of what the hash stands for. Leaving it out meant turning the
		// setting on changed nothing for precisely the products it exists to
		// fix: their eBay addresses had not changed, so the hash still matched
		// and they were skipped by the check below — before the localizer, which
		// is where the setting is applied, ever got to run. Folding it in means
		// changing the setting re-queues every product once, and once only.
		$settings         = get_option( 'tcgiant_sync_ebay_settings', array() );
		$overwrite_images = ! empty( $settings['overwrite_images'] );

		$new_hash = md5( implode( '|', $plain_urls ) . ( $overwrite_images ? '|overwrite' : '' ) );
		$stored_hash = get_post_meta( $product_id, '_tcgiant_image_urls_hash', true );
		$is_localized = (int) get_post_meta( $product_id, '_tcgiant_images_localized', true );

		// A placeholder counts as "no image". It is a stand-in attachment
		// pointing at eBay's CDN, not a downloaded file — but has_post_thumbnail()
		// cannot tell the difference, so a product left holding one satisfied
		// every condition below and was skipped forever. Its eBay URLs never
		// change either, so neither a re-sync nor a full re-fetch could rescue
		// it. Treating placeholders as missing lets those products heal
		// themselves on the next ordinary sync.
		$has_thumbnail = self::has_local_thumbnail( $product_id );

		if ( ! $force && $new_hash === $stored_hash && $is_localized && $has_thumbnail ) {
			// Images unchanged and already localized — nothing to do.
			return;
		}

		// Photographs that came from the shop rather than from eBay.
		//
		// Nothing has ever been imported for this product — no external
		// addresses have ever been stored against it — and yet it already has
		// a real local image. So the pictures are the seller's own, and what
		// eBay is offering back is a copy of what we sent it.
		//
		// Downloading that copy attached a second set of every photograph:
		// -1-1.jpg beside the -1.jpg they uploaded, titled from the filename
		// rather than the product, and with no author because cron did it and
		// not a person. The check further down stops placeholders being
		// attached over local images, but nothing stopped the download itself
		// being queued, and it is the download that creates the duplicates.
		//
		// The hash is recorded so this is not reconsidered on every sync.
		// Turning on "Overwrite Images" changes the hash and bypasses this,
		// which is precisely what that setting is for.
		$never_imported = ! get_post_meta( $product_id, '_tcgiant_external_image_urls', true );

		if ( ! $force && ! $overwrite_images && $has_thumbnail && $never_imported ) {
			update_post_meta( $product_id, '_tcgiant_image_urls_hash', $new_hash );
			update_post_meta( $product_id, '_tcgiant_images_localized', 1 );
			return;
		}

		// Store the hash and external URLs for the localizer to use later.
		update_post_meta( $product_id, '_tcgiant_image_urls_hash', $new_hash );
		update_post_meta( $product_id, '_tcgiant_external_image_urls', $image_entries );

		// Re-queue when the image set changed, when there is no real local
		// thumbnail, or when the caller explicitly asked for a re-download.
		if ( $force || $new_hash !== $stored_hash || ! $has_thumbnail ) {
			update_post_meta( $product_id, '_tcgiant_images_localized', 0 );
		}

		// A changed image set is a fresh start, so clear any retry state —
		// otherwise a product that had exhausted its attempts on the old URLs
		// would be given up on immediately, and a stale backoff would delay it.
		if ( $force || $new_hash !== $stored_hash ) {
			delete_post_meta( $product_id, '_tcgiant_localize_attempts' );
			delete_post_meta( $product_id, '_tcgiant_localize_pauses' );
			delete_post_meta( $product_id, '_tcgiant_localize_retry_after' );
		}

		// Only set external image URLs if the product doesn't already have local images.
		// If it does have images and the hash matches, keep the local copies.
		if ( ! $has_thumbnail && ! empty( $main_urls ) ) {
			// WooCommerce doesn't natively support external image URLs on
			// products, so we attach placeholders that carry the eBay URL and
			// let the front-end filters resolve them. The localizer replaces
			// these with real attachments later.
			self::set_external_placeholders( $product_id, $main_urls );
		}

		// Ensure the localization cron is scheduled.
		self::ensure_cron_scheduled();
	}

	/**
	 * Whether a product has a real, locally-downloaded featured image.
	 *
	 * Distinguishes a genuine attachment from the placeholder the importer
	 * attaches so a product is visible immediately after import — that
	 * placeholder has no file behind it and points at eBay's CDN, which stops
	 * working once the listing ends.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function has_local_thumbnail( $product_id ) {
		if ( ! has_post_thumbnail( $product_id ) ) {
			return false;
		}

		$thumb_id = get_post_thumbnail_id( $product_id );
		if ( ! $thumb_id ) {
			return false;
		}

		return empty( get_post_meta( $thumb_id, '_tcgiant_external_url', true ) );
	}

	/**
	 * Stand in for a product's whole image set with eBay-hosted placeholders.
	 *
	 * Creates lightweight attachment records pointing at the external URLs, so
	 * WooCommerce can display them without downloading anything.
	 *
	 * The first URL becomes the featured image and the rest become the gallery.
	 * Only the featured image used to be represented, so a listing with twelve
	 * photos showed exactly one until the localizer replaced the placeholder
	 * with real attachments — and with "keep eBay-hosted URLs" enabled the
	 * localizer never runs, so it showed one forever.
	 *
	 * @param int      $product_id Product ID.
	 * @param string[] $urls       External image URLs, in eBay's order.
	 */
	private static function set_external_placeholders( $product_id, $urls ) {
		$urls = array_values( array_unique( array_filter( $urls ) ) );
		if ( empty( $urls ) ) {
			return;
		}

		$thumb_id = self::get_or_create_external_attachment( $product_id, array_shift( $urls ) );
		if ( $thumb_id ) {
			set_post_thumbnail( $product_id, $thumb_id );
		}

		if ( empty( $urls ) ) {
			return;
		}

		// Never overwrite a real gallery. A product part-way through
		// localization has genuine attachments here, and replacing them with
		// eBay-hosted stand-ins would be a downgrade.
		$existing = array_filter( explode( ',', (string) get_post_meta( $product_id, '_product_image_gallery', true ) ) );
		foreach ( $existing as $gid ) {
			if ( ! get_post_meta( (int) $gid, '_tcgiant_external_url', true ) ) {
				return;
			}
		}

		$gallery_ids = array();
		foreach ( $urls as $url ) {
			$id = self::get_or_create_external_attachment( $product_id, $url );
			if ( $id ) {
				$gallery_ids[] = $id;
			}
		}

		if ( ! empty( $gallery_ids ) ) {
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
		}
	}

	/**
	 * Get or create a placeholder attachment standing in for an eBay-hosted image.
	 *
	 * @param int    $product_id Product to attach to.
	 * @param string $url        eBay image URL.
	 * @return int Attachment ID, or 0 on failure.
	 */
	private static function get_or_create_external_attachment( $product_id, $url ) {
		// Check if we already have an external attachment for this URL.
		$existing = get_posts( array(
			'post_type'   => 'attachment',
			'post_parent' => $product_id,
			'meta_key'    => '_tcgiant_external_url',
			'meta_value'  => $url,
			'fields'      => 'ids',
			'numberposts' => 1,
		) );

		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		// Create a placeholder attachment with the external URL.
		$attachment_id = wp_insert_attachment( array(
			'post_title'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
			// Its own record name. From the title, every stand-in was
			// "s-l1600-jpg", and WordPress tried s-l1600-jpg-2, -3 and so on,
			// one lookup each, past every stand-in still waiting for its photo.
			'post_name'      => sprintf( 'ebay-photo-%d-%s', (int) $product_id, substr( md5( $url ), 0, 10 ) ),
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
			'guid'           => $url,
		), false, $product_id );

		if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
			// The external URL is the source of truth, read back by the
			// wp_get_attachment_url / _image_src filters registered above.
			update_post_meta( $attachment_id, '_tcgiant_external_url', $url );
			update_post_meta( $attachment_id, '_tcgiant_source_url', preg_replace( '/\?.*$/', '', $url ) );

			// Deliberately NOT storing the URL in _wp_attached_file.
			// WordPress treats that value as a path relative to the uploads
			// directory and prepends the uploads base URL, which turned every
			// placeholder into
			//   https://site.com/wp-content/uploads/https://i.ebayimg.com/...
			// and is why images never appeared with localization disabled.

			$metadata = array(
				'width'  => 800,
				'height' => 800,
				'sizes'  => array(),
			);
			update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );

			return (int) $attachment_id;
		}

		return 0;
	}

	/**
	 * Ensure the localization cron is scheduled.
	 */
	public static function ensure_cron_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Schedule for 5 minutes from now to batch up products.
			wp_schedule_single_event( time() + 300, self::CRON_HOOK );
		}

		// Scheduling alone is not enough on hosts that disable WP-Cron without
		// configuring a replacement — the event would simply never fire and
		// products would keep pointing at eBay-hosted images indefinitely.
		if ( class_exists( 'TCGiant_Sync_Cron' ) ) {
			TCGiant_Sync_Cron::request_dispatch();
		}
	}

	/**
	 * Process a batch of products — download their external images to local media library.
	 *
	 * Called by WP-Cron. Processes PRODUCTS_PER_BATCH products per execution,
	 * then reschedules itself if more remain.
	 */
	public function process_batch() {
		// A batch PHP kills leaves a line saying how, and on which product.
		TCGiant_Sync_Runtime::watch( 'Image localiser' );

		try {
			$this->run_batch();
		} finally {
			TCGiant_Sync_Runtime::unwatch();
		}
	}

	/**
	 * The body of process_batch(), watched by it.
	 */
	private function run_batch() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );

		// Check if localization is disabled.
		if ( isset( $settings['disable_image_localization'] ) && ! empty( $settings['disable_image_localization'] ) ) {
			return;
		}

		TCGiant_Sync_Runtime::raise_time_limit( 300 );

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Find products that need localization and are not in retry backoff.
		global $wpdb;
		$now = time();

		$product_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_ext ON p.ID = pm_ext.post_id AND pm_ext.meta_key = '_tcgiant_external_image_urls'
			 INNER JOIN {$wpdb->postmeta} pm_loc ON p.ID = pm_loc.post_id AND pm_loc.meta_key = '_tcgiant_images_localized'
			 LEFT JOIN {$wpdb->postmeta} pm_retry ON p.ID = pm_retry.post_id AND pm_retry.meta_key = '_tcgiant_localize_retry_after'
			 WHERE p.post_type IN ('product', 'product_variation')
			   AND p.post_status IN ('publish', 'draft', 'private')
			   AND pm_loc.meta_value = '0'
			   AND ( pm_retry.meta_value IS NULL OR CAST( pm_retry.meta_value AS UNSIGNED ) <= %d )
			 ORDER BY p.ID ASC
			 LIMIT %d",
			$now,
			self::PRODUCTS_PER_BATCH
		) );

		if ( empty( $product_ids ) ) {
			// Nothing due right now — but something may be waiting out a backoff.
			$next_due = $wpdb->get_var( $wpdb->prepare(
				"SELECT MIN( CAST( pm_retry.meta_value AS UNSIGNED ) ) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_loc ON p.ID = pm_loc.post_id AND pm_loc.meta_key = '_tcgiant_images_localized'
				 INNER JOIN {$wpdb->postmeta} pm_retry ON p.ID = pm_retry.post_id AND pm_retry.meta_key = '_tcgiant_localize_retry_after'
				 WHERE p.post_type IN ('product', 'product_variation')
				   AND p.post_status IN ('publish', 'draft', 'private')
				   AND pm_loc.meta_value = '0'
				   AND CAST( pm_retry.meta_value AS UNSIGNED ) > %d",
				$now
			) );

			if ( $next_due ) {
				$delay = max( 30, (int) $next_due - $now );
				TCGiant_Sync_Logger::log( sprintf(
					'Image localizer: Nothing due yet — products waiting out a retry backoff. Next check in %ds.',
					$delay
				) );
				wp_schedule_single_event( $now + $delay, self::CRON_HOOK );
				return;
			}

			TCGiant_Sync_Logger::log( 'Image localizer: No products pending. Done.' );
			return;
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Image localizer: Processing batch of %d products.',
			count( $product_ids )
		) );

		// A run for after the lease, in case this batch is killed. Nothing else
		// would schedule the next one then, and only an admin page load re-arms
		// the queue. Replaced below when the batch finishes normally.
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( time() + self::FALLBACK_SECONDS, self::CRON_HOOK );

		// Cap each photo download. http_request_timeout, used here before,
		// only sets the default that media_sideload_image() overrides with
		// 300 s. A listing's photos are not always on eBay's servers, and one
		// elsewhere had the whole 300 s - longer than some hosts let a request
		// run. Only eBay's are capped in size: a seller's own server may hold
		// larger originals, and cutting one short would break it.
		$download_cap = function ( $args, $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$ebay = 'ebayimg.com' === $host || '.ebayimg.com' === substr( $host, -12 );

			if ( $ebay || ( '' !== self::$downloading && $url === self::$downloading ) ) {
				$args['timeout'] = min( (float) ( $args['timeout'] ?? self::DOWNLOAD_TIMEOUT ), self::DOWNLOAD_TIMEOUT );
			}

			if ( $ebay ) {
				$args['limit_response_size'] = self::DOWNLOAD_MAX_BYTES;
			}

			return $args;
		};
		add_filter( 'http_request_args', $download_cap, 10, 2 );

		/**
		 * JPEG quality for images imported from eBay.
		 *
		 * Scoped to this batch and removed afterwards, so it never affects
		 * images the merchant uploads themselves. Defaults to WordPress's own
		 * value; lowering it to around 78 is usually indistinguishable on
		 * product photography and saves roughly a further fifth of the bytes.
		 *
		 * @param int $quality JPEG quality, 1-100.
		 */
		$quality_filter = function() {
			return (int) apply_filters( 'tcgiant_sync_image_quality', 82 );
		};
		add_filter( 'wp_editor_set_quality', $quality_filter );

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$total_downloaded = 0;
		$total_skipped    = 0;
		$total_failed     = 0;

		$started_on = 0;
		$stopped_for = '';
		$paused      = false;

		foreach ( $product_ids as $product_id ) {
			// Stop starting products well short of whatever this host allows.
			// A batch of fifty used to run until the host killed it.
			if ( $started_on > 0 ) {
				TCGiant_Sync_Runtime::release_memory();
				$stopped_for = TCGiant_Sync_Runtime::over_budget();
				if ( '' !== $stopped_for ) {
					break;
				}
			}

			$started_on++;
			$this->context = sprintf( 'WC #%d (%d of %d in this batch)', (int) $product_id, $started_on, count( $product_ids ) );
			TCGiant_Sync_Runtime::context( $this->context );

			try {
				$result = $this->localize_product_images( (int) $product_id );
			} catch ( \Throwable $e ) {
				// One product's trouble is not the batch's. Its attempt was
				// charged before it started, so it cannot come round for ever.
				TCGiant_Sync_Logger::error( sprintf( 'Image localizer: WC #%d failed: %s', (int) $product_id, $e->getMessage() ) );
				$total_failed++;
				continue;
			}

			$total_downloaded += $result['downloaded'];
			$total_skipped    += $result['skipped'];
			$total_failed     += $result['failed'];

			// Out of time part way through it. Nothing more fits in this
			// request; the next batch carries on.
			if ( ! empty( $result['paused'] ) ) {
				$paused = true;
				break;
			}
		}

		TCGiant_Sync_Runtime::context( '' );

		if ( '' !== $stopped_for ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Image localizer: stopped after %d product(s) to stay within this host\'s limits (%s).',
				$started_on - 1,
				$stopped_for
			) );
		}

		remove_filter( 'http_request_args', $download_cap, 10 );

		// Finished normally, so the fallback is not needed; what comes next is
		// decided below.
		wp_clear_scheduled_hook( self::CRON_HOOK );
		remove_filter( 'wp_editor_set_quality', $quality_filter );

		TCGiant_Sync_Logger::log( sprintf(
			'Image localizer: Batch complete. %d downloaded, %d skipped, %d failed.',
			$total_downloaded, $total_skipped, $total_failed
		), $total_failed > 0 ? 'warning' : 'success' );

		// Check if more products are due now. Products sitting in retry backoff
		// are deliberately excluded — counting them here would reschedule every
		// 30 seconds and re-select the same failing rows in a tight loop.
		$remaining = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_ext ON p.ID = pm_ext.post_id AND pm_ext.meta_key = '_tcgiant_external_image_urls'
			 INNER JOIN {$wpdb->postmeta} pm_loc ON p.ID = pm_loc.post_id AND pm_loc.meta_key = '_tcgiant_images_localized'
			 LEFT JOIN {$wpdb->postmeta} pm_retry ON p.ID = pm_retry.post_id AND pm_retry.meta_key = '_tcgiant_localize_retry_after'
			 WHERE p.post_type IN ('product', 'product_variation')
			   AND p.post_status IN ('publish', 'draft', 'private')
			   AND pm_loc.meta_value = '0'
			   AND ( pm_retry.meta_value IS NULL OR CAST( pm_retry.meta_value AS UNSIGNED ) <= %d )",
			time()
		) );

		if ( (int) $remaining > 0 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Image localizer: %d products remaining. Scheduling next batch in 30s.',
				$remaining
			) );
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
			return;
		}

		// A product that stopped part way through is due again in a minute.
		if ( $paused ) {
			wp_schedule_single_event( time() + self::PAUSE_SECONDS, self::CRON_HOOK );
			return;
		}

		// Nothing due now — re-arm for whatever is waiting out a backoff.
		self::ensure_cron_scheduled();
	}

	/**
	 * Localize all images for a single product.
	 *
	 * Downloads external eBay images and replaces the external attachment references
	 * with real local attachments.
	 *
	 * @param int $product_id Product ID.
	 * @return array Counts: downloaded, skipped, failed.
	 */
	private function localize_product_images( $product_id ) {
		$image_entries = get_post_meta( $product_id, '_tcgiant_external_image_urls', true );
		$result = array( 'downloaded' => 0, 'skipped' => 0, 'failed' => 0, 'permanent' => 0 );

		if ( empty( $image_entries ) || ! is_array( $image_entries ) ) {
			// Nothing to localize — mark as done.
			update_post_meta( $product_id, '_tcgiant_images_localized', 1 );
			return $result;
		}

		// Charge the attempt before doing anything, and take a lease on the
		// product - see LEASE_SECONDS. The in-flight mark is cleared however
		// the attempt ends, so finding it still set means the last one died.
		//
		// Only attempts that DIED are given up on here. A product that failed
		// in the ordinary way and was queued again - by an images-only sync,
		// as the give-up message advises, or by a sync that touched it - gets
		// a real attempt, as it always did.
		$attempts = (int) get_post_meta( $product_id, '_tcgiant_localize_attempts', true );
		$inflight = (string) get_post_meta( $product_id, '_tcgiant_localize_inflight', true );
		$died     = '' !== $inflight;
		$progress = (int) get_post_meta( $product_id, '_tcgiant_localize_progress', true );

		// A run that died but downloaded something on the way did not fail -
		// it ran out of room, and what it fetched is kept and reused. A product
		// with many photos on a host that allows a few per request finishes
		// a few at a time, so that death is not counted.
		if ( $died && $progress > (int) $inflight - 1 ) {
			$attempts = max( 0, $attempts - 1 );
		}

		if ( $died && $attempts >= self::MAX_LOCALIZE_ATTEMPTS ) {
			delete_post_meta( $product_id, '_tcgiant_localize_inflight' );
			update_post_meta( $product_id, '_tcgiant_images_localized', 1 );
			delete_post_meta( $product_id, '_tcgiant_localize_retry_after' );
			delete_post_meta( $product_id, '_tcgiant_localize_pauses' );
			TCGiant_Sync_Logger::error( sprintf(
				'Image localizer: WC #%d gave up after %d attempts that never finished - the request was stopped while working on it each time. Its eBay photos stay in place. Re-run an images-only sync for this item to try again.',
				$product_id,
				$attempts
			) );
			$result['failed'] = count( $image_entries );
			return $result;
		}

		update_post_meta( $product_id, '_tcgiant_localize_attempts', $attempts + 1 );
		update_post_meta( $product_id, '_tcgiant_localize_retry_after', time() + self::LEASE_SECONDS );
		// How far it had got, plus one, so the mark is never empty.
		update_post_meta( $product_id, '_tcgiant_localize_inflight', $progress + 1 );

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$overwrite_images = ! empty( $settings['overwrite_images'] );

		$gallery_ids = array();

		// "Overwrite Images" means eBay's photos are authoritative. The setting
		// was read and then never used, so a product that already had a main
		// image kept it whatever eBay showed, and every eBay photo was appended
		// to the gallery behind it. There was no way to make eBay's photo the
		// main one short of deleting the existing image by hand.
		$needs_thumbnail = $overwrite_images || ! has_post_thumbnail( $product_id );

		// If product has a thumbnail, check if it's an external placeholder.
		if ( ! $needs_thumbnail ) {
			$thumb_id = get_post_thumbnail_id( $product_id );
			$external_url = get_post_meta( $thumb_id, '_tcgiant_external_url', true );
			if ( ! empty( $external_url ) ) {
				// It's a placeholder — we need to replace it.
				$needs_thumbnail = true;
			}
		}

		// Load existing gallery to append to. When overwriting, eBay's set
		// replaces it instead — appending would leave the old photos in place
		// and simply add the new ones after them.
		//
		// Only the gallery list is rebuilt. The attachments themselves are left
		// in the Media Library, since they may be the merchant's own uploads and
		// may be in use elsewhere.
		if ( ! $overwrite_images ) {
			$existing_gallery = get_post_meta( $product_id, '_product_image_gallery', true );
			if ( ! empty( $existing_gallery ) ) {
				$gallery_ids = array_filter( explode( ',', $existing_gallery ) );
			}
		}

		// Which photo this is, for the line a killed request leaves.
		$photo = 0;

		foreach ( $image_entries as $image_entry ) {
			++$photo;
			$variation_id = false;
			$image_url = $image_entry;
			if ( is_array( $image_entry ) ) {
				$variation_id = $image_entry[1] ?? false;
				$image_url    = $image_entry[0] ?? '';
				if ( '__retry' === $variation_id ) {
					$variation_id = false;
				}
			}

			if ( empty( $image_url ) ) {
				continue;
			}

			// Check if this image is already downloaded (by source URL dedup).
			$clean_url = preg_replace( '/\?.*$/', '', $image_url );
			$existing_attachment = $this->find_existing_local_attachment( $product_id, $clean_url );

			if ( $existing_attachment ) {
				// Already have a local copy — ensure it's properly assigned.
				if ( $variation_id ) {
					if ( ! has_post_thumbnail( $variation_id ) ) {
						set_post_thumbnail( $variation_id, $existing_attachment );
					}
				} elseif ( $needs_thumbnail ) {
					set_post_thumbnail( $product_id, $existing_attachment );
					$needs_thumbnail = false;
				} elseif ( (int) $existing_attachment !== (int) get_post_thumbnail_id( $product_id ) ) {
					// Reused images still belong in the gallery. Skipping this
					// step meant every re-localization (hash change, relist,
					// images-only sync) dropped already-downloaded images from
					// _product_image_gallery, so galleries shrank over time.
					// Not the main image, though: a product finished over more
					// than one batch showed its main photo twice.
					$gallery_ids[] = $existing_attachment;
				}
				$result['skipped']++;
				continue;
			}

			// Out of time, with something to show for it: stop here, and let
			// the next batch carry on from this photo. A product with a lot of
			// photos used to run on until the host killed the request - every
			// time, on a host that cuts requests off after three minutes -
			// while the rest of the queue waited behind it. Time only: the
			// memory in use is this product's own, and the batch checks
			// memory between products.
			if ( $result['downloaded'] > 0 ) {
				TCGiant_Sync_Runtime::release_memory();
				if ( '' !== TCGiant_Sync_Runtime::over_time_budget() ) {
					$result['paused'] = true;
					break;
				}
			}

			TCGiant_Sync_Runtime::context( sprintf( '%s, photo %d of %d', $this->context, $photo, count( $image_entries ) ) );

			// Download the image, honouring the configured maximum width.
			// $clean_url (the original address) remains the dedup key, so
			// changing the size setting never orphans existing downloads.
			//
			// WordPress's two largest sizes are not made for it (see
			// SKIPPED_IMPORT_SIZES). For this download only, so nothing the
			// shop uploads, and nothing else this request does, is touched -
			// whatever exception the download throws. Late, so a shop's filter
			// sees the list every other plugin has finished with.
			//
			// The photo is marked as the plugin's as it is created - see
			// sideload_photo() - not afterwards, as it was until 3.27.4.
			self::$downloading = self::apply_image_size_preference( $image_url );
			add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'import_image_sizes' ), 99, 3 );
			try {
				$id = self::sideload_photo( $product_id, self::$downloading, $clean_url );
			} finally {
				remove_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'import_image_sizes' ), 99 );
				self::$downloading = '';
			}
			if ( is_wp_error( $id ) ) {
				// A 404/403/410 will never succeed, so it must not keep the
				// product in the retry queue forever. Anything else (timeout,
				// 5xx, DNS) is worth another attempt.
				if ( self::is_permanent_image_failure( $id ) ) {
					$result['permanent']++;
					TCGiant_Sync_Logger::warning( sprintf(
						'Image localizer: [PERMANENT] WC #%d — %s — URL: %s — will not retry.',
						$product_id, $id->get_error_message(), $image_url
					) );
				} else {
					$result['failed']++;
					TCGiant_Sync_Logger::warning( sprintf(
						'Image localizer: Failed for WC #%d — %s — URL: %s',
						$product_id, $id->get_error_message(), $image_url
					) );
				}
				continue;
			}

			// Progress, for an attempt the host stops part way through.
			update_post_meta( $product_id, '_tcgiant_localize_progress', (int) get_post_meta( $product_id, '_tcgiant_localize_progress', true ) + 1 );

			if ( $variation_id ) {
				set_post_thumbnail( $variation_id, $id );
			} elseif ( $needs_thumbnail ) {
				// Remove the external placeholder attachment if it exists.
				$old_thumb_id = get_post_thumbnail_id( $product_id );
				if ( $old_thumb_id ) {
					$is_external = get_post_meta( $old_thumb_id, '_tcgiant_external_url', true );
					if ( ! empty( $is_external ) ) {
						wp_delete_attachment( $old_thumb_id, true );
					}
				}
				set_post_thumbnail( $product_id, $id );
				$needs_thumbnail = false;
			} else {
				$gallery_ids[] = $id;
			}

			$result['downloaded']++;
		}

		// Stopped part way, which is not a failure: hand back the attempt it
		// was charged, so pausing can never use up a product's tries, and come
		// round again soon. Every pause has downloaded a photo the next batch
		// finds and skips, so a product pauses at most once per photo; past
		// that, something is wrong and it is charged like any other attempt.
		//
		// The gallery is left as it is - the eBay stand-ins for the photos
		// still to come stay in place - and is rebuilt when the product is
		// finished, from the copies made along the way.
		if ( ! empty( $result['paused'] ) ) {
			$pauses = (int) get_post_meta( $product_id, '_tcgiant_localize_pauses', true ) + 1;
			update_post_meta( $product_id, '_tcgiant_localize_pauses', $pauses );

			if ( $pauses <= count( $image_entries ) ) {
				update_post_meta( $product_id, '_tcgiant_localize_attempts', $attempts );
			}

			delete_post_meta( $product_id, '_tcgiant_localize_inflight' );
			delete_post_meta( $product_id, '_tcgiant_localize_progress' );
			update_post_meta( $product_id, '_tcgiant_localize_retry_after', time() + self::PAUSE_SECONDS );

			TCGiant_Sync_Logger::log( sprintf(
				'Image localizer: WC #%d paused at photo %d of %d, to stay within this host\'s limits. The next batch carries on from there.',
				$product_id,
				$photo,
				count( $image_entries )
			) );

			return $result;
		}

		// Update gallery.
		if ( ! empty( $gallery_ids ) ) {
			$gallery_ids = array_unique( array_filter( $gallery_ids ) );
			// Remove any external placeholder IDs from gallery.
			$clean_gallery = array();
			$placeholders  = array();
			$main_photo    = (int) get_post_thumbnail_id( $product_id );

			// Each eBay photo once, whichever copy of it: two downloads of one
			// photo - one fetched again while the other was waiting to be
			// removed, say - are the same picture shown twice. The first kept
			// is the one the product already showed. Only the plugin's own
			// copies are compared; the shop's uploads carry no source.
			$sources = array();
			if ( $main_photo > 0 && ! get_post_meta( $main_photo, '_tcgiant_external_url', true ) ) {
				$main_source = (string) get_post_meta( $main_photo, '_tcgiant_source_url', true );
				if ( '' !== $main_source ) {
					$sources[ $main_source ] = true;
				}
			}

			foreach ( $gallery_ids as $gid ) {
				// A copy this plugin made of the main photo is the main photo,
				// not another gallery entry. One a product already shows twice
				// is put right here, the next time its photos are fetched; one
				// the shop put in the gallery itself is left alone.
				if ( (int) $gid === $main_photo && get_post_meta( (int) $gid, '_tcgiant_source_url', true ) ) {
					continue;
				}
				$is_external = get_post_meta( (int) $gid, '_tcgiant_external_url', true );
				if ( empty( $is_external ) ) {
					$source = (string) get_post_meta( (int) $gid, '_tcgiant_source_url', true );
					if ( '' !== $source ) {
						if ( isset( $sources[ $source ] ) ) {
							continue;
						}
						$sources[ $source ] = true;
					}
					$clean_gallery[] = $gid;
				} else {
					$placeholders[] = (int) $gid;
				}
			}
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $clean_gallery ) );

			// Discard the eBay-hosted stand-ins only once every image has been
			// downloaded successfully. Dropping them while a retry is still
			// pending would leave the product showing fewer photos than before.
			if ( 0 === $result['failed'] && ! empty( $clean_gallery ) ) {
				foreach ( $placeholders as $pid ) {
					wp_delete_attachment( $pid, true );
				}
			}
		}

		// Only declare the product done when nothing retryable failed.
		//
		// This flag is the sole gate on the batch query (meta_value = '0'), so
		// setting it unconditionally meant a single timeout or transient 5xx
		// lost the product's images permanently — the localizer would never
		// look at it again.
		// The attempt finished, whichever way it went.
		delete_post_meta( $product_id, '_tcgiant_localize_inflight' );
		delete_post_meta( $product_id, '_tcgiant_localize_progress' );

		if ( 0 === $result['failed'] ) {
			update_post_meta( $product_id, '_tcgiant_images_localized', 1 );
			delete_post_meta( $product_id, '_tcgiant_localize_attempts' );
			delete_post_meta( $product_id, '_tcgiant_localize_pauses' );
			delete_post_meta( $product_id, '_tcgiant_localize_retry_after' );

			if ( $result['permanent'] > 0 ) {
				TCGiant_Sync_Logger::warning( sprintf(
					'Image localizer: WC #%d finished with %d permanently unavailable image(s).',
					$product_id, $result['permanent']
				) );
			}
		} else {
			// Already charged at the start.
			$attempts = max( 1, (int) get_post_meta( $product_id, '_tcgiant_localize_attempts', true ) );

			if ( $attempts >= self::MAX_LOCALIZE_ATTEMPTS ) {
				// Give up so one unreachable image can't pin the queue open.
				update_post_meta( $product_id, '_tcgiant_images_localized', 1 );
				delete_post_meta( $product_id, '_tcgiant_localize_retry_after' );
				delete_post_meta( $product_id, '_tcgiant_localize_pauses' );
				TCGiant_Sync_Logger::error( sprintf(
					'Image localizer: WC #%d gave up after %d attempts with %d image(s) still failing. '
					. 'Re-run an images-only sync for this item to try again.',
					$product_id, $attempts, $result['failed']
				) );
			} else {
				// Back off before retrying so a transient outage has time to
				// clear — otherwise the 30s batch loop burns every attempt in
				// under two minutes.
				$backoff = self::RETRY_BACKOFF_SECONDS * $attempts;
				update_post_meta( $product_id, '_tcgiant_localize_retry_after', time() + $backoff );
				TCGiant_Sync_Logger::log( sprintf(
					'Image localizer: WC #%d has %d image(s) still failing — attempt %d of %d, retrying in %ds.',
					$product_id, $result['failed'], $attempts, self::MAX_LOCALIZE_ATTEMPTS, $backoff
				) );
			}
		}

		return $result;
	}

	/**
	 * Check if an image download failure is permanent (should not be retried).
	 *
	 * Permanent failures include HTTP 404/403/410 (expired or deleted eBay
	 * images) and empty/invalid file responses. Transient failures (timeouts,
	 * 5xx, DNS) are worth retrying.
	 *
	 * @param WP_Error $wp_error The error returned by sideload_photo().
	 * @return bool True if the failure is permanent.
	 */
	private static function is_permanent_image_failure( $wp_error ) {
		$code = (string) $wp_error->get_error_code();

		// download_url() answers every status but 200 with "http_404", and
		// keeps the real one in the error's data. Only 403, 404 and 410 are
		// for good: a 429 or a 5xx is a server having a bad moment.
		if ( 'http_404' === $code ) {
			$data   = $wp_error->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['code'] ?? 0 ) : 0;
			if ( $status > 0 ) {
				return in_array( $status, array( 403, 404, 410 ), true );
			}
		}

		// Timeouts, resets, DNS: worth another attempt. The numbers in the
		// message used to be matched on their own, so a timeout "after 30403
		// milliseconds" was taken for a 403 and its photo dropped for good.
		if ( 'http_request_failed' === $code ) {
			return false;
		}

		$message = strtolower( $wp_error->get_error_message() );

		$permanent_patterns = array(
			'not found', 'forbidden', 'gone',
			'file is empty', 'invalid url', 'invalid image',
			'not an accepted', 'could not calculate',
		);

		foreach ( $permanent_patterns as $pattern ) {
			if ( false !== strpos( $message, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find an existing local (non-external) attachment by source URL.
	 *
	 * Searches globally across ALL attachments in the media library, not just
	 * those attached to the current product. This allows re-imported products
	 * (e.g., relisted with a new Item ID/SKU) to reuse images that were
	 * already downloaded for a previous import of the same eBay listing.
	 *
	 * @param int    $product_id Product ID (used to check product-specific attachments first).
	 * @param string $source_url Clean source URL (no query params).
	 * @return int|false Attachment ID or false.
	 */
	private function find_existing_local_attachment( $product_id, $source_url ) {
		// Placeholders carry _tcgiant_source_url too, so they must be excluded
		// in the query rather than filtered out afterwards. With only one
		// placeholder per product that distinction was academic; now that the
		// whole image set is represented, a placeholder matching first would
		// mask a real local copy and force a needless re-download.
		$real_only = array(
			array(
				'key'   => '_tcgiant_source_url',
				'value' => $source_url,
			),
			array(
				'key'     => '_tcgiant_external_url',
				'compare' => 'NOT EXISTS',
			),

			// Never one whose removal was started and given up on
			// (TCGiant_Sync_Photo_Cleanup::FAILED_KEY, named here as a string
			// so this class stands on its own): part of it may already be gone
			// - an offload plugin's remote copies are removed first - though
			// its record is still here.
			array(
				'key'     => '_tcgiant_delete_failed',
				'compare' => 'NOT EXISTS',
			),
		);

		// First, check attachments belonging to this specific product (fastest).
		// Newest first - get_posts()'s own order - so a half-saved copy that
		// could not be finished is passed over once a complete one has been
		// fetched after it.
		//
		// Even one waiting to be removed: another product that showed it was
		// deleted, but a photo attached to a product that exists is never
		// removed while it does. Fetching it again here would only give this
		// product a second copy of what it already has.
		$attachments = get_posts( array(
			'post_type'   => 'attachment',
			'post_parent' => $product_id,
			'meta_query'  => $real_only, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'fields'      => 'ids',
			'numberposts' => 1,
		) );

		// Then one this product already shows, waiting to be removed or not:
		// its showing it is what keeps it, so it is no other product's
		// picture being taken away.
		if ( empty( $attachments ) ) {
			$shown = $this->shown_copy( $product_id, $source_url );
			if ( $shown > 0 ) {
				$attachments = array( $shown );
			}
		}

		// If not found on this product, search globally across all attachments.
		// This catches images from trashed/re-created products with new SKUs.
		//
		// But not a photo waiting to be removed with the product it was
		// deleted with (TCGiant_Sync_Photo_Cleanup::MARK_KEY). That removal
		// checks again only once its quarantine is over, so a product that
		// took the photo in between would be left pointing at nothing. A fresh
		// copy is downloaded instead.
		if ( empty( $attachments ) ) {
			$attachments = get_posts( array(
				'post_type'   => 'attachment',
				'meta_query'  => array_merge( $real_only, array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_tcgiant_delete_after',
						'compare' => 'NOT EXISTS',
					),
				) ),
				'fields'      => 'ids',
				'numberposts' => 1,
			) );
		}

		if ( empty( $attachments ) ) {
			return false;
		}

		// Ensure it's a real local attachment, not an external placeholder.
		$att_id = (int) $attachments[0];
		$is_external = get_post_meta( $att_id, '_tcgiant_external_url', true );
		if ( ! empty( $is_external ) ) {
			return false;
		}

		// A photo still marked unfinished. Either another request is making
		// it right now - one working on another product with the same eBay
		// photo - or a request was stopped part way through it.
		$unfinished = (int) get_post_meta( $att_id, self::UNFINISHED_META, true );
		if ( $unfinished > 0 && $unfinished < 2 ) {
			$unfinished = 1; // A mark with no time in it is an old one.
		}

		// Being made now: shared as it is. The request making it takes the
		// mark off when it is done, and if it is stopped the mark grows old
		// and a later run finishes the photo. Fetching it again here would
		// only have made a second copy.
		if ( $unfinished > 0 && time() - $unfinished < self::LEASE_SECONDS ) {
			return $att_id;
		}

		// Stopped part way: its record and its file are there, some or all of
		// its smaller sizes may not be. WordPress can make whatever is
		// missing from the file, which is the work the request did not
		// finish, so it is finished here rather than fetched again.
		if ( $unfinished > 0 ) {
			$finished = self::finish_photo( $att_id );

			if ( is_wp_error( $finished ) ) {
				TCGiant_Sync_Logger::warning( sprintf(
					'Image localizer: WC #%d - photo #%d was left half saved by a request that was stopped, and could not be finished (%s). Downloading it again; the half-saved copy stays in the Media Library.',
					(int) $product_id,
					$att_id,
					$finished->get_error_message()
				) );
				return false;
			}

			delete_post_meta( $att_id, self::UNFINISHED_META );
			TCGiant_Sync_Logger::log( sprintf(
				'Image localizer: WC #%d - finished photo #%d, which a stopped request had left half saved, instead of downloading it again.',
				(int) $product_id,
				$att_id
			) );
		}

		// Share the attachment as-is, whatever it is parented to.
		//
		// WordPress attachments are referenced by ID from _thumbnail_id and
		// _product_image_gallery; post_parent is only a bookkeeping hint, so
		// several products may safely point at one attachment.
		//
		// Previously this duplicated the attachment post whenever the parent
		// differed, which (a) grew the media library by one row plus full
		// metadata for every product reusing an image, and (b) left duplicates
		// pointing at a file that wp_delete_attachment( $id, true ) on the
		// original would delete out from under them.
		return $att_id;
	}

	/**
	 * A copy of this eBay photo that the product already shows - as its main
	 * photo, in its gallery, or as one of its variations' - whoever it is
	 * attached to.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $source_url Clean source URL (no query params).
	 * @return int Attachment ID, or 0.
	 */
	private function shown_copy( $product_id, $source_url ) {
		$shown   = array( (int) get_post_thumbnail_id( $product_id ) );
		$gallery = (string) get_post_meta( $product_id, '_product_image_gallery', true );
		if ( '' !== $gallery ) {
			$shown = array_merge( $shown, array_map( 'intval', explode( ',', $gallery ) ) );
		}

		$variations = get_posts( array(
			'post_type'   => 'product_variation',
			'post_parent' => $product_id,
			'post_status' => 'any',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( (array) $variations as $variation_id ) {
			$shown[] = (int) get_post_thumbnail_id( (int) $variation_id );
		}

		foreach ( array_unique( array_filter( $shown ) ) as $att_id ) {
			if ( (string) get_post_meta( $att_id, '_tcgiant_source_url', true ) === (string) $source_url
				&& ! get_post_meta( $att_id, '_tcgiant_external_url', true )
				&& ! get_post_meta( $att_id, '_tcgiant_delete_failed', true ) ) {
				return (int) $att_id;
			}
		}

		return 0;
	}

	/**
	 * Make the sizes a stopped request did not get to.
	 *
	 * wp_update_image_subsizes() reports an error only when the photo has no
	 * record of a file at all. A file that is missing or cannot be read comes
	 * back as success with nothing made - so this looks before and after.
	 * The two largest sizes are skipped, as on download: WordPress asks
	 * wp_get_missing_image_subsizes here, not intermediate_image_sizes_advanced.
	 *
	 * A photo whose file is not on this server is fetched again rather than
	 * kept: from here a file an offloading plugin moved cannot be told from
	 * one that never arrived, and a second copy is better than a broken one.
	 *
	 * @param int $att_id Photo.
	 * @return true|WP_Error True when finished; an error to fetch it again.
	 */
	private static function finish_photo( $att_id ) {
		if ( ! function_exists( 'wp_update_image_subsizes' ) ) {
			return new WP_Error( 'tcgiant_no_subsizes', 'this WordPress cannot finish a photo' );
		}

		$file = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $att_id ) : get_attached_file( $att_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'tcgiant_photo_missing', 'its file is not on this server' );
		}

		add_filter( 'wp_get_missing_image_subsizes', array( __CLASS__, 'import_image_sizes' ), 99, 3 );
		try {
			$meta = wp_update_image_subsizes( $att_id );
		} finally {
			remove_filter( 'wp_get_missing_image_subsizes', array( __CLASS__, 'import_image_sizes' ), 99 );
		}

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['file'] ) ) {
			return new WP_Error( 'tcgiant_photo_unreadable', 'its file could not be read as a photo' );
		}

		return true;
	}

	/**
	 * Clear the localization cron on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
