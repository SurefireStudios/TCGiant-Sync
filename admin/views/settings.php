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
$tcg_categories = TCGiant_Sync_Catalog::get_categories();

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
	<?php if ( isset( $_GET['disconnected'] ) ) : // phpcs:ignore ?>
		<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'eBay account disconnected.', 'tcgiant-sync' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['auth'] ) && 'success' === $_GET['auth'] ) : // phpcs:ignore ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Successfully authenticated with eBay.', 'tcgiant-sync' ); ?></p></div>
	<?php elseif ( isset( $_GET['auth'] ) && 'failed' === $_GET['auth'] ) : // phpcs:ignore ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'eBay authentication failed. Please try again.', 'tcgiant-sync' ); ?></p></div>
	<?php elseif ( isset( $_GET['auth'] ) && 'invalid_state' === $_GET['auth'] ) : // phpcs:ignore ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'eBay connection rejected: no pending authorization request. Always start the connection using the "Connect to eBay" button below — never by following a link from elsewhere.', 'tcgiant-sync' ); ?></p></div>
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
			<?php
			// A connection counts as live only when both tokens are present, so a
			// handshake that got part way through leaves a site looking entirely
			// unconnected while still holding something. Hiding the clear-out
			// button in that state stranded the leftovers with no way to shift
			// them, which is exactly when someone is told to disconnect and
			// reconnect and finds there is nothing to press.
			$leftover_connection = $is_authenticated
				|| ! empty( $settings['access_token'] )
				|| ! empty( $settings['refresh_token'] )
				|| ! empty( $settings['store_name'] )
				|| (bool) get_transient( 'tcgiant_oauth_state' );

			$clear_label = $is_authenticated
				? __( 'Disconnect', 'tcgiant-sync' )
				: __( 'Reset connection', 'tcgiant-sync' );

			$clear_confirm = $is_authenticated
				? __( 'Disconnect eBay account? This will stop synchronization until reconnected.', 'tcgiant-sync' )
				: __( 'Clear the leftover connection details and start fresh? Nothing on eBay is changed.', 'tcgiant-sync' );
			?>
			<div class="tc-top-card-right" style="display:flex;gap:6px;">
				<?php if ( $is_authenticated ) : ?>
					<a href="<?php echo esc_url( $auth_url ); ?>" class="tc-button secondary"><?php esc_html_e( 'Reconnect', 'tcgiant-sync' ); ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( $auth_url ); ?>" class="tc-button success"><?php esc_html_e( 'Connect to eBay', 'tcgiant-sync' ); ?></a>
				<?php endif; ?>

				<button type="button" class="tc-button secondary" id="tc-test-connection"><?php esc_html_e( 'Test connection', 'tcgiant-sync' ); ?></button>

				<?php // Last, and only when there is something to clear: a button
				      // that wipes nothing is worse than no button at all. ?>
				<?php if ( $leftover_connection ) : ?>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tcgiant_sync_disconnect' ), 'tcgiant_sync_disconnect' ) ); ?>" class="tc-button danger" onclick="return confirm('<?php echo esc_js( $clear_confirm ); ?>');"><?php echo esc_html( $clear_label ); ?></a>
				<?php endif; ?>
			</div>
		</div>

		<?php // Where the test writes its answer. Connecting fails in places a
		      // merchant cannot see, and "it does not work" is not something a
		      // host can act on; this turns it into a sentence they can. ?>
		<div id="tc-connection-test" style="display:none;margin:-6px 0 14px;padding:12px 14px;border-radius:4px;border:1px solid #dbe0e7;background:#fff;">
			<p id="tc-connection-test-summary" style="margin:0 0 8px;font-weight:600;"></p>
			<div id="tc-connection-test-rows" style="display:flex;flex-direction:column;gap:6px;"></div>
		</div>

		<script>
		(function($){
			var btn = $('#tc-test-connection');
			if (!btn.length) { return; }

			var box     = $('#tc-connection-test');
			var summary = $('#tc-connection-test-summary');
			var rows    = $('#tc-connection-test-rows');
			var nonce   = '<?php echo esc_js( wp_create_nonce( 'tcgiant_sync_ajax' ) ); ?>';
			var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
			var idle    = btn.text();

			function select(node){
				var range = document.createRange();
				range.selectNodeContents(node);
				var sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange(range);
			}

			var colours = {
				ok:          '#2b6a52',
				intercepted: '#a2352a',
				unreachable: '#a2352a',
				unexpected:  '#8a6d1f'
			};

			btn.on('click', function(){
				btn.prop('disabled', true).text('<?php echo esc_js( __( 'Testing…', 'tcgiant-sync' ) ); ?>');
				rows.empty();
				summary.text('');
				box.show();

				$.post(ajaxUrl, { action: 'tcgiant_test_connection', _ajax_nonce: nonce })
					.done(function(r){
						if (!r || !r.success) {
							summary.css('color', '#a2352a').text(
								(r && r.data && r.data.message) ? r.data.message : '<?php echo esc_js( __( 'The test could not be run.', 'tcgiant-sync' ) ); ?>'
							);
							return;
						}
						summary.css('color', r.data.reachable ? '#2b6a52' : '#a2352a').text(r.data.summary);
						$.each(r.data.results, function(i, row){
							// .text() throughout: these carry whatever a strange
							// server chose to send back, and it is never markup
							// as far as this page is concerned.
							var line = $('<div/>').css({fontSize:'13px', lineHeight:'1.5'});
							$('<strong/>').css('color', colours[row.state] || '#59636f').text(row.label + ': ').appendTo(line);
							$('<span/>').text(row.detail).appendTo(line);
							line.appendTo(rows);

							// The whole reply, for anything that was not a clean
							// answer. The summary line above is trimmed to stay
							// readable, and the log keeps a single line, so
							// asking someone to "send us what you received" was
							// asking for something they had no way to get at.
							if (row.raw) {
								var box = $('<details/>').css({marginTop:'2px'});
								$('<summary/>')
									.css({cursor:'pointer', fontSize:'12px', color:'#59636f'})
									.text('<?php echo esc_js( __( 'Show everything that came back', 'tcgiant-sync' ) ); ?>')
									.appendTo(box);

								var pre = $('<pre/>').css({
									whiteSpace:'pre-wrap', wordBreak:'break-word', fontSize:'11px',
									lineHeight:'1.45', margin:'6px 0 0', padding:'8px',
									background:'#f6f7f9', border:'1px solid #dbe0e7',
									borderRadius:'2px', maxHeight:'260px', overflow:'auto'
								}).text(row.raw);

								$('<button type="button" class="button"/>')
									.css({fontSize:'11px', height:'auto', padding:'2px 8px', marginTop:'6px'})
									.text('<?php echo esc_js( __( 'Copy', 'tcgiant-sync' ) ); ?>')
									.on('click', function(){
										var btn = $(this);
										var done = function(){ btn.text('<?php echo esc_js( __( 'Copied', 'tcgiant-sync' ) ); ?>'); };
										if (navigator.clipboard && navigator.clipboard.writeText) {
											navigator.clipboard.writeText(row.raw).then(done, function(){ select(pre[0]); });
										} else {
											select(pre[0]);
										}
									})
									.appendTo(box);

								pre.appendTo(box);
								box.appendTo(rows);
							}
						});
					})
					.fail(function(){
						summary.css('color', '#a2352a').text('<?php echo esc_js( __( 'The test could not be run — the request to this site failed.', 'tcgiant-sync' ) ); ?>');
					})
					.always(function(){
						btn.prop('disabled', false).text(idle);
					});
			});
		})(jQuery);
		</script>

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
				$export_preserve = array( 'export_category_id', 'export_category_name', 'export_condition_id', 'export_condition_type', 'export_grader_id', 'export_grade_value', 'export_cert_number', 'export_ungraded_condition', 'export_location', 'export_postal_code', 'export_fulfillment_policy', 'export_return_policy', 'export_payment_policy', 'export_listing_type', 'export_listing_duration', 'export_default_quantity', 'export_send_package_details' );
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
						<p class="tc-hint"><?php esc_html_e( 'Select the eBay regional site where your listings are managed. This also sets the Country, Currency, and Site used when pushing listings.', 'tcgiant-sync' ); ?></p>
					</div>

					<div class="tc-field" style="margin-top:24px;">
						<label class="tc-label" for="custom_saved_categories"><?php esc_html_e( 'Saved Custom Categories', 'tcgiant-sync' ); ?></label>
						<textarea class="tc-input" id="custom_saved_categories" name="tcgiant_sync_ebay_settings[custom_saved_categories]" rows="4" placeholder="<?php esc_attr_e( "e.g.\n12345\n67890: Vintage Posters", 'tcgiant-sync' ); ?>"><?php echo esc_textarea( $settings['custom_saved_categories'] ?? '' ); ?></textarea>
						<p class="tc-hint">
							<?php esc_html_e( 'Enter custom eBay Category IDs to make them available in the category dropdowns. Enter one per line. Format: ID or ID: Label', 'tcgiant-sync' ); ?>
							<br><a href="https://www.isoldwhat.com/" target="_blank" rel="noopener"><?php esc_html_e( 'Find eBay Category IDs ↗', 'tcgiant-sync' ); ?></a>
						</p>
					</div>
				</div>

				<!-- Category Filter -->
				<div class="tc-section">
					<h3 class="tc-section-title"><?php esc_html_e( 'Import Filters', 'tcgiant-sync' ); ?></h3>

					<?php
					// Determine if "import all" mode is active (no filters selected).
					$has_custom_cats   = ! empty( $settings['category_ids'] );
					$has_standard_cats = ! empty( $settings['import_standard_category_ids'] ) && is_array( $settings['import_standard_category_ids'] ) && count( $settings['import_standard_category_ids'] ) > 0;
					$import_all        = ! $has_custom_cats && ! $has_standard_cats;
					?>

					<!-- Import All Toggle -->
					<div class="tc-field" style="margin-bottom:16px;">
						<label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;font-weight:600;">
							<span style="position:relative;display:inline-block;width:44px;height:24px;">
								<input type="checkbox" id="tc-import-all-toggle" <?php checked( $import_all ); ?> style="opacity:0;width:0;height:0;position:absolute;">
								<span class="tc-toggle-slider" style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:<?php echo $import_all ? '#16a34a' : '#ccc'; ?>;border-radius:24px;transition:.3s;">
									<span style="position:absolute;content:'';height:18px;width:18px;left:<?php echo $import_all ? '22px' : '3px'; ?>;bottom:3px;background:#fff;border-radius:50%;transition:.3s;box-shadow:0 1px 3px rgba(0,0,0,.2);"></span>
								</span>
							</span>
							<?php esc_html_e( 'Import All Listings', 'tcgiant-sync' ); ?>
						</label>
						<p class="tc-hint" style="margin-top:6px;"><?php esc_html_e( 'When enabled, every active eBay listing is imported regardless of category. Turn off to filter by specific Custom or Standard categories.', 'tcgiant-sync' ); ?></p>
					</div>

					<!-- Category filters (hidden when Import All is ON) -->
					<div id="tc-category-filters" style="<?php echo $import_all ? 'display:none;' : ''; ?>">
						<div class="tc-field">
							<label class="tc-label"><?php esc_html_e( 'eBay Custom Store Categories to Import', 'tcgiant-sync' ); ?></label>
							<div class="tc-category-selector" id="tc-category-selector">
								<div class="tc-category-tags" id="tc-category-tags"></div>
								<input type="hidden" name="tcgiant_sync_ebay_settings[category_ids]" id="tc-category-hidden" value="<?php echo esc_attr( $settings['category_ids'] ?? '' ); ?>">
								<div style="display:flex;gap:6px;">
									<select class="tc-select" id="tc-category-dropdown" style="display:none;"></select>
									<button type="button" class="tc-button secondary" id="tc-load-categories" style="white-space:nowrap;flex-shrink:0;font-size:12px;"><?php esc_html_e( 'Load Store Categories', 'tcgiant-sync' ); ?></button>
								</div>
								<p class="tc-hint"><?php esc_html_e( 'Load your custom eBay store categories, then select which ones to import.', 'tcgiant-sync' ); ?></p>
							</div>
						</div>

						<div class="tc-field" style="margin-top:24px;">
							<label class="tc-label"><?php esc_html_e( 'eBay Standard Categories to Import', 'tcgiant-sync' ); ?></label>
							<?php
							$standard_cats = TCGiant_Sync_Catalog::get_categories();
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
							<p class="tc-hint" style="margin-top:8px;"><?php esc_html_e( 'Only import items that belong to these standard eBay categories.', 'tcgiant-sync' ); ?></p>
						</div>
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

					<div class="tc-field">
						<label class="tc-label" for="ebay_sku_prefix"><?php esc_html_e( 'SKU Format (Fallback)', 'tcgiant-sync' ); ?></label>
						<select name="tcgiant_sync_ebay_settings[sku_prefix]" id="ebay_sku_prefix" class="tc-select">
							<option value="none" <?php selected( $settings['sku_prefix'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'Item Number Only (e.g., 185727729088)', 'tcgiant-sync' ); ?></option>
							<option value="ebay" <?php selected( $settings['sku_prefix'] ?? 'none', 'ebay' ); ?>><?php esc_html_e( 'EBAY- Prefix (e.g., EBAY-185727729088)', 'tcgiant-sync' ); ?></option>
						</select>
						<p class="tc-hint"><?php esc_html_e( 'When an eBay listing has no SKU, UPC, or EAN, the Item ID is used as the WooCommerce SKU. This controls whether to add the "EBAY-" prefix. Only affects new imports.', 'tcgiant-sync' ); ?></p>
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
						<label class="tc-label"><?php esc_html_e( 'Host listing images on eBay?', 'tcgiant-sync' ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[host_images_on_ebay]" value="1" <?php checked( $settings['host_images_on_ebay'] ?? '0', '1' ); ?>> Yes</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[host_images_on_ebay]" value="0" <?php checked( $settings['host_images_on_ebay'] ?? '0', '0' ); ?>> No</label>
						</div>
						<p class="description"><?php esc_html_e( 'By default, listings point at images on this site, which means eBay has to be able to reach it. Turn this on to upload each image to eBay Picture Services instead — necessary if your site is password-protected, behind bot protection, or may change domain. Uploads are cached, so repeat pushes do not re-upload.', 'tcgiant-sync' ); ?></p>
					</div>
					<div class="tc-field">
						<label class="tc-label" for="ebay_import_image_width"><?php esc_html_e( 'Imported Image Size', 'tcgiant-sync' ); ?></label>
						<select name="tcgiant_sync_ebay_settings[import_image_width]" id="ebay_import_image_width" class="tc-select">
							<option value="0" <?php selected( (string) ( $settings['import_image_width'] ?? '0' ), '0' ); ?>><?php esc_html_e( 'Original — full size from eBay (largest files)', 'tcgiant-sync' ); ?></option>
							<option value="1200" <?php selected( (string) ( $settings['import_image_width'] ?? '0' ), '1200' ); ?>><?php esc_html_e( 'Large — up to 1200px', 'tcgiant-sync' ); ?></option>
							<option value="800" <?php selected( (string) ( $settings['import_image_width'] ?? '0' ), '800' ); ?>><?php esc_html_e( 'Medium — up to 800px (recommended)', 'tcgiant-sync' ); ?></option>
							<option value="600" <?php selected( (string) ( $settings['import_image_width'] ?? '0' ), '600' ); ?>><?php esc_html_e( 'Small — up to 600px (smallest files)', 'tcgiant-sync' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'eBay can serve any of these sizes, so a smaller choice is downloaded instead of being resized afterwards — it saves download time as well as disk space, and every thumbnail WordPress generates from it shrinks too. 800px is ample for a product page on most themes. Roughly 100-300KB per image at Original, against 50-120KB at Medium. Only affects images downloaded from now on; existing images are left alone.', 'tcgiant-sync' ); ?></p>
					</div>
					<div class="tc-field">
						<label class="tc-label" for="ebay_log_level"><?php esc_html_e( 'Log Detail', 'tcgiant-sync' ); ?></label>
						<select name="tcgiant_sync_ebay_settings[log_level]" id="ebay_log_level" class="tc-select">
							<option value="info" <?php selected( $settings['log_level'] ?? 'info', 'info' ); ?>><?php esc_html_e( 'Everything (default)', 'tcgiant-sync' ); ?></option>
							<option value="warning" <?php selected( $settings['log_level'] ?? 'info', 'warning' ); ?>><?php esc_html_e( 'Warnings and errors only', 'tcgiant-sync' ); ?></option>
							<option value="error" <?php selected( $settings['log_level'] ?? 'info', 'error' ); ?>><?php esc_html_e( 'Errors only', 'tcgiant-sync' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Every imported product writes a log line. On stores with thousands of listings, switching to "Warnings and errors only" noticeably reduces disk activity during a sync.', 'tcgiant-sync' ); ?></p>
					</div>
					<div class="tc-field">
						<label class="tc-label"><?php esc_html_e( 'Reduce WooCommerce stock on eBay sale?', 'tcgiant-sync' ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[enable_order_sync]" value="1" <?php checked( $settings['enable_order_sync'] ?? '0', '1' ); ?>> Yes</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[enable_order_sync]" value="0" <?php checked( $settings['enable_order_sync'] ?? '0', '0' ); ?>> No</label>
						</div>
						<p class="tc-hint"><?php esc_html_e( 'Only applies to products that are not linked to an eBay listing. Linked products always take their quantity from eBay directly.', 'tcgiant-sync' ); ?></p>
					</div>
					<div class="tc-field">
						<label class="tc-label"><?php esc_html_e( 'Check stock against eBay weekly?', 'tcgiant-sync' ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[enable_reconciliation]" value="1" <?php checked( $settings['enable_reconciliation'] ?? '1', '1' ); ?>> Yes</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[enable_reconciliation]" value="0" <?php checked( $settings['enable_reconciliation'] ?? '1', '0' ); ?>> No</label>
						</div>
						<p class="tc-hint"><?php esc_html_e( 'Compares your stock against eBay once a week and writes anything that disagrees to the log, including products whose listing has ended but which are still showing stock. It only reports; nothing is changed without you asking. Leave this on unless the weekly run is a problem for your host.', 'tcgiant-sync' ); ?></p>
					</div>
					<div class="tc-field">
						<label class="tc-label"><?php esc_html_e( 'Import eBay orders into WooCommerce?', 'tcgiant-sync' ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[enable_order_import]" value="1" <?php checked( $settings['enable_order_import'] ?? '0', '1' ); ?>> Yes</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[enable_order_import]" value="0" <?php checked( $settings['enable_order_import'] ?? '0', '0' ); ?>> No</label>
						</div>
						<p class="tc-hint"><?php esc_html_e( 'When enabled, eBay orders are imported as WooCommerce orders every 15 minutes. Tracking numbers are pushed back to eBay when orders are marked "Completed".', 'tcgiant-sync' ); ?></p>
					</div>
					<div class="tc-field">
						<label class="tc-label"><?php esc_html_e( 'Auto-end listing when stock reaches 0?', 'tcgiant-sync' ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[auto_end_zero_stock]" value="1" <?php checked( $settings['auto_end_zero_stock'] ?? '1', '1' ); ?>> Yes</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[auto_end_zero_stock]" value="0" <?php checked( $settings['auto_end_zero_stock'] ?? '1', '0' ); ?>> No</label>
						</div>
						<p class="tc-hint"><?php esc_html_e( 'Automatically ends the eBay listing when WooCommerce stock hits 0 (eBay rejects quantity=0 via API).', 'tcgiant-sync' ); ?></p>
					</div>
					<div class="tc-field">
						<label class="tc-label"><?php esc_html_e( 'Disable background image localization?', 'tcgiant-sync' ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[disable_image_localization]" value="1" <?php checked( $settings['disable_image_localization'] ?? '0', '1' ); ?>> Yes (keep eBay-hosted URLs)</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[disable_image_localization]" value="0" <?php checked( $settings['disable_image_localization'] ?? '0', '0' ); ?>> No (download to Media Library)</label>
						</div>
						<p class="tc-hint"><?php esc_html_e( 'When enabled, product images remain as eBay-hosted URLs and are never downloaded. This saves disk space and speeds up sync dramatically.', 'tcgiant-sync' ); ?></p>
					</div>
					<div class="tc-field">
						<label class="tc-label"><?php esc_html_e( 'Remove products when their eBay listing sells out?', 'tcgiant-sync' ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[remove_sold_products]" value="1" <?php checked( $settings['remove_sold_products'] ?? '1', '1' ); ?>> Yes</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[remove_sold_products]" value="0" <?php checked( $settings['remove_sold_products'] ?? '1', '0' ); ?>> No</label>
						</div>
						<p class="tc-hint"><?php esc_html_e( 'Moves the product to the trash once its listing has sold out, so sold items leave your shop on their own. Only applies to listings that actually sold: one that ran out or that you ended yourself keeps its product and whatever stock was unsold. Products you pushed to eBay yourself are never removed, and neither is anything in your Preserve Categories.', 'tcgiant-sync' ); ?></p>
					</div>
					<div class="tc-field">
						<label class="tc-label"><?php esc_html_e( 'Auto-relist ended listings?', 'tcgiant-sync' ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[auto_relist_enabled]" value="1" <?php checked( $settings['auto_relist_enabled'] ?? '0', '1' ); ?>> Yes</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[auto_relist_enabled]" value="0" <?php checked( $settings['auto_relist_enabled'] ?? '0', '0' ); ?>> No</label>
						</div>
						<p class="tc-hint"><?php esc_html_e( 'Automatically relist ended eBay listings during daily maintenance (3 AM) if WooCommerce stock is still > 0. Max 50 per day.', 'tcgiant-sync' ); ?></p>
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

				<!-- On Uninstall -->
				<div class="tc-section">
					<h3 class="tc-section-title"><?php esc_html_e( 'When This Plugin Is Deleted', 'tcgiant-sync' ); ?></h3>
					<div class="tc-field">
						<label class="tc-label"><?php esc_html_e( 'Also delete the settings and eBay links?', 'tcgiant-sync' ); ?></label>
						<div class="tc-radio-group">
							<label><input type="radio" name="tcgiant_sync_ebay_settings[delete_data_on_uninstall]" value="1" <?php checked( $settings['delete_data_on_uninstall'] ?? '0', '1' ); ?>> Yes</label>
							<label><input type="radio" name="tcgiant_sync_ebay_settings[delete_data_on_uninstall]" value="0" <?php checked( $settings['delete_data_on_uninstall'] ?? '0', '0' ); ?>> No</label>
						</div>
						<p class="tc-hint"><?php esc_html_e( 'Your products and orders are never deleted either way. This covers only the records this plugin keeps: your settings, the eBay connection, and the links saying which product came from which listing. Leave this on No if you might reinstall or move to a different version, because those links cannot be rebuilt and a later sync would not recognise products it had already imported.', 'tcgiant-sync' ); ?></p>
					</div>
				</div>

				<div class="tc-form-footer" style="padding-top:16px;margin-top:16px;border-top:1px solid var(--tc-border);">
					<button type="submit" class="tc-button full-width" style="font-size:14px;padding:12px 20px;"><?php esc_html_e( 'Save Import Settings', 'tcgiant-sync' ); ?></button>
				</div>
			</form>
		</div><!-- /.tc-card — Import Settings -->

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
				$all_preserve = array_merge( $preserve_keys, array( 'custom_saved_categories', 'category_ids', 'woo_category_ids', 'preserve_woo_category_ids', 'import_specs_as_tags', 'bake_shipping_into_price', 'sku_maps_to', 'sku_prefix', 'sync_interval', 'enable_order_sync', 'enable_order_import', 'enable_reconciliation', 'remove_sold_products', 'auto_end_zero_stock', 'disable_image_localization', 'auto_relist_enabled', 'overwrite_title', 'overwrite_desc', 'overwrite_price', 'overwrite_images', 'overwrite_taxonomy', 'overwrite_weight_dims', 'delete_data_on_uninstall' ) );
				// Note: export condition descriptor fields are handled by the export form itself.
				foreach ( $all_preserve as $key ) {
					$val = $settings[ $key ] ?? null;
					if ( null !== $val ) {
						if ( is_array( $val ) ) {
							foreach ( $val as $v ) {
								echo '<input type="hidden" name="tcgiant_sync_ebay_settings[' . esc_attr( $key ) . '][]" value="' . esc_attr( $v ) . '">';
							}
						} elseif ( 'custom_saved_categories' === $key ) {
							echo '<textarea name="tcgiant_sync_ebay_settings[' . esc_attr( $key ) . ']" style="display:none;">' . esc_textarea( $val ) . '</textarea>';
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
					<input type="hidden" id="export_category_name" name="tcgiant_sync_ebay_settings[export_category_name]" value="<?php echo esc_attr( $settings['export_category_name'] ?? '' ); ?>">
					<?php
					$saved_cat_name = $settings['export_category_name'] ?? '';
					$show_selected  = $current_category && $saved_cat_name;
					?>
					<div id="tc-selected-category-label" style="margin-top:6px; font-size:12px; color:#16a34a; font-weight:500; <?php echo $show_selected ? '' : 'display:none;'; ?>">
						<?php if ( $show_selected ) : ?>
							✔ <?php echo esc_html( $saved_cat_name ); ?> (<?php echo esc_html( $current_category ); ?>)
						<?php endif; ?>
					</div>
					<button type="button" class="tc-button secondary" id="tc-browse-categories-btn" style="margin-top:6px;font-size:12px;">
						<span class="dashicons dashicons-category" style="font-size:14px;vertical-align:middle;margin-right:2px;"></span>
						<?php esc_html_e( 'Browse eBay Categories', 'tcgiant-sync' ); ?>
					</button>
					<div id="tc-category-browser" style="display:none; margin-top:8px; border:1px solid var(--tc-border); border-radius:6px; padding:12px; background:#f9fafb;">
						<div id="tc-category-breadcrumb" style="font-size:12px; color:#666; margin-bottom:8px;"></div>
						<div id="tc-category-drilldown" style="max-height:250px; overflow-y:auto;"></div>
						<div id="tc-category-browser-status" style="font-size:12px; color:#888; margin-top:6px;"></div>
					</div>
					<p class="tc-hint"><?php esc_html_e( 'Choose a common category, browse the full eBay tree, or select "Custom" to enter any category ID. Per-product overrides available on the product edit screen.', 'tcgiant-sync' ); ?></p>
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
							<?php 
							$all_graders = array_merge( TCGiant_Sync_Catalog::GRADERS_TCG, TCGiant_Sync_Catalog::GRADERS_COINS );
							foreach ( $all_graders as $gname => $gid ) : ?>
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
							<?php
							$all_grades = array_merge( TCGiant_Sync_Catalog::GRADES_TCG, TCGiant_Sync_Catalog::GRADES_COINS );
							foreach ( array_unique( $all_grades ) as $grade ) : ?>
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
								<?php foreach ( TCGiant_Sync_Catalog::UNGRADED_TCG as $uid => $ulabel ) : ?>
									<option value="<?php echo esc_attr( $uid ); ?>" <?php selected( $settings['export_ungraded_condition'] ?? '', $uid ); ?>>
										<?php echo esc_html( $ulabel ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
							<optgroup label="<?php esc_attr_e( 'Coins', 'tcgiant-sync' ); ?>" id="tc-ungraded-coins-group">
								<?php foreach ( TCGiant_Sync_Catalog::UNGRADED_COINS as $uid => $ulabel ) : ?>
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
							<?php
							// Grouped, so the restricted grades cannot be picked by mistake.
							//
							// eBay refuses the media grades outside books, film, music and games,
							// and two of them read as ordinary words - "Very Good", "Good" - that a
							// shop selling anything else would reach for first.
							$tc_cond_saved  = $settings['export_condition_id'] ?? '1000';
							$tc_cond_groups = array(
								__( 'Any category', 'tcgiant-sync' )                       => array(),
								__( 'Books, film, music and games only', 'tcgiant-sync' ) => array(),
							);
							$tc_cond_names  = array_keys( $tc_cond_groups );

							foreach ( TCGiant_Sync_Catalog::CONDITIONS as $cid => $clabel ) {
								$tc_media = in_array( (string) $cid, TCGiant_Sync_Catalog::CONDITIONS_MEDIA_ONLY, true );
								$tc_cond_groups[ $tc_cond_names[ $tc_media ? 1 : 0 ] ][ $cid ] = $clabel;
							}
							?>
							<?php foreach ( $tc_cond_groups as $tc_group => $tc_items ) : ?>
								<?php if ( ! empty( $tc_items ) ) : ?>
									<optgroup label="<?php echo esc_attr( $tc_group ); ?>">
										<?php foreach ( $tc_items as $cid => $clabel ) : ?>
											<option value="<?php echo esc_attr( $cid ); ?>" <?php selected( $tc_cond_saved, $cid ); ?>>
												<?php echo esc_html( $clabel ); ?>
											</option>
										<?php endforeach; ?>
									</optgroup>
								<?php endif; ?>
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
						placeholder="<?php esc_attr_e( 'e.g. Chicago, IL or London, UK', 'tcgiant-sync' ); ?>">
					<p class="tc-hint"><?php esc_html_e( 'City and state where items ship from. Required by eBay for all listings.', 'tcgiant-sync' ); ?></p>
				</div>

				<!-- Postal Code -->
				<div class="tc-field">
					<label class="tc-label" for="export_postal_code"><?php esc_html_e( 'Postal Code', 'tcgiant-sync' ); ?></label>
					<input type="text" class="tc-input" id="export_postal_code"
						name="tcgiant_sync_ebay_settings[export_postal_code]"
						value="<?php echo esc_attr( $settings['export_postal_code'] ?? '' ); ?>"
						placeholder="<?php esc_attr_e( 'e.g. 60601 or SW1A 1AA', 'tcgiant-sync' ); ?>">
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
					<p class="tc-hint" style="margin-top:8px;"><?php esc_html_e( 'Select your eBay Business Policies. Per-product overrides available in the product editor.', 'tcgiant-sync' ); ?></p>
				</div>

				<!-- Listing Type & Duration -->
				<div class="tc-field">
					<label class="tc-label"><?php esc_html_e( 'Default Listing Type & Duration', 'tcgiant-sync' ); ?></label>
					<div style="display:flex;gap:12px;flex-wrap:wrap;">
						<div style="flex:1;min-width:180px;">
							<label style="font-size:12px;color:#555;display:block;margin-bottom:3px;"><?php esc_html_e( 'Listing Type', 'tcgiant-sync' ); ?></label>
							<select class="tc-select" name="tcgiant_sync_ebay_settings[export_listing_type]" id="export_listing_type">
								<?php foreach ( TCGiant_Sync_Catalog::LISTING_TYPES as $lt_val => $lt_label ) : ?>
									<option value="<?php echo esc_attr( $lt_val ); ?>" <?php selected( $settings['export_listing_type'] ?? 'FixedPriceItem', $lt_val ); ?>>
										<?php echo esc_html( $lt_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div style="flex:1;min-width:180px;">
							<label style="font-size:12px;color:#555;display:block;margin-bottom:3px;"><?php esc_html_e( 'Listing Duration', 'tcgiant-sync' ); ?></label>
							<select class="tc-select" name="tcgiant_sync_ebay_settings[export_listing_duration]" id="export_listing_duration">
								<?php foreach ( TCGiant_Sync_Catalog::LISTING_DURATIONS as $ld_val => $ld_label ) : ?>
									<option value="<?php echo esc_attr( $ld_val ); ?>" <?php selected( $settings['export_listing_duration'] ?? 'GTC', $ld_val ); ?>>
										<?php echo esc_html( $ld_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<p class="tc-hint"><?php esc_html_e( 'Default for all listings. Can be overridden per-product. Fixed Price supports GTC & 30 Days. Auctions support 1-10 Days.', 'tcgiant-sync' ); ?></p>
				</div>

				<!-- Quantity when stock is not tracked -->
				<div class="tc-field">
					<label class="tc-label" for="export_default_quantity"><?php esc_html_e( 'Quantity When Stock Is Not Tracked', 'tcgiant-sync' ); ?></label>
					<input type="number" min="1" step="1" class="tc-input" id="export_default_quantity"
						name="tcgiant_sync_ebay_settings[export_default_quantity]"
						value="<?php echo esc_attr( $settings['export_default_quantity'] ?? '1' ); ?>" style="max-width:120px;">
					<p class="tc-hint"><?php esc_html_e( 'Only used for products with WooCommerce stock management turned off, where there is no quantity to read. Products that do track stock always list their real quantity. Leave at 1 if you sell single items.', 'tcgiant-sync' ); ?></p>
				</div>

				<!-- Package weight and size -->
				<div class="tc-field">
					<label class="tc-label"><?php esc_html_e( 'Send Package Weight and Size to eBay?', 'tcgiant-sync' ); ?></label>
					<div class="tc-radio-group">
						<label><input type="radio" name="tcgiant_sync_ebay_settings[export_send_package_details]" value="1" <?php checked( $settings['export_send_package_details'] ?? '0', '1' ); ?>> Yes</label>
						<label><input type="radio" name="tcgiant_sync_ebay_settings[export_send_package_details]" value="0" <?php checked( $settings['export_send_package_details'] ?? '0', '0' ); ?>> No</label>
					</div>
					<p class="tc-hint"><?php esc_html_e( 'Sends each product\'s WooCommerce weight and dimensions with the listing. Needed if your eBay shipping policy calculates postage by weight; not needed for flat-rate postage. Off by default so existing listings are not changed until you choose. For a variable product the heaviest variation is used.', 'tcgiant-sync' ); ?></p>
				</div>

				<!-- Best Offer -->
				<div class="tc-field">
					<label class="tc-label"><?php esc_html_e( 'Best Offer', 'tcgiant-sync' ); ?></label>
					<div class="tc-radio-group">
						<label><input type="radio" name="tcgiant_sync_ebay_settings[best_offer_enabled]" value="1" <?php checked( $settings['best_offer_enabled'] ?? '0', '1' ); ?>> <?php esc_html_e( 'Enable Best Offer on new listings', 'tcgiant-sync' ); ?></label>
						<label><input type="radio" name="tcgiant_sync_ebay_settings[best_offer_enabled]" value="0" <?php checked( $settings['best_offer_enabled'] ?? '0', '0' ); ?>> <?php esc_html_e( 'Disabled', 'tcgiant-sync' ); ?></label>
					</div>
					<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;">
						<div style="flex:1;min-width:160px;">
							<label style="font-size:12px;color:#555;display:block;margin-bottom:3px;"><?php esc_html_e( 'Auto-Accept at (% or $)', 'tcgiant-sync' ); ?></label>
							<input type="text" class="tc-input" name="tcgiant_sync_ebay_settings[best_offer_auto_accept]" value="<?php echo esc_attr( $settings['best_offer_auto_accept'] ?? '' ); ?>" placeholder="e.g. 0.95 or 49.99" style="width:100%;" />
						</div>
						<div style="flex:1;min-width:160px;">
							<label style="font-size:12px;color:#555;display:block;margin-bottom:3px;"><?php esc_html_e( 'Auto-Decline below (% or $)', 'tcgiant-sync' ); ?></label>
							<input type="text" class="tc-input" name="tcgiant_sync_ebay_settings[best_offer_auto_decline]" value="<?php echo esc_attr( $settings['best_offer_auto_decline'] ?? '' ); ?>" placeholder="e.g. 0.70 or 25.00" style="width:100%;" />
						</div>
					</div>
					<p class="tc-hint"><?php esc_html_e( 'Use a decimal (0.95) for percentage of price, or a dollar amount (49.99) for a fixed threshold. Per-product overrides available.', 'tcgiant-sync' ); ?></p>
				</div>

				<!-- GPSR (EU Product Safety) -->
				<div class="tc-field">
					<label class="tc-label"><?php esc_html_e( 'GPSR Compliance (EU Product Safety)', 'tcgiant-sync' ); ?></label>
					<div class="tc-radio-group">
						<label><input type="radio" name="tcgiant_sync_ebay_settings[gpsr_enabled]" value="1" <?php checked( $settings['gpsr_enabled'] ?? '0', '1' ); ?>> <?php esc_html_e( 'Include GPSR fields on listings', 'tcgiant-sync' ); ?></label>
						<label><input type="radio" name="tcgiant_sync_ebay_settings[gpsr_enabled]" value="0" <?php checked( $settings['gpsr_enabled'] ?? '0', '0' ); ?>> <?php esc_html_e( 'Disabled', 'tcgiant-sync' ); ?></label>
					</div>
					<div style="margin-top:8px;">
						<label style="font-size:12px;color:#555;display:block;margin-bottom:3px;"><?php esc_html_e( 'Manufacturer Name', 'tcgiant-sync' ); ?></label>
						<input type="text" class="tc-input" name="tcgiant_sync_ebay_settings[gpsr_manufacturer_name]" value="<?php echo esc_attr( $settings['gpsr_manufacturer_name'] ?? '' ); ?>" style="width:100%;margin-bottom:8px;" />
						<label style="font-size:12px;color:#555;display:block;margin-bottom:3px;"><?php esc_html_e( 'Manufacturer Address', 'tcgiant-sync' ); ?></label>
						<input type="text" class="tc-input" name="tcgiant_sync_ebay_settings[gpsr_manufacturer_address]" value="<?php echo esc_attr( $settings['gpsr_manufacturer_address'] ?? '' ); ?>" style="width:100%;margin-bottom:8px;" />
						<label style="font-size:12px;color:#555;display:block;margin-bottom:3px;"><?php esc_html_e( 'EU Responsible Person (if different)', 'tcgiant-sync' ); ?></label>
						<input type="text" class="tc-input" name="tcgiant_sync_ebay_settings[gpsr_eu_responsible]" value="<?php echo esc_attr( $settings['gpsr_eu_responsible'] ?? '' ); ?>" style="width:100%;" />
					</div>
					<p class="tc-hint"><?php esc_html_e( 'Required for EU sellers. Includes manufacturer info in eBay listing ProductSafety XML block.', 'tcgiant-sync' ); ?></p>
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

	// ─── Category Browser (Drill-Down) ───
	function initCategoryBrowser(opts) {
		var $btn        = $(opts.btn);
		var $browser    = $(opts.browser);
		var $breadcrumb = $(opts.breadcrumb);
		var $drilldown  = $(opts.drilldown);
		var $status     = $(opts.status);
		var onSelect    = opts.onSelect;
		var trail       = []; // [{id, name}]

		$btn.on('click', function() {
			$browser.toggle();
			if ($browser.is(':visible') && $drilldown.children().length === 0) {
				loadCategories('');
			}
		});

		function loadCategories(parentId) {
			$drilldown.html('<div style="text-align:center;padding:16px;color:#888;">Loading…</div>');
			$status.text('');
			$.post(tcgiantSync.ajaxUrl, {
				action: 'tcgiant_browse_categories',
				_ajax_nonce: tcgiantSync.nonce,
				parent_id: parentId
			}, function(res) {
				if (!res.success) {
					$drilldown.html('<div style="color:#cc1818;padding:8px;">'+res.data.message+'</div>');
					return;
				}
				renderCategories(res.data.categories);
			}).fail(function() {
				$drilldown.html('<div style="color:#cc1818;padding:8px;">Request failed. Please try again.</div>');
			});
		}

		function renderCategories(cats) {
			$drilldown.empty();
			if (!cats || cats.length === 0) {
				$drilldown.html('<div style="padding:8px;color:#888;">No subcategories found.</div>');
				return;
			}
			var $list = $('<div></div>');
			$.each(cats, function(i, cat) {
				var $item = $('<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 8px;border-bottom:1px solid #eee;cursor:pointer;font-size:13px;transition:background 0.15s;"></div>');
				var $label = $('<span style="flex:1;"></span>').text(cat.name);
				$item.append($label);
				if (!cat.leaf) {
					$item.append('<span class="dashicons dashicons-arrow-right-alt2" style="color:#888;font-size:14px;width:14px;height:14px;"></span>');
				} else {
					$item.append('<span style="color:#888;font-size:11px;">leaf</span>');
				}
				$item.on('mouseenter', function() { $(this).css('background', '#f0f6fc'); });
				$item.on('mouseleave', function() { $(this).css('background', ''); });
				$item.on('click', function() {
					if (cat.leaf) {
						selectCategory(cat);
					} else {
						trail.push({id: cat.id, name: cat.name});
						renderBreadcrumb();
						loadCategories(cat.id);
						$status.html('Click a category to drill deeper, or <a href="#" class="tc-cat-use-this" data-id="' + cat.id + '">use <strong>' + cat.name + ' (' + cat.id + ')</strong></a>');
					}
				});
				$list.append($item);
			});
			$drilldown.append($list);
		}

		function renderBreadcrumb() {
			$breadcrumb.empty();
			var $root = $('<a href="#" style="color:#2271b1;text-decoration:none;">All Categories</a>');
			$root.on('click', function(e) {
				e.preventDefault();
				trail = [];
				renderBreadcrumb();
				loadCategories('');
				$status.text('');
			});
			$breadcrumb.append($root);
			$.each(trail, function(i, crumb) {
				$breadcrumb.append('<span style="margin:0 4px;color:#aaa;"> › </span>');
				if (i < trail.length - 1) {
					var $link = $('<a href="#" style="color:#2271b1;text-decoration:none;"></a>').text(crumb.name);
					(function(idx) {
						$link.on('click', function(e) {
							e.preventDefault();
							trail = trail.slice(0, idx + 1);
							renderBreadcrumb();
							loadCategories(crumb.id);
							$status.text('');
						});
					})(i);
					$breadcrumb.append($link);
				} else {
					$breadcrumb.append($('<strong></strong>').text(crumb.name));
				}
			});
		}

		function selectCategory(cat) {
			onSelect(cat.id, cat.name);
			$status.html('✔ Selected: <strong>' + cat.name + '</strong> (' + cat.id + ')');
			$browser.slideUp(200);
			trail = [];
		}

		// Delegate click on "use this" link in status area.
		$status.on('click', '.tc-cat-use-this', function(e) {
			e.preventDefault();
			var id = $(this).data('id');
			var lastCrumb = trail[trail.length - 1];
			onSelect(id, lastCrumb ? lastCrumb.name : id);
			$status.html('✔ Selected: <strong>' + (lastCrumb ? lastCrumb.name : id) + '</strong> (' + id + ')');
			$browser.slideUp(200);
			trail = [];
		});
	}

	// Init for Settings page
	if ($('#tc-browse-categories-btn').length) {
		initCategoryBrowser({
			btn: '#tc-browse-categories-btn',
			browser: '#tc-category-browser',
			breadcrumb: '#tc-category-breadcrumb',
			drilldown: '#tc-category-drilldown',
			status: '#tc-category-browser-status',
			onSelect: function(id, name) {
				$('#export_category_select').val('custom');
				$('#export_category_id_custom').val(id).show();
				$('#export_category_name').val(name);
				$('#tc-selected-category-label').html('✔ ' + name + ' (' + id + ')').show();
			}
		});
	}
})(jQuery);
</script>
