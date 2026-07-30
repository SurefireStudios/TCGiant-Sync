<?php
/**
 * TCGiant Sync Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes plugin options, transients, and scheduled events.
 *
 * Note: product and order data is deliberately left alone. Deleting the plugin
 * must not destroy a merchant's catalogue. Only the plugin's own bookkeeping is
 * removed here.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/*
 * Delete plugin options.
 *
 * Several names here were previously wrong (tcgiant_sync_settings,
 * tcgiant_sync_sync_state), so uninstalling left every real option behind —
 * including tcgiant_sync_ebay_settings, which holds the eBay OAuth access and
 * refresh tokens and the relay signing key.
 */
$tcgiant_options = array(
	'tcgiant_sync_ebay_settings',      // Settings + OAuth tokens + relay secret.
	'tcgiant_sync_state',              // Import state machine.
	'tcgiant_sync_active_ids',         // Scan bookkeeping.
	'tcgiant_sync_jobs',               // Bulk job progress records.
	'tcgiant_export_state',            // Export state machine.
	'tcgiant_listings_table_version',
	'tcgiant_listing_type_backfilled',
	'tcgiant_postmeta_index_version',
	'tcgiant_sync_license',
	'tcgiant_sync_settings',           // Legacy name, harmless if absent.
	'tcgiant_sync_sync_state',         // Legacy name, harmless if absent.
);

foreach ( $tcgiant_options as $tcgiant_option ) {
	delete_option( $tcgiant_option );
}

// Per-job item lists and cached eBay business policies use dynamic names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'tcgiant_sync_job_items_%'
	    OR option_name LIKE 'tcgiant_export_policies_%'"
);

// Delete all plugin transients (and their timeout rows).
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_tcgiant\_%'
	    OR option_name LIKE '\_transient\_timeout\_tcgiant\_%'"
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Clear scheduled cron events.
$tcgiant_hooks = array(
	'tcgiant_sync_poll_ebay_cron',
	'tcgiant_sync_daily_maintenance',
	'tcgiant_sync_check_ended_listings',
	'tcgiant_sync_reconcile_inventory',
	'tcgiant_sync_import_orders',
	'tcgiant_sync_scan_resume',
	'tcgiant_sync_localize_images',
);

foreach ( $tcgiant_hooks as $tcgiant_hook ) {
	wp_clear_scheduled_hook( $tcgiant_hook );
}

// Clean up any remaining Action Scheduler jobs if the library is available.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	$tcgiant_as_hooks = array(
		'tcgiant_sync_scan_all_pages',
		'tcgiant_sync_fetch_listings',
		'tcgiant_sync_fetch_delta_events',
		'tcgiant_sync_process_item_import',
		'tcgiant_sync_download_images',
		'tcgiant_sync_prune_orphans',
		'tcgiant_sync_update_ebay_stock',
		'tcgiant_sync_push_product',
	);
	foreach ( $tcgiant_as_hooks as $tcgiant_as_hook ) {
		as_unschedule_all_actions( $tcgiant_as_hook );
	}
}

// Remove the custom listings table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tcgiant_listings" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
