<?php
/**
 * Harness: the Listings screen's Sold view, what it says about how each
 * listing ended, and the Stock Review lists beside it.
 *
 * A coin dealer asked for it. Their Ended tab held every listing that was
 * over on eBay - the coins that sold beside the ones that ran out unsold -
 * and the only way to tell which was which was to open each. The same tab
 * counted products long since in the trash, and offered Relist on all of it.
 *
 * Now the listings are in three views, Active, Ended and Sold, told apart by
 * WooCommerce's own stock status; every count is the rows its link shows; a
 * row says how it ended - sold on eBay, sold on the website, ended by the
 * shop, how many of a listing of several sold - from what was recorded about
 * that very listing; and the Sold view offers no Relist.
 *
 * Loaded for real: TCGiant_Sync_DB, TCGiant_Sync_Inventory (with the
 * runtime), TCGiant_Sync_Exporter and TCGiant_Sync_Admin for the end-time
 * rule, TCGiant_Sync_API for its helpers, TCGiant_Sync_Catalog, and
 * admin/views/listings.php itself. Posts, meta, categories, the listings
 * table and WooCommerce's lookup table are SQLite (tests/support/sold-world.php),
 * so every query runs. Nothing reaches eBay.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/sold-world.php';

class TCGiant_Sync_Logger {
	public static $lines = array();
	public static function log( $message, $level = 'info' ) { self::$lines[] = $message; }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
}
function is_admin() { return false; }
function wp_doing_cron() { return false; }

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-db.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $ROOT . '/includes/class-tcgiant-sync-exporter.php';
require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require $ROOT . '/admin/class-tcgiant-sync-admin.php';

/** The views, their counts, and the rows each shows, as the screen asks for them. */
function view_rows( $status, array $more = array() ) {
	$r = TCGiant_Sync_DB::query( array_merge( array( 'status' => $status, 'per_page' => 500, 'orderby' => 'product_id', 'order' => 'ASC' ), $more ) );
	return array_map( 'intval', wp_list_pluck( $r['items'], 'product_id' ) );
}
function view_total( $status, array $more = array() ) {
	$r = TCGiant_Sync_DB::query( array_merge( array( 'status' => $status, 'per_page' => 500 ), $more ) );
	return (int) $r['total'];
}
function view_of( $id ) {
	$r = TCGiant_Sync_DB::query( array( 'per_page' => 500 ) );
	foreach ( $r['items'] as $row ) {
		if ( (int) $row['product_id'] === (int) $id ) {
			return $row['listing_view'];
		}
	}
	return null;
}
function note_of( $id ) {
	return TCGiant_Sync_Inventory::end_note( $id, (string) get_post_meta( $id, '_ebay_item_id', true ), (string) view_of( $id ) );
}

/** The shop the matrix is drawn from. */
function sold_shop() {
	global $wpdb;
	$wpdb->reset();
	sold_forget_tables();
	$GLOBALS['OPTIONS'] = array();
	$settled = function ( $item, $listed, $sold ) {
		return array( '_ebay_stock_settled_at' => '2026-10-20 10:00:00', '_ebay_stock_settled_item' => $item, '_ebay_quantity_listed' => $listed, '_ebay_quantity_sold' => $sold );
	};
	// A coin, one listed, sold on eBay: settled from eBay's figures, out of stock.
	sold_product( 101, array_merge( array( '_ebay_item_id' => '9101', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 0, '_stock_status' => 'outofstock', '_ebay_end_reason' => 'sold_out', '_ebay_end_reason_item' => '9101' ), $settled( '9101', 1, 1 ) ) );
	// Sold on the website: the plugin ended its listing at 0 (3.28.0's mark).
	sold_product( 102, array( '_ebay_item_id' => '9102', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 0, '_stock_status' => 'outofstock', '_ebay_end_reason' => 'stock_zero', '_ebay_end_reason_item' => '9102', '_ebay_stock_settled_at' => '2026-10-20 10:00:00', '_ebay_stock_settled_item' => '9102' ) );
	// Ended unsold, still here to sell.
	sold_product( 103, array_merge( array( '_ebay_item_id' => '9103', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 1, '_stock_status' => 'instock' ), $settled( '9103', 1, 0 ) ) );
	// Five listed, two sold on eBay, three left.
	sold_product( 104, array_merge( array( '_ebay_item_id' => '9104', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 3, '_stock_status' => 'instock' ), $settled( '9104', 5, 2 ) ) );
	// A variable parent whose variations all sold: WooCommerce marks the parent out of stock.
	sold_product( 105, array_merge( array( '_type' => 'variable', '_ebay_item_id' => '9105', '_ebay_listing_status' => 'Ended', '_stock_status' => 'outofstock' ), $settled( '9105', 4, 4 ) ) );
	// A variable parent with one variation left: in stock.
	sold_product( 115, array_merge( array( '_type' => 'variable', '_ebay_item_id' => '9115', '_ebay_listing_status' => 'Ended', '_stock_status' => 'instock' ), $settled( '9115', 4, 3 ) ) );
	// On backorder: still sellable, so not sold.
	sold_product( 106, array( '_ebay_item_id' => '9106', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 0, '_stock_status' => 'onbackorder' ) );
	// In the trash, sold out.
	sold_product( 107, array( '_ebay_item_id' => '9107', '_ebay_listing_status' => 'Ended', '_stock_status' => 'outofstock' ), array(), 'trash' );
	// Live on eBay, out of stock here (eBay's out-of-stock control): Active.
	sold_product( 108, array( '_ebay_item_id' => '9108', '_ebay_listing_status' => 'Active', '_manage_stock' => 'yes', '_stock' => 0, '_stock_status' => 'outofstock' ) );
	// The shop ended it from WordPress.
	sold_product( 109, array( '_ebay_item_id' => '9109', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 2, '_stock_status' => 'instock', '_ebay_end_reason' => 'by_shop', '_ebay_end_reason_item' => '9109' ) );
	// Not a real product yet.
	sold_product( 110, array( '_ebay_item_id' => '9110', '_ebay_listing_status' => 'Active', '_stock_status' => 'instock' ), array(), 'auto-draft' );
	// No row in WooCommerce's lookup table: its own _stock_status decides.
	sold_product( 111, array( '_ebay_item_id' => '9111', '_ebay_listing_status' => 'Ended', '_stock_status' => 'outofstock' ), array(), 'publish', false );
	// Live, in stock.
	sold_product( 112, array( '_ebay_item_id' => '9112', '_ebay_listing_status' => 'Active', '_manage_stock' => 'yes', '_stock' => 4, '_stock_status' => 'instock' ), array( 'listing_type' => 'Chinese' ) );
	// A status from before the views, eBay's Completed, out of stock.
	sold_product( 113, array( '_ebay_item_id' => '9113', '_ebay_listing_status' => 'Completed', '_stock_status' => 'outofstock' ) );
	// Sold on the website though eBay sold some: three of five sold there, none left here.
	sold_product( 116, array_merge( array( '_ebay_item_id' => '9116', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 0, '_stock_status' => 'outofstock' ), $settled( '9116', 5, 3 ) ) );
	// A row whose product is gone for good.
	$wpdb->insert( 'wp_tcgiant_listings', array( 'product_id' => 114, 'ebay_item_id' => '9114', 'listing_status' => 'Ended' ) );
}

