<?php
/**
 * Harness: the WooCommerce -> eBay stock push (3.28.0).
 *
 * Until 3.28.0 a stock change in WooCommerce queued {sku, quantity}, and the
 * push looked the SKU up again in the database to find a listing. So:
 *
 *  - two products sharing a barcode, or a Sell Similar copy sharing a SKU,
 *    sent one product's stock to the other's listing, or ended it;
 *  - a website sale sent WooCommerce's figure as eBay's, putting back on
 *    eBay a unit eBay had sold meanwhile;
 *  - a variation was "not linked", and one with no SKU ended the whole
 *    listing at 0;
 *  - every EndItem was recorded as an ended, settled listing, whatever eBay
 *    answered, and "ended" was read from English words in eBay's error;
 *  - "Auto-end ... No" ended the listing anyway, the order screen's push
 *    ended an untracked product's listing, and block checkout queued two
 *    pushes for one sale.
 *
 * Loaded for real: TCGiant_Sync_Environment, TCGiant_Sync_API (down to the
 * HTTP call, which is captured here and answered in eBay's own envelope),
 * TCGiant_Sync_Sites, TCGiant_Sync_Runtime, TCGiant_Sync_Variations,
 * TCGiant_Sync_Inventory, TCGiant_Sync_Listing_Link, TCGiant_Sync_Mapper,
 * TCGiant_Sync_Importer and TCGiant_Sync_Admin. WordPress, WooCommerce
 * (with its own stock functions and its order stock reduction) and Action
 * Scheduler are played here. A queued push is run the way Action Scheduler
 * runs one: through the hook the inventory class registered for it, with its
 * arguments passed in order.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-84s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$ROOT = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$POSTS      = array();
$META       = array();
$OPTIONS    = array();
$TRANSIENTS = array();
$HOOKS      = array();
$DID        = array();
$LOG        = array();
$QUEUE      = array();
$WIRE       = array();
$EBAY       = array();
$WRITES     = array();
$ORDERS     = array();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function _n( $single, $plural, $n ) { return 1 === (int) $n ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text ) { return esc_html( $text ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $url ) { return (string) $url; }
function esc_xml( $text ) { return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_unslash( $v ) { return $v; }
function wp_rand( $min = 0, $max = 0 ) { return (int) $min; }
function wp_json_encode( $v, $f = 0, $d = 512 ) { return json_encode( $v, $f, $d ); }
function wp_cache_delete() { return true; }
function wp_convert_hr_to_bytes( $v ) { return 536870912; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) { return 'U' === $format ? (string) strtotime( $date . ' UTC' ) : $date; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function sanitize_title( $title ) { return trim( preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( trim( (string) $title ) ) ), '-' ); }
function taxonomy_exists( $t ) { return false; }
function wc_attribute_label( $name ) { return $name; }
function get_term_by() { return false; }
function has_term() { return false; }
function wp_get_post_terms() { return array(); }
function wp_clear_scheduled_hook() {}
function wp_next_scheduled() { return false; }
function wp_schedule_event() { return true; }
// As WordPress's does: the values are put in as they are, not encoded.
function add_query_arg( $args, $url ) {
	$pairs = array();
	foreach ( (array) $args as $k => $v ) {
		$pairs[] = $k . '=' . $v;
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $pairs );
}
function wp_load_alloptions() { return array( 'home' => 'https://coinshop.example.com', 'siteurl' => 'https://coinshop.example.com' ); }
function wp_get_environment_type() { return 'production'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

// Hooks, by priority, as WordPress runs them.
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['HOOKS'][ $hook ][] = array( $callback, $accepted_args, $priority, count( $GLOBALS['HOOKS'][ $hook ] ?? array() ) );
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return add_action( $hook, $callback, $priority, $accepted_args ); }
function harness_listeners( $hook ) {
	$list = $GLOBALS['HOOKS'][ $hook ] ?? array();
	usort( $list, function ( $a, $b ) { return $a[2] === $b[2] ? $a[3] - $b[3] : ( $a[2] < $b[2] ? -1 : 1 ); } );
	return $list;
}
function do_action( $hook, ...$args ) {
	$GLOBALS['DID'][ $hook ] = ( $GLOBALS['DID'][ $hook ] ?? 0 ) + 1;
	foreach ( harness_listeners( $hook ) as $l ) {
		call_user_func_array( $l[0], array_slice( $args, 0, $l[1] ) );
	}
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( harness_listeners( $hook ) as $l ) {
		$value = call_user_func_array( $l[0], array_slice( array_merge( array( $value ), $args ), 0, $l[1] ) );
	}
	return $value;
}
function did_action( $hook ) { return $GLOBALS['DID'][ $hook ] ?? 0; }

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['OPTIONS'][ $name ] = $value;
	if ( null !== $autoload ) {
		$GLOBALS['OPTIONS']['__autoload'][ $name ] = $autoload;
	}
	return true;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( ! array_key_exists( $name, $GLOBALS['OPTIONS'] ) ) {
		$GLOBALS['OPTIONS'][ $name ] = $value;
	}
	return true;
}
function delete_option( $name ) { unset( $GLOBALS['OPTIONS'][ $name ] ); return true; }
function get_transient( $name ) { return $GLOBALS['TRANSIENTS'][ $name ] ?? false; }
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $name ] = $value; return true; }
function delete_transient( $name ) { unset( $GLOBALS['TRANSIENTS'][ $name ] ); return true; }

function get_post_meta( $id, $key = '', $single = false ) {
	$v = $GLOBALS['META'][ (int) $id ][ $key ] ?? null;
	if ( null === $v ) {
		return $single ? '' : array();
	}
	return $single ? $v : array( $v );
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['META'][ (int) $id ][ $key ] = is_scalar( $value ) ? (string) $value : $value;
	return true;
}
function delete_post_meta( $id, $key ) { unset( $GLOBALS['META'][ (int) $id ][ $key ] ); return true; }
function get_post_status( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['status'] ?? false; }
function get_post_type( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['type'] ?? false; }
function wp_trash_post( $id ) { $GLOBALS['POSTS'][ (int) $id ]['status'] = 'trash'; return true; }

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
		foreach ( $GLOBALS['POSTS'] as $id => $post ) {
			if ( (string) ( $GLOBALS['META'][ $id ][ $m[1] ] ?? "\0" ) !== $m[2] ) {
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
		$ids = $this->matching( $query );
		// The stock checks' joins (3.28.0 fix round 3): read by name.
		if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '(tcgiant_sync_settle_joined_[^']+)' LIMIT 1$/", trim( $query ), $jm ) ) {
			return array_key_exists( $jm[1], $GLOBALS['OPTIONS'] ) ? (string) $GLOBALS['OPTIONS'][ $jm[1] ] : null;
		}
		return $ids ? (string) $ids[0] : null;
	}
	public function get_col( $query ) { return array_map( 'strval', $this->matching( $query ) ); }
	// The import's lookup of a product by its item number (fix round 3).
	public function get_row( $query ) {
		$ids = $this->matching( $query );
		return $ids ? (object) array( 'post_id' => (string) $ids[0], 'post_status' => $GLOBALS['POSTS'][ $ids[0] ]['status'] ) : null;
	}
	public function get_results( $query, $output = null ) { return array(); }
	public function query( $query ) {
		// The stock checks' joins (3.28.0 fix round 3): one upsert keeping the
		// later time, autoload off; deleted by name. $GLOBALS['ON_JOIN_QUERY'],
		// when set, runs just before the statement lands.
		if ( preg_match( "/^INSERT INTO wp_options \\( option_name, option_value, autoload \\) VALUES \\( '(tcgiant_sync_settle_joined_[^']+)', '?(\\d+)'?, 'no' \\)\\s*ON DUPLICATE KEY UPDATE option_value = GREATEST\\( option_value \\+ 0, '?(\\d+)'? \\)$/", trim( $query ), $jm ) ) {
			if ( isset( $GLOBALS['ON_JOIN_QUERY'] ) ) {
				call_user_func( $GLOBALS['ON_JOIN_QUERY'], $jm[1], (int) $jm[2] );
			}
			$GLOBALS['OPTIONS'][ $jm[1] ] = (string) ( array_key_exists( $jm[1], $GLOBALS['OPTIONS'] ) ? max( (int) $GLOBALS['OPTIONS'][ $jm[1] ], (int) $jm[3] ) : (int) $jm[2] );
			$GLOBALS['OPTIONS']['__autoload'][ $jm[1] ] = false;
			return 1;
		}
		if ( preg_match( "/^DELETE FROM wp_options WHERE option_name = '(tcgiant_sync_settle_joined_[^']+)'$/", trim( $query ), $jm ) ) {
			$had = array_key_exists( $jm[1], $GLOBALS['OPTIONS'] );
			unset( $GLOBALS['OPTIONS'][ $jm[1] ] );
			return $had ? 1 : 0;
		}
		if ( false !== strpos( $query, 'option_value + 1' ) ) {
			$GLOBALS['CALLS_COUNTED'] = ( $GLOBALS['CALLS_COUNTED'] ?? 0 ) + 1;
			return 1;
		}
		return 0;
	}
	public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }
	public function delete() { return 0; }
}
$wpdb = new Harness_Wpdb();

// --- WooCommerce ----------------------------------------------------------------

/**
 * A product: its fields are its meta. A variation keeps stock of its own
 * ('_manage_stock' yes) or takes its parent's ('no' while the parent's is
 * yes), as WooCommerce's does.
 */
class WC_Product {
	protected $id;
	protected $changes = array();
	public $snap = null; // the product as first read, while WooCommerce's instance cache holds it
	public function __construct( $id = 0 ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	protected function f( $key, $default = '' ) {
		if ( array_key_exists( $key, $this->changes ) ) {
			return $this->changes[ $key ];
		}
		if ( null !== $this->snap ) {
			return $this->snap[ $key ] ?? $default;
		}
		return $GLOBALS['META'][ $this->id ][ $key ] ?? $default;
	}
	public function get_type() { return $GLOBALS['POSTS'][ $this->id ]['kind'] ?? 'simple'; }
	public function is_type( $type ) { return $type === $this->get_type(); }
	public function get_parent_id() { return (int) ( $GLOBALS['POSTS'][ $this->id ]['parent'] ?? 0 ); }
	public function get_status() { return $GLOBALS['POSTS'][ $this->id ]['status'] ?? 'publish'; }
	public function get_name() { return (string) $this->f( '_name', 'Product #' . $this->id ); }
	public function get_meta( $key, $single = true ) { return $this->f( $key ); }
	public function update_meta_data( $key, $value ) { $this->changes[ $key ] = $value; }
	public function delete_meta_data( $key ) { $this->changes[ $key ] = null; }
	public function get_sku( $context = 'view' ) {
		$own = (string) $this->f( '_sku' );
		if ( '' === $own && 'view' === $context && $this->get_parent_id() ) {
			return (string) ( $GLOBALS['META'][ $this->get_parent_id() ]['_sku'] ?? '' );
		}
		return $own;
	}
	public function get_stock_managed_by_id() {
		if ( 'yes' === $this->f( '_manage_stock', 'no' ) || ! $this->get_parent_id() ) {
			return $this->id;
		}
		return 'yes' === ( $GLOBALS['META'][ $this->get_parent_id() ]['_manage_stock'] ?? 'no' ) ? $this->get_parent_id() : $this->id;
	}
	public function managing_stock() {
		return 'yes' === ( $GLOBALS['META'][ $this->get_stock_managed_by_id() ]['_manage_stock'] ?? 'no' )
			|| ( $this->get_stock_managed_by_id() === $this->id && 'yes' === $this->f( '_manage_stock', 'no' ) );
	}
	public function get_stock_quantity() {
		if ( ! $this->managing_stock() ) {
			return null;
		}
		$holder = $this->get_stock_managed_by_id();
		return $holder === $this->id ? (int) $this->f( '_stock', 0 ) : (int) ( $GLOBALS['META'][ $holder ]['_stock'] ?? 0 );
	}
	public function get_stock_status() { return (string) $this->f( '_stock_status', 'instock' ); }
	public function is_in_stock() { return 'outofstock' !== $this->get_stock_status(); }
	public function get_attributes() { $a = $this->f( '_attrs', array() ); return is_array( $a ) ? $a : array(); }
	public function get_children() {
		$out = array();
		foreach ( $GLOBALS['POSTS'] as $id => $p ) {
			if ( (int) ( $p['parent'] ?? 0 ) === $this->id && 'trash' !== $p['status'] ) {
				$out[] = $id;
			}
		}
		return $out;
	}
	// What the import's save of a variation sets.
	public function set_parent_id( $id ) { $this->changes['__parent'] = (int) $id; }
	public function set_sku( $sku ) { $this->changes['_sku'] = (string) $sku; }
	public function set_regular_price( $p ) { $this->changes['_regular_price'] = $p; }
	public function set_sale_price( $p ) { $this->changes['_sale_price'] = $p; }
	public function set_manage_stock( $on ) { $this->changes['_manage_stock'] = $on ? 'yes' : 'no'; }
	public function set_stock_quantity( $q ) { $this->changes['_stock'] = (int) $q; }
	public function set_stock_status( $s ) { $this->changes['_stock_status'] = $s; }
	public function set_status( $s ) { $this->changes['__status'] = $s; }
	public function set_attributes( $a ) { $this->changes['_attrs'] = $a; }
	// The rest of what the import's save of a product sets and reads: kept as
	// meta under the property's name (fix round 3, the full import of R3-H1).
	public function __call( $name, $args ) {
		if ( 0 === strpos( $name, 'set_' ) ) {
			$this->changes[ '_' . substr( $name, 4 ) ] = $args[0] ?? null;
			return null;
		}
		if ( 0 === strpos( $name, 'get_' ) ) {
			return $this->f( '_' . substr( $name, 4 ) );
		}
		return null;
	}
	public function save() {
		if ( ! $this->id ) {
			$this->id = $GLOBALS['NEXT_ID']++;
			$GLOBALS['POSTS'][ $this->id ] = array( 'type' => 'product_variation', 'kind' => 'variation', 'status' => 'publish', 'parent' => 0 );
		}
		$stock_changed = array_key_exists( '_stock', $this->changes ) && (string) $this->changes['_stock'] !== (string) ( $GLOBALS['META'][ $this->id ]['_stock'] ?? '' );
		foreach ( $this->changes as $key => $value ) {
			if ( '__parent' === $key ) {
				$GLOBALS['POSTS'][ $this->id ]['parent'] = $value;
			} elseif ( '__status' === $key ) {
				$GLOBALS['POSTS'][ $this->id ]['status'] = $value;
			} elseif ( null === $value ) {
				unset( $GLOBALS['META'][ $this->id ][ $key ] );
			} elseif ( '_stock' === $key && in_array( $this->id, $GLOBALS['HELD_STOCK'] ?? array(), true ) ) {
				continue;
			} else {
				$GLOBALS['META'][ $this->id ][ $key ] = $value;
			}
		}
		$this->changes = array();
		$this->snap    = null;
		unset( $GLOBALS['PCACHE'][ $this->id ] );
		if ( ! empty( $GLOBALS['SAVE_THROWS_EARLY'] ) ) {
			$GLOBALS['SAVE_THROWS_EARLY'] = false;
			throw new RuntimeException( 'an earlier save hook failed' );
		}
		if ( ! empty( $GLOBALS['SAVE_FIRES'] ) ) {
			$variation = 'variation' === $this->get_type();
			if ( $stock_changed ) {
				do_action( $variation ? 'woocommerce_variation_set_stock' : 'woocommerce_product_set_stock', $this );
			}
			do_action( $variation ? 'woocommerce_update_product_variation' : 'woocommerce_update_product', $this->id, $this );
		}
		return $this->id;
	}
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variable extends WC_Product {
	public static function sync( $id ) {}
}
class WC_Product_Variation extends WC_Product {}
class WC_Product_Attribute {
	private $d = array( 'id' => 0, 'name' => '', 'options' => array(), 'position' => 0, 'visible' => true, 'variation' => false );
	public function set_id( $v ) { $this->d['id'] = $v; }
	public function set_name( $v ) { $this->d['name'] = $v; }
	public function set_options( $v ) { $this->d['options'] = $v; }
	public function set_position( $v ) { $this->d['position'] = $v; }
	public function set_visible( $v ) { $this->d['visible'] = $v; }
	public function set_variation( $v ) { $this->d['variation'] = $v; }
	public function get_name() { return $this->d['name']; }
	public function get_options() { return $this->d['options']; }
	public function get_position() { return $this->d['position']; }
	public function get_variation() { return $this->d['variation']; }
	public function is_taxonomy() { return false; }
}

function wc_get_product( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['POSTS'][ $id ] ) ) {
		return false;
	}
	if ( ! empty( $GLOBALS['PCACHE_ON'] ) ) {
		if ( ! isset( $GLOBALS['PCACHE'][ $id ] ) ) {
			$p       = wc_get_product_fresh( $id );
			$p->snap = $GLOBALS['META'][ $id ] ?? array();
			$GLOBALS['PCACHE'][ $id ] = $p;
		}
		return clone $GLOBALS['PCACHE'][ $id ];
	}
	return wc_get_product_fresh( $id );
}
function wc_get_product_fresh( $id ) {
	switch ( $GLOBALS['POSTS'][ $id ]['kind'] ) {
		case 'variable':
			return new WC_Product_Variable( $id );
		case 'variation':
			return new WC_Product_Variation( $id );
		default:
			return new WC_Product_Simple( $id );
	}
}
function wc_get_product_id_by_sku( $sku ) {
	foreach ( $GLOBALS['POSTS'] as $id => $p ) {
		if ( '' !== (string) $sku && 'trash' !== $p['status'] && (string) ( $GLOBALS['META'][ $id ]['_sku'] ?? '' ) === (string) $sku ) {
			return $id;
		}
	}
	return 0;
}
/**
 * WooCommerce's own, in its order (wc_update_product_stock() and its data
 * store's update_product_stock(), WooCommerce 11.2.1): writes the stock of
 * whichever product holds it and fires woocommerce_updated_product_stock with
 * its id; saves the product (woocommerce_update_product, where other plugins'
 * save hooks run); then fires the stock action for that product - the one the
 * plugin listens to in order to push a change to eBay.
 */
function wc_update_product_stock( $product, $quantity = null, $operation = 'set' ) {
	$p      = is_object( $product ) ? $product : wc_get_product( $product );
	$holder = $p ? $p->get_stock_managed_by_id() : 0;
	if ( ! $p || ! isset( $GLOBALS['POSTS'][ $holder ] ) || null === $quantity ) {
		return null;
	}
	$GLOBALS['WRITES'][] = array( $holder, $operation, (int) $quantity, TCGiant_Sync_Inventory::is_push_suppressed() );
	$m   = &$GLOBALS['META'][ $holder ];
	$now = (int) ( $m['_stock'] ?? 0 );
	// Another plugin holding this one's quantity: the write does not take,
	// and the action fires all the same.
	if ( ! in_array( (int) $holder, $GLOBALS['HELD_STOCK'] ?? array(), true ) ) {
		$m['_stock'] = 'set' === $operation ? (int) $quantity : ( 'decrease' === $operation ? $now - (int) $quantity : $now + (int) $quantity );
	}
	$m['_stock_status'] = $m['_stock'] > 0 ? 'instock' : 'outofstock';
	unset( $m );
	if ( empty( $GLOBALS['NO_SQL_ACTION'] ) ) {
		do_action( 'woocommerce_updated_product_stock', (int) $holder );
	}
	do_action( 'woocommerce_update_product', (int) $holder, wc_get_product( $holder ) );
	$saved = wc_get_product( $holder );
	do_action( $saved->is_type( 'variation' ) ? 'woocommerce_variation_set_stock' : 'woocommerce_product_set_stock', $saved );
	return $saved->get_stock_quantity();
}
function wc_update_product_stock_status( $id, $status ) { $GLOBALS['META'][ (int) $id ]['_stock_status'] = $status; }

// Orders.
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
	public function save_meta_data() { $GLOBALS['ORDER_SAVES'][] = $this->id; }
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
function wc_get_order( $id ) { return $GLOBALS['ORDERS'][ (int) $id ] ?? false; }

/**
 * WooCommerce's wc_reduce_stock_levels(): asks whether stock may be reduced,
 * takes each line's quantity off the product that holds the stock, records
 * it on the line, then says the order's stock was reduced.
 */
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

/**
 * WooCommerce's wc_increase_stock_levels() - an order cancelled, or set back
 * to pending: puts back what each line took, takes the line's _reduced_stock
 * away, then says the order's stock was put back.
 */
function wc_increase_stock_levels( $order ) {
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		$reduced = $item->get_meta( '_reduced_stock' );
		if ( '' === (string) $reduced || ! $product || ! $product->managing_stock() ) {
			continue;
		}
		wc_update_product_stock( $product, (int) $reduced, 'increase' );
		unset( $item->meta['_reduced_stock'] );
		$item->save();
	}
	do_action( 'woocommerce_restore_order_stock', $order );
}

// Action Scheduler: a queue, run through the hooks the plugin registered.
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	if ( isset( $GLOBALS['ON_QUEUE'] ) ) {
		( $GLOBALS['ON_QUEUE'] )( $hook );
	}
	$GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'when' => time() );
	return count( $GLOBALS['QUEUE'] );
}
function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) {
	if ( isset( $GLOBALS['ON_QUEUE'] ) ) {
		( $GLOBALS['ON_QUEUE'] )( $hook );
	}
	$GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'when' => (int) $when );
	return count( $GLOBALS['QUEUE'] );
}
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['QUEUE'] as $a ) {
		if ( $a['hook'] === $hook && ( null === $args || $a['args'] === $args ) ) {
			return $a['when'];
		}
	}
	return false;
}
function as_unschedule_all_actions( $hook, $args = null, $group = '' ) {
	$GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) use ( $hook ) { return $a['hook'] !== $hook; } ) );
}
// Action Scheduler 3.0 and later: the next action with this hook, these
// arguments and this group is taken off the queue.
function as_unschedule_action( $hook, $args = array(), $group = '' ) {
	foreach ( $GLOBALS['QUEUE'] as $i => $a ) {
		if ( $a['hook'] === $hook && $a['args'] === $args && ( '' === $group || $a['group'] === $group ) ) {
			array_splice( $GLOBALS['QUEUE'], $i, 1 );
			return $i;
		}
	}
	return null;
}

// The plugin's neighbours.
class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function write_now( $message, $level = 'error' ) { self::log( $message, $level ); }
	public static function flush() {}
}
class TCGiant_Sync_OAuth {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_settings() { return get_option( 'tcgiant_sync_ebay_settings', array() ); }
	public function get_access_token() { return 'harness-token'; }
	public function refresh_access_token() { return false; }
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { $GLOBALS['UPSERTS'][] = $row; return true; }
	public static function table_exists() { return false; }
	public static function table_name() { return 'wp_tcgiant_listings'; }
}

// The admin's AJAX ends a request; here it ends the call, carrying what it sent.
class Harness_Sent extends Exception {
	public $payload;
	public function __construct( $p ) { parent::__construct( 'sent' ); $this->payload = $p; }
}
function check_ajax_referer() { return 1; }
function current_user_can() { return true; }
function wp_send_json_success( $d = null ) { throw new Harness_Sent( array( 'success' => true, 'data' => $d ) ); }
function wp_send_json_error( $d = null ) { throw new Harness_Sent( array( 'success' => false, 'data' => $d ) ); }

// --- eBay, at the HTTP call -----------------------------------------------------

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

/** eBay refusing a call, as its envelope says it. */
function refused( $code, $message ) {
	return array( 'refuse' => array( (string) $code, $message ) );
}

/**
 * Every request is recorded and answered. A Trading call by its name, from
 * what the test scripted for it: GetItem from the listings in $EBAY['items']
 * (a list is answered in turn, the last one staying), anything else from
 * $EBAY['answers'][call] in turn, Success when nothing is scripted. REST calls
 * answer the order list.
 */
function wp_remote_request( $url, $args ) {
	$call   = $args['headers']['X-EBAY-API-CALL-NAME'] ?? '';
	$body   = (string) ( $args['body'] ?? '' );
	$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	$path   = preg_replace( '#^https://api\.ebay\.com/#', '', (string) strtok( $url, '?' ) );
	$GLOBALS['WIRE'][]      = array( '' !== $call ? $call : $method . ' ' . $path, $body );
	$GLOBALS['WIRE_URLS'][] = (string) $url;

	// The host stopping the request as this call goes out.
	if ( '' !== $call && ( $GLOBALS['KILL_ON'] ?? null ) === $call ) {
		$GLOBALS['KILL_ON'] = null;
		throw new Error( 'Maximum execution time of 120 seconds exceeded' );
	}
	// Something that happens while this call is out, once.
	if ( '' !== $call && isset( $GLOBALS['ON_CALL'][ $call ] ) ) {
		$then = $GLOBALS['ON_CALL'][ $call ];
		unset( $GLOBALS['ON_CALL'][ $call ] );
		$then();
	}

	// The REST APIs: the order list, and the Inventory API's offers and items.
	if ( '' === $call ) {
		$json = function ( $code, $data ) { return array( 'headers' => array(), 'response' => array( 'code' => $code ), 'body' => null === $data ? '' : json_encode( $data ) ); };
		$none = array( 'errors' => array( array( 'errorId' => 25713, 'message' => 'This Offer is not available.' ) ) );
		if ( 'sell/inventory/v1/offer' === $path ) {
			parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
			$offers = $GLOBALS['EBAY']['offers'][ $q['sku'] ?? '' ] ?? null;
			return null === $offers ? $json( 404, $none ) : $json( 200, array( 'offers' => $offers, 'total' => count( $offers ) ) );
		}
		if ( 0 === strpos( $path, 'sell/inventory/v1/inventory_item/' ) ) {
			$sku = rawurldecode( substr( $path, strlen( 'sell/inventory/v1/inventory_item/' ) ) );
			if ( 'PUT' === $method ) {
				$GLOBALS['EBAY']['put'][ $sku ] = json_decode( $body, true );
				return $json( 204, null );
			}
			return isset( $GLOBALS['EBAY']['inventory'][ $sku ] ) ? $json( 200, $GLOBALS['EBAY']['inventory'][ $sku ] ) : $json( 404, array( 'errors' => array( array( 'errorId' => 25710 ) ) ) );
		}
		return $json( 200, $GLOBALS['EBAY']['orders'] ?? array( 'orders' => array() ) );
	}

	$answer = null;
	if ( 'GetItem' === $call && preg_match( '#<ItemID>(\d+)</ItemID>#', $body, $m ) ) {
		$list = $GLOBALS['EBAY']['items'][ $m[1] ] ?? null;
		if ( is_array( $list ) && isset( $list[0] ) ) {
			$answer = count( $list ) > 1 ? array_shift( $GLOBALS['EBAY']['items'][ $m[1] ] ) : $list[0];
		} else {
			$answer = refused( 17, 'This item cannot be accessed because the listing has been deleted, is a Half.com listing, or you are not the seller.' );
		}
	} elseif ( ! empty( $GLOBALS['EBAY']['answers'][ $call ] ) ) {
		$answer = array_shift( $GLOBALS['EBAY']['answers'][ $call ] );
	}

	if ( $answer instanceof WP_Error ) {
		return $answer;
	}

	$inner = '<Ack>Success</Ack>';
	if ( is_array( $answer ) && isset( $answer['refuse'] ) ) {
		$inner = '<Ack>Failure</Ack><Errors><ShortMessage>' . esc_xml( $answer['refuse'][1] ) . '</ShortMessage><LongMessage>' . esc_xml( $answer['refuse'][1] ) . '</LongMessage><ErrorCode>' . $answer['refuse'][0] . '</ErrorCode><SeverityCode>Error</SeverityCode></Errors>';
	} elseif ( is_array( $answer ) ) {
		$inner .= to_xml( 'Item', $answer );
	} elseif ( 'EndItem' === $call ) {
		$inner .= '<EndTime>2026-10-24T09:30:00.000Z</EndTime>';
	} elseif ( 'ReviseInventoryStatus' === $call && isset( $GLOBALS['EBAY']['sold'] ) ) {
		// eBay's answer, as it documents it: each InventoryStatus given back
		// with the listing's Quantity - available and sold together. Only for
		// the listings a test says what eBay has sold of ($EBAY['sold']).
		preg_match_all( '#<InventoryStatus>(.*?)</InventoryStatus>#s', $body, $blocks );
		foreach ( $blocks[1] as $block ) {
			$item = item_in( $block );
			$sku  = (string) sku_in( $block );
			$key  = '' !== $sku ? $item . '/' . $sku : $item;
			if ( isset( $GLOBALS['EBAY']['sold'][ $key ] ) ) {
				$inner .= to_xml( 'InventoryStatus', array_merge( array( 'ItemID' => $item ), '' !== $sku ? array( 'SKU' => $sku ) : array(), array( 'Quantity' => (string) ( qty_sent( $block ) + (int) $GLOBALS['EBAY']['sold'][ $key ] ) ) ) );
			}
		}
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

require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-variations.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require $ROOT . '/includes/class-tcgiant-sync-mapper.php';
require $ROOT . '/includes/class-tcgiant-sync-importer.php';
require $ROOT . '/admin/class-tcgiant-sync-admin.php';

$INV = TCGiant_Sync_Inventory::instance();
$IMP = TCGiant_Sync_Importer::instance();
$API = TCGiant_Sync_API::instance();

// --- driving it ----------------------------------------------------------------

function fresh_shop( array $settings = array() ) {
	foreach ( array( 'POSTS', 'META', 'TRANSIENTS', 'DID', 'LOG', 'QUEUE', 'WIRE', 'WIRE_URLS', 'WRITES', 'ORDERS', 'ORDER_SAVES', 'ON_CALL' ) as $g ) {
		$GLOBALS[ $g ] = array();
	}
	$GLOBALS['KILL_ON'] = null;
	// Connected to eBay, as the OAuth stub's token says: a push is queued only
	// for a shop that holds both tokens (3.28.0).
	$GLOBALS['OPTIONS']       = array( 'tcgiant_sync_ebay_settings' => $settings + array( 'access_token' => 'harness-access', 'refresh_token' => 'harness-refresh' ) );
	$GLOBALS['EBAY']          = array( 'items' => array(), 'answers' => array(), 'orders' => array( 'orders' => array() ), 'offers' => array(), 'inventory' => array(), 'put' => array() );
	$GLOBALS['NEXT_ID']       = 9000;
	$GLOBALS['CALLS_COUNTED'] = 0;
}

/**
 * A simple product WooCommerce keeps stock for, on listing $item_id when
 * given - fixed-price, as an import records it: the type, and the listing
 * eBay said it for (pass '_ebay_listing_type_item' => null for a type the
 * 3.x backfill guessed).
 */
function product( $id, $stock, $item_id = '', array $meta = array() ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => 'product', 'kind' => 'simple', 'status' => 'publish', 'parent' => 0 );
	$GLOBALS['META'][ $id ]  = array_merge( array(
		'_sku'                 => 'SKU-' . $id,
		'_manage_stock'        => null === $stock ? 'no' : 'yes',
		'_stock'               => (int) $stock,
		'_stock_status'        => ( null === $stock || $stock > 0 ) ? 'instock' : 'outofstock',
	), '' !== $item_id ? array( '_ebay_item_id' => $item_id, '_ebay_listing_status' => 'Active', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_type_item' => $item_id ) : array(), $meta );
}
/** What eBay had sold, on that listing (and variation SKU), when WooCommerce last agreed with it. */
function seen( $id, $item_id, $sold, $sku = '' ) {
	$GLOBALS['META'][ $id ]['_ebay_sold_seen'] = array( 'item' => (string) $item_id, 'sku' => (string) $sku, 'sold' => (int) $sold );
}
function variable( $id, $item_id, array $meta = array() ) {
	product( $id, null, $item_id, $meta );
	$GLOBALS['POSTS'][ $id ]['kind'] = 'variable';
}
function variation( $id, $parent, $sku, $stock, array $attrs = array(), array $meta = array() ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => 'product_variation', 'kind' => 'variation', 'status' => 'publish', 'parent' => $parent );
	$GLOBALS['META'][ $id ]  = array_merge( array(
		'_sku'          => $sku,
		'_manage_stock' => null === $stock ? 'no' : 'yes',
		'_stock'        => (int) $stock,
		'_stock_status' => ( null === $stock || $stock > 0 ) ? 'instock' : 'outofstock',
		'_attrs'        => $attrs,
	), $meta );
}

/** A live fixed-price listing as GetItem returns it. */
function listing( $item_id, $listed, $sold, array $extra = array() ) {
	return array_replace_recursive( array(
		'ItemID'         => $item_id,
		'Quantity'       => (string) $listed,
		'ListingType'    => 'FixedPriceItem',
		'SellingStatus'  => array( 'ListingStatus' => 'Active', 'QuantitySold' => (string) $sold ),
		'ListingDetails' => array( 'StartTime' => '2026-10-01T10:00:00.000Z', 'EndTime' => '2026-10-31T10:00:00.000Z' ),
	), $extra );
}
function ended( $item_id, $listed, $sold ) {
	return listing( $item_id, $listed, $sold, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed' ) ) );
}
/** One variation: what it listed in all, what sold. */
function ev( $sku, $listed, $sold, array $specifics = array() ) {
	$v = array( 'Quantity' => (string) $listed, 'SellingStatus' => array( 'QuantitySold' => (string) $sold ) );
	if ( '' !== $sku ) {
		$v = array( 'SKU' => $sku ) + $v;
	}
	if ( $specifics ) {
		$pairs = array();
		foreach ( $specifics as $n => $val ) {
			$pairs[] = array( 'Name' => $n, 'Value' => $val );
		}
		$v['VariationSpecifics'] = array( 'NameValueList' => $pairs );
	}
	return $v;
}
function variation_listing( $item_id, array $variations ) {
	$listed = 0;
	$sold   = 0;
	foreach ( $variations as $v ) {
		$listed += (int) $v['Quantity'];
		$sold   += (int) $v['SellingStatus']['QuantitySold'];
	}
	return listing( $item_id, $listed, $sold, array( 'Variations' => array( 'Variation' => $variations ) ) );
}
/** eBay answers GetItem for this listing with these, in turn. */
function ebay_has( $item_id, ...$answers ) { $GLOBALS['EBAY']['items'][ (string) $item_id ] = $answers; }
function ebay_answers( $call, ...$answers ) { $GLOBALS['EBAY']['answers'][ $call ] = $answers; }

/** A website order, its stock reduced as WooCommerce reduces it outside classic checkout. */
function website_order( $order_id, array $lines ) {
	$items = array();
	$n     = 1;
	foreach ( $lines as $product_id => $qty ) {
		$items[] = new WC_Order_Item_Product( $order_id * 10 + $n++, $product_id, $qty );
	}
	$GLOBALS['ORDERS'][ $order_id ] = new WC_Order( $order_id, $items );
	wc_reduce_stock_levels( $GLOBALS['ORDERS'][ $order_id ] );
	return $GLOBALS['ORDERS'][ $order_id ];
}

/** A hand edit of the stock, as the product screen saves one. */
function hand_edit( $id, $stock ) { wc_update_product_stock( $id, $stock, 'set' ); }

/** Run what is queued, as Action Scheduler would: due now, or every action when $all. */
function run_queue( $all = false, $limit = 20 ) {
	$ran = 0;
	while ( $ran < $limit ) {
		$next = null;
		foreach ( $GLOBALS['QUEUE'] as $i => $a ) {
			if ( $all || $a['when'] <= time() ) {
				$next = $i;
				break;
			}
		}
		if ( null === $next ) {
			break;
		}
		$action = $GLOBALS['QUEUE'][ $next ];
		array_splice( $GLOBALS['QUEUE'], $next, 1 );
		foreach ( harness_listeners( $action['hook'] ) as $l ) {
			call_user_func_array( $l[0], array_slice( array_values( $action['args'] ), 0, $l[1] ) );
		}
		$ran++;
	}
	return $ran;
}
function pushes() { return array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) { return TCGiant_Sync_Inventory::PUSH_HOOK === $a['hook']; } ) ); }

function meta( $id, $key ) { return $GLOBALS['META'][ $id ][ $key ] ?? null; }
function stock( $id ) { return isset( $GLOBALS['META'][ $id ]['_stock'] ) ? (int) $GLOBALS['META'][ $id ]['_stock'] : null; }
function calls() { return array_column( $GLOBALS['WIRE'], 0 ); }
function sent( $call ) {
	foreach ( $GLOBALS['WIRE'] as $w ) {
		if ( $call === $w[0] ) {
			return $w[1];
		}
	}
	return '';
}
function all_sent( $call ) { return array_values( array_map( function ( $w ) { return $w[1]; }, array_filter( $GLOBALS['WIRE'], function ( $w ) use ( $call ) { return $call === $w[0]; } ) ) ); }
function wire_names( $needle ) {
	foreach ( $GLOBALS['WIRE'] as $w ) {
		if ( false !== strpos( $w[1], $needle ) ) {
			return true;
		}
	}
	return false;
}
function qty_sent( $body ) { return preg_match( '#<Quantity>(-?\d+)</Quantity>#', $body, $m ) ? (int) $m[1] : null; }
/** A ReviseInventoryStatus's InventoryStatus entries, SKU => Quantity, in order. */
function statuses( $body ) {
	$out = array();
	preg_match_all( '#<InventoryStatus>(.*?)</InventoryStatus>#s', (string) $body, $blocks );
	foreach ( $blocks[1] as $block ) {
		$out[ html_entity_decode( (string) sku_in( $block ), ENT_QUOTES | ENT_XML1 ) ] = qty_sent( $block );
	}
	return $out;
}
function item_in( $body ) { return preg_match( '#<ItemID>(\d+)</ItemID>#', $body, $m ) ? $m[1] : null; }
function sku_in( $body ) { return preg_match( '#<SKU>([^<]*)</SKU>#', $body, $m ) ? $m[1] : null; }
function logged( $needle, $level = null ) {
	foreach ( $GLOBALS['LOG'] as $l ) {
		if ( false !== strpos( $l[1], $needle ) && ( null === $level || $level === $l[0] ) ) {
			return true;
		}
	}
	return false;
}
function count_logged( $needle ) { return count( array_filter( $GLOBALS['LOG'], function ( $l ) use ( $needle ) { return false !== strpos( $l[1], $needle ); } ) ); }
function settled_on( $id ) { return array( null !== meta( $id, '_ebay_stock_settled_at' ), meta( $id, '_ebay_stock_settled_item' ) ); }
/** The relay's figures for today, as the token refresh leaves them. */
function fleet_used( $share ) {
	set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'daily_limit' => 50000, 'global_calls' => (int) ( 50000 * $share ), 'remaining' => (int) ( 50000 * ( 1 - $share ) ), 'updated_at' => time() ) );
}

