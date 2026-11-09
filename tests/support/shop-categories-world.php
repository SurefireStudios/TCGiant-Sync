<?php
/**
 * A world for test-shop-categories.php (3.33.0): the real exporter AND the real
 * mapper in one process, on one played WordPress, so a listing can be pushed,
 * imported, updated and imported again.
 *
 * It is template-world.php with these added:
 *
 * - terms as WordPress keeps them. term_exists() tries the slug, then the name,
 *   under a parent when it is given one (0 is the top level, null anywhere),
 *   and in term_id order; a term's name is stored with "&" as "&amp;"; a second
 *   term of one name has a slug of its own, with its parent's slug after it
 *   under a parent, or a number; wp_insert_term() refuses exactly that name
 *   already at that level, in that case, naming the term, where term_exists()
 *   would have found it in any case. Each of these was read from WordPress 7.1.3,
 *   and run there (the notes of this release say how): this is a stand-in for
 *   speed, not the proof.
 * - products with setters, which keep their categories and tags, and a save;
 * - eBay's GetStore played at TCGiant_Sync_API::trading_api_request() from its
 *   XML, turned into an array as the API class turns it, and every Trading call
 *   recorded.
 *
 * @package TCGiant_Sync
 */

/** A term's name as WordPress stores it: "pre_term_name" leaves "&" as "&amp;". */
function shop_world_stored_name( $name ) {
	return htmlspecialchars( trim( (string) $name ), ENT_NOQUOTES, 'UTF-8', false );
}

/** As term_exists(): the slug, then the name, under a parent when given one. */
function term_exists( $term, $taxonomy = '', $parent = null ) {
	$found = function ( $id ) {
		return array( 'term_id' => (string) $id, 'term_taxonomy_id' => (string) $id );
	};

	if ( 'product_tag' === $taxonomy ) {
		$id = array_search( $term, $GLOBALS['W']['tags'], true );
		return false === $id ? null : $found( $id );
	}

	if ( is_int( $term ) ) {
		return isset( $GLOBALS['W']['terms'][ $term ] ) ? $found( $term ) : null;
	}

	$term = trim( (string) $term );
	if ( '' === $term ) {
		return null;
	}

	$GLOBALS['W']['term_lookups'][] = array( $term, $parent );

	// Made by another request just after this one asked.
	if ( in_array( $term, $GLOBALS['W']['unseen'] ?? array(), true ) ) {
		return null;
	}

	$terms = $GLOBALS['W']['terms'];
	ksort( $terms );

	foreach ( array( 'slug', 'name' ) as $by ) {
		foreach ( $terms as $id => $row ) {
			if ( is_numeric( $parent ) && (int) $row['parent'] !== (int) $parent ) {
				continue;
			}
			$same = 'slug' === $by
				? (string) $row['slug'] === sanitize_title( $term )
				: strtolower( (string) $row['name'] ) === strtolower( shop_world_stored_name( $term ) );
			if ( $same ) {
				return $found( $id );
			}
		}
	}

	return null;
}

/** As wp_insert_term(). */
function wp_insert_term( $name, $taxonomy = '', $args = array() ) {
	if ( 'product_tag' === $taxonomy ) {
		$id = ++$GLOBALS['W']['next_term'];
		$GLOBALS['W']['tags'][ $id ] = $name;
		return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
	}

	$parent = (int) ( $args['parent'] ?? 0 );
	$stored = shop_world_stored_name( $name );

	foreach ( $GLOBALS['W']['terms'] as $id => $row ) {
		if ( (int) $row['parent'] === $parent && (string) $row['name'] === $stored ) {
			return new WP_Error( 'term_exists', 'A term with the name provided already exists with this parent.', $id );
		}
	}

	$taken = function ( $slug ) {
		foreach ( $GLOBALS['W']['terms'] as $row ) {
			if ( (string) $row['slug'] === $slug ) {
				return true;
			}
		}
		return false;
	};

	$slug = sanitize_title( $name );
	if ( $taken( $slug ) && $parent ) {
		$slug .= '-' . $GLOBALS['W']['terms'][ $parent ]['slug'];
	}
	if ( $taken( $slug ) ) {
		$base = $slug;
		$n    = 2;
		while ( $taken( $base . '-' . $n ) ) {
			$n++;
		}
		$slug = $base . '-' . $n;
	}

	$id = ++$GLOBALS['W']['next_term'];
	$GLOBALS['W']['terms'][ $id ] = array( 'parent' => $parent, 'name' => $stored, 'slug' => $slug );
	$GLOBALS['W']['terms_made'][] = $id;

	return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
}

