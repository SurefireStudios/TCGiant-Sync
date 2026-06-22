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
$log_entries      = TCGiant_Sync_Logger::get_recent_entries( 10 );
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

	<?php TCGiant_Sync_Admin::instance()->render_tabs( 'dashboard' ); ?>

	<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended ?>
	<?php if ( isset( $_GET['log_cleared'] ) && '1' === $_GET['log_cleared'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sync log cleared successfully.', 'tcgiant-sync' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['sales_cleared'] ) && '1' === $_GET['sales_cleared'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Recent sales cleared.', 'tcgiant-sync' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['queue_processed'] ) && '1' === $_GET['queue_processed'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Queue processed. Check the Activity Log for results.', 'tcgiant-sync' ); ?></p></div>
	<?php endif; ?>
	<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

	<!-- ─── SUPPORT NOTIFICATION ─── -->
	<div class="notice notice-info" style="border-left-color: #2563eb;">
		<p>
			<strong><?php esc_html_e( 'Need Help?', 'tcgiant-sync' ); ?></strong><br>
			<?php esc_html_e( 'If you are experiencing issues, please ensure you are updated to the most recent version of the plugin via your WordPress Plugins page. If you are still having trouble, we\'re here to help! Contact us at ', 'tcgiant-sync' ); ?>
			<a href="mailto:hello@tcgiant.com">hello@tcgiant.com</a>.
		</p>
	</div>

	<!-- ─── ONBOARDING WIDGET ─── -->
	<?php
	// Check onboarding status
	$onboarding_dismissed = get_user_meta( get_current_user_id(), 'tcgiant_sync_onboarding_dismissed', true );
	$policies_ok = ! empty( $settings['export_fulfillment_policy'] ) && ! empty( $settings['export_return_policy'] ) && ! empty( $settings['export_payment_policy'] );
	$has_imported = (int) $stats['synced_products'] > 0;
	$has_exported = (int) ( $export_state['total_pushed'] ?? 0 ) > 0;
	$has_synced = $has_imported || $has_exported;
	$onboarding_complete = $is_authenticated && $policies_ok && $has_synced;
	
	// Handle dismissal
	if ( isset( $_GET['dismiss_onboarding'] ) && '1' === $_GET['dismiss_onboarding'] ) {
		update_user_meta( get_current_user_id(), 'tcgiant_sync_onboarding_dismissed', '1' );
		$onboarding_dismissed = '1';
	}
	?>
	
	<?php if ( ! $onboarding_dismissed ) : ?>
		<div class="tc-card" style="margin-bottom: 20px; border-left: 4px solid var(--tc-accent);">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
				<h2 style="margin: 0; font-size: 18px;"><span class="dashicons dashicons-clipboard" style="vertical-align: middle;"></span> <?php esc_html_e( 'Getting Started Checklist', 'tcgiant-sync' ); ?></h2>
				<?php if ( $onboarding_complete ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'dismiss_onboarding', '1' ) ); ?>" class="tc-button secondary" style="font-size: 12px;"><?php esc_html_e( 'Dismiss', 'tcgiant-sync' ); ?></a>
				<?php endif; ?>
			</div>
			
			<?php if ( $onboarding_complete ) : ?>
				<div class="notice notice-success inline" style="margin: 0 0 15px 0;">
					<p><strong><?php esc_html_e( 'Awesome! You have completed all setup steps.', 'tcgiant-sync' ); ?></strong></p>
				</div>
			<?php else : ?>
				<p style="margin-bottom: 15px; color: var(--tc-text-muted);"><?php esc_html_e( 'Complete these steps to unlock the full potential of TCGiant Sync.', 'tcgiant-sync' ); ?></p>
			<?php endif; ?>
			
			<ul style="margin: 0; padding: 0; list-style: none;">
				<li style="margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
					<?php if ( $is_authenticated ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color: #10b981;"></span>
						<span style="color: var(--tc-text-muted); text-decoration: line-through;"><?php esc_html_e( 'Connect your eBay Account', 'tcgiant-sync' ); ?></span>
					<?php else : ?>
						<span class="dashicons dashicons-marker" style="color: #d1d5db;"></span>
						<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-settings' ) ); ?>"><?php esc_html_e( 'Connect your eBay Account', 'tcgiant-sync' ); ?></a></strong>
					<?php endif; ?>
				</li>
				<li style="margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
					<?php if ( $policies_ok ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color: #10b981;"></span>
						<span style="color: var(--tc-text-muted); text-decoration: line-through;"><?php esc_html_e( 'Configure Export Defaults (Business Policies)', 'tcgiant-sync' ); ?></span>
					<?php else : ?>
						<span class="dashicons dashicons-marker" style="color: #d1d5db;"></span>
						<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-settings' ) ); ?>"><?php esc_html_e( 'Configure Export Defaults (Business Policies)', 'tcgiant-sync' ); ?></a></strong>
					<?php endif; ?>
				</li>
				<li style="display: flex; align-items: center; gap: 10px;">
					<?php if ( $has_synced ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color: #10b981;"></span>
						<span style="color: var(--tc-text-muted); text-decoration: line-through;"><?php esc_html_e( 'Run your first Import or Push', 'tcgiant-sync' ); ?></span>
					<?php else : ?>
						<span class="dashicons dashicons-marker" style="color: #d1d5db;"></span>
						<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-import' ) ); ?>"><?php esc_html_e( 'Run your first Import or Push', 'tcgiant-sync' ); ?></a></strong>
					<?php endif; ?>
				</li>
			</ul>
		</div>
	<?php endif; ?>

	<!-- ─── QUICK STATS BAR ─── -->
	<div class="tc-row-3col">

		<!-- Import Status Card -->
		<div class="tc-top-card" style="flex:1;">
			<div class="tc-top-card-left">
				<span class="dashicons dashicons-download tc-top-card-icon"></span>
				<div class="tc-top-card-text">
					<h3><?php esc_html_e( 'Total Imported', 'tcgiant-sync' ); ?></h3>
					<p style="font-size: 24px; font-weight: bold; margin: 5px 0; color: var(--tc-text);">
						<?php echo esc_html( $stats['synced_products'] ); ?>
					</p>
					<p style="font-size: 12px; color: var(--tc-text-muted);">
						<span class="tc-sync-dot <?php echo esc_attr( $sync_state['status'] ); ?>" style="display:inline-block;width:8px;height:8px;border-radius:50%;vertical-align:middle;"></span>
						<?php
						$import_labels = array(
							'scanning'      => 'Scanning...',
							'importing'     => 'Importing...',
							'complete'      => 'Idle',
							'stopped'       => 'Idle',
							'error'         => 'Error',
							'limit_reached' => 'Limit Reached',
						);
						echo esc_html( $import_labels[ $sync_state['status'] ] ?? 'Idle' );
						?>
					</p>
				</div>
			</div>
		</div>

		<!-- Export Status Card -->
		<div class="tc-top-card" style="flex:1;">
			<div class="tc-top-card-left">
				<span class="dashicons dashicons-upload tc-top-card-icon"></span>
				<div class="tc-top-card-text">
					<h3><?php esc_html_e( 'Total Exported', 'tcgiant-sync' ); ?></h3>
					<p style="font-size: 24px; font-weight: bold; margin: 5px 0; color: var(--tc-text);">
						<?php echo esc_html( $export_state['total_pushed'] ?? '0' ); ?>
					</p>
					<p style="font-size: 12px; color: var(--tc-text-muted);">
						<?php if ( ! empty( $export_state['last_completed'] ) ) : ?>
							<?php echo esc_html( 'Last: ' . $export_state['last_completed'] ); ?>
						<?php else : ?>
							<?php esc_html_e( 'No exports yet', 'tcgiant-sync' ); ?>
						<?php endif; ?>
					</p>
				</div>
			</div>
		</div>

		<!-- Sync Health Card -->
		<div class="tc-top-card" style="flex:1;">
			<div class="tc-top-card-left">
				<span class="dashicons dashicons-heart tc-top-card-icon"></span>
				<div class="tc-top-card-text">
					<h3><?php esc_html_e( 'Sync Health', 'tcgiant-sync' ); ?></h3>
					<p style="font-size: 24px; font-weight: bold; margin: 5px 0; color: <?php echo $is_authenticated ? '#10b981' : '#ef4444'; ?>;">
						<?php echo $is_authenticated ? esc_html__( 'Healthy', 'tcgiant-sync' ) : esc_html__( 'Action Needed', 'tcgiant-sync' ); ?>
					</p>
					<p style="font-size: 12px; color: var(--tc-text-muted);">
						<?php echo $is_authenticated ? esc_html( $settings['store_name'] ?? 'Connected' ) : esc_html__( 'Not Connected to eBay', 'tcgiant-sync' ); ?>
					</p>
				</div>
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
		</div>

		<div>
			<!-- Quick Links -->
			<div class="tc-card" style="margin-top:0; margin-bottom: 20px;">
				<h2 style="margin-top:0;"><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Quick Actions', 'tcgiant-sync' ); ?></h2>
				<div style="display:flex;flex-wrap:wrap;gap:8px;">
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

			<!-- Recent WooCommerce Orders — Push Stock to eBay -->
			<div class="tc-card" style="margin-top:0;">
			<div class="tc-log-header">
				<h2 style="margin-bottom:0;"><span class="dashicons dashicons-cart"></span> <?php esc_html_e( 'Recent Sales — Sync to eBay', 'tcgiant-sync' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
					<input type="hidden" name="action" value="tcgiant_clear_recent_sales">
					<?php wp_nonce_field( 'tcgiant_clear_recent_sales' ); ?>
					<button type="submit" class="tc-button secondary" style="font-size:11px;padding:4px 10px;"><?php esc_html_e( 'Clear', 'tcgiant-sync' ); ?></button>
				</form>
			</div>
			<p style="color:var(--tc-text-muted,#666);font-size:12px;margin-top:-8px;margin-bottom:12px;">
				<?php esc_html_e( 'Sold something on your site? Click "Push to eBay" on that order to instantly update the stock on your eBay listing — no queue, no cron.', 'tcgiant-sync' ); ?>
			</p>
			<?php
			$recent_orders = wc_get_orders( array(
				'limit'      => 10,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'status'     => array( 'wc-completed', 'wc-processing' ),
				'meta_query' => array(
					array(
						'key'     => '_tcgiant_sync_pushed',
						'compare' => 'NOT EXISTS',
					),
				),
			) );
			?>
			<?php if ( empty( $recent_orders ) ) : ?>
				<div class="tc-premium-empty-state">
					<span class="dashicons dashicons-cart"></span>
					<p><?php esc_html_e( 'No recent sales found.', 'tcgiant-sync' ); ?></p>
				</div>
			<?php else : ?>
				<table class="tc-sales-table">
					<thead>
						<tr>
							<th>Order</th>
							<th>Date</th>
							<th>Items</th>
							<th>Status</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $recent_orders as $recent_order ) : ?>
						<?php
						$item_names = array();
						foreach ( $recent_order->get_items() as $oi ) {
							$item_names[] = $oi->get_name();
						}
						$items_str = implode( ', ', $item_names );
						if ( strlen( $items_str ) > 60 ) {
							$items_str = substr( $items_str, 0, 57 ) . '...';
						}
						?>
						<tr class="tc-order-row" data-order-id="<?php echo esc_attr( $recent_order->get_id() ); ?>">
							<td><strong>#<?php echo esc_html( $recent_order->get_id() ); ?></strong></td>
							<td style="color:var(--tc-text-muted,#666);"><?php echo esc_html( $recent_order->get_date_created()->date( 'M j, g:ia' ) ); ?></td>
							<td><?php echo esc_html( $items_str ); ?></td>
							<td>
								<span style="font-size:11px;padding:3px 8px;border-radius:12px;font-weight:500;background:<?php echo 'wc-completed' === 'wc-' . $recent_order->get_status() ? '#d1fae5' : '#fef3c7'; ?>;color:<?php echo 'wc-completed' === 'wc-' . $recent_order->get_status() ? '#065f46' : '#92400e'; ?>;">
									<?php echo esc_html( ucfirst( $recent_order->get_status() ) ); ?>
								</span>
							</td>
							<td style="text-align:right;">
								<button
									class="tc-button tc-push-order-btn"
									data-order-id="<?php echo esc_attr( $recent_order->get_id() ); ?>"
									style="font-size:11px;padding:5px 12px;background:var(--tc-accent,#2563eb);color:#fff;white-space:nowrap;box-shadow:0 2px 4px rgba(37,99,235,0.2);"
								>
									<span class="dashicons dashicons-yes" style="font-size:13px;vertical-align:middle;"></span>
									<?php esc_html_e( 'Push to eBay', 'tcgiant-sync' ); ?>
								</button>
								<span class="tc-order-result" style="display:none;font-size:11px;margin-left:6px;"></span>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

	</div>
	</div><!-- .tc-row-2col -->

	<!-- Activity Log -->
	<div class="tc-card" style="margin-top:20px;">
		<div class="tc-log-header">
			<h2 style="margin-bottom:0;"><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Recent Activity', 'tcgiant-sync' ); ?></h2>
			<div style="display:flex;gap:10px;align-items:center;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-logs' ) ); ?>" class="tc-button secondary" style="font-size:11px;padding:4px 10px;">
					<?php esc_html_e( 'View All Logs', 'tcgiant-sync' ); ?>
				</a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
					<input type="hidden" name="action" value="tcgiant_clear_log">
					<?php wp_nonce_field( 'tcgiant_clear_log' ); ?>
					<button type="submit" class="tc-button secondary" style="font-size:11px;padding:4px 10px;"><?php esc_html_e( 'Clear', 'tcgiant-sync' ); ?></button>
				</form>
			</div>
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
</div>