echo "\nTHREE VIEWS, BY WHAT WOOCOMMERCE HOLDS\n" . str_repeat( '=', 140 ) . "\n";

sold_shop();
check( 'quantity 1, sold on eBay: Sold', view_of( 101 ), 'Sold' );
check( 'sold on the website, listing ended at 0: Sold', view_of( 102 ), 'Sold' );
check( 'ended unsold, still in stock: Ended', view_of( 103 ), 'Ended' );
check( 'five listed, two sold, three left: Ended', view_of( 104 ), 'Ended' );
check( 'a variable parent whose variations all sold: Sold', view_of( 105 ), 'Sold' );
check( 'a variable parent with a variation left: Ended', view_of( 115 ), 'Ended' );
check( 'on backorder: Ended, not Sold', view_of( 106 ), 'Ended' );
check( 'live on eBay though out of stock here: Active', view_of( 108 ), 'Active' );
check( 'no lookup row: its own _stock_status says Sold', view_of( 111 ), 'Sold' );
check( 'eBay\'s Completed, out of stock: Sold', view_of( 113 ), 'Sold' );
check( 'trashed: in no view', view_of( 107 ), null );
check( 'an auto-draft: in no view', view_of( 110 ), null );
check( 'a row whose product is gone: in no view', view_of( 114 ), null );

check( 'the Active view', view_rows( 'Active' ), array( 108, 112 ) );
check( 'the Ended view', view_rows( 'Ended' ), array( 103, 104, 106, 109, 115 ) );
check( 'the Sold view', view_rows( 'Sold' ), array( 101, 102, 105, 111, 113, 116 ) );
check( 'All: every view, and nothing else', view_rows( '' ), array( 101, 102, 103, 104, 105, 106, 108, 109, 111, 112, 113, 115, 116 ) );
check( 'a link to a stored status from before the views still finds it', view_rows( 'Completed' ), array( 113 ) );

echo "\nEVERY COUNT IS THE ROWS ITS LINK SHOWS, IN ONE QUERY\n" . str_repeat( '=', 140 ) . "\n";

$before = count( $wpdb->queries );
$stats  = TCGiant_Sync_DB::get_stats();
$asked  = count( $wpdb->queries ) - $before;
check( 'the counts', $stats, array( 'active' => 2, 'ended' => 5, 'sold' => 6, 'total' => 13 ) );
check( '  in one query', $asked, 1 );
check( '  All is the three together', $stats['active'] + $stats['ended'] + $stats['sold'], $stats['total'] );
foreach ( array( 'Active' => 'active', 'Ended' => 'ended', 'Sold' => 'sold', '' => 'total' ) as $view => $key ) {
	$label = '' === $view ? 'All' : $view;
	check( "  {$label}: its count, the query's total and its rows agree", array( $stats[ $key ], view_total( $view ), count( view_rows( $view ) ) ), array( $stats[ $key ], $stats[ $key ], $stats[ $key ] ) );
	check( "  {$label}: and \"everything matching\" acts on those rows", TCGiant_Sync_DB::find_product_ids( array( 'status' => $view ) ), view_rows( $view ) );
}
check( 'the trashed product no longer counts in All, as it did', $stats['total'] < 15, true );

// Filters together: the type and the search narrow a view as they narrow All.
check( 'a view and a type', view_rows( 'Active', array( 'type' => 'Chinese' ) ), array( 112 ) );
check( 'a view and a search', view_rows( 'Sold', array( 'search' => '9116' ) ), array( 116 ) );
check( '  its total agrees', view_total( 'Sold', array( 'search' => '9116' ) ), 1 );

// "Everything matching" for a Relist takes the Ended view's rows only, on
// any view: neither what sold nor what is live.
check( 'ended_only: the Ended view\'s rows, from All', TCGiant_Sync_DB::find_product_ids( array( 'ended_only' => true ) ), array( 103, 104, 106, 109, 115 ) );
check( '  asked of the Sold view, nothing', TCGiant_Sync_DB::find_product_ids( array( 'status' => 'Sold', 'ended_only' => true ) ), array() );
check( '  asked of the Active view, nothing', TCGiant_Sync_DB::find_product_ids( array( 'status' => 'Active', 'ended_only' => true ) ), array() );

check( 'no query was refused', $wpdb->errors, array() );

echo "\nNO STRING IS COMPARED ACROSS TWO TABLES\n" . str_repeat( '=', 140 ) . "\n";

// MySQL and MariaDB merge the collations of COALESCE's (and CASE's)
// arguments. A string column of WooCommerce's lookup table and one of
// wp_postmeta, in the same character set and different collations - a shop
// older than WordPress 4.2 converted to utf8mb4_unicode_ci, beside
// WooCommerce's table in utf8mb4_unicode_520_ci, or a MySQL 8 default of
// utf8mb4_0900_ai_ci - merge to no collation at all (derivation NONE).
// COALESCE allows that; comparing the result with 'outofstock' does not, and
// the statement fails before it reads a row: "Illegal mix of collations".
// Every view, every count and Auto-Relist would have broken on such a site.
// SQLite merges no collations, so it cannot show the failure; what it can
// show is the shape that cannot have it: each column compared with the
// literal on its own side, and only the 1/0 answers merged.
sold_shop();
$wpdb->queries = array();
TCGiant_Sync_DB::get_stats();
TCGiant_Sync_DB::query( array( 'status' => 'Sold' ) );
TCGiant_Sync_DB::find_product_ids( array( 'status' => 'Ended', 'ended_only' => true ) );
$asked = implode( "\n", $wpdb->queries );
list( $flag_join, $flag ) = TCGiant_Sync_DB::out_of_stock_sql( 'l.product_id' );
check( 'the flag, with a lookup table', $flag, "COALESCE(lk.stock_status = 'outofstock', (SELECT ss.meta_value = 'outofstock' FROM wp_postmeta ss WHERE ss.post_id = l.product_id AND ss.meta_key = '_stock_status' LIMIT 1), 0)" );
check( '  every outofstock compared with one column, on its own side', preg_match_all( "/'outofstock'/", $asked ), preg_match_all( "/(?:lk\\.stock_status|ss\\.meta_value) = 'outofstock'/", $asked ) );
check( '  and there are some', preg_match_all( "/'outofstock'/", $asked ) > 0, true );
check( '  no COALESCE compared with a string', preg_match( "/COALESCE(\\((?:[^()]++|(?1))*\\))\\s*(?:=|<>|!=)\\s*'/", $asked ), 0 );
check( '  nor a string with a COALESCE', preg_match( "/'\\s*(?:=|<>|!=)\\s*COALESCE\\(/", $asked ), 0 );
check( '  no CASE compared with anything', preg_match( '/\\bEND\\s*(?:=|<>|!=)/', $asked ), 0 );
check( '  the views compare the flag with a number', array( false !== strpos( $asked, $flag . ' = 1' ), false !== strpos( $asked, $flag . ' = 0' ) ), array( true, true ) );
check( '  the old string COALESCE is gone', false === strpos( $asked, 'COALESCE(lk.stock_status,' ), true );

echo "\nWITHOUT WOOCOMMERCE'S LOOKUP TABLE, THE PRODUCT'S OWN STATUS\n" . str_repeat( '=', 140 ) . "\n";

