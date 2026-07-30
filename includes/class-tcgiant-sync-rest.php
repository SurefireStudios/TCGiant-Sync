<?php
/**
 * REST API
 *
 * Registers WP REST API endpoints for external integrations.
 * Namespace: tcgiant-sync/v1
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_REST class.
 */
class TCGiant_Sync_REST {

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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		$namespace = 'tcgiant-sync/v1';

		register_rest_route( $namespace, '/listings', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_listings' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'status' => array(
					'type'    => 'string',
					'enum'    => array( 'Active', 'Ended', '' ),
					'default' => '',
				),
				'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
				'search'   => array( 'type' => 'string', 'default' => '' ),
			),
		) );

		register_rest_route( $namespace, '/listings/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_listing' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true ),
			),
		) );

		register_rest_route( $namespace, '/sync-status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_sync_status' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );

		register_rest_route( $namespace, '/sync/start', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'start_sync' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );

		register_rest_route( $namespace, '/orders', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_orders' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
			),
		) );
	}

	/**
	 * Permission check — requires manage_options.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_permissions( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this endpoint.', 'tcgiant-sync' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /listings — list all eBay-linked products.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_listings( $request ) {
		$status   = $request->get_param( 'status' );
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$search   = $request->get_param( 'search' );

		global $wpdb;

		$where = "pm.meta_key = '_ebay_item_id' AND pm.meta_value != ''";
		$params = array();

		if ( ! empty( $status ) ) {
			$where .= " AND pm2.meta_value = %s";
			$params[] = $status;
		}

		if ( ! empty( $search ) ) {
			$where .= " AND (p.post_title LIKE %s OR pm.meta_value LIKE %s)";
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$offset = ( $page - 1 ) * $per_page;

		$sql = "SELECT pm.post_id, pm.meta_value AS ebay_item_id, p.post_title,
		               COALESCE(pm2.meta_value, 'Unknown') AS listing_status,
		               COALESCE(pm3.meta_value, '') AS listing_type
		        FROM {$wpdb->postmeta} pm
		        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_status = 'publish'
		        LEFT JOIN {$wpdb->postmeta} pm2 ON pm.post_id = pm2.post_id AND pm2.meta_key = '_ebay_listing_status'
		        LEFT JOIN {$wpdb->postmeta} pm3 ON pm.post_id = pm3.post_id AND pm3.meta_key = '_ebay_listing_type'
		        WHERE {$where}
		        ORDER BY pm.post_id DESC
		        LIMIT %d OFFSET %d";

		$params[] = $per_page;
		$params[] = $offset;

		// $params always holds at least the LIMIT/OFFSET values appended above.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

		$listings = array();
		foreach ( $results as $row ) {
			$product = wc_get_product( (int) $row['post_id'] );
			$listings[] = array(
				'product_id'     => (int) $row['post_id'],
				'title'          => $row['post_title'],
				'ebay_item_id'   => $row['ebay_item_id'],
				'ebay_url'       => 'https://www.ebay.com/itm/' . $row['ebay_item_id'],
				'listing_status' => $row['listing_status'],
				'listing_type'   => $row['listing_type'],
				'sku'            => $product ? $product->get_sku() : '',
				'price'          => $product ? $product->get_regular_price() : '',
				'stock'          => $product ? $product->get_stock_quantity() : null,
			);
		}

		// Total count for pagination.
		$count_sql = "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
		              INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_status = 'publish'
		              LEFT JOIN {$wpdb->postmeta} pm2 ON pm.post_id = pm2.post_id AND pm2.meta_key = '_ebay_listing_status'
		              WHERE {$where}";

		// Remove the last two params (limit/offset) for count.
		$count_params = array_slice( $params, 0, -2 );
		$total = ! empty( $count_params )
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$count_params ) )
			: (int) $wpdb->get_var( $count_sql );

		$response = new WP_REST_Response( $listings, 200 );
		// Header values must be strings.
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $total / $per_page ) : 0 ) );

		return $response;
	}

	/**
	 * GET /listings/{id} — single listing details.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_listing( $request ) {
		$product_id = $request->get_param( 'id' );
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'tcgiant-sync' ), array( 'status' => 404 ) );
		}

		$ebay_item_id = get_post_meta( $product_id, '_ebay_item_id', true );
		if ( empty( $ebay_item_id ) ) {
			return new WP_Error( 'not_linked', __( 'Product is not linked to eBay.', 'tcgiant-sync' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array(
			'product_id'     => $product_id,
			'title'          => $product->get_name(),
			'ebay_item_id'   => $ebay_item_id,
			'ebay_url'       => 'https://www.ebay.com/itm/' . $ebay_item_id,
			'listing_status' => get_post_meta( $product_id, '_ebay_listing_status', true ) ?: 'Unknown',
			'listing_type'   => get_post_meta( $product_id, '_ebay_listing_type', true ) ?: '',
			'sku'            => $product->get_sku(),
			'price'          => $product->get_regular_price(),
			'stock'          => $product->get_stock_quantity(),
			'last_synced'    => get_post_meta( $product_id, '_ebay_last_synced', true ) ?: '',
			'last_pushed'    => get_post_meta( $product_id, '_ebay_export_last_pushed', true ) ?: '',
			'images'         => wp_get_attachment_url( (int) $product->get_image_id() ) ?: '',
		), 200 );
	}

	/**
	 * GET /sync-status — current sync state.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_sync_status( $request ) {
		$state    = TCGiant_Sync_Importer::get_sync_state();
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );

		return new WP_REST_Response( array(
			'status'          => $state['status'] ?? 'idle',
			'total_found'     => (int) ( $state['total_found'] ?? 0 ),
			'total_processed' => (int) ( $state['total_processed'] ?? 0 ),
			'total_errors'    => (int) ( $state['total_errors'] ?? 0 ),
			'current_page'    => (int) ( $state['page_number'] ?? 0 ),
			'total_pages'     => (int) ( $state['total_pages'] ?? 0 ),
			'last_activity'   => $state['last_activity'] ?? '',
			'api_calls_today' => (int) ( $settings['api_calls_today'] ?? 0 ),
			'next_cron'       => wp_next_scheduled( 'tcgiant_sync_poll_ebay_cron' )
				? gmdate( 'Y-m-d H:i:s', wp_next_scheduled( 'tcgiant_sync_poll_ebay_cron' ) )
				: null,
		), 200 );
	}

	/**
	 * POST /sync/start — trigger a sync.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_sync( $request ) {
		if ( defined( 'TCGIANT_SYNC_IS_STAGING' ) && TCGIANT_SYNC_IS_STAGING ) {
			return new WP_Error( 'staging', __( 'Sync is disabled on staging environments.', 'tcgiant-sync' ), array( 'status' => 403 ) );
		}

		$importer = TCGiant_Sync_Importer::instance();
		$result   = $importer->start_full_sync();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array(
			'message' => __( 'Sync started.', 'tcgiant-sync' ),
			'status'  => 'scanning',
		), 200 );
	}

	/**
	 * GET /orders — list eBay-imported orders.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_orders( $request ) {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );

		$args = array(
			'meta_key'   => '_ebay_order_id',
			'meta_compare' => 'EXISTS',
			'limit'      => $per_page,
			'page'       => $page,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'return'     => 'ids',
		);

		$order_ids = wc_get_orders( $args );
		$orders    = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$orders[] = array(
				'order_id'      => $order->get_id(),
				'ebay_order_id' => $order->get_meta( '_ebay_order_id' ),
				'status'        => $order->get_status(),
				'total'         => $order->get_total(),
				'date_created'  => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : '',
				'customer'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'items_count'   => $order->get_item_count(),
			);
		}

		return new WP_REST_Response( $orders, 200 );
	}
}
