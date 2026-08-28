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
	 * Option key for storing job progress records.
	 *
	 * Holds metadata only — never the item list. See ITEMS_OPTION_PREFIX.
	 */
	const OPTION_KEY = 'tcgiant_sync_jobs';

	/**
	 * Option key prefix for a single job's item list.
	 *
	 * The item list is stored per job, separately from the progress record,
	 * because progress is rewritten after every batch. Keeping several thousand
	 * product IDs inside that record meant each 5-item batch rewrote a
	 * multi-hundred-kilobyte option — 1,000 full read-modify-write cycles for a
	 * 5,000-product bulk push.
	 */
	const ITEMS_OPTION_PREFIX = 'tcgiant_sync_job_items_';

	/**
	 * Maximum number of jobs kept in history.
	 */
	const MAX_JOB_HISTORY = 10;

	/**
	 * Get the option key holding a job's item list.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	private static function items_option_key( $job_id ) {
		return self::ITEMS_OPTION_PREFIX . $job_id;
	}

	/**
	 * Get the item list for a job.
	 *
	 * @param string $job_id Job ID.
	 * @return array
	 */
	private static function get_job_items( $job_id ) {
		$items = get_option( self::items_option_key( $job_id ), array() );
		return is_array( $items ) ? $items : array();
	}

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
			'total'      => count( $items ),
			'processed'  => 0,
			'succeeded'  => 0,
			'queued'     => 0,
			'failed'     => 0,
			'errors'     => array(),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);

		// Item list lives in its own non-autoloaded option so that per-batch
		// progress writes stay small.
		add_option( self::items_option_key( $job_id ), array_values( $items ), '', false );

		$jobs = get_option( self::OPTION_KEY, array() );
		$jobs = is_array( $jobs ) ? $jobs : array();
		$jobs[ $job_id ] = $job;

		// Trim history, removing each dropped job's item list too.
		if ( count( $jobs ) > self::MAX_JOB_HISTORY ) {
			$keep    = array_slice( $jobs, -self::MAX_JOB_HISTORY, null, true );
			$dropped = array_diff_key( $jobs, $keep );
			foreach ( array_keys( $dropped ) as $old_id ) {
				delete_option( self::items_option_key( $old_id ) );
			}
			$jobs = $keep;
		}

		self::save_jobs( $jobs );
		return $job_id;
	}

	/**
	 * Persist the job progress records without autoloading them.
	 *
	 * @param array $jobs Job records keyed by job ID.
	 * @return void
	 */
	private static function save_jobs( array $jobs ) {
		// delete-then-add is the only reliable way to clear a pre-existing
		// autoload flag on WordPress versions before 6.4.
		if ( false !== get_option( self::OPTION_KEY, false ) ) {
			delete_option( self::OPTION_KEY );
		}
		add_option( self::OPTION_KEY, $jobs, '', false );
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
		$jobs = is_array( $jobs ) ? $jobs : array();

		if ( ! isset( $jobs[ $job_id ] ) ) {
			return;
		}

		// The item list is never part of the progress record.
		unset( $data['items'] );

		$jobs[ $job_id ] = array_merge( $jobs[ $job_id ], $data );
		$jobs[ $job_id ]['updated_at'] = current_time( 'mysql' );
		self::save_jobs( $jobs );

		// A finished or cancelled job no longer needs its item list.
		$status = $jobs[ $job_id ]['status'] ?? '';
		if ( in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
			delete_option( self::items_option_key( $job_id ) );
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

		// "Settle all" cannot post thousands of IDs through a form without
		// running into max_input_vars, so it asks for the set by name and the
		// server resolves it from the same query the screen displays.
		if ( 'bulk_settle' === $type && ! empty( $_POST['select_all'] ) ) {
			$product_ids = TCGiant_Sync_Inventory::find_unsettled_ended_products();
		}

		if ( 'bulk_restore_images' === $type && ! empty( $_POST['select_all'] ) ) {
			$product_ids = TCGiant_Sync_Image_Localizer::find_products_with_duplicate_images();
		}

		if ( empty( $type ) || empty( $product_ids ) ) {
			wp_send_json_error( array( 'message' => 'Missing type or product_ids.' ) );
		}

		$valid_types = array( 'bulk_push', 'bulk_end', 'bulk_verify', 'bulk_relist', 'bulk_settle', 'bulk_restore_images' );
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
			// wp_send_json_success() ends the request.
			wp_send_json_success( array(
				'status'    => 'cancelled',
				'processed' => $job['processed'],
				'total'     => $job['total'],
			) );
		}

		// Process up to 5 items per batch.
		$batch_size  = 5;
		$start_index = $job['processed'];
		$batch       = array_slice( self::get_job_items( $job_id ), $start_index, $batch_size );
		$succeeded   = $job['succeeded'];
		$failed      = $job['failed'];
		$queued      = $job['queued'] ?? 0;
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
					// push_product() only enqueues the push and returns true as
					// soon as the product exists — it does not contact eBay. So
					// this counter records "queued", not "listed". Reporting it
					// as success made the progress bar hit 100% before a single
					// listing had been created, and hid every real failure.
					$result = $exporter->push_product( $product_id );
					if ( $result ) {
						$queued++;
					} else {
						$failed++;
						$errors[] = sprintf( '#%d: Could not be queued for push.', $product_id );
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

				case 'bulk_restore_images':
					// Put a shop's own photographs back and remove the copies we
					// fetched from eBay. Everything about which is which, and
					// the refusal to leave a product with no pictures, lives in
					// restore_own_images(); this only reports what it did.
					$restored = TCGiant_Sync_Image_Localizer::restore_own_images( $product_id );

					if ( is_wp_error( $restored ) ) {
						$failed++;
						$errors[] = sprintf( '#%d: %s', $product_id, $restored->get_error_message() );
					} else {
						$succeeded++;
					}
					break;

				case 'bulk_settle':
					// Put right the stock of a product whose listing has ended,
					// from eBay's own record of what the listing held and what
					// sold. Recovery for stock that went wrong before the fault
					// was found; nothing else can know the figures.
					$ebay_id = get_post_meta( $product_id, '_ebay_item_id', true );
					if ( empty( $ebay_id ) ) {
						$failed++;
						$errors[] = sprintf( '#%d: No eBay Item ID.', $product_id );
						break;
					}

					$detail = $api->get_item( $ebay_id );
					if ( is_wp_error( $detail ) || ! isset( $detail['Item'] ) ) {
						$failed++;
						$errors[] = sprintf(
							'#%d: %s',
							$product_id,
							is_wp_error( $detail ) ? $detail->get_error_message() : 'eBay returned nothing for this listing.'
						);
						break;
					}

					$settled = TCGiant_Sync_Inventory::apply_ended_listing_stock(
						$product_id,
						isset( $detail['Item']['Quantity'] ) ? $detail['Item']['Quantity'] : null,
						isset( $detail['Item']['SellingStatus']['QuantitySold'] ) ? $detail['Item']['SellingStatus']['QuantitySold'] : null
					);

					if ( null === $settled ) {
						// Never guess at a quantity. Saying so and leaving the
						// stock alone is the safe direction.
						$failed++;
						$errors[] = sprintf( '#%d: could not be settled — stock left exactly as it was. The log says why.', $product_id );
					} else {
						TCGiant_Sync_Logger::log( sprintf(
							'Stock review: WC #%d settled to %d from ended eBay listing %s.',
							$product_id, $settled, $ebay_id
						) );
						// The notice reads a 15-minute cache; without this it
						// would keep naming products that have just been put
						// right.
						delete_transient( 'tcgiant_unsettled_stock_count' );
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
			'queued'    => $queued,
			'failed'    => $failed,
			'errors'    => array_slice( $errors, -50 ), // Keep last 50 errors.
			'status'    => $is_done ? 'completed' : 'running',
		) );

		wp_send_json_success( array(
			'status'    => $is_done ? 'completed' : 'running',
			'processed' => $new_processed,
			'total'     => $job['total'],
			'succeeded' => $succeeded,
			'queued'    => $queued,
			'failed'    => $failed,
			// bulk_push is asynchronous: 'queued' items have been handed to the
			// background pusher, not confirmed as listed on eBay. Watch the
			// per-product eBay status column for the real outcome.
			'is_async'  => ( 'bulk_push' === $job['type'] ),
			'errors'    => array_slice( $errors, -5 ), // Return last 5 to client.
		) );
	}

	/**
	 * AJAX: Get job status.
	 */
	public function ajax_job_status() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

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
			'queued'    => $job['queued'] ?? 0,
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
