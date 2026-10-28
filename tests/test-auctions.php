<?php
/**
 * Harness: eBay auctions are not sold on the website.
 *
 * A card shop imported its eBay auctions. Each one became a product priced at
 * the current bid - which moved with every bid, since eBay reports each bid as
 * a change - and with Add to cart. A website buyer took a $37 card at its bid.
 * The plugin then either let it sell again on eBay, or ended the auction from
 * the stock push at 0, which cancels every bid. A coin shop pushing auctions
 * from WooCommerce had the same hole the other way round, and its stock pushes
 * sent ReviseInventoryStatus - a fixed-price call - to every auction.
 *
 * 3.28.0:
 * - A product on a running auction cannot be bought on the website. It shows
 *   the auction, with a link to it on eBay, where Add to cart was.
 * - An imported auction's price is its opening bid (StartPrice), which does
 *   not move, and which a push sends back unchanged.
 * - New auctions are imported only when "Import eBay auctions" is on. It is
 *   turned on once, at upgrade, for a shop that already had imported auctions.
 * - An auction is never sent a quantity. At 0 it is ended only when nobody has
 *   bid; with bids it is left running and the log says so. Nothing is recorded
 *   as ended unless eBay ended it.
 *
 * Loaded for real: TCGiant_Sync_Mapper, TCGiant_Sync_Inventory,
 * TCGiant_Sync_Importer, TCGiant_Sync_Runtime, TCGiant_Sync_Environment and
 * TCGiant_Sync_API; further down, the hourly check in TCGiant_Sync_Cron,
 * TCGiant_Sync_Listing_Link's list of keys and its Unlink, and the admin
 * notice and Link to this listing. WordPress
 * and WooCommerce are played here: products are rows of post meta, and the
 * database answers the plugin's queries from them.
 * eBay is the real API class with its calls answered from a script; what
 * reaches it is recorded. Nothing is copied from the plugin.
 *
 * The sections marked "fails on 3.27.2" do, on purpose: the auction is
 * imported at its current bid, can be bought, has stock pushed to it, and is
 * recorded as ended when eBay refused to end it.
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

// --- the shop the plugin runs in ---------------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$UPLOADS = sys_get_temp_dir() . '/tcg-auctions-harness-' . getmypid();
if ( ! is_dir( $UPLOADS ) ) {
	mkdir( $UPLOADS, 0777, true );
}

$POSTS      = array(); // id => array( 'type', 'status', 'product_type' )
$META       = array(); // id => array( key => value ): WooCommerce's own fields too
$OPTIONS    = array();
$TRANSIENTS = array();
$LOG        = array();
$ROWS       = array(); // TCGiant_Sync_DB::upsert() calls
$CREATED    = array(); // products made by save()
$WRITES     = array(); // every post meta and stock write: id, key
$QUEUE      = array();
$HOOKS      = array(); // add_action(): hook => [ callback, args ]
$FILTERS    = array(); // add_filter(): hook => [ callback, priority, args ]
$EBAY       = array();
$NEXT_ID    = 900;

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

/**
 * The database, as far as these paths ask it anything. Each query is answered
 * from the posts and their meta, by what its SQL says.
 */
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
			$value = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
		}, $query );
	}
	/** Posts whose meta matches the query's "meta_key = ... AND meta_value = ...". */
	private function matching( $query ) {
		if ( ! preg_match( "/meta_key = '([^']+)' AND (?:pm\\.)?meta_value = '([^']*)'/", $query, $m ) ) {
			return null;
		}
		$types = array( 'product', 'product_variation' );
		if ( false !== strpos( $query, "post_type = 'product'" ) ) {
			$types = array( 'product' );
		}
		$out = array();
		foreach ( $GLOBALS['POSTS'] as $id => $post ) {
			if ( (string) ( $GLOBALS['META'][ $id ][ $m[1] ] ?? "\0" ) !== $m[2] || ! in_array( $post['type'], $types, true ) ) {
				continue;
			}
			$status = $post['status'];
			if ( false !== strpos( $query, "post_status != 'trash'" ) && 'trash' === $status ) {
				continue;
			}
			if ( false !== strpos( $query, "NOT IN ( 'trash', 'auto-draft' )" ) && in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
				continue;
			}
			if ( false !== strpos( $query, "AND p.post_status = 'trash'" ) && 'trash' !== $status ) {
				continue;
			}
			if ( preg_match( '/post_id != (\d+)/', $query, $not ) && (int) $not[1] === (int) $id ) {
				continue;
			}
			$out[] = $id;
		}
		if ( false !== strpos( $query, "( p.post_status = 'trash' ) ASC" ) ) {
			usort( $out, function ( $a, $b ) {
				return (int) ( 'trash' === $GLOBALS['POSTS'][ $a ]['status'] ) - (int) ( 'trash' === $GLOBALS['POSTS'][ $b ]['status'] );
			} );
		}
		if ( preg_match( '/LIMIT (\d+)/', $query, $limit ) ) {
			$out = array_slice( $out, 0, (int) $limit[1] );
		}
		return $out;
	}
	public function get_row( $query ) {
		$ids = $this->matching( $query );
		if ( empty( $ids ) ) {
			return null;
		}
		return (object) array( 'post_id' => (string) $ids[0], 'post_status' => $GLOBALS['POSTS'][ $ids[0] ]['status'] );
	}
	public function get_var( $query ) {
		// The upgrade's count: products linked to an auction, never pushed,
		// not in the trash.
		if ( false !== strpos( $query, 'COUNT( DISTINCT p.ID )' ) && false !== strpos( $query, '_ebay_export_last_pushed' ) ) {
			$n = 0;
			foreach ( $GLOBALS['POSTS'] as $id => $post ) {
				$m = $GLOBALS['META'][ $id ] ?? array();
				if ( 'product' === $post['type'] && ! in_array( $post['status'], array( 'trash', 'auto-draft' ), true )
					&& 'Chinese' === ( $m['_ebay_listing_type'] ?? '' ) && '' !== (string) ( $m['_ebay_item_id'] ?? '' )
					&& ! array_key_exists( '_ebay_export_last_pushed', $m ) ) {
					$n++;
				}
			}
			return (string) $n;
		}
		$ids = $this->matching( $query );
		return empty( $ids ) ? null : (string) $ids[0];
	}
	public function get_col( $query ) {
		$ids = $this->matching( $query );
		return null === $ids ? array() : array_map( 'strval', $ids );
	}
	// Unlink's clean-up of per-order keys and of the Listings row: there are
	// none here.
	public function query( $query ) { return 0; }
	public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
	public function delete( $table, $where = array(), $format = null ) { return 0; }
	/**
	 * Lists of rows. Two are asked here: the upgrade's auction products listed
	 * from WooCommerce, one row per product, from the joins its SQL names; and
	 * the hourly check's products whose recorded end has passed. Anything else
	 * finds nothing.
	 */
	public function get_results( $query, $output = null ) {
		if ( preg_match( "/pm_end\\.meta_value < '([^']+)'/", $query, $now ) ) {
			$rows = array();
			foreach ( $GLOBALS['POSTS'] as $id => $post ) {
				$m = $GLOBALS['META'][ $id ] ?? array();
				if ( 'product' !== $post['type'] || 'trash' === $post['status'] || '' === (string) ( $m['_ebay_end_time'] ?? '' )
					|| (string) $m['_ebay_end_time'] >= $now[1] || 'Ended' === (string) ( $m['_ebay_listing_status'] ?? '' ) ) {
					continue;
				}
				$rows[] = (object) array( 'ID' => (string) $id, 'duration' => isset( $m['_ebay_listing_duration'] ) ? (string) $m['_ebay_listing_duration'] : null );
			}
			return $rows;
		}
		if ( preg_match( '/^\s*SELECT p\.ID AS id,/', $query ) ) {
			return $this->joined_rows( $query, $output );
		}
		return array();
	}
	/** Products that have each INNER JOIN's meta, and lack each LEFT JOIN's the WHERE wants absent. */
	private function joined_rows( $query, $output ) {
		preg_match_all( "/INNER JOIN \\S+ (\\w+) ON \\1\\.post_id = p\\.ID AND \\1\\.meta_key = '([^']+)'(?: AND \\1\\.meta_value (=|<>) '([^']*)')?/", $query, $inner, PREG_SET_ORDER );
		preg_match_all( "/LEFT JOIN \\S+ (\\w+) ON \\1\\.post_id = p\\.ID AND \\1\\.meta_key = '([^']+)'/", $query, $left, PREG_SET_ORDER );
		preg_match_all( '/(\w+)\.meta_value AS (\w+)/', $query, $columns, PREG_SET_ORDER );
		$keys = array();
		foreach ( array_merge( $inner, $left ) as $join ) {
			$keys[ $join[1] ] = $join[2];
		}
		$rows = array();
		foreach ( $GLOBALS['POSTS'] as $id => $post ) {
			$m = $GLOBALS['META'][ $id ] ?? array();
			if ( false !== strpos( $query, "p.post_type = 'product'" ) && 'product' !== $post['type'] ) {
				continue;
			}
			if ( false !== strpos( $query, "NOT IN ( 'trash', 'auto-draft' )" ) && in_array( $post['status'], array( 'trash', 'auto-draft' ), true ) ) {
				continue;
			}
			foreach ( $inner as $join ) {
				$op = $join[3] ?? '';
				if ( ! array_key_exists( $join[2], $m )
					|| ( '=' === $op && (string) $m[ $join[2] ] !== ( $join[4] ?? '' ) )
					|| ( '<>' === $op && (string) $m[ $join[2] ] === ( $join[4] ?? '' ) ) ) {
					continue 2;
				}
			}
			foreach ( $left as $join ) {
				if ( false !== strpos( $query, $join[1] . '.meta_id IS NULL' ) && array_key_exists( $join[2], $m ) ) {
					continue 2;
				}
			}
			$row = array( 'id' => (string) $id );
			foreach ( $columns as $column ) {
				$row[ $column[2] ] = isset( $keys[ $column[1] ] ) ? (string) ( $m[ $keys[ $column[1] ] ] ?? '' ) : null;
			}
			$rows[] = ARRAY_A === $output ? $row : (object) $row;
		}
		return $rows;
	}
}
$wpdb = new Harness_Wpdb();
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) { return true; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function _n( $single, $plural, $n ) { return 1 === (int) $n ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text ) { return esc_html( $text ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $url ) { return (string) $url; }
function esc_xml( $text ) { return htmlspecialchars( (string) $text, ENT_XML1, 'UTF-8' ); }
function wc_price( $amount ) { return '<span class="amount">$' . number_format( (float) $amount, 2, '.', '' ) . '</span>'; }
function wc_format_decimal( $number, $dp = false ) { return number_format( (float) $number, (int) $dp, '.', '' ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['HOOKS'][ $hook ][] = array( $callback, $accepted_args, $priority );
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['FILTERS'][ $hook ][] = array( $callback, $priority, $accepted_args );
	return true;
}
function apply_filters( $hook, $value ) { return $value; }
function did_action( $hook ) { return 0; }
function wp_clear_scheduled_hook() {}
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) {
	$ts = strtotime( $date . ' UTC' );
	return 'U' === $format ? (string) $ts : gmdate( $format, $ts );
}
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_convert_hr_to_bytes( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$bytes = (int) $value;
	if ( false !== strpos( $value, 'g' ) ) {
		$bytes *= 1073741824;
	} elseif ( false !== strpos( $value, 'm' ) ) {
		$bytes *= 1048576;
	} elseif ( false !== strpos( $value, 'k' ) ) {
		$bytes *= 1024;
	}
	return min( $bytes, PHP_INT_MAX );
}
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['UPLOADS'] ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function sanitize_title( $title ) { return strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $title ) ); }
function wc_attribute_label( $name ) { return $name; }
function wp_set_object_terms() { return array(); }
function has_term() { return false; }

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default;
}
// The settings' write-backs drop the request's cached copy first (3.28.0); this world keeps none.
function wp_cache_delete( $key, $group = '' ) { return true; }
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['OPTIONS'][ $name ] = $value;
	return true;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( ! array_key_exists( $name, $GLOBALS['OPTIONS'] ) ) {
		$GLOBALS['OPTIONS'][ $name ] = $value;
	}
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['OPTIONS'][ $name ] );
	return true;
}
function get_transient( $name ) { return $GLOBALS['TRANSIENTS'][ $name ] ?? false; }
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $name ] = $value; return true; }
function delete_transient( $name ) { unset( $GLOBALS['TRANSIENTS'][ $name ] ); return true; }

// Posts and their meta: one store, read by WooCommerce and the plugin alike.
function get_post_status( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['status'] ?? false; }
function get_post_type( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['type'] ?? false; }
function wp_get_post_parent_id( $id ) { return 0; }
function get_post_meta( $id, $key = '', $single = false ) {
	$meta = $GLOBALS['META'][ (int) $id ] ?? array();
	if ( ! array_key_exists( $key, $meta ) ) {
		return $single ? '' : array();
	}
	return $single ? $meta[ $key ] : array( $meta[ $key ] );
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['WRITES'][] = array( (int) $id, $key );
	$GLOBALS['META'][ (int) $id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	if ( isset( $GLOBALS['META'][ (int) $id ][ $key ] ) ) {
		$GLOBALS['WRITES'][] = array( (int) $id, '-' . $key );
	}
	unset( $GLOBALS['META'][ (int) $id ][ $key ] );
	return true;
}
function wp_trash_post( $id ) {
	$GLOBALS['POSTS'][ (int) $id ]['status'] = 'trash';
	return true;
}
function get_post_thumbnail_id( $id ) { return 0; }

// WooCommerce's product. Its fields live in the same meta the plugin reads
// directly; a set_*() is held until save().
class WC_Data_Exception extends Exception {}
class WC_Product {
	protected $id      = 0;
	protected $changes = array();
	protected $removed = array();
	protected $kind    = 'simple';
	public function __construct( $id = 0 ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_type() { return $this->kind; }
	public function is_type( $type ) { return $type === $this->kind; }
	protected function field( $key, $default = '' ) {
		if ( isset( $this->removed[ $key ] ) ) {
			return $default;
		}
		if ( array_key_exists( $key, $this->changes ) ) {
			return $this->changes[ $key ];
		}
		return $GLOBALS['META'][ $this->id ][ $key ] ?? $default;
	}
	public function get_meta( $key, $single = true ) { return $this->field( $key ); }
	public function update_meta_data( $key, $value ) {
		unset( $this->removed[ $key ] );
		$this->changes[ $key ] = $value;
	}
	public function delete_meta_data( $key ) {
		unset( $this->changes[ $key ] );
		$this->removed[ $key ] = true;
	}
	public function get_sku() { return (string) $this->field( '_sku' ); }
	public function set_sku( $sku ) {
		$holder = wc_get_product_id_by_sku( $sku );
		if ( '' !== (string) $sku && $holder && $holder !== $this->id ) {
			throw new WC_Data_Exception( 'Invalid or duplicated SKU.' );
		}
		$this->changes['_sku'] = (string) $sku;
	}
	public function get_name() { return (string) $this->field( '_name' ); }
	public function set_name( $name ) { $this->changes['_name'] = $name; }
	public function set_description( $text ) { $this->changes['_description'] = $text; }
	public function managing_stock() { return 'yes' === $this->field( '_manage_stock', 'no' ); }
	public function set_manage_stock( $on ) { $this->changes['_manage_stock'] = $on ? 'yes' : 'no'; }
	public function get_stock_quantity() { return $this->managing_stock() ? (int) $this->field( '_stock', 0 ) : null; }
	public function set_stock_quantity( $quantity ) { $this->changes['_stock'] = (int) $quantity; }
	public function get_stock_status() { return (string) $this->field( '_stock_status', 'instock' ); }
	public function is_in_stock() { return 'outofstock' !== $this->get_stock_status(); }
	public function get_regular_price() { return (string) $this->field( '_regular_price' ); }
	public function get_sale_price() { return (string) $this->field( '_sale_price' ); }
	public function get_price() { return '' !== $this->get_sale_price() ? $this->get_sale_price() : $this->get_regular_price(); }
	public function set_regular_price( $price ) { $this->changes['_regular_price'] = $price; }
	public function set_sale_price( $price ) { $this->changes['_sale_price'] = $price; }
	public function set_weight( $v ) { $this->changes['_weight'] = $v; }
	public function set_length( $v ) { $this->changes['_length'] = $v; }
	public function set_width( $v ) { $this->changes['_width'] = $v; }
	public function set_height( $v ) { $this->changes['_height'] = $v; }
	public function set_category_ids( $ids ) {}
	public function set_tag_ids( $ids ) {}
	public function set_status( $status ) { $this->changes['__status'] = $status; }
	public function get_attributes() { $a = $this->field( '_product_attributes', array() ); return is_array( $a ) ? $a : array(); }
	public function set_attributes( $attributes ) { $this->changes['_product_attributes'] = $attributes; }
	public function get_parent_id() { return 0; }
	public function save() {
		if ( ! $this->id ) {
			$this->id = $GLOBALS['NEXT_ID']++;
			$GLOBALS['POSTS'][ $this->id ] = array( 'type' => 'product', 'status' => 'publish', 'product_type' => $this->kind );
			$GLOBALS['CREATED'][] = $this->id;
		}
		foreach ( array_keys( $this->removed ) as $key ) {
			delete_post_meta( $this->id, $key );
		}
		$this->removed = array();
		foreach ( $this->changes as $key => $value ) {
			if ( '__status' === $key ) {
				$GLOBALS['POSTS'][ $this->id ]['status'] = $value;
				continue;
			}
			$GLOBALS['WRITES'][] = array( $this->id, $key );
			$GLOBALS['META'][ $this->id ][ $key ] = $value;
		}
		$this->changes = array();
		if ( $this->managing_stock() ) {
			$GLOBALS['META'][ $this->id ]['_stock_status'] = $this->get_stock_quantity() > 0 ? 'instock' : 'outofstock';
		}
		return $this->id;
	}
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variable extends WC_Product {
	protected $kind = 'variable';
}
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
	return 'variable' === ( $GLOBALS['POSTS'][ $id ]['product_type'] ?? 'simple' ) ? new WC_Product_Variable( $id ) : new WC_Product_Simple( $id );
}
/** WooCommerce's own lookup leaves the trash out. */
function wc_get_product_id_by_sku( $sku ) {
	if ( '' === (string) $sku ) {
		return 0;
	}
	foreach ( $GLOBALS['POSTS'] as $id => $post ) {
		if ( 'trash' !== $post['status'] && (string) ( $GLOBALS['META'][ $id ]['_sku'] ?? '' ) === (string) $sku ) {
			return $id;
		}
	}
	return 0;
}
function wc_update_product_stock( $product, $quantity = null, $operation = 'set' ) {
	$id = is_object( $product ) ? $product->get_id() : (int) $product;
	if ( null === $quantity || 'yes' !== ( $GLOBALS['META'][ $id ]['_manage_stock'] ?? '' ) ) {
		return null;
	}
	$GLOBALS['META'][ $id ]['_stock']        = (int) $quantity;
	$GLOBALS['META'][ $id ]['_stock_status'] = (int) $quantity > 0 ? 'instock' : 'outofstock';
	return (int) $quantity;
}
function wc_update_product_stock_status( $id, $status ) {
	$GLOBALS['META'][ (int) $id ]['_stock_status'] = $status;
}

// A website order, as far as the stock push after a sale reads one: each
// line as WooCommerce leaves it once it has taken the line's stock off,
// with what it took recorded on the line (_reduced_stock).
class WC_Order_Item_Product {
	private $product_id;
	private $meta;
	public function __construct( $product_id, $reduced = 1 ) { $this->product_id = (int) $product_id; $this->meta = array( '_reduced_stock' => $reduced ); }
	public function get_id() { return 7000 + $this->product_id; }
	public function get_product() { return wc_get_product( $this->product_id ); }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save_meta_data() {}
}
class Harness_Order {
	private $lines;
	public function __construct( array $product_ids ) {
		$this->lines = array_map( function ( $id ) { return new WC_Order_Item_Product( $id ); }, $product_ids );
	}
	public function get_id() { return 8100; }
	public function get_items() { return $this->lines; }
}

// Action Scheduler, as the stock push and the delta use it.
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	$GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group );
	return count( $GLOBALS['QUEUE'] );
}
function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) { return as_enqueue_async_action( $hook, $args, $group ); }
// Action Scheduler 3.0 and later: the next action with this hook and these
// arguments is taken off the queue (a stock push's safety try, 3.28.0).
function as_unschedule_action( $hook, $args = array(), $group = '' ) {
	foreach ( $GLOBALS['QUEUE'] as $i => $action ) {
		if ( $action['hook'] === $hook && $action['args'] === $args ) {
			array_splice( $GLOBALS['QUEUE'], $i, 1 );
			return $i;
		}
	}
	return null;
}
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['QUEUE'] as $action ) {
		if ( $action['hook'] === $hook && ( null === $args || $action['args'] === $args ) ) {
			return true;
		}
	}
	return false;
}
function as_get_scheduled_actions( $args ) { return array(); }
function as_unschedule_all_actions( $hook ) {
	$GLOBALS['QUEUE'] = array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) use ( $hook ) { return $a['hook'] !== $hook; } ) );
}
class ActionScheduler_Store {
	const STATUS_PENDING = 'pending';
}