/** A later request: WooCommerce's did_action() counts start again. */
function next_request() { $GLOBALS['DID'] = array(); }
function push_args() { return array_map( function ( $a ) { return array( $a['args'][0]['product_id'] ?? null, $a['args'][0]['reason'] ?? null, $a['args'][0]['qty'] ?? null ); }, pushes() ); }
function in_about( $action, $seconds ) { return abs( (int) $action['when'] - time() - (int) $seconds ) <= 2; }

// Another plugin that can stop a website order taking stock.
$REFUSE_REDUCTION = false;
add_filter( 'woocommerce_can_reduce_order_stock', function ( $yes ) { return $GLOBALS['REFUSE_REDUCTION'] ? false : $yes; }, 50, 1 );

echo "\nTHE HOOKS\n" . str_repeat( '=', 132 ) . "\n";

$registered = function ( $hook, $method ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $l ) {
		if ( is_array( $l[0] ) && $method === $l[0][1] ) {
			return array( $l[1], $l[2] );
		}
	}
	return null;
};
check( 'the push has a hook of its own, taking one argument', $registered( 'tcgiant_sync_push_stock', 'process_stock_push' ), array( 1, 10 ) );
check( 'pushes queued before 3.28.0 still run, with their two', $registered( 'tcgiant_sync_update_ebay_stock', 'process_ebay_stock_update' ), array( 2, 10 ) );
check( 'the order-reduction note is the last word on woocommerce_can_reduce_order_stock', $registered( 'woocommerce_can_reduce_order_stock', 'note_order_reduction' ), array( 2, PHP_INT_MAX ) );
check( 'pushes have a group of their own', TCGiant_Sync_Inventory::PUSH_GROUP, 'tcgiant_sync_stock' );
check( 'a push eBay will not take is tried for about 10 hours', array_sum( TCGiant_Sync_Inventory::PUSH_RETRY_DELAYS ), 37200 );

fresh_shop();
do_action( 'tcgiant_sync_push_stock', 'not a push' );
check( 'a queued push naming no product: nothing sent, said once', array( $WIRE, count_logged( 'named no product' ) ), array( array(), 1 ) );

echo "\nROUTED BY THE PRODUCT'S OWN LISTING, NEVER BY A SKU OR BARCODE ANOTHER PRODUCT SHARES (R3)\n" . str_repeat( '=', 132 ) . "\n";

// Two auto parts with one barcode: A on listing 111 with 4, B on 222 with 1.
// The SKU lookup found A first, so B's sale ended A's listing and left B's up.
fresh_shop();
product( 101, 4, '110000000111', array( '_sku' => 'UPC-0123', '_ebay_sku' => 'UPC-0123' ) );
product( 102, 1, '220000000222', array( '_sku' => 'UPC-0123-222', '_ebay_sku' => 'UPC-0123' ) );
ebay_has( '220000000222', listing( '220000000222', 1, 0 ) );
website_order( 501, array( 102 => 1 ) );
check( 'shared barcode: B\'s website sale queues one push, for B', push_args(), array( array( 102, 'order', 1 ) ) );
check( '  naming the product, never a SKU or a quantity to look up later; with the listing it is for, and the stock before the sale', array_keys( pushes()[0]['args'][0] ?? array() ), array( 'product_id', 'reason', 'qty', 'order', 'line', 'token', 'item', 'before' ) );
check( '  the listing B follows, and B\'s 1 before the sale', array( pushes()[0]['args'][0]['item'] ?? null, pushes()[0]['args'][0]['before'] ?? null ), array( '220000000222', 1 ) );
check( '  in its own group', pushes()[0]['group'] ?? null, 'tcgiant_sync_stock' );
run_queue();
check( '  eBay is asked about 222, and 222 is ended', array( calls(), item_in( sent( 'GetItem' ) ), item_in( sent( 'EndItem' ) ) ), array( array( 'GetItem', 'EndItem' ), '220000000222', '220000000222' ) );
check( '  no request names 111', wire_names( '110000000111' ), false );
check( '  A stays Active with its 4', array( meta( 101, '_ebay_listing_status' ), stock( 101 ) ), array( 'Active', 4 ) );
check( '  B is Ended, with eBay\'s end time and why', array( meta( 102, '_ebay_listing_status' ), meta( 102, '_ebay_end_time' ), meta( 102, '_ebay_end_reason' ), meta( 102, '_ebay_end_reason_item' ) ), array( 'Ended', '2026-10-24T09:30:00.000Z', 'stock_zero', '220000000222' ) );
check( '  and settled against 222', TCGiant_Sync_Inventory::listing_already_settled( 102, '220000000222' ), true );
next_request();
$WIRE = array();
hand_edit( 101, 3 );
run_queue();
check( '  a hand edit of A goes to 111 alone, with no read first', array( calls(), item_in( sent( 'ReviseInventoryStatus' ) ), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'ReviseInventoryStatus' ), '110000000111', 3 ) );

// The coin shop's SKU names the old listing: A moved on to relist 222 under
// EBAY-111, and B is a Sell Similar copy of 222 (listing 333, 3 coins)
// carrying the same SKU. B's sale went to A's live relist.
fresh_shop( array( 'sku_prefix' => 'ebay' ) );
product( 110, 1, '220000000222', array( '_sku' => 'EBAY-110000000111', '_ebay_sku' => 'EBAY-110000000111' ) );
product( 111, 3, '330000000333', array( '_sku' => 'EBAY-110000000111-333', '_ebay_sku' => 'EBAY-110000000111' ) );
ebay_has( '330000000333', listing( '330000000333', 3, 0 ), listing( '330000000333', 3, 1 ) );
website_order( 502, array( 111 => 1 ) );
run_queue();
check( 'a Sell Similar copy sells one: eBay is told 333 has 2', array( calls(), item_in( sent( 'ReviseInventoryStatus' ) ), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), '330000000333', 2 ) );
check( '  A\'s relist 222 is never named', wire_names( '220000000222' ), false );
next_request();
$WIRE = array();
website_order( 503, array( 111 => 2 ) );
run_queue();
check( '  the copy sells out: 333 is ended, not 222', array( calls(), item_in( sent( 'EndItem' ) ) ), array( array( 'GetItem', 'EndItem' ), '330000000333' ) );
check( '  A is still Active with its coin', array( meta( 110, '_ebay_listing_status' ), stock( 110 ) ), array( 'Active', 1 ) );

// export_5: a SKU made from the old item number, on a product that follows 222.
fresh_shop();
product( 120, 5, '220000000222', array( '_sku' => 'EBAY-110000000111' ) );
hand_edit( 120, 2 );
run_queue();
check( 'a SKU naming 111 on a product that follows 222: the change goes to 222', array( calls(), item_in( sent( 'ReviseInventoryStatus' ) ), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'ReviseInventoryStatus' ), '220000000222', 2 ) );
$WIRE = array();
$API->update_inventory_item_availability( 'EBAY-110000000111', 2 );
check( '  and the old call, given only that SKU, finds the product and sends to 222 too', array( calls(), item_in( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'ReviseInventoryStatus' ), '220000000222' ) );

// stock_13: a product pushed from WooCommerce has no _ebay_sku, so the old
// push never found it to record the end.
fresh_shop();
product( 130, 1, '440000000444', array( '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-10-01 10:00:00' ) );
ebay_has( '440000000444', listing( '440000000444', 1, 0 ) );
website_order( 504, array( 130 => 1 ) );
run_queue();
check( 'a pushed product (no _ebay_sku) sells out: EndItem, and the end recorded on it', array( calls(), meta( 130, '_ebay_listing_status' ), TCGiant_Sync_Inventory::listing_already_settled( 130, '440000000444' ) ), array( array( 'GetItem', 'EndItem' ), 'Ended', true ) );

// A listing two products claim - one copied before the duplicate guard.
fresh_shop();
product( 140, 2, '550000000555' );
product( 141, 2, '550000000555' );
hand_edit( 140, 1 );
run_queue();
check( 'a listing two products claim: nothing sent', $WIRE, array() );
check( '  and the shop is told which', logged( 'eBay listing 550000000555 is linked to WC #140 and also to #141', 'warning' ), true );

echo "\nPUSHES QUEUED BEFORE 3.28.0\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 150, 2, '555' );
$INV->process_ebay_stock_update( 'EBAY-555', 3 );
check( '(EBAY-555, 3) with WooCommerce now at 2: Quantity 2 sent to 555, not the old 3', array( calls(), item_in( sent( 'ReviseInventoryStatus' ) ), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'ReviseInventoryStatus' ), '555', 2 ) );
fresh_shop();
product( 151, 0, '660000000666', array( '_ebay_sku' => 'MORGAN-1921' ) );
product( 152, 0, '770000000777', array( '_ebay_sku' => 'MORGAN-1921' ) );
$INV->process_ebay_stock_update( 'MORGAN-1921', 0 );
check( '(MORGAN-1921, 0) with two products holding that SKU: nothing sent', $WIRE, array() );
check( '  the log names both', logged( 'matches WC #151 and #152', 'warning' ), true );
fresh_shop();
product( 153, 0, '880000000888', array( '_ebay_sku' => 'MORGAN-1922' ) );
$INV->process_ebay_stock_update( 'MORGAN-1922', 5 );
check( 'one product holding it, now at 0: ended at 0, not raised to the 5 queued', calls(), array( 'EndItem' ) );
fresh_shop();
$INV->process_ebay_stock_update( 'NOBODY', 1 );
check( 'a SKU no product has: nothing sent, said', array( $WIRE, logged( 'matches no product' ) ), array( array(), true ) );

echo "\nVARIATIONS: UPDATED ONE BY ONE, BY SKU, AND NEVER THE WHOLE LISTING ENDED FOR ONE\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
variable( 700, '777000000777', array( '_sku' => 'PHONE' ) );
variation( 701, 700, 'PHONE-BLK-64', 3, array( 'colour' => 'Black' ) );
variation( 702, 700, 'PHONE-WHT-64', 5, array( 'colour' => 'White' ) );
ebay_has( '777000000777', variation_listing( '777000000777', array( ev( 'PHONE-BLK-64', 3, 0, array( 'Colour' => 'Black' ) ), ev( 'PHONE-WHT-64', 5, 0, array( 'Colour' => 'White' ) ) ) ) );
website_order( 601, array( 701 => 1 ) );
check( 'a variation sells on the website: one push, for the variation', push_args(), array( array( 701, 'order', 1 ) ) );
run_queue();
$b = sent( 'ReviseInventoryStatus' );
check( '  eBay is asked, then that variation is set', calls(), array( 'GetItem', 'ReviseInventoryStatus' ) );
check( '  ItemID 777 (its parent\'s), SKU PHONE-BLK-64, Quantity 2', array( item_in( $b ), sku_in( $b ), qty_sent( $b ) ), array( '777000000777', 'PHONE-BLK-64', 2 ) );
check( '  one InventoryStatus, not the whole listing', substr_count( $b, '<InventoryStatus>' ), 1 );
check( '  no Inventory API request, no EndItem', array_values( array_diff( calls(), array( 'GetItem', 'ReviseInventoryStatus' ) ) ), array() );

// Black sells out while White still has 5.
next_request();
$WIRE = array();
ebay_has( '777000000777', variation_listing( '777000000777', array( ev( 'PHONE-BLK-64', 3, 1, array( 'Colour' => 'Black' ) ), ev( 'PHONE-WHT-64', 5, 0, array( 'Colour' => 'White' ) ) ) ) );
website_order( 602, array( 701 => 2 ) );
run_queue();
$b = sent( 'ReviseInventoryStatus' );
check( 'Black sells out while White has 5: SKU PHONE-BLK-64, Quantity 0', array( sku_in( $b ), qty_sent( $b ) ), array( 'PHONE-BLK-64', 0 ) );
check( '  no EndItem anywhere', in_array( 'EndItem', calls(), true ), false );
check( '  the listing stays Active', meta( 700, '_ebay_listing_status' ), 'Active' );

// A variation with no SKU of its own: WooCommerce shows its parent's. That
// SKU was sent, and named the whole listing - which at 0 was ended.
fresh_shop();
variable( 710, '788000000788', array( '_sku' => 'PARENT' ) );
variation( 711, 710, '', 2, array( 'size' => 'S' ) );
hand_edit( 711, 0 );
run_queue();
check( 'a variation with no SKU: nothing reaches eBay', $WIRE, array() );
check( '  the warning names the variation and the listing', array( logged( 'variation #711 of WC #710', 'warning' ), logged( '788000000788', 'warning' ) ), array( true, true ) );
check( '  the parent\'s SKU is never sent', wire_names( 'PARENT' ), false );

// The import made a variation's SKU unique, and recorded eBay's.
fresh_shop();
variable( 720, '799000000799' );
variation( 721, 720, 'ABC-1-720', 4, array(), array( '_ebay_variation_sku' => 'ABC-1' ) );
hand_edit( 721, 3 );
run_queue();
check( 'a variation whose SKU the import made unique: eBay\'s own SKU is sent', array( sku_in( sent( 'ReviseInventoryStatus' ) ), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( 'ABC-1', 3 ) );
// Imported before 3.28.0, so nothing recorded: a sale reads the listing anyway.
fresh_shop();
variable( 720, '799000000799' );
variation( 721, 720, 'ABC-1-720', 4 );
ebay_has( '799000000799', variation_listing( '799000000799', array( ev( 'ABC-1', 4, 0 ), ev( 'ABC-2', 2, 0 ) ) ) );
website_order( 603, array( 721 => 1 ) );
run_queue();
check( '  imported before 3.28.0: a sale finds eBay\'s SKU on the listing', array( sku_in( sent( 'ReviseInventoryStatus' ) ), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( 'ABC-1', 3 ) );
check( '  and keeps it for the variation once eBay has taken it', meta( 721, '_ebay_variation_sku' ), 'ABC-1' );
// And a hand edit, which does not read first: refused for WooCommerce's
// SKU, then sent once more to eBay's.
fresh_shop();
variable( 720, '799000000799' );
variation( 721, 720, 'ABC-1-720', 4 );
ebay_has( '799000000799', variation_listing( '799000000799', array( ev( 'ABC-1', 4, 0 ), ev( 'ABC-2', 2, 0 ) ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21676, 'Invalid SKU number.' ) );
hand_edit( 721, 2 );
run_queue();
check( '  a hand edit refused for WooCommerce\'s SKU: sent once more, to eBay\'s', array( calls(), array_map( 'sku_in', all_sent( 'ReviseInventoryStatus' ) ) ), array( array( 'ReviseInventoryStatus', 'GetItem', 'ReviseInventoryStatus' ), array( 'ABC-1-720', 'ABC-1' ) ) );
check( '  and not tried again', pushes(), array() );
check( '  eBay\'s SKU kept for the variation (fix round 1, L4)', meta( 721, '_ebay_variation_sku' ), 'ABC-1' );
next_request();
$WIRE = array();
hand_edit( 721, 1 );
run_queue();
check( '  so the next change goes to it first time', array( calls(), array_map( 'sku_in', all_sent( 'ReviseInventoryStatus' ) ) ), array( array( 'ReviseInventoryStatus' ), array( 'ABC-1' ) ) );

// The last variation with stock sells. Without Out-of-Stock control eBay will
// not set it to 0 (942); every other variation shows 0, so the listing ends.
fresh_shop();
variable( 750, '820000000820' );
variation( 751, 750, 'CARD-A', 1 );
variation( 752, 750, 'CARD-B', 0 );
$both_out = variation_listing( '820000000820', array( ev( 'CARD-A', 1, 0 ), ev( 'CARD-B', 1, 1 ) ) );
ebay_has( '820000000820', $both_out, $both_out );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
website_order( 604, array( 751 => 1 ) );
run_queue();
check( 'the last variation with stock: refused at 0, every other shows 0, so the listing is ended', calls(), array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ) );
check( '  EndItem for 820', item_in( sent( 'EndItem' ) ), '820000000820' );
check( '  the log explains Out-of-Stock control', logged( 'Turn on Out-of-Stock control', 'warning' ), true );
check( '  Ended, with why, and settled', array( meta( 750, '_ebay_listing_status' ), meta( 750, '_ebay_end_reason' ), settled_on( 750 ) ), array( 'Ended', 'stock_zero', array( true, '820000000820' ) ) );
check( '  nothing tried again', pushes(), array() );
// Another variation still has stock: no EndItem, tried again.
fresh_shop();
variable( 750, '820000000820' );
variation( 751, 750, 'CARD-A', 1 );
variation( 752, 750, 'CARD-B', 2 );
$b_left = variation_listing( '820000000820', array( ev( 'CARD-A', 1, 0 ), ev( 'CARD-B', 2, 0 ) ) );
ebay_has( '820000000820', $b_left, $b_left );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
website_order( 605, array( 751 => 1 ) );
run_queue();
$p = pushes();
check( '  another variation still has stock: no EndItem', in_array( 'EndItem', calls(), true ), false );
check( '  tried again in 5 minutes, as attempt 2', array( count( $p ), isset( $p[0] ) && in_about( $p[0], 300 ), $p[0]['args'][0]['attempt'] ?? null ), array( 1, true, 2 ) );
check( '  the listing still Active', meta( 750, '_ebay_listing_status' ), 'Active' );
// eBay still shows another variation with stock, though WooCommerce has none
// of it: whatever eBay refused, it was not the last variation's 0.
fresh_shop();
variable( 750, '820000000820' );
variation( 751, 750, 'CARD-A', 1 );
variation( 752, 750, 'CARD-B', 0 );
$b_on_ebay = variation_listing( '820000000820', array( ev( 'CARD-A', 1, 0 ), ev( 'CARD-B', 2, 0 ) ) );
ebay_has( '820000000820', $b_on_ebay, $b_on_ebay );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
website_order( 608, array( 751 => 1 ) );
run_queue();
check( '  eBay still shows B with stock, WooCommerce has none of it: not the last variation, so no EndItem', array( in_array( 'EndItem', calls(), true ), count( pushes() ) ), array( false, 1 ) );

// Stock kept on the parent and shared by its variations (fix round 1, M1).
// Before, nothing at all was sent for one - not even at 0, where 3.27 ended
// the listing. The one figure now goes to every variation of the listing,
// up to four in a call, and is read back: eBay can take part of a call.
fresh_shop();
variable( 760, '830000000830', array( '_manage_stock' => 'yes', '_stock' => 5 ) );
variation( 761, 760, 'POOL-A', null, array( 'size' => 'S' ) );
variation( 762, 760, 'POOL-B', null, array( 'size' => 'M' ) );
$pool = function ( $a, $b, $b_sold = 0 ) { return variation_listing( '830000000830', array( ev( 'POOL-A', $a, 0, array( 'size' => 'S' ) ), ev( 'POOL-B', $b + $b_sold, $b_sold, array( 'size' => 'M' ) ) ) ); };
ebay_has( '830000000830', $pool( 5, 5 ), $pool( 4, 4 ) );
hand_edit( 761, 4 );
check( 'a variation sharing its parent\'s stock is edited: one push, for the parent that holds it', push_args(), array( array( 760, 'edit', null ) ) );
run_queue();
check( '  eBay asked, both variations set to the shared 4 in one call, then read back', array( calls(), statuses( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), array( 'POOL-A' => 4, 'POOL-B' => 4 ) ) );
check( '  eBay\'s sales recorded for each variation sharing it, on that variation, by its SKU (fix round 2)', array( meta( 761, '_ebay_sold_seen' ), meta( 762, '_ebay_sold_seen' ) ), array( array( 'item' => '830000000830', 'sku' => 'POOL-A', 'sold' => 0 ), array( 'item' => '830000000830', 'sku' => 'POOL-B', 'sold' => 0 ) ) );
// A website sale, while eBay has sold one of B that WooCommerce has not heard
// of: that one comes off the shared stock too, and no variation eBay shows
// fewer of is raised.
next_request();
$WIRE = array();
ebay_has( '830000000830', $pool( 1, 4, 1 ), $pool( 1, 2, 1 ) );
website_order( 606, array( 761 => 1 ) );
check( '  sold on the website: one push, for the parent, with the stock before the sale', array( push_args(), pushes()[0]['args'][0]['before'] ?? null ), array( array( array( 760, 'order', 1 ) ), 4 ) );
run_queue();
check( '  eBay\'s sale of B comes off too (4 - 1 - 1 = 2): 2 sent where eBay shows more; A\'s 1 is not raised', array( statuses( sent( 'ReviseInventoryStatus' ) ), stock( 760 ), meta( 762, '_ebay_sold_seen' )['sold'] ?? null ), array( array( 'POOL-B' => 2 ), 2, 1 ) );
check( '  the order line marked as sent once eBay took it', '' !== (string) $ORDERS[606]->get_items()[0]->get_meta( '_tcgiant_ebay_push' ), true );
// Sold out: 0 to each. eBay will not take 0 for the last with stock without
// Out-of-Stock control, so that one is refused and the listing ended.
next_request();
$WIRE = array();
ebay_has( '830000000830', $pool( 1, 2, 1 ), $pool( 0, 2, 1 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
website_order( 607, array( 762 => 2 ) );
run_queue();
check( '  the shared stock runs out: 0 sent to each, the last with stock kept by eBay, so the listing is ended', array( calls(), statuses( sent( 'ReviseInventoryStatus' ) ), item_in( sent( 'EndItem' ) ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), array( 'POOL-A' => 0, 'POOL-B' => 0 ), '830000000830' ) );
check( '  Ended, with why, settled, and the log explains Out-of-Stock control', array( meta( 760, '_ebay_listing_status' ), meta( 760, '_ebay_end_reason' ), settled_on( 760 ), logged( 'share stock that has run out', 'warning' ) ), array( 'Ended', 'stock_zero', array( true, '830000000830' ), true ) );
// Answered Success, yet one variation did not take it: tried again, never
// called done.
fresh_shop();
variable( 760, '830000000830', array( '_manage_stock' => 'yes', '_stock' => 5 ) );
variation( 761, 760, 'POOL-A', null, array( 'size' => 'S' ) );
variation( 762, 760, 'POOL-B', null, array( 'size' => 'M' ) );
ebay_has( '830000000830', $pool( 5, 5 ), $pool( 4, 5 ) );
hand_edit( 761, 4 );
run_queue();
check( '  eBay took A and not B, though it answered Success: tried again', array( calls(), count( pushes() ), pushes()[0]['args'][0]['attempt'] ?? null ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), 1, 2 ) );
// Five variations: four to a call.
fresh_shop();
variable( 765, '835000000835', array( '_manage_stock' => 'yes', '_stock' => 3 ) );
$five = array();
foreach ( array( 'A', 'B', 'C', 'D', 'E' ) as $n => $letter ) {
	variation( 766 + $n, 765, 'FIVE-' . $letter, null, array( 'size' => $letter ) );
	$five[] = ev( 'FIVE-' . $letter, 3, 0, array( 'size' => $letter ) );
}
ebay_has( '835000000835', variation_listing( '835000000835', $five ) );
hand_edit( 766, 2 );
run_queue();
check( '  five variations: two calls, four and one, then read back', array( calls(), array_map( function ( $b ) { return count( statuses( $b ) ); }, all_sent( 'ReviseInventoryStatus' ) ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'ReviseInventoryStatus', 'GetItem' ), array( 4, 1 ) ) );
// The parent of variations that keep their own stock: its total is not
// sent, and the shop is warned - the listing is on eBay and is not told.
fresh_shop();
variable( 780, '845000000845', array( '_manage_stock' => 'yes', '_stock' => 9 ) );
variation( 781, 780, 'OWN-1', 4 );
hand_edit( 780, 8 );
check( '  a parent whose variations keep their own stock: its own figure is not queued, with a warning', array( pushes(), logged( 'WC #780 is a variable product whose variations keep their own stock', 'warning' ) ), array( array(), true ) );

// A variation eBay no longer has - taken off at 0 before it ever sold.
fresh_shop();
variable( 770, '840000000840' );
variation( 771, 770, 'GONE-1', 2, array( 'size' => 'XL' ) );
ebay_has( '840000000840', variation_listing( '840000000840', array( ev( 'OTHER-1', 2, 0, array( 'size' => 'S' ) ) ) ) );
website_order( 607, array( 771 => 1 ) );
run_queue();
check( 'a variation not on the listing: nothing sent, an error, not tried again', array( calls(), pushes(), logged( 'variation GONE-1 is not on eBay listing 840000000840', 'error' ) ), array( array( 'GetItem' ), array(), true ) );

echo "\nTHE IMPORT RECORDS eBAY'S VARIATION SKU, AND HOLDS BACK WHILE A PUSH IS ON ITS WAY\n" . str_repeat( '=', 132 ) . "\n";

$MAPPER = TCGiant_Sync_Mapper::instance();
$SAVE   = new ReflectionMethod( 'TCGiant_Sync_Mapper', 'save_variations' );
if ( PHP_VERSION_ID < 80100 ) {
	$SAVE->setAccessible( true );
}
function import_variations( $parent, array $variations ) {
	$data = array();
	foreach ( $variations as $v ) {
		$data[] = $v + array( 'regular_price' => '9.99', 'sale_price' => '', 'attributes' => array(), 'image_url' => '' );
	}
	$GLOBALS['SAVE']->invokeArgs( $GLOBALS['MAPPER'], array( $parent, &$data ) );
	return $data;
}

fresh_shop();
variable( 730, '800000000800' );
product( 739, 1, '', array( '_sku' => 'MUG-RED' ) );
$data = import_variations( 730, array(
	array( 'sku' => 'MUG-RED', 'stock_quantity' => 3, 'attributes' => array( 'Colour' => 'Red' ) ),
	array( 'sku' => 'MUG-BLUE', 'stock_quantity' => 2, 'attributes' => array( 'Colour' => 'Blue' ) ),
	array( 'sku' => '', 'stock_quantity' => 1, 'attributes' => array( 'Colour' => 'Green' ) ),
) );
$red = (int) ( $data[0]['variation_id'] ?? 0 );
check( 'an imported variation whose SKU another product holds: made unique here', meta( $red, '_sku' ), 'MUG-RED-730' );
check( '  eBay\'s own SKU recorded beside it', meta( $red, '_ebay_variation_sku' ), 'MUG-RED' );
check( '  one with no conflict records its SKU too', meta( (int) ( $data[1]['variation_id'] ?? 0 ), '_ebay_variation_sku' ), 'MUG-BLUE' );
check( '  one with none records nothing', meta( (int) ( $data[2]['variation_id'] ?? 0 ), '_ebay_variation_sku' ), null );
// A stock push for it then names the variation by eBay's SKU.
hand_edit( $red, 2 );
run_queue();
check( '  and its stock change is sent for MUG-RED, not MUG-RED-730', sku_in( sent( 'ReviseInventoryStatus' ) ), 'MUG-RED' );

// While a push of the variation is on its way, the import keeps the lower.
fresh_shop();
variable( 740, '810000000810' );
variation( 741, 740, 'TEE-S', 3, array( 'Size' => 'S' ) );
hand_edit( 741, 1 );
check( 'a hand edit queues its push and marks the change on its way', array( count( pushes() ), TCGiant_Sync_Mapper::push_pending( 741 ) ), array( 1, true ) );
import_variations( 740, array( array( 'sku' => 'TEE-S', 'stock_quantity' => 3, 'attributes' => array( 'Size' => 'S' ) ) ) );
check( '  an import before the push runs keeps WooCommerce\'s 1, not eBay\'s older 3', stock( 741 ), 1 );
check( '  and says so', logged( 'Variation #741 kept at 1 rather than eBay\'s 3' ), true );
run_queue();
$left = (array) meta( 741, '_ebay_push_pending' );
check( '  once sent, it counts a quarter of an hour more, no longer', $left && max( $left ) - time() <= 900 && max( $left ) - time() > 800, true );
$META[741]['_ebay_push_pending'] = array( 'old' => time() - 1 );
import_variations( 740, array( array( 'sku' => 'TEE-S', 'stock_quantity' => 4, 'attributes' => array( 'Size' => 'S' ) ) ) );
check( '  with nothing on its way, eBay\'s figure stands again: stock raised in Seller Hub arrives', stock( 741 ), 4 );

echo "\nWHAT eBAY'S ANSWER MEANS IS READ BACK FROM eBAY, NEVER FROM ITS WORDS\n" . str_repeat( '=', 132 ) . "\n";

// EndItem refused with a code nobody has seen, and the listing still live.
fresh_shop();
product( 200, 1, '900000000900' );
ebay_has( '900000000900', listing( '900000000900', 1, 0 ) );
ebay_answers( 'EndItem', refused( 21919999, 'Something eBay has not said before.' ) );
hand_edit( 200, 0 );
run_queue();
$p = pushes();
check( 'EndItem refused, unknown code, GetItem says Active: still Active', meta( 200, '_ebay_listing_status' ), 'Active' );
check( '  no settle written, no end reason', array( settled_on( 200 ), meta( 200, '_ebay_end_reason' ) ), array( array( false, null ), null ) );
check( '  one try again, in 5 minutes, as attempt 2', array( count( $p ), isset( $p[0] ) && in_about( $p[0], 300 ), $p[0]['args'][0]['attempt'] ?? null ), array( 1, true, 2 ) );

// EndItem refused with 1047, and eBay says the listing sold out: settled
// through the same routine as the delta, sold-out removal included.
fresh_shop();
product( 201, 1, '900000000901' );
ebay_has( '900000000901', ended( '900000000901', 1, 1 ) );
ebay_answers( 'EndItem', refused( 1047, 'The auction has been closed.' ) );
hand_edit( 201, 0 );
run_queue();
check( 'EndItem refused (1047), GetItem says Completed, 1 of 1 sold: settled as the delta settles', array( meta( 201, '_ebay_listing_status' ), settled_on( 201 ) ), array( 'Ended', array( true, '900000000901' ) ) );
check( '  sold out on eBay and imported, so "Remove sold products" trashed it', get_post_status( 201 ), 'trash' );
check( '  not tried again', pushes(), array() );

// "Suspended" contains "ended". It was read as an ended listing.
fresh_shop();
product( 220, 2, '920000000920' );
ebay_has( '920000000920', listing( '920000000920', 2, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21916750, 'Your selling privileges are suspended while we review your account.' ) );
hand_edit( 220, 1 );
run_queue();
check( 'a refusal saying "suspended", GetItem Active: not an ended listing', array( meta( 220, '_ebay_listing_status' ), settled_on( 220 ), count( pushes() ) ), array( 'Active', array( false, null ), 1 ) );

// A listing made through eBay's Inventory API takes no change from the
// Trading API. eBay's code for that refusal is not known, so it is not
// relied on: the listing is read back, and its SKU's offers asked.
fresh_shop();
product( 310, 3, '100000000310', array( '_sku' => 'INV-310' ) );
ebay_has( '100000000310', listing( '100000000310', 3, 0, array( 'SKU' => 'INV-310' ) ), listing( '100000000310', 2, 0, array( 'SKU' => 'INV-310' ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21919474, 'This listing was created with the Inventory API.' ) );
$EBAY['offers']['INV-310']    = array( array( 'offerId' => '9001', 'sku' => 'INV-310', 'listing' => array( 'listingId' => '100000000310', 'listingStatus' => 'ACTIVE' ) ) );
$EBAY['inventory']['INV-310'] = array( 'sku' => 'INV-310', 'product' => array( 'title' => 'Brake pad' ), 'availability' => array( 'shipToLocationAvailability' => array( 'quantity' => 3 ) ) );
hand_edit( 310, 2 );
run_queue();
check( 'an Inventory API listing: refused by the Trading API, sent through the Inventory API, read back', calls(), array( 'ReviseInventoryStatus', 'GetItem', 'GET sell/inventory/v1/offer', 'GET sell/inventory/v1/inventory_item/INV-310', 'PUT sell/inventory/v1/inventory_item/INV-310', 'GetItem' ) );
check( '  the inventory item sent back whole, with the new quantity', $EBAY['put']['INV-310'] ?? null, array( 'sku' => 'INV-310', 'product' => array( 'title' => 'Brake pad' ), 'availability' => array( 'shipToLocationAvailability' => array( 'quantity' => 2 ) ) ) );
check( '  done, not tried again', pushes(), array() );
// The SKU's offer is for another listing: that inventory item is not ours.
fresh_shop();
product( 311, 3, '100000000311', array( '_sku' => 'INV-311' ) );
ebay_has( '100000000311', listing( '100000000311', 3, 0, array( 'SKU' => 'INV-311' ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21919474, 'This listing was created with the Inventory API.' ) );
$EBAY['offers']['INV-311']    = array( array( 'offerId' => '9002', 'sku' => 'INV-311', 'listing' => array( 'listingId' => '199999999999' ) ) );
$EBAY['inventory']['INV-311'] = array( 'sku' => 'INV-311', 'availability' => array( 'shipToLocationAvailability' => array( 'quantity' => 3 ) ) );
hand_edit( 311, 2 );
run_queue();
check( '  a SKU whose offer is another listing\'s: that item is left alone, the push tried again', array( isset( $EBAY['put']['INV-311'] ), count( pushes() ) ), array( false, 1 ) );
// A listing with no SKU on eBay is not asked about at all.
fresh_shop();
product( 312, 3, '100000000312' );
ebay_has( '100000000312', listing( '100000000312', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21919474, 'This listing was created with the Inventory API.' ) );
hand_edit( 312, 2 );
run_queue();
check( '  a listing with no SKU on eBay: the Inventory API is not asked', calls(), array( 'ReviseInventoryStatus', 'GetItem' ) );

// Every HTTP try fails, then eBay is out of calls on every try after. The
// listing has nothing on record yet, so eBay is asked straight away whether
// the change got there (fix round 1, for S4): it did not.
fresh_shop();
product( 210, 5, '910000000910' );
ebay_has( '910000000910', listing( '910000000910', 5, 0 ) );
$timeout = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
hand_edit( 210, 4 );
run_queue();
check( 'every HTTP try fails: three tries at the call, eBay asked, then the push is queued again', array( count( all_sent( 'ReviseInventoryStatus' ) ), array_values( array_unique( calls() ) ), count( pushes() ), pushes()[0]['args'][0]['attempt'] ?? null ), array( 3, array( 'ReviseInventoryStatus', 'GetItem' ), 1, 2 ) );
check( '  still Active, nothing recorded', array( meta( 210, '_ebay_listing_status' ), settled_on( 210 ) ), array( 'Active', array( false, null ) ) );
check( '  said as a warning, with the try', logged( 'Trying again in 5 minutes (try 2 of 6)', 'warning' ), true );
$waits = array();
for ( $try = 2; $try <= 6; $try++ ) {
	// eBay's 10007 on tries 2 to 5, an error inside eBay that passes: each a
	// counted try. The last meets eBay's 518, which since release fix round
	// 2 waits on its own (below) whatever the try.
	ebay_answers( 'ReviseInventoryStatus', 6 === $try ? refused( 518, 'Call usage limit has been reached.' ) : refused( 10007, 'Internal error to the application.' ) );
	$p = pushes();
	if ( $p ) {
		$waits[] = (int) $p[0]['when'] - time();
	}
	run_queue( true, 1 );
}
check( '  tried again after 5 and 15 minutes, 1, 3 and 6 hours', array_map( function ( $w ) { return (int) round( $w / 60 ); }, $waits ), array( 5, 15, 60, 180, 360 ) );
// Release fix rounds 1 and 2 (F2, N2): eBay's 518 on the last try lifts when
// it lifts - the push waits, not counted as a try, and is tried again within
// the hour (every 15 minutes in the hour after 00:05:30 UTC); each try asks
// the shared question first, and sends nothing while a 518 is known. After
// three days from when it was queued it is sent once more, and given up.
$slot = gmmktime( 0, 0, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ), (int) gmdate( 'Y' ) ) + 330;
$soon = ( time() >= $slot && time() < $slot + HOUR_IN_SECONDS ) ? 900 : min( HOUR_IN_SECONDS, ( $slot > time() ? $slot : $slot + DAY_IN_SECONDS ) - time() );
$p    = pushes();
check( 'N2 the last try meets eBay\'s 518: tried again within the hour, its try not counted, waiting on the 518', array( count( $p ), (int) ( $p[0]['args'][0]['attempt'] ?? 0 ), ! empty( $p[0]['args'][0]['e518'] ), isset( $p[0] ) && in_about( $p[0], $soon ) ), array( 1, 6, true, true ) );
check( '  said, as the try again it is', logged( 'on its last try. Trying again in ', 'warning' ) && logged( 'once eBay\'s call limit (518) has lifted, not counted as a try.', 'warning' ), true );
// Its try again, while the 518 is still known: nothing sent, put off again.
$WIRE = array();
run_queue( true, 1 );
$p = pushes();
check( '  its try again while that 518 is known: nothing sent, put off again, still its last try', array( $WIRE, count( $p ), (int) ( $p[0]['args'][0]['attempt'] ?? 0 ) ), array( array(), 1, 6 ) );
// The next slot: the shared question (eBay\'s clock) still meets the 518 - nothing else sent.
delete_transient( 'tcgiant_sync_ebay_518' );
ebay_answers( 'GeteBayOfficialTime', refused( 518, 'Call usage limit has been reached.' ) );
$WIRE = array();
run_queue( true, 1 );
check( '  the next slot: eBay\'s clock asked once, it meets the 518 too, and the push sends nothing', array( calls(), count( pushes() ) ), array( array( 'GeteBayOfficialTime' ), 1 ) );
// Three days on from when it was queued: sent once more - even with a 518
// still kept for the others, it waits no longer - and given up.
$p   = pushes();
$job = $p[0]['args'][0];
$job['since'] = time() - 3 * DAY_IN_SECONDS - 1;
$QUEUE = array();
set_transient( 'tcgiant_sync_ebay_518', array( 'until' => time() + 1800 ), 1800 );
ebay_answers( 'ReviseInventoryStatus', refused( 518, 'Call usage limit has been reached.' ) );
$OPTIONS[ TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION ] = array_fill( 0, 200, array( 'product_id' => 1 ) );
$INV->process_stock_push( $job );
check( '  three days from when it was queued: sent once more, not queued again', array( in_array( 'ReviseInventoryStatus', calls(), true ), pushes() ), array( true, array() ) );
check( '  an error, with eBay\'s answer', array( logged( 'gave up after 6 tries', 'error' ), logged( '518', 'error' ) ), array( true, true ) );
check( '  saying how long it waited, and for what (release fix round 3)', logged( 'gave up after 6 tries over about 3 days, waiting for eBay\'s call limit (518) to lift.', 'error' ), true );
$failures = get_option( TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION );
$last     = end( $failures );
check( '  listed, newest last, 200 kept', array( count( $failures ), $last['product_id'], $last['item_id'], $last['code'] ), array( 200, 210, '910000000910', '518' ) );
check( '  in an option not loaded on every page', $OPTIONS['__autoload'][ TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION ] ?? null, false );
check( '  the listing still Active', meta( 210, '_ebay_listing_status' ), 'Active' );
check( '  and the push no longer holds back an import', TCGiant_Sync_Mapper::push_pending( 210 ) && max( (array) meta( 210, '_ebay_push_pending' ) ) - time() > 900, false );
check( '  never having got there, it recorded nothing of eBay\'s sales', meta( 210, '_ebay_sold_seen' ), null );

// N2: eBay's 518 on a push's first try waits on it the same way - not its
// tries hours apart, which reached eBay up to four hours after it lifted.
fresh_shop();
product( 213, 5, '913000000913' );
ebay_has( '913000000913', listing( '913000000913', 5, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 518, 'Call usage limit has been reached.' ) );
$TRANSIENTS = array();
hand_edit( 213, 4 );
run_queue();
$p = pushes();
check( 'N2 eBay\'s 518 on the first try: still try 1, waiting on the 518, tried again within the hour', array( count( $p ), (int) ( $p[0]['args'][0]['attempt'] ?? 0 ), ! empty( $p[0]['args'][0]['e518'] ), isset( $p[0] ) && (int) $p[0]['when'] - time() <= HOUR_IN_SECONDS ), array( 1, 1, true, true ) );
check( '  said, with the try', logged( 'on try 1 of 6. Trying again in ', 'warning' ), true );
// N1 with it: while it waits on the kept 518, its pending entry runs to the
// slot and the tail, its queue time kept - and is never shortened.
$tok213 = (string) ( $p[0]['args'][0]['token'] ?? '' );
$q213   = time() - HOUR_IN_SECONDS;
$META[213]['_ebay_push_pending'][ $tok213 ] = array( 'until' => time() + 60, 'queued_at' => $q213 );
$WIRE = array();
run_queue( true, 1 );
$e213 = ( (array) meta( 213, '_ebay_push_pending' ) )[ $tok213 ] ?? array();
check( 'N1 its try again while the 518 is kept: nothing sent, its entry runs to that slot and the tail, its queue time kept', array( '' !== $tok213, $WIRE, (int) ( $e213['until'] ?? 0 ) >= (int) ( pushes()[0]['when'] ?? PHP_INT_MAX ) + TCGiant_Sync_Inventory::PUSH_PENDING_TAIL, (int) ( $e213['queued_at'] ?? 0 ) === $q213 ), array( true, array(), true, true ) );
$far213 = time() + 2 * DAY_IN_SECONDS;
$META[213]['_ebay_push_pending'][ $tok213 ] = array( 'until' => $far213, 'queued_at' => $q213 );
run_queue( true, 1 );
check( '  an entry already longer is never shortened', (int) ( ( (array) meta( 213, '_ebay_push_pending' ) )[ $tok213 ]['until'] ?? 0 ), $far213 );
// N2: once it meets anything but a 518, it no longer waits on one - its
// next try asks nothing first. A failure that passes, counted:
$j213 = pushes()[0]['args'][0];
$QUEUE = array();
$TRANSIENTS = array();
ebay_answers( 'ReviseInventoryStatus', refused( 10007, 'Internal error to the application.' ) );
$INV->process_stock_push( $j213 );
check( 'N2 the 518 lifted, its try meets a failure that passes: counted, and no longer waiting on the 518', array( ! empty( $j213['e518'] ), (int) ( pushes()[0]['args'][0]['attempt'] ?? 0 ), ! empty( pushes()[0]['args'][0]['e518'] ) ), array( true, 2, false ) );
// and its last try timing out with eBay not answering: it waits, uncounted, no longer on the 518.
$j213            = pushes()[0]['args'][0];
$j213['attempt'] = 6;
$j213['e518']    = 1;
$QUEUE      = array();
$TRANSIENTS = array();
$t213       = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
ebay_answers( 'ReviseInventoryStatus', $t213, $t213, $t213 );
ebay_answers( 'GeteBayOfficialTime', 'ok', $t213, $t213, $t213 );
$INV->process_stock_push( $j213 );
check( '  its last try timing out, eBay not answering: it waits uncounted, no longer on the 518', array( count( pushes() ), (int) ( pushes()[0]['args'][0]['attempt'] ?? 0 ), ! empty( pushes()[0]['args'][0]['since'] ), ! empty( pushes()[0]['args'][0]['e518'] ) ), array( 1, 6, true, false ) );
ebay_answers( 'GeteBayOfficialTime' );

// A sale's push whose revise did not go through. A try again carries no
// figure from the first try (fix round 1, H1): it works out again from what
// eBay and WooCommerce say then, and takes off only the eBay sales that
// WooCommerce has not heard of - recorded before the first try sent.
fresh_shop();
product( 230, 3, '930000000930' );
ebay_has( '930000000930', listing( '930000000930', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 10007, 'Internal error to the application.' ) );
website_order( 610, array( 230 => 1 ) );
run_queue();
check( 'a sale\'s revise not taken: tried again, carrying no figure', array( array_key_exists( 'target', pushes()[0]['args'][0] ?? array() ), pushes()[0]['args'][0]['attempt'] ?? null ), array( false, 2 ) );
check( '  eBay\'s sales recorded before it sent: none yet', meta( 230, '_ebay_sold_seen' ), array( 'item' => '930000000930', 'sku' => '', 'sold' => 0 ) );
ebay_has( '930000000930', listing( '930000000930', 2, 0 ) );
$WIRE = array();
run_queue( true );
check( '  the revise had got there after all (2 listed, none sold): nothing more sent', calls(), array( 'GetItem' ) );
check( '  WooCommerce at 2', stock( 230 ), 2 );
fresh_shop();
product( 233, 3, '930000000933' );
ebay_has( '930000000933', listing( '930000000933', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 10007, 'Internal error to the application.' ) );
website_order( 616, array( 233 => 1 ) );
run_queue();
ebay_has( '930000000933', listing( '930000000933', 3, 1 ) );
$WIRE = array();
run_queue( true );
check( '  eBay sold one meanwhile (3 listed, 1 sold): WooCommerce takes it off too, and eBay is sent the 1 left', array( calls(), qty_sent( sent( 'ReviseInventoryStatus' ) ), stock( 233 ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), 1, 1 ) );
fresh_shop();
product( 231, 3, '930000000931' );
ebay_has( '930000000931', listing( '930000000931', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 10007, 'Internal error to the application.' ) );
website_order( 611, array( 231 => 1 ) );
run_queue();
$WIRE = array();
run_queue( true );
check( '  eBay still at 3 on the next try: Quantity 2, once', array( calls(), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), 2 ) );
fresh_shop();
product( 232, 3, '930000000932' );
ebay_has( '930000000932', listing( '930000000932', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 10007, 'Internal error to the application.' ) );
website_order( 615, array( 232 => 1 ) );
run_queue();
ebay_has( '930000000932', listing( '930000000932', 3, 2 ) );
$WIRE = array();
run_queue( true );
check( '  eBay sold two meanwhile (3 listed, 2 sold): both come off WooCommerce too, and at 0 the listing is ended', array( calls(), stock( 232 ) ), array( array( 'GetItem', 'EndItem' ), 0 ) );

// eBay could not be asked at all: nothing worked out yet to carry.
fresh_shop();
product( 240, 2, '940000000940' );
ebay_has( '940000000940', refused( 10007, 'Internal error to the application.' ), listing( '940000000940', 2, 0 ) );
website_order( 612, array( 240 => 1 ) );
run_queue();
check( 'eBay out of calls when asked first: nothing sent, tried again with no figure', array( calls(), isset( pushes()[0]['args'][0]['target'] ) ), array( array( 'GetItem' ), false ) );
$WIRE = array();
run_queue( true );
check( '  the next try asks again and sends 2 less the sale', array( calls(), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), 1 ) );

// Not this seller's listing, or not there at all.
fresh_shop();
product( 250, 2, '950000000950' );
website_order( 613, array( 250 => 1 ) );
run_queue();
check( 'a listing eBay cannot find for this seller (17): an error, not tried again', array( calls(), pushes(), logged( 'cannot be found on this eBay account', 'error' ) ), array( array( 'GetItem' ), array(), true ) );

// Ended on eBay before the push: settled from the same answer.
fresh_shop( array( 'remove_sold_products' => '0' ) );
product( 260, 2, '960000000960' );
ebay_has( '960000000960', ended( '960000000960', 3, 3 ) );
website_order( 614, array( 260 => 1 ) );
run_queue();
check( 'the listing had ended on eBay: nothing sent, settled from that one GetItem', array( calls(), meta( 260, '_ebay_listing_status' ), settled_on( 260 ) ), array( array( 'GetItem' ), 'Ended', array( true, '960000000960' ) ) );
check( '  the stock lowered to what eBay left', stock( 260 ), 0 );

// Ended and settled here already - by an EndItem eBay took (3.28.0 writes why).
fresh_shop();
product( 270, 0, '970000000970', array( '_ebay_listing_status' => 'Ended', '_ebay_end_reason' => 'stock_zero', '_ebay_end_reason_item' => '970000000970', '_ebay_stock_settled_at' => '2026-10-20 10:00:00', '_ebay_stock_settled_item' => '970000000970' ) );
hand_edit( 270, 2 );
run_queue();
check( 'restocked after its listing ended and was settled: nothing sent', $WIRE, array() );
check( '  the log says to relist it', logged( 'Relist it to sell it on eBay again' ), true );
// The same marks as 3.27 left them - it wrote them for an EndItem eBay
// refused too, so they are not taken on trust (fix round 1, M5).
fresh_shop();
$marks_327 = array( '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-10-01 10:00:00', '_ebay_stock_settled_item' => '970000000971' );
product( 271, 0, '970000000971', $marks_327 );
ebay_has( '970000000971', listing( '970000000971', 1, 0 ) );
hand_edit( 271, 2 );
run_queue();
check( '  a 3.27 mark, and eBay says the listing is live: the mark is cleared and the restock sent', array( calls(), qty_sent( sent( 'ReviseInventoryStatus' ) ), meta( 271, '_ebay_listing_status' ), settled_on( 271 ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), 2, 'Active', array( false, null ) ) );
fresh_shop( array( 'remove_sold_products' => '0' ) );
product( 272, 0, '970000000972', array( '_ebay_stock_settled_item' => '970000000972' ) + $marks_327 );
ebay_has( '970000000972', ended( '970000000972', 1, 1 ) );
hand_edit( 272, 2 );
run_queue();
check( '  a 3.27 mark, and eBay says it is over: nothing sent, its final figures kept as eBay\'s', array( calls(), meta( 272, '_ebay_listing_status' ), meta( 272, '_ebay_quantity_listed' ) ), array( array( 'GetItem' ), 'Ended', '1' ) );
next_request();
$WIRE = array();
hand_edit( 272, 3 );
run_queue();
check( '  so the next restock trusts it without asking again', array( $WIRE, logged( 'Relist it to sell it on eBay again' ) ), array( array(), true ) );

// Final: pushes switched off since, or the product gone.
fresh_shop();
product( 280, 3, '980000000980' );
hand_edit( 280, 2 );
$OPTIONS['tcgiant_sync_ebay_settings']['push_stock_to_ebay'] = '0';
run_queue();
check( 'pushes switched off after a push was queued: nothing sent, never tried again', array( $WIRE, pushes() ), array( array(), array() ) );
fresh_shop();
product( 281, 3, '980000000981' );
hand_edit( 281, 2 );
$POSTS[281]['status'] = 'trash';
run_queue();
check( 'a product trashed before its push ran: nothing sent, never tried again', array( $WIRE, pushes() ), array( array(), array() ) );

echo "\nA WEBSITE SALE SENDS eBAY ITS OWN FIGURE LESS THE SALE - OR WOOCOMMERCE'S, IF LOWER\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 300, 5, '100000000300' );
ebay_has( '100000000300', listing( '100000000300', 5, 2 ) );
website_order( 620, array( 300 => 1 ) );
check( 'eBay 5 listed, 2 sold; WooCommerce 5; a website order of 1 takes WooCommerce to 4', stock( 300 ), 4 );
$before = count( $WRITES );
run_queue();
check( '  eBay is sent its 3 less the sale: 2', qty_sent( sent( 'ReviseInventoryStatus' ) ), 2 );
check( '  and WooCommerce comes down to 2', stock( 300 ), 2 );
check( '  written with pushes held off', array_column( array_slice( $WRITES, $before ), 3 ), array( true ) );
check( '  so nothing new is queued', pushes(), array() );

fresh_shop();
product( 301, 1, '100000000301' );
ebay_has( '100000000301', listing( '100000000301', 3, 0 ) );
website_order( 621, array( 301 => 1 ) );
run_queue();
check( 'eBay 3, WooCommerce lowered by hand to 1, an order of 1: the lower, 0 - EndItem', calls(), array( 'GetItem', 'EndItem' ) );

fresh_shop();
product( 302, 3, '100000000302' );
ebay_has( '100000000302', listing( '100000000302', 3, 2 ) );
website_order( 622, array( 302 => 1 ) );
run_queue();
check( 'eBay sold 2 of 3 WooCommerce had not heard of, and the website sold 1: ended, and WooCommerce comes down to 0 with it', array( calls(), stock( 302 ), meta( 302, '_stock_status' ) ), array( array( 'GetItem', 'EndItem' ), 0, 'outofstock' ) );
check( '  so an import of the old figures cannot put it back on sale: it is settled', TCGiant_Sync_Inventory::listing_already_settled( 302, '100000000302' ), true );

// What eBay shows below WooCommerce that eBay did not sell is not a sale:
// the sale is not taken off twice (fix round 1, H1). This ended the listing
// and took WooCommerce to 0 while the shop still had 2.
fresh_shop();
product( 303, 3, '100000000303' );
ebay_has( '100000000303', listing( '100000000303', 1, 0 ) );
website_order( 623, array( 303 => 1 ) );
run_queue();
check( 'eBay shows 1 with nothing sold there, the website sells 1 of 3: eBay keeps its 1, WooCommerce its 2', array( calls(), stock( 303 ), meta( 303, '_ebay_listing_status' ) ), array( array( 'GetItem' ), 2, 'Active' ) );
// With eBay's sales on record, only those since are taken off.
fresh_shop();
product( 304, 3, '100000000304' );
seen( 304, '100000000304', 2 );
ebay_has( '100000000304', listing( '100000000304', 5, 2 ) );
website_order( 624, array( 304 => 1 ) );
run_queue();
check( '5 listed, 2 sold that WooCommerce had already taken off, WooCommerce 3 then 2: eBay is sent 2, WooCommerce left at 2', array( qty_sent( sent( 'ReviseInventoryStatus' ) ), stock( 304 ) ), array( 2, 2 ) );
fresh_shop();
product( 305, 3, '100000000305' );
seen( 305, '100000000305', 1 );
ebay_has( '100000000305', listing( '100000000305', 5, 2 ) );
website_order( 625, array( 305 => 1 ) );
run_queue();
check( '  one eBay sale since the record: that one comes off WooCommerce, 2 to 1, and eBay is sent 1', array( qty_sent( sent( 'ReviseInventoryStatus' ) ), stock( 305 ), meta( 305, '_ebay_sold_seen' )['sold'] ?? null ), array( 1, 1, 2 ) );
fresh_shop();
product( 306, 3, '100000000306' );
seen( 306, '199999999999', 0 );
ebay_has( '100000000306', listing( '100000000306', 5, 2 ) );
website_order( 626, array( 306 => 1 ) );
run_queue();
check( '  a record for another listing (before a relist) counts for nothing: eBay\'s 3 is what WooCommerce had before the sale, so none of eBay\'s 2 are new', array( qty_sent( sent( 'ReviseInventoryStatus' ) ), stock( 306 ), meta( 306, '_ebay_sold_seen' ) ), array( 2, 2, array( 'item' => '100000000306', 'sku' => '', 'sold' => 2 ) ) );

echo "\nONE PUSH FOR ONE SALE\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 400, 3, '200000000400' );
$order = website_order( 630, array( 400 => 1 ) );
check( 'block checkout, webhooks, an admin status change: no classic checkout hook fired', did_action( 'woocommerce_checkout_order_processed' ), 0 );
check( '  exactly one push, for the order, with what it took', push_args(), array( array( 400, 'order', 1 ) ) );
do_action( 'woocommerce_reduce_order_stock', $order );
check( 'woocommerce_reduce_order_stock fired again for the order: still one', count( pushes() ), 1 );
check( '  the line is marked as queued, with its push\'s token - not yet as sent', array( (string) $order->get_items()[0]->get_meta( '_tcgiant_ebay_push_queued' ) === ( pushes()[0]['args'][0]['token'] ?? '-' ), (string) $order->get_items()[0]->get_meta( '_tcgiant_ebay_push' ) ), array( true, '' ) );
ebay_has( '200000000400', listing( '200000000400', 3, 0 ) );
run_queue();
check( '  marked as sent once eBay took it', '' !== (string) $order->get_items()[0]->get_meta( '_tcgiant_ebay_push' ), true );
do_action( 'woocommerce_reduce_order_stock', $order );
check( '  and fired again after that: nothing queued', pushes(), array() );
$META[400]['_ebay_push_pending'] = array( 'old' => time() - 1 );
do_action( 'woocommerce_reduce_order_stock', $order );
check( '  nor once its push no longer counts as on its way: the line says it was sent', pushes(), array() );

fresh_shop();
product( 401, 3, '200000000401' );
$REFUSE_REDUCTION = true;
$order = new WC_Order( 631, array( new WC_Order_Item_Product( 6311, 401, 1 ) ) );
wc_reduce_stock_levels( $order );
$REFUSE_REDUCTION = false;
check( 'another plugin stops the reduction: nothing taken, nothing queued', array( stock( 401 ), pushes() ), array( 3, array() ) );
hand_edit( 401, 2 );
check( '  a hand edit in the same request is still pushed', push_args(), array( array( 401, 'edit', null ) ) );

fresh_shop();
product( 402, 5, '200000000402' );
hand_edit( 402, 4 );
hand_edit( 402, 3 );
check( 'two hand edits before the push runs: one push', count( pushes() ), 1 );
run_queue();
check( '  sending what WooCommerce holds when it runs: 3', qty_sent( sent( 'ReviseInventoryStatus' ) ), 3 );
hand_edit( 402, 2 );
check( '  an edit after it ran queues another', count( pushes() ), 1 );

echo "\n\"AUTO-END ... NO\" KEEPS THE LISTING, WITH eBAY'S OUT-OF-STOCK CONTROL\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop( array( 'auto_end_zero_stock' => '0' ) );
product( 500, 1, '300000000500' );
hand_edit( 500, 0 );
run_queue();
check( 'Out-of-Stock control on: ReviseInventoryStatus with Quantity 0, no EndItem', array( calls(), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'ReviseInventoryStatus' ), 0 ) );
check( '  still Active', meta( 500, '_ebay_listing_status' ), 'Active' );

fresh_shop( array( 'auto_end_zero_stock' => '0' ) );
product( 501, 1, '300000000501' );
product( 502, 1, '300000000502' );
ebay_has( '300000000501', listing( '300000000501', 1, 0 ) );
ebay_has( '300000000502', listing( '300000000502', 1, 0 ) );
$invalid = refused( 515, 'Invalid quantity. The quantity must be greater than 0 for an active item.' );
ebay_answers( 'ReviseInventoryStatus', $invalid, $invalid );
hand_edit( 501, 0 );
run_queue();
check( 'without it: 0 refused (515), GetItem shows 1 left, so the listing is ended', calls(), array( 'ReviseInventoryStatus', 'GetItem', 'EndItem' ) );
check( '  one warning, naming Out-of-Stock control', count_logged( 'Turn on Out-of-Stock control' ), 1 );
hand_edit( 502, 0 );
run_queue();
check( '  a second the same day is ended too, with no second warning', array( item_in( all_sent( 'EndItem' )[1] ?? '' ), count_logged( 'Turn on Out-of-Stock control' ) ), array( '300000000502', 1 ) );

echo "\nPAST THE FLEET'S 80% CEILING: ONLY WHAT STOPS AN OVERSELL\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
fleet_used( 0.85 );
product( 600, 1, '400000000600' );
ebay_has( '400000000600', listing( '400000000600', 1, 0 ) );
website_order( 640, array( 600 => 1 ) );
run_queue();
check( 'the fleet at 85% of its day: a website sale still reaches eBay', calls(), array( 'GetItem', 'EndItem' ) );
$WIRE = array();
$API->trading_api_request( 'CompleteSale', '<OrderID>12-34567-89012</OrderID>' );
check( '  CompleteSale goes through too', calls(), array( 'CompleteSale' ) );
$WIRE = array();
$held = $API->get_item( '400000000600' );
check( '  a GetItem outside a push is held back as before', array( calls(), is_wp_error( $held ) ? $held->get_error_data() : null ), array( array(), array( 'daily_budget' => true ) ) );
next_request();
product( 601, 2, '400000000601' );
hand_edit( 601, 3 );
run_queue();
$p        = pushes();
$tomorrow = gmmktime( 0, 0, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ) + 1, (int) gmdate( 'Y' ) );
check( '  a hand edit to 3 sends nothing today', $WIRE, array() );
check( '  it waits for the next UTC day, not counted as a try', array( count( $p ), $p[0]['args'][0]['attempt'] ?? null, ( $p[0]['when'] ?? 0 ) >= $tomorrow && ( $p[0]['when'] ?? 0 ) <= $tomorrow + 1800 ), array( 1, 1, true ) );
$QUEUE = array();
product( 602, 1, '400000000602' );
hand_edit( 602, 0 );
run_queue();
check( '  a hand edit to 0 goes through', calls(), array( 'EndItem' ) );

