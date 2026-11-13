<?php
/**
 * Harness: bulk edit of eBay fields (3.39.0), in WooCommerce's own Bulk Edit.
 *
 * An "eBay (TCGiant Sync)" group at the end of WooCommerce's Bulk Edit fields
 * (woocommerce_product_bulk_edit_end), saved for each product once
 * WooCommerce has saved it (woocommerce_product_bulk_edit_save), through the
 * eBay tab's own save.
 *
 * The request is WooCommerce's bulk edit as WordPress sends it: the products
 * list's form, by GET, slashed - WordPress's own fields, then WooCommerce's
 * from includes/admin/views/html-bulk-edit-product.php in its order
 * (change_regular_price ... _sold_individually), then the group's, drawn
 * here and read off the page in its order, then woocommerce_bulk_edit and
 * woocommerce_quick_edit_nonce. It runs as wp-admin/edit.php runs it:
 * bulk_edit_posts() checks edit_post for each product and saves it
 * (wp_update_post(), whose save_post runs WooCommerce's
 * WC_Admin_Post_Types::bulk_and_quick_edit_save_post(): its nonce and
 * edit_post, then bulk_edit_save(): $product->save(), then
 * woocommerce_product_bulk_edit_save), then fires bulk_edit_posts.
 *
 * Loaded for real: TCGiant_Sync_Bulk_Edit, TCGiant_Sync_Admin (the eBay tab's
 * own save), TCGiant_Sync_Ebay_Price, TCGiant_Sync_Identifiers,
 * TCGiant_Sync_Listing_Options, TCGiant_Sync_Catalog, the exporter and the
 * API class (eBay played, every call counted), on support/template-world.php
 * with WordPress's own hooks (tests/wordpress/plugin.php).
 *
 * Proved:
 *  - the group: drawn at the end of WooCommerce's fields, every field at
 *    No change, Set to and Clear for text and prices, each choice and "Use
 *    the shop's setting" for a select; the fee line naming no amount;
 *  - the group left alone changes no meta of any product, byte for byte,
 *    writes no log line and keeps no notice; nor does WooCommerce's quick
 *    edit, nor a bulk edit whose nonce WooCommerce would not take;
 *  - every field's No change, Set and Clear (or the shop's setting), each
 *    stored exactly as the eBay tab's own save stores the same value;
 *  - the skip rules, and a value no product could take;
 *  - a variable product;
 *  - an auction's prices and duration with the format set in the same edit
 *    (the fields apply in their order: the format first);
 *  - the nonce, edit_products and edit_post for each product;
 *  - the notice's counts, once, on the products list; the log's one line;
 *  - nothing reaches eBay, Action Scheduler or WP-Cron.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

// What the admin needs of the plugin's other classes, played (as
// test-template-verify.php plays them).
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
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}

// WordPress, as far as these paths read it.
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $text ) { return trim( strip_tags( (string) $text ) ); }
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = null ) { return esc_attr( $text ); }
}
/** Nonces: a good one is "nonce-" and its action. */
function wp_verify_nonce( $nonce, $action = -1 ) { return 'nonce-' . $action === (string) $nonce ? 1 : false; }
function wp_create_nonce( $action = -1 ) { return 'nonce-' . $action; }
$GLOBALS['SCREEN'] = 'edit-product';
function get_current_screen() { return null === $GLOBALS['SCREEN'] ? null : (object) array( 'id' => $GLOBALS['SCREEN'] ); }

// eBay, the push queue and WP-Cron, played: anything that reached them is counted.
$GLOBALS['OUT'] = array();
function wp_remote_request( $url, $args = array() ) { $GLOBALS['OUT'][] = 'http ' . $url; return new WP_Error( 'harness', 'no network' ); }
function wp_remote_post( $url, $args = array() ) { $GLOBALS['OUT'][] = 'http ' . $url; return new WP_Error( 'harness', 'no network' ); }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['OUT'][] = 'http ' . $url; return new WP_Error( 'harness', 'no network' ); }
function wp_safe_remote_post( $url, $args = array() ) { $GLOBALS['OUT'][] = 'http ' . $url; return new WP_Error( 'harness', 'no network' ); }
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { $GLOBALS['OUT'][] = 'queue ' . $hook; return 1; }
function as_schedule_single_action( $time, $hook, $args = array(), $group = '' ) { $GLOBALS['OUT'][] = 'queue ' . $hook; return 1; }
function wp_schedule_single_event( $time, $hook, $args = array() ) { $GLOBALS['OUT'][] = 'cron ' . $hook; return true; }
function wp_next_scheduled( $hook, $args = array() ) { return false; }

require dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php';
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-bulk-edit.php';

$API = $GLOBALS['API'];
const BE = 'TCGiant_Sync_Bulk_Edit';
const LO = 'TCGiant_Sync_Listing_Options';

// The eBay tab's saves, hooked as the plugin hooks them, and the group's.
$tcg_admin = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();
add_action( 'woocommerce_process_product_meta', array( $tcg_admin, 'save_product_export_meta' ) );
TCGiant_Sync_Ebay_Price::register_admin();
TCGiant_Sync_Identifiers::register_admin();
TCGiant_Sync_Listing_Options::register_admin();
TCGiant_Sync_Bulk_Edit::register_admin();

// The shop: a default category and format, its shipping policies fetched.
TCGiant_Sync_OAuth::$settings['export_category_id']    = '261328';
TCGiant_Sync_OAuth::$settings['export_listing_type']   = 'FixedPriceItem';
TCGiant_Sync_OAuth::$settings['export_condition_type'] = 'ungraded';
update_option( 'tcgiant_export_policies_fulfillment', array( array( 'id' => 'FP1', 'name' => 'Standard' ), array( 'id' => 'FP2', 'name' => 'Free postage' ) ) );

/** A simple product with these meta, or a variable one. */
function product( $id, array $meta = array(), $variable = false ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Item ' . $id ) );
	$product = $variable ? new WC_Product_Variable( $id, array( 'type' => 'variable', 'name' => 'Item ' . $id ) ) : new WC_Product( $id, array( 'name' => 'Item ' . $id ) );
	foreach ( $meta as $key => $value ) {
		$GLOBALS['W']['meta'][ $id ][ $key ] = $value;
	}
	return $product;
}
/** Every eBay meta a product could hold, filled. */
function full_meta() {
	return array(
		'_ebay_item_id'                    => '110000000777',
		'_ebay_listing_type'               => 'FixedPriceItem',
		'_ebay_export_item_type'           => 'tcg',
		'_ebay_export_category_id'         => '183454',
		'_ebay_export_category_typed'      => '',
		'_ebay_export_category_name'       => '',
		'_ebay_export_condition_type'      => 'graded',
		'_ebay_export_grader_id'           => '275010',
		'_ebay_export_grade_value'         => '10',
		'_ebay_export_cert_number'         => '12345678',
		'_ebay_export_condition_id'        => '',
		'_ebay_export_listing_type'        => 'FixedPriceItem',
		'_ebay_export_listing_duration'    => 'GTC',
		'_ebay_export_fulfillment_policy'  => 'FP1',
		'_ebay_export_price'               => '19.99',
		'_ebay_export_identifier'          => '012345678905',
		'_ebay_export_identifier_none'     => '1',
		'_ebay_export_subtitle'            => 'Old subtitle',
		'_ebay_export_condition_note'      => "Old note.\nTwo lines.",
		'_ebay_export_second_category'     => '183050',
		'_ebay_export_second_category_name' => 'Non-Sport Trading Card Singles (183050)',
		'_ebay_export_brand'               => 'Old brand',
		'_ebay_export_mpn'                 => 'OLD-1',
		'_ebay_export_specifics'           => array( 'Game' => 'Pokémon TCG' ),
		'_tcgiant_tpl_override'            => '5',
		'_tcgiant_content_hash'            => '3:abc',
		'_ebay_export_options_relist_refused' => 'x>1>1',
	);
}
/** Every meta of the shop, as a string, to compare byte for byte. */
function meta_bytes() {
	return serialize( $GLOBALS['W']['meta'] );
}
/** One product's meta. */
function meta_of( $id ) {
	$m = $GLOBALS['W']['meta'][ $id ] ?? array();
	ksort( $m );
	return $m;
}
/** One meta of a product, or null when it has none. */
function m( $id, $key ) {
	return $GLOBALS['W']['meta'][ $id ][ $key ] ?? null;
}

/** The group as WooCommerce's Bulk Edit draws it (woocommerce_product_bulk_edit_end). */
function panel() {
	ob_start();
	do_action( 'woocommerce_product_bulk_edit_end' );
	return (string) ob_get_clean();
}
/**
 * The group's own names, in the order it draws them, as a user who may edit
 * products is shown them: a request for one who may not carries them too.
 */
function panel_names() {
	static $names = null;
	if ( null === $names ) {
		$caps = $GLOBALS['W']['caps'] ?? array();
		unset( $GLOBALS['W']['caps']['edit_products'] );
		preg_match_all( '#name="(tcgiant_bulk_[a-z_]+)"#', panel(), $m );
		$GLOBALS['W']['caps'] = $caps;
		$names                = $m[1];
	}
	return $names;
}

/**
 * WooCommerce's bulk edit request, as WordPress has it: WordPress's own
 * fields; WooCommerce's (html-bulk-edit-product.php, in order) at No
 * change unless $wc says; the group's, in the order it draws them, each ''
 * unless $ebay says (field => choice, field_value => typed); then
 * WooCommerce's marker and nonce. Slashed, as WordPress slashes $_GET.
 */
function bulk_request( array $ids, array $ebay, array $wc = array(), array $tail = array() ) {
	$form = array(
		's'                => '',
		'post_status'      => 'all',
		'post_type'        => 'product',
		'_wpnonce'         => 'nonce-bulk-posts',
		'_wp_http_referer' => '/wp-admin/edit.php?post_type=product',
		'action'           => 'edit',
		'product_cat'      => '',
		'product_type'     => '',
		'stock_status'     => '',
		'paged'            => '1',
		'post'             => array_map( 'strval', $ids ),
		'tax_input'        => array( 'product_tag' => '' ),
		'post_author'      => '-1',
		'comment_status'   => '',
		'_status'          => '',
	);
	foreach ( array( 'change_regular_price', '_regular_price', 'change_sale_price', '_sale_price', '_tax_status', '_tax_class', 'change_weight', '_weight', 'change_dimensions', '_length', '_width', '_height', '_shipping_class', '_visibility', '_featured', '_stock_status', '_manage_stock', 'change_stock', '_stock', '_backorders', '_sold_individually' ) as $name ) {
		$form[ $name ] = $wc[ $name ] ?? '';
	}
	foreach ( panel_names() as $name ) {
		$field         = substr( $name, strlen( 'tcgiant_bulk_' ) );
		$form[ $name ] = (string) ( $ebay[ $field ] ?? '' );
	}
	$form = array_merge( $form, array(
		'woocommerce_bulk_edit'        => '1',
		'woocommerce_quick_edit_nonce' => 'nonce-woocommerce_quick_edit_nonce',
		'bulk_edit'                    => 'Update',
		'post_view'                    => 'list',
		'screen'                       => 'edit-product',
		'action2'                      => '-1',
	), $tail );
	$_GET     = wp_slash( $form );
	$_REQUEST = $_GET;
	return $form;
}

