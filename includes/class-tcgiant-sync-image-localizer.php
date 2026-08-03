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
		$pending = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE meta_key = '_tcgiant_images_localized' AND meta_value = '0'
			 LIMIT 1"
		);

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
		$new_hash = md5( implode( '|', $plain_urls ) );
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
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );

		// Check if localization is disabled.
		if ( isset( $settings['disable_image_localization'] ) && ! empty( $settings['disable_image_localization'] ) ) {
			return;
		}

		@set_time_limit( 300 );
		ignore_user_abort( true );

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

		// Lower HTTP timeout for image downloads.
		$timeout_filter = function() { return 30; };
		add_filter( 'http_request_timeout', $timeout_filter );

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

		foreach ( $product_ids as $product_id ) {
			$result = $this->localize_product_images( (int) $product_id );
			$total_downloaded += $result['downloaded'];
			$total_skipped    += $result['skipped'];
			$total_failed     += $result['failed'];
		}

		remove_filter( 'http_request_timeout', $timeout_filter );
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

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$overwrite_images = ! empty( $settings['overwrite_images'] );

		$gallery_ids = array();
		$needs_thumbnail = ! has_post_thumbnail( $product_id );

		// If product has a thumbnail, check if it's an external placeholder.
		if ( ! $needs_thumbnail ) {
			$thumb_id = get_post_thumbnail_id( $product_id );
			$external_url = get_post_meta( $thumb_id, '_tcgiant_external_url', true );
			if ( ! empty( $external_url ) ) {
				// It's a placeholder — we need to replace it.
				$needs_thumbnail = true;
			}
		}

		// Load existing gallery to append to.
		$existing_gallery = get_post_meta( $product_id, '_product_image_gallery', true );
		if ( ! empty( $existing_gallery ) ) {
			$gallery_ids = array_filter( explode( ',', $existing_gallery ) );
		}

		foreach ( $image_entries as $image_entry ) {
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
				} else {
					// Reused images still belong in the gallery. Skipping this
					// step meant every re-localization (hash change, relist,
					// images-only sync) dropped already-downloaded images from
					// _product_image_gallery, so galleries shrank over time.
					$gallery_ids[] = $existing_attachment;
				}
				$result['skipped']++;
				continue;
			}

			// Download the image, honouring the configured maximum width.
			// $clean_url (the original address) remains the dedup key, so
			// changing the size setting never orphans existing downloads.
			$id = media_sideload_image( self::apply_image_size_preference( $image_url ), $product_id, null, 'id' );
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

			// Store source URL on attachment for future dedup.
			update_post_meta( $id, '_tcgiant_source_url', $clean_url );

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

		// Update gallery.
		if ( ! empty( $gallery_ids ) ) {
			$gallery_ids = array_unique( array_filter( $gallery_ids ) );
			// Remove any external placeholder IDs from gallery.
			$clean_gallery = array();
			$placeholders  = array();
			foreach ( $gallery_ids as $gid ) {
				$is_external = get_post_meta( (int) $gid, '_tcgiant_external_url', true );
				if ( empty( $is_external ) ) {
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
		if ( 0 === $result['failed'] ) {
			update_post_meta( $product_id, '_tcgiant_images_localized', 1 );
			delete_post_meta( $product_id, '_tcgiant_localize_attempts' );
			delete_post_meta( $product_id, '_tcgiant_localize_retry_after' );

			if ( $result['permanent'] > 0 ) {
				TCGiant_Sync_Logger::warning( sprintf(
					'Image localizer: WC #%d finished with %d permanently unavailable image(s).',
					$product_id, $result['permanent']
				) );
			}
		} else {
			$attempts = (int) get_post_meta( $product_id, '_tcgiant_localize_attempts', true ) + 1;
			update_post_meta( $product_id, '_tcgiant_localize_attempts', $attempts );

			if ( $attempts >= self::MAX_LOCALIZE_ATTEMPTS ) {
				// Give up so one unreachable image can't pin the queue open.
				update_post_meta( $product_id, '_tcgiant_images_localized', 1 );
				delete_post_meta( $product_id, '_tcgiant_localize_retry_after' );
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
	 * @param WP_Error $wp_error The error returned by media_sideload_image.
	 * @return bool True if the failure is permanent.
	 */
	private static function is_permanent_image_failure( $wp_error ) {
		$message = strtolower( $wp_error->get_error_message() );

		$permanent_patterns = array(
			'404', '403', '410',
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
		// First, check attachments belonging to this specific product (fastest).
		$attachments = get_posts( array(
			'post_type'   => 'attachment',
			'post_parent' => $product_id,
			'meta_key'    => '_tcgiant_source_url',
			'meta_value'  => $source_url,
			'fields'      => 'ids',
			'numberposts' => 1,
		) );

		// If not found on this product, search globally across all attachments.
		// This catches images from trashed/re-created products with new SKUs.
		if ( empty( $attachments ) ) {
			$attachments = get_posts( array(
				'post_type'   => 'attachment',
				'meta_key'    => '_tcgiant_source_url',
				'meta_value'  => $source_url,
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
	 * Clear the localization cron on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
