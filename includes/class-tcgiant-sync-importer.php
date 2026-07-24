<?php
/**
 * Importer Logic
 *
 * Manages the batch import process from eBay to WooCommerce.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Importer class
 */
class TCGiant_Sync_Importer {

	/**
	 * Instance.
	 */
	private static $_instance = null;

	/**
	 * Sync state option key.
	 */
	const STATE_OPTION = 'tcgiant_sync_state';

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
	 * TCGiant_Sync_Importer Constructor.
	 */
	public function __construct() {
		add_action( 'tcgiant_sync_process_item_import', array( $this, 'process_item_import' ), 10, 1 );
		add_action( 'tcgiant_sync_fetch_listings', array( $this, 'fetch_listings_page' ), 10, 1 );
		add_action( 'tcgiant_sync_fetch_delta_events', array( $this, 'fetch_delta_events' ) );
		add_action( 'tcgiant_sync_download_images', array( $this, 'download_product_images' ), 10, 3 );
		add_action( 'tcgiant_sync_prune_orphans', array( $this, 'prune_orphaned_items' ) );
	}

	/**
	 * Get the current sync state.
	 *
	 * @return array Sync state data.
	 */
	public static function get_sync_state() {
		return get_option( self::STATE_OPTION, array(
			'status'                  => 'idle',       // idle, scanning, importing, complete, stopped, error, rate_limited
			'sync_mode'               => 'full',       // full or delta
			'delta_mod_from'          => '',           // ISO 8601 timestamp for delta sync ModTimeFrom
			'total_found'             => 0,
			'total_queued'            => 0,
			'total_processed'         => 0,
			'total_errors'            => 0,
			'current_page'            => 0,
			'total_pages'             => 0,
			'filter_name'             => '',
			'started_at'              => '',
			'last_activity'           => '',
			'last_completed'          => '',
			'last_item_title'         => '',
			'last_successful_sync_at' => '',           // ISO 8601 UTC timestamp of last successful sync (used as ModTimeFrom for next delta)
			'rate_limit_retries'      => 0,            // Number of consecutive rate limit hits (for escalating backoff)
		) );
	}

	/**
	 * Update sync state.
	 *
	 * @param array $updates Key-value pairs to merge into current state.
	 */
	public static function update_sync_state( $updates ) {
		$state = self::get_sync_state();
		$state = array_merge( $state, $updates );
		$state['last_activity'] = current_time( 'mysql' );
		update_option( self::STATE_OPTION, $state );
	}

	/**
	 * Start a full sync.
	 *
	 * @param bool $force If true, bypass the "already running" guard.
	 */
	public function start_full_sync( $force = false ) {
		// License check: can the user import more products?
		$license = TCGiant_Sync_License::instance();
		if ( ! $license->can_import() ) {
			self::update_sync_state( array( 'status' => 'limit_reached' ) );
			TCGiant_Sync_Logger::log(
				sprintf(
					'Free tier limit reached (%d/%d active products). Upgrade to TCGiant Sync Pro for unlimited imports.',
					$license->get_active_product_count(),
					TCGiant_Sync_License::FREE_LIMIT
				),
				'warning'
			);
			return;
		}

		// Guard: don't restart if a sync is already in progress (prevents cron overlap).
		if ( ! $force ) {
			$current = self::get_sync_state();
			if ( in_array( $current['status'], array( 'scanning', 'importing' ), true ) ) {
				TCGiant_Sync_Logger::log( 'Sync already in progress - skipping duplicate request.' );
				return;
			}
		}

		// Clear any previous pending sync jobs to prevent stacking.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_listings', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_fetch_delta_events', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_download_images', null, 'tcgiant_sync_images' );
		}

		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$filter_name = ! empty( $settings['category_ids'] ) ? $settings['category_ids'] : 'All Categories';

		// Clear cached category ID transient so we get fresh resolution.
		if ( ! empty( $settings['category_ids'] ) ) {
			delete_transient( 'tcgiant_sync_cats_' . md5( $settings['category_ids'] ) );
		}

		// Reset sync state.
		self::update_sync_state( array(
			'status'              => 'scanning',
			'sync_mode'           => 'full',
			'delta_mod_from'      => '',
			'total_found'         => 0,
			'total_queued'        => 0,
			'total_processed'     => 0,
			'total_errors'        => 0,
			'current_page'        => 1,
			'total_pages'         => 0,
			'filter_name'         => $filter_name,
			'started_at'          => current_time( 'mysql' ),
			'last_item_title'     => '',
			'rate_limit_retries'  => 0,
		) );

		// Clear active IDs list for pruning orphaned items.
		delete_option( 'tcgiant_sync_active_ids' );

