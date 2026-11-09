<?php
/**
 * The eBay shop's own categories, and which WooCommerce category goes in which.
 *
 * A shop whose website is its master pushed every product to "Other" in its
 * eBay shop: build_item_xml() sent no Storefront. And an import matched a shop
 * category to WooCommerce by its name alone, so two shop categories of the
 * same name under different parents landed in one term.
 *
 * The tree. One cached read of GetStore gives each shop category's id, name,
 * parent, full path ("English Single Cards > Scarlet & Violet") and whether it
 * has children. Everything that reads the shop's categories reads this: the
 * import, its category filter, the Settings screen's Load eBay Categories, a
 * push and the Shop Categories page. So there is one GetStore an hour, and no
 * more for any number of listings - or one a minute while eBay gives nothing, as
 * the old map's transient allowed: an empty read kept for an hour lost every
 * category imported in it - beside what a person asks for: Refresh from eBay,
 * Load eBay Categories, and a Full Sync whose filter names categories, which
 * read eBay afresh. A good tree stays until eBay has answered. It has a transient
 * of its own, so the old flat list can never be read as a tree.
 *
 * The map. term_id => shop category id, one option, not autoloaded. A product
 * goes in the nearest mapped term of each of its categories, so mapping a
 * parent covers its children unless a child has a choice of its own; two at
 * most, as eBay takes two, deepest first, then lowest term_id, so that repeated
 * Updates never swap them.
 *
 * What eBay says of a Storefront (StorefrontType, read 2026-11-06): an item
 * goes in a root-level category or one with no subcategories, and one sent a
 * category with subcategories goes in "Other" with a Warning in the answer.
 * The page also says the API takes only root-level categories, which the rule
 * contradicts: any category with no children counts as good here, as the worst
 * of that is where every push landed before.
 *
 * Category ids are eBay's "long": they stay strings of digits, never ints.
 * They are array keys only where PHP makes them so, and each row of the tree
 * keeps its own 'id' as a string.
 *
 * What the plugin knows of a shop category does not stop a push. When eBay
 * could not be asked the id stored is sent unchecked; with a tree to hand, an id
 * it no longer has, or says has children, is left out and said once, as is every
 * id when eBay says the account has no Store or no categories. And if eBay
 * refuses an item for its Storefront alone, and plainly made nothing, the item
 * goes once more without it, and that id is held back for an hour (fix round
 * 1): nothing documents such a refusal, but it would otherwise cost every mapped
 * push in the shop. A refusal of the 0 that clears a second category holds no id
 * (fix round 2): only the 0 is left out, for the hour.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Shop_Categories class
 */
class TCGiant_Sync_Shop_Categories {

	/**
	 * The tree, kept an hour, or a minute when eBay gave nothing. Not the
	 * transient 3.32.1 kept its flat id => name list in, which may still be
	 * there for up to an hour after the update.
	 */
	const TREE_TRANSIENT = 'tcgiant_sync_store_cat_tree';

	/**
	 * What a tree says it is. A value without it is not one, whatever it
	 * holds.
	 */
	const TREE_SCHEMA = 1;

	/**
	 * The map: term_id => shop category id. Not autoloaded.
	 */
	const MAP_OPTION = 'tcgiant_sync_shop_category_map';

	/**
	 * The Shop Categories screen.
	 */
	const PAGE = 'tcgiant-shop-categories';

	/**
	 * Its Save, and the one hidden field the whole map goes in: PHP's
	 * max_input_vars silently cuts a large form, and a cut form would lose
	 * mappings.
	 */
	const SAVE_FORM = 'tcgiant_shop_categories_save';
	const MAP_FIELD = 'shop_category_map';

	/**
	 * Its Refresh from eBay.
	 */
	const REFRESH_LINK = 'tcgiant_shop_categories_refresh';

	/**
	 * Its way to stop sending the choices when there is no form to save.
	 */
	const CLEAR_FORM = 'tcgiant_shop_categories_clear';

	/**
	 * eBay's own "Other", where a listing with no shop category goes.
	 */
	const OTHER_ID = '1';

	/**
	 * How many shop categories eBay takes for one listing.
	 */
	const MOST = 2;

	/**
	 * eBay's code for a category with subcategories, from its list of error
	 * messages (read 2026-11-06). No live answer has shown it, so the words
	 * are read as well (warnings_in()).
	 */
	const NON_LEAF_WARNING = '20169';

	/**
	 * eBay's answers to GetStore for an account with no Store, by code, from
	 * its list of error messages: eBay said so, which is not the same as not
	 * being asked.
	 */
	const NO_STORE_CODES = array( '319', '17721' );

	/**
	 * An id eBay refused an item for, held back for an hour, one transient
	 * each: a bulk push does not pay twice per listing, and the shop's other
	 * categories go on being sent.
	 */
	const HELD_TRANSIENT = 'tcgiant_sync_store_cat_held_';
	const HELD_SECONDS   = 3600;

	/**
	 * eBay refused the 0 a revise clears a second category with, so no revise
	 * sends it for HELD_SECONDS (3.33.0 fix round 2). Nothing documents that it
	 * is taken, and only a live Update can show it: Verify never sends it.
	 */
	const NO_ZERO_TRANSIENT = 'tcgiant_sync_store_cat_no_zero';

	/**
	 * Add the screen's two actions.
	 */
	public static function register_admin() {
		add_action( 'admin_post_tcgiant_shop_categories_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_tcgiant_shop_categories_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_post_tcgiant_shop_categories_clear', array( __CLASS__, 'handle_clear' ) );
	}

