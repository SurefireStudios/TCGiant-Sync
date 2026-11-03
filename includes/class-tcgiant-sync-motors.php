<?php
/**
 * eBay Motors Parts & Accessories, for a shop on eBay United States (3.28.9).
 *
 * A shop on eBay US could not push anything into an eBay Motors category.
 * Every push went to site 0 and its category was looked up in category tree 0,
 * and eBay keeps its Motors categories in tree 100 and lists them through site
 * 100. eBay said so three times for one push into 35560: the Taxonomy API's
 * 62005 ("The specified category ID does not belong to specified category
 * tree"), then AddFixedPriceItem's "The category is not valid, select another
 * category", and "Condition is not applicable for this category".
 *
 * What eBay's documentation says, and this class does:
 * - AddFixedPriceItem's PrimaryCategory "must be a valid leaf category on the
 *   category tree for the request site (X-EBAY-API-SITEID / listing
 *   Item.Site). A category ID that is valid only on another site's tree is
 *   rejected", with "getCategorySubtree on tree 100 for eBay Motors" as the
 *   example; its Item.Site "must be consistent with the numeric site ID" of
 *   X-EBAY-API-SITEID; and the Motors guide: "To list any item in US eBay
 *   Motors categories, the Site ID must be set to '100', instead of '0'".
 *   So a listing in a Motors category is made and verified with
 *   X-EBAY-API-SITEID 100 and Item.Site eBayMotors (request_site()), by a
 *   choice the push makes for that one listing
 *   (TCGiant_Sync_API::with_site()). Its revise and relist carry Item.Site
 *   eBayMotors with the X-EBAY-API-SITEID eBay took that listing's with
 *   (else the one it last took any listing's with), 100 until eBay refuses
 *   that for the site and takes 0 (listing_call()): eBay's
 *   Motors revise guide says to "specify "0" as the site ID", and nothing of
 *   a relist. The shop's marketplace stays EBAY_US; Currency stays USD and
 *   Country US. GetItem, EndItem and ReviseInventoryStatus name the listing
 *   by its ItemID and go as before.
 * - The Taxonomy API's trees: "eBay US: 0", "eBay Motors US: 100". Item
 *   specifics are read from the tree the listing is in, cached by tree.
 * - Item conditions: Metadata getItemConditionPolicies for EBAY_MOTORS_US
 *   (conditions()), cached by tree and category. A condition the category does
 *   not take is refused here, with the ones it does take, rather than sent for
 *   eBay to drop.
 * - Business policies: a policy's categoryTypes are MOTORS_VEHICLES and/or
 *   ALL_EXCLUDING_MOTORS_VEHICLES ("whether the policy applies to motor
 *   vehicle listings, or to any other items except motor vehicle listings");
 *   return policies are always the second. Parts & Accessories are not motor
 *   vehicles, so the shop's ordinary US policies apply; one known to be for
 *   vehicles only is refused (problems()). So is one on an ordinary push
 *   (3.29.2): eBay US lists no motor vehicle on its own site.
 *
 * Only Parts & Accessories (6028, under eBay Motors). A new listing (Add,
 * Verify) in a whole vehicle's category - cars and trucks, motorcycles,
 * powersports, boats, other vehicles and trailers, which list with VINs,
 * deposits and their own revise rules - is refused here with a clear message;
 * so is one in a Motors category that cannot be confirmed as Parts &
 * Accessories. A vehicle listing already on eBay Motors - imported, or
 * listed some other way - is updated, relisted, ended and has its stock sent
 * as before 3.28.9, with the shop's own site, and the log says vehicles are
 * not otherwise supported. Parts compatibility (fitment) is not sent.
 *
 * A listing's site is the listing's own (listing_site()): recorded, or in
 * eBay's answer about it, or read first. Never its category's, and a name
 * saved with a category - the product's, the default's - never decides a
 * category's tree: its ID does (category_fields()).
 *
 * Which tree a category is in, at the least cost in calls (resolve()), by its
 * ID: the plugin's own list (TCGiant_Sync_Catalog::CATEGORIES) is eBay US's;
 * otherwise
 * - a record kept here, by tree, of the categories learnt so far: from the
 *   category browser's eBay Motors entry (every category under Parts &
 *   Accessories it lists, at no call of its own), from Suggest Category (the
 *   ancestors eBay gives with each), and from the probe below;
 * - for a listing's own category, the listing's site, and eBay's own path
 *   for it as the listing's last read recorded it (listing_branch());
 * - otherwise the probe, at a push: the item specifics read for tree 0, which
 *   the push makes anyway (and the API keeps for 7 days), answers at no extra
 *   call for an ordinary category. Only its 62005 asks tree 100: one
 *   getCategorySubtree for the category's node, whose parent says the branch
 *   when it is Parts & Accessories or a vehicle category, or else one
 *   getCategorySuggestions of the category's own name, whose ancestors say
 *   it. Kept KEEP_SECONDS, so a push of the same category asks nothing again.
 *   eBay not answering is held for an hour (HOLD_TRANSIENT), so a bulk push
 *   asks once an hour, not once a product.
 *
 * The record is one small option, never autoloaded, keyed "100:<category ID>"
 * (MAX entries; the ones learnt longest ago go first), beside the import's
 * category memory (TCGiant_Sync_Category_Memory), which keeps each listing's
 * categories by its ItemID, and under its name, which uninstall sweeps. The
 * memory's own forget_all() leaves it: what tree a category is in does not
 * depend on the import filter. Nothing here is written for a shop that is not
 * on eBay US, and nothing for an ordinary category.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.28.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Motors class.
 */
class TCGiant_Sync_Motors {

	/** The marketplace whose shops can list in eBay Motors. */
	const HOME_MARKETPLACE = 'EBAY_US';

	/** eBay Motors' marketplace ID (Metadata API), category tree (Taxonomy API), site code and site ID (Trading API). */
	const MARKETPLACE = 'EBAY_MOTORS_US';
	const TREE        = '100';
	const SITE        = 'eBayMotors';
	const SITE_ID     = '100';

	/** eBay Motors > Parts & Accessories, the one branch a push lists in. */
	const PARTS      = '6028';
	const PARTS_NAME = 'Parts & Accessories';
	const ROOT_ID    = '6000';
	const ROOT_NAME  = 'eBay Motors';

	/** The whole-vehicle branches of eBay Motors, refused. */
	const VEHICLES = array(
		'6001'  => 'Cars & Trucks',
		'6024'  => 'Motorcycles',
		'66466' => 'Powersports',
		'26429' => 'Boats',
		'6038'  => 'Other Vehicles & Trailers',
	);

	/** A category's branch: Parts & Accessories, a vehicle branch, or another part of eBay Motors. */
	const BRANCH_PARTS    = 'parts';
	const BRANCH_VEHICLES = 'vehicles';
	const BRANCH_OTHER    = 'other';

