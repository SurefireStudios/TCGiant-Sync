<?php
/**
 * Harness: removing a product whose eBay listing sold out.
 *
 * A shop with over fifteen thousand products wrote in: "Items are not being
 * deleted after being sold, my last 10 items did not delete."
 *
 * The cause was structural rather than a broken condition. Removal had exactly
 * ONE call site - inside the delta sync's ended-listing branch - while THREE
 * paths notice that a listing has ended: that branch, the hourly ended-listing
 * check, and the Stock Review settle. The other two set the stock to zero and
 * left the product in the catalogue, because they had no way to remove it.
 *
 * So whichever path saw the ending first decided whether the product was ever
 * removed, and on a large catalogue the delta sync is the slowest of the three:
 * it handles forty changed items per run. Worse, the hourly check's query
 * excludes anything already marked Ended - so a product gets one pass, ever. If
 * the wrong path won that race, nothing would ever reconsider it.
 *
 * Every refusal was also a bare `return false`, and the line that WAS logged on
 * the way past reads like success, which is why this had to be diagnosed from
 * the source rather than from the merchant's log.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-68s got %-16s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$root      = dirname( __DIR__ );
$inventory = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-inventory.php' );
$importer  = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-importer.php' );
$cron      = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-cron.php' );
$jobs      = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-jobs.php' );
$settings  = (string) file_get_contents( $root . '/admin/views/settings.php' );
$review    = (string) file_get_contents( $root . '/admin/views/stock-review.php' );

echo "\nEVERY PATH THAT SEES A LISTING END CAN NOW ACT ON IT\n" . str_repeat( '=', 114 ) . "\n";

// The whole bug in one assertion: one decision, one caller, three observers.
$calls = substr_count( $importer, 'TCGiant_Sync_Inventory::maybe_remove_sold_out(' )
	+ substr_count( $cron, 'TCGiant_Sync_Inventory::maybe_remove_sold_out(' )
	+ substr_count( $jobs, 'TCGiant_Sync_Inventory::maybe_remove_sold_out(' );

check( 'removal is reachable from four places, not one', $calls, 4 );
check( '  the delta sync', false !== strpos( $importer, 'TCGiant_Sync_Inventory::maybe_remove_sold_out( (int) $existing_id, $listed, $sold )' ), true );
check( '  the hourly check, both its branches', substr_count( $cron, 'TCGiant_Sync_Inventory::maybe_remove_sold_out(' ), 2 );
check( '  and the Stock Review settle', false !== strpos( $jobs, 'TCGiant_Sync_Inventory::maybe_remove_sold_out(' ), true );

// One implementation, so the rules cannot drift between the four.
check( 'there is one implementation', substr_count( $inventory, 'public static function maybe_remove_sold_out(' ), 1 );
check( '  and the importer no longer keeps its own', false === strpos( $importer, 'function maybe_remove_sold_out_product' ), true );

// It lives beside the function that means "this listing is over".
check( 'it sits with the stock settler it belongs to', false !== strpos( $inventory, 'public static function apply_ended_listing_stock' ), true );

echo "\nAND SAYS WHY WHEN IT KEEPS ONE\n" . str_repeat( '=', 114 ) . "\n";

// Six bare returns and no log is why neither the merchant nor we could tell
// which condition had fired.
check( 'the setting being off is said out loud', false !== strpos( $inventory, 'the "Remove sold products" setting is off' ), true );
check( 'a pushed product says it is the shop\'s own', false !== strpos( $inventory, 'pushed to eBay from WooCommerce' ), true );
check( 'a preserved category says so', false !== strpos( $inventory, 'it is in a category set to be preserved' ), true );
check( 'the reason reaches the activity log', false !== strpos( $inventory, "'WC #%d sold out on eBay but was kept: %s.'" ), true );

// A shop whose whole catalogue is exempt would otherwise get a line per sale
// per path, on a catalogue of fifteen thousand.
check( '  once per product, not once per pass', false !== strpos( $inventory, "if ( \$because === get_post_meta( \$product_id, \$key, true ) ) {" ), true );

echo "\nTHE ARITHMETIC IS UNCHANGED\n" . str_repeat( '=', 114 ) . "\n";

/** Verbatim replica of the sold-out test. */
function sold_out( $listed, $sold ) {
	if ( null === $listed || null === $sold ) {
		return false;
	}

	return ! ( (int) $listed < 1 || (int) $sold < (int) $listed );
}

