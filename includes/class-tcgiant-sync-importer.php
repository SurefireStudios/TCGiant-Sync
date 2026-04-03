<?php
/**
 * Importer Logic
 *
 * Manages the batch import process from eBay to WooCommerce.
 *
 * @package TCGiant_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Importer class
 */
class TCGiant_Sync_Importer {

	/**
	 * Instance.
	 */
	private static $_instance = null;

	/**
	 * Sync state option key.
	 */
	const STATE_OPTION = 'tcgiant_sync_state';

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
	 * TCGiant_Sync_Importer Constructor.
	 */
	public function __construct() {
		add_action( 'tcgiant_sync_process_item_import', array( $this, 'process_item_import' ), 10, 1 );
		add_action( 'tcgiant_sync_fetch_listings', array( $this, 'fetch_listings_page' ), 10, 1 );
		add_action( 'tcgiant_sync_download_images', array( $this, 'download_product_images' ), 10, 2 );
	}

	/**
	 * Get the current sync state.
	 *
	 * @return array Sync state data.
	 */
	public static function get_sync_state() {
		return get_option( self::STATE_OPTION, array(
			'status'          => 'idle',       // idle, scanning, importing, complete, stopped, error
			'total_found'     => 0,
			'total_queued'    => 0,
			'total_processed' => 0,
			'total_errors'    => 0,
			'current_page'    => 0,
			'total_pages'     => 0,
			'filter_name'     => '',
			'started_at'      => '',
			'last_activity'   => '',
			'last_completed'  => '',
			'last_item_title' => '',
		) );
	}

	/**
	 * Update sync state.
	 *
	 * @param array $updates Key-value pairs to merge into current state.
	 */
	public static function update_sync_state( $updates ) {
		$state = self::get_sync_state();
		$state = array_merge( $state, $updates );
		$state['last_activity'] = current_time( 'mysql' );
		update_option( self::STATE_OPTION, $state );
	}

	/**
	 * Start a full sync.
	 *
	 * @param bool $force If true, bypass the "already running" guard.
	 */
	public function start_full_sync( $force = false ) {
		// License check: can the user import more products?
		$license = TCGiant_Sync_License::instance();
		if ( ! $license->can_import() ) {
			self::update_sync_state( array( 'status' => 'limit_reached' ) );
			TCGiant_Sync_Logger::log(
				sprintf(
					'Free tier limit reached (%d/%d active products). Upgrade to TCGiant Sync Pro for unlimited imports.',
					$license->get_active_product_count(),
					TCGiant_Sync_License::FREE_LIMIT
				),
				'warning'
			);
			return;
		}

		// Guard: don't restart if a sync is already in progress (prevents cron overlap).
		if ( ! $force ) {
			$current = self::get_sync_state();
			if ( in_array( $current['status'], array( 'scanning', 'importing' ), true ) ) {
				TCGiant_Sync_Logger::log( 'Sync already in progress — skipping duplicate request.' );
				return;
			}
		}

		// Clear any previous pending sync jobs to prevent stacking.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_listings', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_group' );
		}

		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$filter_name = ! empty( $settings['category_ids'] ) ? $settings['category_ids'] : 'All Categories';

		// Clear cached category ID transient so we get fresh resolution.
		if ( ! empty( $settings['category_ids'] ) ) {
			delete_transient( 'tcgiant_sync_cats_' . md5( $settings['category_ids'] ) );
		}

		// Reset sync state.
		self::update_sync_state( array(
			'status'          => 'scanning',
			'total_found'     => 0,
			'total_queued'    => 0,
			'total_processed' => 0,
			'total_errors'    => 0,
			'current_page'    => 1,
			'total_pages'     => 0,
			'filter_name'     => $filter_name,
			'started_at'      => current_time( 'mysql' ),
			'last_item_title' => '',
		) );

