<?php
/**
 * Harness: the push switch, the Settings forms, and the tokens in the page
 * (3.28.0).
 *
 * Until 3.28.0:
 *
 *  - "Reduce WooCommerce stock on eBay sale?" saved as No - its default -
 *    also switched off every WooCommerce -> eBay stock push, so an item sold
 *    on the website stayed for sale on eBay;
 *  - each Settings form replaced the whole option with what it posted, and
 *    carried the other form's settings back in hidden fields. One missed was
 *    reset: saving Push to eBay Settings lost the import category filter,
 *    Host images on eBay, image size and log detail; saving Import Settings
 *    lost Best Offer and GPSR; both lost last_order_import and
 *    last_reconciliation;
 *  - those hidden fields held the eBay tokens and the relay's key, in the
 *    page, and a page opened before a reconnect put the old tokens back.
 *
 * Loaded for real: TCGiant_Sync_Inventory, TCGiant_Sync_Admin (the settings
 * sanitiser, the notice and its dismissal, the dashboard's health, Disconnect),
 * admin/views/settings.php - rendered, and each form submitted the way a
 * browser submits it, through options.php - the catalogue, the API (down to
 * the HTTP call, answered in eBay's own envelope), the site filter, the
 * environment, runtime, variations, Link, mapper, importer, cron's order
 * sync, order import, the usage ping and 3.27.8's WP-Lister converter (its
 * check that WP-Lister for eBay is active). WordPress's hooks are its own, and
 * its options behave as WordPress's do, each request with its own copy of
 * them (tests/support/settings-world.php).
 * WooCommerce and Action Scheduler are played: a queued push runs through the
 * hook the plugin registered, with its arguments in order.
 *
 * Three children run the same world in processes of their own: one with the
 * release switch FLIP_LEGACY_ZERO false (a copy of the class with only that
 * changed), one that loads shops this build upgraded into such a copy (a
 * later release that changes the switch), and one on a staging copy
 * (TCGIANT_SYNC_IS_STAGING). More run with WP-Lister for eBay loaded, as
 * WordPress loads an active plugin - a site's, or a multisite network's -
 * or with it deactivated since, as a later request finds it.
 *
 * @package TCGiant_Sync
 */

$MODE = isset( $argv[1] ) ? (string) $argv[1] : '';

if ( in_array( $MODE, array( 'child=fallback', 'child=redecide', 'child=wplister-fallback', 'child=wplister-redecide' ), true ) ) {
	$tcg_src  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-inventory.php' );
	$tcg_copy = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tcg-push-switch-fallback-' . getmypid() . '.php';
	file_put_contents( $tcg_copy, str_replace( 'const FLIP_LEGACY_ZERO = true;', 'const FLIP_LEGACY_ZERO = false;', $tcg_src ) );
	$GLOBALS['TCG_INVENTORY_FILE'] = $tcg_copy;
	$GLOBALS['TCG_FLIP_ANCHORS']   = substr_count( $tcg_src, 'const FLIP_LEGACY_ZERO = true;' );
}
if ( 'child=staging' === $MODE ) {
	define( 'TCGIANT_SYNC_IS_STAGING', true );
}
if ( 'child=oauth' === $MODE ) {
	$GLOBALS['TCG_REAL_OAUTH'] = true;   // The real TCGiant_Sync_OAuth (tests/support/settings-world.php).
}

require __DIR__ . '/support/settings-world.php';

if ( isset( $tcg_copy ) ) {
	unlink( $tcg_copy );
}

define( 'ARRAY_A', 'ARRAY_A' );

$W = &$GLOBALS['W'];
$W['posts']    = array();
$W['queue']    = array();
$W['wire']     = array();
$W['writes']   = array();
$W['orders']   = array();
$W['on_call']  = array();
$W['ebay']     = array();
$W['cron']     = array();
$W['cleared']  = array();
$W['pings']    = array();
$W['screen']   = 'dashboard';
$W['terms']    = 'some';
$W['context']  = 'admin';
$W['next_id']  = 9000;

// --- what the Settings view and the admin print with ----------------------------

function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function _e( $text, $domain = null ) { echo $text; }
function selected( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) { echo $out; } return $out; }
function checked( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; }
function settings_fields( $group ) {
	echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '"><input type="hidden" name="action" value="update"><input type="hidden" name="_wpnonce" value="nonce-' . esc_attr( $group ) . '-options">';
}
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$out = '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce-' . esc_attr( $action ) . '">' . ( $referer ? '<input type="hidden" name="_wp_http_referer" value="/wp-admin/index.php">' : '' );
	if ( $echo ) {
		echo $out;
	}
	return $out;
}
function wp_create_nonce( $action = -1 ) { return 'nonce-' . $action; }
function wp_nonce_url( $url, $action = -1 ) { return $url . '&_wpnonce=nonce-' . $action; }
function sanitize_textarea_field( $text ) { return trim( strip_tags( (string) $text ) ); }
/** The shop's WooCommerce categories: three, none, or get_terms() failing. */
function get_terms( $args = array() ) {
	if ( 'error' === $GLOBALS['W']['terms'] ) {
		return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
	}
	if ( 'none' === $GLOBALS['W']['terms'] ) {
		return array();
	}
	$out = array();
	foreach ( array( 7 => 'Coins', 9 => 'Keep forever', 11 => 'Cards' ) as $id => $name ) {
		$out[] = (object) array( 'term_id' => $id, 'name' => $name );
	}
	return $out;
}
function get_current_screen() { return null === $GLOBALS['W']['screen'] ? null : (object) array( 'id' => $GLOBALS['W']['screen'] ); }
function human_time_diff( $from, $to = 0 ) { return abs( (int) $to - (int) $from ) . ' seconds'; }
function is_admin() { return 'admin' === $GLOBALS['W']['context']; }
function wp_doing_cron() { return 'cron' === $GLOBALS['W']['context']; }

class Harness_Redirect extends Exception {
	public $to;
	public function __construct( $to ) { parent::__construct( 'redirect' ); $this->to = $to; }
}
class Harness_Died extends Exception {}
class Harness_Sent extends Exception {
	public $payload;
	public function __construct( $p ) { parent::__construct( 'sent' ); $this->payload = $p; }
}
function wp_safe_redirect( $to ) { throw new Harness_Redirect( $to ); }
function wp_die( $message = '' ) { throw new Harness_Died( (string) $message ); }
function check_admin_referer( $action = -1, $name = '_wpnonce' ) {
	if ( ( $_REQUEST[ $name ] ?? '' ) !== 'nonce-' . $action ) {
		throw new Harness_Died( 'The link you followed has expired.' );
	}
	return 1;
}
function check_ajax_referer() { return 1; }
function wp_send_json_success( $d = null ) { throw new Harness_Sent( array( 'success' => true, 'data' => $d ) ); }
function wp_send_json_error( $d = null ) { throw new Harness_Sent( array( 'success' => false, 'data' => $d ) ); }
function wp_get_referer() { return $_REQUEST['_wp_http_referer'] ?? false; }

// --- the rest of WordPress these paths touch ------------------------------------

function wp_rand( $min = 0, $max = 0 ) { return (int) $min; }
function wp_convert_hr_to_bytes( $v ) { return 536870912; }
function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) { return 'U' === $format ? (string) strtotime( $date . ' UTC' ) : $date; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function sanitize_title( $title ) { return trim( preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( trim( (string) $title ) ) ), '-' ); }
function taxonomy_exists( $t ) { return false; }
function wc_attribute_label( $name ) { return $name; }
function get_term_by() { return false; }
function has_term() { return false; }
function wp_get_post_terms() { return array(); }
function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['W']['cron'][ $hook ] ?? false; }
function wp_get_schedule( $hook, $args = array() ) { return isset( $GLOBALS['W']['cron'][ $hook ] ) ? 'tcgiant_hourly' : false; }
function wp_schedule_event( $when, $recurrence, $hook, $args = array() ) { $GLOBALS['W']['cron'][ $hook ] = (int) $when; return true; }
function wp_clear_scheduled_hook( $hook, $args = array() ) { unset( $GLOBALS['W']['cron'][ $hook ] ); $GLOBALS['W']['cleared'][] = $hook; return 0; }
function wp_schedule_single_event( $when, $hook, $args = array() ) { $GLOBALS['W']['cron'][ $hook ] = (int) $when; return true; }
function add_query_arg( $args, $url ) {
	$pairs = array();
	foreach ( (array) $args as $k => $v ) {
		$pairs[] = $k . '=' . $v;
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $pairs );
}
function wp_load_alloptions() { return array( 'home' => $GLOBALS['W']['home'], 'siteurl' => $GLOBALS['W']['home'] ); }
function get_post_status( $id ) { return $GLOBALS['W']['posts'][ (int) $id ]['status'] ?? false; }
function get_post_type( $id ) { return $GLOBALS['W']['posts'][ (int) $id ]['type'] ?? false; }
function wp_trash_post( $id ) { $GLOBALS['W']['posts'][ (int) $id ]['status'] = 'trash'; return true; }
/** The usage ping: what it would send, never sent. */
function wp_remote_post( $url, $args ) { $GLOBALS['W']['pings'][] = array( $url, $args ); return array( 'response' => array( 'code' => 200 ), 'body' => '' ); }

/** The database, as far as these paths ask it: meta matches, and the call counter. */
class Harness_Wpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $options  = 'wp_options';
	public $prefix   = 'wp_';
	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( $args, &$i ) {
			$v = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $query );
	}
	private function matching( $query ) {
		if ( ! preg_match( "/meta_key = '([^']+)'\\s+AND (?:pm\\.)?meta_value = '([^']*)'/", $query, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $GLOBALS['W']['posts'] as $id => $post ) {
			if ( (string) ( $GLOBALS['W']['meta'][ $id ][ $m[1] ] ?? "\0" ) !== $m[2] ) {
				continue;
			}
			if ( false !== strpos( $query, "post_type = 'product'" ) && 'product' !== $post['type'] ) {
				continue;
			}
			if ( false !== strpos( $query, "post_status != 'trash'" ) && 'trash' === $post['status'] ) {
				continue;
			}
			if ( preg_match( '/post_id != (\d+)/', $query, $not ) && (int) $not[1] === (int) $id ) {
				continue;
			}
			$out[] = $id;
		}
		if ( preg_match( '/LIMIT (\d+)/', $query, $limit ) ) {
			$out = array_slice( $out, 0, (int) $limit[1] );
		}
		return $out;
	}
	public function get_var( $query ) {
		if ( false !== strpos( $query, "lt.meta_value = 'Chinese'" ) ) {
			return (string) ( $GLOBALS['W']['auctions_imported'] ?? 0 );
		}
		$row = settings_world_row( $query );
		if ( null !== $row ) {
			return $row;
		}
		$ids = $this->matching( $query );
		return $ids ? (string) $ids[0] : null;
	}
	public function get_col( $query ) { return array_map( 'strval', $this->matching( $query ) ); }
	public function get_results( $query, $output = null ) { return array(); }
	public function get_row( $query, $output = null ) { return null; }
	public function query( $query ) {
		if ( false !== strpos( $query, 'option_value + 1' ) ) {
			return 1;
		}
		// The weekly stock check's place, written straight to its row (item R).
		$rows = settings_world_sql( $query );
		return null === $rows ? 0 : $rows;
	}
	public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }
	public function delete() { return 0; }
}
$GLOBALS['wpdb'] = new Harness_Wpdb();

// --- WooCommerce ----------------------------------------------------------------

/** A product: its fields are its meta, as in test-stock-push.php. */
class WC_Product {
	protected $id;
	protected $changes = array();
	public function __construct( $id = 0 ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	protected function f( $key, $default = '' ) {
		if ( array_key_exists( $key, $this->changes ) ) {
			return $this->changes[ $key ];
		}
		return $GLOBALS['W']['meta'][ $this->id ][ $key ] ?? $default;
	}
	public function get_type() { return $GLOBALS['W']['posts'][ $this->id ]['kind'] ?? 'simple'; }
	public function is_type( $type ) { return $type === $this->get_type(); }
	public function get_parent_id() { return (int) ( $GLOBALS['W']['posts'][ $this->id ]['parent'] ?? 0 ); }
	public function get_status() { return $GLOBALS['W']['posts'][ $this->id ]['status'] ?? 'publish'; }
	public function get_name() { return (string) $this->f( '_name', 'Product #' . $this->id ); }
	public function get_meta( $key, $single = true ) { return $this->f( $key ); }
	public function update_meta_data( $key, $value ) { $this->changes[ $key ] = $value; }
	public function delete_meta_data( $key ) { $this->changes[ $key ] = null; }
	public function get_sku( $context = 'view' ) {
		$own = (string) $this->f( '_sku' );
		if ( '' === $own && 'view' === $context && $this->get_parent_id() ) {
			return (string) ( $GLOBALS['W']['meta'][ $this->get_parent_id() ]['_sku'] ?? '' );
		}
		return $own;
	}
	public function get_stock_managed_by_id() {
		if ( 'yes' === $this->f( '_manage_stock', 'no' ) || ! $this->get_parent_id() ) {
			return $this->id;
		}
		return 'yes' === ( $GLOBALS['W']['meta'][ $this->get_parent_id() ]['_manage_stock'] ?? 'no' ) ? $this->get_parent_id() : $this->id;
	}
	public function managing_stock() {
		return 'yes' === ( $GLOBALS['W']['meta'][ $this->get_stock_managed_by_id() ]['_manage_stock'] ?? 'no' )
			|| ( $this->get_stock_managed_by_id() === $this->id && 'yes' === $this->f( '_manage_stock', 'no' ) );
	}
	public function get_stock_quantity() {
		if ( ! $this->managing_stock() ) {
			return null;
		}
		$holder = $this->get_stock_managed_by_id();
		return $holder === $this->id ? (int) $this->f( '_stock', 0 ) : (int) ( $GLOBALS['W']['meta'][ $holder ]['_stock'] ?? 0 );
	}
	public function get_stock_status() { return (string) $this->f( '_stock_status', 'instock' ); }
	public function is_in_stock() { return 'outofstock' !== $this->get_stock_status(); }
	public function get_attributes() { $a = $this->f( '_attrs', array() ); return is_array( $a ) ? $a : array(); }
	public function get_children() {
		$out = array();
		foreach ( $GLOBALS['W']['posts'] as $id => $p ) {
			if ( (int) ( $p['parent'] ?? 0 ) === $this->id && 'trash' !== $p['status'] ) {
				$out[] = $id;
			}
		}
		return $out;
	}
	public function save() {
		foreach ( $this->changes as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS['W']['meta'][ $this->id ][ $key ] );
			} else {
				$GLOBALS['W']['meta'][ $this->id ][ $key ] = $value;
			}
		}
		$this->changes = array();
		return $this->id;
	}
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variable extends WC_Product {
	public static function sync( $id ) {}
}
class WC_Product_Variation extends WC_Product {}

function wc_get_product( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['W']['posts'][ $id ] ) || 'product' !== substr( $GLOBALS['W']['posts'][ $id ]['type'], 0, 7 ) ) {
		return false;
	}
	switch ( $GLOBALS['W']['posts'][ $id ]['kind'] ) {
		case 'variable':
			return new WC_Product_Variable( $id );
		case 'variation':
			return new WC_Product_Variation( $id );
		default:
			return new WC_Product_Simple( $id );
	}
}
function wc_get_product_id_by_sku( $sku ) {
	foreach ( $GLOBALS['W']['posts'] as $id => $p ) {
		if ( '' !== (string) $sku && 'trash' !== $p['status'] && (string) ( $GLOBALS['W']['meta'][ $id ]['_sku'] ?? '' ) === (string) $sku ) {
			return $id;
		}
	}
	return 0;
}
/**
 * WooCommerce's own: writes the stock of whichever product holds it, then
 * fires the stock action for that product - the one the plugin listens to in
 * order to push a change to eBay.
 */
function wc_update_product_stock( $product, $quantity = null, $operation = 'set' ) {
	$p      = is_object( $product ) ? $product : wc_get_product( $product );
	$holder = $p ? $p->get_stock_managed_by_id() : 0;
	if ( ! $p || ! isset( $GLOBALS['W']['posts'][ $holder ] ) || null === $quantity ) {
		return null;
	}
	$GLOBALS['W']['writes'][] = array( $holder, $operation, (int) $quantity, TCGiant_Sync_Inventory::is_push_suppressed() );
	$m   = &$GLOBALS['W']['meta'][ $holder ];
	$now = (int) ( $m['_stock'] ?? 0 );
	$m['_stock']        = 'set' === $operation ? (int) $quantity : ( 'decrease' === $operation ? $now - (int) $quantity : $now + (int) $quantity );
	$m['_stock_status'] = $m['_stock'] > 0 ? 'instock' : 'outofstock';
	unset( $m );
	$saved = wc_get_product( $holder );
	do_action( $saved->is_type( 'variation' ) ? 'woocommerce_variation_set_stock' : 'woocommerce_product_set_stock', $saved );
	return $saved->get_stock_quantity();
}
function wc_update_product_stock_status( $id, $status ) { $GLOBALS['W']['meta'][ (int) $id ]['_stock_status'] = $status; }

class WC_Abstract_Order {}
class WC_Order extends WC_Abstract_Order {
	private $id;
	private $items;
	public $meta = array();
	public function __construct( $id, array $items, array $meta = array() ) { $this->id = $id; $this->items = $items; $this->meta = $meta; }
	public function get_id() { return $this->id; }
	public function get_items() { return $this->items; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save_meta_data() {}
	public function save() {}
}
class WC_Order_Item_Product {
	private $id;
	private $product_id;
	private $qty;
	public $meta = array();
	public function __construct( $id, $product_id, $qty ) { $this->id = $id; $this->product_id = $product_id; $this->qty = $qty; }
	public function get_id() { return $this->id; }
	public function get_product() { return wc_get_product( $this->product_id ); }
	public function get_quantity() { return $this->qty; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function add_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ] = $value; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save_meta_data() {}
	public function save() {}
}
function wc_get_order( $id ) { return $GLOBALS['W']['orders'][ (int) $id ] ?? false; }

/** WooCommerce's wc_reduce_stock_levels(), as test-stock-push.php plays it. */
function wc_reduce_stock_levels( $order ) {
	if ( ! apply_filters( 'woocommerce_can_reduce_order_stock', true, $order ) ) {
		return;
	}
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		if ( ! $product || ! $product->managing_stock() || '' !== (string) $item->get_meta( '_reduced_stock' ) ) {
			continue;
		}
		wc_update_product_stock( $product, $item->get_quantity(), 'decrease' );
		$item->add_meta_data( '_reduced_stock', $item->get_quantity(), true );
		$item->save();
	}
	do_action( 'woocommerce_reduce_order_stock', $order );
}

// Action Scheduler: a queue, run through the hooks the plugin registered.
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	$GLOBALS['W']['queue'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'when' => time() );
	return count( $GLOBALS['W']['queue'] );
}
function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) {
	$GLOBALS['W']['queue'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'when' => (int) $when );
	return count( $GLOBALS['W']['queue'] );
}
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['W']['queue'] as $a ) {
		if ( $a['hook'] === $hook && ( null === $args || $a['args'] === $args ) ) {
			return $a['when'];
		}
	}
	return false;
}
function as_has_scheduled_action( $hook, $args = null, $group = '' ) { return false !== as_next_scheduled_action( $hook, $args, $group ); }
function as_unschedule_all_actions( $hook, $args = null, $group = '' ) {
	$GLOBALS['W']['queue'] = array_values( array_filter( $GLOBALS['W']['queue'], function ( $a ) use ( $hook ) { return $a['hook'] !== $hook; } ) );
}
function as_unschedule_action( $hook, $args = array(), $group = '' ) {
	foreach ( $GLOBALS['W']['queue'] as $i => $a ) {
		if ( $a['hook'] === $hook && $a['args'] === $args && ( '' === $group || $a['group'] === $group ) ) {
			array_splice( $GLOBALS['W']['queue'], $i, 1 );
			return $i;
		}
	}
	return null;
}

// --- the plugin's other neighbours ------------------------------------------------

class TCGiant_Sync_DB {
	public static function upsert( $row ) { return true; }
	public static function table_exists() { return false; }
	public static function table_name() { return 'wp_tcgiant_listings'; }
}
class TCGiant_Sync_Entitlements {
	public static function instance() { return new self(); }
	public function get_status_for_ui() { return array( 'is_pro' => true, 'upgrade_url' => 'https://tcgiant.example/pro', 'variant' => 'annual' ); }
	public function __call( $name, $args ) { return null; }
}
class TCGiant_Sync_License {
	public static function instance() { return new self(); }
	public function get_license_data() { return array( 'status' => 'active', 'variant' => 'annual' ); }
	public function get_active_product_count() { return 12; }
	public function get_pushed_product_count() { return 3; }
	public function get_pulled_product_count() { return 9; }
}
// What the scheduler's set-up switches off, never loaded here.
class TCGiant_Sync_Image_Localizer { public static function deactivate() {} }
class TCGiant_Sync_Photo_Cleanup { public static function deactivate() {} }
class TCGiant_Sync_Size_Trim { public static function deactivate() {} }

