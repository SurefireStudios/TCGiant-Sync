<?php
/**
 * Filterable item specifics (3.35.0): the eBay item specifics a shop picks,
 * made into WooCommerce global attributes its filters can use, and Brand
 * into WooCommerce Brands.
 *
 * The import saves every item specific on the product as an attribute of
 * its own (a custom attribute). WooCommerce's filters - the Product Filters
 * block, the layered-nav widget - only use global attributes, the pa_*
 * taxonomies of Products > Attributes, and its Brands only the product_brand
 * taxonomy. So a shop that imports from eBay has the data and cannot filter
 * by any of it.
 *
 * Nothing changes until the shop chooses. Settings lists the names its
 * products have, with how many products have each and how many values it
 * has; a name ticked there becomes one global attribute - the shop's own of
 * the same label, or a new one - and then:
 *
 * - The products already imported are converted on the website, in batches
 *   in Action Scheduler: each one's custom attribute of that name becomes the
 *   global one, its value a term, in the same place in the list and seen or
 *   hidden as it was. Nothing is asked of eBay. It writes the attribute list
 *   and the terms directly, with WooCommerce's caches cleared, and never
 *   saves the product: a save would move its date, and Re-send to eBay
 *   (3.34.0) holds a listing whose product was saved since its last push.
 * - Imports and syncs from then on write the name as the global attribute
 *   (TCGiant_Sync_Mapper::save_as_product(), for_import()).
 * - A push reads a global attribute by its label and its terms' names
 *   (TCGiant_Sync_Exporter::collect_attribute_specifics()), so what it sends
 *   is what it sent before. A value is converted only where the term keeps
 *   it exactly, and only an attribute with one value; and where the exporter
 *   reads the attribute's key (a template's {{attr.x}}, the plain
 *   description's fallback list), it reads one this plugin converted as it
 *   read the custom one (as_custom()).
 *
 * Taking a name off the list turns the plugin's own conversions back into
 * custom attributes. Each product records what this plugin converted on it
 * (META), so a global attribute the shop set itself is never touched; the
 * global attribute and its terms stay, for the shop to delete if it wants.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Filterable class.
 */
class TCGiant_Sync_Filterable {

	/** The shop's choice: names (eBay name => taxonomy) and brands. Not autoloaded. */
	const CHOICE_OPTION = 'tcgiant_sync_filterable';

	/** The names on the shop's products, counted: done, or doing. Not autoloaded. */
	const CHOICES_OPTION = 'tcgiant_sync_filterable_choices';

	/** The conversion run: where it is, and what it has done. Not autoloaded. */
	const STATE_OPTION = 'tcgiant_sync_filterable_state';

	/** One run, and one count, at a time: an options row each. */
	const LOCK_PREFIX = 'tcgiant_sync_filterable_lock_';

	/**
	 * On each product, what this plugin converted there: taxonomy => name
	 * (the eBay name) and run (the run's ID, or "import"); and product_brand
	 * => term and run, a brand it added.
	 */
	const META = '_tcgiant_filterable';

	/** A batch of the conversion run (Action Scheduler). */
	const CONVERT_HOOK = 'tcgiant_sync_filterable_run';

	/** A batch of the count (Action Scheduler). */
	const COUNT_HOOK = 'tcgiant_sync_filterable_count';

	/** Their Action Scheduler group. */
	const GROUP = 'tcgiant_sync_filterable';

	/** The Settings form's action and nonce. */
	const FORM = 'tcgiant_sync_filterable';

	/** Products a batch of the run settles. */
	const BATCH = 200;

	/** Products a batch of the count reads. */
	const COUNT_BATCH = 500;

	/** A batch stopped for its request's time goes on this much later, in another request. */
	const LATE_DELAY = 60;

	/** A hold lasts at most this long; a run or count still for longer is carried on. */
	const LOCK_SECONDS = 600;

	/** Above this many values a name is offered with a warning. */
	const WARN_VALUES = 200;

	/** Above this many values a name is not offered. */
	const REFUSE_VALUES = 2000;

	/** WooCommerce's limit on an attribute's slug. */
	const SLUG_MAX = 28;

	/** eBay's name for the brand. */
	const BRAND = 'Brand';

	/** WooCommerce Brands (core from WooCommerce 9.6). */
	const BRAND_TAXONOMY = 'product_brand';

	/** Names suggested where the shop's products have them. */
	const SUGGESTED = array( 'Brand', 'Set', 'Grade' );

	/**
	 * Names that identify a single item, refused whatever their count: a
	 * filter would list a value for nearly every product.
	 */
	const IDENTIFIERS = array(
		'card name',
		'card number',
		'certification number',
		'cert number',
		'upc',
		'ean',
		'isbn',
		'mpn',
		'gtin',
		'jan',
		'epid',
		'sku',
		'serial number',
		'part number',
		'manufacturer part number',
		'model number',
		'item number',
		'catalog number',
		'barcode',
	);

	/** How many product IDs the log names for each reason a product was skipped. */
	const SAMPLES = 5;

	/**
	 * The holds this request has, by what they hold.
	 *
	 * @var array<string, string>
	 */
	private static $held = array();

