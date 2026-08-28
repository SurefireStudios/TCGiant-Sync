<?php
/**
 * Image Cleanup Page
 *
 * Lists products holding both their own photographs and copies downloaded from
 * eBay, and puts the originals back.
 *
 * Before 3.9.5, pushing a product meant eBay served its pictures back and the
 * plugin fetched them as though they were new — then made its copies the
 * product's images. So the shop's originals are still attached but no longer
 * shown anywhere, which is the wrong way round and why this cannot simply
 * delete whatever is unused.
 *
 * Both sets are shown as pictures rather than described in words. Nobody
 * should be asked to approve a deletion they cannot see.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$per_page  = 30;
$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$all_ids   = TCGiant_Sync_Image_Localizer::find_products_with_duplicate_images();
$total     = count( $all_ids );
$total_pgs = (int) ceil( $total / $per_page );
$page_ids  = array_slice( $all_ids, ( $paged - 1 ) * $per_page, $per_page );
$base_url  = admin_url( 'admin.php?page=tcgiant-image-cleanup' );
?>

<div class="wrap tc-dashboard-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Image Cleanup', 'tcgiant-sync' ); ?></h1>
	<hr class="wp-header-end" />

	<?php TCGiant_Sync_Admin::instance()->render_tabs( 'image_cleanup' ); ?>

	<?php if ( 0 === $total ) : ?>

		<div class="notice notice-success" style="margin-top:16px;">
			<p>
				<strong><?php esc_html_e( 'Nothing to clean up.', 'tcgiant-sync' ); ?></strong><br>
				<?php esc_html_e( 'No product is holding duplicate copies of its own photographs. This page will list anything that turns up in future.', 'tcgiant-sync' ); ?>
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
						'%d product is holding duplicate copies of its own photographs.',
						'%d products are holding duplicate copies of their own photographs.',
						$total,
						'tcgiant-sync'
					) ),
					(int) $total
				);
				?>
				</strong>
			</p>
			<p>
				<?php esc_html_e( 'After a product was pushed, eBay served your pictures back from its own servers and the plugin downloaded them again, not recognising them as the ones it had just sent. Those copies then replaced your originals as the product images — so your own photographs are still here, but are no longer the ones being shown.', 'tcgiant-sync' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'What cleaning up does:', 'tcgiant-sync' ); ?></strong>
				<?php esc_html_e( 'your own photographs are put back as the product image and gallery first, and only then are the downloaded copies deleted. Nothing you uploaded is ever deleted — only files the plugin downloaded itself, and only where your own pictures exist to put back.', 'tcgiant-sync' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Deleting files cannot be undone, so check the pictures below before you start. If a row looks wrong, leave it unticked and tell us.', 'tcgiant-sync' ); ?>
			</p>
		</div>

		<div style="display:flex;gap:8px;align-items:center;margin:14px 0;flex-wrap:wrap;">
			<button type="button" class="button button-primary" id="tc-restore-all">
				<?php
				printf(
					/* translators: %d: number of products */
					esc_html__( 'Clean up all %d products', 'tcgiant-sync' ),
					(int) $total
				);
				?>
			</button>
			<button type="button" class="button" id="tc-restore-selected"><?php esc_html_e( 'Clean up selected', 'tcgiant-sync' ); ?></button>
			<span style="color:#666;font-size:12px;">
				<?php esc_html_e( 'Five products at a time.', 'tcgiant-sync' ); ?>
			</span>
		</div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<td class="check-column" style="padding:8px 4px;"><input type="checkbox" id="tc-cb-all" /></td>
					<th scope="col" style="width:26%;"><?php esc_html_e( 'Product', 'tcgiant-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Your photographs — kept', 'tcgiant-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Downloaded copies — deleted', 'tcgiant-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $page_ids as $pid ) :
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}

				$theirs = TCGiant_Sync_Image_Localizer::attachments_by_origin( $pid, false );
				$ours   = TCGiant_Sync_Image_Localizer::attachments_by_origin( $pid, true );

				// A row with nothing of the shop's own would be refused by the
				// cleanup anyway, so do not offer it.
				if ( empty( $theirs ) || empty( $ours ) ) {
					continue;
				}

				$edit_url = get_edit_post_link( $pid );
				?>
				<tr>
					<th scope="row" class="check-column" style="padding:8px 4px;">
						<input type="checkbox" class="tc-restore-cb" value="<?php echo esc_attr( $pid ); ?>" checked />
					</th>
					<td>
						<strong>
							<?php if ( $edit_url ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $product->get_name() ); ?>
							<?php endif; ?>
						</strong>
						<div style="color:#777;font-size:12px;"><?php echo esc_html( sprintf( '#%d', $pid ) ); ?></div>
					</td>
					<td>
						<div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
							<?php foreach ( array_slice( $theirs, 0, 8 ) as $att_id ) : ?>
								<span style="display:inline-block;border:2px solid #2b6a52;border-radius:2px;line-height:0;">
									<?php echo wp_get_attachment_image( $att_id, array( 48, 48 ) ); ?>
								</span>
							<?php endforeach; ?>
							<span style="color:#2b6a52;font-size:12px;font-weight:600;">
								<?php echo esc_html( sprintf( _n( '%d kept', '%d kept', count( $theirs ), 'tcgiant-sync' ), count( $theirs ) ) ); ?>
							</span>
						</div>
					</td>
					<td>
						<div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
							<?php foreach ( array_slice( $ours, 0, 8 ) as $att_id ) : ?>
								<span style="display:inline-block;border:2px solid #b32d2e;border-radius:2px;line-height:0;opacity:.75;">
									<?php echo wp_get_attachment_image( $att_id, array( 48, 48 ) ); ?>
								</span>
							<?php endforeach; ?>
							<span style="color:#b32d2e;font-size:12px;font-weight:600;">
								<?php echo esc_html( sprintf( _n( '%d deleted', '%d deleted', count( $ours ), 'tcgiant-sync' ), count( $ours ) ) ); ?>
							</span>
						</div>
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
							esc_html( _n( '%d product', '%d products', $total, 'tcgiant-sync' ) ),
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
			var title   = '<?php echo esc_js( __( 'Putting your photographs back', 'tcgiant-sync' ) ); ?>';

			$('#tc-cb-all').on('change', function(){
				$('.tc-restore-cb').prop('checked', this.checked);
			});

			$('#tc-restore-all').on('click', function(){
				if (!confirm('<?php echo esc_js( __( 'Restore your own photographs on every product listed, and delete the downloaded copies? This cannot be undone.', 'tcgiant-sync' ) ); ?>')) return;
				start({ select_all: 1 });
			});

			$('#tc-restore-selected').on('click', function(){
				var ids = [];
				$('.tc-restore-cb:checked').each(function(){ ids.push(parseInt($(this).val(), 10)); });
				if (!ids.length) { alert('<?php echo esc_js( __( 'Select at least one product.', 'tcgiant-sync' ) ); ?>'); return; }
				if (!confirm('<?php echo esc_js( __( 'Delete the downloaded copies on the selected products? This cannot be undone.', 'tcgiant-sync' ) ); ?>')) return;
				start({ product_ids: ids });
			});

			function start(extra){
				var data = $.extend({ action:'tcgiant_job_start', type:'bulk_restore_images', _ajax_nonce:nonce }, extra);
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
					$('#tc-job-status').text(d.succeeded + ' cleaned up, ' + d.failed + ' left alone');
					if (d.errors && d.errors.length) {
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
						$('#tc-job-status').text('<?php echo esc_js( __( 'Finished.', 'tcgiant-sync' ) ); ?> ' + d.succeeded + ' cleaned up, ' + d.failed + ' left alone.');
					}
				});
			}
		})(jQuery);
		</script>

	<?php endif; ?>
</div>
