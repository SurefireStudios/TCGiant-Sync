<?php
/**
 * Data Mapper Logic
 *
 * Maps eBay inventory item data to WooCommerce product data.
 *
 * @package TCGiant_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Mapper class
 */
class TCGiant_Sync_Mapper {

	/**
	 * Instance.
	 */
	private static $_instance = null;

	/**
	 * TCG Attribute Keys.
	 */
	const ATTR_GAME = 'Game';
	const ATTR_SET  = 'Set';
	const ATTR_NAME = 'Card Name';
	const ATTR_NUMBER = 'Card Number';
	const ATTR_RARITY = 'Rarity';
	const ATTR_GRADE  = 'Grade';
	const ATTR_GRADING_COMPANY = 'Grading Company';
	const ATTR_CONDITION = 'Condition';

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
	 * Map eBay data to WooCommerce Product Array.
	 *
	 * @param array $ebay_item Raw data from eBay Trading API (GetItem response).
	 */
	public function map_ebay_to_woo( $ebay_item ) {
		$product_data = array();

		$item_id = $ebay_item['ItemID'] ?? '';

		// Basic Post Data.
		$product_data['title'] = $ebay_item['Title'] ?? '';
		$product_data['description'] = $ebay_item['Description'] ?? '';
		$product_data['sku'] = $ebay_item['SKU'] ?? '';

		// Fallback SKU: use EBAY-{ItemID} if no SKU exists.
		if ( empty( $product_data['sku'] ) && ! empty( $item_id ) ) {
			$product_data['sku'] = 'EBAY-' . $item_id;
		}

		// Map Quantity (available = total - sold).
		$quantity = isset( $ebay_item['Quantity'] ) ? (int) $ebay_item['Quantity'] : 0;
		$sold = isset( $ebay_item['SellingStatus']['QuantitySold'] ) ? (int) $ebay_item['SellingStatus']['QuantitySold'] : 0;
		$product_data['stock_quantity'] = max( 0, $quantity - $sold );
		$product_data['manage_stock'] = true;

		// Map Price — handle all eBay XML→JSON price structures.
		$product_data['price'] = $this->extract_price( $ebay_item );

		// Map Store Categories.
		$product_data['store_categories'] = array();
		if ( ! empty( $ebay_item['Storefront']['StoreCategoryID'] ) && '0' !== (string) $ebay_item['Storefront']['StoreCategoryID'] ) {
			$product_data['store_categories'][] = $ebay_item['Storefront']['StoreCategoryID'];
		}
		if ( ! empty( $ebay_item['Storefront']['StoreCategory2ID'] ) && '0' !== (string) $ebay_item['Storefront']['StoreCategory2ID'] ) {
			$product_data['store_categories'][] = $ebay_item['Storefront']['StoreCategory2ID'];
		}

		// Map Attributes (from Item Specifics).
		$product_data['attributes'] = $this->extract_attributes( $ebay_item );

		// Map Tags from Specifics.
		$tags = array();
		$tag_keys = array( self::ATTR_SET, self::ATTR_GRADE, self::ATTR_GRADING_COMPANY, 'Character', 'Franchise' );
		foreach ( $product_data['attributes'] as $name => $val ) {
			if ( in_array( $name, $tag_keys, true ) ) {
				$tags[] = $val;
			}
		}
		$product_data['tags'] = $tags;

		// Image URLs.
		$images = array();
		if ( isset( $ebay_item['PictureDetails']['PictureURL'] ) ) {
			if ( is_array( $ebay_item['PictureDetails']['PictureURL'] ) ) {
				$images = $ebay_item['PictureDetails']['PictureURL'];
			} else {
				$images = array( $ebay_item['PictureDetails']['PictureURL'] );
			}
		}
		$product_data['images'] = $images;

		// Product Meta.
		$product_data['meta'] = array(
			'_ebay_sku'          => $product_data['sku'],
			'_ebay_item_id'      => $item_id,
			'_sync_last_updated' => current_time( 'mysql' ),
		);

		return $product_data;
	}

