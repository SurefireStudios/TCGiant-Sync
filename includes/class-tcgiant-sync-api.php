<?php
/**
 * eBay API Wrapper
 *
 * Provides methods to interact with eBay Inventory and Fulfillment APIs.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_API class
 */
class TCGiant_Sync_API {

	/**
	 * Instance of this class.
	 */
	private static $_instance = null;

	/**
	 * eBay REST Base URLs.
	 */
	const BASE_URL_PRODUCTION = 'https://api.ebay.com/';

	/**
	 * Supported Marketplaces.
	 */
	const MARKETPLACES = array(
		'EBAY_US' => array( 'label' => 'United States', 'site_id' => '0' ),
		'EBAY_GB' => array( 'label' => 'United Kingdom', 'site_id' => '3' ),
		'EBAY_CA' => array( 'label' => 'Canada', 'site_id' => '2' ),
		'EBAY_AU' => array( 'label' => 'Australia', 'site_id' => '15' ),
		'EBAY_DE' => array( 'label' => 'Germany', 'site_id' => '77' ),
		'EBAY_FR' => array( 'label' => 'France', 'site_id' => '71' ),
		'EBAY_IT' => array( 'label' => 'Italy', 'site_id' => '101' ),
		'EBAY_ES' => array( 'label' => 'Spain', 'site_id' => '186' ),
	);

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
	 * Get configured marketplace settings.
	 */
	public function get_marketplace_config() {
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$marketplace_id = ! empty( $settings['marketplace'] ) ? $settings['marketplace'] : 'EBAY_US';
		
		if ( ! isset( self::MARKETPLACES[ $marketplace_id ] ) ) {
			$marketplace_id = 'EBAY_US';
		}
		
		return array(
			'marketplace_id' => $marketplace_id,
			'site_id'        => self::MARKETPLACES[ $marketplace_id ]['site_id'],
		);
	}

	/**
	 * Send request to eBay API.
	 *
	 * @param string  $endpoint   API Endpoint.
	 * @param string  $method     HTTP Method.
	 * @param array   $body       Request body.
	 * @param array   $params     Query parameters.
	 * @param boolean $log_errors Whether to log errors automatically.
	 */
	public function request( $endpoint, $method = 'GET', $body = array(), $params = array(), $log_errors = true ) {
		$token = TCGiant_Sync_OAuth::instance()->get_access_token();

		if ( ! $token ) {
			TCGiant_Sync_Logger::error( 'eBay API Error: No valid access token found.' );
			return new WP_Error( 'no_token', __( 'No valid eBay token.', 'tcgiant-sync' ) );
		}

		$url = self::BASE_URL_PRODUCTION . $endpoint;
		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'timeout' => 30,
		);

		if ( 'GET' !== $method && ! empty( $body ) ) {
			$args['body'] = json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			TCGiant_Sync_Logger::error( 'eBay API Request Failed: ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code > 299 ) {
			if ( $log_errors ) {
				TCGiant_Sync_Logger::error( sprintf( 'eBay API Error (%d): %s', $code, wp_remote_retrieve_body( $response ) ) );
			}
			return new WP_Error( 'api_error', sprintf( 'eBay API Error %d', $code ), $body );
		}

		return $body;
	}