// --- eBay, at the HTTP call ---------------------------------------------------------

/** eBay's XML for a decoded value: a list repeats the element. */
function to_xml( $name, $value ) {
	if ( ! is_array( $value ) ) {
		return '<' . $name . '>' . esc_xml( (string) $value ) . '</' . $name . '>';
	}
	if ( array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
		return implode( '', array_map( function ( $v ) use ( $name ) { return to_xml( $name, $v ); }, $value ) );
	}
	$inner = '';
	foreach ( $value as $k => $v ) {
		$inner .= to_xml( $k, $v );
	}
	return '<' . $name . '>' . $inner . '</' . $name . '>';
}
function refused( $code, $message ) { return array( 'refuse' => array( (string) $code, $message ) ); }

/**
 * Every request is recorded and answered: GetItem from the listings in
 * $W['ebay']['items'] (a list answered in turn, the last one staying),
 * anything else from $W['ebay']['answers'][call] in turn ('__xml' is put in
 * as it is), Success when nothing is scripted. The REST calls answer the
 * order list and the inventory locations. $W['on_call'][call] runs once while
 * that call is out - a token refreshed, a setting changed.
 */
function wp_remote_request( $url, $args ) {
	$call   = $args['headers']['X-EBAY-API-CALL-NAME'] ?? '';
	$body   = (string) ( $args['body'] ?? '' );
	$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	$path   = preg_replace( '#^https://api\.ebay\.com/#', '', (string) strtok( $url, '?' ) );
	$name   = '' !== $call ? $call : $method . ' ' . $path;
	$GLOBALS['W']['wire'][] = array( $name, $body );
	// The token it carries; $W['token_says'][token] - 'ok', 'foreign' (another
	// eBay account's) or anything else (expired) - answers by it, when set.
	$token                    = (string) ( $args['headers']['X-EBAY-API-IAF-TOKEN'] ?? '' );
	$GLOBALS['W']['tokens'][] = $token;

	if ( isset( $GLOBALS['W']['on_call'][ $name ] ) ) {
		$then = $GLOBALS['W']['on_call'][ $name ];
		unset( $GLOBALS['W']['on_call'][ $name ] );
		$then();
	}

	if ( '' !== $call && ! empty( $GLOBALS['W']['token_says'] ) && 'ok' !== ( $GLOBALS['W']['token_says'][ $token ] ?? '' ) ) {
		$says = ( $GLOBALS['W']['token_says'][ $token ] ?? '' ) === 'foreign'
			? array( '17', 'This item cannot be accessed because the listing has been deleted, is a Half.com listing, or you are not the seller.' )
			: array( '21917053', 'IAF token supplied is expired.' );
		return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => '<?xml version="1.0" encoding="UTF-8"?><' . $call . 'Response xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Failure</Ack><Errors><ShortMessage>' . $says[1] . '</ShortMessage><LongMessage>' . $says[1] . '</LongMessage><ErrorCode>' . $says[0] . '</ErrorCode><SeverityCode>Error</SeverityCode></Errors></' . $call . 'Response>' );
	}

	if ( '' === $call ) {
		$json = function ( $code, $data ) { return array( 'headers' => array(), 'response' => array( 'code' => $code ), 'body' => null === $data ? '' : json_encode( $data ) ); };
		if ( 'sell/inventory/v1/location' === $path ) {
			return $json( 200, array( 'locations' => array( array( 'name' => 'Card Shop HQ', 'merchantLocationKey' => 'hq' ) ) ) );
		}
		return $json( 200, $GLOBALS['W']['ebay']['orders'] ?? array( 'orders' => array() ) );
	}

	$answer = null;
	if ( 'GetItem' === $call && preg_match( '#<ItemID>(\d+)</ItemID>#', $body, $m ) ) {
		$list = $GLOBALS['W']['ebay']['items'][ $m[1] ] ?? null;
		if ( is_array( $list ) && isset( $list[0] ) ) {
			$answer = count( $list ) > 1 ? array_shift( $GLOBALS['W']['ebay']['items'][ $m[1] ] ) : $list[0];
		} else {
			$answer = refused( 17, 'This item cannot be accessed because the listing has been deleted, is a Half.com listing, or you are not the seller.' );
		}
	} elseif ( ! empty( $GLOBALS['W']['ebay']['answers'][ $call ] ) ) {
		$answer = array_shift( $GLOBALS['W']['ebay']['answers'][ $call ] );
	}

	if ( $answer instanceof WP_Error ) {
		return $answer;
	}

	$inner = '<Ack>Success</Ack>';
	if ( is_array( $answer ) && isset( $answer['refuse'] ) ) {
		$inner = '<Ack>Failure</Ack><Errors><ShortMessage>' . esc_xml( $answer['refuse'][1] ) . '</ShortMessage><LongMessage>' . esc_xml( $answer['refuse'][1] ) . '</LongMessage><ErrorCode>' . $answer['refuse'][0] . '</ErrorCode><SeverityCode>Error</SeverityCode></Errors>';
	} elseif ( is_array( $answer ) && isset( $answer['__xml'] ) ) {
		$inner .= $answer['__xml'];
	} elseif ( is_array( $answer ) ) {
		$inner .= to_xml( 'Item', $answer );
	} elseif ( 'EndItem' === $call ) {
		$inner .= '<EndTime>2026-10-24T09:30:00.000Z</EndTime>';
	}

	return array(
		'headers'  => array(),
		'response' => array( 'code' => 200 ),
		'body'     => '<?xml version="1.0" encoding="UTF-8"?><' . $call . 'Response xmlns="urn:ebay:apis:eBLBaseComponents">' . $inner . '</' . $call . 'Response>',
	);
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? ''; }
function wp_remote_retrieve_header( $r, $h ) { return ''; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

$ROOT = dirname( __DIR__ );
require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-variations.php';
require $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require $ROOT . '/includes/class-tcgiant-sync-mapper.php';
require $ROOT . '/includes/class-tcgiant-sync-importer.php';
require $ROOT . '/includes/class-tcgiant-sync-cron.php';
require $ROOT . '/includes/class-tcgiant-sync-orders.php';
require $ROOT . '/includes/class-tcgiant-sync-telemetry.php';
require $ROOT . '/includes/class-tcgiant-sync-wplister-converter.php';
require $ROOT . '/admin/class-tcgiant-sync-admin.php';

/** A class, made without its constructor's hooks. */
function bare( $class ) { return ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor(); }

$ADMIN = bare( 'TCGiant_Sync_Admin' );
$tcg_slot = new ReflectionProperty( 'TCGiant_Sync_Admin', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_slot->setAccessible( true );
}
$tcg_slot->setValue( null, $ADMIN );
$ADMIN->register_settings();

$INV = TCGiant_Sync_Inventory::instance();
$IMP = TCGiant_Sync_Importer::instance();

// --- driving it ----------------------------------------------------------------------

const TOKENS = array( 'access_token' => 'v^1.1#i^1#AT-SECRET-91f2c7', 'refresh_token' => 'v^1.1#i^1#RT-SECRET-4be0d1' );

/** A shop with nothing in it but these settings - connected to eBay unless told not. */
function fresh_shop( $settings = array(), $connected = true ) {
	$w = &$GLOBALS['W'];
	foreach ( array( 'options', 'stale', 'autoload', 'transients', 'meta', 'log', 'posts', 'queue', 'wire', 'writes', 'orders', 'on_call', 'cron', 'cleared', 'pings', 'caps', 'tokens', 'token_says' ) as $k ) {
		$w[ $k ] = array();
	}
	$w['ebay']       = array( 'items' => array(), 'answers' => array(), 'orders' => array( 'orders' => array() ) );
	$w['user_can']   = true;
	$w['screen']     = 'dashboard';
	$w['terms']      = 'some';
	$w['context']    = 'admin';
	$_POST           = array();
	$_REQUEST        = array();
	if ( null !== $settings ) {
		update_option( 'tcgiant_sync_ebay_settings', $connected ? array_merge( TOKENS, $settings ) : $settings );
	}
	$w['log'] = array();
}
/** The 3.28.0 upgrade's routine, once: what any first request does. */
function upgraded() { TCGiant_Sync_Inventory::maybe_upgrade_push_switch(); }
function settings() { return get_option( 'tcgiant_sync_ebay_settings', array() ); }
function set_setting( $key, $value ) {
	$s         = settings();
	$s[ $key ] = $value;
	update_option( 'tcgiant_sync_ebay_settings', $s );
}

/** A simple product on fixed-price listing $item_id, as an import leaves it. */
function product( $id, $stock, $item_id = '', array $meta = array() ) {
	$GLOBALS['W']['posts'][ $id ] = array( 'type' => 'product', 'kind' => 'simple', 'status' => 'publish', 'parent' => 0 );
	$GLOBALS['W']['meta'][ $id ]  = array_merge( array(
		'_sku'          => 'SKU-' . $id,
		'_manage_stock' => null === $stock ? 'no' : 'yes',
		'_stock'        => (int) $stock,
		'_stock_status' => ( null === $stock || $stock > 0 ) ? 'instock' : 'outofstock',
	), '' !== $item_id ? array( '_ebay_item_id' => $item_id, '_ebay_listing_status' => 'Active', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_type_item' => $item_id ) : array(), $meta );
}
function variation( $id, $parent, $sku, $stock ) {
	$GLOBALS['W']['posts'][ $id ] = array( 'type' => 'product_variation', 'kind' => 'variation', 'status' => 'publish', 'parent' => $parent );
	$GLOBALS['W']['meta'][ $id ]  = array( '_sku' => $sku, '_manage_stock' => null === $stock ? 'no' : 'yes', '_stock' => (int) $stock, '_stock_status' => 'instock' );
}
/** A live fixed-price listing as GetItem returns it. */
function listing( $item_id, $listed, $sold ) {
	return array(
		'ItemID'         => $item_id,
		'Quantity'       => (string) $listed,
		'ListingType'    => 'FixedPriceItem',
		'SellingStatus'  => array( 'ListingStatus' => 'Active', 'QuantitySold' => (string) $sold ),
		'ListingDetails' => array( 'StartTime' => '2026-10-01T10:00:00.000Z', 'EndTime' => '2026-10-31T10:00:00.000Z' ),
	);
}
function ebay_has( $item_id, ...$answers ) { $GLOBALS['W']['ebay']['items'][ (string) $item_id ] = $answers; }
function ebay_answers( $call, ...$answers ) { $GLOBALS['W']['ebay']['answers'][ $call ] = $answers; }

/** A website order, its stock reduced as WooCommerce reduces it. */
function website_order( $order_id, array $lines ) {
	$items = array();
	$n     = 1;
	foreach ( $lines as $product_id => $qty ) {
		$items[] = new WC_Order_Item_Product( $order_id * 10 + $n++, $product_id, $qty );
	}
	$GLOBALS['W']['orders'][ $order_id ] = new WC_Order( $order_id, $items );
	wc_reduce_stock_levels( $GLOBALS['W']['orders'][ $order_id ] );
	return $GLOBALS['W']['orders'][ $order_id ];
}
/** A hand edit of the stock, as the product screen saves one. */
function hand_edit( $id, $stock ) { wc_update_product_stock( $id, $stock, 'set' ); }

/** Run what is queued, as Action Scheduler would: due now, or every action when $all. */
function run_queue( $all = false, $limit = 20 ) {
	$ran = 0;
	while ( $ran < $limit ) {
		$next = null;
		foreach ( $GLOBALS['W']['queue'] as $i => $a ) {
			if ( $all || $a['when'] <= time() ) {
				$next = $i;
				break;
			}
		}
		if ( null === $next ) {
			break;
		}
		$action = $GLOBALS['W']['queue'][ $next ];
		array_splice( $GLOBALS['W']['queue'], $next, 1 );
		do_action_ref_array( $action['hook'], array_values( $action['args'] ) );
		$ran++;
	}
	return $ran;
}
function pushes() { return array_values( array_filter( $GLOBALS['W']['queue'], function ( $a ) { return TCGiant_Sync_Inventory::PUSH_HOOK === $a['hook']; } ) ); }
function push_args() { return array_map( function ( $a ) { return array( $a['args'][0]['product_id'] ?? null, $a['args'][0]['reason'] ?? null ); }, pushes() ); }
function queued_hooks() { return array_column( $GLOBALS['W']['queue'], 'hook' ); }
function calls() { return array_column( $GLOBALS['W']['wire'], 0 ); }
function meta( $id, $key ) { return $GLOBALS['W']['meta'][ $id ][ $key ] ?? null; }
function stock( $id ) { return isset( $GLOBALS['W']['meta'][ $id ]['_stock'] ) ? (int) $GLOBALS['W']['meta'][ $id ]['_stock'] : null; }
function logged( $needle, $level = null ) {
	foreach ( $GLOBALS['W']['log'] as $l ) {
		if ( false !== strpos( $l[1], $needle ) && ( null === $level || $level === $l[0] ) ) {
			return true;
		}
	}
	return false;
}
function count_logged( $needle ) { return count( array_filter( $GLOBALS['W']['log'], function ( $l ) use ( $needle ) { return false !== strpos( $l[1], $needle ); } ) ); }
function errors_logged() { return count( array_filter( $GLOBALS['W']['log'], function ( $l ) { return 'error' === $l[0]; } ) ); }
function switch_record() { return get_option( TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION, false ); }

// --- the other plugins, as WordPress loads them -----------------------------------

const WPLISTER = 'wp-lister-ebay/wp-lister-ebay.php';
function is_multisite() { return ! empty( $GLOBALS['W']['multisite'] ); }
function get_site_option( $name, $default = false ) { return $GLOBALS['W']['site_options'][ $name ] ?? $default; }
/**
 * wp-settings.php loading the plugins, as far as it goes here: on a multisite
 * the network's first (wp_get_active_network_plugins()), then the site's own
 * that are not also the network's (wp_get_active_and_valid_plugins()), each
 * included once. Installed in a plugins folder of its own: WP-Lister for eBay,
 * a stand-in defining what its main file defines first (wp-lister-ebay.php,
 * WP-Lister Pro 3.8.8, lines 19 and 23). What was loaded, as basenames.
 */
function load_plugins() {
	$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tcg-plugins-' . getmypid();
	@mkdir( $dir . '/wp-lister-ebay', 0777, true );
	file_put_contents( $dir . '/' . WPLISTER, "<?php\ndefine( 'WPLE_PLUGIN_VERSION', '3.8.8' );\ndefine( 'WPLISTER_VERSION', WPLE_PLUGIN_VERSION );\n" );
	$files = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
	foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
		if ( ! in_array( $plugin, $files, true ) ) {
			$files[] = $plugin;
		}
	}
	$loaded = array();
	foreach ( $files as $plugin ) {
		if ( is_file( $dir . '/' . $plugin ) ) {
			include_once $dir . '/' . $plugin;
			$loaded[] = $plugin;
		}
	}
	unlink( $dir . '/' . WPLISTER );
	rmdir( $dir . '/wp-lister-ebay' );
	rmdir( $dir );
	return $loaded;
}
/** The words of the notice and the line for a shop left off because WP-Lister for eBay is active. */
const HELD_LINE   = array( 'still not sent to eBay', 'WP-Lister for eBay is active on this site', 'overwrite each other\'s quantities', 'turn off WP-Lister\'s stock sync, then set "Send website stock changes to eBay?" to Yes in Settings' );
const HELD_NOTICE = array( 'TCGiant Sync is not sending your website\'s stock changes to eBay', 'WP-Lister for eBay was active on this site when TCGiant Sync updated', 'overwrite each other\'s quantities, so let only one of them do it', 'turn off WP-Lister\'s stock sync, then set "Send website stock changes to eBay?" to Yes in Settings', 'page=tcgiant-settings#tc-push-stock', 'value="nonce-tcgiant_dismiss_push_notice"' );
/** Which of $needles $text holds, each true or false. */
function holds( $text, array $needles ) { return array_map( function ( $n ) use ( $text ) { return false !== strpos( (string) $text, $n ); }, $needles ); }
function all_true( array $needles ) { return array_fill( 0, count( $needles ), true ); }
/** The warning lines of the upgrade: [level, says it held, says it turned sending on]. */
function upgrade_lines() {
	return array_values( array_map( function ( $l ) { return array( $l[0], false !== strpos( $l[1], 'WP-Lister' ), false !== strpos( $l[1], 'now sent to eBay' ) ); }, array_filter( $GLOBALS['W']['log'], function ( $l ) { return 0 === strpos( $l[1], 'Updated to 3.28.0' ); } ) ) );
}

// --- the Settings screen, as a browser has it -------------------------------------

/** The Settings screen's HTML for the option as stored. */
function settings_page( $view = null ) {
	ob_start();
	include null !== $view ? $view : dirname( __DIR__ ) . '/admin/views/settings.php';
	return ob_get_clean();
}

/**
 * Each options.php form in a page: the name/value pairs a browser sends for
 * it, in order - checked boxes and radios only, a select's selected option or
 * its first, a textarea's text - and every name it holds.
 */
function page_forms( $html ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	$xp  = new DOMXPath( $dom );
	$out = array();
	foreach ( $dom->getElementsByTagName( 'form' ) as $form ) {
		if ( 'options.php' !== $form->getAttribute( 'action' ) ) {
			continue;
		}
		$pairs = array();
		$names = array();
		foreach ( $xp->query( './/input|.//select|.//textarea', $form ) as $el ) {
			$name = $el->getAttribute( 'name' );
			if ( '' === $name || $el->hasAttribute( 'disabled' ) ) {
				continue;
			}
			$names[] = $name;
			$tag     = strtolower( $el->nodeName );
			if ( 'input' === $tag ) {
				$type = strtolower( $el->getAttribute( 'type' ) ? $el->getAttribute( 'type' ) : 'text' );
				if ( in_array( $type, array( 'submit', 'button' ), true ) ) {
					continue;
				}
				if ( in_array( $type, array( 'radio', 'checkbox' ), true ) && ! $el->hasAttribute( 'checked' ) ) {
					continue;
				}
				$pairs[] = array( $name, $el->getAttribute( 'value' ) );
			} elseif ( 'select' === $tag ) {
				$val   = null;
				$first = null;
				foreach ( $el->getElementsByTagName( 'option' ) as $o ) {
					if ( null === $first ) {
						$first = $o->getAttribute( 'value' );
					}
					if ( $o->hasAttribute( 'selected' ) ) {
						$val = $o->getAttribute( 'value' );
					}
				}
				$pairs[] = array( $name, null === $val ? (string) $first : $val );
			} else {
				$pairs[] = array( $name, $el->textContent );
			}
		}
		$marker = '';
		foreach ( $pairs as $p ) {
			if ( 'tcgiant_sync_ebay_settings[' . TCGiant_Sync_Admin::SETTINGS_FORM_MARKER . ']' === $p[0] ) {
				$marker = $p[1];
			}
		}
		$out[] = array( 'pairs' => $pairs, 'names' => $names, 'marker' => $marker );
	}
	return $out;
}

/** The settings keys a form holds, from its field names. */
function form_keys( array $form ) {
	$keys = array();
	foreach ( $form['names'] as $name ) {
		if ( preg_match( '/^tcgiant_sync_ebay_settings\[([^\]]+)\]/', $name, $m ) ) {
			$keys[ $m[1] ] = true;
		}
	}
	unset( $keys[ TCGiant_Sync_Admin::SETTINGS_FORM_MARKER ], $keys[ TCGiant_Sync_Admin::SETTINGS_LISTS_MARKER ] );
	$keys = array_keys( $keys );
	sort( $keys );
	return $keys;
}

/**
 * Submit form $which (0 Import, 1 Export) of a page as a browser does, and
 * save it as options.php does. $set replaces a field's value (a radio
 * chosen, a text typed); $untick takes every value of a field away (each box
 * of a list unticked); $add sends more pairs.
 */
function submit_form( $html, $which, array $set = array(), array $untick = array(), array $add = array() ) {
	$forms = page_forms( $html );
	$pairs = $forms[ $which ]['pairs'];
	foreach ( $set as $name => $value ) {
		$full  = 'tcgiant_sync_ebay_settings[' . $name . ']';
		$pairs = array_values( array_filter( $pairs, function ( $p ) use ( $full ) { return $p[0] !== $full; } ) );
		$pairs[] = array( $full, (string) $value );
	}
	foreach ( $untick as $name ) {
		$full  = 'tcgiant_sync_ebay_settings[' . $name . '][]';
		$pairs = array_values( array_filter( $pairs, function ( $p ) use ( $full ) { return $p[0] !== $full; } ) );
	}
	foreach ( $add as $p ) {
		$pairs[] = $p;
	}
	post_pairs( $pairs );
}

/** What PHP makes of a urlencoded body, slashed as WordPress leaves $_POST, saved through options.php. */
function post_pairs( array $pairs ) {
	$parts = array();
	foreach ( $pairs as $p ) {
		$parts[] = rawurlencode( $p[0] ) . '=' . rawurlencode( (string) $p[1] );
	}
	parse_str( implode( '&', $parts ), $post );
	$_POST    = wp_slash( $post );
	$_REQUEST = $_POST;
	options_php_save();
	$_POST    = array();
	$_REQUEST = array();
}

/** The notice, as the admin prints it on this screen for this user. */
function notice_html() {
	ob_start();
	$GLOBALS['ADMIN']->push_switch_notice();
	return html_entity_decode( (string) ob_get_clean(), ENT_QUOTES );
}
/** What a form sends for one field, as a browser has the page. */
function posted( $html, $which, $key ) {
	foreach ( page_forms( $html )[ $which ]['pairs'] as $p ) {
		if ( 'tcgiant_sync_ebay_settings[' . $key . ']' === $p[0] ) {
			return $p[1];
		}
	}
	return null;
}

$CATS     = array_values( array_diff( array_keys( TCGiant_Sync_Catalog::CATEGORIES ), array( '', 'custom' ) ) );
$STD_CATS = array( (string) $CATS[0], (string) $CATS[1] );

/** A shop that has set everything, connected, with the plugin's own records. */
$FULL = array(
	'token_expiry'                   => '1999999999',
	'relay_secret'                   => 'RELAY-SECRET-77aa31',
	'cert_id'                        => 'PRD-CERT-SECRET-1d3c',
	'app_id'                         => 'TCGiant-PRD-app',
	'redirect_uri'                   => 'https://coinshop.example.com/wp-admin/admin.php?page=tcgiant-sync',
	'store_name'                     => 'cardboardshop',
	'import_status'                  => 'complete',
	'marketplace'                    => 'EBAY_GB',
	'import_sites'                   => 'primary',
	'custom_saved_categories'        => '',
	'category_ids'                   => '111,222',
	'import_standard_category_ids'   => $STD_CATS,
	'woo_category_ids'               => array( '7' ),
	'preserve_woo_category_ids'      => array( '9' ),
	'import_specs_as_tags'           => '1',
	'bake_shipping_into_price'       => '1',
	'import_auctions'                => '1',
	'sku_maps_to'                    => 'both',
	'sku_prefix'                     => 'ebay',
	'sync_interval'                  => 'daily',
	'host_images_on_ebay'            => '1',
	'import_image_width'             => '800',
	'log_level'                      => 'warning',
	'enable_order_sync'              => '0',
	'enable_reconciliation'          => '0',
	'enable_order_import'            => '1',
	'auto_end_zero_stock'            => '0',
	'disable_image_localization'     => '1',
	'remove_sold_products'           => '0',
	'auto_relist_enabled'            => '1',
	'overwrite_title'                => '1',
	'overwrite_desc'                 => '1',
	'overwrite_price'                => '0',
	'overwrite_images'               => '1',
	'overwrite_taxonomy'             => '1',
	'overwrite_attributes'           => '1',
	'overwrite_weight_dims'          => '0',
	'delete_data_on_uninstall'       => '1',
	'export_category_id'             => '183050',
	'export_category_name'           => 'Trading Card Games',
	'export_condition_type'          => 'graded',
	'export_grader_id'               => '275010',
	'export_grade_value'             => '9.5',
	'export_cert_number'             => '95422421',
	'export_ungraded_condition'      => '400010',
	'export_condition_id'            => '1500',
	'export_location'                => 'Leeds',
	'export_postal_code'             => 'LS1 1AA',
	'export_fulfillment_policy'      => 'F1',
	'export_return_policy'           => 'R1',
	'export_payment_policy'          => 'P1',
	'export_listing_type'            => 'FixedPriceItem',
	'export_listing_duration'        => 'GTC',
	'export_default_quantity'        => '3',
	'export_send_package_details'    => '1',
	'best_offer_enabled'             => '1',
	'best_offer_auto_accept'         => '0.95',
	'best_offer_auto_decline'        => '0.70',
	'gpsr_enabled'                   => '1',
	'gpsr_manufacturer_name'         => 'ACME Cards',
	'gpsr_manufacturer_address'      => '1 Road, Leeds',
	'gpsr_eu_responsible'            => 'EU Rep GmbH',
	'last_order_import'              => '2026-10-10 10:00:00',
	'last_reconciliation'            => '2026-10-09 03:00:00',
	'last_reconciliation_mismatches' => '3',
);
$RECORDS = array( 'last_order_import', 'last_reconciliation', 'last_reconciliation_mismatches', 'import_status', 'store_name', 'app_id', 'redirect_uri', 'token_expiry', 'relay_secret', 'cert_id', 'access_token', 'refresh_token' );

/** These keys of the stored option, as they are now. */
function stored_keys( array $keys ) {
	$s   = settings();
	$out = array();
	foreach ( $keys as $k ) {
		$out[ $k ] = $s[ $k ] ?? null;
	}
	return $out;
}
function want_keys( array $from, array $keys ) {
	$out = array();
	foreach ( $keys as $k ) {
		$out[ $k ] = $from[ $k ] ?? null;
	}
	return $out;
}
$IMPORT_KEYS = TCGiant_Sync_Admin::SETTINGS_FORMS['import'];
$EXPORT_KEYS = TCGiant_Sync_Admin::SETTINGS_FORMS['export'];

// ═══════════════════════════════════════════════════════════════════════════════
// The children: the same world, with the release switch off, or on a copy.
// ═══════════════════════════════════════════════════════════════════════════════

if ( 'child=fallback' === $MODE ) {
	echo "\n(child) FLIP_LEGACY_ZERO = false: THE FALLBACK IF THE PUSH REWORK IS TAKEN OUT (T12)\n" . str_repeat( '=', 124 ) . "\n";
	check( 'the child runs the class with the release switch off, and only that changed', array( $GLOBALS['TCG_FLIP_ANCHORS'], TCGiant_Sync_Inventory::FLIP_LEGACY_ZERO ), array( 1, false ) );

	fresh_shop( array( 'enable_order_sync' => '0' ) );
	upgraded();
	check( 'a shop that saved "Reduce ... on eBay sale?" as No: not turned on, and the notice is due', switch_record(), array( 'since' => '3.28.0', 'legacy' => '0', 'flipped' => false, 'notice' => 'pending', 'flip' => false ) );
	check( '  nothing said in the log about turning anything on', logged( 'now sent to eBay' ), false );
	product( 100, 5, '100000000100' );
	hand_edit( 100, 4 );
	website_order( 600, array( 100 => 1 ) );
	check( '  its stock changes are still not sent: nothing queued', pushes(), array() );
	check( '  and the log says why, once', count_logged( '"Send website stock changes to eBay?" is No' ), 1 );
	$page = settings_page();
	check( '  Settings shows the push switch at No, as it is', posted( $page, 0, 'push_stock_to_ebay' ), '0' );
	$html = notice_html();
	check( '  the notice asks the shop to turn it on', array( false !== strpos( $html, 'not being sent to eBay' ), false !== strpos( $html, 'turn on "Send website stock changes to eBay?"' ), false !== strpos( $html, 'page=tcgiant-settings#tc-push-stock' ) ), array( true, true, true ) );
	check( '  and does not say they are now sent', false !== strpos( $html, 'now sends your website' ), false );
	set_setting( 'push_stock_to_ebay', '1' );
	check( '  once the shop turns it on, the notice puts itself away', array( notice_html(), TCGiant_Sync_Inventory::push_notice_pending() ), array( '', false ) );
	$W['queue'] = array();
	hand_edit( 100, 3 );
	check( '  and its stock changes go to eBay', push_args(), array( array( 100, 'edit' ) ) );
	fresh_shop( array( 'enable_order_sync' => '0' ) );
	upgraded();
	submit_form( settings_page(), 0 );
	check( '  an untouched Import Settings save keeps it off, and stores nothing of it: the release still decides', array( array_key_exists( 'push_stock_to_ebay', settings() ), TCGiant_Sync_Inventory::pushes_enabled() ), array( false, false ) );
	// The relabelled field never changes what is sent once the upgrade has
	// looked (SW2-M1): a shop that had none is not a "No" shop, whatever an
	// Import save stores in it since.
	fresh_shop( array() );
	upgraded();
	submit_form( settings_page(), 0 );
	check( '  a shop that never had "Reduce ... on eBay sale?": an untouched Import save stores its default No, and pushes stay on', array( settings()['enable_order_sync'] ?? null, array_key_exists( 'push_stock_to_ebay', settings() ), TCGiant_Sync_Inventory::pushes_enabled() ), array( '0', false, true ) );
	fresh_shop( array( 'enable_order_sync' => '1' ) );
	upgraded();
	set_setting( 'enable_order_sync', '0' );
	check( '  a "Yes" shop that saves the relabelled field as No since: pushes stay on', TCGiant_Sync_Inventory::pushes_enabled(), true );
	fresh_shop( array( 'enable_order_sync' => '0' ) );
	upgraded();
	set_setting( 'enable_order_sync', '1' );
	check( '  and a "No" shop that saves it as Yes: pushes stay off - only the push switch turns them on', TCGiant_Sync_Inventory::pushes_enabled(), false );
	fresh_shop( array( 'enable_order_sync' => '0' ) );
	check( '  before the upgrade has run (the start of the first request), the stored key decides', TCGiant_Sync_Inventory::pushes_enabled(), false );
	fresh_shop( array() );
	update_option( TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION, 'not a record', true );
	set_setting( 'enable_order_sync', '0' );
	check( '  a record that is not one: no "No" shop', TCGiant_Sync_Inventory::pushes_enabled(), true );
	$tcg_warnings = array();
	set_error_handler( function ( $no, $str ) use ( &$tcg_warnings ) { $tcg_warnings[] = $str; return true; } );
	fresh_shop( array( 'enable_order_sync' => array( '0' ) ) );
	$tcg_on = TCGiant_Sync_Inventory::pushes_enabled();
	upgraded();
	$tcg_on = array( $tcg_on, TCGiant_Sync_Inventory::pushes_enabled(), switch_record()['legacy'] ?? null );
	restore_error_handler();
	check( '  an array stored as the old setting: no warning, before the upgrade or after, and not a "No" shop', array( $tcg_warnings, $tcg_on ), array( array(), array( true, true, 'absent' ) ) );
	done();
}

if ( 'child=oauth' === $MODE ) {
	echo "\n(child) THE TOKEN A PUSH'S TRY CARRIES, WITH THE REAL OAUTH CLASS\n" . str_repeat( '=', 124 ) . "\n";
	$tcg_a = array( 'access_token' => 'AT-A1', 'refresh_token' => 'RT-A', 'token_expiry' => (string) ( time() + 3600 ) );

	// Reconnected to another eBay account (B) while the push's request ran, its own token not due.
	fresh_shop( $tcg_a, false );
	product( 520, 3, '520000000520' );
	ebay_has( '520000000520', listing( '520000000520', 3, 0 ) );
	$W['token_says'] = array( 'AT-A1' => 'ok', 'AT-B' => 'foreign' );
	website_order( 820, array( 520 => 1 ) );
	$W['wire']   = array();
	$W['tokens'] = array();
	in_one_request( function () {
		other_request_saves( 'access_token', 'AT-B' );
		other_request_saves( 'refresh_token', 'RT-B' );
		other_request_saves( 'token_expiry', (string) ( time() + 7200 ) );
		run_queue( true, 1 );
	} );
	check( 'a try begun before another request connected account B: B\'s token only, the listing not found there, final', array( array_values( array_unique( $W['tokens'] ) ), queued_hooks(), logged( 'cannot be found on this eBay account' ) ), array( array( 'AT-B' ), array(), true ) );

	// eBay refuses this request's token, not due; another request renewed it meanwhile.
	fresh_shop( $tcg_a, false );
	product( 521, 3, '520000000521' );
	ebay_has( '520000000521', listing( '520000000521', 3, 0 ) );
	$W['token_says'] = array( 'AT-A2' => 'ok' );
	website_order( 821, array( 521 => 1 ) );
	$W['wire']   = array();
	$W['tokens'] = array();
	in_one_request( function () {
		other_request_saves( 'access_token', 'AT-A2' );
		other_request_saves( 'token_expiry', (string) ( time() + 7200 ) );
		run_queue( true, 1 );
		$GLOBALS['tcg_after'] = TCGiant_Sync_OAuth::instance()->get_access_token();
	} );
	check( 'a refused token, not due, renewed by another request: the retry carries the renewal, the push lands, the relay is not asked', array( array_values( array_unique( $W['tokens'] ) ), in_array( 'ReviseInventoryStatus', calls(), true ), queued_hooks(), count( $W['pings'] ) ), array( array( 'AT-A1', 'AT-A2' ), true, array(), 0 ) );
	check( '  and the rest of the request uses it', $GLOBALS['tcg_after'] ?? null, 'AT-A2' );
	done();
}

if ( 'child=mapper' === $MODE ) {
	echo "\n(child) THE MARKETPLACE TAKEN FROM A LISTING, IN A BATCH ANOTHER REQUEST DISCONNECTED IN\n" . str_repeat( '=', 124 ) . "\n";
	fresh_shop( array( 'import_sites' => 'all' ) );
	$tcg_adopt = new ReflectionMethod( 'TCGiant_Sync_Mapper', 'maybe_adopt_marketplace' );
	if ( PHP_VERSION_ID < 80100 ) {
		$tcg_adopt->setAccessible( true );
	}
	in_one_request( function () use ( $tcg_adopt ) {
		settings();
		other_request_saves( 'access_token', null );
		other_request_saves( 'refresh_token', null );
		$tcg_adopt->invoke( ( new ReflectionClass( 'TCGiant_Sync_Mapper' ) )->newInstanceWithoutConstructor(), 'UK' );
	} );
	check( 'the marketplace taken from a UK listing is saved, and the tokens another request took away stay gone', array( settings()['marketplace'] ?? null, array_key_exists( 'access_token', settings() ), array_key_exists( 'refresh_token', settings() ) ), array( 'EBAY_GB', false, false ) );
	// Once a process: a second process's run, where the shop chose a marketplace meanwhile.
	done();
}
if ( 'child=mapper2' === $MODE ) {
	echo "\n(child) A MARKETPLACE THE SHOP STORED MEANWHILE STANDS\n" . str_repeat( '=', 124 ) . "\n";
	fresh_shop( array( 'import_sites' => 'all' ) );
	$tcg_adopt = new ReflectionMethod( 'TCGiant_Sync_Mapper', 'maybe_adopt_marketplace' );
	if ( PHP_VERSION_ID < 80100 ) {
		$tcg_adopt->setAccessible( true );
	}
	in_one_request( function () use ( $tcg_adopt ) {
		settings();
		other_request_saves( 'marketplace', 'EBAY_DE' );
		$tcg_adopt->invoke( ( new ReflectionClass( 'TCGiant_Sync_Mapper' ) )->newInstanceWithoutConstructor(), 'UK' );
	} );
	check( 'a marketplace stored by another request since the batch began: it stands, nothing written, nothing said', array( settings()['marketplace'] ?? null, logged( 'Marketplace was not set' ) ), array( 'EBAY_DE', false ) );
	done();
}

if ( 'child=staging' === $MODE ) {
	echo "\n(child) A STAGING COPY STAYS BLOCKED, AND NOTHING IS TRIED AGAIN\n" . str_repeat( '=', 124 ) . "\n";
	check( 'the child is a staging copy', TCGiant_Sync_Environment::write_block() instanceof WP_Error, true );

	fresh_shop( array( 'enable_order_sync' => '0' ) );
	product( 200, 5, '200000000200' );
	ebay_has( '200000000200', listing( '200000000200', 5, 0 ) );
	hand_edit( 200, 4 );
	website_order( 610, array( 200 => 1 ) );
	$queued = count( pushes() );
	run_queue( true );
	check( 'a copy of a "No" shop: pushes queued run, send nothing and read nothing', array( $queued, calls() ), array( 2, array() ) );
	check( '  nothing is tried again, no safety try is left, and no running mark', array( queued_hooks(), meta( 200, '_ebay_push_running' ) ), array( array(), null ) );
	check( '  no sale is held back as unsent', array_key_exists( 'unsent', (array) meta( 200, '_ebay_push_pending' ) ), false );

	// A safety try that comes round on a copy, its try still marked running:
	// it used to be put off to look again.
	fresh_shop( array() );
	product( 201, 5, '200000000201' );
	update_post_meta( 201, TCGiant_Sync_Inventory::PUSH_RUNNING_KEY, array( 'run-a' => array( 'started' => time() - 60, 'when' => time() ) ) );
	do_action( TCGiant_Sync_Inventory::PUSH_HOOK, array( 'product_id' => 201, 'reason' => 'edit', 'attempt' => 2, 'safety' => 'run-a' ) );
	check( 'a safety try on a copy, its try still running: final, nothing put off to look again', array( calls(), queued_hooks(), meta( 201, '_ebay_push_running' ) ), array( array(), array(), null ) );
	// One handed a try again due in an hour (a site without Action Scheduler 3).
	update_post_meta( 201, TCGiant_Sync_Inventory::PUSH_RUNNING_KEY, array( 'run-b' => array( 'started' => time() - 1000, 'when' => time() - 1, 'next' => array( 'product_id' => 201, 'reason' => 'order', 'attempt' => 3, 'qty' => 1 ), 'at' => time() + 3600 ) ) );
	do_action( TCGiant_Sync_Inventory::PUSH_HOOK, array( 'product_id' => 201, 'reason' => 'order', 'attempt' => 2, 'safety' => 'run-b' ) );
	check( '  one handed a try again for later: not put off to it either', array( calls(), queued_hooks(), meta( 201, '_ebay_push_running' ) ), array( array(), array(), null ) );

	// Asked for by hand: a copy still sends nothing.
	fresh_shop( array( 'push_stock_to_ebay' => '0' ) );
	product( 202, 2, '200000000202' );
	ebay_has( '200000000202', listing( '200000000202', 3, 0 ) );
	$out = $INV->push_now( wc_get_product( 202 ), 'manual' );
	check( '  the order screen\'s push on a copy: nothing sent, and said', array( calls(), $out['status'] ), array( array(), 'skipped' ) );

	// Nothing can be sent from a copy: no notice yet, and the health row says so.
	fresh_shop( array( 'enable_order_sync' => '0' ) );
	upgraded();
	$W['screen'] = 'dashboard';
	check( '  the notice waits on a copy, still due', array( notice_html(), TCGiant_Sync_Inventory::push_notice_pending() ), array( '', true ) );
	check( '  the health row: On (this copy is blocked from writing to eBay)', $ADMIN->get_system_health()['stock_push']['text'] ?? null, 'On (this copy is blocked from writing to eBay)' );
	done();
}

if ( 'child=wplister' === $MODE ) {
	echo "\n(child) WP-LISTER FOR EBAY ACTIVE AT THE UPGRADE\n" . str_repeat( '=', 124 ) . "\n";
	$W['options']['active_plugins'] = array( 'woocommerce/woocommerce.php', WPLISTER );
	check( 'the child loads WP-Lister for eBay as WordPress loads a site\'s active plugin, and the converter sees it', array( load_plugins(), TCGiant_Sync_WPLister_Converter::wplister_active() ), array( array( WPLISTER ), true ) );

	fresh_shop( array( 'enable_order_sync' => '0', 'log_level' => 'warning' ) );
	product( 700, 2, '700000000700' );
	ebay_has( '700000000700', listing( '700000000700', 5, 0 ) );
	$before       = settings();
	$tcg_writes   = 0;
	add_action( 'update_option_tcgiant_sync_ebay_settings', function () { $GLOBALS['tcg_writes']++; } );
	$W['context'] = 'front';
	do_action( 'init' );
	check( 'a "No" shop, a shopper\'s request: pushes are not turned on', array( TCGiant_Sync_Inventory::pushes_enabled(), TCGiant_Sync_Inventory::push_switch_state() ), array( false, 'off' ) );
	check( '  stored as the shop\'s own No: the one key written, once, onto the settings as they were', array( settings()['push_stock_to_ebay'] ?? null, array_diff_key( settings(), array( 'push_stock_to_ebay' => 1 ) ) === $before, $tcg_writes ), array( '0', true, 1 ) );
	check( '  the record: not turned on, held for WP-Lister, the notice due', switch_record(), array( 'since' => '3.28.0', 'legacy' => '0', 'flipped' => false, 'notice' => 'pending', 'flip' => true, 'held' => 'wplister' ) );
	check( '  one warning, saying it is held, not that sending is on', upgrade_lines(), array( array( 'warning', true, false ) ) );
	check( '    saying why, and how to use TCGiant Sync\'s', holds( implode( ' ', array_column( $W['log'], 1 ) ), HELD_LINE ), all_true( HELD_LINE ) );
	foreach ( array( 'cron', 'admin', 'cli', 'front' ) as $ctx ) {
		$W['context'] = $ctx;
		do_action( 'init' );
	}
	$W['context'] = 'admin';
	check( '  four more requests - cron, admin, WP-CLI, a shopper: no line, nothing written, still off', array( count_logged( 'Updated to 3.28.0' ), $tcg_writes, switch_record()['held'] ?? null, settings()['push_stock_to_ebay'] ?? null, TCGiant_Sync_Inventory::pushes_enabled() ), array( 1, 1, 'wplister', '0', false ) );
	hand_edit( 700, 1 );
	website_order( 900, array( 700 => 1 ) );
	run_queue( true );
	check( '  a hand edit and a website sale: nothing queued, nothing sent, and the log says why, once', array( pushes(), calls(), count_logged( '"Send website stock changes to eBay?" is No' ) ), array( array(), array(), 1 ) );
	check( '  Settings draws the switch at No', array( posted( settings_page(), 0, 'push_stock_to_ebay' ), posted( settings_page(), 0, 'push_stock_to_ebay_drawn' ) ), array( '0', '0' ) );
	check( '  the health panel: "Website stock to eBay: Off"', array( $ADMIN->get_system_health()['stock_push']['label'] ?? null, $ADMIN->get_system_health()['stock_push']['text'] ?? null ), array( 'Website stock to eBay', 'Off' ) );

	// The notice: the same screens, the same people, the same dismissal.
	foreach ( array( 'dashboard', 'edit-product', 'product', 'toplevel_page_tcgiant-sync' ) as $screen ) {
		$W['screen'] = $screen;
		check( 'the notice on ' . $screen . ': why, and how, with the setting\'s link and a nonce\'d Got it', holds( notice_html(), HELD_NOTICE ), all_true( HELD_NOTICE ) );
	}
	check( '  not the words of a shop turned on, nor of the old setting', holds( notice_html(), array( 'now sends your website', 'Reduce WooCommerce stock', 'wrongly' ) ), array( false, false, false ) );
	$W['screen'] = 'plugins';
	check( '  not on other screens', notice_html(), '' );
	$W['screen']                 = 'dashboard';
	$W['caps']['manage_options'] = false;
	check( '  not for a user who cannot manage options', notice_html(), '' );
	$W['caps'] = array();
	$_REQUEST  = array( '_wpnonce' => 'nonce-tcgiant_dismiss_push_notice', '_wp_http_referer' => '/wp-admin/edit.php?post_type=product' );
	try {
		$ADMIN->handle_dismiss_push_notice();
		$tcg_to = null;
	} catch ( Harness_Redirect $r ) {
		$tcg_to = $r->to;
	}
	$_REQUEST = array();
	upgraded();
	check( '  Got it: put away, back where the shop was, not brought back, and pushes still off', array( notice_html(), switch_record()['notice'] ?? null, $tcg_to, TCGiant_Sync_Inventory::pushes_enabled() ), array( '', 'dismissed', '/wp-admin/edit.php?post_type=product', false ) );

	fresh_shop( array( 'enable_order_sync' => '0' ) );
	upgraded();
	submit_form( settings_page(), 1 );
	check( '  saving Push to eBay Settings leaves it due', TCGiant_Sync_Inventory::push_notice_pending(), true );
	submit_form( settings_page(), 0 );
	check( '  an untouched Import Settings save puts it away, and the shop\'s No stands', array( TCGiant_Sync_Inventory::push_notice_pending(), notice_html(), settings()['push_stock_to_ebay'] ?? null, TCGiant_Sync_Inventory::pushes_enabled() ), array( false, '', '0', false ) );
	fresh_shop( array( 'enable_order_sync' => '0' ) );
	product( 701, 3, '700000000701' );
	upgraded();
	submit_form( settings_page(), 0, array( 'push_stock_to_ebay' => '1' ) );
	hand_edit( 701, 2 );
	check( '  Yes set in Settings: sent from then on, and the notice is away', array( settings()['push_stock_to_ebay'] ?? null, TCGiant_Sync_Inventory::push_switch_state(), push_args(), TCGiant_Sync_Inventory::push_notice_pending() ), array( '1', 'on', array( array( 701, 'edit' ) ), false ) );
	fresh_shop( array( 'enable_order_sync' => '0' ) );
	upgraded();
	set_setting( 'push_stock_to_ebay', '1' );
	check( '  Yes stored some other way: the notice puts itself away on the next screen', array( notice_html(), switch_record()['notice'] ?? null ), array( '', 'dismissed' ) );
	fresh_shop( array( 'enable_order_sync' => '0' ), false );
	upgraded();
	check( '  a shop not connected yet: held all the same, and the notice waits, still due', array( switch_record()['held'] ?? null, settings()['push_stock_to_ebay'] ?? null, notice_html(), TCGiant_Sync_Inventory::push_notice_pending() ), array( 'wplister', '0', '', true ) );
	update_option( 'tcgiant_sync_ebay_settings', array_merge( settings(), TOKENS ) );
	check( '    connected: it shows', holds( notice_html(), HELD_NOTICE ), all_true( HELD_NOTICE ) );

	// Two requests at once: the second began - its copy of the options taken -
	// before the first claimed the run and stored the No.
	fresh_shop( array( 'enable_order_sync' => '0' ) );
	$tcg_writes = 0;
	in_one_request( function () {
		$mine                  = $GLOBALS['W']['cache'];
		$GLOBALS['W']['cache'] = null;
		upgraded();
		$GLOBALS['W']['cache'] = $mine;
		upgraded();
	} );
	check( 'two requests racing: the second inserts the same record, which changes nothing - one line, one write', array( count_logged( 'Updated to 3.28.0' ), $tcg_writes, settings()['push_stock_to_ebay'] ?? null, switch_record()['held'] ?? null, switch_record()['notice'] ?? null ), array( 1, 1, '0', 'wplister', 'pending' ) );

	// Only where the upgrade would turn sending on: every other shop is as without WP-Lister.
	foreach ( array(
		'saved as Yes'                      => array( array( 'enable_order_sync' => '1' ), '1', true ),
		'never saved'                       => array( array(), 'absent', true ),
		'No, with the switch at No'         => array( array( 'enable_order_sync' => '0', 'push_stock_to_ebay' => '0' ), '0', false ),
		'No, with the switch chosen as Yes' => array( array( 'enable_order_sync' => '0', 'push_stock_to_ebay' => '1' ), '0', true ),
	) as $label => $case ) {
		fresh_shop( $case[0] );
		$tcg_before = serialize( settings() );
		upgraded();
		check( 'with WP-Lister active, a shop ' . $label . ': no line, the settings untouched, item 17\'s record, pushes as they were', array( $W['log'], serialize( settings() ) === $tcg_before, switch_record(), TCGiant_Sync_Inventory::pushes_enabled() ), array( array(), true, array( 'since' => '3.28.0', 'legacy' => $case[1], 'flipped' => false, 'notice' => 'none', 'flip' => true ), $case[2] ) );
	}

	// For the later requests (children of the parent): a held shop, its notice still due.
	fresh_shop( array( 'enable_order_sync' => '0' ) );
	upgraded();
	file_put_contents( $argv[2], serialize( $W['options'] ) );
	done();
}

if ( 'child=network' === $MODE ) {
	echo "\n(child) WP-LISTER FOR EBAY ACTIVE FOR THE WHOLE NETWORK OF A MULTISITE\n" . str_repeat( '=', 124 ) . "\n";
	$W['multisite']                                     = true;
	$W['site_options']['active_sitewide_plugins']       = array( WPLISTER => 1761000000 );
	$W['options']['active_plugins']                     = array( 'woocommerce/woocommerce.php' );
	check( 'the child loads it as a network plugin - not one of this site\'s own - and the converter sees it', array( load_plugins(), TCGiant_Sync_WPLister_Converter::wplister_active() ), array( array( WPLISTER ), true ) );
	fresh_shop( array( 'enable_order_sync' => '0' ) );
	upgraded();
	check( 'a "No" shop on the network: not turned on, stored as its own No, held for WP-Lister, one warning', array( TCGiant_Sync_Inventory::pushes_enabled(), settings()['push_stock_to_ebay'] ?? null, switch_record()['held'] ?? null, switch_record()['flipped'] ?? null, upgrade_lines() ), array( false, '0', 'wplister', false, array( array( 'warning', true, false ) ) ) );
	check( '  and the notice says why and how', holds( notice_html(), HELD_NOTICE ), all_true( HELD_NOTICE ) );
	done();
}

if ( 'child=wplister-fallback' === $MODE ) {
	echo "\n(child) WP-LISTER FOR EBAY ACTIVE, WITH THE RELEASE SWITCH OFF\n" . str_repeat( '=', 124 ) . "\n";
	$W['options']['active_plugins'] = array( WPLISTER );
	check( 'the child runs the class with the release switch off, and WP-Lister for eBay loaded', array( TCGiant_Sync_Inventory::FLIP_LEGACY_ZERO, load_plugins() ), array( false, array( WPLISTER ) ) );
	fresh_shop( array( 'enable_order_sync' => '0' ) );
	upgraded();
	check( 'a "No" shop: off, stored as its own No, held for WP-Lister, one warning', array( TCGiant_Sync_Inventory::pushes_enabled(), settings()['push_stock_to_ebay'] ?? null, switch_record(), upgrade_lines() ), array( false, '0', array( 'since' => '3.28.0', 'legacy' => '0', 'flipped' => false, 'notice' => 'pending', 'flip' => false, 'held' => 'wplister' ), array( array( 'warning', true, false ) ) ) );
	check( '  the notice is WP-Lister\'s, not the one asking to turn sending on', array( holds( notice_html(), HELD_NOTICE ), false !== strpos( notice_html(), 'turn on "Send website stock changes to eBay?"' ) ), array( all_true( HELD_NOTICE ), false ) );
	done();
}

if ( 'child=wplister-later' === $MODE ) {
	echo "\n(child) WP-LISTER FOR EBAY DEACTIVATED SINCE THE UPGRADE\n" . str_repeat( '=', 124 ) . "\n";
	$W['options']['active_plugins'] = array( 'woocommerce/woocommerce.php' );
	check( 'the child does not load WP-Lister for eBay: deactivated, still installed', array( load_plugins(), TCGiant_Sync_WPLister_Converter::wplister_active() ), array( array(), false ) );
	$tcg_state = @unserialize( (string) file_get_contents( $argv[2] ) );
	check( '  the held shop, from the child that upgraded it', is_array( $tcg_state ) && 'wplister' === ( $tcg_state[ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ]['held'] ?? null ), true );
	fresh_shop( null );
	$W['options'] = (array) $tcg_state;
	product( 710, 3, '710000000710' );
	foreach ( array( 'front', 'cron', 'admin', 'cli' ) as $ctx ) {
		$W['context'] = $ctx;
		do_action( 'init' );
	}
	$W['context'] = 'admin';
	check( 'later requests: still off, nothing said, the record and the settings as they were', array( TCGiant_Sync_Inventory::pushes_enabled(), $W['log'], switch_record() === ( $tcg_state[ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ] ?? 1 ), settings() === ( $tcg_state['tcgiant_sync_ebay_settings'] ?? 1 ) ), array( false, array(), true, true ) );
	hand_edit( 710, 2 );
	check( '  a stock change is not queued', pushes(), array() );
	check( '  the health panel: Off', $ADMIN->get_system_health()['stock_push']['text'] ?? null, 'Off' );
	check( '  the notice, still due, says how to turn it on', array( TCGiant_Sync_Inventory::push_notice_pending(), holds( notice_html(), HELD_NOTICE ) ), array( true, all_true( HELD_NOTICE ) ) );
	submit_form( settings_page(), 0, array( 'push_stock_to_ebay' => '1' ) );
	$W['queue'] = array();
	hand_edit( 710, 1 );
	check( '  until the shop sets Yes: then sent, and the notice away', array( TCGiant_Sync_Inventory::push_switch_state(), push_args(), TCGiant_Sync_Inventory::push_notice_pending() ), array( 'on', array( array( 710, 'edit' ) ), false ) );
	done();
}

if ( 'child=wplister-redecide' === $MODE ) {
	echo "\n(child) A HELD SHOP IN A LATER RELEASE WITH THE SWITCH OFF\n" . str_repeat( '=', 124 ) . "\n";
	check( 'the child runs the class with the release switch off, without WP-Lister', array( TCGiant_Sync_Inventory::FLIP_LEGACY_ZERO, load_plugins() ), array( false, array() ) );
	$tcg_state = @unserialize( (string) file_get_contents( $argv[2] ) );
	fresh_shop( null );
	$W['options'] = (array) $tcg_state;
	upgraded();
	upgraded();
	$tcg_want                                                 = (array) $tcg_state;
	$tcg_want[ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ]['flip'] = false;
	check( 'the held shop is not decided again: no line, still off, its record only learns the switch', array( $W['log'], TCGiant_Sync_Inventory::pushes_enabled(), $W['options'] === $tcg_want ), array( array(), false, true ) );
	file_put_contents( $argv[2], serialize( $W['options'] ) );
	done();
}

if ( 'child=redecide' === $MODE ) {
	echo "\n(child) A LATER RELEASE WITH THE SWITCH OFF (F4)\n" . str_repeat( '=', 124 ) . "\n";
	check( 'the child runs the class with the release switch off', TCGiant_Sync_Inventory::FLIP_LEGACY_ZERO, false );
	$tcg_state = unserialize( (string) file_get_contents( $argv[2] ) );
	$tcg_out   = array();
	foreach ( $tcg_state as $shop => $options ) {
		fresh_shop( null );
		$W['options'] = $options;
		upgraded();
		upgraded();
		$tcg_out[ $shop ] = array( 'options' => $W['options'], 'log' => $W['log'], 'enabled' => TCGiant_Sync_Inventory::pushes_enabled(), 'notice' => notice_html(), 'calls' => calls(), 'pushes' => pushes() );
	}
	// Two requests racing: both began with the record decided by the other
	// switch, and the other one rewrote it first.
	fresh_shop( null );
	$W['options'] = $tcg_state['never saved, "No"'];
	in_one_request( function () {
		$mine                  = $GLOBALS['W']['cache'];
		$GLOBALS['W']['cache'] = null;
		upgraded();
		$GLOBALS['W']['cache'] = $mine;
		upgraded();
	} );
	$tcg_out['never saved, "No"']['race_lines'] = count_logged( 'does not send this site' );
	// An untouched Import save in the later build, on a shop that never had the old setting.
	fresh_shop( null );
	$W['options'] = $tcg_state['never had it, never saved'];
	upgraded();
	submit_form( settings_page(), 0 );
	$tcg_out['never had it, never saved']['saved_enabled'] = TCGiant_Sync_Inventory::pushes_enabled();
	$tcg_out['never had it, never saved']['saved_eos']     = settings()['enable_order_sync'] ?? null;
	file_put_contents( $argv[2], serialize( $tcg_out ) );
	done();
}

/** A shop's options after the upgrade, as this build decides it, for the re-decision child. */
function redecide_in_child( array $state ) {
	$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tcg-redecide-' . getmypid() . '.ser';
	file_put_contents( $file, serialize( $state ) );
	$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' child=redecide ' . escapeshellarg( $file ) . ' 2>&1' );
	$res = @unserialize( (string) file_get_contents( $file ) );
	unlink( $file );
	if ( ! preg_match( '/(\d+) passed, 0 failed/', $out ) || ! is_array( $res ) ) {
		echo preg_replace( '/^/m', '      ', $out ), "\n";
		return null;
	}
	return $res;
}

/** Run a child of this harness, handing it $arg if given; its checks count here. */
function child( $mode, $arg = null ) {
	global $pass, $fail;
	$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( 'child=' . $mode ) . ( null === $arg ? '' : ' ' . escapeshellarg( $arg ) ) . ' 2>&1' );
	// Its summary reworded, so that tests/run.php, which reads the first
	// summary in the output, reads this harness's own.
	echo preg_replace( '/^(\s*)(\d+) passed, (\d+) failed[ \t]*$/m', '$1(child: $2 checks passed, $3 failed)', $out );
	if ( preg_match( '/(\d+) passed, (\d+) failed/', $out, $m ) ) {
		$pass += (int) $m[1];
		$fail += (int) $m[2];
	} else {
		check( 'the ' . $mode . ' child finished', false, true );
	}
}

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nTHE SWITCH ITSELF\n" . str_repeat( '=', 124 ) . "\n";

