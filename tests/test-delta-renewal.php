<?php
/**
 * Harness: a monthly Good 'Til Cancelled renewal of a listing nothing else has
 * changed on costs no GetItem and no product save (3.28.6).
 *
 * eBay counts each monthly renewal of a GTC listing as a change, so every one
 * arrived in the delta's window - about 450 a day for a shop of 15,000 parts,
 * and 1,795 in one window on one shop - and each cost a GetItem and a full
 * save of a product nothing had happened to. The full save now records what
 * eBay showed (TCGiant_Sync_Mapper::EVENT_RECORD_META), and a renewal whose
 * fields are all as recorded, with its end a month on, only has its new end
 * date written. Everything else takes the full path, as before.
 *
 * Loaded for real: TCGiant_Sync_Importer, TCGiant_Sync_Mapper (its full save
 * writes the record), TCGiant_Sync_Inventory (item 14's stock records),
 * TCGiant_Sync_Runtime, TCGiant_Sync_API, TCGiant_Sync_Sites,
 * TCGiant_Sync_Category_Memory, TCGiant_Sync_Listing_Link and TCGiant_Sync_Cron
 * - see tests/support/renewal-world.php. One case runs a request in a PHP
 * process of its own and kills it (tests/support/renewal-kill.php).
 *
 * Run against 3.28.4, the renewal cases fail and the full-path cases pass.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-92s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

require __DIR__ . '/support/renewal-world.php';

const RECORD = '_ebay_event_sig';
const AFTER  = 'tcgiant_sync_light_after';

function gets() { return count( $GLOBALS['EBAY']['get_items'] ); }
function saves() { return array_sum( $GLOBALS['SAVES'] ); }
function meta( $pid, $key ) { return $GLOBALS['META'][ (int) $pid ][ $key ] ?? null; }
function record( $pid ) { return meta( $pid, RECORD ); }
function state() { return get_option( 'tcgiant_sync_state', array() ); }
function logged( $text ) {
	foreach ( $GLOBALS['LOG'] as $line ) {
		if ( false !== strpos( $line[1], $text ) ) {
			return true;
		}
	}
	return false;
}
function summary() {
	$out = '';
	foreach ( $GLOBALS['LOG'] as $line ) {
		if ( 0 === strpos( $line[1], 'Delta sync complete!' ) || 0 === strpos( $line[1], 'Delta batch done:' ) ) {
			$out = $line[1];
		}
	}
	return $out;
}
/** The keys written to one product's meta since the shop was set up. */
function written( $pid ) {
	$keys = array();
	foreach ( $GLOBALS['WRITES'] as $w ) {
		if ( (int) $w[0] === (int) $pid ) {
			$keys[] = $w[1];
		}
	}
	return array_values( array_unique( $keys ) );
}
/** One listing, one change, one delta: whether it was read in full. */
function full_path( array $change, $before = null, array $settings = array(), array $then = array() ) {
	$pids = shop( array( '1' ), array( '1' => $change ), $settings, array( '1' => $then ) );
	if ( $before ) {
		$before( $pids['1'] );
	}
	delta();
	return array( gets(), saves() );
}

echo "\nAN UNCHANGED MONTHLY RENEWAL\n" . str_repeat( '=', 130 ) . "\n";

$pids = shop( array( '174260628275' ) );
$pid  = $pids['174260628275'];
$was  = record( $pid );
check( 'the full import wrote a record of what eBay showed', is_array( $was ) && 32 === strlen( (string) ( $was['sig'] ?? '' ) ), true );
check( '  of this listing, its end, and when it was read', array( $was['item'] ?? null, $was['end'] ?? null, ( $was['read'] ?? 0 ) > time() - 60 ), array( '174260628275', $E1, true ) );
$ledger  = meta( $pid, '_ebay_sold_seen' );
$synced  = meta( $pid, '_sync_last_updated' );
$synclog = meta( $pid, '_tcgiant_sync_log' );
$runs    = delta();
check( 'no GetItem', $EBAY['get_items'], array() );
check( 'no product save', saves(), 0 );
check( 'the end date moves to the renewal', meta( $pid, '_ebay_end_time' ), $E2 );
check( '  and the Listings row follows it', $ROWS[ $pid ]['ebay_end_time'] ?? null, $E2 );
check( '  which is marked synced', isset( $ROWS[ $pid ]['last_synced'] ), true );
check( 'the record moves on with it, its read time kept', array( record( $pid )['end'] ?? null, record( $pid )['light'] ?? null, record( $pid )['read'] ?? null ), array( $E2, 1, $was['read'] ) );
check( 'nothing else is written: the end date and the record only', written( $pid ), array( '_ebay_end_time', RECORD ) );
check( '  the stock untouched', meta( $pid, '_stock' ), 1 );
check( '  eBay\'s sales record (3.28.0) untouched', meta( $pid, '_ebay_sold_seen' ), $ledger );
check( '  nor the time of the last full read, nor the sync log', array( meta( $pid, '_sync_last_updated' ), meta( $pid, '_tcgiant_sync_log' ) ), array( $synced, $synclog ) );
check( '  nor the photos', $PHOTO_SETS, array() );
check( 'the window finishes, in one request', array( state()['status'] ?? '', $runs ), array( 'complete', 1 ) );
check( 'the summary counts it', summary(), 'Delta sync complete! 0 imported, 0 errors out of 1 modified items, 1 renewals (end date moved, nothing else changed).' );
check( '  and a debug line names it', logged( 'Delta: eBay item 174260628275 renewed to ' . $E2 . ' with nothing else changed' ), true );

$pids = shop( array( '2' ), array( '2' => array( 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '2' ) ) ), array(), array( '2' => array( 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '2' ) ) ) );
delta();
check( 'a listing of several, nothing sold since: light too', array( gets(), saves(), meta( $pids['2'], '_ebay_end_time' ), meta( $pids['2'], '_stock' ) ), array( 0, 0, $E2, 3 ) );

echo "\nEVERYTHING ELSE TAKES THE FULL PATH, AS IN 3.28.4\n" . str_repeat( '=', 130 ) . "\n";

foreach ( array(
	'a price change'                           => array( 'SellingStatus' => array( 'CurrentPrice' => '22.50' ) ),
	'a title change'                           => array( 'Title' => 'Brake caliper - rear' ),
	'a relist (a new StartTime)'               => array( 'ListingDetails' => array( 'StartTime' => '2026-10-29T09:00:00.000Z' ) ),
	'a quantity raised in Seller Hub (1 to 3)' => array( 'Quantity' => '3' ),
	'a revision with no renewal (end as recorded; also eBay\'s maintenance touch)' => array( 'ListingDetails' => array( 'EndTime' => $E1 ) ),
	'an end date moved on only a day'          => array( 'ListingDetails' => array( 'EndTime' => iso( strtotime( $E1 ) + DAY_IN_SECONDS ) ) ),
	'an end date moved on 19 days'             => array( 'ListingDetails' => array( 'EndTime' => iso( strtotime( $E1 ) + 19 * DAY_IN_SECONDS ) ) ),
) as $label => $change ) {
	check( $label . ': GetItem and a save', full_path( $change ), array( 1, 1 ) );
}
check( 'an end date moved on exactly 20 days: light', full_path( array( 'ListingDetails' => array( 'EndTime' => iso( strtotime( $E1 ) + 20 * DAY_IN_SECONDS ) ) ) ), array( 0, 0 ) );

