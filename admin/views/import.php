<?php
/**
 * Import from eBay Page View
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$admin            = TCGiant_Sync_Admin::instance();
$stats            = $admin->get_sync_stats();
$sync_state       = TCGiant_Sync_Importer::get_sync_state();
$is_authenticated = TCGiant_Sync_OAuth::instance()->is_authenticated();
$settings         = TCGiant_Sync_OAuth::instance()->get_settings();
$license          = TCGiant_Sync_Entitlements::instance();
$license_ui       = $license->get_status_for_ui();
$auth_url         = TCGiant_Sync_OAuth::instance()->get_authorization_url();
$log_entries      = TCGiant_Sync_Logger::get_recent_entries( 20 );

if ( 'limit_reached' === $sync_state['status'] && $license_ui['can_import'] ) {
	$sync_state['status'] = 'stopped';
	TCGiant_Sync_Importer::update_sync_state( array( 'status' => 'stopped' ) );
}

// Auto-clear rate_limited state when auto-retry already handled it.
if ( 'rate_limited' === $sync_state['status'] && function_exists( 'as_get_scheduled_actions' ) ) {
	// Scanning is queued as tcgiant_sync_scan_all_pages; the old per-page
	// tcgiant_sync_fetch_listings hook was retired in 2.0.0, so checking it
	// here never found anything.
	$pending_scans = as_get_scheduled_actions( array(
		'hook'   => 'tcgiant_sync_scan_all_pages',
		'group'  => TCGiant_Sync_Importer::GROUP_SCAN,
		'status' => ActionScheduler_Store::STATUS_PENDING,
		'per_page' => 1,
	) );
	// The scan also resumes through WP-Cron rather than Action Scheduler.
	if ( empty( $pending_scans ) && wp_next_scheduled( 'tcgiant_sync_scan_resume' ) ) {
		$pending_scans = array( 'wp-cron' );
	}
	// Item imports are queued in GROUP_IMPORTS, not the scan group.
	$pending_imports = as_get_scheduled_actions( array(
		'hook'   => 'tcgiant_sync_process_item_import',
		'group'  => TCGiant_Sync_Importer::GROUP_IMPORTS,
		'status' => ActionScheduler_Store::STATUS_PENDING,
		'per_page' => 1,
	) );
	// If auto-retry actions exist, show scanning/importing state instead.
	if ( ! empty( $pending_scans ) ) {
		$sync_state['status'] = 'scanning';
	} elseif ( ! empty( $pending_imports ) ) {
		$sync_state['status'] = 'importing';
	}
}

$progress_pct = 0;
if ( $sync_state['total_queued'] > 0 ) {
	$progress_pct = round( ( ( $sync_state['total_processed'] + $sync_state['total_errors'] ) / $sync_state['total_queued'] ) * 100 );
}
?>

<div class="wrap tc-dashboard-wrap">
	<div class="tc-header">
		<h1><?php esc_html_e( 'Import from eBay', 'tcgiant-sync' ); ?></h1>
		<p class="tc-subtitle"><?php esc_html_e( 'Fetch your eBay listings and import them into WooCommerce. Monitor live progress and manage your import queue.', 'tcgiant-sync' ); ?></p>
	</div>

	<?php TCGiant_Sync_Admin::instance()->render_tabs( 'import' ); ?>

	<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended ?>
	<?php if ( isset( $_GET['sync_started'] ) && '1' === $_GET['sync_started'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Full catalog sync has been queued in the background.', 'tcgiant-sync' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['sync_resumed'] ) && '1' === $_GET['sync_resumed'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sync resumed from where it left off. Check progress below.', 'tcgiant-sync' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['sync_failed'] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php
			echo esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['sync_failed'] ) ) ) );
		?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['prune_started'] ) && '1' === $_GET['prune_started'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Scanning eBay now. Products no longer listed will be moved to the Trash once the scan finishes.', 'tcgiant-sync' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['queue_processed'] ) && '1' === $_GET['queue_processed'] ) :
		$tc_as   = isset( $_GET['queue_as'] ) ? (int) $_GET['queue_as'] : 0;
		$tc_cron = isset( $_GET['queue_cron'] ) ? (int) $_GET['queue_cron'] : 0;
		if ( $tc_as || $tc_cron ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php
				printf(
					/* translators: 1: number of background jobs, 2: number of scheduled tasks */
					esc_html__( 'Ran %1$d background job(s) and %2$d scheduled task(s). Check the Activity Log for results.', 'tcgiant-sync' ),
					$tc_as,
					$tc_cron
				);
			?></p></div>
		<?php else : ?>
			<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Nothing was waiting to run — the queue is already empty.', 'tcgiant-sync' ); ?></p></div>
		<?php endif; ?>
	<?php endif; ?>
	<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

	<?php if ( ! $is_authenticated ) : ?>
		<div class="tc-card" style="text-align:center;padding:40px 20px;">
			<span class="dashicons dashicons-lock" style="font-size:48px;color:var(--tc-warning);width:48px;height:48px;margin-bottom:16px;display:block;margin-left:auto;margin-right:auto;"></span>
			<h2 style="margin-bottom:8px;"><?php esc_html_e( 'eBay Not Connected', 'tcgiant-sync' ); ?></h2>
			<p style="color:#666;margin-bottom:20px;"><?php esc_html_e( 'Connect your eBay account first to start importing listings.', 'tcgiant-sync' ); ?></p>
			<a href="<?php echo esc_url( $auth_url ); ?>" class="tc-button success"><?php esc_html_e( 'Connect to eBay', 'tcgiant-sync' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-settings' ) ); ?>" class="tc-button secondary" style="margin-left:8px;"><?php esc_html_e( 'Go to Settings', 'tcgiant-sync' ); ?></a>
		</div>
	<?php else : ?>

	<?php
	// Build contextual settings summary for quick reference.
	$cat_filter_raw  = $settings['category_ids'] ?? '';
	$sync_interval   = $settings['sync_interval'] ?? 'disabled';
	$interval_labels = array(
		'disabled'        => __( 'Manual Only', 'tcgiant-sync' ),
		'tcgiant_15mins'  => __( 'Every 15 min', 'tcgiant-sync' ),
		'tcgiant_hourly'  => __( 'Hourly', 'tcgiant-sync' ),
		'twicedaily'      => __( 'Twice Daily', 'tcgiant-sync' ),
		'daily'           => __( 'Daily', 'tcgiant-sync' ),
	);
	$interval_label = $interval_labels[ $sync_interval ] ?? $sync_interval;

	$overwrite_flags = array();
	if ( ! empty( $settings['overwrite_price'] ) && '1' === $settings['overwrite_price'] )   $overwrite_flags[] = __( 'Price', 'tcgiant-sync' );
	if ( ! empty( $settings['overwrite_title'] ) && '1' === $settings['overwrite_title'] )   $overwrite_flags[] = __( 'Title', 'tcgiant-sync' );
	if ( ! empty( $settings['overwrite_desc'] )  && '1' === $settings['overwrite_desc'] )    $overwrite_flags[] = __( 'Desc', 'tcgiant-sync' );
	if ( ! empty( $settings['overwrite_images'] ) && '1' === $settings['overwrite_images'] ) $overwrite_flags[] = __( 'Images', 'tcgiant-sync' );
	$overwrite_summary = ! empty( $overwrite_flags ) ? implode( ', ', $overwrite_flags ) : __( 'Stock only', 'tcgiant-sync' );
	?>
	<div class="tc-context-bar" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:var(--tc-card-bg, #fff);border:1px solid var(--tc-border, #e0e0e0);border-radius:6px;padding:10px 16px;margin-bottom:20px;font-size:12.5px;line-height:1.5;">
		<span class="dashicons dashicons-admin-settings" style="color:#888;font-size:16px;width:16px;height:16px;flex-shrink:0;"></span>
		<span style="color:#666;">
			<?php esc_html_e( 'Filter:', 'tcgiant-sync' ); ?>
			<strong style="color:var(--tc-text, #1d2327);"><?php echo $cat_filter_raw ? esc_html( $cat_filter_raw ) : esc_html__( 'All Categories', 'tcgiant-sync' ); ?></strong>
		</span>
		<span style="color:var(--tc-border, #ccc);">·</span>
		<span style="color:#666;">
			<?php esc_html_e( 'Auto-Sync:', 'tcgiant-sync' ); ?>
			<strong style="color:var(--tc-text, #1d2327);"><?php echo esc_html( $interval_label ); ?></strong>
		</span>
		<span style="color:var(--tc-border, #ccc);">·</span>
		<span style="color:#666;">
			<?php esc_html_e( 'Overwrites:', 'tcgiant-sync' ); ?>
			<strong style="color:var(--tc-text, #1d2327);"><?php echo esc_html( $overwrite_summary ); ?></strong>
		</span>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-settings' ) ); ?>" style="margin-left:auto;color:var(--tc-primary, #3858e9);text-decoration:none;font-weight:600;white-space:nowrap;font-size:12px;display:flex;align-items:center;gap:3px;">
			<span class="dashicons dashicons-edit" style="font-size:13px;width:13px;height:13px;"></span>
			<?php esc_html_e( 'Edit Settings', 'tcgiant-sync' ); ?>
		</a>
	</div>


	<div class="tc-row-2col">

		<!-- ─── SYNC STATUS ─── -->
		<div class="tc-card">
			<h2><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Status', 'tcgiant-sync' ); ?></h2>

			<!-- Status Indicator -->
			<div class="tc-sync-indicator">
				<div class="tc-sync-dot <?php echo esc_attr( $sync_state['status'] ); ?>"></div>
				<div>
					<div class="tc-sync-status" id="tc-hero-status">
						<?php
						$status_labels = array(
							'scanning'      => 'Scanning eBay…',
							'importing'     => 'Importing…',
							'complete'      => 'Complete',
							'stopped'       => 'Stopped',
							'error'         => 'Error',
							'rate_limited'  => 'Rate Limited — Paused',
							'limit_reached' => 'Import Limit Reached',
						);
						echo esc_html( $status_labels[ $sync_state['status'] ] ?? 'Idle' );
						?>
					</div>
					<div class="tc-sync-detail" id="tc-hero-detail">
						<?php
						if ( 'scanning' === $sync_state['status'] ) {
							echo 'Page ' . esc_html( $sync_state['current_page'] ) . ( $sync_state['total_pages'] ? '/' . esc_html( $sync_state['total_pages'] ) : '' );
						} elseif ( 'importing' === $sync_state['status'] ) {
							echo esc_html( $sync_state['total_processed'] ) . '/' . esc_html( $sync_state['total_queued'] ) . ' items';
						} elseif ( 'complete' === $sync_state['status'] ) {
							echo esc_html( $sync_state['total_processed'] ) . ' imported, ' . esc_html( $sync_state['total_errors'] ) . ' errors';
						} elseif ( 'rate_limited' === $sync_state['status'] ) {
							printf(
								'Page %d%s — %d imported so far. Auto-retry scheduled.',
								absint( $sync_state['current_page'] ),
								$sync_state['total_pages'] ? '/' . absint( $sync_state['total_pages'] ) : '',
								absint( $sync_state['total_processed'] )
							);
						} elseif ( 'limit_reached' === $sync_state['status'] ) {
							printf( esc_html__( '%1$d/%2$d products — Upgrade to Pro for unlimited', 'tcgiant-sync' ), absint( $license_ui['active_count'] ), absint( $license_ui['free_limit'] ) );
						} elseif ( ! empty( $sync_state['last_completed'] ) ) {
							echo 'Last: ' . esc_html( $sync_state['last_completed'] );
						} else {
							echo 'No sync has run yet.';
						}
						?>
					</div>
				</div>
			</div>

			<?php if ( 'limit_reached' === $sync_state['status'] ) : ?>
				<div class="tc-limit-reached-card">
					<p><?php esc_html_e( "You've reached the free tier limit of 50 active products. Upgrade for unlimited imports.", 'tcgiant-sync' ); ?></p>
					<a href="<?php echo esc_url( $license_ui['upgrade_url'] ); ?>" target="_blank" rel="noopener" class="tc-button tc-upgrade-btn full-width">
						<span class="dashicons dashicons-superhero-alt" style="font-size:16px;"></span>
						<?php esc_html_e( 'Upgrade to Pro — $49/year', 'tcgiant-sync' ); ?>
					</a>
				</div>
			<?php endif; ?>

			<?php if ( 'rate_limited' === $sync_state['status'] ) : ?>
			<?php
				$api_for_display = TCGiant_Sync_API::instance();
				$daily_calls = $api_for_display->get_daily_call_count();
				$retries = absint( $sync_state['rate_limit_retries'] ?? 0 );
				// Mirror the backoff logic: 15min base, doubling, capped at 2hr.
				$base_delay_min = 15;
				$backoff_min = min( $base_delay_min * pow( 2, min( max( $retries - 1, 0 ), 4 ) ), 120 );
			?>
				<div class="tc-limit-reached-card" style="border-left:4px solid var(--tc-warning);background:#fff8e1;">
					<p style="margin:0 0 8px;"><strong><?php esc_html_e( '⏸ eBay API Rate Limit Reached', 'tcgiant-sync' ); ?></strong></p>
					<p style="margin:0 0 12px;font-size:13px;color:#555;"><?php
						printf(
							esc_html__( 'The import paused at page %1$d (of %2$d) after importing %3$d products. eBay limits how many API calls can be made per day (%4$d calls used today). An automatic retry has been scheduled in ~%5$d minutes, or you can resume manually below.', 'tcgiant-sync' ),
							absint( $sync_state['current_page'] ),
							absint( $sync_state['total_pages'] ?: '?' ),
							absint( $sync_state['total_processed'] ),
							$daily_calls,
							$backoff_min
						);
					?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="tcgiant_resume_sync">
						<?php wp_nonce_field( 'tcgiant_resume_sync' ); ?>
						<button type="submit" class="tc-button full-width" style="background:var(--tc-warning);border-color:var(--tc-warning);">
							<span class="dashicons dashicons-controls-play" style="font-size:16px;"></span>
							<?php esc_html_e( 'Resume Import Now', 'tcgiant-sync' ); ?>
						</button>
					</form>
				</div>
			<?php endif; ?>

			<!-- Progress Bar -->
			<div id="tc-progress" class="tc-progress-wrap" style="display:<?php echo 'importing' === $sync_state['status'] ? 'block' : 'none'; ?>;">
				<div class="tc-progress-bar">
					<div class="tc-progress-fill" style="width:<?php echo esc_attr( $progress_pct ); ?>%;"></div>
				</div>
				<div class="tc-progress-text"><?php echo esc_html( $progress_pct ); ?>%</div>
			</div>

			<!-- Stats -->
			<div class="tc-sync-stats">
				<div class="tc-mini-stat">
					<span class="tc-mini-val" id="tc-stat-synced"><?php echo esc_html( $stats['synced_products'] ); ?></span>
					<span class="tc-mini-label">Products</span>
				</div>
				<div class="tc-mini-stat">
					<span class="tc-mini-val" id="tc-stat-queued"><?php echo esc_html( $sync_state['total_queued'] ?: '0' ); ?></span>
					<span class="tc-mini-label">Queued</span>
				</div>
				<div class="tc-mini-stat">
					<span class="tc-mini-val" id="tc-stat-pending"><?php echo esc_html( $stats['pending_jobs'] ); ?></span>
					<span class="tc-mini-label">Pending Jobs</span>
				</div>
			</div>

			<?php if ( ! empty( $sync_state['last_item_title'] ) && in_array( $sync_state['status'], array( 'importing', 'complete' ), true ) ) : ?>
				<div class="tc-last-item">
					<span class="tc-mini-label">Latest:</span>
					<span id="tc-last-item-title"><?php echo esc_html( $sync_state['last_item_title'] ); ?></span>
				</div>
			<?php endif; ?>

			<!-- Usage Meter -->
			<div class="tc-section tc-usage-section" style="margin-top:20px;">
				<h3 class="tc-section-title"><?php esc_html_e( 'Import Usage', 'tcgiant-sync' ); ?></h3>
				<?php if ( $license_ui['is_pro'] ) : ?>
					<div class="tc-usage-pro">
						<span class="dashicons dashicons-yes-alt" style="color:var(--tc-success);"></span>
						<span><strong><?php echo esc_html( $license_ui['active_count'] ); ?></strong> <?php esc_html_e( 'active products — Unlimited', 'tcgiant-sync' ); ?></span>
					</div>
				<?php else : ?>
					<div class="tc-usage-bar-wrap">
						<div class="tc-usage-counts">
							<span><strong id="tc-usage-count"><?php echo esc_html( $license_ui['active_count'] ); ?></strong> / <?php echo esc_html( $license_ui['free_limit'] ); ?> <?php esc_html_e( 'products', 'tcgiant-sync' ); ?></span>
							<span class="tc-usage-remaining" id="tc-usage-remaining"><?php echo esc_html( $license_ui['remaining'] ); ?> <?php esc_html_e( 'remaining', 'tcgiant-sync' ); ?></span>
						</div>
						<div class="tc-usage-bar">
							<div class="tc-usage-fill <?php echo $license_ui['usage_pct'] >= 90 ? 'tc-usage-critical' : ( $license_ui['usage_pct'] >= 70 ? 'tc-usage-warning' : '' ); ?>" id="tc-usage-fill" style="width:<?php echo esc_attr( $license_ui['usage_pct'] ); ?>%;"></div>
						</div>
					</div>
					<?php if ( $license_ui['usage_pct'] >= 80 ) : ?>
						<a href="<?php echo esc_url( $license_ui['upgrade_url'] ); ?>" target="_blank" rel="noopener" class="tc-button tc-upgrade-btn full-width" style="margin-top:10px;">
							<span class="dashicons dashicons-superhero-alt" style="font-size:16px;"></span>
							<?php esc_html_e( 'Upgrade to Pro', 'tcgiant-sync' ); ?>
						</a>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<!-- ─── OPERATIONS ─── -->
		<div class="tc-card">
			<h2><span class="dashicons dashicons-performance"></span> <?php esc_html_e( 'Operations', 'tcgiant-sync' ); ?></h2>

			<div class="tc-section" style="padding-top:0;border-top:none;margin-top:0;">
				<h3 class="tc-section-title"><?php esc_html_e( 'Fetch Inventory', 'tcgiant-sync' ); ?></h3>
				<p class="tc-section-desc"><?php esc_html_e( 'Scan your eBay store and queue matching items for import into WooCommerce.', 'tcgiant-sync' ); ?></p>
				<?php if ( ! $license_ui['can_import'] ) : ?>
					<div class="tc-limit-reached-inline">
						<span class="dashicons dashicons-lock" style="color:var(--tc-warning);"></span>
						<span><?php esc_html_e( 'Import limit reached. Upgrade to continue.', 'tcgiant-sync' ); ?></span>
					</div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tcgiant_sync_now">
					<?php wp_nonce_field( 'tcgiant_sync_now' ); ?>
					<button type="submit" class="tc-button full-width" id="tc-fetch-btn" <?php echo ! $license_ui['can_import'] ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>
						<span class="dashicons dashicons-download" style="font-size:16px;"></span>
						<?php esc_html_e( 'Fetch Inventory', 'tcgiant-sync' ); ?>
					</button>
				</form>
			</div>

			<div class="tc-section">
				<h3 class="tc-section-title"><?php esc_html_e( 'Clean Sold Items', 'tcgiant-sync' ); ?></h3>
				<p class="tc-section-desc"><?php esc_html_e( 'Re-scans your eBay store, then moves WooCommerce products that are no longer listed to the Trash. Because it has to check every listing first, this takes as long as a full sync.', 'tcgiant-sync' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'This re-scans your entire eBay store, then moves products that are no longer listed to the Trash.

Products can be restored from the Trash afterwards. Continue?', 'tcgiant-sync' ) ); ?>');">
					<input type="hidden" name="action" value="tcgiant_prune_now">
					<?php wp_nonce_field( 'tcgiant_prune_now' ); ?>
					<button type="submit" class="tc-button secondary full-width">
						<span class="dashicons dashicons-trash" style="font-size:16px;"></span>
						<?php esc_html_e( 'Prune Inventory', 'tcgiant-sync' ); ?>
					</button>
				</form>
			</div>

			<div class="tc-section">
				<h3 class="tc-section-title"><?php esc_html_e( 'Process Queue', 'tcgiant-sync' ); ?></h3>
				<p class="tc-section-desc"><?php esc_html_e( 'Force-run pending background jobs immediately instead of waiting for WordPress cron.', 'tcgiant-sync' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tcgiant_force_queue">
					<?php wp_nonce_field( 'tcgiant_force_queue' ); ?>
					<button type="submit" class="tc-button secondary full-width">
						<span class="dashicons dashicons-controls-play" style="font-size:16px;"></span>
						<?php esc_html_e( 'Process Queue', 'tcgiant-sync' ); ?>
					</button>
				</form>
			</div>

				<div class="tc-section">
				<h3 class="tc-section-title"><?php esc_html_e( 'Sync Specific Items', 'tcgiant-sync' ); ?></h3>
				<p class="tc-section-desc"><?php esc_html_e( 'Enter a comma-separated list of eBay Item IDs to sync them immediately. Optionally re-download images only.', 'tcgiant-sync' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tcgiant_sync_specific">
					<?php wp_nonce_field( 'tcgiant_sync_specific' ); ?>
					<input type="text" name="tcgiant_item_ids" placeholder="e.g. 123456789012, 987654321098" style="width:100%; margin-bottom:10px;">
					<label style="display:flex;align-items:center;gap:6px;margin-bottom:12px;font-size:13px;cursor:pointer;">
						<input type="checkbox" name="tcgiant_images_only" value="1">
						<?php esc_html_e( 'Re-sync images only (skip product data)', 'tcgiant-sync' ); ?>
					</label>
					<button type="submit" class="tc-button secondary full-width">
						<span class="dashicons dashicons-download" style="font-size:16px;"></span>
						<?php esc_html_e( 'Sync specific items', 'tcgiant-sync' ); ?>
					</button>
				</form>
			</div>

			<div class="tc-section">
				<h3 class="tc-section-title tc-danger-text"><?php esc_html_e( 'Emergency Stop', 'tcgiant-sync' ); ?></h3>
				<p class="tc-section-desc"><?php esc_html_e( 'Immediately cancel all pending and scheduled sync jobs. This cannot be undone.', 'tcgiant-sync' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Stop all sync jobs?');">
					<input type="hidden" name="action" value="tcgiant_stop_sync">
					<?php wp_nonce_field( 'tcgiant_stop_sync' ); ?>
					<button type="submit" class="tc-button danger full-width">
						<span class="dashicons dashicons-dismiss" style="font-size:16px;"></span>
						<?php esc_html_e( 'STOP SYNC', 'tcgiant-sync' ); ?>
					</button>
				</form>
			</div>
		</div>
	</div>

	<!-- Activity Log -->
	<div class="tc-card" style="margin-top:20px;">
		<div class="tc-log-header">
			<h2 style="margin-bottom:0;"><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Activity Log', 'tcgiant-sync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
				<input type="hidden" name="action" value="tcgiant_clear_log">
				<?php wp_nonce_field( 'tcgiant_clear_log' ); ?>
				<button type="submit" class="tc-button secondary" style="font-size:11px;padding:4px 10px;"><?php esc_html_e( 'Clear', 'tcgiant-sync' ); ?></button>
			</form>
		</div>
		<div class="tc-log-viewer" id="tc-log-content">
			<?php if ( empty( $log_entries ) ) : ?>
				<div class="tc-premium-empty-state" style="margin-top:20px;border-color:rgba(255,255,255,0.1);">
					<span class="dashicons dashicons-welcome-write-blog" style="color:#64748b;"></span>
					<p style="color:#64748b;"><?php esc_html_e( 'No activity recorded yet.', 'tcgiant-sync' ); ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $log_entries as $entry ) :
					$level_class = '';
					$icon = '[Log]';
					switch ( $entry['level'] ) {
						case 'error':   $level_class = 'tc-is-error';   $icon = '[X]';  break;
						case 'success': $level_class = 'tc-is-success'; $icon = '[OK]'; break;
						case 'warning': $level_class = 'tc-is-warning'; $icon = '[!]';  break;
					}
				?>
				<div class="tc-log-entry <?php echo esc_attr( $level_class ); ?>">
					<span class="tc-log-icon"><?php echo esc_html( $icon ); ?></span>
					<span class="tc-log-time"><?php echo esc_html( $entry['timestamp'] ); ?></span>
					<span class="tc-log-msg"><?php echo esc_html( $entry['message'] ); ?></span>
				</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>

	<?php endif; ?>
</div>
