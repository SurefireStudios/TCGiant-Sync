<?php
/**
 * Harness: a variable product's attributes as the import keeps them, and
 * its variations found again at the next import (3.32.0, audit item E3,
 * defect mapper-import_8).
 *
 * Until 3.32.0 the import built eBay's variation names as custom
 * attributes and let the 3.24 merge decide what reached the product. The
 * merge drops eBay's attribute beside a global one of the same label, and
 * with Overwrite Attributes off - the default - writes nothing to a
 * product it did not make. Each variation was then written under eBay's
 * name as a custom key (attribute_color), and matched to the product's by
 * that key (pa_color=red against color=red). So a product whose variations
 * use the shop's global Color:
 *
 *  - had every variation made again at each import, as "Any Color" - the
 *    product has no custom color attribute, so WooCommerce reads the new
 *    ones' pa_color as '' - and its own retired, private and out of stock;
 *  - and one matched by SKU had its attribute_pa_color deleted, which made
 *    it "Any Color" too;
 *  - and with Overwrite Attributes off a value eBay added was never given
 *    to the product, so its variation could not be offered.
 *
 * Now the variations' attributes are kept in step with eBay's whatever
 * Overwrite Attributes says, under the product's own attribute of that
 * label - global or not - with the global attribute's own values; a value
 * a global attribute does not have is added to it only with "Add eBay's new
 * variation values to global attributes?" at Yes. Variations are matched by
 * label and value (TCGiant_Sync_Variations::signature()), first by what eBay
 * called them at their last import, recorded on each.
 *
 * Loaded for real: TCGiant_Sync_Mapper and TCGiant_Sync_Variations, on
 * support/variation-attributes-world.php's WooCommerce. TREE=<a tree> runs
 * it against another copy of the plugin (3.30.2's, to show the fault).
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-100s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 260 ) );
	if ( ! $ok ) {
		printf( "        want %s\n", substr( str_replace( "\n", ' ', var_export( $want, true ) ), 0, 400 ) );
	}
}

require __DIR__ . '/support/variation-attributes-world.php';

/** The shop's settings, with these. */
function shop( array $settings = array() ) {
	$GLOBALS['SETTINGS'] = array_merge( array( 'overwrite_price' => '1' ), $settings );
}
/** Everything back to an empty shop with these global attributes. */
function fresh() {
	foreach ( array( 'META', 'POSTS', 'LOG', 'WARN', 'TAXONOMIES', 'TERMS', 'OBJECT_TERMS', 'TERM_WRITES', 'ATTR_SAVES' ) as $g ) {
		$GLOBALS[ $g ] = array();
	}
	$GLOBALS['NEXT_ID']   = 5000;
	$GLOBALS['NEXT_TERM'] = 100;
	shop();
}
/** The term a push names a variation's value by (TCGiant_Sync_Exporter, 3.32.0 fix round 1); 'absent' on a tree without it. */
function push_term( $value, $taxonomy ) {
	if ( ! method_exists( 'TCGiant_Sync_Exporter', 'variation_value_term' ) ) {
		return 'absent';
	}
	$method = new ReflectionMethod( 'TCGiant_Sync_Exporter', 'variation_value_term' );
	$method->setAccessible( true );
	return $method->invoke( null, $value, $taxonomy );
}
/** A variation of a product of the shop's, holding this value under this key. */
function new_variation_with( $key, $value ) {
	shop_product( 990, '5990', array( array( $key, array_values( array_map( function ( $t ) { return (int) $t->term_id; }, array_filter( $GLOBALS['TERMS'], function ( $t ) use ( $key ) { return $t->taxonomy === $key; } ) ) ), true ) ) );
	shop_variation( 991, 990, '', 1, array( $key => $value ) );
	return wc_get_product( 991 );
}
/** Published variations of a product holding the same attributes: what the website takes for one. */
function same_published( $parent ) {
	$seen = array();
	foreach ( published_of( $parent ) as $id ) {
		$seen[ json_encode( var_attrs( $id ) ) ][] = $id;
	}
	return array_values( array_filter( $seen, function ( $ids ) { return count( $ids ) > 1; } ) );
}
/** The variations' specifics as recorded at import. */
function recorded( $id ) { return meta( $id, '_ebay_variation_specifics' ); }

$src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-mapper.php' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PRODUCT WHOSE VARIATIONS USE THE SHOP'S GLOBAL COLOR (pa_color)\n" . str_repeat( '=', 124 ) . "\n";

// A tee the shop made itself, with its global Color, and pushed: eBay's
// listing calls the variations Color: Red and Color: Blue, no SKUs.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue', 'Green', 'Navy Blue', 'Black & White' ) );
shop_product( 100, '5001', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ) ) );
shop_variation( 101, 100, '', 3, array( 'pa_color' => 'red' ) );
shop_variation( 102, 100, '', 3, array( 'pa_color' => 'blue' ) );
$before_terms = terms_now();
$listing      = var_listing( '5001', array( array( array( 'Color' => 'Red' ), 2 ), array( array( 'Color' => 'Blue' ), 5 ) ) );

import( $listing );
check( 'the shop\'s two variations are found again: none made, none retired', children_of( 100 ), array( 101 => 'publish', 102 => 'publish' ) );
check( '  each keeps attribute_pa_color, its value the term\'s slug', array( var_meta( 101 ), var_meta( 102 ) ), array( array( 'attribute_pa_color' => 'red' ), array( 'attribute_pa_color' => 'blue' ) ) );
check( '  and takes eBay\'s stock', array( meta( 101, '_stock' ), meta( 102, '_stock' ) ), array( 2, 5 ) );
check( '  the product still has only its global Color, no custom one beside it', attrs_of( 100 ), array( 'pa_color' => 'pa_color: red|blue [v]' ) );
check( '  the website offers both', offered( 100 ), array( 'pa_color=blue', 'pa_color=red' ) );
check( '  eBay\'s names recorded on each', array( recorded( 101 ), recorded( 102 ) ), array( array( 'Color' => 'Red' ), array( 'Color' => 'Blue' ) ) );
check( '  no global attribute or value written', array( term_writes(), terms_now() === $before_terms ), array( array(), true ) );
check( '  and the product\'s attributes not saved at all: nothing changed', $ATTR_SAVES[100] ?? 0, 0 );
check( '  nothing said of attributes but Overwrite Attributes\' own line', array_values( array_filter( decided( 100 ), function ( $d ) { return false !== stripos( $d, 'attribute' ); } ) ), array( 'Attributes skipped (WooCommerce won)' ) );

$snapshot = array( children_of( 100 ), attrs_of( 100 ), var_meta( 101 ), var_meta( 102 ), recorded( 101 ), recorded( 102 ) );
import( $listing );
check( 'the second import changes nothing: the same variations, attributes and records', array( children_of( 100 ), attrs_of( 100 ), var_meta( 101 ), var_meta( 102 ), recorded( 101 ), recorded( 102 ) ), $snapshot );
check( '  no attribute set made twice, no value written', array( count( (array) meta( 100, '_product_attributes' ) ), term_writes() ), array( 1, array() ) );

shop( array( 'overwrite_attributes' => '1' ) );
import( $listing );
check( 'Overwrite Attributes on: the same - the global Color kept, nothing beside it', array( children_of( 100 ), attrs_of( 100 ), var_meta( 101 ) ), array( array( 101 => 'publish', 102 => 'publish' ), array( 'pa_color' => 'pa_color: red|blue [v]' ), array( 'attribute_pa_color' => 'red' ) ) );

