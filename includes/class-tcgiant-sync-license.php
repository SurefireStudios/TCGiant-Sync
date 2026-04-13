<?php
/**
 * License Manager
 *
 * Handles freemium licensing via LemonSqueezy.
 * Free tier: 50 active synced products.
 * Pro tier:  Unlimited (valid license key required).
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * TCGiant_Sync_License class
 */
class TCGiant_Sync_License
{

	/**
	 * Instance.
	 */
	private static $_instance = null;

	/**
	 * Free tier product limit.
	 */
	const FREE_LIMIT = 50;

	/**
	 * Option key for license data.
	 */
	const LICENSE_OPTION = 'tcgiant_sync_license';

	/**
	 * Transient key for cached validation.
	 */
	const VALIDATION_TRANSIENT = 'tcgiant_sync_license_valid';

	/**
	 * LemonSqueezy API base.
	 */
	const LS_API_BASE = 'https://api.lemonsqueezy.com/v1';

	/**
	 * Upgrade URL.
	 */
	const UPGRADE_URL = 'https://tcgiant.com/pro';

	/**
	 * Main instance.
	 */
	public static function instance()
	{
		if (is_null(self::$_instance)) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		// Periodic re-validation on admin_init (at most once per 24 hours via transient).
		if (is_admin()) {
			add_action('admin_init', array($this, 'maybe_revalidate'));
		}
	}

	/**
	 * Get stored license data.
	 *
	 * @return array License data.
	 */
	public function get_license_data()
	{
		return get_option(self::LICENSE_OPTION, array(
			'key' => '',
			'status' => '', // active, expired, invalid, ''
			'instance_id' => '',
			'customer_name' => '',
			'customer_email' => '',
			'variant' => '', // annual, lifetime
			'expires_at' => '',
			'activated_at' => '',
		));
	}

	/**
	 * Update license data.
	 *
	 * @param array $updates Key-value pairs.
	 */
	private function update_license_data($updates)
	{
		$data = $this->get_license_data();
		$data = array_merge($data, $updates);
		update_option(self::LICENSE_OPTION, $data);
	}

	/**
	 * Check if user has a valid Pro license.
	 *
	 * @return bool True if Pro is active.
	 */
	public function is_pro()
	{
		$data = $this->get_license_data();

		if (empty($data['key']) || empty($data['status'])) {
			return false;
		}

		// Check if license is active.
		if ('active' !== $data['status']) {
			return false;
		}

		// For annual licenses, check expiry.
		if ('lifetime' !== $data['variant'] && !empty($data['expires_at'])) {
			if (strtotime($data['expires_at']) < time()) {
				return false;
			}
		}

		return true;
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
	 * Get the current import limit.
	 *
	 * @return int Maximum allowed synced products.
	 */
	public function get_limit()
	{
		return $this->is_pro() ? PHP_INT_MAX : self::FREE_LIMIT;
	}

	/**
	 * Get remaining import slots.
	 *
	 * @return int Number of products that can still be imported. Returns PHP_INT_MAX for Pro.
	 */
	public function get_remaining_slots()
	{
		if ($this->is_pro()) {
			return PHP_INT_MAX;
		}

		$remaining = self::FREE_LIMIT - $this->get_active_product_count();
		return max(0, $remaining);
	}

	/**
	 * Check if the user can import more products.
	 *
	 * @return bool True if import is allowed.
	 */
	public function can_import()
	{
		if ($this->is_pro()) {
			return true;
		}

		return $this->get_active_product_count() < self::FREE_LIMIT;
	}

	/**
	 * Get upgrade URL.
	 *
	 * @return string URL to the upgrade page.
	 */
	public function get_upgrade_url()
	{
		return self::UPGRADE_URL;
	}

	/**
	 * Activate a license key via LemonSqueezy API.
	 *
	 * @param string $key License key to activate.
	 * @return array|WP_Error Result array with 'success' and 'message', or WP_Error.
	 */
	public function activate_license($key)
	{
		$key = sanitize_text_field(trim($key));

		if (empty($key)) {
			return new WP_Error('empty_key', __('Please enter a license key.', 'tcgiant-sync'));
		}

		// Call LemonSqueezy license activation endpoint.
		$response = wp_remote_post(self::LS_API_BASE . '/licenses/activate', array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			),
			'body' => wp_json_encode(array(
				'license_key' => $key,
				'instance_name' => $this->get_instance_name(),
			)),
		));

		if (is_wp_error($response)) {
			TCGiant_Sync_Logger::error('License activation failed: ' . $response->get_error_message());
			return new WP_Error('api_error', __('Could not reach the license server. Please try again.', 'tcgiant-sync'));
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		// Handle API errors.
		if ($code !== 200 || empty($body['activated'])) {
			$error_msg = $body['error'] ?? __('Invalid license key.', 'tcgiant-sync');
			TCGiant_Sync_Logger::error('License activation rejected: ' . $error_msg);
			return new WP_Error('invalid_key', $error_msg);
		}

		// Extract data from LemonSqueezy response.
		$meta = $body['meta'] ?? array();
		$instance = $body['instance'] ?? array();

		// Determine variant type.
		$variant = 'annual';
		$variant_name = strtolower($meta['variant_name'] ?? '');
		if (strpos($variant_name, 'lifetime') !== false || strpos($variant_name, 'founder') !== false) {
			$variant = 'lifetime';
		}

		// Save license data.
		$this->update_license_data(array(
			'key' => $key,
			'status' => 'active',
			'instance_id' => $instance['id'] ?? '',
			'customer_name' => $meta['customer_name'] ?? '',
			'customer_email' => $meta['customer_email'] ?? '',
			'variant' => $variant,
			'expires_at' => $meta['expires_at'] ?? '',
			'activated_at' => current_time('mysql'),
		));

		// Cache validation.
		set_transient(self::VALIDATION_TRANSIENT, 'valid', DAY_IN_SECONDS);

		TCGiant_Sync_Logger::log('License activated successfully! Plan: ' . ucfirst($variant), 'success');

		return array(
			'success' => true,
			'message' => __('License activated! You now have unlimited imports.', 'tcgiant-sync'),
			'variant' => $variant,
		);
	}