$wpdb->db->exec( 'DROP TABLE wp_wc_product_meta_lookup' );
sold_forget_tables();
list( $flag_join, $flag ) = TCGiant_Sync_DB::out_of_stock_sql( 'l.product_id' );
check( 'the flag, without one: its own status, compared on its own side', array( $flag_join, $flag ), array( '', "COALESCE((SELECT ss.meta_value = 'outofstock' FROM wp_postmeta ss WHERE ss.post_id = l.product_id AND ss.meta_key = '_stock_status' LIMIT 1), 0)" ) );
check( 'the same counts', TCGiant_Sync_DB::get_stats(), array( 'active' => 2, 'ended' => 5, 'sold' => 6, 'total' => 13 ) );
check( 'the same Sold view', view_rows( 'Sold' ), array( 101, 102, 105, 111, 113, 116 ) );
check( '  read from _stock_status', false !== strpos( (string) end( $wpdb->queries ), "ss.meta_key = '_stock_status'" ), true );
check( 'no query was refused', $wpdb->errors, array() );

echo "\nWHAT A ROW SAYS ABOUT HOW IT ENDED\n" . str_repeat( '=', 140 ) . "\n";

sold_shop();
check( 'quantity 1 sold on eBay', note_of( 101 ), 'Sold on eBay' );
check( 'ended at 0 after a website sale', note_of( 102 ), 'Out of stock on your website' );
check( 'ended unsold', note_of( 103 ), 'Ended unsold' );
check( 'five listed, two sold', note_of( 104 ), '2 of 5 sold on eBay' );
check( 'a variable listing that sold out', note_of( 105 ), 'Sold on eBay: all 4' );
check( 'ended by the shop', note_of( 109 ), 'Ended by you' );
check( 'three of five sold on eBay, the rest gone here', note_of( 116 ), 'Out of stock on your website - 3 of 5 sold on eBay' );
check( 'nothing recorded: nothing said', note_of( 106 ), '' );
check( 'live: nothing said', note_of( 112 ), '' );

// Only what was recorded about this listing: a product relisted carries the
// old listing's marks no further.
update_post_meta( 109, '_ebay_item_id', '9209' );
check( 'the shop ended an earlier listing: nothing said of this one', TCGiant_Sync_Inventory::end_note( 109, '9209', 'Ended' ), '' );
update_post_meta( 104, '_ebay_item_id', '9204' );
check( 'eBay\'s figures for an earlier listing: not said of this one', TCGiant_Sync_Inventory::end_note( 104, '9204', 'Ended' ), '' );
// The plugin ended it at 0, and the shop has since restocked it.
check( 'ended at 0, restocked since', TCGiant_Sync_Inventory::end_note( 102, '9102', 'Ended' ), 'Ended when your stock reached 0' );
// The shop ended a listing of five that had sold two.
update_post_meta( 104, '_ebay_end_reason', 'by_shop' );
update_post_meta( 104, '_ebay_end_reason_item', '9104' );
check( 'ended by the shop, two of five sold', TCGiant_Sync_Inventory::end_note( 104, '9104', 'Ended' ), 'Ended by you - 2 of 5 sold on eBay' );

echo "\nWHY IT ENDED IS RECORDED WHEN IT ENDS, AND SPENT WHEN A NEW LISTING STARTS\n" . str_repeat( '=', 140 ) . "\n";

sold_shop();
// The shop ends a listing from WordPress; eBay's answer gives the end.
TCGiant_Sync_Inventory::record_shop_end( 112, '9112', array( 'EndTime' => '2026-10-29T09:15:00.000Z' ) );
check( 'End Listing: Ended, eBay\'s end time, and why', array( get_post_meta( 112, '_ebay_listing_status', true ), get_post_meta( 112, '_ebay_end_time', true ), get_post_meta( 112, '_ebay_end_reason', true ), get_post_meta( 112, '_ebay_end_reason_item', true ) ), array( 'Ended', '2026-10-29T09:15:00.000Z', 'by_shop', '9112' ) );
check( '  the listings table follows', $wpdb->get_var( 'SELECT listing_status FROM wp_tcgiant_listings WHERE product_id = 112' ), 'Ended' );
check( '  and it is in the Ended view, saying so', array( view_of( 112 ), note_of( 112 ) ), array( 'Ended', 'Ended by you' ) );
check( '  nothing is settled by it', get_post_meta( 112, '_ebay_stock_settled_at', true ), '' );
TCGiant_Sync_Inventory::record_shop_end( 108, '9108', null );
check( 'no end time in the answer: now', 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.000Z$/', get_post_meta( 108, '_ebay_end_time', true ) ), true );
// It replaces a reason left for that listing by a push that could not tell.
update_post_meta( 103, '_ebay_end_reason', 'stock_zero' );
update_post_meta( 103, '_ebay_end_reason_item', '9103' );
TCGiant_Sync_Inventory::record_shop_end( 103, '9103', array() );
check( 'the shop\'s End replaces another reason for the same listing', get_post_meta( 103, '_ebay_end_reason', true ), 'by_shop' );

// eBay's final figures: every unit sold.
sold_product( 120, array( '_ebay_item_id' => '9120', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 1, '_stock_status' => 'instock' ) );
check( 'a settle where every unit sold: stock 0', TCGiant_Sync_Inventory::apply_ended_listing_stock( 120, 1, 1 ), 0 );
check( '  recorded as sold out, for this listing', array( get_post_meta( 120, '_ebay_end_reason', true ), get_post_meta( 120, '_ebay_end_reason_item', true ) ), array( 'sold_out', '9120' ) );
check( '  and it is in the Sold view, saying so', array( view_of( 120 ), note_of( 120 ) ), array( 'Sold', 'Sold on eBay' ) );
sold_product( 121, array( '_ebay_item_id' => '9121', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 3, '_stock_status' => 'instock' ) );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 121, 5, 2 );
check( 'two of five sold: no reason recorded', get_post_meta( 121, '_ebay_end_reason', true ), '' );
sold_product( 122, array( '_ebay_item_id' => '9122', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 0, '_stock_status' => 'outofstock', '_ebay_end_reason' => 'stock_zero', '_ebay_end_reason_item' => '9122' ) );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 122, 1, 1 );
check( 'a reason already recorded for the listing stands', get_post_meta( 122, '_ebay_end_reason', true ), 'stock_zero' );
sold_product( 123, array( '_ebay_item_id' => '9123', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 1, '_stock_status' => 'instock', '_ebay_end_reason' => 'by_shop', '_ebay_end_reason_item' => '9023' ) );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 123, 1, 1 );
check( 'one left by an earlier listing does not', array( get_post_meta( 123, '_ebay_end_reason', true ), get_post_meta( 123, '_ebay_end_reason_item', true ) ), array( 'sold_out', '9123' ) );
sold_product( 124, array( '_ebay_item_id' => '9124', '_ebay_listing_status' => 'Ended', '_manage_stock' => 'yes', '_stock' => 0, '_stock_status' => 'outofstock' ) );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 124, 0, 0 );
check( 'a listing of nothing is not a sell-out', get_post_meta( 124, '_ebay_end_reason', true ), '' );

