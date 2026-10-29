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
	 * Whether the last save_as_product() call skipped on purpose.
	 *
	 * A deliberate skip is not a failure, and counting it as one would inflate
	 * the error total shown on the dashboard.
	 *
	 * @var bool
	 */
	public static $last_skipped = false;

	/**
	 * Why the last save_as_product() call saved nothing for now, when the
	 * answer it needed from eBay could not be had - out of calls, or no token
	 * to ask with. The caller tries the listing again later, as for any rate
	 * limit, rather than counting it as an error. Null otherwise.
	 *
	 * @var WP_Error|null
	 */
	public static $last_deferred = null;

	/**
	 * Why eBay could not be asked how a relist's old listing ended, the last
	 * time relist_parent_product() needed to know - or null. Read by the delta
	 * sync, which pauses on a passing failure rather than pass the relist by.
	 *
	 * @var WP_Error|null
	 */
	public $relist_parent_error = null;

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * How the listing a relist came from ended - listed and sold - as the last
	 * call to relist_parent_product() found it, for the stock warning that
	 * follows. Null when it found nothing to adopt.
	 *
	 * @var array|null
	 */
	private $relist_parent_figures = null;

	/**
	 * Whether each old listing asked about while importing one listing was
	 * over, by item number - so the SKU match does not ask eBay a second time.
	 *
	 * @var bool[]
	 */
	private $parent_over = array();

	/**
	 * Set while adopt_relist() runs: the relist is not being imported then, and
	 * the log must not say it is.
	 *
	 * @var bool
	 */
	private $adopting = false;

	/**
	 * Set while save_as_product() looks at a relist that, unless a product
	 * takes it on, is an auction left out of the import: the log must not
	 * say it is imported.
	 *
	 * @var bool
	 */
	private $auction_left_out = false;

	/**
	 * Whether the last refusal in relist_parent_product() came from eBay's
	 * answer or the SKU - which asking again would not change - rather than
	 * from something that may be different next time.
	 *
	 * @var bool
	 */
	private $relist_decline_final = false;

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

		// A listing TCGiant sent with a template: only its content region, so
		// that a re-import never wraps one template inside another. Anything
		// else - a listing that never had one, whatever classes its own
		// description uses - eBay's description exactly as it came.
		if ( is_string( $product_data['description'] ) && class_exists( 'TCGiant_Sync_Templates' ) ) {
			$product_data['description'] = TCGiant_Sync_Templates::content_region( $product_data['description'], $this->sent_with_template( (string) $item_id, $product_data['description'] ) );
		}
		$product_data['sku'] = $ebay_item['SKU'] ?? '';

		// Where the SKU came from decides how much it can be trusted to identify
		// a listing. The seller's own SKU is theirs to keep unique. A barcode is
		// not: one manufacturer part number covers every listing of that part,
		// from this seller and everyone else. The eBay item number is unique but
		// belongs to one listing only, so it can never legitimately match a
		// different one.
		$product_data['sku_source'] = ! empty( $product_data['sku'] ) ? 'seller' : 'none';

		// Check if eBay SKU should be routed to a bin location instead of WooCommerce SKU.
		$product_data['bin_location'] = '';
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$sku_maps_to = $settings['sku_maps_to'] ?? 'sku';
		
		if ( 'bin_location' === $sku_maps_to && ! empty( $product_data['sku'] ) ) {
			$product_data['bin_location'] = $product_data['sku'];
			$product_data['sku'] = ''; // Clear so it falls through to ISBN/UPC/EAN or EBAY-{ItemID}
			$product_data['sku_source'] = 'none';
		} elseif ( 'both' === $sku_maps_to && ! empty( $product_data['sku'] ) ) {
			$product_data['bin_location'] = $product_data['sku'];
			// Do not clear SKU so it gets assigned to both WooCommerce SKU and Bin Location.
		}

		// Fallback 1: Try Product Identifiers (ISBN/UPC/EAN) if SKU is missing
		if ( empty( $product_data['sku'] ) && isset( $ebay_item['ProductListingDetails'] ) ) {
			$product_data['sku'] = $ebay_item['ProductListingDetails']['ISBN'] ?? 
								   $ebay_item['ProductListingDetails']['UPC'] ?? 
								   $ebay_item['ProductListingDetails']['EAN'] ?? '';
			
			// Sellers put all sorts of stand-ins in the barcode fields. Accepting one
			// as a SKU gives every listing that used it the same SKU, and they then
			// collide with each other for ever after - "N/A-187465771950" in the log
			// is one of them being made unique the hard way.
			if ( self::is_placeholder_identifier( $product_data['sku'] ) ) {
				$product_data['sku'] = '';
			} elseif ( ! empty( $product_data['sku'] ) ) {
				$product_data['sku_source'] = 'barcode';
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
					if ( ! self::is_placeholder_identifier( $val ) ) {
						$product_data['sku'] = $val;
						$product_data['sku_source'] = 'barcode';
						break;
					}
				}
			}
		}

		// Every SKU and barcode the listing carries, whichever one became its
		// SKU above - a relist that gains a SKU of the seller's own can keep
		// the barcode its product was imported under. See
		// relist_parent_product().
		$identifiers = array();
		if ( isset( $ebay_item['SKU'] ) && is_scalar( $ebay_item['SKU'] ) ) {
			$identifiers[] = (string) $ebay_item['SKU'];
		}
		foreach ( array( 'ISBN', 'UPC', 'EAN' ) as $code ) {
			$value = $ebay_item['ProductListingDetails'][ $code ] ?? '';
			if ( is_scalar( $value ) && ! self::is_placeholder_identifier( $value ) ) {
				$identifiers[] = (string) $value;
			}
		}
		if ( isset( $ebay_item['ItemSpecifics']['NameValueList'] ) && is_array( $ebay_item['ItemSpecifics']['NameValueList'] ) ) {
			$specs = $ebay_item['ItemSpecifics']['NameValueList'];
			if ( isset( $specs['Name'] ) ) {
				$specs = array( $specs );
			}
			foreach ( $specs as $spec ) {
				if ( is_array( $spec ) && in_array( strtolower( (string) ( $spec['Name'] ?? '' ) ), array( 'isbn', 'upc', 'ean' ), true ) ) {
					$value = is_array( $spec['Value'] ?? null ) ? reset( $spec['Value'] ) : ( $spec['Value'] ?? '' );
					if ( is_scalar( $value ) && ! self::is_placeholder_identifier( $value ) ) {
						$identifiers[] = (string) $value;
					}
				}
			}
		}
		$product_data['identifiers'] = array_values( array_filter( array_map( 'trim', $identifiers ), 'strlen' ) );

		// Fallback 3: use ItemID as SKU if no other identifier exists.
		// The sku_prefix setting controls whether to add "EBAY-" prefix (default: no prefix).
		if ( empty( $product_data['sku'] ) && ! empty( $item_id ) ) {
			$sku_prefix = ! empty( $settings['sku_prefix'] ) ? $settings['sku_prefix'] : 'none';
			$product_data['sku'] = ( 'ebay' === $sku_prefix ) ? 'EBAY-' . $item_id : $item_id;
			$product_data['sku_source'] = 'item_id';
		}

		// Map Quantity (available = total - sold).
		$quantity = isset( $ebay_item['Quantity'] ) ? (int) $ebay_item['Quantity'] : 0;
		$sold = isset( $ebay_item['SellingStatus']['QuantitySold'] ) ? (int) $ebay_item['SellingStatus']['QuantitySold'] : 0;
		$product_data['stock_quantity'] = max( 0, $quantity - $sold );
		$product_data['manage_stock'] = true;

		// eBay's sales so far, recorded once its figure is written: the stock
		// push takes off only the eBay sales since (3.28.0). Null when eBay did
		// not say.
		$sold_raw                       = TCGiant_Sync_API::scalar_value( $ebay_item['SellingStatus']['QuantitySold'] ?? '' );
		$product_data['quantity_sold']  = is_numeric( $sold_raw ) ? (int) $sold_raw : null;

		// Map Price - handle all eBay XML->JSON price structures.
		//
		// An auction's price is its opening bid, never the current bid. The
		// current bid moves with every bid, and eBay reports each bid as a
		// change, so the website price used to climb with the auction - and a
		// website buyer could take the item at whatever it had reached. The
		// opening bid is also what a push sends back as StartPrice, so an Update
		// of an imported auction no longer tries to move its opening bid.
		$is_auction = self::is_auction_item( $ebay_item );
		$prices     = $this->extract_prices( $ebay_item, $is_auction );
		$product_data['price'] = $prices['regular_price']; // fallback for other usages
		$product_data['regular_price'] = $prices['regular_price'];
		$product_data['sale_price']    = $prices['sale_price'];

		// eBay's list of changes, and possibly a full scan's page, carry an
		// auction's current bid but not its opening bid. A product that already
		// has a price then keeps it - see save_as_product() - rather than taking
		// the bid for the price.
		$product_data['auction_price_is_bid'] = $is_auction && '' === $this->auction_start_price( $ebay_item );

		// Map Store Categories.
		// eBay sends "0" for "no store category"; empty() already rejects that,
		// so no separate comparison is needed.
		$product_data['store_categories'] = array();
		if ( ! empty( $ebay_item['Storefront']['StoreCategoryID'] ) ) {
			$product_data['store_categories'][] = $ebay_item['Storefront']['StoreCategoryID'];
		}
		if ( ! empty( $ebay_item['Storefront']['StoreCategory2ID'] ) ) {
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

			// Which system the figures below are in, decided from the listing
			// itself. See item_measurement_system() for why this cannot be left
			// to a setting.
			$system = $this->item_measurement_system( $ebay_item );

			// Weight: eBay returns WeightMajor (lbs/kg) + WeightMinor (oz/g).
			// WooCommerce uses a single weight field in the store's configured unit.
			$weight_major = $this->parse_measurement_value( $pkg['WeightMajor'] ?? null );
			$weight_minor = $this->parse_measurement_value( $pkg['WeightMinor'] ?? null );

			if ( '' !== $weight_major || '' !== $weight_minor ) {
				$major = (float) $weight_major;
				$minor = (float) $weight_minor;

				// A unit attribute on the value wins when eBay sends one. It usually
				// does not survive the XML-to-array conversion, which is when the
				// listing's own site decides. A shop in the United Kingdom had a
				// 0.6 kg oil filter arrive as 17.01 kg because this fell through to
				// a marketplace setting nobody had told it about: 600 grams read as
				// 600 ounces.
				$major_unit = $this->parse_measurement_unit( $pkg['WeightMajor'] ?? null, 'weight', $system );
				$is_metric  = in_array( strtolower( $major_unit ), array( 'kg', 'g' ), true );

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

			$dim_unit = $this->parse_measurement_unit( $pkg['PackageLength'] ?? $pkg['PackageWidth'] ?? null, 'dimension', $system );

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
			'_ebay_start_time'       => $ebay_item['ListingDetails']['StartTime'] ?? '',
			'_ebay_end_time'         => $ebay_item['ListingDetails']['EndTime'] ?? '',
			'_ebay_listing_status'   => $ebay_item['SellingStatus']['ListingStatus'] ?? 'Active',
		);

		// Which listing that type was read for: a type some older code wrote
		// as a default is not taken for eBay's (3.28.0).
		if ( '' !== (string) $product_data['meta']['_ebay_listing_type'] ) {
			$product_data['meta']['_ebay_listing_type_item'] = (string) $item_id;
		}

		// What the website shows for a running auction: its opening bid, the
		// bid it has reached, how many bids, and Buy It Now while eBay still
		// offers it. Auctions only, and only what eBay sent - a large fixed-price
		// shop gains no rows, and an answer without a figure does not blank one
		// recorded earlier.
		if ( $is_auction ) {
			$product_data['meta'] = array_merge( $product_data['meta'], $this->auction_meta( $ebay_item ) );
		}

		// The listing this one was relisted from, when it is a relist. eBay puts
		// it on the new listing, so save_as_product() can hand the new number to
		// the product that sold the old one instead of making a second product.
		$relist_parent = self::listing_number( $ebay_item['RelistParentID'] ?? '' );
		if ( $relist_parent === (string) $item_id ) {
			$relist_parent = '';
		}
		$product_data['relist_parent_id'] = $relist_parent;

		// Kept on the product too, so a duplicate made by an earlier version -
		// the old product beside the copy that follows the relist - can be told
		// apart later. Only when there is one: most listings are not relists,
		// and a large shop should not gain an empty row per product.
		if ( '' !== $relist_parent ) {
			$product_data['meta']['_ebay_relist_parent_id'] = $relist_parent;
		}

		// The eBay site the listing is on, which "Import listings from" decides
		// by (TCGiant_Sync_Sites) and Stock Review finds other sites' products by.
		// Recorded only when eBay said: an answer without it must not wipe the
		// site an earlier answer recorded.
		$product_data['site'] = TCGiant_Sync_Sites::site_of( $ebay_item );
		TCGiant_Sync_Sites::note( $product_data['site'] );
		if ( '' !== $product_data['site'] ) {
			$product_data['meta']['_ebay_site'] = $product_data['site'];
		}

		// The listing's shipping, from what eBay sent: its policy, and its
		// services and costs. On the product itself - the parent of a variable
		// one - for a shop's own code to read. Nothing here asks eBay anything.
		$product_data['meta_delete'] = array();
		$this->add_shipping_meta( $ebay_item, $product_data );
		$this->add_policy_meta( $ebay_item, $product_data );

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
					'sold'           => isset( $var['SellingStatus']['QuantitySold'] ) && is_numeric( $var['SellingStatus']['QuantitySold'] ) ? (int) $var['SellingStatus']['QuantitySold'] : null,
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
	 * Extract clean numeric prices from eBay's various response formats.
	 *
	 * eBay Trading API XML->JSON can return prices as:
	 * - A plain string: "29.99"
	 * - An array: {"@attributes": {"currencyID": "USD"}, "#text": "29.99"}
	 * - An array with underscore: {"__text": "29.99"}
	 * - An array with SimpleXML's numeric text key: {"0": "29.99"}
	 * - Just a numeric value
	 *
	 * @param array $ebay_item The full eBay item array.
	 * @param bool  $auction   Whether the listing is an auction. Its price is
	 *                         then the opening bid (StartPrice), with the
	 *                         current bid only when eBay did not send that, and
	 *                         never a sale price.
	 * @return array{regular_price: string, sale_price: string} Clean numeric
	 *               strings, each empty when the value is absent.
	 */
	private function extract_prices( $ebay_item, $auction = false ) {
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

		// Not Buy It Now either: eBay requires it to be at least 30% above the
		// opening bid, and a push sends the price back as StartPrice, so an
		// Update would then move the opening bid or be refused.
		if ( $auction ) {
			$price_candidates = array(
				$ebay_item['StartPrice'] ?? null,
				$ebay_item['SellingStatus']['CurrentPrice'] ?? null,
				$ebay_item['SellingStatus']['ConvertedCurrentPrice'] ?? null,
			);
		}

		foreach ( $price_candidates as $raw ) {
			if ( null !== $raw ) {
				$val = $this->parse_price_value( $raw );
				if ( '' !== $val && (float) $val > 0 ) {
					$current_price = $val;
					break;
				}
			}
		}

		// Original Retail Price. An auction has no markdown, so never a sale.
		$original_price = '';
		if ( ! $auction && isset( $ebay_item['DiscountPriceInfo']['OriginalRetailPrice'] ) ) {
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
		// Shared with the order importer so both paths handle every shape the
		// XML→JSON conversion can produce, including SimpleXML's numeric text key.
		return TCGiant_Sync_API::scalar_value( $raw );
	}

	// ───────────────────────────────────────────────────────────────────────────
	// Auctions
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Option recording the one-time "Import eBay auctions" decision at upgrade:
	 * 'on' when it was turned on and the notice is still to be read,
	 * 'dismissed' once it has been, 'off' when there was nothing to turn on.
	 */
	const AUCTION_NOTICE_OPTION = 'tcgiant_auction_import_notice';

	/**
	 * Whether an eBay listing is an auction.
	 *
	 * 'Chinese' is eBay's name for an auction, and the only auction format a
	 * seller can create.
	 *
	 * @param array $ebay_item The listing as eBay sent it.
	 * @return bool
	 */
	public static function is_auction_item( $ebay_item ) {
		return is_array( $ebay_item ) && 'Chinese' === TCGiant_Sync_API::scalar_value( $ebay_item['ListingType'] ?? '' );
	}

	/**
	 * An auction's opening bid, or '' when eBay did not send it.
	 *
	 * @param array $ebay_item The listing as eBay sent it.
	 * @return string
	 */
	private function auction_start_price( $ebay_item ) {
		$start = $this->parse_price_value( $ebay_item['StartPrice'] ?? '' );

		return ( '' !== $start && is_numeric( $start ) && (float) $start > 0 ) ? $start : '';
	}

	/**
	 * The auction figures kept on the product, each only when eBay sent it.
	 *
	 * @param array $ebay_item The listing as eBay sent it.
	 * @return array Meta key => value.
	 */
	private function auction_meta( $ebay_item ) {
		$meta = array();

		$start = $this->auction_start_price( $ebay_item );
		if ( '' !== $start ) {
			$meta['_ebay_start_price'] = $start;
		}

		$current = $this->parse_price_value( $ebay_item['SellingStatus']['CurrentPrice'] ?? '' );
		if ( '' !== $current && is_numeric( $current ) ) {
			$meta['_ebay_current_price'] = $current;
		}

		$bids = TCGiant_Sync_API::scalar_value( $ebay_item['SellingStatus']['BidCount'] ?? '' );
		if ( '' !== $bids && is_numeric( $bids ) ) {
			$meta['_ebay_bid_count'] = (string) (int) $bids;
		}

		// eBay can go on reporting a Buy It Now price after the option has gone
		// - the first bid takes it away when there is no reserve - and says
		// whether it is still there in BuyItNowAvailable.
		if ( array_key_exists( 'BuyItNowPrice', $ebay_item ) ) {
			$bin       = $this->parse_price_value( $ebay_item['BuyItNowPrice'] );
			$available = strtolower( TCGiant_Sync_API::scalar_value( $ebay_item['ListingDetails']['BuyItNowAvailable'] ?? '' ) );

			// Only GetItem says so. eBay's list of changes and the full scan
			// carry the price but not the answer, so there a bid means Buy It
			// Now has gone - unless eBay says the reserve is not yet met. The
			// website then never offers what eBay no longer does.
			if ( '' === $available && (int) ( $meta['_ebay_bid_count'] ?? 0 ) > 0 ) {
				$reserve_met = strtolower( TCGiant_Sync_API::scalar_value( $ebay_item['SellingStatus']['ReserveMet'] ?? '' ) );
				$available   = 'false' === $reserve_met ? 'true' : 'false';
			}

			$meta['_ebay_buy_it_now_price'] = ( is_numeric( $bin ) && (float) $bin > 0 && 'false' !== $available ) ? $bin : '';
		}

		return $meta;
	}

	/**
	 * Whether new eBay auctions become products.
	 *
	 * Off unless the shop turns it on (Settings, Import), or the one-time step
	 * at upgrade turned it on because the shop already had imported auctions.
	 * That step runs here too, the first time an auction is met, so a sync that
	 * runs before anyone opens the admin follows the same decision.
	 *
	 * @return bool
	 */
	public static function imports_auctions() {
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();

		if ( ! is_array( $settings ) || ! array_key_exists( 'import_auctions', $settings ) ) {
			self::upgrade_auction_import();
			$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		}

		// The upgrade turned it on, but another request that had read the
		// settings before then wrote them back without it. The shop never
		// chose otherwise - an explicit '0' is kept - so it is still on.
		if ( ( ! is_array( $settings ) || ! array_key_exists( 'import_auctions', $settings ) )
			&& in_array( (string) get_option( self::AUCTION_NOTICE_OPTION, '' ), array( 'on', 'dismissed' ), true ) ) {
			return true;
		}

		return is_array( $settings ) && '1' === (string) ( $settings['import_auctions'] ?? '0' );
	}

	/**
	 * Once per site, on the first version with "Import eBay auctions": turn it
	 * on for a shop that already has products imported from eBay auctions, so
	 * its auctions keep arriving. Everyone else starts with it off.
	 *
	 * Runs on admin_init and the hourly ended-listing check (so a site nobody
	 * opens the admin of still decides), and from imports_auctions().
	 *
	 * @return void
	 */
	public static function upgrade_auction_import() {
		// Its own once: a site that already settled the setting under an
		// earlier build of this release still needs its auctions marked.
		if ( '' === (string) get_option( 'tcgiant_auction_listed_marked', '' ) ) {
			self::mark_listed_auctions();
			update_option( 'tcgiant_auction_listed_marked', '1' );
		}

		if ( '' !== (string) get_option( self::AUCTION_NOTICE_OPTION, '' ) ) {
			return;
		}

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		// Already chosen, by the shop or by an earlier run.
		if ( array_key_exists( 'import_auctions', $settings ) ) {
			update_option( self::AUCTION_NOTICE_OPTION, 'off' );
			return;
		}

		$imported = self::count_imported_auctions();

		if ( $imported < 1 ) {
			update_option( self::AUCTION_NOTICE_OPTION, 'off' );
			return;
		}

		// Only its own key, onto the settings as the database holds them now.
		TCGiant_Sync_Inventory::save_settings_keys( array( 'import_auctions' => '1' ) );
		update_option( self::AUCTION_NOTICE_OPTION, 'on' );

		TCGiant_Sync_Logger::log( sprintf(
			'This shop has %d product(s) imported from eBay auctions, so "Import eBay auctions" has been turned on and new auctions keep arriving. While an auction runs, its product shows the opening bid and the current bid, links to eBay, and cannot be bought on your website. A product imported from an auction stays off sale on your website after the auction ends unsold, as its price is the opening bid on eBay; to sell it there, first set your own price, then push it as Fixed Price or unlink it. Turn it off under TCGiant Sync, Settings, Import Settings to stop importing auctions.',
			$imported
		), 'warning' );
	}

	/**
	 * Once, at upgrade: note the auctions the shop listed from WooCommerce
	 * before 3.28.0 recorded which ones they are. The data cannot tell a
	 * pushed auction from an imported one the shop later pushed an update
	 * to, so every auction product that was ever pushed counts as listed
	 * from WooCommerce - as everything did before.
	 *
	 * @return void
	 */
	private static function mark_listed_auctions() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT p.ID AS id, li.meta_value AS item FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} lt ON lt.post_id = p.ID AND lt.meta_key = '_ebay_listing_type' AND lt.meta_value = 'Chinese'
			 INNER JOIN {$wpdb->postmeta} li ON li.post_id = p.ID AND li.meta_key = '_ebay_item_id' AND li.meta_value <> ''
			 INNER JOIN {$wpdb->postmeta} lp ON lp.post_id = p.ID AND lp.meta_key = '_ebay_export_last_pushed' AND lp.meta_value <> ''
			 LEFT JOIN {$wpdb->postmeta} la ON la.post_id = p.ID AND la.meta_key = '_ebay_auction_listed_item'
			 WHERE p.post_type = 'product'
			   AND la.meta_id IS NULL",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) && '' !== (string) ( $row['item'] ?? '' ) ) {
				update_post_meta( (int) $row['id'], TCGiant_Sync_Inventory::AUCTION_LISTED_KEY, (string) $row['item'] );
			}
		}
	}

	/**
	 * Products imported from eBay auctions: linked to an auction, never pushed
	 * from WooCommerce, not in the trash.
	 *
	 * @return int
	 */
	private static function count_imported_auctions() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} lt ON lt.post_id = p.ID AND lt.meta_key = '_ebay_listing_type' AND lt.meta_value = 'Chinese'
			 INNER JOIN {$wpdb->postmeta} li ON li.post_id = p.ID AND li.meta_key = '_ebay_item_id' AND li.meta_value <> ''
			 LEFT JOIN {$wpdb->postmeta} lp ON lp.post_id = p.ID AND lp.meta_key = '_ebay_export_last_pushed'
			 WHERE p.post_type = 'product'
			   AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			   AND lp.meta_id IS NULL"
		);
	}

	/**
	 * Leave an eBay auction out of the import, saying so once per listing.
	 *
	 * eBay reports every bid as a change to the listing, so without the once
	 * a busy auction would write the same line on every sync.
	 *
	 * @param string $item_id    The auction.
	 * @param string $title      Its title.
	 * @param int    $left_alone A product with the same SKU, not linked to
	 *                           eBay, that was not taken over; 0 for none.
	 * @return false
	 */
	private function skip_auction( $item_id, $title, $left_alone ) {
		self::$last_skipped = true;

		$once = 'tcgiant_auction_skip_' . $item_id;
		if ( get_transient( $once ) ) {
			return false;
		}
		set_transient( $once, 1, 10 * DAY_IN_SECONDS );

		if ( $left_alone ) {
			TCGiant_Sync_Logger::log( sprintf(
				'eBay auction %1$s has the same SKU as WC #%2$d, which is not linked to eBay. Import eBay auctions is off, so WC #%2$d was left alone: its stock and price are unchanged. Link it from the product\'s eBay Listing tab if it is the same item.',
				$item_id,
				(int) $left_alone
			) );
			return false;
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Skipped eBay auction %s ("%s"): Import eBay auctions is off (Settings, Import Settings). Auctions already linked to a product keep syncing.',
			$item_id,
			$title
		) );

		return false;
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
		// The first in the order eBay sent them, as it always was here.
		$options = $this->shipping_service_list( $ebay_item['ShippingDetails']['ShippingServiceOptions'] ?? null );
		$first   = $options[0] ?? null;

		if ( empty( $first ) || ! isset( $first['ShippingServiceCost'] ) ) {
			return 0.0;
		}

		$cost = $this->parse_price_value( $first['ShippingServiceCost'] );
		return ( '' !== $cost ) ? (float) $cost : 0.0;
	}

	/**
	 * A listing's shipping services, as a list, however many it has.
	 *
	 * eBay's XML, turned into an array, gives one service as the service itself
	 * (its keys are ShippingService, ShippingServiceCost...) and several as a
	 * list of them.
	 *
	 * @param mixed $options ShippingServiceOptions or InternationalShippingServiceOption.
	 * @return array[]
	 */
	private function shipping_service_list( $options ) {
		if ( empty( $options ) || ! is_array( $options ) ) {
			return array();
		}

		// Single shipping service — $options is the service itself.
		if ( isset( $options['ShippingService'] ) ) {
			return array( $options );
		}

		// Multiple services.
		return array_values( array_filter( $options, 'is_array' ) );
	}

	/**
	 * The product meta that holds the listing's shipping, as eBay has it.
	 *
	 * For a shop's own code: one shop works out its checkout's shipping from
	 * its eBay Business Policies. Until now the sync kept only the first
	 * domestic cost, and only to add it to the price.
	 */
	const SHIPPING_META_KEYS = array(
		'_ebay_shipping_policy_id',
		'_ebay_shipping_policy_name',
		'_ebay_shipping_type',
		'_ebay_shipping_service',
		'_ebay_shipping_cost',
		'_ebay_shipping_currency',
		'_ebay_shipping_services',
	);

	/**
	 * Put the listing's shipping into the product's meta, from the eBay item
	 * in hand, and asking eBay nothing:
	 *
	 * - _ebay_shipping_policy_id and _ebay_shipping_policy_name, from
	 *   SellerProfiles.SellerShippingProfile (the Business Policy);
	 * - _ebay_shipping_type, ShippingDetails.ShippingType: Flat, Calculated,
	 *   FlatDomesticCalculatedInternational...;
	 * - _ebay_shipping_service, _ebay_shipping_cost and _ebay_shipping_currency,
	 *   from the domestic service eBay offers first (the lowest
	 *   ShippingServicePriority): the cost a plain decimal, '0.00' when free;
	 * - _ebay_shipping_services, every domestic and international service:
	 *   service, cost, additional_cost, priority, free, international.
	 *
	 * Only what the item carries is written. A listing read in brief - a delta
	 * row, say - has no ShippingDetails or SellerProfiles, and leaves what an
	 * earlier full read recorded. The policy goes only when the item's
	 * SellerProfiles are in hand and hold no shipping policy.
	 *
	 * The cost's currencyID does not survive the API class's conversion of
	 * eBay's XML (SimpleXML drops an attribute on an element that holds text),
	 * so the currency is the listing's own, Item.Currency, which eBay requires
	 * shipping costs to be in, unless the conversion kept it.
	 *
	 * Never _ebay_export_fulfillment_policy: that is the shop's own choice of
	 * policy for pushing the product, and the import leaves it alone.
	 *
	 * @param array $ebay_item    eBay item.
	 * @param array $product_data Product data, given meta and meta_delete here.
	 */
	private function add_shipping_meta( array $ebay_item, array &$product_data ) {
		// The Business Policy for shipping.
		if ( isset( $ebay_item['SellerProfiles'] ) && is_array( $ebay_item['SellerProfiles'] ) ) {
			$profile = $ebay_item['SellerProfiles']['SellerShippingProfile'] ?? null;
			$id      = is_array( $profile ) ? $this->parse_price_value( $profile['ShippingProfileID'] ?? '' ) : '';

			if ( '' !== $id ) {
				$product_data['meta']['_ebay_shipping_policy_id'] = $id;
				$name = $this->parse_price_value( $profile['ShippingProfileName'] ?? '' );
				if ( '' !== $name ) {
					$product_data['meta']['_ebay_shipping_policy_name'] = $name;
				}
			} else {
				// The listing's policies are in hand, and none is for shipping.
				$product_data['meta_delete'][] = '_ebay_shipping_policy_id';
				$product_data['meta_delete'][] = '_ebay_shipping_policy_name';
			}
		}

		$details = $ebay_item['ShippingDetails'] ?? null;
		if ( ! is_array( $details ) ) {
			return;
		}

		$type = $this->parse_price_value( $details['ShippingType'] ?? '' );
		if ( '' !== $type ) {
			$product_data['meta']['_ebay_shipping_type'] = $type;
		}

		$services = array();
		$currency = '';
		foreach ( array( 'ShippingServiceOptions' => false, 'InternationalShippingServiceOption' => true ) as $container => $international ) {
			foreach ( $this->shipping_service_list( $details[ $container ] ?? null ) as $order => $option ) {
				$service = $this->parse_price_value( $option['ShippingService'] ?? '' );
				if ( '' === $service ) {
					continue;
				}

				$cost     = $this->shipping_amount( $option['ShippingServiceCost'] ?? null );
				$free     = 'true' === strtolower( $this->parse_price_value( $option['FreeShipping'] ?? '' ) )
					|| ( '' !== $cost && 0.0 === (float) $cost );
				$priority = $this->parse_price_value( $option['ShippingServicePriority'] ?? '' );

				$services[] = array(
					'service'         => $service,
					'cost'            => $free && '' === $cost ? '0.00' : $cost,
					'additional_cost' => $this->shipping_amount( $option['ShippingServiceAdditionalCost'] ?? null ),
					'priority'        => '' !== $priority ? (int) $priority : $order + 1,
					'free'            => $free,
					'international'   => $international,
				);

				if ( '' === $currency ) {
					$currency = self::currency_of( $option['ShippingServiceCost'] ?? null );
				}
			}
		}

		if ( empty( $services ) ) {
			return;
		}

		$product_data['meta']['_ebay_shipping_services'] = $services;

		// The domestic service eBay offers first: the lowest priority, and of
		// two the same, the one it sent first. Kept stable by hand, since PHP
		// before 8 does not keep the order of equals when it sorts.
		$domestic = array();
		foreach ( $services as $index => $one ) {
			if ( ! $one['international'] ) {
				$domestic[] = array( $one['priority'], $index, $one );
			}
		}
		if ( empty( $domestic ) ) {
			return;
		}
		usort( $domestic, function ( $a, $b ) {
			return $a[0] === $b[0] ? $a[1] - $b[1] : $a[0] - $b[0];
		} );
		$first = $domestic[0][2];

		$product_data['meta']['_ebay_shipping_service'] = $first['service'];

		// A service with no cost of its own is calculated for each buyer: said
		// as no cost, but only where the listing says it is calculated - an
		// answer that left the cost out says nothing about it.
		if ( '' !== $first['cost'] ) {
			$product_data['meta']['_ebay_shipping_cost'] = $first['cost'];
		} elseif ( 0 === strpos( $type, 'Calculated' ) ) {
			$product_data['meta']['_ebay_shipping_cost'] = '';
		}

		if ( '' === $currency ) {
			$currency = $this->parse_price_value( $ebay_item['Currency'] ?? '' );
		}
		if ( '' !== $currency ) {
			$product_data['meta']['_ebay_shipping_currency'] = $currency;
		}
	}

	/**
	 * The listing's own return and payment policies, beside its shipping one.
	 */
	const POLICY_META_KEYS = array(
		'_ebay_return_policy_id',
		'_ebay_return_policy_name',
		'_ebay_payment_policy_id',
		'_ebay_payment_policy_name',
	);

	/**
	 * Put the listing's return and payment Business Policies into the
	 * product's meta, from SellerProfiles in the item in hand, by the same rule
	 * as its shipping policy: a key goes only when SellerProfiles are in hand
	 * and hold no such policy.
	 *
	 * Nothing reads these in 3.27.8: a push sends the shop's own policies, and
	 * a listing template's policy blocks say what is sent. 3.28.1's item 13
	 * revises imported listings without SellerProfiles, leaving each listing's
	 * own policies on eBay, and its policy blocks will need these to say what
	 * that listing really offers.
	 *
	 * @param array $ebay_item    eBay item.
	 * @param array $product_data Product data, given meta and meta_delete here.
	 */
	private function add_policy_meta( array $ebay_item, array &$product_data ) {
		if ( ! isset( $ebay_item['SellerProfiles'] ) || ! is_array( $ebay_item['SellerProfiles'] ) ) {
			return;
		}

		$kinds = array(
			'return'  => array( 'SellerReturnProfile', 'ReturnProfileID', 'ReturnProfileName' ),
			'payment' => array( 'SellerPaymentProfile', 'PaymentProfileID', 'PaymentProfileName' ),
		);

		foreach ( $kinds as $kind => $fields ) {
			$profile = $ebay_item['SellerProfiles'][ $fields[0] ] ?? null;
			$id      = is_array( $profile ) ? $this->parse_price_value( $profile[ $fields[1] ] ?? '' ) : '';

			if ( '' !== $id ) {
				$product_data['meta'][ '_ebay_' . $kind . '_policy_id' ] = $id;
				$name = $this->parse_price_value( $profile[ $fields[2] ] ?? '' );
				if ( '' !== $name ) {
					$product_data['meta'][ '_ebay_' . $kind . '_policy_name' ] = $name;
				}
			} else {
				$product_data['meta_delete'][] = '_ebay_' . $kind . '_policy_id';
				$product_data['meta_delete'][] = '_ebay_' . $kind . '_policy_name';
			}
		}
	}

	/**
	 * An amount of eBay's as a plain decimal string, or '' when there is none.
	 *
	 * @param mixed $raw ShippingServiceCost and the like.
	 * @return string E.g. '4.50'.
	 */
	private function shipping_amount( $raw ) {
		$value = $this->parse_price_value( $raw );

		return ( '' !== $value && is_numeric( $value ) ) ? number_format( (float) $value, 2, '.', '' ) : '';
	}

	/**
	 * The currencyID an amount came with, where the conversion kept it.
	 *
	 * @param mixed $raw An amount from eBay's answer.
	 * @return string E.g. 'USD', or ''.
	 */
	private static function currency_of( $raw ) {
		if ( ! is_array( $raw ) ) {
			return '';
		}

		$id = $raw['@attributes']['currencyID'] ?? ( $raw['currencyID'] ?? '' );

		return is_string( $id ) ? $id : '';
	}

	/**
	 * One line saying what the product holds of its listing's shipping, for
	 * its eBay tab - or '' when nothing was recorded.
	 *
	 * @param int $product_id Product.
	 * @return string E.g. "UK Standard - RoyalMailFirstClass 3.20 GBP".
	 */
	public static function stored_shipping_line( $product_id ) {
		$policy  = (string) get_post_meta( $product_id, '_ebay_shipping_policy_name', true );
		$service = (string) get_post_meta( $product_id, '_ebay_shipping_service', true );
		$cost    = (string) get_post_meta( $product_id, '_ebay_shipping_cost', true );
		$money   = (string) get_post_meta( $product_id, '_ebay_shipping_currency', true );
		$type    = (string) get_post_meta( $product_id, '_ebay_shipping_type', true );

		$what = $service;
		if ( '' !== $service ) {
			if ( '' !== $cost && 0.0 === (float) $cost ) {
				$what .= ' ' . __( 'free', 'tcgiant-sync' );
			} elseif ( '' !== $cost ) {
				$what .= ' ' . trim( $cost . ' ' . $money );
			} elseif ( '' !== $type ) {
				$what .= ' (' . $type . ')';
			}
		}

		$parts = array_values( array_filter( array( $policy, $what ), function ( $part ) {
			return '' !== $part;
		} ) );

		return implode( ' - ', $parts );
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
	 * Whether the product linked to a listing recorded a template on its last
	 * send, for TCGiant_Sync_Templates::content_region().
	 *
	 * Asked only when the description has something a marker could be, so
	 * every other listing costs no query.
	 *
	 * @param string $item_id     eBay item number.
	 * @param string $description eBay's Description.
	 * @return bool
	 */
	private function sent_with_template( $item_id, $description ) {
		if ( '' === $item_id || ( false === strpos( $description, 'tcg-desc' ) && false === strpos( $description, 'data-tcg' ) ) ) {
			return false;
		}
		global $wpdb;
		$found = $wpdb->get_row( $wpdb->prepare(
			"SELECT pm.post_id, p.post_status FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_ebay_item_id' AND pm.meta_value = %s
			   AND p.post_type = 'product'
			 ORDER BY ( p.post_status = 'trash' ) ASC
			 LIMIT 1",
			$item_id
		) );
		// Of this listing: a record of a send to the listing before it (one
		// that ended, the product since linked to another) proves nothing here.
		return $found && (int) get_post_meta( (int) $found->post_id, '_tcgiant_tpl_id', true ) > 0
			&& (string) get_post_meta( (int) $found->post_id, '_tcgiant_tpl_item', true ) === $item_id;
	}

	/**
	 * Whether a listing's description is still what TCGiant last sent it with
	 * a template or an eBay-only description: the content region by its hash,
	 * or the whole of it.
	 *
	 * @param int    $product_id  Product.
	 * @param string $description The description the import read, the
	 *                            content region where there is one.
	 * @return bool
	 */
	private function came_back_as_sent( $product_id, $description ) {
		$templated = (int) get_post_meta( $product_id, '_tcgiant_tpl_id', true ) > 0;
		$ebay_only = '' !== trim( (string) get_post_meta( $product_id, '_tcgiant_ebay_description', true ) );
		if ( ! $templated && ! $ebay_only ) {
			return false;
		}
		$hash   = sha1( $description );
		$region = (string) get_post_meta( $product_id, '_tcgiant_region_hash', true );
		$whole  = (string) get_post_meta( $product_id, '_tcgiant_desc_hash', true );
		return ( $templated && '' !== $region && hash_equals( $region, $hash ) ) || ( '' !== $whole && hash_equals( $whole, $hash ) );
	}

	/**
	 * Create/Update WooCommerce Product.
	 *
	 * @param array $product_data Mapped product data.
	 */
	public function save_as_product( &$product_data ) {
		self::$last_skipped  = false;
		self::$last_deferred = null;
		$this->parent_over   = array();

		$item_id = $product_data['meta']['_ebay_item_id'];
		$product_id = false;
		// Whether step 2 below gives a product this listing by its SKU.
		$linked_by_sku = false;
		// Whether step 2 completed, in the table, a push that did not finish.
		$completed_push = false;

		// 1. Check by exact eBay Item ID first.
		//
		// Trashed products are included here on purpose. A trashed product
		// carrying this exact Item ID means the merchant deliberately deleted
		// the product for this listing, and re-creating it on the next sync is
		// the single most confusing thing the plugin can do — the item comes
		// back from the dead with no explanation.
		//
		// This does not reintroduce the trashed-SKU problem fixed in 3.0.1:
		// that was about matching by SKU (step 2) when a seller relists under a
		// NEW Item ID. A relist has a different Item ID, so it never matches a
		// trashed product here.
		global $wpdb;
		$found = $wpdb->get_row( $wpdb->prepare(
			"SELECT pm.post_id, p.post_status FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_ebay_item_id' AND pm.meta_value = %s
			   AND p.post_type = 'product'
			 ORDER BY ( p.post_status = 'trash' ) ASC
			 LIMIT 1",
			$item_id
		) );

		if ( $found && 'trash' === $found->post_status ) {
			/**
			 * Filter whether a deleted product should be re-created by a sync.
			 *
			 * Default false: the merchant deleted it, so leave it deleted.
			 * Emptying the Trash removes the record, after which the listing
			 * will be imported as new.
			 *
			 * @param bool   $recreate Whether to re-import.
			 * @param string $item_id  eBay Item ID.
			 */
			if ( ! apply_filters( 'tcgiant_sync_reimport_deleted_products', false, $item_id ) ) {
				TCGiant_Sync_Logger::log( sprintf(
					'Skipped eBay item %s — its WooCommerce product (#%d) is in the Trash, so it was deleted deliberately.',
					$item_id,
					(int) $found->post_id
				) );
				self::$last_skipped = true;
				return false;
			}
		}

		if ( $found && 'trash' !== $found->post_status ) {
			$product_id = (int) $found->post_id;
		}

		// 1a. A listing on another eBay site, when the shop imports only the
		//     listings on its Primary eBay Marketplace (TCGiant_Sync_Sites).
		//
		// Here, after the exact-listing match and before the SKU match, so that
		// one check covers every way a listing reaches a product - the delta,
		// the full scan inline and queued, and Sync Specific Items - and so that
		// a product already linked to this exact listing carries on syncing, as
		// it must until the shop removes it. What it stops is everything below:
		// a new product, an unlinked product being taken over because it has
		// the same SKU, and a product whose own listing ended being re-linked to
		// this one. A seller whose copies on other sites keep the eBay.com SKU
		// would otherwise have its United States products moved onto a copy on
		// eBay.de the moment the United States listing ended.
		if ( ! $product_id ) {
			$listing_site = (string) ( $product_data['site'] ?? '' );

			if ( TCGiant_Sync_Sites::leaves_out( $listing_site ) ) {
				return $this->skip_other_site( $product_data, $item_id, $listing_site );
			}

			if ( '' === $listing_site && TCGiant_Sync_Sites::primary_only() ) {
				// Let through rather than dropped: a product that should not be
				// here can be removed, a listing dropped by mistake never comes
				// back. eBay's GetItem always says, so this is rare.
				TCGiant_Sync_Logger::warning( sprintf(
					'eBay did not say which site item %s is listed on; importing it.',
					$item_id
				) );
			}
		}

		// 1b. A relist of a listing one of our products already follows.
		//
		// eBay names the listing a relist came from. The SKU match below only
		// ever caught a relist whose seller kept a SKU of their own: one whose
		// SKU was its item number matched nothing, and a barcode SKU is refused
		// there on purpose. So a coin relisted on eBay became a second product,
		// while the first stayed in stock pointing at the listing that had
		// ended - and a website sale of it never reached the live relist.
		//
		// Only when no product of any status holds this listing: a product in
		// the trash for it was deleted on purpose, and is dealt with above.
		$adopted_from = '';
		$held_before  = null;
		$review_order = 0;
		$fell_since   = false;

		// Moved on to this relist already, by the per-sale stock check or the
		// inventory prune, which take on a relist without importing it. This
		// import is the one that applies eBay's quantity, so the warning about
		// what WooCommerce held is given here, as if the move were made now.
		if ( $product_id ) {
			$moved = get_post_meta( $product_id, '_ebay_relist_stock_check', true );

			if ( '' !== $moved ) {
				delete_post_meta( $product_id, '_ebay_relist_stock_check' );
			}

			if ( is_array( $moved ) && (string) ( $moved['item'] ?? '' ) === (string) $item_id ) {
				$adopted_from = (string) ( $moved['parent'] ?? '' );
				$held_before  = isset( $moved['held'] ) && is_numeric( $moved['held'] ) ? (int) $moved['held'] : null;

				// A stock the shop set since the move went to the relist as
				// well, so eBay's figure is the shop's own: not warned about.
				// The higher of the two, not the stock now: the sale check and
				// the prune lower it to eBay's figure straight after the move.
				$now = wc_get_product( $product_id );
				if ( null !== $held_before && $now && $now->managing_stock() && null !== $now->get_stock_quantity() ) {
					// Lower now than at the move: for an auction, the item went
					// elsewhere, and a push could not reach a relist with bids.
					// The guard below then keeps the lower figure.
					$fell_since  = (int) $now->get_stock_quantity() < $held_before;
					$held_before = max( $held_before, (int) $now->get_stock_quantity() );
				}

				$this->relist_parent_figures = isset( $moved['listed'], $moved['sold'] )
					? array( 'listed' => (int) $moved['listed'], 'sold' => (int) $moved['sold'] )
					: null;
			}
		}

		// An auction nothing follows, left out unless a product takes it on
		// here or by its SKU below. eBay reports every bid as a change, so the
		// same relist is met again and again; once eBay's answer has decided
		// against moving a product on to it, that is remembered for the
		// auction's life rather than asked again on every bid.
		$leaving_out   = ! $found
			&& 'Chinese' === (string) ( $product_data['meta']['_ebay_listing_type'] ?? '' )
			&& ! self::imports_auctions();
		$declined_once = 'tcgiant_relist_declined_' . $item_id;

		if ( ! $found && '' !== (string) ( $product_data['relist_parent_id'] ?? '' )
			&& ! ( $leaving_out && get_transient( $declined_once ) ) ) {
			$this->auction_left_out = $leaving_out;
			try {
				$parent_product = $this->relist_parent_product(
					$item_id,
					$product_data['relist_parent_id'],
					(string) $product_data['sku'],
					(string) ( $product_data['sku_source'] ?? '' ),
					null,
					(array) ( $product_data['identifiers'] ?? array() )
				);
			} finally {
				$this->auction_left_out = false;
			}

			if ( $parent_product ) {
				// Read before the product moves: moving forgets how the old
				// listing ended, which is what these are worked out from.
				$before = wc_get_product( $parent_product );
				if ( $before && $before->managing_stock() && null !== $before->get_stock_quantity() ) {
					$held_before = (int) $before->get_stock_quantity();
				}
				$review_order = TCGiant_Sync_Inventory::restocked_after_website_sale( $parent_product );

				TCGiant_Sync_Inventory::follow_relist(
					$parent_product,
					$product_data['relist_parent_id'],
					$item_id,
					$this->listing_facts_from_meta( $product_data['meta'] ),
					'Import'
				);

				$product_id   = $parent_product;
				$adopted_from = (string) $product_data['relist_parent_id'];
			} elseif ( self::defers_relist( $this->relist_parent_error ) ) {
				// eBay was never asked how the old listing ended: out of calls,
				// or no token. Made a product of its own now, the relist would
				// stay one for good - every later import finds it by its item
				// number - beside the product that should have moved on to it.
				self::$last_deferred = $this->relist_parent_error;
				return false;
			} elseif ( $leaving_out && $this->relist_decline_final ) {
				set_transient( $declined_once, 1, 10 * DAY_IN_SECONDS );
			}
		}

		// An eBay auction nothing here follows yet, while "Import eBay auctions"
		// is off. It is not made into a product, and an unlinked product that
		// shares its SKU is not taken over: the auction's stock of one and its
		// opening bid would be written over the shop's own product. A product
		// already following it - found above by item number, or moved on to it
		// as a relist - keeps syncing either way, and so does one whose own
		// listing has ended and that the seller has put up again as an auction.
		$auction_blocked = ! $product_id
			&& 'Chinese' === (string) ( $product_data['meta']['_ebay_listing_type'] ?? '' )
			&& ! self::imports_auctions();
		$left_alone      = 0;

		// 2. If not found by Item ID, check by SKU to link to existing WC product.
		//    This handles the case where eBay changed the item number (e.g. old listing
		//    expired and seller created a new listing with the same SKU).
		if ( ! $product_id && ! empty( $product_data['sku'] ) ) {
			$sku_product_id = $this->find_matchable_product_id_by_sku( $product_data['sku'] );
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
					if ( $auction_blocked && empty( $existing_item_id ) ) {
						// The shop's own product, not linked to eBay. Not taken
						// over by an auction that is not being imported.
						$left_alone = (int) $sku_product_id;
					} elseif ( empty( $existing_item_id ) || $existing_item_id === $item_id ) {
						// No eBay link or same item → safe to link.
						$product_id = $sku_product_id;
						$linked_by_sku = true;
					} elseif ( 'seller' !== ( $product_data['sku_source'] ?? '' ) ) {
						// Same SKU, different listing — but this SKU is not something
						// that identifies a listing, so the match proves nothing.
						//
						// A barcode is shared by every listing of that part. Moving an
						// existing product onto a new listing because they share one
						// merges two different items: the new stock never appears as a
						// product of its own, and the product it lands on is taken away
						// from the listing it was actually selling. On a store whose
						// SKUs come from barcodes, and whose listings end and are
						// replaced constantly, that happens continually and silently.
						//
						// An item-number SKU cannot legitimately match another listing
						// either; if it does, an earlier bad re-link left it behind.
						//
						// Not said for an auction left out below, which is not
						// imported at all and is said so once, not on every bid.
						if ( ! $auction_blocked ) {
							TCGiant_Sync_Logger::log( sprintf(
								'SKU "%s" is also on WC #%d (listing %s), but it came from %s rather than the seller SKU, so it does not identify a listing. Importing %s as a separate product.',
								$product_data['sku'],
								$sku_product_id,
								$existing_item_id,
								'barcode' === ( $product_data['sku_source'] ?? '' ) ? 'a barcode' : 'the eBay item number',
								$item_id
							), 'warning' );
						}
					} elseif ( $auction_blocked && get_transient( 'tcgiant_auction_skip_' . $item_id )
						? 'Ended' === (string) get_post_meta( $sku_product_id, '_ebay_listing_status', true )
						: $this->ebay_listing_is_over( $sku_product_id, $existing_item_id ) ) {
						// (An auction already left out once is not asked about
						// on every bid: the product's own record decides, and
						// the delta and the hourly check write Ended.)
						//
						// The listing this product belonged to really has finished, and
						// the seller has put the same item back up under a new number.
						// Point the product at the new listing.
						TCGiant_Sync_Logger::log( sprintf(
							'SKU "%s" matched WC #%d, whose listing %s has ended → re-linking it to the new listing %s.',
							$product_data['sku'], $sku_product_id, $existing_item_id, $item_id
						) );
						$product_id = $sku_product_id;

						// An auction the shop listed from WooCommerce, put up again
						// on eBay with its SKU (Sell Similar, or a relist eBay could
						// not confirm), stays the shop's, as a relist does - see
						// TCGiant_Sync_Inventory::follow_relist().
						if ( (string) get_post_meta( $sku_product_id, TCGiant_Sync_Inventory::AUCTION_LISTED_KEY, true ) === (string) $existing_item_id
							&& 'Chinese' === (string) ( $product_data['meta']['_ebay_listing_type'] ?? '' ) ) {
							update_post_meta( $sku_product_id, TCGiant_Sync_Inventory::AUCTION_LISTED_KEY, (string) $item_id );
						}

						$linked_by_sku = true;
						// Clear the old item ID so it gets replaced with the new one.
						update_post_meta( $sku_product_id, '_ebay_item_id', $item_id );
						// What was sent to the old listing is not this one's.
						if ( class_exists( 'TCGiant_Sync_Templates' ) ) {
							TCGiant_Sync_Templates::forget_sent( $sku_product_id );
						}
						// Its push completed in the table with it. Left to the save
						// below, a save that died left the product linked here and
						// still saying its push did not finish, and the next sync
						// found it by this item number, not by its SKU.
						$completed_push = TCGiant_Sync_Listing_Link::complete_unfinished_push_now( $sku_product_id );
					} elseif ( ! $auction_blocked ) {
						// (An auction left out below is said so once, there.)
						//
						// Two live listings that merely share an identifier. This is
						// ordinary whenever the WooCommerce SKU comes from a UPC or EAN
						// rather than the eBay item number — a manufacturer barcode is
						// not unique to one listing.
						//
						// This used to re-link regardless, on the assumption that a SKU
						// collision meant a relist. When it did not, the new listing was
						// swallowed by an unrelated product instead of becoming one of
						// its own — so newly added inventory never appeared — and that
						// product was detached from the listing it was actually selling,
						// then overwritten with the newcomer's title, price and images.
						// Both silent.
						//
						// Leaving it alone costs at worst a visible duplicate, which can
						// be deleted. The SKU is made unique further down.
						TCGiant_Sync_Logger::log( sprintf(
							'SKU "%s" is also used by WC #%d, whose listing %s is still live — importing %s as a separate product rather than taking that one over.',
							$product_data['sku'], $sku_product_id, $existing_item_id, $item_id
						), 'warning' );
					}
				}
			}
		}

		// Nothing follows this auction and nothing above took it on, so it is
		// left out - before anything is written - as an intended skip rather
		// than a failure, on every import path alike.
		if ( $auction_blocked && ! $product_id ) {
			return $this->skip_auction( (string) $item_id, (string) ( $product_data['title'] ?? '' ), $left_alone );
		}

		// 3. Prevent SKU conflicts on save.
		// Check if the intended SKU is already taken by a DIFFERENT product.
		// Exclude trashed products from conflict detection.
		if ( ! empty( $product_data['sku'] ) ) {
			$conflict_id = wc_get_product_id_by_sku( $product_data['sku'] );

			// A variation of the product we are about to save is not a conflict.
			// Treating it as one renamed the parent's SKU on every sync of a
			// variable listing, and left the listing looking unmatched next time.
			if ( $conflict_id && $product_id && 'product_variation' === get_post_type( $conflict_id )
				&& (int) wp_get_post_parent_id( $conflict_id ) === (int) $product_id ) {
				$conflict_id = 0;
			}

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

		if ( '' !== $adopted_from ) {
			$sync_decisions[] = sprintf( 'Relisted on eBay: now follows listing %s (was %s)', $item_id, $adopted_from );
		}

		if ( $is_new || ! empty( $settings['overwrite_title'] ) ) {
			$product->set_name( $product_data['title'] );
			if ( ! $is_new ) $sync_decisions[] = 'Title updated (eBay won)';
		} elseif ( ! $is_new ) {
			$sync_decisions[] = 'Title skipped (WooCommerce won)';
		}

		// A listing that still holds what TCGiant sent it - with a template,
		// or the product's eBay-only description - was made from this
		// product's own description, which stays as it is: writing back what
		// the template's cleaning left of it would lose the rest for good.
		$as_sent = ! $is_new && ! empty( $settings['overwrite_desc'] ) && $this->came_back_as_sent( (int) $product_id, (string) $product_data['description'] );

		if ( $is_new || ( ! empty( $settings['overwrite_desc'] ) && ! $as_sent ) ) {
			$product->set_description( $product_data['description'] );
			if ( ! $is_new ) $sync_decisions[] = 'Description updated (eBay won)';
		} elseif ( $as_sent ) {
			$sync_decisions[] = 'Description kept: the listing holds what TCGiant sent it';
		} elseif ( ! $is_new ) {
			$sync_decisions[] = 'Description skipped (WooCommerce won)';
		}

		// SKU is always enforced so WooCommerce knows which eBay item this is.
		$product->set_sku( $product_data['sku'] );

		// Stock is ALWAYS managed by eBay - except that an auction this product
		// already follows never raises it. An auction is one item and its
		// quantity never goes up on eBay, so WooCommerce holding less means the
		// item went elsewhere, and a stock push left the auction running
		// because it had bids. Put back to 1, the item was for sale here again
		// once the auction ended unsold, and Auto-Relist listed it again.
		$stock_kept = false;
		$held_now   = ( $found && ( '' === $adopted_from || $fell_since ) && $product->managing_stock() ) ? $product->get_stock_quantity() : null;

		// Stock kept on the product and shared by its variations: eBay shows
		// the whole of it for each, so the listing's total is no figure for
		// it, and writing each variation's own figure turned 5 shared into 5
		// each. The shared stock stays; eBay's sales of the variations come
		// off it once they are saved (settle_pool(), below), and it is never
		// raised from here (3.28.0, fix round 3).
		$pool_hold = null;
		if ( $is_variable && ! $is_new && $product->is_type( 'variable' )
			&& $product->managing_stock() && null !== $product->get_stock_quantity() ) {
			foreach ( (array) $product->get_children() as $child_id ) {
				if ( TCGiant_Sync_Inventory::stock_held_by_parent( wc_get_product( (int) $child_id ) ) ) {
					$hold      = self::sale_hold( $product->get_id() );
					$pool_hold = 'sale' === $hold ? 'all' : ( null !== $hold ? 'unrecorded' : 'none' );
					break;
				}
			}
		}
		if ( null !== $pool_hold ) {
			$product_data['stock_quantity'] = max( 0, (int) $product->get_stock_quantity() );
			$stock_kept                     = true;
			$sync_decisions[]               = 'all' === $pool_hold
				? 'Shared stock kept at ' . $product_data['stock_quantity'] . ': a website sale is still on its way to eBay'
				: 'Shared stock kept at ' . $product_data['stock_quantity'] . ': its variations share it, and only eBay\'s sales of them come off it';
		}

		if ( null !== $held_now && ! $stock_kept
			&& 'Chinese' === (string) ( $product_data['meta']['_ebay_listing_type'] ?? '' )
			&& (int) $held_now < (int) $product_data['stock_quantity'] ) {
			$product_data['stock_quantity'] = max( 0, (int) $held_now );
			$stock_kept                     = true;
			$sync_decisions[]               = 'Stock kept at ' . $product_data['stock_quantity'] . ': WooCommerce held less than the auction, which is one item';
		}

		// Nor while a website sale is on its way to eBay, or never got there:
		// eBay's figure does not have that sale in it, whether it is higher or
		// lower than WooCommerce's - lower when eBay has sold some too. Written
		// over WooCommerce's, it wiped the website's sale, and the record of
		// eBay's sales with it, and the push then found nothing to send.
		// WooCommerce's stock and that record are left as they are, and the
		// push sorts the two out through the record (3.28.0). On every path to
		// a product already here - a relist taken on, a link by SKU - as much
		// as the listing it follows: a relist the import took on wrote its
		// figure over the sale, and the sale's push then found nothing to send.
		// A sale whose push never got there holds only as far as its units:
		// eBay's figure less them, when that is the lower (sale_hold()).
		$sale_base   = null !== $held_now ? $held_now : ( ! $is_new && $product->managing_stock() ? $product->get_stock_quantity() : null );
		$hold        = null !== $sale_base && ! $stock_kept ? self::sale_hold( $product->get_id() ) : null;
		$unsent_sent = false;
		if ( null !== $hold ) {
			$figure                         = self::held_figure( $hold, (int) $sale_base, (int) $product_data['stock_quantity'] );
			$stock_kept                     = $figure['kept'];
			// Lowered below eBay's figure by the units eBay never heard of:
			// eBay is told, once saved (fix round 4).
			$unsent_sent                    = 'sale' !== $hold && ! $figure['kept'];
			$sync_decisions[]               = 'sale' === $hold
				? 'Stock kept at ' . $figure['stock'] . ': a website sale is still on its way to eBay'
				: ( $figure['kept']
					? 'Stock kept at ' . $figure['stock'] . ': a website sale of ' . $hold . ' never reached eBay'
					: 'Stock set to ' . $figure['stock'] . ': eBay\'s ' . (int) $product_data['stock_quantity'] . ' less the ' . $hold . ' of a website sale that never reached eBay' );
			$product_data['stock_quantity'] = $figure['stock'];
		}

		// Nor while a stock change made in WooCommerce is still on its way to
		// eBay: eBay's figure is from before it, and written over the lower
		// one it would put back a unit the website has just sold - which the
		// push then sends to eBay as the shop's own stock. Only while one is on
		// its way: otherwise eBay's figure stands, so stock raised in Seller
		// Hub still arrives.
		if ( null !== $held_now && ! $stock_kept
			&& (int) $held_now < (int) $product_data['stock_quantity']
			&& self::push_pending( $product->get_id() ) ) {
			$product_data['stock_quantity'] = max( 0, (int) $held_now );
			$stock_kept                     = true;
			$sync_decisions[]               = 'Stock kept at ' . $product_data['stock_quantity'] . ': a stock change made in WooCommerce is still on its way to eBay';
		}

		$product->set_manage_stock( true );
		$product->set_stock_quantity( $product_data['stock_quantity'] );

		// A relist offers what eBay says it offers, as any live listing does.
		// But where WooCommerce held less, and the old listing's own sales do
		// not account for it, the difference went outside eBay - a website sale
		// or a hand edit - and the shop is told how to take it back off.
		if ( '' !== $adopted_from ) {
			$this->warn_about_relist_stock( (int) $product_id, $item_id, $adopted_from, (int) $product_data['stock_quantity'], $held_before, $review_order );
		}

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
		if ( ! $stock_kept ) {
			$sync_decisions[] = 'Stock synced to ' . $product_data['stock_quantity'] . ' (eBay won)';
		}
		
		// Set price (already extracted as clean numeric string).
		// By default setting empty('overwrite_price') means DO NOT overwrite if exists.
		// However, in settings we defaulted it to 1.
		$overwrite_price = isset( $settings['overwrite_price'] ) ? $settings['overwrite_price'] : '1';

		// An auction answer that carried only the current bid: a product that
		// already has its price keeps it, rather than climbing with the bids.
		$bid_only = ! $is_new && ! empty( $product_data['auction_price_is_bid'] );

		if ( $bid_only ) {
			$sync_decisions[] = 'Price kept: eBay sent this auction\'s current bid, not its opening bid';
		} elseif ( ( $is_new || '1' === $overwrite_price ) ) {
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
		} elseif ( ! empty( $product_data['regular_price'] ) ) {
			// Only reachable when the product already existed and price
			// overwriting is off, so $is_new is necessarily false here.
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

		// What this product already has, read before anything is built.
		//
		// New attributes are positioned after the existing ones rather than
		// from zero, so importing does not silently reshuffle a list the shop
		// arranged itself.
		$existing_attributes = $product->get_attributes();
		$attr_position       = 0;

		foreach ( $existing_attributes as $existing_attribute ) {
			$attr_position = max( $attr_position, (int) $existing_attribute->get_position() + 1 );
		}
		
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
		
		// Merged, never replaced.
		//
		// set_attributes() takes the whole set, and this used to hand it a list
		// built only from eBay's item specifics - so every attribute the shop
		// had set itself was destroyed on each import of an existing product.
		// A sale triggers a delta import, so a merchant watched their global
		// attributes disappear every time something sold.
		//
		// A global attribute is never touched, even when overwriting is on.
		// eBay's specifics are free text and cannot safely populate a taxonomy
		// term; writing a custom attribute over one would both lose the term
		// relationship and leave two rows meaning the same thing. A custom
		// attribute is replaced only where eBay actually supplies one of the
		// same name, and anything eBay says nothing about is left alone.
		$merged = $existing_attributes;
		$claimed = array();

		foreach ( $existing_attributes as $existing_key => $existing_attribute ) {
			$label = $existing_attribute->is_taxonomy()
				? wc_attribute_label( $existing_attribute->get_name() )
				: $existing_attribute->get_name();

			$claimed[ strtolower( trim( (string) $label ) ) ] = $existing_key;
		}

		foreach ( $woo_attributes as $new_key => $new_attribute ) {
			$label = strtolower( trim( (string) $new_attribute->get_name() ) );

			if ( isset( $claimed[ $label ] ) ) {
				$already = $existing_attributes[ $claimed[ $label ] ];

				// The shop's own global attribute wins and the eBay one is
				// dropped rather than added beside it.
				if ( $already->is_taxonomy() ) {
					continue;
				}

				// Same attribute under the key it already has, so it keeps its
				// place in the list.
				$new_attribute->set_position( (int) $already->get_position() );
				$new_key = $claimed[ $label ];
			}

			$merged[ $new_key ] = $new_attribute;
		}

		// And gated like every other field on that screen.
		//
		// Title, description, images, categories and dimensions all ask before
		// eBay overwrites WooCommerce. Attributes never did, so a shop that had
		// turned every switch it could find to No was still losing them.
		$overwrite_attributes = ! empty( $settings['overwrite_attributes'] );

		if ( $is_new || $overwrite_attributes ) {
			$product->set_attributes( array_values( $merged ) );
			if ( ! $is_new ) { $sync_decisions[] = 'Attributes updated (eBay won, existing kept)'; }
		} elseif ( ! $is_new ) {
			$sync_decisions[] = 'Attributes skipped (WooCommerce won)';
		}

		// A push of this product that did not finish sent its SKU to eBay, and
		// this may well be the listing it made: linked to it now, as Link
		// would have, that push is complete. Left saying it did not finish,
		// the product told the shop to Link (a linked product is not offered
		// Link) or to Unlink a listing it had just left, Clear was refused,
		// and Auto-Relist, Relist and the bulk pushes left it out for good.
		// A re-link over an ended listing has already completed it, above.
		$completed_push = ( $linked_by_sku && TCGiant_Sync_Listing_Link::complete_unfinished_push( $product ) ) || $completed_push;

		// Set Custom Meta (including _ebay_item_id for tracking).
		foreach ( $product_data['meta'] as $key => $val ) {
			$product->update_meta_data( $key, $val );
		}

		// What the listing in hand shows it no longer has - never what it
		// merely did not carry. See add_shipping_meta().
		foreach ( (array) ( $product_data['meta_delete'] ?? array() ) as $key ) {
			if ( ! array_key_exists( $key, $product_data['meta'] ) ) {
				$product->delete_meta_data( $key );
			}
		}

		// Save Bin Location if configured.
		if ( ! empty( $product_data['bin_location'] ) ) {
			$product->update_meta_data( '_bin_location', $product_data['bin_location'] );
			if ( $is_new ) {
				$sync_decisions[] = 'Bin Location: ' . $product_data['bin_location'];
			}
		}

		// Update Sync Log. A stock decision is always recorded above, so this
		// list is never empty by the time we get here.
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

		// Everything below writes stock that came FROM eBay. Suppress the
		// WC → eBay push listeners for the duration, otherwise every imported
		// product queues a redundant ReviseInventoryStatus call — and anything
		// mapped to 0 stock triggers auto-end, permanently ending the seller's
		// live eBay listing as a side effect of importing it.
		//
		// eBay's figure is the stock: its record of eBay's sales is written
		// with it, from WooCommerce's stock action as the save writes it, or
		// once the save is over - never before, or a push would send
		// WooCommerce's figure from before (3.28.0 release fix round 5).
		$record_item = (string) $item_id;
		$record_sold = null !== ( $product_data['quantity_sold'] ?? null ) ? (int) $product_data['quantity_sold'] : null;
		$record_to   = (int) $product_data['stock_quantity'];
		$armed       = ! $is_new && ! $stock_kept && null !== $record_sold && $product->get_id() > 0
			? TCGiant_Sync_Inventory::arm_record(
				$product,
				null,
				function () use ( $product, $record_item, $record_sold ) {
					update_post_meta( (int) $product->get_id(), '_ebay_sold_seen', array( 'item' => $record_item, 'sku' => '', 'sold' => $record_sold ) );
				},
				function ( $now ) use ( $record_to ) {
					return (int) $now === $record_to;
				}
			)
			: null;

		TCGiant_Sync_Inventory::begin_ebay_origin();

		try {
			TCGiant_Sync_Runtime::stage( 'WooCommerce saving the product, with everything hooked to that' );
			$saved_id = $product->save();

			if ( $is_variable && $saved_id ) {
				// eBay's variations as the stock push reads them, before the
				// save makes any SKU unique.
				$pool_ebay = null !== $pool_hold ? self::ebay_variations( $product_data['variations'] ) : array();

				TCGiant_Sync_Runtime::stage( 'saving its variations' );
				$this->save_variations( $saved_id, $product_data['variations'] );

				if ( null !== $pool_hold ) {
					TCGiant_Sync_Inventory::settle_pool( $saved_id, $pool_ebay, (string) $item_id, $pool_hold );
				}
			}
			TCGiant_Sync_Runtime::stage( 'after saving the product' );
		} finally {
			TCGiant_Sync_Inventory::end_ebay_origin();
			// The save is over, thrown past or not: its listeners off, and the
			// record written if the stock holds eBay's figure.
			TCGiant_Sync_Inventory::disarm_record( $armed );
		}

		// The units of a sale that never reached eBay came off eBay's figure:
		// the push that tells eBay, as a sale's would have. Landed, it takes
		// the unsent mark off (fix round 4).
		if ( $saved_id && $unsent_sent ) {
			TCGiant_Sync_Inventory::queue_after_ebay_sale( (int) $saved_id );
		}

		// eBay's figure is the stock now: every eBay sale so far is in it. A
		// product already here has had its record from the armed save above,
		// and only if the stock holds eBay's figure: another plugin can hold
		// the save's stock write back (x22b). A new one, which could not be
		// armed, is checked the same way here.
		if ( $saved_id && $is_new && ! $stock_kept && null !== ( $product_data['quantity_sold'] ?? null )
			&& (int) get_post_meta( $saved_id, '_stock', true ) === (int) $product_data['stock_quantity'] ) {
			update_post_meta( $saved_id, '_ebay_sold_seen', array( 'item' => (string) $item_id, 'sku' => '', 'sold' => (int) $product_data['quantity_sold'] ) );
		}

		// The shop has an auction now: a push asks eBay before it ends a
		// listing whose type it has not had from eBay (3.28.0).
		if ( $saved_id && 'Chinese' === (string) ( $product_data['meta']['_ebay_listing_type'] ?? '' ) ) {
			TCGiant_Sync_Inventory::auctions_seen();
		}

		if ( $completed_push && $saved_id ) {
			TCGiant_Sync_Logger::log( sprintf(
				'WC #%1$d was linked by the sync to eBay listing %2$s, by its SKU, while its last push said it had not finished: that push is taken as complete, and its warning is cleared.',
				$saved_id,
				$item_id
			) );
		}

		// When a brand new product is created and it doesn't have images yet,
		// check if a trashed/orphaned product has the same _ebay_sku and migrate
		// its images. This handles the case where sellers relist with new Item
		// IDs and SKUs — the old product gets trashed but its images survive.
		if ( $is_new && $saved_id && ! empty( $product_data['meta']['_ebay_sku'] ) ) {
			$this->migrate_images_from_orphan(
				$saved_id,
				$product_data['meta']['_ebay_sku'],
				isset( $product_data['images'] ) ? (array) $product_data['images'] : array()
			);
		}

		// Mirror the listing into the indexed custom table.
		//
		// The table is created and migrated on install and read by the Listings
		// admin page and the auto-relist scheduler, but nothing wrote to it
		// after the initial migration — so both were working from a snapshot
		// frozen at install time.
		if ( $saved_id ) {
			// The type eBay gave, or none (the table's own default stands): a
			// row's type is written to the product as well, and the stock push
			// took a FixedPriceItem eBay never gave for eBay's word.
			$row_type = '' !== (string) $product_data['meta']['_ebay_listing_type'] ? array( 'listing_type' => (string) $product_data['meta']['_ebay_listing_type'] ) : array();
			TCGiant_Sync_DB::upsert( $row_type + array(
				'product_id'     => $saved_id,
				'ebay_item_id'   => $item_id,
				'listing_status' => $product_data['meta']['_ebay_listing_status'] ?: 'Active',
				'ebay_price'     => (float) $product_data['regular_price'],
				'ebay_quantity'  => (int) $product_data['stock_quantity'],
				'ebay_url'       => $item_id ? 'https://www.ebay.com/itm/' . $item_id : '',
				'ebay_title'     => $product_data['title'],
				'ebay_start_time' => $product_data['meta']['_ebay_start_time'] ?? '',
				'ebay_end_time'  => $product_data['meta']['_ebay_end_time'] ?? '',
				'last_synced'    => current_time( 'mysql' ),
			) );
		}

		return $saved_id;
	}

	/**
	 * Leave out a listing on another eBay site, and say so - once.
	 *
	 * A deliberate skip, so not counted as an error. Said once per listing per
	 * TCGiant_Sync_Sites::SKIP_LOG_DAYS: a seller whose every listing has seven
	 * copies on other sites would otherwise get thousands of these lines on
	 * every weekly full sync. Sync Specific Items clears the note first, so an
	 * item asked for by hand always says why it did not arrive.
	 *
	 * @param array  $product_data Mapped listing.
	 * @param string $item_id      eBay ItemID.
	 * @param string $site         Its Item.Site.
	 * @return false
	 */
	private function skip_other_site( array $product_data, $item_id, $site ) {
		self::$last_skipped = true;

		$key = TCGiant_Sync_Sites::SKIP_TRANSIENT . $item_id;
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, TCGiant_Sync_Sites::SKIP_LOG_DAYS * DAY_IN_SECONDS );

		// The case a shop most needs told: a product of its own, on the website
		// and not linked to eBay, that this listing would have taken over.
		$sku  = (string) ( $product_data['sku'] ?? '' );
		$held = '' !== $sku ? (int) $this->find_matchable_product_id_by_sku( $sku ) : 0;

		if ( $held && 'trash' !== get_post_status( $held ) && '' === (string) get_post_meta( $held, '_ebay_item_id', true ) ) {
			TCGiant_Sync_Logger::log( sprintf(
				'eBay item %1$s on %2$s has the same SKU as WC #%3$d, which is not linked to eBay. Import listings from is set to %4$s only, so WC #%3$d was left alone.',
				$item_id,
				TCGiant_Sync_Sites::label( $site ),
				$held,
				TCGiant_Sync_Sites::primary_label()
			) );
			return false;
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Skipped eBay item %s ("%s"): it is listed on %s, and Import listings from is set to %s only (Settings, Import Filters). Products already linked to a listing there keep syncing.',
			$item_id,
			(string) ( $product_data['title'] ?? '' ),
			TCGiant_Sync_Sites::label( $site ),
			TCGiant_Sync_Sites::primary_label()
		) );

		return false;
	}

	/**
	 * Look up a product by SKU, ignoring variations.
	 *
	 * wc_get_product_id_by_sku() searches wc_product_meta_lookup, which
	 * includes product variations. An incoming simple eBay listing whose SKU
	 * happens to match an existing variation would otherwise be "matched" to
	 * that variation, and the importer would then write a title, description,
	 * categories, attributes and status onto it — corrupting both the variation
	 * and its parent product.
	 *
	 * @param string $sku SKU to look up.
	 * @return int|false Product ID of a non-variation product, or false.
	 */
	/**
	 * Whether the eBay listing a product is currently attached to has finished.
	 *
	 * @param int    $product_id       WooCommerce product holding the link.
	 * @param string $existing_item_id The eBay Item ID it is attached to.
	 * @return bool True only when the listing is known to be over.
	 */
	private function ebay_listing_is_over( $product_id, $existing_item_id ) {
		if ( 'Ended' === (string) get_post_meta( $product_id, '_ebay_listing_status', true ) ) {
			return true;
		}

		// Asked a moment ago, as the listing a relist came from.
		if ( isset( $this->parent_over[ (string) $existing_item_id ] ) ) {
			return $this->parent_over[ (string) $existing_item_id ];
		}

		// Recorded as active, but that record can lag — and a relist is exactly
		// the case this branch exists to serve. Ask eBay rather than guess. Only
		// reached on a genuine SKU collision, and only once: whichever way it
		// resolves, the next sync matches on the item number instead.
		$response = TCGiant_Sync_API::instance()->get_item( $existing_item_id );

		if ( is_wp_error( $response ) || ! isset( $response['Item'] ) ) {
			// Unknown. Treat as still live: a duplicate product is visible and can
			// be removed, whereas a product taken from a live listing is neither.
			return false;
		}

		$status = $response['Item']['SellingStatus']['ListingStatus'] ?? '';

		return '' !== $status && 'Active' !== $status;
	}

	/**
	 * Whether a stock change made in WooCommerce is still on its way to eBay
	 * for this product or variation.
	 *
	 * While one is, an import keeps WooCommerce's figure when it is the lower
	 * (3.28.0): eBay's figure is from before the change.
	 *
	 * @param int $product_id Product or variation.
	 * @return bool
	 */
	public static function push_pending( $product_id ) {
		// TCGiant_Sync_Inventory::PUSH_PENDING_KEY: token => the time until
		// which that push counts - while it is queued or being tried again,
		// and briefly after it is done - or, queued since 3.28.0's fix round
		// 8, array( until, queued_at ). Read here by name, so harnesses that
		// stand in for the inventory class need know nothing of it.
		$pending = get_post_meta( (int) $product_id, '_ebay_push_pending', true );

		if ( ! is_array( $pending ) ) {
			return false;
		}

		foreach ( $pending as $until ) {
			if ( ( is_array( $until ) ? (int) ( $until['until'] ?? 0 ) : (int) $until ) >= time() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a website sale of this product is on its way to eBay, or never
	 * got there - TCGiant_Sync_Inventory::sale_pending(), read here by name:
	 * a sale's push has a token starting 'sale-', one given up leaves
	 * 'unsent'.
	 *
	 * @param int $product_id Product or variation.
	 * @return bool
	 */
	public static function sale_push_pending( $product_id ) {
		return null !== self::sale_hold( $product_id );
	}

	/**
	 * eBay's variations from an import's figures, in the shape
	 * TCGiant_Sync_Variations::from_item() gives them. A variation eBay gave
	 * no QuantitySold for has no figure here: nothing is worked out from it.
	 *
	 * @param array[] $variations From map_ebay_to_woo().
	 * @return array[]
	 */
	private static function ebay_variations( array $variations ) {
		$out = array();

		foreach ( $variations as $v ) {
			$sold  = $v['sold'] ?? null;
			$out[] = array(
				'sku'         => is_scalar( $v['sku'] ?? '' ) ? (string) ( $v['sku'] ?? '' ) : '',
				'specifics'   => (array) ( $v['attributes'] ?? array() ),
				'listed'      => null !== $sold ? (int) $v['stock_quantity'] + (int) $sold : null,
				'sold'        => null !== $sold ? (int) $sold : 0,
				'available'   => null !== $sold ? (int) $v['stock_quantity'] : null,
				'start_price' => '',
			);
		}

		return $out;
	}

	/**
	 * How an import holds back for a website sale eBay has not heard of.
	 *
	 * - 'sale': a sale's push is on its way. WooCommerce's stock and the
	 *   record of eBay's sales are left as they are; the push sorts them out.
	 * - A number: only the mark of sales whose push never got there is left,
	 *   with the units they took (TCGiant_Sync_Inventory::UNSENT_QTY_KEY). The
	 *   import takes eBay's figure less those units when that is the lower -
	 *   so a figure the seller lowers in Seller Hub still arrives - and
	 *   WooCommerce's otherwise (3.28.0, fix round 3).
	 * - null: nothing held.
	 *
	 * A mark with no units recorded holds as a push on its way does.
	 *
	 * @param int $product_id Product or variation.
	 * @return string|int|null
	 */
	public static function sale_hold( $product_id ) {
		$pending = get_post_meta( (int) $product_id, '_ebay_push_pending', true );
		$unsent  = false;

		foreach ( is_array( $pending ) ? $pending : array() as $token => $until ) {
			if ( ( is_array( $until ) ? (int) ( $until['until'] ?? 0 ) : (int) $until ) < time() ) {
				continue;
			}
			if ( 0 === strpos( (string) $token, 'sale-' ) ) {
				return 'sale';
			}
			if ( 'unsent' === (string) $token ) {
				$unsent = true;
			}
		}

		if ( ! $unsent ) {
			return null;
		}

		$units = get_post_meta( (int) $product_id, '_ebay_unsent_qty', true );

		// By push token since fix round 4, a single count before it.
		if ( is_array( $units ) ) {
			return max( 0, (int) array_sum( array_map( 'intval', $units ) ) );
		}

		return is_numeric( $units ) ? max( 0, (int) $units ) : 'sale';
	}

	/**
	 * The figure an import writes for a product or variation that holds its
	 * own stock, under sale_hold(): eBay's figure, WooCommerce's, or eBay's
	 * less the units of sales that never reached it.
	 *
	 * @param string|int|null $hold  From sale_hold().
	 * @param int             $held  WooCommerce's stock now.
	 * @param int             $ebay  eBay's figure.
	 * @return array stock, and kept (true when it is not eBay's figure as
	 *               eBay's sales stand: the record of them is left alone).
	 */
	private static function held_figure( $hold, $held, $ebay ) {
		if ( 'sale' === $hold ) {
			return array( 'stock' => max( 0, (int) $held ), 'kept' => true );
		}

		$less = max( 0, (int) $ebay - (int) $hold );
		if ( $less < (int) $held ) {
			return array( 'stock' => $less, 'kept' => false );
		}

		return array( 'stock' => max( 0, (int) $held ), 'kept' => true );
	}

	/**
	 * An eBay item number, or '' when the value is not one.
	 *
	 * eBay's item numbers are digits with no leading zero. Anything else -
	 * nothing, "0", or an element that kept its attributes and did not come
	 * down to a number - is treated as absent rather than looked up.
	 *
	 * @param mixed $raw Value from eBay's answer.
	 * @return string
	 */
	private static function listing_number( $raw ) {
		$value = trim( TCGiant_Sync_API::scalar_value( $raw ) );

		return 1 === preg_match( '/^[1-9]\d{0,18}$/', $value ) ? $value : '';
	}

	/**
	 * The product to move on to a relist, or 0 to import the relist as a
	 * product of its own, as every earlier version did.
	 *
	 * eBay says which listing a relist came from. That finds the product, but
	 * is not always reason enough to move it, so each of these must hold -
	 * cheapest first. Whenever one does not, the relist is imported on its own:
	 * a visible duplicate that can be deleted, never a product quietly taken
	 * from the listing it was selling. The log says which, and why.
	 *
	 * - Exactly one live product follows the old listing. Two means a duplicate
	 *   already exists, and choosing between them would be a guess. None may
	 *   mean the old listing was relisted once already and its product has
	 *   moved on to that relist: eBay lets one listing be relisted more than
	 *   once, and only one relist can have the product.
	 * - A SKU of the seller's own does not belong to some other product. The
	 *   seller's SKU names the goods, so the SKU match decides those.
	 * - A SKU of the seller's own, or a barcode, is the one the product has.
	 *   A different one names different goods: the old listing used as a
	 *   template for another item.
	 * - The old listing has really ended. A relist always comes from an ended
	 *   listing; one that is still live means a Sell Similar copy of it.
	 * - eBay's record of the old listing names this one as its relist. Sell
	 *   Similar from an ended listing - a template for the next, different
	 *   coin - can name the old listing too, and only the old listing's own
	 *   record tells the two apart.
	 * - The old listing was not a single item that sold. Relisting one of those
	 *   is nearly always the next copy - another coin, another grade - and
	 *   moving the sold product on to it would put the sold item back in stock,
	 *   under its old title if titles are not overwritten. Several that sold out
	 *   and are relisted are a restock, and are moved.
	 *
	 * @param string     $item_id     The relist.
	 * @param string     $parent_id   The listing eBay says it was relisted from.
	 * @param string     $sku         The SKU the relist would be imported with.
	 * @param string     $sku_source  Where that SKU came from - see map_ebay_to_woo().
	 * @param array|null $parent_item eBay's GetItem answer for the old listing,
	 *                                when the caller has it, so eBay is not asked
	 *                                again.
	 * @param string[]   $identifiers Every SKU and barcode the relist carries -
	 *                                see map_ebay_to_woo().
	 * @return int Product ID, or 0.
	 */
	public function relist_parent_product( $item_id, $parent_id, $sku = '', $sku_source = '', $parent_item = null, $identifiers = array() ) {
		global $wpdb;

		$this->relist_parent_figures = null;
		$this->relist_parent_error   = null;
		$this->relist_decline_final  = false;

		$item_id   = (string) $item_id;
		$parent_id = self::listing_number( $parent_id );

		if ( '' === $item_id || '' === $parent_id || $parent_id === $item_id ) {
			return 0;
		}

		// What becomes of the relist on the import path when no product is
		// moved on to it.
		$own = $this->auction_left_out
			? $item_id . ' is not imported (it is an auction, and Import eBay auctions is off)'
			: $item_id . ' is imported as a product of its own';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$holders = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_ebay_item_id' AND pm.meta_value = %s
			   AND p.post_type = 'product'
			   AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			 LIMIT 2",
			$parent_id
		) ) );

		if ( count( $holders ) > 1 ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'eBay listing %1$s is a relist of %2$s, but WC #%3$d and WC #%4$d are both linked to %2$s, so %5$s. Unlink whichever of the two should not have listing %2$s.',
				$item_id,
				$parent_id,
				$holders[0],
				$holders[1],
				$this->adopting ? 'neither is moved on to it' : $own
			) );
			return 0;
		}

		if ( empty( $holders ) ) {
			// Nobody follows the old listing any more. If a product moved off it
			// on to an earlier relist, say so: this is a second relist of one
			// listing, which eBay allows.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$follower = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				 WHERE pm.meta_key = '_ebay_previous_item_id' AND pm.meta_value = %s
				   AND p.post_type = 'product'
				   AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				 LIMIT 1",
				$parent_id
			) );

			if ( $follower ) {
				TCGiant_Sync_Logger::warning( sprintf(
					'eBay listing %1$s names %2$s as the listing it was relisted from, but no product follows %2$s any more: WC #%3$d moved on from it to listing %4$s. So %5$s.',
					$item_id,
					$parent_id,
					$follower,
					(string) get_post_meta( $follower, '_ebay_item_id', true ),
					$this->adopting ? 'it is not moved on to ' . $item_id : $own
				) );
			}
			return 0;
		}

		$candidate = $holders[0];

		// The product the seller's SKU would match, when there is one: the SKU
		// decides those.
		$sku_holder = ( 'seller' === $sku_source && '' !== (string) $sku )
			? (int) $this->find_matchable_product_id_by_sku( (string) $sku )
			: 0;

		if ( $sku_holder && $sku_holder !== $candidate && 'trash' !== get_post_status( $sku_holder ) ) {
			TCGiant_Sync_Logger::log( sprintf(
				'eBay listing %1$s is a relist of %2$s (WC #%3$d), but its SKU "%4$s" belongs to WC #%5$d, so %6$s.',
				$item_id,
				$parent_id,
				$candidate,
				$sku,
				$sku_holder,
				// On the import the SKU match comes next; elsewhere nothing does.
				$this->adopting ? sprintf( 'neither WC #%d nor WC #%d is moved on to it', $candidate, $sku_holder ) : 'the SKU decides which product it is'
			) );
			return 0;
		}

		// What happens to the relist when the product is not moved on to it.
		if ( $this->adopting ) {
			$instead = sprintf( 'WC #%d is not moved on to it', $candidate );
		} elseif ( $sku_holder === $candidate ) {
			$instead = sprintf( 'it is not moved on as a relist. Its SKU "%s" is WC #%d\'s own, so the SKU match decides, as before', $sku, $candidate );
		} else {
			$instead = sprintf( '%s; WC #%d stays on listing %s', $own, $candidate, $parent_id );
		}

		// A SKU of the seller's own or a barcode that is not the one the product
		// was imported with names other goods: the old listing used as a
		// template for a different item. The product's own SKU, for one the
		// plugin listed itself. A SKU made from an item number names no goods.
		// Any SKU or barcode the relist carries will do: one imported under a
		// barcode keeps it when the seller adds a SKU of their own on relist.
		$held = $this->other_goods_sku( $candidate, $parent_id, $sku, $sku_source, (array) $identifiers );
		if ( '' !== $held ) {
			$this->relist_decline_final = true;
			TCGiant_Sync_Logger::log( sprintf(
				'eBay listing %1$s names %2$s as the listing it was relisted from, but its %3$s "%4$s" is not WC #%5$d\'s "%6$s", so it is another item: %7$s.',
				$item_id,
				$parent_id,
				'barcode' === $sku_source ? 'barcode' : 'SKU',
				$sku,
				$candidate,
				$held,
				$instead
			) );
			return 0;
		}

		$parent = $this->relist_parent_state( $parent_id, $parent_item );

		if ( false === $parent['over'] ) {
			$this->relist_decline_final = true;
			TCGiant_Sync_Logger::warning( sprintf(
				'eBay listing %1$s names %2$s as the listing it was relisted from, but %2$s is still live on eBay, so %1$s is a Sell Similar copy rather than a relist: %3$s.',
				$item_id,
				$parent_id,
				$instead
			) );
			return 0;
		}

		if ( null === $parent['over'] || null === $parent['listed'] || null === $parent['sold'] ) {
			$this->relist_parent_error = $parent['error'];

			if ( self::defers_relist( $parent['error'] ) ) {
				TCGiant_Sync_Logger::warning( sprintf(
					'eBay listing %1$s is a relist of %2$s (WC #%3$d), but eBay could not be asked how %2$s ended (%4$s). It is looked at again once eBay can be asked.',
					$item_id,
					$parent_id,
					$candidate,
					$parent['why']
				) );
				return 0;
			}

			TCGiant_Sync_Logger::warning( sprintf(
				'eBay listing %1$s is a relist of %2$s (WC #%3$d), but eBay could not confirm how %2$s ended (%4$s): %5$s.',
				$item_id,
				$parent_id,
				$candidate,
				$parent['why'],
				$instead
			) );
			return 0;
		}

		if ( $parent['relisted_as'] !== $item_id ) {
			$this->relist_decline_final = true;
			TCGiant_Sync_Logger::warning( sprintf(
				'eBay listing %1$s names %2$s as the listing it came from, but eBay does not record %2$s as relisted as %1$s - it is a Sell Similar copy, or %2$s has been relisted again since: %3$s.',
				$item_id,
				$parent_id,
				$instead
			) );
			return 0;
		}

		if ( $parent['listed'] <= 1 && $parent['sold'] >= 1 ) {
			$this->relist_decline_final = true;
			TCGiant_Sync_Logger::log( sprintf(
				'eBay listing %1$s is a relist of %2$s, which sold its only item, so it is most likely another copy: %3$s.',
				$item_id,
				$parent_id,
				$instead
			) );
			return 0;
		}

		$this->relist_parent_figures = $parent;

		return $candidate;
	}

	/**
	 * The SKU that names the goods a product is - its SKU as eBay knows it, or
	 * its WooCommerce SKU for one the plugin listed itself - or '' when it has
	 * none, or only one made from an eBay item number, which names no goods.
	 *
	 * @param int    $product_id The product.
	 * @param string $parent_id  The listing it follows.
	 * @return string
	 */
	public function goods_sku( $product_id, $parent_id ) {
		$held = trim( (string) get_post_meta( $product_id, '_ebay_sku', true ) );
		if ( '' === $held ) {
			$product = wc_get_product( $product_id );
			$held    = $product ? trim( (string) $product->get_sku() ) : '';
		}

		return ( '' === $held || $this->is_item_number_sku( $held, $product_id, $parent_id ) ) ? '' : $held;
	}

	/**
	 * Whether a relist carries a SKU of the seller's own, or a barcode, while
	 * nothing it carries is the SKU the product's goods have: the old listing
	 * used as a template for a different item. The same rule wherever a
	 * product could be moved on to a relist - the import, the sale check, the
	 * prune, and a relist or push from here.
	 *
	 * @param int      $product_id  The product following the old listing.
	 * @param string   $parent_id   The old listing.
	 * @param string   $sku         The SKU the relist would be imported with.
	 * @param string   $sku_source  Where that SKU came from.
	 * @param string[] $identifiers Every SKU and barcode the relist carries.
	 * @return string The product's own SKU when the relist names other goods,
	 *                '' when it does not.
	 */
	public function other_goods_sku( $product_id, $parent_id, $sku, $sku_source, array $identifiers = array() ) {
		if ( ( 'seller' !== $sku_source && 'barcode' !== $sku_source ) || '' === trim( (string) $sku ) ) {
			return '';
		}

		$held = $this->goods_sku( $product_id, $parent_id );
		if ( '' === $held ) {
			return '';
		}

		foreach ( array_merge( array( (string) $sku ), $identifiers ) as $identifier ) {
			if ( is_scalar( $identifier ) && 0 === strcasecmp( $held, trim( (string) $identifier ) ) ) {
				return '';
			}
		}

		return $held;
	}

	/**
	 * Whether a product's SKU is one made from an eBay item number - the old
	 * listing's, or one it followed before - rather than one naming goods.
	 *
	 * @param string $sku        The product's SKU.
	 * @param int    $product_id The product.
	 * @param string $parent_id  The listing it follows.
	 * @return bool
	 */
	private function is_item_number_sku( $sku, $product_id, $parent_id ) {
		$numbers = array_filter( array(
			(string) $parent_id,
			(string) get_post_meta( $product_id, '_ebay_previous_item_id', true ),
		) );

		foreach ( $numbers as $number ) {
			if ( (string) $sku === $number || 0 === strcasecmp( (string) $sku, 'EBAY-' . $number ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a failed call means eBay was never asked - out of calls, or no
	 * token to ask with - so the answer is worth waiting for, rather than one
	 * that has already been tried three times and may never come.
	 *
	 * @param mixed $error What the call returned.
	 * @return bool
	 */
	public static function defers_relist( $error ) {
		return is_wp_error( $error )
			&& ( 'no_token' === $error->get_error_code() || TCGiant_Sync_Importer::is_out_of_calls( $error ) );
	}

	/**
	 * How a relist's old listing ended, from eBay: whether it is over, how many
	 * it listed and sold, and which listing eBay says it was relisted as.
	 *
	 * Always eBay's own answer. The product's record of the ending holds the
	 * figures, but not the last of these, and only that tells a relist from a
	 * Sell Similar copy of an ended listing.
	 *
	 * @param string     $parent_id   The old listing.
	 * @param array|null $parent_item eBay's answer for it, when already in hand.
	 * @return array over (true, false, or null when unknown), listed and sold
	 *               (int, or null when unknown), relisted_as ('' when none),
	 *               why when unknown, and error - the failed call, or null.
	 */
	private function relist_parent_state( $parent_id, $parent_item = null ) {
		$parent_id = (string) $parent_id;

		if ( is_array( $parent_item ) && self::listing_number( $parent_item['ItemID'] ?? '' ) === $parent_id ) {
			$item = $parent_item;
		} else {
			TCGiant_Sync_Runtime::stage( 'asking eBay how the listing it was relisted from ended (GetItem)' );
			$response = TCGiant_Sync_API::instance()->get_item( $parent_id );

			if ( is_wp_error( $response ) || empty( $response['Item'] ) || ! is_array( $response['Item'] ) ) {
				return array(
					'over'        => null,
					'listed'      => null,
					'sold'        => null,
					'relisted_as' => '',
					'why'         => is_wp_error( $response ) ? $response->get_error_message() : 'eBay returned nothing',
					'error'       => is_wp_error( $response ) ? $response : null,
				);
			}

			$item = $response['Item'];
		}

		$status = TCGiant_Sync_API::scalar_value( $item['SellingStatus']['ListingStatus'] ?? '' );
		$listed = TCGiant_Sync_API::scalar_value( $item['Quantity'] ?? '' );
		$sold   = TCGiant_Sync_API::scalar_value( $item['SellingStatus']['QuantitySold'] ?? '' );
		$over   = '' === $status ? null : 'Active' !== $status;

		if ( null !== $over ) {
			$this->parent_over[ $parent_id ] = $over;
		}

		return array(
			'over'        => $over,
			'listed'      => is_numeric( $listed ) ? (int) $listed : null,
			'sold'        => is_numeric( $sold ) ? (int) $sold : null,
			'relisted_as' => self::listing_number( $item['ListingDetails']['RelistedItemID'] ?? '' ),
			'why'         => '' === $status ? 'eBay did not say whether it is still live' : 'eBay did not say how many it listed and sold',
			'error'       => null,
		);
	}

	/**
	 * Move a product on to a relist that was never imported while it was live.
	 *
	 * For the delta sync, the per-sale stock check and the inventory prune,
	 * which meet a relist only once it has ended or sold, or that the scan
	 * missed. A coin relisted and sold within the hour is never imported, so
	 * without this its sale reached nothing, and the old product stayed in
	 * stock for a coin eBay had already sold. The caller settles the stock
	 * once the product has moved.
	 *
	 * Decided exactly as the import decides - the same SKU, worked out the same
	 * way, and the same checks. Two things more, since the import that would
	 * apply eBay's quantity comes later, if at all:
	 *
	 * - A product that looks sold on the website and put back in stock by an
	 *   older version is not moved on to a relist that has already ended:
	 *   moving it would forget that, and the product would drop off Stock
	 *   Review with nothing left to stop it being relisted. It is said once,
	 *   with the order.
	 * - Moved on to a live relist, the product is marked, so the import that
	 *   applies eBay's quantity gives the warning the import's own move would.
	 *
	 * Called only for a listing that names the listing it was relisted from.
	 *
	 * @param array      $ebay_item   The relist, as GetItem returns it.
	 * @param string     $source      Who found it, for the log.
	 * @param array|null $parent_item eBay's answer for the old listing, when
	 *                                the caller has it.
	 * @return int The product now following it, or 0.
	 */
	public function adopt_relist( array $ebay_item, $source, $parent_item = null ) {
		$this->relist_parent_error = null;

		$item_id = self::listing_number( $ebay_item['ItemID'] ?? '' );
		$parent  = self::listing_number( $ebay_item['RelistParentID'] ?? '' );

		if ( '' === $item_id || '' === $parent || $parent === $item_id ) {
			return 0;
		}

		// Some product already has it - one in the trash included, which the
		// shop deleted on purpose.
		if ( TCGiant_Sync_Importer::find_any_product_for_item( $item_id ) ) {
			return 0;
		}

		// A relist on another eBay site, when the shop imports only its own
		// (TCGiant_Sync_Sites): nothing is moved on to it, as nothing would be
		// imported from it. The one check for every caller - the delta, the
		// per-sale stock check, the hourly check, the prune and a queued
		// import. A relist whose answer names no site is let through, as
		// everywhere else.
		$relist_site = TCGiant_Sync_Sites::site_of( $ebay_item );
		if ( TCGiant_Sync_Sites::leaves_out( $relist_site ) ) {
			TCGiant_Sync_Logger::log( sprintf(
				'%1$s: eBay listing %2$s, a relist of %3$s, is on %4$s, and Import listings from is set to %5$s only, so no product is moved on to it.',
				$source,
				$item_id,
				$parent,
				TCGiant_Sync_Sites::label( $relist_site ),
				TCGiant_Sync_Sites::primary_label()
			) );
			return 0;
		}

		// The SKU and where it came from, worked out as the import would - a
		// barcode counts here as it does there.
		$mapped = $this->map_ebay_to_woo( $ebay_item );

		$this->adopting = true;
		try {
			$product_id = (int) $this->relist_parent_product(
				$item_id,
				$parent,
				(string) ( $mapped['sku'] ?? '' ),
				(string) ( $mapped['sku_source'] ?? '' ),
				$parent_item,
				(array) ( $mapped['identifiers'] ?? array() )
			);
		} finally {
			$this->adopting = false;
		}

		if ( ! $product_id ) {
			return 0;
		}

		// Read before the product moves: moving forgets how the old listing
		// ended, which is what these are worked out from.
		$facts   = TCGiant_Sync_Inventory::listing_facts( $ebay_item );
		$before  = wc_get_product( $product_id );
		$held    = ( $before && $before->managing_stock() && null !== $before->get_stock_quantity() ) ? (int) $before->get_stock_quantity() : null;
		$review  = TCGiant_Sync_Inventory::restocked_after_website_sale( $product_id );
		$figures = $this->relist_parent_figures;

		// What the relist itself sold, when it has ended.
		$r_listed = TCGiant_Sync_API::scalar_value( $ebay_item['Quantity'] ?? '' );
		$r_sold   = TCGiant_Sync_API::scalar_value( $ebay_item['SellingStatus']['QuantitySold'] ?? '' );
		$counted  = 'Ended' === $facts['status'] && empty( $ebay_item['Variations'] ) && is_numeric( $r_listed ) && is_numeric( $r_sold );
		$sold_out = $counted && (int) $r_sold >= 1 && (int) $r_sold >= (int) $r_listed;

		// An auction imported from eBay, relisted in another format: its price
		// is the opening bid, and only the import of the relist writes the
		// relist's own. Moved on here it would be on sale at that bid. Left on
		// its auction it stays off sale until the import moves it; one that
		// sold out is moved and settled to nothing.
		if ( ! $sold_out && '' !== (string) $facts['listing_type'] && 'Chinese' !== (string) $facts['listing_type']
			&& TCGiant_Sync_Inventory::is_auction_listing( $before )
			&& (string) $before->get_meta( TCGiant_Sync_Inventory::AUCTION_LISTED_KEY ) !== $parent ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Ended' === $facts['status']
					? '%1$s: eBay listing %2$s is a relist of auction %3$s in another format, and has ended. WC #%4$d, imported from that auction, stays on it, off sale here: its price is the opening bid. To sell it here, first set your own price, then push it as Fixed Price or unlink it.'
					: '%1$s: eBay listing %2$s is a relist of auction %3$s in another format. WC #%4$d, imported from that auction, is moved on to it when %2$s is imported, which also sets its price.',
				$source,
				$item_id,
				$parent,
				$product_id
			) );
			return 0;
		}

		// Moved on to a relist that has ended without selling out, a product
		// that looks sold on the website would leave Stock Review, and nothing
		// would stop it being relisted. It stays where it is - brought down to
		// what the relist left, when eBay sold some of it. One that sold out
		// is moved on and settled to nothing below, like any other: nothing is
		// left to relist, and its record then says so.
		if ( $review && 'Ended' === $facts['status'] && ! $sold_out ) {
			$now = null;
			if ( $counted && (int) $r_sold > 0 ) {
				$now = TCGiant_Sync_Inventory::lower_stock_to( $product_id, max( 0, (int) $r_listed - (int) $r_sold ) );
				if ( null !== $now && null !== $held && $now < $held ) {
					delete_transient( 'tcgiant_restocked_after_sale' );
				}
			}

			TCGiant_Sync_Logger::warning( sprintf(
				'%1$s: eBay listing %2$s is a relist of %3$s and has ended%6$s, but WC #%4$d looks as though it sold on your website (order #%5$d) and an older version of this plugin put it back in stock. It stays on listing %3$s, and on the Stock Review page, until its stock is set there.%7$s',
				$source,
				$item_id,
				$parent,
				$product_id,
				$review,
				$counted && (int) $r_sold > 0 ? sprintf( ' with %d of %d sold on eBay', (int) $r_sold, (int) $r_listed ) : '',
				null !== $now && null !== $held && $now < $held ? sprintf( ' Its stock is lowered from %d to %d, what that relist has left.', $held, $now ) : ''
			) );
			return 0;
		}

		TCGiant_Sync_Inventory::follow_relist( $product_id, $parent, $item_id, $facts, $source );

		if ( $review && $sold_out ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'%1$s: WC #%2$d now follows eBay listing %3$s, a relist of %4$s, which sold out on eBay (%5$d listed, %6$d sold), so its stock is settled from that. It had looked as though it sold on your website too (order #%7$d) - check that order, in case the item sold twice.',
				$source,
				$product_id,
				$item_id,
				$parent,
				(int) $r_listed,
				(int) $r_sold,
				$review
			) );
		}

		if ( 'Active' === $facts['status'] ) {
			if ( $review ) {
				// Said now: the record of that sale has just gone with the move.
				$this->warn_about_relist_stock( $product_id, $item_id, $parent, (int) ( $mapped['stock_quantity'] ?? 0 ), null, $review );
			} else {
				update_post_meta( $product_id, '_ebay_relist_stock_check', array(
					'item'   => $item_id,
					'parent' => $parent,
					'held'   => $held,
					'listed' => is_array( $figures ) ? (int) $figures['listed'] : null,
					'sold'   => is_array( $figures ) ? (int) $figures['sold'] : null,
				) );
			}
		}

		return $product_id;
	}

	/**
	 * The listing facts follow_relist() needs, from what map_ebay_to_woo()
	 * recorded for the listing.
	 *
	 * @param array $meta The meta map_ebay_to_woo() built.
	 * @return array See TCGiant_Sync_Inventory::listing_facts().
	 */
	private function listing_facts_from_meta( array $meta ) {
		$status = TCGiant_Sync_API::scalar_value( $meta['_ebay_listing_status'] ?? '' );

		return array(
			'status'           => ( '' === $status || 'Active' === $status ) ? 'Active' : 'Ended',
			'start_time'       => TCGiant_Sync_API::scalar_value( $meta['_ebay_start_time'] ?? '' ),
			'end_time'         => TCGiant_Sync_API::scalar_value( $meta['_ebay_end_time'] ?? '' ),
			'listing_type'     => TCGiant_Sync_API::scalar_value( $meta['_ebay_listing_type'] ?? '' ),
			'listing_duration' => TCGiant_Sync_API::scalar_value( $meta['_ebay_listing_duration'] ?? '' ),
		);
	}

	/**
	 * Say so when a relist offers more than WooCommerce held, for a reason eBay
	 * knows nothing about.
	 *
	 * eBay's quantity applies to a relist, as it does to any live listing. The
	 * typical case where that is wrong: a coin sold on the website, the plugin
	 * ended its listing - which eBay records as one listed and none sold - and
	 * the seller then relisted everything on eBay's Unsold list. WooCommerce
	 * held 0; the relist offers 1. Not said where the old listing's own sales
	 * account for the difference: five listed and five sold, relisted with
	 * five more, is a restock.
	 *
	 * @param int      $product_id   Product now following the relist.
	 * @param string   $item_id      The relist.
	 * @param string   $parent_id    The listing it came from.
	 * @param int      $offers       What the relist offers.
	 * @param int|null $held_before  What WooCommerce held, or null when it does
	 *                               not track a quantity.
	 * @param int      $review_order A website order that the settle before
	 *                               3.27.0 seems to have restocked over, or 0.
	 * @return void
	 */
	private function warn_about_relist_stock( $product_id, $item_id, $parent_id, $offers, $held_before, $review_order ) {
		if ( $review_order ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'WC #%1$d now follows eBay listing %2$s, a relist of %3$s, which offers %4$d. It looks as though it sold on your website (order #%5$d) and an older version of this plugin put it back in stock. eBay\'s figure applies, as for any live listing; if it has gone, set the stock to 0, which ends listing %2$s.',
				$product_id,
				$item_id,
				$parent_id,
				$offers,
				$review_order
			) );
			return;
		}

		$figures = $this->relist_parent_figures;

		if ( null === $held_before || $offers <= $held_before || ! is_array( $figures ) ) {
			return;
		}

		// What the old listing still had when it ended, by eBay's count. Holding
		// less than that, WooCommerce lost the rest somewhere eBay cannot see.
		if ( $held_before >= max( 0, (int) $figures['listed'] - (int) $figures['sold'] ) ) {
			return;
		}

		// The units WooCommerce lost that eBay still counted. Holding some, the
		// fix is to take those off, not to zero goods the shop still has.
		$missing = min( max( 0, (int) $figures['listed'] - (int) $figures['sold'] ), $offers ) - $held_before;

		TCGiant_Sync_Logger::warning( sprintf(
			$offers > $missing
				? 'WC #%1$d now follows eBay listing %2$s, a relist of %3$s, which offers %4$d; WooCommerce held %5$d. eBay\'s figure applies, as for any live listing. If %6$d of them sold on your website, lower the stock by %6$d; listing %2$s follows it.'
				: 'WC #%1$d now follows eBay listing %2$s, a relist of %3$s, which offers %4$d; WooCommerce held %5$d. eBay\'s figure applies, as for any live listing. If it sold on your website, set the stock to 0, which ends listing %2$s.',
			$product_id,
			$item_id,
			$parent_id,
			$offers,
			$held_before,
			$missing
		) );
	}

	private function find_matchable_product_id_by_sku( $sku ) {
		$found_id = wc_get_product_id_by_sku( $sku );
		if ( ! $found_id ) {
			return false;
		}

		// Trashed products are handled by the caller, and wc_get_product()
		// returns false for some trashed rows, so check the post type directly.
		if ( 'product_variation' === get_post_type( $found_id ) ) {
			// A listing must never be bound to a variation ID. But refusing to match
			// at all meant the parent went unrecognised and the whole listing was
			// imported again as a second product — which is what happened to
			// variable listings, where eBay's SKU routinely lands on a variation.
			// Resolve to the parent instead: same protection, no duplicate.
			$parent_id = (int) wp_get_post_parent_id( $found_id );

			if ( $parent_id && 'product' === get_post_type( $parent_id ) ) {
				TCGiant_Sync_Logger::log( sprintf(
					'SKU "%s" is held by variation #%d — matching its parent product #%d.',
					$sku, $found_id, $parent_id
				) );
				return $parent_id;
			}

			TCGiant_Sync_Logger::warning( sprintf(
				'SKU "%s" is held by variation #%d, which has no parent product. Skipping SKU match.',
				$sku, $found_id
			) );
			return false;
		}

		return (int) $found_id;
	}

	/**
	 * Save child variations for a variable product.
	 *
	 * @param int $parent_id WooCommerce Parent Product ID.
	 * @param array $variations_data Array of variation data from map_ebay_to_woo (by reference).
	 */
	/**
	 * Flatten stored image entries to a sorted list of plain URLs.
	 *
	 * @param mixed $entries Either plain URLs or [url, variation_id] pairs.
	 * @return string[]
	 */
	/**
	 * Whether a product identifier is really a stand-in for "none".
	 *
	 * @param mixed $value Candidate ISBN, UPC or EAN.
	 * @return bool
	 */
	private static function is_placeholder_identifier( $value ) {
		$normalised = strtolower( trim( (string) $value ) );

		if ( '' === $normalised ) {
			return true;
		}

		$placeholders = array(
			'does not apply',
			'doesnotapply',
			'not applicable',
			'n/a',
			'na',
			'none',
			'null',
			'unbranded',
			'-',
			'0',
		);

		return in_array( $normalised, $placeholders, true );
	}

	private static function plain_image_urls( $entries ) {
		$urls = array();

		foreach ( (array) $entries as $entry ) {
			if ( is_array( $entry ) ) {
				$entry = $entry[0] ?? '';
			}
			$entry = trim( (string) $entry );
			if ( '' !== $entry ) {
				$urls[] = $entry;
			}
		}

		$urls = array_values( array_unique( $urls ) );
		sort( $urls );

		return $urls;
	}

	/**
	 * Build a stable identifier for a variation from its attribute values.
	 *
	 * @param array $attributes Attribute name => value.
	 * @return string Empty when the variation has no usable attributes.
	 */
	private static function variation_signature( $attributes ) {
		$normalised = array();

		foreach ( (array) $attributes as $name => $value ) {
			$key = sanitize_title( (string) $name );
			if ( '' === $key ) {
				continue;
			}
			$normalised[ $key ] = strtolower( trim( (string) $value ) );
		}

		if ( empty( $normalised ) ) {
			return '';
		}

		ksort( $normalised );

		$parts = array();
		foreach ( $normalised as $key => $value ) {
			$parts[] = $key . '=' . $value;
		}

		return implode( '|', $parts );
	}

	private function save_variations( $parent_id, &$variations_data ) {
		$product = wc_get_product( $parent_id );
		$existing_variations = $product->get_children();
		$processed_ids = array();

		// Index what is already on the product by its attribute combination.
		//
		// Variations used to be matched by SKU alone, but eBay variations very
		// often carry no SKU — it is optional and most sellers leave it blank.
		// With nothing to match on, every sync built a fresh set of variations
		// and retired the previous set, so the product accumulated hidden
		// duplicates holding the same attributes and prices while stock moved to
		// the new copies. That is what "the item gets reduplicated when it sells"
		// looks like from outside: a sale is a modification, so the delta sync
		// revisits the item and does it again.
		//
		// The attribute combination is what actually identifies a variation
		// within a listing, so it is the right thing to match on.
		$settings        = TCGiant_Sync_OAuth::instance()->get_settings();
		$overwrite_price = isset( $settings['overwrite_price'] ) ? $settings['overwrite_price'] : '1';

		$by_signature = array();
		foreach ( $existing_variations as $existing_id ) {
			$existing = wc_get_product( $existing_id );
			if ( ! $existing ) {
				continue;
			}
			$sig = self::variation_signature( $existing->get_attributes() );
			if ( '' !== $sig && ! isset( $by_signature[ $sig ] ) ) {
				$by_signature[ $sig ] = (int) $existing_id;
			}
		}
		
		foreach ( $variations_data as &$var_data ) {
			$variation_id = 0;

			// WC needs taxonomy slugs or lowercase attribute names as keys.
			$var_attrs = array();
			foreach ( $var_data['attributes'] as $name => $val ) {
				$var_attrs[ sanitize_title( $name ) ] = $val;
			}

			// eBay's own SKU for the variation, before any suffix below. A stock
			// push names the variation to eBay by it, and once the SKU here has
			// been made unique it no longer matches eBay's.
			$ebay_var_sku = is_scalar( $var_data['sku'] ?? '' ) ? (string) ( $var_data['sku'] ?? '' ) : '';

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

			// No SKU, or one that belonged to something else: fall back to the
			// attribute combination, which is stable for the life of the listing.
			if ( ! $variation_id ) {
				$signature = self::variation_signature( $var_attrs );
				if ( '' !== $signature && isset( $by_signature[ $signature ] ) ) {
					$variation_id = $by_signature[ $signature ];
					// Claim it so two incoming variations cannot take the same one.
					unset( $by_signature[ $signature ] );
				}
			}

			$is_new_variation = ! $variation_id;

			$variation = $variation_id ? wc_get_product( $variation_id ) : new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			
			$variation->set_sku( $var_data['sku'] );
			// Honour "Overwrite Price" here too.
			//
			// The parent product has always respected it, but variations did not:
			// their prices were written from eBay on every sync no matter what the
			// setting said. A seller who priced their variations differently in
			// WooCommerce watched that work get undone hourly, and turning the
			// setting off changed nothing because it was never consulted here.
			//
			// A variation being created now has no price to preserve, so it always
			// takes eBay's.
			if ( $is_new_variation || '1' === $overwrite_price ) {
				$variation->set_regular_price( $var_data['regular_price'] );
				if ( ! empty( $var_data['sale_price'] ) ) {
					$variation->set_sale_price( $var_data['sale_price'] );
				} else {
					$variation->set_sale_price( '' );
				}
			}
			// A variation whose stock the parent keeps is left as it is: its
			// "Manage stock" stays off and the parent holds the stock it shares
			// with the others. Setting it here gave each one the whole of that
			// stock as its own - 5 shared became 5 each (3.28.0, fix round 3).
			// The parent's figure is worked out after (settle_pool()).
			$pooled_var = ! $is_new_variation && TCGiant_Sync_Inventory::stock_held_by_parent( $variation );

			// A variation keeps its stock while a website sale of it is on its
			// way to eBay, and its lower stock while a change made in
			// WooCommerce is, as a product does. A sale whose push never got
			// there holds only as far as its units (sale_hold()).
			$var_kept        = false;
			$var_unsent_sent = false;
			$var_hold        = ! $pooled_var && ! $is_new_variation && $variation->managing_stock() && null !== $variation->get_stock_quantity()
				? self::sale_hold( $variation_id )
				: null;
			if ( null !== $var_hold ) {
				$figure = self::held_figure( $var_hold, (int) $variation->get_stock_quantity(), (int) $var_data['stock_quantity'] );
				if ( 'sale' === $var_hold ) {
					TCGiant_Sync_Logger::log( sprintf(
						'Variation #%d kept at %d rather than eBay\'s %d: a website sale of it is still on its way to eBay.',
						$variation_id,
						(int) $variation->get_stock_quantity(),
						(int) $var_data['stock_quantity']
					) );
				} elseif ( $figure['kept'] ) {
					TCGiant_Sync_Logger::log( sprintf(
						'Variation #%d kept at %d rather than eBay\'s %d: a website sale of %d of it never reached eBay.',
						$variation_id,
						(int) $variation->get_stock_quantity(),
						(int) $var_data['stock_quantity'],
						(int) $var_hold
					) );
				} else {
					TCGiant_Sync_Logger::log( sprintf(
						'Variation #%d set to %d: eBay\'s %d less the %d of a website sale of it that never reached eBay.',
						$variation_id,
						$figure['stock'],
						(int) $var_data['stock_quantity'],
						(int) $var_hold
					) );
				}
				$var_data['stock_quantity'] = $figure['stock'];
				$var_kept                   = $figure['kept'];
				$var_unsent_sent            = 'sale' !== $var_hold && ! $figure['kept'];
			} elseif ( ! $pooled_var && ! $is_new_variation && $variation->managing_stock() && null !== $variation->get_stock_quantity()
				&& (int) $variation->get_stock_quantity() < (int) $var_data['stock_quantity']
				&& self::push_pending( $variation_id ) ) {
				TCGiant_Sync_Logger::log( sprintf(
					'Variation #%d kept at %d rather than eBay\'s %d: a stock change made in WooCommerce is still on its way to eBay.',
					$variation_id,
					(int) $variation->get_stock_quantity(),
					(int) $var_data['stock_quantity']
				) );
				$var_data['stock_quantity'] = (int) $variation->get_stock_quantity();
				$var_kept                   = true;
			}

			if ( ! $pooled_var ) {
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( $var_data['stock_quantity'] );
			}
			$variation->set_status( 'publish' );
			
			$variation->set_attributes( $var_attrs );
			
			// Its record of eBay's sales written with its stock, as the
			// product's is (release fix round 5).
			$var_record_sold = null !== ( $var_data['sold'] ?? null ) ? (int) $var_data['sold'] : null;
			$var_record_to   = (int) $var_data['stock_quantity'];
			$var_record_item = (string) get_post_meta( $parent_id, '_ebay_item_id', true );
			$var_armed       = ! $is_new_variation && ! $var_kept && ! $pooled_var && '' !== $ebay_var_sku && null !== $var_record_sold && $variation->get_id() > 0
				? TCGiant_Sync_Inventory::arm_record(
					$variation,
					null,
					function () use ( $variation, $var_record_item, $ebay_var_sku, $var_record_sold ) {
						update_post_meta( (int) $variation->get_id(), '_ebay_sold_seen', array( 'item' => $var_record_item, 'sku' => $ebay_var_sku, 'sold' => $var_record_sold ) );
					},
					function ( $now ) use ( $var_record_to ) {
						return (int) $now === $var_record_to;
					}
				)
				: null;
			try {
				$saved_var_id = $variation->save();
			} finally {
				TCGiant_Sync_Inventory::disarm_record( $var_armed );
			}
			if ( $saved_var_id ) {
				$processed_ids[] = $saved_var_id;
				$var_data['variation_id'] = $saved_var_id;

				if ( '' !== $ebay_var_sku ) {
					update_post_meta( $saved_var_id, '_ebay_variation_sku', $ebay_var_sku );
				} else {
					delete_post_meta( $saved_var_id, '_ebay_variation_sku' );
				}

				// Lowered by the units of a sale of it eBay never heard of: eBay
				// is told (fix round 4).
				if ( $var_unsent_sent ) {
					TCGiant_Sync_Inventory::queue_after_ebay_sale( (int) $saved_var_id );
				}

				// eBay's figure for the variation is its stock now: every eBay
				// sale of it so far is in it (TCGiant_Sync_Inventory::SOLD_SEEN_KEY).
				// One already here has had its record from its armed save, as
				// the product's (x22b); a new one is checked here.
				if ( $is_new_variation && ! $var_kept && ! $pooled_var && '' !== $ebay_var_sku && null !== ( $var_data['sold'] ?? null )
					&& (int) get_post_meta( $saved_var_id, '_stock', true ) === (int) $var_data['stock_quantity'] ) {
					update_post_meta( $saved_var_id, '_ebay_sold_seen', array( 'item' => (string) get_post_meta( $parent_id, '_ebay_item_id', true ), 'sku' => $ebay_var_sku, 'sold' => (int) $var_data['sold'] ) );
				}
			}
		}
		
		// Retire variations that are no longer present on eBay.
		//
		// These are NOT force-deleted. A partial or degraded eBay response
		// would otherwise permanently destroy variations along with their links
		// to existing order line items. Zeroing stock and hiding them is
		// reversible; deletion is not.
		foreach ( $existing_variations as $existing_id ) {
			if ( in_array( $existing_id, $processed_ids, true ) ) {
				continue;
			}

			$var_to_retire = wc_get_product( $existing_id );
			if ( ! $var_to_retire ) {
				continue;
			}

			TCGiant_Sync_Inventory::begin_ebay_origin();
			try {
				$var_to_retire->set_stock_quantity( 0 );
				$var_to_retire->set_stock_status( 'outofstock' );
				$var_to_retire->set_status( 'private' );
				$var_to_retire->save();
			} finally {
				TCGiant_Sync_Inventory::end_ebay_origin();
			}

			TCGiant_Sync_Logger::log( sprintf(
				'Variation #%d is no longer on eBay — set to private and out of stock (not deleted).',
				$existing_id
			) );
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
	private function migrate_images_from_orphan( $new_product_id, $ebay_sku, $incoming_images = array() ) {
		global $wpdb;

		// _ebay_sku is only unique when it came from the eBay Item ID. Just as
		// often it is an EAN, a UPC or a seller SKU, none of which a seller is
		// obliged to keep unique — the same barcode legitimately appears on
		// several listings. Matching on it alone let a brand new product adopt the
		// photographs of an unrelated trashed product, and because this also
		// copies the localisation hash and marks the images as done, the wrong
		// pictures then stuck: no later sync would revisit them.
		//
		// A genuine relist keeps the same photographs, so requiring the image sets
		// to match preserves the case this exists for and removes the rest.
		$incoming = self::plain_image_urls( $incoming_images );

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

			// Only adopt another product's images when they are demonstrably the
			// same photographs. Anything else is a different listing that merely
			// shares an identifier.
			$donor_images = self::plain_image_urls( get_post_meta( $donor_id, '_tcgiant_external_image_urls', true ) );
			if ( empty( $incoming ) || $donor_images !== $incoming ) {
				TCGiant_Sync_Logger::log( sprintf(
					'Not taking images from trashed WC #%d for WC #%d: same SKU "%s" but a different set of photos.',
					$donor_id, $new_product_id, $ebay_sku
				) );
				continue;
			}

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
			// Cache a failed/empty lookup only briefly. Storing an empty map for
			// a full hour meant every product imported in that window silently
			// lost its categories.
			set_transient( 'tcgiant_sync_store_cat_map', $map, empty( $map ) ? 60 : 3600 );
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
		return TCGiant_Sync_API::scalar_value( $raw );
	}

	/**
	 * Parse the unit string from an eBay measurement field.
	 *
	 * When eBay's XML→JSON conversion strips @attributes (common with UK/metric sites),
	 * falls back to the configured marketplace to determine the unit system.
	 *
	 * @param mixed  $raw         Raw measurement value (may contain @attributes with unit).
	 * @param string $type        Context: 'weight' or 'dimension'.
	 * @param string $item_system 'metric' or 'imperial' from the listing's own site, or ''.
	 * @return string Unit string (e.g., 'lbs', 'kg', 'in', 'cm').
	 */
	private function parse_measurement_unit( $raw, $type = 'weight', $item_system = '' ) {
		if ( is_array( $raw ) && isset( $raw['@attributes']['unit'] ) ) {
			return (string) $raw['@attributes']['unit'];
		}

		$imperial_unit = ( 'weight' === $type ) ? 'lbs' : 'in';
		$metric_unit   = ( 'weight' === $type ) ? 'kg' : 'cm';

		// This used to answer lbs or kg for dimensions too, which the dimension
		// converter does not recognise, so the value went through unconverted.
		if ( is_array( $raw ) && isset( $raw['@attributes']['measurementSystem'] ) ) {
			return 'English' === $raw['@attributes']['measurementSystem'] ? $imperial_unit : $metric_unit;
		}

		// The listing says which site it is on, and the site fixes the system.
		// This is what was missing: without it the answer came from the
		// marketplace setting, which defaults to the United States and which a
		// new shop has usually never been shown.
		if ( 'metric' === $item_system ) {
			return $metric_unit;
		}
		if ( 'imperial' === $item_system ) {
			return $imperial_unit;
		}

		return $this->get_marketplace_default_unit( $type );
	}

	/**
	 * Which measurement system a listing's figures are in.
	 *
	 * Every item eBay returns carries the site it is listed on, and the site
	 * decides the system: the United States is imperial, everything else the
	 * plugin supports is metric. So the answer is on the listing, and asking
	 * the listing needs no configuration and is right per item - a shop that
	 * lists on two sites gets each one right.
	 *
	 * The unit attributes eBay puts on the values themselves would be better
	 * still, but they rarely survive the trip through the XML-to-array
	 * conversion, which is why the code fell back to the marketplace setting
	 * and why that fallback was the whole problem.
	 *
	 * @param array $ebay_item Decoded item from any Trading API listing call.
	 * @return string 'metric', 'imperial', or '' when the site is not known.
	 */
	private function item_measurement_system( $ebay_item ) {
		$site = isset( $ebay_item['Site'] ) ? TCGiant_Sync_API::scalar_value( $ebay_item['Site'] ) : '';

		if ( '' === $site ) {
			return '';
		}

		$this->maybe_adopt_marketplace( $site );

		if ( 'US' === $site || 'eBayMotors' === $site ) {
			return 'imperial';
		}

		foreach ( TCGiant_Sync_API::MARKETPLACES as $m ) {
			if ( $m['site'] === $site ) {
				return 'metric';
			}
		}

		// A site the plugin does not know. Say nothing rather than guess.
		return '';
	}

	/**
	 * Take the marketplace from the first listing seen, if none was ever set.
	 *
	 * The marketplace setting drives more than units: the exporter takes the
	 * listing site, the currency and the API site header from it. Left at its
	 * default, a shop in the United Kingdom would push products onto eBay US
	 * priced in dollars. The only place a new shop is asked for it was a wizard
	 * step nobody could reach, so most never set it.
	 *
	 * Only fills a blank. A marketplace someone chose, even wrongly, is theirs
	 * to change. Runs at most once per request.
	 *
	 * @param string $site Site code from a listing, e.g. UK.
	 * @return void
	 */
	private function maybe_adopt_marketplace( $site ) {
		static $done = false;

		if ( $done ) {
			return;
		}
		$done = true;

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );

		if ( ! empty( $settings['marketplace'] ) ) {
			return;
		}

		// Not while the shop imports only its Primary eBay Marketplace's
		// listings. That keys on the marketplace, so taking it from the first
		// listing seen would make that listing's site the one kept for the rest
		// of the run: a United Kingdom listing mapped first would leave out every
		// United States one after it. Until a marketplace is saved the filter is
		// not in force, everything is imported, and Settings says to save one.
		if ( TCGiant_Sync_Sites::PRIMARY === ( $settings[ TCGiant_Sync_Sites::SETTING ] ?? '' ) ) {
			return;
		}

		foreach ( TCGiant_Sync_API::MARKETPLACES as $id => $m ) {
			if ( $m['site'] !== $site ) {
				continue;
			}

			// A marketplace another request stored since this one began is the
			// shop's: it stands.
			if ( ! empty( TCGiant_Sync_Inventory::settings_row()['marketplace'] ) ) {
				return;
			}

			// Only its own key, onto the settings as the database holds them
			// now: this request's copy dates from the start of an import batch,
			// and a Disconnect saved since must stand.
			TCGiant_Sync_Inventory::save_settings_keys( array( 'marketplace' => $id ) );

			TCGiant_Sync_Logger::log( sprintf(
				'Marketplace was not set. Taking %s from your eBay listings; change it in Settings if that is wrong.',
				$m['label']
			) );
			return;
		}
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
			$value = $value * 0.45359237;
		} elseif ( 'lbs' === $from_unit && 'oz' === $store_unit ) {
			$value = $value * 16;
		} elseif ( 'lbs' === $from_unit && 'g' === $store_unit ) {
			$value = $value * 453.59237;
		} elseif ( 'kg' === $from_unit && 'lbs' === $store_unit ) {
			$value = $value * 2.20462262;
		} elseif ( 'kg' === $from_unit && 'oz' === $store_unit ) {
			$value = $value * 35.2739619;
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
