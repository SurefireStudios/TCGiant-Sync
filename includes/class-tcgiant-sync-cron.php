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
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );
		add_action( 'tcgiant_sync_poll_ebay_cron', array( $this, 'poll_ebay' ) );
		add_action( 'tcgiant_sync_poll_ebay_cron', array( $this, 'sync_orders' ) );
		add_action( 'tcgiant_sync_poll_ebay_cron', array( $this, 'ping_telemetry' ) );
		add_action( 'tcgiant_sync_daily_maintenance', array( $this, 'daily_maintenance' ) );
		add_action( 'tcgiant_sync_daily_maintenance', array( $this, 'auto_relist_ended_items' ) );

		// Hourly ended-listing detection. This must be registered outside the
		// admin class: TCGiant_Sync_Admin is only instantiated when is_admin(),
		// and wp-cron.php requests are not is_admin(), so the event fired with
		// no callback attached and the check never ran.
		add_action( 'tcgiant_sync_check_ended_listings', array( $this, 'check_ended_listings' ) );
		
		// One-time listing type backfill on upgrade.
		add_action( 'admin_init', array( $this, 'maybe_backfill_listing_type' ) );

		// Keep background work moving on hosts where WP-Cron is disabled or
		// unreliable — admin page views act as the heartbeat.
		add_action( 'admin_init', array( __CLASS__, 'maybe_dispatch_overdue' ) );

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

		// Hourly ended-listing detection.
		if ( ! wp_next_scheduled( 'tcgiant_sync_check_ended_listings' ) ) {
			wp_schedule_event( time(), 'hourly', 'tcgiant_sync_check_ended_listings' );
		}
	}

	/**
	 * Hourly cron: detect ended eBay listings.
	 *
	 * Marks products whose _ebay_end_time has passed as 'Ended'.
	 *
	 * Moved here from TCGiant_Sync_Admin, which is only loaded on admin
	 * requests — the scheduled event fires from wp-cron.php, so the callback
	 * was never registered when it ran.
	 */
	const ENDED_VERIFY_PER_RUN = 40;

	/**
	 * Mark listings whose end time has passed as Ended.
	 */
	public function check_ended_listings() {
		global $wpdb;

		$now_utc = gmdate( 'Y-m-d\TH:i:s.000\Z' );

		// Find products with end time in the past that aren't already marked Ended.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, pm_dur.meta_value AS duration
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_end ON p.ID = pm_end.post_id AND pm_end.meta_key = '_ebay_end_time'
			 LEFT JOIN {$wpdb->postmeta} pm_dur ON p.ID = pm_dur.post_id AND pm_dur.meta_key = '_ebay_listing_duration'
			 LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_ebay_listing_status'
			 WHERE p.post_type = 'product'
			   AND p.post_status != 'trash'
			   AND pm_end.meta_value != ''
			   AND pm_end.meta_value < %s
			   AND (pm_status.meta_value IS NULL OR pm_status.meta_value != 'Ended')
			 LIMIT 500",
			$now_utc
		) );

		if ( empty( $rows ) ) {
			return;
		}

		$ended    = 0;
		$active   = 0;
		$verified = 0;

		foreach ( $rows as $row ) {
			$pid      = (int) $row->ID;
			$duration = (string) $row->duration;

			// A fixed-length listing genuinely is over once its end time passes,
			// so the stored value can be trusted and costs nothing to act on.
			if ( '' !== $duration && 0 === strpos( $duration, 'Days_' ) ) {
				// A listing that has run its course may equally have sold out, and
				// once this product is marked Ended it drops out of this query and
				// never gets another chance to have its stock put right. So settle
				// it first, and only for a product still showing stock: there is
				// nothing to settle otherwise and no call worth spending.
				//
				// is_in_stock() rather than a quantity, so a product with stock
				// management switched off is covered as well: it has no number to
				// compare but it can still be sitting there for sale.
				$stock_product = wc_get_product( $pid );

				if ( $stock_product && $stock_product->is_in_stock() ) {
					// Out of budget for this run. Leave the product unmarked so it
					// comes back round next hour rather than being lost with its
					// stock still wrong.
					if ( $verified >= self::ENDED_VERIFY_PER_RUN ) {
						continue;
					}

					$expired_id = get_post_meta( $pid, '_ebay_item_id', true );

					if ( ! empty( $expired_id ) ) {
						$verified++;
						$expired = TCGiant_Sync_API::instance()->get_item( $expired_id );

						if ( ! is_wp_error( $expired ) && isset( $expired['Item'] ) ) {
							$settled = TCGiant_Sync_Inventory::apply_ended_listing_stock(
								$pid,
								isset( $expired['Item']['Quantity'] ) ? $expired['Item']['Quantity'] : null,
								isset( $expired['Item']['SellingStatus']['QuantitySold'] ) ? $expired['Item']['SellingStatus']['QuantitySold'] : null
							);

							if ( null !== $settled ) {
								TCGiant_Sync_Logger::log( sprintf(
									'Ended listing check: WC #%d stock set to %d now its listing has finished.',
									$pid, $settled
								) );
							}
						} else {
							// eBay drops old listings, so this can fail for good.
							// Mark it ended regardless rather than retry forever,
							// but say so: leaving the stock alone is safer than
							// zeroing it on no evidence, and the seller may need
							// to correct it by hand.
							TCGiant_Sync_Logger::log( sprintf(
								'Ended listing check: WC #%d listing has finished but eBay would not report its quantities, so stock is unchanged. Check this product by hand.',
								$pid
							), 'warning' );
						}
					}
				}

				update_post_meta( $pid, '_ebay_listing_status', 'Ended' );
				$ended++;
				continue;
			}

			// Good 'Til Cancelled is different. eBay renews it about every 30
			// days and moves the end time forward each time, so the stored value
			// is only ever a snapshot of the next renewal — not an end. Treating
			// it as authoritative marked live listings as Ended and offered a
			// "Relist" that would have created a duplicate of a listing already
			// running. The same applies when the duration is unknown, which is
			// the case for anything imported before it was recorded.
			if ( $verified >= self::ENDED_VERIFY_PER_RUN ) {
				continue;
			}

			$item_id = get_post_meta( $pid, '_ebay_item_id', true );
			if ( empty( $item_id ) ) {
				continue;
			}

			$verified++;
			$response = TCGiant_Sync_API::instance()->get_item( $item_id );

			// Never conclude "ended" from a failed call — an eBay outage would
			// otherwise mark the whole catalogue as ended.
			if ( is_wp_error( $response ) || ! isset( $response['Item'] ) ) {
				continue;
			}

			$item   = $response['Item'];
			$status = $item['SellingStatus']['ListingStatus'] ?? '';

			// Refresh the snapshot so a renewed listing drops out of this query
			// until its next renewal, rather than being re-checked every hour.
			if ( ! empty( $item['ListingDetails']['EndTime'] ) ) {
				update_post_meta( $pid, '_ebay_end_time', $item['ListingDetails']['EndTime'] );
			}
			if ( ! empty( $item['ListingDuration'] ) ) {
				update_post_meta( $pid, '_ebay_listing_duration', $item['ListingDuration'] );
			}

			if ( 'Active' === $status ) {
				update_post_meta( $pid, '_ebay_listing_status', 'Active' );

				// Back on sale, so any earlier settlement is spent: if this
				// listing ends badly the product must be reviewable again.
				TCGiant_Sync_Inventory::clear_settled_mark( $pid );

				$active++;
				continue;
			}

			if ( '' !== $status ) {
				update_post_meta( $pid, '_ebay_listing_status', 'Ended' );
				$ended++;

				// And settle the stock. Recording the status and stopping there
				// left sold goods showing as available, the same fault the hourly
				// sync had in the other place a listing is seen to end. The item
				// is already in hand here, so this costs nothing.
				$settled = TCGiant_Sync_Inventory::apply_ended_listing_stock(
					$pid,
					isset( $item['Quantity'] ) ? $item['Quantity'] : null,
					isset( $item['SellingStatus']['QuantitySold'] ) ? $item['SellingStatus']['QuantitySold'] : null
				);

				if ( null !== $settled ) {
					TCGiant_Sync_Logger::log( sprintf(
						'Ended listing check: WC #%d stock set to %d now its listing has finished.',
						$pid, $settled
					) );
				}
			}
		}

		if ( $ended > 0 || $active > 0 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Ended listing check: %d marked Ended, %d confirmed still active on eBay (%d verified via GetItem).',
				$ended, $active, $verified
			) );
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

	// ───────────────────────────────────────────────────────────────────────────
	// Cron dispatch — works where WordPress's own spawn_cron() does not
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Whether a loopback dispatch has been queued for this request.
	 *
	 * @var bool
	 */
	private static $dispatch_queued = false;

	/**
	 * Plugin cron hooks that background work depends on.
	 */
	const BACKGROUND_HOOKS = array(
		'tcgiant_sync_scan_resume',
		'tcgiant_sync_localize_images',
		'tcgiant_sync_poll_ebay_cron',
		'tcgiant_sync_import_orders',
		'tcgiant_sync_check_ended_listings',
	);

	/**
	 * Ask for WP-Cron to be poked at the end of this request.
	 *
	 * WordPress's spawn_cron() returns immediately when DOING_CRON is defined,
	 * which is precisely the situation the page scanner is in when it chains
	 * its next batch — so the kick never happened and resumption waited for the
	 * next natural tick. It also does nothing useful mid-request, because
	 * wp-cron.php would just hit the still-held "doing_cron" lock.
	 *
	 * Deferring to shutdown solves both: by then the current cron run has
	 * released its lock, so the loopback is actually able to pick up work.
	 *
	 * @return void
	 */
	public static function request_dispatch() {
		if ( self::$dispatch_queued ) {
			return;
		}
		self::$dispatch_queued = true;
		add_action( 'shutdown', array( __CLASS__, 'dispatch_now' ), 100 );
	}

	/**
	 * Fire a non-blocking loopback request at wp-cron.php.
	 *
	 * Unlike spawn_cron(), this works with DISABLE_WP_CRON set: that constant
	 * only stops WordPress spawning cron automatically on page loads, it does
	 * not stop wp-cron.php running when something asks it to.
	 *
	 * @return void
	 */
	public static function dispatch_now() {
		self::$dispatch_queued = false;

		// Respect the same lock core uses, so we never stampede an in-flight run.
		$now  = microtime( true );
		$lock = (float) get_transient( 'doing_cron' );
		$timeout = defined( 'WP_CRON_LOCK_TIMEOUT' ) ? WP_CRON_LOCK_TIMEOUT : 60;

		if ( $lock > $now + 10 * MINUTE_IN_SECONDS ) {
			$lock = 0; // Nonsensical future value; treat as stale.
		}
		if ( $lock + $timeout > $now ) {
			return; // A cron run is already in progress.
		}

		$doing_wp_cron = sprintf( '%.22F', $now );
		set_transient( 'doing_cron', $doing_wp_cron );

		$cron_request = apply_filters(
			'cron_request',
			array(
				'url'  => add_query_arg( 'doing_wp_cron', $doing_wp_cron, site_url( 'wp-cron.php' ) ),
				'key'  => $doing_wp_cron,
				'args' => array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				),
			),
			$doing_wp_cron
		);

		wp_remote_post( $cron_request['url'], $cron_request['args'] );
	}

	/**
	 * Opportunistically poke cron when the plugin has overdue background work.
	 *
	 * Hosts that set DISABLE_WP_CRON without configuring a replacement system
	 * cron leave every scheduled task stranded — most visibly image
	 * localization, so products keep pointing at eBay-hosted images that die
	 * when the listing ends. Admin page views are a reliable source of requests
	 * on an active store, so they are used to keep things moving.
	 *
	 * Throttled, and only when something is actually overdue.
	 *
	 * @return void
	 */
	public static function maybe_dispatch_overdue() {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		if ( get_transient( 'tcgiant_cron_heartbeat' ) ) {
			return;
		}
		set_transient( 'tcgiant_cron_heartbeat', 1, MINUTE_IN_SECONDS );

		$now = time();
		foreach ( self::BACKGROUND_HOOKS as $hook ) {
			$next = wp_next_scheduled( $hook );
			if ( $next && $next <= $now ) {
				self::request_dispatch();
				return;
			}
		}
	}

	/**
	 * Run every plugin cron event that is currently due, in this request.
	 *
	 * Used by the "Process Queue" button, which promises to run pending
	 * background work immediately. Action Scheduler's queue runner only covers
	 * Action Scheduler; the page-scan resume and image localization are WP-Cron
	 * events, so on a host with broken cron — precisely when someone reaches
	 * for that button — draining Action Scheduler alone achieves nothing.
	 *
	 * @return array{ran:string[], due:int} Hooks executed, and how many were due.
	 */
	public static function run_due_events_now() {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		ignore_user_abort( true );

		$ran = array();
		$now = time();

		foreach ( self::BACKGROUND_HOOKS as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( ! $timestamp || $timestamp > $now ) {
				continue;
			}

			// Take the event off the schedule before running it, so a crash
			// cannot leave it looping, and re-entrant scheduling inside the
			// callback still works.
			wp_unschedule_event( $timestamp, $hook );

			try {
				do_action( $hook );
				$ran[] = $hook;
			} catch ( Exception $e ) {
				TCGiant_Sync_Logger::error( sprintf( 'Manual queue run: %s failed — %s', $hook, $e->getMessage() ) );
			} catch ( Error $e ) {
				TCGiant_Sync_Logger::error( sprintf( 'Manual queue run: %s fatal — %s', $hook, $e->getMessage() ) );
			}
		}

		return array( 'ran' => $ran, 'due' => count( $ran ) );
	}

	/**
	 * Human-readable label for a plugin cron hook.
	 *
	 * @param string $hook Hook name.
	 * @return string
	 */
	public static function describe_hook( $hook ) {
		$labels = array(
			'tcgiant_sync_scan_resume'          => __( 'continued the eBay scan', 'tcgiant-sync' ),
			'tcgiant_sync_localize_images'      => __( 'downloaded pending images', 'tcgiant-sync' ),
			'tcgiant_sync_poll_ebay_cron'       => __( 'ran the scheduled eBay sync', 'tcgiant-sync' ),
			'tcgiant_sync_import_orders'        => __( 'imported eBay orders', 'tcgiant-sync' ),
			'tcgiant_sync_check_ended_listings' => __( 'checked for ended listings', 'tcgiant-sync' ),
		);
		return $labels[ $hook ] ?? $hook;
	}

	/**
	 * Report on how healthy scheduled background work looks.
	 *
	 * @return array{disabled:bool, overdue:string[], alternate:bool}
	 */
	public static function get_cron_health() {
		$overdue = array();
		$now     = time();

		foreach ( self::BACKGROUND_HOOKS as $hook ) {
			$next = wp_next_scheduled( $hook );
			// More than 15 minutes late is well beyond normal cron jitter.
			if ( $next && ( $now - $next ) > 15 * MINUTE_IN_SECONDS ) {
				$overdue[] = $hook;
			}
		}

		return array(
			'disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'overdue'   => $overdue,
			'alternate' => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
		);
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
		// The logger writes to wp-content/uploads/tcgiant-sync/, not to a
		// "logs" directory inside the plugin folder. Pointing at the wrong
		// path meant this rotation task had never deleted anything.
		$log_dir = trailingslashit( TCGiant_Sync_Logger::get_log_dir() );
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
		if ( ! TCGiant_Sync_DB::table_exists() ) {
			return;
		}

		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
		$logs_table = $wpdb->prefix . 'actionscheduler_logs';

		// Collect the ids first so the matching log rows can go too. Deleting
		// only from the actions table left orphaned log rows accumulating
		// forever, which is the larger of the two tables.
		$action_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT action_id FROM {$table}
			 WHERE hook LIKE %s
			   AND status IN ('complete', 'failed', 'canceled')
			   AND scheduled_date_gmt < %s
			 LIMIT 5000",
			'tcgiant_sync_%',
			$cutoff
		) );

		if ( empty( $action_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $action_ids ), '%d' ) );

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$logs_table} WHERE action_id IN ({$placeholders})",
			$action_ids
		) );

		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE action_id IN ({$placeholders})",
			$action_ids
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
		if ( ! TCGiant_Sync_DB::table_exists() ) {
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
			//
			// is_in_stock() is checked as well as the quantity because a variable
			// product usually does not manage stock itself — its variations do — so
			// the quantity test alone passed products with nothing left to sell,
			// and eBay refused the relist.
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->is_in_stock() || ( $product->managing_stock() && $product->get_stock_quantity() <= 0 ) ) {
				$skipped++;
				continue;
			}

			// A listing with variations has to be relisted through eBay's
			// fixed-price call. Through the ordinary one eBay relists the item and
			// silently discards the variations, which would leave the seller with a
			// live listing that has nothing in it to buy.
			$result = TCGiant_Sync_Exporter::instance()->uses_fixed_price_calls( $product, array(), $ebay_item_id )
				? $api->relist_fixed_price_item( $ebay_item_id )
				: $api->relist_item( $ebay_item_id );
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

		$batch_size = 500;

		if ( ! empty( $products ) ) {
			foreach ( $products as $product_id ) {
				update_post_meta( (int) $product_id, '_ebay_listing_type', 'FixedPriceItem' );
			}
			TCGiant_Sync_Logger::log( sprintf(
				'Listing type backfill: Set %d products to FixedPriceItem (default).',
				count( $products )
			) );
		}

		// Only declare the migration finished once a batch comes back short.
		// Marking it done after the first 500 rows left every store with more
		// than 500 eBay-linked products permanently half-backfilled — the
		// remainder never got a listing type, so they were excluded from the
		// listing-type filters and the auto-relist query.
		if ( count( $products ) < $batch_size ) {
			update_option( 'tcgiant_listing_type_backfilled', true );
		}
	}

	/**
	 * Deactivation hook to clear cron.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'tcgiant_sync_poll_ebay_cron' );
		wp_clear_scheduled_hook( 'tcgiant_sync_daily_maintenance' );
		wp_clear_scheduled_hook( 'tcgiant_sync_check_ended_listings' );
		wp_clear_scheduled_hook( 'tcgiant_sync_reconcile_inventory' );
		wp_clear_scheduled_hook( 'tcgiant_sync_import_orders' );
		wp_clear_scheduled_hook( 'tcgiant_sync_scan_resume' );
		TCGiant_Sync_Image_Localizer::deactivate();
	}
}