echo "\nTHE ORDER SCREEN'S PUSH\n" . str_repeat( '=', 132 ) . "\n";

$ADMIN = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();
function order_screen_push( $order_id ) {
	$_POST = array( 'order_id' => $order_id );
	try {
		$GLOBALS['ADMIN']->ajax_sync_order_to_ebay();
	} catch ( Harness_Sent $sent ) {
		return $sent->payload;
	}
	return null;
}

fresh_shop();
product( 800, null, '500000000800' );
product( 801, 2, '500000000801' );
ebay_has( '500000000801', listing( '500000000801', 5, 0 ) );
$ORDERS[650] = new WC_Order( 650, array( new WC_Order_Item_Product( 6501, 800, 1 ), new WC_Order_Item_Product( 6502, 801, 1 ) ) );
$rows = order_screen_push( 650 )['data']['results'] ?? array();
check( 'an untracked line: skipped, saying why', array( $rows[0]['status'] ?? null, $rows[0]['message'] ?? null ), array( 'skipped', 'Stock not tracked in WooCommerce - nothing to send' ) );
check( '  no request names its listing', wire_names( '500000000800' ), false );
check( '  the tracked line: eBay asked, then set to the lower of the two, 2', array( calls(), qty_sent( sent( 'ReviseInventoryStatus' ) ), $rows[1]['status'] ?? null, $rows[1]['message'] ?? null ), array( array( 'GetItem', 'ReviseInventoryStatus' ), 2, 'success', 'eBay stock set to 2' ) );
check( '  nothing queued: it all happened there and then', pushes(), array() );
check( '  the order is marked pushed, nothing having failed', $ORDERS[650]->meta['_tcgiant_sync_pushed'] ?? null, '1' );

fresh_shop();
product( 802, 4, '500000000802' );
ebay_has( '500000000802', listing( '500000000802', 2, 0 ) );
$ORDERS[651] = new WC_Order( 651, array( new WC_Order_Item_Product( 6511, 802, 1 ) ) );
$rows = order_screen_push( 651 )['data']['results'] ?? array();
check( 'eBay already lower than WooCommerce: nothing sent, said', array( calls(), $rows[0]['status'] ?? null, $rows[0]['message'] ?? null ), array( array( 'GetItem' ), 'skipped', 'eBay already shows 2' ) );
check( '  and, the two not agreeing, nothing recorded of eBay\'s sales', meta( 802, '_ebay_sold_seen' ), null );

fresh_shop();
product( 803, 1, '500000000803' );
ebay_has( '500000000803', listing( '500000000803', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 10007, 'Internal error to the application.' ) );
$ORDERS[652] = new WC_Order( 652, array( new WC_Order_Item_Product( 6521, 803, 1 ) ) );
$rows = order_screen_push( 652 )['data']['results'] ?? array();
check( 'eBay out of calls: an error there and then, never queued', array( $rows[0]['status'] ?? null, pushes() ), array( 'error', array() ) );
check( '  so the order is not marked pushed', isset( $ORDERS[652]->meta['_tcgiant_sync_pushed'] ), false );

echo "\nAN AUCTION, THROUGH THE SAME PUSH (ITEM 12'S RULE)\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 900, 1, '600000000900', array( '_ebay_listing_type' => 'Chinese' ) );
ebay_has( '600000000900', listing( '600000000900', 1, 0, array( 'ListingType' => 'Chinese', 'SellingStatus' => array( 'BidCount' => '0' ) ) ) );
website_order( 660, array( 900 => 1 ) );
run_queue();
check( 'an auction sold over the counter, nobody has bid: ended, with why', array( calls(), meta( 900, '_ebay_end_reason' ), settled_on( 900 ) ), array( array( 'GetItem', 'EndItem' ), 'stock_zero', array( true, '600000000900' ) ) );
fresh_shop();
product( 901, 1, '600000000901', array( '_ebay_listing_type' => 'Chinese' ) );
ebay_has( '600000000901', listing( '600000000901', 1, 0, array( 'ListingType' => 'Chinese', 'SellingStatus' => array( 'BidCount' => '2' ) ) ) );
website_order( 661, array( 901 => 1 ) );
run_queue();
check( '  with two bids: left running, and the shop told', array( calls(), meta( 901, '_ebay_listing_status' ), logged( 'has 2 bid(s)', 'error' ), pushes() ), array( array( 'GetItem' ), 'Active', true, array() ) );
fresh_shop();
product( 902, 2, '600000000902', array( '_ebay_listing_type' => 'Chinese' ) );
hand_edit( 902, 1 );
check( '  a stock change above 0: nothing queued, an auction is one item', pushes(), array() );

echo "\nLINKED THROUGH THE PARENT - THE ONE RULE ORDER SYNC AND THE PUSH SHARE (ITEMS 8, E1, 14, 17)\n" . str_repeat( '=', 132 ) . "\n";

// One variation of a linked variable product, met by each path that has to
// decide whether it is on eBay. Item 17 adds its path to this table.
fresh_shop( array( 'enable_order_sync' => '1' ) );
variable( 950, '700000000950' );
variation( 951, 950, 'KIT-RED', 2, array( 'colour' => 'Red' ) );
product( 952, 2, '', array( '_sku' => 'LOOSE-1' ) );
$decided = array();
$decided['the rule itself'] = TCGiant_Sync_Inventory::link_owner( wc_get_product( 951 ) );
// Order sync is asked twice: with the SKU alone, so the link through the
// parent is all it has to go on, and as eBay sends a line, with the item too.
foreach ( array( 'by SKU' => array(), 'as eBay sends it' => array( 'legacyItemId' => '700000000950' ) ) as $how => $extra ) {
	$EBAY['orders'] = array( 'orders' => array( array( 'lineItems' => array(
		array( 'sku' => 'KIT-RED', 'quantity' => 1, 'lineItemId' => 'L-' . $how, 'listingMarketplaceId' => 'EBAY_US' ) + $extra,
	) ) ) );
	$IMP->sync_recent_orders();
	$checks = array_values( array_filter( $QUEUE, function ( $a ) { return 'tcgiant_sync_settle_item' === $a['hook']; } ) );
	$decided[ 'order sync, ' . $how ] = array( $checks[0]['args'] ?? null, stock( 951 ) );
	$QUEUE = array();
}
hand_edit( 951, 1 );
run_queue();
$decided['stock push'] = array( item_in( sent( 'ReviseInventoryStatus' ) ), sku_in( sent( 'ReviseInventoryStatus' ) ) );
check( 'a variation answers through its parent: the rule', $decided['the rule itself'], 950 );
check( '  order sync, by the SKU alone: a stock check of the parent\'s listing, the sale not taken off here', $decided['order sync, by SKU'], array( array( '700000000950' ), 2 ) );
check( '  order sync, as eBay sends the line: the same', $decided['order sync, as eBay sends it'], array( array( '700000000950' ), 2 ) );
check( '  the stock push: the parent\'s listing, the variation\'s SKU', $decided['stock push'], array( '700000000950', 'KIT-RED' ) );
check( '  a product with no listing and no parent answers for itself', TCGiant_Sync_Inventory::link_owner( wc_get_product( 952 ) ), 952 );

echo "\nTHE API'S PIECES\n" . str_repeat( '=', 132 ) . "\n";

$kinds = array(
	'out of calls (518)'          => new WP_Error( 'rate_limited', 'Call usage limit has been reached.', array( 'Errors' => array( 'ErrorCode' => '518' ) ) ),
	'no token'                    => new WP_Error( 'no_token', 'No valid eBay token.' ),
	'a timeout'                   => new WP_Error( 'http_request_failed', 'cURL error 28' ),
	'an unreadable answer'        => new WP_Error( 'invalid_xml', 'Invalid XML from eBay Trading API.' ),
	'nothing from eBay at all'    => new WP_Error( 'api_error', 'eBay API Error 503' ),
	'10007 among several'         => new WP_Error( 'api_error', 'Internal error', array( 'Errors' => array( array( 'ErrorCode' => '21917236' ), array( 'ErrorCode' => '10007' ) ) ) ),
	'a refusal about the listing' => new WP_Error( 'api_error', 'Invalid quantity.', array( 'Errors' => array( 'ErrorCode' => '515' ) ) ),
	'a copy\'s refusal'           => new WP_Error( 'staging_blocked', 'Not sent to eBay: this site looks like a staging or development copy.' ),
	'not an error'                => array( 'Ack' => 'Success' ),
);
$verdicts = array();
foreach ( $kinds as $what => $error ) {
	$verdicts[ $what ] = TCGiant_Sync_API::is_transient_error( $error );
}
check( 'tried again later: out of calls, no token, a timeout, an unreadable or no answer, 10007', $verdicts, array(
	'out of calls (518)'          => true,
	'no token'                    => true,
	'a timeout'                   => true,
	'an unreadable answer'        => true,
	'nothing from eBay at all'    => true,
	'10007 among several'         => true,
	'a refusal about the listing' => false,
	'a copy\'s refusal'           => false,
	'not an error'                => false,
) );
check( 'eBay\'s codes, from one error or several', array( TCGiant_Sync_API::error_codes( $kinds['a refusal about the listing'] ), TCGiant_Sync_API::error_codes( $kinds['10007 among several'] ), TCGiant_Sync_API::error_codes( $kinds['no token'] ) ), array( array( '515' ), array( '21917236', '10007' ), array() ) );

fresh_shop();
$API->revise_inventory_status( '110000000111', -2 );
$API->revise_inventory_status( '110000000111', 4, 'A&B' );
$sent = all_sent( 'ReviseInventoryStatus' );
check( 'ReviseInventoryStatus: below 0 is sent as 0, and no SKU unless one is given', array( qty_sent( $sent[0] ), sku_in( $sent[0] ) ), array( 0, null ) );
check( '  a SKU is escaped', false !== strpos( $sent[1], '<SKU>A&amp;B</SKU>' ), true );
check( '  never EndItem, at any figure', calls(), array( 'ReviseInventoryStatus', 'ReviseInventoryStatus' ) );

// The old call, kept for other code: a variation's listing is its parent's.
fresh_shop();
variable( 790, '777000000790' );
variation( 791, 790, 'PHONE-RED-64', 3 );
$API->update_inventory_item_availability( 'PHONE-RED-64', 2 );
$b = sent( 'ReviseInventoryStatus' );
check( 'the old update_inventory_item_availability() finds a variation through its parent', array( item_in( $b ), sku_in( $b ), qty_sent( $b ) ), array( '777000000790', 'PHONE-RED-64', 2 ) );

echo "\nMORE OF WHAT eBAY'S ANSWER MEANS\n" . str_repeat( '=', 132 ) . "\n";

// Refused, yet the listing shows what was sent: the change is in.
fresh_shop();
product( 320, 5, '100000000320' );
ebay_has( '100000000320', listing( '100000000320', 4, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21919188, 'The request was processed with warnings treated as errors.' ) );
hand_edit( 320, 4 );
run_queue();
check( 'refused, but eBay shows the 4 that was sent: done, not tried again', array( calls(), pushes() ), array( array( 'ReviseInventoryStatus', 'GetItem' ), array() ) );
check( '  eBay showing WooCommerce\'s own figure, its sales are recorded from that answer', meta( 320, '_ebay_sold_seen' ), array( 'item' => '100000000320', 'sku' => '', 'sold' => 0 ) );