	/** The record of categories learnt: "100:<ID>" => array( 'b' => branch, 'p' => parent, 'at' => when ). */
	const OPTION = 'tcgiant_sync_category_memory_trees';

	/** How long what was learnt stands: 90 days. eBay's trees change a few times a year. */
	const KEEP_SECONDS = 7776000;

	/** Categories kept at most. */
	const MAX = 2000;

	/** A learnt time this far ahead of the clock counts as not learnt. */
	const SKEW_SECONDS = 3600;

	/** A category in neither tree, by ID, for a day: tree 100 is not asked about it again. */
	const NOT_MOTORS_TRANSIENT = 'tcgiant_not_motors_';

	/** Item conditions eBay takes in a category, by tree and category: tcgiant_conds_<tree>_<ID>. */
	const CONDITIONS_TRANSIENT = 'tcgiant_conds_';
	const CONDITIONS_TTL       = 604800;

	/**
	 * A category eBay did not answer for, by ID, for an hour (as 3.28.8 holds
	 * a failed read of a listing): array( 'step' => 'ordinary' when tree 0's
	 * read failed, 'motors' when tree 100's did, 'error' => eBay's error ).
	 * Not asked about again until then (resolve()).
	 */
	const HOLD_TRANSIENT = 'tcgiant_motors_hold_';
	const HOLD_SECONDS   = 3600;

	/**
	 * The X-EBAY-API-SITEID eBay last took a revise and a relist of an eBay
	 * Motors listing with, shop-wide: array( 'revise' => '100' or '0',
	 * 'relist' => ... ). SITE_ID until eBay says otherwise (listing_call()).
	 */
	const SITES_OPTION = 'tcgiant_sync_motors_sites';

	/** eBay United States' X-EBAY-API-SITEID, the other one a revise or relist is tried with. */
	const HOME_SITE_ID = '0';

	/**
	 * eBay United States' site code (Item.Site, _ebay_site; the site of
	 * TCGiant_Sync_API::MARKETPLACES' EBAY_US): a listing there is never a
	 * motor vehicle's, which eBay US lists on eBay Motors (3.29.2).
	 */
	const HOME_SITE = 'US';

	/**
	 * eBay's errors that refuse a call for the site it went to, from the
	 * Trading API's error list: 86 "The category selected is not a valid
	 * category for this site", 94 "This listing cannot be edited or relisted
	 * because it was not created on this eBay site", 107 "The category is not
	 * valid, select another category", 20192 "Item/Transaction does not
	 * belong to this site. Please redirect the request to the right site",
	 * 21201 "You can only relist this when you are on eBay Motors", 21670
	 * (the category ID does not correspond with a category on the site).
	 */
	const SITE_ERRORS = array( '86', '94', '107', '20192', '21201', '21670' );

	/**
	 * Of those, the ones eBay also gives for a category that is wrong for a
	 * reason of its own - not a leaf, retired, another field's: they count
	 * only where eBay's words or parameters point at the site, or at the
	 * listing's own category (points_at_site()).
	 */
	const CATEGORY_ERRORS = array( '86', '107', '21670' );

	/**
	 * The X-EBAY-API-SITEID eBay took a revise or a relist of this
	 * product's listing with, by kind: the one a later call of that kind
	 * goes with first. Dropped with the listing's other identity keys
	 * (TCGiant_Sync_Listing_Link::listing_identity_meta_keys()).
	 */
	const LISTING_SITE_META = array(
		'revise' => '_ebay_motors_revise_site',
		'relist' => '_ebay_motors_relist_site',
	);

	/**
	 * Now, in Unix seconds. Overridable for tests.
	 *
	 * @var callable|null
	 */
	public static $clock = null;

	/**
	 * Now.
	 *
	 * @return int
	 */
	public static function now() {
		return self::$clock ? (int) call_user_func( self::$clock ) : time();
	}

	/**
	 * Whether a shop on this marketplace can list in eBay Motors: eBay US, or
	 * none saved, which every call reads as eBay US.
	 *
	 * @param mixed $marketplace The marketplace setting.
	 * @return bool
	 */
	public static function applies( $marketplace ) {
		$marketplace = is_string( $marketplace ) ? $marketplace : '';

		return '' === $marketplace || self::HOME_MARKETPLACE === $marketplace;
	}

	/**
	 * A category ID as eBay writes one, or ''.
	 *
	 * @param mixed $category_id Category ID.
	 * @return string
	 */
	public static function clean_id( $category_id ) {
		$category_id = is_scalar( $category_id ) ? trim( (string) $category_id ) : '';

		return 1 === preg_match( '/^\d{1,12}$/', $category_id ) ? $category_id : '';
	}

	/**
	 * What is known of a Motors category, while fresh: branch and parent.
	 *
	 * @param mixed $category_id Category ID.
	 * @return array|null array( 'b' => branch, 'p' => parent ID ), or null.
	 */
	public static function record( $category_id ) {
		$id = self::clean_id( $category_id );
		if ( '' === $id ) {
			return null;
		}

		$all = get_option( self::OPTION, array() );
		$rec = is_array( $all ) ? ( $all[ self::TREE . ':' . $id ] ?? null ) : null;
		if ( ! is_array( $rec ) ) {
			return null;
		}

		$age = self::now() - (int) ( $rec['at'] ?? 0 );
		if ( $age < -self::SKEW_SECONDS || $age > self::KEEP_SECONDS ) {
			return null;
		}

		return array(
			'b' => (string) ( $rec['b'] ?? '' ),
			'p' => (string) ( $rec['p'] ?? '' ),
		);
	}

	/**
	 * When a Motors category was last learnt, 0 for one not learnt.
	 *
	 * @param string $category_id Category ID.
	 * @return int
	 */
	private static function learnt_at( $category_id ) {
		$all = get_option( self::OPTION, array() );

		return is_array( $all ) ? (int) ( $all[ self::TREE . ':' . self::clean_id( $category_id ) ]['at'] ?? 0 ) : 0;
	}

