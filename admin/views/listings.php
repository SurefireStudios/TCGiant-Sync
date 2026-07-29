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
$base_url = admin_url( 'admin.php?page=tcgiant-listings' );
?>

<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'eBay Listings', 'tcgiant-sync' ); ?></h1>
	<hr class="wp-header-end" />

	<?php if ( empty( $stats['total'] ) && empty( $search ) ) : ?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'No eBay-linked products found. Run a Full Sync from the Import page to populate this table.', 'tcgiant-sync' ); ?></p>
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
		<?php if ( $current_status ) : ?>
			<input type="hidden" name="listing_status" value="<?php echo esc_attr( $current_status ); ?>" />
		<?php endif; ?>
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
				<?php if ( $current_status ) : ?>
					<input type="hidden" name="listing_status" value="<?php echo esc_attr( $current_status ); ?>" />
				<?php endif; ?>
				<label for="filter-by-type" class="screen-reader-text"><?php esc_html_e( 'Filter by type', 'tcgiant-sync' ); ?></label>
				<select name="listing_type" id="filter-by-type">
					<option value=""><?php esc_html_e( 'All types', 'tcgiant-sync' ); ?></option>
					<option value="FixedPriceItem" <?php selected( $current_type, 'FixedPriceItem' ); ?>><?php esc_html_e( 'Fixed Price', 'tcgiant-sync' ); ?></option>
					<option value="Chinese" <?php selected( $current_type, 'Chinese' ); ?>><?php esc_html_e( 'Auction', 'tcgiant-sync' ); ?></option>
				</select>
				<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'tcgiant-sync' ); ?>" />
			</form>
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
				<th scope="col" class="manage-column column-title sortable <?php echo 'ebay_title' === $orderby ? 'sorted ' . strtolower( $order ) : 'asc'; ?>">
					<a href="<?php echo esc_url( add_query_arg( array( 'orderby' => 'ebay_title', 'order' => 'ebay_title' === $orderby ? $sort_order : 'ASC' ) ) ); ?>">
						<span><?php esc_html_e( 'Product', 'tcgiant-sync' ); ?></span>
						<span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>
					</a>
				</th>
				<th scope="col" class="manage-column" style="width: 120px;"><?php esc_html_e( 'eBay Item ID', 'tcgiant-sync' ); ?></th>
				<th scope="col" class="manage-column" style="width: 90px;">
					<a href="<?php echo esc_url( add_query_arg( array( 'orderby' => 'listing_type', 'order' => 'listing_type' === $orderby ? $sort_order : 'ASC' ) ) ); ?>">
						<?php esc_html_e( 'Type', 'tcgiant-sync' ); ?>
					</a>
				</th>
				<th scope="col" class="manage-column" style="width: 80px;">
					<a href="<?php echo esc_url( add_query_arg( array( 'orderby' => 'listing_status', 'order' => 'listing_status' === $orderby ? $sort_order : 'ASC' ) ) ); ?>">
						<?php esc_html_e( 'Status', 'tcgiant-sync' ); ?>
					</a>
				</th>
				<th scope="col" class="manage-column" style="width: 80px;">
					<a href="<?php echo esc_url( add_query_arg( array( 'orderby' => 'ebay_price', 'order' => 'ebay_price' === $orderby ? $sort_order : 'DESC' ) ) ); ?>">
						<?php esc_html_e( 'Price', 'tcgiant-sync' ); ?>
					</a>
				</th>
				<th scope="col" class="manage-column" style="width: 60px;">
					<a href="<?php echo esc_url( add_query_arg( array( 'orderby' => 'ebay_quantity', 'order' => 'ebay_quantity' === $orderby ? $sort_order : 'DESC' ) ) ); ?>">
						<?php esc_html_e( 'Qty', 'tcgiant-sync' ); ?>
					</a>
				</th>
				<th scope="col" class="manage-column" style="width: 140px;">
					<a href="<?php echo esc_url( add_query_arg( array( 'orderby' => 'last_synced', 'order' => 'last_synced' === $orderby ? $sort_order : 'DESC' ) ) ); ?>">
						<?php esc_html_e( 'Last Synced', 'tcgiant-sync' ); ?>
					</a>
				</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $items ) ) : ?>
				<tr>
					<td colspan="7"><?php esc_html_e( 'No listings found.', 'tcgiant-sync' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $items as $listing ) :
					$product_id   = (int) $listing['product_id'];
					$ebay_item_id = esc_html( $listing['ebay_item_id'] );
					$title        = ! empty( $listing['ebay_title'] ) ? esc_html( $listing['ebay_title'] ) : esc_html( get_the_title( $product_id ) );
					$status       = esc_html( $listing['listing_status'] );
					$type         = 'Chinese' === $listing['listing_type'] ? esc_html__( 'Auction', 'tcgiant-sync' ) : esc_html__( 'Fixed Price', 'tcgiant-sync' );
					$price        = wc_price( $listing['ebay_price'] );
					$qty          = (int) $listing['ebay_quantity'];
					$synced       = ! empty( $listing['last_synced'] ) ? esc_html( human_time_diff( strtotime( $listing['last_synced'] ), current_time( 'timestamp' ) ) . ' ago' ) : '—';
					$ebay_url     = 'https://www.ebay.com/itm/' . $ebay_item_id;
					$edit_url     = get_edit_post_link( $product_id );

					// Status badge colors.
					$status_class = 'Active' === $listing['listing_status'] ? 'color: #46B450;' : 'color: #dc3232;';
				?>
				<tr>
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
</div>
