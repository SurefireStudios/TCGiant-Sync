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
 * Above them, since 3.27.4: the photos left behind by products deleted for
 * good. Those are too many to show one by one - tens of thousands on a shop
 * that sells one of each - so the shop is shown a count and the space they
 * take, from a check that judges each photo exactly as the removal will, and
 * nothing is deleted until a second button is pressed.
 *
 * Below that, since 3.27.7: the 1,536 px copies WordPress made of downloaded
 * photos before 3.27.4, counted and removed the same way - a check that
 * deletes nothing, then a button. See TCGiant_Sync_Size_Trim.
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

	<?php
	// Photos of deleted products. See TCGiant_Sync_Photo_Cleanup.
	$tc_photo  = TCGiant_Sync_Photo_Cleanup::status();
	$tc_scan   = $tc_photo['scan'];
	$tc_state  = $tc_scan['status'];
	$tc_format = get_option( 'date_format' ) . ', ' . get_option( 'time_format' );
	$tc_when   = $tc_scan['finished_at'] ? wp_date( $tc_format, $tc_scan['finished_at'] ) : '';
	$tc_after  = max( 0, $tc_photo['trashed'] - $tc_photo['trashed_before'] );
	?>

	<div class="notice notice-info tc-photo-cleanup" id="tc-photo-cleanup" style="margin-top:16px;padding:12px 14px;"
		data-scan-id="<?php echo esc_attr( $tc_scan['id'] ); ?>"
		data-found="<?php echo esc_attr( $tc_scan['found'] ); ?>"
		data-state="<?php echo esc_attr( $tc_state ); ?>">

		<h2 style="margin:0 0 8px;font-size:15px;"><?php esc_html_e( 'Photos of deleted products', 'tcgiant-sync' ); ?></h2>

		<p>
			<?php esc_html_e( 'When a product is deleted for good - when the Trash is emptied, by you or by WordPress after 30 days - TCGiant now removes the photos it downloaded from eBay for that product, about 15 minutes later. Products deleted before this version left theirs behind, and they are still taking up space. So will products that were already in the Trash when this version arrived: their photos are left for the check below, so that nothing they had is removed without you seeing it first.', 'tcgiant-sync' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Only photos TCGiant downloaded are ever removed. Photos you uploaded yourself are never touched, and neither is a photo still in use: the main photo or in the gallery of any product or variation, including those in the Trash; a category\'s or brand\'s picture; placed in a post, page (Flatsome\'s UX Builder pages included), product description or short description, variation description, category, tag or brand description, reusable block or template, a theme\'s or page builder\'s own layouts (headers, footers and the like: GeneratePress or Kadence Elements, the Divi or Elementor library, Divi\'s Theme Builder) or the Customizer\'s Additional CSS, or shown by a widget; pointed at by its address under /uploads/ in that content, in a page builder\'s data, in a theme\'s or SEO plugin\'s settings for a category, tag or brand, or in a site setting; or the site\'s logo, icon, header or background, or WooCommerce\'s placeholder picture.', 'tcgiant-sync' ); ?>
		</p>
		<p style="color:#555;font-size:12px;max-width:760px;">
			<?php esc_html_e( 'Not seen: a photo another plugin keeps by its number alone in a place of its own - a slider\'s or a swatch plugin\'s settings, a theme\'s settings for a category with no address in them, a widget other than WordPress\'s own, a user\'s profile picture, a builder\'s data with no address in it - or one shown from an address outside /uploads/. Such a photo is removed about 15 minutes after its product is deleted for good, as well as when you press the button below: if you use such a plugin with photos TCGiant downloaded, ask your developer to keep them (see changelog.txt), and check before removing leftovers. Orders, old revisions, and another plugin\'s own records with no screen in WordPress\'s admin (email logs, form entries) are not read. If the database cannot answer one of these checks, or reading them takes longer than the host allows, nothing is removed until it can.', 'tcgiant-sync' ); ?>
		</p>

		<div class="tc-photo-result">
		<?php if ( '' === $tc_state ) : ?>

			<p><?php esc_html_e( 'Check how many there are and how much space they take. Nothing is deleted by checking.', 'tcgiant-sync' ); ?></p>

		<?php elseif ( 'scanning' === $tc_state ) : ?>

			<p><?php esc_html_e( 'A check was started and has not finished.', 'tcgiant-sync' ); ?></p>

		<?php elseif ( 'found' === $tc_state && $tc_scan['found'] > 0 ) : ?>

			<p>
				<strong>
				<?php
				printf(
					/* translators: 1: number of photos, 2: size such as "41.2 GB" */
					esc_html( _n(
						'%1$s photo is left over from deleted products, taking about %2$s on this server.',
						'%1$s photos are left over from deleted products, taking about %2$s on this server.',
						$tc_scan['found'],
						'tcgiant-sync'
					) ),
					esc_html( number_format_i18n( $tc_scan['found'] ) ),
					esc_html( $tc_scan['size'] )
				);
				?>
				</strong>
			</p>
			<?php if ( $tc_scan['not_here'] > 0 ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: number of photos */
						esc_html__( '%s of them are no longer on this server - an offload plugin may have moved them - so removing those frees no space here, but tidies up the Media Library.', 'tcgiant-sync' ),
						esc_html( number_format_i18n( $tc_scan['not_here'] ) )
					);
					?>
				</p>
			<?php endif; ?>
			<p style="color:#555;">
				<?php
				/* translators: %s: date and time of the check */
				printf( esc_html__( 'Checked %s.', 'tcgiant-sync' ), esc_html( $tc_when ) );
				?>
			</p>

		<?php elseif ( 'found' === $tc_state ) : ?>

			<p>
				<strong><?php esc_html_e( 'No leftover photos found.', 'tcgiant-sync' ); ?></strong>
				<?php
				/* translators: %s: date and time of the check */
				printf( esc_html__( 'Checked %s.', 'tcgiant-sync' ), esc_html( $tc_when ) );
				?>
			</p>

		<?php else : /* approved or queued: the removal is under way, or done */ ?>

			<p class="tc-photo-progress-text"></p>

		<?php endif; ?>

		<?php if ( 'found' === $tc_state && $tc_scan['waiting'] > 0 ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: number of photos */
					esc_html__( '%s more are already waiting to be removed, and are not counted again here.', 'tcgiant-sync' ),
					esc_html( number_format_i18n( $tc_scan['waiting'] ) )
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( 'found' === $tc_state && $tc_scan['failed'] > 0 ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: number of photos */
					esc_html__( '%s could not be removed before and have been left where they are, because part of each may already be gone - an offload plugin\'s copy, say - and they are not offered again. The activity log names each one; delete them from the Media Library by hand if nothing needs them.', 'tcgiant-sync' ),
					esc_html( number_format_i18n( $tc_scan['failed'] ) )
				);
				?>
			</p>
		<?php endif; ?>
		</div>

		<div class="tc-photo-bar" style="display:none;background:#f0f0f0;border-radius:4px;height:14px;margin:6px 0 10px;max-width:520px;">
			<div style="background:#2271b1;height:100%;border-radius:4px;transition:width .3s;width:0;"></div>
		</div>
		<p class="tc-photo-status" style="color:#555;"></p>

		<?php if ( $tc_photo['paused_until'] > 0 ) : ?>
			<p>
				<?php
				if ( 'reading' === $tc_photo['paused_why'] ) {
					/* translators: %s: date and time */
					$tc_paused = __( 'Removal is paused until %s: a run was stopped while it was reading where photos are used on this site, before it had removed anything, and a run that stalls takes the eBay sync with it. It carries on by itself; the activity log says more.', 'tcgiant-sync' );
				} elseif ( 'slow' === $tc_photo['paused_why'] ) {
					/* translators: %s: date and time */
					$tc_paused = __( 'Removal is paused until %s: reading where photos are used on this site took longer than a run may, so nothing was removed, and runs that long would hold up the eBay sync. It tries again by itself; the activity log says more.', 'tcgiant-sync' );
				} elseif ( 'memory' === $tc_photo['paused_why'] ) {
					/* translators: %s: date and time */
					$tc_paused = __( 'Removal is paused until %s: reading where photos are used on this site needed more memory than a run had left, so it stopped before removing anything rather than run out, which would take the eBay sync with it. It tries again by itself; the activity log says more.', 'tcgiant-sync' );
				} else {
					/* translators: %s: date and time */
					$tc_paused = __( 'Removal is paused until %s: a run was stopped while another plugin was working on deleting a photo, and a run that stalls takes the eBay sync with it. It carries on by itself; the activity log says more.', 'tcgiant-sync' );
				}
				printf( esc_html( $tc_paused ), esc_html( wp_date( $tc_format, $tc_photo['paused_until'] ) ) );
				?>
			</p>
		<?php endif; ?>

		<?php if ( $tc_photo['left_files'] > 0 ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: number of files, 2: size such as "3.1 MB" */
					esc_html__( '%1$s file(s) of removed photos could not be deleted from the uploads folder (about %2$s). The activity log names each one; check that the web server is allowed to delete files there.', 'tcgiant-sync' ),
					esc_html( number_format_i18n( $tc_photo['left_files'] ) ),
					esc_html( $tc_photo['left_size'] )
				);
				?>
			</p>
		<?php endif; ?>

		<div class="tc-photo-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<?php if ( 'found' === $tc_state && $tc_scan['found'] > 0 && ! $tc_scan['stale'] ) : ?>
				<button type="button" class="button button-primary" id="tc-photo-remove">
					<?php
					printf(
						/* translators: %s: number of photos */
						esc_html( _n( 'Remove this %s photo', 'Remove these %s photos', $tc_scan['found'], 'tcgiant-sync' ) ),
						esc_html( number_format_i18n( $tc_scan['found'] ) )
					);
					?>
				</button>
			<?php endif; ?>

			<?php if ( 'approved' !== $tc_state ) : ?>
				<button type="button" class="button<?php echo '' === $tc_state ? ' button-primary' : ''; ?>" id="tc-photo-check">
					<?php
					if ( '' === $tc_state ) {
						esc_html_e( 'Check for leftover photos', 'tcgiant-sync' );
					} elseif ( 'scanning' === $tc_state ) {
						esc_html_e( 'Carry on checking', 'tcgiant-sync' );
					} else {
						esc_html_e( 'Check again', 'tcgiant-sync' );
					}
					?>
				</button>
			<?php endif; ?>
		</div>

		<?php if ( 'found' === $tc_state && $tc_scan['found'] > 0 ) : ?>
			<?php if ( $tc_scan['stale'] ) : ?>
				<p><strong><?php esc_html_e( 'This check is more than a week old. Check again before removing anything.', 'tcgiant-sync' ); ?></strong></p>
			<?php else : ?>
				<p style="color:#555;font-size:12px;max-width:760px;">
					<?php esc_html_e( 'Removal runs in the background, a batch at a time, and starts about 15 minutes after you press the button; you can leave this page. Each photo is checked again just before it is removed, and anything that has started being used since this check is kept. Deleting files cannot be undone, so make sure you have a recent backup of your uploads folder.', 'tcgiant-sync' ); ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $tc_photo['trashed'] > 0 ) : ?>
			<p style="color:#555;font-size:12px;max-width:760px;">
				<?php
				printf(
					/* translators: %s: number of products */
					esc_html( _n(
						'%s product is in the Trash. Its photos are kept while it is there, because restoring it - or relisting it on eBay - uses them again.',
						'%s products are in the Trash. Their photos are kept while they are there, because restoring a product - or relisting it on eBay - uses them again.',
						$tc_photo['trashed'],
						'tcgiant-sync'
					) ),
					esc_html( number_format_i18n( $tc_photo['trashed'] ) )
				);

				if ( $tc_after > 0 ) {
					echo ' ';
					printf(
						/* translators: %s: number of products */
						esc_html__( 'The photos of %s of them are removed automatically, about 15 minutes after the Trash is emptied: WordPress does this 30 days after a product was trashed, or you can empty it now under Products → Trash.', 'tcgiant-sync' ),
						esc_html( number_format_i18n( $tc_after ) )
					);
				}

				if ( $tc_photo['trashed_before'] > 0 ) {
					echo ' ';
					printf(
						/* translators: %s: number of products */
						esc_html( _n(
							'%s of them was already in the Trash when this version arrived, and its photos are not removed by themselves: once the Trash is emptied, check again here to see them before anything goes.',
							'%s of them were already in the Trash when this version arrived, and their photos are not removed by themselves: once the Trash is emptied, check again here to see them before anything goes.',
							$tc_photo['trashed_before'],
							'tcgiant-sync'
						) ),
						esc_html( number_format_i18n( $tc_photo['trashed_before'] ) )
					);
				}

				echo ' ';
				esc_html_e( 'A variation\'s own photo goes with the variation, which goes to the Trash with its product and is deleted when the Trash is emptied. A product deleted outright with nobody signed in - by a script, or WP-CLI without a user - can leave its variations behind, and then their photos stay.', 'tcgiant-sync' );
				?>
			</p>
		<?php endif; ?>
	</div>

	<script>
	(function($){
		var box     = $('#tc-photo-cleanup');
		var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
		var nonce   = '<?php echo esc_js( wp_create_nonce( 'tcgiant_sync_ajax' ) ); ?>';
		var t = {
			checking: '<?php echo esc_js( __( 'Checking… %1$s of about %2$s downloaded photos looked at.', 'tcgiant-sync' ) ); ?>',
			retry:    '<?php echo esc_js( __( 'The server did not answer. Trying again…', 'tcgiant-sync' ) ); ?>',
			confirm:  '<?php echo esc_js( __( 'Permanently delete %s photos left over from deleted products? This cannot be undone.', 'tcgiant-sync' ) ); ?>',
			removing: '<?php echo esc_js( __( 'Removing leftover photos in the background: %1$s removed so far (about %2$s freed), %3$s waiting. This carries on by itself; you can leave this page.', 'tcgiant-sync' ) ); ?>',
			paused:   '<?php echo esc_js( __( 'Paused until %s; it carries on by itself.', 'tcgiant-sync' ) ); ?>',
			queueing: '<?php echo esc_js( __( 'Getting %s photos ready for removal. Removal starts about 15 minutes after that; you can leave this page.', 'tcgiant-sync' ) ); ?>',
			finished: '<?php echo esc_js( __( 'Finished: %1$s photos removed, about %2$s freed.', 'tcgiant-sync' ) ); ?>',
			kept:     '<?php echo esc_js( __( '%s were kept because something started using them after the check.', 'tcgiant-sync' ) ); ?>',
			failed:   '<?php echo esc_js( __( '%s could not be removed and were left where they are; the activity log names each one.', 'tcgiant-sync' ) ); ?>',
			auto:     '<?php echo esc_js( __( 'Separately, %s photos of products deleted since the check are waiting to be removed.', 'tcgiant-sync' ) ); ?>'
		};
		function fmt(s){ var a = arguments; return s.replace(/%(\d)\$s|%s/g, function(m, n){ return String(a[n ? parseInt(n, 10) : 1]); }); }
		function num(n){ return Number(n || 0).toLocaleString(); }
		function bar(pct){ box.find('.tc-photo-bar').show().children().css('width', Math.min(100, Math.max(0, pct)) + '%'); }
		function say(s){ box.find('.tc-photo-status').text(s); }

		// The check: one short step per request until it is done, then reload to
		// show what it found. A step the host cuts off is simply asked for again -
		// the check carries on from the last page it counted.
		function step(restart, tries){
			box.find('button').prop('disabled', true);
			$.post(ajaxUrl, { action:'tcgiant_photo_scan', restart: restart ? 1 : 0, _ajax_nonce: nonce })
				.done(function(r){
					if (!r.success) { say(r.data && r.data.message ? r.data.message : 'Error'); box.find('button').prop('disabled', false); return; }
					var s = r.data.scan;
					bar(s.estimate ? (s.examined / s.estimate) * 100 : 0);
					say(fmt(t.checking, num(s.examined), num(s.estimate)));
					if (s.status === 'scanning') { setTimeout(function(){ step(false, 0); }, r.data.busy ? 3000 : 300); }
					else { location.reload(); }
				})
				.fail(function(){
					say(t.retry);
					if ((tries || 0) < 5) { setTimeout(function(){ step(false, (tries || 0) + 1); }, 5000); }
					else { box.find('button').prop('disabled', false); }
				});
		}

		$('#tc-photo-check').on('click', function(){ step(box.data('state') !== 'scanning', 0); });

		$('#tc-photo-remove').on('click', function(){
			if (!confirm(fmt(t.confirm, num(box.data('found'))))) { return; }
			box.find('button').prop('disabled', true);
			$.post(ajaxUrl, { action:'tcgiant_photo_remove', scan_id: box.data('scan-id'), _ajax_nonce: nonce }, function(r){
				if (!r.success) { alert(r.data && r.data.message ? r.data.message : 'Error'); }
				location.reload();
			});
		});

		// The removal: show where it has got to, and keep showing it while it runs.
		// Counted from the check's own photos only; photos of products deleted
		// since go by themselves, and are given a line of their own.
		function poll(){
			$.post(ajaxUrl, { action:'tcgiant_photo_status', _ajax_nonce: nonce }, function(r){
				if (!r.success) { return; }
				var d = r.data, s = d.scan, c = d.check, kept = s.kept + c.kept, text;
				if (s.status === 'approved') {
					text = fmt(t.queueing, num(s.found));
					bar(s.found ? ((s.queued + s.kept) / s.found) * 100 : 0);
				} else if (c.waiting > 0) {
					text = fmt(t.removing, num(c.removed), c.freed, num(c.waiting));
					if (d.paused_until) { text += ' ' + fmt(t.paused, new Date(d.paused_until * 1000).toLocaleString()); }
					bar(s.queued ? ((c.removed + c.kept + c.failed) / s.queued) * 100 : 0);
				} else {
					text = fmt(t.finished, num(c.removed), c.freed) + (kept ? ' ' + fmt(t.kept, num(kept)) : '') + (c.failed ? ' ' + fmt(t.failed, num(c.failed)) : '');
					bar(100);
				}
				if (d.auto_waiting > 0) { text += ' ' + fmt(t.auto, num(d.auto_waiting)); }
				box.find('.tc-photo-progress-text').text(text);
				if (s.status === 'approved' || c.waiting > 0 || d.auto_waiting > 0) { setTimeout(poll, 15000); }
			});
		}
		if (box.data('state') === 'approved' || box.data('state') === 'queued') { poll(); }
	})(jQuery);
	</script>

	<?php
	// The 1,536 px copies WordPress made before 3.27.4. See TCGiant_Sync_Size_Trim.
	$tc_trim    = TCGiant_Sync_Size_Trim::status();
	$tc_tcheck  = $tc_trim['check'];
	$tc_trun    = $tc_trim['run'];
	$tc_tstate  = $tc_tcheck['status'];
	$tc_rstate  = $tc_trun['status'];
	$tc_twhen   = $tc_tcheck['finished_at'] ? wp_date( $tc_format, $tc_tcheck['finished_at'] ) : '';
	$tc_busy    = in_array( $tc_rstate, array( 'running', 'waiting' ), true );
	$tc_caches  = $tc_trim['caches'];
	?>

	<div class="notice notice-info tc-size-trim" id="tc-size-trim" style="margin-top:16px;padding:12px 14px;"
		data-check-id="<?php echo esc_attr( $tc_tcheck['id'] ); ?>"
		data-photos="<?php echo esc_attr( $tc_tcheck['photos'] ); ?>"
		data-state="<?php echo esc_attr( $tc_tstate ); ?>"
		data-run="<?php echo esc_attr( $tc_rstate ); ?>">

		<h2 style="margin:0 0 8px;font-size:15px;"><?php esc_html_e( 'Old 1,536 px copies of downloaded photos', 'tcgiant-sync' ); ?></h2>

		<p>
			<?php esc_html_e( 'Until version 3.27.4, WordPress made a 1,536 px copy of every photo TCGiant downloaded at eBay\'s Original size (1,600 px): the same photo, 4% smaller, taking about a quarter of the space each such photo takes on your server. Without it your shoppers see the same photo: a browser that would have picked it, on a high-DPI phone say, picks the photo itself instead. Photos downloaded since 3.27.4 do not have it. This removes it from the photos downloaded before.', 'tcgiant-sync' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Only that copy goes, and only from photos TCGiant downloaded: each photo itself and all its other sizes stay, and so does a copy another Media Library entry uses. Your own uploads are never touched. A GIF, a photo larger than 2,048 px and a photo whose files are not on this server keep their copies. Tools that regenerate thumbnails no longer make the copy again for the photos TCGiant downloaded.', 'tcgiant-sync' ); ?>
		</p>

		<?php if ( ! empty( $tc_trim['offloaders'] ) ) : ?>

			<p><strong><?php echo esc_html( TCGiant_Sync_Size_Trim::offloaded_message( $tc_trim['offloaders'] ) ); ?></strong></p>
			<?php if ( $tc_busy ) : ?>
				<p><?php esc_html_e( 'The removal you started is waiting, and carries on by itself, from where it stopped, within an hour of the offload plugin being switched off.', 'tcgiant-sync' ); ?></p>
			<?php endif; ?>

		<?php else : ?>

			<div class="tc-trim-result">
			<?php if ( 'running' === $tc_rstate ) : ?>

				<p class="tc-trim-progress-text">
					<?php
					printf(
						/* translators: 1: number of photos, 2: percentage */
						esc_html__( 'Removing in the background: the copy has been taken off the records of %1$s photos so far (%2$s%% of the way through). This carries on by itself; you can leave this page.', 'tcgiant-sync' ),
						esc_html( number_format_i18n( $tc_trun['photos'] ) ),
						esc_html( number_format_i18n( $tc_trun['percent'] ) )
					);
					?>
				</p>

			<?php elseif ( 'waiting' === $tc_rstate ) : ?>

				<p class="tc-trim-progress-text">
					<?php
					printf(
						/* translators: 1: number of photos, 2: number of files, 3: size such as "21.4 GB", 4: date and time */
						esc_html__( 'The copy is off the records of %1$s photos, so your pages no longer offer it. Their %2$s files, about %3$s, are deleted from %4$s.', 'tcgiant-sync' ),
						esc_html( number_format_i18n( $tc_trun['photos'] ) ),
						esc_html( number_format_i18n( $tc_trun['files_waiting'] ) ),
						esc_html( $tc_trun['size_waiting'] ),
						esc_html( $tc_trun['files_due'] ? wp_date( $tc_format, $tc_trun['files_due'] ) : '' )
					);
					if ( $tc_trun['deleted'] > 0 ) {
						echo ' ';
						printf(
							/* translators: 1: number of files, 2: size such as "3.1 GB" */
							esc_html__( '%1$s deleted so far, about %2$s freed.', 'tcgiant-sync' ),
							esc_html( number_format_i18n( $tc_trun['deleted'] ) ),
							esc_html( $tc_trun['freed'] )
						);
					}
					?>
				</p>

			<?php elseif ( 'done' === $tc_rstate ) : ?>

				<p>
					<strong>
					<?php
					printf(
						/* translators: 1: date and time, 2: number of files, 3: number of photos, 4: size such as "21.4 GB" */
						esc_html__( 'Finished %1$s: %2$s old copies deleted from %3$s photos, about %4$s freed on this server.', 'tcgiant-sync' ),
						esc_html( $tc_trun['finished_at'] ? wp_date( $tc_format, $tc_trun['finished_at'] ) : '' ),
						esc_html( number_format_i18n( $tc_trun['deleted'] ) ),
						esc_html( number_format_i18n( $tc_trun['photos'] ) ),
						esc_html( $tc_trun['freed'] )
					);
					?>
					</strong>
				</p>
				<?php if ( $tc_trun['left_files'] > 0 || $tc_trun['given_up'] > 0 ) : ?>
					<p><?php esc_html_e( 'Some copies could not be removed and were left where they are; the activity log names each one.', 'tcgiant-sync' ); ?></p>
				<?php endif; ?>

			<?php endif; ?>

			<?php if ( ! $tc_busy ) : ?>
				<?php if ( '' === $tc_tstate || ( 'done' === $tc_rstate && $tc_tcheck['id'] === $tc_trun['check'] ) ) : ?>

					<p><?php esc_html_e( 'Check how many photos still have the copy and how much space it takes. Nothing is deleted by checking.', 'tcgiant-sync' ); ?></p>

				<?php elseif ( 'scanning' === $tc_tstate ) : ?>

					<p><?php esc_html_e( 'A check was started and has not finished.', 'tcgiant-sync' ); ?></p>

				<?php elseif ( 'found' === $tc_tstate && $tc_tcheck['photos'] > 0 ) : ?>

					<p>
						<strong>
						<?php
						printf(
							/* translators: 1: number of photos, 2: number of files, 3: size such as "21.4 GB" */
							esc_html( _n(
								'%1$s photo still has the old 1,536 px copy: %2$s file, about %3$s on this server.',
								'%1$s photos still have the old 1,536 px copy: %2$s files, about %3$s on this server.',
								$tc_tcheck['photos'],
								'tcgiant-sync'
							) ),
							esc_html( number_format_i18n( $tc_tcheck['photos'] ) ),
							esc_html( number_format_i18n( $tc_tcheck['files'] ) ),
							esc_html( $tc_tcheck['size'] )
						);
						?>
						</strong>
					</p>
					<?php if ( $tc_tcheck['not_here'] > 0 ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: number of photos */
								esc_html__( '%s more are left as they are, because their files are not on this server - an offload plugin may have moved them.', 'tcgiant-sync' ),
								esc_html( number_format_i18n( $tc_tcheck['not_here'] ) )
							);
							?>
						</p>
					<?php endif; ?>
					<?php if ( $tc_tcheck['shared'] > 0 ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: number of files */
								esc_html__( '%s more copies are shared with another Media Library entry, and not counted here: each is deleted only once nothing else uses it.', 'tcgiant-sync' ),
								esc_html( number_format_i18n( $tc_tcheck['shared'] ) )
							);
							?>
						</p>
					<?php endif; ?>
					<p style="color:#555;">
						<?php
						/* translators: %s: date and time of the check */
						printf( esc_html__( 'Checked %s.', 'tcgiant-sync' ), esc_html( $tc_twhen ) );
						?>
					</p>

				<?php elseif ( 'found' === $tc_tstate ) : ?>

					<p>
						<strong><?php esc_html_e( 'No photo has the old copy.', 'tcgiant-sync' ); ?></strong>
						<?php
						/* translators: %s: date and time of the check */
						printf( esc_html__( 'Checked %s.', 'tcgiant-sync' ), esc_html( $tc_twhen ) );
						?>
					</p>

				<?php endif; ?>
			<?php endif; ?>
			</div>

			<div class="tc-trim-bar" style="display:none;background:#f0f0f0;border-radius:4px;height:14px;margin:6px 0 10px;max-width:520px;">
				<div style="background:#2271b1;height:100%;border-radius:4px;transition:width .3s;width:0;"></div>
			</div>
			<p class="tc-trim-status" style="color:#555;"></p>

			<?php if ( $tc_trim['paused_until'] > 0 && $tc_busy ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: date and time */
						esc_html__( 'Paused until %s: a run was stopped part way, and a run that stops takes the eBay sync with it. It carries on by itself, from where it stopped; the activity log says more.', 'tcgiant-sync' ),
						esc_html( wp_date( $tc_format, $tc_trim['paused_until'] ) )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $tc_busy ) : ?>
				<p style="color:#555;font-size:12px;max-width:760px;">
					<?php
					esc_html_e( 'When every record is done, TCGiant purges the page cache of LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, Breeze or WP Fastest Cache, if one is active. The files are deleted at least a day after the records, and as long as LiteSpeed Cache or WP Rocket keeps pages, up to 8 days, so pages saved before still show the photo. If your site is behind a CDN or another cache that keeps whole pages, such as Cloudflare, purge it once the records are done and before the files go.', 'tcgiant-sync' );
					if ( $tc_trun['purged'] ) {
						echo ' ';
						printf(
							/* translators: %s: names of cache plugins, e.g. "LiteSpeed Cache" */
							esc_html__( 'Purged: %s.', 'tcgiant-sync' ),
							esc_html( implode( ', ', $tc_trun['purged'] ) )
						);
					} elseif ( $tc_caches ) {
						echo ' ';
						printf(
							/* translators: %s: names of cache plugins, e.g. "LiteSpeed Cache" */
							esc_html__( 'This site uses %s.', 'tcgiant-sync' ),
							esc_html( implode( ', ', $tc_caches ) )
						);
					}
					if ( $tc_trun['unpurged'] ) {
						echo ' ';
						printf(
							/* translators: %s: names of cache plugins, e.g. "Cloudflare" */
							esc_html__( '%s cannot be purged by TCGiant: purge it by hand before the files go.', 'tcgiant-sync' ),
							esc_html( implode( ', ', $tc_trun['unpurged'] ) )
						);
					}
					?>
				</p>
			<?php endif; ?>

			<div class="tc-trim-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
				<?php if ( ! $tc_busy && 'found' === $tc_tstate && $tc_tcheck['photos'] > 0 && ! $tc_tcheck['stale'] && $tc_tcheck['id'] !== $tc_trun['check'] ) : ?>
					<button type="button" class="button button-primary" id="tc-trim-start">
						<?php
						printf(
							/* translators: %s: number of photos */
							esc_html( _n( 'Remove the copy from this %s photo', 'Remove the copies from these %s photos', $tc_tcheck['photos'], 'tcgiant-sync' ) ),
							esc_html( number_format_i18n( $tc_tcheck['photos'] ) )
						);
						?>
					</button>
				<?php endif; ?>

				<?php if ( ! $tc_busy ) : ?>
					<button type="button" class="button<?php echo '' === $tc_tstate ? ' button-primary' : ''; ?>" id="tc-trim-check">
						<?php
						if ( '' === $tc_tstate ) {
							esc_html_e( 'Check for old copies', 'tcgiant-sync' );
						} elseif ( 'scanning' === $tc_tstate ) {
							esc_html_e( 'Carry on checking', 'tcgiant-sync' );
						} else {
							esc_html_e( 'Check again', 'tcgiant-sync' );
						}
						?>
					</button>
				<?php endif; ?>
			</div>

			<?php if ( ! $tc_busy && 'found' === $tc_tstate && $tc_tcheck['photos'] > 0 && $tc_tcheck['id'] !== $tc_trun['check'] ) : ?>
				<?php if ( $tc_tcheck['stale'] ) : ?>
					<p><strong><?php esc_html_e( 'This check is more than a week old. Check again before removing anything.', 'tcgiant-sync' ); ?></strong></p>
				<?php else : ?>
					<p style="color:#555;font-size:12px;max-width:760px;">
						<?php esc_html_e( 'Removal runs in the background, a batch at a time; you can leave this page. Each photo is checked again first. Its record is changed first, so your pages stop offering the copy straight away; when every record is done your page cache is purged if TCGiant can purge it, and the copy\'s file is deleted at least a day later, so pages saved before still show. Purge any CDN or other cache that keeps whole pages, such as Cloudflare, then too. Deleting files cannot be undone, so make sure you have a recent backup of your uploads folder.', 'tcgiant-sync' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

		<?php endif; ?>
	</div>

	<script>
	(function($){
		var box     = $('#tc-size-trim');
		var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
		var nonce   = '<?php echo esc_js( wp_create_nonce( 'tcgiant_sync_ajax' ) ); ?>';
		var t = {
			checking: '<?php echo esc_js( __( 'Checking… %1$s%% of the way through; %2$s photos with the copy so far.', 'tcgiant-sync' ) ); ?>',
			retry:    '<?php echo esc_js( __( 'The server did not answer. Trying again…', 'tcgiant-sync' ) ); ?>',
			confirm:  '<?php echo esc_js( __( 'Remove the old 1,536 px copy from %s photos? The photos themselves and their other sizes stay. When every record is done, TCGiant purges LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, Breeze or WP Fastest Cache; purge any other page cache or CDN, such as Cloudflare, yourself then, or a cached page can show a broken photo once the files go. The files are deleted at least a day after the records, and this cannot be undone.', 'tcgiant-sync' ) ); ?>',
			running:  '<?php echo esc_js( __( 'Removing in the background: the copy has been taken off the records of %1$s photos so far (%2$s%% of the way through). This carries on by itself; you can leave this page.', 'tcgiant-sync' ) ); ?>',
			waiting:  '<?php echo esc_js( __( 'The copy is off the records of %1$s photos, so your pages no longer offer it. Their %2$s files, about %3$s, are deleted from %4$s.', 'tcgiant-sync' ) ); ?>',
			deleted:  '<?php echo esc_js( __( '%1$s deleted so far, about %2$s freed.', 'tcgiant-sync' ) ); ?>',
			done:     '<?php echo esc_js( __( 'Finished: %1$s old copies deleted from %2$s photos, about %3$s freed on this server.', 'tcgiant-sync' ) ); ?>'
		};
		function fmt(s){ var a = arguments; return s.replace(/%%|%(\d)\$s|%s/g, function(m, n){ return m === '%%' ? '%' : String(a[n ? parseInt(n, 10) : 1]); }); }
		function num(n){ return Number(n || 0).toLocaleString(); }
		function bar(pct){ box.find('.tc-trim-bar').show().children().css('width', Math.min(100, Math.max(0, pct)) + '%'); }
		function say(s){ box.find('.tc-trim-status').text(s); }

		// The check: one short step per request until it is done, then reload to
		// show what it found. A step the host cuts off is simply asked for again.
		function step(restart, tries){
			box.find('button').prop('disabled', true);
			$.post(ajaxUrl, { action:'tcgiant_size_trim_check', restart: restart ? 1 : 0, _ajax_nonce: nonce })
				.done(function(r){
					if (!r.success) { say(r.data && r.data.message ? r.data.message : 'Error'); box.find('button').prop('disabled', false); return; }
					var c = r.data.check;
					bar(c.percent);
					say(fmt(t.checking, num(c.percent), num(c.photos)));
					if (c.status === 'scanning') { setTimeout(function(){ step(false, 0); }, r.data.busy ? 3000 : 300); }
					else { location.reload(); }
				})
				.fail(function(){
					say(t.retry);
					if ((tries || 0) < 5) { setTimeout(function(){ step(false, (tries || 0) + 1); }, 5000); }
					else { box.find('button').prop('disabled', false); }
				});
		}

		$('#tc-trim-check').on('click', function(){ step(box.data('state') !== 'scanning', 0); });

		$('#tc-trim-start').on('click', function(){
			if (!confirm(fmt(t.confirm, num(box.data('photos'))))) { return; }
			box.find('button').prop('disabled', true);
			$.post(ajaxUrl, { action:'tcgiant_size_trim_start', check_id: box.data('check-id'), _ajax_nonce: nonce }, function(r){
				if (!r.success) { alert(r.data && r.data.message ? r.data.message : 'Error'); }
				location.reload();
			});
		});

		// The removal: show where it has got to while it runs.
		function poll(){
			$.post(ajaxUrl, { action:'tcgiant_size_trim_status', _ajax_nonce: nonce }, function(r){
				if (!r.success) { return; }
				var u = r.data.run, text;
				if (u.status === 'running') {
					text = fmt(t.running, num(u.photos), num(u.percent));
					bar(u.percent);
				} else if (u.status === 'waiting') {
					text = fmt(t.waiting, num(u.photos), num(u.files_waiting), u.size_waiting, u.files_due ? new Date(u.files_due * 1000).toLocaleString() : '');
					if (u.deleted > 0) { text += ' ' + fmt(t.deleted, num(u.deleted), u.freed); }
					bar(100);
				} else {
					text = fmt(t.done, num(u.deleted), num(u.photos), u.freed);
					bar(100);
				}
				box.find('.tc-trim-progress-text').text(text);
				if (u.status === 'running' || u.status === 'waiting') { setTimeout(poll, 15000); }
			});
		}
		if (box.data('run') === 'running' || box.data('run') === 'waiting') { poll(); }
	})(jQuery);
	</script>

	<h2 style="margin:24px 0 0;font-size:15px;"><?php esc_html_e( 'Your own photographs, and the copies downloaded over them', 'tcgiant-sync' ); ?></h2>

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
				<?php esc_html_e( 'A photo the plugin cannot be sure about is not shown here and is never deleted: one with no file, one added while nobody was signed in, or one named the way the plugin used to save photos from eBay (s-l1600.jpg, 57.jpg). Before version 3.27.4, a download your host stopped part way through could leave a photo like that, and it was listed here as yours.', 'tcgiant-sync' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'A downloaded copy that another product or variation still shows, including one in the trash, is kept.', 'tcgiant-sync' ); ?>
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
