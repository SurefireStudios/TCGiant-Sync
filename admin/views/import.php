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
$license          = TCGiant_Sync_License::instance();
$license_ui       = $license->get_status_for_ui();
$auth_url         = TCGiant_Sync_OAuth::instance()->get_authorization_url();

if ( 'limit_reached' === $sync_state['status'] && $license_ui['can_import'] ) {
	$sync_state['status'] = 'stopped';
	TCGiant_Sync_Importer::update_sync_state( array( 'status' => 'stopped' ) );
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

	<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended ?>
	<?php if ( isset( $_GET['sync_started'] ) && '1' === $_GET['sync_started'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Full catalog sync has been queued in the background.', 'tcgiant-sync' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['queue_processed'] ) && '1' === $_GET['queue_processed'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Queue runner executed manually.', 'tcgiant-sync' ); ?></p></div>
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
				<p class="tc-section-desc"><?php esc_html_e( 'Verify active listings and remove WooCommerce products no longer active on eBay.', 'tcgiant-sync' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tcgiant_sync_now">
					<?php wp_nonce_field( 'tcgiant_sync_now' ); ?>
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
	<?php endif; ?>
</div>
