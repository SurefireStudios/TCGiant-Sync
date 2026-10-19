<?php
/**
 * Harness: the sync links a product to the listing its unfinished push made.
 *
 * A push the host kills after eBay has created the listing leaves the product
 * saying its push did not finish, and telling the shop to Link it to that
 * listing - or, for a product linked to a listing that had ended, to Unlink
 * that one and Link the new one. But the push sent the product's SKU, and the
 * next delta reads the new listing and gives it to the product by that SKU,
 * as Link would have. 3.27.4's first candidate left the product saying the
 * push did not finish all the same: its notice told the shop to use a Link a
 * linked product is not offered, or to Unlink a listing it had already left;
 * Clear was refused; and Auto-Relist, Relist and the bulk pushes left it out
 * for good, so when that listing ended it was never relisted.
 *
 * Loaded for real: TCGiant_Sync_Mapper::save_as_product() (the delta's, the
 * full sync's and a single listing's save of a listing), TCGiant_Sync_Exporter
 * (the push's death, push_progress(), Action Scheduler giving up on a push,
 * relist_ended_listing()), TCGiant_Sync_Listing_Link, TCGiant_Sync_Runtime and
 * TCGiant_Sync_Catalog. WordPress, WooCommerce and Action Scheduler are played
 * here, from one array of post meta.
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ . '/' );
define( 'TCGIANT_SYNC_VERSION', '3.27.4' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-84s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 160 ) );
}

// --- WordPress ---------------------------------------------------------------

$META    = array();
$POSTS   = array();
$OPTIONS = array();
$LOG     = array();
$ACTIONS = array();

function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['META'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['META'][ (int) $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['META'][ (int) $id ][ $key ] ); return true; }
function get_post_status( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['status'] ?? false; }
function get_post_type( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['type'] ?? false; }
function get_post_field( $field, $id ) { return 'post_title' === $field ? ( $GLOBALS['POSTS'][ (int) $id ]['title'] ?? '' ) : ''; }
function wp_get_post_parent_id( $id ) { return 0; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['OPTIONS'][ $name ] ); return true; }
function delete_transient( $name ) { return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function do_action() {}
function add_action() { return true; }
function add_filter() { return true; }
function __( $text, $domain = null ) { return $text; }
function sanitize_title( $text ) { return strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $text ) ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wc_attribute_label( $name, $product = null ) { return $name; }
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/** What save_as_product() asks of the database itself: the product holding an item number. */
class Harness_wpdb {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_row( $query ) {
		if ( false === strpos( $query[0], "pm.meta_key = '_ebay_item_id' AND pm.meta_value = %s" ) ) {
			throw new RuntimeException( 'harness wpdb: a query it does not know: ' . $query[0] );
		}
		foreach ( $GLOBALS['META'] as $id => $meta ) {
			if ( (string) ( $meta['_ebay_item_id'] ?? '' ) === (string) $query[1][0] && 'product' === get_post_type( $id ) ) {
				return (object) array( 'post_id' => (string) $id, 'post_status' => get_post_status( $id ) );
			}
		}
		return null;
	}
}
$wpdb = new Harness_wpdb();

// --- WooCommerce -------------------------------------------------------------

function wc_get_product_id_by_sku( $sku ) {
	foreach ( $GLOBALS['META'] as $id => $meta ) {
		if ( '' !== (string) $sku && (string) ( $meta['_sku'] ?? '' ) === (string) $sku ) {
			return (int) $id;
		}
	}
	return 0;
}

/**
 * A product, as WooCommerce holds one: its meta read when it is loaded, kept
 * in the object, and written back - only what changed - when it is saved.
 */
