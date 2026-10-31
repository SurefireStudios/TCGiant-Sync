<?php
/**
 * Harness: the trading-card category IDs (3.28.1).
 *
 * Until 3.28.1 the category list on the product's eBay tab and in Settings
 * offered a category per card game, under IDs eBay US files elsewhere:
 * "Pokémon Individual Cards" was 180006, eBay's Desiccant & Moisture
 * Absorbers; "Disney Lorcana" 183446, Building Toys; "Non-Sport Trading Card
 * Games" 261068, Action Figures; three were no category at all, and "Trading
 * Cards" 2536 is a parent, not a leaf. The same IDs decided when the
 * trading-card ConditionDescriptors went. eBay's own table for those
 * descriptors names three categories, the leaf categories for single cards:
 * 183050, 183454 and 261328, and the game goes in the "Game" item specific.
 *
 * Checked here: the lists; a product or default saved with a retired ID
 * pushed to the category it was meant to be, said once a push, the stored
 * value left alone; a custom ID, or a retired ID the shop chose from eBay's
 * tree or typed as its own, untouched; descriptors only in the three
 * categories, whatever the Item Type, the ConditionID kept; each ungraded
 * card condition and each professional grader only where eBay's table takes
 * it; the mapping only for a shop on eBay US, or with no marketplace saved;
 * and the Settings screen and the product's eBay tab showing a stored
 * retired value as "(retired) ... → ...", and the tab's readiness line
 * saying what a push would leave out.
 *
 * After the 3.28.1 review: a grader or card condition eBay's table does not
 * list for the category is sent all the same, and said (the table lags
 * eBay's own data); a revise or relist of a live listing keeps the stored
 * ID, said, and only a new listing and Verify map it; a retired ID typed
 * into a custom box is never mapped; Verify says so in its own words.
 *
 * Loaded for real: the catalog, the exporter's settings, item build and
 * push; and, in runs of their own, the Settings view
 * (support/category-settings-run.php), and the product's eBay tab and the
 * products list (support/category-panel-run.php).
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

// What a push needs of the plugin's other classes, played, as in test-picture-hosting.php.
class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS         = array();
	const AUCTION_LISTED_KEY  = '_tcgiant_auction_listed';
	const AUCTION_FIGURE_KEYS = array();
	public static function __callStatic( $name, $args ) { return null; }
	public static function restocked_after_website_sale( $product_id ) { return 0; }
	public static $settled = array();
	public static function listing_already_settled( $product_id, $item_id ) { return isset( self::$settled[ $product_id . '|' . $item_id ] ); }
	public static function apply_ended_listing_stock( $product_id, $listed, $sold, $item = null ) {
		self::$settled[ $product_id . '|' . get_post_meta( $product_id, '_ebay_item_id', true ) ] = true;
		return (int) wc_get_product( $product_id )->get_stock_quantity();
	}
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id ) { return 'publish'; }
}

/** The ConditionDescriptors an item carries: Name => its Value or AdditionalInfo. */
function descriptors( $xml ) {
	preg_match_all( '#<ConditionDescriptor>\s*<Name>(.*?)</Name>\s*<(Value|AdditionalInfo)>(.*?)</\2>\s*</ConditionDescriptor>#s', (string) $xml, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $d ) {
		$out[ $d[1] ] = $d[3];
	}
	return $out;
}
function warnings_from( $from ) {
	$out = array();
	foreach ( array_slice( $GLOBALS['W']['log'], $from ) as $row ) {
		if ( 'warning' === $row[0] ) {
			$out[] = $row[1];
		}
	}
	return $out;
}
function category_of( $xml ) {
	return preg_match( '#<PrimaryCategory><CategoryID>(.*?)</CategoryID></PrimaryCategory>#', (string) $xml, $m ) ? $m[1] : null;
}
function condition_of( $xml ) {
	return preg_match( '#<ConditionID>(.*?)</ConditionID>#', (string) $xml, $m ) ? $m[1] : null;
}
/** A graded card's condition, which a push in a card category needs. */
const GRADED = array( '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '9' );

/** A card, product $id, with these export meta. */
function card( $id, array $meta ) {
	$product = new WC_Product( $id, array( 'name' => 'Card ' . $id, 'description' => 'A card.' ) );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
/** The item Verify and Check with eBay build: the XML, its warnings. */
function built( WC_Product $product ) {
	$from = count( $GLOBALS['W']['log'] );
	$xml  = item_xml( $product, push_settings( $product ) );
	return array( $xml, warnings_from( $from ) );
}
/** A push (do_push()): the item sent, the warnings. */
function pushed( WC_Product $product ) {
	$GLOBALS['API']->calls = array();
	$from                  = count( $GLOBALS['W']['log'] );
	private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	$sent = end( $GLOBALS['API']->calls );
	return array( (string) ( $sent[1] ?? '' ), warnings_from( $from ), (string) ( $sent[0] ?? '' ) );
}
function retired_warning( $product, $from, $to, $source = 'product', $for = 'Push' ) {
	return TCGiant_Sync_Exporter::retired_category_warning( $product, array( 'from' => $from, 'to' => $to, 'source' => $source ), $for );
}
/** A card linked to a fixed-price eBay listing, 1100000009NN, live or ended. */
function linked( $id, array $meta, $status = 'Active' ) {
	return card( $id, $meta + array( '_ebay_item_id' => '1100000009' . substr( (string) $id, -2 ), '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => $status ) );
}
/** A push refused, or not: its error code and message, the calls made, the warnings. */
function refused( WC_Product $product ) {
	$GLOBALS['API']->calls = array();
	$from                  = count( $GLOBALS['W']['log'] );
	$res                   = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	return array( is_wp_error( $res ) ? $res->get_error_code() : 'pushed', is_wp_error( $res ) ? $res->get_error_message() : '', array_column( $GLOBALS['API']->calls, 0 ), warnings_from( $from ) );
}
const CARD_WORDS   = 'eBay takes card grading and condition details only in 183454 CCG Individual Cards, 183050 Non-Sport Trading Card Singles and 261328 Sports Trading Card Singles: choose one of them with Browse eBay Categories, or set Item Type to All Other';
const REFUSED_CARD = 'Cannot push to eBay — missing required settings: eBay Category — ' . CARD_WORDS;
/** Every call the last push made: its name, and the category its item named. */
function calls_made() {
	return array_map( function ( $c ) { return array( $c[0], category_of( $c[1] ) ); }, $GLOBALS['API']->calls );
}
function settings_child( $mode ) {
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/category-settings-run.php' ) . ' ' . $mode . ' 2>&1', $out, $code );
	return array( $code, json_decode( (string) end( $out ), true ), implode( "\n", $out ) );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE LISTS\n" . str_repeat( '=', 124 ) . "\n";

$RETIRED = array( '2536' => '183454', '261068' => '183050', '180006' => '183454', '176055' => '183454', '69243' => '183454', '183446' => '183454', '185089' => '183454' );
check( 'the category list: the three single-card leaves, the coins, and the two entries', array_map( 'strval', array_keys( TCGiant_Sync_Catalog::CATEGORIES ) ), array( '', '183454', '183050', '261328', '253', '256', '3377', '4733', '18466', 'custom' ) );
check( '  under eBay\'s own names, CCG Individual Cards saying which games it is for', array( TCGiant_Sync_Catalog::CATEGORIES['183454'], TCGiant_Sync_Catalog::CATEGORIES['183050'], TCGiant_Sync_Catalog::CATEGORIES['261328'], TCGiant_Sync_Catalog::CATEGORIES[''], TCGiant_Sync_Catalog::CATEGORIES['custom'] ), array( 'CCG Individual Cards: Pokémon, Magic, Yu-Gi-Oh!, Lorcana, One Piece and other card games', 'Non-Sport Trading Card Singles', 'Sports Trading Card Singles', '— Select a default category —', 'Custom Category ID...' ) );
check( 'the trading-card descriptor categories: exactly 183050, 183454 and 261328', TCGiant_Sync_Exporter::DESCRIPTOR_CATEGORIES_TCG, array( '183050', '183454', '261328' ) );
check( '  the coins\' as they were', TCGiant_Sync_Exporter::DESCRIPTOR_CATEGORIES_COINS, array( '253', '256', '3377', '4733', '18466' ) );
check( 'the retired IDs and where each goes', TCGiant_Sync_Catalog::RETIRED_CATEGORIES, $RETIRED );
check( '  none of them is offered, or decides descriptors', array( array_values( array_intersect( array_keys( $RETIRED ), array_map( 'strval', array_keys( TCGiant_Sync_Catalog::CATEGORIES ) ) ) ), array_values( array_intersect( array_keys( $RETIRED ), TCGiant_Sync_Exporter::DESCRIPTOR_CATEGORIES_TCG ) ) ), array( array(), array() ) );
check( '  each has its old label', array_keys( TCGiant_Sync_Catalog::RETIRED_CATEGORY_LABELS ), array_keys( $RETIRED ) );
$tcg_src = '';
foreach ( array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ), glob( dirname( __DIR__ ) . '/admin/*.php' ), glob( dirname( __DIR__ ) . '/admin/views/*.php' ) ) as $tcg_file ) {
	if ( false === strpos( $tcg_file, 'class-tcgiant-sync-catalog.php' ) ) {
		$tcg_src .= (string) file_get_contents( $tcg_file );
	}
}
$tcg_left = array();
foreach ( array_keys( $RETIRED ) as $tcg_id ) {
	if ( preg_match( "/['\"]" . $tcg_id . "['\"]/", $tcg_src ) ) {
		$tcg_left[] = (string) $tcg_id;
	}
}
check( '  and no shipped code outside the catalog names one, but the card browser\'s root (2536, a parent, which is browsed, not listed in)', $tcg_left, array( '2536' ) );
check( '  that root is named as eBay names it', false !== strpos( $tcg_src, "sid='2536';trail=[{id:'2536',name:'Collectible Card Games'}];" ), true );
check( 'the ungraded card conditions, by eBay\'s table: where each is valid', TCGiant_Sync_Catalog::UNGRADED_TCG_CATEGORIES, array(
	'400010' => array( '183050', '183454', '261328' ),
	'400011' => array( '183050', '261328' ),
	'400012' => array( '183050', '261328' ),
	'400013' => array( '183050', '261328' ),
	'400015' => array( '183454' ),
	'400016' => array( '183454' ),
	'400017' => array( '183454' ),
) );
check( '  one entry for each condition offered', array_map( 'strval', array_keys( TCGiant_Sync_Catalog::UNGRADED_TCG_CATEGORIES ) ), array_map( 'strval', array_keys( TCGiant_Sync_Catalog::UNGRADED_TCG ) ) );
$tcg_graders = array();
foreach ( array( '275010', '275011', '275012', '275013', '275014', '275015', '275016', '275017', '275018', '275019', '2750110', '2750111', '2750112', '2750113', '2750114', '2750115', '2750116', '2750117', '2750118', '2750119', '2750120', '2750121', '2750122', '2750123' ) as $tcg_g ) {
	$tcg_graders[ $tcg_g ] = array( '183050', '183454', '261328' );
}
$tcg_graders['275014'] = array( '261328' );
foreach ( array( '2750111', '2750118', '2750119', '2750121', '2750122' ) as $tcg_g ) {
	$tcg_graders[ $tcg_g ] = array( '183454' );
}
check( 'the professional graders, by eBay\'s table: CSG in 261328 only; PCA, PCG, Ace, TCG and ARK in 183454 only; the rest in all three', TCGiant_Sync_Catalog::GRADER_TCG_CATEGORIES, $tcg_graders );
check( '  every grader offered is in it, but the six eBay\'s table does not list', array_values( array_diff( array_map( 'strval', TCGiant_Sync_Catalog::GRADERS_TCG ), array_map( 'strval', array_keys( TCGiant_Sync_Catalog::GRADER_TCG_CATEGORIES ) ) ) ), array( '2750124', '2750125', '2750126', '2750127', '2750128', '2750129' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PRODUCT OR DEFAULT SAVED WITH A RETIRED ID IS PUSHED TO THE CATEGORY IT WAS MEANT TO BE\n" . str_repeat( '=', 124 ) . "\n";

$tcg_pid = 700;
foreach ( $RETIRED as $tcg_from => $tcg_to ) {
	$tcg_pid++;
	$p = card( $tcg_pid, GRADED + array( '_ebay_export_category_id' => (string) $tcg_from, '_ebay_export_category_name' => TCGiant_Sync_Catalog::RETIRED_CATEGORY_LABELS[ $tcg_from ] ) );
	list( $xml, $warned, $call ) = pushed( $p );
	check( sprintf( 'saved on the product as %s: the push sends %s, with one warning naming the product and both', $tcg_from, $tcg_to ), array( $call, category_of( $xml ), $warned ), array( 'AddFixedPriceItem', $tcg_to, array( retired_warning( $tcg_pid, (string) $tcg_from, $tcg_to ) ) ) );
	check( '  the stored value is left as it is', get_post_meta( $tcg_pid, '_ebay_export_category_id', true ), (string) $tcg_from );
}
$p = card( 720, GRADED + array( '_ebay_export_category_id' => '180006' ) );
check( 'the warning, word for word', retired_warning( 720, '180006', '183454' ), 'Push: WC #720 is saved with eBay category 180006 ("Pokémon Individual Cards"), an ID TCGiant Sync\'s category list had wrong until 3.28.1, so the listing went to 183454, CCG Individual Cards, where eBay files those cards. Choose the category again on the product\'s eBay tab to stop this message.' );
$from  = count( $GLOBALS['W']['log'] );
$build = private_method( 'TCGiant_Sync_Exporter', 'build_item_xml' );
item_xml( $p, push_settings( $p ) );
$build->invoke( exporter(), $p, push_settings( $p ) );
check( 'built twice in one build of Verify\'s: said once, in Verify\'s words', warnings_from( $from ), array( retired_warning( 720, '180006', '183454', 'product', 'Verify' ) ) );
list( , $warned ) = built( $p );
check( '  and again for the next Verify, which decides afresh', $warned, array( retired_warning( 720, '180006', '183454', 'product', 'Verify' ) ) );
check( '  Verify\'s words, word for word: nothing went anywhere', retired_warning( 720, '180006', '183454', 'product', 'Verify' ), 'Verify: WC #720 is saved with eBay category 180006 ("Pokémon Individual Cards"), an ID TCGiant Sync\'s category list had wrong until 3.28.1, so the item was checked in 183454, CCG Individual Cards, where eBay files those cards and a new listing goes. Choose the category again on the product\'s eBay tab to stop this message.' );
list( , $warned ) = pushed( $p );
list( , $warned2 ) = pushed( $p );
check( '  two pushes: once each', array( $warned, $warned2 ), array( array( retired_warning( 720, '180006', '183454' ) ), array( retired_warning( 720, '180006', '183454' ) ) ) );
$s = push_settings( $p );
check( 'the settings a push reads say what was replaced, and from where', array( $s['category_id'], $s['category_retired'] ?? null ), array( '183454', array( 'from' => '180006', 'to' => '183454', 'source' => 'product' ) ) );

// The default in Settings.
$tcg_keep = TCGiant_Sync_OAuth::$settings;
TCGiant_Sync_OAuth::$settings['export_category_id'] = '261068';
$p = card( 721, GRADED );
list( $xml, $warned ) = pushed( $p );
check( 'the default saved as 261068 (Action Figures): the push sends 183050, said as the default\'s', array( category_of( $xml ), $warned ), array( '183050', array( retired_warning( 721, '261068', '183050', 'default' ) ) ) );
check( '  which says where to change it', false !== strpos( $warned[0] ?? '', 'Choose the category again on Settings, Push to eBay (the default eBay category)' ), true );
$p = card( 722, GRADED + array( '_ebay_export_category_id' => '261328' ) );
list( $xml, $warned ) = pushed( $p );
check( '  a product with its own category is not touched by it', array( category_of( $xml ), $warned ), array( '261328', array() ) );
TCGiant_Sync_OAuth::$settings = $tcg_keep;

// Anything else is the shop's.
$p = card( 723, array( '_ebay_export_category_id' => '183456', '_ebay_export_category_name' => 'Toys & Hobbies > Collectible Card Games > CCG Sealed Boxes' ) );
list( $xml, $warned ) = pushed( $p );
check( 'a custom category (CCG Sealed Boxes, 183456): sent as it is, nothing said', array( category_of( $xml ), $warned ), array( '183456', array() ) );
$p = card( 724, array( '_ebay_export_category_id' => '183446', '_ebay_export_category_name' => 'Toys & Hobbies > Building Toys' ) );
list( $xml, $warned ) = pushed( $p );
check( 'a retired ID chosen from eBay\'s own tree (Building Toys, by its path): the shop\'s custom choice, sent as it is', array( category_of( $xml ), $warned ), array( '183446', array() ) );
$p = card( 725, array( '_ebay_export_category_id' => '261068', '_ebay_export_category_name' => 'Action Figures' ) );
list( $xml ) = pushed( $p );
check( '  by its name alone, the same', category_of( $xml ), '261068' );
$p = card( 726, GRADED + array( '_ebay_export_category_id' => '183446', '_ebay_export_category_name' => 'Disney Lorcana' ) );
list( $xml ) = pushed( $p );
check( '  saved with the old list\'s label: mapped', category_of( $xml ), '183454' );
check( 'the helper: a retired ID, its eBay name in any case, another name, another ID', array( TCGiant_Sync_Catalog::retired_category( '180006' ), TCGiant_Sync_Catalog::retired_category( ' 180006 ' ), TCGiant_Sync_Catalog::retired_category( '180006', 'desiccant & moisture absorbers' ), TCGiant_Sync_Catalog::retired_category( '180006', 'Pokémon Individual Cards' ), TCGiant_Sync_Catalog::retired_category( '183454' ), TCGiant_Sync_Catalog::retired_category( '' ) ), array( '183454', '183454', '', '183454', '', '' ) );
check( '  176055 has no eBay name, so any name stored with it is the old list\'s', TCGiant_Sync_Catalog::retired_category( '176055', 'Magic' ), '183454' );
check( 'the label a retired value is shown with', TCGiant_Sync_Catalog::retired_category_label( '180006' ), '(retired) Pokémon Individual Cards (180006) → CCG Individual Cards (183454)' );
check( '  and 261068\'s', TCGiant_Sync_Catalog::retired_category_label( '261068' ), '(retired) Non-Sport Trading Card Games (261068) → Non-Sport Trading Card Singles (183050)' );

// The item specifics eBay requires are asked for the category a push sends.
$tcg_seen = array();
$API->aspects = array();
$tcg_api_class = get_class( $API );
$aspects = exporter()->describe_aspects( card( 727, array() ), array( 'category_id' => '69243', 'category_name' => '' ) );
check( 'Item Specifics asked about a retired ID on the screen: answered for 183454', $aspects['category_id'] ?? null, '183454' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA LIVE LISTING KEEPS ITS CATEGORY: A RETIRED ID IS MAPPED ONLY FOR A NEW LISTING, AND VERIFY\n" . str_repeat( '=', 124 ) . "\n";

$tcg_kept = array( 'from' => '180006', 'to' => '183454', 'source' => 'product' );
$p = linked( 950, GRADED + array( '_ebay_export_category_id' => '180006', '_ebay_export_category_name' => '' ) );
list( $xml, $warned, $call ) = pushed( $p );
check( 'saved as 180006, with a live listing: revised, the category sent as it is, one warning saying so', array( $call, category_of( $xml ), $warned ), array( 'ReviseFixedPriceItem', '180006', array( TCGiant_Sync_Exporter::kept_category_warning( 950, $tcg_kept, '110000000950' ) ) ) );
check( '  the warning, word for word', TCGiant_Sync_Exporter::kept_category_warning( 950, $tcg_kept, '110000000950' ), 'Push: WC #950 is saved with eBay category 180006 ("Pokémon Individual Cards"), an ID TCGiant Sync\'s category list had wrong until 3.28.1, and eBay US does not file single trading cards under it. Its eBay listing 110000000950 was revised in that category as it is: eBay does not move a live listing to another category once it has sales. To move it to 183454, CCG Individual Cards, end the listing and push the product again, which makes a new listing there.' );
check( '  the stored value as it was', get_post_meta( 950, '_ebay_export_category_id', true ), '180006' );
list( , $warned2 ) = pushed( $p );
check( '  the next push says it again, once', $warned2, array( TCGiant_Sync_Exporter::kept_category_warning( 950, $tcg_kept, '110000000950' ) ) );
list( $xml, $warned ) = built( $p );
check( 'Verify of the same product checks a new listing: 183454, in Verify\'s words', array( category_of( $xml ), $warned ), array( '183454', array( retired_warning( 950, '180006', '183454', 'product', 'Verify' ) ) ) );
$p = linked( 951, GRADED + array( '_ebay_export_item_type' => 'tcg', '_ebay_export_category_id' => '183446', '_ebay_export_category_name' => 'Disney Lorcana' ) );
list( $xml, $warned, $call ) = pushed( $p );
check( 'Item Type "Trading Cards", saved as 183446, a live listing: revised in 183446, so no card details, as the category gate says, and not refused (3.28.5 ruling)', array( $call, category_of( $xml ), condition_of( $xml ), descriptors( $xml ), $warned ), array( 'ReviseFixedPriceItem', '183446', '2750', array(), array( TCGiant_Sync_Exporter::card_category_warning( 951, '183446' ), TCGiant_Sync_Exporter::kept_category_warning( 951, array( 'from' => '183446', 'to' => '183454', 'source' => 'product' ), '110000000951' ) ) ) );
$p = linked( 958, GRADED + array( '_ebay_export_item_type' => 'tcg', '_ebay_export_category_id' => '183446', '_ebay_export_category_name' => 'Disney Lorcana' ), 'Ended' );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000958', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ) );
list( , $warned ) = pushed( $p );
$tcg_add = end( $API->calls );
check( '  the same with its listing known to have ended: not refused for it, as the new listing goes to 183454 with its card details', array( calls_made(), descriptors( $tcg_add[1] ) ), array( array( array( 'ReviseFixedPriceItem', '183446' ), array( 'GetItem', null ), array( 'AddFixedPriceItem', '183454' ) ), array( '27501' => '275010', '27502' => '275022' ) ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
$tcg_keep = TCGiant_Sync_OAuth::$settings;
TCGiant_Sync_OAuth::$settings['export_category_id'] = '261068';
list( $xml, $warned, $call ) = pushed( linked( 952, GRADED ) );
check( 'the default saved as 261068, a live listing, no category of the product\'s own: revised in 261068, said', array( $call, category_of( $xml ), $warned ), array( 'ReviseFixedPriceItem', '261068', array( TCGiant_Sync_Exporter::kept_category_warning( 952, array( 'from' => '261068', 'to' => '183050', 'source' => 'default' ), '110000000952' ) ) ) );
TCGiant_Sync_OAuth::$settings = $tcg_keep;
$p = linked( 953, GRADED + array( '_ebay_export_category_id' => '180006', '_ebay_export_category_name' => '' ), 'Ended' );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000953', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ) );
list( , $warned ) = pushed( $p );
check( 'its listing ended: the revise goes as it is, then the new listing in its place goes to 183454, said as a new listing\'s', array( calls_made(), $warned ), array( array( array( 'ReviseFixedPriceItem', '180006' ), array( 'GetItem', null ), array( 'AddFixedPriceItem', '183454' ) ), array( retired_warning( 953, '180006', '183454' ) ) ) );
unset( $API->answers['ReviseFixedPriceItem'] );
$p    = linked( 954, GRADED + array( '_ebay_export_category_id' => '180006', '_ebay_export_category_name' => '' ), 'Ended' );
$from = count( $GLOBALS['W']['log'] );
$API->calls              = array();
$API->answers['GetItem'] = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000954', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ) );
$tcg_relist = exporter()->relist_ended_listing( 954, 'Relist' );
check( 'relisted (Relist, Auto-Relist): RelistItem sends no category, so the listing stays in 180006, said with how to move it', array( is_wp_error( $tcg_relist ) ? $tcg_relist->get_error_message() : 'relisted', array_column( $API->calls, 0 ), warnings_from( $from ) ), array( 'relisted', array( 'GetItem', 'RelistFixedPriceItem' ), array( TCGiant_Sync_Exporter::kept_category_warning( 954, $tcg_kept, '110000000001', 'relist' ) ) ) );
check( '  the warning, word for word', TCGiant_Sync_Exporter::kept_category_warning( 954, $tcg_kept, '110000000001', 'relist' ), 'Relist: WC #954 is saved with eBay category 180006 ("Pokémon Individual Cards"), an ID TCGiant Sync\'s category list had wrong until 3.28.1, and eBay US does not file single trading cards under it. Its listing was relisted as 110000000001 in the category it was in, as a relist keeps it. To move it to 183454, CCG Individual Cards, end the listing and push the product again, which makes a new listing there.' );
linked( 955, GRADED + array( '_ebay_export_category_id' => '183454' ), 'Ended' );
$from = count( $GLOBALS['W']['log'] );
$API->answers['GetItem'] = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000955', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ) );
$tcg_relist = exporter()->relist_ended_listing( 955, 'Relist' );
check( '  one in CCG Individual Cards relisted: nothing said', array( is_wp_error( $tcg_relist ), warnings_from( $from ) ), array( false, array() ) );
unset( $API->answers['GetItem'] );
// The item specifics eBay requires are those of the category sent.
class Category_Aspects_API extends Template_World_API {
	public $aspects_by = array();
	public function get_category_aspects( $category_id ) { return $this->aspects_by[ (string) $category_id ] ?? array(); }
}
$tcg_api = $API;
$API     = new Category_Aspects_API();
$tcg_slot->setValue( null, $API );
$API->aspects_by['183454'] = array( array( 'name' => 'Game', 'required' => true ) );
list( , , $call ) = pushed( linked( 956, GRADED + array( '_ebay_export_category_id' => '180006', '_ebay_export_category_name' => '' ) ) );
$tcg_new = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), card( 957, GRADED + array( '_ebay_export_category_id' => '180006', '_ebay_export_category_name' => '' ) ) );
check( 'the item specifics eBay requires: the revise in 180006 is held to 180006\'s, a new listing in 183454 to its own (Game)', array( $call, is_wp_error( $tcg_new ) ? $tcg_new->get_error_code() : 'pushed' ), array( 'ReviseFixedPriceItem', 'missing_required_aspects' ) );
$API = $tcg_api;
$tcg_slot->setValue( null, $API );
check( 'the helper: a retired ID kept as it is, anything else untouched', array( TCGiant_Sync_Exporter::keep_listing_category( array( 'category_id' => '183454', 'category_retired' => $tcg_kept ) ), TCGiant_Sync_Exporter::keep_listing_category( array( 'category_id' => '183454' ) ) ), array( array( 'category_id' => '180006', 'category_kept' => $tcg_kept ), array( 'category_id' => '183454' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA RETIRED ID TYPED INTO A CUSTOM BOX IS THE SHOP'S OWN, NEVER MAPPED\n" . str_repeat( '=', 124 ) . "\n";

$p = card( 960, array( '_ebay_export_category_id' => '183446', '_ebay_export_category_name' => '', '_ebay_export_category_typed' => '183446' ) );
list( $xml, $warned ) = pushed( $p );
check( 'a product with 183446 typed into its custom box: sent as it is, nothing said', array( category_of( $xml ), $warned, isset( push_settings( $p )['category_retired'] ) ), array( '183446', array(), false ) );
$p = card( 961, GRADED + array( '_ebay_export_category_id' => '261068', '_ebay_export_category_name' => 'Toys & Hobbies > Building Toys', '_ebay_export_category_typed' => '183446' ) );
list( $xml, $warned ) = pushed( $p );
check( '  one typed before, and the old 261068 chosen from the list since: mapped, as a retired ID', array( category_of( $xml ), $warned ), array( '183050', array( retired_warning( 961, '261068', '183050' ) ) ) );
$tcg_keep = TCGiant_Sync_OAuth::$settings;
TCGiant_Sync_OAuth::$settings['export_category_id']    = '180006';
TCGiant_Sync_OAuth::$settings['export_category_typed'] = '180006';
list( $xml, $warned ) = pushed( card( 962, array() ) );
check( 'the default typed as 180006 into Settings\' box: sent as it is, nothing said', array( category_of( $xml ), $warned ), array( '180006', array() ) );
TCGiant_Sync_OAuth::$settings = $tcg_keep;
check( 'the helper: typed, typed with spaces, another ID typed', array( TCGiant_Sync_Catalog::retired_category( '183446', '', '183446' ), TCGiant_Sync_Catalog::retired_category( '183446', '', ' 183446 ' ), TCGiant_Sync_Catalog::retired_category( '183446', '', '180006' ) ), array( '', '', '183454' ) );
$aspects = exporter()->describe_aspects( card( 963, array() ), array( 'category_id' => '183446', 'category_name' => '', 'category_typed' => '183446' ) );
check( 'Item Specifics asked about 183446 typed on the screen: answered for 183446', $aspects['category_id'] ?? null, '183446' );
$tcg_admin = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' );
check( 'the eBay tab posts what is typed with Push, Verify and Item Specifics, and both saves and the answer keep it', array( substr_count( $tcg_admin, "override_category_id:catId,override_category_typed:(cs==='custom')?cc:''," ), substr_count( $tcg_admin, "'override_category_typed'     => '_ebay_export_category_typed'," ), substr_count( $tcg_admin, "'override_category_typed' => 'category_typed'," ) ), array( 3, 2, 1 ) );
check( '  and the default\'s is the Push to eBay form\'s own setting', false !== strpos( $tcg_admin, "'export_category_id', 'export_category_name', 'export_category_typed'," ), true );
$tcg_settings_src = (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/settings.php' );
check( '  Settings sets it on save, from the box, when the list is not what was chosen', false !== strpos( $tcg_settings_src, "$('#export_category_typed').val( ( sel === 'custom' || sel === '' ) ? $('#export_category_id_custom').val() : '' );" ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTRADING-CARD DESCRIPTORS ONLY IN THE THREE CATEGORIES\n" . str_repeat( '=', 124 ) . "\n";

$tcg_graded = array( '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '9' );
foreach ( array( '183050', '183454', '261328' ) as $i => $tcg_cat ) {
	list( $xml ) = built( card( 730 + $i, $tcg_graded + array( '_ebay_export_category_id' => $tcg_cat ) ) );
	check( 'a graded card in ' . $tcg_cat . ', no item type set: graded, with its descriptors', array( condition_of( $xml ), descriptors( $xml ) ), array( '2750', array( '27501' => '275010', '27502' => '275022' ) ) );
}
foreach ( array( '183456', '2536', '180006', '261068' ) as $i => $tcg_cat ) {
	check( 'the category ' . $tcg_cat . ' alone decides no descriptors', TCGiant_Sync_Exporter::is_descriptor_category( $tcg_cat, '' ), false );
}
list( $xml ) = built( card( 740, $tcg_graded + array( '_ebay_export_category_id' => '183456', '_ebay_export_condition_id' => '1000' ) ) );
check( 'a graded card in another category (CCG Sealed Boxes), no item type: no descriptors, its condition', array( condition_of( $xml ), descriptors( $xml ) ), array( '1000', array() ) );
list( $xml ) = built( card( 741, $tcg_graded + array( '_ebay_export_category_id' => '2536' ) ) );
check( 'a graded card saved in retired 2536: mapped to 183454, so its descriptors go', array( category_of( $xml ), condition_of( $xml ), descriptors( $xml ) ), array( '183454', '2750', array( '27501' => '275010', '27502' => '275022' ) ) );
check( 'Item Type "Trading Cards" still makes a product a descriptor one in any category, so its ConditionID is Graded or Ungraded', TCGiant_Sync_Exporter::is_descriptor_category( '183456', 'tcg' ), true );
$p = card( 742, $tcg_graded + array( '_ebay_export_item_type' => 'tcg', '_ebay_export_category_id' => '183456', '_ebay_export_cert_number' => 'C123' ) );
list( $xml, $warned ) = built( $p );
check( 'but graded in CCG Sealed Boxes (183456): Graded, no grader, grade or certification number, no empty container, one warning', array( condition_of( $xml ), descriptors( $xml ), strpos( $xml, 'ConditionDescriptors' ), $warned ), array( '2750', array(), false, array( TCGiant_Sync_Exporter::card_category_warning( 742, '183456', 'Verify' ) ) ) );
check( '  the warning, word for word', TCGiant_Sync_Exporter::card_category_warning( 742, '183456' ), 'Push: WC #742 is a trading card in eBay category 183456, and eBay takes card grading and condition details (grader, grade, certification number, card condition) only in 183454 CCG Individual Cards, 183050 Non-Sport Trading Card Singles and 261328 Sports Trading Card Singles. So none were sent, only its condition. Choose one of those categories on the product\'s eBay tab, or set its Item Type to All Other.' );
$from = count( $GLOBALS['W']['log'] );
item_xml( $p, push_settings( $p ) );
$build->invoke( exporter(), $p, push_settings( $p ) );
check( '  built twice in one push: said once', count( warnings_from( $from ) ), 1 );
check( '  pushed: refused before any call (3.28.5), as the eBay tab says it is not ready', refused( $p ), array( 'missing_export_settings', REFUSED_CARD, array(), array() ) );
$tcg_valid = exporter()->validate_export_settings( push_settings( $p ) );
check( '  and Verify and Bulk Verify refuse it, in the same words', is_wp_error( $tcg_valid ) ? $tcg_valid->get_error_data()['missing'] : $tcg_valid, array( 'eBay Category — ' . CARD_WORDS ) );
list( $xml, $warned ) = built( card( 743, $tcg_graded + array( '_ebay_export_item_type' => 'tcg', '_ebay_export_category_id' => '261328' ) ) );
check( 'Item Type "Trading Cards" in one of the three: its descriptors, nothing said', array( descriptors( $xml ), $warned ), array( array( '27501' => '275010', '27502' => '275022' ), array() ) );
list( $xml, $warned ) = built( card( 744, array( '_ebay_export_item_type' => 'coins', '_ebay_export_category_id' => '253', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '14', '_ebay_export_grade_value' => 'MS/PR 70' ) ) );
check( 'a graded coin (Item Type Coins, 253): its own descriptors, nothing said', array( condition_of( $xml ), descriptors( $xml ), $warned ), array( '2750', array( '1' => '14', '3' => '18', '4' => '32' ), array() ) );
check( 'the helper the eBay tab shares: a card anywhere, a card by category; not a coin, All Other, no condition type, a coin by its path', array(
	TCGiant_Sync_Exporter::takes_card_descriptors( array( 'item_type' => 'tcg', 'category_id' => '183456', 'condition_type' => 'graded' ) ),
	TCGiant_Sync_Exporter::takes_card_descriptors( array( 'item_type' => '', 'category_id' => '183454', 'condition_type' => 'ungraded' ) ),
	TCGiant_Sync_Exporter::takes_card_descriptors( array( 'item_type' => 'coins', 'category_id' => '253', 'condition_type' => 'graded' ) ),
	TCGiant_Sync_Exporter::takes_card_descriptors( array( 'item_type' => 'other', 'category_id' => '183454', 'condition_type' => 'graded' ) ),
	TCGiant_Sync_Exporter::takes_card_descriptors( array( 'item_type' => 'tcg', 'category_id' => '183454', 'condition_type' => '' ) ),
	TCGiant_Sync_Exporter::takes_card_descriptors( array( 'item_type' => 'tcg', 'category_id' => '526', 'category_name' => 'Coins & Paper Money > Coins: US > Mint Sets', 'condition_type' => 'graded' ) ),
), array( true, true, false, false, false, false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAN UNGRADED CARD CONDITION ONLY WHERE EBAY'S TABLE TAKES IT\n" . str_repeat( '=', 124 ) . "\n";

$tcg_pid = 750;
foreach ( TCGiant_Sync_Catalog::UNGRADED_TCG as $tcg_value => $tcg_label ) {
	foreach ( array( '183050', '183454', '261328' ) as $tcg_cat ) {
		$tcg_pid++;
		$valid = in_array( $tcg_cat, TCGiant_Sync_Catalog::UNGRADED_TCG_CATEGORIES[ (string) $tcg_value ], true );
		list( $xml, $warned ) = built( card( $tcg_pid, array( '_ebay_export_category_id' => $tcg_cat, '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => (string) $tcg_value ) ) );
		check(
			sprintf( '%s (%s) in %s: sent, %s', $tcg_label, $tcg_value, $tcg_cat, $valid ? 'nothing said' : 'and said once: eBay\'s table does not list it there' ),
			array( condition_of( $xml ), descriptors( $xml ), count( $warned ) ),
			array( '4000', array( '40001' => (string) $tcg_value ), $valid ? 0 : 1 )
		);
	}
}
$p = card( 790, array( '_ebay_export_category_id' => '183454', '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => '400011' ) );
list( , $warned ) = built( $p );
check( 'the warning, word for word', $warned, array( 'Verify: the card condition "Excellent" of WC #790 is not one eBay\'s table of trading-card conditions lists for category 183454, CCG Individual Cards, where it lists Near Mint or Better, Lightly Played (Excellent), Moderately Played (Very Good), Heavily Played (Poor). It was sent as it is: if eBay refuses it, choose the condition again in the product\'s eBay Listing tab.' ) );
$from = count( $GLOBALS['W']['log'] );
item_xml( $p, push_settings( $p ) );
$build->invoke( exporter(), $p, push_settings( $p ) );
check( '  built twice in one push: said once', count( warnings_from( $from ) ), 1 );
list( $xml, $warned ) = pushed( $p );
list( , $warned2 ) = pushed( $p );
check( '  two pushes: sent, and said once each', array( descriptors( $xml )['40001'] ?? null, count( $warned ), count( $warned2 ) ), array( '400011', 1, 1 ) );
list( $xml, $warned ) = built( card( 791, array( '_ebay_export_category_id' => '183456', '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => '400011' ) ) );
check( 'Item Type "Trading Cards", ungraded, in a category eBay\'s table does not cover: Ungraded, no card condition, the category\'s warning', array( condition_of( $xml ), descriptors( $xml ), $warned ), array( '4000', array(), array( TCGiant_Sync_Exporter::card_category_warning( 791, '183456', 'Verify' ) ) ) );
list( $xml, $warned ) = built( card( 792, array( '_ebay_export_category_id' => '253', '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => '7' ) ) );
check( 'a coin, ungraded: its own descriptor, as before', array( descriptors( $xml ), $warned ), array( array( '2' => '7' ), array() ) );
list( $xml, $warned ) = built( card( 793, array( '_ebay_export_category_id' => '69243', '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => '400016' ) ) );
check( 'a card saved in retired 69243, Moderately Played: mapped to 183454, where it is listed', array( category_of( $xml ), descriptors( $xml ), $warned ), array( '183454', array( '40001' => '400016' ), array( retired_warning( 793, '69243', '183454', 'product', 'Verify' ) ) ) );
check( 'the check itself, outside the three categories: valid', array( TCGiant_Sync_Exporter::ungraded_valid_in( '400011', '183456' ), TCGiant_Sync_Exporter::ungraded_valid_in( '999999', '183050' ) ), array( true, false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PROFESSIONAL GRADER ONLY WHERE EBAY'S TABLE TAKES IT\n" . str_repeat( '=', 124 ) . "\n";

$tcg_pid     = 800;
$tcg_wrong   = array();
$tcg_refused = 0;
$tcg_every   = TCGiant_Sync_Catalog::GRADERS_TCG + array( 'Certified Sports Guaranty (CSG)' => '275014', 'Professional Card Authenticator (PCA)' => '2750111', 'Premier Card Grading (PCG)' => '2750118', 'Trading Card Grading (TCG)' => '2750121', 'ARK Grading (ARK)' => '2750122' );
foreach ( $tcg_every as $tcg_name => $tcg_grader ) {
	foreach ( array( '183050', '183454', '261328' ) as $tcg_cat ) {
		$tcg_pid++;
		$tcg_listed = TCGiant_Sync_Catalog::GRADER_TCG_CATEGORIES[ (string) $tcg_grader ] ?? null;
		$valid      = null === $tcg_listed || in_array( $tcg_cat, $tcg_listed, true );
		list( $xml, $warned ) = built( card( $tcg_pid, array( '_ebay_export_category_id' => $tcg_cat, '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => (string) $tcg_grader, '_ebay_export_grade_value' => '9' ) ) );
		$got  = array( condition_of( $xml ), descriptors( $xml ), count( $warned ) );
		$want = array( '2750', array( '27501' => (string) $tcg_grader, '27502' => '275022' ), $valid ? 0 : 1 );
		if ( $got !== $want ) {
			$tcg_wrong[] = $tcg_name . ' in ' . $tcg_cat;
		}
		$tcg_refused += $valid ? 0 : 1;
	}
}
check( 'each of the 30 graders (the 25 offered, the 5 only eBay\'s table has) in each of the three: sent, and said once where the table does not list it', array( $tcg_wrong, $tcg_refused ), array( array(), 12 ) );
$p = card( 900, array( '_ebay_export_category_id' => '261328', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '2750119', '_ebay_export_grade_value' => '9' ) );
list( $xml, $warned ) = built( $p );
check( 'Ace in Sports Trading Card Singles: sent with the grade, and the warning word for word', array( descriptors( $xml ), $warned ), array( array( '27501' => '2750119', '27502' => '275022' ), array( 'Verify: the grader "Ace Grading (Ace)" of WC #900 is not one eBay\'s table of trading-card graders lists for category 261328, Sports Trading Card Singles; it lists it only in 183454 CCG Individual Cards. It was sent as it is: if eBay refuses it, choose the grader again in the product\'s eBay Listing tab.' ) ) );
$from = count( $GLOBALS['W']['log'] );
item_xml( $p, push_settings( $p ) );
$build->invoke( exporter(), $p, push_settings( $p ) );
check( '  built twice in one push: said once', count( warnings_from( $from ) ), 1 );
list( $xml, $warned ) = pushed( $p );
list( , $warned2 ) = pushed( $p );
check( '  pushed: the grader sent, and two pushes say it once each, in Push\'s words', array( descriptors( $xml )['27501'] ?? null, count( $warned ), count( $warned2 ), 0 === strpos( (string) ( $warned2[0] ?? '' ), 'Push: the grader "Ace Grading (Ace)"' ) ), array( '2750119', 1, 1, true ) );
list( , $warned ) = built( card( 901, array( '_ebay_export_category_id' => '183454', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275014', '_ebay_export_grade_value' => '9' ) ) );
check( 'CSG, which the plugin does not offer, saved in 183454: named by its ID, and where the table lists it', $warned, array( 'Verify: the grader "275014" of WC #901 is not one eBay\'s table of trading-card graders lists for category 183454, CCG Individual Cards; it lists it only in 261328 Sports Trading Card Singles. It was sent as it is: if eBay refuses it, choose the grader again in the product\'s eBay Listing tab.' ) );
list( $xml, $warned ) = built( card( 902, array( '_ebay_export_category_id' => '183050', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '2750124', '_ebay_export_grade_value' => '9' ) ) );
check( 'a grader eBay\'s table does not list (AGS, 2750124): sent as it is, nothing said', array( descriptors( $xml )['27501'] ?? null, $warned ), array( '2750124', array() ) );
check( 'the check itself: outside the three, and an unlisted grader, valid; Ace in 183454 and not 183050', array( TCGiant_Sync_Exporter::grader_valid_in( '2750119', '183456' ), TCGiant_Sync_Exporter::grader_valid_in( '2750129', '261328' ), TCGiant_Sync_Exporter::grader_valid_in( '2750119', '183454' ), TCGiant_Sync_Exporter::grader_valid_in( '2750119', '183050' ) ), array( true, true, true, false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE LOCAL CHECKS REFUSE WHAT THE EBAY TAB CALLS NOT READY, AND NOTHING ELSE (3.28.5)\n" . str_repeat( '=', 124 ) . "\n";

check( 'the words, shared: a card in another category; none in one of the three, for a coin, for All Other, or with no condition type', array(
	TCGiant_Sync_Exporter::card_category_problem( array( 'item_type' => 'tcg', 'category_id' => '183456', 'condition_type' => 'graded' ) ),
	TCGiant_Sync_Exporter::card_category_problem( array( 'item_type' => 'tcg', 'category_id' => '261328', 'condition_type' => 'ungraded' ) ),
	TCGiant_Sync_Exporter::card_category_problem( array( 'item_type' => 'coins', 'category_id' => '11980', 'condition_type' => 'graded' ) ),
	TCGiant_Sync_Exporter::card_category_problem( array( 'item_type' => 'other', 'category_id' => '183456', 'condition_type' => 'graded' ) ),
	TCGiant_Sync_Exporter::card_category_problem( array( 'item_type' => 'tcg', 'category_id' => '183456', 'condition_type' => '' ) ),
), array( CARD_WORDS, '', '', '', '' ) );
$p = card( 980, array( '_ebay_export_category_id' => '261328', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '2750119', '_ebay_export_grade_value' => '9' ) );
$tcg_r = refused( $p );
check( 'a grader eBay\'s table does not list for the category (Ace in 261328): passes, and is sent with its warning, as the tab warns', array( exporter()->validate_export_settings( push_settings( $p ) ), $tcg_r[0], $tcg_r[2], count( $tcg_r[3] ) ), array( true, 'pushed', array( 'AddFixedPriceItem' ), 1 ) );
$p = card( 981, array( '_ebay_export_category_id' => '183454', '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => '400011' ) );
check( '  an ungraded condition it does not list there (Excellent in 183454): the same', array( exporter()->validate_export_settings( push_settings( $p ) ), refused( $p )[2] ), array( true, array( 'AddFixedPriceItem' ) ) );

// Only where a new listing is made (the coordinator's ruling 3): a revise of
// a live listing goes as 3.28.4 sent it, without the details, said.
$p = linked( 986, GRADED + array( '_ebay_export_item_type' => 'tcg', '_ebay_export_category_id' => '183456' ) );
list( $xml, $warned, $call ) = pushed( $p );
check( 'a card in 183456 with a live listing: revised, without card details, with the category warning, not refused', array( $call, condition_of( $xml ), descriptors( $xml ), $warned ), array( 'ReviseFixedPriceItem', '2750', array(), array( TCGiant_Sync_Exporter::card_category_warning( 986, '183456' ) ) ) );
$p = linked( 987, GRADED + array( '_ebay_export_item_type' => 'tcg', '_ebay_export_category_id' => '183456' ) );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000987', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'ListingDetails' => array( 'EndTime' => '2026-10-20T08:00:00.000Z' ), 'Site' => 'US' ) );
$tcg_r = refused( $p );
check( '  its listing found ended: the new listing in its place refused before it is made', array( $tcg_r[0], $tcg_r[1], $tcg_r[2] ), array( 'missing_export_settings', REFUSED_CARD, array( 'ReviseFixedPriceItem', 'GetItem' ) ) );
check( '    and the ended listing\'s site kept, as when eBay refuses the add', get_post_meta( 987, '_ebay_site', true ), 'US' );
check( '    and the listing recorded as ended, with eBay\'s end time', array( get_post_meta( 987, '_ebay_listing_status', true ), get_post_meta( 987, '_ebay_end_time', true ), TCGiant_Sync_Exporter::pushes_new_listing( 987 ) ), array( 'Ended', '2026-10-20T08:00:00.000Z', true ) );
$tcg_r = refused( $p );
check( '    so the next push is refused before any call', array( $tcg_r[0], $tcg_r[2] ), array( 'missing_export_settings', array() ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
$tcg_valid = exporter()->validate_export_settings( push_settings( $p ), false );
check( '  the check itself: passes for a revise, refuses for a new listing (the default)', array( $tcg_valid, is_wp_error( exporter()->validate_export_settings( push_settings( $p ) ) ) ), array( true, true ) );
check( 'which a push makes: none, an ended one, an item number of "0", a live one', array( TCGiant_Sync_Exporter::pushes_new_listing( 742 ), TCGiant_Sync_Exporter::pushes_new_listing( 958 ), TCGiant_Sync_Exporter::pushes_new_listing( ( card( 989, array( '_ebay_item_id' => '0' ) ) )->get_id() ), TCGiant_Sync_Exporter::pushes_new_listing( 986 ) ), array( true, true, true, false ) );

// LOW 8: the three leaves are eBay UK's, Australia's and Canada's too; the
// mapping stays eBay US's, and the refusal applies there.
$tcg_keep = TCGiant_Sync_OAuth::$settings;
foreach ( array( 'EBAY_GB', 'EBAY_AU', 'EBAY_CA' ) as $i => $tcg_mp ) {
	TCGiant_Sync_OAuth::$settings['marketplace'] = $tcg_mp;
	$p = card( 982 + $i, GRADED + array( '_ebay_export_item_type' => 'tcg', '_ebay_export_category_id' => '180006', '_ebay_export_category_name' => '' ) );
	check( 'a shop on ' . $tcg_mp . ', a trading card saved as 180006: not mapped, and refused before any call, naming the three and Browse eBay Categories', refused( $p ), array( 'missing_export_settings', REFUSED_CARD, array(), array() ) );
}
$p = card( 985, GRADED + array( '_ebay_export_item_type' => 'tcg', '_ebay_export_category_id' => '183454' ) );
$tcg_r = refused( $p );
check( '  one saved as 183454 there: pushed, with its card details', array( $tcg_r[0], calls_made(), descriptors( $API->calls[0][1] ?? '' ) ), array( 'pushed', array( array( 'AddFixedPriceItem', '183454' ) ), array( '27501' => '275010', '27502' => '275022' ) ) );
// Ruling 2: only there. Elsewhere, where the three were not checked, as 3.28.4.
TCGiant_Sync_OAuth::$settings['marketplace'] = 'EBAY_DE';
$p = card( 988, GRADED + array( '_ebay_export_item_type' => 'tcg', '_ebay_export_category_id' => '180006', '_ebay_export_category_name' => '' ) );
$tcg_r = refused( $p );
check( 'a shop on eBay Germany, a card saved as 180006: not refused; listed without card details, with the category warning, as 3.28.4', array( $tcg_r[0], calls_made(), descriptors( $API->calls[0][1] ?? '' ), $tcg_r[3] ), array( 'pushed', array( array( 'AddFixedPriceItem', '180006' ) ), array(), array( TCGiant_Sync_Exporter::card_category_warning( 988, '180006' ) ) ) );
check( '  the helpers say so', array( TCGiant_Sync_Catalog::card_leaves_verified(), TCGiant_Sync_Exporter::card_category_problem( push_settings( $p ) ), exporter()->validate_export_settings( push_settings( $p ) ) ), array( false, '', true ) );
check( '  the sites where it applies', TCGiant_Sync_Catalog::CARD_LEAF_SITES, array( 'EBAY_US', 'EBAY_GB', 'EBAY_AU', 'EBAY_CA' ) );
TCGiant_Sync_OAuth::$settings['marketplace'] = '';
check( '  no marketplace saved (read as eBay US): it applies', TCGiant_Sync_Catalog::card_leaves_verified(), true );
TCGiant_Sync_OAuth::$settings = $tcg_keep;

// LOW 5: 2536 went to CCG Individual Cards, and sports cards do not belong there.
check( 'the default saved as 2536: the warning says where sports cards go, and that CSG is taken only there', retired_warning( 990, '2536', '183454', 'default' ), 'Push: WC #990 is saved with eBay category 2536 ("Trading Cards"), an ID TCGiant Sync\'s category list had wrong until 3.28.1, so the listing went to 183454, CCG Individual Cards, where eBay files those cards. Choose the category again on Settings, Push to eBay (the default eBay category) to stop this message. Sports cards belong in Sports Trading Card Singles (261328), the only one of the three single-card categories that takes CSG as a grader: choose that one for them.' );
$tcg_note = 'Sports cards belong in Sports Trading Card Singles (261328), the only one of the three single-card categories that takes CSG as a grader: choose that one for them.';
check( '  in Verify\'s words too; no other retired ID says it', array( substr( retired_warning( 990, '2536', '183454', 'product', 'Verify' ), -strlen( $tcg_note ) ) === $tcg_note, 0 === strpos( retired_warning( 990, '2536', '183454', 'product', 'Verify' ), 'Verify: ' ), false !== strpos( retired_warning( 990, '180006', '183454' ), 'Sports cards' ), false !== strpos( retired_warning( 990, '261068', '183050' ), 'Sports cards' ) ), array( true, true, false, false ) );
$tcg_keep = TCGiant_Sync_OAuth::$settings;
TCGiant_Sync_OAuth::$settings['export_category_id'] = '2536';
list( $xml, $warned ) = pushed( card( 991, GRADED ) );
check( '  a push with that default: still 183454, the warning with the note', array( category_of( $xml ), $warned ), array( '183454', array( retired_warning( 991, '2536', '183454', 'default' ) ) ) );
TCGiant_Sync_OAuth::$settings = $tcg_keep;

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE COIN CATEGORIES ARE PARENTS: REFUSED BEFORE ANY CALL (3.28.5)\n" . str_repeat( '=', 124 ) . "\n";

$tcg_coin = array( '_ebay_export_item_type' => 'coins', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '14', '_ebay_export_grade_value' => 'MS/PR 70' );
$tcg_got  = array();
foreach ( TCGiant_Sync_Catalog::COIN_PARENT_CATEGORIES as $tcg_id => $tcg_name ) {
	$tcg_got[ $tcg_id ] = refused( card( 1000 + (int) $tcg_id % 1000, $tcg_coin + array( '_ebay_export_category_id' => (string) $tcg_id ) ) );
}
check( 'each of the five, saved on the product: refused, naming it, before any call', array_map( function ( $r ) { return array( $r[0], $r[2] ); }, $tcg_got ), array_fill_keys( array( 253, 256, 3377, 4733, 18466 ), array( 'missing_export_settings', array() ) ) );
check( '  the words, word for word', $tcg_got[253][1], 'Cannot push to eBay — missing required settings: eBay Category — Coins: US (253) is a parent category on eBay, which takes only a bottom-level one: choose a subcategory of it with Browse eBay Categories' );
check( '  the list\'s coin entries are exactly these five', array_map( 'strval', array_keys( TCGiant_Sync_Catalog::COIN_PARENT_CATEGORIES ) ), array_values( array_intersect( array_map( 'strval', array_keys( TCGiant_Sync_Catalog::CATEGORIES ) ), TCGiant_Sync_Exporter::DESCRIPTOR_CATEGORIES_COINS ) ) );
$tcg_keep = TCGiant_Sync_OAuth::$settings;
TCGiant_Sync_OAuth::$settings['export_category_id'] = '3377';
$tcg_valid = exporter()->validate_export_settings( push_settings( card( 1010, $tcg_coin ) ) );
check( 'the default saved as 3377, the product with none of its own: Verify and Bulk Verify refuse it too', is_wp_error( $tcg_valid ) ? $tcg_valid->get_error_data()['missing'] : $tcg_valid, array( 'eBay Category — Coins: Canada (3377) is a parent category on eBay, which takes only a bottom-level one: choose a subcategory of it with Browse eBay Categories' ) );
TCGiant_Sync_OAuth::$settings = $tcg_keep;
$p = linked( 1013, $tcg_coin + array( '_ebay_export_category_id' => '253' ) );
check( 'a coin parent with a live listing: revised as before, not refused (ruling 3)', refused( $p )[2], array( 'ReviseFixedPriceItem' ) );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000913', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ) );
$tcg_r = refused( $p );
check( '  its listing found ended: the new listing refused before it is made', array( $tcg_r[0], $tcg_r[2] ), array( 'missing_export_settings', array( 'ReviseFixedPriceItem', 'GetItem' ) ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
$p = card( 1011, $tcg_coin + array( '_ebay_export_category_id' => '11980', '_ebay_export_category_name' => 'Coins & Paper Money > Coins: US > Small Cents' ) );
$tcg_r = refused( $p );
check( 'a coin leaf, chosen with Browse eBay Categories: pushed, with its coin details', array( $tcg_r[0], calls_made(), descriptors( $API->calls[0][1] ?? '' ) ), array( 'pushed', array( array( 'AddFixedPriceItem', '11980' ) ), array( '1' => '14', '3' => '18', '4' => '32' ) ) );
$tcg_keep = TCGiant_Sync_OAuth::$settings;
TCGiant_Sync_OAuth::$settings['marketplace'] = 'EBAY_GB';
check( 'a shop on eBay UK, saved as 253: not judged, the IDs being eBay US\'s', array( TCGiant_Sync_Catalog::coin_parent_problem( '253' ), refused( card( 1012, $tcg_coin + array( '_ebay_export_category_id' => '253' ) ) )[2] ), array( '', array( 'AddFixedPriceItem' ) ) );
TCGiant_Sync_OAuth::$settings = $tcg_keep;
check( 'the helper: a parent, with spaces; a leaf; a card leaf; nothing', array( TCGiant_Sync_Catalog::coin_parent_problem( ' 18466 ' ), TCGiant_Sync_Catalog::coin_parent_problem( '11980' ), TCGiant_Sync_Catalog::coin_parent_problem( '183454' ), TCGiant_Sync_Catalog::coin_parent_problem( '' ) ), array( 'Coins: Medieval (18466) is a parent category on eBay, which takes only a bottom-level one: choose a subcategory of it with Browse eBay Categories', '', '', '' ) );
check( 'the path helper: the parent named second in eBay\'s path, in any case; not a name that only begins the same; not the parent itself; not one further down a path; not a non-string', array(
	TCGiant_Sync_Catalog::coin_parent_in_path( 'Coins & Paper Money:Coins: US:Small Cents:Lincoln (1959-Now)' ),
	TCGiant_Sync_Catalog::coin_parent_in_path( 'coins & paper money:coins: canada:Dollars' ),
	TCGiant_Sync_Catalog::coin_parent_in_path( 'Coins & Paper Money:Coins: USA:Dollars' ),
	TCGiant_Sync_Catalog::coin_parent_in_path( 'Coins & Paper Money:Coins: US' ),
	TCGiant_Sync_Catalog::coin_parent_in_path( 'Stamps:Coins & Paper Money:Coins: US:Dollars' ),
	TCGiant_Sync_Catalog::coin_parent_in_path( 'Toys & Hobbies:Collectible Card Games:CCG Individual Cards' ),
	TCGiant_Sync_Catalog::coin_parent_in_path( array( 'Coins & Paper Money:Coins: US:Dollars' ) ),
), array( '253', '3377', '', '', '', '', '' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEVERY ITEM A PUSH BUILDS IS MEASURED BEFORE IT IS SENT (3.28.5)\n" . str_repeat( '=', 124 ) . "\n";

// eBay, answering GetItem by the item asked about.
class Size_Gate_API extends Template_World_API {
	public $items = array();
	public $ended = array();
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		if ( 'ReviseFixedPriceItem' === $call_name && preg_match( '#<ItemID>(\d+)</ItemID>#', $xml_body, $m ) && isset( $this->ended[ $m[1] ] ) ) {
			$this->calls[] = array( $call_name, $xml_body );
			return new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
		}
		if ( 'GetItem' === $call_name && preg_match( '#<ItemID>(\d+)</ItemID>#', $xml_body, $m ) && isset( $this->items[ $m[1] ] ) ) {
			$this->calls[] = array( $call_name, $xml_body );
			return array( 'Ack' => 'Success', 'Item' => $this->items[ $m[1] ] );
		}
		return parent::trading_api_request( $call_name, $xml_body, $allow_retry, $max_body_bytes );
	}
}
// What following a relist reads of the import's rules: no SKU of another item.
if ( ! class_exists( 'TCGiant_Sync_Mapper', false ) ) {
	class TCGiant_Sync_Mapper {
		public static function instance() { return new self(); }
		public function map_ebay_to_woo( $item ) { return array(); }
		public function other_goods_sku( ...$args ) { return ''; }
		public function goods_sku( ...$args ) { return ''; }
	}
}
$tcg_api = $API;
$API     = new Size_Gate_API();
$tcg_slot->setValue( null, $API );
$API->ended = array( '110000000970' => true, '110000000971' => true );
$tcg_tpl = (string) make_template( array( 'html' => '{{{description}}}' ) );
// The description goes over eBay's ceiling from the push's second build on.
$GLOBALS['tcg_builds'] = 0;
$tcg_long = function ( $html ) {
	$GLOBALS['tcg_builds']++;
	return $GLOBALS['tcg_builds'] >= 2 ? str_repeat( 'a', 500001 ) : $html;
};
add_filter( 'tcgiant_sync_ebay_description', $tcg_long );

// The ended listing's own sales brought into the stock: built again, then
// checked a second time and found settled, so nothing builds after.
$p = linked( 970, GRADED + array( '_ebay_export_category_id' => '183454', TCGiant_Sync_Templates::OVERRIDE_META => $tcg_tpl ) );
$API->items['110000000970'] = array( 'ItemID' => '110000000970', 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => '1', 'ListingStatus' => 'Completed' ), 'Site' => 'US' );
$GLOBALS['tcg_builds'] = 0;
$tcg_r = refused( $p );
check( 'its listing ended with a sale, the item built again from the stock that leaves: over 500,000 characters, refused, nothing listed', array( $tcg_r[0], $tcg_r[2], $GLOBALS['tcg_builds'] ), array( 'description_too_long', array( 'ReviseFixedPriceItem', 'GetItem' ), 2 ) );

// Its listing relisted on eBay, live: built again for that listing.
$p = linked( 971, GRADED + array( '_ebay_export_category_id' => '183454', TCGiant_Sync_Templates::OVERRIDE_META => $tcg_tpl ) );
$API->items['110000000971'] = array( 'ItemID' => '110000000971', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'ListingDetails' => array( 'RelistedItemID' => '110000000972' ), 'Site' => 'US' );
$API->items['110000000972'] = array( 'ItemID' => '110000000972', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Active' ), 'Site' => 'US' );
$GLOBALS['tcg_builds'] = 0;
$tcg_r = refused( $p );
check( 'its listing relisted on eBay, the item built again for the relist: over 500,000 characters, refused, the relist not revised', array( $tcg_r[0], $tcg_r[2], $GLOBALS['tcg_builds'] ), array( 'description_too_long', array( 'ReviseFixedPriceItem', 'GetItem', 'GetItem' ), 2 ) );

// Under the ceiling every time: both go ahead, as before.
remove_filter( 'tcgiant_sync_ebay_description', $tcg_long );
TCGiant_Sync_Inventory::$settled = array();
$tcg_r1 = refused( wc_get_product( 970 ) );
$tcg_r2 = refused( $p );
check( '  under it: the new listing made, and the relist revised', array( $tcg_r1[0], $tcg_r1[2], $tcg_r2[0], $tcg_r2[2] ), array( 'pushed', array( 'ReviseFixedPriceItem', 'GetItem', 'AddFixedPriceItem' ), 'pushed', array( 'ReviseFixedPriceItem', 'GetItem', 'GetItem', 'ReviseFixedPriceItem' ) ) );
$API = $tcg_api;
$tcg_slot->setValue( null, $API );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE MAPPING IS EBAY US'S: ONLY FOR A SHOP ON EBAY US, OR WITH NO MARKETPLACE SAVED\n" . str_repeat( '=', 124 ) . "\n";

$tcg_keep = TCGiant_Sync_OAuth::$settings;
foreach ( array( 'EBAY_GB', 'EBAY_DE', 'EBAY_AU' ) as $i => $tcg_mp ) {
	TCGiant_Sync_OAuth::$settings['marketplace'] = $tcg_mp;
	$p = card( 920 + $i, GRADED + array( '_ebay_export_category_id' => '180006', '_ebay_export_category_name' => '' ) );
	list( $xml, $warned ) = pushed( $p );
	check( 'a shop on ' . $tcg_mp . ', a product saved as 180006: sent as it is, nothing said, the settings unmarked', array( category_of( $xml ), $warned, isset( push_settings( $p )['category_retired'] ) ), array( '180006', array(), false ) );
}
check( '  the helpers say so', array( TCGiant_Sync_Catalog::maps_retired_categories(), TCGiant_Sync_Catalog::retired_category( '180006' ) ), array( false, '' ) );
TCGiant_Sync_OAuth::$settings['export_category_id'] = '2536';
list( $xml, $warned ) = pushed( card( 924, GRADED ) );
check( '  the default saved as 2536 there: sent as it is', array( category_of( $xml ), $warned ), array( '2536', array() ) );
$aspects = exporter()->describe_aspects( card( 925, array() ), array( 'category_id' => '69243', 'category_name' => '' ) );
check( '  Item Specifics asked about 69243 there: answered for 69243', $aspects['category_id'] ?? null, '69243' );
TCGiant_Sync_OAuth::$settings = $tcg_keep;
unset( TCGiant_Sync_OAuth::$settings['marketplace'] );
check( 'no marketplace saved (every call reads it as eBay US): mapped', array( TCGiant_Sync_Catalog::maps_retired_categories(), TCGiant_Sync_Catalog::retired_category( '180006' ) ), array( true, '183454' ) );
TCGiant_Sync_OAuth::$settings['marketplace'] = '';
$p = card( 926, GRADED + array( '_ebay_export_category_id' => '180006' ) );
list( $xml, $warned ) = pushed( $p );
check( '  saved blank: mapped, and said', array( category_of( $xml ), $warned ), array( '183454', array( retired_warning( 926, '180006', '183454' ) ) ) );
TCGiant_Sync_OAuth::$settings = $tcg_keep;

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SETTINGS SCREEN SHOWS A RETIRED VALUE AS WHAT IT IS (A CHILD RUN)\n" . str_repeat( '=', 124 ) . "\n";

list( $tcg_code, $tcg_child, $tcg_raw ) = settings_child( 'plain' );
$page = (string) ( $tcg_child['page'] ?? '' );
check( 'the Settings screen drew', array( $tcg_code, false !== strpos( $page, 'id="export_category_select"' ) ), array( 0, true ) );
check( 'the default saved as 180006: "(retired) Pokémon Individual Cards (180006) → CCG Individual Cards (183454)", chosen', 1 === preg_match( '#<option value="180006"\s*selected="selected">\s*\(retired\) Pokémon Individual Cards \(180006\) → CCG Individual Cards \(183454\)\s*</option>#u', $page ), true );
check( '  not a blank custom entry: the custom field is hidden', 1 === preg_match( '#id="export_category_id_custom"[^>]*value="180006"[^>]*style="display:none;"#s', $page ), true );
check( '  with a line saying why', false !== strpos( $page, 'The default saved here, Pokémon Individual Cards (180006), was wrong in TCGiant Sync&#039;s list until 3.28.1' ), true );
check( '  the three single-card categories are offered', array( substr_count( $page, '<option value="183454"' ) >= 1, substr_count( $page, '<option value="183050"' ) >= 1, substr_count( $page, '<option value="261328"' ) >= 1 ), array( true, true, true ) );
check( 'the import filter keeps a ticked retired ID, ticked, as what it is', 1 === preg_match( '#value="69243" checked>\s*\(retired\) Yu-Gi-Oh! Individual Cards \(69243\) → CCG Individual Cards \(183454\)#u', $page ), true );
check( '  with a line saying it imports none', false !== strpos( $page, 'no card listing is filed under it, so it imports none.' ), true );
check( '  and one for each box, naming the category to tick in its place (3.28.5): 261068\'s is Non-Sport Trading Card Singles', array( false !== strpos( $page, 'Yu-Gi-Oh! Individual Cards (69243): tick CCG Individual Cards (183454) instead, and untick this one.' ), false !== strpos( $page, 'Non-Sport Trading Card Games (261068): tick Non-Sport Trading Card Singles (183050) instead, and untick this one.' ), false !== strpos( $page, 'Tick the category its label points to' ) ), array( true, true, false ) );
check( '  the default\'s typed field, empty: 180006 was chosen from the list', 1 === preg_match( '#<input type="hidden" id="export_category_typed" name="tcgiant_sync_ebay_settings\[export_category_typed\]" value="">#', $page ), true );
check( '  and the valid one stays ticked', 1 === preg_match( '#value="183454"\s*checked="checked">#', $page ), true );

list( $tcg_code, $tcg_child ) = settings_child( 'custom' );
$page = (string) ( $tcg_child['page'] ?? '' );
check( 'a retired ID the shop typed as one of its custom categories: its own entry, no "(retired)"', array( 1 === preg_match( '#<option value="180006"\s*selected="selected">\s*My desiccant packs \(180006\)#', $page ), false !== strpos( $page, '(retired) Pokémon' ) ), array( true, false ) );

list( $tcg_code, $tcg_child ) = settings_child( 'sports' );
check( 'the default saved as 2536: Settings\' line says where sports cards go (3.28.5)', array( $tcg_code, false !== strpos( (string) ( $tcg_child['page'] ?? '' ), 'The default saved here, Trading Cards (2536), was wrong in TCGiant Sync&#039;s list until 3.28.1: eBay does not file trading cards under 2536. Pushes send CCG Individual Cards (183454) instead, where eBay files those cards, and the log says so. Choose a category here to replace it. Sports cards belong in Sports Trading Card Singles (261328), the only one of the three single-card categories that takes CSG as a grader: choose that one for them.' ) ), array( 0, true ) );
list( $tcg_code, $tcg_child ) = settings_child( 'typed' );
$page = (string) ( $tcg_child['page'] ?? '' );
preg_match( '#<select class="tc-select" id="export_category_select".*?</select>#s', $page, $tcg_select );
$tcg_select = $tcg_select[0] ?? '';
check( 'the default typed as 180006 into Settings\' box: a custom ID of its own, no "(retired)" entry, no line, kept as typed', array( $tcg_code, 1 === preg_match( '#<option value="custom"\s*selected="selected">#', $tcg_select ), false !== strpos( $tcg_select, 'value="180006"' ), false !== strpos( $page, 'The default saved here' ), 1 === preg_match( '#id="export_category_typed" name="tcgiant_sync_ebay_settings\[export_category_typed\]" value="180006"#', $page ) ), array( 0, true, false, false, true ) );

list( $tcg_code, $tcg_child ) = settings_child( 'gb' );
$page = (string) ( $tcg_child['page'] ?? '' );
preg_match( '#<select class="tc-select" id="export_category_select".*?</select>#s', $page, $tcg_select );
$tcg_select = $tcg_select[0] ?? '';
check( 'a shop on eBay UK, the default saved as 180006: a custom ID of its own, no "(retired)" entry, no line', array( $tcg_code, 1 === preg_match( '#<option value="custom"\s*selected="selected">#', $tcg_select ), false !== strpos( $tcg_select, 'value="180006"' ), false !== strpos( $page, 'The default saved here' ) ), array( 0, true, false, false ) );
check( '  the import filter as it is: the ticked box kept', 1 === preg_match( '#value="69243" checked>#', $page ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PRODUCT'S EBAY TAB AND THE PRODUCTS LIST, RENDERED (A RUN OF ITS OWN)\n" . str_repeat( '=', 124 ) . "\n";

exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/category-panel-run.php' ) . ' 2>&1', $tcg_out, $tcg_code );
$tab = json_decode( (string) end( $tcg_out ), true );
check( 'the tab rendered', array( $tcg_code, is_array( $tab ) ), array( 0, true ) );
$r = $tab['retired'] ?? array();
check( 'saved as 180006: the dropdown shows "(retired) Pokémon Individual Cards (180006) → CCG Individual Cards (183454)", chosen', array( $r['options']['180006'] ?? null, $r['selected'] ?? null ), array( '(retired) Pokémon Individual Cards (180006) → CCG Individual Cards (183454)', '180006' ) );
check( '  not a blank custom entry: the custom field hidden', $r['custom_hidden'] ?? null, true );
check( '  a line saying the saved category is replaced, and why', $r['line'] ?? null, 'The category saved for this product, Pokémon Individual Cards (180006), was wrong in TCGiant Sync\'s list until 3.28.1: eBay does not file trading cards under 180006. Pushes send CCG Individual Cards (183454) instead, where eBay files those cards, and the log says so. Choose a category here to replace it.' );
check( '  the readiness line names what is sent', $r['readiness'][0] ?? null, '✔ Category: CCG Individual Cards (183454), in place of the retired 180006' );
check( '  the other options are the list\'s, with no other retired one', array_keys( array_diff_key( $r['options'] ?? array(), array( '180006' => 1 ) ) ), array_keys( TCGiant_Sync_Catalog::CATEGORIES ) );
$b = $tab['browsed'] ?? array();
check( 'a retired ID chosen from eBay\'s tree (Building Toys): a custom category, as before, with no line', array( $b['selected'] ?? null, $b['custom_hidden'] ?? null, $b['line'] ?? null, isset( $b['options']['183446'] ) ), array( 'custom', false, '', false ) );
$c = $tab['custom'] ?? array();
check( 'a custom category (CCG Sealed Boxes): custom, its readiness line as before', array( $c['selected'] ?? null, $c['line'] ?? null, $c['readiness'][0] ?? null ), array( 'custom', '', '✔ Category: Toys & Hobbies > Collectible Card Games > CCG Sealed Boxes (183456)' ) );
check( '  Item Type "Trading Cards", graded, there: the condition not ready, in the push\'s own words', $c['readiness'][1] ?? null, '✘ Condition: Graded - Professional Sports Authenticator (PSA) 9 - ' . CARD_WORDS );
check( 'Ace in Sports Trading Card Singles: a warning, not "not ready": eBay\'s table does not list it there, it is sent as it is', $tab['grader']['readiness'][1] ?? null, '● Condition: Graded - Ace Grading (Ace) 9 - eBay\'s table of card graders does not list it for this category: sent as it is, eBay may refuse it' );
check( '  Ace in CCG Individual Cards: ready', $tab['grader_ok']['readiness'][1] ?? null, '✔ Condition: Graded - Ace Grading (Ace) 9' );
check( '  the retired 180006, mapped to 183454, graded by PSA: ready', $r['readiness'][1] ?? null, '✔ Condition: Graded - Professional Sports Authenticator (PSA) 9' );
$g = $tab['gb'] ?? array();
check( 'a shop on eBay UK, a product saved as 180006: its custom ID, no "(retired)" option, no line', array( $g['selected'] ?? null, $g['custom_hidden'] ?? null, isset( $g['options']['180006'] ), $g['line'] ?? null, $g['readiness'][0] ?? null ), array( 'custom', false, false, '', '✔ Category: 180006' ) );
$d = $tab['default'] ?? array();
check( 'the default saved as 2536, the product with none of its own: the line says so, and where to change it', $d['line'] ?? null, 'The category saved as the default in Settings, Trading Cards (2536), was wrong in TCGiant Sync\'s list until 3.28.1: eBay does not file trading cards under 2536. Pushes send CCG Individual Cards (183454) instead, where eBay files those cards, and the log says so. Choose one here for this product, or the default again in Settings. Sports cards belong in Sports Trading Card Singles (261328), the only one of the three single-card categories that takes CSG as a grader: choose that one for them.' );
check( '  the product\'s own choice stays on the default, and no retired option is added', array( $d['selected'] ?? null, isset( $d['options']['2536'] ) ), array( '', false ) );
$u = $tab['ungraded'] ?? array();
check( 'Very Good, ungraded, in CCG Individual Cards: a warning on the readiness line, as the table does not list it there', $u['readiness'][1] ?? null, '● Condition: Ungraded - Very Good - eBay\'s table of card conditions does not list it for this category: sent as it is, eBay may refuse it' );
$l = $tab['linked'] ?? array();
check( 'saved as 180006, with a live listing: the line says the listing stays there, and how to move it', $l['line'] ?? null, 'The category saved for this product, Pokémon Individual Cards (180006), was wrong in TCGiant Sync\'s list until 3.28.1: eBay does not file trading cards under 180006. Its eBay listing 110000000509 stays there, as eBay does not move a live listing to another category once it has sales, and a push revises it as it is. To move it to CCG Individual Cards (183454), end the listing and push again: the new listing goes there.' );
check( '  the readiness line: the category as a warning, and the card details by the category sent', array( $l['readiness'][0] ?? null, $l['readiness'][1] ?? null ), array( '● Category: Pokémon Individual Cards (180006) - its eBay listing stays in this category, which eBay US does not file single trading cards under: to move it to CCG Individual Cards (183454), end the listing and push again', '● Condition: Graded - Professional Sports Authenticator (PSA) 9 - sent without card details: this category takes none' ) );
$e = $tab['linked_ended'] ?? array();
check( '  its listing ended: a push makes a new listing, in 183454, as the line and the readiness say', array( 0 === strpos( (string) ( $e['line'] ?? '' ), 'The category saved for this product, Pokémon Individual Cards (180006), was wrong in TCGiant Sync\'s list until 3.28.1: eBay does not file trading cards under 180006. Pushes send CCG Individual Cards (183454) instead' ), $e['readiness'][0] ?? null, $e['readiness'][1] ?? null ), array( true, '✔ Category: CCG Individual Cards (183454), in place of the retired 180006', '✔ Condition: Graded - Professional Sports Authenticator (PSA) 9' ) );
$t = $tab['typed'] ?? array();
check( '183446 typed into the tab\'s custom box: custom, with no "(retired)" option or line', array( $t['selected'] ?? null, $t['custom_hidden'] ?? null, isset( $t['options']['183446'] ), $t['line'] ?? null ), array( 'custom', false, false, '' ) );
check( 'the product save keeps the ID typed into the custom box, and clears it when one is chosen from the list', $tab['saved_typed'] ?? null, array( '261068', '' ) );
check( 'the products list names a retired category for what is sent, and a browsed one as before', $tab['column'] ?? null, array( '(retired) Pokémon Individual Cards (180006) → CCG Individual Cards (183454)', 'Toys & Hobbies > Building Toys' ) );
$export_src = (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/export.php' );
check( 'the Push to eBay page names a retired default the same way, in both of its summaries', substr_count( $export_src, "esc_html( TCGiant_Sync_Catalog::retired_category_label( \$settings['export_category_id'] ) )" ), 2 );
check( '  unless it was typed into Settings\' box', substr_count( $export_src, "TCGiant_Sync_Catalog::retired_category( \$settings['export_category_id'], \$settings['export_category_name'] ?? '', \$settings['export_category_typed'] ?? '' )" ), 2 );
check( 'the products list: a typed 183446 is not named "(retired)"', array( '' !== (string) ( $tab['column_typed'] ?? '' ), false !== strpos( (string) ( $tab['column_typed'] ?? '' ), '(retired)' ) ), array( true, false ) );
check( 'a coin parent (Coins: US, 253): the category not ready, in the push\'s own words (3.28.5)', $tab['coin_parent']['readiness'][0] ?? null, '✘ Category: Coins: US (253) is a parent category on eBay, which takes only a bottom-level one: choose a subcategory of it with Browse eBay Categories' );
check( '  a coin leaf under it: ready', $tab['coin_leaf']['readiness'][0] ?? null, '✔ Category: Coins & Paper Money > Coins: US > Small Cents (11980)' );
check( 'a shop on eBay UK, a trading card saved as 180006: the condition not ready, as the push refuses it', $tab['gb_card']['readiness'][1] ?? null, '✘ Condition: Graded - Professional Sports Authenticator (PSA) 9 - ' . CARD_WORDS );
check( '  on eBay Germany: a warning, as the push sends it without (ruling 2)', $tab['de_card']['readiness'][1] ?? null, '● Condition: Graded - Professional Sports Authenticator (PSA) 9 - sent without card details: this category takes none' );
check( 'a card in CCG Sealed Boxes with a live listing: a warning, as its revise sends it without (ruling 3)', $tab['custom_live']['readiness'][1] ?? null, '● Condition: Graded - Professional Sports Authenticator (PSA) 9 - sent without card details: this category takes none' );
check( '  a coin parent with a live listing: a warning, in the same words as the refusal', $tab['coin_live']['readiness'][0] ?? null, '● Category: Coins: US (253) is a parent category on eBay, which takes only a bottom-level one: choose a subcategory of it with Browse eBay Categories' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PUSH BUTTON CHECKS AS THE PUSH DOES (A RUN OF ITS OWN)\n" . str_repeat( '=', 124 ) . "\n";

exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/category-push-run.php' ) . ' 2>&1', $tcg_pout, $tcg_pcode );
$push = json_decode( (string) end( $tcg_pout ), true );
$tcg_queued = array( 'ok' => true, 'message' => 'Product queued for push to eBay. Check the Activity Log for results.', 'queued' => true, 'status' => 'queued' );
check( 'the run finished', array( $tcg_pcode, is_array( $push ) ), array( 0, true ) );
check( 'Push on a card in CCG Sealed Boxes with a live listing: queued, as its revise goes (the review\'s H1)', $push['live_card'] ?? null, $tcg_queued );
check( '  on a coin in Coins: US (253) with a live listing: queued', $push['live_coin'] ?? null, $tcg_queued );
check( '  the same card with no listing: refused, in the push\'s words, marked errored, nothing queued', $push['card'] ?? null, array( 'ok' => false, 'message' => REFUSED_CARD, 'queued' => false, 'status' => 'error' ) );
check( '  the same coin with no listing: refused', $push['coin'] ?? null, array( 'ok' => false, 'message' => 'Cannot push to eBay — missing required settings: eBay Category — Coins: US (253) is a parent category on eBay, which takes only a bottom-level one: choose a subcategory of it with Browse eBay Categories', 'queued' => false, 'status' => 'error' ) );
check( '  the card with its listing recorded as ended: refused, as its push makes a new listing', array( $push['ended_card']['ok'] ?? null, $push['ended_card']['queued'] ?? null ), array( false, false ) );
check( 'Item Type Trading Cards in Coins: US, live: not ready, still saying the mismatch (the review\'s L1)', $push['mismatch_live'] ?? null, '✘ Category: Coins: US (253) is a parent category on eBay, which takes only a bottom-level one: choose a subcategory of it with Browse eBay Categories - item type mismatch' );
check( '  with no listing: the same', $push['mismatch'] ?? null, '✘ Category: Coins: US (253) is a parent category on eBay, which takes only a bottom-level one: choose a subcategory of it with Browse eBay Categories - item type mismatch' );
check( '  and its Push is refused for it', array( $push['mismatch_push']['ok'] ?? null, false !== strpos( (string) ( $push['mismatch_push']['message'] ?? '' ), 'this is a Trading Cards item but the eBay category is set to Coins' ) ), array( false, true ) );

done();