$pids = shop( array( '3' ), array( '3' => array( 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '3' ) ) ), array(), array( '3' => array( 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '2' ) ) ) );
delta();
check( 'a listing of several with a sale since the last read: full path, stock 2', array( gets(), saves(), meta( $pids['3'], '_stock' ) ), array( 1, 1, 2 ) );

$pids = shop( array( '4' ) );
$EBAY['event_over']['4'] = array( 'Title' => '**********' );
delta();
check( 'a title eBay masked with asterisks (a listing on hold): full path', array( gets(), saves() ), array( 1, 1 ) );

$pids = shop( array( '5' ), array( '5' => array( 'SellingStatus' => array( 'QuantitySold' => '1', 'ListingStatus' => 'Completed' ) ) ) );
delta();
check( 'a coin that sold (Completed, no Quantity) takes the ended branch: stock 0, Ended', array( meta( $pids['5'], '_stock' ), meta( $pids['5'], '_ebay_listing_status' ) ), array( 0, 'Ended' ) );

$pids = shop( array( '6' ) );
$EBAY['event_over']['6'] = array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0', 'CurrentPrice' => '24.99' ), 'ListingDetails' => array( 'StartTime' => '2026-03-30T16:50:00.000Z', 'EndTime' => iso( strtotime( $E1 ) + 25 * DAY_IN_SECONDS ) ) );
delta();
check( 'ended unsold, its fields otherwise as recorded and a month on: ended, never light', array( meta( $pids['6'], '_ebay_listing_status' ), logged( 'renewals (end date moved' ) ), array( 'Ended', false ) );

check( 'an auction (Chinese): full path', full_path( array( 'ListingType' => 'Chinese' ), null, array( 'import_auctions' => '1' ), array( 'ListingType' => 'Chinese' ) ), array( 1, 1 ) );

$pids = shop( array( '7' ), array( '7' => array( 'SellingStatus' => array( 'QuantitySold' => '1' ) ) ), array(), array( '7' => array( 'SellingStatus' => array( 'QuantitySold' => '1' ) ) ) );
delta();
check( 'nothing left to sell, still Active (Out-of-Stock control): full path', array( gets(), saves(), meta( $pids['7'], '_stock' ) ), array( 1, 1, 0 ) );

// A variation listing: its record is '' and its row has Variations.
$pids = shop( array( '8' ) );
$POSTS[ $pids['8'] ]['product_type'] = 'variable';
$META[ $pids['8'] ][ RECORD ]['sig'] = '';
$EBAY['event_over']['8']              = array( 'Variations' => array( 'Variation' => array( array( 'SKU' => 'A' ) ) ) );
$EBAY['answers']['8']                 = new WP_Error( 'api_error', 'not scripted' );
delta();
check( 'a variation listing: full path (GetItem asked)', array( gets(), logged( 'renewals (end date moved' ) ), array( 1, false ) );

echo "\nTHE PRODUCT HERE MUST HOLD WHAT A FULL READ WOULD WRITE\n" . str_repeat( '=', 130 ) . "\n";

check( 'WooCommerce\'s stock is not eBay\'s (a website sale): full path', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ]['_stock'] = 0; } ), array( 1, 1 ) );
check( 'a stock change on its way to eBay (item 14): full path', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ]['_ebay_push_pending'] = array( 'abc' => array( 'until' => time() + 3600, 'queued_at' => time() ) ); } ), array( 1, 1 ) );
check( '  one long expired: light', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ]['_ebay_push_pending'] = array( 'abc' => time() - 60 ); } ), array( 0, 0 ) );
check( 'no record of eBay\'s sales (3.28.0) for this listing: full path', full_path( array(), function ( $pid ) { unset( $GLOBALS['META'][ $pid ]['_ebay_sold_seen'] ); } ), array( 1, 1 ) );
check( '  one of fewer sales than eBay\'s: full path', full_path( array( 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '1' ) ), function ( $pid ) { $GLOBALS['META'][ $pid ]['_ebay_sold_seen']['sold'] = 0; }, array(), array( 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '1' ) ) ), array( 1, 1 ) );
check( 'stored as Ended: full path', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ]['_ebay_listing_status'] = 'Ended'; } ), array( 1, 1 ) );
check( 'this listing\'s ending already settled: full path', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ]['_ebay_stock_settled_at'] = time(); $GLOBALS['META'][ $pid ]['_ebay_stock_settled_item'] = '1'; } ), array( 1, 1 ) );
check( 'in the Listings screen\'s Sold view, the product saying Active: full path', full_path( array(), function ( $pid ) { $GLOBALS['ROWS'][ $pid ]['listing_status'] = 'Ended'; } ), array( 1, 1 ) );
check( 'no Listings row: full path, which makes one', full_path( array(), function ( $pid ) { unset( $GLOBALS['ROWS'][ $pid ] ); } ), array( 1, 1 ) );
check( 'a Listings row for another listing: full path', full_path( array(), function ( $pid ) { $GLOBALS['ROWS'][ $pid ]['ebay_item_id'] = '99'; } ), array( 1, 1 ) );
check( 'photos not downloaded yet: full path, where they heal', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ]['_tcgiant_images_localized'] = 0; } ), array( 1, 1 ) );
check( 'a placeholder for a featured image: full path', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ]['_harness_placeholder'] = 1; } ), array( 1, 1 ) );
check( 'stock not managed: full path', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ]['_manage_stock'] = 'no'; } ), array( 1, 1 ) );
$pids = shop( array( '39' ), array(), array( 'disable_image_localization' => '1' ) );
$META[ $pids['39'] ]['_tcgiant_images_localized'] = 0;
$META[ $pids['39'] ]['_harness_placeholder']      = 1;
delta();
check( 'a shop that keeps eBay-hosted photos: light without downloaded ones (the owner\'s ruling)', array( gets(), saves(), meta( $pids['39'], '_ebay_end_time' ) ), array( 0, 0, $E2 ) );
$pids = shop( array( '9' ) );
$POSTS[ $pids['9'] ]['product_type'] = 'variable';
$EBAY['answers']['9']                 = new WP_Error( 'api_error', 'not scripted' );
delta();
check( 'a variable product on a listing of one: full path (GetItem asked)', array( gets(), meta( $pids['9'], '_ebay_end_time' ) ), array( 1, $E1 ) );

echo "\nTHE RECORD MUST BE THIS LISTING'S, RECENT, AND UNDER TODAY'S SETTINGS\n" . str_repeat( '=', 130 ) . "\n";

check( 'no record (imported before 3.28.6): full path, which writes one', full_path( array(), function ( $pid ) { unset( $GLOBALS['META'][ $pid ][ RECORD ] ); } ), array( 1, 1 ) );
check( '  a record of an unknown shape: full path', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ][ RECORD ]['v'] = 2; } ), array( 1, 1 ) );
check( '  of another listing (one the product followed before): full path', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ][ RECORD ]['item'] = '99'; } ), array( 1, 1 ) );
check( '  with no end time: full path', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ][ RECORD ]['end'] = ''; } ), array( 1, 1 ) );
check( '  read 91 days ago: full path', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ][ RECORD ]['read'] = time() - 91 * DAY_IN_SECONDS; } ), array( 1, 1 ) );
check( '  read 89 days ago: light', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ][ RECORD ]['read'] = time() - 89 * DAY_IN_SECONDS; } ), array( 0, 0 ) );
check( '  read before the delta last stepped over changes: full path', full_path( array(), function ( $pid ) { update_option( AFTER, time() + 1 ); } ), array( 1, 1 ) );

