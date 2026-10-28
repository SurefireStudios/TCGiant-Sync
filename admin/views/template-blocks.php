<?php
/**
 * Listing Templates: blocks.
 *
 * The two policy blocks are written from the shop's Business Policies and
 * shown here as they would render now; text blocks are the shop's own, one
 * text per language.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$tc_export   = class_exists( 'TCGiant_Sync_Exporter' ) ? TCGiant_Sync_Exporter::instance()->get_export_settings( 0 ) : array();
$tc_policies = TCGiant_Sync_Template_Policies::blocks( $tc_export, true );
$tc_fetched  = (int) get_option( 'tcgiant_export_policies_fetched', 0 );
$tc_blocks   = TCGiant_Sync_Templates::blocks();
$tc_langs    = array( 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'es' => 'Español' );
$tc_policy_blocks = array(
	'returns'  => array( __( 'Returns', 'tcgiant-sync' ), '{{{policy.returns}}}', __( 'From the return policy a push sends: whether returns are accepted, the period, and who pays return shipping.', 'tcgiant-sync' ) ),
	'handling' => array( __( 'Handling time', 'tcgiant-sync' ), '{{{policy.handling}}}', __( 'From the shipping policy a push sends, the product\'s own where it has one: the handling time only, never services or costs.', 'tcgiant-sync' ) ),
);
?>

<div class="tc-card">
	<h2><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Policy blocks', 'tcgiant-sync' ); ?></h2>
	<p class="tc-hint">
		<?php esc_html_e( 'Written from your eBay Business Policies, so the description never disagrees with the terms eBay shows. In English only in this version.', 'tcgiant-sync' ); ?>
		<?php
		if ( $tc_fetched ) {
			/* translators: %s: date */
			echo esc_html( sprintf( __( 'Policies last fetched %s.', 'tcgiant-sync' ), wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $tc_fetched ) ) );
		} else {
			esc_html_e( 'Policies have not been fetched since this version.', 'tcgiant-sync' );
		}
		?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-settings' ) ); ?>"><?php esc_html_e( 'Fetch / Refresh Policies from eBay on the Settings screen', 'tcgiant-sync' ); ?></a>
	</p>
	<table class="widefat striped">
		<tbody>
			<?php foreach ( $tc_policy_blocks as $tc_key => $tc_block ) : ?>
				<tr>
					<td style="width:22%;"><strong><?php echo esc_html( $tc_block[0] ); ?></strong><br><code><?php echo esc_html( $tc_block[1] ); ?></code></td>
					<td>
						<p class="tc-hint" style="margin-top:0;"><?php echo esc_html( $tc_block[2] ); ?></p>
						<?php if ( '' !== $tc_policies[ $tc_key ] ) : ?>
							<p><?php echo esc_html( wp_strip_all_tags( $tc_policies[ $tc_key ] ) ); ?></p>
						<?php else : ?>
							<p class="tc-warning-text"><?php echo esc_html( $tc_policies['warnings'][ $tc_key ] ?? __( 'Nothing to show.', 'tcgiant-sync' ) ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<div class="tc-card" style="margin-top:20px;">
	<h2><span class="dashicons dashicons-text"></span> <?php esc_html_e( 'Text blocks', 'tcgiant-sync' ); ?></h2>
	<p class="tc-hint"><?php esc_html_e( 'Text you use in several templates - about your shop, how you grade, how you pack. Put one in a template with {{{block.name}}}. A template uses the text in its own language, and shows nothing if there is none.', 'tcgiant-sync' ); ?></p>

	<?php foreach ( array_merge( $tc_blocks, array( null ) ) as $tc_block ) : ?>
		<details class="tc-block"<?php echo null === $tc_block && empty( $tc_blocks ) ? ' open' : ''; ?>>
			<summary>
				<?php
				if ( $tc_block ) {
					echo esc_html( $tc_block['label'] ) . ' <code>{{{block.' . esc_html( $tc_block['slug'] ) . '}}}</code>';
				} else {
					esc_html_e( 'New block', 'tcgiant-sync' );
				}
				?>
			</summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tcgiant_tpl_block">
				<?php wp_nonce_field( 'tcgiant_tpl_block' ); ?>
				<div class="tc-field">
					<label class="tc-label"><?php esc_html_e( 'Name', 'tcgiant-sync' ); ?>
						<input type="text" class="tc-input" name="label" value="<?php echo esc_attr( $tc_block ? $tc_block['label'] : '' ); ?>">
					</label>
				</div>
				<div class="tc-field">
					<label class="tc-label"><?php esc_html_e( 'Short name, for the template (letters, numbers, hyphens)', 'tcgiant-sync' ); ?>
						<input type="text" class="tc-input" name="slug" value="<?php echo esc_attr( $tc_block ? $tc_block['slug'] : '' ); ?>" <?php echo $tc_block ? 'readonly' : ''; ?>>
					</label>
				</div>
				<?php foreach ( $tc_langs as $tc_code => $tc_lang ) : ?>
					<div class="tc-field">
						<label class="tc-label"><?php echo esc_html( $tc_lang ); ?>
							<textarea class="tc-input tc-code" name="html[<?php echo esc_attr( $tc_code ); ?>]" rows="4"><?php echo esc_textarea( $tc_block ? (string) ( $tc_block['html'][ $tc_code ] ?? '' ) : '' ); ?></textarea>
						</label>
					</div>
				<?php endforeach; ?>
				<button type="submit" class="tc-button"><?php esc_html_e( 'Save block', 'tcgiant-sync' ); ?></button>
				<?php if ( $tc_block ) : ?>
					<button type="submit" class="tc-button secondary" name="delete" value="1" onclick="return confirm('<?php echo esc_js( __( 'Delete this block? Templates that use it will show nothing in its place.', 'tcgiant-sync' ) ); ?>');"><?php esc_html_e( 'Delete', 'tcgiant-sync' ); ?></button>
				<?php endif; ?>
			</form>
		</details>
	<?php endforeach; ?>
</div>