// A new listing: the reason goes with the rest of the settle.
TCGiant_Sync_Inventory::clear_settled_mark( 120 );
check( 'live again: the reason is spent', array( get_post_meta( 120, '_ebay_end_reason', true ), get_post_meta( 120, '_ebay_end_reason_item', true ), get_post_meta( 120, '_ebay_quantity_sold', true ) ), array( '', '', '' ) );
check( '  a duplicate of the product does not carry it', array_values( array_intersect( array( '_ebay_end_reason', '_ebay_end_reason_item' ), TCGiant_Sync_Listing_Link::listing_identity_meta_keys() ) ), array( '_ebay_end_reason', '_ebay_end_reason_item' ) );

echo "\nA GOOD 'TIL CANCELLED LISTING PAST ITS RENEWAL DATE IS NOT ENDED\n" . str_repeat( '=', 140 ) . "\n";

$past   = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 3 * DAY_IN_SECONDS );
$future = gmdate( 'Y-m-d\TH:i:s.000\Z', time() + 3 * DAY_IN_SECONDS );
check( 'GTC, its date gone by: not ended', TCGiant_Sync_Admin::listing_has_ended( '9001', 'Active', $past, 'GTC', 'FixedPriceItem' ), false );
check( 'seven days, its date gone by: ended', TCGiant_Sync_Admin::listing_has_ended( '9001', 'Active', $past, 'Days_7', 'FixedPriceItem' ), true );
check( 'seven days, still to come: not ended', TCGiant_Sync_Admin::listing_has_ended( '9001', 'Active', $future, 'Days_7', 'Chinese' ), false );
check( 'an auction with no duration recorded, gone by: ended', TCGiant_Sync_Admin::listing_has_ended( '9001', 'Active', $past, '', 'Chinese' ), true );
check( 'fixed price with no duration recorded, gone by: not judged by its date', TCGiant_Sync_Admin::listing_has_ended( '9001', 'Active', $past, '', 'FixedPriceItem' ), false );
check( 'recorded Ended: ended, whatever its duration', TCGiant_Sync_Admin::listing_has_ended( '9001', 'Ended', $future, 'GTC', '' ), true );
check( 'no listing: not ended', TCGiant_Sync_Admin::listing_has_ended( '', 'Ended', $past, 'Days_7', '' ), false );

// The push's own copy, for what a push that did not finish says.
sold_product( 130, array( '_ebay_item_id' => '9130', '_ebay_listing_status' => 'Active', '_ebay_end_time' => $past, '_ebay_listing_duration' => 'GTC' ), false );
sold_product( 131, array( '_ebay_item_id' => '9131', '_ebay_listing_status' => 'Active', '_ebay_end_time' => $past, '_ebay_listing_duration' => 'Days_10', '_ebay_listing_type' => 'Chinese' ), false );
check( 'the push agrees: a GTC listing past its date is not ended', TCGiant_Sync_Exporter::linked_listing_ended( 130 ), false );
check( '  a ten-day auction past its date is', TCGiant_Sync_Exporter::linked_listing_ended( 131 ), true );

// The products list: its badge, its button and its End Listing.
$admin_src = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
check( 'the products list has no rule of its own left', substr_count( $admin_src, "( 'Ended' === \$listing_status ) ||" ), 0 );
check( '  its badge, Relist button and End Listing ask the shared one', substr_count( $admin_src, 'listing_has_ended( $ebay_item_id, $listing_status, $end_time, $listing_dur, $listing_type )' ), 2 );

echo "\nTHE LISTINGS SCREEN ITSELF\n" . str_repeat( '=', 140 ) . "\n";

/** The page as WordPress would draw it for $get. */
function listings_page( array $get ) {
	$_GET = $get;
	ob_start();
	include $GLOBALS['ROOT'] . '/admin/views/listings.php';
	return (string) ob_get_clean();
}

sold_shop();
$sold_page = listings_page( array( 'listing_status' => 'Sold' ) );
check( 'a Sold link, with its count', 1 === preg_match( '/listing_status=Sold" class="current"[^>]*>\s*Sold <span class="count">\(6\)<\/span>/', $sold_page ), true );
check( 'All counts what the views hold', false !== strpos( $sold_page, 'All <span class="count">(13)</span>' ), true );
check( 'the Sold view offers no Relist', false === strpos( $sold_page, 'value="bulk_relist"' ), true );
check( '  but still End Listing and the rest', false !== strpos( $sold_page, 'value="bulk_end"' ) && false !== strpos( $sold_page, 'value="bulk_push"' ), true );
check( '  its rows say Sold, and how', false !== strpos( $sold_page, '>Sold</strong>' ) && false !== strpos( $sold_page, 'Out of stock on your website - 3 of 5 sold on eBay' ), true );
check( '  the trashed product is not drawn', false === strpos( $sold_page, 'Coin 107' ), true );
$cached = preg_grep( '/^meta cache /', $GLOBALS['EVENTS'] );
$cached = $cached ? explode( ',', substr( (string) end( $cached ), 11 ) ) : array();
sort( $cached );
check( '  the rows\' meta is read in one go', $cached, array( '101', '102', '105', '111', '113', '116' ) );
$ended_page = listings_page( array( 'listing_status' => 'Ended' ) );
check( 'the Ended view still offers Relist', false !== strpos( $ended_page, 'value="bulk_relist"' ), true );
check( '  and says which ended unsold', false !== strpos( $ended_page, 'Ended unsold' ), true );
check( 'All offers Relist', false !== strpos( listings_page( array() ), 'value="bulk_relist"' ), true );

echo "\nSTOCK REVIEW: SOLD OUT AND STILL HERE, ONLY WHAT THE BUTTON WOULD MOVE\n" . str_repeat( '=', 140 ) . "\n";

/** A product the old settle, or this one, left ended and out of stock. */
function sold_out_product( $id, array $more = array(), $status = 'publish' ) {
	sold_product( $id, array_merge( array(
		'_ebay_item_id'            => '95' . $id,
		'_ebay_listing_status'     => 'Ended',
		'_manage_stock'            => 'yes',
		'_stock'                   => 0,
		'_stock_status'            => 'outofstock',
		'_ebay_stock_settled_at'   => '2026-10-20 10:00:00',
		'_ebay_stock_settled_item' => '95' . $id,
		'_ebay_quantity_listed'    => 1,
		'_ebay_quantity_sold'      => 1,
	), $more ), false, $status );
}

sold_shop();
$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = array( 'preserve_woo_category_ids' => array( '55' ) );
sold_out_product( 201 );                                                              // imported, sold out on eBay: listed
sold_out_product( 202, array( '_ebay_export_status' => 'pushed' ) );                  // the shop pushed it: kept
sold_out_product( 203, array( '_ebay_export_last_pushed' => '2026-01-01 10:00:00' ) );// pushed once, re-push queued: kept
sold_out_product( 204, array( '_ebay_export_status' => 'pushing' ) );                 // a push under way: kept
sold_out_product( 205, array( '_ebay_export_status' => 'failed' ) );                  // a push that did not finish: kept
sold_out_product( 206, array( '_ebay_export_status' => 'queued' ) );                  // imported, a push queued: listed, as before
sold_out_product( 207 );                                                              // in a Preserve category: kept
sold_out_product( 208, array( '_ebay_quantity_listed' => 5, '_ebay_quantity_sold' => 3 ) );   // eBay sold three of five: not a sell-out
sold_out_product( 209, array( '_ebay_restock_reviewed' => 'zeroed' ) );               // set to 0 from Stock Review as a website sale
sold_out_product( 210, array( '_ebay_stock_settled_item' => '9999' ) );               // eBay's figures are an earlier listing's
sold_out_product( 211, array(), 'trash' );                                            // in the trash
sold_out_product( 212, array( '_stock_status' => 'instock', '_stock' => 1 ) );        // restocked
sold_out_product( 213, array( '_ebay_listing_status' => 'Active' ) );                 // live again
sold_out_product( 214, array( '_ebay_export_status' => 'error' ) );                   // imported, its first push refused: listed, as before
// Settled before 3.27.0: no figures, no listing named. Once taken for a sell-out.
sold_product( 215, array( '_ebay_item_id' => '95215', '_ebay_listing_status' => 'Ended', '_stock_status' => 'outofstock', '_ebay_stock_settled_at' => '2026-01-20 10:00:00' ), false );
sold_out_product( 216, array( '_ebay_quantity_listed' => 0, '_ebay_quantity_sold' => 0 ) );   // a listing of nothing
sold_category( 55, array( 207 ) );
sold_category( 56, array( 201 ) );