	// -------------------------------------------------------------------------
	// The tree
	// -------------------------------------------------------------------------

	/**
	 * The shop's categories, from the transient or, when it is empty, one
	 * GetStore.
	 *
	 * @param bool $refresh Read eBay whatever the transient holds. If eBay
	 *                      cannot be asked, a good tree already held stays,
	 *                      and comes back with why as refresh_error.
	 * @return array schema; categories, id => row, in eBay's order, each row
	 *               id, name, parent ('' at the top), levels (the names from
	 *               the top down) and children (bool); error, eBay's or the
	 *               request's words when it could not be read; none, whether
	 *               eBay said there is no Store or nothing in it.
	 */
	public static function tree( $refresh = false ) {
		$held = get_transient( self::TREE_TRANSIENT );
		$good = self::is_tree( $held );
		if ( $good && ! $refresh ) {
			return $held;
		}

		$tree = self::read_tree();

		// Asked again and not answered: what was read earlier still stands. An
		// import in the minute after a failed Refresh would lose its categories.
		if ( $good && self::usable( $held ) && ! self::usable( $tree ) && empty( $tree['none'] ) ) {
			$held['refresh_error'] = (string) $tree['error'];
			return $held;
		}

		// A failed or empty read is kept only briefly: for an hour it would
		// lose the categories of everything imported in it. And it stops a
		// bulk push asking again for every product.
		set_transient( self::TREE_TRANSIENT, $tree, empty( $tree['categories'] ) ? 60 : 3600 );

		return $tree;
	}

	/**
	 * Whether a value is a tree, and not anything else a transient could hold.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_tree( $value ) {
		return is_array( $value )
			&& self::TREE_SCHEMA === ( $value['schema'] ?? null )
			&& isset( $value['categories'] )
			&& is_array( $value['categories'] );
	}

	/**
	 * Whether a tree has anything in it to check an id against.
	 *
	 * @param array $tree tree().
	 * @return bool
	 */
	public static function usable( array $tree ) {
		return ! empty( $tree['categories'] );
	}

	/**
	 * One GetStore, flattened.
	 *
	 * @return array See tree().
	 */
	private static function read_tree() {
		$tree   = array( 'schema' => self::TREE_SCHEMA, 'categories' => array(), 'error' => '', 'none' => false );
		$answer = TCGiant_Sync_API::instance()->get_store();

		if ( is_wp_error( $answer ) ) {
			$tree['error'] = (string) $answer->get_error_message();
			$tree['none']  = (bool) array_intersect( TCGiant_Sync_API::error_codes( $answer ), self::NO_STORE_CODES );
			return $tree;
		}

		if ( is_array( $answer ) && isset( $answer['Store']['CustomCategories']['CustomCategory'] ) ) {
			self::walk( $answer['Store']['CustomCategories']['CustomCategory'], '', array(), $tree['categories'] );
		}

		$tree['none'] = is_array( $answer ) && empty( $tree['categories'] );

		return $tree;
	}

	/**
	 * Forget the tree: another eBay account has another Store.
	 */
	public static function forget_tree() {
		delete_transient( self::TREE_TRANSIENT );
	}

	/**
	 * Add a level of eBay's categories, and those under them, to the rows.
	 *
	 * @param mixed  $nodes  One category, or a list of them: eBay's XML gives
	 *                       a single child as the child itself.
	 * @param string $parent Their parent's id.
	 * @param array  $levels The names above them.
	 * @param array  $rows   The rows so far.
	 */
	private static function walk( $nodes, $parent, array $levels, array &$rows ) {
		foreach ( self::list_of( $nodes ) as $node ) {
			$id   = trim( TCGiant_Sync_API::scalar_value( $node['CategoryID'] ?? '' ) );
			$name = TCGiant_Sync_API::scalar_value( $node['Name'] ?? '' );
			$kids = self::list_of( $node['ChildCategory'] ?? array() );

			if ( 1 !== preg_match( '/^\d+$/', $id ) || isset( $rows[ $id ] ) ) {
				continue;
			}

			$line        = array_merge( $levels, array( $name ) );
			$rows[ $id ] = array(
				'id'       => $id,
				'name'     => $name,
				'parent'   => (string) $parent,
				'levels'   => $line,
				'children' => ! empty( $kids ),
			);

			self::walk( $kids, $id, $line, $rows );
		}
	}

	/**
	 * eBay's categories at one level, as a list, whichever way the XML came.
	 *
	 * @param mixed $nodes One category, or a list.
	 * @return array
	 */
	private static function list_of( $nodes ) {
		if ( ! is_array( $nodes ) ) {
			return array();
		}

		return array_filter( isset( $nodes['CategoryID'] ) ? array( $nodes ) : $nodes, 'is_array' );
	}

	/**
	 * A shop category's row.
	 *
	 * @param mixed      $id   Its id.
	 * @param array|null $tree tree(), when it is in hand.
	 * @return array|null
	 */
	public static function find( $id, $tree = null ) {
		$tree = is_array( $tree ) ? $tree : self::tree();

		return $tree['categories'][ TCGiant_Sync_API::scalar_value( $id ) ] ?? null;
	}

	/**
	 * A row's full path, as the shop sees it on eBay.
	 *
	 * @param array $row A row of the tree.
	 * @return string
	 */
	public static function path( array $row ) {
		return implode( ' > ', (array) ( $row['levels'] ?? array() ) );
	}