/**
 * WordPress's bulk edit of these products, as wp-admin/edit.php runs it for
 * the request in $_REQUEST: bulk_edit_posts() - edit_post, unless WordPress
 * itself skips it ($GLOBALS['WP_SKIP']); wp_update_post(), whose save_post runs
 * WooCommerce's bulk_and_quick_edit_save_post() - its nonce and edit_post,
 * then quick_edit_save() or bulk_edit_save(), which saves the product and
 * fires woocommerce_product_bulk_edit_save - then bulk_edit_posts' own
 * action. Each product's edit_post may be refused for the group alone
 * ($GLOBALS['GROUP_DENY']): WordPress and WooCommerce let it through, and
 * only the group's own check stands.
 */
$GLOBALS['WP_SKIP']    = array();
$GLOBALS['GROUP_DENY'] = array();
function run_bulk( array $ids ) {
	$updated = array();
	foreach ( $ids as $id ) {
		if ( in_array( $id, $GLOBALS['WP_SKIP'], true ) ) {
			continue;
		}
		$product = wc_get_product( $id );
		// wp_update_post(): the post's date moves, as it does.
		$GLOBALS['W']['posts'][ $id ]->post_modified = gmdate( 'Y-m-d H:i:s' );
		$request = $_REQUEST;
		if ( ! isset( $request['woocommerce_quick_edit_nonce'] ) || ! wp_verify_nonce( $request['woocommerce_quick_edit_nonce'], 'woocommerce_quick_edit_nonce' ) ) {
			$updated[] = $id;
			continue;
		}
		if ( ! empty( $request['woocommerce_quick_edit'] ) ) {
			do_action( 'woocommerce_product_quick_edit_save', $product );
		} else {
			$product->save();
			$deny = in_array( $id, $GLOBALS['GROUP_DENY'], true );
			if ( $deny ) {
				$GLOBALS['W']['caps']['edit_post'] = false;
			}
			do_action( 'woocommerce_product_bulk_edit_save', $product );
			unset( $GLOBALS['W']['caps']['edit_post'] );
		}
		$updated[] = $id;
	}
	do_action( 'bulk_edit_posts', $updated, array() );
	return $updated;
}
/** A bulk edit of these products with the group as $ebay asks: the request, then the run. */
function bulk( array $ids, array $ebay, array $wc = array(), array $tail = array() ) {
	bulk_request( $ids, $ebay, $wc, $tail );
	$done     = run_bulk( $ids );
	$_GET     = array();
	$_REQUEST = array();
	return $done;
}
/** The notice on a screen, its text. */
function notice( $screen = 'edit-product' ) {
	$GLOBALS['SCREEN'] = $screen;
	ob_start();
	TCGiant_Sync_Bulk_Edit::bulk_edit_notice();
	$GLOBALS['SCREEN'] = 'edit-product';
	return html_entity_decode( strip_tags( str_replace( '</li>', ' | ', (string) ob_get_clean() ) ), ENT_QUOTES );
}
/** The notice's record for the user. */
function said() {
	return get_transient( TCGiant_Sync_Bulk_Edit::SAID . get_current_user_id() );
}
/** The log's lines of a bulk edit. */
function bulk_lines() {
	$out = array();
	foreach ( $GLOBALS['W']['log'] as $row ) {
		if ( 0 === strpos( $row[1], 'Bulk edit of eBay fields' ) ) {
			$out[] = $row[1];
		}
	}
	return $out;
}
/** A fresh run: no log, no record, nothing sent. */
function fresh() {
	$GLOBALS['W']['log'] = array();
	delete_transient( TCGiant_Sync_Bulk_Edit::SAID . get_current_user_id() );
	$GLOBALS['OUT']        = array();
	$GLOBALS['API']->calls   = array();
	$GLOBALS['API']->lookups = array();
	$GLOBALS['WP_SKIP']    = array();
	$GLOBALS['GROUP_DENY'] = array();
	$GLOBALS['W']['caps']  = array();
}
/** Whether anything reached eBay, Action Scheduler or WP-Cron. */
function sent() {
	return array_merge( $GLOBALS['OUT'], array_column( $GLOBALS['API']->calls, 0 ), array_column( $GLOBALS['API']->lookups, 0 ) );
}

/**
 * The eBay tab's save of a product, as its edit screen posts it: every field
 * of the tab, as the tab draws them for what the product holds, with
 * $changes over them; through woocommerce_process_product_meta.
 */
function tab_form( $id, array $changes = array() ) {
	$g    = function ( $k ) use ( $id ) { $v = m( $id, $k ); return null === $v ? '' : (string) $v; };
	$cat  = $g( '_ebay_export_category_id' );
	$list = TCGiant_Sync_Bulk_Edit::categories();
	$type = $g( '_ebay_export_item_type' );
	$form = array(
		'_ebay_export_item_type'               => $type,
		'_ebay_export_category_id_select'      => '' === $cat ? '' : ( isset( $list[ $cat ] ) ? $cat : 'custom' ),
		'_ebay_export_category_id_custom'      => isset( $list[ $cat ] ) ? '' : $cat,
		'_ebay_export_category_name'           => $g( '_ebay_export_category_name' ),
		'_ebay_export_condition_type'          => $g( '_ebay_export_condition_type' ),
		'_ebay_export_grader_id_tcg'           => 'tcg' === $type ? $g( '_ebay_export_grader_id' ) : '',
		'_ebay_export_grade_value_tcg'         => 'tcg' === $type ? $g( '_ebay_export_grade_value' ) : '',
		'_ebay_export_grader_id_coins'         => 'coins' === $type ? $g( '_ebay_export_grader_id' ) : '',
		'_ebay_export_grade_value_coins'       => 'coins' === $type ? $g( '_ebay_export_grade_value' ) : '',
		'_ebay_export_cert_number'             => $g( '_ebay_export_cert_number' ),
		'_ebay_export_coin_year'               => $g( '_ebay_export_coin_year' ),
		'_ebay_export_ungraded_condition_tcg'  => 'tcg' === $type ? $g( '_ebay_export_ungraded_condition' ) : '',
		'_ebay_export_ungraded_condition_coins' => 'coins' === $type ? $g( '_ebay_export_ungraded_condition' ) : '',
		'_ebay_export_condition_id'            => $g( '_ebay_export_condition_id' ),
		'_ebay_export_listing_type'            => $g( '_ebay_export_listing_type' ),
		'_ebay_export_listing_duration'        => $g( '_ebay_export_listing_duration' ),
		'_ebay_export_fulfillment_policy'      => $g( '_ebay_export_fulfillment_policy' ),
		'_ebay_export_price_shown'             => '1',
		'_ebay_export_price'                   => $g( '_ebay_export_price' ),
		'_ebay_export_identifier_shown'        => '1',
		'_ebay_export_identifier'              => $g( '_ebay_export_identifier' ),
		'_ebay_export_identifier_type'         => $g( '_ebay_export_identifier_type' ),
		'_ebay_export_subtitle_shown'          => '1',
	);
	if ( '1' === $g( '_ebay_export_identifier_none' ) ) {
		$form['_ebay_export_identifier_none'] = '1';
	}
	foreach ( TCGiant_Sync_Listing_Options::METAS as $meta ) {
		$form[ $meta ] = $g( $meta );
	}
	$form['_ebay_export_second_category_name'] = $g( '_ebay_export_second_category_name' );
	foreach ( $changes as $k => $v ) {
		if ( null === $v ) {
			unset( $form[ $k ] );
		} else {
			$form[ $k ] = $v;
		}
	}
	return $form;
}
function tab_save( $id, array $changes = array() ) {
	$_POST = wp_slash( tab_form( $id, $changes ) );
	do_action( 'woocommerce_process_product_meta', $id );
	$_POST = array();
}

fresh();

// tools/check-bulk-edit-js.js runs the group's script in jsdom: it asks for
// the group as drawn here (php tests/test-bulk-edit.php --panel).
if ( in_array( '--panel', (array) ( $GLOBALS['argv'] ?? array() ), true ) ) {
	echo panel();
	$GLOBALS['harness_done'] = true;
	exit( 0 );
}

// -----------------------------------------------------------------------------
echo "\n== The group, in WooCommerce's Bulk Edit\n";

