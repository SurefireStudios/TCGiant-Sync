<?php
/**
 * Bulk edit of eBay fields (3.39.0).
 *
 * Every field of a product's eBay tab was set one product at a time. Now an
 * "eBay (TCGiant Sync)" group sits in WooCommerce's own Bulk Edit panel
 * (Products, All Products: select products, Bulk actions, Edit), beside
 * WooCommerce's fields: WooCommerce's filters, search and selection pick the
 * products, and this class only draws the group and saves it.
 *
 * - Every field starts at "- No change -". A bulk edit that leaves the group
 *   alone reads and writes no meta of a product's at all, byte for byte.
 * - A text or price field is No change, Set to or Clear, as WooCommerce's own
 *   price; a select is No change, each choice, or a clear of the product's
 *   own value (SHOP: then the shop's setting goes, or eBay's own for an
 *   imported listing that keeps its values; for the item type, none).
 * - Each value is saved through the code the eBay tab's own save runs
 *   (TCGiant_Sync_Admin::save_export_posted(), TCGiant_Sync_Ebay_Price::
 *   save_posted(), TCGiant_Sync_Identifiers::set_none(),
 *   TCGiant_Sync_Listing_Options::save_posted()), posted in the tab's own
 *   names, one field at a time: the same cleaning, limits and side effects,
 *   with no second copy of them.
 * - A value that cannot apply to a product is skipped for that product, and
 *   said: an auction's Buy It Now or reserve on a variable product or one
 *   whose format is not Auction after the edit, a duration its format does
 *   not take, a grader of another item type or of an item not graded, a
 *   second category that is the product's own category, and the rest in
 *   skip(). A value no product could take - a price that is not one, an ID
 *   that is no category - changes nothing anywhere, and is said once.
 * - The fields apply in their order here, so a value judged by another goes
 *   after it: the item type before the condition and grader, the format
 *   before the duration and an auction's prices, the category before the
 *   second category. Auction itself is judged by the duration the product
 *   will have after the edit, the one set in it included.
 * - An item type changed to Trading Cards or Coins posts what the eBay tab
 *   posts for it - that type's grader, grade and ungraded condition, empty -
 *   so another type's never stay with it (fix round 1, M1); a category set
 *   to the one a product already has leaves it, and its name, alone.
 * - Nothing goes to eBay: no call, and no push or Update queued. A linked
 *   product's listing takes the change at its next push or Update.
 * - One log line a bulk edit, and a notice on the products list after it:
 *   how many products changed, field by field, and which were skipped and
 *   why.
 *
 * Permissions: WooCommerce's bulk edit nonce (woocommerce_quick_edit_nonce)
 * and edit_products for the request, and edit_post for each product, as
 * WooCommerce checks them (WC_Admin_Post_Types::bulk_and_quick_edit_save_post()).
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Bulk_Edit class.
 */
class TCGiant_Sync_Bulk_Edit {

	/**
	 * The group's names in the request: tcgiant_bulk_<field> for the choice,
	 * tcgiant_bulk_<field>_value for what is typed beside it.
	 */
	const PREFIX = 'tcgiant_bulk_';

	/**
	 * A select's clear of the product's own value: the shop's setting goes
	 * then, or eBay's own for an imported listing that keeps its values; an
	 * item type, none.
	 */
	const SHOP = '_shop';

	/** A text or price field's "Set to". */
	const SET = 'set';

	/** A text or price field's "Clear". */
	const CLEAR = 'clear';

	/** A category's "a typed ID". */
	const TYPED = 'custom';

	/**
	 * Transient, per user, that keeps what a bulk edit did for the notice on
	 * the products list after it, for five minutes, shown once (as the
	 * follow choice's own, TCGiant_Sync_Admin::FOLLOW_SAID). Uninstall's
	 * sweep of the plugin's transients takes it.
	 */
	const SAID = 'tcgiant_sync_bulk_edit_said_';

	/** How long the notice's record is kept. */
	const SAID_SECONDS = 300;

	/** The most product IDs a line of the notice or the log names; the rest are counted. */
	const IDS_SHOWN = 10;

	/** The eBay tab's names of the fields its own save (TCGiant_Sync_Admin::save_export_posted()) keeps. */
	const TAB_KEYS = array(
		'item_type'        => '_ebay_export_item_type',
		'condition_type'   => '_ebay_export_condition_type',
		'grader'           => '_ebay_export_grader_id',
		'condition_id'     => '_ebay_export_condition_id',
		'listing_type'     => '_ebay_export_listing_type',
		'listing_duration' => '_ebay_export_listing_duration',
		'fulfillment'      => '_ebay_export_fulfillment_policy',
	);

	/**
	 * What this request's bulk edit has done so far, or null: built by
	 * save_bulk_edit(), product by product, and written to the log and the
	 * notice once by finish_bulk_edit().
	 *
	 * @var array|null
	 */
	private static $run = null;

