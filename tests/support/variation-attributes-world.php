<?php
/**
 * A played WordPress and WooCommerce for tests/test-variation-attributes.php
 * (3.32.0): variable products, their variations and the shop's global
 * attributes, as WooCommerce keeps them.
 *
 * Loaded for real: TCGiant_Sync_Mapper (map_ebay_to_woo(), save_as_product(),
 * keep_variation_attributes(), save_variations()), TCGiant_Sync_Variations,
 * TCGiant_Sync_Ebay_Price and the classes the mapper reads beside them, as
 * support/ebay-price-import-world.php loads them.
 *
 * Played as WooCommerce does it, because the fault lived there:
 * - a product's attributes keyed by sanitize_title() of their names; a
 *   global one's values are term IDs, saved as the product's terms in its
 *   taxonomy and read back from them; a value that is not a term ID is made
 *   a term on save, as WC_Product_Attribute::get_terms() does;
 * - set_attributes() records a change only when the set it is handed is not
 *   the very one it read (WC_Data::set_prop()'s !==), and the save writes
 *   the attributes only then;
 * - a variation's attributes are read through its parent
 *   (wc_get_product_variation_attributes()): a key the parent does not use
 *   for variations is dropped, and one it does that the variation lacks
 *   reads '' - "Any";
 * - a variation's save writes its attribute_* meta and deletes any other;
 * - a term's name kept as WordPress keeps it ("&" as "&amp;") and found by
 *   name blind to case and accents, as MySQL compares, the first found
 *   (get_term_by()) or all (get_terms()); wp_insert_term() refusing a name
 *   only as strtolower() calls it taken; a slug by sanitize_title(). Every
 *   term written is recorded in $TERM_WRITES;
 * - get_attributes( 'view' ) runs a filter another plugin may hook
 *   ($VIEW_FILTER), 'edit' does not.
 *
 * @package TCGiant_Sync
 */

$ROOT = getenv( 'TREE' ) ? rtrim( (string) getenv( 'TREE' ), '/\\' ) : dirname( __DIR__, 2 );

