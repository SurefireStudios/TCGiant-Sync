<?php
/**
 * Harness: a graded trading card's grade goes to eBay as eBay's value ID.
 *
 * Since 1.5.1 a graded card's grade went in ConditionDescriptor 27502 as it
 * reads - "8" for a PSA 8 - and eBay refuses every such listing: "Condition
 * descriptor value 8 is not valid for condition descriptor 27502" (found live,
 * Check with eBay on a PSA 8 card in category 183454). eBay wants its value
 * ID for the grade (275024 for 8), as the grader (27501) already went. The
 * certificate number (27503) is free text of at most 30 characters for cards;
 * coins keep their own descriptors and 20.
 *
 * Loaded for real: the exporter's item build (build_item_xml(), as Verify,
 * Check with eBay and a push build it) and the catalog, through the played
 * WordPress of the template harnesses; the template renderer for the grading
 * line.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

// What a push needs of the plugin's other classes, played, as in test-picture-hosting.php.
class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS = array();
	public static function restocked_after_website_sale( $product_id ) { return 0; }
	public static function listing_already_settled( $product_id, $item_id ) { return false; }
	public static function apply_ended_listing_stock( $product_id, $listed, $sold ) { return (int) wc_get_product( $product_id )->get_stock_quantity(); }
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}

/** eBay's table for descriptor 27502 (MIP user guide, condition descriptor IDs for trading cards), copied here rather than read from the catalog. */
$EBAY_GRADE_IDS = array(
	'10' => '275020', '9.5' => '275021', '9' => '275022', '8.5' => '275023', '8' => '275024',
	'7.5' => '275025', '7' => '275026', '6.5' => '275027', '6' => '275028', '5.5' => '275029',
	'5' => '2750210', '4.5' => '2750211', '4' => '2750212', '3.5' => '2750213', '3' => '2750214',
	'2.5' => '2750215', '2' => '2750216', '1.5' => '2750217', '1' => '2750218',
	'Authentic' => '2750219', 'Authentic Altered' => '2750220', 'Authentic - Trimmed' => '2750221', 'Authentic - Coloured' => '2750222',
);

/** The ConditionDescriptors an item carries, in order: Name => its Value or AdditionalInfo. */
function descriptors( $xml ) {
	preg_match_all( '#<ConditionDescriptor>\s*<Name>(.*?)</Name>\s*<(Value|AdditionalInfo)>(.*?)</\2>\s*</ConditionDescriptor>#s', (string) $xml, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $d ) {
		$out[ $d[1] ] = $d[3];
	}
	return $out;
}

/** The warnings logged from row $from on. */
function warnings_from( $from ) {
	$out = array();
	foreach ( array_slice( $GLOBALS['W']['log'], $from ) as $row ) {
		if ( 'warning' === $row[0] ) {
			$out[] = $row[1];
		}
	}
	return $out;
}

