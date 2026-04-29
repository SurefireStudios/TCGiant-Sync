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
		add_action( 'wp_ajax_tcgiant_sync_order_to_ebay', array( $this, 'ajax_sync_order_to_ebay' ) );
		add_action( 'admin_post_tcgiant_stop_sync', array( $this, 'handle_stop_sync' ) );
		add_action( 'admin_post_tcgiant_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		
		// AJAX endpoints — Import.
		add_action( 'wp_ajax_tcgiant_sync_status', array( $this, 'ajax_sync_status' ) );
		add_action( 'wp_ajax_tcgiant_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_tcgiant_get_store_categories', array( $this, 'ajax_get_store_categories' ) );
		add_action( 'wp_ajax_tcgiant_activate_license', array( $this, 'ajax_activate_license' ) );
		add_action( 'wp_ajax_tcgiant_deactivate_license', array( $this, 'ajax_deactivate_license' ) );

		// AJAX endpoints — Export.
		add_action( 'wp_ajax_tcgiant_push_product', array( $this, 'ajax_push_product' ) );
		add_action( 'wp_ajax_tcgiant_fetch_policies', array( $this, 'ajax_fetch_policies' ) );
		add_action( 'wp_ajax_tcgiant_export_status', array( $this, 'ajax_export_status' ) );

		// Bulk action on WooCommerce Products list.
		add_filter( 'bulk_actions-edit-product', array( $this, 'register_bulk_push_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_push_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_push_admin_notice' ) );

		// Save per-product export overrides.
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_export_meta' ) );

		// WooCommerce Product Metabox Hooks.
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_sync_log_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_sync_log_panel' ) );
	}

	/**
	 * Enqueue admin assets.
	 */
	public function enqueue_admin_assets( $hook ) {
		$tc_pages = array(
			'toplevel_page_tcgiant-sync',
			'tcgiant-sync_page_tcgiant-import',
			'tcgiant-sync_page_tcgiant-export',
			'tcgiant-sync_page_tcgiant-settings',
		);
		if ( ! in_array( $hook, $tc_pages, true ) ) {
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
	 * AJAX: Sync a specific WooCommerce order's stock directly to eBay.
	 *
	 * This bypasses Action Scheduler and WP-Cron entirely — it reads the
	 * order items, gets their current WooCommerce stock, and calls the eBay
	 * API directly and synchronously. Nothing else runs as a side effect.
	 */
	public function ajax_sync_order_to_ebay() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => 'Invalid order ID.' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Order not found.' ) );
		}

		$api     = TCGiant_Sync_API::instance();
		$results = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$sku      = $product->get_meta( '_ebay_sku' ) ?: $product->get_sku();
			$new_qty  = $product->get_stock_quantity();
			$name     = $product->get_name();

			if ( empty( $sku ) ) {
				$results[] = array(
					'product' => $name,
					'status'  => 'skipped',
					'message' => 'No SKU set — cannot identify on eBay.',
				);
				continue;
			}

			TCGiant_Sync_Logger::log( sprintf(
				'Manual order sync: Pushing stock for SKU %s (Order #%d) → Qty %d',
				$sku, $order_id, (int) $new_qty
			) );

			// Direct API call — no queue, no cron, no side effects.
			$result = $api->update_inventory_item_availability( $sku, (int) $new_qty );

			if ( is_wp_error( $result ) ) {
				if ( 'not_found_on_ebay' === $result->get_error_code() ) {
					$results[] = array(
						'product' => $name,
						'sku'     => $sku,
						'status'  => 'skipped',
						'message' => 'Not linked to an eBay listing.',
					);
				} else {
					TCGiant_Sync_Logger::error( sprintf(
						'Manual order sync failed for SKU %s: %s',
						$sku, $result->get_error_message()
					) );
					$results[] = array(
						'product' => $name,
						'sku'     => $sku,
						'status'  => 'error',
						'message' => $result->get_error_message(),
					);
				}
			} else {
				TCGiant_Sync_Logger::log( sprintf(
					'Manual order sync success: SKU %s set to Qty %d on eBay.',
					$sku, (int) $new_qty
				), 'success' );
				$results[] = array(
					'product' => $name,
					'sku'     => $sku,
					'status'  => 'success',
					'message' => (int) $new_qty === 0
						? 'eBay listing ended (sold out)'
						: 'eBay stock set to ' . (int) $new_qty,
				);
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
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

		add_submenu_page(
			'tcgiant-sync',
			__( 'Dashboard', 'tcgiant-sync' ),
			__( 'Dashboard', 'tcgiant-sync' ),
			'manage_options',
			'tcgiant-sync',
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			'tcgiant-sync',
			__( 'Import from eBay', 'tcgiant-sync' ),
			__( 'Import from eBay', 'tcgiant-sync' ),
			'manage_options',
			'tcgiant-import',
			array( $this, 'render_import_page' )
		);

		add_submenu_page(
			'tcgiant-sync',
			__( 'Push to eBay', 'tcgiant-sync' ),
			__( 'Push to eBay', 'tcgiant-sync' ),
			'manage_options',
			'tcgiant-export',
			array( $this, 'render_export_page' )
		);

		add_submenu_page(
			'tcgiant-sync',
			__( 'Settings', 'tcgiant-sync' ),
			__( 'Settings', 'tcgiant-sync' ),
			'manage_options',
			'tcgiant-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render Dashboard.
	 */
	public function render_dashboard_page() {
		include_once TCGIANT_SYNC_PATH . 'admin/views/dashboard.php';
	}

	/**
	 * Render Import from eBay page.
	 */
	public function render_import_page() {
		include_once TCGIANT_SYNC_PATH . 'admin/views/import.php';
	}

	/**
	 * Render Push to eBay page.
	 */
	public function render_export_page() {
		include_once TCGIANT_SYNC_PATH . 'admin/views/export.php';
	}

	/**
	 * Render Settings page.
	 */
	public function render_settings_page() {
		include_once TCGIANT_SYNC_PATH . 'admin/views/settings.php';
	}

	/**
	 * Handle OAuth Callback.
	 */
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || ! in_array( $_GET['page'], array( 'tcgiant-sync', 'tcgiant-settings' ), true ) ) {
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

	public function sanitize_settings( $input ) {
		$sanitized = array();
		if ( is_array( $input ) ) {
			foreach ( $input as $key => $value ) {
				if ( is_array( $value ) ) {
					$sanitized[ sanitize_key( $key ) ] = array_map( 'sanitize_text_field', $value );
				} else {
					$sanitized[ sanitize_key( $key ) ] = sanitize_text_field( $value );
				}
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

	// =========================================================================
	// Export / Push to eBay — Admin Methods
	// =========================================================================

	/**
	 * AJAX: Push a single product to eBay from the product edit screen.
	 */
	public function ajax_push_product() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => 'Invalid product ID.' ) );
		}

		$exporter = TCGiant_Sync_Exporter::instance();
		$queued   = $exporter->push_product( $product_id );

		if ( $queued ) {
			wp_send_json_success( array( 'message' => 'Product queued for push to eBay. Check the Activity Log for results.' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Could not queue product. It may not exist.' ) );
		}
	}

	/**
	 * AJAX: Fetch business policies from eBay and return as JSON.
	 */
	public function ajax_fetch_policies() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		if ( ! TCGiant_Sync_OAuth::instance()->is_authenticated() ) {
			wp_send_json_error( array( 'message' => 'Not connected to eBay.' ) );
		}

		// Bust cache so we always get fresh data on explicit refresh.
		TCGiant_Sync_Exporter::clear_policy_cache();

		$fulfillment = TCGiant_Sync_Exporter::get_policies( 'fulfillment' );
		$return      = TCGiant_Sync_Exporter::get_policies( 'return' );
		$payment     = TCGiant_Sync_Exporter::get_policies( 'payment' );

		if ( is_wp_error( $fulfillment ) ) {
			$raw_msg = $fulfillment->get_error_message();

			// 403 means the current OAuth token was issued without the sell.account scope.
			// The user must re-authenticate via Settings → Reconnect to get a new token.
			if ( false !== strpos( $raw_msg, '403' ) ) {
				wp_send_json_error( array(
					'message' => 'Permission denied (403). Your eBay token was issued without the required account scope. Go to Settings → Reconnect to re-authenticate, then try again.',
				) );
			}

			wp_send_json_error( array(
				'message' => 'Could not fetch policies: ' . $raw_msg . '. If this persists, try reconnecting to eBay via Settings.',
			) );
		}

		wp_send_json_success( array(
			'fulfillment' => is_array( $fulfillment ) ? $fulfillment : array(),
			'return'      => is_array( $return )      ? $return      : array(),
			'payment'     => is_array( $payment )     ? $payment     : array(),
		) );
	}

	/**
	 * AJAX: Return current export queue state for dashboard polling.
	 */
	public function ajax_export_status() {
		check_ajax_referer( 'tcgiant_sync_ajax' );
		wp_send_json_success( array(
			'state' => TCGiant_Sync_Exporter::get_export_state(),
		) );
	}

	/**
	 * Register "Push to eBay" as a WooCommerce bulk action.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function register_bulk_push_action( $actions ) {
		$actions['tcgiant_push_to_ebay'] = __( 'Push to eBay', 'tcgiant-sync' );
		return $actions;
	}

	/**
	 * Handle the "Push to eBay" bulk action.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action name.
	 * @param int[]  $post_ids    Selected product IDs.
	 * @return string Modified redirect URL.
	 */
	public function handle_bulk_push_action( $redirect_to, $action, $post_ids ) {
		if ( 'tcgiant_push_to_ebay' !== $action ) {
			return $redirect_to;
		}

		$exporter = TCGiant_Sync_Exporter::instance();
		$queued   = $exporter->bulk_push_products( $post_ids );

		return add_query_arg(
			array(
				'tcgiant_pushed' => $queued,
				'post_type'      => 'product',
			),
			$redirect_to
		);
	}

	/**
	 * Display admin notice after bulk push is queued.
	 */
	public function bulk_push_admin_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST['tcgiant_pushed'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$count = (int) $_REQUEST['tcgiant_pushed'];
		echo '<div class="notice notice-success is-dismissible"><p>';
		printf(
			/* translators: %d: number of products queued */
			esc_html( _n( '%d product queued for push to eBay.', '%d products queued for push to eBay.', $count, 'tcgiant-sync' ) ),
			(int) $count
		);
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=tcgiant-sync' ) ) . '">' . esc_html__( 'View progress →', 'tcgiant-sync' ) . '</a></p></div>';
	}

	/**
	 * Save per-product eBay export override fields.
	 *
	 * @param int $post_id WooCommerce Product ID.
	 */
	public function save_product_export_meta( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['_ebay_export_category_id'] ) ) {
			update_post_meta( $post_id, '_ebay_export_category_id', sanitize_text_field( wp_unslash( $_POST['_ebay_export_category_id'] ) ) );
		}
		if ( isset( $_POST['_ebay_export_condition_id'] ) ) {
			update_post_meta( $post_id, '_ebay_export_condition_id', sanitize_text_field( wp_unslash( $_POST['_ebay_export_condition_id'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
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
	 * Render the custom tab content — includes eBay export controls and sync log.
	 */
	public function render_sync_log_panel() {
		global $post;
		$product_id = $post->ID;

		echo '<div id="tcgiant_sync_log_data" class="panel woocommerce_options_panel hide_if_grouped hide_if_external">';
		echo '<div style="padding:15px;">';

		// ---- Push to eBay Section ----
		$ebay_item_id   = get_post_meta( $product_id, '_ebay_item_id', true );
		$export_status  = get_post_meta( $product_id, '_ebay_export_status', true );
		$export_error   = get_post_meta( $product_id, '_ebay_export_error', true );
		$last_pushed    = get_post_meta( $product_id, '_ebay_export_last_pushed', true );
		$cat_override   = get_post_meta( $product_id, '_ebay_export_category_id', true );
		$cond_override  = get_post_meta( $product_id, '_ebay_export_condition_id', true );

		$global_settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$global_cat      = $global_settings['export_category_id'] ?? '';

		echo '<div style="border:1px solid #ddd; border-radius:4px; padding:12px; margin-bottom:16px; background:#fafafa;">';
		echo '<h4 style="margin:0 0 10px; font-size:13px; color:#23282d;">📤 ' . esc_html__( 'Push to eBay', 'tcgiant-sync' ) . '</h4>';

		// eBay listing status.
		if ( ! empty( $ebay_item_id ) ) {
			$listing_url = 'https://www.ebay.com/itm/' . esc_attr( $ebay_item_id );
			echo '<p style="margin:0 0 8px;"><strong>' . esc_html__( 'eBay Item ID:', 'tcgiant-sync' ) . '</strong> ';
			echo '<a href="' . esc_url( $listing_url ) . '" target="_blank">' . esc_html( $ebay_item_id ) . ' ↗</a>';
			if ( $last_pushed ) {
				echo ' <span style="color:#888; font-size:11px;">(' . esc_html__( 'Last pushed:', 'tcgiant-sync' ) . ' ' . esc_html( $last_pushed ) . ')</span>';
			}
			echo '</p>';
		}

		// Export error notice.
		if ( 'error' === $export_status && ! empty( $export_error ) ) {
			echo '<p style="color:#cc1818; background:#fff0f0; border:1px solid #fcc; padding:6px 8px; border-radius:3px; font-size:12px; margin:0 0 8px;">';
			echo '⚠ ' . esc_html( $export_error );
			echo '</p>';
		}

		// Per-product overrides.
		echo '<div style="display:flex; gap:12px; margin-bottom:10px; flex-wrap:wrap;">';

		// Category override.
		echo '<div style="flex:1; min-width:140px;">';
		echo '<label style="display:block; font-size:12px; color:#555; margin-bottom:3px;">';
		echo esc_html__( 'eBay Category ID', 'tcgiant-sync' );
		if ( $global_cat ) {
			echo ' <span style="color:#888;">(global: ' . esc_html( $global_cat ) . ')</span>';
		}
		echo '</label>';
		echo '<input type="text" name="_ebay_export_category_id" value="' . esc_attr( $cat_override ) . '" placeholder="' . esc_attr( $global_cat ?: __( 'Use global default', 'tcgiant-sync' ) ) . '" style="width:100%; font-size:12px;">';
		echo '</div>';

		// Condition override.
		$conditions = TCGiant_Sync_Exporter::CONDITIONS;
		$global_cond = $global_settings['export_condition_id'] ?? '1000';
		echo '<div style="flex:1; min-width:140px;">';
		echo '<label style="display:block; font-size:12px; color:#555; margin-bottom:3px;">';
		echo esc_html__( 'Condition', 'tcgiant-sync' );
		echo ' <span style="color:#888;">(global: ' . esc_html( $conditions[ $global_cond ] ?? $global_cond ) . ')</span>';
		echo '</label>';
		echo '<select name="_ebay_export_condition_id" style="width:100%; font-size:12px;">';
		echo '<option value="">' . esc_html__( '— Use global default —', 'tcgiant-sync' ) . '</option>';
		foreach ( $conditions as $cid => $clabel ) {
			echo '<option value="' . esc_attr( $cid ) . '"' . selected( $cond_override, $cid, false ) . '>' . esc_html( $clabel ) . '</option>';
		}
		echo '</select>';
		echo '</div>';

		echo '</div>'; // end flex row

		// Push button.
		$btn_label = ! empty( $ebay_item_id ) ? __( '↺ Update eBay Listing', 'tcgiant-sync' ) : __( '📤 Push to eBay', 'tcgiant-sync' );
		echo '<button type="button" id="tcgiant-push-btn" class="button button-primary" data-product-id="' . esc_attr( $product_id ) . '" style="margin-top:4px;">' . esc_html( $btn_label ) . '</button>';
		echo ' <span id="tcgiant-push-status" style="margin-left:8px; font-size:12px; color:#555;"></span>';

		echo '</div>'; // end push box

		// ---- Sync Log Section ----
		$logs = get_post_meta( $product_id, '_tcgiant_sync_log', true );

		if ( empty( $logs ) || ! is_array( $logs ) ) {
			echo '<p style="color:#888;">' . esc_html__( 'No import sync decisions logged yet.', 'tcgiant-sync' ) . '</p>';
		} else {
			echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Import Sync Log (Last 20)', 'tcgiant-sync' ) . '</strong></p>';
			echo '<div style="max-height: 300px; overflow-y: auto;">';
			foreach ( $logs as $entry ) {
				echo '<div style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee;">';
				echo '<strong style="color: #666; font-size: 11px;">' . esc_html( $entry['timestamp'] ?? 'Unknown Time' ) . '</strong>';
				if ( ! empty( $entry['decisions'] ) && is_array( $entry['decisions'] ) ) {
					echo '<ul style="margin-top: 4px; padding-left: 16px; margin-bottom: 0;">';
					foreach ( $entry['decisions'] as $decision ) {
						echo '<li style="font-size:12px;">' . esc_html( $decision ) . '</li>';
					}
					echo '</ul>';
				}
				echo '</div>';
			}
			echo '</div>';
		}

		echo '</div></div>'; // end padding + panel

		// Inline JS for the Push button.
		?>
		<script>
		(function($){
			$('#tcgiant-push-btn').on('click', function(){
				var btn = $(this);
				var status = $('#tcgiant-push-status');
				btn.prop('disabled', true);
				status.text('Saving overrides and queuing push...');
				// Save post first so override fields are persisted, then trigger push.
				$.post(ajaxurl, {
					action: 'tcgiant_push_product',
					product_id: btn.data('product-id'),
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'tcgiant_sync_ajax' ) ); ?>'
				}, function(res){
					if (res.success) {
						status.css('color','#2a8a2a').text('✔ ' + res.data.message);
					} else {
						status.css('color','#cc1818').text('✖ ' + (res.data ? res.data.message : 'Unknown error'));
						btn.prop('disabled', false);
					}
				});
			});
		})(jQuery);
		</script>
		<?php
	}
}
