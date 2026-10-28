<?php
/**
 * Listing Templates: template settings.
 *
 * Its own form and its own option (tcgiant_sync_template_settings), so that
 * saving the Import or Push settings can never wipe it; and the bulk-push
 * limit in an option of its own, for the same reason.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$tc_settings  = TCGiant_Sync_Templates::settings();
$tc_published = TCGiant_Sync_Templates::all( 'publish' );
$tc_terms     = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
$tc_terms     = is_array( $tc_terms ) ? $tc_terms : array();
$tc_rows      = array_merge( (array) $tc_settings['category_map'], array_fill( 0, 3, array( 'term_id' => 0, 'template_id' => 0, 'priority' => 10 ) ) );
$tc_logo_url  = TCGiant_Sync_Templates::admin_image_url( (int) $tc_settings['logo_id'] );

// Categories as a tree, each indented under its parent.
$tc_children = array();
foreach ( $tc_terms as $tc_term ) {
	$tc_children[ (int) $tc_term->parent ][] = $tc_term;
}
$tc_category_options = array();
$tc_walk             = function ( $parent, $depth ) use ( &$tc_walk, &$tc_category_options, $tc_children ) {
	foreach ( $tc_children[ $parent ] ?? array() as $tc_term ) {
		$tc_category_options[ (int) $tc_term->term_id ] = str_repeat( '— ', $depth ) . $tc_term->name;
		$tc_walk( (int) $tc_term->term_id, $depth + 1 );
	}
};
$tc_walk( 0, 0 );
?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="tcgiant_tpl_settings">
	<?php wp_nonce_field( 'tcgiant_tpl_settings' ); ?>

	<div class="tc-row-2col">
		<div class="tc-card">
			<h2><span class="dashicons dashicons-randomize"></span> <?php esc_html_e( 'Which template a listing uses', 'tcgiant-sync' ); ?></h2>
			<p class="tc-hint"><?php esc_html_e( 'First match wins: a template chosen on the product\'s eBay tab; then its WooCommerce category; then the default; then None. Nothing is sent to eBay when you change these: each listing gets its template on its next push.', 'tcgiant-sync' ); ?></p>

			<div class="tc-field">
				<label class="tc-label" for="tc-default-template"><?php esc_html_e( 'Default template', 'tcgiant-sync' ); ?></label>
				<select class="tc-select" id="tc-default-template" name="tpl[default_template_id]">
					<option value="0"><?php esc_html_e( 'None (plain WooCommerce description)', 'tcgiant-sync' ); ?></option>
					<?php foreach ( $tc_published as $tc_tpl ) : ?>
						<option value="<?php echo esc_attr( (string) $tc_tpl['id'] ); ?>" <?php selected( (int) $tc_settings['default_template_id'], $tc_tpl['id'] ); ?>><?php echo esc_html( $tc_tpl['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( empty( $tc_published ) ) : ?>
					<p class="tc-hint"><?php esc_html_e( 'Only published templates can be chosen. Open a template, set it to Published and save it.', 'tcgiant-sync' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="tc-field">
				<span class="tc-label"><?php esc_html_e( 'By WooCommerce category', 'tcgiant-sync' ); ?></span>
				<p class="tc-hint"><?php esc_html_e( 'A rule covers the category and every category inside it. The most specific category wins; between two as specific, the lower priority number (1 before 2).', 'tcgiant-sync' ); ?></p>
				<table class="widefat tc-category-map" id="tc-category-map">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Category', 'tcgiant-sync' ); ?></th>
							<th><?php esc_html_e( 'Template', 'tcgiant-sync' ); ?></th>
							<th><?php esc_html_e( 'Priority', 'tcgiant-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tc_rows as $tc_i => $tc_row ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Category', 'tcgiant-sync' ); ?>">
									<select name="tpl[category_map][<?php echo esc_attr( (string) $tc_i ); ?>][term_id]">
										<option value="0"><?php esc_html_e( '— none —', 'tcgiant-sync' ); ?></option>
										<?php foreach ( $tc_category_options as $tc_term_id => $tc_name ) : ?>
											<option value="<?php echo esc_attr( (string) $tc_term_id ); ?>" <?php selected( (int) $tc_row['term_id'], $tc_term_id ); ?>><?php echo esc_html( $tc_name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td data-label="<?php esc_attr_e( 'Template', 'tcgiant-sync' ); ?>">
									<select name="tpl[category_map][<?php echo esc_attr( (string) $tc_i ); ?>][template_id]">
										<option value="0"><?php esc_html_e( '— none —', 'tcgiant-sync' ); ?></option>
										<?php foreach ( $tc_published as $tc_tpl ) : ?>
											<option value="<?php echo esc_attr( (string) $tc_tpl['id'] ); ?>" <?php selected( (int) $tc_row['template_id'], $tc_tpl['id'] ); ?>><?php echo esc_html( $tc_tpl['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td data-label="<?php esc_attr_e( 'Priority', 'tcgiant-sync' ); ?>"><input type="number" min="1" max="99" name="tpl[category_map][<?php echo esc_attr( (string) $tc_i ); ?>][priority]" value="<?php echo esc_attr( (string) (int) $tc_row['priority'] ); ?>" style="width:70px;"></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="tc-button secondary" id="tc-category-add"><?php esc_html_e( 'Add a row', 'tcgiant-sync' ); ?></button></p>
			</div>
		</div>

		<div class="tc-card">
			<h2><span class="dashicons dashicons-store"></span> <?php esc_html_e( 'Your shop, for the templates', 'tcgiant-sync' ); ?></h2>

			<div class="tc-field">
				<label class="tc-label" for="tc-shop-name"><?php esc_html_e( 'Shop name ({{shop_name}})', 'tcgiant-sync' ); ?></label>
				<input type="text" class="tc-input" id="tc-shop-name" name="tpl[shop_name]" maxlength="80" value="<?php echo esc_attr( (string) $tc_settings['shop_name'] ); ?>">
			</div>

			<div class="tc-field tc-image-field">
				<span class="tc-label"><?php esc_html_e( 'Logo ({{logo_url}}), from your Media Library', 'tcgiant-sync' ); ?></span>
				<input type="hidden" class="tc-image-id" name="tpl[logo_id]" value="<?php echo esc_attr( (string) (int) $tc_settings['logo_id'] ); ?>">
				<img class="tc-image-preview" src="<?php echo esc_url( $tc_logo_url ); ?>" alt="" <?php echo '' === $tc_logo_url ? 'hidden' : ''; ?>>
				<button type="button" class="tc-button secondary tc-image-choose"><?php esc_html_e( 'Choose', 'tcgiant-sync' ); ?></button>
				<button type="button" class="tc-button secondary tc-image-clear"><?php esc_html_e( 'Remove', 'tcgiant-sync' ); ?></button>
				<p class="tc-hint"><?php esc_html_e( 'Shown only when your site serves it over https.', 'tcgiant-sync' ); ?></p>
			</div>

			<div class="tc-field">
				<label class="tc-label" for="tc-store-url"><?php esc_html_e( 'Your eBay Store address ({{ebay_store_url}})', 'tcgiant-sync' ); ?></label>
				<input type="url" class="tc-input" id="tc-store-url" name="tpl[ebay_store_url]" value="<?php echo esc_attr( (string) $tc_settings['ebay_store_url'] ); ?>" placeholder="https://www.ebay.com/str/yourstore">
				<p class="tc-hint"><?php esc_html_e( 'An eBay address only: eBay allows links to its own pages in a listing, and no others.', 'tcgiant-sync' ); ?></p>
			</div>

			<div class="tc-field">
				<label class="tc-label" for="tc-meta-allowlist"><?php esc_html_e( 'Product meta a template may show ({{meta.key}})', 'tcgiant-sync' ); ?></label>
				<textarea class="tc-input" id="tc-meta-allowlist" name="tpl[meta_allowlist]" rows="3" style="font-family:monospace;"><?php echo esc_textarea( implode( "\n", (array) $tc_settings['meta_allowlist'] ) ); ?></textarea>
				<p class="tc-hint"><?php esc_html_e( 'One key a line. Product meta can hold cost prices and supplier codes, so a template shows only the keys listed here.', 'tcgiant-sync' ); ?></p>
			</div>
		</div>
	</div>

	<div class="tc-card" style="margin-top:20px;">
		<h2><span class="dashicons dashicons-dashboard"></span> <?php esc_html_e( 'Limits', 'tcgiant-sync' ); ?></h2>
		<p class="tc-hint"><?php esc_html_e( 'Every TCGiant shop shares one daily allowance of eBay calls, and each push uses 1 to 13 of them. These keep one shop from using everyone\'s day.', 'tcgiant-sync' ); ?></p>
		<div class="tc-row-2col">
			<div class="tc-field">
				<label class="tc-label" for="tc-bulk-cap"><?php esc_html_e( 'Products bulk pushes may queue a day (50 to 20,000)', 'tcgiant-sync' ); ?></label>
				<input type="number" class="tc-input" id="tc-bulk-cap" name="bulk_push_cap" min="<?php echo esc_attr( (string) TCGiant_Sync_Exporter::BULK_PUSH_CAP_MIN ); ?>" max="<?php echo esc_attr( (string) TCGiant_Sync_Exporter::BULK_PUSH_CAP_MAX ); ?>" value="<?php echo esc_attr( (string) TCGiant_Sync_Exporter::bulk_push_cap() ); ?>" style="max-width:140px;">
				<p class="tc-hint"><?php echo esc_html( TCGiant_Sync_Exporter::bulk_push_line() ); ?>. <?php esc_html_e( 'Bulk pushes from the products list, the Listings screen and the coin update all count; a push from a product\'s own screen does not. It resets at midnight UTC.', 'tcgiant-sync' ); ?></p>
			</div>
			<div class="tc-field">
				<label class="tc-label" for="tc-verify-cap"><?php esc_html_e( 'Check with eBay presses a day (0 to 200)', 'tcgiant-sync' ); ?></label>
				<input type="number" class="tc-input" id="tc-verify-cap" name="tpl[verify_daily_cap]" min="0" max="200" value="<?php echo esc_attr( (string) (int) $tc_settings['verify_daily_cap'] ); ?>" style="max-width:140px;">
				<p class="tc-hint">
					<?php
					/* translators: %d: presses left today */
					echo esc_html( sprintf( __( 'One eBay call a press. %d left today.', 'tcgiant-sync' ), TCGiant_Sync_Template_Admin::checks_left() ) );
					?>
				</p>
			</div>
		</div>
	</div>

	<div class="tc-form-footer" style="margin-top:16px;">
		<button type="submit" class="tc-button"><?php esc_html_e( 'Save template settings', 'tcgiant-sync' ); ?></button>
	</div>
</form>