	/**
	 * Whether a row is eBay's "Other": by its id, or by the name at the top
	 * level. "Pokemon > Other" is the shop's own category, and may be chosen.
	 *
	 * @param array $row A row of the tree.
	 * @return bool
	 */
	private static function is_other( array $row ) {
		return self::OTHER_ID === (string) ( $row['id'] ?? '' )
			|| ( '' === (string) ( $row['parent'] ?? '' ) && 'other' === self::fold( $row['name'] ?? '' ) );
	}

	/**
	 * Why a shop category is not one to send a listing to: 'other', 'none'
	 * (eBay says there is no Store, or nothing in it), 'gone', 'parent', or ''
	 * when it may go. When eBay could not be asked only what needs no tree is
	 * known, so the id goes unchecked.
	 *
	 * @param string $id   The id.
	 * @param array  $tree tree().
	 * @return string
	 */
	private static function refusal( $id, array $tree ) {
		$id = (string) $id;

		if ( self::OTHER_ID === $id || '' === ltrim( $id, '0' ) ) {
			return 'other';
		}
		if ( ! self::usable( $tree ) ) {
			return empty( $tree['none'] ) ? '' : 'none';
		}

		$row = self::find( $id, $tree );
		if ( null === $row ) {
			return 'gone';
		}
		if ( self::is_other( $row ) ) {
			return 'other';
		}

		return empty( $row['children'] ) ? '' : 'parent';
	}

	/**
	 * A name as it is compared: entities decoded, spaces closed up, and
	 * lower-cased. WordPress stores "&" in a term's name as "&amp;".
	 *
	 * @param string $text A name.
	 * @return string
	 */
	public static function fold( $text ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', self::plain( $text ) ) );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * A name as it reads: a term's, with the entities WordPress stored it
	 * with decoded.
	 *
	 * @param string $text A name.
	 * @return string
	 */
	public static function plain( $text ) {
		return html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Whether a shop category's name is on more than one category in the
	 * shop's tree, ignoring case.
	 *
	 * @param string $name The name.
	 * @param array  $tree tree().
	 * @return bool
	 */
	public static function name_is_duplicated( $name, array $tree ) {
		$folded = self::fold( $name );
		$seen   = 0;

		foreach ( $tree['categories'] as $row ) {
			if ( self::fold( $row['name'] ) === $folded ) {
				$seen++;
			}
		}

		return $seen > 1;
	}

	// -------------------------------------------------------------------------
	// The map
	// -------------------------------------------------------------------------

	/**
	 * The map as it counts: only a term that exists and an id that is digits.
	 * Empty for a shop that has none, which is all such a shop ever pays:
	 * nothing is read of its terms, its products or eBay.
	 *
	 * @return array term_id => id, a string.
	 */
	public static function map() {
		$map = self::stored();
		if ( ! $map ) {
			return array();
		}

		$known = self::term_ids();

		return null === $known ? $map : array_intersect_key( $map, $known );
	}

	/**
	 * The rows of the option that hold an id of digits, without asking which
	 * terms exist: what a product is asked about is its own terms, which do.
	 *
	 * @return array term_id => id, a string.
	 */
	private static function stored() {
		$stored = get_option( self::MAP_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$map = array();
		foreach ( $stored as $term_id => $id ) {
			$term_id = (int) $term_id;
			$id      = is_string( $id ) ? trim( $id ) : '';

			if ( $term_id > 0 && 1 === preg_match( '/^\d+$/', $id ) ) {
				$map[ $term_id ] = $id;
			}
		}

		return $map;
	}

	/**
	 * Which product categories exist, as one query.
	 *
	 * @return array|null term_id => true, or null when WordPress could not say.
	 */
	private static function term_ids() {
		$ids = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) );

		return is_array( $ids ) ? array_fill_keys( array_map( 'intval', $ids ), true ) : null;
	}

	/**
	 * What a product's listing is sent, and what was left out of it.
	 *
	 * For each of the product's categories, the nearest mapped term: the term
	 * itself, then its ancestors. Then the deepest of those first, ties by the
	 * lowest term_id, so repeated Updates never swap them; duplicates dropped;
	 * and what must not be sent left out, before the two eBay takes are
	 * counted. The tree is read only for a product that maps to something.
	 *
	 * @param WC_Product $product  The product. A variation goes by its parent.
	 * @param bool       $held_too Leave in what eBay refused within the hour:
	 *                             Verify asks eBay, and shows its answer.
	 * @return array ids, at most MOST of them; left_out, id => why ('other',
	 *               'none', 'gone', 'parent' or 'held'); mapped, every id the
	 *               product's categories map to, sendable or not; tree, tree()
	 *               or null if not read.
	 */
	public static function plan( $product, $held_too = false ) {
		$plan = array( 'ids' => array(), 'left_out' => array(), 'mapped' => array(), 'tree' => null );
		$map  = self::stored();
		if ( ! $map ) {
			return $plan;
		}

		if ( $product->is_type( 'variation' ) ) {
			$parent  = wc_get_product( $product->get_parent_id() );
			$product = $parent ? $parent : $product;
		}

		$depths = array();
		foreach ( (array) $product->get_category_ids() as $term_id ) {
			$line = array_merge( array( (int) $term_id ), array_map( 'intval', (array) get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) ) );
			foreach ( $line as $step => $candidate ) {
				if ( isset( $map[ $candidate ] ) ) {
					$depths[ $candidate ] = count( $line ) - 1 - $step;
					break;
				}
			}
		}

		if ( ! $depths ) {
			return $plan;
		}

		uksort( $depths, function ( $a, $b ) use ( $depths ) {
			return ( $depths[ $b ] <=> $depths[ $a ] ) ?: ( $a <=> $b );
		} );

		$plan['tree']   = self::tree();
		$plan['mapped'] = array_values( array_unique( array_map( function ( $term_id ) use ( $map ) {
			return $map[ $term_id ];
		}, array_keys( $depths ) ) ) );

		foreach ( array_keys( $depths ) as $term_id ) {
			if ( count( $plan['ids'] ) >= self::MOST ) {
				break;
			}

			$id  = $map[ $term_id ];
			$why = self::refusal( $id, $plan['tree'] );
			if ( '' === $why && ! $held_too && self::is_held( $id ) ) {
				$why = 'held';
			}

			if ( '' !== $why ) {
				$plan['left_out'][ $id ] = $why;
			} elseif ( ! in_array( $id, $plan['ids'], true ) ) {
				$plan['ids'][] = $id;
			}
		}

		return $plan;
	}