/** As get_terms() for the two lists the plugin asks for. */
function get_terms( $args = array() ) {
	$GLOBALS['W']['term_lists'] = ( $GLOBALS['W']['term_lists'] ?? 0 ) + 1;
	if ( ! empty( $GLOBALS['W']['no_terms'] ) ) {
		return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
	}

	$terms = $GLOBALS['W']['terms'];
	ksort( $terms );

	if ( 'ids' === ( $args['fields'] ?? '' ) ) {
		return array_keys( $terms );
	}

	$out = array();
	foreach ( $terms as $id => $row ) {
		$out[] = (object) array( 'term_id' => $id, 'name' => $row['name'], 'parent' => (int) $row['parent'], 'slug' => $row['slug'] );
	}
	return $out;
}

/** The world's own term, for the tests to set up. */
function make_term( $name, $parent = 0 ) {
	$made = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
	return (int) $made['term_id'];
}

function get_post_status( $id ) { return 'publish'; }
function get_post_type( $id ) { return 'product'; }
function get_post_field( $field, $id ) { return ''; }
function wp_get_post_parent_id( $id ) { return 0; }
function wc_tax_enabled() { return false; }
function wc_prices_include_tax() { return false; }
function wc_get_product_id_by_sku( $sku ) {
	foreach ( $GLOBALS['W']['products'] as $id => $product ) {
		if ( '' !== (string) $sku && $id && (string) $product->get_sku() === (string) $sku ) {
			return (int) $id;
		}
	}
	return 0;
}
function taxonomy_exists( $taxonomy ) { return false; }
function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) { return array(); }
function get_user_by( $field, $value ) { return false; }

require __DIR__ . '/template-world.php';

$GLOBALS['W']['next_term']    = 100;
$GLOBALS['W']['term_lookups'] = array();
$GLOBALS['W']['terms_made']   = array();

/**
 * eBay, with answers that can change from one call to the next: $queue is, by
 * call name, the answers to give in turn before $answers is read.
 */
class Shop_API extends Template_World_API {
	public $queue = array();
	/** Per call, how many times more each sending went out (TCGiant_Sync_API::resends()). */
	public $resent = array();
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		if ( ! empty( $this->resent[ $call_name ] ) ) {
			$this->resends += (int) array_shift( $this->resent[ $call_name ] );
		}
		if ( ! empty( $this->queue[ $call_name ] ) ) {
			$this->calls[] = array( $call_name, $xml_body );
			return array_shift( $this->queue[ $call_name ] );
		}
		return parent::trading_api_request( $call_name, $xml_body, $allow_retry, $max_body_bytes );
	}
}
$GLOBALS['API'] = new Shop_API();
$tcg_slot->setValue( null, $GLOBALS['API'] );

class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS         = array();
	const AUCTION_LISTED_KEY  = '_ebay_auction_listed_item';
	const AUCTION_FIGURE_KEYS = array();
	public static function __callStatic( $name, $args ) { return null; }
	public static function restocked_after_website_sale( $product_id ) { return 0; }
	public static function listing_already_settled( $product_id, $item_id ) { return false; }
	public static function apply_ended_listing_stock( $product_id, $listed, $sold, $item = null ) { return (int) wc_get_product( $product_id )->get_stock_quantity(); }
	public static function ended_by_shop( $product_id, $item_id ) { return false; }
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
	public static function table_exists() { return false; }
	public static function get_by_product( $id ) { return null; }
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Image_Localizer {
	public static function set_external_images() {}
	public static function __callStatic( $name, $args ) { return null; }
}

/**
 * A product with what an import writes to one: its categories and tags are
 * kept, the rest goes nowhere. New ones are numbered at their save.
 */