	/**
	 * Register the batches, on every request: Action Scheduler runs them from
	 * WP-Cron and its own requests as well as the admin.
	 */
	public static function register() {
		add_action( self::CONVERT_HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( self::COUNT_HOOK, array( __CLASS__, 'run_count' ) );
		add_action( 'admin_post_tcgiant_sync_filterable', array( __CLASS__, 'handle_form' ) ); // FORM.
	}

	// -------------------------------------------------------------------------
	// The choice
	// -------------------------------------------------------------------------

	/**
	 * The shop's choice, as saved.
	 *
	 * @return array names (eBay name => taxonomy), brands (bool), saved.
	 */
	public static function choice() {
		$choice = self::read( self::CHOICE_OPTION );
		return array(
			'names'  => is_array( $choice['names'] ?? null ) ? $choice['names'] : array(),
			// On by default once Brand is chosen (the owner's decision 5).
			'brands' => array_key_exists( 'brands', $choice ) ? (bool) $choice['brands'] : true,
			'saved'  => (int) ( $choice['saved'] ?? 0 ),
		);
	}

	/**
	 * The names chosen whose global attribute is there: eBay name => taxonomy.
	 *
	 * @return array<string, string>
	 */
	public static function chosen() {
		$out = array();
		foreach ( self::choice()['names'] as $name => $taxonomy ) {
			if ( '' !== (string) $taxonomy && taxonomy_exists( (string) $taxonomy ) ) {
				$out[ (string) $name ] = (string) $taxonomy;
			}
		}
		return $out;
	}

	/**
	 * Whether Brand goes into WooCommerce Brands: chosen, the box ticked, and
	 * WooCommerce Brands on this site.
	 *
	 * @param array $names eBay name => taxonomy.
	 * @param bool  $brands The box.
	 * @return bool
	 */
	private static function brands_apply( array $names, $brands ) {
		return $brands && isset( $names[ self::BRAND ] ) && taxonomy_exists( self::BRAND_TAXONOMY );
	}

	/**
	 * Why a name may not be made filterable, or ''.
	 *
	 * @param string     $name    The eBay name.
	 * @param array|null $counted Its row in the count.
	 * @return string
	 */
	public static function refusal( $name, $counted ) {
		if ( self::identifier_like( $name ) ) {
			return __( 'Not offered: it names one item - a number, a code or a card\'s own name - so a filter would have a value for nearly every product.', 'tcgiant-sync' );
		}
		if ( is_array( $counted ) && ( ! empty( $counted['over'] ) || (int) ( $counted['values'] ?? 0 ) > self::REFUSE_VALUES ) ) {
			/* translators: %s: 2,000 */
			return sprintf( __( 'Not offered: it has over %s different values, too many for a filter to be any help.', 'tcgiant-sync' ), number_format_i18n( self::REFUSE_VALUES ) );
		}
		return '';
	}

	/**
	 * A warning for a name that may be chosen, or ''.
	 *
	 * @param array|null $counted Its row in the count.
	 * @return string
	 */
	public static function warning( $counted ) {
		$values = is_array( $counted ) ? (int) ( $counted['values'] ?? 0 ) : 0;
		if ( $values > self::WARN_VALUES && empty( $counted['over'] ) ) {
			/* translators: %s: a number of values */
			return sprintf( __( '%s different values: a long list for a filter.', 'tcgiant-sync' ), number_format_i18n( $values ) );
		}
		return '';
	}

	/**
	 * Whether a name identifies a single item: one of IDENTIFIERS, a name
	 * ending in "Number", "No." or "#" (not "Number of ..."), or one naming a
	 * serial, a certificate, a barcode or a product code.
	 *
	 * @param string $name The eBay name.
	 * @return bool
	 */
	public static function identifier_like( $name ) {
		$key = strtolower( trim( (string) preg_replace( '/\s+/', ' ', (string) $name ) ) );
		if ( in_array( $key, self::IDENTIFIERS, true ) ) {
			return true;
		}
		if ( preg_match( '/(?:\bnumber|\bno\.?|#)$/', $key ) ) {
			return true;
		}
		return (bool) preg_match( '/\b(?:serial|certification|cert|isbn|upc|ean|gtin|mpn|sku|barcode|epid)\b/', $key );
	}

	/**
	 * Save the shop's choice, and start a run to bring every product to it.
	 *
	 * A name newly ticked must be in the count and pass the guards, and gets
	 * its global attribute now - the shop's own of the same label, or a new
	 * one - so that a refusal is said at once. A name already chosen stays
	 * while it is ticked, whatever its count says now. A name unticked is
	 * turned back by the run.
	 *
	 * @param string[] $names  The names ticked.
	 * @param bool     $brands The Brands box.
	 * @return array ok, refused (name => why), started, message.
	 */
	public static function save_choice( array $names, $brands ) {
		$choice  = self::choice();
		$was     = $choice['names'];
		$counted = self::counted();
		$new     = array();
		$refused = array();

		foreach ( array_unique( array_map( 'strval', $names ) ) as $name ) {
			if ( '' === trim( $name ) ) {
				continue;
			}
			if ( isset( $was[ $name ] ) ) {
				// Kept. Its attribute found again, or made again if the shop
				// deleted it; the one it had if neither can be done.
				$taxonomy     = taxonomy_exists( (string) $was[ $name ] ) ? (string) $was[ $name ] : self::resolve( $name );
				$new[ $name ] = is_wp_error( $taxonomy ) ? (string) $was[ $name ] : $taxonomy;
				continue;
			}
			if ( ! isset( $counted[ $name ] ) ) {
				continue;
			}
			$taxonomy = '';
			$why      = self::refusal( $name, $counted[ $name ] );
			if ( '' === $why ) {
				$taxonomy = self::resolve( $name );
				if ( is_wp_error( $taxonomy ) ) {
					$why = $taxonomy->get_error_message();
				}
			}
			if ( '' !== $why ) {
				$refused[ $name ] = $why;
				continue;
			}
			$new[ $name ] = $taxonomy;
		}

		ksort( $new );
		ksort( $was );
		$brands  = (bool) $brands;
		$changed = $new !== $was || ( $brands !== $choice['brands'] && isset( $new[ self::BRAND ] ) );
		self::write( self::CHOICE_OPTION, array( 'names' => $new, 'brands' => $brands, 'saved' => time() ) );

		$started = $changed ? self::start( $was, $new, $brands ) : false;

		$words = array();
		if ( $started ) {
			$words[] = __( 'Saved. The products that have these item specifics are being converted in the background; this section shows how far it has got. Nothing is sent to eBay, and what a push sends does not change.', 'tcgiant-sync' );
		} elseif ( $changed ) {
			$words[] = __( 'Saved, but WooCommerce\'s background queue (Action Scheduler) is not available, so your products could not be converted. Imports and syncs write the item specifics you ticked as global attributes from now on.', 'tcgiant-sync' );
		} else {
			$words[] = __( 'Saved. Nothing needed changing.', 'tcgiant-sync' );
		}
		foreach ( $refused as $name => $why ) {
			$words[] = '"' . $name . '": ' . $why;
		}

		return array(
			'ok'      => empty( $refused ),
			'refused' => $refused,
			'started' => $started,
			'message' => implode( ' ', $words ),
		);
	}

	// -------------------------------------------------------------------------
	// The global attribute
	// -------------------------------------------------------------------------

	/**
	 * The global attribute for an eBay name: the shop's own whose label is the
	 * name, or a new one labelled with it. A label that differs only in its
	 * capitals or spaces is not used, and not made again beside it: a push
	 * sends the label, which would then not be eBay's name.
	 *
	 * @param string $name The eBay name.
	 * @return string|WP_Error The taxonomy, pa_*.
	 */
	public static function resolve( $name ) {
		$name  = (string) $name;
		$loose = null;
		foreach ( self::attribute_taxonomies() as $attribute ) {
			$label = (string) ( $attribute->attribute_label ?? '' );
			if ( $label === $name ) {
				return self::registered( (string) $attribute->attribute_name, $label );
			}
			if ( null === $loose && strtolower( trim( $label ) ) === strtolower( trim( $name ) ) ) {
				$loose = $label;
			}
		}
		if ( null !== $loose ) {
			return new WP_Error( 'tcgiant_filterable_label', sprintf(
				/* translators: 1: the shop's attribute's label, 2: eBay's name */
				__( 'Not made filterable: your global attribute "%1$s" (Products > Attributes) differs from eBay\'s "%2$s" only in its capitals or spaces. Rename it to "%2$s" there, then tick this again.', 'tcgiant-sync' ),
				$loose,
				$name
			) );
		}

		if ( ! function_exists( 'wc_create_attribute' ) ) {
			return new WP_Error( 'tcgiant_filterable_wc', __( 'Not made filterable: WooCommerce could not make the global attribute.', 'tcgiant-sync' ) );
		}
		$slug = self::slug_for( $name );
		if ( '' === $slug ) {
			return new WP_Error( 'tcgiant_filterable_slug', __( 'Not made filterable: no name WooCommerce accepts was free for its global attribute.', 'tcgiant-sync' ) );
		}
		$made = wc_create_attribute( array(
			'name'         => $name,
			'slug'         => $slug,
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		) );
		if ( is_wp_error( $made ) ) {
			/* translators: %s: WooCommerce's words */
			return new WP_Error( 'tcgiant_filterable_made', sprintf( __( 'Not made filterable: WooCommerce said "%s".', 'tcgiant-sync' ), $made->get_error_message() ) );
		}

		TCGiant_Sync_Logger::log( sprintf( 'Filterable item specifics: made the global attribute "%1$s" (pa_%2$s) for eBay\'s item specific of that name.', $name, $slug ), 'info' );
		return self::registered( $slug, $name );
	}

	/**
	 * A global attribute's taxonomy, registered for this request if it is not
	 * yet: WooCommerce registers them at init, so one made in this request is
	 * not, and its terms could not be written until the next.
	 *
	 * @param string $slug  The attribute's slug, without pa_.
	 * @param string $label Its label.
	 * @return string The taxonomy.
	 */
	private static function registered( $slug, $label ) {
		$taxonomy = 'pa_' . $slug;
		if ( ! taxonomy_exists( $taxonomy ) && function_exists( 'register_taxonomy' ) ) {
			// As WooCommerce's own product importer registers one it has just made.
			register_taxonomy( $taxonomy, array( 'product' ), array(
				'labels'       => array( 'name' => $label ),
				'hierarchical' => false,
				'show_ui'      => false,
				'query_var'    => true,
				'rewrite'      => false,
			) );
			global $wc_product_attributes;
			if ( is_array( $wc_product_attributes ) ) {
				foreach ( self::attribute_taxonomies() as $attribute ) {
					if ( (string) $attribute->attribute_name === $slug ) {
						$wc_product_attributes[ $taxonomy ] = $attribute;
					}
				}
			}
		}
		return $taxonomy;
	}

	/**
	 * The shop's global attributes, as WooCommerce lists them.
	 *
	 * @return object[] attribute_name (the slug), attribute_label.
	 */
	private static function attribute_taxonomies() {
		return function_exists( 'wc_get_attribute_taxonomies' ) ? array_values( (array) wc_get_attribute_taxonomies() ) : array();
	}

	/**
	 * A slug for a new global attribute: the name's, within WooCommerce's 28
	 * characters, and free - not a reserved word, not another attribute's -
	 * with -2, -3 ... after it when it is not. A name with nothing a slug
	 * keeps, or none but letters outside a-z, gets "specific-" and a short
	 * hash of the name.
	 *
	 * @param string $name The eBay name.
	 * @return string '' when none is free.
	 */
	public static function slug_for( $name ) {
		$base = function_exists( 'wc_sanitize_taxonomy_name' ) ? (string) wc_sanitize_taxonomy_name( $name ) : sanitize_title( $name );
		if ( '' === $base || ! preg_match( '/^[a-z0-9_-]+$/', $base ) ) {
			$base = 'specific-' . substr( md5( (string) $name ), 0, 8 );
		}
		$base = self::cut( $base, self::SLUG_MAX );

		for ( $n = 1; $n < 100; $n++ ) {
			$slug = 1 === $n ? $base : self::cut( $base, self::SLUG_MAX - strlen( '-' . $n ) ) . '-' . $n;
			if ( self::slug_free( $slug ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * A slug cut to a length, without a hyphen or underscore at its end.
	 *
	 * @param string $slug   Slug.
	 * @param int    $length Bytes at most.
	 * @return string
	 */
	private static function cut( $slug, $length ) {
		return rtrim( substr( (string) $slug, 0, max( 1, (int) $length ) ), '-_' );
	}

	/**
	 * Whether WooCommerce would take a slug for a new global attribute.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	private static function slug_free( $slug ) {
		if ( '' === $slug || strlen( $slug ) > self::SLUG_MAX ) {
			return false;
		}
		if ( function_exists( 'wc_check_if_attribute_name_is_reserved' ) && wc_check_if_attribute_name_is_reserved( $slug ) ) {
			return false;
		}
		if ( taxonomy_exists( 'pa_' . $slug ) ) {
			return false;
		}
		foreach ( self::attribute_taxonomies() as $attribute ) {
			if ( (string) $attribute->attribute_name === $slug ) {
				return false;
			}
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Values
	// -------------------------------------------------------------------------

	/**
	 * What a term of this name would be stored as: WordPress cleans a term's
	 * name on the way in (pre_term_name: tags and runs of spaces go, "&"
	 * becomes "&amp;"), and keeps it so.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $value    The value.
	 * @return string
	 */
	public static function stored_name( $taxonomy, $value ) {
		return (string) wp_unslash( (string) sanitize_term_field( 'name', wp_slash( (string) $value ), 0, (string) $taxonomy, 'db' ) );
	}

	/**
	 * The term holding exactly this value, found or made; 0 when none can: a
	 * value WordPress would store otherwise, an empty one, or a write that
	 * did not keep it.
	 *
	 * Found by its exact name: WordPress finds a name blind to case and
	 * accents, and would hand "Pokemon" for "Pokémon". One that differs from
	 * a value already there only in its capitals gets a slug of its own,
	 * which WordPress otherwise refuses it.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $value    The value.
	 * @return int
	 */
	public static function term_for( $taxonomy, $value ) {
		$value = (string) $value;
		// A term's name is at most 200 characters (wp_terms.name).
		if ( '' === trim( $value ) || mb_strlen( $value, 'UTF-8' ) > 200 || ! taxonomy_exists( (string) $taxonomy ) || self::stored_name( $taxonomy, $value ) !== $value ) {
			return 0;
		}

		$found = get_terms( array(
			'taxonomy'   => $taxonomy,
			'name'       => $value,
			'hide_empty' => false,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		) );
		if ( is_array( $found ) ) {
			foreach ( $found as $term ) {
				if ( is_object( $term ) && (string) $term->name === $value ) {
					return (int) $term->term_id;
				}
			}
		}

		$made = wp_insert_term( wp_slash( $value ), $taxonomy );
		if ( is_wp_error( $made ) && 'term_exists' === $made->get_error_code() ) {
			$made = wp_insert_term( wp_slash( $value ), $taxonomy, array( 'slug' => self::free_term_slug( $taxonomy, $value ) ) );
		}
		if ( is_wp_error( $made ) || empty( $made['term_id'] ) ) {
			return 0;
		}

		$term = get_term( (int) $made['term_id'], $taxonomy );
		return ( is_object( $term ) && ! is_wp_error( $term ) && (string) $term->name === $value ) ? (int) $term->term_id : 0;
	}

	/**
	 * A term slug not yet taken in a taxonomy, from the value's.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $value    The value.
	 * @return string
	 */
	private static function free_term_slug( $taxonomy, $value ) {
		$base = sanitize_title( $value );
		$base = '' === $base ? 'value' : $base;
		for ( $n = 2; $n < 1000; $n++ ) {
			$taken = get_terms( array( 'taxonomy' => $taxonomy, 'slug' => $base . '-' . $n, 'hide_empty' => false, 'fields' => 'ids' ) );
			if ( empty( $taken ) || is_wp_error( $taken ) ) {
				return $base . '-' . $n;
			}
		}
		return $base . '-' . wp_generate_uuid4();
	}

	/**
	 * A custom attribute's values as WooCommerce reads them: split at "|",
	 * entities decoded, each trimmed, empty ones dropped.
	 *
	 * @param string $stored What _product_attributes holds.
	 * @return string[]
	 */
	private static function text_values( $stored ) {
		return array_values( array_map( 'strval', (array) wc_get_text_attributes( (string) $stored ) ) );
	}

	/**
	 * A product's terms' names in a taxonomy, as WordPress keeps them.
	 *
	 * @param int    $product_id Product.
	 * @param string $taxonomy   Taxonomy.
	 * @return string[]
	 */
	private static function term_names( $product_id, $taxonomy ) {
		$names = wp_get_object_terms( (int) $product_id, $taxonomy, array( 'fields' => 'names' ) );
		return is_array( $names ) ? array_values( array_map( 'strval', $names ) ) : array();
	}

	// -------------------------------------------------------------------------
	// What the exporter and the mapper ask
	// -------------------------------------------------------------------------

	/**
	 * What this plugin converted on a product, as recorded.
	 *
	 * @param int $product_id Product.
	 * @return array
	 */
	public static function record( $product_id ) {
		$record = get_post_meta( (int) $product_id, self::META, true );
		return is_array( $record ) ? $record : array();
	}

	/**
	 * A global attribute this plugin made of an item specific on this product,
	 * as the custom attribute it was: its name, and its values (the terms'
	 * names). Null for any other attribute - a shop's own global attribute, a
	 * custom one - which the exporter reads as it always has.
	 *
	 * @param WC_Product           $product   Product.
	 * @param WC_Product_Attribute $attribute One of its attributes.
	 * @return array|null name, options.
	 */
	public static function as_custom( $product, $attribute ) {
		if ( ! $product instanceof WC_Product || ! $attribute instanceof WC_Product_Attribute || ! $attribute->is_taxonomy() ) {
			return null;
		}
		$record   = $product->get_meta( self::META );
		$taxonomy = (string) $attribute->get_name();
		if ( ! is_array( $record ) || self::BRAND_TAXONOMY === $taxonomy || empty( $record[ $taxonomy ]['name'] ) ) {
			return null;
		}
		return array(
			'name'    => (string) $record[ $taxonomy ]['name'],
			'options' => array_values( array_map( 'strval', (array) wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) ) ) ),
		);
	}

	/**
	 * The import's item specifics, with each chosen one as its global
	 * attribute where its value can be kept exactly: TCGiant_Sync_Mapper::
	 * save_as_product(), when it writes the attributes. Not a variation's
	 * (left as they are in this version), and not a value that is two or more
	 * once stored ("A | B"), which the push would join in the terms' order.
	 *
	 * @param WC_Product_Attribute[] $attributes Key => the custom attributes built from eBay's item specifics.
	 * @param WC_Product             $product    The product.
	 * @return array attributes (key => attribute, a global one keyed by its
	 *               taxonomy), ours (taxonomy => true: this plugin's own on
	 *               the product, which the merge may replace), made
	 *               (taxonomy => name, attribute), record.
	 */
	public static function for_import( array $attributes, $product ) {
		$want   = self::chosen();
		$record = $product instanceof WC_Product && $product->get_id() > 0 ? self::record( $product->get_id() ) : array();
		$ours   = array();
		foreach ( $record as $taxonomy => $entry ) {
			if ( self::BRAND_TAXONOMY !== $taxonomy ) {
				$ours[ (string) $taxonomy ] = true;
			}
		}

		// The shop's own global attributes on the product: the merge keeps
		// them and drops eBay's of that label, so no term is made for it
		// (fix round 1, LOW 2).
		$shops = array();
		if ( $product instanceof WC_Product && $product->get_id() > 0 ) {
			foreach ( (array) $product->get_attributes() as $held ) {
				if ( $held instanceof WC_Product_Attribute && $held->is_taxonomy() && ! isset( $ours[ (string) $held->get_name() ] ) ) {
					$shops[ (string) $held->get_name() ] = true;
				}
			}
		}

		$out  = array();
		$made = array();
		foreach ( $attributes as $key => $attribute ) {
			$name = $attribute instanceof WC_Product_Attribute ? (string) $attribute->get_name() : '';
			if ( '' !== $name && isset( $want[ $name ] ) && ! isset( $shops[ $want[ $name ] ] ) && ! $attribute->is_taxonomy() && ! $attribute->get_variation() ) {
				$taxonomy = $want[ $name ];
				$values   = self::text_values( wc_implode_text_attributes( array_map( 'strval', (array) $attribute->get_options() ) ) );
				$id       = function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? (int) wc_attribute_taxonomy_id_by_name( $taxonomy ) : 0;
				$term     = ( 1 === count( $values ) && $id > 0 ) ? self::term_for( $taxonomy, $values[0] ) : 0;
				if ( $term > 0 ) {
					$global = new WC_Product_Attribute();
					$global->set_id( $id );
					$global->set_name( $taxonomy );
					$global->set_options( array( $term ) );
					$global->set_position( $attribute->get_position() );
					$global->set_visible( $attribute->get_visible() );
					$global->set_variation( false );
					$out[ $taxonomy ]  = $global;
					$made[ $taxonomy ] = array( 'name' => $name, 'attribute' => $global );
					continue;
				}
			}
			$out[ $key ] = $attribute;
		}

		return array( 'attributes' => $out, 'ours' => $ours, 'made' => $made, 'record' => $record );
	}

	/**
	 * After the import's merge, what the product records: each global
	 * attribute it made that the merge kept, and no longer one a custom
	 * attribute replaced or the product no longer has.
	 *
	 * @param WC_Product             $product    The product.
	 * @param WC_Product_Attribute[] $merged     What the import hands set_attributes().
	 * @param array                  $filterable for_import()'s answer.
	 */
	public static function after_import( $product, array $merged, array $filterable ) {
		$record = (array) ( $filterable['record'] ?? array() );
		$now    = array();
		foreach ( $merged as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->is_taxonomy() ) {
				$now[ (string) $attribute->get_name() ] = $attribute;
			}
		}
		foreach ( array_keys( $record ) as $taxonomy ) {
			if ( self::BRAND_TAXONOMY !== $taxonomy && ! isset( $now[ $taxonomy ] ) ) {
				unset( $record[ $taxonomy ] );
			}
		}
		foreach ( (array) ( $filterable['made'] ?? array() ) as $taxonomy => $made ) {
			if ( isset( $now[ $taxonomy ] ) && $now[ $taxonomy ] === $made['attribute'] ) {
				$record[ $taxonomy ] = array( 'name' => (string) $made['name'], 'run' => 'import' );
			}
		}

		if ( $record ) {
			$product->update_meta_data( self::META, $record );
		} elseif ( ! empty( $filterable['record'] ) ) {
			$product->delete_meta_data( self::META );
		}
	}

	/**
	 * After the import's save: eBay's Brand into WooCommerce Brands, for a
	 * product that has no brand yet, when the shop asked for it.
	 *
	 * @param int   $product_id Product.
	 * @param array $specifics  The listing's item specifics, eBay name => value.
	 */
	public static function brand_after_import( $product_id, array $specifics ) {
		$choice = self::choice();
		if ( ! self::brands_apply( self::chosen(), $choice['brands'] ) || ! isset( $specifics[ self::BRAND ] ) ) {
			return;
		}
		$values = self::text_values( wc_implode_text_attributes( array( (string) $specifics[ self::BRAND ] ) ) );
		if ( 1 !== count( $values ) || ! self::brandless( (int) $product_id ) ) {
			return;
		}
		$term = self::term_for( self::BRAND_TAXONOMY, $values[0] );
		if ( $term <= 0 ) {
			return;
		}
		// Recorded first, as a run records it (settle()).
		$record                         = self::record( $product_id );
		$record[ self::BRAND_TAXONOMY ] = array( 'term' => $term, 'run' => 'import' );
		update_post_meta( (int) $product_id, self::META, $record );
		wp_set_object_terms( (int) $product_id, array( $term ), self::BRAND_TAXONOMY, false );
	}

	// -------------------------------------------------------------------------
	// The run
	// -------------------------------------------------------------------------

	/**
	 * Start a run toward a choice: a new run ID, so a batch of an earlier run
	 * still going writes nothing after it.
	 *
	 * @param array $was    The names chosen before, eBay name => taxonomy.
	 * @param array $want   The names chosen now.
	 * @param bool  $brands The Brands box.
	 * @return bool Whether it was queued.
	 */
	private static function start( array $was, array $want, $brands ) {
		$run   = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
		$state = array(
			'run'            => $run,
			'status'         => 'running',
			'want'           => $want,
			'brands'         => self::brands_apply( $want, $brands ),
			'added'          => array_values( array_map( 'strval', array_keys( array_diff_key( $want, $was ) ) ) ),
			'removed'        => array_values( array_map( 'strval', array_keys( array_diff_key( $was, $want ) ) ) ),
			'cursor'         => 0,
			'looked'         => 0,
			'total'          => self::product_total(),
			'converted'      => 0,
			'undone'         => 0,
			'brands_added'   => 0,
			'brands_removed' => 0,
			'skipped'        => array(),
			'samples'        => array(),
			'started'        => time(),
		);
		self::save_state( $state );

		TCGiant_Sync_Logger::log( sprintf(
			'Filterable item specifics: run %1$s started. Filterable: %2$s.%3$s Brands: %4$s. Nothing is sent to eBay.',
			$run,
			$want ? implode( ', ', array_keys( $want ) ) : 'none',
			$state['removed'] ? ' Turned back: ' . implode( ', ', $state['removed'] ) . '.' : '',
			$state['brands'] ? 'yes' : 'no'
		), 'info' );

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CONVERT_HOOK, null, self::GROUP );
		}
		as_schedule_single_action( time(), self::CONVERT_HOOK, array(), self::GROUP );
		return true;
	}