		$api = TCGiant_Sync_API::instance();
		TCGiant_Sync_Logger::log( sprintf(
			'Starting full sync for: %s (API budget: %d/%d calls remaining today)',
			$filter_name, $api->get_remaining_daily_budget(), TCGiant_Sync_API::DAILY_CALL_LIMIT
		) );
		as_enqueue_async_action( 'tcgiant_sync_fetch_listings', array( 'page_number' => 1 ), 'tcgiant_sync_group' );
	}

	/**
	 * Start a delta sync — only import items modified since the last successful sync.
	 *
	 * Falls back to a full sync if there is no previous sync timestamp (e.g., first run).
	 */
	public function start_delta_sync() {
		$state = self::get_sync_state();
		$last_sync = ! empty( $state['last_successful_sync_at'] ) ? $state['last_successful_sync_at'] : '';

		// No previous sync? Fall back to full sync.
		if ( empty( $last_sync ) ) {
			TCGiant_Sync_Logger::log( 'No previous sync timestamp found — falling back to full sync.' );
			$this->start_full_sync();
			return;
		}

		// License check.
		$license = TCGiant_Sync_License::instance();
		if ( ! $license->can_import() ) {
			self::update_sync_state( array( 'status' => 'limit_reached' ) );
			return;
		}

		// Guard: don't restart if a sync is already in progress.
		if ( in_array( $state['status'], array( 'scanning', 'importing' ), true ) ) {
			TCGiant_Sync_Logger::log( 'Sync already in progress — skipping delta sync request.' );
			return;
		}

		// Clear any previous pending sync jobs.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_listings', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_fetch_delta_events', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_download_images', null, 'tcgiant_sync_images' );
		}

		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$filter_name = ! empty( $settings['category_ids'] ) ? $settings['category_ids'] : 'All Categories';

		// Add a small overlap buffer (5 min) to avoid missing items due to clock skew.
		$buffer_seconds = 300;
		$mod_from_ts = strtotime( $last_sync ) - $buffer_seconds;
		$mod_from_iso = gmdate( 'Y-m-d\TH:i:s.000\Z', $mod_from_ts );

		self::update_sync_state( array(
			'status'          => 'scanning',
			'sync_mode'       => 'delta',
			'delta_mod_from'  => $mod_from_iso,
			'total_found'     => 0,
			'total_queued'    => 0,
			'total_processed' => 0,
			'total_errors'    => 0,
			'current_page'    => 1,
			'total_pages'     => 0,
			'filter_name'     => $filter_name,
			'started_at'      => current_time( 'mysql' ),
			'last_item_title' => '',
		) );

		TCGiant_Sync_Logger::log( sprintf(
			'Starting delta sync for: %s (changes since %s)',
			$filter_name,
			$mod_from_iso
		) );
		as_enqueue_async_action( 'tcgiant_sync_fetch_delta_events', array(), 'tcgiant_sync_group' );
	}

	/**
	 * Start a sync for specific item IDs.
	 *
	 * @param array $item_ids List of eBay Item IDs to sync.
	 */
	public function start_specific_sync( $item_ids ) {
		$license = TCGiant_Sync_License::instance();
		if ( ! $license->can_import() ) {
			self::update_sync_state( array( 'status' => 'limit_reached' ) );
			return;
		}

		$state = self::get_sync_state();
		$new_queued = $state['total_queued'] + count( $item_ids );

		self::update_sync_state( array(
			'status'       => 'importing',
			'total_queued' => $new_queued,
		) );

		foreach ( $item_ids as $item_id ) {
			as_enqueue_async_action( 'tcgiant_sync_process_item_import', array( 'item_id' => $item_id ), 'tcgiant_sync_group' );
		}

		TCGiant_Sync_Logger::log( sprintf( 'Manually queued %d specific item(s) for sync.', count( $item_ids ) ), 'success' );
	}

	/**
	 * Re-sync images only for specific eBay Item IDs.
	 *
	 * Fetches image URLs from eBay via GetItem but does NOT update
	 * product data (title, price, stock, etc). Only re-downloads images.
	 *
	 * @param array $item_ids List of eBay Item IDs.
	 */
	public function start_images_only_sync( $item_ids ) {
		$api = TCGiant_Sync_API::instance();
		$mapper = TCGiant_Sync_Mapper::instance();
		$queued = 0;

		foreach ( $item_ids as $item_id ) {
			$item_id = trim( $item_id );
			if ( empty( $item_id ) ) {
				continue;
			}

			// Find the existing WooCommerce product for this eBay item.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$product_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ebay_item_id' AND meta_value = %s LIMIT 1",
				$item_id
			) );

			if ( ! $product_id ) {
				TCGiant_Sync_Logger::warning( sprintf( 'Images-only sync: No WooCommerce product found for eBay Item %s. Skipping.', $item_id ) );
				continue;
			}

			// Fetch the item from eBay to get current image URLs.
			$ebay_response = $api->get_item( $item_id );
			if ( is_wp_error( $ebay_response ) || ! isset( $ebay_response['Item'] ) ) {
				$msg = is_wp_error( $ebay_response ) ? $ebay_response->get_error_message() : 'No item data returned';
				TCGiant_Sync_Logger::error( sprintf( 'Images-only sync: Failed to fetch eBay Item %s: %s', $item_id, $msg ) );
				continue;
			}

			$ebay_item = $ebay_response['Item'];

			// Extract main product images.
			$all_images = array();
			if ( isset( $ebay_item['PictureDetails']['PictureURL'] ) ) {
				$pics = $ebay_item['PictureDetails']['PictureURL'];
				$all_images = is_array( $pics ) ? $pics : array( $pics );
			}

			// Extract variation-specific images — map them to WC variation IDs.
			if ( isset( $ebay_item['Variations'] ) ) {
				$product_data = $mapper->map_ebay_to_woo( $ebay_item );
				// We need to call save_as_product to get variation_ids populated,
				// but we don't want to update product data.
				// Instead, match existing variations by SKU.
				if ( ! empty( $product_data['variations'] ) ) {
					foreach ( $product_data['variations'] as $var ) {
						if ( ! empty( $var['image_url'] ) && ! empty( $var['sku'] ) ) {
							$var_wc_id = wc_get_product_id_by_sku( $var['sku'] );
							if ( $var_wc_id ) {
								$all_images[] = array( $var['image_url'], $var_wc_id );
							}
						}
					}
				}
			}

			if ( empty( $all_images ) ) {
				TCGiant_Sync_Logger::log( sprintf( 'Images-only sync: No images found on eBay for Item %s.', $item_id ) );
				continue;
			}

			// Force overwrite by deleting existing images first.
			// The download function will handle the rest.
			as_enqueue_async_action( 'tcgiant_sync_download_images', array(
				'product_id'     => (int) $product_id,
				'image_urls'     => $all_images,
				'is_first_batch' => true,
			), 'tcgiant_sync_images' );

			$queued++;
			TCGiant_Sync_Logger::log( sprintf(
				'Images-only sync: Queued %d image(s) for WC #%d (eBay %s).',
				count( $all_images ), $product_id, $item_id
			) );
		}

		TCGiant_Sync_Logger::log( sprintf( 'Images-only sync: Queued image downloads for %d product(s).', $queued ), 'success' );
	}

	/**
	 * Fetch modified items via GetSellerEvents and process them directly.
	 *
	 * This replaces the GetSellerList + per-item GetItem flow for delta syncs.
	 * GetSellerEvents returns full item data inline, so no individual GetItem calls are needed.
	 * Max time window is 48 hours per eBay API rules.
	 */
	public function fetch_delta_events() {
		$state = self::get_sync_state();
		$mod_from = $state['delta_mod_from'] ?? '';

		if ( empty( $mod_from ) ) {
			TCGiant_Sync_Logger::error( 'Delta events: No delta_mod_from timestamp in state.' );
			self::update_sync_state( array(
				'status'                  => 'complete',
				'last_completed'          => current_time( 'mysql' ),
				'last_successful_sync_at' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			) );
			return;
		}

		$mod_to = gmdate( 'Y-m-d\TH:i:s.000\Z' );

		// GetSellerEvents has a max 48-hour window. Chunk if needed.
		$from_ts = strtotime( $mod_from );
		$to_ts   = strtotime( $mod_to );
		$max_window = 48 * 3600; // 48 hours in seconds

		if ( ( $to_ts - $from_ts ) > $max_window ) {
			// Clamp to 48hr window and log a note. Next delta sync will pick up the rest.
			$mod_to = gmdate( 'Y-m-d\TH:i:s.000\Z', $from_ts + $max_window );
			TCGiant_Sync_Logger::log( sprintf(
				'Delta window exceeds 48hr max. Clamped to %s → %s. Next sync will continue.',
				$mod_from, $mod_to
			), 'warning' );
		}

		$api = TCGiant_Sync_API::instance();
		$response = $api->get_seller_events( $mod_from, $mod_to );

		// Handle rate limiting.
		if ( is_wp_error( $response ) && 'rate_limited' === $response->get_error_code() ) {
			$state_for_retry = self::get_sync_state();
			$retries = (int) ( $state_for_retry['rate_limit_retries'] ?? 0 );
			$retry_delay = $this->get_rate_limit_backoff_delay( $retries );
			self::update_sync_state( array(
				'status'             => 'rate_limited',
				'rate_limit_retries' => $retries + 1,
			) );
			TCGiant_Sync_Logger::log( sprintf(
				'eBay API rate limit hit during delta events fetch (attempt %d). Auto-retry in %d minutes.',
				$retries + 1, $retry_delay / 60
			), 'warning' );
			as_schedule_single_action( time() + $retry_delay, 'tcgiant_sync_fetch_delta_events', array(), 'tcgiant_sync_group' );
			return;
		}

		if ( is_wp_error( $response ) ) {
			TCGiant_Sync_Logger::error( 'Delta events API error: ' . $response->get_error_message() );
			self::update_sync_state( array(
				'status'                  => 'complete',
				'last_completed'          => current_time( 'mysql' ),
				'last_successful_sync_at' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			) );
			return;
		}

		// Parse items from response.
		$items = array();
		if ( isset( $response['ItemArray']['Item'] ) ) {
			$items = $response['ItemArray']['Item'];
			if ( isset( $items['ItemID'] ) ) {
				$items = array( $items ); // Single item — normalize to array.
			}
		}

		if ( empty( $items ) ) {
			TCGiant_Sync_Logger::log( 'Delta sync complete. No items modified since last sync.' );
			self::update_sync_state( array(
				'status'                  => 'complete',
				'last_completed'          => current_time( 'mysql' ),
				'last_successful_sync_at' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			) );
			return;
		}

		// Category filtering (same logic as fetch_listings_page).
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$is_custom_filtering = ! empty( $settings['category_ids'] );
		$standard_cats       = ! empty( $settings['import_standard_category_ids'] ) && is_array( $settings['import_standard_category_ids'] ) ? $settings['import_standard_category_ids'] : array();
		$is_filtering        = $is_custom_filtering || ! empty( $standard_cats );
		
		$valid_custom_ids = $is_custom_filtering ? $this->get_allowed_category_ids() : array();

		$mapper = TCGiant_Sync_Mapper::instance();
		$license = TCGiant_Sync_License::instance();
		$processed = 0;
		$errors = 0;
		$skipped = 0;
		$fallback_count = 0;

		self::update_sync_state( array(
			'status'      => 'importing',
			'total_found' => count( $items ),
		) );

		TCGiant_Sync_Logger::log( sprintf(
			'Delta events: %d modified items found. Processing inline [delta].',
			count( $items )
		) );

		foreach ( $items as $ebay_item ) {
			try {
				// License check.
				if ( ! $license->can_import() ) {
					self::update_sync_state( array( 'status' => 'limit_reached' ) );
					TCGiant_Sync_Logger::log( 'Import limit reached during delta sync. Remaining items skipped.', 'warning' );
					break;
				}

				$item_id = $ebay_item['ItemID'] ?? '';
				if ( empty( $item_id ) ) {
					continue;
				}

				// Category pre-filter.
				if ( $is_filtering ) {
					$primary_cat = $ebay_item['PrimaryCategory']['CategoryID'] ?? '';
					$store_cat1  = $ebay_item['Storefront']['StoreCategoryID'] ?? '';
					$store_cat2  = $ebay_item['Storefront']['StoreCategory2ID'] ?? '';

					$match = false;
					
					// Check against Standard Categories
					if ( ! empty( $standard_cats ) ) {
						if ( in_array( (string) $primary_cat, $standard_cats, true ) ) $match = true;
					}

					// Check against Custom Store Categories
					if ( ! empty( $valid_custom_ids ) ) {
						if ( in_array( (string) $primary_cat, $valid_custom_ids, true ) ) $match = true;
						if ( in_array( (string) $store_cat1, $valid_custom_ids, true ) ) $match = true;
						if ( in_array( (string) $store_cat2, $valid_custom_ids, true ) ) $match = true;
					}

					if ( ! $match ) {
						$skipped++;
						continue;
					}
				}

				// Fallback: if critical data is missing, fetch via GetItem.
				$needs_fallback = false;
				if ( ! isset( $ebay_item['Title'] ) || ! isset( $ebay_item['SellingStatus'] ) ) {
					$needs_fallback = true;
				}
				// If specs or images are missing, we need GetItem to fetch the full data.
				if ( ! isset( $ebay_item['ItemSpecifics'] ) || ! isset( $ebay_item['PictureDetails'] ) ) {
					$needs_fallback = true;
				}
				// Check for variations that should exist but are missing.
				if ( isset( $ebay_item['Variations'] ) && empty( $ebay_item['Variations']['Variation'] ) ) {
					$needs_fallback = true;
				}

				if ( $needs_fallback ) {
					$fallback_count++;
					$api = TCGiant_Sync_API::instance();
					$full_response = $api->get_item( $item_id );
					if ( ! is_wp_error( $full_response ) && isset( $full_response['Item'] ) ) {
						$ebay_item = $full_response['Item'];
						TCGiant_Sync_Logger::log( sprintf( 'Delta fallback: Used GetItem for %s (missing data in events response).', $item_id ) );
					} else {
						$errors++;
						TCGiant_Sync_Logger::error( sprintf( 'Delta fallback failed for item %s. Skipping.', $item_id ) );
						continue;
					}
				}

				// Map and save — same flow as process_item_import but without the GetItem call.
				$title = $ebay_item['Title'] ?? 'Unknown';
				$product_data = $mapper->map_ebay_to_woo( $ebay_item );
				$product_id = $mapper->save_as_product( $product_data );

				if ( $product_id ) {
					// Append variation images to the download queue
					if ( ! empty( $product_data['variations'] ) ) {
						foreach ( $product_data['variations'] as $var ) {
							if ( ! empty( $var['image_url'] ) && ! empty( $var['variation_id'] ) ) {
								$product_data['images'][] = array( $var['image_url'], $var['variation_id'] );
							}
						}
					}

					// URL-hash dedup: skip if images haven't changed since last sync.
					// When overwrite_images is enabled, always re-download.
					$settings_for_img = TCGiant_Sync_OAuth::instance()->get_settings();
					$force_overwrite = ! empty( $settings_for_img['overwrite_images'] );
					if ( ! empty( $product_data['images'] ) && $this->should_download_images( $product_id, $product_data['images'], $force_overwrite ) ) {
						as_enqueue_async_action( 'tcgiant_sync_download_images', array(
							'product_id'     => $product_id,
							'image_urls'     => $product_data['images'],
							'is_first_batch' => true,
						), 'tcgiant_sync_images' );
					}
					$processed++;

					$price_display = ! empty( $product_data['price'] ) ? '$' . $product_data['price'] : 'No price';
					$attr_count = count( $product_data['attributes'] );
					TCGiant_Sync_Logger::log( sprintf(
						'Delta imported: "%s" → WC #%d (%s, Qty: %d, %d attrs)',
						$title, $product_id, $price_display, $product_data['stock_quantity'], $attr_count
					), 'success' );
				} else {
					$errors++;
					TCGiant_Sync_Logger::error( sprintf( 'Delta: Failed to save product for eBay Item: %s (%s)', $item_id, $title ) );
				}

			} catch ( Exception $e ) {
				$errors++;
				TCGiant_Sync_Logger::error( 'Delta import exception: ' . $e->getMessage() );
			} catch ( Error $e ) {
				$errors++;
				TCGiant_Sync_Logger::error( 'Delta import fatal: ' . $e->getMessage() );
			}
		}

		// Log summary.
		$filter_label = $skipped > 0 ? sprintf( ', %d skipped (category filter)', $skipped ) : '';
		$fallback_label = $fallback_count > 0 ? sprintf( ', %d GetItem fallbacks', $fallback_count ) : '';
		TCGiant_Sync_Logger::log( sprintf(
			'Delta sync complete! %d imported, %d errors out of %d modified items%s%s.',
			$processed, $errors, count( $items ), $filter_label, $fallback_label
		), 'success' );

		self::update_sync_state( array(
			'status'                  => 'complete',
			'total_found'             => count( $items ),
			'total_queued'            => $processed + $errors,
			'total_processed'         => $processed,
			'total_errors'            => $errors,
			'last_completed'          => current_time( 'mysql' ),
			'last_successful_sync_at' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
		) );
	}

	/**
	 * Calculate the retry delay for rate-limited operations using exponential backoff.
	 *
	 * Starts at 15 minutes and doubles on each consecutive rate limit hit,
	 * capped at 2 hours. This prevents burning through the daily API budget
	 * when eBay is actively throttling the account.
	 *
	 * @param int $retry_count Number of consecutive rate limit retries so far.
	 * @return int Delay in seconds before the next retry.
	 */
	private function get_rate_limit_backoff_delay( $retry_count ) {
		$base_delay = 900;  // 15 minutes
		$max_delay  = 7200; // 2 hours
		$delay = $base_delay * pow( 2, min( $retry_count, 4 ) );
		return min( $delay, $max_delay );
	}

	/**
	 * Resume a sync that was paused due to API rate limiting.
	 *
	 * Unlike start_full_sync(), this preserves existing totals and continues
	 * from the last scanned page or re-queues pending item imports.
	 */
	public function resume_sync() {
		$state = self::get_sync_state();

		// Only resume from rate_limited or stopped states.
		if ( ! in_array( $state['status'], array( 'rate_limited', 'stopped', 'error' ), true ) ) {
			TCGiant_Sync_Logger::log( 'Cannot resume: sync is not in a paused/limited state.', 'warning' );
			return;
		}

		// License check.
		$license = TCGiant_Sync_License::instance();
		if ( ! $license->can_import() ) {
			self::update_sync_state( array( 'status' => 'limit_reached' ) );
			return;
		}

		// Clear any stale scheduled actions.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_listings', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_group' );
		}

		$resume_page = max( 1, (int) $state['current_page'] );
		$total_pages = (int) $state['total_pages'];

		// Reset backoff counter on manual resume.
		self::update_sync_state( array( 'rate_limit_retries' => 0 ) );

		// If we still have pages to scan, resume scanning.
		if ( $total_pages === 0 || $resume_page <= $total_pages ) {
			self::update_sync_state( array( 'status' => 'scanning' ) );
			TCGiant_Sync_Logger::log( sprintf(
				'Resuming sync from page %d%s. Progress so far: %d found, %d queued, %d imported.',
				$resume_page,
				$total_pages ? '/' . $total_pages : '',
				$state['total_found'],
				$state['total_queued'],
				$state['total_processed']
			), 'success' );
			as_enqueue_async_action( 'tcgiant_sync_fetch_listings', array( 'page_number' => $resume_page ), 'tcgiant_sync_group' );
		} else {
			// All pages were scanned but import phase was interrupted.
			// Transition to importing — any pending item imports will be re-processed.
			if ( $state['total_queued'] > ( $state['total_processed'] + $state['total_errors'] ) ) {
				self::update_sync_state( array( 'status' => 'importing' ) );
				TCGiant_Sync_Logger::log( sprintf(
					'Resuming import phase. %d/%d items still pending.',
					$state['total_queued'] - $state['total_processed'] - $state['total_errors'],
					$state['total_queued']
				), 'success' );
			} else {
				self::update_sync_state( array(
					'status'         => 'complete',
					'last_completed' => current_time( 'mysql' ),
				) );
				TCGiant_Sync_Logger::log( 'Resume check: all items were already processed. Sync complete.', 'success' );
			}
		}
	}

	/**
	 * Fetch a single page of listings via Trading API.
	 *
	 * @param int $page_number Page number to fetch.
	 */
	public function fetch_listings_page( $page_number ) {
		self::update_sync_state( array(
			'status'              => 'scanning',
			'current_page'        => $page_number,
			'rate_limit_retries'  => 0, // Reset backoff on successful page fetch.
		) );

		$api = TCGiant_Sync_API::instance();
		$state_for_mode = self::get_sync_state();
		$mod_time_from = ( 'delta' === ( $state_for_mode['sync_mode'] ?? 'full' ) ) ? ( $state_for_mode['delta_mod_from'] ?? '' ) : '';
		$response = $api->get_active_listings( $page_number, 200, $mod_time_from );

		// Handle rate limiting: pause and schedule retry with escalating backoff.
		if ( is_wp_error( $response ) && 'rate_limited' === $response->get_error_code() ) {
			$state_for_retry = self::get_sync_state();
			$retries = (int) ( $state_for_retry['rate_limit_retries'] ?? 0 );
			$retry_delay = $this->get_rate_limit_backoff_delay( $retries );
			self::update_sync_state( array(
				'status'             => 'rate_limited',
				'current_page'       => $page_number, // preserve the page we failed on
				'rate_limit_retries' => $retries + 1,
			) );
			$api_instance = TCGiant_Sync_API::instance();
			TCGiant_Sync_Logger::log( sprintf(
				'eBay API rate limit hit on page %d (attempt %d, %d API calls today). Pausing scan. Auto-retry in %d minutes. You can also click "Resume Import" later.',
				$page_number,
				$retries + 1,
				$api_instance->get_daily_call_count(),
				$retry_delay / 60
			), 'warning' );
			as_schedule_single_action( time() + $retry_delay, 'tcgiant_sync_fetch_listings', array( 'page_number' => $page_number ), 'tcgiant_sync_group' );
			return;
		}

		if ( is_wp_error( $response ) || ! isset( $response['ItemArray']['Item'] ) ) {
			$state = self::get_sync_state();
			if ( $state['total_queued'] > 0 ) {
				self::update_sync_state( array( 'status' => 'importing' ) );
				TCGiant_Sync_Logger::log( sprintf( 'Scan complete. %d items queued for import.', $state['total_queued'] ) );
			} else {
				self::update_sync_state( array(
					'status'                  => 'complete',
					'last_completed'          => current_time( 'mysql' ),
					'last_successful_sync_at' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				) );
				TCGiant_Sync_Logger::log( 'Scan complete. No matching items found.' );
			}
			return;
		}

		$items = $response['ItemArray']['Item'];
		if ( ! isset( $items[0] ) ) {
			$items = array( $items );
		}

		$total_pages = isset( $response['PaginationResult']['TotalNumberOfPages'] ) ? (int) $response['PaginationResult']['TotalNumberOfPages'] : 1;
		self::update_sync_state( array( 'total_pages' => $total_pages ) );

		$queued_count = 0;
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$is_custom_filtering = ! empty( $settings['category_ids'] );
		$standard_cats       = ! empty( $settings['import_standard_category_ids'] ) && is_array( $settings['import_standard_category_ids'] ) ? $settings['import_standard_category_ids'] : array();
		$is_filtering        = $is_custom_filtering || ! empty( $standard_cats );
		
		$valid_custom_ids = $is_custom_filtering ? $this->get_allowed_category_ids() : array();

		// Debug: log resolved category IDs on the first page so we can verify matching.
		if ( $page_number === 1 && $is_filtering ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Category filter active. Custom IDs: [%s]. Standard IDs: [%s].',
				implode( ', ', $valid_custom_ids ),
				implode( ', ', $standard_cats )
			) );
			// Log the first item's category fields to verify what eBay returns.
			if ( ! empty( $items[0] ) ) {
				$sample = $items[0];
				TCGiant_Sync_Logger::log( sprintf(
					'Sample item "%s": PrimaryCat=%s, StoreCat1=%s, StoreCat2=%s',
					$sample['Title'] ?? '?',
					$sample['PrimaryCategory']['CategoryID'] ?? 'n/a',
					$sample['Storefront']['StoreCategoryID'] ?? 'n/a',
					$sample['Storefront']['StoreCategory2ID'] ?? 'n/a'
				) );
			}
		}

		$active_ids_batch = array();
		$mapper = TCGiant_Sync_Mapper::instance();
		$license = TCGiant_Sync_License::instance();
		$inline_processed = 0;
		$inline_errors = 0;

		foreach ( $items as $item ) {
			$item_id = $item['ItemID'] ?? '';
			
			if ( empty( $item_id ) ) {
				continue;
			}

			// Store for pruning. We record ALL encountered IDs from pagination.
			$active_ids_batch[] = $item_id;

			// Category pre-filter.
			if ( $is_filtering ) {
				$primary_cat = $item['PrimaryCategory']['CategoryID'] ?? '';
				$store_cat1 = $item['Storefront']['StoreCategoryID'] ?? '';
				$store_cat2 = $item['Storefront']['StoreCategory2ID'] ?? '';

				$match = false;
				
				// Check against Standard Categories (Primary Category Only usually, but checking all is fine)
				if ( ! empty( $standard_cats ) ) {
					if ( in_array( (string) $primary_cat, $standard_cats, true ) ) $match = true;
				}

				// Check against Custom Store Categories
				if ( ! empty( $valid_custom_ids ) ) {
					if ( in_array( (string) $primary_cat, $valid_custom_ids, true ) ) $match = true;
					if ( in_array( (string) $store_cat1, $valid_custom_ids, true ) ) $match = true;
					if ( in_array( (string) $store_cat2, $valid_custom_ids, true ) ) $match = true;
				}

				if ( ! $match ) {
					continue;
				}
			}

			// License check per item.
			if ( ! $license->can_import() ) {
				self::update_sync_state( array( 'status' => 'limit_reached' ) );
				TCGiant_Sync_Logger::log( 'Import limit reached. Remaining items skipped.', 'warning' );
				break;
			}

			// Determine if this item needs a GetItem fallback.
			$has_variations = isset( $item['Variations'] );
			$missing_critical = ! isset( $item['Title'] ) || ! isset( $item['SellingStatus'] );

			// Also require ItemSpecifics and PictureDetails for inline processing.
			// GetSellerList sometimes omits these even with DetailLevel=ReturnAll.
			// Without this check, products import with empty attributes and no images.
			$missing_specs_or_images = ! isset( $item['ItemSpecifics'] ) || ! isset( $item['PictureDetails'] );

			// Try inline processing first — even for variation items if the data is complete.
			// GetSellerList with DetailLevel=ReturnAll already returns variation data.
			$can_inline = ! $missing_critical && ! $missing_specs_or_images;
			if ( $has_variations && $can_inline ) {
				// Only inline if variation data is actually present and populated.
				$can_inline = isset( $item['Variations']['Variation'] ) && ! empty( $item['Variations']['Variation'] );
			}

			if ( $can_inline ) {
				// Process inline — item has sufficient data from GetSellerList.
				try {
					$title = $item['Title'] ?? 'Unknown';
					$product_data = $mapper->map_ebay_to_woo( $item );
					$product_id = $mapper->save_as_product( $product_data );

					if ( $product_id ) {
						// Append variation images to the download queue
						if ( ! empty( $product_data['variations'] ) ) {
							foreach ( $product_data['variations'] as $var ) {
								if ( ! empty( $var['image_url'] ) && ! empty( $var['variation_id'] ) ) {
									$product_data['images'][] = array( $var['image_url'], $var['variation_id'] );
								}
							}
						}

						// Schedule image downloads as async to avoid timeout.
						// URL-hash dedup: skip if images haven't changed since last sync.
						// When overwrite_images is enabled, always re-download.
						$settings_for_img = TCGiant_Sync_OAuth::instance()->get_settings();
						$force_overwrite = ! empty( $settings_for_img['overwrite_images'] );
						if ( ! empty( $product_data['images'] ) && $this->should_download_images( $product_id, $product_data['images'], $force_overwrite ) ) {
							as_enqueue_async_action( 'tcgiant_sync_download_images', array(
								'product_id'     => $product_id,
								'image_urls'     => $product_data['images'],
								'is_first_batch' => true,
							), 'tcgiant_sync_images' );
						}

						$inline_processed++;
						$price_display = ! empty( $product_data['price'] ) ? '$' . $product_data['price'] : 'No price';
						$attr_count = count( $product_data['attributes'] );
						$weight_display = ! empty( $product_data['weight'] ) ? ', ' . $product_data['weight'] . get_option( 'woocommerce_weight_unit', 'lbs' ) : '';
						$var_label = $has_variations ? ' [inline+vars]' : ' [inline]';
						TCGiant_Sync_Logger::log( sprintf(
							'Imported: "%s" -> WC #%d (%s, Qty: %d, %d attrs%s)%s',
							$title, $product_id, $price_display, $product_data['stock_quantity'], $attr_count, $weight_display, $var_label
						), 'success' );
					} else {
						$inline_errors++;
						TCGiant_Sync_Logger::error( sprintf( 'Failed to save product for eBay Item: %s [inline]', $item_id ) );
					}
				} catch ( Exception $e ) {
					$inline_errors++;
					TCGiant_Sync_Logger::error( 'Inline import exception for ' . $item_id . ': ' . $e->getMessage() );
				} catch ( Error $e ) {
					$inline_errors++;
					TCGiant_Sync_Logger::error( 'Inline import fatal for ' . $item_id . ': ' . $e->getMessage() );
				}
			} else {
				// Queue for GetItem fallback — critical data is missing from GetSellerList response.
				// Stagger by 1s per item to avoid burst-firing, cap at 60s total stagger.
				$delay = min( $queued_count, 60 );
				as_schedule_single_action( time() + $delay, 'tcgiant_sync_process_item_import', array( 'item_id' => $item_id ), 'tcgiant_sync_group' );
				$queued_count++;
			}
		}

		if ( ! empty( $active_ids_batch ) ) {
			$existing_active = get_option( 'tcgiant_sync_active_ids', array() );
			$existing_active = is_array( $existing_active ) ? $existing_active : array();
			update_option( 'tcgiant_sync_active_ids', array_unique( array_merge( $existing_active, $active_ids_batch ) ) );
		}

		// Update state with running totals (inline items are already processed).
		$state = self::get_sync_state();
		$new_total_found = $state['total_found'] + count( $items );
		$new_total_queued = $state['total_queued'] + $queued_count + $inline_processed + $inline_errors;
		$new_total_processed = $state['total_processed'] + $inline_processed;
		$new_total_errors = $state['total_errors'] + $inline_errors;

		self::update_sync_state( array(
			'total_found'     => $new_total_found,
			'total_queued'    => $new_total_queued,
			'total_processed' => $new_total_processed,
			'total_errors'    => $new_total_errors,
		) );

		// Log page summary (always log first/last page, or when items were processed).
		$has_activity = ( $queued_count > 0 || $inline_processed > 0 );
		$should_log = ( $page_number === 1 || $page_number === $total_pages || $page_number % 5 === 0 || $has_activity );
		if ( $should_log ) {
			$filter_label = $is_filtering ? sprintf( ' [filter: %s]', $settings['category_ids'] ) : '';
			$matched_label = ( $inline_processed + $queued_count ) > 0
				? sprintf( ', %d matched', $inline_processed + $queued_count )
				: ', 0 matched';
			$inline_label = $inline_processed > 0 ? sprintf( ', %d imported inline', $inline_processed ) : '';
			$queued_label = $queued_count > 0 ? sprintf( ', %d queued for GetItem (variations)', $queued_count ) : '';
			TCGiant_Sync_Logger::log( sprintf(
				'Page %d/%d: Scanned %d items%s%s%s%s.',
				$page_number, $total_pages, count( $items ), $matched_label, $inline_label, $queued_label, $filter_label
			) );
		}

		// Schedule next page with adaptive delay:
		// - Base: 5s for small stores, 10s for large (10+ pages).
		// - If GetItem fallbacks were queued, add a small buffer (capped at 30s).
		//   Fallbacks run in the background; we don't need to wait for all of them.
		if ( $page_number < $total_pages ) {
			$base_delay = $total_pages >= 10 ? 10 : 5;
			$fallback_buffer = $queued_count > 0 ? min( $queued_count, 20 ) : 0;
			$delay_next_page = $base_delay + $fallback_buffer;
			as_schedule_single_action( time() + $delay_next_page, 'tcgiant_sync_fetch_listings', array( 'page_number' => $page_number + 1 ), 'tcgiant_sync_group' );
		} else {
			if ( $new_total_queued > 0 ) {
				self::update_sync_state( array( 'status' => 'importing' ) );
				TCGiant_Sync_Logger::log( sprintf( 'Scan complete. %d total items queued for import across %d pages.', $new_total_queued, $total_pages ) );
			} else {
				self::update_sync_state( array(
					'status'                  => 'complete',
					'last_completed'          => current_time( 'mysql' ),
					'last_successful_sync_at' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				) );
				TCGiant_Sync_Logger::log( 'Scan complete. No matching items found.' );
				
				// Ensure pruning runs even if 0 items matched the filter, because items might have ended.
				as_enqueue_async_action( 'tcgiant_sync_prune_orphans', array(), 'tcgiant_sync_group' );
			}
		}
	}

	/**
	 * Resolves User Category String Settings into eBay Category IDs.
	 */
	private function get_allowed_category_ids() {
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$raw_category_setting = $settings['category_ids'] ?? '';
		
		$transient_key = 'tcgiant_sync_cats_' . md5( $raw_category_setting );
		$valid_ids = get_transient( $transient_key );
		if ( false !== $valid_ids ) {
			return $valid_ids;
		}

		$allowed_strings = ! empty( $raw_category_setting ) ? explode( ',', $raw_category_setting ) : array();
		
		if ( empty( $allowed_strings ) ) {
			return array();
		}

		$allowed_strings = array_map( 'strtolower', array_map( 'trim', $allowed_strings ) );
		$valid_ids = array();

		// Hard global numeric IDs.
		foreach ( $allowed_strings as $str ) {
			if ( is_numeric( $str ) ) {
				$valid_ids[] = (string) $str;
			}
		}

		// Download Store Category Tree.
		$api = TCGiant_Sync_API::instance();
		$store_response = $api->get_store();
		if ( ! is_wp_error( $store_response ) && isset( $store_response['Store']['CustomCategories']['CustomCategory'] ) ) {
			$categories = $store_response['Store']['CustomCategories']['CustomCategory'];
			if ( isset( $categories['CategoryID'] ) ) {
				$categories = array( $categories );
			}
			
			$flatten = function( $cats ) use ( &$flatten, &$valid_ids, $allowed_strings ) {
				if ( ! is_array( $cats ) ) return;
				foreach ( $cats as $cat ) {
					$name = strtolower( trim( $cat['Name'] ?? '' ) );
					$id = $cat['CategoryID'] ?? '';
					if ( $name && $id && in_array( $name, $allowed_strings, true ) ) {
						$valid_ids[] = (string) $id;
					}
					if ( isset( $cat['ChildCategory'] ) ) {
						$children = isset( $cat['ChildCategory']['CategoryID'] ) ? array( $cat['ChildCategory'] ) : $cat['ChildCategory'];
						$flatten( $children );
					}
				}
			};
			$flatten( $categories );
		}

		$valid_ids = array_unique( $valid_ids );
		set_transient( $transient_key, $valid_ids, 3600 );
		return $valid_ids;
	}

	/**
	 * Sync recent orders and reduce stock.
	 */
	public function sync_recent_orders() {
		$api = TCGiant_Sync_API::instance();
		$response = $api->get_orders();

		if ( is_wp_error( $response ) ) {
			TCGiant_Sync_Logger::error( 'Order Sync failed: ' . $response->get_error_message() );
			return;
		}

		if ( empty( $response['orders'] ) ) {
			TCGiant_Sync_Logger::log( 'No recent orders found on eBay.' );
			return;
		}

		$order_count = 0;
		foreach ( $response['orders'] as $order ) {
			if ( empty( $order['lineItems'] ) ) {
				continue;
			}
			
			foreach ( $order['lineItems'] as $line ) {
				$sku = $line['sku'] ?? '';
				$legacy_item_id = $line['legacyItemId'] ?? '';

				if ( empty( $sku ) && ! empty( $legacy_item_id ) ) {
					$sku = 'EBAY-' . $legacy_item_id;
				}

				$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
				$line_item_id = $line['lineItemId'] ?? '';

				if ( empty( $sku ) || empty( $line_item_id ) ) {
					continue;
				}

				$product_id = wc_get_product_id_by_sku( $sku );

				if ( ! $product_id && ! empty( $legacy_item_id ) ) {
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$fallback_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ebay_item_id' AND meta_value = %s LIMIT 1", $legacy_item_id ) );
					if ( $fallback_id ) {
						$product_id = (int) $fallback_id;
					}
				}

				if ( ! $product_id ) {
					continue;
				}

				$processed = get_post_meta( $product_id, '_ebay_order_processed_' . $line_item_id, true );
				if ( $processed ) {
					continue;
				}

				$stock_reduced = wc_update_product_stock( $product_id, $quantity, 'decrease' );
				
				if ( ! is_wp_error( $stock_reduced ) ) {
					update_post_meta( $product_id, '_ebay_order_processed_' . $line_item_id, current_time( 'mysql' ) );
					TCGiant_Sync_Logger::log( sprintf( 'Reduced stock for WC Product %d by %d (eBay order).', $product_id, $quantity ), 'success' );
					$order_count++;
				}
			}
		}

		TCGiant_Sync_Logger::log( sprintf( 'Order sync complete. Processed %d new line items.', $order_count ) );
	}

	/**
	 * Process a single eBay Item ID.
	 *
	 * @param string $item_id The eBay Item ID to import.
	 */
	public function process_item_import( $item_id ) {
		try {
			// Per-item license check.
			$license = TCGiant_Sync_License::instance();
			if ( ! $license->can_import() ) {
				self::update_sync_state( array( 'status' => 'limit_reached' ) );
				TCGiant_Sync_Logger::log(
					sprintf(
						'Import limit reached (%d/%d products). Remaining queued items skipped. Upgrade to Pro for unlimited.',
						$license->get_active_product_count(),
						TCGiant_Sync_License::FREE_LIMIT
					),
					'warning'
				);
				// Cancel remaining queued jobs to avoid wasting API calls.
				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_group' );
				}
				return;
			}

			$api = TCGiant_Sync_API::instance();
			$ebay_response = $api->get_item( $item_id );

			// Handle rate limiting: reschedule this item for later with escalating backoff.
			if ( is_wp_error( $ebay_response ) && 'rate_limited' === $ebay_response->get_error_code() ) {
				$state_for_retry = self::get_sync_state();
				$retries = (int) ( $state_for_retry['rate_limit_retries'] ?? 0 );
				$retry_delay = $this->get_rate_limit_backoff_delay( $retries );
				self::update_sync_state( array(
					'status'             => 'rate_limited',
					'rate_limit_retries' => $retries + 1,
				) );
				TCGiant_Sync_Logger::log( sprintf(
					'eBay API rate limit hit while importing item %s (attempt %d). Rescheduled for %d minutes later.',
					$item_id,
					$retries + 1,
					$retry_delay / 60
				), 'warning' );
				as_schedule_single_action( time() + $retry_delay, 'tcgiant_sync_process_item_import', array( 'item_id' => $item_id ), 'tcgiant_sync_group' );
				return;
			}

			if ( is_wp_error( $ebay_response ) || ! isset( $ebay_response['Item'] ) ) {
				self::update_sync_state( array(
					'total_errors' => self::get_sync_state()['total_errors'] + 1,
				) );
				TCGiant_Sync_Logger::error( 'Import failed for eBay Item ID: ' . $item_id );
				$this->check_sync_completion();
				return;
			}

			$ebay_item = $ebay_response['Item'];
			$title = $ebay_item['Title'] ?? 'Unknown';

			$mapper = TCGiant_Sync_Mapper::instance();
			$product_data = $mapper->map_ebay_to_woo( $ebay_item );
			
			$product_id = $mapper->save_as_product( $product_data );

			if ( $product_id ) {
				// Append variation images to the download queue
				if ( ! empty( $product_data['variations'] ) ) {
					foreach ( $product_data['variations'] as $var ) {
						if ( ! empty( $var['image_url'] ) && ! empty( $var['variation_id'] ) ) {
							$product_data['images'][] = array( $var['image_url'], $var['variation_id'] );
						}
					}
				}

				// Schedule image downloads as async to avoid blocking the Action Scheduler queue.
				// URL-hash dedup: skip if images haven't changed since last sync.
				// When overwrite_images is enabled, always re-download.
				$settings_for_img = TCGiant_Sync_OAuth::instance()->get_settings();
				$force_overwrite = ! empty( $settings_for_img['overwrite_images'] );
				if ( ! empty( $product_data['images'] ) && $this->should_download_images( $product_id, $product_data['images'], $force_overwrite ) ) {
					as_enqueue_async_action( 'tcgiant_sync_download_images', array(
						'product_id'     => $product_id,
						'image_urls'     => $product_data['images'],
						'is_first_batch' => true,
					), 'tcgiant_sync_images' );
				}

				$state = self::get_sync_state();
				self::update_sync_state( array(
					'total_processed' => $state['total_processed'] + 1,
					'last_item_title' => $title,
				) );

				$price_display = ! empty( $product_data['price'] ) ? '$' . $product_data['price'] : 'No price';
				$attr_count = count( $product_data['attributes'] );
				$weight_display = ! empty( $product_data['weight'] ) ? ', ' . $product_data['weight'] . get_option( 'woocommerce_weight_unit', 'lbs' ) : '';
				TCGiant_Sync_Logger::log( sprintf(
					'Imported: "%s" -> WC #%d (%s, Qty: %d, %d attrs%s)',
					$title, $product_id, $price_display, $product_data['stock_quantity'], $attr_count, $weight_display
				), 'success' );
			} else {
				self::update_sync_state( array(
					'total_errors' => self::get_sync_state()['total_errors'] + 1,
				) );
				TCGiant_Sync_Logger::error( sprintf( 'Failed to save product for eBay Item: %s (%s)', $item_id, $title ) );
			}

			$this->check_sync_completion();
			
		} catch ( Exception $e ) {
			self::update_sync_state( array(
				'total_errors' => self::get_sync_state()['total_errors'] + 1,
			) );
			TCGiant_Sync_Logger::error( 'Exception importing item ' . $item_id . ': ' . $e->getMessage() );
			$this->check_sync_completion();
		} catch ( Error $e ) {
			self::update_sync_state( array(
				'total_errors' => self::get_sync_state()['total_errors'] + 1,
			) );
			TCGiant_Sync_Logger::error( 'Fatal error importing item ' . $item_id . ': ' . $e->getMessage() );
			$this->check_sync_completion();
		}
	}

	/**
	 * Check if the sync is complete (all items processed or no jobs remain).
	 */
	private function check_sync_completion() {
		$state = self::get_sync_state();
		if ( 'importing' !== $state['status'] ) {
			return;
		}

		$completed = $state['total_processed'] + $state['total_errors'];

		// Primary check: all items accounted for.
		$is_done = ( $completed >= $state['total_queued'] && $state['total_queued'] > 0 );

		// Fallback: if Action Scheduler has no remaining jobs, we're done regardless of counters.
		if ( ! $is_done && function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions( array(
				'hook'   => 'tcgiant_sync_process_item_import',
				'group'  => 'tcgiant_sync_group',
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			) );
			if ( empty( $pending ) ) {
				$is_done = true;
			}
		}

		if ( $is_done ) {
			$mode_label = ( 'delta' === ( $state['sync_mode'] ?? 'full' ) ) ? 'Delta sync' : 'Sync';
			self::update_sync_state( array(
				'status'                  => 'complete',
				'last_completed'          => current_time( 'mysql' ),
				'last_successful_sync_at' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				'rate_limit_retries'      => 0,
			) );
			TCGiant_Sync_Logger::log( sprintf(
				'%s complete! %d imported, %d errors out of %d total.',
				$mode_label, $state['total_processed'], $state['total_errors'], $state['total_queued']
			), 'success' );

			// Trigger cleanup of sold/ended items.
			as_enqueue_async_action( 'tcgiant_sync_prune_orphans', array(), 'tcgiant_sync_group' );
		}
	}

	/**
	 * Remove WooCommerce products that are no longer active on eBay.
	 */
	public function prune_orphaned_items() {
		$active_ids = get_option( 'tcgiant_sync_active_ids', array() );
		if ( ! is_array( $active_ids ) ) {
			return;
		}

		global $wpdb;
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$preserve_cats = isset( $settings['preserve_woo_category_ids'] ) && is_array( $settings['preserve_woo_category_ids'] ) ? $settings['preserve_woo_category_ids'] : array();

		// Get all products that have an _ebay_item_id.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ebay_linked_products = $wpdb->get_results(
			"SELECT post_id, meta_value as ebay_id 
			FROM {$wpdb->postmeta} pm
			JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
			WHERE pm.meta_key = '_ebay_item_id' AND pm.meta_value != '' AND p.post_type = 'product' AND p.post_status != 'trash'"
		);

		$trashed_count = 0;
		$preserved_count = 0;

		foreach ( $ebay_linked_products as $row ) {
			$ebay_id = $row->ebay_id;
			$product_id = $row->post_id;
			$should_prune = false;

			if ( ! in_array( $ebay_id, $active_ids, true ) && ! empty( $active_ids ) ) {
				// The item is no longer on eBay.
				$should_prune = true;
			} elseif ( empty( $active_ids ) ) {
				// Safety check: if active_ids is entirely empty, maybe the scan failed or they have 0 items.
				// Wait, if they truly have 0 items on eBay, active_ids IS empty.
				// But to be completely safe, we shouldn't delete their entire store if active_ids is empty due to a bug.
				// We'll verify directly with the API as a fallback.
				$api = TCGiant_Sync_API::instance();
				$ebay_response = $api->get_item( $ebay_id );
				if ( ! is_wp_error( $ebay_response ) && isset( $ebay_response['Item']['SellingStatus']['ListingStatus'] ) ) {
					$status = $ebay_response['Item']['SellingStatus']['ListingStatus'];
					if ( 'Active' !== $status ) {
						$should_prune = true;
					}
				}
			}

			if ( $should_prune ) {
				$is_preserved = false;
				if ( ! empty( $preserve_cats ) && has_term( $preserve_cats, 'product_cat', $product_id ) ) {
					$is_preserved = true;
				}

				if ( $is_preserved ) {
					wc_update_product_stock( $product_id, 0, 'set' );
					wc_update_product_stock_status( $product_id, 'outofstock' );
					delete_post_meta( $product_id, '_ebay_item_id' );
					$preserved_count++;
				} else {
					wp_trash_post( $product_id );
					$trashed_count++;
				}
			}
		}

		if ( $trashed_count > 0 || $preserved_count > 0 ) {
			TCGiant_Sync_Logger::log( sprintf( 'Inventory Pruning: Trashed %d products, preserved %d products (set to out of stock).', $trashed_count, $preserved_count ), 'success' );
		} else {
			TCGiant_Sync_Logger::log( 'Inventory Pruning: No orphaned products found.' );
		}

		delete_option( 'tcgiant_sync_active_ids' );
	}

	/**
	 * Check if image downloads are needed by comparing URL hashes.
	 *
	 * Computes an MD5 hash of the incoming image URL list and compares it
	 * against the stored hash from the last successful download. If the URLs
	 * haven't changed and the product already has a featured image, the
	 * download is skipped entirely — saving bandwidth and Action Scheduler
	 * capacity on large stores (5,000+ items).
	 *
	 * @param int   $product_id WooCommerce Product ID.
	 * @param array $image_urls List of image URLs (strings or [url, variation_id] arrays).
	 * @param bool  $force_overwrite If true, always download images (overwrite_images is enabled).
	 * @return bool True if images should be downloaded, false if unchanged.
	 */
	private function should_download_images( $product_id, $image_urls, $force_overwrite = false ) {
		// Extract plain URLs from the mixed array for hashing.
		$plain_urls = array();
		foreach ( $image_urls as $entry ) {
			if ( is_array( $entry ) ) {
				$plain_urls[] = $entry[0] ?? '';
			} else {
				$plain_urls[] = $entry;
			}
		}
		$plain_urls = array_filter( $plain_urls );
		if ( empty( $plain_urls ) ) {
			return false;
		}

		sort( $plain_urls );
		$new_hash = md5( implode( '|', $plain_urls ) );

		// When overwrite is enabled, always re-download regardless of hash match.
		// Store the hash so future non-overwrite syncs can still benefit from dedup.
		if ( $force_overwrite ) {
			update_post_meta( $product_id, '_tcgiant_image_urls_hash', $new_hash );
			return true;
		}

		$stored_hash = get_post_meta( $product_id, '_tcgiant_image_urls_hash', true );

		// If hash matches and product already has a featured image, skip download.
		if ( $new_hash === $stored_hash && has_post_thumbnail( $product_id ) ) {
			return false;
		}

		// Store the new hash for future comparisons.
		update_post_meta( $product_id, '_tcgiant_image_urls_hash', $new_hash );
		return true;
	}

	/**
	 * Check if an image download failure is permanent (should not be retried).
	 *
	 * Permanent failures include HTTP 404/403/410 (expired or deleted eBay images)
	 * and empty file responses. Transient failures (timeouts, 5xx, DNS) are worth
	 * retrying after a delay.
	 *
	 * @param WP_Error $wp_error The error returned by media_sideload_image.
	 * @return bool True if the failure is permanent and should not be retried.
	 */
	private function is_permanent_image_failure( $wp_error ) {
		$message = strtolower( $wp_error->get_error_message() );
		$code    = $wp_error->get_error_code();

		// HTTP status code patterns indicating permanent failures.
		$permanent_patterns = array(
			'404',
			'403',
			'410',
			'not found',
			'forbidden',
			'gone',
			'file is empty',
			'invalid url',
			'invalid image',
			'not an accepted',
			'could not calculate',
		);

		foreach ( $permanent_patterns as $pattern ) {
			if ( strpos( $message, $pattern ) !== false ) {
				return true;
			}
		}

		// Also check error code for 'http_request_failed' with permanent status.
		if ( 'http_request_failed' === $code ) {
			// Check for status code in message.
			if ( preg_match( '/\b(404|403|410)\b/', $message ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find an existing WordPress attachment by its eBay source URL.
	 *
	 * Each sideloaded image stores its original eBay URL in `_tcgiant_source_url`
	 * attachment meta. This allows us to skip re-downloading images that are
	 * already attached to the product.
	 *
	 * @param int    $product_id The WooCommerce product ID.
	 * @param string $image_url  The eBay image URL to search for.
	 * @return int|false Attachment ID if found, false otherwise.
	 */
	private function find_existing_attachment_by_url( $product_id, $image_url ) {
		// Normalize the URL for comparison (strip query strings from eBay CDN URLs).
		$normalized = preg_replace( '/\?.*$/', '', $image_url );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attachment_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id
			FROM {$wpdb->postmeta} pm
			JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_tcgiant_source_url'
			AND pm.meta_value = %s
			AND p.post_parent = %d
			AND p.post_type = 'attachment'
			LIMIT 1",
			$normalized,
			$product_id
		) );

		return $attachment_id ? (int) $attachment_id : false;
	}

	/**
	 * Download and attach images to product (Chunked).
	 *
	 * Images are processed in small batches (2 at a time) to prevent PHP
	 * execution timeouts. Failed images get a single automatic retry
	 * (transient failures only — permanent 404/403 failures are not retried).
	 *
	 * Large store optimizations (v1.7.3):
	 * - Per-image source URL dedup: skips images already attached to the product.
	 * - Smart failure classification: only retries transient network failures.
	 * - Smart clearing: only removes attachments whose source URL is no longer in
	 *   the incoming set (instead of deleting all existing images).
	 *
	 * @param int   $product_id WooCommerce Product ID.
	 * @param array $images     List of eBay image URLs (strings or [url, variation_id] arrays).
	 * @param bool  $is_first_batch True if this is the first batch of images for the product.
	 */
	public function download_product_images( $product_id, $images, $is_first_batch = true ) {
		if ( empty( $images ) || ! is_array( $images ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$overwrite_images = ! empty( $settings['overwrite_images'] );

		// Process up to 5 images per batch to prevent PHP timeouts.
		// Bumped from 2 to reduce Action Scheduler job count and gallery state fragmentation.
		$batch_size = 5;
		$current_batch = array_slice( $images, 0, $batch_size );
		$remaining_images = array_slice( $images, $batch_size );

		TCGiant_Sync_Logger::log( sprintf(
			'Images: WC #%d — downloading batch of %d (%d remaining).',
			$product_id, count( $current_batch ), count( $remaining_images )
		) );

		// Track image sync status on the product for diagnostics.
		if ( $is_first_batch ) {
			// Count only non-variation (main product) images.
			$main_count = 0;
			$var_count = 0;
			foreach ( $images as $img ) {
				if ( is_array( $img ) ) {
					$var_count++;
				} else {
					$main_count++;
				}
			}
			update_post_meta( $product_id, '_tcgiant_image_status', array(
				'expected_main'      => $main_count,
				'expected_variation' => $var_count,
				'downloaded'         => 0,
				'skipped_existing'   => 0,
				'failed'             => 0,
				'failed_permanent'   => 0,
				'started_at'         => current_time( 'mysql' ),
				'completed_at'       => '',
			) );
		}

		// Lower the HTTP timeout for image downloads to 30s per file.
		// WordPress default is 300s which is too generous and can stall the entire queue.
		$timeout_filter = function( $timeout ) {
			return 30;
		};
		add_filter( 'http_request_timeout', $timeout_filter );

		// Temporarily bump memory limit for image processing (thumbnail generation is memory hungry).
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		// Handle clearing existing images only on the very first batch.
		if ( $is_first_batch && has_post_thumbnail( $product_id ) ) {
			if ( ! $overwrite_images ) {
				// We don't overwrite images, so abort entirely (no need to schedule next batch).
				remove_filter( 'http_request_timeout', $timeout_filter );
				update_post_meta( $product_id, '_tcgiant_image_status', array(
					'status'       => 'skipped',
					'reason'       => 'overwrite_images is off and product already has images',
					'completed_at' => current_time( 'mysql' ),
				) );
				return;
			}

			// Smart clearing: only delete attachments whose source URL is NOT in
			// the incoming image set. This prevents re-downloading unchanged images.
			$incoming_urls = array();
			foreach ( $images as $entry ) {
				$url = is_array( $entry ) ? ( $entry[0] ?? '' ) : $entry;
				if ( $url ) {
					$incoming_urls[] = preg_replace( '/\?.*$/', '', $url );
				}
			}

			// Delete thumbnail if its source URL is no longer in the incoming set.
			$thumb_id = get_post_thumbnail_id( $product_id );
			if ( $thumb_id ) {
				$thumb_source = get_post_meta( $thumb_id, '_tcgiant_source_url', true );
				if ( empty( $thumb_source ) || ! in_array( $thumb_source, $incoming_urls, true ) ) {
					wp_delete_attachment( $thumb_id, true );
					delete_post_thumbnail( $product_id );
				}
			}

			// Delete gallery images whose source URL is no longer in the incoming set.
			$gallery = get_post_meta( $product_id, '_product_image_gallery', true );
			if ( ! empty( $gallery ) ) {
				$gallery_ids_existing = explode( ',', $gallery );
				$keep_ids = array();
				foreach ( $gallery_ids_existing as $g_id ) {
					$g_source = get_post_meta( (int) $g_id, '_tcgiant_source_url', true );
					if ( ! empty( $g_source ) && in_array( $g_source, $incoming_urls, true ) ) {
						$keep_ids[] = $g_id;
					} else {
						wp_delete_attachment( (int) $g_id, true );
					}
				}
				if ( ! empty( $keep_ids ) ) {
					update_post_meta( $product_id, '_product_image_gallery', implode( ',', $keep_ids ) );
				} else {
					delete_post_meta( $product_id, '_product_image_gallery' );
				}
			}
		}

		$gallery_ids = array();

		// After smart clearing, initialize gallery_ids from the retained gallery
		// so that preserved images aren't overwritten when the first batch saves.
		if ( $is_first_batch && $overwrite_images ) {
			$retained_gallery = get_post_meta( $product_id, '_product_image_gallery', true );
			if ( ! empty( $retained_gallery ) ) {
				$gallery_ids = array_filter( explode( ',', $retained_gallery ) );
			}
		}
		
		// If it's not the first batch, we might need to append to an existing gallery.
		if ( ! $is_first_batch ) {
			$existing = get_post_meta( $product_id, '_product_image_gallery', true );
			if ( ! empty( $existing ) ) {
				$gallery_ids = explode( ',', $existing );
			}
		}

		// Keep track if we need to set the featured image (only if the product doesn't have one).
		$needs_thumbnail = ! has_post_thumbnail( $product_id );
		$failed_images = array();
		$permanent_failures = 0;
		$downloaded_count = 0;
		$skipped_existing = 0;

		foreach ( $current_batch as $image_entry ) {
			// variation images are passed as an array [url, variation_id].
			$variation_id = false;
			$image_url = $image_entry;
			if ( is_array( $image_entry ) ) {
				// Filter out the __retry flag when reading entries.
				$variation_id = $image_entry[1] ?? false;
				$image_url    = $image_entry[0] ?? '';
				if ( '__retry' === $variation_id ) {
					$variation_id = false;
				}
			}

			if ( empty( $image_url ) ) continue;

			// Per-image source URL dedup: check if this exact image is already attached.
			$target_id = $variation_id ? $variation_id : $product_id;
			$existing_attachment = $this->find_existing_attachment_by_url( $product_id, $image_url );

			if ( $existing_attachment ) {
				// Image already downloaded — reuse it.
				if ( $variation_id ) {
					if ( ! has_post_thumbnail( $variation_id ) ) {
						set_post_thumbnail( $variation_id, $existing_attachment );
					}
				} elseif ( $needs_thumbnail ) {
					set_post_thumbnail( $product_id, $existing_attachment );
					$needs_thumbnail = false;
				}
				// No need to add to gallery — it's already there from the previous sync.
				$skipped_existing++;
				continue;
			}

			// Assigning image to a variation.
			if ( $variation_id ) {
				// Check if variation already has thumbnail if we aren't overwriting.
				if ( has_post_thumbnail( $variation_id ) ) {
					if ( ! $overwrite_images && $is_first_batch ) {
						continue; // skip
					}
					// Only delete variation thumbnail on the very first batch that includes this variation's photos.
					if ( $is_first_batch ) {
						$var_thumb_id = get_post_thumbnail_id( $variation_id );
						if ( $var_thumb_id ) {
							wp_delete_attachment( $var_thumb_id, true );
							delete_post_thumbnail( $variation_id );
						}
					} elseif ( ! $overwrite_images ) {
						// don't overwrite existing
						continue;
					}
				}

				$id = media_sideload_image( $image_url, $product_id, null, 'id' );
				if ( is_wp_error( $id ) ) {
					if ( $this->is_permanent_image_failure( $id ) ) {
						TCGiant_Sync_Logger::warning( sprintf(
							'[PERMANENT] Variation image failed for WC #%d (var #%d): %s — URL: %s — will not retry.',
							$product_id, $variation_id, $id->get_error_message(), $image_url
						) );
						$permanent_failures++;
					} else {
						TCGiant_Sync_Logger::warning( sprintf(
							'Variation image download failed for WC #%d (var #%d): %s — URL: %s',
							$product_id, $variation_id, $id->get_error_message(), $image_url
						) );
						$failed_images[] = $image_entry; // keep the original [url, var_id] pair
					}
					continue;
				}
				// Store source URL on the attachment for future dedup.
				update_post_meta( $id, '_tcgiant_source_url', preg_replace( '/\?.*$/', '', $image_url ) );
				set_post_thumbnail( $variation_id, $id );
				$downloaded_count++;
				
			} else {
				// Assigning image to the main product.
				$id = media_sideload_image( $image_url, $product_id, null, 'id' );
				if ( is_wp_error( $id ) ) {
					if ( $this->is_permanent_image_failure( $id ) ) {
						TCGiant_Sync_Logger::warning( sprintf(
							'[PERMANENT] Image failed for WC #%d: %s — URL: %s — will not retry.',
							$product_id, $id->get_error_message(), $image_url
						) );
						$permanent_failures++;
					} else {
						TCGiant_Sync_Logger::warning( sprintf(
							'Image download failed for WC #%d: %s — URL: %s',
							$product_id, $id->get_error_message(), $image_url
						) );
						$failed_images[] = $image_entry;
					}
					continue;
				}

				// Store source URL on the attachment for future dedup.
				update_post_meta( $id, '_tcgiant_source_url', preg_replace( '/\?.*$/', '', $image_url ) );

				if ( $needs_thumbnail ) {
					set_post_thumbnail( $product_id, $id );
					$needs_thumbnail = false;
				} else {
					$gallery_ids[] = $id;
				}
				$downloaded_count++;
			}
		}

		if ( ! empty( $gallery_ids ) ) {
			// Ensure unique IDs in case of any weird overlap.
			$gallery_ids = array_unique( array_filter( $gallery_ids ) );
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
		}

		// Remove the timeout filter.
		remove_filter( 'http_request_timeout', $timeout_filter );

		// Update running download count in status meta.
		$img_status = get_post_meta( $product_id, '_tcgiant_image_status', true );
		if ( is_array( $img_status ) ) {
			$img_status['downloaded'] = ( $img_status['downloaded'] ?? 0 ) + $downloaded_count;
			$img_status['skipped_existing'] = ( $img_status['skipped_existing'] ?? 0 ) + $skipped_existing;
			$img_status['failed'] = ( $img_status['failed'] ?? 0 ) + count( $failed_images );
			$img_status['failed_permanent'] = ( $img_status['failed_permanent'] ?? 0 ) + $permanent_failures;
			update_post_meta( $product_id, '_tcgiant_image_status', $img_status );
		}

		// Schedule retry for transient failures only (one attempt, after 60s delay).
		// Permanent failures (404/403/410) are NOT retried.
		if ( ! empty( $failed_images ) ) {
			// Check if these are already retries (marked with a __retry flag).
			$retryable = array();
			foreach ( $failed_images as $entry ) {
				$is_retry = false;
				if ( is_array( $entry ) && isset( $entry['__retry'] ) ) {
					$is_retry = true;
				}
				if ( ! $is_retry ) {
					// Mark as retry so we don't retry infinitely.
					if ( is_array( $entry ) ) {
						$entry['__retry'] = true;
					} else {
						$entry = array( $entry, false, '__retry' => true );
					}
					$retryable[] = $entry;
				}
			}

			if ( ! empty( $retryable ) ) {
				TCGiant_Sync_Logger::log( sprintf(
					'Images: WC #%d — %d transient failure(s). Scheduling 1 retry in 60s.',
					$product_id, count( $retryable )
				), 'warning' );
				as_schedule_single_action( time() + 60, 'tcgiant_sync_download_images', array(
					'product_id'     => $product_id,
					'image_urls'     => $retryable,
					'is_first_batch' => false,
				), 'tcgiant_sync_images' );
			}
		}

		// If there are more images to process, schedule the next batch.
		if ( ! empty( $remaining_images ) ) {
			as_enqueue_async_action( 'tcgiant_sync_download_images', array(
				'product_id'     => $product_id,
				'image_urls'     => $remaining_images,
				'is_first_batch' => false,
			), 'tcgiant_sync_images' );
		} elseif ( empty( $failed_images ) ) {
			// Mark completion in status meta.
			$img_status = get_post_meta( $product_id, '_tcgiant_image_status', true );
			if ( is_array( $img_status ) ) {
				$img_status['completed_at'] = current_time( 'mysql' );
				$total_failures = ( $img_status['failed'] ?? 0 ) + ( $img_status['failed_permanent'] ?? 0 );
				$img_status['status'] = $total_failures > 0 ? 'partial' : 'complete';
				update_post_meta( $product_id, '_tcgiant_image_status', $img_status );
			}

			$skipped_total = ( $img_status['skipped_existing'] ?? 0 );
			$skip_label = $skipped_total > 0 ? sprintf( ' (%d reused)', $skipped_total ) : '';
			TCGiant_Sync_Logger::log( sprintf(
				'Images: WC #%d — all images processed successfully%s.',
				$product_id, $skip_label
			), 'success' );
		}
	}
}
