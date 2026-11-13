<?php
/**
 * Product identifiers on a push (3.30.3): UPC, EAN and ISBN, and eBay's own
 * "Does not apply" where a category requires one and the product has none.
 *
 * Until 3.30.3 a push sent eBay no product identifier at all, and a listing
 * in a category that requires one was refused by eBay: AddFixedPriceItem's
 * "The UPC field is missing. Please add UPC to the listing and try again."
 * Now a product, and each variation, can have one (_ebay_export_identifier,
 * on the eBay tab and under Variations), with WooCommerce's own GTIN field
 * (WooCommerce 9.2's global_unique_id) read where it is empty; and where a
 * product has none and its category requires one, the push sends the text
 * eBay gives for "no identifier" on the listing's site - its own words,
 * localised: "Does not apply" on eBay US, "Nicht zutreffend" on eBay
 * Germany - when the shop's setting (Settings, Push to eBay Settings) or the
 * product's own choice says so, and refuses the push before eBay otherwise.
 *
 * What eBay's documentation says, as read on 2026-11-04:
 *
 * - Item.ProductListingDetails carries a single-variation listing's UPC, EAN
 *   and ISBN; a multi-variation listing's go in each
 *   Variation.VariationProductListingDetails, the same types for every
 *   variation, and "If a product identifier is provided in the
 *   Item.ProductListingDetails container for a multiple-variation listing,
 *   the add/revise/relist call will fail."
 * - Where the category expects one the product does not have, "the seller
 *   must provide a string indicating that the product identifier is
 *   unavailable", which varies by site: GeteBayDetails' ProductDetails.
 *   ProductIdentifierUnavailableText, "This text should only be used if no
 *   product ID exists for the product". GeteBayDetails is deprecated, to be
 *   decommissioned on 15 March 2027, and its migration guide moves this field
 *   to no new method - "Static information; product identifier guidance is
 *   documented per marketplace language" - so eBay's own table of it
 *   (UNAVAILABLE_TEXT) is what a site gets when the call cannot answer.
 * - Which identifiers a category requires: GetCategoryFeatures' UPCEnabled,
 *   EANEnabled and ISBNEnabled (Disabled, Enabled or Required). That call is
 *   deprecated too, and its guide maps the three to the Taxonomy API's
 *   getItemAspectsForCategory - an aspect named UPC, EAN or ISBN, with
 *   aspectRequired - which every push already reads (cached a week). 3.30.3
 *   asked both. On 2026-11-04, on eBay's live API, GetCategoryFeatures
 *   answered with no XML at all, every time ("eBay Trading API Error:
 *   Invalid XML Response."), so since 3.31.1 it is not asked: the
 *   requirement is the category's item specifics, and eBay's own refusals
 *   (below). Neither saying so is "not known", and then nothing is sent and
 *   nothing refused, as before 3.30.3.
 * - IncludeeBayProductDetails defaults to true: eBay looks the identifier up
 *   in its catalogue and builds the listing from the catalogue product's
 *   title, description, item specifics and stock photo. The plugin's
 *   listings are the shop's own, so it is sent false with every identifier.
 * - A revise "cannot remove existing product listing details data", nor
 *   change an identifier once the listing has bids or ends within 12 hours.
 *   So an Update sends no identifier at all, as before 3.30.3 (fix round 1),
 *   and nor does a relist (fix round 2), which keeps what the listing had:
 *   a listing already on eBay never fails for one. An identifier added to a
 *   product already listed reaches eBay when it is typed into the listing on
 *   eBay, or with a new listing.
 *
 * And eBay's own refusal (fix round 1): AddFixedPriceItem, AddItem,
 * VerifyAddFixedPriceItem or VerifyAddItem refused only for a missing UPC,
 * EAN or ISBN (refused_for() - "The UPC field is missing.
 * Please add UPC to the listing and try again.", by its words: eBay's error
 * reference lists no code for it) is learnt for the category on that site, a
 * week (learn()), whatever the item specifics say, and the item is sent once
 * more with "Does not apply" for what eBay named, when the setting or the
 * product says so (TCGiant_Sync_Exporter::identifier_resend()). So the
 * shop's case is mended where the item specifics do not say, and later
 * pushes in the category send it the first time.
 *
 * Calls this adds, each cached per site: GeteBayDetails once a week for the
 * site a "Does not apply" goes to, an hour after it fails. The item
 * specifics are the push's own read. A refusal eBay names an identifier in
 * costs the one call sent again. (3.30.3 also asked GetCategoryFeatures once
 * a week a category; 3.31.1 asks it nothing, and deletes what was kept of
 * its answers - forget_category_features().)
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Identifiers class.
 */
class TCGiant_Sync_Identifiers {

	/** A product's or a variation's own identifier, as the shop typed it. */
	const META = '_ebay_export_identifier';

	/** Which identifier it is: '' to tell by its digits, or UPC, EAN, ISBN. */
	const TYPE_META = '_ebay_export_identifier_type';

	/** '1': the product has none, and a push sends eBay's "Does not apply". */
	const NONE_META = '_ebay_export_identifier_none';

	/**
	 * A variation's field in the product form, by its place: a name of its
	 * own, never the eBay tab's, which the same form posts for the product.
	 */
	const VARIATION_FIELD = '_ebay_export_identifier_var';

	/** Settings: send "Does not apply" where a category requires one ('1', the default) or refuse ('0'). */
	const SETTING = 'export_identifier_unavailable';

	/** The identifiers eBay takes in ProductListingDetails, in the order sent. */
	const TYPES = array( 'EAN', 'ISBN', 'UPC' );

