<?php
/**
 * Cron Tasks
 *
 * Handles scheduled synchronization tasks.
 *
 * @package TCGiant_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Cron class
 */
class TCGiant_Sync_Cron {

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
	}

	/**
	 * Add custom cron intervals.
	 */
	public function add_cron_intervals( $schedules ) {
		$schedules['tcgiant_15mins'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => esc_html__( 'Every 15 Minutes', 'tcgiant-sync' ),
		);
		$schedules['tcgiant_hourly'] = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => esc_html__( 'Hourly', 'tcgiant-sync' ),
		);
		return $schedules;
	}

	/**
	 * Run the polling task.
	 */
	public function poll_ebay() {
		TCGiant_Sync_Logger::log( 'WP-Cron: Starting scheduled eBay poll...' );
		TCGiant_Sync_Importer::instance()->start_full_sync();
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
	 * Deactivation hook to clear cron.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'tcgiant_sync_poll_ebay_cron' );
	}
}
