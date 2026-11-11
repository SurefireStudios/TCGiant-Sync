<?php
/**
 * A world for test-filterable-specifics.php (3.35.0): the re-send world
 * (resend-world.php: the template world, Action Scheduler, the options rows
 * of the holds, the Listings table) with the real mapper beside the real
 * exporter, templates and re-send, and products whose attributes are kept as
 * WooCommerce keeps them:
 *
 * - _product_attributes, a row a key: a custom attribute's values joined
 *   with " | ", a global one's values as the product's terms in its
 *   taxonomy; read back as WooCommerce reads them (split at "|", entities
 *   decoded, trimmed; a taxonomy not registered left out), keyed by
 *   sanitize_title() of the name and in position order; set_attributes()
 *   and save() as WooCommerce's (a key no longer there written as null: its
 *   terms taken off), and every save dating the product (post_modified);
 * - terms as WordPress keeps them: a name cleaned on the way in as
 *   pre_term_name cleans it ("&" as "&amp;", tags and runs of spaces gone),
 *   found by name blind to case and accents as MySQL compares, by slug, or
 *   by ID; wp_insert_term() refusing a name only as strtolower() calls it
 *   taken (at the same parent), a slug made unique with -2, -3; a product's
 *   terms in each taxonomy;
 * - WooCommerce's global attributes (wc_get_attribute_taxonomies(),
 *   wc_create_attribute() with its 28-character, reserved-word and
 *   taken-slug refusals; registered at "init" only, so one made now is not
 *   until register_taxonomy()), its labels, wc_get_product_terms() and its
 *   text-attribute helpers; and its caches and attribute lookup table,
 *   recorded.
 *
 * This is a stand-in for speed, not the proof: each rule was read from
 * WordPress's and WooCommerce's own code (the notes of this release say
 * where).
 *
 * @package TCGiant_Sync
 */

// --- terms, as WordPress keeps them ---------------------------------------------

$GLOBALS['FW'] = array(
	'tx'         => array(),   // term ID => taxonomy, name (stored), slug, parent
	'ot'         => array(),   // object ID => taxonomy => term IDs
	'next_term'  => 500,
	'taxonomies' => array(),   // taxonomy => args
	'attributes' => array(),   // attribute_id => row
	'next_attr'  => 1,
	'term_sets'  => array(),   // every wp_set_object_terms(): object, taxonomy, IDs
	'inserted'   => array(),   // every term made: taxonomy, name
	'created'    => array(),   // every global attribute made: slug, label
	'cleared'    => array(),   // wc_delete_product_transients() and the cache groups
	'primed'     => 0,
	'meta_cache' => array(),   // post ID => its meta, as this request last read it
	'cache_reads' => false,
	'cleaned'    => array(),   // every clean_post_cache()
	'saves'      => array(),   // product ID => saves
);

/** What pre_term_name leaves of a name: sanitize_text_field(), wp_filter_kses(), _wp_specialchars(). */
function fw_clean_name( $name ) {
	$name = strip_tags( (string) $name );
	$name = (string) preg_replace( '/[\r\n\t ]+/', ' ', $name );
	$name = trim( $name );
	$found = false;
	while ( preg_match( '/%[a-f0-9]{2}/i', $name, $m ) ) {
		$name  = str_replace( $m[0], '', $name );
		$found = true;
	}
	if ( $found ) {
		$name = trim( (string) preg_replace( '/ +/', ' ', $name ) );
	}
	return htmlspecialchars( $name, ENT_NOQUOTES, 'UTF-8', false );
}

