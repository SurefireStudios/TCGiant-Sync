<?php
/**
 * Listing Templates: the list.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$tc_templates = TCGiant_Sync_Templates::all();
$tc_settings  = TCGiant_Sync_Templates::settings();
$tc_default   = (int) $tc_settings['default_template_id'];
$tc_starters  = class_exists( 'TCGiant_Sync_Template_Starters' ) ? TCGiant_Sync_Template_Starters::all() : array();
$tc_action    = function ( $do, $id = 0, array $more = array() ) {
	return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'tcgiant_tpl_action', 'do' => $do, 'id' => (int) $id ), $more ), admin_url( 'admin-post.php' ) ), 'tcgiant_tpl_action' );
};
$tc_langs = array( 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'es' => 'Español' );
?>

<div class="tc-card">
	<h2><span class="dashicons dashicons-layout"></span> <?php esc_html_e( 'Your templates', 'tcgiant-sync' ); ?></h2>

	<p class="tc-hint">
		<?php
		$tc_default_tpl = $tc_default ? TCGiant_Sync_Templates::get( $tc_default ) : null;
		if ( $tc_default_tpl ) {
			/* translators: %s: template name */
			printf( esc_html__( 'Default template: %s. Listings that follow the rules and have no category rule use it from their next push.', 'tcgiant-sync' ), '<strong>' . esc_html( $tc_default_tpl['name'] ) . '</strong>' );
			echo ' <a href="' . esc_url( $tc_action( 'default', 0 ) ) . '">' . esc_html__( 'Set the default back to None', 'tcgiant-sync' ) . '</a>';
		} else {
			esc_html_e( 'Default template: None. Every listing is sent the plain WooCommerce description, exactly as before, until you choose a default, a category rule or a template on a product.', 'tcgiant-sync' );
		}
		?>
	</p>

	<?php if ( empty( $tc_templates ) ) : ?>
		<p><?php esc_html_e( 'No templates yet. Start from one of the designs below, import a template file, or convert one from WP-Lister.', 'tcgiant-sync' ); ?></p>
	<?php else : ?>
		<table class="widefat striped tc-templates-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'tcgiant-sync' ); ?></th>
					<th><?php esc_html_e( 'Language', 'tcgiant-sync' ); ?></th>
					<th><?php esc_html_e( 'Version', 'tcgiant-sync' ); ?></th>
					<th><?php esc_html_e( 'Last saved', 'tcgiant-sync' ); ?></th>
					<th><?php esc_html_e( 'Set for', 'tcgiant-sync' ); ?></th>
					<th><?php esc_html_e( 'Sent with', 'tcgiant-sync' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'tcgiant-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tc_templates as $tc_tpl ) :
					$tc_use  = TCGiant_Sync_Templates::assignments( $tc_tpl['id'] );
					$tc_set  = array();
					if ( $tc_use['default'] ) {
						$tc_set[] = __( 'the default', 'tcgiant-sync' );
					}
					if ( $tc_use['categories'] ) {
						/* translators: %d: number of category rules */
						$tc_set[] = sprintf( _n( '%d category', '%d categories', $tc_use['categories'], 'tcgiant-sync' ), $tc_use['categories'] );
					}
					if ( $tc_use['products'] ) {
						/* translators: %d: number of products */
						$tc_set[] = sprintf( _n( '%d product', '%d products', $tc_use['products'], 'tcgiant-sync' ), $tc_use['products'] );
					}
					$tc_sent = TCGiant_Sync_Templates::sent_count( $tc_tpl['id'] );
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Name', 'tcgiant-sync' ); ?>">
							<a href="<?php echo esc_url( TCGiant_Sync_Template_Admin::url( 'edit', array( 'id' => $tc_tpl['id'] ) ) ); ?>"><strong><?php echo esc_html( $tc_tpl['name'] ); ?></strong></a>
							<?php if ( 'publish' !== $tc_tpl['status'] ) : ?>
								<span class="tc-badge tc-badge-draft"><?php esc_html_e( 'Draft', 'tcgiant-sync' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Language', 'tcgiant-sync' ); ?>"><?php echo esc_html( $tc_langs[ $tc_tpl['lang'] ] ?? $tc_tpl['lang'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'Version', 'tcgiant-sync' ); ?>"><?php echo esc_html( (string) $tc_tpl['version'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'Last saved', 'tcgiant-sync' ); ?>"><?php echo esc_html( $tc_tpl['modified'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'Set for', 'tcgiant-sync' ); ?>"><?php echo esc_html( $tc_set ? implode( ', ', $tc_set ) : __( 'nothing yet', 'tcgiant-sync' ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Sent with', 'tcgiant-sync' ); ?>">
							<?php
							/* translators: %d: number of listings */
							echo esc_html( sprintf( _n( '%d listing', '%d listings', $tc_sent, 'tcgiant-sync' ), $tc_sent ) );
							?>
						</td>
						<td class="tc-template-actions" data-label="<?php esc_attr_e( 'Actions', 'tcgiant-sync' ); ?>">
							<a href="<?php echo esc_url( TCGiant_Sync_Template_Admin::url( 'edit', array( 'id' => $tc_tpl['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'tcgiant-sync' ); ?></a>
							· <a href="<?php echo esc_url( TCGiant_Sync_Template_Admin::url( 'preview', array( 'id' => $tc_tpl['id'] ) ) ); ?>"><?php esc_html_e( 'Preview', 'tcgiant-sync' ); ?></a>
							· <a href="<?php echo esc_url( $tc_action( 'duplicate', $tc_tpl['id'] ) ); ?>"><?php esc_html_e( 'Duplicate', 'tcgiant-sync' ); ?></a>
							· <a href="<?php echo esc_url( $tc_action( 'export', $tc_tpl['id'] ) ); ?>"><?php esc_html_e( 'Export JSON', 'tcgiant-sync' ); ?></a>
							<?php if ( ! $tc_use['default'] && 'publish' === $tc_tpl['status'] ) : ?>
								· <a href="<?php echo esc_url( $tc_action( 'default', $tc_tpl['id'] ) ); ?>"><?php esc_html_e( 'Set as default', 'tcgiant-sync' ); ?></a>
							<?php endif; ?>
							<?php if ( ! $tc_set ) : ?>
								· <a href="<?php echo esc_url( $tc_action( 'delete', $tc_tpl['id'] ) ); ?>" class="tc-danger-link" onclick="return confirm('<?php echo esc_js( __( 'Delete this template and its saved versions? This cannot be undone.', 'tcgiant-sync' ) ); ?>');"><?php esc_html_e( 'Delete', 'tcgiant-sync' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<div class="tc-row-2col" style="margin-top:20px;">
	<div class="tc-card">
		<h2><span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e( 'New template', 'tcgiant-sync' ); ?></h2>
		<p class="tc-hint"><?php esc_html_e( 'Each starts as a draft, with nothing assigned. Pick colours, a logo and which parts show on the design form; the HTML is there if you want it.', 'tcgiant-sync' ); ?></p>
		<?php foreach ( $tc_starters as $tc_slug => $tc_starter ) : ?>
			<div class="tc-starter-row">
				<div>
					<strong><?php echo esc_html( $tc_starter['name'] ); ?></strong>
					<p class="tc-hint" style="margin:2px 0 0;"><?php echo esc_html( $tc_starter['summary'] ); ?></p>
				</div>
				<a class="tc-button secondary" href="<?php echo esc_url( $tc_action( 'new', 0, array( 'starter' => $tc_slug ) ) ); ?>"><?php esc_html_e( 'Start from this', 'tcgiant-sync' ); ?></a>
			</div>
		<?php endforeach; ?>
		<div class="tc-starter-row">
			<div>
				<strong><?php esc_html_e( 'Blank', 'tcgiant-sync' ); ?></strong>
				<p class="tc-hint" style="margin:2px 0 0;"><?php esc_html_e( 'Just the description, for writing your own.', 'tcgiant-sync' ); ?></p>
			</div>
			<a class="tc-button secondary" href="<?php echo esc_url( $tc_action( 'new' ) ); ?>"><?php esc_html_e( 'Start blank', 'tcgiant-sync' ); ?></a>
		</div>
	</div>

	<div class="tc-card">
		<h2><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Import a template file', 'tcgiant-sync' ); ?></h2>
		<p class="tc-hint"><?php esc_html_e( 'A file exported from TCGiant Sync (.tcgiant-template.json). It comes in as a draft. For a WP-Lister template, use Switching from WP-Lister above.', 'tcgiant-sync' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="tcgiant_tpl_import">
			<?php wp_nonce_field( 'tcgiant_tpl_import' ); ?>
			<div class="tc-field">
				<input type="file" name="template_file" accept=".json,application/json">
			</div>
			<div class="tc-field">
				<label class="tc-label" for="tc-template-json"><?php esc_html_e( 'Or paste it here', 'tcgiant-sync' ); ?></label>
				<textarea class="tc-input" id="tc-template-json" name="template_json" rows="4" style="font-family:monospace;font-size:12px;"></textarea>
			</div>
			<button type="submit" class="tc-button"><?php esc_html_e( 'Import as a draft', 'tcgiant-sync' ); ?></button>
		</form>
	</div>
</div>

<?php include TCGIANT_SYNC_PATH . 'admin/views/template-guide.php'; ?>
