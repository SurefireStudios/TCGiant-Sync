<?php
/**
 * Harness: the quantity on the Listings screen, and how long a listing runs.
 *
 * A coin dealer wrote in: the Ended tab showed a quantity against items that
 * had sold, while opening the product showed none left. Both numbers were
 * ours, and they disagreed.
 *
 * Two separate faults produced the same "1".
 *
 * The first is staleness. ebay_quantity is written by a push, an import, a
 * manual link and the one-time backfill, and by nothing else. Every path that
 * ends a listing writes only the status meta - which the mirror does carry - so
 * a listing moved onto the Ended tab with its push-time quantity frozen beside
 * it. Nothing was keeping the number current, and nothing ever had been.
 *
 * The second is arithmetic. eBay's Item.Quantity is what the listing was
 * created with and keeps reporting that after the goods have gone; what is
 * available is Quantity minus QuantitySold, as eBay's own guidance says. The
 * bulk importer has always subtracted. The handler that links a product to an
 * existing listing did not, so linking to a sold single-item listing recorded a
 * quantity of one permanently, with no staleness involved at all. For a dealer
 * whose stock is one-of-a-kind, that is every linked listing that ever sold.
 *
 * The same seller asked whether 90-day fixed price listings exist. They do not:
 * eBay ended fixed-duration fixed-price listings in March 2019 and its Trading
 * API guide now says Good 'Til Cancelled is the only supported duration for
 * fixed price. We were still offering 30 Days and sending it, and eBay was
 * quietly making it GTC - so the choice was between two options that did the
 * same thing, and not the one the seller picked.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-66s got %-22s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$root  = dirname( __DIR__ );
$db    = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-db.php' );
$admin = (string) file_get_contents( $root . '/admin/class-tcgiant-sync-admin.php' );
$cat   = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-catalog.php' );
$exp   = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-exporter.php' );
$jobs  = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-jobs.php' );
$view  = (string) file_get_contents( $root . '/admin/views/listings.php' );

echo "\nWHAT IS LEFT, NOT WHAT WAS LISTED\n" . str_repeat( '=', 118 ) . "\n";

/** What the link handler did: store eBay's figure as it came. */
function old_available( array $item ) {
	return (int) ( $item['Quantity'] ?? 0 );
}

/** What it does now, and what the bulk importer always did. */
function new_available( array $item ) {
	$listed = isset( $item['Quantity'] ) ? (int) $item['Quantity'] : 0;
	$sold   = isset( $item['SellingStatus']['QuantitySold'] ) ? (int) $item['SellingStatus']['QuantitySold'] : 0;

	return max( 0, $listed - $sold );
}

// The reported case: a single coin, listed, sold, listing over.
$sold_out = array( 'Quantity' => 1, 'SellingStatus' => array( 'QuantitySold' => 1 ) );

check( 'a sold single item reads as none left', new_available( $sold_out ), 0 );
check( '  and used to read as one', old_available( $sold_out ), 1 );

check( 'a listing of ten with four gone leaves six', new_available( array( 'Quantity' => 10, 'SellingStatus' => array( 'QuantitySold' => 4 ) ) ), 6 );
check( 'an untouched listing is unaffected', new_available( array( 'Quantity' => 3, 'SellingStatus' => array( 'QuantitySold' => 0 ) ) ), 3 );
check( 'a listing eBay says nothing about is none', new_available( array() ), 0 );

// eBay has been seen to report more sold than listed on a multi-variation
// listing. A negative quantity would be written straight onto the product.
check( 'more sold than listed never goes below zero', new_available( array( 'Quantity' => 1, 'SellingStatus' => array( 'QuantitySold' => 5 ) ) ), 0 );

echo "\nTHE TWO SIDES AGREE ON WHAT THE FIGURE MEANS\n" . str_repeat( '=', 118 ) . "\n";

check( 'the link handler subtracts what sold', false !== strpos( $admin, '$available = max( 0, $listed - $sold );' ), true );
check( '  and records that, not eBay\'s raw figure', false !== strpos( $admin, "'ebay_quantity'  => \$available," ), true );
check( '  the raw figure is no longer written anywhere', false === strpos( $admin, "(int) ( \$item['Quantity'] ?? 0 )" ), true );
check( 'the bulk importer still subtracts too', false !== strpos( (string) file_get_contents( $root . '/includes/class-tcgiant-sync-mapper.php' ), 'max( 0, $quantity - $sold )' ), true );

echo "\nA DERIVED NUMBER CANNOT GO STALE\n" . str_repeat( '=', 118 ) . "\n";

