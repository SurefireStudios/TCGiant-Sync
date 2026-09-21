<?php
/**
 * Listings Management Page
 *
 * Displays all eBay-linked products with status, filters, and bulk actions.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Query parameters.
$current_status = isset( $_GET['listing_status'] ) ? sanitize_text_field( $_GET['listing_status'] ) : '';
$current_type   = isset( $_GET['listing_type'] ) ? sanitize_text_field( $_GET['listing_type'] ) : '';
$search         = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
$orderby        = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'updated_at';
$order          = isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'DESC';
$paged          = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$per_page       = 20;

// Query the custom listings table.
$result = TCGiant_Sync_DB::query( array(
	'status'   => $current_status,
	'type'     => $current_type,
	'search'   => $search,
	'orderby'  => $orderby,
	'order'    => $order,
	'per_page' => $per_page,
	'page'     => $paged,
) );

$items      = $result['items'];
$total      = $result['total'];
$total_pages = ceil( $total / $per_page );

// Get stats for filter counts.
$stats = TCGiant_Sync_DB::get_stats();

// Sort direction toggle.
$sort_order = 'ASC' === strtoupper( $order ) ? 'DESC' : 'ASC';

/**
 * Where a sortable column header points.
 *
 * Note the paged reset. add_query_arg() rebuilds from the current URL, so
 * every one of these headers used to carry the page number along with it:
 * re-sorting from page three dropped the seller into the middle of the newly
 * ordered list, showing rows that had nothing to do with what they clicked.
 */
$sort_url = function ( $column, $default = 'DESC' ) use ( $orderby, $sort_order ) {
	return esc_url( add_query_arg( array(
		'orderby' => $column,
		'order'   => $column === $orderby ? $sort_order : $default,
		'paged'   => 1,
	) ) );
};

/**
 * The classes WordPress needs before it will draw a sort arrow. Only the
 * Product column carried them, so the other five looked like plain text and
 * nobody could tell the list was sortable at all.
 */
$sort_class = function ( $column, $default = 'DESC' ) use ( $orderby, $order ) {
	return 'manage-column sortable ' . ( $column === $orderby ? 'sorted ' . strtolower( $order ) : strtolower( $default ) );
};

/**
 * Everything a form must carry so that using it does not silently undo
 * whatever else the seller had already narrowed the list down to.
 */
$carry = array(
	'listing_status' => $current_status,
	'listing_type'   => $current_type,
	's'              => $search,
	'orderby'        => $orderby,
	'order'          => $order,
);
$base_url = admin_url( 'admin.php?page=tcgiant-listings' );
?>

