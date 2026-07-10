<?php
/**
 * Settings Page View
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$admin            = TCGiant_Sync_Admin::instance();
$is_authenticated = TCGiant_Sync_OAuth::instance()->is_authenticated();
$settings         = TCGiant_Sync_OAuth::instance()->get_settings();
$auth_url         = TCGiant_Sync_OAuth::instance()->get_authorization_url();
$license          = TCGiant_Sync_License::instance();
$license_ui       = $license->get_status_for_ui();

$preserve_keys = array( 'access_token', 'refresh_token', 'token_expiry', 'relay_secret', 'redirect_uri', 'app_id', 'cert_id', 'store_name', 'import_status', 'marketplace' );

// TCG-relevant eBay category map (ID => label).
$tcg_categories = TCGiant_Sync_Exporter::CATEGORIES;

$current_category = $settings['export_category_id'] ?? '';
// If the saved value isn't in our curated list, it's a custom ID.
$is_custom_cat = $current_category !== '' && ! array_key_exists( $current_category, $tcg_categories );
?>

<div class="wrap tc-dashboard-wrap">
	<div class="tc-header">
		<h1><?php esc_html_e( 'TCGiant Sync — Settings', 'tcgiant-sync' ); ?></h1>
		<p class="tc-subtitle"><?php esc_html_e( 'Manage your eBay connection, license, and sync configuration.', 'tcgiant-sync' ); ?></p>
	</div>

	<?php TCGiant_Sync_Admin::instance()->render_tabs( 'settings' ); ?>

	<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'tcgiant-sync' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['auth'] ) && 'success' === $_GET['auth'] ) : // phpcs:ignore ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Successfully authenticated with eBay.', 'tcgiant-sync' ); ?></p></div>
	<?php elseif ( isset( $_GET['auth'] ) && 'failed' === $_GET['auth'] ) : // phpcs:ignore ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'eBay authentication failed. Please try again.', 'tcgiant-sync' ); ?></p></div>
	<?php endif; ?>

	<!-- ─── TOP BAR: CONNECTION + LICENSE ─── -->
	<div class="tc-top-bar">

		<!-- eBay Connection -->
		<div class="tc-top-card">
			<div class="tc-top-card-left">
				<span class="dashicons dashicons-plugins-checked tc-top-card-icon"></span>
				<div class="tc-top-card-text">
					<h3><?php esc_html_e( 'eBay Connection', 'tcgiant-sync' ); ?></h3>
					<?php if ( $is_authenticated ) : ?>
						<p><span class="dashicons dashicons-yes-alt" style="color:var(--tc-success);font-size:13px;width:13px;height:13px;vertical-align:text-bottom;"></span>
						<?php echo esc_html( sprintf( __( 'Connected: %s', 'tcgiant-sync' ), $settings['store_name'] ?? 'eBay Store' ) ); ?></p>
					<?php else : ?>
						<p><span class="dashicons dashicons-warning" style="color:var(--tc-warning);font-size:13px;width:13px;height:13px;vertical-align:text-bottom;"></span>
						<?php esc_html_e( 'Not Connected', 'tcgiant-sync' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<div class="tc-top-card-right">
				<?php if ( $is_authenticated ) : ?>
					<a href="<?php echo esc_url( $auth_url ); ?>" class="tc-button secondary"><?php esc_html_e( 'Reconnect', 'tcgiant-sync' ); ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( $auth_url ); ?>" class="tc-button success"><?php esc_html_e( 'Connect to eBay', 'tcgiant-sync' ); ?></a>
				<?php endif; ?>
			</div>
		</div>

		<!-- License -->
		<div class="tc-top-card" id="tc-license-section">
			<div class="tc-top-card-left">
				<span class="dashicons dashicons-awards tc-top-card-icon"></span>
				<div class="tc-top-card-text">
					<h3><?php esc_html_e( 'License Status', 'tcgiant-sync' ); ?></h3>
					<?php if ( $license_ui['is_pro'] ) : ?>
						<p><span class="dashicons dashicons-yes-alt" style="color:var(--tc-success);font-size:13px;width:13px;height:13px;vertical-align:text-bottom;"></span>
						<?php esc_html_e( 'Pro License Active', 'tcgiant-sync' ); ?>
						<?php if ( 'lifetime' === $license_ui['variant'] ) : ?><span class="tc-lifetime-badge" style="margin-left:4px;"><?php esc_html_e( 'Lifetime', 'tcgiant-sync' ); ?></span><?php endif; ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Free Tier (Max 50 Products)', 'tcgiant-sync' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<div class="tc-top-card-right" style="position:relative;">
				<?php if ( $license_ui['is_pro'] ) : ?>
					<button type="button" class="tc-button secondary" id="tc-deactivate-license"><?php esc_html_e( 'Deactivate', 'tcgiant-sync' ); ?></button>
				<?php else : ?>
					<div style="display:flex;flex-direction:column;align-items:flex-end;">
						<div class="tc-license-row" style="margin:0;width:260px;">
							<input type="text" class="tc-input" id="tc-license-key" placeholder="<?php esc_attr_e( 'Enter license key...', 'tcgiant-sync' ); ?>" autocomplete="off" style="padding:7px 10px;">
							<button type="button" class="tc-button" id="tc-activate-license" style="padding:7px 14px;"><?php esc_html_e( 'Activate', 'tcgiant-sync' ); ?></button>
						</div>
						<div id="tc-license-message" class="tc-license-msg" style="display:none;position:absolute;top:40px;right:0;width:100%;box-sizing:border-box;z-index:10;"></div>
						<a href="<?php echo esc_url( $license_ui['upgrade_url'] ); ?>" target="_blank" rel="noopener" style="font-size:11px;margin-top:6px;color:var(--tc-primary);text-decoration:none;display:flex;align-items:center;gap:3px;font-weight:600;">
							<span class="dashicons dashicons-external" style="font-size:12px;width:12px;height:12px;"></span>
							<?php esc_html_e( 'Get TCGiant Sync Pro — $49/yr', 'tcgiant-sync' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- ─── SETTINGS FORM ─── -->
	<div class="tc-row-2col">
		<div class="tc-card">
			<h2><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Import Settings', 'tcgiant-sync' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'tcgiant_sync_ebay_group' ); ?>
				<?php
				foreach ( $preserve_keys as $key ) {
					if ( ! empty( $settings[ $key ] ) ) {
						echo '<input type="hidden" name="tcgiant_sync_ebay_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $settings[ $key ] ) . '">';
					}
				}
				// Preserve export settings too so they aren't wiped by this form.
				$export_preserve = array( 'export_category_id', 'export_condition_id', 'export_condition_type', 'export_grader_id', 'export_grade_value', 'export_cert_number', 'export_ungraded_condition', 'export_location', 'export_postal_code', 'export_fulfillment_policy', 'export_return_policy', 'export_payment_policy' );
				foreach ( $export_preserve as $key ) {
					if ( ! empty( $settings[ $key ] ) ) {
						echo '<input type="hidden" name="tcgiant_sync_ebay_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $settings[ $key ] ) . '">';
					}
				}
				?>

				<!-- General Settings -->
				<div class="tc-section" style="padding-top:0;border-top:none;margin-top:0;">
					<h3 class="tc-section-title"><?php esc_html_e( 'General', 'tcgiant-sync' ); ?></h3>
					<div class="tc-field">
						<label class="tc-label" for="ebay_marketplace"><?php esc_html_e( 'Primary eBay Marketplace', 'tcgiant-sync' ); ?></label>
						<select name="tcgiant_sync_ebay_settings[marketplace]" id="ebay_marketplace" class="tc-select">
							<?php
							$supported_marketplaces = TCGiant_Sync_API::MARKETPLACES;
							$current_marketplace = $settings['marketplace'] ?? 'EBAY_US';
							foreach ( $supported_marketplaces as $m_id => $m_data ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $m_id ), selected( $current_marketplace, $m_id, false ), esc_html( $m_data['label'] ) );
							}
							?>
						</select>
						<p class="tc-hint"><?php esc_html_e( 'Select the eBay regional site where your listings are managed.', 'tcgiant-sync' ); ?></p>
					</div>
				</div>

				<!-- Category Filter -->
				<div class="tc-section">
					<h3 class="tc-section-title"><?php esc_html_e( 'Import Filters', 'tcgiant-sync' ); ?></h3>
					<div class="tc-field">
						<label class="tc-label"><?php esc_html_e( 'eBay Custom Store Categories to Import', 'tcgiant-sync' ); ?></label>
						<div class="tc-category-selector" id="tc-category-selector">
							<div class="tc-category-tags" id="tc-category-tags"></div>
							<input type="hidden" name="tcgiant_sync_ebay_settings[category_ids]" id="tc-category-hidden" value="<?php echo esc_attr( $settings['category_ids'] ?? '' ); ?>">
							<div style="display:flex;gap:6px;">
								<select class="tc-select" id="tc-category-dropdown" style="display:none;"></select>
								<button type="button" class="tc-button secondary" id="tc-load-categories" style="white-space:nowrap;flex-shrink:0;font-size:12px;"><?php esc_html_e( 'Load Store Categories', 'tcgiant-sync' ); ?></button>
							</div>
							<p class="tc-hint"><?php esc_html_e( 'Load your custom eBay store categories, then select which ones to import. Leave empty to import all.', 'tcgiant-sync' ); ?></p>
						</div>
					</div>

					<div class="tc-field" style="margin-top:24px;">
						<label class="tc-label"><?php esc_html_e( 'eBay Standard Categories to Import', 'tcgiant-sync' ); ?></label>
						<?php
						$standard_cats = TCGiant_Sync_Exporter::CATEGORIES;
						$selected_standard_cats = isset( $settings['import_standard_category_ids'] ) && is_array( $settings['import_standard_category_ids'] ) ? $settings['import_standard_category_ids'] : array();
						?>
						<div class="tc-checkbox-group" style="max-height:160px;overflow-y:auto;border:1px solid var(--tc-border);padding:12px;border-radius:4px;background:#fff;">
							<?php foreach ( $standard_cats as $id => $label ) : 
								if ( '' === $id || 'custom' === $id ) continue;
							?>
								<label style="display:block;margin-bottom:6px;font-weight:normal;cursor:pointer;">
									<input type="checkbox" name="tcgiant_sync_ebay_settings[import_standard_category_ids][]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $selected_standard_cats ) ); ?>>
									<?php echo esc_html( $label . ' (' . $id . ')' ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="tc-hint" style="margin-top:8px;"><?php esc_html_e( 'Only import items that belong to these standard eBay categories. If neither Custom nor Standard categories are selected, ALL active listings will be imported.', 'tcgiant-sync' ); ?></p>
					</div>

					<div class="tc-field" style="margin-top:24px;">
						<label class="tc-label"><?php esc_html_e( 'WooCommerce Sync Categories', 'tcgiant-sync' ); ?></label>
						<?php
						$woo_cats          = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
						$selected_woo_cats = isset( $settings['woo_category_ids'] ) && is_array( $settings['woo_category_ids'] ) ? $settings['woo_category_ids'] : array();
						?>
						<div class="tc-checkbox-group" style="max-height:160px;overflow-y:auto;border:1px solid var(--tc-border);padding:12px;border-radius:4px;background:#fff;">
							<?php if ( ! empty( $woo_cats ) && ! is_wp_error( $woo_cats ) ) : ?>
								<?php foreach ( $woo_cats as $cat ) : ?>
									<label style="display:block;margin-bottom:6px;font-weight:normal;cursor:pointer;">
										<input type="checkbox" name="tcgiant_sync_ebay_settings[woo_category_ids][]" value="<?php echo esc_attr( $cat->term_id ); ?>" <?php checked( in_array( $cat->term_id, $selected_woo_cats ) ); ?>>
										<?php echo esc_html( $cat->name ); ?>
									</label>
								<?php endforeach; ?>
							<?php else : ?>
								<p style="margin:0;font-size:13px;color:#666;"><?php esc_html_e( 'No WooCommerce categories found.', 'tcgiant-sync' ); ?></p>
							<?php endif; ?>
						</div>
						<p class="tc-hint" style="margin-top:8px;"><?php esc_html_e( 'Select which WooCommerce categories trigger eBay stock updates when sold. Leave empty to sync all.', 'tcgiant-sync' ); ?></p>
					</div>

					<div class="tc-field" style="margin-top:24px;">
						<label class="tc-label"><?php esc_html_e( 'Import Item Specifics as Product Tags', 'tcgiant-sync' ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[import_specs_as_tags]" value="1" <?php checked( $settings['import_specs_as_tags'] ?? '0', '1' ); ?>> Yes</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[import_specs_as_tags]" value="0" <?php checked( $settings['import_specs_as_tags'] ?? '0', '0' ); ?>> No</label>
						</div>
						<p class="tc-hint"><?php esc_html_e( 'If enabled, all eBay Item Specifics will be added as WooCommerce Product Tags in addition to Product Attributes.', 'tcgiant-sync' ); ?></p>
					</div>

					<div class="tc-field" style="margin-top:24px;">
						<label class="tc-label"><?php esc_html_e( 'Add eBay Shipping Cost to Product Price', 'tcgiant-sync' ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[bake_shipping_into_price]" value="1" <?php checked( $settings['bake_shipping_into_price'] ?? '0', '1' ); ?>> Yes</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[bake_shipping_into_price]" value="0" <?php checked( $settings['bake_shipping_into_price'] ?? '0', '0' ); ?>> No</label>
						</div>
						<p class="tc-hint"><?php esc_html_e( 'If enabled, the eBay flat-rate shipping cost will be added to the product price on import. For example, a $10 item with $5 shipping becomes $15 in WooCommerce. Only applies to flat-rate shipping — calculated/free shipping items are unaffected.', 'tcgiant-sync' ); ?></p>
						<p class="tc-hint" style="margin-top:6px;"><strong><?php esc_html_e( '💡 Free Shipping Tip:', 'tcgiant-sync' ); ?></strong> <?php esc_html_e( 'Since shipping is baked into the price, you probably want to offer free shipping at checkout. Go to WooCommerce → Settings → Shipping → your zone → Add Shipping Method → "Free Shipping". Customers see one all-inclusive price with no separate shipping charge.', 'tcgiant-sync' ); ?></p>
					</div>

					<div class="tc-field" style="margin-top:24px;">
						<label class="tc-label" for="ebay_sku_maps_to"><?php esc_html_e( 'eBay SKU Maps To', 'tcgiant-sync' ); ?></label>
						<select name="tcgiant_sync_ebay_settings[sku_maps_to]" id="ebay_sku_maps_to" class="tc-select">
							<option value="sku" <?php selected( $settings['sku_maps_to'] ?? 'sku', 'sku' ); ?>><?php esc_html_e( 'Product SKU (Default)', 'tcgiant-sync' ); ?></option>
							<option value="bin_location" <?php selected( $settings['sku_maps_to'] ?? 'sku', 'bin_location' ); ?>><?php esc_html_e( 'Bin Location (Custom Field)', 'tcgiant-sync' ); ?></option>
							<option value="both" <?php selected( $settings['sku_maps_to'] ?? 'sku', 'both' ); ?>><?php esc_html_e( 'Both (Product SKU & Bin Location)', 'tcgiant-sync' ); ?></option>
						</select>
						<p class="tc-hint"><?php esc_html_e( 'If your eBay SKU field contains a warehouse/shelf location (e.g., "A3-B7") rather than a product identifier, select "Bin Location" to store it as a custom field instead. Select "Both" if you want it assigned to both the WooCommerce SKU and the Bin Location field.', 'tcgiant-sync' ); ?></p>
					</div>

					<div class="tc-field" style="margin-top:24px;">
						<label class="tc-label"><?php esc_html_e( 'Preserve Categories (Do Not Trash)', 'tcgiant-sync' ); ?></label>
						<?php
						$selected_preserve_cats = isset( $settings['preserve_woo_category_ids'] ) && is_array( $settings['preserve_woo_category_ids'] ) ? $settings['preserve_woo_category_ids'] : array();
						?>
						<div class="tc-checkbox-group" style="max-height:160px;overflow-y:auto;border:1px solid var(--tc-border);padding:12px;border-radius:4px;background:#fff;">
							<?php if ( ! empty( $woo_cats ) && ! is_wp_error( $woo_cats ) ) : ?>
								<?php foreach ( $woo_cats as $cat ) : ?>
									<label style="display:block;margin-bottom:6px;font-weight:normal;cursor:pointer;">
										<input type="checkbox" name="tcgiant_sync_ebay_settings[preserve_woo_category_ids][]" value="<?php echo esc_attr( $cat->term_id ); ?>" <?php checked( in_array( $cat->term_id, $selected_preserve_cats ) ); ?>>
										<?php echo esc_html( $cat->name ); ?>
									</label>
								<?php endforeach; ?>
							<?php else : ?>
								<p style="margin:0;font-size:13px;color:#666;"><?php esc_html_e( 'No WooCommerce categories found.', 'tcgiant-sync' ); ?></p>
							<?php endif; ?>
						</div>
						<p class="tc-hint" style="margin-top:8px;"><?php esc_html_e( 'Items in these categories will not be trashed when they end on eBay. Instead, their stock will be set to 0 and they will be unlinked from the old eBay listing.', 'tcgiant-sync' ); ?></p>
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

				<!-- Data Mapping -->
				<div class="tc-section">
					<h3 class="tc-section-title"><?php esc_html_e( 'Data Mapping (Re-imports)', 'tcgiant-sync' ); ?></h3>
					<p class="tc-hint" style="margin-bottom:12px;"><?php esc_html_e( 'When an existing product is re-synced, which platform wins? Stock is always updated from eBay.', 'tcgiant-sync' ); ?></p>
					<?php
					$mapping_fields = array(
						'overwrite_title'       => array( 'label' => __( 'Overwrite Title', 'tcgiant-sync' ), 'default' => '0' ),
						'overwrite_desc'        => array( 'label' => __( 'Overwrite Description', 'tcgiant-sync' ), 'default' => '0' ),
						'overwrite_price'       => array( 'label' => __( 'Overwrite Price', 'tcgiant-sync' ), 'default' => '1' ),
						'overwrite_images'      => array( 'label' => __( 'Overwrite Images', 'tcgiant-sync' ), 'default' => '0' ),
						'overwrite_taxonomy'    => array( 'label' => __( 'Overwrite Categories &amp; Tags', 'tcgiant-sync' ), 'default' => '0' ),
						'overwrite_weight_dims' => array( 'label' => __( 'Overwrite Weight &amp; Dimensions', 'tcgiant-sync' ), 'default' => '1' ),
					);
					foreach ( $mapping_fields as $field_key => $field ) :
					?>
					<div class="tc-field">
						<label class="tc-label"><?php echo esc_html( $field['label'] ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[<?php echo esc_attr( $field_key ); ?>]" value="1" <?php checked( $settings[ $field_key ] ?? $field['default'], '1' ); ?>> Yes</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[<?php echo esc_attr( $field_key ); ?>]" value="0" <?php checked( $settings[ $field_key ] ?? $field['default'], '0' ); ?>> No</label>
						</div>
					</div>
					<?php endforeach; ?>
				</div>

				<div class="tc-form-footer" style="padding-top:16px;margin-top:16px;border-top:1px solid var(--tc-border);">
					<button type="submit" class="tc-button full-width" style="font-size:14px;padding:12px 20px;"><?php esc_html_e( 'Save Import Settings', 'tcgiant-sync' ); ?></button>
				</div>
			</form>
		</div>

		<!-- Push to eBay Settings -->
		<div class="tc-card">
			<h2><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Push to eBay Settings', 'tcgiant-sync' ); ?></h2>

			<?php if ( ! $is_authenticated ) : ?>
				<p class="tc-hint" style="color:var(--tc-warning);"><?php esc_html_e( 'Connect to eBay above to configure export settings.', 'tcgiant-sync' ); ?></p>
			<?php else : ?>

			<?php
			$policies_configured = ! empty( $settings['export_fulfillment_policy'] )
			                    && ! empty( $settings['export_return_policy'] )
			                    && ! empty( $settings['export_payment_policy'] );
			$notice_border = $policies_configured ? '#2271b1' : '#d63638';
			$notice_bg     = $policies_configured ? '#f0f6fc'  : '#fcf0f1';
			$notice_color  = $policies_configured ? '#2271b1'  : '#d63638';
			?>
			<div style="border-left:4px solid <?php echo esc_attr( $notice_border ); ?>;background:<?php echo esc_attr( $notice_bg ); ?>;padding:10px 14px;border-radius:0 4px 4px 0;margin-bottom:16px;font-size:13px;line-height:1.5;">
				<p style="margin:0 0 4px;font-weight:600;color:<?php echo esc_attr( $notice_color ); ?>;">
					<?php if ( $policies_configured ) : ?>
						<?php esc_html_e( '✔ Business Policies are configured.', 'tcgiant-sync' ); ?>
					<?php else : ?>
						<?php esc_html_e( '⚠ Prerequisite: eBay Business Policies must be enabled on your account.', 'tcgiant-sync' ); ?>
					<?php endif; ?>
				</p>
				<p style="margin:0;color:#444;">
					<?php if ( $policies_configured ) : ?>
						<?php esc_html_e( 'Your shipping, return, and payment policies are saved. Re-fetch any time you update them on eBay.', 'tcgiant-sync' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'To push listings, your eBay seller account must be enrolled in Business Policies (Shipping, Returns & Payments). Most accounts already have this. If the Fetch button returns an error, check your account first.', 'tcgiant-sync' ); ?>
						<br>
						<a href="https://www.ebay.com/help/selling/listings/selling-policies/business-policies?id=4212" target="_blank" rel="noopener" style="color:#666;"><?php esc_html_e( 'What are Business Policies? ↗', 'tcgiant-sync' ); ?></a>
					<?php endif; ?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'tcgiant_sync_ebay_group' ); ?>
				<?php
				// Preserve all non-export fields.
				$all_preserve = array_merge( $preserve_keys, array( 'category_ids', 'woo_category_ids', 'preserve_woo_category_ids', 'import_specs_as_tags', 'bake_shipping_into_price', 'sku_maps_to', 'sync_interval', 'enable_order_sync', 'overwrite_title', 'overwrite_desc', 'overwrite_price', 'overwrite_images', 'overwrite_taxonomy', 'overwrite_weight_dims' ) );
				// Note: export condition descriptor fields are handled by the export form itself.
				foreach ( $all_preserve as $key ) {
					$val = $settings[ $key ] ?? null;
					if ( null !== $val ) {
						if ( is_array( $val ) ) {
							foreach ( $val as $v ) {
								echo '<input type="hidden" name="tcgiant_sync_ebay_settings[' . esc_attr( $key ) . '][]" value="' . esc_attr( $v ) . '">';
							}
						} else {
							echo '<input type="hidden" name="tcgiant_sync_ebay_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '">';
						}
					}
				}
				?>

				<!-- Default Category -->
				<div class="tc-field">
					<label class="tc-label" for="export_category_select"><?php esc_html_e( 'Default eBay Category', 'tcgiant-sync' ); ?></label>
					<select class="tc-select" id="export_category_select" style="margin-bottom:6px;">
						<?php foreach ( $tcg_categories as $cat_id => $cat_label ) : ?>
							<option value="<?php echo esc_attr( $cat_id ); ?>"
								<?php selected( $is_custom_cat ? 'custom' : $current_category, $cat_id ); ?>>
								<?php echo esc_html( $cat_id && $cat_id !== 'custom' ? $cat_label . ' (' . $cat_id . ')' : $cat_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<input type="text" class="tc-input" id="export_category_id_custom"
						name="tcgiant_sync_ebay_settings[export_category_id]"
						value="<?php echo esc_attr( $current_category ); ?>"
						placeholder="<?php esc_attr_e( 'Enter numeric eBay Category ID', 'tcgiant-sync' ); ?>"
						style="display:<?php echo ( ! $current_category || $is_custom_cat ) ? 'block' : 'none'; ?>;">
					<p class="tc-hint"><?php esc_html_e( 'Choose a common TCG category or select "Custom" to enter any eBay category ID. Per-product overrides available on the product edit screen.', 'tcgiant-sync' ); ?></p>
				</div>

				<!-- Condition Type -->
				<div class="tc-field">
					<label class="tc-label" for="export_condition_type"><?php esc_html_e( 'Condition Type', 'tcgiant-sync' ); ?></label>
					<select class="tc-select" id="export_condition_type" name="tcgiant_sync_ebay_settings[export_condition_type]">
						<option value="" <?php selected( $settings['export_condition_type'] ?? '', '' ); ?>><?php esc_html_e( 'None (Legacy — use for non-TCG/Coins categories)', 'tcgiant-sync' ); ?></option>
						<option value="graded" <?php selected( $settings['export_condition_type'] ?? '', 'graded' ); ?>><?php esc_html_e( 'Graded: Professionally graded', 'tcgiant-sync' ); ?></option>
						<option value="ungraded" <?php selected( $settings['export_condition_type'] ?? '', 'ungraded' ); ?>><?php esc_html_e( 'Ungraded: Not professionally graded', 'tcgiant-sync' ); ?></option>
					</select>
					<p class="tc-hint"><?php esc_html_e( 'Required for Trading Cards and Coins categories. Select "None" for other categories to use the legacy condition below.', 'tcgiant-sync' ); ?></p>
				</div>

				<!-- Graded Fields (shown when Condition Type = graded) -->
				<div id="tc-graded-fields" style="display:none; border-left:3px solid var(--tc-primary, #2563eb); padding-left:14px; margin-top:4px;">
					<div class="tc-field">
						<label class="tc-label" for="export_grader_id"><?php esc_html_e( 'Professional Grader', 'tcgiant-sync' ); ?></label>
						<select class="tc-select" id="export_grader_id" name="tcgiant_sync_ebay_settings[export_grader_id]">
							<option value=""><?php esc_html_e( '— Select grader —', 'tcgiant-sync' ); ?></option>
							<?php foreach ( TCGiant_Sync_Exporter::GRADERS as $gname => $gid ) : ?>
								<option value="<?php echo esc_attr( $gid ); ?>" <?php selected( $settings['export_grader_id'] ?? '', $gid ); ?>>
									<?php echo esc_html( $gname ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="tc-field">
						<label class="tc-label" for="export_grade_value"><?php esc_html_e( 'Grade', 'tcgiant-sync' ); ?></label>
						<select class="tc-select" id="export_grade_value" name="tcgiant_sync_ebay_settings[export_grade_value]">
							<option value=""><?php esc_html_e( '— Select grade —', 'tcgiant-sync' ); ?></option>
							<?php foreach ( TCGiant_Sync_Exporter::GRADES as $grade ) : ?>
								<option value="<?php echo esc_attr( $grade ); ?>" <?php selected( $settings['export_grade_value'] ?? '', $grade ); ?>>
									<?php echo esc_html( $grade ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="tc-field">
						<label class="tc-label" for="export_cert_number"><?php esc_html_e( 'Certification Number', 'tcgiant-sync' ); ?> <span style="color:#888; font-weight:normal;"><?php esc_html_e( '(optional)', 'tcgiant-sync' ); ?></span></label>
						<input type="text" class="tc-input" id="export_cert_number"
							name="tcgiant_sync_ebay_settings[export_cert_number]"
							value="<?php echo esc_attr( $settings['export_cert_number'] ?? '' ); ?>"
							placeholder="<?php esc_attr_e( 'e.g. 95422421', 'tcgiant-sync' ); ?>">
						<p class="tc-hint"><?php esc_html_e( 'The slab certification number. Can be overridden per-product.', 'tcgiant-sync' ); ?></p>
					</div>
				</div>

				<!-- Ungraded Fields (shown when Condition Type = ungraded) -->
				<div id="tc-ungraded-fields" style="display:none; border-left:3px solid var(--tc-warning, #f59e0b); padding-left:14px; margin-top:4px;">
					<div class="tc-field">
						<label class="tc-label" for="export_ungraded_condition"><?php esc_html_e( 'Card / Coin Condition', 'tcgiant-sync' ); ?></label>
						<select class="tc-select" id="export_ungraded_condition" name="tcgiant_sync_ebay_settings[export_ungraded_condition]">
							<option value=""><?php esc_html_e( '— Select condition —', 'tcgiant-sync' ); ?></option>
							<optgroup label="<?php esc_attr_e( 'Trading Cards', 'tcgiant-sync' ); ?>" id="tc-ungraded-tcg-group">
								<?php foreach ( TCGiant_Sync_Exporter::UNGRADED_TCG as $uid => $ulabel ) : ?>
									<option value="<?php echo esc_attr( $uid ); ?>" <?php selected( $settings['export_ungraded_condition'] ?? '', $uid ); ?>>
										<?php echo esc_html( $ulabel ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
							<optgroup label="<?php esc_attr_e( 'Coins', 'tcgiant-sync' ); ?>" id="tc-ungraded-coins-group">
								<?php foreach ( TCGiant_Sync_Exporter::UNGRADED_COINS as $uid => $ulabel ) : ?>
									<option value="<?php echo esc_attr( $uid ); ?>" <?php selected( $settings['export_ungraded_condition'] ?? '', $uid ); ?>>
										<?php echo esc_html( $ulabel ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
						</select>
						<p class="tc-hint"><?php esc_html_e( 'The available conditions depend on whether your category is Trading Cards or Coins.', 'tcgiant-sync' ); ?></p>
					</div>
				</div>

				<!-- Legacy Condition (shown when Condition Type = none) -->
				<div id="tc-legacy-condition" style="display:none;">
					<div class="tc-field">
						<label class="tc-label" for="export_condition_id"><?php esc_html_e( 'Legacy Condition', 'tcgiant-sync' ); ?></label>
						<select class="tc-select" id="export_condition_id" name="tcgiant_sync_ebay_settings[export_condition_id]">
							<?php foreach ( TCGiant_Sync_Exporter::CONDITIONS as $cid => $clabel ) : ?>
								<option value="<?php echo esc_attr( $cid ); ?>" <?php selected( $settings['export_condition_id'] ?? '1000', $cid ); ?>>
									<?php echo esc_html( $clabel ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="tc-hint"><?php esc_html_e( 'Used for categories that do not require ConditionDescriptors (non-TCG/Coins).', 'tcgiant-sync' ); ?></p>
					</div>
				</div>

				<!-- Item Location -->
				<div class="tc-field">
					<label class="tc-label" for="export_location"><?php esc_html_e( 'Item Location', 'tcgiant-sync' ); ?></label>
					<input type="text" class="tc-input" id="export_location"
						name="tcgiant_sync_ebay_settings[export_location]"
						value="<?php echo esc_attr( $settings['export_location'] ?? '' ); ?>"
						placeholder="<?php esc_attr_e( 'e.g. Chicago, IL', 'tcgiant-sync' ); ?>">
					<p class="tc-hint"><?php esc_html_e( 'City and state where items ship from. Required by eBay for all listings.', 'tcgiant-sync' ); ?></p>
				</div>

				<!-- Postal Code -->
				<div class="tc-field">
					<label class="tc-label" for="export_postal_code"><?php esc_html_e( 'Postal Code', 'tcgiant-sync' ); ?></label>
					<input type="text" class="tc-input" id="export_postal_code"
						name="tcgiant_sync_ebay_settings[export_postal_code]"
						value="<?php echo esc_attr( $settings['export_postal_code'] ?? '' ); ?>"
						placeholder="<?php esc_attr_e( 'e.g. 60601', 'tcgiant-sync' ); ?>">
					<p class="tc-hint"><?php esc_html_e( 'ZIP / postal code of your shipping origin. Required by eBay for all listings.', 'tcgiant-sync' ); ?></p>
				</div>

				<!-- Business Policies -->
				<div class="tc-field">
					<label class="tc-label"><?php esc_html_e( 'Business Policies', 'tcgiant-sync' ); ?></label>
					<button type="button" class="tc-button secondary" id="tc-fetch-policies" style="margin-bottom:10px;font-size:12px;">
						<span class="dashicons dashicons-update" style="font-size:14px;vertical-align:middle;"></span>
						<?php esc_html_e( 'Fetch / Refresh Policies from eBay', 'tcgiant-sync' ); ?>
					</button>
					<span id="tc-policy-status" style="font-size:12px;color:#555;margin-left:8px;"></span>

					<div style="display:flex;flex-direction:column;gap:8px;margin-top:4px;">
						<?php
						$policy_fields = array(
							'export_fulfillment_policy' => __( 'Shipping / Fulfillment Policy', 'tcgiant-sync' ),
							'export_return_policy'      => __( 'Return Policy', 'tcgiant-sync' ),
							'export_payment_policy'     => __( 'Payment Policy', 'tcgiant-sync' ),
						);
						$policy_ids = array( 'fulfillment', 'return', 'payment' );
						$i = 0;
						foreach ( $policy_fields as $field_key => $field_label ) :
						$pid = $policy_ids[ $i++ ];
						?>
						<div>
							<label style="font-size:12px;color:#555;display:block;margin-bottom:3px;"><?php echo esc_html( $field_label ); ?></label>
							<select class="tc-select" id="tc-policy-<?php echo esc_attr( $pid ); ?>"
								name="tcgiant_sync_ebay_settings[<?php echo esc_attr( $field_key ); ?>]"
								style="font-size:12px;">
								<option value=""><?php esc_html_e( '— Click Fetch to load —', 'tcgiant-sync' ); ?></option>
								<?php if ( ! empty( $settings[ $field_key ] ) ) : ?>
									<option value="<?php echo esc_attr( $settings[ $field_key ] ); ?>" selected>
										<?php echo esc_html( $settings[ $field_key ] ); ?>
									</option>
								<?php endif; ?>
							</select>
						</div>
						<?php endforeach; ?>
					</div>
					<p class="tc-hint" style="margin-top:8px;"><?php esc_html_e( 'Fixed Price listings · Good Till Cancelled · Regional marketplace based on settings.', 'tcgiant-sync' ); ?></p>
				</div>

				<div class="tc-form-footer" style="padding-top:16px;margin-top:16px;border-top:1px solid var(--tc-border);">
					<button type="submit" class="tc-button full-width" style="font-size:14px;padding:12px 20px;"><?php esc_html_e( 'Save Export Settings', 'tcgiant-sync' ); ?></button>
				</div>
			</form>
			<?php endif; ?>
		</div>
	</div>
</div>

<script>
(function($){
	// Category dropdown → show/hide custom text input.
	$('#export_category_select').on('change', function(){
		var val = $(this).val();
		var $input = $('#export_category_id_custom');
		if (val === 'custom' || val === '') {
			$input.show().val('').focus();
		} else {
			$input.val(val).hide();
		}
	});

	// Condition Type → show/hide graded/ungraded/legacy fields.
	function toggleConditionFields() {
		var type = $('#export_condition_type').val();
		$('#tc-graded-fields').toggle(type === 'graded');
		$('#tc-ungraded-fields').toggle(type === 'ungraded');
		$('#tc-legacy-condition').toggle(type === '' || !type);
	}
	$('#export_condition_type').on('change', toggleConditionFields);
	toggleConditionFields(); // Init on page load.
})(jQuery);
</script>
