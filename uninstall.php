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
	'tcgiant_sync_reconcile_stall',     // The weekly check's stall check, 3.28.0.
	'tcgiant_sync_import_orders',
	'tcgiant_sync_scan_resume',
	'tcgiant_sync_localize_images',
	'tcgiant_sync_weekly_full',         // Added in 3.12.0; was never cleared.
	'tcgiant_sync_weekly_full_retry',   // The weekly full sync tried again, 3.30.1.
	'tcgiant_sync_photo_cleanup',       // Photos of deleted products, 3.27.4.
	'tcgiant_sync_size_trim',           // Old large copies of downloaded photos, 3.27.7.
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
		'tcgiant_sync_license_check',      // The licence's checks with Lemon Squeezy, 3.36.0.
		'tcgiant_sync_limit_replay',       // Listings the free limit held back, imported on a move to Pro, 3.36.0.
		'tcgiant_sync_prune_orphans',
		'tcgiant_sync_update_ebay_stock',  // Stock pushes queued before 3.28.0.
		'tcgiant_sync_push_stock',         // Stock pushes, 3.28.0.
		'tcgiant_sync_settle_item',        // Per-listing stock checks, 3.27.0.
		'tcgiant_sync_gallery_heal',       // The gallery pass, 3.28.0.
		'tcgiant_sync_own_photos',         // The pass over copies of the shop's own photos, 3.30.4.
		'tcgiant_sync_reconcile_continue', // The weekly check's follow-up, 3.28.0.
		'tcgiant_sync_tpl_resend_scan',    // Re-send to eBay's count, 3.34.0.
		'tcgiant_sync_tpl_resend_run',     // Re-send to eBay's Re-send all, 3.34.0.
		'tcgiant_sync_filterable_run',     // Filterable item specifics' conversion, 3.35.0.
		'tcgiant_sync_filterable_count',   // Filterable item specifics' count, 3.35.0.

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
 *
 * It guards the DATA only, deliberately, and sits below the schedule
 * clearing for that reason. The glob matches any folder whose name merely
 * begins with tcgiant-sync, so a kept backup - tcgiant-sync-old,
 * tcgiant-sync-3.12.0 - reads as a sibling. With this above the schedule
 * clearing, one such folder meant a real uninstall cleared nothing at all
 * and left every cron event firing with nothing to answer it.
 */
$tcgiant_siblings = glob( WP_PLUGIN_DIR . '/tcgiant-sync*/tcgiant-sync.php' );