	/**
	 * Learn Motors categories: category ID => array( branch, parent ). One
	 * write for the lot, only when something changed or is a day old.
	 *
	 * @param array $learnt Category ID => array( branch, parent ID ).
	 * @return void
	 */
	public static function learn( array $learnt ) {
		$all     = get_option( self::OPTION, array() );
		$all     = is_array( $all ) ? $all : array();
		$now     = self::now();
		$changed = false;

		foreach ( $learnt as $category_id => $what ) {
			$id = self::clean_id( $category_id );
			if ( '' === $id || ! is_array( $what ) ) {
				continue;
			}
			$branch = (string) ( $what[0] ?? '' );
			$parent = self::clean_id( $what[1] ?? '' );
			$key    = self::TREE . ':' . $id;
			$had    = $all[ $key ] ?? null;

			// A branch already known is not unlearnt by an answer that did not say.
			if ( '' === $branch && is_array( $had ) ) {
				$branch = (string) ( $had['b'] ?? '' );
			}
			if ( '' === $parent && is_array( $had ) ) {
				$parent = (string) ( $had['p'] ?? '' );
			}

			if ( is_array( $had ) && $branch === (string) ( $had['b'] ?? '' ) && $parent === (string) ( $had['p'] ?? '' )
				&& $now - (int) ( $had['at'] ?? 0 ) < DAY_IN_SECONDS && (int) ( $had['at'] ?? 0 ) <= $now ) {
				continue;
			}

			unset( $all[ $key ] );
			$all[ $key ] = array( 'b' => $branch, 'p' => $parent, 'at' => $now );
			$changed     = true;
		}

		if ( ! $changed ) {
			return;
		}

		// The ones learnt longest ago go first; an entry learnt again moves
		// to the end above.
		if ( count( $all ) > self::MAX ) {
			$all = array_slice( $all, count( $all ) - self::MAX, null, true );
		}

		update_option( self::OPTION, $all, false );
	}

	/**
	 * A category's branch from eBay's path for it: GetItem's CategoryName
	 * ("eBay Motors:Parts & Accessories:Motorcycle Parts:...") or a path the
	 * category browser or Suggest Category gave ("eBay Motors > ...").
	 *
	 * @param mixed $path The path.
	 * @return string A BRANCH_ value, or '' when the path is not eBay Motors'.
	 */
	public static function branch_of_path( $path ) {
		$path  = is_string( $path ) ? trim( $path ) : '';
		$parts = array();
		foreach ( (array) preg_split( '/\s*(?::|>|\x{203A})\s*/u', $path ) as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}

		if ( ! $parts || 0 !== strcasecmp( $parts[0], self::ROOT_NAME ) || ! isset( $parts[1] ) ) {
			return '';
		}

		// eBay's website calls it "Auto Parts & Accessories".
		if ( in_array( strtolower( str_replace( ' and ', ' & ', $parts[1] ) ), array( strtolower( self::PARTS_NAME ), 'auto ' . strtolower( self::PARTS_NAME ) ), true ) ) {
			return self::BRANCH_PARTS;
		}
		foreach ( self::VEHICLES as $name ) {
			if ( 0 === strcasecmp( $parts[1], $name ) ) {
				return self::BRANCH_VEHICLES;
			}
		}

		return self::BRANCH_OTHER;
	}

	/**
	 * A category's branch from the IDs above it, nearest first.
	 *
	 * @param string[] $ancestors Ancestor IDs.
	 * @return string A BRANCH_ value, or '' when none says.
	 */
	public static function branch_of_ancestors( array $ancestors ) {
		$ancestors = array_map( 'strval', $ancestors );

		if ( in_array( self::PARTS, $ancestors, true ) ) {
			return self::BRANCH_PARTS;
		}
		if ( array_intersect( array_keys( self::VEHICLES ), $ancestors ) ) {
			return self::BRANCH_VEHICLES;
		}

		return '';
	}

	/**
	 * A category's branch from what is known without asking eBay: itself or
	 * its parent being a branch, or what was learnt of either.
	 *
	 * @param string $category_id Category ID.
	 * @param string $parent      Its parent's ID, if known.
	 * @return string A BRANCH_ value, or ''.
	 */
	private static function branch_known( $category_id, $parent ) {
		foreach ( array( (string) $category_id, (string) $parent ) as $id ) {
			if ( self::PARTS === $id ) {
				return self::BRANCH_PARTS;
			}
			if ( isset( self::VEHICLES[ $id ] ) ) {
				return self::BRANCH_VEHICLES;
			}
		}
		if ( self::ROOT_ID === (string) $parent ) {
			return self::BRANCH_OTHER;
		}

		$rec = '' !== (string) $parent ? self::record( $parent ) : null;

		return $rec ? $rec['b'] : '';
	}