/** How MySQL's utf8mb4_unicode_ci compares two names: blind to case and accents. */
function fw_collate( $name ) {
	return mb_strtolower( strtr( (string) $name, array( 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'É' => 'E', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ç' => 'c' ) ), 'UTF-8' );
}

/**
 * WordPress's sanitize_title() (3.35.0 fix round 2): for Latin letters as the
 * template world's (a run of anything else a hyphen), so every key the other
 * suites know is the same; a letter of another script percent-encoded, in
 * lower case, as sanitize_title_with_dashes() encodes it (utf8_uri_encode()
 * after mb_strtolower()), its accents first taken off as remove_accents()
 * takes them. So "Марка" is %d0%bc%d0%b0%d1%80%d0%ba%d0%b0, a key WooCommerce
 * keeps and the template renderer cannot read as a name.
 */
function sanitize_title( $title ) {
	$title = mb_strtolower( strtr( (string) $title, array( 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'É' => 'E', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ç' => 'c' ) ), 'UTF-8' );
	$out   = '';
	foreach ( preg_split( '/([\x80-\xff]+)/', $title, -1, PREG_SPLIT_DELIM_CAPTURE ) as $n => $part ) {
		$out .= $n % 2 ? '%' . implode( '%', str_split( bin2hex( $part ), 2 ) ) : preg_replace( '/[^a-z0-9_\-]+/', '-', $part );
	}
	return trim( $out, '-' );
}

/** WordPress's slug of a name: sanitize_title(), after remove_accents(). */
function fw_slug( $name ) {
	return sanitize_title( strtr( (string) $name, array( 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'É' => 'E', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ç' => 'c' ) ) );
}

/** The host ending a request part way: the die_at'th write from now (a term set, or a product's caches cleared) throws. */
function fw_maybe_die() {
	if ( isset( $GLOBALS['FW']['die_at'] ) && --$GLOBALS['FW']['die_at'] <= 0 ) {
		unset( $GLOBALS['FW']['die_at'] );
		throw new RuntimeException( 'killed' );
	}
}

function sanitize_term_field( $field, $value, $term_id, $taxonomy, $context ) {
	return ( 'name' === $field && 'db' === $context ) ? fw_clean_name( $value ) : $value;
}

function taxonomy_exists( $taxonomy ) { return isset( $GLOBALS['FW']['taxonomies'][ (string) $taxonomy ] ); }
function register_taxonomy( $taxonomy, $objects, $args = array() ) {
	$GLOBALS['FW']['taxonomies'][ (string) $taxonomy ] = (array) $args;
	return true;
}
function is_taxonomy_hierarchical( $taxonomy ) { return ! empty( $GLOBALS['FW']['taxonomies'][ (string) $taxonomy ]['hierarchical'] ); }

function fw_term( $id ) {
	$row = $GLOBALS['FW']['tx'][ (int) $id ] ?? null;
	return $row ? (object) array( 'term_id' => (int) $id, 'name' => $row['name'], 'slug' => $row['slug'], 'parent' => (int) $row['parent'], 'taxonomy' => $row['taxonomy'] ) : null;
}

function get_term( $id, $taxonomy = '' ) {
	$term = fw_term( $id );
	return ( $term && ( '' === $taxonomy || $term->taxonomy === $taxonomy ) ) ? $term : null;
}

/** get_terms(): taxonomy, name (as the database compares it), slug, fields; in term ID order. */
function get_terms( $args = array() ) {
	$out = array();
	$ids = array_keys( $GLOBALS['FW']['tx'] );
	sort( $ids );
	foreach ( $ids as $id ) {
		$row = $GLOBALS['FW']['tx'][ $id ];
		if ( isset( $args['taxonomy'] ) && ! in_array( $row['taxonomy'], (array) $args['taxonomy'], true ) ) {
			continue;
		}
		if ( isset( $args['name'] ) && fw_collate( $row['name'] ) !== fw_collate( fw_clean_name( $args['name'] ) ) ) {
			continue;
		}
		if ( isset( $args['slug'] ) && $row['slug'] !== (string) $args['slug'] ) {
			continue;
		}
		if ( isset( $args['parent'] ) && (int) $row['parent'] !== (int) $args['parent'] ) {
			continue;
		}
		$out[] = fw_term( $id );
	}
	$fields = $args['fields'] ?? 'all';
	if ( 'ids' === $fields ) {
		return array_map( function ( $t ) { return $t->term_id; }, $out );
	}
	if ( 'names' === $fields ) {
		return array_map( function ( $t ) { return $t->name; }, $out );
	}
	return $out;
}

/** term_exists(): the slug, then the name, as the database compares them. */
function term_exists( $term, $taxonomy = '', $parent = null ) {
	if ( is_int( $term ) ) {
		return isset( $GLOBALS['FW']['tx'][ $term ] ) ? array( 'term_id' => (string) $term, 'term_taxonomy_id' => (string) $term ) : null;
	}
	$term = trim( (string) $term );
	if ( '' === $term ) {
		return null;
	}
	foreach ( array( array( 'slug' => fw_slug( $term ) ), array( 'name' => $term ) ) as $by ) {
		$found = get_terms( array_merge( array( 'taxonomy' => $taxonomy ), $by, null !== $parent ? array( 'parent' => $parent ) : array() ) );
		if ( $found ) {
			return array( 'term_id' => (string) $found[0]->term_id, 'term_taxonomy_id' => (string) $found[0]->term_id );
		}
	}
	return null;
}

/** wp_insert_term(), as WordPress: the name slashed in, cleaned, refused only as strtolower() calls it taken. */
function wp_insert_term( $term, $taxonomy, $args = array() ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
	}
	$name   = wp_unslash( fw_clean_name( (string) $term ) );
	$parent = (int) ( $args['parent'] ?? 0 );
	if ( '' === trim( $name ) ) {
		return new WP_Error( 'empty_term_name', 'A name is required for this term.' );
	}
	$slug_given = ! empty( $args['slug'] );
	$slug       = $slug_given ? (string) $args['slug'] : fw_slug( $name );
	foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'name' => $name ) ) as $match ) {
		if ( is_taxonomy_hierarchical( $taxonomy ) && (int) $match->parent !== $parent ) {
			continue;
		}
		if ( strtolower( $name ) === strtolower( $match->name ) ) {
			if ( ! $slug_given || $match->slug === $slug || get_terms( array( 'taxonomy' => $taxonomy, 'slug' => $slug ) ) ) {
				return new WP_Error( 'term_exists', 'A term with the name provided already exists in this taxonomy.', $match->term_id );
			}
		}
	}
	$base = '' === $slug ? 'term' : $slug;
	$slug = $base;
	for ( $n = 2; get_terms( array( 'taxonomy' => $taxonomy, 'slug' => $slug ) ); $n++ ) {
		$slug = $base . '-' . $n;
	}
	$id = ++$GLOBALS['FW']['next_term'];
	$GLOBALS['FW']['tx'][ $id ]   = array( 'taxonomy' => $taxonomy, 'name' => $name, 'slug' => $slug, 'parent' => $parent );
	$GLOBALS['FW']['inserted'][] = array( $taxonomy, $name );
	return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
}

/** A term the shop made itself: its name as WordPress would store it. */
function fw_shop_term( $taxonomy, $name, $slug = null ) {
	$made = wp_insert_term( wp_slash( $name ), $taxonomy, null !== $slug ? array( 'slug' => $slug ) : array() );
	return is_array( $made ) ? (int) $made['term_id'] : 0;
}

function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
	fw_maybe_die();
	$ids = array();
	foreach ( (array) $terms as $term ) {
		if ( is_int( $term ) ) {
			$ids[] = $term;
			continue;
		}
		$found = term_exists( (string) $term, $taxonomy );
		if ( ! $found && taxonomy_exists( $taxonomy ) ) {
			$made  = wp_insert_term( (string) $term, $taxonomy );
			$found = is_array( $made ) ? $made : null;
		}
		if ( $found ) {
			$ids[] = (int) $found['term_id'];
		}
	}
	$now = $append ? array_merge( $GLOBALS['FW']['ot'][ (int) $object_id ][ $taxonomy ] ?? array(), $ids ) : $ids;
	$GLOBALS['FW']['ot'][ (int) $object_id ][ $taxonomy ] = array_values( array_unique( $now ) );
	$GLOBALS['FW']['term_sets'][]                          = array( (int) $object_id, $taxonomy, $ids );
	return $ids;
}

function wp_get_object_terms( $object_ids, $taxonomies, $args = array() ) {
	$out = array();
	foreach ( (array) $object_ids as $object_id ) {
		foreach ( (array) $taxonomies as $taxonomy ) {
			foreach ( $GLOBALS['FW']['ot'][ (int) $object_id ][ $taxonomy ] ?? array() as $id ) {
				$term = fw_term( $id );
				if ( $term ) {
					$out[] = $term;
				}
			}
		}
	}
	usort( $out, function ( $a, $b ) { return strcmp( $a->name, $b->name ); } );
	$fields = $args['fields'] ?? 'all';
	if ( 'ids' === $fields ) {
		return array_map( function ( $t ) { return $t->term_id; }, $out );
	}
	if ( 'names' === $fields ) {
		return array_map( function ( $t ) { return $t->name; }, $out );
	}
	return $out;
}

// --- WooCommerce's global attributes ----------------------------------------------

function wc_get_attribute_taxonomies() {
	return array_map( function ( $row ) { return (object) $row; }, array_values( $GLOBALS['FW']['attributes'] ) );
}
function wc_attribute_taxonomy_name( $slug ) { return 'pa_' . $slug; }
function wc_attribute_taxonomy_id_by_name( $name ) {
	$slug = 0 === strpos( (string) $name, 'pa_' ) ? substr( (string) $name, 3 ) : (string) $name;
	foreach ( $GLOBALS['FW']['attributes'] as $id => $row ) {
		if ( $row['attribute_name'] === $slug ) {
			return (int) $id;
		}
	}
	return 0;
}
function wc_sanitize_taxonomy_name( $taxonomy ) { return strtolower( urldecode( sanitize_title( urldecode( (string) $taxonomy ) ) ) ); }
function wc_check_if_attribute_name_is_reserved( $attribute_name ) {
	return in_array( $attribute_name, array( 'attachment', 'attachment_id', 'author', 'author_name', 'calendar', 'cat', 'category', 'category__and', 'category__in', 'category__not_in', 'category_name', 'comments_per_page', 'comments_popup', 'cpage', 'day', 'debug', 'error', 'exact', 'feed', 'hour', 'link_category', 'm', 'minute', 'monthnum', 'more', 'name', 'nav_menu', 'nopaging', 'offset', 'order', 'orderby', 'p', 'page', 'page_id', 'paged', 'pagename', 'pb', 'perm', 'post', 'post__in', 'post__not_in', 'post_format', 'post_mime_type', 'post_status', 'post_tag', 'post_type', 'posts', 'posts_per_archive_page', 'posts_per_page', 'preview', 'robots', 's', 'search', 'second', 'sentence', 'showposts', 'static', 'subpost', 'subpost_id', 'tag', 'tag__and', 'tag__in', 'tag__not_in', 'tag_id', 'tag_slug__and', 'tag_slug__in', 'taxonomy', 'tb', 'term', 'type', 'w', 'withcomments', 'withoutcomments', 'year' ), true );
}
/** wc_create_attribute(), as WooCommerce: its refusals, and no taxonomy registered until "init". */
function wc_create_attribute( $args ) {
	$args = wp_unslash( $args );
	if ( empty( $args['name'] ) ) {
		return new WP_Error( 'missing_attribute_name', 'Please, provide an attribute name.' );
	}
	$slug = empty( $args['slug'] ) ? wc_sanitize_taxonomy_name( $args['name'] ) : preg_replace( '/^pa\_/', '', wc_sanitize_taxonomy_name( $args['slug'] ) );
	if ( strlen( $slug ) > 28 ) {
		return new WP_Error( 'invalid_product_attribute_slug_too_long', sprintf( 'Slug "%s" is too long (28 characters max). Shorten it, please.', $slug ) );
	}
	if ( wc_check_if_attribute_name_is_reserved( $slug ) ) {
		return new WP_Error( 'invalid_product_attribute_slug_reserved_name', sprintf( 'Slug "%s" is not allowed because it is a reserved term. Change it, please.', $slug ) );
	}
	if ( taxonomy_exists( wc_attribute_taxonomy_name( $slug ) ) ) {
		return new WP_Error( 'invalid_product_attribute_slug_already_exists', sprintf( 'Slug "%s" is already in use. Change it, please.', $slug ) );
	}
	$id = $GLOBALS['FW']['next_attr']++;
	$GLOBALS['FW']['attributes'][ $id ] = array( 'attribute_id' => $id, 'attribute_name' => $slug, 'attribute_label' => (string) $args['name'], 'attribute_type' => $args['type'] ?? 'select', 'attribute_orderby' => $args['order_by'] ?? 'menu_order', 'attribute_public' => (int) ( $args['has_archives'] ?? 0 ) );
	$GLOBALS['FW']['created'][]         = array( $slug, (string) $args['name'] );
	return $id;
}
/** A global attribute of the shop's, registered as WooCommerce registers them at "init". */
function fw_global_attribute( $slug, $label ) {
	$id = $GLOBALS['FW']['next_attr']++;
	$GLOBALS['FW']['attributes'][ $id ] = array( 'attribute_id' => $id, 'attribute_name' => $slug, 'attribute_label' => $label, 'attribute_type' => 'select', 'attribute_orderby' => 'menu_order', 'attribute_public' => 0 );
	register_taxonomy( 'pa_' . $slug, array( 'product' ), array( 'hierarchical' => false ) );
	return 'pa_' . $slug;
}
/** A global attribute's label; a custom one's name. */
function wc_attribute_label( $name, $product = null ) {
	if ( 0 === strpos( (string) $name, 'pa_' ) ) {
		$id = wc_attribute_taxonomy_id_by_name( $name );
		return $id ? $GLOBALS['FW']['attributes'][ $id ]['attribute_label'] : substr( (string) $name, 3 );
	}
	return (string) $name;
}
/** wc_get_product_terms(): the product's terms in the taxonomy, by their order (all 0) then name, as names. */
function wc_get_product_terms( $product_id, $taxonomy, $args = array() ) {
	return taxonomy_exists( $taxonomy ) ? wp_get_object_terms( $product_id, $taxonomy, $args ) : array();
}
function wc_get_text_attributes( $raw_attributes ) {
	return array_filter( array_map( 'trim', explode( '|', html_entity_decode( (string) $raw_attributes, ENT_QUOTES, 'UTF-8' ) ) ), function ( $value ) {
		return '' !== $value;
	} );
}
function wc_implode_text_attributes( $attributes ) { return implode( ' | ', (array) $attributes ); }

// --- WooCommerce's caches, recorded -----------------------------------------------

function wc_delete_product_transients( $post_id = 0 ) {
	fw_maybe_die();
	$GLOBALS['FW']['cleared'][] = array( 'transients', (int) $post_id );
}
class WC_Cache_Helper {
	public static function invalidate_cache_group( $group ) { $GLOBALS['FW']['cleared'][] = array( 'group', (string) $group ); }
	public static function invalidate_attribute_count( $attribute_keys ) { $GLOBALS['FW']['cleared'][] = array( 'counts', implode( ',', (array) $attribute_keys ) ); }
}
require __DIR__ . '/filterable-lookup.php';
$GLOBALS['FW']['lookup'] = new Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore();
/** WooCommerce's container, as far as the lookup table goes. */
class FW_Container {
	public function get( $id ) { return $GLOBALS['FW']['lookup']; }
}
function wc_get_container() { return new FW_Container(); }
/**
 * WordPress's meta cache (fix round 1): update_meta_cache() keeps a copy of
 * each post's meta, and get_post_meta() answers from it until it is cleared
 * - by this request's own update_post_meta(), delete_post_meta(),
 * wp_cache_delete( $id, 'post_meta' ) or clean_post_cache(). A write by
 * another request (a harness writing $GLOBALS['W']['meta'] itself) does not
 * clear it. WordPress also caches every read it was not primed for
 * (get_metadata_raw()); here only while $GLOBALS['FW']['cache_reads'] is on.
 */
function update_meta_cache( $type, $ids ) {
	foreach ( (array) $ids as $id ) {
		$GLOBALS['FW']['primed']++;
		if ( ! isset( $GLOBALS['FW']['meta_cache'][ (int) $id ] ) ) {
			$GLOBALS['FW']['meta_cache'][ (int) $id ] = $GLOBALS['W']['meta'][ (int) $id ] ?? array();
		}
	}
	return array();
}
function get_post_meta( $id, $key = '', $single = false ) {
	$id = (int) $id;
	if ( isset( $GLOBALS['FW']['meta_cache'][ $id ] ) ) {
		$all = $GLOBALS['FW']['meta_cache'][ $id ];
	} else {
		$all = $GLOBALS['W']['meta'][ $id ] ?? array();
		if ( ! empty( $GLOBALS['FW']['cache_reads'] ) ) {
			$GLOBALS['FW']['meta_cache'][ $id ] = $all;
		}
	}
	if ( '' === $key ) {
		return $all;
	}
	if ( ! array_key_exists( $key, $all ) ) {
		return $single ? '' : array();
	}
	return $single ? $all[ $key ] : array( $all[ $key ] );
}
function update_post_meta( $id, $key, $value ) {
	unset( $GLOBALS['FW']['meta_cache'][ (int) $id ] );
	// As WordPress does: the value is unslashed on the way in.
	$GLOBALS['W']['meta'][ (int) $id ][ $key ] = wp_unslash( $value );
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['FW']['meta_cache'][ (int) $id ], $GLOBALS['W']['meta'][ (int) $id ][ $key ] );
	return true;
}
function wp_cache_delete( $key, $group = '' ) {
	if ( 'post_meta' === $group ) {
		unset( $GLOBALS['FW']['meta_cache'][ (int) $key ] );
	}
	return true;
}
/** clean_post_cache(): the post's caches, its meta's among them; recorded. */
function clean_post_cache( $post ) {
	$id = is_object( $post ) ? (int) $post->ID : (int) $post;
	unset( $GLOBALS['FW']['meta_cache'][ $id ] );
	$GLOBALS['FW']['cleaned'][] = $id;
}
function wp_count_posts( $type = 'post' ) {
	$out = array( 'publish' => 0, 'private' => 0, 'draft' => 0, 'pending' => 0, 'future' => 0, 'trash' => 0 );
	foreach ( $GLOBALS['W']['products'] as $id => $product ) {
		if ( $id > 0 && ! $product instanceof WC_Product_Variation ) {
			$out[ $product->get_status() ] = ( $out[ $product->get_status() ] ?? 0 ) + 1;
		}
	}
	return (object) $out;
}

// --- the mapper's reads of WordPress ------------------------------------------------

function get_post_status( $id ) { return isset( $GLOBALS['W']['products'][ (int) $id ] ) ? $GLOBALS['W']['products'][ (int) $id ]->get_status() : false; }
function get_post_type( $id ) { return isset( $GLOBALS['W']['products'][ (int) $id ] ) ? 'product' : false; }
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
function get_user_by( $field, $value ) { return false; }

// --- WooCommerce's attribute, as it is ------------------------------------------------

class WC_Product_Attribute {
	protected $data = array( 'id' => 0, 'name' => '', 'options' => array(), 'position' => 0, 'visible' => false, 'variation' => false );
	/** As the template world's, for a harness that builds one in a line: name, options, taxonomy, variation. */
	public function __construct( $name = '', array $options = array(), $taxonomy = false, $variation = false ) {
		$this->data['name']      = (string) $name;
		$this->data['options']   = $options;
		$this->data['variation'] = (bool) $variation;
		if ( $taxonomy ) {
			$this->data['id'] = wc_attribute_taxonomy_id_by_name( $name );
		}
	}
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

require __DIR__ . '/resend-world.php';

// The mapper's other neighbours.
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS         = array();
	const AUCTION_LISTED_KEY  = '_ebay_auction_listed_item';
	const AUCTION_FIGURE_KEYS = array();
	public static function __callStatic( $name, $args ) { return null; }
	public static function restocked_after_website_sale( $product_id ) { return 0; }
	public static function listing_already_settled( $product_id, $item_id ) { return false; }
	public static function ended_by_shop( $product_id, $item_id ) { return false; }
}
class TCGiant_Sync_Image_Localizer {
	public static function set_external_images() {}
	public static function __callStatic( $name, $args ) { return null; }
}
foreach ( array( 'product_cat' => true, 'product_tag' => false, 'product_type' => false ) as $fw_tax => $fw_tree ) {
	register_taxonomy( $fw_tax, array( 'product' ), array( 'hierarchical' => $fw_tree ) );
}

/**
 * A product whose attributes are kept as WooCommerce keeps them. Every save
 * dates it (its 'modified', what WooCommerce writes to post_modified) and is
 * counted.
 */
trait FW_Attributes {
	public $pending = null;
	public $unsaved = array();
	public function __construct( $id = 0, array $data = array() ) {
		parent::__construct( $id, $data );
	}
	/** A new product's meta waits for its save, as WooCommerce's does. */
	public function get_meta( $key, $single = true ) {
		return 0 === $this->id ? ( $this->unsaved[ $key ] ?? '' ) : parent::get_meta( $key, $single );
	}
	public function update_meta_data( $key, $value ) {
		if ( 0 === $this->id ) {
			$this->unsaved[ $key ] = $value;
		} else {
			parent::update_meta_data( $key, $value );
		}
	}
	public function delete_meta_data( $key ) {
		if ( 0 === $this->id ) {
			unset( $this->unsaved[ $key ] );
		} else {
			parent::delete_meta_data( $key );
		}
	}
	public function get_attributes( $context = 'view' ) {
		return null !== $this->pending ? $this->pending : fw_read_attributes( $this->id );
	}
	public function set_attributes( $raw ) {
		$attributes = array_fill_keys( array_keys( $this->get_attributes() ), null );
		foreach ( (array) $raw as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute ) {
				$attributes[ sanitize_title( $attribute->get_name() ) ] = $attribute;
			}
		}
		fw_by_position( $attributes );
		$this->pending = $attributes;
	}
	public function save() {
		if ( 0 === $this->id ) {
			$this->id = ++$GLOBALS['W']['next_id'];
			$GLOBALS['W']['products'][ $this->id ] = $this;
			unset( $GLOBALS['W']['products'][0] );
			$GLOBALS['W']['posts'][ $this->id ] = new WP_Post( array( 'ID' => $this->id, 'post_type' => 'product', 'post_status' => 'publish' ) );
			foreach ( $this->unsaved as $key => $value ) {
				$GLOBALS['W']['meta'][ $this->id ][ $key ] = $value;
			}
			$this->unsaved = array();
		}
		if ( null !== $this->pending ) {
			fw_write_attributes( $this->id, $this->pending );
			$this->pending = null;
		}
		$this->data['modified']                 = time() + ( $GLOBALS['FW']['clock'] ?? 0 );
		$GLOBALS['FW']['saves'][ $this->id ] = ( $GLOBALS['FW']['saves'][ $this->id ] ?? 0 ) + 1;
		return $this->id;
	}
	public function get_category_ids( $context = 'view' ) { return $this->data['categories']; }
	public function set_category_ids( $ids ) { $this->data['categories'] = array_values( array_map( 'intval', (array) $ids ) ); }
	public function get_tag_ids( $context = 'view' ) { return $this->data['tags'] ?? array(); }
	public function set_tag_ids( $ids ) { $this->data['tags'] = array_map( 'intval', (array) $ids ); }
	public function set_name( $name ) { $this->data['name'] = (string) $name; }
	public function set_description( $text ) { $this->data['description'] = (string) $text; }
	public function set_short_description( $text ) { $this->data['short_description'] = (string) $text; }
	public function set_sku( $sku ) { $this->data['sku'] = (string) $sku; }
	public function set_regular_price( $price ) { $this->data['regular_price'] = (string) $price; }
	public function set_stock_quantity( $qty ) { $this->data['stock'] = null === $qty ? null : (int) $qty; }
	public function set_status( $status ) { $this->data['status'] = (string) $status; }
	public function __call( $name, $args ) { return null; }
}
class FW_Product extends WC_Product {
	use FW_Attributes;
}
class WC_Product_Simple extends FW_Product {}

/** A variable product: its axes in get_variation_attributes(), as WooCommerce lists them, and its variations. */
class FW_Product_Variable extends WC_Product_Variable {
	use FW_Attributes;
	public $children = array();
	public function get_children() { return $this->children; }
}
/** A variation, as the exporter reads one. */
class FW_Product_Variation extends WC_Product_Variation {
	public function get_parent_id() { return (int) ( $this->data['parent'] ?? 0 ); }
	public function get_variation_attributes() { return array( 'attribute_size' => $this->data['size'] ?? '' ); }
	public function get_attributes() { return array( 'size' => $this->data['size'] ?? '' ); }
	public function get_category_ids( $context = 'view' ) { return array(); }
}

/** WooCommerce's order of attributes: by position, ties as they came (wc_product_attribute_uasort_comparison()). */
function fw_by_position( array &$attributes ) {
	$n = 0;
	$at = array();
	foreach ( $attributes as $key => $attribute ) {
		$at[ $key ] = array( null === $attribute ? null : $attribute->get_position(), $n++ );
	}
	uksort( $attributes, function ( $a, $b ) use ( $at ) {
		return $at[ $a ][0] <=> $at[ $b ][0] ?: $at[ $a ][1] <=> $at[ $b ][1];
	} );
}

/** WC_Product_Data_Store_CPT::read_attributes(). */
function fw_read_attributes( $id ) {
	$out  = array();
	$meta = get_post_meta( $id, '_product_attributes', true );
	foreach ( is_array( $meta ) ? $meta : array() as $row ) {
		$row = array_merge( array( 'name' => '', 'value' => '', 'position' => 0, 'is_visible' => 0, 'is_variation' => 0, 'is_taxonomy' => 0 ), (array) $row );
		if ( ! empty( $row['is_taxonomy'] ) ) {
			if ( ! taxonomy_exists( $row['name'] ) ) {
				continue;
			}
			$attr_id = wc_attribute_taxonomy_id_by_name( $row['name'] );
			$options = array_map( 'intval', $GLOBALS['FW']['ot'][ (int) $id ][ $row['name'] ] ?? array() );
		} else {
			$attr_id = 0;
			$options = wc_get_text_attributes( $row['value'] );
		}
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $attr_id );
		$attribute->set_name( $row['name'] );
		$attribute->set_options( $options );
		$attribute->set_position( $row['position'] );
		$attribute->set_visible( $row['is_visible'] );
		$attribute->set_variation( $row['is_variation'] );
		$out[ sanitize_title( $row['name'] ) ] = $attribute;
	}
	fw_by_position( $out );
	return $out;
}

/** WC_Product_Data_Store_CPT::update_attributes(): a removed key's terms taken off, a global one's set from its term IDs (or names, made where none). */
function fw_write_attributes( $id, array $attributes ) {
	$rows = array();
	foreach ( $attributes as $key => $attribute ) {
		if ( null === $attribute ) {
			if ( taxonomy_exists( $key ) ) {
				wp_set_object_terms( $id, array(), $key );
			}
			continue;
		}
		$value = '';
		if ( $attribute->is_taxonomy() ) {
			$ids = array();
			foreach ( (array) $attribute->get_options() as $option ) {
				if ( is_int( $option ) ) {
					$ids[] = $option;
				} else {
					$found = term_exists( (string) $option, $attribute->get_name() );
					$ids[] = $found ? (int) $found['term_id'] : (int) ( wp_insert_term( (string) $option, $attribute->get_name() )['term_id'] ?? 0 );
				}
			}
			wp_set_object_terms( $id, $ids, $attribute->get_name() );
		} else {
			$value = wc_implode_text_attributes( $attribute->get_options() );
		}
		$rows[ $key ] = array(
			'name'         => $attribute->get_name(),
			'value'        => $value,
			'position'     => $attribute->get_position(),
			'is_visible'   => $attribute->get_visible() ? 1 : 0,
			'is_variation' => $attribute->get_variation() ? 1 : 0,
			'is_taxonomy'  => $attribute->is_taxonomy() ? 1 : 0,
		);
	}
	update_post_meta( $id, '_product_attributes', wp_slash( $rows ) );
}

require $ROOT . '/includes/class-tcgiant-sync-mapper.php';
require $ROOT . '/includes/class-tcgiant-sync-filterable.php';
TCGiant_Sync_Filterable::register();

// --- the shop --------------------------------------------------------------------------

/**
 * A product of the shop's, linked to a live fixed-price listing pushed an
 * hour ago and not saved since, with its row on the Listings table (as the
 * re-send world's listing()), and these attributes as WooCommerce keeps
 * them: each array( name, value or term IDs, position, visible, variation ),
 * a global one by its taxonomy.
 */
function fw_listing( $id, array $attributes, array $data = array(), array $meta = array() ) {
	unset( $GLOBALS['W']['meta'][ $id ], $GLOBALS['FW']['ot'][ $id ] );
	$product = new FW_Product( $id, array_merge( array(
		'name'        => 'Card ' . $id,
		'description' => 'Near mint card number ' . $id . '.',
		'sku'         => 'SKU-' . $id,
		'stock'       => 3,
		'modified'    => time() - 3700,
	), $data ) );
	$GLOBALS['W']['posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => 'product', 'post_status' => 'publish' ) );
	foreach ( array_merge( array(
		'_ebay_item_id'            => (string) ( 110000000000 + $id ),
		'_ebay_listing_status'     => 'Active',
		'_ebay_listing_type'       => 'FixedPriceItem',
		'_ebay_site'               => 'US',
		'_ebay_export_last_pushed' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
		'_ebay_origin'             => 'push',
	), $meta ) as $key => $value ) {
		if ( null !== $value ) {
			$GLOBALS['W']['meta'][ $id ][ $key ] = $value;
		}
	}
	$rows = array();
	foreach ( $attributes as $n => $a ) {
		$global = 0 === strpos( $a[0], 'pa_' );
		$rows[ sanitize_title( $a[0] ) ] = array(
			'name'         => $a[0],
			'value'        => $global ? '' : $a[1],
			'position'     => $a[2] ?? $n,
			'is_visible'   => (int) ( $a[3] ?? 1 ),
			'is_variation' => (int) ( $a[4] ?? 0 ),
			'is_taxonomy'  => $global ? 1 : 0,
		);
		if ( $global ) {
			$GLOBALS['FW']['ot'][ $id ][ $a[0] ] = array_map( 'intval', (array) $a[1] );
		}
	}
	if ( $rows ) {
		$GLOBALS['W']['meta'][ $id ]['_product_attributes'] = $rows;
	}
	TCGiant_Sync_DB::$rows[ $id ] = array( 'product_id' => $id, 'ebay_title' => $product->get_name(), 'last_synced' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
	return $product;
}

/** A clean shop: no products, terms, attributes, choice, run or count; the shop's settings as a push needs them. */
function fw_fresh() {
	$GLOBALS['W']['meta']       = array();
	$GLOBALS['W']['products']   = array();
	$GLOBALS['W']['posts']      = array();
	$GLOBALS['W']['log']        = array();
	$GLOBALS['W']['transients'] = array();
	$GLOBALS['W']['autoload']   = array();
	$GLOBALS['W']['next_id']    = 1000;
	foreach ( array_keys( $GLOBALS['W']['options'] ) as $name ) {
		if ( 0 === strpos( $name, 'tcgiant_sync_filterable' ) || 0 === strpos( $name, 'tcgiant_sync_tpl_resend' ) ) {
			unset( $GLOBALS['W']['options'][ $name ] );
		}
	}
	$GLOBALS['FW']['tx']         = array();
	$GLOBALS['FW']['ot']         = array();
	$GLOBALS['FW']['attributes'] = array();
	$GLOBALS['FW']['term_sets']  = array();
	$GLOBALS['FW']['inserted']   = array();
	$GLOBALS['FW']['created']    = array();
	$GLOBALS['FW']['cleared']    = array();
	$GLOBALS['FW']['saves']      = array();
	$GLOBALS['FW']['meta_cache'] = array();
	$GLOBALS['FW']['cache_reads'] = false;
	$GLOBALS['FW']['cleaned']    = array();
	$GLOBALS['FW']['lookup']->changed = array();
	foreach ( array_keys( $GLOBALS['FW']['taxonomies'] ) as $taxonomy ) {
		if ( 0 === strpos( $taxonomy, 'pa_' ) || TCGiant_Sync_Filterable::BRAND_TAXONOMY === $taxonomy ) {
			unset( $GLOBALS['FW']['taxonomies'][ $taxonomy ] );
		}
	}
	$GLOBALS['wpdb']->rows    = array();
	$GLOBALS['AS']['actions'] = array();
	$GLOBALS['AS']['ran']     = array();
	TCGiant_Sync_DB::$rows    = array();
	TCGiant_Sync_Runtime::$late = '';
	$GLOBALS['API']->calls    = array();
	update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, TCGiant_Sync_Templates::default_settings() );
}

/** WooCommerce Brands on this site (core from 9.6): its taxonomy registered. */
function fw_brands_on() {
	register_taxonomy( TCGiant_Sync_Filterable::BRAND_TAXONOMY, array( 'product' ), array( 'hierarchical' => true ) );
}

/** Count now: the count's batches run until done; its names. */
function fw_count() {
	TCGiant_Sync_Filterable::queue_count();
	as_run( TCGiant_Sync_Filterable::COUNT_HOOK, true );
	return TCGiant_Sync_Filterable::counted();
}

/** Choose these names, as Save does, and run the conversion through; Save's answer. */
function fw_choose( array $names, $brands = true, $run = true ) {
	$said = TCGiant_Sync_Filterable::save_choice( $names, $brands );
	if ( $run ) {
		as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
	}
	return $said;
}

/** A product's attributes as kept: key => "name: values" - a global one's by term name - and [hidden], [v]. */
function fw_attrs( $id ) {
	$out = array();
	foreach ( (array) ( $GLOBALS['W']['meta'][ $id ]['_product_attributes'] ?? array() ) as $key => $row ) {
		$values = ! empty( $row['is_taxonomy'] ) ? wp_get_object_terms( $id, $row['name'], array( 'fields' => 'names' ) ) : array( $row['value'] );
		$out[ $key ] = $row['name'] . ': ' . implode( '|', $values ) . ' @' . $row['position'] . ( empty( $row['is_visible'] ) ? ' [hidden]' : '' ) . ( ! empty( $row['is_variation'] ) ? ' [v]' : '' );
	}
	return $out;
}

/** A product's terms in a taxonomy, by name. */
function fw_terms_of( $id, $taxonomy ) { return wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'names' ) ); }