// The stored column is only ever written by a push, an import or a link. The
// screen now asks WooCommerce instead, every time it draws.
check( 'the query reads live stock', false !== strpos( $db, 'COALESCE(lk.stock_quantity, l.ebay_quantity)' ), true );
check( '  and live price', false !== strpos( $db, 'COALESCE(lk.min_price, l.ebay_price)' ), true );
check( '  from WooCommerce\'s own lookup table', false !== strpos( $db, "wc_product_meta_lookup" ), true );
check( '  which is checked for rather than assumed', false !== strpos( $db, 'public static function lookup_table()' ), true );
check( '  falling back to what we recorded when there is none', false !== strpos( $db, "\$live_qty   = \$lookup ? 'COALESCE(lk.stock_quantity, l.ebay_quantity)' : 'l.ebay_quantity';" ), true );
check( 'the view prefers the live figure', false !== strpos( $view, "isset( \$listing['live_quantity'] ) ? \$listing['live_quantity'] : \$listing['ebay_quantity']" ), true );

// Sorting on the stored column while showing the live one would order the rows
// in a way the screen appears to contradict.
check( 'sorting uses the same figure the column shows', false !== strpos( $db, "'ebay_quantity'  => \$live_qty," ), true );
check( '  and the same for price', false !== strpos( $db, "'ebay_price'     => \$live_price," ), true );

echo "\nWHEN THE LISTING ENDS\n" . str_repeat( '=', 118 ) . "\n";

// Four date columns, every one of them about our own row. None could answer a
// seller asking when a listing finished.
check( 'the table records the end time', false !== strpos( $db, 'ebay_end_time VARCHAR(32)' ), true );
check( '  indexed, because it is sorted on', false !== strpos( $db, 'KEY ebay_end_time (ebay_end_time)' ), true );
check( '  the mirror keeps it current', false !== strpos( $db, "'_ebay_end_time'       => 'ebay_end_time'," ), true );
check( '  upsert may write it', false !== strpos( $db, "'ebay_end_time'," ), true );
check( '  and it is offered as a sort', false !== strpos( $db, "'ebay_end_time'  => 'l.ebay_end_time'," ), true );

// Rows copied before the column existed have to be filled, which INSERT IGNORE
// would skip entirely.
check( 'the backfill updates rows that already exist', false !== strpos( $db, 'ON DUPLICATE KEY UPDATE ebay_start_time = VALUES(ebay_start_time), ebay_end_time = VALUES(ebay_end_time)' ), true );
check( '  and it runs again to do it', false !== strpos( $db, "const BACKFILL_VERSION = '3';" ), true );
check( '  naming that one column and no other', 1, substr_count( $db, 'ON DUPLICATE KEY UPDATE' ) );

check( 'linking records the end time as well', false !== strpos( $admin, "'ebay_end_time'  => \$end_time," ), true );

echo "\nSORTING TAKES YOU TO THE TOP OF THE LIST\n" . str_repeat( '=', 118 ) . "\n";

// add_query_arg() rebuilds from the current URL, so every sort link carried the
// page number with it: re-sorting from page three landed the seller in the
// middle of the newly ordered list.
check( 'every sort link resets the page', false !== strpos( $view, "'paged'   => 1," ), true );
check( '  through one helper, not six copies', 1, substr_count( $view, '$sort_url = function' ) );
// Only the Product column carried the classes WordPress needs before it draws
// an arrow, so the other five read as plain text and nothing said the list
// could be sorted at all. Seven columns, seven sets of indicators.
check( 'every sortable header says it is sortable', substr_count( $view, 'sorting-indicators' ), 8 );
check( '  and each is classed for it', substr_count( $view, 'class="<?php echo esc_attr( $sort_class' ), 8 );

// Both filters were their own form and each dropped whatever the other had set.
check( 'the forms carry each other\'s state', 2, substr_count( $view, 'foreach ( $carry as $carry_key => $carry_value )' ) );

echo "\nHOW LONG AN EBAY LISTING RUNS\n" . str_repeat( '=', 118 ) . "\n";

// eBay's Trading API guide: for fixed price "the only supported listing
// duration for all marketplaces is Good 'Til Cancelled".
check( 'fixed price offers exactly one duration', false !== strpos( $cat, "'FixedPriceItem' => array( 'GTC' )," ), true );
check( '  30 Days is no longer among them', false === strpos( $cat, "array( 'GTC', 'Days_30' )" ), true );
check( 'auctions are unchanged', false !== strpos( $cat, "'Chinese'        => array( 'Days_1', 'Days_3', 'Days_5', 'Days_7', 'Days_10' )," ), true );

// Offered on the Settings page, then refused by the push path - a seller could
// pick 90 Days and find out weeks later.
check( '90 Days cannot be chosen at all', false === strpos( $cat, "'Days_90'" ), true );
check( '  nor 60 Days', false === strpos( $cat, "'Days_60'" ), true );

