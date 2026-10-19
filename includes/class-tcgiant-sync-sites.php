<?php
/**
 * Which eBay site a listing is on, and whether this shop imports it.
 *
 * eBay hands the plugin a seller's listings from every eBay site they sell on,
 * whatever site the request names: GetSellerList, GetSellerEvents, GetItem and
 * both order calls take no site to filter on. A seller who copies their eBay.com
 * listings onto eBay.co.uk, eBay.de and the rest - by hand, or with a service
 * such as Web Interpret, which makes seven or eight copies of every listing,
 * each one a listing of its own with its own item number and its own site, and
 * the same SKU and store categories - had every copy imported as a product of
 * its own, priced in a currency the shop does not use. A store-category
 * allowlist cannot tell them apart, because the copies carry the same store
 * categories as the original.
 *
 * "Import listings from" (Settings, Import Filters) lets a shop take only the
 * listings on its Primary eBay Marketplace. What decides is the LISTING's site:
 * Item.Site in the Trading API, listingMarketplaceId in the Fulfillment API.
 * Never Item.Country, which is only where the item sits; never the seller's own
 * country - a seller registered in India who lists on eBay.com is selling on
 * eBay United States; and never the buyer's site - a buyer in the United
 * Kingdom who finds a United States listing through international visibility is
 * still buying that United States listing.
 *
 * The rule, wherever a listing comes in:
 * - a listing on another site never creates a product, never links to a
 *   product by SKU, and never takes over a product whose own listing ended;
 * - its sales never change the stock of a product that is not linked to that
 *   same listing;
 * - a product ALREADY linked to that exact listing keeps syncing - stock,
 *   price, the settle when it ends - until the shop removes it, so the website
 *   never sells from figures that quietly stopped being true. Stock Review
 *   lists those products and can move them to the trash.
 *
 * All sites is the default, for every shop, new or old, and nothing is written
 * on upgrade. The marketplace setting is the weakest one in the plugin: the form
 * shows United States until a shop changes it, and a blank one is taken from the
 * first listing seen. An import that depended on it unasked would leave a shop
 * in the United Kingdom that saved the form without reading it with nothing
 * imported at all.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.27.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Sites class.
 */
class TCGiant_Sync_Sites {

	/** Settings key, and its two values. Anything else reads as ALL. */
	const SETTING = 'import_sites';
	const PRIMARY = 'primary';
	const ALL     = 'all';

	/** Option: site code => when a listing on it was last seen (unix time). */
	const SEEN_OPTION = 'tcgiant_sync_sites_seen';

	/** Option: what the last clean full sync found when it saw nothing on the primary site. */
	const MISMATCH_OPTION = 'tcgiant_sync_sites_mismatch';

	/** Option: the sites the shop had when it dismissed the "more than one site" notice. */
	const DISMISSED_OPTION = 'tcgiant_sync_sites_notice_dismissed';

	/**
	 * Transient prefix: a listing from another site was skipped and said so.
	 *
	 * Once per listing per SKIP_LOG_DAYS. A shop with thousands of copies on
	 * other sites would otherwise get a line for each of them on every weekly
	 * full sync, and the lines that matter would be lost among them.
	 */
	const SKIP_TRANSIENT = 'tcgiant_site_skip_';
	const SKIP_LOG_DAYS  = 10;

	/**
	 * Transient prefix: the site eBay's GetItem gave for a delta row that had
	 * none, by item, for SKIP_LOG_DAYS.
	 *
	 * Without it a listing on another site with no product was asked about
	 * again every time it changed. A listing never moves site, so the answer
	 * stays true.
	 */
	const SITE_TRANSIENT = 'tcgiant_site_of_';

	/**
	 * Transient prefix, by user: the list Stock Review last drew, and the token
	 * its button posts back, so the cleanup acts on exactly what was shown.
	 */
	const LIST_TRANSIENT = 'tcgiant_sites_list_';

	/**
	 * Products moved to the trash in one request of the cleanup. It asks eBay
	 * nothing, so this can be many more than the five of a job that does.
	 */
	const CLEANUP_BATCH = 50;

	/**
	 * Meta a push that succeeded writes. Either one, or one of OWN_STATUSES,
	 * makes a product the shop's own - see is_shops_own().
	 */
	const PUSHED_KEYS = array( '_ebay_export_last_pushed', '_ebay_export_pushed_version' );