if ( is_array( $tcgiant_siblings ) && count( $tcgiant_siblings ) > 1 ) {
	return;
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
 * Take this shop off the connection service's list.
 *
 * The service sends every eBay account-deletion notice to every site it
 * knows about, and until recently nothing could take one off. A shop that
 * removed the plugin went on receiving a signed request every forty seconds
 * indefinitely; one of them had to write in and ask us to stop.
 *
 * Signed with the key this site was issued when it connected, because the
 * service cannot otherwise tell this apart from a stranger naming somebody
 * else's address - and stopping another shop's deletion notices would be a
 * compliance failure for them.
 *
 * Best effort by design. It runs where the key is still readable, moments
 * before the option holding it is deleted, and nothing about uninstalling
 * may fail or hang because a remote service is unreachable. If it does not
 * get through, the service notices the site has gone quiet and sets it
 * aside on its own.
 */
if ( ! empty( $tcgiant_settings['relay_secret'] ) && function_exists( 'wp_remote_post' ) ) {
	$tcgiant_site_url  = rtrim( get_site_url(), '/' );
	$tcgiant_timestamp = time();

	$tcgiant_body = array(
		'action'    => 'deregister',
		'site_url'  => $tcgiant_site_url,
		'timestamp' => $tcgiant_timestamp,
		'signature' => hash_hmac(
			'sha256',
			$tcgiant_site_url . '|' . $tcgiant_timestamp,
			$tcgiant_settings['relay_secret']
		),
	);

	// The hostname set aside for machine traffic first, then the main site,
	// which is the same pair the plugin uses everywhere else. Short timeouts:
	// an uninstall must not sit waiting on us.
	foreach ( array( 'https://api.tcgiant.com/relay.php', 'https://tcgiant.com/syncconnect/relay.php' ) as $tcgiant_relay ) {
		$tcgiant_reply = wp_remote_post( $tcgiant_relay, array(
			'body'     => $tcgiant_body,
			'timeout'  => 5,
			'blocking' => true,
		) );

		if ( ! is_wp_error( $tcgiant_reply ) && 200 === (int) wp_remote_retrieve_response_code( $tcgiant_reply ) ) {
			break;
		}
	}

	unset( $tcgiant_site_url, $tcgiant_timestamp, $tcgiant_body, $tcgiant_relay, $tcgiant_reply );
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
	'tcgiant_sync_sites_seen',         // eBay sites listings were seen on, 3.27.5.
	'tcgiant_sync_sites_mismatch',     // A full sync found none on the primary site.
	'tcgiant_sync_sites_notice_dismissed',
	'tcgiant_sync_active_ids',         // Scan bookkeeping.
	'tcgiant_sync_delta_progress',     // Delta's place in its window, 3.27.0.
	'tcgiant_sync_delta_window',       // Delta's window kept in brief, 3.27.0.
	'tcgiant_sync_delta_held',         // Listings the delta stepped over, 3.27.0.
	'tcgiant_sync_light_after',        // When the delta last stepped over changes, 3.28.6.
	'tcgiant_sync_oos_control',        // eBay's Out-of-Stock control preference, and when it was asked, 3.28.6.
	'tcgiant_sync_scan_progress',      // The full sync's place in its pages, 3.27.6.
	'tcgiant_sync_scan_page',          // The listings of the page it is on, 3.27.6.
	'tcgiant_sync_prune_pending',      // Listings the clean-up has still to ask eBay about, 3.27.6.
	'tcgiant_sync_last_crash',         // Last run PHP killed, 3.27.0.
	'tcgiant_sync_last_crash_delta_sync',      // The same, per job, 3.27.2.
	'tcgiant_sync_last_crash_full_sync_scan',
	'tcgiant_sync_last_crash_image_localiser',
	'tcgiant_sync_last_crash_inventory_pruning',
	'tcgiant_sync_prune_queue',        // The pruner's list to check, 3.28.0.
	'tcgiant_sync_full_completed',     // Which full sync last completed, 3.30.0.
	'tcgiant_sync_weekly_retry_since', // The weekly full sync's first refusal, 3.30.1.
	'tcgiant_sync_env_verdict',        // Staging check as last recorded, 3.28.0.
	'tcgiant_sync_live_confirmed',     // "This is my live shop", 3.28.0.
	'tcgiant_sync_bound_address',      // The addresses the eBay connection belongs to, 3.31.0.
	'tcgiant_sync_orders_follow',      // Open eBay orders followed by their OrderID, 3.31.0.
	'tcgiant_sync_push_failures',      // Stock pushes eBay never took, 3.28.0.
	'tcgiant_sync_push_switch',        // What the 3.28.0 upgrade did about stock pushes, and its notice.
	'tcgiant_sync_tax_default',        // "eBay Prices Include Tax" never saved: new install or not, and its notice, 3.31.1.
	'tcgiant_sync_gtin_req_forgotten', // 3.30.3's GetCategoryFeatures answers deleted, 3.31.1.
	'tcgiant_sync_allowance_noted',    // The day the log said only eBay's sales reads go, 3.31.1.
	'tcgiant_sync_disconnected_at',    // When the eBay connection was last taken away, 3.28.0.
	'tcgiant_sync_last_crash_stock_push',      // A stock push PHP killed, 3.28.0.
	'tcgiant_auction_import_notice',   // "Import eBay auctions" decided at upgrade, 3.28.0.
	'tcgiant_auction_listed_marked',   // Auctions listed from WooCommerce marked at upgrade, 3.28.0.
	'tcgiant_sync_origin_sweep',       // Who made each listing, judged once at upgrade, 3.28.8's preview.
	'tcgiant_sync_origin_sweep_2',     // Who made each listing, judged once at upgrade, 3.28.8.
	'tcgiant_sync_origin_notice',      // Its notice, dismissed, 3.29.1.
	'tcgiant_sync_motors_sites',       // The site eBay took an eBay Motors revise and relist with, 3.28.9.
	'tcgiant_sync_last_crash_push',            // A push PHP killed, 3.27.4.
	'tcgiant_sync_last_crash_photo_cleanup',   // Photos of deleted products, 3.27.4.
	'tcgiant_sync_last_crash_photo_check',
	'tcgiant_sync_photo_scan',                 // Image Cleanup's check for leftover photos.
	'tcgiant_sync_photo_cleanup_stats',
	'tcgiant_sync_photo_cleanup_pause',
	'tcgiant_sync_photo_cleanup_since',
	'tcgiant_sync_photo_cleanup_deleting',
	'tcgiant_sync_photo_cleanup_reading',
	'tcgiant_sync_photo_check_reading',
	'tcgiant_sync_photo_cleanup_check',
	'tcgiant_sync_size_trim_check',            // Image Cleanup's check for old large copies, 3.27.7.
	'tcgiant_sync_size_trim_run',
	'tcgiant_sync_size_trim_pause',
	'tcgiant_sync_last_crash_large_photo_copies',
	'tcgiant_sync_last_crash_large_photo_copies_check',
	'tcgiant_sync_shop_category_map',  // Which eBay shop category each product category goes in, 3.33.0.
	'tcgiant_sync_template_settings',  // Listing templates' settings, 3.27.8.
	'tcgiant_sync_template_starters',  // Which starter templates were installed.
	'tcgiant_sync_tpl_resend_count',   // Re-send to eBay: the last count, 3.34.0.
	'tcgiant_sync_tpl_resend_state',   // Re-send to eBay: where Re-send all is.
	'tcgiant_sync_tpl_resend_retry',   // Re-send to eBay: refusals to try again.
	'tcgiant_sync_tpl_resend_tests',   // Re-send to eBay: each group's Test on 5.
	'tcgiant_sync_tpl_resend_window',  // Re-send to eBay: re-sends in the last two hours.
	'tcgiant_sync_filterable_choices', // Filterable item specifics: the names counted.
	'tcgiant_sync_filterable_state',   // Filterable item specifics: where its conversion is.
	'tcgiant_sync_bulk_push_cap',      // The daily cap on bulk pushes.
	'tcgiant_sync_bulk_pushed',        // Bulk pushes counted today.
	'tcgiant_sync_bulk_waiting',       // Bulk pushes counted today and still waiting.
	'tcgiant_sync_jobs',               // Bulk job progress records.
	'tcgiant_export_state',            // Export state machine.
	'tcgiant_listings_table_version',
	'tcgiant_listing_type_backfilled',
	'tcgiant_sync_gallery_heal',       // The gallery pass, how far it got and what it did, 3.28.0.
	'tcgiant_sync_gallery_heal_open',  // The gallery pass's batch in hand, 3.28.0.
	'tcgiant_sync_own_photos_heal',      // The pass over copies of the shop's own photos, what it did, 3.30.4.
	'tcgiant_sync_own_photos_heal_open', // Its batch in hand, 3.30.4.
	'tcgiant_sync_settle_chains',      // Stock checks waiting to try again, 3.28.0.
	'tcgiant_sync_call_count_tidied',  // The day the eBay call counts were last tidied, 3.28.0.
	'tcgiant_sync_reconcile_pass',     // Where the weekly check has got to, 3.28.0.
	'tcgiant_postmeta_index_version',
	'tcgiant_sync_license',
	'tcgiant_sync_limit_held',         // New listings the free limit held back, for a move to Pro, 3.36.0.
	'tcgiant_sync_limit_replay',       // Their replay's own record, 3.36.0.
	'tcgiant_sync_settings',           // Legacy name, harmless if absent.
	'tcgiant_sync_sync_state',         // Legacy name, harmless if absent.
);

foreach ( $tcgiant_options as $tcgiant_option ) {
	delete_option( $tcgiant_option );
}

// Per-job item lists, cached eBay business policies, the leftover-photo
// check's findings and its holds, the large-copy removal's holds, the
// day's eBay call counts (3.28.0's, and the transients 3.27 kept them in),
// when each stock check waiting to try again was last joined (3.28.0), the
// listings' categories the delta remembers (3.28.4), the moved addresses
// the administrator was emailed about (3.31.0), Re-send to eBay's
// counted listings, its queue and its holds (3.34.0), and Filterable item
// specifics' holds (3.35.0) use dynamic names. What Filterable item specifics
// converted on each product (_tcgiant_filterable) stays, with the global
// attributes, values and brands it describes: they are the shop's catalogue,
// and a reinstalled plugin reads them by it as before, and can turn them back.
// So does the list of names chosen (tcgiant_sync_filterable): without it a
// reinstalled plugin would show them unticked, and its next Save would turn
// them back (3.35.0 fix round 2). Its counts and its run go, above.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'tcgiant_sync_job_items_%'
	    OR option_name LIKE 'tcgiant_export_policies_%'
	    OR option_name LIKE 'tcgiant_sync_photo_scan_ids_%'
	    OR option_name LIKE 'tcgiant_sync_photo_cleanup_lock_%'
	    OR option_name LIKE 'tcgiant_sync_size_trim_lock_%'
	    OR option_name LIKE 'tcgiant_sync_api_calls_%'
	    OR option_name LIKE 'tcgiant_sync_move_mailed_%'
	    OR option_name LIKE 'tcgiant_sync_settle_joined_%'
	    OR option_name LIKE 'tcgiant_sync_category_memory_%'
	    OR option_name LIKE 'tcgiant_sync_tpl_resend_ids_%'
	    OR option_name LIKE 'tcgiant_sync_tpl_resend_queue_%'
	    OR option_name LIKE 'tcgiant_sync_tpl_resend_lock_%'
	    OR option_name LIKE 'tcgiant_sync_filterable_lock_%'
	    OR option_name LIKE '_transient_tcgiant_api_calls_%'
	    OR option_name LIKE '_transient_timeout_tcgiant_api_calls_%'"
);