/** Every term of a taxonomy: slug => name. */
function fw_terms( $taxonomy ) {
	$out = array();
	foreach ( get_terms( array( 'taxonomy' => $taxonomy ) ) as $term ) {
		$out[ $term->slug ] = $term->name;
	}
	return $out;
}

/** The log's lines holding these words. */
function fw_logged( $words ) {
	$out = array();
	foreach ( $GLOBALS['W']['log'] as $entry ) {
		if ( false !== strpos( $entry[1], $words ) ) {
			$out[] = $entry[1];
		}
	}
	return $out;
}

/** The import of a listing, as every import path makes it. */
function fw_import( array $item ) {
	$mapper = new TCGiant_Sync_Mapper();
	$data   = $mapper->map_ebay_to_woo( $item );
	return $mapper->save_as_product( $data );
}

/** A listing as GetItem answers it, with these item specifics. */
function fw_ebay_item( $item_id, array $specifics, array $more = array() ) {
	$nvl = array();
	foreach ( $specifics as $name => $value ) {
		$nvl[] = array( 'Name' => $name, 'Value' => $value );
	}
	return array_merge( array(
		'ItemID'          => (string) $item_id,
		'Title'           => 'Card ' . $item_id,
		'SKU'             => 'SKU-' . $item_id,
		'Site'            => 'US',
		'Currency'        => 'USD',
		'Quantity'        => '3',
		'ListingType'     => 'FixedPriceItem',
		'ListingDuration' => 'GTC',
		'Description'     => 'Near mint.',
		'ListingDetails'  => array( 'StartTime' => '2026-01-05T10:00:00.000Z', 'EndTime' => '2026-12-05T10:00:00.000Z' ),
		'SellingStatus'   => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0', 'CurrentPrice' => '25.0' ),
		'PrimaryCategory' => array( 'CategoryID' => '183454' ),
		'ItemSpecifics'   => array( 'NameValueList' => $nvl ),
	), $more );
}