class WC_Product {
	protected $id;
	protected $meta    = array();
	protected $changed = array();
	public function __construct( $id = 0 ) { $this->id = (int) $id; $this->meta = $GLOBALS['META'][ $this->id ] ?? array(); }
	public function get_id() { return $this->id; }
	public function get_name() { return get_post_field( 'post_title', $this->id ); }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; $this->changed[ $key ] = true; }
	public function delete_meta_data( $key ) { if ( array_key_exists( $key, $this->meta ) ) { $this->meta[ $key ] = null; $this->changed[ $key ] = true; } }
	public function set_sku( $sku ) { $this->update_meta_data( '_sku', $sku ); }
	public function get_attributes() { return array(); }
	public function is_type( $type ) { return 'simple' === $type; }
	public function is_in_stock() { return true; }
	public function managing_stock() { return true; }
	public function get_stock_quantity() { return 1; }
	public function save() {
		// The request dies in this save, once: memory, the host, or a hook that throws.
		if ( ! empty( $GLOBALS['SAVE_DIES'] ) ) {
			$GLOBALS['SAVE_DIES'] = false;
			$this->changed        = array();
			throw new RuntimeException( 'Allowed memory size exhausted (the request dies in the save)' );
		}
		foreach ( array_keys( $this->changed ) as $key ) {
			if ( null === $this->meta[ $key ] ) {
				unset( $GLOBALS['META'][ $this->id ][ $key ], $this->meta[ $key ] );
			} else {
				$GLOBALS['META'][ $this->id ][ $key ] = $this->meta[ $key ];
			}
		}
		$this->changed = array();
		return $this->id;
	}
	// Every other setter: what the sync writes there is not this harness's business.
	public function __call( $name, $args ) { return null; }
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variable extends WC_Product {}
class WC_Product_Attribute {
	public function __call( $name, $args ) { return null; }
}
function wc_get_product( $id ) { return isset( $GLOBALS['POSTS'][ (int) $id ] ) ? new WC_Product( $id ) : false; }

// --- Action Scheduler, as far as giving up on a push goes ----------------------

class Harness_Action {
	private $row;
	public function __construct( array $row ) { $this->row = $row; }
	public function get_hook() { return $this->row['hook'] ?? ''; }
	public function get_args() { return $this->row['args'] ?? array(); }
}
class ActionScheduler_Store {
	public function fetch_action( $id ) { return new Harness_Action( $GLOBALS['ACTIONS'][ (int) $id ] ?? array() ); }
}
class ActionScheduler {
	public static function store() { return new ActionScheduler_Store(); }
}

// --- The plugin's other classes, as far as these paths go ----------------------

class TCGiant_Sync_OAuth {
	public static function instance() { return new self(); }
	public function get_settings() { return array(); }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS = array();
	public static function begin_ebay_origin() {}
	public static function end_ebay_origin() {}
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
}
/** eBay, which this harness does not ask: a relist that gets as far as asking it is not refused. */
class TCGiant_Sync_API {
	public static function instance() { return new self(); }
	public function get_item( $item_id ) { return new WP_Error( 'harness', 'eBay is not asked here' ); }
}
class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = $message; }
	public static function warning( $message ) { $GLOBALS['LOG'][] = $message; }
	public static function error( $message ) { $GLOBALS['LOG'][] = $message; }
	public static function write_now( $message, $level = 'error' ) { $GLOBALS['LOG'][] = $message; }
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
// The mapper's save and the push read the listing's eBay site through it.
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-exporter.php';
require $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require $ROOT . '/includes/class-tcgiant-sync-mapper.php';

/** A product of the shop's, with this SKU and this meta. */
function shop_product( $id, $sku, array $meta = array() ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => 'product', 'status' => 'publish', 'title' => 'Front brake caliper ' . $id );
	$GLOBALS['META'][ $id ]  = array_merge( array( '_sku' => $sku ), $meta );
}

/** What map_ebay_to_woo() hands the save for a simple fixed-price listing. */
function listing( $item_id, $sku, $status = 'Active' ) {
	return array(
		'title'                 => 'Front brake caliper',
		'description'           => 'Tested and working.',
		'sku'                   => $sku,
		'sku_source'            => 'seller',
		'stock_quantity'        => 1,
		'weight'                => '',
		'length'                => '',
		'width'                 => '',
		'height'                => '',
		'regular_price'         => '49.99',
		'sale_price'            => '',
		'store_categories'      => array(),
		'primary_category_name' => '',
		'tags'                  => array(),
		'attributes'            => array(),
		'variations'            => array(),
		'bin_location'          => '',
		'images'                => array(),
		'meta'                  => array(
			'_ebay_item_id'        => $item_id,
			'_ebay_listing_status' => $status,
			'_ebay_listing_type'   => 'FixedPriceItem',
		),
	);
}