$pids = shop( array( '10' ) );
$OPTIONS['tcgiant_sync_ebay_settings']['overwrite_desc'] = '1';
delta();
check( 'an import setting changed (Overwrite Description): full path once', array( gets(), saves() ), array( 1, 1 ) );
$EBAY['get_items'] = array();
$SAVES             = array();
$EBAY['items']['10']['ListingDetails']['EndTime'] = $E3;
delta();
check( '  and the renewal after that is light again', array( gets(), saves(), meta( $pids['10'], '_ebay_end_time' ) ), array( 0, 0, $E3 ) );
foreach ( array( 'bake_shipping_into_price', 'overwrite_price', 'overwrite_title', 'overwrite_weight_dims', 'overwrite_images', 'sku_maps_to', 'import_image_width', 'disable_image_localization', 'import_auctions', 'import_sites' ) as $key ) {
	check( '  likewise ' . $key, full_path( array(), function ( $pid ) use ( $key ) { $GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'][ $key ] = 'changed'; } ), array( 1, 1 ) );
}
check( '  likewise WooCommerce\'s weight unit, which eBay\'s package weights are converted into', full_path( array(), function ( $pid ) { update_option( 'woocommerce_weight_unit', 'kg' ); } ), array( 1, 1 ) );
check( '  but not a setting the import does not read (the log level)', full_path( array(), function ( $pid ) { $GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings']['log_level'] = 'warning'; } ), array( 0, 0 ) );

echo "\nTHE WINDOW MUST HAVE BEEN READ WITHIN 48 HOURS OF ITS START\n" . str_repeat( '=', 130 ) . "\n";

shop( array( '11' ) );
delta( 72 );
check( 'a window read three days behind: full path', array( gets(), saves() ), array( 1, 1 ) );
shop( array( '12' ) );
delta( 47 );
check( '  47 hours behind: light', array( gets(), saves() ), array( 0, 0 ) );

// A window 3.28.4 read and kept, carried on after the update: its progress
// has no read time and its rows no signature.
$ids = array();
for ( $i = 0; $i < 41; $i++ ) {
	$ids[] = (string) ( 200000000000 + $i );
}
$over = array();
foreach ( $ids as $id ) {
	$over[ $id ] = array( 'SellingStatus' => array( 'CurrentPrice' => '1.00' ) );
}
$ids[] = '200000000099';
$pids  = shop( $ids, $over );
start_window();
delta_request();
check( 'a window too long for one run hands on after forty that need eBay', array( gets(), as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) ), array( 40, true ) );
$progress = get_option( 'tcgiant_sync_delta_progress', array() );
$cache    = get_option( 'tcgiant_sync_delta_window', array() );
check( '  its place keeps when eBay was read, its rows the signature', array( ( $progress['read_at'] ?? 0 ) > 0, 32 === strlen( (string) ( $cache['rows'][0]['_sig'] ?? '' ) ), $cache['rows'][0]['ListingType'] ?? null ), array( true, true, 'FixedPriceItem' ) );
unset( $progress['read_at'] );
foreach ( $cache['rows'] as $i => $row ) {
	unset( $cache['rows'][ $i ]['_sig'], $cache['rows'][ $i ]['ListingType'] );
}
update_option( 'tcgiant_sync_delta_progress', $progress );
update_option( 'tcgiant_sync_delta_window', $cache );
$EBAY['get_items'] = array();
do {
	delta_request();
} while ( as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) );
check( 'carried on from a window kept by 3.28.4: the renewal takes the full path', array( $EBAY['get_items'], $EBAY['event_reads'] ), array( array( '200000000040', '200000000099' ), 1 ) );

// The same window, its rows signed but its place without the time eBay was
// read: not known to be within 48 hours, so read in full.
$pids = shop( $ids, $over );
start_window();
delta_request();
$progress = get_option( 'tcgiant_sync_delta_progress', array() );
unset( $progress['read_at'] );
update_option( 'tcgiant_sync_delta_progress', $progress );
$EBAY['get_items'] = array();
do {
	delta_request();
} while ( as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) );
check( '  and one whose place has lost the time it was read: full path too', $EBAY['get_items'], array( '200000000040', '200000000099' ) );

echo "\nTHE CATEGORY FILTER, AND WHAT IT REMEMBERS (3.28.4)\n" . str_repeat( '=', 130 ) . "\n";

$filter = array( 'import_standard_category_ids' => array( '33563' ) );
$pids   = shop( array( '13', '14' ), array(), $filter, array( '14' => array( 'PrimaryCategory' => array( 'CategoryID' => '6030' ) ) ) );
$memory = array();
foreach ( array( '13', '14' ) as $id ) {
	$memory[ $id ] = TCGiant_Sync_Category_Memory::recall( array( $id ) );
}
delta();
check( 'remembered as in the filter: light, with no GetItem for its category', array( gets(), saves(), meta( $pids['13'], '_ebay_end_time' ) ), array( 0, 0, $E2 ) );
check( 'remembered as outside it: skipped as before, never reaching the renewal check, its record forgotten', array( meta( $pids['14'], '_ebay_end_time' ), record( $pids['14'] ) ), array( $E1, null ) );
check( '  the summary says both', summary(), 'Delta sync complete! 0 imported, 0 errors out of 2 modified items, 1 renewals (end date moved, nothing else changed), 1 skipped (category filter, remembered).' );
check( '  and the memory learns nothing, and forgets nothing', array( TCGiant_Sync_Category_Memory::recall( array( '13' ) ), TCGiant_Sync_Category_Memory::recall( array( '14' ) ) ), array( $memory['13'], $memory['14'] ) );

$pids = shop( array( '15' ), array(), $filter );
foreach ( array_keys( $OPTIONS ) as $name ) {
	if ( 0 === strpos( $name, 'tcgiant_sync_category_memory_' ) ) {
		unset( $OPTIONS[ $name ] );
	}
}
delta();
check( 'not remembered: light before the GetItem that would only have said its category', array( gets(), saves(), meta( $pids['15'], '_ebay_end_time' ) ), array( 0, 0, $E2 ) );
$pids = shop( array( '16' ), array( '16' => array( 'SellingStatus' => array( 'CurrentPrice' => '9.99' ) ) ), $filter );
foreach ( array_keys( $OPTIONS ) as $name ) {
	if ( 0 === strpos( $name, 'tcgiant_sync_category_memory_' ) ) {
		unset( $OPTIONS[ $name ] );
	}
}
delta();
check( '  not remembered and changed: one GetItem, which the filter and the import share', array( gets(), saves() ), array( 1, 1 ) );

echo "\nA LISTING OF ONE: LIGHT ONLY WHILE EBAY SAYS OUT-OF-STOCK CONTROL IS OFF (THE OWNER'S RULING)\n" . str_repeat( '=', 130 ) . "\n";

/** TCGiant_Sync_Importer::record_renewal() on one row, in a run that may; 0 where there is none (3.28.4). */
function renewal_of( array $row ) {
	if ( ! method_exists( 'TCGiant_Sync_Importer', 'record_renewal' ) ) {
		return 0;
	}
	return call_private( 'TCGiant_Sync_Importer', 'record_renewal', $row, array( 'on' => true, 'fp' => TCGiant_Sync_Mapper::settings_fingerprint(), 'after' => 0, 'photos' => true ) );
}
/** What the shop last learnt of the preference. */
function oos_kept() { return get_option( 'tcgiant_sync_oos_control', null ); }
/** Two listings of one renewing, and a third of five. */
function oos_shop() {
	return shop( array( '40', '41', '42' ), array( '42' => array( 'Quantity' => '5' ) ), array(), array( '42' => array( 'Quantity' => '5' ) ) );
}
/** The three renew again, a month on. */
function oos_renew_again() {
	foreach ( array( '40', '41', '42' ) as $id ) {
		$GLOBALS['EBAY']['items'][ $id ]['ListingDetails']['EndTime'] = $GLOBALS['E3'];
	}
	$GLOBALS['EBAY']['get_items'] = array();
}

$pids = oos_shop();
delta();
check( 'off: the renewals of listings of one are light, and eBay is asked once', array( gets(), saves(), count( $EBAY['prefs'] ), meta( $pids['40'], '_ebay_end_time' ), meta( $pids['41'], '_ebay_end_time' ) ), array( 0, 0, 1, $E2, $E2 ) );
check( '  with GetUserPreferences, asking for the Out-of-Stock control preference', array( $EBAY['wire'], $EBAY['prefs'][0] ?? '' ), array( array( 'GetUserPreferences' ), '<ShowOutOfStockControlPreference>true</ShowOutOfStockControlPreference>' ) );
check( '  a read, so the staging guard lets it through (and it goes by trading_api_request(): counted, held at the ceiling)', TCGiant_Sync_API::is_read_call( 'GetUserPreferences' ), true );
check( '  kept with the time it was asked, never autoloaded', array( oos_kept()['pref'] ?? null, oos_kept()['ok'] ?? null, abs( ( oos_kept()['at'] ?? 0 ) - time() ) < 60, $AUTOLOAD['tcgiant_sync_oos_control'] ?? null ), array( 'false', true, true, false ) );
oos_renew_again();
delta();
check( '  the next renewals, within the day: light, and eBay not asked again', array( gets(), count( $EBAY['prefs'] ), meta( $pids['41'], '_ebay_end_time' ) ), array( 0, 1, $E3 ) );

$pids = oos_shop();
$EBAY['oos'] = 'true';
delta();
check( 'on: the listings of one are read in full, the one of several still light, eBay asked once', array( $EBAY['get_items'], meta( $pids['42'], '_ebay_end_time' ), count( $EBAY['prefs'] ) ), array( array( '40', '41' ), $E2, 1 ) );
check( '  and the log says why', logged( 'eBay says your Out-of-Stock control is on' ), true );
$pids = oos_shop();
update_option( 'tcgiant_sync_oos_control', array( 'pref' => 'true', 'ok' => true, 'at' => time() - 3600 ) );
delta();
check( '  on, as eBay said an hour ago: read in full, and not asked again within the day', array( $EBAY['get_items'], count( $EBAY['prefs'] ) ), array( array( '40', '41' ), 0 ) );

$pids = oos_shop();
$EBAY['oos'] = '';
delta();
check( 'unknown - eBay\'s answer does not say: read in full', array( $EBAY['get_items'], count( $EBAY['prefs'] ), logged( 'eBay did not say whether your Out-of-Stock control is on' ) ), array( array( '40', '41' ), 1, true ) );

$pids = oos_shop();
$EBAY['oos'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
delta();
check( 'the call fails: read in full, and said', array( $EBAY['get_items'], count( $EBAY['prefs'] ), oos_kept()['ok'] ?? null, logged( 'eBay could not say whether your Out-of-Stock control is on' ) ), array( array( '40', '41' ), 1, false, true ) );
$EBAY['oos'] = 'false';
oos_renew_again();
delta();
check( '  and not asked again that day, though eBay would answer now: read in full again', array( count( $EBAY['prefs'] ), $EBAY['get_items'] ), array( 1, array( '40', '41' ) ) );

$pids = oos_shop();
update_option( 'tcgiant_sync_oos_control', array( 'pref' => 'false', 'ok' => true, 'at' => time() - 23 * HOUR_IN_SECONDS ) );
delta();
check( 'off, as eBay said 23 hours ago: light, eBay not asked', array( gets(), count( $EBAY['prefs'] ) ), array( 0, 0 ) );
$pids = oos_shop();
update_option( 'tcgiant_sync_oos_control', array( 'pref' => 'false', 'ok' => true, 'at' => time() - 25 * HOUR_IN_SECONDS ) );
delta();
check( 'stale - said 25 hours ago: asked again, once, and light', array( gets(), count( $EBAY['prefs'] ), abs( ( oos_kept()['at'] ?? 0 ) - time() ) < 60 ), array( 0, 1, true ) );
$pids = oos_shop();
update_option( 'tcgiant_sync_oos_control', array( 'pref' => 'false', 'ok' => true, 'at' => time() - 25 * HOUR_IN_SECONDS ) );
$EBAY['at_ceiling'] = true;
delta();
check( '  stale at the day\'s call ceiling: not asked, read in full', array( count( $EBAY['prefs'] ), $EBAY['get_items'] ), array( 0, array( '40', '41' ) ) );
$pids = oos_shop();
update_option( 'tcgiant_sync_oos_control', array( 'pref' => 'false', 'ok' => true, 'at' => time() - 25 * HOUR_IN_SECONDS ) );
$EBAY['connected'] = false;
$row = event_row( $EBAY['items']['40'] );
check( '  stale with no eBay connection: not asked, not light', array( renewal_of( $row ), count( $EBAY['prefs'] ) ), array( 0, 0 ) );
$EBAY['connected'] = true;
check( '  and with it: light (the same row)', array( renewal_of( $row ) > 0, count( $EBAY['prefs'] ) ), array( true, 1 ) );

$pids = shop( array( '43' ), array( '43' => array( 'SellingStatus' => array( 'CurrentPrice' => '1.00' ) ) ) );
delta();
check( 'no renewal to record - a price change: eBay not asked', array( gets(), count( $EBAY['prefs'] ) ), array( 1, 0 ) );
$pids = shop( array( '44' ), array( '44' => array( 'Quantity' => '5' ) ), array(), array( '44' => array( 'Quantity' => '5' ) ) );
delta();
check( '  only listings of several renewing: light, not asked', array( gets(), count( $EBAY['prefs'] ) ), array( 0, 0 ) );
$pids = shop( array( '45' ) );
$META[ $pids['45'] ]['_stock'] = 0;
delta();
check( '  a listing of one whose stock differs: read in full, not asked', array( gets(), count( $EBAY['prefs'] ) ), array( 1, 0 ) );
shop( array( '46' ) );
delta( 72 );
check( '  a window read three days late: not asked', count( $EBAY['prefs'] ), 0 );
check( 'uninstall removes the preference', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ), "'tcgiant_sync_oos_control'" ), true );

echo "\nTHE REVIEW'S CASES: A BACKLOG'S END, A FILTER WIDENED AGAIN, SHIPPING IN THE PRICE, CLOCKS PUT BACK\n" . str_repeat( '=', 130 ) . "\n";

// M1. A shop 72 hours behind. The listing was read in full 70 hours ago, its
// description edited on eBay 60 hours ago (nothing eBay's list of changes
// shows), and renewed 12 hours ago - its last change, so it is only in the
// second window. The first window, read 72 hours late, records nothing light;
// the second, read 24 hours late, would. eBay's windows are played by when
// each listing last changed.
$pids = shop( array( '50' ), array( '50' => array( 'Description' => 'NEW: now fits 2009-2012 too.' ) ), array( 'overwrite_desc' => '1' ) );
$EBAY['mod'] = array( '50' => time() - 12 * HOUR_IN_SECONDS );
$META[ $pids['50'] ][ RECORD ]['read'] = time() - 70 * HOUR_IN_SECONDS;
start_window( 72 );
$runs = 0;
do {
	delta_request();
	$runs++;
} while ( as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) && $runs < 20 );
check( 'a backlog\'s end: the renewal in the first window read on time is read in full, its edit imported', array( count( $EBAY['windows'] ), $EBAY['get_items'], saves(), meta( $pids['50'], '_description' ) ), array( 2, array( '50' ), 1, 'NEW: now fits 2009-2012 too.' ) );
check( '  every record from before the late read untrusted, as after a step-over', (int) get_option( AFTER, 0 ) > time() - 60, true );

// The same window read late, needing two runs: a listing the first run reads
// in full keeps that record through the second run, read from the rows kept
// in brief - only eBay's own answer, read late, distrusts.
$ids  = array();
$over = array();
for ( $i = 0; $i < 41; $i++ ) {
	$ids[]                = (string) ( 510000000000 + $i );
	$over[ end( $ids ) ] = array( 'SellingStatus' => array( 'CurrentPrice' => '1.00' ) );
}
$pids = shop( $ids, $over );
$EBAY['mod'] = array_fill_keys( $ids, time() - 60 * HOUR_IN_SECONDS );
start_window( 72 );
delta_request();
$first = $ids[0];
$META[ $pids[ $first ] ][ RECORD ]['read'] = time() - 10;
update_option( AFTER, time() - 20 );
do {
	delta_request();
} while ( as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) );
$EBAY['items'][ $first ]['ListingDetails']['EndTime'] = $E3;
$EBAY['mod']       = array( $first => time() - 1800 );
$EBAY['get_items'] = array();
$SAVES             = array();
start_window( 1 );
do {
	delta_request();
} while ( as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) );
check( '  a record made while the late window was worked through stays trusted: its next renewal is light', array( gets(), saves(), meta( $pids[ $first ], '_ebay_end_time' ) ), array( 0, 0, $E3 ) );
$EBAY['mod'] = array();

