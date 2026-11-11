<?php
/**
 * Harness: Filterable item specifics (3.35.0) - the eBay item specifics a
 * shop chooses made into global attributes its filters can use, and Brand
 * into WooCommerce Brands.
 *
 * Held to the owner's decisions: nothing changes until the shop chooses; a
 * name becomes one global attribute, the shop's own of that label or a new
 * one; products already imported are converted in batches, resumably, with
 * no eBay call; imports and syncs then write the global attribute, never
 * beside a custom one; Brand into Brands only where a product has none;
 * values kept exactly; names that identify an item, or have thousands of
 * values, refused; undo touches only the plugin's own conversions; and a
 * conversion changes nothing a push sends, nor the product's date, nor the
 * content hash, so Re-send to eBay judges a converted listing as before.
 *
 * Loaded for real: the class, the mapper, the exporter, the templates and
 * their render, the re-send's judge and count, the Settings section's view.
 * WordPress's terms and WooCommerce's attributes are played as they keep
 * them (tests/support/filterable-world.php).
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/filterable-world.php';

class Harness_Stop extends Exception {
	public $kind;
	public $data;
	public function __construct( $kind, $data = null ) { parent::__construct( $kind ); $this->kind = $kind; $this->data = $data; }
}
$GLOBALS['nonce_ok'] = true;
function check_admin_referer( $action = -1, $arg = '_wpnonce' ) {
	if ( empty( $GLOBALS['nonce_ok'] ) ) {
		throw new Harness_Stop( 'died', 'nonce' );
	}
	$GLOBALS['nonce_action'] = $action;
	return 1;
}
function wp_die( $message = '' ) { throw new Harness_Stop( 'died', $message ); }
function wp_safe_redirect( $url ) { throw new Harness_Stop( 'redirect', $url ); }
function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . $name . '" value="nonce:' . $action . '">'; }
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? " checked='checked'" : ''; if ( $echo ) { echo $r; } return $r; }
function disabled( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? " disabled='disabled'" : ''; if ( $echo ) { echo $r; } return $r; }

/** A handler, run to its end: what stopped it, and the notice it left. */
function handler( $callback, array $post = array() ) {
	$_POST = $post;
	$stop  = null;
	try {
		call_user_func( $callback );
	} catch ( Harness_Stop $e ) {
		$stop = $e;
	}
	$notice = get_transient( 'tcgiant_filterable_notice_1' );
	delete_transient( 'tcgiant_filterable_notice_1' );
	return array( $stop ? $stop->kind : '', $stop ? $stop->data : null, is_array( $notice ) ? $notice : null );
}

/** The Settings section, drawn, its spaces folded. */
function section() {
	ob_start();
	include TCGIANT_SYNC_PATH . 'admin/views/filterable.php';
	return preg_replace( '/\s+/', ' ', ob_get_clean() );
}

/** A push (do_push()). */
function fw_push( WC_Product $product ) {
	return private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
}

/** Every Trading call since the last asked, its UUID taken out. */
function fw_bodies() {
	$out = array();
	foreach ( $GLOBALS['API']->calls as $call ) {
		$out[] = array( $call[0], preg_replace( '#<UUID>[0-9a-fA-F]+</UUID>\n?#', '', (string) $call[1] ) );
	}
	$GLOBALS['API']->calls = array();
	return $out;
}

/** What a push of each of these products would send, and its description's render. */
function fw_sends( array $ids ) {
	$out = array();
	foreach ( $ids as $id ) {
		$product    = wc_get_product( $id );
		$render     = described( $product );
		$out[ $id ] = array( item_xml( $product, push_settings( $product ) ), $render['html'], $render['content_hash'] );
	}
	return $out;
}

/** The modified dates and saves of every product. */
function fw_dates() {
	$out = array();
	foreach ( $GLOBALS['W']['products'] as $id => $product ) {
		$out[ $id ] = $product->data['modified'] ?? null;
	}
	return array( $out, $GLOBALS['FW']['saves'] );
}

/** A product's record, without the runs' IDs. */
function fw_record( $id ) {
	$out = array();
	foreach ( TCGiant_Sync_Filterable::record( $id ) as $taxonomy => $entry ) {
		$out[ $taxonomy ] = isset( $entry['name'] ) ? $entry['name'] : 'term ' . (int) $entry['term'];
	}
	return $out;
}

/** The run's state. */
function fw_state() { return TCGiant_Sync_Filterable::state(); }

/** The brand a product has in WooCommerce Brands, by term ID. */
function fw_terms_of_id( $id ) { return (int) ( $GLOBALS['FW']['ot'][ $id ]['product_brand'][0] ?? 0 ); }

$GLOBALS['W']['caps'] = array();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE CHOICES: THE NAMES ON THE SHOP'S PRODUCTS, COUNTED, AND NOTHING CHANGED\n" . str_repeat( '=', 124 ) . "\n";

fw_fresh();
$color = fw_global_attribute( 'color', 'Color' );
$red   = fw_shop_term( $color, 'Red' );
fw_listing( 701, array( array( 'Brand', 'Pokemon' ), array( 'Set', 'Base Set' ), array( 'Grade', '9' ), array( 'Card Name', 'Charizard' ), array( 'Card Number', '4/102' ), array( 'Language', 'English' ) ) );
fw_listing( 702, array( array( 'Brand', 'Pokemon' ), array( 'Set', 'Jungle' ), array( 'Card Name', 'Pikachu' ), array( 'Card Number', '60/64' ), array( 'Size', 'S | M', 6, 1, 1 ) ) );
fw_listing( 703, array( array( 'Brand', 'Wizards' ), array( 'Set', 'Base Set' ), array( 'Grade', '10' ), array( 'Card Name', 'Blastoise' ), array( $color, array( $red ) ) ) );
fw_listing( 704, array( array( 'Brand', 'Konami | Upper Deck' ) ) );
fw_listing( 705, array() );
$meta_before  = $GLOBALS['W']['meta'];
$dates_before = fw_dates();
check( 'before the first count, nothing is chosen and the list is empty', array( TCGiant_Sync_Filterable::choice()['names'], TCGiant_Sync_Filterable::counted() ), array( array(), array() ) );
$names = fw_count();
check( 'the names, the most used first', array_keys( $names ), array( 'Brand', 'Card Name', 'Set', 'Card Number', 'Grade', 'Language' ) );
check( '  Brand: on 4 products, 4 values ("Konami | Upper Deck" is two)', $names['Brand'], array( 'products' => 4, 'converted' => 0, 'values' => 4, 'over' => false ) );
check( '  Set: on 3, 2 values; Grade on 2, 2 values', array( $names['Set']['products'], $names['Set']['values'], $names['Grade']['products'], $names['Grade']['values'] ), array( 3, 2, 2, 2 ) );
check( '  a variation\'s Size and the shop\'s own global Color are not offered', array( isset( $names['Size'] ), isset( $names['Color'] ), isset( $names[ $color ] ) ), array( false, false, false ) );
check( 'counting wrote nothing to any product, saved none and set no term', array( $GLOBALS['W']['meta'] === $meta_before, fw_dates() === $dates_before, $GLOBALS['FW']['term_sets'] ), array( true, true, array() ) );
check( '  its list is kept unautoloaded, with when it was counted and over how many products', array( $GLOBALS['W']['autoload'][ TCGiant_Sync_Filterable::CHOICES_OPTION ] ?? null, get_option( TCGiant_Sync_Filterable::CHOICES_OPTION )['done']['products'] ?? null, (int) ( get_option( TCGiant_Sync_Filterable::CHOICES_OPTION )['done']['at'] ?? 0 ) > 0 ), array( 'no', 5, true ) );
check( '  and no eBay call', $GLOBALS['API']->calls, array() );

