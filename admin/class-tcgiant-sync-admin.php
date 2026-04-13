<?php
/**
 * Admin Logic
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Admin class
 */
class TCGiant_Sync_Admin {

	/**
	 * Instance of this class.
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
	 * TCGiant_Sync_Admin Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_tcgiant_sync_now', array( $this, 'handle_manual_sync' ) );
		add_action( 'admin_post_tcgiant_force_queue', array( $this, 'handle_force_queue' ) );
		add_action( 'admin_post_tcgiant_stop_sync', array( $this, 'handle_stop_sync' ) );
		add_action( 'admin_post_tcgiant_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		
		// AJAX endpoints.
		add_action( 'wp_ajax_tcgiant_sync_status', array( $this, 'ajax_sync_status' ) );
		add_action( 'wp_ajax_tcgiant_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_tcgiant_get_store_categories', array( $this, 'ajax_get_store_categories' ) );
		add_action( 'wp_ajax_tcgiant_activate_license', array( $this, 'ajax_activate_license' ) );
		add_action( 'wp_ajax_tcgiant_deactivate_license', array( $this, 'ajax_deactivate_license' ) );

		// WooCommerce Product Metabox Hooks
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_sync_log_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_sync_log_panel' ) );
	}

	/**
	 * Enqueue admin assets.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_tcgiant-sync' !== $hook ) {
			return;
		}

		$css_ver = filemtime( TCGIANT_SYNC_PATH . 'admin/assets/css/admin.css' );
		$js_ver  = filemtime( TCGIANT_SYNC_PATH . 'admin/assets/js/admin.js' );
		wp_enqueue_style( 'tcgiant-sync-admin', TCGIANT_SYNC_URL . 'admin/assets/css/admin.css', array(), $css_ver );
		wp_enqueue_script( 'tcgiant-sync-admin', TCGIANT_SYNC_URL . 'admin/assets/js/admin.js', array( 'jquery' ), $js_ver, true );

		$license = TCGiant_Sync_License::instance();
		wp_localize_script( 'tcgiant-sync-admin', 'tcgiantSync', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tcgiant_sync_ajax' ),
			'license' => $license->get_status_for_ui(),
		) );
	}

	/**
	 * Handle manual sync request.
	 */
	public function handle_manual_sync() {
		check_admin_referer( 'tcgiant_sync_now' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		TCGiant_Sync_Importer::instance()->start_full_sync( true );
		wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-sync&sync_started=1' ) );
		exit;
	}

	/**
	 * Handle manual processing of the Action Scheduler queue.
	 */
	public function handle_force_queue() {
		check_admin_referer( 'tcgiant_force_queue' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
			ActionScheduler_QueueRunner::instance()->run();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-sync&queue_processed=1' ) );
		exit;
	}

	/**
	 * Handle stopping the sync completely.
	 */
	public function handle_stop_sync() {
		check_admin_referer( 'tcgiant_stop_sync' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_listings', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_group' );
		}

		TCGiant_Sync_Importer::update_sync_state( array( 'status' => 'stopped' ) );
		TCGiant_Sync_Logger::log( 'Emergency Stop: All sync jobs cleared.', 'warning' );

		wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-sync' ) );
		exit;
	}

	/**
	 * Handle clearing the log file.
	 */
	public function handle_clear_log() {
		check_admin_referer( 'tcgiant_clear_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		TCGiant_Sync_Logger::clear();
		wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-sync&log_cleared=1' ) );
		exit;
	}

	/**
	 * Register menu pages.
	 */
	public function add_menu_pages() {
		add_menu_page(
			__( 'TCGiant Sync', 'tcgiant-sync' ),
			__( 'TCGiant Sync', 'tcgiant-sync' ),
			'manage_options',
			'tcgiant-sync',
			array( $this, 'render_dashboard_page' ),
			'dashicons-update',
			56
		);
	}

	/**
	 * Render Dashboard.
	 */
	public function render_dashboard_page() {
		include_once TCGIANT_SYNC_PATH . 'admin/views/dashboard.php';
	}

	/**
	 * Handle OAuth Callback.
	 */
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || ( 'tcgiant-sync-settings' !== $_GET['page'] && 'tcgiant-sync' !== $_GET['page'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ebay_access_token'], $_GET['ebay_refresh_token'], $_GET['ebay_expires_in'] ) ) {
			$oauth = TCGiant_Sync_OAuth::instance();
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$data = array(
				'access_token'  => sanitize_text_field( wp_unslash( $_GET['ebay_access_token'] ) ),
				'refresh_token' => sanitize_text_field( wp_unslash( $_GET['ebay_refresh_token'] ) ),
				'expires_in'    => sanitize_text_field( wp_unslash( $_GET['ebay_expires_in'] ) ),
			);
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( $oauth->save_tokens_from_relay( $data ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-sync&auth=success' ) );
				exit;
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-sync&auth=failed' ) );
				exit;
			}
		}
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting( 'tcgiant_sync_ebay_group', 'tcgiant_sync_ebay_settings', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );
	}

	/**
	 * Sanitize settings array.
	 *
	 * @param array $input Input settings.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();
		if ( is_array( $input ) ) {
			foreach ( $input as $key => $value ) {
				$sanitized[ sanitize_key( $key ) ] = sanitize_text_field( $value );
			}
		}
		return $sanitized;
	}

	/**
	 * AJAX: Get live sync status (polled by dashboard JS).
	 */
	public function ajax_sync_status() {
		check_ajax_referer( 'tcgiant_sync_ajax' );
		
		$state = TCGiant_Sync_Importer::get_sync_state();

		// Self-heal: if status says 'importing' but no pending jobs remain, auto-complete.
		if ( 'importing' === $state['status'] && function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions( array(
				'hook'     => 'tcgiant_sync_process_item_import',
				'group'    => 'tcgiant_sync_group',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			) );
			if ( empty( $pending ) ) {
				TCGiant_Sync_Importer::update_sync_state( array(
					'status'         => 'complete',
					'last_completed' => current_time( 'mysql' ),
				) );
				TCGiant_Sync_Logger::log( sprintf(
					'Sync complete! %d imported, %d errors out of %d total.',
					$state['total_processed'], $state['total_errors'], $state['total_queued']
				), 'success' );
				$state = TCGiant_Sync_Importer::get_sync_state(); // Refresh.
			}
		}

		$stats = $this->get_sync_stats();
		$license = TCGiant_Sync_License::instance();
		
		wp_send_json_success( array(
			'state'   => $state,
			'stats'   => $stats,
			'license' => $license->get_status_for_ui(),
		) );
	}

	/**
	 * AJAX: Get recent logs as HTML.
	 */
	public function ajax_get_logs() {
		check_ajax_referer( 'tcgiant_sync_ajax' );
		
		$entries = TCGiant_Sync_Logger::get_recent_entries( 20 );
		
		ob_start();
		if ( empty( $entries ) ) {
			echo '<div class="tc-log-entry tc-log-empty">No activity recorded yet.</div>';
		} else {
			foreach ( $entries as $entry ) {
				$level_class = '';
				$icon = '[Log]';
				switch ( $entry['level'] ) {
					case 'error':
						$level_class = 'tc-is-error';
						$icon = '[X]';
						break;
					case 'success':
						$level_class = 'tc-is-success';
						$icon = '[OK]';
						break;
					case 'warning':
						$level_class = 'tc-is-warning';
						$icon = '[!]';
						break;
				}
				printf(
					'<div class="tc-log-entry %s"><span class="tc-log-icon">%s</span><span class="tc-log-time">%s</span><span class="tc-log-msg">%s</span></div>',
					esc_attr( $level_class ),
					esc_html( $icon ),
					esc_html( $entry['timestamp'] ),
					esc_html( $entry['message'] )
				);
			}
		}
		$html = ob_get_clean();
		
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * AJAX: Get eBay Store Categories for the dropdown selector.
	 */
	public function ajax_get_store_categories() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! TCGiant_Sync_OAuth::instance()->is_authenticated() ) {
			wp_send_json_error( array( 'message' => 'Not connected to eBay.' ) );
		}

		$api = TCGiant_Sync_API::instance();
		$store_response = $api->get_store();

		if ( is_wp_error( $store_response ) ) {
			wp_send_json_error( array( 'message' => $store_response->get_error_message() ) );
		}

		$categories = array();
		if ( isset( $store_response['Store']['CustomCategories']['CustomCategory'] ) ) {
			$raw = $store_response['Store']['CustomCategories']['CustomCategory'];
			if ( isset( $raw['CategoryID'] ) ) {
				$raw = array( $raw );
			}

			$flatten = function( $cats, $depth = 0 ) use ( &$flatten, &$categories ) {
				if ( ! is_array( $cats ) ) return;
				foreach ( $cats as $cat ) {
					$prefix = str_repeat( '- ', $depth );
					$categories[] = array(
						'id'   => $cat['CategoryID'] ?? '',
						'name' => $prefix . ( $cat['Name'] ?? '' ),
						'raw'  => $cat['Name'] ?? '',
					);
					if ( isset( $cat['ChildCategory'] ) ) {
						$children = isset( $cat['ChildCategory']['CategoryID'] ) ? array( $cat['ChildCategory'] ) : $cat['ChildCategory'];
						$flatten( $children, $depth + 1 );
					}
				}
			};
			$flatten( $raw );
		}

		wp_send_json_success( array( 'categories' => $categories ) );
	}

	/**
	 * Get System Health Status.
	 */
	public function get_system_health() {
		$oauth = TCGiant_Sync_OAuth::instance();
		$settings = $oauth->get_settings();
		$health = array();

		// Attempt to fetch and cache Store Name if authenticated but unknown.
		if ( $oauth->is_authenticated() && empty( $settings['store_name'] ) ) {
			$api = TCGiant_Sync_API::instance();
			$locations = $api->request( 'sell/inventory/v1/location', 'GET' );
			
			if ( ! is_wp_error( $locations ) && ! empty( $locations['locations'][0]['name'] ) ) {
				$settings['store_name'] = $locations['locations'][0]['name'];
			} elseif ( ! is_wp_error( $locations ) && ! empty( $locations['locations'][0]['merchantLocationKey'] ) ) {
				$settings['store_name'] = $locations['locations'][0]['merchantLocationKey'];
			} else {
				$settings['store_name'] = 'Authenticated Account';
			}
			update_option( 'tcgiant_sync_ebay_settings', $settings );
		}

		$health['connection'] = array(
			'label'  => __( 'eBay Connection', 'tcgiant-sync' ),
			'status' => $oauth->is_authenticated() ? 'active' : 'inactive',
			'text'   => $oauth->is_authenticated() ? __( 'Connected', 'tcgiant-sync' ) : __( 'Disconnected', 'tcgiant-sync' ),
		);

		if ( $oauth->is_authenticated() ) {
			$health['store'] = array(
				'label'  => __( 'Connected Store', 'tcgiant-sync' ),
				'status' => 'active',
				'text'   => $settings['store_name'] ?? 'Unknown',
			);
		}

		// Proactively refresh the token if needed before checking health.
		if ( $oauth->is_authenticated() ) {
			$oauth->get_access_token();
			$settings = $oauth->get_settings(); // Refresh local settings array with new expiry.
		}

		// Token Expiry.
		if ( $oauth->is_authenticated() && isset( $settings['token_expiry'] ) ) {
			$remaining = $settings['token_expiry'] - time();
			$health['token'] = array(
				'label'  => __( 'Token Health', 'tcgiant-sync' ),
				'status' => $remaining > 3600 ? 'active' : ( $remaining > 0 ? 'warning' : 'inactive' ),
				/* translators: %s: time remaining until token expires */
				'text'   => $remaining > 0 ? sprintf( __( 'Expires in %s', 'tcgiant-sync' ), human_time_diff( time(), $settings['token_expiry'] ) ) : __( 'Expired', 'tcgiant-sync' ),
			);
		}

		// Cron Status - FIXED: use correct hook name.
		$cron_active = wp_next_scheduled( 'tcgiant_sync_poll_ebay_cron' );
		$health['cron'] = array(
			'label'  => __( 'Auto-Sync Scheduler', 'tcgiant-sync' ),
			'status' => $cron_active ? 'active' : 'inactive',
			/* translators: %s: time remaining until next sync */
			'text'   => $cron_active ? sprintf( __( 'Next: %s', 'tcgiant-sync' ), human_time_diff( time(), $cron_active ) ) : __( 'Disabled', 'tcgiant-sync' ),
		);

		return $health;
	}

	/**
	 * Get sync statistics.
	 */
	public function get_sync_stats() {
		global $wpdb;

		// Count WooCommerce products synced from eBay.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$synced_products = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = 'product'
			 AND p.post_status IN ('publish','draft')
			 AND pm.meta_key = '_ebay_item_id'
			 AND pm.meta_value != ''"
		);

		// Pending Action Scheduler jobs.
		$pending_jobs = 0;
		if ( class_exists( 'ActionScheduler' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pending_jobs = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions
				 WHERE `group_id` IN (SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = 'tcgiant_sync_group')
				 AND status = 'pending'"
			);
		}

		$state = TCGiant_Sync_Importer::get_sync_state();

		return array(
			'synced_products' => $synced_products,
			'pending_jobs'    => $pending_jobs,
			'last_completed'  => $state['last_completed'] ?? '',
		);
	}

	/**
	 * AJAX: Activate a license key.
	 */
	public function ajax_activate_license() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		$license = TCGiant_Sync_License::instance();
		$result = $license->activate_license( $key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => $result['message'],
			'license' => $license->get_status_for_ui(),
		) );
	}

	/**
	 * AJAX: Deactivate the current license.
	 */
	public function ajax_deactivate_license() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$license = TCGiant_Sync_License::instance();
		$result = $license->deactivate_license();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => $result['message'],
			'license' => $license->get_status_for_ui(),
		) );
	}

	/**
	 * Add custom tab to WooCommerce Product Data metabox.
	 *
	 * @param array $tabs Current tabs.
	 * @return array
	 */
	public function add_sync_log_tab( $tabs ) {
		$tabs['tcgiant_sync_log'] = array(
			'label'    => __( 'eBay Sync Log', 'tcgiant-sync' ),
			'target'   => 'tcgiant_sync_log_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 90,
		);
		return $tabs;
	}

	/**
	 * Render the custom tab content.
	 */
	public function render_sync_log_panel() {
		global $post;

		echo '<div id="tcgiant_sync_log_data" class="panel woocommerce_options_panel hide_if_grouped hide_if_external">';
		
		$logs = get_post_meta( $post->ID, '_tcgiant_sync_log', true );
		
		if ( empty( $logs ) || ! is_array( $logs ) ) {
			echo '<div style="padding:15px;"><p>' . esc_html__( 'This product has not synced yet, or no sync decisions have been logged.', 'tcgiant-sync' ) . '</p></div>';
		} else {
			echo '<div style="padding:15px; max-height: 400px; overflow-y: auto;">';
			echo '<p style="margin-top:0;"><strong>' . esc_html__( 'Recent Sync Decisions (Last 20)', 'tcgiant-sync' ) . '</strong></p>';
			foreach ( $logs as $entry ) {
				echo '<div style="margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #eee;">';
				echo '<strong style="color: #666; font-size: 11px;">' . esc_html( $entry['timestamp'] ?? 'Unknown Time' ) . '</strong>';
				if ( ! empty( $entry['decisions'] ) && is_array( $entry['decisions'] ) ) {
					echo '<ul style="margin-top: 4px; padding-left: 16px; margin-bottom: 0; list-style-type: disc;">';
					foreach ( $entry['decisions'] as $decision ) {
						echo '<li>' . esc_html( $decision ) . '</li>';
					}
					echo '</ul>';
				}
				echo '</div>';
			}
			echo '</div>';
		}

		echo '</div>';
	}
}