// L1. Product 52's row is skipped by the filter, its category remembered or
// sent in the row; then the filter takes it again. Its next renewal is read
// in full, so the edit made while it was left out arrives.
foreach ( array( 'remembered' => false, 'in the row' => true ) as $how => $in_row ) {
	$pids = shop( array( '52' ), array( '52' => array( 'Description' => 'NEW text', 'ListingDetails' => array( 'EndTime' => $E1 ) ) ), array( 'overwrite_desc' => '1', 'import_standard_category_ids' => array( '33563' ) ) );
	if ( $in_row ) {
		$EBAY['event_over']['52'] = array( 'PrimaryCategory' => array( 'CategoryID' => '33563' ) );
	}
	$OPTIONS['tcgiant_sync_ebay_settings']['import_standard_category_ids'] = array( '6030' );
	delta();
	$left = array( gets(), record( $pids['52'] ) );
	$OPTIONS['tcgiant_sync_ebay_settings']['import_standard_category_ids'] = array( '33563' );
	$EBAY['items']['52']['ListingDetails']['EndTime'] = $E2;
	unset( $EBAY['event_over']['52'] );
	$EBAY['get_items'] = array();
	delta();
	check( 'a row the filter skips (category ' . $how . '): no GetItem, its record forgotten; taken again, its renewal is read in full', array( $left, $EBAY['get_items'], meta( $pids['52'], '_description' ) ), array( array( 0, null ), array( '52' ), 'NEW text' ) );
}

