<?php
/**
 * Plugin Name: TCGiant Sync
 * Plugin URI:  https://github.com/SurefireStudios/TCGiant-Sync
 * Description: Sync your eBay store to WooCommerce — import listings, images, and inventory with automated stock updates.
 * Version:     3.8.9
 * Author:      TCGiant Team
 * Author URI:  https://surefirestudios.io
 * Text Domain: tcgiant-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 10.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/*
 * Refuse to load a second copy of this plugin.
 *
 * Two copies in wp-content/plugins — the usual folder plus something like
 * "tcgiant-sync-2" left behind by an interrupted manual install — both declare
 * the same functions, and PHP stops dead with "Cannot redeclare". That takes
 * the whole site down, including wp-admin, so the only way back is to delete a
 * folder over FTP. Reinstalling then reproduces it, because the other copy is
 * still sitting there.
 *
 * Note that every function below is wrapped in function_exists() as well. PHP
 * binds unconditional top-level functions when a file is compiled rather than
 * when it runs, so returning early here would be too late to prevent the
 * clash — the guard only works if nothing is declared unconditionally.
 */
if ( defined( 'TCGIANT_SYNC_VERSION' ) ) {

	if ( ! function_exists( 'tcgiant_sync_duplicate_copy_notice' ) ) {
		/**
		 * Explain why the second copy is doing nothing.
		 */
		function tcgiant_sync_duplicate_copy_notice() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>';
			esc_html_e( 'TCGiant Sync is installed twice.', 'tcgiant-sync' );
			echo '</strong> ';
			esc_html_e( 'Only the first copy is running. Delete the spare folder from wp-content/plugins — an interrupted install usually leaves one behind with a name like "tcgiant-sync-2".', 'tcgiant-sync' );
			echo '</p></div>';
		}
		add_action( 'admin_notices', 'tcgiant_sync_duplicate_copy_notice' );
	}

	return;
}

// Define constants.
define('TCGIANT_SYNC_VERSION', '3.8.9');
define('TCGIANT_SYNC_FILE', __FILE__);
define('TCGIANT_SYNC_PATH', plugin_dir_path(__FILE__));
define('TCGIANT_SYNC_URL', plugin_dir_url(__FILE__));
define('TCGIANT_SYNC_BASENAME', plugin_basename(__FILE__));

// Staging environment detection.
// Prevents sync/push operations from running on staging or dev copies.
if ( ! defined( 'TCGIANT_SYNC_IS_STAGING' ) ) {
	$tcgiant_env  = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
	$tcgiant_host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( $_SERVER['HTTP_HOST'] ) : '';
	$is_staging   = ! in_array( $tcgiant_env, array( 'production', 'live' ), true );

	// Common staging URL patterns.
	if ( ! $is_staging ) {
		$staging_patterns = array( '.staging.', '.test.', '.dev.', '.local', 'staging-', 'dev-', 'localhost' );
		foreach ( $staging_patterns as $pattern ) {
			if ( strpos( $tcgiant_host, $pattern ) !== false ) {
				$is_staging = true;
				break;
			}
		}
	}

	define( 'TCGIANT_SYNC_IS_STAGING', $is_staging );
	unset( $tcgiant_env, $tcgiant_host, $is_staging, $staging_patterns );
}

/**
 * Declare HPOS compatibility.
 */
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

/**
 * Check if WooCommerce is active.
 */
if ( ! function_exists( 'tcgiant_sync_check_woocommerce' ) ) {
	function tcgiant_sync_check_woocommerce()
	{
		if (!class_exists('WooCommerce')) {
			add_action('admin_notices', 'tcgiant_sync_missing_wc_notice');
			return false;
		}
		return true;
	}
}

/**
 * Show notice if WooCommerce is missing.
 */
if ( ! function_exists( 'tcgiant_sync_missing_wc_notice' ) ) {
	function tcgiant_sync_missing_wc_notice()
	{
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e('TCGiant Sync requires WooCommerce to be installed and active.', 'tcgiant-sync'); ?></p>
		</div>
		<?php
	}
}

/**
 * Initialize the plugin.
 */
if ( ! function_exists( 'tcgiant_sync_init' ) ) {
	function tcgiant_sync_init()
	{
		if (!tcgiant_sync_check_woocommerce()) {
			return;
		}

		// Load Plugin Update Checker
		require_once TCGIANT_SYNC_PATH . 'includes/plugin-update-checker/plugin-update-checker.php';
		$tcgiant_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/SurefireStudios/TCGiant-Sync/',
			TCGIANT_SYNC_FILE,
			'tcgiant-sync'
		);
		$tcgiant_update_checker->setBranch('main');

		// Load core class.
		require_once TCGIANT_SYNC_PATH . 'includes/class-tcgiant-sync.php';

		// Launch plugin.
		TCGiant_Sync::instance();
	}
}

/**
 * Deactivation.
 */
if ( ! function_exists( 'tcgiant_sync_deactivate' ) ) {
	function tcgiant_sync_deactivate()
	{
		require_once TCGIANT_SYNC_PATH . 'includes/class-tcgiant-sync-cron.php';
		TCGiant_Sync_Cron::deactivate();
	}
}

add_action('plugins_loaded', 'tcgiant_sync_init');
register_deactivation_hook(__FILE__, 'tcgiant_sync_deactivate');