// A count asked for during one carries on after it: counted again. The count
// is stopped for time after its first product, and a product is added behind
// its place, which only a count after it can see.
TCGiant_Sync_Runtime::$late = function () {
	static $n = 0;
	return 1 === ++$n ? '61 s of this request used' : '';
};
TCGiant_Sync_Filterable::queue_count();
as_run( TCGiant_Sync_Filterable::COUNT_HOOK, true, 1 );
TCGiant_Sync_Runtime::$late = '';
$doing = get_option( TCGiant_Sync_Filterable::CHOICES_OPTION )['doing'] ?? array();
TCGiant_Sync_Filterable::queue_count();
fw_listing( 700, array( array( 'Brand', 'Bandai' ) ) );
as_run( TCGiant_Sync_Filterable::COUNT_HOOK, true );
check( 'Count again while counting: counted again after, with what it had already passed', array( $doing['cursor'] ?? null, TCGiant_Sync_Filterable::counted()['Brand']['products'] ), array( 701, 5 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE GUARDS: NAMES THAT IDENTIFY AN ITEM, AND NAMES WITH THOUSANDS OF VALUES\n" . str_repeat( '=', 124 ) . "\n";

$want = array( 'Card Name' => true, 'Card Number' => true, 'Certification Number' => true, 'UPC' => true, 'EAN' => true, 'ISBN' => true, 'MPN' => true, 'GTIN' => true, 'card name' => true, 'CARD  NUMBER' => true, 'Card No.' => true, 'Lot #' => true, 'Serial Number' => true, 'Serial Numbered' => true, 'Manufacturer Part Number' => true, 'Model Number' => true, 'Cert No' => true, 'Number of Cards' => false, 'Number of Players' => false, 'Brand' => false, 'Set' => false, 'Grade' => false, 'Professional Grader' => false, 'Year Manufactured' => false, 'Language' => false, 'Character' => false, 'Game' => false, 'Rarity' => false, 'Player/Athlete' => false );
$got  = array();
foreach ( array_keys( $want ) as $name ) {
	$got[ $name ] = TCGiant_Sync_Filterable::identifier_like( $name );
}
check( 'identifier-like names: the list, "... Number", "No.", "#", serials, certificates and codes; not "Number of ..."', $got, $want );
$row = function ( $values, $over = false ) { return array( 'products' => 10, 'converted' => 0, 'values' => $values, 'over' => $over ); };
check( 'values: 200 is fine, 201 warns, 2,000 is offered, 2,001 is refused, and so is "over"', array(
	TCGiant_Sync_Filterable::warning( $row( 200 ) ),
	'' !== TCGiant_Sync_Filterable::warning( $row( 201 ) ),
	TCGiant_Sync_Filterable::refusal( 'Player', $row( 2000 ) ),
	'' !== TCGiant_Sync_Filterable::refusal( 'Player', $row( 2001 ) ),
	'' !== TCGiant_Sync_Filterable::refusal( 'Player', $row( 0, true ) ),
), array( '', true, '', true, true ) );
check( '  in words: the warning and the refusal', array( TCGiant_Sync_Filterable::warning( $row( 312 ) ), TCGiant_Sync_Filterable::refusal( 'Player', $row( 0, true ) ), TCGiant_Sync_Filterable::refusal( 'Card Name', $row( 3 ) ) ), array(
	'312 different values: a long list for a filter.',
	'Not offered: it has over 2,000 different values, too many for a filter to be any help.',
	'Not offered: it names one item - a number, a code or a card\'s own name - so a filter would have a value for nearly every product.',
) );
update_option( TCGiant_Sync_Filterable::CHOICES_OPTION, array( 'done' => array( 'at' => time(), 'products' => 3000, 'names' => array(
	'Player'    => $row( 2001, true ),
	'Card Name' => $row( 3 ),
	'Rarity'    => $row( 312 ),
) ) ), false );
$said = fw_choose( array( 'Player', 'Card Name', 'Rarity', 'Not Counted' ), true, false );
check( 'Save refuses the identifier and the name with too many values, says why, and takes the one with many', array( array_keys( $said['refused'] ), array_keys( TCGiant_Sync_Filterable::choice()['names'] ), $said['ok'] ), array( array( 'Player', 'Card Name' ), array( 'Rarity' ), false ) );
check( '  a name not in the count is not taken; no global attribute is made for a refused one', array( wc_attribute_taxonomy_id_by_name( 'pa_player' ), wc_attribute_taxonomy_id_by_name( 'pa_card-name' ), count( $GLOBALS['FW']['created'] ) ), array( 0, 0, 1 ) );
check( '  its words name each', array( false !== strpos( $said['message'], '"Player": Not offered: it has over 2,000' ), false !== strpos( $said['message'], '"Card Name": Not offered: it names one item' ) ), array( true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE GLOBAL ATTRIBUTE: THE SHOP'S OWN OF THAT LABEL, OR A NEW ONE, IN 28 CHARACTERS\n" . str_repeat( '=', 124 ) . "\n";

fw_fresh();
$own_brand = fw_global_attribute( 'brand', 'Brand' );
$own_maker = fw_global_attribute( 'manufacturer', 'Rarity' );
fw_global_attribute( 'grade', 'grade' );
fw_global_attribute( 'set', 'Card Set' );
check( 'the shop\'s own "Brand": reused, nothing made', array( TCGiant_Sync_Filterable::resolve( 'Brand' ), $GLOBALS['FW']['created'] ), array( 'pa_brand', array() ) );
check( '  one labelled "Rarity" under another slug: reused by its label', TCGiant_Sync_Filterable::resolve( 'Rarity' ), 'pa_manufacturer' );
$clash = TCGiant_Sync_Filterable::resolve( 'Grade' );
check( '  "grade", only its capitals differ: refused, and none made beside it', array( is_wp_error( $clash ), is_wp_error( $clash ) ? $clash->get_error_message() : '', $GLOBALS['FW']['created'] ), array( true, 'Not made filterable: your global attribute "grade" (Products > Attributes) differs from eBay\'s "Grade" only in its capitals or spaces. Rename it to "Grade" there, then tick this again.', array() ) );
check( '  "Set", its slug taken by the shop\'s "Card Set": a new one, set-2, labelled "Set"', array( TCGiant_Sync_Filterable::resolve( 'Set' ), wc_attribute_label( 'pa_set-2' ), wc_attribute_label( 'pa_set' ) ), array( 'pa_set-2', 'Set', 'Card Set' ) );
check( '  and its taxonomy registered in this request, so its terms can be written now', taxonomy_exists( 'pa_set-2' ), true );
$long  = 'Original/Licensed Reprint Details Extra';
$long2 = 'Original Licensed Reprint Detail Others';
$t1    = TCGiant_Sync_Filterable::resolve( $long );
$t2    = TCGiant_Sync_Filterable::resolve( $long2 );
check( 'a long name: its slug cut to 28, with no hyphen at its end; its label the whole name', array( $t1, strlen( substr( $t1, 3 ) ) <= 28, wc_attribute_label( $t1 ) ), array( 'pa_original-licensed-reprint-de', true, $long ) );
check( '  another with the same 28: -2, still within 28', array( $t2, strlen( substr( $t2, 3 ) ), wc_attribute_label( $t2 ) ), array( 'pa_original-licensed-reprint-2', 27, $long2 ) );
check( '  a reserved word ("Year"): year-2; a name of no Latin letters: "specific-" and a hash', array( TCGiant_Sync_Filterable::resolve( 'Year' ), (bool) preg_match( '/^pa_specific-[0-9a-f]{8}$/', (string) TCGiant_Sync_Filterable::resolve( 'ブランド' ) ) ), array( 'pa_year-2', true ) );
check( '  every slug WooCommerce took: none over 28 characters', array_values( array_filter( $GLOBALS['FW']['created'], function ( $c ) { return strlen( $c[0] ) > 28; } ) ), array() );
check( '  each made one said in the log', count( fw_logged( 'Filterable item specifics: made the global attribute' ) ), 5 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nCONVERSION: THE SAME VALUES, PLACE AND VISIBILITY; NO DUPLICATE; VARIATIONS LEFT; NO SAVE\n" . str_repeat( '=', 124 ) . "\n";

/** The conversion shop: the shop's own global Brand, and products of every kind. */
function conversion_shop() {
	fw_fresh();
	$brand  = fw_global_attribute( 'brand', 'Brand' );
	$bandai = fw_shop_term( $brand, 'Bandai' );
	fw_shop_term( $brand, 'Pokemon' );
	fw_listing( 801, array( array( 'Brand', 'Pokemon', 0, 1 ), array( 'Set', 'Base Set', 1, 0 ), array( 'Card Name', 'Charizard\\Holo', 2 ), array( 'Size', 'S | M', 3, 1, 1 ) ) );
	fw_listing( 802, array( array( 'Card Name', 'Pikachu', 0 ), array( 'Brand', 'Pokémon', 1 ), array( 'Set', 'Jungle', 2 ) ) );
	fw_listing( 803, array( array( 'Brand', 'POKEMON', 0 ), array( 'Set', 'base set', 1 ) ) );
	fw_listing( 804, array( array( 'Brand', 'Pokemon &amp; Friends', 0 ), array( 'Set', 'Fossil', 1 ) ) );
	fw_listing( 805, array( array( 'Brand', 'Konami | Upper Deck', 0 ), array( 'Set', 'Fossil', 1 ) ) );
	fw_listing( 806, array( array( 'Brand', 'Pokemon | Bandai', 0, 1, 1 ), array( 'Set', 'Fossil', 1 ) ) );
	fw_listing( 807, array( array( 'Brand', 'Pokemon', 0 ), array( $brand, array( $bandai ), 1 ), array( 'Set', 'Fossil', 2 ) ) );
	fw_listing( 808, array( array( $brand, array( $bandai ), 0 ), array( 'Set', 'Fossil', 1 ) ) );
	fw_listing( 809, array( array( 'Brand', 'Pokemon  TCG', 0 ) ) );
	fw_listing( 810, array( array( 'Set', 'Base Set', 0 ), array( 'Brand', 'Wizards', 4 ) ), array( 'description' => '' ) );
	fw_listing( 811, array() );
	return $brand;
}
$brand = conversion_shop();
$ids   = array( 801, 802, 803, 804, 805, 806, 807, 808, 809, 810, 811 );
$plain = fw_sends( $ids );
$attrs = array();
foreach ( $ids as $id ) {
	$attrs[ $id ] = fw_attrs( $id );
}
$dates = fw_dates();
fw_count();
$said = fw_choose( array( 'Brand', 'Set' ) );
check( 'Brand (the shop\'s own pa_brand) and a new Set chosen, a run started, nothing refused', array( TCGiant_Sync_Filterable::choice()['names'], $said['ok'], $said['started'] ), array( array( 'Brand' => 'pa_brand', 'Set' => 'pa_set' ), true, true ) );
check( 'a product: Brand and Set global, each in its place and seen or hidden as it was; Card Name and the variation\'s Size as they were', fw_attrs( 801 ), array(
	'pa_brand'  => 'pa_brand: Pokemon @0',
	'pa_set'    => 'pa_set: Base Set @1 [hidden]',
	'card-name' => 'Card Name: Charizard\\Holo @2',
	'size'      => 'Size: S | M @3 [v]',
) );
check( '  the same order of keys, the custom one\'s place', array_keys( $GLOBALS['W']['meta'][801]['_product_attributes'] ), array( 'pa_brand', 'pa_set', 'card-name', 'size' ) );
check( '  never a custom and a global attribute of the same name, on any product', array_values( array_filter( $ids, function ( $id ) {
	$keys = array_keys( (array) ( $GLOBALS['W']['meta'][ $id ]['_product_attributes'] ?? array() ) );
	return ( in_array( 'brand', $keys, true ) && in_array( 'pa_brand', $keys, true ) && 807 !== $id ) || ( in_array( 'set', $keys, true ) && in_array( 'pa_set', $keys, true ) );
} ) ), array() );
check( '  recorded as the plugin\'s: taxonomy => the eBay name, with the run', array( fw_record( 801 ), TCGiant_Sync_Filterable::record( 801 )['pa_brand']['run'] === fw_state()['run'] ), array( array( 'pa_brand' => 'Brand', 'pa_set' => 'Set' ), true ) );
check( '"Pokémon" is a term of its own, not the "Pokemon" the database would match', array( fw_attrs( 802 )['pa_brand'], fw_terms( 'pa_brand' )['pokemon'] ?? null ), array( 'pa_brand: Pokémon @1', 'Pokemon' ) );
check( '"POKEMON", which WordPress would refuse beside "Pokemon": a slug of its own, the name exact', array( fw_attrs( 803 )['pa_brand'], array_search( 'POKEMON', fw_terms( 'pa_brand' ), true ) ), array( 'pa_brand: POKEMON @0', 'pokemon-3' ) );
check( '  and "base set" beside "Base Set" the same', array( fw_attrs( 803 )['pa_set'], fw_terms( 'pa_set' ) ), array( 'pa_set: base set @1', array( 'base-set' => 'Base Set', 'jungle' => 'Jungle', 'base-set-2' => 'base set', 'fossil' => 'Fossil' ) ) );
check( 'left as they are: a value a term would store otherwise ("&", runs of spaces), two values, a variation\'s', array( fw_attrs( 804 )['brand'], fw_attrs( 805 )['brand'], fw_attrs( 806 )['brand'], fw_attrs( 809 )['brand'] ), array( 'Brand: Pokemon &amp; Friends @0', 'Brand: Konami | Upper Deck @0', 'Brand: Pokemon | Bandai @0 [v]', 'Brand: Pokemon  TCG @0' ) );
check( '  their Set converted all the same', array( fw_attrs( 804 )['pa_set'], fw_attrs( 805 )['pa_set'], fw_attrs( 806 )['pa_set'] ), array( 'pa_set: Fossil @1', 'pa_set: Fossil @1', 'pa_set: Fossil @1' ) );
check( 'a product with the shop\'s own global Brand beside a custom one: both left, its Set converted', array( fw_attrs( 807 ), fw_record( 807 ) ), array( array( 'brand' => 'Brand: Pokemon @0', 'pa_brand' => 'pa_brand: Bandai @1', 'pa_set' => 'pa_set: Fossil @2' ), array( 'pa_set' => 'Set' ) ) );
check( '  one with only the shop\'s own: its Brand untouched and not recorded', array( fw_attrs( 808 ), fw_record( 808 ) ), array( array( 'pa_brand' => 'pa_brand: Bandai @0', 'pa_set' => 'pa_set: Fossil @1' ), array( 'pa_set' => 'Set' ) ) );
check( 'no product saved, and none dated', fw_dates(), $dates );
check( 'every push sends what it sent before: the item, the description and its content hash', fw_sends( $ids ), $plain );
$state = fw_state();
check( 'the run: done, every product looked at, the converted and skipped counted by reason, with examples', array( $state['status'], $state['looked'], $state['converted'], $state['skipped'], $state['samples'] ), array( 'done', 11, 9, array( 'value' => 2, 'several' => 1, 'variation' => 1, 'has_global' => 1 ), array( 'value' => array( 804, 809 ), 'several' => array( 805 ), 'variation' => array( 806 ), 'has_global' => array( 807 ) ) ) );
$line = fw_logged( 'finished' );
check( '  one line in the log: the names, the products converted, those skipped and why', $line, array( 'Filterable item specifics: run ' . $state['run'] . ' finished. Filterable: Brand, Set. 11 products looked at: 9 converted, 0 turned back, 0 given a brand, 0 brands taken off; skipped: 2 a value WordPress would store differently as a filter value (an "&", "<" or ">", or extra spaces) (WC #804, #809); 1 more than one value, separated by "|" (WC #805); 1 used for variations, which are left as they are (WC #806); 1 the global attribute is on the product already (WC #807). Nothing was sent to eBay.' ) );
check( '  and its start: the names, and no eBay call', array( count( fw_logged( 'run ' . $state['run'] . ' started. Filterable: Brand, Set. Brands: no. Nothing is sent to eBay.' ) ), $GLOBALS['API']->calls ), array( 1, array() ) );
$changed = array_values( array_unique( array_column( $GLOBALS['FW']['lookup']->changed, 0 ) ) );
sort( $changed );
check( 'WooCommerce\'s lookup table told of each product converted, as a new one', array( $changed, array_unique( array_column( $GLOBALS['FW']['lookup']->changed, 1 ) ) ), array( array( 801, 802, 803, 804, 805, 806, 807, 808, 810 ), array( null ) ) );
$cleaned = array_values( array_unique( $GLOBALS['FW']['cleaned'] ) );
sort( $cleaned );
check( '  and its post caches cleared (clean_post_cache(), fix round 1), which moves no date', $cleaned, array( 801, 802, 803, 804, 805, 806, 807, 808, 810 ) );
check( '  its transients, its cache group and the counts of the attributes written cleared for each', array(
	count( array_filter( $GLOBALS['FW']['cleared'], function ( $c ) { return 'transients' === $c[0]; } ) ),
	in_array( array( 'group', 'product_801' ), $GLOBALS['FW']['cleared'], true ),
	in_array( array( 'counts', 'pa_brand,pa_set' ), $GLOBALS['FW']['cleared'], true ),
), array( 9, true, true ) );
as_run( TCGiant_Sync_Filterable::COUNT_HOOK, true );
check( 'the run ends by counting again: how many have each as a filter (a variation\'s Brand not among them)', array( TCGiant_Sync_Filterable::counted()['Brand']['converted'], TCGiant_Sync_Filterable::counted()['Brand']['products'], TCGiant_Sync_Filterable::counted()['Set']['converted'] ), array( 4, 8, 9 ) );
fw_choose( array( 'Brand', 'Set' ) );
check( 'Save again with nothing changed: no run', array( fw_state()['run'] === $state['run'], count( fw_logged( 'started' ) ) ), array( true, 1 ) );
$made = count( $GLOBALS['FW']['inserted'] );
check( 'a value over WordPress\'s 200 characters for a term\'s name: no term, none made; 200 is one', array( TCGiant_Sync_Filterable::term_for( 'pa_brand', str_repeat( 'x', 201 ) ), count( $GLOBALS['FW']['inserted'] ) - $made, TCGiant_Sync_Filterable::term_for( 'pa_brand', str_repeat( 'é', 200 ) ) > 0 ), array( 0, 0, true ) );
$writes = $GLOBALS['FW']['term_sets'];
$s      = fw_state();
$s['status'] = 'running';
$s['cursor'] = 0;
update_option( TCGiant_Sync_Filterable::STATE_OPTION, $s, false );
as_schedule_single_action( time(), TCGiant_Sync_Filterable::CONVERT_HOOK, array(), TCGiant_Sync_Filterable::GROUP );
as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
check( 'a product looked at again settles the same: no term written, nothing moved', array( $GLOBALS['FW']['term_sets'] === $writes, fw_attrs( 801 ), fw_sends( $ids ) === $plain ), array( true, array( 'pa_brand' => 'pa_brand: Pokemon @0', 'pa_set' => 'pa_set: Base Set @1 [hidden]', 'card-name' => 'Card Name: Charizard\\Holo @2', 'size' => 'Size: S | M @3 [v]' ), true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PUSH BEFORE AND AFTER: EVERY BODY THE SAME, A TEMPLATE'S SPECIFICS TABLE AND {{attr.*}} INCLUDED\n" . str_repeat( '=', 124 ) . "\n";

/** The same shop pushed: converted first, or not. Every Trading call's body. */
function pushed_bodies( $convert, $template ) {
	fw_fresh();
	if ( $template ) {
		$tpl                             = make_template( array( 'name' => 'Clean', 'html' => '<h1>{{title}}</h1><p>{{attr.brand}} / {{attr.set}} / {{attr.pa_brand}} / {{attr.card-name}}</p>{{{description}}}{{{specifics_table}}}', 'css' => 'h1{font-size:20px}' ) );
		$settings                        = TCGiant_Sync_Templates::default_settings();
		$settings['default_template_id'] = $tpl;
		update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, $settings );
	}
	$products = array();
	foreach ( array( 821, 822, 823, 824 ) as $id ) {
		$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/item-' . $id . '.jpg';
	}
	// A new listing; an Update of a pushed one; an Update of an imported one
	// that keeps eBay's values; one with no description of its own.
	$products[] = fw_listing( 821, array( array( 'Brand', 'Pokemon', 0 ), array( 'Set', 'Base Set', 1, 0 ), array( 'Card Name', 'Charizard', 2 ) ), array( 'image_id' => 9821 ), array( '_ebay_item_id' => null, '_ebay_listing_status' => null, '_ebay_export_last_pushed' => null, '_ebay_origin' => null, '_ebay_site' => null ) );
	$products[] = fw_listing( 822, array( array( 'Card Name', 'Pikachu', 0 ), array( 'Brand', 'Pokemon', 1 ), array( 'Set', 'Jungle', 2 ) ), array( 'image_id' => 9822 ) );
	$products[] = fw_listing( 823, array( array( 'Brand', 'Wizards', 0 ), array( 'Set', 'Fossil', 1 ) ), array( 'image_id' => 9823 ), array( '_ebay_origin' => 'import', '_ebay_live' => array( 'item_id' => '110000000823', 'category_id' => '183454', 'condition_id' => '3000', 'policies' => array( 'shipping' => '1', 'return' => '2', 'payment' => '3' ), 'location' => 'Columbus, OH' ) ) );
	$products[] = fw_listing( 824, array( array( 'Set', 'Base Set', 0 ), array( 'Brand', 'Pokemon', 3 ) ), array( 'image_id' => 9824, 'description' => '' ) );
	if ( $convert ) {
		fw_count();
		fw_choose( array( 'Brand', 'Set' ) );
	}
	$GLOBALS['API']->calls = array();
	foreach ( $products as $product ) {
		fw_push( $product );
	}
	$out = fw_bodies();
	foreach ( $products as $product ) {
		$out[] = array( 'Verify', item_xml( $product, push_settings( $product ) ) );
	}
	return $out;
}
foreach ( array( false => 'no template', true => 'a template as the default' ) as $template => $label ) {
	$before = pushed_bodies( false, $template );
	$after  = pushed_bodies( true, $template );
	check( $label . ': Add, three Updates and four Verify bodies, byte for byte', array( count( $before ), array_column( $before, 0 ), $before === $after ), array( 8, array( 'AddFixedPriceItem', 'ReviseFixedPriceItem', 'ReviseFixedPriceItem', 'ReviseFixedPriceItem', 'Verify', 'Verify', 'Verify', 'Verify' ), true ) );
	check( '  the converted Brand and Set are in what they send', array( false !== strpos( $after[1][1], '<Name>Brand</Name>' ), false !== strpos( $after[1][1], '<Value>Jungle</Value>' ) ), array( true, true ) );
	if ( $template ) {
		check( '  the template\'s {{attr.brand}}, {{attr.set}} and specifics table as before; {{attr.pa_brand}} empty, as before', array( false !== strpos( $after[1][1], '<p>Pokemon / Jungle /  / Pikachu</p>' ), false !== strpos( $after[1][1], '>Brand</th><td style="vertical-align:top;padding:7px 10px;border-bottom:1px solid #dddddd">Pokemon</td>' ) ), array( true, true ) );
	} else {
		check( '  the plain description of a product with none, its attributes by value, as before', false !== strpos( $after[3][1], '<strong>Set:</strong> Base Set<br><strong>Brand:</strong> Pokemon' ), true );
	}
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nRE-SEND TO EBAY (3.34.0): A CONVERTED LISTING IS JUDGED AS BEFORE - DATE AND CONTENT HASH UNMOVED\n" . str_repeat( '=', 124 ) . "\n";

fw_fresh();
roomy();
$tpl                             = make_template( array( 'name' => 'Clean', 'html' => '<h1>{{title}}</h1><p>{{attr.brand}}</p>{{{description}}}{{{specifics_table}}}' ) );
$settings                        = TCGiant_Sync_Templates::default_settings();
$settings['default_template_id'] = $tpl;
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, $settings );
$a = fw_listing( 841, array( array( 'Brand', 'Pokemon', 0 ), array( 'Set', 'Base Set', 1 ) ) );
$b = fw_listing( 842, array( array( 'Brand', 'Wizards', 0 ), array( 'Set', 'Jungle', 1 ) ) );
sent_now( $a );
// 842 was last sent the plain description: no template hash, judged by its date.
$GLOBALS['W']['meta'][842]['_tcgiant_desc_hash']    = sha1( 'old plain description' );
$GLOBALS['W']['meta'][842]['_tcgiant_desc_sent_at'] = (string) ( time() - 172800 );
$GLOBALS['W']['meta'][842]['_tcgiant_tpl_item']     = '110000000842';
$hash_a  = (string) $GLOBALS['W']['meta'][841]['_tcgiant_content_hash'];
$before  = array( TCGiant_Sync_Template_Resend::judge( 841 )['verdict'], TCGiant_Sync_Template_Resend::judge( 842 )['verdict'], TCGiant_Sync_Template_Resend::judge( 842 )['group'] );
$counted = count_now();
fw_count();
fw_choose( array( 'Brand', 'Set' ) );
check( 'both converted', array( fw_record( 841 ), fw_record( 842 ) ), array( array( 'pa_brand' => 'Brand', 'pa_set' => 'Set' ), array( 'pa_brand' => 'Brand', 'pa_set' => 'Set' ) ) );
check( 'judged as before: one sent this template unchanged, one last sent plain still to be sent (its date unmoved)', array( TCGiant_Sync_Template_Resend::judge( 841 )['verdict'], TCGiant_Sync_Template_Resend::judge( 842 )['verdict'], TCGiant_Sync_Template_Resend::judge( 842 )['group'] ), $before );
check( '  which is unchanged, then to send in the template\'s group', $before, array( 'unchanged', 'send', 't' . $tpl ) );
check( '  the content hash the listing was sent with is the one its render makes now', exporter()->resend_description( $a, exporter()->resend_settings( $a ) )['content_hash'], $hash_a );
$recount = count_now();
check( 'Count again in the box: the same figures as before the conversion', array( $recount['unchanged'], $recount['groups'][ 't' . $tpl ]['count'] ?? null, $recount['reasons'] ), array( $counted['unchanged'], $counted['groups'][ 't' . $tpl ]['count'] ?? null, $counted['reasons'] ) );
TCGiant_Sync_Templates::save( $tpl, array( 'name' => 'Clean', 'status' => 'publish', 'html' => '<h1>{{title}}</h1><p>{{attr.brand}} :)</p>{{{description}}}{{{specifics_table}}}', 'css' => '' ), 1 );
$v = TCGiant_Sync_Template_Resend::judge( 841 );
check( 'the template saved again: the converted listing goes in its group to send, not held for a push (the 3: hash holds)', array( $v['verdict'], $v['group'], $v['reason'] ), array( 'send', 't' . $tpl, '' ) );
// Had the conversion saved the product, both would have been held.
$GLOBALS['W']['products'][841]->data['modified'] = time();
$GLOBALS['W']['products'][842]->data['modified'] = time();
check( '  (a product saved since its push: held - what a save would have done to every converted listing)', array( TCGiant_Sync_Template_Resend::judge( 842 )['reason'] ), array( 'held' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nBRANDS: EBAY'S BRAND INTO WOOCOMMERCE BRANDS, ONLY WHERE A PRODUCT HAS NONE\n" . str_repeat( '=', 124 ) . "\n";

fw_fresh();
fw_listing( 861, array( array( 'Brand', 'Pokemon', 0 ) ) );
check( 'without WooCommerce Brands on the site: the box says so, and Brand is only an attribute', array( TCGiant_Sync_Filterable::screen()['brands_here'] ), array( false ) );
fw_count();
fw_choose( array( 'Brand' ) );
check( '  converted, no brand anywhere', array( fw_attrs( 861 ), fw_state()['brands'], fw_state()['brands_added'] ), array( array( 'pa_brand' => 'pa_brand: Pokemon @0' ), false, 0 ) );

fw_fresh();
fw_brands_on();
$own_brand = fw_shop_term( 'product_brand', 'Bandai' );
fw_listing( 871, array( array( 'Brand', 'Pokemon', 0 ) ) );
fw_listing( 872, array( array( 'Brand', 'Pokemon', 0 ) ) );
wp_set_object_terms( 872, array( $own_brand ), 'product_brand' );
fw_listing( 873, array( array( 'Brand', 'Pokemon &amp; Co', 0 ) ) );
fw_listing( 874, array( array( 'Set', 'Jungle', 0 ) ) );
fw_listing( 875, array( array( 'Brand', 'Wizards', 0 ) ) );
$GLOBALS['FW']['term_sets'] = array();
$plain = fw_sends( array( 871, 872, 873, 875 ) );
fw_count();
fw_choose( array( 'Brand' ), false );
check( 'Brands ticked off: Brand converted, no brand added', array( fw_terms_of( 871, 'product_brand' ), fw_state()['brands'] ), array( array(), false ) );
fw_choose( array( 'Brand' ), true );
check( 'ticked on (the default): a run; a product with no brand gets eBay\'s, recorded', array( fw_terms_of( 871, 'product_brand' ), fw_record( 871 ), fw_state()['brands_added'] ), array( array( 'Pokemon' ), array( 'pa_brand' => 'Brand', 'product_brand' => 'term ' . fw_terms_of_id( 871 ) ), 2 ) );
check( '  the Brand attributes it converted last run counted as converted by none of this one\'s', fw_state()['converted'], 0 );
check( '  the shop\'s own brand is never replaced; a value a term cannot keep gets none; no Brand, none', array( fw_terms_of( 872, 'product_brand' ), fw_terms_of( 873, 'product_brand' ), fw_terms_of( 874, 'product_brand' ) ), array( array( 'Bandai' ), array(), array() ) );
check( '  the Brand attribute stays, so every push sends what it did', fw_sends( array( 871, 872, 873, 875 ) ), $plain );
wp_set_object_terms( 875, array( $own_brand ), 'product_brand' );
fw_choose( array( 'Brand' ), false );
check( 'ticked off again: the brand the plugin added taken off; one the shop has changed since kept', array( fw_terms_of( 871, 'product_brand' ), fw_terms_of( 875, 'product_brand' ), fw_terms_of( 872, 'product_brand' ), isset( TCGiant_Sync_Filterable::record( 871 )['product_brand'] ), isset( TCGiant_Sync_Filterable::record( 875 )['product_brand'] ), fw_state()['brands_removed'] ), array( array(), array( 'Bandai' ), array( 'Bandai' ), false, false, 1 ) );
check( '  the brand terms themselves stay', fw_terms( 'product_brand' ), array( 'bandai' => 'Bandai', 'pokemon' => 'Pokemon', 'wizards' => 'Wizards' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA SYNC AFTER THE CHOICE: CHOSEN NAMES WRITTEN AS THE GLOBAL ATTRIBUTE, NEVER BESIDE A CUSTOM ONE\n" . str_repeat( '=', 124 ) . "\n";

fw_fresh();
fw_brands_on();
$brand = fw_global_attribute( 'brand', 'Brand' );
$bandai = fw_shop_term( $brand, 'Bandai' );
TCGiant_Sync_OAuth::$settings['overwrite_attributes'] = '1';
fw_listing( 881, array( array( 'Brand', 'Pokemon', 0 ), array( 'Set', 'Jungle', 1 ) ), array(), array( '_ebay_origin' => 'import' ) );
fw_count();
fw_choose( array( 'Brand', 'Set' ), true, false );
// The run waits; a sync gets there first.
$GLOBALS['FW']['saves'] = array();
$new = fw_import( fw_ebay_item( '110000000990', array( 'Brand' => 'Pokemon', 'Set' => 'Base Set', 'Card Name' => 'Mew' ) ) );
check( 'a listing imported new: Brand and Set global from the start, Card Name its own, in the import\'s order, recorded as an import', array( fw_attrs( $new ), fw_record( $new ), TCGiant_Sync_Filterable::record( $new )['pa_brand']['run'] ?? '' ), array( array( 'pa_set' => 'pa_set: Base Set @0', 'card-name' => 'Card Name: Mew @1', 'pa_brand' => 'pa_brand: Pokemon @2' ), array( 'pa_set' => 'Set', 'pa_brand' => 'Brand', 'product_brand' => 'term ' . fw_terms_of_id( $new ) ), 'import' ) );
check( '  and given eBay\'s Brand in Brands', fw_terms_of( $new, 'product_brand' ), array( 'Pokemon' ) );
fw_listing( 991, array( array( 'Set', 'Base Set', 0 ), array( 'Card Name', 'Mew', 1 ), array( 'Brand', 'Pokemon', 2 ) ), array( 'name' => 'Card 110000000990', 'sku' => 'SKU-110000000990', 'stock' => 3 ) );
preg_match( '#<ItemSpecifics>.*?</ItemSpecifics>#s', item_xml( wc_get_product( $new ), push_settings( wc_get_product( $new ) ) ), $m1 );
preg_match( '#<ItemSpecifics>.*?</ItemSpecifics>#s', item_xml( wc_get_product( 991 ), push_settings( wc_get_product( 991 ) ) ), $m2 );
check( '  its push sends the item specifics a product with them as its own attributes sends', array( $m1[0] ?? 'none', strlen( $m1[0] ?? '' ) > 100 ), array( $m2[0] ?? 'none', true ) );
fw_import( fw_ebay_item( '110000000881', array( 'Brand' => 'Pokemon', 'Set' => 'Jungle', 'Rarity' => 'Holo' ), array( 'SKU' => 'SKU-881', 'Title' => 'Card 881' ) ) );
check( 'a product imported before the choice, synced: its custom Brand and Set replaced in place by the global ones, nothing beside them', array( fw_attrs( 881 ), fw_record( 881 ) ), array( array( 'pa_brand' => 'pa_brand: Pokemon @0', 'pa_set' => 'pa_set: Jungle @1', 'rarity' => 'Rarity: Holo @3' ), array( 'pa_set' => 'Set', 'pa_brand' => 'Brand', 'product_brand' => 'term ' . fw_terms_of_id( 881 ) ) ) );
fw_import( fw_ebay_item( '110000000881', array( 'Brand' => 'Pokémon', 'Set' => 'Jungle', 'Rarity' => 'Holo' ), array( 'SKU' => 'SKU-881', 'Title' => 'Card 881' ) ) );
check( 'synced again with a new value on eBay: the term follows it, as a custom attribute\'s value would', fw_attrs( 881 )['pa_brand'], 'pa_brand: Pokémon @0' );
check( '  its brand in Brands is not replaced', fw_terms_of( 881, 'product_brand' ), array( 'Pokemon' ) );
fw_import( fw_ebay_item( '110000000881', array( 'Brand' => 'Pokemon & Co', 'Set' => 'Jungle', 'Rarity' => 'Holo' ), array( 'SKU' => 'SKU-881', 'Title' => 'Card 881' ) ) );
check( 'a value a term cannot keep exactly: the custom attribute again, in its place, and the plugin\'s global one gone', array( fw_attrs( 881 ), fw_record( 881 ) ), array( array( 'brand' => 'Brand: Pokemon & Co @0', 'pa_set' => 'pa_set: Jungle @1', 'rarity' => 'Rarity: Holo @3' ), array( 'pa_set' => 'Set', 'product_brand' => 'term ' . fw_terms_of_id( 881 ) ) ) );
check( '  its old term taken off it, and no term made of the value it could not keep', array( fw_terms_of( 881, 'pa_brand' ), array_values( array_filter( fw_terms( 'pa_brand' ), function ( $name ) { return false !== strpos( $name, 'Co' ); } ) ) ), array( array(), array() ) );
fw_listing( 882, array( array( $brand, array( $bandai ), 0 ), array( 'Set', 'Fossil', 1 ) ), array(), array( '_ebay_origin' => 'import' ) );
fw_import( fw_ebay_item( '110000000882', array( 'Brand' => 'Pokemon', 'Set' => 'Fossil' ), array( 'SKU' => 'SKU-882', 'Title' => 'Card 882' ) ) );
check( 'the shop\'s own global Brand on a product: never touched, eBay\'s dropped as before', array( fw_attrs( 882 ), fw_record( 882 ) ), array( array( 'pa_brand' => 'pa_brand: Bandai @0', 'pa_set' => 'pa_set: Fossil @1' ), array( 'pa_set' => 'Set', 'product_brand' => 'term ' . fw_terms_of_id( 882 ) ) ) );
$made = count( $GLOBALS['FW']['inserted'] );
fw_import( fw_ebay_item( '110000000882', array( 'Brand' => 'Konami', 'Set' => 'Fossil' ), array( 'SKU' => 'SKU-882', 'Title' => 'Card 882' ) ) );
check( '  synced again with a value its Brand has no term for: none made (fix round 1, LOW 2), the shop\'s Brand as it was', array( array_slice( $GLOBALS['FW']['inserted'], $made ), fw_attrs( 882 )['pa_brand'], isset( fw_terms( 'pa_brand' )['konami'] ) ), array( array(), 'pa_brand: Bandai @0', false ) );
TCGiant_Sync_OAuth::$settings['overwrite_attributes'] = '0';
fw_listing( 883, array( array( 'Brand', 'Pokemon', 0 ) ), array(), array( '_ebay_origin' => 'import' ) );
$terms_made = count( $GLOBALS['FW']['inserted'] );
fw_import( fw_ebay_item( '110000000883', array( 'Brand' => 'Wizards' ), array( 'SKU' => 'SKU-883', 'Title' => 'Card 883' ) ) );
check( 'Overwrite Attributes off: the sync leaves the attributes as they are, makes no term and adds no brand', array( fw_attrs( 883 ), fw_record( 883 ), count( array_filter( array_slice( $GLOBALS['FW']['inserted'], $terms_made ), function ( $t ) { return 'pa_brand' === $t[0]; } ) ), fw_terms_of( 883, 'product_brand' ) ), array( array( 'brand' => 'Brand: Pokemon @0' ), array(), 0, array() ) );
as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
check( '  the run converts it, whatever that setting says (no value changes), and gives it its own Brand in Brands', array( fw_attrs( 883 ), fw_terms_of( 883, 'product_brand' ) ), array( array( 'pa_brand' => 'pa_brand: Pokemon @0' ), array( 'Pokemon' ) ) );
TCGiant_Sync_OAuth::$settings['overwrite_attributes'] = '1';
fw_choose( array( 'Set' ), true, false );
fw_import( fw_ebay_item( '110000000990', array( 'Brand' => 'Pokemon', 'Set' => 'Base Set', 'Card Name' => 'Mew' ) ) );
check( 'Brand taken off the list, synced before the run turns it back: the custom attribute again, in its place', array( fw_attrs( $new ), fw_record( $new ) ), array( array( 'pa_set' => 'pa_set: Base Set @0', 'card-name' => 'Card Name: Mew @1', 'brand' => 'Brand: Pokemon @2' ), array( 'pa_set' => 'Set', 'product_brand' => 'term ' . fw_terms_of_id( $new ) ) ) );
fw_import( fw_ebay_item( '110000000995', array( 'Brand' => 'Pokemon', 'Set' => 'Base | Jungle' ) ) );
$two = (int) array_search( '110000000995', array_map( function ( $id ) { return (string) ( $GLOBALS['W']['meta'][ $id ]['_ebay_item_id'] ?? '' ); }, array_combine( array_keys( $GLOBALS['W']['products'] ), array_keys( $GLOBALS['W']['products'] ) ) ), true );
check( 'an imported value WooCommerce reads as two ("Base | Jungle"): Set, chosen, stays its own attribute (Brand is no longer chosen)', array( $two > 0, fw_attrs( $two ), fw_record( $two ) ), array( true, array( 'set' => 'Set: Base | Jungle @0', 'brand' => 'Brand: Pokemon @1' ), array() ) );
$item = TCGiant_Sync_Filterable::for_import( array( 'size' => ( function () { $a = new WC_Product_Attribute(); $a->set_name( 'Set' ); $a->set_options( array( 'Jungle' ) ); $a->set_variation( true ); return $a; } )() ), new FW_Product( 0 ) );
check( 'a chosen name a listing\'s variations are made of: left to the variations', array( array_keys( $item['attributes'] ), $item['made'] ), array( array( 'size' ), array() ) );
unset( $GLOBALS['W']['products'][0] );
TCGiant_Sync_OAuth::$settings['overwrite_attributes'] = '0';

// ─────────────────────────────────────────────────────────────────────────────
echo "\nUNDO: A NAME TAKEN OFF TURNS THE PLUGIN'S OWN CONVERSIONS BACK, AND NOTHING OF THE SHOP'S\n" . str_repeat( '=', 124 ) . "\n";

$brand = conversion_shop();
$ids   = array( 801, 802, 803, 804, 805, 806, 807, 808, 809, 810, 811 );
$plain = fw_sends( $ids );
$attrs = array();
foreach ( $ids as $id ) {
	$attrs[ $id ] = fw_attrs( $id );
}
fw_count();
fw_choose( array( 'Brand', 'Set' ) );
$terms_then = fw_terms( 'pa_brand' );
$attributes = $GLOBALS['FW']['attributes'];
$dates      = fw_dates();
// The shop made one converted product's Set a variation axis, and added a custom Brand beside another's global one.
$GLOBALS['W']['meta'][805]['_product_attributes']['pa_set']['is_variation'] = 1;
$GLOBALS['W']['meta'][802]['_product_attributes']['brand2'] = array( 'name' => 'brand', 'value' => 'Hand made', 'position' => 9, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 0 );
fw_choose( array() );
$now = array();
foreach ( $ids as $id ) {
	$now[ $id ] = fw_attrs( $id );
}
check( 'every product as it was before the conversion: values, places, visibility', array_diff_key( $now, array( 802 => 1, 805 => 1 ) ), array_diff_key( $attrs, array( 802 => 1, 805 => 1 ) ) );
check( '  but one the shop made a variation since (left), and one with a custom Brand added beside (left)', array( $now[805], $now[802] ), array( array( 'brand' => 'Brand: Konami | Upper Deck @0', 'pa_set' => 'pa_set: Fossil @1 [v]' ), array( 'card-name' => 'Card Name: Pikachu @0', 'pa_brand' => 'pa_brand: Pokémon @1', 'set' => 'Set: Jungle @2', 'brand2' => 'brand: Hand made @9' ) ) );
$s = fw_state();
check( '  the run: 8 turned back, and why two were not', array( $s['undone'], $s['converted'], $s['skipped'] ), array( 8, 0, array( 'both' => 1, 'variation' => 1 ) ) );
check( 'the shop\'s own global Brand untouched on 807 and 808, and not recorded', array( fw_attrs( 807 )['pa_brand'], fw_attrs( 808 )['pa_brand'], fw_record( 808 ) ), array( 'pa_brand: Bandai @1', 'pa_brand: Bandai @0', array() ) );
check( 'the global attributes and their terms stay, for the shop to delete', array( $GLOBALS['FW']['attributes'] === $attributes, fw_terms( 'pa_brand' ) === $terms_then ), array( true, true ) );
check( '  the turned-back products hold no term of them', array( fw_terms_of( 801, 'pa_brand' ), fw_terms_of( 801, 'pa_set' ), fw_terms_of( 810, 'pa_set' ) ), array( array(), array(), array() ) );
check( '  every record of them gone but the two left', array_values( array_filter( $ids, function ( $id ) { return (bool) TCGiant_Sync_Filterable::record( $id ); } ) ), array( 802, 805 ) );
check( 'no product saved or dated', fw_dates(), $dates );
check( 'every push sends what it sent before the conversion', array_diff_key( fw_sends( $ids ), array( 802 => 1, 805 => 1 ) ), array_diff_key( $plain, array( 802 => 1, 805 => 1 ) ) );
check( 'the log: what was turned back', count( fw_logged( 'finished. Filterable: none. Turned back: Brand, Set. 11 products looked at: 0 converted, 8 turned back' ) ), 1 );
check( 'the choice now: none', TCGiant_Sync_Filterable::choice()['names'], array() );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA BATCH OUT OF TIME, OR KILLED, CARRIES ON: EACH PRODUCT SETTLED AND COUNTED ONCE\n" . str_repeat( '=', 124 ) . "\n";

/** A shop of $n products with a Brand and a Set; the choice saved; what is left of each product afterwards. */
function big_shop( $n ) {
	fw_fresh();
	for ( $i = 1; $i <= $n; $i++ ) {
		fw_listing( 3000 + $i, array( array( 'Brand', 'Brand ' . ( $i % 30 ), 0 ), array( 'Set', 'Set ' . ( $i % 7 ), 1, $i % 2 ), array( 'Card Name', 'Card ' . $i, 2 ) ) );
	}
	fw_count();
}
function big_result( $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$out[ 3000 + $i ] = array( fw_attrs( 3000 + $i ), fw_record( 3000 + $i ) );
	}
	return $out;
}

big_shop( 450 );
fw_choose( array( 'Brand', 'Set' ) );
$whole = big_result( 450 );
$s     = fw_state();
check( 'uninterrupted: 450 products in 3 batches of 200, every one converted', array( $s['looked'], $s['converted'], count( array_filter( $GLOBALS['AS']['ran'], function ( $h ) { return TCGiant_Sync_Filterable::CONVERT_HOOK === $h; } ) ) ), array( 450, 450, 3 ) );

big_shop( 450 );
$asked = 0;
TCGiant_Sync_Runtime::$late = function () use ( &$asked ) {
	return 0 === ( ++$asked % 7 ) ? '61 s of this request used' : '';
};
fw_choose( array( 'Brand', 'Set' ), true, false );
as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true, 1 );
$next = as_waiting( TCGiant_Sync_Filterable::CONVERT_HOOK );
check( 'out of time: the batch stops, keeps its place, and the next comes a minute on, in another request', array( fw_state()['looked'], count( $next ), ( $next[0]['time'] ?? 0 ) >= time() + 59 ), array( 7, 1, true ) );
as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
TCGiant_Sync_Runtime::$late = '';
$s = fw_state();
check( '  carried on to the end: the same products as an uninterrupted run, each counted once', array( big_result( 450 ) === $whole, $s['status'], $s['looked'], $s['converted'] ), array( true, 'done', 450, 450 ) );

foreach ( array( 1 => 'just after its record, before its terms', 2 => 'after one of its terms, before the other and its attribute list', 3 => 'after its attribute list, before its place was kept' ) as $at => $where ) {
	big_shop( 450 );
	fw_choose( array( 'Brand', 'Set' ), true, false );
	TCGiant_Sync_Runtime::$late = function () use ( $at ) {
		static $n = 0;
		if ( 120 === ++$n ) {
			$GLOBALS['FW']['die_at'] = $at;
		}
		return '';
	};
	$died = '';
	try {
		as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
	} catch ( RuntimeException $e ) {
		$died = $e->getMessage();
	}
	TCGiant_Sync_Runtime::$late = '';
	unset( $GLOBALS['FW']['die_at'] );
	$s = fw_state();
	check( 'the host kills a batch ' . $where . ': nothing waits to carry it on', array( $died, $s['status'], count( as_waiting( TCGiant_Sync_Filterable::CONVERT_HOOK ) ) ), array( 'killed', 'running', 0 ) );
	TCGiant_Sync_Filterable::maybe_resume();
	check( '  Settings drawn within ten minutes: still left alone, as a batch might yet be going', count( as_waiting( TCGiant_Sync_Filterable::CONVERT_HOOK ) ), 0 );
	$s['touched'] = time() - 601;
	update_option( TCGiant_Sync_Filterable::STATE_OPTION, $s, false );
	TCGiant_Sync_Filterable::maybe_resume();
	as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
	$s = fw_state();
	check( '  drawn after: carried on, with a line in the log; the same products as uninterrupted, each counted once', array( count( fw_logged( 'had stopped part way with nothing to carry it on' ) ), big_result( 450 ) === $whole, $s['status'], $s['looked'], $s['converted'] ), array( 1, true, 'done', 450, 450 ) );
}

big_shop( 60 );
fw_choose( array( 'Brand', 'Set' ) );
fw_choose( array( 'Set' ), true, false );
TCGiant_Sync_Runtime::$late = function () {
	static $n = 0;
	if ( 20 === ++$n ) {
		$GLOBALS['FW']['die_at'] = 1;
	}
	return '';
};
try {
	as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
} catch ( RuntimeException $e ) {
	unset( $GLOBALS['FW']['die_at'] );
}
TCGiant_Sync_Runtime::$late = '';
$s            = fw_state();
$s['touched'] = time() - 601;
update_option( TCGiant_Sync_Filterable::STATE_OPTION, $s, false );
TCGiant_Sync_Filterable::maybe_resume();
as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
$s    = fw_state();
$left = array();
for ( $i = 1; $i <= 60; $i++ ) {
	$a = fw_attrs( 3000 + $i );
	if ( isset( $a['pa_brand'] ) || ! isset( $a['brand'] ) || fw_terms_of( 3000 + $i, 'pa_brand' ) || isset( TCGiant_Sync_Filterable::record( 3000 + $i )['pa_brand'] ) ) {
		$left[] = 3000 + $i;
	}
}
check( 'an undo killed after a product\'s attribute list, before its terms came off: carried on, the product clean, counted once', array( $left, $s['status'], $s['looked'], $s['undone'] ), array( array(), 'done', 60, 60 ) );

// The hold: one batch at a time.
big_shop( 30 );
fw_choose( array( 'Brand' ), true, false );
$GLOBALS['wpdb']->rows[ TCGiant_Sync_Filterable::LOCK_PREFIX . 'run' ] = array( array( 1, ( time() + 300 ) . '|other' ) );
as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true, 1 );
$next = as_waiting( TCGiant_Sync_Filterable::CONVERT_HOOK );
check( 'another batch holding the run: this one does nothing and comes back a minute on', array( fw_state()['looked'], count( $next ), ( $next[0]['time'] ?? 0 ) >= time() + 59 ), array( 0, 1, true ) );
$GLOBALS['wpdb']->rows[ TCGiant_Sync_Filterable::LOCK_PREFIX . 'run' ] = array( array( 1, ( time() - 1 ) . '|dead' ) );
as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
check( '  a hold past its time, a killed request\'s: taken over, the run done, the hold let go', array( fw_state()['status'], fw_state()['converted'], $GLOBALS['wpdb']->rows[ TCGiant_Sync_Filterable::LOCK_PREFIX . 'run' ] ?? null ), array( 'done', 30, null ) );

// The count, killed part way.
big_shop( 1200 );
TCGiant_Sync_Filterable::queue_count();
as_run( TCGiant_Sync_Filterable::COUNT_HOOK, true, 1 );
$doing = get_option( TCGiant_Sync_Filterable::CHOICES_OPTION )['doing'];
check( 'a count part way: what it found kept, its place too', array( $doing['looked'], $doing['cursor'], isset( get_option( TCGiant_Sync_Filterable::CHOICES_OPTION )['done'] ) ), array( 500, 3500, true ) );
$GLOBALS['AS']['actions'] = array();
TCGiant_Sync_Filterable::maybe_resume();
check( '  its batch died: within ten minutes, left alone', count( as_waiting( TCGiant_Sync_Filterable::COUNT_HOOK ) ), 0 );
$c                     = get_option( TCGiant_Sync_Filterable::CHOICES_OPTION );
$c['doing']['touched'] = time() - 601;
update_option( TCGiant_Sync_Filterable::CHOICES_OPTION, $c, false );
TCGiant_Sync_Filterable::maybe_resume();
as_run( TCGiant_Sync_Filterable::COUNT_HOOK, true );
check( '  after: carried on from its place, every product counted once', array( TCGiant_Sync_Filterable::counted()['Brand']['products'], TCGiant_Sync_Filterable::counted()['Brand']['values'], TCGiant_Sync_Filterable::counted()['Set']['values'], get_option( TCGiant_Sync_Filterable::CHOICES_OPTION )['done']['products'] ), array( 1200, 30, 7, 1200 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEACH RUN'S ID: A BATCH OF AN EARLIER RUN WRITES NOTHING ONCE A NEW ONE HAS STARTED\n" . str_repeat( '=', 124 ) . "\n";

big_shop( 300 );
fw_choose( array( 'Brand' ), true, false );
$first = fw_state()['run'];
TCGiant_Sync_Runtime::$late = function () {
	static $n = 0;
	if ( 5 === ++$n ) {
		// The shop saves again while the first run's batch is part way.
		TCGiant_Sync_Filterable::save_choice( array( 'Brand', 'Set' ), true );
	}
	return '';
};
as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, false, 1 );
TCGiant_Sync_Runtime::$late = '';
$s = fw_state();
check( 'a new run, with its own ID; the old batch stopped at its next product and wrote nothing over it', array( $s['run'] !== $first, $s['status'], $s['looked'], $s['want'] ), array( true, 'running', 0, array( 'Brand' => 'pa_brand', 'Set' => 'pa_set' ) ) );
check( '  the old batch\'s products: the five it reached, Brand only', array( count( array_filter( range( 3001, 3300 ), function ( $id ) { return isset( fw_attrs( $id )['pa_brand'] ); } ) ), count( array_filter( range( 3001, 3300 ), function ( $id ) { return isset( fw_attrs( $id )['pa_set'] ); } ) ) ), array( 5, 0 ) );
as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
$s = fw_state();
check( '  the new run settles every product, the five included; only it finishes', array( $s['status'], $s['looked'], $s['converted'], count( array_filter( range( 3001, 3300 ), function ( $id ) { return isset( fw_attrs( $id )['pa_set'] ) && isset( fw_attrs( $id )['pa_brand'] ); } ) ), count( fw_logged( 'run ' . $first . ' finished' ) ), count( fw_logged( 'run ' . $s['run'] . ' finished' ) ) ), array( 'done', 300, 300, 300, 0, 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SETTINGS SECTION: ITS FORM, ITS NONCE AND CAPABILITY, AND WHAT IT SHOWS\n" . str_repeat( '=', 124 ) . "\n";

fw_fresh();
check( 'the section is on Settings, below the two cards (where its class is loaded)', false !== strpos( (string) file_get_contents( TCGIANT_SYNC_PATH . 'admin/views/settings.php' ), "\t</div>\n\n\t<?php\n\t// Filterable item specifics (3.35.0), where its class is loaded.\n\tif ( class_exists( 'TCGiant_Sync_Filterable' ) ) {\n\t\trequire TCGIANT_SYNC_PATH . 'admin/views/filterable.php';\n\t}\n\t?>\n</div>" ), true );
check( 'its form and its batches are registered, on every request', array( false !== has_action( 'admin_post_tcgiant_sync_filterable' ), false !== has_action( TCGiant_Sync_Filterable::CONVERT_HOOK ), false !== has_action( TCGiant_Sync_Filterable::COUNT_HOOK ), false !== strpos( (string) file_get_contents( TCGIANT_SYNC_PATH . 'includes/class-tcgiant-sync.php' ), "\t\tTCGiant_Sync_Filterable::register();" ) ), array( true, true, true, true ) );
$view = section();
check( 'never counted: drawing it starts the first count, and says so', array( false !== strpos( $view, 'Counting the item specifics on your products.' ), count( as_waiting( TCGiant_Sync_Filterable::COUNT_HOOK ) ) ), array( true, 1 ) );
fw_brands_on();
fw_listing( 891, array( array( 'Brand', 'Pokemon', 0 ), array( 'Set', 'Base Set', 1 ), array( 'Card Name', 'Mew', 2 ), array( 'Rarity', 'Holo', 3 ) ) );
as_run( TCGiant_Sync_Filterable::COUNT_HOOK, true );
$c = get_option( TCGiant_Sync_Filterable::CHOICES_OPTION );
$c['done']['names']['Rarity']['values'] = 312;
update_option( TCGiant_Sync_Filterable::CHOICES_OPTION, $c, false );
$view = section();
check( 'counted: a row for each name, a box to tick, the suggested first, the refused at the end and its box disabled', array(
	(bool) preg_match( '#Brand</strong>.*Set</strong>.*Rarity</strong>.*Card Name</strong>#', $view ),
	false !== strpos( $view, '<input type="checkbox" name="names[]" value="Brand">' ),
	false !== strpos( $view, '<input type="checkbox" name="names[]" value="Card Name" disabled=\'disabled\'>' ),
	substr_count( $view, 'Suggested.' ),
	false !== strpos( $view, '312 different values: a long list for a filter.' ),
	false !== strpos( $view, 'Not offered: it names one item' ),
), array( true, true, true, 2, true, true ) );
check( '  and the order to delete in', false !== strpos( $view, 'Take the tick off first: deleting a global attribute that is still ticked takes that item specific off every product that has it' ), true );
check( '  and that a value differing only in its capitals is a value of its own (fix round 1, LOW 3)', false !== strpos( $view, 'A value that differs from one already there only in its capitals (&quot;TOPPS&quot; and &quot;Topps&quot;) is kept as eBay has it, as a value of its own, so a filter can list both.' ), true );
check( '  the Brands box, ticked by default, and the mark that it was drawn', array( false !== strpos( $view, '<input type="checkbox" name="brands" value="1" checked=\'checked\'>' ), false !== strpos( $view, '<input type="hidden" name="brands_drawn" value="1">' ) ), array( true, true ) );
check( '  every form carries the nonce and its action', array( substr_count( $view, 'name="_wpnonce" value="nonce:tcgiant_sync_filterable"' ), substr_count( $view, 'name="action" value="tcgiant_sync_filterable"' ) ), array( 2, 2 ) );
$GLOBALS['W']['caps']['manage_options'] = false;
check( 'Save without manage_options: refused, nothing saved', array( handler( array( 'TCGiant_Sync_Filterable', 'handle_form' ), array( 'do' => 'save', 'names' => array( 'Brand' ) ) )[0], TCGiant_Sync_Filterable::choice()['names'] ), array( 'died', array() ) );
$GLOBALS['W']['caps'] = array();
$GLOBALS['nonce_ok']  = false;
check( '  without the section\'s nonce: refused', handler( array( 'TCGiant_Sync_Filterable', 'handle_form' ), array( 'do' => 'save', 'names' => array( 'Brand' ) ) )[0], 'died' );
$GLOBALS['nonce_ok'] = true;
$h = handler( array( 'TCGiant_Sync_Filterable', 'handle_form' ), array( 'do' => 'save', 'names' => array( 'Brand', 'Card Name', array( 'x' ) ), 'brands' => '1', 'brands_drawn' => '1' ) );
check( 'Save: the names ticked, its nonce, back to Settings at the section, with what happened', array( $h[0], $h[1], $GLOBALS['nonce_action'], TCGiant_Sync_Filterable::choice()['names'], $h[2]['type'] ?? '' ), array( 'redirect', 'https://shop.example/wp-admin/admin.php?page=tcgiant-settings#tc-filterable', 'tcgiant_sync_filterable', array( 'Brand' => 'pa_brand' ), 'warning' ) );
$view = section();
check( '  the section while it converts: how far it has got', false !== strpos( $view, 'Converting your products now.</strong> 0 of about 1 products looked at: 0 converted, 0 turned back, 0 skipped so far.' ), true );
as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
as_run( TCGiant_Sync_Filterable::COUNT_HOOK, true );
$view = section();
check( '  after: the last run, and Brand filterable on 1 of its 1 product', array( (bool) preg_match( '#Last converted \d+ \w+ \d{4}, \d\d:\d\d: 1 products converted, 0 turned back, 1 given a brand\.#', $view ), false !== strpos( $view, 'Filterable on 1 of the 1 products that have it.' ), false !== strpos( $view, '<input type="checkbox" name="names[]" value="Brand" checked=\'checked\'>' ) ), array( true, true, true ) );
handler( array( 'TCGiant_Sync_Filterable', 'handle_form' ), array( 'do' => 'save', 'names' => array( 'Brand' ), 'brands_drawn' => '1' ) );
check( 'the Brands box unticked: off', TCGiant_Sync_Filterable::choice()['brands'], false );
handler( array( 'TCGiant_Sync_Filterable', 'handle_form' ), array( 'do' => 'save', 'names' => array( 'Brand' ), 'brands' => '1', 'brands_drawn' => '1' ) );
handler( array( 'TCGiant_Sync_Filterable', 'handle_form' ), array( 'do' => 'save', 'names' => array( 'Brand' ) ) );
check( '  ticked again, then a Save where it was not drawn (no WooCommerce Brands then): kept as it was', TCGiant_Sync_Filterable::choice()['brands'], true );
as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
$h = handler( array( 'TCGiant_Sync_Filterable', 'handle_form' ), array( 'do' => 'count' ) );
check( 'Count again: a count queued, and said', array( $h[0], count( as_waiting( TCGiant_Sync_Filterable::COUNT_HOOK ) ), $h[2]['text'] ?? '' ), array( 'redirect', 1, 'Counting the item specifics on your products again. Reload this page in a minute for the figures.' ) );

// ─────────────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PRODUCT IS READ JUST BEFORE IT IS WRITTEN: AN EDIT MADE DURING THE BATCH IS KEPT (FIX ROUND 1, M1)\n" . str_repeat( '=', 124 ) . "\n";

fw_fresh();
for ( $i = 1; $i <= 12; $i++ ) {
	fw_listing( 3000 + $i, array( array( 'Brand', 'Brand ' . ( $i % 3 ), 0 ), array( 'Set', 'Base Set', 1 ) ) );
}
fw_count();
fw_choose( array( 'Brand' ), true, false );
// This request has read two of them already: WordPress keeps every read.
$GLOBALS['FW']['cache_reads'] = true;
get_post_meta( 3011, '_product_attributes', true );
get_post_meta( 3012, '_product_attributes', true );
$primed = $GLOBALS['FW']['primed'];
// While the batch is part way, other requests write: the shop adds "Grade: 9"
// to 3011 and clicks Update; a sync gives 3012 a new Set and a Rarity.
TCGiant_Sync_Runtime::$late = function () {
	static $n = 0;
	if ( 3 === ++$n ) {
		$GLOBALS['W']['meta'][3011]['_product_attributes']['grade'] = array( 'name' => 'Grade', 'value' => '9', 'position' => 2, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 0 );
		$GLOBALS['W']['meta'][3012]['_product_attributes']['set']['value'] = 'Jungle';
		$GLOBALS['W']['meta'][3012]['_product_attributes']['rarity'] = array( 'name' => 'Rarity', 'value' => 'Holo', 'position' => 2, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 0 );
	}
	return '';
};
as_run( TCGiant_Sync_Filterable::CONVERT_HOOK, true );
TCGiant_Sync_Runtime::$late = '';
$GLOBALS['FW']['cache_reads'] = false;
check( 'the shop\'s edit made while the batch was going: kept, and the product converted', fw_attrs( 3011 ), array( 'pa_brand' => 'pa_brand: Brand 2 @0', 'set' => 'Set: Base Set @1', 'grade' => 'Grade: 9 @2' ) );
check( '  a sync\'s write made then: kept the same', fw_attrs( 3012 ), array( 'pa_brand' => 'pa_brand: Brand 0 @0', 'set' => 'Set: Jungle @1', 'rarity' => 'Rarity: Holo @2' ) );
check( '  the run primed no product: each was read as it came to it', array( $GLOBALS['FW']['primed'] - $primed, fw_state()['converted'] ), array( 0, 12 ) );

// The count primes its batch, and lets go of every product in it, those it
// did not reach included, when it stops for time.
fw_fresh();
for ( $i = 1; $i <= 12; $i++ ) {
	fw_listing( 3000 + $i, array( array( 'Brand', 'Brand ' . $i, 0 ) ) );
}
TCGiant_Sync_Runtime::$late = function () {
	static $n = 0;
	return 3 === ++$n ? '61 s of this request used' : '';
};
TCGiant_Sync_Filterable::queue_count();
as_run( TCGiant_Sync_Filterable::COUNT_HOOK, true, 1 );
TCGiant_Sync_Runtime::$late = '';
check( 'a count stopped for time after 3 of its 12: none of the 12 left as it read them, for another job in the request', array( get_option( TCGiant_Sync_Filterable::CHOICES_OPTION )['doing']['looked'] ?? null, $GLOBALS['FW']['primed'] >= 12, array_keys( $GLOBALS['FW']['meta_cache'] ) ), array( 3, true, array() ) );
as_run( TCGiant_Sync_Filterable::COUNT_HOOK, true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE TEMPLATE EDITOR OFFERS THE PLACEHOLDER A CONVERTED PRODUCT ANSWERS TO (FIX ROUND 1, M2)\n" . str_repeat( '=', 124 ) . "\n";

if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action = -1 ) { return $url . '&_wpnonce=nonce'; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) { echo $out; } return $out; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = null ) { return esc_attr( $text ); }
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $id ) { return false; }
}
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $id ) { return 'post.php?post=' . (int) $id; }
}
require_once TCGIANT_SYNC_PATH . 'includes/class-tcgiant-sync-template-admin.php';

/** The template editor's page for a template. */
function editor_page( $template ) {
	$_GET = array( 'id' => $template, 'view' => 'edit' );
	ob_start();
	include TCGIANT_SYNC_PATH . 'admin/views/template-edit.php';
	$page = ob_get_clean();
	$_GET = array();
	return $page;
}

/** The editor's plain lines under its Attributes list (fix round 2, N1). */
function editor_notes( $template ) {
	preg_match( '#<h3 class="tc-insert-group">Attributes</h3>\s*<ul class="tc-insert-list">.*?</ul>((?:\s*<p class="tc-hint">.*?</p>)*)#s', editor_page( $template ), $m );
	preg_match_all( '#<p class="tc-hint">\s*(.*?)\s*</p>#s', $m[1] ?? '', $lines );
	return array_map( function ( $line ) { return html_entity_decode( $line, ENT_QUOTES ); }, $lines[1] );
}

/** The editor's Attributes list for a template: placeholder => label. */
function editor_attributes( $template ) {
	$page = editor_page( $template );
	$out  = array();
	if ( preg_match( '#<h3 class="tc-insert-group">Attributes</h3>\s*<ul class="tc-insert-list">(.*?)</ul>#s', $page, $m ) ) {
		preg_match_all( '#data-insert="([^"]*)" title="[^"]*">([^<]*)</button>#', $m[1], $items, PREG_SET_ORDER );
		foreach ( $items as $item ) {
			$out[ html_entity_decode( $item[1], ENT_QUOTES ) ] = html_entity_decode( $item[2], ENT_QUOTES );
		}
	}
	return $out;
}

fw_fresh();
$maker = fw_global_attribute( 'manufacturer', 'Brand' );
$own   = fw_shop_term( $maker, 'Bandai' );
fw_global_attribute( 'set', 'Set' );
$long = 'Original/Licensed Reprint Details Extra';
fw_listing( 3101, array( array( 'Brand', 'Pokemon', 0 ), array( 'Year', '1999', 1 ), array( $long, 'Original', 2 ), array( 'Set', 'Base Set', 3 ) ) );
fw_listing( 3102, array( array( $maker, array( $own ), 0 ) ) );
fw_count();
fw_choose( array( 'Brand', 'Year', $long, 'Set' ) );
check( 'chosen: Brand on the shop\'s own "manufacturer", Year as year-2, the long name cut to 28, Set on its own slug', TCGiant_Sync_Filterable::choice()['names'], array( 'Brand' => 'pa_manufacturer', $long => 'pa_original-licensed-reprint-de', 'Set' => 'pa_set', 'Year' => 'pa_year-2' ) );
$tpl    = make_template( array( 'name' => 'Attrs', 'html' => '<p>{{attr.brand}}|{{attr.manufacturer}}|{{attr.year}}|{{attr.year-2}}|{{attr.original-licensed-reprint-details-extra}}|{{attr.original-licensed-reprint-de}}|{{attr.set}}</p>' ) );
$offers = editor_attributes( $tpl );
check( 'where the slug is not the eBay name\'s, the editor offers both, each saying which products it fills', $offers, array(
	'{{attr.brand}}'                                   => 'Brand, on products with it as an eBay item specific, made filterable or not',
	'{{attr.manufacturer}}'                            => 'Brand, on products given it any other way',
	'{{attr.set}}'                                     => 'Set',
	'{{attr.year}}'                                    => 'Year, on products with it as an eBay item specific, made filterable or not',
	'{{attr.year-2}}'                                  => 'Year, on products given it any other way',
	'{{attr.original-licensed-reprint-details-extra}}' => $long . ', on products with it as an eBay item specific, made filterable or not',
	'{{attr.original-licensed-reprint-de}}'            => $long . ', on products given it any other way',
) );
check( '  and no plain line under the list: every eBay name here has a key a placeholder can use', editor_notes( $tpl ), array() );
$settings                        = TCGiant_Sync_Templates::default_settings();
$settings['default_template_id'] = $tpl;
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, $settings );
preg_match( '#<p>(.*?)</p>#', described( wc_get_product( 3101 ) )['html'], $converted );
preg_match( '#<p>(.*?)</p>#', described( wc_get_product( 3102 ) )['html'], $shops );
check( '  on a converted product, the placeholder offered for it renders its value; the other is empty, as before', $converted[1] ?? '', 'Pokemon||1999||Original||Base Set' );
check( '  on the shop\'s own product, the one offered for it does', $shops[1] ?? '', '|Bandai|||||' );
$filled = array();
foreach ( $offers as $code => $label ) {
	if ( false !== strpos( $label, 'made filterable or not' ) || false === strpos( $label, ',' ) ) {
		$settings['default_template_id'] = make_template( array( 'name' => 'One', 'html' => '<p>' . $code . '</p>' ) );
		update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, $settings );
		$filled[ $code ] = preg_match( '#<p>([^<]+)</p>#', described( wc_get_product( 3101 ) )['html'] ) ? true : false;
	}
}
check( '  every placeholder the editor offers for converted products renders a value on one', $filled, array( '{{attr.brand}}' => true, '{{attr.set}}' => true, '{{attr.year}}' => true, '{{attr.original-licensed-reprint-details-extra}}' => true ) );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, TCGiant_Sync_Templates::default_settings() );
check( 'with nothing chosen, the editor lists each global attribute by its slug, as before', ( function () {
	update_option( TCGiant_Sync_Filterable::CHOICE_OPTION, array(), false );
	return editor_attributes( make_template( array( 'name' => 'Plain' ) ) );
} )(), array( '{{attr.manufacturer}}' => 'Brand', '{{attr.set}}' => 'Set', '{{attr.year-2}}' => 'Year', '{{attr.original-licensed-reprint-de}}' => $long ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE EDITOR NEVER OFFERS A PLACEHOLDER THE RENDERER CANNOT READ (FIX ROUND 2, N1)\n" . str_repeat( '=', 124 ) . "\n";

/** On each product, what a template renders between <p> and </p>. */
function rendered( $html, array $ids ) {
	$settings                        = TCGiant_Sync_Templates::default_settings();
	$settings['default_template_id'] = make_template( array( 'name' => 'Render', 'html' => '<p>' . $html . '</p>' ) );
	update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, $settings );
	$out = array();
	foreach ( $ids as $id ) {
		$out[ $id ] = preg_match( '#<p>(.*?)</p>#s', described( wc_get_product( $id ) )['html'], $m ) ? $m[1] : null;
	}
	update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, TCGiant_Sync_Templates::default_settings() );
	return $out;
}

fw_fresh();
$ru = 'Марка';
$ja = 'ブランド';
fw_listing( 3301, array( array( $ru, 'Sony', 0 ), array( $ja, 'Nintendo', 1 ), array( 'Year', '2001', 2 ) ) );
fw_count();
check( 'WordPress keys a name in another script by its percent-encoded letters: a key the renderer cannot read as a name', array( array_keys( $GLOBALS['W']['meta'][3301]['_product_attributes'] ), (bool) preg_match( '/^' . TCGiant_Sync_Template_Renderer::NAME . '$/', sanitize_title( $ru ) ) ), array( array( '%d0%bc%d0%b0%d1%80%d0%ba%d0%b0', '%e3%83%96%e3%83%a9%e3%83%b3%e3%83%89', 'year' ), false ) );
$table  = '{{#specifics}}{{name}}={{value}};{{/specifics}}';
$before = rendered( $table . '|{{attr.' . sanitize_title( $ru ) . '}}|{{attr.year}}', array( 3301 ) );
fw_choose( array( $ru, $ja, 'Year' ) );
$made = TCGiant_Sync_Filterable::choice()['names'];
check( 'chosen: each made a global attribute, "specific-" and a hash for the two, year-2 for Year', array( (bool) preg_match( '/^pa_specific-[0-9a-f]{8}$/', $made[ $ru ] ?? '' ), (bool) preg_match( '/^pa_specific-[0-9a-f]{8}$/', $made[ $ja ] ?? '' ), $made['Year'] ?? '', count( fw_attrs( 3301 ) ), isset( fw_attrs( 3301 )[ $made[ $ru ] ?? '' ], fw_attrs( 3301 )[ $made[ $ja ] ?? '' ], fw_attrs( 3301 )['pa_year-2'] ) ), array( true, true, 'pa_year-2', 3, true ) );
$tpl    = make_template( array( 'name' => 'Scripts' ) );
$offers = editor_attributes( $tpl );
$ru_tag = '{{attr.' . substr( $made[ $ru ], 3 ) . '}}';
$ja_tag = '{{attr.' . substr( $made[ $ja ], 3 ) . '}}';
check( 'the editor offers no key with a "%": the two by their slugs, for products given them any other way, and Year both ways', $offers, array(
	$ru_tag          => $ru . ', on products given it any other way',
	$ja_tag          => $ja . ', on products given it any other way',
	'{{attr.year}}'   => 'Year, on products with it as an eBay item specific, made filterable or not',
	'{{attr.year-2}}' => 'Year, on products given it any other way',
) );
check( '  every placeholder it offers is one the renderer reads as a name', array_values( array_filter( array_keys( $offers ), function ( $code ) { return ! preg_match( '/^\{\{attr\.' . TCGiant_Sync_Template_Renderer::NAME . '\}\}$/', $code ) || false !== strpos( $code, '%' ); } ) ), array() );
check( '  and a plain line under the list says so of each, and that the Item specifics table still shows it', editor_notes( $tpl ), array(
	$ru . ': this item specific\'s name cannot be used as a placeholder, so none is listed for the products it was made filterable on. The Item specifics table still shows it.',
	$ja . ': this item specific\'s name cannot be used as a placeholder, so none is listed for the products it was made filterable on. The Item specifics table still shows it.',
) );
check( '  on the converted product: the item specifics as before, the "%" key printed as it was, Year as before', rendered( $table . '|{{attr.' . sanitize_title( $ru ) . '}}|{{attr.year}}', array( 3301 ) ), $before );
check( '  and its item specifics show both names with their values', false !== strpos( (string) $before[3301], $ru . '=Sony;' ) && false !== strpos( (string) $before[3301], $ja . '=Nintendo;' ), true );
fw_listing( 3302, array( array( $made[ $ru ], array( fw_shop_term( $made[ $ru ], 'Panasonic' ) ), 0 ), array( $made[ $ja ], array( fw_shop_term( $made[ $ja ], 'Sega' ) ), 1 ), array( 'pa_year-2', array( fw_shop_term( 'pa_year-2', '1999' ) ), 2 ) ) );
$filled = array();
foreach ( array_keys( $offers ) as $code ) {
	$filled[ $code ] = array_values( array_filter( rendered( $code, array( 3301, 3302 ) ), 'strlen' ) );
}
check( '  every placeholder offered renders a value on a product: the converted one, or one given the attribute by the shop', $filled, array( $ru_tag => array( 'Panasonic' ), $ja_tag => array( 'Sega' ), '{{attr.year}}' => array( '2001' ), '{{attr.year-2}}' => array( '1999' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTWO THAT WOULD OFFER THE SAME PLACEHOLDER ARE LISTED ONCE, WITH BOTH LABELS (FIX ROUND 2, N2)\n" . str_repeat( '=', 124 ) . "\n";

fw_fresh();
$card_set = fw_global_attribute( 'set', 'Card Set' );
fw_listing( 3401, array( array( 'Set', 'Jungle', 0 ), array( 'Year', '1999', 1 ) ) );
fw_listing( 3402, array( array( $card_set, array( fw_shop_term( $card_set, 'Base Set' ) ), 0 ) ) );
fw_count();
$pair   = '{{attr.set}}|{{attr.set-2}}';
$before = rendered( $pair, array( 3401, 3402 ) );
fw_choose( array( 'Set' ) );
check( 'the shop\'s "Card Set" is on set, so eBay\'s "Set" is made set-2, and 3401 converted', array( TCGiant_Sync_Filterable::choice()['names'], fw_attrs( 3401 )['pa_set-2'] ?? '' ), array( array( 'Set' => 'pa_set-2' ), 'pa_set-2: Jungle @0' ) );
$offers = editor_attributes( make_template( array( 'name' => 'Sets' ) ) );
check( 'the editor keeps both on {{attr.set}}, each labelled with the products it fills; set-2 for products given it any other way', $offers, array(
	'{{attr.set}}'   => 'Card Set, on products that have it / Set, on products with it as an eBay item specific, made filterable or not',
	'{{attr.set-2}}' => 'Set, on products given it any other way',
) );
check( '  {{attr.set}} renders as before on both: eBay\'s Set on the converted product, the shop\'s Card Set on its own', rendered( $pair, array( 3401, 3402 ) ), $before );
check( '  which is what it rendered before the conversion', $before, array( 3401 => 'Jungle|', 3402 => 'Base Set|' ) );
fw_listing( 3403, array( array( 'pa_set-2', array( fw_shop_term( 'pa_set-2', 'Fossil' ) ), 0 ) ) );
check( '  and {{attr.set-2}} renders on a product given it any other way', rendered( $pair, array( 3403 ) ), array( 3403 => '|Fossil' ) );

// The other order (review3): the chosen attribute listed first, the plain one
// on its key second. WooCommerce lists attributes by slug, so the shop's own
// "Set" on card-set, reused for eBay's "Set" (key set), comes before a plain
// "Expansion" on set; the plain entry must add its label, not replace.
fw_fresh();
$own_set   = fw_global_attribute( 'card-set', 'Set' );
$expansion = fw_global_attribute( 'set', 'Expansion' );
fw_listing( 3501, array( array( 'Set', 'Jungle', 0 ) ) );
fw_listing( 3502, array( array( $expansion, array( fw_shop_term( $expansion, 'Base Set' ) ), 0 ) ) );
fw_count();
$pair   = '{{attr.set}}|{{attr.card-set}}';
$before = rendered( $pair, array( 3501, 3502 ) );
fw_choose( array( 'Set' ) );
check( 'the other order: eBay\'s "Set" reuses the shop\'s "Set" on card-set, listed before the plain "Expansion" on set', array( TCGiant_Sync_Filterable::choice()['names'], fw_attrs( 3501 )['pa_card-set'] ?? '', array_map( function ( $a ) { return $a->attribute_name; }, wc_get_attribute_taxonomies() ) ), array( array( 'Set' => 'pa_card-set' ), 'pa_card-set: Jungle @0', array( 'card-set', 'set' ) ) );
check( '  the editor keeps both on {{attr.set}}, the plain one added after, not in place of, the chosen one', editor_attributes( make_template( array( 'name' => 'Sets, other order' ) ) ), array(
	'{{attr.set}}'      => 'Set, on products with it as an eBay item specific, made filterable or not / Expansion, on products that have it',
	'{{attr.card-set}}' => 'Set, on products given it any other way',
) );
check( '  {{attr.set}} renders as before on both', array( rendered( $pair, array( 3501, 3502 ) ), $before ), array( array( 3501 => 'Jungle|', 3502 => 'Base Set|' ), array( 3501 => 'Jungle|', 3502 => 'Base Set|' ) ) );
fw_listing( 3503, array( array( $own_set, array( fw_shop_term( $own_set, 'Fossil' ) ), 0 ) ) );
check( '  and {{attr.card-set}} renders on a product given it any other way', rendered( $pair, array( 3503 ) ), array( 3503 => '|Fossil' ) );

// The mutation run leaves the 20,000 out, but for the mutants of the count.
if ( getenv( 'TCG_FILTERABLE_QUICK' ) ) {
	done();
}

echo "\n20,000 PRODUCTS: THE CHOICES LIST OUT OF AUTOLOAD AND WITHIN MEMORY\n" . str_repeat( '=', 124 ) . "\n";

fw_fresh();
for ( $i = 1; $i <= 20000; $i++ ) {
	$id = 100000 + $i;
	new FW_Product( $id, array( 'name' => 'Card ' . $i ) );
	$GLOBALS['W']['meta'][ $id ]['_product_attributes'] = array(
		'brand'       => array( 'name' => 'Brand', 'value' => 'Brand ' . ( $i % 40 ), 'position' => 0, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 0 ),
		'set'         => array( 'name' => 'Set', 'value' => 'Set ' . ( $i % 300 ), 'position' => 1, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 0 ),
		'card-name'   => array( 'name' => 'Card Name', 'value' => 'A card with a long name, number ' . $i, 'position' => 2, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 0 ),
		'card-number' => array( 'name' => 'Card Number', 'value' => $i . '/20000', 'position' => 3, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 0 ),
		'grade'       => array( 'name' => 'Grade', 'value' => (string) ( $i % 10 ), 'position' => 4, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 0 ),
	);
}
$started = microtime( true );
$memory  = memory_get_usage();
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
TCGiant_Sync_Filterable::queue_count();
$largest = 0;
$batches = 0;
while ( as_run( TCGiant_Sync_Filterable::COUNT_HOOK, true, 1 ) ) {
	$batches++;
	$largest = max( $largest, strlen( serialize( get_option( TCGiant_Sync_Filterable::CHOICES_OPTION ) ) ) );
}
$peak  = function_exists( 'memory_reset_peak_usage' ) ? memory_get_peak_usage() - $memory : 0;
$names = TCGiant_Sync_Filterable::counted();
check( 'counted in 40 batches of 500, and one that found none left', array( $batches, $names['Brand']['products'], get_option( TCGiant_Sync_Filterable::CHOICES_OPTION )['done']['products'] ), array( 41, 20000, 20000 ) );
check( '  Brand 40 values, Set 300, Grade 10; Card Name and Card Number over 2,000, their values let go', array( $names['Brand']['values'], $names['Set']['values'], $names['Grade']['values'], $names['Card Name']['over'], $names['Card Number']['over'] ), array( 40, 300, 10, true, true ) );
check( '  never autoloaded', $GLOBALS['W']['autoload'][ TCGiant_Sync_Filterable::CHOICES_OPTION ] ?? null, 'no' );
check( '  the list small at every step - under 32 KB with two names counting their way up to 2,000 values - the finished one under 1 KB', array( $largest < 32768, strlen( serialize( get_option( TCGiant_Sync_Filterable::CHOICES_OPTION ) ) ) < 1024 ), array( true, true ) );
check( '  and within memory: under 4 MB more kept, and (PHP 8.2 on) under 16 MB at its peak', array( memory_get_usage() - $memory < 4194304, $peak < 16777216 ), array( true, true ) );
printf( "  (counted 20,000 in %.1f s; largest row %d bytes; %.1f MB more at the peak)\n", microtime( true ) - $started, $largest, $peak / 1048576 );

done();