// The panel had its own copy of the rule, in JavaScript.
check( 'the product panel agrees with the constant', false !== strpos( $admin, "{'FixedPriceItem':['GTC'],'Chinese':" ), true );
check( '  and filters on load, not only on change', false !== strpos( $admin, ".trigger('change');" ), true );

check( 'no screen still claims 30 Days is supported', false === strpos( $admin, 'Fixed Price supports GTC' ), true );
check( '  including the settings page', false === strpos( (string) file_get_contents( $root . '/admin/views/settings.php' ), 'Fixed Price supports GTC' ), true );

echo "\nAND SITES THAT SAVED 30 DAYS KEEP WORKING\n" . str_repeat( '=', 118 ) . "\n";

/** The XML builder, verbatim: it has always corrected an unusable duration. */
function duration_sent( $type, $stored, array $by_type ) {
	$valid = $by_type[ $type ] ?? array( 'GTC' );

	return in_array( $stored, $valid, true ) ? $stored : $valid[0];
}

$by_type = array(
	'FixedPriceItem' => array( 'GTC' ),
	'Chinese'        => array( 'Days_1', 'Days_3', 'Days_5', 'Days_7', 'Days_10' ),
);

check( 'a saved 30 Days goes to eBay as GTC', duration_sent( 'FixedPriceItem', 'Days_30', $by_type ), 'GTC' );
check( '  which is what eBay did with it anyway', duration_sent( 'FixedPriceItem', 'Days_90', $by_type ), 'GTC' );
check( 'an auction keeps the duration it was given', duration_sent( 'Chinese', 'Days_7', $by_type ), 'Days_7' );

// The validator returns a hard WP_Error. Tightening the rule without this would
// have stopped every push on every site that had ever saved 30 Days.
check( 'a legacy fixed-price duration is not thrown back at the seller', false !== strpos( $exp, "if ( 'FixedPriceItem' === \$listing_type ) {" ), true );
check( '  while an auction duration is still checked', false !== strpos( $exp, "'invalid_duration'" ), true );

echo "\nWHAT THE PRODUCTS SCREEN COULD DO, AND THIS ONE NOW CAN\n" . str_repeat( '=', 118 ) . "\n";

check( 'the format can be changed in bulk', false !== strpos( $jobs, "case 'bulk_set_format':" ), true );
check( '  writing the same overrides the product panel writes', false !== strpos( $jobs, "update_post_meta( \$product_id, '_ebay_export_listing_type', \$want_type );" ), true );
check( '  and it is an accepted job type', false !== strpos( $jobs, "'bulk_set_format' );" ) || false !== strpos( $jobs, "'bulk_restore_images', 'bulk_set_format' )" ), true );
check( '  a duration the format cannot use is refused once, not per product', false !== strpos( $jobs, 'That duration is not one eBay allows for that listing format.' ), true );

// bulk_verify had a complete handler and appeared in no screen at all.
check( 'checking before pushing is finally offered', false !== strpos( $view, 'value="bulk_verify"' ), true );

check( 'a bulk action can reach past the current page', false !== strpos( $jobs, 'TCGiant_Sync_DB::find_product_ids(' ), true );
check( '  resolved from the filters the screen used', false !== strpos( $jobs, "'status' => sanitize_text_field( wp_unslash( \$_POST['listing_status'] ?? '' ) )," ), true );
check( '  sharing one filter builder with the screen', false !== strpos( $db, 'private static function build_where( array $args )' ), true );
check( '  and the page offers it only when there is more to reach', false !== strpos( $view, '$total > count( $items )' ), true );

echo "\nWHAT DBDELTA ACTUALLY READS\n" . str_repeat( '=', 118 ) . "\n";

// 3.21.0 shipped a migration that never ran, and nothing said so.
//
// dbDelta does not parse SQL. It splits the field block on newlines and takes
// the first word of each line as a column name, so the nine explanatory comments
// added inside the CREATE TABLE became a column called "--", every ALTER built
// from them was invalid, and ebay_end_time was never created. The version option
// is written straight afterwards regardless, so it was recorded as done.
//
// Nothing was visibly wrong until a seller sorted by the new column - which put
// a column that did not exist into ORDER BY, failed the query and emptied the
// screen. Every assertion in this file passed throughout, because they all read
// the source rather than the statement.
//
// So: read the statement the way dbDelta does.
$create = '';
if ( preg_match( '/\$sql = "CREATE TABLE.*?\) \{\$charset\};";/s', $db, $m ) ) {
	$create = $m[0];
}

check( 'the CREATE TABLE statement was found', '' !== $create, true );

$names = array();

