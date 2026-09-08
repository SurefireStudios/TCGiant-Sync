<?php
/**
 * Harness: the title cleaner and the condition list.
 *
 * Both affect what a buyer sees on a live listing, and both were wrong in a way
 * that produced no error anywhere - a shortened title and an overstated
 * condition just quietly happen.
 */

const MAX_TITLE_LENGTH = 80;

// ---- verbatim from the exporter ---------------------------------------------
function sanitize_title( $title ) {
	$title = preg_replace( '/[<>]/', '', $title );
	$title = trim( $title );

	if ( mb_strlen( $title ) > MAX_TITLE_LENGTH ) {
		$title = mb_substr( $title, 0, MAX_TITLE_LENGTH );
	}

	return $title;
}

/** What it used to do, for comparison. */
function sanitize_title_before( $title ) {
	$title = preg_replace( '/[<>&"\'!@#*]/', '', $title );
	return trim( $title );
}
// -----------------------------------------------------------------------------

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-46s got %-34s want %s\n", $ok ? 'PASS' : 'FAIL', $label,
		var_export( $got, true ), var_export( $want, true ) );
}

echo "\nTITLES KEEP WHAT THE SELLER TYPED\n" . str_repeat( '=', 108 ) . "\n";

$cases = array(
	'Pokemon Base Set #4 Charizard'        => 'Pokemon Base Set #4 Charizard',
	"Levi's 501 Jeans"                     => "Levi's 501 Jeans",
	'NETGEAR GS524UP 24-Port PoE+ Switch'  => 'NETGEAR GS524UP 24-Port PoE+ Switch',
	'AT&T Fibre Modem'                     => 'AT&T Fibre Modem',
	'Cisco WS-C2960* Catalyst'             => 'Cisco WS-C2960* Catalyst',
	'RARE! 1st Edition Holo'               => 'RARE! 1st Edition Holo',
	'Email @ Sale - Bundle'                => 'Email @ Sale - Bundle',
);

foreach ( $cases as $in => $want ) {
	check( 'kept intact', sanitize_title( $in ), $want );
}

echo "\n  what the old cleaner did to those same titles:\n";
foreach ( array_keys( $cases ) as $in ) {
	$was = sanitize_title_before( $in );
	if ( $was !== $in ) {
		printf( "    %-38s -> %s\n", $in, $was );
	}
}

echo "\n  " . str_repeat( '-', 104 ) . "\n";

// Angle brackets still go - nothing legitimate has them and eBay treats
// markup in a title as a policy matter.
check( 'markup is still removed', sanitize_title( 'Switch <b>NEW</b>' ), 'Switch bNEW/b' );
check( 'the 80 character limit still applies',
	mb_strlen( sanitize_title( str_repeat( 'x', 200 ) ) ), 80 );
check( 'surrounding whitespace still trimmed', sanitize_title( '  Spaced  ' ), 'Spaced' );

// The character that made this worth fixing.
check( 'a card number survives', sanitize_title( 'Base Set #4' ), 'Base Set #4' );
check( '  and it did not before', sanitize_title_before( 'Base Set #4' ), 'Base Set 4' );

echo "\nCONDITION LABELS MATCH WHAT EBAY PUBLISHES\n" . str_repeat( '=', 108 ) . "\n";

// eBay's names, confirmed against two independent tables in the bundled
// reference plugin (ListingsPage::getConditionDisplayName and
// WooFrontendIntegration's $default_conditions).
$ebay = array(
	'1000' => 'New',
	'1500' => 'New other',
	'1750' => 'New with defects',
	'2000' => 'Manufacturer refurbished',
	'2500' => 'Seller refurbished',
	'3000' => 'Used',
	'4000' => 'Very Good',
	'5000' => 'Good',
	'6000' => 'Acceptable',
	'7000' => 'For parts or not working',
);

// The condition vocabulary moved to the Catalog class in Stage 1 of the edition
// split. Same constants, same values - sliced out of the exporter verbatim.
$src = file_get_contents( __DIR__ . '/../includes/class-tcgiant-sync-catalog.php' );
preg_match( "/const CONDITIONS = array\((.*?)\n\t\);/s", $src, $m );
preg_match_all( "/'(\d+)' => '([^']*)'/", $m[1], $rows, PREG_SET_ORDER );

$ours = array();
foreach ( $rows as $r ) {
	$ours[ $r[1] ] = $r[2];
}

printf( "  (%d conditions offered)\n", count( $ours ) );

// The three that were a rung out. Each must now start with eBay's own word.
foreach ( array( '3000' => 'Used', '4000' => 'Very Good', '5000' => 'Good' ) as $id => $word ) {
	check( sprintf( '%s now reads as eBay\'s "%s"', $id, $word ),
		isset( $ours[ $id ] ) && 0 === strpos( $ours[ $id ], $word ), true );
}

// No label may claim a grade eBay gives to a DIFFERENT id.
$mislabelled = array();
foreach ( $ours as $id => $label ) {
	$plain = trim( preg_replace( '/\s*\(.*\)$/', '', $label ) );
	foreach ( $ebay as $other_id => $other_name ) {
		if ( $other_id !== $id && strcasecmp( $plain, $other_name ) === 0 ) {
			$mislabelled[] = $id . ' is labelled "' . $plain . '", which eBay calls ' . $other_id;
		}
	}
}
check( 'no id borrows another id\'s name', $mislabelled, array() );

// A general seller needs these and had none of them.
foreach ( array( '1500', '1750', '2000', '2500', '7000' ) as $id ) {
	check( sprintf( '%s is now offered', $id ), isset( $ours[ $id ] ), true );
}

// The restricted ones must be separated, not merely labelled.
//
// Correcting the labels moved the words "Very Good" off 3000, which every
// category takes, onto 4000, which most refuse. A shop that had 3000 saved sees
// "Used" where it used to read "Very Good" and would reach for the familiar
// words again - swapping a working condition for one eBay throws out. The
// grouping is what prevents that, so it is what gets asserted.
preg_match( "/const CONDITIONS_MEDIA_ONLY = array\(([^)]*)\)/", $src, $mm );
preg_match_all( "/'(\d+)'/", $mm[1] ?? '', $media, PREG_SET_ORDER );
$media_ids = array_map( function ( $r ) { return $r[1]; }, $media );

check( 'the restricted grades are named as a set', $media_ids,
	array( '2750', '4000', '5000', '6000' ) );

check( 'the universally valid Used is NOT in that set',
	in_array( '3000', $media_ids, true ), false );

check( 'the settings field groups them under a heading',
	false !== strpos( file_get_contents( __DIR__ . '/../admin/views/settings.php' ),
		'Books, film, music and games only' ), true );

check( 'and offers the rest under one that says any category',
	false !== strpos( file_get_contents( __DIR__ . '/../admin/views/settings.php' ),
		'Any category' ), true );

// Every id must sit in exactly one group.
$ungrouped = array_values( array_diff( array_keys( $ours ), $media_ids ) );
check( 'every remaining id is a general one', count( $ungrouped ), 7 );

// And the warning against "fixing" the descriptor branch must be present.
check( 'the 2750/4000 double meaning is documented',
	false !== strpos( $src, 'Same numbers, different vocabulary.' ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
