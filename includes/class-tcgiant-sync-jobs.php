<?php
/**
 * Job Queue System
 *
 * AJAX-driven background task runner with progress tracking.
 * Used for bulk operations like bulk push, bulk end, bulk verify.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Jobs class.
 */
class TCGiant_Sync_Jobs {

	/**
	 * Option key for storing jobs.
	 */
	const OPTION_KEY = 'tcgiant_sync_jobs';

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
		add_action( 'wp_ajax_tcgiant_job_start', array( $this, 'ajax_start_job' ) );
		add_action( 'wp_ajax_tcgiant_job_process', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_tcgiant_job_status', array( $this, 'ajax_job_status' ) );
		add_action( 'wp_ajax_tcgiant_job_cancel', array( $this, 'ajax_cancel_job' ) );
	}

	/**
	 * Create a new job.
	 *
	 * @param string $type    Job type: 'bulk_push', 'bulk_end', 'bulk_verify'.
	 * @param array  $items   Array of product IDs to process.
	 * @return string Job ID.
	 */
	public static function create_job( $type, array $items ) {
		$job_id = wp_generate_uuid4();
		$job = array(
			'id'         => $job_id,
			'type'       => $type,
			'status'     => 'pending',
			'items'      => $items,
			'total'      => count( $items ),
			'processed'  => 0,
			'succeeded'  => 0,
			'failed'     => 0,
			'errors'     => array(),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);

		$jobs = get_option( self::OPTION_KEY, array() );
		$jobs[ $job_id ] = $job;

		// Keep max 10 jobs in history.
		if ( count( $jobs ) > 10 ) {
			$jobs = array_slice( $jobs, -10, null, true );
		}

		update_option( self::OPTION_KEY, $jobs );
		return $job_id;
	}

	/**
	 * Get a job by ID.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Job data or null.
	 */
	public static function get_job( $job_id ) {
		$jobs = get_option( self::OPTION_KEY, array() );
		return $jobs[ $job_id ] ?? null;
	}

	/**
	 * Update a job.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $data   Key-value pairs to update.
	 */
	public static function update_job( $job_id, array $data ) {
		$jobs = get_option( self::OPTION_KEY, array() );
		if ( isset( $jobs[ $job_id ] ) ) {
			$jobs[ $job_id ] = array_merge( $jobs[ $job_id ], $data );
			$jobs[ $job_id ]['updated_at'] = current_time( 'mysql' );
			update_option( self::OPTION_KEY, $jobs );
		}
	}

	/**
	 * AJAX: Start a job.
	 */
	public function ajax_start_job() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$type       = sanitize_text_field( wp_unslash( $_POST['type'] ?? '' ) );
		$product_ids = array_map( 'absint', (array) ( $_POST['product_ids'] ?? array() ) );
		$product_ids = array_filter( $product_ids );

		if ( empty( $type ) || empty( $product_ids ) ) {
			wp_send_json_error( array( 'message' => 'Missing type or product_ids.' ) );
		}

		$valid_types = array( 'bulk_push', 'bulk_end', 'bulk_verify', 'bulk_relist' );
		if ( ! in_array( $type, $valid_types, true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid job type.' ) );
		}

		$job_id = self::create_job( $type, $product_ids );
		self::update_job( $job_id, array( 'status' => 'running' ) );

		wp_send_json_success( array(
			'job_id' => $job_id,
			'total'  => count( $product_ids ),
		) );
	}

