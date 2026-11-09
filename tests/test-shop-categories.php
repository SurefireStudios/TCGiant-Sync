<?php
/**
 * Harness: eBay shop categories on a push, and an import that knows the parent
 * (3.33.0).
 *
 * A card shop whose website is its master pushes its products to eBay. Each
 * listing went in "Other" in its eBay shop, because build_item_xml() sent no
 * Storefront. And its eBay shop has two categories of one name under different
 * parents, which an import matched by name alone, into one term.
 *
 * The real exporter and the real mapper run here on one played WordPress
 * (support/shop-categories-world.php), with eBay played at
 * TCGiant_Sync_API::trading_api_request(), so a listing is pushed, imported,
 * updated and imported again, and every body checked is the request as it
 * would go out.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/shop-categories-world.php';

$root = dirname( __DIR__ );

// The importer's filter by shop category, run for real.
foreach ( array( 'category-memory', 'catalog', 'sites', 'entitlements' ) as $tcg_class ) {
	require_once $root . '/includes/class-tcgiant-sync-' . $tcg_class . '.php';
}
require_once $root . '/includes/class-tcgiant-sync-importer.php';

// What each kind of body carries for one shop category.
$ONE  = '<Storefront><StoreCategoryID>9011</StoreCategoryID></Storefront>';
$ONE0 = '<Storefront><StoreCategoryID>9011</StoreCategoryID><StoreCategory2ID>0</StoreCategory2ID></Storefront>';

function section( $title ) {
	echo "\n" . $title . "\n" . str_repeat( '=', 112 ) . "\n";
}

/** Who may ask eBay for a shop's categories, in the plugin's own code. */
function files_asking_get_store( $root ) {
	$found = array();
	foreach ( array_merge( glob( $root . '/includes/*.php' ), glob( $root . '/admin/*.php' ), glob( $root . '/admin/views/*.php' ) ) as $file ) {
		if ( false !== strpos( (string) file_get_contents( $file ), '->get_store()' ) ) {
			$found[] = basename( $file );
		}
	}
	return $found;
}

/** Terms as get_terms() gives them. */
function term_list() {
	return get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
}

/** The product's categories, by full path as stored. */
function paths_of( $product_id ) {
	return array_map( 'term_path', wc_get_product( $product_id )->get_category_ids() );
}

/** A listing imported as a new product: its categories' paths. */
function imported_into( $item_id, $store1, $store2 = '' ) {
	$id = import( listing_of( $item_id, $store1, $store2 ) );
	return paths_of( $id );
}

/** GetStore cannot be had. */
function store_fails() {
	$GLOBALS['API']->answers['GetStore'] = new WP_Error( 'http_request_failed', 'eBay could not be reached.' );
	delete_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT );
}

/** eBay says the account has no Store, by this code. */
function no_store( $code ) {
	$GLOBALS['API']->answers['GetStore'] = new WP_Error( 'api_error', 'No store exists for the user.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => $code, 'ShortMessage' => 'No store.', 'LongMessage' => 'No store exists for the user.', 'SeverityCode' => 'Error' ) ) );
	delete_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT );
}

/** eBay refusing an item with these Errors. */
function refusal_with( array $errors ) {
	$words = array();
	foreach ( $errors as $error ) {
		$words[] = $error['LongMessage'] ?? '';
	}
	return new WP_Error( 'api_error', implode( ' | ', $words ), array( 'Ack' => 'Failure', 'Errors' => 1 === count( $errors ) ? $errors[0] : $errors ) );
}

/** eBay refusing an item for its Storefront alone. */
function storefront_refusal() {
	return refusal_with( array( array( 'ErrorCode' => '37', 'ShortMessage' => 'Input data is invalid.', 'LongMessage' => 'Input data for tag <Item.Storefront.StoreCategoryID> is invalid or missing.', 'SeverityCode' => 'Error' ) ) );
}

/** eBay refusing an item for the StoreCategory2ID it was sent. */
function second_refusal() {
	return refusal_with( array( array( 'ErrorCode' => '37', 'ShortMessage' => 'Input data is invalid.', 'LongMessage' => 'Input data for tag <Item.Storefront.StoreCategory2ID> is invalid or missing.', 'SeverityCode' => 'Error' ) ) );
}

/** Whether a shop category is held back. */
function is_held( $id ) {
	return false !== get_transient( TCGiant_Sync_Shop_Categories::HELD_TRANSIENT . $id );
}

/** The bodies of the calls of one name, their UUIDs taken out. */
function bodies_named( $name ) {
	return array_values( array_map( function ( $call ) {
		return $call[1];
	}, array_filter( calls_made(), function ( $call ) use ( $name ) {
		return $name === $call[0];
	} ) ) );
}

/** The filter's ids, as the real importer resolves them. */
function allowed_ids() {
	$importer = ( new ReflectionClass( 'TCGiant_Sync_Importer' ) )->newInstanceWithoutConstructor();
	return array_values( private_method( 'TCGiant_Sync_Importer', 'get_allowed_category_ids' )->invoke( $importer ) );
}

/** An ended listing, as GetItem answers. */
function ended_answer( $item_id, $type, array $more = array() ) {
	return array( 'Ack' => 'Success', 'Item' => array_merge( array( 'ItemID' => $item_id, 'ListingType' => $type, 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ), $more ) );
}

/** A live listing, as GetItem answers. */
function live_answer( $item_id, $type ) {
	return array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => $item_id, 'ListingType' => $type, 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Active' ), 'Site' => 'US' ) );
}

/** Every call made, its UUID taken out. */
function calls_made() {
	$out = array();
	foreach ( $GLOBALS['API']->calls as $call ) {
		$out[] = array( $call[0], preg_replace( '#<UUID>[0-9a-fA-F]+</UUID>\n?#', '', (string) $call[1] ) );
	}
	return $out;
}

// =============================================================================
section( 'THE TREE: ONE CACHED READ OF GETSTORE' );

fresh_shop();
card_shop();
$tree = TCGiant_Sync_Shop_Categories::tree();
$rows = $tree['categories'];

check( 'one GetStore', calls_named( 'GetStore' ), 1 );
check( 'kept an hour', $GLOBALS['W']['expiry'][ TCGiant_Sync_Shop_Categories::TREE_TRANSIENT ], 3600 );
check( 'an id stays a string of digits', $rows['9011']['id'], '9011' );
check( 'a category\'s name, as eBay gave it', $rows['9011']['name'], 'Scarlet & Violet' );
check( 'its parent', $rows['9011']['parent'], '9001' );
check( 'its full path', TCGiant_Sync_Shop_Categories::path( $rows['9011'] ), 'English Single Cards > Scarlet & Violet' );
check( 'the other of that name, under its own parent', TCGiant_Sync_Shop_Categories::path( $rows['9021'] ), 'Japanese Single Cards > Scarlet & Violet' );
check( 'three levels', TCGiant_Sync_Shop_Categories::path( $rows['9051'] ), 'Graded > PSA > PSA 10' );
check( 'no children', $rows['9011']['children'], false );
check( 'a parent of several has children', $rows['9001']['children'], true );
check( 'a parent of one, which eBay\'s XML gives as the child itself, has children', $rows['9002']['children'], true );
check( 'and a child of one', $rows['9041']['children'], true );
check( 'at the top, no parent', $rows['9001']['parent'], '' );
check( 'Other is in the tree', $rows['1']['name'], 'Other' );
check( 'in eBay\'s order, parents before their children', array_map( 'strval', array_keys( $rows ) ), array( '1', '9001', '9011', '9012', '9002', '9021', '9003', '9004', '9041', '9051' ) );

TCGiant_Sync_Shop_Categories::tree();
TCGiant_Sync_Shop_Categories::find( '9011' );
check( 'read again: from the transient', calls_named( 'GetStore' ), 1 );

TCGiant_Sync_Shop_Categories::tree( true );
check( 'unless asked to read eBay', calls_named( 'GetStore' ), 2 );

// One category at the top, and ids too long for an int.
fresh_shop();
$GLOBALS['API']->answers['GetStore'] = ebay_answer( '<GetStoreResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack><Store><CustomCategories>' . custom_category( '9223372036854775807', 'Long', child_category( '123456789012345678901', 'Longer' ) ) . '</CustomCategories></Store></GetStoreResponse>' );
$tree = TCGiant_Sync_Shop_Categories::tree();
check( 'a single category at the top is read', count( $tree['categories'] ), 2 );
check( 'an id of 19 digits is the string eBay gave', TCGiant_Sync_Shop_Categories::find( '9223372036854775807', $tree )['id'], '9223372036854775807' );
check( 'one too long for any int, the same', TCGiant_Sync_Shop_Categories::find( '123456789012345678901', $tree )['id'], '123456789012345678901' );
check( '  and its parent', TCGiant_Sync_Shop_Categories::find( '123456789012345678901', $tree )['parent'], '9223372036854775807' );

// The old transient's shape.
fresh_shop();
card_shop();
set_transient( 'tcgiant_sync_store_cat_map', array( '9011' => 'From the old list' ), 3600 );
check( 'the transient 3.32.1 left is not read', TCGiant_Sync_Shop_Categories::tree()['categories']['9011']['name'], 'Scarlet & Violet' );

delete_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT );
set_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT, array( '9011' => 'Flat' ), 3600 );
check( 'nor that shape under the new name', TCGiant_Sync_Shop_Categories::tree()['categories']['9011']['name'], 'Scarlet & Violet' );

delete_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT );
set_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT, array( 'schema' => 99, 'categories' => array( '5' => array() ), 'error' => '' ), 3600 );
check( 'nor another version of the tree', isset( TCGiant_Sync_Shop_Categories::tree()['categories']['5'] ), false );

delete_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT );
set_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT, array( 'schema' => TCGiant_Sync_Shop_Categories::TREE_SCHEMA ), 3600 );
check( 'nor one with no categories in it', isset( TCGiant_Sync_Shop_Categories::tree()['categories']['9011'] ), true );

store_with( custom_category( 'abc', 'Not a number', child_category( '9700', 'Under it' ) ) . custom_category( '9701', 'Fine' ) );
check( 'a category with no number for an id is left out', array_map( 'strval', array_keys( TCGiant_Sync_Shop_Categories::tree()['categories'] ) ), array( '1', '9701' ) );

// A failed read.
fresh_shop();
store_fails();
$tree = TCGiant_Sync_Shop_Categories::tree();
check( 'a failed read holds no categories', $tree['categories'], array() );
check( '  and says why', $tree['error'], 'eBay could not be reached.' );
check( '  and is kept a minute', $GLOBALS['W']['expiry'][ TCGiant_Sync_Shop_Categories::TREE_TRANSIENT ], 60 );
TCGiant_Sync_Shop_Categories::tree();
check( '  within which eBay is not asked again', calls_named( 'GetStore' ), 1 );
check( 'it is not usable', TCGiant_Sync_Shop_Categories::usable( $tree ), false );
check( '  and is not eBay saying there is nothing', $tree['none'], false );

foreach ( array( '319', '17721' ) as $code ) {
	fresh_shop();
	no_store( $code );
	$tree = TCGiant_Sync_Shop_Categories::tree();
	check( 'eBay\'s no-store code ' . $code . ': eBay said there is nothing', array( $tree['none'], $tree['error'] ), array( true, 'No store exists for the user.' ) );
}

fresh_shop();
$GLOBALS['API']->answers['GetStore'] = refusal_with( array( array( 'ErrorCode' => '10007', 'LongMessage' => 'Internal error.', 'SeverityCode' => 'Error' ) ) );
check( 'another of eBay\'s refusals: it could not be asked', TCGiant_Sync_Shop_Categories::tree()['none'], false );

fresh_shop();
$GLOBALS['API']->answers['GetStore'] = array( 'Ack' => 'Success', 'Store' => array( 'Name' => 'Shop' ) );
$tree = TCGiant_Sync_Shop_Categories::tree();
check( 'a Store with no categories is kept a minute', $GLOBALS['W']['expiry'][ TCGiant_Sync_Shop_Categories::TREE_TRANSIENT ], 60 );
check( '  with no error', $tree['error'], '' );
check( '  and eBay said there is nothing', $tree['none'], true );

// Asked again, and not answered.
fresh_shop();
card_shop();
TCGiant_Sync_Shop_Categories::tree();
$GLOBALS['API']->answers['GetStore'] = new WP_Error( 'http_request_failed', 'eBay could not be reached.' );
$tree = TCGiant_Sync_Shop_Categories::tree( true );
check( 'a refresh eBay does not answer keeps the good tree', array( isset( $tree['categories']['9011'] ), $tree['refresh_error'] ), array( true, 'eBay could not be reached.' ) );
check( '  in the transient too', array( isset( get_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT )['categories']['9011'] ), isset( get_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT )['refresh_error'] ) ), array( true, false ) );

no_store( '319' );
TCGiant_Sync_Shop_Categories::tree();
fresh_shop();
card_shop();
TCGiant_Sync_Shop_Categories::tree();
$GLOBALS['API']->answers['GetStore'] = array( 'Ack' => 'Success', 'Store' => array( 'Name' => 'Shop' ) );
check( 'a refresh eBay answers with nothing is believed', TCGiant_Sync_Shop_Categories::tree( true )['categories'], array() );

fresh_shop();
$GLOBALS['API']->answers['GetStore'] = new WP_Error( 'http_request_failed', 'eBay could not be reached.' );
check( 'a refresh with no good tree to keep is a failed read', array( TCGiant_Sync_Shop_Categories::tree( true )['categories'], $GLOBALS['W']['expiry'][ TCGiant_Sync_Shop_Categories::TREE_TRANSIENT ] ), array( array(), 60 ) );

fresh_shop();
$GLOBALS['API']->answers['GetStore'] = array( 'Store' => array( 'CustomCategories' => array( 'CustomCategory' => 'nonsense' ) ) );
check( 'an answer of the wrong shape holds nothing, and does not stop anything', TCGiant_Sync_Shop_Categories::tree()['categories'], array() );

check( 'nothing else in the plugin asks eBay for them', files_asking_get_store( $root ), array( 'class-tcgiant-sync-shop-categories.php' ) );
check( 'the old transient is not read anywhere', 0 === substr_count( implode( '', array_map( 'file_get_contents', glob( $root . '/includes/*.php' ) ) ), "'tcgiant_sync_store_cat_map'" ), true );

// =============================================================================
section( 'THE MAP' );

fresh_shop();
$a = make_term( 'A' );
$b = make_term( 'B' );
$c = make_term( 'C' );
shop_map( array( $a => '9011', 99999 => '9012', $b => '90x1', $c => 9003 ) );
check( 'only a term that exists and an id of digits count', TCGiant_Sync_Shop_Categories::map(), array( $a => '9011' ) );

$GLOBALS['W']['no_terms'] = true;
check( 'WordPress unable to list its terms: the map is not lost', TCGiant_Sync_Shop_Categories::map(), array( $a => '9011', 99999 => '9012' ) );
$GLOBALS['W']['no_terms'] = false;

$GLOBALS['W']['term_lists'] = 0;
TCGiant_Sync_Shop_Categories::for_product( shop_product( 91, array( $a ) ) );
check( 'what a product goes in needs no list of terms', $GLOBALS['W']['term_lists'], 0 );

unset( $GLOBALS['W']['options'][ TCGiant_Sync_Shop_Categories::MAP_OPTION ] );
$GLOBALS['W']['term_lists']     = 0;
$GLOBALS['W']['category_reads'] = 0;
check( 'no option: no map', TCGiant_Sync_Shop_Categories::map(), array() );
check( 'no ids for a product', TCGiant_Sync_Shop_Categories::for_product( shop_product( 90, array( $a ) ) ), array() );
check( '  and neither the terms nor the product are read to say so', array( $GLOBALS['W']['term_lists'], $GLOBALS['W']['category_reads'] ), array( 0, 0 ) );
update_option( TCGiant_Sync_Shop_Categories::MAP_OPTION, 'nonsense', false );
check( 'an option that is not a list: no map', TCGiant_Sync_Shop_Categories::map(), array() );

check( 'saved not autoloaded', 1 === preg_match( '/update_option\( self::MAP_OPTION, \$map, false \)/', (string) file_get_contents( $root . '/includes/class-tcgiant-sync-shop-categories.php' ) ), true );
check( 'and in uninstall\'s list', false !== strpos( (string) file_get_contents( $root . '/uninstall.php' ), "'tcgiant_sync_shop_category_map'," ), true );

// =============================================================================
section( 'WHAT A PRODUCT GOES IN' );

fresh_shop();
card_shop();
$top    = make_term( 'Pokemon' );
$mid    = make_term( 'Scarlet', $top );
$leaf   = make_term( 'Paldea', $mid );
$other  = make_term( 'Sealed boxes' );
$shelf  = make_term( 'Shelf' );
shop_map( array( $top => '9012', $mid => '9011' ) );

check( 'the term itself', TCGiant_Sync_Shop_Categories::for_product( shop_product( 1, array( $mid ) ) ), array( '9011' ) );
check( 'a child with no choice takes its nearest ancestor\'s', TCGiant_Sync_Shop_Categories::for_product( shop_product( 2, array( $leaf ) ) ), array( '9011' ) );
shop_map( array( $top => '9012' ) );
check( 'mapping a parent covers two levels down', TCGiant_Sync_Shop_Categories::for_product( shop_product( 3, array( $leaf ) ) ), array( '9012' ) );
shop_map( array( $top => '9012', $leaf => '9003' ) );
check( 'unless a child has a choice of its own', TCGiant_Sync_Shop_Categories::for_product( shop_product( 4, array( $leaf ) ) ), array( '9003' ) );
check( 'a product in no mapped category goes in none', TCGiant_Sync_Shop_Categories::for_product( shop_product( 5, array( $shelf ) ) ), array() );
check( 'a product in no category goes in none', TCGiant_Sync_Shop_Categories::for_product( shop_product( 6, array() ) ), array() );
delete_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT );
$GLOBALS['API']->calls = array();
TCGiant_Sync_Shop_Categories::for_product( shop_product( 5, array( $shelf ) ) );
check( 'no GetStore for one that maps to nothing, with the transient empty', calls_named( 'GetStore' ), 0 );

shop_map( array( $mid => '9011', $other => '9012', $shelf => '9003' ) );
check( 'the deepest first', TCGiant_Sync_Shop_Categories::for_product( shop_product( 7, array( $other, $mid ) ) ), array( '9011', '9012' ) );
check( 'ties by the lowest term_id', TCGiant_Sync_Shop_Categories::for_product( shop_product( 8, array( $shelf, $other ) ) ), array( '9012', '9003' ) );
check( '  whichever order the product has them in', TCGiant_Sync_Shop_Categories::for_product( shop_product( 9, array( $other, $shelf ) ) ), array( '9012', '9003' ) );
check( 'at most two: the deepest, then the lowest', TCGiant_Sync_Shop_Categories::for_product( shop_product( 10, array( $shelf, $other, $mid ) ) ), array( '9011', '9012' ) );
shop_map( array( $mid => '9011', $other => '9011', $shelf => '9012' ) );
check( 'a duplicate id is dropped, and the next takes its place', TCGiant_Sync_Shop_Categories::for_product( shop_product( 11, array( $shelf, $other, $mid ) ) ), array( '9011', '9012' ) );
shop_map( array( $top => '9012', $mid => '9011' ) );
check( 'a parent and its child both held: the child first', TCGiant_Sync_Shop_Categories::for_product( shop_product( 12, array( $top, $mid ) ) ), array( '9011', '9012' ) );

$parent = new Shop_Product_Variable( 20, array( 'categories' => array( $mid ), 'type' => 'variable' ) );
$child  = new Shop_Product_Variation( 21, array( 'parent' => 20, 'type' => 'variation' ) );
check( 'a variation uses its parent\'s categories', TCGiant_Sync_Shop_Categories::for_product( $child ), array( '9011' ) );

// =============================================================================
section( 'WHAT IS NEVER SENT' );

fresh_shop();
card_shop();
$t1 = make_term( 'One' );
$t2 = make_term( 'Two' );

foreach ( array(
	array( 'ID 1', '1', 'other' ),
	array( '0', '0', 'other' ),
	array( 'an id the tree no longer has', '424242', 'gone' ),
	array( 'an id the tree says has children', '9001', 'parent' ),
	array( 'a category of three levels\' parent', '9041', 'parent' ),
) as list( $label, $id, $why ) ) {
	shop_map( array( $t1 => $id ) );
	$plan = TCGiant_Sync_Shop_Categories::plan( shop_product( 30, array( $t1 ) ) );
	check( $label . ' is not sent', $plan['ids'], array() );
	check( '  and is left out, saying why', $plan['left_out'], array( $id => $why ) );
}