	/**
	 * Send XML request to eBay Trading API.
	 *
	 * @param string $call_name Name of the Trading API call.
	 * @param string $xml_body  The inner XML body.
	 */
	public function trading_api_request( $call_name, $xml_body ) {
		$token = TCGiant_Sync_OAuth::instance()->get_access_token();

		if ( ! $token ) {
			TCGiant_Sync_Logger::error( 'eBay API Error: No valid access token found for Trading API.' );
			return new WP_Error( 'no_token', __( 'No valid eBay token.', 'tcgiant-sync' ) );
		}

		$url = 'https://api.ebay.com/ws/api.dll';

		$xml_envelope = '<?xml version="1.0" encoding="utf-8"?>
<' . $call_name . 'Request xmlns="urn:ebay:apis:eBLBaseComponents">
' . $xml_body . '
</' . $call_name . 'Request>';

		$config = $this->get_marketplace_config();

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'X-EBAY-API-SITEID'              => $config['site_id'],
				'X-EBAY-API-COMPATIBILITY-LEVEL' => '1323',
				'X-EBAY-API-CALL-NAME'           => $call_name,
				'X-EBAY-API-IAF-TOKEN'           => $token,
				'Content-Type'                   => 'text/xml',
			),
			'body'    => $xml_envelope,
			'timeout' => 45,
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			TCGiant_Sync_Logger::error( 'eBay Trading API Request Failed: ' . $response->get_error_message() );
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		
		$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA );
		if ( ! $xml ) {
			TCGiant_Sync_Logger::error( 'eBay Trading API Error: Invalid XML Response.' );
			return new WP_Error( 'invalid_xml', 'Invalid XML from eBay Trading API.' );
		}

		$json = wp_json_encode( $xml );
		$array = json_decode( $json, true );

		if ( isset( $array['Ack'] ) && 'Failure' === $array['Ack'] ) {
			// Normalize: eBay may return a single error object or an indexed array of errors.
			if ( isset( $array['Errors'][0] ) ) {
				$first_error = $array['Errors'][0];
				$error_parts = array();
				foreach ( $array['Errors'] as $err ) {
					if ( isset( $err['ShortMessage'] ) ) {
						$error_parts[] = $err['ShortMessage'];
					}
				}
				$error_msg  = ! empty( $error_parts ) ? implode( ' | ', $error_parts ) : 'Unknown Trading API Error';
				$error_code = isset( $first_error['ErrorCode'] ) ? (string) $first_error['ErrorCode'] : '';
			} else {
				$error_msg  = isset( $array['Errors']['ShortMessage'] ) ? $array['Errors']['ShortMessage'] : 'Unknown Trading API Error';
				$error_code = isset( $array['Errors']['ErrorCode'] ) ? (string) $array['Errors']['ErrorCode'] : '';
			}

			// eBay rate limit error codes:
			// 518  = "Calls to this call have exceeded the call limit."
			// 10007 = "Internal error to the application." (daily limit)
			// Also check for multiple errors returned as an array.
			$rate_limit_codes = array( '518', '10007' );
			$is_rate_limited = false;

			if ( in_array( $error_code, $rate_limit_codes, true ) ) {
				$is_rate_limited = true;
			} elseif ( isset( $array['Errors'][0] ) ) {
				// Multiple errors returned as indexed array.
				foreach ( $array['Errors'] as $err ) {
					if ( isset( $err['ErrorCode'] ) && in_array( (string) $err['ErrorCode'], $rate_limit_codes, true ) ) {
						$is_rate_limited = true;
						$error_msg = $err['ShortMessage'] ?? $error_msg;
						break;
					}
				}
			}

			// Also detect rate limiting from HTTP 429 or known error messages.
			if ( ! $is_rate_limited ) {
				$error_str = is_string( $error_msg ) ? strtolower( $error_msg ) : '';
				if ( false !== strpos( $error_str, 'call limit' ) || false !== strpos( $error_str, 'rate limit' ) || false !== strpos( $error_str, 'exceeded' ) ) {
					$is_rate_limited = true;
				}
			}

			if ( $is_rate_limited ) {
				TCGiant_Sync_Logger::log( sprintf( 'eBay API rate limit reached during %s. Will retry later.', $call_name ), 'warning' );
				return new WP_Error( 'rate_limited', is_string( $error_msg ) ? $error_msg : 'API call limit exceeded', $array );
			}

			TCGiant_Sync_Logger::error( sprintf( 'eBay Trading API Error (%s): %s', $call_name, is_string( $error_msg ) ? $error_msg : wp_json_encode( $error_msg ) ) );
			return new WP_Error( 'api_error', is_string( $error_msg ) ? $error_msg : 'API Error', $array );
		}

		return $array;
	}

	/**
	 * Get all active listings via Trading API.
	 *
	 * @param int    $page_number     Page number (1-indexed).
	 * @param int    $entries_per_page Limit per page.
	 * @param string $mod_time_from   Optional ISO 8601 timestamp. When provided, uses
	 *                                ModTimeFrom/ModTimeTo to only return items modified
	 *                                since this time (delta sync mode). When omitted,
	 *                                uses EndTimeFrom/EndTimeTo for a full scan.
	 */
	public function get_active_listings( $page_number = 1, $entries_per_page = 200, $mod_time_from = '' ) {
		// Use GetSellerList instead of GetMyeBaySelling because only GetSellerList
		// returns Storefront (StoreCategoryID) data needed for store category filtering.

		// GetSellerList requires either EndTime or ModTime range — they are mutually exclusive.
		if ( ! empty( $mod_time_from ) ) {
			// Delta sync mode: only items modified since the given timestamp.
			$xml = '
<ModTimeFrom>' . $mod_time_from . '</ModTimeFrom>
<ModTimeTo>' . gmdate( 'Y-m-d\TH:i:s.000\Z' ) . '</ModTimeTo>';
		} else {
			// Full scan mode: all active listings.
			$end_from = gmdate( 'Y-m-d\TH:i:s.000\Z' );
			$end_to   = gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( '+120 days' ) );
			$xml = '
<EndTimeFrom>' . $end_from . '</EndTimeFrom>
<EndTimeTo>' . $end_to . '</EndTimeTo>';
		}

		$xml .= '