/** The delta's save of a listing. */
function sync_saves( $item_id, $sku, $status = 'Active' ) {
	$GLOBALS['LOG'] = array();
	$data           = listing( $item_id, $sku, $status );
	return ( new TCGiant_Sync_Mapper() )->save_as_product( $data );
}

/** The push as process_single_push() holds it, while it runs. */
function pushing( $product_id, $linked, $created ) {
	update_post_meta( $product_id, '_ebay_export_status', 'pushing' );
	update_post_meta( $product_id, '_ebay_export_pushing_since', time() - 60 );
	$in_flight = new ReflectionProperty( 'TCGiant_Sync_Exporter', 'in_flight' );
	$in_flight->setAccessible( true );
	$in_flight->setValue( null, array(
		'product_id' => $product_id,
		'title'      => get_post_field( 'post_title', $product_id ),
		'linked'     => $linked,
		'ended'      => '' !== $linked,
		'created'    => $created,
	) );
}

/** PHP kills that push after eBay made $created: what the dying request leaves. */
function push_died( $product_id, $linked, $created ) {
	pushing( $product_id, $linked, $created );
	TCGiant_Sync_Exporter::on_push_death( 'Push stopped by a PHP fatal error' );
}

function meta( $id, $key ) { return $GLOBALS['META'][ (int) $id ][ $key ] ?? null; }

function relist( $product_id ) {
	$result = ( new ReflectionClass( 'TCGiant_Sync_Exporter' ) )->newInstanceWithoutConstructor()->relist_ended_listing( $product_id, 'Auto-Relist' );
	return is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'relisted';
}

function logged( $words ) { return count( preg_grep( '/' . preg_quote( $words, '/' ) . '/', $GLOBALS['LOG'] ) ); }

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA FIRST PUSH KILLED AFTER EBAY MADE THE LISTING, AND THE SYNC THEN SEES IT\n" . str_repeat( '=', 124 ) . "\n";

shop_product( 4717, 'GC-4717' );
push_died( 4717, '', '297600000001' );
check( 'the push died: not linked, and says it did not finish', array( meta( 4717, '_ebay_item_id' ), meta( 4717, '_ebay_export_status' ), TCGiant_Sync_Exporter::push_progress( 4717 ) ), array( null, 'failed', 'failed' ) );
check( '  telling the shop to Link it', false !== strpos( (string) meta( 4717, '_ebay_export_error' ), 'Use Link on the product with that item number' ), true );

// The next delta reads the new listing, which carries the SKU the push sent.
check( 'the delta saves that listing onto the product, by its SKU', sync_saves( '297600000001', 'GC-4717' ), 4717 );
check( '  linked to the listing its push made', meta( 4717, '_ebay_item_id' ), '297600000001' );
check( '  and that push is complete: pushed, its warning gone', array( meta( 4717, '_ebay_export_status' ), meta( 4717, '_ebay_export_error' ), TCGiant_Sync_Exporter::push_progress( 4717 ) ), array( 'pushed', '', '' ) );
check( '  dated as pushed, so "Remove sold products" keeps it', '' !== (string) meta( 4717, '_ebay_export_last_pushed' ), true );
check( '  no longer saying when a push began', meta( 4717, '_ebay_export_pushing_since' ), null );
check( '  and the log says so', logged( 'WC #4717 was linked by the sync to eBay listing 297600000001, by its SKU, while its last push said it had not finished' ), 1 );