	/**
	 * Export statuses that make a product the shop's own: a push that
	 * succeeded, and one on its way or that did not finish - see
	 * is_shops_own(). Never error.
	 */
	const OWN_STATUSES = array( 'pushed', 'queued', 'pushing', 'failed' );

	/**
	 * Sites that count as the marketplace itself.
	 *
	 * eBay Motors is a vertical inside eBay US - eBay's SiteCodeType says it is
	 * "no longer its own site". CanadaFrench is eBay.ca in French. Whether a
	 * listing made there reports CanadaFrench or Canada is not confirmed, so
	 * both are taken as Canada.
	 */
	const SITE_ALIASES = array(
		'EBAY_US' => array( 'eBayMotors' ),
		'EBAY_CA' => array( 'CanadaFrench' ),
	);

	/** The same, in the Fulfillment API's MarketplaceIdEnum. */
	const MARKETPLACE_ALIASES = array(
		'EBAY_US' => array( 'EBAY_MOTORS_US' ),
	);

	/**
	 * Sites met in this request, written once at shutdown.
	 *
	 * @var array<string, bool>
	 */
	private static $noted = array();

	/**
	 * Why this request has already said the cleanup stopped: once each.
	 *
	 * @var array<string, bool>
	 */
	private static $stop_said = array();

	/**
	 * The marketplace the filter keys on, or '' when it is not in force.
	 *
	 * Not in force unless the shop chose "only my Primary eBay Marketplace" AND a
	 * marketplace the plugin supports is actually saved. A blank one reads as
	 * United States elsewhere and is filled from the first listing mapped, so
	 * filtering on it could keep the wrong site's listings and drop the shop's
	 * own. While the filter is chosen the mapper does not fill a blank one at
	 * all (see maybe_adopt_marketplace()), so a run cannot start keeping a
	 * different site part way through.
	 *
	 * @param array|null $settings Settings; read when null.
	 * @return string Marketplace ID, e.g. EBAY_US.
	 */
	public static function primary_marketplace( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		}

		if ( ! is_array( $settings ) || self::PRIMARY !== ( $settings[ self::SETTING ] ?? '' ) ) {
			return '';
		}

		$id = $settings['marketplace'] ?? '';