$page = panel();
check( 'drawn at woocommerce_product_bulk_edit_end, headed eBay (TCGiant Sync)', false !== strpos( $page, '<h4>eBay (TCGiant Sync)</h4>' ), true );
$names = panel_names();
$want  = array();
foreach ( TCGiant_Sync_Bulk_Edit::fields() as $field => $def ) {
	$want[] = 'tcgiant_bulk_' . $field;
	if ( in_array( $def['kind'], array( 'category', 'second', 'note', 'text', 'price' ), true ) ) {
		$want[] = 'tcgiant_bulk_' . $field . '_value';
	}
}
check( 'every field, in its order: a choice, and a box beside a text, price or category', $names, $want );
check( 'the fields: steps 1 to 4, eBay price, the identifier\'s "does not apply", the seven 3.38.0 fields', array_keys( TCGiant_Sync_Bulk_Edit::fields() ), array( 'item_type', 'category', 'condition_type', 'grader', 'condition_id', 'listing_type', 'listing_duration', 'fulfillment', 'ebay_price', 'identifier_none', 'subtitle', 'condition_note', 'second_category', 'buy_it_now', 'reserve', 'brand', 'mpn' ) );
preg_match_all( '#<select class="tcgiant-bulk-change" name="([a-z_]+)">\s*<option value="">([^<]*)</option>#', $page, $first );
check( 'every select starts at "— No change —", the value ""', array( count( $first[1] ), array_unique( $first[2] ) ), array( 17, array( '— No change —' ) ) );
preg_match_all( '#<select class="tcgiant-bulk-change" name="tcgiant_bulk_([a-z_]+)">(.*?)</select>#s', $page, $sel, PREG_SET_ORDER );
$opts = array();
foreach ( $sel as $s ) {
	preg_match_all( '#<option value="([^"]*)">#', $s[2], $o );
	$opts[ $s[1] ] = $o[1];
}
check( 'a select: each choice, then the clear of the product\'s own', array( $opts['item_type'], $opts['condition_type'], $opts['listing_type'], end( $opts['grader'] ) ), array( array( '', 'tcg', 'coins', 'other', '_shop' ), array( '', 'graded', 'ungraded', '_shop' ), array( '', 'FixedPriceItem', 'Chinese', '_shop' ), '_shop' ) );
check( 'a text or price: Set to, Clear', array( $opts['subtitle'], $opts['ebay_price'], $opts['buy_it_now'], $opts['condition_note'] ), array_fill( 0, 4, array( '', 'set', 'clear' ) ) );
check( 'the categories: the shop\'s list, a typed ID, the clear of the product\'s own; the second: Clear', array( array_slice( $opts['category'], -2 ), array_slice( $opts['second_category'], -2 ), in_array( '183454', $opts['category'], true ), in_array( '183454', $opts['second_category'], true ) ), array( array( 'custom', '_shop' ), array( 'custom', 'clear' ), true, true ) );
check( 'the shipping policies fetched; the conditions without the media-only four; the graders of both, grouped', array( $opts['fulfillment'], in_array( '4000', $opts['condition_id'], true ), in_array( '3000', $opts['condition_id'], true ), substr_count( $page, '<optgroup label="Trading Cards">' ) + substr_count( $page, '<optgroup label="Coins">' ) ), array( array( '', 'FP1', 'FP2', '_shop' ), false, true, 2 ) );
check( 'the tick: ticked or unticked', $opts['identifier_none'], array( '', 'yes', 'no' ) );
check( 'the fee line: subtitle, second category, reserve, charged for each listing, no amount named', 1 === preg_match( '#<p class="tcgiant-bulk-fees"[^>]*>([^<]*)</p>#', $page, $fee ) && false !== strpos( $fee[1], 'a subtitle, a second category (a second insertion fee, and the listing&#039;s upgrade fees again) and a reserve price' ) && false !== strpos( $fee[1], 'charges it for each of them' ) && 0 === preg_match( '/[0-9$£€%]/', html_entity_decode( $fee[1], ENT_QUOTES ) ), true );
// Fix round 1, M2: the renewal, and new listings and relists.
$fee_words = html_entity_decode( $fee[1] ?? '', ENT_QUOTES );
// Fix round 2 (review 2's L-5): when each is charged - a subtitle or second
// category at a fixed-price Update, a relist or a new listing; a reserve
// when an auction is listed or relisted, never at an Update.
check( '  a Good \'Til Cancelled listing\'s subtitle paid again at each monthly renewal on some sites; a subtitle or second category at a fixed-price Update, a relist or a new listing; a reserve never at an Update', array( false !== strpos( $fee_words, 'On some eBay sites a Good \'Til Cancelled listing pays for its subtitle again at each monthly renewal.' ), false !== strpos( $fee_words, 'for each listing that has one: a subtitle or second category when a fixed-price listing is updated with it, or a listing is relisted or listed new with it; a reserve price when an auction is listed or relisted, never at an Update. Filling one in here on many products charges it for each of them.' ), false !== strpos( $fee_words, 'eBay does not refund a fee if you remove the field.' ) ), array( true, true, true ) );
check( 'the group says it sends nothing to eBay, and the next push or Update takes it', false !== strpos( $page, 'This group sends nothing to eBay: a product linked to an eBay listing takes the change at its next push or Update.' ), true );
// Fix round 1, L6 and H1: the ticked rows of a page; WooCommerce's own Stock
// field, and (fix round 2, review 2's L-1) the stock it sends at No change.
check( '  and that a bulk edit changes the ticked rows of one page (Screen Options), and WooCommerce\'s Stock field sends only a change', 1 === preg_match( '#<p class="tcgiant-bulk-scope"[^>]*>([^<]*)</p>#', $page, $scope ) ? html_entity_decode( $scope[1], ENT_QUOTES ) : null, 'A bulk edit changes the products ticked on this page of the list (Screen Options sets how many a page shows). WooCommerce\'s own Stock field, when it changes a stock, sends it to a linked listing as any stock change does; at No change it sends nothing, unless the product\'s last stock change or website sale never reached eBay: then its stock is sent.' );
// Fix round 1, L4: what a clear of the product's own value says.
preg_match_all( '#<option value="_shop">([^<]*)</option>#', $page, $shop_labels );
check( 'the clear of a product\'s own: "None (clear)" for the item type; elsewhere the product\'s own cleared, the shop\'s or eBay\'s going', array( html_entity_decode( $shop_labels[1][0] ?? '', ENT_QUOTES ), array_values( array_unique( array_map( function ( $l ) { return html_entity_decode( $l, ENT_QUOTES ); }, array_slice( $shop_labels[1], 1 ) ) ) ), count( $shop_labels[1] ), substr_count( $page, 'Use the shop' ) ), array( 'None (clear)', array( 'Clear the product\'s own (the shop\'s, or eBay\'s if imported)' ), 8, 0 ) );
// Fix round 1, L2: the durations a format takes, as the Listings page labels them.
preg_match( '#<select class="tcgiant-bulk-change" name="tcgiant_bulk_listing_duration">(.*?)</select>#s', $page, $dur_sel );
preg_match_all( '#<option value="([^"]*)">([^<]*)</option>#', $dur_sel[1] ?? '', $dur_opts );
check( 'the durations: Good \'Til Cancelled and an auction\'s five, as the Listings page labels them - never 30 Days, which no format takes', array_combine( $dur_opts[1], array_map( function ( $l ) { return html_entity_decode( $l, ENT_QUOTES ); }, $dur_opts[2] ) ), array( '' => '— No change —', 'GTC' => 'Good Til Cancelled', 'Days_1' => '1 Day', 'Days_3' => '3 Days', 'Days_5' => '5 Days', 'Days_7' => '7 Days', 'Days_10' => '10 Days', '_shop' => 'Clear the product\'s own (the shop\'s, or eBay\'s if imported)' ) );
// Fix round 1, L3: Auction and the duration it needs.
check( 'the rules line: set Listing duration with Auction', false !== strpos( html_entity_decode( $page, ENT_QUOTES ), 'Auction goes only where the product\'s duration after this edit is an auction\'s (1, 3, 5, 7 or 10 days): set Listing duration with it.' ), true );
// Fix round 1, L7 (review 1's K01 and K02): every Bulk Edit opened starts at
// No change - each select, every box and text area emptied, the boxes hidden.
// Pinned in its source here; tools/check-bulk-edit-js.js runs it in jsdom.
check( 'the group\'s script: Set to and a typed ID show their box; each Bulk Edit opened resets every select, box and text area, and hides the boxes', array( substr_count( $page, "box.on( 'change', 'select.tcgiant-bulk-change', function () {" ), substr_count( $page, "\$( '#wpbody' ).on( 'click', '#doaction, #doaction2', function () {\n\t\t\t\tbox.find( 'select.tcgiant-bulk-change' ).val( '' );\n\t\t\t\tbox.find( 'input.text, textarea' ).val( '' );\n\t\t\t\tbox.find( '.tcgiant-bulk-input' ).hide();\n\t\t\t} );" ) ), array( 1, 1 ) );
check( 'the boxes beside start hidden and empty; the text boxes keep eBay\'s lengths', array( substr_count( $page, 'class="tcgiant-bulk-input" style="display:none;"' ), substr_count( $page, 'value="" maxlength="55"' ), substr_count( $page, 'value="" maxlength="65"' ), substr_count( $page, 'maxlength="1000"></textarea>' ) ), array( 9, 1, 2, 1 ) );
$GLOBALS['W']['caps']['edit_products'] = false;
check( 'not drawn for a user who may not edit products', panel(), '' );
unset( $GLOBALS['W']['caps']['edit_products'] );
check( 'the hooks: drawn, saved, finished (bulk_edit_posts, and the request\'s end), said; nothing on quick edit', array( has_action( 'woocommerce_product_bulk_edit_end', array( BE, 'render_bulk_edit' ) ), has_action( 'woocommerce_product_bulk_edit_save', array( BE, 'save_bulk_edit' ) ), has_action( 'bulk_edit_posts', array( BE, 'finish_bulk_edit' ) ), has_action( 'shutdown', array( BE, 'finish_bulk_edit' ) ), has_action( 'admin_notices', array( BE, 'bulk_edit_notice' ) ), has_action( 'woocommerce_product_quick_edit_save' ) ), array( 10, 10, 10, 10, 10, false ) );
check( 'registered with the other eBay tab classes, in the admin', substr_count( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync.php' ), "\t\t\tTCGiant_Sync_Bulk_Edit::register_admin();\n\t\t}" ), 1 );

// -----------------------------------------------------------------------------
echo "\n== The group left alone: no meta written, byte for byte\n";

fresh();
product( 701, full_meta() );
product( 702, full_meta(), true );
product( 703, array() );
product( 704, array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_buy_it_now' => '120.00' ) );
$before = meta_bytes();
$done   = bulk( array( 701, 702, 703, 704 ), array(), array( 'change_regular_price' => '1', '_regular_price' => '15', '_tax_status' => 'taxable' ) );
check( 'WooCommerce\'s own fields changed, the group at No change: every product saved', $done, array( 701, 702, 703, 704 ) );
check( '  every meta of every product byte for byte as before', meta_bytes() === $before, true );
check( '  no log line, no notice kept, nothing sent or queued', array( bulk_lines(), said(), sent() ), array( array(), false, array() ) );
check( '  and no notice drawn', notice(), '' );
// The group's boxes typed into but their selects left at No change.
bulk( array( 701, 703 ), array( 'subtitle_value' => 'Typed but not chosen', 'ebay_price_value' => '9.99', 'category_value' => '31387', 'second_category_value' => '31387', 'condition_note_value' => 'Note' ) );
check( 'a box typed into with its field at No change: nothing written', array( meta_bytes() === $before, bulk_lines(), said() ), array( true, array(), false ) );
// WooCommerce's quick edit, even with the group's names in the request.
bulk_request( array( 701, 702 ), array( 'subtitle' => 'set', 'subtitle_value' => 'Quick', 'listing_type' => 'Chinese' ), array(), array( 'woocommerce_quick_edit' => '1' ) );
run_bulk( array( 701, 702 ) );
check( 'WooCommerce\'s quick edit: no _ebay_* meta written', array( meta_bytes() === $before, bulk_lines(), said() ), array( true, array(), false ) );
do_action( 'woocommerce_product_bulk_edit_save', wc_get_product( 701 ) );
check( '  the bulk hook fired during a quick edit request: nothing', meta_bytes() === $before, true );
$_GET = $_REQUEST = array();
// No bulk edit at all: the hook alone, with nothing asked.
do_action( 'woocommerce_product_bulk_edit_save', wc_get_product( 701 ) );
do_action( 'bulk_edit_posts', array( 701 ), array() );
do_action( 'shutdown' );
check( 'the hooks with no bulk edit request: nothing written, logged or kept', array( meta_bytes() === $before, bulk_lines(), said() ), array( true, array(), false ) );

// -----------------------------------------------------------------------------
echo "\n== Nonce and capabilities\n";

fresh();
product( 711, full_meta() );
product( 712, full_meta() );
$before = meta_bytes();
bulk( array( 711, 712 ), array( 'subtitle' => 'set', 'subtitle_value' => 'New' ), array(), array( 'woocommerce_quick_edit_nonce' => 'nonce-wrong' ) );
check( 'a wrong nonce: WooCommerce saves nothing, nor does the group', array( meta_bytes() === $before, bulk_lines(), said() ), array( true, array(), false ) );
bulk_request( array( 711 ), array( 'subtitle' => 'set', 'subtitle_value' => 'New' ), array(), array( 'woocommerce_quick_edit_nonce' => 'nonce-wrong' ) );
do_action( 'woocommerce_product_bulk_edit_save', wc_get_product( 711 ) );
do_action( 'bulk_edit_posts', array( 711 ), array() );
check( '  the group\'s hook reached with a wrong nonce: nothing', array( meta_bytes() === $before, bulk_lines() ), array( true, array() ) );
bulk_request( array( 711 ), array( 'subtitle' => 'set', 'subtitle_value' => 'New' ), array(), array( 'woocommerce_quick_edit_nonce' => null ) );
unset( $_REQUEST['woocommerce_quick_edit_nonce'] );
do_action( 'woocommerce_product_bulk_edit_save', wc_get_product( 711 ) );
do_action( 'bulk_edit_posts', array( 711 ), array() );
check( '  no nonce at all: nothing', array( meta_bytes() === $before, bulk_lines() ), array( true, array() ) );
$_GET = $_REQUEST = array();
bulk_request( array( 711 ), array( 'subtitle' => 'set', 'subtitle_value' => 'New' ) );
unset( $_REQUEST['woocommerce_bulk_edit'] );
do_action( 'woocommerce_product_bulk_edit_save', wc_get_product( 711 ) );
do_action( 'bulk_edit_posts', array( 711 ), array() );
check( '  a request without WooCommerce\'s bulk edit marker, its nonce good: nothing', array( meta_bytes() === $before, bulk_lines() ), array( true, array() ) );
$_GET = $_REQUEST = array();
$GLOBALS['W']['caps']['edit_products'] = false;
bulk( array( 711, 712 ), array( 'subtitle' => 'set', 'subtitle_value' => 'New' ) );
check( 'a user who may not edit products: nothing', array( meta_bytes() === $before, bulk_lines(), said() ), array( true, array(), false ) );
fresh();
$GLOBALS['GROUP_DENY'] = array( 711 );
bulk( array( 711, 712 ), array( 'subtitle' => 'set', 'subtitle_value' => 'New' ) );
check( 'edit_post refused for one product: it is left as it was, the other set', array( m( 711, '_ebay_export_subtitle' ), m( 712, '_ebay_export_subtitle' ) ), array( 'Old subtitle', 'New' ) );
check( '  said in the notice\'s record and the log', array( said()['denied'], said()['changed'], said()['products'] ), array( array( 'n' => 1, 'ids' => array( 711 ) ), 1, 2 ) );
check( '  in words', false !== strpos( notice(), '1 product not changed: you cannot edit it (#711).' ), true );
fresh();
product( 711, full_meta() );
$GLOBALS['WP_SKIP'] = array( 711 );
bulk( array( 711, 712 ), array( 'brand' => 'set', 'brand_value' => 'Acme' ) );
check( 'a product WordPress itself skips (locked, not the user\'s) never reaches the group', array( m( 711, '_ebay_export_brand' ), m( 712, '_ebay_export_brand' ), said()['products'] ), array( 'Old brand', 'Acme', 1 ) );

// -----------------------------------------------------------------------------
echo "\n== Every field: Set, Clear, No change - stored as the eBay tab stores it\n";

/**
 * Two products alike, each first saved once through the tab (so every row
 * the tab writes is there); then the same value through the tab on one and
 * the bulk edit on the other: their meta, whole, the same. $setup gives the
 * meta both start with.
 */
function same_as_tab( $label, array $setup, array $tab_changes, array $ebay, array $expect, $variable = false ) {
	fresh();
	product( 801, $setup, $variable );
	product( 802, $setup, $variable );
	tab_save( 801 );
	tab_save( 802 );
	$others = $GLOBALS['W']['meta'];
	unset( $others[801], $others[802] );
	tab_save( 801, $tab_changes );
	bulk( array( 802 ), $ebay );
	$rest = $GLOBALS['W']['meta'];
	unset( $rest[801], $rest[802] );
	$got = array();
	foreach ( array_keys( $expect ) as $key ) {
		$got[ $key ] = m( 802, $key );
	}
	check( $label, $got, $expect );
	check( '  the tab and the bulk edit leave the same meta, whole', meta_of( 801 ) === meta_of( 802 ), true );
	check( '  no other product touched; nothing sent; one log line', array( $rest === $others, sent(), count( bulk_lines() ) ), array( true, array(), 1 ) );
}
$base = array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' );
same_as_tab( 'item type: Coins', $base, array( '_ebay_export_item_type' => 'coins', '_ebay_export_grader_id_coins' => '', '_ebay_export_grade_value_coins' => '', '_ebay_export_ungraded_condition_coins' => '' ), array( 'item_type' => 'coins' ), array( '_ebay_export_item_type' => 'coins' ) );
same_as_tab( 'item type: the shop\'s setting', $base, array( '_ebay_export_item_type' => '' ), array( 'item_type' => '_shop' ), array( '_ebay_export_item_type' => '' ) );
same_as_tab( 'category from the shop\'s list', $base, array( '_ebay_export_category_id_select' => '183454', '_ebay_export_category_id_custom' => '', '_ebay_export_category_name' => '' ), array( 'category' => '183454' ), array( '_ebay_export_category_id' => '183454', '_ebay_export_category_typed' => '', '_ebay_export_category_name' => '' ) );
same_as_tab( 'category typed', $base + array( '_ebay_export_category_id' => '183454', '_ebay_export_category_name' => 'CCG' ), array( '_ebay_export_category_id_select' => 'custom', '_ebay_export_category_id_custom' => '31387', '_ebay_export_category_name' => '' ), array( 'category' => 'custom', 'category_value' => ' 31387 ' ), array( '_ebay_export_category_id' => '31387', '_ebay_export_category_typed' => '31387', '_ebay_export_category_name' => '' ) );
same_as_tab( 'category: the shop\'s setting (name gone too)', $base + array( '_ebay_export_category_id' => '31387', '_ebay_export_category_typed' => '31387', '_ebay_export_category_name' => 'Watches' ), array( '_ebay_export_category_id_select' => '', '_ebay_export_category_id_custom' => '', '_ebay_export_category_name' => '' ), array( 'category' => '_shop' ), array( '_ebay_export_category_id' => '', '_ebay_export_category_typed' => '', '_ebay_export_category_name' => '' ) );
same_as_tab( 'graded or ungraded: Ungraded', $base, array( '_ebay_export_condition_type' => 'ungraded' ), array( 'condition_type' => 'ungraded' ), array( '_ebay_export_condition_type' => 'ungraded' ) );
same_as_tab( 'graded or ungraded: the shop\'s setting', $base, array( '_ebay_export_condition_type' => '' ), array( 'condition_type' => '_shop' ), array( '_ebay_export_condition_type' => '' ) );
same_as_tab( 'grader: PSA on a graded card', $base, array( '_ebay_export_grader_id_tcg' => '275010' ), array( 'grader' => '275010' ), array( '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '' ) );
same_as_tab( 'grader: NGC on a graded coin, the grade left as it is', array( '_ebay_export_item_type' => 'coins', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '14', '_ebay_export_grade_value' => 'MS 65' ), array( '_ebay_export_grader_id_coins' => '15' ), array( 'grader' => '15' ), array( '_ebay_export_grader_id' => '15', '_ebay_export_grade_value' => 'MS 65' ) );
same_as_tab( 'grader: the shop\'s setting', $base + array( '_ebay_export_grader_id' => '275013' ), array( '_ebay_export_grader_id_tcg' => '' ), array( 'grader' => '_shop' ), array( '_ebay_export_grader_id' => '' ) );
same_as_tab( 'condition: Used, on an All Other item', array( '_ebay_export_item_type' => 'other' ), array( '_ebay_export_condition_id' => '3000' ), array( 'condition_id' => '3000' ), array( '_ebay_export_condition_id' => '3000' ) );
same_as_tab( 'condition: the shop\'s setting', array( '_ebay_export_item_type' => 'other', '_ebay_export_condition_id' => '7000' ), array( '_ebay_export_condition_id' => '' ), array( 'condition_id' => '_shop' ), array( '_ebay_export_condition_id' => '' ) );
same_as_tab( 'listing type: Fixed Price', $base, array( '_ebay_export_listing_type' => 'FixedPriceItem' ), array( 'listing_type' => 'FixedPriceItem' ), array( '_ebay_export_listing_type' => 'FixedPriceItem' ) );
same_as_tab( 'listing type: the shop\'s setting', $base, array( '_ebay_export_listing_type' => '' ), array( 'listing_type' => '_shop' ), array( '_ebay_export_listing_type' => '' ) );
same_as_tab( 'listing duration: 10 days on an auction', $base, array( '_ebay_export_listing_duration' => 'Days_10' ), array( 'listing_duration' => 'Days_10' ), array( '_ebay_export_listing_duration' => 'Days_10' ) );
same_as_tab( 'listing duration: the shop\'s setting', $base, array( '_ebay_export_listing_duration' => '' ), array( 'listing_duration' => '_shop' ), array( '_ebay_export_listing_duration' => '' ) );
same_as_tab( 'shipping policy: one fetched', $base, array( '_ebay_export_fulfillment_policy' => 'FP2' ), array( 'fulfillment' => 'FP2' ), array( '_ebay_export_fulfillment_policy' => 'FP2' ) );
same_as_tab( 'shipping policy: the shop\'s setting', $base + array( '_ebay_export_fulfillment_policy' => 'FP1' ), array( '_ebay_export_fulfillment_policy' => '' ), array( 'fulfillment' => '_shop' ), array( '_ebay_export_fulfillment_policy' => '' ) );
same_as_tab( 'eBay price: set, as eBay takes one', $base, array( '_ebay_export_price' => '12.5' ), array( 'ebay_price' => 'set', 'ebay_price_value' => '12.5' ), array( '_ebay_export_price' => '12.50' ) );
same_as_tab( 'eBay price: cleared', $base + array( '_ebay_export_price' => '19.99' ), array( '_ebay_export_price' => '' ), array( 'ebay_price' => 'clear' ), array( '_ebay_export_price' => null ) );
same_as_tab( 'no UPC, EAN or ISBN: ticked', $base, array( '_ebay_export_identifier_none' => '1' ), array( 'identifier_none' => 'yes' ), array( '_ebay_export_identifier_none' => '1' ) );
same_as_tab( 'no UPC, EAN or ISBN: unticked (the number and its type left)', $base + array( '_ebay_export_identifier_none' => '1', '_ebay_export_identifier' => '012345678905', '_ebay_export_identifier_type' => 'UPC' ), array( '_ebay_export_identifier_none' => null ), array( 'identifier_none' => 'no' ), array( '_ebay_export_identifier_none' => null, '_ebay_export_identifier' => '012345678905', '_ebay_export_identifier_type' => 'UPC' ) );
$long = str_repeat( 'Ünïcode subtitle ', 6 );
same_as_tab( 'subtitle: set, cut to 55 characters', $base, array( '_ebay_export_subtitle' => $long ), array( 'subtitle' => 'set', 'subtitle_value' => $long ), array( '_ebay_export_subtitle' => rtrim( mb_substr( trim( $long ), 0, 55 ) ) ) );
same_as_tab( 'subtitle: a quote and an ampersand, slashed as WordPress sends them', $base, array( '_ebay_export_subtitle' => 'Ash\'s "Mint" & boxed' ), array( 'subtitle' => 'set', 'subtitle_value' => 'Ash\'s "Mint" & boxed' ), array( '_ebay_export_subtitle' => 'Ash\'s "Mint" & boxed' ) );
same_as_tab( 'subtitle: cleared', $base + array( '_ebay_export_subtitle' => 'Old' ), array( '_ebay_export_subtitle' => '' ), array( 'subtitle' => 'clear' ), array( '_ebay_export_subtitle' => null ) );
same_as_tab( 'condition note: set, its lines kept', $base, array( '_ebay_export_condition_note' => "Light wear.\nNo creases." ), array( 'condition_note' => 'set', 'condition_note_value' => "Light wear.\nNo creases." ), array( '_ebay_export_condition_note' => "Light wear.\nNo creases." ) );
same_as_tab( 'condition note: cleared', $base + array( '_ebay_export_condition_note' => 'Old' ), array( '_ebay_export_condition_note' => '' ), array( 'condition_note' => 'clear' ), array( '_ebay_export_condition_note' => null ) );
same_as_tab( 'second category from the list, named as the tab names it', $base, array( '_ebay_export_second_category' => '183454', '_ebay_export_second_category_name' => 'CCG Individual Cards: Pokémon, Magic, Yu-Gi-Oh!, Lorcana, One Piece and other card games (183454)' ), array( 'second_category' => '183454' ), array( '_ebay_export_second_category' => '183454', '_ebay_export_second_category_name' => 'CCG Individual Cards: Pokémon, Magic, Yu-Gi-Oh!, Lorcana, One Piece and other card games (183454)' ) );
same_as_tab( 'second category typed: no name', $base + array( '_ebay_export_second_category' => '183454', '_ebay_export_second_category_name' => 'CCG (183454)' ), array( '_ebay_export_second_category' => '31387', '_ebay_export_second_category_name' => '' ), array( 'second_category' => 'custom', 'second_category_value' => '31387' ), array( '_ebay_export_second_category' => '31387', '_ebay_export_second_category_name' => null ) );
same_as_tab( 'second category: cleared, name too', $base + array( '_ebay_export_second_category' => '31387', '_ebay_export_second_category_name' => 'Watches (31387)' ), array( '_ebay_export_second_category' => '', '_ebay_export_second_category_name' => '' ), array( 'second_category' => 'clear' ), array( '_ebay_export_second_category' => null, '_ebay_export_second_category_name' => null ) );
same_as_tab( 'Buy It Now: set on an auction, as eBay takes a price', $base, array( '_ebay_export_buy_it_now' => '120' ), array( 'buy_it_now' => 'set', 'buy_it_now_value' => '120' ), array( '_ebay_export_buy_it_now' => '120.00' ) );
same_as_tab( 'Buy It Now: cleared', $base + array( '_ebay_export_buy_it_now' => '120.00' ), array( '_ebay_export_buy_it_now' => '' ), array( 'buy_it_now' => 'clear' ), array( '_ebay_export_buy_it_now' => null ) );
same_as_tab( 'reserve: set on an auction', $base, array( '_ebay_export_reserve' => '90.5' ), array( 'reserve' => 'set', 'reserve_value' => '90.5' ), array( '_ebay_export_reserve' => '90.50' ) );
same_as_tab( 'reserve: cleared', $base + array( '_ebay_export_reserve' => '90.00' ), array( '_ebay_export_reserve' => '' ), array( 'reserve' => 'clear' ), array( '_ebay_export_reserve' => null ) );
same_as_tab( 'Brand: set, cut to 65', $base, array( '_ebay_export_brand' => str_repeat( 'b', 70 ) ), array( 'brand' => 'set', 'brand_value' => str_repeat( 'b', 70 ) ), array( '_ebay_export_brand' => str_repeat( 'b', 65 ) ) );
same_as_tab( 'Brand: cleared', $base + array( '_ebay_export_brand' => 'Acme' ), array( '_ebay_export_brand' => '' ), array( 'brand' => 'clear' ), array( '_ebay_export_brand' => null ) );
same_as_tab( 'MPN: set', $base, array( '_ebay_export_mpn' => 'ZT-1' ), array( 'mpn' => 'set', 'mpn_value' => 'ZT-1' ), array( '_ebay_export_mpn' => 'ZT-1' ) );
same_as_tab( 'MPN: cleared', $base + array( '_ebay_export_mpn' => 'ZT-1' ), array( '_ebay_export_mpn' => '' ), array( 'mpn' => 'clear' ), array( '_ebay_export_mpn' => null ) );
same_as_tab( 'a relist\'s held options forgotten when a relist field changes, as the tab forgets them', $base + array( '_ebay_export_subtitle' => 'Old', '_ebay_export_options_relist_refused' => 'x>1>1' ), array( '_ebay_export_subtitle' => 'New' ), array( 'subtitle' => 'set', 'subtitle_value' => 'New' ), array( '_ebay_export_options_relist_refused' => null ) );
same_as_tab( '  and kept when Brand changes, which no relist sends', $base + array( '_ebay_export_options_relist_refused' => 'x>1>1' ), array( '_ebay_export_brand' => 'Acme' ), array( 'brand' => 'set', 'brand_value' => 'Acme' ), array( '_ebay_export_options_relist_refused' => 'x>1>1' ) );

// Every field at once, and No change beside them.
fresh();
product( 820, full_meta() );
product( 821, array( '_ebay_export_item_type' => 'tcg' ) );
$keep = meta_of( 820 );
bulk( array( 820, 821 ), array( 'subtitle' => 'set', 'subtitle_value' => 'One field' ) );
$diff = array();
foreach ( meta_of( 820 ) as $k => $v ) {
	if ( ( $keep[ $k ] ?? null ) !== $v ) {
		$diff[ $k ] = $v;
	}
}
check( 'one field set: only its meta (and the relist memo its change forgets) moved; every other field at No change untouched', array( $diff, array_diff_key( $keep, meta_of( 820 ) ) ), array( array( '_ebay_export_subtitle' => 'One field' ), array( '_ebay_export_options_relist_refused' => 'x>1>1' ) ) );
check( '  a product with nothing: the one field alone', meta_of( 821 ), array( '_ebay_export_item_type' => 'tcg', '_ebay_export_subtitle' => 'One field' ) );

fresh();
product( 830, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded' ) );
bulk( array( 830 ), array(
	'item_type' => 'tcg', 'category' => '183454', 'condition_type' => 'graded', 'grader' => '275016', 'listing_type' => 'Chinese', 'listing_duration' => 'Days_5', 'fulfillment' => 'FP2',
	'ebay_price' => 'set', 'ebay_price_value' => '50', 'identifier_none' => 'yes', 'subtitle' => 'set', 'subtitle_value' => 'S', 'condition_note' => 'set', 'condition_note_value' => 'N',
	'second_category' => '183050', 'buy_it_now' => 'set', 'buy_it_now_value' => '99', 'reserve' => 'set', 'reserve_value' => '70', 'brand' => 'set', 'brand_value' => 'B', 'mpn' => 'set', 'mpn_value' => 'M',
) );
check( 'every field at once, on one product', array( m( 830, '_ebay_export_category_id' ), m( 830, '_ebay_export_grader_id' ), m( 830, '_ebay_export_listing_type' ), m( 830, '_ebay_export_listing_duration' ), m( 830, '_ebay_export_fulfillment_policy' ), m( 830, '_ebay_export_price' ), m( 830, '_ebay_export_identifier_none' ), m( 830, '_ebay_export_subtitle' ), m( 830, '_ebay_export_condition_note' ), m( 830, '_ebay_export_second_category' ), m( 830, '_ebay_export_buy_it_now' ), m( 830, '_ebay_export_reserve' ), m( 830, '_ebay_export_brand' ), m( 830, '_ebay_export_mpn' ) ), array( '183454', '275016', 'Chinese', 'Days_5', 'FP2', '50.00', '1', 'S', 'N', '183050', '99.00', '70.00', 'B', 'M' ) );
check( '  counted: 1 product changed, 14 of the 16 fields set, item type and Graded already so', array( said()['changed'], said()['products'], array_sum( array_column( said()['fields'], 'changed' ) ), said()['fields']['item_type']['same'], said()['fields']['condition_type']['same'] ), array( 1, 1, 14, 1, 1 ) );
check( '  nothing reached eBay, Action Scheduler or WP-Cron', sent(), array() );

// -----------------------------------------------------------------------------
echo "\n== The skip rules\n";

fresh();
product( 840, array( '_ebay_export_listing_type' => 'FixedPriceItem' ) );                  // Fixed Price.
product( 841, array( '_ebay_export_listing_type' => 'Chinese' ) );                         // Auction.
product( 842, array(), true );                                                             // Variable.
product( 843, array() );                                                                   // The shop's format: Fixed Price.
product( 844, array( '_ebay_listing_type' => 'Chinese' ) );                                // Recorded as an auction.
bulk( array( 840, 841, 842, 843, 844 ), array( 'buy_it_now' => 'set', 'buy_it_now_value' => '150', 'reserve' => 'set', 'reserve_value' => '100' ) );
check( 'Buy It Now and reserve: set on an auction, by its own format or its recorded listing; skipped on Fixed Price, the shop\'s Fixed Price and a variable product', array( m( 840, '_ebay_export_buy_it_now' ), m( 841, '_ebay_export_buy_it_now' ), m( 842, '_ebay_export_buy_it_now' ), m( 843, '_ebay_export_reserve' ), m( 844, '_ebay_export_reserve' ) ), array( null, '150.00', null, null, '100.00' ) );
check( '  skipped, by reason and ID', array( said()['fields']['buy_it_now']['skipped'], said()['fields']['reserve']['changed'] ), array( array( 'not_auction' => array( 'n' => 2, 'ids' => array( 840, 843 ) ), 'variable' => array( 'n' => 1, 'ids' => array( 842 ) ) ), 2 ) );
$words = notice();
check( '  in the notice\'s words', array( false !== strpos( $words, 'Buy It Now price: set on 2 products; skipped on 2 - its format is not Auction: #840, #843; skipped on 1 - a variable product is never an auction: #842.' ), false !== strpos( $words, 'eBay fields (TCGiant Sync): 2 of 5 products changed.' ) ), array( true, true ) );
fresh();
TCGiant_Sync_OAuth::$settings['export_listing_type'] = 'Chinese';
bulk( array( 843 ), array( 'buy_it_now' => 'set', 'buy_it_now_value' => '150' ) );
check( '  a product with no format of its own, in a shop whose format is Auction: set', m( 843, '_ebay_export_buy_it_now' ), '150.00' );
TCGiant_Sync_OAuth::$settings['export_listing_type'] = 'FixedPriceItem';
bulk( array( 840, 842 ), array( 'buy_it_now' => 'clear', 'reserve' => 'clear' ) );
check( '  Clear is never skipped: a fixed-price or variable product holds none after', array( m( 840, '_ebay_export_buy_it_now' ), m( 842, '_ebay_export_buy_it_now' ), said()['fields']['buy_it_now']['skipped'] ), array( null, null, array() ) );

// An auction's prices with the format set in the same edit: the format first.
fresh();
product( 850, array( '_ebay_export_listing_type' => 'FixedPriceItem', '_ebay_export_listing_duration' => 'GTC' ) );
product( 851, array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) );
product( 852, array(), true );
bulk( array( 850, 852 ), array( 'listing_type' => 'Chinese', 'listing_duration' => 'Days_10', 'buy_it_now' => 'set', 'buy_it_now_value' => '80', 'reserve' => 'set', 'reserve_value' => '60' ) );
check( 'Auction, 10 days, Buy It Now and reserve in one edit on a fixed-price product: all set (the format first)', array( m( 850, '_ebay_export_listing_type' ), m( 850, '_ebay_export_listing_duration' ), m( 850, '_ebay_export_buy_it_now' ), m( 850, '_ebay_export_reserve' ) ), array( 'Chinese', 'Days_10', '80.00', '60.00' ) );
check( '  a variable product: Auction skipped, so its duration and prices too', array( m( 852, '_ebay_export_listing_type' ), m( 852, '_ebay_export_listing_duration' ), m( 852, '_ebay_export_buy_it_now' ), said()['fields']['listing_type']['skipped'], said()['fields']['listing_duration']['skipped'] ), array( null, null, null, array( 'variable_auction' => array( 'n' => 1, 'ids' => array( 852 ) ) ), array( 'duration_fixed' => array( 'n' => 1, 'ids' => array( 852 ) ) ) ) );
bulk( array( 851 ), array( 'listing_type' => 'FixedPriceItem', 'buy_it_now' => 'set', 'buy_it_now_value' => '80' ) );
check( 'Fixed Price and a Buy It Now in one edit on an auction: the format set, the price skipped', array( m( 851, '_ebay_export_listing_type' ), m( 851, '_ebay_export_buy_it_now' ), said()['fields']['buy_it_now']['skipped'] ), array( 'FixedPriceItem', null, array( 'not_auction' => array( 'n' => 1, 'ids' => array( 851 ) ) ) ) );
bulk( array( 851 ), array( 'listing_duration' => 'Days_7' ) );
check( 'a duration Fixed Price does not take: skipped', array( m( 851, '_ebay_export_listing_duration' ), said()['fields']['listing_duration']['skipped'] ), array( 'Days_7', array( 'duration_fixed' => array( 'n' => 1, 'ids' => array( 851 ) ) ) ) );
product( 853, array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) );
bulk( array( 853 ), array( 'listing_duration' => 'GTC' ) );
check( 'Good \'Til Cancelled on an auction: skipped (the push would refuse it)', array( m( 853, '_ebay_export_listing_duration' ), said()['fields']['listing_duration']['skipped'] ), array( 'Days_7', array( 'duration_auction' => array( 'n' => 1, 'ids' => array( 853 ) ) ) ) );
bulk( array( 853, 852 ), array( 'listing_duration' => '_shop', 'listing_type' => '_shop' ) );
check( '  the shop\'s setting never skipped', array( m( 853, '_ebay_export_listing_duration' ), m( 852, '_ebay_export_listing_type' ), said()['fields']['listing_duration']['skipped'] ), array( '', '', array() ) );

// Grader, graded or ungraded, the condition list: by the item type after the edit.
fresh();
product( 860, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded' ) );
product( 861, array( '_ebay_export_item_type' => 'coins', '_ebay_export_condition_type' => 'graded' ) );
product( 862, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'ungraded' ) );
product( 863, array( '_ebay_export_item_type' => 'tcg' ) ); // The shop's: ungraded.
product( 864, array() );
bulk( array( 860, 861, 862, 863, 864 ), array( 'grader' => '275010' ) );
check( 'a card grader: set on a graded card; skipped on a coin, an ungraded card (its own or the shop\'s) and one with no item type', array( m( 860, '_ebay_export_grader_id' ), m( 861, '_ebay_export_grader_id' ), m( 862, '_ebay_export_grader_id' ), m( 863, '_ebay_export_grader_id' ), m( 864, '_ebay_export_grader_id' ) ), array( '275010', null, null, null, null ) );
check( '  reasons', said()['fields']['grader']['skipped'], array( 'grader_tcg' => array( 'n' => 2, 'ids' => array( 861, 864 ) ), 'not_graded' => array( 'n' => 2, 'ids' => array( 862, 863 ) ) ) );
bulk( array( 861, 864 ), array( 'item_type' => 'tcg', 'condition_type' => 'graded', 'grader' => '275013' ) );
check( '  the item type and Graded in the same edit: set (they go first)', array( m( 861, '_ebay_export_grader_id' ), m( 864, '_ebay_export_grader_id' ) ), array( '275013', '275013' ) );
bulk( array( 860 ), array( 'grader' => '14' ) );
check( 'a coin grader on a card: skipped', array( m( 860, '_ebay_export_grader_id' ), said()['fields']['grader']['skipped'] ), array( '275010', array( 'grader_coins' => array( 'n' => 1, 'ids' => array( 860 ) ) ) ) );
TCGiant_Sync_OAuth::$settings['export_condition_type'] = 'graded';
bulk( array( 863 ), array( 'grader' => '275011' ) );
check( '  a card with no Graded of its own, in a shop that grades: set', m( 863, '_ebay_export_grader_id' ), '275011' );
TCGiant_Sync_OAuth::$settings['export_condition_type'] = 'ungraded';
product( 865, array( '_ebay_export_item_type' => 'other' ) );
bulk( array( 860, 865 ), array( 'condition_type' => 'ungraded', 'condition_id' => '3000' ) );
check( 'Graded or Ungraded: skipped on All Other; the condition list: only on All Other', array( m( 860, '_ebay_export_condition_type' ), m( 865, '_ebay_export_condition_type' ), m( 860, '_ebay_export_condition_id' ), m( 865, '_ebay_export_condition_id' ), said()['fields']['condition_type']['skipped'], said()['fields']['condition_id']['skipped'] ), array( 'ungraded', null, null, '3000', array( 'all_other' => array( 'n' => 1, 'ids' => array( 865 ) ) ), array( 'not_all_other' => array( 'n' => 1, 'ids' => array( 860 ) ) ) ) );

// The eBay price of a variable product; the second category as the primary.
fresh();
product( 870, array() );
product( 871, array(), true );
product( 872, array( '_ebay_export_category_id' => '183454' ) );
bulk( array( 870, 871 ), array( 'ebay_price' => 'set', 'ebay_price_value' => '25' ) );
check( 'eBay price: set on a simple product, skipped on a variable one (each variation has its own)', array( m( 870, '_ebay_export_price' ), m( 871, '_ebay_export_price' ), said()['fields']['ebay_price']['skipped'] ), array( '25.00', null, array( 'variable_price' => array( 'n' => 1, 'ids' => array( 871 ) ) ) ) );
bulk( array( 870, 872 ), array( 'second_category' => '183454' ) );
check( 'second category: skipped where it is the product\'s own category, set elsewhere', array( m( 870, '_ebay_export_second_category' ), m( 872, '_ebay_export_second_category' ), said()['fields']['second_category']['skipped'] ), array( '183454', null, array( 'primary' => array( 'n' => 1, 'ids' => array( 872 ) ) ) ) );
bulk( array( 870 ), array( 'second_category' => 'custom', 'second_category_value' => '261328' ) );
check( '  the shop\'s default category, for a product with none of its own: skipped', array( m( 870, '_ebay_export_second_category' ), said()['fields']['second_category']['skipped'] ), array( '183454', array( 'primary' => array( 'n' => 1, 'ids' => array( 870 ) ) ) ) );
bulk( array( 872 ), array( 'category' => '183050', 'second_category' => '183454' ) );
check( '  judged by the category after the edit (the category goes first)', array( m( 872, '_ebay_export_category_id' ), m( 872, '_ebay_export_second_category' ) ), array( '183050', '183454' ) );

// A variable product: the item's own fields go, an auction's never.
fresh();
product( 880, array( '_ebay_export_listing_type' => 'Chinese' ), true );
bulk( array( 880 ), array( 'subtitle' => 'set', 'subtitle_value' => 'Var', 'condition_note' => 'set', 'condition_note_value' => 'Note', 'second_category' => '183050', 'brand' => 'set', 'brand_value' => 'Acme', 'mpn' => 'set', 'mpn_value' => 'M1', 'identifier_none' => 'yes', 'buy_it_now' => 'set', 'buy_it_now_value' => '50', 'reserve' => 'set', 'reserve_value' => '40', 'ebay_price' => 'set', 'ebay_price_value' => '9' ) );
check( 'a variable product: subtitle, note, second category, Brand, MPN and the tick set at item level', array( m( 880, '_ebay_export_subtitle' ), m( 880, '_ebay_export_condition_note' ), m( 880, '_ebay_export_second_category' ), m( 880, '_ebay_export_brand' ), m( 880, '_ebay_export_mpn' ), m( 880, '_ebay_export_identifier_none' ) ), array( 'Var', 'Note', '183050', 'Acme', 'M1', '1' ) );
check( '  Buy It Now, reserve and the eBay price skipped', array( m( 880, '_ebay_export_buy_it_now' ), m( 880, '_ebay_export_reserve' ), m( 880, '_ebay_export_price' ) ), array( null, null, null ) );
check( '  said, by field', array_keys( array_filter( array_map( function ( $f ) { return $f['skipped']; }, said()['fields'] ) ) ), array( 'ebay_price', 'buy_it_now', 'reserve' ) );

// -----------------------------------------------------------------------------
echo "\n== A value no product could take\n";

fresh();
product( 890, full_meta() + array( '_ebay_export_buy_it_now' => '120.00' ) );
$before = meta_bytes();
bulk( array( 890 ), array( 'ebay_price' => 'set', 'ebay_price_value' => '12,5x', 'buy_it_now' => 'set', 'buy_it_now_value' => '0', 'second_category' => 'custom', 'second_category_value' => '12a', 'category' => 'custom', 'category_value' => '0', 'subtitle' => 'set', 'subtitle_value' => '   ', 'brand' => 'set', 'brand_value' => '', 'listing_type' => 'Weekly', 'grader' => '999', 'reserve' => 'maybe', 'identifier_none' => 'perhaps' ) );
check( 'a price that is not one, an ID that is no category, Set with nothing typed, a choice that is none: nothing written, nothing emptied', meta_bytes() === $before, true );
check( '  said once each, by field', said()['refused'], array( 'category' => array( 'not_category', '0' ), 'grader' => array( 'choice', '' ), 'listing_type' => array( 'choice', '' ), 'ebay_price' => array( 'not_price', '12,5x' ), 'identifier_none' => array( 'choice', '' ), 'subtitle' => array( 'empty', '' ), 'second_category' => array( 'not_category', '12a' ), 'buy_it_now' => array( 'not_price', '0' ), 'reserve' => array( 'choice', '' ), 'brand' => array( 'empty', '' ) ) );
$words = notice();
check( '  in words', array( false !== strpos( $words, 'eBay price: not changed - "12,5x" is not a price above 0.' ), false !== strpos( $words, 'Second category: not changed - "12a" is not an eBay category ID.' ), false !== strpos( $words, 'Subtitle: not changed - nothing was typed (choose Clear to empty it).' ), false !== strpos( $words, 'Listing type: not changed - it is not one of its choices.' ) ), array( true, true, true, true ) );
check( '  the log\'s line too, and 0 of 1 changed', array( count( bulk_lines() ), false !== strpos( bulk_lines()[0], '0 of 1 products changed' ) ), array( 1, true ) );
bulk( array( 890 ), array( 'category' => '99999', 'second_category' => '12345' ) );
check( 'a category not in the shop\'s list, chosen as one from it: not changed, said', array( meta_bytes() === $before, said()['refused'] ), array( true, array( 'category' => array( 'choice', '' ), 'second_category' => array( 'choice', '' ) ) ) );

// -----------------------------------------------------------------------------
echo "\n== The notice and the log\n";

fresh();
product( 900, array( '_ebay_export_listing_type' => 'Chinese' ) );
product( 901, array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_subtitle' => 'Same' ) );
product( 902, array( '_ebay_export_listing_type' => 'FixedPriceItem' ) );
for ( $i = 0; $i < 12; $i++ ) {
	product( 910 + $i, array() );
}
$ids = array_merge( array( 900, 901, 902 ), range( 910, 921 ) );
bulk( $ids, array( 'subtitle' => 'set', 'subtitle_value' => 'Same', 'reserve' => 'set', 'reserve_value' => '40' ) );
$rec = said();
check( 'the record: products in the edit, products changed, field by field', array( $rec['products'], $rec['changed'], $rec['fields']['subtitle']['changed'], $rec['fields']['subtitle']['same'], $rec['fields']['reserve']['changed'], $rec['fields']['reserve']['skipped']['not_auction']['n'] ), array( 15, 15, 14, 1, 2, 13 ) );
check( '  the first ten IDs kept of a list, the rest counted', $rec['fields']['reserve']['skipped']['not_auction']['ids'], array( 902, 910, 911, 912, 913, 914, 915, 916, 917, 918 ) );
$words = notice();
check( 'the notice: counts, field by field, the IDs and how many more', array( false !== strpos( $words, 'eBay fields (TCGiant Sync): 15 of 15 products changed.' ), false !== strpos( $words, 'Subtitle: set on 14 products; 1 already so.' ), false !== strpos( $words, 'Reserve price: set on 2 products; skipped on 13 - its format is not Auction: #902, #910, #911, #912, #913, #914, #915, #916, #917, #918 and 3 more.' ) ), array( true, true, true ) );
check( '  where the change goes, and the Listings page', array( false !== strpos( $words, 'These fields were not sent to eBay: a product linked to an eBay listing takes the change there at its next push or Update. A stock left at No change is sent only for a product whose last stock change or website sale never reached eBay. Listings' ), 1 === preg_match( '#<a href="https://shop\.example/wp-admin/admin\.php\?page=tcgiant-listings">Listings</a>#', ( function () { set_transient( TCGiant_Sync_Bulk_Edit::SAID . get_current_user_id(), $GLOBALS['rec'], 300 ); ob_start(); TCGiant_Sync_Bulk_Edit::bulk_edit_notice(); return ob_get_clean(); } )() ) ), array( true, true ) );
check( '  a warning when anything was skipped', false !== strpos( ( function () { set_transient( TCGiant_Sync_Bulk_Edit::SAID . get_current_user_id(), $GLOBALS['rec'], 300 ); ob_start(); TCGiant_Sync_Bulk_Edit::bulk_edit_notice(); return ob_get_clean(); } )(), 'notice-warning' ), true );
check( '  shown once', notice(), '' );
set_transient( TCGiant_Sync_Bulk_Edit::SAID . get_current_user_id(), $rec, 300 );
check( '  not on another screen, nor for a user who may not edit products; kept for five minutes', array( notice( 'toplevel_page_tcgiant-sync' ), ( function () { $GLOBALS['W']['caps']['edit_products'] = false; $n = notice(); unset( $GLOBALS['W']['caps']['edit_products'] ); return $n; } )(), $GLOBALS['W']['expiry'][ TCGiant_Sync_Bulk_Edit::SAID . get_current_user_id() ], is_array( said() ) ), array( '', '', 300, true ) );
fresh();
bulk( array( 900, 901 ), array( 'brand' => 'set', 'brand_value' => 'Zeta' ) );
check( 'nothing skipped: a success notice', false !== strpos( ( function () { ob_start(); TCGiant_Sync_Bulk_Edit::bulk_edit_notice(); return ob_get_clean(); } )(), 'notice-success' ), true );
fresh();
bulk( $ids, array( 'subtitle' => 'set', 'subtitle_value' => 'Same', 'reserve' => 'set', 'reserve_value' => '40' ) );
check( 'the log: one line a bulk edit, with the counts and the fields', bulk_lines(), array( 'Bulk edit of eBay fields (WooCommerce Bulk Edit, user #1): 0 of 15 products changed. Subtitle: set on 0 products; 15 already so. Reserve price: set on 0 products; 2 already so; skipped on 13 - its format is not Auction: #902, #910, #911, #912, #913, #914, #915, #916, #917, #918 and 3 more. These fields were not sent to eBay. A stock left at No change is sent only for a product whose last stock change or website sale never reached eBay.' ) );
do_action( 'shutdown' );
check( '  the request\'s end after bulk_edit_posts writes no second line', count( bulk_lines() ), 1 );
fresh();
bulk_request( array( 900 ), array( 'mpn' => 'set', 'mpn_value' => 'X9' ) );
do_action( 'woocommerce_product_bulk_edit_save', wc_get_product( 900 ) );
$_GET = $_REQUEST = array();
check( '  an older WordPress, with no bulk_edit_posts: written at the request\'s end, once', array( bulk_lines(), ( function () { do_action( 'shutdown' ); do_action( 'shutdown' ); return count( bulk_lines() ); } )(), said()['fields']['mpn']['changed'] ), array( array(), 1, 1 ) );

// -----------------------------------------------------------------------------
echo "\n== Nothing reaches eBay\n";

fresh();
product( 950, full_meta() );
product( 951, full_meta(), true );
bulk( array( 950, 951 ), array( 'category' => '183050', 'second_category' => '183454', 'listing_type' => 'Chinese', 'subtitle' => 'set', 'subtitle_value' => 'X', 'ebay_price' => 'set', 'ebay_price_value' => '5', 'buy_it_now' => 'set', 'buy_it_now_value' => '9' ) );
check( 'a bulk edit of linked products: no eBay call, no push or Update queued, nothing scheduled', array( sent(), $GLOBALS['API']->calls, $GLOBALS['API']->lookups ), array( array(), array(), array() ) );
check( '  the push\'s own marks untouched: no status, no queued push', array( m( 950, '_ebay_export_status' ), m( 950, '_ebay_export_pushing_since' ), m( 950, '_ebay_item_id' ) ), array( null, null, '110000000777' ) );
/** A PHP file's code, its comments left out. */
function code_only( $file ) {
	$out = '';
	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$out .= is_array( $t ) ? $t[1] : $t;
	}
	return $out;
}
$src = code_only( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-bulk-edit.php' );
check( '  the class calls nothing that sends or queues', array( preg_match( '/TCGiant_Sync_API|push_product|bulk_push|as_enqueue|as_schedule|wp_schedule|wp_remote|trading_api/', $src ) ), array( 0 ) );

// -----------------------------------------------------------------------------
echo "\n== The eBay tab's own save, through the shared code\n";

$admin_src = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' );
check( 'the product screen\'s save hands $_POST to the shared save', 1 === preg_match( '/public function save_product_export_meta\( \$post_id \) \{\s*\/\/ phpcs:ignore[^\n]*\n\s*self::save_export_posted\( \$post_id, \$_POST \);\s*\}/', $admin_src ), true );
check( '  the shared save reads nothing of $_POST itself', 1 === preg_match( '/public static function save_export_posted\( \$post_id, array \$posted \) \{(.*?)\n\t\}\n/s', $admin_src, $body ) && false === strpos( $body[1], '$_POST' ), true );
check( 'the bulk edit saves through the tab\'s own code, never update_post_meta of its own', array( substr_count( $src, 'update_post_meta' ), substr_count( $src, 'delete_post_meta' ), substr_count( $src, 'TCGiant_Sync_Admin::save_export_posted(' ), substr_count( $src, 'TCGiant_Sync_Listing_Options::save_posted(' ), substr_count( $src, 'TCGiant_Sync_Ebay_Price::save_posted(' ), substr_count( $src, 'TCGiant_Sync_Identifiers::set_none(' ) ), array( 0, 0, 2, 1, 1, 1 ) );
fresh();
product( 960, array() );
tab_save( 960, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id_tcg' => '275010', '_ebay_export_grade_value_tcg' => '9', '_ebay_export_listing_type' => 'Chinese', '_ebay_export_subtitle' => 'Tab', '_ebay_export_buy_it_now' => '30', '_ebay_export_identifier_none' => '1', '_ebay_export_price' => '7' ) );
check( 'the tab\'s own save, posted whole, stores as before: grade and grader by item type, every key written', array( m( 960, '_ebay_export_grader_id' ), m( 960, '_ebay_export_grade_value' ), m( 960, '_ebay_export_listing_type' ), m( 960, '_ebay_export_cert_number' ), m( 960, '_ebay_export_subtitle' ), m( 960, '_ebay_export_buy_it_now' ), m( 960, '_ebay_export_identifier_none' ), m( 960, '_ebay_export_price' ), m( 960, '_ebay_export_category_id' ) ), array( '275010', '9', 'Chinese', '', 'Tab', '30.00', '1', '7.00', '' ) );
check( '  and writes no log line or notice of the bulk edit\'s', array( bulk_lines(), said() ), array( array(), false ) );

// -----------------------------------------------------------------------------
echo "\n== Fix round 1: the item type, the category it has, Auction's duration, and review 1's surviving mutants\n";

// M1: an item type changed to Trading Cards or Coins posts what the eBay tab
// posts for it - that type's grader, grade and ungraded condition, as the
// tab draws them for a product of another type: empty - so the other type's
// never stay with it, as the tab's own save of the change clears them.
$graded_card   = array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '10', '_ebay_export_cert_number' => '12345678' );
$ungraded_card = array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => '400010' );
$to_coins      = array( '_ebay_export_item_type' => 'coins', '_ebay_export_grader_id_coins' => '', '_ebay_export_grade_value_coins' => '', '_ebay_export_ungraded_condition_coins' => '' );
$to_cards      = array( '_ebay_export_item_type' => 'tcg', '_ebay_export_grader_id_tcg' => '', '_ebay_export_grade_value_tcg' => '', '_ebay_export_ungraded_condition_tcg' => '' );
same_as_tab( 'item type Coins on a graded card: its card grader and grade cleared, as the tab clears them; its cert number kept', $graded_card, $to_coins, array( 'item_type' => 'coins' ), array( '_ebay_export_item_type' => 'coins', '_ebay_export_grader_id' => '', '_ebay_export_grade_value' => '', '_ebay_export_ungraded_condition' => '', '_ebay_export_cert_number' => '12345678' ) );
check( '  counted as changed, nothing skipped', array( said()['changed'], said()['fields']['item_type']['changed'], said()['fields']['item_type']['skipped'] ), array( 1, 1, array() ) );
same_as_tab( 'item type Coins on an ungraded card: its card condition cleared, as the tab clears it', $ungraded_card, $to_coins, array( 'item_type' => 'coins' ), array( '_ebay_export_item_type' => 'coins', '_ebay_export_ungraded_condition' => '', '_ebay_export_grader_id' => '' ) );
same_as_tab( 'item type Trading Cards on a graded coin: its coin grader and grade cleared; its year kept', array( '_ebay_export_item_type' => 'coins', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '14', '_ebay_export_grade_value' => 'MS 65', '_ebay_export_coin_year' => '1921' ), $to_cards, array( 'item_type' => 'tcg' ), array( '_ebay_export_item_type' => 'tcg', '_ebay_export_grader_id' => '', '_ebay_export_grade_value' => '', '_ebay_export_coin_year' => '1921' ) );
same_as_tab( 'item type Trading Cards on an All Other item still holding a card\'s grader: cleared, as the tab clears it', array( '_ebay_export_item_type' => 'other', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '9' ), $to_cards, array( 'item_type' => 'tcg' ), array( '_ebay_export_item_type' => 'tcg', '_ebay_export_grader_id' => '', '_ebay_export_grade_value' => '' ) );
same_as_tab( 'item type Trading Cards on a graded card (already so): its grader and grade kept, as the tab keeps them', $graded_card, array( '_ebay_export_item_type' => 'tcg' ), array( 'item_type' => 'tcg' ), array( '_ebay_export_item_type' => 'tcg', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '10' ) );
check( '  counted as already so', array( said()['fields']['item_type']['same'], said()['changed'] ), array( 1, 0 ) );
same_as_tab( 'item type All Other on a graded card: the grader and grade kept, as the tab keeps them', $graded_card, array( '_ebay_export_item_type' => 'other' ), array( 'item_type' => 'other' ), array( '_ebay_export_item_type' => 'other', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '10' ) );

// L1: a category set to the one a product already has, chosen as it was.
same_as_tab( 'category typed as the one it has: left alone, its name kept, as the tab keeps it', $base + array( '_ebay_export_category_id' => '31387', '_ebay_export_category_typed' => '31387', '_ebay_export_category_name' => 'Wristwatches' ), array(), array( 'category' => 'custom', 'category_value' => '31387' ), array( '_ebay_export_category_id' => '31387', '_ebay_export_category_typed' => '31387', '_ebay_export_category_name' => 'Wristwatches' ) );
check( '  counted as already so', array( said()['fields']['category']['same'], said()['fields']['category']['changed'], said()['changed'] ), array( 1, 0, 0 ) );
same_as_tab( 'category from the list, the one it has: left alone, its name kept', $base + array( '_ebay_export_category_id' => '183454', '_ebay_export_category_name' => 'CCG Individual Cards' ), array(), array( 'category' => '183454' ), array( '_ebay_export_category_id' => '183454', '_ebay_export_category_typed' => '', '_ebay_export_category_name' => 'CCG Individual Cards' ) );
fresh();
product( 1001, array( '_ebay_export_category_id' => '183454', '_ebay_export_category_typed' => '183454', '_ebay_export_category_name' => 'Typed name' ) );
bulk( array( 1001 ), array( 'category' => '183454' ) );
check( 'the ID it has, typed before, now chosen from the list: chosen from the list, its name cleared, as the tab clears it when its select changes', array( m( 1001, '_ebay_export_category_id' ), m( 1001, '_ebay_export_category_typed' ), m( 1001, '_ebay_export_category_name' ), said()['fields']['category']['changed'] ), array( '183454', '', '', 1 ) );

// L3: Auction only where the product's duration after the edit is an
// auction's; skipped and said otherwise.
fresh();
product( 1002, array( '_ebay_export_listing_type' => 'FixedPriceItem', '_ebay_export_listing_duration' => 'GTC' ) );
product( 1003, array() );
product( 1004, array( '_ebay_export_listing_duration' => 'Days_7' ) );
bulk( array( 1002, 1003, 1004 ), array( 'listing_type' => 'Chinese' ) );
check( 'Auction alone: skipped where the duration would be Good \'Til Cancelled (its own, or the shop\'s), set where it is 7 days', array( m( 1002, '_ebay_export_listing_type' ), m( 1003, '_ebay_export_listing_type' ), m( 1004, '_ebay_export_listing_type' ), said()['fields']['listing_type']['skipped'] ), array( 'FixedPriceItem', null, 'Chinese', array( 'auction_duration' => array( 'n' => 2, 'ids' => array( 1002, 1003 ) ) ) ) );
check( '  said in words', false !== strpos( notice(), 'Listing type: Auction on 1 product; skipped on 2 - its listing duration would not be one an auction takes (1, 3, 5, 7 or 10 days): set Listing duration in the same edit: #1002, #1003.' ), true );
bulk( array( 1002, 1003 ), array( 'listing_type' => 'Chinese', 'listing_duration' => 'Days_3' ) );
check( '  with Listing duration 3 days in the same edit: both set', array( m( 1002, '_ebay_export_listing_type' ), m( 1002, '_ebay_export_listing_duration' ), m( 1003, '_ebay_export_listing_type' ), m( 1003, '_ebay_export_listing_duration' ) ), array( 'Chinese', 'Days_3', 'Chinese', 'Days_3' ) );
product( 1005, array( '_ebay_export_listing_duration' => 'Days_5' ) );
bulk( array( 1005 ), array( 'listing_type' => 'Chinese', 'listing_duration' => '_shop' ) );
check( '  with the duration cleared in the same edit, so the shop\'s Good \'Til Cancelled: skipped, the duration cleared', array( m( 1005, '_ebay_export_listing_type' ), m( 1005, '_ebay_export_listing_duration' ), said()['fields']['listing_type']['skipped'] ), array( null, '', array( 'auction_duration' => array( 'n' => 1, 'ids' => array( 1005 ) ) ) ) );
product( 1006, array() );
TCGiant_Sync_OAuth::$settings['export_listing_duration'] = 'Days_10';
bulk( array( 1006 ), array( 'listing_type' => 'Chinese' ) );
unset( TCGiant_Sync_OAuth::$settings['export_listing_duration'] );
check( '  a product with no duration of its own, in a shop whose duration is 10 days: set', m( 1006, '_ebay_export_listing_type' ), 'Chinese' );
product( 1007, array() );
bulk( array( 1007 ), array( 'listing_type' => 'Chinese', 'listing_duration' => 'GTC' ) );
check( '  Auction with Good \'Til Cancelled in the same edit: the format skipped, the duration set for the format it keeps', array( m( 1007, '_ebay_export_listing_type' ), m( 1007, '_ebay_export_listing_duration' ), said()['fields']['listing_type']['skipped'] ), array( null, 'GTC', array( 'auction_duration' => array( 'n' => 1, 'ids' => array( 1007 ) ) ) ) );
product( 1008, array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) );
bulk( array( 1008 ), array( 'listing_type' => 'FixedPriceItem' ) );
check( '  Fixed Price, which takes any duration (the push sends Good \'Til Cancelled): never skipped for it', array( m( 1008, '_ebay_export_listing_type' ), said()['fields']['listing_type']['skipped'] ), array( 'FixedPriceItem', array() ) );

// L2: 30 Days is no choice.
fresh();
product( 1009, array( '_ebay_export_listing_duration' => 'GTC' ) );
bulk( array( 1009 ), array( 'listing_duration' => 'Days_30' ) );
check( '30 Days, which no format takes: not one of its choices, refused and said', array( m( 1009, '_ebay_export_listing_duration' ), said()['refused'] ), array( 'GTC', array( 'listing_duration' => array( 'choice', '' ) ) ) );

// L4: a clear named as what it is.
fresh();
product( 1012, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_listing_type' => 'Chinese' ) );
bulk( array( 1012 ), array( 'item_type' => '_shop', 'listing_type' => '_shop' ) );
$words = notice();
check( 'the notice names a clear as what it is: the item type none, the format the product\'s own cleared', array( false !== strpos( $words, 'Item type: none (cleared) on 1 product.' ), false !== strpos( $words, 'Listing type: the product\'s own cleared on 1 product.' ) ), array( true, true ) );

// L5 (review 1's K05): the eBay price of a variable product, Set or Clear.
fresh();
product( 1010, array( '_ebay_export_price' => '9.99' ), true );
bulk( array( 1010 ), array( 'ebay_price' => 'clear' ) );
check( 'eBay price Clear on a variable product: skipped and said, as Set is (it has no eBay price of its own); a stray value left as it is', array( m( 1010, '_ebay_export_price' ), said()['fields']['ebay_price']['skipped'] ), array( '9.99', array( 'variable_price' => array( 'n' => 1, 'ids' => array( 1010 ) ) ) ) );

// L7, review 1's K04: a typed value goes to the save still slashed, so the
// save unslashes it once, as the tab's does, and WordPress's meta save once
// more, as for the tab: a single backslash typed is lost either way, a double
// one stored as one. Handed over unslashed already, a double one was lost too.
same_as_tab( 'subtitle: a single backslash lost and a double one stored as one, as the tab stores them', $base, array( '_ebay_export_subtitle' => 'Ash\\Pikachu C:\\\\cards' ), array( 'subtitle' => 'set', 'subtitle_value' => 'Ash\\Pikachu C:\\\\cards' ), array( '_ebay_export_subtitle' => 'AshPikachu C:\\cards' ) );
same_as_tab( 'MPN: a double backslash stored as one, as the tab stores it', $base, array( '_ebay_export_mpn' => 'AB\\\\12' ), array( 'mpn' => 'set', 'mpn_value' => 'AB\\\\12' ), array( '_ebay_export_mpn' => 'AB\\12' ) );

// L7, review 1's K06: a value refused, with nothing skipped or denied, is a warning.
fresh();
product( 1011, array() );
bulk( array( 1011 ), array( 'subtitle' => 'set', 'subtitle_value' => '   ' ) );
check( 'a value refused, nothing skipped or denied: a warning notice, not a success', ( function () { ob_start(); TCGiant_Sync_Bulk_Edit::bulk_edit_notice(); $h = (string) ob_get_clean(); return array( false !== strpos( $h, 'notice-warning' ), false !== strpos( $h, 'notice-success' ) ); } )(), array( true, false ) );

// -----------------------------------------------------------------------------
echo "\n== The shared rules\n";

check( 'Listing options typed(): a field as save_posted() keeps it', array( TCGiant_Sync_Listing_Options::typed( 'subtitle', '  a \\"b\\"  ' ), TCGiant_Sync_Listing_Options::typed( 'buy_it_now', '12.5' ), TCGiant_Sync_Listing_Options::typed( 'reserve', '-1' ), TCGiant_Sync_Listing_Options::typed( 'second_category', '0183' ), TCGiant_Sync_Listing_Options::typed( 'condition_note', "a\r\nb" ) ), array( 'a "b"', '12.50', '', '183', "a\nb" ) );
check( 'eBay price typed(): as set_own() keeps it', array( TCGiant_Sync_Ebay_Price::typed( '12.5' ), TCGiant_Sync_Ebay_Price::typed( '0' ), TCGiant_Sync_Ebay_Price::typed( 'x' ), TCGiant_Sync_Ebay_Price::typed( '' ) ), array( '12.50', '', '', '' ) );
product( 970, array( '_ebay_export_listing_type' => '' ) );
product( 971, array(), true );
check( 'auction_fields_shown(): its own format, else the shop\'s, or a recorded auction; never a variable product', array( TCGiant_Sync_Listing_Options::auction_fields_shown( wc_get_product( 970 ), 'Chinese' ), TCGiant_Sync_Listing_Options::auction_fields_shown( wc_get_product( 970 ), 'FixedPriceItem' ), TCGiant_Sync_Listing_Options::auction_fields_shown( wc_get_product( 971 ), 'Chinese' ) ), array( true, false, false ) );
check( 'plain_conditions(): the tab\'s list, the media-only four left out', array_keys( TCGiant_Sync_Catalog::plain_conditions() ), array( 1000, 1500, 1750, 2000, 2500, 3000, 7000 ) );
check( '  the tab draws its Condition select from it', substr_count( $admin_src, "array( '' => __( '-- use the store default --', 'tcgiant-sync' ) ) + TCGiant_Sync_Catalog::plain_conditions();" ), 1 );
$lo_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-listing-options.php' );
check( 'the tab\'s section shows Buy It Now and reserve by the same rule', substr_count( $lo_src, '$auction    = self::auction_fields_shown( $product, $default_format );' ), 1 );
check( 'uninstall: nothing new kept; the notice\'s record is one of the plugin\'s transients, which it sweeps', array( 0 === strpos( TCGiant_Sync_Bulk_Edit::SAID, 'tcgiant_' ), false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ), "WHERE option_name LIKE '\\_transient\\_tcgiant\\_%'" ) ), array( true, true ) );

done();