$listed = TCGiant_Sync_Inventory::find_sold_out_not_removed();
sort( $listed );
// 101 and 105 are the shop's: sold out on eBay, imported, removable too.
check( 'the list: sold out on eBay by its own figures, and removable', $listed, array( 101, 105, 201, 206, 214 ) );
check( '  a settle from before 3.27.0 is not on it', in_array( 215, $listed, true ), false );
check( '  with a limit', count( TCGiant_Sync_Inventory::find_sold_out_not_removed( 2 ) ), 2 );
check( 'no query was refused', $wpdb->errors, array() );

// What the button then does, product by product: the same products, and
// only those. Every product in the shop is put to it.
$moved = array();
foreach ( range( 201, 216 ) as $id ) {
	if ( TCGiant_Sync_Inventory::sold_out_on_ebay( $id ) && TCGiant_Sync_Inventory::remove_if_allowed( $id ) ) {
		$moved[] = $id;
	}
}
check( 'the cleanup, asked of every product, moves exactly the listed ones', $moved, array( 201, 206, 214 ) );
check( '  to the trash', array( get_post_status( 201 ), get_post_status( 206 ), get_post_status( 214 ), get_post_status( 202 ) ), array( 'trash', 'trash', 'trash', 'publish' ) );
check( 'a settle from before 3.27.0 is not sold out on eBay', TCGiant_Sync_Inventory::sold_out_on_ebay( 215 ), false );
check( '  nor is a product that is not one', array( TCGiant_Sync_Inventory::sold_out_on_ebay( 999 ), TCGiant_Sync_Inventory::sold_out_on_ebay( 0 ) ), array( false, false ) );

echo "\nSTOCK REVIEW: SOLD OUT BEFORE 3.27.0, TO CHECK ONE BY ONE\n" . str_repeat( '=', 140 ) . "\n";

/** Ended, out of stock, settled before 3.27.0: no figures, no listing named. */
function legacy_sold_out( $id, array $more = array(), $status = 'publish' ) {
	sold_product( $id, array_merge( array( '_ebay_item_id' => '95' . $id, '_ebay_listing_status' => 'Ended', '_stock_status' => 'outofstock', '_ebay_stock_settled_at' => '2026-01-20 10:00:00' ), $more ), false, $status );
}
legacy_sold_out( 230 );
legacy_sold_out( 231, array( '_ebay_export_status' => 'pushed' ) );                     // pushed by the shop: kept
legacy_sold_out( 232, array( '_ebay_export_last_pushed' => '2025-01-01 10:00:00' ) );   // pushed once: kept
legacy_sold_out( 233 );                                                                   // in a Preserve category: kept
legacy_sold_out( 234, array( '_stock_status' => 'instock' ) );                            // restocked
legacy_sold_out( 235, array( '_ebay_restock_reviewed' => 'zeroed' ) );                    // set to 0 as a website sale
legacy_sold_out( 236, array(), 'trash' );                                                 // in the trash
sold_category( 55, array( 233 ) );
$legacy = TCGiant_Sync_Inventory::find_sold_out_unverified();
check( 'the list: settled before 3.27.0, out of stock, and the shop\'s to remove', $legacy, array( 230, 215 ) );
check( '  counted the same', TCGiant_Sync_Inventory::count_sold_out_unverified(), 2 );
check( '  newest first, with a limit', TCGiant_Sync_Inventory::find_sold_out_unverified( 1 ), array( 230 ) );
check( '  never on the sold-out button\'s list', array_values( array_intersect( $legacy, TCGiant_Sync_Inventory::find_sold_out_not_removed() ) ), array() );
check( '  nor trashed by it', array( TCGiant_Sync_Inventory::sold_out_on_ebay( 230 ), TCGiant_Sync_Inventory::sold_out_on_ebay( 215 ) ), array( false, false ) );
check( 'no query was refused', $wpdb->errors, array() );

// No Preserve Categories saved: nothing to leave out for them.
$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = array();
sold_out_product( 217 );
sold_category( 57, array( 217 ) );
check( 'no Preserve Categories: a product in any category is listed', in_array( 217, TCGiant_Sync_Inventory::find_sold_out_not_removed(), true ), true );
check( 'no query was refused', $wpdb->errors, array() );

echo "\nSTOCK REVIEW: PUSHES EBAY NEVER TOOK\n" . str_repeat( '=', 140 ) . "\n";

sold_shop();
$GLOBALS['OPTIONS'][ TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION ] = array(
	array( 'time' => 1000, 'product_id' => 301, 'item_id' => '9301', 'sku' => '', 'reason' => 'order', 'qty' => 1, 'order' => 77, 'stock' => 0, 'code' => '21916750', 'message' => 'Item cannot be accessed' ),
	'not a row',
	array( 'time' => 2000, 'product_id' => 302, 'item_id' => '9302', 'sku' => 'A-1', 'reason' => 'edit', 'qty' => 0, 'order' => 0, 'stock' => 4, 'code' => '', 'message' => 'Timeout' ),
	array( 'time' => 3000, 'product_id' => 0, 'item_id' => '9303' ),
);
check( 'newest first, and only real rows', wp_list_pluck( TCGiant_Sync_Inventory::push_failures(), 'product_id' ), array( 302, 301 ) );
check( '  as record_push_failure() wrote them', TCGiant_Sync_Inventory::push_failures()[1]['message'], 'Item cannot be accessed' );
TCGiant_Sync_Inventory::forget_push_failures();
check( 'cleared: nothing listed', TCGiant_Sync_Inventory::push_failures(), array() );
$GLOBALS['OPTIONS'][ TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION ] = 'garbage';
check( 'a list that is not one reads as empty', TCGiant_Sync_Inventory::push_failures(), array() );

echo "\nSTOCK REVIEW: A RELIST BESIDE THE PRODUCT STILL ON THE LISTING IT CAME FROM\n" . str_repeat( '=', 140 ) . "\n";

