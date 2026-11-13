<?php
/**
 * Listing options on a push (3.38.0): a subtitle, a condition note, a second
 * category, an auction's Buy It Now and reserve prices, and Brand and MPN.
 *
 * Until 3.38.0 a push sent eBay none of them. Now each is a field of the
 * product's own, on its eBay tab, every one empty unless the shop fills it
 * in - and an empty field sends nothing, so a product's requests are exactly
 * 3.37.0's until one is filled. The import does not fill them: a listing
 * imported from eBay keeps its own subtitle, condition note and the rest.
 *
 * What eBay's documentation says, as read on 2026-11-12 (developer.ebay.com,
 * the Trading API's ReviseItem, RelistItem, AddFixedPriceItem and
 * VerifyAddItem references, its error list and its Trading user guide; and
 * eBay's own fee pages):
 *
 * - Item.SubTitle: "Max length: 55." A fee: "If you pass any value, this
 *   feature is applied (with applicable fees)." A revise "can add, change,
 *   or remove the subtitle", but error 10039 says it "cannot be changed if
 *   an auction-style listing has a bid or ends within 12 hours". Removed by
 *   <DeletedField>Item.SubTitle</DeletedField>, eBay's own example.
 * - Item.ConditionDescription: "Max length: 1000." Ignored, with a warning,
 *   for a New condition (ConditionID 1000-1499), and "should not be
 *   included if ConditionDescriptors are being used ... the field will be
 *   dropped". Not changed on an auction with bids or within 12 hours of its
 *   end. A string, so removed by DeletedField.
 * - Item.SecondaryCategory: "You cannot list US eBay Motors vehicles in two
 *   categories"; some categories do not take one, and eBay drops it with a
 *   warning. On a revise it cannot be deleted with DeletedField, but "can be
 *   dropped for any active listing at any time" by "a value of 0 in the
 *   SecondaryCategory.CategoryID field"; on a relist, "To remove this value
 *   when relisting an item, use DeletedField." A fee: an insertion fee for
 *   the second category, and the listing's upgrade fees again.
 * - Item.BuyItNowPrice: "only applicable for auction listings", ignored on
 *   any other, and not in AddFixedPriceItem's schema; "at least 30 percent
 *   higher than the starting bid price" (eBay US; eBay UK and Germany say 40%
 *   on their own pages, and eBay's error leaves the figure to the site). Not
 *   revised once there are bids or within 12 hours of the end.
 * - Item.ReservePrice: "The StartPrice value must be lower than the
 *   ReservePrice value." A fee, charged whether or not the item sells. A
 *   relist's must be "less than or equal to the reserve price set in the
 *   initial listing" (error 115).
 * - Errors 190 and 591: "Buy It Now price must be greater than your
 *   starting price and your reserve price."
 * - An omitted field is kept on a revise or relist ("By default, all the
 *   original item features are rolled over"); DeletedField sits beside the
 *   Item, not in it, and "Some optional fields cannot be deleted if the item
 *   has bids and/or ends within 12 hours."
 * - Item.ItemSpecifics: "If this container is included when revising or
 *   relisting an item, it will do a complete replace". Brand and MPN go in
 *   it, under the category's own aspect names (eBay Motors names MPN
 *   "Manufacturer Part Number").
 *
 * So:
 *
 * - A new listing, Verify and Check with eBay send every filled field; a
 *   relist (Bulk Relist, Auto-Relist, a push's relist of an imported
 *   auction) every filled one but Brand and MPN, which it keeps as the
 *   listing had them - a relist sends no item specifics, and two alone would
 *   replace all of eBay's.
 * - An Update sends each filled field, but none to a live auction (the
 *   3.28.8 rule for its opening bid, extended: eBay refuses most changes
 *   once an auction has a bid or is within 12 hours of its end, and the
 *   plugin cannot see bids) - Brand and MPN neither, its item specifics
 *   going exactly as 3.37.0 sent them (fix round 2) - and Buy It Now and
 *   reserve never: they are an auction's alone, and a revise of one is a
 *   live auction's.
 * - Brand and MPN replace an item specific of the same name (case and
 *   punctuation aside, MPN's other name too): the field is the shop's
 *   choice for eBay. An Update sends them only with the item specifics
 *   3.37.0 would have sent it (fix round 1), pushed listing or imported:
 *   never as a partial set, which would wipe the listing's others on eBay.
 * - The condition note is not sent where eBay ignores it: a New condition,
 *   or one with condition descriptors.
 * - Emptying a field takes off at the next Update, or relist, only a value
 *   this plugin sent (recorded per product and listing, SENT_META): the
 *   subtitle and condition note by DeletedField, the second category by
 *   CategoryID 0 on an Update and DeletedField on a relist. Buy It Now and
 *   reserve are left on the listing: eBay documents no way to remove them on
 *   a relist, and an Update never reaches them. A value eBay had of its own
 *   is never taken off, nor one eBay's answer says it dropped (fix round 1).
 * - A Buy It Now not above the opening bid and the reserve, or a reserve
 *   not above the opening bid, is refused before eBay for a new listing or
 *   Verify, as eBay's documentation is plain on them. A relist leaves out
 *   (and says) one of those, a reserve above the ended listing's or added to
 *   one that had none, and a Buy It Now not above the reserve it carries,
 *   or (fix round 2) under its site's minimum share above eBay's opening
 *   bid: the relist still goes. A new listing's minimum share is eBay's to
 *   judge: its own refusal is shown, Verify's too.
 * - A relist eBay refuses while it carries any of them - not as an ended
 *   listing, nor with no answer - goes once more in the same run without
 *   them, as 3.37.0 sent it, and later relists carrying the same leave
 *   them out until one changes (fix round 2, RELIST_MEMO): Auto-Relist is
 *   not stopped by a value eBay refuses on the relist itself. Fix round 3:
 *   for a week only when the relist without them was not taken either,
 *   as the refusal may have had nothing to do with them; and a change on
 *   the eBay tab, an Unlink or a Duplicate forgets it.
 * - Fix round 3, the owner's choice: a shop setting, off by default
 *   (WOO_BRAND_SETTING), sends a product's WooCommerce brand as eBay's
 *   Brand where its Brand field is empty and its item specifics carry no
 *   Brand already, by the Brand field's own rules (fills_brand()).
 *
 * Calls this adds: none, but for a relist eBay refuses while it carries
 * them, which is sent once more, through the same API path and its budget
 * guards, once per product per set of values (fix round 2; fix round 3:
 * again a week later while the relist without them is refused too). Brand
 * and MPN take their names from the category's item specifics, which the
 * push reads already (cached), once a build. A WooCommerce brand is read
 * from WordPress.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Listing_Options class.
 */
class TCGiant_Sync_Listing_Options {

	/** The subtitle, as the shop typed it. */
	const SUBTITLE_META = '_ebay_export_subtitle';

	/** The condition note (Item.ConditionDescription). */
	const CONDITION_NOTE_META = '_ebay_export_condition_note';

	/** The second category's eBay ID. */
	const SECOND_CATEGORY_META = '_ebay_export_second_category';

	/** Its name, as the category browser gave it, for the tab alone. */
	const SECOND_CATEGORY_NAME_META = '_ebay_export_second_category_name';

	/** An auction's Buy It Now price. */
	const BUY_IT_NOW_META = '_ebay_export_buy_it_now';

	/** An auction's reserve price. */
	const RESERVE_META = '_ebay_export_reserve';

	/** Brand, sent as an item specific. */
	const BRAND_META = '_ebay_export_brand';

	/** MPN, sent as an item specific. */
	const MPN_META = '_ebay_export_mpn';

	/**
	 * What a push or relist eBay took sent of the item's own fields, by the
	 * listing it went to: 'item' => the eBay item ID, and each field sent
	 * (subtitle, condition_note, second_category, buy_it_now, reserve) =>
	 * its value. Only a value recorded here, for the listing the product
	 * follows, is ever taken off eBay when its field is emptied.
	 *
	 * Fix round 4 (review 4's M-1, the owner's choice): and 'specifics' =>
	 * the Brand and MPN item specifics the product's own fields (or its
	 * WooCommerce brand) put in what the listing was sent, by the name sent
	 * => the value sent. Written when eBay takes a new listing, or an Update
	 * whose ItemSpecifics went (one that went without them clears them);
	 * left as it is by an Update that sent no ItemSpecifics, and by a live
	 * auction's; carried to the new item of a relist the plugin makes, as
	 * eBay rolls the item specifics over; never from Verify. A live
	 * auction's Update sends them again (resent()), so eBay sees its own
	 * values. Never taken off by DeletedField: they are not a take-off field.
	 */
	const SENT_META = '_ebay_export_options_sent';

	/** eBay's longest subtitle. */
	const SUBTITLE_MAX = 55;

	/** eBay's longest condition note. */
	const CONDITION_NOTE_MAX = 1000;

	/** eBay's longest item specific value (TCGiant_Sync_Exporter::MAX_SPECIFIC_VALUE). */
	const SPECIFIC_MAX = 65;

	/** The item's own fields, in the order a request carries them, by their element. */
	const ITEM_FIELDS = array(
		'subtitle'        => 'SubTitle',
		'condition_note'  => 'ConditionDescription',
		'second_category' => 'SecondaryCategory',
		'buy_it_now'      => 'BuyItNowPrice',
		'reserve'         => 'ReservePrice',
	);

	/** The fields an auction alone takes. */
	const AUCTION_FIELDS = array( 'buy_it_now', 'reserve' );

	/** The item specifics, by field: the name each goes as where the category does not name it. */
	const SPECIFIC_FIELDS = array(
		'brand' => 'Brand',
		'mpn'   => 'MPN',
	);

	/**
	 * Each item specific field's names, as normalize_aspect_name() compares
	 * them - letters and digits, lower case (fix round 1): eBay Motors and
	 * some other categories name MPN "Manufacturer Part Number". Brand needs
	 * none: every category read for this release names it "Brand".
	 */
	const SPECIFIC_ALIASES = array(
		'brand' => array( 'brand' ),
		'mpn'   => array( 'mpn', 'manufacturerpartnumber' ),
	);

	/**
	 * A fixed-price product's Buy It Now and reserve, as last said "not
	 * sent" (fix round 1): said once until the value or the format changes.
	 */
	const SAID_META = '_ebay_export_options_said';

	/**
	 * eBay's warnings that it dropped a field it was sent and took the
	 * listing anyway, by code: the field is not recorded as sent (fix round 1).
	 */
	const DROPPED_WARNINGS = array(
		'12317'    => 'subtitle',        // "Listing Enhancement <SubTitle> is not supported for this category and has been removed".
		'21920381' => 'condition_note',  // "Condition description field is not valid for category ... ignored."
		'21917152' => 'second_category',
		'21916361' => 'second_category',
	);

	/**
	 * eBay's refusals of an Update taking a field off, by code (fix round 1):
	 * 10031 "Second Category can not be removed from the item", and 10039,
	 * a subtitle that "cannot be changed".
	 */
	const REFUSED_TAKE_OFF = array(
		'10031' => 'second_category',
		'10039' => 'subtitle',
	);

	/**
	 * How far above its opening bid a relist's Buy It Now must be, in per
	 * cent, by the listing's eBay site as eBay's answer names it (Item.Site;
	 * fix round 2). eBay's own pages: eBay US 30 "for most categories"
	 * (eBay Motors is eBay US's), eBay Australia 30; eBay Canada, UK and
	 * Germany 40. Any other site, and one not known, is held to
	 * BUY_IT_NOW_MINIMUM_OTHER, so nothing under a site's own minimum goes.
	 */
	const BUY_IT_NOW_MINIMUM = array(
		'US'         => 30,
		'eBayMotors' => 30,
		'Australia'  => 30,
	);

	/** Every other site's minimum, and a site not known's (fix round 2). */
	const BUY_IT_NOW_MINIMUM_OTHER = 40;