// The plugin's neighbours.
class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
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
	public static function upsert( $data ) { $GLOBALS['ROWS'][] = $data; return true; }
	public static function table_exists() { return false; }
}
class TCGiant_Sync_Entitlements {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function can_import() { return true; }
	public function get_active_product_count() { return 0; }
	public function get_free_limit() { return 0; }
}
class TCGiant_Sync_Image_Localizer {
	public static function set_external_images() {}
}

// The live shop: the staging guard lets everything through.
function wp_load_alloptions() { return array( 'home' => 'https://cardshop.example.com', 'siteurl' => 'https://cardshop.example.com' ); }
function wp_get_environment_type() { return 'production'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
// "Import listings from" (3.27.5), which the mapper and the importer ask.
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
// Link's helper, which the mapper's re-link by SKU asks (3.27.5).
require_once $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require $ROOT . '/includes/class-tcgiant-sync-mapper.php';
require $ROOT . '/includes/class-tcgiant-sync-importer.php';

/**
 * eBay: the real API class, with GetItem and GetSellerEvents answered from a
 * script, and every Trading call and Inventory API request that would have
 * gone out recorded and answered from a script too.
 */
class Scripted_eBay extends TCGiant_Sync_API {
	public function get_item( $item_id ) {
		$GLOBALS['EBAY']['get_items'][] = (string) $item_id;
		// What eBay says once an EndItem has been sent for it, where scripted.
		if ( isset( $GLOBALS['EBAY']['after_end'][ (string) $item_id ] ) && in_array( 'EndItem', array_column( $GLOBALS['EBAY']['wire'], 0 ), true ) ) {
			return $GLOBALS['EBAY']['after_end'][ (string) $item_id ];
		}
		return $GLOBALS['EBAY']['items'][ (string) $item_id ] ?? new WP_Error( 'api_error', 'The item could not be found.' );
	}
	public function get_seller_events( $from, $to, $max_body_bytes = 0 ) {
		return array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $GLOBALS['EBAY']['events'] ) );
	}
	public function get_official_time() { return array( 'Ack' => 'Success' ); }
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$GLOBALS['EBAY']['wire'][] = array( $call_name, $xml_body );
		return $GLOBALS['EBAY']['answers'][ $call_name ] ?? array( 'Ack' => 'Success' );
	}
	// The Inventory API knows none of these listings, as for any listing made
	// through the Trading API - so a quantity goes on to ReviseInventoryStatus.
	public function request( $endpoint, $method = 'GET', $body = array(), $params = array(), $log_errors = true ) {
		$GLOBALS['EBAY']['wire'][] = array( 'REST ' . $method, $endpoint );
		return new WP_Error( 'api_error', 'Not found', array( 'errors' => array( array( 'errorId' => 25710 ) ) ) );
	}
}
$slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$slot->setAccessible( true );
}
$slot->setValue( null, new Scripted_eBay() );

$MAPPER = TCGiant_Sync_Mapper::instance();
$IMP    = TCGiant_Sync_Importer::instance();
$INV    = TCGiant_Sync_Inventory::instance();

// The pieces 3.28.0 adds, reached through these so that the same harness runs
// against 3.27.2 and reports each thing it gets wrong, rather than stopping at
// the first method it lacks. Without them a product is as WooCommerce left it.
define( 'NOTICE_OPTION', defined( 'TCGiant_Sync_Mapper::AUCTION_NOTICE_OPTION' ) ? TCGiant_Sync_Mapper::AUCTION_NOTICE_OPTION : 'tcgiant_auction_import_notice' );
function filter_purchasable( $in, $product ) {
	return method_exists( 'TCGiant_Sync_Inventory', 'filter_purchasable' ) ? TCGiant_Sync_Inventory::filter_purchasable( $in, $product ) : $in;
}
function filter_price_html( $html, $product ) {
	return method_exists( 'TCGiant_Sync_Inventory', 'filter_price_html' ) ? TCGiant_Sync_Inventory::filter_price_html( $html, $product ) : $html;
}
function render_auction_notice() {
	ob_start();
	if ( method_exists( 'TCGiant_Sync_Inventory', 'render_auction_notice' ) ) {
		TCGiant_Sync_Inventory::render_auction_notice();
	}
	return (string) ob_get_clean();
}
function upgrade_auction_import() {
	if ( method_exists( 'TCGiant_Sync_Mapper', 'upgrade_auction_import' ) ) {
		TCGiant_Sync_Mapper::upgrade_auction_import();
	}
}
function imports_auctions() {
	return method_exists( 'TCGiant_Sync_Mapper', 'imports_auctions' ) ? TCGiant_Sync_Mapper::imports_auctions() : null;
}

// --- driving it --------------------------------------------------------------

$OLD = '110000000111';
$NEW = '220000000222';

/**
 * A shop with nothing in it. The one-time upgrade step has already run and
 * found nothing to turn on, unless the settings say otherwise.
 */
function fresh_shop( array $settings = array(), $upgraded = true ) {
	$GLOBALS['POSTS']      = array();
	$GLOBALS['META']       = array();
	// Connected to eBay, as the OAuth stub's token says: a push is queued only
	// for a shop that holds both tokens (3.28.0).
	$GLOBALS['OPTIONS']    = array( 'tcgiant_sync_ebay_settings' => $settings + array( 'access_token' => 'harness-access', 'refresh_token' => 'harness-refresh' ) );
	if ( $upgraded ) {
		$GLOBALS['OPTIONS'][ NOTICE_OPTION ] = 'off';
	}
	$GLOBALS['TRANSIENTS'] = array();
	$GLOBALS['LOG']        = array();
	$GLOBALS['ROWS']       = array();
	$GLOBALS['CREATED']    = array();
	$GLOBALS['WRITES']     = array();
	$GLOBALS['QUEUE']      = array();
	$GLOBALS['EBAY']       = array( 'items' => array(), 'get_items' => array(), 'events' => array(), 'wire' => array(), 'answers' => array() );
}

/** A product. Meta given replaces the defaults. */
function product( $id, array $meta = array(), $status = 'publish' ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => 'product', 'status' => $status, 'product_type' => 'simple' );
	$GLOBALS['META'][ $id ]  = array_merge( array(
		'_name'         => 'Card #' . $id,
		'_manage_stock' => 'yes',
		'_stock'        => 1,
		'_stock_status' => 'instock',
	), $meta );
}

/** A product following eBay auction $item_id. */
function auction_product( $id, $item_id, array $meta = array() ) {
	product( $id, array_merge( array(
		'_sku'                 => $item_id,
		'_ebay_sku'            => $item_id,
		'_ebay_item_id'        => $item_id,
		'_ebay_listing_type'   => 'Chinese',
		'_ebay_listing_status' => 'Active',
		'_regular_price'       => '0.99',
	), $meta ) );
}

/** The fixture: a live auction at $37.00 after four bids, opened at $0.99. */
function auction( $item_id, array $over = array() ) {
	return array_replace_recursive( array(
		'ItemID'          => $item_id,
		'Title'           => 'Charizard 4/102 Holo PSA 8',
		'Quantity'        => '1',
		'ListingType'     => 'Chinese',
		'ListingDuration' => 'Days_7',
		'StartPrice'      => '0.99',
		'BuyItNowPrice'   => '90.00',
		'SellingStatus'   => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0', 'CurrentPrice' => '37.00', 'BidCount' => '4' ),
		'ListingDetails'  => array( 'StartTime' => '2026-10-10T10:00:00.000Z', 'EndTime' => '2026-10-17T10:00:00.000Z' ),
		'PictureDetails'  => array( 'PictureURL' => 'https://i.ebayimg.com/' . $item_id . '.jpg' ),
	), $over );
}

/** A fixed-price listing at $12.50. */
function fixed( $item_id, array $over = array() ) {
	return array_replace_recursive( array(
		'ItemID'          => $item_id,
		'Title'           => 'Pikachu 58/102 NM',
		'Quantity'        => '3',
		'ListingType'     => 'FixedPriceItem',
		'ListingDuration' => 'GTC',
		'StartPrice'      => '12.50',
		'SellingStatus'   => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0', 'CurrentPrice' => '12.50' ),
		'ListingDetails'  => array( 'StartTime' => '2026-10-10T10:00:00.000Z', 'EndTime' => '2026-11-09T10:00:00.000Z' ),
		'PictureDetails'  => array( 'PictureURL' => 'https://i.ebayimg.com/' . $item_id . '.jpg' ),
	), $over );
}

function ebay_has( $item_id, $item ) {
	$GLOBALS['EBAY']['items'][ $item_id ] = is_wp_error( $item ) ? $item : array( 'Ack' => 'Success', 'Item' => $item );
}

/** Import one listing as every import path does: map it, then save it. */
function import( array $item ) {
	$data = $GLOBALS['MAPPER']->map_ebay_to_woo( $item );
	return $GLOBALS['MAPPER']->save_as_product( $data );
}

function meta( $id, $key ) { return $GLOBALS['META'][ $id ][ $key ] ?? null; }
function stock( $id ) { return isset( $GLOBALS['META'][ $id ]['_stock'] ) ? (int) $GLOBALS['META'][ $id ]['_stock'] : null; }
function has_meta( $id, $key ) { return array_key_exists( $key, $GLOBALS['META'][ $id ] ?? array() ); }
function logged( $needle, $level = null ) {
	foreach ( $GLOBALS['LOG'] as $line ) {
		if ( false !== strpos( $line[1], $needle ) && ( null === $level || $level === $line[0] ) ) {
			return true;
		}
	}
	return false;
}
function log_lines_naming( $needle ) {
	return count( array_filter( $GLOBALS['LOG'], function ( $line ) use ( $needle ) { return false !== strpos( $line[1], $needle ); } ) );
}
function writes_to( $id ) {
	return array_values( array_filter( $GLOBALS['WRITES'], function ( $w ) use ( $id ) { return $w[0] === $id; } ) );
}
/** Trading calls and REST requests that reached eBay, by name. */
function wire_calls() { return array_column( $GLOBALS['EBAY']['wire'], 0 ); }
function settle_marks( $id ) { return array( meta( $id, '_ebay_stock_settled_at' ) !== null, meta( $id, '_ebay_stock_settled_item' ) ); }
function purchasable( $id, $in = true ) { return filter_purchasable( $in, wc_get_product( $id ) ); }
function iso( $ts ) { return gmdate( 'Y-m-d\TH:i:s.000\Z', $ts ); }

echo "\nA NEW AUCTION, WITH IMPORT EBAY AUCTIONS OFF (FAILS ON 3.27.2)\n" . str_repeat( '=', 134 ) . "\n";

// The card shop's $37 Charizard, four bids in. 3.27.2 made it a product at
// $37.00 with Add to cart.
fresh_shop();
$got = import( auction( $NEW ) );
check( 'the auction is not made into a product', $got, false );
check( '  it is an intended skip, not a failure', TCGiant_Sync_Mapper::$last_skipped, true );
check( '  nothing is created', $CREATED, array() );
check( '  nothing is written to any product', $WRITES, array() );
check( '  one line says so, naming the auction and the setting', array( log_lines_naming( $NEW ), logged( 'Import eBay auctions is off' ) ), array( 1, true ) );
// The next bid is the next change eBay reports.
$got = import( auction( $NEW, array( 'SellingStatus' => array( 'CurrentPrice' => '45.00', 'BidCount' => '5' ) ) ) );
check( 'the next bid: skipped again', array( $got, TCGiant_Sync_Mapper::$last_skipped, $CREATED ), array( false, true, array() ) );
check( '  without another line in the log', log_lines_naming( $NEW ), 1 );

// Explicitly off is the same as never set.
fresh_shop( array( 'import_auctions' => '0' ) );
check( 'set to No: skipped the same way', array( import( auction( $NEW ) ), $CREATED ), array( false, array() ) );

echo "\nAN AUCTION ALREADY LINKED TO A PRODUCT KEEPS SYNCING, AT ITS OPENING BID\n" . str_repeat( '=', 134 ) . "\n";

// A coin the shop pushed as an auction, found by its item number.
fresh_shop();
auction_product( 501, $NEW, array( '_regular_price' => '0.99', '_ebay_export_last_pushed' => '2026-10-10 10:00:00' ) );
$got = import( auction( $NEW ) );
check( 'the linked product is updated', $got, 501 );
check( '  not skipped', TCGiant_Sync_Mapper::$last_skipped, false );
check( '  its price is the opening bid, 0.99 - never the current bid (fails on 3.27.2)', meta( 501, '_regular_price' ), '0.99' );
check( '  and no sale price', meta( 501, '_sale_price' ), '' );
check( '  the current bid is kept beside it', meta( 501, '_ebay_current_price' ), '37.00' );
check( '  with the number of bids', meta( 501, '_ebay_bid_count' ), '4' );
check( '  no Buy It Now: four bids, and nothing from eBay says it is still offered', meta( 501, '_ebay_buy_it_now_price' ), '' );
check( '  and the opening bid itself', meta( 501, '_ebay_start_price' ), '0.99' );
check( '  the Listings row carries the opening bid too', ( end( $ROWS ) ?: array() )['ebay_price'] ?? null, 0.99 );

// Buy It Now that eBay says has gone.
import( auction( $NEW, array( 'ListingDetails' => array( 'BuyItNowAvailable' => 'false' ) ) ) );
check( 'Buy It Now that eBay says is no longer available is not offered', meta( 501, '_ebay_buy_it_now_price' ), '' );

// eBay's list of changes carries the bid but not the opening bid. The price
// stays what it is, rather than becoming the bid.
$brief = auction( $NEW, array( 'SellingStatus' => array( 'CurrentPrice' => '52.00', 'BidCount' => '6' ) ) );
unset( $brief['StartPrice'] );
import( $brief );
check( 'an answer with only the current bid leaves the price alone', meta( 501, '_regular_price' ), '0.99' );
check( '  but records the bid', array( meta( 501, '_ebay_current_price' ), meta( 501, '_ebay_bid_count' ) ), array( '52.00', '6' ) );
check( '  and keeps the opening bid it had', meta( 501, '_ebay_start_price' ), '0.99' );
check( '  the product\'s history says why', in_array( 'Price kept: eBay sent this auction\'s current bid, not its opening bid', meta( 501, '_tcgiant_sync_log' )[0]['decisions'] ?? array(), true ), true );

