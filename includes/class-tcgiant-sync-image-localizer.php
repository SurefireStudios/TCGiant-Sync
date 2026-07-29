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
	 * Each product can have 5-12 images, so 20 products ≈ 100-240 downloads.
	 */
	const PRODUCTS_PER_BATCH = 20;

	/**
	 * WP-Cron hook name.
	 */
	const CRON_HOOK = 'tcgiant_sync_localize_images';

	/**
	 * Instance.
	 */
	private static $_instance = null;

	/**
	 * Main instance.
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
	}

	/**
	 * Set external eBay image URLs directly on a WooCommerce product.
	 *
	 * This makes the product immediately visible with images hosted on eBay.
	 * The images will be localized (downloaded to WP media library) in the background.
	 *
	 * @param int   $product_id  WooCommerce product ID.
	 * @param array $image_entries Array of image URLs (strings or [url, variation_id] arrays).
	 */
	public static function set_external_images( $product_id, $image_entries ) {
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

		if ( $new_hash === $stored_hash && $is_localized && has_post_thumbnail( $product_id ) ) {
			// Images unchanged and already localized — nothing to do.
			return;
		}

		// Store the hash and external URLs for the localizer to use later.
		update_post_meta( $product_id, '_tcgiant_image_urls_hash', $new_hash );
		update_post_meta( $product_id, '_tcgiant_external_image_urls', $image_entries );

		// If images changed, mark as not localized so the background process picks it up.
		if ( $new_hash !== $stored_hash ) {
			update_post_meta( $product_id, '_tcgiant_images_localized', 0 );
		}

		// Only set external image URLs if the product doesn't already have local images.
		// If it does have images and the hash matches, keep the local copies.
		if ( ! has_post_thumbnail( $product_id ) && ! empty( $main_urls ) ) {
			// Use the first URL as the product's external image via WC's image setter.
			// WooCommerce doesn't natively support external image URLs on products,
			// so we attach a placeholder and store the external URL for front-end use.
			// The localizer will replace this with a real attachment later.
			self::set_product_external_thumbnail( $product_id, $main_urls[0] );
		}

		// Ensure the localization cron is scheduled.
		self::ensure_cron_scheduled();
	}

	/**
	 * Set an external image URL as the product thumbnail.
	 *
	 * Creates a lightweight attachment record pointing to the external URL.
	 * This lets WooCommerce display the image without downloading it.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $url        External image URL.
	 */
	private static function set_product_external_thumbnail( $product_id, $url ) {
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
			set_post_thumbnail( $product_id, $existing[0] );
			return;
		}

		// Create a placeholder attachment with the external URL.
		$attachment_id = wp_insert_attachment( array(
			'post_title'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
			'guid'           => $url,
		), false, $product_id );

		if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
			// Store external URL metadata so WP knows where to find the image.
			update_post_meta( $attachment_id, '_wp_attached_file', $url );
			update_post_meta( $attachment_id, '_tcgiant_external_url', $url );
			update_post_meta( $attachment_id, '_tcgiant_source_url', preg_replace( '/\?.*$/', '', $url ) );

			// Generate a minimal _wp_attachment_metadata pointing to the external URL.
			// This lets wp_get_attachment_image_src() return the eBay URL.
			$metadata = array(
				'width'  => 800,
				'height' => 800,
				'file'   => $url,
				'sizes'  => array(),
			);
			update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );

			set_post_thumbnail( $product_id, $attachment_id );
		}
	}

	/**
	 * Ensure the localization cron is scheduled.
	 */
	public static function ensure_cron_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Schedule for 5 minutes from now to batch up products.
			wp_schedule_single_event( time() + 300, self::CRON_HOOK );
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

		// Find products that need localization.
		global $wpdb;
		$product_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_ext ON p.ID = pm_ext.post_id AND pm_ext.meta_key = '_tcgiant_external_image_urls'
			 INNER JOIN {$wpdb->postmeta} pm_loc ON p.ID = pm_loc.post_id AND pm_loc.meta_key = '_tcgiant_images_localized'
			 WHERE p.post_type IN ('product', 'product_variation')
			   AND p.post_status IN ('publish', 'draft', 'private')
			   AND pm_loc.meta_value = '0'
			 ORDER BY p.ID ASC
			 LIMIT %d",
			self::PRODUCTS_PER_BATCH
		) );

		if ( empty( $product_ids ) ) {
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

		TCGiant_Sync_Logger::log( sprintf(
			'Image localizer: Batch complete. %d downloaded, %d skipped, %d failed.',
			$total_downloaded, $total_skipped, $total_failed
		), $total_failed > 0 ? 'warning' : 'success' );

		// Check if more products need processing.
		$remaining = $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_ext ON p.ID = pm_ext.post_id AND pm_ext.meta_key = '_tcgiant_external_image_urls'
			 INNER JOIN {$wpdb->postmeta} pm_loc ON p.ID = pm_loc.post_id AND pm_loc.meta_key = '_tcgiant_images_localized'
			 WHERE p.post_type IN ('product', 'product_variation')
			   AND p.post_status IN ('publish', 'draft', 'private')
			   AND pm_loc.meta_value = '0'"
		);

		if ( (int) $remaining > 0 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Image localizer: %d products remaining. Scheduling next batch in 60s.',
				$remaining
			) );
			wp_schedule_single_event( time() + 60, self::CRON_HOOK );
		}
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
		$result = array( 'downloaded' => 0, 'skipped' => 0, 'failed' => 0 );

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
				}
				$result['skipped']++;
				continue;
			}

			// Download the image.
			$id = media_sideload_image( $image_url, $product_id, null, 'id' );
			if ( is_wp_error( $id ) ) {
				$result['failed']++;
				TCGiant_Sync_Logger::warning( sprintf(
					'Image localizer: Failed for WC #%d — %s — URL: %s',
					$product_id, $id->get_error_message(), $image_url
				) );
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
			foreach ( $gallery_ids as $gid ) {
				$is_external = get_post_meta( (int) $gid, '_tcgiant_external_url', true );
				if ( empty( $is_external ) ) {
					$clean_gallery[] = $gid;
				}
			}
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $clean_gallery ) );
		}

		// Mark product as localized.
		update_post_meta( $product_id, '_tcgiant_images_localized', 1 );

		return $result;
	}

	/**
	 * Find an existing local (non-external) attachment by source URL.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $source_url Clean source URL (no query params).
	 * @return int|false Attachment ID or false.
	 */
	private function find_existing_local_attachment( $product_id, $source_url ) {
		$attachments = get_posts( array(
			'post_type'   => 'attachment',
			'post_parent' => $product_id,
			'meta_key'    => '_tcgiant_source_url',
			'meta_value'  => $source_url,
			'fields'      => 'ids',
			'numberposts' => 1,
		) );

		if ( empty( $attachments ) ) {
			return false;
		}

		// Ensure it's a real local attachment, not an external placeholder.
		$att_id = $attachments[0];
		$is_external = get_post_meta( $att_id, '_tcgiant_external_url', true );
		if ( ! empty( $is_external ) ) {
			return false;
		}

		return $att_id;
	}

	/**
	 * Clear the localization cron on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