// Photos waiting to be removed with the products they belonged to. Taking
// the mark off leaves each photo exactly where it is - the safe direction for
// anything the plugin can no longer finish - except for one WordPress had
// already been asked to delete (_tcgiant_delete_started): part of it may be
// gone, so it keeps a mark that stops the image batch reusing it if the
// plugin is ever installed again, as a photo given up on
// (_tcgiant_delete_failed) does. A run that only counted an attempt, and
// threw before any deletion began, touched nothing.
//
// The change is confirmed before the other marks come off - no such photo
// left unchanged - since until it is in they are what keeps one from being
// reused. If the database refused it (a lock it waited too long for, a full
// disk), or cannot say, every mark stays where it is, and a reinstalled
// plugin judges each photo again before anything is deleted.
$wpdb->query(
	"UPDATE {$wpdb->prefix}postmeta SET meta_key = '_tcgiant_delete_failed'
	 WHERE meta_key = '_tcgiant_delete_started'"
);
$tcgiant_started_left = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE meta_key = '_tcgiant_delete_started'" );
if ( '0' === (string) $tcgiant_started_left ) {
	$wpdb->query(
		"DELETE FROM {$wpdb->prefix}postmeta
		 WHERE meta_key IN ( '_tcgiant_delete_after', '_tcgiant_delete_attempts', '_tcgiant_delete_approved' )"
	);
}

