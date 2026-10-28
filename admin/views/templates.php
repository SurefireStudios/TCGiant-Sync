<?php
/**
 * Listing Templates Page View
 *
 * The list, the editor, the blocks, the template settings and the WP-Lister
 * converter, each a view of this one screen.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- these only choose what to show.

$tc_view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';
$tc_views = array(
	'list'     => __( 'Templates', 'tcgiant-sync' ),
	'blocks'   => __( 'Blocks', 'tcgiant-sync' ),
	'settings' => __( 'Template settings', 'tcgiant-sync' ),
	'convert'  => __( 'Switching from WP-Lister', 'tcgiant-sync' ),
);
$tc_files = array(
	'list'     => 'template-list.php',
	'edit'     => 'template-edit.php',
	'preview'  => 'template-preview.php',
	'blocks'   => 'template-blocks.php',
	'settings' => 'template-settings.php',
	'convert'  => 'template-convert.php',
);
if ( ! isset( $tc_files[ $tc_view ] ) ) {
	$tc_view = 'list';
}
$tc_current = in_array( $tc_view, array( 'edit', 'preview' ), true ) ? 'list' : $tc_view;
?>

<div class="wrap tc-dashboard-wrap tc-templates-wrap">
	<div class="tc-header">
		<h1><?php esc_html_e( 'Listing Templates', 'tcgiant-sync' ); ?></h1>
		<p class="tc-subtitle"><?php esc_html_e( 'Designed eBay descriptions, with your returns and handling time taken from your Business Policies. Nothing changes until you pick a template: the default is None, the plain WooCommerce description.', 'tcgiant-sync' ); ?></p>
	</div>

	<?php TCGiant_Sync_Admin::instance()->render_tabs( 'templates' ); ?>

	<?php TCGiant_Sync_Template_Admin::show_notice(); ?>

	<div class="tc-subnav">
		<?php foreach ( $tc_views as $tc_key => $tc_label ) : ?>
			<a href="<?php echo esc_url( TCGiant_Sync_Template_Admin::url( $tc_key ) ); ?>" class="tc-subnav-link<?php echo $tc_key === $tc_current ? ' is-active' : ''; ?>"><?php echo esc_html( $tc_label ); ?></a>
		<?php endforeach; ?>
	</div>

	<?php include TCGIANT_SYNC_PATH . 'admin/views/' . $tc_files[ $tc_view ]; ?>
</div>