// L2. With "Include shipping in the price" on, the website's price is eBay's
// plus the shipping, which eBay's list of changes leaves out.
$pids = shop( array( '53' ), array( '53' => array( 'ShippingDetails' => array( 'ShippingServiceOptions' => array( 'ShippingService' => 'USPSPriority', 'ShippingServiceCost' => '15.00' ) ) ) ), array( 'bake_shipping_into_price' => '1' ) );
delta();
check( 'shipping included in the price: every renewal read in full, eBay\'s Out-of-Stock answer never needed', array( gets(), saves(), count( $EBAY['prefs'] ) ), array( 1, 1, 0 ) );
$pids = shop( array( '54' ), array(), array( 'bake_shipping_into_price' => '0' ) );
delta();
check( '  saved as off: light', array( gets(), saves() ), array( 0, 0 ) );

// L3. Times a server clock later put back: in the future.
check( 'a record read in the future: full path', full_path( array(), function ( $pid ) { $GLOBALS['META'][ $pid ][ RECORD ]['read'] = time() + 2 * HOUR_IN_SECONDS; } ), array( 1, 1 ) );
$pids = oos_shop();
update_option( 'tcgiant_sync_oos_control', array( 'pref' => 'false', 'ok' => true, 'at' => time() + 2 * HOUR_IN_SECONDS ) );
delta();
check( 'an Out-of-Stock answer kept from the future: stale, asked again, light on eBay\'s new answer', array( gets(), count( $EBAY['prefs'] ), abs( ( oos_kept()['at'] ?? 0 ) - time() ) < 60 ), array( 0, 1, true ) );
$pids = oos_shop();
update_option( 'tcgiant_sync_oos_control', array( 'pref' => 'false', 'ok' => true, 'at' => time() + 2 * HOUR_IN_SECONDS ) );
$EBAY['at_ceiling'] = true;
delta();
check( '  and at the call ceiling: not asked, read in full', array( count( $EBAY['prefs'] ), $EBAY['get_items'] ), array( 0, array( '40', '41' ) ) );

echo "\nSTOCK AND THE RECORDS OF ITEM 14 ARE NEVER WRITTEN BY A RENEWAL\n" . str_repeat( '=', 130 ) . "\n";