	/**
	 * What 3.30.3 kept of GetCategoryFeatures' answer for a category, by
	 * site: tcgiant_gtin_req_<site>_<category>. Read by nothing since 3.31.1;
	 * named only so forget_category_features() can delete them.
	 */
	const FEATURES_TRANSIENT = 'tcgiant_gtin_req_';

	/** That they were deleted (3.31.1): the version that did it. */
	const FEATURES_FORGOTTEN_OPTION = 'tcgiant_sync_gtin_req_forgotten';

	/** GeteBayDetails' ProductIdentifierUnavailableText, by site: tcgiant_gtin_text_<site>. */
	const TEXT_TRANSIENT = 'tcgiant_gtin_text_';

	/** What eBay's own refusals taught of a category, by site: tcgiant_gtin_learnt_<site>_<category> (fix round 1). */
	const LEARNT_TRANSIENT = 'tcgiant_gtin_learnt_';

	/**
	 * eBay's refusal of a listing for a product identifier it lacks, by its
	 * words, as the shop's log had it: "The UPC field is missing. Please add
	 * UPC to the listing and try again.", and the same of an item specific
	 * ("The item specific UPC is missing."). Matched in English: the add
	 * calls ask eBay for its errors in en_US.
	 */
	const MISSING_PATTERN = '/\bThe (?:item specific )?(UPC|EAN|ISBN)(?: field)? is missing\b/i';

	/** How long an answer is kept: a week, as eBay recommends for GeteBayDetails. */
	const KEEP_SECONDS = 604800;

	/** How long a call that failed is not asked again: GeteBayDetails. */
	const FAILED_SECONDS = 3600;

	/** A product a 14-digit GTIN was said of, a month: tcgiant_gtin14_<product> (fix round 2). */
	const GTIN14_TRANSIENT = 'tcgiant_gtin14_';

	/** eBay Motors' site ID, where an eBay Motors listing's category is asked about. */
	const MOTORS_SITE_ID = '100';

	/**
	 * eBay's own "no identifier" text by site ID, from its Product Identifier
	 * Text reference (developer.ebay.com, api-docs/sell/static/inventory/
	 * product-identifier-text.html, read 2026-11-04): what a site gets when
	 * GeteBayDetails cannot be asked. eBay Hong Kong is left out: the page's
	 * text for it is not readable.
	 */
	const UNAVAILABLE_TEXT = array(
		'0'   => 'Does not apply',      // eBay US.
		'2'   => 'Does not apply',      // eBay Canada (English).
		'3'   => 'Does not apply',      // eBay UK.
		'15'  => 'Does not apply',      // eBay Australia.
		'16'  => 'Nicht zutreffend',    // eBay Austria.
		'23'  => 'Non applicable',      // eBay Belgium (French).
		'71'  => 'Non applicable',      // eBay France.
		'77'  => 'Nicht zutreffend',    // eBay Germany.
		'100' => 'Does not apply',      // eBay Motors.
		'101' => 'Non applicabile',     // eBay Italy.
		'123' => 'Niet van toepassing', // eBay Belgium (Dutch).
		'146' => 'Niet van toepassing', // eBay Netherlands.
		'186' => 'No aplicable',        // eBay Spain.
		'193' => 'Nicht zutreffend',    // eBay Switzerland.
		'205' => 'Does not apply',      // eBay Ireland.
		'207' => 'Does not apply',      // eBay Malaysia.
		'210' => 'Non applicable',      // eBay Canada (French).
		'211' => 'Does not apply',      // eBay Philippines.
		'212' => 'Nie dotyczy',         // eBay Poland.
		'216' => 'Does not apply',      // eBay Singapore.
	);

	/**
	 * Stand-ins for "none" a product's field may hold, which are no
	 * identifier: eBay's own texts above, and those the import already
	 * passes over (TCGiant_Sync_Mapper::is_placeholder_identifier()).
	 */
	const PLACEHOLDERS = array( 'does not apply', 'doesnotapply', 'not applicable', 'n/a', 'na', 'none', 'null', 'unbranded', '-', '0', 'nicht zutreffend', 'non applicable', 'non applicabile', 'no aplicable', 'niet van toepassing', 'nie dotyczy' );

	// -------------------------------------------------------------------------
	// What a product has
	// -------------------------------------------------------------------------

	/**
	 * Whether the shop sends eBay's "Does not apply" where a category
	 * requires an identifier and a product has none. On unless the shop
	 * turned it off.
	 *
	 * @param array|null $settings The plugin's settings; read when null.
	 * @return bool
	 */
	public static function sends_unavailable( $settings = null ) {
		if ( null === $settings ) {
			$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		}
		return '0' !== (string) ( is_array( $settings ) ? ( $settings[ self::SETTING ] ?? '1' ) : '1' );
	}

	/**
	 * An identifier as eBay takes it: no spaces or hyphens, which ISBNs and
	 * EANs are often printed with.
	 *
	 * @param mixed $value What a field holds.
	 * @return string
	 */
	public static function normalize( $value ) {
		return is_scalar( $value ) ? strtoupper( (string) preg_replace( '/[\s\-]+/u', '', (string) $value ) ) : '';
	}

	/**
	 * Whether a field holds a stand-in for "none" rather than an identifier.
	 *
	 * @param mixed $value What a field holds.
	 * @return bool
	 */
	public static function is_placeholder( $value ) {
		$plain = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return '' === $plain || in_array( $plain, self::PLACEHOLDERS, true );
	}

