<?php
/**
 * Listing Templates: switching from WP-Lister, and its template converter.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- this only chooses what to show.

$tc_installed = class_exists( 'TCGiant_Sync_WPLister_Converter' ) ? TCGiant_Sync_WPLister_Converter::installed() : array();
$tc_has_zip   = class_exists( 'ZipArchive' );
$tc_result    = ! empty( $_GET['report'] ) ? get_transient( 'tcgiant_tpl_convert_' . get_current_user_id() ) : false;
$tc_sections  = array(
	'mapped'      => __( 'Mapped', 'tcgiant-sync' ),
	'unsupported' => __( 'No equivalent here, taken out', 'tcgiant-sync' ),
	'dropped'     => __( 'Dropped', 'tcgiant-sync' ),
	'allowlist'   => __( 'Product meta keys to add to the allowlist in Template settings', 'tcgiant-sync' ),
	'attributes'  => __( 'Attribute labels that match none of this shop\'s global attributes (they still work for a product with a custom attribute of that name)', 'tcgiant-sync' ),
	'fields'      => __( 'WP-Lister custom fields, written in as plain values', 'tcgiant-sync' ),
	'unresolved'  => __( 'Left as it was: nothing here fills these', 'tcgiant-sync' ),
	'skipped'     => __( 'Not read from the zip', 'tcgiant-sync' ),
	'suggestions' => __( 'Worth a look', 'tcgiant-sync' ),
);
?>

<?php if ( is_array( $tc_result ) && ! empty( $tc_result['id'] ) ) :
	$tc_draft = TCGiant_Sync_Templates::get( (int) $tc_result['id'] );
	?>
	<div class="tc-card" style="margin-bottom:20px;">
		<h2><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Conversion report', 'tcgiant-sync' ); ?></h2>
		<?php if ( $tc_draft ) : ?>
			<p>
				<?php
				/* translators: %s: template name */
				echo esc_html( sprintf( __( 'Saved as the draft "%s". Nothing is assigned and nothing was sent to eBay.', 'tcgiant-sync' ), $tc_draft['name'] ) );
				?>
				<a class="tc-button" href="<?php echo esc_url( TCGiant_Sync_Template_Admin::url( 'edit', array( 'id' => $tc_draft['id'] ) ) ); ?>"><?php esc_html_e( 'Open the draft and preview it', 'tcgiant-sync' ); ?></a>
			</p>
		<?php endif; ?>
		<?php foreach ( $tc_sections as $tc_key => $tc_title ) :
			$tc_items = (array) ( $tc_result['report'][ $tc_key ] ?? array() );
			if ( empty( $tc_items ) ) {
				continue;
			}
			?>
			<h3><?php echo esc_html( $tc_title ); ?></h3>
			<ul class="tc-report-list">
				<?php foreach ( $tc_items as $tc_from => $tc_item ) : ?>
					<li>
						<?php if ( 'mapped' === $tc_key || 'fields' === $tc_key ) : ?>
							<code><?php echo esc_html( (string) $tc_from ); ?></code> &rarr; <code><?php echo esc_html( (string) $tc_item ); ?></code>
						<?php else : ?>
							<?php echo esc_html( (string) $tc_item ); ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<div class="tc-card">
	<h2><span class="dashicons dashicons-migrate"></span> <?php esc_html_e( 'Convert a WP-Lister template', 'tcgiant-sync' ); ?></h2>
	<p class="tc-hint"><?php esc_html_e( 'The design comes across as a draft: its HTML and stylesheet, with WP-Lister\'s [[placeholders]] turned into ours and its colour settings written in. Its PHP files are never run - they are listed in the report - and neither are its widgets, which eBay counts as active content.', 'tcgiant-sync' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="tc-convert-form">
		<input type="hidden" name="action" value="tcgiant_tpl_convert">
		<?php wp_nonce_field( 'tcgiant_tpl_convert' ); ?>

		<?php if ( $tc_has_zip ) : ?>
			<div class="tc-field">
				<label class="tc-label"><input type="radio" name="source" value="upload" checked> <?php esc_html_e( 'A template zip, from WP-Lister\'s Templates page (its Download link)', 'tcgiant-sync' ); ?></label>
				<input type="file" name="wplister_zip" accept=".zip,application/zip">
				<p class="tc-hint"><?php esc_html_e( 'Up to 2 MB. It is read where it is uploaded; nothing in it is unpacked onto your server.', 'tcgiant-sync' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $tc_installed ) : ?>
			<div class="tc-field">
				<label class="tc-label"><input type="radio" name="source" value="installed" <?php checked( ! $tc_has_zip ); ?>> <?php esc_html_e( 'One of the WP-Lister templates on this site', 'tcgiant-sync' ); ?></label>
				<select name="installed" class="tc-select" style="max-width:320px;">
					<?php foreach ( array_keys( $tc_installed ) as $tc_folder ) : ?>
						<option value="<?php echo esc_attr( $tc_folder ); ?>"><?php echo esc_html( $tc_folder ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>

		<div class="tc-field">
			<label class="tc-label"><input type="radio" name="source" value="paste" <?php checked( ! $tc_has_zip && ! $tc_installed ); ?>> <?php esc_html_e( 'Or paste its template.html and style.css', 'tcgiant-sync' ); ?></label>
			<?php if ( ! $tc_has_zip ) : ?>
				<p class="tc-hint"><?php esc_html_e( 'This server\'s PHP cannot read zip files, so paste the two files instead.', 'tcgiant-sync' ); ?></p>
			<?php endif; ?>
			<textarea name="paste_html" rows="6" class="tc-input tc-code" placeholder="template.html"></textarea>
			<textarea name="paste_css" rows="6" class="tc-input tc-code" placeholder="style.css" style="margin-top:6px;"></textarea>
			<textarea name="paste_config" rows="3" class="tc-input tc-code" placeholder="<?php esc_attr_e( 'config.json, if the template has one: its colour and text settings', 'tcgiant-sync' ); ?>" style="margin-top:6px;"></textarea>
		</div>

		<button type="submit" class="tc-button"><?php esc_html_e( 'Convert to a draft', 'tcgiant-sync' ); ?></button>
	</form>
</div>

<?php include TCGIANT_SYNC_PATH . 'admin/views/template-guide.php'; ?>