sold_shop();
// 401 was imported from relist 9401, of 8401, which 402 still follows.
sold_product( 401, array( '_ebay_item_id' => '9401', '_ebay_relist_parent_id' => '8401' ), false );
sold_product( 402, array( '_ebay_item_id' => '8401', '_ebay_listing_status' => 'Ended' ), false );
// 403 was moved on to its relist by 3.28.0: nothing else holds 8403.
sold_product( 403, array( '_ebay_item_id' => '9403', '_ebay_relist_parent_id' => '8403', '_ebay_previous_item_id' => '8403' ), false );
// 405's old product is in the trash.
sold_product( 405, array( '_ebay_item_id' => '9405', '_ebay_relist_parent_id' => '8405' ), false );
sold_product( 406, array( '_ebay_item_id' => '8405' ), false, 'trash' );
// 407 has since been unlinked.
sold_product( 407, array( '_ebay_item_id' => '', '_ebay_relist_parent_id' => '8407' ), false );
sold_product( 408, array( '_ebay_item_id' => '8407' ), false );
// 409's parent is held by a variation, not a product.
sold_product( 409, array( '_ebay_item_id' => '9409', '_ebay_relist_parent_id' => '8409' ), false );
sold_product( 410, array( '_ebay_item_id' => '8409' ), false, 'publish', true, 'product_variation' );
// 411's parent is held by two products.
sold_product( 411, array( '_ebay_item_id' => '9411', '_ebay_relist_parent_id' => '8411' ), false );
sold_product( 412, array( '_ebay_item_id' => '8411' ), false );
sold_product( 413, array( '_ebay_item_id' => '8411' ), false );
// 414, in the trash itself.
sold_product( 414, array( '_ebay_item_id' => '9414', '_ebay_relist_parent_id' => '8414' ), false, 'trash' );
sold_product( 415, array( '_ebay_item_id' => '8414' ), false );

// 417's older product sold its single item: the relist is another copy.
sold_product( 417, array( '_ebay_item_id' => '9417', '_ebay_relist_parent_id' => '8417' ), false );
sold_product( 418, array( '_ebay_item_id' => '8417', '_ebay_listing_status' => 'Ended', '_stock_status' => 'outofstock', '_ebay_stock_settled_item' => '8417', '_ebay_quantity_listed' => 1, '_ebay_quantity_sold' => 1 ), false );
// 419's older product listed five and sold two: listed.
sold_product( 419, array( '_ebay_item_id' => '9419', '_ebay_relist_parent_id' => '8419' ), false );
sold_product( 420, array( '_ebay_item_id' => '8419', '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_item' => '8419', '_ebay_quantity_listed' => 5, '_ebay_quantity_sold' => 2 ), false );
// 421's older product sold a single item - of a listing before that one: listed.
sold_product( 421, array( '_ebay_item_id' => '9421', '_ebay_relist_parent_id' => '8421' ), false );
sold_product( 422, array( '_ebay_item_id' => '8421', '_ebay_stock_settled_item' => '7421', '_ebay_quantity_listed' => 1, '_ebay_quantity_sold' => 1 ), false );