// Buy It Now once bidding has started. Only GetItem says whether it is still
// offered: eBay's list of changes and the full scan carry the price alone, and
// on an auction with no reserve the first bid takes Buy It Now away. A shopper
// sent to eBay for a Buy It Now that has gone finds none.
$row = auction( $NEW, array( 'SellingStatus' => array( 'CurrentPrice' => '55.00', 'BidCount' => '7' ) ) );
unset( $row['StartPrice'] );
import( $row );
check( 'a row from eBay\'s list of changes, with bids and no word on Buy It Now: not offered', meta( 501, '_ebay_buy_it_now_price' ), '' );
import( auction( $NEW, array( 'SellingStatus' => array( 'ReserveMet' => 'false' ) ) ) );
check( '  with bids, but eBay says the reserve is not met yet: still offered', meta( 501, '_ebay_buy_it_now_price' ), '90.00' );
import( auction( $NEW, array( 'SellingStatus' => array( 'ReserveMet' => 'true' ) ) ) );
check( '  with bids and the reserve met: not offered', meta( 501, '_ebay_buy_it_now_price' ), '' );
import( auction( $NEW, array( 'ListingDetails' => array( 'BuyItNowAvailable' => 'true' ) ) ) );
check( '  with bids, and eBay says it is still offered: offered', meta( 501, '_ebay_buy_it_now_price' ), '90.00' );
import( auction( $NEW, array( 'SellingStatus' => array( 'CurrentPrice' => '0.99', 'BidCount' => '0' ) ) ) );
check( '  before the first bid: offered', meta( 501, '_ebay_buy_it_now_price' ), '90.00' );

// A sale price never comes from an auction.
fresh_shop( array( 'import_auctions' => '1' ) );
$got = import( auction( $NEW, array( 'DiscountPriceInfo' => array( 'OriginalRetailPrice' => '150.00' ) ) ) );
check( 'an auction with an original retail price on it gets no sale price', array( meta( $got, '_regular_price' ), meta( $got, '_sale_price' ) ), array( '0.99', '' ) );

echo "\nA PRODUCT OF THE SHOP'S OWN WITH THE SAME SKU IS NOT TAKEN OVER\n" . str_repeat( '=', 134 ) . "\n";

// The shop's own Charizard, five in stock at $60, not linked to eBay. 3.27.2
// linked the auction to it: stock 1, price $37.
fresh_shop();
product( 502, array( '_sku' => 'CHZ-4', '_regular_price' => '60.00', '_stock' => 5 ) );
$got = import( auction( $NEW, array( 'SKU' => 'CHZ-4' ) ) );
check( 'not linked', array( $got, meta( 502, '_ebay_item_id' ) ), array( false, null ) );
check( '  its stock is still 5', stock( 502 ), 5 );
check( '  its price still 60.00', meta( 502, '_regular_price' ), '60.00' );
check( '  nothing written to it at all', writes_to( 502 ), array() );
check( '  no product created', $CREATED, array() );
check( '  the log names both', logged( 'eBay auction ' . $NEW . ' has the same SKU as WC #502' ), true );
check( '  once', array( import( auction( $NEW, array( 'SKU' => 'CHZ-4' ) ) ), log_lines_naming( $NEW ) ), array( false, 1 ) );

// Two live listings sharing a seller SKU: no "importing as a separate
// product" line for an auction that is not imported at all.
fresh_shop();
product( 504, array( '_sku' => 'CHZ-4', '_ebay_item_id' => '330000000333', '_ebay_listing_status' => 'Active' ) );
ebay_has( '330000000333', fixed( '330000000333' ) );
import( auction( $NEW, array( 'SKU' => 'CHZ-4' ) ) );
check( 'a SKU shared with a live listing: skipped, the other product untouched', array( $CREATED, meta( 504, '_ebay_item_id' ) ), array( array(), '330000000333' ) );
check( '  and no line claims it is imported as a separate product', logged( 'as a separate product' ), false );

echo "\nA RELIST OF A TRACKED LISTING IS STILL FOLLOWED\n" . str_repeat( '=', 134 ) . "\n";

// The seller's own SKU, on a product whose listing 111 has ended. The seller
// put the card back up as an auction, 222. The existing relink stands.
fresh_shop();
product( 505, array( '_sku' => 'CHZ-4', '_ebay_sku' => 'CHZ-4', '_ebay_item_id' => $OLD, '_ebay_listing_status' => 'Active', '_ebay_listing_type' => 'FixedPriceItem' ) );
ebay_has( $OLD, fixed( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed' ) ) ) );
$got = import( auction( $NEW, array( 'SKU' => 'CHZ-4' ) ) );
check( 'a seller-SKU product whose listing ended is moved on to the auction', array( $got, meta( 505, '_ebay_item_id' ) ), array( 505, $NEW ) );
check( '  as an auction', meta( 505, '_ebay_listing_type' ), 'Chinese' );

// Item 8: eBay names 111 as the listing 222 was relisted from, and 111's own
// record names 222 as its relist.
fresh_shop();
product( 506, array( '_sku' => $OLD, '_ebay_sku' => $OLD, '_ebay_item_id' => $OLD, '_ebay_listing_status' => 'Ended', '_ebay_listing_type' => 'Chinese', '_ebay_stock_settled_at' => '2026-10-09 10:00:00', '_ebay_stock_settled_item' => $OLD, '_ebay_quantity_listed' => 1, '_ebay_quantity_sold' => 0 ) );
ebay_has( $OLD, auction( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0' ), 'ListingDetails' => array( 'RelistedItemID' => $NEW ) ) ) );
$got = import( auction( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'an auction relisted on eBay is adopted by the product that followed the old one', array( $got, meta( 506, '_ebay_item_id' ) ), array( 506, $NEW ) );
check( '  not skipped', TCGiant_Sync_Mapper::$last_skipped, false );
check( '  and it cannot be bought here while it runs', purchasable( 506 ), false );

echo "\nA RELIST NOTHING MOVES ON TO, WITH IMPORT EBAY AUCTIONS OFF: DECIDED ONCE\n" . str_repeat( '=', 134 ) . "\n";

// The coin shop pushes its auctions, so the setting is off. Its auction 111
// sold its only coin; the seller used Relist on it for the next coin, 222.
// eBay reports every bid on 222 as a change, and 222 never becomes a product
// for a later import to find - so each bid asked eBay about 111 again and
// logged that 222 "is imported as a product of its own", which it is not.
fresh_shop();
auction_product( 580, $OLD, array( '_ebay_export_last_pushed' => '2026-10-01 10:00:00', '_ebay_auction_listed_item' => $OLD, '_ebay_listing_status' => 'Ended', '_stock' => 0, '_stock_status' => 'outofstock' ) );
ebay_has( $OLD, auction( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ), 'ListingDetails' => array( 'RelistedItemID' => $NEW ) ) ) );
for ( $bid = 1; $bid <= 4; $bid++ ) {
	import( auction( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'BidCount' => (string) $bid ) ) ) );
}
check( 'four bids on a declined relist: no product made, the sold coin stays on 111', array( $CREATED, meta( 580, '_ebay_item_id' ), stock( 580 ) ), array( array(), $OLD, 0 ) );
check( '  eBay is asked about 111 once, not on every bid', count( array_keys( $EBAY['get_items'], $OLD, true ) ), 1 );
check( '  the decline is said once', log_lines_naming( 'sold its only item' ), 1 );
check( '  saying the auction is not imported, and why', logged( $NEW . ' is not imported (it is an auction, and Import eBay auctions is off); WC #580 stays on listing ' . $OLD ), true );
check( '  and never that it is imported as a product of its own', logged( 'is imported as a product of its own' ), false );

// The shop turns Import eBay auctions on: the relist is looked at afresh,
// and now becomes a product of its own.
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'import_auctions' => '1' ) + array( 'access_token' => 'harness-access', 'refresh_token' => 'harness-refresh' );
$got = import( auction( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'BidCount' => '5' ) ) ) );
check( 'Import eBay auctions turned on: 111 is asked about again', count( array_keys( $EBAY['get_items'], $OLD, true ) ), 2 );
check( '  and 222 is imported as a product of its own', array( $got > 0, $CREATED === array( $got ), meta( 580, '_ebay_item_id' ) ), array( true, true, $OLD ) );
check( '  as the log says', logged( $NEW . ' is imported as a product of its own; WC #580 stays on listing ' . $OLD ), true );

// A Sell Similar copy of a coin still at auction: warned about once.
fresh_shop();
auction_product( 581, $OLD, array( '_ebay_export_last_pushed' => '2026-10-01 10:00:00', '_ebay_auction_listed_item' => $OLD ) );
ebay_has( $OLD, auction( $OLD ) );
for ( $bid = 1; $bid <= 3; $bid++ ) {
	import( auction( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'BidCount' => (string) $bid ) ) ) );
}
check( 'a Sell Similar copy of a live auction, three bids: eBay asked about the original once', count( array_keys( $EBAY['get_items'], $OLD, true ) ), 1 );
check( '  and one warning', count( array_filter( $LOG, function ( $l ) { return 'warning' === $l[0] && false !== strpos( $l[1], 'still live on eBay' ); } ) ), 1 );

// The first time, eBay could not be reached about 111. That is not a
// verdict, so the next bid asks again - and the relist, which is the same
// coin back up unsold, is moved on to.
fresh_shop();
auction_product( 582, $OLD, array( '_ebay_export_last_pushed' => '2026-10-01 10:00:00', '_ebay_auction_listed_item' => $OLD, '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-10-09 10:00:00', '_ebay_stock_settled_item' => $OLD, '_ebay_quantity_listed' => 1, '_ebay_quantity_sold' => 0 ) );
ebay_has( $OLD, new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
$got = import( auction( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'BidCount' => '1' ) ) ) );
check( 'the old listing could not be read: nothing moved, nothing made', array( $got, meta( 582, '_ebay_item_id' ), $CREATED ), array( false, $OLD, array() ) );
check( '  and that is not remembered', get_transient( 'tcgiant_relist_declined_' . $NEW ), false );
ebay_has( $OLD, auction( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0' ), 'ListingDetails' => array( 'RelistedItemID' => $NEW ) ) ) );
$got = import( auction( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'BidCount' => '2' ) ) ) );
check( '  the next bid asks again, and the product moves on to the relist', array( $got, meta( 582, '_ebay_item_id' ) ), array( 582, $NEW ) );
check( '  it is still the shop\'s own auction', meta( 582, '_ebay_auction_listed_item' ), $NEW );

// Out of calls: eBay was never asked. Tried again later, not remembered.
fresh_shop();
auction_product( 583, $OLD, array( '_ebay_export_last_pushed' => '2026-10-01 10:00:00', '_ebay_listing_status' => 'Ended' ) );
ebay_has( $OLD, new WP_Error( 'rate_limited', 'Daily call budget reached.', array( 'daily_budget' => true ) ) );
$got = import( auction( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'out of eBay calls: put off for later, not remembered as declined', array( $got, TCGiant_Sync_Mapper::$last_deferred instanceof WP_Error, get_transient( 'tcgiant_relist_declined_' . $NEW ) ), array( false, true, false ) );

// Two products on 111: the shop is to unlink one, which changes the answer,
// so it is said again on the next bid rather than remembered.
fresh_shop();
auction_product( 584, $OLD, array( '_ebay_listing_status' => 'Ended' ) );
auction_product( 585, $OLD, array( '_sku' => 'COPY-585', '_ebay_sku' => 'COPY-585', '_ebay_listing_status' => 'Ended' ) );
import( auction( $NEW, array( 'RelistParentID' => $OLD ) ) );
import( auction( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'BidCount' => '5' ) ) ) );
check( 'two products on the old listing: not remembered, said on each bid', array( get_transient( 'tcgiant_relist_declined_' . $NEW ), log_lines_naming( 'are both linked to ' . $OLD ) ), array( false, 2 ) );
check( '  saying the auction is not imported', logged( 'so ' . $NEW . ' is not imported (it is an auction, and Import eBay auctions is off)' ), true );

// The SKU step. An auction with the seller's SKU CHZ-4, which a product on a
// live fixed-price listing also carries: eBay is asked once whether that
// listing is over, not again on every bid.
fresh_shop();
product( 586, array( '_sku' => 'CHZ-4', '_ebay_sku' => 'CHZ-4', '_ebay_item_id' => '330000000333', '_ebay_listing_status' => 'Active', '_ebay_listing_type' => 'FixedPriceItem' ) );
ebay_has( '330000000333', fixed( '330000000333' ) );
for ( $bid = 1; $bid <= 4; $bid++ ) {
	import( auction( $NEW, array( 'SKU' => 'CHZ-4', 'SellingStatus' => array( 'BidCount' => (string) $bid ) ) ) );
}
check( 'a SKU shared with a live listing, four bids: eBay asked about that listing once', $EBAY['get_items'], array( '330000000333' ) );
check( '  the other product untouched, nothing made', array( meta( 586, '_ebay_item_id' ), $CREATED ), array( '330000000333', array() ) );
// The hourly check then records 333 as ended: the next bid moves the
// product on, from that record, with no call.
$META[586]['_ebay_listing_status'] = 'Ended';
$EBAY['get_items'] = array();
$got = import( auction( $NEW, array( 'SKU' => 'CHZ-4', 'SellingStatus' => array( 'BidCount' => '5' ) ) ) );
check( '  once 333 is recorded as ended, the next bid moves the product on with no call', array( $got, meta( 586, '_ebay_item_id' ), $EBAY['get_items'] ), array( 586, $NEW, array() ) );

echo "\nIMPORT EBAY AUCTIONS ON\n" . str_repeat( '=', 134 ) . "\n";

fresh_shop( array( 'import_auctions' => '1' ) );
$TRANSIENTS['tcgiant_shop_has_auctions'] = 'no';
$got = import( auction( $NEW ) );
check( 'a product is made for the auction', $got > 0 && $CREATED === array( $got ), true );
check( '  and the shop is known to have an auction at once (3.28.0: a stock push asks eBay before an EndItem)', $TRANSIENTS['tcgiant_shop_has_auctions'] ?? null, 'yes' );
check( '  at the opening bid, 0.99 (3.27.2: 37.00)', meta( $got, '_regular_price' ), '0.99' );
check( '  with no sale price', meta( $got, '_sale_price' ), '' );
check( '  it cannot be bought on the website', purchasable( $got ), false );
import( auction( $NEW, array( 'SellingStatus' => array( 'CurrentPrice' => '45.00', 'BidCount' => '5' ) ) ) );
check( 'the next bid: the price stays 0.99', meta( $got, '_regular_price' ), '0.99' );
check( '  the current bid follows it', meta( $got, '_ebay_current_price' ), '45.00' );

// No opening bid in the answer, and no product yet: the bid is all there is.
fresh_shop( array( 'import_auctions' => '1' ) );
$brief = auction( $NEW );
unset( $brief['StartPrice'] );
$got = import( $brief );
check( 'a new auction from an answer without its opening bid takes the current bid', meta( $got, '_regular_price' ), '37.00' );

// Fixed price is untouched, either way.
foreach ( array( 'off' => array(), 'on' => array( 'import_auctions' => '1' ) ) as $label => $settings ) {
	fresh_shop( $settings );
	$got = import( fixed( '440000000444' ) );
	check( sprintf( 'a fixed-price listing, setting %s: imported at 12.50', $label ), array( $got > 0, meta( $got, '_regular_price' ) ), array( true, '12.50' ) );
	$extra = array_values( array_filter( array( '_ebay_start_price', '_ebay_current_price', '_ebay_bid_count', '_ebay_buy_it_now_price' ), function ( $key ) use ( $got ) { return has_meta( $got, $key ); } ) );
	check( '  with none of the auction rows', $extra, array() );
	check( '  and on sale', purchasable( $got ), true );
}

echo "\nTHE ONE-TIME DECISION AT UPGRADE\n" . str_repeat( '=', 134 ) . "\n";

// A card shop that already imports its auctions: two imported auction
// products, one pushed coin, one in the trash.
fresh_shop( array( 'marketplace' => 'EBAY_US' ), false );
auction_product( 510, '510000000510' );
auction_product( 511, '511000000511' );
auction_product( 512, '512000000512', array( '_ebay_export_last_pushed' => '2026-10-01 10:00:00' ) );
auction_product( 513, '513000000513' );
$POSTS[513]['status'] = 'trash';
upgrade_auction_import();
check( 'a shop with imported auctions: Import eBay auctions is turned on', $OPTIONS['tcgiant_sync_ebay_settings']['import_auctions'] ?? null, '1' );
check( '  the rest of its settings kept', $OPTIONS['tcgiant_sync_ebay_settings']['marketplace'] ?? null, 'EBAY_US' );
check( '  the notice is waiting', $OPTIONS[ NOTICE_OPTION ] ?? null, 'on' );
check( '  one line in the log, counting the two', array( log_lines_naming( 'Import eBay auctions" has been turned on' ), logged( 'This shop has 2 product(s) imported from eBay auctions' ) ), array( 1, true ) );
check( '  which says an imported auction stays off sale after an unsold end, and how to sell one', logged( 'A product imported from an auction stays off sale on your website after the auction ends unsold, as its price is the opening bid on eBay; to sell it there, first set your own price, then push it as Fixed Price or unlink it.' ), true );
check( '  the pushed coin is marked as listed from WooCommerce, the imported ones are not', array( meta( 512, '_ebay_auction_listed_item' ), meta( 510, '_ebay_auction_listed_item' ), meta( 511, '_ebay_auction_listed_item' ) ), array( '512000000512', null, null ) );
upgrade_auction_import();
check( '  and it is done once', log_lines_naming( 'Import eBay auctions" has been turned on' ), 1 );
check( 'its new auctions keep arriving', import( auction( $NEW ) ) > 0, true );

