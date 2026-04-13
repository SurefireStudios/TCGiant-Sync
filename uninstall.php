<?php
/**
 * TCGiant Sync Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all plugin options, transients, and scheduled events.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options.
delete_option( 'tcgiant_sync_settings' );
delete_option( 'tcgiant_sync_license' );
delete_option( 'tcgiant_sync_sync_state' );

// Delete transients.
delete_transient( 'tcgiant_sync_license_valid' );
delete_transient( 'tcgiant_sync_store_categories' );

// Clear any scheduled cron events.
$tcgiant_sync_timestamp = wp_next_scheduled( 'tcgiant_sync_cron_hook' );
if ( $tcgiant_sync_timestamp ) {
	wp_unschedule_event( $tcgiant_sync_timestamp, 'tcgiant_sync_cron_hook' );
}

// Clean up any remaining Action Scheduler jobs if the library is available.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'tcgiant_sync_import_batch' );
	as_unschedule_all_actions( 'tcgiant_sync_inventory_update' );
}