<div class="wrap tc-dashboard-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'eBay Listings', 'tcgiant-sync' ); ?></h1>
	<hr class="wp-header-end" />

	<?php TCGiant_Sync_Admin::instance()->render_tabs( 'listings' ); ?>

	<?php if ( empty( $stats['total'] ) && empty( $search ) ) : ?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'No eBay-linked products yet. Products appear here once they have been pushed to eBay, or after importing your existing listings with Fetch Inventory on the Import from eBay page.', 'tcgiant-sync' ); ?></p>
		</div>
	<?php else : ?>

	<!-- Filters -->
	<ul class="subsubsub">
		<li>
			<a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo empty( $current_status ) ? 'current' : ''; ?>">
				<?php printf( esc_html__( 'All %s', 'tcgiant-sync' ), '<span class="count">(' . esc_html( $stats['total'] ) . ')</span>' ); ?>
			</a> |
		</li>
		<li>
			<a href="<?php echo esc_url( add_query_arg( 'listing_status', 'Active', $base_url ) ); ?>" class="<?php echo 'Active' === $current_status ? 'current' : ''; ?>">
				<?php printf( esc_html__( 'Active %s', 'tcgiant-sync' ), '<span class="count">(' . esc_html( $stats['active'] ) . ')</span>' ); ?>
			</a> |
		</li>
		<li>
			<a href="<?php echo esc_url( add_query_arg( 'listing_status', 'Ended', $base_url ) ); ?>" class="<?php echo 'Ended' === $current_status ? 'current' : ''; ?>">
				<?php printf( esc_html__( 'Ended %s', 'tcgiant-sync' ), '<span class="count">(' . esc_html( $stats['ended'] ) . ')</span>' ); ?>
			</a>
		</li>
	</ul>

	<!-- Search Box -->
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="tcgiant-listings" />
		<?php foreach ( $carry as $carry_key => $carry_value ) : ?>
			<?php if ( 's' !== $carry_key && '' !== $carry_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $carry_key ); ?>" value="<?php echo esc_attr( $carry_value ); ?>" />
			<?php endif; ?>
		<?php endforeach; ?>
		<p class="search-box">
			<label class="screen-reader-text" for="listing-search-input"><?php esc_html_e( 'Search Listings', 'tcgiant-sync' ); ?></label>
			<input type="search" id="listing-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by title, eBay ID, or WC ID...', 'tcgiant-sync' ); ?>" />
			<input type="submit" id="search-submit" class="button" value="<?php esc_attr_e( 'Search', 'tcgiant-sync' ); ?>" />
		</p>
	</form>

	<!-- Type Filter -->
	<div class="tablenav top" style="clear: both;">
		<div class="alignleft actions">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display: inline;">
				<input type="hidden" name="page" value="tcgiant-listings" />
				<?php foreach ( $carry as $carry_key => $carry_value ) : ?>
					<?php if ( 'listing_type' !== $carry_key && '' !== $carry_value ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $carry_key ); ?>" value="<?php echo esc_attr( $carry_value ); ?>" />
					<?php endif; ?>
				<?php endforeach; ?>
				<label for="filter-by-type" class="screen-reader-text"><?php esc_html_e( 'Filter by type', 'tcgiant-sync' ); ?></label>
				<select name="listing_type" id="filter-by-type">
					<option value=""><?php esc_html_e( 'All types', 'tcgiant-sync' ); ?></option>
					<option value="FixedPriceItem" <?php selected( $current_type, 'FixedPriceItem' ); ?>><?php esc_html_e( 'Fixed Price', 'tcgiant-sync' ); ?></option>
					<option value="Chinese" <?php selected( $current_type, 'Chinese' ); ?>><?php esc_html_e( 'Auction', 'tcgiant-sync' ); ?></option>
				</select>
				<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'tcgiant-sync' ); ?>" />
			</form>
		</div>

		<!-- Bulk Actions -->
		<div class="alignleft actions" style="margin-left: 8px;">
			<select id="tc-bulk-action">
				<option value=""><?php esc_html_e( 'Bulk Actions', 'tcgiant-sync' ); ?></option>
				<option value="bulk_push"><?php esc_html_e( 'Push to eBay', 'tcgiant-sync' ); ?></option>
				<?php // Already implemented in the job runner and, until now, offered nowhere. ?>
				<option value="bulk_verify"><?php esc_html_e( 'Check before pushing', 'tcgiant-sync' ); ?></option>
				<option value="bulk_end"><?php esc_html_e( 'End Listing', 'tcgiant-sync' ); ?></option>
				<option value="bulk_relist"><?php esc_html_e( 'Relist', 'tcgiant-sync' ); ?></option>
				<option value="bulk_set_format"><?php esc_html_e( 'Change listing format', 'tcgiant-sync' ); ?></option>
			</select>

			<?php // Only meaningful for the format change, so it stays out of the way until then. ?>
			<span id="tc-format-fields" style="display:none;">
				<select id="tc-format-type">
					<option value=""><?php esc_html_e( 'Leave format', 'tcgiant-sync' ); ?></option>
					<?php foreach ( TCGiant_Sync_Catalog::LISTING_TYPES as $bf_val => $bf_label ) : ?>
						<option value="<?php echo esc_attr( $bf_val ); ?>"><?php echo esc_html( $bf_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select id="tc-format-duration">
					<option value=""><?php esc_html_e( 'Leave duration', 'tcgiant-sync' ); ?></option>
					<?php foreach ( TCGiant_Sync_Catalog::LISTING_DURATIONS as $bd_val => $bd_label ) : ?>
						<option value="<?php echo esc_attr( $bd_val ); ?>" data-types="<?php echo esc_attr( implode( ' ', array_keys( array_filter( TCGiant_Sync_Catalog::DURATIONS_BY_TYPE, function ( $ds ) use ( $bd_val ) { return in_array( $bd_val, $ds, true ); } ) ) ) ); ?>"><?php echo esc_html( $bd_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</span>

			<button type="button" id="tc-bulk-apply" class="button"><?php esc_html_e( 'Apply', 'tcgiant-sync' ); ?></button>

			<?php if ( $total > count( $items ) ) : ?>
				<label style="margin-left:8px;">
					<input type="checkbox" id="tc-select-all-matching" />
					<?php printf( esc_html__( 'Apply to all %s matching listings, not just this page', 'tcgiant-sync' ), number_format_i18n( $total ) ); ?>
				</label>
			<?php endif; ?>
		</div>

		<!-- Pagination -->
		<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php printf( esc_html( _n( '%s item', '%s items', $total, 'tcgiant-sync' ) ), number_format_i18n( $total ) ); ?>
			</span>
			<span class="pagination-links">
				<?php if ( $paged > 1 ) : ?>
					<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">‹</a>
				<?php endif; ?>
				<span class="paging-input">
					<?php echo esc_html( $paged ); ?> / <?php echo esc_html( $total_pages ); ?>
				</span>
				<?php if ( $paged < $total_pages ) : ?>
					<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>">›</a>
				<?php endif; ?>
			</span>
		</div>
		<?php endif; ?>
	</div>

	<!-- Listings Table -->
	<table class="wp-list-table widefat fixed striped">
		<thead>
		<tr>
				<td class="manage-column column-cb check-column" style="padding: 8px 4px;"><input type="checkbox" id="tc-cb-select-all" /></td>
				<th scope="col" class="<?php echo esc_attr( $sort_class( 'ebay_title', 'ASC' ) ); ?> column-title">
					<a href="<?php echo $sort_url( 'ebay_title', 'ASC' ); ?>">
						<span><?php esc_html_e( 'Product', 'tcgiant-sync' ); ?></span>
						<span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>
					</a>
				</th>
				<th scope="col" class="manage-column" style="width: 120px;"><?php esc_html_e( 'eBay Item ID', 'tcgiant-sync' ); ?></th>
				<th scope="col" class="<?php echo esc_attr( $sort_class( 'listing_type', 'ASC' ) ); ?>" style="width: 90px;">
					<a href="<?php echo $sort_url( 'listing_type', 'ASC' ); ?>">
						<span><?php esc_html_e( 'Type', 'tcgiant-sync' ); ?></span>
						<span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>
					</a>
				</th>
				<th scope="col" class="<?php echo esc_attr( $sort_class( 'listing_status', 'ASC' ) ); ?>" style="width: 80px;">
					<a href="<?php echo $sort_url( 'listing_status', 'ASC' ); ?>">
						<span><?php esc_html_e( 'Status', 'tcgiant-sync' ); ?></span>
						<span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>
					</a>
				</th>
				<th scope="col" class="<?php echo esc_attr( $sort_class( 'ebay_price', 'DESC' ) ); ?>" style="width: 80px;">
					<a href="<?php echo $sort_url( 'ebay_price', 'DESC' ); ?>">
						<span><?php esc_html_e( 'Price', 'tcgiant-sync' ); ?></span>
						<span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>
					</a>
				</th>
				<th scope="col" class="<?php echo esc_attr( $sort_class( 'ebay_quantity', 'DESC' ) ); ?>" style="width: 60px;">
					<a href="<?php echo $sort_url( 'ebay_quantity', 'DESC' ); ?>">
						<span><?php esc_html_e( 'Qty', 'tcgiant-sync' ); ?></span>
						<span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>
					</a>
				</th>
				<th scope="col" class="<?php echo esc_attr( $sort_class( 'ebay_end_time', 'DESC' ) ); ?>" style="width: 140px;">
					<a href="<?php echo $sort_url( 'ebay_end_time', 'DESC' ); ?>">
						<span><?php esc_html_e( 'Ends / Ended', 'tcgiant-sync' ); ?></span>
						<span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>
					</a>
				</th>
				<th scope="col" class="<?php echo esc_attr( $sort_class( 'last_synced', 'DESC' ) ); ?>" style="width: 120px;">
					<a href="<?php echo $sort_url( 'last_synced', 'DESC' ); ?>">
						<span><?php esc_html_e( 'Last Synced', 'tcgiant-sync' ); ?></span>
						<span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>
					</a>
				</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $items ) ) : ?>
				<tr>
					<td colspan="9"><?php esc_html_e( 'No listings found.', 'tcgiant-sync' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $items as $listing ) :
					$product_id   = (int) $listing['product_id'];
					$ebay_item_id = esc_html( $listing['ebay_item_id'] );
					$title        = ! empty( $listing['ebay_title'] ) ? esc_html( $listing['ebay_title'] ) : esc_html( get_the_title( $product_id ) );
					$status       = esc_html( $listing['listing_status'] );
					$type         = 'Chinese' === $listing['listing_type'] ? esc_html__( 'Auction', 'tcgiant-sync' ) : esc_html__( 'Fixed Price', 'tcgiant-sync' );
					// The live figures the query derives from WooCommerce, falling back to
					// what was recorded at push time on a store with no lookup table. The
					// stored numbers are written by a push, an import or a manual link and
					// by nothing else, so a sale used to leave this column showing stock
					// that had already gone - which is what a seller sees first on the
					// Ended tab, where everything has just sold.
					$price        = wc_price( isset( $listing['live_price'] ) ? $listing['live_price'] : $listing['ebay_price'] );
					$qty          = (int) ( isset( $listing['live_quantity'] ) ? $listing['live_quantity'] : $listing['ebay_quantity'] );
					$end_raw      = isset( $listing['ebay_end_time'] ) ? (string) $listing['ebay_end_time'] : '';
					$end_stamp    = '' !== $end_raw ? strtotime( $end_raw ) : false;
					$ended        = $end_stamp ? esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', $end_stamp + ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ) : '—';
					$synced       = ! empty( $listing['last_synced'] ) ? esc_html( human_time_diff( strtotime( $listing['last_synced'] ), current_time( 'timestamp' ) ) . ' ago' ) : '—';
					$ebay_url     = 'https://www.ebay.com/itm/' . $ebay_item_id;
					$edit_url     = get_edit_post_link( $product_id );

					// Status badge colors.
					$status_class = 'Active' === $listing['listing_status'] ? 'color: #46B450;' : 'color: #dc3232;';
				?>
				<tr>
					<th scope="row" class="check-column" style="padding: 8px 4px;"><input type="checkbox" class="tc-listing-cb" value="<?php echo esc_attr( $product_id ); ?>" /></th>
					<td class="column-title">
						<strong>
							<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo $title; ?></a>
						</strong>
						<div class="row-actions">
							<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'tcgiant-sync' ); ?></a> | </span>
							<span class="view"><a href="<?php echo esc_url( $ebay_url ); ?>" target="_blank"><?php esc_html_e( 'View on eBay', 'tcgiant-sync' ); ?> ↗</a></span>
						</div>
					</td>
					<td>
						<a href="<?php echo esc_url( $ebay_url ); ?>" target="_blank" title="<?php esc_attr_e( 'View on eBay', 'tcgiant-sync' ); ?>">
							<?php echo $ebay_item_id; ?>
						</a>
					</td>
					<td><?php echo $type; ?></td>
					<td><strong style="<?php echo $status_class; ?>"><?php echo $status; ?></strong></td>
					<td><?php echo $price; ?></td>
					<td><?php echo esc_html( $qty ); ?></td>
					<td><?php echo $ended; ?></td>
					<td><?php echo $synced; ?></td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<!-- Bottom Pagination -->
	<?php if ( $total_pages > 1 ) : ?>
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php printf( esc_html( _n( '%s item', '%s items', $total, 'tcgiant-sync' ) ), number_format_i18n( $total ) ); ?>
			</span>
			<span class="pagination-links">
				<?php if ( $paged > 1 ) : ?>
					<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">‹</a>
				<?php endif; ?>
				<span class="paging-input">
					<?php echo esc_html( $paged ); ?> / <?php echo esc_html( $total_pages ); ?>
				</span>
				<?php if ( $paged < $total_pages ) : ?>
					<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>">›</a>
				<?php endif; ?>
			</span>
		</div>
	</div>
	<?php endif; ?>

	<?php endif; // end empty check ?>