	/**
	 * Which identifier a value is: the one the shop chose for the product, or
	 * else by its digits - 12 a UPC, 13 an EAN unless it starts 978 or 979
	 * (an ISBN-13), 8 an EAN-8, 10 with a last digit or X an ISBN-10. '' for
	 * one that is none of them, which is not sent.
	 *
	 * @param string $value    normalize()'s value.
	 * @param string $declared UPC, EAN, ISBN, or '' to tell.
	 * @return string
	 */
	public static function type_of( $value, $declared = '' ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( in_array( $declared, self::TYPES, true ) ) {
			return $declared;
		}
		if ( 1 === preg_match( '/^\d{12}$/', $value ) ) {
			return 'UPC';
		}
		if ( 1 === preg_match( '/^\d{13}$/', $value ) ) {
			return in_array( substr( $value, 0, 3 ), array( '978', '979' ), true ) ? 'ISBN' : 'EAN';
		}
		if ( 1 === preg_match( '/^\d{8}$/', $value ) ) {
			return 'EAN';
		}
		if ( 1 === preg_match( '/^\d{9}[\dX]$/', $value ) ) {
			return 'ISBN';
		}
		return '';
	}

	/**
	 * The type a product's identifiers are, as the shop chose: '' to tell.
	 *
	 * @param int $product_id The product (a variation's parent for a variation).
	 * @return string
	 */
	public static function declared_type( $product_id ) {
		$type = strtoupper( trim( (string) get_post_meta( (int) $product_id, self::TYPE_META, true ) ) );
		return in_array( $type, self::TYPES, true ) ? $type : '';
	}

