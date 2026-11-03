<?php
/**
 * The admin side of eBay Motors, for test-motors.php (3.28.9): Browse eBay
 * Categories and Suggest Category, the eBay tab's Verify, its readiness lines
 * and its Item Specifics box, for a shop on eBay US. What each gave, as JSON.
 * A process of its own: the admin class needs more of the plugin than that
 * harness plays.
 *
 * usage: php tests/support/motors-admin.php
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/template-world.php';

class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS         = array();
	const AUCTION_FIGURE_KEYS = array();
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
	public static function get_by_product( $id ) { return null; }
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
	public static function complete_unfinished_push( $product ) { return false; }
}
class TCGiant_Sync_Mapper {
	public static function __callStatic( $name, $args ) { return ''; }
}
class Admin_Run_Answer extends Exception {
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
function wp_send_json_success( $data = null ) { throw new Admin_Run_Answer( true, $data ); }
function wp_send_json_error( $data = null ) { throw new Admin_Run_Answer( false, $data ); }
function get_post_field( $field, $id ) { return ''; }
function get_post_status( $id ) { return 'publish'; }
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { return 1; }

require dirname( __DIR__, 2 ) . '/admin/class-tcgiant-sync-admin.php';
require __DIR__ . '/motors-api.php';
$admin = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();
$slot  = new ReflectionProperty( 'TCGiant_Sync_Exporter', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$slot->setAccessible( true );
}
$slot->setValue( null, exporter() );
$API = $GLOBALS['API'];

/** An AJAX handler run with these posted: OK or not, and its data. */
function ajax( $method, array $post ) {
	$_POST  = $post;
	$answer = null;
	try {
		$GLOBALS['admin']->$method();
	} catch ( Admin_Run_Answer $a ) {
		$answer = $a;
	}
	$_POST = array();
	return $answer ? array( $answer->ok, $answer->data ) : array( null, null );
}
/** Product $id with a photo, a Brand and these meta. */
function product( $id, array $meta ) {
	new WC_Product( $id, array( 'name' => 'Item ' . $id, 'description' => 'An item.', 'stock' => 2, 'regular_price' => '85.00', 'image_id' => 9000 + $id, 'attributes' => array( new WC_Product_Attribute( 'Brand', array( 'Triumph' ) ) ) ) );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/p' . $id . '.jpg';
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
}
/** The eBay tab for product $id. */
function tab( $id ) {
	$GLOBALS['post'] = (object) array( 'ID' => $id );
	ob_start();
	$GLOBALS['admin']->render_ebay_listing_panel();
	return ob_get_clean();
}
/** Its readiness title and lines. */
function readiness( $id ) {
	preg_match_all( '#<div class="tc-readiness-(?:title|check)"[^>]*>(.*?)</div>#s', tab( $id ), $lines );
	return array_map( function ( $l ) { return trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $l ), ENT_QUOTES, 'UTF-8' ) ) ); }, $lines[1] );
}
/** What was learnt, by its keys. */
function learnt() {
	return array_keys( (array) get_option( TCGiant_Sync_Motors::OPTION, array() ) );
}

$shop = array( 'marketplace' => 'EBAY_US', 'export_condition_id' => '1000', 'export_location' => 'Leeds, AL', 'export_postal_code' => '35094', 'export_fulfillment_policy' => '1', 'export_return_policy' => '2', 'export_payment_policy' => '3' );
TCGiant_Sync_OAuth::$settings = $shop;
$out = array();

// Browse eBay Categories.
$out['root'] = ajax( 'ajax_browse_categories', array( 'parent_id' => '' ) )[1]['categories'] ?? null;
TCGiant_Sync_OAuth::$settings = array( 'marketplace' => 'EBAY_GB' ) + $shop;
$out['root_uk'] = ajax( 'ajax_browse_categories', array( 'parent_id' => '' ) )[1]['categories'] ?? null;
TCGiant_Sync_OAuth::$settings = $shop;
$API->children['6028'] = array( array( 'id' => '10063', 'name' => 'Motorcycle & Scooter Parts', 'leaf' => false ), array( 'id' => '33559', 'name' => 'Brakes', 'leaf' => false ) );
$API->clear();
$out['open']      = ajax( 'ajax_browse_categories', array( 'parent_id' => '6028', 'tree' => '100' ) )[1]['categories'] ?? null;
$out['open_rest'] = $API->rest;
$out['learnt']    = learnt();
$API->clear();
$outside             = ajax( 'ajax_browse_categories', array( 'parent_id' => '6024', 'tree' => '100' ) );
$out['outside']      = array( $outside[0], (string) ( $outside[1]['message'] ?? '' ) );
$out['outside_rest'] = $API->rest;