	/**
	 * Deactivate the current license.
	 *
	 * @return array|WP_Error Result.
	 */
	public function deactivate_license()
	{
		$data = $this->get_license_data();

		if (empty($data['key'])) {
			return new WP_Error('no_license', __('No license key to deactivate.', 'tcgiant-sync'));
		}

		// Call LemonSqueezy deactivation endpoint.
		$response = wp_remote_post(self::LS_API_BASE . '/licenses/deactivate', array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			),
			'body' => wp_json_encode(array(
				'license_key' => $data['key'],
				'instance_id' => $data['instance_id'],
			)),
		));

		// Clear license regardless of API response (graceful local cleanup).
		$this->update_license_data(array(
			'key' => '',
			'status' => '',
			'instance_id' => '',
			'customer_name' => '',
			'customer_email' => '',
			'variant' => '',
			'expires_at' => '',
			'activated_at' => '',
		));

		delete_transient(self::VALIDATION_TRANSIENT);

		TCGiant_Sync_Logger::log('License deactivated.', 'warning');

		return array(
			'success' => true,
			'message' => __('License deactivated. You are now on the free tier (50 products).', 'tcgiant-sync'),
		);
	}

	/**
	 * Revalidate license key periodically (once per 24 hours).
	 * Uses transient caching for performance.
	 */
	public function maybe_revalidate()
	{
		$data = $this->get_license_data();

		if (empty($data['key'])) {
			return;
		}

		// Skip if recently validated.
		if (false !== get_transient(self::VALIDATION_TRANSIENT)) {
			return;
		}

		$this->validate_license();
	}

	/**
	 * Validate the current license against LemonSqueezy.
	 *
	 * @return bool True if valid.
	 */
	public function validate_license()
	{
		$data = $this->get_license_data();

		if (empty($data['key'])) {
			return false;
		}

		$response = wp_remote_post(self::LS_API_BASE . '/licenses/validate', array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			),
			'body' => wp_json_encode(array(
				'license_key' => $data['key'],
				'instance_id' => $data['instance_id'],
			)),
		));

		// Graceful degradation: if API is unreachable, assume valid using cached state.
		if (is_wp_error($response)) {
			set_transient(self::VALIDATION_TRANSIENT, 'cached', DAY_IN_SECONDS);
			return 'active' === $data['status'];
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (!empty($body['valid'])) {
			$this->update_license_data(array('status' => 'active'));
			set_transient(self::VALIDATION_TRANSIENT, 'valid', DAY_IN_SECONDS);
			return true;
		}

		// License is no longer valid (expired, refunded, etc.).
		$this->update_license_data(array('status' => 'expired'));
		delete_transient(self::VALIDATION_TRANSIENT);

		TCGiant_Sync_Logger::log('License validation failed. License may have expired or been deactivated.', 'warning');
		return false;
	}

	/**
	 * Get a unique instance name for this WordPress installation.
	 *
	 * @return string Instance identifier.
	 */
	private function get_instance_name()
	{
		$site_url = get_site_url();
		// Use domain + path as instance name.
		$parsed = wp_parse_url($site_url);
		return sanitize_title(($parsed['host'] ?? 'unknown') . ($parsed['path'] ?? ''));
	}

	/**
	 * Get license status summary for the admin UI.
	 *
	 * @return array Status data for rendering in the dashboard.
	 */
	public function get_status_for_ui()
	{
		$data = $this->get_license_data();
		$active_count = $this->get_active_product_count();
		$is_pro = $this->is_pro();
		$limit = $is_pro ? 'unlimited' : self::FREE_LIMIT;
		$can_import = $this->can_import();

		$usage_pct = 0;
		if (!$is_pro && self::FREE_LIMIT > 0) {
			$usage_pct = min(100, round(($active_count / self::FREE_LIMIT) * 100));
		}

		return array(
			'is_pro' => $is_pro,
			'plan' => $is_pro ? 'pro' : 'free',
			'variant' => $data['variant'] ?? '',
			'active_count' => $active_count,
			'limit' => $limit,
			'remaining' => $is_pro ? 'unlimited' : max(0, self::FREE_LIMIT - $active_count),
			'can_import' => $can_import,
			'usage_pct' => $usage_pct,
			'key_masked' => $this->mask_key($data['key'] ?? ''),
			'customer_name' => $data['customer_name'] ?? '',
			'expires_at' => $data['expires_at'] ?? '',
			'has_key' => !empty($data['key']),
			'status' => $data['status'] ?? '',
			'upgrade_url' => self::UPGRADE_URL,
			'free_limit' => self::FREE_LIMIT,
		);
	}

	/**
	 * Mask a license key for display (show first 4 + last 4).
	 *
	 * @param string $key Full license key.
	 * @return string Masked key.
	 */
	private function mask_key($key)
	{
		if (strlen($key) <= 8) {
			return $key;
		}
		return substr($key, 0, 4) . str_repeat('-', strlen($key) - 8) . substr($key, -4);
	}
}