class Shop_Product extends WC_Product {
	public function __construct( $id = 0, array $data = array() ) {
		parent::__construct( $id, $data );
	}
	public function get_category_ids( $context = 'view' ) {
		$GLOBALS['W']['category_reads'] = ( $GLOBALS['W']['category_reads'] ?? 0 ) + 1;
		return $this->data['categories'];
	}
	public function set_category_ids( $ids ) { $this->data['categories'] = array_values( array_unique( array_map( 'intval', (array) $ids ) ) ); }
	public function get_tag_ids( $context = 'view' ) { return $this->data['tags'] ?? array(); }
	public function set_tag_ids( $ids ) { $this->data['tags'] = array_map( 'intval', (array) $ids ); }
	public function set_name( $name ) { $this->data['name'] = $name; }
	public function set_description( $text ) { $this->data['description'] = $text; }
	public function set_sku( $sku ) { $this->data['sku'] = (string) $sku; }
	public function set_attributes( $attributes ) { $this->data['attributes'] = (array) $attributes; }
	public function get_attributes() { return (array) $this->data['attributes']; }
	public function save() {
		if ( 0 === $this->id ) {
			$this->id = ++$GLOBALS['W']['next_id'];
			$GLOBALS['W']['products'][ $this->id ] = $this;
			unset( $GLOBALS['W']['products'][0] );
		}
		return $this->id;
	}
	public function __call( $name, $args ) { return null; }
}
class WC_Product_Simple extends Shop_Product {}
class Shop_Product_Variable extends WC_Product_Variable {
	public $children = array();
	public function get_children() { return $this->children; }
	public function get_category_ids( $context = 'view' ) { return $this->data['categories']; }
}
class Shop_Product_Variation extends WC_Product_Variation {
	public function get_parent_id() { return (int) ( $this->data['parent'] ?? 0 ); }
	public function get_variation_attributes() { return array( 'attribute_size' => $this->data['size'] ?? '' ); }
	public function get_attributes() { return array( 'size' => $this->data['size'] ?? '' ); }
	/** As WooCommerce's: none of its own to read here. */
	public function get_category_ids( $context = 'view' ) { return array(); }
}

require $ROOT . '/includes/class-tcgiant-sync-mapper.php';

/** eBay's answer, as the API class turns its XML into an array. */
function ebay_answer( $xml ) {
	$parsed = simplexml_load_string( '<?xml version="1.0" encoding="UTF-8"?>' . $xml, 'SimpleXMLElement', LIBXML_NOCDATA );
	return json_decode( wp_json_encode( $parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), true );
}

/**
 * A category of GetStore's answer.
 *
 * @param string $id       CategoryID.
 * @param string $name     Name, as text: the XML's entities are escaped here.
 * @param string $children ChildCategory elements.
 * @return string
 */
function custom_category( $id, $name, $children = '' ) {
	return '<CustomCategory><CategoryID>' . $id . '</CategoryID><Name>' . htmlspecialchars( $name, ENT_XML1 ) . '</Name><Order>1</Order>' . $children . '</CustomCategory>';
}

/** The same, under a parent. */
function child_category( $id, $name, $children = '' ) {
	return '<ChildCategory><CategoryID>' . $id . '</CategoryID><Name>' . htmlspecialchars( $name, ENT_XML1 ) . '</Name><Order>1</Order>' . $children . '</ChildCategory>';
}

/** GetStore's answer, with eBay's own Other first, as in its sample. */
function store_with( $categories ) {
	$GLOBALS['API']->answers['GetStore'] = ebay_answer(
		'<GetStoreResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack><Store><Name>Shop</Name><CustomCategories>'
		. custom_category( '1', 'Other' ) . $categories
		. '</CustomCategories></Store></GetStoreResponse>'
	);
	delete_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT );
}

/** The card shop: two parents, each with a "Scarlet & Violet", and a parent with a child. */
function card_shop() {
	store_with(
		custom_category( '9001', 'English Single Cards',
			child_category( '9011', 'Scarlet & Violet' ) . child_category( '9012', 'Sword & Shield' ) )
		. custom_category( '9002', 'Japanese Single Cards',
			child_category( '9021', 'Scarlet & Violet' ) )
		. custom_category( '9003', 'Sealed' )
		. custom_category( '9004', 'Graded',
			child_category( '9041', 'PSA', child_category( '9051', 'PSA 10' ) ) )
	);
}

/** How many times each Trading call was made since the last asked. */
function calls_named( $name ) {
	$n = 0;
	foreach ( $GLOBALS['API']->calls as $call ) {
		if ( $name === $call[0] ) {
			$n++;
		}
	}
	return $n;
}

/** The world's categories and products gone, with the shop's settings as a push needs them. */
function fresh_shop( array $settings = array() ) {
	$GLOBALS['W']['terms']        = array();
	$GLOBALS['W']['tags']         = array();
	$GLOBALS['W']['terms_made']   = array();
	$GLOBALS['W']['term_lookups'] = array();
	$GLOBALS['W']['meta']         = array();
	$GLOBALS['W']['products']     = array();
	$GLOBALS['W']['log']          = array();
	$GLOBALS['W']['transients']   = array();
	$GLOBALS['W']['next_term']    = 100;
	unset( $GLOBALS['W']['options'][ TCGiant_Sync_Shop_Categories::MAP_OPTION ] );
	$GLOBALS['API']->calls   = array();
	$GLOBALS['API']->answers = array();
	$GLOBALS['API']->queue   = array();
	$GLOBALS['API']->resent  = array();
	TCGiant_Sync_OAuth::$settings = array_merge( array(
		'marketplace'               => 'EBAY_US',
		'export_category_id'        => '33564',
		'export_condition_id'       => '3000',
		'export_location'           => 'Austin, TX',
		'export_postal_code'        => '78701',
		'export_fulfillment_policy' => '250000011',
		'export_return_policy'      => '250000012',
		'export_payment_policy'     => '250000013',
		'export_listing_type'       => 'FixedPriceItem',
		'export_listing_duration'   => 'GTC',
	), $settings );
}