check( 'the release switch is on: the push rework ships with this', TCGiant_Sync_Inventory::FLIP_LEGACY_ZERO, true );
foreach ( array(
	'"Reduce ... on eBay sale?" saved as No, push switch not answered' => array( array( 'enable_order_sync' => '0' ), true ),
	'  saved as Yes'                                                   => array( array( 'enable_order_sync' => '1' ), true ),
	'  never saved'                                                    => array( array(), true ),
	'  no settings at all'                                             => array( null, true ),
	'"Send website stock changes to eBay?" No, whatever the other says' => array( array( 'push_stock_to_ebay' => '0', 'enable_order_sync' => '1' ), false ),
	'"Send website stock changes to eBay?" Yes over the old No'        => array( array( 'push_stock_to_ebay' => '1', 'enable_order_sync' => '0' ), true ),
) as $label => $case ) {
	check( $label . ': ' . ( $case[1] ? 'sent' : 'not sent' ), array( TCGiant_Sync_Inventory::pushes_enabled( $case[0] ), TCGiant_Sync_Inventory::pushes_enabled( null === $case[0] ? false : $case[0] ) ), array( $case[1], $case[1] ) );
}
fresh_shop( array( 'push_stock_to_ebay' => '0' ) );
check( 'with no settings given, it reads them', TCGiant_Sync_Inventory::pushes_enabled(), false );
// Stored values no form would write: read without a PHP warning, and taken as
// not answered (the switch) or not saved (the old setting).
$tcg_warnings = array();
set_error_handler( function ( $no, $str ) use ( &$tcg_warnings ) { $tcg_warnings[] = $str; return true; } );
$tcg_odd = array( TCGiant_Sync_Inventory::pushes_enabled( array( 'push_stock_to_ebay' => array( '0' ) ) ) );
fresh_shop( array( 'enable_order_sync' => array( '0' ) ) );
upgraded();
$tcg_odd[] = switch_record()['legacy'] ?? null;
restore_error_handler();
check( '  a switch or old setting stored as a list: no warning, on, and the old one as never saved', array( $tcg_warnings, $tcg_odd ), array( array(), array( true, 'absent' ) ) );
/** The functions of a file that name this, in order, once each. */
function functions_naming( $file, $needle ) {
	$out = array();
	$at  = 0;
	$src = (string) file_get_contents( $file );
	while ( false !== ( $at = strpos( $src, $needle, $at ) ) ) {
		preg_match_all( '/function\s+(\w+)\s*\(/', substr( $src, 0, $at ), $m );
		$out[] = end( $m[1] );
		$at   += strlen( $needle );
	}
	return array_values( array_unique( $out ) );
}
check( 'one place decides: in the inventory class only the frozen legacy rule (legacy_zero()) and the upgrade read "Reduce ... on eBay sale?"', functions_naming( $ROOT . '/includes/class-tcgiant-sync-inventory.php', "'enable_order_sync'" ), array( 'legacy_zero', 'maybe_upgrade_push_switch' ) );
$tcg_admin_src = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
$tcg_forms_at  = (int) strpos( $tcg_admin_src, 'const SETTINGS_FORMS' );
$tcg_named_at  = (int) strpos( $tcg_admin_src, "'enable_order_sync'" );
check( '  and elsewhere only order sync, for what it does now - the admin names it once, as a field of Import Settings', array( functions_naming( $ROOT . '/includes/class-tcgiant-sync-importer.php', "'enable_order_sync'" ), functions_naming( $ROOT . '/includes/class-tcgiant-sync-cron.php', "'enable_order_sync'" ), substr_count( $tcg_admin_src, "'enable_order_sync'" ), $tcg_named_at > $tcg_forms_at && $tcg_named_at < (int) strpos( $tcg_admin_src, "'export' => array(", $tcg_forms_at ) ), array( array( 'sync_recent_orders' ), array(), 1, true ) );
check( '  every push path asks it: the three queues (the push after an eBay sale, item 14\'s, among them), the run, the stop checks', functions_naming( $ROOT . '/includes/class-tcgiant-sync-inventory.php', 'self::pushes_enabled(' ), array( 'queue_after_ebay_sale', 'queue_stock_update_for_product', 'push_stopped', 'push_switch_state', 'queue_order_pushes', 'run_push' ) );
check( '  and each of the three queues asks whether it may queue at all (may_queue())', functions_naming( $ROOT . '/includes/class-tcgiant-sync-inventory.php', 'self::may_queue( $settings )' ), array( 'queue_after_ebay_sale', 'queue_stock_update_for_product', 'queue_order_pushes' ) );
$tcg_inv_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-inventory.php' );
check( '  and the stop checks read the settings from their row, not this request\'s copy', array( substr_count( $tcg_inv_src, 'self::pushes_enabled( self::settings_row() )' ), substr_count( $tcg_inv_src, '$row = \'manual\' === $job[\'reason\'] ? null : self::settings_row();' ) ), array( 1, 1 ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nWEBSITE STOCK CHANGES REACH EBAY ON A SHOP THAT SAVED \"NO\" (T1, FAILS ON 3.27)\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( array( 'enable_order_sync' => '0' ) );
product( 300, 5, '300000000300' );
hand_edit( 300, 4 );
check( 'T1 a hand edit, 5 to 4: one push queued, for the product', push_args(), array( array( 300, 'edit' ) ) );
$W['queue'] = array();
website_order( 620, array( 300 => 1 ) );
check( '  a website sale of a linked, tracked product: one push for the sale', push_args(), array( array( 300, 'order' ) ) );
check( '  and nothing said about pushes being off', logged( 'not sent to eBay' ), false );

echo "\nSWITCHED OFF: NOTHING QUEUED, SAID ONCE A DAY (T2)\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( array( 'push_stock_to_ebay' => '0', 'enable_order_sync' => '1' ) );
product( 310, 5, '310000000310' );
hand_edit( 310, 4 );
website_order( 621, array( 310 => 1 ) );
check( 'T2 "Send ... to eBay?" No: neither path queues a push', pushes(), array() );
check( '  exactly one line says so, at info level', array( count_logged( 'not sent to eBay because "Send website stock changes to eBay?" is No' ), logged( 'not sent to eBay because', 'info' ) ), array( 1, true ) );
hand_edit( 310, 2 );
check( '  a second change the same day adds no line', count_logged( 'not sent to eBay because' ), 1 );
unset( $W['transients'][ TCGiant_Sync_Inventory::PUSH_OFF_NOTED ] );
hand_edit( 310, 1 );
check( '  the next day says it again', count_logged( 'not sent to eBay because' ), 2 );
fresh_shop( array( 'push_stock_to_ebay' => '0' ) );
product( 311, 5, '310000000311' );
website_order( 624, array( 311 => 1 ) );
check( '  a website sale alone says it too', array( pushes(), count_logged( 'not sent to eBay because' ) ), array( array(), 1 ) );

echo "\nNO SETTINGS, AND NO CONNECTION (T3, T4)\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( array() );
product( 320, 5, '320000000320' );
hand_edit( 320, 4 );
website_order( 622, array( 320 => 1 ) );
check( 'T3 nothing set but the connection: both paths queue', push_args(), array( array( 320, 'edit' ), array( 320, 'order' ) ) );
fresh_shop( array( 'enable_order_sync' => '0' ), false );
product( 321, 5, '320000000321' );
hand_edit( 321, 4 );
website_order( 623, array( 321 => 1 ) );
check( 'T4 a "No" shop never connected to eBay (no tokens, no disconnect): nothing queued', pushes(), array() );
check( '  and no error logged, nor anything about pushes being off', array( errors_logged(), logged( 'not sent' ) ), array( 0, false ) );
fresh_shop( array( 'refresh_token' => '' ) );
product( 322, 5, '320000000322' );
hand_edit( 322, 4 );
check( '  half a connection - no refresh token - is none either', pushes(), array() );

echo "\nDISCONNECTED FOR A WHILE: THE CHANGE STILL REACHES EBAY AFTER THE RECONNECT (F1, F3)\n" . str_repeat( '=', 124 ) . "\n";

check( 'the window is item 14\'s tries and an hour', TCGiant_Sync_Inventory::PUSH_RECONNECT_WINDOW, array_sum( TCGiant_Sync_Inventory::PUSH_RETRY_DELAYS ) + HOUR_IN_SECONDS );
$tcg_disconnect = function () {
	$_REQUEST = array( '_wpnonce' => 'nonce-tcgiant_sync_disconnect' );
	try {
		$GLOBALS['ADMIN']->handle_disconnect();
	} catch ( Harness_Redirect $r ) {
		unset( $r );
	}
	$_REQUEST = array();
};
$tcg_reconnect = function () { update_option( 'tcgiant_sync_ebay_settings', array_merge( settings(), TOKENS ) ); };
fresh_shop( array() );
product( 500, 1, '500000000500' );
product( 501, 5, '500000000501' );
ebay_has( '500000000500', listing( '500000000500', 1, 0 ) );
ebay_has( '500000000501', listing( '500000000501', 5, 0 ) );
$tcg_disconnect();
check( 'Disconnect stamps the time, in an option of its own', array( abs( (int) get_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, 0 ) - time() ) <= 2, array_key_exists( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, settings() ) ), array( true, false ) );
website_order( 800, array( 500 => 1 ) );
hand_edit( 501, 2 );
check( '  a website sale and a hand edit a few minutes later: both queued', push_args(), array( array( 500, 'order' ), array( 501, 'edit' ) ) );
run_queue();
check( '  their first tries find no token and are tried again, as item 14 does', array( calls(), count( pushes() ), logged( 'not connected to eBay' ) ), array( array(), 2, false ) );
$tcg_reconnect();
check( '  reconnected: the stamp goes', get_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, 'gone' ), 'gone' );
$W['wire'] = array();
run_queue( true );
$tcg_sent = array();
foreach ( $W['wire'] as $w ) {
	if ( 'EndItem' === $w[0] && preg_match( '#<ItemID>(\d+)</ItemID>#', $w[1], $m ) ) {
		$tcg_sent[] = 'EndItem ' . $m[1];
	} elseif ( 'ReviseInventoryStatus' === $w[0] && preg_match( '#<ItemID>(\d+)</ItemID>.*?<Quantity>(-?\d+)</Quantity>#s', $w[1], $m ) ) {
		$tcg_sent[] = 'ReviseInventoryStatus ' . $m[1] . ' qty ' . $m[2];
	}
}
check( '  the tries after the reconnect send them: the coin\'s listing ended, the other at 2', $tcg_sent, array( 'EndItem 500000000500', 'ReviseInventoryStatus 500000000501 qty 2' ) );
check( '  nothing left queued, nothing listed as failed', array( pushes(), get_option( TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION, array() ) ), array( array(), array() ) );

