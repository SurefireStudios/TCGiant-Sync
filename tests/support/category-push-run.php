<?php
/**
 * Press the product's Push button (TCGiant_Sync_Admin::ajax_push_product(),
 * which the products list's Update button posts too) in template-world.php,
 * for products in a category a new listing is refused (3.28.5), with a live
 * listing and without one, and print what each got as JSON - for
 * test-category-ids.php. With the eBay tab's readiness line for an Item Type
 * that does not fit a coin parent. A process of its own: the admin class
 * needs more of the plugin than that harness plays.
 *
 * usage: php tests/support/category-push-run.php
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
class TCGiant_Sync_Mapper {
	public static function __callStatic( $name, $args ) { return ''; }
}
class Push_Run_Answer extends Exception {
	public $ok;
	public $data;
	public function __construct( $ok, $data ) { parent::__construct( 'json' ); $this->ok = $ok; $this->data = $data; }
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
function check_ajax_referer( $action = -1, $arg = false, $die = true ) { return 1; }
function wp_send_json_success( $data = null ) { throw new Push_Run_Answer( true, $data ); }
function wp_send_json_error( $data = null ) { throw new Push_Run_Answer( false, $data ); }
// What a push button queues: recorded, not run.
$QUEUED = array();
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { $GLOBALS['QUEUED'][] = (int) ( $args['product_id'] ?? 0 ); return 1; }

require dirname( __DIR__, 2 ) . '/admin/class-tcgiant-sync-admin.php';
$admin = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();
// The exporter the button asks, with nothing registered.
$slot = new ReflectionProperty( 'TCGiant_Sync_Exporter', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$slot->setAccessible( true );
}
$slot->setValue( null, exporter() );

/** Product $id with these meta and a photo. */
function product( $id, array $meta ) {
	new WC_Product( $id, array( 'name' => 'Item ' . $id, 'description' => 'An item.', 'stock' => 3, 'image_id' => 9000 + $id ) );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/p' . $id . '.jpg';
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
}
/** The Push button pressed for product $id: answered OK or not, its message, queued, the status it leaves. */
function press( $id ) {
	$GLOBALS['QUEUED'] = array();
	$_POST             = array( 'product_id' => (string) $id );
	$answer            = null;
	try {
		$GLOBALS['admin']->ajax_push_product();
	} catch ( Push_Run_Answer $a ) {
		$answer = $a;
	}
	$_POST = array();
	return array(
		'ok'      => $answer ? $answer->ok : null,
		'message' => $answer ? (string) ( $answer->data['message'] ?? '' ) : '',
		'queued'  => in_array( (int) $id, $GLOBALS['QUEUED'], true ),
		'status'  => (string) get_post_meta( $id, '_ebay_export_status', true ),
	);
}
/** The eBay tab's readiness lines for product $id. */
function readiness( $id ) {
	$GLOBALS['post'] = (object) array( 'ID' => $id );
	ob_start();
	$GLOBALS['admin']->render_ebay_listing_panel();
	$html = ob_get_clean();
	preg_match_all( '#<div class="tc-readiness-check"[^>]*>(.*?)</div>#s', $html, $checks );
	return array_map( function ( $c ) { return trim( html_entity_decode( strip_tags( $c ), ENT_QUOTES, 'UTF-8' ) ); }, $checks[1] );
}

$card  = array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '9', '_ebay_export_category_id' => '183456', '_ebay_export_category_name' => 'Toys & Hobbies > Collectible Card Games > CCG Sealed Boxes' );
$coin  = array( '_ebay_export_item_type' => 'coins', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '14', '_ebay_export_grade_value' => 'MS/PR 70', '_ebay_export_category_id' => '253', '_ebay_export_category_name' => '' );
$live  = array( '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' );
$ended = array( '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Ended' );

product( 601, $card + $live + array( '_ebay_item_id' => '110000000601' ) );
product( 602, $coin + $live + array( '_ebay_item_id' => '110000000602' ) );
product( 603, $card );
product( 604, $coin );
product( 605, $card + $ended + array( '_ebay_item_id' => '110000000605' ) );
$out = array(
	'live_card'  => press( 601 ),
	'live_coin'  => press( 602 ),
	'card'       => press( 603 ),
	'coin'       => press( 604 ),
	'ended_card' => press( 605 ),
);

// Item Type Trading Cards in a coin parent: the mismatch is still said.
$tcg_in_coin = array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '9', '_ebay_export_category_id' => '253', '_ebay_export_category_name' => '' );
product( 606, $tcg_in_coin + $live + array( '_ebay_item_id' => '110000000606' ) );
product( 607, $tcg_in_coin );
$out['mismatch_live'] = readiness( 606 )[0] ?? null;
$out['mismatch']      = readiness( 607 )[0] ?? null;
$out['mismatch_push'] = press( 606 );

echo json_encode( $out );
$GLOBALS['harness_done'] = true;
