<?php
/**
 * Exporter Logic
 *
 * Manages pushing WooCommerce products to eBay as new or updated listings.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Exporter class
 */
class TCGiant_Sync_Exporter {

	/**
	 * Instance.
	 */
	private static $_instance = null;

	/**
	 * Export state option key.
	 */
	const EXPORT_STATE_OPTION = 'tcgiant_export_state';

	/**
	 * Action Scheduler hook for processing a single export job.
	 */
	const PUSH_ACTION = 'tcgiant_export_push_product';

	/**
	 * eBay condition IDs relevant to TCG / collectibles.
	 */
	const CONDITIONS = array(
		'1000' => 'New / Sealed',
		'2750' => 'Like New',
		'3000' => 'Very Good',
		'4000' => 'Good',
		'5000' => 'Acceptable',
	);

	/**
	 * Maximum eBay title length (hard limit).
	 */
	const MAX_TITLE_LENGTH = 80;

	/**
	 * Maximum images per eBay listing.
	 */
	const MAX_IMAGES = 12;

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
	 * Constructor — register Action Scheduler callbacks.
	 */
	public function __construct() {
		add_action( self::PUSH_ACTION, array( $this, 'process_single_push' ), 10, 1 );
	}

	// -------------------------------------------------------------------------
	// State Management
	// -------------------------------------------------------------------------

	/**
	 * Get the current export state.
	 *
	 * @return array Export state data.
	 */
	public static function get_export_state() {
		return get_option( self::EXPORT_STATE_OPTION, array(
			'status'          => 'idle',   // idle, queued, pushing, complete, error
			'total_queued'    => 0,
			'total_pushed'    => 0,
			'total_errors'    => 0,
			'started_at'      => '',
			'last_activity'   => '',
			'last_completed'  => '',
			'last_item_title' => '',
		) );
	}

	/**
	 * Update export state.
	 *
	 * @param array $updates Key-value pairs to merge into current state.
	 */
	public static function update_export_state( $updates ) {
		$state = self::get_export_state();
		$state = array_merge( $state, $updates );
		$state['last_activity'] = current_time( 'mysql' );
		update_option( self::EXPORT_STATE_OPTION, $state );
	}

	// -------------------------------------------------------------------------
	// Public Entry Points
	// -------------------------------------------------------------------------