// Away longer than the tries would last.
fresh_shop( array() );
product( 510, 1, '510000000510' );
product( 511, 5, '510000000511' );
website_order( 810, array( 510 => 1 ) );
hand_edit( 511, 4 );
$tcg_disconnect();
update_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, time() - TCGiant_Sync_Inventory::PUSH_RECONNECT_WINDOW - 1, false );
$W['log'] = array();
run_queue( true );
check( 'disconnected longer than the window: pushes queued before it end at their first try', array( calls(), pushes() ), array( array(), array() ) );
check( '  one line each, not listed as failed, no error', array( count_logged( 'not sent: this site is not connected to eBay' ), get_option( TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION, array() ), errors_logged() ), array( 2, array(), 0 ) );
check( '  the sale keeps its unsent mark, so an import cannot bring the sold unit back', array( array_key_exists( 'unsent', (array) meta( 510, '_ebay_push_pending' ) ), array_key_exists( 'unsent', (array) meta( 511, '_ebay_push_pending' ) ) ), array( true, false ) );
hand_edit( 511, 3 );
check( '  and a stock change now queues nothing', pushes(), array() );
update_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, time() - TCGiant_Sync_Inventory::PUSH_RECONNECT_WINDOW + 60, false );
hand_edit( 511, 2 );
check( '  (a minute inside the window it is queued)', push_args(), array( array( 511, 'edit' ) ) );
// A try run in a request whose copy still holds tokens that went long ago: the row is read.
fresh_shop( array() );
product( 513, 5, '510000000513' );
hand_edit( 513, 4 );
$W['log'] = array();
in_one_request( function () {
	other_request_saves( 'access_token', null );
	other_request_saves( 'refresh_token', null );
	update_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, time() - TCGiant_Sync_Inventory::PUSH_RECONNECT_WINDOW - 1, false );
	$GLOBALS['tcg_ran'] = run_queue( true );
} );
check( '  a try in a request whose copy still has the tokens, gone from the row long since: ends at once all the same', array( calls(), pushes(), count_logged( 'not sent: this site is not connected to eBay' ) ), array( array(), array(), 1 ) );
check( '    at its first try: one action run, no error', array( $GLOBALS['tcg_ran'], errors_logged() ), array( 1, 0 ) );
// A checkout begun while disconnected, that takes the stock after the
// reconnect was saved by another request (SW2-L1): the row is read.
fresh_shop( array() );
product( 514, 1, '510000000514' );
$tcg_disconnect();
in_one_request( function () {
	other_request_saves( 'access_token', TOKENS['access_token'] );
	other_request_saves( 'refresh_token', TOKENS['refresh_token'] );
	unset( $GLOBALS['W']['options'][ TCGiant_Sync_Inventory::DISCONNECTED_OPTION ], $GLOBALS['W']['cache'][ TCGiant_Sync_Inventory::DISCONNECTED_OPTION ] );
	website_order( 814, array( 514 => 1 ) );
} );
check( 'a checkout begun before another request reconnected, taking the stock after: the sale is queued', push_args(), array( array( 514, 'order' ) ) );
// A shop with no tokens: a CSV of 100 products not on eBay, and a website
// sale of one, ask the database nothing (SW3-L2); a product on eBay still
// reads, and is queued inside the window.
fresh_shop( array(), false );
for ( $i = 0; $i < 100; $i++ ) {
	product( 1000 + $i, 5, '' );
}
$W['row_reads'] = array();
for ( $i = 0; $i < 100; $i++ ) {
	hand_edit( 1000 + $i, 4 );
}
website_order( 1900, array( 1000 => 1, 1001 => 1 ) );
check( 'a shop with no tokens: 100 unlinked products\' stock changes and a sale of two read no row', array( $W['row_reads'], pushes() ), array( array(), array() ) );
update_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, time() - 60, false );
product( 1100, 5, '110000001100' );
$W['row_reads'] = array();
hand_edit( 1100, 4 );
website_order( 1901, array( 1100 => 1 ) );
check( '  one on eBay reads the row and the stamp, and is queued inside the window', array( count( pushes() ), ( $W['row_reads']['tcgiant_sync_ebay_settings'] ?? 0 ) > 0, ( $W['row_reads'][ TCGiant_Sync_Inventory::DISCONNECTED_OPTION ] ?? 0 ) > 0 ), array( 2, true, true ) );
product( 1101, 5, '110000001101' );
$W['row_reads'] = array();
website_order( 1902, array( 1100 => 1, 1101 => 1 ) );
check( '  a sale of two on eBay asks once', $W['row_reads'], array( 'tcgiant_sync_ebay_settings' => 1, TCGiant_Sync_Inventory::DISCONNECTED_OPTION => 1 ) );
// The push after an eBay sale (item 14) asks the same: never connected,
// nothing queued; not on eBay, nothing read; connected, queued.
fresh_shop( array(), false );
product( 1110, 5, '111000001110' );
product( 1111, 5, '' );
$W['row_reads'] = array();
TCGiant_Sync_Inventory::queue_after_ebay_sale( 1111 );
check( 'the push after an eBay sale, not on eBay, on a shop with no tokens: nothing queued, nothing read', array( pushes(), $W['row_reads'] ), array( array(), array() ) );
TCGiant_Sync_Inventory::queue_after_ebay_sale( 1110 );
check( '  on eBay, never connected: nothing queued', pushes(), array() );
fresh_shop( array() );
product( 1110, 5, '111000001110' );
TCGiant_Sync_Inventory::queue_after_ebay_sale( 1110 );
check( '  connected: queued, as a sale\'s', array( push_args(), ! empty( pushes()[0]['args'][0]['ebay_sale'] ) ), array( array( array( 1110, 'edit' ) ), true ) );
// The row is read only when this request's copy has no tokens.
fresh_shop( array() );
product( 516, 5, '510000000516' );
$W['row_reads'] = array();
hand_edit( 516, 4 );
website_order( 816, array( 516 => 1 ) );
check( '  a connected shop\'s stock changes are queued without a read of the row or the stamp', array( count( pushes() ), $W['row_reads'] ), array( 2, array() ) );
fresh_shop( array(), false );
product( 515, 1, '510000000515' );
in_one_request( function () {
	// Another request connects and disconnects again: a stamp in the row, none in this request's copy.
	$GLOBALS['W']['options'][ TCGiant_Sync_Inventory::DISCONNECTED_OPTION ] = time();
	hand_edit( 515, 0 );
} );
check( '  and the disconnect stamp is read from its row: one written after this request began counts', push_args(), array( array( 515, 'edit' ) ) );
// A token missing with the tokens still stored (a refresh eBay refuses): item 14's tries, as before.
fresh_shop( array() );
product( 512, 1, '510000000512' );
TCGiant_Sync_OAuth::$connected = false;
website_order( 812, array( 512 => 1 ) );
run_queue();
TCGiant_Sync_OAuth::$connected = null;
check( 'no token from OAuth while both are stored: tried again, as item 14 does', array( count( pushes() ), logged( 'not connected to eBay' ) ), array( 1, false ) );
// The stamp is the update's, whatever made it: a save of the settings without the tokens.
fresh_shop( array() );
$s = settings();
unset( $s['access_token'], $s['refresh_token'] );
update_option( 'tcgiant_sync_ebay_settings', $s );
check( 'any save that takes the tokens away stamps it; one that keeps them leaves none', array( false !== get_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, false ) ), array( true ) );
fresh_shop( array() );
set_setting( 'log_level', 'error' );
check( '  (a save that keeps them leaves none)', get_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, false ), false );
fresh_shop( array(), false );
set_setting( 'log_level', 'error' );
check( '  nor does a save on a shop that had none to take away', get_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, false ), false );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nEVERY STOCK WRITE THAT CAME FROM EBAY STAYS FROM EBAY: NO ECHO (T5)\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( array( 'enable_order_sync' => '1' ) );
product( 330, 5, '330000000330' );
$INV->on_product_stock_change( wc_get_product( 330 ) );
check( 'T5 the harness has teeth: an unguarded stock change of a linked product is queued', push_args(), array( array( 330, 'edit' ) ) );
$tcg_echoes = array(
	'apply_ended_listing_stock()' => function () { product( 331, 5, '330000000331' ); TCGiant_Sync_Inventory::apply_ended_listing_stock( 331, 3, 2 ); return 331; },
	'lower_stock_to()'            => function () { product( 332, 5, '330000000332' ); TCGiant_Sync_Inventory::lower_stock_to( 332, 2 ); return 332; },
	'zero_stock()'                => function () { product( 333, 2, '330000000333', array( '_ebay_listing_status' => 'Ended' ) ); TCGiant_Sync_Inventory::zero_stock( 333 ); return 333; },
	'use_ebay_figure()'           => function () { product( 334, 0, '330000000334', array( '_ebay_listing_status' => 'Ended', '_ebay_unsold_at_end' => '2' ) ); TCGiant_Sync_Inventory::use_ebay_figure( 334 ); return 334; },
	'a mapper-style save'         => function () {
		product( 335, 5, '330000000335' );
		TCGiant_Sync_Inventory::begin_ebay_origin();
		try {
			wc_update_product_stock( 335, 3, 'set' );
		} finally {
			TCGiant_Sync_Inventory::end_ebay_origin();
		}
		return 335;
	},
);
foreach ( $tcg_echoes as $label => $write ) {
	$W['queue']  = array();
	$W['writes'] = array();
	$id          = $write();
	$written     = array_values( array_filter( $W['writes'], function ( $w ) use ( $id ) { return $w[0] === $id; } ) );
	check( '  ' . $label . ': the stock is written, inside the guard, and nothing is queued', array( count( $written ) > 0, array_values( array_unique( array_column( $written, 3 ) ) ), pushes() ), array( true, array( true ), array() ) );
}
// Order sync's own decrements: a product not linked to a listing. Stock a
// linked listing's variation shares with its parent is no longer taken off
// here but by its stock check (3.28.0 release fix round 1): order sync left
// it to be taken off again by every read of eBay after it.
$W['queue']  = array();
$W['writes'] = array();
product( 336, 4, '' );
product( 337, null, '330000000337', array( '_sku' => 'SET-337', '_manage_stock' => 'yes', '_stock' => 6 ) );
$W['posts'][337]['kind'] = 'variable';
variation( 338, 337, 'SET-337-RED', null );
$W['ebay']['orders'] = array( 'orders' => array(
	array( 'orderId' => 'O-1', 'lineItems' => array( array( 'lineItemId' => 'L-1', 'sku' => 'SKU-336', 'legacyItemId' => '330000000999', 'quantity' => 1 ) ) ),
	array( 'orderId' => 'O-2', 'lineItems' => array( array( 'lineItemId' => 'L-2', 'sku' => 'SET-337-RED', 'legacyItemId' => '330000000337', 'quantity' => 2 ) ) ),
) );
$IMP->sync_recent_orders();
check( '  order sync taking a sale off an unlinked product: inside the guard; a variation\'s shared stock left to its stock check', array( stock( 336 ), stock( 337 ), array_values( array_unique( array_column( $W['writes'], 3 ) ) ) ), array( 3, 6, array( true ) ) );
check( '  nothing queued for either', pushes(), array() );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nA PUSH QUEUED BEFORE THE SWITCH WENT OFF STOPS WHEN IT RUNS (T6)\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( array() );
product( 340, 5, '340000000340' );
ebay_has( '340000000340', listing( '340000000340', 5, 0 ) );
hand_edit( 340, 4 );
website_order( 630, array( 340 => 1 ) );
set_setting( 'push_stock_to_ebay', '0' );
run_queue( true );
check( 'T6 an edit and a sale queued, then switched off: no eBay call', calls(), array() );
check( '  nothing tried again, no safety try, no running mark', array( queued_hooks(), meta( 340, '_ebay_push_running' ) ), array( array(), null ) );
check( '  one line each says why', count_logged( 'Stock push for WC #340 not sent: "Send website stock changes to eBay?" is No.' ), 2 );
check( '  and the sale is not held back as unsent', array_key_exists( 'unsent', (array) meta( 340, '_ebay_push_pending' ) ), false );