store_with( custom_category( '9500', 'other' ) . custom_category( '9501', 'Fine', child_category( '9502', 'Other' ) ) );
shop_map( array( $t1 => '9500' ) );
check( 'the name "Other" at the top, whatever its id or case, is not sent', TCGiant_Sync_Shop_Categories::for_product( shop_product( 31, array( $t1 ) ) ), array() );
shop_map( array( $t1 => '9502' ) );
check( 'a shop\'s own "Fine > Other" is', TCGiant_Sync_Shop_Categories::for_product( shop_product( 31, array( $t1 ) ) ), array( '9502' ) );

card_shop();
shop_map( array( $t1 => '9001', $t2 => '9011' ) );
$plan = TCGiant_Sync_Shop_Categories::plan( shop_product( 32, array( $t1, $t2 ) ) );
check( 'one left out does not take a place', $plan['ids'], array( '9011' ) );

$t3 = make_term( 'Three' );
$t4 = make_term( 'Four' );
shop_map( array( $t1 => '424242', $t2 => '9011', $t3 => '9012', $t4 => '9003' ) );
$plan = TCGiant_Sync_Shop_Categories::plan( shop_product( 33, array( $t1, $t2, $t3, $t4 ) ) );
check( 'the next two go in its place', $plan['ids'], array( '9011', '9012' ) );

shop_map( array( $t1 => '9011', $t2 => '9012', $t3 => '424242' ) );
check( 'one past the two eBay takes is not left out: it was never going', TCGiant_Sync_Shop_Categories::plan( shop_product( 34, array( $t1, $t2, $t3 ) ) )['left_out'], array() );

// With no tree.
store_fails();
shop_map( array( $t1 => '424242', $t2 => '9001' ) );
check( 'no tree to hand: the stored ids go unchecked', TCGiant_Sync_Shop_Categories::for_product( shop_product( 35, array( $t1, $t2 ) ) ), array( '424242', '9001' ) );
shop_map( array( $t1 => '1', $t2 => '0' ) );
check( '  but never ID 1 or 0, which need no tree', TCGiant_Sync_Shop_Categories::for_product( shop_product( 36, array( $t1, $t2 ) ) ), array() );

// eBay says there is nothing there: not the same as not being able to ask.
foreach ( array( 'a Store with no categories' => null, 'code 319' => '319', 'code 17721' => '17721' ) as $label => $code ) {
	if ( null === $code ) {
		$GLOBALS['API']->answers['GetStore'] = array( 'Ack' => 'Success', 'Store' => array( 'Name' => 'Shop' ) );
		delete_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT );
	} else {
		no_store( $code );
	}
	shop_map( array( $t1 => '9011' ) );
	$plan = TCGiant_Sync_Shop_Categories::plan( shop_product( 37, array( $t1 ) ) );
	check( $label . ': nothing is sent, and it says why', array( $plan['ids'], $plan['left_out'] ), array( array(), array( '9011' => 'none' ) ) );
}
check( '  in plain words', TCGiant_Sync_Shop_Categories::left_out_warning( 'Push', 37, $plan ), 'Push: WC #37 leaves out eBay shop category 9011 (eBay says your account has no Store, or no shop categories).' );

// =============================================================================
section( 'MATCH BY NAME: suggest()' );

fresh_shop();
card_shop();
$tree = TCGiant_Sync_Shop_Categories::tree();
$en   = make_term( 'english single cards' );
$en_s = make_term( 'SCARLET & VIOLET', $en );
$jp   = make_term( 'Japanese Single Cards' );
$jp_s = make_term( 'Scarlet & Violet', $jp );
$stuff  = make_term( 'Stuff' );
$sealed = make_term( 'Sealed', $stuff );
$shield = make_term( 'Sword & Shield' );
$loose  = make_term( 'Scarlet & Violet' );

$got = TCGiant_Sync_Shop_Categories::suggest( term_list(), $tree );
check( 'a full path, ignoring case and entities', $got[ $en_s ] ?? '', '9011' );
check( '  and the other of that name by its own path', $got[ $jp_s ] ?? '', '9021' );
check( 'a name on one category each side', $got[ $sealed ] ?? '', '9003' );
check( '  with "&" stored as "&amp;"', $got[ $shield ] ?? '', '9012' );
check( 'a name duplicated on eBay with no path to tell them: nothing', isset( $got[ $loose ] ), false );
check( 'a category with children is not suggested', isset( $got[ $en ] ) || isset( $got[ $jp ] ), false );
check( 'a suggestion is a string of digits', is_string( $got[ $en_s ] ), true );

fresh_shop();
card_shop();
$tree  = TCGiant_Sync_Shop_Categories::tree();
$alone = make_term( 'Scarlet & Violet' );
check( 'a name on one term here and two on eBay, with no path: nothing', TCGiant_Sync_Shop_Categories::suggest( term_list(), $tree ), array() );

fresh_shop();
card_shop();
$tree   = TCGiant_Sync_Shop_Categories::tree();
$en     = make_term( 'english single cards' );
$en_s   = make_term( 'SCARLET & VIOLET', $en );
$jp     = make_term( 'Japanese Single Cards' );
$jp_s   = make_term( 'Scarlet & Violet', $jp );
$stuff  = make_term( 'Stuff' );
$sealed = make_term( 'Sealed', $stuff );
$again  = make_term( 'Sealed', $en );
$got    = TCGiant_Sync_Shop_Categories::suggest( term_list(), $tree );
check( 'a name duplicated here with no path: nothing, for either', isset( $got[ $sealed ] ) || isset( $got[ $again ] ), false );

$got = TCGiant_Sync_Shop_Categories::suggest( term_list(), $tree, array( $en_s => '9012' ) );
check( 'a row that already has a choice is left alone', isset( $got[ $en_s ] ), false );
check( '  and the others still are suggested', $got[ $jp_s ] ?? '', '9021' );

fresh_shop();
card_shop();
$tree  = TCGiant_Sync_Shop_Categories::tree();
$other = make_term( 'Other' );
$psa_p = make_term( 'Graded' );
$psa   = make_term( 'PSA', $psa_p );
$psa10 = make_term( 'PSA 10', $psa );
$got   = TCGiant_Sync_Shop_Categories::suggest( term_list(), $tree );
check( 'Other is never suggested', isset( $got[ $other ] ), false );
check( 'a full path to a category with children: nothing, by path or by name', isset( $got[ $psa ] ), false );
check( 'its child with no children is', $got[ $psa10 ] ?? '', '9051' );

fresh_shop();
store_with( custom_category( '9001', 'Pokemon', child_category( '9502', 'Other' ) ) );
$pok   = make_term( 'Pokemon' );
$below = make_term( 'Other', $pok );
check( 'a shop\'s own "Pokemon > Other" is suggested by its path', TCGiant_Sync_Shop_Categories::suggest( term_list(), TCGiant_Sync_Shop_Categories::tree() ), array( $below => '9502' ) );

// =============================================================================
section( 'A PUSH AND AN UPDATE SEND <Storefront>' );

fresh_shop();
card_shop();
$en   = make_term( 'English' );
$sv   = make_term( 'Scarlet', $en );
$ss   = make_term( 'Sword', $en );
$none = make_term( 'Not mapped' );
shop_map( array( $sv => '9011', $ss => '9012' ) );

$GLOBALS['API']->calls = array();
push( shop_product( 40, array( $sv ) ) );
$body = body_of( 'AddFixedPriceItem' );
check( 'a new listing goes to its category', storefront_of( $body ), '<Storefront><StoreCategoryID>9011</StoreCategoryID></Storefront>' );
check( '  right after <PrimaryCategory>', false !== strpos( $body, "</PrimaryCategory>\n<Storefront>" ), true );
check( '  on a line of its own', false !== strpos( $body, "</Storefront>\n" ), true );

$GLOBALS['API']->calls = array();
push( shop_product( 41, array( $sv, $ss ) ) );
check( 'two: the second as StoreCategory2ID, lower term first', storefront_of( body_of( 'AddFixedPriceItem' ) ), '<Storefront><StoreCategoryID>9011</StoreCategoryID><StoreCategory2ID>9012</StoreCategory2ID></Storefront>' );

$GLOBALS['API']->calls = array();
push( shop_product( 42, array( $sv ), pushed_meta( '110000000042' ) ) );
check( 'an Update of a product the plugin pushed, with 0 for a second it no longer has', storefront_of( body_of( 'ReviseFixedPriceItem' ) ), $ONE0 );

$GLOBALS['API']->calls = array();
push( shop_product( 39, array( $sv, $ss ), pushed_meta( '110000000039' ) ) );
check( 'an Update with two sends the two', storefront_of( body_of( 'ReviseFixedPriceItem' ) ), '<Storefront><StoreCategoryID>9011</StoreCategoryID><StoreCategory2ID>9012</StoreCategory2ID></Storefront>' );

$GLOBALS['API']->calls = array();
delete_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT );
push( shop_product( 43, array( $sv ), imported_meta( '110000000043' ) ) );
check( 'an Update of an imported listing that keeps eBay\'s values: none', storefront_of( body_of( 'ReviseFixedPriceItem' ) ), '' );
check( '  and the tree is not read for it', calls_named( 'GetStore' ), 0 );

$GLOBALS['API']->calls = array();
push( shop_product( 44, array( $sv ), imported_meta( '110000000044', array( TCGiant_Sync_Ebay_Live::FOLLOW_META => '1' ) ) ) );
check( 'with "Sends my shop defaults" ticked: sent', storefront_of( body_of( 'ReviseFixedPriceItem' ) ), $ONE0 );

$GLOBALS['API']->calls = array();
push( shop_product( 45, array( $sv ), pushed_meta( '110000000045', array( TCGiant_Sync_Ebay_Live::FOLLOW_META => '0' ) ) ) );
check( 'a pushed product told to keep eBay\'s values: none', storefront_of( body_of( 'ReviseFixedPriceItem' ) ), '' );

$GLOBALS['API']->calls = array();
push( shop_product( 46, array( $none ) ) );
check( 'a product that maps to nothing: none on a new listing', storefront_of( body_of( 'AddFixedPriceItem' ) ), '' );
$GLOBALS['API']->calls = array();
push( shop_product( 47, array( $none ), pushed_meta( '110000000047' ) ) );
check( '  and none on an Update', storefront_of( body_of( 'ReviseFixedPriceItem' ) ), '' );

// The other kinds of item.
$GLOBALS['API']->calls = array();
push( shop_product( 48, array( $sv ), array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ), array( 'stock' => 1 ) ) );
check( 'an auction', storefront_of( body_of( 'AddItem' ) ), '<Storefront><StoreCategoryID>9011</StoreCategoryID></Storefront>' );

