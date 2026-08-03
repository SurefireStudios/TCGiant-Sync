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
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * Sync state option key.
	 */
	const STATE_OPTION = 'tcgiant_sync_state';

	/**
	 * Action Scheduler group names.
	 *
	 * The three queues run in parallel so that page scanning is never stuck
	 * behind item imports or image downloads. Cancelling or querying an action
	 * requires the RIGHT group — passing the wrong one silently matches nothing,
	 * which is how "Emergency Stop" came to cancel neither imports nor images.
	 * Use these constants rather than repeating the literals.
	 */
	const GROUP_SCAN    = 'tcgiant_sync_group';
	const GROUP_IMPORTS = 'tcgiant_sync_imports';
	const GROUP_IMAGES  = 'tcgiant_sync_images';

	/**
	 * Option holding the eBay Item IDs seen during the current scan.
	 */
	const ACTIVE_IDS_OPTION = 'tcgiant_sync_active_ids';

	/**
	 * Append eBay Item IDs to the running active-ID list for this scan.
	 *
	 * Written with autoload = false. WordPress decides autoload when an option
	 * is first created, and update_option() never revisits that decision — so
	 * because page 1 creates a small option, a 10,000-item scan used to leave a
	 * ~300 KB serialized array being loaded into memory on every front-end and
	 * admin request until the scan finished and the pruner deleted it.
	 *
	 * @param array $ids eBay Item IDs seen on this page.
	 * @return void
	 */
	private static function append_active_ids( array $ids ) {
		if ( empty( $ids ) ) {
			return;
		}

		$existing = get_option( self::ACTIVE_IDS_OPTION, array() );
		$existing = is_array( $existing ) ? $existing : array();

		$merged = array_values( array_unique( array_merge( $existing, $ids ) ) );

		// Delete-then-add is the only reliable way to change an existing
		// option's autoload flag on WordPress versions before 6.4.
		if ( ! empty( $existing ) ) {
			delete_option( self::ACTIVE_IDS_OPTION );
		}
		add_option( self::ACTIVE_IDS_OPTION, $merged, '', false );
	}

	/**
	 * Cancel every queued sync job and clear all sync-related cron events.
	 *
	 * Used by the "Emergency Stop" button. Also releases the concurrency lock
	 * and clears the WP-Cron scan-resume event, without which the scan simply
	 * picks up again on the next cron tick.
	 *
	 * Image localization is deliberately left running: it finishes downloading
	 * images for products that were already imported, and is not part of the
	 * scan/import pipeline being stopped.
	 *
	 * @return void
	 */
	public static function stop_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_listings', null, self::GROUP_SCAN );
			as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages', null, self::GROUP_SCAN );
			as_unschedule_all_actions( 'tcgiant_sync_fetch_delta_events', null, self::GROUP_SCAN );
			as_unschedule_all_actions( 'tcgiant_sync_prune_orphans', null, self::GROUP_SCAN );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, self::GROUP_IMPORTS );
			as_unschedule_all_actions( 'tcgiant_sync_download_images', null, self::GROUP_IMAGES );
		}

		// The scan resumes itself through WP-Cron, not Action Scheduler.
		wp_clear_scheduled_hook( 'tcgiant_sync_scan_resume' );

		// Otherwise the next sync is blocked until the lock goes stale.
		self::release_lock();
	}

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
	 * TCGiant_Sync_Importer Constructor.
	 */
	public function __construct() {
		add_action( 'tcgiant_sync_process_item_import', array( $this, 'process_item_import' ), 10, 1 );
		add_action( 'tcgiant_sync_scan_all_pages', array( $this, 'scan_all_pages' ) );
		add_action( 'tcgiant_sync_scan_resume', array( $this, 'scan_all_pages' ) ); // WP-Cron resume hook.
		add_action( 'tcgiant_sync_fetch_delta_events', array( $this, 'fetch_delta_events' ) );
		add_action( 'tcgiant_sync_prune_orphans', array( $this, 'prune_orphaned_items' ) );

		// Note: 'tcgiant_sync_fetch_listings' (per-page scanning, superseded by
		// scan_all_pages in 2.0.0) and 'tcgiant_sync_download_images' (the
		// synchronous image downloader, superseded by the background localizer
		// in 3.0.0) no longer have handlers. Both are still unscheduled in
		// stop_all() and when a sync starts, so any actions left queued on an
		// upgraded site are cleared rather than retried forever.
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
			'scan_complete_clean'     => false,        // True only when every page was scanned without error — gates orphan pruning
		) );
	}

	/**
	 * Maximum share of eBay-linked products the pruner may trash in one run.
	 *
	 * A correct scan rarely retires more than a few percent of a catalogue at
	 * once. Anything above this threshold almost certainly means the scan was
	 * incomplete, so we refuse and ask for a human decision rather than
	 * trashing the store.
	 */
	const PRUNE_MAX_RATIO = 0.20;

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

	// ───────────────────────────────────────────────────────────────────────────
	// Concurrent execution lock — file-based with stale detection.
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Lock file path.
	 */
	private static function get_lock_file_path() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'tcgiant_sync.lock';
	}

	/**
	 * Acquire the sync lock.
	 *
	 * @param string $operation Label for which operation is acquiring (for diagnostics).
	 * @return bool True if lock acquired, false if already locked.
	 */
	private static function acquire_lock( $operation = 'sync' ) {
		$lock_file = self::get_lock_file_path();

		if ( file_exists( $lock_file ) ) {
			$lock_time = (int) file_get_contents( $lock_file );
			$age = time() - $lock_time;

			// Stale detection: if lock is older than 10 minutes, break it.
			if ( $age > 600 ) {
				TCGiant_Sync_Logger::warning( sprintf(
					'Lock: Stale lock detected (%d minutes old). Breaking lock for "%s".',
					round( $age / 60 ), $operation
				) );
				@unlink( $lock_file );
			} else {
				TCGiant_Sync_Logger::log( sprintf(
					'Lock: Cannot acquire for "%s" — already held (%d seconds ago).',
					$operation, $age
				) );
				return false;
			}
		}

		// Write lock file with current timestamp.
		$written = @file_put_contents( $lock_file, (string) time() );
		if ( false === $written ) {
			TCGiant_Sync_Logger::warning( 'Lock: Failed to write lock file — proceeding without lock.' );
			return true; // Proceed anyway; lock is advisory.
		}

		return true;
	}

	/**
	 * Release the sync lock.
	 */
	private static function release_lock() {
		$lock_file = self::get_lock_file_path();
		if ( file_exists( $lock_file ) ) {
			@unlink( $lock_file );
		}
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
			return new WP_Error(
				'limit_reached',
				__( 'Import limit reached. Upgrade to Pro for unlimited imports.', 'tcgiant-sync' )
			);
		}

		// Guard: don't restart if a sync is already in progress (prevents cron overlap).
		if ( ! $force ) {
			$current = self::get_sync_state();
			if ( in_array( $current['status'], array( 'scanning', 'importing' ), true ) ) {
				TCGiant_Sync_Logger::log( 'Sync already in progress - skipping duplicate request.' );
				return new WP_Error(
					'already_running',
					__( 'A sync is already running.', 'tcgiant-sync' )
				);
			}
		}

		// File lock: prevent concurrent execution from overlapping cron triggers.
		//
		// This is why "force" is not absolute: a scan holding the lock keeps it
		// until it finishes or the lock goes stale, so the caller has to be told
		// the request was declined rather than shown a success message.
		if ( ! self::acquire_lock( 'full_sync' ) ) {
			return new WP_Error(
				'sync_locked',
				__( 'Another sync is currently running. Wait for it to finish, or use Emergency Stop first.', 'tcgiant-sync' )
			);
		}

		// Clear any previous pending sync jobs to prevent stacking.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_listings', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages', null, 'tcgiant_sync_group' );
			wp_clear_scheduled_hook( 'tcgiant_sync_scan_resume' );
			as_unschedule_all_actions( 'tcgiant_sync_fetch_delta_events', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_imports' );
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
			'scan_complete_clean' => false,
		) );

		// Clear active IDs list for pruning orphaned items.
		delete_option( self::ACTIVE_IDS_OPTION );

		$api = TCGiant_Sync_API::instance();
		TCGiant_Sync_Logger::log( sprintf(
			'Starting full sync for: %s (API budget: %d/%d calls remaining today)',
			$filter_name, $api->get_remaining_daily_budget(), TCGiant_Sync_API::DAILY_CALL_LIMIT
		) );
		as_enqueue_async_action( 'tcgiant_sync_scan_all_pages', array(), 'tcgiant_sync_group' );

		return true;
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

		// File lock: prevent concurrent execution.
		if ( ! self::acquire_lock( 'delta_sync' ) ) {
			return;
		}

		// Clear any previous pending sync jobs.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_listings', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages', null, 'tcgiant_sync_group' );
			wp_clear_scheduled_hook( 'tcgiant_sync_scan_resume' );
			as_unschedule_all_actions( 'tcgiant_sync_fetch_delta_events', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_imports' );
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
			// A delta sync never builds a complete active-ID list, so it must
			// never leave the pruner authorised.
			'scan_complete_clean' => false,
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
			// Syncing a hand-picked set of items says nothing about which
			// listings are still active, so it must not authorise pruning.
			'scan_complete_clean' => false,
		) );

		foreach ( $item_ids as $item_id ) {
			as_enqueue_async_action( 'tcgiant_sync_process_item_import', array( 'item_id' => $item_id ), 'tcgiant_sync_imports' );
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

			// Set external eBay image URLs — localized in background.
			TCGiant_Sync_Image_Localizer::set_external_images( (int) $product_id, $all_images );

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

		// Category filtering (same logic as process_page_items).
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

				// Don't create products for listings that have already ended.
				//
				// A sale IS a modification, so GetSellerEvents reports the item
				// on the very next delta sync — meaning the items most likely to
				// arrive here are the ones that just sold and ended. Importing
				// those created a fresh product for something no longer for sale,
				// and for a merchant who had deleted the product after the sale
				// it looked like deleted items rising from the dead.
				//
				// An existing product still gets its status updated, so ended
				// listings are still reflected; only creation is prevented.
				$listing_status = $ebay_item['SellingStatus']['ListingStatus'] ?? '';
				if ( '' !== $listing_status && 'Active' !== $listing_status ) {
					global $wpdb;
					$existing_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT pm.post_id FROM {$wpdb->postmeta} pm
						 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
						 WHERE pm.meta_key = '_ebay_item_id' AND pm.meta_value = %s
						   AND p.post_status != 'trash'
						 LIMIT 1",
						$item_id
					) );

					if ( ! $existing_id ) {
						$skipped++;
						continue;
					}

					// Known product — record that its listing is over and move on.
					update_post_meta( (int) $existing_id, '_ebay_listing_status', 'Ended' );
					if ( ! empty( $ebay_item['ListingDetails']['EndTime'] ) ) {
						update_post_meta( (int) $existing_id, '_ebay_end_time', $ebay_item['ListingDetails']['EndTime'] );
					}
					TCGiant_Sync_Logger::log( sprintf(
						'Delta: eBay item %s is %s — marked WC #%d as ended (not re-imported).',
						$item_id, $listing_status, (int) $existing_id
					) );
					$skipped++;
					continue;
				}

				// Fallback: if critical data is missing, fetch via GetItem.
				$needs_fallback = false;
				if ( ! isset( $ebay_item['Title'] ) || ! isset( $ebay_item['SellingStatus'] ) ) {
					$needs_fallback = true;
				}
				// If specs or images are missing, we need GetItem to fetch the full data.
				// Test PictureURL rather than the PictureDetails container: a summary
				// response can carry PictureDetails holding only a GalleryURL, which
				// looked complete here but yields no importable images at all.
				if ( ! isset( $ebay_item['ItemSpecifics'] ) || ! isset( $ebay_item['PictureDetails']['PictureURL'] ) ) {
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

					// Set external eBay image URLs — localized in background.
					if ( ! empty( $product_data['images'] ) ) {
						TCGiant_Sync_Image_Localizer::set_external_images( $product_id, $product_data['images'] );
					}
					$processed++;

					$price_display = ! empty( $product_data['price'] ) ? '$' . $product_data['price'] : 'No price';
					$attr_count = count( $product_data['attributes'] );
					TCGiant_Sync_Logger::log( sprintf(
						'Delta imported: "%s" → WC #%d (%s, Qty: %d, %d attrs)',
						$title, $product_id, $price_display, $product_data['stock_quantity'], $attr_count
					), 'success' );
				} elseif ( ! TCGiant_Sync_Mapper::$last_skipped ) {
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
			as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages', null, 'tcgiant_sync_group' );
			wp_clear_scheduled_hook( 'tcgiant_sync_scan_resume' );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_imports' );
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
			as_enqueue_async_action( 'tcgiant_sync_scan_all_pages', array(), 'tcgiant_sync_group' );
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
	 * Scan eBay listing pages in batches with WP-Cron resume.
	 *
	 * Processes up to PAGES_PER_BATCH pages per execution, then schedules
	 * an immediate WP-Cron event to continue. This handles hosts with strict
	 * 30-second PHP time limits where set_time_limit() is disabled.
	 *
	 * Uses wp_schedule_single_event() (WordPress built-in cron) instead of
	 * Action Scheduler for resumption. WP-Cron events fire directly on the
	 * next page load — they don't compete with the AS batch queue.
	 *
	 * A 47-page store completes scanning in ~5 minutes across ~5 batches.
	 */
	const PAGES_PER_BATCH = 10;

	public function scan_all_pages() {
		// Try to extend the time limit, but don't rely on it.
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		ignore_user_abort( true );

		$state = self::get_sync_state();
		$start_page = max( 1, (int) ( $state['current_page'] ?? 1 ) );

		// If resuming, start from the NEXT page (current_page was already processed).
		if ( $start_page > 1 && in_array( $state['status'] ?? '', array( 'scanning', 'rate_limited' ), true ) ) {
			$start_page++;
		}

		$api = TCGiant_Sync_API::instance();
		$mod_time_from = ( 'delta' === ( $state['sync_mode'] ?? 'full' ) ) ? ( $state['delta_mod_from'] ?? '' ) : '';

		TCGiant_Sync_Logger::log( sprintf(
			'Scan batch starting from page %d (up to %d pages). API budget: %d/%d.',
			$start_page, self::PAGES_PER_BATCH, $api->get_remaining_daily_budget(), TCGiant_Sync_API::DAILY_CALL_LIMIT
		) );

		$total_pages = (int) ( $state['total_pages'] ?? 0 );
		$pages_this_batch = 0;

		for ( $current_page = $start_page; $pages_this_batch < self::PAGES_PER_BATCH; $current_page++ ) {
			self::update_sync_state( array(
				'status'              => 'scanning',
				'current_page'        => $current_page,
				'rate_limit_retries'  => 0,
			) );

			$response = $api->get_active_listings( $current_page, 200, $mod_time_from );

			// Handle rate limiting — pause and schedule via WP-Cron.
			if ( is_wp_error( $response ) && 'rate_limited' === $response->get_error_code() ) {
				$retries = (int) ( self::get_sync_state()['rate_limit_retries'] ?? 0 );
				$retry_delay = $this->get_rate_limit_backoff_delay( $retries );
				self::update_sync_state( array(
					'status'             => 'rate_limited',
					'current_page'       => $current_page - 1, // Last successful page.
					'rate_limit_retries' => $retries + 1,
				) );
				TCGiant_Sync_Logger::log( sprintf(
					'eBay API rate limit hit on page %d (attempt %d). Auto-retry in %d minutes.',
					$current_page, $retries + 1, $retry_delay / 60
				), 'warning' );
				wp_schedule_single_event( time() + $retry_delay, 'tcgiant_sync_scan_resume' );
				return;
			}

			// A failed request is NOT the same as "no more pages".
			// Treating it as end-of-scan used to hand a half-populated
			// active-ID list to the pruner, which then trashed every product
			// belonging to the pages we never reached.
			if ( is_wp_error( $response ) ) {
				TCGiant_Sync_Logger::error( sprintf(
					'Scan aborted on page %d: %s. Progress kept — use "Resume Import" to continue. Orphan pruning skipped.',
					$current_page,
					$response->get_error_message()
				) );
				self::update_sync_state( array(
					'status'              => 'error',
					'current_page'        => max( 1, $current_page - 1 ), // Last page we actually completed.
					'scan_complete_clean' => false,
				) );
				self::release_lock();
				return;
			}

			// Genuinely empty page — we have reached the end of the listings.
			if ( ! isset( $response['ItemArray']['Item'] ) ) {
				$this->finalize_scan( true );
				return;
			}

			// Parse items from the response.
			$items = $response['ItemArray']['Item'];
			if ( ! isset( $items[0] ) ) {
				$items = array( $items );
			}

			$total_pages = isset( $response['PaginationResult']['TotalNumberOfPages'] ) ? (int) $response['PaginationResult']['TotalNumberOfPages'] : 1;
			self::update_sync_state( array( 'total_pages' => $total_pages ) );

			// Process all items on this page inline.
			$this->process_page_items( $items, $current_page, $total_pages );
			$pages_this_batch++;

			TCGiant_Sync_Logger::log( sprintf(
				'Page %d/%d scanned (%d/%d in this batch).',
				$current_page, $total_pages, $pages_this_batch, self::PAGES_PER_BATCH
			) );

			// Check if we've reached the last page.
			if ( $current_page >= $total_pages ) {
				$this->finalize_scan( true );
				return;
			}

			// Rate-limit pause between pages — 2 seconds to avoid hammering eBay.
			sleep( 2 );
		}

		// Batch complete but more pages remain.
		// Schedule immediate resume via WP-Cron (NOT Action Scheduler).
		TCGiant_Sync_Logger::log( sprintf(
			'Batch complete (%d pages). Scheduling immediate resume for page %d/%d via WP-Cron.',
			$pages_this_batch, $current_page, $total_pages
		) );
		wp_schedule_single_event( time() - 1, 'tcgiant_sync_scan_resume' );

		// spawn_cron() was a no-op here: it returns immediately when DOING_CRON
		// is defined, which it is, because this scan is itself running from
		// cron. Dispatching on shutdown instead means the loopback fires once
		// this run has released the cron lock, so the next batch starts in
		// seconds rather than waiting for the next natural tick.
		TCGiant_Sync_Cron::request_dispatch();
	}

	/**
	 * Finalize the scan — transition to importing or complete state.
	 *
	 * @param bool $clean True when every page was scanned without error. Only a
	 *                    clean scan may authorise orphan pruning, since the
	 *                    pruner trashes anything absent from the active-ID list.
	 */
	private function finalize_scan( $clean = false ) {
		$state = self::get_sync_state();

		if ( $state['total_queued'] > 0 || $state['total_processed'] > 0 ) {
			self::update_sync_state( array(
				'status'              => 'importing',
				'scan_complete_clean' => (bool) $clean,
			) );
			TCGiant_Sync_Logger::log( sprintf(
				'Scan complete. %d found, %d imported inline, %d queued for GetItem across %d pages.',
				$state['total_found'], $state['total_processed'], $state['total_queued'], $state['total_pages']
			) );
		} else {
			self::update_sync_state( array(
				'status'                  => 'complete',
				'last_completed'          => current_time( 'mysql' ),
				'last_successful_sync_at' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				'scan_complete_clean'     => (bool) $clean,
			) );
			TCGiant_Sync_Logger::log( 'Scan complete. No matching items found.' );
			if ( $clean ) {
				as_enqueue_async_action( 'tcgiant_sync_prune_orphans', array(), 'tcgiant_sync_group' );
			}
		}

		// Release the concurrent execution lock.
		self::release_lock();
	}

	/**
	 * Process all items from a single page of eBay listings.
	 *
	 * Called from the page-scanning loop in scan_all_pages().
	 *
	 * @param array $items       Array of eBay item arrays.
	 * @param int   $page_number Current page number.
	 * @param int   $total_pages Total pages available.
	 */
	private function process_page_items( $items, $page_number, $total_pages ) {
		$queued_count = 0;
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$is_custom_filtering = ! empty( $settings['category_ids'] );
		$standard_cats       = ! empty( $settings['import_standard_category_ids'] ) && is_array( $settings['import_standard_category_ids'] ) ? $settings['import_standard_category_ids'] : array();
		$is_filtering        = $is_custom_filtering || ! empty( $standard_cats );

		$valid_custom_ids = $is_custom_filtering ? $this->get_allowed_category_ids() : array();

		// Debug: log resolved category IDs on the first page.
		if ( $page_number === 1 && $is_filtering ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Category filter active. Custom IDs: [%s]. Standard IDs: [%s].',
				implode( ', ', $valid_custom_ids ),
				implode( ', ', $standard_cats )
			) );
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

			$active_ids_batch[] = $item_id;

			// Category pre-filter.
			if ( $is_filtering ) {
				$primary_cat = $item['PrimaryCategory']['CategoryID'] ?? '';
				$store_cat1 = $item['Storefront']['StoreCategoryID'] ?? '';
				$store_cat2 = $item['Storefront']['StoreCategory2ID'] ?? '';

				$match = false;

				if ( ! empty( $standard_cats ) ) {
					if ( in_array( (string) $primary_cat, $standard_cats, true ) ) $match = true;
				}

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
			// PictureURL, not the PictureDetails container — see fetch_delta_events().
			$missing_specs_or_images = ! isset( $item['ItemSpecifics'] ) || ! isset( $item['PictureDetails']['PictureURL'] );

			$can_inline = ! $missing_critical && ! $missing_specs_or_images;
			if ( $has_variations && $can_inline ) {
				$can_inline = isset( $item['Variations']['Variation'] ) && ! empty( $item['Variations']['Variation'] );
			}

			if ( $can_inline ) {
				try {
					$title = $item['Title'] ?? 'Unknown';
					$product_data = $mapper->map_ebay_to_woo( $item );
					$product_id = $mapper->save_as_product( $product_data );

					if ( $product_id ) {
						if ( ! empty( $product_data['variations'] ) ) {
							foreach ( $product_data['variations'] as $var ) {
								if ( ! empty( $var['image_url'] ) && ! empty( $var['variation_id'] ) ) {
									$product_data['images'][] = array( $var['image_url'], $var['variation_id'] );
								}
							}
						}

						// Set external eBay image URLs — localized in background.
						if ( ! empty( $product_data['images'] ) ) {
							TCGiant_Sync_Image_Localizer::set_external_images( $product_id, $product_data['images'] );
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
					} elseif ( ! TCGiant_Sync_Mapper::$last_skipped ) {
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
				// Queue for GetItem fallback.
				$delay = min( $queued_count, 60 );
				as_schedule_single_action( time() + $delay, 'tcgiant_sync_process_item_import', array( 'item_id' => $item_id ), 'tcgiant_sync_imports' );
				$queued_count++;
			}
		}

		self::append_active_ids( $active_ids_batch );

		// Update state with running totals.
		$state = self::get_sync_state();
		$new_total_found = $state['total_found'] + count( $items );
		$new_total_queued = $state['total_queued'] + $queued_count;
		$new_total_processed = $state['total_processed'] + $inline_processed;
		$new_total_errors = $state['total_errors'] + $inline_errors;

		$last_title = '';
		if ( ! empty( $items ) ) {
			$last_item = end( $items );
			$last_title = $last_item['Title'] ?? '';
		}

		self::update_sync_state( array(
			'current_page'    => $page_number,
			'total_found'     => $new_total_found,
			'total_queued'    => $new_total_queued,
			'total_processed' => $new_total_processed,
			'total_errors'    => $new_total_errors,
			'last_item_title' => $last_title,
		) );

		// Log summary.
		if ( $page_number <= $total_pages ) {
			$matched_label = $is_filtering ? sprintf( ', %d matched filter', $inline_processed + $queued_count ) : '';
			$inline_label = $inline_processed > 0 ? sprintf( ', %d imported inline', $inline_processed ) : '';
			$queued_label = $queued_count > 0 ? sprintf( ', %d queued for GetItem', $queued_count ) : '';
			$filter_label = '';
			TCGiant_Sync_Logger::log( sprintf(
				'Page %d/%d: Scanned %d items%s%s%s%s.',
				$page_number, $total_pages, count( $items ), $matched_label, $inline_label, $queued_label, $filter_label
			) );
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

				// eBay already decremented its own quantity when the order was
				// placed — suppress the WC → eBay push so we don't echo it back.
				TCGiant_Sync_Inventory::begin_ebay_origin();
				try {
					$stock_reduced = wc_update_product_stock( $product_id, $quantity, 'decrease' );
				} finally {
					TCGiant_Sync_Inventory::end_ebay_origin();
				}

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
					as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_imports' );
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
				as_schedule_single_action( time() + $retry_delay, 'tcgiant_sync_process_item_import', array( 'item_id' => $item_id ), 'tcgiant_sync_imports' );
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

				// Set external eBay image URLs — localized in background.
				if ( ! empty( $product_data['images'] ) ) {
					TCGiant_Sync_Image_Localizer::set_external_images( $product_id, $product_data['images'] );
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
			} elseif ( ! TCGiant_Sync_Mapper::$last_skipped ) {
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
				'group'  => self::GROUP_IMPORTS,
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
	 *
	 * This is the single most destructive operation in the plugin, so it is
	 * gated three ways:
	 *
	 *   1. The last scan must have completed cleanly (scan_complete_clean).
	 *      A scan aborted by an API error leaves a partial active-ID list, and
	 *      pruning against that trashes every product on the pages we never
	 *      reached.
	 *   2. The active-ID list must be non-empty. An empty list cannot be
	 *      distinguished from a failure, and verifying each product against the
	 *      API instead costs one Trading API call per product.
	 *   3. The prune set must not exceed PRUNE_MAX_RATIO of the catalogue.
	 */
	public function prune_orphaned_items() {
		$state = self::get_sync_state();

		if ( empty( $state['scan_complete_clean'] ) ) {
			TCGiant_Sync_Logger::warning(
				'Inventory Pruning: Skipped — the last scan did not complete cleanly. '
				. 'Run a full sync to completion before orphaned products can be retired.'
			);
			return;
		}

		$active_ids = get_option( self::ACTIVE_IDS_OPTION, array() );
		if ( ! is_array( $active_ids ) || empty( $active_ids ) ) {
			TCGiant_Sync_Logger::warning(
				'Inventory Pruning: Skipped — no active eBay Item IDs were recorded during the scan. '
				. 'Refusing to prune, since an empty list is indistinguishable from a failed scan.'
			);
			return;
		}

		global $wpdb;
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$preserve_cats = isset( $settings['preserve_woo_category_ids'] ) && is_array( $settings['preserve_woo_category_ids'] ) ? $settings['preserve_woo_category_ids'] : array();

		// O(1) membership test. in_array() over a 10k-element list, once per
		// product, is 100M string comparisons on a large catalogue.
		$active_lookup = array_flip( array_map( 'strval', $active_ids ) );

		// Get all products that have an _ebay_item_id.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ebay_linked_products = $wpdb->get_results(
			"SELECT post_id, meta_value as ebay_id
			FROM {$wpdb->postmeta} pm
			JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_ebay_item_id' AND pm.meta_value != '' AND p.post_type = 'product' AND p.post_status != 'trash'"
		);

		$total_linked = count( $ebay_linked_products );

		// Decide the prune set up front so we can sanity-check its size before
		// touching anything.
		$prune_rows = array();
		foreach ( $ebay_linked_products as $row ) {
			if ( ! isset( $active_lookup[ (string) $row->ebay_id ] ) ) {
				$prune_rows[] = $row;
			}
		}

		$prune_count = count( $prune_rows );

		if ( 0 === $prune_count ) {
			TCGiant_Sync_Logger::log( 'Inventory Pruning: No orphaned products found.' );
			delete_option( self::ACTIVE_IDS_OPTION );
			return;
		}

		/**
		 * Filter the maximum share of eBay-linked products a single prune may retire.
		 *
		 * @param float $ratio        Between 0 and 1. Default self::PRUNE_MAX_RATIO.
		 * @param int   $prune_count  Number of products this run would retire.
		 * @param int   $total_linked Total eBay-linked products.
		 */
		$max_ratio = (float) apply_filters( 'tcgiant_sync_prune_max_ratio', self::PRUNE_MAX_RATIO, $prune_count, $total_linked );

		if ( $total_linked > 0 && $max_ratio > 0 && ( $prune_count / $total_linked ) > $max_ratio ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Inventory Pruning: ABORTED — would have trashed %d of %d eBay-linked products (%.1f%%, limit %.0f%%). '
				. 'This normally means the scan was incomplete. Nothing was changed. '
				. 'Re-run a full sync; if the removal is genuine, raise the limit with the '
				. 'tcgiant_sync_prune_max_ratio filter.',
				$prune_count,
				$total_linked,
				( $prune_count / $total_linked ) * 100,
				$max_ratio * 100
			) );
			return;
		}

		$trashed_count   = 0;
		$preserved_count = 0;

		foreach ( $prune_rows as $row ) {
			$product_id = (int) $row->post_id;

			$is_preserved = ! empty( $preserve_cats ) && has_term( $preserve_cats, 'product_cat', $product_id );

			if ( $is_preserved ) {
				// The listing is already gone from eBay; zeroing WC stock must
				// not trigger a push (which would try to end an already-ended
				// listing and burn an API call per product).
				TCGiant_Sync_Inventory::begin_ebay_origin();
				try {
					wc_update_product_stock( $product_id, 0, 'set' );
					wc_update_product_stock_status( $product_id, 'outofstock' );
				} finally {
					TCGiant_Sync_Inventory::end_ebay_origin();
				}
				delete_post_meta( $product_id, '_ebay_item_id' );
				$preserved_count++;
			} else {
				wp_trash_post( $product_id );
				$trashed_count++;
			}
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Inventory Pruning: Trashed %d products, preserved %d products (set to out of stock), out of %d eBay-linked.',
			$trashed_count, $preserved_count, $total_linked
		), 'success' );

		delete_option( self::ACTIVE_IDS_OPTION );
	}








}