// A try again, after eBay did not answer, that comes round switched off.
fresh_shop( array() );
product( 341, 3, '340000000341' );
ebay_has( '340000000341', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
website_order( 631, array( 341 => 1 ) );
run_queue();
$W['wire'] = array();
$tcg_retry = pushes();
set_setting( 'push_stock_to_ebay', '0' );
run_queue( true );
check( '  a try again queued after a timeout, run once switched off: no call, nothing queued', array( count( $tcg_retry ) > 0, calls(), queued_hooks(), meta( 341, '_ebay_push_running' ) ), array( true, array(), array(), null ) );

// Switched off while a try is out with eBay, which then fails.
fresh_shop( array() );
product( 342, 3, '340000000342' );
ebay_has( '340000000342', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
$W['on_call']['GetItem'] = function () { other_request_saves( 'push_stock_to_ebay', '0' ); };
website_order( 632, array( 342 => 1 ) );
in_one_request( function () { run_queue(); } );
check( '  switched off by another request while the try was out, and it failed: only that read (the API\'s own three tries), not tried again, no safety try left', array( array_values( array_unique( calls() ) ), queued_hooks(), meta( 342, '_ebay_push_running' ) ), array( array( 'GetItem' ), array(), null ) );
check( '  and says so', logged( 'is not tried again: "Send website stock changes to eBay?" is No in Settings.' ), true );
check( '  not held back as an unsent sale', array_key_exists( 'unsent', (array) meta( 342, '_ebay_push_pending' ) ), false );

// A sale's last try, eBay out of reach, switched off while it is out: the
// push would be given up and the sale held back from imports as unsent.
$tcg_last_try = function ( $id, $off ) {
	fresh_shop( array() );
	product( $id, 2, '34000000' . $id ); // The website sold one of eBay's 3.
	ebay_has( '34000000' . $id, listing( '34000000' . $id, 3, 0 ) );
	ebay_answers( 'ReviseInventoryStatus', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ), new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ), new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
	if ( $off ) {
		$GLOBALS['W']['on_call']['ReviseInventoryStatus'] = function () { other_request_saves( 'push_stock_to_ebay', '0' ); };
	}
	in_one_request( function () use ( $id ) {
		do_action( TCGiant_Sync_Inventory::PUSH_HOOK, array( 'product_id' => $id, 'reason' => 'order', 'qty' => 1, 'attempt' => 6, 'item' => '34000000' . $id, 'token' => 'sale-x' ) );
	} );
};
$tcg_last_try( 343, true );
check( '  a sale\'s last try, switched off by another request while it was out: not given up, not held back as unsent, nothing queued', array( in_array( 'ReviseInventoryStatus', calls(), true ), logged( 'gave up' ), array_key_exists( 'unsent', (array) meta( 343, '_ebay_push_pending' ) ), queued_hooks() ), array( true, false, false, array() ) );
$tcg_last_try( 344, false );
check( '  (left on, the same try is given up and the sale held back as unsent, as item 14 does)', array( logged( 'gave up' ), array_key_exists( 'unsent', (array) meta( 344, '_ebay_push_pending' ) ) ), array( true, true ) );

// One batch of Action Scheduler - one request - switched off by another
// request after its first push went: the batch's others do not send.
fresh_shop( array() );
for ( $i = 0; $i < 3; $i++ ) {
	product( 346 + $i, 5, (string) ( 346000000346 + $i ) );
	hand_edit( 346 + $i, 4 );
}
$W['on_call']['ReviseInventoryStatus'] = function () { other_request_saves( 'push_stock_to_ebay', '0' ); };
in_one_request( function () { run_queue( true ); } );
check( '  one batch, switched off by another request after its first push: the other two do not send', array( count( array_filter( calls(), function ( $c ) { return 'ReviseInventoryStatus' === $c; } ) ), queued_hooks() ), array( 1, array() ) );
// A try again queued before, run in a request whose copy is older than the switch-off.
fresh_shop( array() );
product( 349, 3, '340000000349' );
ebay_has( '340000000349', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
website_order( 639, array( 349 => 1 ) );
run_queue();
$W['wire'] = array();
in_one_request( function () {
	other_request_saves( 'push_stock_to_ebay', '0' );
	run_queue( true );
} );
check( '  a try again in a request that began before the switch-off was saved: stops all the same', array( calls(), queued_hooks() ), array( array(), array() ) );

// A safety try that comes round switched off, its try still marked running:
// it used to be put off to look again in a quarter of an hour.
fresh_shop( array( 'push_stock_to_ebay' => '0' ) );
product( 345, 5, '340000000345' );
update_post_meta( 345, TCGiant_Sync_Inventory::PUSH_RUNNING_KEY, array( 'run-a' => array( 'started' => time() - 60, 'when' => time() ) ) );
do_action( TCGiant_Sync_Inventory::PUSH_HOOK, array( 'product_id' => 345, 'reason' => 'edit', 'attempt' => 2, 'safety' => 'run-a' ) );
check( '  a safety try, switched off, its try still running: final - nothing put off to look again', array( calls(), queued_hooks(), meta( 345, '_ebay_push_running' ) ), array( array(), array(), null ) );
check( '  saying why, once', count_logged( 'Stock push for WC #345 not sent' ), 1 );
// One handed a try again due later (a site without Action Scheduler 3).
update_post_meta( 345, TCGiant_Sync_Inventory::PUSH_RUNNING_KEY, array( 'run-b' => array( 'started' => time() - 1000, 'when' => time() - 1, 'next' => array( 'product_id' => 345, 'reason' => 'order', 'attempt' => 3, 'qty' => 1 ), 'at' => time() + 3600 ) ) );
do_action( TCGiant_Sync_Inventory::PUSH_HOOK, array( 'product_id' => 345, 'reason' => 'order', 'attempt' => 2, 'safety' => 'run-b' ) );
check( '  one handed a try again for an hour on: not put off to it', array( calls(), queued_hooks(), meta( 345, '_ebay_push_running' ) ), array( array(), array(), null ) );
// A leftover safety try whose try finished: nothing to say.
$W['log'] = array();
do_action( TCGiant_Sync_Inventory::PUSH_HOOK, array( 'product_id' => 345, 'reason' => 'edit', 'attempt' => 2, 'safety' => 'run-gone' ) );
check( '  a leftover safety try, its try long done: nothing, and nothing said', array( calls(), queued_hooks(), $W['log'] ), array( array(), array(), array() ) );

echo "\nA PUSH ASKED FOR BY HAND STILL GOES\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( array( 'push_stock_to_ebay' => '0' ) );
product( 350, 2, '350000000350' );
ebay_has( '350000000350', listing( '350000000350', 3, 0 ) );
$out = $INV->push_now( wc_get_product( 350 ), 'manual' );
check( 'switched off, the order screen\'s Push to eBay: eBay is read and told', array( calls(), $out['status'] ), array( array( 'GetItem', 'ReviseInventoryStatus' ), 'success' ) );

echo "\nA STAGING COPY (CHILD)\n" . str_repeat( '=', 124 ) . "\n";
child( 'staging' );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nTHE UPGRADE TURNS IT ON ONCE, AND SAYS SO (T7-T11)\n" . str_repeat( '=', 124 ) . "\n";

check( 'it runs at init, first of all, on every kind of request', has_action( 'init', array( 'TCGiant_Sync_Inventory', 'maybe_upgrade_push_switch' ) ), 1 );
fresh_shop( array( 'enable_order_sync' => '0', 'log_level' => 'warning' ) );
// A linked product whose stock drifted while pushes were off: 2 here, 5 on eBay.
product( 380, 2, '380000000380' );
ebay_has( '380000000380', listing( '380000000380', 5, 0 ) );
$before = serialize( settings() );
$W['context'] = 'front';
do_action( 'init' );
check( 'T7 a "No" shop, a shopper\'s request: one warning, naming both settings', array( count_logged( 'Updated to 3.28.0' ), logged( '"Reduce WooCommerce stock on eBay sale?"', 'warning' ), logged( '"Send website stock changes to eBay?"', 'warning' ) ), array( 1, true, true ) );
check( '  the record, with the release switch it was decided by', switch_record(), array( 'since' => '3.28.0', 'legacy' => '0', 'flipped' => true, 'notice' => 'pending', 'flip' => true ) );
check( '  autoloaded', $W['autoload'][ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ] ?? null, true );
foreach ( array( 'cron', 'admin', 'cli', 'front' ) as $ctx ) {
	$W['context'] = $ctx;
	do_action( 'init' );
}
check( '  four more requests - cron, admin, WP-CLI, a shopper - add no line and change nothing', array( count_logged( 'Updated to 3.28.0' ), switch_record()['notice'] ), array( 1, 'pending' ) );
check( '  and the settings are byte for byte as they were: nothing written to them', serialize( settings() ), $before );
check( '  nothing is sent or queued at the upgrade itself (decision 4)', array( calls(), pushes() ), array( array(), array() ) );
$W['context'] = 'admin';
foreach ( array(
	'T8 saved as Yes'                => array( array( 'enable_order_sync' => '1' ), '1' ),
	'T9 never saved'                 => array( array(), 'absent' ),
	'T10 No, with the switch at No'  => array( array( 'enable_order_sync' => '0', 'push_stock_to_ebay' => '0' ), '0' ),
) as $label => $case ) {
	fresh_shop( $case[0] );
	upgraded();
	check( $label . ': no line, no notice', array( logged( 'Updated to 3.28.0' ), switch_record(), TCGiant_Sync_Inventory::push_notice_pending() ), array( false, array( 'since' => '3.28.0', 'legacy' => $case[1], 'flipped' => false, 'notice' => 'none', 'flip' => true ), false ) );
}
check( '  in T10 pushes stay off', TCGiant_Sync_Inventory::pushes_enabled(), false );

// Two requests at once: both read "not run yet" before either claims it.
fresh_shop( array( 'enable_order_sync' => '0' ) );
upgraded();
$W['stale'][ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ] = true;
upgraded();
unset( $W['stale'][ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ] );
check( 'T11 two requests racing: the second inserts the same row, which changes nothing - one line', array( count_logged( 'Updated to 3.28.0' ), switch_record()['notice'] ), array( 1, 'pending' ) );
$tcg_inserts = 0;
add_filter( 'sanitize_option_' . TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION, function ( $v ) { $GLOBALS['tcg_inserts']++; return $v; } );
upgraded();
upgraded();
check( '  once it has run, a request only reads the record: no insert is tried', $tcg_inserts, 0 );

echo "\nTHE FALLBACK, WITH THE PUSH REWORK TAKEN OUT (CHILD)\n" . str_repeat( '=', 124 ) . "\n";
child( 'fallback' );

echo "\nA LATER RELEASE THAT CHANGES THE SWITCH DECIDES AGAIN, ONLY WHERE IT DECIDED (F4)\n" . str_repeat( '=', 124 ) . "\n";

// This build (FLIP true) upgrades three shops; a later build with FLIP false
// (a child) loads them; then this build again.
$tcg_shops = array(
	'never saved, "No"' => array( 'enable_order_sync' => '0' ),
	'saved since'       => array( 'enable_order_sync' => '0', 'push_stock_to_ebay' => '1' ),
	'"Yes"'             => array( 'enable_order_sync' => '1' ),
);
$tcg_state = array();
foreach ( $tcg_shops as $shop => $s ) {
	fresh_shop( $s );
	upgraded();
	TCGiant_Sync_Inventory::dismiss_push_notice();
	product( 520, 5, '520000000520' );
	$tcg_state[ $shop ] = $W['options'];
}
// Two that never had "Reduce ... on eBay sale?" (SW2-M1): one saved Import
// untouched here - which stores its default, No - and one never saved.
fresh_shop( array() );
upgraded();
submit_form( settings_page(), 0 );
$tcg_state['never had it, Import saved'] = $W['options'];
fresh_shop( array() );
upgraded();
$tcg_state['never had it, never saved'] = $W['options'];
// And one whose record is not a record at all: left alone, by either build.
fresh_shop( array( 'enable_order_sync' => '1' ) );
$W['options'][ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ] = 'not a record';
upgraded();
$tcg_state['broken record'] = $W['options'];
$tcg_off = redecide_in_child( $tcg_state );
check( 'the later build ran', is_array( $tcg_off ), true );
$tcg_off = (array) $tcg_off;
$tcg_a   = $tcg_off['never saved, "No"'] ?? array();
check( 'switch off in a later release: the never-saved "No" shop goes off', array( $tcg_a['enabled'] ?? null, $tcg_a['options'][ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ]['flipped'] ?? null, $tcg_a['options'][ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ]['flip'] ?? null ), array( false, false, false ) );
check( '  one line, from two requests', count( array_filter( (array) ( $tcg_a['log'] ?? array() ), function ( $l ) { return false !== strpos( $l[1], 'does not send this site' ); } ) ), 1 );
check( '    saying what this version does, plainly: not the old setting, not "wrongly"', array_map( function ( $l ) { return array( false !== strpos( $l[1], 'has never been chosen here' ), false !== strpos( $l[1], 'Reduce WooCommerce stock' ), false !== strpos( $l[1], 'wrongly' ) ); }, array_values( array_filter( (array) ( $tcg_a['log'] ?? array() ), function ( $l ) { return 0 === strpos( $l[1], 'Updated' ); } ) ) ), array( array( true, false, false ) ) );
check( '    and from two racing, both begun with the old record: one too', $tcg_a['race_lines'] ?? null, 1 );
check( '  a record that is not one: left as it is, no line', array( $tcg_off['broken record']['options'][ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ] ?? null, $tcg_off['broken record']['log'] ?? null ), array( 'not a record', array() ) );
check( '  and an "off" notice, due again', array( $tcg_a['options'][ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ]['notice'] ?? null, false !== strpos( (string) ( $tcg_a['notice'] ?? '' ), 'not sending your website' ) ), array( 'pending', true ) );
check( '    in words of its own: what this version does, not the old setting, not "wrongly"', array( false !== strpos( (string) ( $tcg_a['notice'] ?? '' ), 'this version of TCGiant Sync does not send them' ), false !== strpos( (string) ( $tcg_a['notice'] ?? '' ), 'Reduce WooCommerce stock' ), false !== strpos( (string) ( $tcg_a['notice'] ?? '' ), 'wrongly' ), false !== strpos( (string) ( $tcg_a['notice'] ?? '' ), 'not being sent to eBay' ) ), array( true, false, false, true ) );
check( '  nothing sent or queued, the settings as they were', array( $tcg_a['calls'] ?? null, $tcg_a['pushes'] ?? null, $tcg_a['options']['tcgiant_sync_ebay_settings'] ?? null ), array( array(), array(), $tcg_state['never saved, "No"']['tcgiant_sync_ebay_settings'] ) );
foreach ( array( 'saved since', '"Yes"', 'never had it, Import saved', 'never had it, never saved' ) as $shop ) {
	$tcg_b = $tcg_off[ $shop ] ?? array();
	check( '  the ' . $shop . ' shop stays on, with no line, and its record only learns the switch', array( $tcg_b['enabled'] ?? null, count( (array) ( $tcg_b['log'] ?? array( 1 ) ) ), $tcg_b['options'][ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ]['flip'] ?? null, $tcg_b['options'][ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ]['notice'] ?? null ), array( true, 0, false, 'none' ) );
}
check( '  a shop that never had the old setting, saved untouched in the later build too: still on (its default No is not the old setting)', array( $tcg_off['never had it, never saved']['saved_enabled'] ?? null, $tcg_off['never had it, never saved']['saved_eos'] ?? null ), array( true, '0' ) );
// And back: this build (FLIP true) over the later build's records.
foreach ( $tcg_off as $shop => $res ) {
	fresh_shop( null );
	$W['options'] = $res['options'];
	$W['log']     = array();
	upgraded();
	upgraded();
	if ( 'never saved, "No"' === $shop ) {
		check( 'switch on again: the same shop comes back on, with one line and a notice saying so', array( TCGiant_Sync_Inventory::pushes_enabled(), count_logged( 'sends this site\'s website stock changes to eBay' ), switch_record()['notice'] ?? null, false !== strpos( notice_html(), 'now sends your website' ) ), array( true, 1, 'pending', true ) );
		check( '  in plain words too: what this version does, not the old setting, not "wrongly"', array( false !== strpos( notice_html(), 'This version of TCGiant Sync sends this site' ), false !== strpos( notice_html() . implode( ' ', array_column( $W['log'], 1 ) ), 'Reduce WooCommerce stock' ), false !== strpos( notice_html() . implode( ' ', array_column( $W['log'], 1 ) ), 'wrongly' ) ), array( true, false, false ) );
	} else {
		check( '  the ' . $shop . ' shop: no line, still on', array( TCGiant_Sync_Inventory::pushes_enabled(), $W['log'] ), array( true, array() ) );
	}
}
// A record written before it kept the switch was decided with it on.
fresh_shop( array( 'enable_order_sync' => '0' ) );
add_option( TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION, array( 'since' => '3.28.0', 'legacy' => '0', 'flipped' => true, 'notice' => 'dismissed' ), '', true );
upgraded();
check( '  a record from before the switch was kept is taken as decided with it on: nothing happens', array( $W['log'], switch_record()['notice'] ), array( array(), 'dismissed' ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nWP-LISTER FOR EBAY ACTIVE: THE UPGRADE DOES NOT TURN PUSHES ON\n" . str_repeat( '=', 124 ) . "\n";

// Installed but not activated: WordPress does not load it, and the upgrade is item 17's.
$W['options']['active_plugins'] = array( 'woocommerce/woocommerce.php' );
check( 'WP-Lister for eBay installed but not activated: not loaded, and the converter says so', array( load_plugins(), TCGiant_Sync_WPLister_Converter::wplister_active() ), array( array(), false ) );
fresh_shop( array( 'enable_order_sync' => '0' ) );
$tcg_before = serialize( settings() );
upgraded();
check( '  a "No" shop is turned on exactly as without it: item 17\'s record and line, nothing written', array( switch_record(), upgrade_lines(), serialize( settings() ) === $tcg_before, TCGiant_Sync_Inventory::pushes_enabled() ), array( array( 'since' => '3.28.0', 'legacy' => '0', 'flipped' => true, 'notice' => 'pending', 'flip' => true ), array( array( 'warning', false, true ) ), true, true ) );
// A multisite where it is active on another site, not this one and not for the network.
$W['multisite']                 = true;
$W['site_options']              = array( 'active_sitewide_plugins' => array( 'woocommerce/woocommerce.php' => 1761000000 ) );
$W['options']['active_plugins'] = array();
check( '  on a multisite, active on another site only: not loaded on this one', array( load_plugins(), TCGiant_Sync_WPLister_Converter::wplister_active() ), array( array(), false ) );
fresh_shop( array( 'enable_order_sync' => '0' ) );
upgraded();
check( '    and its "No" shop is turned on as before', array( switch_record()['flipped'] ?? null, array_key_exists( 'held', (array) switch_record() ), TCGiant_Sync_Inventory::pushes_enabled() ), array( true, false, true ) );
$W['multisite']    = false;
$W['site_options'] = array();

// Active (children: a constant defined stays defined for the process).
$tcg_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tcg-wplister-' . getmypid() . '.ser';
child( 'wplister', $tcg_file );
child( 'network' );
child( 'wplister-fallback' );
child( 'wplister-later', $tcg_file );
child( 'wplister-redecide', $tcg_file );
$tcg_res = @unserialize( (string) @file_get_contents( $tcg_file ) );
@unlink( $tcg_file );
check( 'the held shop came back from the later release', is_array( $tcg_res ) && isset( $tcg_res[ TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION ] ), true );
fresh_shop( null );
$W['options'] = (array) $tcg_res;
upgraded();
upgraded();
check( '  and back in this build: not decided again either - no line, still off, the notice still due', array( $W['log'], TCGiant_Sync_Inventory::pushes_enabled(), switch_record()['flip'] ?? null, switch_record()['held'] ?? null, switch_record()['notice'] ?? null ), array( array(), false, true, 'wplister', 'pending' ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nTHE DASHBOARD NOTICE (T13, T14)\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( array( 'enable_order_sync' => '0' ) );
upgraded();
foreach ( array( 'dashboard', 'edit-product', 'toplevel_page_tcgiant-sync' ) as $screen ) {
	$W['screen'] = $screen;
	$html        = notice_html();
	check( 'T13 on ' . $screen . ': the notice, with the setting\'s link and a nonce\'d Got it', array( false !== strpos( $html, 'now sends your website\'s stock changes to eBay' ), false !== strpos( $html, 'page=tcgiant-settings#tc-push-stock' ), false !== strpos( $html, 'name="action" value="tcgiant_dismiss_push_notice"' ), false !== strpos( $html, 'value="nonce-tcgiant_dismiss_push_notice"' ) ), array( true, true, true, true ) );
}
$W['screen'] = 'plugins';
check( '  not on other screens', notice_html(), '' );
$W['screen']                  = 'dashboard';
$W['caps']['manage_options'] = false;
check( '  not for a user who cannot manage options', notice_html(), '' );
$W['caps']   = array();
$W['screen']  = null;
$tcg_warnings = array();
set_error_handler( function ( $no, $str ) use ( &$tcg_warnings ) { $tcg_warnings[] = $str; return true; } );
$tcg_html = notice_html();
restore_error_handler();
check( '  nor where there is no screen (an AJAX request, say), and without a warning', array( $tcg_html, $tcg_warnings ), array( '', array() ) );
$W['screen'] = 'dashboard';
$W['caps'] = array();
fresh_shop( array( 'enable_order_sync' => '1' ) );
upgraded();
check( '  not where nothing was turned on', notice_html(), '' );
fresh_shop( array( 'enable_order_sync' => '0' ), false );
upgraded();
check( '  not on a shop that is not connected - nothing can be sent from it yet - and it stays due', array( notice_html(), TCGiant_Sync_Inventory::push_notice_pending() ), array( '', true ) );
update_option( 'tcgiant_sync_ebay_settings', array_merge( settings(), TOKENS ) );
check( '    connected: it shows', false !== strpos( notice_html(), 'now sends your website\'s stock changes to eBay' ), true );

fresh_shop( array( 'enable_order_sync' => '0' ) );
upgraded();
$_REQUEST = array( '_wpnonce' => 'nonce-tcgiant_dismiss_push_notice', '_wp_http_referer' => '/wp-admin/index.php' );
try {
	$ADMIN->handle_dismiss_push_notice();
	$tcg_to = null;
} catch ( Harness_Redirect $r ) {
	$tcg_to = $r->to;
}
$_REQUEST = array();
check( 'T14 Got it: put away, and back to where the shop was', array( notice_html(), switch_record()['notice'], $tcg_to ), array( '', 'dismissed', '/wp-admin/index.php' ) );
upgraded();
check( '  and the upgrade does not bring it back', notice_html(), '' );
fresh_shop( array( 'enable_order_sync' => '0' ) );
upgraded();
$_REQUEST = array( '_wpnonce' => 'forged' );
try {
	$ADMIN->handle_dismiss_push_notice();
	$tcg_died = false;
} catch ( Harness_Died $d ) {
	$tcg_died = true;
} catch ( Harness_Redirect $r ) {
	$tcg_died = false;
}
$_REQUEST = array();
check( '  a dismissal without the nonce is refused, and changes nothing', array( $tcg_died, TCGiant_Sync_Inventory::push_notice_pending() ), array( true, true ) );
$_REQUEST                    = array( '_wpnonce' => 'nonce-tcgiant_dismiss_push_notice' );
$W['caps']['manage_options'] = false;
try {
	$ADMIN->handle_dismiss_push_notice();
	$tcg_died = false;
} catch ( Harness_Died $d ) {
	$tcg_died = true;
} catch ( Harness_Redirect $r ) {
	$tcg_died = false;
}
$W['caps'] = array();
$_REQUEST  = array();
check( '  so is one by a user who cannot manage options', array( $tcg_died, TCGiant_Sync_Inventory::push_notice_pending() ), array( true, true ) );
submit_form( settings_page(), 1 );
check( '  saving Push to eBay Settings leaves it where it is', TCGiant_Sync_Inventory::push_notice_pending(), true );
submit_form( settings_page(), 0 );
check( '  saving Import Settings puts it away too', array( TCGiant_Sync_Inventory::push_notice_pending(), notice_html() ), array( false, '' ) );
fresh_shop( array( 'enable_order_sync' => '0' ) );
upgraded();
set_setting( 'push_stock_to_ebay', '0' );
check( '  switched off since: it puts itself away on the next screen', array( notice_html(), switch_record()['notice'] ), array( '', 'dismissed' ) );
fresh_shop( array( 'enable_order_sync' => '1' ) );
upgraded();
submit_form( settings_page(), 0 );
check( '  saving Import Settings where there was no notice leaves the record as it was', switch_record()['notice'], 'none' );
fresh_shop( array( 'enable_order_sync' => '0' ) );
submit_form( settings_page(), 0 );
check( '  and before the upgrade has run, writes no record of its own, nor the switch - the upgrade still decides, and turns it on', array( switch_record(), array_key_exists( 'push_stock_to_ebay', settings() ), upgraded(), switch_record()['flipped'] ?? null ), array( false, false, null, true ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nEACH FORM CHANGES ITS OWN SETTINGS, AND NOTHING ELSE (T15-T18, FAIL ON 3.27)\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( $FULL );
$page = settings_page();
submit_form( $page, 1, array( 'export_location' => 'York' ) );
$after = settings();
check( 'T15 saving Push to eBay Settings: every import setting kept', stored_keys( $IMPORT_KEYS ), want_keys( array_merge( TOKENS, $FULL ), $IMPORT_KEYS ) );
check( '  the connection and the records kept', stored_keys( $RECORDS ), want_keys( array_merge( TOKENS, $FULL ), $RECORDS ) );
check( '  and its own change made', $after['export_location'], 'York' );
check( '  nothing stored that is not a setting', array( array_key_exists( '_tcg_form', $after ), array_key_exists( '_tcg_lists', $after ) ), array( false, false ) );

fresh_shop( $FULL );
submit_form( settings_page(), 0, array( 'log_level' => 'error' ) );
check( 'T16 saving Import Settings: every export setting kept, Best Offer and GPSR among them', stored_keys( $EXPORT_KEYS ), want_keys( $FULL, $EXPORT_KEYS ) );
check( '  the connection and the records kept', stored_keys( $RECORDS ), want_keys( array_merge( TOKENS, $FULL ), $RECORDS ) );
check( '  its own change made, its lists as ticked', array( settings()['log_level'], settings()['import_standard_category_ids'], settings()['woo_category_ids'], settings()['preserve_woo_category_ids'] ), array( 'error', $STD_CATS, array( '7' ), array( '9' ) ) );

fresh_shop( array( 'enable_order_sync' => '0' ) );
submit_form( settings_page(), 0 );
check( 'T17 an untouched Import Settings save on a "No" shop: sending stays on, the old setting stays No', array( settings()['push_stock_to_ebay'] ?? null, settings()['enable_order_sync'] ?? null, TCGiant_Sync_Inventory::pushes_enabled() ), array( null, '0', true ) );
check( '  the switch is not stored, as it was never chosen: the release still decides it (S-F2)', array_key_exists( 'push_stock_to_ebay', settings() ), false );
check( '  nor what the form drew it with', array_key_exists( 'push_stock_to_ebay_drawn', settings() ), false );
submit_form( settings_page(), 0, array( 'push_stock_to_ebay' => '0' ) );
check( '  changed to No: stored, off', array( settings()['push_stock_to_ebay'] ?? null, TCGiant_Sync_Inventory::pushes_enabled(), TCGiant_Sync_Inventory::push_switch_state() ), array( '0', false, 'off' ) );
check( '    the form draws it at No now', posted( settings_page(), 0, 'push_stock_to_ebay_drawn' ), '0' );
submit_form( settings_page(), 0, array( 'push_stock_to_ebay' => '1' ) );
check( '  back to Yes: stored as Yes', settings()['push_stock_to_ebay'] ?? null, '1' );
submit_form( settings_page(), 0 );
check( '  a stored Yes stays stored on an untouched save', settings()['push_stock_to_ebay'] ?? null, '1' );
fresh_shop( array( 'enable_order_sync' => '0' ) );
upgraded();
check( '  the ping: on because of the upgrade while never chosen', TCGiant_Sync_Inventory::push_switch_state(), 'on_by_upgrade' );
submit_form( settings_page(), 0 );
check( '    still so after an untouched save', TCGiant_Sync_Inventory::push_switch_state(), 'on_by_upgrade' );
submit_form( settings_page(), 0, array( 'push_stock_to_ebay' => '0' ) );
submit_form( settings_page(), 0, array( 'push_stock_to_ebay' => '1' ) );
check( '    and on, once the shop has chosen it', TCGiant_Sync_Inventory::push_switch_state(), 'on' );
fresh_shop( array( 'enable_order_sync' => '0' ) );
post_pairs( array( array( 'option_page', 'tcgiant_sync_ebay_group' ), array( 'tcgiant_sync_ebay_settings[' . TCGiant_Sync_Admin::SETTINGS_FORM_MARKER . ']', '1' ), array( 'tcgiant_sync_ebay_settings[log_level]', 'error' ), array( 'tcgiant_sync_ebay_settings[enable_order_sync]', '0' ) ) );
check( '  a 3.27.8 page\'s untouched save leaves it unstored', array( array_key_exists( 'push_stock_to_ebay', settings() ), settings()['log_level'] ?? null ), array( false, 'error' ) );
fresh_shop( array( 'enable_order_sync' => '0' ) );
post_pairs( array( array( 'option_page', 'tcgiant_sync_ebay_group' ), array( 'tcgiant_sync_ebay_settings[push_stock_to_ebay_drawn]', '1' ), array( 'tcgiant_sync_ebay_settings[log_level]', 'error' ) ) );
check( '  and a drawn value posted by a page without a marker is never stored', array( array_key_exists( 'push_stock_to_ebay_drawn', settings() ), settings()['log_level'] ?? null ), array( false, 'error' ) );

fresh_shop( $FULL );
submit_form( settings_page(), 0, array(), array( 'import_standard_category_ids' ) );
check( 'T18 every standard category unticked (Import All Listings): the filter is cleared', array_key_exists( 'import_standard_category_ids', settings() ), false );
fresh_shop( $FULL );
submit_form( settings_page(), 0, array(), array( 'woo_category_ids', 'preserve_woo_category_ids' ) );
check( '  every WooCommerce category unticked: both lists cleared', array( array_key_exists( 'woo_category_ids', settings() ), array_key_exists( 'preserve_woo_category_ids', settings() ) ), array( false, false ) );
fresh_shop( $FULL );
$W['terms'] = 'error';
$page       = settings_page();
check( '  get_terms() failing: the lists are not drawn, and not marked as drawn', array( false !== strpos( $page, '[woo_category_ids][]' ), false !== strpos( $page, 'value="woo_category_ids"' ) ), array( false, false ) );
submit_form( $page, 0 );
check( '  so a save leaves them as they were (3.27 cleared them)', array( settings()['woo_category_ids'] ?? null, settings()['preserve_woo_category_ids'] ?? null ), array( array( '7' ), array( '9' ) ) );
$W['terms'] = 'some';
fresh_shop( $FULL );
submit_form( settings_page(), 1, array(), array(), array( array( 'tcgiant_sync_ebay_settings[_tcg_lists][]', 'woo_category_ids' ), array( 'tcgiant_sync_ebay_settings[_tcg_lists][]', 'import_standard_category_ids' ) ) );
check( '  the Export form cannot clear an import list, even saying it drew one', array( settings()['woo_category_ids'] ?? null, settings()['import_standard_category_ids'] ?? null ), array( array( '7' ), $STD_CATS ) );
fresh_shop( $FULL );
submit_form( settings_page(), 0, array(), array(), array( array( 'tcgiant_sync_ebay_settings[_tcg_lists][]', 'marketplace' ), array( 'tcgiant_sync_ebay_settings[_tcg_lists][]', 'last_order_import' ) ) );
check( '  nor can the Import form clear a setting that is not a list by naming it as one', array( settings()['marketplace'] ?? null, settings()['last_order_import'] ?? null ), array( 'EBAY_GB', $FULL['last_order_import'] ) );
fresh_shop( $FULL );
post_pairs( array( array( 'option_page', 'tcgiant_sync_ebay_group' ), array( 'tcgiant_sync_ebay_settings[' . TCGiant_Sync_Admin::SETTINGS_FORM_MARKER . ']', 'import' ), array( 'tcgiant_sync_ebay_settings[sync_interval]', 'daily' ), array( 'tcgiant_sync_ebay_settings[' . TCGiant_Sync_Admin::SETTINGS_LISTS_MARKER . '][]', 'log_level' ) ) );
check( '  even one of its own it did not send', settings()['log_level'] ?? null, 'warning' );
// The stored settings broken - not an array - when a form is saved.
fresh_shop( $FULL );
$tcg_page = settings_page();
$W['options']['tcgiant_sync_ebay_settings'] = 'broken';
$tcg_warnings = array();
set_error_handler( function ( $no, $str ) use ( &$tcg_warnings ) { $tcg_warnings[] = $str; return true; } );
try {
	submit_form( $tcg_page, 0, array( 'log_level' => 'error' ) );
	$tcg_thrown = '';
} catch ( Throwable $e ) {
	$tcg_thrown = get_class( $e );
}
restore_error_handler();
check( '  settings found broken: the form\'s own are saved, without an error', array( $tcg_thrown, $tcg_warnings, settings()['log_level'] ?? null, array_values( array_diff( array_keys( (array) settings() ), $IMPORT_KEYS ) ) ), array( '', array(), 'error', array() ) );
// A page drawn by 3.27.8, whose marker names no form: what it sends is taken,
// but no list is cleared and no marker kept.
fresh_shop( $FULL );
post_pairs( array( array( 'option_page', 'tcgiant_sync_ebay_group' ), array( 'tcgiant_sync_ebay_settings[' . TCGiant_Sync_Admin::SETTINGS_FORM_MARKER . ']', '1' ), array( 'tcgiant_sync_ebay_settings[log_level]', 'error' ), array( 'tcgiant_sync_ebay_settings[' . TCGiant_Sync_Admin::SETTINGS_LISTS_MARKER . '][]', 'woo_category_ids' ) ) );
check( '  a 3.27.8 page\'s save: its fields taken, no list cleared, no marker stored', array( settings()['log_level'], settings()['woo_category_ids'] ?? null, array_key_exists( '_tcg_lists', settings() ), array_key_exists( '_tcg_form', settings() ) ), array( 'error', array( '7' ), false, false ) );

echo "\n\"IMPORT EBAY AUCTIONS\" SAVED CHANGED: THE DAY'S ANSWER IS ASKED AGAIN\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( $FULL );
set_transient( TCGiant_Sync_Inventory::AUCTIONS_TRANSIENT, 'no', DAY_IN_SECONDS );
submit_form( settings_page(), 0, array( 'log_level' => 'info' ) );
check( 'a save that leaves it as it was keeps the answer', get_transient( TCGiant_Sync_Inventory::AUCTIONS_TRANSIENT ), 'no' );
submit_form( settings_page(), 0, array( 'import_auctions' => '0' ) );
check( 'switched off: the answer is dropped, to be asked again', get_transient( TCGiant_Sync_Inventory::AUCTIONS_TRANSIENT ), false );
check( '  the setting is in its form', in_array( 'import_auctions', $IMPORT_KEYS, true ), true );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nTHE TOKENS ARE NOT IN THE PAGE, AND A FORM CANNOT SET THEM (T19-T22)\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( $FULL );
$page = settings_page();
check( 'T19 both forms drawn', count( page_forms( $page ) ), 2 );
check( '  no token, relay key or Cert ID value in the page', array( false === strpos( $page, TOKENS['access_token'] ), false === strpos( $page, TOKENS['refresh_token'] ), false === strpos( $page, $FULL['relay_secret'] ), false === strpos( $page, $FULL['cert_id'] ) ), array( true, true, true, true ) );
check( '  and no field for them, nor the expiry', array( false === strpos( $page, '[access_token]' ), false === strpos( $page, '[refresh_token]' ), false === strpos( $page, '[relay_secret]' ), false === strpos( $page, '[token_expiry]' ) ), array( true, true, true, true ) );

fresh_shop( $FULL );
submit_form( settings_page(), 0, array(), array(), array(
	array( 'tcgiant_sync_ebay_settings[access_token]', 'evil-at' ),
	array( 'tcgiant_sync_ebay_settings[refresh_token]', 'evil-rt' ),
	array( 'tcgiant_sync_ebay_settings[relay_secret]', 'evil-relay' ),
	array( 'tcgiant_sync_ebay_settings[store_name]', 'evil-store' ),
	array( 'tcgiant_sync_ebay_settings[token_expiry]', '1' ),
	array( 'tcgiant_sync_ebay_settings[last_order_import]', '1999-01-01 00:00:00' ),
) );
check( 'T20 an Import save carrying the connection and the records: none of them changes', stored_keys( $RECORDS ), want_keys( array_merge( TOKENS, $FULL ), $RECORDS ) );
fresh_shop( $FULL );
submit_form( settings_page(), 1, array(), array(), array( array( 'tcgiant_sync_ebay_settings[ACCESS_TOKEN]', 'evil' ), array( 'tcgiant_sync_ebay_settings[log_level]', 'error' ) ) );
check( '  nor an Export save, by any spelling - and it cannot set an import setting either', array( settings()['access_token'], settings()['log_level'] ), array( TOKENS['access_token'], 'warning' ) );

// T21: a page drawn by 3.27 and saved after the update - and after a reconnect.
$NEW = array( 'access_token' => 'v^1.1#i^1#AT-NEW-6a01', 'refresh_token' => 'v^1.1#i^1#RT-NEW-e3c2', 'relay_secret' => 'RELAY-NEW-0b9d', 'token_expiry' => '2000000000' );
$tcg_legacy = array( array( 'option_page', 'tcgiant_sync_ebay_group' ), array( 'action', 'update' ), array( '_wpnonce', 'n' ) );
foreach ( array_merge( TOKENS, $FULL ) as $key => $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $v ) {
			$tcg_legacy[] = array( 'tcgiant_sync_ebay_settings[' . $key . '][]', $v );
		}
	} else {
		$tcg_legacy[] = array( 'tcgiant_sync_ebay_settings[' . $key . ']', $value );
	}
}
$tcg_legacy[] = array( 'tcgiant_sync_ebay_settings[sync_interval]', 'tcgiant_15mins' );
fresh_shop( array_merge( $FULL, $NEW ), false );
post_pairs( $tcg_legacy );
check( 'T21 a 3.27 page posting old tokens after a reconnect: the new tokens and relay key stay', want_keys( settings(), array_keys( $NEW ) ), $NEW );
check( '  the form\'s own change is made', settings()['sync_interval'], 'tcgiant_15mins' );
check( '  and the push switch, which it does not have, is not stored', array_key_exists( 'push_stock_to_ebay', settings() ), false );
check( '  and nothing else is lost', want_keys( settings(), array_keys( $FULL ) ), array_merge( $FULL, array( 'sync_interval' => 'tcgiant_15mins', 'token_expiry' => $NEW['token_expiry'], 'relay_secret' => $NEW['relay_secret'] ) ) );
// The real 3.27.5 Settings page, where the checkout has the tag (a CI clone
// one commit deep does not).
$tcg_old = (string) shell_exec( 'git -C ' . escapeshellarg( $ROOT ) . ' show v3.27.5:admin/views/settings.php 2>&1' );
if ( false !== strpos( $tcg_old, 'tcgiant_sync_ebay_settings' ) ) {
	$tcg_old_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tcg-settings-3275-' . getmypid() . '.php';
	file_put_contents( $tcg_old_file, $tcg_old );
	foreach ( array( 0 => 'Import', 1 => 'Push to eBay' ) as $which => $label ) {
		fresh_shop( $FULL );
		$old_page = settings_page( $tcg_old_file );
		update_option( 'tcgiant_sync_ebay_settings', array_merge( settings(), $NEW ) );
		submit_form( $old_page, $which );
		check( '  the real 3.27.5 page, ' . $label . ' Settings saved after a reconnect: the new tokens stay', want_keys( settings(), array_keys( $NEW ) ), $NEW );
		check( '    and every other setting and record is as it was', want_keys( settings(), array_keys( $FULL ) ), want_keys( array_merge( $FULL, $NEW ), array_keys( $FULL ) ) );
		check( '    and the push switch is not stored', array_key_exists( 'push_stock_to_ebay', settings() ), false );
	}
	unlink( $tcg_old_file );
} else {
	echo "      (no v3.27.5 in this checkout: the real 3.27.5 page is not rendered)\n";
}

fresh_shop( $FULL );
$page_before = settings_page();
$_REQUEST    = array( '_wpnonce' => 'nonce-tcgiant_sync_disconnect' );
try {
	$ADMIN->handle_disconnect();
} catch ( Harness_Redirect $r ) {
	unset( $r );
}
$_REQUEST = array();
check( 'T22 Disconnect, through the settings filter: the tokens are gone, and stay gone', array( array_key_exists( 'access_token', settings() ), array_key_exists( 'refresh_token', settings() ) ), array( false, false ) );
submit_form( $page_before, 0 );
check( '  a Settings page drawn before it and saved after: they stay gone', array( array_key_exists( 'access_token', settings() ), array_key_exists( 'refresh_token', settings() ), settings()['relay_secret'] ?? null ), array( false, false, $FULL['relay_secret'] ) );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nEVERY FIELD OF A FORM IS ITS FORM'S, AND ONLY ITS OWN (T23)\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( $FULL );
$forms = page_forms( settings_page() );
foreach ( array( 'import' => 0, 'export' => 1 ) as $name => $which ) {
	$want = TCGiant_Sync_Admin::SETTINGS_FORMS[ $name ];
	sort( $want );
	check( 'T23 the ' . $name . ' form says it is the ' . $name . ' form', $forms[ $which ]['marker'], $name );
	check( '  every setting it draws is in SETTINGS_FORMS[\'' . $name . '\'], and every one there is drawn', form_keys( $forms[ $which ] ), $want );
}
check( '  no setting belongs to both forms', array_values( array_intersect( $IMPORT_KEYS, $EXPORT_KEYS ) ), array() );
check( '  the lists are each a form\'s', array_values( array_diff( TCGiant_Sync_Admin::SETTINGS_LISTS, $IMPORT_KEYS ) ), array() );
check( '  nothing a form may set is the connection or a record', array_values( array_intersect( array_merge( $IMPORT_KEYS, $EXPORT_KEYS ), TCGiant_Sync_Admin::NEVER_FROM_POST ) ), array() );
check( '  a drawn value is a field of its form, and of the never-stored list', array( in_array( 'push_stock_to_ebay_drawn', $IMPORT_KEYS, true ), array_values( TCGiant_Sync_Admin::DRAWN_SETTINGS ) ), array( true, array( 'push_stock_to_ebay_drawn' ) ) );
check( '  and every secret is one a form can never set', array_values( array_diff( TCGiant_Sync_Admin::SECRET_SETTINGS, TCGiant_Sync_Admin::NEVER_FROM_POST ) ), array() );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nEVERY OTHER WRITE IS KEPT AS WRITTEN; THE FIRST SAVE IS CLEAN (T24, T25)\n" . str_repeat( '=', 124 ) . "\n";

fresh_shop( null );
$tcg_whole = array_merge( TOKENS, array( 'marketplace' => 'EBAY_US', 'store_name' => 'shop', 'last_order_import' => '2026-10-11 09:00:00' ) );
update_option( 'tcgiant_sync_ebay_settings', $tcg_whole );
update_option( 'tcgiant_sync_ebay_settings', array( 'marketplace' => 'EBAY_US' ) );
check( 'T24 update_option() with no form - a refresh, Disconnect, the wizard: stored exactly as written', settings(), array( 'marketplace' => 'EBAY_US' ) );
fresh_shop( null );
$_POST = wp_slash( array( 'option_page' => 'tcgiant_sync_ebay_group', 'tcgiant_sync_ebay_settings' => array( 'log_level' => 'error' ) ) );
update_option( 'tcgiant_sync_ebay_settings', $tcg_whole );
$_POST = array();
check( '  nor one made while a form is being posted, that is not the form\'s', settings(), $tcg_whole );
fresh_shop( null );
$_POST = wp_slash( array( 'option_page' => 'another_plugins_group', 'tcgiant_sync_ebay_settings' => array( 'marketplace' => 'EBAY_US' ) ) );
update_option( 'tcgiant_sync_ebay_settings', $tcg_whole );
update_option( 'tcgiant_sync_ebay_settings', array( 'marketplace' => 'EBAY_US' ) );
$_POST = array();
check( '  nor one made while another settings page is posted', settings(), array( 'marketplace' => 'EBAY_US' ) );

fresh_shop( null );
$tcg_passes = 0;
add_filter( 'sanitize_option_tcgiant_sync_ebay_settings', function ( $v ) { $GLOBALS['tcg_passes']++; return $v; }, 20 );
$page = settings_page();
submit_form( $page, 0, array( 'log_level' => 'error' ) );
check( 'T25 the first save ever (no option yet) is sanitised twice, as WordPress does', $tcg_passes, 2 );
check( '  and stored with neither marker', array( array_key_exists( '_tcg_form', settings() ), array_key_exists( '_tcg_lists', settings() ), settings()['log_level'] ?? null ), array( false, false, 'error' ) );
check( '  holding its own settings only', array_values( array_diff( array_keys( settings() ), $IMPORT_KEYS ) ), array() );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nTHE OLD SETTING, RELABELLED: ORDER SYNC FOR EVERY CONNECTED SHOP (T26-T28, 3.27.1)\n" . str_repeat( '=', 124 ) . "\n";

$CRON = bare( 'TCGiant_Sync_Cron' );
$tcg_orders = array( 'orders' => array(
	array( 'orderId' => 'O-9', 'lineItems' => array(
		array( 'lineItemId' => 'L-91', 'sku' => 'SKU-360', 'legacyItemId' => '360000000360', 'quantity' => 1 ),
		array( 'lineItemId' => 'L-92', 'sku' => 'SKU-361', 'legacyItemId' => '360000000999', 'quantity' => 1 ),
	) ),
) );
foreach ( array( 'never saved' => array(), 'No' => array( 'enable_order_sync' => '0' ) ) as $how => $tcg_s ) {
	fresh_shop( $tcg_s );
	product( 360, 3, '360000000360' );
	product( 361, 4, '' );
	$W['ebay']['orders'] = $tcg_orders;
	$CRON->sync_orders();
	$settles = array_values( array_filter( $W['queue'], function ( $a ) { return 'tcgiant_sync_settle_item' === $a['hook']; } ) );
	check( 'T26 "Take eBay sales off ..." ' . $how . ', through cron: eBay\'s orders are read', in_array( 'GET sell/fulfillment/v1/order', calls(), true ), true );
	check( '  the linked sale gets its stock check', array_column( $settles, 'args' ), array( array( '360000000360' ) ) );
	check( '  the unlinked one is left alone, unmarked', array( stock( 361 ), meta( 361, '_ebay_order_processed_L-92' ) ), array( 4, null ) );
}
fresh_shop( array( 'enable_order_sync' => '1' ) );
product( 360, 3, '360000000360' );
product( 361, 4, '' );
$W['ebay']['orders'] = $tcg_orders;
$CRON->sync_orders();
check( 'T27 Yes: the unlinked sale is taken off once and marked, and the linked one checked', array( stock( 361 ), null !== meta( 361, '_ebay_order_processed_L-92' ), count( array_filter( $W['queue'], function ( $a ) { return 'tcgiant_sync_settle_item' === $a['hook']; } ) ) ), array( 3, true, 1 ) );
$CRON->sync_orders();
check( '  a second run takes nothing more off', stock( 361 ), 3 );
fresh_shop( array(), false );
$CRON->sync_orders();
check( '  a shop not connected reads nothing', calls(), array() );
fresh_shop( array( 'sync_interval' => 'disabled' ) );
$W['cron']['tcgiant_sync_poll_ebay_cron'] = time() + 60;
$CRON->schedule_events();
check( 'T28 Auto-Sync Disabled: the poll that runs order sync is not scheduled', array( wp_next_scheduled( 'tcgiant_sync_poll_ebay_cron' ), in_array( 'tcgiant_sync_poll_ebay_cron', $W['cleared'], true ) ), array( false, true ) );
$page = settings_page();
check( 'the setting says what it does now', array( false !== strpos( $page, 'Take eBay sales off stock for products not linked to a listing?' ), false === strpos( $page, 'Reduce WooCommerce stock on eBay sale?' ), false !== strpos( $page, 'It has no effect on what is sent to eBay.' ) ), array( true, true, true ) );
check( '  and the switch says backorder products still end the listing at 0 or below', false !== strpos( $page, 'A product that allows backorders is treated the same: at 0 or below, its eBay listing is ended' ), true );
check( '  the switch is where the notice links to', false !== strpos( $page, 'id="tc-push-stock"' ), true );

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nTHE USAGE PING AND THE DASHBOARD SAY WHICH WAY IT IS\n" . str_repeat( '=', 124 ) . "\n";

$TEL = bare( 'TCGiant_Sync_Telemetry' );
foreach ( array(
	'turned on by the upgrade' => array( array( 'enable_order_sync' => '0' ), 'on_by_upgrade' ),
	'on'                       => array( array( 'enable_order_sync' => '1' ), 'on' ),
	'off'                      => array( array( 'push_stock_to_ebay' => '0' ), 'off' ),
) as $label => $case ) {
	fresh_shop( $case[0] );
	upgraded();
	$TEL->ping_telemetry();
	$sent = json_decode( (string) ( $W['pings'][0][1]['body'] ?? '' ), true );
	check( 'the ping, ' . $label . ': stock_push says so', $sent['stock_push'] ?? null, $case[1] );
}
// And 3.27.8's version after it (test-relay-version.php).
check( '  and nothing else new is sent', array_keys( (array) $sent ), array( 'site_url', 'synced_total', 'pushed_total', 'pulled_total', 'license_type', 'stock_push', 'version', 'php_version' ) );

fresh_shop( array( 'push_stock_to_ebay' => '0', 'store_name' => 'shop' ) );
$health = $ADMIN->get_system_health();
check( 'the dashboard\'s health: Website stock to eBay, Off', array( $health['stock_push']['label'] ?? null, $health['stock_push']['text'] ?? null, $health['stock_push']['status'] ?? null ), array( 'Website stock to eBay', 'Off', 'inactive' ) );
set_setting( 'push_stock_to_ebay', '1' );
check( '  and On', $ADMIN->get_system_health()['stock_push']['text'] ?? null, 'On' );
fresh_shop( array( 'enable_order_sync' => '0' ), false );
check( '  On (not connected), where nothing can be sent', array( $ADMIN->get_system_health()['stock_push']['text'] ?? null, $ADMIN->get_system_health()['stock_push']['status'] ?? null ), array( 'On (not connected)', 'warning' ) );
fresh_shop( array( 'push_stock_to_ebay' => '0' ), false );
check( '  and Off is Off, connected or not', $ADMIN->get_system_health()['stock_push']['text'] ?? null, 'Off' );

// PHP2Shell (3.28.4): a line of its own, straight after it, for a PHP before
// the fix - test-php2shell.php has the versions - and none after it.
TCGiant_Sync_Environment::$php_version = '8.3.20';
$health = $ADMIN->get_system_health();
$keys   = array_keys( $health );
check( 'the health panel on PHP 8.3.20: a PHP2Shell line after "Website stock to eBay"', array( array_search( 'php2shell', $keys, true ) - array_search( 'stock_push', $keys, true ), $health['php2shell'] ?? null ), array( 1, TCGiant_Sync_Environment::php2shell_row( '8.3.20' ) ) );
TCGiant_Sync_Environment::$php_version = '8.3.36';
check( '  none on 8.3.36', array_key_exists( 'php2shell', $ADMIN->get_system_health() ), false );
TCGiant_Sync_Environment::$php_version = null;

// ═══════════════════════════════════════════════════════════════════════════════
echo "\nA WRITE-BACK AFTER AN EBAY CALL KEEPS A TOKEN REFRESHED DURING IT\n" . str_repeat( '=', 124 ) . "\n";

$tcg_refresh = function () {
	$s                 = settings();
	$s['access_token'] = 'v^1.1#i^1#AT-REFRESHED-55';
	$s['token_expiry'] = '2100000000';
	update_option( 'tcgiant_sync_ebay_settings', $s );
};
fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
$W['on_call']['GET sell/inventory/v1/location'] = $tcg_refresh;
$ADMIN->get_system_health();
check( 'the dashboard fetching the store name: the name is kept, and so is the token refreshed meanwhile', array( settings()['store_name'] ?? null, settings()['access_token'], settings()['token_expiry'] ?? null ), array( 'Card Shop HQ', 'v^1.1#i^1#AT-REFRESHED-55', '2100000000' ) );

fresh_shop( array( 'enable_order_import' => '1' ) );
$W['posts'][700] = array( 'type' => 'shop_order', 'kind' => 'order', 'status' => 'publish', 'parent' => 0 );
$W['meta'][700]  = array( '_ebay_order_id' => '12-34567-89012' );
ebay_answers( 'GetOrders', array( '__xml' => '<OrderArray><Order><OrderID>12-34567-89012</OrderID></Order></OrderArray><PaginationResult><TotalNumberOfPages>1</TotalNumberOfPages></PaginationResult>' ) );
$W['on_call']['GetOrders'] = $tcg_refresh;
bare( 'TCGiant_Sync_Orders' )->import_recent_orders();
check( 'order import: last_order_import is written, and the token refreshed during it is kept', array( null !== ( settings()['last_order_import'] ?? null ), settings()['access_token'] ), array( true, 'v^1.1#i^1#AT-REFRESHED-55' ) );
fresh_shop( array( 'enable_order_import' => '1' ) );
$W['posts'][700] = array( 'type' => 'shop_order', 'kind' => 'order', 'status' => 'publish', 'parent' => 0 );
$W['meta'][700]  = array( '_ebay_order_id' => '12-34567-89012' );
ebay_answers( 'GetOrders', array( '__xml' => '<OrderArray><Order><OrderID>12-34567-89012</OrderID></Order></OrderArray>' ) );
$W['on_call']['GetOrders'] = function () { $s = settings(); unset( $s['access_token'], $s['refresh_token'] ); update_option( 'tcgiant_sync_ebay_settings', $s ); };
bare( 'TCGiant_Sync_Orders' )->import_recent_orders();
check( '  and Disconnect pressed during it stays done', array_key_exists( 'access_token', settings() ), false );
// The settings found broken - not an array - by the time either writes back.
$tcg_warnings = array();
set_error_handler( function ( $no, $str ) use ( &$tcg_warnings ) { $tcg_warnings[] = $str; return true; } );
fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
$W['on_call']['GET sell/inventory/v1/location'] = function () { $GLOBALS['W']['options']['tcgiant_sync_ebay_settings'] = 'broken'; };
$ADMIN->get_system_health();
$tcg_after_health = settings();
fresh_shop( array( 'enable_order_import' => '1' ) );
$W['posts'][700] = array( 'type' => 'shop_order', 'kind' => 'order', 'status' => 'publish', 'parent' => 0 );
$W['meta'][700]  = array( '_ebay_order_id' => '12-34567-89012' );
ebay_answers( 'GetOrders', array( '__xml' => '<OrderArray><Order><OrderID>12-34567-89012</OrderID></Order></OrderArray>' ) );
$W['on_call']['GetOrders'] = function () { $GLOBALS['W']['options']['tcgiant_sync_ebay_settings'] = 'broken'; };
bare( 'TCGiant_Sync_Orders' )->import_recent_orders();
$tcg_after_orders = settings();
restore_error_handler();
check( '  settings found broken by then: each writes what it has, as settings, without a warning', array( $tcg_after_health, array_keys( (array) $tcg_after_orders ), $tcg_warnings ), array( array( 'store_name' => 'Card Shop HQ' ), array( 'last_order_import' ), array() ) );

echo "\nACROSS REQUESTS: WHAT ANOTHER REQUEST SAVED MEANWHILE STANDS (S-F1)\n" . str_repeat( '=', 124 ) . "\n";

// WordPress gives each request its copy of the options as it began; another
// request's Disconnect is not in it. The write-backs read the row.
$tcg_away = function () { other_request_saves( 'access_token', null ); other_request_saves( 'refresh_token', null ); };
fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
$W['on_call']['GET sell/inventory/v1/location'] = $tcg_away;
in_one_request( function () { $GLOBALS['ADMIN']->get_system_health(); } );
check( 'the dashboard fetching the store name while another request disconnects: the name saved, the tokens stay gone', array( settings()['store_name'] ?? null, array_key_exists( 'access_token', settings() ), array_key_exists( 'refresh_token', settings() ) ), array( 'Card Shop HQ', false, false ) );
fresh_shop( array( 'enable_order_import' => '1' ) );
$W['posts'][700] = array( 'type' => 'shop_order', 'kind' => 'order', 'status' => 'publish', 'parent' => 0 );
$W['meta'][700]  = array( '_ebay_order_id' => '12-34567-89012' );
ebay_answers( 'GetOrders', array( '__xml' => '<OrderArray><Order><OrderID>12-34567-89012</OrderID></Order></OrderArray>' ) );
$W['on_call']['GetOrders'] = $tcg_away;
in_one_request( function () { bare( 'TCGiant_Sync_Orders' )->import_recent_orders(); } );
check( '  order import, the same: last_order_import saved, the tokens stay gone', array( null !== ( settings()['last_order_import'] ?? null ), array_key_exists( 'access_token', settings() ) ), array( true, false ) );
fresh_shop( array() );
$W['on_call']['GetSellerList'] = $tcg_away;
in_one_request( function () { $GLOBALS['INV']->reconcile_inventory(); } );
check( '  the weekly stock check, the same: last_reconciliation saved, the tokens stay gone', array( null !== ( settings()['last_reconciliation'] ?? null ), array_key_exists( 'access_token', settings() ), logged( 'Reconciliation: Fatal' ) ), array( true, false, false ) );
// A Settings save whose request began before a token refresh made by cron.
fresh_shop( $FULL );
$tcg_page = settings_page();
in_one_request( function () use ( $tcg_page ) {
	other_request_saves( 'access_token', 'v^1.1#i^1#AT-CRON-REFRESHED' );
	other_request_saves( 'token_expiry', '2200000000' );
	submit_form( $tcg_page, 0, array( 'log_level' => 'error' ) );
} );
check( '  a Settings save that began before cron refreshed the token: the new token and expiry stand', array( settings()['access_token'], settings()['token_expiry'], settings()['log_level'] ), array( 'v^1.1#i^1#AT-CRON-REFRESHED', '2200000000', 'error' ) );
fresh_shop( $FULL );
$tcg_page = settings_page();
in_one_request( function () use ( $tcg_page ) {
	other_request_saves( 'access_token', null );
	other_request_saves( 'refresh_token', null );
	submit_form( $tcg_page, 1, array( 'export_location' => 'York' ) );
} );
check( '  one that began before another request disconnected: they stay gone', array( array_key_exists( 'access_token', settings() ), array_key_exists( 'refresh_token', settings() ), settings()['export_location'] ), array( false, false, 'York' ) );
// The import's own write-backs (SS2-L1): the auction upgrade's setting, and
// the marketplace taken from a listing (in a child - it runs once a process).
fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
$W['auctions_imported'] = 1;
in_one_request( function () {
	settings();   // The request's copy, taken before ...
	$tcg_away = function () { other_request_saves( 'access_token', null ); other_request_saves( 'refresh_token', null ); };
	$tcg_away();  // ... another request disconnects.
	TCGiant_Sync_Mapper::upgrade_auction_import();
} );
$W['auctions_imported'] = 0;
check( '  the auction upgrade during a batch another request disconnected in: its setting saved, the tokens stay gone', array( settings()['import_auctions'] ?? null, array_key_exists( 'access_token', settings() ) ), array( '1', false ) );
child( 'mapper' );
child( 'mapper2' );
child( 'oauth' );
// The wizard's import step, and the dashboard's one-time redirect address (SS3-T1, T2): only their own keys.
fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
in_one_request( function () {
	settings();
	other_request_saves( 'access_token', null );
	other_request_saves( 'refresh_token', null );
	$_REQUEST = array( '_wpnonce' => 'nonce-tcgiant_wizard_save_import' );
	$_POST    = array( 'marketplace' => 'EBAY_GB', 'sync_interval' => 'daily' );
	try {
		$GLOBALS['ADMIN']->handle_wizard_save_import();
	} catch ( Harness_Redirect $r ) {
		unset( $r );
	}
} );
$_POST    = array();
$_REQUEST = array();
check( '  the wizard\'s import step in a request another disconnected in: its two settings saved, the tokens stay gone', array( settings()['marketplace'] ?? null, settings()['sync_interval'] ?? null, array_key_exists( 'access_token', settings() ) ), array( 'EBAY_GB', 'daily', false ) );
$tcg_dash = (string) file_get_contents( $ROOT . '/admin/views/dashboard.php' );
check( '  the dashboard writes only its redirect address, onto the row', array( false !== strpos( $tcg_dash, "TCGiant_Sync_Inventory::save_settings_keys( array( 'redirect_uri' => \$settings['redirect_uri'] ) );" ), false === strpos( $tcg_dash, "update_option( 'tcgiant_sync_ebay_settings'" ) ), array( true, true ) );
// The request's copy equal to the write, the row not: the write still reaches the row.
fresh_shop( array( 'last_order_import' => '2026-10-10 10:00:00' ) );
in_one_request( function () {
	other_request_saves( 'last_order_import', '2026-10-11 11:00:00' );
	TCGiant_Sync_Inventory::save_settings_keys( array( 'last_order_import' => '2026-10-10 10:00:00' ) );
} );
check( '  a write-back this request\'s old copy happens to equal still reaches the row', settings()['last_order_import'], '2026-10-10 10:00:00' );
// The same with the settings not autoloaded (WordPress 6.6 may decide so for a large option): its own cache entry.
fresh_shop( array( 'last_order_import' => '2026-10-10 10:00:00' ) );
$W['autoload']['tcgiant_sync_ebay_settings'] = 'no';
in_one_request( function () {
	other_request_saves( 'last_order_import', '2026-10-11 11:00:00' );
	TCGiant_Sync_Inventory::save_settings_keys( array( 'last_order_import' => '2026-10-10 10:00:00' ) );
} );
check( '    and so it does when the settings are not autoloaded', settings()['last_order_import'], '2026-10-10 10:00:00' );
fresh_shop( array( 'log_level' => 'error' ) );
$W['row_unreadable'] = true;
TCGiant_Sync_Inventory::save_settings_keys( array( 'last_order_import' => '2026-10-12 12:00:00' ) );
$W['row_unreadable'] = false;
check( '  a row that cannot be read gives this request\'s copy: a write-back loses nothing', array( settings()['log_level'] ?? null, settings()['last_order_import'] ?? null, array_key_exists( 'access_token', settings() ) ), array( 'error', '2026-10-12 12:00:00', true ) );
check( '  the row read: a row that is not an array is no settings', array( ( function () { $GLOBALS['W']['options']['tcgiant_sync_ebay_settings'] = 'broken'; return TCGiant_Sync_Inventory::settings_row(); } )() ), array( array() ) );

echo "\nA STORED OPTION THAT IS NOT AN ARRAY BREAKS NOTHING (S-F5)\n" . str_repeat( '=', 124 ) . "\n";

$tcg_broken = function ( callable $fn ) {
	fresh_shop( null );
	$GLOBALS['W']['options']['tcgiant_sync_ebay_settings'] = 'broken';
	$warnings = array();
	set_error_handler( function ( $no, $str ) use ( &$warnings ) { $warnings[] = $str; return true; } );
	$thrown = '';
	try {
		$fn();
	} catch ( Harness_Redirect $r ) {
		unset( $r );
	} catch ( Throwable $e ) {
		$thrown = get_class( $e ) . ': ' . $e->getMessage();
	}
	restore_error_handler();
	return array( $thrown, $warnings, settings() );
};
check( 'Disconnect on broken settings: no error, settings now an empty set', $tcg_broken( function () { $_REQUEST = array( '_wpnonce' => 'nonce-tcgiant_sync_disconnect' ); $GLOBALS['ADMIN']->handle_disconnect(); } ), array( '', array(), array() ) );
check( 'the setup wizard\'s import save on broken settings: no error, its own setting saved', $tcg_broken( function () { $_REQUEST = array( '_wpnonce' => 'nonce-tcgiant_wizard_save_import' ); $_POST = array( 'marketplace' => 'EBAY_GB' ); $GLOBALS['ADMIN']->handle_wizard_save_import(); } ), array( '', array(), array( 'marketplace' => 'EBAY_GB' ) ) );
$_POST    = array();
$_REQUEST = array();
$W['log'] = array();
// Connected all the same, so that its read of eBay goes: since 3.28.0 (item
// R) a read that fails is tried again later, and the record waits for the
// pass to finish.
TCGiant_Sync_OAuth::$connected = true;
$tcg_rec  = $tcg_broken( function () { $GLOBALS['INV']->reconcile_inventory(); } );
TCGiant_Sync_OAuth::$connected = null;
check( 'the weekly stock check on broken settings: no error, and its record saved', array( $tcg_rec[0], $tcg_rec[1], array_keys( (array) $tcg_rec[2] ), logged( 'Reconciliation: Fatal' ) ), array( '', array(), array( 'last_reconciliation', 'last_reconciliation_mismatches' ), false ) );

echo "\nDELETING THE PLUGIN WITH ITS DATA TAKES THE RECORD\n" . str_repeat( '=', 124 ) . "\n";

$tcg_uninstall = (string) file_get_contents( $ROOT . '/uninstall.php' );
check( 'uninstall.php deletes ' . TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION, false !== strpos( $tcg_uninstall, "'" . TCGiant_Sync_Inventory::PUSH_SWITCH_OPTION . "'," ), true );
check( '  and the once-a-day note is a plugin transient, which it sweeps', 0 === strpos( TCGiant_Sync_Inventory::PUSH_OFF_NOTED, 'tcgiant_' ), true );
check( '  and the disconnect stamp', false !== strpos( $tcg_uninstall, "'" . TCGiant_Sync_Inventory::DISCONNECTED_OPTION . "'," ), true );

done();
