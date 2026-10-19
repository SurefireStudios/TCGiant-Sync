<?php
/**
 * Keeps a product's link to its eBay listing honest.
 *
 * A WooCommerce product remembers which eBay listing it came from, or went to,
 * in _ebay_item_id. Duplicating a product copies that along with everything
 * else, so the copy appeared to already be listed - pointing at the ORIGINAL's
 * live listing. Pressing Update on the copy then overwrote the real listing,
 * and End Listing ended it.
 *
 * This lived in the exporter, but it is not export logic: the importer's own
 * dedup resolves products by _ebay_item_id and would be confused by a copy
 * carrying one just the same. Every edition needs it, including the one that
 * ships no exporter at all.
 *
 * @package TCGiant_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listing identity: what must not be copied, and how to sever a link.
 */
class TCGiant_Sync_Listing_Link {

	/**
	 * Single instance.
	 *
	 * @var TCGiant_Sync_Listing_Link|null
	 */
	private static $_instance = null;

	/**
	 * @return TCGiant_Sync_Listing_Link
	 */
	public static function instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		// Strip the eBay link when a product is duplicated. WooCommerce copies
		// all post meta, so a duplicate inherited _ebay_item_id and appeared to
		// already be listed — while actually pointing at the ORIGINAL's live
		// listing. Pressing Update on the copy then overwrote the real listing,
		// and End Listing ended it.
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( __CLASS__, 'duplicate_exclude_meta' ) );
		add_action( 'woocommerce_product_duplicate', array( __CLASS__, 'on_product_duplicated' ), 10, 2 );
		// Yoast Duplicate Post and similar tools bypass WooCommerce's own
		// duplicator, so cover their hooks too.
		add_action( 'dp_duplicate_post', array( __CLASS__, 'on_third_party_duplicate' ), 10, 2 );
		add_action( 'dp_duplicate_page', array( __CLASS__, 'on_third_party_duplicate' ), 10, 2 );
	}

	/**
	 * Meta keys that identify a specific eBay listing and must never be copied.
	 *
	 * Per-product export *settings* (category, condition, grader, policies) are
	 * deliberately NOT in this list — carrying those over is the whole point of
	 * duplicating a product to build a similar one.
	 *
	 * @return string[]
	 */
	public static function listing_identity_meta_keys() {
		return array(
			// Identity of the eBay listing this product is bound to.
			'_ebay_item_id',
			'_ebay_sku',
			'_ebay_listing_status',
			// The eBay site that listing is on (3.27.5). A copy, or a product
			// unlinked from its listing, has no listing and so no site, and must
			// not be listed on Stock Review among other sites' products.
			'_ebay_site',
			'_ebay_listing_type',
			'_ebay_listing_duration',
			'_ebay_end_time',
			'_sync_last_updated',
			// Push state belonging to the original.
			'_ebay_export_status',
			'_ebay_export_error',
			'_ebay_export_last_pushed',
			'_ebay_export_pushing_since',
			// History belonging to the original.
			'_tcgiant_sync_log',
			'_tcgiant_sync_pushed',
		);
	}

	/**
	 * Tell WooCommerce not to copy eBay linkage meta when duplicating.
	 *
	 * @param array $exclude Meta keys WooCommerce will skip.
	 * @return array
	 */
	public static function duplicate_exclude_meta( $exclude ) {
		$exclude = is_array( $exclude ) ? $exclude : array();
		return array_merge( $exclude, self::listing_identity_meta_keys() );
	}

	/**
	 * Belt-and-braces cleanup after WooCommerce duplicates a product.
	 *
	 * The exclude-meta filter handles current WooCommerce, but this also clears
	 * per-order bookkeeping (which uses dynamic key names the filter cannot
	 * express) and covers older versions where the filter is absent.
	 *
	 * @param WC_Product $duplicate The new product.
	 * @param WC_Product $original  The product it was copied from.
	 * @return void
	 */
	public static function on_product_duplicated( $duplicate, $original = null ) {
		if ( ! $duplicate instanceof WC_Product ) {
			return;
		}
		self::unlink_product_from_ebay( $duplicate->get_id(), 'duplicated' );
	}

	/**
	 * Cleanup for third-party duplication plugins.
	 *
	 * @param int          $new_post_id The new post ID.
	 * @param WP_Post|null $post        The original post.
	 * @return void
	 */
	public static function on_third_party_duplicate( $new_post_id, $post = null ) {
		if ( 'product' !== get_post_type( $new_post_id ) ) {
			return;
		}
		self::unlink_product_from_ebay( (int) $new_post_id, 'duplicated' );
	}

	/**
	 * Remove every trace of an eBay listing binding from a product.
	 *
	 * Does not touch eBay itself — this only severs the local link so the
	 * product is treated as "not yet listed".
	 *
	 * @param int    $product_id Product ID.
	 * @param string $reason     Short reason, for the log.
	 * @return void
	 */
	public static function unlink_product_from_ebay( $product_id, $reason = 'unlinked' ) {
		global $wpdb;

		$product_id = (int) $product_id;
		if ( ! $product_id ) {
			return;
		}

		foreach ( self::listing_identity_meta_keys() as $key ) {
			delete_post_meta( $product_id, $key );
		}

		// Per-line-item order bookkeeping uses dynamic key names.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
			$product_id,
			$wpdb->esc_like( '_ebay_order_processed_' ) . '%'
		) );

		// Drop the row in the listings table so the Listings page does not show
		// the copy as a live listing.
		if ( class_exists( 'TCGiant_Sync_DB' ) && TCGiant_Sync_DB::table_exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( TCGiant_Sync_DB::table_name(), array( 'product_id' => $product_id ), array( '%d' ) );
		}

		// The "shared listing" admin notice is cached; drop it so the warning
		// disappears as soon as the conflict is resolved.
		delete_transient( 'tcgiant_shared_item_ids' );

		TCGiant_Sync_Logger::log( sprintf(
			'Product #%d %s — eBay listing link removed. It is now treated as not yet listed.',
			$product_id,
			$reason
		) );
	}

	/**
	 * Find other products bound to the same eBay Item ID.
	 *
	 * More than one product sharing an Item ID means a duplicate was made
	 * before the duplication fix landed. Acting on either one would hit the
	 * same live eBay listing.
	 *
	 * @param int    $product_id   The product being acted on.
	 * @param string $ebay_item_id The eBay Item ID.
	 * @return int[] Other product IDs sharing the Item ID.
	 */
	public static function find_products_sharing_item_id( $product_id, $ebay_item_id ) {
		global $wpdb;

		if ( empty( $ebay_item_id ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_ebay_item_id'
			   AND pm.meta_value = %s
			   AND pm.post_id != %d
			   AND p.post_type = 'product'
			   AND p.post_status != 'trash'",
			$ebay_item_id,
			(int) $product_id
		) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * A product whose push did not finish is now linked to a listing: that
	 * push is complete.
	 *
	 * Linking is what such a push asks the shop to do, and the sync does the
	 * same by itself when it finds the listing that push made, by the SKU the
	 * push sent. Once the product is linked, what it said about that push is
	 * over - and the listing is the one that push made, so the product is the
	 * shop's own, pushed from here. Left saying the push did not finish, it
	 * told the shop to use a Link a linked product is not offered, Clear was
	 * refused, and Auto-Relist, Relist and the bulk pushes left it out for
	 * good. Left blank, it read as imported, and "Remove sold products"
	 * trashed it when that listing sold out.
	 *
	 * A product whose first push died has no last-pushed date - only a push
	 * that finishes writes one - and that date is what keeps it through the
	 * pushes after this: without it, one whose listing sold out while a
	 * re-push was queued, or after eBay refused one, was trashed all the same.
	 * So it is dated now.
	 *
	 * On the product, for its own save to write.
	 *
	 * @param WC_Product $product The product, before it is saved.
	 * @return bool Whether it said its push was under way or did not finish.
	 */
	public static function complete_unfinished_push( $product ) {
		if ( ! in_array( (string) $product->get_meta( '_ebay_export_status' ), array( 'failed', 'pushing' ), true ) ) {
			return false;
		}

		$product->update_meta_data( '_ebay_export_status', 'pushed' );
		$product->update_meta_data( '_ebay_export_error', '' );
		if ( '' === (string) $product->get_meta( '_ebay_export_last_pushed' ) ) {
			$product->update_meta_data( '_ebay_export_last_pushed', current_time( 'mysql' ) );
		}
		$product->delete_meta_data( '_ebay_export_pushing_since' );

		return true;
	}

	/**
	 * complete_unfinished_push(), straight to the table.
	 *
	 * For a link written straight to the table ahead of the product's save,
	 * as the sync's re-link over an ended listing is. Completed only by that
	 * save, the push stayed unfinished when the save died: the product was
	 * linked to the listing its push made and still said the push did not
	 * finish, and the next sync found it by that item number rather than by
	 * its SKU, so nothing completed it.
	 *
	 * @param int $product_id The product.
	 * @return bool Whether it said its push was under way or did not finish.
	 */
	public static function complete_unfinished_push_now( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! in_array( (string) get_post_meta( $product_id, '_ebay_export_status', true ), array( 'failed', 'pushing' ), true ) ) {
			return false;
		}

		update_post_meta( $product_id, '_ebay_export_status', 'pushed' );
		update_post_meta( $product_id, '_ebay_export_error', '' );
		if ( '' === (string) get_post_meta( $product_id, '_ebay_export_last_pushed', true ) ) {
			update_post_meta( $product_id, '_ebay_export_last_pushed', current_time( 'mysql' ) );
		}
		delete_post_meta( $product_id, '_ebay_export_pushing_since' );

		return true;
	}
}