	/**
	 * The identifier of a product or variation: its own field, or
	 * WooCommerce's GTIN where that is empty (WooCommerce 9.2 and later), and
	 * neither when it holds a stand-in for none.
	 *
	 * A variation's GTIN is its own alone (fix round 2): read in WooCommerce's
	 * 'edit' context, never a value a filter gives it from its parent. (The
	 * WooCommerce source on hand, 11.3, keeps the parent's _global_unique_id
	 * in a variation's parent data but its getter does not fall back to it, as
	 * get_sku() does; 'edit' makes sure of it whatever a version or a plugin
	 * does.)
	 *
	 * A 14-digit GTIN (a GTIN-14, or a code padded with zeros) is none of
	 * eBay's identifier types (fix round 2): not sent, and the product taken
	 * as having none - so "Does not apply" goes where the category requires
	 * one, or the push is refused - with the value in 'gtin14' to be said.
	 *
	 * @param WC_Product $product  The product or variation.
	 * @param string     $declared The type the shop chose, or ''.
	 * @return array{value: string, type: string, from: string, gtin14: string} from: own, woocommerce or ''.
	 */
	public static function of( $product, $declared = '' ) {
		$candidates = array( 'own' => $product->get_meta( self::META ) );
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$candidates['woocommerce'] = $product instanceof WC_Product_Variation ? $product->get_global_unique_id( 'edit' ) : $product->get_global_unique_id();
		}
		$gtin14 = '';
		foreach ( $candidates as $from => $raw ) {
			if ( self::is_placeholder( $raw ) ) {
				continue;
			}
			$value = self::normalize( $raw );
			if ( 1 === preg_match( '/^\d{14}$/', $value ) ) {
				$gtin14 = '' !== $gtin14 ? $gtin14 : $value;
				continue;
			}
			return array( 'value' => $value, 'type' => self::type_of( $value, $declared ), 'from' => $from, 'gtin14' => $gtin14 );
		}
		return array( 'value' => '', 'type' => '', 'from' => '', 'gtin14' => $gtin14 );
	}

	// -------------------------------------------------------------------------
	// What eBay requires
	// -------------------------------------------------------------------------

	/**
	 * The site a listing's category is asked about: eBay Motors' for a
	 * listing that goes there (TCGiant_Sync_Exporter::settle_motors()'s
	 * _request_site), the shop's marketplace's otherwise.
	 *
	 * @param array $settings Settled export settings.
	 * @return string X-EBAY-API-SITEID.
	 */
	public static function lookup_site( array $settings ) {
		if ( class_exists( 'TCGiant_Sync_Motors', false ) && TCGiant_Sync_Motors::SITE === (string) ( $settings['_request_site'] ?? '' ) ) {
			return self::MOTORS_SITE_ID;
		}
		return (string) TCGiant_Sync_API::instance()->get_marketplace_config()['site_id'];
	}

	/**
	 * Delete what 3.30.3 kept of GetCategoryFeatures' answers (3.31.1), once:
	 * nothing reads them now that the call is not made. Their rows in the
	 * options table, each with its timeout. A host with a persistent object
	 * cache keeps transients in the cache only, where they cannot be listed;
	 * there they go when they expire - a week at most, a day for a failure -
	 * read by nothing meanwhile.
	 *
	 * Recorded first, so two requests do not both run it.
	 *
	 * @return void
	 */
	public static function forget_category_features() {
		if ( '3.31.1' === get_option( self::FEATURES_FORGOTTEN_OPTION, '' ) ) {
			return;
		}
		update_option( self::FEATURES_FORGOTTEN_OPTION, '3.31.1', true );

		global $wpdb;
		// The LIKE patterns escaped as wpdb::esc_like() escapes them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			addcslashes( '_transient_' . self::FEATURES_TRANSIENT, '_%\\' ) . '%',
			addcslashes( '_transient_timeout_' . self::FEATURES_TRANSIENT, '_%\\' ) . '%'
		) );
	}

	/**
	 * The types eBay's own refusal says an item lacks, when that is all it
	 * says: every error in its answer (warnings aside) is MISSING_PATTERN's.
	 * None for any other refusal, or one that mixes another error in.
	 *
	 * @param mixed $error What a listing call gave back.
	 * @return string[] Types, in TYPES' order.
	 */
	public static function refused_for( $error ) {
		if ( ! is_wp_error( $error ) || 'api_error' !== $error->get_error_code() ) {
			return array();
		}

		$data   = $error->get_error_data();
		$errors = is_array( $data ) && isset( $data['Errors'] ) && is_array( $data['Errors'] ) ? $data['Errors'] : array();
		if ( isset( $errors['ErrorCode'] ) || isset( $errors['ShortMessage'] ) || isset( $errors['LongMessage'] ) ) {
			$errors = array( $errors );
		}
		$said = array();
		foreach ( $errors as $one ) {
			if ( ! is_array( $one ) || 'Warning' === TCGiant_Sync_API::scalar_value( $one['SeverityCode'] ?? '' ) ) {
				continue;
			}
			$long   = TCGiant_Sync_API::scalar_value( $one['LongMessage'] ?? '' );
			$said[] = '' !== $long ? $long : TCGiant_Sync_API::scalar_value( $one['ShortMessage'] ?? '' );
		}
		if ( ! $said ) {
			$said = explode( ' | ', $error->get_error_message() );
		}

		$types = array();
		foreach ( $said as $words ) {
			if ( 1 !== preg_match( self::MISSING_PATTERN, (string) $words, $hit ) ) {
				return array();
			}
			$types[ strtoupper( $hit[1] ) ] = true;
		}

		return array_values( array_intersect( self::TYPES, array_keys( $types ) ) );
	}

	/**
	 * eBay's errors in an answer, each with its severity and code, as the
	 * log says them (fix round 2): "Error 21919301: The UPC field is
	 * missing. ...; Warning 123: ...". The message alone where the answer
	 * carries no errors.
	 *
	 * @param mixed $error What a listing call gave back.
	 * @return string
	 */
	public static function errors_said( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return '';
		}
		$data   = $error->get_error_data();
		$errors = is_array( $data ) && isset( $data['Errors'] ) && is_array( $data['Errors'] ) ? $data['Errors'] : array();
		if ( isset( $errors['ErrorCode'] ) || isset( $errors['ShortMessage'] ) || isset( $errors['LongMessage'] ) ) {
			$errors = array( $errors );
		}
		$said = array();
		foreach ( $errors as $one ) {
			if ( ! is_array( $one ) ) {
				continue;
			}
			$severity = TCGiant_Sync_API::scalar_value( $one['SeverityCode'] ?? '' );
			$code     = TCGiant_Sync_API::scalar_value( $one['ErrorCode'] ?? '' );
			$long     = TCGiant_Sync_API::scalar_value( $one['LongMessage'] ?? '' );
			$said[]   = ( '' !== $severity ? $severity : 'Error' ) . ( '' !== $code ? ' ' . $code : '' ) . ': ' . ( '' !== $long ? $long : TCGiant_Sync_API::scalar_value( $one['ShortMessage'] ?? '' ) );
		}
		return $said ? implode( '; ', $said ) : $error->get_error_message();
	}

	/**
	 * Whether any error or warning in eBay's answer says an identifier is
	 * missing, whatever else it says: for the log, when the item is not sent
	 * again (fix round 2).
	 *
	 * @param mixed $error What a listing call gave back.
	 * @return bool
	 */
	public static function mentions_missing( $error ) {
		return is_wp_error( $error ) && 1 === preg_match( self::MISSING_PATTERN, self::errors_said( $error ) );
	}

	/**
	 * What eBay's refusals have taught of a category on a site, this week.
	 *
	 * @param string $site_id     X-EBAY-API-SITEID.
	 * @param string $category_id eBay category.
	 * @return string[] Types.
	 */
	public static function learnt( $site_id, $category_id ) {
		$kept = get_transient( self::learnt_key( $site_id, $category_id ) );
		return is_array( $kept ) ? array_values( array_intersect( self::TYPES, $kept ) ) : array();
	}

	/**
	 * Keep, a week, that eBay refused a listing in a category on a site for
	 * these identifiers, beside what it said before.
	 *
	 * @param string   $site_id     X-EBAY-API-SITEID.
	 * @param string   $category_id eBay category.
	 * @param string[] $types       The types eBay named.
	 */
	public static function learn( $site_id, $category_id, array $types ) {
		if ( '' === trim( (string) $category_id ) || ! $types ) {
			return;
		}
		$all = array_values( array_intersect( self::TYPES, array_merge( self::learnt( $site_id, $category_id ), $types ) ) );
		set_transient( self::learnt_key( $site_id, $category_id ), $all, self::KEEP_SECONDS );
		TCGiant_Sync_Logger::log( sprintf( 'Product identifiers: eBay refused a listing in category %1$s on site %2$s for a missing %3$s; kept a week, so a listing there with none is sent eBay\'s "Does not apply" for it the first time.', (string) $category_id, (string) $site_id, implode( ' and ', $types ) ) );
	}

	/**
	 * The transient a category's learnt requirement is kept under.
	 *
	 * @param string $site_id     X-EBAY-API-SITEID.
	 * @param string $category_id eBay category.
	 * @return string
	 */
	private static function learnt_key( $site_id, $category_id ) {
		return self::LEARNT_TRANSIENT . preg_replace( '/[^0-9]/', '', (string) $site_id ) . '_' . preg_replace( '/[^0-9A-Za-z]/', '', (string) $category_id );
	}

	/**
	 * Which identifiers a category requires: those its item specifics
	 * (getItemAspectsForCategory, as the push reads them) have as required
	 * aspects, and those eBay's own refusals named this week (learn()).
	 * GetCategoryFeatures is not asked (3.31.1): eBay's live API answered it
	 * with no XML at all.
	 *
	 * @param array               $settings Settled export settings.
	 * @param array|WP_Error|null $aspects  The category's aspects, if read.
	 * @return string[] Types, in TYPES' order.
	 */
	public static function required_types( array $settings, $aspects ) {
		$site     = self::lookup_site( $settings );
		$category = (string) ( $settings['category_id'] ?? '' );
		$required = array_fill_keys( self::learnt( $site, $category ), true );

		if ( is_array( $aspects ) ) {
			foreach ( $aspects as $aspect ) {
				$name = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) ( $aspect['name'] ?? '' ) ) );
				if ( ! empty( $aspect['required'] ) && in_array( $name, self::TYPES, true ) ) {
					$required[ $name ] = true;
				}
			}
		}

		return array_values( array_intersect( self::TYPES, array_keys( $required ) ) );
	}

	/**
	 * eBay's own "no identifier" text for a site: GeteBayDetails' kept a
	 * week, or eBay's documented one (UNAVAILABLE_TEXT) when it cannot be
	 * asked. '' for a site eBay documents none for.
	 *
	 * @param string $site_id X-EBAY-API-SITEID.
	 * @return string
	 */
	public static function unavailable_text( $site_id ) {
		$site_id = preg_replace( '/[^0-9]/', '', (string) $site_id );
		$key     = self::TEXT_TRANSIENT . $site_id;
		$kept    = get_transient( $key );
		if ( is_array( $kept ) ) {
			return '' !== (string) ( $kept['text'] ?? '' ) ? (string) $kept['text'] : (string) ( self::UNAVAILABLE_TEXT[ $site_id ] ?? '' );
		}

		$answer = TCGiant_Sync_API::instance()->get_identifier_unavailable_text( $site_id );
		if ( is_wp_error( $answer ) || '' === trim( (string) $answer ) ) {
			set_transient( $key, array( 'text' => '' ), self::FAILED_SECONDS );
			return (string) ( self::UNAVAILABLE_TEXT[ $site_id ] ?? '' );
		}

		$text = trim( (string) $answer );
		set_transient( $key, array( 'text' => $text ), self::KEEP_SECONDS );
		return $text;
	}

	// -------------------------------------------------------------------------
	// What a push sends
	// -------------------------------------------------------------------------

	/**
	 * What one build sends: each identifier of the item or of each variation,
	 * and why the push may not go, if it may not.
	 *
	 * - 'new', a new listing or Verify: the product's (each variation's) own
	 *   identifier; where one has none and the category requires one, eBay's
	 *   "Does not apply" for each type required (for a variation listing, of
	 *   the types its variations use, or else those required), when the
	 *   shop's setting or the product's own choice says so - and otherwise
	 *   the push is refused, before eBay.
	 * - 'revise', an Update: none at all (fix round 1), as before 3.30.3. A
	 *   relist builds no item and sends none either (fix round 2).
	 * - A slot whose only value is a 14-digit GTIN has none (of()), and is
	 *   said once a product ('gtin14').
	 * - An item specific named UPC, EAN or ISBN (an attribute, or typed on
	 *   the eBay tab) is the product's identifier of that type: it goes as an
	 *   item specific, as before, and nothing is added for it.
	 *
	 * Where the category's requirement is not known - its item specifics do
	 * not say, and eBay has not refused a listing there for one - a product
	 * with none is sent none, and eBay judges, as before 3.30.3.
	 *
	 * @param WC_Product           $product   The product.
	 * @param array                $settings  Settled export settings.
	 * @param string|bool          $mode      'new' or 'revise'; true for a
	 *                                        revise, false a new one.
	 * @param array<string,string> $specifics The item specifics the item sends.
	 * @param callable|null        $aspects   Gives the category's aspects, when wanted.
	 * @return array{item: array, variations: array, unavailable: array, refused: string, required: array, unread: array, gtin14: array}
	 *         item TYPE => value; variations ID => TYPE => value; unavailable
	 *         what "Does not apply" went for (types, text, slots); refused
	 *         why the push may not go, or ''; required the types the
	 *         category requires, where asked; unread slot => a value that is
	 *         no identifier; gtin14 slot => a 14-digit GTIN not sent.
	 */
	public static function plan( $product, array $settings, $mode, array $specifics = array(), $aspects = null ) {
		$plan = array( 'item' => array(), 'variations' => array(), 'unavailable' => array(), 'refused' => '', 'required' => array(), 'unread' => array(), 'gtin14' => array() );

		// An Update sends none (fix round 1): eBay refuses a changed
		// identifier on a listing with bids or ending within 12 hours, and
		// never takes one away, so a live listing's Update is left as it was.
		if ( true === $mode || 'revise' === $mode ) {
			return $plan;
		}

		$declared = self::declared_type( $product->get_id() );
		$variable = $product->is_type( 'variable' );

		// Each slot that carries identifiers: the item, or each variation the
		// item lists.
		$slots = array();
		if ( $variable ) {
			foreach ( (array) $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child instanceof WC_Product_Variation && 'publish' === $child->get_status() ) {
					$slots[ (int) $child_id ] = self::of( $child, $declared );
				}
			}
		} else {
			$slots[0] = self::of( $product, $declared );
			// One the item specifics carry already - "Does not apply" typed
			// as one included - goes as it is, and is the product's.
			foreach ( $specifics as $name => $value ) {
				$type = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $name ) );
				if ( in_array( $type, self::TYPES, true ) && '' !== trim( (string) $value ) ) {
					$slots[0]['specific'][ $type ] = true;
				}
			}
		}

		// Values first; the types the listing's identifiers are.
		$used   = array();
		$lacked = array();
		$found  = array();
		foreach ( $slots as $id => $slot ) {
			$in_specifics = array_keys( (array) ( $slot['specific'] ?? array() ) );
			if ( '' !== $slot['type'] && ! in_array( $slot['type'], $in_specifics, true ) ) {
				$found[ $id ][ $slot['type'] ] = $slot['value'];
			}
			if ( '' === $slot['value'] && '' !== $slot['gtin14'] ) {
				$plan['gtin14'][ $id ] = $slot['gtin14'];
			}
			if ( '' !== $slot['value'] && '' === $slot['type'] ) {
				// Digits that are no UPC, EAN or ISBN, and no type chosen: not
				// sent, and said (TCGiant_Sync_Exporter::build_item_xml()).
				$plan['unread'][ $id ] = $slot['value'];
			}
			if ( '' !== $slot['type'] ) {
				$used[ $slot['type'] ] = true;
			}
			foreach ( $in_specifics as $type ) {
				$used[ $type ] = true;
			}
			if ( '' === $slot['value'] && ! $in_specifics ) {
				$lacked[] = $id;
			}
		}
		if ( $variable ) {
			$plan['variations'] = $found;
		} else {
			$plan['item'] = $found[0] ?? array();
		}

		if ( ! $lacked ) {
			return $plan;
		}

		$required         = self::required_types( $settings, is_callable( $aspects ) ? call_user_func( $aspects ) : null );
		$plan['required'] = $required;
		$wanted           = $used ? array_values( array_intersect( array_keys( $used ), $required ) ) : $required;
		if ( ! $wanted ) {
			return $plan;
		}

		$allowed = self::sends_unavailable() || '1' === (string) get_post_meta( $product->get_id(), self::NONE_META, true );
		$text    = $allowed ? self::unavailable_text( self::lookup_site( $settings ) ) : '';
		if ( '' === $text ) {
			$plan['refused'] = self::refusal( $product, $settings, $wanted, $variable ? $lacked : array(), $allowed );
			return $plan;
		}

		foreach ( $lacked as $id ) {
			foreach ( $wanted as $type ) {
				if ( $variable ) {
					$plan['variations'][ $id ][ $type ] = $text;
				} else {
					$plan['item'][ $type ] = $text;
				}
			}
		}
		$plan['unavailable'] = array( 'types' => $wanted, 'text' => $text, 'slots' => $variable ? $lacked : array() );

		return $plan;
	}

	/**
	 * Why a push with no identifier where its category requires one may not
	 * go, in 3.28.5's words for a refusal before eBay.
	 *
	 * @param WC_Product $product  The product.
	 * @param array      $settings Settled export settings.
	 * @param string[]   $types    The types required.
	 * @param int[]      $children The variations with none, for a variation listing.
	 * @param bool       $allowed  Whether "Does not apply" was allowed (and eBay gave no text for the site).
	 * @return string
	 */
	private static function refusal( $product, array $settings, array $types, array $children, $allowed ) {
		if ( count( $children ) > 1 ) {
			/* translators: %s: variation IDs */
			$who = sprintf( __( 'variations #%s have none', 'tcgiant-sync' ), implode( ', #', $children ) );
		} elseif ( $children ) {
			/* translators: %s: a variation's ID */
			$who = sprintf( __( 'variation #%s has none', 'tcgiant-sync' ), (string) reset( $children ) );
		} else {
			$who = __( 'this product has none', 'tcgiant-sync' );
		}

		$why = $allowed
			/* translators: 1: UPC, EAN or ISBN, 2: eBay category ID, 3: whose */
			? sprintf( __( 'eBay requires %1$s in category %2$s, %3$s, and eBay gives no "Does not apply" text for this eBay site: enter it in Product Identifier on the product\'s eBay tab', 'tcgiant-sync' ), implode( ' / ', $types ), (string) ( $settings['category_id'] ?? '' ), $who )
			/* translators: 1: UPC, EAN or ISBN, 2: eBay category ID, 3: whose */
			: sprintf( __( 'eBay requires %1$s in category %2$s, and %3$s: enter it in Product Identifier on the product\'s eBay tab, or tick "This product has no UPC, EAN or ISBN" there, or choose to send eBay\'s "Does not apply" in Settings, Push to eBay Settings', 'tcgiant-sync' ), implode( ' / ', $types ), (string) ( $settings['category_id'] ?? '' ), $who );

		/* translators: %s: comma-separated list of missing settings */
		return sprintf( __( 'Cannot push to eBay — missing required settings: %s', 'tcgiant-sync' ), sprintf(
			/* translators: %s: why */
			__( 'Product Identifier — %s', 'tcgiant-sync' ),
			$why
		) );
	}

	/**
	 * Item.ProductListingDetails for a plan: '' when it has none for the item.
	 *
	 * @param array $plan plan()'s.
	 * @return string
	 */
	public static function item_xml( array $plan ) {
		if ( empty( $plan['item'] ) ) {
			return '';
		}
		$xml = '<ProductListingDetails>' . "\n";
		foreach ( self::TYPES as $type ) {
			if ( isset( $plan['item'][ $type ] ) ) {
				$xml .= "\t<" . $type . '>' . esc_xml( $plan['item'][ $type ] ) . '</' . $type . ">\n";
			}
		}
		$xml .= "\t<IncludeeBayProductDetails>false</IncludeeBayProductDetails>\n";
		return $xml . '</ProductListingDetails>' . "\n";
	}

	/**
	 * A variation's VariationProductListingDetails for a plan: '' when it has none.
	 *
	 * @param array $plan     plan()'s.
	 * @param int   $child_id The variation.
	 * @return string
	 */
	public static function variation_xml( array $plan, $child_id ) {
		$mine = $plan['variations'][ (int) $child_id ] ?? array();
		if ( ! $mine ) {
			return '';
		}
		$xml = "\t\t<VariationProductListingDetails>\n";
		foreach ( self::TYPES as $type ) {
			if ( isset( $mine[ $type ] ) ) {
				$xml .= "\t\t\t<" . $type . '>' . esc_xml( $mine[ $type ] ) . '</' . $type . ">\n";
			}
		}
		return $xml . "\t\t</VariationProductListingDetails>\n";
	}

	/**
	 * Whether a 14-digit GTIN not sent is still to be said of a product: once
	 * a product and value, a month (fix round 2).
	 *
	 * @param int    $product_id The product (or variation).
	 * @param string $value      The GTIN.
	 * @return bool
	 */
	public static function gtin14_unsaid( $product_id, $value ) {
		$key = self::GTIN14_TRANSIENT . (int) $product_id;
		if ( (string) get_transient( $key ) === (string) $value ) {
			return false;
		}
		set_transient( $key, (string) $value, 2592000 );
		return true;
	}

	// -------------------------------------------------------------------------
	// Admin fields
	// -------------------------------------------------------------------------

	/**
	 * The fields' hooks: a variation's field and every save. The product's
	 * own section is drawn on its eBay tab by
	 * TCGiant_Sync_Admin::render_ebay_listing_panel().
	 */
	public static function register_admin() {
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_field' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation_field' ), 30, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_field' ), 10, 2 );
	}

	/**
	 * Set or clear an identifier from what the shop typed.
	 *
	 * @param int    $product_id Product or variation.
	 * @param string $typed      What was typed.
	 */
	public static function set_own( $product_id, $typed ) {
		$value = trim( (string) $typed );
		if ( '' === $value ) {
			delete_post_meta( (int) $product_id, self::META );
			return;
		}
		update_post_meta( (int) $product_id, self::META, mb_substr( $value, 0, 32 ) );
	}

	/**
	 * Save what the eBay tab posted, when it posted it: the product screen's
	 * own save, or the eBay tab's Push and Verify buttons.
	 *
	 * @param int   $product_id The product.
	 * @param array $posted     $_POST.
	 */
	public static function save_posted( $product_id, array $posted ) {
		if ( ! isset( $posted[ self::META . '_shown' ] ) ) {
			return;
		}
		if ( isset( $posted[ self::META ] ) && is_string( $posted[ self::META ] ) ) {
			self::set_own( (int) $product_id, sanitize_text_field( wp_unslash( $posted[ self::META ] ) ) );
		}
		$type = isset( $posted[ self::TYPE_META ] ) && is_string( $posted[ self::TYPE_META ] ) ? strtoupper( sanitize_text_field( wp_unslash( $posted[ self::TYPE_META ] ) ) ) : '';
		if ( in_array( $type, self::TYPES, true ) ) {
			update_post_meta( (int) $product_id, self::TYPE_META, $type );
		} else {
			delete_post_meta( (int) $product_id, self::TYPE_META );
		}
		self::set_none( (int) $product_id, isset( $posted[ self::NONE_META ] ) && '1' === (string) $posted[ self::NONE_META ] );
	}

	/**
	 * Tick or untick "This product has no UPC, EAN or ISBN": the eBay tab's
	 * box, and the bulk edit's (3.39.0).
	 *
	 * @param int  $product_id The product.
	 * @param bool $none       Ticked.
	 */
	public static function set_none( $product_id, $none ) {
		if ( $none ) {
			update_post_meta( (int) $product_id, self::NONE_META, '1' );
		} else {
			delete_post_meta( (int) $product_id, self::NONE_META );
		}
	}

	/**
	 * woocommerce_process_product_meta: the eBay tab's section.
	 *
	 * @param int $post_id The product.
	 */
	public static function save_product_field( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked its own nonce before this action.
		self::save_posted( (int) $post_id, $_POST );
	}

	/**
	 * woocommerce_save_product_variation: a variation's field.
	 *
	 * @param int $variation_id The variation.
	 * @param int $i            Its place in the form.
	 */
	public static function save_variation_field( $variation_id, $i ) {
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked its own nonce before this action.
		if ( isset( $_POST[ self::VARIATION_FIELD . '_shown' ][ $i ], $_POST[ self::VARIATION_FIELD ][ $i ] ) && is_string( $_POST[ self::VARIATION_FIELD ][ $i ] ) ) {
			self::set_own( (int) $variation_id, sanitize_text_field( wp_unslash( $_POST[ self::VARIATION_FIELD ][ $i ] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * woocommerce_variation_options_pricing: a variation's identifier.
	 *
	 * @param int     $loop           Its place in the form.
	 * @param array   $variation_data Its data.
	 * @param WP_Post $variation      Its post.
	 */
	public static function render_variation_field( $loop, $variation_data, $variation ) {
		$id = (int) $variation->ID;
		echo '<input type="hidden" name="' . esc_attr( self::VARIATION_FIELD . '_shown[' . (int) $loop . ']' ) . '" value="1">';
		woocommerce_wp_text_input( array(
			'id'            => self::VARIATION_FIELD . '_' . (int) $loop,
			'name'          => self::VARIATION_FIELD . '[' . (int) $loop . ']',
			'label'         => __( 'eBay UPC, EAN or ISBN', 'tcgiant-sync' ),
			'value'         => (string) get_post_meta( $id, self::META, true ),
			'wrapper_class' => 'form-row form-row-full',
			'desc_tip'      => true,
			'description'   => __( 'This variation\'s product identifier on eBay. Leave it empty to send WooCommerce\'s GTIN, UPC, EAN or ISBN field, if it has one. The type, and what is sent when a variation has none, are set on the product\'s eBay tab.', 'tcgiant-sync' ),
		) );
	}

	/**
	 * The Product Identifier section of a product's eBay tab.
	 *
	 * @param WC_Product $product The product.
	 */
	public static function render_product_section( $product ) {
		$product_id = (int) $product->get_id();
		$variable   = $product->is_type( 'variable' );
		$declared   = self::declared_type( $product_id );
		$own        = (string) get_post_meta( $product_id, self::META, true );
		$none       = '1' === (string) get_post_meta( $product_id, self::NONE_META, true );
		$shown      = $variable ? array( 'value' => '', 'type' => '', 'from' => '' ) : self::of( $product, $declared );
		$summary    = '' !== $shown['value'] ? trim( $shown['type'] . ' ' . $shown['value'] ) : ( $none ? __( 'None: eBay\'s "Does not apply" where required', 'tcgiant-sync' ) : '' );
		$types      = array(
			''     => __( 'Tell by its digits', 'tcgiant-sync' ),
			'UPC'  => 'UPC',
			'EAN'  => 'EAN',
			'ISBN' => 'ISBN',
		);
		?>
		<div class="tc-ebay-section" id="tc-section-identifier">
			<div class="tc-ebay-section-head" data-target="tc-body-identifier">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<span><?php esc_html_e( 'Product Identifier (UPC, EAN, ISBN)', 'tcgiant-sync' ); ?></span>
				<span id="tc-identifier-summary" style="font-weight:400;color:#888;font-size:12px;margin-left:auto;"><?php echo esc_html( $summary ); ?></span>
			</div>
			<div class="tc-ebay-section-body" id="tc-body-identifier">
				<input type="hidden" name="<?php echo esc_attr( self::META . '_shown' ); ?>" value="1">
				<?php if ( $variable ) : ?>
					<p style="margin:0 0 6px;font-size:12px;color:#555;"><?php esc_html_e( 'Each variation has its own, under Variations (eBay UPC, EAN or ISBN), or WooCommerce\'s GTIN field.', 'tcgiant-sync' ); ?></p>
				<?php else : ?>
					<?php
					woocommerce_wp_text_input( array(
						'id'          => self::META,
						'label'       => __( 'UPC, EAN or ISBN', 'tcgiant-sync' ),
						'value'       => $own,
						'placeholder' => 'woocommerce' === $shown['from'] ? $shown['value'] : '',
						'desc_tip'    => true,
						'description' => __( 'The product\'s barcode number, sent to eBay with the listing. Leave it empty to send WooCommerce\'s GTIN, UPC, EAN or ISBN field (shown greyed), if it has one.', 'tcgiant-sync' ),
					) );
					?>
				<?php endif; ?>
				<p class="form-field">
					<label for="<?php echo esc_attr( self::TYPE_META ); ?>"><?php esc_html_e( 'Which it is', 'tcgiant-sync' ); ?></label>
					<select id="<?php echo esc_attr( self::TYPE_META ); ?>" name="<?php echo esc_attr( self::TYPE_META ); ?>">
						<?php foreach ( $types as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $declared, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="form-field" style="margin:4px 0 0;padding-left:12px;">
					<label for="<?php echo esc_attr( self::NONE_META ); ?>" style="float:none;width:auto;margin:0;">
						<input type="checkbox" id="<?php echo esc_attr( self::NONE_META ); ?>" name="<?php echo esc_attr( self::NONE_META ); ?>" value="1" <?php checked( $none ); ?>>
						<?php esc_html_e( 'This product has no UPC, EAN or ISBN', 'tcgiant-sync' ); ?>
					</label>
					<span style="display:block;margin-top:2px;font-size:11px;color:#888;"><?php esc_html_e( 'Ticked, a new listing sends eBay\'s own "Does not apply" (in the words of your eBay site) where its eBay category requires an identifier, whatever Settings says. A number entered above is always sent instead.', 'tcgiant-sync' ); ?></span>
				</p>
				<p style="margin:4px 0 0;font-size:11px;color:#888;padding-left:12px;">
					<?php esc_html_e( 'A new listing and Verify send it. An Update or a relist of a listing already on eBay does not: an identifier added later reaches eBay when you type it into the listing on eBay, or with a new listing.', 'tcgiant-sync' ); ?>
				</p>
			</div>
		</div>
		<script>
		jQuery( function ( $ ) {
			// The Push and Verify buttons save what the tab holds before the
			// product is updated: the identifier goes with them.
			$.ajaxPrefilter( function ( options ) {
				if ( 'string' !== typeof options.data || ! /(^|&)action=tcgiant_(push|verify)_product(&|$)/.test( options.data ) ) {
					return;
				}
				var value = $( '#<?php echo esc_js( self::META ); ?>' ), type = $( '#<?php echo esc_js( self::TYPE_META ); ?>' ), none = $( '#<?php echo esc_js( self::NONE_META ); ?>' );
				if ( ! type.length ) {
					return;
				}
				options.data += '&<?php echo esc_js( self::META ); ?>_shown=1&<?php echo esc_js( self::TYPE_META ); ?>=' + encodeURIComponent( type.val() || '' ) + ( none.is( ':checked' ) ? '&<?php echo esc_js( self::NONE_META ); ?>=1' : '' ) + ( value.length ? '&<?php echo esc_js( self::META ); ?>=' + encodeURIComponent( value.val() || '' ) : '' );
			} );
		} );
		</script>
		<?php
	}
}