	/**
	 * A relist eBay refused while it carried listing options (fix round 2):
	 * "<relist_state()>[,<relist_state()>]><when>><lasting>". A relist
	 * carrying the same options sends none of them - it goes as 3.37.0 sent
	 * it (relist_held()) - so a refusal costs one extra call per product per
	 * set of values.
	 *
	 * Fix round 3 (review 3's F-1 to F-3):
	 * - Written before the second try as expiring ('0'): held for
	 *   TCGiant_Sync_Exporter::RELIST_PHOTOS_MEMO_DAYS, as the photos memo
	 *   is, then tried again. When the plain retry is refused too, or a
	 *   guard stops it, the refusal may have had nothing to do with the
	 *   options, so it stays expiring.
	 * - Made lasting ('1') once the plain relist is taken: then the options
	 *   were what eBay refused, and they are held until one of them changes
	 *   (relist_taken_plain()). With the elements-alone set too when the
	 *   refused relist also took a field off, as the next relist carries no
	 *   DeletedField: its record names the old listing.
	 * - Forgotten when the shop changes any of the item's fields on the eBay
	 *   tab, emptied included (save_posted()), and by Unlink and a
	 *   WooCommerce Duplicate (TCGiant_Sync_Listing_Link::
	 *   listing_identity_meta_keys()): a relist of another listing is judged
	 *   afresh.
	 *
	 * Kept on uninstall, as the other _ebay_export_ keys are.
	 */
	const RELIST_MEMO = '_ebay_export_options_relist_refused';

	/**
	 * The shop's setting, off unless it is '1' (fix round 3, the owner's
	 * choice): each product's WooCommerce brand as eBay's Brand, where its
	 * Brand field is empty and its item specifics carry no Brand already
	 * (fills_brand()). A key of the plugin's settings, the push form's.
	 */
	const WOO_BRAND_SETTING = 'export_woo_brand';

	/** WooCommerce Brands' taxonomy (core from WooCommerce 9.6, or its Brands extension before that). */
	const BRAND_TAXONOMY = 'product_brand';

	/** Each field's meta key. */
	const METAS = array(
		'subtitle'        => self::SUBTITLE_META,
		'condition_note'  => self::CONDITION_NOTE_META,
		'second_category' => self::SECOND_CATEGORY_META,
		'buy_it_now'      => self::BUY_IT_NOW_META,
		'reserve'         => self::RESERVE_META,
		'brand'           => self::BRAND_META,
		'mpn'             => self::MPN_META,
	);

	// -------------------------------------------------------------------------
	// What a product has
	// -------------------------------------------------------------------------

	/**
	 * A product's fields, each as it would be sent: trimmed, cut to eBay's
	 * length, a price as eBay takes one, a category its digits. '' for an
	 * empty field, or one holding nothing eBay could take.
	 *
	 * @param WC_Product|int $product The product (a variable product's own, never a variation's).
	 * @return array<string,string> Field => value, every field.
	 */
	public static function values( $product ) {
		$id  = is_object( $product ) ? (int) $product->get_id() : (int) $product;
		$out = array();
		foreach ( self::METAS as $field => $meta ) {
			$out[ $field ] = self::clean( $field, get_post_meta( $id, $meta, true ) );
		}
		return $out;
	}

	/**
	 * A field's value as it would be sent.
	 *
	 * Text is cut by the characters eBay reads (fix round 1): entities
	 * decoded first - sanitize_text_field() keeps a "<" as "&lt;", and a cut
	 * through it sent "&amp;l" - and escaped again only as the request is
	 * made (esc_xml()).
	 *
	 * @param string $field The field.
	 * @param mixed  $raw   What it holds.
	 * @return string
	 */
	public static function clean( $field, $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$text = (string) $raw;
		if ( ! in_array( $field, array( 'second_category', 'buy_it_now', 'reserve' ), true ) ) {
			$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( 'condition_note' === $field ) {
			// A note keeps its lines.
			$value = trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
		}
		if ( '' === $value ) {
			return '';
		}
		switch ( $field ) {
			case 'subtitle':
				return self::cut( $value, self::SUBTITLE_MAX );
			case 'condition_note':
				return self::cut( $value, self::CONDITION_NOTE_MAX );
			case 'second_category':
				// An eBay category ID: digits, never 0 (which a revise takes
				// as "no second category").
				return 1 === preg_match( '/^\d{1,10}$/', $value ) ? ltrim( $value, '0' ) : '';
			case 'buy_it_now':
			case 'reserve':
				$number = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value ) : $value;
				if ( ! is_numeric( $number ) || (float) $number <= 0 ) {
					return '';
				}
				return TCGiant_Sync_Ebay_Price::money( TCGiant_Sync_Ebay_Price::cents( $number ) );
			default:
				return self::cut( $value, self::SPECIFIC_MAX );
		}
	}