	/**
	 * The shop categories a product goes in, at most two.
	 *
	 * @param WC_Product $product The product.
	 * @return string[] Ids, deepest first.
	 */
	public static function for_product( $product ) {
		return self::plan( $product )['ids'];
	}

	/**
	 * The Storefront of an item, or nothing to send.
	 *
	 * eBay resets a StoreCategory2ID sent without a StoreCategoryID, or the
	 * same as it, so a new listing is sent a second only when there is one. A
	 * revise is sent 0 in its place: it changes only what it is sent, so a
	 * product that went from two categories to one would keep eBay's second.
	 * Not within the hour after eBay refused the 0 (stop_zero()).
	 *
	 * @param string[] $ids    plan()'s ids.
	 * @param bool     $revise Whether the item changes a listing eBay has.
	 * @return string XML, with its line end, or ''.
	 */
	public static function storefront_xml( array $ids, $revise = false ) {
		$ids = array_values( $ids );
		if ( ! $ids ) {
			return '';
		}

		$second = isset( $ids[1] ) ? esc_attr( $ids[1] ) : ( $revise && false === get_transient( self::NO_ZERO_TRANSIENT ) ? '0' : '' );

		return '<Storefront><StoreCategoryID>' . esc_attr( $ids[0] ) . '</StoreCategoryID>'
			. ( '' !== $second ? '<StoreCategory2ID>' . $second . '</StoreCategory2ID>' : '' )
			. '</Storefront>' . "\n";
	}

	/**
	 * An item with its Storefront taken out, or another put in its place. The
	 * last one in it: a description comes first, and could hold those very
	 * words.
	 *
	 * @param string $xml        The item.
	 * @param string $storefront storefront_xml()'s answer.
	 * @param string $instead    What goes in its place.
	 * @return string
	 */
	public static function without_storefront( $xml, $storefront, $instead = '' ) {
		$at = strrpos( (string) $xml, (string) $storefront );

		return false === $at ? (string) $xml : substr_replace( (string) $xml, $instead, $at, strlen( (string) $storefront ) );
	}

	/**
	 * Whether eBay refused an item for its shop category and nothing else:
	 * Ack Failure, and every entry that is not a Warning names one. Not an
	 * answer that may have made something (fix round 2): one with an ItemID,
	 * or that relist_not_made() would not call a plain refusal. A resend goes
	 * with a UUID of its own, so eBay would not stop a second listing.
	 *
	 * @param mixed $response A Trading call's answer.
	 * @return bool
	 */
	public static function refused_for( $response ) {
		if ( ! is_wp_error( $response ) || 'api_error' !== $response->get_error_code() ) {
			return false;
		}

		$data = $response->get_error_data();
		if ( ! is_array( $data ) || 'Failure' !== TCGiant_Sync_API::scalar_value( $data['Ack'] ?? '' ) ) {
			return false;
		}

		if ( isset( $data['ItemID'] ) || ! TCGiant_Sync_Exporter::relist_not_made( $response ) ) {
			return false;
		}

		$named = false;
		foreach ( self::entries( $data ) as $entry ) {
			if ( self::is_warning( $entry ) ) {
				continue;
			}
			if ( ! self::names_store_category( $entry ) ) {
				return false;
			}
			$named = true;
		}

		return $named;
	}

	/**
	 * Hold back the ids eBay refused, for an hour.
	 *
	 * @param string[] $ids The ids the refused item was sent.
	 */
	public static function hold( array $ids ) {
		foreach ( $ids as $id ) {
			set_transient( self::HELD_TRANSIENT . $id, time(), self::HELD_SECONDS );
		}
	}

	/**
	 * Whether an id is held back.
	 *
	 * @param string $id A shop category's id.
	 * @return bool
	 */
	private static function is_held( $id ) {
		return false !== get_transient( self::HELD_TRANSIENT . $id );
	}

	/**
	 * Lift the holds on some ids (fix round 2): the shop has saved its choices,
	 * or read eBay again, so the next push asks eBay.
	 *
	 * @param string[] $ids The ids.
	 */
	private static function lift_holds( array $ids ) {
		foreach ( array_unique( $ids ) as $id ) {
			delete_transient( self::HELD_TRANSIENT . $id );
		}
	}

