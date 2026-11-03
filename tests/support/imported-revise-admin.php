<?php
/**
 * The eBay tab and its Push button, for test-imported-revise.php (3.28.8):
 * an imported card in a shop with no export defaults saved, pressed and
 * shown; the box that says whose values its pushes send, posted ticked and
 * unticked, from the button and from the product's own save; the readiness
 * line for the shop's default card condition sent as its pair; the lines for
 * a category, condition or policies the plugin has not read; and Link to
 * this listing, by hand.
 * What each got, as JSON. A process of its own: the admin class needs more of
 * the plugin than that harness plays.
 *
 * usage: php tests/support/imported-revise-admin.php
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
$QUEUED = array();
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { $GLOBALS['QUEUED'][] = (int) ( $args['product_id'] ?? 0 ); return 1; }

require dirname( __DIR__, 2 ) . '/admin/class-tcgiant-sync-admin.php';
$admin = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();
$slot  = new ReflectionProperty( 'TCGiant_Sync_Exporter', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$slot->setAccessible( true );
}
$slot->setValue( null, exporter() );

/** Product $id with a photo and these meta. */
function product( $id, array $meta ) {
	new WC_Product( $id, array( 'name' => 'Item ' . $id, 'description' => 'An item.', 'stock' => 1, 'image_id' => 9000 + $id ) );
	$GLOBALS['W']['images'][ 9000 + $id ] = 'https://shop.example/wp-content/uploads/p' . $id . '.jpg';
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
}
/** The Push button pressed, with these posted: answered OK or not, its message, queued. */
function press( $id, array $post = array() ) {
	$GLOBALS['QUEUED'] = array();
	$_POST             = array( 'product_id' => (string) $id ) + $post;
	$answer            = null;
	try {
		$GLOBALS['admin']->ajax_push_product();
	} catch ( Admin_Run_Answer $a ) {
		$answer = $a;
	}
	$_POST = array();
	return array( 'ok' => $answer ? $answer->ok : null, 'message' => $answer ? (string) ( $answer->data['message'] ?? '' ) : '', 'queued' => in_array( (int) $id, $GLOBALS['QUEUED'], true ) );
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
/** Its box: ticked, unticked, or null when there is none. */
function box( $id ) {
	if ( ! preg_match( '#<input type="checkbox" id="_ebay_export_follow_defaults"[^>]*>#', tab( $id ), $m ) ) {
		return null;
	}
	return false !== strpos( $m[0], 'checked' );
}
/** The product's own save, with the box shown and ticked or not, or not shown. */
function save( $id, $shown, $ticked ) {
	$_POST = array();
	if ( $shown ) {
		$_POST['_ebay_export_follow_defaults_shown'] = '1';
	}
	if ( $ticked ) {
		$_POST['_ebay_export_follow_defaults'] = '1';
	}
	$GLOBALS['admin']->save_product_export_meta( $id );
	$_POST = array();
	return (string) get_post_meta( $id, '_ebay_export_follow_defaults', true );
}

// A shop with nothing saved for pushing.
TCGiant_Sync_OAuth::$settings = array( 'marketplace' => 'EBAY_US' );

$live = array(
	'_ebay_item_id'        => '256100000001',
	'_ebay_listing_type'   => 'FixedPriceItem',
	'_ebay_listing_status' => 'Active',
	'_ebay_origin'         => 'import',
	'_ebay_live'           => array(
		'item_id'       => '256100000001',
		'condition_id'  => '2750',
		'descriptors'   => array( array( 'name' => '27501', 'value' => '275010' ), array( 'name' => '27502', 'value' => '275020' ) ),
		'category_id'   => '183454',
		'category_name' => 'Toys & Hobbies:Collectible Card Games:CCG Individual Cards',
		'policies'      => array( 'shipping' => '240000011', 'return' => '240000012', 'payment' => '240000013' ),
		'location'      => 'Torrance, CA',
		'postal_code'   => '90501',
	),
);
product( 801, $live );
$out = array( 'card' => press( 801 ), 'card_lines' => readiness( 801 ), 'card_box' => box( 801 ) );
press( 801, array( 'override_follow_defaults' => '0' ) );
$out['stored_unticked'] = (string) get_post_meta( 801, '_ebay_export_follow_defaults', true );
$out['card_defaults']   = press( 801, array( 'override_follow_defaults' => '1' ) );
$out['stored_ticked']   = (string) get_post_meta( 801, '_ebay_export_follow_defaults', true );

product( 802, array( '_ebay_origin' => 'push' ) + $live );
$out['pushed_box'] = box( 802 );
press( 802, array( 'override_follow_defaults' => '0' ) );
$out['pushed_unticked'] = (string) get_post_meta( 802, '_ebay_export_follow_defaults', true );
press( 802, array( 'override_follow_defaults' => '1' ) );
$out['pushed_ticked'] = (string) get_post_meta( 802, '_ebay_export_follow_defaults', true );

product( 803, $live );
$out['saved_unticked'] = save( 803, true, false );
$out['saved_ticked']   = save( 803, true, true );
$out['saved_absent']   = save( 803, false, false );

product( 804, array() );
$out['new_box'] = box( 804 );

// A new card in 183454, the shop's default card condition Excellent.
TCGiant_Sync_OAuth::$settings = array( 'marketplace' => 'EBAY_US', 'export_condition_type' => 'ungraded', 'export_ungraded_condition' => '400011', 'export_location' => 'Austin, TX', 'export_postal_code' => '78701', 'export_fulfillment_policy' => '1', 'export_return_policy' => '2', 'export_payment_policy' => '3' );
product( 805, array( '_ebay_export_category_id' => '183454', '_ebay_export_item_type' => 'tcg' ) );
foreach ( readiness( 805 ) as $line ) {
	if ( false !== strpos( $line, 'Condition:' ) ) {
		$out['pair_line'] = $line;
	}
}

// An imported card with its own category, whose condition no read has
// recorded: not ready, and refused.
$no_condition = $live;
unset( $no_condition['_ebay_live']['condition_id'], $no_condition['_ebay_live']['descriptors'] );
product( 806, array( '_ebay_export_category_id' => '261328' ) + $no_condition );
$out['pair_lines'] = readiness( 806 );
$out['pair_press'] = press( 806 );
// A whole condition of its own, whose category no read has recorded.
$no_category = $live;
unset( $no_category['_ebay_live']['category_id'], $no_category['_ebay_live']['category_name'], $no_category['_ebay_live']['condition_id'], $no_category['_ebay_live']['descriptors'] );
product( 807, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => '400010' ) + $no_category );
foreach ( readiness( 807 ) as $line ) {
	if ( false !== strpos( $line, 'Category:' ) ) {
		$out['cat_unknown_line'] = $line;
	}
}
$out['cat_unknown_press'] = press( 807 );
// A part (3000) moved to 183454: a pair known, and one eBay refuses.
$part_live                = $live;
$part_live['_ebay_live']  = array( 'item_id' => '256100000001', 'condition_id' => '3000', 'descriptors' => array(), 'category_id' => '33564', 'category_name' => 'eBay Motors:Parts', 'policies' => $live['_ebay_live']['policies'], 'location' => 'Columbus, OH', 'postal_code' => '43215' );
product( 812, array( '_ebay_export_category_id' => '183454' ) + $part_live );
foreach ( readiness( 812 ) as $line ) {
	if ( false !== strpos( $line, 'Condition:' ) ) {
		$out['conflict_line'] = $line;
	}
}
$out['conflict_press'] = press( 812 );
// Its own shipping policy, the other two not read.
$no_policies = $live;
unset( $no_policies['_ebay_live']['policies'] );
product( 808, array( '_ebay_export_fulfillment_policy' => '250000077' ) + $no_policies );
foreach ( readiness( 808 ) as $line ) {
	if ( false !== strpos( $line, 'Business Policies:' ) ) {
		$out['policies_line'] = $line;
	}
}
// The same, read whole by a push (3.28.8): eBay gave it no business policies.
$read_whole                           = $no_policies;
$read_whole['_ebay_live']['policies'] = array();
$read_whole['_ebay_live']['full']     = '1';
product( 811, array( '_ebay_export_fulfillment_policy' => '250000077' ) + $read_whole );
foreach ( readiness( 811 ) as $line ) {
	if ( false !== strpos( $line, 'Business Policies:' ) ) {
		$out['policies_read_line'] = $line;
	}
}
$out['policies_read_press'] = press( 811 );
// Read whole with a shipping business policy only.
$some_policies                           = $read_whole;
$some_policies['_ebay_live']['policies'] = array( 'shipping' => '240000011' );
product( 814, array( '_ebay_export_fulfillment_policy' => '250000077' ) + $some_policies );
foreach ( readiness( 814 ) as $line ) {
	if ( false !== strpos( $line, 'Business Policies:' ) ) {
		$out['policies_some_line'] = $line;
	}
}
// A card condition of its own with no Graded or Ungraded, eBay's not read.
product( 815, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_ungraded_condition' => '400011' ) + $no_condition );
foreach ( readiness( 815 ) as $line ) {
	if ( false !== strpos( $line, 'Condition:' ) ) {
		$out['partial_line'] = $line;
	}
}
// The same with a shipping policy of its own, the others not read either.
$neither = $no_condition;
unset( $neither['_ebay_live']['policies'] );
product( 816, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_ungraded_condition' => '400011', '_ebay_export_fulfillment_policy' => '250000077' ) + $neither );
// The push's read of the listing failed in the last hour (every listing
// here is 256100000001): 815, 816 and 806 again.
set_transient( TCGiant_Sync_Exporter::READ_BACKOFF . '256100000001', 1, 3600 );
foreach ( readiness( 815 ) as $line ) {
	if ( false !== strpos( $line, 'Condition:' ) ) {
		$out['partial_held_line'] = $line;
	}
}
foreach ( readiness( 816 ) as $line ) {
	if ( false !== strpos( $line, 'Condition:' ) ) {
		$out['partial_pol_held_line'] = $line;
	}
	if ( false !== strpos( $line, 'Business Policies:' ) ) {
		$out['partial_pol_held_policies'] = $line;
	}
}
foreach ( readiness( 806 ) as $line ) {
	if ( false !== strpos( $line, 'Condition:' ) ) {
		$out['pair_held_line'] = $line;
	}
}
delete_transient( TCGiant_Sync_Exporter::READ_BACKOFF . '256100000001' );

// Who made its listing not known (round 6): pushed before 3.28.8, no
// start time, no SKU of the import's, nothing kept.
product( 817, array( '_ebay_item_id' => '256100000817', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-06-01 10:00:00' ) );
$untold = function ( $id ) {
	return preg_match( '#<span id="tc-origin-unknown"[^>]*>(.*?)</span>#s', tab( $id ), $m ) ? trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) : false;
};
$out['untold_note'] = $untold( 817 );
$out['untold_box']  = box( 817 );
set_transient( TCGiant_Sync_Exporter::READ_BACKOFF . '256100000817', 1, 3600 );
$out['untold_held_note'] = $untold( 817 );
delete_transient( TCGiant_Sync_Exporter::READ_BACKOFF . '256100000817' );
$out['untold_saved_unticked'] = save( 817, true, false );
$out['untold_saved_ticked']   = save( 817, true, true );
$out['known_note']            = $untold( 801 );

// 3.29.1: the box's state as the tab shows it, posted back beside it, so a
// save or a Push with the box left as it was changes nothing - a bulk choice
// included - and a box changed on a listing not known yet keeps 1 or 0.
$was = function ( $id ) {
	return preg_match( '#<input type="hidden" id="_ebay_export_follow_defaults_was" name="_ebay_export_follow_defaults_was" value="([01])">#', tab( $id ), $m ) ? $m[1] : null;
};
$out['was_card']   = $was( 801 );
$out['was_pushed'] = $was( 802 );
$out['was_new']    = $was( 804 );
product( 819, array( '_ebay_item_id' => '256100000819', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-06-01 10:00:00' ) );
TCGiant_Sync_Admin::set_follow_choice( 819, 'keep' );
$out['bulk_keep_was'] = $was( 819 );
$out['bulk_keep_box'] = box( 819 );
press( 819, array( 'override_follow_defaults' => '0', 'override_follow_defaults_was' => '0' ) );
$out['bulk_keep_pressed'] = (string) get_post_meta( 819, '_ebay_export_follow_defaults', true );
press( 819, array( 'override_follow_defaults' => '1', 'override_follow_defaults_was' => '0' ) );
$out['bulk_keep_ticked'] = (string) get_post_meta( 819, '_ebay_export_follow_defaults', true );
press( 819, array( 'override_follow_defaults' => '0', 'override_follow_defaults_was' => '1' ) );
$out['bulk_keep_unticked'] = (string) get_post_meta( 819, '_ebay_export_follow_defaults', true );
// Drawn ticked, then a bulk Keep from another tab, then Push with the box as drawn: the Keep stays.
product( 820, array( '_ebay_item_id' => '256100000820', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-06-01 10:00:00', '_ebay_export_follow_defaults' => '1' ) );
$drawn = $was( 820 );
TCGiant_Sync_Admin::set_follow_choice( 820, 'keep' );
set_transient( TCGiant_Sync_Ebay_Live::NOTICE_COUNT, 5, 3600 );
press( 820, array( 'override_follow_defaults' => '1', 'override_follow_defaults_was' => $drawn ) );
$out['stale_press']      = array( $drawn, (string) get_post_meta( 820, '_ebay_export_follow_defaults', true ) );
$out['count_kept']       = get_transient( TCGiant_Sync_Ebay_Live::NOTICE_COUNT );
press( 820, array( 'override_follow_defaults' => '1', 'override_follow_defaults_was' => '0' ) );
$out['count_forgotten']  = array( (string) get_post_meta( 820, '_ebay_export_follow_defaults', true ), get_transient( TCGiant_Sync_Ebay_Live::NOTICE_COUNT ) );

/** Link to this listing, by hand: answered OK or not, the origin kept, eBay's category and condition recorded. */
function link_to( $id, $item_id ) {
	$_POST  = array( 'product_id' => (string) $id, 'ebay_item_id' => $item_id );
	$answer = null;
	try {
		$GLOBALS['admin']->ajax_link_ebay();
	} catch ( Admin_Run_Answer $a ) {
		$answer = $a;
	}
	$_POST = array();
	$rec   = get_post_meta( $id, '_ebay_live', true );
	return array( 'ok' => $answer ? $answer->ok : null, 'origin' => (string) get_post_meta( $id, '_ebay_origin', true ), 'live' => is_array( $rec ) ? ( $rec['category_id'] ?? '' ) . '/' . ( $rec['condition_id'] ?? '' ) : '' );
}
/** eBay's GetItem answer for a listing of ours. */
function listing_answer( $item_id, array $extra = array() ) {
	return array( 'Ack' => 'Success', 'Item' => $extra + array( 'ItemID' => $item_id, 'Title' => 'Listing ' . $item_id, 'ListingType' => 'FixedPriceItem', 'Quantity' => '1', 'SellingStatus' => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0' ), 'ConditionID' => '3000', 'PrimaryCategory' => array( 'CategoryID' => '33564', 'CategoryName' => 'eBay Motors:Parts & Accessories' ), 'Site' => 'US' ) );
}
// A product the shop once pushed and unlinked, linked to a listing made on eBay.
product( 809, array( '_ebay_export_pushed_version' => '3.28.6' ) );
$GLOBALS['API']->answers['GetItem'] = listing_answer( '256100000809' );
$out['link_import'] = link_to( 809, '256100000809' );
$out['link_by']     = (string) get_post_meta( 809, '_ebay_origin_by', true );
// A product linked to the listing its own push made: the site has an
// address to make its push mark from.
$GLOBALS['W']['options']['siteurl'] = 'https://shop.example';
product( 810, array() );
$mark               = TCGiant_Sync_API::push_mark( 810 );
$out['has_mark']    = '' !== $mark;
$GLOBALS['API']->answers['GetItem'] = listing_answer( '256100000810', array( 'ApplicationData' => $mark ) );
$out['link_push']   = link_to( 810, '256100000810' );

echo json_encode( $out, JSON_UNESCAPED_UNICODE );
$GLOBALS['harness_done'] = true;