	/**
	 * Text cut to a length in characters.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Characters.
	 * @return string
	 */
	private static function cut( $text, $limit ) {
		return rtrim( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit ) );
	}

	/**
	 * Whether a product has any field filled.
	 *
	 * @param WC_Product|int $product The product.
	 * @return bool
	 */
	public static function any( $product ) {
		return '' !== implode( '', self::values( $product ) );
	}

	/**
	 * Whether a product has Brand or MPN filled, or (fix round 3) a
	 * WooCommerce brand the shop sends as eBay's Brand: whether its builds
	 * go the way of the two fields. One whose item specifics carry a Brand
	 * already sends them as before (fills_brand()).
	 *
	 * @param WC_Product|int $product The product.
	 * @return bool
	 */
	public static function has_specifics( $product ) {
		$values = self::values( $product );
		return '' !== $values['brand'] . $values['mpn'] || '' !== self::woo_brand( $product );
	}

	// -------------------------------------------------------------------------
	// The product's WooCommerce brand as eBay's Brand (fix round 3)
	// -------------------------------------------------------------------------

	/**
	 * Whether the shop sends its products' WooCommerce brands as eBay's
	 * Brand: only when the setting is '1'. Off by default.
	 *
	 * @return bool
	 */
	public static function woo_brand_on() {
		$settings = class_exists( 'TCGiant_Sync_OAuth' ) ? TCGiant_Sync_OAuth::instance()->get_settings() : array();
		return is_array( $settings ) && '1' === (string) ( $settings[ self::WOO_BRAND_SETTING ] ?? '' );
	}

	/**
	 * Whether WooCommerce Brands is on this site: without its taxonomy, the
	 * setting does nothing.
	 *
	 * @return bool
	 */
	public static function woo_brands_here() {
		return function_exists( 'taxonomy_exists' ) && taxonomy_exists( self::BRAND_TAXONOMY );
	}

	/**
	 * A product's WooCommerce brand, as the Brand field would send it: one
	 * brand, its name; brands that are one line - each the parent, or a
	 * parent's parent, of the next, as "Wizards" and "Wizards > Pokemon" -
	 * the deepest; several that are not, none, and their names. Its name
	 * decoded, cut to 65 characters and cleaned as the field is (clean()).
	 * Nothing when the setting is off or WooCommerce Brands is not on the
	 * site. A variable product's own, never a variation's. Read here, from
	 * WordPress: no call to eBay.
	 *
	 * @param WC_Product|int $product The product.
	 * @return array{name: string, several: string[]}
	 */
	public static function woo_brand_read( $product ) {
		$read = array( 'name' => '', 'several' => array() );
		if ( ! self::woo_brand_on() || ! self::woo_brands_here() ) {
			return $read;
		}
		$terms = get_the_terms( is_object( $product ) ? (int) $product->get_id() : (int) $product, self::BRAND_TAXONOMY );
		$on    = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$row = self::term_row( $term );
			if ( (int) ( $row['term_id'] ?? 0 ) > 0 ) {
				$on[ (int) $row['term_id'] ] = $row;
			}
		}
		foreach ( $on as $term_id => $term ) {
			// The deepest: every other brand on the product is above it.
			if ( ! array_diff( array_keys( $on ), array_merge( array( $term_id ), self::brand_ancestors( $term, $on ) ) ) ) {
				$read['name'] = self::clean( 'brand', (string) ( $term['name'] ?? '' ) );
				return $read;
			}
		}
		foreach ( $on as $term ) {
			if ( '' !== self::clean( 'brand', (string) ( $term['name'] ?? '' ) ) ) {
				$read['several'][] = self::clean( 'brand', (string) ( $term['name'] ?? '' ) );
			}
		}
		return $read;
	}

	/**
	 * A term's fields, as WordPress gives them on a WP_Term: none for
	 * anything else (a WP_Error, null).
	 *
	 * @param mixed $term A term.
	 * @return array
	 */
	private static function term_row( $term ) {
		return is_object( $term ) ? get_object_vars( $term ) : array();
	}

	/**
	 * The brands above one, by its parent and its parent's, as far as they
	 * go: the product's own, or WordPress's term where a level between them
	 * is not on the product.
	 *
	 * @param array $term A brand's fields.
	 * @param array $on   The product's brands, by term ID.
	 * @return int[]
	 */
	private static function brand_ancestors( array $term, array $on ) {
		$above  = array();
		$parent = (int) ( $term['parent'] ?? 0 );
		while ( $parent > 0 && ! isset( $above[ $parent ] ) && count( $above ) < 100 ) {
			$above[ $parent ] = true;
			$next             = $on[ $parent ] ?? self::term_row( get_term( $parent, self::BRAND_TAXONOMY ) );
			$parent           = (int) ( $next['parent'] ?? 0 );
		}
		return array_keys( $above );
	}

	/**
	 * A product's WooCommerce brand as eBay's Brand, or ''.
	 *
	 * @param WC_Product|int $product The product.
	 * @return string
	 */
	public static function woo_brand( $product ) {
		return self::woo_brand_read( $product )['name'];
	}

	/**
	 * The WooCommerce brand a build's item specifics take as eBay's Brand,
	 * filling in only (fix round 3): the Brand field first; then a Brand the
	 * item specifics carry already - an attribute, a typed item specific, a
	 * derived one, by the field's own name comparison, aliases included;
	 * then the WooCommerce brand. '' for none to add.
	 *
	 * @param array<string,string> $specifics The item specifics without the fields.
	 * @param WC_Product|int       $product   The product.
	 * @return string
	 */
	public static function fills_brand( array $specifics, $product ) {
		if ( '' !== self::values( $product )['brand'] ) {
			return '';
		}
		$brand = self::woo_brand( $product );
		if ( '' === $brand ) {
			return '';
		}
		// Fix round 4 (review 4's L-2): a variable product whose variations
		// differ by Brand carries it in each variation already.
		if ( self::on_axis( 'brand', $product ) ) {
			return '';
		}
		foreach ( $specifics as $name => $value ) {
			if ( self::names_field( 'brand', (string) $name ) && '' !== trim( (string) $value ) ) {
				return '';
			}
		}
		return $brand;
	}

	/**
	 * The names of a variable product's variation attributes, as its
	 * VariationSpecificsSet names them (fix round 4): none for any other
	 * product.
	 *
	 * @param WC_Product|int $product The product.
	 * @return string[]
	 */
	private static function variation_axes( $product ) {
		if ( ! is_object( $product ) || ! $product->is_type( 'variable' ) ) {
			return array();
		}
		$axes = array();
		foreach ( (array) $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->get_variation() ) {
				$axes[] = (string) ( function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $attribute->get_name(), $product ) : $attribute->get_name() );
			}
		}
		return $axes;
	}

	/**
	 * Whether a field's item specific is one a variable product's variations
	 * differ by (fix round 4, review 4's L-2): eBay takes it in each
	 * variation, and refuses a name both there and among the item's own
	 * item specifics, so the field goes in neither.
	 *
	 * @param string         $field   brand or mpn.
	 * @param WC_Product|int $product The product.
	 * @return bool
	 */
	public static function on_axis( $field, $product ) {
		foreach ( self::variation_axes( $product ) as $axis ) {
			if ( self::names_field( $field, $axis ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What a build's item specifics take of the product's own Brand and MPN
	 * (fix round 4, from with_specifics()): each filled field - an empty
	 * Brand field taking the WooCommerce brand where it fills in
	 * (fills_brand()) - but not one a variable product's variations differ
	 * by (on_axis()). Field => value.
	 *
	 * @param array<string,string> $specifics The item specifics without the fields.
	 * @param WC_Product|int       $product   The product.
	 * @return array<string,string>
	 */
	public static function adds( array $specifics, $product ) {
		$values = self::values( $product );
		if ( '' === $values['brand'] ) {
			$values['brand'] = self::fills_brand( $specifics, $product );
		}
		$out = array();
		foreach ( array_keys( self::SPECIFIC_FIELDS ) as $field ) {
			if ( '' !== $values[ $field ] && ! self::on_axis( $field, $product ) ) {
				$out[ $field ] = $values[ $field ];
			}
		}
		return $out;
	}

	/**
	 * The fields a variable product's variations differ by that would
	 * otherwise have gone among its item specifics (fix round 4): each
	 * field => 'field', or 'woo' for its WooCommerce brand. For the log.
	 *
	 * @param WC_Product|int $product The product.
	 * @return array<string,string>
	 */
	public static function axis_held( $product ) {
		$values = self::values( $product );
		$held   = array();
		foreach ( array_keys( self::SPECIFIC_FIELDS ) as $field ) {
			if ( self::on_axis( $field, $product ) ) {
				if ( '' !== $values[ $field ] ) {
					$held[ $field ] = 'field';
				} elseif ( 'brand' === $field && '' !== self::woo_brand( $product ) ) {
					$held[ $field ] = 'woo';
				}
			}
		}
		return $held;
	}

	/**
	 * The log line of a variable product whose variations differ by Brand or
	 * MPN, where the field (or its WooCommerce brand) would have gone among
	 * its item specifics (fix round 4).
	 *
	 * @param string               $source     Push, Verify ...
	 * @param int                  $product_id The product.
	 * @param array<string,string> $held       axis_held()'s.
	 * @return string
	 */
	public static function axis_said( $source, $product_id, array $held ) {
		$said = array();
		foreach ( $held as $field => $from ) {
			$name   = self::SPECIFIC_FIELDS[ $field ];
			$said[] = sprintf(
				'%1$s is one of its variations\' options, which eBay takes in each variation and refuses among the item\'s own item specifics too, so %2$s is not sent as one',
				$name,
				'woo' === $from ? 'its WooCommerce brand' : 'its ' . $name . ' field'
			);
		}
		return sprintf( '%1$s: WC #%2$d - %3$s.', $source, (int) $product_id, implode( '; ', $said ) );
	}

	/**
	 * The log line of a product with several WooCommerce brands that are not
	 * one line, where one would have gone as eBay's Brand.
	 *
	 * @param string   $source     Push, Verify ...
	 * @param int      $product_id The product.
	 * @param string[] $names      Its brands.
	 * @return string
	 */
	public static function woo_brands_said( $source, $product_id, array $names ) {
		return sprintf( '%1$s: WC #%2$d - its WooCommerce brands (%3$s) are not one brand, nor one brand and those above it, so none was sent as eBay\'s Brand. Keep one brand on the product, or type the one to send in the Brand field on its eBay tab.', $source, (int) $product_id, implode( ', ', $names ) );
	}

	/**
	 * What a push or relist eBay took recorded of the product's fields, for
	 * the listing it follows: empty for another listing's record, or none.
	 *
	 * @param int    $product_id The product.
	 * @param string $item_id    The listing it follows.
	 * @return array<string,string> Field => value sent.
	 */
	public static function sent( $product_id, $item_id ) {
		$record = get_post_meta( (int) $product_id, self::SENT_META, true );
		if ( ! is_array( $record ) || '' === (string) $item_id || (string) ( $record['item'] ?? '' ) !== (string) $item_id ) {
			return array();
		}
		return array_intersect_key( array_map( 'strval', array_filter( $record, 'is_scalar' ) ), self::ITEM_FIELDS );
	}

	// -------------------------------------------------------------------------
	// What a push sends
	// -------------------------------------------------------------------------

	/**
	 * Brand and MPN in the item specifics (3.38.0): each filled field in
	 * place of any item specific of the same name - letters and digits
	 * compared, case aside, as the push compares a category's aspect names,
	 * MPN's other name too (SPECIFIC_ALIASES, fix round 1) - and first, so
	 * eBay's cut to thirty never takes them. Each is named as the category's
	 * aspect for it is (eBay Motors' "Manufacturer Part Number"), else
	 * "Brand" and "MPN"; the push then names every item specific as the
	 * category's aspects do (TCGiant_Sync_Exporter::
	 * canonicalize_specific_names()). The specifics as they were for a
	 * product with neither filled. Fix round 3: an empty Brand field takes
	 * the product's WooCommerce brand when the shop sends them, unless the
	 * specifics carry a Brand already (fills_brand()), and it goes as the
	 * field would.
	 *
	 * @param array<string,string> $specifics The item specifics, name => value.
	 * @param WC_Product|int       $product   The product.
	 * @param array                $aspects   The category's aspects, each with
	 *                                        its 'name', as the push read them;
	 *                                        none when not known.
	 * @return array<string,string>
	 */
	public static function with_specifics( array $specifics, $product, array $aspects = array() ) {
		// Fix round 4: not a field the product's variations differ by (adds()).
		$values = self::adds( $specifics, $product );
		$first  = array();
		foreach ( self::SPECIFIC_FIELDS as $field => $name ) {
			if ( '' === ( $values[ $field ] ?? '' ) ) {
				continue;
			}
			foreach ( array_keys( $specifics ) as $other ) {
				if ( self::names_field( $field, (string) $other ) ) {
					unset( $specifics[ $other ] );
				}
			}
			$first[ self::aspect_name( $field, $aspects ) ] = $values[ $field ];
		}
		return $first ? $first + $specifics : $specifics;
	}

	/**
	 * The Brand and MPN item specifics recorded as sent with the listing the
	 * product follows (fix round 4, SENT_META's 'specifics'): the name sent
	 * => the value sent. None for another listing's record, or none.
	 *
	 * @param int    $product_id The product.
	 * @param string $item_id    The listing it follows.
	 * @return array<string,string>
	 */
	public static function listed( $product_id, $item_id ) {
		$record = get_post_meta( (int) $product_id, self::SENT_META, true );
		if ( ! is_array( $record ) || '' === (string) $item_id || (string) ( $record['item'] ?? '' ) !== (string) $item_id || empty( $record['specifics'] ) || ! is_array( $record['specifics'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $record['specifics'] as $name => $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) && ( self::names_field( 'brand', (string) $name ) || self::names_field( 'mpn', (string) $name ) ) ) {
				$out[ (string) $name ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * The Brand and MPN item specifics a request sends of the product's own
	 * fields (fix round 4), for the record: each pair sent whose name is one
	 * of the fields adds() put in, by the name and value it went as.
	 *
	 * @param array[]              $pairs The item specifics sent, each array( name, value ).
	 * @param array<string,string> $adds  adds()'s.
	 * @return array<string,string>
	 */
	public static function listed_in( array $pairs, array $adds ) {
		$out = array();
		foreach ( $pairs as $pair ) {
			foreach ( array_keys( $adds ) as $field ) {
				if ( self::names_field( $field, (string) $pair[0] ) ) {
					$out[ (string) $pair[0] ] = (string) $pair[1];
				}
			}
		}
		return $out;
	}

	/**
	 * A live auction's Update's item specifics (fix round 4, review 4's M-1,
	 * the owner's choice): the Brand and MPN the listing was listed with, as
	 * recorded, in place of any item specific of the same name, first - so
	 * eBay sees its own values again, and the Update neither takes them off
	 * nor is refused for a change. A value typed since, or a WooCommerce
	 * brand that differs, waits for a new listing. A set that carries each
	 * already, by name and value, is left as it is; with nothing recorded,
	 * the set as it was.
	 *
	 * @param array<string,string> $specifics The Update's item specifics, without the fields.
	 * @param array<string,string> $listed    listed()'s.
	 * @return array<string,string>
	 */
	public static function resent( array $specifics, array $listed ) {
		$carried = 0;
		foreach ( $listed as $name => $value ) {
			foreach ( $specifics as $other => $now ) {
				if ( self::same_name( (string) $name, (string) $other ) && (string) $now === (string) $value ) {
					$carried++;
					break;
				}
			}
		}
		if ( count( $listed ) === $carried ) {
			return $specifics;
		}
		foreach ( array_keys( $listed ) as $name ) {
			$field = self::names_field( 'brand', (string) $name ) ? 'brand' : 'mpn';
			foreach ( array_keys( $specifics ) as $other ) {
				if ( self::names_field( $field, (string) $other ) ) {
					unset( $specifics[ $other ] );
				}
			}
		}
		return $listed + $specifics;
	}

	/**
	 * The recorded Brand and MPN, by field (fix round 4): field => value.
	 *
	 * @param array<string,string> $listed listed()'s.
	 * @return array<string,string>
	 */
	public static function listed_by_field( array $listed ) {
		$out = array();
		foreach ( $listed as $name => $value ) {
			$out[ self::names_field( 'brand', (string) $name ) ? 'brand' : 'mpn' ] = (string) $value;
		}
		return $out;
	}

	/**
	 * The recorded Brand and MPN as the log names them (fix round 4):
	 * "Brand Acme, MPN ZT-100".
	 *
	 * @param array<string,string> $listed listed()'s.
	 * @return string
	 */
	public static function listed_words( array $listed ) {
		$out = array();
		foreach ( $listed as $name => $value ) {
			$out[] = $name . ' ' . $value;
		}
		return implode( ', ', $out );
	}

	/**
	 * The name a field goes as: the category's first aspect that is one of
	 * its names, else its own.
	 *
	 * @param string $field   brand or mpn.
	 * @param array  $aspects The category's aspects.
	 * @return string
	 */
	private static function aspect_name( $field, array $aspects ) {
		foreach ( $aspects as $aspect ) {
			$name = is_array( $aspect ) ? (string) ( $aspect['name'] ?? '' ) : '';
			if ( self::names_field( $field, $name ) ) {
				return $name;
			}
		}
		return self::SPECIFIC_FIELDS[ $field ];
	}

	/**
	 * A name by its letters and digits, lower case: as the push compares
	 * aspect names (TCGiant_Sync_Exporter::normalize_aspect_name()).
	 *
	 * @param string $name A name.
	 * @return string
	 */
	private static function plain( $name ) {
		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $name ) );
	}

	/**
	 * Whether an item specific's name is one of a field's names.
	 *
	 * @param string $field brand or mpn.
	 * @param string $name  An item specific's name.
	 * @return bool
	 */
	public static function names_field( $field, $name ) {
		$plain = self::plain( $name );
		return '' !== $plain && in_array( $plain, self::SPECIFIC_ALIASES[ $field ] ?? array(), true );
	}

	/**
	 * Whether two item specific names are the same name to eBay's eye: by
	 * their letters and digits, case aside.
	 *
	 * @param string $a A name.
	 * @param string $b Another.
	 * @return bool
	 */
	public static function same_name( $a, $b ) {
		return '' !== self::plain( $a ) && self::plain( $a ) === self::plain( $b );
	}

	/**
	 * Whether a build may carry Brand and MPN (fix round 1): a new listing
	 * and Verify always; an Update, pushed or imported, only when the item
	 * specifics 3.37.0 would have sent it are not empty, so the two ride
	 * along a whole set that goes anyway. ItemSpecifics in a revise replaces
	 * the listing's whole set: the two alone would take off any others it
	 * has on eBay - eBay's own on an imported listing, and those eBay's
	 * catalogue or Seller Hub added to a listing pushed with none.
	 *
	 * @param bool  $revise  Whether the item goes to a Revise call.
	 * @param array $without The values of the item specifics the build sends
	 *                       without the two, as eBay is sent them.
	 * @return bool
	 */
	public static function specifics_go( $revise, array $without ) {
		if ( ! $revise ) {
			return true;
		}
		foreach ( $without as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What one build sends of the item's own fields (3.38.0).
	 *
	 * - 'new', a new listing, Verify or Check with eBay: each filled field.
	 * - 'revise', an Update: each filled field, none to a live auction, and
	 *   never Buy It Now or reserve; an emptied field that this plugin sent
	 *   to the listing is taken off - the subtitle and condition note by
	 *   DeletedField, the second category by CategoryID 0.
	 * - 'relist', RelistItem or RelistFixedPriceItem: each filled field, and
	 *   an emptied one this plugin sent taken off by DeletedField (the
	 *   subtitle, condition note and second category).
	 *
	 * Buy It Now and reserve go only with an auction, by eBay's price rules
	 * (auction_prices()). The condition note not for a New condition or one
	 * with descriptors; the second category not as the primary, nor for an
	 * eBay Motors vehicle.
	 *
	 * @param WC_Product $product The product.
	 * @param array      $context mode ('new', 'revise' or 'relist'); auction
	 *                            (bool, the item's format); condition_id (the
	 *                            ConditionID sent, or the listing's, '' when
	 *                            not known); descriptors (bool, sent or the
	 *                            listing's); primary (the primary category);
	 *                            vehicle (bool, an eBay Motors vehicle);
	 *                            start_price (an auction's opening bid, ''
	 *                            when not known); listing_reserve (a relist's:
	 *                            the ended listing's ReservePrice as eBay's
	 *                            GetItem answer gave it, '' or 0 for none);
	 *                            listing_bin (a relist's: the ended listing's
	 *                            BuyItNowPrice, likewise; fix round 2); site
	 *                            (a relist's: the listing's Item.Site; fix
	 *                            round 2); item_id (the listing the product
	 *                            follows, '' for none).
	 * @return array{xml: string, deleted: string[], off: string[], state: array|null, refused: string, notes: string[]}
	 *         xml the item's elements, each on its line; deleted the
	 *         DeletedField values; off the fields it takes off, by either
	 *         way; state what the listing carries of the plugin's once eBay
	 *         takes it (null: nothing to record); refused why a new listing
	 *         may not go, or ''; notes what was left out, for the log.
	 */
	public static function plan( $product, array $context ) {
		$mode    = (string) ( $context['mode'] ?? 'new' );
		$auction = ! empty( $context['auction'] );
		$values  = self::values( $product );
		$item_id = (string) ( $context['item_id'] ?? '' );
		$before  = 'new' === $mode ? array() : self::sent( $product->get_id(), $item_id );
		$plan    = array( 'xml' => '', 'deleted' => array(), 'off' => array(), 'state' => null, 'refused' => '', 'notes' => array() );

		$own = array_intersect_key( $values, self::ITEM_FIELDS );
		if ( '' === implode( '', $own ) && ! $before ) {
			// Nothing filled and nothing of the plugin's on the listing: the
			// request as 3.37.0 sent it, and nothing recorded. A new listing
			// in place of one that carried a record writes it off.
			if ( 'new' === $mode && is_array( get_post_meta( $product->get_id(), self::SENT_META, true ) ) ) {
				$plan['state'] = array();
			}
			return $plan;
		}

		// A fixed-price product's Buy It Now or reserve is said "not sent"
		// once (notes_said()); a build as an auction forgets that, so a
		// change of format back says it again.
		if ( $auction && '' !== $own['buy_it_now'] . $own['reserve'] && '' !== (string) get_post_meta( $product->get_id(), self::SAID_META, true ) ) {
			delete_post_meta( $product->get_id(), self::SAID_META );
		}

		// A live auction's Update sends none of them, nor takes any off.
		$locked = 'revise' === $mode && $auction;
		$state  = $before;
		$xml    = array();
		$prices = $auction && 'revise' !== $mode ? self::auction_prices( $own, $mode, $context, $plan ) : array();

		foreach ( self::ITEM_FIELDS as $field => $element ) {
			$value = $own[ $field ];

			if ( in_array( $field, self::AUCTION_FIELDS, true ) ) {
				// An auction's alone; never in a revise, which is a live
				// auction's; never taken off (eBay documents no way on a
				// relist).
				if ( '' === $value || ! $auction ) {
					if ( '' !== $value ) {
						$plan['notes'][] = $field . ':fixed';
					}
					continue;
				}
				if ( 'revise' === $mode ) {
					$plan['notes'][] = $field . ':live';
					continue;
				}
				// Refused, or left out of a relist and said: auction_prices().
				if ( ! isset( $prices[ $field ] ) ) {
					continue;
				}
				$xml[]           = '<' . $element . '>' . esc_xml( $prices[ $field ] ) . '</' . $element . '>';
				$state[ $field ] = $prices[ $field ];
				continue;
			}

			if ( 'condition_note' === $field && '' !== $value ) {
				$condition = (string) ( $context['condition_id'] ?? '' );
				if ( ! empty( $context['descriptors'] ) || ( is_numeric( $condition ) && (int) $condition >= 1000 && (int) $condition < 1500 ) ) {
					// eBay ignores it there: not sent, and nothing taken off.
					$plan['notes'][] = 'condition_note:' . ( ! empty( $context['descriptors'] ) ? 'descriptors' : 'new' );
					continue;
				}
			}

			if ( 'second_category' === $field && '' !== $value ) {
				if ( ! empty( $context['vehicle'] ) ) {
					$plan['notes'][] = 'second_category:vehicle';
					continue;
				}
				if ( $value === (string) ( $context['primary'] ?? '' ) ) {
					$plan['notes'][] = 'second_category:primary';
					continue;
				}
			}

			if ( $locked ) {
				if ( '' !== $value ) {
					$plan['notes'][] = $field . ':live';
				} elseif ( isset( $before[ $field ] ) ) {
					$plan['notes'][] = $field . ':kept';
				}
				continue;
			}

			if ( '' !== $value ) {
				$xml[]           = 'second_category' === $field
					? '<SecondaryCategory><CategoryID>' . esc_xml( $value ) . '</CategoryID></SecondaryCategory>'
					: '<' . $element . '>' . esc_xml( $value ) . '</' . $element . '>';
				$state[ $field ] = $value;
				continue;
			}

			// Emptied: off the listing only when this plugin sent it there.
			if ( ! isset( $before[ $field ] ) ) {
				continue;
			}
			if ( 'second_category' === $field && 'revise' === $mode ) {
				// eBay's own way on a revise: DeletedField may not take it.
				$xml[] = '<SecondaryCategory><CategoryID>0</CategoryID></SecondaryCategory>';
			} else {
				$plan['deleted'][] = 'Item.' . $element;
			}
			$plan['off'][] = $field;
			unset( $state[ $field ] );
		}

		$plan['xml']   = $xml ? implode( "\n", $xml ) . "\n" : '';
		$plan['state'] = $state;
		if ( '' !== $plan['refused'] ) {
			// Its own sentence (fix round 1): nothing is missing.
			$plan['refused'] = sprintf(
				/* translators: %s: what eBay would refuse, e.g. "The Buy It Now price 100.00 is not above the reserve price 120.00" */
				__( '%s. eBay takes a Buy It Now price only above the opening bid and the reserve price, and a reserve price only above the opening bid: change it on the product\'s eBay tab (More listing options), or empty it, then push again. Nothing was sent to eBay.', 'tcgiant-sync' ),
				ucfirst( $plan['refused'] )
			);
		}
		return $plan;
	}

	/**
	 * An auction's Buy It Now and reserve for a new listing, Verify or a
	 * relist, by eBay's price rules (fix round 1): what goes, field => price.
	 *
	 * - A reserve must be above the opening bid (ReservePrice: "The StartPrice
	 *   value must be lower"). On a relist, no higher than the ended
	 *   listing's (error 115: "less than or equal to the reserve price set in
	 *   the initial listing"), and none added to a listing that had none:
	 *   whether eBay takes one there is not documented, so a new listing
	 *   takes it.
	 * - A Buy It Now must be above the opening bid and the reserve (errors
	 *   190 and 591): the reserve sent, or on a relist, the ended listing's,
	 *   which the relist keeps when none is sent. A new listing's is held
	 *   to the reserve typed even when that is refused (fix round 2), so one
	 *   refusal names every rule it breaks.
	 * - A relist's Buy It Now must also be at least the listing's site's
	 *   minimum above eBay's opening bid, which RelistItem rolls over (fix
	 *   round 2, BUY_IT_NOW_MINIMUM): "at least", so in cents, inclusive.
	 *   With no opening bid in eBay's answer it cannot be judged, and is
	 *   left out. A new listing and Verify keep eBay's own refusal for it.
	 *
	 * What breaks a rule refuses a new listing or Verify before eBay
	 * ($plan['refused']); a relist leaves it out and says so
	 * ($plan['notes']), with what the relist carries in its place - the
	 * ended listing's own Buy It Now, rolled over, or none - and goes:
	 * Auto-Relist is never stopped by these.
	 *
	 * @param array<string,string> $own     The fields, as sent.
	 * @param string               $mode    new or relist.
	 * @param array                $context plan()'s.
	 * @param array                $plan    plan()'s, given its refusal and notes.
	 * @return array<string,string>
	 */
	private static function auction_prices( array $own, $mode, array $context, array &$plan ) {
		$start  = (string) ( $context['start_price'] ?? '' );
		$start  = is_numeric( $start ) ? TCGiant_Sync_Ebay_Price::money( TCGiant_Sync_Ebay_Price::cents( $start ) ) : '';
		$had    = 'relist' === $mode ? self::price_of( $context['listing_reserve'] ?? '' ) : '';
		$sent   = array();
		$refuse = function ( $field, $why ) use ( $mode, &$plan ) {
			if ( 'new' === $mode ) {
				$plan['refused'] = '' !== $plan['refused'] ? $plan['refused'] . '; ' . $why : $why;
			} else {
				$plan['notes'][] = $field . ':left:' . $why;
			}
		};
		$above  = function ( $price, $floor ) {
			return TCGiant_Sync_Ebay_Price::cents( $price ) > TCGiant_Sync_Ebay_Price::cents( $floor );
		};

		// The reserve first: a Buy It Now must be above it.
		$reserve = $own['reserve'];
		if ( '' !== $reserve ) {
			if ( '' !== $start && ! $above( $reserve, $start ) ) {
				/* translators: 1: the reserve price, 2: the opening bid */
				$refuse( 'reserve', sprintf( __( 'the reserve price %1$s is not above the opening bid %2$s', 'tcgiant-sync' ), $reserve, $start ) );
			} elseif ( 'relist' === $mode && '' === $had ) {
				/* translators: %s: the reserve price */
				$refuse( 'reserve', sprintf( __( 'the ended listing had no reserve price, as eBay\'s answer gives it, and eBay does not document adding one on a relist, so %s waits for a new listing', 'tcgiant-sync' ), $reserve ) );
			} elseif ( 'relist' === $mode && $above( $reserve, $had ) ) {
				/* translators: 1: the reserve price, 2: the ended listing's */
				$refuse( 'reserve', sprintf( __( 'the reserve price %1$s is above the ended listing\'s, %2$s, and eBay takes a relist\'s reserve only at or below that; the relist keeps %2$s', 'tcgiant-sync' ), $reserve, $had ) );
			} else {
				$sent['reserve'] = $reserve;
			}
		}

		$bin = $own['buy_it_now'];
		if ( '' !== $bin ) {
			$carried = $sent['reserve'] ?? ( 'new' === $mode ? $reserve : $had );
			$why     = '';
			if ( '' !== $start && ! $above( $bin, $start ) ) {
				/* translators: 1: the Buy It Now price, 2: the opening bid */
				$why = sprintf( __( 'the Buy It Now price %1$s is not above the opening bid %2$s', 'tcgiant-sync' ), $bin, $start );
			} elseif ( '' !== $carried && ! $above( $bin, $carried ) ) {
				$why = sprintf(
					isset( $sent['reserve'] ) || 'new' === $mode
						/* translators: 1: the Buy It Now price, 2: the reserve price */
						? __( 'the Buy It Now price %1$s is not above the reserve price %2$s', 'tcgiant-sync' )
						/* translators: 1: the Buy It Now price, 2: the ended listing's reserve price */
						: __( 'the Buy It Now price %1$s is not above the ended listing\'s reserve price %2$s, which the relist keeps', 'tcgiant-sync' ),
					$bin,
					$carried
				);
			} elseif ( 'relist' === $mode && '' === $start ) {
				// Fix round 2: not judged, not sent.
				$why = __( 'eBay\'s answer for the ended listing gives no opening bid to judge it by', 'tcgiant-sync' );
			} elseif ( 'relist' === $mode && ! self::buy_it_now_clears( $bin, $start, self::buy_it_now_minimum( $context['site'] ?? '' ) ) ) {
				$site = (string) ( $context['site'] ?? '' );
				$why  = sprintf(
					/* translators: 1: the Buy It Now price, 2: the share, 3: the opening bid, 4: the listing's eBay site */
					__( 'the Buy It Now price %1$s is not %2$d%% above the opening bid %3$s, the least eBay takes on the listing\'s site (%4$s)', 'tcgiant-sync' ),
					$bin,
					self::buy_it_now_minimum( $site ),
					$start,
					'' !== $site ? $site : __( 'not known', 'tcgiant-sync' )
				);
			}

			if ( '' === $why ) {
				$sent['buy_it_now'] = $bin;
			} elseif ( 'relist' === $mode ) {
				// What the relist carries in its place (fix round 2): the
				// ended listing's own, which RelistItem rolls over, or none.
				$kept = self::price_of( $context['listing_bin'] ?? '' );
				$refuse( 'buy_it_now', $why . '; ' . ( '' !== $kept
					/* translators: %s: the ended listing's Buy It Now price */
					? sprintf( __( 'the relist keeps the ended listing\'s Buy It Now %s', 'tcgiant-sync' ), $kept )
					: __( 'the relist goes without one', 'tcgiant-sync' ) ) );
			} else {
				$refuse( 'buy_it_now', $why );
			}
		}

		return $sent;
	}

	/**
	 * How far above the opening bid a relist's Buy It Now must be on an eBay
	 * site, in per cent (fix round 2): BUY_IT_NOW_MINIMUM's, else
	 * BUY_IT_NOW_MINIMUM_OTHER.
	 *
	 * @param mixed $site eBay's Item.Site ('US', 'eBayMotors', 'Australia', 'UK' ...).
	 * @return int
	 */
	public static function buy_it_now_minimum( $site ) {
		return self::BUY_IT_NOW_MINIMUM[ is_scalar( $site ) ? (string) $site : '' ] ?? self::BUY_IT_NOW_MINIMUM_OTHER;
	}

	/**
	 * Whether a Buy It Now is at least a share above an opening bid (fix
	 * round 2): eBay's "at least", so equal goes - in whole cents, as eBay
	 * takes a price.
	 *
	 * @param string $bin   The Buy It Now price.
	 * @param string $start The opening bid.
	 * @param int    $share Per cent.
	 * @return bool
	 */
	public static function buy_it_now_clears( $bin, $start, $share ) {
		return TCGiant_Sync_Ebay_Price::cents( $bin ) * 100 >= TCGiant_Sync_Ebay_Price::cents( $start ) * ( 100 + (int) $share );
	}

	/**
	 * A price eBay gave, as eBay takes one: '' for none, 0 or not a price.
	 *
	 * @param mixed $raw eBay's value, as TCGiant_Sync_API::scalar_value() gives it.
	 * @return string
	 */
	private static function price_of( $raw ) {
		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( ! is_numeric( $raw ) || TCGiant_Sync_Ebay_Price::cents( $raw ) <= 0 ) {
			return '';
		}
		return TCGiant_Sync_Ebay_Price::money( TCGiant_Sync_Ebay_Price::cents( $raw ) );
	}

	/**
	 * The DeletedField elements for a request, each on its line after the
	 * Item: '' for none.
	 *
	 * @param string[] $deleted plan()'s.
	 * @return string
	 */
	public static function deleted_xml( array $deleted ) {
		$xml = '';
		foreach ( $deleted as $field ) {
			$xml .= "\n<DeletedField>" . esc_xml( (string) $field ) . '</DeletedField>';
		}
		return $xml;
	}

	/**
	 * What a log line says of the fields a build left out, once: '' for
	 * nothing worth saying.
	 *
	 * A fixed-price product's Buy It Now and reserve are said once until
	 * their value or the product's format changes (fix round 1, SAID_META;
	 * plan() forgets it in a build as an auction): a bulk push said them on
	 * every push.
	 *
	 * @param int      $product_id The product.
	 * @param string   $source     Push, Verify, Bulk relist ...
	 * @param string[] $notes      plan()'s.
	 * @return string
	 */
	public static function notes_said( $product_id, $source, array $notes ) {
		$notes = array_values( array_unique( $notes ) );
		$fixed = preg_grep( '/^(buy_it_now|reserve):fixed$/', $notes );
		if ( $fixed ) {
			$values = self::values( (int) $product_id );
			$memo   = $values['buy_it_now'] . '|' . $values['reserve'];
			if ( (string) get_post_meta( (int) $product_id, self::SAID_META, true ) === $memo ) {
				$notes = array_diff( $notes, $fixed );
			} else {
				update_post_meta( (int) $product_id, self::SAID_META, $memo );
			}
		}

		$said = array();
		foreach ( $notes as $note ) {
			$parts = explode( ':', $note, 3 );
			$label = self::label( $parts[0] );
			switch ( $parts[1] ?? '' ) {
				case 'fixed':
					$said[] = sprintf( '%s is for an auction, and this listing is fixed-price: not sent', $label );
					break;
				case 'live':
					// Brand and MPN (fix round 2): a relist never takes them.
					// Nor the WooCommerce brand (fix round 3). Fix round 4: the
					// Update sends again what the listing was listed with, as
					// recorded ($parts[2]), or, with none recorded, goes
					// without them.
					if ( 'woo_brand' === $parts[0] || 'specifics' === $parts[0] ) {
						$woo    = 'woo_brand' === $parts[0];
						$said[] = sprintf(
							'%1$s to a live auction, whose item specifics eBay does not let change once it has a bid or is within 12 hours of its end: %2$s, and a new listing takes %3$s',
							$woo ? 'its WooCommerce brand is not sent as eBay\'s Brand' : 'the Brand and MPN fields are not sent',
							'' !== ( $parts[2] ?? '' )
								? sprintf( 'its Update sends again what it was listed with (%s)', $parts[2] )
								: ( $woo ? 'its item specifics go without it, as TCGiant Sync has no record of this listing being sent it (listed before 3.38.0, relisted on eBay, or linked by the import after an unfinished push)' : 'its item specifics go without them, as TCGiant Sync has no record of this listing being sent them (listed before 3.38.0, relisted on eBay, or linked by the import after an unfinished push)' ),
							$woo ? 'it' : 'the fields'
						);
						break;
					}
					$said[] = sprintf( '%s is not sent to a live auction, which eBay does not let change once it has a bid or is within 12 hours of its end: a new listing or relist takes it', $label );
					break;
				case 'kept':
					// Fix round 4: the Brand and MPN a live auction was listed
					// with, which its product no longer gives.
					$said[] = 'specifics' === $parts[0]
						? sprintf( 'its Update sends again what the live auction was listed with (%s), whose item specifics eBay does not let change once it has a bid or is within 12 hours of its end: a new listing goes by the product as it is now', $parts[2] ?? '' )
						: sprintf( '%s, emptied here, stays on the live auction: a relist takes it off', $label );
					break;
				case 'left':
					$said[] = sprintf( '%1$s not sent: %2$s', $label, $parts[2] ?? '' );
					break;
				case 'descriptors':
					$said[] = 'the condition note is not sent with condition descriptors, which eBay drops it for';
					break;
				case 'new':
					$said[] = 'the condition note is not sent with a New condition, which eBay ignores it for';
					break;
				case 'vehicle':
					$said[] = 'the second category is not sent: eBay does not list an eBay Motors vehicle in two categories';
					break;
				case 'primary':
					$said[] = 'the second category is not sent: it is the listing\'s primary category';
					break;
			}
		}
		return $said ? sprintf( '%1$s: WC #%2$d - %3$s.', $source, (int) $product_id, implode( '; ', $said ) ) : '';
	}

	/**
	 * A field's name, for the log.
	 *
	 * @param string $field The field.
	 * @return string
	 */
	private static function label( $field ) {
		$labels = array(
			'subtitle'        => 'the subtitle',
			'condition_note'  => 'the condition note',
			'second_category' => 'the second category',
			'buy_it_now'      => 'the Buy It Now price',
			'reserve'         => 'the reserve price',
		);
		return $labels[ $field ] ?? $field;
	}

	/**
	 * Record what eBay took: the state plan() gave, for the listing it went
	 * to. Null records nothing; an empty state takes the record off.
	 *
	 * @param WC_Product|int $product The product (its meta saved with it when an object).
	 * @param array|null     $state   plan()'s.
	 * @param string         $item_id The listing eBay made or kept.
	 * @return void
	 */
	public static function record( $product, $state, $item_id ) {
		if ( ! is_array( $state ) ) {
			return;
		}
		$record = array_intersect_key( $state, self::ITEM_FIELDS );
		// Fix round 4: the Brand and MPN sent, by name, when there are any.
		if ( ! empty( $state['specifics'] ) && is_array( $state['specifics'] ) ) {
			$record['specifics'] = $state['specifics'];
		}
		$record = $record ? array( 'item' => (string) $item_id ) + $record : array();
		if ( is_object( $product ) ) {
			if ( $record ) {
				$product->update_meta_data( self::SENT_META, $record );
			} else {
				$product->delete_meta_data( self::SENT_META );
			}
			return;
		}
		if ( $record ) {
			update_post_meta( (int) $product, self::SENT_META, $record );
		} else {
			delete_post_meta( (int) $product, self::SENT_META );
		}
	}

	/**
	 * A build's state with the Brand and MPN it sent (fix round 4,
	 * SENT_META's 'specifics'), for record().
	 *
	 * @param array|null                $state      plan()'s.
	 * @param array<string,string>|null $listed     What the request sends of
	 *                                              them (listed_in()); null to
	 *                                              leave the record's as it is
	 *                                              - an Update that sends no
	 *                                              ItemSpecifics, a live
	 *                                              auction's.
	 * @param int                       $product_id The product.
	 * @param string                    $item_id    The listing an Update
	 *                                              revises; '' for a new one.
	 * @return array|null
	 */
	public static function state_with( $state, $listed, $product_id, $item_id ) {
		$current = '' === (string) $item_id ? array() : self::listed( $product_id, $item_id );
		if ( null === $listed ) {
			if ( ! is_array( $state ) ) {
				return $state;
			}
			$listed = $current;
		} elseif ( ! is_array( $state ) ) {
			// plan() gives null only where the listing has none of the
			// item's own fields recorded: nothing else to keep.
			if ( $listed === $current ) {
				return $state;
			}
			$state = array();
		}
		if ( $listed ) {
			$state['specifics'] = $listed;
		} else {
			unset( $state['specifics'] );
		}
		return $state;
	}

	/**
	 * A relist's state, with the Brand and MPN recorded for the ended
	 * listing carried to the new one (fix round 4): eBay rolls the item
	 * specifics over, and RelistItem sends none.
	 *
	 * @param array|null $state      What the relist records of the other fields.
	 * @param int        $product_id The product.
	 * @param string     $item_id    The ended listing.
	 * @return array|null
	 */
	public static function carried_over( $state, $product_id, $item_id ) {
		$listed = self::listed( $product_id, $item_id );
		if ( ! $listed ) {
			return $state;
		}
		$state              = is_array( $state ) ? $state : array();
		$state['specifics'] = $listed;
		return $state;
	}

	/**
	 * The state to record once eBay took a request (fix round 1): without a
	 * field eBay's answer warns it removed or ignored (DROPPED_WARNINGS,
	 * matched by code), which the listing does not carry - an emptied field
	 * would otherwise send a DeletedField, or a CategoryID 0, for nothing.
	 *
	 * @param array|null $state  plan()'s state.
	 * @param mixed      $answer eBay's answer to the request.
	 * @return array{0: array|null, 1: array<string,string>} The state, and the fields dropped => eBay's code.
	 */
	public static function accepted( $state, $answer ) {
		$dropped = array();
		if ( ! is_array( $state ) ) {
			return array( $state, $dropped );
		}
		foreach ( self::errors_in( $answer ) as $entry ) {
			$field = self::DROPPED_WARNINGS[ $entry['code'] ] ?? '';
			if ( 'warning' === $entry['severity'] && '' !== $field && isset( $state[ $field ] ) ) {
				unset( $state[ $field ] );
				$dropped[ $field ] = $entry['code'];
			}
		}
		return array( $state, $dropped );
	}

	/**
	 * What the log says of the fields eBay took a request without: '' for
	 * none.
	 *
	 * @param int                  $product_id The product.
	 * @param string               $item_id    The listing.
	 * @param array<string,string> $dropped    accepted()'s.
	 * @param string               $source     Push, Bulk relist ...
	 * @return string
	 */
	public static function dropped_said( $product_id, $item_id, array $dropped, $source ) {
		if ( ! $dropped ) {
			return '';
		}
		$said = array();
		foreach ( $dropped as $field => $code ) {
			$said[] = sprintf( '%1$s (its warning %2$s)', self::label( $field ), $code );
		}
		return sprintf( '%1$s: eBay took listing %2$s of WC #%3$d without %4$s, which it says it removed or ignored there, so TCGiant Sync does not count it as sent.', $source, $item_id, (int) $product_id, implode( ' and ', $said ) );
	}

	/**
	 * An Update eBay refused while it was taking a recorded field off (fix
	 * round 1): with 10031 for the second category, or 10039 for the
	 * subtitle, that entry leaves the record, so the next Update does not try
	 * it again and is not refused for it every time. Nothing is sent again
	 * here; any other refusal leaves the record as it is.
	 *
	 * @param int      $product_id The product.
	 * @param string   $item_id    The listing revised.
	 * @param string[] $off        plan()'s fields taken off.
	 * @param mixed    $response   eBay's answer.
	 * @param string   $source     Push ...
	 * @return string The log line, or '' for nothing done.
	 */
	public static function refused_take_off( $product_id, $item_id, array $off, $response, $source ) {
		if ( ! $off || ! is_wp_error( $response ) ) {
			return '';
		}
		$gone = array();
		foreach ( self::errors_in( $response->get_error_data() ) as $entry ) {
			$field = self::REFUSED_TAKE_OFF[ $entry['code'] ] ?? '';
			if ( 'warning' !== $entry['severity'] && '' !== $field && in_array( $field, $off, true ) ) {
				$gone[ $field ] = $entry['code'];
			}
		}
		$record = get_post_meta( (int) $product_id, self::SENT_META, true );
		if ( ! $gone || ! is_array( $record ) || (string) ( $record['item'] ?? '' ) !== (string) $item_id ) {
			return '';
		}
		$record = array_diff_key( $record, $gone );
		// Fix round 4: the Brand and MPN sent stay with the record.
		if ( array_intersect_key( $record, self::ITEM_FIELDS ) || ! empty( $record['specifics'] ) ) {
			update_post_meta( (int) $product_id, self::SENT_META, $record );
		} else {
			delete_post_meta( (int) $product_id, self::SENT_META );
		}
		// 10039 is a sale, which lasts, or a pending Best Offer, which does
		// not (fix round 2): said how to take the subtitle off once that is
		// answered - typed in again, it is recorded as sent, and emptied, it
		// is taken off.
		return sprintf(
			'%1$s: eBay would not take %2$s off eBay listing %3$s of WC #%4$d (its error %5$s), so it stays on that listing, and TCGiant Sync no longer tries to take it off. Press Update again to send the product\'s other changes.%6$s',
			$source,
			implode( ' or ', array_map( function ( $field ) {
				return self::label( $field );
			}, array_keys( $gone ) ) ),
			$item_id,
			(int) $product_id,
			implode( ', ', $gone ),
			isset( $gone['subtitle'] ) ? ' If a Best Offer was pending, answer it, then type the subtitle in again, Update, empty it and Update.' : ''
		);
	}

	/**
	 * eBay's errors and warnings in an answer, or in a refusal's data: each
	 * its severity (lower case) and code.
	 *
	 * @param mixed $answer eBay's answer.
	 * @return array[]
	 */
	private static function errors_in( $answer ) {
		$errors = is_array( $answer ) && isset( $answer['Errors'] ) && is_array( $answer['Errors'] ) ? $answer['Errors'] : array();
		if ( isset( $errors['ErrorCode'] ) || isset( $errors['ShortMessage'] ) || isset( $errors['LongMessage'] ) || isset( $errors['SeverityCode'] ) ) {
			$errors = array( $errors );
		}
		$out = array();
		foreach ( $errors as $one ) {
			if ( is_array( $one ) ) {
				$out[] = array(
					'severity' => strtolower( TCGiant_Sync_API::scalar_value( $one['SeverityCode'] ?? '' ) ),
					'code'     => TCGiant_Sync_API::scalar_value( $one['ErrorCode'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * What a relist's log line says of the fields it sent and took off, by
	 * the eBay tab's labels (fix round 1): '' for none.
	 *
	 * @param string   $xml     plan()'s elements.
	 * @param string[] $deleted DeletedField values.
	 * @return string
	 */
	public static function relist_said( $xml, array $deleted ) {
		$by_element = array_flip( self::ITEM_FIELDS );
		$labels     = function ( array $elements ) use ( $by_element ) {
			$out = array();
			foreach ( $elements as $element ) {
				$out[] = isset( $by_element[ $element ] ) ? substr( self::label( $by_element[ $element ] ), 4 ) : $element;
			}
			return implode( ', ', $out );
		};
		$sent = preg_match_all( '#^<(\w+)>#m', (string) $xml, $m ) ? $m[1] : array();
		$off  = array_map( function ( $field ) {
			return substr( (string) $field, 5 );
		}, $deleted );
		return ( $sent ? ', with its ' . $labels( $sent ) : '' ) . ( $off ? ', taking off its ' . $labels( $off ) : '' );
	}

	/**
	 * What Verify or Check with eBay adds when eBay's answer warns it would
	 * drop one of these fields (fix round 2): DROPPED_WARNINGS, by code and a
	 * Warning severity, as accepted() reads an Add's answer. '' for none.
	 * Nothing is sent again: Verify shows it, which is what it is for.
	 *
	 * @param mixed $checked The Verify call's answer (TCGiant_Sync_API::verify_answer()'s, eBay's Errors as 'warnings').
	 * @return string
	 */
	public static function verify_said( $checked ) {
		$codes = array();
		foreach ( self::errors_in( array( 'Errors' => is_array( $checked ) ? ( $checked['warnings'] ?? array() ) : array() ) ) as $entry ) {
			$field = self::DROPPED_WARNINGS[ $entry['code'] ] ?? '';
			if ( 'warning' === $entry['severity'] && '' !== $field && ! isset( $codes[ $field ] ) ) {
				$codes[ $field ] = $entry['code'];
			}
		}
		if ( 1 === count( $codes ) ) {
			/* translators: 1: the field, e.g. "the subtitle", 2: eBay's warning code */
			return sprintf( __( 'eBay would drop %1$s here (its warning %2$s).', 'tcgiant-sync' ), self::label( (string) key( $codes ) ), (string) current( $codes ) );
		}
		$said = array();
		foreach ( $codes as $field => $code ) {
			$said[] = sprintf( '%1$s (its warning %2$s)', self::label( $field ), $code );
		}
		$last = array_pop( $said );
		/* translators: %s: the fields, each with eBay's warning code, e.g. "the subtitle (its warning 12317) and the second category (its warning 21917152)" */
		return $said ? sprintf( __( 'eBay would drop %s here.', 'tcgiant-sync' ), implode( ', ', $said ) . ' and ' . $last ) : '';
	}

	// -------------------------------------------------------------------------
	// A relist eBay refused for its listing options (fix round 2)
	// -------------------------------------------------------------------------

	/**
	 * A relist's listing options as it carries them, for RELIST_MEMO: its
	 * elements and DeletedField values, each value in them. '' for none.
	 *
	 * @param string   $xml     plan()'s elements.
	 * @param string[] $deleted DeletedField values.
	 * @return string
	 */
	public static function relist_state( $xml, array $deleted ) {
		return '' === (string) $xml && ! $deleted ? '' : md5( (string) $xml . '>' . implode( ',', $deleted ) );
	}

	/**
	 * Whether a relist carrying these options was refused before (fix round
	 * 2): it goes without them, as 3.37.0 sent it. Fix round 3: a memo the
	 * plain relist was not taken after holds them for
	 * TCGiant_Sync_Exporter::RELIST_PHOTOS_MEMO_DAYS from the refusal, one
	 * it was taken after until a value changes.
	 *
	 * @param int      $product_id The product.
	 * @param string   $xml        plan()'s elements.
	 * @param string[] $deleted    DeletedField values.
	 * @return bool
	 */
	public static function relist_held( $product_id, $xml, array $deleted ) {
		$state = self::relist_state( $xml, $deleted );
		if ( '' === $state ) {
			return false;
		}
		$memo = explode( '>', (string) get_post_meta( (int) $product_id, self::RELIST_MEMO, true ) );
		if ( ! in_array( $state, explode( ',', $memo[0] ), true ) ) {
			return false;
		}
		return '1' === ( $memo[2] ?? '' ) || (int) ( $memo[1] ?? 0 ) > time() - TCGiant_Sync_Exporter::RELIST_PHOTOS_MEMO_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Remember a relist eBay refused while it carried these options (fix
	 * round 2), before it is sent again without them: expiring (fix round
	 * 3), as the refusal may have had nothing to do with them.
	 *
	 * @param int      $product_id The product.
	 * @param string   $xml        plan()'s elements.
	 * @param string[] $deleted    DeletedField values.
	 * @return void
	 */
	public static function relist_refused( $product_id, $xml, array $deleted ) {
		// Fix round 4 (review 4's L-1): with the elements alone too when it
		// took a field off, as relist_taken_plain() holds them - the relist
		// of the new listing carries no DeletedField.
		$states = array( self::relist_state( $xml, $deleted ) );
		if ( $deleted && '' !== self::relist_state( $xml, array() ) ) {
			$states[] = self::relist_state( $xml, array() );
		}
		update_post_meta( (int) $product_id, self::RELIST_MEMO, implode( ',', $states ) . '>' . time() . '>0' );
	}

	/**
	 * The plain relist after a refusal was taken (fix round 3): the options
	 * were what eBay refused, so the memo lasts until one changes. And when
	 * the refused relist took a field off, the elements alone are held too
	 * (review 3's F-2): the next relist, of the new listing, carries no
	 * DeletedField, as the record still names the old one, and would
	 * otherwise be sent with them, refused and sent again once more.
	 *
	 * @param int      $product_id The product.
	 * @param string   $xml        The refused relist's elements.
	 * @param string[] $deleted    Its DeletedField values.
	 * @return void
	 */
	public static function relist_taken_plain( $product_id, $xml, array $deleted ) {
		$states = array( self::relist_state( $xml, $deleted ) );
		if ( $deleted && '' !== self::relist_state( $xml, array() ) ) {
			$states[] = self::relist_state( $xml, array() );
		}
		update_post_meta( (int) $product_id, self::RELIST_MEMO, implode( ',', $states ) . '>' . time() . '>1' );
	}

	/**
	 * A relist's options by the eBay tab's labels (fix round 2): "its
	 * subtitle and second category", "taking off its condition note", or
	 * both; and how many fields that is.
	 *
	 * @param string   $xml     plan()'s elements.
	 * @param string[] $deleted DeletedField values.
	 * @return array{0: string, 1: int}
	 */
	private static function carried( $xml, array $deleted ) {
		$by_element = array_flip( self::ITEM_FIELDS );
		$name       = function ( $element ) use ( $by_element ) {
			return isset( $by_element[ $element ] ) ? substr( self::label( $by_element[ $element ] ), 4 ) : $element;
		};
		$listed     = function ( array $names ) {
			$last = array_pop( $names );
			return $names ? implode( ', ', $names ) . ' and ' . $last : (string) $last;
		};
		$sent  = preg_match_all( '#^<(\w+)>#m', (string) $xml, $m ) ? array_map( $name, $m[1] ) : array();
		$off   = array_map( function ( $field ) use ( $name ) {
			return $name( substr( (string) $field, 5 ) );
		}, $deleted );
		$parts = array();
		if ( $sent ) {
			$parts[] = 'its ' . $listed( $sent );
		}
		if ( $off ) {
			$parts[] = 'taking off its ' . $listed( $off );
		}
		return array( implode( ', and ', $parts ), count( $sent ) + count( $off ) );
	}

	/**
	 * The log line of a relist eBay refused for what its listing options may
	 * be, sent once more without them (fix round 2).
	 *
	 * @param string         $source     Auto-Relist, Bulk relist, Push.
	 * @param int            $product_id The product.
	 * @param string         $xml        The options it carried.
	 * @param string[]       $deleted    And their DeletedField values.
	 * @param WP_Error       $refusal    eBay's refusal of it.
	 * @param array|WP_Error $again      The answer without them.
	 * @return string
	 */
	public static function relist_without_said( $source, $product_id, $xml, array $deleted, $refusal, $again ) {
		list( $what, $count ) = self::carried( $xml, $deleted );
		$it                   = 1 === $count ? 'it' : 'them';
		$words                = rtrim( trim( is_wp_error( $refusal ) ? $refusal->get_error_message() : '' ), '. ' );
		if ( ! is_wp_error( $again ) ) {
			return sprintf( '%1$s: WC #%2$d relisted without %3$s, which eBay refused: %4$s; fix or empty %5$s on the eBay tab. Until then, its relists go without %5$s.', $source, (int) $product_id, $what, $words, $it );
		}
		// Fix round 3: the refusal may have had nothing to do with them, so
		// they are held for a week, not until one changes.
		return sprintf(
			'%1$s: eBay refused the relist of WC #%2$d with %3$s (%4$s), and once more without %5$s: %6$s. Its relists go without %5$s for a week, or until %5$s %7$s changed on the eBay tab, then %5$s %7$s sent again.',
			$source,
			(int) $product_id,
			$what,
			$words,
			$it,
			rtrim( trim( $again->get_error_message() ), '. ' ),
			1 === $count ? 'is' : 'are'
		);
	}

	/**
	 * The log line of a relist that goes without its listing options, as a
	 * relist with them was refused before (fix round 2). Fix round 3: one
	 * held for a week says until when.
	 *
	 * @param string   $source     Auto-Relist, Bulk relist, Push.
	 * @param int      $product_id The product.
	 * @param string   $xml        The options it would carry.
	 * @param string[] $deleted    And their DeletedField values.
	 * @return string
	 */
	public static function relist_held_said( $source, $product_id, $xml, array $deleted ) {
		list( $what, $count ) = self::carried( $xml, $deleted );
		$it                   = 1 === $count ? 'it' : 'them';
		$memo                 = explode( '>', (string) get_post_meta( (int) $product_id, self::RELIST_MEMO, true ) );
		if ( '1' !== ( $memo[2] ?? '' ) ) {
			return sprintf(
				'%1$s: WC #%2$d - its relist goes without %3$s until %4$s UTC: eBay refused a relist with %5$s, and the relist without %5$s did not go either, so the refusal may not have been about %5$s. Changed on the eBay tab, %5$s %6$s sent again at once.',
				$source,
				(int) $product_id,
				$what,
				gmdate( 'Y-m-d H:i', (int) ( $memo[1] ?? 0 ) + TCGiant_Sync_Exporter::RELIST_PHOTOS_MEMO_DAYS * DAY_IN_SECONDS ),
				$it,
				1 === $count ? 'is' : 'are'
			);
		}
		return sprintf( '%1$s: WC #%2$d - its relist goes without %3$s: eBay refused a relist with %4$s as %5$s now. Fix or empty %4$s on the eBay tab to send %4$s again.', $source, (int) $product_id, $what, $it, 1 === $count ? 'it is' : 'they are' );
	}

	// -------------------------------------------------------------------------
	// Admin fields
	// -------------------------------------------------------------------------

	/**
	 * The fields' save. The section is drawn on the product's eBay tab by
	 * TCGiant_Sync_Admin::render_ebay_listing_panel().
	 */
	public static function register_admin() {
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_field' ) );
	}

	/**
	 * Save what the eBay tab posted, when it posted the section: the product
	 * screen's own save, or the eBay tab's Push and Verify buttons. An empty
	 * field is deleted, never kept as ''.
	 *
	 * @param int   $product_id The product.
	 * @param array $posted     $_POST.
	 */
	public static function save_posted( $product_id, array $posted ) {
		if ( ! isset( $posted[ self::SUBTITLE_META . '_shown' ] ) ) {
			return;
		}
		// What a relist carries of them, before (fix round 3): a change to any,
		// emptied included, forgets a relist's refusal (RELIST_MEMO), so the
		// next relist sends them again, as its log line says. Brand and MPN go
		// in no relist, and change nothing there.
		$was = array_intersect_key( self::values( (int) $product_id ), self::ITEM_FIELDS );
		foreach ( self::METAS as $field => $meta ) {
			if ( ! isset( $posted[ $meta ] ) || ! is_string( $posted[ $meta ] ) ) {
				continue;
			}
			$value = self::typed( $field, $posted[ $meta ] );
			if ( '' === $value ) {
				delete_post_meta( (int) $product_id, $meta );
			} else {
				update_post_meta( (int) $product_id, $meta, $value );
			}
			if ( 'second_category' === $field ) {
				$name = isset( $posted[ self::SECOND_CATEGORY_NAME_META ] ) && is_string( $posted[ self::SECOND_CATEGORY_NAME_META ] ) ? sanitize_text_field( wp_unslash( $posted[ self::SECOND_CATEGORY_NAME_META ] ) ) : '';
				if ( '' === $value || '' === $name ) {
					delete_post_meta( (int) $product_id, self::SECOND_CATEGORY_NAME_META );
				} else {
					update_post_meta( (int) $product_id, self::SECOND_CATEGORY_NAME_META, $name );
				}
			}
		}
		if ( array_intersect_key( self::values( (int) $product_id ), self::ITEM_FIELDS ) !== $was ) {
			delete_post_meta( (int) $product_id, self::RELIST_MEMO );
		}
	}

	/**
	 * A posted field as save_posted() keeps it: sanitised as the tab's field
	 * is (a condition note keeps its lines), then as it would be sent
	 * (clean()). '' is an empty field, which save_posted() deletes. The bulk
	 * edit (3.39.0) asks it before it sets one, so a value eBay could not
	 * take is never saved as an emptied field.
	 *
	 * @param string $field The field.
	 * @param string $raw   What was posted, still slashed.
	 * @return string
	 */
	public static function typed( $field, $raw ) {
		$typed = 'condition_note' === $field ? sanitize_textarea_field( wp_unslash( $raw ) ) : sanitize_text_field( wp_unslash( $raw ) );
		return self::clean( $field, $typed );
	}

	/**
	 * Whether a product takes an auction's Buy It Now and reserve: its eBay
	 * tab shows their rows only then, and the bulk edit (3.39.0) sets them
	 * only then. Never a variable product. An auction by the format it would
	 * be pushed as - its own, else the shop's - or by the format its listing
	 * is recorded as (fix round 1): an imported auction in a fixed-price shop
	 * is relisted as an auction, with these.
	 *
	 * @param WC_Product $product        The product.
	 * @param string     $default_format The shop's default format (Settings), for a product with none of its own.
	 * @return bool
	 */
	public static function auction_fields_shown( $product, $default_format ) {
		$product_id = (int) $product->get_id();
		$own_format = (string) get_post_meta( $product_id, '_ebay_export_listing_type', true );
		$recorded   = (string) get_post_meta( $product_id, '_ebay_listing_type', true );
		return ! $product->is_type( 'variable' ) && ( 'Chinese' === ( '' !== $own_format ? $own_format : (string) $default_format ) || 'Chinese' === $recorded );
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
	 * The "More listing options" section of a product's eBay tab.
	 *
	 * @param WC_Product $product        The product.
	 * @param string     $default_format The shop's default format (Settings), for a product with none of its own.
	 */
	public static function render_product_section( $product, $default_format = 'FixedPriceItem' ) {
		$product_id = (int) $product->get_id();
		$variable   = $product->is_type( 'variable' );
		$values     = array();
		foreach ( self::METAS as $field => $meta ) {
			$values[ $field ] = (string) get_post_meta( $product_id, $meta, true );
		}
		// An auction by the format it would be pushed as, or by the format
		// its listing is recorded as (fix round 1): an imported auction in a
		// fixed-price shop is relisted as an auction, with these.
		$recorded   = (string) get_post_meta( $product_id, '_ebay_listing_type', true );
		$auction    = self::auction_fields_shown( $product, $default_format );
		$cat_name   = (string) get_post_meta( $product_id, self::SECOND_CATEGORY_NAME_META, true );
		$choices    = array();
		if ( class_exists( 'TCGiant_Sync_Catalog' ) ) {
			foreach ( (array) TCGiant_Sync_Catalog::get_categories() as $choice_id => $choice_label ) {
				if ( 1 === preg_match( '/^\d+$/', (string) $choice_id ) ) {
					$choices[ (string) $choice_id ] = (string) $choice_label;
				}
			}
		}
		$filled     = count( array_filter( $values, function ( $value ) {
			return '' !== $value;
		} ) );
		/* translators: %d: how many fields are filled */
		$summary = $filled ? sprintf( _n( '%d filled', '%d filled', $filled, 'tcgiant-sync' ), $filled ) : __( 'None: the listing keeps what eBay has', 'tcgiant-sync' );
		$hint    = 'display:block;margin-top:2px;font-size:11px;color:#888;';
		$fee     = 'display:block;margin-top:2px;font-size:11px;color:#8a6d3b;';
		?>
		<div class="tc-ebay-section" id="tc-section-more-options">
			<div class="tc-ebay-section-head" data-target="tc-body-more-options">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<span><?php esc_html_e( 'More listing options (subtitle, condition note, second category, Brand, MPN)', 'tcgiant-sync' ); ?></span>
				<span id="tc-more-options-summary" style="font-weight:400;color:#888;font-size:12px;margin-left:auto;"><?php echo esc_html( $summary ); ?></span>
			</div>
			<div class="tc-ebay-section-body" id="tc-body-more-options">
				<input type="hidden" name="<?php echo esc_attr( self::SUBTITLE_META . '_shown' ); ?>" value="1">
				<p style="margin:0 0 6px;font-size:12px;color:#555;"><?php esc_html_e( 'Each is sent only when filled in. Empty: the listing keeps what eBay has - a listing imported from eBay keeps its own subtitle, condition note, second category and the rest.', 'tcgiant-sync' ); ?></p>

				<p class="form-field">
					<label for="<?php echo esc_attr( self::SUBTITLE_META ); ?>"><?php esc_html_e( 'Subtitle', 'tcgiant-sync' ); ?></label>
					<input type="text" id="<?php echo esc_attr( self::SUBTITLE_META ); ?>" name="<?php echo esc_attr( self::SUBTITLE_META ); ?>" value="<?php echo esc_attr( $values['subtitle'] ); ?>" maxlength="<?php echo (int) self::SUBTITLE_MAX; ?>">
					<span style="<?php echo esc_attr( $hint ); ?>"><?php esc_html_e( 'Up to 55 characters, shown under the title in eBay\'s search results.', 'tcgiant-sync' ); ?></span>
					<span class="tc-fee-line" style="<?php echo esc_attr( $fee ); ?>"><?php esc_html_e( 'eBay charges a fee for a subtitle, set by your eBay site. On some eBay sites (eBay US and eBay Germany among them) a Good \'Til Cancelled listing pays it again at each monthly renewal. Not refunded if you remove it.', 'tcgiant-sync' ); ?></span>
				</p>

				<p class="form-field">
					<label for="<?php echo esc_attr( self::CONDITION_NOTE_META ); ?>"><?php esc_html_e( 'Condition note', 'tcgiant-sync' ); ?></label>
					<textarea id="<?php echo esc_attr( self::CONDITION_NOTE_META ); ?>" name="<?php echo esc_attr( self::CONDITION_NOTE_META ); ?>" rows="3" maxlength="<?php echo (int) self::CONDITION_NOTE_MAX; ?>" style="width:50%;"><?php echo esc_textarea( $values['condition_note'] ); ?></textarea>
					<span style="<?php echo esc_attr( $hint ); ?>"><?php esc_html_e( 'Up to 1,000 characters on the item\'s wear or flaws, shown beside its condition. Not sent with a New condition, or a card sent with condition descriptors (graded or ungraded): eBay ignores it there. Some eBay categories do not take one, and eBay drops it with a warning.', 'tcgiant-sync' ); ?></span>
				</p>

				<div class="form-field" style="padding:5px 20px 5px 162px;">
					<label for="<?php echo esc_attr( self::SECOND_CATEGORY_META ); ?>" style="float:left;width:150px;margin:0 0 0 -150px;"><?php esc_html_e( 'Second category', 'tcgiant-sync' ); ?></label>
					<?php // The primary category's own list (TCGiant_Sync_Catalog::get_categories()), then an ID typed or browsed. ?>
					<select id="tc-second-category-choice" style="width:100%;font-size:12px;margin-bottom:4px;">
						<option value=""><?php esc_html_e( '-- none, or one typed or browsed below --', 'tcgiant-sync' ); ?></option>
						<?php foreach ( $choices as $choice_id => $choice_label ) : ?>
							<option value="<?php echo esc_attr( (string) $choice_id ); ?>" <?php echo (string) $choice_id === $values['second_category'] ? 'selected="selected"' : ''; ?>><?php echo esc_html( $choice_label . ' (' . $choice_id . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="text" id="<?php echo esc_attr( self::SECOND_CATEGORY_META ); ?>" name="<?php echo esc_attr( self::SECOND_CATEGORY_META ); ?>" value="<?php echo esc_attr( $values['second_category'] ); ?>" placeholder="<?php esc_attr_e( 'Enter eBay leaf category ID', 'tcgiant-sync' ); ?>" style="width:100%;font-size:12px;">
					<input type="hidden" id="<?php echo esc_attr( self::SECOND_CATEGORY_NAME_META ); ?>" name="<?php echo esc_attr( self::SECOND_CATEGORY_NAME_META ); ?>" value="<?php echo esc_attr( $cat_name ); ?>">
					<span id="tc-second-category-label" style="display:<?php echo '' !== $cat_name && '' !== $values['second_category'] ? 'block' : 'none'; ?>;margin-top:4px;font-size:11px;color:#16a34a;font-weight:500;"><?php echo '' !== $cat_name ? '&#10004; ' . esc_html( $cat_name . ' (' . $values['second_category'] . ')' ) : ''; ?></span>
					<button type="button" class="button" id="tc-browse-second-category" style="margin-top:6px;font-size:11px;width:100%;"><span class="dashicons dashicons-category" style="font-size:13px;vertical-align:middle;margin-right:2px;"></span><?php esc_html_e( 'Browse eBay Categories', 'tcgiant-sync' ); ?></button>
					<div id="tc-second-category-browser" style="display:none;margin-top:6px;border:1px solid #e5e5e5;border-radius:4px;padding:8px;background:#f9fafb;">
						<div id="tc-second-category-breadcrumb" style="font-size:11px;color:#666;margin-bottom:6px;"></div>
						<div id="tc-second-category-drilldown" style="max-height:200px;overflow-y:auto;"></div>
					</div>
					<span style="<?php echo esc_attr( $hint ); ?>"><?php esc_html_e( 'The listing shows in this eBay category too. Not sent for an eBay Motors vehicle, or when it is the listing\'s primary category; some categories do not take a second one, and eBay drops it with a warning.', 'tcgiant-sync' ); ?></span>
					<span class="tc-fee-line" style="<?php echo esc_attr( $fee ); ?>"><?php esc_html_e( 'eBay charges a second insertion fee for it, and the listing\'s upgrade fees (a subtitle, for one) again. Not refunded if you remove it.', 'tcgiant-sync' ); ?></span>
				</div>

				<?php if ( ! $variable ) : ?>
					<div id="tc-auction-options" style="<?php echo $auction ? '' : 'display:none;'; ?>" data-default-format="<?php echo esc_attr( (string) $default_format ); ?>" data-recorded-format="<?php echo esc_attr( $recorded ); ?>">
						<p class="form-field">
							<label for="<?php echo esc_attr( self::BUY_IT_NOW_META ); ?>"><?php esc_html_e( 'Buy It Now price', 'tcgiant-sync' ); ?></label>
							<input type="text" class="wc_input_price" id="<?php echo esc_attr( self::BUY_IT_NOW_META ); ?>" name="<?php echo esc_attr( self::BUY_IT_NOW_META ); ?>" value="<?php echo esc_attr( $values['buy_it_now'] ); ?>">
							<span style="<?php echo esc_attr( $hint ); ?>"><?php esc_html_e( 'Auctions only. Must be above the opening bid and the reserve price, and at least a set share above the opening bid: 30% on eBay US (eBay Motors too) and eBay Australia in most categories, 40% on eBay UK, Canada and Germany. A new listing gets eBay\'s own refusal for one too low; a relist leaves it out, holding a site not named here to 40%. Gone once a bid meets the reserve price, if there is one, or else at the first bid.', 'tcgiant-sync' ); ?></span>
							<span class="tc-fee-line" style="<?php echo esc_attr( $fee ); ?>"><?php esc_html_e( 'Free on most eBay sites.', 'tcgiant-sync' ); ?></span>
						</p>
						<p class="form-field">
							<label for="<?php echo esc_attr( self::RESERVE_META ); ?>"><?php esc_html_e( 'Reserve price', 'tcgiant-sync' ); ?></label>
							<input type="text" class="wc_input_price" id="<?php echo esc_attr( self::RESERVE_META ); ?>" name="<?php echo esc_attr( self::RESERVE_META ); ?>" value="<?php echo esc_attr( $values['reserve'] ); ?>">
							<span style="<?php echo esc_attr( $hint ); ?>"><?php esc_html_e( 'Auctions only: the lowest price the item sells for. Must be above the opening bid; some eBay sites set a minimum reserve.', 'tcgiant-sync' ); ?></span>
							<span class="tc-fee-line" style="<?php echo esc_attr( $fee ); ?>"><?php esc_html_e( 'eBay charges a reserve fee, set by your eBay site and the reserve, whether or not the item sells. Not refunded if you lower or remove it.', 'tcgiant-sync' ); ?></span>
						</p>
						<p style="margin:0 0 6px;font-size:11px;color:#888;padding-left:12px;"><?php esc_html_e( 'A new listing and a relist send them; an Update of a live auction never does. A relist leaves out a reserve above the ended listing\'s, or one that listing did not have, and a Buy It Now not above the reserve it keeps or under its site\'s share above the opening bid, and the log says so; the relist still goes, with the ended listing\'s Buy It Now if it had one. Emptied, they stay on a listing that has them until it ends: eBay documents no way to take them off a relist.', 'tcgiant-sync' ); ?></p>
					</div>
				<?php endif; ?>

				<p class="form-field">
					<label for="<?php echo esc_attr( self::BRAND_META ); ?>"><?php esc_html_e( 'Brand', 'tcgiant-sync' ); ?></label>
					<input type="text" id="<?php echo esc_attr( self::BRAND_META ); ?>" name="<?php echo esc_attr( self::BRAND_META ); ?>" value="<?php echo esc_attr( $values['brand'] ); ?>" maxlength="<?php echo (int) self::SPECIFIC_MAX; ?>">
					<span style="<?php echo esc_attr( $hint ); ?>"><?php esc_html_e( 'Empty: the item specifics\' own Brand, if they have one (an attribute or item specific named Brand), or the product\'s WooCommerce brand when that setting is on (Settings, Push to eBay: WooCommerce Brands as eBay\'s Brand).', 'tcgiant-sync' ); ?></span>
				</p>
				<p class="form-field">
					<label for="<?php echo esc_attr( self::MPN_META ); ?>"><?php esc_html_e( 'MPN', 'tcgiant-sync' ); ?></label>
					<input type="text" id="<?php echo esc_attr( self::MPN_META ); ?>" name="<?php echo esc_attr( self::MPN_META ); ?>" value="<?php echo esc_attr( $values['mpn'] ); ?>" maxlength="<?php echo (int) self::SPECIFIC_MAX; ?>">
					<span style="<?php echo esc_attr( $hint ); ?>"><?php esc_html_e( 'Sent as the item specifics Brand and MPN (manufacturer part number), by the names the eBay category gives them (eBay Motors\' "Manufacturer Part Number" too), in place of an item specific or attribute of the same name. An Update sends them only with the product\'s other item specifics: eBay replaces all of a listing\'s item specifics with those sent, so these two alone would take off any others the listing has on eBay. A live auction\'s Update keeps the Brand and MPN it was listed with, and a value changed since goes with a new listing. A relist keeps the item specifics the listing had. Not sent at item level for a variable product whose variations differ by Brand or MPN: eBay takes it in each variation.', 'tcgiant-sync' ); ?></span>
				</p>

				<p style="margin:4px 0 0;font-size:11px;color:#888;padding-left:12px;">
					<?php esc_html_e( 'A new listing, Verify and a relist send every filled field. An Update sends them too, except to a live auction: eBay refuses most changes to an auction once it has a bid or is within 12 hours of its end. Emptying a field takes it off the listing at the next Update or relist, but only a value TCGiant Sync sent; a value eBay had of its own stays. A listing relisted on eBay itself keeps what it carried, so emptying a field won\'t take it off that listing. A relist eBay refuses with these fields goes once more without them, and later relists leave them out until one is changed here, or for a week when the relist without them was refused too. Fees eBay charged are not refunded.', 'tcgiant-sync' ); ?>
				</p>
			</div>
		</div>
		<script>
		jQuery( function ( $ ) {
			var fields = <?php echo wp_json_encode( array_values( self::METAS ) ); ?>;
			// Buy It Now and reserve are an auction's: shown with the format,
			// or always for a listing recorded as an auction.
			$( '#_ebay_export_listing_type' ).on( 'change', function () {
				var box = $( '#tc-auction-options' ), v = $( this ).val() || box.data( 'default-format' );
				box.toggle( 'Chinese' === v || 'Chinese' === box.data( 'recorded-format' ) );
			} );
			// The second category, from eBay's tree as the primary's browser reads it.
			var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', nonce = '<?php echo esc_js( wp_create_nonce( 'tcgiant_sync_ajax' ) ); ?>', trail = [];
			$( '#<?php echo esc_js( self::SECOND_CATEGORY_META ); ?>' ).on( 'input', function () { $( '#<?php echo esc_js( self::SECOND_CATEGORY_NAME_META ); ?>' ).val( '' ); $( '#tc-second-category-label' ).hide(); $( '#tc-second-category-choice' ).val( '' ); } );
			$( '#tc-second-category-choice' ).on( 'change', function () {
				var v = $( this ).val() || '';
				$( '#<?php echo esc_js( self::SECOND_CATEGORY_META ); ?>' ).val( v );
				$( '#<?php echo esc_js( self::SECOND_CATEGORY_NAME_META ); ?>' ).val( v ? $( this ).find( 'option:selected' ).text() : '' );
				$( '#tc-second-category-label' ).hide();
			} );
			function load( pid, tree ) {
				var d = $( '#tc-second-category-drilldown' );
				d.html( '<div style="text-align:center;padding:12px;color:#888;">Loading...</div>' );
				$.post( ajaxUrl, { action: 'tcgiant_browse_categories', _ajax_nonce: nonce, parent_id: pid, tree: tree || '' }, function ( r ) {
					d.empty();
					if ( ! r.success ) { d.append( $( '<div style="color:#c00;padding:6px;"></div>' ).text( r.data && r.data.message ? r.data.message : '' ) ); return; }
					$.each( r.data.categories || [], function ( i, c ) {
						var row = $( '<div style="padding:5px 6px;border-bottom:1px solid #eee;cursor:pointer;font-size:12px;"></div>' ).text( c.name + ( c.leaf ? ' (leaf)' : ' ›' ) );
						row.on( 'click', function () {
							if ( c.leaf ) {
								var name = $.map( trail, function ( t ) { return t.name; } ).concat( [ c.name ] ).join( ' > ' );
								$( '#<?php echo esc_js( self::SECOND_CATEGORY_META ); ?>' ).val( c.id );
								$( '#<?php echo esc_js( self::SECOND_CATEGORY_NAME_META ); ?>' ).val( name );
								$( '#tc-second-category-label' ).text( '✔ ' + name + ' (' + c.id + ')' ).show();
								$( '#tc-second-category-browser' ).slideUp( 200 );
								trail = [];
							} else {
								trail.push( { id: c.id, name: c.name, tree: c.tree || '' } );
								crumbs();
								load( c.id, c.tree || '' );
							}
						} );
						d.append( row );
					} );
				} );
			}
			function crumbs() {
				var bc = $( '#tc-second-category-breadcrumb' ).empty(), root = $( '<a href="#">All</a>' );
				root.on( 'click', function ( e ) { e.preventDefault(); trail = []; crumbs(); load( '' ); } );
				bc.append( root );
				$.each( trail, function ( i, c ) { bc.append( ' › ' ).append( $( '<b></b>' ).text( c.name ) ); } );
			}
			$( '#tc-browse-second-category' ).on( 'click', function () {
				var b = $( '#tc-second-category-browser' ).toggle();
				if ( b.is( ':visible' ) && ! $( '#tc-second-category-drilldown' ).children().length ) { crumbs(); load( '' ); }
			} );
			// The Push and Verify buttons save what the section holds before
			// the product is updated: the fields go with them.
			$.ajaxPrefilter( function ( options ) {
				if ( 'string' !== typeof options.data || ! /(^|&)action=tcgiant_(push|verify)_product(&|$)/.test( options.data ) || ! $( '#<?php echo esc_js( self::SUBTITLE_META ); ?>' ).length ) {
					return;
				}
				var extra = '&<?php echo esc_js( self::SUBTITLE_META ); ?>_shown=1&<?php echo esc_js( self::SECOND_CATEGORY_NAME_META ); ?>=' + encodeURIComponent( $( '#<?php echo esc_js( self::SECOND_CATEGORY_NAME_META ); ?>' ).val() || '' );
				$.each( fields, function ( i, name ) {
					var el = $( '#' + name );
					if ( el.length ) {
						extra += '&' + name + '=' + encodeURIComponent( el.val() || '' );
					}
				} );
				options.data += extra;
			} );
		} );
		</script>
		<?php
	}
}