$pids = shop( array( '17', '18' ) );
$META[ $pids['18'] ]['_ebay_push_pending'] = array( 'sale-x' => array( 'until' => time() + 3600, 'queued_at' => time() ) );
$META[ $pids['18'] ]['_stock']             = 0;
$before = array( meta( $pids['17'], '_ebay_sold_seen' ), meta( $pids['18'], '_ebay_sold_seen' ), meta( $pids['18'], '_ebay_push_pending' ) );
delta();
check( 'the renewal writes no stock, status, push mark or sales record', array_values( array_intersect( written( $pids['17'] ), array( '_stock', '_stock_status', '_ebay_listing_status', '_ebay_sold_seen', '_ebay_push_pending', '_ebay_unsent_qty', '_ebay_stock_settled_at' ) ) ), array() );
check( '  a website sale on its way: full path, and the import keeps WooCommerce\'s 0 and the record', array( $EBAY['get_items'], meta( $pids['18'], '_stock' ), meta( $pids['18'], '_ebay_sold_seen' ), meta( $pids['18'], '_ebay_push_pending' ) ), array( array( '18' ), 0, $before[1], $before[2] ) );

echo "\nAN ENDED PRODUCT, OR ONE IN THE SOLD VIEW, IS NEVER TOUCHED BY A RENEWAL\n" . str_repeat( '=', 130 ) . "\n";

$pids = shop( array( '19' ) );
$META[ $pids['19'] ]['_ebay_listing_status'] = 'Ended';
$META[ $pids['19'] ]['_stock']               = 0;
$META[ $pids['19'] ]['_stock_status']        = 'outofstock';
$ROWS[ $pids['19'] ]['listing_status']        = 'Ended';
$EBAY['answers']['19']                        = new WP_Error( 'api_error', 'not scripted' );
delta();
check( 'in the Sold view: not light - its end date and Listings row as they were, eBay asked', array( meta( $pids['19'], '_ebay_end_time' ), $ROWS[ $pids['19'] ]['ebay_end_time'] ?? null, $ROWS[ $pids['19'] ]['listing_status'] ?? null, logged( 'renewals (end date moved' ), gets() ), array( $E1, $E1, 'Ended', false, 1 ) );

echo "\nONE WINDOW OF 1,795 RENEWALS\n" . str_repeat( '=', 130 ) . "\n";

$ids = array();
for ( $i = 0; $i < 1795; $i++ ) {
	$ids[] = (string) ( 174260628000 + $i );
}
$pids = shop( $ids, array(
	$ids[100]  => array( 'SellingStatus' => array( 'CurrentPrice' => '19.99' ) ),
	$ids[900]  => array( 'Title' => 'Changed' ),
	$ids[1700] => array( 'SellingStatus' => array( 'CurrentPrice' => '9.99' ) ),
) );
$t0   = microtime( true );
$runs = delta();
$secs = microtime( true ) - $t0;
check( 'GetItem only for the three that changed', $EBAY['get_items'], array( $ids[100], $ids[900], $ids[1700] ) );
check( '  three saves', saves(), 3 );
check( '  every end date moved on', count( array_filter( $pids, function ( $pid ) { return $GLOBALS['E2'] === meta( $pid, '_ebay_end_time' ); } ) ), 1795 );
check( '  in no more than 8 requests (3.28.4: 45), from one read of eBay\'s list', array( $runs <= 8, $EBAY['event_reads'] ), array( true, 1 ) );
check( '  rows 501 on, read from the window kept in brief, are light too', meta( $pids[ $ids[1794] ], '_ebay_end_time' ), $E2 );
check( '  the window finishes', state()['status'] ?? '', 'complete' );
printf( "      (%d requests, %d GetSellerEvents read, %d GetItem, %d saves, %.1f s)\n", $runs, $EBAY['event_reads'], gets(), saves(), $secs );

echo "\nFORTY PER RUN STILL HOLDS FOR LISTINGS THAT NEED EBAY\n" . str_repeat( '=', 130 ) . "\n";

$ids  = array();
$over = array();
for ( $i = 0; $i < 100; $i++ ) {
	$ids[]                = (string) ( 300000000000 + $i );
	$over[ end( $ids ) ] = array( 'SellingStatus' => array( 'CurrentPrice' => '1.00' ) );
}
shop( $ids, $over );
start_window();
$per = array();
do {
	$before = gets();
	delta_request();
	$per[] = gets() - $before;
} while ( as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) && count( $per ) < 50 );
check( '100 price changes: 40, 40 and 20 GetItem a request', $per, array( 40, 40, 20 ) );
check( '  every one saved once', array( saves(), max( $SAVES ?: array( 0 ) ) ), array( 100, 1 ) );

// With the category filter on and nothing remembered, each change costs the
// filter's GetItem, which the import then uses: one a row, and forty a run.
$ids  = array();
$over = array();
for ( $i = 0; $i < 41; $i++ ) {
	$ids[]                = (string) ( 400000000000 + $i );
	$over[ end( $ids ) ] = array( 'SellingStatus' => array( 'CurrentPrice' => '1.00' ) );
}
shop( $ids, $over, array( 'import_standard_category_ids' => array( '33563' ) ) );
foreach ( array_keys( $OPTIONS ) as $name ) {
	if ( 0 === strpos( $name, 'tcgiant_sync_category_memory_' ) ) {
		unset( $OPTIONS[ $name ] );
	}
}
start_window();
$per = array();
do {
	$before = gets();
	delta_request();
	$per[] = gets() - $before;
} while ( as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) && count( $per ) < 50 );
check( 'the category filter on, 41 changes not remembered: 40 and 1 GetItem a request, each counted once', $per, array( 40, 1 ) );
check( '  every one saved once', array( saves(), max( $SAVES ?: array( 0 ) ) ), array( 41, 1 ) );

echo "\nTHE HOURLY ENDED-LISTING CHECK GETS TO THE RENEWAL FIRST\n" . str_repeat( '=', 130 ) . "\n";

$pids = shop( array( '20' ) );
TCGiant_Sync_Cron::instance()->check_ended_listings();
check( 'the hourly check reads it (its end has passed) and moves its end date', array( gets(), meta( $pids['20'], '_ebay_end_time' ), meta( $pids['20'], '_ebay_listing_status' ) ), array( 1, $E2, 'Active' ) );
delta();
check( '  the delta\'s row is still light: one GetItem in all (3.28.4: two)', array( gets(), saves(), record( $pids['20'] )['end'] ?? null ), array( 1, 0, $E2 ) );

echo "\nTHE WEEKLY FULL SYNC WRITES THE RECORD, SO SAVINGS START AFTER IT\n" . str_repeat( '=', 130 ) . "\n";

$pids = shop( array( '21' ) );
unset( $META[ $pids['21'] ][ RECORD ] );
$page = item( '21', array( 'ListingDetails' => array( 'EndTime' => $E1 ) ) );
call_private( TCGiant_Sync_Importer::instance(), 'process_page_items', array( $page ), 1, 1 );
check( 'a page of GetSellerList saved inline writes the record', array( saves(), record( $pids['21'] )['end'] ?? null, record( $pids['21'] )['sig'] ?? null ), array( 1, $E1, method_exists( 'TCGiant_Sync_Mapper', 'event_signature' ) ? TCGiant_Sync_Mapper::event_signature( event_row( $EBAY['items']['21'] ) ) : 'its signature' ) );
$SAVES = array();
delta();
check( '  and the next renewal is light', array( gets(), saves(), meta( $pids['21'], '_ebay_end_time' ) ), array( 0, 0, $E2 ) );

