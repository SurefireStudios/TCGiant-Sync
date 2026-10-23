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
$sold_out_ids = TCGiant_Sync_Inventory::find_sold_out_not_removed();

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
		<div class="notice notice-error" style="margin-top:16px;padding:12px 14px;">
			<p style="margin:0 0 6px;">
				<strong><?php printf( esc_html( _n( '%s eBay listing stopped the sync every time it was imported.', '%s eBay listings stopped the sync every time they were imported.', count( $held_back ), 'tcgiant-sync' ) ), esc_html( number_format_i18n( count( $held_back ) ) ) ); ?></strong>
			</p>
			<p style="margin:0 0 10px;"><?php esc_html_e( 'The sync moved past each of these so it could carry on with the rest. Each was given a separate stock check, so a sale still takes it off your shop, but its other details may be out of date. The Activity Log says what stopped the sync. Syncing the item by hand from the Import page tries it again.', 'tcgiant-sync' ); ?></p>
			<table class="widefat striped" style="margin-bottom:10px;">
				<thead><tr><th><?php esc_html_e( 'eBay item', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Product', 'tcgiant-sync' ); ?></th><th><?php esc_html_e( 'Moved past', 'tcgiant-sync' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_reverse( $held_back, true ) as $held ) : ?>
					<?php $held_pid = TCGiant_Sync_Importer::find_live_product_for_item( $held['item_id'] ); ?>
					<tr>
						<td><?php echo esc_html( $held['item_id'] ); ?></td>
						<td>
							<?php if ( $held_pid ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $held_pid ) ); ?>">#<?php echo esc_html( $held_pid ); ?></a>
							<?php else : ?>
								<span style="color:#777;"><?php esc_html_e( 'Not imported', 'tcgiant-sync' ); ?></span>
							<?php endif; ?>
						</td>
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
