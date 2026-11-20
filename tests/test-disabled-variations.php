<?php
/**
 * Harness: a disabled variation enabled again, and a disabled variation
 * keeping its own stock (3.44.0).
 *
 * 3.43.0 sent a disabled variation that takes its product's shared stock 0
 * on eBay, once, and left it out after; enabled again, it stayed at 0 on eBay
 * until the next stock edit or Send stock to eBay. Now:
 *
 *  - Enabled again, a variation of a variable product on eBay has its stock
 *    sent as a stock change of it or its parent would send it: the shared
 *    stock's push, or its own. Every rule of a stock change applies. One that
 *    eBay removed when it was set to 0 before it had ever sold (3.43.0's
 *    _ebay_variation_zeroed) has no variation on eBay to send to: nothing is
 *    queued, the log says so once, and the product's edit screen says an
 *    Update adds it back.
 *  - A disabled variation keeping its own stock gets 3.43.0's rule for the
 *    shared stock: its stock change, or Send stock to eBay, sends it 0 once
 *    while eBay shows it with stock, then leaves it out; never 0 while it is
 *    the listing's only variation with stock; its record of eBay's sales
 *    written before the 0; marked when it never sold; and the import leaves
 *    it disabled, and never writes eBay's 0 over its stock.
 *
 * The world is tests/test-pool-disabled.php's - tests/test-stock-push.php's,
 * taken as it stands up to its first case - with WooCommerce 11.2.2's save
 * of a product object played. Two changes to the world's product, each
 * checked to apply exactly once:
 *
 *  - get_status() reads a status set and not yet saved, as WooCommerce's
 *    does (WC_Data::get_prop() reads the changes first);
 *  - save() fires woocommerce_before_product_object_save before it writes
 *    and woocommerce_after_product_object_save after, as WC_Product::save()
 *    does (includes/abstracts/abstract-wc-product.php, 11.2.2) for every
 *    product object - a variation's too, whose object type is 'product'
 *    (includes/class-wc-product-variation.php sets none of its own).
 *
 * Then tests/test-pool-disabled.php's helpers, taken as they stand from its
 * world to its first case. WooCommerce 11.2.2's four ways of enabling a
 * variation are played as its source does them (each function says where).
 * A staging copy is a child run of this file with TCGIANT_SYNC_IS_STAGING
 * defined, as wp-config.php defines it.
 *
 * @package TCGiant_Sync
 */

$STAGING_CHILD = in_array( '--staging', (array) ( $argv ?? array() ), true );
if ( $STAGING_CHILD ) {
	define( 'TCGIANT_SYNC_IS_STAGING', true );
}

// --- The world: tests/test-stock-push.php's, up to its first case, with
// WooCommerce's save of a product object --------------------------------------

$WORLD_SRC = (string) file_get_contents( __DIR__ . '/test-stock-push.php' );
$WORLD_CUT = strpos( $WORLD_SRC, 'echo "' . chr( 92 ) . 'nTHE HOOKS' );
$PATCHES   = array(
	// WC_Data::get_prop(): a status set and not yet saved is the status.
	"\tpublic function get_status() { return \$GLOBALS['POSTS'][ \$this->id ]['status'] ?? 'publish'; }\n"
		=> "\tpublic function get_status( \$context = 'view' ) { return array_key_exists( '__status', \$this->changes ) ? \$this->changes['__status'] : ( \$GLOBALS['POSTS'][ \$this->id ]['status'] ?? 'publish' ); }\n",
	// WC_Product::save(): the action before anything is written.
	"\tpublic function save() {\n\t\tif ( ! \$this->id ) {\n"
		=> "\tpublic function save() {\n\t\tdo_action( 'woocommerce_before_product_object_save', \$this, null );\n\t\tif ( ! \$this->id ) {\n",
	// ... and the action after the data store's update and its actions.
	"\t\t\tdo_action( \$variation ? 'woocommerce_update_product_variation' : 'woocommerce_update_product', \$this->id, \$this );\n\t\t}\n\t\treturn \$this->id;\n"
		=> "\t\t\tdo_action( \$variation ? 'woocommerce_update_product_variation' : 'woocommerce_update_product', \$this->id, \$this );\n\t\t}\n\t\tdo_action( 'woocommerce_after_product_object_save', \$this, null );\n\t\treturn \$this->id;\n",
);
$PATCHED = false === $WORLD_CUT ? -1 : 0;
if ( false !== $WORLD_CUT ) {
	$WORLD_SRC = substr( $WORLD_SRC, 0, $WORLD_CUT );
	foreach ( $PATCHES as $from => $to ) {
		$PATCHED  += 1 === substr_count( $WORLD_SRC, $from ) ? 1 : 0;
		$WORLD_SRC = str_replace( $from, $to, $WORLD_SRC );
	}
}
if ( count( $PATCHES ) !== $PATCHED ) {
	echo "the world of tests/test-stock-push.php is not as this harness takes it\n\n  0 passed, 1 failed\n";
	exit( 1 );
}
$WORLD_SRC = str_replace( '__DIR__', var_export( __DIR__, true ), $WORLD_SRC );
eval( '?>' . $WORLD_SRC );
unset( $WORLD_SRC );

// tests/test-pool-disabled.php's helpers: the polo, its listing, the import.
$POOL_SRC  = (string) file_get_contents( __DIR__ . '/test-pool-disabled.php' );
$POOL_FROM = strpos( $POOL_SRC, "if ( ! function_exists( 'number_format_i18n' ) )" );
$POOL_TO   = strpos( $POOL_SRC, 'echo "' . chr( 92 ) . 'nA DISABLED VARIATION STILL ON EBAY' );
if ( false === $POOL_FROM || false === $POOL_TO ) {
	echo "the helpers of tests/test-pool-disabled.php are not as this harness takes them\n\n  0 passed, 1 failed\n";
	exit( 1 );
}
eval( substr( $POOL_SRC, $POOL_FROM, $POOL_TO - $POOL_FROM ) );
unset( $POOL_SRC );

// --- WordPress's screen, for the product edit screen's notice ----------------

$SCREEN_ID = 'product';
function get_current_screen() { return null === $GLOBALS['SCREEN_ID'] ? null : (object) array( 'id' => $GLOBALS['SCREEN_ID'] ); }

// --- WooCommerce 11.2.2's ways of enabling (or disabling) a variation --------

/**
 * A variation's own save (WooCommerce's CRUD): set_status(), then save() -
 * WC_Product::save(), which fires the two actions round its data store's
 * update (abstract-wc-product.php, 11.2.2, 1546-1586).
 */
function wc_set_status( $id, $status ) {
	$v = wc_get_product( $id );
	$v->set_status( $status );
	$v->save();
}
/**
 * The variations panel's Save changes (WC_AJAX::save_variations(),
 * class-wc-ajax.php 3134-3150, an admin-ajax request): the parent saved
 * first, then each variation posted - set_props() with 'status' =>
 * isset( $_POST['variable_enabled'][ $i ] ) ? publish : private and its stock,
 * woocommerce_admin_process_variation_object, save(),
 * woocommerce_save_product_variation (WC_Meta_Box_Product_Data::
 * save_variations(), class-wc-meta-box-product-data.php 498-633). Every
 * variation of the product is posted; its data store fires the stock
 * actions for a stock that changed.
 */
function wc_variations_panel( $parent_id, array $enabled, array $stock = array() ) {
	$GLOBALS['SAVE_FIRES'] = true;
	wc_get_product( $parent_id )->save();
	foreach ( wc_get_product( $parent_id )->get_children() as $i => $id ) {
		$v = wc_get_product( $id );
		$v->set_status( in_array( (int) $id, $enabled, true ) ? 'publish' : 'private' );
		if ( array_key_exists( (int) $id, $stock ) ) {
			$v->set_stock_quantity( $stock[ (int) $id ] );
		}
		do_action( 'woocommerce_admin_process_variation_object', $v, $i );
		$v->save();
		do_action( 'woocommerce_save_product_variation', $id, $i );
	}
	$GLOBALS['SAVE_FIRES'] = false;
}
/**
 * The variations panel's bulk action "Toggle "Enabled""
 * (WC_AJAX::variation_bulk_action_toggle_enabled(), class-wc-ajax.php
 * 3178-3184): each variation chosen, private to publish or publish to private,
 * and saved.
 */
function wc_toggle_enabled( array $ids ) {
	foreach ( $ids as $id ) {
		$v = wc_get_product( $id );
		$v->set_status( 'private' === $v->get_status( 'edit' ) ? 'publish' : 'private' );
		$v->save();
	}
}
/**
 * WooCommerce's REST API: PUT /wc/v3/products/<parent>/variations/<id>
 * (WC_REST_Product_Variations_Controller::prepare_object_for_database(),
 * class-wc-rest-product-variations-controller.php 203-214: 'status' set with
 * set_status(); its stock with set_stock_quantity()), saved by
 * WC_REST_CRUD_Controller::save_object() (class-wc-rest-crud-controller.php
 * 161-170, $object->save()); v2's 'visible' sets publish or private
 * (class-wc-rest-product-variations-v2-controller.php 402-403).
 */
function wc_rest_variation( $id, array $request ) {
	$GLOBALS['SAVE_FIRES'] = true;
	$v = wc_get_product( $id );
	if ( isset( $request['status'] ) ) {
		$v->set_status( $request['status'] );
	}
	if ( isset( $request['visible'] ) ) {
		$v->set_status( false === $request['visible'] ? 'private' : 'publish' );
	}
	if ( isset( $request['stock_quantity'] ) ) {
		$v->set_stock_quantity( (int) $request['stock_quantity'] );
	}
	$v->save();
	$GLOBALS['SAVE_FIRES'] = false;
}

/**
 * The kit: 7500 on listing 750000007500, its variations each keeping their
 * own stock; B (7502) disabled unless said. Each carries what eBay calls it,
 * as every import since 3.32.0 leaves a variation it found (fix round 1).
 */
function kit_shop( $a = 3, $b = 5, $b_status = 'private' ) {
	fresh_shop();
	variable( 7500, '750000007500', array( '_sku' => 'OS' ) );
	variation( 7501, 7500, 'OS-A', $a, array( 'size' => 'S' ), array( '_ebay_variation_sku' => 'OS-A', '_name' => 'Kit - S', TCGiant_Sync_Variations::SPECIFICS_META => array( 'size' => 'S' ) ) );
	variation( 7502, 7500, 'OS-B', $b, array( 'size' => 'M' ), array( '_ebay_variation_sku' => 'OS-B', '_name' => 'Kit - M', TCGiant_Sync_Variations::SPECIFICS_META => array( 'size' => 'M' ) ) );
	$GLOBALS['POSTS'][7502]['status'] = $b_status;
}
/** eBay's kit listing: what A and B (and C, when given) have available, and have sold; null is not on it. */
function kit( $a, $b, array $sold = array(), $c = null ) {
	$sold = $sold + array( 'a' => 0, 'b' => 0, 'c' => 0 );
	$v    = array();
	foreach ( array( 'a' => array( $a, 'S' ), 'b' => array( $b, 'M' ), 'c' => array( $c, 'L' ) ) as $k => $row ) {
		if ( null !== $row[0] ) {
			$v[] = ev( 'OS-' . strtoupper( $k ), $row[0] + $sold[ $k ], $sold[ $k ], array( 'size' => $row[1] ) );
		}
	}
	return variation_listing( '750000007500', $v ) + array( 'Title' => 'Kit', 'SKU' => 'OS' );
}
/** B as a 3.44.0 push left it: sent 0 on eBay while disabled, its record at $sold. */
function b_sent_zero( $sold ) {
	seen( 7502, '750000007500', $sold, 'OS-B' );
	$GLOBALS['META'][7502][ TCGiant_Sync_Mapper::OFF_META ] = array( 'item' => '750000007500', 'sku' => 'OS-B', 'at' => time() - 3600 );
}
function enabled_lines() { return array_values( array_map( function ( $l ) { return $l[1]; }, array_filter( $GLOBALS['LOG'], function ( $l ) { return 1 === preg_match( '/^Variation #\d+ (\([^)]*\) )?of WC #\d+ enabled again: /', $l[1] ); } ) ) ); }
function disabled_lines() { return array_values( array_map( function ( $l ) { return $l[1]; }, array_filter( $GLOBALS['LOG'], function ( $l ) { return 1 === preg_match( '/^Variation #\d+ (\([^)]*\) )?of WC #\d+ disabled: /', $l[1] ); } ) ) ); }
function oos_on() { $GLOBALS['OPTIONS'][ TCGiant_Sync_Importer::OOS_PREF_OPTION ] = array( 'pref' => 'true', 'ok' => true, 'at' => time() ); }
function push_ids() { return array_map( function ( $a ) { return $a['args'][0]['product_id'] ?? null; }, pushes() ); }
function off_mark( $id ) { return meta( $id, TCGiant_Sync_Mapper::OFF_META )['item'] ?? null; }
function zeroed_mark( $id ) { return meta( $id, TCGiant_Sync_Mapper::ZEROED_META ); }
/** The notice on a product's edit screen, as drawn. */
function edit_screen_notice( $product_id, $screen = 'product' ) {
	$GLOBALS['SCREEN_ID'] = $screen;
	$GLOBALS['post']      = (object) array( 'ID' => $product_id );
	ob_start();
	$GLOBALS['ADMIN_PAGE']->zeroed_variation_notice();
	return (string) ob_get_clean();
}
$ADMIN_PAGE = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();