	/**
	 * Extract a clean numeric price from eBay's various response formats.
	 *
	 * eBay Trading API XML→JSON can return prices as:
	 * - A plain string: "29.99"
	 * - An array: {"@attributes": {"currencyID": "USD"}, "#text": "29.99"}
	 * - An array with underscore: {"__text": "29.99"}
	 * - Just a numeric value
	 *
	 * @param array $ebay_item The full eBay item array.
	 * @return string The clean numeric price or empty string.
	 */
	private function extract_price( $ebay_item ) {
		// Try CurrentPrice first (actual selling price), then StartPrice.
		$price_candidates = array(
			$ebay_item['SellingStatus']['CurrentPrice'] ?? null,
			$ebay_item['SellingStatus']['ConvertedCurrentPrice'] ?? null,
			$ebay_item['StartPrice'] ?? null,
			$ebay_item['BuyItNowPrice'] ?? null,
		);

		foreach ( $price_candidates as $raw ) {
			if ( null === $raw ) {
				continue;
			}

			$value = $this->parse_price_value( $raw );
			if ( '' !== $value && (float) $value > 0 ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Parse a single price value from various formats.
	 *
	 * @param mixed $raw The raw price value.
	 * @return string Clean numeric string.
	 */
	private function parse_price_value( $raw ) {
		if ( is_string( $raw ) || is_numeric( $raw ) ) {
			return (string) $raw;
		}

		if ( is_array( $raw ) ) {
			// SimpleXML→JSON format: {"#text": "29.99", "@attributes": {...}}
			if ( isset( $raw['#text'] ) ) {
				return (string) $raw['#text'];
			}
			// Alternative format: {"__text": "29.99"}
			if ( isset( $raw['__text'] ) ) {
				return (string) $raw['__text'];
			}
			// Sometimes the value key itself contains the price
			if ( isset( $raw['value'] ) ) {
				return (string) $raw['value'];
			}
		}

		return '';
	}

	/**
	 * Extract attributes from eBay Item Specifics.
	 *
	 * @param array $ebay_item Raw data from eBay Trading API.
	 */
	private function extract_attributes( $ebay_item ) {
		$attributes = array();
		
		$specifics = array();
		if ( isset( $ebay_item['ItemSpecifics']['NameValueList'] ) ) {
			$nvl = $ebay_item['ItemSpecifics']['NameValueList'];
			if ( isset( $nvl['Name'] ) ) { // single item
				$nvl = array( $nvl );
			}
			foreach ( $nvl as $spec ) {
				$name = $spec['Name'] ?? '';
				$value = $spec['Value'] ?? '';
				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}
				if ( $name ) {
					$specifics[ $name ] = $value;
				}
			}
		}

		// Map standard eBay aspects to TCGiant Attributes.
		$mapping = array(
			'Game'              => self::ATTR_GAME,
			'Set'               => self::ATTR_SET,
			'Card Name'         => self::ATTR_NAME,
			'Card Number'       => self::ATTR_NUMBER,
			'Rarity'            => self::ATTR_RARITY,
			'Grade'             => self::ATTR_GRADE,
			'Grading Company'   => self::ATTR_GRADING_COMPANY,
			'Condition'         => self::ATTR_CONDITION,
		);

		foreach ( $mapping as $ebay_key => $woo_key ) {
			if ( isset( $specifics[ $ebay_key ] ) ) {
				$attributes[ $woo_key ] = $specifics[ $ebay_key ];
			}
		}

		return $attributes;
	}

	/**
	 * Create/Update WooCommerce Product.
	 *
	 * @param array $product_data Mapped product data.
	 */
	public function save_as_product( $product_data ) {
		// Check for existing product by SKU (handles re-imports).
		$product_id = wc_get_product_id_by_sku( $product_data['sku'] );
		
		$product = $product_id ? wc_get_product( $product_id ) : new WC_Product_Simple();

		$product->set_name( $product_data['title'] );
		$product->set_description( $product_data['description'] );
		$product->set_sku( $product_data['sku'] );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $product_data['stock_quantity'] );
		
		// Set price (already extracted as clean numeric string).
		if ( ! empty( $product_data['price'] ) ) {
			$product->set_regular_price( $product_data['price'] );
		}
		
		// Set WooCommerce categories from eBay Store Categories.
		if ( ! empty( $product_data['store_categories'] ) ) {
			$cat_ids = $this->resolve_and_create_categories( $product_data['store_categories'] );
			if ( ! empty( $cat_ids ) ) {
				$product->set_category_ids( $cat_ids );
			}
		}
		
		// Set WooCommerce tags from eBay Item Specifics.
		if ( ! empty( $product_data['tags'] ) ) {
			$tag_ids = $this->resolve_and_create_tags( $product_data['tags'] );
			if ( ! empty( $tag_ids ) ) {
				$product->set_tag_ids( $tag_ids );
			}
		}
		
		// Only set status on brand new products.
		if ( ! $product_id ) {
			$settings = TCGiant_Sync_OAuth::instance()->get_settings();
			$import_status = $settings['import_status'] ?? 'publish';
			$product->set_status( $import_status );
		}
		
		// Set Attributes as visible product data.
		$woo_attributes = array();
		foreach ( $product_data['attributes'] as $name => $value ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( array( $value ) );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$woo_attributes[] = $attribute;
		}
		$product->set_attributes( $woo_attributes );

		// Set Custom Meta (including _ebay_item_id for tracking).
		foreach ( $product_data['meta'] as $key => $val ) {
			$product->update_meta_data( $key, $val );
		}

		return $product->save();
	}

	/**
	 * Map eBay Store Category IDs to WooCommerce Categories, creating if they don't exist.
	 */
	private function resolve_and_create_categories( $store_cat_ids ) {
		$names = $this->get_store_category_names( $store_cat_ids );
		$woo_cat_ids = array();
		foreach ( $names as $cat_name ) {
			if ( empty( trim( $cat_name ) ) || 'Other' === $cat_name ) continue;
			$term = term_exists( $cat_name, 'product_cat' );
			if ( ! $term ) {
				$term = wp_insert_term( $cat_name, 'product_cat' );
			}
			if ( ! is_wp_error( $term ) ) {
				$woo_cat_ids[] = (int) $term['term_id'];
			}
		}
		return $woo_cat_ids;
	}

	/**
	 * Map tag strings to WooCommerce product tags, creating if they don't exist.
	 */
	private function resolve_and_create_tags( $tags ) {
		$tag_ids = array();
		foreach ( $tags as $tag_name ) {
			if ( empty( trim( $tag_name ) ) || 'Ungraded' === $tag_name || 'Not Graded' === $tag_name ) continue;
			$term = term_exists( $tag_name, 'product_tag' );
			if ( ! $term ) {
				$term = wp_insert_term( $tag_name, 'product_tag' );
			}
			if ( ! is_wp_error( $term ) ) {
				$tag_ids[] = (int) $term['term_id'];
			}
		}
		return array_unique( $tag_ids );
	}

	/**
	 * Translate numeric eBay Store Category IDs into their textual names.
	 */
	private function get_store_category_names( $search_ids ) {
		$map = get_transient( 'tcgiant_sync_store_cat_map' );
		if ( false === $map ) {
			$map = array();
			$api = TCGiant_Sync_API::instance();
			$store_response = $api->get_store();
			if ( ! is_wp_error( $store_response ) && isset( $store_response['Store']['CustomCategories']['CustomCategory'] ) ) {
				$categories = $store_response['Store']['CustomCategories']['CustomCategory'];
				if ( isset( $categories['CategoryID'] ) ) {
					$categories = array( $categories );
				}
				$flatten = function( $cats ) use ( &$flatten, &$map ) {
					if ( ! is_array( $cats ) ) return;
					foreach ( $cats as $cat ) {
						if ( isset( $cat['CategoryID'] ) && isset( $cat['Name'] ) ) {
							$map[ (string) $cat['CategoryID'] ] = $cat['Name'];
						}
						if ( isset( $cat['ChildCategory'] ) ) {
							$children = isset( $cat['ChildCategory']['CategoryID'] ) ? array( $cat['ChildCategory'] ) : $cat['ChildCategory'];
							$flatten( $children );
						}
					}
				};
				$flatten( $categories );
			}
			set_transient( 'tcgiant_sync_store_cat_map', $map, 3600 );
		}

		$names = array();
		foreach ( $search_ids as $id ) {
			if ( isset( $map[ (string) $id ] ) ) {
				$names[] = $map[ (string) $id ];
			}
		}
		return $names;
	}
}
