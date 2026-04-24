<?php
/**
 * Plugin Name: TCGiant Sync
 * Plugin URI:  https://github.com/SurefireStudios/TCGiant-Sync
 * Description: Production-ready eBay to WooCommerce synchronization for TCG products.
 * Version:     1.0.1
 * Author:      TCGiant Team
 * Author URI:  https://surefirestudios.io
 * Text Domain: tcgiant-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define constants.
define( 'TCGIANT_SYNC_VERSION', '1.0.1' );
define( 'TCGIANT_SYNC_FILE', __FILE__ );
define( 'TCGIANT_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCGIANT_SYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'TCGIANT_SYNC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active.
 */
function tcgiant_sync_check_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'tcgiant_sync_missing_wc_notice' );
		return false;
	}
	return true;
}

/**
 * Show notice if WooCommerce is missing.
 */
function tcgiant_sync_missing_wc_notice() {
	?>
	<div class="notice notice-error is-dismissible">
		<p><?php esc_html_e( 'TCGiant Sync requires WooCommerce to be installed and active.', 'tcgiant-sync' ); ?></p>
	</div>
	<?php
}

/**
 * Initialize the plugin.
 */
function tcgiant_sync_init() {
	if ( ! tcgiant_sync_check_woocommerce() ) {
		return;
	}

	// Load core class.
	require_once TCGIANT_SYNC_PATH . 'includes/class-tcgiant-sync.php';

	// Launch plugin.
	TCGiant_Sync::instance();
}

/**
 * Deactivation.
 */
function tcgiant_sync_deactivate() {
	require_once TCGIANT_SYNC_PATH . 'includes/class-tcgiant-sync-cron.php';
	TCGiant_Sync_Cron::deactivate();
}

add_action( 'plugins_loaded', 'tcgiant_sync_init' );
register_deactivation_hook( __FILE__, 'tcgiant_sync_deactivate' );
