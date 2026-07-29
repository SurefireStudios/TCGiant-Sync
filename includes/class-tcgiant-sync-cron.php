<?php
/**
 * Cron Tasks
 *
 * Handles scheduled synchronization tasks, rate limiting, and daily maintenance.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Cron class
 */
class TCGiant_Sync_Cron {

	/**
	 * Minimum seconds between cron runs to prevent rapid-fire stacking.
	 */
	const MIN_INTERVAL_SECONDS = 120;

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
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );
		add_action( 'tcgiant_sync_poll_ebay_cron', array( $this, 'poll_ebay' ) );
		add_action( 'tcgiant_sync_poll_ebay_cron', array( $this, 'sync_orders' ) );
		add_action( 'tcgiant_sync_poll_ebay_cron', array( $this, 'ping_telemetry' ) );
		add_action( 'tcgiant_sync_daily_maintenance', array( $this, 'daily_maintenance' ) );
		add_action( 'tcgiant_sync_daily_maintenance', array( $this, 'auto_relist_ended_items' ) );
		
		// One-time listing type backfill on upgrade.
		add_action( 'admin_init', array( $this, 'maybe_backfill_listing_type' ) );

		add_action( 'init', array( $this, 'schedule_events' ) );
	}

	/**
	 * Schedule cron events after init to avoid early translation loading.
	 */
	public function schedule_events() {
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$interval = $settings['sync_interval'] ?? 'tcgiant_hourly';

		$scheduled_hook = wp_get_schedule( 'tcgiant_sync_poll_ebay_cron' );

		if ( 'disabled' === $interval ) {
			wp_clear_scheduled_hook( 'tcgiant_sync_poll_ebay_cron' );
		} else {
			if ( ! wp_next_scheduled( 'tcgiant_sync_poll_ebay_cron' ) || $scheduled_hook !== $interval ) {
				wp_clear_scheduled_hook( 'tcgiant_sync_poll_ebay_cron' );
				wp_schedule_event( time(), $interval, 'tcgiant_sync_poll_ebay_cron' );
			}
		}

		// Schedule daily maintenance if not already scheduled.
		if ( ! wp_next_scheduled( 'tcgiant_sync_daily_maintenance' ) ) {
			// Run at 3 AM server time to avoid peak hours.
			$next_3am = strtotime( 'tomorrow 03:00:00' );
			wp_schedule_event( $next_3am, 'daily', 'tcgiant_sync_daily_maintenance' );
		}
	}

	/**
	 * Add custom cron intervals.
	 */
	public function add_cron_intervals( $schedules ) {
		$schedules['tcgiant_15mins'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => did_action( 'wp_loaded' ) ? esc_html__( 'Every 15 Minutes', 'tcgiant-sync' ) : 'Every 15 Minutes',
		);
		$schedules['tcgiant_hourly'] = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => did_action( 'wp_loaded' ) ? esc_html__( 'Hourly', 'tcgiant-sync' ) : 'Hourly',
		);
		return $schedules;
	}

	/**
	 * Rate limiter: prevent rapid-fire cron runs.
	 *
	 * @return bool True if OK to proceed, false if too soon.
	 */
	private function rate_limit_check() {
		$transient_key = 'tcgiant_sync_last_cron_run';
		$last_run = get_transient( $transient_key );

		if ( false !== $last_run ) {
			$elapsed = time() - (int) $last_run;
			if ( $elapsed < self::MIN_INTERVAL_SECONDS ) {
				TCGiant_Sync_Logger::log( sprintf(
					'WP-Cron: Rate limited — last run %d seconds ago (minimum %d). Skipping.',
					$elapsed, self::MIN_INTERVAL_SECONDS
				) );
				return false;
			}
		}

		set_transient( $transient_key, time(), self::MIN_INTERVAL_SECONDS );
		return true;
	}

	/**
	 * Run the polling task.
	 *
	 * Uses delta sync to only fetch items modified since the last successful sync.
	 * If no previous sync exists, it automatically falls back to a full sync.
	 */
	public function poll_ebay() {
		if ( ! $this->rate_limit_check() ) {
			return;
		}

		TCGiant_Sync_Logger::log( 'WP-Cron: Starting scheduled eBay poll (delta sync)...' );
		TCGiant_Sync_Importer::instance()->start_delta_sync();
	}

	/**
	 * Run the order syncing task.
	 */
	public function sync_orders() {
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		if ( empty( $settings['enable_order_sync'] ) ) {
			return; // disabled
		}
		
		TCGiant_Sync_Logger::log( 'WP-Cron: Starting scheduled eBay Order Sync...' );
		TCGiant_Sync_Importer::instance()->sync_recent_orders();
	}

	/**
	 * Send an absolute telemetry ping to keep dashboard accurate.
	 */
	public function ping_telemetry() {
		$license_data = TCGiant_Sync_License::instance()->get_license_data();
		$license_type = 'free';
		if ( ! empty( $license_data['status'] ) && 'active' === $license_data['status'] ) {
			$license_type = ! empty( $license_data['variant'] ) ? $license_data['variant'] : 'pro';
		}

		wp_remote_post( 'https://tcgiant.com/syncconnect/telemetry.php', array(
			'blocking' => false,
			'headers'  => array(
				'Content-Type' => 'application/json',
			),
			'body'     => wp_json_encode( array(
				'site_url'     => get_site_url(),
				'synced_total' => TCGiant_Sync_License::instance()->get_active_product_count(),
				'pushed_total' => TCGiant_Sync_License::instance()->get_pushed_product_count(),
				'pulled_total' => TCGiant_Sync_License::instance()->get_pulled_product_count(),
				'license_type' => $license_type,
			) ),
		) );
	}

	/**
	 * Daily maintenance task — runs at 3 AM.
	 *
	 * Cleans up old logs, stale sync state, and orphaned AS entries.
	 */
	public function daily_maintenance() {
		TCGiant_Sync_Logger::log( 'Daily maintenance: Starting...' );
		$this->clean_old_logs();
		$this->clean_stale_sync_state();
		$this->clean_orphaned_as_entries();
		$this->log_daily_summary();
		TCGiant_Sync_Logger::log( 'Daily maintenance: Complete.' );
	}

	/**
	 * Remove log files older than 30 days.
	 */
	private function clean_old_logs() {
		$log_dir = TCGIANT_SYNC_PATH . 'logs/';
		if ( ! is_dir( $log_dir ) ) {
			return;
		}

		$cutoff = time() - ( 30 * DAY_IN_SECONDS );
		$cleaned = 0;

		foreach ( glob( $log_dir . '*.log' ) as $file ) {
			if ( filemtime( $file ) < $cutoff ) {
				@unlink( $file );
				$cleaned++;
			}
		}

		if ( $cleaned > 0 ) {
			TCGiant_Sync_Logger::log( sprintf( 'Daily maintenance: Cleaned %d old log file(s).', $cleaned ) );
		}
	}

	/**
	 * Reset syncs stuck in "scanning" for 24+ hours.
	 */
	private function clean_stale_sync_state() {
		$state = TCGiant_Sync_Importer::get_sync_state();
		if ( ! in_array( $state['status'], array( 'scanning', 'importing' ), true ) ) {
			return;
		}

		$last_activity = strtotime( $state['last_activity'] );
		if ( $last_activity && ( time() - $last_activity ) > DAY_IN_SECONDS ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Daily maintenance: Sync stuck in "%s" since %s. Resetting to idle.',
				$state['status'], $state['last_activity']
			) );
			TCGiant_Sync_Importer::update_sync_state( array(
				'status' => 'idle',
			) );
		}
	}

	/**
	 * Clean up completed/failed AS entries older than 7 days.
	 */
	private function clean_orphaned_as_entries() {
		if ( ! class_exists( 'ActionScheduler_DBStore' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';

		// Check if the table exists.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table}
			 WHERE hook LIKE %s
			   AND status IN ('complete', 'failed', 'canceled')
			   AND scheduled_date_gmt < %s",
			'tcgiant_sync_%',
			$cutoff
		) );

		if ( $deleted > 0 ) {
			TCGiant_Sync_Logger::log( sprintf( 'Daily maintenance: Cleaned %d old AS entries.', $deleted ) );
		}
	}

	/**
	 * Log a daily summary of sync activity.
	 */
	private function log_daily_summary() {
		$state = TCGiant_Sync_Importer::get_sync_state();
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$api_calls = isset( $settings['api_calls_today'] ) ? (int) $settings['api_calls_today'] : 0;

		TCGiant_Sync_Logger::log( sprintf(
			'Daily summary: Status=%s, Total found=%d, Processed=%d, Errors=%d, API calls today=%d',
			$state['status'], $state['total_found'], $state['total_processed'], $state['total_errors'], $api_calls
		) );
	}

	// ───────────────────────────────────────────────────────────────────────────
	// Auto-Relist Scheduler
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Auto-relist ended eBay items that still have stock.
	 * Runs daily as part of maintenance. Disabled by default.
	 */
	public function auto_relist_ended_items() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		if ( empty( $settings['auto_relist_enabled'] ) ) {
			return;
		}

		TCGiant_Sync_Logger::log( 'Auto-Relist: Checking for ended listings with stock > 0...' );

		global $wpdb;
		$table = TCGiant_Sync_DB::table_name();

		// Check if custom table exists.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			// Fall back to post meta query.
			$ended_products = $wpdb->get_results(
				"SELECT pm1.post_id, pm1.meta_value AS ebay_item_id
				 FROM {$wpdb->postmeta} pm1
				 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_ebay_listing_status'
				 INNER JOIN {$wpdb->posts} p ON pm1.post_id = p.ID
				 WHERE pm1.meta_key = '_ebay_item_id'
				   AND pm2.meta_value = 'Ended'
				   AND p.post_status = 'publish'
				 LIMIT 50",
				ARRAY_A
			);
		} else {
			$ended_products = $wpdb->get_results(
				"SELECT product_id, ebay_item_id FROM {$table}
				 WHERE listing_status = 'Ended'
				 LIMIT 50",
				ARRAY_A
			);
		}

		if ( empty( $ended_products ) ) {
			TCGiant_Sync_Logger::log( 'Auto-Relist: No ended listings found.' );
			return;
		}

		$api = TCGiant_Sync_API::instance();
		$relisted = 0;
		$skipped = 0;

		foreach ( $ended_products as $row ) {
			$product_id   = (int) ( $row['product_id'] ?? $row['post_id'] ?? 0 );
			$ebay_item_id = $row['ebay_item_id'] ?? '';

			if ( empty( $ebay_item_id ) || ! $product_id ) {
				continue;
			}

			// Only relist if WC stock > 0.
			$product = wc_get_product( $product_id );
			if ( ! $product || ( $product->managing_stock() && $product->get_stock_quantity() <= 0 ) ) {
				$skipped++;
				continue;
			}

			$result = $api->relist_item( $ebay_item_id );
			if ( is_wp_error( $result ) ) {
				TCGiant_Sync_Logger::error( sprintf(
					'Auto-Relist: Failed to relist eBay #%s (WC #%d): %s',
					$ebay_item_id, $product_id, $result->get_error_message()
				) );
			} else {
				$new_item_id = $result['ItemID'] ?? $ebay_item_id;
				update_post_meta( $product_id, '_ebay_item_id', $new_item_id );
				update_post_meta( $product_id, '_ebay_listing_status', 'Active' );

				// Update custom table if available.
				TCGiant_Sync_DB::upsert( array(
					'product_id'     => $product_id,
					'ebay_item_id'   => $new_item_id,
					'listing_status' => 'Active',
					'last_synced'    => current_time( 'mysql' ),
				) );

				$relisted++;
			}
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Auto-Relist: Complete. %d relisted, %d skipped (no stock).',
			$relisted, $skipped
		), $relisted > 0 ? 'success' : 'info' );
	}

	// ───────────────────────────────────────────────────────────────────────────
	// Listing Type Meta Backfill
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * One-time migration: backfill _ebay_listing_type for products that have
	 * _ebay_item_id but no listing type set.
	 */
	public function maybe_backfill_listing_type() {
		if ( get_option( 'tcgiant_listing_type_backfilled', false ) ) {
			return;
		}

		global $wpdb;

		// Find all products with _ebay_item_id but no _ebay_listing_type.
		$products = $wpdb->get_col(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->postmeta} pm2 ON pm.post_id = pm2.post_id AND pm2.meta_key = '_ebay_listing_type'
			 WHERE pm.meta_key = '_ebay_item_id'
			   AND pm.meta_value != ''
			   AND pm2.meta_id IS NULL
			 LIMIT 500"
		);

		if ( ! empty( $products ) ) {
			foreach ( $products as $product_id ) {
				update_post_meta( (int) $product_id, '_ebay_listing_type', 'FixedPriceItem' );
			}
			TCGiant_Sync_Logger::log( sprintf(
				'Listing type backfill: Set %d products to FixedPriceItem (default).',
				count( $products )
			) );
		}

		update_option( 'tcgiant_listing_type_backfilled', true );
	}

	/**
	 * Deactivation hook to clear cron.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'tcgiant_sync_poll_ebay_cron' );
		wp_clear_scheduled_hook( 'tcgiant_sync_daily_maintenance' );
		TCGiant_Sync_Image_Localizer::deactivate();
	}
}