	/**
	 * One batch of the run (Action Scheduler).
	 */
	public static function run_batch() {
		if ( ! self::take_lock( 'run', self::LOCK_SECONDS ) ) {
			// Another batch is going: never two at once.
			if ( self::active( self::state() ) && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + self::LATE_DELAY, self::CONVERT_HOOK, array(), self::GROUP );
			}
			return;
		}

		try {
			self::batch();
		} finally {
			self::release_lock( 'run' );
		}
	}

	/**
	 * Settle up to BATCH products, in ID order. Where it has got to is kept
	 * after each product, so a batch the host ends part way loses nothing,
	 * and each product settles the same however often it is looked at. It
	 * stops early when this request has used the time the host gives it
	 * (TCGiant_Sync_Runtime::over_time_budget()), and writes only while the
	 * run it began with is the one in the table.
	 *
	 * Each product is read just before it is written, never from a copy read
	 * when the batch began (fix round 1, M1): WordPress answers get_post_meta()
	 * from what this request has cached, so a shop's edit, or a sync's, made
	 * since would be written over. Not primed, then, and let go of first.
	 */
	private static function batch() {
		$state = self::state();
		if ( ! self::active( $state ) ) {
			return;
		}
		$run  = (string) $state['run'];
		$ids  = self::products_after( (int) $state['cursor'], self::BATCH );
		$late = false;

		foreach ( $ids as $n => $id ) {
			if ( $n > 0 && '' !== (string) TCGiant_Sync_Runtime::over_time_budget() ) {
				$late = true;
				break;
			}
			if ( ! self::still( $run ) ) {
				return;
			}
			self::forget( $id );
			self::settle( $id, $state );
			$state['cursor'] = $id;
			$state['looked']++;
			self::forget( $id );
			if ( ! self::save_progress( $state, $run ) ) {
				return;
			}
		}

		if ( ! $late && count( $ids ) < self::BATCH ) {
			self::finish( $state );
			return;
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + ( $late ? self::LATE_DELAY : 0 ), self::CONVERT_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Bring one product to the run's choice.
	 *
	 * - Each custom attribute of a chosen name, not used for variations,
	 *   with one value the term keeps exactly, on a product without the
	 *   global attribute already: the global attribute, in its place, seen or
	 *   hidden as it was.
	 * - Each global attribute this plugin converted, of a name no longer
	 *   chosen: the custom attribute again, with the terms' names.
	 * - Brand into WooCommerce Brands where the product has no brand, or the
	 *   brand this plugin added taken off when the shop no longer wants it.
	 *
	 * In this order, so that a product the host stops part way is never left
	 * looking like the shop's own, and settles the same - counted once - when
	 * it is looked at again: the record of what is being added, the new
	 * terms, the attribute list, the terms taken off, the record of what was
	 * taken off. No product save: its date stays.
	 *
	 * @param int   $id    Product.
	 * @param array $state The run's state, its figures written to.
	 */
	private static function settle( $id, array &$state ) {
		$rows = get_post_meta( $id, '_product_attributes', true );
		$rows = is_array( $rows ) ? $rows : array();
		if ( ! $rows && ! get_post_meta( $id, self::META, true ) ) {
			return;
		}

		$want   = (array) $state['want'];
		$run    = (string) $state['run'];
		$before = self::record( $id );
		$add    = array();
		$drop   = array();
		$set    = array();
		$clear  = array();
		$global = array();
		$custom = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['is_taxonomy'] ) ) {
				$global[ (string) ( $row['name'] ?? '' ) ] = true;
			} elseif ( is_array( $row ) ) {
				$custom[ sanitize_title( (string) ( $row['name'] ?? '' ) ) ] = true;
			}
		}

		$out       = array();
		$listed    = array();
		$converted = false;
		$undone    = false;
		$rewrite   = false;
		$brand     = null;

		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) ) {
				$out[ $key ] = $row;
				continue;
			}
			$name = (string) ( $row['name'] ?? '' );

			if ( empty( $row['is_taxonomy'] ) && isset( $want[ $name ] ) ) {
				$taxonomy = (string) $want[ $name ];
				$values   = self::text_values( (string) ( $row['value'] ?? '' ) );
				$why      = self::why_not( $row, $taxonomy, $global, $values );
				$term     = '' === $why ? self::term_for( $taxonomy, $values[0] ) : 0;
				if ( '' === $why && $term <= 0 ) {
					$why = 'value';
				}
				if ( self::BRAND === $name && 1 === count( $values ) && 'variation' !== $why ) {
					$brand = $values[0];
				}
				if ( '' !== $why ) {
					self::skipped( $state, $why, $id );
					$out[ $key ] = $row;
					continue;
				}
				$out[ sanitize_title( $taxonomy ) ] = array(
					'name'         => $taxonomy,
					'value'        => '',
					'position'     => $row['position'] ?? 0,
					'is_visible'   => $row['is_visible'] ?? 0,
					'is_variation' => 0,
					'is_taxonomy'  => 1,
				);
				$listed[ $taxonomy ] = true;
				$set[ $taxonomy ]    = array( $term );
				$add[ $taxonomy ]    = array( 'name' => $name, 'run' => $run );
				$global[ $taxonomy ] = true;
				$converted           = true;
				$rewrite             = true;
				continue;
			}

			if ( ! empty( $row['is_taxonomy'] ) ) {
				$listed[ $name ] = true;
			}

			if ( ! empty( $row['is_taxonomy'] ) && self::BRAND_TAXONOMY !== $name && isset( $before[ $name ]['name'] ) ) {
				$was = (string) $before[ $name ]['name'];
				if ( ( $want[ $was ] ?? '' ) === $name ) {
					// Still chosen: as it is. Converted by this run already, on
					// a pass the host stopped before its place was kept.
					$converted = $converted || $run === (string) ( $before[ $name ]['run'] ?? '' );
					if ( self::BRAND === $was ) {
						$names = self::term_names( $id, $name );
						$brand = 1 === count( $names ) ? $names[0] : null;
					}
					$out[ $key ] = $row;
					continue;
				}
				// The plugin's own conversion of a name no longer chosen.
				$why = ! empty( $row['is_variation'] ) ? 'variation' : ( isset( $custom[ sanitize_title( $was ) ] ) ? 'both' : '' );
				if ( '' !== $why ) {
					self::skipped( $state, $why, $id );
					$out[ $key ] = $row;
					continue;
				}
				unset( $listed[ $name ] );
				$out[ sanitize_title( $was ) ] = array(
					'name'         => $was,
					'value'        => wc_implode_text_attributes( self::term_names( $id, $name ) ),
					'position'     => $row['position'] ?? 0,
					'is_visible'   => $row['is_visible'] ?? 0,
					'is_variation' => 0,
					'is_taxonomy'  => 0,
				);
				$clear[ $name ] = true;
				$drop[ $name ]  = true;
				$undone         = true;
				$rewrite        = true;
				continue;
			}

			$out[ $key ] = $row;
		}

		// A record of an attribute the product no longer has goes, with any
		// terms left there: a run stopped part way through turning it back
		// (counted now), or the shop took it off.
		foreach ( $before as $taxonomy => $entry ) {
			$taxonomy = (string) $taxonomy;
			if ( self::BRAND_TAXONOMY === $taxonomy || isset( $listed[ $taxonomy ] ) || isset( $drop[ $taxonomy ] ) ) {
				continue;
			}
			if ( taxonomy_exists( $taxonomy ) && self::term_names( $id, $taxonomy ) ) {
				$clear[ $taxonomy ] = true;
			}
			$drop[ $taxonomy ] = true;
			$undone            = $undone || ( $want[ (string) ( $entry['name'] ?? '' ) ] ?? '' ) !== $taxonomy;
		}

		// Brands. One the plugin added is recorded before it is written, so a
		// product stopped between the two gets it at its next look.
		$brand_added   = false;
		$brand_removed = false;
		$brand_term    = 0;
		if ( ! empty( $state['brands'] ) && null !== $brand && self::brandless( $id ) ) {
			$brand_term = self::term_for( self::BRAND_TAXONOMY, $brand );
			if ( $brand_term > 0 ) {
				$add[ self::BRAND_TAXONOMY ] = array( 'term' => $brand_term, 'run' => $run );
			}
		} elseif ( empty( $state['brands'] ) && ! empty( $before[ self::BRAND_TAXONOMY ] ) ) {
			$has = taxonomy_exists( self::BRAND_TAXONOMY ) ? wp_get_object_terms( $id, self::BRAND_TAXONOMY, array( 'fields' => 'ids' ) ) : array();
			if ( is_array( $has ) && array( (int) $before[ self::BRAND_TAXONOMY ]['term'] ) === array_map( 'intval', $has ) ) {
				$clear[ self::BRAND_TAXONOMY ] = true;
				$brand_removed                 = true;
			}
			$drop[ self::BRAND_TAXONOMY ] = true;
		}

		// The writes, in order.
		$middle = array_merge( $before, $add );
		if ( $middle !== $before ) {
			update_post_meta( $id, self::META, $middle );
		}
		foreach ( $set as $taxonomy => $ids ) {
			wp_set_object_terms( $id, $ids, (string) $taxonomy, false );
		}
		if ( $brand_term > 0 ) {
			wp_set_object_terms( $id, array( $brand_term ), self::BRAND_TAXONOMY, false );
			$brand_added = true;
		}
		if ( $rewrite ) {
			// As WooCommerce writes it, slashed for update_post_meta() to unslash.
			update_post_meta( $id, '_product_attributes', wp_slash( $out ) );
		}
		foreach ( array_keys( $clear ) as $taxonomy ) {
			wp_set_object_terms( $id, array(), (string) $taxonomy, false );
		}
		$after = array_diff_key( $middle, $drop );
		if ( $after !== $middle ) {
			if ( $after ) {
				update_post_meta( $id, self::META, $after );
			} else {
				delete_post_meta( $id, self::META );
			}
		}
		if ( $rewrite || $set || $clear || $brand_added ) {
			self::refreshed( $id, array_keys( $set + $clear ) );
		}

		$state['converted']      += $converted ? 1 : 0;
		$state['undone']         += $undone ? 1 : 0;
		$state['brands_added']   += $brand_added ? 1 : 0;
		$state['brands_removed'] += $brand_removed ? 1 : 0;
	}

	/**
	 * Whether a product has no brand in WooCommerce Brands.
	 *
	 * @param int $id Product.
	 * @return bool
	 */
	private static function brandless( $id ) {
		if ( ! taxonomy_exists( self::BRAND_TAXONOMY ) ) {
			return false;
		}
		$has = wp_get_object_terms( (int) $id, self::BRAND_TAXONOMY, array( 'fields' => 'ids' ) );
		return is_array( $has ) && ! $has;
	}

	/**
	 * Why a custom attribute of a chosen name is not converted on a product,
	 * or ''.
	 *
	 * @param array  $row      Its row in _product_attributes.
	 * @param string $taxonomy The global attribute.
	 * @param array  $global   The product's global attributes, taxonomy => true.
	 * @param array  $values   Its values, as WooCommerce reads them.
	 * @return string variation, has_global, missing, empty, several, value or ''.
	 */
	private static function why_not( array $row, $taxonomy, array $global, array $values ) {
		if ( ! empty( $row['is_variation'] ) ) {
			return 'variation';
		}
		if ( isset( $global[ $taxonomy ] ) ) {
			return 'has_global';
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 'missing';
		}
		if ( ! $values ) {
			return 'empty';
		}
		if ( count( $values ) > 1 ) {
			return 'several';
		}
		return self::stored_name( $taxonomy, $values[0] ) === $values[0] ? '' : 'value';
	}

	/**
	 * A product skipped, counted under its reason, with its ID among the
	 * first few.
	 *
	 * @param array  $state The run's state.
	 * @param string $why   The reason.
	 * @param int    $id    Product.
	 */
	private static function skipped( array &$state, $why, $id ) {
		$state['skipped'][ $why ] = (int) ( $state['skipped'][ $why ] ?? 0 ) + 1;
		if ( count( (array) ( $state['samples'][ $why ] ?? array() ) ) < self::SAMPLES ) {
			$state['samples'][ $why ][] = (int) $id;
		}
	}

	/**
	 * WooCommerce's caches of a product, cleared as its own save clears them,
	 * and the attributes lookup table its filters may read, brought up to
	 * date - without the save, which would move the product's date.
	 *
	 * @param int      $id         Product.
	 * @param string[] $taxonomies The taxonomies written.
	 */
	private static function refreshed( $id, array $taxonomies ) {
		// The post's caches, as a save clears them (and whatever hooks
		// clean_post_cache: a product object cache, a page cache). It moves
		// no date (fix round 1).
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $id );
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $id );
		}
		// Both from WooCommerce 3.9 and 3.6; this plugin needs 5.0.
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
			if ( $taxonomies ) {
				WC_Cache_Helper::invalidate_attribute_count( array_values( array_map( 'strval', $taxonomies ) ) );
			}
		}

		$store = 'Automattic\\WooCommerce\\Internal\\ProductAttributesLookup\\LookupDataStore';
		if ( function_exists( 'wc_get_container' ) && class_exists( $store ) ) {
			try {
				$lookup  = wc_get_container()->get( $store );
				$product = wc_get_product( $id );
				if ( $product && is_object( $lookup ) && method_exists( $lookup, 'on_product_changed' ) ) {
					$lookup->on_product_changed( $product );
				}
			} catch ( \Throwable $e ) {
				TCGiant_Sync_Logger::log( sprintf( 'Filterable item specifics: WooCommerce\'s attribute lookup table was not updated for WC #%1$d (%2$s). Its filters catch up when the product is next saved, or when the table is regenerated (WooCommerce > Status > Tools).', $id, $e->getMessage() ), 'warning' );
			}
		}
	}

	/**
	 * End the run, with its one line in the log, and count the names again.
	 *
	 * @param array $state The run's state.
	 */
	private static function finish( array $state ) {
		$state['status'] = 'done';
		$state['ended']  = time();
		self::save_state( $state );

		TCGiant_Sync_Logger::log( self::summary( $state ), 'success' );
		self::queue_count();
	}

	/**
	 * A run's line, for the log.
	 *
	 * @param array $state Its state.
	 * @return string
	 */
	public static function summary( array $state ) {
		$why = array();
		foreach ( (array) ( $state['skipped'] ?? array() ) as $reason => $n ) {
			$why[] = sprintf( '%1$d %2$s (WC #%3$s)', (int) $n, self::reason_words( (string) $reason ), implode( ', #', array_map( 'intval', (array) ( $state['samples'][ $reason ] ?? array() ) ) ) );
		}
		$want = array_keys( (array) ( $state['want'] ?? array() ) );

		return sprintf(
			'Filterable item specifics: run %1$s finished. Filterable: %2$s.%3$s %4$d products looked at: %5$d converted, %6$d turned back, %7$d given a brand, %8$d brands taken off; %9$s. Nothing was sent to eBay.',
			(string) ( $state['run'] ?? '' ),
			$want ? implode( ', ', $want ) : 'none',
			! empty( $state['removed'] ) ? ' Turned back: ' . implode( ', ', (array) $state['removed'] ) . '.' : '',
			(int) ( $state['looked'] ?? 0 ),
			(int) ( $state['converted'] ?? 0 ),
			(int) ( $state['undone'] ?? 0 ),
			(int) ( $state['brands_added'] ?? 0 ),
			(int) ( $state['brands_removed'] ?? 0 ),
			$why ? 'skipped: ' . implode( '; ', $why ) : 'none skipped'
		);
	}

	/**
	 * A reason a product was skipped, in plain words.
	 *
	 * @param string $reason The reason's key.
	 * @return string
	 */
	public static function reason_words( $reason ) {
		$words = array(
			'variation'  => __( 'used for variations, which are left as they are', 'tcgiant-sync' ),
			'has_global' => __( 'the global attribute is on the product already', 'tcgiant-sync' ),
			'missing'    => __( 'its global attribute has been deleted', 'tcgiant-sync' ),
			'empty'      => __( 'no value', 'tcgiant-sync' ),
			'several'    => __( 'more than one value, separated by "|"', 'tcgiant-sync' ),
			'value'      => __( 'a value WordPress would store differently as a filter value (an "&", "<" or ">", or extra spaces)', 'tcgiant-sync' ),
			'both'       => __( 'a custom attribute of that name is on the product too', 'tcgiant-sync' ),
		);
		return $words[ $reason ] ?? (string) $reason;
	}

	/**
	 * Whether a run is still the one in the table, and going.
	 *
	 * @param string $run Its ID.
	 * @return bool
	 */
	private static function still( $run ) {
		$now = self::state();
		return self::active( $now ) && (string) ( $now['run'] ?? '' ) === (string) $run;
	}

	/**
	 * Keep a batch's place and figures - if its run is still the one in the
	 * table, and going.
	 *
	 * @param array  $state The batch's state.
	 * @param string $run   The run it began with.
	 * @return bool
	 */
	private static function save_progress( array $state, $run ) {
		if ( ! self::still( $run ) ) {
			return false;
		}
		self::save_state( $state );
		return true;
	}

	/**
	 * The run's state, as the table has it now.
	 *
	 * @return array
	 */
	public static function state() {
		return self::read( self::STATE_OPTION );
	}

	/**
	 * Whether a state is of a run still going.
	 *
	 * @param array $state State.
	 * @return bool
	 */
	private static function active( array $state ) {
		return 'running' === (string) ( $state['status'] ?? '' ) && '' !== (string) ( $state['run'] ?? '' );
	}

	/**
	 * Write the run's state, with when it last moved.
	 *
	 * @param array $state State.
	 */
	private static function save_state( array $state ) {
		$state['touched'] = time();
		self::write( self::STATE_OPTION, $state );
	}

	// -------------------------------------------------------------------------
	// The count
	// -------------------------------------------------------------------------

	/**
	 * Ask for the names to be counted: now, or after the count already going.
	 *
	 * @return bool Whether a count is queued or going.
	 */
	public static function queue_count() {
		$count = self::read( self::CHOICES_OPTION );
		if ( isset( $count['doing'] ) ) {
			$count['again'] = true;
			self::write( self::CHOICES_OPTION, $count );
			return true;
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		$count['doing'] = array( 'cursor' => 0, 'looked' => 0, 'names' => array(), 'started' => time(), 'touched' => time() );
		self::write( self::CHOICES_OPTION, $count );
		if ( function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( self::COUNT_HOOK, null, self::GROUP ) ) {
			return true;
		}
		as_schedule_single_action( time(), self::COUNT_HOOK, array(), self::GROUP );
		return true;
	}

	/**
	 * One batch of the count (Action Scheduler).
	 */
	public static function run_count() {
		if ( ! self::take_lock( 'count', self::LOCK_SECONDS ) ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + self::LATE_DELAY, self::COUNT_HOOK, array(), self::GROUP );
			}
			return;
		}

		try {
			self::count_batch();
		} finally {
			self::release_lock( 'count' );
		}
	}

	/**
	 * Count up to COUNT_BATCH products' item specifics, in ID order: for each
	 * name, the products that have it - as their own attribute, or as a
	 * global one this plugin converted - and its different values, up to
	 * REFUSE_VALUES (past that only that it has more). What a batch found is
	 * kept with the count, never autoloaded - each name's values as a 4-byte
	 * checksum each, packed, so at most about 11 KB a name - and the finished
	 * list is only figures; so a large shop's count takes more batches, not
	 * more memory. Nothing is written to any product.
	 */
	private static function count_batch() {
		$count = self::read( self::CHOICES_OPTION );
		$doing = is_array( $count['doing'] ?? null ) ? $count['doing'] : array( 'cursor' => 0, 'looked' => 0, 'names' => array(), 'started' => time() );
		$ids   = self::products_after( (int) $doing['cursor'], self::COUNT_BATCH );
		$late  = false;

		foreach ( $doing['names'] as $name => $row ) {
			$doing['names'][ $name ]['seen'] = self::unpack_seen( $row['seen'] ?? '' );
		}

		self::prime( $ids );
		foreach ( $ids as $n => $id ) {
			if ( $n > 0 && '' !== (string) TCGiant_Sync_Runtime::over_time_budget() ) {
				$late = true;
				break;
			}
			self::tally( $id, $doing['names'] );
			$doing['cursor'] = $id;
			$doing['looked']++;
			self::forget( $id );
		}
		// Those it primed and did not reach, let go of too: another job in this
		// request must not read them as they were when this batch began.
		foreach ( $ids as $id ) {
			self::forget( $id );
		}

		if ( ! $late && count( $ids ) < self::COUNT_BATCH ) {
			$names = array();
			foreach ( (array) $doing['names'] as $name => $row ) {
				$names[ (string) $name ] = array(
					'products'  => (int) $row['products'],
					'converted' => (int) $row['converted'],
					'values'    => ! empty( $row['over'] ) ? self::REFUSE_VALUES + 1 : count( (array) $row['seen'] ),
					'over'      => ! empty( $row['over'] ),
				);
			}
			uksort( $names, function ( $a, $b ) use ( $names ) {
				return $names[ $b ]['products'] <=> $names[ $a ]['products'] ?: strcasecmp( (string) $a, (string) $b );
			} );
			$again          = ! empty( $count['again'] );
			$count          = array( 'done' => array( 'at' => time(), 'products' => (int) $doing['looked'], 'names' => $names ) );
			self::write( self::CHOICES_OPTION, $count );
			if ( $again ) {
				self::queue_count();
			}
			return;
		}

		foreach ( $doing['names'] as $name => $row ) {
			$doing['names'][ $name ]['seen'] = self::pack_seen( (array) $row['seen'] );
		}
		$doing['touched'] = time();
		$count['doing']   = $doing;
		self::write( self::CHOICES_OPTION, $count );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + ( $late ? self::LATE_DELAY : 0 ), self::COUNT_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * One product's item specifics, added to the count.
	 *
	 * @param int   $id    Product.
	 * @param array $names The count so far, written to.
	 */
	private static function tally( $id, array &$names ) {
		$rows = get_post_meta( $id, '_product_attributes', true );
		if ( ! is_array( $rows ) || ! $rows ) {
			return;
		}
		$record = null;
		$here   = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['is_variation'] ) ) {
				continue;
			}
			$name = (string) ( $row['name'] ?? '' );
			if ( ! empty( $row['is_taxonomy'] ) ) {
				$record = null === $record ? self::record( $id ) : $record;
				if ( empty( $record[ $name ]['name'] ) || self::BRAND_TAXONOMY === $name ) {
					// The shop's own global attribute: not an item specific to offer.
					continue;
				}
				$values    = self::term_names( $id, $name );
				$name      = (string) $record[ $name ]['name'];
				$converted = 1;
			} else {
				$values    = self::text_values( (string) ( $row['value'] ?? '' ) );
				$converted = 0;
			}
			if ( '' === trim( $name ) || isset( $here[ $name ] ) ) {
				continue;
			}
			$here[ $name ] = true;

			if ( ! isset( $names[ $name ] ) ) {
				$names[ $name ] = array( 'products' => 0, 'converted' => 0, 'seen' => array(), 'over' => false );
			}
			$names[ $name ]['products']++;
			$names[ $name ]['converted'] += $converted;
			if ( $names[ $name ]['over'] ) {
				continue;
			}
			foreach ( $values as $value ) {
				// By its checksum: two values of one name alike in theirs are
				// counted as one, once in about two thousand such counts.
				$names[ $name ]['seen'][ crc32( $value ) ] = 1;
			}
			if ( count( $names[ $name ]['seen'] ) > self::REFUSE_VALUES ) {
				$names[ $name ]['over'] = true;
				$names[ $name ]['seen'] = array();
			}
		}
	}

	/**
	 * A name's values so far, as the count keeps them between batches: their
	 * checksums, four bytes each, packed and in base64 (an option is text).
	 *
	 * @param array $seen Checksum => 1.
	 * @return string
	 */
	private static function pack_seen( array $seen ) {
		return $seen ? base64_encode( pack( 'N*', ...array_keys( $seen ) ) ) : '';
	}

	/**
	 * The same, read back.
	 *
	 * @param string $packed pack_seen()'s.
	 * @return array Checksum => 1.
	 */
	private static function unpack_seen( $packed ) {
		$raw = '' === (string) $packed ? '' : base64_decode( (string) $packed, true );
		if ( ! is_string( $raw ) || '' === $raw || 0 !== strlen( $raw ) % 4 ) {
			return array();
		}
		return array_fill_keys( array_values( (array) unpack( 'N*', $raw ) ), 1 );
	}

	/**
	 * The finished count's names, or none.
	 *
	 * @return array eBay name => products, converted, values, over.
	 */
	public static function counted() {
		$count = self::read( self::CHOICES_OPTION );
		return is_array( $count['done']['names'] ?? null ) ? $count['done']['names'] : array();
	}

	// -------------------------------------------------------------------------
	// Products
	// -------------------------------------------------------------------------

	/**
	 * Products after an ID, in ID order: every status but the trash and
	 * auto-drafts, as Re-send to eBay's count reads them.
	 *
	 * @param int $after Product ID.
	 * @param int $limit How many.
	 * @return int[]
	 */
	private static function products_after( $after, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ( 'publish', 'private', 'draft', 'pending', 'future' ) AND ID > %d ORDER BY ID ASC LIMIT %d",
			(int) $after,
			(int) $limit
		) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * How many products a run looks at, for its progress line.
	 *
	 * @return int
	 */
	private static function product_total() {
		if ( ! function_exists( 'wp_count_posts' ) ) {
			return 0;
		}
		$counts = (array) wp_count_posts( 'product' );
		$total  = 0;
		foreach ( array( 'publish', 'private', 'draft', 'pending', 'future' ) as $status ) {
			$total += (int) ( $counts[ $status ] ?? 0 );
		}
		return $total;
	}

	/**
	 * A batch's products' meta read in one query: for the count, which only
	 * reads. The run reads each product as it comes to it (batch()).
	 *
	 * @param int[] $ids Products.
	 */
	private static function prime( array $ids ) {
		if ( $ids && function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $ids );
		}
	}

	/**
	 * A product's meta let go of once it is done with, so a request that runs
	 * several batches does not hold every product it has read.
	 *
	 * @param int $id Product.
	 */
	private static function forget( $id ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( (int) $id, 'post_meta' );
		}
	}

	// -------------------------------------------------------------------------
	// Carrying on
	// -------------------------------------------------------------------------

	/**
	 * Carry on a run, or a count, whose batch died part way: nothing waits to
	 * carry it on, and it has not moved for longer than a hold lasts. And
	 * start the first count. Asked whenever Settings is drawn.
	 */
	public static function maybe_resume() {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$state = self::state();
		if ( self::active( $state ) && false === as_next_scheduled_action( self::CONVERT_HOOK, null, self::GROUP ) && time() - (int) ( $state['touched'] ?? 0 ) > self::LOCK_SECONDS ) {
			TCGiant_Sync_Logger::log( sprintf( 'Filterable item specifics: run %s had stopped part way with nothing to carry it on, so it carries on now.', (string) $state['run'] ), 'warning' );
			as_schedule_single_action( time(), self::CONVERT_HOOK, array(), self::GROUP );
		}

		$count = self::read( self::CHOICES_OPTION );
		if ( false !== as_next_scheduled_action( self::COUNT_HOOK, null, self::GROUP ) ) {
			return;
		}
		if ( isset( $count['doing'] ) ) {
			if ( time() - (int) ( $count['doing']['touched'] ?? 0 ) > self::LOCK_SECONDS ) {
				as_schedule_single_action( time(), self::COUNT_HOOK, array(), self::GROUP );
			}
		} elseif ( ! isset( $count['done'] ) ) {
			self::queue_count();
		}
	}

	// -------------------------------------------------------------------------
	// The screen
	// -------------------------------------------------------------------------

	/**
	 * What the section on Settings shows.
	 *
	 * @return array rows (each name, products, converted, values, over,
	 *               chosen, suggested, refusal, warning), counted (when, or
	 *               0), counting, products, state, brands, brands_here.
	 */
	public static function screen() {
		self::maybe_resume();

		$choice = self::choice();
		$count  = self::read( self::CHOICES_OPTION );
		$names  = self::counted();
		$rows   = array();

		// Chosen names first, then the suggested, then by how many products have them.
		foreach ( $choice['names'] as $name => $taxonomy ) {
			if ( ! isset( $names[ $name ] ) ) {
				$names = array( $name => array( 'products' => 0, 'converted' => 0, 'values' => 0, 'over' => false ) ) + $names;
			}
		}
		foreach ( $names as $name => $row ) {
			$name     = (string) $name;
			$chosen   = isset( $choice['names'][ $name ] );
			$refusal  = $chosen ? '' : self::refusal( $name, $row );
			$rows[]   = array(
				'name'      => $name,
				'products'  => (int) $row['products'],
				'converted' => (int) $row['converted'],
				'values'    => (int) $row['values'],
				'over'      => ! empty( $row['over'] ),
				'chosen'    => $chosen,
				'gone'      => $chosen && ! taxonomy_exists( (string) $choice['names'][ $name ] ),
				'suggested' => in_array( $name, self::SUGGESTED, true ),
				'refusal'   => $refusal,
				'warning'   => '' === $refusal ? self::warning( $row ) : '',
			);
		}
		usort( $rows, function ( $a, $b ) {
			$rank = function ( $row ) {
				return $row['chosen'] ? 0 : ( $row['suggested'] && '' === $row['refusal'] ? 1 : ( '' === $row['refusal'] ? 2 : 3 ) );
			};
			return $rank( $a ) <=> $rank( $b ) ?: ( $b['products'] <=> $a['products'] ?: strcasecmp( $a['name'], $b['name'] ) );
		} );

		return array(
			'rows'        => $rows,
			'counted'     => (int) ( $count['done']['at'] ?? 0 ),
			'products'    => (int) ( $count['done']['products'] ?? 0 ),
			'counting'    => isset( $count['doing'] ) || ( function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( self::COUNT_HOOK, null, self::GROUP ) ),
			'state'       => self::state(),
			'brands'      => $choice['brands'],
			'brands_here' => taxonomy_exists( self::BRAND_TAXONOMY ),
		);
	}

	/**
	 * The section's form: Save, and Count again. manage_options and the
	 * section's nonce.
	 */
	public static function handle_form() {
		check_admin_referer( self::FORM );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		$do = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		if ( 'count' === $do ) {
			self::queue_count();
			self::notice( 'success', __( 'Counting the item specifics on your products again. Reload this page in a minute for the figures.', 'tcgiant-sync' ) );
		} elseif ( 'save' === $do ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each name is taken only if it is exactly one counted or already chosen.
			$names = isset( $_POST['names'] ) && is_array( $_POST['names'] ) ? array_values( array_filter( (array) wp_unslash( $_POST['names'] ), 'is_string' ) ) : array();
			// The Brands box is drawn only where WooCommerce Brands is on: kept as it was where not.
			$brands = isset( $_POST['brands_drawn'] ) ? ! empty( $_POST['brands'] ) : self::choice()['brands'];
			$said   = self::save_choice( $names, $brands );
			self::notice( $said['ok'] ? 'success' : 'warning', $said['message'] );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tcgiant-settings' ) . '#tc-filterable' );
		exit;
	}

	/**
	 * Leave a notice for the section.
	 *
	 * @param string $type success, warning or error.
	 * @param string $text What to say.
	 */
	private static function notice( $type, $text ) {
		set_transient( 'tcgiant_filterable_notice_' . get_current_user_id(), array( 'type' => $type, 'text' => (string) $text ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Show, once, the notice left for this user.
	 */
	public static function show_notice() {
		$key    = 'tcgiant_filterable_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr( (string) $notice['type'] ), esc_html( (string) $notice['text'] ) );
	}

	// -------------------------------------------------------------------------
	// Holds and storage
	// -------------------------------------------------------------------------

	/**
	 * Hold a piece of this work for one request, as Re-send to eBay holds its
	 * own: a plain INSERT into the options table, whose unique name lets one
	 * request in; a hold past its time is a request that was killed, taken
	 * over by deleting exactly the value read.
	 *
	 * @param string $what    run or count.
	 * @param int    $seconds How long the hold lasts at most.
	 * @return bool
	 */
	private static function take_lock( $what, $seconds ) {
		global $wpdb;

		$name  = self::LOCK_PREFIX . $what;
		$now   = time();
		$value = ( $now + (int) $seconds ) . '|' . wp_generate_uuid4();
		$first = "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s ORDER BY option_id ASC LIMIT 1";

		for ( $try = 0; $try < 2; $try++ ) {
			$quiet = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$took = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				$name,
				$value
			) );
			$wpdb->suppress_errors( $quiet );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$held = $wpdb->get_var( $wpdb->prepare( $first, $name ) );

			if ( $took && $value === $held ) {
				self::$held[ $what ] = $value;
				return true;
			}
			if ( $took ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value ) );
			}
			if ( null === $held || (int) $held > $now ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $held ) );
		}

		return false;
	}

	/**
	 * Let go of a hold: this request's own, never one taken over since.
	 *
	 * @param string $what run or count.
	 */
	private static function release_lock( $what ) {
		global $wpdb;

		if ( empty( self::$held[ $what ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_PREFIX . $what, self::$held[ $what ] ) );
		unset( self::$held[ $what ] );
	}

	/**
	 * An option as the table has it now, not as this request first read it.
	 *
	 * @param string $name Option.
	 * @return array
	 */
	private static function read( $name ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
		}
		$value = get_option( $name, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Write an option, never autoloaded.
	 *
	 * @param string $name  Option.
	 * @param array  $value Value.
	 */
	private static function write( $name, array $value ) {
		update_option( $name, $value, false );
	}
}