echo "\nA FULL READ THAT FAILS FORGETS THE RECORD\n" . str_repeat( '=', 130 ) . "\n";

$pids = shop( array( '22' ), array( '22' => array( 'SellingStatus' => array( 'CurrentPrice' => '5.00' ) ) ) );
$EBAY['answers']['22'] = new WP_Error( 'api_error', 'The item could not be found.' );
delta();
check( 'GetItem fails for a changed listing: its record goes', record( $pids['22'] ), null );
$EBAY['answers'] = array();
$EBAY['items']['22']['SellingStatus']['CurrentPrice'] = '24.99';
$EBAY['get_items'] = array();
delta();
check( '  so its next renewal is read in full, whatever it shows', array( gets(), saves() ), array( 1, 1 ) );

$pids = shop( array( '23' ), array( '23' => array( 'SellingStatus' => array( 'CurrentPrice' => '5.00' ) ) ) );
$SAVE_FAILS[ $pids['23'] ] = true;
delta();
check( 'the save fails: its record goes', record( $pids['23'] ), null );

$pids = shop( array( '24' ), array( '24' => array( 'SellingStatus' => array( 'CurrentPrice' => '5.00' ) ) ) );
$SAVE_THROWS[ $pids['24'] ] = true;
delta();
check( 'the save throws: its record goes', array( record( $pids['24'] ), logged( 'Delta import exception' ) ), array( null, true ) );

$pids = shop( array( '24' ), array( '24' => array( 'SellingStatus' => array( 'CurrentPrice' => '5.00' ) ) ) );
$SAVE_THROWS[ $pids['24'] ] = 'error';
delta();
check( '  a PHP Error in it: its record goes', array( record( $pids['24'] ), logged( 'Delta import fatal' ) ), array( null, true ) );

$pids = shop( array( '25' ), array( '25' => array( 'SellingStatus' => array( 'CurrentPrice' => '5.00' ) ) ) );
$EBAY['answers']['25'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
start_window();
for ( $i = 0; $i < 3; $i++ ) {
	delta_request();
}
check( 'eBay cannot be read for it three runs in a row: moved past, and its record goes', array( isset( get_option( 'tcgiant_sync_delta_held', array() )['25'] ), record( $pids['25'] ) ), array( true, null ) );

// A run killed on a listing twice is stepped over: the place as the dying
// request left it.
$pids = shop( array( '26', '27' ) );
start_window();
delta_request();
$from = state()['delta_mod_from'];
$pids = shop( array( '26', '27' ) );
$st   = start_window();
$EBAY['from'] = $st;
update_option( 'tcgiant_sync_delta_progress', array( 'from' => $st, 'to' => iso( time() ), 'cursor' => '', 'done' => 0, 'in_flight' => '26', 'kills' => 1, 'kill_item' => '26', 'read_at' => time(), 'body_bytes' => 0, 'settled' => false, 'ts' => time() ) );
update_option( 'tcgiant_sync_delta_window', array( 'key' => $st . '|' . iso( time() ), 'rows' => array_map( function ( $id ) { return call_private( 'TCGiant_Sync_Importer', 'compact_delta_item', event_row( $GLOBALS['EBAY']['items'][ $id ] ) ); }, array( '26', '27' ) ) ) );
delta_request();
check( 'a listing that killed two runs is stepped over: its record goes, the next is light', array( record( $pids['26'] ), meta( $pids['27'], '_ebay_end_time' ), logged( 'stopped 2 times in a row while importing eBay item 26' ) ), array( null, $E2, true ) );

echo "\nAFTER A WINDOW IS STEPPED OVER, EVERY LISTING IS READ IN FULL ONCE\n" . str_repeat( '=', 130 ) . "\n";

/** Run the step-over, then a renewal: whether it was read in full. */
function after_step_over( callable $step ) {
	$pids = shop( array( '28' ) );
	// Read in full a minute before the step-over.
	$GLOBALS['META'][ $pids['28'] ][ RECORD ]['read'] = time() - 60;
	$step();
	$after = (int) get_option( AFTER, 0 );
	$GLOBALS['EBAY']['events_answer'] = null;
	unset( $GLOBALS['EBAY']['events_answer'] );
	$GLOBALS['EBAY']['get_items'] = array();
	$GLOBALS['SAVES']             = array();
	delta();
	return array( $after > time() - 60, gets(), saves() );
}
check( 'killed three times reading the shortest window', after_step_over( function () {
	$from = start_window( 1, 15 );
	$st   = state();
	$st['delta_attempt']       = $from . '|15';
	$st['delta_attempt_kills'] = 2;
	update_option( 'tcgiant_sync_state', $st );
	delta_request();
} ), array( true, 1, 1 ) );
check( 'eBay refusing the shortest window', after_step_over( function () {
	$GLOBALS['EBAY']['events_answer'] = new WP_Error( 'api_error', 'Please try a smaller complete result set.' );
	start_window( 1, 15 );
	delta_request();
} ), array( true, 1, 1 ) );
check( 'eBay\'s 3,000 at the shortest window', after_step_over( function () {
	$rows = array();
	for ( $i = 0; $i < 3000; $i++ ) {
		$rows[] = array( 'ItemID' => (string) ( 900000000000 + $i ), 'Site' => 'UK', 'SellingStatus' => array( 'ListingStatus' => 'Active' ) );
	}
	$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' );
	$GLOBALS['EBAY']['events_answer'] = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $rows ) );
	start_window( 1, 15 );
	do {
		delta_request();
	} while ( as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) );
	$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = array();
} ), array( true, 1, 1 ) );
check( 'no place in the state to start from', after_step_over( function () {
	update_option( 'tcgiant_sync_state', array( 'status' => 'scanning', 'sync_mode' => 'delta', 'delta_mod_from' => '' ) );
	delta_request();
} ), array( true, 1, 1 ) );

echo "\nA REQUEST KILLED WHILE RECORDING A RENEWAL\n" . str_repeat( '=', 130 ) . "\n";

// Its own PHP process, killed by a real out-of-memory fatal in the record
// write of the second listing - after its end date, before its record.
$pids  = shop( array( '29', '30', '31' ) );
start_window();
$world = sys_get_temp_dir() . '/tcg-renewal-kill-' . getmypid() . '.ser';
file_put_contents( $world, serialize( array( 'globals' => array_intersect_key( $GLOBALS, array_flip( array( 'POSTS', 'META', 'ROWS', 'OPTIONS', 'TRANSIENTS', 'QUEUE', 'EBAY', 'NEXT_ID', 'E1', 'E2', 'E3' ) ) ), 'kill_on' => $pids['30'] ) ) );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/renewal-kill.php' ) . ' ' . escapeshellarg( $world ) . ' 2>&1', $out, $code );
$back = unserialize( (string) file_get_contents( $world ) );
@unlink( $world );
check( 'the request dies of a PHP fatal', is_array( $back ) && ! empty( $back['fatal'] ), true );
if ( is_array( $back ) ) {
	foreach ( $back['globals'] as $name => $value ) {
		$GLOBALS[ $name ] = $value;
	}
}
check( '  having recorded the first, and the second\'s end date', array( meta( $pids['29'], '_ebay_end_time' ), record( $pids['29'] )['end'] ?? null, meta( $pids['30'], '_ebay_end_time' ), record( $pids['30'] )['end'] ?? null ), array( $E2, $E2, $E2, $E1 ) );
check( '  and handed straight on', as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ), true );
$EBAY['get_items'] = array();
$SAVES             = array();
$LOG               = array();
do {
	delta_request();
} while ( as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) );
check( 'the next request tries the second once more, and records it', array( logged( 'The last delta run stopped while importing eBay item 30' ), record( $pids['30'] )['end'] ?? null ), array( true, $E2 ) );
check( '  nothing read in full or saved twice, the third light too, the window finished', array( gets(), saves(), meta( $pids['31'], '_ebay_end_time' ), state()['status'] ?? '' ), array( 0, 0, $E2, 'complete' ) );

