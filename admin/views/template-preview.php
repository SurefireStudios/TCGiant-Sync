<?php
/**
 * Listing Templates: the Preview view, for a saved template or a product's own
 * rules.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- these only choose what to show.

$tc_preview_template = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
$tc_preview_product  = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
$tc_preview_draft    = false;
$tc_preview_tpl      = $tc_preview_template ? TCGiant_Sync_Templates::get( $tc_preview_template ) : null;
?>

<div class="tc-card" style="margin-bottom:20px;">
	<h2>
		<?php
		if ( $tc_preview_tpl ) {
			/* translators: 1: template name, 2: version */
			echo esc_html( sprintf( __( 'Preview of %1$s, version %2$d', 'tcgiant-sync' ), $tc_preview_tpl['name'], $tc_preview_tpl['version'] ) );
		} else {
			esc_html_e( 'Preview of the template this product\'s rules choose', 'tcgiant-sync' );
		}
		?>
	</h2>
	<?php if ( $tc_preview_tpl ) : ?>
		<p><a href="<?php echo esc_url( TCGiant_Sync_Template_Admin::url( 'edit', array( 'id' => $tc_preview_tpl['id'] ) ) ); ?>"><?php esc_html_e( 'Edit this template', 'tcgiant-sync' ); ?></a></p>
	<?php elseif ( $tc_preview_product ) : ?>
		<p><a href="<?php echo esc_url( (string) get_edit_post_link( $tc_preview_product ) ); ?>"><?php esc_html_e( 'Back to the product', 'tcgiant-sync' ); ?></a></p>
	<?php endif; ?>
</div>

<?php include TCGIANT_SYNC_PATH . 'admin/views/template-preview-panel.php'; ?>
