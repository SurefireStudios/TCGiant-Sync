<?php
/**
 * What this installation is allowed to do, asked without naming a licence.
 *
 * The importer, the dashboard and the settings screens all need two answers:
 * may another product be imported, and how many are linked already. In the
 * Pro edition those answers come from the licence. The Lite and Standard
 * editions have no licence of ours at all - WordPress.org forbids one, and
 * WooCommerce.com handles billing itself - so they must not carry the licence
 * class, and nothing outside it may depend on it by name.
 *
 * So: the licence EXTENDS this. instance() hands back the real licence when
 * its class exists, which in Pro it always does, and Pro's path is therefore
 * unchanged by construction. When the class is absent - which the autoloader
 * reports without a fatal - this base class answers for itself, and its
 * answer is "unlimited".
 *
 * The product counters live here because they are statistics, not licensing,
 * and every edition's dashboard shows them.
 *
 * @package TCGiant_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entitlements, and the counts they are judged against.
 */
class TCGiant_Sync_Entitlements {

	/**
	 * The instance in use - the licence where there is one, else this.
	 *
	 * @var TCGiant_Sync_Entitlements|null
	 */
	private static $_shared = null;

	/**
	 * The licence if this build has one, otherwise an unlimited stand-in.
	 *
	 * class_exists() goes through the autoloader, whose file_exists() guard
	 * means a missing licence file is simply false rather than a fatal. That
	 * guard is the whole mechanism.
	 *
	 * @return TCGiant_Sync_Entitlements
	 */
	public static function instance() {
		if ( null === self::$_shared ) {
			self::$_shared = class_exists( 'TCGiant_Sync_License' )
				? TCGiant_Sync_License::instance()
				: new self();
		}
		return self::$_shared;
	}

	/**
	 * Whether another product may be imported. Without a licence, always.
	 *
	 * @return bool
	 */
	public function can_import() {
		return true;
	}

	/**
	 * Whether this installation is unrestricted. Without a licence, it is:
	 * there is nothing to restrict it with.
	 *
	 * @return bool
	 */
	public function is_pro() {
		return true;
	}

	/**
	 * The free-tier cap. Zero means there is none.
	 *
	 * @return int
	 */
	public function get_free_limit() {
		return 0;
	}

	/**
	 * The same shape the licence returns, so every screen reads it the same
	 * way: unrestricted, no key, nothing to upgrade to.
	 *
	 * @return array<string,mixed>
	 */
	public function get_status_for_ui() {
		return array(
			'is_pro'        => true,
			'plan'          => 'unlimited',
			'variant'       => '',
			'active_count'  => $this->get_active_product_count(),
			'limit'         => 'unlimited',
			'remaining'     => 'unlimited',
			'can_import'    => true,
			'usage_pct'     => 0,
			'key_masked'    => '',
			'customer_name' => '',
			'expires_at'    => '',
			'has_key'       => false,
			'status'        => '',
			'upgrade_url'   => '',
			'free_limit'    => 0,
		);
	}

	/**
	 * Count active synced products (products with _ebay_item_id meta).
	 *
	 * @return int Number of active synced products.
	 */
	public function get_active_product_count()
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = 'product'
			 AND p.post_status IN ('publish','draft')
			 AND pm.meta_key = '_ebay_item_id'
			 AND pm.meta_value != ''"
		);
	}

	/**
	 * Count products pushed from Woo to eBay.
	 *
	 * @return int Number of pushed products.
	 */
	public function get_pushed_product_count()
	{
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID
			 INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID
			 WHERE p.post_type = 'product'
			 AND p.post_status IN ('publish','draft')
			 AND pm1.meta_key = '_ebay_item_id' AND pm1.meta_value != ''
			 AND pm2.meta_key = '_ebay_export_status' AND pm2.meta_value = 'pushed'"
		);
	}

	/**
	 * Count products pulled from eBay to Woo.
	 *
	 * @return int Number of pulled products.
	 */
	public function get_pulled_product_count()
	{
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID
			 LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_ebay_export_status'
			 WHERE p.post_type = 'product'
			 AND p.post_status IN ('publish','draft')
			 AND pm1.meta_key = '_ebay_item_id' AND pm1.meta_value != ''
			 AND (pm2.meta_value IS NULL OR pm2.meta_value != 'pushed')"
		);
	}
}