$POOL_LINE = 'Variation #7103 (PO-L) of WC #7100 enabled again: the shared stock\'s push is queued for eBay listing 710000007100, to send it the shared stock, 4.';
$OWN_LINE  = 'Variation #7502 (OS-B) of WC #7500 enabled again: its stock, 5, is queued for eBay listing 750000007500.';

if ( $STAGING_CHILD ) {
	goto staging;
}

echo "\nTHE HOOKS\n" . str_repeat( '=', 132 ) . "\n";

check( 'WooCommerce\'s actions round every save of a product object: noted as the save begins, acted on as it ends', array( has_filter( 'woocommerce_before_product_object_save', array( 'TCGiant_Sync_Inventory', 'note_status_change' ) ), has_filter( 'woocommerce_after_product_object_save', array( $INV, 'on_status_saved' ) ) ), array( 10, 10 ) );
check( 'the mapper\'s marks, as the inventory names them where the mapper is not loaded', array( TCGiant_Sync_Inventory::VARIATION_MARKS, TCGiant_Sync_Inventory::variation_mark( 'OFF_META' ), TCGiant_Sync_Inventory::variation_mark( 'SHOP_OFF_META' ) ), array( array( 'RETIRED_META' => TCGiant_Sync_Mapper::RETIRED_META, 'ZEROED_META' => TCGiant_Sync_Mapper::ZEROED_META, 'OFF_META' => TCGiant_Sync_Mapper::OFF_META, 'SHOP_OFF_META' => TCGiant_Sync_Mapper::SHOP_OFF_META ), '_ebay_variation_off', '_ebay_variation_shop_off' ) );

echo "\nENABLED AGAIN, SHARING THE PARENT'S STOCK: THE SHARED STOCK'S PUSH, ON EACH OF WOOCOMMERCE'S WAYS\n" . str_repeat( '=', 132 ) . "\n";

/** The polo: 4 shared, L disabled, eBay keeping L at 0 (it had sold one, so eBay kept it at 0). */
function polo_at_zero( $pool = 4 ) {
	polo_shop( $pool );
	seen( 7101, '710000007100', 0, 'PO-S' );
	seen( 7102, '710000007100', 0, 'PO-M' );
	seen( 7103, '710000007100', 1, 'PO-L' );
	$GLOBALS['META'][7103]['_name'] = 'Polo - L';
}
$tcg_ways = array(
	'the variation\'s own save (CRUD)'        => function () { wc_set_status( 7103, 'publish' ); },
	'the variations panel\'s "Enabled"'       => function () { wc_variations_panel( 7100, array( 7101, 7102, 7103 ) ); },
	'its bulk action "Toggle Enabled"'        => function () { wc_toggle_enabled( array( 7103 ) ); },
	'the REST API, v3 status publish'         => function () { wc_rest_variation( 7103, array( 'status' => 'publish' ) ); },
	'the REST API, v2 visible true'           => function () { wc_rest_variation( 7103, array( 'visible' => true ) ); },
);
foreach ( $tcg_ways as $tcg_way => $tcg_do ) {
	polo_at_zero();
	$tcg_do();
	$tcg_queued = array( push_args(), enabled_lines(), status_of( 7103 ) );
	ebay_has( '710000007100', polo( 4, 4, 0, array( 'l' => 1 ) ), polo( 4, 4, 4, array( 'l' => 1 ) ) );
	run_queue();
	check( 'L enabled by ' . $tcg_way . ': the parent\'s push queued, one line; run, eBay sends L the shared 4', array( $tcg_queued, calls(), revises(), count_logged( 'Real-time stock sync' ) ), array( array( array( array( 7100, 'edit', null ) ), array( $POOL_LINE ), 'publish' ), array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), array( array( 'PO-L' => 4 ) ), 0 ) );
}
// The bulk toggle flips each chosen: S enabled to disabled, L disabled to
// enabled. S's disable queues the shared stock's push (fix round 1), which
// L's enable then finds waiting.
polo_at_zero();
wc_toggle_enabled( array( 7101, 7103 ) );
check( 'the bulk toggle on S (enabled) and L (disabled): a line for each; one push, the parent\'s', array( disabled_lines(), enabled_lines(), push_args(), status_of( 7101 ), status_of( 7103 ) ), array( array( 'Variation #7101 (PO-S) of WC #7100 disabled: the shared stock\'s push is queued for eBay listing 710000007100, to set it to 0 there - unless it is the listing\'s only variation with stock.' ), array( 'Variation #7103 (PO-L) of WC #7100 enabled again: a stock push of WC #7100 is already waiting for eBay listing 710000007100, and sends it.' ), array( array( 7100, 'edit', null ) ), 'private', 'publish' ) );
ebay_has( '710000007100', polo( 4, 4, 0, array( 'l' => 1 ) ), polo( 0, 4, 4, array( 'l' => 1 ) ) );
run_queue();
check( '  run: L sent the shared 4, S, disabled now, sent 0 by 3.43.0\'s rule - one push for both', array( revises(), logged( 'disabled variation(s) #7101 (PO-S) of eBay listing 710000007100 sent 0' ) ), array( array( array( 'PO-L' => 4, 'PO-S' => 0 ) ), true ) );
// The panel saving a new stock with it: the stock change's push is the one.
polo_at_zero();
wc_variations_panel( 7100, array( 7101, 7102, 7103 ) );
$tcg_first = push_args();
polo_at_zero();
$GLOBALS['SAVE_FIRES'] = true;
$tcg_v = wc_get_product( 7100 );
$tcg_v->set_stock_quantity( 3 );
$tcg_v->save();
$GLOBALS['SAVE_FIRES'] = false;
wc_rest_variation( 7103, array( 'status' => 'publish' ) );
check( 'the shared stock changed and L enabled in one go: one push - the stock change\'s - and the line says it is waiting', array( $tcg_first, push_args(), enabled_lines() ), array( array( array( 7100, 'edit', null ) ), array( array( 7100, 'edit', null ) ), array( 'Variation #7103 (PO-L) of WC #7100 enabled again: a stock push of WC #7100 is already waiting for eBay listing 710000007100, and sends it.' ) ) );
// Neither an enable nor a disable: a save that leaves the status as it was
// (enabled, or disabled), a variation made new, one sent to the trash.
polo_shop( 4, 'publish' );
$POSTS[7102]['status'] = 'private';
wc_set_status( 7103, 'publish' );
wc_set_status( 7102, 'private' );
$tcg_new = new WC_Product_Variation();
$tcg_new->set_parent_id( 7100 );
$tcg_new->set_status( 'publish' );
$tcg_new->save();
wc_set_status( 7101, 'trash' );
check( 'neither: a save of one enabled, of one disabled, a variation made new, one sent to the trash - nothing queued, nothing said', array( push_args(), enabled_lines(), disabled_lines() ), array( array(), array(), array() ) );
// The import publishing a variation it retired: eBay's own state, sent nothing back.
polo_shop( 5, 'publish' );
import_listing( polo( 5, 5, null ) );
$tcg_retired = status_of( 7103 );
import_listing( polo( 5, 5, 5 ) );
check( 'the import publishing a variation it had retired: nothing queued, nothing said', array( $tcg_retired, status_of( 7103 ), push_args(), enabled_lines() ), array( 'private', 'publish', array(), array() ) );
// Not on eBay: nothing said, as a stock change of it says nothing.
fresh_shop();
variable( 7600, '', array( '_manage_stock' => 'yes', '_stock' => 3 ) );
variation( 7601, 7600, 'NE-A', null, array( 'size' => 'S' ) );
$POSTS[7601]['status'] = 'private';
wc_set_status( 7601, 'publish' );
check( 'a variation of a product not on eBay enabled: nothing queued, nothing said', array( push_args(), $LOG ), array( array(), array() ) );

echo "\nENABLED AGAIN, KEEPING ITS OWN STOCK: ITS OWN PUSH, ON EACH WAY\n" . str_repeat( '=', 132 ) . "\n";

$tcg_ways = array(
	'the variation\'s own save (CRUD)'        => function () { wc_set_status( 7502, 'publish' ); },
	'the variations panel\'s "Enabled"'       => function () { wc_variations_panel( 7500, array( 7501, 7502 ) ); },
	'its bulk action "Toggle Enabled"'        => function () { wc_toggle_enabled( array( 7502 ) ); },
	'the REST API, v3 status publish'         => function () { wc_rest_variation( 7502, array( 'status' => 'publish' ) ); },
	'the REST API, v2 visible true'           => function () { wc_rest_variation( 7502, array( 'visible' => true ) ); },
);
foreach ( $tcg_ways as $tcg_way => $tcg_do ) {
	kit_shop( 3, 5 );
	b_sent_zero( 1 );
	$tcg_do();
	$tcg_queued = array( push_args(), enabled_lines() );
	run_queue();
	check( 'B enabled by ' . $tcg_way . ': its own push queued, one line; run, eBay sends B its 5, no read first (as a stock change of it); the mark of its 0 goes', array( $tcg_queued, calls(), revises(), off_mark( 7502 ) ), array( array( array( array( 7502, 'edit', null ) ), array( $OWN_LINE ) ), array( 'ReviseInventoryStatus' ), array( array( 'OS-B' => 5 ) ), null ) );
}

echo "\nEVERY RULE OF A STOCK CHANGE\n" . str_repeat( '=', 132 ) . "\n";

// Pushes off.
polo_at_zero();
$OPTIONS['tcgiant_sync_ebay_settings']['push_stock_to_ebay'] = '0';
wc_set_status( 7103, 'publish' );
kit_shop();
$OPTIONS['tcgiant_sync_ebay_settings']['push_stock_to_ebay'] = '0';
wc_set_status( 7502, 'publish' );
check( 'pushes off: nothing queued, the line says why (and not the stock change\'s once-a-day line too)', array( push_args(), enabled_lines(), count_logged( 'Said once a day' ) ), array( array(), array( 'Variation #7502 (OS-B) of WC #7500 enabled again: nothing queued for eBay listing 750000007500 - "Send website stock changes to eBay?" is No.' ), 0 ) );
// Not connected.
polo_at_zero();
$OPTIONS['tcgiant_sync_ebay_settings'] = array();
wc_set_status( 7103, 'publish' );
check( 'not connected to eBay: nothing queued, the line says why', array( push_args(), enabled_lines() ), array( array(), array( 'Variation #7103 (PO-L) of WC #7100 enabled again: nothing queued for eBay listing 710000007100 - this site is not connected to eBay.' ) ) );
// The day's share of eBay calls used up: the push waits for tomorrow, as an edit's does.
polo_at_zero();
fleet_used( 0.85 );
wc_set_status( 7103, 'publish' );
run_queue();
$tcg_p        = pushes();
$tcg_tomorrow = gmmktime( 0, 0, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ) + 1, (int) gmdate( 'Y' ) );
check( 'the fleet past its 80% ceiling: queued; run, nothing sent today, it waits for the next UTC day', array( enabled_lines(), $WIRE, count( $tcg_p ), ( $tcg_p[0]['when'] ?? 0 ) >= $tcg_tomorrow ), array( array( $POOL_LINE ), array(), 1, true ) );
kit_shop();
b_sent_zero( 1 );
fleet_used( 0.85 );
wc_set_status( 7502, 'publish' );
run_queue();
$tcg_p = pushes();
check( '  B\'s own push the same', array( $WIRE, count( $tcg_p ), ( $tcg_p[0]['when'] ?? 0 ) >= $tcg_tomorrow, off_mark( 7502 ) ), array( array(), 1, true, '750000007500' ) );
fleet_used( 0 );
// The 60-second mark: a push already waiting.
polo_at_zero();
set_transient( 'tcgiant_stock_pending_7100', 1, 60 );
wc_set_status( 7103, 'publish' );
kit_shop();
set_transient( 'tcgiant_stock_pending_7502', 1, 60 );
wc_set_status( 7502, 'publish' );
check( 'a push already waiting (the 60-second mark): nothing more queued, the line says it sends it', array( push_args(), enabled_lines() ), array( array(), array( 'Variation #7502 (OS-B) of WC #7500 enabled again: a stock push of WC #7502 is already waiting for eBay listing 750000007500, and sends it.' ) ) );
// At 0: the shared stock's push reads first, as ever; a variation's own 0 is
// sent as a stock change of it to 0 sends it, with no read first.
polo_at_zero( 0 );
wc_set_status( 7103, 'publish' );
ebay_has( '710000007100', polo( 0, 0, 0, array( 'l' => 1 ) ) );
run_queue();
check( 'the shared stock at 0: queued; run, eBay read first and already showing 0 for each - nothing sent', array( enabled_lines(), calls(), revises() ), array( array( 'Variation #7103 (PO-L) of WC #7100 enabled again: the shared stock\'s push is queued for eBay listing 710000007100, to send it the shared stock, 0.' ), array( 'GetItem' ), array() ) );
kit_shop( 3, 0 );
b_sent_zero( 1 );
wc_set_status( 7502, 'publish' );
run_queue();
check( 'B\'s own stock at 0: queued; run, 0 sent with no read first, as any variation\'s stock change to 0; the mark goes (eBay shows its own 0)', array( enabled_lines(), calls(), revises(), off_mark( 7502 ) ), array( array( 'Variation #7502 (OS-B) of WC #7500 enabled again: its stock, 0, is queued for eBay listing 750000007500.' ), array( 'ReviseInventoryStatus' ), array( array( 'OS-B' => 0 ) ), null ) );
// A variation with no SKU: the stock change's own refusal, in the line.
kit_shop();
unset( $META[7502]['_ebay_variation_sku'] );
$META[7502]['_sku'] = '';
wc_set_status( 7502, 'publish' );
check( 'a variation with no SKU: nothing queued, the stock change\'s reason in one warning - not the stock change\'s own line too', array( push_args(), count( enabled_lines() ), logged( 'Variation #7502 of WC #7500 enabled again: nothing queued for eBay listing 750000007500 - Variation has no SKU', 'warning' ), count_logged( 'Stock change for WC #7502 not sent' ) ), array( array(), 1, true, 0 ) );
// Its stock not managed: nothing to send.
kit_shop();
$META[7502]['_manage_stock'] = 'no';
wc_set_status( 7502, 'publish' );
check( 'its stock not managed: nothing queued, said', array( push_args(), enabled_lines() ), array( array(), array( 'Variation #7502 (OS-B) of WC #7500 enabled again: nothing queued for eBay listing 750000007500 - WooCommerce does not manage the stock it takes (WC #7502).' ) ) );

