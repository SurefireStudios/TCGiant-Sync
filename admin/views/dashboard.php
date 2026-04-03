<?php
/**
 * Dashboard Page View
 *
 * @package TCGiant_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$admin          = TCGiant_Sync_Admin::instance();
$health         = $admin->get_system_health();
$stats          = $admin->get_sync_stats();
$sync_state     = TCGiant_Sync_Importer::get_sync_state();
$is_authenticated = TCGiant_Sync_OAuth::instance()->is_authenticated();
$settings       = TCGiant_Sync_OAuth::instance()->get_settings();
$auth_url       = TCGiant_Sync_OAuth::instance()->get_authorization_url();
$log_entries    = TCGiant_Sync_Logger::get_recent_entries( 20 );
$license        = TCGiant_Sync_License::instance();
$license_ui     = $license->get_status_for_ui();

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
		<p class="tc-subtitle"><?php esc_html_e( 'eBay → WooCommerce Inventory Management', 'tcgiant-sync' ); ?></p>
	</div>

	<?php if ( isset( $_GET['sync_started'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sync started! Watch the Sync Status card below.', 'tcgiant-sync' ); ?></p></div>
	<?php elseif ( isset( $_GET['queue_processed'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Queue processed.', 'tcgiant-sync' ); ?></p></div>
	<?php elseif ( isset( $_GET['auth'] ) && 'success' === $_GET['auth'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'eBay connected successfully!', 'tcgiant-sync' ); ?></p></div>
	<?php elseif ( isset( $_GET['auth'] ) && 'failed' === $_GET['auth'] ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'eBay connection failed. Check logs.', 'tcgiant-sync' ); ?></p></div>
	<?php elseif ( isset( $_GET['settings-updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'tcgiant-sync' ); ?></p></div>
	<?php elseif ( isset( $_GET['log_cleared'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Activity log cleared.', 'tcgiant-sync' ); ?></p></div>
	<?php endif; ?>

	<!-- ═══ ROW 1: CONFIGURATION + OPERATIONS (2 columns) ═══ -->
	<div class="tc-row-2col">

		<!-- ─── CONFIGURATION ─── -->
		<div class="tc-card">
			<h2><span class="dashicons dashicons-admin-settings"></span><?php esc_html_e( 'Configuration', 'tcgiant-sync' ); ?></h2>

			<!-- Connection Status -->
			<div class="tc-section">
				<h3 class="tc-section-title"><?php esc_html_e( 'eBay Account', 'tcgiant-sync' ); ?></h3>
				<div class="tc-connection">
					<?php if ( $is_authenticated ) : ?>
						<p><span class="dashicons dashicons-yes-alt" style="color: var(--tc-success);"></span> <strong><?php echo esc_html( sprintf( __( 'Connected: %s', 'tcgiant-sync' ), $settings['store_name'] ?? 'eBay Store' ) ); ?></strong></p>
						<a href="<?php echo esc_url( $auth_url ); ?>" class="tc-button secondary" style="font-size:12px; padding: 5px 12px;"><?php esc_html_e( 'Reconnect', 'tcgiant-sync' ); ?></a>
					<?php else : ?>
						<p><span class="dashicons dashicons-warning" style="color: var(--tc-warning);"></span> <strong><?php esc_html_e( 'Not Connected', 'tcgiant-sync' ); ?></strong></p>
						<a href="<?php echo esc_url( $auth_url ); ?>" class="tc-button success full-width"><?php esc_html_e( 'Connect to eBay', 'tcgiant-sync' ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<!-- License -->
			<div class="tc-section">
				<h3 class="tc-section-title"><?php esc_html_e( 'License', 'tcgiant-sync' ); ?></h3>
				<div id="tc-license-section">
					<?php if ( $license_ui['is_pro'] ) : ?>
						<div class="tc-license-active">
							<div class="tc-license-status-row">
								<span class="dashicons dashicons-yes-alt" style="color: var(--tc-success);"></span>
								<strong><?php esc_html_e( 'Pro License Active', 'tcgiant-sync' ); ?></strong>
								<?php if ( $license_ui['variant'] === 'lifetime' ) : ?>
									<span class="tc-lifetime-badge"><?php esc_html_e( 'Lifetime', 'tcgiant-sync' ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( ! empty( $license_ui['customer_name'] ) ) : ?>
								<p class="tc-license-meta"><?php echo esc_html( sprintf( __( 'Licensed to: %s', 'tcgiant-sync' ), $license_ui['customer_name'] ) ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $license_ui['expires_at'] ) && $license_ui['variant'] !== 'lifetime' ) : ?>
								<p class="tc-license-meta"><?php echo esc_html( sprintf( __( 'Expires: %s', 'tcgiant-sync' ), date_i18n( get_option( 'date_format' ), strtotime( $license_ui['expires_at'] ) ) ) ); ?></p>
							<?php endif; ?>
							<p class="tc-license-meta"><?php echo esc_html( sprintf( __( 'Key: %s', 'tcgiant-sync' ), $license_ui['key_masked'] ) ); ?></p>
							<button type="button" class="tc-button secondary" id="tc-deactivate-license" style="font-size:12px; padding: 5px 12px; margin-top: 8px;"><?php esc_html_e( 'Deactivate', 'tcgiant-sync' ); ?></button>
						</div>
					<?php else : ?>
						<div class="tc-license-input-wrap">
							<div class="tc-license-row">
								<input type="text" class="tc-input" id="tc-license-key" placeholder="<?php esc_attr_e( 'Enter your license key…', 'tcgiant-sync' ); ?>" autocomplete="off">
								<button type="button" class="tc-button" id="tc-activate-license"><?php esc_html_e( 'Activate', 'tcgiant-sync' ); ?></button>
							</div>
							<div id="tc-license-message" class="tc-license-msg" style="display:none;"></div>
							<div class="tc-license-divider"><span><?php esc_html_e( 'or', 'tcgiant-sync' ); ?></span></div>
							<a href="<?php echo esc_url( $license_ui['upgrade_url'] ); ?>" target="_blank" rel="noopener" class="tc-upgrade-link">
								<span class="dashicons dashicons-external"></span>
								<?php esc_html_e( 'Get TCGiant Sync Pro — $49/year', 'tcgiant-sync' ); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Settings Form -->
			<form method="post" action="options.php">
				<?php settings_fields( 'tcgiant_sync_ebay_group' ); ?>
				<?php
				$preserve_keys = array( 'access_token', 'refresh_token', 'token_expiry', 'relay_secret', 'redirect_uri', 'app_id', 'cert_id', 'store_name', 'import_status' );
				foreach ( $preserve_keys as $key ) {
					if ( ! empty( $settings[ $key ] ) ) {
						echo '<input type="hidden" name="tcgiant_sync_ebay_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $settings[ $key ] ) . '">';
					}
				}
				?>

				<!-- Category Filter -->
				<div class="tc-section">
					<h3 class="tc-section-title"><?php esc_html_e( 'Import Filters', 'tcgiant-sync' ); ?></h3>
					<div class="tc-field">
						<label class="tc-label"><?php esc_html_e( 'eBay Store Categories', 'tcgiant-sync' ); ?></label>
						<div class="tc-category-selector" id="tc-category-selector">
							<div class="tc-category-tags" id="tc-category-tags"></div>
							<input type="hidden" name="tcgiant_sync_ebay_settings[category_ids]" id="tc-category-hidden" value="<?php echo esc_attr( $settings['category_ids'] ?? '' ); ?>">
							<div style="display: flex; gap: 6px;">
								<select class="tc-select" id="tc-category-dropdown" style="display:none;">
									<option value=""><?php esc_html_e( '— Select a category —', 'tcgiant-sync' ); ?></option>
								</select>
								<button type="button" class="tc-button secondary" id="tc-load-categories" style="white-space:nowrap; flex-shrink:0; font-size:12px;"><?php esc_html_e( 'Load eBay Categories', 'tcgiant-sync' ); ?></button>
							</div>
							<p class="tc-hint"><?php esc_html_e( 'Load your store categories, then select which ones to import. Leave empty to import all.', 'tcgiant-sync' ); ?></p>
						</div>
					</div>
				</div>

				<!-- Scheduling -->
				<div class="tc-section">
					<h3 class="tc-section-title"><?php esc_html_e( 'Scheduling', 'tcgiant-sync' ); ?></h3>
					<div class="tc-field">
						<label class="tc-label" for="ebay_sync_interval"><?php esc_html_e( 'Auto-Sync Interval', 'tcgiant-sync' ); ?></label>
						<select name="tcgiant_sync_ebay_settings[sync_interval]" id="ebay_sync_interval" class="tc-select">
							<option value="disabled" <?php selected( $settings['sync_interval'] ?? 'disabled', 'disabled' ); ?>><?php esc_html_e( 'Disabled (Manual Only)', 'tcgiant-sync' ); ?></option>
							<option value="tcgiant_15mins" <?php selected( $settings['sync_interval'] ?? 'disabled', 'tcgiant_15mins' ); ?>><?php esc_html_e( 'Every 15 Minutes', 'tcgiant-sync' ); ?></option>
							<option value="tcgiant_hourly" <?php selected( $settings['sync_interval'] ?? 'disabled', 'tcgiant_hourly' ); ?>><?php esc_html_e( 'Hourly', 'tcgiant-sync' ); ?></option>
							<option value="twicedaily" <?php selected( $settings['sync_interval'] ?? 'disabled', 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'tcgiant-sync' ); ?></option>
							<option value="daily" <?php selected( $settings['sync_interval'] ?? 'disabled', 'daily' ); ?>><?php esc_html_e( 'Daily', 'tcgiant-sync' ); ?></option>
						</select>
					</div>

					<div class="tc-field">
						<label class="tc-label"><?php esc_html_e( 'Reduce WooCommerce stock on eBay sale?', 'tcgiant-sync' ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[enable_order_sync]" value="1" <?php checked( $settings['enable_order_sync'] ?? '0', '1' ); ?>> Yes</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[enable_order_sync]" value="0" <?php checked( $settings['enable_order_sync'] ?? '0', '0' ); ?>> No</label>
						</div>
					</div>
				</div>

				<div class="tc-form-footer">
					<button type="submit" class="tc-button"><?php esc_html_e( 'Save Settings', 'tcgiant-sync' ); ?></button>
				</div>
			</form>
		</div>

		<!-- ─── OPERATIONS ─── -->
		<div class="tc-card">
			<h2><span class="dashicons dashicons-performance"></span><?php esc_html_e( 'Operations', 'tcgiant-sync' ); ?></h2>

			<!-- ─── Usage Meter ─── -->
			<div class="tc-section tc-usage-section">
				<h3 class="tc-section-title"><?php esc_html_e( 'Import Usage', 'tcgiant-sync' ); ?></h3>
				<div class="tc-usage-meter" id="tc-usage-meter">
					<?php if ( $license_ui['is_pro'] ) : ?>
						<div class="tc-usage-pro">
							<span class="dashicons dashicons-yes-alt" style="color: var(--tc-success);"></span>
							<span><strong><?php echo esc_html( $license_ui['active_count'] ); ?></strong> <?php esc_html_e( 'active products', 'tcgiant-sync' ); ?> — <em><?php esc_html_e( 'Unlimited', 'tcgiant-sync' ); ?></em></span>
						</div>
					<?php else : ?>
						<div class="tc-usage-bar-wrap">
							<div class="tc-usage-counts">
								<span><strong id="tc-usage-count"><?php echo esc_html( $license_ui['active_count'] ); ?></strong> / <?php echo esc_html( $license_ui['free_limit'] ); ?> <?php esc_html_e( 'products', 'tcgiant-sync' ); ?></span>
								<span class="tc-usage-remaining" id="tc-usage-remaining"><?php echo esc_html( $license_ui['remaining'] ); ?> <?php esc_html_e( 'remaining', 'tcgiant-sync' ); ?></span>
							</div>
							<div class="tc-usage-bar">
								<div class="tc-usage-fill <?php echo $license_ui['usage_pct'] >= 90 ? 'tc-usage-critical' : ( $license_ui['usage_pct'] >= 70 ? 'tc-usage-warning' : '' ); ?>" id="tc-usage-fill" style="width: <?php echo esc_attr( $license_ui['usage_pct'] ); ?>%;"></div>
							</div>
						</div>
						<?php if ( $license_ui['usage_pct'] >= 80 ) : ?>
							<div class="tc-upgrade-nudge">
								<p><?php esc_html_e( 'Running low on import slots!', 'tcgiant-sync' ); ?></p>
								<a href="<?php echo esc_url( $license_ui['upgrade_url'] ); ?>" target="_blank" rel="noopener" class="tc-button tc-upgrade-btn">
									<span class="dashicons dashicons-superhero-alt" style="font-size:16px;"></span>
									<?php esc_html_e( 'Upgrade to Pro', 'tcgiant-sync' ); ?>
								</a>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $is_authenticated ) : ?>
				<div class="tc-section">
					<h3 class="tc-section-title"><?php esc_html_e( 'Fetch Inventory', 'tcgiant-sync' ); ?></h3>
					<p class="tc-section-desc"><?php esc_html_e( 'Scan your eBay store and queue matching items for import into WooCommerce.', 'tcgiant-sync' ); ?></p>
					<?php if ( ! $license_ui['can_import'] ) : ?>
						<div class="tc-limit-reached-inline">
							<span class="dashicons dashicons-lock" style="color: var(--tc-warning);"></span>
							<span><?php esc_html_e( 'Import limit reached. Upgrade to continue.', 'tcgiant-sync' ); ?></span>
						</div>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="tcgiant_sync_now">
						<?php wp_nonce_field( 'tcgiant_sync_now' ); ?>
						<button type="submit" class="tc-button full-width" id="tc-fetch-btn" <?php echo ! $license_ui['can_import'] ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''; ?>>
							<span class="dashicons dashicons-download" style="font-size:16px;"></span> <?php esc_html_e( 'Fetch Inventory', 'tcgiant-sync' ); ?>
						</button>
					</form>
				</div>

				<div class="tc-section">
					<h3 class="tc-section-title"><?php esc_html_e( 'Process Queue', 'tcgiant-sync' ); ?></h3>
					<p class="tc-section-desc"><?php esc_html_e( 'Force-run any pending background jobs immediately instead of waiting for WordPress cron.', 'tcgiant-sync' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="tcgiant_force_queue">
						<?php wp_nonce_field( 'tcgiant_force_queue' ); ?>
						<button type="submit" class="tc-button secondary full-width">
							<span class="dashicons dashicons-controls-play" style="font-size:16px;"></span> <?php esc_html_e( 'Process Queue', 'tcgiant-sync' ); ?>
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
							<span class="dashicons dashicons-dismiss" style="font-size:16px;"></span> <?php esc_html_e( 'STOP SYNC', 'tcgiant-sync' ); ?>
						</button>
					</form>
				</div>
			<?php else : ?>
				<div class="tc-section">
					<p class="tc-section-desc"><?php esc_html_e( 'Connect your eBay account to access sync operations.', 'tcgiant-sync' ); ?></p>
					<a href="<?php echo esc_url( $auth_url ); ?>" class="tc-button success full-width"><?php esc_html_e( 'Connect to eBay', 'tcgiant-sync' ); ?></a>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- ═══ ROW 2: SYSTEM HEALTH + SYNC STATUS + ACTIVITY LOG (3 columns) ═══ -->
	<div class="tc-row-3col">

		<!-- ─── SYSTEM HEALTH ─── -->
		<div class="tc-card">
			<h2><span class="dashicons dashicons-heart"></span><?php esc_html_e( 'System Health', 'tcgiant-sync' ); ?></h2>
			<div class="health-list">
				<?php foreach ( $health as $key => $metric ) : ?>
					<div class="health-item">
						<span class="health-label"><?php echo esc_html( $metric['label'] ); ?></span>
						<span class="status-badge <?php echo esc_attr( $metric['status'] ); ?>">
							<?php echo esc_html( $metric['text'] ); ?>
						</span>
					</div>
				<?php endforeach; ?>
				<!-- License Status in Health -->
				<div class="health-item">
					<span class="health-label"><?php esc_html_e( 'License', 'tcgiant-sync' ); ?></span>
					<span class="status-badge <?php echo $license_ui['is_pro'] ? 'active' : 'warning'; ?>">
						<?php echo $license_ui['is_pro'] ? esc_html__( 'Pro', 'tcgiant-sync' ) : esc_html__( 'Free Tier', 'tcgiant-sync' ); ?>
					</span>
				</div>
			</div>
		</div>

		<!-- ─── SYNC STATUS (Live) ─── -->
		<div class="tc-card">
			<h2><span class="dashicons dashicons-update"></span><?php esc_html_e( 'Sync Status', 'tcgiant-sync' ); ?></h2>

			<!-- Status Indicator -->
			<div class="tc-sync-indicator">
				<div class="tc-sync-dot <?php echo esc_attr( $sync_state['status'] ); ?>"></div>
				<div>
					<div class="tc-sync-status" id="tc-hero-status">
						<?php
						switch ( $sync_state['status'] ) {
							case 'scanning':      echo 'Scanning eBay…'; break;
							case 'importing':     echo 'Importing…'; break;
							case 'complete':      echo 'Complete'; break;
							case 'stopped':       echo 'Stopped'; break;
							case 'error':         echo 'Error'; break;
							case 'limit_reached': echo 'Import Limit Reached'; break;
							default:              echo 'Idle';
						}
						?>
					</div>
					<div class="tc-sync-detail" id="tc-hero-detail">
						<?php
						if ( 'scanning' === $sync_state['status'] ) {
							echo 'Page ' . esc_html( $sync_state['current_page'] ) . ( $sync_state['total_pages'] ? '/' . esc_html( $sync_state['total_pages'] ) : '' );
							echo ' — ' . esc_html( $sync_state['filter_name'] );
						} elseif ( 'importing' === $sync_state['status'] ) {
							echo esc_html( $sync_state['total_processed'] ) . '/' . esc_html( $sync_state['total_queued'] ) . ' items';
						} elseif ( 'complete' === $sync_state['status'] ) {
							echo esc_html( $sync_state['total_processed'] ) . ' imported, ' . esc_html( $sync_state['total_errors'] ) . ' errors';
						} elseif ( 'limit_reached' === $sync_state['status'] ) {
							echo sprintf(
								esc_html__( '%d/%d products — Upgrade to Pro for unlimited', 'tcgiant-sync' ),
								$license_ui['active_count'],
								$license_ui['free_limit']
							);
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
					<p><?php esc_html_e( 'You\'ve reached the free tier limit of 50 active products. Upgrade to TCGiant Sync Pro for unlimited imports.', 'tcgiant-sync' ); ?></p>
					<a href="<?php echo esc_url( $license_ui['upgrade_url'] ); ?>" target="_blank" rel="noopener" class="tc-button tc-upgrade-btn full-width">
						<span class="dashicons dashicons-superhero-alt" style="font-size:16px;"></span>
						<?php esc_html_e( 'Upgrade to Pro — $49/year', 'tcgiant-sync' ); ?>
					</a>
				</div>
			<?php endif; ?>

			<!-- Progress Bar -->
			<div id="tc-progress" class="tc-progress-wrap" style="display: <?php echo 'importing' === $sync_state['status'] ? 'block' : 'none'; ?>;">
				<?php
				$pct = 0;
				if ( $sync_state['total_queued'] > 0 ) {
					$pct = round( ( ( $sync_state['total_processed'] + $sync_state['total_errors'] ) / $sync_state['total_queued'] ) * 100 );
				}
				?>
				<div class="tc-progress-bar">
					<div class="tc-progress-fill" style="width: <?php echo esc_attr( $pct ); ?>%;"></div>
				</div>
				<div class="tc-progress-text"><?php echo esc_html( $pct ); ?>%</div>
			</div>

			<!-- Counters -->
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
					<span class="tc-mini-label">Pending</span>
				</div>
			</div>

			<?php if ( ! empty( $sync_state['last_item_title'] ) && in_array( $sync_state['status'], array( 'importing', 'complete' ), true ) ) : ?>
				<div class="tc-last-item">
					<span class="tc-mini-label">Latest:</span>
					<span id="tc-last-item-title"><?php echo esc_html( $sync_state['last_item_title'] ); ?></span>
				</div>
			<?php endif; ?>
		</div>

		<!-- ─── ACTIVITY LOG ─── -->
		<div class="tc-card">
			<div class="tc-log-header">
				<h2 style="margin-bottom:0;"><span class="dashicons dashicons-list-view"></span><?php esc_html_e( 'Activity Log', 'tcgiant-sync' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
					<input type="hidden" name="action" value="tcgiant_clear_log">
					<?php wp_nonce_field( 'tcgiant_clear_log' ); ?>
					<button type="submit" class="tc-button secondary" style="font-size:11px; padding: 4px 10px;"><?php esc_html_e( 'Clear', 'tcgiant-sync' ); ?></button>
				</form>
			</div>
			<div class="tc-log-viewer" id="tc-log-content">
				<?php if ( empty( $log_entries ) ) : ?>
					<div class="tc-log-entry tc-log-empty"><?php esc_html_e( 'No activity recorded yet.', 'tcgiant-sync' ); ?></div>
				<?php else : ?>
					<?php foreach ( $log_entries as $entry ) :
						$level_class = '';
						$icon = '📋';
						switch ( $entry['level'] ) {
							case 'error':   $level_class = 'tc-is-error';   $icon = '❌'; break;
							case 'success': $level_class = 'tc-is-success'; $icon = '✅'; break;
							case 'warning': $level_class = 'tc-is-warning'; $icon = '⚠️'; break;
						}
						?>
						<div class="tc-log-entry <?php echo esc_attr( $level_class ); ?>">
							<span class="tc-log-icon"><?php echo $icon; ?></span>
							<span class="tc-log-time"><?php echo esc_html( $entry['timestamp'] ); ?></span>
							<span class="tc-log-msg"><?php echo esc_html( $entry['message'] ); ?></span>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
