<?php
/**
 * Data Mapper Logic
 *
 * Maps eBay inventory item data to WooCommerce product data.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
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

		$product_data['sku'] = $ebay_item['SKU'] ?? '';

		// Fallback 1: Try Product Identifiers (ISBN/UPC/EAN) if SKU is missing
		if ( empty( $product_data['sku'] ) && isset( $ebay_item['ProductListingDetails'] ) ) {
			$product_data['sku'] = $ebay_item['ProductListingDetails']['ISBN'] ?? 
								   $ebay_item['ProductListingDetails']['UPC'] ?? 
								   $ebay_item['ProductListingDetails']['EAN'] ?? '';
			
			// eBay sometimes returns 'Does not apply' or 'Does Not Apply' for missing identifiers
			if ( strtolower( $product_data['sku'] ) === 'does not apply' ) {
				$product_data['sku'] = '';
			}
		}

		// Fallback 2: Check Item Specifics for ISBN/UPC
		if ( empty( $product_data['sku'] ) && isset( $ebay_item['ItemSpecifics']['NameValueList'] ) ) {
			$nvl = $ebay_item['ItemSpecifics']['NameValueList'];
			if ( isset( $nvl['Name'] ) ) $nvl = array( $nvl );
			foreach ( $nvl as $spec ) {
				$name = strtolower( $spec['Name'] ?? '' );
				if ( in_array( $name, array( 'isbn', 'upc', 'ean' ), true ) ) {
					$val = is_array( $spec['Value'] ) ? reset( $spec['Value'] ) : $spec['Value'];
					if ( strtolower( $val ) !== 'does not apply' ) {
						$product_data['sku'] = $val;
						break;
					}
				}
			}
		}

		// Fallback 3: use EBAY-{ItemID} if no other identifier exists.
		if ( empty( $product_data['sku'] ) && ! empty( $item_id ) ) {
			$product_data['sku'] = 'EBAY-' . $item_id;
		}

		// Map Quantity (available = total - sold).
		$quantity = isset( $ebay_item['Quantity'] ) ? (int) $ebay_item['Quantity'] : 0;
		$sold = isset( $ebay_item['SellingStatus']['QuantitySold'] ) ? (int) $ebay_item['SellingStatus']['QuantitySold'] : 0;
		$product_data['stock_quantity'] = max( 0, $quantity - $sold );
		$product_data['manage_stock'] = true;

		// Map Price - handle all eBay XML->JSON price structures.
		$prices = $this->extract_prices( $ebay_item );
		$product_data['price'] = $prices['regular_price']; // fallback for other usages
		$product_data['regular_price'] = $prices['regular_price'];
		$product_data['sale_price']    = $prices['sale_price'];

		// Map Store Categories.
		$product_data['store_categories'] = array();
		if ( ! empty( $ebay_item['Storefront']['StoreCategoryID'] ) && '0' !== (string) $ebay_item['Storefront']['StoreCategoryID'] ) {
			$product_data['store_categories'][] = $ebay_item['Storefront']['StoreCategoryID'];
		}
		if ( ! empty( $ebay_item['Storefront']['StoreCategory2ID'] ) && '0' !== (string) $ebay_item['Storefront']['StoreCategory2ID'] ) {
			$product_data['store_categories'][] = $ebay_item['Storefront']['StoreCategory2ID'];
		}

		// Map Primary Category Fallback
		$product_data['primary_category_name'] = '';
		if ( isset( $ebay_item['PrimaryCategory']['CategoryName'] ) ) {
			// e.g., "Toys & Hobbies:Collectible Card Games:CCG Individual Cards"
			$cat_string = $ebay_item['PrimaryCategory']['CategoryName'];
			$parts = explode( ':', $cat_string );
			$product_data['primary_category_name'] = end( $parts );
		}

		// Map Attributes (from Item Specifics).
		$product_data['attributes'] = $this->extract_attributes( $ebay_item );

		// Map Tags from Specifics.
		$tags = array();
		$tag_keys = array( self::ATTR_SET, self::ATTR_GRADE, self::ATTR_GRADING_COMPANY, 'Character', 'Franchise' );
		
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$import_specs_as_tags = isset( $settings['import_specs_as_tags'] ) && '1' === $settings['import_specs_as_tags'];

		foreach ( $product_data['attributes'] as $name => $val ) {
			if ( $import_specs_as_tags || in_array( $name, $tag_keys, true ) ) {
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

		// Map Variations.
		$product_data['variations'] = array();
		if ( isset( $ebay_item['Variations']['Variation'] ) ) {
			$variations = $ebay_item['Variations']['Variation'];
			if ( isset( $variations['SKU'] ) || isset( $variations['StartPrice'] ) ) {
				$variations = array( $variations );
			}
			
			foreach ( $variations as $var ) {
				$var_prices = $this->extract_prices( $var );
				$var_data = array(
					'sku' => $var['SKU'] ?? '',
					'regular_price' => $var_prices['regular_price'],
					'sale_price' => $var_prices['sale_price'],
					'stock_quantity' => max( 0, (int) ($var['Quantity'] ?? 0) - (int) ($var['SellingStatus']['QuantitySold'] ?? 0) ),
					'attributes' => array(),
				);
				
				if ( isset( $var['VariationSpecifics']['NameValueList'] ) ) {
					$nvl = $var['VariationSpecifics']['NameValueList'];
					if ( isset( $nvl['Name'] ) ) $nvl = array( $nvl );
					foreach ( $nvl as $spec ) {
						$name = $spec['Name'] ?? '';
						$val = is_array( $spec['Value'] ) ? implode( ', ', $spec['Value'] ) : ( $spec['Value'] ?? '' );
						if ( $name ) {
							$var_data['attributes'][ $name ] = $val;
						}
					}
				}
				
				$product_data['variations'][] = $var_data;
			}
		}

		return $product_data;
	}

	/**
	 * Extract a clean numeric price from eBay's various response formats.
	 *
	 * eBay Trading API XML->JSON can return prices as:
	 * - A plain string: "29.99"
	 * - An array: {"@attributes": {"currencyID": "USD"}, "#text": "29.99"}
	 * - An array with underscore: {"__text": "29.99"}
	 * - Just a numeric value
	 *
	 * @param array $ebay_item The full eBay item array.
	 * @return string The clean numeric price or empty string.
	 */
	private function extract_prices( $ebay_item ) {
		$prices = array(
			'regular_price' => '',
			'sale_price'    => '',
		);

		// Current selling price
		$current_price = '';
		$price_candidates = array(
			$ebay_item['SellingStatus']['CurrentPrice'] ?? null,
			$ebay_item['SellingStatus']['ConvertedCurrentPrice'] ?? null,
			$ebay_item['StartPrice'] ?? null,
			$ebay_item['BuyItNowPrice'] ?? null,
		);

		foreach ( $price_candidates as $raw ) {
			if ( null !== $raw ) {
				$val = $this->parse_price_value( $raw );
				if ( '' !== $val && (float) $val > 0 ) {
					$current_price = $val;
					break;
				}
			}
		}

		// Original Retail Price
		$original_price = '';
		if ( isset( $ebay_item['DiscountPriceInfo']['OriginalRetailPrice'] ) ) {
			$val = $this->parse_price_value( $ebay_item['DiscountPriceInfo']['OriginalRetailPrice'] );
			if ( '' !== $val && (float) $val > 0 ) {
				$original_price = $val;
			}
		}

		if ( '' !== $original_price && (float) $original_price > (float) $current_price ) {
			$prices['regular_price'] = $original_price;
			$prices['sale_price']    = $current_price;
		} else {
			$prices['regular_price'] = $current_price;
		}

		return $prices;
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
			// SimpleXML->JSON format: {"#text": "29.99", "@attributes": {...}}
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

		// First, apply specific mappings
		foreach ( $mapping as $ebay_key => $woo_key ) {
			if ( isset( $specifics[ $ebay_key ] ) ) {
				$attributes[ $woo_key ] = $specifics[ $ebay_key ];
				unset( $specifics[ $ebay_key ] ); // Remove to avoid duplication
			}
		}

		// Then, map all remaining specifics directly
		foreach ( $specifics as $name => $val ) {
			// Skip internal identifiers that are already handled as SKUs
			if ( in_array( strtolower( $name ), array( 'isbn', 'upc', 'ean' ), true ) ) {
				continue;
			}
			$attributes[ $name ] = $val;
		}

		return $attributes;
	}

	/**
	 * Create/Update WooCommerce Product.
	 *
	 * @param array $product_data Mapped product data.
	 */
	public function save_as_product( $product_data ) {
		$item_id = $product_data['meta']['_ebay_item_id'];
		$product_id = false;

		// 1. Check by exact eBay Item ID first.
		global $wpdb;
		$found_by_meta = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ebay_item_id' AND meta_value = %s LIMIT 1", $item_id ) );
		
		if ( $found_by_meta ) {
			$product_id = (int) $found_by_meta;
		}

		// 2. If not found by Item ID, check by SKU to link to existing WC product.
		if ( ! $product_id && ! empty( $product_data['sku'] ) ) {
			$sku_product_id = wc_get_product_id_by_sku( $product_data['sku'] );
			if ( $sku_product_id ) {
				$existing_item_id = get_post_meta( $sku_product_id, '_ebay_item_id', true );
				if ( empty( $existing_item_id ) || $existing_item_id === $item_id ) {
					// Safe to link.
					$product_id = $sku_product_id;
				}
			}
		}

		// 3. Prevent SKU conflicts on save.
		// Check if the intended SKU is already taken by a DIFFERENT product.
		if ( ! empty( $product_data['sku'] ) ) {
			$conflict_id = wc_get_product_id_by_sku( $product_data['sku'] );
			if ( $conflict_id && $conflict_id !== $product_id ) {
				// SKU is taken by another product. We must make this one unique.
				$product_data['sku'] .= '-' . $item_id;
				TCGiant_Sync_Logger::log( sprintf( 'Duplicate SKU detected. Appending Item ID to ensure uniqueness: %s', $product_data['sku'] ), 'warning' );
			}
		}
		
		$is_variable = ! empty( $product_data['variations'] );
		
		if ( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product && $is_variable && ! $product->is_type( 'variable' ) ) {
				wp_set_object_terms( $product_id, 'variable', 'product_type' );
				$product = wc_get_product( $product_id );
			}
		} else {
			$product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
		}

		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$is_new = ! $product_id;
		$sync_decisions = array();

		if ( $is_new ) {
			$sync_decisions[] = 'New product imported. All fields synced from eBay.';
		}

		if ( $is_new || ! empty( $settings['overwrite_title'] ) ) {
			$product->set_name( $product_data['title'] );
			if ( ! $is_new ) $sync_decisions[] = 'Title updated (eBay won)';
		} elseif ( ! $is_new ) {
			$sync_decisions[] = 'Title skipped (WooCommerce won)';
		}

		if ( $is_new || ! empty( $settings['overwrite_desc'] ) ) {
			$product->set_description( $product_data['description'] );
			if ( ! $is_new ) $sync_decisions[] = 'Description updated (eBay won)';
		} elseif ( ! $is_new ) {
			$sync_decisions[] = 'Description skipped (WooCommerce won)';
		}

		// SKU is always enforced so WooCommerce knows which eBay item this is.
		$product->set_sku( $product_data['sku'] );

		// Stock is ALWAYS managed by eBay.
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $product_data['stock_quantity'] );
		$sync_decisions[] = 'Stock synced to ' . $product_data['stock_quantity'] . ' (eBay won)';
		
		// Set price (already extracted as clean numeric string).
		// By default setting empty('overwrite_price') means DO NOT overwrite if exists.
		// However, in settings we defaulted it to 1.
		$overwrite_price = isset( $settings['overwrite_price'] ) ? $settings['overwrite_price'] : '1';
		if ( ( $is_new || '1' === $overwrite_price ) ) {
			$has_price = false;
			if ( ! empty( $product_data['regular_price'] ) ) {
				$product->set_regular_price( $product_data['regular_price'] );
				$has_price = true;
			}
			if ( ! empty( $product_data['sale_price'] ) ) {
				$product->set_sale_price( $product_data['sale_price'] );
				$has_price = true;
			} else {
				$product->set_sale_price( '' ); // Clear sale price if no longer on sale
			}

			if ( $has_price && ! $is_new ) {
				$sync_decisions[] = 'Price updated (eBay won)';
			}
		} elseif ( ! $is_new && ! empty( $product_data['regular_price'] ) ) {
			$sync_decisions[] = 'Price skipped (WooCommerce won)';
		}
		
		$overwrite_taxonomy = ! empty( $settings['overwrite_taxonomy'] );

		// Set WooCommerce categories from eBay Store Categories.
		if ( $is_new || $overwrite_taxonomy ) {
			if ( ! empty( $product_data['store_categories'] ) ) {
				$cat_ids = $this->resolve_and_create_categories( $product_data['store_categories'] );
				if ( ! empty( $cat_ids ) ) {
					$product->set_category_ids( $cat_ids );
				}
			} elseif ( ! empty( $product_data['primary_category_name'] ) ) {
				// Fallback to Primary Category if no Store Category is found
				$term = term_exists( $product_data['primary_category_name'], 'product_cat' );
				if ( ! $term ) {
					$term = wp_insert_term( $product_data['primary_category_name'], 'product_cat' );
				}
				if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
					$product->set_category_ids( array( (int) $term['term_id'] ) );
				}
			}
			if ( ! $is_new ) $sync_decisions[] = 'Categories & Tags updated (eBay won)';
		} elseif ( ! $is_new ) {
			$sync_decisions[] = 'Categories & Tags skipped (WooCommerce won)';
		}
		
		// Set WooCommerce tags from eBay Item Specifics.
		if ( $is_new || $overwrite_taxonomy ) {
			if ( ! empty( $product_data['tags'] ) ) {
				$tag_ids = $this->resolve_and_create_tags( $product_data['tags'] );
				if ( ! empty( $tag_ids ) ) {
					$product->set_tag_ids( $tag_ids );
				}
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
		
		// Gather all possible values for variation attributes to set at parent level
		$parent_attr_values = array();
		if ( $is_variable ) {
			foreach ( $product_data['variations'] as $var ) {
				if ( ! empty( $var['attributes'] ) ) {
					foreach ( $var['attributes'] as $name => $val ) {
						$parent_attr_values[ $name ][] = $val;
					}
				}
			}
		}
		
		// Map normal item specifics
		foreach ( $product_data['attributes'] as $name => $value ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( array( $value ) );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$woo_attributes[ sanitize_title( $name ) ] = $attribute;
		}

		// Map variation specifics
		foreach ( $parent_attr_values as $name => $values ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( array_unique( $values ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$woo_attributes[ sanitize_title( $name ) ] = $attribute;
		}
		
		$product->set_attributes( array_values( $woo_attributes ) );

		// Set Custom Meta (including _ebay_item_id for tracking).
		foreach ( $product_data['meta'] as $key => $val ) {
			$product->update_meta_data( $key, $val );
		}

		// Update Sync Log.
		if ( ! empty( $sync_decisions ) ) {
			$existing_logs = $product->get_meta( '_tcgiant_sync_log', true );
			if ( ! is_array( $existing_logs ) ) {
				$existing_logs = array();
			}

			array_unshift( $existing_logs, array(
				'timestamp' => current_time( 'mysql' ),
				'decisions' => $sync_decisions,
			) );

			$existing_logs = array_slice( $existing_logs, 0, 20 ); // Keep last 20 syncs
			$product->update_meta_data( '_tcgiant_sync_log', $existing_logs );
		}

		$saved_id = $product->save();
		
		if ( $is_variable && $saved_id ) {
			$this->save_variations( $saved_id, $product_data['variations'] );
		}

		return $saved_id;
	}

	/**
	 * Save child variations for a variable product.
	 *
	 * @param int $parent_id WooCommerce Parent Product ID.
	 * @param array $variations_data Array of variation data from map_ebay_to_woo.
	 */
	private function save_variations( $parent_id, $variations_data ) {
		$product = wc_get_product( $parent_id );
		$existing_variations = $product->get_children();
		$processed_ids = array();
		
		foreach ( $variations_data as $var_data ) {
			$variation_id = 0;
			
			// Try to match by SKU
			if ( ! empty( $var_data['sku'] ) ) {
				$conflict_id = wc_get_product_id_by_sku( $var_data['sku'] );
				if ( $conflict_id ) {
					$conflict_product = wc_get_product( $conflict_id );
					if ( $conflict_product && $conflict_product->get_parent_id() === $parent_id ) {
						$variation_id = $conflict_id;
					} else {
						$var_data['sku'] .= '-' . $parent_id; // prevent conflict
					}
				}
			}
			
			$variation = $variation_id ? wc_get_product( $variation_id ) : new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			
			$variation->set_sku( $var_data['sku'] );
			$variation->set_regular_price( $var_data['regular_price'] );
			if ( ! empty( $var_data['sale_price'] ) ) {
				$variation->set_sale_price( $var_data['sale_price'] );
			} else {
				$variation->set_sale_price( '' );
			}
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $var_data['stock_quantity'] );
			$variation->set_status( 'publish' );
			
			// Set attributes (WC requires keys to be taxonomy slugs or lowercase attribute names)
			$var_attrs = array();
			foreach ( $var_data['attributes'] as $name => $val ) {
				$var_attrs[ sanitize_title( $name ) ] = $val;
			}
			$variation->set_attributes( $var_attrs );
			
			$saved_var_id = $variation->save();
			if ( $saved_var_id ) {
				$processed_ids[] = $saved_var_id;
			}
		}
		
		// Delete any existing variations that are no longer present
		foreach ( $existing_variations as $existing_id ) {
			if ( ! in_array( $existing_id, $processed_ids ) ) {
				$var_to_delete = wc_get_product( $existing_id );
				if ( $var_to_delete ) {
					$var_to_delete->delete( true );
				}
			}
		}
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