echo "\nSET TO 0 BEFORE IT HAD EVER SOLD (3.43.0'S MARK): EBAY REMOVED IT, OR MAY HAVE (FIX ROUND 1, REVIEW 1'S M1)\n" . str_repeat( '=', 132 ) . "\n";

$ZEROED_MAY  = 'Variation #7103 (PO-L) of WC #7100 enabled again: nothing queued - eBay may have removed it from listing 710000007100: it was set to 0 before it had ever sold, and with Out-of-Stock control off eBay removes such a variation. The next import of the listing says which: still listed, its stock is sent then; gone, Update eBay Listing on the product adds it back.';
$ZEROED_WARN = 'Variation #7103 (PO-L) of WC #7100 enabled again: nothing queued - eBay removed it from listing 710000007100 when it was set to 0 before it had ever sold (Out-of-Stock control off), so no stock push can reach it. Update eBay Listing on the product adds it back; the other variations\' stock goes to eBay as before.';
$ZEROED_NOTE = 'eBay no longer lists the variation Polo - L; press Update eBay Listing to add it back.';
$MAY_NOTE    = 'eBay may no longer list the variation Polo - L: it was set to 0 there before it had ever sold. The next import of the listing will tell; if eBay removed it, press Update eBay Listing to add it back.';
$NUDGE_LINE  = 'Variation #7103 of WC #7100 is enabled, and eBay listing 710000007100 still shows it at 0 - only the 0 sent while it was disabled reached eBay: its stock push is queued.';
// Review 1's probe A: L never sold, sent 0 by a stock edit with Out-of-Stock
// control on, as last heard - eBay keeps it at 0 - and enabled before the
// next import: its push is queued as for any enable.
polo_shop( 4 );
$META[7103]['_name'] = 'Polo - L';
oos_on();
ebay_has( '710000007100', polo( 4, 4, 4 ), polo( 3, 3, 0 ) );
hand_edit( 7100, 3 );
run_queue();
$tcg_marked = null !== zeroed_mark( 7103 );
next_request();
$WIRE = array();
wc_set_status( 7103, 'publish' );
check( 'A (probe): Out-of-Stock control known on, L marked by its 0, enabled before the next import: the shared stock\'s push queued as for any enable; no notice', array( $tcg_marked, push_args(), enabled_lines(), edit_screen_notice( 7100 ) ), array( true, array( array( 7100, 'edit', null ) ), array( 'Variation #7103 (PO-L) of WC #7100 enabled again: the shared stock\'s push is queued for eBay listing 710000007100, to send it the shared stock, 3.' ), '' ) );
ebay_has( '710000007100', polo( 3, 3, 0 ), polo( 3, 3, 3 ) );
run_queue();
check( '  run: eBay sends L the shared 3 - before fix round 1 nothing was queued, and L stayed at 0 on eBay', revises(), array( array( 'PO-L' => 3 ) ) );
unset( $OPTIONS[ TCGiant_Sync_Importer::OOS_PREF_OPTION ] );
// The same with Out-of-Stock control not known on: nothing queued, "may";
// the import finds L still listed at 0 and queues its push then.
polo_shop( 4 );
$META[7103]['_name'] = 'Polo - L';
$META[7103][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '710000007100', 'at' => time() - 600 );
check( 'disabled with the mark: no notice (it is meant to be off eBay)', array( TCGiant_Sync_Inventory::zeroed_variation_lines( 7100 ), edit_screen_notice( 7100 ) ), array( array(), '' ) );
wc_variations_panel( 7100, array( 7101, 7102, 7103 ) );
check( 'Out-of-Stock control not known on, L enabled with the mark: nothing queued; one warning - eBay may have removed it', array( push_args(), enabled_lines(), logged( $ZEROED_MAY, 'warning' ) ), array( array(), array( $ZEROED_MAY ), true ) );
check( '  the edit screen says eBay may no longer list it, and what adds it back', array( TCGiant_Sync_Inventory::zeroed_variation_lines( 7100 ), edit_screen_notice( 7100 ) ), array( array( $MAY_NOTE ), '<div class="notice notice-warning"><p><strong>TCGiant Sync:</strong> ' . $MAY_NOTE . '</p></div>' ) );
check( '  not on another screen, nor another product\'s', array( edit_screen_notice( 7100, 'edit-product' ), edit_screen_notice( 7101 ) ), array( '', '' ) );
next_request();
$WIRE = array();
import_listing( polo( 4, 4, 0 ) );
check( '  the import finds L still listed at 0 (eBay kept it): the mark goes, the notice with it, and the shared stock\'s push is queued, said', array( zeroed_mark( 7103 ), edit_screen_notice( 7100 ), push_args(), logged( $NUDGE_LINE ) ), array( null, '', array( array( 7100, 'edit', null ) ), true ) );
ebay_has( '710000007100', polo( 4, 4, 0 ), polo( 4, 4, 4 ) );
run_queue();
check( '  run: eBay sends L the shared 4', revises(), array( array( 'PO-L' => 4 ) ) );
// The import finds L gone: then, and only then, "eBay removed it".
polo_shop( 4 );
$META[7103]['_name'] = 'Polo - L';
$META[7103][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '710000007100', 'at' => time() - 600 );
wc_set_status( 7103, 'publish' );
next_request();
import_listing( polo( 4, 4, null ) );
import_listing( polo( 4, 4, null ) );
check( '  the import finds L gone: noted when, L left enabled, said once that eBay removed it; the notice says so', array( ! empty( zeroed_mark( 7103 )['gone'] ), status_of( 7103 ), count_logged( 'is not on eBay listing 710000007100 any more: eBay removed it' ), edit_screen_notice( 7100 ) ), array( true, 'publish', 1, '<div class="notice notice-warning"><p><strong>TCGiant Sync:</strong> ' . $ZEROED_NOTE . '</p></div>' ) );
wc_set_status( 7103, 'private' );
$LOG = array();
wc_set_status( 7103, 'publish' );
next_request();
import_listing( polo( 4, 4, null ) );
check( '  disabled and enabled again after that: nothing queued for L, one warning - eBay removed it - and the import does not say it again', array( push_args(), enabled_lines(), count_logged( 'is not on eBay listing 710000007100 any more' ) ), array( array( array( 7100, 'edit', null ) ), array( $ZEROED_WARN ), 0 ) );
$QUEUE = array();
$TRANSIENTS = array();
$WIRE = array();
ebay_has( '710000007100', polo( 4, 4, null ), polo( 3, 3, null ) );
hand_edit( 7100, 3 );
run_queue();
check( '  the other variations\' push unaffected: a stock edit sends S and M the shared 3', revises(), array( array( 'PO-S' => 3, 'PO-M' => 3 ) ) );
import_listing( polo( 3, 3, 3 ) );
check( '  once eBay lists it again (an Update): the mark goes, and with it the notice; eBay shows it stock, so nothing is queued', array( zeroed_mark( 7103 ), edit_screen_notice( 7100 ), push_args() ), array( null, '', array() ) );
// Found gone while still disabled (the import says nothing for a disabled
// one), then enabled: the enable says eBay removed it, and so the next
// import does not say it again.
polo_shop( 4 );
$META[7103]['_name'] = 'Polo - L';
$META[7103][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '710000007100', 'at' => time() - 600 );
import_listing( polo( 4, 4, null ) );
$tcg_gone = array( ! empty( zeroed_mark( 7103 )['gone'] ), empty( zeroed_mark( 7103 )['said'] ), status_of( 7103 ) );
wc_set_status( 7103, 'publish' );
next_request();
import_listing( polo( 4, 4, null ) );
check( '  found gone while disabled (nothing said then), enabled: the enable says eBay removed it, and the next import does not say it again', array( $tcg_gone, enabled_lines(), count_logged( 'is not on eBay listing 710000007100 any more' ) ), array( array( true, true, 'private' ), array( $ZEROED_WARN ), 0 ) );
// Enabled, the mark taken off with eBay at 0, but the shared stock at 0 here
// too: nothing to send.
polo_shop( 0, 'publish' );
$META[7103][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '710000007100', 'at' => time() - 600 );
import_listing( polo( 0, 0, 0 ) );
check( '  the mark taken off with eBay at 0 and the shared stock at 0 here: nothing queued', array( zeroed_mark( 7103 ), push_args() ), array( null, array() ) );
// Enabled with the mark, eBay listing it with stock (an Update since): the
// mark goes, and nothing is queued - eBay has its figure.
polo_shop( 4, 'publish' );
$META[7103][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '710000007100', 'at' => time() - 600 );
import_listing( polo( 4, 4, 4 ) );
check( '  enabled with the mark, the import finds L listed at 4: the mark goes, nothing queued, nothing said', array( zeroed_mark( 7103 ), push_args(), logged( $NUDGE_LINE ) ), array( null, array(), false ) );
// Pushes off: the import finding it still listed at 0 queues nothing either.
polo_shop( 4, 'publish' );
$OPTIONS['tcgiant_sync_ebay_settings']['push_stock_to_ebay'] = '0';
$META[7103][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '710000007100', 'at' => time() - 600 );
import_listing( polo( 4, 4, 0 ) );
check( '  pushes off ("Send website stock changes to eBay?" No), the import finds L still listed at 0: nothing queued, nothing said', array( zeroed_mark( 7103 ), push_args(), logged( $NUDGE_LINE ) ), array( null, array(), false ) );
$OPTIONS['tcgiant_sync_ebay_settings']['push_stock_to_ebay'] = '1';
// Still disabled, the mark taken off by the import with eBay at 0: nothing
// queued - eBay showing 0 is what a disabled variation should show.
polo_shop( 4 );
$META[7103][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '710000007100', 'at' => time() - 600 );
import_listing( polo( 4, 4, 0 ) );
check( '  L still disabled, the import finds it listed at 0: nothing queued, nothing said', array( status_of( 7103 ), push_args(), logged( $NUDGE_LINE ) ), array( 'private', array(), false ) );
// Found gone, then Out-of-Stock control known on (turned on since): eBay
// removed it all the same - nothing queued, "eBay removed it", the notice.
polo_shop( 4 );
$META[7103]['_name'] = 'Polo - L';
$META[7103][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '710000007100', 'at' => time() - 600, 'gone' => time() - 300, 'said' => time() - 300 );
oos_on();
wc_set_status( 7103, 'publish' );
check( '  found gone, the control known on since: nothing queued, eBay removed it; the notice says so', array( push_args(), enabled_lines(), edit_screen_notice( 7100 ) ), array( array(), array( $ZEROED_WARN ), '<div class="notice notice-warning"><p><strong>TCGiant Sync:</strong> ' . $ZEROED_NOTE . '</p></div>' ) );
// Known on, L enabled with the mark, the import before its push runs: the
// push already waiting is the one - no second, and nothing said.
polo_shop( 4 );
$META[7103][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '710000007100', 'at' => time() - 600 );
oos_on();
wc_set_status( 7103, 'publish' );
$tcg_first = push_args();
import_listing( polo( 4, 4, 0 ) );
check( '  known on, enabled, the import before its push runs: one push, the enable\'s; nothing more said', array( $tcg_first, push_args(), logged( $NUDGE_LINE ) ), array( array( array( 7100, 'edit', null ) ), array( array( 7100, 'edit', null ) ), false ) );
unset( $OPTIONS[ TCGiant_Sync_Importer::OOS_PREF_OPTION ] );
// The mark for another listing (the product relisted since): enabled as any.
polo_at_zero();
$META[7103][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '700000000001', 'at' => time() - 600 );
wc_set_status( 7103, 'publish' );
check( 'the mark of an earlier listing: the push queued as for any; no notice', array( push_args(), enabled_lines(), edit_screen_notice( 7100 ) ), array( array( array( 7100, 'edit', null ) ), array( $POOL_LINE ), '' ) );
// Review 1's probe B: B keeping its own stock, never sold, sent 0 with
// Out-of-Stock control on, enabled before the next import.
kit_shop( 3, 5 );
oos_on();
ebay_has( '750000007500', kit( 3, 5 ) );
hand_edit( 7502, 4 );
run_queue();
$tcg_marks = array( off_mark( 7502 ), zeroed_mark( 7502 )['item'] ?? null );
next_request();
$WIRE = array();
wc_set_status( 7502, 'publish' );
run_queue();
check( 'B (probe): Out-of-Stock control known on, both marks, enabled before the next import: its push queued and run - eBay sends B its 4; the "sent 0" mark goes', array( $tcg_marks, enabled_lines(), calls(), revises(), off_mark( 7502 ) ), array( array( '750000007500', '750000007500' ), array( 'Variation #7502 (OS-B) of WC #7500 enabled again: its stock, 4, is queued for eBay listing 750000007500.' ), array( 'ReviseInventoryStatus' ), array( array( 'OS-B' => 4 ) ), null ) );
unset( $OPTIONS[ TCGiant_Sync_Importer::OOS_PREF_OPTION ] );
// Not known on: nothing at the enable; the import finds B still listed at
// 0 and queues its push, once a mark.
kit_shop( 3, 4 );
b_sent_zero( 0 );
$META[7502][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '750000007500', 'at' => time() - 600 );
wc_set_status( 7502, 'publish' );
$tcg_at_enable = array( push_args(), count( enabled_lines() ), logged( 'Variation #7502 (OS-B) of WC #7500 enabled again: nothing queued - eBay may have removed it', 'warning' ), TCGiant_Sync_Inventory::zeroed_variation_lines( 7500 ) );
next_request();
import_listing( kit( 3, 0 ) );
import_listing( kit( 3, 0 ) );
check( 'B, the control not known on, enabled with both marks: nothing queued, "may"; the imports find it at 0 - its 4 kept, its push queued once, said once', array( $tcg_at_enable, status_of( 7502 ), stock( 7502 ), zeroed_mark( 7502 ), push_args(), count_logged( 'Variation #7502 of WC #7500 is enabled, and eBay listing 750000007500 still shows it at 0' ) ), array( array( array(), 1, true, array( 'eBay may no longer list the variation Kit - M: it was set to 0 there before it had ever sold. The next import of the listing will tell; if eBay removed it, press Update eBay Listing to add it back.' ) ), 'publish', 4, null, array( array( 7502, 'edit', null ) ), 1 ) );
$WIRE = array();
run_queue();
$QUEUE = array();
$TRANSIENTS = array();
import_listing( kit( 3, 4 ) );
check( '  run: eBay sends B its 4, the mark goes; the next import queues nothing more', array( revises(), off_mark( 7502 ), push_args() ), array( array( array( 'OS-B' => 4 ) ), null, array() ) );
// Its push given up (eBay refused it for good): the next imports do not
// queue another - once a mark.
kit_shop( 3, 4, 'publish' );
b_sent_zero( 1 );
import_listing( kit( 3, 0, array( 'b' => 1 ) ) );
$tcg_first = array( push_args(), ! empty( meta( 7502, TCGiant_Sync_Mapper::OFF_META )['nudged'] ) );
$QUEUE = array();
$TRANSIENTS = array();
unset( $META[7502]['_ebay_push_pending'] );
import_listing( kit( 3, 0, array( 'b' => 1 ) ) );
check( '  "sent 0" mark kept, its push given up: queued once, noted; the next import queues none', array( $tcg_first, push_args(), off_mark( 7502 ) ), array( array( array( array( 7502, 'edit', null ) ), true ), array(), '750000007500' ) );
// A website sale of B before that push: it reads eBay at 0 and sends
// nothing (a sale only lowers) - the mark stays, so the import still knows.
kit_shop( 3, 5, 'publish' );
b_sent_zero( 1 );
ebay_has( '750000007500', kit( 3, 0, array( 'b' => 1 ) ) );
website_order( 7552, array( 7502 => 1 ) );
run_queue();
check( 'B enabled, eBay still at its 0, a website sale: its push reads and sends nothing, and the mark stays (eBay\'s 0 is still not its 4)', array( calls(), stock( 7502 ), off_mark( 7502 ) ), array( array( 'GetItem' ), 4, '750000007500' ) );

