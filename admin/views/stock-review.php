<?php
/**
 * Stock Review Page
 *
 * Lists products whose eBay listing has ended but which WooCommerce still shows
 * as in stock, and settles them from eBay's own figures.
 *
 * An ended listing is never imported again, so nothing in the ordinary sync can
 * put its stock right. That is what this screen is for: recovery for stock left
 * wrong by the fault fixed in 3.7.11 and 3.7.12, and a standing check afterwards.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$per_page  = 50;
$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$all_ids   = TCGiant_Sync_Inventory::find_unsettled_ended_products();
$total     = count( $all_ids );
$total_pgs = (int) ceil( $total / $per_page );
$page_ids  = array_slice( $all_ids, ( $paged - 1 ) * $per_page, $per_page );
$base_url  = admin_url( 'admin.php?page=tcgiant-stock-review' );

// Products that sold out on eBay and are still in the catalogue.
//
// Separate from the list below, which is about stock that was never
// settled. These were settled - correctly, from eBay's own figures - and
// came out at nothing, but removal used to live on a single code path and
// whichever path noticed the ending first decided whether it ever ran.
//
// Not while "Remove products when their eBay listing sells out?" is off: the
// shop has said to keep them, and the button would only keep every one.
$tc_review_settings = get_option( 'tcgiant_sync_ebay_settings', array() );
$tc_remove_sold     = ! ( is_array( $tc_review_settings ) && isset( $tc_review_settings['remove_sold_products'] ) && empty( $tc_review_settings['remove_sold_products'] ) );
$sold_out_ids       = $tc_remove_sold ? TCGiant_Sync_Inventory::find_sold_out_not_removed() : array();

// Out of stock and settled before 3.27.0, which kept no eBay figures: some
// sold on the website, so they are listed apart, to check one by one and
// trash by hand. The newest hundred, and how many there are.
$tc_unverified_total = $tc_remove_sold ? TCGiant_Sync_Inventory::count_sold_out_unverified() : 0;
$tc_unverified_ids   = $tc_unverified_total > 0 ? TCGiant_Sync_Inventory::find_sold_out_unverified( 100 ) : array();

// Stock pushes eBay still refused once every try was spent.
$push_failures = TCGiant_Sync_Inventory::push_failures();

// A product imported from a relist beside another still on the listing it
// was relisted from: possibly the same item twice. Only when the shop asks:
// it is the heaviest question on this page, and the answer changes rarely.
// Read newest first, a bounded stretch a request; "Look further back" goes
// on from where it stopped (relist_before).
$tc_pairs_asked  = ! empty( $_GET['relist_pairs'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tc_pairs_before = isset( $_GET['relist_before'] ) ? absint( $_GET['relist_before'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tc_pairs        = $tc_pairs_asked ? TCGiant_Sync_Inventory::find_relist_pairs( 200, $tc_pairs_before ) : array( 'pairs' => array(), 'checked' => 0, 'next' => 0 );
$relist_pairs    = $tc_pairs['pairs'];
$tc_pairs_more   = $tc_pairs_asked && (int) $tc_pairs['next'] > 0
	? admin_url( 'admin.php?page=tcgiant-stock-review&relist_pairs=1&relist_before=' . (int) $tc_pairs['next'] ) . '#tc-relist-pairs'
	: '';

// Probably sold on the website and then put back in stock by the settle
// before 3.27.0, which wrote eBay's figure over WooCommerce's.
$restocked = TCGiant_Sync_Inventory::find_restocked_after_website_sale();

// Ended on eBay with more unsold than WooCommerce holds - kept, not applied.
$unsold_at_end = TCGiant_Sync_Inventory::find_unsold_at_end();

// Listings the delta sync had to step over.
$held_back = TCGiant_Sync_Importer::held_back_listings();

// Products from listings on other eBay sites, while only the Primary eBay
// Marketplace's are imported - see TCGiant_Sync_Sites. Never offered while
// every site is imported: then they are what the shop asked for.
$tc_sites_on        = TCGiant_Sync_Sites::primary_only();
$tc_other_ids       = $tc_sites_on ? TCGiant_Sync_Sites::find_other_site_products() : array();
$tc_other_unchecked = $tc_sites_on ? TCGiant_Sync_Sites::count_unchecked_products() : 0;

$tc_ajax_url = admin_url( 'admin-ajax.php' );
$tc_nonce    = wp_create_nonce( 'tcgiant_sync_ajax' );
?>

<div class="wrap tc-dashboard-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Stock Review', 'tcgiant-sync' ); ?></h1>
	<hr class="wp-header-end" />

	<?php TCGiant_Sync_Admin::instance()->render_tabs( 'stock_review' ); ?>

	<?php if ( ! empty( $sold_out_ids ) ) : ?>
		<div class="notice notice-warning" style="margin-top:16px;padding:12px 14px;">
			<p style="margin:0 0 6px;">
				<strong>
					<?php printf( esc_html( _n( '%s product sold out on eBay and is still here.', '%s products sold out on eBay and are still here.', count( $sold_out_ids ), 'tcgiant-sync' ) ), number_format_i18n( count( $sold_out_ids ) ) ); ?>
				</strong>
			</p>
			<p style="margin:0 0 10px;">
				<?php esc_html_e( 'Their stock was set correctly, but they were never removed. Until now the plugin could only remove a sold-out product if one particular sync noticed the sale first, and on a large catalogue it often did not. That is fixed for future sales; these are the ones it missed.', 'tcgiant-sync' ); ?>
			</p>
			<p style="margin:0 0 10px;">
				<?php esc_html_e( 'They go to the trash, not away for good, and anything your settings protect is left alone with the reason written to the log.', 'tcgiant-sync' ); ?>
			</p>
			<p style="margin:0 0 10px;">
				<?php esc_html_e( 'Each one is here because eBay\'s own figures for its listing say every unit sold. Products you pushed to eBay yourself, and products in your Preserve Categories, are not listed: they are always kept.', 'tcgiant-sync' ); ?>
			</p>
			<p style="margin:0;">
				<button type="button" id="tc-remove-sold" class="button button-primary"><?php esc_html_e( 'Move them to the trash', 'tcgiant-sync' ); ?></button>
				<span id="tc-remove-sold-status" style="margin-left:10px;color:#555;"></span>
			</p>
		</div>

		<script>
			// Self-contained: this panel shows whether or not the list below has
			// anything in it, so it cannot rely on that section's modal.
			( function ( $ ) {
				var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
				var nonce   = '<?php echo esc_js( wp_create_nonce( 'tcgiant_sync_ajax' ) ); ?>';
				var $btn    = $( '#tc-remove-sold' );
				var $say    = $( '#tc-remove-sold-status' );

				$btn.on( 'click', function () {
					if ( ! confirm( '<?php echo esc_js( __( 'Move every sold-out product on this list to the trash?', 'tcgiant-sync' ) ); ?>' ) ) { return; }

					$btn.prop( 'disabled', true );
					$say.text( '<?php echo esc_js( __( 'Starting...', 'tcgiant-sync' ) ); ?>' );

					$.post( ajaxUrl, { action: 'tcgiant_job_start', type: 'bulk_remove_sold', select_all: 1, _ajax_nonce: nonce }, function ( r ) {
						if ( ! r.success ) {
							$btn.prop( 'disabled', false );
							$say.text( r.data && r.data.message ? r.data.message : 'Could not start.' );
							return;
						}

						next( r.data.job_id, r.data.total );
					} );
				} );

				function next( jobId, total ) {
					$.post( ajaxUrl, { action: 'tcgiant_job_process', job_id: jobId, _ajax_nonce: nonce }, function ( r ) {
						if ( ! r.success ) {
							$say.text( r.data && r.data.message ? r.data.message : 'Stopped.' );
							$btn.prop( 'disabled', false );
							return;
						}

						var done = r.data.processed || 0;
						var kept = r.data.kept || 0;
						$say.text( done + ' / ' + total + ( kept ? ' (' + kept + ' kept — see the log)' : '' ) );

						if ( 'completed' === r.data.status || done >= total ) {
							$say.text( '<?php echo esc_js( __( 'Done. Reloading...', 'tcgiant-sync' ) ); ?>' );
							location.reload();
							return;
						}

						next( jobId, total );
					} );
				}
			}( jQuery ) );
		</script>
	<?php endif; ?>

	<?php if ( ! empty( $tc_unverified_ids ) ) : ?>
		<?php $tc_trash_off_now = TCGiant_Sync_Sites::trash_is_off(); ?>
		<div class="notice notice-info" id="tc-sold-out-unverified" style="margin-top:16px;padding:12px 14px;">
			<p style="margin:0 0 6px;">
				<strong><?php printf( esc_html( _n( '%s product ended out of stock before version 3.27.0.', '%s products ended out of stock before version 3.27.0.', $tc_unverified_total, 'tcgiant-sync' ) ), esc_html( number_format_i18n( $tc_unverified_total ) ) ); ?></strong>
			</p>
			<p style="margin:0 0 10px;"><?php esc_html_e( 'Those versions kept no record of what sold on eBay, so whether each sold there or on your website cannot be told from here, and none is moved to the trash for you. Open each one: if it has gone for good, move it to the trash from its own link. Products you pushed to eBay yourself, and products in your Preserve Categories, are not listed.', 'tcgiant-sync' ); ?></p>
			<p style="margin:0 0 10px;"><?php esc_html_e( 'First check on eBay that its listing has really ended. Those versions could record a listing as ended when eBay had refused to end it, and moving a product to the trash does not end its listing: it would go on selling, with its product in the trash.', 'tcgiant-sync' ); ?></p>
			<?php if ( $tc_unverified_total > count( $tc_unverified_ids ) ) : ?>
				<p style="margin:0 0 10px;"><?php /* translators: 1: products shown, 2: products in all */ printf( esc_html__( 'The newest %1$s of %2$s are shown.', 'tcgiant-sync' ), esc_html( number_format_i18n( count( $tc_unverified_ids ) ) ), esc_html( number_format_i18n( $tc_unverified_total ) ) ); ?></p>
			<?php endif; ?>
			<?php if ( $tc_trash_off_now ) : ?>
				<p style="margin:0 0 10px;"><?php esc_html_e( 'The trash is switched off on this website (EMPTY_TRASH_DAYS is 0), so WordPress would delete a product for good instead of moving it to the trash. No trash link is offered here.', 'tcgiant-sync' ); ?></p>
			<?php endif; ?>
			<table class="widefat striped" style="margin-bottom:10px;">
				<thead><tr><th><?php esc_html_e( 'Product', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'eBay item', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Settled', 'tcgiant-sync' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $tc_unverified_ids as $tc_uid ) : ?>
					<?php $tc_up = wc_get_product( $tc_uid ); ?>
					<?php if ( ! $tc_up ) { continue; } ?>
					<?php $tc_trash_link = $tc_trash_off_now ? '' : (string) get_delete_post_link( $tc_uid ); ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $tc_uid ) ); ?>"><?php echo esc_html( $tc_up->get_name() ); ?></a> <span style="color:#777;">#<?php echo esc_html( (string) $tc_uid ); ?></span></td>
						<?php $tc_uitem = (string) get_post_meta( $tc_uid, '_ebay_item_id', true ); ?>
						<td><a href="<?php echo esc_url( 'https://www.ebay.com/itm/' . rawurlencode( $tc_uitem ) ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Check on eBay that it has ended', 'tcgiant-sync' ); ?>"><?php echo esc_html( $tc_uitem ); ?> &nearr;</a></td>
						<td><?php echo esc_html( (string) get_post_meta( $tc_uid, '_ebay_stock_settled_at', true ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( (string) get_edit_post_link( $tc_uid ) ); ?>"><?php esc_html_e( 'Edit', 'tcgiant-sync' ); ?></a>
							<?php if ( '' !== $tc_trash_link ) : ?>
								| <a href="<?php echo esc_url( $tc_trash_link ); ?>" style="color:#b32d2e;"><?php esc_html_e( 'Trash', 'tcgiant-sync' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $tc_other_ids ) || $tc_other_unchecked > 0 ) : ?>
		<?php
		$tc_other_total = count( $tc_other_ids );
		$tc_other_paged = isset( $_GET['other_paged'] ) ? max( 1, (int) $_GET['other_paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tc_other_pages = (int) ceil( $tc_other_total / $per_page );
		$tc_other_rows  = array_slice( $tc_other_ids, ( $tc_other_paged - 1 ) * $per_page, $per_page );
		$tc_other_sites = array();
		foreach ( TCGiant_Sync_Sites::count_other_site_products_by_site() as $tc_site_label => $tc_site_n ) {
			$tc_other_sites[] = sprintf( '%s: %s', $tc_site_label, number_format_i18n( $tc_site_n ) );
		}
		$tc_settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$tc_preserve = is_array( $tc_settings ) && isset( $tc_settings['preserve_woo_category_ids'] ) && is_array( $tc_settings['preserve_woo_category_ids'] ) ? $tc_settings['preserve_woo_category_ids'] : array();

		// What the button would move: the list less what Preserve Categories
		// keep, so its question gives the number that would go. With the trash
		// switched off WordPress deletes instead, so there is no button at all.
		// Nor while the last full sync found nothing on the Primary eBay
		// Marketplace: it is almost certainly set wrongly, and this list is then
		// the shop's own catalogue. The cleanup refuses as well.
		$tc_trash_off = TCGiant_Sync_Sites::trash_is_off();
		$tc_mismatch  = null !== TCGiant_Sync_Sites::mismatch();
		$tc_to_move   = $tc_other_total > 0 ? max( 0, $tc_other_total - TCGiant_Sync_Sites::count_preserved_other_site_products( $tc_preserve ) ) : 0;
		$tc_can_move  = ! $tc_trash_off && ! $tc_mismatch && $tc_to_move > 0;

		// The button acts on this list as drawn now - see remember_shown_list().
		$tc_list_token = $tc_can_move ? TCGiant_Sync_Sites::remember_shown_list( $tc_other_ids ) : '';
		?>
		<div class="notice notice-warning" id="tc-other-sites" style="margin-top:16px;padding:12px 14px;">
			<?php if ( $tc_other_total > 0 ) : ?>
				<p style="margin:0 0 6px;">
					<strong>
						<?php
						printf(
							/* translators: 1: number of products, 2: per site, e.g. "eBay United Kingdom: 30, eBay Germany: 7" */
							esc_html( _n( '%1$s product on your website came from a listing on another eBay site (%2$s).', '%1$s products on your website came from listings on other eBay sites (%2$s).', $tc_other_total, 'tcgiant-sync' ) ),
							esc_html( number_format_i18n( $tc_other_total ) ),
							esc_html( implode( ', ', $tc_other_sites ) )
						);
						?>
					</strong>
				</p>
				<p style="margin:0 0 10px;">
					<?php
					printf(
						/* translators: %s: the shop's eBay site, e.g. "eBay United States" */
						esc_html__( 'Import listings from is set to %s only, so no more will be imported, but these keep syncing until you remove them. Their prices were copied from eBay without currency conversion.', 'tcgiant-sync' ),
						esc_html( TCGiant_Sync_Sites::primary_label() )
					);
					?>
				</p>
				<?php if ( $tc_trash_off ) : ?>
					<p style="margin:0 0 10px;"><?php esc_html_e( 'The trash is switched off on this website (EMPTY_TRASH_DAYS is 0), so WordPress would delete these products for good instead of moving them to the trash. This page therefore does not remove them. To remove them, switch the trash back on, or delete them yourself.', 'tcgiant-sync' ); ?></p>
				<?php elseif ( $tc_mismatch ) : ?>
					<p style="margin:0 0 10px;">
						<?php
						printf(
							/* translators: %s: the shop's eBay site, e.g. "eBay United States" */
							esc_html__( 'The last full sync found no listings on %s, your Primary eBay Marketplace, so it is almost certainly set wrongly, and these are most likely your own products. This page does not remove them until you correct Primary eBay Marketplace in Settings, or a full sync finds listings on it.', 'tcgiant-sync' ),
							esc_html( TCGiant_Sync_Sites::primary_label() )
						);
						?>
					</p>
				<?php else : ?>
					<p style="margin:0 0 10px;"><?php esc_html_e( 'They go to the trash, not away for good. Their eBay listings are not changed. Anything your Preserve Categories protect is left alone, with the reason written to the log. Nothing changes until you press the button; this list is what it would do.', 'tcgiant-sync' ); ?></p>
				<?php endif; ?>
				<table class="widefat striped" style="margin-bottom:10px;">
					<thead><tr><th><?php esc_html_e( 'Product', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'eBay item', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Site', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Price', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Stock', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Status', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Would be', 'tcgiant-sync' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $tc_other_rows as $tc_pid ) : ?>
						<?php $tc_p = wc_get_product( $tc_pid ); ?>
						<?php if ( ! $tc_p ) { continue; } ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $tc_pid ) ); ?>"><?php echo esc_html( $tc_p->get_name() ); ?></a> <span style="color:#777;">#<?php echo esc_html( $tc_pid ); ?></span></td>
							<td><?php echo esc_html( (string) get_post_meta( $tc_pid, '_ebay_item_id', true ) ); ?></td>
							<td><?php echo esc_html( TCGiant_Sync_Sites::label( (string) get_post_meta( $tc_pid, '_ebay_site', true ) ) ); ?></td>
							<td><?php echo esc_html( (string) $tc_p->get_price() ); ?></td>
							<td><?php echo $tc_p->managing_stock() ? esc_html( (string) (int) $tc_p->get_stock_quantity() ) : esc_html( $tc_p->get_stock_status() ); ?></td>
							<td><?php echo esc_html( (string) get_post_status( $tc_pid ) ); ?></td>
							<td>
								<?php if ( $tc_trash_off ) : ?>
									<?php esc_html_e( 'Left here - the trash is switched off', 'tcgiant-sync' ); ?>
								<?php elseif ( $tc_mismatch ) : ?>
									<?php esc_html_e( 'Left here - check Primary eBay Marketplace first', 'tcgiant-sync' ); ?>
								<?php elseif ( ! empty( $tc_preserve ) && has_term( $tc_preserve, 'product_cat', $tc_pid ) ) : ?>
									<?php esc_html_e( 'Kept - in a preserved category', 'tcgiant-sync' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Moved to the trash', 'tcgiant-sync' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $tc_other_pages > 1 ) : ?>
					<p style="margin:0 0 10px;">
						<?php
						echo wp_kses_post( paginate_links( array(
							'base'      => $base_url . '%_%',
							'format'    => '&other_paged=%#%',
							'current'   => $tc_other_paged,
							'total'     => $tc_other_pages,
							'prev_text' => '‹',
							'next_text' => '›',
						) ) );
						?>
					</p>
				<?php endif; ?>
				<?php if ( $tc_can_move ) : ?>
					<p style="margin:0;">
						<button type="button" id="tc-trash-other-sites" class="button button-primary"><?php esc_html_e( 'Move them to the trash', 'tcgiant-sync' ); ?></button>
						<span id="tc-trash-other-sites-status" style="margin-left:10px;color:#555;"></span>
					</p>
				<?php elseif ( ! $tc_trash_off && ! $tc_mismatch ) : ?>
					<p style="margin:0;"><?php esc_html_e( 'Every one of them is in a category your Preserve Categories protect, so there is nothing to move to the trash.', 'tcgiant-sync' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $tc_other_unchecked > 0 ) : ?>
				<p style="margin:<?php echo $tc_other_total > 0 ? '10px' : '0'; ?> 0 0;">
					<?php
					printf(
						/* translators: %s: number of products */
						esc_html( _n( '%s linked product has not been checked yet. Run Import from eBay, Fetch Inventory to check it.', '%s linked products have not been checked yet. Run Import from eBay, Fetch Inventory to check them.', $tc_other_unchecked, 'tcgiant-sync' ) ),
						esc_html( number_format_i18n( $tc_other_unchecked ) )
					);
					?>
					<?php
					// The list is only of products whose site the scan has
					// recorded, so it grows while a full sync reads the pages -
					// after the first ones have gone to the trash, too. A shop
					// seeing that took the clean-up for broken.
					esc_html_e( 'This list fills in as a full sync reads your eBay listings and records which site each one is on, so more products appear here while a sync is running, including after you have moved some to the trash.', 'tcgiant-sync' );
					?>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( $tc_can_move ) : ?>
			<script>
				( function ( $ ) {
					var ajaxUrl = '<?php echo esc_url( $tc_ajax_url ); ?>';
					var nonce   = '<?php echo esc_js( $tc_nonce ); ?>';
					var list    = '<?php echo esc_js( $tc_list_token ); ?>';
					var $btn    = $( '#tc-trash-other-sites' );
					var $say    = $( '#tc-trash-other-sites-status' );

					$btn.on( 'click', function () {
						if ( ! confirm( '<?php echo esc_js( sprintf( /* translators: %s: number of products */ _n( 'Move %s product from another eBay site to the trash? Its eBay listing is not changed.', 'Move %s products from other eBay sites to the trash? Their eBay listings are not changed.', $tc_to_move, 'tcgiant-sync' ), number_format_i18n( $tc_to_move ) ) ); ?>' ) ) { return; }

						$btn.prop( 'disabled', true );
						$say.text( '<?php echo esc_js( __( 'Starting...', 'tcgiant-sync' ) ); ?>' );

						// The list this page shows, by its token: the server kept it.
						$.post( ajaxUrl, { action: 'tcgiant_job_start', type: 'bulk_trash_other_sites', select_all: 1, list: list, _ajax_nonce: nonce }, function ( r ) {
							if ( ! r.success ) {
								$btn.prop( 'disabled', false );
								$say.text( r.data && r.data.message ? r.data.message : 'Could not start.' );
								return;
							}
							next( r.data.job_id, r.data.total );
						} );
					} );

					function next( jobId, total ) {
						$.post( ajaxUrl, { action: 'tcgiant_job_process', job_id: jobId, _ajax_nonce: nonce }, function ( r ) {
							if ( ! r.success ) {
								$say.text( r.data && r.data.message ? r.data.message : 'Stopped.' );
								$btn.prop( 'disabled', false );
								return;
							}

							var done = r.data.processed || 0;
							var kept = r.data.kept || 0;
							$say.text( done + ' / ' + total + ( kept ? ' (' + kept + ' kept — see the log)' : '' ) );

							if ( 'completed' === r.data.status || done >= total ) {
								$say.text( '<?php echo esc_js( __( 'Done. Reloading...', 'tcgiant-sync' ) ); ?>' );
								location.reload();
								return;
							}

							next( jobId, total );
						} );
					}
				}( jQuery ) );
			</script>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! empty( $restocked ) || ! empty( $unsold_at_end ) ) : ?>
		<script>
			// Runs a Stock Review job over the ticked rows of one panel.
			window.tcRunStockJob = window.tcRunStockJob || function ( type, panel, button ) {
				var $ = jQuery;
				var $panel = $( panel );
				var ids = $panel.find( '.tc-row-cb:checked' ).map( function () { return parseInt( this.value, 10 ); } ).get();
				var $btn = button ? $( button ) : $panel.find( '.tc-panel-run' ).first();
				var $say = $panel.find( '.tc-panel-status' );

				if ( ! ids.length ) { $say.text( '<?php echo esc_js( __( 'Tick at least one product.', 'tcgiant-sync' ) ); ?>' ); return; }
				if ( ! confirm( $btn.data( 'confirm' ) ) ) { return; }

				$panel.find( '.tc-panel-run' ).prop( 'disabled', true );
				$say.text( '<?php echo esc_js( __( 'Starting...', 'tcgiant-sync' ) ); ?>' );

				var next = function ( jobId, total ) {
					$.post( '<?php echo esc_url( $tc_ajax_url ); ?>', { action: 'tcgiant_job_process', job_id: jobId, _ajax_nonce: '<?php echo esc_js( $tc_nonce ); ?>' }, function ( r ) {
						if ( ! r.success ) { $say.text( r.data && r.data.message ? r.data.message : 'Stopped.' ); $btn.prop( 'disabled', false ); return; }
						var done = r.data.processed || 0;
						$say.text( done + ' / ' + total );
						if ( 'complete' === r.data.status || done >= total ) { $say.text( '<?php echo esc_js( __( 'Done. Reloading...', 'tcgiant-sync' ) ); ?>' ); location.reload(); return; }
						next( jobId, total );
					} );
				};

				$.post( '<?php echo esc_url( $tc_ajax_url ); ?>', { action: 'tcgiant_job_start', type: type, product_ids: ids, _ajax_nonce: '<?php echo esc_js( $tc_nonce ); ?>' }, function ( r ) {
					if ( ! r.success ) { $btn.prop( 'disabled', false ); $say.text( r.data && r.data.message ? r.data.message : 'Could not start.' ); return; }
					next( r.data.job_id, r.data.total );
				} );
			};
		</script>
	<?php endif; ?>

	<?php if ( ! empty( $restocked ) ) : ?>
		<div class="notice notice-warning tc-stock-panel" id="tc-restocked" style="margin-top:16px;padding:12px 14px;">
			<p style="margin:0 0 6px;">
				<strong><?php printf( esc_html( _n( '%s product may have sold on your website and been put back in stock.', '%s products may have sold on your website and been put back in stock.', count( $restocked ), 'tcgiant-sync' ) ), esc_html( number_format_i18n( count( $restocked ) ) ) ); ?></strong>
			</p>
			<p style="margin:0 0 10px;"><?php esc_html_e( 'Before this version, when an eBay listing ended the plugin set the stock from eBay\'s figures, and eBay never sees a sale made on your website. Each product below has a website order placed after its listing started and is in stock again. Check each order. Tick the ones whose item has gone and set them to 0; tick any you have genuinely restocked and keep their stock. Either way they leave this list, and relisting them is no longer held back. Nothing changes unless you press a button.', 'tcgiant-sync' ); ?></p>
			<table class="widefat striped" style="margin-bottom:10px;">
				<thead><tr><th style="width:28px;"></th><th><?php esc_html_e( 'Product', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Stock now', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Website order', 'tcgiant-sync' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $restocked as $row ) : ?>
					<?php $p = wc_get_product( $row['product_id'] ); ?>
					<?php if ( ! $p ) { continue; } ?>
					<tr>
						<td><input type="checkbox" class="tc-row-cb" value="<?php echo esc_attr( $row['product_id'] ); ?>" /></td>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['product_id'] ) ); ?>"><?php echo esc_html( $p->get_name() ); ?></a> <span style="color:#777;">#<?php echo esc_html( $row['product_id'] ); ?></span></td>
						<td><?php echo $p->managing_stock() ? esc_html( (string) (int) $p->get_stock_quantity() ) : esc_html__( 'In stock', 'tcgiant-sync' ); ?></td>
						<?php $tc_order = wc_get_order( $row['order_id'] ); ?>
						<td>
							<?php if ( $tc_order instanceof WC_Order ) : ?>
								<a href="<?php echo esc_url( $tc_order->get_edit_order_url() ); ?>">#<?php echo esc_html( $row['order_id'] ); ?></a>
							<?php else : ?>
								#<?php echo esc_html( $row['order_id'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin:0;">
				<button type="button" class="button button-primary tc-panel-run" data-confirm="<?php echo esc_attr__( 'Set the ticked products to 0 stock?', 'tcgiant-sync' ); ?>" onclick="tcRunStockJob( 'bulk_zero_stock', '#tc-restocked', this );"><?php esc_html_e( 'Set ticked products to 0', 'tcgiant-sync' ); ?></button>
				<button type="button" class="button tc-panel-run" data-confirm="<?php echo esc_attr__( 'Keep the ticked products\' stock as it is, and stop listing them here?', 'tcgiant-sync' ); ?>" onclick="tcRunStockJob( 'bulk_keep_stock', '#tc-restocked', this );"><?php esc_html_e( 'Keep their stock - it is real', 'tcgiant-sync' ); ?></button>
				<span class="tc-panel-status" style="margin-left:10px;color:#555;"></span>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $unsold_at_end ) ) : ?>
		<div class="notice notice-info tc-stock-panel" id="tc-unsold-at-end" style="margin-top:16px;padding:12px 14px;">
			<p style="margin:0 0 6px;">
				<strong><?php printf( esc_html( _n( '%s ended listing reported more unsold than your shop holds.', '%s ended listings reported more unsold than your shop holds.', count( $unsold_at_end ), 'tcgiant-sync' ) ), esc_html( number_format_i18n( count( $unsold_at_end ) ) ) ); ?></strong>
			</p>
			<p style="margin:0 0 10px;"><?php esc_html_e( 'Your shop\'s figure was kept, because the difference usually sold on your website and eBay cannot know that. If you only sell on eBay, or you know the items are still here, you can take eBay\'s figure instead.', 'tcgiant-sync' ); ?></p>
			<table class="widefat striped" style="margin-bottom:10px;">
				<thead><tr><th style="width:28px;"></th><th><?php esc_html_e( 'Product', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Your shop', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'eBay said unsold', 'tcgiant-sync' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $unsold_at_end as $row ) : ?>
					<?php $p = wc_get_product( $row['product_id'] ); ?>
					<?php if ( ! $p ) { continue; } ?>
					<tr>
						<td><input type="checkbox" class="tc-row-cb" value="<?php echo esc_attr( $row['product_id'] ); ?>" /></td>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['product_id'] ) ); ?>"><?php echo esc_html( $p->get_name() ); ?></a> <span style="color:#777;">#<?php echo esc_html( $row['product_id'] ); ?></span></td>
						<td><?php echo $p->managing_stock() ? esc_html( (string) (int) $p->get_stock_quantity() ) : esc_html( $p->get_stock_status() ); ?></td>
						<td><?php echo esc_html( (string) $row['ebay_remaining'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin:0;">
				<button type="button" class="button tc-panel-run" data-confirm="<?php echo esc_attr__( 'Use eBay\'s figure for the ticked products?', 'tcgiant-sync' ); ?>" onclick="tcRunStockJob( 'bulk_use_ebay_figure', '#tc-unsold-at-end', this );"><?php esc_html_e( 'Use eBay\'s figure for ticked products', 'tcgiant-sync' ); ?></button>
				<span class="tc-panel-status" style="margin-left:10px;color:#555;"></span>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $held_back ) ) : ?>
		<?php
		// Why each is here (3.28.0): the sync moved past it, or its stock
		// check after an eBay sale gave up. One moved past by 3.27 has no
		// reason kept.
		$held_why = array(
			TCGiant_Sync_Importer::HELD_KILLED     => __( 'It stopped the sync every time it was imported. Its other details may be out of date.', 'tcgiant-sync' ),
			TCGiant_Sync_Importer::HELD_UNREAD     => __( 'eBay could not be read for it, three runs in a row, while it answered other calls. Its stock is checked on its own.', 'tcgiant-sync' ),
			TCGiant_Sync_Importer::HELD_SALE_CHECK => __( 'Its stock check after an eBay sale could not read it from eBay, though it tried for up to three days. Its stock here is unchanged.', 'tcgiant-sync' ),
			TCGiant_Sync_Importer::HELD_NO_CALLS   => __( 'The day\'s eBay calls ran out before its stock check after an eBay sale could read it, three days running. Its stock here is unchanged.', 'tcgiant-sync' ),
		);
		// A relist no product follows: it was the listing it was relisted from that could not be read.
		$held_why_relist = __( 'Its stock check after an eBay sale could not read the listing it was relisted from, though it tried for up to three days. Its stock here is unchanged.', 'tcgiant-sync' );
		$held_also = array(
			TCGiant_Sync_Importer::HELD_SALE_CHECK => __( 'Its stock check after an eBay sale could not read it either.', 'tcgiant-sync' ),
			TCGiant_Sync_Importer::HELD_NO_CALLS   => __( 'Its stock check after an eBay sale ran out of the day\'s eBay calls.', 'tcgiant-sync' ),
		);
		$held_counts = array( 'moved' => 0, 'check' => 0, 'calls' => 0 );
		foreach ( $held_back as $held ) {
			// One the sync moved past whose own check then gave up is counted
			// with the checks that gave up: its stock check is not coming.
			$held_reason = TCGiant_Sync_Importer::is_check_reason( $held['check'] ?? '' ) ? (string) $held['check'] : (string) ( $held['why'] ?? '' );
			if ( TCGiant_Sync_Importer::HELD_NO_CALLS === $held_reason ) {
				$held_counts['calls']++;
			} elseif ( TCGiant_Sync_Importer::HELD_SALE_CHECK === $held_reason ) {
				$held_counts['check']++;
			} else {
				$held_counts['moved']++;
			}
		}
		?>
		<div class="notice notice-error" style="margin-top:16px;padding:12px 14px;">
			<p style="margin:0 0 6px;">
				<strong><?php printf( esc_html( _n( '%s eBay listing to check by hand.', '%s eBay listings to check by hand.', count( $held_back ), 'tcgiant-sync' ) ), esc_html( number_format_i18n( count( $held_back ) ) ) ); ?></strong>
			</p>
			<?php if ( $held_counts['moved'] > 0 ) : ?>
				<p style="margin:0 0 6px;"><?php printf( esc_html( _n( 'The sync moved past %s of them so it could carry on with the rest. It was given a stock check of its own, which lowers the stock here when eBay sells it.', 'The sync moved past %s of them so it could carry on with the rest. Each was given a stock check of its own, which lowers the stock here when eBay sells it.', $held_counts['moved'], 'tcgiant-sync' ) ), esc_html( number_format_i18n( $held_counts['moved'] ) ) ); ?></p>
			<?php endif; ?>
			<?php if ( $held_counts['check'] > 0 ) : ?>
				<p style="margin:0 0 6px;"><?php printf( esc_html( _n( 'For %s, the stock check after an eBay sale could not read eBay, so its stock here is unchanged.', 'For %s, the stock check after an eBay sale could not read eBay, so their stock here is unchanged.', $held_counts['check'], 'tcgiant-sync' ) ), esc_html( number_format_i18n( $held_counts['check'] ) ) ); ?></p>
			<?php endif; ?>
			<?php if ( $held_counts['calls'] > 0 ) : ?>
				<p style="margin:0 0 6px;"><?php printf( esc_html( _n( 'For %s, the day\'s eBay calls ran out before the stock check after an eBay sale could read it, so its stock here is unchanged.', 'For %s, the day\'s eBay calls ran out before the stock check after an eBay sale could read them, so their stock here is unchanged.', $held_counts['calls'], 'tcgiant-sync' ) ), esc_html( number_format_i18n( $held_counts['calls'] ) ) ); ?></p>
			<?php endif; ?>
			<p style="margin:0 0 10px;"><?php esc_html_e( 'Check each listing on eBay, and its stock here. Syncing an item by hand from the Import page tries it again; the Activity Log says what happened to each.', 'tcgiant-sync' ); ?></p>
			<table class="widefat striped" style="margin-bottom:10px;">
				<thead><tr><th><?php esc_html_e( 'eBay item', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Product', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Why', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Since', 'tcgiant-sync' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_reverse( $held_back, true ) as $held ) : ?>
					<?php
					// A relist no product follows: the product of the listing it was relisted from.
					$held_pid    = ! empty( $held['product'] ) ? (int) $held['product'] : TCGiant_Sync_Importer::find_live_product_for_item( $held['item_id'] );
					$held_reason = (string) ( $held['why'] ?? '' );
					$held_text   = $held_why[ $held_reason ] ?? __( 'The Activity Log from that day says why.', 'tcgiant-sync' );
					if ( TCGiant_Sync_Importer::HELD_SALE_CHECK === $held_reason && ! empty( $held['parent'] ) ) {
						$held_text = $held_why_relist;
					}
					if ( isset( $held['check'], $held_also[ (string) $held['check'] ] ) ) {
						$held_text .= ' ' . $held_also[ (string) $held['check'] ];
					}
					?>
					<tr>
						<td><?php echo esc_html( $held['item_id'] ); ?></td>
						<td>
							<?php if ( $held_pid ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $held_pid ) ); ?>">#<?php echo esc_html( $held_pid ); ?></a>
								<?php if ( ! empty( $held['parent'] ) ) : ?>
									<span style="color:#777;"><?php printf( esc_html__( 'follows %s, which this was relisted from', 'tcgiant-sync' ), esc_html( (string) $held['parent'] ) ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span style="color:#777;"><?php esc_html_e( 'Not imported', 'tcgiant-sync' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $held_text ); ?></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $held['at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
				<input type="hidden" name="action" value="tcgiant_forget_held" />
				<?php wp_nonce_field( 'tcgiant_forget_held' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Clear this list', 'tcgiant-sync' ); ?></button>
			</form>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $push_failures ) ) : ?>
		<?php
		$push_why = array(
			'order'  => __( 'A website order', 'tcgiant-sync' ),
			'manual' => __( 'Push to eBay, by hand', 'tcgiant-sync' ),
			'edit'   => __( 'A stock change on your website', 'tcgiant-sync' ),
		);
		?>
		<div class="notice notice-error" id="tc-push-failures" style="margin-top:16px;padding:12px 14px;">
			<p style="margin:0 0 6px;">
				<strong><?php printf( esc_html( _n( '%s stock change never reached eBay.', '%s stock changes never reached eBay.', count( $push_failures ), 'tcgiant-sync' ) ), esc_html( number_format_i18n( count( $push_failures ) ) ) ); ?></strong>
			</p>
			<p style="margin:0 0 10px;"><?php esc_html_e( 'eBay refused each of these every time it was tried, over about 10 hours (longer when eBay was down or out of calls), so the listing on eBay may still show more than your website holds. Check each listing in Seller Hub and set its quantity there if it is wrong. Your website\'s stock was not changed. The most recent are first.', 'tcgiant-sync' ); ?></p>
			<table class="widefat striped" style="margin-bottom:10px;">
				<thead><tr><th><?php esc_html_e( 'When', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Product', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'eBay item', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'What changed', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Your stock then', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'eBay\'s last answer', 'tcgiant-sync' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $push_failures as $push_row ) : ?>
					<?php $push_pid = (int) $push_row['product_id']; ?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $push_row['time'] ?? 0 ) ) ); ?></td>
						<td>
							<?php if ( get_post_status( $push_pid ) ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $push_pid ) ); ?>"><?php echo esc_html( get_the_title( $push_pid ) ); ?></a>
							<?php endif; ?>
							<span style="color:#777;">#<?php echo esc_html( (string) $push_pid ); ?></span>
							<?php if ( '' !== (string) ( $push_row['sku'] ?? '' ) ) : ?>
								<span style="color:#777;"><?php echo esc_html( sprintf( /* translators: %s: SKU */ __( 'SKU %s', 'tcgiant-sync' ), (string) $push_row['sku'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) ( $push_row['item_id'] ?? '' ) ); ?></td>
						<td>
							<?php echo esc_html( $push_why[ (string) ( $push_row['reason'] ?? '' ) ] ?? $push_why['edit'] ); ?>
							<?php if ( ! empty( $push_row['order'] ) ) : ?>
								<span style="color:#777;">#<?php echo esc_html( (string) (int) $push_row['order'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) (int) ( $push_row['stock'] ?? 0 ) ); ?></td>
						<td>
							<?php echo esc_html( (string) ( $push_row['message'] ?? '' ) ); ?>
							<?php if ( '' !== (string) ( $push_row['code'] ?? '' ) ) : ?>
								<span style="color:#777;">(<?php echo esc_html( (string) $push_row['code'] ); ?>)</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
				<input type="hidden" name="action" value="tcgiant_forget_push_failures" />
				<?php wp_nonce_field( 'tcgiant_forget_push_failures' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Clear this list', 'tcgiant-sync' ); ?></button>
				<span style="margin-left:8px;color:#555;"><?php esc_html_e( 'Only the list is cleared. Nothing is sent to eBay.', 'tcgiant-sync' ); ?></span>
			</form>
		</div>
	<?php endif; ?>

	<?php if ( ! $tc_pairs_asked ) : ?>
		<p id="tc-relist-pairs-ask" style="margin-top:16px;">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-stock-review&relist_pairs=1' ) . '#tc-relist-pairs' ); ?>"><?php esc_html_e( 'Look for products that may be the same item twice', 'tcgiant-sync' ); ?></a>
			<span style="margin-left:8px;color:#555;"><?php esc_html_e( 'A relist made on eBay before version 3.28.0 could become a second product beside the first. Nothing changes from the list.', 'tcgiant-sync' ); ?></span>
		</p>
	<?php elseif ( empty( $relist_pairs ) && '' === $tc_pairs_more ) : ?>
		<div class="notice notice-success" id="tc-relist-pairs" style="margin-top:16px;padding:12px 14px;">
			<p style="margin:0;">
				<?php if ( $tc_pairs_before > 0 ) : ?>
					<?php esc_html_e( 'No more were found: every relist the sync has recorded has now been looked through.', 'tcgiant-sync' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'No products were found that may be the same item twice.', 'tcgiant-sync' ); ?>
				<?php endif; ?>
			</p>
		</div>
	<?php elseif ( empty( $relist_pairs ) ) : ?>
		<div class="notice notice-info" id="tc-relist-pairs" style="margin-top:16px;padding:12px 14px;">
			<p style="margin:0;">
				<?php /* translators: %s: number of relists looked through */ printf( esc_html( _n( 'None among the %s relist looked through so far, but there are older ones still to look through.', 'None among the %s relists looked through so far, but there are older ones still to look through.', (int) $tc_pairs['checked'], 'tcgiant-sync' ) ), esc_html( number_format_i18n( (int) $tc_pairs['checked'] ) ) ); ?>
				<a class="button" style="margin-left:8px;" href="<?php echo esc_url( $tc_pairs_more ); ?>"><?php esc_html_e( 'Look further back', 'tcgiant-sync' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $relist_pairs ) ) : ?>
		<div class="notice notice-info" id="tc-relist-pairs" style="margin-top:16px;padding:12px 14px;">
			<p style="margin:0 0 6px;">
				<strong><?php printf( esc_html( _n( '%s product may be the same item as another.', '%s products may be the same item as another.', count( $relist_pairs ), 'tcgiant-sync' ) ), esc_html( number_format_i18n( count( $relist_pairs ) ) ) ); ?></strong>
			</p>
			<p style="margin:0 0 10px;"><?php esc_html_e( 'Each was imported from an eBay listing relisted from another, while a second product stayed linked to that older listing. Versions before 3.28.0 did this when you relisted on eBay, so the same item could be on your website twice, and a sale of one did not reach the other. A single item that sold and was relisted is usually another copy of the item, so those are left out. Nothing changes from this list; which to keep is yours to decide.', 'tcgiant-sync' ); ?></p>
			<table class="widefat striped" style="margin-bottom:10px;">
				<thead><tr><th><?php esc_html_e( 'From the relist', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Its listing', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Still on the older listing', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Older listing', 'tcgiant-sync' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $relist_pairs as $pair ) : ?>
					<?php
					$pair_new = wc_get_product( $pair['product_id'] );
					$pair_old = wc_get_product( $pair['other_id'] );
					if ( ! $pair_new || ! $pair_old ) {
						continue;
					}
					?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $pair['product_id'] ) ); ?>"><?php echo esc_html( $pair_new->get_name() ); ?></a> <span style="color:#777;">#<?php echo esc_html( (string) $pair['product_id'] ); ?>, <?php echo esc_html( sprintf( /* translators: %s: stock quantity or status */ __( 'stock %s', 'tcgiant-sync' ), $pair_new->managing_stock() ? (string) (int) $pair_new->get_stock_quantity() : $pair_new->get_stock_status() ) ); ?></span></td>
						<td><?php echo esc_html( $pair['item_id'] ); ?></td>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $pair['other_id'] ) ); ?>"><?php echo esc_html( $pair_old->get_name() ); ?></a> <span style="color:#777;">#<?php echo esc_html( (string) $pair['other_id'] ); ?>, <?php echo esc_html( sprintf( /* translators: %s: stock quantity or status */ __( 'stock %s', 'tcgiant-sync' ), $pair_old->managing_stock() ? (string) (int) $pair_old->get_stock_quantity() : $pair_old->get_stock_status() ) ); ?></span></td>
						<td><?php echo esc_html( $pair['parent_item'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( '' !== $tc_pairs_more ) : ?>
				<p style="margin:0;">
					<?php /* translators: %s: number of relists looked through */ printf( esc_html( _n( 'These are from the newest %s relist looked through; there are older ones still to look through.', 'These are from the %s relists looked through so far; there are older ones still to look through.', (int) $tc_pairs['checked'], 'tcgiant-sync' ) ), esc_html( number_format_i18n( (int) $tc_pairs['checked'] ) ) ); ?>
					<a class="button" style="margin-left:8px;" href="<?php echo esc_url( $tc_pairs_more ); ?>"><?php esc_html_e( 'Look further back', 'tcgiant-sync' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( 0 === $total ) : ?>

		<div class="notice notice-success" style="margin-top:16px;">
			<p>
				<strong><?php esc_html_e( 'Nothing to review.', 'tcgiant-sync' ); ?></strong><br>
				<?php esc_html_e( 'Every product whose eBay listing has ended is showing as out of stock, which is what you want. This page will list anything that falls out of step in future.', 'tcgiant-sync' ); ?>
			</p>
		</div>

	<?php else : ?>

		<div class="notice notice-warning" style="margin-top:16px;">
			<p>
				<strong>
				<?php
				printf(
					/* translators: %d: number of products */
					esc_html( _n(
						'%d product is still showing stock although its eBay listing has ended.',
						'%d products are still showing stock although their eBay listings have ended.',
						$total,
						'tcgiant-sync'
					) ),
					(int) $total
				);
				?>
				</strong>
			</p>
			<p>
				<?php esc_html_e( 'These are most likely items that sold on eBay before the stock fault was fixed. A listing that holds one of something ends the moment it sells, and an ended listing is never imported again, so nothing in the ordinary sync can correct these on its own.', 'tcgiant-sync' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Settling asks eBay what each listing held and what sold, then sets the WooCommerce stock to the difference. A listing that sold out leaves nothing in stock; one you ended yourself with items unsold keeps them, because they are still yours to sell. Where eBay will not report the figures, the stock is left alone rather than guessed at, and the product stays on this list.', 'tcgiant-sync' ); ?>
			</p>
		</div>

		<div style="display:flex;gap:8px;align-items:center;margin:14px 0;flex-wrap:wrap;">
			<button type="button" class="button button-primary" id="tc-settle-all">
				<?php
				printf(
					/* translators: %d: number of products */
					esc_html__( 'Settle all %d from eBay', 'tcgiant-sync' ),
					(int) $total
				);
				?>
			</button>
			<button type="button" class="button" id="tc-settle-selected"><?php esc_html_e( 'Settle selected', 'tcgiant-sync' ); ?></button>
			<span style="color:#666;font-size:12px;">
				<?php esc_html_e( 'One eBay call per product, five at a time.', 'tcgiant-sync' ); ?>
			</span>
		</div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<td class="check-column" style="padding:8px 4px;"><input type="checkbox" id="tc-cb-all" /></td>
					<th scope="col"><?php esc_html_e( 'Product', 'tcgiant-sync' ); ?></th>
					<th scope="col" style="width:150px;"><?php esc_html_e( 'eBay listing', 'tcgiant-sync' ); ?></th>
					<th scope="col" style="width:110px;"><?php esc_html_e( 'Showing', 'tcgiant-sync' ); ?></th>
					<th scope="col" style="width:170px;"><?php esc_html_e( 'Listing ended', 'tcgiant-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $page_ids as $pid ) :
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}

				$name      = esc_html( $product->get_name() );
				$edit_url  = get_edit_post_link( $product->is_type( 'variation' ) ? $product->get_parent_id() : $pid );
				$item_id   = esc_html( (string) get_post_meta( $pid, '_ebay_item_id', true ) );
				$ebay_url  = 'https://www.ebay.com/itm/' . $item_id;
				$managing  = $product->managing_stock();
				$qty       = $product->get_stock_quantity();
				$end_raw   = (string) get_post_meta( $pid, '_ebay_end_time', true );
				$end_stamp = $end_raw ? strtotime( $end_raw ) : 0;
				?>
				<tr>
					<th scope="row" class="check-column" style="padding:8px 4px;">
						<input type="checkbox" class="tc-settle-cb" value="<?php echo esc_attr( $pid ); ?>" />
					</th>
					<td>
						<strong>
							<?php if ( $edit_url ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo $name; ?></a>
							<?php else : ?>
								<?php echo $name; ?>
							<?php endif; ?>
						</strong>
						<div style="color:#777;font-size:12px;">
							<?php echo esc_html( sprintf( '#%d', $pid ) ); ?>
							<?php if ( $product->is_type( 'variation' ) ) : ?>
								· <?php esc_html_e( 'variation', 'tcgiant-sync' ); ?>
							<?php endif; ?>
						</div>
					</td>
					<td>
						<?php if ( '' !== $item_id ) : ?>
							<a href="<?php echo esc_url( $ebay_url ); ?>" target="_blank" rel="noopener"><?php echo $item_id; ?> ↗</a>
						<?php else : ?>
							<span style="color:#a00;"><?php esc_html_e( 'not linked', 'tcgiant-sync' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $managing ) : ?>
							<strong style="color:#b32d2e;"><?php echo esc_html( sprintf( _n( '%d in stock', '%d in stock', (int) $qty, 'tcgiant-sync' ), (int) $qty ) ); ?></strong>
						<?php else : ?>
							<strong style="color:#b32d2e;"><?php esc_html_e( 'In stock', 'tcgiant-sync' ); ?></strong>
							<div style="color:#777;font-size:12px;"><?php esc_html_e( 'no quantity tracked', 'tcgiant-sync' ); ?></div>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $end_stamp ) : ?>
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), $end_stamp ) ); ?>
							<div style="color:#777;font-size:12px;">
								<?php echo esc_html( sprintf( __( '%s ago', 'tcgiant-sync' ), human_time_diff( $end_stamp, time() ) ) ); ?>
							</div>
						<?php else : ?>
							<span style="color:#777;"><?php esc_html_e( 'unknown', 'tcgiant-sync' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pgs > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php
						printf(
							/* translators: %d: number of products */
							esc_html( _n( '%d item', '%d items', $total, 'tcgiant-sync' ) ),
							(int) $total
						);
						?>
					</span>
					<?php
					echo wp_kses_post( paginate_links( array(
						'base'      => $base_url . '%_%',
						'format'    => '&paged=%#%',
						'current'   => $paged,
						'total'     => $total_pgs,
						'prev_text' => '‹',
						'next_text' => '›',
					) ) );
					?>
				</div>
			</div>
		<?php endif; ?>

		<!-- Progress modal -->
		<div id="tc-job-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;">
			<div style="background:#fff;border-radius:6px;padding:22px;width:min(460px,92vw);box-shadow:0 8px 30px rgba(0,0,0,.3);">
				<h2 id="tc-job-title" style="margin:0 0 12px;font-size:16px;"></h2>
				<div style="background:#f0f0f0;border-radius:4px;height:20px;margin-bottom:10px;">
					<div id="tc-job-bar" style="background:#2271b1;height:100%;border-radius:4px;transition:width .3s;width:0;"></div>
				</div>
				<p id="tc-job-status" style="font-size:13px;color:#555;margin:0 0 8px;"></p>
				<div id="tc-job-errors" style="display:none;max-height:140px;overflow-y:auto;background:#fff0f0;border:1px solid #fcc;border-radius:4px;padding:8px;font-size:12px;margin-bottom:10px;"></div>
				<div style="text-align:right;">
					<button type="button" id="tc-job-cancel" class="button"><?php esc_html_e( 'Cancel', 'tcgiant-sync' ); ?></button>
					<button type="button" id="tc-job-done" class="button button-primary" style="display:none;"><?php esc_html_e( 'Done', 'tcgiant-sync' ); ?></button>
				</div>
			</div>
		</div>

		<script>
		(function($){
			var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
			var nonce   = '<?php echo esc_js( wp_create_nonce( 'tcgiant_sync_ajax' ) ); ?>';
			var title   = '<?php echo esc_js( __( 'Settling stock from eBay', 'tcgiant-sync' ) ); ?>';

			$('#tc-cb-all').on('change', function(){
				$('.tc-settle-cb').prop('checked', this.checked);
			});

			$('#tc-settle-all').on('click', function(){
				if (!confirm('<?php echo esc_js( __( 'Settle every product on this list from eBay?', 'tcgiant-sync' ) ); ?>')) return;
				start({ select_all: 1 });
			});

			$('#tc-settle-selected').on('click', function(){
				var ids = [];
				$('.tc-settle-cb:checked').each(function(){ ids.push(parseInt($(this).val(), 10)); });
				if (!ids.length) { alert('<?php echo esc_js( __( 'Select at least one product.', 'tcgiant-sync' ) ); ?>'); return; }
				start({ product_ids: ids });
			});

			function start(extra){
				var data = $.extend({ action:'tcgiant_job_start', type:'bulk_settle', _ajax_nonce:nonce }, extra);
				$.post(ajaxUrl, data, function(r){
					if (!r.success) { alert(r.data ? r.data.message : 'Could not start.'); return; }
					openModal(r.data.job_id, r.data.total);
				});
			}

			function openModal(jobId, total){
				$('#tc-job-title').text(title + ' — 0/' + total);
				$('#tc-job-bar').css('width','0%');
				$('#tc-job-status').text('<?php echo esc_js( __( 'Starting…', 'tcgiant-sync' ) ); ?>');
				$('#tc-job-errors').hide().empty();
				$('#tc-job-done').hide();
				$('#tc-job-cancel').show().off('click').on('click', function(){
					$.post(ajaxUrl, { action:'tcgiant_job_cancel', job_id:jobId, _ajax_nonce:nonce });
					$('#tc-job-modal').fadeOut(200, function(){ location.reload(); });
				});
				$('#tc-job-done').off('click').on('click', function(){
					$('#tc-job-modal').fadeOut(200, function(){ location.reload(); });
				});
				$('#tc-job-modal').css('display','flex').hide().fadeIn(200);
				next(jobId, total);
			}

			function next(jobId, total){
				$.post(ajaxUrl, { action:'tcgiant_job_process', job_id:jobId, _ajax_nonce:nonce }, function(r){
					if (!r.success) {
						$('#tc-job-status').text('Error: ' + (r.data ? r.data.message : 'Unknown'));
						$('#tc-job-cancel').hide();
						$('#tc-job-done').show();
						return;
					}
					var d = r.data, pct = total ? Math.round((d.processed / total) * 100) : 100;
					$('#tc-job-title').text(title + ' — ' + d.processed + '/' + total);
					$('#tc-job-bar').css('width', pct + '%');
					$('#tc-job-status').text(d.succeeded + ' settled, ' + d.failed + ' could not be settled');
					if (d.errors && d.errors.length) {
						// .text() per row: these strings carry product titles and
						// eBay's own error wording, so they are never trusted as
						// markup.
						var box = $('#tc-job-errors').show().empty();
						d.errors.forEach(function(e){
							$('<div/>').css({color:'#c00',padding:'1px 0'}).text(e).appendTo(box);
						});
					}
					if (d.status === 'running') {
						setTimeout(function(){ next(jobId, total); }, 400);
					} else {
						$('#tc-job-cancel').hide();
						$('#tc-job-done').show();
						$('#tc-job-status').text('<?php echo esc_js( __( 'Finished.', 'tcgiant-sync' ) ); ?> ' + d.succeeded + ' settled, ' + d.failed + ' left alone.');
					}
				});
			}
		})(jQuery);
		</script>

	<?php endif; ?>
</div>