if ( preg_match( '|\((.*)\)|ms', $create, $inner ) ) {
	foreach ( explode( "\n", trim( $inner[1] ) ) as $line ) {
		$line = trim( $line, " \t\n\r\0\x0B," );

		if ( '' === $line ) {
			continue;
		}

		preg_match( '|^([^ ]*)|', $line, $first );
		$names[] = trim( $first[1], '`' );
	}
}

$unreadable = array_values( array_filter( $names, function ( $name ) {
	return ! preg_match( '/^[A-Za-z_]/', $name );
} ) );

check( 'every line reads as a definition', $unreadable, array() );
check( '  no SQL comment inside the statement', false === strpos( $create, '--' ), true );
check( '  and no blank line either', false === strpos( $create, "\n\n" ), true );
check( 'the columns a sort depends on are among them', in_array( 'ebay_start_time', $names, true ) && in_array( 'ebay_end_time', $names, true ), true );

// The explanation still exists - it just lives where dbDelta will not read it.
check( 'the reason is recorded above the statement', false !== strpos( $db, 'dbDelta does not parse SQL' ), true );

echo "\nA MIGRATION THAT DID NOT HAPPEN IS NOT RECORDED AS DONE\n" . str_repeat( '=', 118 ) . "\n";

check( 'the columns are checked for after the upgrade', false !== strpos( $db, 'SHOW COLUMNS FROM' ), true );
check( '  and the version is only then recorded', strpos( $db, '$missing = array_diff( self::REQUIRED_COLUMNS, $present );' ) < strpos( $db, "update_option( 'tcgiant_listings_table_version'" ), true );
check( '  a missing column is said out loud', false !== strpos( $db, 'Listings table is missing column(s) after the upgrade' ), true );
check( '  and it will be tried again', false !== strpos( $db, 'It will be attempted again on the next admin page.' ), true );
check( 'the schema version moved, so repaired sites re-run it', false !== strpos( $db, "const TABLE_VERSION = '1.2.0';" ), true );

echo "\nAND A MISSING COLUMN COSTS A SORT, NOT THE LIST\n" . str_repeat( '=', 118 ) . "\n";

// The defence that would have turned this from "my listings vanished" into
// "that column will not sort yet".
check( 'a column that is not there is not offered as a sort', false !== strpos( $db, 'unset( $allowed_orderby[ $late_column ] );' ), true );
check( '  established once per request, not per row', false !== strpos( $db, 'private static $columns = null;' ), true );
check( '  over the columns a migration adds', false !== strpos( $db, "const REQUIRED_COLUMNS = array( 'ebay_start_time', 'ebay_end_time' );" ), true );

echo "\nWHEN EBAY STARTED THE LISTING\n" . str_repeat( '=', 118 ) . "\n";

// The seller's rotation - ten-day auction, GTC watched for ninety days, then
// ended and re-run - needs the eBay start date. WordPress's own publish date is
// no use: the products have been in WooCommerce for years.
$mapper   = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-mapper.php' );
$importer = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-importer.php' );
$cron     = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-cron.php' );

check( 'the importer records it', false !== strpos( $mapper, "'_ebay_start_time'       => \$ebay_item['ListingDetails']['StartTime'] ?? ''," ), true );
check( 'the delta importer records it', false !== strpos( $importer, "'_ebay_start_time', \$ebay_item['ListingDetails']['StartTime']" ), true );
check( 'the hourly refresh records it', false !== strpos( $cron, "'_ebay_start_time', \$item['ListingDetails']['StartTime']" ), true );
check( 'linking records it', false !== strpos( $admin, "\$start_time = (string) ( \$item['ListingDetails']['StartTime'] ?? '' );" ), true );
check( 'a push records it', false !== strpos( $exp, "'ebay_start_time' => gmdate(" ), true );

// Every one of those is beside an existing EndTime read, so no extra eBay call
// is made to get it.
check( 'it comes from the answer we already had', substr_count( $cron, "\$item['ListingDetails']" ) >= 2, true );

check( 'the table holds it', false !== strpos( $db, 'ebay_start_time VARCHAR(32)' ), true );
check( '  indexed for sorting', false !== strpos( $db, 'KEY ebay_start_time (ebay_start_time)' ), true );
check( '  mirrored like the rest', false !== strpos( $db, "'_ebay_start_time'     => 'ebay_start_time'," ), true );
check( '  and sortable', false !== strpos( $db, "'ebay_start_time' => 'l.ebay_start_time'," ), true );

check( 'the screen shows the date and how long ago', false !== strpos( $view, '$age_days = (int) floor( ( time() - $start_stamp ) / DAY_IN_SECONDS );' ), true );
check( '  under a Listed column', false !== strpos( $view, "esc_html_e( 'Listed', 'tcgiant-sync' )" ), true );
check( '  and says nothing rather than guessing when eBay has not told us', false !== strpos( $view, "\$listed       = '—';" ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
