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
	 * TCGiant_Sync_Admin Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_tcgiant_oauth_start', array( $this, 'handle_oauth_start' ) );
		add_action( 'admin_post_tcgiant_sync_now', array( $this, 'handle_manual_sync' ) );
		add_action( 'admin_post_tcgiant_sync_specific', array( $this, 'handle_sync_specific' ) );
		add_action( 'admin_post_tcgiant_force_queue', array( $this, 'handle_force_queue' ) );
		add_action( 'admin_post_tcgiant_prune_now', array( $this, 'handle_prune_now' ) );
		add_action( 'admin_post_tcgiant_resume_sync', array( $this, 'handle_resume_sync' ) );
		add_action( 'wp_ajax_tcgiant_sync_order_to_ebay', array( $this, 'ajax_sync_order_to_ebay' ) );
		add_action( 'admin_post_tcgiant_stop_sync', array( $this, 'handle_stop_sync' ) );
		add_action( 'admin_post_tcgiant_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_tcgiant_clear_recent_sales', array( $this, 'handle_clear_recent_sales' ) );
		add_action( 'admin_post_tcgiant_sync_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_tcgiant_wizard_save_import', array( $this, 'handle_wizard_save_import' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		
		// AJAX endpoints — Import.
		add_action( 'wp_ajax_tcgiant_sync_status', array( $this, 'ajax_sync_status' ) );
		add_action( 'wp_ajax_tcgiant_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_tcgiant_get_store_categories', array( $this, 'ajax_get_store_categories' ) );
		add_action( 'wp_ajax_tcgiant_activate_license', array( $this, 'ajax_activate_license' ) );
		add_action( 'wp_ajax_tcgiant_deactivate_license', array( $this, 'ajax_deactivate_license' ) );

		// AJAX endpoints — Export.
		add_action( 'wp_ajax_tcgiant_push_product', array( $this, 'ajax_push_product' ) );
		add_action( 'wp_ajax_tcgiant_verify_product', array( $this, 'ajax_verify_product' ) );
		add_action( 'wp_ajax_tcgiant_end_listing', array( $this, 'ajax_end_listing' ) );
		add_action( 'wp_ajax_tcgiant_fetch_policies', array( $this, 'ajax_fetch_policies' ) );
		add_action( 'wp_ajax_tcgiant_export_status', array( $this, 'ajax_export_status' ) );
		add_action( 'wp_ajax_tcgiant_browse_categories', array( $this, 'ajax_browse_categories' ) );
		add_action( 'wp_ajax_tcgiant_clear_export_error', array( $this, 'ajax_clear_export_error' ) );
		add_action( 'wp_ajax_tcgiant_suggest_category', array( $this, 'ajax_suggest_category' ) );
		add_action( 'wp_ajax_tcgiant_unlink_ebay', array( $this, 'ajax_unlink_ebay' ) );

		// Warn when several products claim one eBay listing (duplicated products).
		add_action( 'admin_notices', array( $this, 'shared_listing_admin_notice' ) );

		// eBay column on WooCommerce Orders list (HPOS-compatible).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_ebay_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_ebay_column' ), 10, 2 );
		// Legacy order table support.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_ebay_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_ebay_column_legacy' ), 10, 2 );

		// Bulk action on WooCommerce Products list.
		add_filter( 'bulk_actions-edit-product', array( $this, 'register_bulk_push_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_push_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_push_admin_notice' ) );
		add_action( 'admin_notices', array( $this, 'staging_admin_notice' ) );

		// Save per-product export overrides.
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_export_meta' ) );

		// WooCommerce Product Metabox Hooks - unified eBay Listing tab.
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_sync_log_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_ebay_listing_panel' ) );

		// Custom Bin Location Field.
		add_action( 'woocommerce_product_options_sku', array( $this, 'add_bin_location_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_bin_location_field' ) );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'add_variation_bin_location_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_bin_location_field' ), 10, 2 );



		// eBay column on WooCommerce Products list.
		add_filter( 'manage_edit-product_columns', array( $this, 'add_ebay_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_ebay_column' ), 10, 2 );
		add_action( 'admin_footer-edit.php', array( $this, 'render_product_list_inline_assets' ) );

		// Sortable eBay column + filter dropdown.
		add_filter( 'manage_edit-product_sortable_columns', array( $this, 'make_ebay_column_sortable' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_ebay_status_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'handle_ebay_status_filter' ) );

		// Ended-listing detection lives in TCGiant_Sync_Cron. Registering it
		// here meant the callback only existed on admin requests, while the
		// event fires from wp-cron.php — which is not is_admin() — so it never
		// actually ran.
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
			'tcgiant-sync_page_tcgiant-logs',
		);
		if ( ! in_array( $hook, $tc_pages, true ) ) {
			return;
		}

		$css_ver = filemtime( TCGIANT_SYNC_PATH . 'admin/assets/css/admin.css' );
		$js_ver  = filemtime( TCGIANT_SYNC_PATH . 'admin/assets/js/admin.js' );
		wp_enqueue_style( 'tcgiant-sync-admin', TCGIANT_SYNC_URL . 'admin/assets/css/admin.css', array(), $css_ver );
		wp_enqueue_script( 'tcgiant-sync-admin', TCGIANT_SYNC_URL . 'admin/assets/js/admin.js', array( 'jquery' ), $js_ver, true );

		$license = TCGiant_Sync_License::instance();

		// Only enable AJAX polling on pages that display live status/logs.
		$polling_pages = array(
			'toplevel_page_tcgiant-sync',
			'tcgiant-sync_page_tcgiant-import',
		);
		$enable_polling = in_array( $hook, $polling_pages, true );

		wp_localize_script( 'tcgiant-sync-admin', 'tcgiantSync', array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'tcgiant_sync_ajax' ),
			'license'       => $license->get_status_for_ui(),
			'enablePolling' => $enable_polling,
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

		// Report what actually happened. A sync already holding the lock makes
		// this a no-op, and the old unconditional "sync queued" message meant
		// the button looked like it worked while doing nothing.
		$result = TCGiant_Sync_Importer::instance()->start_full_sync( true );

		$args = is_wp_error( $result )
			? array( 'sync_failed' => rawurlencode( $result->get_error_message() ) )
			: array( 'sync_started' => 1 );

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=tcgiant-import' ) ) );
		exit;
	}

	/**
	 * Handle the "Prune Inventory" request.
	 *
	 * Removing products that are no longer on eBay requires knowing what IS on
	 * eBay, so this necessarily runs a fresh scan first and prunes at the end.
	 *
	 * It previously posted the same action as "Fetch Inventory", so the button
	 * silently started a full import instead of a prune — same outcome
	 * eventually, but nothing said so, and the two buttons were
	 * indistinguishable in the logs.
	 */
	public function handle_prune_now() {
		check_admin_referer( 'tcgiant_prune_now' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		TCGiant_Sync_Logger::log( 'Prune Inventory requested — running a full scan first so retired listings can be identified.' );

		$result = TCGiant_Sync_Importer::instance()->start_full_sync( true );

		$args = is_wp_error( $result )
			? array( 'sync_failed' => rawurlencode( $result->get_error_message() ) )
			: array( 'prune_started' => 1 );

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=tcgiant-import' ) ) );
		exit;
	}

	/**
	 * Handle specific items sync request.
	 */
	public function handle_sync_specific() {
		check_admin_referer( 'tcgiant_sync_specific' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		$item_ids_str  = isset( $_POST['tcgiant_item_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['tcgiant_item_ids'] ) ) : '';
		$images_only   = ! empty( $_POST['tcgiant_images_only'] );

		if ( ! empty( $item_ids_str ) ) {
			$item_ids = array_map( 'trim', explode( ',', $item_ids_str ) );
			$item_ids = array_filter( $item_ids );

			if ( ! empty( $item_ids ) ) {
				$importer = TCGiant_Sync_Importer::instance();
				if ( $images_only ) {
					$importer->start_images_only_sync( $item_ids );
				} else {
					$importer->start_specific_sync( $item_ids );
				}
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-import&sync_started=1' ) );
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

		// Action Scheduler side: item imports, pruning, stock pushes.
		$as_processed = 0;
		if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
			$result = ActionScheduler_QueueRunner::instance()->run();
			// Older Action Scheduler releases return null rather than a count.
			$as_processed = is_numeric( $result ) ? (int) $result : 0;
		}

		// WP-Cron side: continuing the page scan and downloading images.
		//
		// This is the half that was missing. Those are WP-Cron events, not
		// Action Scheduler actions, so draining Action Scheduler alone left
		// them untouched — on a host with broken cron, which is exactly when
		// someone presses this button, it achieved nothing at all.
		$cron = TCGiant_Sync_Cron::run_due_events_now();

		TCGiant_Sync_Logger::log( sprintf(
			'Manual queue run: %d Action Scheduler job(s), %d scheduled task(s).',
			$as_processed,
			$cron['due']
		) );

		// Return to whichever screen the button was pressed on, rather than
		// always bouncing to the Dashboard.
		$referer = wp_get_referer();
		$target  = $referer ? $referer : admin_url( 'admin.php?page=tcgiant-import' );

		wp_safe_redirect( add_query_arg(
			array(
				'queue_processed' => 1,
				'queue_as'        => $as_processed,
				'queue_cron'      => $cron['due'],
			),
			remove_query_arg( array( 'queue_processed', 'queue_as', 'queue_cron' ), $target )
		) );
		exit;
	}

	/**
	 * Handle resuming a paused/rate-limited sync.
	 */
	public function handle_resume_sync() {
		check_admin_referer( 'tcgiant_resume_sync' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		TCGiant_Sync_Importer::instance()->resume_sync();
		wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-import&sync_resumed=1' ) );
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
			// get_items() defaults to line items, but shipping/fee/tax rows are
			// plain WC_Order_Item objects with no get_product().
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
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

		$has_errors = false;
		$has_success_or_skip = false;
		foreach ( $results as $res ) {
			if ( 'error' === $res['status'] ) {
				$has_errors = true;
			}
			if ( in_array( $res['status'], array( 'success', 'skipped' ), true ) ) {
				$has_success_or_skip = true;
			}
		}

		if ( ! $has_errors && $has_success_or_skip ) {
			$order->update_meta_data( '_tcgiant_sync_pushed', '1' );
			$order->save_meta_data();
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

		// Cancels every queue with its correct group, clears the WP-Cron
		// scan-resume event and releases the concurrency lock. Doing this
		// inline with the wrong group names meant Stop reported success while
		// imports and image downloads carried on.
		TCGiant_Sync_Importer::stop_all();

		TCGiant_Sync_Importer::update_sync_state( array(
			'status'              => 'stopped',
			'scan_complete_clean' => false,
		) );
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
	 * Handle clearing recent sales from the dashboard.
	 */
	public function handle_clear_recent_sales() {
		check_admin_referer( 'tcgiant_clear_recent_sales' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		$recent_orders = wc_get_orders( array(
			'limit'      => 10,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'status'     => array( 'wc-completed', 'wc-processing' ),
			'meta_query' => array(
				array(
					'key'     => '_tcgiant_sync_pushed',
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		foreach ( $recent_orders as $order ) {
			$order->update_meta_data( '_tcgiant_sync_pushed', 'cleared' );
			$order->save_meta_data();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-sync&sales_cleared=1' ) );
		exit;
	}

	/**
	 * Handle disconnecting eBay account (clearing stored OAuth tokens).
	 */
	public function handle_disconnect() {
		check_admin_referer( 'tcgiant_sync_disconnect' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		unset( $settings['access_token'], $settings['refresh_token'], $settings['token_expiry'], $settings['store_name'], $settings['store_username'], $settings['store_id'] );
		update_option( 'tcgiant_sync_ebay_settings', $settings );

		TCGiant_Sync_Logger::log( 'eBay account disconnected by user.', 'warning' );

		wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-settings&disconnected=1' ) );
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
			__( 'Listings', 'tcgiant-sync' ),
			__( 'Listings', 'tcgiant-sync' ),
			'manage_options',
			'tcgiant-listings',
			array( $this, 'render_listings_page' )
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

		add_submenu_page(
			'tcgiant-sync',
			__( 'Logs', 'tcgiant-sync' ),
			__( 'Logs', 'tcgiant-sync' ),
			'manage_options',
			'tcgiant-logs',
			array( $this, 'render_logs_page' )
		);

		// Setup Wizard (hidden from menu).
		add_submenu_page(
			'', // Hidden from the menu; still reachable by URL. Passing null
			    // here is deprecated and emits a notice on PHP 8.1+.
			__( 'Setup Wizard', 'tcgiant-sync' ),
			__( 'Setup Wizard', 'tcgiant-sync' ),
			'manage_options',
			'tcgiant-wizard',
			array( $this, 'render_wizard_page' )
		);
	}

	/**
	 * Render the horizontal tab navigation.
	 */
	public function render_tabs( $active_tab = 'dashboard' ) {
		$tabs = array(
			'dashboard' => array( 'title' => __( 'Dashboard', 'tcgiant-sync' ), 'icon' => 'dashicons-dashboard', 'page' => 'tcgiant-sync' ),
			'import'    => array( 'title' => __( 'Import from eBay', 'tcgiant-sync' ), 'icon' => 'dashicons-download', 'page' => 'tcgiant-import' ),
			'export'    => array( 'title' => __( 'Push to eBay', 'tcgiant-sync' ), 'icon' => 'dashicons-upload', 'page' => 'tcgiant-export' ),
			'settings'  => array( 'title' => __( 'Settings', 'tcgiant-sync' ), 'icon' => 'dashicons-admin-settings', 'page' => 'tcgiant-settings' ),
			'logs'      => array( 'title' => __( 'Logs', 'tcgiant-sync' ), 'icon' => 'dashicons-list-view', 'page' => 'tcgiant-logs' ),
		);

		echo '<div class="tc-nav-tabs">';
		foreach ( $tabs as $id => $tab ) {
			$active_class = ( $active_tab === $id ) ? ' tc-nav-tab-active' : '';
			$url = admin_url( 'admin.php?page=' . $tab['page'] );
			printf(
				'<a href="%s" class="tc-nav-tab%s"><span class="dashicons %s"></span> %s</a>',
				esc_url( $url ),
				esc_attr( $active_class ),
				esc_attr( $tab['icon'] ),
				esc_html( $tab['title'] )
			);
		}
		echo '</div>';
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
	 * Render Listings management page.
	 */
	public function render_listings_page() {
		include_once TCGIANT_SYNC_PATH . 'admin/views/listings.php';
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
		require_once TCGIANT_SYNC_PATH . 'admin/views/settings.php';
	}

	/**
	 * Render logs page.
	 */
	public function render_logs_page() {
		require_once TCGIANT_SYNC_PATH . 'admin/views/logs.php';
	}

	/**
	 * Render setup wizard page.
	 */
	public function render_wizard_page() {
		require_once TCGIANT_SYNC_PATH . 'admin/views/wizard.php';
	}

	/**
	 * Handle wizard step 2 import settings save.
	 */
	public function handle_wizard_save_import() {
		check_admin_referer( 'tcgiant_wizard_save_import' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$fields   = array( 'marketplace', 'sync_interval', 'enable_order_import', 'disable_image_localization' );
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$settings[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			}
		}

		update_option( 'tcgiant_sync_ebay_settings', $settings );

		wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-wizard&step=3' ) );
		exit;
	}

	/**
	 * AJAX: Sever a product's link to an eBay listing.
	 *
	 * Local only — the eBay listing itself is untouched. Used to clean up
	 * products duplicated before the duplication fix, which inherited the
	 * original's eBay Item ID and so appeared already listed.
	 */
	public function ajax_unlink_ebay() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid product.' ) );
		}

		$was = get_post_meta( $product_id, '_ebay_item_id', true );
		TCGiant_Sync_Exporter::unlink_product_from_ebay( $product_id, 'unlinked by admin' );

		wp_send_json_success( array(
			'message' => $was
				? sprintf( __( 'Unlinked from eBay listing %s. The eBay listing itself was not changed.', 'tcgiant-sync' ), $was )
				: __( 'This product was not linked to an eBay listing.', 'tcgiant-sync' ),
		) );
	}

	/**
	 * Warn when more than one product claims the same eBay listing.
	 *
	 * Duplicating a product used to copy _ebay_item_id, leaving the copy
	 * pointing at the original's live listing. Products duplicated before that
	 * was fixed still carry the stale link, and pushing or ending either one
	 * would act on the original's listing.
	 */
	public function shared_listing_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-product', 'product', 'plugins' ), true )
			&& strpos( (string) $screen->id, 'tcgiant' ) === false ) {
			return;
		}

		$duplicates = get_transient( 'tcgiant_shared_item_ids' );
		if ( false === $duplicates ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$duplicates = $wpdb->get_results(
				"SELECT pm.meta_value AS item_id, COUNT(*) AS n, GROUP_CONCAT(pm.post_id) AS ids
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				 WHERE pm.meta_key = '_ebay_item_id'
				   AND pm.meta_value != ''
				   AND p.post_type = 'product'
				   AND p.post_status != 'trash'
				 GROUP BY pm.meta_value
				 HAVING n > 1
				 LIMIT 20"
			);
			set_transient( 'tcgiant_shared_item_ids', $duplicates, 10 * MINUTE_IN_SECONDS );
		}

		if ( empty( $duplicates ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html__( 'TCGiant Sync: some products share an eBay listing', 'tcgiant-sync' )
			. '</strong></p><p>'
			. esc_html__( 'These products are each linked to the same eBay Item ID, which happens when a product is duplicated. Pushing or ending a listing from the copy would change the original\'s live eBay listing, so those actions are blocked until it is resolved. Open the copy, go to the eBay Listing tab and choose "Unlink from eBay".', 'tcgiant-sync' )
			. '</p><ul style="list-style:disc;margin-left:20px;">';

		foreach ( $duplicates as $row ) {
			$ids   = array_map( 'absint', explode( ',', $row->ids ) );
			$links = array();
			foreach ( $ids as $id ) {
				$links[] = '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">'
					. esc_html( get_the_title( $id ) ?: '#' . $id ) . '</a>';
			}
			printf(
				'<li>%s: %s</li>',
				esc_html( sprintf( __( 'eBay Item %s', 'tcgiant-sync' ), $row->item_id ) ),
				wp_kses_post( implode( ', ', $links ) )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * Begin the eBay OAuth handshake.
	 *
	 * Records a one-time state token, then redirects out to the relay. This is
	 * the only supported entry point into the connect flow — handle_oauth_callback()
	 * refuses to store tokens unless this ran first.
	 */
	public function handle_oauth_start() {
		check_admin_referer( 'tcgiant_oauth_start' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		$oauth = TCGiant_Sync_OAuth::instance();
		$state = $oauth->begin_authorization();

		// External host, so wp_redirect() rather than wp_safe_redirect().
		wp_redirect( $oauth->get_relay_authorization_url( $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Handle OAuth Callback.
	 *
	 * Runs on admin_init, which fires for every authenticated user on every
	 * wp-admin request and executes BEFORE WordPress performs the per-page
	 * capability check. Everything here is therefore gated explicitly:
	 *
	 *   1. The current user must be able to manage options.
	 *   2. This site must have started a handshake within the last 15 minutes
	 *      (proved by the state transient set in handle_oauth_start()).
	 *   3. If the relay echoed a state value back, it must match.
	 *
	 * Without these, any logged-in subscriber — or CSRF against an administrator
	 * — could plant arbitrary eBay credentials and a forged relay signing key.
	 */
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || ! in_array( $_GET['page'], array( 'tcgiant-sync', 'tcgiant-settings' ), true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['ebay_access_token'], $_GET['ebay_refresh_token'], $_GET['ebay_expires_in'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Rejected eBay OAuth callback from user #%d: insufficient capability.',
				get_current_user_id()
			) );
			return;
		}

		$oauth = TCGiant_Sync_OAuth::instance();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$returned_state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( ! $oauth->consume_authorization_state( $returned_state ) ) {
			TCGiant_Sync_Logger::warning(
				'Rejected eBay OAuth callback: no pending authorization request for this site. '
				. 'Start the connection from the Settings page.'
			);
			wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-settings&auth=invalid_state' ) );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$data = array(
			'access_token'  => sanitize_text_field( wp_unslash( $_GET['ebay_access_token'] ) ),
			'refresh_token' => sanitize_text_field( wp_unslash( $_GET['ebay_refresh_token'] ) ),
			'expires_in'    => sanitize_text_field( wp_unslash( $_GET['ebay_expires_in'] ) ),
			'relay_key'     => isset( $_GET['ebay_relay_key'] ) ? sanitize_text_field( wp_unslash( $_GET['ebay_relay_key'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $oauth->save_tokens_from_relay( $data ) ) {
			TCGiant_Sync_Logger::log( 'eBay account connected successfully.', 'success' );
			wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-sync&auth=success' ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-sync&auth=failed' ) );
		exit;
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
				$clean_key = sanitize_key( $key );
				if ( is_array( $value ) ) {
					// map_deep() recurses; array_map( 'sanitize_text_field' )
					// raised a TypeError on any nested array value.
					$sanitized[ $clean_key ] = map_deep( $value, 'sanitize_text_field' );
				} elseif ( 'custom_saved_categories' === $clean_key ) {
					$sanitized[ $clean_key ] = sanitize_textarea_field( $value );
				} else {
					$sanitized[ $clean_key ] = sanitize_text_field( $value );
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

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}
		
		$state = TCGiant_Sync_Importer::get_sync_state();

		// Self-heal: if status says 'importing' but no pending jobs remain, auto-complete.
		if ( 'importing' === $state['status'] && function_exists( 'as_get_scheduled_actions' ) ) {
			// Item imports live in GROUP_IMPORTS. Querying the scan group here
			// always came back empty, so an in-progress sync was flipped to
			// "complete" the first time the dashboard polled.
			$pending = as_get_scheduled_actions( array(
				'hook'     => 'tcgiant_sync_process_item_import',
				'group'    => TCGiant_Sync_Importer::GROUP_IMPORTS,
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

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}
		
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

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

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

		// Background task health.
		//
		// Everything the plugin does out of band — page scanning, image
		// localization, order import — depends on scheduled tasks actually
		// running. On a host that sets DISABLE_WP_CRON without a replacement,
		// they silently never fire, and the usual symptom is "my images never
		// downloaded" with nothing in the log to explain it.
		$cron_health = TCGiant_Sync_Cron::get_cron_health();

		if ( ! empty( $cron_health['overdue'] ) ) {
			$health['background'] = array(
				'label'  => __( 'Background Tasks', 'tcgiant-sync' ),
				'status' => 'warning',
				'text'   => sprintf(
					/* translators: %d: number of overdue scheduled tasks */
					_n( '%d task overdue — cron may not be running', '%d tasks overdue — cron may not be running', count( $cron_health['overdue'] ), 'tcgiant-sync' ),
					count( $cron_health['overdue'] )
				),
			);
		} elseif ( $cron_health['disabled'] ) {
			$health['background'] = array(
				'label'  => __( 'Background Tasks', 'tcgiant-sync' ),
				'status' => 'active',
				'text'   => __( 'WP-Cron disabled — running via admin activity', 'tcgiant-sync' ),
			);
		} else {
			$health['background'] = array(
				'label'  => __( 'Background Tasks', 'tcgiant-sync' ),
				'status' => 'active',
				'text'   => __( 'Up to date', 'tcgiant-sync' ),
			);
		}

		return $health;
	}

	/**
	 * Get sync statistics.
	 */
	public function get_sync_stats() {
		// Use a short transient to avoid running expensive DB queries on every poll.
		$cached = get_transient( 'tcgiant_sync_stats_cache' );
		if ( false !== $cached ) {
			// Always refresh the state (cheap get_option) for live status updates.
			$state = TCGiant_Sync_Importer::get_sync_state();
			$cached['last_completed'] = $state['last_completed'] ?? '';
			return $cached;
		}

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

		// Pending Action Scheduler jobs (all sync groups).
		$pending_jobs = 0;
		if ( class_exists( 'ActionScheduler' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pending_jobs = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions
				 WHERE `group_id` IN (SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug IN ('tcgiant_sync_group', 'tcgiant_sync_imports', 'tcgiant_sync_images'))
				 AND status = 'pending'"
			);
		}

		$state = TCGiant_Sync_Importer::get_sync_state();

		$stats = array(
			'synced_products' => $synced_products,
			'pending_jobs'    => $pending_jobs,
			'last_completed'  => $state['last_completed'] ?? '',
		);

		set_transient( 'tcgiant_sync_stats_cache', $stats, 30 );

		return $stats;
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

		// Save per-product override fields from the form before queuing.
		$override_fields = array(
			'override_category_id'        => '_ebay_export_category_id',
			'override_condition_id'       => '_ebay_export_condition_id',
			'override_item_type'          => '_ebay_export_item_type',
			'override_condition_type'     => '_ebay_export_condition_type',
			'override_grader_id'          => '_ebay_export_grader_id',
			'override_grade_value'        => '_ebay_export_grade_value',
			'override_cert_number'        => '_ebay_export_cert_number',
			'override_coin_year'          => '_ebay_export_coin_year',
			'override_ungraded_condition' => '_ebay_export_ungraded_condition',
			'override_listing_type'       => '_ebay_export_listing_type',
			'override_listing_duration'   => '_ebay_export_listing_duration',
			'override_fulfillment_policy' => '_ebay_export_fulfillment_policy',
		);
		foreach ( $override_fields as $post_key => $meta_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
				update_post_meta( $product_id, $meta_key, $value );
			}
		}

		// Pre-push validation: check all requirements BEFORE queuing.
		// This gives the user instant feedback instead of async error discovery.
		$settings = $exporter->get_export_settings( $product_id );
		$valid    = $exporter->validate_export_settings( $settings );
		if ( is_wp_error( $valid ) ) {
			// Save error to product meta so it shows on page reload too.
			update_post_meta( $product_id, '_ebay_export_status', 'error' );
			update_post_meta( $product_id, '_ebay_export_error', $valid->get_error_message() );
			wp_send_json_error( array( 'message' => $valid->get_error_message() ) );
		}

		// Also check product has images (eBay requires at least 1).
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$image_id  = $product->get_image_id();
			$gallery   = $product->get_gallery_image_ids();
			if ( empty( $image_id ) && empty( $gallery ) ) {
				$msg = __( 'Product has no images. eBay requires at least 1 photo.', 'tcgiant-sync' );
				update_post_meta( $product_id, '_ebay_export_status', 'error' );
				update_post_meta( $product_id, '_ebay_export_error', $msg );
				wp_send_json_error( array( 'message' => $msg ) );
			}
		}

		$queued = $exporter->push_product( $product_id );

		if ( $queued ) {
			wp_send_json_success( array( 'message' => 'Product queued for push to eBay. Check the Activity Log for results.' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Could not queue product. It may not exist.' ) );
		}
	}

	/**
	 * AJAX: Clear export error for a product.
	 */
	public function ajax_clear_export_error() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => 'Invalid product ID.' ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => 'Product not found.' ) );
		}

		$product->update_meta_data( '_ebay_export_status', '' );
		$product->update_meta_data( '_ebay_export_error', '' );
		$product->save();

		wp_send_json_success( array( 'message' => 'Error cleared.' ) );
	}

	/**
	 * AJAX: End an eBay listing from the product edit screen.
	 */
	public function ajax_end_listing() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => 'Missing product ID.' ) );
		}

		$ebay_item_id = get_post_meta( $product_id, '_ebay_item_id', true );
		if ( empty( $ebay_item_id ) ) {
			wp_send_json_error( array( 'message' => 'No eBay Item ID found for this product.' ) );
		}

		// Do not end a listing that another product also claims — ending it
		// from a duplicate would close the original's live listing.
		$shared_with = TCGiant_Sync_Exporter::find_products_sharing_item_id( $product_id, $ebay_item_id );
		if ( ! empty( $shared_with ) ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: 1: eBay Item ID, 2: comma-separated product IDs */
					__( 'Refusing to end eBay listing %1$s: product(s) #%2$s are linked to the same listing, so this product was probably duplicated from one of them. Ending it here would close their live listing. Use "Unlink from eBay" on this product instead.', 'tcgiant-sync' ),
					$ebay_item_id,
					implode( ', ', $shared_with )
				),
			) );
		}

		$api = TCGiant_Sync_API::instance();
		$result = $api->end_item( $ebay_item_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Update product meta to reflect ended status.
		update_post_meta( $product_id, '_ebay_listing_status', 'Ended' );
		update_post_meta( $product_id, '_ebay_end_time', gmdate( 'Y-m-d\TH:i:s.000\Z' ) );

		TCGiant_Sync_Logger::log( sprintf(
			'eBay listing %s ended from WordPress (product #%d).',
			$ebay_item_id, $product_id
		), 'success' );

		wp_send_json_success( array( 'message' => sprintf( 'Listing %s ended on eBay.', $ebay_item_id ) ) );
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

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}
		wp_send_json_success( array(
			'state' => TCGiant_Sync_Exporter::get_export_state(),
		) );
	}

	/**
	 * AJAX: Browse eBay categories via the Taxonomy API.
	 * If parent_id is empty, returns root categories. Otherwise returns children.
	 */
	public function ajax_browse_categories() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		if ( ! TCGiant_Sync_OAuth::instance()->is_authenticated() ) {
			wp_send_json_error( array( 'message' => 'Not connected to eBay. Please connect first in Settings.' ) );
		}

		$parent_id = isset( $_POST['parent_id'] ) ? sanitize_text_field( wp_unslash( $_POST['parent_id'] ) ) : '';
		$api = TCGiant_Sync_API::instance();

		if ( empty( $parent_id ) ) {
			$result = $api->get_category_tree_root();
		} else {
			$result = $api->get_category_subtree( $parent_id );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'categories' => $result ) );
	}

	/**
	 * AJAX: Suggest eBay categories based on a product title.
	 */
	public function ajax_suggest_category() {
		check_ajax_referer( 'tcgiant_sync_ajax' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}
		if ( ! TCGiant_Sync_OAuth::instance()->is_authenticated() ) {
			wp_send_json_error( array( 'message' => 'Not connected to eBay.' ) );
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( empty( $title ) ) {
			wp_send_json_error( array( 'message' => 'No title provided.' ) );
		}
		$result = TCGiant_Sync_API::instance()->get_suggested_categories( $title );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'suggestions' => $result ) );
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
	 * Show admin notice on staging/dev environments.
	 */
	public function staging_admin_notice() {
		if ( ! defined( 'TCGIANT_SYNC_IS_STAGING' ) || ! TCGIANT_SYNC_IS_STAGING ) {
			return;
		}
		// Only show on TCGiant Sync pages or WooCommerce product pages.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$show_on = array( 'toplevel_page_tcgiant-sync', 'edit-product', 'product' );
		if ( ! in_array( $screen->id, $show_on, true ) && strpos( $screen->id, 'tcgiant' ) === false ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo '<strong>⚠ TCGiant Sync — Staging Mode:</strong> ';
		esc_html_e( 'This appears to be a staging or development environment. eBay write operations (push, revise, end listing) are disabled to prevent accidental changes to live listings.', 'tcgiant-sync' );
		echo '</p></div>';
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
		if ( isset( $_POST['_ebay_export_category_id_select'] ) ) {
			$cat_select = sanitize_text_field( wp_unslash( $_POST['_ebay_export_category_id_select'] ) );
			if ( 'custom' === $cat_select ) {
				$cat_val = isset( $_POST['_ebay_export_category_id_custom'] ) ? sanitize_text_field( wp_unslash( $_POST['_ebay_export_category_id_custom'] ) ) : '';
			} else {
				$cat_val = $cat_select;
			}
			update_post_meta( $post_id, '_ebay_export_category_id', $cat_val );
			// Save the category name if provided.
			if ( isset( $_POST['_ebay_export_category_name'] ) ) {
				update_post_meta( $post_id, '_ebay_export_category_name', sanitize_text_field( wp_unslash( $_POST['_ebay_export_category_name'] ) ) );
			}
		}
		if ( isset( $_POST['_ebay_export_condition_id'] ) ) {
			update_post_meta( $post_id, '_ebay_export_condition_id', sanitize_text_field( wp_unslash( $_POST['_ebay_export_condition_id'] ) ) );
		}
		// ConditionDescriptor overrides.
		$descriptor_fields = array(
			'_ebay_export_item_type',
			'_ebay_export_condition_type',
			'_ebay_export_cert_number',
			'_ebay_export_coin_year',
			'_ebay_export_listing_type',
			'_ebay_export_listing_duration',
			'_ebay_export_fulfillment_policy',
		);
		foreach ( $descriptor_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		// Handle grader ID and grade value saving based on item type.
		if ( isset( $_POST['_ebay_export_item_type'] ) ) {
			$item_type = sanitize_text_field( wp_unslash( $_POST['_ebay_export_item_type'] ) );
			if ( 'tcg' === $item_type ) {
				if ( isset( $_POST['_ebay_export_grader_id_tcg'] ) ) {
					update_post_meta( $post_id, '_ebay_export_grader_id', sanitize_text_field( wp_unslash( $_POST['_ebay_export_grader_id_tcg'] ) ) );
				}
				if ( isset( $_POST['_ebay_export_grade_value_tcg'] ) ) {
					update_post_meta( $post_id, '_ebay_export_grade_value', sanitize_text_field( wp_unslash( $_POST['_ebay_export_grade_value_tcg'] ) ) );
				}
			} elseif ( 'coins' === $item_type ) {
				if ( isset( $_POST['_ebay_export_grader_id_coins'] ) ) {
					update_post_meta( $post_id, '_ebay_export_grader_id', sanitize_text_field( wp_unslash( $_POST['_ebay_export_grader_id_coins'] ) ) );
				}
				if ( isset( $_POST['_ebay_export_grade_value_coins'] ) ) {
					update_post_meta( $post_id, '_ebay_export_grade_value', sanitize_text_field( wp_unslash( $_POST['_ebay_export_grade_value_coins'] ) ) );
				}
			}
		} else {
			// Fallback if item type wasn't posted but grader fields were.
			if ( isset( $_POST['_ebay_export_grader_id'] ) ) {
				update_post_meta( $post_id, '_ebay_export_grader_id', sanitize_text_field( wp_unslash( $_POST['_ebay_export_grader_id'] ) ) );
			}
			if ( isset( $_POST['_ebay_export_grade_value'] ) ) {
				update_post_meta( $post_id, '_ebay_export_grade_value', sanitize_text_field( wp_unslash( $_POST['_ebay_export_grade_value'] ) ) );
			}
		}

		// Handle ungraded condition saving based on item type.
		if ( isset( $_POST['_ebay_export_item_type'] ) ) {
			$item_type = sanitize_text_field( wp_unslash( $_POST['_ebay_export_item_type'] ) );
			if ( 'tcg' === $item_type && isset( $_POST['_ebay_export_ungraded_condition_tcg'] ) ) {
				update_post_meta( $post_id, '_ebay_export_ungraded_condition', sanitize_text_field( wp_unslash( $_POST['_ebay_export_ungraded_condition_tcg'] ) ) );
			} elseif ( 'coins' === $item_type && isset( $_POST['_ebay_export_ungraded_condition_coins'] ) ) {
				update_post_meta( $post_id, '_ebay_export_ungraded_condition', sanitize_text_field( wp_unslash( $_POST['_ebay_export_ungraded_condition_coins'] ) ) );
			}
		} elseif ( isset( $_POST['_ebay_export_ungraded_condition'] ) ) {
			update_post_meta( $post_id, '_ebay_export_ungraded_condition', sanitize_text_field( wp_unslash( $_POST['_ebay_export_ungraded_condition'] ) ) );
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
		$tabs['tcgiant_ebay_listing'] = array(
			'label'    => __( 'eBay Listing', 'tcgiant-sync' ),
			'target'   => 'tcgiant_ebay_listing_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 89,
		);
		return $tabs;
	}
	/**
	 * Render the unified "eBay Listing" panel - combines item type, category,
	 * grading/condition, readiness checks, push button, and sync log.
	 */
	public function render_ebay_listing_panel() {
		global $post;
		$product_id = $post->ID;
		$product    = wc_get_product( $product_id );

		// ---- Load all meta ----
		$ebay_item_id   = get_post_meta( $product_id, '_ebay_item_id', true );
		$export_status  = get_post_meta( $product_id, '_ebay_export_status', true );
		$export_error   = get_post_meta( $product_id, '_ebay_export_error', true );
		$last_pushed    = get_post_meta( $product_id, '_ebay_export_last_pushed', true );
		$cat_override   = get_post_meta( $product_id, '_ebay_export_category_id', true );
		$cat_name_saved = get_post_meta( $product_id, '_ebay_export_category_name', true );
		$item_type      = get_post_meta( $product_id, '_ebay_export_item_type', true );
		$cond_type      = get_post_meta( $product_id, '_ebay_export_condition_type', true );
		$grader_id      = get_post_meta( $product_id, '_ebay_export_grader_id', true );
		$grade_val      = get_post_meta( $product_id, '_ebay_export_grade_value', true );
		$cert_num       = get_post_meta( $product_id, '_ebay_export_cert_number', true );
		$coin_year      = get_post_meta( $product_id, '_ebay_export_coin_year', true );
		$ungraded       = get_post_meta( $product_id, '_ebay_export_ungraded_condition', true );
		$listing_type_override   = get_post_meta( $product_id, '_ebay_export_listing_type', true );
		$listing_dur_override    = get_post_meta( $product_id, '_ebay_export_listing_duration', true );
		$fulfillment_override    = get_post_meta( $product_id, '_ebay_export_fulfillment_policy', true );

		$global_settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$global_cat      = $global_settings['export_category_id'] ?? '';
		$exporter        = TCGiant_Sync_Exporter::instance();
		$merged_settings = $exporter->get_export_settings( $product_id );
		$tcg_categories  = TCGiant_Sync_Exporter::get_categories();
		?>
		<div id="tcgiant_ebay_listing_data" class="panel woocommerce_options_panel hide_if_grouped hide_if_external">
		<style>
			.tc-ebay-section{border:1px solid #e0e0e0;border-radius:6px;margin:0 15px 12px;background:#fafafa;overflow:hidden}
			.tc-ebay-section-head{display:flex;align-items:center;gap:8px;padding:10px 14px;cursor:pointer;user-select:none;font-size:13px;font-weight:600;color:#23282d;background:#fafafa;transition:background .15s}
			.tc-ebay-section-head:hover{background:#f0f0f0}
			.tc-ebay-section-head .dashicons{font-size:14px;width:14px;height:14px;color:#666;transition:transform .2s}
			.tc-ebay-section-head.open .dashicons{transform:rotate(90deg)}
			.tc-ebay-section-body{padding:12px 14px;border-top:1px solid #e0e0e0;display:none}
			.tc-ebay-section-body.open{display:block}
			.tc-card-selector{display:flex;gap:10px;flex-wrap:wrap}
			.tc-card-option{flex:1;min-width:110px;border:2px solid #ddd;border-radius:8px;padding:14px 10px;text-align:center;cursor:pointer;transition:all .2s;background:#fff}
			.tc-card-option:hover{border-color:#2271b1;background:#f0f6fc}
			.tc-card-option.selected{border-color:#2271b1;background:#e8f2fb;box-shadow:0 0 0 1px #2271b1}
			.tc-card-icon{display:block;font-size:16px;margin-bottom:4px}
			.tc-card-icon .dashicons{font-size:28px;width:28px;height:28px;color:#2271b1}
			.tc-card-label{display:block;font-size:13px;font-weight:600;color:#333}
			.tc-card-desc{display:block;font-size:10px;color:#888;margin-top:2px}
			.tc-readiness{border:1px solid #c3e6cb;border-radius:5px;padding:8px 12px;margin:0 0 10px;background:#f0faf0;font-size:12px}
			.tc-readiness.has-issues{border-color:#f5c6cb;background:#fef8f8}
			.tc-readiness-title{font-weight:600;margin-bottom:3px}
			.tc-readiness-check{padding:1px 0}
			.tc-suggest-pills{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
			.tc-suggest-pill{display:inline-block;background:#e8f2fb;border:1px solid #b8d4f0;border-radius:12px;padding:3px 10px;font-size:11px;color:#2271b1;cursor:pointer;transition:all .15s}
			.tc-suggest-pill:hover{background:#2271b1;color:#fff}
		</style>
		<div style="padding:12px 0;">

		<?php // ---- Error Notice ---- ?>
		<?php if ( 'error' === $export_status && ! empty( $export_error ) ) : ?>
			<div id="tcgiant-export-error-notice" style="background:#fff0f0;border:1px solid #fcc;border-radius:4px;padding:8px 10px;margin:0 15px 10px;">
				<p style="color:#cc1818;font-size:12px;margin:0 0 6px;line-height:1.4;"><?php echo esc_html( $export_error ); ?></p>
				<a href="#" id="tcgiant-dismiss-export-error" data-product-id="<?php echo esc_attr( $product_id ); ?>" style="display:inline-block;background:#cc1818;color:#fff;font-size:11px;font-weight:600;padding:3px 10px;border-radius:3px;text-decoration:none;cursor:pointer;"><?php esc_html_e( 'Clear Error', 'tcgiant-sync' ); ?></a>
			</div>
		<?php endif; ?>

		<?php // ---- eBay Listing Status ---- ?>
		<?php if ( ! empty( $ebay_item_id ) ) : ?>
			<div style="margin:0 15px 10px;padding:8px 12px;background:#f0faf0;border:1px solid #c3e6cb;border-radius:4px;font-size:12px;">
				<strong><?php esc_html_e( 'eBay Item:', 'tcgiant-sync' ); ?></strong>
				<a href="<?php echo esc_url( 'https://www.ebay.com/itm/' . $ebay_item_id ); ?>" target="_blank"><?php echo esc_html( $ebay_item_id ); ?> &nearr;</a>
				<?php if ( $last_pushed ) : ?>
					<span style="color:#888;margin-left:6px;"><?php echo esc_html( 'Last pushed: ' . $last_pushed ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php // == STEP 1: ITEM TYPE == ?>
		<div class="tc-ebay-section" id="tc-section-item-type">
			<div class="tc-ebay-section-head open" data-target="tc-body-item-type">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<span><?php esc_html_e( '1. Item Type', 'tcgiant-sync' ); ?></span>
				<span id="tc-step1-summary" style="font-weight:400;color:#888;font-size:12px;margin-left:auto;"><?php
					if ( 'coins' === $item_type ) { esc_html_e( 'Coins', 'tcgiant-sync' ); }
					elseif ( 'tcg' === $item_type ) { esc_html_e( 'Trading Cards', 'tcgiant-sync' ); }
					elseif ( 'other' === $item_type ) { esc_html_e( 'All Other', 'tcgiant-sync' ); }
				?></span>
			</div>
			<div class="tc-ebay-section-body open" id="tc-body-item-type">
				<p style="margin:0 0 8px;font-size:12px;color:#666;"><?php esc_html_e( 'What type of item is this?', 'tcgiant-sync' ); ?></p>
				<input type="hidden" name="_ebay_export_item_type" id="_ebay_export_item_type" value="<?php echo esc_attr( $item_type ); ?>">
				<div class="tc-card-selector">
					<div class="tc-card-option <?php echo 'tcg' === $item_type ? 'selected' : ''; ?>" data-value="tcg">
						<span class="tc-card-icon"><span class="dashicons dashicons-tickets-alt"></span></span>
						<span class="tc-card-label"><?php esc_html_e( 'Trading Cards', 'tcgiant-sync' ); ?></span>
						<span class="tc-card-desc"><?php esc_html_e( 'Pokemon, MTG, Yu-Gi-Oh, Sports', 'tcgiant-sync' ); ?></span>
					</div>
					<div class="tc-card-option <?php echo 'coins' === $item_type ? 'selected' : ''; ?>" data-value="coins">
						<span class="tc-card-icon"><span class="dashicons dashicons-money-alt"></span></span>
						<span class="tc-card-label"><?php esc_html_e( 'Coins', 'tcgiant-sync' ); ?></span>
						<span class="tc-card-desc"><?php esc_html_e( 'US, World, Bullion, Paper Money', 'tcgiant-sync' ); ?></span>
					</div>
					<div class="tc-card-option <?php echo 'other' === $item_type ? 'selected' : ''; ?>" data-value="other">
						<span class="tc-card-icon"><span class="dashicons dashicons-archive"></span></span>
						<span class="tc-card-label"><?php esc_html_e( 'All Other', 'tcgiant-sync' ); ?></span>
						<span class="tc-card-desc"><?php esc_html_e( 'Electronics, Clothing, Collectibles', 'tcgiant-sync' ); ?></span>
					</div>
				</div>
			</div>
		</div>

		<?php // == STEP 2: CATEGORY ==
			  $is_custom_cat = ! empty( $cat_override ) && ! isset( $tcg_categories[ $cat_override ] );
			  $select_val    = $is_custom_cat ? 'custom' : $cat_override;
			  $eff_cat       = $merged_settings['category_id'] ?? '';
		?>
		<div class="tc-ebay-section" id="tc-section-category">
			<div class="tc-ebay-section-head <?php echo ! empty( $item_type ) ? 'open' : ''; ?>" data-target="tc-body-category">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<span><?php esc_html_e( '2. eBay Category', 'tcgiant-sync' ); ?></span>
				<span id="tc-step2-summary" style="font-weight:400;color:#888;font-size:12px;margin-left:auto;"><?php
					if ( $cat_name_saved && $cat_override ) { echo esc_html( $cat_name_saved . ' (' . $cat_override . ')' ); }
					elseif ( $eff_cat ) { echo esc_html( $eff_cat ); }
				?></span>
			</div>
			<div class="tc-ebay-section-body <?php echo ! empty( $item_type ) ? 'open' : ''; ?>" id="tc-body-category">
				<?php if ( $global_cat ) : ?>
					<p style="margin:0 0 6px;font-size:11px;color:#888;"><?php echo esc_html( sprintf( __( 'Global default: %s - override below for this product', 'tcgiant-sync' ), $global_cat ) ); ?></p>
				<?php endif; ?>
				<select name="_ebay_export_category_id_select" id="_ebay_export_category_id_select" style="width:100%;font-size:12px;margin-bottom:4px;">
				<?php foreach ( $tcg_categories as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $select_val, $id ); ?>><?php
						if ( '' === $id ) { echo esc_html( $label ) . ( $global_cat ? ' (Global: ' . esc_html( $global_cat ) . ')' : '' ); }
						else { echo esc_html( $label . ( 'custom' !== $id ? " ($id)" : '' ) ); }
					?></option>
				<?php endforeach; ?>
				</select>
				<input type="text" name="_ebay_export_category_id_custom" id="_ebay_export_category_id_custom" value="<?php echo esc_attr( $is_custom_cat ? $cat_override : '' ); ?>" placeholder="<?php esc_attr_e( 'Enter eBay leaf category ID', 'tcgiant-sync' ); ?>" style="width:100%;font-size:12px;<?php echo 'custom' === $select_val ? '' : 'display:none;'; ?>">
				<input type="hidden" name="_ebay_export_category_name" id="_ebay_export_category_name" value="<?php echo esc_attr( $cat_name_saved ); ?>">
				<div id="tc-selected-category-label-product" style="margin-top:4px;font-size:11px;color:#16a34a;font-weight:500;<?php echo ( $cat_override && $cat_name_saved ) ? '' : 'display:none;'; ?>"><?php
					if ( $cat_override && $cat_name_saved ) { echo '&#10004; ' . esc_html( $cat_name_saved . ' (' . $cat_override . ')' ); }
				?></div>
				<div id="tc-category-suggestions" style="margin-top:4px;">
					<button type="button" id="tc-suggest-category-btn" class="button" style="font-size:11px;margin-top:4px;"><span class="dashicons dashicons-lightbulb" style="font-size:13px;vertical-align:middle;margin-right:2px;"></span><?php esc_html_e( 'Suggest Category from Title', 'tcgiant-sync' ); ?></button>
					<div id="tc-suggest-pills" class="tc-suggest-pills" style="display:none;"></div>
				</div>
				<button type="button" class="button" id="tc-browse-categories-btn-product" style="margin-top:6px;font-size:11px;width:100%;"><span class="dashicons dashicons-category" style="font-size:13px;vertical-align:middle;margin-right:2px;"></span><?php esc_html_e( 'Browse eBay Categories', 'tcgiant-sync' ); ?></button>
				<div id="tc-category-browser-product" style="display:none;margin-top:6px;border:1px solid #e5e5e5;border-radius:4px;padding:8px;background:#f9fafb;">
					<div id="tc-category-breadcrumb-product" style="font-size:11px;color:#666;margin-bottom:6px;"></div>
					<div id="tc-category-drilldown-product" style="max-height:200px;overflow-y:auto;"></div>
					<div id="tc-category-browser-status-product" style="font-size:11px;color:#888;margin-top:4px;"></div>
				</div>
			</div>
		</div>

		<?php // == STEP 3: CONDITION == ?>
		<div class="tc-ebay-section" id="tc-section-condition">
			<div class="tc-ebay-section-head <?php echo ! empty( $item_type ) ? 'open' : ''; ?>" data-target="tc-body-condition">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<span><?php esc_html_e( '3. Condition', 'tcgiant-sync' ); ?></span>
				<span id="tc-step3-summary" style="font-weight:400;color:#888;font-size:12px;margin-left:auto;"><?php
					if ( 'graded' === $cond_type ) {
						$all_g = TCGiant_Sync_Exporter::GRADERS_TCG + TCGiant_Sync_Exporter::GRADERS_COINS;
						$gl    = $grader_id ? array_search( $grader_id, $all_g, true ) : '';
						echo esc_html( 'Graded' . ( $gl ? ' - ' . $gl : '' ) . ( $grade_val ? ' ' . $grade_val : '' ) );
					} elseif ( 'ungraded' === $cond_type ) {
						$all_u = TCGiant_Sync_Exporter::UNGRADED_TCG + TCGiant_Sync_Exporter::UNGRADED_COINS;
						echo esc_html( 'Ungraded' . ( isset( $all_u[ $ungraded ] ) ? ' - ' . $all_u[ $ungraded ] : '' ) );
					}
				?></span>
			</div>
			<div class="tc-ebay-section-body <?php echo ! empty( $item_type ) ? 'open' : ''; ?>" id="tc-body-condition">
				<?php if ( empty( $item_type ) ) : ?>
					<p style="margin:0;font-size:12px;color:#999;"><?php esc_html_e( 'Select an Item Type above first.', 'tcgiant-sync' ); ?></p>
				<?php endif; ?>
				<div id="tc-condition-type-wrapper" style="<?php echo empty( $item_type ) ? 'display:none;' : ''; ?>">
					<p style="margin:0 0 8px;font-size:12px;color:#666;"><?php esc_html_e( 'What condition is this item in?', 'tcgiant-sync' ); ?></p>
					<input type="hidden" name="_ebay_export_condition_type" id="_ebay_export_condition_type" value="<?php echo esc_attr( $cond_type ); ?>">
					<div class="tc-card-selector" style="margin-bottom:10px;">
						<div class="tc-card-option <?php echo 'graded' === $cond_type ? 'selected' : ''; ?>" data-value="graded">
							<span class="tc-card-icon"><span class="dashicons dashicons-awards"></span></span>
							<span class="tc-card-label"><?php esc_html_e( 'Graded', 'tcgiant-sync' ); ?></span>
							<span class="tc-card-desc"><?php esc_html_e( 'Professionally graded & slabbed', 'tcgiant-sync' ); ?></span>
						</div>
						<div class="tc-card-option <?php echo 'ungraded' === $cond_type ? 'selected' : ''; ?>" data-value="ungraded">
							<span class="tc-card-icon"><span class="dashicons dashicons-archive"></span></span>
							<span class="tc-card-label"><?php esc_html_e( 'Ungraded', 'tcgiant-sync' ); ?></span>
							<span class="tc-card-desc"><?php esc_html_e( 'Raw / not professionally graded', 'tcgiant-sync' ); ?></span>
						</div>
					</div>
					<div id="tcgiant-graded-tcg-fields" style="<?php echo ( 'graded' !== $cond_type || 'tcg' !== $item_type ) ? 'display:none;' : ''; ?>"><?php
						$go1 = array( '' => __( '-- Select grader --', 'tcgiant-sync' ) );
						foreach ( TCGiant_Sync_Exporter::GRADERS_TCG as $gn => $gi ) { $go1[ $gi ] = $gn; }
						woocommerce_wp_select( array( 'id' => '_ebay_export_grader_id_tcg', 'label' => __( 'Professional Grader', 'tcgiant-sync' ), 'options' => $go1, 'value' => 'tcg' === $item_type ? $grader_id : '' ) );
						$go2 = array( '' => __( '-- Select grade --', 'tcgiant-sync' ) );
						foreach ( TCGiant_Sync_Exporter::GRADES_TCG as $g ) { $go2[ $g ] = $g; }
						woocommerce_wp_select( array( 'id' => '_ebay_export_grade_value_tcg', 'label' => __( 'Grade', 'tcgiant-sync' ), 'options' => $go2, 'value' => 'tcg' === $item_type ? $grade_val : '' ) );
					?></div>
					<div id="tcgiant-graded-coins-fields" style="<?php echo ( 'graded' !== $cond_type || 'coins' !== $item_type ) ? 'display:none;' : ''; ?>"><?php
						$co1 = array( '' => __( '-- Select grader --', 'tcgiant-sync' ) );
						foreach ( TCGiant_Sync_Exporter::GRADERS_COINS as $gn => $gi ) { $co1[ $gi ] = $gn; }
						woocommerce_wp_select( array( 'id' => '_ebay_export_grader_id_coins', 'label' => __( 'Professional Grader', 'tcgiant-sync' ), 'options' => $co1, 'value' => 'coins' === $item_type ? $grader_id : '' ) );
						$co2 = array( '' => __( '-- Select grade --', 'tcgiant-sync' ) );
						foreach ( TCGiant_Sync_Exporter::GRADES_COINS as $g ) { $co2[ $g ] = $g; }
						woocommerce_wp_select( array( 'id' => '_ebay_export_grade_value_coins', 'label' => __( 'Grade', 'tcgiant-sync' ), 'options' => $co2, 'value' => 'coins' === $item_type ? $grade_val : '' ) );
					?></div>
					<div id="tcgiant-graded-shared-fields" style="<?php echo ( 'graded' !== $cond_type || empty( $item_type ) ) ? 'display:none;' : ''; ?>"><?php
						woocommerce_wp_text_input( array( 'id' => '_ebay_export_cert_number', 'label' => __( 'Cert Number', 'tcgiant-sync' ), 'placeholder' => __( 'e.g. 12345678', 'tcgiant-sync' ), 'value' => $cert_num, 'desc_tip' => true, 'description' => __( 'The certification/slab number from the grading company.', 'tcgiant-sync' ) ) );
					?></div>
					<div id="tcgiant-coins-year-field" style="<?php echo 'coins' !== $item_type ? 'display:none;' : ''; ?>"><?php
						woocommerce_wp_text_input( array( 'id' => '_ebay_export_coin_year', 'label' => __( 'Year', 'tcgiant-sync' ), 'placeholder' => __( 'e.g. 1921', 'tcgiant-sync' ), 'value' => $coin_year, 'desc_tip' => true, 'description' => __( 'The year of the coin. Required by eBay for all Coins & Paper Money categories.', 'tcgiant-sync' ) ) );
					?></div>
					<div id="tcgiant-ungraded-tcg-fields" style="<?php echo ( 'ungraded' !== $cond_type || 'tcg' !== $item_type ) ? 'display:none;' : ''; ?>"><?php
						$uo1 = array( '' => __( '-- Select condition --', 'tcgiant-sync' ) );
						foreach ( TCGiant_Sync_Exporter::UNGRADED_TCG as $uid => $ul ) { $uo1[ $uid ] = $ul; }
						woocommerce_wp_select( array( 'id' => '_ebay_export_ungraded_condition_tcg', 'label' => __( 'Condition', 'tcgiant-sync' ), 'options' => $uo1, 'value' => 'tcg' === $item_type ? $ungraded : '' ) );
					?></div>
					<div id="tcgiant-ungraded-coins-fields" style="<?php echo ( 'ungraded' !== $cond_type || 'coins' !== $item_type ) ? 'display:none;' : ''; ?>"><?php
						$uo2 = array( '' => __( '-- Select condition --', 'tcgiant-sync' ) );
						foreach ( TCGiant_Sync_Exporter::UNGRADED_COINS as $uid => $ul ) { $uo2[ $uid ] = $ul; }
						woocommerce_wp_select( array( 'id' => '_ebay_export_ungraded_condition_coins', 'label' => __( 'Condition', 'tcgiant-sync' ), 'options' => $uo2, 'value' => 'coins' === $item_type ? $ungraded : '' ) );
					?></div>
				</div>
			</div>
		</div>

		<?php // == STEP 4: LISTING OPTIONS == ?>
		<div class="tc-ebay-section" id="tc-section-listing-options">
			<div class="tc-ebay-section-head <?php echo ! empty( $item_type ) ? 'open' : ''; ?>" data-target="tc-body-listing-options">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<span><?php esc_html_e( '4. Listing Options', 'tcgiant-sync' ); ?></span>
				<span id="tc-step4-summary" style="font-weight:400;color:#888;font-size:12px;margin-left:auto;"><?php
					$lt = $merged_settings['listing_type'] ?? 'FixedPriceItem';
					$ld = $merged_settings['listing_duration'] ?? 'GTC';
					$lt_label = TCGiant_Sync_Exporter::LISTING_TYPES[ $lt ] ?? $lt;
					$ld_label = TCGiant_Sync_Exporter::LISTING_DURATIONS[ $ld ] ?? $ld;
					echo esc_html( $lt_label . ' · ' . $ld_label );
				?></span>
			</div>
			<div class="tc-ebay-section-body <?php echo ! empty( $item_type ) ? 'open' : ''; ?>" id="tc-body-listing-options">
				<?php
				// Listing Type.
				woocommerce_wp_select( array(
					'id'      => '_ebay_export_listing_type',
					'label'   => __( 'Listing Type', 'tcgiant-sync' ),
					'options' => array_merge(
						array( '' => __( '-- use profile setting --', 'tcgiant-sync' ) ),
						TCGiant_Sync_Exporter::LISTING_TYPES
					),
					'value'   => $listing_type_override,
				) );

				// Listing Duration.
				woocommerce_wp_select( array(
					'id'      => '_ebay_export_listing_duration',
					'label'   => __( 'Listing Duration', 'tcgiant-sync' ),
					'options' => array_merge(
						array( '' => __( '-- use profile setting --', 'tcgiant-sync' ) ),
						TCGiant_Sync_Exporter::LISTING_DURATIONS
					),
					'value'   => $listing_dur_override,
				) );

				// Shipping Policy Override.
				$fulfillment_policies = array( '' => __( '-- use profile setting --', 'tcgiant-sync' ) );
				$cached_policies = array( 'fulfillment' => get_option( 'tcgiant_export_policies_fulfillment', array() ) );
				if ( ! empty( $cached_policies['fulfillment'] ) && is_array( $cached_policies['fulfillment'] ) ) {
					foreach ( $cached_policies['fulfillment'] as $fp ) {
						if ( ! empty( $fp['id'] ) ) {
							$fulfillment_policies[ $fp['id'] ] = $fp['name'] ?? $fp['id'];
						}
					}
				}
				// Also include the currently-saved policy if it wasn't in the cache.
				if ( ! empty( $fulfillment_override ) && ! isset( $fulfillment_policies[ $fulfillment_override ] ) ) {
					$fulfillment_policies[ $fulfillment_override ] = $fulfillment_override;
				}
				woocommerce_wp_select( array(
					'id'          => '_ebay_export_fulfillment_policy',
					'label'       => __( 'Shipping Policy', 'tcgiant-sync' ),
					'options'     => $fulfillment_policies,
					'value'       => $fulfillment_override,
					'desc_tip'    => true,
					'description' => __( 'Override the global shipping/fulfillment policy for this product. Fetch policies from the Settings page first.', 'tcgiant-sync' ),
				) );
				?>
				<p style="margin:4px 0 0;font-size:11px;color:#888;padding-left:12px;">
					<?php esc_html_e( 'eBay rules: Fixed Price supports GTC & 30 Days. Auctions support 1, 3, 5, 7, or 10 Days.', 'tcgiant-sync' ); ?>
				</p>
			</div>
		</div>

		<?php // == STEP 5: READINESS + PUSH ==
			  $checks = array();
			  // Category
			  if ( ! empty( $eff_cat ) ) {
				  $cd = $cat_name_saved ? $cat_name_saved . ' (' . $eff_cat . ')' : $eff_cat;
				  $mm = ! empty( $merged_settings['_category_mismatch'] );
				  $checks[] = array( 'ok' => ! $mm, 'label' => $mm ? sprintf( __( 'Category: %s - item type mismatch', 'tcgiant-sync' ), $cd ) : sprintf( __( 'Category: %s', 'tcgiant-sync' ), $cd ) );
			  } else { $checks[] = array( 'ok' => false, 'label' => __( 'Category: Not set', 'tcgiant-sync' ) ); }
			  // Condition
			  $cte = $merged_settings['condition_type'] ?? ''; $ite = $merged_settings['item_type'] ?? '';
			  if ( 'other' === $ite ) {
				  // "All Other" uses simple ConditionID, no grading required.
				  $conditions = TCGiant_Sync_Exporter::CONDITIONS;
				  $cond_id    = $merged_settings['condition_id'] ?? '1000';
				  $cond_label = $conditions[ $cond_id ] ?? $cond_id;
				  $checks[] = array( 'ok' => true, 'label' => 'Condition: ' . $cond_label );
			  } elseif ( ! empty( $cte ) ) {
				  if ( 'graded' === $cte ) {
					  $gi = $merged_settings['grader_id'] ?? ''; $gv = $merged_settings['grade_value'] ?? '';
					  $gm = 'coins' === $ite ? TCGiant_Sync_Exporter::GRADERS_COINS : TCGiant_Sync_Exporter::GRADERS_TCG;
					  $gn = $gi ? array_search( $gi, $gm, true ) : '';
					  $cl = 'Graded' . ( $gn ? ' - ' . $gn : '' ) . ( $gv ? ' ' . $gv : '' );
					  $co = ! empty( $gi ) && ! empty( $gv );
					  if ( ! $co ) { $cl .= ' - grader and grade required'; }
				  } else {
					  $uv = $merged_settings['ungraded_condition'] ?? '';
					  $um = 'coins' === $ite ? TCGiant_Sync_Exporter::UNGRADED_COINS : TCGiant_Sync_Exporter::UNGRADED_TCG;
					  $cl = 'Ungraded' . ( isset( $um[ $uv ] ) ? ' - ' . $um[ $uv ] : '' );
					  $co = ! empty( $uv );
					  if ( ! $co ) { $cl .= ' - condition required'; }
				  }
				  $checks[] = array( 'ok' => $co, 'label' => 'Condition: ' . $cl );
			  } else { $checks[] = array( 'ok' => false, 'label' => __( 'Condition: Not set', 'tcgiant-sync' ) ); }
			  // Policies
			  $hp = ! empty( $merged_settings['fulfillment_policy_id'] ) && ! empty( $merged_settings['return_policy_id'] ) && ! empty( $merged_settings['payment_policy_id'] );
			  $checks[] = array( 'ok' => $hp, 'label' => $hp ? __( 'Business Policies: Configured', 'tcgiant-sync' ) : __( 'Business Policies: Missing - configure in Settings', 'tcgiant-sync' ) );
			  // Images
			  $ii = $product ? array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) : array();
			  $io = count( $ii ) > 0;
			  $checks[] = array( 'ok' => $io, 'label' => $io ? sprintf( __( 'Images: %d photo(s)', 'tcgiant-sync' ), count( $ii ) ) : __( 'Images: No photos - eBay requires at least 1', 'tcgiant-sync' ) );
			  // Title
			  $tl = $product ? mb_strlen( $product->get_name() ) : 0;
			  $checks[] = array( 'ok' => $tl <= 80 && $tl > 0, 'label' => sprintf( __( 'Title: %d/80 characters', 'tcgiant-sync' ), $tl ) . ( $tl > 80 ? ' - will be truncated' : '' ) );
			  $all_ok = true;
			  foreach ( $checks as $c ) { if ( ! $c['ok'] ) { $all_ok = false; break; } }
		?>
		<div class="tc-ebay-section" id="tc-section-push">
			<div class="tc-ebay-section-head open" data-target="tc-body-push">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<span><?php esc_html_e( '5. Push to eBay', 'tcgiant-sync' ); ?></span>
			</div>
			<div class="tc-ebay-section-body open" id="tc-body-push">
				<div class="tc-readiness <?php echo $all_ok ? '' : 'has-issues'; ?>">
					<div class="tc-readiness-title" style="color:<?php echo $all_ok ? '#1e7e34' : '#721c24'; ?>;"><?php echo $all_ok ? '&#10004; ' . esc_html__( 'Ready to push', 'tcgiant-sync' ) : '&#9888; ' . esc_html__( 'Not ready - fix issues above', 'tcgiant-sync' ); ?></div>
					<?php foreach ( $checks as $c ) : ?>
						<div class="tc-readiness-check" style="color:<?php echo $c['ok'] ? '#333' : '#721c24'; ?>;"><?php echo $c['ok'] ? '<span style="color:#1e7e34;">&#10004;</span>' : '<span style="color:#dc3545;">&#10008;</span>'; ?> <?php echo esc_html( $c['label'] ); ?></div>
					<?php endforeach; ?>
				</div>
				<?php $bl = ! empty( $ebay_item_id ) ? __( 'Update eBay Listing', 'tcgiant-sync' ) : __( 'Push to eBay', 'tcgiant-sync' ); ?>
				<div style="display:flex;gap:8px;align-items:center;margin-top:4px;">
					<button type="button" id="tcgiant-push-btn" class="button button-primary" data-product-id="<?php echo esc_attr( $product_id ); ?>" style="font-size:13px;padding:4px 16px;"><span class="dashicons dashicons-upload" style="font-size:16px;width:16px;height:16px;vertical-align:middle;margin-right:4px;"></span><?php echo esc_html( $bl ); ?></button>
					<?php if ( empty( $ebay_item_id ) ) : ?>
					<button type="button" id="tcgiant-verify-btn" class="button" data-product-id="<?php echo esc_attr( $product_id ); ?>" style="font-size:12px;padding:4px 12px;" title="<?php esc_attr_e( 'Test listing without creating it — shows fees and catches errors', 'tcgiant-sync' ); ?>"><span class="dashicons dashicons-visibility" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:3px;"></span><?php esc_html_e( 'Verify', 'tcgiant-sync' ); ?></button>
					<?php endif; ?>
				</div>
				<span id="tcgiant-push-status" style="display:block;margin-top:6px;font-size:12px;color:#555;"></span>
			</div>
		</div>

		<?php // == SYNC LOG (collapsed) ==
			  $logs = get_post_meta( $product_id, '_tcgiant_sync_log', true );
		?>
		<div class="tc-ebay-section" id="tc-section-sync-log">
			<div class="tc-ebay-section-head" data-target="tc-body-sync-log">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<span><?php esc_html_e( 'Import Sync Log', 'tcgiant-sync' ); ?></span>
				<?php if ( ! empty( $logs ) && is_array( $logs ) ) : ?>
					<span style="font-weight:400;color:#888;font-size:11px;margin-left:auto;"><?php echo esc_html( count( $logs ) . ' entries' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="tc-ebay-section-body" id="tc-body-sync-log">
				<?php if ( empty( $logs ) || ! is_array( $logs ) ) : ?>
					<p style="color:#888;margin:0;font-size:12px;"><?php esc_html_e( 'No import sync decisions logged yet.', 'tcgiant-sync' ); ?></p>
				<?php else : ?>
					<div style="max-height:250px;overflow-y:auto;">
					<?php foreach ( $logs as $entry ) : ?>
						<div style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #eee;">
							<strong style="color:#666;font-size:11px;"><?php echo esc_html( $entry['timestamp'] ?? 'Unknown Time' ); ?></strong>
							<?php if ( ! empty( $entry['decisions'] ) && is_array( $entry['decisions'] ) ) : ?>
								<ul style="margin-top:3px;padding-left:16px;margin-bottom:0;"><?php foreach ( $entry['decisions'] as $d ) : ?><li style="font-size:12px;"><?php echo esc_html( $d ); ?></li><?php endforeach; ?></ul>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>

		</div></div>

		<script>
		(function($){
			// Accordion
			$('.tc-ebay-section-head').on('click',function(){var $h=$(this),$b=$('#'+$h.data('target'));$h.toggleClass('open');if($b.hasClass('open')){$b.removeClass('open').slideUp(200);}else{$b.addClass('open').slideDown(200);}});
			// Item Type cards
			$('#tc-section-item-type .tc-card-option').on('click',function(){var v=$(this).data('value');$('#tc-section-item-type .tc-card-option').removeClass('selected');$(this).addClass('selected');$('#_ebay_export_item_type').val(v);$('#tc-step1-summary').text(v==='coins'?'Coins':v==='other'?'All Other':'Trading Cards');$('#tc-section-category .tc-ebay-section-head,#tc-section-listing-options .tc-ebay-section-head').addClass('open');$('#tc-body-category,#tc-body-listing-options').addClass('open').slideDown(200);if(v==='other'){$('#tc-section-condition .tc-ebay-section-head').addClass('open');$('#tc-body-condition').addClass('open').slideDown(200);$('#tc-condition-type-wrapper').hide();$('#tc-body-condition p[style*="color:#999"]').hide();}else{$('#tc-section-condition .tc-ebay-section-head').addClass('open');$('#tc-body-condition').addClass('open').slideDown(200);$('#tc-condition-type-wrapper').show();$('#tc-body-condition p[style*="color:#999"]').hide();}tcGradingToggle();});
			// Condition Type cards
			$('#tc-section-condition .tc-card-selector .tc-card-option').on('click',function(){var v=$(this).data('value');$('#tc-section-condition .tc-card-selector .tc-card-option').removeClass('selected');$(this).addClass('selected');$('#_ebay_export_condition_type').val(v);tcGradingToggle();});
			// Grading toggle
			function tcGradingToggle(){var it=$('#_ebay_export_item_type').val(),ct=$('#_ebay_export_condition_type').val();if(it==='other'){$('#tcgiant-graded-tcg-fields,#tcgiant-graded-coins-fields,#tcgiant-graded-shared-fields,#tcgiant-ungraded-tcg-fields,#tcgiant-ungraded-coins-fields,#tcgiant-coins-year-field').hide();$('#tc-condition-type-wrapper').hide();return;}$('#tcgiant-graded-tcg-fields').toggle(it==='tcg'&&ct==='graded');$('#tcgiant-graded-coins-fields').toggle(it==='coins'&&ct==='graded');$('#tcgiant-graded-shared-fields').toggle(it!==''&&ct==='graded');$('#tcgiant-ungraded-tcg-fields').toggle(it==='tcg'&&ct==='ungraded');$('#tcgiant-ungraded-coins-fields').toggle(it==='coins'&&ct==='ungraded');$('#tcgiant-coins-year-field').toggle(it==='coins');}
			// Category dropdown
			$('#_ebay_export_category_id_select').on('change',function(){$('#_ebay_export_category_id_custom').toggle($(this).val()==='custom');});
			// Category browser
			var ajaxUrl='<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',nonce='<?php echo esc_js( wp_create_nonce( 'tcgiant_sync_ajax' ) ); ?>',trail=[];
			$('#tc-browse-categories-btn-product').on('click',function(){var $b=$('#tc-category-browser-product');$b.toggle();if($b.is(':visible')&&$('#tc-category-drilldown-product').children().length===0){var it=$('#_ebay_export_item_type').val(),sid='';if(it==='coins'){sid='11116';trail=[{id:'11116',name:'Coins & Paper Money'}];}else if(it==='tcg'){sid='2536';trail=[{id:'2536',name:'Toys & Hobbies'}];}renderBread();loadCats(sid);}});
			function loadCats(pid){var $d=$('#tc-category-drilldown-product'),$s=$('#tc-category-browser-status-product');$d.html('<div style="text-align:center;padding:12px;color:#888;">Loading...</div>');$s.text('');$.post(ajaxUrl,{action:'tcgiant_browse_categories',_ajax_nonce:nonce,parent_id:pid},function(r){if(!r.success){$d.html('<div style="color:#c00;padding:6px;">'+r.data.message+'</div>');return;}renderCats(r.data.categories);}).fail(function(){$d.html('<div style="color:#c00;padding:6px;">Request failed.</div>');});}
			function renderCats(cats){var $d=$('#tc-category-drilldown-product');$d.empty();if(!cats||!cats.length){$d.html('<div style="padding:6px;color:#888;">No subcategories.</div>');return;}$.each(cats,function(i,c){var $r=$('<div style="display:flex;align-items:center;justify-content:space-between;padding:5px 6px;border-bottom:1px solid #eee;cursor:pointer;font-size:12px;"></div>');$r.append($('<span style="flex:1;"></span>').text(c.name));$r.append(c.leaf?'<span style="color:#16a34a;font-size:10px;font-weight:600;">leaf</span>':'<span class="dashicons dashicons-arrow-right-alt2" style="color:#888;font-size:13px;width:13px;height:13px;"></span>');$r.on('mouseenter',function(){$(this).css('background','#f0f6fc');}).on('mouseleave',function(){$(this).css('background','');});$r.on('click',function(){if(c.leaf){selectCat(c.id,c.name);$('#tc-category-browser-product').slideUp(200);trail=[];}else{trail.push({id:c.id,name:c.name});renderBread();loadCats(c.id);}});$d.append($r);});}
			function selectCat(id,name){$('#_ebay_export_category_id_select').val('custom');$('#_ebay_export_category_id_custom').val(id).show();$('#_ebay_export_category_name').val(name);$('#tc-selected-category-label-product').html('&#10004; '+name+' ('+id+')').show();$('#tc-step2-summary').text(name+' ('+id+')');}
			function renderBread(){var $bc=$('#tc-category-breadcrumb-product');$bc.empty();var $root=$('<a href="#" style="color:#2271b1;text-decoration:none;font-size:11px;">All</a>');$root.on('click',function(e){e.preventDefault();trail=[];renderBread();loadCats('');});$bc.append($root);$.each(trail,function(i,c){$bc.append('<span style="margin:0 3px;color:#aaa;"> &rsaquo; </span>');if(i<trail.length-1){var $l=$('<a href="#" style="color:#2271b1;text-decoration:none;font-size:11px;"></a>').text(c.name);(function(idx){$l.on('click',function(e){e.preventDefault();trail=trail.slice(0,idx+1);renderBread();loadCats(c.id);});})(i);$bc.append($l);}else{$bc.append($('<b style="font-size:11px;"></b>').text(c.name));}});}
			// Category suggestion
			$('#tc-suggest-category-btn').on('click',function(){var btn=$(this),$p=$('#tc-suggest-pills'),title='<?php echo esc_js( $product ? $product->get_name() : '' ); ?>';if(!title)return;btn.prop('disabled',true).text('Searching...');$.post(ajaxUrl,{action:'tcgiant_suggest_category',_ajax_nonce:nonce,title:title},function(r){btn.prop('disabled',false).html('<span class="dashicons dashicons-lightbulb" style="font-size:13px;vertical-align:middle;margin-right:2px;"></span> Suggest Category from Title');if(r.success&&r.data.suggestions&&r.data.suggestions.length){$p.empty().show();$.each(r.data.suggestions,function(i,s){var $pill=$('<span class="tc-suggest-pill"></span>').text(s.name+' ('+s.id+')');$pill.on('click',function(){selectCat(s.id,s.name);$p.slideUp(200);});$p.append($pill);});}else{$p.html('<span style="font-size:11px;color:#888;">No suggestions found.</span>').show();}}).fail(function(){btn.prop('disabled',false).html('<span class="dashicons dashicons-lightbulb" style="font-size:13px;vertical-align:middle;margin-right:2px;"></span> Suggest Category from Title');});});
			// Dismiss error
			$('#tcgiant-dismiss-export-error').on('click',function(e){e.preventDefault();$.post(ajaxUrl,{action:'tcgiant_clear_export_error',product_id:$(this).data('product-id'),_ajax_nonce:nonce});$('#tcgiant-export-error-notice').slideUp(200);});
			// Push
			$('#tcgiant-push-btn').on('click',function(){var btn=$(this),st=$('#tcgiant-push-status');btn.prop('disabled',true);st.css('color','#555').text('Saving & validating...');var cs=$('#_ebay_export_category_id_select').val()||'',cc=$('#_ebay_export_category_id_custom').val()||'',catId=(cs==='custom')?cc:cs,it=$('#_ebay_export_item_type').val()||'',sf=(it&&it!=='other')?'_'+it:'',gid=$('#_ebay_export_grader_id'+sf).val()||'',gv=$('#_ebay_export_grade_value'+sf).val()||'',uc=$('#_ebay_export_ungraded_condition'+sf).val()||'';$.post(ajaxUrl,{action:'tcgiant_push_product',product_id:btn.data('product-id'),override_category_id:catId,override_condition_id:$('#_ebay_export_condition_id').val()||'',override_item_type:it,override_condition_type:$('#_ebay_export_condition_type').val()||'',override_grader_id:gid,override_grade_value:gv,override_cert_number:$('[name="_ebay_export_cert_number"]').val()||'',override_coin_year:$('[name="_ebay_export_coin_year"]').val()||'',override_ungraded_condition:uc,override_listing_type:$('#_ebay_export_listing_type').val()||'',override_listing_duration:$('#_ebay_export_listing_duration').val()||'',override_fulfillment_policy:$('#_ebay_export_fulfillment_policy').val()||'',_ajax_nonce:nonce},function(r){if(r.success){st.css('color','#2a8a2a').text('OK - '+r.data.message);}else{st.css('color','#cc1818').text('Error: '+(r.data?r.data.message:'Unknown error'));btn.prop('disabled',false);}});});
			// Duration filtering based on listing type.
			var fpDurations={'FixedPriceItem':['GTC','Days_30'],'Chinese':['Days_1','Days_3','Days_5','Days_7','Days_10']};
			$('#_ebay_export_listing_type').on('change',function(){var lt=$(this).val(),$dur=$('#_ebay_export_listing_duration');if(!lt){$dur.find('option').show();return;}var valid=fpDurations[lt]||[];$dur.find('option').each(function(){var v=$(this).val();if(!v){$(this).show();}else{$(this).toggle(valid.indexOf(v)>=0);}});if(valid.indexOf($dur.val())<0){$dur.val('');}});
			// Verify (dry run)
			$('#tcgiant-verify-btn').on('click',function(){var btn=$(this),st=$('#tcgiant-push-status');btn.prop('disabled',true);st.css('color','#555').text('Verifying listing...');var cs=$('#_ebay_export_category_id_select').val()||'',cc=$('#_ebay_export_category_id_custom').val()||'',catId=(cs==='custom')?cc:cs,it=$('#_ebay_export_item_type').val()||'',sf=(it&&it!=='other')?'_'+it:'',gid=$('#_ebay_export_grader_id'+sf).val()||'',gv=$('#_ebay_export_grade_value'+sf).val()||'',uc=$('#_ebay_export_ungraded_condition'+sf).val()||'';$.post(ajaxUrl,{action:'tcgiant_verify_product',product_id:btn.data('product-id'),override_category_id:catId,override_condition_id:$('#_ebay_export_condition_id').val()||'',override_item_type:it,override_condition_type:$('#_ebay_export_condition_type').val()||'',override_grader_id:gid,override_grade_value:gv,override_cert_number:$('[name="_ebay_export_cert_number"]').val()||'',override_coin_year:$('[name="_ebay_export_coin_year"]').val()||'',override_ungraded_condition:uc,override_listing_type:$('#_ebay_export_listing_type').val()||'',override_listing_duration:$('#_ebay_export_listing_duration').val()||'',override_fulfillment_policy:$('#_ebay_export_fulfillment_policy').val()||'',_ajax_nonce:nonce},function(r){btn.prop('disabled',false);if(r.success){st.css('color','#2a8a2a').html(r.data.message.replace(/\n/g,'<br>'));}else{st.css('color','#cc1818').text('Verify failed: '+(r.data?r.data.message:'Unknown error'));}}).fail(function(){btn.prop('disabled',false);st.css('color','#cc1818').text('Verify request failed.');});});
		})(jQuery);
		</script>
		<?php
	}


	/**
	 * Add BIN Location field to WooCommerce Simple Product Inventory tab.
	 */
	public function add_bin_location_field() {
		echo '<div class="options_group">';
		woocommerce_wp_text_input( array(
			'id'          => '_bin_location',
			'label'       => __( 'BIN', 'tcgiant-sync' ),
			'placeholder' => 'Enter product BIN location here e.g R2S3B2',
			'desc_tip'    => 'true',
			'description' => __( 'The physical bin location of the product.', 'tcgiant-sync' ),
		) );
		echo '</div>';
	}

	/**
	 * Save BIN Location field for Simple Products.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_bin_location_field( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['_bin_location'] ) ) {
			$bin_location = sanitize_text_field( wp_unslash( $_POST['_bin_location'] ) );
			update_post_meta( $post_id, '_bin_location', $bin_location );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Add BIN Location field to WooCommerce Variation Inventory options.
	 *
	 * @param int     $loop           Position in the loop.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Post data.
	 */
	public function add_variation_bin_location_field( $loop, $variation_data, $variation ) {
		$bin_location = get_post_meta( $variation->ID, '_bin_location', true );
		
		echo '<div class="options_group form-row form-row-full">';
		woocommerce_wp_text_input( array(
			'id'            => '_bin_location[' . $loop . ']',
			'label'         => __( 'BIN', 'tcgiant-sync' ),
			'placeholder'   => 'Enter product BIN location here e.g R2S3B2',
			'value'         => $bin_location,
			'wrapper_class' => 'form-row form-row-full',
		) );
		echo '</div>';
	}

	/**
	 * Save BIN Location field for Variations.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Loop position.
	 */
	public function save_variation_bin_location_field( $variation_id, $i ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['_bin_location'][ $i ] ) ) {
			$bin_location = sanitize_text_field( wp_unslash( $_POST['_bin_location'][ $i ] ) );
			update_post_meta( $variation_id, '_bin_location', $bin_location );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	// =========================================================================
	// eBay Column on WooCommerce Products List
	// =========================================================================

	/**
	 * Add the "eBay" column to the WooCommerce products list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_ebay_column( $columns ) {
		$columns['tcgiant_ebay'] = __( 'Push to eBay', 'tcgiant-sync' );
		return $columns;
	}

	/**
	 * Render the "eBay" column content for each product row.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product (post) ID.
	 */
	public function render_ebay_column( $column, $post_id ) {
		if ( 'tcgiant_ebay' !== $column ) {
			return;
		}

		$ebay_item_id  = get_post_meta( $post_id, '_ebay_item_id', true );
		$last_pushed   = get_post_meta( $post_id, '_ebay_export_last_pushed', true );
		$export_status = get_post_meta( $post_id, '_ebay_export_status', true );
		$export_error  = get_post_meta( $post_id, '_ebay_export_error', true );
		$cat_override  = get_post_meta( $post_id, '_ebay_export_category_id', true );
		$cat_name      = get_post_meta( $post_id, '_ebay_export_category_name', true );

		$global_settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$global_cat      = $global_settings['export_category_id'] ?? '';
		$global_cat_name = $global_settings['export_category_name'] ?? '';

		// Determine which category to display.
		if ( ! empty( $cat_override ) ) {
			$display_cat_id   = $cat_override;
			$display_cat_name = ! empty( $cat_name ) ? $cat_name : '';
			$cat_source       = '';
		} elseif ( ! empty( $global_cat ) ) {
			$display_cat_id   = $global_cat;
			$display_cat_name = ! empty( $global_cat_name ) ? $global_cat_name : '';
			$cat_source       = 'global';
		} else {
			$display_cat_id   = '';
			$display_cat_name = '';
			$cat_source       = '';
		}

		// Also check the built-in categories list for a label.
		if ( ! empty( $display_cat_id ) && empty( $display_cat_name ) ) {
			$all_cats = TCGiant_Sync_Exporter::get_categories();
			if ( isset( $all_cats[ $display_cat_id ] ) ) {
				$display_cat_name = $all_cats[ $display_cat_id ];
			}
		}

		echo '<div class="tc-ebay-col">';

		// Category line.
		if ( ! empty( $display_cat_id ) ) {
			$cat_label = ! empty( $display_cat_name ) ? esc_html( $display_cat_name ) : esc_html( $display_cat_id );
			$source_badge = 'global' === $cat_source ? ' <span class="tc-ebay-global">(global)</span>' : '';
			echo '<div class="tc-ebay-cat" title="eBay Category ID: ' . esc_attr( $display_cat_id ) . '">' . $cat_label . $source_badge . '</div>';
		} else {
			echo '<div class="tc-ebay-cat tc-ebay-nocat">No eBay category</div>';
		}

		// eBay Item ID link if already pushed.
		if ( ! empty( $ebay_item_id ) ) {
			echo '<div class="tc-ebay-itemid"><a href="https://www.ebay.com/itm/' . esc_attr( $ebay_item_id ) . '" target="_blank" rel="noopener" title="View on eBay">Item ' . esc_html( $ebay_item_id ) . ' ↗</a></div>';
		}

		// Listing type, duration, and end time badges.
		$listing_type   = get_post_meta( $post_id, '_ebay_listing_type', true );
		$listing_dur    = get_post_meta( $post_id, '_ebay_listing_duration', true );
		$end_time       = get_post_meta( $post_id, '_ebay_end_time', true );
		$listing_status = get_post_meta( $post_id, '_ebay_listing_status', true );

		if ( ! empty( $listing_type ) ) {
			// Type badge.
			$type_labels = array(
				'Chinese'        => array( 'Auction', 'tc-badge-auction' ),
				'FixedPriceItem' => array( 'Fixed Price', 'tc-badge-fixed' ),
			);
			$type_info = $type_labels[ $listing_type ] ?? array( $listing_type, 'tc-badge-fixed' );

			// Duration label.
			$dur_labels = array(
				'Days_1'  => '1 Day',
				'Days_3'  => '3 Days',
				'Days_5'  => '5 Days',
				'Days_7'  => '7 Days',
				'Days_10' => '10 Days',
				'Days_30' => '30 Days',
				'GTC'     => 'GTC',
			);
			$dur_label = $dur_labels[ $listing_dur ] ?? $listing_dur;

			echo '<div class="tc-ebay-listing-info">';
			echo '<span class="tc-ebay-badge ' . esc_attr( $type_info[1] ) . '">' . esc_html( $type_info[0] ) . '</span>';
			if ( ! empty( $dur_label ) ) {
				echo ' <span class="tc-ebay-duration">' . esc_html( $dur_label ) . '</span>';
			}
			echo '</div>';

			// End time / status.
			if ( ! empty( $end_time ) ) {
				$end_ts   = strtotime( $end_time );
				$is_ended = ( 'Ended' === $listing_status ) || ( $end_ts && $end_ts < time() );

				if ( $is_ended ) {
					echo '<div class="tc-ebay-end tc-ended">⛔ Ended ' . esc_html( date_i18n( 'M j, Y', $end_ts ) ) . '</div>';
				} else {
					echo '<div class="tc-ebay-end">⏰ Ends ' . esc_html( date_i18n( 'M j, Y', $end_ts ) ) . '</div>';
				}
			}
		}

		// Error notice — show actual error message and link to logs.
		if ( 'error' === $export_status && ! empty( $export_error ) ) {
			$log_url = admin_url( 'admin.php?page=tcgiant-export' );
			// Truncate very long error messages for the column display.
			$display_error = mb_strlen( $export_error ) > 120 ? mb_substr( $export_error, 0, 117 ) . '…' : $export_error;
			echo '<div class="tc-ebay-error" title="' . esc_attr( $export_error ) . '">';
			echo '<a href="' . esc_url( $log_url ) . '" style="color:#d63638;text-decoration:none;">⚠ ' . esc_html( $display_error ) . '</a>';
			echo '</div>';
		}

		// Last pushed date.
		if ( ! empty( $last_pushed ) ) {
			echo '<div class="tc-ebay-pushed">✔ ' . esc_html( date_i18n( 'M j, Y', strtotime( $last_pushed ) ) ) . '</div>';
		}

		// Push/Update button.
		$btn_label = ! empty( $ebay_item_id ) ? '↺ Update' : '📤 Push to eBay';
		$btn_class = ! empty( $ebay_item_id ) ? 'tc-ebay-btn tc-ebay-btn-update' : 'tc-ebay-btn tc-ebay-btn-push';
		echo '<button type="button" class="' . esc_attr( $btn_class ) . '" data-product-id="' . esc_attr( $post_id ) . '">' . esc_html( $btn_label ) . '</button>';
		echo '<span class="tc-ebay-btn-status" data-product-id="' . esc_attr( $post_id ) . '"></span>';

		// End Listing button — only show for active (non-ended) listings.
		$is_ended = ( 'Ended' === $listing_status ) || ( ! empty( $end_time ) && strtotime( $end_time ) && strtotime( $end_time ) < time() );
		if ( ! empty( $ebay_item_id ) && ! $is_ended ) {
			echo '<button type="button" class="tc-ebay-btn tc-ebay-btn-end" data-product-id="' . esc_attr( $post_id ) . '" title="End this listing on eBay">⛔ End Listing</button>';
		}

		// Unlink — for products that inherited another product's eBay Item ID
		// through duplication. Local only; the eBay listing is not touched.
		if ( ! empty( $ebay_item_id ) ) {
			$shared = TCGiant_Sync_Exporter::find_products_sharing_item_id( $post_id, $ebay_item_id );
			if ( ! empty( $shared ) ) {
				echo '<button type="button" class="tc-ebay-btn tc-ebay-btn-unlink" data-product-id="' . esc_attr( $post_id ) . '" '
					. 'title="' . esc_attr__( 'This product shares an eBay listing with another product. Unlink it so it can be listed separately. The eBay listing is not changed.', 'tcgiant-sync' ) . '">'
					. '🔗 ' . esc_html__( 'Unlink from eBay', 'tcgiant-sync' ) . '</button>';
				echo '<span class="tc-ebay-shared-warning" style="display:block;margin-top:4px;color:#b32d2e;font-size:11px;">'
					. esc_html( sprintf(
						/* translators: %s: comma-separated product IDs */
						__( 'Shares eBay listing with product #%s — push and end are blocked until unlinked.', 'tcgiant-sync' ),
						implode( ', #', $shared )
					) )
					. '</span>';
			}
		}

		echo '</div>';
	}

	/**
	 * Output inline CSS and JS for the eBay column on the product list page.
	 * Hooked to admin_footer-edit.php so it only fires on list table screens.
	 */
	public function render_product_list_inline_assets() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		?>
		<style>
			/* eBay column styling */
			.column-tcgiant_ebay { width: 190px; }
			.tc-ebay-col { font-size: 12px; line-height: 1.5; }
			.tc-ebay-cat {
				color: #1d2327;
				font-weight: 500;
				margin-bottom: 3px;
				white-space: nowrap;
				overflow: hidden;
				text-overflow: ellipsis;
				max-width: 155px;
			}
			.tc-ebay-cat.tc-ebay-nocat { color: #d63638; font-weight: 400; }
			.tc-ebay-global { color: #888; font-weight: 400; font-size: 11px; }
			.tc-ebay-itemid { margin-bottom: 2px; }
			.tc-ebay-itemid a {
				color: #2271b1;
				text-decoration: none;
				font-size: 11px;
			}
			.tc-ebay-itemid a:hover { text-decoration: underline; }
			.tc-ebay-error {
				color: #d63638;
				font-size: 11px;
				margin-bottom: 2px;
				word-break: break-word;
				line-height: 1.4;
			}
			.tc-ebay-error a { cursor: pointer; }
			.tc-ebay-error a:hover { text-decoration: underline !important; }
			.tc-ebay-pushed {
				color: #00a32a;
				font-size: 11px;
				margin-bottom: 4px;
			}
			.tc-ebay-btn {
				display: inline-block;
				padding: 3px 10px;
				font-size: 11px;
				font-weight: 500;
				border-radius: 3px;
				cursor: pointer;
				border: 1px solid #2271b1;
				background: #2271b1;
				color: #fff;
				line-height: 1.6;
				transition: background 0.15s, border-color 0.15s;
			}
			.tc-ebay-btn:hover { background: #135e96; border-color: #135e96; }
			.tc-ebay-btn:disabled {
				opacity: 0.6;
				cursor: not-allowed;
			}
			.tc-ebay-btn-update {
				background: #fff;
				color: #2271b1;
				border-color: #2271b1;
			}
			.tc-ebay-btn-update:hover {
				background: #f0f6fc;
				color: #135e96;
				border-color: #135e96;
			}
			.tc-ebay-btn-end {
				background: #fff;
				color: #d63638;
				border-color: #d63638;
				margin-top: 4px;
			}
			.tc-ebay-btn-end:hover {
				background: #fcf0f1;
				color: #b32d2e;
				border-color: #b32d2e;
			}
			.tc-ebay-btn-status {
				display: block;
				font-size: 11px;
				margin-top: 3px;
				min-height: 16px;
			}
			.tc-ebay-btn-status.is-success { color: #00a32a; }
			.tc-ebay-btn-status.is-error { color: #d63638; }

			/* Listing type badges */
			.tc-ebay-listing-info { margin: 3px 0 2px; }
			.tc-ebay-badge {
				display: inline-block;
				padding: 1px 6px;
				font-size: 10px;
				font-weight: 600;
				text-transform: uppercase;
				border-radius: 3px;
				letter-spacing: 0.3px;
			}
			.tc-badge-auction {
				background: #fef0e5;
				color: #b35c00;
				border: 1px solid #f0c8a0;
			}
			.tc-badge-fixed {
				background: #e7f5ff;
				color: #0a6abf;
				border: 1px solid #a0d0f0;
			}
			.tc-ebay-duration {
				font-size: 10px;
				color: #757575;
				margin-left: 2px;
			}
			.tc-ebay-end {
				font-size: 11px;
				color: #646970;
				margin-bottom: 2px;
			}
			.tc-ebay-end.tc-ended {
				color: #d63638;
				font-weight: 600;
			}
		</style>
		<script>
		(function($){
			var nonce = '<?php echo esc_js( wp_create_nonce( 'tcgiant_sync_ajax' ) ); ?>';
			var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

			$(document).on('click', '.tc-ebay-btn:not(.tc-ebay-btn-end)', function(e) {
				e.preventDefault();
				var btn = $(this);
				var productId = btn.data('product-id');
				var statusEl = $('.tc-ebay-btn-status[data-product-id="' + productId + '"]');

				btn.prop('disabled', true);
				statusEl.removeClass('is-success is-error').text('Queuing…');

				$.post(ajaxUrl, {
					action: 'tcgiant_push_product',
					product_id: productId,
					_ajax_nonce: nonce
				}, function(res) {
					if (res.success) {
						statusEl.addClass('is-success').text('✔ Queued!');
						// Change button text to indicate it's been queued.
						btn.text('↺ Update');
						btn.removeClass('tc-ebay-btn-push').addClass('tc-ebay-btn-update');
						// Re-enable after a delay.
						setTimeout(function() {
							btn.prop('disabled', false);
							statusEl.text('');
						}, 5000);
					} else {
						var msg = res.data && res.data.message ? res.data.message : 'Unknown error';
						statusEl.addClass('is-error').text('✖ ' + msg);
						btn.prop('disabled', false);
					}
				}).fail(function() {
					statusEl.addClass('is-error').text('✖ Request failed');
					btn.prop('disabled', false);
				});
			});

			// End Listing button handler (product list page).
			$(document).on('click', '.tc-ebay-btn-end', function(e) {
				e.preventDefault();
				if (!confirm('Are you sure you want to end this eBay listing? This cannot be undone.')) return;
				var btn = $(this);
				var productId = btn.data('product-id');
				var statusEl = $('.tc-ebay-btn-status[data-product-id="' + productId + '"]');

				btn.prop('disabled', true);
				statusEl.removeClass('is-success is-error').text('Ending…');

				$.post(ajaxUrl, {
					action: 'tcgiant_end_listing',
					product_id: productId,
					_ajax_nonce: nonce
				}, function(res) {
					if (res.success) {
						statusEl.addClass('is-success').text('⛔ Ended');
						btn.remove();
					} else {
						var msg = res.data && res.data.message ? res.data.message : 'Unknown error';
						statusEl.addClass('is-error').text('✖ ' + msg);
						btn.prop('disabled', false);
					}
				}).fail(function() {
					statusEl.addClass('is-error').text('✖ Request failed');
					btn.prop('disabled', false);
				});
			});

			// Unlink handler — severs the local link only, eBay is untouched.
			$(document).on('click', '.tc-ebay-btn-unlink', function(e) {
				e.preventDefault();
				if (!confirm('Unlink this product from its eBay listing?\n\nThis only removes the link stored in WordPress. The eBay listing itself is NOT changed or ended.')) return;
				var btn = $(this);
				var productId = btn.data('product-id');
				var statusEl = $('.tc-ebay-btn-status[data-product-id="' + productId + '"]');

				btn.prop('disabled', true);
				statusEl.removeClass('is-success is-error').text('Unlinking…');

				$.post(ajaxUrl, {
					action: 'tcgiant_unlink_ebay',
					product_id: productId,
					_ajax_nonce: nonce
				}, function(res) {
					if (res.success) {
						statusEl.addClass('is-success').text('✔ Unlinked');
						btn.remove();
						$('.tc-ebay-shared-warning').remove();
					} else {
						var msg = res.data && res.data.message ? res.data.message : 'Unknown error';
						statusEl.addClass('is-error').text('✖ ' + msg);
						btn.prop('disabled', false);
					}
				}).fail(function() {
					statusEl.addClass('is-error').text('✖ Request failed');
					btn.prop('disabled', false);
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * Make the eBay column sortable.
	 */
	public function make_ebay_column_sortable( $columns ) {
		$columns['tcgiant_ebay'] = 'tcgiant_ebay';
		return $columns;
	}

	/**
	 * Render dropdown filter for eBay status on the Products list.
	 */
	public function render_ebay_status_filter( $post_type ) {
		if ( 'product' !== $post_type ) {
			return;
		}
		$current = isset( $_GET['tc_ebay_status'] ) ? sanitize_text_field( wp_unslash( $_GET['tc_ebay_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<select name="tc_ebay_status">
			<option value=""><?php esc_html_e( 'All eBay Statuses', 'tcgiant-sync' ); ?></option>
			<option value="listed" <?php selected( $current, 'listed' ); ?>><?php esc_html_e( '✅ Listed on eBay', 'tcgiant-sync' ); ?></option>
			<option value="not_listed" <?php selected( $current, 'not_listed' ); ?>><?php esc_html_e( '⬜ Not on eBay', 'tcgiant-sync' ); ?></option>
			<option value="ended" <?php selected( $current, 'ended' ); ?>><?php esc_html_e( '⛔ Ended', 'tcgiant-sync' ); ?></option>
			<option value="auction" <?php selected( $current, 'auction' ); ?>><?php esc_html_e( '🏷 Auctions Only', 'tcgiant-sync' ); ?></option>
			<option value="fixed" <?php selected( $current, 'fixed' ); ?>><?php esc_html_e( '💰 Fixed Price Only', 'tcgiant-sync' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Handle the eBay status filter and sortable column in pre_get_posts.
	 */
	public function handle_ebay_status_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		global $pagenow;
		if ( 'edit.php' !== $pagenow || 'product' !== ( $query->get( 'post_type' ) ?: '' ) ) {
			return;
		}

		// Filter by eBay status.
		$status = isset( $_GET['tc_ebay_status'] ) ? sanitize_text_field( wp_unslash( $_GET['tc_ebay_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! empty( $status ) ) {
			$meta_query = $query->get( 'meta_query' ) ?: array();

			switch ( $status ) {
				case 'listed':
					$meta_query[] = array(
						'key'     => '_ebay_item_id',
						'value'   => '',
						'compare' => '!=',
					);
					$meta_query[] = array(
						'relation' => 'OR',
						array(
							'key'     => '_ebay_listing_status',
							'value'   => 'Active',
							'compare' => '=',
						),
						array(
							'key'     => '_ebay_listing_status',
							'compare' => 'NOT EXISTS',
						),
					);
					break;
				case 'not_listed':
					$meta_query[] = array(
						'relation' => 'OR',
						array(
							'key'     => '_ebay_item_id',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_ebay_item_id',
							'value'   => '',
							'compare' => '=',
						),
					);
					break;
				case 'ended':
					$meta_query[] = array(
						'key'     => '_ebay_listing_status',
						'value'   => 'Ended',
						'compare' => '=',
					);
					break;
				case 'auction':
					$meta_query[] = array(
						'key'     => '_ebay_listing_type',
						'value'   => 'Chinese',
						'compare' => '=',
					);
					break;
				case 'fixed':
					$meta_query[] = array(
						'key'     => '_ebay_listing_type',
						'value'   => 'FixedPriceItem',
						'compare' => '=',
					);
					break;
			}

			$query->set( 'meta_query', $meta_query );
		}

		// Handle sortable column.
		if ( 'tcgiant_ebay' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', '_ebay_item_id' );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Verify Product (Dry Run)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * AJAX: Verify a product listing without creating it (VerifyAddItem).
	 */
	public function ajax_verify_product() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => 'Invalid product ID.' ) );
		}

		$exporter = TCGiant_Sync_Exporter::instance();

		// Save per-product override fields from the form before verifying.
		$override_fields = array(
			'override_category_id'        => '_ebay_export_category_id',
			'override_condition_id'       => '_ebay_export_condition_id',
			'override_item_type'          => '_ebay_export_item_type',
			'override_condition_type'     => '_ebay_export_condition_type',
			'override_grader_id'          => '_ebay_export_grader_id',
			'override_grade_value'        => '_ebay_export_grade_value',
			'override_cert_number'        => '_ebay_export_cert_number',
			'override_coin_year'          => '_ebay_export_coin_year',
			'override_ungraded_condition' => '_ebay_export_ungraded_condition',
			'override_listing_type'       => '_ebay_export_listing_type',
			'override_listing_duration'   => '_ebay_export_listing_duration',
			'override_fulfillment_policy' => '_ebay_export_fulfillment_policy',
		);
		foreach ( $override_fields as $post_key => $meta_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
				update_post_meta( $product_id, $meta_key, $value );
			}
		}

		$settings = $exporter->get_export_settings( $product_id );
		$valid    = $exporter->validate_export_settings( $settings );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( array( 'message' => $valid->get_error_message() ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => 'Product not found.' ) );
		}

		// Build item XML and call VerifyAddItem.
		$item_xml = $exporter->build_item_xml_public( $product, $settings );
		$full_xml = '<Item>' . "\n" . $item_xml . "\n" . '</Item>';
		$api      = TCGiant_Sync_API::instance();
		$result   = $api->verify_add_item( $full_xml );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Parse fees from normalized API response (name => amount).
		$fees = array();
		if ( ! empty( $result['fees'] ) ) {
			foreach ( $result['fees'] as $name => $amount ) {
				if ( $amount > 0 ) {
					$fees[] = $name . ': $' . number_format( $amount, 2 );
				}
			}
		}

		$msg = __( '✅ Listing verified! No errors.', 'tcgiant-sync' );
		if ( ! empty( $fees ) ) {
			$msg .= "\n" . __( 'Estimated fees: ', 'tcgiant-sync' ) . implode( ', ', $fees );
		}

		wp_send_json_success( array( 'message' => $msg, 'fees' => $fees ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// eBay Column on WooCommerce Orders
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Add eBay column to WooCommerce Orders list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_order_ebay_column( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'order_number' === $key ) {
				$new_columns['tcgiant_ebay_order'] = __( 'eBay', 'tcgiant-sync' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render eBay column content (HPOS orders table).
	 *
	 * @param string   $column_name Column identifier.
	 * @param WC_Order $order       Order object.
	 */
	public function render_order_ebay_column( $column_name, $order ) {
		if ( 'tcgiant_ebay_order' !== $column_name ) {
			return;
		}

		$ebay_order_id = $order->get_meta( '_ebay_order_id' );
		if ( ! empty( $ebay_order_id ) ) {
			echo '<span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;">';
			echo '<span style="color:#e53238;font-weight:600;">eBay</span> ';
			echo '<span style="color:#555;">' . esc_html( $ebay_order_id ) . '</span>';
			echo '</span>';
		} else {
			echo '<span style="color:#ccc;">—</span>';
		}
	}

	/**
	 * Render eBay column content (legacy post-based orders).
	 *
	 * @param string $column_name Column identifier.
	 * @param int    $post_id     Post ID.
	 */
	public function render_order_ebay_column_legacy( $column_name, $post_id ) {
		if ( 'tcgiant_ebay_order' !== $column_name ) {
			return;
		}

		$ebay_order_id = get_post_meta( $post_id, '_ebay_order_id', true );
		if ( ! empty( $ebay_order_id ) ) {
			echo '<span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;">';
			echo '<span style="color:#e53238;font-weight:600;">eBay</span> ';
			echo '<span style="color:#555;">' . esc_html( $ebay_order_id ) . '</span>';
			echo '</span>';
		} else {
			echo '<span style="color:#ccc;">—</span>';
		}
	}
}