// Matched by SKU, the variation used to be written under eBay's name, and
// WooCommerce deleted its attribute_pa_color.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 110, '5002', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ) ) );
shop_variation( 111, 110, 'TEE-R', 3, array( 'pa_color' => 'red' ) );
shop_variation( 112, 110, 'TEE-B', 3, array( 'pa_color' => 'blue' ) );
import( var_listing( '5002', array( array( array( 'Color' => 'Red' ), 1, 'TEE-R' ), array( array( 'Color' => 'Blue' ), 1, 'TEE-B' ) ) ) );
check( 'found by SKU: each still under pa_color, not "Any Color"', array( var_meta( 111 ), var_attrs( 112 ) ), array( array( 'attribute_pa_color' => 'red' ), array( 'pa_color' => 'blue' ) ) );
check( '  both offered', offered( 110 ), array( 'pa_color=blue', 'pa_color=red' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nGLOBAL AND CUSTOM ATTRIBUTES TOGETHER\n" . str_repeat( '=', 124 ) . "\n";

fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 200, '5003', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true, 0 ), array( 'Size', array( 'S', 'M' ), true, 1 ), array( 'Material', array( 'Cotton' ), false, 2 ) ) );
shop_variation( 201, 200, '', 1, array( 'pa_color' => 'red', 'size' => 'S' ) );
shop_variation( 202, 200, '', 1, array( 'pa_color' => 'blue', 'size' => 'M' ) );
$listing = var_listing( '5003', array( array( array( 'Color' => 'Red', 'Size' => 'S' ), 4 ), array( array( 'Color' => 'Blue', 'Size' => 'M' ), 6 ) ), array( 'Material' => 'Linen' ) );
import( $listing );
check( 'both found again', children_of( 200 ), array( 201 => 'publish', 202 => 'publish' ) );
check( '  each under the global Color and the custom Size', array( var_meta( 201 ), var_meta( 202 ) ), array( array( 'attribute_pa_color' => 'red', 'attribute_size' => 'S' ), array( 'attribute_pa_color' => 'blue', 'attribute_size' => 'M' ) ) );
check( '  Overwrite Attributes off: the item specific Material left as the shop has it', attrs_of( 200 ), array( 'pa_color' => 'pa_color: red|blue [v]', 'size' => 'Size: S|M [v]', 'material' => 'Material: Cotton' ) );
check( '  both offered', offered( 200 ), array( 'pa_color=blue,size=M', 'pa_color=red,size=S' ) );
$snapshot = array( children_of( 200 ), attrs_of( 200 ), var_meta( 201 ), var_meta( 202 ) );
import( $listing );
check( '  again: nothing changes', array( children_of( 200 ), attrs_of( 200 ), var_meta( 201 ), var_meta( 202 ) ), $snapshot );

