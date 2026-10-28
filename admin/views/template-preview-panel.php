<?php
/**
 * Listing Templates: the preview, shared by the editor and the Preview view.
 *
 * The description arrives as JSON and is shown only inside an iframe whose
 * sandbox allows nothing - no scripts, no forms, not even this site's origin -
 * so a description can never run anything in the admin.
 *
 * Expects $tc_preview_template (template ID, 0 for the product's own rules),
 * $tc_preview_product (product ID or 0) and $tc_preview_draft (bool: whether
 * the editor's unsaved changes are previewed).
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$tc_preview_template    = isset( $tc_preview_template ) ? (int) $tc_preview_template : 0;
$tc_preview_product     = isset( $tc_preview_product ) ? (int) $tc_preview_product : 0;
$tc_preview_draft       = ! empty( $tc_preview_draft );
$tc_preview_product_obj = $tc_preview_product ? wc_get_product( $tc_preview_product ) : null;
$tc_checks_left         = TCGiant_Sync_Template_Admin::checks_left();
?>

<div class="tc-card tc-preview-card" id="tc-preview" data-template="<?php echo esc_attr( (string) (int) $tc_preview_template ); ?>" data-draft="<?php echo $tc_preview_draft ? '1' : ''; ?>">
	<h2><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Preview', 'tcgiant-sync' ); ?></h2>
	<p class="tc-hint">
		<?php
		echo $tc_preview_draft
			? esc_html__( 'Exactly the description a push would send for this product, with the changes above as they are now, saved or not.', 'tcgiant-sync' )
			: esc_html__( 'Exactly the description a push would send for this product.', 'tcgiant-sync' );
		?>
	</p>

	<div class="tc-preview-bar">
		<label class="screen-reader-text" for="tc-preview-search"><?php esc_html_e( 'Find a product', 'tcgiant-sync' ); ?></label>
		<input type="search" id="tc-preview-search" class="tc-input" placeholder="<?php esc_attr_e( 'Find a product by name or SKU', 'tcgiant-sync' ); ?>" style="max-width:260px;">
		<select id="tc-preview-product" class="tc-select" style="max-width:320px;">
			<?php if ( $tc_preview_product_obj ) : ?>
				<option value="<?php echo esc_attr( (string) $tc_preview_product_obj->get_id() ); ?>" selected><?php echo esc_html( $tc_preview_product_obj->get_name() ); ?></option>
			<?php else : ?>
				<option value=""><?php esc_html_e( '— the last products pushed load here —', 'tcgiant-sync' ); ?></option>
			<?php endif; ?>
		</select>
		<button type="button" class="tc-button" id="tc-preview-run"><?php esc_html_e( 'Preview', 'tcgiant-sync' ); ?></button>
		<span class="tc-preview-devices" role="group" aria-label="<?php esc_attr_e( 'Width', 'tcgiant-sync' ); ?>">
			<button type="button" class="tc-button secondary is-active" data-width="100%" aria-pressed="true"><?php esc_html_e( 'Desktop', 'tcgiant-sync' ); ?></button>
			<button type="button" class="tc-button secondary" data-width="768px" aria-pressed="false"><?php esc_html_e( 'Tablet', 'tcgiant-sync' ); ?></button>
			<button type="button" class="tc-button secondary" data-width="375px" aria-pressed="false"><?php esc_html_e( 'Phone (375 px)', 'tcgiant-sync' ); ?></button>
		</span>
	</div>

	<div class="tc-preview-stage">
		<iframe id="tc-preview-frame" sandbox="" referrerpolicy="no-referrer" title="<?php esc_attr_e( 'The eBay description', 'tcgiant-sync' ); ?>"></iframe>
	</div>

	<div class="tc-preview-report">
		<p id="tc-preview-count" class="tc-preview-count"></p>
		<div class="tc-preview-lists">
			<div>
				<h3><?php esc_html_e( 'Warnings', 'tcgiant-sync' ); ?></h3>
				<ul id="tc-preview-warnings"></ul>
			</div>
			<div>
				<h3><?php esc_html_e( 'Removed before sending', 'tcgiant-sync' ); ?></h3>
				<ul id="tc-preview-removed"></ul>
			</div>
		</div>
	</div>

	<div class="tc-preview-check">
		<button type="button" class="tc-button secondary" id="tc-preview-check" <?php disabled( $tc_checks_left < 1 ); ?>><?php esc_html_e( 'Check with eBay', 'tcgiant-sync' ); ?></button>
		<span class="tc-hint" id="tc-preview-check-left">
			<?php
			/* translators: 1: checks left today, 2: the daily limit */
			echo esc_html( sprintf( __( 'One eBay call a press, without creating a listing. %1$d of %2$d left today.', 'tcgiant-sync' ), $tc_checks_left, (int) TCGiant_Sync_Templates::settings()['verify_daily_cap'] ) );
			?>
		</span>
		<p id="tc-preview-check-result" class="tc-preview-check-result"></p>
	</div>
</div>
