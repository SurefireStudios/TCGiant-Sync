<?php
/**
 * Render the real product eBay tab (TCGiant_Sync_Admin::render_ebay_listing_panel())
 * and the products list's eBay column, in template-world.php, for products
 * saved with retired and custom category IDs, and print what each shows as
 * JSON - for test-category-ids.php. A process of its own: the tab needs
 * more of the plugin's classes than that harness plays.
 *
 * usage: php tests/support/category-panel-run.php
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/template-world.php';

class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS = array();
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}
// What the tab shows of a linked listing, played: nothing recorded.
class TCGiant_Sync_Mapper {
	public static function __callStatic( $name, $args ) { return ''; }
}
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function selected( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) { echo $out; } return $out; }
function checked( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; }
function wp_create_nonce( $action = -1 ) { return 'nonce'; }
function date_i18n( $format, $time = false ) { return gmdate( $format, $time ? $time : time() ); }
function woocommerce_wp_select( $args ) {}
function woocommerce_wp_text_input( $args ) {}
function get_terms( $args = array() ) { return array(); }

require dirname( __DIR__, 2 ) . '/admin/class-tcgiant-sync-admin.php';
$admin = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();

/** The tab for product $id with these meta: the category select, the retired line, the readiness lines. */
function tab( $id, array $meta ) {
	new WC_Product( $id, array( 'name' => 'Card ' . $id ) );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	$GLOBALS['post'] = (object) array( 'ID' => $id );
	ob_start();
	$GLOBALS['admin']->render_ebay_listing_panel();
	$html = ob_get_clean();

	preg_match( '#<select name="_ebay_export_category_id_select".*?</select>#s', $html, $select );
	preg_match_all( '#<option value="([^"]*)"\s*(selected="selected")?>(.*?)</option>#s', $select[0] ?? '', $options, PREG_SET_ORDER );
	preg_match( '#<input type="text" name="_ebay_export_category_id_custom"[^>]*>#', $html, $custom );
	preg_match( '#<p class="tc-retired-category"[^>]*>(.*?)</p>#s', $html, $line );
	preg_match_all( '#<div class="tc-readiness-check"[^>]*>(.*?)</div>#s', $html, $checks );

	$out = array( 'options' => array(), 'selected' => null );
	foreach ( $options as $o ) {
		$out['options'][ $o[1] ] = trim( html_entity_decode( $o[3], ENT_QUOTES, 'UTF-8' ) );
		if ( ! empty( $o[2] ) ) {
			$out['selected'] = $o[1];
		}
	}
	$out['custom_hidden'] = false !== strpos( $custom[0] ?? '', 'display:none;' );
	$out['line']          = isset( $line[1] ) ? html_entity_decode( $line[1], ENT_QUOTES, 'UTF-8' ) : '';
	$out['readiness']     = array_map( function ( $c ) { return trim( html_entity_decode( strip_tags( $c ), ENT_QUOTES, 'UTF-8' ) ); }, $checks[1] );
	return $out;
}

/** The products list's eBay column for product $id. */
function column( $id ) {
	ob_start();
	$GLOBALS['admin']->render_ebay_column( 'tcgiant_ebay', $id );
	$html = ob_get_clean();
	return preg_match( '#<div class="tc-ebay-cat"[^>]*>(.*?)</div>#s', $html, $m ) ? html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) : '';
}

$graded = array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '9' );
$out    = array(
	'retired'  => tab( 501, $graded + array( '_ebay_export_category_id' => '180006', '_ebay_export_category_name' => '' ) ),
	'browsed'  => tab( 502, $graded + array( '_ebay_export_category_id' => '183446', '_ebay_export_category_name' => 'Toys & Hobbies > Building Toys' ) ),
	'custom'   => tab( 503, $graded + array( '_ebay_export_category_id' => '183456', '_ebay_export_category_name' => 'Toys & Hobbies > Collectible Card Games > CCG Sealed Boxes' ) ),
	'ungraded' => tab( 504, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_category_id' => '183454', '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => '400012' ) ),
	'column'   => array( column( 501 ), column( 502 ) ),
);

// A grader eBay's table takes in CCG Individual Cards only.
$ace              = array( '_ebay_export_grader_id' => '2750119' ) + $graded;
$out['grader']    = tab( 506, $ace + array( '_ebay_export_category_id' => '261328' ) );
$out['grader_ok'] = tab( 507, $ace + array( '_ebay_export_category_id' => '183454' ) );

// The default in Settings saved as a retired ID, the product with none of its own.
TCGiant_Sync_OAuth::$settings['export_category_id'] = '2536';
$out['default'] = tab( 505, $graded );

// A live listing keeps its category; one that has ended is replaced (3.28.1).
$listing             = array( '_ebay_listing_type' => 'FixedPriceItem', '_ebay_export_category_id' => '180006', '_ebay_export_category_name' => '' );
$out['linked']       = tab( 509, $graded + $listing + array( '_ebay_item_id' => '110000000509', '_ebay_listing_status' => 'Active' ) );
$out['linked_ended'] = tab( 510, $graded + $listing + array( '_ebay_item_id' => '110000000510', '_ebay_listing_status' => 'Ended' ) );

// Typed into the custom box: the shop's own (3.28.1).
$out['typed']        = tab( 511, $graded + array( '_ebay_export_category_id' => '183446', '_ebay_export_category_name' => '', '_ebay_export_category_typed' => '183446' ) );
$out['column_typed'] = column( 511 );

// The product save, from the custom box and then from the list.
new WC_Product( 512, array( 'name' => 'Card 512' ) );
$_POST = array( '_ebay_export_category_id_select' => 'custom', '_ebay_export_category_id_custom' => '261068', '_ebay_export_category_name' => '' );
$admin->save_product_export_meta( 512 );
$out['saved_typed'] = array( (string) get_post_meta( 512, '_ebay_export_category_typed', true ) );
$_POST = array( '_ebay_export_category_id_select' => '183454', '_ebay_export_category_name' => '' );
$admin->save_product_export_meta( 512 );
$out['saved_typed'][] = (string) get_post_meta( 512, '_ebay_export_category_typed', true );
$_POST = array();

// A shop on eBay UK: the mapping is eBay US's.
TCGiant_Sync_OAuth::$settings['marketplace'] = 'EBAY_GB';
$out['gb'] = tab( 508, $graded + array( '_ebay_export_category_id' => '180006', '_ebay_export_category_name' => '' ) );

echo json_encode( $out );
$GLOBALS['harness_done'] = true;