echo "\nDISABLED (FIX ROUND 1, THE OWNER'S DECISION): ITS 0 QUEUED AT ONCE, ON EACH OF WOOCOMMERCE'S WAYS\n" . str_repeat( '=', 132 ) . "\n";

$POOL_OFF = 'Variation #7103 (PO-L) of WC #7100 disabled: the shared stock\'s push is queued for eBay listing 710000007100, to set it to 0 there - unless it is the listing\'s only variation with stock.';
$OWN_OFF  = 'Variation #7502 (OS-B) of WC #7500 disabled: its push is queued for eBay listing 750000007500, to set it to 0 there - unless it is the listing\'s only variation with stock.';
$tcg_ways = array(
	'the variation\'s own save (CRUD)'        => function () { wc_set_status( 7103, 'private' ); },
	'the variations panel\'s "Enabled"'       => function () { wc_variations_panel( 7100, array( 7101, 7102 ) ); },
	'its bulk action "Toggle Enabled"'        => function () { wc_toggle_enabled( array( 7103 ) ); },
	'the REST API, v3 status private'         => function () { wc_rest_variation( 7103, array( 'status' => 'private' ) ); },
	'the REST API, v2 visible false'          => function () { wc_rest_variation( 7103, array( 'visible' => false ) ); },
);
foreach ( $tcg_ways as $tcg_way => $tcg_do ) {
	polo_shop( 4, 'publish' );
	$tcg_do();
	$tcg_queued = array( push_args(), disabled_lines(), status_of( 7103 ) );
	ebay_has( '710000007100', polo( 4, 4, 4 ), polo( 4, 4, 0 ) );
	run_queue();
	check( 'L (shared stock) disabled by ' . $tcg_way . ': the parent\'s push queued, one line; run, eBay sets L to 0', array( $tcg_queued, calls(), revises() ), array( array( array( array( 7100, 'edit', null ) ), array( $POOL_OFF ), 'private' ), array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), array( array( 'PO-L' => 0 ) ) ) );
}
$tcg_ways = array(
	'the variation\'s own save (CRUD)'        => function () { wc_set_status( 7502, 'private' ); },
	'the variations panel\'s "Enabled"'       => function () { wc_variations_panel( 7500, array( 7501 ) ); },
	'its bulk action "Toggle Enabled"'        => function () { wc_toggle_enabled( array( 7502 ) ); },
	'the REST API, v3 status private'         => function () { wc_rest_variation( 7502, array( 'status' => 'private' ) ); },
	'the REST API, v2 visible false'          => function () { wc_rest_variation( 7502, array( 'visible' => false ) ); },
);
foreach ( $tcg_ways as $tcg_way => $tcg_do ) {
	kit_shop( 3, 5, 'publish' );
	$tcg_do();
	$tcg_queued = array( push_args(), disabled_lines() );
	ebay_has( '750000007500', kit( 3, 5, array( 'b' => 1 ) ) );
	run_queue();
	check( 'B (its own stock) disabled by ' . $tcg_way . ': its push queued, one line; run, eBay read, its record first, then 0; marked', array( $tcg_queued, calls(), revises(), rec( 7502 ), off_mark( 7502 ) ), array( array( array( array( 7502, 'edit', null ) ), array( $OWN_OFF ) ), array( 'GetItem', 'ReviseInventoryStatus' ), array( array( 'OS-B' => 0 ) ), array( 'OS-B', 1 ), '750000007500' ) );
}
// Enabled with the removal mark (not yet found gone, the control not known
// on), disabled: its 0 queued as any - the mark is about enabling.
polo_shop( 4, 'publish' );
$META[7103][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '710000007100', 'at' => time() - 600 );
wc_set_status( 7103, 'private' );
check( 'L enabled with the removal mark, disabled: the shared stock\'s push queued as for any disable, its line', array( push_args(), disabled_lines() ), array( array( array( 7100, 'edit', null ) ), array( $POOL_OFF ) ) );
// The only-stock guard: never 0 while it is the listing's only variation with stock.
kit_shop( 0, 5, 'publish' );
wc_set_status( 7502, 'private' );
ebay_has( '750000007500', kit( 0, 5, array( 'b' => 1 ) ) );
run_queue();
check( 'B the listing\'s only variation with stock, disabled: read, not set to 0 (eBay shows its own 5), warned; not ended', array( calls(), logged( 'is the only variation of that listing with stock, so it was not set to 0', 'warning' ), meta( 7500, '_ebay_listing_status' ) ), array( array( 'GetItem' ), true, 'Active' ) );
polo_shop( 4, 'publish' );
wc_set_status( 7103, 'private' );
ebay_has( '710000007100', polo( null, null, 4 ) );
run_queue();
check( 'L the listing\'s only variation (shared stock), disabled: read, not set to 0, warned', array( calls(), logged( 'disabled variation(s) #7103 (PO-L) are the only variations of eBay listing 710000007100 with stock', 'warning' ) ), array( array( 'GetItem' ), true ) );
// The rules of a stock change.
kit_shop( 3, 5, 'publish' );
$OPTIONS['tcgiant_sync_ebay_settings']['push_stock_to_ebay'] = '0';
wc_set_status( 7502, 'private' );
check( 'pushes off: nothing queued, the line says why', array( push_args(), disabled_lines() ), array( array(), array( 'Variation #7502 (OS-B) of WC #7500 disabled: nothing queued for eBay listing 750000007500 - "Send website stock changes to eBay?" is No.' ) ) );
polo_shop( 4, 'publish' );
$OPTIONS['tcgiant_sync_ebay_settings'] = array();
wc_set_status( 7103, 'private' );
check( 'not connected to eBay: nothing queued, the line says why', array( push_args(), disabled_lines() ), array( array(), array( 'Variation #7103 (PO-L) of WC #7100 disabled: nothing queued for eBay listing 710000007100 - this site is not connected to eBay.' ) ) );
kit_shop( 3, 5, 'publish' );
fleet_used( 0.85 );
wc_set_status( 7502, 'private' );
run_queue();
$tcg_p = pushes();
check( 'the fleet past its 80% ceiling: queued; run, nothing sent today, it waits for the next UTC day', array( disabled_lines(), $WIRE, count( $tcg_p ), ( $tcg_p[0]['when'] ?? 0 ) >= $tcg_tomorrow ), array( array( $OWN_OFF ), array(), 1, true ) );
fleet_used( 0 );
kit_shop( 3, 5, 'publish' );
set_transient( 'tcgiant_stock_pending_7502', 1, 60 );
wc_set_status( 7502, 'private' );
check( 'a push already waiting (the 60-second mark): nothing more queued, the line says it sets it to 0', array( push_args(), disabled_lines() ), array( array(), array( 'Variation #7502 (OS-B) of WC #7500 disabled: a stock push of WC #7502 is already waiting for eBay listing 750000007500, and sets it to 0 there.' ) ) );
kit_shop( 3, 0, 'publish' );
seen( 7502, '750000007500', 1, 'OS-B' );
wc_set_status( 7502, 'private' );
ebay_has( '750000007500', kit( 3, 0, array( 'b' => 1 ) ) );
run_queue();
check( 'at 0, eBay already showing 0: read, nothing sent', array( disabled_lines(), calls() ), array( array( $OWN_OFF ), array( 'GetItem' ) ) );
// Not its doing: the import's own retire, a product not on eBay.
polo_shop( 5, 'publish' );
import_listing( polo( 5, 5, null ) );
check( 'the import retiring a variation eBay dropped (private): nothing queued, nothing said', array( status_of( 7103 ), push_args(), disabled_lines() ), array( 'private', array(), array() ) );
fresh_shop();
variable( 7600, '', array( '_manage_stock' => 'yes', '_stock' => 3 ) );
variation( 7601, 7600, 'NE-A', null, array( 'size' => 'S' ) );
wc_set_status( 7601, 'private' );
check( 'a variation of a product not on eBay disabled: nothing queued, nothing said', array( push_args(), $LOG ), array( array(), array() ) );

