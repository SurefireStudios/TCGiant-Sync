<?php
/**
 * Listing Templates: the editor.
 *
 * The body is printed with esc_textarea() only: it is stored as typed, and
 * this page never puts it into its own markup any other way. Old versions are
 * shown escaped too.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- this only chooses what to show.

$tc_id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
$tc_tpl = $tc_id ? TCGiant_Sync_Templates::get( $tc_id ) : null;

if ( ! $tc_tpl ) : ?>
	<div class="tc-card"><p><?php esc_html_e( 'That template no longer exists.', 'tcgiant-sync' ); ?> <a href="<?php echo esc_url( TCGiant_Sync_Template_Admin::url() ); ?>"><?php esc_html_e( 'Back to the list', 'tcgiant-sync' ); ?></a></p></div>
	<?php
	return;
endif;

$tc_design   = $tc_tpl['design'];
$tc_settings = TCGiant_Sync_Templates::settings();
$tc_langs    = array( 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'es' => 'Español' );
$tc_parts    = array(
	'header'            => __( 'Header (logo or shop name, and banner)', 'tcgiant-sync' ),
	'short_description' => __( 'Short description, where the template shows it (Sealed or general does)', 'tcgiant-sync' ),
	'grading'           => __( 'Grading line', 'tcgiant-sync' ),
	'specifics'         => __( 'Item specifics table', 'tcgiant-sync' ),
	'returns'           => __( 'Returns', 'tcgiant-sync' ),
	'handling'          => __( 'Handling time', 'tcgiant-sync' ),
	'gallery'           => __( 'Image gallery (eBay already shows your photos above the description)', 'tcgiant-sync' ),
);
$tc_colours  = array(
	'accent'      => __( 'Accent colour', 'tcgiant-sync' ),
	'accent_text' => __( 'Text on the accent', 'tcgiant-sync' ),
	'background'  => __( 'Page background', 'tcgiant-sync' ),
);
$tc_images   = array(
	'logo_id'   => __( 'Logo (your shop logo from Template settings when empty)', 'tcgiant-sync' ),
	'banner_id' => __( 'Banner image', 'tcgiant-sync' ),
);

// The placeholders the sidebar inserts.
$tc_placeholders = array(
	__( 'Product', 'tcgiant-sync' )           => array(
		'{{title}}'                        => __( 'Title, as sent to eBay', 'tcgiant-sync' ),
		'{{{description}}}'                => __( 'Description (or the eBay-only one)', 'tcgiant-sync' ),
		'{{{short_description}}}'          => __( 'Short description', 'tcgiant-sync' ),
		'{{sku}}'                          => __( 'SKU', 'tcgiant-sync' ),
		'{{condition_name}}'               => __( 'Condition', 'tcgiant-sync' ),
		'{{#grading}}{{grader}} {{grade}} {{cert}}{{/grading}}' => __( 'Grading line (graded cards and coins)', 'tcgiant-sync' ),
		'{{{specifics_table}}}'            => __( 'Item specifics table', 'tcgiant-sync' ),
		'{{#specifics}}{{name}}: {{value}}<br>{{/specifics}}' => __( 'Item specifics, your own markup', 'tcgiant-sync' ),
		'{{{variations_table}}}'           => __( 'Options of a variable product', 'tcgiant-sync' ),
	),
	__( 'Images', 'tcgiant-sync' )            => array(
		'{{main_image}}'                   => __( 'Main image address', 'tcgiant-sync' ),
		'{{image.2}}'                      => __( 'Image 2 address (1 to 24)', 'tcgiant-sync' ),
		'{{#images}}<img src="{{url}}" alt="">{{/images}}' => __( 'Every image', 'tcgiant-sync' ),
		'{{{gallery}}}'                    => __( 'Gallery of up to 6 images', 'tcgiant-sync' ),
	),
	__( 'Your policies and shop', 'tcgiant-sync' ) => array(
		'{{#policy.returns}}<h3>Returns</h3>{{{policy.returns}}}{{/policy.returns}}'   => __( 'Returns, from your return policy', 'tcgiant-sync' ),
		'{{#policy.handling}}<h3>Handling</h3>{{{policy.handling}}}{{/policy.handling}}' => __( 'Handling time, from your shipping policy', 'tcgiant-sync' ),
		'{{shop_name}}'                    => __( 'Shop name', 'tcgiant-sync' ),
		'{{logo_url}}'                     => __( 'Shop logo address', 'tcgiant-sync' ),
		'{{ebay_store_url}}'               => __( 'Your eBay Store address', 'tcgiant-sync' ),
	),
	__( 'Design form', 'tcgiant-sync' )       => array(
		'{{design.accent}}'                => __( 'Accent colour', 'tcgiant-sync' ),
		'{{design.accent_text}}'           => __( 'Text on the accent', 'tcgiant-sync' ),
		'{{design.background}}'            => __( 'Page background', 'tcgiant-sync' ),
		'{{design.text}}'                  => __( 'Text colour (from the background)', 'tcgiant-sync' ),
		'{{design.logo_url}}'              => __( 'Logo address', 'tcgiant-sync' ),
		'{{design.banner_url}}'            => __( 'Banner address', 'tcgiant-sync' ),
		'{{#show.returns}}<p>Your text</p>{{/show.returns}}' => __( 'A part the form can hide', 'tcgiant-sync' ),
	),
);
if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
	foreach ( (array) wc_get_attribute_taxonomies() as $tc_attr ) {
		$tc_placeholders[ __( 'Attributes', 'tcgiant-sync' ) ][ '{{attr.' . $tc_attr->attribute_name . '}}' ] = $tc_attr->attribute_label;
	}
}
foreach ( (array) $tc_settings['meta_allowlist'] as $tc_key ) {
	$tc_placeholders[ __( 'Allowed product meta', 'tcgiant-sync' ) ][ '{{meta.' . $tc_key . '}}' ] = $tc_key;
}
foreach ( TCGiant_Sync_Templates::blocks() as $tc_block ) {
	$tc_placeholders[ __( 'Your blocks', 'tcgiant-sync' ) ][ '{{{block.' . $tc_block['slug'] . '}}}' ] = $tc_block['label'];
}

$tc_preview_template = $tc_tpl['id'];
$tc_preview_product  = 0;
$tc_preview_draft    = true;
$tc_action_url       = function ( $do, array $more = array() ) use ( $tc_tpl ) {
	return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'tcgiant_tpl_action', 'do' => $do, 'id' => $tc_tpl['id'] ), $more ), admin_url( 'admin-post.php' ) ), 'tcgiant_tpl_action' );
};
?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tc-template-form">
	<input type="hidden" name="action" value="tcgiant_tpl_save">
	<input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $tc_tpl['id'] ); ?>">
	<input type="hidden" name="version" value="<?php echo esc_attr( (string) $tc_tpl['version'] ); ?>">
	<?php wp_nonce_field( 'tcgiant_tpl_save' ); ?>

	<div class="tc-editor-grid">
		<div class="tc-editor-main">
			<div class="tc-card">
				<h2>
					<span class="dashicons dashicons-edit"></span>
					<?php
					/* translators: 1: template name, 2: version */
					echo esc_html( sprintf( __( '%1$s, version %2$d', 'tcgiant-sync' ), $tc_tpl['name'], $tc_tpl['version'] ) );
					?>
				</h2>

				<div class="tc-editor-meta">
					<div class="tc-field">
						<label class="tc-label" for="tc-tpl-name"><?php esc_html_e( 'Name', 'tcgiant-sync' ); ?></label>
						<input type="text" class="tc-input" id="tc-tpl-name" name="name" value="<?php echo esc_attr( $tc_tpl['name'] ); ?>">
					</div>
					<div class="tc-field">
						<label class="tc-label" for="tc-tpl-status"><?php esc_html_e( 'Status', 'tcgiant-sync' ); ?></label>
						<select class="tc-select" id="tc-tpl-status" name="status">
							<option value="draft" <?php selected( $tc_tpl['status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'tcgiant-sync' ); ?></option>
							<option value="publish" <?php selected( $tc_tpl['status'], 'publish' ); ?>><?php esc_html_e( 'Published', 'tcgiant-sync' ); ?></option>
						</select>
						<p class="tc-hint"><?php esc_html_e( 'A draft can be previewed but is never used for listings. Only a published template can be assigned.', 'tcgiant-sync' ); ?></p>
					</div>
					<div class="tc-field">
						<label class="tc-label" for="tc-tpl-lang"><?php esc_html_e( 'Language it is written in', 'tcgiant-sync' ); ?></label>
						<select class="tc-select" id="tc-tpl-lang" name="lang">
							<?php foreach ( $tc_langs as $tc_code => $tc_lang ) : ?>
								<option value="<?php echo esc_attr( $tc_code ); ?>" <?php selected( $tc_tpl['lang'], $tc_code ); ?>><?php echo esc_html( $tc_lang ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="tc-field">
						<label class="tc-label" for="tc-tpl-filters"><?php esc_html_e( 'Description formatting', 'tcgiant-sync' ); ?></label>
						<select class="tc-select" id="tc-tpl-filters" name="content_filters">
							<option value="core" <?php selected( $tc_tpl['content_filters'], 'core' ); ?>><?php esc_html_e( 'WordPress only', 'tcgiant-sync' ); ?></option>
							<option value="all" <?php selected( $tc_tpl['content_filters'], 'all' ); ?>><?php esc_html_e( 'Everything', 'tcgiant-sync' ); ?></option>
						</select>
						<p class="tc-hint"><?php esc_html_e( 'WordPress only: its paragraphs, blocks and shortcodes. Everything: whatever your site adds to descriptions, other plugins included.', 'tcgiant-sync' ); ?></p>
					</div>
				</div>
			</div>

			<div class="tc-card" style="margin-top:20px;">
				<h2><span class="dashicons dashicons-admin-appearance"></span> <?php esc_html_e( 'Design', 'tcgiant-sync' ); ?></h2>
				<p class="tc-hint"><?php esc_html_e( 'Colours, images and parts for templates built on the design form, as every starter is. Colours are #rrggbb; images come from your Media Library.', 'tcgiant-sync' ); ?></p>

				<div class="tc-design-grid">
					<?php foreach ( $tc_colours as $tc_key => $tc_label ) : ?>
						<div class="tc-field">
							<label class="tc-label" for="tc-design-<?php echo esc_attr( $tc_key ); ?>"><?php echo esc_html( $tc_label ); ?></label>
							<span class="tc-colour">
								<input type="color" class="tc-colour-picker" data-for="tc-design-<?php echo esc_attr( $tc_key ); ?>" value="<?php echo esc_attr( $tc_design[ $tc_key ] ); ?>">
								<input type="text" class="tc-input tc-colour-text" id="tc-design-<?php echo esc_attr( $tc_key ); ?>" name="design[<?php echo esc_attr( $tc_key ); ?>]" value="<?php echo esc_attr( $tc_design[ $tc_key ] ); ?>" pattern="#[0-9a-fA-F]{6}" maxlength="7" style="max-width:110px;">
							</span>
						</div>
					<?php endforeach; ?>

					<?php foreach ( $tc_images as $tc_key => $tc_label ) :
						$tc_url = TCGiant_Sync_Templates::admin_image_url( $tc_design[ $tc_key ] );
						?>
						<div class="tc-field tc-image-field">
							<span class="tc-label"><?php echo esc_html( $tc_label ); ?></span>
							<input type="hidden" class="tc-image-id" name="design[<?php echo esc_attr( $tc_key ); ?>]" value="<?php echo esc_attr( (string) $tc_design[ $tc_key ] ); ?>">
							<img class="tc-image-preview" src="<?php echo esc_url( $tc_url ); ?>" alt="" <?php echo '' === $tc_url ? 'hidden' : ''; ?>>
							<button type="button" class="tc-button secondary tc-image-choose"><?php esc_html_e( 'Choose', 'tcgiant-sync' ); ?></button>
							<button type="button" class="tc-button secondary tc-image-clear"><?php esc_html_e( 'Remove', 'tcgiant-sync' ); ?></button>
						</div>
					<?php endforeach; ?>
				</div>

				<fieldset class="tc-parts">
					<legend class="tc-label"><?php esc_html_e( 'Parts shown', 'tcgiant-sync' ); ?></legend>
					<?php foreach ( $tc_parts as $tc_part => $tc_label ) : ?>
						<label><input type="checkbox" name="design[show][<?php echo esc_attr( $tc_part ); ?>]" value="1" <?php checked( ! empty( $tc_design['show'][ $tc_part ] ) ); ?>> <?php echo esc_html( $tc_label ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			</div>

			<div class="tc-card" style="margin-top:20px;">
				<h2><span class="dashicons dashicons-editor-code"></span> <?php esc_html_e( 'HTML and CSS', 'tcgiant-sync' ); ?></h2>
				<p class="tc-hint"><?php esc_html_e( 'Optional. {{x}} puts a value in, escaped; {{{x}}} puts in markup that is cleaned first; {{#x}}…{{/x}} shows a part only when x has something. Scripts, forms, iframes and links off eBay are removed before anything is sent. Keep the layout in inline styles: if eBay drops the stylesheet, the description still reads.', 'tcgiant-sync' ); ?></p>
				<label class="tc-label" for="tc-tpl-html"><?php esc_html_e( 'HTML', 'tcgiant-sync' ); ?></label>
				<textarea id="tc-tpl-html" name="html" rows="22" class="tc-code" spellcheck="false"><?php echo esc_textarea( $tc_tpl['html'] ); ?></textarea>
				<label class="tc-label" for="tc-tpl-css" style="margin-top:12px;display:block;"><?php esc_html_e( 'CSS', 'tcgiant-sync' ); ?></label>
				<textarea id="tc-tpl-css" name="css" rows="10" class="tc-code" spellcheck="false"><?php echo esc_textarea( $tc_tpl['css'] ); ?></textarea>
				<p class="tc-hint"><?php esc_html_e( 'A <style> typed into the HTML is moved here when you save. Only the design form\'s colours can be used as placeholders in the CSS.', 'tcgiant-sync' ); ?></p>
			</div>

			<div class="tc-form-footer" style="margin-top:16px;">
				<button type="submit" class="tc-button"><?php esc_html_e( 'Save as a new version', 'tcgiant-sync' ); ?></button>
				<span class="tc-hint" style="margin-left:8px;"><?php esc_html_e( 'Saving sends nothing to eBay. Listings using this template get it on their next push.', 'tcgiant-sync' ); ?></span>
			</div>
		</div>

		<aside class="tc-editor-side">
			<div class="tc-card">
				<h2><span class="dashicons dashicons-shortcode"></span> <?php esc_html_e( 'Insert', 'tcgiant-sync' ); ?></h2>
				<?php foreach ( $tc_placeholders as $tc_group => $tc_items ) : ?>
					<h3 class="tc-insert-group"><?php echo esc_html( $tc_group ); ?></h3>
					<ul class="tc-insert-list">
						<?php foreach ( $tc_items as $tc_code => $tc_label ) : ?>
							<li><button type="button" class="tc-insert" data-insert="<?php echo esc_attr( $tc_code ); ?>" title="<?php echo esc_attr( $tc_code ); ?>"><?php echo esc_html( $tc_label ); ?></button></li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>
			</div>

			<div class="tc-card" style="margin-top:20px;">
				<h2><span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'Versions', 'tcgiant-sync' ); ?></h2>
				<?php if ( empty( $tc_tpl['versions'] ) ) : ?>
					<p class="tc-hint"><?php esc_html_e( 'Each save keeps the one before it. The last ten are kept here.', 'tcgiant-sync' ); ?></p>
				<?php else : ?>
					<?php foreach ( array_reverse( $tc_tpl['versions'] ) as $tc_old ) :
						$tc_who = ! empty( $tc_old['user_id'] ) && function_exists( 'get_userdata' ) ? get_userdata( (int) $tc_old['user_id'] ) : false;
						?>
						<details class="tc-version">
							<summary>
								<?php
								/* translators: 1: version, 2: date, 3: user */
								echo esc_html( sprintf( __( 'Version %1$d, saved %2$s%3$s', 'tcgiant-sync' ), (int) $tc_old['version'], (string) ( $tc_old['saved_at'] ?? '' ), $tc_who ? ' ' . sprintf( __( 'by %s', 'tcgiant-sync' ), $tc_who->display_name ) : '' ) );
								?>
							</summary>
							<p><a class="tc-button secondary" href="<?php echo esc_url( $tc_action_url( 'restore', array( 'version' => (int) $tc_old['version'] ) ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Restore this version? It is saved as a new version; nothing is sent to eBay.', 'tcgiant-sync' ) ); ?>');"><?php esc_html_e( 'Restore this version', 'tcgiant-sync' ); ?></a></p>
							<p class="tc-hint"><?php esc_html_e( 'What changed from it to the version now, line by line:', 'tcgiant-sync' ); ?></p>
							<pre class="tc-diff"><?php
							foreach ( TCGiant_Sync_Template_Admin::line_diff( (string) ( $tc_old['html'] ?? '' ) . "\n/* CSS */\n" . (string) ( $tc_old['css'] ?? '' ), $tc_tpl['html'] . "\n/* CSS */\n" . $tc_tpl['css'] ) as $tc_line ) {
								echo '<span class="tc-diff-' . esc_attr( $tc_line[0] ) . '">' . esc_html( ( 'add' === $tc_line[0] ? '+ ' : ( 'del' === $tc_line[0] ? '- ' : '  ' ) ) . $tc_line[1] ) . "</span>\n";
							}
							?></pre>
						</details>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</aside>
	</div>
</form>

<div style="margin-top:20px;">
	<?php include TCGIANT_SYNC_PATH . 'admin/views/template-preview-panel.php'; ?>
</div>