define( 'ABSPATH', $ROOT . '/' );
define( 'TCGIANT_SYNC_VERSION', '3.32.0' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

$META         = array();
$POSTS        = array();
$OPTIONS      = array();
$LOG          = array();
$WARN         = array();
$SETTINGS     = array();
$NEXT_ID      = 900;
$TAXONOMIES   = array();
$TERMS        = array();
$NEXT_TERM    = 100;
$OBJECT_TERMS = array();
$TERM_WRITES  = array();
$ATTR_SAVES   = array();
$BLIND        = array();
$VIEW_FILTER  = null;
$INSERTING    = false;
$TAX          = array( 'enabled' => false, 'inclusive' => false, 'rates' => array() );

function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['META'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['META'][ (int) $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['META'][ (int) $id ][ $key ] ); return true; }
function get_post_status( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['status'] ?? false; }
function get_post_type( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['type'] ?? false; }
function get_post_field( $field, $id ) { return 'post_title' === $field ? ( $GLOBALS['POSTS'][ (int) $id ]['title'] ?? '' ) : ( $GLOBALS['POSTS'][ (int) $id ][ $field ] ?? '' ); }
function wp_get_post_parent_id( $id ) { return (int) ( $GLOBALS['POSTS'][ (int) $id ]['parent'] ?? 0 ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['OPTIONS'][ $name ] ); return true; }
function get_transient( $name ) { return false; }
function set_transient( $name, $value, $ttl = 0 ) { return true; }
function delete_transient( $name ) { return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function do_action() {}
function add_action() { return true; }
function add_filter() { return true; }
function __( $text, $domain = null ) { return $text; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wc_format_decimal( $number, $dp = false ) { return false === $dp ? (string) $number : number_format( (float) $number, (int) $dp, '.', '' ); }
function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
function wc_tax_enabled() { return (bool) $GLOBALS['TAX']['enabled']; }
function wc_prices_include_tax() { return wc_tax_enabled() && (bool) $GLOBALS['TAX']['inclusive']; }

/**
 * WordPress's sanitize_title(): remove_accents() (for a few), then
 * sanitize_title_with_dashes() in its 'save' context, for ASCII: "/" and
 * the en and em dash are hyphens and "×" an x (fix round 2: WordPress makes
 * "1/2" the slug 1-2, not 12), the rest of what is not a-z, 0-9, "_" or
 * "-" dropped.
 */
function sanitize_title( $title ) {
	$title = str_replace( array( '/', '–', '—', '×' ), array( '-', '-', '-', 'x' ), (string) $title );
	$title = strtr( (string) $title, array( 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'É' => 'E', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ç' => 'c' ) );
	$title = strtolower( strip_tags( $title ) );
	$title = (string) preg_replace( '/&.+?;/', '', $title );
	$title = str_replace( '.', '-', $title );
	$title = (string) preg_replace( '/[^%a-z0-9 _-]/', '', $title );
	$title = (string) preg_replace( '/\s+/', '-', $title );
	$title = (string) preg_replace( '|-+|', '-', $title );
	return trim( $title, '-' );
}

class WC_Tax {
	public static function get_base_tax_rates( $tax_class = '' ) { return array(); }
	public static function calc_tax( $price, $rates, $price_includes_tax = false ) { return array(); }
}
class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data( $code = '' ) { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

// ---- the shop's global attributes and their values (terms) ---------------------------------

/** WordPress keeps a term's name with "&" as "&amp;" (pre_term_name's _wp_specialchars()). */
function term_db_name( $name ) { return htmlspecialchars( trim( (string) $name ), ENT_NOQUOTES, 'UTF-8', false ); }
function taxonomy_exists( $taxonomy ) { return isset( $GLOBALS['TAXONOMIES'][ (string) $taxonomy ] ); }
/** A global attribute's label; one deleted since reads as its slug (wc_attribute_taxonomy_slug()). */
function wc_attribute_label( $name, $product = null ) { return $GLOBALS['TAXONOMIES'][ (string) $name ]['label'] ?? ( 0 === strpos( (string) $name, 'pa_' ) ? substr( (string) $name, 3 ) : (string) $name ); }
/**
 * How the database compares a term's name (utf8mb4_unicode_ci, WordPress's
 * default): blind to case and to accents - "Rosé" is "Rose" (3.32.0 fix
 * round 1).
 */
function collate( $name ) {
	return mb_strtolower( strtr( (string) $name, array( 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'É' => 'E', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ç' => 'c' ) ), 'UTF-8' );
}
/** get_terms( array( 'taxonomy', 'name' ) ): every term of that name, as the database finds it. */
function get_terms( $args = array() ) {
	if ( isset( $args['name'] ) && in_array( sanitize_title( $args['name'] ), array_map( 'sanitize_title', $GLOBALS['BLIND'] ), true ) && empty( $GLOBALS['INSERTING'] ) ) {
		return array();
	}
	$out = array();
	foreach ( $GLOBALS['TERMS'] as $term ) {
		if ( $term->taxonomy === ( $args['taxonomy'] ?? '' ) && ( ! isset( $args['name'] ) || collate( $term->name ) === collate( term_db_name( $args['name'] ) ) ) ) {
			$out[] = clone $term;
		}
	}
	return $out;
}
function get_term_by( $field, $value, $taxonomy = '' ) {
	// A value another import is adding at this moment: not there yet when
	// looked up, there by the time it is inserted.
	if ( 'id' !== $field && in_array( sanitize_title( $value ), array_map( 'sanitize_title', $GLOBALS['BLIND'] ), true ) && empty( $GLOBALS['INSERTING'] ) ) {
		return false;
	}
	if ( ! isset( $GLOBALS['TAXONOMIES'][ (string) $taxonomy ] ) ) {
		// The product's categories and tags: there.
		return (object) array( 'term_id' => 7, 'name' => (string) $value, 'slug' => sanitize_title( $value ) );
	}
	// By name the first the database finds (number 1, orderby none).
	if ( 'name' === $field ) {
		$found = get_terms( array( 'taxonomy' => $taxonomy, 'name' => $value ) );
		return $found ? $found[0] : false;
	}
	foreach ( $GLOBALS['TERMS'] as $term ) {
		if ( $term->taxonomy !== $taxonomy ) {
			continue;
		}
		if ( ( 'slug' === $field && $term->slug === sanitize_title( $value ) )
			|| ( 'id' === $field && (int) $term->term_id === (int) $value ) ) {
			return clone $term;
		}
	}
	return false;
}
function get_term( $id, $taxonomy = '' ) {
	return isset( $GLOBALS['TERMS'][ (int) $id ] ) ? clone $GLOBALS['TERMS'][ (int) $id ] : null;
}
function term_exists( $term, $taxonomy = '' ) {
	if ( ! isset( $GLOBALS['TAXONOMIES'][ (string) $taxonomy ] ) ) {
		return array( 'term_id' => 7, 'term_taxonomy_id' => 7 );
	}
	$found = get_term_by( 'name', $term, $taxonomy );
	return $found ? array( 'term_id' => $found->term_id, 'term_taxonomy_id' => $found->term_id ) : null;
}
/** As WordPress: a name taken only by one strtolower() calls the same, among those the database finds. */
function wp_insert_term( $name, $taxonomy = '', $args = array() ) {
	if ( ! isset( $GLOBALS['TAXONOMIES'][ (string) $taxonomy ] ) ) {
		return array( 'term_id' => 7, 'term_taxonomy_id' => 7 );
	}
	$GLOBALS['TERM_WRITES'][] = array( 'insert', $taxonomy, (string) $name );
	$GLOBALS['INSERTING'] = true;
	$named = get_terms( array( 'taxonomy' => $taxonomy, 'name' => $name ) );
	$GLOBALS['INSERTING'] = false;
	foreach ( $named as $same ) {
		if ( strtolower( term_db_name( $name ) ) === strtolower( $same->name ) ) {
			return new WP_Error( 'term_exists', 'A term with the name provided already exists in this taxonomy.', $same->term_id );
		}
	}
	return array( 'term_id' => add_term( $taxonomy, $name ), 'term_taxonomy_id' => 0 );
}
function wp_update_term( $id, $taxonomy, $args = array() ) { $GLOBALS['TERM_WRITES'][] = array( 'update', $taxonomy, $id ); return array( 'term_id' => $id ); }
function wp_delete_term( $id, $taxonomy, $args = array() ) { $GLOBALS['TERM_WRITES'][] = array( 'delete', $taxonomy, $id ); return true; }
function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
	if ( 'product_type' === $taxonomy && 'variable' === $terms ) {
		$GLOBALS['POSTS'][ (int) $object_id ]['variable'] = true;
	}
	return array();
}

/** A term made: its slug unique in its taxonomy, as WordPress makes it. */
function add_term( $taxonomy, $name, $slug = null ) {
	$id   = ++$GLOBALS['NEXT_TERM'];
	$slug = null !== $slug ? $slug : sanitize_title( $name );
	$base = $slug;
	for ( $n = 2; get_term_by( 'slug', $slug, $taxonomy ); $n++ ) {
		$slug = $base . '-' . $n;
	}
	$GLOBALS['TERMS'][ $id ] = (object) array( 'term_id' => $id, 'name' => term_db_name( $name ), 'slug' => $slug, 'taxonomy' => $taxonomy );
	return $id;
}

/** A global attribute of the shop's, with these values: name => term ID. */
function global_attribute( $taxonomy, $label, array $names ) {
	$GLOBALS['TAXONOMIES'][ $taxonomy ] = array( 'id' => count( $GLOBALS['TAXONOMIES'] ) + 1, 'label' => $label );
	$ids = array();
	foreach ( $names as $key => $name ) {
		// name => slug, for a term whose slug is not its name's.
		$ids[ is_string( $key ) ? $key : $name ] = is_string( $key ) ? add_term( $taxonomy, $key, $name ) : add_term( $taxonomy, $name );
	}
	return $ids;
}

/** What save_as_product() asks of the database itself: the product holding an item number. */
class Harness_wpdb {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_row( $query ) {
		foreach ( $GLOBALS['META'] as $id => $meta ) {
			if ( (string) ( $meta['_ebay_item_id'] ?? '' ) === (string) ( $query[1][0] ?? '' ) && 'product' === get_post_type( $id ) ) {
				return (object) array( 'post_id' => (string) $id, 'post_status' => get_post_status( $id ) );
			}
		}
		return null;
	}
	public function get_var( $query ) { return null; }
	public function get_col( $query ) { return array(); }
	public function get_results( $query, $output = null ) { return array(); }
	public function query( $query ) { return 0; }
	public function esc_like( $text ) { return $text; }
}
$wpdb = new Harness_wpdb();

function wc_get_product_id_by_sku( $sku ) {
	foreach ( $GLOBALS['META'] as $id => $meta ) {
		if ( '' !== (string) $sku && (string) ( $meta['_sku'] ?? '' ) === (string) $sku && 'trash' !== get_post_status( $id ) ) {
			return (int) $id;
		}
	}
	return 0;
}

// ---- WooCommerce's attribute, product and variation -----------------------------------------

class WC_Product_Attribute {
	protected $data = array( 'id' => 0, 'name' => '', 'options' => array(), 'position' => 0, 'visible' => false, 'variation' => false );
	public function set_id( $value ) { $this->data['id'] = (int) $value; }
	public function get_id() { return $this->data['id']; }
	public function set_name( $value ) { $this->data['name'] = (string) $value; }
	public function get_name() { return $this->data['name']; }
	public function set_options( $value ) { $this->data['options'] = $value; }
	public function get_options() { return $this->data['options']; }
	public function set_position( $value ) { $this->data['position'] = (int) $value; }
	public function get_position() { return $this->data['position']; }
	public function set_visible( $value ) { $this->data['visible'] = (bool) $value; }
	public function get_visible() { return $this->data['visible']; }
	public function set_variation( $value ) { $this->data['variation'] = (bool) $value; }
	public function get_variation() { return $this->data['variation']; }
	public function is_taxonomy() { return 0 < $this->data['id']; }
	public function get_taxonomy() { return $this->is_taxonomy() ? $this->data['name'] : ''; }
}

/** A product: what it read, what changed, saved as WooCommerce saves it. */
class WC_Product {
	protected $id          = 0;
	protected $data        = array( 'attributes' => array() );
	protected $changes     = array();
	protected $object_read = false;
	protected $meta        = array();
	protected $dirty       = array();
	protected $status      = null;

	public function __construct( $id = 0 ) {
		$this->id = (int) $id;
		if ( $this->id > 0 ) {
			$this->meta               = $GLOBALS['META'][ $this->id ] ?? array();
			$this->data['attributes'] = $this->read_attributes();
			$this->object_read        = true;
		}
	}
	protected function read_attributes() {
		$out = array();
		foreach ( (array) ( $this->meta['_product_attributes'] ?? array() ) as $key => $row ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( $row['id'] );
			$attribute->set_name( $row['name'] );
			$attribute->set_options( $row['id'] > 0 ? ( $GLOBALS['OBJECT_TERMS'][ $this->id ][ $row['name'] ] ?? array() ) : $row['options'] );
			$attribute->set_position( $row['position'] );
			$attribute->set_visible( $row['visible'] );
			$attribute->set_variation( $row['variation'] );
			$out[ $key ] = $attribute;
		}
		return $out;
	}
	protected function set_prop( $prop, $value ) {
		if ( $this->object_read ) {
			if ( $value !== $this->data[ $prop ] || array_key_exists( $prop, $this->changes ) ) {
				$this->changes[ $prop ] = $value;
			}
		} else {
			$this->data[ $prop ] = $value;
		}
	}
	public function get_attributes( $context = 'view' ) {
		$attributes = array_key_exists( 'attributes', $this->changes ) ? $this->changes['attributes'] : $this->data['attributes'];
		// 'view' runs woocommerce_product_get_attributes, which another plugin may hook.
		return 'view' === $context && is_callable( $GLOBALS['VIEW_FILTER'] ) ? call_user_func( $GLOBALS['VIEW_FILTER'], $attributes ) : $attributes;
	}
	public function set_attributes( $raw_attributes ) {
		$attributes = array_fill_keys( array_keys( $this->get_attributes( 'edit' ) ), null );
		foreach ( (array) $raw_attributes as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute ) {
				$attributes[ sanitize_title( $attribute->get_name() ) ] = $attribute;
			}
		}
		uasort( $attributes, function ( $a, $b ) {
			$pa = null === $a ? null : $a->get_position();
			$pb = null === $b ? null : $b->get_position();
			return $pa === $pb ? 0 : ( $pa < $pb ? -1 : 1 );
		} );
		$this->set_prop( 'attributes', $attributes );
	}
	public function get_id() { return $this->id; }
	public function get_name() { return get_post_field( 'post_title', $this->id ); }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; $this->dirty[ $key ] = true; }
	public function delete_meta_data( $key ) { if ( array_key_exists( $key, $this->meta ) ) { $this->meta[ $key ] = null; $this->dirty[ $key ] = true; } }
	public function set_sku( $sku ) { $this->update_meta_data( '_sku', $sku ); }
	public function get_sku( $context = 'view' ) { return (string) ( $this->meta['_sku'] ?? '' ); }
	public function set_regular_price( $price ) { $this->update_meta_data( '_regular_price', (string) $price ); }
	public function get_regular_price( $context = 'view' ) { return (string) ( $this->meta['_regular_price'] ?? '' ); }
	public function set_sale_price( $price ) { $this->update_meta_data( '_sale_price', (string) $price ); }
	public function get_sale_price( $context = 'view' ) { return (string) ( $this->meta['_sale_price'] ?? '' ); }
	public function is_taxable() { return true; }
	public function get_tax_class( $context = 'view' ) { return ''; }
	public function set_manage_stock( $on ) { $this->update_meta_data( '_manage_stock', $on ? 'yes' : 'no' ); }
	public function managing_stock() { return 'yes' === ( $this->meta['_manage_stock'] ?? 'yes' ); }
	public function set_stock_quantity( $qty ) { $this->update_meta_data( '_stock', null === $qty ? null : (int) $qty ); }
	public function get_stock_quantity() { return isset( $this->meta['_stock'] ) ? (int) $this->meta['_stock'] : null; }
	public function set_stock_status( $status ) { $this->update_meta_data( '_stock_status', (string) $status ); }
	public function get_stock_status() { return (string) ( $this->meta['_stock_status'] ?? 'instock' ); }
	public function is_in_stock() { return 'outofstock' !== $this->get_stock_status(); }
	public function set_status( $status ) { $this->status = (string) $status; }
	public function get_status() { return null !== $this->status ? $this->status : (string) get_post_status( $this->id ); }
	public function get_children() {
		$out = array();
		foreach ( $GLOBALS['POSTS'] as $id => $post ) {
			if ( 'product_variation' === $post['type'] && (int) ( $post['parent'] ?? 0 ) === $this->id ) {
				$out[] = (int) $id;
			}
		}
		sort( $out );
		return $out;
	}
	public function is_type( $type ) { return 'simple' === $type; }
	public function get_type() { return 'simple'; }
	public function save() {
		$new = 0 === $this->id;
		if ( $new ) {
			$this->id = ++$GLOBALS['NEXT_ID'];
			$GLOBALS['POSTS'][ $this->id ] = array( 'type' => 'product', 'status' => 'publish', 'title' => 'New', 'variable' => $this instanceof WC_Product_Variable, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ) );
		}
		if ( ! $this->object_read || array_key_exists( 'attributes', $this->changes ) ) {
			$this->write_attributes( $this->get_attributes( 'edit' ) );
			$GLOBALS['ATTR_SAVES'][ $this->id ] = ( $GLOBALS['ATTR_SAVES'][ $this->id ] ?? 0 ) + 1;
		}
		$this->write_rest();
		$this->data        = array_merge( $this->data, $this->changes );
		$this->changes     = array();
		$this->object_read = true;
		return $this->id;
	}
	protected function write_rest() {
		foreach ( array_keys( $this->dirty ) as $key ) {
			if ( null === $this->meta[ $key ] ) {
				unset( $GLOBALS['META'][ $this->id ][ $key ], $this->meta[ $key ] );
			} else {
				$GLOBALS['META'][ $this->id ][ $key ] = $this->meta[ $key ];
			}
		}
		$this->dirty = array();
		if ( null !== $this->status ) {
			$GLOBALS['POSTS'][ $this->id ]['status'] = $this->status;
			$this->status                            = null;
		}
	}
	protected function write_attributes( $attributes ) {
		$rows = array();
		foreach ( (array) $attributes as $key => $attribute ) {
			if ( null === $attribute ) {
				unset( $GLOBALS['OBJECT_TERMS'][ $this->id ][ $key ] );
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$ids = array();
				foreach ( (array) $attribute->get_options() as $option ) {
					if ( is_int( $option ) ) {
						$ids[] = $option;
						continue;
					}
					// WC_Product_Attribute::get_terms(): a value that is not a
					// term ID is looked up by name, and made a term if none.
					$term = get_term_by( 'name', $option, $attribute->get_name() );
					if ( ! $term ) {
						$made = wp_insert_term( $option, $attribute->get_name() );
						$term = is_array( $made ) ? get_term( $made['term_id'] ) : null;
					}
					if ( $term ) {
						$ids[] = (int) $term->term_id;
					}
				}
				$GLOBALS['OBJECT_TERMS'][ $this->id ][ $attribute->get_name() ] = $ids;
			}
			$rows[ $key ] = array(
				'id'        => $attribute->get_id(),
				'name'      => $attribute->get_name(),
				'options'   => $attribute->is_taxonomy() ? array() : array_values( (array) $attribute->get_options() ),
				'position'  => $attribute->get_position(),
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
			);
		}
		$GLOBALS['META'][ $this->id ]['_product_attributes'] = $rows;
		$this->meta['_product_attributes']                   = $rows;
	}
	public function __call( $name, $args ) { return null; }
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variable extends WC_Product {
	public function is_type( $type ) { return 'variable' === $type; }
	public function get_type() { return 'variable'; }
}
/** A variation: its attributes read through its parent, as WooCommerce reads them. */
class WC_Product_Variation extends WC_Product {
	protected $parent = 0;
	public function __construct( $id = 0 ) {
		$this->id = (int) $id;
		$this->data['attributes'] = array();
		if ( $this->id > 0 ) {
			$this->meta               = $GLOBALS['META'][ $this->id ] ?? array();
			$this->parent             = (int) ( $GLOBALS['POSTS'][ $this->id ]['parent'] ?? 0 );
			$this->data['attributes'] = self::read_through_parent( $this->id );
			$this->object_read        = true;
		}
	}
	/** wc_get_product_variation_attributes(), without the prefix. */
	public static function read_through_parent( $id ) {
		$parent = (int) ( $GLOBALS['POSTS'][ (int) $id ]['parent'] ?? 0 );
		$out    = array();
		foreach ( (array) ( $GLOBALS['META'][ $parent ]['_product_attributes'] ?? array() ) as $key => $row ) {
			if ( ! empty( $row['variation'] ) ) {
				$out[ $key ] = (string) ( $GLOBALS['META'][ (int) $id ][ 'attribute_' . $key ] ?? '' );
			}
		}
		return $out;
	}
	public function set_attributes( $raw_attributes ) {
		$attributes = array();
		foreach ( (array) $raw_attributes as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'attribute_' ) ) {
				$key = substr( (string) $key, 10 );
			}
			$attributes[ $key ] = $value;
		}
		$this->set_prop( 'attributes', $attributes );
	}
	public function get_variation_attributes() {
		$out = array();
		foreach ( $this->get_attributes() as $key => $value ) {
			$out[ 'attribute_' . $key ] = $value;
		}
		return $out;
	}
	public function set_parent_id( $id ) { $this->parent = (int) $id; }
	public function get_parent_id() { return $this->parent; }
	public function is_type( $type ) { return 'variation' === $type; }
	public function save() {
		$new = 0 === $this->id;
		if ( $new ) {
			$this->id = ++$GLOBALS['NEXT_ID'];
			$GLOBALS['POSTS'][ $this->id ] = array( 'type' => 'product_variation', 'status' => 'publish', 'title' => 'Variation', 'parent' => $this->parent );
		}
		if ( $new || array_key_exists( 'attributes', $this->changes ) ) {
			foreach ( array_keys( $GLOBALS['META'][ $this->id ] ?? array() ) as $key ) {
				if ( 0 === strpos( (string) $key, 'attribute_' ) ) {
					unset( $GLOBALS['META'][ $this->id ][ $key ], $this->meta[ $key ] );
				}
			}
			foreach ( $this->get_attributes() as $key => $value ) {
				$GLOBALS['META'][ $this->id ][ 'attribute_' . $key ] = (string) $value;
				$this->meta[ 'attribute_' . $key ]                   = (string) $value;
			}
		}
		$this->write_rest();
		$this->data        = array_merge( $this->data, $this->changes );
		$this->changes     = array();
		$this->object_read = true;
		return $this->id;
	}
}
function wc_get_product( $id ) {
	$type = $GLOBALS['POSTS'][ (int) $id ]['type'] ?? '';
	if ( '' === $type ) {
		return false;
	}
	if ( 'product_variation' === $type ) {
		return new WC_Product_Variation( $id );
	}
	return ! empty( $GLOBALS['POSTS'][ (int) $id ]['variable'] ) ? new WC_Product_Variable( $id ) : new WC_Product( $id );
}

// ---- the plugin's classes the mapper reads, played --------------------------------------------

class TCGiant_Sync_OAuth {
	public static function instance() { return new self(); }
	public function get_settings() { return $GLOBALS['SETTINGS']; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS        = array();
	const AUCTION_LISTED_KEY = '_ebay_auction_listed_item';
	public static function arm_record( $product, $before, $record, $holds = null ) { return null; }
	public static function disarm_record( $armed ) { return false; }
	public static function begin_ebay_origin() {}
	public static function end_ebay_origin() {}
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
	public static function table_exists() { return false; }
}
class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = $message; }
	public static function warning( $message ) { $GLOBALS['LOG'][] = $message; $GLOBALS['WARN'][] = $message; }
	public static function error( $message ) { $GLOBALS['LOG'][] = $message; }
	public static function write_now( $message, $level = 'error' ) { $GLOBALS['LOG'][] = $message; }
}
class TCGiant_Sync_Image_Localizer {
	public static function set_external_images() {}
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
if ( ! defined( 'TCGIANT_SYNC_IS_STAGING' ) ) {
	define( 'TCGIANT_SYNC_IS_STAGING', false );
}
require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-exporter.php';
require $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require_once $ROOT . '/includes/class-tcgiant-sync-variations.php';
require $ROOT . '/includes/class-tcgiant-sync-mapper.php';

// ---- eBay's listings ----------------------------------------------------------------------------

/** A listing as eBay's GetItem answers, turned into an array as the API class does. */
function ebay_item( $inner ) {
	$xml    = '<?xml version="1.0" encoding="UTF-8"?><GetItemResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack><Item>' . $inner . '</Item></GetItemResponse>';
	$parsed = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA );
	$array  = json_decode( wp_json_encode( $parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), true );
	return $array['Item'];
}

/**
 * A listing with variations: each array( specifics, quantity left, SKU ),
 * plus any item specifics of the listing's own.
 *
 * @param string $item_id   Item number.
 * @param array  $variations Each: 0 => Name => Value, 1 => left, 2 => SKU ('' none).
 * @param array  $specifics The listing's item specifics, Name => Value.
 * @return array
 */
function var_listing( $item_id, array $variations, array $specifics = array() ) {
	$vars = '';
	foreach ( $variations as $v ) {
		$nvl = '';
		foreach ( $v[0] as $name => $value ) {
			$nvl .= '<NameValueList><Name>' . htmlspecialchars( $name, ENT_XML1 ) . '</Name><Value>' . htmlspecialchars( $value, ENT_XML1 ) . '</Value></NameValueList>';
		}
		$vars .= '<Variation>' . ( '' !== (string) ( $v[2] ?? '' ) ? '<SKU>' . $v[2] . '</SKU>' : '' )
			. '<StartPrice currencyID="USD">12.0</StartPrice><Quantity>' . ( (int) $v[1] + 1 ) . '</Quantity><SellingStatus><QuantitySold>1</QuantitySold></SellingStatus>'
			. '<VariationSpecifics>' . $nvl . '</VariationSpecifics></Variation>';
	}
	$item_specifics = '';
	foreach ( $specifics as $name => $value ) {
		$item_specifics .= '<NameValueList><Name>' . htmlspecialchars( $name, ENT_XML1 ) . '</Name><Value>' . htmlspecialchars( $value, ENT_XML1 ) . '</Value></NameValueList>';
	}
	return ebay_item( '<ItemID>' . $item_id . '</ItemID><Title>Tee ' . $item_id . '</Title><SKU>SKU-' . $item_id . '</SKU><Currency>USD</Currency><Site>US</Site>'
		. '<Quantity>9</Quantity><ListingType>FixedPriceItem</ListingType><ListingDuration>GTC</ListingDuration>'
		. '<ListingDetails><StartTime>2026-01-05T10:00:00.000Z</StartTime><EndTime>2026-12-05T10:00:00.000Z</EndTime></ListingDetails>'
		. '<SellingStatus><ListingStatus>Active</ListingStatus><QuantitySold>0</QuantitySold><CurrentPrice currencyID="USD">12.0</CurrentPrice></SellingStatus>'
		. ( '' !== $item_specifics ? '<ItemSpecifics>' . $item_specifics . '</ItemSpecifics>' : '' )
		. '<Variations>' . $vars . '</Variations>' );
}

/** The import saves the listing: mapped, then saved, as every import path does. */
function import( array $item ) {
	$mapper = new TCGiant_Sync_Mapper();
	$data   = $mapper->map_ebay_to_woo( $item );
	return $mapper->save_as_product( $data );
}

// ---- the shop's products --------------------------------------------------------------------------

/**
 * A variable product of the shop's, linked to a listing, with these
 * attributes: each array( name - pa_* for a global one -, values - term
 * IDs for a global one -, used for variations, position ).
 */
function shop_product( $id, $item_id, array $attributes ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => 'product', 'status' => 'publish', 'title' => 'Tee ' . $item_id, 'post_date_gmt' => '2026-01-01 09:00:00', 'variable' => true );
	$GLOBALS['META'][ $id ]  = array( '_sku' => 'SKU-' . $item_id, '_ebay_item_id' => $item_id, '_ebay_listing_status' => 'Active' );
	$rows                    = array();
	foreach ( $attributes as $a ) {
		$global = isset( $GLOBALS['TAXONOMIES'][ $a[0] ] );
		$key    = sanitize_title( $a[0] );
		$rows[ $key ] = array( 'id' => $global ? $GLOBALS['TAXONOMIES'][ $a[0] ]['id'] : 0, 'name' => $a[0], 'options' => $global ? array() : $a[1], 'position' => $a[3] ?? count( $rows ), 'visible' => true, 'variation' => (bool) $a[2] );
		if ( $global ) {
			$GLOBALS['OBJECT_TERMS'][ $id ][ $a[0] ] = array_values( $a[1] );
		}
	}
	$GLOBALS['META'][ $id ]['_product_attributes'] = $rows;
	return $id;
}

/** A variation of the shop's: its attributes key => value, as WooCommerce keeps them. */
function shop_variation( $id, $parent, $sku, $stock, array $attributes, $status = 'publish', array $meta = array() ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => 'product_variation', 'status' => $status, 'title' => 'Variation', 'parent' => $parent );
	$GLOBALS['META'][ $id ]  = array_merge( array( '_sku' => $sku, '_stock' => $stock, '_manage_stock' => 'yes' ), $meta );
	foreach ( $attributes as $key => $value ) {
		$GLOBALS['META'][ $id ][ 'attribute_' . $key ] = $value;
	}
	return $id;
}

// ---- what to look at ----------------------------------------------------------------------------

function meta( $id, $key ) { return $GLOBALS['META'][ (int) $id ][ $key ] ?? null; }
function status_of( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['status'] ?? null; }

/** A product's variations, by status: id => status. */
function children_of( $parent ) {
	$out = array();
	foreach ( ( new WC_Product( $parent ) )->get_children() as $id ) {
		$out[ $id ] = status_of( $id );
	}
	return $out;
}
function published_of( $parent ) { return array_keys( array_filter( children_of( $parent ), function ( $s ) { return 'publish' === $s; } ) ); }

/** A product's attributes as saved: key => "name: values [v]" - a global one's values by slug. */
function attrs_of( $id ) {
	$out = array();
	foreach ( (array) meta( $id, '_product_attributes' ) as $key => $row ) {
		if ( $row['id'] > 0 ) {
			$values = array();
			foreach ( $GLOBALS['OBJECT_TERMS'][ $id ][ $row['name'] ] ?? array() as $term_id ) {
				$values[] = $GLOBALS['TERMS'][ $term_id ]->slug ?? ( '?' . $term_id );
			}
		} else {
			$values = $row['options'];
		}
		$out[ $key ] = $row['name'] . ': ' . implode( '|', $values ) . ( $row['variation'] ? ' [v]' : '' );
	}
	return $out;
}

/** A variation's attributes as WooCommerce reads them, through its parent. */
function var_attrs( $id ) { return WC_Product_Variation::read_through_parent( $id ); }

/** Its attribute_* meta as stored. */
function var_meta( $id ) {
	$out = array();
	foreach ( (array) ( $GLOBALS['META'][ (int) $id ] ?? array() ) as $key => $value ) {
		if ( 0 === strpos( (string) $key, 'attribute_' ) ) {
			$out[ $key ] = $value;
		}
	}
	ksort( $out );
	return $out;
}

/**
 * What the website offers: each published variation as "key=value,..."
 * when every value is one of its parent's for that attribute (a global
 * one's by slug), "ANY" when one reads '' - it matches every choice - and
 * nothing for one whose value the parent does not have, which no choice
 * on the page reaches.
 */
function offered( $parent ) {
	$options = array();
	foreach ( (array) meta( $parent, '_product_attributes' ) as $key => $row ) {
		if ( empty( $row['variation'] ) ) {
			continue;
		}
		if ( $row['id'] > 0 ) {
			$options[ $key ] = array();
			foreach ( $GLOBALS['OBJECT_TERMS'][ $parent ][ $row['name'] ] ?? array() as $term_id ) {
				$options[ $key ][] = $GLOBALS['TERMS'][ $term_id ]->slug ?? '';
			}
		} else {
			$options[ $key ] = $row['options'];
		}
	}
	$out = array();
	foreach ( published_of( $parent ) as $id ) {
		$parts = array();
		foreach ( var_attrs( $id ) as $key => $value ) {
			if ( '' === $value ) {
				$parts = 'ANY';
				break;
			}
			if ( ! in_array( $value, $options[ $key ] ?? array(), true ) ) {
				$parts = null;
				break;
			}
			$parts[] = $key . '=' . $value;
		}
		if ( 'ANY' === $parts ) {
			$out[] = 'ANY';
		} elseif ( null !== $parts ) {
			$out[] = implode( ',', $parts );
		}
	}
	sort( $out );
	return $out;
}

/** The pa_ terms ever written: inserts, updates, deletes. */
function term_writes() { return $GLOBALS['TERM_WRITES']; }

/** Every global attribute's values as they stand: taxonomy => [ slug => name ]. */
function terms_now() {
	$out = array();
	foreach ( $GLOBALS['TERMS'] as $term ) {
		$out[ $term->taxonomy ][ $term->slug ] = $term->name;
	}
	ksort( $out );
	return $out;
}

/** The product's sync log's newest entry. */
function decided( $id ) {
	$log = meta( $id, '_tcgiant_sync_log' );
	return is_array( $log ) && $log ? (array) ( $log[0]['decisions'] ?? array() ) : array();
}