	/**
	 * Queue a single WooCommerce product for export to eBay.
	 *
	 * @param int $product_id WooCommerce Product ID.
	 * @return bool True if queued, false if product not found.
	 */
	public function push_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			TCGiant_Sync_Logger::error( sprintf( 'Export: Product ID %d not found.', $product_id ) );
			return false;
		}

		as_enqueue_async_action(
			self::PUSH_ACTION,
			array( 'product_id' => $product_id ),
			'tcgiant_sync_group'
		);

		TCGiant_Sync_Logger::log( sprintf( 'Export: Queued "%s" (WC #%d) for push to eBay.', $product->get_name(), $product_id ) );
		return true;
	}

	/**
	 * Queue multiple WooCommerce products for export to eBay.
	 *
	 * @param int[] $product_ids Array of WooCommerce Product IDs.
	 * @return int Number of products successfully queued.
	 */
	public function bulk_push_products( $product_ids ) {
		if ( empty( $product_ids ) || ! is_array( $product_ids ) ) {
			return 0;
		}

		// Clear any existing pending export jobs to prevent stacking.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::PUSH_ACTION, null, 'tcgiant_sync_group' );
		}

		$queued = 0;
		foreach ( $product_ids as $index => $product_id ) {
			$product_id = (int) $product_id;
			if ( $product_id <= 0 ) {
				continue;
			}
			// Stagger jobs by 3 seconds each to avoid rate limiting.
			$delay = 3 * $queued;
			as_schedule_single_action(
				time() + $delay,
				self::PUSH_ACTION,
				array( 'product_id' => $product_id ),
				'tcgiant_sync_group'
			);
			$queued++;
		}

		if ( $queued > 0 ) {
			self::update_export_state( array(
				'status'       => 'queued',
				'total_queued' => $queued,
				'total_pushed' => 0,
				'total_errors' => 0,
				'started_at'   => current_time( 'mysql' ),
			) );
			TCGiant_Sync_Logger::log( sprintf( 'Export: Queued %d products for bulk push to eBay.', $queued ) );
		}

		return $queued;
	}

	// -------------------------------------------------------------------------
	// Action Scheduler Callback
	// -------------------------------------------------------------------------

	/**
	 * Process a single product push (called by Action Scheduler).
	 *
	 * @param int $product_id WooCommerce Product ID.
	 */
	public function process_single_push( $product_id ) {
		$product_id = (int) $product_id;
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			self::update_export_state( array(
				'total_errors' => self::get_export_state()['total_errors'] + 1,
			) );
			TCGiant_Sync_Logger::error( sprintf( 'Export: Product ID %d not found during async push.', $product_id ) );
			$this->check_export_completion();
			return;
		}

		$title = $product->get_name();

		try {
			$result = $this->do_push( $product );

			if ( is_wp_error( $result ) ) {
				$error_msg = $result->get_error_message();
				$product->update_meta_data( '_ebay_export_status', 'error' );
				$product->update_meta_data( '_ebay_export_error', $error_msg );
				$product->save();

				self::update_export_state( array(
					'total_errors' => self::get_export_state()['total_errors'] + 1,
				) );
				TCGiant_Sync_Logger::error( sprintf( 'Export failed for "%s" (WC #%d): %s', $title, $product_id, $error_msg ) );
			} else {
				$item_id = $result['item_id'];
				$action  = $result['action']; // 'created' or 'updated'

				$product->update_meta_data( '_ebay_item_id', $item_id );
				$product->update_meta_data( '_ebay_export_status', 'pushed' );
				$product->update_meta_data( '_ebay_export_error', '' );
				$product->update_meta_data( '_ebay_export_last_pushed', current_time( 'mysql' ) );
				$product->save();

				$state = self::get_export_state();
				self::update_export_state( array(
					'total_pushed'    => $state['total_pushed'] + 1,
					'last_item_title' => $title,
				) );

				TCGiant_Sync_Logger::log(
					sprintf(
						'Export %s: "%s" (WC #%d) → eBay Item ID %s.',
						$action, $title, $product_id, $item_id
					),
					'success'
				);
			}
		} catch ( Exception $e ) {
			self::update_export_state( array(
				'total_errors' => self::get_export_state()['total_errors'] + 1,
			) );
			TCGiant_Sync_Logger::error( sprintf( 'Export exception for "%s" (WC #%d): %s', $title, $product_id, $e->getMessage() ) );
		} catch ( Error $e ) {
			self::update_export_state( array(
				'total_errors' => self::get_export_state()['total_errors'] + 1,
			) );
			TCGiant_Sync_Logger::error( sprintf( 'Export fatal error for "%s" (WC #%d): %s', $title, $product_id, $e->getMessage() ) );
		}

		$this->check_export_completion();
	}

	// -------------------------------------------------------------------------
	// Core Push Logic
	// -------------------------------------------------------------------------

	/**
	 * Execute the push for a single product — creates or updates the eBay listing.
	 *
	 * @param WC_Product $product The WooCommerce product.
	 * @return array|WP_Error On success, array with 'item_id' and 'action' keys.
	 */
	private function do_push( WC_Product $product ) {
		$settings = $this->get_export_settings( $product->get_id() );

		// Validate required settings before hitting the API.
		$validation = $this->validate_export_settings( $settings );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$item_xml  = $this->build_item_xml( $product, $settings );
		$api       = TCGiant_Sync_API::instance();
		$ebay_item_id = $product->get_meta( '_ebay_item_id' );

		if ( ! empty( $ebay_item_id ) ) {
			// Existing listing — revise it.
			$full_xml = '<Item>' . "\n" . '<ItemID>' . esc_attr( $ebay_item_id ) . '</ItemID>' . "\n" . $item_xml . "\n" . '</Item>';
			$response = $api->revise_item( $full_xml );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// ReviseItem returns ItemID in the response.
			$returned_item_id = $response['ItemID'] ?? $ebay_item_id;
			return array( 'item_id' => $returned_item_id, 'action' => 'updated' );

		} else {
			// New listing — add it.
			$full_xml = '<Item>' . "\n" . $item_xml . "\n" . '</Item>';
			$response = $api->add_item( $full_xml );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( empty( $response['ItemID'] ) ) {
				return new WP_Error( 'no_item_id', __( 'eBay did not return an Item ID after AddItem. Listing may have been created — check your eBay account.', 'tcgiant-sync' ) );
			}

			return array( 'item_id' => $response['ItemID'], 'action' => 'created' );
		}
	}

	/**
	 * Build the inner <Item> XML content (without the outer <Item> tags).
	 *
	 * @param WC_Product $product  The WooCommerce product.
	 * @param array      $settings Merged export settings.
	 * @return string XML string.
	 */
	private function build_item_xml( WC_Product $product, array $settings ) {
		$title       = $this->sanitize_title( $product->get_name() );
		$description = $this->build_description( $product );
		$price       = wc_format_decimal( $product->get_regular_price(), 2 );
		$quantity    = max( 1, (int) $product->get_stock_quantity() );
		$sku         = $product->get_sku();
		$images      = $this->get_product_image_urls( $product );

		// Build picture URLs XML.
		$picture_xml = '';
		if ( ! empty( $images ) ) {
			$picture_xml = '<PictureDetails>' . "\n";
			foreach ( array_slice( $images, 0, self::MAX_IMAGES ) as $url ) {
				$picture_xml .= "\t<PictureURL>" . esc_url( $url ) . "</PictureURL>\n";
			}
			$picture_xml .= '</PictureDetails>';
		}

		// Build seller profiles XML (business policies).
		$profiles_xml = '';
		if ( ! empty( $settings['fulfillment_policy_id'] ) || ! empty( $settings['return_policy_id'] ) || ! empty( $settings['payment_policy_id'] ) ) {
			$profiles_xml = '<SellerProfiles>' . "\n";
			if ( ! empty( $settings['fulfillment_policy_id'] ) ) {
				$profiles_xml .= "\t<SellerShippingProfile>\n\t\t<ShippingProfileID>" . esc_attr( $settings['fulfillment_policy_id'] ) . "</ShippingProfileID>\n\t</SellerShippingProfile>\n";
			}
			if ( ! empty( $settings['return_policy_id'] ) ) {
				$profiles_xml .= "\t<SellerReturnProfile>\n\t\t<ReturnProfileID>" . esc_attr( $settings['return_policy_id'] ) . "</ReturnProfileID>\n\t</SellerReturnProfile>\n";
			}
			if ( ! empty( $settings['payment_policy_id'] ) ) {
				$profiles_xml .= "\t<SellerPaymentProfile>\n\t\t<PaymentProfileID>" . esc_attr( $settings['payment_policy_id'] ) . "</PaymentProfileID>\n\t</SellerPaymentProfile>\n";
			}
			$profiles_xml .= '</SellerProfiles>';
		}

		$xml  = '<Title>' . esc_xml( $title ) . '</Title>' . "\n";
		$xml .= '<Description><![CDATA[' . $description . ']]></Description>' . "\n";
		$xml .= '<PrimaryCategory><CategoryID>' . esc_attr( $settings['category_id'] ) . '</CategoryID></PrimaryCategory>' . "\n";
		$xml .= '<StartPrice>' . esc_attr( $price ) . '</StartPrice>' . "\n";
		$xml .= '<CategoryMappingAllowed>true</CategoryMappingAllowed>' . "\n";
		$xml .= '<ConditionID>' . esc_attr( $settings['condition_id'] ) . '</ConditionID>' . "\n";
		$xml .= '<Country>US</Country>' . "\n";
		$xml .= '<Currency>USD</Currency>' . "\n";
		$xml .= '<DispatchTimeMax>3</DispatchTimeMax>' . "\n";
		$xml .= '<ListingDuration>GTC</ListingDuration>' . "\n";
		$xml .= '<ListingType>FixedPriceItem</ListingType>' . "\n";
		$xml .= '<Quantity>' . (int) $quantity . '</Quantity>' . "\n";
		$xml .= '<Site>US</Site>' . "\n";

		if ( ! empty( $sku ) ) {
			$xml .= '<SKU>' . esc_xml( $sku ) . '</SKU>' . "\n";
		}

		if ( $picture_xml ) {
			$xml .= $picture_xml . "\n";
		}

		if ( $profiles_xml ) {
			$xml .= $profiles_xml . "\n";
		}

		return $xml;
	}

	// -------------------------------------------------------------------------
	// Settings & Validation
	// -------------------------------------------------------------------------

	/**
	 * Get merged export settings: global defaults overridden by per-product meta.
	 *
	 * @param int $product_id WooCommerce Product ID.
	 * @return array Merged settings ready for use in build_item_xml().
	 */
	public function get_export_settings( $product_id ) {
		$global   = TCGiant_Sync_OAuth::instance()->get_settings();
		$product  = wc_get_product( $product_id );

		$settings = array(
			'category_id'          => $global['export_category_id'] ?? '',
			'condition_id'         => $global['export_condition_id'] ?? '1000',
			'fulfillment_policy_id' => $global['export_fulfillment_policy'] ?? '',
			'return_policy_id'     => $global['export_return_policy'] ?? '',
			'payment_policy_id'    => $global['export_payment_policy'] ?? '',
		);

		if ( $product ) {
			// Per-product overrides take precedence if set.
			$override_category  = $product->get_meta( '_ebay_export_category_id' );
			$override_condition = $product->get_meta( '_ebay_export_condition_id' );

			if ( ! empty( $override_category ) ) {
				$settings['category_id'] = $override_category;
			}
			if ( ! empty( $override_condition ) ) {
				$settings['condition_id'] = $override_condition;
			}
		}

		return $settings;
	}

	/**
	 * Validate that the minimum required settings for a push are present.
	 *
	 * @param array $settings Export settings array.
	 * @return true|WP_Error True on success, WP_Error describing what's missing.
	 */
	private function validate_export_settings( array $settings ) {
		$missing = array();

		if ( empty( $settings['category_id'] ) ) {
			$missing[] = __( 'eBay Category ID (set a default in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['fulfillment_policy_id'] ) ) {
			$missing[] = __( 'Fulfillment (Shipping) Policy (fetch policies in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['return_policy_id'] ) ) {
			$missing[] = __( 'Return Policy (fetch policies in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['payment_policy_id'] ) ) {
			$missing[] = __( 'Payment Policy (fetch policies in TCGiant Sync settings)', 'tcgiant-sync' );
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'missing_export_settings',
				/* translators: %s: comma-separated list of missing settings */
				sprintf( __( 'Cannot push to eBay — missing required settings: %s', 'tcgiant-sync' ), implode( ', ', $missing ) )
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Helper Methods
	// -------------------------------------------------------------------------

	/**
	 * Sanitize a product title to eBay's 80-character limit.
	 *
	 * @param string $title Raw WooCommerce product name.
	 * @return string Sanitized, truncated title.
	 */
	private function sanitize_title( $title ) {
		// eBay disallows some characters in titles.
		$title = preg_replace( '/[<>&"\'!@#*]/', '', $title );
		$title = trim( $title );

		if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Export: Title truncated from %d to %d chars: "%s..."',
				mb_strlen( $title ),
				self::MAX_TITLE_LENGTH,
				mb_substr( $title, 0, 40 )
			), 'warning' );
			$title = mb_substr( $title, 0, self::MAX_TITLE_LENGTH );
		}

		return $title;
	}

	/**
	 * Build the HTML description for an eBay listing.
	 *
	 * Falls back to a simple formatted block if no WC description exists.
	 *
	 * @param WC_Product $product The WooCommerce product.
	 * @return string HTML description string.
	 */
	private function build_description( WC_Product $product ) {
		$desc = $product->get_description();

		if ( empty( trim( $desc ) ) ) {
			$desc = $product->get_short_description();
		}

		if ( empty( trim( $desc ) ) ) {
			// Auto-generate a minimal description from product attributes.
			$attrs = $product->get_attributes();
			if ( ! empty( $attrs ) ) {
				$lines = array();
				foreach ( $attrs as $attr ) {
					$name  = wc_attribute_label( $attr->get_name() );
					$value = implode( ', ', $attr->get_options() );
					if ( $name && $value ) {
						$lines[] = '<strong>' . esc_html( $name ) . ':</strong> ' . esc_html( $value );
					}
				}
				if ( ! empty( $lines ) ) {
					$desc = implode( '<br>', $lines );
				}
			}
		}

		if ( empty( trim( $desc ) ) ) {
			$desc = esc_html( $product->get_name() );
		}

		// Apply WP content filters (shortcodes, etc.) for a clean HTML output.
		return apply_filters( 'the_content', $desc );
	}

	/**
	 * Get all public image URLs for a WooCommerce product.
	 *
	 * Returns the featured image first, then gallery images, up to MAX_IMAGES.
	 *
	 * @param WC_Product $product The WooCommerce product.
	 * @return string[] Array of image URLs.
	 */
	private function get_product_image_urls( WC_Product $product ) {
		$urls = array();

		// Featured image.
		$thumb_id = $product->get_image_id();
		if ( $thumb_id ) {
			$src = wp_get_attachment_image_url( $thumb_id, 'full' );
			if ( $src ) {
				$urls[] = $src;
			}
		}

		// Gallery images.
		$gallery_ids = $product->get_gallery_image_ids();
		foreach ( $gallery_ids as $img_id ) {
			if ( count( $urls ) >= self::MAX_IMAGES ) {
				break;
			}
			$src = wp_get_attachment_image_url( $img_id, 'full' );
			if ( $src ) {
				$urls[] = $src;
			}
		}

		return $urls;
	}

	/**
	 * Get cached business policies from a transient, or fetch fresh from eBay.
	 *
	 * @param string $type One of 'fulfillment', 'return', 'payment'.
	 * @return array|WP_Error Array of policy objects, or WP_Error on failure.
	 */
	public static function get_policies( $type ) {
		$transient_key = 'tcgiant_export_policies_' . $type;
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$api = TCGiant_Sync_API::instance();

		switch ( $type ) {
			case 'fulfillment':
				$response = $api->get_fulfillment_policies();
				$key      = 'fulfillmentPolicies';
				$id_field = 'fulfillmentPolicyId';
				break;
			case 'return':
				$response = $api->get_return_policies();
				$key      = 'returnPolicies';
				$id_field = 'returnPolicyId';
				break;
			case 'payment':
				$response = $api->get_payment_policies();
				$key      = 'paymentPolicies';
				$id_field = 'paymentPolicyId';
				break;
			default:
				return new WP_Error( 'invalid_policy_type', 'Invalid policy type: ' . $type );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$policies = array();
		if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
			foreach ( $response[ $key ] as $policy ) {
				$policies[] = array(
					'id'   => $policy[ $id_field ] ?? '',
					'name' => $policy['name'] ?? 'Unknown Policy',
				);
			}
		}

		// Cache for 1 hour.
		set_transient( $transient_key, $policies, HOUR_IN_SECONDS );

		return $policies;
	}

	/**
	 * Bust the cached business policies (called after user clicks "Refresh Policies").
	 */
	public static function clear_policy_cache() {
		delete_transient( 'tcgiant_export_policies_fulfillment' );
		delete_transient( 'tcgiant_export_policies_return' );
		delete_transient( 'tcgiant_export_policies_payment' );
	}

	// -------------------------------------------------------------------------
	// Completion Check
	// -------------------------------------------------------------------------

	/**
	 * Check if the bulk export is complete and update state accordingly.
	 */
	private function check_export_completion() {
		$state = self::get_export_state();

		if ( ! in_array( $state['status'], array( 'queued', 'pushing' ), true ) ) {
			return;
		}

		self::update_export_state( array( 'status' => 'pushing' ) );

		$completed = $state['total_pushed'] + $state['total_errors'];
		$is_done   = ( $completed >= $state['total_queued'] && $state['total_queued'] > 0 );

		// Fallback: check Action Scheduler for any remaining pending jobs.
		if ( ! $is_done && function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions( array(
				'hook'     => self::PUSH_ACTION,
				'group'    => 'tcgiant_sync_group',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			) );
			if ( empty( $pending ) ) {
				$is_done = true;
			}
		}

		if ( $is_done ) {
			self::update_export_state( array(
				'status'        => 'complete',
				'last_completed' => current_time( 'mysql' ),
			) );
			TCGiant_Sync_Logger::log( sprintf(
				'Export complete! %d pushed, %d errors out of %d total.',
				$state['total_pushed'] + 1, // +1 for the current item
				$state['total_errors'],
				$state['total_queued']
			), 'success' );
		}
	}
}