/** A graded item: product $id with these grading fields, in category $category. */
function graded( $id, $grade, $cert = '', $category = '183454', $grader = '275010' ) {
	$product = new WC_Product( $id, array( 'name' => 'Card ' . $id, 'description' => 'A graded card.' ) );
	foreach ( array( '_ebay_export_category_id' => $category, '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => $grader, '_ebay_export_grade_value' => $grade, '_ebay_export_cert_number' => $cert ) as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}

/** The item Verify and Check with eBay build: its descriptors, the warnings, the XML. */
function built( WC_Product $product ) {
	$from = count( $GLOBALS['W']['log'] );
	$xml  = item_xml( $product, push_settings( $product ) );
	return array( descriptors( $xml ), warnings_from( $from ), $xml );
}

/** The warning for a card grade with no eBay ID, word for word: a push's, or Verify's (3.28.5). */
function no_grade_id_warning( $product, $grade, $for = 'Push' ) {
	return $for . sprintf( ': the grade "%s" of WC #%d is not one eBay takes for a graded trading card, so it was not sent and eBay will ask for it. Choose the grade again in the product\'s eBay Listing tab.', $grade, $product );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA GRADED CARD'S GRADE GOES AS EBAY'S VALUE ID\n" . str_repeat( '=', 124 ) . "\n";

// The live case: a PSA 8 in CCG Individual Cards, through Check with eBay.
$psa8 = graded( 901, '8', '95422421' );
list( $d, $warned, $xml ) = built( $psa8 );
check( 'a PSA 8 in 183454: 27501 PSA, 27502 275024 (not "8"), 27503 its cert', $d, array( '27501' => '275010', '27502' => '275024', '27503' => '95422421' ) );
check( '  graded condition 2750, and nothing said', array( false !== strpos( $xml, '<ConditionID>2750</ConditionID>' ), $warned ), array( true, array() ) );
check( '  the ID goes only in its descriptor: not the title, specifics or description', substr_count( $xml, '275024' ), 1 );

// Every grade eBay lists, as the products and the settings hold it.
$got = array();
$n   = 910;
foreach ( $EBAY_GRADE_IDS as $label => $id ) {
	list( $d ) = built( graded( $n++, (string) $label ) );
	$got[ $label ] = $d['27502'] ?? '';
}
check( 'every grade of eBay\'s table goes as its ID: 10 to 1 by halves, and the four Authentic', $got, $EBAY_GRADE_IDS );
check( '  the catalog\'s map is eBay\'s table, entry for entry', TCGiant_Sync_Catalog::GRADE_IDS_TCG, $EBAY_GRADE_IDS );
check( '  and every grade the product tab and the settings offer for cards has an ID', array_values( array_diff( TCGiant_Sync_Catalog::GRADES_TCG, array_map( 'strval', array_keys( TCGiant_Sync_Catalog::GRADE_IDS_TCG ) ) ) ), array() );
check( '  the graders already go as eBay\'s IDs (PSA 275010, BGS 275013, CGC 275015)', array( TCGiant_Sync_Catalog::GRADERS_TCG['Professional Sports Authenticator (PSA)'], TCGiant_Sync_Catalog::GRADERS_TCG['Beckett Grading Services (BGS)'], TCGiant_Sync_Catalog::GRADERS_TCG['Certified Guaranty Company (CGC)'] ), array( '275010', '275013', '275015' ) );

// A stored value that is already eBay's ID goes as it is.
list( $d, $warned ) = built( graded( 940, '275024' ) );
check( 'a grade stored as eBay\'s ID already (275024): sent as it is, nothing said', array( $d['27502'] ?? '', $warned ), array( '275024', array() ) );
list( $d ) = built( graded( 941, '2750222' ) );
check( '  so too Authentic - Coloured\'s (2750222)', $d['27502'] ?? '', '2750222' );
list( $d ) = built( graded( 942, ' 9.5 ' ) );
check( '  a label with spaces round it reads as the label: 275021', $d['27502'] ?? '', '275021' );

// A grade with no ID: no 27502 at all, and one warning naming the product and the value.
list( $d, $warned, $xml ) = built( graded( 950, 'MS/PR 65' ) );
check( 'a coin\'s grade on a card (MS/PR 65): no 27502, the grader and the rest still sent', array( isset( $d['27502'] ), $d['27501'] ?? '', false !== strpos( $xml, 'MS/PR 65' ) ), array( false, '275010', false ) );
check( '  one warning, naming the product and the value, in Verify\'s words, as Verify built it', $warned, array( no_grade_id_warning( 950, 'MS/PR 65', 'Verify' ) ) );
list( $d, $warned ) = built( graded( 951, '11' ) );
check( '  a grade of 11: the same', array( isset( $d['27502'] ), $warned ), array( false, array( no_grade_id_warning( 951, '11', 'Verify' ) ) ) );
list( $d, $warned ) = built( graded( 952, '275099' ) );
check( '  a number shaped like an ID that is not one of eBay\'s: the same', array( isset( $d['27502'] ), $warned ), array( false, array( no_grade_id_warning( 952, '275099', 'Verify' ) ) ) );
list( $d, $warned ) = built( graded( 953, '' ) );
check( '  no grade at all: no 27502 and nothing said (the push refuses it first: "Grade (required for graded items)")', array( isset( $d['27502'] ), $warned ), array( false, array() ) );

// Said once a push: a relist builds its item twice.
$twice = graded( 954, 'MS/PR 65' );
$from  = count( $GLOBALS['W']['log'] );
$build = private_method( 'TCGiant_Sync_Exporter', 'build_item_xml' );
item_xml( $twice, push_settings( $twice ) );
$build->invoke( exporter(), $twice, push_settings( $twice ) );
check( 'built twice in one build, the warning is given once', warnings_from( $from ), array( no_grade_id_warning( 954, 'MS/PR 65', 'Verify' ) ) );
$from = count( $GLOBALS['W']['log'] );
item_xml( $twice, push_settings( $twice ) );
check( '  and again for the next push or Verify, which decides afresh', warnings_from( $from ), array( no_grade_id_warning( 954, 'MS/PR 65', 'Verify' ) ) );
$pushed = graded( 955, '11' );
$push   = private_method( 'TCGiant_Sync_Exporter', 'do_push' );
$from   = count( $GLOBALS['W']['log'] );
$push->invoke( exporter(), $pushed );
$push->invoke( exporter(), $pushed );
$sent = end( $GLOBALS['API']->calls );
check( '  two pushes of it (AddFixedPriceItem): said once each, and neither sends a 27502', array( warnings_from( $from ), (string) ( $sent[0] ?? '' ), false !== strpos( (string) ( $sent[1] ?? '' ), '<Name>27502</Name>' ) ), array( array( no_grade_id_warning( 955, '11' ), no_grade_id_warning( 955, '11' ) ), 'AddFixedPriceItem', false ) );
$ok_push = graded( 956, '9.5', 'PSA-12345678' );
$push->invoke( exporter(), $ok_push );
$sent = end( $GLOBALS['API']->calls );
check( '  a push of a PSA 9.5: AddFixedPriceItem sends 27502 275021', descriptors( (string) ( $sent[1] ?? '' ) ), array( '27501' => '275010', '27502' => '275021', '27503' => 'PSA-12345678' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE CERTIFICATE NUMBER: 30 CHARACTERS FOR CARDS, 20 FOR COINS\n" . str_repeat( '=', 124 ) . "\n";

$long_cert = str_repeat( '1234567890', 3 ) . 'ABCDE';
list( $d ) = built( graded( 960, '10', $long_cert ) );
check( 'a card\'s certificate number of 35 characters: 27503 gets the first 30', array( $d['27503'] ?? '', strlen( $d['27503'] ?? '' ) ), array( substr( $long_cert, 0, 30 ), 30 ) );
list( $d ) = built( graded( 961, '10', 'CGC-4012345001' ) );
check( '  a short one goes whole', $d['27503'] ?? '', 'CGC-4012345001' );

// Coins are as they were: their own descriptors, their own IDs, 20 characters.
$coin = graded( 970, 'MS/PR 65', 'PCGS-12345678901234567890', '253', TCGiant_Sync_Catalog::GRADERS_COINS['PCGS'] );
list( $d, $warned ) = built( $coin );
check( 'a graded coin: 1 PCGS (14), 3 MS/PR (18), 4 65 (55), 5 the first 20 of its cert; no card descriptor, nothing said', array( $d, $warned ), array( array( '1' => '14', '3' => '18', '4' => '55', '5' => 'PCGS-123456789012345' ), array() ) );
$specifics = private_method( 'TCGiant_Sync_Exporter', 'collect_configured_specifics' )->invoke( exporter(), push_settings( $coin ) );
check( '  its Grade item specific is the grade as it reads, MS/PR 65', $specifics['Grade'] ?? '', 'MS/PR 65' );

// The description's grading line shows what the descriptors send, the grade as it reads.
$tpl = make_template( array( 'html' => '{{#grading}}[{{grader}}|{{grade}}|{{cert}}]{{/grading}}' ) );
update_post_meta( 960, TCGiant_Sync_Templates::OVERRIDE_META, (string) $tpl );
update_post_meta( 970, TCGiant_Sync_Templates::OVERRIDE_META, (string) $tpl );
check( 'the grading line: the card\'s grade as it reads (10) and its cert\'s first 30', false !== strpos( described( wc_get_product( 960 ) )['html'], '[Professional Sports Authenticator (PSA)|10|' . substr( $long_cert, 0, 30 ) . ']' ), true );
check( '  and the coin\'s first 20, as before', false !== strpos( described( $coin )['html'], '[PCGS|MS/PR 65|PCGS-123456789012345]' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE ONE PLACE THAT SENDS 27502\n" . str_repeat( '=', 124 ) . "\n";

$sends = array();
foreach ( array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ), glob( dirname( __DIR__ ) . '/admin/*.php' ), glob( dirname( __DIR__ ) . '/admin/views/*.php' ) ) as $file ) {
	$n = substr_count( (string) file_get_contents( $file ), '<Name>27502</Name>' );
	if ( $n ) {
		$sends[ basename( $file ) ] = $n;
	}
}
check( 'only the exporter\'s descriptor builder writes 27502', $sends, array( 'class-tcgiant-sync-exporter.php' => 1 ) );

done();