// The same, killed after the record write: the retry finds the end already
// moved, and reads it in full - one GetItem, one save.
$pids  = shop( array( '32', '33' ) );
start_window();
$world = sys_get_temp_dir() . '/tcg-renewal-kill-' . getmypid() . '.ser';
file_put_contents( $world, serialize( array( 'globals' => array_intersect_key( $GLOBALS, array_flip( array( 'POSTS', 'META', 'ROWS', 'OPTIONS', 'TRANSIENTS', 'QUEUE', 'EBAY', 'NEXT_ID', 'E1', 'E2', 'E3' ) ) ), 'kill_on' => $pids['32'], 'kill_after' => true ) ) );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/renewal-kill.php' ) . ' ' . escapeshellarg( $world ) . ' 2>&1', $out, $code );
$back = unserialize( (string) file_get_contents( $world ) );
@unlink( $world );
if ( is_array( $back ) ) {
	foreach ( $back['globals'] as $name => $value ) {
		$GLOBALS[ $name ] = $value;
	}
}
$EBAY['get_items'] = array();
$SAVES             = array();
do {
	delta_request();
} while ( as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events' ) );
check( 'killed after its record was written: the retry reads it in full, once', array( ! empty( $back['fatal'] ), $EBAY['get_items'], saves(), meta( $pids['33'], '_ebay_end_time' ), state()['status'] ?? '' ), array( true, array( '32' ), 1, $E2, 'complete' ) );

echo "\nA COPY OR AN UNLINKED PRODUCT HAS NO RECORD\n" . str_repeat( '=', 130 ) . "\n";

check( 'the record is among the keys a duplicate or an unlink drops', in_array( RECORD, TCGiant_Sync_Listing_Link::listing_identity_meta_keys(), true ), true );
$pids = shop( array( '34' ) );
TCGiant_Sync_Listing_Link::unlink_product_from_ebay( $pids['34'] );
check( '  unlinking takes it off', record( $pids['34'] ), null );
check( 'uninstall removes the step-over time', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ), "'" . AFTER . "'" ), true );

echo "\nWHAT IS SENT TO EBAY\n" . str_repeat( '=', 130 ) . "\n";

class Wire_eBay extends TCGiant_Sync_API {
	public $bodies = array();
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$this->bodies[ $call_name ] = $xml_body;
		return array( 'Ack' => 'Success' );
	}
}
$wire = new Wire_eBay();
$wire->get_seller_events( iso( time() - 3600 ), iso( time() ) );
$wire->get_item( '1' );
$wire->get_active_listings( 1, 200 );
check( 'GetSellerEvents no longer sends IncludeItemSpecifics, which is not one of its inputs', false !== strpos( $wire->bodies['GetSellerEvents'], 'IncludeItemSpecifics' ), false );
check( '  but still IncludeVariationSpecifics', false !== strpos( $wire->bodies['GetSellerEvents'], '<IncludeVariationSpecifics>true</IncludeVariationSpecifics>' ), true );
check( 'GetItem and GetSellerList still do', array( false !== strpos( $wire->bodies['GetItem'], '<IncludeItemSpecifics>true</IncludeItemSpecifics>' ), false !== strpos( $wire->bodies['GetSellerList'], '<IncludeItemSpecifics>true</IncludeItemSpecifics>' ) ), array( true, true ) );

echo "\nTHE SIGNATURE\n" . str_repeat( '=', 130 ) . "\n";

if ( method_exists( 'TCGiant_Sync_Mapper', 'event_signature' ) ) {
	$full = item( '35' );
	check( 'GetItem\'s answer and the events row about the same listing sign alike', TCGiant_Sync_Mapper::event_signature( $full ), TCGiant_Sync_Mapper::event_signature( event_row( $full ) ) );
	check( '  0, 1 and no Quantity are one', array( TCGiant_Sync_Mapper::event_signature( item( '35', array( 'Quantity' => '0' ) ) ), TCGiant_Sync_Mapper::event_signature( item( '35', array( 'Quantity' => array( '#text' => '1' ) ) ) ) ), array( TCGiant_Sync_Mapper::event_signature( event_row( $full ) ), TCGiant_Sync_Mapper::event_signature( event_row( $full ) ) ) );
	check( '  a price as 24.99 or 24.990 or with its currency kept is one', array( TCGiant_Sync_Mapper::event_signature( item( '35', array( 'SellingStatus' => array( 'CurrentPrice' => '24.990' ) ) ) ), TCGiant_Sync_Mapper::event_signature( item( '35', array( 'SellingStatus' => array( 'CurrentPrice' => array( '#text' => '24.99', '@attributes' => array( 'currencyID' => 'USD' ) ) ) ) ) ) ), array( TCGiant_Sync_Mapper::event_signature( $full ), TCGiant_Sync_Mapper::event_signature( $full ) ) );
	check( '  the end time is not in it', TCGiant_Sync_Mapper::event_signature( item( '35', array( 'ListingDetails' => array( 'EndTime' => $E3 ) ) ) ), TCGiant_Sync_Mapper::event_signature( $full ) );
	$differs = array();
	foreach ( array( 'ItemID' => array( 'ItemID' => '36' ), 'ListingType' => array( 'ListingType' => 'StoresFixedPrice' ), 'StartTime' => array( 'ListingDetails' => array( 'StartTime' => '2026-04-01T00:00:00.000Z' ) ), 'Title' => array( 'Title' => 'x' ), 'CurrentPrice' => array( 'SellingStatus' => array( 'CurrentPrice' => '25.00' ) ), 'Quantity' => array( 'Quantity' => '2' ), 'QuantitySold' => array( 'SellingStatus' => array( 'QuantitySold' => '1' ) ) ) as $field => $o ) {
		if ( TCGiant_Sync_Mapper::event_signature( item( '35', $o ) ) !== TCGiant_Sync_Mapper::event_signature( $full ) ) {
			$differs[] = $field;
		}
	}
	check( '  each field eBay\'s list of changes carries is in it', $differs, array( 'ItemID', 'ListingType', 'StartTime', 'Title', 'CurrentPrice', 'Quantity', 'QuantitySold' ) );
	check( '  a variation listing\'s is empty', TCGiant_Sync_Mapper::event_signature( item( '35', array( 'Variations' => array( 'Variation' => array() + array( array( 'SKU' => 'A' ) ) ) ) ) ), '' );
} else {
	check( 'TCGiant_Sync_Mapper::event_signature() exists', false, true );
}

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