shop( array( 'overwrite_attributes' => '1' ) );
import( $listing );
check( 'Overwrite Attributes on: Material takes eBay\'s value in its place (3.24); Color and Size as they were', attrs_of( 200 ), array( 'pa_color' => 'pa_color: red|blue [v]', 'size' => 'Size: S|M [v]', 'material' => 'Material: Linen' ) );
check( '  the variations as they were', array( children_of( 200 ), var_meta( 201 ) ), array( array( 201 => 'publish', 202 => 'publish' ), array( 'attribute_pa_color' => 'red', 'attribute_size' => 'S' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nOVERWRITE ATTRIBUTES OFF: A VALUE EBAY ADDED, AND A NAME THE PRODUCT LACKED\n" . str_repeat( '=', 124 ) . "\n";

// An imported product, its Size custom: S and M. eBay now sells L too.
fresh();
shop_product( 300, '5004', array( array( 'Size', array( 'S', 'M' ), true ), array( 'Brand', array( 'Acme' ), false ) ) );
shop_variation( 301, 300, '', 1, array( 'size' => 'S' ) );
shop_variation( 302, 300, '', 1, array( 'size' => 'M' ) );
import( var_listing( '5004', array( array( array( 'Size' => 'S' ), 1 ), array( array( 'Size' => 'M' ), 1 ), array( array( 'Size' => 'L' ), 3 ) ), array( 'Brand' => 'Other' ) ) );
$new = array_values( array_diff( array_keys( children_of( 300 ) ), array( 301, 302 ) ) );
check( 'L made as a new variation, S and M found again', array( count( $new ), children_of( 300 )[301], children_of( 300 )[302] ), array( 1, 'publish', 'publish' ) );
check( '  L added to the product\'s Size, nothing else of its attributes touched', attrs_of( 300 ), array( 'size' => 'Size: S|M|L [v]', 'brand' => 'Brand: Acme' ) );
check( '  so the website offers all three', offered( 300 ), array( 'size=L', 'size=M', 'size=S' ) );
check( '  and the log says why the attributes changed with Overwrite Attributes off', array_values( array_filter( decided( 300 ), function ( $d ) { return false !== stripos( $d, 'attribute' ); } ) ), array( 'Attributes skipped (WooCommerce won)', 'Attributes used for variations brought in line with eBay\'s variations: Size' ) );

// A product that has no attribute of eBay's name at all, say one the shop
// cleared, or a simple product whose listing gained variations.
fresh();
shop_product( 310, '5005', array( array( 'Brand', array( 'Acme' ), false ) ) );
import( var_listing( '5005', array( array( array( 'Style' => 'Slim' ), 1 ), array( array( 'Style' => 'Loose' ), 1 ) ) ) );
check( 'the product gains a custom Style used for variations, after its own', attrs_of( 310 ), array( 'brand' => 'Brand: Acme', 'style' => 'Style: Slim|Loose [v]' ) );
check( '  both variations offered', offered( 310 ), array( 'style=Loose', 'style=Slim' ) );
check( '  placed after the product\'s own, not over them', array( meta( 310, '_product_attributes' )['brand']['position'] ?? null, meta( 310, '_product_attributes' )['style']['position'] ?? null ), array( 0, 1 ) );
check( '  and shown on the product page, as the import has always made them', meta( 310, '_product_attributes' )['style']['visible'] ?? null, true );
fresh();
shop_product( 311, '5022', array( array( 'Brand', array( 'Acme' ), false ) ) );
import( var_listing( '5022', array( array( array( 'Style' => 'Slim', 'Fit' => 'Tall' ), 1 ), array( array( 'Style' => 'Loose', 'Fit' => 'Short' ), 1 ) ) ) );
check( 'two names the product lacked: each in a place of its own, after the product\'s', array( meta( 311, '_product_attributes' )['style']['position'] ?? null, meta( 311, '_product_attributes' )['fit']['position'] ?? null ), array( 1, 2 ) );

// Another plugin hides the global Color on view (woocommerce_product_get_attributes):
// the attributes saved back are the stored ones, so it is not deleted.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red' ) );
shop_product( 315, '5025', array( array( 'pa_color', array( $c['Red'] ), false, 0 ), array( 'Size', array( 'S' ), true, 1 ) ) );
$VIEW_FILTER = function ( $attributes ) { unset( $attributes['pa_color'] ); return $attributes; };
import( var_listing( '5025', array( array( array( 'Size' => 'S' ), 1 ), array( array( 'Size' => 'M' ), 1 ) ) ) );
$VIEW_FILTER = null;
check( 'an attribute a filter hides on view is kept when the variations\' attributes are saved', attrs_of( 315 ), array( 'pa_color' => 'pa_color: red', 'size' => 'Size: S|M [v]' ) );

// One the shop typed in another case: its own spelling is the variation's.
fresh();
shop_product( 320, '5006', array( array( 'Size', array( 'small', 'Medium' ), true ) ) );
shop_variation( 321, 320, '', 1, array( 'size' => 'small' ) );
import( var_listing( '5006', array( array( array( 'Size' => 'Small' ), 2 ), array( array( 'Size' => 'MEDIUM' ), 2 ) ) ) );
check( 'a custom value matched without regard to case: the shop\'s spelling kept, nothing added', attrs_of( 320 ), array( 'size' => 'Size: small|Medium [v]' ) );
check( '  the variations hold it, so the website offers them', offered( 320 ), array( 'size=Medium', 'size=small' ) );
fresh();
shop_product( 325, '5034', array( array( 'Size', array( 'Extra Large' ), true ) ) );
shop_variation( 326, 325, '', 1, array( 'size' => 'Extra Large' ) );
import( var_listing( '5034', array( array( array( 'Size' => 'Extra  Large' ), 2 ) ) ) );
check( 'a custom value with runs of spaces is the shop\'s: nothing added beside it', array( attrs_of( 325 ), children_of( 325 ) ), array( array( 'size' => 'Size: Extra Large [v]' ), array( 326 => 'publish' ) ) );

// Neither its custom Color nor its global one used for variations: the
// global one is taken, as the shop's structured record.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 340, '5023', array( array( 'Color', array( 'Mixed' ), false, 0 ), array( 'pa_color', array( $c['Red'], $c['Blue'] ), false, 1 ) ) );
import( var_listing( '5023', array( array( array( 'Color' => 'Red' ), 1 ), array( array( 'Color' => 'Blue' ), 1 ) ) ) );
check( 'a plain Color and a global one, neither used for variations: the global one taken, the plain one untouched', attrs_of( 340 ), array( 'color' => 'Color: Mixed', 'pa_color' => 'pa_color: red|blue [v]' ) );

// The global Color already holds every value eBay sells, and the shop's
// variations hold them, but it is not marked as used for variations: so
// WooCommerce reads them as having none. Marked, and nothing else.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 350, '5024', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), false ) ) );
shop_variation( 351, 350, '', 1, array( 'pa_color' => 'red' ) );
shop_variation( 352, 350, '', 1, array( 'pa_color' => 'blue' ) );
import( var_listing( '5024', array( array( array( 'Color' => 'Red' ), 2 ), array( array( 'Color' => 'Blue' ), 2 ) ) ) );
check( 'a global attribute that only needed marking as used for variations: marked and saved, no value added', array( attrs_of( 350 ), $ATTR_SAVES[350] ?? 0, term_writes() ), array( array( 'pa_color' => 'pa_color: red|blue [v]' ), 1, array() ) );
check( '  so the shop\'s variations are found, none made, and both offered', array( children_of( 350 ), offered( 350 ) ), array( array( 351 => 'publish', 352 => 'publish' ), array( 'pa_color=blue', 'pa_color=red' ) ) );

// The shop's product has a plain Color too - a description, not used for
// variations - and its global Color, used for them: the variations go
// under the one used for variations.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 330, '5018', array( array( 'Color', array( 'Mixed' ), false, 0 ), array( 'pa_color', array( $c['Red'], $c['Blue'] ), true, 1 ) ) );
shop_variation( 331, 330, '', 1, array( 'pa_color' => 'red' ) );
import( var_listing( '5018', array( array( array( 'Color' => 'Red' ), 1 ), array( array( 'Color' => 'Blue' ), 1 ) ) ) );
check( 'a plain Color and a global one: the variations under the global one, used for them', array( var_meta( 331 ), attrs_of( 330 ) ), array( array( 'attribute_pa_color' => 'red' ), array( 'color' => 'Color: Mixed', 'pa_color' => 'pa_color: red|blue [v]' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nLABELS AND VALUES IN ANOTHER CASE OR SPACING\n" . str_repeat( '=', 124 ) . "\n";

fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Navy Blue', 'Black & White', 'Salt & Pepper' ) );
shop_product( 400, '5007', array( array( 'pa_color', array( $c['Red'], $c['Navy Blue'], $c['Black & White'], $c['Salt & Pepper'] ), true ), array( 'Card Name', array( 'Mew' ), true ) ) );
shop_variation( 401, 400, '', 1, array( 'pa_color' => 'red', 'card-name' => 'Mew' ) );
shop_variation( 402, 400, '', 1, array( 'pa_color' => 'navy-blue', 'card-name' => 'Mew' ) );
shop_variation( 403, 400, '', 1, array( 'pa_color' => 'black-white', 'card-name' => 'Mew' ) );
shop_variation( 404, 400, '', 1, array( 'pa_color' => 'salt-pepper', 'card-name' => 'Mew' ) );
$listing = var_listing( '5007', array(
	array( array( ' COLOR ' => 'red', 'card  name' => 'MEW' ), 1 ),
	array( array( ' COLOR ' => 'Navy  Blue', 'card  name' => 'Mew' ), 1 ),
	array( array( ' COLOR ' => 'Black & White', 'card  name' => 'Mew' ), 1 ),
	array( array( ' COLOR ' => 'Salt  &  Pepper', 'card  name' => 'Mew' ), 1 ),
) );
import( $listing );
check( '" COLOR " is the global Color, "card  name" the custom Card Name: all four found again', children_of( 400 ), array( 401 => 'publish', 402 => 'publish', 403 => 'publish', 404 => 'publish' ) );
check( '  "Salt  &  Pepper", found by its slug: the term WordPress keeps as "Salt &amp; Pepper"', var_meta( 404 )['attribute_pa_color'] ?? null, 'salt-pepper' );
check( '  "Navy  Blue" is the term Navy Blue, "Black & White" the one WordPress keeps as "&amp;"', array( var_meta( 402 )['attribute_pa_color'] ?? null, var_meta( 403 )['attribute_pa_color'] ?? null ), array( 'navy-blue', 'black-white' ) );
check( '  the custom value kept in the shop\'s spelling', var_meta( 401 )['attribute_card-name'] ?? null, 'Mew' );
check( '  no attribute added beside them, no value written', array( attrs_of( 400 ), term_writes() ), array( array( 'pa_color' => 'pa_color: red|navy-blue|black-white|salt-pepper [v]', 'card-name' => 'Card Name: Mew [v]' ), array() ) );
$snapshot = array( children_of( 400 ), var_meta( 401 ), var_meta( 402 ), var_meta( 403 ), var_meta( 404 ), attrs_of( 400 ) );
import( $listing );
check( '  again: nothing changes', array( children_of( 400 ), var_meta( 401 ), var_meta( 402 ), var_meta( 403 ), var_meta( 404 ), attrs_of( 400 ) ), $snapshot );

// A slug is not a name: "1/2" gives the slug of the term "1-2" (WordPress
// makes "/" a hyphen), and is not it.
fresh();
$z = global_attribute( 'pa_size', 'Size', array( '12', '1-2' ) );
shop_product( 410, '5008', array( array( 'pa_size', array( $z['12'], $z['1-2'] ), true ) ) );
shop_variation( 411, 410, '', 1, array( 'pa_size' => '12' ) );
import( var_listing( '5008', array( array( array( 'Size' => '12' ), 1 ), array( array( 'Size' => '1/2' ), 1 ) ) ) );
$half = array_values( array_diff( array_keys( children_of( 410 ) ), array( 411 ) ) );
check( '"1/2" is not taken for the term 1-2, whose slug it gives: its own variation, holding eBay\'s value', array( count( $half ), var_meta( $half[0] ?? 0 ) ), array( 1, array( 'attribute_pa_size' => '1/2' ) ) );
check( '  12 still found, and still 12', var_meta( 411 ), array( 'attribute_pa_size' => '12' ) );
check( '  "1/2" read back as itself, not as the term 1-2: by the stock checks and by a push', array(
	TCGiant_Sync_Variations::wc_specifics( wc_get_product( $half[0] ?? 0 ), false ),
	push_term( '1/2', 'pa_size' ),
	push_term( '1-2', 'pa_size' )->name ?? null,
), array( array( 'Size' => '1/2' ), false, '1-2' ) );
// A push names a variation holding a term's slug by the term's name (S19): a
// slug that is not its name lower-cased ("light-blue"), and a renamed term's.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Light Blue', 'Crimson' => 'red' ) );
check( '  a term\'s slug is read as its name: "light-blue" as Light Blue, "red" as the renamed Crimson', array( push_term( 'light-blue', 'pa_color' )->name ?? null, push_term( 'red', 'pa_color' )->name ?? null, TCGiant_Sync_Variations::wc_specifics( new_variation_with( 'pa_color', 'light-blue' ), false ) ), array( 'Light Blue', 'Crimson', array( 'Color' => 'Light Blue' ) ) );
fresh();
$z = global_attribute( 'pa_size', 'Size', array( '12', '1-2' ) );
shop_product( 410, '5008', array( array( 'pa_size', array( $z['12'], $z['1-2'] ), true ) ) );
shop_variation( 411, 410, '', 1, array( 'pa_size' => '12' ) );
import( var_listing( '5008', array( array( array( 'Size' => '12' ), 1 ), array( array( 'Size' => '1/2' ), 1 ) ) ) );
import( var_listing( '5008', array( array( array( 'Size' => '12' ), 1 ), array( array( 'Size' => '1/2' ), 1 ) ) ) );
check( '  again: the same two, nothing made', count( children_of( 410 ) ), 2 );

// A listing made on eBay, linked by SKU: eBay's "X Large" is the shop's
// X-Large - hyphens and spaces alike (fix round 1, review M1).
fresh();
$z = global_attribute( 'pa_size', 'Size', array( 'Medium', 'X-Large' ) );
shop_product( 420, '5026', array( array( 'pa_size', array( $z['Medium'], $z['X-Large'] ), true ) ) );
shop_variation( 421, 420, 'T-M', 1, array( 'pa_size' => 'medium' ) );
shop_variation( 422, 420, 'T-XL', 1, array( 'pa_size' => 'x-large' ) );
import( var_listing( '5026', array( array( array( 'Size' => 'Medium' ), 3, 'T-M' ), array( array( 'Size' => 'X Large' ), 2, 'T-XL' ) ) ) );
check( 'eBay\'s "X Large" is the term X-Large: the variation keeps it and is still offered, no warning', array( var_meta( 422 ), offered( 420 ), $WARN ), array( array( 'attribute_pa_size' => 'x-large' ), array( 'pa_size=medium', 'pa_size=x-large' ), array() ) );

// "Wi-Fi + Cellular" on eBay, "Wi-Fi & Cellular" here: not the same name,
// but the variation of that SKU holds the shop's term, which it keeps.
fresh();
$m = global_attribute( 'pa_model', 'Model', array( 'Wi-Fi', 'Wi-Fi & Cellular' ) );
shop_product( 430, '5027', array( array( 'pa_model', array( $m['Wi-Fi'], $m['Wi-Fi & Cellular'] ), true ) ) );
shop_variation( 431, 430, 'TAB-W', 1, array( 'pa_model' => 'wi-fi' ) );
shop_variation( 432, 430, 'TAB-C', 1, array( 'pa_model' => 'wi-fi-cellular' ) );
$listing = var_listing( '5027', array( array( array( 'Model' => 'Wi-Fi' ), 3, 'TAB-W' ), array( array( 'Model' => 'Wi-Fi + Cellular' ), 2, 'TAB-C' ) ) );
shop( array( 'add_variation_terms' => '1' ) );
import( $listing );
check( 'eBay spells it otherwise, by SKU: the variation keeps the shop\'s term and stays offered, none added though Yes', array( var_meta( 432 ), offered( 430 ), term_writes(), $WARN ), array( array( 'attribute_pa_model' => 'wi-fi-cellular' ), array( 'pa_model=wi-fi', 'pa_model=wi-fi-cellular' ), array(), array() ) );
$said = array_values( array_filter( $LOG, function ( $l ) { return false !== strpos( $l, 'calls a value of' ); } ) );
check( '  the log says once that eBay calls it otherwise', $said, array( 'eBay listing 5027 calls a value of Model "Wi-Fi + Cellular", which WC #430\'s variations hold as your "Wi-Fi & Cellular": they keep "Wi-Fi & Cellular".' ) );
import( $listing );
check( '  and not again', count( array_filter( $LOG, function ( $l ) { return false !== strpos( $l, 'calls a value of' ); } ) ), 1 );

// Two variations recorded as eBay's Red hold two different terms - the shop
// changed one to Maroon by hand: eBay's Red is neither for certain, so each
// variation found keeps its own, and none is given eBay's text.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Crimson' => 'red', 'Maroon' ) );
shop_product( 440, '5028', array( array( 'pa_color', array( $c['Crimson'], $c['Maroon'] ), true ), array( 'Size', array( 'S', 'L' ), true ) ) );
shop_variation( 441, 440, '', 1, array( 'pa_color' => 'red', 'size' => 'S' ), 'publish', array( '_ebay_variation_specifics' => array( 'Color' => 'Red', 'Size' => 'S' ) ) );
shop_variation( 442, 440, '', 1, array( 'pa_color' => 'maroon', 'size' => 'L' ), 'publish', array( '_ebay_variation_specifics' => array( 'Color' => 'Red', 'Size' => 'L' ) ) );
import( var_listing( '5028', array( array( array( 'Color' => 'Red', 'Size' => 'S' ), 1 ), array( array( 'Color' => 'Red', 'Size' => 'L' ), 1 ) ) ) );
check( 'a value two variations hold as different terms: each found keeps its own, none given eBay\'s text', array( var_meta( 441 ), var_meta( 442 ), count( offered( 440 ) ), $WARN ), array( array( 'attribute_pa_color' => 'red', 'attribute_size' => 'S' ), array( 'attribute_pa_color' => 'maroon', 'attribute_size' => 'L' ), 2, array() ) );
check( '  and the log says eBay calls it otherwise (S13)', count( array_filter( $LOG, function ( $l ) { return 0 === strpos( $l, 'eBay listing 5028 calls a value of Color "Red", which WC #440\'s variations hold as your' ); } ) ), 1 );

// The spot check's N1 (fix round 2): eBay changed SKU S1's value from Red
// to Scarlet, which no term has, and added a new Red. S1's variation is
// recorded as Red, so its red is not learnt for Scarlet, nor kept for it -
// red is the new Red's: no two published variations hold red.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 480, '7001', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ) ) );
shop_variation( 481, 480, 'S1', 4, array( 'pa_color' => 'red' ), 'publish', array( '_ebay_variation_sku' => 'S1', '_ebay_variation_specifics' => array( 'Color' => 'Red' ) ) );
shop_variation( 482, 480, 'S3', 2, array( 'pa_color' => 'blue' ), 'publish', array( '_ebay_variation_sku' => 'S3', '_ebay_variation_specifics' => array( 'Color' => 'Blue' ) ) );
import( var_listing( '7001', array( array( array( 'Color' => 'Scarlet' ), 4, 'S1' ), array( array( 'Color' => 'Red' ), 6, 'S2' ), array( array( 'Color' => 'Blue' ), 2, 'S3' ) ) ) );
$s2 = wc_get_product_id_by_sku( 'S2' );
check( 'eBay changed S1 to Scarlet and added a Red: S1 holds Scarlet, the new Red red - never two published variations on red', array( var_meta( 481 ), var_meta( $s2 ), same_published( 480 ) ), array( array( 'attribute_pa_color' => 'Scarlet' ), array( 'attribute_pa_color' => 'red' ), array() ) );
check( '  and Scarlet is warned of, as a value Color does not have', count( array_filter( $WARN, function ( $w ) { return false !== strpos( $w, 'Color "Scarlet"' ); } ) ), 1 );

// The same without the new Red: eBay changed the value, so the variation
// follows it, warned, and is not pinned to Red - at No or at Yes, where
// Scarlet is added as a term of its own.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 485, '7002', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ) ) );
shop_variation( 486, 485, 'S1', 4, array( 'pa_color' => 'red' ), 'publish', array( '_ebay_variation_sku' => 'S1', '_ebay_variation_specifics' => array( 'Color' => 'Red' ) ) );
$listing = var_listing( '7002', array( array( array( 'Color' => 'Scarlet' ), 4, 'S1' ) ) );
import( $listing );
check( '  without it: the record says eBay changed it - Scarlet held and warned, not pinned to Red', array( var_meta( 486 ), count( $WARN ), count( array_filter( $LOG, function ( $l ) { return false !== strpos( $l, 'calls a value of' ); } ) ) ), array( array( 'attribute_pa_color' => 'Scarlet' ), 1, 0 ) );
shop( array( 'add_variation_terms' => '1' ) );
import( $listing );
check( '  at Yes: Scarlet added as its own term, the variation on it', array( term_writes(), var_meta( 486 ) ), array( array( array( 'insert', 'pa_color', 'Scarlet' ) ), array( 'attribute_pa_color' => 'scarlet' ) ) );

// A SKU used again on eBay after its variation was retired here: a retired
// variation teaches nothing, and the real Red keeps red alone.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 490, '7003', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ) ) );
shop_variation( 491, 490, 'S1', 0, array( 'pa_color' => 'red' ), 'private', array( '_ebay_variation_sku' => 'S1', '_ebay_variation_specifics' => array( 'Color' => 'Red' ) ) );
shop_variation( 493, 490, 'S2', 5, array( 'pa_color' => 'red' ), 'publish', array( '_ebay_variation_sku' => 'S2', '_ebay_variation_specifics' => array( 'Color' => 'Red' ) ) );
shop_variation( 492, 490, 'S3', 2, array( 'pa_color' => 'blue' ), 'publish', array( '_ebay_variation_sku' => 'S3', '_ebay_variation_specifics' => array( 'Color' => 'Blue' ) ) );
import( var_listing( '7003', array( array( array( 'Color' => 'Scarlet' ), 4, 'S1' ), array( array( 'Color' => 'Red' ), 5, 'S2' ), array( array( 'Color' => 'Blue' ), 2, 'S3' ) ) ) );
check( '  a SKU used again after its variation was retired: no second red published', array( var_meta( 491 ), var_meta( 493 ), same_published( 490 ) ), array( array( 'attribute_pa_color' => 'Scarlet' ), array( 'attribute_pa_color' => 'red' ), array() ) );

// Ambiguous SKU history: an old retired variation with S1 held blue. S1 is
// Scarlet on eBay now, and Red is new: S1's variation keeps neither term.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 495, '7006', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ) ) );
shop_variation( 496, 495, 'S1', 4, array( 'pa_color' => 'red' ), 'publish', array( '_ebay_variation_sku' => 'S1', '_ebay_variation_specifics' => array( 'Color' => 'Red' ) ) );
shop_variation( 497, 495, '', 0, array( 'pa_color' => 'blue' ), 'private', array( '_ebay_variation_sku' => 'S1' ) );
import( var_listing( '7006', array( array( array( 'Color' => 'Scarlet' ), 4, 'S1' ), array( array( 'Color' => 'Red' ), 6, 'S2' ) ) ) );
check( '  an ambiguous SKU history: S1 holds Scarlet, warned; no two published variations alike', array( var_meta( 496 ), same_published( 495 ), count( $WARN ) ), array( array( 'attribute_pa_color' => 'Scarlet' ), array(), 1 ) );

// No record yet, but a term another of eBay's values has by name is not
// kept for an unplaced one either: two variations on red would follow.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 498, '7007', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ) ) );
shop_variation( 499, 498, 'S1', 4, array( 'pa_color' => 'red' ) );
import( var_listing( '7007', array( array( array( 'Color' => 'Scarlet' ), 4, 'S1' ), array( array( 'Color' => 'Red' ), 6, 'S2' ) ) ) );
check( '  a term another value has by name is not kept for an unplaced one', array( var_meta( 499 ), same_published( 498 ) ), array( array( 'attribute_pa_color' => 'Scarlet' ), array() ) );

// Two of eBay's values each recorded on a variation holding red - the shop
// had them both on one term: red is neither's for certain. Each variation
// found keeps it only for the first; a new Scarlet variation gets none.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 486, '7008', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ), array( 'Size', array( 'M', 'L' ), true ) ) );
shop_variation( 487, 486, '', 1, array( 'pa_color' => 'red', 'size' => 'M' ), 'publish', array( '_ebay_variation_specifics' => array( 'Color' => 'Scarlet', 'Size' => 'M' ) ) );
shop_variation( 488, 486, '', 1, array( 'pa_color' => 'red', 'size' => 'L' ), 'publish', array( '_ebay_variation_specifics' => array( 'Color' => 'Ruby', 'Size' => 'L' ) ) );
import( var_listing( '7008', array( array( array( 'Color' => 'Scarlet', 'Size' => 'M' ), 1 ), array( array( 'Color' => 'Ruby', 'Size' => 'L' ), 1 ), array( array( 'Color' => 'Scarlet', 'Size' => 'L' ), 1 ) ) ) );
$scarlet_l = array_values( array_diff( array_keys( children_of( 486 ) ), array( 487, 488 ) ) );
check( 'two of eBay\'s values on one term: it is kept for one only, and learnt for neither', array( var_meta( 487 )['attribute_pa_color'] ?? null, var_meta( 488 )['attribute_pa_color'] ?? null, var_meta( $scarlet_l[0] ?? 0 )['attribute_pa_color'] ?? null ), array( 'red', 'Ruby', 'Scarlet' ) );

// What a variation's record says and what eBay's SKU says disagree: the
// value is learnt from neither (the spot check's S09).
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 489, '7009', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ), array( 'Size', array( 'M', 'L' ), true ) ) );
shop_variation( 4891, 489, '', 1, array( 'pa_color' => 'red', 'size' => 'S' ), 'publish', array( '_ebay_variation_specifics' => array( 'Color' => 'Scarlet', 'Size' => 'S' ) ) );
shop_variation( 4892, 489, 'S2', 1, array( 'pa_color' => 'blue', 'size' => 'M' ) );
import( var_listing( '7009', array( array( array( 'Color' => 'Scarlet', 'Size' => 'M' ), 1, 'S2' ), array( array( 'Color' => 'Scarlet', 'Size' => 'L' ), 1 ) ) ) );
$scarlet_new = array_values( array_diff( array_keys( children_of( 489 ) ), array( 4891, 4892 ) ) );
check( '  a record and a SKU that disagree: learnt from neither - a new Scarlet variation holds eBay\'s text', var_meta( $scarlet_new[0] ?? 0 )['attribute_pa_color'] ?? null, 'Scarlet' );

// The spot check's N2: a hyphen before or after a value is kept, so "-1.5"
// is not the term 1.5 (they share the slug 1-5), nor "-10°C" 10°C.
fresh();
$g = global_attribute( 'pa_strength', 'Strength', array( '1.5', '2.0' ) );
shop_product( 500, '7101', array( array( 'pa_strength', array( $g['1.5'], $g['2.0'] ), true ) ) );
shop_variation( 501, 500, '', 3, array( 'pa_strength' => '1-5' ) );
import( var_listing( '7101', array( array( array( 'Strength' => '1.5' ), 3 ), array( array( 'Strength' => '-1.5' ), 5 ) ) ) );
$minus = array_values( array_diff( array_keys( children_of( 500 ) ), array( 501 ) ) );
check( '"-1.5" is not the term 1.5: held as eBay\'s, warned; 1.5 alone on 1-5', array( var_meta( $minus[0] ?? 0 ), var_meta( 501 ), same_published( 500 ), count( $WARN ) ), array( array( 'attribute_pa_strength' => '-1.5' ), array( 'attribute_pa_strength' => '1-5' ), array(), 1 ) );
fresh();
$g = global_attribute( 'pa_strength', 'Strength', array( '1.5', '2.0' ) );
shop_product( 503, '7103', array( array( 'pa_strength', array( $g['1.5'], $g['2.0'] ), true ) ) );
import( var_listing( '7103', array( array( array( 'Strength' => '2.0' ), 3 ), array( array( 'Strength' => '-1.5' ), 5 ) ) ) );
check( '  "-1.5" alone, with no 1.5 on eBay: still not the term 1.5', array( attrs_of( 503 ), offered( 503 ) ), array( array( 'pa_strength' => 'pa_strength: 1-5|2-0 [v]' ), array( 'pa_strength=2-0' ) ) );
fresh();
$g = global_attribute( 'pa_rating', 'Rating', array( '10°C', '0°C' ) );
shop_product( 505, '7102', array( array( 'pa_rating', array( $g['10°C'], $g['0°C'] ), true ) ) );
shop( array( 'add_variation_terms' => '1' ) );
import( var_listing( '7102', array( array( array( 'Rating' => '10°C' ), 3 ), array( array( 'Rating' => '-10°C' ), 5 ) ) ) );
check( '  nor "-10°C" 10°C: at Yes it is added as its own term', array( term_writes(), count( terms_now()['pa_rating'] ?? array() ), same_published( 505 ) ), array( array( array( 'insert', 'pa_rating', '-10°C' ) ), 3, array() ) );

// "Rosé" is not "Rose", though the database finds either by the other.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Rose' => 'rose', 'Rosé' => 'rose-2' ) );
shop_product( 450, '5029', array( array( 'pa_color', array( $c['Rose'], $c['Rosé'] ), true ) ) );
shop_variation( 451, 450, '', 1, array( 'pa_color' => 'rose' ), 'publish', array( '_ebay_variation_specifics' => array( 'Color' => 'Rose' ) ) );
shop_variation( 452, 450, '', 1, array( 'pa_color' => 'rose-2' ), 'publish', array( '_ebay_variation_specifics' => array( 'Color' => 'Rosé' ) ) );
import( var_listing( '5029', array( array( array( 'Color' => 'Rose' ), 3 ), array( array( 'Color' => 'Rosé' ), 4 ) ) ) );
check( 'Rose and Rosé, both the shop\'s terms: each variation keeps its own', array( var_meta( 451 ), var_meta( 452 ), offered( 450 ) ), array( array( 'attribute_pa_color' => 'rose' ), array( 'attribute_pa_color' => 'rose-2' ), array( 'pa_color=rose', 'pa_color=rose-2' ) ) );
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Rose' ) );
shop_product( 460, '5030', array( array( 'pa_color', array( $c['Rose'] ), true ) ) );
shop_variation( 461, 460, '', 1, array( 'pa_color' => 'rose' ) );
$listing = var_listing( '5030', array( array( array( 'Color' => 'Rose' ), 3 ), array( array( 'Color' => 'Rosé' ), 4 ) ) );
import( $listing );
$rose_e = array_values( array_diff( array_keys( children_of( 460 ) ), array( 461 ) ) );
check( 'only Rose a term, setting No: eBay\'s Rosé is not filed under Rose - held as eBay\'s, not offered', array( var_meta( $rose_e[0] ?? 0 ), offered( 460 ), term_writes() ), array( array( 'attribute_pa_color' => 'Rosé' ), array( 'pa_color=rose' ), array() ) );
shop( array( 'add_variation_terms' => '1' ) );
import( $listing );
check( '  setting Yes: Rosé added as its own term beside Rose, and offered', array( terms_now()['pa_color'] ?? null, var_meta( $rose_e[0] ?? 0 ), offered( 460 ) ), array( array( 'rose' => 'Rose', 'rose-2' => 'Rosé' ), array( 'attribute_pa_color' => 'rose-2' ), array( 'pa_color=rose', 'pa_color=rose-2' ) ) );

// New variations, with no variation here to learn from: the term is found by
// itself - "X Large" by its slug and name, "Rosé" among the terms the
// database names alike, "Black & White" by its name as WordPress keeps it.
fresh();
$z = global_attribute( 'pa_size', 'Size', array( 'Medium', 'X-Large' ) );
$c = global_attribute( 'pa_color', 'Color', array( 'Rose' => 'rose', 'Rosé' => 'rose-2', 'Black & White' ) );
shop_product( 470, '5035', array( array( 'pa_size', array( $z['Medium'] ), true ), array( 'pa_color', array( $c['Rose'] ), true ) ) );
shop_variation( 471, 470, '', 1, array( 'pa_size' => 'medium', 'pa_color' => 'rose' ) );
import( var_listing( '5035', array(
	array( array( 'Size' => 'Medium', 'Color' => 'Rose' ), 1 ),
	array( array( 'Size' => 'X Large', 'Color' => 'Rosé' ), 1 ),
	array( array( 'Size' => 'X Large', 'Color' => 'Black & White' ), 1 ),
) ) );
check( 'new variations: "X Large" is X-Large, "Rosé" is Rosé, "Black & White" is the term kept as "&amp;" - all given to the product and offered, none written', array( attrs_of( 470 ), offered( 470 ), term_writes(), $WARN ), array(
	array( 'pa_size' => 'pa_size: medium|x-large [v]', 'pa_color' => 'pa_color: rose|rose-2|black-white [v]' ),
	array( 'pa_size=medium,pa_color=rose', 'pa_size=x-large,pa_color=black-white', 'pa_size=x-large,pa_color=rose-2' ),
	array(),
	array(),
) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA VARIATION REMOVED ON EBAY, AND PUT BACK\n" . str_repeat( '=', 124 ) . "\n";

fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 500, '5009', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ) ) );
shop_variation( 501, 500, '', 1, array( 'pa_color' => 'red' ) );
shop_variation( 502, 500, '', 1, array( 'pa_color' => 'blue' ) );
import( var_listing( '5009', array( array( array( 'Color' => 'Red' ), 1 ), array( array( 'Color' => 'Blue' ), 1 ) ) ) );
import( var_listing( '5009', array( array( array( 'Color' => 'Red' ), 1 ) ) ) );
check( 'Blue gone from eBay: its variation retired, private and out of stock, not deleted', array( children_of( 500 ), meta( 502, '_stock' ), meta( 502, '_stock_status' ) ), array( array( 501 => 'publish', 502 => 'private' ), 0, 'outofstock' ) );
check( '  it keeps its attribute_pa_color and its record', array( var_meta( 502 ), recorded( 502 ) ), array( array( 'attribute_pa_color' => 'blue' ), array( 'Color' => 'Blue' ) ) );
check( '  the product keeps blue among its values: nothing is taken away', attrs_of( 500 ), array( 'pa_color' => 'pa_color: red|blue [v]' ) );
import( var_listing( '5009', array( array( array( 'Color' => 'Red' ), 1 ), array( array( 'Color' => 'Blue' ), 4 ) ) ) );
check( 'Blue back on eBay: the same variation published again, none made', array( children_of( 500 ), meta( 502, '_stock' ) ), array( array( 501 => 'publish', 502 => 'publish' ), 4 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA NEW VARIATION ADDED ON EBAY, UNDER A GLOBAL ATTRIBUTE\n" . str_repeat( '=', 124 ) . "\n";

// Green is a value of the shop's Color, not yet on this product.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue', 'Green' ) );
shop_product( 600, '5010', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ) ) );
shop_variation( 601, 600, '', 1, array( 'pa_color' => 'red' ) );
$before_terms = terms_now();
import( var_listing( '5010', array( array( array( 'Color' => 'Red' ), 1 ), array( array( 'Color' => 'Green' ), 2 ) ) ) );
$green = array_values( array_diff( array_keys( children_of( 600 ) ), array( 601 ) ) );
check( 'Green: a value the global Color has - given to the product, the variation under it', array( attrs_of( 600 ), var_meta( $green[0] ?? 0 ) ), array( array( 'pa_color' => 'pa_color: red|blue|green [v]' ), array( 'attribute_pa_color' => 'green' ) ) );
check( '  no value written to the global attribute', array( term_writes(), terms_now() === $before_terms ), array( array(), true ) );
check( '  offered', offered( 600 ), array( 'pa_color=green', 'pa_color=red' ) );

// Teal is not a value of the shop's Color at all. By default it is not added.
$warned = count( $WARN );
import( var_listing( '5010', array( array( array( 'Color' => 'Red' ), 1 ), array( array( 'Color' => 'Green' ), 2 ), array( array( 'Color' => 'Teal' ), 3 ) ) ) );
$teal = array_values( array_diff( array_keys( children_of( 600 ) ), array( 601, $green[0] ?? 0 ) ) );
check( 'Teal, which the global Color does not have, setting at No: nothing written to the attribute', array( term_writes(), terms_now() === $before_terms ), array( array(), true ) );
check( '  its variation made and kept up to date, holding eBay\'s value', array( count( $teal ), var_meta( $teal[0] ?? 0 ), meta( $teal[0] ?? 0, '_stock' ), status_of( $teal[0] ?? 0 ) ), array( 1, array( 'attribute_pa_color' => 'Teal' ), 3, 'publish' ) );
check( '  the product\'s values unchanged, so the website does not offer it', array( attrs_of( 600 ), offered( 600 ) ), array( array( 'pa_color' => 'pa_color: red|blue|green [v]' ), array( 'pa_color=green', 'pa_color=red' ) ) );
check( '  the log says so once', array_slice( $WARN, $warned ), array( 'eBay listing 5010 has variations with Color "Teal", which your global attribute "Color" does not have: WC #600 keeps them up to date, but your website does not offer them until you add it to "Color" in Products > Attributes, or set "Add eBay\'s new variation values to global attributes?" to Yes in Settings. Until then WooCommerce\'s variation editor shows them as "Any Color": saved there, they would match any choice.' ) );
$listing = var_listing( '5010', array( array( array( 'Color' => 'Red' ), 1 ), array( array( 'Color' => 'Green' ), 2 ), array( array( 'Color' => 'Teal' ), 3 ) ) );
import( $listing );
check( '  and not again at the next import; the same variation found', array( count( $WARN ) - $warned, count( children_of( 600 ) ) ), array( 1, 3 ) );

// The shop adds Teal itself: the next import gives it to the product.
$teal_id = add_term( 'pa_color', 'Teal' );
import( $listing );
check( 'the shop adds Teal: the next import finds it, gives it to the product, and the website offers it', array( var_meta( $teal[0] ?? 0 ), offered( 600 ), count( children_of( 600 ) ) ), array( array( 'attribute_pa_color' => 'teal' ), array( 'pa_color=green', 'pa_color=red', 'pa_color=teal' ), 3 ) );
check( '  and the memo is gone', meta( 600, '_ebay_variation_values_unplaced' ), null );

// A variation holding eBay's text from before - no term had it - which eBay
// now calls otherwise: only the product's terms are kept against eBay, so it
// takes eBay's new text.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red' ) );
shop_product( 640, '5037', array( array( 'pa_color', array( $c['Red'] ), true ) ) );
shop_variation( 641, 640, 'TEE-T', 1, array( 'pa_color' => 'Teal' ) );
import( var_listing( '5037', array( array( array( 'Color' => 'Teal Blue' ), 1, 'TEE-T' ) ) ) );
check( 'eBay\'s old text, not a term, is not kept against eBay\'s new: the variation takes the new', var_meta( 641 ), array( 'attribute_pa_color' => 'Teal Blue' ) );

// With the setting at Yes, the value is added to the attribute, once.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 610, '5011', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ) ) );
shop_variation( 611, 610, '', 1, array( 'pa_color' => 'red' ) );
shop( array( 'add_variation_terms' => '1' ) );
$listing = var_listing( '5011', array( array( array( 'Color' => 'Red' ), 1 ), array( array( 'Color' => 'Sea Green' ), 2 ) ) );
import( $listing );
$sea = array_values( array_diff( array_keys( children_of( 610 ) ), array( 611 ) ) );
check( 'setting at Yes: "Sea Green" added to the global Color, once, as a new value only', array( term_writes(), terms_now()['pa_color'] ?? null ), array( array( array( 'insert', 'pa_color', 'Sea Green' ) ), array( 'red' => 'Red', 'blue' => 'Blue', 'sea-green' => 'Sea Green' ) ) );
check( '  given to the product, the variation under it, offered', array( attrs_of( 610 ), var_meta( $sea[0] ?? 0 ), offered( 610 ) ), array( array( 'pa_color' => 'pa_color: red|blue|sea-green [v]' ), array( 'attribute_pa_color' => 'sea-green' ), array( 'pa_color=red', 'pa_color=sea-green' ) ) );
check( '  and the log says it was added', count( array_filter( $LOG, function ( $l ) { return 0 === strpos( $l, 'Added "Sea Green" to your global attribute "Color"' ); } ) ), 1 );
import( $listing );
check( '  the next import adds nothing more', array( count( term_writes() ), count( children_of( 610 ) ) ), array( 1, 2 ) );

// Another import adds Olive between this one's look and its insert: the
// insert is refused as there already, and that value is the one used.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Olive' ) );
shop_product( 620, '5021', array( array( 'pa_color', array( $c['Red'] ), true ) ) );
shop_variation( 621, 620, '', 1, array( 'pa_color' => 'red' ) );
shop( array( 'add_variation_terms' => '1' ) );
$BLIND = array( 'Olive' );
import( var_listing( '5021', array( array( array( 'Color' => 'Red' ), 1 ), array( array( 'Color' => 'Olive' ), 1 ) ) ) );
$BLIND = array();
$olive = array_values( array_diff( array_keys( children_of( 620 ) ), array( 621 ) ) );
check( 'a value added meanwhile by another import: no second one made, that one used', array( term_writes(), count( terms_now()['pa_color'] ?? array() ), attrs_of( 620 ), var_meta( $olive[0] ?? 0 ) ), array( array( array( 'insert', 'pa_color', 'Olive' ) ), 2, array( 'pa_color' => 'pa_color: red|olive [v]' ), array( 'attribute_pa_color' => 'olive' ) ) );

// A global attribute the shop has since deleted under Products > Attributes,
// still on the product: no value is looked for in a taxonomy that is gone,
// and none written.
fresh();
$c = global_attribute( 'pa_shade', 'Shade', array( 'Light' ) );
shop_product( 630, '5033', array( array( 'pa_shade', array( $c['Light'] ), true ) ) );
unset( $TAXONOMIES['pa_shade'] );
shop( array( 'add_variation_terms' => '1' ) );
import( var_listing( '5033', array( array( array( 'Shade' => 'Light' ), 1 ) ) ) );
$light = array_keys( children_of( 630 ) );
check( 'a global attribute deleted since: nothing looked for or written in it, the variation holds eBay\'s value', array( term_writes(), $OBJECT_TERMS[630]['pa_shade'] ?? null, var_meta( $light[0] ?? 0 ) ), array( array(), array( $c['Light'] ), array( 'attribute_pa_shade' => 'Light' ) ) );

// Never under a label that is not the product's own global attribute: a
// product the import makes keeps eBay's names as custom attributes, as
// before, even where the shop has a global attribute of that label.
fresh();
global_attribute( 'pa_color', 'Color', array( 'Red' ) );
shop( array( 'add_variation_terms' => '1' ) );
$made = import( var_listing( '5012', array( array( array( 'Color' => 'Red' ), 1 ), array( array( 'Color' => 'Ochre' ), 1 ) ) ) );
check( 'a product new from eBay: custom Color, as before - no global attribute used or written', array( attrs_of( $made ), term_writes() ), array( array( 'color' => 'Color: Red|Ochre [v]' ), array() ) );
check( '  its variations offered', offered( $made ), array( 'color=Ochre', 'color=Red' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNEVER RENAMED, CHANGED OR TAKEN AWAY\n" . str_repeat( '=', 124 ) . "\n";

fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue', 'Gold' ) );
// The shop's Color also lists Gold, which no variation has; it is not used
// for variations yet; it sits second, hidden.
shop_product( 700, '5013', array( array( 'Fit', array( 'Slim' ), false, 0 ), array( 'pa_color', array( $c['Red'], $c['Gold'] ), false, 1 ) ) );
$GLOBALS['META'][700]['_product_attributes']['pa_color']['visible'] = false;
shop( array( 'add_variation_terms' => '1', 'overwrite_attributes' => '1' ) );
$before_terms = terms_now();
import( var_listing( '5013', array( array( array( 'Color' => 'Red' ), 1 ), array( array( 'Color' => 'Blue' ), 1 ) ) ) );
$row = meta( 700, '_product_attributes' )['pa_color'] ?? array();
check( 'the global Color now used for variations, Blue added beside Gold', array( attrs_of( 700 )['pa_color'] ?? null, $row['variation'] ?? null ), array( 'pa_color: red|gold|blue [v]', true ) );
check( '  its place and visibility as the shop set them', array( $row['position'] ?? null, $row['visible'] ?? null, array_keys( attrs_of( 700 ) ) ), array( 1, false, array( 'fit', 'pa_color' ) ) );
check( '  no value of the shop\'s renamed, changed or deleted - none even written', array( term_writes(), terms_now() === $before_terms ), array( array(), true ) );
check( '  Fit, which eBay says nothing of, untouched', attrs_of( 700 )['fit'] ?? null, 'Fit: Slim' );
check( '  and in the source no term is ever updated or deleted', array( false === strpos( $src, 'wp_update_term' ), false === strpos( $src, 'wp_delete_term' ) ), array( true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PRODUCT 3.30 HAD ALREADY DUPLICATED\n" . str_repeat( '=', 124 ) . "\n";

// What the old import left: the shop's two variations retired, and two of
// its own made under a custom key the product does not have - "Any Color".
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red', 'Blue' ) );
shop_product( 800, '5014', array( array( 'pa_color', array( $c['Red'], $c['Blue'] ), true ) ) );
shop_variation( 801, 800, '', 0, array( 'pa_color' => 'red' ), 'private' );
shop_variation( 802, 800, '', 0, array( 'pa_color' => 'blue' ), 'private' );
shop_variation( 803, 800, '', 2, array( 'color' => 'Red' ) );
shop_variation( 804, 800, '', 5, array( 'color' => 'Blue' ) );
check( 'before: the website sees the old import\'s two as "Any Color"', offered( 800 ), array( 'ANY', 'ANY' ) );
import( var_listing( '5014', array( array( array( 'Color' => 'Red' ), 2 ), array( array( 'Color' => 'Blue' ), 5 ) ) ) );
check( 'the shop\'s own two found and published again with eBay\'s stock; the "Any" ones retired', array( children_of( 800 ), meta( 801, '_stock' ), meta( 802, '_stock' ), meta( 803, '_stock' ) ), array( array( 801 => 'publish', 802 => 'publish', 803 => 'private', 804 => 'private' ), 2, 5, 0 ) );
check( '  the website offers Red and Blue again', offered( 800 ), array( 'pa_color=blue', 'pa_color=red' ) );

// One of 3.30's copies the shop then put right by hand - pa_color red,
// published, with its own photo - while the variation it stood in for is
// still retired: the published one is kept (fix round 1, review L4).
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Red' ) );
shop_product( 810, '5031', array( array( 'pa_color', array( $c['Red'] ), true ) ) );
shop_variation( 811, 810, '', 0, array( 'pa_color' => 'red' ), 'private' );
shop_variation( 812, 810, '', 4, array( 'pa_color' => 'red' ), 'publish', array( '_thumbnail_id' => 77 ) );
import( var_listing( '5031', array( array( array( 'Color' => 'Red' ), 4 ) ) ) );
check( 'a copy the shop put right by hand stays published; the retired one stays retired', array( children_of( 810 ), meta( 812, '_stock' ) ), array( array( 811 => 'private', 812 => 'publish' ), 4 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFOUND BY WHAT EBAY CALLED IT, OR WHAT IT HOLDS NOW\n" . str_repeat( '=', 124 ) . "\n";

// A term whose name is not eBay's value - the shop renamed Red to Crimson
// after it was pushed - is not taken for it: eBay's Red has no value in
// Color. Recorded at the import that made it, the variation is still found.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Crimson' => 'red', 'Blue' ) );
shop_product( 900, '5015', array( array( 'pa_color', array( $c['Crimson'], $c['Blue'] ), true ) ) );
shop_variation( 901, 900, '', 1, array( 'pa_color' => 'red' ), 'publish', array( '_ebay_variation_specifics' => array( 'Color' => 'Red' ) ) );
shop_variation( 902, 900, '', 1, array( 'pa_color' => 'blue' ) );
import( var_listing( '5015', array( array( array( 'Color' => 'Red' ), 3 ), array( array( 'Color' => 'Blue' ), 1 ) ) ) );
check( 'by its record: Red found though its term is now Crimson; nothing made', array( children_of( 900 ), meta( 901, '_stock' ) ), array( array( 901 => 'publish', 902 => 'publish' ), 3 ) );
check( '  it keeps the term it holds, so the website still offers it, and no warning (fix round 1, M1)', array( var_meta( 901 ), offered( 900 ), $WARN ), array( array( 'attribute_pa_color' => 'red' ), array( 'pa_color=blue', 'pa_color=red' ), array() ) );
check( '  the log says once that eBay calls it Red', array_values( array_filter( $LOG, function ( $l ) { return false !== strpos( $l, 'calls a value of' ); } ) ), array( 'eBay listing 5015 calls a value of Color "Red", which WC #900\'s variations hold as your "Crimson": they keep "Crimson".' ) );
// The stock checks match the same way (TCGiant_Sync_Variations::match()).
$m = TCGiant_Sync_Variations::match( wc_get_product( 900 ), TCGiant_Sync_Variations::from_item( var_listing( '5015', array( array( array( 'Color' => 'Red' ), 3 ), array( array( 'Color' => 'Blue' ), 1 ) ) ) ) );
check( '  the stock checks pair them as the import does', $m, array( 'pairs' => array( array( 901 ), array( 902 ) ), 'unmatched' => array() ) );
// With the setting at Yes: no second term "Red" is added beside Crimson.
fresh();
$c = global_attribute( 'pa_color', 'Color', array( 'Crimson' => 'red', 'Blue' ) );
shop_product( 905, '5032', array( array( 'pa_color', array( $c['Crimson'], $c['Blue'] ), true ) ) );
shop_variation( 906, 905, '', 1, array( 'pa_color' => 'red' ), 'publish', array( '_ebay_variation_specifics' => array( 'Color' => 'Red' ) ) );
shop( array( 'add_variation_terms' => '1' ) );
import( var_listing( '5032', array( array( array( 'Color' => 'Red' ), 3 ) ) ) );
check( '  setting Yes: no "Red" added beside the renamed Crimson; the variation keeps it, offered', array( term_writes(), var_meta( 906 ), offered( 905 ) ), array( array(), array( 'attribute_pa_color' => 'red' ), array( 'pa_color=red' ) ) );

// The shop changed a variation here, and pushed: eBay now calls it what
// it holds, not what was recorded. Found by what it holds.
fresh();
shop_product( 910, '5016', array( array( 'Size', array( 'S', 'XL' ), true ) ) );
shop_variation( 911, 910, '', 1, array( 'size' => 'XL' ), 'publish', array( '_ebay_variation_specifics' => array( 'Size' => 'L' ) ) );
import( var_listing( '5016', array( array( array( 'Size' => 'XL' ), 2 ) ) ) );
check( 'changed here and pushed: found by what it holds, and its record brought up to date', array( children_of( 910 ), recorded( 911 ) ), array( array( 911 => 'publish' ), array( 'Size' => 'XL' ) ) );

// Recorded as L, holding XL - and eBay now has both: L is the one recorded,
// and XL a new variation, never the same one twice.
fresh();
shop_product( 915, '5019', array( array( 'Size', array( 'L', 'XL' ), true ) ) );
shop_variation( 916, 915, '', 1, array( 'size' => 'XL' ), 'publish', array( '_ebay_variation_specifics' => array( 'Size' => 'L' ) ) );
import( var_listing( '5019', array( array( array( 'Size' => 'L' ), 2 ), array( array( 'Size' => 'XL' ), 3 ) ) ) );
$xl = array_values( array_diff( array_keys( children_of( 915 ) ), array( 916 ) ) );
check( 'two of eBay\'s that would both answer to one variation: it takes the one recorded, the other is made', array( count( $xl ), var_meta( 916 ), var_meta( $xl[0] ?? 0 ), meta( 916, '_stock' ) ), array( 1, array( 'attribute_size' => 'L' ), array( 'attribute_size' => 'XL' ), 2 ) );

// Two variations both changed here to XL, recorded as L and M: eBay now sells
// only XL. The first found answers for it; the other is retired.
fresh();
shop_product( 917, '5036', array( array( 'Size', array( 'XL' ), true ) ) );
shop_variation( 918, 917, '', 1, array( 'size' => 'XL' ), 'publish', array( '_ebay_variation_specifics' => array( 'Size' => 'L' ) ) );
shop_variation( 919, 917, '', 1, array( 'size' => 'XL' ), 'publish', array( '_ebay_variation_specifics' => array( 'Size' => 'M' ) ) );
import( var_listing( '5036', array( array( array( 'Size' => 'XL' ), 3 ) ) ) );
check( '  two that hold XL against their records: the first answers, the other is retired', array( children_of( 917 ), meta( 918, '_stock' ) ), array( array( 918 => 'publish', 919 => 'private' ), 3 ) );

fresh();
shop_product( 920, '5017', array( array( 'Size', array( 'XL' ), true ) ) );
shop_variation( 921, 920, '', 1, array( 'size' => 'XL' ), 'publish', array( '_ebay_variation_specifics' => array( 'Size' => 'L' ) ) );
$m = TCGiant_Sync_Variations::match( wc_get_product( 920 ), TCGiant_Sync_Variations::from_item( var_listing( '5017', array( array( array( 'Size' => 'XL' ), 2 ) ) ) ) );
check( '  one changed here and pushed, before the import that records it: by what it holds', $m, array( 'pairs' => array( array( 921 ) ), 'unmatched' => array() ) );
$m = TCGiant_Sync_Variations::match( wc_get_product( 920 ), TCGiant_Sync_Variations::from_item( var_listing( '5017', array( array( array( 'Size' => 'L' ), 2 ), array( array( 'Size' => 'XL' ), 2 ) ) ) ) );
check( '  but never for two of eBay\'s', $m, array( 'pairs' => array( array( 921 ) ), 'unmatched' => array( 1 ) ) );
// And what it holds now only after what any variation was recorded as, or
// holds with no record: another XL, never changed, answers for XL.
shop_variation( 922, 920, '', 1, array( 'size' => 'XL' ) );
$m = TCGiant_Sync_Variations::match( wc_get_product( 920 ), TCGiant_Sync_Variations::from_item( var_listing( '5017', array( array( array( 'Size' => 'L' ), 2 ), array( array( 'Size' => 'XL' ), 2 ) ) ) ) );
check( '  a record first, then what one with no record holds, then what one holds against its record', $m, array( 'pairs' => array( array( 921 ), array( 922 ) ), 'unmatched' => array() ) );

// A copy, or a product unlinked, keeps no record of the old listing's names.
check( 'what eBay called a variation is among the keys a duplicate or an unlink drops', in_array( '_ebay_variation_specifics', TCGiant_Sync_Listing_Link::listing_identity_meta_keys(), true ), true );
fresh();
shop_product( 930, '5020', array( array( 'Size', array( 'S' ), true ) ) );
shop_variation( 931, 930, '', 1, array( 'size' => 'S' ), 'publish', array( '_ebay_variation_specifics' => array( 'Size' => 'S' ) ) );
TCGiant_Sync_Listing_Link::unlink_product_from_ebay( 930, 'unlinked by admin' );
check( '  and Unlink takes it off the variations of a variable product', array( meta( 930, '_ebay_item_id' ), recorded( 931 ), var_meta( 931 ) ), array( null, null, array( 'attribute_size' => 'S' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE 3.24 MERGE, AND THE SETTING\n" . str_repeat( '=', 124 ) . "\n";

check( 'the merge is as it was: existing set first, a global attribute skipped, gated by Overwrite Attributes', array(
	false !== strpos( $src, '$merged = $existing_attributes;' ),
	false !== strpos( $src, "if ( \$already->is_taxonomy() ) {\n\t\t\t\t\tcontinue;" ),
	false !== strpos( $src, 'if ( $is_new || $overwrite_attributes ) {' ),
), array( true, true, true ) );
check( '  the variations\' attributes kept after it, whatever it decided', strpos( $src, '$variation_dims = $is_variable ? $this->keep_variation_attributes(' ) > strpos( $src, "'Attributes skipped (WooCommerce won)'" ), true );
$admin_src = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
$view      = (string) file_get_contents( $ROOT . '/admin/views/settings.php' );
$forms_at  = (int) strpos( $admin_src, 'const SETTINGS_FORMS' );
$import_of = substr( $admin_src, $forms_at, (int) strpos( $admin_src, "'export' => array(", $forms_at ) - $forms_at );
check( 'the setting is Import Settings\' own, so a save keeps it', false !== strpos( $import_of, "'add_variation_terms'" ), true );
check( '  drawn No unless chosen', false !== strpos( $view, "checked( \$settings['add_variation_terms'] ?? '0', '0' )" ), true );
check( '  and the import reads it as Yes only when it is', defined( 'TCGiant_Sync_Mapper::VARIATION_TERMS_SETTING' ) ? TCGiant_Sync_Mapper::VARIATION_TERMS_SETTING : null, 'add_variation_terms' );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
