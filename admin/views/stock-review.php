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
?>

<div class="wrap tc-dashboard-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Stock Review', 'tcgiant-sync' ); ?></h1>
	<hr class="wp-header-end" />

	<?php TCGiant_Sync_Admin::instance()->render_tabs( 'stock_review' ); ?>

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
