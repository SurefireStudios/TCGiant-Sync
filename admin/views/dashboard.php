<?php
/**
 * Dashboard Page View
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$admin            = TCGiant_Sync_Admin::instance();
$health           = $admin->get_system_health();
$stats            = $admin->get_sync_stats();
$sync_state       = TCGiant_Sync_Importer::get_sync_state();
$is_authenticated = TCGiant_Sync_OAuth::instance()->is_authenticated();
$settings         = TCGiant_Sync_OAuth::instance()->get_settings();
$log_entries      = TCGiant_Sync_Logger::get_recent_entries( 20 );
$license          = TCGiant_Sync_License::instance();
$license_ui       = $license->get_status_for_ui();
$export_state     = TCGiant_Sync_Exporter::get_export_state();

if ( empty( $settings['redirect_uri'] ) ) {
	$settings['redirect_uri'] = admin_url( 'admin.php?page=tcgiant-sync' );
	update_option( 'tcgiant_sync_ebay_settings', $settings );
}
?>

<div class="wrap tc-dashboard-wrap">
	<div class="tc-header">
		<h1>
			<?php esc_html_e( 'TCGiant Sync', 'tcgiant-sync' ); ?>
			<?php if ( $license_ui['is_pro'] ) : ?>
				<span class="tc-pro-badge">PRO</span>
			<?php else : ?>
				<span class="tc-free-badge">FREE</span>
			<?php endif; ?>
		</h1>
		<p class="tc-subtitle">
			<?php esc_html_e( 'Bidirectional eBay ↔ WooCommerce sync for TCG products. Use the menu to import listings, push products, or adjust settings.', 'tcgiant-sync' ); ?>
		</p>
	</div>

	<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended ?>
	<?php if ( isset( $_GET['log_cleared'] ) && '1' === $_GET['log_cleared'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sync log cleared successfully.', 'tcgiant-sync' ); ?></p></div>
	<?php endif; ?>
	<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

	<!-- ─── QUICK STATS BAR ─── -->
	<div class="tc-top-bar" style="margin-bottom:20px;">

		<!-- Import Status Card -->
		<div class="tc-top-card">
			<div class="tc-top-card-left">
				<span class="dashicons dashicons-download tc-top-card-icon"></span>
				<div class="tc-top-card-text">
					<h3><?php esc_html_e( 'Import Status', 'tcgiant-sync' ); ?></h3>
					<p>
						<strong><?php echo esc_html( $stats['synced_products'] ); ?></strong> <?php esc_html_e( 'synced products', 'tcgiant-sync' ); ?>
						&nbsp;·&nbsp;
						<span class="tc-sync-dot <?php echo esc_attr( $sync_state['status'] ); ?>" style="display:inline-block;width:8px;height:8px;border-radius:50%;vertical-align:middle;"></span>
						<?php
						$import_labels = array(
							'scanning'      => 'Scanning',
							'importing'     => 'Importing',
							'complete'      => 'Complete',
							'stopped'       => 'Idle',
							'error'         => 'Error',
							'limit_reached' => 'Limit Reached',
						);
						echo esc_html( $import_labels[ $sync_state['status'] ] ?? 'Idle' );
						?>
					</p>
				</div>
			</div>
			<div class="tc-top-card-right">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-import' ) ); ?>" class="tc-button secondary"><?php esc_html_e( 'Import →', 'tcgiant-sync' ); ?></a>
			</div>
		</div>

		<!-- Export Status Card -->
		<div class="tc-top-card">
			<div class="tc-top-card-left">
				<span class="dashicons dashicons-upload tc-top-card-icon"></span>
				<div class="tc-top-card-text">
					<h3><?php esc_html_e( 'Export Status', 'tcgiant-sync' ); ?></h3>
					<p>
						<strong><?php echo esc_html( $export_state['total_pushed'] ?? '0' ); ?></strong> <?php esc_html_e( 'pushed to eBay', 'tcgiant-sync' ); ?>
						<?php if ( ! empty( $export_state['last_completed'] ) ) : ?>
							&nbsp;·&nbsp; <?php echo esc_html( 'Last: ' . $export_state['last_completed'] ); ?>
						<?php endif; ?>
					</p>
				</div>
			</div>
			<div class="tc-top-card-right">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-export' ) ); ?>" class="tc-button secondary"><?php esc_html_e( 'Push →', 'tcgiant-sync' ); ?></a>
			</div>
		</div>
	</div>

	<!-- ─── SYSTEM HEALTH + LOG ─── -->
	<div class="tc-row-2col">

		<!-- System Health -->
		<div class="tc-card">
			<h2><span class="dashicons dashicons-heart"></span> <?php esc_html_e( 'System Health', 'tcgiant-sync' ); ?></h2>
			<div class="health-list">
				<?php foreach ( $health as $metric ) : ?>
					<div class="health-item">
						<span class="health-label"><?php echo esc_html( $metric['label'] ); ?></span>
						<span class="status-badge <?php echo esc_attr( $metric['status'] ); ?>"><?php echo esc_html( $metric['text'] ); ?></span>
					</div>
				<?php endforeach; ?>
				<div class="health-item">
					<span class="health-label"><?php esc_html_e( 'eBay Connection', 'tcgiant-sync' ); ?></span>
					<span class="status-badge <?php echo $is_authenticated ? 'active' : 'error'; ?>">
						<?php echo $is_authenticated ? esc_html( $settings['store_name'] ?? 'Connected' ) : esc_html__( 'Not Connected', 'tcgiant-sync' ); ?>
					</span>
				</div>
				<div class="health-item">
					<span class="health-label"><?php esc_html_e( 'License', 'tcgiant-sync' ); ?></span>
					<span class="status-badge <?php echo $license_ui['is_pro'] ? 'active' : 'warning'; ?>">
						<?php echo $license_ui['is_pro'] ? esc_html__( 'Pro Active', 'tcgiant-sync' ) : esc_html__( 'Free Tier', 'tcgiant-sync' ); ?>
					</span>
				</div>
				<div class="health-item">
					<span class="health-label"><?php esc_html_e( 'Export Policies', 'tcgiant-sync' ); ?></span>
					<?php
					$policies_ok = ! empty( $settings['export_fulfillment_policy'] )
					            && ! empty( $settings['export_return_policy'] )
					            && ! empty( $settings['export_payment_policy'] );
					?>
					<span class="status-badge <?php echo $policies_ok ? 'active' : 'warning'; ?>">
						<?php echo $policies_ok ? esc_html__( 'Configured', 'tcgiant-sync' ) : esc_html__( 'Not Set', 'tcgiant-sync' ); ?>
					</span>
				</div>
			</div>

			<!-- Quick Links -->
			<div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--tc-border);display:flex;flex-wrap:wrap;gap:8px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-import' ) ); ?>" class="tc-button secondary" style="flex:1;text-align:center;font-size:12px;">
					<span class="dashicons dashicons-download" style="font-size:14px;"></span> <?php esc_html_e( 'Import', 'tcgiant-sync' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-export' ) ); ?>" class="tc-button secondary" style="flex:1;text-align:center;font-size:12px;">
					<span class="dashicons dashicons-upload" style="font-size:14px;"></span> <?php esc_html_e( 'Push to eBay', 'tcgiant-sync' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-settings' ) ); ?>" class="tc-button secondary" style="flex:1;text-align:center;font-size:12px;">
					<span class="dashicons dashicons-admin-settings" style="font-size:14px;"></span> <?php esc_html_e( 'Settings', 'tcgiant-sync' ); ?>
				</a>
			</div>
		</div>

		<!-- Activity Log -->
		<div class="tc-card">
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
					<div class="tc-log-entry tc-log-empty"><?php esc_html_e( 'No activity recorded yet.', 'tcgiant-sync' ); ?></div>
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
	</div>
</div>