	/**
	 * AJAX: Process next batch of a job.
	 */
	public function ajax_process_batch() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
		$job    = self::get_job( $job_id );

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'Job not found.' ) );
		}

		if ( 'cancelled' === $job['status'] ) {
			wp_send_json_success( array(
				'status'    => 'cancelled',
				'processed' => $job['processed'],
				'total'     => $job['total'],
			) );
			return;
		}

		// Process up to 5 items per batch.
		$batch_size  = 5;
		$start_index = $job['processed'];
		$batch       = array_slice( $job['items'], $start_index, $batch_size );
		$succeeded   = $job['succeeded'];
		$failed      = $job['failed'];
		$errors      = $job['errors'];

		$exporter = TCGiant_Sync_Exporter::instance();
		$api      = TCGiant_Sync_API::instance();

		foreach ( $batch as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				$failed++;
				$errors[] = sprintf( 'Product #%d not found.', $product_id );
				continue;
			}

			switch ( $job['type'] ) {
				case 'bulk_push':
					$result = $exporter->push_product( $product_id );
					if ( $result ) {
						$succeeded++;
					} else {
						$failed++;
						$errors[] = sprintf( '#%d: Push failed.', $product_id );
					}
					break;

				case 'bulk_end':
					$ebay_id = get_post_meta( $product_id, '_ebay_item_id', true );
					if ( empty( $ebay_id ) ) {
						$failed++;
						$errors[] = sprintf( '#%d: No eBay Item ID.', $product_id );
						break;
					}
					$result = $api->end_item( $ebay_id );
					if ( is_wp_error( $result ) ) {
						$failed++;
						$errors[] = sprintf( '#%d: %s', $product_id, $result->get_error_message() );
					} else {
						update_post_meta( $product_id, '_ebay_listing_status', 'Ended' );
						$succeeded++;
					}
					break;

				case 'bulk_verify':
					$settings = $exporter->get_export_settings( $product_id );
					$valid    = $exporter->validate_export_settings( $settings );
					if ( is_wp_error( $valid ) ) {
						$failed++;
						$errors[] = sprintf( '#%d: %s', $product_id, $valid->get_error_message() );
					} else {
						$succeeded++;
					}
					break;

				case 'bulk_relist':
					$ebay_id = get_post_meta( $product_id, '_ebay_item_id', true );
					if ( empty( $ebay_id ) ) {
						$failed++;
						$errors[] = sprintf( '#%d: No eBay Item ID.', $product_id );
						break;
					}
					$result = $api->relist_item( $ebay_id );
					if ( is_wp_error( $result ) ) {
						$failed++;
						$errors[] = sprintf( '#%d: %s', $product_id, $result->get_error_message() );
					} else {
						$new_id = $result['ItemID'] ?? $ebay_id;
						update_post_meta( $product_id, '_ebay_item_id', $new_id );
						update_post_meta( $product_id, '_ebay_listing_status', 'Active' );
						$succeeded++;
					}
					break;
			}
		}

		$new_processed = $start_index + count( $batch );
		$is_done       = $new_processed >= $job['total'];

		self::update_job( $job_id, array(
			'processed' => $new_processed,
			'succeeded' => $succeeded,
			'failed'    => $failed,
			'errors'    => array_slice( $errors, -50 ), // Keep last 50 errors.
			'status'    => $is_done ? 'completed' : 'running',
		) );

		wp_send_json_success( array(
			'status'    => $is_done ? 'completed' : 'running',
			'processed' => $new_processed,
			'total'     => $job['total'],
			'succeeded' => $succeeded,
			'failed'    => $failed,
			'errors'    => array_slice( $errors, -5 ), // Return last 5 to client.
		) );
	}

	/**
	 * AJAX: Get job status.
	 */
	public function ajax_job_status() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		$job_id = sanitize_text_field( wp_unslash( $_GET['job_id'] ?? $_POST['job_id'] ?? '' ) );
		$job    = self::get_job( $job_id );

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'Job not found.' ) );
		}

		wp_send_json_success( array(
			'status'    => $job['status'],
			'processed' => $job['processed'],
			'total'     => $job['total'],
			'succeeded' => $job['succeeded'],
			'failed'    => $job['failed'],
			'errors'    => array_slice( $job['errors'], -10 ),
		) );
	}

	/**
	 * AJAX: Cancel a job.
	 */
	public function ajax_cancel_job() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
		$job    = self::get_job( $job_id );

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'Job not found.' ) );
		}

		self::update_job( $job_id, array( 'status' => 'cancelled' ) );

		wp_send_json_success( array( 'message' => 'Job cancelled.' ) );
	}
}