$GLOBALS['API']->calls = array();
push( shop_product( 49, array( $sv ), pushed_meta( '110000000049', array( '_ebay_listing_type' => 'Chinese', '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) ), array( 'stock' => 1 ) ) );
check( 'a pushed live auction\'s Update', storefront_of( body_of( 'ReviseItem' ) ), $ONE0 );

/** A variable product in these categories, with two variations. */
function variable_product( $id, array $categories, array $meta = array() ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/item-' . $id . '.jpg';
	$product = new Shop_Product_Variable( $id, array( 'name' => 'Item ' . $id, 'description' => 'Described.', 'sku' => 'VAR-' . $id, 'type' => 'variable', 'image_id' => 9000 + $id, 'categories' => $categories ) );
	$product->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
	$product->children             = array( $id * 10 + 1, $id * 10 + 2 );
	new Shop_Product_Variation( $id * 10 + 1, array( 'sku' => 'VAR-S', 'size' => 'Small', 'regular_price' => '10', 'stock' => 3, 'parent' => $id ) );
	new Shop_Product_Variation( $id * 10 + 2, array( 'sku' => 'VAR-L', 'size' => 'Large', 'regular_price' => '12', 'stock' => 1, 'parent' => $id ) );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}

$GLOBALS['API']->calls = array();
push( variable_product( 50, array( $sv ) ) );
$body = body_of( 'AddFixedPriceItem' );
check( 'a variation listing, once, on the listing', array( storefront_of( $body ), substr_count( $body, '<Storefront>' ), strpos( $body, '<Storefront>' ) < strpos( $body, '<Variations>' ) ), array( '<Storefront><StoreCategoryID>9011</StoreCategoryID></Storefront>', 1, true ) );

$GLOBALS['API']->calls = array();
push( variable_product( 51, array( $sv ), pushed_meta( '110000000051' ) ) );
check( 'a variation listing\'s Update', storefront_of( body_of( 'ReviseFixedPriceItem' ) ), $ONE0 );

$product = shop_product( 52, array( $sv ) );
check( 'Verify and Check with eBay, which send this item', storefront_of( item_xml( $product, exporter()->get_export_settings( 52 ) ) ), '<Storefront><StoreCategoryID>9011</StoreCategoryID></Storefront>' );
$product = shop_product( 53, array( $sv ), imported_meta( '110000000053' ) );
check( 'Verify of an imported listing checks a new listing', storefront_of( item_xml( $product, exporter()->get_export_settings( 53 ) ) ), '<Storefront><StoreCategoryID>9011</StoreCategoryID></Storefront>' );
$verify = (string) file_get_contents( $root . '/admin/class-tcgiant-sync-admin.php' ) . (string) file_get_contents( $root . '/includes/class-tcgiant-sync-template-admin.php' );
check( '  both of which build through build_item_xml_public()', substr_count( $verify, '->build_item_xml_public(' ) >= 2, true );

// eBay refuses a new listing for a missing UPC, and it is sent again.
$GLOBALS['API']->calls = array();
$upc_missing = 'The UPC field is missing. Please add UPC to the listing and try again.';
$GLOBALS['API']->queue['AddFixedPriceItem'] = array(
	new WP_Error( 'api_error', $upc_missing, array( 'Ack' => 'Failure', 'Errors' => array( 'ShortMessage' => $upc_missing, 'LongMessage' => $upc_missing, 'SeverityCode' => 'Error' ) ) ),
);
$GLOBALS['W']['log'] = array();
push( shop_product( 54, array( $sv ) ) );
$sent = array_values( array_filter( calls_made(), function ( $call ) {
	return 'AddFixedPriceItem' === $call[0];
} ) );
check( 'a new listing sent a second time carries it each time', array_map( function ( $call ) {
	return storefront_of( $call[1] );
}, $sent ), array( '<Storefront><StoreCategoryID>9011</StoreCategoryID></Storefront>', '<Storefront><StoreCategoryID>9011</StoreCategoryID></Storefront>' ) );
check( '  and is said once', logged_with( 'was sent to eBay shop category' ), 1 );

// =============================================================================
section( 'A PUSH THAT FINDS ITS LISTING ENDED' );

fresh_shop();
card_shop();
$en = make_term( 'English' );
$sv = make_term( 'Scarlet', $en );
shop_map( array( $sv => '9011' ) );
$closed = new WP_Error( 'api_error', 'The auction has already been closed.' );

// A fixed-price listing: a new one in its place.
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$GLOBALS['API']->answers['GetItem']              = ended_answer( '110000000060', 'FixedPriceItem' );
push( shop_product( 60, array( $sv ), pushed_meta( '110000000060' ) ) );
check( 'pushed, fixed price: the new listing in its place goes to the category', storefront_of( body_of( 'AddFixedPriceItem' ) ), '<Storefront><StoreCategoryID>9011</StoreCategoryID></Storefront>' );

$GLOBALS['API']->calls                           = array();
$GLOBALS['API']->answers['GetItem']              = ended_answer( '110000000061', 'FixedPriceItem' );
push( shop_product( 61, array( $sv ), imported_meta( '110000000061' ) ) );
check( 'imported, fixed price: its revise sent none', storefront_of( body_of( 'ReviseFixedPriceItem' ) ), '' );
check( '  and the new listing in its place goes to the category', storefront_of( body_of( 'AddFixedPriceItem' ) ), '<Storefront><StoreCategoryID>9011</StoreCategoryID></Storefront>' );
unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'] );

// An imported auction: RelistItem, with the shop's edits.
$auction = array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_duration' => 'Days_7', '_ebay_listing_status' => 'Ended' );
$GLOBALS['W']['log']                   = array();
$GLOBALS['API']->calls                 = array();
$GLOBALS['API']->answers['ReviseItem'] = $closed;
$GLOBALS['API']->answers['GetItem']    = ended_answer( '110000000062', 'Chinese', array( 'StartPrice' => '37.0' ) );
$GLOBALS['API']->answers['RelistItem'] = array( 'Ack' => 'Success', 'ItemID' => '110000000162' );
$result = push( shop_product( 62, array( $sv ), imported_meta( '110000000062', $auction ), array( 'stock' => 1 ) ) );
$body   = body_of( 'RelistItem' );
check( 'an imported auction relisted by a push', is_array( $result ) ? $result['action'] : $result, 'relisted' );
check( '  goes to the category, as the push\'s other fields go, with 0 for any second it had', storefront_of( $body ), $ONE0 );
check( '  with them', array( false !== strpos( $body, '<Title>' ), false !== strpos( $body, '<Description>' ) ), array( true, true ) );
check( '  and is said once', logged_with( '110000000162 of WC #62 was sent to eBay shop category English Single Cards > Scarlet & Violet' ), 1 );
check( '  the relist line as it was', logged_with( 'Push: WC #62 relisted on eBay as 110000000162 (was 110000000062)' ), 1 );

$GLOBALS['API']->calls              = array();
$GLOBALS['API']->answers['GetItem'] = ended_answer( '110000000063', 'Chinese', array( 'StartPrice' => '37.0' ) );
$unmapped = make_term( 'Not mapped' );
push( shop_product( 63, array( $unmapped ), imported_meta( '110000000063', $auction ), array( 'stock' => 1 ) ) );
check( 'one that maps to nothing keeps the category it is in', storefront_of( body_of( 'RelistItem' ) ), '' );
unset( $GLOBALS['API']->answers['ReviseItem'], $GLOBALS['API']->answers['RelistItem'] );

// The listing was relisted on eBay, and that relist is live.
$GLOBALS['API']->calls = array();
$GLOBALS['API']->queue['ReviseFixedPriceItem'] = array( new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' ) );
$GLOBALS['API']->queue['GetItem'] = array( ended_answer( '110000000064', 'FixedPriceItem', array( 'ListingDetails' => array( 'RelistedItemID' => '110000000164' ) ) ), live_answer( '110000000164', 'FixedPriceItem' ) );
unset( $GLOBALS['API']->answers['GetItem'] );
push( shop_product( 64, array( $sv ), pushed_meta( '110000000064' ) ) );
$bodies = array_values( array_filter( calls_made(), function ( $call ) {
	return 'ReviseFixedPriceItem' === $call[0];
} ) );
check( 'pushed, relisted on eBay: the Update of the live relist sends it', storefront_of( $bodies[1][1] ?? '' ), $ONE0 );
check( '  and is said', logged_with( 'of WC #64 was sent to eBay shop category English Single Cards > Scarlet & Violet' ), 1 );

$GLOBALS['API']->calls = array();
$GLOBALS['API']->queue['ReviseFixedPriceItem'] = array( new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' ) );
$GLOBALS['API']->queue['GetItem'] = array( ended_answer( '110000000065', 'FixedPriceItem', array( 'ListingDetails' => array( 'RelistedItemID' => '110000000165' ) ) ), live_answer( '110000000165', 'FixedPriceItem' ) );
push( shop_product( 65, array( $sv ), imported_meta( '110000000065' ) ) );
$bodies = array_values( array_filter( calls_made(), function ( $call ) {
	return 'ReviseFixedPriceItem' === $call[0];
} ) );
check( 'imported, relisted on eBay: the Update keeps eBay\'s values', storefront_of( $bodies[1][1] ?? 'none' ), '' );

// =============================================================================
section( 'EBAY REFUSES THE STOREFRONT: THE ITEM GOES ONCE MORE WITHOUT IT' );

/** A shop with one mapped term, and another mapped to 9012. */
function refusing_shop() {
	fresh_shop();
	card_shop();
	$GLOBALS['t_a'] = make_term( 'Scarlet' );
	$GLOBALS['t_b'] = make_term( 'Sword' );
	shop_map( array( $GLOBALS['t_a'] => '9011', $GLOBALS['t_b'] => '9012' ) );
}

// A new listing.
refusing_shop();
$GLOBALS['API']->queue['AddFixedPriceItem'] = array( storefront_refusal() );
$result = push( shop_product( 600, array( $t_a ) ) );
$adds   = array_values( array_filter( $GLOBALS['API']->calls, function ( $call ) {
	return 'AddFixedPriceItem' === $call[0];
} ) );
check( 'a new listing: sent twice', count( $adds ), 2 );
check( '  the first with its Storefront, the second without', array( storefront_of( $adds[0][1] ), storefront_of( $adds[1][1] ) ), array( $ONE, '' ) );
preg_match( '#<UUID>(\w+)</UUID>#', $adds[0][1], $first );
preg_match( '#<UUID>(\w+)</UUID>#', $adds[1][1], $second );
check( '  the second with a UUID of its own', array( 1 === substr_count( $adds[1][1], '<UUID>' ), $first[1] !== $second[1] ), array( true, true ) );
check( '  nothing else changed', preg_replace( '#<UUID>\w+</UUID>#', '', str_replace( $ONE . "\n", '', $adds[0][1] ) ), preg_replace( '#<UUID>\w+</UUID>#', '', $adds[1][1] ) );
check( '  the listing is made', array( is_array( $result ), $result['action'] ?? '' ), array( true, 'created' ) );
check( '  said as a warning, in eBay\'s words', logged_with( 'Push: eBay refused WC #600 for its shop category English Single Cards > Scarlet & Violet, so it was sent once more without it, and that goes unsent for an hour. eBay said: Input data for tag <Item.Storefront.StoreCategoryID> is invalid or missing.' ), 1 );
check( '  a warning', count( logged( 'warning' ) ), 1 );
check( '  and no "was sent to" line', logged_with( 'was sent to eBay shop' ), 0 );

// The hold.
check( 'the id is held an hour', array( false !== get_transient( TCGiant_Sync_Shop_Categories::HELD_TRANSIENT . '9011' ), $GLOBALS['W']['expiry'][ TCGiant_Sync_Shop_Categories::HELD_TRANSIENT . '9011' ] ), array( true, 3600 ) );
$GLOBALS['API']->calls = array();
$GLOBALS['W']['log']   = array();
push( shop_product( 601, array( $t_a ) ) );
check( 'the next product of a bulk push pays once: one call, no Storefront', array( calls_named( 'AddFixedPriceItem' ), storefront_of( body_of( 'AddFixedPriceItem' ) ) ), array( 1, '' ) );
check( '  and is told why', logged_with( 'Push: WC #601 leaves out eBay shop category English Single Cards > Scarlet & Violet (eBay refused it within the last hour).' ), 1 );
$GLOBALS['API']->calls = array();
push( shop_product( 602, array( $t_b ) ) );
check( 'the hold is by id: the shop\'s other categories go on', storefront_of( body_of( 'AddFixedPriceItem' ) ), '<Storefront><StoreCategoryID>9012</StoreCategoryID></Storefront>' );
$GLOBALS['API']->calls = array();
push( shop_product( 603, array( $t_a, $t_b ) ) );
check( '  the held one leaves its place to the other', storefront_of( body_of( 'AddFixedPriceItem' ) ), '<Storefront><StoreCategoryID>9012</StoreCategoryID></Storefront>' );
$product = shop_product( 604, array( $t_a ) );
check( 'Verify asks eBay all the same: showing its answer is what it is for', storefront_of( item_xml( $product, exporter()->get_export_settings( 604 ) ) ), $ONE );
delete_transient( TCGiant_Sync_Shop_Categories::HELD_TRANSIENT . '9011' );
$GLOBALS['API']->calls = array();
push( shop_product( 605, array( $t_a ) ) );
check( 'the hour over, it goes again', storefront_of( body_of( 'AddFixedPriceItem' ) ), $ONE );

// A Revise.
refusing_shop();
$GLOBALS['API']->queue['ReviseFixedPriceItem'] = array( storefront_refusal() );
$result = push( shop_product( 610, array( $t_a ), pushed_meta( '110000000610' ) ) );
$bodies = array_values( array_filter( calls_made(), function ( $call ) {
	return 'ReviseFixedPriceItem' === $call[0];
} ) );
check( 'a Revise: sent twice, the second the same but for its Storefront', array( count( $bodies ), str_replace( $ONE0 . "\n", '', $bodies[0][1] ) === $bodies[1][1] ), array( 2, true ) );
check( '  the Update goes through', $result['action'] ?? '', 'updated' );
check( '  no "was sent to" line', logged_with( 'was sent to eBay shop' ), 0 );

// A push's relist.
refusing_shop();
$GLOBALS['API']->answers['ReviseItem'] = new WP_Error( 'api_error', 'The auction has already been closed.' );
$GLOBALS['API']->answers['GetItem']    = ended_answer( '110000000611', 'Chinese', array( 'StartPrice' => '37.0' ) );
$GLOBALS['API']->answers['RelistItem'] = array( 'Ack' => 'Success', 'ItemID' => '110000000711' );
$GLOBALS['API']->queue['RelistItem']   = array( storefront_refusal() );
$result = push( shop_product( 611, array( $t_a ), imported_meta( '110000000611', array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_duration' => 'Days_7', '_ebay_listing_status' => 'Ended' ) ), array( 'stock' => 1 ) ) );
$bodies = array_values( array_filter( calls_made(), function ( $call ) {
	return 'RelistItem' === $call[0];
} ) );
check( 'a push\'s relist: sent twice, so the auction does not stay ended', array( count( $bodies ), $result['action'] ?? '', $result['item_id'] ?? '' ), array( 2, 'relisted', '110000000711' ) );
check( '  the second without its Storefront, with the push\'s other fields', array( storefront_of( $bodies[0][1] ), storefront_of( $bodies[1][1] ), false !== strpos( $bodies[1][1], '<Title>' ) ), array( $ONE0, '', true ) );
check( '  said, and no "was sent to" line', array( logged_with( 'Push: eBay refused WC #611 for its shop category' ), logged_with( 'was sent to eBay shop' ) ), array( 1, 0 ) );

// The live relist of an Update.
refusing_shop();
$GLOBALS['API']->queue['ReviseFixedPriceItem'] = array( new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' ), storefront_refusal() );
$GLOBALS['API']->queue['GetItem'] = array( ended_answer( '110000000612', 'FixedPriceItem', array( 'ListingDetails' => array( 'RelistedItemID' => '110000000712' ) ) ), live_answer( '110000000712', 'FixedPriceItem' ) );
$result = push( shop_product( 612, array( $t_a ), pushed_meta( '110000000612' ) ) );
check( 'the Update of a live relist: goes through without it', array( calls_named( 'ReviseFixedPriceItem' ), $result['action'] ?? '' ), array( 3, 'updated' ) );

// When not.
refusing_shop();
$GLOBALS['API']->answers['AddFixedPriceItem'] = refusal_with( array(
	array( 'ErrorCode' => '37', 'LongMessage' => 'Store category is invalid.', 'SeverityCode' => 'Error' ),
	array( 'ErrorCode' => '21', 'LongMessage' => 'Your payments are on hold.', 'SeverityCode' => 'Error' ),
) );
$result = push( shop_product( 620, array( $t_a ) ) );
check( 'another Error beside it: not sent again, and refused', array( calls_named( 'AddFixedPriceItem' ), is_wp_error( $result ), false !== get_transient( TCGiant_Sync_Shop_Categories::HELD_TRANSIENT . '9011' ) ), array( 1, true, false ) );

refusing_shop();
$GLOBALS['API']->queue['AddFixedPriceItem'] = array( refusal_with( array(
	array( 'ErrorCode' => '37', 'LongMessage' => 'Store category is invalid.', 'SeverityCode' => 'Error' ),
	array( 'ErrorCode' => '21', 'LongMessage' => 'Funds may be unavailable.', 'SeverityCode' => 'Warning' ),
) ) );
$result = push( shop_product( 621, array( $t_a ) ) );
check( 'a Warning beside it does not count: sent again', array( calls_named( 'AddFixedPriceItem' ), is_array( $result ) ), array( 2, true ) );

refusing_shop();
$GLOBALS['API']->answers['AddFixedPriceItem'] = refusal_with( array( array( 'ErrorCode' => '37', 'LongMessage' => 'The title is too long.', 'SeverityCode' => 'Error' ) ) );
push( shop_product( 622, array( $t_a ) ) );
check( 'any other Error: not sent again', calls_named( 'AddFixedPriceItem' ), 1 );

refusing_shop();
$GLOBALS['API']->answers['AddFixedPriceItem'] = new WP_Error( 'http_request_failed', 'Timed out naming the Store Category.' );
push( shop_product( 623, array( $t_a ) ) );
check( 'no refusal from eBay at all: not sent again', calls_named( 'AddFixedPriceItem' ), 1 );

refusing_shop();
$GLOBALS['API']->answers['AddFixedPriceItem'] = new WP_Error( 'api_error', 'Store category is invalid.', array( 'Ack' => 'Warning', 'Errors' => array( 'LongMessage' => 'Store category is invalid.', 'SeverityCode' => 'Error' ) ) );
push( shop_product( 624, array( $t_a ) ) );
check( 'not Ack Failure: not sent again', calls_named( 'AddFixedPriceItem' ), 1 );

refusing_shop();
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = storefront_refusal();
$result = push( shop_product( 625, array( $t_a ), imported_meta( '110000000625' ) ) );
check( 'no Storefront sent: the words alone do not send it again', array( calls_named( 'ReviseFixedPriceItem' ), is_wp_error( $result ) ), array( 1, true ) );

refusing_shop();
$GLOBALS['API']->answers['AddFixedPriceItem'] = storefront_refusal();
$result = push( shop_product( 626, array( $t_a ) ) );
check( 'refused again without it: twice, and no more', array( calls_named( 'AddFixedPriceItem' ), is_wp_error( $result ) ), array( 2, true ) );

// The words in a description.
refusing_shop();
$GLOBALS['API']->queue['AddFixedPriceItem'] = array( storefront_refusal() );
push( shop_product( 627, array( $t_a ), array(), array( 'description' => 'How to send one: ' . $ONE . "\n" . 'and so on.' ) ) );
$sent = body_of( 'AddFixedPriceItem' );
check( 'a description holding those very words keeps them; the real one is what goes', array( substr_count( $sent, $ONE ), false !== strpos( $sent, "</PrimaryCategory>\n<StartPrice>" ) ), array( 1, true ) );

// The refusal itself.
check( 'refused_for(): by its code too', TCGiant_Sync_Shop_Categories::refused_for( refusal_with( array( array( 'ErrorCode' => '20169', 'LongMessage' => 'No.', 'SeverityCode' => 'Error' ) ) ) ), true );
check( '  a list of Warnings alone is no refusal for it', TCGiant_Sync_Shop_Categories::refused_for( refusal_with( array( array( 'LongMessage' => 'Store category.', 'SeverityCode' => 'Warning' ) ) ) ), false );
check( '  nor an answer that took the item', TCGiant_Sync_Shop_Categories::refused_for( array( 'Ack' => 'Success' ) ), false );
check( '  nor a rate limit', TCGiant_Sync_Shop_Categories::refused_for( new WP_Error( 'rate_limited', 'Store category.', array( 'Ack' => 'Failure', 'Errors' => array( 'LongMessage' => 'Store category.', 'SeverityCode' => 'Error' ) ) ) ), false );
check( 'the only callers of the retry are the push\'s', array( substr_count( (string) file_get_contents( $root . '/admin/class-tcgiant-sync-admin.php' ), 'storefront_retried' ), substr_count( (string) file_get_contents( $root . '/includes/class-tcgiant-sync-template-admin.php' ), 'storefront_retried' ) ), array( 0, 0 ) );

// =============================================================================
section( 'EBAY MAY HAVE MADE IT: NOT SENT AGAIN' );

// What the words of a Failure cannot say: a fresh UUID is what would let a
// second listing through (fix round 2).
$store_words = array( 'ErrorCode' => '37', 'LongMessage' => 'Input data for tag <Item.Storefront.StoreCategoryID> is invalid.', 'SeverityCode' => 'Error' );
$made_cases  = array(
	'an ItemID on the Failure'           => new WP_Error( 'api_error', 'Store category.', array( 'Ack' => 'Failure', 'Errors' => $store_words, 'ItemID' => '110000000999' ) ),
	'DuplicateInvocationDetails'         => new WP_Error( 'api_error', 'Store category.', array( 'Ack' => 'Failure', 'Errors' => $store_words, 'DuplicateInvocationDetails' => array( 'Status' => 'Success' ) ) ),
	'a 488 entry'                        => refusal_with( array( array( 'ErrorCode' => '488', 'LongMessage' => 'Store category was already sent.', 'SeverityCode' => 'Error' ) ) ),
	'a 488 entry among store words'      => refusal_with( array( $store_words, array( 'ErrorCode' => '488', 'LongMessage' => 'Store category was already sent.', 'SeverityCode' => 'Error' ) ) ),
	'a message naming the UUID'          => refusal_with( array( array( 'ErrorCode' => '37', 'LongMessage' => 'Store category: the UUID was used before.', 'SeverityCode' => 'Error' ) ) ),
);
foreach ( $made_cases as $label => $answer ) {
	check( 'refused_for(): false for ' . $label, TCGiant_Sync_Shop_Categories::refused_for( $answer ), false );

	refusing_shop();
	$GLOBALS['API']->queue['AddFixedPriceItem'] = array( $answer, array( 'Ack' => 'Success', 'ItemID' => '110000000630' ) );
	$result = push( shop_product( 630, array( $t_a ) ) );
	check( '  a new listing: one Add, nothing held, and the push says eBay\'s words', array( calls_named( 'AddFixedPriceItem' ), is_held( '9011' ), is_wp_error( $result ) ), array( 1, false, true ) );
}

// The same request sent twice: eBay may have the first, whatever it said last.
refusing_shop();
$GLOBALS['API']->resent['AddFixedPriceItem'] = array( 1 );
$GLOBALS['API']->queue['AddFixedPriceItem']  = array( storefront_refusal(), array( 'Ack' => 'Success', 'ItemID' => '110000000631' ) );
$result = push( shop_product( 631, array( $t_a ) ) );
check( 'an Add that went out twice, then refused for its shop category: not sent again', array( calls_named( 'AddFixedPriceItem' ), is_held( '9011' ), is_wp_error( $result ) ), array( 1, false, true ) );

refusing_shop();
$GLOBALS['API']->resent['ReviseFixedPriceItem'] = array( 1 );
$GLOBALS['API']->queue['ReviseFixedPriceItem']  = array( storefront_refusal() );
$result = push( shop_product( 632, array( $t_a ), pushed_meta( '110000000632' ) ) );
check( 'a Revise that went out twice: not sent again', array( calls_named( 'ReviseFixedPriceItem' ), is_held( '9011' ), is_wp_error( $result ) ), array( 1, false, true ) );

refusing_shop();
$GLOBALS['API']->answers['ReviseItem'] = new WP_Error( 'api_error', 'The auction has already been closed.' );
$GLOBALS['API']->answers['GetItem']    = ended_answer( '110000000633', 'Chinese', array( 'StartPrice' => '37.0' ) );
$GLOBALS['API']->resent['RelistItem']  = array( 1 );
$GLOBALS['API']->queue['RelistItem']   = array( storefront_refusal(), array( 'Ack' => 'Success', 'ItemID' => '110000000733' ) );
$result = push( shop_product( 633, array( $t_a ), imported_meta( '110000000633', array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_duration' => 'Days_7', '_ebay_listing_status' => 'Ended' ) ), array( 'stock' => 1 ) ) );
check( 'a push\'s relist that went out twice: not sent again', array( calls_named( 'RelistItem' ), is_held( '9011' ), is_wp_error( $result ) ), array( 1, false, true ) );

refusing_shop();
$GLOBALS['API']->answers['ReviseItem'] = new WP_Error( 'api_error', 'The auction has already been closed.' );
$GLOBALS['API']->answers['GetItem']    = ended_answer( '110000000634', 'Chinese', array( 'StartPrice' => '37.0' ) );
$GLOBALS['API']->queue['RelistItem']   = array( new WP_Error( 'api_error', 'Store category.', array( 'Ack' => 'Failure', 'Errors' => $store_words, 'ItemID' => '110000000734' ) ) );
$result = push( shop_product( 634, array( $t_a ), imported_meta( '110000000634', array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_duration' => 'Days_7', '_ebay_listing_status' => 'Ended' ) ), array( 'stock' => 1 ) ) );
check( 'a push\'s relist with an ItemID on its Failure: not sent again', array( calls_named( 'RelistItem' ), is_held( '9011' ) ), array( 1, false ) );

refusing_shop();
$GLOBALS['API']->queue['ReviseFixedPriceItem'] = array( new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' ), storefront_refusal(), array( 'Ack' => 'Success', 'ItemID' => '110000000735' ) );
$GLOBALS['API']->resent['ReviseFixedPriceItem'] = array( 0, 1 );
$GLOBALS['API']->queue['GetItem'] = array( ended_answer( '110000000635', 'FixedPriceItem', array( 'ListingDetails' => array( 'RelistedItemID' => '110000000735' ) ) ), live_answer( '110000000735', 'FixedPriceItem' ) );
push( shop_product( 635, array( $t_a ), pushed_meta( '110000000635' ) ) );
check( 'the Update of a live relist that went out twice: not sent again', array( calls_named( 'ReviseFixedPriceItem' ), is_held( '9011' ) ), array( 2, false ) );

// The first Add went twice, eBay said a UPC was missing, and the second was
// refused for its shop category: the first may still be on eBay.
$upc_refusal = refusal_with( array( array( 'ErrorCode' => '21919301', 'LongMessage' => 'The UPC field is missing. Please add UPC to the listing.', 'SeverityCode' => 'Error' ) ) );
refusing_shop();
$GLOBALS['API']->resent['AddFixedPriceItem'] = array( 1, 0 );
$GLOBALS['API']->queue['AddFixedPriceItem']  = array( $upc_refusal, storefront_refusal(), array( 'Ack' => 'Success', 'ItemID' => '110000000636' ) );
$result = push( shop_product( 636, array( $t_a ) ) );
check( 'a UPC\'s resend after an Add that went twice: the Storefront retry does not go', array( calls_named( 'AddFixedPriceItem' ), is_wp_error( $result ) ), array( 2, true ) );

refusing_shop();
$GLOBALS['API']->queue['AddFixedPriceItem'] = array( $upc_refusal, storefront_refusal(), array( 'Ack' => 'Success', 'ItemID' => '110000000637' ) );
$result = push( shop_product( 637, array( $t_a ) ) );
check( '  and it does after a UPC\'s resend when each went once', array( calls_named( 'AddFixedPriceItem' ), $result['action'] ?? '' ), array( 3, 'created' ) );

// Only the send counts: a read that went twice before it says nothing of it.
refusing_shop();
$GLOBALS['API']->resent['GetStore']         = array( 1 );
$GLOBALS['API']->queue['AddFixedPriceItem'] = array( storefront_refusal(), array( 'Ack' => 'Success', 'ItemID' => '110000000638' ) );
$result = push( shop_product( 638, array( $t_a ) ) );
check( 'a GetStore that went out twice before the Add does not stop the retry', array( $GLOBALS['API']->resends() > 0, calls_named( 'AddFixedPriceItem' ), $result['action'] ?? '' ), array( true, 2, 'created' ) );

// A shop with no map asks the API nothing new: stand-ins have no resends().
fresh_shop();
card_shop();
$exporter_text = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-exporter.php' );
check( 'storefront_resends() asks only of an item with a Storefront', false !== strpos( $exporter_text, 'return null === $this->shop_categories_sent ? 0 : TCGiant_Sync_API::instance()->resends();' ), true );
check( 'one rule for what eBay may have made, not two', array( substr_count( $exporter_text, 'function relist_not_made' ), substr_count( (string) file_get_contents( $root . '/includes/class-tcgiant-sync-shop-categories.php' ), "'488'" ) ), array( 1, 0 ) );

// =============================================================================
section( 'EBAY REFUSES THE 0: THE CATEGORY STAYS' );

// What the live test is for: Verify never sends the 0, and an Update is the
// first anyone hears of it.
refusing_shop();
$GLOBALS['API']->queue['ReviseFixedPriceItem'] = array( second_refusal() );
$result = push( shop_product( 640, array( $t_a ), pushed_meta( '110000000640' ) ) );
$bodies = bodies_named( 'ReviseFixedPriceItem' );
check( 'refused for StoreCategory2ID: sent once more', count( $bodies ), 2 );
check( '  the first with the 0, the second with the category and no 0', array( storefront_of( $bodies[0] ), storefront_of( $bodies[1] ) ), array( $ONE0, $ONE ) );
check( '  nothing else changed', str_replace( $ONE0, $ONE, $bodies[0] ), $bodies[1] );
check( '  the Update goes through', $result['action'] ?? '', 'updated' );
check( '  no id is held', is_held( '9011' ), false );
check( '  no revise sends the 0 for an hour', array( false !== get_transient( TCGiant_Sync_Shop_Categories::NO_ZERO_TRANSIENT ), $GLOBALS['W']['expiry'][ TCGiant_Sync_Shop_Categories::NO_ZERO_TRANSIENT ] ), array( true, 3600 ) );
check( '  said in plain words, the category not blamed', logged_with( 'Push: eBay would not take the 0 sent with WC #640 to clear a listing\'s second shop category. Its shop category English Single Cards > Scarlet & Violet is not in question: the item was sent once more without the 0, and Updates go without it for an hour, so a second category already on a listing stays there. eBay said: Input data for tag <Item.Storefront.StoreCategory2ID> is invalid or missing.' ), 1 );
check( '  one warning, and the line for the category that went', array( count( logged( 'warning' ) ), logged_with( 'was sent to eBay shop category English Single Cards > Scarlet & Violet' ) ), array( 1, 1 ) );

$GLOBALS['API']->calls = array();
push( shop_product( 641, array( $t_a ), pushed_meta( '110000000641' ) ) );
check( 'the next Update: one call, no 0, its category', array( calls_named( 'ReviseFixedPriceItem' ), storefront_of( body_of( 'ReviseFixedPriceItem' ) ) ), array( 1, $ONE ) );
$GLOBALS['API']->calls = array();
push( shop_product( 642, array( $t_a ) ) );
check( 'the next new listing keeps its category', storefront_of( body_of( 'AddFixedPriceItem' ) ), $ONE );
$two = '<Storefront><StoreCategoryID>9011</StoreCategoryID><StoreCategory2ID>9012</StoreCategory2ID></Storefront>';
check( 'two ids still go as two', TCGiant_Sync_Shop_Categories::storefront_xml( array( '9011', '9012' ), true ), $two . "\n" );
delete_transient( TCGiant_Sync_Shop_Categories::NO_ZERO_TRANSIENT );
check( 'the hour over, the 0 goes again', TCGiant_Sync_Shop_Categories::storefront_xml( array( '9011' ), true ), $ONE0 . "\n" );

// Refused for the store category again: the retry of fix round 1, once.
refusing_shop();
$GLOBALS['API']->queue['ReviseFixedPriceItem'] = array( second_refusal(), storefront_refusal() );
$result = push( shop_product( 643, array( $t_a ), pushed_meta( '110000000643' ) ) );
$bodies = bodies_named( 'ReviseFixedPriceItem' );
check( 'then refused for the category: three calls, the last without a Storefront', array_map( 'storefront_of', $bodies ), array( $ONE0, $ONE, '' ) );
check( '  the Update goes through, the id held, each step said, and no "was sent to" line', array( $result['action'] ?? '', is_held( '9011' ), count( logged( 'warning' ) ), logged_with( 'was sent to eBay shop' ) ), array( 'updated', true, 2, 0 ) );

refusing_shop();
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = second_refusal();
$result = push( shop_product( 644, array( $t_a ), pushed_meta( '110000000644' ) ) );
check( 'refused every time: three calls, and no more', array( calls_named( 'ReviseFixedPriceItem' ), is_wp_error( $result ) ), array( 3, true ) );

refusing_shop();
$GLOBALS['API']->queue['ReviseFixedPriceItem'] = array( second_refusal(), refusal_with( array( array( 'ErrorCode' => '37', 'LongMessage' => 'The title is too long.', 'SeverityCode' => 'Error' ) ) ) );
$result = push( shop_product( 645, array( $t_a ), pushed_meta( '110000000645' ) ) );
check( 'then refused for something else: two calls, eBay\'s words, nothing held', array( calls_named( 'ReviseFixedPriceItem' ), is_wp_error( $result ) ? $result->get_error_message() : '', is_held( '9011' ) ), array( 2, 'The title is too long.', false ) );

refusing_shop();
$GLOBALS['API']->resent['ReviseFixedPriceItem'] = array( 0, 1 );
$GLOBALS['API']->queue['ReviseFixedPriceItem']  = array( second_refusal(), storefront_refusal(), array( 'Ack' => 'Success', 'ItemID' => '110000000646' ) );
$result = push( shop_product( 646, array( $t_a ), pushed_meta( '110000000646' ) ) );
check( 'the resend went out twice, then refused: no third call', array( calls_named( 'ReviseFixedPriceItem' ), is_held( '9011' ), is_wp_error( $result ) ), array( 2, false, true ) );

refusing_shop();
$GLOBALS['API']->resent['ReviseFixedPriceItem'] = array( 1 );
$GLOBALS['API']->queue['ReviseFixedPriceItem']  = array( second_refusal() );
$result = push( shop_product( 647, array( $t_a ), pushed_meta( '110000000647' ) ) );
check( 'the first went out twice: no resend at all, and the 0 not stopped', array( calls_named( 'ReviseFixedPriceItem' ), false !== get_transient( TCGiant_Sync_Shop_Categories::NO_ZERO_TRANSIENT ) ), array( 1, false ) );

refusing_shop();
$GLOBALS['API']->queue['ReviseFixedPriceItem'] = array( new WP_Error( 'api_error', 'Store category.', array( 'Ack' => 'Failure', 'ItemID' => '110000000648', 'Errors' => array( 'ErrorCode' => '37', 'LongMessage' => 'Input data for tag <Item.Storefront.StoreCategory2ID> is invalid.', 'SeverityCode' => 'Error' ) ) ) );
push( shop_product( 648, array( $t_a ), pushed_meta( '110000000648' ) ) );
check( 'an ItemID on it: no resend', calls_named( 'ReviseFixedPriceItem' ), 1 );

// A real second id is another thing: it is the category eBay refused.
refusing_shop();
$GLOBALS['API']->queue['ReviseFixedPriceItem'] = array( second_refusal() );
$result = push( shop_product( 650, array( $t_a, $t_b ), pushed_meta( '110000000650' ) ) );
$bodies = bodies_named( 'ReviseFixedPriceItem' );
check( 'two ids, the second refused: no 0 was sent, so the Storefront goes, and both are held', array( array_map( 'storefront_of', $bodies ), is_held( '9011' ), is_held( '9012' ), false !== get_transient( TCGiant_Sync_Shop_Categories::NO_ZERO_TRANSIENT ) ), array( array( $two, '' ), true, true, false ) );

refusing_shop();
$GLOBALS['API']->queue['AddFixedPriceItem'] = array( second_refusal() );
push( shop_product( 651, array( $t_a ) ) );
check( 'a new listing has no 0 to leave out: the Storefront goes, as before', array( array_map( 'storefront_of', bodies_named( 'AddFixedPriceItem' ) ), is_held( '9011' ) ), array( array( $ONE, '' ), true ) );

refusing_shop();
$GLOBALS['API']->queue['ReviseFixedPriceItem'] = array( refusal_with( array(
	array( 'ErrorCode' => '37', 'LongMessage' => 'Input data for tag <Item.Storefront.StoreCategory2ID> is invalid.', 'SeverityCode' => 'Error' ),
	array( 'ErrorCode' => '37', 'LongMessage' => 'Input data for tag <Item.Storefront.StoreCategoryID> is invalid.', 'SeverityCode' => 'Error' ),
) ) );
push( shop_product( 652, array( $t_a ), pushed_meta( '110000000652' ) ) );
check( 'both named: the category too, so the Storefront goes and it is held', array( array_map( 'storefront_of', bodies_named( 'ReviseFixedPriceItem' ) ), is_held( '9011' ), false !== get_transient( TCGiant_Sync_Shop_Categories::NO_ZERO_TRANSIENT ) ), array( array( $ONE0, '' ), true, false ) );

refusing_shop();
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = refusal_with( array(
	array( 'ErrorCode' => '37', 'LongMessage' => 'Input data for tag <Item.Storefront.StoreCategory2ID> is invalid.', 'SeverityCode' => 'Error' ),
	array( 'ErrorCode' => '21', 'LongMessage' => 'Your payments are on hold.', 'SeverityCode' => 'Error' ),
) );
push( shop_product( 653, array( $t_a ), pushed_meta( '110000000653' ) ) );
check( 'another Error beside it: not sent again', array( calls_named( 'ReviseFixedPriceItem' ), false !== get_transient( TCGiant_Sync_Shop_Categories::NO_ZERO_TRANSIENT ) ), array( 1, false ) );

check( 'refused_second(): a Warning beside it does not count', TCGiant_Sync_Shop_Categories::refused_second( refusal_with( array(
	array( 'LongMessage' => 'Input data for tag <Item.Storefront.StoreCategory2ID> is invalid.', 'SeverityCode' => 'Error' ),
	array( 'LongMessage' => 'Funds may be unavailable.', 'SeverityCode' => 'Warning' ),
) ) ), true );
check( '  nor is an answer that took the item one', TCGiant_Sync_Shop_Categories::refused_second( array( 'Ack' => 'Success' ) ), false );

// A push's relist carries the 0 too.
refusing_shop();
$GLOBALS['API']->answers['ReviseItem'] = new WP_Error( 'api_error', 'The auction has already been closed.' );
$GLOBALS['API']->answers['GetItem']    = ended_answer( '110000000654', 'Chinese', array( 'StartPrice' => '37.0' ) );
$GLOBALS['API']->answers['RelistItem'] = array( 'Ack' => 'Success', 'ItemID' => '110000000754' );
$GLOBALS['API']->queue['RelistItem']   = array( second_refusal() );
$result = push( shop_product( 654, array( $t_a ), imported_meta( '110000000654', array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_duration' => 'Days_7', '_ebay_listing_status' => 'Ended' ) ), array( 'stock' => 1 ) ) );
check( 'a push\'s relist refused for the 0: relisted with its category, nothing held', array( array_map( 'storefront_of', bodies_named( 'RelistItem' ) ), $result['action'] ?? '', is_held( '9011' ) ), array( array( $ONE0, $ONE ), 'relisted', false ) );

check( 'without_storefront(): another in its place, the last one in the item', TCGiant_Sync_Shop_Categories::without_storefront( 'AxBxC', 'x', 'y' ), 'AxByC' );
check( 'the readme says no more than the push does', false !== strpos( (string) file_get_contents( $root . '/readme.txt' ), 'never stops a push' ), false );

// =============================================================================
section( 'THE WORD STOREFRONT ALONE NAMES NO CATEGORY' );

$words_of = function ( $long, $severity = 'Error' ) {
	return refusal_with( array( array( 'ErrorCode' => '37', 'LongMessage' => $long, 'SeverityCode' => $severity ) ) );
};
foreach ( array( 'Input data for tag <Item.Storefront.StoreCategoryID> is invalid.', 'Item.Storefront.StoreURL is wrong.', 'The StoreCategory2ID is wrong.', 'Invalid store category.', 'Those store categories are gone.' ) as $words ) {
	check( 'counts: ' . $words, TCGiant_Sync_Shop_Categories::refused_for( $words_of( $words ) ), true );
}
foreach ( array( 'The title "Storefront Sale" holds a word eBay does not allow.', 'Your storefront subscription has lapsed.', 'Item.Title is wrong.' ) as $words ) {
	check( 'does not: ' . $words, TCGiant_Sync_Shop_Categories::refused_for( $words_of( $words ) ), false );
}
check( 'the same for a Warning: eBay\'s words on a category are kept', TCGiant_Sync_Shop_Categories::warnings_in( array( 'Errors' => array(
	array( 'LongMessage' => 'Invalid store category.', 'SeverityCode' => 'Warning' ),
	array( 'LongMessage' => 'Your storefront subscription has lapsed.', 'SeverityCode' => 'Warning' ),
	array( 'ErrorCode' => '20169', 'LongMessage' => 'Not leaf.', 'SeverityCode' => 'Warning' ),
) ) ), array( 'Invalid store category.', 'Not leaf.' ) );

refusing_shop();
$GLOBALS['API']->answers['AddFixedPriceItem'] = $words_of( 'The title "Storefront Sale" holds a word eBay does not allow.' );
push( shop_product( 660, array( $t_a ) ) );
check( 'a title holding the word: one call, and a good id not held', array( calls_named( 'AddFixedPriceItem' ), is_held( '9011' ) ), array( 1, false ) );

// =============================================================================
section( 'RELIST KEEPS THE CATEGORY THE LISTING HAS' );

/** A relist of a product, its calls with no UUID. */
function relist_calls( $source, WC_Product $product, array $answer ) {
	$GLOBALS['API']->calls              = array();
	$GLOBALS['API']->answers['GetItem'] = $answer;
	exporter()->relist_ended_listing( $product->get_id(), $source );
	unset( $GLOBALS['API']->answers['GetItem'] );
	return calls_made();
}

foreach ( array( 'Bulk relist', 'Auto-Relist' ) as $source ) {
	foreach ( array( 'FixedPriceItem', 'Chinese' ) as $type ) {
		$item = '110000000' . ( 'Chinese' === $type ? '071' : '070' );
		$meta = array( '_ebay_item_id' => $item, '_ebay_listing_type' => $type, '_ebay_listing_status' => 'Ended', '_ebay_origin' => 'push' );

		fresh_shop();
		card_shop();
		$sv     = make_term( 'Scarlet' );
		$before = relist_calls( $source, shop_product( 70, array( $sv ), $meta, array( 'stock' => 1 ) ), ended_answer( $item, $type ) );

		fresh_shop();
		card_shop();
		$sv = make_term( 'Scarlet' );
		shop_map( array( $sv => '9011' ) );
		$after = relist_calls( $source, shop_product( 70, array( $sv ), $meta, array( 'stock' => 1 ) ), ended_answer( $item, $type ) );

		check( $source . ' of a ' . $type . ': with a map, byte for byte as with none', $after, $before );
		check( '  no Storefront, no GetStore, no line', array( false !== strpos( json_encode( $after ), 'Storefront' ), calls_named( 'GetStore' ), logged_with( 'shop categor' ) ), array( false, 0, 0 ) );
	}
}

// A push in the same request does not lend a relist its line.
fresh_shop();
card_shop();
$sv = make_term( 'Scarlet' );
shop_map( array( $sv => '9011' ) );
push( shop_product( 72, array( $sv ) ) );
$GLOBALS['W']['log'] = array();
relist_calls( 'Bulk relist', shop_product( 73, array( $sv ), array( '_ebay_item_id' => '110000000073', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Ended', '_ebay_origin' => 'push' ), array( 'stock' => 1 ) ), ended_answer( '110000000073', 'FixedPriceItem' ) );
check( 'a relist after a push in one request says nothing of a shop category', array( logged_with( 'relisted on eBay' ), logged_with( 'shop categor' ) ), array( 1, 0 ) );

// A push eBay refused leaves what it sent on the exporter, and a Bulk Relist
// that goes next, refused in store words, carries no Storefront to take out.
foreach ( array( 'Bulk relist', 'Auto-Relist' ) as $source ) {
	refusing_shop();
	$GLOBALS['API']->answers['AddFixedPriceItem'] = refusal_with( array( array( 'ErrorCode' => '37', 'LongMessage' => 'The title is too long.', 'SeverityCode' => 'Error' ) ) );
	push( shop_product( 74, array( $t_a ) ) );
	$GLOBALS['API']->answers['RelistFixedPriceItem'] = storefront_refusal();
	relist_calls( $source, shop_product( 75, array( $t_a ), array( '_ebay_item_id' => '110000000075', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Ended', '_ebay_origin' => 'push' ), array( 'stock' => 1 ) ), ended_answer( '110000000075', 'FixedPriceItem' ) );
	check( $source . ' refused in store words, after a refused push: not sent again, nothing held', array( calls_named( 'RelistFixedPriceItem' ), is_held( '9011' ) ), array( 1, false ) );
}

// =============================================================================
section( 'NO CALL PER LISTING' );

fresh_shop();
card_shop();
$sv = make_term( 'Scarlet' );
shop_map( array( $sv => '9011' ) );
foreach ( range( 80, 89 ) as $id ) {
	push( shop_product( $id, array( $sv ) ) );
}
check( 'ten pushes, the transient empty: one GetStore', calls_named( 'GetStore' ), 1 );
check( '  and ten listings', calls_named( 'AddFixedPriceItem' ), 10 );

fresh_shop();
store_fails();
$sv = make_term( 'Scarlet' );
shop_map( array( $sv => '9011' ) );
foreach ( range( 80, 89 ) as $id ) {
	$result = push( shop_product( $id, array( $sv ) ) );
}
check( 'GetStore failing: one try for the bulk push', calls_named( 'GetStore' ), 1 );
check( '  and the push goes on, with the stored id', array( is_array( $result ), calls_named( 'AddFixedPriceItem' ), storefront_of( body_of( 'AddFixedPriceItem' ) ) ), array( true, 10, '<Storefront><StoreCategoryID>9011</StoreCategoryID></Storefront>' ) );
check( '  said by its id, as eBay could not be asked', logged_with( 'was sent to eBay shop category 9011.' ), 10 );

fresh_shop();
$sv = make_term( 'Scarlet' );
push( shop_product( 90, array( $sv ) ) );
push( shop_product( 91, array( $sv ), pushed_meta( '110000000091' ) ) );
check( 'a shop with no map: no GetStore', calls_named( 'GetStore' ), 0 );
check( '  and no Storefront, no line', array( storefront_of( body_of( 'AddFixedPriceItem' ) ), storefront_of( body_of( 'ReviseFixedPriceItem' ) ), logged_with( 'shop categor' ) ), array( '', '', 0 ) );

// =============================================================================
section( 'WHAT A PUSH SAYS' );

fresh_shop();
card_shop();
$en = make_term( 'English' );
$sv = make_term( 'Scarlet', $en );
$ss = make_term( 'Sword', $en );
shop_map( array( $sv => '9011', $ss => '9012' ) );
push( shop_product( 100, array( $sv ) ) );
check( 'one line, by path', logged_with( 'Push: eBay listing 110000000001 of WC #100 was sent to eBay shop category English Single Cards > Scarlet & Violet.' ), 1 );
$GLOBALS['W']['log'] = array();
push( shop_product( 101, array( $sv, $ss ) ) );
check( 'two, one line', logged_with( 'was sent to eBay shop categories English Single Cards > Scarlet & Violet and English Single Cards > Sword & Shield.' ), 1 );

// A refusal says nothing went.
$GLOBALS['W']['log'] = array();
$GLOBALS['API']->answers['AddFixedPriceItem'] = new WP_Error( 'api_error', 'No.' );
push( shop_product( 102, array( $sv ) ) );
unset( $GLOBALS['API']->answers['AddFixedPriceItem'] );
check( 'eBay refuses the push: no line', logged_with( 'was sent to eBay shop' ), 0 );

// Left out.
fresh_shop();
card_shop();
$t1 = make_term( 'One' );
$t2 = make_term( 'Two' );
shop_map( array( $t1 => '9001', $t2 => '424242' ) );
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$GLOBALS['API']->answers['GetItem']              = ended_answer( '110000000103', 'FixedPriceItem' );
push( shop_product( 103, array( $t1, $t2 ), pushed_meta( '110000000103' ) ) );
check( 'a mapped id left out: one warning, over the several builds of a push', count( array_filter( logged( 'warning' ), function ( $line ) {
	return false !== strpos( $line, 'leaves out' );
} ) ), 1 );
check( '  saying why, and where to go', logged_with( 'Push: WC #103 leaves out eBay shop category English Single Cards (it has subcategories, so eBay would put the listing in Other); 424242 (your eBay shop no longer has it). Choose another on TCGiant Sync, Shop Categories.' ), 1 );
check( '  and the push went on, with no Storefront', array( calls_named( 'AddFixedPriceItem' ), storefront_of( body_of( 'AddFixedPriceItem' ) ), logged_with( 'was sent to eBay shop' ) ), array( 1, '', 0 ) );
unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'], $GLOBALS['API']->answers['GetItem'] );

$GLOBALS['W']['log'] = array();
$product = shop_product( 104, array( $t1 ) );
item_xml( $product, exporter()->get_export_settings( 104 ) );
check( 'Verify says it in its own words', logged_with( 'Verify: WC #104 leaves out' ), 1 );

$GLOBALS['W']['log'] = array();
shop_map( array( $t1 => '9001' ) );
push( shop_product( 105, array( $t1 ), imported_meta( '110000000105' ) ) );
check( 'an imported listing that keeps eBay\'s values says nothing of it', logged_with( 'leaves out' ), 0 );

// eBay's Warning.
$warning = array( 'ShortMessage' => 'Invalid store category ID.', 'LongMessage' => 'Invalid store category ID, 9011, since it is non-leaf. So item has been listed to the Other store category.', 'ErrorCode' => '20169', 'SeverityCode' => 'Warning', 'ErrorClassification' => 'RequestError' );
$unrelated = array( 'ShortMessage' => 'Funds from your sales may be unavailable.', 'LongMessage' => 'Funds from your sales may be unavailable and not appear in your account.', 'ErrorCode' => '21917236', 'SeverityCode' => 'Warning' );

check( 'one Warning', TCGiant_Sync_Shop_Categories::warnings_in( array( 'Ack' => 'Warning', 'Errors' => $warning ) ), array( $warning['LongMessage'] ) );
check( 'in a list, the others left', TCGiant_Sync_Shop_Categories::warnings_in( array( 'Ack' => 'Warning', 'Errors' => array( $unrelated, $warning ) ) ), array( $warning['LongMessage'] ) );
check( 'by its code alone', TCGiant_Sync_Shop_Categories::warnings_in( array( 'Errors' => array( 'ErrorCode' => '20169', 'SeverityCode' => 'Warning', 'LongMessage' => 'Something else.' ) ) ), array( 'Something else.' ) );
check( 'by the words alone, with another code', TCGiant_Sync_Shop_Categories::warnings_in( array( 'Errors' => array( 'ErrorCode' => '99', 'SeverityCode' => 'Warning', 'ShortMessage' => 'The Store Category was changed.' ) ) ), array( 'The Store Category was changed.' ) );
check( 'by its field\'s name', TCGiant_Sync_Shop_Categories::warnings_in( array( 'Errors' => array( 'ErrorCode' => '99', 'SeverityCode' => 'Warning', 'LongMessage' => 'Item.Storefront.StoreCategoryID was reset.' ) ) ), array( 'Item.Storefront.StoreCategoryID was reset.' ) );
check( 'by the container\'s', TCGiant_Sync_Shop_Categories::warnings_in( array( 'Errors' => array( 'ErrorCode' => '99', 'SeverityCode' => 'Warning', 'LongMessage' => 'Item.Storefront was ignored.' ) ) ), array( 'Item.Storefront was ignored.' ) );
check( 'an Error that takes the item is not a Warning', TCGiant_Sync_Shop_Categories::warnings_in( array( 'Errors' => array( 'ErrorCode' => '20169', 'SeverityCode' => 'Error', 'LongMessage' => 'Store category.' ) ) ), array() );
check( 'another Warning: nothing', TCGiant_Sync_Shop_Categories::warnings_in( array( 'Errors' => $unrelated ) ), array() );
check( 'none, or no answer: nothing', array( TCGiant_Sync_Shop_Categories::warnings_in( array( 'Ack' => 'Success' ) ), TCGiant_Sync_Shop_Categories::warnings_in( 'nonsense' ), TCGiant_Sync_Shop_Categories::warnings_in( array( 'Errors' => 'nonsense' ) ) ), array( array(), array(), array() ) );

fresh_shop();
card_shop();
$sv = make_term( 'Scarlet' );
shop_map( array( $sv => '9011' ) );
$GLOBALS['API']->answers['AddFixedPriceItem'] = array( 'Ack' => 'Warning', 'ItemID' => '110000000110', 'Errors' => $warning );
$result = push( shop_product( 110, array( $sv ) ) );
check( 'eBay\'s Warning on an Add: said with eBay\'s words', logged_with( 'Push: eBay took listing 110000000110 of WC #110 but warned about its shop category, so check it is not in Other in your eBay shop. eBay said: ' . $warning['LongMessage'] ), 1 );
check( '  as a warning', count( logged( 'warning' ) ), 1 );
check( '  and nothing else changes: the listing is made', array( $result['action'], $result['item_id'] ), array( 'created', '110000000110' ) );

$GLOBALS['W']['log'] = array();
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = array( 'Ack' => 'Warning', 'ItemID' => '110000000111', 'Errors' => array( $unrelated, $warning ) );
$result = push( shop_product( 111, array( $sv ), pushed_meta( '110000000111' ) ) );
check( 'on a Revise, in a list', array( logged_with( 'but warned about its shop category' ), $result['action'] ), array( 1, 'updated' ) );

$GLOBALS['W']['log'] = array();
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = array( 'Ack' => 'Warning', 'ItemID' => '110000000112', 'Errors' => $warning );
push( shop_product( 112, array( $sv ), imported_meta( '110000000112' ) ) );
check( 'with no Storefront sent, a Warning is not read', logged_with( 'but warned about its shop category' ), 0 );
unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'], $GLOBALS['API']->answers['AddFixedPriceItem'] );

// =============================================================================
section( 'A SHOP WITH NO MAP IMPORTS AS 3.32.1 DID' );

fresh_shop();
store_with( custom_category( '9001', 'Cards', child_category( '9011', 'Holo & Foil' ) ) . custom_category( '9003', 'Sealed' ) );
check( 'no term: a new top-level term', imported_into( '110000000200', '9003' ), array( 'Sealed' ) );
check( 'a child no term matches: a new top-level term too, and no parent made', imported_into( '110000000201', '9011' ), array( 'Holo &amp; Foil' ) );
check( '  those are the only terms', count( $GLOBALS['W']['terms'] ), 2 );
check( 'the name again: the same term', imported_into( '110000000202', '9011' ), array( 'Holo &amp; Foil' ) );
check( '  none made', count( $GLOBALS['W']['terms'] ), 2 );

fresh_shop();
store_with( custom_category( '9003', 'Sealed' ) );
$elsewhere = make_term( 'Stuff' );
$sealed    = make_term( 'Sealed', $elsewhere );
check( 'a term of that name anywhere, by name alone', imported_into( '110000000203', '9003' ), array( 'Stuff > Sealed' ) );

fresh_shop();
store_with( custom_category( '9001', 'Cards', child_category( '9011', 'Sealed' ) ) );
$top   = make_term( 'Sealed' );
$cards = make_term( 'Cards' );
$under = make_term( 'Sealed', $cards );
check( 'by name alone, though the exact path is there', imported_into( '110000000204', '9011' ), array( 'Sealed' ) );

fresh_shop();
card_shop();
check( 'a name the eBay shop uses twice: one top-level term, as ever', array( imported_into( '110000000240', '9011' ), imported_into( '110000000241', '9021' ) ), array( array( 'Scarlet &amp; Violet' ), array( 'Scarlet &amp; Violet' ) ) );
check( '  and no parent made', count( $GLOBALS['W']['terms'] ), 1 );

fresh_shop();
card_shop();
$somewhere = make_term( 'Somewhere' );
update_option( TCGiant_Sync_Shop_Categories::MAP_OPTION, array(), false );
check( 'a saved empty map is no map', imported_into( '110000000242', '9021' ), array( 'Scarlet &amp; Violet' ) );

// The review's rows: a website with its own deeper tree, Overwrite on.
fresh_shop( array( 'overwrite_taxonomy' => '1' ) );
store_with( custom_category( '9001', 'Pokemon', child_category( '9011', 'Singles' ) ) . custom_category( '9002', 'Magic', child_category( '9021', 'Singles' ) ) );
$cards   = make_term( 'Cards' );
$pokemon = make_term( 'Pokemon', $cards );
$singles = make_term( 'Singles', $pokemon );
shop_product( 243, array( $singles ), imported_meta( '110000000243' ), array( 'sku' => 'SKU-110000000243' ) );
import( listing_of( '110000000243', '9011' ) );
check( 'a product in the website\'s own deeper tree stays there, and no parallel tree is made', array( paths_of( 243 ), count( $GLOBALS['W']['terms'] ), logged_with( 'was made' ) ), array( array( 'Cards > Pokemon > Singles' ), 3, 0 ) );

// Skips.
fresh_shop();
store_with( custom_category( '9003', 'Sealed' ) . custom_category( '9600', '' ) . custom_category( '9001', 'Pokemon', child_category( '9502', 'Other' ) ) );
$id = import( listing_of( '110000000205', '1' ) );
check( 'Other makes no term', array( paths_of( $id ), count( $GLOBALS['W']['terms'] ) ), array( array(), 0 ) );
$id = import( listing_of( '110000000206', '9600' ) );
check( 'an empty name makes no term', array( paths_of( $id ), count( $GLOBALS['W']['terms'] ) ), array( array(), 0 ) );
$id = import( listing_of( '110000000207', '424242' ) );
check( 'an id the tree does not have makes no term', array( paths_of( $id ), count( $GLOBALS['W']['terms'] ) ), array( array(), 0 ) );
$id = import( listing_of( '110000000208', '9502' ) );
check( 'a shop\'s own "Pokemon > Other", unmapped, is left out by its name, as ever', array( paths_of( $id ), count( $GLOBALS['W']['terms'] ) ), array( array(), 0 ) );

// =============================================================================
section( 'WITH A MAP, AN IMPORT KNOWS THE PARENT' );

/** A shop with a map of something else, so that the map is not empty. */
function mapped_shop( array $settings = array() ) {
	fresh_shop( $settings );
	card_shop();
	$GLOBALS['t_any'] = make_term( 'Anything' );
	shop_map( array( $GLOBALS['t_any'] => '9012' ) );
}

mapped_shop();
store_with( custom_category( '9001', 'Cards', child_category( '9011', 'Sealed' ) ) . custom_category( '9012', 'Elsewhere' ) );
$top   = make_term( 'Sealed' );
$cards = make_term( 'Cards' );
$under = make_term( 'Sealed', $cards );
check( 'the exact path, where a name match would find the other', imported_into( '110000000209', '9011' ), array( 'Cards > Sealed' ) );

fresh_shop();
card_shop();
$mine  = make_term( 'My Scarlet' );
$mine2 = make_term( 'Also Scarlet' );
shop_map( array( $mine2 => '9011', $mine => '9011' ) );
check( 'the map first', imported_into( '110000000210', '9011' ), array( 'My Scarlet' ) );
check( '  of several, the lowest term_id', count( $GLOBALS['W']['terms'] ), 2 );

shop_product( 211, array( $mine2 ), imported_meta( '110000000211' ), array( 'sku' => 'SKU-110000000211' ) );
TCGiant_Sync_OAuth::$settings['overwrite_taxonomy'] = '1';
$id = import( listing_of( '110000000211', '9011', '9003' ) );
check( '  the one the product already has, when it does', paths_of( $id ), array( 'Also Scarlet', 'Sealed' ) );

// The default category.
fresh_shop();
card_shop();
$plain = make_term( 'Uncategorized' );
$real  = make_term( 'Pokemon' );
update_option( 'default_product_cat', $plain );
shop_map( array( $real => '9011', $plain => '9011' ) );
check( 'two terms to one id, the lower WooCommerce\'s default: a new listing goes in the real one', imported_into( '110000000218', '9011' ), array( 'Pokemon' ) );
check( '  a product already in the default keeps it', TCGiant_Sync_Shop_Categories::term_for( '9011', array( $plain ) ), $plain );
shop_map( array( $plain => '9011' ) );
check( '  the default alone is used', TCGiant_Sync_Shop_Categories::term_for( '9011' ), $plain );
delete_option( 'default_product_cat' );

// With no tree.
fresh_shop();
store_fails();
$mine = make_term( 'My Scarlet' );
shop_map( array( $mine => '9011' ) );
check( 'with no tree the map still tells', imported_into( '110000000212', '9011' ), array( 'My Scarlet' ) );
shop_map( array( $mine => '1' ) );
check( '  but never for ID 1 or 0, should the option hold one', array( TCGiant_Sync_Shop_Categories::term_for( '1' ), TCGiant_Sync_Shop_Categories::term_for( '0' ) ), array( 0, 0 ) );
shop_map( array( $mine => '9011' ) );
check( '  and what it does not tell makes nothing', array( imported_into( '110000000213', '9012' ), count( $GLOBALS['W']['terms'] ) ), array( array(), 1 ) );
check( 'a new product takes what was told, of two', imported_into( '110000000219', '9011', '9012' ), array( 'My Scarlet' ) );

// An existing product, the tree down: all or nothing.
fresh_shop( array( 'overwrite_taxonomy' => '1' ) );
store_fails();
$mapped = make_term( 'Mapped' );
$second = make_term( 'SecondOnEbay' );
$only   = make_term( 'WebsiteOnly' );
shop_map( array( $mapped => '9011' ) );
shop_product( 250, array( $mapped, $second, $only ), imported_meta( '110000000250' ), array( 'sku' => 'SKU-110000000250' ) );
import( listing_of( '110000000250', '9011', '9021' ) );
check( 'with no tree an existing product told in part keeps what it has', paths_of( 250 ), array( 'Mapped', 'SecondOnEbay', 'WebsiteOnly' ) );

shop_product( 251, array( $second ), imported_meta( '110000000251' ), array( 'sku' => 'SKU-110000000251' ) );
import( listing_of( '110000000251', '9011' ) );
check( '  told in full, it takes that', paths_of( 251 ), array( 'Mapped' ) );

shop_product( 252, array( $second ), imported_meta( '110000000252' ), array( 'sku' => 'SKU-110000000252' ) );
import( listing_of( '110000000252', '9011', '1' ) );
check( '  eBay\'s Other beside it needs no telling', paths_of( 252 ), array( 'Mapped' ) );

fresh_shop( array( 'overwrite_taxonomy' => '1' ) );
card_shop();
$mapped = make_term( 'Mapped' );
$only   = make_term( 'WebsiteOnly' );
shop_map( array( $mapped => '9011' ) );
shop_product( 253, array( $only ), imported_meta( '110000000253' ), array( 'sku' => 'SKU-110000000253' ) );
import( listing_of( '110000000253', '9011', '424242' ) );
check( 'with a tree, an id it does not have does not hold the rest back', paths_of( 253 ), array( 'Mapped' ) );

// "Pokemon > Other".
fresh_shop();
store_with( custom_category( '9001', 'Pokemon', child_category( '9502', 'Other' ) ) . custom_category( '9500', 'Other' ) );
$mine = make_term( 'Pokemon misc' );
$top  = make_term( 'Top other' );
shop_map( array( $mine => '9502', $top => '9500' ) );
check( 'a listing in the shop\'s own "Pokemon > Other", mapped, reaches its term', imported_into( '110000000254', '9502' ), array( 'Pokemon misc' ) );
check( '  a top-level Other, though the option names it, does not', array( imported_into( '110000000255', '9500' ), TCGiant_Sync_Shop_Categories::term_for( '9500' ) ), array( array(), 0 ) );

// Entities, and the batch.
mapped_shop();
$en  = make_term( 'English Single Cards' );
$sv  = make_term( 'Scarlet & Violet', $en );
check( 'a path with "&", stored as "&amp;"', imported_into( '110000000214', '9011' ), array( 'English Single Cards > Scarlet &amp; Violet' ) );
check( '  by the term that is there', count( $GLOBALS['W']['terms'] ), 3 );

check( 'a term made for one listing of a batch', imported_into( '110000000215', '9021' ), array( 'Japanese Single Cards > Scarlet &amp; Violet' ) );
$made = count( $GLOBALS['W']['terms'] );
check( '  is found for the next', imported_into( '110000000216', '9021' ), array( 'Japanese Single Cards > Scarlet &amp; Violet' ) );
check( '  and none made twice', count( $GLOBALS['W']['terms'] ), $made );
check( 'each level was asked under its parent, the top as 0, never anywhere', array_values( array_unique( array_map( function ( $lookup ) {
	return null === $lookup[1] ? 'anywhere' : 'under a parent';
}, $GLOBALS['W']['term_lookups'] ) ) ), array( 'under a parent' ) );
check( 'each term made is logged, by path', array( logged_with( 'Import: WooCommerce category Japanese Single Cards was made' ), logged_with( 'Import: WooCommerce category Japanese Single Cards > Scarlet & Violet was made' ) ), array( 1, 1 ) );
check( '  and nothing that was not made', logged_with( 'Import: WooCommerce category English Single Cards' ), 0 );

$mapper = new TCGiant_Sync_Mapper();
$path   = private_method( 'TCGiant_Sync_Mapper', 'make_term_path' );
check( 'levels already there are used', $path->invoke( $mapper, array( 'English Single Cards', 'Scarlet & Violet' ) ), $sv );

// wp_insert_term() refusing a level made by another request meanwhile.
$GLOBALS['W']['unseen'] = array( 'English Single Cards' );
$GLOBALS['W']['log']    = array();
check( 'one made by another request since it was asked for: WordPress names it, and it is used', $path->invoke( $mapper, array( 'English Single Cards' ) ), $en );
check( '  and it is not said to have been made here', logged_with( 'was made' ), 0 );
$GLOBALS['W']['unseen'] = array();

// A name the eBay shop has once, with a map: nothing is made under a parent.
mapped_shop();
check( 'a name used once, no term: by name alone, even with a map', imported_into( '110000000259', '9051' ), array( 'PSA 10' ) );
check( '  no parent made', count( $GLOBALS['W']['terms'] ), 2 );

// A level there in another case: WordPress would make a second, so it is asked first.
mapped_shop();
make_term( 'english single CARDS' );
check( 'a parent there in another case is the parent', imported_into( '110000000217', '9011' ), array( 'english single CARDS > Scarlet &amp; Violet' ) );
check( '  and only the child made', count( $GLOBALS['W']['terms'] ), 3 );

// An existing product makes no term under a parent.
mapped_shop( array( 'overwrite_taxonomy' => '1' ) );
$web = make_term( 'WebsiteOnly' );
shop_product( 260, array( $web ), imported_meta( '110000000260' ), array( 'sku' => 'SKU-110000000260' ) );
import( listing_of( '110000000260', '9021' ) );
check( 'an existing product is not moved to a term made for it: by name alone', array( paths_of( 260 ), count( $GLOBALS['W']['terms'] ), logged_with( 'was made' ) ), array( array( 'Scarlet &amp; Violet' ), 3, 0 ) );

mapped_shop( array( 'overwrite_taxonomy' => '1' ) );
$web = make_term( 'WebsiteOnly' );
$jp  = make_term( 'Japanese Single Cards' );
$sj  = make_term( 'Scarlet & Violet', $jp );
shop_product( 261, array( $web ), imported_meta( '110000000261' ), array( 'sku' => 'SKU-110000000261' ) );
import( listing_of( '110000000261', '9021' ) );
check( '  a path already there it may take', paths_of( 261 ), array( 'Japanese Single Cards > Scarlet &amp; Violet' ) );

// =============================================================================
section( 'SAME-NAMED SHOP CATEGORIES: TWO PARENTS, EACH WITH "Scarlet & Violet"' );

$both = array( 'English Single Cards > Scarlet &amp; Violet', 'Japanese Single Cards > Scarlet &amp; Violet' );

// No map: as 3.32.1.
fresh_shop();
card_shop();
check( 'no map, no terms: one top-level term, as 3.32.1', array( imported_into( '110000000220', '9011' )[0], imported_into( '110000000221', '9021' )[0] ), array( 'Scarlet &amp; Violet', 'Scarlet &amp; Violet' ) );
check( '  one term', count( $GLOBALS['W']['terms'] ), 1 );

fresh_shop();
card_shop();
$en = make_term( 'English Single Cards' );
$jp = make_term( 'Japanese Single Cards' );
$s1 = make_term( 'Scarlet & Violet', $en );
$s2 = make_term( 'Scarlet & Violet', $jp );
$terms = count( $GLOBALS['W']['terms'] );
check( 'no map, the terms there: by name alone, as 3.32.1', array( imported_into( '110000000222', '9011' )[0], imported_into( '110000000223', '9021' )[0] ), array( $both[0], $both[0] ) );
check( '  none made', count( $GLOBALS['W']['terms'] ), $terms );

// A map of something else, no terms: new products.
mapped_shop();
check( 'a map, no terms: each made under its own parent', array( imported_into( '110000000262', '9011' )[0], imported_into( '110000000263', '9021' )[0] ), $both );
check( '  four terms, and the map\'s', count( $GLOBALS['W']['terms'] ), 5 );
check( '  and no top-level term of that name', term_exists( 'Scarlet & Violet', 'product_cat', 0 ), null );
check( '  the second has a slug of its own', array_column( $GLOBALS['W']['terms'], 'slug' ), array( 'anything', 'english-single-cards', 'scarlet-violet', 'japanese-single-cards', 'scarlet-violet-japanese-single-cards' ) );

// A map of something else, the terms there.
mapped_shop();
$en = make_term( 'English Single Cards' );
$jp = make_term( 'Japanese Single Cards' );
$s1 = make_term( 'Scarlet & Violet', $en );
$s2 = make_term( 'Scarlet & Violet', $jp );
$terms = count( $GLOBALS['W']['terms'] );
check( 'a map, the terms there: each by its path', array( imported_into( '110000000264', '9011' )[0], imported_into( '110000000265', '9021' )[0] ), $both );
check( '  none made', count( $GLOBALS['W']['terms'] ), $terms );

// A map, one parent there and the child not.
mapped_shop();
$jp = make_term( 'Japanese Single Cards' );
$s1 = make_term( 'Scarlet & Violet' );
check( 'a map, a parent there and a top-level term of the name: the child made under its parent', imported_into( '110000000224', '9021' ), array( 'Japanese Single Cards > Scarlet &amp; Violet' ) );
check( '  the top-level term left out of it', term_path( $s1 ), 'Scarlet &amp; Violet' );

// With a map of them, terms of the shop's own.
fresh_shop();
card_shop();
$m1 = make_term( 'SV English' );
$m2 = make_term( 'SV Japanese' );
shop_map( array( $m1 => '9011', $m2 => '9021' ) );
check( 'a map of both, no terms of those names: the map', array( imported_into( '110000000225', '9011' ), imported_into( '110000000226', '9021' ) ), array( array( 'SV English' ), array( 'SV Japanese' ) ) );
check( '  none made', count( $GLOBALS['W']['terms'] ), 2 );

// With a map of one, and the terms there too.
fresh_shop();
card_shop();
$en = make_term( 'English Single Cards' );
$jp = make_term( 'Japanese Single Cards' );
$s1 = make_term( 'Scarlet & Violet', $en );
$s2 = make_term( 'Scarlet & Violet', $jp );
$m1 = make_term( 'SV English' );
shop_map( array( $m1 => '9011' ) );
check( 'a map of one, the terms there: the map, then the path', array( imported_into( '110000000227', '9011' ), imported_into( '110000000228', '9021' ) ), array( array( 'SV English' ), array( 'Japanese Single Cards > Scarlet &amp; Violet' ) ) );

// Case.
fresh_shop();
store_with( custom_category( '9001', 'A', child_category( '9011', 'Sealed Box' ) ) . custom_category( '9002', 'B', child_category( '9021', 'SEALED BOX' ) ) . custom_category( '9012', 'Elsewhere' ) );
$any = make_term( 'Anything' );
shop_map( array( $any => '9012' ) );
check( 'a name duplicated ignoring case', array( imported_into( '110000000229', '9011' ), imported_into( '110000000230', '9021' ) ), array( array( 'A > Sealed Box' ), array( 'B > SEALED BOX' ) ) );

// A push of each goes back where it came from.
fresh_shop();
card_shop();
$en = make_term( 'English Single Cards' );
$jp = make_term( 'Japanese Single Cards' );
$s1 = make_term( 'Scarlet & Violet', $en );
$s2 = make_term( 'Scarlet & Violet', $jp );
shop_map( array( $s1 => '9011', $s2 => '9021' ) );
push( shop_product( 231, array( $s2 ) ) );
check( 'pushed from the Japanese term: the Japanese shop category', storefront_of( body_of( 'AddFixedPriceItem' ) ), '<Storefront><StoreCategoryID>9021</StoreCategoryID></Storefront>' );

// =============================================================================
section( 'OVERWRITE ON: A LISTING THAT KEEPS EBAY\'S VALUES' );

/**
 * A shop with Overwrite Categories & Tags on, and an imported product of two
 * categories, one mapped.
 */
function guarded_shop() {
	fresh_shop( array( 'overwrite_taxonomy' => '1', 'import_specs_as_tags' => '1' ) );
	card_shop();
	$GLOBALS['t_sv']  = make_term( 'Scarlet' );
	$GLOBALS['t_web'] = make_term( 'On offer' );
	$GLOBALS['t_ss']  = make_term( 'Sword' );
	shop_map( array( $GLOBALS['t_sv'] => '9011', $GLOBALS['t_ss'] => '9012' ) );
	return shop_product( 300, array( $GLOBALS['t_sv'], $GLOBALS['t_web'] ), imported_meta( '110000000300' ), array( 'sku' => 'SKU-110000000300' ) );
}

/** The decisions of the product's newest import. */
function decisions( $id ) {
	$log = get_post_meta( $id, '_tcgiant_sync_log', true );
	return is_array( $log ) ? (array) ( $log[0]['decisions'] ?? array() ) : array();
}

$KEPT   = 'Categories kept: the listing is in the eBay shop categories this product maps to';
$OTHER  = 'Categories kept: the listing is in Other in the eBay shop, and this product maps to a shop category';
$LEADS  = 'Categories kept: the website leads, as this product\'s next Update sends its eBay shop category';
$TAGS   = 'Tags updated (eBay won)';
$WON    = 'Categories & Tags updated (eBay won)';

guarded_shop();
$specifics = array( 'ItemSpecifics' => array( 'NameValueList' => array( array( 'Name' => 'Game', 'Value' => 'Pokemon' ) ) ) );
import( listing_of( '110000000300', '9011', '', $specifics ) );
check( 'the listing is where the product maps: its categories kept, the unmapped one too', wc_get_product( 300 )->get_category_ids(), array( $t_sv, $t_web ) );
check( '  said in the decisions, the tags with them', array( in_array( $KEPT, decisions( 300 ), true ), in_array( $TAGS, decisions( 300 ), true ) ), array( true, true ) );
check( '  not as overwritten', in_array( $WON, decisions( 300 ), true ), false );
check( '  no term made', count( $GLOBALS['W']['terms'] ), 3 );
check( '  the tags go on as before', 1 === count( wc_get_product( 300 )->get_tag_ids() ), true );

guarded_shop();
$product = shop_product( 300, array( $t_sv, $t_ss ), imported_meta( '110000000300' ), array( 'sku' => 'SKU-110000000300' ) );
import( listing_of( '110000000300', '9011', '9012' ) );
check( 'both of the listing\'s covered: kept', wc_get_product( 300 )->get_category_ids(), array( $t_sv, $t_ss ) );

guarded_shop();
import( listing_of( '110000000300', '1' ) );
check( 'the listing in Other alone: left out, and kept', wc_get_product( 300 )->get_category_ids(), array( $t_sv, $t_web ) );
check( '  said as that, not as being where it maps', array( in_array( $OTHER, decisions( 300 ), true ), in_array( $KEPT, decisions( 300 ), true ), in_array( $TAGS, decisions( 300 ), true ) ), array( true, false, true ) );

guarded_shop();
import( listing_of( '110000000300', '9011', '1' ) );
check( 'in the mapped category and Other: kept, as being where it maps', array( wc_get_product( 300 )->get_category_ids(), in_array( $KEPT, decisions( 300 ), true ) ), array( array( $t_sv, $t_web ), true ) );

guarded_shop();
import( listing_of( '110000000300', '9012' ) );
check( 'moved on eBay to a category the product does not map to: eBay wins, as before', wc_get_product( 300 )->get_category_ids(), array( $t_ss ) );
check( '  said as before', in_array( $WON, decisions( 300 ), true ), true );

guarded_shop();
import( listing_of( '110000000300', '9011', '9012' ) );
check( 'one of two not covered: eBay wins, as before', wc_get_product( 300 )->get_category_ids(), array( $t_sv, $t_ss ) );

guarded_shop();
$product = shop_product( 300, array( $t_web ), imported_meta( '110000000300' ), array( 'sku' => 'SKU-110000000300' ) );
import( listing_of( '110000000300', '9011' ) );
check( 'a product that maps to nothing: as before', wc_get_product( 300 )->get_category_ids(), array( $t_sv ) );

guarded_shop();
TCGiant_Sync_OAuth::$settings['overwrite_taxonomy'] = '';
import( listing_of( '110000000300', '9012' ) );
check( 'Overwrite off: untouched', wc_get_product( 300 )->get_category_ids(), array( $t_sv, $t_web ) );
check( '  said as before', in_array( 'Categories & Tags skipped (WooCommerce won)', decisions( 300 ), true ), true );

guarded_shop();
TCGiant_Sync_OAuth::$settings['overwrite_taxonomy'] = '';
import( listing_of( '110000000300', '9011' ) );
check( 'Overwrite off, the listing where the product maps: said as before too', array( in_array( 'Categories & Tags skipped (WooCommerce won)', decisions( 300 ), true ), in_array( $TAGS, decisions( 300 ), true ) ), array( true, false ) );

fresh_shop( array( 'overwrite_taxonomy' => '1' ) );
card_shop();
$web = make_term( 'On offer' );
shop_product( 300, array( $web ), imported_meta( '110000000300' ), array( 'sku' => 'SKU-110000000300' ) );
$item = listing_of( '110000000300', '9011' );
unset( $item['Storefront'] );
$item['PrimaryCategory']['CategoryName'] = 'Toys:Cards:Singles';
shop_map( array( $web => '9011' ) );
import( $item );
check( 'a listing that says no store category at all: the eBay category, as before', paths_of( 300 ), array( 'Singles' ) );

// L3: a mapped id that gained children since, the listing still in it.
fresh_shop( array( 'overwrite_taxonomy' => '1' ) );
card_shop();
$mapped = make_term( 'Mapped' );
$web    = make_term( 'WebsiteOnly' );
shop_map( array( $mapped => '9004' ) );
shop_product( 301, array( $mapped, $web ), imported_meta( '110000000301' ), array( 'sku' => 'SKU-110000000301' ) );
import( listing_of( '110000000301', '9004' ) );
check( 'covers() goes by what the product maps to, not what a push would send', array( paths_of( 301 ), in_array( $KEPT, decisions( 301 ), true ) ), array( array( 'Mapped', 'WebsiteOnly' ), true ) );

// =============================================================================
section( 'OVERWRITE ON: THE WEBSITE LEADS A PRODUCT THE SHOP PUSHES' );

/** A shop with Overwrite on, three mapped terms and one not. */
function leading_shop() {
	fresh_shop( array( 'overwrite_taxonomy' => '1', 'import_specs_as_tags' => '1' ) );
	card_shop();
	$GLOBALS['t_a']   = make_term( 'A' );
	$GLOBALS['t_b']   = make_term( 'B' );
	$GLOBALS['t_c']   = make_term( 'C' );
	$GLOBALS['t_web'] = make_term( 'WebsiteOnly' );
	shop_map( array( $GLOBALS['t_a'] => '9011', $GLOBALS['t_b'] => '9012', $GLOBALS['t_c'] => '9021' ) );
}

// The review's M4: moved on the website, and a sync before the Update.
leading_shop();
shop_product( 310, array( $t_c ), pushed_meta( '110000000310' ), array( 'sku' => 'SKU-110000000310' ) );
import( listing_of( '110000000310', '9011', '', $specifics ) );
check( 'moved on the website from A to C, the listing still in A\'s id: stays in C', paths_of( 310 ), array( 'C' ) );
check( '  in a decision of its own, the tags with it', array( in_array( $LEADS, decisions( 310 ), true ), in_array( $TAGS, decisions( 310 ), true ), in_array( $KEPT, decisions( 310 ), true ) ), array( true, true, false ) );
check( '  the tags go on', 1 === count( wc_get_product( 310 )->get_tag_ids() ), true );
check( '  no term made', count( $GLOBALS['W']['terms'] ), 4 );

// The review's S4, M3's website side.
leading_shop();
$product = shop_product( 311, array( $t_a, $t_b, $t_web ), pushed_meta( '110000000311' ), array( 'sku' => 'SKU-110000000311' ) );
push( $product );
check( 'two categories: the Update sends the two', storefront_of( body_of( 'ReviseFixedPriceItem' ) ), '<Storefront><StoreCategoryID>9011</StoreCategoryID><StoreCategory2ID>9012</StoreCategory2ID></Storefront>' );
$product->set_category_ids( array( $t_a, $t_web ) );
$GLOBALS['API']->calls = array();
push( $product );
check( 'taken out of one: the Update clears the second', storefront_of( body_of( 'ReviseFixedPriceItem' ) ), $ONE0 );
import( listing_of( '110000000311', '9011', '9012' ) );
check( '  and if eBay kept it all the same, a sync does not bring B back, or drop WebsiteOnly', paths_of( 311 ), array( 'A', 'WebsiteOnly' ) );

// The review's S5, for a pushed product with another category left to send.
leading_shop();
shop_map( array( $t_a => '9011', $t_b => '9004' ) );
shop_product( 312, array( $t_a, $t_b, $t_web ), pushed_meta( '110000000312' ), array( 'sku' => 'SKU-110000000312' ) );
import( listing_of( '110000000312', '9004' ) );
check( 'a mapped id that gained children, another still sendable: kept', paths_of( 312 ), array( 'A', 'B', 'WebsiteOnly' ) );

// Its only id can no longer be sent: its Update sends none, so covers() answers.
leading_shop();
shop_map( array( $t_b => '9004' ) );
shop_product( 313, array( $t_b, $t_web ), pushed_meta( '110000000313' ), array( 'sku' => 'SKU-110000000313' ) );
import( listing_of( '110000000313', '9004' ) );
check( 'its only id gained children: no Storefront to come, and covers() keeps it', array( paths_of( 313 ), in_array( $KEPT, decisions( 313 ), true ) ), array( array( 'B', 'WebsiteOnly' ), true ) );

// In Other, as every pushed listing is until its first Update.
leading_shop();
shop_product( 314, array( $t_a, $t_web ), pushed_meta( '110000000314' ), array( 'sku' => 'SKU-110000000314' ) );
import( listing_of( '110000000314', '1' ) );
check( 'a pushed listing still in Other: kept, in the website\'s words', array( paths_of( 314 ), in_array( $LEADS, decisions( 314 ), true ) ), array( array( 'A', 'WebsiteOnly' ), true ) );

// The listing says no store category.
leading_shop();
shop_product( 315, array( $t_a ), pushed_meta( '110000000315' ), array( 'sku' => 'SKU-110000000315' ) );
$item = listing_of( '110000000315', '9011' );
unset( $item['Storefront'] );
import( $item );
check( 'whatever the listing says: kept, with no store category at all', paths_of( 315 ), array( 'A' ) );

// "Sends my shop defaults" on an imported listing leads as well.
leading_shop();
shop_product( 316, array( $t_c ), imported_meta( '110000000316', array( TCGiant_Sync_Ebay_Live::FOLLOW_META => '1' ) ), array( 'sku' => 'SKU-110000000316' ) );
import( listing_of( '110000000316', '9011' ) );
check( '"Sends my shop defaults" ticked: the website leads', array( paths_of( 316 ), in_array( $LEADS, decisions( 316 ), true ) ), array( array( 'C' ), true ) );

// Not for a product whose next Update sends no Storefront.
leading_shop();
shop_product( 317, array( $t_c ), pushed_meta( '110000000317', array( TCGiant_Sync_Ebay_Live::FOLLOW_META => '0' ) ), array( 'sku' => 'SKU-110000000317' ) );
import( listing_of( '110000000317', '9011' ) );
check( 'a pushed product told to keep eBay\'s values: eBay wins, as before', array( paths_of( 317 ), in_array( $LEADS, decisions( 317 ), true ) ), array( array( 'A' ), false ) );

leading_shop();
shop_product( 318, array( $t_web ), pushed_meta( '110000000318' ), array( 'sku' => 'SKU-110000000318' ) );
import( listing_of( '110000000318', '9011' ) );
check( 'a pushed product that maps to nothing: eBay wins, as before', paths_of( 318 ), array( 'A' ) );

leading_shop();
TCGiant_Sync_OAuth::$settings['overwrite_taxonomy'] = '';
shop_product( 319, array( $t_c ), pushed_meta( '110000000319' ), array( 'sku' => 'SKU-110000000319' ) );
import( listing_of( '110000000319', '9011' ) );
check( 'Overwrite off: as before', array( paths_of( 319 ), in_array( $LEADS, decisions( 319 ), true ), in_array( 'Categories & Tags skipped (WooCommerce won)', decisions( 319 ), true ) ), array( array( 'C' ), false, true ) );

// A shop with no map is untouched.
fresh_shop( array( 'overwrite_taxonomy' => '1' ) );
card_shop();
$web = make_term( 'WebsiteOnly' );
shop_product( 320, array( $web ), pushed_meta( '110000000320' ), array( 'sku' => 'SKU-110000000320' ) );
import( listing_of( '110000000320', '9003' ) );
check( 'no map: a pushed product goes as 3.32.1 sent it', array( paths_of( 320 ), in_array( $WON, decisions( 320 ), true ) ), array( array( 'Sealed' ), true ) );

// A hold is an hour: the Update still sends the category after it.
leading_shop();
shop_product( 370, array( $t_c, $t_web ), pushed_meta( '110000000370' ), array( 'sku' => 'SKU-110000000370' ) );
TCGiant_Sync_Shop_Categories::hold( array( '9021' ) );
import( listing_of( '110000000370', '9011' ) );
check( 'moved on the website, its new id held: a sync still does not move it back', paths_of( 370 ), array( 'C', 'WebsiteOnly' ) );
check( '  while a push of it leaves that id out', TCGiant_Sync_Shop_Categories::for_product( wc_get_product( 370 ) ), array() );

// =============================================================================
section( 'ROUND TRIP WITH OVERWRITE ON: PUSH, IMPORT, UPDATE, IMPORT' );

/** eBay's listing as the last push left it: its Storefront, as it was sent. */
function listing_as_sent( $item_id, $body ) {
	preg_match( '#<StoreCategoryID>(\d+)#', (string) $body, $first );
	preg_match( '#<StoreCategory2ID>(\d+)#', (string) $body, $second );
	return listing_of( $item_id, $first[1] ?? '1', $second[1] ?? '' );
}

/**
 * The whole trip, for a shop with this map.
 *
 * @param string $label  What to call it.
 * @param array  $has    The product's categories.
 * @param string $added  The Storefront a new listing carries.
 * @param string $update The one an Update carries.
 */
function round_trip( $label, array $has, $added, $update ) {
	$terms = count( $GLOBALS['W']['terms'] );

	shop_product( 400, $has, array(), array( 'sku' => 'SKU-110000000001' ) );
	exporter()->process_single_push( 400 );
	$body = body_of( 'AddFixedPriceItem' );
	check( $label . ': the push sends it', storefront_of( $body ), $added );

	import( listing_as_sent( '110000000001', $body ) );
	check( '  the first import leaves the product as it was', wc_get_product( 400 )->get_category_ids(), $has );

	exporter()->process_single_push( 400 );
	$body = body_of( 'ReviseFixedPriceItem' );
	check( '  the Update sends the same', storefront_of( $body ), $update );

	import( listing_as_sent( '110000000001', $body ) );
	check( '  the second import too', wc_get_product( 400 )->get_category_ids(), $has );
	check( '  no term made', count( $GLOBALS['W']['terms'] ), $terms );
}

/**
 * The same for an imported listing that keeps eBay's values, which the guard
 * looks after: no Storefront goes, and the listing stays where it is.
 *
 * @param string $label  What to call it.
 * @param array  $has    The product's categories.
 * @param array  $listed The shop categories the listing is in.
 */
function imported_round_trip( $label, array $has, array $listed ) {
	$terms = count( $GLOBALS['W']['terms'] );

	shop_product( 401, $has, imported_meta( '110000000401' ), array( 'sku' => 'SKU-110000000401' ) );
	import( listing_of( '110000000401', $listed[0], $listed[1] ?? '' ) );
	check( $label . ': the first import leaves the product as it was', wc_get_product( 401 )->get_category_ids(), $has );

	exporter()->process_single_push( 401 );
	check( '  the Update sends no Storefront', storefront_of( body_of( 'ReviseFixedPriceItem' ) ), '' );

	import( listing_of( '110000000401', $listed[0], $listed[1] ?? '' ) );
	check( '  the second import too', wc_get_product( 401 )->get_category_ids(), $has );
	check( '  no term made', count( $GLOBALS['W']['terms'] ), $terms );
}

fresh_shop( array( 'overwrite_taxonomy' => '1' ) );
card_shop();
$en  = make_term( 'English Single Cards' );
$sv  = make_term( 'Scarlet & Violet', $en );
$web = make_term( 'On offer' );
shop_map( array( $sv => '9011' ) );
round_trip( 'one term to one id, with an unmapped category beside it', array( $sv, $web ), $ONE, $ONE0 );
imported_round_trip( 'the same, imported', array( $sv, $web ), array( '9011' ) );

fresh_shop( array( 'overwrite_taxonomy' => '1' ) );
card_shop();
$en  = make_term( 'English Single Cards' );
$sv  = make_term( 'Scarlet & Violet', $en );
$jp  = make_term( 'Japanese Single Cards' );
$sj  = make_term( 'Scarlet & Violet', $jp );
shop_map( array( $sv => '9011', $sj => '9021' ) );
round_trip( 'one term to one id, the two same-named', array( $sj ), '<Storefront><StoreCategoryID>9021</StoreCategoryID></Storefront>', '<Storefront><StoreCategoryID>9021</StoreCategoryID><StoreCategory2ID>0</StoreCategory2ID></Storefront>' );
imported_round_trip( 'the same, imported', array( $sj ), array( '9021' ) );

fresh_shop( array( 'overwrite_taxonomy' => '1' ) );
card_shop();
$a   = make_term( 'First' );
$b   = make_term( 'Second' );
$web = make_term( 'On offer' );
shop_map( array( $a => '9011', $b => '9011' ) );
round_trip( 'two terms to one id, the product in the higher', array( $b, $web ), $ONE, $ONE0 );
imported_round_trip( 'the same, imported', array( $b, $web ), array( '9011' ) );

fresh_shop( array( 'overwrite_taxonomy' => '1' ) );
card_shop();
$a = make_term( 'First' );
$b = make_term( 'Second' );
shop_map( array( $a => '9011', $b => '9011' ) );
round_trip( 'two terms to one id, the product in both', array( $a, $b ), $ONE, $ONE0 );
imported_round_trip( 'the same, imported', array( $a, $b ), array( '9011' ) );

fresh_shop( array( 'overwrite_taxonomy' => '1' ) );
card_shop();
$p = make_term( 'Parent' );
$c = make_term( 'Child', $p );
shop_map( array( $p => '9011' ) );
round_trip( 'a parent mapped, the product in its child', array( $c ), $ONE, $ONE0 );
imported_round_trip( 'the same, imported', array( $c ), array( '9011' ) );

// The website moves the product in the middle of the trip.
fresh_shop( array( 'overwrite_taxonomy' => '1' ) );
card_shop();
$a   = make_term( 'First' );
$c   = make_term( 'Third' );
$web = make_term( 'On offer' );
shop_map( array( $a => '9011', $c => '9012' ) );
shop_product( 402, array( $a, $web ), array(), array( 'sku' => 'SKU-110000000001' ) );
exporter()->process_single_push( 402 );
$body = body_of( 'AddFixedPriceItem' );
wc_get_product( 402 )->set_category_ids( array( $c, $web ) );
import( listing_as_sent( '110000000001', $body ) );
check( 'moved on the website in mid-trip: the sync before the Update leaves it', wc_get_product( 402 )->get_category_ids(), array( $c, $web ) );
exporter()->process_single_push( 402 );
$body = body_of( 'ReviseFixedPriceItem' );
check( '  the Update sends the new category', storefront_of( $body ), '<Storefront><StoreCategoryID>9012</StoreCategoryID><StoreCategory2ID>0</StoreCategory2ID></Storefront>' );
import( listing_as_sent( '110000000001', $body ) );
check( '  and the next import leaves it', wc_get_product( 402 )->get_category_ids(), array( $c, $web ) );
check( '  no term made', count( $GLOBALS['W']['terms'] ), 3 );

// =============================================================================
section( 'THE SHOP CATEGORIES PAGE: WHAT IT SHOWS' );

TCGiant_Sync_Shop_Categories::register_admin();
check( 'its three actions are registered', array( has_action( 'admin_post_tcgiant_shop_categories_save' ), has_action( 'admin_post_tcgiant_shop_categories_refresh' ), has_action( 'admin_post_tcgiant_shop_categories_clear' ) ), array( true, true, true ) );

class TCGiant_Sync_Admin {
	public static function instance() { return new self(); }
	public function render_tabs( $tab ) { echo '<nav data-tab="' . esc_attr( $tab ) . '"></nav>'; }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
}
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . esc_attr( $action ) . '">'; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=nonce-' . $action; }

/** The page as it draws now. */
function page() {
	ob_start();
	include dirname( __DIR__ ) . '/admin/views/shop-categories.php';
	return (string) ob_get_clean();
}

/** The map as saved. */
function stored_map_now() {
	return get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION );
}

/** The JSON the page carries for its selects. */
function page_data( $html ) {
	return preg_match( '#<script type="application/json" id="tc-shop-cat-data">(.*?)</script>#s', $html, $m ) ? json_decode( $m[1], true ) : null;
}

fresh_shop();
TCGiant_Sync_OAuth::$connected = false;
card_shop();
$html = page();
check( 'not connected: a plain message, no form', array( false !== strpos( $html, 'Connect your eBay account first' ), false !== strpos( $html, '<form' ) ), array( true, false ) );
check( '  and eBay not asked', calls_named( 'GetStore' ), 0 );
TCGiant_Sync_OAuth::$connected = true;

fresh_shop();
store_fails();
$html = page();
check( 'GetStore failed: a plain message, eBay\'s words, no form', array( false !== strpos( $html, 'could not be read' ), false !== strpos( $html, 'eBay could not be reached.' ), false !== strpos( $html, '<form' ), false !== strpos( $html, '<select' ) ), array( true, true, false, false ) );

$t1 = make_term( 'One' );
shop_map( array( $t1 => '9011' ) );
$html = page();
check( 'with a map: the way out, its own form, and still no selects', array( false !== strpos( $html, '>Stop sending these choices<' ), false !== strpos( $html, 'name="action" value="' . TCGiant_Sync_Shop_Categories::CLEAR_FORM . '"' ), false !== strpos( $html, 'nonce-' . TCGiant_Sync_Shop_Categories::CLEAR_FORM ), false !== strpos( $html, 'id="tc-shop-cat-form"' ), false !== strpos( $html, '<select' ) ), array( true, true, true, false, false ) );
check( '  the page says pushes go on sending them, while eBay could not be asked', false !== strpos( $html, 'pushes go on sending them' ), true );

fresh_shop();
no_store( '319' );
$t1 = make_term( 'One' );
shop_map( array( $t1 => '9011' ) );
$html = page();
check( 'eBay says no Store: the no-store card, and the way out', array( false !== strpos( $html, 'No eBay shop categories' ), false !== strpos( $html, 'pushes send no shop category' ), false !== strpos( $html, '>Stop sending these choices<' ) ), array( true, true, true ) );

fresh_shop();
$GLOBALS['API']->answers['GetStore'] = array( 'Ack' => 'Success', 'Store' => array( 'Name' => 'Shop' ) );
$html = page();
check( 'no categories: a plain message, no form', array( false !== strpos( $html, 'No eBay shop categories' ), false !== strpos( $html, '<form' ), false !== strpos( $html, 'Stop sending' ) ), array( true, false, false ) );

fresh_shop();
store_with( '' );
$html = page();
check( 'only eBay\'s Other, which is not offered: the same', array( false !== strpos( $html, 'No eBay shop categories' ), false !== strpos( $html, '<form' ) ), array( true, false ) );

fresh_shop();
card_shop();
$en    = make_term( 'English Single Cards' );
$sv    = make_term( 'Scarlet & Violet', $en );
$sealed = make_term( 'Sealed' );
$gone  = make_term( 'Gone' );
$kids  = make_term( 'Kids' );
shop_map( array( $sv => '9011', $gone => '424242', $kids => '9001' ) );
$html = page();
$data = page_data( $html );

check( 'the tab', false !== strpos( $html, 'data-tab="shop_categories"' ), true );
check( 'a row for each category', substr_count( $html, 'data-term="' ), 5 );
check( '  by full path', false !== strpos( $html, '>English Single Cards &gt; Scarlet &amp; Violet</label>' ), true );
check( 'the first choice is None', 5 === substr_count( $html, '<option value="">None</option>' ), true );
check( 'no select carries the list: None, and the row\'s own choice', substr_count( $html, '<option ' ), 8 );
check( 'a row shows its choice', false !== strpos( $html, '<option value="9011" selected>English Single Cards &gt; Scarlet &amp; Violet</option>' ), true );
check( 'the list is in the page once', array_column( $data['options'], 'id' ), array( '9001', '9011', '9012', '9004', '9041', '9051', '9002', '9021', '9003' ) );
check( 'Other is not offered', in_array( '1', array_column( $data['options'], 'id' ), true ), false );
check( 'a category with children is disabled, with its reason', $data['options'][0], array( 'id' => '9001', 'label' => 'English Single Cards (has subcategories: eBay would put the listing in Other)', 'disabled' => true ) );
check( 'one with none is not', $data['options'][1], array( 'id' => '9011', 'label' => 'English Single Cards > Scarlet & Violet', 'disabled' => false ) );
check( 'what Match by name fills is passed as data, empty rows only', $data['suggestions'], array( (string) $sealed => '9003' ) );
check( 'a saved id that has gone: a warning on its row', false !== strpos( $html, 'Your eBay shop no longer has this category' ), true );
check( '  and it stays, shown', false !== strpos( $html, '<option value="424242" selected>424242</option>' ), true );
check( 'a saved id that now has children: a warning on its row', false !== strpos( $html, 'This category now has subcategories' ), true );
check( '  and it stays, shown', false !== strpos( $html, '<option value="9001" selected>English Single Cards</option>' ), true );
check( 'two rows warn', substr_count( $html, 'tc-shop-cat-problem' ), 2 );
check( 'the whole map goes in one hidden field', array( substr_count( $html, 'name="' . TCGiant_Sync_Shop_Categories::MAP_FIELD . '"' ), substr_count( $html, '<select' ), substr_count( $html, ' name="tc' ) ), array( 1, 5, 0 ) );
check( 'no select has a name, so none is posted', 0 === preg_match( '/<select[^>]* name=/', $html ), true );
check( 'every row goes in the field, None as "", so a row not sent can be told from one cleared', 1 === preg_match( "/selects\.forEach\( function \( select \) \{\s+map\[ select\.getAttribute\( 'data-term' \) \] = select\.value;\s+\} \);/", $html ), true );
check( 'with a nonce', false !== strpos( $html, 'nonce-' . TCGiant_Sync_Shop_Categories::SAVE_FORM ), true );
check( 'to admin_post', false !== strpos( $html, 'name="action" value="' . TCGiant_Sync_Shop_Categories::SAVE_FORM . '"' ), true );
check( 'its buttons', array( false !== strpos( $html, '>Match by name<' ), false !== strpos( $html, '>Refresh from eBay<' ), false !== strpos( $html, '>Save<' ) ), array( true, true, true ) );
check( 'the hint on what an Update moves', false !== strpos( $html, 'An Update moves a pushed listing to the mapped category, even if you moved it on eBay by hand; an imported listing that keeps eBay&#039;s values stays where it is.' ), true );
check( 'the hint on listings already on eBay, and what moving them all costs', false !== strpos( $html, 'Listings already on eBay move at their next Update, so to move them all, select them and choose Push to eBay: each one is a full Update, and uses a call of your day&#039;s allowance.' ), true );
check( 'the hint on what a map turns on for new listings', false !== strpos( $html, 'Once anything is mapped here, a new listing imported from eBay also goes by the full path of its shop category' ), true );
check( 'the hint on an existing imported product', false !== strpos( $html, 'With Overwrite Categories &amp; Tags on, an existing imported product also moves to a category at that exact path, if you have one' ), true );
check( 'the hint on what a row set to None can then do', false !== strpos( $html, 'a sync can replace the website categories of a pushed listing whose row is set to None' ), true );
check( 'no Pro gate', 0 === preg_match( '/Entitlements|is_pro|can_use/', (string) file_get_contents( $root . '/admin/views/shop-categories.php' ) ), true );

// N5: a held id on its row.
fresh_shop();
card_shop();
$t1 = make_term( 'One' );
$t2 = make_term( 'Two' );
$t3 = make_term( 'Three' );
shop_map( array( $t1 => '9011', $t2 => '9012' ) );
TCGiant_Sync_Shop_Categories::hold( array( '9011', '9013' ) );
$problems = array();
foreach ( TCGiant_Sync_Shop_Categories::screen()['rows'] as $row ) {
	$problems[ $row['path'] ] = $row['problem'];
}
check( 'a held id shows on its row, and only there', $problems, array( 'One' => 'held', 'Three' => '', 'Two' => '' ) );
$html = page();
check( '  in words that say how to lift it', array( substr_count( $html, 'tc-shop-cat-problem' ), false !== strpos( $html, 'eBay refused a listing for this category within the last hour, so listings go without it until then. Save this page, or press Refresh from eBay, to try it again now.' ) ), array( 1, true ) );
$gone = make_term( 'Gone' );
shop_map( array( $gone => '424242' ) );
TCGiant_Sync_Shop_Categories::hold( array( '424242' ) );
check( '  an id with worse to say says that', false !== strpos( page(), 'Your eBay shop no longer has this category' ), true );

// N4: no rows, no form.
fresh_shop();
card_shop();
$t1 = make_term( 'One' );
shop_map( array( $t1 => '9011' ) );
$GLOBALS['W']['no_terms'] = true;
$scr  = TCGiant_Sync_Shop_Categories::screen();
$html = page();
$GLOBALS['W']['no_terms'] = false;
check( 'WordPress lists no terms: a state of its own', array( $scr['state'], $scr['rows'], $scr['has_map'] ), array( 'no_terms', array(), true ) );
check( '  no form to post an empty map with, and no script', array( false !== strpos( $html, 'tc-shop-cat-form' ), false !== strpos( $html, TCGiant_Sync_Shop_Categories::MAP_FIELD ), false !== strpos( $html, 'tc-shop-cat-data' ) ), array( false, false, false ) );
check( '  says so, and what is safe', false !== strpos( $html, 'WordPress listed no product categories, so there is nothing to choose here. Anything already chosen stays as it was.' ), true );
check( '  with the way to try again, and the way out', array( false !== strpos( $html, '>Refresh from eBay<' ), false !== strpos( $html, 'Stop sending these choices' ) ), array( true, true ) );
check( '  the map as it was', stored_map_now(), array( $t1 => '9011' ) );

// A name that could close the script, or open a comment in it.
fresh_shop();
store_with( custom_category( '9700', 'Odd </script><b>' ) . custom_category( '9701', 'Odd <!-- <script>' ) );
make_term( 'One' );
$html = page();
check( 'a shop category\'s name cannot close the script it is carried in', array( substr_count( $html, '</script>' ), page_data( $html )['options'][1]['label'] ), array( 2, 'Odd </script><b>' ) );
check( '  nor open a comment in it', array( false !== strpos( $html, '<!--' ), page_data( $html )['options'][0]['label'] ), array( false, 'Odd <!-- <script>' ) );

// eBay's Other under another name.
fresh_shop();
$GLOBALS['API']->answers['GetStore'] = ebay_answer( '<GetStoreResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack><Store><CustomCategories>' . custom_category( '1', 'Everything else' ) . custom_category( '9701', 'Fine' ) . '</CustomCategories></Store></GetStoreResponse>' );
make_term( 'One' );
check( 'ID 1 is not offered, whatever eBay calls it', array_column( page_data( page() )['options'], 'id' ), array( '9701' ) );

fresh_shop();
store_with( custom_category( '9001', 'Pokemon', child_category( '9502', 'Other' ) ) . custom_category( '9500', 'OTHER' ) );
make_term( 'One' );
check( 'a shop\'s own "Pokemon > Other" is offered; a top-level Other is not', array_column( page_data( page() )['options'], 'label' ), array( 'Pokemon (has subcategories: eBay would put the listing in Other)', 'Pokemon > Other' ) );

check( 'the page is reached from Push to eBay', false !== strpos( (string) file_get_contents( $root . '/admin/views/export.php' ), 'TCGiant_Sync_Shop_Categories::url()' ), true );
$lint = array();
exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $root . '/admin/views/export.php' ) . ' 2>&1', $lint, $lint_code );
check( '  and that view parses', $lint_code, 0 );
$admin = (string) file_get_contents( $root . '/admin/class-tcgiant-sync-admin.php' );
check( 'a submenu page, for those who can manage the site', 1 === preg_match( "/__\( 'Shop Categories', 'tcgiant-sync' \),\s+'manage_options',\s+'tcgiant-shop-categories',/", $admin ), true );
check( 'in the tabs', false !== strpos( $admin, "'page' => 'tcgiant-shop-categories'" ), true );
check( 'with the same look as the other screens', false !== strpos( $admin, "'tcgiant-sync_page_tcgiant-shop-categories'" ), true );

// =============================================================================
section( 'SAVE NEVER LOSES A MAP BY ACCIDENT' );

class Shop_Redirect extends Exception {}
class Shop_Died extends Exception {}
function wp_safe_redirect( $url ) { throw new Shop_Redirect( $url ); }
function wp_die( $message = '' ) { throw new Shop_Died( (string) $message ); }
function check_admin_referer( $action ) {
	$GLOBALS['W']['nonce_checked'] = $action;
	if ( ! empty( $GLOBALS['W']['bad_nonce'] ) ) {
		throw new Shop_Died( 'nonce' );
	}
	return 1;
}

/** The option as it stands, to compare. */
function stored_map() {
	return serialize( get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION, 'none' ) );
}

fresh_shop();
card_shop();
$t1 = make_term( 'One' );
$t2 = make_term( 'Two' );
shop_map( array( $t1 => '9011', $t2 => '9012' ) );
$before = stored_map();

foreach ( array(
	'no field'            => null,
	'an empty field'      => '',
	'spaces'              => '   ',
	'invalid JSON'        => '{"' . $t1 . '":"9011"',
	'a number'            => '5',
	'a string'            => '"9011"',
	'true'                => 'true',
	'null'                => 'null',
	'a list of ids'       => '["9011","9012"]',
	'a list of numbers'   => '[9011]',
	'an array field'      => array( '9011' ),
) as $label => $sent ) {
	$said = TCGiant_Sync_Shop_Categories::save( $sent );
	check( 'refused: ' . $label, array( $said['saved'], $said['type'], stored_map() ), array( false, 'error', $before ) );
}
check( '  and says so', TCGiant_Sync_Shop_Categories::save( null )['text'], 'The page sent no choices. Nothing was saved: your choices are as they were.' );
check( '  and what it could not read', TCGiant_Sync_Shop_Categories::save( 'nonsense' )['text'], 'The page sent its choices in a form that could not be read. Nothing was saved: your choices are as they were.' );

store_fails();
$said = TCGiant_Sync_Shop_Categories::save( '{"' . $t1 . '":"9012"}' );
check( 'no tree to check against: refused, the map as it was', array( $said['saved'], stored_map() ), array( false, $before ) );
check( '  and says so', false !== strpos( $said['text'], 'could not read your eBay shop' ), true );
$said = TCGiant_Sync_Shop_Categories::save( '{}' );
check( 'an empty map needs no tree: a shop whose Store has gone can clear its map', array( $said['saved'], get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ) ), array( true, array() ) );
shop_map( array( $t1 => '9011', $t2 => '9012' ) );
store_with( '' );
card_shop();

$GLOBALS['W']['no_terms'] = true;
$said = TCGiant_Sync_Shop_Categories::save( '{"' . $t1 . '":"9012"}' );
$GLOBALS['W']['no_terms'] = false;
check( 'no list of terms to check against: refused, the map as it was', array( $said['saved'], stored_map() ), array( false, $before ) );

$said = TCGiant_Sync_Shop_Categories::save( '{}' );
check( 'a valid empty map is a real choice', array( $said['saved'], $said['type'], get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ) ), array( true, 'success', array() ) );
shop_map( array( $t1 => '9011' ) );
$said = TCGiant_Sync_Shop_Categories::save( '[]' );
check( '  as an empty list too', array( $said['saved'], get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ) ), array( true, array() ) );

$said = TCGiant_Sync_Shop_Categories::save( '{"' . $t1 . '":"9011","' . $t2 . '":"9012"}' );
check( 'a good map', array( $said['saved'], $said['type'], get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ) ), array( true, 'success', array( $t1 => '9011', $t2 => '9012' ) ) );
check( '  ids kept as strings', is_string( get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION )[ $t1 ] ), true );
check( '  said', $said['text'], 'Saved: 2 categories are mapped.' );

$t3 = make_term( 'Three' );
$t4 = make_term( 'Four' );
$t5 = make_term( 'Five' );
shop_map( array() );
$said = TCGiant_Sync_Shop_Categories::save( '{"' . $t1 . '":"424242","' . $t2 . '":"9001","' . $t3 . '":"1","' . $t4 . '":"9011","' . $t5 . '":"abc","99999":"9012"}' );
check( 'an id that has gone, or has children, or is Other, or is no id, or a term that has gone: not saved', get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ), array( $t4 => '9011' ) );
check( '  the rest is', $said['saved'], true );
check( '  the notice counts them', false !== strpos( $said['text'], '5 choices were not saved, and their rows keep what they had' ), true );
check( '  as a warning', $said['type'], 'warning' );

$said = TCGiant_Sync_Shop_Categories::save( '{"' . $t1 . '":9011}' );
check( 'an id sent as a number is not an id', array( get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ), false !== strpos( $said['text'], '1 choice was not saved' ) ), array( array( $t4 => '9011' ), true ) );

// Rows the page did not send, and rows it sent as None.
shop_map( array( $t1 => '9011', $t2 => '9012', $t3 => '9003' ) );
$said = TCGiant_Sync_Shop_Categories::save( '{"' . $t1 . '":"9021","' . $t2 . '":""}' );
check( 'a row sent is set, a row sent as None is cleared, a row not sent keeps what it has', get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ), array( $t1 => '9021', $t3 => '9003' ) );
check( '  and it is a plain success', $said['type'], 'success' );

shop_map( array( $t1 => '9011', 99999 => '9012' ) );
TCGiant_Sync_Shop_Categories::save( '{"' . $t2 . '":"9012"}' );
check( 'a term that has gone goes from the option at a Save', get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ), array( $t1 => '9011', $t2 => '9012' ) );

$deleted = make_term( 'Deleted' );
shop_map( array( $t1 => '9011', $t2 => '9012', $deleted => '9013' ) );
unset( $GLOBALS['W']['terms'][ $deleted ] );
$said = TCGiant_Sync_Shop_Categories::save( '{"' . $deleted . '":"","' . $t1 . '":"9011","' . $t2 . '":"9012"}' );
check( 'a None row of a term deleted while the page was open is not "not saved"', array( get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ), $said['type'], false !== strpos( $said['text'], 'not saved' ) ), array( array( $t1 => '9011', $t2 => '9012' ), 'success', false ) );

shop_map( array( $t1 => '9011', $t2 => '9012' ) );
$said = TCGiant_Sync_Shop_Categories::save( '{"' . $t1 . '":"424242","' . $t2 . '":"9012"}' );
check( 'a good row changed to an id that has gone keeps its good choice', get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ), array( $t1 => '9011', $t2 => '9012' ) );
check( '  and is counted', false !== strpos( $said['text'], '1 choice was not saved, and its row keeps what it had' ), true );

shop_map( array( $t1 => '9011', $t2 => '9012' ) );
TCGiant_Sync_Shop_Categories::save( '{"' . $t1 . '":"","' . $t2 . '":""}' );
check( 'every row set to None clears them all, as the page sends it', get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ), array() );

// A row whose saved id has since gone stays until it is changed.
shop_map( array( $t1 => '424242', $t2 => '9001', $t3 => '9012' ) );
$said = TCGiant_Sync_Shop_Categories::save( '{"' . $t1 . '":"424242","' . $t2 . '":"9001","' . $t3 . '":"9012"}' );
check( 'rows whose saved ids went bad, unchanged: they stay', get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ), array( $t1 => '424242', $t2 => '9001', $t3 => '9012' ) );
check( '  not counted as refused', false !== strpos( $said['text'], 'not saved' ), false );
check( '  but said', false !== strpos( $said['text'], '2 rows still point at shop categories that cannot be used' ), true );
$said = TCGiant_Sync_Shop_Categories::save( '{"' . $t1 . '":"9011","' . $t2 . '":"","' . $t3 . '":"9012"}' );
check( 'changed, one to a good id and one to None: gone', get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ), array( $t1 => '9011', $t3 => '9012' ) );
shop_map( array( $t1 => '424242' ) );
$said = TCGiant_Sync_Shop_Categories::save( '{"' . $t1 . '":"9001"}' );
check( 'changed to another bad id: not saved, counted, and the row keeps what it had', array( get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ), false !== strpos( $said['text'], '1 choice was not saved' ) ), array( array( $t1 => '424242' ), true ) );

// The handler.
fresh_shop();
card_shop();
$t1 = make_term( 'One' );
shop_map( array( $t1 => '9012' ) );
$before = stored_map();

/** A press of Save: where it went, or what stopped it. */
function press_save() {
	try {
		TCGiant_Sync_Shop_Categories::handle_save();
	} catch ( Shop_Redirect $back ) {
		return $back->getMessage();
	} catch ( Shop_Died $died ) {
		return 'died: ' . $died->getMessage();
	}
	return 'fell through';
}

$_POST = array( TCGiant_Sync_Shop_Categories::MAP_FIELD => wp_slash( '{"' . $t1 . '":"9011"}' ) );
$GLOBALS['W']['bad_nonce'] = true;
check( 'a bad nonce stops Save', array( press_save(), stored_map() ), array( 'died: nonce', $before ) );
$GLOBALS['W']['bad_nonce'] = false;

$GLOBALS['W']['caps']['manage_options'] = false;
check( 'no manage_options stops Save', array( press_save(), stored_map() ), array( 'died: Unauthorized.', $before ) );
unset( $GLOBALS['W']['caps']['manage_options'] );

check( 'Save goes back to the page', press_save(), 'https://shop.example/wp-admin/admin.php?page=tcgiant-shop-categories' );
check( '  under its own nonce', $GLOBALS['W']['nonce_checked'], TCGiant_Sync_Shop_Categories::SAVE_FORM );
check( '  having saved, through WordPress\'s slashes', get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ), array( $t1 => '9011' ) );

$_POST = array();
$before = stored_map();
press_save();
ob_start();
TCGiant_Sync_Shop_Categories::show_notice();
$notice = (string) ob_get_clean();
check( 'the field missing: the map kept, and the page says so', array( stored_map(), false !== strpos( $notice, 'notice-error' ), false !== strpos( $notice, 'Nothing was saved' ) ), array( $before, true, true ) );
ob_start();
TCGiant_Sync_Shop_Categories::show_notice();
check( '  once', ob_get_clean(), '' );

// 1,500 rows.
fresh_shop();
$categories = '';
foreach ( range( 1, 1500 ) as $n ) {
	$categories .= custom_category( (string) ( 20000000000 + $n ), 'Set ' . $n );
}
store_with( $categories );
$posted = array();
foreach ( range( 1, 1500 ) as $n ) {
	$posted[ make_term( 'Set ' . $n ) ] = (string) ( 20000000000 + $n );
}
$_POST = array( '_wpnonce' => 'x', 'action' => TCGiant_Sync_Shop_Categories::SAVE_FORM, TCGiant_Sync_Shop_Categories::MAP_FIELD => wp_slash( (string) json_encode( $posted ) ) );
press_save();
check( 'a 1,500-row map goes through', count( get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ) ), 1500 );
check( '  exactly', get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ) === $posted, true );
check( '  in one field of three posted', count( $_POST ), 3 );
$html = page();
check( '  and the page of it carries each list once', array( substr_count( $html, 'data-term="' ), count( page_data( $html )['options'] ), substr_count( $html, '<option ' ) ), array( 1500, 1500, 3000 ) );

// Refresh from eBay.
fresh_shop();
card_shop();
TCGiant_Sync_Shop_Categories::tree();
store_with( custom_category( '9800', 'New' ) );
set_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT, array( 'schema' => TCGiant_Sync_Shop_Categories::TREE_SCHEMA, 'categories' => array( '9011' => array( 'id' => '9011', 'name' => 'Old', 'parent' => '', 'levels' => array( 'Old' ), 'children' => false ) ), 'error' => '' ), 3600 );
$GLOBALS['API']->calls = array();
try {
	TCGiant_Sync_Shop_Categories::handle_refresh();
	$went = 'fell through';
} catch ( Shop_Redirect $back ) {
	$went = $back->getMessage();
}
check( 'Refresh drops the transient and reads again', array( calls_named( 'GetStore' ), isset( TCGiant_Sync_Shop_Categories::tree()['categories']['9800'] ) ), array( 1, true ) );
check( '  and goes back to the page', $went, 'https://shop.example/wp-admin/admin.php?page=tcgiant-shop-categories' );
check( '  under its own nonce', $GLOBALS['W']['nonce_checked'], TCGiant_Sync_Shop_Categories::REFRESH_LINK );

$GLOBALS['W']['caps']['manage_options'] = false;
try {
	TCGiant_Sync_Shop_Categories::handle_refresh();
	$went = 'fell through';
} catch ( Shop_Died $died ) {
	$went = 'died';
} catch ( Shop_Redirect $back ) {
	$went = 'went';
}
unset( $GLOBALS['W']['caps']['manage_options'] );
check( 'no manage_options stops Refresh', $went, 'died' );

// Refresh while eBay does not answer.
fresh_shop();
card_shop();
TCGiant_Sync_Shop_Categories::tree();
$GLOBALS['API']->answers['GetStore'] = new WP_Error( 'http_request_failed', 'eBay could not be reached.' );
try {
	TCGiant_Sync_Shop_Categories::handle_refresh();
} catch ( Shop_Redirect $back ) {
	$went = $back->getMessage();
}
ob_start();
TCGiant_Sync_Shop_Categories::show_notice();
$notice = (string) ob_get_clean();
check( 'a Refresh eBay does not answer says so', array( false !== strpos( $notice, 'notice-warning' ), false !== strpos( $notice, 'eBay could not be read (eBay could not be reached.), so this page shows the categories it read earlier.' ) ), array( true, true ) );
$made = import( listing_of( '110000000901', '9003' ) );
check( '  and an import just after it still has its categories', paths_of( $made ), array( 'Sealed' ) );

// What lifts a hold.
/** A press of Refresh from eBay. */
function press_refresh() {
	try {
		TCGiant_Sync_Shop_Categories::handle_refresh();
	} catch ( Shop_Redirect $back ) {
		return $back->getMessage();
	}
	return 'fell through';
}

/** A shop with two ids held and one not mapped held too. */
function holding_shop() {
	fresh_shop();
	card_shop();
	$GLOBALS['t_a'] = make_term( 'A' );
	$GLOBALS['t_b'] = make_term( 'B' );
	shop_map( array( $GLOBALS['t_a'] => '9011', $GLOBALS['t_b'] => '9012' ) );
	TCGiant_Sync_Shop_Categories::hold( array( '9011', '9012' ) );
}

holding_shop();
press_refresh();
check( 'a good Refresh lifts the holds', array( is_held( '9011' ), is_held( '9012' ) ), array( false, false ) );

holding_shop();
TCGiant_Sync_Shop_Categories::tree( true );
check( '  no other read of eBay does: a Full Sync\'s is not a person looking at the page', is_held( '9011' ), true );

holding_shop();
TCGiant_Sync_Shop_Categories::tree();
$GLOBALS['API']->answers['GetStore'] = new WP_Error( 'http_request_failed', 'eBay could not be reached.' );
press_refresh();
check( '  a Refresh eBay does not answer does not', is_held( '9011' ), true );

holding_shop();
no_store( '319' );
press_refresh();
check( '  nor one that says there is no Store', is_held( '9011' ), true );

holding_shop();
TCGiant_Sync_Shop_Categories::save( '{"' . $t_a . '":"9011","' . $t_b . '":"9012"}' );
check( 'a Save lifts them, the same choices or not', array( is_held( '9011' ), is_held( '9012' ) ), array( false, false ) );

holding_shop();
TCGiant_Sync_Shop_Categories::save( '{"' . $t_a . '":"9021"}' );
check( '  a row moved off a held id too', is_held( '9011' ), false );

holding_shop();
TCGiant_Sync_Shop_Categories::hold( array( '9021' ) );
TCGiant_Sync_Shop_Categories::save( '{"' . $t_a . '":"9021"}' );
check( '  and a row moved on to one', is_held( '9021' ), false );

holding_shop();
TCGiant_Sync_Shop_Categories::save( '{}' );
check( '  a Save of an empty map too', array( is_held( '9011' ), is_held( '9012' ) ), array( false, false ) );

holding_shop();
TCGiant_Sync_Shop_Categories::save( 'not json' );
check( '  a Save refused does not', is_held( '9011' ), true );

holding_shop();
$GLOBALS['W']['no_terms'] = true;
TCGiant_Sync_Shop_Categories::save( '{"' . $t_a . '":"9011"}' );
$GLOBALS['W']['no_terms'] = false;
check( '  nor one with nothing to check against', is_held( '9011' ), true );

// Stop sending these choices.
/** A press of the way out: where it went, or what stopped it. */
function press_clear() {
	try {
		TCGiant_Sync_Shop_Categories::handle_clear();
	} catch ( Shop_Redirect $back ) {
		return $back->getMessage();
	} catch ( Shop_Died $died ) {
		return 'died: ' . $died->getMessage();
	}
	return 'fell through';
}
fresh_shop();
no_store( '319' );
$t1 = make_term( 'One' );
shop_map( array( $t1 => '9011' ) );
$before = stored_map();
$GLOBALS['W']['bad_nonce'] = true;
check( 'a bad nonce stops it', array( press_clear(), stored_map() ), array( 'died: nonce', $before ) );
$GLOBALS['W']['bad_nonce'] = false;
$GLOBALS['W']['caps']['manage_options'] = false;
check( 'no manage_options stops it', array( press_clear(), stored_map() ), array( 'died: Unauthorized.', $before ) );
unset( $GLOBALS['W']['caps']['manage_options'] );
check( 'it clears the map, with no tree', array( press_clear(), get_option( TCGiant_Sync_Shop_Categories::MAP_OPTION ) ), array( 'https://shop.example/wp-admin/admin.php?page=tcgiant-shop-categories', array() ) );
check( '  under its own nonce', $GLOBALS['W']['nonce_checked'], TCGiant_Sync_Shop_Categories::CLEAR_FORM );
ob_start();
TCGiant_Sync_Shop_Categories::show_notice();
check( '  and says so', false !== strpos( (string) ob_get_clean(), 'Your choices were cleared.' ), true );

// =============================================================================
section( 'WHAT STOP WARNS OF' );

fresh_shop();
no_store( '319' );
$t1 = make_term( 'One' );
shop_map( array( $t1 => '9011' ) );
check( 'the confirm says what a sync can then do', false !== strpos( page(), 'Clear every choice on this page? With Overwrite Categories & Tags on, a sync can then replace the website categories of a pushed listing.' ), true );

// =============================================================================
section( 'THE FILTER BY NAME: THE REAL IMPORTER' );

/** The shop's Store, for the filter. */
function filter_store( $more = '' ) {
	$GLOBALS['API']->answers['GetStore'] = ebay_answer( '<GetStoreResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack><Store><CustomCategories>' . custom_category( '9001', 'Pokemon', child_category( '9011', 'Holo' ) ) . custom_category( '9002', 'Magic' ) . $more . '</CustomCategories></Store></GetStoreResponse>' );
}
$key = function () {
	return 'tcgiant_sync_cats_' . md5( TCGiant_Sync_OAuth::$settings['category_ids'] );
};

fresh_shop( array( 'category_ids' => 'POKEMON, holo , 555' ) );
filter_store();
check( 'names in any case, at any level, and numbers kept', allowed_ids(), array( '555', '9001', '9011' ) );
check( '  kept an hour', $GLOBALS['W']['expiry'][ $key() ], 3600 );
check( '  one GetStore', calls_named( 'GetStore' ), 1 );
allowed_ids();
check( '  read again from what it kept', calls_named( 'GetStore' ), 1 );

fresh_shop( array( 'category_ids' => 'Pokemon, 555' ) );
$GLOBALS['API']->answers['GetStore'] = new WP_Error( 'http_request_failed', 'eBay could not be reached.' );
check( 'eBay not answering: the numbers alone', allowed_ids(), array( '555' ) );
check( '  kept a minute, not an hour', $GLOBALS['W']['expiry'][ $key() ], 60 );

// The shop adds a category on eBay and to the filter, and presses Full Sync.
fresh_shop( array( 'category_ids' => 'Pokemon, Lorcana' ) );
filter_store();
TCGiant_Sync_Shop_Categories::tree();
filter_store( custom_category( '9003', 'Lorcana' ) );
TCGiant_Sync_Importer::refresh_category_filter( TCGiant_Sync_OAuth::$settings );
check( 'a Full Sync reads eBay afresh: a category added this hour is found', allowed_ids(), array( '9001', '9003' ) );

// Someone else's read failed in the minute before it.
fresh_shop( array( 'category_ids' => 'Pokemon' ) );
$GLOBALS['API']->answers['GetStore'] = new WP_Error( 'http_request_failed', 'eBay could not be reached.' );
TCGiant_Sync_Shop_Categories::tree();
filter_store();
TCGiant_Sync_Importer::refresh_category_filter( TCGiant_Sync_OAuth::$settings );
check( 'a failed read a moment before does not empty the filter', allowed_ids(), array( '9001' ) );

fresh_shop( array( 'category_ids' => '555, 777' ) );
filter_store();
TCGiant_Sync_Importer::refresh_category_filter( TCGiant_Sync_OAuth::$settings );
check( 'a filter of numbers alone asks eBay nothing at a Full Sync\'s start', calls_named( 'GetStore' ), 0 );
fresh_shop();
TCGiant_Sync_Importer::refresh_category_filter( TCGiant_Sync_OAuth::$settings );
check( 'nor does no filter', calls_named( 'GetStore' ), 0 );

// Numbers alone, and an account with no Store: an hour, or GetStore goes on
// every sync run (fix round 2).
fresh_shop( array( 'category_ids' => '555, 777' ) );
no_store( '319' );
check( 'a filter of numbers, and no Store: the numbers', allowed_ids(), array( '555', '777' ) );
check( '  kept an hour, as in 3.32.1', $GLOBALS['W']['expiry'][ $key() ], 3600 );
fresh_shop( array( 'category_ids' => 'Pokemon, 777' ) );
no_store( '319' );
allowed_ids();
check( '  a filter with a name in it still a minute', $GLOBALS['W']['expiry'][ $key() ], 60 );

// The Settings screen stores names, and a number can be one.
fresh_shop( array( 'category_ids' => '2024' ) );
$GLOBALS['API']->answers['GetStore'] = ebay_answer( '<GetStoreResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack><Store><CustomCategories>' . custom_category( '9900', '2024' ) . custom_category( '9901', '2023' ) . '</CustomCategories></Store></GetStoreResponse>' );
check( 'a shop category named by a year is still found by its name, as in 3.32.1', allowed_ids(), array( '2024', '9900' ) );
fresh_shop( array( 'category_ids' => 'Pokemon' ) );
filter_store();
set_transient( $key(), array( 'stale' ), 3600 );
TCGiant_Sync_Importer::refresh_category_filter( TCGiant_Sync_OAuth::$settings );
check( 'what it resolved before goes', get_transient( $key() ), false );
check( 'a Full Sync\'s start is what calls it', 1 === preg_match( '/Clear cached category ID transient[^\n]*\n\s*self::refresh_category_filter\( \$settings \);/', (string) file_get_contents( $root . '/includes/class-tcgiant-sync-importer.php' ) ), true );

// =============================================================================
section( 'A NEW EBAY ACCOUNT HAS ANOTHER STORE' );

fresh_shop();
card_shop();
TCGiant_Sync_Shop_Categories::tree();
TCGiant_Sync_Shop_Categories::forget_tree();
check( 'forget_tree() drops it', get_transient( TCGiant_Sync_Shop_Categories::TREE_TRANSIENT ), false );
check( 'connecting forgets it', 1 === preg_match( '/function save_tokens_from_relay.*?TCGiant_Sync_Shop_Categories::forget_tree\(\);.*?return true;/s', (string) file_get_contents( $root . '/includes/class-tcgiant-sync-oauth.php' ) ), true );
check( 'disconnecting forgets it', 1 === preg_match( '/function handle_disconnect.*?TCGiant_Sync_Shop_Categories::forget_tree\(\);.*?wp_safe_redirect/s', (string) file_get_contents( $root . '/admin/class-tcgiant-sync-admin.php' ) ), true );

done();