	/**
	 * Whether eBay refused an item for the StoreCategory2ID it was sent, and
	 * for nothing else.
	 *
	 * @param mixed $response A Trading call's answer.
	 * @return bool
	 */
	public static function refused_second( $response ) {
		if ( ! self::refused_for( $response ) ) {
			return false;
		}

		foreach ( self::entries( $response->get_error_data() ) as $entry ) {
			if ( ! self::is_warning( $entry ) && false === stripos( self::words_of( $entry ), 'StoreCategory2ID' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * No revise sends the 0 for the hour.
	 */
	public static function stop_zero() {
		set_transient( self::NO_ZERO_TRANSIENT, time(), self::HELD_SECONDS );
	}

	/**
	 * The term a shop category's listing belongs to by the map: of several,
	 * the one the product already has, else the lowest term_id - but not
	 * WooCommerce's default category while another term maps there too, or a
	 * new listing would land in Uncategorized over the real one. eBay's Other
	 * has no term.
	 *
	 * @param string $id  The shop category's id.
	 * @param array  $has The term ids the product already has.
	 * @return int 0 when no term maps to it.
	 */
	public static function term_for( $id, array $has = array() ) {
		$id    = TCGiant_Sync_API::scalar_value( $id );
		$terms = array_keys( self::map(), $id, true );
		if ( ! $terms || 'other' === self::refusal( $id, self::tree() ) ) {
			return 0;
		}

		$held = array_intersect( $terms, array_map( 'intval', $has ) );
		if ( $held ) {
			return (int) min( $held );
		}

		$real = array_diff( $terms, array( (int) get_option( 'default_product_cat', 0 ) ) );

		return (int) min( $real ? $real : $terms );
	}

	/**
	 * Whether the shop categories a product maps to cover every one its
	 * listing is in, Other left out: the import then keeps the product's
	 * categories, with "Overwrite Categories & Tags" on as well. Replacing
	 * them would drop its other website categories, and flip a product
	 * between two terms that map to one shop category. By what it maps to,
	 * not by what a push would send: a row whose id has since gained children
	 * is still where the listing is.
	 *
	 * @param WC_Product $product A product that already exists.
	 * @param array      $listed  The listing's store category ids.
	 * @return bool
	 */
	public static function covers( $product, array $listed ) {
		$plan = self::plan( $product );
		if ( ! $plan['mapped'] ) {
			return false;
		}

		foreach ( $listed as $id ) {
			$id = TCGiant_Sync_API::scalar_value( $id );
			if ( 'other' !== self::refusal( $id, $plan['tree'] ) && ! in_array( $id, $plan['mapped'], true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the website leads a product's categories: its next Update would
	 * send its listing a shop category, so whatever the listing says now is
	 * about to change to what the product says. The import keeps them: a
	 * product moved on the website would be moved back by a sync that came
	 * before the Update, and the Update would then send the old category. A
	 * held id counts: it goes again within the hour.
	 *
	 * @param WC_Product $product A product that already exists.
	 * @return bool
	 */
	public static function website_leads( $product ) {
		return self::plan( $product, true )['ids'] && TCGiant_Sync_Ebay_Live::follows_defaults( $product->get_id() );
	}

	/**
	 * Whether a listing is in no shop category but eBay's Other.
	 *
	 * @param array $listed The listing's store category ids.
	 * @return bool
	 */
	public static function only_in_other( array $listed ) {
		$tree = self::tree();
		foreach ( $listed as $id ) {
			if ( 'other' !== self::refusal( TCGiant_Sync_API::scalar_value( $id ), $tree ) ) {
				return false;
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// What a push says
	// -------------------------------------------------------------------------

	/**
	 * The categories of a plan, by path where the tree has them.
	 *
	 * @param array $ids  Ids.
	 * @param array $plan plan().
	 * @return string
	 */
	public static function said( array $ids, array $plan ) {
		$names = array();
		foreach ( $ids as $id ) {
			$row     = is_array( $plan['tree'] ?? null ) ? self::find( $id, $plan['tree'] ) : null;
			$names[] = null !== $row ? self::path( $row ) : (string) $id;
		}

		return implode( ' and ', $names );
	}

	/**
	 * The line for a shop category or two left out of a listing, or ''.
	 *
	 * @param string $for        Who is sending: "Push", "Verify".
	 * @param int    $product_id The product.
	 * @param array  $plan       plan().
	 * @return string
	 */
	public static function left_out_warning( $for, $product_id, array $plan ) {
		$words = array(
			'other'  => 'it is eBay\'s own Other',
			'none'   => 'eBay says your account has no Store, or no shop categories',
			'gone'   => 'your eBay shop no longer has it',
			'parent' => 'it has subcategories, so eBay would put the listing in Other',
			'held'   => 'eBay refused it within the last hour',
		);

		$said = array();
		foreach ( (array) ( $plan['left_out'] ?? array() ) as $id => $why ) {
			$said[] = sprintf( '%1$s (%2$s)', self::said( array( (string) $id ), $plan ), $words[ $why ] ?? (string) $why );
		}

		if ( ! $said ) {
			return '';
		}

		return sprintf(
			'%1$s: WC #%2$d leaves out eBay shop category %3$s.%4$s',
			$for,
			(int) $product_id,
			implode( '; ', $said ),
			in_array( 'none', $plan['left_out'], true ) ? '' : ' Choose another on TCGiant Sync, Shop Categories.'
		);
	}

	/**
	 * What eBay warned of a store category in an answer that took the item,
	 * in its own words.
	 *
	 * The API class reads only Failure, so a Warning went unseen. Errors is
	 * one entry or a list. An entry counts when it is a Warning and carries
	 * NON_LEAF_WARNING, or names a store category: a live answer has not shown
	 * eBay's exact code.
	 *
	 * @param mixed $answer eBay's answer.
	 * @return string[]
	 */
	public static function warnings_in( $answer ) {
		$said = array();
		foreach ( self::entries( $answer ) as $entry ) {
			if ( self::is_warning( $entry ) && self::names_store_category( $entry ) ) {
				$long   = TCGiant_Sync_API::scalar_value( $entry['LongMessage'] ?? '' );
				$said[] = '' !== $long ? $long : TCGiant_Sync_API::scalar_value( $entry['ShortMessage'] ?? '' );
			}
		}

		return $said;
	}

	/**
	 * What Verify or Check with eBay adds when eBay warned of the shop
	 * category, or ''. Their answers keep eBay's Errors as warnings
	 * (TCGiant_Sync_API::verify_answer()); they only show it, and send nothing
	 * again: this is how a shop reads what eBay would do with a category.
	 *
	 * @param mixed $checked The Verify call's answer.
	 * @return string
	 */
	public static function verify_warning( $checked ) {
		$said = self::warnings_in( array( 'Errors' => is_array( $checked ) ? ( $checked['warnings'] ?? array() ) : array() ) );

		/* translators: %s: eBay's own words */
		return $said ? sprintf( __( 'eBay warned about the shop category: %s', 'tcgiant-sync' ), implode( ' ', $said ) ) : '';
	}

	/**
	 * The entries of an answer's Errors, one or a list.
	 *
	 * @param mixed $answer eBay's answer.
	 * @return array
	 */
	private static function entries( $answer ) {
		$errors = is_array( $answer ) && isset( $answer['Errors'] ) && is_array( $answer['Errors'] ) ? $answer['Errors'] : array();

		if ( isset( $errors['ErrorCode'] ) || isset( $errors['ShortMessage'] ) || isset( $errors['LongMessage'] ) || isset( $errors['SeverityCode'] ) ) {
			$errors = array( $errors );
		}

		return array_filter( $errors, 'is_array' );
	}

	/**
	 * Whether an entry is a Warning.
	 *
	 * @param array $entry One of entries().
	 * @return bool
	 */
	private static function is_warning( array $entry ) {
		return 'warning' === strtolower( TCGiant_Sync_API::scalar_value( $entry['SeverityCode'] ?? ( $entry['Severity'] ?? '' ) ) );
	}

	/**
	 * Whether an entry is about a store category. The word storefront alone
	 * does not count (fix round 2): eBay may echo a title that holds it.
	 *
	 * @param array $entry One of entries().
	 * @return bool
	 */
	private static function names_store_category( array $entry ) {
		return self::NON_LEAF_WARNING === TCGiant_Sync_API::scalar_value( $entry['ErrorCode'] ?? '' )
			|| 1 === preg_match( '/Item\.Storefront|store\s*categor/i', self::words_of( $entry ) );
	}

	/**
	 * What an entry says.
	 *
	 * @param array $entry One of entries().
	 * @return string
	 */
	private static function words_of( array $entry ) {
		return TCGiant_Sync_API::scalar_value( $entry['ShortMessage'] ?? '' ) . ' ' . TCGiant_Sync_API::scalar_value( $entry['LongMessage'] ?? '' );
	}

	// -------------------------------------------------------------------------
	// The screen
	// -------------------------------------------------------------------------

	/**
	 * Each product category's name from the top down.
	 *
	 * @param array $terms Terms: term_id, name, parent.
	 * @return array term_id => names, decoded.
	 */
	public static function term_levels( array $terms ) {
		$by_id = array();
		foreach ( $terms as $term ) {
			$by_id[ (int) $term->term_id ] = $term;
		}

		$levels = array();
		foreach ( $by_id as $term_id => $term ) {
			$line = array();
			$seen = array();
			$at   = $term;

			while ( $at && ! isset( $seen[ (int) $at->term_id ] ) ) {
				$seen[ (int) $at->term_id ] = true;
				array_unshift( $line, self::plain( $at->name ) );
				$at = $by_id[ (int) $at->parent ] ?? null;
			}

			$levels[ $term_id ] = $line;
		}

		return $levels;
	}

	/**
	 * What Match by name would fill: for each term with no choice, a shop
	 * category with no children, by the same full path, ignoring case and
	 * HTML entities; else by a name that is on one category on each side. A
	 * name on two on either side, with no full path to tell them apart, gets
	 * nothing: no guessing.
	 *
	 * @param array $terms Terms: term_id, name, parent.
	 * @param array $tree  tree().
	 * @param array $map   The rows that already have a choice.
	 * @return array term_id => id.
	 */
	public static function suggest( array $terms, array $tree, array $map = array() ) {
		$by_path = array();
		$by_name = array();
		foreach ( $tree['categories'] as $row ) {
			$by_path[ self::fold( implode( "\x1f", $row['levels'] ) ) ][] = $row;
			$by_name[ self::fold( $row['name'] ) ][]                      = $row;
		}

		$levels = self::term_levels( $terms );
		$names  = array();
		foreach ( $levels as $line ) {
			$name           = self::fold( (string) end( $line ) );
			$names[ $name ] = ( $names[ $name ] ?? 0 ) + 1;
		}

		$out = array();
		foreach ( $levels as $term_id => $line ) {
			if ( isset( $map[ $term_id ] ) ) {
				continue;
			}

			$same = $by_path[ self::fold( implode( "\x1f", $line ) ) ] ?? array();
			$name = self::fold( (string) end( $line ) );
			if ( ! $same && 1 === $names[ $name ] ) {
				$same = $by_name[ $name ] ?? array();
			}

			if ( 1 === count( $same ) && '' === self::offer_refusal( $same[0] ) ) {
				$out[ $term_id ] = (string) $same[0]['id'];
			}
		}

		return $out;
	}

	/**
	 * Why a category of the tree is not one to choose: 'other', 'parent', or ''.
	 *
	 * @param array $row A row of the tree.
	 * @return string
	 */
	private static function offer_refusal( array $row ) {
		if ( self::is_other( $row ) ) {
			return 'other';
		}

		return empty( $row['children'] ) ? '' : 'parent';
	}

	/**
	 * What the screen draws: one of 'not_connected', 'failed' (eBay could not
	 * be asked), 'no_store' (it said there is no Store, or gave nothing to
	 * choose), 'no_terms' (WordPress listed no product categories), or 'ready',
	 * with a row for each product category, by full path, and the one list of
	 * the shop's categories its selects are filled from.
	 *
	 * @return array state, error, rows, options, suggestions, has_map.
	 */
	public static function screen() {
		$screen = array( 'state' => 'not_connected', 'error' => '', 'rows' => array(), 'options' => array(), 'suggestions' => array(), 'has_map' => (bool) self::stored() );

		if ( ! TCGiant_Sync_OAuth::instance()->is_authenticated() ) {
			return $screen;
		}

		$tree = self::tree();
		if ( ! self::usable( $tree ) ) {
			$screen['state'] = empty( $tree['none'] ) ? 'failed' : 'no_store';
			$screen['error'] = (string) ( $tree['error'] ?? '' );
			return $screen;
		}

		foreach ( $tree['categories'] as $row ) {
			$why = self::offer_refusal( $row );
			if ( 'other' !== $why ) {
				$screen['options'][] = array( 'id' => (string) $row['id'], 'path' => self::path( $row ), 'disabled' => 'parent' === $why );
			}
		}
		if ( ! $screen['options'] ) {
			$screen['state'] = 'no_store';
			return $screen;
		}
		usort( $screen['options'], function ( $a, $b ) {
			return strnatcasecmp( $a['path'], $b['path'] );
		} );

		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$terms = is_array( $terms ) ? $terms : array();
		$map   = self::map();

		foreach ( self::term_levels( $terms ) as $term_id => $line ) {
			$choice = (string) ( $map[ $term_id ] ?? '' );
			$row    = '' !== $choice ? self::find( $choice, $tree ) : null;
			$why    = '' !== $choice ? self::refusal( $choice, $tree ) : '';
			if ( '' !== $choice && '' === $why && self::is_held( $choice ) ) {
				$why = 'held';
			}

			$screen['rows'][] = array(
				'term_id' => (int) $term_id,
				'path'    => implode( ' > ', $line ),
				'choice'  => $choice,
				'label'   => null !== $row ? self::path( $row ) : $choice,
				'problem' => $why,
			);
		}
		// No form with no rows (fix round 2): it would post an empty map, which
		// clears every choice.
		if ( ! $screen['rows'] ) {
			$screen['state'] = 'no_terms';
			return $screen;
		}
		usort( $screen['rows'], function ( $a, $b ) {
			return strnatcasecmp( $a['path'], $b['path'] );
		} );

		$screen['state']       = 'ready';
		$screen['suggestions'] = self::suggest( $terms, $tree, $map );

		return $screen;
	}

	/**
	 * Save what the screen sent, or say why not and keep the map as it was.
	 *
	 * Refused whole, with the option untouched: no field, what it holds is
	 * not a JSON object of rows, or, for a map with rows in it, no tree or no
	 * term list to check against. A valid empty map is a choice, and clears
	 * every row; it needs nothing checked.
	 *
	 * The screen sends every row it drew, None as "". So a row it did not send
	 * - a category made since the page was drawn, one another language's list
	 * leaves out, one mapped in another tab - keeps what it has, and that can
	 * be told from a row set to None. A row refused keeps what it has too: a
	 * choice of a category that has gone or has children is not saved, and is
	 * counted, and a good earlier choice is not lost for it. A row that was
	 * sent the id it already holds, gone bad since, stays, is said apart, and
	 * goes on showing its warning until it is changed. A Save lifts the holds
	 * on what was mapped and what is now.
	 *
	 * @param mixed $json The field, unslashed; null when it was not sent.
	 * @return array saved (bool), type ('success', 'warning', 'error'), text.
	 */
	public static function save( $json ) {
		$refused = function ( $text ) {
			return array( 'saved' => false, 'type' => 'error', 'text' => $text . ' ' . __( 'Nothing was saved: your choices are as they were.', 'tcgiant-sync' ) );
		};

		$text = is_string( $json ) ? trim( $json ) : '';
		if ( '' === $text ) {
			return $refused( __( 'The page sent no choices.', 'tcgiant-sync' ) );
		}

		$posted = json_decode( $text, true );
		if ( ! is_array( $posted ) || ( '{' !== $text[0] && '[]' !== $text ) ) {
			return $refused( __( 'The page sent its choices in a form that could not be read.', 'tcgiant-sync' ) );
		}

		if ( ! $posted ) {
			self::lift_holds( array_values( self::stored() ) );
			update_option( self::MAP_OPTION, array(), false );
			return array( 'saved' => true, 'type' => 'success', 'text' => __( 'Saved: no category is mapped.', 'tcgiant-sync' ) );
		}

		$tree = self::tree();
		if ( ! self::usable( $tree ) ) {
			return $refused( __( 'TCGiant Sync could not read your eBay shop\'s categories to check your choices against.', 'tcgiant-sync' ) );
		}

		$known = self::term_ids();
		if ( null === $known ) {
			return $refused( __( 'WordPress could not list your product categories to check your choices against.', 'tcgiant-sync' ) );
		}

		$map  = array_intersect_key( self::stored(), $known );
		$left = 0;
		$kept = 0;

		foreach ( $posted as $term_id => $id ) {
			$term_id = (int) $term_id;
			$id      = is_string( $id ) ? trim( $id ) : null;

			if ( '' === $id ) {
				unset( $map[ $term_id ] );
			} elseif ( ! isset( $known[ $term_id ] ) ) {
				$left++;
			} elseif ( null === $id || 1 !== preg_match( '/^\d+$/', $id ) ) {
				$left++;
			} elseif ( '' === self::refusal( $id, $tree ) ) {
				$map[ $term_id ] = $id;
			} elseif ( $id === (string) ( $map[ $term_id ] ?? '' ) ) {
				$kept++;
			} else {
				$left++;
			}
		}

		self::lift_holds( array_merge( array_values( self::stored() ), array_values( $map ) ) );
		update_option( self::MAP_OPTION, $map, false );

		/* translators: %s: how many categories are mapped */
		$said = array( sprintf( _n( 'Saved: %s category is mapped.', 'Saved: %s categories are mapped.', count( $map ), 'tcgiant-sync' ), number_format_i18n( count( $map ) ) ) );
		if ( $left ) {
			/* translators: %s: how many choices */
			$said[] = sprintf( _n( '%s choice was not saved, and its row keeps what it had: its category has gone, or its eBay shop category has subcategories.', '%s choices were not saved, and their rows keep what they had: their categories have gone, or their eBay shop categories have subcategories.', $left, 'tcgiant-sync' ), number_format_i18n( $left ) );
		}
		if ( $kept ) {
			/* translators: %s: how many rows */
			$said[] = sprintf( _n( '%s row still points at a shop category that cannot be used, and goes without it until you change it.', '%s rows still point at shop categories that cannot be used, and go without them until you change them.', $kept, 'tcgiant-sync' ), number_format_i18n( $kept ) );
		}

		return array( 'saved' => true, 'type' => $left || $kept ? 'warning' : 'success', 'text' => implode( ' ', $said ) );
	}

	/**
	 * The screen's Save.
	 */
	public static function handle_save() {
		check_admin_referer( self::SAVE_FORM );
		self::must_manage();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON: every key and value is checked in save().
		$said = self::save( isset( $_POST[ self::MAP_FIELD ] ) ? wp_unslash( $_POST[ self::MAP_FIELD ] ) : null );

		self::notice( $said['type'], $said['text'] );
		self::back();
	}

	/**
	 * The screen's Refresh from eBay: eBay read again. A good tree stays until
	 * eBay has answered: dropped first, a failed read would leave anything
	 * imported in the next minute with no categories. A good read lifts the
	 * holds: only this button, not every read of eBay, as a Full Sync's is.
	 */
	public static function handle_refresh() {
		check_admin_referer( self::REFRESH_LINK );
		self::must_manage();

		$tree = self::tree( true );

		if ( isset( $tree['refresh_error'] ) ) {
			/* translators: %s: why */
			self::notice( 'warning', sprintf( __( 'eBay could not be read (%s), so this page shows the categories it read earlier.', 'tcgiant-sync' ), $tree['refresh_error'] ) );
		} elseif ( self::usable( $tree ) ) {
			self::lift_holds( array_values( self::stored() ) );
			self::notice( 'success', __( 'Your eBay shop\'s categories were read again.', 'tcgiant-sync' ) );
		}
		self::back();
	}

	/**
	 * The way out when the screen has no form to save with: the map cleared,
	 * so a push sends no shop category.
	 */
	public static function handle_clear() {
		check_admin_referer( self::CLEAR_FORM );
		self::must_manage();

		update_option( self::MAP_OPTION, array(), false );

		self::notice( 'success', __( 'Your choices were cleared. A push sends no shop category now.', 'tcgiant-sync' ) );
		self::back();
	}

	/**
	 * Only someone who can manage the site.
	 */
	private static function must_manage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}
	}

	/**
	 * Leave a notice for the screen.
	 *
	 * @param string $type success, warning or error.
	 * @param string $text What to say.
	 */
	private static function notice( $type, $text ) {
		set_transient( 'tcgiant_shop_cat_notice_' . get_current_user_id(), array( 'type' => $type, 'text' => (string) $text ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Show, once, the notice left for this user.
	 */
	public static function show_notice() {
		$key    = 'tcgiant_shop_cat_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( (string) $notice['type'] ), esc_html( (string) $notice['text'] ) );
	}

	/**
	 * The screen's address.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Back to the screen.
	 */
	private static function back() {
		wp_safe_redirect( self::url() );
		exit;
	}
}