// Months on, that listing ends; Auto-Relist and bulk Relist ask this.
update_post_meta( 4717, '_ebay_listing_status', 'Ended' );
check( 'its listing ended: Auto-Relist no longer refuses it as a push that did not finish', relist( 4717 ), 'relist_unchecked: could not ask eBay whether listing 297600000001 sold (eBay is not asked here), so it was not relisted' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA RELIST BY PUSH: THE LINKED LISTING HAD ENDED, THE PUSH MADE ITS REPLACEMENT AND WAS KILLED\n" . str_repeat( '=', 124 ) . "\n";

shop_product( 4718, 'GC-4718', array( '_ebay_item_id' => '297500000009', '_ebay_listing_status' => 'Ended', '_ebay_export_last_pushed' => '2026-06-01 10:00:00' ) );
push_died( 4718, '297500000009', '297600000002' );
check( 'the push died: still linked to the ended listing, told to Unlink it', array( meta( 4718, '_ebay_export_status' ), false !== strpos( (string) meta( 4718, '_ebay_export_error' ), 'Unlink it from listing 297500000009' ) ), array( 'failed', true ) );

// The ended listing comes by first, matched by its item number: nothing is
// linked anew, so nothing is complete.
check( 'the delta saves the ended listing: matched by item number, the same product', sync_saves( '297500000009', 'GC-4718', 'Ended' ), 4718 );
check( '  which still says its push did not finish', array( meta( 4718, '_ebay_export_status' ), TCGiant_Sync_Exporter::push_progress( 4718 ) ), array( 'failed', 'failed' ) );

check( 'then the replacement: the product is re-linked to it, by its SKU', array( sync_saves( '297600000002', 'GC-4718' ), meta( 4718, '_ebay_item_id' ) ), array( 4718, '297600000002' ) );
check( '  and that push is complete: pushed, its warning gone', array( meta( 4718, '_ebay_export_status' ), meta( 4718, '_ebay_export_error' ), TCGiant_Sync_Exporter::push_progress( 4718 ) ), array( 'pushed', '', '' ) );
check( '  its first push\'s date kept', meta( 4718, '_ebay_export_last_pushed' ), '2026-06-01 10:00:00' );
check( '  and the log says so', logged( 'WC #4718 was linked by the sync to eBay listing 297600000002' ), 1 );

// The re-link writes the new item number straight to the table, ahead of the
// product's save. Completed only by that save, a save that died left the
// product linked to the replacement and still saying its push did not finish;
// the next sync found it by that item number, not by its SKU, so nothing
// completed the push, and when that listing ended Auto-Relist refused it.
//
// An imported product, relisted by a push: it has no last-pushed date.
shop_product( 4722, 'GC-4722', array( '_ebay_item_id' => '297500000012', '_ebay_listing_status' => 'Ended' ) );
push_died( 4722, '297500000012', '297600000012' );
$SAVE_DIES = true;
try {
	sync_saves( '297600000012', 'GC-4722' );
	$died = '';
} catch ( RuntimeException $e ) {
	$died = $e->getMessage();
}
check( 'the re-link to the replacement dies in the product\'s save', array( '' !== $died, meta( 4722, '_ebay_item_id' ) ), array( true, '297600000012' ) );
check( '  the push is complete all the same: pushed, its warning gone, not pushing', array( meta( 4722, '_ebay_export_status' ), meta( 4722, '_ebay_export_error' ), meta( 4722, '_ebay_export_pushing_since' ), TCGiant_Sync_Exporter::push_progress( 4722 ) ), array( 'pushed', '', null, '' ) );
check( '  dated as pushed, so "Remove sold products" keeps it', '' !== (string) meta( 4722, '_ebay_export_last_pushed' ), true );
check( 'the next sync finds it by that item number: still pushed', array( sync_saves( '297600000012', 'GC-4722' ), meta( 4722, '_ebay_export_status' ), TCGiant_Sync_Exporter::push_progress( 4722 ) ), array( 4722, 'pushed', '' ) );
update_post_meta( 4722, '_ebay_listing_status', 'Ended' );
check( '  and when that listing ends, Auto-Relist does not refuse it as a push that did not finish', relist( 4722 ), 'relist_unchecked: could not ask eBay whether listing 297600000012 sold (eBay is not asked here), so it was not relisted' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nKILLED OUTRIGHT: THE SYNC LINKS IT BEFORE ACTION SCHEDULER GIVES UP ON THE PUSH\n" . str_repeat( '=', 124 ) . "\n";

// A host that kills the request from outside runs no shutdown function, so
// the product goes on saying pushing until Action Scheduler fails the action
// five minutes on - and the delta can read the new listing before then.
shop_product( 4719, 'GC-4719' );
pushing( 4719, '', '297600000003' );
$in_flight = new ReflectionProperty( 'TCGiant_Sync_Exporter', 'in_flight' );
$in_flight->setAccessible( true );
$in_flight->setValue( null, null ); // That request is gone.
check( 'the delta saves the listing that push made onto the product, by its SKU', array( sync_saves( '297600000003', 'GC-4719' ), meta( 4719, '_ebay_item_id' ) ), array( 4719, '297600000003' ) );
check( '  and that push is complete: pushed, no longer pushing', array( meta( 4719, '_ebay_export_status' ), meta( 4719, '_ebay_export_pushing_since' ), TCGiant_Sync_Exporter::push_progress( 4719 ) ), array( 'pushed', null, '' ) );

$ACTIONS[77] = array( 'hook' => TCGiant_Sync_Exporter::PUSH_ACTION, 'args' => array( 'product_id' => 4719 ) );
$LOG         = array();
( new ReflectionClass( 'TCGiant_Sync_Exporter' ) )->newInstanceWithoutConstructor()->on_push_action_failed( 77, 300 );
check( 'Action Scheduler then gives up on the push: the product still says pushed', array( meta( 4719, '_ebay_export_status' ), meta( 4719, '_ebay_export_error' ) ), array( 'pushed', '' ) );
check( '  and nothing says it did not finish', logged( 'did not finish' ), 0 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PRODUCT WITH NO UNFINISHED PUSH IS LINKED AS BEFORE\n" . str_repeat( '=', 124 ) . "\n";

// An imported product whose listing was relisted on eBay: re-linked by its
// SKU, and still an imported product - "Remove sold products" goes by that.
shop_product( 4720, 'GC-4720', array( '_ebay_item_id' => '297500000010', '_ebay_listing_status' => 'Ended' ) );
check( 'an imported product, re-linked to its relist by its SKU', array( sync_saves( '297600000010', 'GC-4720' ), meta( 4720, '_ebay_item_id' ) ), array( 4720, '297600000010' ) );
check( '  says nothing of a push, and is not dated as pushed', array( meta( 4720, '_ebay_export_status' ), meta( 4720, '_ebay_export_last_pushed' ) ), array( null, null ) );
check( '  and the log says nothing of one', logged( 'by its SKU, while its last push' ), 0 );

// One eBay refused: the push made nothing, and its error stays, as Link leaves it.
shop_product( 4721, 'GC-4721', array( '_ebay_export_status' => 'error', '_ebay_export_error' => 'Item specifics missing: Brand.' ) );
check( 'one whose push eBay refused, linked by its SKU', array( sync_saves( '297600000011', 'GC-4721' ), meta( 4721, '_ebay_item_id' ) ), array( 4721, '297600000011' ) );
check( '  keeps what eBay said', array( meta( 4721, '_ebay_export_status' ), meta( 4721, '_ebay_export_error' ), meta( 4721, '_ebay_export_last_pushed' ) ), array( 'error', 'Item specifics missing: Brand.', null ) );

// Link on the product screen does the same, through the same helper.
$admin = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
preg_match( '/public function ajax_link_ebay\(\) \{(.*?)\n\t\}/s', $admin, $link );
check( 'Link completes a push that did not finish the same way, before its save', 1 === preg_match( '/TCGiant_Sync_Listing_Link::complete_unfinished_push\( \$product \);.*\$product->save\(\);/s', $link[1] ?? '' ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