/** The shop's map, as Save leaves it. */
function shop_map( array $map ) {
	update_option( TCGiant_Sync_Shop_Categories::MAP_OPTION, $map, false );
}

/** A product of the shop's in these categories, with a photo on the website. */
function shop_product( $id, array $categories, array $meta = array(), array $data = array() ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/item-' . $id . '.jpg';
	$product = new Shop_Product( $id, array_merge( array( 'name' => 'Item ' . $id, 'description' => 'Described ' . $id . '.', 'regular_price' => '25.00', 'stock' => 2, 'sku' => 'SKU-' . $id, 'image_id' => 9000 + $id, 'categories' => $categories ), $data ) );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}

/** What a product carries once the plugin has pushed it. */
function pushed_meta( $item_id, array $more = array() ) {
	return array_merge( array( '_ebay_item_id' => $item_id, '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-10-01 10:00:00' ), $more );
}

/** What a product carries once it was imported, keeping eBay's values. */
function imported_meta( $item_id, array $more = array() ) {
	return array_merge( array( '_ebay_item_id' => $item_id, '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_origin' => 'import', '_ebay_live' => array( 'item_id' => $item_id, 'category_id' => '33564', 'condition_id' => '3000', 'policies' => array( 'shipping' => '1', 'return' => '2', 'payment' => '3' ), 'location' => 'Columbus, OH' ) ), $more );
}

/** A push (do_push()): what it returned. */
function push( WC_Product $product ) {
	return private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
}

/** The body of the last Trading call of a name, or ''. */
function body_of( $name ) {
	$found = '';
	foreach ( $GLOBALS['API']->calls as $call ) {
		if ( $name === $call[0] ) {
			$found = (string) $call[1];
		}
	}
	return $found;
}

/** The Storefront of a body, or ''. */
function storefront_of( $body ) {
	return preg_match( '#<Storefront>.*?</Storefront>#s', (string) $body, $m ) ? $m[0] : '';
}

/** A listing as GetItem answers, in these shop categories. */
function listing_of( $item_id, $store1, $store2 = '', array $more = array() ) {
	$storefront = array( 'StoreCategoryID' => $store1, 'StoreCategory2ID' => '' !== $store2 ? $store2 : '0', 'StoreURL' => 'https://www.ebay.com/str/shop' );
	return array_merge( array(
		'ItemID'          => $item_id,
		'Title'           => 'Item ' . $item_id,
		'SKU'             => 'SKU-' . $item_id,
		'Site'            => 'US',
		'Currency'        => 'USD',
		'Quantity'        => '3',
		'ListingType'     => 'FixedPriceItem',
		'ListingDuration' => 'GTC',
		'ListingDetails'  => array( 'StartTime' => '2026-01-05T10:00:00.000Z', 'EndTime' => '2026-12-05T10:00:00.000Z' ),
		'SellingStatus'   => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0', 'CurrentPrice' => '25.0' ),
		'PrimaryCategory' => array( 'CategoryID' => '33564', 'CategoryName' => 'Toys:Cards:Singles' ),
		'Storefront'      => $storefront,
	), $more );
}

/** The import of a listing, as every import path makes it. */
function import( array $item ) {
	$mapper = new TCGiant_Sync_Mapper();
	$data   = $mapper->map_ebay_to_woo( $item );
	return $mapper->save_as_product( $data );
}

/** The words the log holds, joined, of a level or of all. */
function logged( $level = '' ) {
	$out = array();
	foreach ( $GLOBALS['W']['log'] as $entry ) {
		if ( '' === $level || $level === $entry[0] ) {
			$out[] = $entry[1];
		}
	}
	return $out;
}

/** How many log lines hold these words. */
function logged_with( $words ) {
	return count( array_filter( logged(), function ( $line ) use ( $words ) {
		return false !== strpos( $line, $words );
	} ) );
}

/** A term's full path, as stored: for the checks to read. */
function term_path( $id ) {
	$line = array();
	$at   = (int) $id;
	while ( isset( $GLOBALS['W']['terms'][ $at ] ) ) {
		array_unshift( $line, $GLOBALS['W']['terms'][ $at ]['name'] );
		$at = (int) $GLOBALS['W']['terms'][ $at ]['parent'];
	}
	return implode( ' > ', $line );
}