// 431 holds both listings - two _ebay_item_id rows, as a restore can leave.
sold_product( 431, array( '_ebay_item_id' => '9431', '_ebay_relist_parent_id' => '8431' ), false );
$wpdb->db->exec( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( 431, '_ebay_item_id', '8431' )" );

/** The pairs as "relist>older". */
function pair_names( array $pairs ) {
	return array_map( function ( $p ) { return $p['product_id'] . '>' . $p['other_id']; }, $pairs );
}

$wpdb->queries = array();
$found = TCGiant_Sync_Inventory::find_relist_pairs();
$pairs = $found['pairs'];
check( 'the pairs, newest mark first', pair_names( $pairs ), array( '421>422', '419>420', '411>412', '411>413', '401>402' ) );
check( '  never a product with itself', in_array( '431>431', pair_names( $pairs ), true ), false );
check( '  every mark read, nothing further back', array( $found['checked'], $found['next'] ), array( 9, 0 ) );
check( '  with both listings', $pairs[4], array( 'product_id' => 401, 'item_id' => '9401', 'parent_item' => '8401', 'other_id' => 402 ) );
check( '  a single item that sold and was relisted is left out', in_array( '417>418', array_map( function ( $p ) { return $p['product_id'] . '>' . $p['other_id']; }, $pairs ), true ), false );
check( '  two queries: the relist marks, then who holds their listings', count( $wpdb->queries ), 2 );
check( '  neither joins post meta to post meta by value', preg_match( '/meta_value = \w+\.meta_value/', implode( "\n", $wpdb->queries ) ), 0 );
check( '  the first bounded', false !== strpos( $wpdb->queries[0], 'LIMIT ' . TCGiant_Sync_Inventory::RELIST_PAIRS_SCAN ), true );
check( '  the second by its listings, named', false !== strpos( $wpdb->queries[1], "o.meta_value IN ( '" ), true );
$first = TCGiant_Sync_Inventory::find_relist_pairs( 1 );
check( '  at most as many as asked, and where to go on from', array( pair_names( $first['pairs'] ), $first['next'] > 0 ), array( array( '421>422' ), true ) );
$second = TCGiant_Sync_Inventory::find_relist_pairs( 1, $first['next'] );
check( '  going on from there: the next mark', pair_names( $second['pairs'] ), array( '419>420' ) );
$third = TCGiant_Sync_Inventory::find_relist_pairs( 1, $second['next'] );
check( '  a mark\'s pairs are never split between requests', pair_names( $third['pairs'] ), array( '411>412', '411>413' ) );
$rest = TCGiant_Sync_Inventory::find_relist_pairs( 200, $third['next'] );
check( '  and the rest, to the end', array( pair_names( $rest['pairs'] ), $rest['next'] ), array( array( '401>402' ), 0 ) );
check( 'no query was refused', $wpdb->errors, array() );

// Many relists: their listings are asked after a chunk at a time.
sold_shop();
for ( $i = 0; $i < 450; $i++ ) {
	sold_product( 5000 + $i, array( '_ebay_item_id' => '93' . $i, '_ebay_relist_parent_id' => '83' . $i ), false );
}
sold_product( 4999, array( '_ebay_item_id' => '83449' ), false );
$wpdb->queries = array();
$found = TCGiant_Sync_Inventory::find_relist_pairs();
check( '450 relists: their listings asked after in three chunks', count( $wpdb->queries ), 4 );
check( '  and the pair found', pair_names( $found['pairs'] ), array( '5449>4999' ) );
check( 'no query was refused', $wpdb->errors, array() );

// A shop that relists every day: 20,050 marks, its one duplicate from
// before 3.28.0 the oldest. Reading only the newest 2,000 never reached it.
sold_shop();
$wpdb->db->exec( 'BEGIN' );
$wpdb->db->exec( "WITH RECURSIVE n( i ) AS ( SELECT 0 UNION ALL SELECT i + 1 FROM n WHERE i < 20049 ) INSERT INTO wp_posts ( ID, post_type, post_status, post_title ) SELECT 100000 + i, 'product', 'publish', 'Relist ' || i FROM n" );
$wpdb->db->exec( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) SELECT ID, '_ebay_item_id', '93' || ( ID - 100000 ) FROM wp_posts WHERE ID >= 100000 ORDER BY ID" );
$wpdb->db->exec( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) SELECT ID, '_ebay_relist_parent_id', '83' || ( ID - 100000 ) FROM wp_posts WHERE ID >= 100000 ORDER BY ID" );
$wpdb->db->exec( 'COMMIT' );
sold_product( 99000, array( '_ebay_item_id' => '830' ), false );        // still on the oldest mark's listing
sold_product( 99001, array( '_ebay_item_id' => '8320049' ), false );    // and on the newest's
$wpdb->queries = array();
$found = TCGiant_Sync_Inventory::find_relist_pairs();
check( '20,050 relists: the newest 20,000 read in ten rounds of 2,000', array( $found['checked'], count( preg_grep( '/_ebay_relist_parent_id/', $wpdb->queries ) ) ), array( 20000, TCGiant_Sync_Inventory::RELIST_PAIRS_ROUNDS ) );
check( '  each round below the last by meta_id', count( preg_grep( '/rp\.meta_id < \d+/', $wpdb->queries ) ), TCGiant_Sync_Inventory::RELIST_PAIRS_ROUNDS - 1 );
check( '  the newest pair found, and where to go on from', array( pair_names( $found['pairs'] ), $found['next'] > 0 ), array( array( '120049>99001' ), true ) );
$older = TCGiant_Sync_Inventory::find_relist_pairs( 200, $found['next'] );
check( '  going on: the oldest - the duplicate from before 3.28.0 - found, and the end', array( pair_names( $older['pairs'] ), $older['checked'], $older['next'] ), array( array( '100000>99000' ), 50, 0 ) );
check( 'no query was refused', $wpdb->errors, array() );

check( 'a duplicate of the product, or one unlinked, does not carry its relist mark', in_array( '_ebay_relist_parent_id', TCGiant_Sync_Listing_Link::listing_identity_meta_keys(), true ), true );

echo "\nSTOCK REVIEW ITSELF\n" . str_repeat( '=', 140 ) . "\n";

// The page's other panels, which have harnesses of their own, say nothing here.
class TCGiant_Sync_Importer {
	const HELD_KILLED = 'killed'; const HELD_UNREAD = 'unread'; const HELD_SALE_CHECK = 'sale_check'; const HELD_NO_CALLS = 'no_calls';
	public static function held_back_listings() { return array(); }
	public static function is_check_reason( $r ) { return false; }
	public static function find_live_product_for_item( $id ) { return 0; }
}
class TCGiant_Sync_Sites {
	public static $trash_off = false;
	public static function primary_only() { return false; }
	public static function trash_is_off() { return self::$trash_off; }
}
function wp_kses_post( $t ) { return (string) $t; }
function get_delete_post_link( $id ) { return 'https://shop.example/wp-admin/post.php?post=' . (int) $id . '&action=trash'; }

/** Stock Review as WordPress would draw it. */
function stock_review_page( array $get = array() ) {
	$_GET = $get;
	ob_start();
	include $GLOBALS['ROOT'] . '/admin/views/stock-review.php';
	return (string) ob_get_clean();
}

sold_shop();
$GLOBALS['TRANSIENTS']['tcgiant_restocked_after_sale'] = array();
sold_out_product( 801 );
sold_out_product( 802, array( '_ebay_export_status' => 'pushed' ) );
sold_product( 803, array( '_ebay_item_id' => '95803', '_ebay_listing_status' => 'Ended', '_stock_status' => 'outofstock', '_ebay_stock_settled_at' => '2026-01-20 10:00:00' ), false );
sold_product( 804, array( '_ebay_item_id' => '9804', '_ebay_relist_parent_id' => '8804', '_manage_stock' => 'yes', '_stock' => 1 ), false );
sold_product( 805, array( '_ebay_item_id' => '8804', '_ebay_listing_status' => 'Ended', '_stock_status' => 'outofstock' ), false );
$GLOBALS['OPTIONS'][ TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION ] = array(
	array( 'time' => 1761700000, 'product_id' => 801, 'item_id' => '95801', 'sku' => 'COIN-801', 'reason' => 'order', 'qty' => 1, 'order' => 4242, 'stock' => 0, 'code' => '21916750', 'message' => 'Item <b>cannot</b> be accessed' ),
	array( 'time' => 1761800000, 'product_id' => 999, 'item_id' => '95999', 'sku' => '', 'reason' => 'edit', 'qty' => 0, 'order' => 0, 'stock' => 4, 'code' => '', 'message' => 'Timeout' ),
);
$wpdb->queries = array();
$review = stock_review_page();
check( 'the relist pairs are not looked for unless asked', preg_match( '/_ebay_relist_parent_id/', implode( "\n", $wpdb->queries ) ), 0 );
check( '  a button asks', false !== strpos( $review, 'relist_pairs=1#tc-relist-pairs' ) && false === strpos( $review, 'id="tc-relist-pairs"' ), true );
$sold_ids = array_values( array_diff( TCGiant_Sync_Inventory::find_sold_out_not_removed(), array( 101, 105 ) ) );
check( 'the sold-out panel counts what its button would move', 1 === preg_match( '/(\d+) products sold out on eBay and are still here\./', $review, $m ) ? (int) $m[1] : null, count( TCGiant_Sync_Inventory::find_sold_out_not_removed() ) );
check( '  801 is among them, 802 (pushed) is not', $sold_ids, array( 801 ) );
check( '  it says why those are listed, and that pushed and preserved ones are kept', false !== strpos( $review, 'Products you pushed to eBay yourself, and products in your Preserve Categories, are not listed' ), true );
$legacy_at    = (int) strpos( $review, 'id="tc-sold-out-unverified"' );
$legacy_panel = $legacy_at ? substr( $review, $legacy_at, (int) strpos( $review, '</table>', $legacy_at ) - $legacy_at ) : '';
check( 'the one from before 3.27.0 has a list of its own', false !== strpos( $legacy_panel, '1 product ended out of stock before version 3.27.0.' ), true );
check( '  with its Edit link and WordPress\'s own Trash link', array( false !== strpos( $legacy_panel, 'post.php?post=803"' ), false !== strpos( $legacy_panel, 'post.php?post=803&amp;action=trash' ) || false !== strpos( $legacy_panel, 'post.php?post=803&action=trash' ) ), array( true, true ) );
check( '  and no button: nothing is trashed in bulk', false === strpos( $legacy_panel, '<button' ) && false === strpos( $legacy_panel, '<form' ), true );
check( '  its eBay listing linked, to check it has ended', false !== strpos( $legacy_panel, 'href="https://www.ebay.com/itm/95803"' ), true );
check( '  and told to check that first: an old version could record an end eBay refused', false !== strpos( $legacy_panel, 'First check on eBay that its listing has really ended.' ), true );
check( 'the pushes eBay never took', false !== strpos( $review, '2 stock changes never reached eBay.' ), true );
check( '  newest first', strpos( $review, '95999' ) < strpos( $review, '95801' ), true );
check( '  with what changed, the order, the SKU and eBay\'s answer, escaped', array( false !== strpos( $review, 'A website order' ), false !== strpos( $review, '#4242' ), false !== strpos( $review, 'SKU COIN-801' ), false !== strpos( $review, 'Item &lt;b&gt;cannot&lt;/b&gt; be accessed' ), false !== strpos( $review, '(21916750)' ) ), array( true, true, true, true, true ) );
check( '  a product gone since is still listed, by its number', false !== strpos( $review, '#999' ), true );
check( '  and the list can be cleared, by a nonce\'d form of its own', false !== strpos( $review, 'name="action" value="tcgiant_forget_push_failures"' ), true );
$review = stock_review_page( array( 'relist_pairs' => '1' ) );
check( 'asked: the relist beside the product on its older listing', false !== strpos( $review, '1 product may be the same item as another.' ), true );
check( '  naming both, and both listings', array( false !== strpos( $review, 'Coin 804' ), false !== strpos( $review, 'Coin 805' ), false !== strpos( $review, '9804' ), false !== strpos( $review, '8804' ) ), array( true, true, true, true ) );
$pairs_at    = (int) strpos( $review, 'id="tc-relist-pairs"' );
$pairs_panel = substr( $review, $pairs_at, (int) strpos( $review, '</table>', $pairs_at ) - $pairs_at );
check( '  with no button: nothing changes from it', '' !== $pairs_panel && false === strpos( $pairs_panel, '<button' ) && false === strpos( $pairs_panel, '<form' ), true );
check( 'no query was refused', $wpdb->errors, array() );

// Asked, with none to find.
delete_post_meta( 804, '_ebay_relist_parent_id' );
$review = stock_review_page( array( 'relist_pairs' => '1' ) );
check( 'asked, none found: it says so', false !== strpos( $review, 'No products were found that may be the same item twice.' ), true );

// More pairs than a page holds: the page says it stopped, and goes on.
for ( $i = 0; $i < 201; $i++ ) {
	sold_product( 6000 + $i, array( '_ebay_item_id' => '96' . $i, '_ebay_relist_parent_id' => '86' . $i ), false );
	sold_product( 7000 + $i, array( '_ebay_item_id' => '86' . $i ), false );
}
$review = stock_review_page( array( 'relist_pairs' => '1' ) );
check( 'over 200 pairs: 200 shown, and it says there are older relists to look through', array( false !== strpos( $review, '200 products may be the same item as another.' ), false !== strpos( $review, 'there are older ones still to look through' ) ), array( true, true ) );
check( '  with a link that goes on from there', 1 === preg_match( '/relist_pairs=1&(?:amp;)?relist_before=(\d+)#tc-relist-pairs/', $review, $more ), true );
$review = stock_review_page( array( 'relist_pairs' => '1', 'relist_before' => $more[1] ) );
check( '  which shows the last one, and no further link', array( false !== strpos( $review, '1 product may be the same item as another.' ), false !== strpos( $review, 'relist_before=' ) ), array( true, false ) );
$review = stock_review_page( array( 'relist_pairs' => '1', 'relist_before' => '1' ) );
check( '  past the oldest: every relist has been looked through', false !== strpos( $review, 'every relist the sync has recorded has now been looked through' ), true );

// The trash switched off: no Trash link, said why.
TCGiant_Sync_Sites::$trash_off = true;
$review       = stock_review_page();
$legacy_at    = (int) strpos( $review, 'id="tc-sold-out-unverified"' );
$legacy_panel = substr( $review, $legacy_at, (int) strpos( $review, '</table>', $legacy_at ) - $legacy_at );
check( 'the trash switched off: no Trash link, and it says why', array( false === strpos( $legacy_panel, 'action=trash' ), false !== strpos( $legacy_panel, 'EMPTY_TRASH_DAYS is 0' ) ), array( true, true ) );
TCGiant_Sync_Sites::$trash_off = false;

// "Remove products when their eBay listing sells out?" off: neither list.
$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = array( 'remove_sold_products' => '0' );
$review = stock_review_page();
check( '"Remove sold products" off: no sold-out panel, nor the list from before 3.27.0', array( false !== strpos( $review, 'sold out on eBay and are still here' ), false !== strpos( $review, 'id="tc-sold-out-unverified"' ) ), array( false, false ) );
check( '  the rest of the page as before', false !== strpos( $review, 'stock changes never reached eBay.' ), true );
$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = array( 'remove_sold_products' => '1' );
$review = stock_review_page();
check( '  on again: both back', array( false !== strpos( $review, 'sold out on eBay and is still here' ) || false !== strpos( $review, 'sold out on eBay and are still here' ), false !== strpos( $review, 'id="tc-sold-out-unverified"' ) ), array( true, true ) );

// None among the newest 20,000, the shop's one duplicate older still: the
// page says how far it looked, not that there are none.
sold_shop();
$GLOBALS['TRANSIENTS']['tcgiant_restocked_after_sale'] = array();
$wpdb->db->exec( 'BEGIN' );
$wpdb->db->exec( "WITH RECURSIVE n( i ) AS ( SELECT 0 UNION ALL SELECT i + 1 FROM n WHERE i < 20049 ) INSERT INTO wp_posts ( ID, post_type, post_status, post_title ) SELECT 100000 + i, 'product', 'publish', 'Relist ' || i FROM n" );
$wpdb->db->exec( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) SELECT ID, '_ebay_item_id', '93' || ( ID - 100000 ) FROM wp_posts WHERE ID >= 100000 ORDER BY ID" );
$wpdb->db->exec( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) SELECT ID, '_ebay_relist_parent_id', '83' || ( ID - 100000 ) FROM wp_posts WHERE ID >= 100000 ORDER BY ID" );
$wpdb->db->exec( 'COMMIT' );
sold_product( 99000, array( '_ebay_item_id' => '830' ), false );
$review = stock_review_page( array( 'relist_pairs' => '1' ) );
check( 'none in the newest 20,000: it says how far it looked, not that there are none', array( false !== strpos( $review, 'None among the 20,000 relists looked through so far' ), false !== strpos( $review, 'No products were found' ) ), array( true, false ) );
check( '  and offers to look further back', 1 === preg_match( '/relist_pairs=1&(?:amp;)?relist_before=(\d+)#tc-relist-pairs/', $review, $more ), true );
$review = stock_review_page( array( 'relist_pairs' => '1', 'relist_before' => $more[1] ) );
check( '  where the duplicate from before 3.28.0 is', array( false !== strpos( $review, '1 product may be the same item as another.' ), false !== strpos( $review, 'Relist 0' ) ), array( true, true ) );
check( 'no query was refused', $wpdb->errors, array() );

// The Clear button's handler, which redirects and exits: read, not run.
// What it calls is forget_push_failures(), run above.
$src = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
check( 'Clear checks its nonce and the capability, then forgets the list', 1 === preg_match( "/function handle_forget_push_failures\(\) \{\s*check_admin_referer\( 'tcgiant_forget_push_failures' \);\s*if \( ! current_user_can\( 'manage_options' \) \) \{/", $src ), true );
check( '  registered for its form', false !== strpos( $src, "add_action( 'admin_post_tcgiant_forget_push_failures', array( \$this, 'handle_forget_push_failures' ) );" ), true );

echo "\nNOTHING HERE ASKS EBAY\n" . str_repeat( '=', 140 ) . "\n";

// Nothing here can reach eBay: the harness has no HTTP at all, so any
// request would have stopped it with a fatal error.
check( 'no HTTP in this harness, and it ran to the end', function_exists( 'wp_remote_post' ) || function_exists( 'wp_remote_request' ), false );
$inv_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-inventory.php' );
foreach ( array( 'end_note', 'record_shop_end', 'sold_out_on_ebay', 'push_failures', 'find_relist_pairs', 'find_sold_out_unverified', 'count_sold_out_unverified' ) as $fn ) {
	preg_match( '/public static function ' . $fn . '\(.*?\n\t\}\n/s', $inv_src, $body );
	check( "  {$fn}() makes none", 1 === count( $body ) && 0 === preg_match( '/TCGiant_Sync_API::instance\(\)/', $body[0] ), true );
}

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
