<?php
/**
 * Push to eBay Page View
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$is_authenticated = TCGiant_Sync_OAuth::instance()->is_authenticated();
$settings         = TCGiant_Sync_OAuth::instance()->get_settings();
$license          = TCGiant_Sync_License::instance();
$license_ui       = $license->get_status_for_ui();
$auth_url         = TCGiant_Sync_OAuth::instance()->get_authorization_url();

$policies_configured = ! empty( $settings['export_fulfillment_policy'] )
                    && ! empty( $settings['export_return_policy'] )
                    && ! empty( $settings['export_payment_policy'] );
$category_configured = ! empty( $settings['export_category_id'] );

$notice_border = $policies_configured ? '#2271b1' : '#d63638';
$notice_bg     = $policies_configured ? '#f0f6fc'  : '#fcf0f1';
$notice_color  = $policies_configured ? '#2271b1'  : '#d63638';

$export_state = TCGiant_Sync_Exporter::get_export_state();
?>

<div class="wrap tc-dashboard-wrap">
	<div class="tc-header">
		<h1><?php esc_html_e( 'Push to eBay', 'tcgiant-sync' ); ?></h1>
		<p class="tc-subtitle"><?php esc_html_e( 'Create and update eBay listings directly from your WooCommerce products — individually or in bulk.', 'tcgiant-sync' ); ?></p>
	</div>

	<?php if ( ! $is_authenticated ) : ?>
		<div class="tc-card" style="text-align:center;padding:40px 20px;">
			<span class="dashicons dashicons-lock" style="font-size:48px;color:var(--tc-warning);width:48px;height:48px;margin-bottom:16px;display:block;margin-left:auto;margin-right:auto;"></span>
			<h2 style="margin-bottom:8px;"><?php esc_html_e( 'eBay Not Connected', 'tcgiant-sync' ); ?></h2>
			<p style="color:#666;margin-bottom:20px;"><?php esc_html_e( 'Connect your eBay account first to push listings.', 'tcgiant-sync' ); ?></p>
			<a href="<?php echo esc_url( $auth_url ); ?>" class="tc-button success"><?php esc_html_e( 'Connect to eBay', 'tcgiant-sync' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-settings' ) ); ?>" class="tc-button secondary" style="margin-left:8px;"><?php esc_html_e( 'Go to Settings', 'tcgiant-sync' ); ?></a>
		</div>
	<?php else : ?>

	<!-- ─── BUSINESS POLICY NOTICE ─── -->
	<div style="border-left:4px solid <?php echo esc_attr( $notice_border ); ?>;background:<?php echo esc_attr( $notice_bg ); ?>;padding:12px 16px;border-radius:0 4px 4px 0;margin-bottom:20px;font-size:13px;line-height:1.6;">
		<p style="margin:0 0 4px;font-weight:600;color:<?php echo esc_attr( $notice_color ); ?>;">
			<?php if ( $policies_configured ) : ?>
				<?php esc_html_e( '✔ Business Policies configured — you\'re ready to push!', 'tcgiant-sync' ); ?>
			<?php else : ?>
				<?php esc_html_e( '⚠ Setup required before you can push listings.', 'tcgiant-sync' ); ?>
			<?php endif; ?>
		</p>
		<p style="margin:0;color:#444;">
			<?php if ( $policies_configured && $category_configured ) : ?>
				<?php
				$conditions    = TCGiant_Sync_Exporter::CONDITIONS;
				$cond_id       = $settings['export_condition_id'] ?? '1000';
				$cond_label    = $conditions[ $cond_id ] ?? $cond_id;
				?>
				<?php
				/* translators: %s: eBay category ID */
				printf( esc_html__( 'Default category: %s · Condition: %s', 'tcgiant-sync' ), '<strong>' . esc_html( $settings['export_category_id'] ) . '</strong>', '<strong>' . esc_html( $cond_label ) . '</strong>' );
				?>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-settings' ) ); ?>" style="color:inherit;"><?php esc_html_e( 'Edit settings ↗', 'tcgiant-sync' ); ?></a>
			<?php else : ?>
				<?php if ( ! $category_configured ) : ?>
					<?php esc_html_e( 'A default eBay Category ID is required. ', 'tcgiant-sync' ); ?>
				<?php endif; ?>
				<?php if ( ! $policies_configured ) : ?>
					<?php esc_html_e( 'Business Policies (Shipping, Returns, Payments) must be configured. ', 'tcgiant-sync' ); ?>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-settings' ) ); ?>" class="tc-button" style="display:inline-block;margin-top:6px;font-size:12px;padding:5px 12px;"><?php esc_html_e( 'Go to Settings to complete setup →', 'tcgiant-sync' ); ?></a>
				<br><a href="https://www.ebay.com/help/selling/listings/selling-policies/business-policies?id=4212" target="_blank" rel="noopener" style="color:#666;font-size:12px;"><?php esc_html_e( 'What are Business Policies? ↗', 'tcgiant-sync' ); ?></a>
			<?php endif; ?>
		</p>
	</div>

	<div class="tc-row-2col">

		<!-- ─── HOW TO PUSH ─── -->
		<div class="tc-card">
			<h2><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'How to Push Listings', 'tcgiant-sync' ); ?></h2>

			<div class="tc-section" style="padding-top:0;border-top:none;margin-top:0;">
				<h3 class="tc-section-title"><?php esc_html_e( 'Single Product', 'tcgiant-sync' ); ?></h3>
				<p class="tc-section-desc">
					<?php esc_html_e( 'Open any WooCommerce product, go to the "eBay Sync" tab in the product data panel, and click "Push to eBay". You can override the category and condition per-product before pushing.', 'tcgiant-sync' ); ?>
				</p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>" class="tc-button secondary full-width">
					<span class="dashicons dashicons-products" style="font-size:16px;"></span>
					<?php esc_html_e( 'Browse Products', 'tcgiant-sync' ); ?>
				</a>
			</div>

			<div class="tc-section">
				<h3 class="tc-section-title"><?php esc_html_e( 'Bulk Push', 'tcgiant-sync' ); ?></h3>
				<p class="tc-section-desc">
					<?php esc_html_e( 'Go to Products, select the items you want to push, choose "Push to eBay" from the Bulk Actions dropdown and click Apply. Jobs run in the background via Action Scheduler.', 'tcgiant-sync' ); ?>
				</p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>" class="tc-button full-width">
					<span class="dashicons dashicons-list-view" style="font-size:16px;"></span>
					<?php esc_html_e( 'Go to Products → Bulk Push', 'tcgiant-sync' ); ?>
				</a>
			</div>

			<div class="tc-section">
				<h3 class="tc-section-title"><?php esc_html_e( 'Smart Update Detection', 'tcgiant-sync' ); ?></h3>
				<p class="tc-section-desc">
					<?php esc_html_e( 'If a product already has an eBay Item ID saved (from a previous push or import), the plugin automatically uses ReviseItem to update the listing instead of creating a duplicate.', 'tcgiant-sync' ); ?>
				</p>
			</div>
		</div>

		<!-- ─── EXPORT STATUS ─── -->
		<div class="tc-card">
			<h2><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Export Status', 'tcgiant-sync' ); ?></h2>

			<?php
			$export_status = $export_state['status'] ?? 'idle';
			$status_labels = array(
				'idle'      => array( 'label' => 'Idle', 'color' => '#888' ),
				'queued'    => array( 'label' => 'Queued', 'color' => 'var(--tc-warning)' ),
				'pushing'   => array( 'label' => 'Pushing…', 'color' => 'var(--tc-primary)' ),
				'complete'  => array( 'label' => 'Complete', 'color' => 'var(--tc-success)' ),
				'error'     => array( 'label' => 'Error', 'color' => '#d63638' ),
			);
			$st = $status_labels[ $export_status ] ?? $status_labels['idle'];
			?>

			<div class="tc-sync-indicator">
				<div class="tc-sync-dot <?php echo esc_attr( 'complete' === $export_status ? 'complete' : ( 'error' === $export_status ? 'error' : ( 'idle' === $export_status ? 'stopped' : 'importing' ) ) ); ?>"></div>
				<div>
					<div class="tc-sync-status" style="color:<?php echo esc_attr( $st['color'] ); ?>;" id="tc-export-status"><?php echo esc_html( $st['label'] ); ?></div>
					<div class="tc-sync-detail" id="tc-export-detail">
						<?php if ( ! empty( $export_state['last_completed'] ) ) : ?>
							<?php echo esc_html( 'Last: ' . $export_state['last_completed'] ); ?>
						<?php else : ?>
							<?php esc_html_e( 'No exports have run yet.', 'tcgiant-sync' ); ?>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- Export Stats -->
			<div class="tc-sync-stats" style="margin-top:16px;">
				<div class="tc-mini-stat">
					<span class="tc-mini-val" id="tc-export-pushed"><?php echo esc_html( $export_state['total_pushed'] ?? '0' ); ?></span>
					<span class="tc-mini-label">Total Pushed</span>
				</div>
				<div class="tc-mini-stat">
					<span class="tc-mini-val" id="tc-export-updated"><?php echo esc_html( $export_state['total_updated'] ?? '0' ); ?></span>
					<span class="tc-mini-label">Updated</span>
				</div>
				<div class="tc-mini-stat">
					<span class="tc-mini-val" id="tc-export-errors"><?php echo esc_html( $export_state['total_errors'] ?? '0' ); ?></span>
					<span class="tc-mini-label">Errors</span>
				</div>
			</div>

			<div class="tc-section" style="margin-top:20px;">
				<h3 class="tc-section-title"><?php esc_html_e( 'Current Configuration', 'tcgiant-sync' ); ?></h3>
				<table style="width:100%;font-size:13px;border-collapse:collapse;">
					<tr>
						<td style="padding:5px 0;color:#666;width:45%;"><?php esc_html_e( 'Default Category', 'tcgiant-sync' ); ?></td>
						<td style="padding:5px 0;font-weight:500;">
							<?php echo $category_configured ? esc_html( $settings['export_category_id'] ) : '<span style="color:#d63638;">' . esc_html__( 'Not set', 'tcgiant-sync' ) . '</span>'; ?>
						</td>
					</tr>
					<tr>
						<td style="padding:5px 0;color:#666;"><?php esc_html_e( 'Condition', 'tcgiant-sync' ); ?></td>
						<td style="padding:5px 0;font-weight:500;">
							<?php
							$conditions  = TCGiant_Sync_Exporter::CONDITIONS;
							$cond_id     = $settings['export_condition_id'] ?? '1000';
							echo esc_html( $conditions[ $cond_id ] ?? $cond_id );
							?>
						</td>
					</tr>
					<tr>
						<td style="padding:5px 0;color:#666;"><?php esc_html_e( 'Fulfillment Policy', 'tcgiant-sync' ); ?></td>
						<td style="padding:5px 0;font-weight:500;">
							<?php echo ! empty( $settings['export_fulfillment_policy'] ) ? esc_html( $settings['export_fulfillment_policy'] ) : '<span style="color:#d63638;">' . esc_html__( 'Not set', 'tcgiant-sync' ) . '</span>'; ?>
						</td>
					</tr>
					<tr>
						<td style="padding:5px 0;color:#666;"><?php esc_html_e( 'Return Policy', 'tcgiant-sync' ); ?></td>
						<td style="padding:5px 0;font-weight:500;">
							<?php echo ! empty( $settings['export_return_policy'] ) ? esc_html( $settings['export_return_policy'] ) : '<span style="color:#d63638;">' . esc_html__( 'Not set', 'tcgiant-sync' ) . '</span>'; ?>
						</td>
					</tr>
					<tr>
						<td style="padding:5px 0;color:#666;"><?php esc_html_e( 'Payment Policy', 'tcgiant-sync' ); ?></td>
						<td style="padding:5px 0;font-weight:500;">
							<?php echo ! empty( $settings['export_payment_policy'] ) ? esc_html( $settings['export_payment_policy'] ) : '<span style="color:#d63638;">' . esc_html__( 'Not set', 'tcgiant-sync' ) . '</span>'; ?>
						</td>
					</tr>
				</table>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-settings' ) ); ?>" class="tc-button secondary full-width" style="margin-top:12px;">
					<span class="dashicons dashicons-admin-settings" style="font-size:14px;"></span>
					<?php esc_html_e( 'Edit Export Settings', 'tcgiant-sync' ); ?>
				</a>
			</div>
		</div>
	</div>
	<?php endif; ?>
</div>