// Suggest Category.
delete_option( TCGiant_Sync_Motors::OPTION );
$API->suggest0['Triumph rocker cover'] = array( array( 'id' => '183454', 'name' => 'Toys & Hobbies > CCG Individual Cards' ) );
$API->suggest['Triumph rocker cover']  = array(
	array( 'id' => '6472', 'name' => 'eBay Motors > Motorcycles > Triumph', 'ancestors' => array( '6024', '6000' ) ),
	array( 'id' => '35560', 'name' => 'Parts & Accessories > Motorcycle & Scooter Parts > Vintage Motorcycle Parts', 'ancestors' => array( '10063', '6028' ) ),
);
$out['suggest']        = ajax( 'ajax_suggest_category', array( 'title' => 'Triumph rocker cover' ) )[1]['suggestions'] ?? null;
$out['suggest_learnt'] = learnt();

// A part in 35560, learnt from the suggestion: Verify, the tab, its Item Specifics.
$API->tree_aspects['100']['35560'] = array( array( 'name' => 'Brand', 'required' => true ) );
$API->conds['35560']               = motors_conditions( '35560', array( '1000' => 'New', '3000' => 'Used' ) );
product( 601, array( '_ebay_export_category_id' => '35560', '_ebay_export_category_name' => 'Vintage Motorcycle Parts', '_ebay_export_item_type' => 'other' ) );
$API->clear();
$verify        = ajax( 'ajax_verify_product', array( 'product_id' => '601' ) );
$out['verify'] = array( 'ok' => $verify[0], 'message' => $verify[1]['message'] ?? null, 'sites' => $API->sites );
$out['ready']  = readiness( 601 );
$out['label']  = false !== strpos( tab( 601 ), '&#10004; Vintage Motorcycle Parts (35560) - eBay Motors' );
$API->clear();
$aspects             = ajax( 'ajax_category_aspects', array( 'product_id' => '601' ) );
$out['aspects']      = array( 'status' => $aspects[1]['status'] ?? null );
$out['aspects_rest'] = array_values( array_unique( $API->rest ) );

// Its Item Specifics box asked about a category on the screen and not saved
// yet: a Motors one over a saved ordinary one, and the other way round.
product( 603, array( '_ebay_export_category_id' => '183454', '_ebay_export_item_type' => 'other' ) );
$API->clear();
ajax( 'ajax_category_aspects', array( 'product_id' => '603', 'override_category_id' => '35560' ) );
$out['screen_motors'] = array_values( array_unique( $API->rest ) );
$API->clear();
ajax( 'ajax_category_aspects', array( 'product_id' => '601', 'override_category_id' => '183454' ) );
$out['screen_us'] = array_values( array_unique( $API->rest ) );
// A shop on eBay UK with the same category: its own tree, whatever was learnt.
TCGiant_Sync_OAuth::$settings = array( 'marketplace' => 'EBAY_GB' ) + $shop;
$API->clear();
ajax( 'ajax_category_aspects', array( 'product_id' => '601' ) );
$out['uk_aspects']            = array_values( array_unique( $API->rest ) );
TCGiant_Sync_OAuth::$settings = $shop;