		return is_string( $id ) && isset( TCGiant_Sync_API::MARKETPLACES[ $id ] ) ? $id : '';
	}

	/**
	 * Whether listings on other eBay sites are being left out.
	 *
	 * @param array|null $settings Settings; read when null.
	 * @return bool
	 */
	public static function primary_only( $settings = null ) {
		return '' !== self::primary_marketplace( $settings );
	}

	/**
	 * Trading API site codes that count as the primary marketplace.
	 *
	 * @param array|null $settings Settings; read when null.
	 * @return string[] Empty when the filter is not in force.
	 */
	public static function primary_sites( $settings = null ) {
		$id = self::primary_marketplace( $settings );

		if ( '' === $id ) {
			return array();
		}

		return array_merge( array( TCGiant_Sync_API::MARKETPLACES[ $id ]['site'] ), self::SITE_ALIASES[ $id ] ?? array() );
	}

	/**
	 * "eBay United States" for the marketplace the filter keeps.
	 *
	 * @param array|null $settings Settings; read when null.
	 * @return string '' when the filter is not in force.
	 */
	public static function primary_label( $settings = null ) {
		$id = self::primary_marketplace( $settings );

		return '' === $id ? '' : 'eBay ' . TCGiant_Sync_API::MARKETPLACES[ $id ]['label'];
	}

	/**
	 * The listing site of a Trading API item, '' when eBay did not say.
	 *
	 * Takes anything. An order's transaction with an empty <Item/> arrives as an
	 * empty array or a string, and a TypeError here would end the order import
	 * part way through eBay's answer, with every order after it lost.
	 *
	 * @param mixed $ebay_item Item from GetSellerList, GetSellerEvents, GetItem or GetOrders.
	 * @return string e.g. US, UK, eBayMotors.
	 */
	public static function site_of( $ebay_item ) {
		if ( ! is_array( $ebay_item ) || ! isset( $ebay_item['Site'] ) ) {
			return '';
		}

		return trim( TCGiant_Sync_API::scalar_value( $ebay_item['Site'] ) );
	}

	/**
	 * Whether a listing on this site is left out of the import.
	 *
	 * An unknown site ('') is never left out here. The delta asks eBay first
	 * when it can (GetItem always says), and anything still unknown is let
	 * through with a warning: a product that should not be here can be removed,
	 * but a listing silently dropped never comes back. A site the plugin does
	 * not support - eBay Austria, say - is another site like any other.
	 *
	 * @param string     $site     Item.Site.
	 * @param array|null $settings Settings; read when null.
	 * @return bool
	 */
	public static function leaves_out( $site, $settings = null ) {
		$site = (string) $site;

		if ( '' === $site || ! self::primary_only( $settings ) ) {
			return false;
		}

		return ! in_array( $site, self::primary_sites( $settings ), true );
	}

	/**
	 * The same, for a Fulfillment line item's listingMarketplaceId.
	 *
	 * The listing's marketplace, never purchaseMarketplaceId: that is where the
	 * buyer was, and a buyer abroad who found a listing on the shop's own site
	 * is buying from the shop's own site.
	 *
	 * @param string     $marketplace_id e.g. EBAY_GB.
	 * @param array|null $settings       Settings; read when null.
	 * @return bool
	 */
	public static function leaves_out_marketplace( $marketplace_id, $settings = null ) {
		$marketplace_id = is_scalar( $marketplace_id ) ? trim( (string) $marketplace_id ) : '';
		$primary        = self::primary_marketplace( $settings );

		if ( '' === $marketplace_id || '' === $primary ) {
			return false;
		}

		return ! in_array( $marketplace_id, array_merge( array( $primary ), self::MARKETPLACE_ALIASES[ $primary ] ?? array() ), true );
	}

	/**
	 * The marketplace a site belongs to, aliases included.
	 *
	 * @param string $site Item.Site.
	 * @return string Marketplace ID, or '' for a site the plugin does not support.
	 */
	public static function marketplace_of( $site ) {
		$site = (string) $site;

		foreach ( TCGiant_Sync_API::MARKETPLACES as $id => $m ) {
			if ( $m['site'] === $site || in_array( $site, self::SITE_ALIASES[ $id ] ?? array(), true ) ) {
				return $id;
			}
		}

		return '';
	}

	/**
	 * "eBay United Kingdom" for UK; "eBay Austria" for a site the plugin does
	 * not support, so the log can still name it.
	 *
	 * @param string $site Item.Site.
	 * @return string
	 */
	public static function label( $site ) {
		$id = self::marketplace_of( $site );

		if ( '' !== $id ) {
			return 'eBay ' . TCGiant_Sync_API::MARKETPLACES[ $id ]['label'];
		}

		return 'eBay ' . ( '' !== (string) $site ? (string) $site : '(unknown site)' );
	}

	/**
	 * Remember that a listing on this site was seen.
	 *
	 * Costs nothing per listing: the option is written at most once a request,
	 * at shutdown, and only when a site is new or was last recorded more than a
	 * day ago.
	 *
	 * @param string $site Item.Site.
	 * @return void
	 */
	public static function note( $site ) {
		$site = (string) $site;

		if ( '' === $site ) {
			return;
		}

		if ( empty( self::$noted ) && function_exists( 'add_action' ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_seen' ) );
		}

		self::$noted[ $site ] = true;
	}

	/**
	 * Write down the sites this request met.
	 *
	 * @return void
	 */
	public static function flush_seen() {
		if ( empty( self::$noted ) ) {
			return;
		}

		$seen  = get_option( self::SEEN_OPTION, array() );
		$seen  = is_array( $seen ) ? $seen : array();
		$now   = time();
		$dirty = false;

		foreach ( array_keys( self::$noted ) as $site ) {
			if ( (int) ( $seen[ $site ] ?? 0 ) < $now - DAY_IN_SECONDS ) {
				$seen[ $site ] = $now;
				$dirty         = true;
			}
		}

		self::$noted = array();

		if ( $dirty ) {
			update_option( self::SEEN_OPTION, $seen, false );
		}
	}

	/**
	 * Site codes with a listing seen in the last $days days.
	 *
	 * @param int $days Look-back.
	 * @return string[]
	 */
	public static function seen( $days = 30 ) {
		$seen = get_option( self::SEEN_OPTION, array() );
		$out  = array();

		foreach ( is_array( $seen ) ? $seen : array() as $site => $ts ) {
			if ( (int) $ts >= time() - (int) $days * DAY_IN_SECONDS ) {
				$out[] = (string) $site;
			}
		}

		sort( $out );

		return $out;
	}

	/**
	 * The eBay sites seen, one name per marketplace.
	 *
	 * eBay Motors listings are on eBay United States, and eBay.ca in French is
	 * eBay Canada, so a shop with both kinds of listing on one marketplace sees
	 * it named once - and is not told it sells on more than one site when it
	 * does not.
	 *
	 * @param int $days Look-back.
	 * @return string[] e.g. "eBay United Kingdom", sorted.
	 */
	public static function seen_labels( $days = 30 ) {
		$labels = array();

		foreach ( self::seen( $days ) as $site ) {
			$labels[ self::label( $site ) ] = true;
		}

		$labels = array_keys( $labels );
		sort( $labels );

		return $labels;
	}

	/**
	 * Whether a product is the shop's own catalogue entry: one it pushed to
	 * eBay from WooCommerce.
	 *
	 * Listed on whatever site the shop chose at the time, and a change of
	 * Primary eBay Marketplace since does not make it something that came from
	 * elsewhere. So it is never offered for the trash, and never counted as
	 * waiting to be checked: where in doubt, a product is kept.
	 *
	 * Never used to let a listing on another site go back up. One revise that
	 * succeeded writes the same marks as a listing the shop made, so a copy
	 * pushed once from the Listings screen counts as the shop's own here. Push,
	 * Relist and Auto-relist go by the listing's site alone.
	 *
	 * Pushed means a push that succeeded: _ebay_export_last_pushed, which every
	 * one has written since 1.0.2, _ebay_export_pushed_version, or a status of
	 * pushed. A push on its way or one that did not finish counts as well, even
	 * with no push before it: a status of queued, pushing or failed. One that
	 * died once eBay had made the listing left no item number behind, so that
	 * listing is known to this product alone, and the trash would leave it
	 * live on eBay with its product gone - "Remove sold products" keeps such a
	 * product for the same reason. One queued or under way may be about to
	 * make one. None of the three is meant to last: each ends as pushed, as
	 * error, or as failed until Link or a push that finishes.
	 *
	 * A status of error does not count on its own. It is written by a push
	 * eBay refused, or that this plugin refused, so a product of the shop's own
	 * in it still carries the keys from its last good push, while a product
	 * imported from another site's listing carries only the attempt. Counting
	 * it would let one refused press of "Update eBay Listing" on it take it off
	 * Stock Review for good.
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function is_shops_own( $product_id ) {
		$product_id = (int) $product_id;

		foreach ( self::PUSHED_KEYS as $key ) {
			if ( '' !== (string) get_post_meta( $product_id, $key, true ) ) {
				return true;
			}
		}

		return in_array( (string) get_post_meta( $product_id, '_ebay_export_status', true ), self::OWN_STATUSES, true );
	}

	/**
	 * The same test, in SQL: an EXISTS on the caller's product ID column.
	 *
	 * @param string $id_column The product ID column in the caller's query, e.g. p.ID.
	 * @return string SQL, with no leading AND.
	 */
	private static function sql_shops_own( $id_column ) {
		global $wpdb;

		$keys     = "'" . implode( "', '", self::PUSHED_KEYS ) . "'";
		$statuses = "'" . implode( "', '", self::OWN_STATUSES ) . "'";

		// $id_column is the caller's own literal, never input; $keys and
		// $statuses are ours.
		return "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} tcg_own
			   WHERE tcg_own.post_id = {$id_column}
			     AND ( ( tcg_own.meta_key IN ( {$keys} ) AND tcg_own.meta_value != '' )
			        OR ( tcg_own.meta_key = '_ebay_export_status' AND tcg_own.meta_value IN ( {$statuses} ) ) ) )";
	}

	/**
	 * The shared WHERE for products linked to a listing on another site.
	 *
	 * Not the shop's own - see is_shops_own().
	 *
	 * @param string[] $sites Sites that count as the primary marketplace.
	 * @return string SQL, prepared.
	 */
	private static function other_site_where( array $sites ) {
		global $wpdb;

		$in  = implode( ', ', array_fill( 0, count( $sites ), '%s' ) );
		$own = self::sql_shops_own( 'p.ID' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->prepare(
			"WHERE p.post_type = 'product'
			   AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			   AND s.meta_value != ''
			   AND s.meta_value NOT IN ( {$in} )
			   AND NOT {$own}",
			$sites
		);
	}

	/**
	 * Products on this website linked to a listing on another eBay site.
	 *
	 * What Stock Review lists, and so what its "Move them to the trash" acts
	 * on - the list as the page drew it, see remember_shown_list(). Only while
	 * the filter is in force. The site comes from _ebay_site, which every save
	 * writes when eBay says, and which the full sync and the delta also write
	 * for every listing with a product, before any import filter, so a Fetch
	 * Inventory fills it in for every live listing.
	 *
	 * @return int[]
	 */
	public static function find_other_site_products() {
		global $wpdb;

		$sites = self::primary_sites();

		if ( empty( $sites ) ) {
			return array();
		}

		$where = self::other_site_where( $sites );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_ebay_site'
			 INNER JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = '_ebay_item_id' AND i.meta_value != ''
			 {$where}
			 ORDER BY p.ID"
		) );
	}

	/**
	 * How many of those are in a category the shop has set to be preserved.
	 *
	 * The cleanup keeps them (Preserve Categories, Do Not Trash), so Stock
	 * Review's button asks about the rest only. Counted in the database for
	 * the same reason as the count by site below; the term IDs are the ones
	 * has_term() is given, so the two cannot differ.
	 *
	 * @param array $preserve Preserve Categories, as saved.
	 * @return int
	 */
	public static function count_preserved_other_site_products( array $preserve ) {
		global $wpdb;

		$sites = self::primary_sites();
		$terms = array_values( array_filter( array_map( 'intval', $preserve ) ) );

		if ( empty( $sites ) || empty( $terms ) ) {
			return 0;
		}

		$where = self::other_site_where( $sites );
		$in    = implode( ', ', $terms ); // Every one through intval().

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_ebay_site'
			 INNER JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = '_ebay_item_id' AND i.meta_value != ''
			 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			   AND tt.taxonomy = 'product_cat' AND tt.term_id IN ( {$in} )
			 {$where}"
		);
	}

	/**
	 * How many of those there are on each site, by name.
	 *
	 * Counted in the database: a shop with seven copies of five thousand
	 * listings may have tens of thousands of these, and reading each product's
	 * site in turn to count them would be as many queries.
	 *
	 * @return array<string, int> e.g. "eBay United Kingdom" => 30, largest first.
	 */
	public static function count_other_site_products_by_site() {
		global $wpdb;

		$sites = self::primary_sites();

		if ( empty( $sites ) ) {
			return array();
		}

		$where = self::other_site_where( $sites );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			"SELECT s.meta_value AS site, COUNT( DISTINCT p.ID ) AS n FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_ebay_site'
			 INNER JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = '_ebay_item_id' AND i.meta_value != ''
			 {$where}
			 GROUP BY s.meta_value",
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$label            = self::label( (string) ( $row['site'] ?? '' ) );
			$counts[ $label ] = ( $counts[ $label ] ?? 0 ) + (int) ( $row['n'] ?? 0 );
		}

		arsort( $counts );

		return $counts;
	}

	/**
	 * Linked products whose site is not known yet.
	 *
	 * Imported before 3.27.5 and not synced since. Live listings only: an ended
	 * listing is settled, never saved again, so a Fetch Inventory would never
	 * fill one in, and counting them would leave "run Fetch Inventory" on the
	 * screen for ever. A live one is filled in by the next Fetch Inventory
	 * whatever site it is on and whether or not the import filters take it.
	 * The shop's own products are not counted either, since they are never
	 * offered for removal.
	 *
	 * @return int
	 */
	public static function count_unchecked_products() {
		global $wpdb;

		$own = self::sql_shops_own( 'p.ID' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = '_ebay_item_id' AND i.meta_value != ''
			 INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_ebay_listing_status' AND st.meta_value = 'Active'
			 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_ebay_site'
			 WHERE p.post_type = 'product'
			   AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			   AND ( s.meta_id IS NULL OR s.meta_value = '' )
			   AND NOT {$own}"
		);
	}

	/**
	 * Record a product's listing site, when eBay said and it is not already so.
	 *
	 * Never with '': an answer without a site must not wipe one recorded from an
	 * earlier answer, or the product would drop off Stock Review's list and the
	 * cleanup's own check would spare it.
	 *
	 * @param int    $product_id Product.
	 * @param string $site       Item.Site.
	 * @return void
	 */
	public static function record_site( $product_id, $site ) {
		$product_id = (int) $product_id;
		$site       = (string) $site;

		if ( $product_id < 1 || '' === $site || $site === (string) get_post_meta( $product_id, '_ebay_site', true ) ) {
			return;
		}

		update_post_meta( $product_id, '_ebay_site', $site );
	}

	/**
	 * The site eBay gave before for a listing whose delta row has none.
	 *
	 * @param string $item_id eBay ItemID.
	 * @return string '' when nothing is remembered.
	 */
	public static function remembered_site( $item_id ) {
		$item_id = (string) $item_id;

		if ( '' === $item_id ) {
			return '';
		}

		$site = get_transient( self::SITE_TRANSIENT . $item_id );

		return is_string( $site ) ? $site : '';
	}

	/**
	 * Remember the site eBay gave for a listing whose delta row had none.
	 *
	 * @param string $item_id eBay ItemID.
	 * @param string $site    Item.Site from eBay's GetItem answer.
	 * @return void
	 */
	public static function remember_site( $item_id, $site ) {
		$item_id = (string) $item_id;
		$site    = (string) $site;

		if ( '' === $item_id || '' === $site ) {
			return;
		}

		set_transient( self::SITE_TRANSIENT . $item_id, $site, self::SKIP_LOG_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Whether the trash is switched off on this website.
	 *
	 * With EMPTY_TRASH_DAYS set to 0, wp_trash_post() deletes the post for good
	 * instead - WordPress core's first line. The cleanup promises the trash, so
	 * it does nothing at all then.
	 *
	 * @return bool
	 */
	public static function trash_is_off() {
		return defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS;
	}

	/**
	 * Keep the list Stock Review is showing, for its button to act on.
	 *
	 * Drawn again when the button was pressed, the list took in every product
	 * whose site had been recorded since the page loaded - by the Fetch
	 * Inventory the same page asks for, or a delta in the background - and
	 * trashed products the shop had never been shown, under a count that said
	 * fewer. One list per user: a new page load replaces the last, and the
	 * token tells an older page that its list is gone.
	 *
	 * @param int[] $ids The products listed.
	 * @return string The token the page posts back.
	 */
	public static function remember_shown_list( array $ids ) {
		$token = wp_generate_uuid4();

		set_transient( self::LIST_TRANSIENT . get_current_user_id(), array(
			'token' => $token,
			'ids'   => array_values( array_map( 'intval', $ids ) ),
		), DAY_IN_SECONDS );

		return $token;
	}

	/**
	 * The list a Stock Review page showed, once, for the token it posted.
	 *
	 * @param string $token From the page.
	 * @return int[]|null Null when that page's list is no longer held.
	 */
	public static function take_shown_list( $token ) {
		$key   = self::LIST_TRANSIENT . get_current_user_id();
		$held  = get_transient( $key );
		$token = (string) $token;

		if ( '' === $token || ! is_array( $held ) || ! hash_equals( (string) ( $held['token'] ?? '' ), $token ) ) {
			return null;
		}

		// One job per page drawn.
		delete_transient( $key );

		return array_values( array_map( 'intval', (array) ( $held['ids'] ?? array() ) ) );
	}

	/**
	 * Whether a product is, right now, one the cleanup may move to the trash.
	 *
	 * Asked again for each product as the job reaches it, not only when the list
	 * was drawn: the shop may have gone back to all sites, trashed it by hand,
	 * or it may have been found to be on the shop's own site since.
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function is_other_site_product( $product_id ) {
		$product_id = (int) $product_id;

		if ( ! self::primary_only() || 'product' !== get_post_type( $product_id ) ) {
			return false;
		}

		if ( in_array( get_post_status( $product_id ), array( false, 'trash', 'auto-draft' ), true ) ) {
			return false;
		}

		if ( self::is_shops_own( $product_id ) || '' === (string) get_post_meta( $product_id, '_ebay_item_id', true ) ) {
			return false;
		}

		return self::leaves_out( (string) get_post_meta( $product_id, '_ebay_site', true ) );
	}

	/**
	 * Stock Review's "Move them to the trash", for one product.
	 *
	 * The trash, never a permanent delete: it can be put back, and while it is
	 * there a sync knows it was removed on purpose. Nothing at all while the
	 * trash is switched off, since WordPress would delete instead, nor while the
	 * last full sync says the Primary eBay Marketplace is set wrongly. No eBay
	 * call: the listing on the other site carries on as it was. Preserve
	 * Categories (Do Not Trash) are honoured as the pruner honours them.
	 *
	 * @param int $product_id Product.
	 * @return string 'trashed' or 'kept'.
	 */
	public static function trash_other_site_product( $product_id ) {
		$product_id = (int) $product_id;

		if ( ! self::primary_only() ) {
			self::cleanup_stopped( 'all sites', 'Other-site cleanup: stopped - Import listings from is set to All eBay sites, so nothing more is moved to the trash.' );
			return 'kept';
		}

		if ( self::trash_is_off() ) {
			self::cleanup_stopped( 'no trash', 'Other-site cleanup: stopped - the trash is switched off on this website (EMPTY_TRASH_DAYS is 0), so WordPress would delete each product for good instead of moving it to the trash. Nothing was removed.' );
			return 'kept';
		}

		// The last full sync found nothing on the Primary eBay Marketplace, so it
		// is almost certainly set wrongly (after_full_scan()), and "another site"
		// is then the shop's own: an eBay.com shop with eBay United Kingdom saved
		// had its whole catalogue listed, and moved to the trash, where the syncs
		// leave a product once the marketplace is put right. Nothing moves until
		// it is, or a full sync finds listings on it.
		if ( null !== self::mismatch() ) {
			self::cleanup_stopped( 'mismatch', sprintf(
				'Other-site cleanup: stopped - the last full sync found no listings on %s, your Primary eBay Marketplace, so it is almost certainly set wrongly. Nothing more was removed.',
				self::primary_label()
			) );
			return 'kept';
		}

		if ( ! self::is_other_site_product( $product_id ) ) {
			// Already in the trash, the shop's own, or on its own site now.
			return 'kept';
		}

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$preserve = is_array( $settings ) && isset( $settings['preserve_woo_category_ids'] ) && is_array( $settings['preserve_woo_category_ids'] )
			? $settings['preserve_woo_category_ids']
			: array();

		$site    = (string) get_post_meta( $product_id, '_ebay_site', true );
		$item_id = (string) get_post_meta( $product_id, '_ebay_item_id', true );

		if ( ! empty( $preserve ) && has_term( $preserve, 'product_cat', $product_id ) ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Other-site cleanup: WC #%d (%s listing %s) kept - it is in a category set to be preserved (Preserve Categories, Do Not Trash).',
				$product_id,
				self::label( $site ),
				$item_id
			) );
			return 'kept';
		}

		// Trashing goes through wp_update_post(), and its save_post as well.
		TCGiant_Sync_Runtime::guard_loops();

		try {
			$trashed = wp_trash_post( $product_id );
		} finally {
			TCGiant_Sync_Runtime::unguard_loops();
		}

		// Counted, and logged as moved, only once it is in the trash. A
		// pre_trash_post filter can refuse, and then nothing has moved.
		if ( ! $trashed || 'trash' !== get_post_status( $product_id ) ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Other-site cleanup: WC #%d (%s listing %s) kept - WordPress did not move it to the trash.',
				$product_id,
				self::label( $site ),
				$item_id
			) );
			return 'kept';
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Other-site cleanup: WC #%d (%s listing %s) moved to the trash.',
			$product_id,
			self::label( $site ),
			$item_id
		) );

		return 'trashed';
	}

	/**
	 * Say why the cleanup stopped - once a request for each reason, since every
	 * product left in the job would say the same.
	 *
	 * @param string $why     Which reason.
	 * @param string $message The log line.
	 * @return void
	 */
	private static function cleanup_stopped( $why, $message ) {
		if ( empty( self::$stop_said[ $why ] ) ) {
			self::$stop_said[ $why ] = true;
			TCGiant_Sync_Logger::log( $message );
		}
	}

	/**
	 * SQL that keeps products recorded on another eBay site out of a query.
	 *
	 * For auto-relist, which takes fifty ended products a day: products from
	 * other sites would be refused one by one, and could take every place.
	 * The shop's own are left out with the rest, since Relist refuses them by
	 * their site as well (is_shops_own() says why).
	 *
	 * @param string $id_column The product ID column in the caller's query, e.g. l.product_id.
	 * @return string '' when the filter is not in force.
	 */
	public static function sql_keeps_out_other_sites( $id_column ) {
		global $wpdb;

		$sites = self::primary_sites();

		if ( empty( $sites ) ) {
			return '';
		}

		$in = implode( ', ', array_fill( 0, count( $sites ), '%s' ) );

		// $id_column is the caller's own literal, never input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->prepare(
			" AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} tcg_site
			   WHERE tcg_site.post_id = {$id_column} AND tcg_site.meta_key = '_ebay_site'
			     AND tcg_site.meta_value != '' AND tcg_site.meta_value NOT IN ( {$in} ) )",
			$sites
		);
	}

	/**
	 * What a clean full sync found, under "only my Primary eBay Marketplace".
	 *
	 * Nothing at all on the primary marketplace and something on others means
	 * the wrong marketplace is almost certainly saved - and nothing new is being
	 * imported. The whole account has just been read, so this is the one place
	 * that can say so with confidence. A later full sync that finds listings on
	 * the primary site takes the warning down again.
	 *
	 * @param int      $primary_seen Listings on the primary site.
	 * @param int      $other_seen   Listings on other sites.
	 * @param string[] $other_sites  Their site codes.
	 * @return void
	 */
	public static function after_full_scan( $primary_seen, $other_seen, array $other_sites ) {
		if ( ! self::primary_only() || (int) $primary_seen > 0 || (int) $other_seen < 1 ) {
			delete_option( self::MISMATCH_OPTION );
			return;
		}

		$labels = array();
		foreach ( $other_sites as $site ) {
			$labels[ self::label( $site ) ] = true;
		}
		$labels = array_keys( $labels );
		sort( $labels );

		update_option( self::MISMATCH_OPTION, array(
			'marketplace' => self::primary_marketplace(),
			'sites'       => $labels,
			'at'          => time(),
		), false );

		TCGiant_Sync_Logger::warning( sprintf(
			'The full sync found no listings on %s, your Primary eBay Marketplace, so nothing new was imported. Your listings are on %s. Change Primary eBay Marketplace, or set Import listings from to All eBay sites, in Settings.',
			self::primary_label(),
			implode( ', ', $labels )
		) );
	}

	/**
	 * The last clean full sync's warning, while it still applies.
	 *
	 * @return array|null marketplace, sites, at.
	 */
	public static function mismatch() {
		$found = get_option( self::MISMATCH_OPTION, null );

		if ( ! is_array( $found ) || ! self::primary_only() || (string) ( $found['marketplace'] ?? '' ) !== self::primary_marketplace() ) {
			return null;
		}

		return $found;
	}

	/**
	 * Settings are being saved: a changed marketplace or choice of sites answers
	 * the warning above, so it comes down until the next full sync looks again.
	 *
	 * @param mixed $old Settings stored now.
	 * @param array $new Settings about to be stored.
	 * @return void
	 */
	public static function settings_changed( $old, array $new ) {
		$old = is_array( $old ) ? $old : array();

		foreach ( array( 'marketplace', self::SETTING ) as $key ) {
			if ( ( $old[ $key ] ?? '' ) !== ( $new[ $key ] ?? '' ) ) {
				delete_option( self::MISMATCH_OPTION );
				return;
			}
		}
	}

	/**
	 * Whether the shop has already dismissed the notice for these sites.
	 *
	 * A site that appears after the dismissal brings it back: the shop said it
	 * knew about the others, not about this one.
	 *
	 * @param string[] $labels Sites seen, by name.
	 * @return bool
	 */
	public static function notice_dismissed( array $labels ) {
		$dismissed = get_option( self::DISMISSED_OPTION, array() );

		return is_array( $dismissed ) && ! empty( $dismissed ) && array() === array_diff( $labels, $dismissed );
	}

	/**
	 * Dismiss the notice for the sites seen now.
	 *
	 * @return void
	 */
	public static function dismiss_notice() {
		update_option( self::DISMISSED_OPTION, self::seen_labels(), false );
	}
}