<!-- Job Progress Modal -->
<div id="tc-job-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:100000;">
	<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:30px;min-width:400px;max-width:500px;box-shadow:0 4px 20px rgba(0,0,0,0.3);">
		<h3 id="tc-job-title" style="margin:0 0 16px;font-size:16px;"></h3>
		<div style="background:#f0f0f0;border-radius:4px;height:20px;margin-bottom:10px;">
			<div id="tc-job-bar" style="background:#2271b1;height:100%;border-radius:4px;transition:width .3s;width:0;"></div>
		</div>
		<p id="tc-job-status" style="font-size:13px;color:#555;margin:0 0 8px;"></p>
		<div id="tc-job-errors" style="display:none;max-height:120px;overflow-y:auto;background:#fff0f0;border:1px solid #fcc;border-radius:4px;padding:8px;font-size:12px;margin-bottom:10px;"></div>
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

	// Select all checkboxes.
	$('#tc-cb-select-all').on('change',function(){ $('.tc-listing-cb').prop('checked', this.checked); });

	// The format selects belong to one action only.
	$('#tc-bulk-action').on('change',function(){
		$('#tc-format-fields').toggle($(this).val()==='bulk_set_format');
	}).trigger('change');

	// Only durations eBay honours for the chosen format. Offering the rest is
	// how a seller sets nine hundred products to something every push refuses.
	$('#tc-format-type').on('change',function(){
		var chosen=$(this).val(), $d=$('#tc-format-duration'), keep=true;
		$d.find('option').each(function(){
			var v=$(this).val();
			if(!v){return;}
			var legal = !chosen || ($(this).attr('data-types')||'').split(' ').indexOf(chosen)>=0;
			$(this).prop('disabled',!legal).toggle(legal);
			if(!legal && $d.val()===v){keep=false;}
		});
		if(!keep){$d.val('');}
	}).trigger('change');

	// Bulk apply.
	$('#tc-bulk-apply').on('click',function(){
		var action = $('#tc-bulk-action').val();
		if (!action) { alert('Select a bulk action.'); return; }

		var all = $('#tc-select-all-matching').is(':checked');
		var ids = [];
		$('.tc-listing-cb:checked').each(function(){ ids.push(parseInt($(this).val())); });
		if (!all && !ids.length) { alert('Select at least one listing, or tick the box to apply to everything matching.'); return; }

		var labels = { bulk_push:'Push to eBay', bulk_verify:'Check before pushing', bulk_end:'End Listing', bulk_relist:'Relist', bulk_set_format:'Change listing format' };
		var payload = { action:'tcgiant_job_start', type:action, product_ids:ids, _ajax_nonce:nonce };

		if (action === 'bulk_set_format') {
			payload.format_type = $('#tc-format-type').val()||'';
			payload.format_duration = $('#tc-format-duration').val()||'';
			if (!payload.format_type && !payload.format_duration) { alert('Choose a listing format, a duration, or both.'); return; }
		}

		// The filters go with it so the server can resolve "everything matching"
		// from the same query this page ran, rather than the page posting
		// thousands of ids into max_input_vars.
		if (all) {
			payload.select_all = 1;
			payload.listing_status = <?php echo wp_json_encode( $current_status ); ?>;
			payload.listing_type = <?php echo wp_json_encode( $current_type ); ?>;
			payload.s = <?php echo wp_json_encode( $search ); ?>;
		}

		var howMany = all ? <?php echo (int) $total; ?> : ids.length;
		if (!confirm('Run "' + labels[action] + '" on ' + howMany + ' listing(s)?')) return;

		// Start the job.
		$.post(ajaxUrl, payload, function(r){
			if (!r.success) { alert(r.data.message); return; }
			showProgressModal(labels[action], r.data.job_id, r.data.total);
		});
	});

	function showProgressModal(title, jobId, total) {
		$('#tc-job-title').text(title + ' — 0/' + total);
		$('#tc-job-bar').css('width','0%');
		$('#tc-job-status').text('Starting...');
		$('#tc-job-errors').hide().empty();
		$('#tc-job-done').hide();
		$('#tc-job-cancel').show().off('click').on('click',function(){
			$.post(ajaxUrl, { action:'tcgiant_job_cancel', job_id:jobId, _ajax_nonce:nonce });
			$('#tc-job-modal').fadeOut(200);
		});
		$('#tc-job-done').off('click').on('click',function(){
			$('#tc-job-modal').fadeOut(200);
			location.reload();
		});
		$('#tc-job-modal').fadeIn(200);
		processNext(jobId, total, title);
	}

	function processNext(jobId, total, title) {
		$.post(ajaxUrl, { action:'tcgiant_job_process', job_id:jobId, _ajax_nonce:nonce }, function(r){
			if (!r.success) { $('#tc-job-status').text('Error: ' + (r.data?r.data.message:'Unknown')); return; }
			var d = r.data, pct = Math.round((d.processed/total)*100);
			$('#tc-job-title').text(title + ' — ' + d.processed + '/' + total);
			$('#tc-job-bar').css('width', pct + '%');
			$('#tc-job-status').text(d.succeeded + ' succeeded, ' + d.failed + ' failed');
			if (d.errors && d.errors.length) {
				$('#tc-job-errors').show().html(d.errors.map(function(e){ return '<div style="color:#c00;padding:1px 0;">'+e+'</div>'; }).join(''));
			}
			if (d.status==='running') {
				setTimeout(function(){ processNext(jobId, total, title); }, 500);
			} else {
				$('#tc-job-cancel').hide();
				$('#tc-job-done').show();
				$('#tc-job-status').text('Complete! ' + d.succeeded + ' succeeded, ' + d.failed + ' failed.');
			}
		});
	}
})(jQuery);
</script>

</div>