// A coin shop that only pushes auctions, and a parts shop with none.
foreach ( array( 'only pushed auctions' => array( '_ebay_export_last_pushed' => '2026-10-01 10:00:00' ), 'no auctions' => array( '_ebay_listing_type' => 'FixedPriceItem' ) ) as $label => $meta ) {
	fresh_shop( array( 'marketplace' => 'EBAY_US' ), false );
	auction_product( 514, '514000000514', $meta );
	upgrade_auction_import();
	check( sprintf( 'a shop with %s: left off', $label ), array_key_exists( 'import_auctions', $OPTIONS['tcgiant_sync_ebay_settings'] ), false );
	check( '  no notice, no line', array( $OPTIONS[ NOTICE_OPTION ] ?? null, count( $LOG ) ), array( 'off', 0 ) );
}

// A new install has nothing.
fresh_shop( array(), false );
check( 'a new install: imports_auctions() is false', imports_auctions(), false );
check( '  and the decision is recorded', $OPTIONS[ NOTICE_OPTION ] ?? null, 'off' );

// The shop's own choice is never overruled.
fresh_shop( array( 'import_auctions' => '0' ), false );
auction_product( 515, '515000000515' );
upgrade_auction_import();
check( 'a shop that already chose No keeps No', $OPTIONS['tcgiant_sync_ebay_settings']['import_auctions'], '0' );

// The upgrade turned it on while another request, holding the settings it
// had read before, saved them back without it. The shop never said No.
fresh_shop( array( 'marketplace' => 'EBAY_US' ), false );
$OPTIONS[ NOTICE_OPTION ] = 'on';
check( 'turned on at upgrade, then lost to an older save of the settings: still on', imports_auctions(), true );
$OPTIONS[ NOTICE_OPTION ] = 'dismissed';
check( '  and after the notice is dismissed', imports_auctions(), true );
$OPTIONS['tcgiant_sync_ebay_settings']['import_auctions'] = '0';
check( '  but No, once chosen, is No', imports_auctions(), false );

