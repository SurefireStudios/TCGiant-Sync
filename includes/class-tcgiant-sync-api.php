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
	 * Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Send request to eBay API.
	 *
	 * @param string $endpoint API Endpoint.
	 * @param string $method   HTTP Method.
	 * @param array  $body     Request body.
	 * @param array  $params   Query parameters.
	 */
	public function request( $endpoint, $method = 'GET', $body = array(), $params = array() ) {
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
			TCGiant_Sync_Logger::error( sprintf( 'eBay API Error (%d): %s', $code, wp_remote_retrieve_body( $response ) ) );
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

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'X-EBAY-API-SITEID'              => '0',
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
			$error_msg = isset( $array['Errors']['ShortMessage'] ) ? $array['Errors']['ShortMessage'] : 'Unknown Trading API Error';
			TCGiant_Sync_Logger::error( sprintf( 'eBay Trading API Error (%s): %s', $call_name, is_string( $error_msg ) ? $error_msg : wp_json_encode( $error_msg ) ) );
			return new WP_Error( 'api_error', is_string( $error_msg ) ? $error_msg : 'API Error', $array );
		}

		return $array;
	}

	/**
	 * Get all active listings via Trading API.
	 *
	 * @param int $page_number Page number (1-indexed).
	 * @param int $entries_per_page Limit per page.
	 */
	public function get_active_listings( $page_number = 1, $entries_per_page = 200 ) {
		// Use GetSellerList instead of GetMyeBaySelling because only GetSellerList
		// returns Storefront (StoreCategoryID) data needed for store category filtering.
		$end_from = gmdate( 'Y-m-d\TH:i:s.000\Z' );
		$end_to   = gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( '+120 days' ) );

		$xml = '
<EndTimeFrom>' . $end_from . '</EndTimeFrom>
<EndTimeTo>' . $end_to . '</EndTimeTo>
<GranularityLevel>Fine</GranularityLevel>
<Pagination>
	<EntriesPerPage>' . (int) $entries_per_page . '</EntriesPerPage>
	<PageNumber>' . (int) $page_number . '</PageNumber>
</Pagination>
<OutputSelector>ItemID</OutputSelector>
<OutputSelector>Title</OutputSelector>
<OutputSelector>PrimaryCategory</OutputSelector>
<OutputSelector>Storefront</OutputSelector>
<OutputSelector>QuantityAvailable</OutputSelector>
<OutputSelector>PaginationResult</OutputSelector>';

		return $this->trading_api_request( 'GetSellerList', $xml );
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
		$xml = '<DetailLevel>ReturnAll</DetailLevel>';
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
	 * @param string $sku The SKU.
	 */
	public function get_inventory_item( $sku ) {
		return $this->request( 'sell/inventory/v1/inventory_item/' . rawurlencode( $sku ) );
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
		// First get the item to preserve other data (optional, depends on use case, 
		// but Inventory API requires full object for PUT).
		$item = $this->get_inventory_item( $sku );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$item['availability']['shipToLocationAvailability']['quantity'] = (int) $quantity;
		
		return $this->request( 'sell/inventory/v1/inventory_item/' . rawurlencode( $sku ), 'PUT', $item );
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
}