// A listing tracked by SKU, sent without one: sent once more with eBay's.
// eBay still does not know it: an error, not tried again.
fresh_shop();
product( 321, 5, '100000000321' );
ebay_has( '100000000321', listing( '100000000321', 5, 0, array( 'SKU' => 'TRK-1', 'InventoryTrackingMethod' => 'SKU' ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21676, 'Invalid SKU number.' ), refused( 21676, 'Invalid SKU number.' ) );
hand_edit( 321, 4 );
run_queue();
check( 'a listing tracked by SKU: sent again once, with the SKU eBay has', array_map( 'sku_in', all_sent( 'ReviseInventoryStatus' ) ), array( null, 'TRK-1' ) );
check( '  eBay still does not know that SKU: an error, not tried again', array( pushes(), logged( 'does not know SKU "TRK-1"', 'error' ) ), array( array(), true ) );

// The Inventory API took it, but the listing does not show it yet.
fresh_shop();
product( 313, 3, '100000000313', array( '_sku' => 'INV-313' ) );
ebay_has( '100000000313', listing( '100000000313', 3, 0, array( 'SKU' => 'INV-313' ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21919474, 'This listing was created with the Inventory API.' ) );
$EBAY['offers']['INV-313']    = array( array( 'sku' => 'INV-313', 'listing' => array( 'listingId' => '100000000313' ) ) );
$EBAY['inventory']['INV-313'] = array( 'sku' => 'INV-313', 'availability' => array( 'shipToLocationAvailability' => array( 'quantity' => 3 ) ) );
hand_edit( 313, 2 );
run_queue();
check( 'the Inventory API took the change but the listing still shows 3: tried again', array( isset( $EBAY['put']['INV-313'] ), count( pushes() ) ), array( true, 1 ) );

// A SKU with a space and an ampersand: its offers are asked for by that SKU.
fresh_shop();
product( 314, 3, '100000000314', array( '_sku' => 'INV 3&14' ) );
ebay_has( '100000000314', listing( '100000000314', 3, 0, array( 'SKU' => 'INV 3&14' ) ), listing( '100000000314', 2, 0, array( 'SKU' => 'INV 3&14' ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21919474, 'This listing was created with the Inventory API.' ) );
$EBAY['offers']['INV 3&14']    = array( array( 'sku' => 'INV 3&14', 'listing' => array( 'listingId' => '100000000314' ) ) );
$EBAY['inventory']['INV 3&14'] = array( 'sku' => 'INV 3&14', 'availability' => array( 'shipToLocationAvailability' => array( 'quantity' => 3 ) ) );
hand_edit( 314, 2 );
run_queue();
check( 'an Inventory API listing whose SKU has a space and an ampersand: its offer is found, and the change sent', array( $EBAY['put']['INV 3&14']['availability']['shipToLocationAvailability']['quantity'] ?? null, pushes() ), array( 2, array() ) );

// WooCommerce fired its "stock reduced" for an order whose line it took
// nothing off: nothing to send.
fresh_shop();
product( 403, 3, '200000000403' );
$order = new WC_Order( 632, array( new WC_Order_Item_Product( 6321, 403, 1 ) ) );
do_action( 'woocommerce_reduce_order_stock', $order );
check( 'an order line WooCommerce took no stock off: nothing queued', pushes(), array() );

echo "\nWHAT A PUSH RECORDS BELONGS TO ITS LISTING\n" . str_repeat( '=', 132 ) . "\n";

check( 'the end reason is forgotten when a new listing starts', array( in_array( '_ebay_end_reason', TCGiant_Sync_Inventory::SETTLE_KEYS, true ), in_array( '_ebay_end_reason_item', TCGiant_Sync_Inventory::SETTLE_KEYS, true ) ), array( true, true ) );
fresh_shop();
product( 990, 0, '800000000990', array( '_ebay_end_reason' => 'stock_zero', '_ebay_end_reason_item' => '800000000990', '_ebay_stock_settled_at' => '2026-10-20 10:00:00', '_ebay_stock_settled_item' => '800000000990' ) );
TCGiant_Sync_Inventory::clear_settled_mark( 990 );
check( '  as the relist and the hourly check forget it', array( meta( 990, '_ebay_end_reason' ), meta( 990, '_ebay_end_reason_item' ) ), array( null, null ) );
check( 'never copied to a duplicate, and gone with an unlink', array_values( array_diff( array( '_ebay_end_reason', '_ebay_end_reason_item', '_ebay_variation_sku', '_ebay_push_pending', '_ebay_sold_seen', '_ebay_listing_type_item', '_ebay_push_running', '_ebay_unsent_qty', '_ebay_own_push_tries' ), TCGiant_Sync_Listing_Link::listing_identity_meta_keys() ) ), array() );

// ─────────────────────────────────────────────────────────────────────────────
// Fix round 1: what two reviews of item 14 found, each as the review proved it.
// ─────────────────────────────────────────────────────────────────────────────

$timeout = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$befores = function () { return array_map( function ( $a ) { return $a['args'][0]['before'] ?? null; }, pushes() ); };
$revised = function () { return array_map( 'qty_sent', all_sent( 'ReviseInventoryStatus' ) ); };
function relisted( $product_id, $old, $new, $ebay_stock ) {
	TCGiant_Sync_Inventory::follow_relist( $product_id, $old, $new, array( 'status' => 'Active', 'listing_type' => 'FixedPriceItem', 'start_time' => '2026-10-24T12:00:00.000Z', 'end_time' => '2026-11-23T12:00:00.000Z' ), 'Delta sync' );
	TCGiant_Sync_Inventory::begin_ebay_origin();
	try {
		wc_update_product_stock( $product_id, $ebay_stock, 'set' );
	} finally {
		TCGiant_Sync_Inventory::end_ebay_origin();
	}
}

echo "\nH1: A SALE IS TAKEN OFF ONCE; ONLY eBAY'S OWN SALES SINCE THE RECORD COME OFF WOOCOMMERCE\n" . str_repeat( '=', 132 ) . "\n";

// S1: two website sales of a sealed box (3) before the queue runs. Each push
// took its sale off eBay's figure, which already had both off: the second
// ended the listing and took WooCommerce to 0 while the shop had 1.
fresh_shop();
product( 1201, 3, '120000001201' );
ebay_has( '120000001201', listing( '120000001201', 3, 0 ), listing( '120000001201', 1, 0 ) );
website_order( 1601, array( 1201 => 1 ) );
website_order( 1602, array( 1201 => 1 ) );
check( 'S1 two sales of a 3 before the queue runs: two pushes, each with the stock before its sale', $befores(), array( 3, 2 ) );
run_queue();
check( '  the first sends WooCommerce\'s 1; the second finds eBay at 1 and sends nothing', array( calls(), $revised() ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), array( 1 ) ) );
check( '  the listing still live, WooCommerce at 1: the shop has 1', array( meta( 1201, '_ebay_listing_status' ), stock( 1201 ) ), array( 'Active', 1 ) );
check( '  eBay\'s sales recorded: none of its own', meta( 1201, '_ebay_sold_seen' ), array( 'item' => '120000001201', 'sku' => '', 'sold' => 0 ) );

// S1b: five in a burst lost four units.
fresh_shop();
product( 1202, 10, '120000001202' );
ebay_has( '120000001202', listing( '120000001202', 10, 0 ), listing( '120000001202', 5, 0 ) );
for ( $i = 0; $i < 5; $i++ ) {
	website_order( 1610 + $i, array( 1202 => 1 ) );
}
run_queue();
check( 'S1b five sales of a 10 in a burst: one revise, to 5, and WooCommerce keeps 5', array( $revised(), stock( 1202 ) ), array( array( 5 ), 5 ) );

// S1c: the control, the queue run between the sales.
fresh_shop();
product( 1203, 3, '120000001203' );
ebay_has( '120000001203', listing( '120000001203', 3, 0 ) );
website_order( 1603, array( 1203 => 1 ) );
run_queue();
ebay_has( '120000001203', listing( '120000001203', 2, 0 ) );
next_request();
website_order( 1604, array( 1203 => 1 ) );
run_queue();
check( 'S1c the same two with the queue run between: 2, then 1', array( $revised(), stock( 1203 ) ), array( array( 2, 1 ), 1 ) );

// S3: a restock queued behind a sale was wiped: 11 became 1.
fresh_shop();
product( 1204, 2, '120000001204' );
ebay_has( '120000001204', listing( '120000001204', 2, 0 ) );
website_order( 1620, array( 1204 => 1 ) );
next_request();
hand_edit( 1204, 11 );
check( 'S3 a sale, then a delivery taking it to 11, before the queue runs: both queued', array_column( push_args(), 1 ), array( 'order', 'edit' ) );
run_queue();
check( '  eBay is sent 11 and WooCommerce keeps 11', array( $revised(), stock( 1204 ) ), array( array( 11 ), 11 ) );

// S3b: the delivery first, then the sale.
fresh_shop();
product( 1205, 2, '120000001205' );
ebay_has( '120000001205', listing( '120000001205', 11, 0 ) );
hand_edit( 1205, 12 );
next_request();
website_order( 1621, array( 1205 => 1 ) );
run_queue();
check( 'S3b a delivery to 12, then a sale, before the queue runs: eBay sent 11 once, WooCommerce keeps 11', array( calls(), $revised(), stock( 1205 ) ), array( array( 'ReviseInventoryStatus', 'GetItem' ), array( 11 ), 11 ) );

// S11: two lines of one product in one order.
fresh_shop();
product( 1206, 3, '120000001206' );
ebay_has( '120000001206', listing( '120000001206', 3, 0 ), listing( '120000001206', 1, 0 ) );
$ORDERS[1622] = new WC_Order( 1622, array( new WC_Order_Item_Product( 16221, 1206, 1 ), new WC_Order_Item_Product( 16222, 1206, 1 ) ) );
wc_reduce_stock_levels( $ORDERS[1622] );
check( 'S11 one order, two lines of the same 3: a push for each, both with the 3 before the order', $befores(), array( 3, 3 ) );
run_queue();
check( '  eBay sent 1 once, the listing still live, WooCommerce 1', array( $revised(), meta( 1206, '_ebay_listing_status' ), stock( 1206 ) ), array( array( 1 ), 'Active', 1 ) );

// S15: the same for a variation.
fresh_shop();
variable( 1210, '121000001210' );
variation( 1211, 1210, 'PH-BLK', 3, array( 'colour' => 'Black' ) );
variation( 1212, 1210, 'PH-WHT', 2, array( 'colour' => 'White' ) );
$phone = function ( $blk ) { return variation_listing( '121000001210', array( ev( 'PH-BLK', $blk, 0, array( 'colour' => 'Black' ) ), ev( 'PH-WHT', 2, 0, array( 'colour' => 'White' ) ) ) ); };
ebay_has( '121000001210', $phone( 3 ), $phone( 1 ) );
website_order( 1623, array( 1211 => 1 ) );
website_order( 1624, array( 1211 => 1 ) );
run_queue();
check( 'S15 two sales of one variation before the queue runs: 1 sent once for it, and it is still offered', array( array_map( 'sku_in', all_sent( 'ReviseInventoryStatus' ) ), $revised(), stock( 1211 ) ), array( array( 'PH-BLK' ), array( 1 ), 1 ) );
check( '  the record is the variation\'s own, by eBay\'s SKU', meta( 1211, '_ebay_sold_seen' ), array( 'item' => '121000001210', 'sku' => 'PH-BLK', 'sold' => 0 ) );

// S13: a sale's revise times out though eBay took it, and eBay sells one
// before the try again.
fresh_shop();
product( 1207, 5, '120000001207' );
ebay_has( '120000001207', listing( '120000001207', 5, 0 ), listing( '120000001207', 4, 1 ) );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
website_order( 1625, array( 1207 => 1 ) );
run_queue();
check( 'S13 a sale\'s revise to 4 times out: tried again, eBay\'s sales recorded before it was sent', array( count( pushes() ), meta( 1207, '_ebay_sold_seen' )['sold'] ?? null ), array( 1, 0 ) );
$WIRE = array();
run_queue( true );
check( '  the try again finds 4 listed, 1 sold: that sale comes off WooCommerce too (3), eBay already shows 3, nothing sent', array( calls(), stock( 1207 ) ), array( array( 'GetItem' ), 3 ) );

// S4: a hand edit's revise times out though eBay took it, and eBay sells
// one. The try again read eBay first and, with nothing on record, could not
// tell that sale from the old figure: it sent 3 back.
fresh_shop();
product( 1208, 5, '120000001208' );
ebay_has( '120000001208', listing( '120000001208', 3, 0 ), listing( '120000001208', 3, 1 ) );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
hand_edit( 1208, 3 );
run_queue();
check( 'S4 a hand edit to 3 times out, nothing on record: eBay is asked there and then, shows the 3, so it is done', array( calls(), pushes(), meta( 1208, '_ebay_sold_seen' ) ), array( array( 'ReviseInventoryStatus', 'ReviseInventoryStatus', 'ReviseInventoryStatus', 'GetItem' ), array(), array( 'item' => '120000001208', 'sku' => '', 'sold' => 0 ) ) );
next_request();
$WIRE = array();
website_order( 1626, array( 1208 => 1 ) );
run_queue();
check( '  eBay then sells one, and so does the website: both come off - WooCommerce 1, eBay sent 1', array( $revised(), stock( 1208 ) ), array( array( 1 ), 1 ) );
fresh_shop();
product( 1209, 5, '120000001209' );
ebay_has( '120000001209', listing( '120000001209', 5, 0 ) );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
hand_edit( 1209, 3 );
run_queue();
check( '  the same, and eBay still shows the old 5: tried again, and nothing recorded', array( count( pushes() ), meta( 1209, '_ebay_sold_seen' ) ), array( 1, null ) );
fresh_shop();
product( 1219, 5, '120000001219' );
ebay_answers( 'ReviseInventoryStatus', refused( 10007, 'Internal error to the application.' ) );
hand_edit( 1219, 3 );
run_queue();
check( '  out of calls (518) says eBay took nothing: not asked', array( calls(), count( pushes() ) ), array( array( 'ReviseInventoryStatus' ), 1 ) );

// The try again carried the target the first try worked out.
fresh_shop();
product( 1213, 3, '120000001213' );
ebay_has( '120000001213', listing( '120000001213', 3, 0 ), listing( '120000001213', 10, 0 ) );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
website_order( 1627, array( 1213 => 1 ) );
run_queue();
$retry = pushes();
$QUEUE = array();
next_request();
hand_edit( 1213, 10 );
run_queue();
$QUEUE = $retry;
$WIRE  = array();
run_queue( true );
check( 'retry_target_after_restock: a sale\'s try again after a delivery to 10 was sent: eBay\'s 10 left, WooCommerce kept at 10', array( calls(), stock( 1213 ) ), array( array( 'GetItem' ), 10 ) );

fresh_shop();
product( 1214, 1, '111000001214' );
ebay_has( '111000001214', listing( '111000001214', 1, 0 ) );
ebay_answers( 'EndItem', $timeout, $timeout, $timeout );
website_order( 1628, array( 1214 => 1 ) );
run_queue();
check( 'retry_target_after_relist_end: a coin\'s EndItem times out - tried again, for the listing it was queued for', array( count( pushes() ), pushes()[0]['args'][0]['item'] ?? null ), array( 1, '111000001214' ) );
relisted( 1214, '111000001214', '222000001214', 3 );
ebay_has( '222000001214', listing( '222000001214', 3, 0 ) );
$WIRE = array();
run_queue( true );
// Since fix round 2 (R2-M5) the sale goes to the relist the product follows
// now, from what that listing shows: here the import brought eBay's 3 in, so
// nothing needs sending. Before, the try again was dropped with no mark.
check( '  relisted with 3, the import followed: the try again reads the relist, and 3 = 3, so nothing is sent - and 111 is not named', array( array_map( 'item_in', all_sent( 'GetItem' ) ), wire_names( '111000001214' ), meta( 1214, '_ebay_item_id' ), meta( 1214, '_ebay_listing_status' ), stock( 1214 ) ), array( array( '222000001214' ), false, '222000001214', 'Active', 3 ) );
check( '  and the relist\'s record of eBay\'s sales starts from it', meta( 1214, '_ebay_sold_seen' ), array( 'item' => '222000001214', 'sku' => '', 'sold' => 0 ) );
check( '  the type eBay gave for the relist is recorded as the relist\'s', meta( 1214, '_ebay_listing_type_item' ), '222000001214' );

fresh_shop();
product( 1215, 3, '111000001215' );
ebay_has( '111000001215', listing( '111000001215', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
website_order( 1629, array( 1215 => 1 ) );
run_queue();
relisted( 1215, '111000001215', '222000001215', 5 );
ebay_has( '222000001215', listing( '222000001215', 5, 0 ) );
$WIRE = array();
run_queue( true );
check( 'retry_target_after_relist_lower: a sale of 1 of 3, relisted with 5 before its try again: the relist read, nothing sent, WooCommerce keeps 5', array( calls(), stock( 1215 ) ), array( array( 'GetItem' ), 5 ) );
// The import kept WooCommerce's figure while the sale was on its way (R2-H2):
// the relist shows more than the shop has, and is brought down to it.
fresh_shop();
product( 1225, 3, '111000001225' );
ebay_has( '111000001225', listing( '111000001225', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
website_order( 1634, array( 1225 => 1 ) );
run_queue();
TCGiant_Sync_Inventory::follow_relist( 1225, '111000001225', '222000001225', array( 'status' => 'Active', 'listing_type' => 'FixedPriceItem', 'start_time' => '2026-10-24T12:00:00.000Z', 'end_time' => '2026-11-23T12:00:00.000Z' ), 'Delta sync' );
check( '  following the relist records the type eBay gave for it, before any push reads it', meta( 1225, '_ebay_listing_type_item' ), '222000001225' );
ebay_has( '222000001225', listing( '222000001225', 3, 0 ) );
$WIRE = array();
run_queue( true );
check( 'R2-M5 a sale whose push had not got there when the relist was followed, WooCommerce 2, the relist 3: 2 sent to the relist', array( calls(), item_in( sent( 'ReviseInventoryStatus' ) ), qty_sent( sent( 'ReviseInventoryStatus' ) ), stock( 1225 ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), '222000001225', 2, 2 ) );
// The relist shows less than WooCommerce: nothing to send, and its record
// starts from what it shows - without one, a later sale would have nothing
// to go on but the stock before it.
fresh_shop();
product( 1227, 6, '111000001227' );
ebay_has( '111000001227', listing( '111000001227', 6, 0 ) );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
website_order( 1636, array( 1227 => 1 ) );
run_queue();
TCGiant_Sync_Inventory::follow_relist( 1227, '111000001227', '222000001227', array( 'status' => 'Active', 'listing_type' => 'FixedPriceItem', 'start_time' => '2026-10-24T12:00:00.000Z', 'end_time' => '2026-11-23T12:00:00.000Z' ), 'Delta sync' );
ebay_has( '222000001227', listing( '222000001227', 3, 0 ) );
$WIRE = array();
run_queue( true );
check( '  WooCommerce 5, the relist 3: nothing sent, and the relist\'s record starts from it', array( calls(), stock( 1227 ), meta( 1227, '_ebay_sold_seen' ) ), array( array( 'GetItem' ), 5, array( 'item' => '222000001227', 'sku' => '', 'sold' => 0 ) ) );
fresh_shop();
product( 1226, 3, '111000001226' );
ebay_has( '111000001226', listing( '111000001226', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
website_order( 1635, array( 1226 => 1 ) );
run_queue();
TCGiant_Sync_Inventory::follow_relist( 1226, '111000001226', '222000001226', array( 'status' => 'Active', 'listing_type' => 'FixedPriceItem', 'start_time' => '2026-10-24T12:00:00.000Z', 'end_time' => '2026-11-23T12:00:00.000Z' ), 'Delta sync' );
ebay_has( '222000001226', $timeout );
run_queue( true, 1 );
check( '  the relist cannot be read: tried again, and the sale still holds imports back', array( count( pushes() ), TCGiant_Sync_Mapper::sale_push_pending( 1226 ) ), array( 1, true ) );
fresh_shop();
product( 1216, 3, '111000001216' );
hand_edit( 1216, 2 );
relisted( 1216, '111000001216', '222000001216', 4 );
run_queue();
check( '  a hand edit queued for 111, the product moved on to 222 before it ran: nothing sent', array( $WIRE, stock( 1216 ) ), array( array(), 4 ) );
check( '  and its pending mark goes at once - the import of 222 is not held back by a push for 111', meta( 1216, '_ebay_push_pending' ), null );

// The record is for one variation of one listing: eBay's SKU changed in
// Seller Hub since, so it says nothing about the one eBay has now.
fresh_shop();
variable( 1217, '121700001217' );
variation( 1218, 1217, 'NEW-SKU', 3, array( 'size' => 'S' ) );
seen( 1218, '121700001217', 0, 'OLD-SKU' );
ebay_has( '121700001217', variation_listing( '121700001217', array( ev( 'NEW-SKU', 5, 2, array( 'size' => 'S' ) ) ) ) );
website_order( 1632, array( 1218 => 1 ) );
run_queue();
check( '  a record under the variation\'s old SKU counts for nothing: eBay\'s 3 is what WooCommerce had, so its 2 sales are old', array( $revised(), stock( 1218 ), meta( 1218, '_ebay_sold_seen' ) ), array( array( 2 ), 2, array( 'item' => '121700001217', 'sku' => 'NEW-SKU', 'sold' => 2 ) ) );

// With a record, a hand edit's timeout is left to the try again, which reads.
fresh_shop();
product( 1223, 5, '122300001223' );
seen( 1223, '122300001223', 0 );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
hand_edit( 1223, 3 );
run_queue();
check( '  a hand edit with eBay\'s sales on record times out: not asked there and then, tried again', array( array_values( array_unique( calls() ) ), count( pushes() ) ), array( array( 'ReviseInventoryStatus' ), 1 ) );

echo "\nH2: A SALE REDUCED AGAIN AFTER ITS STOCK WAS PUT BACK IS PUSHED AGAIN\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 1220, 2, '122000001220' );
ebay_has( '122000001220', listing( '122000001220', 2, 0 ) );
$ORDERS[1630] = new WC_Order( 1630, array( new WC_Order_Item_Product( 16301, 1220, 1 ) ) );
$line = $ORDERS[1630]->get_items()[0];
wc_reduce_stock_levels( $ORDERS[1630] );
run_queue();
check( 'S2 an order on hold takes 1 of 2: pushed, the line marked as sent', array( $revised(), '' !== (string) $line->get_meta( '_tcgiant_ebay_push' ) ), array( array( 1 ), true ) );
next_request();
wc_increase_stock_levels( $ORDERS[1630] );
check( '  cancelled, its stock put back: the line\'s marks go with it', array( (string) $line->get_meta( '_tcgiant_ebay_push' ), (string) $line->get_meta( '_tcgiant_ebay_push_queued' ) ), array( '', '' ) );
check( '  and the 1 put back is pushed', push_args(), array( array( 1220, 'edit', null ) ) );
run_queue();
next_request();
$WIRE = array();
wc_reduce_stock_levels( $ORDERS[1630] );
check( '  paid after all, reduced again: pushed again', push_args(), array( array( 1220, 'order', 1 ) ) );
run_queue();
check( '  eBay is sent the 1 left', array( calls(), $revised(), stock( 1220 ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), array( 1 ), 1 ) );
// A line whose stock was not put back keeps its mark.
fresh_shop();
product( 1221, 3, '122000001221' );
product( 1222, 3, '122000001222' );
$ORDERS[1631] = new WC_Order( 1631, array( new WC_Order_Item_Product( 16311, 1221, 1 ), new WC_Order_Item_Product( 16312, 1222, 1 ) ) );
foreach ( $ORDERS[1631]->get_items() as $item ) {
	$item->meta = array( '_reduced_stock' => 1, '_tcgiant_ebay_push' => '1700000000' );
}
unset( $ORDERS[1631]->get_items()[0]->meta['_reduced_stock'] );
$INV->forget_order_pushes( $ORDERS[1631] );
check( '  only the lines whose stock came back lose their mark', array_map( function ( $i ) { return (string) $i->get_meta( '_tcgiant_ebay_push' ); }, $ORDERS[1631]->get_items() ), array( '', '1700000000' ) );
// A reduction that began and never finished (another plugin threw part way)
// left its products held back from the listeners; the order's stock put
// back lets them go.
fresh_shop();
product( 1224, 3, '122400001224' );
$ORDERS[1633] = new WC_Order( 1633, array( new WC_Order_Item_Product( 16331, 1224, 1 ) ) );
apply_filters( 'woocommerce_can_reduce_order_stock', true, $ORDERS[1633] );
$INV->forget_order_pushes( $ORDERS[1633] );
hand_edit( 1224, 5 );
check( '  a reduction that never finished holds no stock change back once the order\'s stock is put back', push_args(), array( array( 1224, 'edit', null ) ) );

echo "\nM2 - M7\n" . str_repeat( '=', 132 ) . "\n";

// M2: an auction with bids ended because the product did not say auction.
fresh_shop();
product( 1230, 1, '123000001230', array( '_ebay_listing_type' => '', '_ebay_listing_type_item' => null ) );
ebay_has( '123000001230', listing( '123000001230', 1, 0, array( 'ListingType' => 'Chinese', 'SellingStatus' => array( 'BidCount' => '3' ) ) ) );
website_order( 1640, array( 1230 => 1 ) );
run_queue();
check( 'M2 linked by hand, no listing type: the sale\'s GetItem says an auction with 3 bids, so it is not ended', array( calls(), meta( 1230, '_ebay_listing_status' ), logged( 'has 3 bid(s)', 'error' ) ), array( array( 'GetItem' ), 'Active', true ) );
check( '  eBay\'s type kept, for this listing', array( meta( 1230, '_ebay_listing_type' ), meta( 1230, '_ebay_listing_type_item' ) ), array( 'Chinese', '123000001230' ) );
// A shop with an auction among its products (fix round 2 keeps the read for
// those only, R2-M1).
fresh_shop();
product( 1299, 1, '129900001299', array( '_ebay_listing_type' => 'Chinese' ) );
product( 1231, 1, '123000001231', array( '_ebay_listing_type_item' => null ) );
ebay_has( '123000001231', listing( '123000001231', 1, 0, array( 'ListingType' => 'Chinese', 'SellingStatus' => array( 'BidCount' => '2' ) ) ) );
hand_edit( 1231, 0 );
run_queue();
check( '  a shop with an auction: a hand edit to 0, fixed-price only by the 3.x backfill\'s guess - eBay read first, 2 bids, not ended', array( calls(), meta( 1231, '_ebay_listing_status' ) ), array( array( 'GetItem' ), 'Active' ) );
fresh_shop();
product( 1299, 1, '129900001299', array( '_ebay_listing_type' => 'Chinese' ) );
product( 1232, 1, '123000001232', array( '_ebay_listing_type_item' => '199999999999' ) );
ebay_has( '123000001232', listing( '123000001232', 1, 0 ) );
hand_edit( 1232, 0 );
run_queue();
check( '  fixed-price as eBay said for another listing: read first too, then ended', calls(), array( 'GetItem', 'EndItem' ) );

// R2-M1: a shop with no auction, and "Import eBay auctions" off. Reading
// before each EndItem doubled a zero CSV's calls - all past the ceiling.
fresh_shop();
for ( $i = 0; $i < 20; $i++ ) {
	product( 1500 + $i, 3, (string) ( 150000001500 + $i ), array( '_ebay_listing_type_item' => null ) );
}
for ( $i = 0; $i < 20; $i++ ) {
	hand_edit( 1500 + $i, 0 );
}
run_queue( true, 100 );
check( 'R2-M1 a shop with no auction, 20 products fixed-price by the backfill set to 0: 20 EndItems, no reads', array( count( calls() ), array_values( array_unique( calls() ) ) ), array( 20, array( 'EndItem' ) ) );
check( '  the check of the shop asked the database once, and is kept for a day', get_transient( TCGiant_Sync_Inventory::AUCTIONS_TRANSIENT ), 'no' );
fresh_shop( array( 'import_auctions' => '1' ) );
product( 1520, 3, '152000001520', array( '_ebay_listing_type_item' => null ) );
ebay_has( '152000001520', listing( '152000001520', 3, 0 ) );
hand_edit( 1520, 0 );
run_queue();
check( '  "Import eBay auctions" on: read first', calls(), array( 'GetItem', 'EndItem' ) );
fresh_shop();
product( 1521, 3, '152100001521', array( '_ebay_listing_type' => '', '_ebay_listing_type_item' => null ) );
ebay_has( '152100001521', listing( '152100001521', 3, 0 ) );
hand_edit( 1521, 0 );
run_queue();
check( '  no type at all, linked by hand: read first, auctions or not', calls(), array( 'GetItem', 'EndItem' ) );
fresh_shop();
set_transient( TCGiant_Sync_Inventory::AUCTIONS_TRANSIENT, 'no', DAY_IN_SECONDS );
product( 1522, 1, '152200001522', array( '_ebay_listing_type' => '', '_ebay_listing_type_item' => null ) );
ebay_has( '152200001522', listing( '152200001522', 1, 0, array( 'ListingType' => 'Chinese', 'SellingStatus' => array( 'BidCount' => '0' ) ) ) );
website_order( 1660, array( 1522 => 1 ) );
run_queue();
check( '  an auction read for the first time marks the shop as having one at once', get_transient( TCGiant_Sync_Inventory::AUCTIONS_TRANSIENT ), 'yes' );
// (a) Types eBay gave in answers the plugin already holds, kept for the
// listing: the hourly check, a settle, and eBay taking a quantity.
fresh_shop();
product( 1523, 3, '152300001523', array( '_ebay_listing_type_item' => null ) );
ebay_has( '152300001523', listing( '152300001523', 3, 1 ) );
$IMP->settle_item( '152300001523' );
check( '  (a) order sync\'s settle keeps the type from its GetItem, for the listing', meta( 1523, '_ebay_listing_type_item' ), '152300001523' );
fresh_shop();
product( 1524, 3, '152400001524', array( '_ebay_listing_type_item' => null ) );
hand_edit( 1524, 2 );
run_queue();
check( '  (a) eBay took a quantity: the listing is fixed-price, recorded for it', meta( 1524, '_ebay_listing_type_item' ), '152400001524' );
fresh_shop();
product( 1525, 3, '152500001525', array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_type_item' => null ) );
TCGiant_Sync_Inventory::note_listing_type( 1525, '199999999999', 'FixedPriceItem' );
check( '  (a) never from an answer about another listing', array( meta( 1525, '_ebay_listing_type' ), meta( 1525, '_ebay_listing_type_item' ) ), array( 'Chinese', null ) );
fresh_shop();
product( 1233, 1, '123000001233' );
hand_edit( 1233, 0 );
run_queue();
check( '  fixed-price as eBay said for this listing: EndItem, no read', calls(), array( 'EndItem' ) );
fresh_shop();
product( 1234, 1, '123000001234', array( '_ebay_listing_type' => 'LeadGeneration' ) );
ebay_has( '123000001234', listing( '123000001234', 1, 0 ) );
hand_edit( 1234, 0 );
run_queue();
check( '  a type eBay gave for this listing that is not fixed-price: read first', calls(), array( 'GetItem', 'EndItem' ) );
// A raised quantity for a product not known to be on an auction: eBay
// refuses it, and its GetItem says auction - the auction's rule, not ten
// hours of tries.
fresh_shop();
product( 1235, 1, '123000001235', array( '_ebay_listing_type' => '', '_ebay_listing_type_item' => null ) );
ebay_has( '123000001235', listing( '123000001235', 1, 0, array( 'ListingType' => 'Chinese', 'SellingStatus' => array( 'BidCount' => '1' ) ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21919999, 'This call is not for auctions.' ) );
hand_edit( 1235, 2 );
run_queue();
check( '  a hand edit to 2 refused, and eBay says auction: nothing more sent, not tried again', array( calls(), pushes(), logged( 'An auction is always one item' ) ), array( array( 'ReviseInventoryStatus', 'GetItem' ), array(), true ) );

// M3: the last variation with stock on eBay, while another has stock here.
fresh_shop();
variable( 1240, '124000001240' );
variation( 1241, 1240, 'CARD-A', 1, array( 'name' => 'A' ) );
variation( 1242, 1240, 'CARD-B', 0, array( 'name' => 'B' ) );
ebay_has( '124000001240', variation_listing( '124000001240', array( ev( 'CARD-A', 1, 0, array( 'name' => 'A' ) ), ev( 'CARD-B', 1, 1, array( 'name' => 'B' ) ) ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
hand_edit( 1241, 0 );
hand_edit( 1242, 4 );
run_queue( true, 1 );
check( 'M3 A to 0 is refused - the last with stock on eBay - but B has 4 here, its push on its way: not ended, tried again', array( calls(), in_array( 'EndItem', calls(), true ), count( pushes() ) ), array( array( 'ReviseInventoryStatus', 'GetItem' ), false, 2 ) );
fresh_shop();
variable( 1245, '124500001245' );
variation( 1246, 1245, 'CARD-A', 1, array( 'name' => 'A' ) );
variation( 1247, 1245, 'CARD-B', 0, array( 'name' => 'B' ) );
$META[1247]['_ebay_push_pending'] = array( 'elsewhere' => time() + 600 );
ebay_has( '124500001245', variation_listing( '124500001245', array( ev( 'CARD-A', 1, 0, array( 'name' => 'A' ) ), ev( 'CARD-B', 1, 1, array( 'name' => 'B' ) ) ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
hand_edit( 1246, 0 );
run_queue();
check( '  B at 0 here, but a change of it on its way: not ended either', array( in_array( 'EndItem', calls(), true ), count( pushes() ) ), array( false, 1 ) );
fresh_shop();
variable( 1248, '124800001248' );
variation( 1249, 1248, 'CARD-A', 1, array( 'name' => 'A' ) );
variation( 1255, 1248, 'CARD-B', 3, array( 'name' => 'B' ) );
ebay_has( '124800001248', variation_listing( '124800001248', array( ev( 'CARD-A', 1, 0, array( 'name' => 'A' ) ), ev( 'CARD-B', 1, 1, array( 'name' => 'B' ) ) ) ) );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
hand_edit( 1249, 0 );
run_queue();
check( '  B has 3 here with nothing on its way (eBay is behind): not ended either, tried again', array( in_array( 'EndItem', calls(), true ), count( pushes() ) ), array( false, 1 ) );

// M4: a sale whose push gave up let the import put the unit back 15 minutes later.
fresh_shop();
product( 1250, 1, '125000001250' );
ebay_has( '125000001250', listing( '125000001250', 1, 0 ) );
$r = array();
for ( $i = 0; $i < 12; $i++ ) {
	$r[] = refused( 10039, 'Unexpected error.' );
}
ebay_answers( 'EndItem', ...$r );
website_order( 1650, array( 1250 => 1 ) );
run_queue( true );
$pending = (array) meta( 1250, '_ebay_push_pending' );
check( 'M4 a sale\'s push given up: it holds back an import for 30 days, not 15 minutes', array( pushes(), TCGiant_Sync_Mapper::push_pending( 1250 ), isset( $pending['unsent'] ) && $pending['unsent'] - time() > 29 * DAY_IN_SECONDS ), array( array(), true, true ) );
next_request();
hand_edit( 1250, 2 );
run_queue();
check( '  a later push that gets there lets it go', array_key_exists( 'unsent', (array) meta( 1250, '_ebay_push_pending' ) ), false );
fresh_shop( array( 'remove_sold_products' => '0' ) );
product( 1251, 0, '125000001251' );
$META[1251]['_ebay_push_pending'] = array( 'unsent' => time() + 30 * DAY_IN_SECONDS );
TCGiant_Sync_Importer::settle_ended_from_item( 1251, ended( '125000001251', 1, 1 ), 'Delta sync' );
check( '  so does the listing being settled as over', meta( 1251, '_ebay_push_pending' ), null );
fresh_shop();
variable( 1253, '125300001253' );
variation( 1254, 1253, 'MAT-A', 2, array( 'size' => 'S' ) );
$META[1254]['_ebay_push_pending'] = array( 'unsent' => time() + 30 * DAY_IN_SECONDS );
$over = variation_listing( '125300001253', array( ev( 'MAT-A', 2, 1, array( 'size' => 'S' ) ) ) );
$over['SellingStatus']['ListingStatus'] = 'Completed';
ebay_has( '125300001253', $over );
website_order( 1652, array( 1254 => 1 ) );
run_queue();
check( '  and for a variation, a push that finds its listing over', array_key_exists( 'unsent', (array) meta( 1254, '_ebay_push_pending' ) ), false );
fresh_shop();
product( 1252, 2, '125000001252' );
$no_qty = listing( '125000001252', 2, 0 );
unset( $no_qty['Quantity'] );
ebay_has( '125000001252', $no_qty );
website_order( 1651, array( 1252 => 1 ) );
run_queue( true );
check( '  eBay never says how many: five tries ask, and the last sends WooCommerce\'s 1', array( count( all_sent( 'GetItem' ) ), $revised(), pushes() ), array( 6, array( 1 ), array() ) );

// M6: the order screen's push with the switch off said "sent" and sent nothing.
fresh_shop( array( 'push_stock_to_ebay' => '0' ) );
product( 1260, 2, '126000001260' );
ebay_has( '126000001260', listing( '126000001260', 3, 0 ) );
$ORDERS[1660] = new WC_Order( 1660, array( new WC_Order_Item_Product( 16601, 1260, 1 ) ) );
$rows = order_screen_push( 1660 )['data']['results'] ?? array();
check( 'M6 the order screen\'s push, "Send stock changes" off: sent all the same - it was asked for by hand', array( calls(), qty_sent( sent( 'ReviseInventoryStatus' ) ), $rows[0]['status'] ?? null, $ORDERS[1660]->meta['_tcgiant_sync_pushed'] ?? null ), array( array( 'GetItem', 'ReviseInventoryStatus' ), 2, 'success', '1' ) );
fresh_shop( array( 'push_stock_to_ebay' => '0' ) );
product( 1261, 2, '126000001261' );
$INV->process_stock_push( array( 'product_id' => 1261, 'reason' => 'edit' ) );
check( '  a queued push with it off still sends nothing', $WIRE, array() );

// M7: a push the host stops.
fresh_shop();
product( 1270, 1, '127000001270' );
ebay_has( '127000001270', listing( '127000001270', 1, 0 ) );
$ORDERS[1670] = website_order( 1670, array( 1270 => 1 ) );
$KILL_ON      = 'EndItem';
$thrown       = null;
try {
	run_queue();
} catch ( Error $e ) {
	$thrown = $e->getMessage();
}
$p = pushes();
check( 'M7 a push stopped by an error as EndItem goes out: tried again in 5 minutes, and the error not swallowed', array( $thrown, count( $p ), $p[0]['args'][0]['attempt'] ?? null, isset( $p[0] ) && in_about( $p[0], 300 ) ), array( 'Maximum execution time of 120 seconds exceeded', 1, 2, true ) );
check( '  said as an error, the line not marked as sent, the listing still recorded live', array( logged( 'was stopped before it finished', 'error' ), (string) $ORDERS[1670]->get_items()[0]->get_meta( '_tcgiant_ebay_push' ), meta( 1270, '_ebay_listing_status' ) ), array( true, '', 'Active' ) );
$WIRE = array();
run_queue( true, 1 );
check( '  that try again runs when it comes round, as try 2 - not held back as a try still running: read, and ended', array( calls(), meta( 1270, '_ebay_listing_status' ) ), array( array( 'GetItem', 'EndItem' ), 'Ended' ) );
fresh_shop();
product( 1271, 3, '127000001271' );
ebay_has( '127000001271', listing( '127000001271', 3, 0 ) );
website_order( 1671, array( 1271 => 1 ) );
$DIED                              = null;
$ON_CALL['ReviseInventoryStatus'] = function () {
	// PHP is stopped here - a time limit - and its shutdown runs the watch.
	TCGiant_Sync_Runtime::on_shutdown();
	$GLOBALS['DIED'] = array( pushes(), logged( 'was stopped before it finished', 'error' ) );
};
run_queue();
check( '  killed outright: the watch\'s shutdown queues the try again, and says so', array( count( $DIED[0] ?? array() ), $DIED[0][0]['args'][0]['attempt'] ?? null, $DIED[1] ?? null ), array( 1, 2, true ) );
fresh_shop();
product( 1272, 3, '127200001272' );
hand_edit( 1272, 2 );
run_queue();
TCGiant_Sync_Runtime::on_shutdown();
check( '  a push that finished is watched no longer: the request\'s end queues nothing and says nothing', array( calls(), pushes(), logged( 'was stopped before it finished' ) ), array( array( 'ReviseInventoryStatus' ), array(), false ) );

echo "\nL1 - L8, AND get_offers()\n" . str_repeat( '=', 132 ) . "\n";

// L1: the check after a refused raised edit went past the 80% ceiling.
fresh_shop();
fleet_used( 0.79 );
product( 1280, 3, '128000001280' );
ebay_has( '128000001280', listing( '128000001280', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21919999, 'Something eBay has not said before.' ) );
$ON_CALL['ReviseInventoryStatus'] = function () { fleet_used( 0.85 ); };
hand_edit( 1280, 5 );
run_queue();
check( 'L1 a raised hand edit refused as the fleet passes 80%: its check waits for tomorrow, like the change', array( calls(), pushes()[0]['args'][0]['tomorrow'] ?? null ), array( array( 'ReviseInventoryStatus' ), 1 ) );
fresh_shop();
fleet_used( 0.85 );
product( 1281, 3, '128000001281' );
ebay_has( '128000001281', listing( '128000001281', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 21919999, 'Something eBay has not said before.' ) );
website_order( 1680, array( 1281 => 1 ) );
run_queue();
check( '  a sale\'s check goes past it', array_slice( calls(), 0, 3 ), array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ) );

// L2: a refusal for good cost three calls a try for ten hours. Since fix
// round 2 (R2-L1) only a raised or lowered quantity from a hand edit or an
// import is given up after two identical refusals; a sale, a push by hand
// and a change to 0 keep decision 9's six tries, at two calls each after the
// first, which also checks the listing after the refusal.
fresh_shop();
product( 1290, 3, '129000001290' );
ebay_has( '129000001290', listing( '129000001290', 3, 0 ) );
$r = array();
for ( $i = 0; $i < 12; $i++ ) {
	$r[] = refused( 21919999, 'Something about the listing.' );
}
ebay_answers( 'ReviseInventoryStatus', ...$r );
hand_edit( 1290, 5 );
run_queue( true );
check( 'L2 a hand edit to 5 refused the same way twice, the listing unchanged: given up after two tries and 4 calls, listed, an error', array( calls(), pushes(), count( get_option( TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION, array() ) ), logged( 'refused it the same way twice', 'error' ) ), array( array( 'ReviseInventoryStatus', 'GetItem', 'GetItem', 'ReviseInventoryStatus' ), array(), 1, true ) );
fresh_shop();
product( 1291, 3, '129000001291' );
ebay_has( '129000001291', listing( '129000001291', 3, 0 ), listing( '129000001291', 3, 1 ) );
ebay_answers( 'ReviseInventoryStatus', ...$r );
hand_edit( 1291, 5 );
run_queue( true, 2 );
check( '  the listing changed between the two (eBay sold one): not the same refusal, tried a third time', array( count( all_sent( 'ReviseInventoryStatus' ) ), count( pushes() ) ), array( 2, 1 ) );
fresh_shop();
product( 1294, 3, '129400001294' );
ebay_has( '129400001294', listing( '129400001294', 3, 0 ), listing( '129400001294', 4, 0 ), listing( '129400001294', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', ...$r );
hand_edit( 1294, 5 );
run_queue( true );
check( '  the second try reads 4 first, but the check after the refusal finds 3 again, as the first refusal did: the same refusal, given up', array( calls(), pushes(), logged( 'refused it the same way twice', 'error' ) ), array( array( 'ReviseInventoryStatus', 'GetItem', 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), array(), true ) );
fresh_shop();
product( 1292, 3, '129200001292' );
ebay_has( '129200001292', listing( '129200001292', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', ...$r );
website_order( 1692, array( 1292 => 1 ) );
run_queue( true );
$names = array_count_values( calls() );
check( 'R2-L1 a sale refused the same way every time: all six tries, 13 calls (3, then 2 a try), then listed and an error', array( $names['GetItem'] ?? 0, $names['ReviseInventoryStatus'] ?? 0, pushes(), count( get_option( TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION, array() ) ), logged( 'gave up after 6 tries', 'error' ) ), array( 7, 6, array(), 1, true ) );
fresh_shop();
product( 1293, 1, '129300001293' );
ebay_has( '129300001293', listing( '129300001293', 1, 0 ) );
ebay_answers( 'EndItem', ...$r );
hand_edit( 1293, 0 );
run_queue( true );
$names = array_count_values( calls() );
check( '  a change to 0 the same: six tries, 12 calls', array( $names['EndItem'] ?? 0, $names['GetItem'] ?? 0, count( get_option( TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION, array() ) ) ), array( 6, 6, 1 ) );

// L3: pushes held for tomorrow piled up, one per edit.
fresh_shop();
fleet_used( 0.85 );
product( 1300, 9, '130000001300' );
foreach ( array( 8, 7, 6 ) as $q ) {
	next_request();
	hand_edit( 1300, $q );
	run_queue();
}
$waiting = array_values( array_filter( pushes(), function ( $a ) { return $a['when'] > time() + 60; } ) );
check( 'L3 three hand edits of one product past the ceiling: one push waits for tomorrow, not three', array( count( $waiting ), count( pushes() ), $WIRE ), array( 1, 1, array() ) );
fleet_used( 0.10 );
run_queue( true );
check( '  tomorrow it sends what WooCommerce holds then, and the next one held may wait again', array( $revised(), get_transient( 'tcgiant_push_tomorrow_1300' ) ), array( array( 6 ), false ) );

// L5: a local Ended that eBay says is live.
fresh_shop();
product( 1310, 3, '131000001310', array( '_ebay_listing_status' => 'Ended' ) );
ebay_has( '131000001310', listing( '131000001310', 3, 0 ) );
website_order( 1700, array( 1310 => 1 ) );
run_queue();
check( 'L5 recorded here as Ended (not settled), eBay says live: recorded live again, and the sale sent', array( calls(), meta( 1310, '_ebay_listing_status' ), $revised() ), array( array( 'GetItem', 'ReviseInventoryStatus' ), 'Active', array( 2 ) ) );

// L6: an old push's SKU, one product's eBay SKU and another's WooCommerce SKU.
fresh_shop();
product( 1320, 3, '132000001320', array( '_sku' => 'A-OWN', '_ebay_sku' => 'X-1' ) );
product( 1321, 0, '132100001321', array( '_sku' => 'X-1' ) );
$INV->process_ebay_stock_update( 'X-1', 0 );
check( 'L6 an old push for a SKU that is one product\'s eBay SKU and another\'s WooCommerce SKU: sent to neither, both named', array( $WIRE, logged( 'matches WC #1320 and #1321', 'warning' ) ), array( array(), true ) );
fresh_shop();
product( 1322, 3, '132200001322', array( '_sku' => 'EBAY-132200001322' ) );
$INV->process_ebay_stock_update( 'EBAY-132200001322', 0 );
check( '  one product found by its SKU and by its listing is one product: sent', array( calls(), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'ReviseInventoryStatus' ), 3 ) );

// L7: one order's reduction stopped every stock change for the rest of the request.
fresh_shop();
product( 1330, 3, '133000001330' );
product( 1331, 4, '133100001331' );
$DID['woocommerce_checkout_order_processed'] = 1;
website_order( 1710, array( 1330 => 1 ) );
hand_edit( 1331, 1 );
check( 'L7 an order\'s stock taken at checkout, then another product lowered in the same request: both pushed', push_args(), array( array( 1330, 'order', 1 ), array( 1331, 'edit', null ) ) );

// L8: EndItem's answer lost on the way.
fresh_shop( array( 'remove_sold_products' => '0' ) );
product( 1340, 1, '134000001340' );
ebay_has( '134000001340', listing( '134000001340', 1, 0 ), ended( '134000001340', 1, 0 ) );
ebay_answers( 'EndItem', $timeout, $timeout, $timeout );
website_order( 1720, array( 1340 => 1 ) );
run_queue();
$WIRE = array();
run_queue( true );
check( 'L8 EndItem got there but its answer was lost: the try again finds it over, settles it, and records why', array( calls(), meta( 1340, '_ebay_listing_status' ), meta( 1340, '_ebay_end_reason' ), meta( 1340, '_ebay_end_reason_item' ) ), array( array( 'GetItem' ), 'Ended', 'stock_zero', '134000001340' ) );

// get_offers(): add_query_arg() encodes nothing.
fresh_shop();
$EBAY['offers']['A&B 1/2'] = array( array( 'offerId' => '77', 'sku' => 'A&B 1/2' ) );
$got = $API->get_offers( 'A&B 1/2' );
check( 'get_offers() asks for the SKU it is given - an ampersand, a space and a slash in it', array( is_array( $got ) ? ( $got['offers'][0]['offerId'] ?? null ) : null, false !== strpos( $WIRE_URLS[0] ?? '', 'sku=A%26B%201%2F2' ) ), array( '77', true ) );

echo "\nTHE RECORD OF eBAY'S SALES, KEPT BY THE IMPORT AND THE SETTLES\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 1350, 5, '135000001350' );
seen( 1350, '135000001350', 1 );
ebay_has( '135000001350', listing( '135000001350', 5, 3 ) );
$IMP->settle_item( '135000001350' );
check( 'order sync brings two eBay sales in (5 to 2): the record moves on by those two, so no push takes them off again', array( stock( 1350 ), meta( 1350, '_ebay_sold_seen' )['sold'] ?? null ), array( 2, 3 ) );
fresh_shop();
product( 1351, 5, '135100001351' );
ebay_has( '135100001351', listing( '135100001351', 5, 3 ) );
$IMP->settle_item( '135100001351' );
check( '  with nothing on record, a settle records nothing: what it lowered does not say which sales it was', array( stock( 1351 ), meta( 1351, '_ebay_sold_seen' ) ), array( 2, null ) );
fresh_shop();
variable( 1360, '136000001360' );
variation( 1361, 1360, 'MUG-A', 4, array( 'Colour' => 'A' ) );
seen( 1361, '136000001360', 1, 'MUG-A' );
TCGiant_Sync_Inventory::settle_variations( 1360, variation_listing( '136000001360', array( ev( 'MUG-A', 5, 3, array( 'Colour' => 'A' ) ) ) ), 'Sale check' );
check( '  a variation\'s settle, 4 to 2, moves its record on the same way', array( stock( 1361 ), meta( 1361, '_ebay_sold_seen' )['sold'] ?? null ), array( 2, 3 ) );
fresh_shop();
variable( 1355, '135500001355' );
$var = import_variations( 1355, array(
	array( 'sku' => 'CUP-RED', 'stock_quantity' => 3, 'sold' => 2, 'attributes' => array( 'Colour' => 'Red' ) ),
	array( 'sku' => '', 'stock_quantity' => 1, 'sold' => 0, 'attributes' => array( 'Colour' => 'Green' ) ),
) );
check( 'an import writes eBay\'s figure for a variation, and records eBay\'s sales of it, by its SKU', meta( (int) ( $var[0]['variation_id'] ?? 0 ), '_ebay_sold_seen' ), array( 'item' => '135500001355', 'sku' => 'CUP-RED', 'sold' => 2 ) );
check( '  one eBay has no SKU for records nothing', meta( (int) ( $var[1]['variation_id'] ?? 0 ), '_ebay_sold_seen' ), null );
$red_cup = (int) ( $var[0]['variation_id'] ?? 0 );
hand_edit( $red_cup, 1 );
import_variations( 1355, array( array( 'sku' => 'CUP-RED', 'stock_quantity' => 3, 'sold' => 4, 'attributes' => array( 'Colour' => 'Red' ) ) ) );
check( '  one whose lower stock it keeps, a push on its way, keeps its record too', array( stock( $red_cup ), meta( $red_cup, '_ebay_sold_seen' )['sold'] ?? null ), array( 1, 2 ) );
$mapped = $MAPPER->map_ebay_to_woo( variation_listing( '135600001356', array( ev( 'JUG-A', 5, 2, array( 'Colour' => 'A' ) ), ev( 'JUG-B', 3, 0, array( 'Colour' => 'B' ) ) ) ) + array( 'Title' => 'Jug' ) );
check( '  each variation\'s sales are read from eBay\'s answer for it', array_column( (array) ( $mapped['variations'] ?? array() ), 'sold' ), array( 2, 0 ) );

// ─────────────────────────────────────────────────────────────────────────────
// Fix round 2: what the re-verification of 71dc87b found.
// ─────────────────────────────────────────────────────────────────────────────

echo "\nR2-H1: STOCK ON THE PARENT NEVER OVERWRITES A VARIATION THAT KEEPS ITS OWN\n" . str_repeat( '=', 132 ) . "\n";

// S and M take the parent's 10; L keeps its own 2.
$mixed = function ( $s, $m, $l ) { return variation_listing( '840000000840', array( ev( 'MX-S', $s, 0, array( 'size' => 'S' ) ), ev( 'MX-M', $m, 0, array( 'size' => 'M' ) ), ev( 'MX-L', $l, 0, array( 'size' => 'L' ) ) ) ); };
$mixed_shop = function () {
	fresh_shop();
	variable( 1600, '840000000840', array( '_manage_stock' => 'yes', '_stock' => 10 ) );
	variation( 1601, 1600, 'MX-S', null, array( 'size' => 'S' ) );
	variation( 1602, 1600, 'MX-M', null, array( 'size' => 'M' ) );
	variation( 1603, 1600, 'MX-L', 2, array( 'size' => 'L' ) );
};
$mixed_shop();
ebay_has( '840000000840', $mixed( 10, 10, 2 ), $mixed( 9, 9, 2 ) );
hand_edit( 1600, 9 );
run_queue();
check( 'R2-H1 S and M share the parent\'s 10, L keeps its own 2; the parent to 9: 9 sent to S and M only, L left alone', array( calls(), statuses( sent( 'ReviseInventoryStatus' ) ), stock( 1603 ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), array( 'MX-S' => 9, 'MX-M' => 9 ), 2 ) );
$mixed_shop();
ebay_has( '840000000840', $mixed( 10, 10, 2 ), $mixed( 0, 0, 2 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
hand_edit( 1600, 0 );
run_queue();
check( '  the shared stock runs out: 0 sent to S and M only; L still has 2 on eBay, so the listing is not ended', array( calls(), statuses( sent( 'ReviseInventoryStatus' ) ), meta( 1600, '_ebay_listing_status' ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), array( 'MX-S' => 0, 'MX-M' => 0 ), 'Active' ) );
// eBay shows L at 0 (behind), WooCommerce has 2 of it: still not ended.
$mixed_shop();
ebay_has( '840000000840', $mixed( 10, 10, 0 ), $mixed( 0, 10, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
hand_edit( 1600, 0 );
run_queue();
check( '  eBay keeps M, the last with stock there, but L has 2 here: not ended, tried again', array( in_array( 'EndItem', calls(), true ), count( pushes() ), meta( 1600, '_ebay_listing_status' ) ), array( false, 1, 'Active' ) );
$mixed_shop();
$META[1603]['_stock'] = 0;
ebay_has( '840000000840', $mixed( 10, 10, 0 ), $mixed( 0, 10, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
hand_edit( 1600, 0 );
run_queue();
check( '  and with L at 0 here too: ended', array( item_in( sent( 'EndItem' ) ), meta( 1600, '_ebay_listing_status' ) ), array( '840000000840', 'Ended' ) );
// L's own change goes by L's own push, as before.
$mixed_shop();
ebay_has( '840000000840', $mixed( 10, 10, 2 ) );
hand_edit( 1603, 1 );
run_queue();
check( '  L edited to 1: its own push, for its SKU alone', array( push_args(), statuses( sent( 'ReviseInventoryStatus' ) ) ), array( array(), array( 'MX-L' => 1 ) ) );

echo "\nR2-H2: AN IMPORT BETWEEN A WEBSITE SALE AND ITS PUSH LEAVES THE SALE ALONE\n" . str_repeat( '=', 132 ) . "\n";

// L8: a variation, eBay sells one, the website sells one, then the import.
fresh_shop();
variable( 1610, '161000001610' );
$mug = function ( $listed_r, $sold_r ) { return array( array( 'sku' => 'MG-R', 'stock_quantity' => $listed_r - $sold_r, 'sold' => $sold_r, 'attributes' => array( 'colour' => 'Red' ) ) ); };
$var = import_variations( 1610, $mug( 5, 0 ) );
$red = (int) ( $var[0]['variation_id'] ?? 0 );
check( 'R2-H2 a variation imported: 5, eBay\'s sales recorded (0)', array( stock( $red ), meta( $red, '_ebay_sold_seen' )['sold'] ?? null ), array( 5, 0 ) );
website_order( 1661, array( $red => 1 ) );
import_variations( 1610, $mug( 5, 1 ) );
check( '  eBay sold one, the website one, and the import lands before the sale\'s push: WooCommerce\'s 4 and the record are left alone', array( stock( $red ), meta( $red, '_ebay_sold_seen' )['sold'] ?? null, logged( 'a website sale of it is still on its way' ) ), array( 4, 0, true ) );
ebay_has( '161000001610', variation_listing( '161000001610', array( ev( 'MG-R', 5, 1, array( 'colour' => 'Red' ) ) ) ) );
run_queue();
check( '  the push then takes eBay\'s sale off too: 3, and sends 3', array( stock( $red ), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( 3, 3 ) );
// A hand edit on its way still keeps only the lower (decision 10).
fresh_shop();
variable( 1615, '161500001615' );
$var = import_variations( 1615, array( array( 'sku' => 'MG-B', 'stock_quantity' => 5, 'sold' => 0, 'attributes' => array( 'colour' => 'Blue' ) ) ) );
$blue = (int) ( $var[0]['variation_id'] ?? 0 );
hand_edit( $blue, 7 );
import_variations( 1615, array( array( 'sku' => 'MG-B', 'stock_quantity' => 4, 'sold' => 1, 'attributes' => array( 'colour' => 'Blue' ) ) ) );
check( '  a hand edit to 7 on its way, eBay lower at 4: eBay\'s 4 written, as before', stock( $blue ), 4 );

echo "\nR2-M2: A TRY THE HOST ENDS WITH NOTHING TO REPORT\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 1620, 3, '162000001620' );
ebay_has( '162000001620', listing( '162000001620', 3, 0 ) );
website_order( 1662, array( 1620 => 1 ) );
$AT_CALL = null;
$ON_CALL['GetItem'] = function () {
	// The process killed here (SIGKILL, an FPM time limit): nothing after
	// this line runs, not even PHP's shutdown. What it leaves is this.
	$GLOBALS['AT_CALL'] = array( 'queue' => $GLOBALS['QUEUE'], 'meta' => $GLOBALS['META'] );
};
run_queue();
$safety = array_values( array_filter( $AT_CALL['queue'] ?? array(), function ( $a ) { return ! empty( $a['args'][0]['safety'] ); } ) );
check( 'R2-M2 before eBay is called, a safety try is queued - the next try, at 16 minutes: the 15-minute limit of a try, and a minute (R3-L2)', array( count( $safety ), $safety[0]['args'][0]['attempt'] ?? null, isset( $safety[0] ) && in_about( $safety[0], 960 ) ), array( 1, 2, true ) );
check( '  the push finished: the safety try taken off the queue, the running mark gone', array( pushes(), meta( 1620, '_ebay_push_running' ) ), array( array(), null ) );
// As the host left it: the safety try queued, the try marked as running.
$QUEUE = $AT_CALL['queue'] ?? array();
$META  = $AT_CALL['meta'] ?? array();
$WIRE  = array();
$LOG   = array();
$run   = array_keys( (array) meta( 1620, '_ebay_push_running' ) )[0] ?? '';
run_queue( true );
check( '  run while that try could still be going: nothing sent, looked at again once it cannot be', array( $WIRE, count( pushes() ), isset( pushes()[0] ) && pushes()[0]['when'] > time() + 800 ), array( array(), 1, true ) );
// The clock moves on for all of it: the start and the last eBay call the try
// began (its heartbeat, fix round 4).
$META[1620]['_ebay_push_running'][ $run ]['started'] = time() - 1100;
$META[1620]['_ebay_push_running'][ $run ]['beat']    = time() - 1100;
$QUEUE[0]['when'] = time();
run_queue( true );
check( '  past that: it is the next try, and says why', array( calls(), logged( 'with nothing PHP could report', 'error' ), stock( 1620 ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), true, 2 ) );
check( '  and it sent the sale once', qty_sent( sent( 'ReviseInventoryStatus' ) ), 2 );
// The last try killed: its safety try gives the push up, with no call.
fresh_shop();
product( 1621, 3, '162100001621' );
$INV->process_stock_push( array( 'product_id' => 1621, 'reason' => 'order', 'attempt' => 7, 'qty' => 1, 'item' => '162100001621' ) );
check( '  a safety try past the sixth: given up, listed, no call', array( $WIRE, count( get_option( TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION, array() ) ), logged( 'on its last try', 'error' ) ), array( array(), 1, true ) );
// A try that hits the ceiling and waits for tomorrow takes its safety try off too.
fresh_shop();
fleet_used( 0.85 );
product( 1622, 3, '162200001622' );
hand_edit( 1622, 5 );
run_queue();
check( '  a push put off to tomorrow: one action queued - the one for tomorrow', array( count( pushes() ), pushes()[0]['args'][0]['tomorrow'] ?? null, isset( pushes()[0]['args'][0]['safety'] ) ), array( 1, 1, false ) );
// N1 (release fix round 2): its pending entry, near its end, runs to tomorrow's try and the tail.
fresh_shop();
fleet_used( 0.85 );
product( 1623, 3, '162300001623' );
hand_edit( 1623, 5 );
$tcg_tok = (string) array_key_first( (array) meta( 1623, '_ebay_push_pending' ) );
$tcg_q   = time() - 40 * HOUR_IN_SECONDS;
$META[1623]['_ebay_push_pending'][ $tcg_tok ] = array( 'until' => time() + 600, 'queued_at' => $tcg_q );
run_queue();
$tcg_entry = ( (array) meta( 1623, '_ebay_push_pending' ) )[ $tcg_tok ] ?? array();
check( 'N1 a push put off to tomorrow near its entry\'s end: the entry runs to tomorrow\'s try and the tail, its queue time kept', array( '' !== $tcg_tok, (int) ( $tcg_entry['until'] ?? 0 ) >= (int) ( pushes()[0]['when'] ?? PHP_INT_MAX ) + TCGiant_Sync_Inventory::PUSH_PENDING_TAIL, (int) ( $tcg_entry['queued_at'] ?? 0 ) === $tcg_q ), array( true, true, true ) );

echo "\nR2-M3, R2-M6: THE RECORD FROM eBAY'S ANSWER TO A REVISE\n" . str_repeat( '=', 132 ) . "\n";

// L3: no record, 10 sold long ago (in WooCommerce's 2). A delivery of 10,
// then a sale, both before the queue runs.
foreach ( array( 'eBay\'s answer says how many' => true, 'eBay\'s answer does not say' => false ) as $how => $says ) {
	fresh_shop();
	product( 1630, 2, '163000001630' );
	if ( $says ) {
		$EBAY['sold'] = array( '163000001630' => 10 );
	}
	hand_edit( 1630, 12 );
	next_request();
	website_order( 1663, array( 1630 => 1 ) );
	ebay_has( '163000001630', listing( '163000001630', 21, 10 ) );
	run_queue( true );
	check( ( $says ? 'R2-M3 L3' : '  ' ) . ' (' . $how . ') a delivery to 12 sent after the sale took it to 11, then the sale\'s push: 11 kept, nothing more sent', array( $revised(), stock( 1630 ) ), array( array( 11 ), 11 ) );
}
check( '  the record made from the answer: 10 sold', ( function () { fresh_shop(); product( 1631, 2, '163100001631' ); $GLOBALS['EBAY']['sold'] = array( '163100001631' => 10 ); hand_edit( 1631, 12 ); run_queue(); return meta( 1631, '_ebay_sold_seen' ); } )(), array( 'item' => '163100001631', 'sku' => '', 'sold' => 10 ) );
check( '  without it, what eBay was left showing', ( function () { fresh_shop(); product( 1632, 2, '163200001632' ); hand_edit( 1632, 12 ); run_queue(); return meta( 1632, '_ebay_sold_seen' ); } )(), array( 'item' => '163200001632', 'sku' => '', 'sent' => 12 ) );
// L3b: two hand edits, then a sale of 2.
fresh_shop();
product( 1633, 3, '163300001633' );
$EBAY['sold'] = array( '163300001633' => 20 );
hand_edit( 1633, 8 );
next_request();
website_order( 1664, array( 1633 => 2 ) );
ebay_has( '163300001633', listing( '163300001633', 26, 20 ) );
run_queue( true );
check( 'R2-M3 L3b 20 sold long ago, a delivery to 8 then a sale of 2: 6, and eBay 6', array( $revised(), stock( 1633 ) ), array( array( 6 ), 6 ) );
// eBay's sales since a 'sent' record: what eBay shows below it.
fresh_shop();
product( 1634, 5, '163400001634', array( '_ebay_sold_seen' => array( 'item' => '163400001634', 'sku' => '', 'sent' => 5 ) ) );
ebay_has( '163400001634', listing( '163400001634', 15, 12 ) );
website_order( 1665, array( 1634 => 1 ) );
run_queue();
check( '  left showing 5, eBay now has 3 (2 sold there) and the website sold 1: WooCommerce 2, eBay sent 2, the record eBay\'s 12', array( $revised(), stock( 1634 ), meta( 1634, '_ebay_sold_seen' ) ), array( array( 2 ), 2, array( 'item' => '163400001634', 'sku' => '', 'sold' => 12 ) ) );
// L5: a refund puts one back after an eBay sale WooCommerce has not seen.
fresh_shop();
product( 1635, 4, '163500001635' );
seen( 1635, '163500001635', 0 );
$EBAY['sold'] = array( '163500001635' => 1 );
$order = new WC_Order( 1666, array( new WC_Order_Item_Product( 16661, 1635, 1 ) ) );
$order->get_items()[0]->meta = array( '_reduced_stock' => 1 );
$ORDERS[1666] = $order;
wc_increase_stock_levels( $order );
run_queue();
check( 'R2-M6 L5 a refund puts 1 back (4 to 5) after eBay sold 1 unseen: eBay\'s answer shows it, so WooCommerce 4, and eBay sent 4 again', array( $revised(), stock( 1635 ), meta( 1635, '_ebay_sold_seen' )['sold'] ?? null ), array( array( 5, 4 ), 4, 1 ) );
check( '  one extra call, only because it happened', calls(), array( 'ReviseInventoryStatus', 'ReviseInventoryStatus' ) );
fresh_shop();
product( 1636, 4, '163600001636' );
seen( 1636, '163600001636', 1 );
$EBAY['sold'] = array( '163600001636' => 1 );
hand_edit( 1636, 5 );
run_queue();
check( '  no eBay sale since the record: one call', array( calls(), stock( 1636 ) ), array( array( 'ReviseInventoryStatus' ), 5 ) );
fresh_shop();
product( 1637, 1, '163700001637' );
seen( 1637, '163700001637', 0 );
$EBAY['sold'] = array( '163700001637' => 1 );
hand_edit( 1637, 2 );
run_queue();
check( '  a restock of the last one, which eBay sold meanwhile: WooCommerce 1, and eBay sent 1', array( $revised(), stock( 1637 ) ), array( array( 2, 1 ), 1 ) );
// R2-L2: QuantitySold below the record, with no unit back.
fresh_shop();
product( 1638, 10, '163800001638' );
seen( 1638, '163800001638', 10 );
ebay_has( '163800001638', listing( '163800001638', 10, 3 ) );
website_order( 1667, array( 1638 => 1 ) );
run_queue();
check( 'R2-L2 eBay\'s QuantitySold fell below the record: said as a warning, and the record follows it down', array( logged( 'fewer than the 10 recorded', 'warning' ), meta( 1638, '_ebay_sold_seen' )['sold'] ?? null ), array( true, 3 ) );

echo "\nR2-M4: STOCK SHARED ON THE PARENT, WITH NO RECORD YET\n" . str_repeat( '=', 132 ) . "\n";

// L9: the parent's 6 set on eBay (nothing to send: the record is made), then
// eBay sells 1 S and 2 M, then a website sale.
$tee = function ( $s_sold, $m_sold, $s_av = 6, $m_av = 6 ) { return variation_listing( '164000001640', array( ev( 'TE-S', $s_av + $s_sold, $s_sold, array( 'size' => 'S' ) ), ev( 'TE-M', $m_av + $m_sold, $m_sold, array( 'size' => 'M' ) ) ) ); };
$tee_shop = function () {
	fresh_shop();
	variable( 1640, '164000001640', array( '_manage_stock' => 'yes', '_stock' => 6 ) );
	variation( 1641, 1640, 'TE-S', null, array( 'size' => 'S' ) );
	variation( 1642, 1640, 'TE-M', null, array( 'size' => 'M' ) );
};
$tee_shop();
ebay_has( '164000001640', $tee( 0, 0 ) );
hand_edit( 1640, 6 );
run_queue();
check( 'R2-M4 the shared 6 already on eBay: nothing sent, and each variation\'s record made', array( calls(), meta( 1641, '_ebay_sold_seen' )['sold'] ?? null, meta( 1642, '_ebay_sold_seen' )['sold'] ?? null ), array( array( 'GetItem' ), 0, 0 ) );
next_request();
$WIRE = array();
ebay_has( '164000001640', $tee( 1, 2, 5, 4 ), $tee( 1, 2, 2, 2 ) );
website_order( 1668, array( 1641 => 1 ) );
run_queue();
check( '  L9 eBay sells 1 S and 2 M, then the website 1: the shared stock 2, and each variation sent 2', array( stock( 1640 ), statuses( sent( 'ReviseInventoryStatus' ) ) ), array( 2, array( 'TE-S' => 2, 'TE-M' => 2 ) ) );
check( '  L9: the sale\'s push recorded each variation\'s eBay sales on it', array( meta( 1641, '_ebay_sold_seen' )['sold'] ?? null, meta( 1642, '_ebay_sold_seen' )['sold'] ?? null ), array( 1, 2 ) );
// No record at all: each variation against the stock before the sale.
$tee_shop();
ebay_has( '164000001640', $tee( 1, 2, 5, 4 ), $tee( 1, 2, 2, 2 ) );
website_order( 1669, array( 1641 => 1 ) );
run_queue();
check( '  with no record at all: each variation looked at against the 6 before the sale - 2 again, not the sum', array( stock( 1640 ), statuses( sent( 'ReviseInventoryStatus' ) ) ), array( 2, array( 'TE-S' => 2, 'TE-M' => 2 ) ) );
// L9c: order sync's settle first, with no record.
$tee_shop();
ebay_has( '164000001640', $tee( 1, 2, 5, 4 ) );
$IMP->settle_item( '164000001640' );
check( '  L9c order sync settles eBay\'s sales of the variations sharing the stock: 6 to 3, each one\'s record made', array( stock( 1640 ), meta( 1641, '_ebay_sold_seen' )['sold'] ?? null, meta( 1642, '_ebay_sold_seen' )['sold'] ?? null ), array( 3, 1, 2 ) );
ebay_has( '164000001640', $tee( 1, 2, 5, 4 ), $tee( 1, 2, 2, 2 ) );
website_order( 1670, array( 1641 => 1 ) );
run_queue();
check( '  then the website sale: 2, and each variation sent 2', array( stock( 1640 ), statuses( sent( 'ReviseInventoryStatus' ) ) ), array( 2, array( 'TE-S' => 2, 'TE-M' => 2 ) ) );
$tee_shop();
$META[1640]['_ebay_push_pending'] = array( 'sale-x' => time() + 600 );
ebay_has( '164000001640', $tee( 1, 2, 5, 4 ) );
$IMP->settle_item( '164000001640' );
check( '  with no record and a website sale on its way, the settle leaves the shared stock to that sale\'s push', stock( 1640 ), 6 );

echo "\nROUND 2: MORE OF THE SAFETY TRY, THE RECORD AND THE AUCTION CHECK\n" . str_repeat( '=', 132 ) . "\n";

// A try again due before the safety try is queued for its own time, and the
// safety try is taken off: decision 9's slots stay as they are (R3-L2).
fresh_shop();
product( 1680, 3, '168000001680' );
ebay_has( '168000001680', listing( '168000001680', 3, 0 ) );
ebay_answers( 'ReviseInventoryStatus', refused( 10007, 'Internal error to the application.' ) );
website_order( 1690, array( 1680 => 1 ) );
run_queue();
check( 'a sale tried again in 5 minutes: one action queued, the try again itself, and the safety try taken off', array( count( pushes() ), isset( pushes()[0]['args'][0]['safety'] ), isset( pushes()[0] ) && in_about( pushes()[0], 300 ), meta( 1680, '_ebay_push_running' ) ), array( 1, false, true, null ) );
$WIRE = array();
run_queue( true );
check( '  it runs as the try again: eBay read, the sale sent', array( calls(), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), 2 ) );
// A safety try left on the queue after its try finished does nothing.
fresh_shop();
product( 1681, 3, '168100001681' );
$LEFT = null;
$ON_CALL['ReviseInventoryStatus'] = function () { $GLOBALS['LEFT'] = pushes(); };
hand_edit( 1681, 2 );
run_queue();
$QUEUE = $LEFT;
$WIRE  = array();
run_queue( true );
check( '  a safety try whose try finished, run anyway (no Action Scheduler 3 to take it off): nothing sent, nothing queued', array( $WIRE, pushes() ), array( array(), array() ) );
// Handed a try again due later than itself: put off to then.
fresh_shop();
product( 1682, 3, '168200001682' );
$META[1682]['_ebay_push_running'] = array( 'run-x' => array( 'started' => time() - 60, 'when' => time(), 'next' => array( 'product_id' => 1682, 'reason' => 'edit', 'attempt' => 4 ), 'at' => time() + 3600 ) );
$INV->process_stock_push( array( 'product_id' => 1682, 'reason' => 'edit', 'attempt' => 4, 'safety' => 'run-x' ) );
check( '  a safety try handed a try again due in an hour: nothing sent now, the push queued for then', array( $WIRE, count( pushes() ), isset( pushes()[0] ) && in_about( pushes()[0], 3600 ), isset( pushes()[0]['args'][0]['safety'] ) ), array( array(), 1, true, false ) );

// The record: a note of what eBay was left showing never replaces one of
// eBay's QuantitySold.
fresh_shop();
product( 1683, 5, '168300001683' );
seen( 1683, '168300001683', 3 );
hand_edit( 1683, 4 );
run_queue();
check( 'a push lands with eBay\'s answer silent: a record of eBay\'s QuantitySold is kept as it was', meta( 1683, '_ebay_sold_seen' ), array( 'item' => '168300001683', 'sku' => '', 'sold' => 3 ) );
fresh_shop();
product( 1684, 5, '168400001684', array( '_ebay_sold_seen' => array( 'item' => '168400001684', 'sku' => '', 'sent' => 5 ) ) );
ebay_has( '168400001684', listing( '168400001684', 3, 2 ) );
website_order( 1691, array( 1684 => 1 ) );
run_queue();
check( '  left showing 5, eBay shows 1 but has sold only 2: no more than those 2 come off (5 - 1 - 2 = 2)', array( stock( 1684 ), $revised() ), array( 2, array() ) );
fresh_shop();
product( 1685, 5, '168500001685', array( '_ebay_sold_seen' => array( 'item' => '168500001685', 'sku' => '', 'sent' => 5 ) ) );
ebay_has( '168500001685', $timeout );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
hand_edit( 1685, 3 );
run_queue();
check( '  a revise unanswered and eBay unreadable: what it was left showing says nothing any more, and is dropped', meta( 1685, '_ebay_sold_seen' ), null );
fresh_shop();
product( 1686, 5, '168600001686', array( '_ebay_sold_seen' => array( 'item' => '168600001686', 'sku' => '', 'sent' => 5 ) ) );
ebay_has( '168600001686', listing( '168600001686', 7, 4 ) );
$IMP->settle_item( '168600001686' );
check( '  order sync settles from a note of what eBay was left showing: 5 to 3, and eBay\'s QuantitySold recorded', array( stock( 1686 ), meta( 1686, '_ebay_sold_seen' ) ), array( 3, array( 'item' => '168600001686', 'sku' => '', 'sold' => 4 ) ) );
fresh_shop();
product( 1687, 4, '168700001687' );
seen( 1687, '168700001687', 5 );
$EBAY['sold'] = array( '168700001687' => 2 );
hand_edit( 1687, 3 );
run_queue();
check( '  eBay\'s answer says fewer sold than recorded: a warning, and the record follows it down', array( logged( 'fewer than the 5 recorded', 'warning' ), meta( 1687, '_ebay_sold_seen' )['sold'] ?? null ), array( true, 2 ) );

// The auction check: kept a day, and set by what shows an auction.
fresh_shop();
set_transient( TCGiant_Sync_Inventory::AUCTIONS_TRANSIENT, 'yes', DAY_IN_SECONDS );
product( 1688, 1, '168800001688', array( '_ebay_listing_type_item' => null ) );
ebay_has( '168800001688', listing( '168800001688', 1, 0 ) );
hand_edit( 1688, 0 );
run_queue();
check( 'the shop known to have an auction (kept for the day): a backfill-typed product to 0 reads first', calls(), array( 'GetItem', 'EndItem' ) );
fresh_shop();
product( 1689, 1, '168900001689' );
TCGiant_Sync_Inventory::follow_relist( 1689, '168900001689', '268900001689', array( 'status' => 'Active', 'listing_type' => 'Chinese', 'start_time' => '2026-10-24T12:00:00.000Z', 'end_time' => '2026-10-31T12:00:00.000Z' ), 'Delta sync' );
check( '  a relist followed that is an auction: the shop has one', get_transient( TCGiant_Sync_Inventory::AUCTIONS_TRANSIENT ), 'yes' );
fresh_shop( array( 'remove_sold_products' => '0' ) );
product( 1692, 0, '169200001692', array( '_ebay_listing_type_item' => null ) );
TCGiant_Sync_Importer::settle_ended_from_item( 1692, ended( '169200001692', 1, 1 ), 'Delta sync' );
check( '  a listing settled as over: the type from that answer kept for it', meta( 1692, '_ebay_listing_type_item' ), '169200001692' );

echo "\nR2-M1(a), and the pruner's lowering\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 1650, 5, '165000001650' );
seen( 1650, '165000001650', 1 );
delete_post_meta( 1650, '_ebay_listing_type_item' );
$CHECK = new ReflectionMethod( 'TCGiant_Sync_Importer', 'check_prune_candidate' );
if ( PHP_VERSION_ID < 80100 ) {
	$CHECK->setAccessible( true );
}
ebay_has( '165000001650', listing( '165000001650', 5, 3 ) );
$calls_made = 0;
$CHECK->invokeArgs( $IMP, array( 1650, '165000001650', &$calls_made ) );
check( 'the inventory pruner lowers 5 to 2 for two eBay sales: the record moves on by them, and the type is kept', array( stock( 1650 ), meta( 1650, '_ebay_sold_seen' )['sold'] ?? null, meta( 1650, '_ebay_listing_type_item' ) ), array( 2, 3, '165000001650' ) );

// ─────────────────────────────────────────────────────────────────────────────
// Fix round 3: what the re-verification of 8cfa729 found.
// ─────────────────────────────────────────────────────────────────────────────

/** The import of one listing, as the delta and the full sync make it. */
function import_listing( array $item ) {
	TCGiant_Sync_Inventory::begin_ebay_origin();
	try {
		$data = $GLOBALS['MAPPER']->map_ebay_to_woo( $item );
		return $GLOBALS['MAPPER']->save_as_product( $data );
	} finally {
		TCGiant_Sync_Inventory::end_ebay_origin();
	}
}

echo "\nR3-H1: AN IMPORT KEEPS STOCK SHARED ON THE PARENT\n" . str_repeat( '=', 132 ) . "\n";

// S and M take the parent's stock; eBay shows the whole of it for each.
$sock      = function ( $s_sold, $m_sold, $s_av, $m_av, array $more = array() ) {
	return variation_listing( '170000001700', array_merge( array( ev( 'SOCK-S', $s_av + $s_sold, $s_sold, array( 'size' => 'S' ) ), ev( 'SOCK-M', $m_av + $m_sold, $m_sold, array( 'size' => 'M' ) ) ), $more ) ) + array( 'Title' => 'Socks', 'SKU' => 'SOCK' );
};
$sock_shop = function ( $pool ) {
	fresh_shop();
	variable( 1700, '170000001700', array( '_manage_stock' => 'yes', '_stock' => $pool, '_sku' => 'SOCK' ) );
	variation( 1701, 1700, 'SOCK-S', null, array( 'size' => 'S' ) );
	variation( 1702, 1700, 'SOCK-M', null, array( 'size' => 'M' ) );
};
// What WooCommerce offers in all: the shared stock, and any variation's own.
$offered = function () {
	$n = (int) stock( 1700 );
	foreach ( array( 1701, 1702 ) as $v ) {
		$p  = wc_get_product( $v );
		$n += TCGiant_Sync_Inventory::stock_held_by_parent( $p ) ? 0 : (int) $p->get_stock_quantity();
	}
	return $n;
};

// L11: the pool push, then the import of the listing eBay reports as changed.
$sock_shop( 6 );
ebay_has( '170000001700', $sock( 0, 0, 6, 6 ), $sock( 0, 0, 5, 5 ) );
hand_edit( 1700, 5 );
run_queue();
next_request();
import_listing( $sock( 0, 0, 5, 5 ) );
check( 'R3-H1 L11 the shared 5 sent to S and M, then the import of the listing: S and M still take the parent\'s 5 - 5 in all, not 10', array( stock( 1700 ), meta( 1701, '_manage_stock' ), meta( 1702, '_manage_stock' ), $offered() ), array( 5, 'no', 'no', 5 ) );
import_listing( $sock( 1, 1, 4, 4 ) );
check( '  eBay sells one S and one M before the next import: both come off the shared stock, 5 to 3, and each record moves on', array( stock( 1700 ), meta( 1701, '_ebay_sold_seen' )['sold'] ?? null, meta( 1702, '_ebay_sold_seen' )['sold'] ?? null ), array( 3, 1, 1 ) );
import_listing( $sock( 1, 1, 9, 9 ) );
check( '  the seller raises both to 9 in Seller Hub: an import never raises the shared stock - 3', array( stock( 1700 ), $offered() ), array( 3, 3 ) );

$sock_shop( 6 );
import_listing( $sock( 1, 0, 5, 6 ) );
check( '  no record yet: S shows 5 against the shared 6, one eBay sale - 6 to 5 (each figure against the pool, never their sum), and the records made', array( stock( 1700 ), meta( 1701, '_ebay_sold_seen' )['sold'] ?? null, meta( 1702, '_ebay_sold_seen' )['sold'] ?? null ), array( 5, 1, 0 ) );

// L14: a website sale of S puts its token on the parent, which holds the stock.
$sock_shop( 6 );
seen( 1701, '170000001700', 0, 'SOCK-S' );
seen( 1702, '170000001700', 0, 'SOCK-M' );
website_order( 1750, array( 1701 => 1 ) );
$tokens = array( array_keys( (array) meta( 1700, '_ebay_push_pending' ) ), array_keys( (array) meta( 1701, '_ebay_push_pending' ) ) );
import_listing( $sock( 1, 0, 5, 6 ) );
check( 'R3-H1 L14 a website sale of S (its token on the parent), then an import before its push, eBay having sold an S too: the shared 5 and the records left for the push', array( count( $tokens[0] ), $tokens[1], stock( 1700 ), meta( 1701, '_ebay_sold_seen' )['sold'] ?? null ), array( 1, array(), 5, 0 ) );
ebay_has( '170000001700', $sock( 1, 0, 5, 6 ), $sock( 1, 0, 4, 4 ) );
run_queue();
check( '  the push then takes eBay\'s sale off too: 4, and S and M sent 4', array( stock( 1700 ), statuses( sent( 'ReviseInventoryStatus' ) ) ), array( 4, array( 'SOCK-S' => 4, 'SOCK-M' => 4 ) ) );

$sock_shop( 6 );
$META[1700]['_ebay_push_pending'] = array( 'unsent' => time() + DAY_IN_SECONDS );
$META[1700]['_ebay_unsent_qty']   = 1;
seen( 1701, '170000001700', 0, 'SOCK-S' );
import_listing( $sock( 1, 1, 5, 5 ) );
check( '  a sale of the pool that never reached eBay: eBay\'s sales of a variation with a record come off (S), one with none waits (M) - 6 to 5', array( stock( 1700 ), meta( 1701, '_ebay_sold_seen' )['sold'] ?? null, meta( 1702, '_ebay_sold_seen' ) ), array( 5, 1, null ) );

$sock_shop( 6 );
import_listing( $sock( 0, 0, 6, 6, array( ev( 'SOCK-L', 3, 0, array( 'size' => 'L' ) ) ) ) );
$new_l = wc_get_product_id_by_sku( 'SOCK-L' );
check( '  a variation eBay has that WooCommerce did not: made as before, with its own 3; the shared 6 untouched', array( $new_l > 0, meta( $new_l, '_manage_stock' ), stock( $new_l ), stock( 1700 ) ), array( true, 'yes', 3, 6 ) );

echo "\nR3-H2: A POOL AT 0 WITH A VARIATION KEEPING ITS OWN STOCK\n" . str_repeat( '=', 132 ) . "\n";

$pool_shop = function ( $l_sku, $l_size ) {
	fresh_shop();
	variable( 1770, '177000001770', array( '_manage_stock' => 'yes', '_stock' => 1 ) );
	variation( 1771, 1770, 'PL-S', null, array( 'size' => 'S' ) );
	variation( 1772, 1770, 'PL-M', null, array( 'size' => 'M' ) );
	variation( 1773, 1770, $l_sku, 2, array( 'size' => $l_size ) );
};
$no_zero = refused( 942, 'Quantity is not valid for an item with variations.' );

// a. L keeps 2 here, but the listing has no L: eBay removed it at 0 before it sold.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 1, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ) ) ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
run_queue();
check( 'R3-H2 a: the shared stock runs out and eBay will not zero S, the last with stock; L keeps 2 here but is not on the listing: ended, and L named', array( calls(), meta( 1770, '_ebay_listing_status' ), logged( 'variation(s) #1773 (PL-L) keep stock of their own here, but eBay listing 177000001770 has no variation of theirs', 'warning' ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), 'Ended', true ) );

// b. L is on eBay under a SKU and a size that do not pair with it, at 0.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 1, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( 'PL-LARGE', 0, 0, array( 'size' => 'Large' ) ) ) ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
run_queue();
check( 'R3-H2 b: L on eBay only as PL-LARGE / Large, which nothing here pairs with: ended', array( calls(), meta( 1770, '_ebay_listing_status' ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), 'Ended' ) );

// The paired case: the listing has L at 0, and WooCommerce has 2 of it.
$pool_shop( 'PL-L', 'L' );
$at_zero = variation_listing( '177000001770', array( ev( 'PL-S', 1, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 0, 0, array( 'size' => 'L' ) ) ) );
$l_back  = variation_listing( '177000001770', array( ev( 'PL-S', 1, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 2, 0, array( 'size' => 'L' ) ) ) );
$all_out = variation_listing( '177000001770', array( ev( 'PL-S', 0, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 2, 0, array( 'size' => 'L' ) ) ) );
ebay_has( '177000001770', $at_zero, $at_zero, $l_back, $all_out );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
run_queue();
$queued = array_map( function ( $a ) { return array( (int) $a['args'][0]['product_id'], (string) $a['args'][0]['reason'], (int) ( $a['args'][0]['attempt'] ?? 1 ) ); }, pushes() );
check( 'R3-H2 the listing has L at 0 and WooCommerce 2 of it: not ended - L\'s own push is queued and sends its 2, and the shared stock is tried again after it', array( meta( 1770, '_ebay_listing_status' ), logged( 'its own push is queued to send it' ), array_map( 'statuses', all_sent( 'ReviseInventoryStatus' ) ), $queued ), array( 'Active', true, array( array( 'PL-S' => 0 ), array( 'PL-L' => 2 ) ), array( array( 1770, 'edit', 2 ) ) ) );
$WIRE = array();
run_queue( true );
check( '  the try again: eBay now takes S at 0, L having stock there - the listing stays live on L', array( array_map( 'statuses', all_sent( 'ReviseInventoryStatus' ) ), meta( 1770, '_ebay_listing_status' ), pushes() ), array( array( array( 'PL-S' => 0 ) ), 'Active', array() ) );

// L keeps its own stock, has none here now, and a change of it is on its
// way: the pool waits for it (case d), as before.
$pool_shop( 'PL-L', 'L' );
$META[1773]['_stock']             = 0;
$META[1773]['_ebay_push_pending'] = array( 'l-own' => time() + HOUR_IN_SECONDS );
ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 1, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 1, 0, array( 'size' => 'L' ) ) ) ), variation_listing( '177000001770', array( ev( 'PL-S', 1, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 0, 0, array( 'size' => 'L' ) ) ) ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
run_queue();
check( 'R3-H2 L at 0 here with a change of its own on its way: the pool waits for it - not ended, tried again', array( meta( 1770, '_ebay_listing_status' ), count( pushes() ), logged( 'has a change on its way' ) ), array( 'Active', 1, true ) );

// L joined the pool (its "Manage stock" unticked) while a push of its own
// was still counting: it shares the pool now, so it cannot keep the listing
// live, and is not waited for.
$pool_shop( 'PL-L', 'L' );
$META[1773]['_manage_stock']      = 'no';
$META[1773]['_ebay_push_pending'] = array( 'l-own' => time() + HOUR_IN_SECONDS );
ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 1, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 0, 0, array( 'size' => 'L' ) ) ) ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
run_queue();
check( '  L now shares the pool, though a push of its own still counts: not waited for - ended', meta( 1770, '_ebay_listing_status' ), 'Ended' );

echo "\nR3-M1: THE SALE HOLD ON A RELIST THE IMPORT TAKES ON\n" . str_repeat( '=', 132 ) . "\n";

// L7b: the sale's push times out, the seller relists in Seller Hub, and the
// import takes the relist on (by its SKU, the listing it names being over).
$timed_out = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
fresh_shop();
product( 1780, 3, '111000001780' );
ebay_has( '111000001780', $timed_out, $timed_out, $timed_out, $timed_out );
website_order( 1781, array( 1780 => 1 ) );
run_queue();
ebay_has( '111000001780', array_replace_recursive( ended( '111000001780', 3, 0 ), array( 'ListingDetails' => array( 'RelistedItemID' => '222000001780' ) ) ) );
ebay_has( '222000001780', listing( '222000001780', 3, 0 ) );
import_listing( listing( '222000001780', 3, 0 ) + array( 'Title' => 'Box', 'SKU' => 'SKU-1780', 'RelistParentID' => '111000001780' ) );
check( 'R3-M1 L7b a sale\'s push timed out, the seller relisted, and the import took the relist on: WooCommerce keeps 2 (the sale), not the relist\'s 3', array( meta( 1780, '_ebay_item_id' ), stock( 1780 ) ), array( '222000001780', 2 ) );
$WIRE = array();
run_queue( true );
check( '  the sale\'s try again then sends 2 to the relist', array( item_in( sent( 'ReviseInventoryStatus' ) ), qty_sent( sent( 'ReviseInventoryStatus' ) ), stock( 1780 ) ), array( '222000001780', 2, 2 ) );

// The move made earlier (the sale check or the prune), the import after it.
fresh_shop();
product( 1782, 2, '222000001782', array( '_ebay_relist_stock_check' => array( 'item' => '222000001782', 'parent' => '111000001782', 'held' => 3 ), '_ebay_push_pending' => array( 'sale-r3m1' => time() + HOUR_IN_SECONDS ) ) );
import_listing( listing( '222000001782', 3, 0 ) + array( 'Title' => 'Box', 'SKU' => 'SKU-1782' ) );
check( '  the same where the product had already moved on to the relist: 2 kept', stock( 1782 ), 2 );

echo "\nR3-L1: A SALE THAT NEVER REACHED EBAY HOLDS ONLY ITS OWN UNITS\n" . str_repeat( '=', 132 ) . "\n";

$given_up = function ( $id, $item ) {
	fresh_shop();
	product( $id, 5, $item );
	seen( $id, $item, 0 );
	ebay_has( $item, listing( $item, 5, 0 ) );
	$r = array();
	for ( $i = 0; $i < 12; $i++ ) {
		$r[] = refused( 21919999, 'Something about the listing.' );
	}
	ebay_answers( 'ReviseInventoryStatus', ...$r );
	website_order( $id + 1, array( $id => 1 ) );
	run_queue( true );
	ebay_answers( 'ReviseInventoryStatus' );
};
$given_up( 1790, '179000001790' );
check( 'R3-L1 a sale refused for good: given up; its own mark goes at once, the unsent mark holds, with its 1 unit', array( array_keys( (array) meta( 1790, '_ebay_push_pending' ) ), array_sum( (array) meta( 1790, '_ebay_unsent_qty' ) ), stock( 1790 ) ), array( array( 'unsent' ), 1, 4 ) );
import_listing( listing( '179000001790', 2, 0 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1790' ) );
check( '  L13 the seller lowers the listing to 2 in Seller Hub (3 damaged), then the import: eBay\'s 2 less the 1 unsent - WooCommerce 1, the shelf', array( stock( 1790 ), meta( 1790, '_ebay_sold_seen' )['sold'] ?? null ), array( 1, 0 ) );
$given_up( 1792, '179200001792' );
import_listing( listing( '179200001792', 5, 0 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1792' ) );
check( '  eBay still at its 5: 5 less 1 is not lower than WooCommerce\'s 4 - kept, the record left alone', array( stock( 1792 ), meta( 1792, '_ebay_sold_seen' )['sold'] ?? null ), array( 4, 0 ) );
$META[1792]['_ebay_push_pending'] = array( 'unsent' => time() + DAY_IN_SECONDS, 'sale-r3l1' => time() + HOUR_IN_SECONDS );
import_listing( listing( '179200001792', 2, 0 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1792' ) );
check( '  a sale\'s push on its way as well: the whole hold - 4 kept', stock( 1792 ), 4 );
$given_up( 1794, '179400001794' );
$META[1794]['_stock'] = 4;
website_order( 1796, array( 1794 => 2 ) );
ebay_answers( 'ReviseInventoryStatus', ...array_fill( 0, 12, refused( 21919999, 'Something about the listing.' ) ) );
run_queue( true );
check( '  a second sale given up while the mark holds: its units are added - 3', array_sum( (array) meta( 1794, '_ebay_unsent_qty' ) ), 3 );
ebay_answers( 'ReviseInventoryStatus' );
hand_edit( 1794, 1 );
run_queue( true );
check( '  a later push that lands takes the mark off, and its units', array( in_array( 'unsent', array_keys( (array) meta( 1794, '_ebay_push_pending' ) ), true ), meta( 1794, '_ebay_unsent_qty' ) ), array( false, null ) );

echo "\nR3-L2: A SAFETY TRY LOOKS ONLY AFTER A TRY'S LIMIT AND A MINUTE\n" . str_repeat( '=', 132 ) . "\n";

// Try 2 is still in its eBay call after 15 minutes when a second queue
// runner takes its safety try.
fresh_shop();
product( 1800, 3, '180000001800' );
ebay_has( '180000001800', $timed_out, $timed_out, $timed_out, listing( '180000001800', 3, 0 ) );
website_order( 1801, array( 1800 => 1 ) );
run_queue();
$NESTED = null;
$ON_CALL['ReviseInventoryStatus'] = function () {
	foreach ( $GLOBALS['META'][1800]['_ebay_push_running'] as $run => $entry ) {
		$GLOBALS['META'][1800]['_ebay_push_running'][ $run ]['started'] = (int) $entry['started'] - 900;
	}
	$safety = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) { return ! empty( $a['args'][0]['safety'] ); } ) );
	$w0     = count( $GLOBALS['WIRE'] );
	if ( $safety ) {
		TCGiant_Sync_Inventory::instance()->process_stock_push( $safety[0]['args'][0] );
	}
	$GLOBALS['NESTED'] = array( count( $safety ), count( $GLOBALS['WIRE'] ) - $w0 );
};
$WIRE = array();
run_queue( true, 1 );
check( 'R3-L2 try 2 still in its call at 15 minutes, its safety try run by a second runner: nothing sent beside it (a try is ended only at 16)', array( $NESTED, calls() ), array( array( 1, 0 ), array( 'GetItem', 'ReviseInventoryStatus' ) ) );
// Try 1 still in its call a second short of 16 minutes, the same.
fresh_shop();
product( 1802, 3, '180200001802' );
ebay_has( '180200001802', listing( '180200001802', 3, 0 ) );
website_order( 1803, array( 1802 => 1 ) );
$NESTED = null;
$ON_CALL['ReviseInventoryStatus'] = function () {
	foreach ( $GLOBALS['META'][1802]['_ebay_push_running'] as $run => $entry ) {
		$GLOBALS['META'][1802]['_ebay_push_running'][ $run ]['started'] = (int) $entry['started'] - 959;
	}
	$safety = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) { return ! empty( $a['args'][0]['safety'] ); } ) );
	$w0     = count( $GLOBALS['WIRE'] );
	if ( $safety ) {
		TCGiant_Sync_Inventory::instance()->process_stock_push( $safety[0]['args'][0] );
	}
	$GLOBALS['NESTED'] = array( count( $safety ), count( $GLOBALS['WIRE'] ) - $w0 );
};
$WIRE = array();
run_queue( true, 1 );
check( '  try 1 still in its call a second short of 16 minutes: nothing sent beside it either', array( $NESTED, calls(), stock( 1802 ) ), array( array( 1, 0 ), array( 'GetItem', 'ReviseInventoryStatus' ), 2 ) );

echo "\nR3-L4: A TAKEN REVISE CONFIRMS NOTHING IN A SHOP WITH AUCTIONS\n" . str_repeat( '=', 132 ) . "\n";

foreach ( array( 'no type' => null, 'the 3.27 backfill\'s type' => 'FixedPriceItem' ) as $label => $type ) {
	fresh_shop();
	set_transient( TCGiant_Sync_Inventory::AUCTIONS_TRANSIENT, 'yes', DAY_IN_SECONDS );
	product( 1810, 1, '181000001810' );
	unset( $META[1810]['_ebay_listing_type_item'] );
	if ( null === $type ) {
		unset( $META[1810]['_ebay_listing_type'] );
	} else {
		$META[1810]['_ebay_listing_type'] = $type;
	}
	ebay_has( '181000001810', listing( '181000001810', 1, 0, array( 'ListingType' => 'Chinese', 'SellingStatus' => array( 'BidCount' => '2' ) ) ) );
	hand_edit( 1810, 2 );
	run_queue();
	$after = array( meta( 1810, '_ebay_listing_type' ), meta( 1810, '_ebay_listing_type_item' ) );
	$WIRE  = array();
	hand_edit( 1810, 0 );
	run_queue();
	check( 'R3-L4 ' . $label . ', a shop with auctions: eBay takes a revise, which confirms nothing; the edit to 0 then reads, finds an auction with bids, and keeps it', array( $after, calls(), meta( 1810, '_ebay_listing_status' ) ), array( array( $type, null ), array( 'GetItem' ), 'Active' ) );
}

echo "\nR3-L3: THE LINK TOOL TELLS THE AUCTION CHECK\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 1820, 1, '182000001820' );
product( 1821, 1, '' );
TCGiant_Sync_Inventory::shop_may_have_auctions();
$cached = get_transient( TCGiant_Sync_Inventory::AUCTIONS_TRANSIENT );
ebay_has( '182100001821', listing( '182100001821', 1, 0, array( 'ListingType' => 'Chinese', 'Title' => 'Coin' ) ) );
$_POST = array( 'product_id' => '1821', 'ebay_item_id' => '182100001821' );
try {
	$GLOBALS['ADMIN']->ajax_link_ebay();
} catch ( Harness_Sent $sent ) {
	$linked = $sent->payload['success'] ?? null;
}
$_POST = array();
check( 'R3-L3 the shop cached "no auction"; the Link tool then links a product to an auction: the type, for that listing, and the shop marked as having one', array( $cached, $linked ?? null, meta( 1821, '_ebay_listing_type' ), meta( 1821, '_ebay_listing_type_item' ), get_transient( TCGiant_Sync_Inventory::AUCTIONS_TRANSIENT ) ), array( 'no', true, 'Chinese', '182100001821', 'yes' ) );
unset( $META[1820]['_ebay_listing_type_item'] );
ebay_has( '182000001820', listing( '182000001820', 1, 0, array( 'ListingType' => 'Chinese', 'SellingStatus' => array( 'BidCount' => '3' ) ) ) );
$WIRE = array();
hand_edit( 1820, 0 );
run_queue();
check( '  a product the backfill called fixed-price then goes to 0: read first, an auction with bids, kept', array( calls(), meta( 1820, '_ebay_listing_status' ) ), array( array( 'GetItem' ), 'Active' ) );

echo "\nR3-M2: NO TYPE eBAY NEVER GAVE\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
$UPSERTS = array();
$row     = listing( '183000001830', 2, 0, array( 'Title' => 'Card', 'SKU' => 'SKU-1830' ) );
unset( $row['ListingType'] );
$made = import_listing( $row );
check( 'R3-M2 an import row with no ListingType: no type recorded, and none written to the listings table', array( (string) meta( (int) $made, '_ebay_listing_type' ), meta( (int) $made, '_ebay_listing_type_item' ), array_key_exists( 'listing_type', end( $UPSERTS ) ?: array() ) ), array( '', null, false ) );

echo "\nROUND 3: MORE OF THE POOL, THE UNSENT UNITS AND THE TYPE\n" . str_repeat( '=', 132 ) . "\n";

// The paired variation without a SKU on eBay: its own push could not name it.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 1, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( '', 0, 0, array( 'size' => 'L' ) ) ) ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
run_queue();
check( 'R3-H2 L on the listing at 0, but with no SKU there for its own push to name: ended, and L named', array( meta( 1770, '_ebay_listing_status' ), logged( 'variation(s) #1773 (PL-L) keep stock of their own here, but eBay listing 177000001770 has no variation of theirs', 'warning' ) ), array( 'Ended', true ) );

// L keeps its own stock, but has none here either.
$pool_shop( 'PL-L', 'L' );
$META[1773]['_stock'] = 0;
ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 1, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 0, 0, array( 'size' => 'L' ) ) ) ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
run_queue();
check( 'R3-H2 L on the listing at 0, and 0 here too: nothing can keep the listing live - ended, no push of L', array( meta( 1770, '_ebay_listing_status' ), pushes() ), array( 'Ended', array() ) );

// A pooled variation eBay gave no QuantitySold for: nothing is worked out from it.
$sock_shop( 6 );
seen( 1701, '170000001700', 1, 'SOCK-S' );
$no_sold = $sock( 0, 0, 5, 6 );
unset( $no_sold['Variations']['Variation'][0]['SellingStatus'] );
import_listing( $no_sold );
check( 'R3-H1 a pooled variation eBay gave no QuantitySold for: the shared 6 and its record (1) left alone', array( stock( 1700 ), meta( 1701, '_ebay_sold_seen' )['sold'] ?? null ), array( 6, 1 ) );

// The unsent units, with eBay's own sales in its figure too.
$given_up( 1830, '183000001830' );
import_listing( listing( '183000001830', 3, 1 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1830' ) );
check( 'R3-L1 the seller lowers the listing and eBay sells one: eBay\'s 2 less the 1 unsent - 1, and the record moves on to eBay\'s sale', array( stock( 1830 ), meta( 1830, '_ebay_sold_seen' )['sold'] ?? null ), array( 1, 1 ) );

// A mark with no units recorded holds as a push on its way does.
fresh_shop();
product( 1832, 4, '183200001832', array( '_ebay_push_pending' => array( 'unsent' => time() + DAY_IN_SECONDS ) ) );
import_listing( listing( '183200001832', 2, 0 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1832' ) );
check( 'R3-L1 an unsent mark with no units recorded: the whole hold - 4 kept', stock( 1832 ), 4 );

// A variation keeping its own stock, with a sale of it that never reached eBay.
fresh_shop();
variable( 1834, '183400001834' );
variation( 1835, 1834, 'VAR-R', 4, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'VAR-R', '_ebay_push_pending' => array( 'unsent' => time() + DAY_IN_SECONDS ), '_ebay_unsent_qty' => 1 ) );
import_variations( 1834, array( array( 'sku' => 'VAR-R', 'stock_quantity' => 2, 'sold' => 0, 'attributes' => array( 'Colour' => 'Red' ) ) ) );
check( 'R3-L1 a variation\'s own sale never reached eBay, the seller lowers it to 2: eBay\'s 2 less the 1 - 1', stock( 1835 ), 1 );
$META[1835]['_stock'] = 4;
import_variations( 1834, array( array( 'sku' => 'VAR-R', 'stock_quantity' => 6, 'sold' => 0, 'attributes' => array( 'Colour' => 'Red' ) ) ) );
check( '  eBay at 6: 6 less 1 is not lower than its 4 - kept', stock( 1835 ), 4 );

// Link to a listing whose answer gave no type: no type, for this listing or the last.
fresh_shop();
product( 1840, 1, '', array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_type_item' => '184000000001' ) );
$no_type = listing( '184000001840', 1, 0, array( 'Title' => 'Card' ) );
unset( $no_type['ListingType'] );
ebay_has( '184000001840', $no_type );
$_POST = array( 'product_id' => '1840', 'ebay_item_id' => '184000001840' );
try {
	$GLOBALS['ADMIN']->ajax_link_ebay();
} catch ( Harness_Sent $sent ) {
	$linked = $sent->payload['success'] ?? null;
}
$_POST = array();
check( 'R3-L3 linked to a listing eBay gave no type for: the last listing\'s type is gone, and none is made up', array( $linked ?? null, meta( 1840, '_ebay_listing_type' ), meta( 1840, '_ebay_listing_type_item' ), array_key_exists( 'listing_type', end( $UPSERTS ) ?: array() ) ), array( true, null, null, false ) );

// ─────────────────────────────────────────────────────────────────────────────
// Fix round 4: what the re-verification of 177c253 found.
// ─────────────────────────────────────────────────────────────────────────────

echo "\nW4-M1: AN EBAY SALE TAKEN OFF THE SHARED STOCK GOES TO EBAY'S OTHER VARIATIONS\n" . str_repeat( '=', 132 ) . "\n";

$queued_jobs = function () { return array_map( function ( $a ) { return $a['args'][0]; }, pushes() ); };

// L18: the shared 10 on eBay for each, records made; eBay sells 3 S.
$sock_shop( 10 );
seen( 1701, '170000001700', 0, 'SOCK-S' );
seen( 1702, '170000001700', 0, 'SOCK-M' );
import_listing( $sock( 3, 0, 7, 10 ) );
$jobs = $queued_jobs();
check( 'W4-M1 L18 eBay sells 3 S of the shared 10, then the import: 10 to 7, and one push of the shared stock queued, as a sale\'s', array( stock( 1700 ), count( $jobs ), (int) ( $jobs[0]['product_id'] ?? 0 ), ! empty( $jobs[0]['ebay_sale'] ) ), array( 7, 1, 1700, true ) );
ebay_has( '170000001700', $sock( 3, 0, 7, 10 ), $sock( 3, 0, 7, 7 ) );
$WIRE = array();
fleet_used( 0.85 );
run_queue();
check( '  it goes past the 80% ceiling, as a sale does, and M is sent 7 - S already shows it', array( statuses( sent( 'ReviseInventoryStatus' ) ), pushes() ), array( array( 'SOCK-M' => 7 ), array() ) );
fleet_used( 0 );
next_request();
import_listing( $sock( 3, 0, 7, 7 ) );
check( '  the next import: eBay\'s answer is no new sale - the shared 7 stays, and nothing is queued', array( stock( 1700 ), pushes() ), array( 7, array() ) );

// Order sync's settle, the same.
$sock_shop( 10 );
seen( 1701, '170000001700', 0, 'SOCK-S' );
seen( 1702, '170000001700', 0, 'SOCK-M' );
ebay_has( '170000001700', $sock( 0, 2, 10, 8 ) );
$IMP->settle_item( '170000001700' );
$jobs = $queued_jobs();
check( '  order sync\'s settle of an eBay sale of 2 M: 10 to 8, and the push of 8 queued', array( stock( 1700 ), count( $jobs ), ! empty( $jobs[0]['ebay_sale'] ) ), array( 8, 1, true ) );

// Nothing changed: nothing queued.
$sock_shop( 6 );
seen( 1701, '170000001700', 0, 'SOCK-S' );
seen( 1702, '170000001700', 0, 'SOCK-M' );
import_listing( $sock( 0, 0, 6, 6 ) );
check( '  an import with no eBay sale of the pool: no push', pushes(), array() );

echo "\nW4-L1: THE UNSENT UNITS TAKEN OFF GO TO EBAY TOO\n" . str_repeat( '=', 132 ) . "\n";

// L20: L13 continued.
$given_up( 1860, '186000001860' );
import_listing( listing( '186000001860', 2, 0 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1860' ) );
$jobs = $queued_jobs();
check( 'W4-L1 L20 the import sets WooCommerce to eBay\'s 2 less the 1 unsent: 1, and the push that tells eBay is queued, as a sale\'s', array( stock( 1860 ), count( $jobs ), ! empty( $jobs[0]['ebay_sale'] ) ), array( 1, 1, true ) );
ebay_has( '186000001860', listing( '186000001860', 2, 0 ) );
$WIRE = array();
run_queue();
check( '  it lands: eBay 1, WooCommerce 1, and the unsent mark and its units go', array( qty_sent( sent( 'ReviseInventoryStatus' ) ), stock( 1860 ), in_array( 'unsent', array_keys( (array) meta( 1860, '_ebay_push_pending' ) ), true ), meta( 1860, '_ebay_unsent_qty' ) ), array( 1, 1, false, null ) );
$given_up( 1862, '186200001862' );
import_listing( listing( '186200001862', 5, 0 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1862' ) );
check( '  eBay still at its 5: WooCommerce\'s 4 kept, nothing queued, the mark holds', array( stock( 1862 ), pushes(), in_array( 'unsent', array_keys( (array) meta( 1862, '_ebay_push_pending' ) ), true ) ), array( 4, array(), true ) );
fresh_shop();
variable( 1864, '186400001864' );
variation( 1865, 1864, 'VAR-R', 4, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'VAR-R', '_ebay_push_pending' => array( 'unsent' => time() + DAY_IN_SECONDS ), '_ebay_unsent_qty' => array( 'sale-x' => 1 ) ) );
import_variations( 1864, array( array( 'sku' => 'VAR-R', 'stock_quantity' => 2, 'sold' => 0, 'attributes' => array( 'Colour' => 'Red' ) ) ) );
$jobs = $queued_jobs();
check( '  a variation keeping its own stock, the same: 1, and its push queued', array( stock( 1865 ), count( $jobs ), (int) ( $jobs[0]['product_id'] ?? 0 ) ), array( 1, 1, 1865 ) );

// N3 (release fix round 2): the sale check, with only the unsent mark, does
// as the import does - it held the stock up for the mark's 30 days.
$given_up( 1866, '186600001866' );
ebay_has( '186600001866', listing( '186600001866', 2, 0 ) );
$IMP->settle_item( '186600001866' );
$jobs = $queued_jobs();
check( 'N3 the sale check with only the unsent mark: eBay\'s 2 less the 1 unsent - 1, and the push that tells eBay queued, as a sale\'s', array( stock( 1866 ), count( $jobs ), ! empty( $jobs[0]['ebay_sale'] ) ), array( 1, 1, true ) );
ebay_has( '186600001866', listing( '186600001866', 2, 0 ) );
$WIRE = array();
run_queue();
check( '  it lands: eBay 1, WooCommerce 1, the unsent mark and its units gone', array( qty_sent( sent( 'ReviseInventoryStatus' ) ), stock( 1866 ), in_array( 'unsent', array_keys( (array) meta( 1866, '_ebay_push_pending' ) ), true ), meta( 1866, '_ebay_unsent_qty' ) ), array( 1, 1, false, null ) );
$given_up( 1868, '186800001868' );
ebay_has( '186800001868', listing( '186800001868', 5, 0 ) );
$IMP->settle_item( '186800001868' );
check( '  eBay still at its 5: WooCommerce\'s 4 kept, and the push that tells eBay queued all the same (release fix round 3)', array( stock( 1868 ), count( $queued_jobs() ), ! empty( $queued_jobs()[0]['ebay_sale'] ), in_array( 'unsent', array_keys( (array) meta( 1868, '_ebay_push_pending' ) ), true ) ), array( 4, 1, true, true ) );
fresh_shop();
variable( 1869, '186900001869' );
variation( 1870, 1869, 'VAR-R', 4, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'VAR-R', '_ebay_push_pending' => array( 'unsent' => time() + DAY_IN_SECONDS ), '_ebay_unsent_qty' => array( 'sale-x' => 1 ) ) );
ebay_has( '186900001869', variation_listing( '186900001869', array( ev( 'VAR-R', 2, 0, array( 'Colour' => 'Red' ) ) ) ) );
$IMP->settle_item( '186900001869' );
$jobs = $queued_jobs();
check( '  a variation keeping its own stock, the same: eBay\'s 2 less 1 - 1, and its push queued', array( stock( 1870 ), count( $jobs ), (int) ( $jobs[0]['product_id'] ?? 0 ) ), array( 1, 1, 1870 ) );
fresh_shop();
variable( 1869, '186900001869' );
variation( 1870, 1869, 'VAR-R', 4, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'VAR-R', '_ebay_push_pending' => array( 'unsent' => time() + DAY_IN_SECONDS ), '_ebay_unsent_qty' => array( 'sale-x' => 1 ) ) );
ebay_has( '186900001869', variation_listing( '186900001869', array( ev( 'VAR-R', 5, 0, array( 'Colour' => 'Red' ) ) ) ) );
$IMP->settle_item( '186900001869' );
check( '  and eBay still at its 5: the variation\'s 4 kept, its push queued all the same', array( stock( 1870 ), count( $queued_jobs() ), (int) ( $queued_jobs()[0]['product_id'] ?? 0 ) ), array( 4, 1, 1870 ) );

echo "\nC4-H1: A SALE THAT GAVE UP IS NO PUSH ON ITS WAY\n" . str_repeat( '=', 132 ) . "\n";

foreach ( array( 0, 2 ) as $l_here ) {
	$pool_shop( 'PL-L', 'L' );
	$META[1773]['_stock']             = $l_here;
	$META[1773]['_ebay_push_pending'] = array( 'unsent' => time() + 30 * DAY_IN_SECONDS );
	$META[1773]['_ebay_unsent_qty']   = array( 'sale-y' => 1 );
	ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 1, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 0, 0, array( 'size' => 'L' ) ) ) ) );
	// The pool's zero is refused; so, for good, is L's own push of its 2.
	$r = array( $no_zero );
	for ( $i = 0; $i < 12; $i++ ) {
		$r[] = refused( 21919999, 'Something about the variation.' );
	}
	ebay_answers( 'ReviseInventoryStatus', ...$r );
	hand_edit( 1770, 0 );
	run_queue();
	$jobs = $queued_jobs();
	if ( 0 === $l_here ) {
		check( 'C4-H1 L\'s sale push gave up (the unsent mark), L at 0 here: not waited for - ended in 4 calls', array( meta( 1770, '_ebay_listing_status' ), calls() ), array( 'Ended', array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ) ) );
	} else {
		check( '  the same with L at 2 here: not waited for - L\'s own push is queued (it is on the listing at 0) and sent, and the pool tried again after it', array( meta( 1770, '_ebay_listing_status' ), array_map( 'statuses', all_sent( 'ReviseInventoryStatus' ) ), count( array_filter( $jobs, function ( $j ) { return 1770 === (int) $j['product_id'] && ! empty( $j['own_queued'] ); } ) ) ), array( 'Active', array( array( 'PL-S' => 0 ), array( 'PL-L' => 2 ) ), 1 ) );
	}
}
// L's own push does not get there (refused for good); the pool's try again
// does not queue it a second time, and ends the listing.
$META[1773]['_ebay_push_pending'] = array();
delete_post_meta( 1773, '_ebay_push_running' );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
$WIRE = array();
foreach ( pushes() as $i => $a ) {
	if ( 1770 === (int) $a['args'][0]['product_id'] ) {
		$QUEUE = array( $a );
		break;
	}
}
run_queue( true, 1 );
check( '  L\'s own push never got there: the pool\'s try again does not queue it twice - ended, and L named', array( meta( 1770, '_ebay_listing_status' ), logged( 'their own push did not get it to eBay listing 177000001770', 'warning' ), count( array_filter( pushes(), function ( $a ) { return 1773 === (int) $a['args'][0]['product_id']; } ) ) ), array( 'Ended', true, 0 ) );
// The tail of a push already done is no push on its way either.
$pool_shop( 'PL-L', 'L' );
$META[1773]['_stock']             = 0;
$META[1773]['_ebay_push_pending'] = array( 'done-l' => time() + 600 );
ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 1, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 0, 0, array( 'size' => 'L' ) ) ) ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
run_queue();
check( '  L\'s last push landed ten minutes ago (its mark\'s tail): not waited for - ended', meta( 1770, '_ebay_listing_status' ), 'Ended' );

echo "\nC4-L1: THE HEARTBEAT, AND A GIVE-UP COUNTED ONCE\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 1870, 3, '187000001870' );
ebay_has( '187000001870', listing( '187000001870', 3, 0 ) );
website_order( 1871, array( 1870 => 1 ) );
$AT_REVISE = null;
$ON_CALL['ReviseInventoryStatus'] = function () {
	$GLOBALS['AT_REVISE'] = array( 'queue' => $GLOBALS['QUEUE'], 'meta' => $GLOBALS['META'] );
};
run_queue();
$beat_run = array_keys( (array) ( $AT_REVISE['meta'][1870]['_ebay_push_running'] ?? array() ) )[0] ?? '';
$mark     = $AT_REVISE['meta'][1870]['_ebay_push_running'][ $beat_run ] ?? array();
check( 'C4-L1 (a) the try notes the time just before its eBay calls, on its running mark', array( isset( $mark['beat'] ), abs( (int) ( $mark['beat'] ?? 0 ) - time() ) <= 2 ), array( true, true ) );
// As the host left it, killed during the Revise: the try began long ago, but
// its last call five minutes ago.
$QUEUE = $AT_REVISE['queue'];
$META  = $AT_REVISE['meta'];
$WIRE  = array();
$META[1870]['_ebay_push_running'][ $beat_run ]['started'] = time() - 2000;
$META[1870]['_ebay_push_running'][ $beat_run ]['beat']    = time() - 300;
run_queue( true );
check( '  its safety try: the last call was 5 minutes ago - still going, nothing sent, looked at again 16 minutes after that call', array( $WIRE, count( pushes() ), isset( pushes()[0] ) && in_about( pushes()[0], 660 ) ), array( array(), 1, true ) );
$META[1870]['_ebay_push_running'][ $beat_run ]['beat'] = time() - 1000;
$QUEUE[0]['when'] = time();
run_queue( true );
check( '  16 minutes after its last call: it is the next try', array( calls(), stock( 1870 ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), 2 ) );

// (b) The same push given up twice (two chains of tries): its units once.
fresh_shop();
product( 1872, 4, '187200001872' );
$DONE = new ReflectionMethod( 'TCGiant_Sync_Inventory', 'push_done' );
if ( PHP_VERSION_ID < 80100 ) {
	$DONE->setAccessible( true );
}
$META[1872]['_ebay_push_pending'] = array( 'sale-z' => time() + HOUR_IN_SECONDS );
$DONE->invoke( null, array( 'product_id' => 1872, 'reason' => 'order', 'qty' => 1, 'token' => 'sale-z' ), true, true );
$DONE->invoke( null, array( 'product_id' => 1872, 'reason' => 'order', 'qty' => 1, 'token' => 'sale-z' ), true, true );
check( '  (b) the same sale given up twice: 1 unit recorded, not 2', array_sum( (array) meta( 1872, '_ebay_unsent_qty' ) ), 1 );
$DONE->invoke( null, array( 'product_id' => 1872, 'reason' => 'order', 'qty' => 2, 'token' => 'sale-w' ), true, true );
check( '  another sale given up while the mark holds: added - 3', array_sum( (array) meta( 1872, '_ebay_unsent_qty' ) ), 3 );
import_listing( listing( '187200001872', 5, 0 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1872' ) );
check( '  and the import takes their sum off eBay\'s 5: 2', stock( 1872 ), 2 );

echo "\nROUND 4: MORE OF THE PUSH AFTER AN EBAY SALE\n" . str_repeat( '=', 132 ) . "\n";

// Pushes switched off: the stock is lowered, nothing is queued.
$sock_shop( 10 );
// "Send website stock changes to eBay?" at No, on a connected shop (3.28.0
// item 17: the old setting no longer switches pushes off).
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'push_stock_to_ebay' => '0', 'access_token' => 'harness-access', 'refresh_token' => 'harness-refresh' );
seen( 1701, '170000001700', 0, 'SOCK-S' );
seen( 1702, '170000001700', 0, 'SOCK-M' );
import_listing( $sock( 3, 0, 7, 10 ) );
check( 'W4-M1 with stock changes switched off: the shared stock comes down to 7, and nothing is queued', array( stock( 1700 ), pushes() ), array( 7, array() ) );
$given_up( 1866, '186600001866' );
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'push_stock_to_ebay' => '0', 'access_token' => 'harness-access', 'refresh_token' => 'harness-refresh' );
import_listing( listing( '186600001866', 2, 0 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1866' ) );
check( 'W4-L1 the same for the unsent units: 1, nothing queued', array( stock( 1866 ), pushes() ), array( 1, array() ) );

// Two lowerings close together: one push, which reads the stock when it runs.
$sock_shop( 10 );
seen( 1701, '170000001700', 0, 'SOCK-S' );
seen( 1702, '170000001700', 0, 'SOCK-M' );
import_listing( $sock( 3, 0, 7, 10 ) );
import_listing( $sock( 3, 1, 7, 9 ) );
check( 'W4-M1 two imports a moment apart, each taking an eBay sale off: 10 to 6, and one push queued', array( stock( 1700 ), count( pushes() ) ), array( 6, 1 ) );

// A variation keeping its own stock that the import keeps: nothing queued.
fresh_shop();
variable( 1868, '186800001868' );
variation( 1869, 1868, 'VAR-B', 4, array( 'Colour' => 'Blue' ), array( '_ebay_variation_sku' => 'VAR-B', '_ebay_push_pending' => array( 'unsent' => time() + DAY_IN_SECONDS ), '_ebay_unsent_qty' => array( 'sale-v' => 1 ) ) );
import_variations( 1868, array( array( 'sku' => 'VAR-B', 'stock_quantity' => 6, 'sold' => 0, 'attributes' => array( 'Colour' => 'Blue' ) ) ) );
check( 'W4-L1 a variation\'s unsent units, eBay at 6: its 4 kept, nothing queued', array( stock( 1869 ), pushes() ), array( 4, array() ) );

// ─────────────────────────────────────────────────────────────────────────────
// Fix round 5: what the re-verification of 24634d0 found.
// ─────────────────────────────────────────────────────────────────────────────

echo "\nW5-M1 / C5-H1: A VARIATION'S OWN PUSH FOR THE POOL'S 0 GOES AS A SALE\n" . str_repeat( '=', 132 ) . "\n";

$at_zero = function () { return variation_listing( '177000001770', array( ev( 'PL-S', 1, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 0, 0, array( 'size' => 'L' ) ) ) ); };
$l_back  = function ( $s ) { return variation_listing( '177000001770', array( ev( 'PL-S', $s, 0, array( 'size' => 'S' ) ), ev( 'PL-M', 0, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 2, 0, array( 'size' => 'L' ) ) ) ); };

// L22: past the 80% ceiling.
$pool_shop( 'PL-L', 'L' );
fleet_used( 0.85 );
ebay_has( '177000001770', $at_zero(), $at_zero(), $l_back( 1 ), $l_back( 0 ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
run_queue();
$own_job = null;
foreach ( $WIRE as $w ) {
	if ( 'ReviseInventoryStatus' === $w[0] && 'PL-L' === sku_in( $w[1] ) ) {
		$own_job = qty_sent( $w[1] );
	}
}
check( 'W5-M1 L22 past the 80% ceiling, the pool\'s 0 refused: L\'s own push goes as a sale does - L sent 2 today, not tomorrow', array( $own_job, logged( 'waits until' ) ), array( 2, false ) );
$WIRE = array();
run_queue( true );
check( '  the pool\'s try again zeroes S: S 0, L 2, the listing live', array( array_map( 'statuses', all_sent( 'ReviseInventoryStatus' ) ), meta( 1770, '_ebay_listing_status' ), pushes() ), array( array( array( 'PL-S' => 0 ) ), 'Active', array() ) );
fleet_used( 0 );

// A push of L's own parked for tomorrow is no push on its way for the pool.
$pool_shop( 'PL-L', 'L' );
$META[1773]['_ebay_push_pending'] = array( 'parked-l' => time() + 48 * HOUR_IN_SECONDS );
set_transient( 'tcgiant_push_tomorrow_1773', 1, DAY_IN_SECONDS );
ebay_has( '177000001770', $at_zero(), $at_zero(), $l_back( 1 ), $l_back( 0 ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
run_queue();
check( 'C5-H1 L has a push of its own parked for tomorrow: not waited for - its own push for the pool is queued and sent now', array( meta( 1770, '_ebay_listing_status' ), array_map( 'statuses', all_sent( 'ReviseInventoryStatus' ) ) ), array( 'Active', array( array( 'PL-S' => 0 ), array( 'PL-L' => 2 ) ) ) );

// The own push did not get there by the pool's next try (eBay's 518, a retry):
// the pool's 0 waits for it no longer - the listing ends.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', $at_zero() );
ebay_answers( 'ReviseInventoryStatus', $no_zero, refused( 518, 'Call usage limit has been reached.' ), $no_zero );
hand_edit( 1770, 0 );
run_queue();
check( 'C5-H1 L\'s own push meets eBay\'s 518 and waits to try again', array( meta( 1770, '_ebay_listing_status' ), count( pushes() ) ), array( 'Active', 2 ) );
$own_retry = array_values( array_filter( pushes(), function ( $a ) { return 1773 === (int) $a['args'][0]['product_id']; } ) );
check( '  that own push is a sale\'s, and names the pool it was queued for', array( ! empty( $own_retry[0]['args'][0]['ebay_sale'] ), (int) ( $own_retry[0]['args'][0]['pool_after'] ?? 0 ) ), array( true, 1770 ) );
$pool_retry = array_values( array_filter( pushes(), function ( $a ) { return 1770 === (int) $a['args'][0]['product_id']; } ) );
$QUEUE      = $pool_retry;
$WIRE       = array();
run_queue( true, 1 );
check( '  the pool\'s try again: L still at 0 on eBay, its own push still to come - waited for no longer, the listing ended, L named', array( calls(), meta( 1770, '_ebay_listing_status' ), logged( 'their own push did not get it to eBay listing 177000001770', 'warning' ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), 'Ended', true ) );

// The own push lands when nothing of the pool's is on its way: the pool's 0
// is tried once more.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', $l_back( 1 ) );
$INV->process_stock_push( array( 'product_id' => 1773, 'reason' => 'edit', 'ebay_sale' => 1, 'pool_after' => 1770, 'item' => '177000001770' ) );
$jobs = $queued_jobs();
check( 'W5-M1 L\'s own push lands with no push of the pool on its way: the pool\'s 0 is queued once more, as a sale', array( count( $jobs ), (int) ( $jobs[0]['product_id'] ?? 0 ), ! empty( $jobs[0]['ebay_sale'] ) ), array( 1, 1770, true ) );
$pool_shop( 'PL-L', 'L' );
$META[1770]['_ebay_push_pending'] = array( 'pool-live' => time() + 48 * HOUR_IN_SECONDS );
ebay_has( '177000001770', $l_back( 1 ) );
$INV->process_stock_push( array( 'product_id' => 1773, 'reason' => 'edit', 'ebay_sale' => 1, 'pool_after' => 1770, 'item' => '177000001770' ) );
check( '  with the pool\'s own try again on its way: nothing more queued', pushes(), array() );

// ─────────────────────────────────────────────────────────────────────────────
// Fix round 6: what the re-verification of 3eb7a06 found.
// ─────────────────────────────────────────────────────────────────────────────

echo "\nW6-L1 / C6-M1 / C6-L1: THE POOL WAITS FOR AN OWN PUSH STILL ON ITS WAY\n" . str_repeat( '=', 132 ) . "\n";

// The first queued push of one product, as one of two queue runners takes
// it; the rest stay queued.
$run_push_of = function ( $product_id ) {
	foreach ( $GLOBALS['QUEUE'] as $i => $a ) {
		if ( TCGiant_Sync_Inventory::PUSH_HOOK === $a['hook'] && (int) $product_id === (int) $a['args'][0]['product_id'] && empty( $a['args'][0]['safety'] ) ) {
			array_splice( $GLOBALS['QUEUE'], $i, 1 );
			TCGiant_Sync_Inventory::instance()->process_stock_push( $a['args'][0] );
			return $a['args'][0];
		}
	}
	return null;
};
$timeout  = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30000 milliseconds' );
$own_jobs = function () use ( $queued_jobs ) { return array_values( array_filter( $queued_jobs(), function ( $j ) { return 1773 === (int) $j['product_id']; } ) ); };
$s_and_l  = function () { return array( array_map( 'statuses', all_sent( 'ReviseInventoryStatus' ) ), meta( 1770, '_ebay_listing_status' ), pushes() ); };

// L22c: L's own push times out once; its try again would land 5 minutes later.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', $at_zero() );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
$run_push_of( 1770 );                                 // the pool's 0 refused: L's own push queued
$mark = null;
$ON_CALL['ReviseInventoryStatus'] = function () use ( &$mark ) {
	$mark = current( (array) meta( 1773, '_ebay_push_running' ) );
};
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
$run_push_of( 1773 );                                 // L's own push: no answer, to the API's three tries
$noted = array_values( (array) meta( 1773, '_ebay_own_push_tries' ) );
check( 'W6-L1 L22c L\'s own push times out once: its running mark carried its try, 1; the try noted for the pool, with its try again in 5 minutes', array( (int) ( $mark['attempt'] ?? 0 ), count( $noted ), (int) ( $noted[0]['failed'] ?? 0 ), abs( (int) ( $noted[0]['next'] ?? 0 ) - time() - 300 ) <= 2, (string) ( $noted[0]['stop'] ?? 'x' ), (int) ( $own_jobs()[0]['attempt'] ?? 0 ) ), array( 1, 1, 1, true, '', 2 ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
$WIRE = array();
$run_push_of( 1770 );                                 // the pool's try again, before L's
check( '  the pool\'s try again comes first, L still at 0 on eBay: its try again is due by the pool\'s next - waited for, the listing live', array( calls(), meta( 1770, '_ebay_listing_status' ), logged( 'has a change on its way, so listing 177000001770 is not ended' ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), 'Active', true ) );
ebay_answers( 'ReviseInventoryStatus' );
$run_push_of( 1773 );                                 // L's try again lands
ebay_has( '177000001770', $l_back( 1 ), $l_back( 0 ) );
$WIRE = array();
$run_push_of( 1770 );
check( '  L\'s try again lands, then the pool\'s next try zeroes S: S 0, L 2, the listing live, nothing queued', $s_and_l(), array( array( array( 'PL-S' => 0 ) ), 'Active', array() ) );

// C6-M1: L's own push is in its Revise when a second queue runner takes the
// pool's try again.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', $at_zero() );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
$run_push_of( 1770 );
$mid = null;
$ON_CALL['ReviseInventoryStatus'] = function () use ( $run_push_of, &$mid ) {
	$w0 = count( $GLOBALS['WIRE'] );
	$run_push_of( 1770 );
	$mid = array( array_slice( array_column( $GLOBALS['WIRE'], 0 ), $w0 ), meta( 1770, '_ebay_listing_status' ) );
};
ebay_answers( 'ReviseInventoryStatus', $no_zero );    // the pool's, during L's; L's own is taken
$run_push_of( 1773 );
check( 'C6-M1 L\'s own push is running (its Revise on its way) when the pool\'s try again comes round: waited for - nothing ended', $mid, array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), 'Active' ) );
ebay_has( '177000001770', $l_back( 1 ), $l_back( 0 ) );
$WIRE = array();
$run_push_of( 1770 );
check( '  L lands, and the pool\'s next try zeroes S: S 0, L 2, the listing live, nothing queued', $s_and_l(), array( array( array( 'PL-S' => 0 ) ), 'Active', array() ) );

// The pool's try again before L's own push has run at all: waited for.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', $at_zero() );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
$run_push_of( 1770 );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
$WIRE = array();
$run_push_of( 1770 );
check( '  the pool\'s try again before L\'s own push has run: waited for - nothing ended, L\'s push still queued', array( calls(), meta( 1770, '_ebay_listing_status' ), count( $own_jobs() ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), 'Active', 1 ) );

// C6-L1: L's own push fails two tries: the pool waits no longer.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', $at_zero() );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
$run_push_of( 1770 );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
$run_push_of( 1773 );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
$run_push_of( 1770 );                                 // one failure: waited for
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
$run_push_of( 1773 );                                 // the second
ebay_answers( 'ReviseInventoryStatus', $no_zero );
$WIRE = array();
$run_push_of( 1770 );
check( 'C6-L1 L\'s own push timed out twice: the pool\'s next try waits no longer - ended, L named', array( calls(), meta( 1770, '_ebay_listing_status' ), logged( 'their own push did not get it to eBay listing 177000001770', 'warning' ) ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), 'Ended', true ) );

// Refused, and the listing read back still shows L at 0: refused for good.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', $at_zero() );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
$run_push_of( 1770 );
ebay_answers( 'ReviseInventoryStatus', refused( 21919999, 'Something about the variation.' ) );
$run_push_of( 1773 );
$noted = array_values( (array) meta( 1773, '_ebay_own_push_tries' ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
$WIRE = array();
$run_push_of( 1770 );
check( '  L\'s own push refused, the listing read back with L at 0: a refusal for good - one try is enough, ended', array( (string) ( $noted[0]['stop'] ?? '' ), calls(), meta( 1770, '_ebay_listing_status' ) ), array( 'refused', array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), 'Ended' ) );

// What the pool reads, set as each state leaves it. Its times are made
// when the table is, and moved on by however long the states before took
// (each state's push can take seconds), so a state 959 s overdue stays so.
$ways  = array();
$built = time();
$shift = function ( $v, $d ) {
	if ( ! is_array( $v ) ) {
		return $v;
	}
	foreach ( array( 'next', 'started', 'beat', 'when', 'until', 'queued_at' ) as $k ) {
		if ( isset( $v[ $k ] ) ) {
			$v[ $k ] += $d;
		}
	}
	return $v;
};
foreach ( array(
	'not yet run'                                          => array( 2, null, null ),
	'running now, its first try'                           => array( 2, null, array( 'started' => time() - 30, 'beat' => time() - 10, 'when' => time() + 930, 'attempt' => 1 ) ),
	'running now, its third try (two failed)'              => array( 2, array( 'failed' => 2, 'next' => time() - 60, 'stop' => '' ), array( 'started' => time() - 30, 'when' => time() + 930, 'attempt' => 3 ) ),
	'running now, its third try, nothing noted'            => array( 2, null, array( 'started' => time() - 30, 'when' => time() + 930, 'attempt' => 3 ) ),
	'one failure, its try again in 5 minutes'              => array( 2, array( 'failed' => 1, 'next' => time() + 300, 'stop' => '' ), null ),
	'one failure, its try again in 2 hours'                => array( 2, array( 'failed' => 1, 'next' => time() + 7200, 'stop' => '' ), null ),
	'one failure, on the pool\'s last try'                 => array( 6, array( 'failed' => 1, 'next' => time() + 300, 'stop' => '' ), null ),
	'its first try ended by the host'                      => array( 2, null, array( 'started' => time() - 1200, 'beat' => time() - 1100, 'when' => time() - 240, 'attempt' => 1 ) ),
	'its second try ended by the host'                     => array( 2, array( 'failed' => 1, 'next' => time() - 1500, 'stop' => '' ), array( 'started' => time() - 1200, 'when' => time() - 240, 'attempt' => 2 ) ),
	'eBay\'s call limit'                                   => array( 2, array( 'failed' => 1, 'next' => time() + 300, 'stop' => 'call limit' ), null ),
	'a note of a push already over'                        => array( 2, array( 'failed' => 2, 'next' => time() - 60, 'stop' => 'refused' ), null, 'old-l' ),
	'a refusal noted for a push now in its tail'           => array( 2, array( 'failed' => 1, 'next' => time() - 60, 'stop' => 'refused' ), null, 'done-l', array( 'done-l' => time() + 60 ) ),
	'not yet run, on the pool\'s last try'                 => array( 6, null, null ),
	'running its second try, on the pool\'s last try'      => array( 6, array( 'failed' => 1, 'next' => time() - 60, 'stop' => '' ), array( 'started' => time() - 30, 'when' => time() + 930, 'attempt' => 2 ) ),
	'its second try still going, its last call 1 minute ago' => array( 2, array( 'failed' => 1, 'next' => time() - 1300, 'stop' => '' ), array( 'started' => time() - 1200, 'beat' => time() - 60, 'when' => time() - 240, 'attempt' => 2 ) ),
	'running its second try, its try again noted for later' => array( 2, array( 'failed' => 1, 'next' => time() + 7200, 'stop' => '' ), array( 'started' => time() - 30, 'when' => time() + 930, 'attempt' => 2 ) ),
	'queued 5 minutes ago, not yet run'                     => array( 2, null, null, null, array(), 300 ),
	'queued 20 minutes ago, never run, nothing noted'       => array( 2, null, null, null, array(), 1200 ),
	'queued 20 minutes ago, running now'                    => array( 2, null, array( 'started' => time() - 30, 'when' => time() + 930, 'attempt' => 1 ), null, array(), 1200 ),
	'queued 20 minutes ago, never run, beside a sale\'s unsent mark' => array( 2, null, null, null, array( 'unsent' => time() + 30 * DAY_IN_SECONDS ), 1200 ),
	// Fix round 8: a try again lost after one failure; the queue time from the entry itself.
	'one failure, its try again 959 s overdue, not run'     => array( 2, array( 'failed' => 1, 'next' => time() - 959, 'stop' => '' ), null ),
	'one failure, its try again 961 s overdue, not run'     => array( 2, array( 'failed' => 1, 'next' => time() - 961, 'stop' => '' ), null ),
	'one failure, its try again 961 s overdue, running now' => array( 2, array( 'failed' => 1, 'next' => time() - 961, 'stop' => '' ), array( 'started' => time() - 30, 'when' => time() + 930, 'attempt' => 2 ) ),
	'an entry from before round 8, queued 959 s ago'        => array( 2, null, null, null, array(), 959 ),
	'an entry from before round 8, queued 961 s ago'        => array( 2, null, null, null, array(), 961 ),
	'queued_at 959 s ago'                                   => array( 2, null, null, null, array(), 0, array( 'until' => time() + 48 * HOUR_IN_SECONDS - 959, 'queued_at' => time() - 959 ) ),
	'queued_at 961 s ago'                                   => array( 2, null, null, null, array(), 0, array( 'until' => time() + 48 * HOUR_IN_SECONDS - 961, 'queued_at' => time() - 961 ) ),
	'queued_at a minute ago, its until a writer\'s clock 20 minutes behind' => array( 2, null, null, null, array(), 0, array( 'until' => time() + 48 * HOUR_IN_SECONDS - 1260, 'queued_at' => time() - 60 ) ),
	'queued_at 20 minutes ago, its until a writer\'s clock 30 minutes ahead' => array( 2, null, null, null, array(), 0, array( 'until' => time() + 48 * HOUR_IN_SECONDS + 1800, 'queued_at' => time() - 1200 ) ),
) as $way => $state ) {
	$drift = time() - $built;
	$state[1] = $shift( $state[1], $drift );
	$state[2] = $shift( $state[2], $drift );
	if ( isset( $state[6] ) ) {
		$state[6] = $shift( $state[6], $drift );
	}
	foreach ( $state[4] ?? array() as $tk => $u ) {
		$state[4][ $tk ] = is_array( $u ) ? $shift( $u, $drift ) : $u + $drift;
	}
	$pool_shop( 'PL-L', 'L' );
	$META[1770]['_stock']             = 0;
	$META[1773]['_ebay_push_pending'] = array( 'own-l' => $state[6] ?? time() + 48 * HOUR_IN_SECONDS - ( $state[5] ?? 0 ) ) + ( $state[4] ?? array() );
	if ( $state[1] ) {
		$META[1773]['_ebay_own_push_tries'] = array( ( $state[3] ?? 'own-l' ) => $state[1] );
	}
	if ( $state[2] ) {
		$META[1773]['_ebay_push_running'] = array( 'run-l' => $state[2] );
	}
	ebay_has( '177000001770', $at_zero() );
	ebay_answers( 'ReviseInventoryStatus', $no_zero );
	$INV->process_stock_push( array( 'product_id' => 1770, 'reason' => 'edit', 'ebay_sale' => 1, 'attempt' => $state[0], 'own_queued' => 1, 'item' => '177000001770' ) );
	$ways[ $way ] = meta( 1770, '_ebay_listing_status' );
}
check( '  each state of L\'s own push, as the pool\'s try again finds it (fix rounds 6 and 7): Active (waited for) or Ended', $ways, array(
	'not yet run'                                          => 'Active',
	'running now, its first try'                           => 'Active',
	'running now, its third try (two failed)'              => 'Ended',
	'running now, its third try, nothing noted'            => 'Ended',
	'one failure, its try again in 5 minutes'              => 'Active',
	'one failure, its try again in 2 hours'                => 'Ended',
	'one failure, on the pool\'s last try'                 => 'Ended',
	'its first try ended by the host'                      => 'Active',
	'its second try ended by the host'                     => 'Ended',
	'eBay\'s call limit'                                   => 'Ended',
	'a note of a push already over'                        => 'Active',
	'a refusal noted for a push now in its tail'           => 'Active',
	'not yet run, on the pool\'s last try'                 => 'Ended',
	'running its second try, on the pool\'s last try'      => 'Ended',
	'its second try still going, its last call 1 minute ago' => 'Active',
	'running its second try, its try again noted for later' => 'Active',
	'queued 5 minutes ago, not yet run'                     => 'Active',
	'queued 20 minutes ago, never run, nothing noted'       => 'Ended',
	'queued 20 minutes ago, running now'                    => 'Active',
	'queued 20 minutes ago, never run, beside a sale\'s unsent mark' => 'Ended',
	'one failure, its try again 959 s overdue, not run'     => 'Active',
	'one failure, its try again 961 s overdue, not run'     => 'Ended',
	'one failure, its try again 961 s overdue, running now' => 'Active',
	'an entry from before round 8, queued 959 s ago'        => 'Active',
	'an entry from before round 8, queued 961 s ago'        => 'Ended',
	'queued_at 959 s ago'                                   => 'Active',
	'queued_at 961 s ago'                                   => 'Ended',
	'queued_at a minute ago, its until a writer\'s clock 20 minutes behind' => 'Active',
	'queued_at 20 minutes ago, its until a writer\'s clock 30 minutes ahead' => 'Ended',
) );

// ─────────────────────────────────────────────────────────────────────────────
// Fix round 7: what the re-check of 2494614 found.
// ─────────────────────────────────────────────────────────────────────────────

echo "\nW7-L1 / C7-M1: NO WAIT ON THE POOL'S LAST TRY; THE TRIES KEPT; A LOST OWN PUSH NOT WAITED FOR\n" . str_repeat( '=', 132 ) . "\n";

// W7-L1: L's own push times out twice, and every transient is dropped at
// each step (an object cache evicting them): its tries are still counted.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', $at_zero() );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
$run_push_of( 1770 );
$TRANSIENTS = array();
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
$run_push_of( 1773 );
$TRANSIENTS = array();
ebay_answers( 'ReviseInventoryStatus', $no_zero );
$run_push_of( 1770 );                                 // one failure: waited for
$TRANSIENTS = array();
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
$run_push_of( 1773 );                                 // the second
$TRANSIENTS = array();
$tries = array_values( (array) meta( 1773, '_ebay_own_push_tries' ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
$WIRE = array();
$run_push_of( 1770 );
check( 'W7-L1 L\'s own push times out twice, every transient dropped between: its tries are in post meta (2), and the pool\'s next try ends the listing, L named', array( count( $tries ), (int) ( $tries[0]['failed'] ?? 0 ), calls(), meta( 1770, '_ebay_listing_status' ), logged( 'their own push did not get it to eBay listing 177000001770', 'warning' ) ), array( 1, 2, array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), 'Ended', true ) );

// The tries are taken off when that push is over: it lands, or gives up.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', $at_zero() );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
$run_push_of( 1770 );
$META[1773]['_ebay_own_push_tries'] = array( 'old-x' => array( 'failed' => 1, 'next' => time() - 9000, 'stop' => '' ) );   // a push long over
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
$run_push_of( 1773 );
$had = count( (array) meta( 1773, '_ebay_own_push_tries' ) );
ebay_answers( 'ReviseInventoryStatus' );
$run_push_of( 1773 );
$landed_tries = meta( 1773, '_ebay_own_push_tries' );
$pool_shop( 'PL-L', 'L' );
$META[1773]['_ebay_push_pending']   = array( 'own-z' => time() + 48 * HOUR_IN_SECONDS );
$META[1773]['_ebay_own_push_tries'] = array( 'own-z' => array( 'failed' => 5, 'next' => time(), 'stop' => '' ) );
ebay_has( '177000001770', $at_zero() );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
$INV->process_stock_push( array( 'product_id' => 1773, 'reason' => 'edit', 'ebay_sale' => 1, 'pool_after' => 1770, 'item' => '177000001770', 'token' => 'own-z', 'attempt' => 6 ) );
check( '  its tries are taken off when it is over: noted after a failure (1 - the entry of a push long over pruned), gone once it lands; gone when it gives up', array( $had, $landed_tries, logged( 'Stock push for WC #1773 to eBay listing 177000001770 gave up', 'error' ), meta( 1773, '_ebay_own_push_tries' ) ), array( 1, null, true, null ) );

// The pool's last try waits for nothing, whatever L's own push says: the
// listing ends, and L is named.
$last = array();
foreach ( array(
	'L\'s own push queued a minute ago, not yet run' => array( 'own_queued' => 1 ),
	'L running its first try'                        => array( 'own_queued' => 1 ),
	'L at 0 on eBay, its own push never queued'      => array(),
) as $way => $extra ) {
	$pool_shop( 'PL-L', 'L' );
	$META[1770]['_stock'] = 0;
	if ( $extra ) {
		$META[1773]['_ebay_push_pending'] = array( 'own-l' => time() + 48 * HOUR_IN_SECONDS - 60 );
	}
	if ( false !== strpos( $way, 'running' ) ) {
		$META[1773]['_ebay_push_running'] = array( 'run-l' => array( 'started' => time() - 30, 'when' => time() + 930, 'attempt' => 1 ) );
	}
	ebay_has( '177000001770', $at_zero() );
	ebay_answers( 'ReviseInventoryStatus', $no_zero );
	$WIRE = array();
	$INV->process_stock_push( array( 'product_id' => 1770, 'reason' => 'edit', 'ebay_sale' => 1, 'attempt' => 6, 'item' => '177000001770' ) + $extra );
	$last[ $way ] = array( calls(), meta( 1770, '_ebay_listing_status' ), logged( 'their own push did not get it to eBay listing 177000001770', 'warning' ), count( $own_jobs() ) );
}
$ends = array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), 'Ended', true, 0 );
check( 'W7-L1 the pool\'s last try: nothing waited for - the listing ended and L named, and no own push queued with no try left after it', $last, array(
	'L\'s own push queued a minute ago, not yet run' => $ends,
	'L running its first try'                        => $ends,
	'L at 0 on eBay, its own push never queued'      => $ends,
) );

// C7-M1: L's own push's action never runs (cancelled in Scheduled Actions,
// or a fatal error before it began); its token stays live.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', $at_zero() );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
$run_push_of( 1770 );
$lost_job = $own_jobs()[0] ?? array();
$QUEUE    = array_values( array_filter( $QUEUE, function ( $a ) { return 1773 !== (int) $a['args'][0]['product_id']; } ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
$WIRE = array();
$run_push_of( 1770 );                                 // 5 minutes on: it could still be starting
$first = array( calls(), meta( 1770, '_ebay_listing_status' ) );
foreach ( (array) $META[1773]['_ebay_push_pending'] as $t => $u ) {
	$META[1773]['_ebay_push_pending'][ $t ]['queued_at'] -= 1200;  // queued 20 minutes ago (fix round 8: its entry says when)
}
ebay_answers( 'ReviseInventoryStatus', $no_zero );
$WIRE  = array();
$ended = $run_push_of( 1770 );
check( 'C7-M1 L\'s own push never runs: waited for at the pool\'s next try, 5 minutes on; 20 minutes on, no running mark and no try - lost: ended at that try (3 of 6), L named', array( $first, calls(), meta( 1770, '_ebay_listing_status' ), (int) ( $ended['attempt'] ?? 0 ), logged( 'their own push did not get it to eBay listing 177000001770', 'warning' ) ), array( array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), 'Active' ), array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), 'Ended', 3, true ) );

// It runs after all, once the listing has ended: it does not fight the end.
$gone = array_replace_recursive( $at_zero(), array( 'SellingStatus' => array( 'ListingStatus' => 'Completed' ) ) );
ebay_has( '177000001770', $gone );
$WIRE = array();
$INV->process_stock_push( $lost_job );
run_queue( true );
$writes = array_values( array_intersect( calls(), array( 'ReviseInventoryStatus', 'EndItem', 'RelistFixedPriceItem', 'RelistItem', 'AddFixedPriceItem' ) ) );
check( '  L\'s own push runs after the end: nothing written to eBay, nothing relisted, the listing still Ended, nothing left queued', array( $writes, meta( 1770, '_ebay_listing_status' ), pushes() ), array( array(), 'Ended', array() ) );

// ─────────────────────────────────────────────────────────────────────────────
// Fix round 8: what the re-checks of bc30cf4 found.
// ─────────────────────────────────────────────────────────────────────────────

echo "\nW8-L1 / C8-L1: A TRY AGAIN LOST AFTER ONE FAILURE; THE QUEUE TIME KEPT IN THE ENTRY\n" . str_repeat( '=', 132 ) . "\n";

// W8-L1 (L22i): L's own push times out once; its try again and its safety
// try are cancelled in Scheduled Actions.
$pool_shop( 'PL-L', 'L' );
ebay_has( '177000001770', $at_zero() );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
hand_edit( 1770, 0 );
$run_push_of( 1770 );
ebay_answers( 'ReviseInventoryStatus', $timeout, $timeout, $timeout );
$run_push_of( 1773 );
$QUEUE = array_values( array_filter( $QUEUE, function ( $a ) { return 1773 !== (int) $a['args'][0]['product_id']; } ) );
ebay_answers( 'ReviseInventoryStatus', $no_zero );
$WIRE = array();
$run_push_of( 1770 );                                 // its try again due in 5 minutes: waited for
$first = array( calls(), meta( 1770, '_ebay_listing_status' ) );
$tries = (array) meta( 1773, '_ebay_own_push_tries' );
foreach ( $tries as $tk => $row ) {
	$tries[ $tk ]['next'] = time() - 900 - 961;      // the clock moved on: due 31 minutes ago, never begun
}
$META[1773]['_ebay_own_push_tries'] = $tries;
ebay_answers( 'ReviseInventoryStatus', $no_zero );
$WIRE  = array();
$ended = $run_push_of( 1770 );
check( 'W8-L1 L22i L\'s own push fails once, its try again cancelled: waited for while due; once more than 16 minutes past due, not running - lost: ended at the pool\'s try 3, L named', array( $first, calls(), meta( 1770, '_ebay_listing_status' ), (int) ( $ended['attempt'] ?? 0 ), logged( 'their own push did not get it to eBay listing 177000001770', 'warning' ) ), array( array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), 'Active' ), array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), 'Ended', 3, true ) );

// C8-L1: the queue time is written into the entry, with the shared span.
$pool_shop( 'PL-L', 'L' );
$before = time();
hand_edit( 1773, 3 );
$entry = current( (array) meta( 1773, '_ebay_push_pending' ) );
check( 'C8-L1 a push queued: its pending entry holds when it was queued, and counts PUSH_PENDING_FOR from then', array( is_array( $entry ), abs( (int) ( $entry['queued_at'] ?? 0 ) - $before ) <= 2, (int) ( $entry['until'] ?? 0 ) - (int) ( $entry['queued_at'] ?? 0 ), TCGiant_Sync_Inventory::PUSH_PENDING_FOR ), array( true, true, 172800, 172800 ) );
$token = (string) key( (array) meta( 1773, '_ebay_push_pending' ) );
ebay_has( '177000001770', $l_back( 0 ) );
run_queue();
$tail = meta( 1773, '_ebay_push_pending' )[ $token ] ?? null;
check( '  once that push is over, its tail is a plain time again, within the quarter of an hour', array( is_int( $tail ), (int) $tail - time() <= 900, (int) $tail - time() > 800 ), array( true, true, true ) );

// The import's hold-back reads both kinds of entry.
$pool_shop( 'PL-L', 'L' );
$META[1773]['_ebay_push_pending'] = array( 'edit-x' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) );
$held_live = TCGiant_Sync_Mapper::push_pending( 1773 );
$META[1773]['_ebay_push_pending'] = array( 'edit-x' => array( 'until' => time() - 1, 'queued_at' => time() - 2 * DAY_IN_SECONDS ) );
$held_gone = TCGiant_Sync_Mapper::push_pending( 1773 );
$META[1773]['_ebay_push_pending'] = array( 'sale-x' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) );
$sale_live = TCGiant_Sync_Mapper::sale_hold( 1773 );
$META[1773]['_ebay_push_pending'] = array( 'sale-x' => array( 'until' => time() - 1, 'queued_at' => time() - 2 * DAY_IN_SECONDS ) );
$sale_gone = TCGiant_Sync_Mapper::sale_hold( 1773 );
check( '  an import reads the new entries as the old: a push on its way holds it back, one past its time does not; a sale\'s the same', array( $held_live, $held_gone, $sale_live, $sale_gone ), array( true, false, 'sale', null ) );

// Written back, other pushes' entries keep their queue time.
$pool_shop( 'PL-L', 'L' );
$META[1773]['_ebay_push_pending'] = array( 'kept' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() - 120 ) );
hand_edit( 1773, 3 );
$kept = (array) meta( 1773, '_ebay_push_pending' );
ebay_has( '177000001770', $l_back( 0 ) );
run_queue();
$after_done = (array) meta( 1773, '_ebay_push_pending' );
$META[1773]['_ebay_push_pending']['unsent'] = time() + DAY_IN_SECONDS;
TCGiant_Sync_Inventory::forget_unsent_sale( 1773 );
$after_unsent = (array) meta( 1773, '_ebay_push_pending' );
check( '  a second push queued beside one on its way: the first keeps its queue time - when the second is queued, when it is over, and when a sale\'s unsent mark goes', array( count( $kept ), (int) ( $kept['kept']['queued_at'] ?? 0 ) === time() - 120, (int) ( $after_done['kept']['queued_at'] ?? 0 ) === time() - 120, isset( $after_unsent['unsent'] ), (int) ( $after_unsent['kept']['queued_at'] ?? 0 ) === time() - 120 ), array( 2, true, true, false, true ) );


// ─────────────────────────────────────────────────────────────────────────────
// 3.28.0 release fix round 1: what the cross-item review of 27636ef found.
// ─────────────────────────────────────────────────────────────────────────────

echo "\nF2: A PUSH'S LAST TRY IN A LONG OUTAGE WAITS, AS ITEM D'S SALE CHECK DOES\n" . str_repeat( '=', 132 ) . "\n";

$rf1_timeout = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$rf1_last    = function () {
	// The sale's push, on its last try: every HTTP try of its revise times out.
	$job            = pushes()[0]['args'][0];
	$job['attempt'] = 6;
	$GLOBALS['QUEUE'] = array();
	return $job;
};

// eBay does not answer its clock either: the push's last delay again, uncounted.
fresh_shop();
product( 240, 3, '940000000940' );
ebay_has( '940000000940', listing( '940000000940', 3, 0 ) );
website_order( 620, array( 240 => 1 ) );
$job = $rf1_last();
ebay_answers( 'ReviseInventoryStatus', $rf1_timeout, $rf1_timeout, $rf1_timeout );
ebay_answers( 'GeteBayOfficialTime', $rf1_timeout, $rf1_timeout, $rf1_timeout );
$INV->process_stock_push( $job );
$again = pushes();
check( 'F2 a sale\'s last try times out, and eBay\'s clock does not answer: tried again in 6 hours, still its last try, from when it was queued', array( count( $again ), (int) ( $again[0]['args'][0]['attempt'] ?? 0 ), isset( $again[0] ) && in_about( $again[0], 21600 ), (int) ( $again[0]['args'][0]['since'] ?? 0 ) === (int) ( current( (array) meta( 240, '_ebay_push_pending' ) )['queued_at'] ?? -1 ), get_option( TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION, array() ) ), array( 1, 6, true, true, array() ) );
check( '  said, not given up', array( logged( 'on its last try. Trying again in 6 hours: eBay is not answering other calls either, not counted as a try.', 'warning' ), logged( 'gave up', 'error' ) ), array( true, false ) );
// eBay is back: it lands.
$WIRE = array();
ebay_answers( 'ReviseInventoryStatus' );
run_queue( true, 1 );
check( '  eBay back: the try again sends the sale, and nothing is left queued or given up', array( qty_sent( sent( 'ReviseInventoryStatus' ) ), pushes(), get_option( TCGiant_Sync_Inventory::PUSH_FAILURES_OPTION, array() ) ), array( 2, array(), array() ) );

// N1 (release fix round 2): its pending entry - the sale's hold on an
// import - lasts as long as the push waits, its queue time kept. Here its
// two days are all but over when the last try waits another 6 hours.
fresh_shop();
product( 244, 3, '944000000944' );
ebay_has( '944000000944', listing( '944000000944', 3, 0 ) );
website_order( 624, array( 244 => 1 ) );
$job   = $rf1_last();
$token = (string) $job['token'];
$queued_at = time() - 2 * DAY_IN_SECONDS + 600;
$META[244]['_ebay_push_pending'][ $token ] = array( 'until' => time() + 600, 'queued_at' => $queued_at );
ebay_answers( 'ReviseInventoryStatus', $rf1_timeout, $rf1_timeout, $rf1_timeout );
ebay_answers( 'GeteBayOfficialTime', $rf1_timeout, $rf1_timeout, $rf1_timeout );
$TRANSIENTS = array();
$INV->process_stock_push( $job );
$again = pushes();
$entry = meta( 244, '_ebay_push_pending' )[ $token ] ?? array();
check( 'N1 a push waiting past its pending entry\'s two days: the entry runs to its try again and the tail, its queue time kept', array( count( $again ), (int) ( $entry['until'] ?? 0 ) >= (int) ( $again[0]['when'] ?? PHP_INT_MAX ) + 900, (int) ( $entry['queued_at'] ?? 0 ) === $queued_at ), array( 1, true, true ) );

// eBay's clock answers: the failure is this listing's, given up as before.
fresh_shop();
product( 241, 3, '941000000941' );
ebay_has( '941000000941', listing( '941000000941', 3, 0 ) );
website_order( 621, array( 241 => 1 ) );
$job = $rf1_last();
ebay_answers( 'ReviseInventoryStatus', $rf1_timeout, $rf1_timeout, $rf1_timeout );
$INV->process_stock_push( $job );
check( '  eBay\'s clock answers: given up, as before, the sale kept as unsent', array( pushes(), logged( 'gave up after 6 tries', 'error' ), isset( meta( 241, '_ebay_push_pending' )['unsent'] ) ), array( array(), true, true ) );

// Three days on from when it was queued: given up, eBay answering or not.
fresh_shop();
product( 242, 3, '942000000942' );
ebay_has( '942000000942', listing( '942000000942', 3, 0 ) );
website_order( 622, array( 242 => 1 ) );
$job          = $rf1_last();
$job['since'] = time() - 3 * DAY_IN_SECONDS - 1;
ebay_answers( 'ReviseInventoryStatus', $rf1_timeout, $rf1_timeout, $rf1_timeout );
ebay_answers( 'GeteBayOfficialTime', $rf1_timeout, $rf1_timeout, $rf1_timeout );
$INV->process_stock_push( $job );
check( '  three days from when it was queued: given up', array( pushes(), logged( 'gave up after 6 tries', 'error' ) ), array( array(), true ) );

// No token: item 17's rules for a disconnected shop, not an outage.
fresh_shop();
product( 243, 3, '943000000943' );
ebay_has( '943000000943', listing( '943000000943', 3, 0 ) );
website_order( 623, array( 243 => 1 ) );
$job = $rf1_last();
ebay_answers( 'ReviseInventoryStatus', new WP_Error( 'no_token', 'No valid eBay token.' ) );
ebay_answers( 'GeteBayOfficialTime', $rf1_timeout, $rf1_timeout, $rf1_timeout );
$INV->process_stock_push( $job );
check( '  no token on its last try: not an outage - eBay\'s clock not asked, nothing waits', array( in_array( 'GeteBayOfficialTime', calls(), true ), count( array_filter( pushes(), function ( $a ) { return ! empty( $a['args'][0]['since'] ); } ) ) ), array( false, 0 ) );

echo "\nF4: ONE POOL PUSH FOR A PAIR OF EVENTS\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 250, 5, '950000000950' );
$META[250]['_ebay_push_pending'] = array( 'sale-250' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) );
TCGiant_Sync_Inventory::queue_after_ebay_sale( 250 );
$with_live = count( pushes() );
$TRANSIENTS = array();
set_transient( 'tcgiant_push_tomorrow_250', 1, DAY_IN_SECONDS );
TCGiant_Sync_Inventory::queue_after_ebay_sale( 250 );
$with_parked = count( pushes() );
$QUEUE = array();
$TRANSIENTS = array();
$META[250]['_ebay_push_pending'] = array();
TCGiant_Sync_Inventory::queue_after_ebay_sale( 250 );
$with_none = count( pushes() );
check( 'F4 an eBay sale\'s push after a settle: none while a sale\'s push of it is on its way; one while that push waits for tomorrow; one with none', array( $with_live, $with_parked, $with_none ), array( 0, 1, 1 ) );
// N4 (release fix round 2): an edit's push on its way - its try again may be hours off - does not hold it back.
$QUEUE = array();
$TRANSIENTS = array();
$META[250]['_ebay_push_pending'] = array( 'edit-250' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) );
TCGiant_Sync_Inventory::queue_after_ebay_sale( 250 );
check( 'N4 an edit\'s push of it on its way: the eBay sale\'s push is queued all the same', array( count( pushes() ), ! empty( pushes()[0]['args'][0]['ebay_sale'] ) ), array( 1, true ) );
// A sale's push that has landed - its entry only its tail - reads nothing more.
$QUEUE = array();
$TRANSIENTS = array();
$META[250]['_ebay_push_pending'] = array( 'sale-250' => array( 'until' => time() + TCGiant_Sync_Inventory::PUSH_PENDING_TAIL - 5, 'queued_at' => time() - 600 ) );
TCGiant_Sync_Inventory::queue_after_ebay_sale( 250 );
check( '  a sale\'s push that has landed, its entry only the tail: the eBay sale\'s push is queued', count( pushes() ), 1 );

// F1 (release fix round 2): order sync takes a shared variation's eBay sale
// off at once when the record's stamp - eBay's Timestamp on the read that
// wrote it - is older than the order, and queues the pool's push itself.
$pool_shop( 'PL-L', 'L' );
$META[1770]['_stock']          = 3;
$rf2_read                      = time() - 3600;
$META[1771]['_ebay_sold_seen'] = array( 'item' => '177000001770', 'sku' => 'PL-S', 'sold' => 0, 'at' => $rf2_read );
$EBAY['orders'] = array( 'orders' => array( array( 'orderId' => '27-0001', 'creationDate' => gmdate( 'Y-m-d\TH:i:s.000\Z', $rf2_read + 600 ), 'lineItems' => array( array( 'lineItemId' => 'RF2-1', 'sku' => 'PL-S', 'legacyItemId' => '177000001770', 'quantity' => 1 ) ) ) ) );
$IMP->sync_recent_orders();
$sale_pushes = array_values( array_filter( pushes(), function ( $a ) { return 1770 === (int) $a['args'][0]['product_id'] && ! empty( $a['args'][0]['ebay_sale'] ); } ) );
check( 'F1 order sync, the record\'s stamp older than the order: the shared 3 goes to 2 at once, S\'s record absorbs it, and the pool\'s push is queued as a sale\'s', array( stock( 1770 ), meta( 1771, '_ebay_sold_seen' )['sold'] ?? null, meta( 1771, '_ebay_sold_seen' )['at'] ?? null, count( $sale_pushes ), meta( 1771, '_ebay_order_processed_RF2-1' ) ? true : false ), array( 2, 1, $rf2_read, 1, true ) );
$IMP->sync_recent_orders();
check( '  order sync again: the line is marked, nothing more taken off', stock( 1770 ), 2 );
$QUEUE = array();
$TRANSIENTS = array();

// L1 (release fix round 3): another plugin's stock listener throws after the
// shared stock is written. S's record is written first, by a listener of the
// take's own at PHP_INT_MIN, so the check takes nothing more; the check is
// queued after the take, thrown past or not.
$pool_shop( 'PL-L', 'L' );
$META[1770]['_stock']          = 3;
$META[1771]['_ebay_sold_seen'] = array( 'item' => '177000001770', 'sku' => 'PL-S', 'sold' => 0, 'at' => $rf2_read );
$EBAY['orders'] = array( 'orders' => array( array( 'orderId' => '27-0002', 'creationDate' => gmdate( 'Y-m-d\TH:i:s.000\Z', $rf2_read + 600 ), 'lineItems' => array( array( 'lineItemId' => 'RF3-1', 'sku' => 'PL-S', 'legacyItemId' => '177000001770', 'quantity' => 1 ) ) ) ) );
$RF3_THROW = true;
add_action( 'woocommerce_product_set_stock', function () {
	if ( ! empty( $GLOBALS['RF3_THROW'] ) ) {
		$GLOBALS['RF3_THROW'] = false;
		$GLOBALS['RF3_SEEN']  = meta( 1771, '_ebay_sold_seen' )['sold'] ?? null;
		throw new RuntimeException( 'another plugin\'s stock hook failed' );
	}
}, 5 );
$rf3_died  = '';
$ON_QUEUE  = function ( $hook ) {
	if ( 'tcgiant_sync_settle_item' === $hook && ! isset( $GLOBALS['RF3_AT_QUEUE'] ) ) {
		$GLOBALS['RF3_AT_QUEUE'] = meta( 1771, '_ebay_sold_seen' )['sold'] ?? null;
	}
};
try {
	$IMP->sync_recent_orders();
} catch ( Throwable $e ) {
	$rf3_died = $e->getMessage();
}
unset( $GLOBALS['ON_QUEUE'] );
check( '  its check queued only after the take: the record already absorbed then', $RF3_AT_QUEUE ?? 'not queued', 1 );
$rf3_checks = count( array_filter( $QUEUE, function ( $a ) { return 'tcgiant_sync_settle_item' === $a['hook']; } ) );
check( 'RF3-L1 another plugin\'s stock listener throws after the write: the shared 3 is 2, S\'s record already absorbed when that listener ran, and its check is queued all the same', array( $rf3_died, stock( 1770 ), $RF3_SEEN ?? null, meta( 1771, '_ebay_sold_seen' )['sold'] ?? null, $rf3_checks ), array( 'another plugin\'s stock hook failed', 2, 1, 1, 1 ) );
$IMP->sync_recent_orders();
ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 3, 1, array( 'size' => 'S' ) ), ev( 'PL-M', 3, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 2, 0, array( 'size' => 'L' ) ) ) ) );
$IMP->settle_item( '177000001770' );
check( '  order sync again, then the check reading eBay\'s 1 sold: still 2, never 1', stock( 1770 ), 2 );
// Another plugin holds the shared stock back: the write takes nothing off, so
// the record is not touched, and the sale is the check's.
$pool_shop( 'PL-L', 'L' );
$META[1770]['_stock']          = 3;
$META[1771]['_ebay_sold_seen'] = array( 'item' => '177000001770', 'sku' => 'PL-S', 'sold' => 0, 'at' => $rf2_read );
$EBAY['orders'] = array( 'orders' => array( array( 'orderId' => '27-0003', 'creationDate' => gmdate( 'Y-m-d\TH:i:s.000\Z', $rf2_read + 600 ), 'lineItems' => array( array( 'lineItemId' => 'RF3-2', 'sku' => 'PL-S', 'legacyItemId' => '177000001770', 'quantity' => 1 ) ) ) ) );
$HELD_STOCK = array( 1770 );
$IMP->sync_recent_orders();
$HELD_STOCK = array();
check( '  another plugin holding the shared stock back: 3, the record untouched, left to the check', array( stock( 1770 ), meta( 1771, '_ebay_sold_seen' )['sold'] ?? null, logged( 'its stock check takes it off the shared stock' ) ), array( 3, 0, true ) );
// Its listener heard nothing, and is inert once the take is over, even where
// it could not be taken off the hook: a later lowering writes nothing for it.
hand_edit( 1770, 2 );
check( '  a hand edit lowering the shared stock afterwards: the take\'s listener writes nothing', meta( 1771, '_ebay_sold_seen' )['sold'] ?? null, 0 );
$QUEUE = array();
$TRANSIENTS = array();

// RF4-1 (release fix round 4): the sale check's own settle of the shared
// stock. Another plugin's stock listener throws after the write: S's record
// was written first, at the write, and the pool's push queued before it, so
// the next read takes nothing more and eBay's other variations are told.
$RF4_THROW_ON = '';
$RF4_SEEN     = null;
$rf4_thrower  = function ( $hook ) {
	return function () use ( $hook ) {
		if ( $hook === $GLOBALS['RF4_THROW_ON'] ) {
			$GLOBALS['RF4_THROW_ON'] = '';
			$GLOBALS['RF4_SEEN']     = meta( $GLOBALS['RF4_PEEK'] ?? 1771, '_ebay_sold_seen' )['sold'] ?? null;
			throw new RuntimeException( 'another plugin\'s hook failed' );
		}
	};
};
foreach ( array( 'woocommerce_updated_product_stock', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_update_product', 'woocommerce_update_product_variation' ) as $rf4_h ) {
	add_action( $rf4_h, $rf4_thrower( $rf4_h ), 5 );
}
foreach ( array( 'its stock listener' => 'woocommerce_product_set_stock', 'its save of the product (RF4-2)' => 'woocommerce_update_product', 'a listener on WooCommerce\'s data store action itself' => 'woocommerce_updated_product_stock' ) as $rf4_where => $rf4_hook ) {
	$pool_shop( 'PL-L', 'L' );
	$META[1770]['_stock']          = 3;
	$META[1771]['_ebay_sold_seen'] = array( 'item' => '177000001770', 'sku' => 'PL-S', 'sold' => 0, 'at' => $rf2_read );
	ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 3, 1, array( 'size' => 'S' ) ), ev( 'PL-M', 3, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 2, 0, array( 'size' => 'L' ) ) ) ) );
	$RF4_THROW_ON = $rf4_hook;
	$RF4_SEEN     = null;
	$rf4_died     = '';
	try {
		$IMP->settle_item( '177000001770' );
	} catch ( Throwable $e ) {
		$rf4_died = $e->getMessage();
	}
	$rf4_pushes = count( array_filter( pushes(), function ( $a ) { return 1770 === (int) $a['args'][0]['product_id'] && ! empty( $a['args'][0]['ebay_sale'] ); } ) );
	$rf4_mid    = array( $rf4_died, stock( 1770 ), $RF4_SEEN, $rf4_pushes );
	$IMP->settle_item( '177000001770' );
	check( sprintf( 'RF4-1 the sale check\'s settle of the shared stock, a throw in %s: 2, S\'s record already written when it threw, the pool\'s push already queued; the next read takes nothing more', $rf4_where ), array( $rf4_mid, stock( 1770 ) ), array( array( 'another plugin\'s hook failed', 2, 1, 1 ), 2 ) );
	$QUEUE      = array();
	$TRANSIENTS = array();
}
// RF4-2: order sync's take, a throw in WooCommerce's save of the product -
// before its stock action: the record was written right after the stock SQL.
$pool_shop( 'PL-L', 'L' );
$META[1770]['_stock']          = 3;
$META[1771]['_ebay_sold_seen'] = array( 'item' => '177000001770', 'sku' => 'PL-S', 'sold' => 0, 'at' => $rf2_read );
$EBAY['orders'] = array( 'orders' => array( array( 'orderId' => '27-0004', 'creationDate' => gmdate( 'Y-m-d\TH:i:s.000\Z', $rf2_read + 600 ), 'lineItems' => array( array( 'lineItemId' => 'RF4-1', 'sku' => 'PL-S', 'legacyItemId' => '177000001770', 'quantity' => 1 ) ) ) ) );
$RF4_THROW_ON = 'woocommerce_update_product';
$RF4_SEEN     = null;
$rf4_died     = '';
try {
	$IMP->sync_recent_orders();
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
check( 'RF4-2 order sync\'s take, a throw in WooCommerce\'s save of the product, before its stock action: 2, and S\'s record already written then', array( $rf4_died, stock( 1770 ), $RF4_SEEN, meta( 1771, '_ebay_sold_seen' )['sold'] ?? null ), array( 'another plugin\'s hook failed', 2, 1, 1 ) );
$RF4_THROW_ON = '';
$QUEUE        = array();
$TRANSIENTS   = array();

// RF4-1: the other sites, each a throw in the stock listener after its write.
// A simple product's sale check: eBay sold 1 of 3.
fresh_shop();
product( 1885, 3, '188500001885' );
seen( 1885, '188500001885', 0 );
ebay_has( '188500001885', listing( '188500001885', 3, 1 ) );
$RF4_THROW_ON = 'woocommerce_product_set_stock';
$RF4_PEEK     = 1885;
$RF4_SEEN     = null;
$rf4_died     = '';
try {
	$IMP->settle_item( '188500001885' );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$rf4_mid = array( $rf4_died, stock( 1885 ), $RF4_SEEN );
$IMP->settle_item( '188500001885' );
check( 'RF4-1 a simple product\'s sale check, a throw after its write: 2, its record already moved on to eBay\'s 1 sold; the next read takes nothing more', array( $rf4_mid, stock( 1885 ) ), array( array( 'another plugin\'s hook failed', 2, 1 ), 2 ) );
// A variation keeping its own stock: eBay sold 1 of its 3.
fresh_shop();
variable( 1886, '188600001886' );
variation( 1887, 1886, 'VAR-R', 3, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'VAR-R' ) );
seen( 1887, '188600001886', 0, 'VAR-R' );
ebay_has( '188600001886', variation_listing( '188600001886', array( ev( 'VAR-R', 3, 1, array( 'Colour' => 'Red' ) ) ) ) );
$RF4_THROW_ON = 'woocommerce_variation_set_stock';
$RF4_PEEK     = 1887;
$RF4_SEEN     = null;
$rf4_died     = '';
try {
	$IMP->settle_item( '188600001886' );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$rf4_mid = array( $rf4_died, stock( 1887 ), $RF4_SEEN );
$IMP->settle_item( '188600001886' );
check( '  a variation keeping its own stock, the same: 2, its record already moved on; the next read takes nothing more', array( $rf4_mid, stock( 1887 ) ), array( array( 'another plugin\'s hook failed', 2, 1 ), 2 ) );
// A website sale's push, reading eBay's sale WooCommerce had not taken off.
fresh_shop();
product( 1888, 5, '188800001888' );
seen( 1888, '188800001888', 0 );
ebay_has( '188800001888', listing( '188800001888', 5, 1 ), listing( '188800001888', 5, 1 ), listing( '188800001888', 3, 2 ) );
website_order( 1889, array( 1888 => 1 ) );
$rf4_job      = pushes()[0]['args'][0];
$QUEUE        = array();
$RF4_THROW_ON = 'woocommerce_product_set_stock';
$RF4_PEEK     = 1888;
$RF4_SEEN     = null;
$rf4_died     = '';
try {
	$INV->process_stock_push( $rf4_job );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$rf4_mid = array( $rf4_died, stock( 1888 ), $RF4_SEEN );
$INV->process_stock_push( $rf4_job );
check( '  a website sale\'s push reading eBay\'s sale first, a throw after its write: 3, the record already moved on; its try again takes nothing more', array( $rf4_mid, stock( 1888 ) ), array( array( 'another plugin\'s hook failed', 3, 1 ), 3 ) );
// A push whose answer shows an eBay sale WooCommerce had not taken off
// (correct_unseen(), R2-M6's refund): the same.
fresh_shop();
product( 1890, 4, '189000001890' );
seen( 1890, '189000001890', 0 );
$EBAY['sold'] = array( '189000001890' => 1 );
$order = new WC_Order( 1891, array( new WC_Order_Item_Product( 18911, 1890, 1 ) ) );
$order->get_items()[0]->meta = array( '_reduced_stock' => 1 );
$ORDERS[1891] = $order;
wc_increase_stock_levels( $order );
$rf4_job      = pushes()[0]['args'][0];
$QUEUE        = array();
$RF4_THROW_ON = 'woocommerce_product_set_stock';
$RF4_PEEK     = 1890;
$RF4_SEEN     = null;
$rf4_died     = '';
try {
	$INV->process_stock_push( $rf4_job );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$rf4_mid = array( $rf4_died, stock( 1890 ), $RF4_SEEN );
$INV->process_stock_push( $rf4_job );
check( '  a refund\'s push whose answer shows eBay\'s unseen sale, a throw after the lowering: 4, the record already moved on; its try again takes nothing more', array( $rf4_mid, stock( 1890 ) ), array( array( 'another plugin\'s hook failed', 4, 1 ), 4 ) );
// The pool's own push reading eBay's sale of S first (push_pool()).
$pool_shop( 'PL-L', 'L' );
$META[1770]['_stock'] = 5;
seen( 1771, '177000001770', 0, 'PL-S' );
seen( 1772, '177000001770', 0, 'PL-M' );
ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 5, 1, array( 'size' => 'S' ) ), ev( 'PL-M', 5, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 2, 0, array( 'size' => 'L' ) ) ) ) );
website_order( 1892, array( 1772 => 1 ) );
$rf4_job      = pushes()[0]['args'][0];
$QUEUE        = array();
$RF4_THROW_ON = 'woocommerce_product_set_stock';
$RF4_PEEK     = 1771;
$RF4_SEEN     = null;
$rf4_died     = '';
try {
	$INV->process_stock_push( $rf4_job );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$rf4_mid = array( $rf4_died, stock( 1770 ), $RF4_SEEN );
$INV->process_stock_push( $rf4_job );
check( '  the pool\'s push reading eBay\'s sale of S first, a throw after the lowering: 3, S\'s record already moved on; its try again takes nothing more', array( $rf4_mid, stock( 1770 ) ), array( array( 'another plugin\'s hook failed', 3, 1 ), 3 ) );
// A data store of another plugin's that does not fire
// woocommerce_updated_product_stock: the stock action still writes the
// record first.
$pool_shop( 'PL-L', 'L' );
$META[1770]['_stock']          = 3;
$META[1771]['_ebay_sold_seen'] = array( 'item' => '177000001770', 'sku' => 'PL-S', 'sold' => 0, 'at' => $rf2_read );
ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 3, 1, array( 'size' => 'S' ) ), ev( 'PL-M', 3, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 2, 0, array( 'size' => 'L' ) ) ) ) );
$NO_SQL_ACTION = true;
$RF4_THROW_ON  = 'woocommerce_product_set_stock';
$RF4_PEEK      = 1771;
$RF4_SEEN      = null;
$rf4_died      = '';
try {
	$IMP->settle_item( '177000001770' );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$NO_SQL_ACTION = false;
check( '  no woocommerce_updated_product_stock from the data store: the stock action writes S\'s record first, before the listener that throws', array( $rf4_died, stock( 1770 ), $RF4_SEEN ), array( 'another plugin\'s hook failed', 2, 1 ) );
// Absorbed once: absorbing is relative, so a second absorb would take in a
// sale this lowering did not. The website had already sold 2 of 9 that eBay
// does not know of; eBay sold 3 of its 10: lowered to 7, by 2, so the record
// moves to 2, not 3.
fresh_shop();
product( 1893, 9, '189300001893' );
seen( 1893, '189300001893', 0 );
ebay_has( '189300001893', listing( '189300001893', 10, 3 ) );
$IMP->settle_item( '189300001893' );
check( 'RF4-1 absorbed once: a simple product lowered from 9 to 7 by eBay\'s 3 sales - its record moves to 2, not 3', array( stock( 1893 ), meta( 1893, '_ebay_sold_seen' )['sold'] ?? null ), array( 7, 2 ) );
fresh_shop();
variable( 1894, '189400001894' );
variation( 1895, 1894, 'VAR-R', 9, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'VAR-R' ) );
seen( 1895, '189400001894', 0, 'VAR-R' );
ebay_has( '189400001894', variation_listing( '189400001894', array( ev( 'VAR-R', 10, 3, array( 'Colour' => 'Red' ) ) ) ) );
$IMP->settle_item( '189400001894' );
check( '  a variation keeping its own stock, the same: 7, and its record 2', array( stock( 1895 ), meta( 1895, '_ebay_sold_seen' )['sold'] ?? null ), array( 7, 2 ) );
// RF5-2 (release fix round 5): WooCommerce's product instance cache on, as
// for shops installed on WooCommerce 11.0 and later. It drops a product at
// priority 10 on its data store's stock action, so at PHP_INT_MIN
// wc_get_product() still gives the stock from before the write: the record
// is read from _stock meta, which is the stock now.
add_action( 'woocommerce_updated_product_stock', function ( $id ) { unset( $GLOBALS['PCACHE'][ (int) $id ] ); }, 10 );
foreach ( array( 'order sync\'s take' => 'take', 'the sale check\'s settle of the shared stock' => 'settle' ) as $rf5_what => $rf5_how ) {
	$pool_shop( 'PL-L', 'L' );
	$META[1770]['_stock']          = 3;
	$META[1771]['_ebay_sold_seen'] = array( 'item' => '177000001770', 'sku' => 'PL-S', 'sold' => 0, 'at' => $rf2_read );
	ebay_has( '177000001770', variation_listing( '177000001770', array( ev( 'PL-S', 3, 1, array( 'size' => 'S' ) ), ev( 'PL-M', 3, 0, array( 'size' => 'M' ) ), ev( 'PL-L', 2, 0, array( 'size' => 'L' ) ) ) ) );
	$EBAY['orders'] = array( 'orders' => array( array( 'orderId' => '27-0005', 'creationDate' => gmdate( 'Y-m-d\TH:i:s.000\Z', $rf2_read + 600 ), 'lineItems' => array( array( 'lineItemId' => 'RF5-' . $rf5_how, 'sku' => 'PL-S', 'legacyItemId' => '177000001770', 'quantity' => 1 ) ) ) ) );
	$PCACHE_ON    = true;
	$PCACHE       = array();
	$RF4_THROW_ON = 'woocommerce_update_product';
	$RF4_PEEK     = 1771;
	$RF4_SEEN     = null;
	$rf4_died     = '';
	try {
		if ( 'take' === $rf5_how ) {
			$IMP->sync_recent_orders();
		} else {
			$IMP->settle_item( '177000001770' );
		}
	} catch ( Throwable $e ) {
		$rf4_died = $e->getMessage();
	}
	$PCACHE_ON = false;
	$PCACHE    = array();
	check( sprintf( 'RF5-2 the product instance cache on, %s, a throw in WooCommerce\'s save: 2, and S\'s record already written then, from the stock as WooCommerce has just written it', $rf5_what ), array( $rf4_died, stock( 1770 ), $RF4_SEEN ), array( 'another plugin\'s hook failed', 2, 1 ) );
	$QUEUE      = array();
	$TRANSIENTS = array();
}

// RF5-1: the import's own save. It sets the stock to eBay's figure through
// the product's save, then writes the record: the record now goes in from
// WooCommerce's stock action in that save, before its other save hooks, once
// the stock holds eBay's figure - so a hook that throws there no longer
// leaves the next read to take eBay's sales again.
$SAVE_FIRES = true;
fresh_shop();
product( 1896, 5, '189600001896' );
seen( 1896, '189600001896', 0 );
$RF4_THROW_ON = 'woocommerce_update_product';
$RF4_PEEK     = 1896;
$RF4_SEEN     = null;
$rf4_died     = '';
try {
	import_listing( listing( '189600001896', 5, 2 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1896' ) );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$rf5_mid = array( $rf4_died, stock( 1896 ), $RF4_SEEN );
ebay_has( '189600001896', listing( '189600001896', 5, 2 ) );
$IMP->settle_item( '189600001896' );
check( 'RF5-1 the import of a simple product, a throw in its save: eBay\'s 3, the record already moved on to eBay\'s 2 sold; the next read takes nothing more', array( $rf5_mid, stock( 1896 ) ), array( array( 'another plugin\'s hook failed', 3, 2 ), 3 ) );
// Another plugin holds the save's stock back: not eBay's figure, so no record
// from the save - the throw stops the import before its own.
fresh_shop();
product( 1897, 5, '189700001897' );
seen( 1897, '189700001897', 0 );
$HELD_STOCK   = array( 1897 );
$RF4_THROW_ON = 'woocommerce_update_product';
$RF4_PEEK     = 1897;
$RF4_SEEN     = null;
$rf4_died     = '';
try {
	import_listing( listing( '189700001897', 5, 2 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1897' ) );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$HELD_STOCK = array();
check( '  another plugin holding the save\'s stock back, a throw in its save: 5, the record untouched', array( $rf4_died, stock( 1897 ), $RF4_SEEN, meta( 1897, '_ebay_sold_seen' )['sold'] ?? null ), array( 'another plugin\'s hook failed', 5, 0, 0 ) );
// A variation keeping its own stock.
fresh_shop();
variable( 1898, '189800001898' );
variation( 1899, 1898, 'VAR-R', 5, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'VAR-R' ) );
seen( 1899, '189800001898', 0, 'VAR-R' );
$RF4_THROW_ON = 'woocommerce_update_product_variation';
$RF4_PEEK     = 1899;
$RF4_SEEN     = null;
$rf4_died     = '';
try {
	import_variations( 1898, array( array( 'sku' => 'VAR-R', 'stock_quantity' => 3, 'sold' => 2, 'attributes' => array( 'Colour' => 'Red' ) ) ) );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$rf5_mid = array( $rf4_died, stock( 1899 ), $RF4_SEEN );
ebay_has( '189800001898', variation_listing( '189800001898', array( ev( 'VAR-R', 5, 2, array( 'Colour' => 'Red' ) ) ) ) );
$IMP->settle_item( '189800001898' );
check( '  the import of a variation keeping its own stock, a throw in its save: 3, its record already moved on to 2; the next read takes nothing more', array( $rf5_mid, stock( 1899 ) ), array( array( 'another plugin\'s hook failed', 3, 2 ), 3 ) );
fresh_shop();
variable( 1898, '189800001898' );
variation( 1899, 1898, 'VAR-R', 5, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'VAR-R' ) );
seen( 1899, '189800001898', 0, 'VAR-R' );
$HELD_STOCK   = array( 1899 );
$RF4_THROW_ON = 'woocommerce_update_product_variation';
$RF4_PEEK     = 1899;
$RF4_SEEN     = null;
$rf4_died     = '';
try {
	import_variations( 1898, array( array( 'sku' => 'VAR-R', 'stock_quantity' => 3, 'sold' => 2, 'attributes' => array( 'Colour' => 'Red' ) ) ) );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$HELD_STOCK = array();
check( '  and its save\'s stock held back by another plugin, a throw in its save: 5, its record untouched', array( $rf4_died, stock( 1899 ), $RF4_SEEN, meta( 1899, '_ebay_sold_seen' )['sold'] ?? null ), array( 'another plugin\'s hook failed', 5, 0, 0 ) );
// x22b: the stock held back and nothing thrown - the import's own write after
// the save leaves the record too, so a website sale's push still takes eBay's
// 2 sales off: 5 - 2 - 1 = 2, and eBay is sent 2.
$x22b_revised = function () {
	return array_values( array_map( function ( $w ) { return preg_match( '#<Quantity>(\d+)</Quantity>#', $w[1], $m ) ? (int) $m[1] : null; }, array_filter( $GLOBALS['WIRE'], function ( $w ) { return 'ReviseInventoryStatus' === $w[0]; } ) ) );
};
fresh_shop();
product( 1897, 5, '189700001897' );
seen( 1897, '189700001897', 0 );
$HELD_STOCK = array( 1897 );
import_listing( listing( '189700001897', 5, 2 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1897' ) );
$HELD_STOCK = array();
$x22b_mid   = array( stock( 1897 ), meta( 1897, '_ebay_sold_seen' )['sold'] ?? null );
next_request();
ebay_has( '189700001897', listing( '189700001897', 5, 2 ), listing( '189700001897', 5, 2 ) );
website_order( 18971, array( 1897 => 1 ) );
run_queue( true );
check( 'x22b another plugin holding the import\'s stock back, nothing thrown: 5, the record untouched; a website sale then: 2, and eBay sent 2', array( $x22b_mid, stock( 1897 ), $x22b_revised() ), array( array( 5, 0 ), 2, array( 2 ) ) );
fresh_shop();
variable( 1898, '189800001898' );
variation( 1899, 1898, 'VAR-R', 5, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'VAR-R' ) );
seen( 1899, '189800001898', 0, 'VAR-R' );
$HELD_STOCK = array( 1899 );
// From eBay, as the import's save of the listing runs it.
TCGiant_Sync_Inventory::begin_ebay_origin();
try {
	import_variations( 1898, array( array( 'sku' => 'VAR-R', 'stock_quantity' => 3, 'sold' => 2, 'attributes' => array( 'Colour' => 'Red' ) ) ) );
} finally {
	TCGiant_Sync_Inventory::end_ebay_origin();
}
$HELD_STOCK = array();
$x22b_mid   = array( stock( 1899 ), meta( 1899, '_ebay_sold_seen' )['sold'] ?? null );
next_request();
$x22b_vl = variation_listing( '189800001898', array( ev( 'VAR-R', 5, 2, array( 'Colour' => 'Red' ) ) ) );
ebay_has( '189800001898', $x22b_vl, $x22b_vl, $x22b_vl );
website_order( 18991, array( 1899 => 1 ) );
run_queue( true );
check( '  a variation keeping its own stock, the same: 5, its record untouched; a website sale then: 2, and eBay sent 2', array( $x22b_mid, stock( 1899 ), $x22b_revised() ), array( array( 5, 0 ), 2, array( 2 ) ) );
// A product new to the shop is not armed: the write after its save makes its
// record when the stock holds eBay's figure, and not when it was held back.
fresh_shop();
$x22b_new = (int) import_listing( listing( '189600001896', 5, 2 ) + array( 'Title' => 'New', 'SKU' => 'SKU-NEW-1896' ) );
check( '  a product new to the shop: eBay\'s 3, and its record made from eBay\'s 2 sold', array( $x22b_new >= 9000, stock( $x22b_new ), meta( $x22b_new, '_ebay_sold_seen' )['sold'] ?? null ), array( true, 3, 2 ) );
fresh_shop();
$HELD_STOCK = array( $NEXT_ID );
$x22b_new   = (int) import_listing( listing( '189600001896', 5, 2 ) + array( 'Title' => 'New', 'SKU' => 'SKU-NEW-1896' ) );
$HELD_STOCK = array();
check( '  and its stock held back by another plugin: no record', array( $x22b_new >= 9000, meta( $x22b_new, '_ebay_sold_seen' ) ), array( true, null ) );
// A variation new to the shop is not armed: the write after its save makes its
// record, as before.
fresh_shop();
variable( 1898, '189800001898' );
variation( 1899, 1898, 'VAR-R', 5, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'VAR-R' ) );
seen( 1899, '189800001898', 0, 'VAR-R' );
$x22b_new = import_variations( 1898, array(
	array( 'sku' => 'VAR-R', 'stock_quantity' => 5, 'sold' => 0, 'attributes' => array( 'Colour' => 'Red' ) ),
	array( 'sku' => 'VAR-B', 'stock_quantity' => 4, 'sold' => 1, 'attributes' => array( 'Colour' => 'Blue' ) ),
) );
$x22b_id  = (int) ( $x22b_new[1]['variation_id'] ?? 0 );
check( '  a variation new to the shop: its record made from eBay\'s 1 sold', array( $x22b_id > 1899, $x22b_id ? ( meta( $x22b_id, '_ebay_sold_seen' )['sold'] ?? null ) : null ), array( true, 1 ) );
fresh_shop();
variable( 1898, '189800001898' );
variation( 1899, 1898, 'VAR-R', 5, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'VAR-R' ) );
seen( 1899, '189800001898', 0, 'VAR-R' );
$HELD_STOCK = array( $NEXT_ID );
$x22b_new   = import_variations( 1898, array(
	array( 'sku' => 'VAR-R', 'stock_quantity' => 5, 'sold' => 0, 'attributes' => array( 'Colour' => 'Red' ) ),
	array( 'sku' => 'VAR-B', 'stock_quantity' => 4, 'sold' => 1, 'attributes' => array( 'Colour' => 'Blue' ) ),
) );
$HELD_STOCK = array();
$x22b_id    = (int) ( $x22b_new[1]['variation_id'] ?? 0 );
check( '  and its stock held back by another plugin: no record', array( $x22b_id > 1899, $x22b_id ? meta( $x22b_id, '_ebay_sold_seen' ) : 'none' ), array( true, null ) );
// A save that throws before WooCommerce fires its stock action: the record is
// written on the way out, once the stock holds eBay's figure.
fresh_shop();
product( 1900, 5, '190000001900' );
seen( 1900, '190000001900', 0 );
$SAVE_THROWS_EARLY = true;
$rf4_died          = '';
try {
	import_listing( listing( '190000001900', 5, 2 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1900' ) );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$SAVE_THROWS_EARLY = false;
check( '  a save throwing before WooCommerce\'s stock action: 3, the record written on the way out', array( $rf4_died, stock( 1900 ), meta( 1900, '_ebay_sold_seen' )['sold'] ?? null ), array( 'an earlier save hook failed', 3, 2 ) );
fresh_shop();
variable( 1902, '190200001902' );
variation( 1903, 1902, 'VAR-R', 5, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'VAR-R' ) );
seen( 1903, '190200001902', 0, 'VAR-R' );
$SAVE_THROWS_EARLY = true;
$rf4_died          = '';
try {
	import_variations( 1902, array( array( 'sku' => 'VAR-R', 'stock_quantity' => 3, 'sold' => 2, 'attributes' => array( 'Colour' => 'Red' ) ) ) );
} catch ( Throwable $e ) {
	$rf4_died = $e->getMessage();
}
$SAVE_THROWS_EARLY = false;
check( '  a variation\'s save throwing before WooCommerce\'s stock action: 3, its record written on the way out', array( $rf4_died, stock( 1903 ), meta( 1903, '_ebay_sold_seen' )['sold'] ?? null ), array( 'an earlier save hook failed', 3, 2 ) );
// A simple product whose lower stock the import keeps, a push on its way:
// its record is left as it is, as a variation's is.
fresh_shop();
product( 1901, 2, '190100001901' );
seen( 1901, '190100001901', 0 );
$META[1901]['_ebay_push_pending'] = array( 'edit-1901' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) );
import_listing( listing( '190100001901', 6, 1 ) + array( 'Title' => 'Lot', 'SKU' => 'SKU-1901' ) );
check( '  a simple product whose lower stock it keeps, a push on its way, keeps its record too', array( stock( 1901 ), meta( 1901, '_ebay_sold_seen' )['sold'] ?? null ), array( 2, 0 ) );
$SAVE_FIRES = false;

$RF4_THROW_ON = '';
$RF4_PEEK     = null;
$QUEUE        = array();
$TRANSIENTS   = array();
printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