check( 'one listed, one sold is sold out', sold_out( 1, 1 ), true );
check( 'ten listed, ten sold is sold out', sold_out( 10, 10 ), true );
check( 'ten listed, nine sold is not', sold_out( 10, 9 ), false );
check( 'ended unsold is not', sold_out( 1, 0 ), false );
check( 'more sold than listed still counts', sold_out( 1, 5 ), true );

// Ended is not sold. A listing the seller pulled leaves goods the shop owns,
// and removing the product would contradict the stock just written.
check( 'nothing eBay would not answer for', sold_out( null, null ), false );
check( '  nor a half answer', sold_out( 1, null ), false );
check( 'a listing of nothing is not a sale', sold_out( 0, 0 ), false );

echo "\nTHE BACKLOG IS SHOWN, NOT SILENTLY TRASHED\n" . str_repeat( '=', 114 ) . "\n";

// Fixing the forward path does nothing for products already settled and left:
// the hourly query excludes anything marked Ended, so they get no second pass.
check( 'there is a finder for them', false !== strpos( $inventory, 'public static function find_sold_out_not_removed(' ), true );

// Found without asking eBay again: the settle recorded the judgement, and eBay
// drops old listings, which a backlog is made of.
check( '  it reads the settle mark', false !== strpos( $inventory, "settled.meta_key = '_ebay_stock_settled_at'" ), true );
check( '  and only out-of-stock ended products', false !== strpos( $inventory, "stock.meta_value = 'outofstock'" ), true );
check( '  never the trash', false !== strpos( $inventory, "p.post_status NOT IN ( 'trash', 'auto-draft' )" ), true );

check( 'the guards apply to the backlog too', false !== strpos( $inventory, 'public static function remove_if_allowed(' ), true );
check( '  which is what the arithmetic path calls', false !== strpos( $inventory, 'return self::remove_if_allowed( $product_id );' ), true );

check( 'it is a job the shop starts', false !== strpos( $jobs, "case 'bulk_remove_sold':" ), true );
check( '  an accepted type', false !== strpos( $jobs, "'bulk_set_format', 'bulk_remove_sold' )" ), true );
check( '  resolved server-side for select-all', false !== strpos( $jobs, "TCGiant_Sync_Inventory::find_sold_out_not_removed();" ), true );

// Kept is neither a success nor a failure, and inflating either would misreport
// a run on a catalogue that is largely exempt.
check( 'products kept are counted separately', false !== strpos( $jobs, "'kept'      => \$kept," ), true );
check( '  and initialised, unlike the counter it replaces', false !== strpos( $jobs, "\$kept        = \$job['kept'] ?? 0;" ), true );

check( 'the shop is shown the list first', false !== strpos( $review, 'tc-remove-sold' ), true );
check( '  told the trash is not permanent', false !== strpos( $review, 'They go to the trash, not away for good' ), true );
check( '  and nothing runs on upgrade', false === strpos( $inventory, 'add_action' ) || false === strpos( $inventory, 'find_sold_out_not_removed();' ), true );

echo "\nTHE SETTINGS SCREEN AGREES WITH THE SCHEDULER\n" . str_repeat( '=', 114 ) . "\n";

// The screen said "Disabled" while the scheduler ran hourly, so a shop that had
// never touched the field and pressed Save switched off a sync that was going.
check( 'the scheduler still defaults to hourly', false !== strpos( $cron, "\$settings['sync_interval'] ?? 'tcgiant_hourly'" ), true );
// Comments stripped: the note explaining this very fault quotes the default it
// is about, and must not be allowed to answer for the markup.
$settings_code = php_strip_whitespace( $root . '/admin/views/settings.php' );

check( '  and so does the screen now, on all five options', substr_count( $settings_code, "\$settings['sync_interval'] ?? 'tcgiant_hourly'" ), 5 );
check( '  with none still claiming otherwise', substr_count( $settings_code, "\$settings['sync_interval'] ?? 'disabled'" ), 0 );
check( '  and the reason recorded', false !== strpos( $settings, 'switched off a sync that was running' ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