	/**
	 * The hooks: the group drawn at the end of WooCommerce's Bulk Edit fields,
	 * saved after WooCommerce has saved each product, finished once the bulk
	 * edit is done (WordPress 6.3's bulk_edit_posts, or the request's end on
	 * an older WordPress), and said on the products list.
	 */
	public static function register_admin() {
		add_action( 'woocommerce_product_bulk_edit_end', array( __CLASS__, 'render_bulk_edit' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( __CLASS__, 'save_bulk_edit' ) );
		add_action( 'bulk_edit_posts', array( __CLASS__, 'finish_bulk_edit' ) );
		add_action( 'shutdown', array( __CLASS__, 'finish_bulk_edit' ) );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_edit_notice' ) );
	}

	// -------------------------------------------------------------------------
	// The fields
	// -------------------------------------------------------------------------

	/**
	 * The fields, in the order they are drawn and applied: kind, label, and
	 * the meta each keeps, by which a change is told.
	 *
	 * Kinds: select (a choice, or the shop's setting), category (the shop's
	 * list, a typed ID, or the shop's setting), second (the shop's list, a
	 * typed ID, or Clear), text, note (a text of lines), price (Set to or
	 * Clear), tick (ticked or not).
	 *
	 * @return array<string,array{kind: string, label: string, metas: string[]}>
	 */
	public static function fields() {
		return array(
			'item_type'        => array( 'kind' => 'select', 'label' => __( 'Item type', 'tcgiant-sync' ), 'metas' => array( self::TAB_KEYS['item_type'] ) ),
			'category'         => array( 'kind' => 'category', 'label' => __( 'eBay category', 'tcgiant-sync' ), 'metas' => array( '_ebay_export_category_id', '_ebay_export_category_typed', '_ebay_export_category_name' ) ),
			'condition_type'   => array( 'kind' => 'select', 'label' => __( 'Graded or Ungraded', 'tcgiant-sync' ), 'metas' => array( self::TAB_KEYS['condition_type'] ) ),
			'grader'           => array( 'kind' => 'select', 'label' => __( 'Professional grader', 'tcgiant-sync' ), 'metas' => array( self::TAB_KEYS['grader'] ) ),
			'condition_id'     => array( 'kind' => 'select', 'label' => __( 'Condition (All Other)', 'tcgiant-sync' ), 'metas' => array( self::TAB_KEYS['condition_id'] ) ),
			'listing_type'     => array( 'kind' => 'select', 'label' => __( 'Listing type', 'tcgiant-sync' ), 'metas' => array( self::TAB_KEYS['listing_type'] ) ),
			'listing_duration' => array( 'kind' => 'select', 'label' => __( 'Listing duration', 'tcgiant-sync' ), 'metas' => array( self::TAB_KEYS['listing_duration'] ) ),
			'fulfillment'      => array( 'kind' => 'select', 'label' => __( 'Shipping policy', 'tcgiant-sync' ), 'metas' => array( self::TAB_KEYS['fulfillment'] ) ),
			'ebay_price'       => array( 'kind' => 'price', 'label' => __( 'eBay price', 'tcgiant-sync' ), 'metas' => array( TCGiant_Sync_Ebay_Price::OWN_META ) ),
			'identifier_none'  => array( 'kind' => 'tick', 'label' => __( 'No UPC, EAN or ISBN', 'tcgiant-sync' ), 'metas' => array( TCGiant_Sync_Identifiers::NONE_META ) ),
			'subtitle'         => array( 'kind' => 'text', 'label' => __( 'Subtitle', 'tcgiant-sync' ), 'metas' => array( TCGiant_Sync_Listing_Options::SUBTITLE_META ) ),
			'condition_note'   => array( 'kind' => 'note', 'label' => __( 'Condition note', 'tcgiant-sync' ), 'metas' => array( TCGiant_Sync_Listing_Options::CONDITION_NOTE_META ) ),
			'second_category'  => array( 'kind' => 'second', 'label' => __( 'Second category', 'tcgiant-sync' ), 'metas' => array( TCGiant_Sync_Listing_Options::SECOND_CATEGORY_META, TCGiant_Sync_Listing_Options::SECOND_CATEGORY_NAME_META ) ),
			'buy_it_now'       => array( 'kind' => 'price', 'label' => __( 'Buy It Now price', 'tcgiant-sync' ), 'metas' => array( TCGiant_Sync_Listing_Options::BUY_IT_NOW_META ) ),
			'reserve'          => array( 'kind' => 'price', 'label' => __( 'Reserve price', 'tcgiant-sync' ), 'metas' => array( TCGiant_Sync_Listing_Options::RESERVE_META ) ),
			'brand'            => array( 'kind' => 'text', 'label' => __( 'Brand', 'tcgiant-sync' ), 'metas' => array( TCGiant_Sync_Listing_Options::BRAND_META ) ),
			'mpn'              => array( 'kind' => 'text', 'label' => __( 'MPN', 'tcgiant-sync' ), 'metas' => array( TCGiant_Sync_Listing_Options::MPN_META ) ),
		);
	}

	/**
	 * A select's choices, value => label, as the eBay tab offers them: the
	 * item types, Graded or Ungraded, the graders of trading cards and of
	 * coins (by group), the All Other conditions, the formats and durations,
	 * and the shipping policies fetched from eBay.
	 *
	 * @param string $field The field.
	 * @return array<string,array<string,string>> Group label ('' for none) => value => label.
	 */
	public static function choices( $field ) {
		switch ( $field ) {
			case 'item_type':
				// As the eBay tab's cards name them.
				return array( '' => array(
					'tcg'   => __( 'Trading Cards', 'tcgiant-sync' ),
					'coins' => __( 'Coins', 'tcgiant-sync' ),
					'other' => __( 'All Other', 'tcgiant-sync' ),
				) );
			case 'condition_type':
				return array( '' => array( 'graded' => __( 'Graded', 'tcgiant-sync' ), 'ungraded' => __( 'Ungraded', 'tcgiant-sync' ) ) );
			case 'grader':
				return array(
					__( 'Trading Cards', 'tcgiant-sync' ) => self::flipped( TCGiant_Sync_Catalog::GRADERS_TCG ),
					__( 'Coins', 'tcgiant-sync' )         => self::flipped( TCGiant_Sync_Catalog::GRADERS_COINS ),
				);
			case 'condition_id':
				return array( '' => self::strings( TCGiant_Sync_Catalog::plain_conditions() ) );
			case 'listing_type':
				return array( '' => self::strings( TCGiant_Sync_Catalog::LISTING_TYPES ) );
			case 'listing_duration':
				// The durations a format takes (DURATIONS_BY_TYPE, which the
				// push checks), labelled as the Listings page's format change
				// labels them: never 30 Days, which none takes.
				$taken = array();
				foreach ( TCGiant_Sync_Catalog::DURATIONS_BY_TYPE as $durations ) {
					$taken = array_merge( $taken, (array) $durations );
				}
				return array( '' => array_intersect_key( self::strings( TCGiant_Sync_Catalog::LISTING_DURATIONS ), array_flip( $taken ) ) );
			case 'fulfillment':
				$out = array();
				foreach ( (array) get_option( 'tcgiant_export_policies_fulfillment', array() ) as $policy ) {
					if ( is_array( $policy ) && ! empty( $policy['id'] ) ) {
						$out[ (string) $policy['id'] ] = (string) ( $policy['name'] ?? $policy['id'] );
					}
				}
				return array( '' => $out );
		}
		return array();
	}

	/**
	 * Name => ID, as ID => name.
	 *
	 * @param array $list Name => ID.
	 * @return array<string,string>
	 */
	private static function flipped( array $list ) {
		$out = array();
		foreach ( $list as $name => $id ) {
			$out[ (string) $id ] = (string) $name;
		}
		return $out;
	}

	/**
	 * A list with its keys as strings.
	 *
	 * @param array $list Key => label.
	 * @return array<string,string>
	 */
	private static function strings( array $list ) {
		$out = array();
		foreach ( $list as $key => $label ) {
			$out[ (string) $key ] = (string) $label;
		}
		return $out;
	}

	/**
	 * The shop's category list, as the eBay tab's second category offers it
	 * (TCGiant_Sync_Catalog::get_categories(), its IDs alone): ID => label.
	 *
	 * @return array<string,string>
	 */
	public static function categories() {
		$out = array();
		foreach ( (array) TCGiant_Sync_Catalog::get_categories() as $id => $label ) {
			if ( 1 === preg_match( '/^\d+$/', (string) $id ) ) {
				$out[ (string) $id ] = (string) $label;
			}
		}
		return $out;
	}

	/**
	 * A choice's label, for the notice and the log.
	 *
	 * @param string $field  The field.
	 * @param array  $change Its change (requested()).
	 * @return string
	 */
	private static function done_words( $field, array $change ) {
		$kind = self::fields()[ $field ]['kind'];
		if ( self::SHOP === $change['choice'] ) {
			return 'item_type' === $field ? __( 'none (cleared)', 'tcgiant-sync' ) : __( 'the product\'s own cleared', 'tcgiant-sync' );
		}
		if ( self::CLEAR === $change['choice'] ) {
			return __( 'cleared', 'tcgiant-sync' );
		}
		if ( 'tick' === $kind ) {
			return 'yes' === $change['choice'] ? __( 'ticked', 'tcgiant-sync' ) : __( 'unticked', 'tcgiant-sync' );
		}
		if ( 'select' === $kind ) {
			foreach ( self::choices( $field ) as $group ) {
				if ( isset( $group[ $change['value'] ] ) ) {
					return $group[ $change['value'] ];
				}
			}
		}
		if ( 'category' === $kind || 'second' === $kind ) {
			return (string) $change['value'];
		}
		return __( 'set', 'tcgiant-sync' );
	}

	// -------------------------------------------------------------------------
	// The request
	// -------------------------------------------------------------------------

	/**
	 * The group as WooCommerce's bulk edit request carries it ($_REQUEST, as
	 * WooCommerce reads its own: the list's form is sent by GET).
	 *
	 * Nothing for any other request: one that is not WooCommerce's bulk edit,
	 * its quick edit, a bulk edit whose nonce WooCommerce would not take, or
	 * a user who may not edit products.
	 *
	 * @return array{fields: array, refused: array}|array{} fields: field =>
	 *         array( choice, value[, select][, name] ), in the fields' order,
	 *         for those changed; refused: field => array( why, what was
	 *         typed ), for a value no product could take. Empty when the group
	 *         was left alone.
	 */
	public static function requested() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WooCommerce's nonce, checked here as WooCommerce checks it.
		$request = $_REQUEST;
		if ( empty( $request['woocommerce_bulk_edit'] ) || ! empty( $request['woocommerce_quick_edit'] ) ) {
			return array();
		}
		$nonce = isset( $request['woocommerce_quick_edit_nonce'] ) && is_string( $request['woocommerce_quick_edit_nonce'] ) ? sanitize_text_field( wp_unslash( $request['woocommerce_quick_edit_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'woocommerce_quick_edit_nonce' ) || ! current_user_can( 'edit_products' ) ) {
			return array();
		}

		$fields  = array();
		$refused = array();
		foreach ( self::fields() as $field => $def ) {
			$key    = self::PREFIX . $field;
			$choice = isset( $request[ $key ] ) && is_string( $request[ $key ] ) ? sanitize_text_field( wp_unslash( $request[ $key ] ) ) : '';
			if ( '' === $choice ) {
				// No change.
				continue;
			}
			// What is typed, still slashed: the tab's own save unslashes it.
			$raw   = isset( $request[ $key . '_value' ] ) && is_string( $request[ $key . '_value' ] ) ? $request[ $key . '_value' ] : '';
			$shown = trim( sanitize_text_field( wp_unslash( $raw ) ) );
			$said  = self::change( $field, $def['kind'], $choice, $raw );
			if ( is_string( $said ) ) {
				$refused[ $field ] = array( $said, $shown );
				continue;
			}
			$fields[ $field ] = $said;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $fields && ! $refused ) {
			return array();
		}
		return array( 'fields' => $fields, 'refused' => $refused );
	}

	/**
	 * One field's change, from its choice and what is typed beside it, or
	 * why no product could take it.
	 *
	 * A value is judged by the rule its own save keeps it by - a price by
	 * TCGiant_Sync_Ebay_Price::typed() or TCGiant_Sync_Listing_Options::
	 * typed(), a text by the latter, a category ID as the second category's
	 * is (digits, never 0) - so a value that save would keep as an emptied
	 * field is refused here, never saved as one.
	 *
	 * @param string $field  The field.
	 * @param string $kind   Its kind.
	 * @param string $choice Its choice, sanitised.
	 * @param string $raw    What is typed beside it, still slashed.
	 * @return array|string array( choice, value[, select][, name] ), or why:
	 *                      choice, empty, not_price, not_category.
	 */
	private static function change( $field, $kind, $choice, $raw ) {
		$typed = trim( sanitize_text_field( wp_unslash( $raw ) ) );
		switch ( $kind ) {
			case 'select':
				if ( self::SHOP === $choice ) {
					return array( 'choice' => self::SHOP, 'value' => '' );
				}
				foreach ( self::choices( $field ) as $group ) {
					if ( isset( $group[ $choice ] ) ) {
						return array( 'choice' => $choice, 'value' => $choice );
					}
				}
				return 'choice';

			case 'category':
			case 'second':
				$none = 'category' === $kind ? self::SHOP : self::CLEAR;
				if ( $none === $choice ) {
					return array( 'choice' => $choice, 'value' => '', 'select' => '', 'name' => '' );
				}
				if ( self::TYPED === $choice ) {
					// An eBay category ID: digits, never 0 - the rule the
					// second category is kept by (TCGiant_Sync_Listing_Options::clean()).
					$id = TCGiant_Sync_Listing_Options::clean( 'second_category', $typed );
					if ( '' === $id ) {
						return '' === $typed ? 'empty' : 'not_category';
					}
					return array( 'choice' => self::TYPED, 'value' => $id, 'select' => self::TYPED, 'name' => '' );
				}
				$list = self::categories();
				if ( ! isset( $list[ $choice ] ) ) {
					return 'choice';
				}
				// As the tab names a category chosen from its list: the
				// option's own text, "Label (ID)" (second category), or none
				// (the main category, whose tab clears its name).
				return array( 'choice' => $choice, 'value' => $choice, 'select' => $choice, 'name' => 'second' === $kind ? $list[ $choice ] . ' (' . $choice . ')' : '' );

			case 'tick':
				return in_array( $choice, array( 'yes', 'no' ), true ) ? array( 'choice' => $choice, 'value' => 'yes' === $choice ? '1' : '' ) : 'choice';

			default:
				if ( self::CLEAR === $choice ) {
					return array( 'choice' => self::CLEAR, 'value' => '' );
				}
				if ( self::SET !== $choice ) {
					return 'choice';
				}
				$value = 'ebay_price' === $field
					? TCGiant_Sync_Ebay_Price::typed( $typed )
					: TCGiant_Sync_Listing_Options::typed( $field, $raw );
				if ( '' === $value ) {
					return ( '' === $typed || 'price' !== $kind ) ? 'empty' : 'not_price';
				}
				// The value as typed: its save cleans it as the tab's does.
				return array( 'choice' => self::SET, 'value' => $raw );
		}
	}

	// -------------------------------------------------------------------------
	// The save
	// -------------------------------------------------------------------------

	/**
	 * woocommerce_product_bulk_edit_save: the group's changes to one product,
	 * once WooCommerce has saved its own.
	 *
	 * The group left alone - every field "No change" - returns at once: no
	 * meta of the product's is read or written.
	 *
	 * @param WC_Product|int $product The product WooCommerce saved.
	 */
	public static function save_bulk_edit( $product ) {
		$edit = self::requested();
		if ( ! $edit ) {
			return;
		}
		$product = is_object( $product ) ? $product : wc_get_product( (int) $product );
		if ( ! $product ) {
			return;
		}
		$id = (int) $product->get_id();
		if ( null === self::$run ) {
			self::$run = array(
				'fields'   => $edit['fields'],
				'refused'  => $edit['refused'],
				'products' => 0,
				'changed'  => array(),
				'done'     => array(),
				'same'     => array(),
				'skipped'  => array(),
				'denied'   => array(),
			);
		}
		self::$run['products']++;
		// Each product, as WooCommerce asks for its own save.
		if ( ! current_user_can( 'edit_post', $id ) ) {
			self::$run['denied'][] = $id;
			return;
		}
		foreach ( $edit['fields'] as $field => $change ) {
			$why = self::skip( $field, $change, $product, $edit['fields'] );
			if ( '' !== $why ) {
				self::$run['skipped'][ $field ][ $why ][] = $id;
				continue;
			}
			$before = self::stored( $id, $field );
			self::apply( $field, $change, $id );
			if ( self::stored( $id, $field ) !== $before ) {
				self::$run['done'][ $field ]  = ( self::$run['done'][ $field ] ?? 0 ) + 1;
				self::$run['changed'][ $id ] = true;
			} else {
				self::$run['same'][ $field ] = ( self::$run['same'][ $field ] ?? 0 ) + 1;
			}
		}
	}

	/**
	 * What a field keeps of a product, by which a change is told.
	 *
	 * @param int    $id    The product.
	 * @param string $field The field.
	 * @return array<string,mixed>
	 */
	private static function stored( $id, $field ) {
		$out = array();
		foreach ( self::fields()[ $field ]['metas'] as $meta ) {
			$out[ $meta ] = get_post_meta( $id, $meta, true );
		}
		return $out;
	}

	/**
	 * Why a change cannot apply to a product, or '' when it can. Judged by
	 * what the product holds as each field comes, so by the fields this edit
	 * changed before it.
	 *
	 * - Listing type Auction: not a variable product (eBay lists no auction
	 *   with variations), and only where the product's duration after this
	 *   edit - the one the edit sets, else its own, else the shop's - is one
	 *   an auction takes: the push refuses an auction of any other.
	 * - Listing duration: one the product's format takes
	 *   (TCGiant_Sync_Catalog::DURATIONS_BY_TYPE, which the push checks): its
	 *   own format, else the shop's.
	 * - Professional grader: one of its item type's graders, on a product
	 *   graded (its own, else the shop's Graded or Ungraded), as the tab
	 *   offers it.
	 * - Graded or Ungraded: not an All Other item, which takes a plain
	 *   condition; the Condition list: only an All Other item, as the tab
	 *   shows it.
	 * - eBay price, Set or Clear: not a variable product, which has no eBay
	 *   price of its own - each variation has its own, and its eBay tab no
	 *   product-level field (as WooCommerce's own price bulk edit leaves it).
	 * - Buy It Now and reserve, set: a product its eBay tab shows them for
	 *   (TCGiant_Sync_Listing_Options::auction_fields_shown()): not a variable
	 *   product, and an auction by its format after the edit.
	 * - Second category, set: not the product's own eBay category, as its
	 *   push sends it (TCGiant_Sync_Exporter::get_export_settings()).
	 *
	 * A clear of the product's own value (SHOP), Clear and an untick are
	 * never skipped, but for the eBay price of a variable product: they only
	 * take the product's own value away.
	 *
	 * @param string     $field   The field.
	 * @param array      $change  Its change.
	 * @param WC_Product $product The product.
	 * @param array      $edit    Every field's change in this edit, by field.
	 * @return string
	 */
	private static function skip( $field, array $change, $product, array $edit = array() ) {
		$id       = (int) $product->get_id();
		$variable = $product->is_type( 'variable' );
		$takes    = self::SHOP !== $change['choice'] && self::CLEAR !== $change['choice'];
		switch ( $field ) {
			case 'listing_type':
				if ( ! $takes || 'Chinese' !== $change['value'] ) {
					return '';
				}
				if ( $variable ) {
					return 'variable_auction';
				}
				return in_array( self::duration_after( $id, $edit ), TCGiant_Sync_Catalog::DURATIONS_BY_TYPE['Chinese'], true ) ? '' : 'auction_duration';
			case 'listing_duration':
				if ( ! $takes ) {
					return '';
				}
				$format = (string) get_post_meta( $id, self::TAB_KEYS['listing_type'], true );
				$format = '' !== $format ? $format : (string) ( self::shop()['export_listing_type'] ?? 'FixedPriceItem' );
				$valid  = TCGiant_Sync_Catalog::DURATIONS_BY_TYPE[ $format ] ?? array( 'GTC' );
				if ( in_array( $change['value'], $valid, true ) ) {
					return '';
				}
				return 'Chinese' === $format ? 'duration_auction' : 'duration_fixed';
			case 'grader':
				if ( ! $takes ) {
					return '';
				}
				$item_type = (string) get_post_meta( $id, self::TAB_KEYS['item_type'], true );
				$of        = isset( self::flipped( TCGiant_Sync_Catalog::GRADERS_TCG )[ $change['value'] ] ) ? 'tcg' : 'coins';
				if ( $of !== $item_type ) {
					return 'grader_' . $of;
				}
				$graded = (string) get_post_meta( $id, self::TAB_KEYS['condition_type'], true );
				$graded = '' !== $graded ? $graded : (string) ( self::shop()['export_condition_type'] ?? '' );
				return 'graded' === $graded ? '' : 'not_graded';
			case 'condition_type':
				return $takes && 'other' === (string) get_post_meta( $id, self::TAB_KEYS['item_type'], true ) ? 'all_other' : '';
			case 'condition_id':
				return $takes && 'other' !== (string) get_post_meta( $id, self::TAB_KEYS['item_type'], true ) ? 'not_all_other' : '';
			case 'ebay_price':
				return $variable ? 'variable_price' : '';
			case 'buy_it_now':
			case 'reserve':
				if ( ! $takes ) {
					return '';
				}
				if ( $variable ) {
					return 'variable';
				}
				return TCGiant_Sync_Listing_Options::auction_fields_shown( $product, (string) ( self::shop()['export_listing_type'] ?? 'FixedPriceItem' ) ) ? '' : 'not_auction';
			case 'second_category':
				if ( ! $takes ) {
					return '';
				}
				$primary = class_exists( 'TCGiant_Sync_Exporter' ) ? (string) ( TCGiant_Sync_Exporter::instance()->get_export_settings( $id )['category_id'] ?? '' ) : '';
				return '' !== $primary && $primary === (string) $change['value'] ? 'primary' : '';
		}
		return '';
	}

	/**
	 * The listing duration a product will have once this edit is done: the
	 * one the edit sets, else, for a clear, the shop's, else its own, else
	 * the shop's - as its push reads them (Good 'Til Cancelled when the shop
	 * has none).
	 *
	 * @param int   $id   The product.
	 * @param array $edit Every field's change in this edit, by field.
	 * @return string
	 */
	private static function duration_after( $id, array $edit ) {
		$shop = (string) ( self::shop()['export_listing_duration'] ?? '' );
		$shop = '' !== $shop ? $shop : 'GTC';
		if ( isset( $edit['listing_duration'] ) ) {
			return self::SHOP === $edit['listing_duration']['choice'] ? $shop : (string) $edit['listing_duration']['value'];
		}
		$own = (string) get_post_meta( $id, self::TAB_KEYS['listing_duration'], true );
		return '' !== $own ? $own : $shop;
	}

	/**
	 * The shop's settings.
	 *
	 * @return array
	 */
	private static function shop() {
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * One field's change saved to a product, through the eBay tab's own
	 * save, posted in the tab's own names: that field alone, so nothing else
	 * of the product's is touched.
	 *
	 * @param string $field  The field.
	 * @param array  $change Its change.
	 * @param int    $id     The product.
	 */
	private static function apply( $field, array $change, $id ) {
		if ( isset( self::TAB_KEYS[ $field ] ) ) {
			// The tab's save of the eBay tab's steps 1 to 4; a grader posted
			// without an item type is kept as it is posted.
			$posted = array( self::TAB_KEYS[ $field ] => $change['value'] );
			if ( 'item_type' === $field ) {
				$posted += self::item_type_posted( $id, (string) $change['value'] );
			}
			TCGiant_Sync_Admin::save_export_posted( $id, $posted );
			return;
		}
		switch ( $field ) {
			case 'category':
				// The category it has, chosen as it was (from the list, or
				// typed): left alone, its name with it, as the tab keeps it
				// when its select and box are not changed.
				if ( '' !== $change['value'] && (string) get_post_meta( $id, '_ebay_export_category_id', true ) === $change['value'] && (string) get_post_meta( $id, '_ebay_export_category_typed', true ) === ( self::TYPED === $change['select'] ? $change['value'] : '' ) ) {
					return;
				}
				// As the tab posts a category: from its list, or typed in its
				// box (kept as typed, _ebay_export_category_typed), or none,
				// with the name the tab clears when its select or box changes.
				TCGiant_Sync_Admin::save_export_posted( $id, array(
					'_ebay_export_category_id_select' => $change['select'],
					'_ebay_export_category_id_custom' => self::TYPED === $change['select'] ? $change['value'] : '',
					'_ebay_export_category_name'      => '',
				) );
				return;
			case 'ebay_price':
				TCGiant_Sync_Ebay_Price::save_posted( $id, array(
					TCGiant_Sync_Ebay_Price::OWN_META . '_shown' => '1',
					TCGiant_Sync_Ebay_Price::OWN_META            => $change['value'],
				), TCGiant_Sync_Ebay_Price::OWN_META );
				return;
			case 'identifier_none':
				TCGiant_Sync_Identifiers::set_none( $id, '1' === $change['value'] );
				return;
		}
		$posted = array(
			TCGiant_Sync_Listing_Options::SUBTITLE_META . '_shown' => '1',
			TCGiant_Sync_Listing_Options::METAS[ $field ]           => $change['value'],
		);
		if ( 'second_category' === $field ) {
			$posted[ TCGiant_Sync_Listing_Options::SECOND_CATEGORY_NAME_META ] = $change['name'];
		}
		TCGiant_Sync_Listing_Options::save_posted( $id, $posted );
	}

	/**
	 * What the eBay tab posts beside an item type of Trading Cards or Coins
	 * chosen in place of another: it draws each type's grader, grade and
	 * ungraded condition selects with the product's values only for the type
	 * the product holds, empty for any other, so its save of the new type
	 * writes that type's three empty - another type's grader, grade and
	 * condition never stay with it, nor go with its next push. Nothing for
	 * the type a product already holds, All Other, or none.
	 *
	 * @param int    $id  The product.
	 * @param string $new The item type set.
	 * @return array<string,string> The tab's names, posted empty.
	 */
	private static function item_type_posted( $id, $new ) {
		if ( ! in_array( $new, array( 'tcg', 'coins' ), true ) || (string) get_post_meta( $id, self::TAB_KEYS['item_type'], true ) === $new ) {
			return array();
		}
		return array(
			'_ebay_export_grader_id_' . $new          => '',
			'_ebay_export_grade_value_' . $new        => '',
			'_ebay_export_ungraded_condition_' . $new => '',
		);
	}

	/**
	 * bulk_edit_posts (WordPress 6.3), else shutdown: the bulk edit done.
	 * One log line, and the notice's record for the products list, once.
	 * Nothing when no bulk edit of the group ran in this request.
	 */
	public static function finish_bulk_edit() {
		if ( null === self::$run ) {
			return;
		}
		$said      = self::said( self::$run );
		self::$run = null;
		TCGiant_Sync_Logger::log( self::log_line( $said ) );
		set_transient( self::SAID . get_current_user_id(), $said, self::SAID_SECONDS );
	}

	/**
	 * What a bulk edit did, as the notice and the log say it: counts, and
	 * the first IDS_SHOWN product IDs of each list.
	 *
	 * @param array $run The run.
	 * @return array
	 */
	private static function said( array $run ) {
		$fields = array();
		foreach ( $run['fields'] as $field => $change ) {
			$skipped = array();
			foreach ( $run['skipped'][ $field ] ?? array() as $why => $ids ) {
				$skipped[ $why ] = array( 'n' => count( $ids ), 'ids' => array_slice( $ids, 0, self::IDS_SHOWN ) );
			}
			$fields[ $field ] = array(
				'done'    => self::done_words( $field, $change ),
				'changed' => (int) ( $run['done'][ $field ] ?? 0 ),
				'same'    => (int) ( $run['same'][ $field ] ?? 0 ),
				'skipped' => $skipped,
			);
		}
		return array(
			'products' => (int) $run['products'],
			'changed'  => count( $run['changed'] ),
			'fields'   => $fields,
			'refused'  => $run['refused'],
			'denied'   => array( 'n' => count( $run['denied'] ), 'ids' => array_slice( $run['denied'], 0, self::IDS_SHOWN ) ),
		);
	}

	// -------------------------------------------------------------------------
	// What is said
	// -------------------------------------------------------------------------

	/**
	 * Why a product was skipped for a field, in words.
	 *
	 * @param string $why The reason.
	 * @return string
	 */
	private static function why_words( $why ) {
		$words = array(
			'variable_auction' => __( 'eBay lists no auction of a product with variations', 'tcgiant-sync' ),
			'auction_duration' => __( 'its listing duration would not be one an auction takes (1, 3, 5, 7 or 10 days): set Listing duration in the same edit', 'tcgiant-sync' ),
			'duration_fixed'   => __( 'its format is Fixed Price, which runs until cancelled', 'tcgiant-sync' ),
			'duration_auction' => __( 'its format is Auction, which runs 1, 3, 5, 7 or 10 days', 'tcgiant-sync' ),
			'grader_tcg'       => __( 'its item type is not Trading Cards', 'tcgiant-sync' ),
			'grader_coins'     => __( 'its item type is not Coins', 'tcgiant-sync' ),
			'not_graded'       => __( 'it is not set to Graded', 'tcgiant-sync' ),
			'all_other'        => __( 'it is an All Other item, which takes a plain condition', 'tcgiant-sync' ),
			'not_all_other'    => __( 'its item type is not All Other', 'tcgiant-sync' ),
			'variable_price'   => __( 'a variable product\'s eBay price is set on each variation', 'tcgiant-sync' ),
			'variable'         => __( 'a variable product is never an auction', 'tcgiant-sync' ),
			'not_auction'      => __( 'its format is not Auction', 'tcgiant-sync' ),
			'primary'          => __( 'it is the product\'s own eBay category', 'tcgiant-sync' ),
		);
		return $words[ $why ] ?? $why;
	}

	/**
	 * Why no product could take a field's value, in words.
	 *
	 * @param string $why   The reason.
	 * @param string $typed What was typed.
	 * @return string
	 */
	private static function refused_words( $why, $typed ) {
		switch ( $why ) {
			case 'empty':
				return __( 'nothing was typed (choose Clear to empty it)', 'tcgiant-sync' );
			case 'not_price':
				/* translators: %s: what was typed */
				return sprintf( __( '"%s" is not a price above 0', 'tcgiant-sync' ), $typed );
			case 'not_category':
				/* translators: %s: what was typed */
				return sprintf( __( '"%s" is not an eBay category ID', 'tcgiant-sync' ), $typed );
		}
		return __( 'it is not one of its choices', 'tcgiant-sync' );
	}

	/**
	 * Product IDs, the first IDS_SHOWN of them and how many more.
	 *
	 * @param int[] $ids Shown.
	 * @param int   $n   How many in all.
	 * @return string
	 */
	private static function ids_words( array $ids, $n ) {
		$list = '#' . implode( ', #', array_map( 'intval', $ids ) );
		if ( $n > count( $ids ) ) {
			/* translators: 1: product IDs, 2: how many more */
			$list = sprintf( __( '%1$s and %2$d more', 'tcgiant-sync' ), $list, $n - count( $ids ) );
		}
		return $list;
	}

	/**
	 * The lines a bulk edit's record says, field by field.
	 *
	 * @param array $said The record (said()).
	 * @return string[]
	 */
	private static function lines( array $said ) {
		$labels = array_map( function ( $def ) {
			return $def['label'];
		}, self::fields() );
		$lines  = array();
		foreach ( $said['fields'] as $field => $f ) {
			$parts = array();
			/* translators: 1: what was done ("set", "cleared", "Auction"), 2: how many products */
			$parts[] = sprintf( _n( '%1$s on %2$d product', '%1$s on %2$d products', $f['changed'], 'tcgiant-sync' ), $f['done'], $f['changed'] );
			if ( $f['same'] > 0 ) {
				/* translators: %d: how many products */
				$parts[] = sprintf( _n( '%d already so', '%d already so', $f['same'], 'tcgiant-sync' ), $f['same'] );
			}
			foreach ( $f['skipped'] as $why => $s ) {
				/* translators: 1: how many products, 2: why, 3: their IDs */
				$parts[] = sprintf( _n( 'skipped on %1$d - %2$s: %3$s', 'skipped on %1$d - %2$s: %3$s', $s['n'], 'tcgiant-sync' ), $s['n'], self::why_words( $why ), self::ids_words( $s['ids'], $s['n'] ) );
			}
			$lines[] = $labels[ $field ] . ': ' . implode( '; ', $parts ) . '.';
		}
		foreach ( $said['refused'] as $field => $r ) {
			/* translators: 1: the field, 2: why */
			$lines[] = sprintf( __( '%1$s: not changed - %2$s.', 'tcgiant-sync' ), $labels[ $field ] ?? $field, self::refused_words( (string) $r[0], (string) $r[1] ) );
		}
		if ( $said['denied']['n'] > 0 ) {
			/* translators: 1: how many products, 2: their IDs */
			$lines[] = sprintf( _n( '%1$d product not changed: you cannot edit it (%2$s).', '%1$d products not changed: you cannot edit them (%2$s).', $said['denied']['n'], 'tcgiant-sync' ), $said['denied']['n'], self::ids_words( $said['denied']['ids'], $said['denied']['n'] ) );
		}
		return $lines;
	}

	/**
	 * The log's one line for a bulk edit: the counts and the fields.
	 *
	 * @param array $said The record (said()).
	 * @return string
	 */
	public static function log_line( array $said ) {
		return sprintf(
			'Bulk edit of eBay fields (WooCommerce Bulk Edit, user #%1$d): %2$d of %3$d products changed. %4$s These fields were not sent to eBay. A stock left at No change is sent only for a product whose last stock change or website sale never reached eBay.',
			get_current_user_id(),
			$said['changed'],
			$said['products'],
			implode( ' ', self::lines( $said ) )
		);
	}

	/**
	 * admin_notices, on the products list: what the last bulk edit of the
	 * group did, once, with where its listings go from here.
	 */
	public static function bulk_edit_notice() {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		$said = get_transient( self::SAID . get_current_user_id() );
		if ( ! is_array( $said ) || ! isset( $said['fields'], $said['refused'], $said['denied'] ) ) {
			return;
		}
		// Shown once.
		delete_transient( self::SAID . get_current_user_id() );
		$warn = $said['denied']['n'] > 0 || $said['refused'];
		foreach ( $said['fields'] as $f ) {
			$warn = $warn || ! empty( $f['skipped'] );
		}
		echo '<div class="notice ' . ( $warn ? 'notice-warning' : 'notice-success' ) . ' is-dismissible"><p><strong>'
			. esc_html( sprintf(
				/* translators: 1: products changed, 2: products in the bulk edit */
				_n( 'eBay fields (TCGiant Sync): %1$d of %2$d product changed.', 'eBay fields (TCGiant Sync): %1$d of %2$d products changed.', $said['products'], 'tcgiant-sync' ),
				$said['changed'],
				$said['products']
			) )
			. '</strong></p><ul style="list-style:disc;margin-left:2em;">';
		foreach ( self::lines( $said ) as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'These fields were not sent to eBay: a product linked to an eBay listing takes the change there at its next push or Update. A stock left at No change is sent only for a product whose last stock change or website sale never reached eBay.', 'tcgiant-sync' )
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=tcgiant-listings' ) ) . '">' . esc_html__( 'Listings', 'tcgiant-sync' ) . '</a></p></div>';
	}

	// -------------------------------------------------------------------------
	// The group
	// -------------------------------------------------------------------------

	/**
	 * woocommerce_product_bulk_edit_end: the "eBay (TCGiant Sync)" group, at
	 * the end of WooCommerce's Bulk Edit fields, every field at No change.
	 */
	public static function render_bulk_edit() {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		$no_change = __( '— No change —', 'tcgiant-sync' );
		// A clear of the product's own value: what then goes is the shop's
		// setting, or eBay's own for an imported listing that keeps its
		// values. An item type has no shop setting: none.
		$shop      = __( 'Clear the product\'s own (the shop\'s, or eBay\'s if imported)', 'tcgiant-sync' );
		$none      = __( 'None (clear)', 'tcgiant-sync' );
		$list      = self::categories();
		?>
		<div id="tcgiant-bulk-edit" class="tcgiant-bulk-edit" style="clear:both;padding-top:6px;">
			<h4><?php esc_html_e( 'eBay (TCGiant Sync)', 'tcgiant-sync' ); ?></h4>
			<p class="tcgiant-bulk-intro" style="margin:0 0 6px;font-size:12px;color:#555;"><?php esc_html_e( 'Each field set here changes the selected products\' eBay tab; one left at No change keeps each product\'s own. A value a product cannot take is skipped for it, and the notice after says which. This group sends nothing to eBay: a product linked to an eBay listing takes the change at its next push or Update.', 'tcgiant-sync' ); ?></p>
			<p class="tcgiant-bulk-scope" style="margin:0 0 6px;font-size:12px;color:#555;"><?php esc_html_e( 'A bulk edit changes the products ticked on this page of the list (Screen Options sets how many a page shows). WooCommerce\'s own Stock field, when it changes a stock, sends it to a linked listing as any stock change does; at No change it sends nothing, unless the product\'s last stock change or website sale never reached eBay: then its stock is sent.', 'tcgiant-sync' ); ?></p>
			<?php foreach ( self::fields() as $field => $def ) : ?>
				<?php $name = self::PREFIX . $field; ?>
				<div class="inline-edit-group tcgiant-bulk-field">
					<label class="alignleft">
						<span class="title"><?php echo esc_html( $def['label'] ); ?></span>
						<span class="input-text-wrap">
							<select class="tcgiant-bulk-change" name="<?php echo esc_attr( $name ); ?>">
								<option value=""><?php echo esc_html( $no_change ); ?></option>
								<?php if ( 'select' === $def['kind'] ) : ?>
									<?php foreach ( self::choices( $field ) as $group => $options ) : ?>
										<?php if ( '' !== (string) $group ) : ?>
											<optgroup label="<?php echo esc_attr( (string) $group ); ?>">
										<?php endif; ?>
										<?php foreach ( $options as $value => $label ) : ?>
											<option value="<?php echo esc_attr( (string) $value ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
										<?php if ( '' !== (string) $group ) : ?>
											</optgroup>
										<?php endif; ?>
									<?php endforeach; ?>
									<option value="<?php echo esc_attr( self::SHOP ); ?>"><?php echo esc_html( 'item_type' === $field ? $none : $shop ); ?></option>
								<?php elseif ( 'category' === $def['kind'] || 'second' === $def['kind'] ) : ?>
									<?php foreach ( $list as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label . ' (' . $value . ')' ); ?></option>
									<?php endforeach; ?>
									<option value="<?php echo esc_attr( self::TYPED ); ?>"><?php esc_html_e( 'A category ID typed below', 'tcgiant-sync' ); ?></option>
									<?php if ( 'category' === $def['kind'] ) : ?>
										<option value="<?php echo esc_attr( self::SHOP ); ?>"><?php echo esc_html( $shop ); ?></option>
									<?php else : ?>
										<option value="<?php echo esc_attr( self::CLEAR ); ?>"><?php esc_html_e( 'Clear', 'tcgiant-sync' ); ?></option>
									<?php endif; ?>
								<?php elseif ( 'tick' === $def['kind'] ) : ?>
									<option value="yes"><?php esc_html_e( 'Ticked: it has none (eBay\'s "Does not apply" where required)', 'tcgiant-sync' ); ?></option>
									<option value="no"><?php esc_html_e( 'Unticked', 'tcgiant-sync' ); ?></option>
								<?php else : ?>
									<option value="<?php echo esc_attr( self::SET ); ?>"><?php esc_html_e( 'Set to:', 'tcgiant-sync' ); ?></option>
									<option value="<?php echo esc_attr( self::CLEAR ); ?>"><?php esc_html_e( 'Clear', 'tcgiant-sync' ); ?></option>
								<?php endif; ?>
							</select>
						</span>
					</label>
					<?php if ( in_array( $def['kind'], array( 'category', 'second' ), true ) ) : ?>
						<label class="tcgiant-bulk-input" style="display:none;">
							<input type="text" class="text" name="<?php echo esc_attr( $name . '_value' ); ?>" value="" placeholder="<?php esc_attr_e( 'eBay leaf category ID', 'tcgiant-sync' ); ?>">
						</label>
					<?php elseif ( 'note' === $def['kind'] ) : ?>
						<label class="tcgiant-bulk-input" style="display:none;">
							<textarea name="<?php echo esc_attr( $name . '_value' ); ?>" rows="3" maxlength="<?php echo (int) TCGiant_Sync_Listing_Options::CONDITION_NOTE_MAX; ?>"></textarea>
						</label>
					<?php elseif ( in_array( $def['kind'], array( 'text', 'price' ), true ) ) : ?>
						<?php
						$max = array(
							'subtitle' => TCGiant_Sync_Listing_Options::SUBTITLE_MAX,
							'brand'    => TCGiant_Sync_Listing_Options::SPECIFIC_MAX,
							'mpn'      => TCGiant_Sync_Listing_Options::SPECIFIC_MAX,
						);
						?>
						<label class="tcgiant-bulk-input" style="display:none;">
							<input type="text" class="text" name="<?php echo esc_attr( $name . '_value' ); ?>" value=""<?php echo isset( $max[ $field ] ) ? ' maxlength="' . (int) $max[ $field ] . '"' : ''; ?>>
						</label>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<p class="tcgiant-bulk-fees" style="clear:both;margin:6px 0 0;font-size:11px;color:#8a6d3b;"><?php esc_html_e( 'eBay charges a fee for a subtitle, a second category (a second insertion fee, and the listing\'s upgrade fees again) and a reserve price, set by your eBay site, for each listing that has one: a subtitle or second category when a fixed-price listing is updated with it, or a listing is relisted or listed new with it; a reserve price when an auction is listed or relisted, never at an Update. Filling one in here on many products charges it for each of them. On some eBay sites a Good \'Til Cancelled listing pays for its subtitle again at each monthly renewal. eBay does not refund a fee if you remove the field.', 'tcgiant-sync' ); ?></p>
			<p class="tcgiant-bulk-rules" style="margin:4px 0 0;font-size:11px;color:#888;"><?php esc_html_e( 'Buy It Now and reserve go only on a single product whose format is Auction, after this edit; a duration only where its format takes it; a grader only on a graded item of its own type. The fields apply in this order, so set Listing type here to set an auction\'s prices with it. Auction goes only where the product\'s duration after this edit is an auction\'s (1, 3, 5, 7 or 10 days): set Listing duration with it.', 'tcgiant-sync' ); ?></p>
		</div>
		<script>
		jQuery( function ( $ ) {
			var box = $( '#tcgiant-bulk-edit' );
			// Set to, and a typed category ID, show the box beside them.
			box.on( 'change', 'select.tcgiant-bulk-change', function () {
				var v = $( this ).val();
				$( this ).closest( '.tcgiant-bulk-field' ).find( '.tcgiant-bulk-input' ).toggle( '<?php echo esc_js( self::SET ); ?>' === v || '<?php echo esc_js( self::TYPED ); ?>' === v );
			} );
			// Each Bulk Edit opened starts at No change.
			$( '#wpbody' ).on( 'click', '#doaction, #doaction2', function () {
				box.find( 'select.tcgiant-bulk-change' ).val( '' );
				box.find( 'input.text, textarea' ).val( '' );
				box.find( '.tcgiant-bulk-input' ).hide();
			} );
		} );
		</script>
		<?php
	}
}
