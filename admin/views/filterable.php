<?php
/**
 * Settings: Filterable item specifics (3.35.0).
 *
 * The item specifics on the shop's products, counted, each with how many
 * products have it and how many values it has; a tick makes one a global
 * attribute the shop's filters can use (TCGiant_Sync_Filterable). Its form
 * and Count again post to TCGiant_Sync_Filterable::handle_form(), with the
 * section's nonce, which needs manage_options.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$tc_fs       = TCGiant_Sync_Filterable::screen();
$tc_fs_state = $tc_fs['state'];
$tc_fs_date  = function ( $time ) {
	return function_exists( 'wp_date' ) ? wp_date( 'j M Y, H:i', (int) $time ) : gmdate( 'j M Y, H:i', (int) $time ) . ' UTC';
};
$tc_fs_any   = false;
foreach ( $tc_fs['rows'] as $tc_fs_row ) {
	$tc_fs_any = $tc_fs_any || '' === $tc_fs_row['refusal'];
}
?>

<div class="tc-card tc-filterable" id="tc-filterable" style="margin-top:20px;">
	<h2><span class="dashicons dashicons-filter"></span> <?php esc_html_e( 'Filterable item specifics', 'tcgiant-sync' ); ?></h2>
	<?php TCGiant_Sync_Filterable::show_notice(); ?>
	<p class="tc-hint"><?php esc_html_e( 'The item specifics imported from eBay - Brand, Set, Grade and the rest - are saved on each product as attributes of its own, which WooCommerce\'s filters (the Product Filters block, the Filter Products by Attribute widget) cannot use. Tick the ones your customers should be able to filter by. Each becomes a global attribute (Products > Attributes) on every product that has it, with the same value, in the same place on the product page, and imports and syncs from then on keep it so. Nothing is sent to eBay, and what a push sends does not change.', 'tcgiant-sync' ); ?></p>

	<?php if ( ! empty( $tc_fs_state['run'] ) ) : ?>
		<div class="tc-filterable-run">
			<?php if ( 'running' === ( $tc_fs_state['status'] ?? '' ) ) : ?>
				<strong><?php esc_html_e( 'Converting your products now.', 'tcgiant-sync' ); ?></strong>
				<?php
				/* translators: 1: products looked at, 2: products in all, 3: converted, 4: turned back, 5: skipped */
				echo esc_html( sprintf( __( '%1$s of about %2$s products looked at: %3$s converted, %4$s turned back, %5$s skipped so far. Reload this page to see how far it has got.', 'tcgiant-sync' ), number_format_i18n( (int) $tc_fs_state['looked'] ), number_format_i18n( max( (int) $tc_fs_state['looked'], (int) $tc_fs_state['total'] ) ), number_format_i18n( (int) $tc_fs_state['converted'] ), number_format_i18n( (int) $tc_fs_state['undone'] ), number_format_i18n( array_sum( array_map( 'intval', (array) $tc_fs_state['skipped'] ) ) ) ) );
				?>
			<?php else : ?>
				<?php
				/* translators: 1: a date and time, 2: products converted, 3: turned back, 4: given a brand */
				echo esc_html( sprintf( __( 'Last converted %1$s: %2$s products converted, %3$s turned back, %4$s given a brand.', 'tcgiant-sync' ), $tc_fs_date( (int) ( $tc_fs_state['ended'] ?? $tc_fs_state['touched'] ?? 0 ) ), number_format_i18n( (int) $tc_fs_state['converted'] ), number_format_i18n( (int) $tc_fs_state['undone'] ), number_format_i18n( (int) $tc_fs_state['brands_added'] ) ) );
				?>
			<?php endif; ?>
			<?php if ( ! empty( $tc_fs_state['skipped'] ) ) : ?>
				<ul class="tc-filterable-skipped">
					<?php foreach ( (array) $tc_fs_state['skipped'] as $tc_fs_why => $tc_fs_n ) : ?>
						<li>
							<?php
							/* translators: 1: a number of products, 2: why they were left as they are */
							echo esc_html( sprintf( _n( '%1$s product left as it is: %2$s.', '%1$s products left as they are: %2$s.', (int) $tc_fs_n, 'tcgiant-sync' ), number_format_i18n( (int) $tc_fs_n ), TCGiant_Sync_Filterable::reason_words( (string) $tc_fs_why ) ) );
							$tc_fs_ids = array_map( 'intval', (array) ( $tc_fs_state['samples'][ $tc_fs_why ] ?? array() ) );
							if ( $tc_fs_ids ) {
								/* translators: %s: product IDs */
								echo ' ' . esc_html( sprintf( __( 'For example: #%s.', 'tcgiant-sync' ), implode( ', #', $tc_fs_ids ) ) );
							}
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<p class="tc-filterable-count">
		<?php if ( $tc_fs['counted'] ) : ?>
			<?php
			/* translators: 1: a date and time, 2: a number of products */
			echo esc_html( sprintf( __( 'Counted %1$s, over %2$s products.', 'tcgiant-sync' ), $tc_fs_date( $tc_fs['counted'] ), number_format_i18n( $tc_fs['products'] ) ) );
			if ( $tc_fs['counting'] ) {
				echo ' ' . esc_html__( 'Counting again now: reload this page in a minute.', 'tcgiant-sync' );
			}
			?>
		<?php elseif ( $tc_fs['counting'] ) : ?>
			<?php esc_html_e( 'Counting the item specifics on your products. Nothing is changed while it counts; reload this page in a minute.', 'tcgiant-sync' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Not counted yet. Press Count again.', 'tcgiant-sync' ); ?>
		<?php endif; ?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tc-filterable-again">
		<input type="hidden" name="action" value="<?php echo esc_attr( TCGiant_Sync_Filterable::FORM ); ?>">
		<input type="hidden" name="do" value="count">
		<?php wp_nonce_field( TCGiant_Sync_Filterable::FORM ); ?>
		<button type="submit" class="tc-button secondary"><?php esc_html_e( 'Count again', 'tcgiant-sync' ); ?></button>
	</form>

	<?php if ( $tc_fs['rows'] ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( TCGiant_Sync_Filterable::FORM ); ?>">
			<input type="hidden" name="do" value="save">
			<?php wp_nonce_field( TCGiant_Sync_Filterable::FORM ); ?>
			<table class="widefat striped tc-templates-table tc-filterable-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Item specific', 'tcgiant-sync' ); ?></th>
						<th><?php esc_html_e( 'Products', 'tcgiant-sync' ); ?></th>
						<th><?php esc_html_e( 'Values', 'tcgiant-sync' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'tcgiant-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tc_fs['rows'] as $tc_fs_n => $tc_fs_row ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Item specific', 'tcgiant-sync' ); ?>">
								<label>
									<input type="checkbox" name="names[]" value="<?php echo esc_attr( $tc_fs_row['name'] ); ?>"<?php checked( $tc_fs_row['chosen'] ); ?><?php disabled( '' !== $tc_fs_row['refusal'] ); ?>>
									<strong><?php echo esc_html( $tc_fs_row['name'] ); ?></strong>
								</label>
							</td>
							<td data-label="<?php esc_attr_e( 'Products', 'tcgiant-sync' ); ?>"><?php echo esc_html( number_format_i18n( $tc_fs_row['products'] ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Values', 'tcgiant-sync' ); ?>">
								<?php
								if ( $tc_fs_row['over'] ) {
									/* translators: %s: 2,000 */
									echo esc_html( sprintf( __( 'over %s', 'tcgiant-sync' ), number_format_i18n( TCGiant_Sync_Filterable::REFUSE_VALUES ) ) );
								} else {
									echo esc_html( number_format_i18n( $tc_fs_row['values'] ) );
								}
								?>
							</td>
							<td data-label="<?php esc_attr_e( 'Notes', 'tcgiant-sync' ); ?>" class="tc-filterable-notes">
								<?php
								$tc_fs_notes = array();
								if ( $tc_fs_row['chosen'] ) {
									/* translators: 1: products with it as a filter, 2: products with it */
									$tc_fs_notes[] = sprintf( __( 'Filterable on %1$s of the %2$s products that have it.', 'tcgiant-sync' ), number_format_i18n( $tc_fs_row['converted'] ), number_format_i18n( $tc_fs_row['products'] ) );
									if ( $tc_fs_row['gone'] ) {
										$tc_fs_notes[] = __( 'Its global attribute has been deleted. Save with it ticked to make it again.', 'tcgiant-sync' );
									}
								} elseif ( $tc_fs_row['suggested'] && '' === $tc_fs_row['refusal'] ) {
									$tc_fs_notes[] = __( 'Suggested.', 'tcgiant-sync' );
								}
								if ( '' !== $tc_fs_row['refusal'] ) {
									$tc_fs_notes[] = $tc_fs_row['refusal'];
								}
								if ( '' !== $tc_fs_row['warning'] ) {
									$tc_fs_notes[] = $tc_fs_row['warning'];
								}
								echo esc_html( implode( ' ', $tc_fs_notes ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $tc_fs['brands_here'] ) : ?>
				<p class="tc-filterable-brands">
					<input type="hidden" name="brands_drawn" value="1">
					<label>
						<input type="checkbox" name="brands" value="1"<?php checked( $tc_fs['brands'] ); ?>>
						<?php esc_html_e( 'Also put Brand into WooCommerce Brands (Products > Brands) when Brand is ticked, for products that have no brand yet. A brand you have set is never replaced.', 'tcgiant-sync' ); ?>
					</label>
				</p>
			<?php else : ?>
				<p class="tc-hint"><?php esc_html_e( 'WooCommerce Brands is not on this site (it comes with WooCommerce 9.6 and later), so Brand can only be an attribute filter here.', 'tcgiant-sync' ); ?></p>
			<?php endif; ?>

			<p class="tc-hint"><?php esc_html_e( 'Item specifics used for variations, such as a Size the customer picks, are left as they are. Taking a tick off turns that item specific back into each product\'s own attribute; its global attribute and values stay in Products > Attributes, for you to delete if you want. Take the tick off first: deleting a global attribute that is still ticked takes that item specific off every product that has it, and a push then no longer sends it. A value that differs from one already there only in its capitals ("TOPPS" and "Topps") is kept as eBay has it, as a value of its own, so a filter can list both.', 'tcgiant-sync' ); ?></p>

			<?php if ( $tc_fs_any ) : ?>
				<button type="submit" class="tc-button"><?php esc_html_e( 'Save filterable item specifics', 'tcgiant-sync' ); ?></button>
			<?php endif; ?>
		</form>
	<?php elseif ( $tc_fs['counted'] ) : ?>
		<p class="tc-hint"><?php esc_html_e( 'None of your products has an item specific yet.', 'tcgiant-sync' ); ?></p>
	<?php endif; ?>
</div>