<DetailLevel>ReturnAll</DetailLevel>
<Pagination>
	<EntriesPerPage>' . (int) $entries_per_page . '</EntriesPerPage>
	<PageNumber>' . (int) $page_number . '</PageNumber>
</Pagination>
<IncludeItemSpecifics>true</IncludeItemSpecifics>';

		return $this->trading_api_request( 'GetSellerList', $xml );
	}

	/**
	 * Get modified items with full detail via GetSellerEvents (Trading API).
	 *
	 * Unlike GetSellerList, GetSellerEvents returns complete item data inline
	 * (Description, ItemSpecifics, Variations, PictureDetails, ShippingPackageDetails, etc.)
	 * so individual GetItem calls are not needed.
	 *
	 * @param string $mod_time_from ISO 8601 UTC timestamp (start of window).
	 * @param string $mod_time_to   ISO 8601 UTC timestamp (end of window). Max 48hr span.
	 * @return array|WP_Error Parsed response or error.
	 */
	public function get_seller_events( $mod_time_from, $mod_time_to ) {
		$xml = '
<ModTimeFrom>' . $mod_time_from . '</ModTimeFrom>
<ModTimeTo>' . $mod_time_to . '</ModTimeTo>
<DetailLevel>ReturnAll</DetailLevel>
<IncludeItemSpecifics>true</IncludeItemSpecifics>
<IncludeVariationSpecifics>true</IncludeVariationSpecifics>
<HideVariations>false</HideVariations>';

		return $this->trading_api_request( 'GetSellerEvents', $xml );
	}

	/**
	 * Get full item details via Trading API.
	 *
	 * @param string $item_id The Item ID.
	 */
	public function get_item( $item_id ) {
		$xml = '
<ItemID>' . esc_attr( $item_id ) . '</ItemID>
<DetailLevel>ReturnAll</DetailLevel>
<IncludeItemSpecifics>true</IncludeItemSpecifics>';

		return $this->trading_api_request( 'GetItem', $xml );
	}

	/**
	 * Get user's eBay Store Details and Categories via Trading API.
	 */
	public function get_store() {
		$xml = '<DetailLevel>ReturnAll</DetailLevel><CategoryStructureOnly>true</CategoryStructureOnly>';
		return $this->trading_api_request( 'GetStore', $xml );
	}

	/**
	 * Get all active inventory items.
	 *
	 * @param int $limit  Results per page.
	 * @param int $offset Offset.
	 */
	public function get_inventory_items( $limit = 100, $offset = 0 ) {
		return $this->request( 'sell/inventory/v1/inventory_item', 'GET', array(), array(
			'limit'  => $limit,
			'offset' => $offset,
		) );
	}

	/**
	 * Get inventory item by SKU.
	 *
	 * @param string  $sku        The SKU.
	 * @param boolean $log_errors Whether to log errors automatically.
	 */
	public function get_inventory_item( $sku, $log_errors = true ) {
		return $this->request( 'sell/inventory/v1/inventory_item/' . rawurlencode( $sku ), 'GET', array(), array(), $log_errors );
	}

	/**
	 * Get offers for a specific SKU.
	 *
	 * @param string $sku The SKU.
	 */
	public function get_offers( $sku ) {
		return $this->request( 'sell/inventory/v1/offer', 'GET', array(), array(
			'sku' => $sku,
		) );
	}

	/**
	 * Update inventory item quantity.
	 * 
	 * Note: Inventory API uses SKU to manage availability.
	 */
	public function update_inventory_item_availability( $sku, $quantity ) {
		// Check if this is a Trading API listing (EBAY-{ItemID} SKU pattern).
		// These listings don't exist in the Inventory (REST) API and will always 404.
		if ( preg_match( '/^EBAY-(\d+)$/', $sku, $matches ) ) {
			return $this->update_trading_api_stock( $matches[1], $quantity );
		}

		// Try the Inventory API first.
		$item = $this->get_inventory_item( $sku, false );

		// If 404, the listing was created via Trading API with a custom SKU.
		// Fall back to ReviseInventoryStatus using the stored eBay Item ID.
		if ( is_wp_error( $item ) ) {
			$error_data = $item->get_error_data();
			$is_not_found = ( isset( $error_data['errors'][0]['errorId'] ) && 25710 === $error_data['errors'][0]['errorId'] );

			if ( $is_not_found ) {
				// Look up the eBay Item ID from WooCommerce product meta.
				$item_id = $this->resolve_ebay_item_id_from_sku( $sku );
				if ( $item_id ) {
					return $this->update_trading_api_stock( $item_id, $quantity );
				}
				
				return new WP_Error( 'not_found_on_ebay', __( 'Item not found on eBay. It may not be linked.', 'tcgiant-sync' ) );
			}
			return $item;
		}

		$item['availability']['shipToLocationAvailability']['quantity'] = (int) $quantity;
		
		return $this->request( 'sell/inventory/v1/inventory_item/' . rawurlencode( $sku ), 'PUT', $item );
	}

	/**
	 * Update stock quantity via the Trading API's ReviseInventoryStatus.
	 *
	 * This works for listings created through the Trading API (legacy listings)
	 * that don't exist in the Inventory (REST) API.
	 *
	 * @param string $item_id  The eBay Item ID (numeric).
	 * @param int    $quantity New stock quantity.
	 * @return array|WP_Error API response or error.
	 */
	public function update_trading_api_stock( $item_id, $quantity ) {
		// eBay's ReviseInventoryStatus rejects qty = 0.
		// When stock is depleted, the correct action is to end the listing.
		if ( (int) $quantity <= 0 ) {
			return $this->end_item( $item_id );
		}

		$xml = '
<InventoryStatus>
	<ItemID>' . esc_attr( $item_id ) . '</ItemID>
	<Quantity>' . (int) $quantity . '</Quantity>
</InventoryStatus>';

		return $this->trading_api_request( 'ReviseInventoryStatus', $xml );
	}

	/**
	 * End (close) an eBay listing via Trading API EndItem.
	 *
	 * Used when WooCommerce stock hits 0 — eBay does not allow setting
	 * quantity to 0 via ReviseInventoryStatus, so we end the listing instead.
	 *
	 * @param string $item_id The eBay Item ID (numeric).
	 * @return array|WP_Error API response or error.
	 */
	public function end_item( $item_id ) {
		$xml = '
<ItemID>' . esc_attr( $item_id ) . '</ItemID>
<EndingReason>NotAvailable</EndingReason>';

		$result = $this->trading_api_request( 'EndItem', $xml );

		if ( ! is_wp_error( $result ) ) {
			TCGiant_Sync_Logger::log(
				sprintf( 'eBay listing %s ended (stock = 0).', $item_id ),
				'success'
			);
		}

		return $result;
	}

	/**
	 * Look up the eBay Item ID from a WooCommerce product by its SKU.
	 *
	 * @param string $sku The product SKU.
	 * @return string|false The eBay Item ID, or false if not found.
	 */
	private function resolve_ebay_item_id_from_sku( $sku ) {
		$product_id = wc_get_product_id_by_sku( $sku );
		if ( ! $product_id ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$item_id = $product->get_meta( '_ebay_item_id' );
		return ! empty( $item_id ) ? $item_id : false;
	}
	/**
	 * Get recent orders from eBay Fulfillment API.
	 *
	 * @param int $limit  Results per page.
	 * @param int $offset Offset.
	 */
	public function get_orders( $limit = 50, $offset = 0 ) {
		return $this->request( 'sell/fulfillment/v1/order', 'GET', array(), array(
			'limit'  => $limit,
			'offset' => $offset,
			'filter' => 'creationdate:[' . gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '-24 hours' ) ) . '..]'
		) );
	}

	/**
	 * Create a new eBay listing via Trading API AddItem.
	 *
	 * @param string $item_xml The <Item>...</Item> XML block to wrap in the request.
	 * @return array|WP_Error Parsed API response or error. On success, contains 'ItemID'.
	 */
	public function add_item( $item_xml ) {
		$xml = '<ErrorLanguage>en_US</ErrorLanguage>
<WarningLevel>High</WarningLevel>
' . $item_xml;
		return $this->trading_api_request( 'AddItem', $xml );
	}

	/**
	 * Revise an existing eBay listing via Trading API ReviseItem.
	 *
	 * @param string $item_xml The <Item>...</Item> XML block to wrap in the request.
	 *                         Must include <ItemID> inside the block.
	 * @return array|WP_Error Parsed API response or error.
	 */
	public function revise_item( $item_xml ) {
		$xml = '<ErrorLanguage>en_US</ErrorLanguage>
<WarningLevel>High</WarningLevel>
' . $item_xml;
		return $this->trading_api_request( 'ReviseItem', $xml );
	}

	/**
	 * Fetch fulfillment (shipping) policies from eBay Account API.
	 *
	 * @param string $marketplace_id eBay marketplace ID, default EBAY_US.
	 * @return array|WP_Error List of fulfillment policies or error.
	 */
	public function get_fulfillment_policies( $marketplace_id = null ) {
		if ( null === $marketplace_id ) {
			$config = $this->get_marketplace_config();
			$marketplace_id = $config['marketplace_id'];
		}
		return $this->request( 'sell/account/v1/fulfillment_policy', 'GET', array(), array(
			'marketplace_id' => $marketplace_id,
		) );
	}

	/**
	 * Fetch return policies from eBay Account API.
	 *
	 * @param string $marketplace_id eBay marketplace ID, default EBAY_US.
	 * @return array|WP_Error List of return policies or error.
	 */
	public function get_return_policies( $marketplace_id = null ) {
		if ( null === $marketplace_id ) {
			$config = $this->get_marketplace_config();
			$marketplace_id = $config['marketplace_id'];
		}
		return $this->request( 'sell/account/v1/return_policy', 'GET', array(), array(
			'marketplace_id' => $marketplace_id,
		) );
	}

	/**
	 * Fetch payment policies from eBay Account API.
	 *
	 * @param string $marketplace_id eBay marketplace ID, default EBAY_US.
	 * @return array|WP_Error List of payment policies or error.
	 */
	public function get_payment_policies( $marketplace_id = null ) {
		if ( null === $marketplace_id ) {
			$config = $this->get_marketplace_config();
			$marketplace_id = $config['marketplace_id'];
		}
		return $this->request( 'sell/account/v1/payment_policy', 'GET', array(), array(
			'marketplace_id' => $marketplace_id,
		) );
	}

	// =========================================================================
	// Category Tree (Taxonomy API)
	// =========================================================================

	/**
	 * eBay marketplace ID → Taxonomy API category_tree_id mapping.
	 */
	const CATEGORY_TREE_IDS = array(
		'EBAY_US' => '0',
		'EBAY_GB' => '3',
		'EBAY_CA' => '2',
		'EBAY_AU' => '15',
		'EBAY_DE' => '77',
		'EBAY_FR' => '71',
		'EBAY_IT' => '101',
		'EBAY_ES' => '186',
	);

	/**
	 * Get top-level eBay categories (root children) from the Taxonomy API.
	 * Cached in a transient for 7 days since eBay's tree rarely changes.
	 *
	 * @return array|WP_Error Array of categories [{ id, name, leaf }] or error.
	 */
	public function get_category_tree_root() {
		$config  = $this->get_marketplace_config();
		$tree_id = self::CATEGORY_TREE_IDS[ $config['marketplace_id'] ] ?? '0';

		$transient_key = 'tcgiant_cat_tree_root_' . $tree_id;
		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = $this->request(
			'commerce/taxonomy/v1/category_tree/' . $tree_id,
			'GET',
			array(),
			array(),
			false
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$categories = array();
		if ( ! empty( $response['rootCategoryNode']['childCategoryTreeNodes'] ) ) {
			foreach ( $response['rootCategoryNode']['childCategoryTreeNodes'] as $node ) {
				$categories[] = array(
					'id'   => $node['category']['categoryId'] ?? '',
					'name' => $node['category']['categoryName'] ?? '',
					'leaf' => empty( $node['childCategoryTreeNodes'] ),
				);
			}
		}

		set_transient( $transient_key, $categories, 7 * DAY_IN_SECONDS );
		return $categories;
	}

	/**
	 * Get children of a specific eBay category via the Taxonomy API.
	 *
	 * @param string $category_id The parent category ID.
	 * @return array|WP_Error Array of categories [{ id, name, leaf }] or error.
	 */
	public function get_category_subtree( $category_id ) {
		$config  = $this->get_marketplace_config();
		$tree_id = self::CATEGORY_TREE_IDS[ $config['marketplace_id'] ] ?? '0';

		$transient_key = 'tcgiant_cat_sub_' . $tree_id . '_' . $category_id;
		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = $this->request(
			'commerce/taxonomy/v1/category_tree/' . $tree_id . '/get_category_subtree',
			'GET',
			array(),
			array( 'category_id' => $category_id ),
			false
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$categories = array();
		if ( ! empty( $response['categorySubtreeNode']['childCategoryTreeNodes'] ) ) {
			foreach ( $response['categorySubtreeNode']['childCategoryTreeNodes'] as $node ) {
				$categories[] = array(
					'id'   => $node['category']['categoryId'] ?? '',
					'name' => $node['category']['categoryName'] ?? '',
					'leaf' => empty( $node['childCategoryTreeNodes'] ),
				);
			}
		}

		set_transient( $transient_key, $categories, 7 * DAY_IN_SECONDS );
		return $categories;
	}
}
