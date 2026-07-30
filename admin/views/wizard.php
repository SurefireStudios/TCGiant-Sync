<?php
/**
 * Setup Wizard
 *
 * Multi-step guided setup for new installations.
 * Steps: Connect → Import Settings → Export Settings → Done.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = TCGiant_Sync_OAuth::instance()->get_settings();
// Use the shared authentication check — the token lives under 'access_token',
// not 'ebay_access_token', so the old local check never reported connected.
$is_connected = TCGiant_Sync_OAuth::instance()->is_authenticated();
// Route through the nonce-protected connect handler like every other view.
$auth_url     = TCGiant_Sync_OAuth::instance()->get_authorization_url();
$license_key    = $settings['license_key'] ?? '';
$license_status = $settings['license_status'] ?? '';
$marketplace    = $settings['marketplace'] ?? 'EBAY_US';
$current_step   = isset( $_GET['step'] ) ? max( 1, min( 4, (int) $_GET['step'] ) ) : 1;

// Auto-advance: if connected but on step 1, go to step 2.
if ( $is_connected && 1 === $current_step && 'active' === $license_status ) {
	$current_step = 2;
}
?>

<div class="wrap" style="max-width: 700px; margin: 40px auto;">
	<style>
		.tc-wizard { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 0; }
		.tc-wizard-header { padding: 24px 30px 20px; border-bottom: 1px solid #e0e0e0; }
		.tc-wizard-header h1 { margin: 0 0 16px; font-size: 22px; color: #1d2327; }
		.tc-wizard-steps { display: flex; gap: 0; counter-reset: step; }
		.tc-wizard-step { flex: 1; text-align: center; padding: 8px 0; position: relative; font-size: 13px; color: #888; }
		.tc-wizard-step::before { content: counter(step); counter-increment: step; display: block; width: 28px; height: 28px; line-height: 28px; border-radius: 50%; background: #e0e0e0; color: #666; font-weight: 600; margin: 0 auto 6px; }
		.tc-wizard-step.active { color: #1d2327; font-weight: 600; }
		.tc-wizard-step.active::before { background: #2271b1; color: #fff; }
		.tc-wizard-step.done { color: #46B450; }
		.tc-wizard-step.done::before { content: "✓"; background: #46B450; color: #fff; }
		.tc-wizard-body { padding: 30px; }
		.tc-wizard-body h2 { margin: 0 0 8px; font-size: 18px; }
		.tc-wizard-body p { color: #555; font-size: 14px; line-height: 1.6; }
		.tc-wizard-footer { padding: 20px 30px; border-top: 1px solid #e0e0e0; display: flex; justify-content: space-between; }
		.tc-wizard .tc-field { margin-bottom: 20px; }
		.tc-wizard .tc-label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px; }
		.tc-wizard .tc-select, .tc-wizard .tc-input { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
		.tc-wizard .tc-hint { font-size: 12px; color: #888; margin-top: 4px; }
		.tc-wizard .tc-button { display: inline-block; padding: 10px 24px; border-radius: 4px; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; border: none; }
		.tc-wizard .tc-button.primary { background: #2271b1; color: #fff; }
		.tc-wizard .tc-button.primary:hover { background: #135e96; }
		.tc-wizard .tc-button.secondary { background: #f0f0f0; color: #333; }
		.tc-wizard .tc-button.success { background: #46B450; color: #fff; }
		.tc-wizard .tc-connected { display: flex; align-items: center; gap: 10px; background: #f0faf0; border: 1px solid #c3e6cb; padding: 14px 18px; border-radius: 6px; }
		.tc-wizard .tc-connected .dashicons { color: #46B450; font-size: 24px; }
	</style>

	<div class="tc-wizard">
		<div class="tc-wizard-header">
			<h1><?php esc_html_e( 'TCGiant Sync — Setup Wizard', 'tcgiant-sync' ); ?></h1>
			<div class="tc-wizard-steps">
				<div class="tc-wizard-step <?php echo $current_step >= 1 ? ( $current_step > 1 ? 'done' : 'active' ) : ''; ?>"><?php esc_html_e( 'Connect', 'tcgiant-sync' ); ?></div>
				<div class="tc-wizard-step <?php echo $current_step >= 2 ? ( $current_step > 2 ? 'done' : 'active' ) : ''; ?>"><?php esc_html_e( 'Import', 'tcgiant-sync' ); ?></div>
				<div class="tc-wizard-step <?php echo $current_step >= 3 ? ( $current_step > 3 ? 'done' : 'active' ) : ''; ?>"><?php esc_html_e( 'Export', 'tcgiant-sync' ); ?></div>
				<div class="tc-wizard-step <?php echo 4 === $current_step ? 'active' : ''; ?>"><?php esc_html_e( 'Done', 'tcgiant-sync' ); ?></div>
			</div>
		</div>

		<div class="tc-wizard-body">

		<?php if ( 1 === $current_step ) : ?>
			<!-- Step 1: Connect -->
			<h2><?php esc_html_e( 'Connect Your eBay Account', 'tcgiant-sync' ); ?></h2>
			<p><?php esc_html_e( 'First, activate your license key. Then connect your eBay seller account to start syncing.', 'tcgiant-sync' ); ?></p>

			<?php if ( $is_connected && 'active' === $license_status ) : ?>
				<div class="tc-connected">
					<span class="dashicons dashicons-yes-alt"></span>
					<div>
						<strong><?php esc_html_e( 'Connected!', 'tcgiant-sync' ); ?></strong>
						<p style="margin:0;font-size:12px;color:#555;"><?php esc_html_e( 'Your eBay account and license are active.', 'tcgiant-sync' ); ?></p>
					</div>
				</div>
			<?php else : ?>
				<?php if ( 'active' !== $license_status ) : ?>
				<div class="tc-field">
					<label class="tc-label" for="wizard_license_key"><?php esc_html_e( 'License Key', 'tcgiant-sync' ); ?></label>
					<div style="display:flex;gap:8px;">
						<input type="text" class="tc-input" id="wizard_license_key" value="<?php echo esc_attr( $license_key ); ?>" placeholder="<?php esc_attr_e( 'Enter your license key', 'tcgiant-sync' ); ?>" style="flex:1;" />
						<button type="button" class="tc-button primary" id="wizard-activate-license" style="white-space:nowrap;"><?php esc_html_e( 'Activate', 'tcgiant-sync' ); ?></button>
					</div>
					<p class="tc-hint" id="wizard-license-status"></p>
				</div>
				<?php endif; ?>

				<?php if ( ! $is_connected ) : ?>
				<div class="tc-field" style="margin-top:20px;">
					<a href="<?php echo esc_url( $auth_url ); ?>" class="tc-button success" style="display:inline-block;text-align:center;">
						<span class="dashicons dashicons-external" style="vertical-align:middle;margin-right:4px;font-size:16px;"></span>
						<?php esc_html_e( 'Connect to eBay', 'tcgiant-sync' ); ?>
					</a>
				</div>
				<?php endif; ?>
			<?php endif; ?>

		<?php elseif ( 2 === $current_step ) : ?>
			<!-- Step 2: Import Settings -->
			<h2><?php esc_html_e( 'Import Settings', 'tcgiant-sync' ); ?></h2>
			<p><?php esc_html_e( 'Configure how products are imported from eBay. You can always change these later in Settings.', 'tcgiant-sync' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tcgiant_wizard_save_import" />
				<?php wp_nonce_field( 'tcgiant_wizard_save_import' ); ?>

				<div class="tc-field">
					<label class="tc-label" for="wizard_marketplace"><?php esc_html_e( 'eBay Marketplace', 'tcgiant-sync' ); ?></label>
					<select class="tc-select" name="marketplace" id="wizard_marketplace">
						<?php
						foreach ( TCGiant_Sync_API::MARKETPLACES as $m_id => $m_data ) {
							printf( '<option value="%s" %s>%s</option>', esc_attr( $m_id ), selected( $marketplace, $m_id, false ), esc_html( $m_data['label'] ) );
						}
						?>
					</select>
				</div>

				<div class="tc-field">
					<label class="tc-label" for="wizard_sync_interval"><?php esc_html_e( 'Sync Interval', 'tcgiant-sync' ); ?></label>
					<select class="tc-select" name="sync_interval" id="wizard_sync_interval">
						<option value="disabled"><?php esc_html_e( 'Disabled (manual only)', 'tcgiant-sync' ); ?></option>
						<option value="tcgiant_15mins"><?php esc_html_e( 'Every 15 Minutes', 'tcgiant-sync' ); ?></option>
						<option value="tcgiant_hourly" selected><?php esc_html_e( 'Hourly (Recommended)', 'tcgiant-sync' ); ?></option>
						<option value="twicedaily"><?php esc_html_e( 'Twice Daily', 'tcgiant-sync' ); ?></option>
						<option value="daily"><?php esc_html_e( 'Daily', 'tcgiant-sync' ); ?></option>
					</select>
				</div>

				<div class="tc-field">
					<label class="tc-label"><?php esc_html_e( 'Import eBay orders?', 'tcgiant-sync' ); ?></label>
					<label><input type="radio" name="enable_order_import" value="1"> <?php esc_html_e( 'Yes', 'tcgiant-sync' ); ?></label>
					<label style="margin-left:16px;"><input type="radio" name="enable_order_import" value="0" checked> <?php esc_html_e( 'No', 'tcgiant-sync' ); ?></label>
				</div>

				<div class="tc-field">
					<label class="tc-label"><?php esc_html_e( 'Image handling', 'tcgiant-sync' ); ?></label>
					<label><input type="radio" name="disable_image_localization" value="1" checked> <?php esc_html_e( 'Keep eBay-hosted URLs (fast, saves disk)', 'tcgiant-sync' ); ?></label><br>
					<label><input type="radio" name="disable_image_localization" value="0"> <?php esc_html_e( 'Download to Media Library (background)', 'tcgiant-sync' ); ?></label>
				</div>

				<button type="submit" class="tc-button primary"><?php esc_html_e( 'Save & Continue →', 'tcgiant-sync' ); ?></button>
			</form>

		<?php elseif ( 3 === $current_step ) : ?>
			<!-- Step 3: Export Settings -->
			<h2><?php esc_html_e( 'Export Settings (Optional)', 'tcgiant-sync' ); ?></h2>
			<p><?php esc_html_e( 'If you want to push WooCommerce products to eBay, configure your default listing settings. Skip this if you only want to import.', 'tcgiant-sync' ); ?></p>
			<p style="font-size:13px;color:#666;"><?php esc_html_e( 'You can configure these later in Settings → Push to eBay tab.', 'tcgiant-sync' ); ?></p>

		<?php elseif ( 4 === $current_step ) : ?>
			<!-- Step 4: Done -->
			<div style="text-align:center;padding:20px 0;">
				<span class="dashicons dashicons-yes-alt" style="font-size:64px;width:64px;height:64px;color:#46B450;"></span>
				<h2 style="margin-top:16px;"><?php esc_html_e( 'You\'re All Set!', 'tcgiant-sync' ); ?></h2>
				<p style="max-width:400px;margin:0 auto;"><?php esc_html_e( 'TCGiant Sync is configured and ready. Run your first sync from the Import page to start bringing in your eBay listings.', 'tcgiant-sync' ); ?></p>
			</div>
		<?php endif; ?>

		</div>

		<div class="tc-wizard-footer">
			<?php if ( $current_step > 1 && $current_step < 4 ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'step', $current_step - 1 ) ); ?>" class="tc-button secondary"><?php esc_html_e( '← Back', 'tcgiant-sync' ); ?></a>
			<?php else : ?>
				<span></span>
			<?php endif; ?>

			<?php if ( 1 === $current_step && $is_connected && 'active' === $license_status ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'step', 2 ) ); ?>" class="tc-button primary"><?php esc_html_e( 'Continue →', 'tcgiant-sync' ); ?></a>
			<?php elseif ( 3 === $current_step ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'step', 4 ) ); ?>" class="tc-button primary"><?php esc_html_e( 'Skip & Finish →', 'tcgiant-sync' ); ?></a>
			<?php elseif ( 4 === $current_step ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-sync' ) ); ?>" class="tc-button primary"><?php esc_html_e( 'Go to Dashboard →', 'tcgiant-sync' ); ?></a>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( 1 === $current_step ) : ?>
	<script>
	(function($){
		var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
		var nonce   = '<?php echo esc_js( wp_create_nonce( 'tcgiant_sync_ajax' ) ); ?>';
		$('#wizard-activate-license').on('click',function(){
			var btn = $(this), key = $('#wizard_license_key').val().trim();
			if (!key) { $('#wizard-license-status').text('Enter a license key.').css('color','#c00'); return; }
			btn.prop('disabled',true).text('Activating...');
			$.post(ajaxUrl, { action:'tcgiant_activate_license', license_key:key, _ajax_nonce:nonce }, function(r){
				btn.prop('disabled',false).text('Activate');
				if (r.success) {
					$('#wizard-license-status').text('✅ ' + r.data.message).css('color','#2a8a2a');
					setTimeout(function(){ location.reload(); }, 1000);
				} else {
					$('#wizard-license-status').text('❌ ' + (r.data ? r.data.message : 'Failed')).css('color','#c00');
				}
			});
		});
	})(jQuery);
	</script>
	<?php endif; ?>
</div>