echo "\nENABLED AND DISABLED IN QUICK SUCCESSION: THE PUSH SENDS WHAT THE VARIATION IS WHEN IT RUNS\n" . str_repeat( '=', 132 ) . "\n";

polo_at_zero();
wc_set_status( 7103, 'publish' );
wc_set_status( 7103, 'private' );
ebay_has( '710000007100', polo( 4, 4, 0, array( 'l' => 1 ) ) );
run_queue();
check( 'L enabled, then disabled before its push runs: one push; it leaves L at 0 - no stale 4', array( count( enabled_lines() ), disabled_lines(), calls(), revises() ), array( 1, array( 'Variation #7103 (PO-L) of WC #7100 disabled: a stock push of WC #7100 is already waiting for eBay listing 710000007100, and sets it to 0 there.' ), array( 'GetItem' ), array() ) );
polo_at_zero();
wc_set_status( 7103, 'publish' );
wc_set_status( 7103, 'private' );
wc_set_status( 7103, 'publish' );
ebay_has( '710000007100', polo( 4, 4, 0, array( 'l' => 1 ) ), polo( 4, 4, 4, array( 'l' => 1 ) ) );
run_queue();
check( '  enabled, disabled, enabled: one push (each later change finds it waiting), which sends L the 4', array( push_ids(), enabled_lines(), revises() ), array( array(), array( $POOL_LINE, 'Variation #7103 (PO-L) of WC #7100 enabled again: a stock push of WC #7100 is already waiting for eBay listing 710000007100, and sends it.' ), array( array( 'PO-L' => 4 ) ) ) );
polo_shop( 4, 'publish' );
wc_set_status( 7103, 'private' );
wc_set_status( 7103, 'publish' );
ebay_has( '710000007100', polo( 4, 4, 4 ) );
run_queue();
check( '  L disabled, then enabled again before its push runs: eBay left at its 4 - read, nothing sent', array( calls(), revises() ), array( array( 'GetItem' ), array() ) );
kit_shop();
b_sent_zero( 1 );
wc_set_status( 7502, 'publish' );
wc_set_status( 7502, 'private' );
ebay_has( '750000007500', kit( 3, 0, array( 'b' => 1 ) ) );
run_queue();
check( 'B enabled, then disabled before its push runs: read, B at 0 on eBay left out - not its 5', array( calls(), revises(), off_mark( 7502 ) ), array( array( 'GetItem' ), array(), '750000007500' ) );
kit_shop();
wc_set_status( 7502, 'publish' );
wc_set_status( 7502, 'private' );
ebay_has( '750000007500', kit( 3, 5, array( 'b' => 1 ) ) );
run_queue();
check( '  B on eBay at 5 (never sent 0), enabled then disabled: its push sends it 0, not 5', revises(), array( array( 'OS-B' => 0 ) ) );
kit_shop( 3, 5, 'publish' );
wc_set_status( 7502, 'private' );
wc_set_status( 7502, 'publish' );
run_queue();
check( '  B disabled, then enabled again before its push runs: its push sends its 5, not 0', revises(), array( array( 'OS-B' => 5 ) ) );
// A disable landing while the enable's push is already under way (review
// 1's stale case): the disable queues a push of its own, which sets the 0.
kit_shop( 3, 5 );
b_sent_zero( 1 );
wc_set_status( 7502, 'publish' );
$ON_CALL['ReviseInventoryStatus'] = function () { wc_set_status( 7502, 'private' ); };
ebay_has( '750000007500', kit( 3, 5, array( 'b' => 1 ) ) );
run_queue();
check( '  B disabled while its enable\'s push is sending its 5: its own push then reads and sends 0', array( revises(), status_of( 7502 ) ), array( array( array( 'OS-B' => 5 ), array( 'OS-B' => 0 ) ), 'private' ) );

echo "\nANOTHER PLUGIN SAVING THE VARIATION AGAIN INSIDE ITS SAVE (FIX ROUND 1, REVIEW 1'S LOW 2, PROBE G)\n" . str_repeat( '=', 132 ) . "\n";

$RESAVE_BUSY = false;
$RESAVE_ON   = true;
add_action( 'woocommerce_update_product_variation', function ( $id ) {
	if ( ! $GLOBALS['RESAVE_ON'] || $GLOBALS['RESAVE_BUSY'] || 7502 !== (int) $id ) {
		return;
	}
	$GLOBALS['RESAVE_BUSY'] = true;
	wc_get_product( $id )->save();
	$GLOBALS['RESAVE_BUSY'] = false;
} );
kit_shop();
b_sent_zero( 1 );
$SAVE_FIRES = true;
wc_set_status( 7502, 'publish' );
$SAVE_FIRES = false;
check( 'G (probe): enabled, another plugin saving it again inside the save: the enable still queued, one line', array( status_of( 7502 ), push_args(), enabled_lines() ), array( 'publish', array( array( 7502, 'edit', null ) ), array( $OWN_LINE ) ) );
kit_shop( 3, 5, 'publish' );
$SAVE_FIRES = true;
wc_set_status( 7502, 'private' );
$SAVE_FIRES = false;
check( '  disabled the same way: the disable still queued, one line', array( status_of( 7502 ), push_args(), disabled_lines() ), array( 'private', array( array( 7502, 'edit', null ) ), array( $OWN_OFF ) ) );
$RESAVE_ON = false;

echo "\nA DISABLED VARIATION KEEPING ITS OWN STOCK: 0 ONCE, THEN LEFT OUT (3.43.0'S RULE)\n" . str_repeat( '=', 132 ) . "\n";

