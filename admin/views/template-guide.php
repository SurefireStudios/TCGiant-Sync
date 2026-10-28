<?php
/**
 * Listing Templates: the guide for shops switching from WP-Lister.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="tc-card tc-guide" style="margin-top:20px;">
	<h2><span class="dashicons dashicons-randomize"></span> <?php esc_html_e( 'Switching from WP-Lister for eBay', 'tcgiant-sync' ); ?></h2>
	<p class="tc-hint"><?php esc_html_e( 'Your eBay listings keep WP-Lister\'s design until each is pushed from here. Linking them changes nothing on eBay, and stock updates send the quantity only. In this order:', 'tcgiant-sync' ); ?></p>
	<ol class="tc-guide-steps">
		<li><?php esc_html_e( 'In WP-Lister, turn off its order creation and its stock sync. With both plugins doing it, the same eBay orders are imported twice and stock is pushed twice.', 'tcgiant-sync' ); ?></li>
		<li>
			<?php
			printf(
				/* translators: %s: link to Import from eBay */
				esc_html__( 'Link your listings: %s, with Overwrite Description off, so your WooCommerce descriptions stay as they are.', 'tcgiant-sync' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=tcgiant-import' ) ) . '">' . esc_html__( 'Import from eBay', 'tcgiant-sync' ) . '</a>'
			);
			?>
		</li>
		<li>
			<?php
			printf(
				/* translators: %s: link to the converter */
				esc_html__( 'Convert your WP-Lister template (%s), or start from one of the designs here.', 'tcgiant-sync' ),
				'<a href="' . esc_url( TCGiant_Sync_Template_Admin::url( 'convert' ) ) . '">' . esc_html__( 'Switching from WP-Lister', 'tcgiant-sync' ) . '</a>'
			);
			?>
		</li>
		<li>
			<?php
			printf(
				/* translators: %s: link to Template settings */
				esc_html__( 'Assign it: as the default, to WooCommerce categories in %s, or on a product\'s eBay tab.', 'tcgiant-sync' ),
				'<a href="' . esc_url( TCGiant_Sync_Template_Admin::url( 'settings' ) ) . '">' . esc_html__( 'Template settings', 'tcgiant-sync' ) . '</a>'
			);
			?>
		</li>
		<li><?php esc_html_e( 'Preview a few products, and Check with eBay once. A template imported or converted from elsewhere can name pictures and stylesheets on other sites, and the preview loads them in your browser, so convert only templates you trust.', 'tcgiant-sync' ); ?></li>
		<li><?php esc_html_e( 'Then push. Each listing gets the new design on its next push; bulk pushes are limited per day, so a large shop does it over a few days.', 'tcgiant-sync' ); ?></li>
	</ol>
</div>