// The note on each photo whose old large copy was being removed (3.27.7).
// Taking it off leaves the photo as it is: one whose record no longer names
// the copy keeps the copy's file on disk, named by nothing - the safe way
// round for anything the plugin can no longer finish.
$wpdb->query(
	"DELETE FROM {$wpdb->prefix}postmeta WHERE meta_key = '_tcgiant_trim_pending'"
);

// The listing's shipping each imported product holds, copied from eBay for a
// shop's own code to read (3.27.6). The import writes these and nothing else
// does; the shop's own choice of policy for pushing, _ebay_export_fulfillment_policy,
// is not among them.
$wpdb->query(
	"DELETE FROM {$wpdb->prefix}postmeta
	 WHERE meta_key IN ( '_ebay_shipping_policy_id', '_ebay_shipping_policy_name', '_ebay_shipping_type', '_ebay_shipping_service', '_ebay_shipping_cost', '_ebay_shipping_currency', '_ebay_shipping_services' )"
);

// The listing's own return and payment policies, recorded beside its shipping
// one (3.27.8), which an imported listing's revise keeps (3.28.8).
$wpdb->query(
	"DELETE FROM {$wpdb->prefix}postmeta
	 WHERE meta_key IN ( '_ebay_return_policy_id', '_ebay_return_policy_name', '_ebay_payment_policy_id', '_ebay_payment_policy_name' )"
);