	/**
	 * The eBay error ID in a REST answer's error, e.g. '62005'.
	 *
	 * @param mixed $error A WP_Error from TCGiant_Sync_API::request().
	 * @return string
	 */
	public static function error_id( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return '';
		}
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['errors'][0]['errorId'] ) && is_scalar( $data['errors'][0]['errorId'] ) ? (string) $data['errors'][0]['errorId'] : '';
	}

	/**
	 * Which tree a category is in, for a push from a shop on eBay US, asking
	 * eBay when nothing here knows (see the class docblock for the cost).
	 *
	 * eBay not answering is held for an hour (HOLD_TRANSIENT), as 3.28.8
	 * holds a failed read of a listing: tree 0's read failing leaves the
	 * category ordinary, as before 3.28.9; tree 100's failing, after tree 0
	 * said the category is not there, is 'wait', which a push is refused for
	 * with eBay's error, to be tried again later (problems()).
	 *
	 * @param mixed $category_id Category ID.
	 * @return array 'tree' (TREE, or '' for an ordinary category or one not
	 *               known to be in eBay Motors), 'branch' (for TREE: a
	 *               BRANCH_ value, '' if it could not be told), 'said' (what
	 *               the probe found worth saying, '' when nothing), 'wait'
	 *               (eBay's error, when tree 100 did not answer within the
	 *               hour; '' otherwise).
	 */
	public static function resolve( $category_id ) {
		$none = array( 'tree' => '', 'branch' => '', 'said' => '', 'wait' => '' );
		$id   = self::clean_id( $category_id );
		if ( '' === $id ) {
			return $none;
		}

		// Known: a bulk push of 500 products in it asks eBay nothing more.
		$rec = self::record( $id );
		if ( $rec && '' !== $rec['b'] ) {
			return array( 'tree' => self::TREE, 'branch' => $rec['b'], 'said' => '', 'wait' => '' );
		}

		// eBay did not answer for it within the hour: not asked again yet.
		$held = get_transient( self::HOLD_TRANSIENT . $id );
		if ( is_array( $held ) ) {
			return 'motors' === ( $held['step'] ?? '' )
				? array( 'tree' => self::TREE, 'branch' => '', 'said' => '', 'wait' => (string) ( $held['error'] ?? '' ) )
				: $none;
		}

		// Known not to be placed, and asked within the day.
		if ( $rec && self::now() - self::learnt_at( $id ) < DAY_IN_SECONDS ) {
			return array( 'tree' => self::TREE, 'branch' => '', 'said' => '', 'wait' => '' );
		}
		if ( ! $rec && false !== get_transient( self::NOT_MOTORS_TRANSIENT . $id ) ) {
			return $none;
		}

		$api = TCGiant_Sync_API::instance();

		// Known to be in eBay Motors, the branch not: straight to it.
		if ( ! $rec ) {
			// The item specifics of tree 0, which the push reads anyway and
			// the API keeps: any answer but 62005 leaves it ordinary. No
			// answer leaves it ordinary too, as before 3.28.9, for the hour.
			$ordinary = $api->get_category_aspects( $id );
			if ( is_wp_error( $ordinary ) && '62005' !== self::error_id( $ordinary ) ) {
				self::hold( $id, 'ordinary', $ordinary->get_error_message() );
				return $none;
			}
			if ( '62005' !== self::error_id( $ordinary ) ) {
				return $none;
			}
		}

		$node = $api->get_category_node( $id, self::TREE );
		if ( is_wp_error( $node ) ) {
			// In neither tree (an old ID eBay may still map, say): as before
			// 3.28.9, and tree 100 not asked again for a day.
			if ( '62005' === self::error_id( $node ) ) {
				set_transient( self::NOT_MOTORS_TRANSIENT . $id, 1, DAY_IN_SECONDS );
				return array( 'tree' => $rec ? self::TREE : '', 'branch' => '', 'said' => '', 'wait' => '' );
			}
			return self::held_for( $id, $node );
		}

		$branch = self::branch_known( $id, $node['parent'] );
		$learnt = array();

		if ( '' === $branch && '' !== $node['name'] ) {
			// eBay's suggestions for the category's own name carry each
			// one's ancestors: the category among them says its branch.
			$suggested = $api->get_category_suggestions_in_tree( $node['name'], self::TREE );
			if ( is_wp_error( $suggested ) ) {
				// Nothing learnt, so the hour's hold, not the day's, decides
				// when it is asked again.
				return self::held_for( $id, $suggested );
			}
			foreach ( $suggested as $s ) {
				if ( $id === (string) ( $s['id'] ?? '' ) ) {
					$branch = self::branch_of_ancestors( (array) ( $s['ancestors'] ?? array() ) );
					if ( '' === $branch ) {
						$branch = self::branch_of_path( self::motors_path( (string) ( $s['name'] ?? '' ) ) );
					}
					// Its ancestors are in the same branch: learnt too.
					$above = (array) ( $s['ancestors'] ?? array() );
					foreach ( $above as $i => $ancestor ) {
						if ( '' !== $branch && ! in_array( (string) $ancestor, array( self::PARTS, self::ROOT_ID ), true ) && ! isset( self::VEHICLES[ (string) $ancestor ] ) ) {
							$learnt[ (string) $ancestor ] = array( $branch, (string) ( $above[ $i + 1 ] ?? '' ) );
						}
					}
					break;
				}
			}
		}

		$learnt[ $id ] = array( $branch, $node['parent'] );
		self::learn( $learnt );

		return array(
			'tree'   => self::TREE,
			'branch' => $branch,
			'said'   => '' === $branch ? '' : sprintf( 'Category %1$s is an eBay Motors category (%2$s): pushes list it on eBay Motors.', $id, self::branch_label( $branch ) ),
			'wait'   => '',
		);
	}

	/**
	 * Hold a category eBay did not answer for, for HOLD_SECONDS.
	 *
	 * @param string $id    Category ID.
	 * @param string $step  'ordinary' (tree 0's read failed) or 'motors'.
	 * @param string $error eBay's error.
	 * @return void
	 */
	private static function hold( $id, $step, $error ) {
		set_transient( self::HOLD_TRANSIENT . $id, array( 'step' => $step, 'error' => (string) $error ), self::HOLD_SECONDS );
	}

	/**
	 * Tree 100 did not answer for a category tree 0 does not have: held for
	 * the hour, and a push in it waits.
	 *
	 * @param string   $id    Category ID.
	 * @param WP_Error $error eBay's error.
	 * @return array As resolve().
	 */
	private static function held_for( $id, WP_Error $error ) {
		self::hold( $id, 'motors', $error->get_error_message() );

		return array(
			'tree'   => self::TREE,
			'branch' => '',
			'said'   => sprintf( 'eBay did not say whether category %1$s is in eBay Motors Parts & Accessories (%2$s): it is asked again after an hour, and a push in it waits until then.', $id, $error->get_error_message() ),
			'wait'   => $error->get_error_message(),
		);
	}

	/**
	 * A path eBay gave for a tree-100 suggestion, under "eBay Motors" if it
	 * does not start there.
	 *
	 * @param string $path The path.
	 * @return string
	 */
	public static function motors_path( $path ) {
		$path = trim( (string) $path );

		return 0 === stripos( $path, self::ROOT_NAME ) ? $path : self::ROOT_NAME . ' > ' . $path;
	}

	/**
	 * The words for a branch.
	 *
	 * @param string $branch A BRANCH_ value.
	 * @return string
	 */
	public static function branch_label( $branch ) {
		if ( self::BRANCH_PARTS === $branch ) {
			return 'eBay Motors Parts & Accessories';
		}
		if ( self::BRANCH_VEHICLES === $branch ) {
			return 'an eBay Motors vehicle category';
		}

		return 'eBay Motors';
	}

	/**
	 * The item conditions eBay takes in a Motors category: Metadata
	 * getItemConditionPolicies for EBAY_MOTORS_US, kept CONDITIONS_TTL by
	 * tree and category.
	 *
	 * @param string $category_id Category ID.
	 * @return array|WP_Error 'required' (bool) and 'conditions' (ID => eBay's name for it).
	 */
	public static function conditions( $category_id ) {
		$id = self::clean_id( $category_id );
		if ( '' === $id ) {
			return new WP_Error( 'no_category', 'no category' );
		}

		$key    = self::CONDITIONS_TRANSIENT . self::TREE . '_' . $id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['conditions'] ) ) {
			return $cached;
		}

		$answer = TCGiant_Sync_API::instance()->get_condition_policies_for( $id, self::MARKETPLACE );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		foreach ( (array) ( $answer['itemConditionPolicies'] ?? array() ) as $policy ) {
			if ( ! is_array( $policy ) || $id !== (string) ( $policy['categoryId'] ?? '' ) ) {
				continue;
			}
			$conditions = array();
			foreach ( (array) ( $policy['itemConditions'] ?? array() ) as $condition ) {
				$cid = is_array( $condition ) ? (string) ( $condition['conditionId'] ?? '' ) : '';
				if ( '' !== $cid ) {
					$conditions[ $cid ] = (string) ( $condition['conditionDescription'] ?? '' );
				}
			}
			$out = array(
				'required'   => ! empty( $policy['itemConditionRequired'] ),
				'conditions' => $conditions,
			);
			set_transient( $key, $out, self::CONDITIONS_TTL );
			return $out;
		}

		// eBay's 46003, "The specified categoryId was not found for the
		// marketplace", comes as a warning with no policy in it.
		return new WP_Error( 'no_condition_policy', sprintf( 'eBay gave no item conditions for category %s on eBay Motors', $id ) );
	}

	/**
	 * What is known, without asking eBay, of the tree the settings' category
	 * is in (TCGiant_Sync_Exporter::get_export_settings()), by its ID alone:
	 * a name saved with a category - the product's, the shop default's -
	 * never decides it, since the eBay tab and Settings can leave one behind
	 * when the ID changes.
	 *
	 * - 'category_tree': TREE for eBay Motors; '' for the marketplace's own
	 *   tree; 'unknown' for a shop on eBay US whose category nothing here
	 *   knows yet, which a push settles (resolve()). The listing's own
	 *   category ('live') is in the tree of the listing's site
	 *   (listing_site()), its branch the listing's (listing_branch()). Any
	 *   other is ordinary when it is in the plugin's own list
	 *   (TCGiant_Sync_Catalog::CATEGORIES), and a Motors category when learnt
	 *   as one (record()).
	 * - '_motors_branch': for TREE, the BRANCH_ value known, or ''.
	 *
	 * @param array  $settings     Settings with category_id, _source,
	 *                             _listing_branch and _motors_applies
	 *                             (applies() of the shop's marketplace).
	 * @param string $listing_site The listing's site (listing_site()), ''
	 *                             for none known.
	 * @return array<string,string>
	 */
	public static function category_fields( array $settings, $listing_site ) {
		$out = array( 'category_tree' => '', '_motors_branch' => '' );
		$id  = self::clean_id( $settings['category_id'] ?? '' );
		if ( empty( $settings['_motors_applies'] ) || '' === $id ) {
			return $out;
		}

		if ( 'live' === ( $settings['_source']['category'] ?? '' ) ) {
			if ( self::SITE === (string) $listing_site ) {
				$out['category_tree']  = self::TREE;
				$out['_motors_branch'] = (string) ( $settings['_listing_branch'] ?? '' );
			}
			return $out;
		}

		if ( isset( TCGiant_Sync_Catalog::CATEGORIES[ $id ] ) ) {
			return $out;
		}

		$rec = self::record( $id );
		if ( ! $rec ) {
			$out['category_tree'] = 'unknown';
			return $out;
		}

		$out['category_tree']  = self::TREE;
		$out['_motors_branch'] = $rec['b'];

		return $out;
	}

	/**
	 * The eBay site of the listing a product is linked to, from the listing
	 * itself: the site the import, a push or a read of it recorded
	 * (_ebay_site); else, where the last read of it recorded its category,
	 * eBay's own path for that category (_ebay_live) - eBay lists its Motors
	 * categories on eBay Motors alone; else, for a listing a push made -
	 * TCGiant_Sync_Ebay_Live::origin() 'push', on its evidence; never
	 * 'import' or UNKNOWN - the shop's own site, since every push that
	 * makes a listing records its
	 * site (TCGiant_Sync_Exporter::record_listing_made(), from 3.27.5) and a
	 * push lists on eBay Motors only from 3.28.9; else, where the shop's
	 * imports have never met a listing on eBay Motors (motors_seen()), the
	 * shop's own site, for none of its listings is there. Never the category
	 * a push would send. '' when none of those is known: the push reads the
	 * listing first, which records its site.
	 *
	 * @param string $recorded    _ebay_site.
	 * @param array  $snapshot    TCGiant_Sync_Ebay_Live::snapshot().
	 * @param string $origin      TCGiant_Sync_Ebay_Live::origin().
	 * @param string $home_site   The shop's marketplace's site code.
	 * @param bool   $motors_seen motors_seen().
	 * @return string
	 */
	public static function listing_site( $recorded, array $snapshot, $origin, $home_site, $motors_seen = true ) {
		$recorded = (string) $recorded;
		if ( '' !== $recorded ) {
			return $recorded;
		}

		if ( '' !== self::clean_id( $snapshot['category_id'] ?? '' ) && '' !== (string) ( $snapshot['category_name'] ?? '' ) ) {
			return '' !== self::branch_of_path( $snapshot['category_name'] ) ? self::SITE : (string) $home_site;
		}

		return 'push' === (string) $origin || ! $motors_seen ? (string) $home_site : '';
	}

	/**
	 * Whether listing_site() or listing_branch() turn on who made the
	 * listing: no site recorded and nothing read of its category, or a
	 * listing on eBay Motors whose category does not say its part. Only then
	 * is the origin worked out (TCGiant_Sync_Ebay_Live::origin(), which can
	 * read the database).
	 *
	 * @param string $recorded _ebay_site.
	 * @param array  $snapshot TCGiant_Sync_Ebay_Live::snapshot().
	 * @return bool
	 */
	public static function needs_origin( $recorded, array $snapshot ) {
		$recorded = (string) $recorded;
		if ( '' === $recorded ) {
			return '' === self::clean_id( $snapshot['category_id'] ?? '' ) || '' === (string) ( $snapshot['category_name'] ?? '' );
		}

		return self::SITE === $recorded && '' === self::listing_branch( $snapshot, '' );
	}

	/**
	 * Whether the shop's imports have met a listing on eBay Motors: the
	 * sites they record each listing's on (TCGiant_Sync_Sites::SEEN_OPTION,
	 * since 3.27.5), at any time. A shop with none there has no listing a
	 * push need read for its site.
	 *
	 * @return bool
	 */
	public static function motors_seen() {
		$seen = get_option( 'tcgiant_sync_sites_seen', array() );

		return is_array( $seen ) && isset( $seen[ self::SITE ] );
	}

	/**
	 * Which part of eBay Motors the listing a product is linked to is in,
	 * for one there: its own category's branch, by that category's ID (what
	 * was learnt of it), else eBay's own path for it, as the last read of the
	 * listing recorded the two together (_ebay_live); else Parts &
	 * Accessories for a listing a push made, the only part a push lists in.
	 * '' when not known: the push reads the listing first.
	 *
	 * @param array  $snapshot TCGiant_Sync_Ebay_Live::snapshot().
	 * @param string $origin   TCGiant_Sync_Ebay_Live::origin().
	 * @return string A BRANCH_ value, or ''.
	 */
	public static function listing_branch( array $snapshot, $origin ) {
		$id = self::clean_id( $snapshot['category_id'] ?? '' );
		if ( '' !== $id ) {
			$rec = self::record( $id );
			if ( $rec && '' !== $rec['b'] ) {
				return $rec['b'];
			}
			$branch = self::branch_of_path( $snapshot['category_name'] ?? '' );
			if ( '' !== $branch ) {
				return $branch;
			}
		}

		return 'push' === (string) $origin ? self::BRANCH_PARTS : '';
	}

	/**
	 * The site a push of these settings goes to: SITE for eBay Motors, ''
	 * for the shop's own marketplace's, as before.
	 *
	 * A revise goes by the listing it revises, never by a category: eBay
	 * Motors for a Parts & Accessories listing there (listing_site(),
	 * listing_branch(): _listing_site and _listing_branch in the settings).
	 * Any other listing on eBay Motors - a whole vehicle, or one whose part
	 * of eBay Motors is not known - is revised as before 3.28.9, on the
	 * shop's own site with its own site's Item.Site. A new listing, and
	 * Verify, go by the category: eBay Motors for one in tree 100.
	 *
	 * @param array $settings TCGiant_Sync_Exporter::get_export_settings().
	 * @param bool  $revise   A revise of a live listing.
	 * @return string
	 */
	public static function request_site( array $settings, $revise ) {
		if ( empty( $settings['_motors_applies'] ) ) {
			return '';
		}

		if ( $revise ) {
			return self::SITE === (string) ( $settings['_listing_site'] ?? '' ) && self::BRANCH_PARTS === (string) ( $settings['_listing_branch'] ?? '' ) ? self::SITE : '';
		}

		return self::TREE === (string) ( $settings['category_tree'] ?? '' ) ? self::SITE : '';
	}

	/**
	 * Whether a shop on eBay US has a listing whose site, or, on eBay
	 * Motors, whose part of eBay Motors, the listing itself has not said
	 * (listing_site(), listing_branch()): the push reads it first.
	 *
	 * @param array $settings TCGiant_Sync_Exporter::get_export_settings() of
	 *                        a product with a listing.
	 * @return bool
	 */
	public static function site_unknown( array $settings ) {
		if ( empty( $settings['_motors_applies'] ) ) {
			return false;
		}
		$site = (string) ( $settings['_listing_site'] ?? '' );

		return '' === $site || ( self::SITE === $site && '' === (string) ( $settings['_listing_branch'] ?? '' ) );
	}

	/**
	 * Whether a revise of a listing on eBay Motors goes as it did before
	 * 3.28.9, because the listing is a whole vehicle (or its part of eBay
	 * Motors is not known): the push says so once.
	 *
	 * @param array $settings TCGiant_Sync_Exporter::get_export_settings().
	 * @return bool
	 */
	public static function revised_as_before( array $settings ) {
		return ! empty( $settings['_motors_applies'] ) && self::SITE === (string) ( $settings['_listing_site'] ?? '' ) && '' === self::request_site( $settings, true );
	}

	/**
	 * Make a listing call for a listing on eBay Motors (3.28.9).
	 *
	 * A new listing and Verify go with X-EBAY-API-SITEID 100, as eBay's
	 * Motors guide has it ("To list any item in US eBay Motors categories,
	 * the Site ID must be set to '100'"). A revise and a relist go with the
	 * site eBay last took one with (SITES_OPTION; 100 until eBay says
	 * otherwise): eBay's pages disagree on a revise - its Motors revise guide
	 * says to "specify "0" as the site ID" with Item.Site eBayMotors, its
	 * PrimaryCategory reference that the category must be valid on the
	 * request site's tree - and say nothing of a relist.
	 *
	 * Each listing keeps the site eBay took it with (LISTING_SITE_META, on
	 * its product), and its later calls of that kind go with it first; one
	 * with none yet goes with the site eBay last took for any listing
	 * (SITES_OPTION; 100 until eBay says otherwise), and keeps whichever
	 * eBay takes. When eBay refuses one for the site it went to
	 * (refused_for_site()), it is sent once more with the other site, the
	 * body as it was; when eBay takes that, the listing keeps it, it becomes
	 * the first guess for listings with none, and the log says so. So a
	 * listing costs at most one call more, once, however the shop's
	 * listings differ.
	 *
	 * @param string   $kind        'revise', 'relist', or anything else for a
	 *                              new listing or Verify.
	 * @param callable $call        The call.
	 * @param string   $label       Which listing, for the log.
	 * @param int      $product_id  The product whose listing it is; 0 for none.
	 * @param string   $category_id The listing's category, as the call has
	 *                              it (refused_for_site()).
	 * @return mixed What the call returns.
	 */
	public static function listing_call( $kind, callable $call, $label = '', $product_id = 0, $category_id = '' ) {
		$api = TCGiant_Sync_API::instance();
		if ( ! isset( self::LISTING_SITE_META[ $kind ] ) ) {
			return $api->with_site( self::SITE_ID, $call );
		}

		$product_id = (int) $product_id;
		$meta       = self::LISTING_SITE_META[ $kind ];
		$own        = $product_id > 0 ? (string) get_post_meta( $product_id, $meta, true ) : '';
		$first      = in_array( $own, array( self::SITE_ID, self::HOME_SITE_ID ), true ) ? $own : self::call_site( $kind );
		$result     = $api->with_site( $first, $call );
		if ( ! self::refused_for_site( $result, $category_id ) ) {
			if ( $product_id > 0 && ! is_wp_error( $result ) && $own !== $first ) {
				update_post_meta( $product_id, $meta, $first );
			}
			return $result;
		}

		$other = self::SITE_ID === $first ? self::HOME_SITE_ID : self::SITE_ID;
		$retry = $api->with_site( $other, $call );
		if ( is_wp_error( $retry ) ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'eBay Motors: eBay refused the %1$s of %2$s with X-EBAY-API-SITEID %3$s (%4$s), and with %5$s (%6$s).',
				$kind,
				$label,
				$first,
				$result->get_error_message(),
				$other,
				$retry->get_error_message()
			) );
			return $retry;
		}

		if ( $product_id > 0 ) {
			update_post_meta( $product_id, $meta, $other );
		}
		$sites          = get_option( self::SITES_OPTION, array() );
		$sites          = is_array( $sites ) ? $sites : array();
		$sites[ $kind ] = $other;
		update_option( self::SITES_OPTION, $sites, false );
		TCGiant_Sync_Logger::warning( sprintf(
			'eBay Motors: eBay refused the %1$s of %2$s with X-EBAY-API-SITEID %3$s (%4$s) and took it with %5$s, so that listing\'s %1$s goes with %5$s first from now on, as does that of any eBay Motors listing eBay has not yet taken one with.',
			$kind,
			$label,
			$first,
			$result->get_error_message(),
			$other
		) );

		return $retry;
	}

	/**
	 * The X-EBAY-API-SITEID a revise or relist of an eBay Motors listing
	 * goes with first: the one eBay last took, else SITE_ID.
	 *
	 * @param string $kind 'revise' or 'relist'.
	 * @return string
	 */
	public static function call_site( $kind ) {
		$sites = get_option( self::SITES_OPTION, array() );
		$site  = is_array( $sites ) ? (string) ( $sites[ $kind ] ?? '' ) : '';

		return in_array( $site, array( self::SITE_ID, self::HOME_SITE_ID ), true ) ? $site : self::SITE_ID;
	}

	/**
	 * Whether eBay refused a call for the site it went to: its answer a
	 * Failure (Ack), with an error - SeverityCode Error, not a warning
	 * beside some other cause - of SITE_ERRORS; one of CATEGORY_ERRORS only
	 * where it points at the site or the listing's category
	 * (points_at_site()). Anything else is eBay's answer, not tried again.
	 *
	 * @param mixed  $result      A Trading call's answer.
	 * @param string $category_id The listing's category, as the call has it.
	 * @return bool
	 */
	public static function refused_for_site( $result, $category_id = '' ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}
		$data = $result->get_error_data();
		if ( ! is_array( $data ) || 'Failure' !== TCGiant_Sync_API::scalar_value( $data['Ack'] ?? '' ) ) {
			return false;
		}

		$errors = isset( $data['Errors'] ) && is_array( $data['Errors'] ) ? $data['Errors'] : array();
		if ( isset( $errors['ErrorCode'] ) ) {
			$errors = array( $errors );
		}
		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}
			$code = TCGiant_Sync_API::scalar_value( $error['ErrorCode'] ?? '' );
			if ( ! in_array( $code, self::SITE_ERRORS, true ) || 'Error' !== TCGiant_Sync_API::scalar_value( $error['SeverityCode'] ?? '' ) ) {
				continue;
			}
			if ( in_array( $code, self::CATEGORY_ERRORS, true ) && ! self::points_at_site( $error, $category_id ) ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * Whether one of eBay's errors points at the site the call went to, or
	 * at the listing's own category: its words name the site, or one of its
	 * parameters is that category's ID or names the site.
	 *
	 * @param array  $error       One of eBay's Errors.
	 * @param string $category_id The listing's category.
	 * @return bool
	 */
	private static function points_at_site( array $error, $category_id ) {
		$category_id = self::clean_id( $category_id );
		$words       = TCGiant_Sync_API::scalar_value( $error['ShortMessage'] ?? '' ) . ' ' . TCGiant_Sync_API::scalar_value( $error['LongMessage'] ?? '' );
		if ( 1 === preg_match( '/\bsite\b/i', $words ) ) {
			return true;
		}

		// One parameter comes as itself, its Value among its keys; several as a
		// list: either way each Value is met.
		$params = isset( $error['ErrorParameters'] ) && is_array( $error['ErrorParameters'] ) ? $error['ErrorParameters'] : array();
		foreach ( $params as $param ) {
			$value = trim( TCGiant_Sync_API::scalar_value( is_array( $param ) ? ( $param['Value'] ?? '' ) : $param ) );
			if ( ( '' !== $category_id && $category_id === $value ) || 1 === preg_match( '/\bsite\b/i', $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Why a push of these settings cannot go, by group, each in words for the
	 * shop (TCGiant_Sync_Exporter::validate_export_settings()). For an
	 * ordinary push, only a business policy for motor vehicles only (3.29.2,
	 * policies_problem()). Asks eBay nothing: what the push read first
	 * (TCGiant_Sync_Exporter::settle_motors()) is in the settings.
	 *
	 * @param array $settings    The settings, as the push settled them.
	 * @param bool  $new_listing A new listing (or Verify), not a revise.
	 * @return array<string,string> 'category', 'condition', 'policies'.
	 */
	public static function problems( array $settings, $new_listing ) {
		$problems = array();
		if ( empty( $settings['_motors_applies'] ) ) {
			return $problems;
		}

		$revise        = ! $new_listing;
		$site          = self::request_site( $settings, $revise );
		$tree          = (string) ( $settings['category_tree'] ?? '' );
		$category_id   = (string) ( $settings['category_id'] ?? '' );
		$sends         = class_exists( 'TCGiant_Sync_Exporter' );
		$send_category = $sends && TCGiant_Sync_Exporter::sends( $settings, 'category', $revise );
		$listing       = (string) ( $settings['_listing_site'] ?? '' );

		// eBay does not move a listing between eBay Motors and another site:
		// by the site the listing is on, whatever site the revise goes to.
		// Not for a listing revised as before 3.28.9 - a whole vehicle, or
		// one whose part of eBay Motors is not known - whose Update goes
		// exactly as 3.28.8 sent it, whatever category it sends.
		if ( $revise && $send_category && '' !== $listing && '' !== $category_id && 'unknown' !== $tree && ! self::revised_as_before( $settings ) ) {
			if ( self::SITE === $listing && self::TREE !== $tree ) {
				/* translators: %s: eBay category ID */
				$problems['category'] = sprintf( __( 'this listing is on eBay Motors and %s is not an eBay Motors category: eBay does not move a listing to another site. End the listing and push again to list it in that category', 'tcgiant-sync' ), $category_id );
			} elseif ( self::SITE !== $listing && self::TREE === $tree ) {
				/* translators: %s: eBay category ID */
				$problems['category'] = sprintf( __( 'this listing is not on eBay Motors and %s is an eBay Motors category: eBay does not move a listing to another site. End the listing and push again to list it on eBay Motors', 'tcgiant-sync' ), $category_id );
			}
		}

		if ( isset( $problems['category'] ) ) {
			return $problems;
		}

		// Not on eBay Motors: an ordinary push is held to its business
		// policies alone (3.29.2), where its listing cannot be a motor
		// vehicle's - a new listing, made on the shop's own site, or a revise
		// of a listing on eBay US (HOME_SITE): eBay US lists every motor
		// vehicle on eBay Motors, so a policy for motor vehicles only is one
		// eBay refuses there. A listing on eBay Motors revised as before
		// 3.28.9 - a whole vehicle, or one whose part of eBay Motors is not
		// known (revised_as_before()) - and one on another site, or whose
		// site is not known, are held to nothing new here: a vehicle
		// listing's own policy may well be for vehicles only.
		if ( self::SITE !== $site ) {
			$policies = $new_listing || self::HOME_SITE === $listing ? self::policies_problem( $settings, $revise, false ) : '';
			if ( '' !== $policies ) {
				$problems['policies'] = $policies;
			}
			return $problems;
		}

		// A new listing's category, or the one a revise sends. The listing's
		// own, which a revise leaves as it is, is the listing's business.
		$branch = (string) ( $settings['_motors_branch'] ?? '' );
		$wait   = (string) ( $settings['_motors_wait'] ?? '' );
		if ( self::BRANCH_VEHICLES === $branch && ( $new_listing || $send_category ) ) {
			/* translators: %s: eBay category ID */
			$problems['category'] = sprintf( __( '%s is an eBay Motors vehicle category, and TCGiant Sync lists only eBay Motors Parts & Accessories: cars and trucks, motorcycles, powersports, boats and other whole vehicles are not supported. Choose a Parts & Accessories category', 'tcgiant-sync' ), $category_id );
		} elseif ( ( $new_listing || $send_category ) && self::TREE === $tree && self::BRANCH_PARTS !== $branch && '' !== $wait ) {
			/* translators: 1: eBay category ID, 2: eBay's error */
			$problems['category'] = sprintf( __( 'eBay did not say whether %1$s is an eBay Motors Parts & Accessories category (%2$s), so nothing was sent: try again in an hour', 'tcgiant-sync' ), $category_id, $wait );
		} elseif ( ( $new_listing || $send_category ) && self::TREE === $tree && self::BRANCH_PARTS !== $branch ) {
			/* translators: %s: eBay category ID */
			$problems['category'] = sprintf( __( '%s is an eBay Motors category that could not be confirmed as Parts & Accessories, the only part of eBay Motors TCGiant Sync lists in: choose it with Browse eBay Categories, under eBay Motors: Parts & Accessories', 'tcgiant-sync' ), $category_id );
		}

		$send_condition = $sends && TCGiant_Sync_Exporter::sends( $settings, 'condition', $revise );
		$item_type      = (string) ( $settings['item_type'] ?? '' );
		if ( $send_condition && in_array( $item_type, array( 'tcg', 'coins' ), true ) ) {
			$problems['condition'] = __( 'eBay Motors categories take an ordinary item condition, not a trading card\'s or a coin\'s: set Item Type to All Other and choose a condition', 'tcgiant-sync' );
		} elseif ( $send_condition && isset( $settings['_motors_conditions_failed'] ) ) {
			/* translators: 1: eBay category ID, 2: eBay's error */
			$problems['condition'] = sprintf( __( 'could not read which item conditions eBay takes in eBay Motors category %1$s (%2$s), so nothing was sent: push again later', 'tcgiant-sync' ), $category_id, (string) $settings['_motors_conditions_failed'] );
		} elseif ( $send_condition && isset( $settings['_motors_conditions'] ) && is_array( $settings['_motors_conditions'] ) ) {
			$taken = (array) $settings['_motors_conditions'];
			$sent  = (string) ( $settings['condition_id'] ?? '' );
			if ( ! isset( $taken[ $sent ] ) ) {
				$list = array();
				foreach ( $taken as $cid => $name ) {
					$list[] = trim( $cid . ' ' . $name );
				}
				$problems['condition'] = $list
					/* translators: 1: condition ID, 2: eBay category ID, 3: the conditions it takes */
					? sprintf( __( '%1$s is not a condition eBay takes in eBay Motors category %2$s. It takes: %3$s', 'tcgiant-sync' ), '' !== $sent ? $sent : __( 'None', 'tcgiant-sync' ), $category_id, implode( ', ', $list ) )
					/* translators: %s: eBay category ID */
					: sprintf( __( 'eBay takes no item condition in eBay Motors category %s, and a push always sends one: choose another category', 'tcgiant-sync' ), $category_id );
			}
		}

		// A business policy known to be for motor vehicles only.
		$policies = self::policies_problem( $settings, $revise, true );
		if ( '' !== $policies ) {
			$problems['policies'] = $policies;
		}

		return $problems;
	}

	/**
	 * A business policy a push of these settings sends that is known to be
	 * for motor vehicles only, in words for the shop (3.28.9 for eBay Motors
	 * Parts & Accessories, 3.29.2 for any push problems() holds to it); ''
	 * for none. Nothing for a push that sends no business policies - an
	 * imported listing's revise that leaves eBay's own
	 * (TCGiant_Sync_Exporter::sends()) - nor for a policy whose category
	 * types are not known (vehicles_only_policy()): those go, for eBay to
	 * judge, as before.
	 *
	 * An eBay Motors push is told in 3.28.9's words; an ordinary push in its
	 * own (3.29.2), which name the policy as shipping or payment and say
	 * nothing of eBay Motors, where it is not listed.
	 *
	 * @param array $settings The settings, as the push settled them.
	 * @param bool  $revise   A revise, not a new listing.
	 * @param bool  $motors   A push to eBay Motors.
	 * @return string
	 */
	private static function policies_problem( array $settings, $revise, $motors ) {
		if ( ! class_exists( 'TCGiant_Sync_Exporter' ) || ! TCGiant_Sync_Exporter::sends( $settings, 'policies', $revise ) ) {
			return '';
		}

		foreach ( array( 'fulfillment' => 'fulfillment_policy_id', 'payment' => 'payment_policy_id' ) as $type => $key ) {
			$name = self::vehicles_only_policy( $type, (string) ( $settings[ $key ] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			if ( $motors ) {
				/* translators: %s: business policy name */
				return sprintf( __( '%s is a business policy for motor vehicles only, and eBay Motors Parts & Accessories take one for everything except motor vehicles: choose another', 'tcgiant-sync' ), $name );
			}
			return 'fulfillment' === $type
				/* translators: %s: business policy name */
				? sprintf( __( 'This product\'s shipping policy "%s" is for motor vehicles only on eBay, so eBay would refuse this listing. Choose another business policy for it, or press Fetch / Refresh Policies from eBay in Settings if you\'ve changed it on eBay', 'tcgiant-sync' ), $name )
				/* translators: %s: business policy name */
				: sprintf( __( 'This product\'s payment policy "%s" is for motor vehicles only on eBay, so eBay would refuse this listing. Choose another business policy for it, or press Fetch / Refresh Policies from eBay in Settings if you\'ve changed it on eBay', 'tcgiant-sync' ), $name );
		}

		return '';
	}

	/**
	 * The name of a cached business policy whose category types eBay gave and
	 * leave out ALL_EXCLUDING_MOTORS_VEHICLES; '' otherwise, and for any
	 * policy fetched before 3.28.9, whose types were not kept.
	 *
	 * @param string $type 'fulfillment' or 'payment'.
	 * @param string $id   Policy ID.
	 * @return string
	 */
	private static function vehicles_only_policy( $type, $id ) {
		if ( '' === $id ) {
			return '';
		}
		$cached = get_option( 'tcgiant_export_policies_' . $type, array() );
		foreach ( is_array( $cached ) ? $cached : array() as $row ) {
			if ( is_array( $row ) && $id === (string) ( $row['id'] ?? '' ) && isset( $row['category_types'] ) && is_array( $row['category_types'] )
				&& $row['category_types'] && ! in_array( 'ALL_EXCLUDING_MOTORS_VEHICLES', $row['category_types'], true ) ) {
				return (string) ( $row['name'] ?? $id );
			}
		}

		return '';
	}
}
