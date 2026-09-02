<?php
/**
 * TCGiant Sync Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 *
 * Two things are removed on every uninstall, because leaving them behind is a
 * fault in itself: scheduled events that would go on firing with nothing
 * listening, and queued background jobs that would never be picked up.
 *
 * Everything else — settings, the eBay connection, the listings table, and the
 * links between products and eBay items — is kept unless the shop has asked for
 * it to go. Deleting a plugin is how people reinstall it, move to a different
 * edition, or try a newer version, and a merchant who does any of those should
 * not come back to a store that has forgotten which of its products came from
 * where. Anyone who genuinely wants a clean slate can say so in Settings.
 *
 * Note: product and order data is never touched here under any setting.
 * Deleting the plugin must not destroy a merchant's catalogue.
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
 * Do nothing at all if another edition is still installed.
 *
 * Lite, Standard and Pro share the same options, the same meta keys, the same
 * table and the same scheduled events — that shared storage is what lets a shop
 * move between them without reconnecting to eBay or re-linking a single
 * product. It also means this file, run for one of them, would strip the
 * ground out from under another that is still running.
 *
 * The likely way to hit this is the obvious way to upgrade: install the new
 * edition, then delete the old one. So the check is not a nicety.
 */
$tcgiant_siblings = glob( WP_PLUGIN_DIR . '/tcgiant-sync*/tcgiant-sync.php' );

if ( is_array( $tcgiant_siblings ) && count( $tcgiant_siblings ) > 1 ) {
	return;
}

/*
 * Scheduled events always go.
 *
 * These are cleared whether or not the shop asked for its data to be removed,
 * because an event with no listener is not data — it is a job WordPress will
 * keep waking up to run forever, finding nothing, and rescheduling.
 *
 * The list must name every hook any edition has ever scheduled. A shop that ran
 * Standard and moved to Lite still has Standard's recurring events sitting in
 * its cron array, and only a complete list clears them.
 */
$tcgiant_hooks = array(
	'tcgiant_sync_poll_ebay_cron',
	'tcgiant_sync_daily_maintenance',
	'tcgiant_sync_check_ended_listings',
	'tcgiant_sync_reconcile_inventory',
	'tcgiant_sync_import_orders',
	'tcgiant_sync_scan_resume',
	'tcgiant_sync_localize_images',
	'tcgiant_sync_weekly_full',         // Added in 3.12.0; was never cleared.
);

foreach ( $tcgiant_hooks as $tcgiant_hook ) {
	wp_clear_scheduled_hook( $tcgiant_hook );
}

// Clean up any remaining Action Scheduler jobs if the library is available.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	$tcgiant_as_hooks = array(
		'tcgiant_sync_scan_all_pages',
		'tcgiant_sync_fetch_delta_events',
		'tcgiant_sync_process_item_import',
		'tcgiant_sync_prune_orphans',
		'tcgiant_sync_update_ebay_stock',

		// The exporter's queue. This was listed as tcgiant_sync_push_product,
		// which is not a hook this plugin has ever registered, so queued pushes
		// survived every uninstall.
		'tcgiant_export_push_product',

		// Retired, but a long-running store may still hold some.
		'tcgiant_sync_fetch_listings',
		'tcgiant_sync_download_images',
	);
	foreach ( $tcgiant_as_hooks as $tcgiant_as_hook ) {
		as_unschedule_all_actions( $tcgiant_as_hook );
	}
}

/*
 * Everything below is the clean slate, and only happens if it was asked for.
 *
 * Read before the options are deleted, for obvious reasons.
 */
$tcgiant_settings = get_option( 'tcgiant_sync_ebay_settings', array() );

if ( empty( $tcgiant_settings['delete_data_on_uninstall'] ) ) {
	return;
}

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

// Remove the custom listings table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tcgiant_listings" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