// eBay's own values for each imported listing (3.28.8, _ebay_live), which
// the import records and its next read writes again, and eBay's price as
// last sent or read, with its mark (3.29.0, _ebay_price_seen and
// _ebay_price_marked) and what a push would have sent then (3.31.1,
// _ebay_price_basis), and the photos the last push sent and those of its
// listing held, not downloaded, or let go (3.30.4, _tcgiant_pushed_photos,
// _tcgiant_photos_held and _tcgiant_photos_released). Who made each listing, _ebay_origin, and who said
// so, _ebay_origin_by, stay with the product's link to it; so do the shop's
// own eBay prices, _ebay_export_price, and the postage in its website
// prices, _ebay_baked_shipping. The eBay numbers a photo was put on eBay as
// (3.30.4, _tcgiant_ebay_number) stay with the photo, as its eBay copy's
// address does (_tcgiant_eps_url).
$wpdb->query(
	"DELETE FROM {$wpdb->prefix}postmeta
	 WHERE meta_key IN ( '_ebay_live', '_ebay_price_seen', '_ebay_price_marked', '_ebay_price_basis', '_tcgiant_pushed_photos', '_tcgiant_photos_held', '_tcgiant_photos_released' )"
);

// Listing templates and their text blocks (3.27.8): private posts of the
// plugin's own types, with their meta - the bodies, versions and design
// settings.
$wpdb->query(
	"DELETE pm FROM {$wpdb->prefix}postmeta pm
	 INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id
	 WHERE p.post_type IN ( 'tcgiant_tpl', 'tcgiant_tpl_block' )"
);
$wpdb->query(
	"DELETE FROM {$wpdb->prefix}posts WHERE post_type IN ( 'tcgiant_tpl', 'tcgiant_tpl_block' )"
);

// What each product holds of them: its own template choice, its eBay-only
// description, the record of the description last sent, and a re-send of
// it eBay refused (3.34.0).
$wpdb->query(
	"DELETE FROM {$wpdb->prefix}postmeta
	 WHERE meta_key IN ( '_tcgiant_tpl_override', '_tcgiant_ebay_description', '_tcgiant_tpl_id', '_tcgiant_tpl_ver', '_tcgiant_tpl_hash_sent', '_tcgiant_content_hash', '_tcgiant_desc_hash', '_tcgiant_region_hash', '_tcgiant_tpl_item', '_tcgiant_desc_sent_at', '_tcgiant_resend_failed' )"
);

// The WP-Lister notice, dismissed per user.
$wpdb->query(
	"DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'tcgiant_wplister_notice_dismissed'"
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
