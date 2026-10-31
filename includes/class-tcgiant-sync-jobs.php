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
	/**
	 * Job types the Listings screen starts, and which may therefore be asked
	 * for as "everything matching the current filters".
	 */
	const LISTING_JOB_TYPES = array( 'bulk_push', 'bulk_end', 'bulk_relist', 'bulk_verify', 'bulk_set_format' );

	public static function create_job( $type, array $items, array $args = array() ) {
		$job_id = wp_generate_uuid4();
		$job = array(
			'id'         => $job_id,
			'type'       => $type,

			// Whatever the job needs beyond a list of products - the format to
			// change to, for instance. Kept on the job rather than re-posted per
			// batch so that every batch acts on what was actually asked for, and
			// stays small, since progress is written back here after each one.
			'args'       => $args,
			'status'     => 'pending',
			'total'      => count( $items ),
			'processed'  => 0,
			'succeeded'  => 0,
			'queued'     => 0,
			'kept'       => 0,
			'capped'     => 0,
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

		// Jobs that change listings on eBay are refused whole on a staging
		// copy: one clear answer, rather than the same failure written against
		// every product in the job. Verify only asks eBay to check a listing,
		// and the Stock Review jobs only change this site, so those still run.
		if ( in_array( $type, array( 'bulk_push', 'bulk_end', 'bulk_relist' ), true ) ) {
			$blocked = TCGiant_Sync_Environment::write_block();
			if ( $blocked ) {
				TCGiant_Sync_Environment::note_blocked( 'Bulk job ' . $type, $blocked );
				wp_send_json_error( array( 'message' => $blocked->get_error_message() ) );
			}
		}

		$product_ids = array_map( 'absint', (array) ( $_POST['product_ids'] ?? array() ) );
		$product_ids = array_filter( $product_ids );

		// "Settle all" cannot post thousands of IDs through a form without
		// running into max_input_vars, so it asks for the set by name and the
		// server resolves it from the same query the screen displays.
		if ( 'bulk_settle' === $type && ! empty( $_POST['select_all'] ) ) {
			$product_ids = TCGiant_Sync_Inventory::find_unsettled_ended_products();
		}

		if ( 'bulk_remove_sold' === $type && ! empty( $_POST['select_all'] ) ) {
			$product_ids = TCGiant_Sync_Inventory::find_sold_out_not_removed();
		}

		if ( 'bulk_restore_images' === $type && ! empty( $_POST['select_all'] ) ) {
			$product_ids = TCGiant_Sync_Image_Localizer::find_products_with_duplicate_images();
		}

		// Stock Review's products from listings on other eBay sites.
		if ( 'bulk_trash_other_sites' === $type ) {
			// With the trash switched off WordPress deletes instead, and the
			// page promises the trash. Refused whatever was asked for.
			if ( TCGiant_Sync_Sites::trash_is_off() ) {
				wp_send_json_error( array( 'message' => 'The trash is switched off on this website (EMPTY_TRASH_DAYS is 0), so WordPress would delete these products for good instead of moving them to the trash. Nothing was moved.' ) );
			}

			// Nor while the last full sync says the Primary eBay Marketplace is
			// almost certainly set wrongly: the list is then the shop's own.
			if ( null !== TCGiant_Sync_Sites::mismatch() ) {
				wp_send_json_error( array( 'message' => sprintf( 'The last full sync found no listings on %s, your Primary eBay Marketplace, so it is almost certainly set wrongly. Nothing was moved. Correct it in Settings, then reload Stock Review.', TCGiant_Sync_Sites::primary_label() ) ) );
			}

			// The list the page showed, kept when it was drawn - not the query
			// run again, which would take in every product recorded on another
			// site since, unseen. Each is still checked again as it is reached.
			if ( ! empty( $_POST['select_all'] ) ) {
				$product_ids = TCGiant_Sync_Sites::take_shown_list( sanitize_key( wp_unslash( $_POST['list'] ?? '' ) ) );
				if ( null === $product_ids ) {
					wp_send_json_error( array( 'message' => 'This list is out of date. Reload Stock Review to see it as it is now.' ) );
				}
			}
		}

		// The Listings screen asking for everything it is currently showing.
		//
		// Its checkboxes only exist for the twenty rows it has drawn, so a
		// seller who filtered to 900 ended auctions and pressed Relist got
		// twenty of them and nothing to say the rest had been passed over. The
		// filters come back here and the set is resolved from the same query.
		if ( ! empty( $_POST['select_all'] ) && in_array( $type, self::LISTING_JOB_TYPES, true ) ) {
			$listing_view = sanitize_text_field( wp_unslash( $_POST['listing_status'] ?? '' ) );

			// The Sold view has nothing left to sell, so it offers no Relist;
			// asked for anyway, it is refused whole rather than run row by row.
			if ( 'bulk_relist' === $type && 'Sold' === $listing_view ) {
				wp_send_json_error( array( 'message' => 'Nothing was relisted: the Sold view lists listings with nothing left in stock. Restock a product and it moves to Ended, where it can be relisted.' ) );
			}

			// Nor the Active view: those listings are live.
			if ( 'bulk_relist' === $type && 'Active' === $listing_view ) {
				wp_send_json_error( array( 'message' => 'Nothing was relisted: the Active view lists live eBay listings, which need no relisting.' ) );
			}

			$product_ids = TCGiant_Sync_DB::find_product_ids( array(
				'status'     => $listing_view,
				'type'       => sanitize_text_field( wp_unslash( $_POST['listing_type'] ?? '' ) ),
				'search'     => sanitize_text_field( wp_unslash( $_POST['s'] ?? '' ) ),
				// A Relist of everything matching, on any view, takes in only
				// the Ended view's rows: a sold one would be refused for having
				// no stock, and a live one after a GetItem each - every live
				// listing in the shop, on All. A listing the shop ended itself
				// is still taken: this is the shop asking.
				'ended_only' => 'bulk_relist' === $type,
			) );
		}

		if ( empty( $type ) || empty( $product_ids ) ) {
			wp_send_json_error( array( 'message' => 'Missing type or product_ids.' ) );
		}

		$valid_types = array( 'bulk_push', 'bulk_end', 'bulk_verify', 'bulk_relist', 'bulk_settle', 'bulk_restore_images', 'bulk_set_format', 'bulk_remove_sold', 'bulk_zero_stock', 'bulk_use_ebay_figure', 'bulk_keep_stock', 'bulk_trash_other_sites' );
		if ( ! in_array( $type, $valid_types, true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid job type.' ) );
		}

		$args = array();

		if ( 'bulk_set_format' === $type ) {
			// Read as a closed set. These are written straight onto products as
			// the override the next push reads, so anything not recognised must
			// not be stored - an unknown duration would be refused by eBay long
			// afterwards, on a product nobody was looking at any more.
			$want_type     = sanitize_text_field( wp_unslash( $_POST['format_type'] ?? '' ) );
			$want_duration = sanitize_text_field( wp_unslash( $_POST['format_duration'] ?? '' ) );

			if ( '' !== $want_type && ! isset( TCGiant_Sync_Catalog::LISTING_TYPES[ $want_type ] ) ) {
				wp_send_json_error( array( 'message' => 'Unknown listing format.' ) );
			}

			if ( '' !== $want_duration && ! isset( TCGiant_Sync_Catalog::LISTING_DURATIONS[ $want_duration ] ) ) {
				wp_send_json_error( array( 'message' => 'Unknown listing duration.' ) );
			}

			// A duration eBay will not honour for the chosen format is the
			// mistake this action exists to make easy, so it is caught here
			// rather than on each of nine hundred products at push time.
			if ( '' !== $want_type && '' !== $want_duration ) {
				$allowed = TCGiant_Sync_Catalog::DURATIONS_BY_TYPE[ $want_type ] ?? array();
				if ( ! in_array( $want_duration, $allowed, true ) ) {
					wp_send_json_error( array( 'message' => 'That duration is not one eBay allows for that listing format.' ) );
				}
			}

			if ( '' === $want_type && '' === $want_duration ) {
				wp_send_json_error( array( 'message' => 'Choose a listing format, a duration, or both.' ) );
			}

			$args = array(
				'format_type'     => $want_type,
				'format_duration' => $want_duration,
			);
		}

		$job_id = self::create_job( $type, $product_ids, $args );
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

		// Process up to 5 items per batch. The cleanup of other sites' products
		// asks eBay nothing, so it takes more: at five a request, 35,000 copies
		// were 7,000 requests with the tab left open.
		$batch_size  = 'bulk_trash_other_sites' === $job['type'] ? TCGiant_Sync_Sites::CLEANUP_BATCH : 5;
		$start_index = $job['processed'];
		$batch       = array_slice( self::get_job_items( $job_id ), $start_index, $batch_size );
		$succeeded   = $job['succeeded'];
		$failed      = $job['failed'];
		$queued      = $job['queued'] ?? 0;

		// Products a job deliberately left alone. Distinct from failed, which
		// means something went wrong, and from succeeded, which means acted on.
		$kept        = $job['kept'] ?? 0;
		// Of those, the ones the day's cap on bulk pushes left out (3.27.8).
		$capped      = $job['capped'] ?? 0;
		$errors      = $job['errors'];

		// The exporter is resolved inside the arms that use it, not here. Fetched
		// unconditionally, this fatalled on the Stock Review and Image Cleanup
		// screens in any build that ships no exporter.
		$api = TCGiant_Sync_API::instance();

		foreach ( $batch as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				$failed++;
				$errors[] = sprintf( 'Product #%d not found.', $product_id );
				continue;
			}

			switch ( $job['type'] ) {
				case 'bulk_push':
					// Not one whose last push is under way or did not finish:
					// that push may already have made its listing - in place of
					// an ended one, too - and pushing it again lists it twice.
					// The products list warns beside each one; this screen shows
					// nothing, so it is left out here, and the shop told why.
					if ( '' !== TCGiant_Sync_Exporter::push_progress( $product_id ) ) {
						$kept++;
						$errors[] = sprintf( '#%d: not pushed - its last push is still under way or did not finish, and may already have made its listing on eBay. Its eBay tab says what to do.', $product_id );
						break;
					}

					// A bulk push, one product at a time: it counts against the
					// site's daily cap as the products list's does
					// (TCGiant_Sync_Exporter::take_bulk_push_allowance()).
					if ( 1 !== TCGiant_Sync_Exporter::take_bulk_push_allowance( 1 ) ) {
						$kept++;
						$capped++;
						$errors[] = sprintf( '#%d: not pushed - this site\'s limit of %d bulk pushes a day is reached. It resets at midnight UTC.', $product_id, TCGiant_Sync_Exporter::bulk_push_cap() );
						break;
					}

					// push_product() only enqueues the push and returns true as
					// soon as the product exists — it does not contact eBay. So
					// this counter records "queued", not "listed". Reporting it
					// as success made the progress bar hit 100% before a single
					// listing had been created, and hid every real failure.
					$exporter = TCGiant_Sync_Exporter::instance();
					$result = $exporter->push_product( $product_id );
					if ( $result ) {
						$queued++;
						// Counted and waiting: a later bulk push that cancels it gives it back.
						TCGiant_Sync_Exporter::note_bulk_queued( array( $product_id ) );
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
						// Ended, when, and that the shop ended it, for the
						// Listings screen to say.
						TCGiant_Sync_Inventory::record_shop_end( $product_id, (string) $ebay_id, $result );
						$succeeded++;
					}
					break;

				case 'bulk_set_format':
					// The same two overrides the product panel writes, so a listing
					// changed here behaves exactly as one changed by hand. Neither is
					// sent to eBay now; the next push reads them.
					$want_type     = (string) ( $job['args']['format_type'] ?? '' );
					$want_duration = (string) ( $job['args']['format_duration'] ?? '' );

					if ( '' !== $want_type ) {
						update_post_meta( $product_id, '_ebay_export_listing_type', $want_type );
					}

					if ( '' !== $want_duration ) {
						update_post_meta( $product_id, '_ebay_export_listing_duration', $want_duration );
					}

					$succeeded++;
					break;

				case 'bulk_remove_sold':
					// The sell-out was established when the stock was settled, from
					// eBay's own figures, and recorded. Asking eBay again would only
					// fail for the older ones - it drops old listings - and a backlog
					// is by definition old.
					//
					// Checked again here, as each product is reached: the list is
					// the one drawn when the button was pressed - or whatever ids
					// the request named - and a product relisted, restocked or put
					// right since is no longer sold out.
					if ( ! TCGiant_Sync_Inventory::sold_out_on_ebay( $product_id ) ) {
						$kept++;
						$errors[] = sprintf( '#%d: not moved to the trash - eBay\'s figures for the listing it follows do not say it sold out.', $product_id );
						TCGiant_Sync_Logger::log( sprintf( 'Sold-out cleanup: WC #%d was kept - eBay\'s figures for the listing it follows do not say it sold out.', $product_id ) );
						break;
					}

					if ( TCGiant_Sync_Inventory::remove_if_allowed( $product_id ) ) {
						$succeeded++;
						TCGiant_Sync_Logger::log( sprintf( 'Sold-out cleanup: WC #%d moved to the trash.', $product_id ) );
					} else {
						// Not a failure and not a success: the guards are the
						// point, and each has already said in the log why this
						// product is being kept. Counted as neither rather than
						// inflating either figure.
						$kept++;
					}
					break;

				case 'bulk_trash_other_sites':
					// Checked again as each product is reached - the filter still
					// on, the product not already gone and still recorded on
					// another site - and Preserve Categories honoured. The trash
					// only, never a permanent delete, and nothing sent to eBay.
					if ( 'trashed' === TCGiant_Sync_Sites::trash_other_site_product( $product_id ) ) {
						$succeeded++;
					} else {
						// Left alone on purpose, and the log says why where it
						// matters; neither a success nor a failure.
						$kept++;
					}
					break;

				case 'bulk_zero_stock':
					// Products the old settle put back in stock after a website
					// sale. Chosen one by one by the shop from a list that shows
					// the order behind each.
					if ( TCGiant_Sync_Inventory::zero_stock( $product_id ) ) {
						$succeeded++;
						TCGiant_Sync_Logger::log( sprintf( 'Stock review: WC #%d set to 0 - it had sold on the website and been put back in stock.', $product_id ) );
					} else {
						$failed++;
						$errors[] = sprintf( '#%d: product not found.', $product_id );
					}
					break;

				case 'bulk_keep_stock':
					if ( TCGiant_Sync_Inventory::keep_stock( $product_id ) ) {
						$succeeded++;
						TCGiant_Sync_Logger::log( sprintf( 'Stock review: WC #%d kept as it is - the shop says its stock is real.', $product_id ) );
					} else {
						$failed++;
						$errors[] = sprintf( '#%d: product not found.', $product_id );
					}
					break;

				case 'bulk_use_ebay_figure':
					$now = TCGiant_Sync_Inventory::use_ebay_figure( $product_id );
					if ( null !== $now ) {
						$succeeded++;
						TCGiant_Sync_Logger::log( sprintf( 'Stock review: WC #%d set to %d, the figure eBay reported when its listing ended.', $product_id, $now ) );
					} else {
						$failed++;
						$errors[] = sprintf( '#%d: no eBay figure recorded.', $product_id );
					}
					break;

				case 'bulk_verify':
					$exporter = TCGiant_Sync_Exporter::instance();
					$settings = $exporter->get_export_settings( $product_id );
					// As the push checks it (3.28.5): a new listing's only for a
					// product with no live listing.
					$valid    = $exporter->validate_export_settings( $settings, TCGiant_Sync_Exporter::pushes_new_listing( $product_id ) );
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

					// A variation listing's variations each from their own
					// figures, before the parent takes the listing's.
					$settle_product = wc_get_product( $product_id );
					if ( $settle_product && $settle_product->is_type( 'variable' )
						&& null === TCGiant_Sync_Inventory::settle_variations( $product_id, $detail['Item'] + array( 'ItemID' => (string) $ebay_id ), 'Stock review' ) ) {
						$failed++;
						$errors[] = sprintf( '#%d: eBay did not report the variations of this listing; their stock was not checked.', $product_id );
						break;
					}

					$settled = TCGiant_Sync_Inventory::apply_ended_listing_stock(
						$product_id,
						isset( $detail['Item']['Quantity'] ) ? $detail['Item']['Quantity'] : null,
						isset( $detail['Item']['SellingStatus']['QuantitySold'] ) ? $detail['Item']['SellingStatus']['QuantitySold'] : null,
						$detail['Item']
					);

					if ( null === $settled ) {
						// Never guess at a quantity. Saying so and leaving the
						// stock alone is the safe direction.
						$failed++;
						$errors[] = sprintf( '#%d: could not be settled — stock left exactly as it was. The log says why.', $product_id );
					} else {
						TCGiant_Sync_Logger::log( sprintf(
							'Stock review: WC #%d stock now %d after ended eBay listing %s.',
							$product_id, $settled, $ebay_id
						) );

						// Settling and removing are the same decision about the same
						// event, and this screen only ever did the first half.
						if ( TCGiant_Sync_Inventory::maybe_remove_sold_out(
							$product_id,
							isset( $detail['Item']['Quantity'] ) ? $detail['Item']['Quantity'] : null,
							isset( $detail['Item']['SellingStatus']['QuantitySold'] ) ? $detail['Item']['SellingStatus']['QuantitySold'] : null
						) ) {
							TCGiant_Sync_Logger::log( sprintf(
								'Stock review: WC #%d sold out on eBay and has been moved to the trash.',
								$product_id
							) );
						}
						// The notice reads a 15-minute cache; without this it
						// would keep naming products that have just been put
						// right.
						delete_transient( 'tcgiant_unsettled_stock_count' );
						$succeeded++;
					}
					break;

				case 'bulk_relist':
					// Every check is in the exporter, shared with auto-relist. This
					// used to relist whatever was selected - sold-out coins at their
					// original quantity, and trashed products too.
					$result = TCGiant_Sync_Exporter::instance()->relist_ended_listing( $product_id, 'Bulk relist' );
					if ( is_wp_error( $result ) && 'relist_refused' === $result->get_error_code() ) {
						// Right not to, so neither a success nor a failure.
						$kept++;
						$errors[] = sprintf( '#%d: not relisted - %s.', $product_id, $result->get_error_message() );
					} elseif ( is_wp_error( $result ) ) {
						$failed++;
						$errors[] = sprintf( '#%d: %s', $product_id, $result->get_error_message() );
					} else {
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
			'kept'      => $kept,
			'capped'    => $capped,
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
			'kept'      => $kept,
			'capped'    => $capped,
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
