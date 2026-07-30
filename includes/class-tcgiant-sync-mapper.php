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

		// Check if eBay SKU should be routed to a bin location instead of WooCommerce SKU.
		$product_data['bin_location'] = '';
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$sku_maps_to = $settings['sku_maps_to'] ?? 'sku';
		
		if ( 'bin_location' === $sku_maps_to && ! empty( $product_data['sku'] ) ) {
			$product_data['bin_location'] = $product_data['sku'];
			$product_data['sku'] = ''; // Clear so it falls through to ISBN/UPC/EAN or EBAY-{ItemID}
		} elseif ( 'both' === $sku_maps_to && ! empty( $product_data['sku'] ) ) {
			$product_data['bin_location'] = $product_data['sku'];
			// Do not clear SKU so it gets assigned to both WooCommerce SKU and Bin Location.
		}

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

		// Fallback 3: use ItemID as SKU if no other identifier exists.
		// The sku_prefix setting controls whether to add "EBAY-" prefix (default: no prefix).
		if ( empty( $product_data['sku'] ) && ! empty( $item_id ) ) {
			$sku_prefix = ! empty( $settings['sku_prefix'] ) ? $settings['sku_prefix'] : 'none';
			$product_data['sku'] = ( 'ebay' === $sku_prefix ) ? 'EBAY-' . $item_id : $item_id;
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

		// Weight & Dimensions from ShippingPackageDetails.
		$product_data['weight']     = '';
		$product_data['length']     = '';
		$product_data['width']      = '';
		$product_data['height']     = '';

		if ( isset( $ebay_item['ShippingPackageDetails'] ) ) {
			$pkg = $ebay_item['ShippingPackageDetails'];

			// Weight: eBay returns WeightMajor (lbs/kg) + WeightMinor (oz/g).
			// WooCommerce uses a single weight field in the store's configured unit.
			$weight_major = $this->parse_measurement_value( $pkg['WeightMajor'] ?? null );
			$weight_minor = $this->parse_measurement_value( $pkg['WeightMinor'] ?? null );

			if ( '' !== $weight_major || '' !== $weight_minor ) {
				$major = (float) $weight_major;
				$minor = (float) $weight_minor;

				// Determine unit system from eBay data.
				$major_unit = $this->parse_measurement_unit( $pkg['WeightMajor'] ?? null, 'weight' );
				$is_metric  = ( 'kg' === strtolower( $major_unit ) );

				if ( $is_metric ) {
					// kg + g → kg
					$total_kg = $major + ( $minor / 1000 );
					$product_data['weight'] = $this->convert_weight_to_store_unit( $total_kg, 'kg' );
				} else {
					// lbs + oz → lbs
					$total_lbs = $major + ( $minor / 16 );
					$product_data['weight'] = $this->convert_weight_to_store_unit( $total_lbs, 'lbs' );
				}
			}

			// Dimensions: PackageLength, PackageWidth, PackageDepth.
			$length_val = $this->parse_measurement_value( $pkg['PackageLength'] ?? null );
			$width_val  = $this->parse_measurement_value( $pkg['PackageWidth'] ?? null );
			$depth_val  = $this->parse_measurement_value( $pkg['PackageDepth'] ?? null );

			$dim_unit = $this->parse_measurement_unit( $pkg['PackageLength'] ?? $pkg['PackageWidth'] ?? null, 'dimension' );

			if ( '' !== $length_val ) {
				$product_data['length'] = $this->convert_dimension_to_store_unit( (float) $length_val, $dim_unit );
			}
			if ( '' !== $width_val ) {
				$product_data['width'] = $this->convert_dimension_to_store_unit( (float) $width_val, $dim_unit );
			}
			if ( '' !== $depth_val ) {
				$product_data['height'] = $this->convert_dimension_to_store_unit( (float) $depth_val, $dim_unit );
			}
		}

		// Product Meta.
		$product_data['meta'] = array(
			'_ebay_sku'              => $product_data['sku'],
			'_ebay_item_id'          => $item_id,
			'_sync_last_updated'     => current_time( 'mysql' ),
			'_ebay_listing_type'     => $ebay_item['ListingType'] ?? '',
			'_ebay_listing_duration' => $ebay_item['ListingDuration'] ?? '',
			'_ebay_end_time'         => $ebay_item['ListingDetails']['EndTime'] ?? '',
			'_ebay_listing_status'   => $ebay_item['SellingStatus']['ListingStatus'] ?? 'Active',
		);

		// Map Variations.
		$product_data['variations'] = array();
		if ( isset( $ebay_item['Variations']['Variation'] ) ) {
			$variations = $ebay_item['Variations']['Variation'];
			if ( isset( $variations['SKU'] ) || isset( $variations['StartPrice'] ) ) {
				$variations = array( $variations );
			}
			
			// Parse variation-specific pictures if present.
			$variation_pictures = array();
			if ( isset( $ebay_item['Variations']['Pictures']['VariationSpecificPictureSet'] ) ) {
				$sets = $ebay_item['Variations']['Pictures']['VariationSpecificPictureSet'];
				if ( isset( $sets['VariationSpecificValue'] ) ) {
					$sets = array( $sets );
				}
				foreach ( $sets as $set ) {
					$val = $set['VariationSpecificValue'] ?? '';
					$urls = array();
					if ( isset( $set['PictureURL'] ) ) {
						$urls = is_array( $set['PictureURL'] ) ? $set['PictureURL'] : array( $set['PictureURL'] );
					}
					if ( $val && ! empty( $urls ) ) {
						$variation_pictures[ $val ] = $urls[0]; // First photo is thumbnail
					}
				}
			}
			
			foreach ( $variations as $var ) {
				$var_prices = $this->extract_prices( $var );
				$var_data = array(
					'sku'            => $var['SKU'] ?? '',
					'regular_price'  => $var_prices['regular_price'],
					'sale_price'     => $var_prices['sale_price'],
					'stock_quantity' => max( 0, (int) ($var['Quantity'] ?? 0) - (int) ($var['SellingStatus']['QuantitySold'] ?? 0) ),
					'attributes'     => array(),
					'image_url'      => '',
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
						// Check if this specific value has a corresponding picture
						if ( isset( $variation_pictures[ $val ] ) ) {
							$var_data['image_url'] = $variation_pictures[ $val ];
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

		// Bake flat-rate shipping cost into price if setting is enabled.
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		if ( ! empty( $settings['bake_shipping_into_price'] ) && '1' === $settings['bake_shipping_into_price'] ) {
			$shipping_cost = $this->extract_shipping_cost( $ebay_item );
			if ( $shipping_cost > 0 ) {
				if ( '' !== $prices['regular_price'] ) {
					$prices['regular_price'] = wc_format_decimal( (float) $prices['regular_price'] + $shipping_cost, 2 );
				}
				if ( '' !== $prices['sale_price'] ) {
					$prices['sale_price'] = wc_format_decimal( (float) $prices['sale_price'] + $shipping_cost, 2 );
				}
			}
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
	 * Extract flat-rate shipping cost from an eBay item.
	 *
	 * Returns the first domestic ShippingServiceCost as a float.
	 * Returns 0.0 for free shipping, calculated shipping, or missing data.
	 *
	 * @param array $ebay_item Raw data from eBay Trading API.
	 * @return float Shipping cost in listing currency.
	 */
	private function extract_shipping_cost( $ebay_item ) {
		$options = $ebay_item['ShippingDetails']['ShippingServiceOptions'] ?? null;
		if ( empty( $options ) ) {
			return 0.0;
		}

		// Normalize: single service is an assoc array, multiple is indexed array.
		if ( isset( $options['ShippingService'] ) ) {
			// Single shipping service — $options is the service itself.
			$first = $options;
		} else {
			// Multiple services — grab the first one.
			$first = $options[0] ?? null;
		}

		if ( empty( $first ) || ! isset( $first['ShippingServiceCost'] ) ) {
			return 0.0;
		}

		$cost = $this->parse_price_value( $first['ShippingServiceCost'] );
		return ( '' !== $cost ) ? (float) $cost : 0.0;
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
	public function save_as_product( &$product_data ) {
		$item_id = $product_data['meta']['_ebay_item_id'];
		$product_id = false;

		// 1. Check by exact eBay Item ID first (exclude trashed products).
		global $wpdb;
		$found_by_meta = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_ebay_item_id' AND pm.meta_value = %s
			   AND p.post_status != 'trash'
			 LIMIT 1",
			$item_id
		) );
		
		if ( $found_by_meta ) {
			$product_id = (int) $found_by_meta;
		}

		// 2. If not found by Item ID, check by SKU to link to existing WC product.
		//    This handles the case where eBay changed the item number (e.g. old listing
		//    expired and seller created a new listing with the same SKU).
		if ( ! $product_id && ! empty( $product_data['sku'] ) ) {
			$sku_product_id = wc_get_product_id_by_sku( $product_data['sku'] );
			if ( $sku_product_id ) {
				// Skip trashed products — they shouldn't block matching.
				$post_status = get_post_status( $sku_product_id );
				if ( 'trash' === $post_status ) {
					// Trashed product holds this SKU. Clear its SKU so it doesn't
					// block the active import, and continue as if not found.
					$trashed_product = wc_get_product( $sku_product_id );
					if ( $trashed_product ) {
						$trashed_product->set_sku( '' );
						$trashed_product->save();
						TCGiant_Sync_Logger::log( sprintf(
							'Cleared SKU from trashed product WC #%d to allow re-linking.',
							$sku_product_id
						), 'warning' );
					}
				} else {
					$existing_item_id = get_post_meta( $sku_product_id, '_ebay_item_id', true );
					if ( empty( $existing_item_id ) || $existing_item_id === $item_id ) {
						// No eBay link or same item → safe to link.
						$product_id = $sku_product_id;
					} else {
						// SKU matches but _ebay_item_id is different. This usually means
						// eBay expired the old listing and the seller created a new one.
						// Re-link the WC product to the new eBay listing.
						TCGiant_Sync_Logger::log( sprintf(
							'SKU "%s" matched WC #%d which has old eBay Item ID %s → re-linking to new eBay Item ID %s.',
							$product_data['sku'], $sku_product_id, $existing_item_id, $item_id
						) );
						$product_id = $sku_product_id;
						// Clear the old item ID so it gets replaced with the new one.
						update_post_meta( $sku_product_id, '_ebay_item_id', $item_id );
					}
				}
			}
		}

		// 3. Prevent SKU conflicts on save.
		// Check if the intended SKU is already taken by a DIFFERENT product.
		// Exclude trashed products from conflict detection.
		if ( ! empty( $product_data['sku'] ) ) {
			$conflict_id = wc_get_product_id_by_sku( $product_data['sku'] );
			if ( $conflict_id && $conflict_id !== $product_id ) {
				$conflict_status = get_post_status( $conflict_id );
				if ( 'trash' === $conflict_status ) {
					// Conflict is a trashed product — clear its SKU instead of mangling ours.
					$trashed = wc_get_product( $conflict_id );
					if ( $trashed ) {
						$trashed->set_sku( '' );
						$trashed->save();
						TCGiant_Sync_Logger::log( sprintf(
							'Cleared SKU from trashed product WC #%d (was blocking SKU "%s").',
							$conflict_id, $product_data['sku']
						), 'warning' );
					}
				} else {
					// SKU is taken by another active product. We must make this one unique.
					$product_data['sku'] .= '-' . $item_id;
					TCGiant_Sync_Logger::log( sprintf( 'Duplicate SKU detected. Appending Item ID to ensure uniqueness: %s', $product_data['sku'] ), 'warning' );
				}
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

		// Weight & Dimensions.
		$overwrite_weight_dims = isset( $settings['overwrite_weight_dims'] ) ? $settings['overwrite_weight_dims'] : '1';
		if ( $is_new || '1' === $overwrite_weight_dims ) {
			$has_weight_dims = false;
			if ( '' !== $product_data['weight'] ) {
				$product->set_weight( $product_data['weight'] );
				$has_weight_dims = true;
			}
			if ( '' !== $product_data['length'] ) {
				$product->set_length( $product_data['length'] );
				$has_weight_dims = true;
			}
			if ( '' !== $product_data['width'] ) {
				$product->set_width( $product_data['width'] );
				$has_weight_dims = true;
			}
			if ( '' !== $product_data['height'] ) {
				$product->set_height( $product_data['height'] );
				$has_weight_dims = true;
			}
			if ( $has_weight_dims && ! $is_new ) {
				$sync_decisions[] = 'Weight/Dimensions updated (eBay won)';
			}
		} elseif ( ! $is_new ) {
			$sync_decisions[] = 'Weight/Dimensions skipped (WooCommerce won)';
		}
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
		$attr_position = 0;
		
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
			$attribute->set_id( 0 ); // 0 = custom (non-taxonomy) attribute
			$attribute->set_name( $name );
			$attribute->set_options( array( $value ) );
			$attribute->set_position( $attr_position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$woo_attributes[ sanitize_title( $name ) ] = $attribute;
		}

		// Map variation specifics
		foreach ( $parent_attr_values as $name => $values ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 ); // 0 = custom (non-taxonomy) attribute
			$attribute->set_name( $name );
			$attribute->set_options( array_unique( $values ) );
			$attribute->set_position( $attr_position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$woo_attributes[ sanitize_title( $name ) ] = $attribute;
		}
		
		$product->set_attributes( array_values( $woo_attributes ) );

		// Set Custom Meta (including _ebay_item_id for tracking).
		foreach ( $product_data['meta'] as $key => $val ) {
			$product->update_meta_data( $key, $val );
		}

		// Save Bin Location if configured.
		if ( ! empty( $product_data['bin_location'] ) ) {
			$product->update_meta_data( '_bin_location', $product_data['bin_location'] );
			if ( $is_new ) {
				$sync_decisions[] = 'Bin Location: ' . $product_data['bin_location'];
			}
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

		// Everything below writes stock that came FROM eBay. Suppress the
		// WC → eBay push listeners for the duration, otherwise every imported
		// product queues a redundant ReviseInventoryStatus call — and anything
		// mapped to 0 stock triggers auto-end, permanently ending the seller's
		// live eBay listing as a side effect of importing it.
		TCGiant_Sync_Inventory::begin_ebay_origin();

		try {
			$saved_id = $product->save();

			if ( $is_variable && $saved_id ) {
				$this->save_variations( $saved_id, $product_data['variations'] );
			}
		} finally {
			TCGiant_Sync_Inventory::end_ebay_origin();
		}

		// When a brand new product is created and it doesn't have images yet,
		// check if a trashed/orphaned product has the same _ebay_sku and migrate
		// its images. This handles the case where sellers relist with new Item
		// IDs and SKUs — the old product gets trashed but its images survive.
		if ( $is_new && $saved_id && ! empty( $product_data['meta']['_ebay_sku'] ) ) {
			$this->migrate_images_from_orphan( $saved_id, $product_data['meta']['_ebay_sku'] );
		}

		return $saved_id;
	}

	/**
	 * Save child variations for a variable product.
	 *
	 * @param int $parent_id WooCommerce Parent Product ID.
	 * @param array $variations_data Array of variation data from map_ebay_to_woo (by reference).
	 */
	private function save_variations( $parent_id, &$variations_data ) {
		$product = wc_get_product( $parent_id );
		$existing_variations = $product->get_children();
		$processed_ids = array();
		
		foreach ( $variations_data as &$var_data ) {
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
				$var_data['variation_id'] = $saved_var_id;
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
	 * Migrate images from an orphaned/trashed product to a new product.
	 *
	 * When an eBay seller relists with a new Item ID (and possibly new SKU),
	 * the old WooCommerce product gets trashed by the orphan pruner. This
	 * method finds that trashed product by matching the _ebay_sku meta, then
	 * reassigns its thumbnail and gallery images to the new product — avoiding
	 * a full re-download of all images.
	 *
	 * @param int    $new_product_id The newly created WooCommerce product ID.
	 * @param string $ebay_sku       The eBay SKU (_ebay_sku meta value).
	 */
	private function migrate_images_from_orphan( $new_product_id, $ebay_sku ) {
		global $wpdb;

		// Only ever harvest images from TRASHED products.
		//
		// Without the post_status filter this also matched live products. Two
		// active eBay listings can legitimately share one seller SKU (and
		// therefore one _ebay_sku value, which is recorded before the
		// "-{item_id}" uniqueness suffix is applied to the WooCommerce SKU),
		// so importing the second listing would strip the thumbnail and
		// gallery off the first one's live product.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$donor_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_ebay_sku' AND pm.meta_value = %s
			   AND pm.post_id != %d
			   AND p.post_type = 'product'
			   AND p.post_status = 'trash'
			 LIMIT 5",
			$ebay_sku,
			$new_product_id
		) );

		if ( empty( $donor_ids ) ) {
			return;
		}

		foreach ( $donor_ids as $donor_id ) {
			$donor_id = (int) $donor_id;

			// Check if the donor has a real (non-external) thumbnail.
			$thumb_id = get_post_thumbnail_id( $donor_id );
			if ( ! $thumb_id ) {
				continue;
			}

			// Skip if it's an external placeholder — not useful.
			$is_external = get_post_meta( $thumb_id, '_tcgiant_external_url', true );
			if ( ! empty( $is_external ) ) {
				continue;
			}

			// Migrate thumbnail: re-parent the attachment to the new product.
			wp_update_post( array( 'ID' => $thumb_id, 'post_parent' => $new_product_id ) );
			set_post_thumbnail( $new_product_id, $thumb_id );

			// Migrate gallery images.
			$gallery = get_post_meta( $donor_id, '_product_image_gallery', true );
			if ( ! empty( $gallery ) ) {
				$gallery_ids = array_filter( explode( ',', $gallery ) );
				foreach ( $gallery_ids as $gid ) {
					$gid = (int) $gid;
					$is_ext = get_post_meta( $gid, '_tcgiant_external_url', true );
					if ( empty( $is_ext ) ) {
						wp_update_post( array( 'ID' => $gid, 'post_parent' => $new_product_id ) );
					}
				}
				update_post_meta( $new_product_id, '_product_image_gallery', $gallery );
				delete_post_meta( $donor_id, '_product_image_gallery' );
			}

			// Copy localization hash so the localizer knows images are already done.
			$hash = get_post_meta( $donor_id, '_tcgiant_image_urls_hash', true );
			if ( $hash ) {
				update_post_meta( $new_product_id, '_tcgiant_image_urls_hash', $hash );
				update_post_meta( $new_product_id, '_tcgiant_images_localized', 1 );
			}

			// Clear the donor's thumbnail so it doesn't interfere.
			delete_post_thumbnail( $donor_id );

			TCGiant_Sync_Logger::log( sprintf(
				'Migrated images from trashed WC #%d to new WC #%d (SKU: %s).',
				$donor_id, $new_product_id, $ebay_sku
			) );

			break; // Only migrate from the first match.
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

	/**
	 * Parse a measurement value from eBay's XML→JSON format.
	 *
	 * eBay can return measurements as:
	 * - A plain string/number: "5"
	 * - An array: {"@attributes": {"unit": "lbs", "measurementSystem": "English"}, "#text": "5"}
	 *
	 * @param mixed $raw Raw measurement value.
	 * @return string Numeric string or empty string.
	 */
	private function parse_measurement_value( $raw ) {
		if ( null === $raw ) {
			return '';
		}
		if ( is_string( $raw ) || is_numeric( $raw ) ) {
			return (string) $raw;
		}
		if ( is_array( $raw ) ) {
			if ( isset( $raw['#text'] ) ) {
				return (string) $raw['#text'];
			}
			if ( isset( $raw['__text'] ) ) {
				return (string) $raw['__text'];
			}
			if ( isset( $raw['value'] ) ) {
				return (string) $raw['value'];
			}
		}
		return '';
	}

	/**
	 * Parse the unit string from an eBay measurement field.
	 *
	 * When eBay's XML→JSON conversion strips @attributes (common with UK/metric sites),
	 * falls back to the configured marketplace to determine the unit system.
	 *
	 * @param mixed  $raw  Raw measurement value (may contain @attributes with unit).
	 * @param string $type Context: 'weight' or 'dimension'.
	 * @return string Unit string (e.g., 'lbs', 'kg', 'in', 'cm').
	 */
	private function parse_measurement_unit( $raw, $type = 'weight' ) {
		if ( is_array( $raw ) && isset( $raw['@attributes']['unit'] ) ) {
			return (string) $raw['@attributes']['unit'];
		}
		if ( is_array( $raw ) && isset( $raw['@attributes']['measurementSystem'] ) ) {
			return 'English' === $raw['@attributes']['measurementSystem'] ? 'lbs' : 'kg';
		}
		// Fallback: use configured marketplace to determine unit system.
		// US uses imperial (lbs/in), all other supported marketplaces use metric (kg/cm).
		return $this->get_marketplace_default_unit( $type );
	}

	/**
	 * Get the default measurement unit for the configured eBay marketplace.
	 *
	 * US = imperial (lbs/in), all others (UK, CA, AU, DE, FR, IT, ES) = metric (kg/cm).
	 *
	 * @param string $type 'weight' or 'dimension'.
	 * @return string Default unit for the marketplace.
	 */
	private function get_marketplace_default_unit( $type = 'weight' ) {
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$marketplace = ! empty( $settings['marketplace'] ) ? $settings['marketplace'] : 'EBAY_US';

		// Only EBAY_US uses imperial. All other supported marketplaces use metric.
		$is_imperial = ( 'EBAY_US' === $marketplace );

		if ( 'weight' === $type ) {
			return $is_imperial ? 'lbs' : 'kg';
		}
		return $is_imperial ? 'in' : 'cm';
	}

	/**
	 * Convert a weight value to the WooCommerce store's configured weight unit.
	 *
	 * @param float  $value     The weight value.
	 * @param string $from_unit Source unit ('lbs' or 'kg').
	 * @return string Formatted weight for WooCommerce.
	 */
	private function convert_weight_to_store_unit( $value, $from_unit ) {
		if ( $value <= 0 ) {
			return '';
		}

		$store_unit = get_option( 'woocommerce_weight_unit', 'lbs' );

		// Convert to store unit if different.
		if ( 'lbs' === $from_unit && 'kg' === $store_unit ) {
			$value = $value * 0.453592;
		} elseif ( 'lbs' === $from_unit && 'oz' === $store_unit ) {
			$value = $value * 16;
		} elseif ( 'lbs' === $from_unit && 'g' === $store_unit ) {
			$value = $value * 453.592;
		} elseif ( 'kg' === $from_unit && 'lbs' === $store_unit ) {
			$value = $value * 2.20462;
		} elseif ( 'kg' === $from_unit && 'oz' === $store_unit ) {
			$value = $value * 35.274;
		} elseif ( 'kg' === $from_unit && 'g' === $store_unit ) {
			$value = $value * 1000;
		}

		return wc_format_decimal( $value, 2 );
	}

	/**
	 * Convert a dimension value to the WooCommerce store's configured dimension unit.
	 *
	 * @param float  $value     The dimension value.
	 * @param string $from_unit Source unit ('in', 'cm', etc.).
	 * @return string Formatted dimension for WooCommerce.
	 */
	private function convert_dimension_to_store_unit( $value, $from_unit ) {
		if ( $value <= 0 ) {
			return '';
		}

		$store_unit = get_option( 'woocommerce_dimension_unit', 'in' );
		$from_unit  = strtolower( $from_unit );

		// Normalize eBay unit names.
		if ( 'inches' === $from_unit ) $from_unit = 'in';
		if ( empty( $from_unit ) ) $from_unit = $this->get_marketplace_default_unit( 'dimension' );

		if ( 'in' === $from_unit && 'cm' === $store_unit ) {
			$value = $value * 2.54;
		} elseif ( 'in' === $from_unit && 'mm' === $store_unit ) {
			$value = $value * 25.4;
		} elseif ( 'in' === $from_unit && 'm' === $store_unit ) {
			$value = $value * 0.0254;
		} elseif ( 'cm' === $from_unit && 'in' === $store_unit ) {
			$value = $value / 2.54;
		} elseif ( 'cm' === $from_unit && 'mm' === $store_unit ) {
			$value = $value * 10;
		} elseif ( 'cm' === $from_unit && 'm' === $store_unit ) {
			$value = $value / 100;
		}

		return wc_format_decimal( $value, 2 );
	}
}