// A whole motorcycle's category, learnt as one (its name decides nothing).
TCGiant_Sync_Motors::learn( array( '6473' => array( TCGiant_Sync_Motors::BRANCH_VEHICLES, '6001' ) ) );
product( 602, array( '_ebay_export_category_id' => '6473', '_ebay_export_category_name' => 'Ford', '_ebay_export_item_type' => 'other' ) );
$out['vehicle'] = readiness( 602 );
// The plugin's own 183454 with an old eBay Motors path left on the product.
product( 604, array( '_ebay_export_category_id' => '183454', '_ebay_export_category_name' => 'eBay Motors > Parts & Accessories > Motorcycle & Scooter Parts > Vintage Motorcycle Parts', '_ebay_export_item_type' => 'other' ) );
$out['stale_name'] = readiness( 604 );
// A category's name is kept only with its own ID.
$tc_path          = 'eBay Motors > Parts & Accessories > Motorcycle & Scooter Parts > Vintage Motorcycle Parts';
$out['name_keep'] = array(
	TCGiant_Sync_Admin::category_name_to_keep( '35560', '11450', $tc_path, null ),
	TCGiant_Sync_Admin::category_name_to_keep( '35560', '11450', $tc_path, $tc_path ),
	TCGiant_Sync_Admin::category_name_to_keep( '35560', '11450', $tc_path, 'Clothing > Shirts' ),
	TCGiant_Sync_Admin::category_name_to_keep( '35560', '35560', $tc_path, null ),
	TCGiant_Sync_Admin::category_name_to_keep( '35560', '', $tc_path, 'Clothing > Shirts' ),
);
product( 605, array( '_ebay_export_category_id' => '35560', '_ebay_export_category_name' => $tc_path, '_ebay_export_item_type' => 'other' ) );
$API->clear();
ajax( 'ajax_verify_product', array( 'product_id' => '605', 'override_category_id' => '183454' ) );
$out['verify_name'] = array( get_post_meta( 605, '_ebay_export_category_id', true ), get_post_meta( 605, '_ebay_export_category_name', true ) );
$tc_tab          = tab( 601 );
$out['tab_js']   = array(
	false !== strpos( $tc_tab, "\$('#_ebay_export_category_id_select').on('change',function(){\$('#_ebay_export_category_id_custom').toggle(\$(this).val()==='custom');\$('#_ebay_export_category_name').val('');" ),
	false !== strpos( $tc_tab, "\$('#_ebay_export_category_id_custom').on('input',function(){\$('#_ebay_export_category_name').val('');" ),
	substr_count( $tc_tab, "override_category_name:\$('#_ebay_export_category_name').val()||''" ),
);

// The Suggest Category button's title (3.29.2): the name as
// TCGiant_Sync_Admin::suggest_title_js() writes it, never esc_js() inside
// quotes, and nothing of a script in the name raw on the page.
$tc_name = 'Pokemon & Yu-Gi-Oh! <script>alert(1)</script> 6" Lot';
new WC_Product( 607, array( 'name' => $tc_name, 'description' => 'An item.', 'stock' => 2, 'regular_price' => '5.00', 'image_id' => 9607 ) );
$GLOBALS['W']['images'][9607] = 'https://shop.example/wp-content/uploads/p607.jpg';
$tc_tab            = tab( 607 );
$out['suggest_js'] = array(
	false !== strpos( $tc_tab, 'title=' . TCGiant_Sync_Admin::suggest_title_js( $tc_name ) . ';if(!title)return;' ),
	false !== strpos( $tc_tab, "title='" ),
	false !== stripos( $tc_tab, '<script>alert(1)' ),
);

// An ordinary product whose shipping policy is for motor vehicles only
// (3.29.2): the tab says so in the push's words, and Verify sends nothing.
update_option( 'tcgiant_export_policies_fulfillment', array( array( 'id' => '1', 'name' => 'Truck freight', 'category_types' => array( 'MOTORS_VEHICLES' ) ) ) );
product( 606, array( '_ebay_export_category_id' => '183454', '_ebay_export_item_type' => 'other' ) );
$out['vehicle_policy'] = readiness( 606 );
$API->clear();
$tc_verify                    = ajax( 'ajax_verify_product', array( 'product_id' => '606' ) );
$out['vehicle_policy_verify'] = array( $tc_verify[0], (string) ( $tc_verify[1]['message'] ?? '' ), $API->sites );
update_option( 'tcgiant_export_policies_fulfillment', array( array( 'id' => '1', 'name' => 'Parcel', 'category_types' => array( 'ALL_EXCLUDING_MOTORS_VEHICLES' ) ) ) );
$out['vehicle_policy_not'] = readiness( 606 );
delete_option( 'tcgiant_export_policies_fulfillment' );

// Check with eBay, from a listing template's preview: as Verify.
require dirname( __DIR__, 2 ) . '/includes/class-tcgiant-sync-template-admin.php';
$API->clear();
$_POST = array( 'product_id' => '601', 'template_id' => '0' );
try {
	( new ReflectionClass( 'TCGiant_Sync_Template_Admin' ) )->newInstanceWithoutConstructor()->ajax_check_ebay();
} catch ( Admin_Run_Answer $a ) {
	$out['template_check'] = array( $a->ok, $API->sites );
}
$_POST = array();

echo json_encode( $out );
$GLOBALS['harness_done'] = true;