		TCGiant_Sync_Logger::log( sprintf( 'Starting sync for: %s', $filter_name ) );
		as_enqueue_async_action( 'tcgiant_sync_fetch_listings', array( 'page_number' => 1 ), 'tcgiant_sync_group' );
	}

	/**
	 * Fetch a single page of listings via Trading API.
	 *
	 * @param int $page_number Page number to fetch.
	 */
	public function fetch_listings_page( $page_number ) {
		self::update_sync_state( array(
			'status'       => 'scanning',
			'current_page' => $page_number,
		) );

		$api = TCGiant_Sync_API::instance();
		$response = $api->get_active_listings( $page_number, 100 );

		if ( is_wp_error( $response ) || ! isset( $response['ItemArray']['Item'] ) ) {
			$state = self::get_sync_state();
			if ( $state['total_queued'] > 0 ) {
				self::update_sync_state( array( 'status' => 'importing' ) );
				TCGiant_Sync_Logger::log( sprintf( 'Scan complete. %d items queued for import.', $state['total_queued'] ) );
			} else {
				self::update_sync_state( array(
					'status'         => 'complete',
					'last_completed' => current_time( 'mysql' ),
				) );
				TCGiant_Sync_Logger::log( 'Scan complete. No matching items found.' );
			}
			return;
		}

		$items = $response['ItemArray']['Item'];
		if ( ! isset( $items[0] ) ) {
			$items = array( $items );
		}

		$total_pages = isset( $response['PaginationResult']['TotalNumberOfPages'] ) ? (int) $response['PaginationResult']['TotalNumberOfPages'] : 1;
		self::update_sync_state( array( 'total_pages' => $total_pages ) );

		$queued_count = 0;
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$is_filtering = ! empty( $settings['category_ids'] );
		$valid_ids = $is_filtering ? $this->get_allowed_category_ids() : array();

		// Debug: log resolved category IDs on the first page so we can verify matching.
		if ( $page_number === 1 && $is_filtering ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Category filter active: "%s" → resolved to IDs: [%s]',
				$settings['category_ids'],
				implode( ', ', $valid_ids )
			) );
			// Log the first item's category fields to verify what eBay returns.
			if ( ! empty( $items[0] ) ) {
				$sample = $items[0];
				TCGiant_Sync_Logger::log( sprintf(
					'Sample item "%s": PrimaryCat=%s, StoreCat1=%s, StoreCat2=%s',
					$sample['Title'] ?? '?',
					$sample['PrimaryCategory']['CategoryID'] ?? 'n/a',
					$sample['Storefront']['StoreCategoryID'] ?? 'n/a',
					$sample['Storefront']['StoreCategory2ID'] ?? 'n/a'
				) );
			}
		}

		foreach ( $items as $item ) {
			$item_id = $item['ItemID'] ?? '';
			
			if ( empty( $item_id ) ) {
				continue;
			}

			// Category pre-filter.
			if ( $is_filtering && empty( $valid_ids ) ) {
				continue;
			} elseif ( $is_filtering ) {
				$primary_cat = $item['PrimaryCategory']['CategoryID'] ?? '';
				$store_cat1 = $item['Storefront']['StoreCategoryID'] ?? '';
				$store_cat2 = $item['Storefront']['StoreCategory2ID'] ?? '';

				$match = false;
				if ( in_array( (string) $primary_cat, $valid_ids, true ) ) $match = true;
				if ( in_array( (string) $store_cat1, $valid_ids, true ) ) $match = true;
				if ( in_array( (string) $store_cat2, $valid_ids, true ) ) $match = true;

				if ( ! $match ) {
					continue;
				}
			}

			// Schedule item with staggered delay (3s per item).
			$delay = 3 * $queued_count;
			as_schedule_single_action( time() + $delay, 'tcgiant_sync_process_item_import', array( 'item_id' => $item_id ), 'tcgiant_sync_group' );
			$queued_count++;
		}

		// Update state with running totals.
		$state = self::get_sync_state();
		$new_total_found = $state['total_found'] + count( $items );
		$new_total_queued = $state['total_queued'] + $queued_count;

		self::update_sync_state( array(
			'total_found'  => $new_total_found,
			'total_queued' => $new_total_queued,
		) );

		// Only log every 5 pages to reduce noise (always log first and last page).
		$should_log = ( $page_number === 1 || $page_number === $total_pages || $page_number % 5 === 0 || $queued_count > 0 );
		if ( $should_log ) {
			$filter_label = $is_filtering ? sprintf( ' matching "%s"', $settings['category_ids'] ) : '';
			TCGiant_Sync_Logger::log( sprintf(
				'Page %d/%d: Scanned %d items, queued %d%s.',
				$page_number, $total_pages, count( $items ), $queued_count, $filter_label
			) );
		}

		// Schedule next page — fast (2s) when no matches, slower when items are queued.
		if ( $page_number < $total_pages ) {
			$delay_next_page = $queued_count > 0 ? ( $queued_count * 3 ) + 5 : 2;
			as_schedule_single_action( time() + $delay_next_page, 'tcgiant_sync_fetch_listings', array( 'page_number' => $page_number + 1 ), 'tcgiant_sync_group' );
		} else {
			if ( $new_total_queued > 0 ) {
				self::update_sync_state( array( 'status' => 'importing' ) );
				TCGiant_Sync_Logger::log( sprintf( 'Scan complete. %d total items queued for import across %d pages.', $new_total_queued, $total_pages ) );
			} else {
				self::update_sync_state( array(
					'status'         => 'complete',
					'last_completed' => current_time( 'mysql' ),
				) );
				TCGiant_Sync_Logger::log( 'Scan complete. No matching items found.' );
			}
		}
	}

	/**
	 * Resolves User Category String Settings into eBay Category IDs.
	 */
	private function get_allowed_category_ids() {
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$raw_category_setting = $settings['category_ids'] ?? '';
		
		$transient_key = 'tcgiant_sync_cats_' . md5( $raw_category_setting );
		$valid_ids = get_transient( $transient_key );
		if ( false !== $valid_ids ) {
			return $valid_ids;
		}

		$allowed_strings = ! empty( $raw_category_setting ) ? explode( ',', $raw_category_setting ) : array();
		
		if ( empty( $allowed_strings ) ) {
			return array();
		}

		$allowed_strings = array_map( 'strtolower', array_map( 'trim', $allowed_strings ) );
		$valid_ids = array();

		// Hard global numeric IDs.
		foreach ( $allowed_strings as $str ) {
			if ( is_numeric( $str ) ) {
				$valid_ids[] = (string) $str;
			}
		}

		// Download Store Category Tree.
		$api = TCGiant_Sync_API::instance();
		$store_response = $api->get_store();
		if ( ! is_wp_error( $store_response ) && isset( $store_response['Store']['CustomCategories']['CustomCategory'] ) ) {
			$categories = $store_response['Store']['CustomCategories']['CustomCategory'];
			if ( isset( $categories['CategoryID'] ) ) {
				$categories = array( $categories );
			}
			
			$flatten = function( $cats ) use ( &$flatten, &$valid_ids, $allowed_strings ) {
				if ( ! is_array( $cats ) ) return;
				foreach ( $cats as $cat ) {
					$name = strtolower( trim( $cat['Name'] ?? '' ) );
					$id = $cat['CategoryID'] ?? '';
					if ( $name && $id && in_array( $name, $allowed_strings, true ) ) {
						$valid_ids[] = (string) $id;
					}
					if ( isset( $cat['ChildCategory'] ) ) {
						$children = isset( $cat['ChildCategory']['CategoryID'] ) ? array( $cat['ChildCategory'] ) : $cat['ChildCategory'];
						$flatten( $children );
					}
				}
			};
			$flatten( $categories );
		}

		$valid_ids = array_unique( $valid_ids );
		set_transient( $transient_key, $valid_ids, 3600 );
		return $valid_ids;
	}

	/**
	 * Sync recent orders and reduce stock.
	 */
	public function sync_recent_orders() {
		$api = TCGiant_Sync_API::instance();
		$response = $api->get_orders();

		if ( is_wp_error( $response ) ) {
			TCGiant_Sync_Logger::error( 'Order Sync failed: ' . $response->get_error_message() );
			return;
		}

		if ( empty( $response['orders'] ) ) {
			TCGiant_Sync_Logger::log( 'No recent orders found on eBay.' );
			return;
		}

		$order_count = 0;
		foreach ( $response['orders'] as $order ) {
			if ( empty( $order['lineItems'] ) ) {
				continue;
			}
			
			foreach ( $order['lineItems'] as $line ) {
				$sku = $line['sku'] ?? '';
				$quantity = isset($line['quantity']) ? (int) $line['quantity'] : 1;
				$line_item_id = $line['lineItemId'] ?? '';

				if ( empty( $sku ) || empty( $line_item_id ) ) {
					continue;
				}

				$product_id = wc_get_product_id_by_sku( $sku );
				if ( ! $product_id ) {
					continue;
				}

				$processed = get_post_meta( $product_id, '_ebay_order_processed_' . $line_item_id, true );
				if ( $processed ) {
					continue;
				}

				$stock_reduced = wc_update_product_stock( $product_id, $quantity, 'decrease' );
				
				if ( ! is_wp_error( $stock_reduced ) ) {
					update_post_meta( $product_id, '_ebay_order_processed_' . $line_item_id, current_time( 'mysql' ) );
					TCGiant_Sync_Logger::log( sprintf( 'Reduced stock for WC Product %d by %d (eBay order).', $product_id, $quantity ), 'success' );
					$order_count++;
				}
			}
		}

		TCGiant_Sync_Logger::log( sprintf( 'Order sync complete. Processed %d new line items.', $order_count ) );
	}

	/**
	 * Process a single eBay Item ID.
	 *
	 * @param string $item_id The eBay Item ID to import.
	 */
	public function process_item_import( $item_id ) {
		try {
			// Per-item license check.
			$license = TCGiant_Sync_License::instance();
			if ( ! $license->can_import() ) {
				self::update_sync_state( array( 'status' => 'limit_reached' ) );
				TCGiant_Sync_Logger::log(
					sprintf(
						'Import limit reached (%d/%d products). Remaining queued items skipped. Upgrade to Pro for unlimited.',
						$license->get_active_product_count(),
						TCGiant_Sync_License::FREE_LIMIT
					),
					'warning'
				);
				// Cancel remaining queued jobs to avoid wasting API calls.
				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_group' );
				}
				return;
			}

			$api = TCGiant_Sync_API::instance();
			$ebay_response = $api->get_item( $item_id );

			if ( is_wp_error( $ebay_response ) || ! isset( $ebay_response['Item'] ) ) {
				self::update_sync_state( array(
					'total_errors' => self::get_sync_state()['total_errors'] + 1,
				) );
				TCGiant_Sync_Logger::error( 'Import failed for eBay Item ID: ' . $item_id );
				$this->check_sync_completion();
				return;
			}

			$ebay_item = $ebay_response['Item'];
			$title = $ebay_item['Title'] ?? 'Unknown';

			$mapper = TCGiant_Sync_Mapper::instance();
			$product_data = $mapper->map_ebay_to_woo( $ebay_item );
			
			$product_id = $mapper->save_as_product( $product_data );

			if ( $product_id ) {
				// Download images synchronously.
				if ( ! empty( $product_data['images'] ) ) {
					$this->download_product_images( $product_id, $product_data['images'] );
				}

				$state = self::get_sync_state();
				self::update_sync_state( array(
					'total_processed' => $state['total_processed'] + 1,
					'last_item_title' => $title,
				) );

				$price_display = ! empty( $product_data['price'] ) ? '$' . $product_data['price'] : 'No price';
				TCGiant_Sync_Logger::log( sprintf(
					'Imported: "%s" → WC #%d (%s, Qty: %d)',
					$title, $product_id, $price_display, $product_data['stock_quantity']
				), 'success' );
			} else {
				self::update_sync_state( array(
					'total_errors' => self::get_sync_state()['total_errors'] + 1,
				) );
				TCGiant_Sync_Logger::error( sprintf( 'Failed to save product for eBay Item: %s (%s)', $item_id, $title ) );
			}

			$this->check_sync_completion();
			
		} catch ( Exception $e ) {
			self::update_sync_state( array(
				'total_errors' => self::get_sync_state()['total_errors'] + 1,
			) );
			TCGiant_Sync_Logger::error( 'Exception importing item ' . $item_id . ': ' . $e->getMessage() );
			$this->check_sync_completion();
		} catch ( Error $e ) {
			self::update_sync_state( array(
				'total_errors' => self::get_sync_state()['total_errors'] + 1,
			) );
			TCGiant_Sync_Logger::error( 'Fatal error importing item ' . $item_id . ': ' . $e->getMessage() );
			$this->check_sync_completion();
		}
	}

	/**
	 * Check if the sync is complete (all items processed or no jobs remain).
	 */
	private function check_sync_completion() {
		$state = self::get_sync_state();
		if ( 'importing' !== $state['status'] ) {
			return;
		}

		$completed = $state['total_processed'] + $state['total_errors'];

		// Primary check: all items accounted for.
		$is_done = ( $completed >= $state['total_queued'] && $state['total_queued'] > 0 );

		// Fallback: if Action Scheduler has no remaining jobs, we're done regardless of counters.
		if ( ! $is_done && function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions( array(
				'hook'   => 'tcgiant_sync_process_item_import',
				'group'  => 'tcgiant_sync_group',
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			) );
			if ( empty( $pending ) ) {
				$is_done = true;
			}
		}

		if ( $is_done ) {
			self::update_sync_state( array(
				'status'         => 'complete',
				'last_completed' => current_time( 'mysql' ),
			) );
			TCGiant_Sync_Logger::log( sprintf(
				'Sync complete! %d imported, %d errors out of %d total.',
				$state['total_processed'], $state['total_errors'], $state['total_queued']
			), 'success' );
		}
	}

	/**
	 * Download and attach images to product.
	 *
	 * @param int   $product_id WooCommerce Product ID.
	 * @param array $images     List of eBay image URLs.
	 */
	public function download_product_images( $product_id, $images ) {
		if ( empty( $images ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Skip if product already has a thumbnail (re-import protection).
		if ( has_post_thumbnail( $product_id ) ) {
			return;
		}

		$gallery_ids = array();
		$first_image = true;

		foreach ( $images as $image_url ) {
			$id = media_sideload_image( $image_url, $product_id, null, 'id' );

			if ( is_wp_error( $id ) ) {
				TCGiant_Sync_Logger::warning( 'Image download failed: ' . $id->get_error_message() );
				continue;
			}

			if ( $first_image ) {
				set_post_thumbnail( $product_id, $id );
				$first_image = false;
			} else {
				$gallery_ids[] = $id;
			}
		}

		if ( ! empty( $gallery_ids ) ) {
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
		}
	}
}