// Which auctions the shop listed from WooCommerce was not recorded before
// 3.28.0. Once, at upgrade, every auction product ever pushed is marked as
// listed from WooCommerce - as everything pushed counted before - whatever
// the setting.
fresh_shop( array( 'import_auctions' => '0' ), false );
auction_product( 517, '517000000517', array( '_ebay_export_last_pushed' => '2026-10-01 10:00:00', '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-10-09 10:00:00', '_ebay_stock_settled_item' => '517000000517' ) );
auction_product( 518, '518000000518', array( '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-10-09 10:00:00', '_ebay_stock_settled_item' => '518000000518' ) );
product( 519, array( '_ebay_item_id' => '519000000519', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_export_last_pushed' => '2026-10-01 10:00:00' ) );
auction_product( 527, '527000000527', array( '_ebay_export_last_pushed' => '' ) );
auction_product( 528, '528000000528', array( '_ebay_export_last_pushed' => '2026-10-01 10:00:00', '_ebay_auction_listed_item' => '330000000333' ) );
auction_product( 529, '', array( '_ebay_export_last_pushed' => '2026-10-01 10:00:00' ) );
check( 'before the upgrade, a pushed coin that ended unsold is not for sale', purchasable( 517 ), false );
upgrade_auction_import();
check( 'the upgrade marks the pushed auction as listed from WooCommerce', meta( 517, '_ebay_auction_listed_item' ), '517000000517' );
check( '  and it is back on sale, as on 3.27.2', purchasable( 517 ), true );
check( '  the imported auction is not marked, and stays off sale', array( meta( 518, '_ebay_auction_listed_item' ), purchasable( 518 ) ), array( null, false ) );
check( '  nor a fixed-price product, one with an empty push date, or one with no listing', array( meta( 519, '_ebay_auction_listed_item' ), meta( 527, '_ebay_auction_listed_item' ), meta( 529, '_ebay_auction_listed_item' ) ), array( null, null, null ) );
check( '  and a mark already there is left as it is', meta( 528, '_ebay_auction_listed_item' ), '330000000333' );

// A sync that runs before anyone opens the admin follows the same decision:
// the first auction it meets runs the step.
fresh_shop( array( 'marketplace' => 'EBAY_US' ), false );
auction_product( 516, '516000000516' );
$got = import( auction( $NEW ) );
check( 'a sync before the admin is opened: the step runs and the auction is imported', array( $got > 0, $OPTIONS['tcgiant_sync_ebay_settings']['import_auctions'] ?? null ), array( true, '1' ) );

echo "\nNOT FOR SALE ON THE WEBSITE WHILE THE AUCTION RUNS (FAILS ON 3.27.2)\n" . str_repeat( '=', 134 ) . "\n";

$registered = array();
foreach ( $FILTERS['woocommerce_is_purchasable'] ?? array() as $f ) {
	$registered[] = array( is_array( $f[0] ) ? $f[0][1] : $f[0], $f[1], $f[2] );
}
check( 'the inventory class hooks woocommerce_is_purchasable, priority 20, 2 arguments', $registered, array( array( 'filter_purchasable', 20, 2 ) ) );
check( '  and the price shown', count( $FILTERS['woocommerce_get_price_html'] ?? array() ), 1 );
check( '  and the space where Add to cart was', count( array_filter( $HOOKS['woocommerce_simple_add_to_cart'] ?? array(), function ( $h ) { return 31 === $h[2]; } ) ), 1 );

fresh_shop();
$settled_222 = array( '_ebay_stock_settled_at' => '2026-10-17 11:00:00', '_ebay_stock_settled_item' => $NEW );
$cases = array(
	'(a) a live auction'                                              => array( array(), false ),
	'(a) a live auction the shop listed from WooCommerce'             => array( array( '_ebay_export_last_pushed' => '2026-10-10 10:00:00' ), false ),
	'(b) marked Ended here but not settled (GetItem failed, EndItem refused)' => array( array( '_ebay_listing_status' => 'Ended' ), false ),
	'(c) over and settled, listed from WooCommerce: back on sale'     => array( $settled_222 + array( '_ebay_listing_status' => 'Ended', '_ebay_export_last_pushed' => '2026-10-10 10:00:00', '_ebay_auction_listed_item' => $NEW ), true ),
	'(d) over and settled, imported from eBay: not'                    => array( $settled_222 + array( '_ebay_listing_status' => 'Ended' ), false ),
	'(e) settled for the old listing 111 while it follows 222'         => array( array( '_ebay_stock_settled_at' => '2026-10-01 11:00:00', '_ebay_stock_settled_item' => $OLD, '_ebay_export_last_pushed' => '2026-10-10 10:00:00' ), false ),
	'(f) fixed price'                                                 => array( array( '_ebay_listing_type' => 'FixedPriceItem' ), true ),
	'(g) an auction product no longer linked to eBay'                 => array( array( '_ebay_item_id' => '' ), true ),
);
$id = 520;
foreach ( $cases as $label => $case ) {
	auction_product( $id, $NEW, $case[0] );
	check( $label, purchasable( $id ), $case[1] );
	$id++;
}
check( '(h) what something else made unbuyable stays unbuyable', purchasable( 526, false ), false );
check( '(i) anything that is not a product passes through', filter_purchasable( true, 'not a product' ), true );

echo "\nWHAT THE WEBSITE SHOWS INSTEAD\n" . str_repeat( '=', 134 ) . "\n";

fresh_shop();
auction_product( 530, $NEW, array( '_ebay_current_price' => '37.00', '_ebay_bid_count' => '4', '_ebay_start_price' => '0.99', '_ebay_buy_it_now_price' => '' ) );
$html = filter_price_html( '<span>$0.99</span>', wc_get_product( 530 ) );
check( 'a running auction with bids shows the current bid and the count', false !== strpos( $html, 'Auction on eBay - current bid <span class="amount">$37.00</span> (4 bids)' ), true );
auction_product( 531, $NEW, array( '_ebay_current_price' => '0.99', '_ebay_bid_count' => '0', '_ebay_start_price' => '0.99', '_ebay_buy_it_now_price' => '90.00' ) );
$html = filter_price_html( '<span>$0.99</span>', wc_get_product( 531 ) );
check( 'one with no bids shows its opening bid', false !== strpos( $html, 'Auction on eBay - opening bid <span class="amount">$0.99</span>' ), true );
check( '  and Buy It Now while it is offered', false !== strpos( $html, 'or Buy It Now <span class="amount">$90.00</span>' ), true );
auction_product( 532, $NEW, array( '_regular_price' => '25.00', '_ebay_export_last_pushed' => '2026-10-10 10:00:00' ) );
check( 'one listed from WooCommerce and not imported since shows its own price as the opening bid', false !== strpos( filter_price_html( 'x', wc_get_product( 532 ) ), 'opening bid <span class="amount">$25.00</span>' ), true );

// A coin listed at auction from WooCommerce at $50, on sale here at $40. The
// push sent eBay its regular price, so that is the opening bid there.
auction_product( 535, $NEW, array( '_regular_price' => '50.00', '_sale_price' => '40.00', '_ebay_export_last_pushed' => '2026-10-10 10:00:00' ) );
$html = filter_price_html( 'x', wc_get_product( 535 ) );
check( 'one with a sale price shows its regular price as the opening bid, as eBay has it', array( false !== strpos( $html, 'opening bid <span class="amount">$50.00</span>' ), false !== strpos( $html, '$40.00' ) ), array( true, false ) );

// An auction 3.27.2 imported: its price is the bid it had reached, $37, and
// none of eBay's auction figures are recorded until it is next imported.
auction_product( 536, $NEW, array( '_regular_price' => '37.00', '_sync_last_updated' => '2026-10-11 10:00:00' ) );
$html = filter_price_html( 'x', wc_get_product( 536 ) );
check( 'one imported before 3.28.0 shows the auction with no figure, not "opening bid $37.00"', array( false !== strpos( $html, 'Auction on eBay' ), false !== strpos( $html, 'opening bid' ), false !== strpos( $html, '$37.00' ) ), array( true, false, false ) );
// Pushed once, then imported by 3.27.2 while it took bids.
auction_product( 537, $NEW, array( '_regular_price' => '37.00', '_ebay_export_last_pushed' => '2026-10-01 10:00:00', '_sync_last_updated' => '2026-10-11 10:00:00' ) );
check( '  so does one pushed, then imported since', false !== strpos( filter_price_html( 'x', wc_get_product( 537 ) ), 'opening bid' ), false );

// eBay said nobody has bid: its current price is the opening bid.
auction_product( 538, $NEW, array( '_regular_price' => '37.00', '_sync_last_updated' => '2026-10-11 10:00:00', '_ebay_bid_count' => '0', '_ebay_current_price' => '1.50' ) );
check( 'no bids recorded, and no opening bid: eBay\'s current price is shown as the opening bid', false !== strpos( filter_price_html( 'x', wc_get_product( 538 ) ), 'Auction on eBay - opening bid <span class="amount">$1.50</span>' ), true );

// The coin ended with five bids at $80 (reserve not met), and the seller
// relisted it on eBay. The new auction has no bids yet.
auction_product( 539, $OLD, array( '_regular_price' => '50.00', '_ebay_export_last_pushed' => '2026-10-01 10:00:00', '_ebay_start_price' => '50.00', '_ebay_current_price' => '80.00', '_ebay_bid_count' => '5', '_ebay_buy_it_now_price' => '120.00' ) );
TCGiant_Sync_Inventory::follow_relist( 539, $OLD, $NEW, array( 'status' => 'Active', 'listing_type' => 'Chinese' ), 'Import' );
$left = array_values( array_filter( array( '_ebay_start_price', '_ebay_current_price', '_ebay_bid_count', '_ebay_buy_it_now_price' ), function ( $key ) { return has_meta( 539, $key ); } ) );
check( 'moved on to a relist: the old auction\'s bids and prices are forgotten', $left, array() );
$html = filter_price_html( 'x', wc_get_product( 539 ) );
check( '  so its five bids at $80.00 are not shown on the new one', array( false !== strpos( $html, '$80.00' ), false !== strpos( $html, 'opening bid <span class="amount">$50.00</span>' ) ), array( false, true ) );
auction_product( 533, $NEW, $settled_222 );
check( 'an imported auction that is over keeps the shop\'s price display', filter_price_html( '<span>$0.99</span>', wc_get_product( 533 ) ), '<span>$0.99</span>' );
product( 534, array( '_regular_price' => '12.50' ) );
check( 'a product with no auction is not touched', filter_price_html( '<span>$12.50</span>', wc_get_product( 534 ) ), '<span>$12.50</span>' );

// In place of Add to cart on the product page.
// WooCommerce's single product page sets the global $product.
$product = wc_get_product( 530 );
$notice  = render_auction_notice();
check( 'the product page links to the auction on eBay', false !== strpos( $notice, 'href="https://www.ebay.com/itm/' . $NEW . '"' ), true );
check( '  saying where to bid', false !== strpos( $notice, 'Bid on eBay' ) && false !== strpos( $notice, 'current bid' ), true );
$product = wc_get_product( 536 );
$notice  = render_auction_notice();
check( 'one imported before 3.28.0: the auction and the link, with no figure', array( false !== strpos( $notice, 'Auction on eBay <a href="https://www.ebay.com/itm/' . $NEW . '"' ), false !== strpos( $notice, '$37.00' ) ), array( true, false ) );
$product = wc_get_product( 533 );
check( 'an imported auction that is over says it has ended', false !== strpos( render_auction_notice(), 'This auction has ended on eBay.' ), true );
$product = wc_get_product( 534 );
check( 'nothing for a product that can be bought', render_auction_notice(), '' );

echo "\nAN AUCTION SETTLED BEFORE 3.27.0\n" . str_repeat( '=', 134 ) . "\n";

// Before 3.27.0 a settle noted when, not which listing. A coin the shop
// listed at auction from WooCommerce ended unsold in September and was
// settled then; 3.27.2 sold it on the website at $450. Read as a running
// auction it lost Add to cart for good, showed "opening bid $450.00" and
// linked to an auction that had ended weeks before.
fresh_shop();
$september = array( '_ebay_listing_status' => 'Ended', '_ebay_end_time' => '2026-09-20T10:00:00.000Z', '_ebay_stock_settled_at' => '2026-09-20 11:00:00' );
auction_product( 590, $OLD, $september + array( '_regular_price' => '450.00', '_ebay_export_last_pushed' => '2026-09-13 10:00:00', '_ebay_auction_listed_item' => $OLD ) );
check( 'listed from WooCommerce, ended unsold, settled before 3.27.0: back on sale', purchasable( 590 ), true );
check( '  at its own price', filter_price_html( '<span>$450.00</span>', wc_get_product( 590 ) ), '<span>$450.00</span>' );
$product = wc_get_product( 590 );
check( '  with no auction notice', render_auction_notice(), '' );
auction_product( 591, $OLD, array( '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-09-20 11:00:00', '_ebay_export_last_pushed' => '2026-09-13 10:00:00', '_ebay_auction_listed_item' => $OLD ) );
check( '  the same with no end time recorded', purchasable( 591 ), true );

// The card shop's auction, imported from eBay, sold in September: stock 0.
auction_product( 592, $OLD, $september + array( '_stock' => 0, '_stock_status' => 'outofstock', '_ebay_current_price' => '37.00', '_ebay_bid_count' => '4' ) );
check( 'imported from eBay and settled before 3.27.0: still not for sale here', purchasable( 592 ), false );
check( '  its price display left alone', filter_price_html( '<span>$37.00</span>', wc_get_product( 592 ) ), '<span>$37.00</span>' );
$product = wc_get_product( 592 );
$notice  = render_auction_notice();
check( '  the product page says it has ended, not "Bid on eBay"', array( false !== strpos( $notice, 'This auction has ended on eBay.' ), false !== strpos( $notice, 'Bid on eBay' ) ), array( true, false ) );

// Relists before 3.27.0 did not clear the mark, so an old one can sit on a
// product that has moved on to a newer auction. It is not about that one.
$older = array( '_ebay_stock_settled_at' => '2026-09-20 11:00:00', '_ebay_export_last_pushed' => '2026-09-13 10:00:00', '_ebay_auction_listed_item' => $NEW );
auction_product( 593, $NEW, $older + array( '_ebay_end_time' => '2026-10-17T10:00:00.000Z' ) );
check( 'an older settle on a product whose newer auction runs: not for sale', purchasable( 593 ), false );
auction_product( 594, $NEW, $older );
check( '  nor with no end time recorded for the newer one', purchasable( 594 ), false );
// Recorded Ended while its end time is still ahead: an EndItem eBay refused.
auction_product( 595, $NEW, $older + array( '_ebay_end_time' => iso( time() + 3 * DAY_IN_SECONDS ), '_ebay_listing_status' => 'Ended' ) );
check( '  nor once the newer one is recorded Ended, until it is settled', purchasable( 595 ), false );
$product = wc_get_product( 595 );
check( '  which the product page does not call ended while its end time is ahead', false !== strpos( render_auction_notice(), 'This auction has ended on eBay.' ), false );

echo "\nAN AUCTION THAT ENDED BUT WAS NEVER SETTLED\n" . str_repeat( '=', 134 ) . "\n";

// The card shop's auction, imported from eBay, sold in August. Its ending was
// recorded with no settle - before settles were written, or by a path that
// writes none - and nothing settles it later. Read as a running auction, the
// shop grid said "Auction on eBay - current bid $37.00 (4 bids)" and the
// product page sent buyers to bid on a listing that had ended weeks before.
fresh_shop();
$august = array( '_ebay_listing_status' => 'Ended', '_ebay_end_time' => '2026-08-10T10:00:00.000Z' );
auction_product( 600, $OLD, $august + array( '_stock' => 0, '_stock_status' => 'outofstock', '_ebay_start_price' => '0.99', '_ebay_current_price' => '37.00', '_ebay_bid_count' => '4' ) );
check( 'recorded Ended in August and never settled: the shop shows WooCommerce\'s price', filter_price_html( '<span>$0.99</span>', wc_get_product( 600 ) ), '<span>$0.99</span>' );
$product = wc_get_product( 600 );
$notice  = render_auction_notice();
check( '  the product page says it has ended and links to view it, not to bid', array( false !== strpos( $notice, 'This auction has ended on eBay.' ), false !== strpos( $notice, 'View it on eBay' ), false !== strpos( $notice, 'Bid on eBay' ), false !== strpos( $notice, '$37.00' ) ), array( true, true, false, false ) );
check( '  and it still cannot be bought here', purchasable( 600 ), false );

// The coin shop's own auction, recorded Ended by the hourly check when eBay
// would not report its quantities: still in stock, never settled. It says it
// has ended, and stays off sale until Stock Review settles it.
auction_product( 601, $OLD, $august + array( '_regular_price' => '450.00', '_ebay_export_last_pushed' => '2026-08-03 10:00:00', '_ebay_auction_listed_item' => $OLD ) );
check( 'the shop\'s own auction, recorded Ended in August and never settled: its price is shown', filter_price_html( '<span>$450.00</span>', wc_get_product( 601 ) ), '<span>$450.00</span>' );
$product = wc_get_product( 601 );
check( '  the product page says it has ended', false !== strpos( render_auction_notice(), 'This auction has ended on eBay.' ), true );
check( '  but it is not for sale here until it is settled', purchasable( 601 ), false );

// No evidence the auction is over: recorded Ended with its end time still
// ahead, or with no end time; or past its end time but not yet recorded Ended,
// as before the hourly check reads it.
$cases = array(
	'recorded Ended, its end time still ahead: shown as running'   => array( '_ebay_listing_status' => 'Ended', '_ebay_end_time' => iso( time() + 2 * DAY_IN_SECONDS ) ),
	'recorded Ended with no end time: shown as running'            => array( '_ebay_listing_status' => 'Ended' ),
	'past its end time, not yet recorded Ended: shown as running'  => array( '_ebay_end_time' => '2026-08-10T10:00:00.000Z' ),
);
$id = 602;
foreach ( $cases as $label => $case ) {
	auction_product( $id, $NEW, $case + array( '_ebay_current_price' => '37.00', '_ebay_bid_count' => '4' ) );
	$product = wc_get_product( $id );
	$notice  = render_auction_notice();
	check( $label, array( false !== strpos( filter_price_html( 'x', $product ), 'Auction on eBay - current bid' ), false !== strpos( $notice, 'Bid on eBay' ), false !== strpos( $notice, 'has ended' ) ), array( true, true, false ) );
	check( '  and not for sale here', purchasable( $id ), false );
	$id++;
}

echo "\nBACK ON SALE ONLY WHEN THE SHOP LISTED THAT AUCTION\n" . str_repeat( '=', 134 ) . "\n";

// The card shop pushed an Update - a new title - to one of its imported
// auctions. A push, but the auction is still eBay's, priced at eBay's
// opening bid: after an unsold end it stays off sale here. (An Update is a
// revise of the listing, which writes the push date and nothing else of
// which listing the shop put up.)
fresh_shop( array( 'import_auctions' => '1' ) );
$card = import( auction( $NEW, array( 'SellingStatus' => array( 'BidCount' => '0', 'CurrentPrice' => '0.99' ) ) ) );
$META[ $card ]['_ebay_export_last_pushed'] = '2026-10-12 10:00:00';
ebay_has( $NEW, auction( $NEW, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0', 'CurrentPrice' => '0.99', 'QuantitySold' => '0' ) ) ) );
$IMP->settle_item( $NEW );
check( 'an imported auction the shop pushed an Update to, ended unsold: settled, in stock', array( settle_marks( $card ), stock( $card ) ), array( array( true, $NEW ), 1 ) );
check( '  still not for sale here at its opening bid', purchasable( $card ), false );

// A coin pushed at a fixed $60. That listing ended, and the seller put the
// coin up again on eBay as a $0.99 auction with the same SKU. The product
// moves on to the auction and takes its opening bid as its price.
fresh_shop();
product( 596, array( '_sku' => 'COIN-1', '_ebay_sku' => 'COIN-1', '_ebay_item_id' => $OLD, '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_regular_price' => '60.00', '_ebay_export_last_pushed' => '2026-10-01 10:00:00' ) );
ebay_has( $OLD, fixed( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed' ) ) ) );
import( auction( $NEW, array( 'SKU' => 'COIN-1', 'SellingStatus' => array( 'BidCount' => '0', 'CurrentPrice' => '0.99' ) ) ) );
check( 'pushed at a fixed price, then moved on to an auction started on eBay', array( meta( 596, '_ebay_item_id' ), meta( 596, '_regular_price' ), purchasable( 596 ) ), array( $NEW, '0.99', false ) );
ebay_has( $NEW, auction( $NEW, array( 'SKU' => 'COIN-1', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0', 'CurrentPrice' => '0.99', 'QuantitySold' => '0' ) ) ) );
$IMP->settle_item( $NEW );
check( '  it ends unsold: settled, in stock', array( settle_marks( 596 ), stock( 596 ) ), array( array( true, $NEW ), 1 ) );
check( '  but not for sale here at the $0.99 opening bid', purchasable( 596 ), false );
check( '  never marked as an auction the shop listed', meta( 596, '_ebay_auction_listed_item' ), null );

// The coin shop's own auction 111 ended unsold and was settled, so the coin
// went back on sale here. The seller then put it up again on eBay with its
// SKU: with Sell Similar, which names no listing it came from, or with Relist
// while eBay could not be asked about 111. The SKU moved the product on to
// the new auction 222 but left the mark on 111, so once 222 ended unsold the
// coin stayed off sale here for good, with nothing in the log to say why.
$put_up_again = array(
	'Sell Similar'                    => array( array(), null ),
	'Relist, 111 could not be read'   => array( array( 'RelistParentID' => $OLD ), new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ),
);
foreach ( array( 'off' => array(), 'on' => array( 'import_auctions' => '1' ) ) as $setting => $settings ) {
	foreach ( $put_up_again as $how => $again ) {
		fresh_shop( $settings );
		auction_product( 610, $OLD, array( '_sku' => 'COIN-610', '_ebay_sku' => 'COIN-610', '_regular_price' => '25.00', '_ebay_listing_status' => 'Ended', '_ebay_end_time' => '2026-10-10T10:00:00.000Z', '_ebay_stock_settled_at' => '2026-10-10 11:00:00', '_ebay_stock_settled_item' => $OLD, '_ebay_quantity_listed' => 1, '_ebay_quantity_sold' => 0, '_ebay_export_last_pushed' => '2026-10-03 10:00:00', '_ebay_auction_listed_item' => $OLD ) );
		if ( $again[1] ) {
			ebay_has( $OLD, $again[1] );
		}
		$got = import( auction( $NEW, array_merge( array( 'SKU' => 'COIN-610', 'StartPrice' => '25.00', 'SellingStatus' => array( 'BidCount' => '0', 'CurrentPrice' => '25.00' ) ), $again[0] ) ) );
		check( sprintf( 'the shop\'s auction put up again by %s, Import eBay auctions %s: moved on by its SKU', $how, $setting ), array( $got, meta( 610, '_ebay_item_id' ) ), array( 610, $NEW ) );
		if ( $again[1] ) {
			check( '  once eBay could not say how 111 ended', logged( 'but eBay could not confirm how ' . $OLD . ' ended' ), true );
		}
		check( '  still the shop\'s own auction', meta( 610, '_ebay_auction_listed_item' ), $NEW );
		check( '  not for sale here while it runs', purchasable( 610 ), false );
		ebay_has( $NEW, auction( $NEW, array( 'SKU' => 'COIN-610', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0', 'QuantitySold' => '0', 'CurrentPrice' => '25.00' ) ) ) );
		$IMP->settle_item( $NEW );
		check( '  it ends unsold: settled, in stock, back on sale here', array( settle_marks( 610 ), stock( 610 ), purchasable( 610 ) ), array( array( true, $NEW ), 1, true ) );
	}
}
// Put up again at a fixed price instead: not an auction, so nothing is
// carried.
fresh_shop();
auction_product( 611, $OLD, array( '_sku' => 'COIN-611', '_ebay_sku' => 'COIN-611', '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-10-10 11:00:00', '_ebay_stock_settled_item' => $OLD, '_ebay_export_last_pushed' => '2026-10-03 10:00:00', '_ebay_auction_listed_item' => $OLD ) );
import( fixed( $NEW, array( 'SKU' => 'COIN-611' ) ) );
check( 'the shop\'s auction put up again at a fixed price with its SKU: moved on, the mark not carried', array( meta( 611, '_ebay_item_id' ), meta( 611, '_ebay_auction_listed_item' ) ), array( $NEW, $OLD ) );

// The shop's own auction, relisted on eBay as an auction: still the shop's,
// and back on sale here once that one ends unsold.
fresh_shop();
auction_product( 597, $OLD, array( '_regular_price' => '450.00', '_ebay_export_last_pushed' => '2026-10-01 10:00:00', '_ebay_auction_listed_item' => $OLD ) );
TCGiant_Sync_Inventory::follow_relist( 597, $OLD, $NEW, array( 'status' => 'Active', 'listing_type' => 'Chinese' ), 'Import' );
check( 'the shop\'s own auction relisted on eBay: still the shop\'s', meta( 597, '_ebay_auction_listed_item' ), $NEW );
ebay_has( $NEW, auction( $NEW, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0', 'QuantitySold' => '0' ) ) ) );
$IMP->settle_item( $NEW );
check( '  ended unsold: back on sale here', array( settle_marks( 597 ), purchasable( 597 ) ), array( array( true, $NEW ), true ) );
// Relisted at a fixed price instead, or carrying a mark for some other
// listing: nothing is carried.
auction_product( 598, $OLD, array( '_ebay_auction_listed_item' => $OLD ) );
TCGiant_Sync_Inventory::follow_relist( 598, $OLD, $NEW, array( 'status' => 'Active', 'listing_type' => 'FixedPriceItem' ), 'Import' );
check( '  relisted at a fixed price: the mark is not carried', meta( 598, '_ebay_auction_listed_item' ), $OLD );
auction_product( 599, $OLD, array( '_ebay_auction_listed_item' => '330000000333' ) );
TCGiant_Sync_Inventory::follow_relist( 599, $OLD, $NEW, array( 'status' => 'Active', 'listing_type' => 'Chinese' ), 'Import' );
check( '  a mark naming another listing is not carried', meta( 599, '_ebay_auction_listed_item' ), '330000000333' );

// A product duplicated to list the next coin is not the shop's auction of
// the first; the same list is what Unlink removes.
require_once $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
check( 'the mark is not copied to a duplicate, and Unlink removes it', in_array( '_ebay_auction_listed_item', TCGiant_Sync_Listing_Link::duplicate_exclude_meta( array() ), true ), true );
// Nor are the auction's bids and prices: a duplicate later linked to another
// auction would show them as that one's.
check( '  nor the auction\'s bids and prices', array_values( array_diff( array( '_ebay_start_price', '_ebay_current_price', '_ebay_bid_count', '_ebay_buy_it_now_price' ), TCGiant_Sync_Listing_Link::duplicate_exclude_meta( array() ) ) ), array() );

echo "\nSTOCK PUSHES TO AN AUCTION (FAILS ON 3.27.2)\n" . str_repeat( '=', 134 ) . "\n";

// (a) The coin shop's stock moves to 2: there is no quantity to send.
fresh_shop();
auction_product( 540, $NEW );
$INV->process_ebay_stock_update( $NEW, 2 );
check( '(a) stock 2: nothing sent to eBay - no ReviseInventoryStatus, no Inventory API', wire_calls(), array() );
check( '    one line says why', logged( 'WC #540 is on eBay auction ' . $NEW . '. An auction is always one item' ), true );

// Since 3.28.0 a stock push names the product and reads its stock when it
// runs (TCGiant_Sync_Inventory::process_stock_push()). So each case below
// sets the stock it is about, and runs the push the way Action Scheduler runs
// one queued by a stock change.
function sold_out( $id ) {
	$GLOBALS['META'][ $id ]['_stock']        = 0;
	$GLOBALS['META'][ $id ]['_stock_status'] = 'outofstock';
	TCGiant_Sync_Inventory::instance()->process_stock_push( array( 'product_id' => $id, 'reason' => 'edit' ) );
}
function pushes_queued() {
	return array_values( array_filter( $GLOBALS['QUEUE'], function ( $a ) { return 'tcgiant_sync_push_stock' === $a['hook']; } ) );
}

// (b) Sold out over the counter, with three bids on eBay.
fresh_shop();
auction_product( 541, $NEW );
ebay_has( $NEW, auction( $NEW, array( 'SellingStatus' => array( 'BidCount' => '3' ) ) ) );
sold_out( 541 );
check( '(b) stock 0 with 3 bids: eBay is asked, and nothing ends', wire_calls(), array() );
check( '    asked once, about this auction', $EBAY['get_items'], array( $NEW ) );
check( '    still recorded as running', meta( 541, '_ebay_listing_status' ), 'Active' );
check( '    no settle written', settle_marks( 541 ), array( false, null ) );
check( '    an error names the auction and the bids', logged( 'eBay auction ' . $NEW . ' has 3 bid(s)', 'error' ), true );
check( '    and says to end it in Seller Hub', logged( 'end it in Seller Hub', 'error' ), true );
check( '    and it still cannot be bought here', purchasable( 541 ), false );
check( '    not tried again: the bids will not go away', pushes_queued(), array() );

// (c) Sold out, nobody has bid.
fresh_shop();
auction_product( 542, $NEW );
ebay_has( $NEW, auction( $NEW, array( 'SellingStatus' => array( 'BidCount' => '0', 'CurrentPrice' => '0.99' ) ) ) );
sold_out( 542 );
check( '(c) stock 0, no bids: exactly one EndItem', wire_calls(), array( 'EndItem' ) );
check( '    for this auction', false !== strpos( $EBAY['wire'][0][1] ?? '', '<ItemID>' . $NEW . '</ItemID>' ), true );
check( '    then recorded Ended', meta( 542, '_ebay_listing_status' ), 'Ended' );
check( '    and settled against it', settle_marks( 542 ), array( true, $NEW ) );
check( '    with why it ended, for that listing', array( meta( 542, '_ebay_end_reason' ), meta( 542, '_ebay_end_reason_item' ) ), array( 'stock_zero', $NEW ) );

// (d) eBay refuses EndItem for a reason that is not "already ended". 3.27.2
// recorded it Ended and settled all the same. Since 3.28.0 it is tried again
// later, said as a warning until the last try.
fresh_shop();
auction_product( 543, $NEW );
ebay_has( $NEW, auction( $NEW, array( 'SellingStatus' => array( 'BidCount' => '0' ) ) ) );
$EBAY['answers']['EndItem'] = new WP_Error( 'api_error', 'Internal error to the application.' );
sold_out( 543 );
check( '(d) EndItem refused: still recorded as running', meta( 543, '_ebay_listing_status' ), 'Active' );
check( '    no settle written', settle_marks( 543 ), array( false, null ) );
check( '    and the log says so', logged( 'Could not end eBay auction ' . $NEW, 'warning' ), true );
check( '    tried again in 5 minutes', array( count( pushes_queued() ), pushes_queued()[0]['args'][0]['attempt'] ?? null ), array( 1, 2 ) );

// eBay says it was already over. Its words are not taken for it any more
// (3.28.0): what eBay then says about the auction decides.
fresh_shop();
auction_product( 544, $NEW );
ebay_has( $NEW, auction( $NEW, array( 'SellingStatus' => array( 'BidCount' => '0' ) ) ) );
$EBAY['after_end'][ $NEW ] = array( 'Ack' => 'Success', 'Item' => auction( $NEW, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0', 'QuantitySold' => '0' ) ) ) );
$EBAY['answers']['EndItem'] = new WP_Error( 'api_error', 'The auction has already been closed.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '1047', 'ShortMessage' => 'The auction has already been closed.' ) ) );
sold_out( 544 );
check( 'EndItem answered "already been closed" (1047) and eBay says it is over: Ended and settled', array( meta( 544, '_ebay_listing_status' ), settle_marks( 544 ) ), array( 'Ended', array( true, $NEW ) ) );
check( '  eBay asked again to find out', $EBAY['get_items'], array( $NEW, $NEW ) );
fresh_shop();
auction_product( 549, $NEW );
ebay_has( $NEW, auction( $NEW, array( 'SellingStatus' => array( 'BidCount' => '0' ) ) ) );
$EBAY['answers']['EndItem'] = new WP_Error( 'api_error', 'The auction has already been closed.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '1047', 'ShortMessage' => 'The auction has already been closed.' ) ) );
sold_out( 549 );
check( '  the same words while eBay says it is still running: running, tried again', array( meta( 549, '_ebay_listing_status' ), settle_marks( 549 ), count( pushes_queued() ) ), array( 'Active', array( false, null ), 1 ) );

// (e) Auto-end is off.
fresh_shop( array( 'auto_end_zero_stock' => '0' ) );
auction_product( 545, $NEW );
sold_out( 545 );
check( '(e) auto-end off: no EndItem, no ReviseInventoryStatus, eBay not asked', array( wire_calls(), $EBAY['get_items'] ), array( array(), array() ) );
check( '    a warning that it is still running', logged( 'is still running', 'warning' ), true );

// (f) eBay cannot be asked: tried again later rather than given up on.
fresh_shop();
auction_product( 546, $NEW );
ebay_has( $NEW, new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
sold_out( 546 );
check( '(f) GetItem fails: no EndItem', wire_calls(), array() );
check( '    nothing recorded', array( meta( 546, '_ebay_listing_status' ), settle_marks( 546 ) ), array( 'Active', array( false, null ) ) );
check( '    the log says why', logged( 'cURL error 28', 'warning' ), true );
check( '    and it is tried again', count( pushes_queued() ), 1 );

// The auction has ended on eBay already: its figures are brought in from the
// same answer, not ended.
fresh_shop( array( 'remove_sold_products' => '0' ) );
auction_product( 547, $NEW );
ebay_has( $NEW, auction( $NEW, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ) ) ) );
sold_out( 547 );
check( 'over on eBay already: no EndItem', wire_calls(), array() );
check( '  settled from that answer, with no second GetItem', array( meta( 547, '_ebay_listing_status' ), settle_marks( 547 ), $EBAY['get_items'] ), array( 'Ended', array( true, $NEW ), array( $NEW ) ) );

// Already settled here, from eBay's final figures.
fresh_shop();
auction_product( 548, $NEW, $settled_222 + array( '_ebay_listing_status' => 'Ended', '_ebay_quantity_listed' => 1, '_ebay_quantity_sold' => 1 ) );
sold_out( 548 );
check( 'already over and settled here: eBay is not asked anything', array( wire_calls(), $EBAY['get_items'] ), array( array(), array() ) );
// The same marks as 3.27 left them, with none of eBay's figures: 3.27 wrote
// them for an EndItem eBay refused too, so they are not taken on trust
// (3.28.0, fix round 1, M5). eBay says the auction is still running, nobody
// has bid: the record is put right and the auction ended.
fresh_shop();
auction_product( 553, $NEW, $settled_222 + array( '_ebay_listing_status' => 'Ended' ) );
ebay_has( $NEW, auction( $NEW, array( 'SellingStatus' => array( 'BidCount' => '0' ) ) ) );
sold_out( 553 );
check( '  marks 3.27 left, the auction still running: eBay is asked, and it is ended now', array( $EBAY['get_items'], wire_calls(), meta( 553, '_ebay_end_reason' ) ), array( array( $NEW ), array( 'EndItem' ), 'stock_zero' ) );
// Recorded Ended by the hourly check, which could not read it: asked too.
fresh_shop();
auction_product( 554, $NEW, array( '_ebay_listing_status' => 'Ended' ) );
ebay_has( $NEW, auction( $NEW, array( 'SellingStatus' => array( 'BidCount' => '2' ) ) ) );
sold_out( 554 );
check( '  recorded Ended but never settled, 2 bids on eBay: left running, and recorded as running again', array( $EBAY['get_items'], wire_calls(), meta( 554, '_ebay_listing_status' ) ), array( array( $NEW ), array(), 'Active' ) );

// (g) A fixed-price listing still gets its quantity, from a push queued
// before 3.28.0 by its SKU.
fresh_shop();
product( 549, array( '_sku' => 'PIKA-58', '_ebay_sku' => 'PIKA-58', '_ebay_item_id' => '440000000444', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_stock' => 2 ) );
$INV->process_ebay_stock_update( 'PIKA-58', 2 );
$revise = array_values( array_filter( $EBAY['wire'], function ( $c ) { return 'ReviseInventoryStatus' === $c[0]; } ) );
check( '(g) fixed price, stock 2: ReviseInventoryStatus', count( $revise ), 1 );
check( '    with Quantity 2, for its listing', array( false !== strpos( $revise[0][1] ?? '', '<Quantity>2</Quantity>' ), false !== strpos( $revise[0][1] ?? '', '<ItemID>440000000444</ItemID>' ) ), array( true, true ) );

// (h) A hand edit queues nothing for an auction at 2, and the push at 0.
fresh_shop();
auction_product( 550, $NEW, array( '_stock' => 2 ) );
$INV->on_product_stock_change( wc_get_product( 550 ) );
check( '(h) a hand edit to 2 on an auction queues nothing', $QUEUE, array() );
check( '    and says why', logged( 'WC #550 stock changed to 2. It is on eBay auction ' . $NEW ), true );
$META[550]['_stock'] = 0;
$INV->on_product_stock_change( wc_get_product( 550 ) );
check( '    to 0 it queues the push, for the product', array_map( function ( $a ) { return array( $a['args'][0]['product_id'] ?? null, $a['args'][0]['reason'] ?? null ); }, pushes_queued() ), array( array( 550, 'edit' ) ) );

// A website order: only running out is queued.
fresh_shop();
auction_product( 551, $NEW, array( '_stock' => 3 ) );
product( 552, array( '_sku' => 'PIKA-58', '_ebay_sku' => 'PIKA-58', '_ebay_item_id' => '440000000444', '_ebay_listing_type' => 'FixedPriceItem', '_stock' => 2 ) );
$INV->sync_stock_to_ebay( new Harness_Order( array( 551, 552 ) ) );
check( 'after an order: the fixed-price product is queued, the auction is not', array_map( function ( $a ) { return array( $a['args'][0]['product_id'] ?? null, $a['args'][0]['reason'] ?? null, $a['args'][0]['qty'] ?? null ); }, pushes_queued() ), array( array( 552, 'order', 1 ) ) );

echo "\nA STOCK OF 0 LEFT ON A RUNNING AUCTION STAYS 0\n" . str_repeat( '=', 134 ) . "\n";

// The coin shop's auction, listed from WooCommerce, has three bids when the
// coin sells over the counter: stock 0. With bids on it the auction is left
// running. The next bid is a change eBay reports, and its import wrote
// eBay's quantity, 1, back over the 0 - so once the auction ended unsold the
// coin that had gone was for sale on the website again.
fresh_shop();
auction_product( 560, $NEW, array( '_ebay_export_last_pushed' => '2026-10-10 10:00:00', '_ebay_auction_listed_item' => $NEW, '_regular_price' => '450.00' ) );
ebay_has( $NEW, auction( $NEW, array( 'SellingStatus' => array( 'BidCount' => '3' ) ) ) );
$META[560]['_stock']        = 0;
$META[560]['_stock_status'] = 'outofstock';
$INV->process_ebay_stock_update( $NEW, 0 );
check( 'stock 0 with three bids: the auction is left running', array( wire_calls(), meta( 560, '_ebay_listing_status' ) ), array( array(), 'Active' ) );
$got = import( auction( $NEW, array( 'SellingStatus' => array( 'BidCount' => '4' ) ) ) );
check( 'the next bid is imported: stock stays 0, though eBay still lists 1', array( $got, stock( 560 ), meta( 560, '_stock_status' ) ), array( 560, 0, 'outofstock' ) );
check( '  the product\'s history says why', in_array( 'Stock kept at 0: WooCommerce held less than the auction, which is one item', meta( 560, '_tcgiant_sync_log' )[0]['decisions'] ?? array(), true ), true );
// The reserve is not met, and the auction ends unsold.
ebay_has( $NEW, auction( $NEW, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '4', 'QuantitySold' => '0' ) ) ) );
$IMP->settle_item( $NEW );
check( 'it ends unsold: settled, at 0', array( stock( 560 ), settle_marks( 560 ) ), array( 0, array( true, $NEW ) ) );
check( '  out of stock, so the website cannot sell the coin that has gone', wc_get_product( 560 )->is_in_stock(), false );

// A product moved on to a relist in the same import takes the relist's
// figure, as any relist does, and the shop is told what WooCommerce held.
fresh_shop();
auction_product( 561, $OLD, array( '_ebay_export_last_pushed' => '2026-10-01 10:00:00', '_ebay_auction_listed_item' => $OLD, '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-10-09 10:00:00', '_ebay_stock_settled_item' => $OLD, '_ebay_quantity_listed' => 1, '_ebay_quantity_sold' => 0, '_stock' => 0, '_stock_status' => 'outofstock' ) );
ebay_has( $OLD, auction( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0' ), 'ListingDetails' => array( 'RelistedItemID' => $NEW ) ) ) );
$got = import( auction( $NEW, array( 'RelistParentID' => $OLD ) ) );
check( 'moved on to a relist in the same import: eBay\'s figure applies', array( $got, meta( 561, '_ebay_item_id' ), stock( 561 ) ), array( 561, $NEW, 1 ) );
check( '  nothing says it was kept', in_array( 'Stock kept at 0: WooCommerce held less than the auction, which is one item', meta( 561, '_tcgiant_sync_log' )[0]['decisions'] ?? array(), true ), false );
check( '  and a warning says what WooCommerce held', logged( 'which offers 1; WooCommerce held 0', 'warning' ), true );
// Moved on by the per-sale stock check instead, which takes on a live relist
// without importing it: the import that follows applies eBay's figure, as
// the move's own import would have.
fresh_shop();
auction_product( 564, $OLD, array( '_ebay_export_last_pushed' => '2026-10-01 10:00:00', '_ebay_auction_listed_item' => $OLD, '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-10-09 10:00:00', '_ebay_stock_settled_item' => $OLD, '_ebay_quantity_listed' => 1, '_ebay_quantity_sold' => 0, '_stock' => 0, '_stock_status' => 'outofstock' ) );
ebay_has( $OLD, auction( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0' ), 'ListingDetails' => array( 'RelistedItemID' => $NEW ) ) ) );
ebay_has( $NEW, auction( $NEW, array( 'RelistParentID' => $OLD ) ) );
$IMP->settle_item( $NEW );
check( 'moved on by the sale check: follows the relist, stock as it was', array( meta( 564, '_ebay_item_id' ), stock( 564 ) ), array( $NEW, 0 ) );
$LOG = array();
import( $EBAY['items'][ $NEW ]['Item'] );
check( '  the next import applies eBay\'s figure', stock( 564 ), 1 );
check( '  and warns what WooCommerce held', logged( 'which offers 1; WooCommerce held 0', 'warning' ), true );

// So does a product the seller's SKU moves on to a new auction: its own
// fixed-price listing sold out, and this is the next copy.
fresh_shop();
product( 562, array( '_sku' => 'COIN-9', '_ebay_sku' => 'COIN-9', '_ebay_item_id' => $OLD, '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Ended', '_stock' => 0, '_stock_status' => 'outofstock' ) );
ebay_has( $OLD, fixed( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed' ) ) ) );
$got = import( auction( $NEW, array( 'SKU' => 'COIN-9' ) ) );
check( 'a seller-SKU product moved on to a new auction: eBay\'s 1 applies', array( $got, meta( 562, '_ebay_item_id' ), stock( 562 ) ), array( 562, $NEW, 1 ) );

// A fixed-price listing is not one item: eBay's quantity stands, as ever.
fresh_shop();
product( 563, array( '_sku' => '440000000444', '_ebay_sku' => '440000000444', '_ebay_item_id' => '440000000444', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' ) );
import( fixed( '440000000444' ) );
check( 'a fixed-price product holding 1 while eBay lists 3: 3', stock( 563 ), 3 );

echo "\nTHE IMPORTER COUNTS A SKIPPED AUCTION AS SKIPPED\n" . str_repeat( '=', 134 ) . "\n";

// The real delta sync over a window holding one auction nothing follows.
fresh_shop();
$from = time() - HOUR_IN_SECONDS;
ebay_has( $NEW, auction( $NEW ) );
$EBAY['events'] = array( array(
	'ItemID'         => $NEW,
	'ListingType'    => 'Chinese',
	'SellingStatus'  => array( 'ListingStatus' => 'Active', 'CurrentPrice' => '37.00', 'BidCount' => '4' ),
	'ListingDetails' => array( 'StartTime' => iso( $from + 600 ), 'EndTime' => iso( $from + 7 * DAY_IN_SECONDS ) ),
) );
update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array(
	'status'               => 'scanning',
	'sync_mode'            => 'delta',
	'delta_mod_from'       => iso( $from ),
	'delta_window_from'    => '',
	'delta_cursor'         => '',
	'delta_offset'         => 0,
	'delta_window_minutes' => 2880,
) ) );
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
$IMP->fetch_delta_events();
$state = TCGiant_Sync_Importer::get_sync_state();
check( 'the delta window finishes', $state['status'] ?? null, 'complete' );
check( '  no product made', $CREATED, array() );
check( '  no error counted', (int) ( $state['total_errors'] ?? -1 ), 0 );
check( '  no "Failed to save" line', logged( 'Failed to save' ), false );
check( '  nothing held back', TCGiant_Sync_Importer::held_back_listings(), array() );
check( '  the skip is said once', log_lines_naming( 'Skipped eBay auction ' . $NEW ), 1 );

// The shop then asks for that auction by number, under Sync specific items.
// The delta's skip was under ten days old, so the run ended "0 imported, 0
// errors" with no word of why.
set_transient( 'tcgiant_relist_declined_' . $NEW, 1 );
$IMP->start_specific_sync( array( $NEW ) );
check( 'Sync specific items: what was said once about the auction is forgotten', array( get_transient( 'tcgiant_auction_skip_' . $NEW ), get_transient( 'tcgiant_relist_declined_' . $NEW ) ), array( false, false ) );
$IMP->process_item_import( $NEW );
check( '  and its import says again why it is left out', log_lines_naming( 'Skipped eBay auction ' . $NEW ), 2 );
check( '  still no product, and no error', array( $CREATED, (int) ( TCGiant_Sync_Importer::get_sync_state()['total_errors'] ?? -1 ) ), array( array(), 0 ) );

// A declined relist asked for by number is looked at afresh too.
fresh_shop();
auction_product( 587, $OLD, array( '_ebay_listing_status' => 'Ended', '_stock' => 0, '_stock_status' => 'outofstock' ) );
ebay_has( $OLD, auction( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ), 'ListingDetails' => array( 'RelistedItemID' => $NEW ) ) ) );
ebay_has( $NEW, auction( $NEW, array( 'RelistParentID' => $OLD ) ) );
import( $EBAY['items'][ $NEW ]['Item'] );
$IMP->start_specific_sync( array( $NEW ) );
$IMP->process_item_import( $NEW );
check( 'a declined relist asked for by number: 111 is asked about again, and the answer said again', array( count( array_keys( $EBAY['get_items'], $OLD, true ) ), log_lines_naming( 'sold its only item' ) ), array( 2, 2 ) );

// A full scan's page: the auction is still recorded as live on eBay, so the
// pruner never takes a product linked to it for an orphan.
fresh_shop();
$page = new ReflectionMethod( 'TCGiant_Sync_Importer', 'process_page_items' );
if ( PHP_VERSION_ID < 80100 ) {
	$page->setAccessible( true );
}
$scan_item = auction( $NEW, array( 'ItemSpecifics' => array( 'NameValueList' => array() ) ) );
$page->invoke( $IMP, array( $scan_item ), 1, 1 );
check( 'a scan page holding it: recorded as live for the pruner', in_array( $NEW, (array) get_option( TCGiant_Sync_Importer::ACTIVE_IDS_OPTION, array() ), true ), true );
check( '  no product made', $CREATED, array() );
check( '  no error counted', (int) ( TCGiant_Sync_Importer::get_sync_state()['total_errors'] ?? -1 ), 0 );

echo "\nTHE HOURLY CHECK MOVES A PRODUCT ON TO THE RELIST EBAY NAMES\n" . str_repeat( '=', 134 ) . "\n";

// The real hourly ended-listing check, with the database answering its query
// from the products' end times.
require $ROOT . '/includes/class-tcgiant-sync-cron.php';
$CRON = TCGiant_Sync_Cron::instance();

// The coin shop's auction 111, listed from WooCommerce, ended unsold, and the
// seller relisted it in Seller Hub as 222 before the next delta sync. The
// hourly check read 111, settled it - so the coin went back on sale here -
// and left the product on 111 while 222 ran. A website sale of the coin then
// sent its stock change to 111, which had ended, and 222 stayed up.
$ended_at = iso( time() - 1800 );
$relist_222 = auction( $NEW, array( 'SKU' => 'COIN-950', 'RelistParentID' => $OLD, 'SellingStatus' => array( 'BidCount' => '0', 'CurrentPrice' => '25.00' ), 'ListingDetails' => array( 'StartTime' => iso( time() - 600 ), 'EndTime' => iso( time() + 7 * DAY_IN_SECONDS ) ) ) );
foreach ( array( 'a seven-day auction' => 'Days_7', 'one whose length was never recorded' => '' ) as $label => $duration ) {
	fresh_shop();
	auction_product( 950, $OLD, array( '_sku' => 'COIN-950', '_ebay_sku' => 'COIN-950', '_regular_price' => '25.00', '_ebay_listing_duration' => $duration, '_ebay_end_time' => $ended_at, '_ebay_export_last_pushed' => '2026-10-05 10:00:00', '_ebay_auction_listed_item' => $OLD ) );
	ebay_has( $OLD, auction( $OLD, array( 'SKU' => 'COIN-950', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0', 'CurrentPrice' => '25.00' ), 'ListingDetails' => array( 'EndTime' => $ended_at, 'RelistedItemID' => $NEW ) ) ) );
	ebay_has( $NEW, $relist_222 );
	$CRON->check_ended_listings();
	check( sprintf( 'the hourly check, %s relisted on eBay: the product moves on to the relist', $label ), array( meta( 950, '_ebay_item_id' ), meta( 950, '_ebay_listing_status' ), meta( 950, '_ebay_end_time' ) ), array( $NEW, 'Active', $relist_222['ListingDetails']['EndTime'] ) );
	check( '  eBay asked about each listing once', $EBAY['get_items'], array( $OLD, $NEW ) );
	check( '  the log says so', logged( 'Ended listing check: eBay listing ' . $NEW . ' is a relist of ' . $OLD . '; WC #950 now follows the new listing' ), true );
	check( '  still the shop\'s own auction, and not for sale here while it runs', array( meta( 950, '_ebay_auction_listed_item' ), purchasable( 950 ) ), array( $NEW, false ) );
	check( '  its stock as it was', stock( 950 ), 1 );
}
// The coin then sells over the counter: the push reaches the live relist,
// which nobody has bid on, and ends it.
$META[950]['_stock']        = 0;
$META[950]['_stock_status'] = 'outofstock';
$EBAY['wire'] = array();
$INV->process_ebay_stock_update( 'COIN-950', 0 );
check( 'a stock push to 0 then ends the relist', array( wire_calls(), false !== strpos( $EBAY['wire'][0][1] ?? '', '<ItemID>' . $NEW . '</ItemID>' ) ), array( array( 'EndItem' ), true ) );

// A relist another product already follows: left to it.
fresh_shop();
auction_product( 951, $OLD, array( '_ebay_listing_duration' => 'Days_7', '_ebay_end_time' => $ended_at, '_ebay_export_last_pushed' => '2026-10-05 10:00:00', '_ebay_auction_listed_item' => $OLD ) );
auction_product( 952, $NEW );
ebay_has( $OLD, auction( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0' ), 'ListingDetails' => array( 'EndTime' => $ended_at, 'RelistedItemID' => $NEW ) ) ) );
$CRON->check_ended_listings();
check( 'a relist another product follows: this one stays on the ended listing, settled', array( meta( 951, '_ebay_item_id' ), meta( 951, '_ebay_listing_status' ), settle_marks( 951 ) ), array( $OLD, 'Ended', array( true, $OLD ) ) );
check( '  and eBay is not asked about the relist', $EBAY['get_items'], array( $OLD ) );

// The hourly check reads a listing it thought had ended and finds it live:
// the type eBay gives is kept, for that listing (3.28.0) - a stock push may
// then end it without asking eBay what it is.
fresh_shop();
product( 958, array( '_sku' => 'PIKA-958', '_ebay_item_id' => '440000000958', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_listing_duration' => 'GTC', '_ebay_end_time' => $ended_at, '_stock' => 1 ) );
ebay_has( '440000000958', fixed( '440000000958' ) );
$CRON->check_ended_listings();
check( 'the hourly check finds a listing live: its type kept for it', array( meta( 958, '_ebay_listing_status' ), meta( 958, '_ebay_listing_type_item' ) ), array( 'Active', '440000000958' ) );

// The card shop's auction 111, imported from eBay at its $0.99 opening bid,
// ended unsold. The seller relisted it as Buy It Now at $50, 222, before the
// next delta sync. The hourly check moved the product on to 222 with no
// import, so the price stayed $0.99 - and, no longer on an auction, the card
// could be bought here at that until the delta imported 222.
$fixed_222 = fixed( $NEW, array( 'RelistParentID' => $OLD, 'Quantity' => '1', 'StartPrice' => '50.00', 'SellingStatus' => array( 'CurrentPrice' => '50.00' ) ) );
$unsold_111 = auction( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0', 'CurrentPrice' => '0.99' ), 'ListingDetails' => array( 'EndTime' => $ended_at, 'RelistedItemID' => $NEW ) ) );
fresh_shop( array( 'import_auctions' => '1' ) );
auction_product( 953, $OLD, array( '_ebay_listing_duration' => 'Days_7', '_ebay_end_time' => $ended_at, '_ebay_start_price' => '0.99', '_ebay_current_price' => '0.99', '_ebay_bid_count' => '0', '_sync_last_updated' => '2026-10-12 10:00:00' ) );
ebay_has( $OLD, $unsold_111 );
ebay_has( $NEW, $fixed_222 );
$CRON->check_ended_listings();
check( 'an imported auction relisted at a fixed price: the hourly check leaves the product on the auction', array( meta( 953, '_ebay_item_id' ), meta( 953, '_ebay_listing_type' ), meta( 953, '_ebay_listing_status' ) ), array( $OLD, 'Chinese', 'Ended' ) );
check( '  so it cannot be bought here at the $0.99 opening bid', array( meta( 953, '_regular_price' ), purchasable( 953 ) ), array( '0.99', false ) );
check( '  the log says the import of 222 moves it', logged( 'Ended listing check: eBay listing ' . $NEW . ' is a relist of auction ' . $OLD . ' in another format. WC #953, imported from that auction, is moved on to it when ' . $NEW . ' is imported, which also sets its price.' ), true );
$got = import( $fixed_222 );
check( '  the delta\'s import of 222 then moves it, at the relist\'s $50.00', array( $got, meta( 953, '_ebay_item_id' ), meta( 953, '_ebay_listing_type' ), meta( 953, '_regular_price' ) ), array( 953, $NEW, 'FixedPriceItem', '50.00' ) );
check( '  and it is on sale here at that', purchasable( 953 ), true );

// The coin shop's own auction relisted at a fixed price: its price is the
// shop's own, so it is moved on at once.
fresh_shop();
auction_product( 954, $OLD, array( '_regular_price' => '45.00', '_ebay_listing_duration' => 'Days_7', '_ebay_end_time' => $ended_at, '_ebay_export_last_pushed' => '2026-10-05 10:00:00', '_ebay_auction_listed_item' => $OLD ) );
ebay_has( $OLD, $unsold_111 );
ebay_has( $NEW, $fixed_222 );
$CRON->check_ended_listings();
check( 'the shop\'s own auction relisted at a fixed price: the hourly check moves it on', array( meta( 954, '_ebay_item_id' ), meta( 954, '_ebay_listing_type' ), meta( 954, '_regular_price' ) ), array( $NEW, 'FixedPriceItem', '45.00' ) );
check( '  on sale here at its own price', purchasable( 954 ), true );

// An imported auction's fixed-price relist that has already sold out on
// eBay: moved on by the sale check and settled to nothing, so there is
// nothing left to sell here at the opening bid.
fresh_shop( array( 'import_auctions' => '1' ) );
auction_product( 955, $OLD, array( '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-10-09 10:00:00', '_ebay_stock_settled_item' => $OLD, '_ebay_quantity_listed' => 1, '_ebay_quantity_sold' => 0 ) );
ebay_has( $OLD, $unsold_111 );
ebay_has( $NEW, fixed( $NEW, array( 'RelistParentID' => $OLD, 'Quantity' => '1', 'StartPrice' => '50.00', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1', 'CurrentPrice' => '50.00' ) ) ) );
$IMP->settle_item( $NEW );
check( 'an imported auction\'s fixed-price relist that sold out: moved on and settled to 0', array( meta( 955, '_ebay_item_id' ), stock( 955 ), wc_get_product( 955 )->is_in_stock() ), array( $NEW, 0, false ) );

// The coin shop's auction 111 ended unsold, and the seller relisted it on
// eBay as 222. Something other than an import moved the product on to 222:
// the hourly check, or the per-sale stock check. 222 then took bids, and the
// coin sold over the counter; the push leaves an auction with bids running.
// 222's first import wrote eBay's 1 back over the 0 with nothing said, and
// once 222 ended unsold the coin that had gone was for sale here again.
foreach ( array( 'the hourly check', 'the sale check' ) as $route ) {
	fresh_shop();
	$pushed = array( '_sku' => 'COIN-956', '_ebay_sku' => 'COIN-956', '_regular_price' => '450.00', '_ebay_export_last_pushed' => '2026-10-05 10:00:00', '_ebay_auction_listed_item' => $OLD, '_ebay_end_time' => $ended_at );
	auction_product( 956, $OLD, $pushed + ( 'the hourly check' === $route
		? array( '_ebay_listing_duration' => 'Days_7' )
		: array( '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => gmdate( 'Y-m-d H:i:s' ), '_ebay_stock_settled_item' => $OLD, '_ebay_quantity_listed' => 1, '_ebay_quantity_sold' => 0 ) ) );
	ebay_has( $OLD, auction( $OLD, array( 'SKU' => 'COIN-956', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0', 'CurrentPrice' => '450.00' ), 'ListingDetails' => array( 'EndTime' => $ended_at, 'RelistedItemID' => $NEW ) ) ) );
	ebay_has( $NEW, auction( $NEW, array( 'SKU' => 'COIN-956', 'RelistParentID' => $OLD, 'StartPrice' => '450.00', 'SellingStatus' => array( 'BidCount' => '0', 'CurrentPrice' => '450.00' ), 'ListingDetails' => array( 'StartTime' => iso( time() - 600 ), 'EndTime' => iso( time() + 7 * DAY_IN_SECONDS ) ) ) ) );
	if ( 'the hourly check' === $route ) {
		$CRON->check_ended_listings();
	} else {
		$IMP->settle_item( $NEW );
	}
	check( sprintf( 'moved on to the live relist by %s, holding 1', $route ), array( meta( 956, '_ebay_item_id' ), stock( 956 ), ( meta( 956, '_ebay_relist_stock_check' ) ?: array() )['held'] ?? null ), array( $NEW, 1, 1 ) );
	ebay_has( $NEW, auction( $NEW, array( 'SKU' => 'COIN-956', 'RelistParentID' => $OLD, 'StartPrice' => '450.00', 'SellingStatus' => array( 'BidCount' => '2', 'CurrentPrice' => '460.00' ), 'ListingDetails' => array( 'StartTime' => iso( time() - 600 ), 'EndTime' => iso( time() + 7 * DAY_IN_SECONDS ) ) ) ) );
	$META[956]['_stock']        = 0;
	$META[956]['_stock_status'] = 'outofstock';
	$EBAY['wire']               = array();
	$INV->process_ebay_stock_update( 'COIN-956', 0 );
	check( '  the coin sells over the counter while 222 has two bids: nothing is sent to eBay', wire_calls(), array() );
	$LOG = array();
	$got = import( $EBAY['items'][ $NEW ]['Item'] );
	check( '  222\'s first import keeps the stock at 0', array( $got, stock( 956 ), meta( 956, '_stock_status' ) ), array( 956, 0, 'outofstock' ) );
	check( '  the product\'s history says why', in_array( 'Stock kept at 0: WooCommerce held less than the auction, which is one item', meta( 956, '_tcgiant_sync_log' )[0]['decisions'] ?? array(), true ), true );
	check( '  and no warning tells the shop what WooCommerce held', logged( 'WooCommerce held' ), false );
	ebay_has( $NEW, auction( $NEW, array( 'SKU' => 'COIN-956', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '2', 'QuantitySold' => '0', 'CurrentPrice' => '460.00' ) ) ) );
	$IMP->settle_item( $NEW );
	check( '  222 ends unsold: settled at 0, so the coin that has gone is not for sale here', array( settle_marks( 956 ), stock( 956 ), wc_get_product( 956 )->is_in_stock() ), array( array( true, $NEW ), 0, false ) );
}

// Item 8's case through the hourly check: the coin sold on the website before
// the move, so WooCommerce held 0 then. The relist still offers it on eBay,
// so its figure applies and the shop is told to end it.
fresh_shop();
auction_product( 958, $OLD, array( '_sku' => 'COIN-958', '_ebay_sku' => 'COIN-958', '_ebay_end_time' => $ended_at, '_ebay_export_last_pushed' => '2026-10-05 10:00:00', '_ebay_auction_listed_item' => $OLD, '_stock' => 0, '_stock_status' => 'outofstock' ) );
ebay_has( $OLD, auction( $OLD, array( 'SKU' => 'COIN-958', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0' ), 'ListingDetails' => array( 'EndTime' => $ended_at, 'RelistedItemID' => $NEW ) ) ) );
ebay_has( $NEW, auction( $NEW, array( 'SKU' => 'COIN-958', 'RelistParentID' => $OLD, 'SellingStatus' => array( 'BidCount' => '0' ), 'ListingDetails' => array( 'StartTime' => iso( time() - 600 ), 'EndTime' => iso( time() + 7 * DAY_IN_SECONDS ) ) ) ) );
$CRON->check_ended_listings();
check( 'holding 0 when the hourly check moved it on', array( meta( 958, '_ebay_item_id' ), stock( 958 ), ( meta( 958, '_ebay_relist_stock_check' ) ?: array() )['held'] ?? null ), array( $NEW, 0, 0 ) );
import( $EBAY['items'][ $NEW ]['Item'] );
check( '  222\'s first import applies eBay\'s 1', stock( 958 ), 1 );
check( '  and warns what WooCommerce held', logged( 'which offers 1; WooCommerce held 0', 'warning' ), true );

// The coin shop's auction 111 sold its only coin, and the seller used Relist
// on it for the next coin, 222. A single item that sold is never moved on to
// its relist, so eBay is not asked about 222 only to be told so.
fresh_shop();
auction_product( 957, $OLD, array( '_ebay_listing_duration' => 'Days_7', '_ebay_end_time' => $ended_at, '_ebay_export_last_pushed' => '2026-10-05 10:00:00', '_ebay_export_status' => 'pushed', '_ebay_auction_listed_item' => $OLD ) );
ebay_has( $OLD, auction( $OLD, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '6', 'QuantitySold' => '1' ), 'ListingDetails' => array( 'EndTime' => $ended_at, 'RelistedItemID' => $NEW ) ) ) );
ebay_has( $NEW, auction( $NEW, array( 'RelistParentID' => $OLD, 'SellingStatus' => array( 'BidCount' => '0' ) ) ) );
$CRON->check_ended_listings();
check( 'the shop\'s auction sold its only coin and was relisted for the next: eBay asked only about 111', $EBAY['get_items'], array( $OLD ) );
check( '  the product stays on 111, Ended, at 0, and is kept', array( meta( 957, '_ebay_item_id' ), meta( 957, '_ebay_listing_status' ), stock( 957 ), get_post_status( 957 ) ), array( $OLD, 'Ended', 0, 'publish' ) );

echo "\nTHE NOTICE AFTER THE UPGRADE\n" . str_repeat( '=', 134 ) . "\n";

// The real admin class's notice and its Dismiss, with the admin screen played.
require $ROOT . '/admin/class-tcgiant-sync-admin.php';
class Harness_Redirect extends Exception {}
$CAN    = true;
$SCREEN = 'toplevel_page_tcgiant-sync';
function current_user_can( $cap ) { return $GLOBALS['CAN']; }
function get_current_screen() { $s = new stdClass(); $s->id = $GLOBALS['SCREEN']; return $s; }
function esc_html_e( $text ) { echo esc_html( $text ); }
function admin_url( $path = '' ) { return 'https://cardshop.example.com/wp-admin/' . $path; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=nonce-' . $action; }
function check_admin_referer( $action ) { return 1; }
function wp_get_referer() { return 'https://cardshop.example.com/wp-admin/edit.php?post_type=product'; }
function wp_safe_redirect( $to ) { throw new Harness_Redirect( $to ); }
function wp_die( $message = '' ) { throw new Harness_Redirect( 'died' ); }
$ADMIN = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();
$notice_html = function () use ( $ADMIN ) {
	ob_start();
	if ( method_exists( $ADMIN, 'auction_import_notice' ) ) {
		$ADMIN->auction_import_notice();
	}
	return (string) ob_get_clean();
};

fresh_shop( array(), false );
$OPTIONS[ NOTICE_OPTION ] = 'on';
$shown = $notice_html();
check( 'turned on at upgrade: the notice shows on the plugin\'s screens', false !== strpos( $shown, 'eBay auctions can no longer be bought on your website while they run' ), true );
check( '  says Import eBay auctions was turned on', false !== strpos( $shown, 'Import eBay auctions has been turned on' ), true );
// The card shop's auction cards that ended unsold sold here on 3.27.2. They
// do not now, and "while they run" alone said nothing of them.
check( '  says an imported auction stays off sale after an unsold end too', false !== strpos( $shown, 'A product imported from an eBay auction also stays off sale here after the auction ends unsold, because its price is the opening bid on eBay rather than one you set.' ), true );
check( '  and how to sell one here', false !== strpos( $shown, 'To sell one here, first set your own price, then push it as Fixed Price or unlink it on its eBay Listing tab.' ), true );
check( '  links to the setting', false !== strpos( $shown, 'page=tcgiant-settings#tc-import-auctions' ), true );
check( '  and offers Dismiss', false !== strpos( $shown, 'action=tcgiant_dismiss_auction_notice' ), true );
$SCREEN = 'edit-product';
check( '  and on the Products list', '' !== $notice_html(), true );
$SCREEN = 'edit-post';
check( '  but not on unrelated screens', $notice_html(), '' );
$SCREEN = 'toplevel_page_tcgiant-sync';
$CAN    = false;
check( '  nor to someone who cannot change settings', $notice_html(), '' );
$CAN     = true;
$back_to = null;
try {
	if ( method_exists( $ADMIN, 'handle_dismiss_auction_notice' ) ) {
		$ADMIN->handle_dismiss_auction_notice();
	}
} catch ( Harness_Redirect $back ) {
	$back_to = $back->getMessage();
}
check( 'Dismiss goes back where it was pressed', $back_to, 'https://cardshop.example.com/wp-admin/edit.php?post_type=product' );
check( '  and the notice stays away', array( $OPTIONS[ NOTICE_OPTION ] ?? null, $notice_html() ), array( 'dismissed', '' ) );
$OPTIONS[ NOTICE_OPTION ] = 'off';
check( 'a shop that had nothing turned on sees nothing', $notice_html(), '' );

echo "\nUNLINKED, THEN LINKED TO ANOTHER AUCTION\n" . str_repeat( '=', 134 ) . "\n";

// The real Unlink, and the real Link to this listing on the eBay Listing tab,
// with WordPress's AJAX played: its answer ends the request.
class Harness_Json extends Exception {
	public $ok;
	public function __construct( $ok, $data ) {
		$this->ok = $ok;
		parent::__construct( is_array( $data ) ? (string) ( $data['message'] ?? '' ) : (string) $data );
	}
}
function check_ajax_referer( $action ) { return 1; }
function absint( $value ) { return abs( (int) $value ); }
function wp_unslash( $value ) { return $value; }
function wp_send_json_error( $data = null ) { throw new Harness_Json( false, $data ); }
function wp_send_json_success( $data = null ) { throw new Harness_Json( true, $data ); }
function link_to_listing( $product_id, $item_id ) {
	$_POST = array( 'product_id' => $product_id, 'ebay_item_id' => $item_id );
	try {
		$GLOBALS['ADMIN']->ajax_link_ebay();
	} catch ( Harness_Json $answer ) {
		return $answer->ok;
	}
	return null;
}
$figure_keys = array( '_ebay_start_price', '_ebay_current_price', '_ebay_bid_count', '_ebay_buy_it_now_price' );
$figures_on  = function ( $id ) use ( $figure_keys ) {
	return array_values( array_filter( $figure_keys, function ( $key ) use ( $id ) { return has_meta( $id, $key ); } ) );
};

// A card imported at "current bid $80.00 (5 bids)". The shop unlinks it and
// links it to the seller's new auction 333, which nobody has bid on. The old
// auction's bids were shown on 333 until it was next imported - for an
// auction nobody bids on, as late as the weekly full sync.
fresh_shop( array( 'import_auctions' => '1' ) );
$THIRD = '330000000333';
$card  = import( auction( $OLD, array( 'SellingStatus' => array( 'CurrentPrice' => '80.00', 'BidCount' => '5' ) ) ) );
check( 'an imported auction with five bids at $80.00', array( meta( $card, '_ebay_current_price' ), meta( $card, '_ebay_bid_count' ) ), array( '80.00', '5' ) );
TCGiant_Sync_Listing_Link::unlink_product_from_ebay( $card );
check( '  Unlink removes its bids and prices with the link', $figures_on( $card ), array() );
ebay_has( $THIRD, auction( $THIRD, array( 'StartPrice' => '49.00', 'SellingStatus' => array( 'CurrentPrice' => '49.00', 'BidCount' => '0' ) ) ) );
check( '  linked to auction 333', array( link_to_listing( $card, $THIRD ), meta( $card, '_ebay_item_id' ) ), array( true, $THIRD ) );
$html = filter_price_html( 'x', wc_get_product( $card ) );
check( '  the shop shows the auction, with none of the old one\'s bids', array( false !== strpos( $html, 'Auction on eBay' ), false !== strpos( $html, '$80.00' ), false !== strpos( $html, '5 bids' ) ), array( true, false, false ) );

// One unlinked by an earlier build, which left the figures behind: linking
// it drops them too.
product( 621, array( '_regular_price' => '0.99', '_ebay_start_price' => '0.99', '_ebay_current_price' => '80.00', '_ebay_bid_count' => '5', '_ebay_buy_it_now_price' => '120.00' ) );
ebay_has( '440000000444', auction( '440000000444', array( 'StartPrice' => '49.00', 'SellingStatus' => array( 'CurrentPrice' => '49.00', 'BidCount' => '0' ) ) ) );
check( 'a product still holding an old auction\'s figures, linked to auction 444', array( link_to_listing( 621, '440000000444' ), meta( 621, '_ebay_item_id' ) ), array( true, '440000000444' ) );
check( '  the figures are gone', $figures_on( 621 ), array() );
$html = filter_price_html( 'x', wc_get_product( 621 ) );
check( '  and the shop shows neither the old bids nor the old Buy It Now', array( false !== strpos( $html, 'Auction on eBay' ), false !== strpos( $html, '$80.00' ), false !== strpos( $html, '$120.00' ) ), array( true, false, false ) );

echo "\nA STOCK CHANGE STILL ON ITS WAY TO EBAY HOLDS BACK AN IMPORT (3.28.0)\n" . str_repeat( '=', 134 ) . "\n";

// A card sold on the website: WooCommerce 1, and its push to eBay not run yet.
// The import's figures are from before it - written over the 1, they put
// back a card that has gone, which the push then sends eBay as the shop's.
fresh_shop();
product( 980, array( '_sku' => 'PIKA-980', '_ebay_sku' => 'PIKA-980', '_ebay_item_id' => '440000000980', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_stock' => 1, '_ebay_push_pending' => array( 'sale' => time() + HOUR_IN_SECONDS ) ) );
$got = import( fixed( '440000000980', array( 'SKU' => 'PIKA-980', 'Quantity' => '3' ) ) );
check( 'a push is on its way: the import keeps WooCommerce\'s 1, not eBay\'s older 3', array( $got, stock( 980 ) ), array( 980, 1 ) );
check( '  the product\'s history says why', in_array( 'Stock kept at 1: a stock change made in WooCommerce is still on its way to eBay', meta( 980, '_tcgiant_sync_log' )[0]['decisions'] ?? array(), true ), true );
check( '  and eBay\'s sales are not recorded as in the stock: they are not', meta( 980, '_ebay_sold_seen' ), null );
// Nothing on its way: eBay's figure stands, so stock raised in Seller Hub
// reaches the website.
$META[980]['_ebay_push_pending'] = array( 'sale' => time() - 1 );
import( fixed( '440000000980', array( 'SKU' => 'PIKA-980', 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '2' ) ) ) );
check( '  once nothing is on its way, eBay\'s figure stands: 3', stock( 980 ), 3 );
check( '  with eBay\'s 2 sales recorded as in it, for the stock push (3.28.0)', meta( 980, '_ebay_sold_seen' ), array( 'item' => '440000000980', 'sku' => '', 'sold' => 2 ) );
check( '  and the listing type as eBay said it, for this listing', array( meta( 980, '_ebay_listing_type' ), meta( 980, '_ebay_listing_type_item' ) ), array( 'FixedPriceItem', '440000000980' ) );
// Nor does a hand edit's push on its way keep a figure higher than eBay's
// (decision 10: only ever the lower).
$META[980]['_ebay_push_pending'] = array( 'edit' => time() + HOUR_IN_SECONDS );
$META[980]['_stock']             = 5;
import( fixed( '440000000980', array( 'SKU' => 'PIKA-980', 'Quantity' => '2' ) ) );
check( '  only ever the lower: WooCommerce 5, eBay 2, a hand edit\'s push on its way - 2', stock( 980 ), 2 );
// A website sale's push on its way is another matter (fix round 2, R2-H2,
// a refinement of decision 10): eBay's figure does not have that sale in it
// whether it is higher or lower - lower when eBay has sold some too - so
// WooCommerce's stock and the record of eBay's sales are both left alone,
// and the sale's push sorts them out through the record. Written over, the
// website's sale was wiped, and the push found nothing to send.
$META[980]['_ebay_push_pending'] = array( 'sale-6a1b' => time() + HOUR_IN_SECONDS );
$META[980]['_stock']             = 5;
$META[980]['_ebay_sold_seen']    = array( 'item' => '440000000980', 'sku' => '', 'sold' => 2 );
import( fixed( '440000000980', array( 'SKU' => 'PIKA-980', 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '3' ) ) ) );
check( '  a website sale\'s push on its way, eBay lower at 2: WooCommerce\'s 5 and the record (2) kept', array( stock( 980 ), meta( 980, '_ebay_sold_seen' )['sold'] ?? null ), array( 5, 2 ) );
check( '  and the history says why', in_array( 'Stock kept at 5: a website sale is still on its way to eBay', meta( 980, '_tcgiant_sync_log' )[0]['decisions'] ?? array(), true ), true );
$META[980]['_ebay_push_pending'] = array( 'unsent' => time() + DAY_IN_SECONDS );
import( fixed( '440000000980', array( 'SKU' => 'PIKA-980', 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '3' ) ) ) );
check( '  a sale whose push never got there holds it too', stock( 980 ), 5 );
// An entry as item 14's fix round 8 writes it, array( until, queued_at ): read alike (3.28.0 integration).
$META[980]['_ebay_push_pending'] = array( 'sale-6a1d' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) );
$META[980]['_stock']             = 5;
$META[980]['_ebay_sold_seen']    = array( 'item' => '440000000980', 'sku' => '', 'sold' => 2 );
import( fixed( '440000000980', array( 'SKU' => 'PIKA-980', 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '3' ) ) ) );
check( '  a sale\'s push on its way, its entry array( until, queued_at ): held alike - 5, the record 2', array( stock( 980 ), meta( 980, '_ebay_sold_seen' )['sold'] ?? null ), array( 5, 2 ) );
$META[980]['_ebay_push_pending'] = array( 'edit-6a1e' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) );
$META[980]['_stock']             = 1;
import( fixed( '440000000980', array( 'SKU' => 'PIKA-980', 'Quantity' => '3' ) ) );
check( '    and a push on its way in that shape keeps WooCommerce\'s 1, not eBay\'s older 3', stock( 980 ), 1 );

// L1, L1b (the oversell-and-ends review): eBay sells, the website sells,
// then the delta imports before the website sale's push has run.
foreach ( array( 'L1 one eBay sale' => array( 1, 3 ), 'L1b two eBay sales, eBay below WooCommerce' => array( 2, 2 ) ) as $what => $case ) {
	list( $ebay_sold, $shelf ) = $case;
	fresh_shop();
	product( 985, array( '_sku' => 'PIKA-985', '_ebay_sku' => 'PIKA-985', '_ebay_item_id' => '440000000985', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_stock' => 5 ) );
	import( fixed( '440000000985', array( 'SKU' => 'PIKA-985', 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '0' ) ) ) );
	$META[985]['_stock']             = 4;
	$META[985]['_ebay_push_pending'] = array( 'sale-6a1c' => time() + HOUR_IN_SECONDS );
	import( fixed( '440000000985', array( 'SKU' => 'PIKA-985', 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => (string) $ebay_sold ) ) ) );
	check( 'R2-H2 ' . $what . ', the website one, then the import before the sale\'s push: WooCommerce\'s 4 and the record (0) kept, for the push to take eBay\'s off (shelf ' . $shelf . ')', array( stock( 985 ), meta( 985, '_ebay_sold_seen' )['sold'] ?? null ), array( 4, 0 ) );
}

echo "\nIN THE SOURCE\n" . str_repeat( '=', 134 ) . "\n";

$settings_view = (string) file_get_contents( $ROOT . '/admin/views/settings.php' );
check( 'the Import Settings form has the field, off unless chosen', false !== strpos( $settings_view, "checked( \$settings['import_auctions'] ?? '0', '1' )" ), true );
// 3.28.0: each form changes only its own settings (TCGiant_Sync_Admin::SETTINGS_FORMS).
check( 'saving the Push to eBay form keeps it: it is Import Settings\' own', array( in_array( 'import_auctions', TCGiant_Sync_Admin::SETTINGS_FORMS['import'], true ), in_array( 'import_auctions', TCGiant_Sync_Admin::SETTINGS_FORMS['export'], true ) ), array( true, false ) );

$admin = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
$start = strpos( $admin, 'function ajax_sync_order_to_ebay' );
$body  = false === $start ? '' : substr( $admin, $start, 5000 );
// Since 3.28.0 the order screen's push is the stock push itself, run there and
// then, so an auction meets the push's one auction rule.
$inventory_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-inventory.php' );
check( 'the order screen\'s push goes through the stock push, never straight to eBay', array( false !== strpos( $body, '->push_now( $product' ), false !== strpos( $body, '->update_inventory_item_availability(' ), false !== strpos( $body, '->end_item(' ) ), array( true, false, false ) );
check( '  where an auction takes the auction rule', false !== strpos( $inventory_src, '$target[\'auction\']' . "\n\t\t\t\t? \$this->push_auction(" ), true );

// A push records the auction as the shop's only when it put the listing up -
// created or relisted it. An Update revises a listing that may be eBay's.
$exporter = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-exporter.php' );
// 12,000 characters: 3.27.8's record of the description sent sits in between.
$push     = (string) substr( $exporter, (int) strpos( $exporter, 'function process_single_push' ), 12000 );
$branch   = strpos( $push, "if ( 'updated' !== \$action ) {" );
$mark     = strpos( $push, 'update_meta_data( TCGiant_Sync_Inventory::AUCTION_LISTED_KEY' );
$after    = strpos( $push, "if ( ! empty( \$result['previous_item_id'] ) )" );
check( 'a push marks an auction as the shop\'s only when it created or relisted it, not on an Update', false !== $branch && false !== $mark && false !== $after && $branch < $mark && $mark < $after, true );

check( 'uninstall removes the upgrade\'s option',false !== strpos( (string) file_get_contents( $ROOT . '/uninstall.php' ), "'" . NOTICE_OPTION . "'" ), true );

$cron = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-cron.php' );
check( 'the upgrade runs on admin_init', false !== strpos( $cron, "add_action( 'admin_init', array( 'TCGiant_Sync_Mapper', 'upgrade_auction_import' ) )" ), true );
check( '  and from the hourly check, for sites nobody opens', false !== strpos( $cron, "add_action( 'tcgiant_sync_check_ended_listings', array( 'TCGiant_Sync_Mapper', 'upgrade_auction_import' )" ), true );

echo "\nONLY THE SHOP'S OWN EBAY SITE IMPORTED: NO AUCTION FROM ANOTHER, NOR ITS RELIST (3.27.5 WITH 3.28.0)\n" . str_repeat( '=', 134 ) . "\n";

// "Import listings from: only my Primary eBay Marketplace" (3.27.5) beside
// "Import eBay auctions" (3.28.0): an auction on a Web Interpret copy is no
// more imported than a fixed-price one.
$ONLY_US_AUCTIONS = array( 'import_auctions' => '1', 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' );
fresh_shop( $ONLY_US_AUCTIONS );
$got = import( auction( '440000000956', array( 'Site' => 'Germany' ) ) );
check( 'Import eBay auctions on, an auction on eBay Germany: not imported', array( $got, $CREATED ), array( false, array() ) );
check( '  said as any other site\'s listing is', logged( 'Skipped eBay item 440000000956' ), true );
fresh_shop( $ONLY_US_AUCTIONS );
check( '  one on eBay United States: imported, as before', import( auction( '440000000957', array( 'Site' => 'US' ) ) ) > 0, true );

// The hourly check, which follows a relist eBay names (3.28.0): a copy's
// auction, imported before the setting was chosen, relisted on its own site,
// is not followed - and eBay is not asked about the relist.
fresh_shop( $ONLY_US_AUCTIONS );
auction_product( 955, $OLD, array( '_sku' => 'COIN-955', '_ebay_sku' => 'COIN-955', '_ebay_listing_duration' => 'Days_7', '_ebay_end_time' => $ended_at, '_ebay_site' => 'UK' ) );
ebay_has( $OLD, auction( $OLD, array( 'SKU' => 'COIN-955', 'Site' => 'UK', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0' ), 'ListingDetails' => array( 'EndTime' => $ended_at, 'RelistedItemID' => $NEW ) ) ) );
ebay_has( $NEW, auction( $NEW, array( 'SKU' => 'COIN-955', 'Site' => 'UK', 'RelistParentID' => $OLD, 'ListingDetails' => array( 'StartTime' => iso( time() - 600 ), 'EndTime' => iso( time() + 7 * DAY_IN_SECONDS ) ) ) ) );
$CRON->check_ended_listings();
check( 'the hourly check: a copy\'s auction relisted on eBay.co.uk stays on its ended listing', array( meta( 955, '_ebay_item_id' ), meta( 955, '_ebay_listing_status' ) ), array( $OLD, 'Ended' ) );
check( '  and eBay is not asked about the relist', $EBAY['get_items'], array( $OLD ) );
// All eBay sites: followed, as 3.28.0 follows it.
fresh_shop( array( 'import_auctions' => '1', 'marketplace' => 'EBAY_US' ) );
auction_product( 955, $OLD, array( '_sku' => 'COIN-955', '_ebay_sku' => 'COIN-955', '_ebay_listing_duration' => 'Days_7', '_ebay_end_time' => $ended_at, '_ebay_site' => 'UK' ) );
ebay_has( $OLD, auction( $OLD, array( 'SKU' => 'COIN-955', 'Site' => 'UK', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'BidCount' => '0' ), 'ListingDetails' => array( 'EndTime' => $ended_at, 'RelistedItemID' => $NEW ) ) ) );
ebay_has( $NEW, auction( $NEW, array( 'SKU' => 'COIN-955', 'Site' => 'UK', 'RelistParentID' => $OLD, 'ListingDetails' => array( 'StartTime' => iso( time() - 600 ), 'EndTime' => iso( time() + 7 * DAY_IN_SECONDS ) ) ) ) );
$CRON->check_ended_listings();
check( '  every site imported: the same relist is followed', meta( 955, '_ebay_item_id' ), $NEW );

echo "\nAN IMPORTED AUCTION KEEPS ITS BIDS AND TAKES ITS SHIPPING TOO (3.27.6 WITH 3.28.0)\n" . str_repeat( '=', 134 ) . "\n";

// The auction's own figures (3.28.0) and the listing's shipping (3.27.6, for
// a shop's own checkout code), from one answer, on one product.
fresh_shop( array( 'import_auctions' => '1' ) );
$shipped = auction( '440000000958', array(
	'SellingStatus'   => array( 'BidCount' => '3', 'CurrentPrice' => '41.00' ),
	'ShippingDetails' => array(
		'ShippingType'           => 'Flat',
		'ShippingServiceOptions' => array( 'ShippingService' => 'USPSPriority', 'ShippingServiceCost' => '7.50', 'ShippingServicePriority' => '1' ),
	),
) );
$got = import( $shipped );
check( 'an auction with its shipping: imported', $got > 0, true );
check( '  its bids and prices recorded', array( meta( $got, '_ebay_bid_count' ), meta( $got, '_ebay_current_price' ) ), array( '3', '41.00' ) );
check( '  and its shipping, as for any listing', array( meta( $got, '_ebay_shipping_type' ), meta( $got, '_ebay_shipping_service' ), meta( $got, '_ebay_shipping_cost' ) ), array( 'Flat', 'USPSPriority', '7.50' ) );

foreach ( glob( $UPLOADS . '/*' ) ?: array() as $f ) {
	@unlink( $f );
}
@rmdir( $UPLOADS );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