$B_LINE = 'Stock push for WC #7502: disabled variation OS-B of eBay listing 750000007500 sent 0, not its stock, 4: it is not for sale on your website, so not on eBay either. While it is disabled its stock is not sent; enabled again, it is.';
$B_GONE = ' It never sold on eBay: with Out-of-Stock control off, eBay removes a variation set to 0 before it has sold. To sell it again, enable it and Update the listing (push the product to eBay), which adds it back.';
// O1: B's stock edited 5 to 4, eBay still showing B at 5 (it sold one).
kit_shop( 3, 5 );
ebay_has( '750000007500', kit( 3, 5, array( 'b' => 1 ) ) );
hand_edit( 7502, 4 );
run_queue();
check( 'O1 B\'s stock edited 5 to 4, eBay showing it at 5: read, then 0 - until 3.44.0 its 4', array( calls(), revises(), stock( 7502 ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), array( array( 'OS-B' => 0 ) ), 4 ) );
check( '  its record made before the 0, from the read (it had none); marked as sent 0; not as removed (it had sold); the line', array( rec( 7502 ), off_mark( 7502 ), zeroed_mark( 7502 ), logged( $B_LINE ), count_logged( 'never sold on eBay' ) ), array( array( 'OS-B', 1 ), '750000007500', null, true, 0 ) );
// O2: the next edit, eBay showing B at 0: left out.
next_request();
$WIRE = array();
ebay_has( '750000007500', kit( 3, 0, array( 'b' => 1 ) ) );
hand_edit( 7502, 3 );
run_queue();
check( 'O2 the next edit, eBay showing B at 0: read, nothing sent - left out', array( calls(), logged( 'Stock push for WC #7502: disabled variation OS-B of eBay listing 750000007500 already shows 0, so nothing was sent: while it is disabled its stock is not sent.' ) ), array( array( 'GetItem' ), true ) );
// O3: B never sold: marked as eBay removes it (Out-of-Stock control off).
kit_shop( 3, 5 );
ebay_has( '750000007500', kit( 3, 5 ) );
hand_edit( 7502, 4 );
run_queue();
check( 'O3 B never sold: 0, marked as one eBay removes with Out-of-Stock control off, and the line says so', array( revises(), zeroed_mark( 7502 )['item'] ?? null, logged( $B_LINE . $B_GONE ) ), array( array( array( 'OS-B' => 0 ) ), '750000007500', true ) );
kit_shop( 3, 5 );
$OPTIONS[ TCGiant_Sync_Importer::OOS_PREF_OPTION ] = array( 'pref' => 'true', 'ok' => true, 'at' => time() );
ebay_has( '750000007500', kit( 3, 5 ) );
hand_edit( 7502, 4 );
run_queue();
check( '  Out-of-Stock control on, as last heard: marked all the same, no removal words', array( null !== zeroed_mark( 7502 ), count_logged( 'never sold on eBay' ) ), array( true, 0 ) );
unset( $OPTIONS[ TCGiant_Sync_Importer::OOS_PREF_OPTION ] );
// O4: B the listing's only variation with stock: never 0, never raised.
kit_shop( 3, 5 );
ebay_has( '750000007500', kit( 0, 5, array( 'b' => 1 ) ) );
hand_edit( 7502, 4 );
run_queue();
$B_ONLY = 'Stock push for WC #7502: disabled variation OS-B of eBay listing 750000007500 is the only variation of that listing with stock, so it was not set to 0 - that would leave the listing nothing to sell, which eBay refuses without Out-of-Stock control. It is kept no higher than its own stock, 4. To stop it selling on eBay, end the listing in Seller Hub, or enable another variation.';
check( 'O4 B the only variation with stock (A at 0 on eBay), eBay 5, its stock 4: not 0 - lowered to its 4; warned; not ended', array( revises(), logged( $B_ONLY, 'warning' ), in_array( 'EndItem', calls(), true ), off_mark( 7502 ) ), array( array( array( 'OS-B' => 4 ) ), true, false, null ) );
kit_shop( 3, 7 );
ebay_has( '750000007500', kit( 0, 5, array( 'b' => 1 ) ) );
hand_edit( 7502, 6 );
run_queue();
check( '  eBay 5, its stock 6: never raised - nothing sent', array( calls(), revises() ), array( array( 'GetItem' ), array() ) );
kit_shop( 3, 1 );
ebay_has( '750000007500', kit( 0, 5, array( 'b' => 1 ) ) );
hand_edit( 7502, 0 );
run_queue();
check( '  its own stock run out (0): sent 0, as any variation\'s - never more on eBay than here; no kept warning', array( revises(), count_logged( 'kept no higher than its own stock' ) ), array( array( array( 'OS-B' => 0 ) ), 0 ) );
// A second disabled variation C with stock on eBay: not counted, so the two
// never set each other to 0 and leave the listing nothing to sell.
kit_shop( 3, 5 );
variation( 7503, 7500, 'OS-C', 2, array( 'size' => 'L' ), array( '_ebay_variation_sku' => 'OS-C' ) );
$POSTS[7503]['status'] = 'private';
ebay_has( '750000007500', kit( 0, 5, array( 'b' => 1 ), 2 ) );
hand_edit( 7502, 4 );
run_queue();
check( '  another disabled variation with stock on eBay is not counted: B kept, as the only stock', array( revises(), logged( 'is the only variation of that listing with stock' ) ), array( array( array( 'OS-B' => 4 ) ), true ) );
// O5: eBay refuses B's 0: never ended, tried again.
kit_shop( 3, 5 );
ebay_has( '750000007500', kit( 3, 5, array( 'b' => 1 ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21916799, 'You cannot set the quantity of the last variation with stock to 0.' ) );
hand_edit( 7502, 4 );
run_queue();
check( 'O5 eBay refusing B\'s 0: not ended, not marked, tried again, said', array( in_array( 'EndItem', calls(), true ), meta( 7500, '_ebay_listing_status' ), off_mark( 7502 ), count( pushes() ), logged( 'eBay did not take 0 for disabled variation OS-B', 'warning' ) ), array( false, 'Active', null, 1, true ) );
// Not on eBay at all: nothing sent, and no error.
kit_shop( 3, 5 );
ebay_has( '750000007500', kit( 3, null ) );
hand_edit( 7502, 4 );
run_queue();
check( 'B not on the listing: read, nothing sent, said plainly - no error', array( calls(), logged( 'disabled variation OS-B is not on eBay listing 750000007500 with a SKU and a quantity, so nothing was sent' ), count( array_filter( $LOG, function ( $l ) { return 'error' === $l[0]; } ) ) ), array( array( 'GetItem' ), true, 0 ) );

echo "\nAN EBAY SALE AND A WEBSITE SALE OF IT\n" . str_repeat( '=', 132 ) . "\n";

// O6: eBay sold one of B since its record, before the 0: off its stock first.
kit_shop( 3, 6 );
seen( 7502, '750000007500', 1, 'OS-B' );
ebay_has( '750000007500', kit( 3, 4, array( 'b' => 2 ) ) );
hand_edit( 7502, 5 );
$WIRE = array();
run_queue();
check( 'O6 an eBay sale of B since its record, before the 0: taken off first (5 to 4), then 0; its record moves on', array( stock( 7502 ), revises(), rec( 7502 ) ), array( 4, array( array( 'OS-B' => 0 ) ), array( 'OS-B', 2 ) ) );
// O7: an eBay sale between the push's read and its 0: order sync takes it off, once.
kit_shop( 3, 5 );
ebay_has( '750000007500', kit( 3, 5, array( 'b' => 1 ) ) );
hand_edit( 7502, 4 );
run_queue();
next_request();
$WIRE = array();
ebay_has( '750000007500', kit( 3, 0, array( 'b' => 2 ) ), kit( 3, 0, array( 'b' => 2 ) ) );
$IMP->settle_item( '750000007500' );
$tcg_once = stock( 7502 );
$IMP->settle_item( '750000007500' );
check( 'O7 an eBay sale between the read and the 0: order sync\'s settle takes it off B\'s own stock once (4 to 3), never to eBay\'s 0', array( $tcg_once, stock( 7502 ), rec( 7502 ), count( pushes() ) ), array( 3, 3, array( 'OS-B', 2 ), 0 ) );
// A website sale of B (an order made by hand): its push reads, and sends 0.
kit_shop( 3, 5 );
ebay_has( '750000007500', kit( 3, 5, array( 'b' => 1 ) ) );
website_order( 7550, array( 7502 => 1 ) );
run_queue();
check( 'a website sale of B: 5 to 4 here; its push reads and sends 0, not 4; the order line marked as sent', array( stock( 7502 ), push_args(), revises(), meta( 7500, '_ebay_listing_status' ), '' !== (string) $ORDERS[7550]->get_items()[0]->get_meta( '_tcgiant_ebay_push' ) ), array( 4, array(), array( array( 'OS-B' => 0 ) ), 'Active', true ) );
kit_shop( 3, 4 );
b_sent_zero( 1 );
ebay_has( '750000007500', kit( 3, 0, array( 'b' => 1 ) ) );
website_order( 7551, array( 7502 => 1 ) );
run_queue();
check( '  B already at 0 on eBay: its sale\'s push reads and sends nothing', array( stock( 7502 ), calls() ), array( 3, array( 'GetItem' ) ) );

echo "\nTHE IMPORT AFTERWARDS: B STAYS DISABLED, AND EBAY'S 0 IS NOT ITS STOCK\n" . str_repeat( '=', 132 ) . "\n";

kit_shop( 3, 5 );
ebay_has( '750000007500', kit( 3, 5, array( 'b' => 1 ) ) );
hand_edit( 7502, 4 );
run_queue();
next_request();
$WIRE = array();
import_listing( kit( 3, 0, array( 'b' => 1 ) ) );
check( 'the import after B\'s 0: B stays disabled at 4 - not eBay\'s 0; its record as it was; nothing queued - until 3.44.0 it was published again at 0', array( status_of( 7502 ), stock( 7502 ), rec( 7502 ), count( pushes() ), calls() ), array( 'private', 4, array( 'OS-B', 1 ), 0, array() ) );
import_listing( kit( 3, 0, array( 'b' => 2 ) ) );
check( '  a sale eBay made before the 0 landed: the next import takes it off B\'s own stock (4 to 3), and says so', array( status_of( 7502 ), stock( 7502 ), rec( 7502 ), logged( 'Variation #7502 set to 3: eBay sold 1 of it since its record, taken off its own stock - not eBay\'s 0' ) ), array( 'private', 3, array( 'OS-B', 2 ), true ) );
// Disabled with no stock change yet, eBay selling it at its own figure: an
// eBay sale comes off it once, as before, and it stays disabled.
kit_shop( 3, 5 );
seen( 7502, '750000007500', 1, 'OS-B' );
import_listing( kit( 3, 4, array( 'b' => 2 ) ) );
check( 'B disabled, never sent 0, eBay selling it: the import takes eBay\'s sale off (5 to 4) and leaves it disabled', array( status_of( 7502 ), stock( 7502 ), rec( 7502 ) ), array( 'private', 4, array( 'OS-B', 2 ) ) );
// No record at all: eBay's figure, as before.
kit_shop( 3, 5 );
import_listing( kit( 3, 2, array( 'b' => 3 ) ) );
check( '  with no record of eBay\'s sales: eBay\'s figure, as before (it says what eBay sold), still disabled', array( status_of( 7502 ), stock( 7502 ), rec( 7502 ) ), array( 'private', 2, array( 'OS-B', 3 ) ) );
// Enabled again, its push not landed yet: the import does not write eBay's 0 over its stock.
kit_shop( 3, 5, 'publish' );
b_sent_zero( 1 );
import_listing( kit( 3, 0, array( 'b' => 1 ) ) );
check( 'B enabled, its push not landed yet, eBay still at the 0: the import keeps its 5, enabled, marked', array( status_of( 7502 ), stock( 7502 ), off_mark( 7502 ) ), array( 'publish', 5, '750000007500' ) );
import_listing( kit( 3, 5, array( 'b' => 1 ) ) );
check( '  once eBay shows its 5 (its push landed): the mark goes, and eBay\'s figure is its stock again', array( stock( 7502 ), off_mark( 7502 ) ), array( 5, null ) );
import_listing( kit( 3, 4, array( 'b' => 2 ) ) );
check( '  as for any enabled variation: eBay\'s 4', stock( 7502 ), 4 );
// The mark of an earlier listing (the product relisted since): eBay's figure for this one is its stock.
kit_shop( 3, 5, 'publish' );
seen( 7502, '750000007500', 1, 'OS-B' );
$META[7502][ TCGiant_Sync_Mapper::OFF_META ] = array( 'item' => '700000000001', 'sku' => 'OS-B', 'at' => time() - 3600 );
import_listing( kit( 3, 0, array( 'b' => 3 ) ) );
check( '  the mark of an earlier listing: eBay sold it out on this one - eBay\'s 0, as for any enabled variation', stock( 7502 ), 0 );
// The import still publishes a variation it retired itself (RETIRED_META).
kit_shop( 3, 5, 'publish' );
seen( 7502, '750000007500', 0, 'OS-B' );
import_listing( kit( 3, null ) );
$tcg_retired = array( status_of( 7502 ), stock( 7502 ), null !== meta( 7502, TCGiant_Sync_Mapper::RETIRED_META ) );
import_listing( kit( 3, 6 ) );
check( 'the import retires B when eBay drops it (private, 0, marked), and publishes it again when eBay lists it again, at eBay\'s 6', array( $tcg_retired, status_of( 7502 ), stock( 7502 ), meta( 7502, TCGiant_Sync_Mapper::RETIRED_META ) ), array( array( 'private', 0, true ), 'publish', 6, null ) );
kit_shop( 3, 5 );
import_listing( kit( 3, 6 ) );
check( '  one the shop disabled (no mark): left disabled - until 3.44.0 published again (3.32.0\'s restore of 3.30\'s copies)', status_of( 7502 ), 'private' );
// Order sync's settle, B disabled and at 0 on eBay: no sale, no change.
kit_shop( 3, 4 );
b_sent_zero( 1 );
ebay_has( '750000007500', kit( 3, 0, array( 'b' => 1 ) ) );
$IMP->settle_item( '750000007500' );
check( 'order sync\'s settle, B at 0 on eBay with its record: no sale - its 4 kept', array( stock( 7502 ), rec( 7502 ) ), array( 4, array( 'OS-B', 1 ) ) );

echo "\nFIX ROUND 1: THE REVIEW'S PROBES C, D, E AND F, AND ITS MUTANTS R2 AND R5\n" . str_repeat( '=', 132 ) . "\n";

// Probe C (review 1's LOW 1): B enabled, its own push not landed, eBay back
// above 0 - a cancelled eBay sale from before the 0 gives a unit back. eBay's
// 1 is not B's stock: the mark stays, and nothing comes off.
kit_shop( 3, 5, 'publish' );
b_sent_zero( 2 );
import_listing( kit( 3, 1, array( 'b' => 1 ) ) );
check( 'C (probe): B enabled, its push not landed, a cancelled eBay sale gives a unit back (eBay 1): the import keeps its 5 and the mark - before fix round 1, 1', array( stock( 7502 ), off_mark( 7502 ), logged( 'fewer than the 2 recorded' ) ), array( 5, '750000007500', true ) );
kit_shop( 3, 5, 'publish' );
b_sent_zero( 2 );
ebay_has( '750000007500', kit( 3, 1, array( 'b' => 1 ) ) );
$IMP->settle_item( '750000007500' );
check( '  order sync\'s settle the same: 5 kept, the mark kept', array( stock( 7502 ), off_mark( 7502 ) ), array( 5, '750000007500' ) );
// Probe D: B disabled, the listing's only stock on eBay, its own stock run
// out: sent 0 as any variation's. eBay refusing it: with A enabled and
// holding stock here, not ended; with none anywhere, ended - as any variation's 0.
kit_shop( 3, 1 );
ebay_has( '750000007500', kit( 0, 5, array( 'b' => 1 ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21916799, 'You cannot set the quantity of the last variation with stock to 0.' ) );
hand_edit( 7502, 0 );
run_queue();
check( 'D1 (probe): B\'s own stock run out, eBay refusing its 0, A enabled with 3 here: not ended, tried again', array( in_array( 'EndItem', calls(), true ), meta( 7500, '_ebay_listing_status' ), count( pushes() ) ), array( false, 'Active', 1 ) );
kit_shop( 0, 1 );
ebay_has( '750000007500', kit( 0, 5, array( 'b' => 1 ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21916799, 'You cannot set the quantity of the last variation with stock to 0.' ) );
hand_edit( 7502, 0 );
run_queue();
check( 'D2 (probe): the same with no enabled variation holding stock anywhere: ended, as any variation\'s 0 - the readme says so now', array( calls(), meta( 7500, '_ebay_listing_status' ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), 'Ended' ) );
// Probe E (review 1's M2): a variation an import before 3.32.0 retired -
// private, 0, out of stock, no mark, and no record of what eBay calls it,
// which every import since 3.32.0 writes - listed by eBay again: published
// again with eBay's figure, as 3.32.0's first import would have.
kit_shop( 3, 0 );
unset( $META[7502][ TCGiant_Sync_Variations::SPECIFICS_META ] );
$META[7502]['_stock_status'] = 'outofstock';
seen( 7502, '750000007500', 2, 'OS-B' );
import_listing( kit( 3, 4, array( 'b' => 2 ) ) );
check( 'E (probe): retired by an import before 3.32.0, eBay listing it at 4: published again at 4, said - before fix round 1 left private at 0', array( status_of( 7502 ), stock( 7502 ), meta( 7502, TCGiant_Sync_Mapper::RETIRED_META ), logged( 'Variation #7502 of WC #7500: no import since 3.32.0 had found it' ) ), array( 'publish', 4, null, true ) );
// One the import retired itself (its mark), with no record of what eBay calls
// it: published again by its mark, as ever - not said as 3.32.0's restore.
kit_shop( 3, 0 );
unset( $META[7502][ TCGiant_Sync_Variations::SPECIFICS_META ] );
$META[7502][ TCGiant_Sync_Mapper::RETIRED_META ] = (string) ( time() - 600 );
$META[7502]['_stock_status'] = 'outofstock';
seen( 7502, '750000007500', 2, 'OS-B' );
import_listing( kit( 3, 4, array( 'b' => 2 ) ) );
check( '  one the import retired itself (its mark), no record of eBay\'s names: published again at 4 by its mark, not said as 3.32.0\'s restore', array( status_of( 7502 ), stock( 7502 ), logged( 'no import since 3.32.0 had found it' ) ), array( 'publish', 4, false ) );
// L, taking the shared stock, disabled with the shared stock at 0 and no
// record of eBay's names: 3.43.0's rule - left disabled; the restore is for
// one keeping its own stock.
polo_shop( 0 );
import_listing( polo( 0, 0, 0 ) );
check( '  L disabled, the shared stock at 0, no record of eBay\'s names: left disabled, not said - the restore is for one keeping its own stock', array( status_of( 7103 ), logged( 'no import since 3.32.0 had found it' ) ), array( 'private', false ) );
// A sold-out variation the shop disabled (an import since 3.32.0 found it:
// its record of what eBay calls it): left disabled, its 0 kept.
kit_shop( 3, 0 );
$META[7502]['_stock_status'] = 'outofstock';
seen( 7502, '750000007500', 2, 'OS-B' );
import_listing( kit( 3, 4, array( 'b' => 2 ) ) );
check( '  a sold-out variation the shop disabled, found by an import since 3.32.0: left disabled at 0, unmarked', array( status_of( 7502 ), stock( 7502 ), meta( 7502, TCGiant_Sync_Mapper::RETIRED_META ) ), array( 'private', 0, null ) );
kit_shop( 3, 2 );
unset( $META[7502][ TCGiant_Sync_Variations::SPECIFICS_META ] );
seen( 7502, '750000007500', 2, 'OS-B' );
import_listing( kit( 3, 4, array( 'b' => 2 ) ) );
check( '  with no record of what eBay calls it but stock of its own (2): the shop\'s, left disabled at 2', array( status_of( 7502 ), stock( 7502 ), meta( 7502, TCGiant_Sync_Mapper::RETIRED_META ) ), array( 'private', 2, null ) );
// Probe F (review 1's LOW 3): B disabled with 4 of its own, gone from the
// listing (deleted in Seller Hub, or a relist without it): left as it is -
// not wiped to 0, not marked - and not published again when eBay lists it.
kit_shop( 3, 4 );
b_sent_zero( 1 );
import_listing( kit( 3, null ) );
check( 'F (probe): disabled with its own 4, gone from the listing: left disabled at 4, unmarked - before fix round 1 retired at 0', array( status_of( 7502 ), stock( 7502 ), meta( 7502, TCGiant_Sync_Mapper::RETIRED_META ), count_logged( 'Variation #7502 is no longer on eBay' ) ), array( 'private', 4, null, 0 ) );
import_listing( kit( 3, 2 ) );
check( '  eBay lists it again at 2: still disabled, its 4 kept - not published at eBay\'s 2', array( status_of( 7502 ), stock( 7502 ) ), array( 'private', 4 ) );
kit_shop( 3, 0 );
import_listing( kit( 3, null ) );
check( '  one disabled at 0, gone from the listing: retired and marked, as ever (an older import\'s retire is at 0)', array( status_of( 7502 ), null !== meta( 7502, TCGiant_Sync_Mapper::RETIRED_META ) ), array( 'private', true ) );
polo_shop( 5 );
import_listing( polo( 5, 5, null ) );
check( '  L disabled, taking the shared 5, gone from the listing: retired and marked, as ever - the shared stock is not its own', array( status_of( 7103 ), null !== meta( 7103, TCGiant_Sync_Mapper::RETIRED_META ) ), array( 'private', true ) );
// The review's mutant R5: the listing's only stock, eBay showing exactly its own stock.
kit_shop( 3, 6 );
ebay_has( '750000007500', kit( 0, 5, array( 'b' => 1 ) ) );
hand_edit( 7502, 5 );
run_queue();
check( 'R5: B the listing\'s only stock, eBay showing exactly its own 5: read, nothing sent', array( calls(), revises() ), array( array( 'GetItem' ), array() ) );
// The review's mutant R2, now the code: B disabled, a website sale of it
// that never reached eBay, eBay still showing more than its stock - the sale
// check queues its push, which sets it to 0.
kit_shop( 3, 4 );
seen( 7502, '750000007500', 1, 'OS-B' );
$META[7502]['_ebay_push_pending'] = array( 'unsent' => time() + DAY_IN_SECONDS );
$META[7502]['_ebay_unsent_qty']   = 1;
ebay_has( '750000007500', kit( 3, 5, array( 'b' => 1 ) ) );
$IMP->settle_item( '750000007500' );
$tcg_after = array( stock( 7502 ), push_args() );
run_queue();
check( 'R2: B disabled with a website sale that never reached eBay (eBay 5, its 4): its 4 kept, its push queued; run, eBay sets it to 0', array( $tcg_after, revises() ), array( array( 4, array( array( 7502, 'edit', null ) ) ), array( array( 'OS-B' => 0 ) ) ) );

echo "\nFIX ROUND 2: REVIEW 2'S PROBES A3, DS8 AND M2W2, ITS MUTANTS S3 AND S4, AND THE SHOP'S NOTE OF A DISABLE\n" . str_repeat( '=', 132 ) . "\n";

// Review 2's probe A3 (LOW 1): L never sold, sent 0 and marked, the control
// not known on; enabled (nothing queued, "may"); a website sale of S queues
// the parent's push; the import, while that sale's push is on its way, finds
// L still listed at 0: it drops the mark and queues the push that sends the
// shared stock - a sale's push only lowers eBay's figure, so L stayed at 0.
polo_shop( 4 );
ebay_has( '710000007100', polo( 4, 4, 4 ), polo( 3, 3, 0 ) );
hand_edit( 7100, 3 );
run_queue();
next_request();
$WIRE = array();
wc_set_status( 7103, 'publish' );
website_order( 7580, array( 7101 => 1 ) );
$tcg_before = push_args();
import_listing( polo( 3, 3, 0 ) );
$tcg_after = array( zeroed_mark( 7103 ), push_args(), logged( $NUDGE_LINE ) );
ebay_has( '710000007100', polo( 3, 3, 0 ), polo( 2, 2, 0 ), polo( 2, 2, 0 ), polo( 2, 2, 2 ) );
run_queue();
check( 'A3 (probe): a website sale\'s push on its way at the import - the import still queues the shared stock\'s push; run, the sale lowers S and M, then L gets the shared 2 - before fix round 2 L stayed at 0', array( $tcg_before, $tcg_after, revises() ), array( array( array( 7100, 'order', 1 ) ), array( null, array( array( 7100, 'order', 1 ), array( 7100, 'edit', null ) ), true ), array( array( 'PO-S' => 2, 'PO-M' => 2 ), array( 'PO-L' => 2 ) ) ) );
// Review 2's mutant S4, pinned as the narrowed rule: an edit's push of the
// shared stock already on its way (past its 60-second mark) sends the full
// figure - the import queues no second.
polo_shop( 4, 'publish' );
$META[7103][ TCGiant_Sync_Mapper::ZEROED_META ] = array( 'item' => '710000007100', 'at' => time() - 600 );
hand_edit( 7100, 3 );
$TRANSIENTS = array();
import_listing( polo( 3, 3, 0 ) );
check( '  an edit\'s push on its way (its 60-second mark gone): the import queues none, says nothing - that push sends the figure', array( zeroed_mark( 7103 ), push_args(), logged( $NUDGE_LINE ) ), array( null, array( array( 7100, 'edit', null ) ), false ) );
// Own stock: B's own website sale on its way at the import, the "sent 0"
// mark on: the import queues B's edit push too, and notes it.
kit_shop( 3, 5, 'publish' );
b_sent_zero( 1 );
website_order( 7581, array( 7502 => 1 ) );
import_listing( kit( 3, 0, array( 'b' => 1 ) ) );
check( '  B, its own website sale on its way, eBay still at 0: the import queues B\'s push beside the sale\'s, noted once', array( push_args(), ! empty( meta( 7502, TCGiant_Sync_Mapper::OFF_META )['nudged'] ) ), array( array( array( 7502, 'order', 1 ), array( 7502, 'edit', null ) ), true ) );

// Review 2's probe DS8 (LOW 2): a save of B that throws between WooCommerce's
// two actions (another plugin's listener), caught by its caller, as Action
// Scheduler, a WP-CLI loop or a REST batch catches it and goes on; a later
// disable and enable of B in the same process are each answered.
kit_shop( 3, 5, 'publish' );
seen( 7502, '750000007500', 1, 'OS-B' );
$THROW_ONCE = true;
add_action( 'woocommerce_update_product_variation', function ( $id ) {
	if ( ! empty( $GLOBALS['THROW_ONCE'] ) && 7502 === (int) $id ) {
		$GLOBALS['THROW_ONCE'] = false;
		throw new RuntimeException( 'another plugin failed' );
	}
} );
$tcg_caught = '';
$SAVE_FIRES = true;
try {
	$tcg_v = wc_get_product( 7502 );
	$tcg_v->set_name( 'x' );
	$tcg_v->save();
} catch ( RuntimeException $e ) {
	$tcg_caught = $e->getMessage();
}
$SAVE_FIRES = false;
wc_set_status( 7502, 'private' );
$tcg_off = array( status_of( 7502 ), push_args(), disabled_lines() );
wc_set_status( 7502, 'publish' );
check( 'DS8 (probe): a save that threw, then B disabled and enabled in the same process: each answered - before fix round 2 both were taken as nested saves', array( $tcg_caught, $tcg_off, enabled_lines() ), array( 'another plugin failed', array( 'private', array( array( 7502, 'edit', null ) ), array( $OWN_OFF ) ), array( 'Variation #7502 (OS-B) of WC #7500 enabled again: a stock push of WC #7502 is already waiting for eBay listing 750000007500, and sends it.' ) ) );
// The nested save (probe G) still counts as nested after a save that threw.
kit_shop( 3, 5, 'publish' );
$THROW_ONCE = true;
$SAVE_FIRES = true;
try {
	wc_get_product( 7502 )->save();
} catch ( RuntimeException $e ) {
	unset( $e );
}
$RESAVE_ON = true;
wc_set_status( 7502, 'private' );
$RESAVE_ON = false;
$SAVE_FIRES = false;
check( '  after a save that threw, a disable with another plugin saving B again inside it: still one push, one line', array( status_of( 7502 ), push_args(), disabled_lines() ), array( 'private', array( array( 7502, 'edit', null ) ), array( $OWN_OFF ) ) );

// Review 2's probe M2W2 (LOW 3): B, at 0, disabled by the shop on a product
// no import since 3.32.0 has read (no record of eBay's names), its 0 never
// on eBay (pushes off), eBay still showing 2: the shop's note keeps it
// disabled - before fix round 2 published at eBay's 2.
kit_shop( 3, 0, 'publish' );
unset( $META[7502][ TCGiant_Sync_Variations::SPECIFICS_META ] );
$OPTIONS['tcgiant_sync_ebay_settings']['push_stock_to_ebay'] = '0';
wc_set_status( 7502, 'private' );
$tcg_note = null !== meta( 7502, TCGiant_Sync_Mapper::SHOP_OFF_META );
seen( 7502, '750000007500', 3, 'OS-B' );
import_listing( kit( 3, 2, array( 'b' => 3 ) ) );
check( 'M2W2 (probe): disabled by the shop at 0, no record of eBay\'s names, eBay still at 2: noted, and left disabled at 0 by the import - before fix round 2 published at 2', array( $tcg_note, status_of( 7502 ), stock( 7502 ), logged( 'no import since 3.32.0 had found it' ) ), array( true, 'private', 0, false ) );
wc_set_status( 7502, 'publish' );
check( '  enabled again by the shop: the note taken off', meta( 7502, TCGiant_Sync_Mapper::SHOP_OFF_META ), null );
$OPTIONS['tcgiant_sync_ebay_settings']['push_stock_to_ebay'] = '1';
// The same with eBay at 0 (review 2's M2W1).
kit_shop( 3, 0, 'publish' );
unset( $META[7502][ TCGiant_Sync_Variations::SPECIFICS_META ] );
wc_set_status( 7502, 'private' );
seen( 7502, '750000007500', 3, 'OS-B' );
import_listing( kit( 3, 0, array( 'b' => 3 ) ) );
check( '  M2W1: the same with eBay at 0: left disabled at 0', array( status_of( 7502 ), stock( 7502 ) ), array( 'private', 0 ) );
// The note on a product not on eBay yet: it is the shop's choice whenever the
// product is linked.
fresh_shop();
variable( 7600, '', array( '_manage_stock' => 'yes', '_stock' => 3 ) );
variation( 7601, 7600, 'NE-A', null, array( 'size' => 'S' ) );
wc_set_status( 7601, 'private' );
check( '  a variation of a product not on eBay, disabled: noted, nothing queued, nothing said', array( null !== meta( 7601, TCGiant_Sync_Mapper::SHOP_OFF_META ), push_args(), $LOG ), array( true, array(), array() ) );
// Never by the import's own writes: its retire, its publishing again.
polo_shop( 5, 'publish' );
import_listing( polo( 5, 5, null ) );
$tcg_retired = array( status_of( 7103 ), meta( 7103, TCGiant_Sync_Mapper::SHOP_OFF_META ) );
import_listing( polo( 5, 5, 5 ) );
check( '  the import retiring L, then publishing it again: no note either way', array( $tcg_retired, status_of( 7103 ), meta( 7103, TCGiant_Sync_Mapper::SHOP_OFF_META ) ), array( array( 'private', null ), 'publish', null ) );
// Disabled before 3.44.0 (no note) on a product no import since 3.32.0 has
// read: 3.32.0's restore, as before - what is left of M2's window.
kit_shop( 3, 0 );
unset( $META[7502][ TCGiant_Sync_Variations::SPECIFICS_META ] );
seen( 7502, '750000007500', 3, 'OS-B' );
import_listing( kit( 3, 2, array( 'b' => 3 ) ) );
check( '  disabled with no note (before 3.44.0), no record of eBay\'s names, eBay at 2: published at 2, as 3.32.0 to 3.43.0 did - the window left', array( status_of( 7502 ), stock( 7502 ) ), array( 'publish', 2 ) );

// Review 2's mutant S3: a move between two statuses that are not 'publish'
// is neither an enable nor a disable - private to draft (REST v3) was off the
// website already, its 0 queued when it was disabled; draft back to private
// the same. No push, no line, no note.
kit_shop( 3, 5 );
wc_rest_variation( 7502, array( 'status' => 'draft' ) );
$tcg_draft = array( status_of( 7502 ), push_args(), disabled_lines(), meta( 7502, TCGiant_Sync_Mapper::SHOP_OFF_META ) );
wc_rest_variation( 7502, array( 'status' => 'private' ) );
check( 'S3: private to draft, then draft to private (REST v3): neither a disable - no push, no line, no note', array( $tcg_draft, status_of( 7502 ), push_args(), disabled_lines(), enabled_lines() ), array( array( 'draft', array(), array(), null ), 'private', array(), array(), array() ) );
kit_shop( 3, 5, 'publish' );
wc_rest_variation( 7502, array( 'status' => 'draft' ) );
check( '  publish to draft (REST v3) is a disable: its push, its line, the note', array( push_args(), disabled_lines(), null !== meta( 7502, TCGiant_Sync_Mapper::SHOP_OFF_META ) ), array( array( array( 7502, 'edit', null ) ), array( $OWN_OFF ), true ) );

echo "\nSEND STOCK TO EBAY (THE BULK ACTION): B'S OWN PUSH, SAID APART\n" . str_repeat( '=', 132 ) . "\n";

kit_shop( 3, 5 );
$tcg_said = TCGiant_Sync_Inventory::send_stock_to_ebay( array( 7500 ) );
TCGiant_Sync_Inventory::log_send_stock( $tcg_said, 'products list', 1 );
check( 'Send stock to eBay on the kit: A\'s push and B\'s queued - until 3.44.0 B was named as not on eBay', array( push_ids(), $tcg_said['revise']['ids'], $tcg_said['off_own']['ids'], $tcg_said['not_linked']['n'], TCGiant_Sync_Inventory::send_stock_queued( $tcg_said ), TCGiant_Sync_Inventory::send_stock_skipped( $tcg_said ) ), array( array( 7501, 7502 ), array( 7501 ), array( 7502 ), 0, 2, 0 ) );
check( '  its log line', logged( 'Send stock to eBay (products list, user #1): 2 queued (ReviseInventoryStatus 1: #7501; disabled, keeping its own stock, sent 0 unless the listing\'s only stock 1: #7502); skipped 0.' ), true );
check( '  its notice line, not a warning', array( TCGiant_Sync_Inventory::send_stock_lines( $tcg_said )[2] ?? null, TCGiant_Sync_Inventory::send_stock_warns( $tcg_said ) ), array( '1 disabled variation keeping its own stock: its push sets it to 0 on eBay if eBay still shows it with stock, unless it is the listing\'s last variation with stock and still has stock of its own here, when the log says so: #7502.', false ) );
ebay_has( '750000007500', kit( 3, 5, array( 'b' => 1 ) ) );
run_queue();
check( '  run: A sent its 3; B read and sent 0', array( calls(), array_map( 'statuses', all_sent( 'ReviseInventoryStatus' ) ) ), array( array( 'ReviseInventoryStatus', 'GetItem', 'ReviseInventoryStatus' ), array( array( 'OS-A' => 3 ), array( 'OS-B' => 0 ) ) ) );
kit_shop( 3, 5 );
b_sent_zero( 1 );
$tcg_said = TCGiant_Sync_Inventory::send_stock_to_ebay( array( 7502 ) );
ebay_has( '750000007500', kit( 3, 0, array( 'b' => 1 ) ) );
run_queue();
check( '  B chosen by itself, eBay already at 0 for it: said apart, read, nothing sent', array( $tcg_said['off_own']['ids'], calls() ), array( array( 7502 ), array( 'GetItem' ) ) );
check( '  the merged batches keep the new list (the Listings page\'s job)', TCGiant_Sync_Inventory::merge_send_stock( null, TCGiant_Sync_Inventory::send_stock_said() )['off_own'], array( 'n' => 0, 'ids' => array() ) );
$tcg_said_plural = TCGiant_Sync_Inventory::send_stock_said();
$tcg_said_plural['off_own'] = array( 'n' => 2, 'ids' => array( 7502, 7503 ) );
check( '  two: the plural words', TCGiant_Sync_Inventory::send_stock_lines( $tcg_said_plural )[1] ?? null, '2 disabled variations keeping their own stock: each one\'s push sets it to 0 on eBay if eBay still shows it with stock, unless it is the listing\'s last variation with stock and still has stock of its own here, when the log says so: #7502, #7503.' );

echo "\nTHE WORDS\n" . str_repeat( '=', 132 ) . "\n";

$tcg_readme = (string) file_get_contents( $ROOT . '/readme.txt' );
$tcg_log    = (string) file_get_contents( $ROOT . '/changelog.txt' );
$tcg_md     = (string) file_get_contents( $ROOT . '/README.md' );
check( 'the readme\'s entry: enabled or disabled, sent as a stock change would; the removal mark only once found gone; a disabled variation keeping its own stock as the shared one\'s; the import leaves it disabled', array(
	substr_count( $tcg_readme, '* CHANGE: A variation you enable again in WooCommerce has its stock sent to eBay, as a stock change of it would send it:' ),
	substr_count( $tcg_readme, 'A variation you disable is sent 0 on eBay the same way, unless it is the listing\'s only variation with stock and still has stock of its own here (or, sharing its product\'s stock, the shared stock).' ),
	substr_count( $tcg_readme, 'Enabled again, it is sent its stock if TCGiant Sync last heard that Out-of-Stock control is on; otherwise nothing is sent until the next import of the listing tells: still listed, its stock is sent then; gone, the product\'s edit screen says so, and Update eBay Listing adds it back.' ),
	substr_count( $tcg_readme, '* CHANGE: A variation you disabled that keeps its own stock is no longer sent that stock on eBay. Disabling it, its next stock change, or Send stock to eBay reads eBay' ),
	substr_count( $tcg_readme, 'it is never set to 0 while it is the listing\'s only variation with stock and still has stock of its own here: it is then kept no higher than that stock, never raised, and the log says what to do. When its own stock runs out, it goes to 0 as any variation does, by the same rules.' ),
	substr_count( $tcg_readme, '* FIX: The import no longer turns a disabled variation keeping its own stock back on, and no longer sets its stock to eBay\'s 0:' ),
	substr_count( $tcg_readme, 'One that leaves the eBay listing with stock here keeps it and stays disabled. The import still turns back on a variation an import took off your website itself, before 3.32.0 too, once eBay lists it again.' ),
	substr_count( $tcg_readme, 'Send stock to eBay (Bulk actions) now sends a disabled variation keeping its own stock too' ),
	substr_count( $tcg_readme, 'eBay refusing its 0 never ends the listing' ),
	substr_count( preg_match( '/= 3\.44\.0 - (?:YYYY-MM-DD|\d{4}-\d{2}-\d{2}) =(.*?)= 3\.43\.0 - 2026-11-19 =/s', $tcg_readme, $tcg_m ) ? $tcg_m[1] : 'straight away', 'straight away' ),
), array( 1, 1, 1, 1, 1, 1, 1, 1, 0, 0 ) );
check( 'the Upgrade Notice: disabling and enabling, and a disabled variation keeping its own stock', substr_count( $tcg_readme, "= 3.44.0 =\nA variation you disable is now set to 0 on eBay, and one you enable again has its stock sent, as a stock change would - one keeping its own stock included, which is no longer sent its stock while disabled. The import leaves a disabled variation disabled." ), 1 );
check( 'the changelog\'s entry and README.md\'s rows', array( preg_match( '/^(?:YYYY-MM-DD|\d{4}-\d{2}-\d{2}) - version 3\.44\.0/m', $tcg_log ), substr_count( $tcg_log, 'TCGiant_Sync_Inventory::on_status_saved() on woocommerce_after_product_object_save' ), substr_count( $tcg_log, '- Fix round 1 (review 1; the owner\'s decision of 2026-11-20). Disabling a variation queues its 0 at once' ), substr_count( $tcg_md, '| **A variation enabled or disabled** (3.44.0) has its stock, or its 0, queued for eBay as a stock change of it would be' ), substr_count( $tcg_md, '| **A disabled variation keeping its own stock** (3.44.0) gets the same: disabling it, its stock change,' ), substr_count( $tcg_md, 'never 0 while it is the listing\'s only stock and still has stock of its own' ), substr_count( implode( "\n", preg_grep( '/\(3\.44\.0\)/', explode( "\n", $tcg_md ) ) ), 'at once' ), substr_count( $tcg_log, 'its own stock run out, sent 0 as any variation\'s 0 is, by the rules for the last with stock.' ) ), array( 1, 1, 1, 1, 1, 1, 0, 1 ) );
check( 'the 3.43.0 entry left as it was published', substr_count( $tcg_readme, 'Enabled again, the next stock edit, or Send stock to eBay, sends it the shared stock' ), 1 );
check( 'fix round 2: the changelog\'s item; the readme no longer says one leaving the listing keeps its stock whatever it holds, nor the notice "tells"', array( substr_count( $tcg_log, '- Fix round 2 (review 2). LOW 1: TCGiant_Sync_Inventory::queue_after_import() waits only for a push' ), substr_count( $tcg_readme, 'One that leaves the eBay listing keeps its stock and stays disabled.' ), substr_count( (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-inventory.php' ), 'The next import of the listing tells;' ) ), array( 1, 0, 0 ) );

// The staging copy: a child of this file, with TCGIANT_SYNC_IS_STAGING defined.
echo "\nA STAGING COPY (A CHILD RUN, TCGIANT_SYNC_IS_STAGING DEFINED)\n" . str_repeat( '=', 132 ) . "\n";
$child = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --staging 2>&1', $child );
foreach ( $child as $line ) {
	if ( preg_match( '/^\s*(\d+) passed, (\d+) failed/', $line, $m ) ) {
		$pass += (int) $m[1];
		$fail += (int) $m[2];
		printf( "  (child: %d checks passed, %d failed)\n", $m[1], $m[2] );
		continue;
	}
	echo $line, "\n";
}
if ( ! preg_grep( '/passed, \d+ failed/', $child ) ) {
	$fail++;
	echo "  FAIL  the staging child gave no summary\n";
}

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );

staging:
check( 'the child is a staging copy', TCGiant_Sync_Environment::write_block() instanceof WP_Error, true );
polo_at_zero();
wc_set_status( 7103, 'publish' );
$tcg_queued = array( push_args(), enabled_lines() );
ebay_has( '710000007100', polo( 4, 4, 0, array( 'l' => 1 ) ) );
run_queue();
check( 'L enabled on a staging copy: queued as a stock change\'s is; the push\'s own block stops it - no call', array( $tcg_queued, $WIRE, logged( 'Stock change for WC #7100' ) || is_array( get_transient( TCGiant_Sync_Environment::BLOCKED_TRANSIENT ) ) ), array( array( array( array( 7100, 'edit', null ) ), array( $POOL_LINE ) ), array(), true ) );
kit_shop();
b_sent_zero( 1 );
wc_set_status( 7502, 'publish' );
$tcg_queued = push_args();
run_queue();
check( 'B enabled on a staging copy: its push queued, stopped before eBay; its mark kept', array( $tcg_queued, $WIRE, off_mark( 7502 ) ), array( array( array( 7502, 'edit', null ) ), array(), '750000007500' ) );
kit_shop( 3, 5 );
hand_edit( 7502, 4 );
run_queue();
check( 'B\'s stock change on a staging copy: no call, nothing marked', array( $WIRE, off_mark( 7502 ), rec( 7502 ) ), array( array(), null, null ) );
kit_shop( 3, 5, 'publish' );
wc_set_status( 7502, 'private' );
$tcg_queued = array( push_args(), disabled_lines() );
run_queue();
check( 'B disabled on a staging copy (fix round 1): its push queued as a stock change\'s is, one line; stopped before eBay, nothing marked', array( $tcg_queued, $WIRE, off_mark( 7502 ), rec( 7502 ) ), array( array( array( array( 7502, 'edit', null ) ), array( 'Variation #7502 (OS-B) of WC #7500 disabled: its push is queued for eBay listing 750000007500, to set it to 0 there - unless it is the listing\'s only variation with stock.' ) ), array(), null, null ) );
polo_shop( 4, 'publish' );
wc_set_status( 7103, 'private' );
$tcg_queued = push_args();
run_queue();
check( 'L (shared stock) disabled on a staging copy: the parent\'s push queued, stopped before eBay', array( $tcg_queued, $WIRE ), array( array( array( 7100, 'edit', null ) ), array() ) );
printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
