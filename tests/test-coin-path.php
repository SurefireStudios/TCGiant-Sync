<?php
/**
 * Harness: a product set to All Other is not a coin because of its category's path.
 *
 * Any category whose stored path contains "Coins & Paper Money" counted as a
 * coin category, so a share certificate, bond or banknote in a Stocks &
 * Bonds or Paper Money leaf was sent the coin defaults - Certification,
 * Grade, Year and Circulated/Uncirculated - even with its Item Type set to
 * All Other, which is_descriptor_category() already honours. From 3.27.5 the
 * path test is skipped for All Other. Nothing changes for a product that
 * never had All Other chosen, and detection by category ID is untouched.
 *
 * Loaded for real: TCGiant_Sync_Exporter's coin detection and the specifics
 * it builds from it, with TCGiant_Sync_Catalog.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-84s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 160 ) );
}

$ROOT = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-exporter.php';

$x         = ( new ReflectionClass( 'TCGiant_Sync_Exporter' ) )->newInstanceWithoutConstructor();
$collect   = new ReflectionMethod( 'TCGiant_Sync_Exporter', 'collect_configured_specifics' );
$derive    = new ReflectionMethod( 'TCGiant_Sync_Exporter', 'add_derived_specifics' );
if ( PHP_VERSION_ID < 80100 ) {
	$collect->setAccessible( true );
	$derive->setAccessible( true );
}

$SCRIPOPHILY = 'Coins & Paper Money > Stocks & Bonds, Scripophily > Railroads';
$PAPER_MONEY = 'Coins & Paper Money > Paper Money: US > Large Size Notes';
$US_COIN     = 'Coins & Paper Money > Coins: US > Mint Sets';

/** What get_export_settings() resolves for a product: a graded item, PCGS MS 65, 1921. */
function settings( $category_name, $item_type, $category_id = '3410' ) {
	return array(
		'category_id'    => $category_id,
		'category_name'  => $category_name,
		'item_type'      => $item_type,
		'condition_type' => 'graded',
		'grader_id'      => TCGiant_Sync_Catalog::GRADERS_COINS['PCGS'],
		'grade_value'    => 'MS 65',
		'coin_year'      => '1921',
	);
}
/** The coin specifics the push sends for these settings. */
function coin_specifics( array $settings ) {
	global $x, $collect, $derive;
	return $derive->invoke( $x, $collect->invoke( $x, $settings ), $settings );
}

$COIN_DEFAULTS = array( 'Certification' => 'PCGS', 'Grade' => 'MS 65', 'Year' => '1921', 'Circulated/Uncirculated' => 'Uncirculated' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nALL OTHER: NOT A COIN BY ITS PATH\n" . str_repeat( '=', 124 ) . "\n";

check( 'a railroad bond in Stocks & Bonds, set to All Other: not a coin category', TCGiant_Sync_Exporter::is_coins_category( '3410', 'other', $SCRIPOPHILY ), false );
check( '  so no coin specifics are sent with it', coin_specifics( settings( $SCRIPOPHILY, 'other' ) ), array() );
check( 'a banknote in Paper Money, set to All Other: the same', array( TCGiant_Sync_Exporter::is_coins_category( '3412', 'other', $PAPER_MONEY ), coin_specifics( settings( $PAPER_MONEY, 'other', '3412' ) ) ), array( false, array() ) );
check( 'and its specifics already there are left as they were, nothing added', $derive->invoke( $x, array( 'Issuing Company' => 'Erie Railroad' ), settings( $SCRIPOPHILY, 'other' ) ), array( 'Issuing Company' => 'Erie Railroad' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNOTHING CHANGES FOR A PRODUCT THAT NEVER HAD ALL OTHER CHOSEN\n" . str_repeat( '=', 124 ) . "\n";

check( 'no Item Type, a Stocks & Bonds path: still a coin category, as before', TCGiant_Sync_Exporter::is_coins_category( '3410', '', $SCRIPOPHILY ), true );
check( '  and still sent the coin defaults', coin_specifics( settings( $SCRIPOPHILY, '' ) ), $COIN_DEFAULTS );
check( 'Item Type Coins: a coin, with its specifics', array( TCGiant_Sync_Exporter::is_coins_category( '526', 'coins', $US_COIN ), coin_specifics( settings( $US_COIN, 'coins', '526' ) ) ), array( true, $COIN_DEFAULTS ) );
check( 'Item Type Trading Cards in a coin path: by the path, as before', TCGiant_Sync_Exporter::is_coins_category( '526', 'tcg', $US_COIN ), true );
check( 'no Item Type, a coin leaf by its path: a coin', TCGiant_Sync_Exporter::is_coins_category( '526', '', $US_COIN ), true );
check( 'All Other with no coin path: not a coin, as before', TCGiant_Sync_Exporter::is_coins_category( '3410', 'other', 'Collectibles > Postcards' ), false );

// Detection by category ID waits for a later phase: a known coin category ID
// still counts, whatever Item Type says.
check( 'All Other in a known coin category ID (253, Coins: US): by its ID, as before', TCGiant_Sync_Exporter::is_coins_category( '253', 'other', '' ), true );
check( 'All Other never gets condition descriptors, as before', TCGiant_Sync_Exporter::is_descriptor_category( '253', 'other' ), false );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
