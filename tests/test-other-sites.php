<?php
/**
 * Harness: "Import listings from" - only the listings on the shop's own eBay site.
 *
 * Rabbi Ent has 5,000 listings on eBay.com and uses Web Interpret to copy each
 * one onto eBay.co.uk, eBay.de, eBay.fr, eBay.it, eBay.com.au, eBay.ca and
 * more. Every copy is a listing of its own, with its own item number and its
 * own Item.Site, and Web Interpret copies the store categories too, so a
 * store-category allowlist cannot keep them apart. About twenty new listings a
 * day each make seven or eight copies. eBay hands the plugin all of them,
 * whatever site the request names, and 3.27.4 imported every copy as a product
 * of its own, priced in pounds or euros as though they were dollars. The
 * seller is registered in India and lists on eBay.com, so Primary eBay
 * Marketplace is United States and what decides is the LISTING's site, never
 * the seller's country or where the item sits.
 *
 * Web Interpret keeps each copy's quantity in step with the eBay.com listing,
 * so a sale on a copy arrives as a quantity change on the eBay.com listing,
 * which the delta brings to the website. A copy's sale must therefore never
 * change the website's stock by itself.
 *
 * Loaded for real: TCGiant_Sync_Sites, TCGiant_Sync_Mapper,
 * TCGiant_Sync_Importer (the pruner too), TCGiant_Sync_Inventory,
 * TCGiant_Sync_Runtime, TCGiant_Sync_API, TCGiant_Sync_Jobs,
 * TCGiant_Sync_Listing_Link, TCGiant_Sync_Admin (its settings sanitiser,
 * notice and "Link to this listing"), TCGiant_Sync_Exporter (its push and
 * relist; for the push, only the private listing builder is answered from a
 * script), and the Stock Review view. WordPress and WooCommerce are played
 * here: products are rows of post meta, and the database answers the plugin's
 * queries from them by what each query's SQL says - test-site-sql.php runs
 * that SQL itself. eBay is the real API class with its calls answered from a
 * script. Nothing is copied from the plugin.
 *
 * The first part is the prototype's decision tables, run against the real
 * class. Sections marked "fails on 3.27.4" do, on purpose: the copies are
 * imported, take over products, and their sales come off the website's stock.
 *
 * Relisting and auto-relist are in test-relist-guard.php, order sync in
 * test-sale-check.php and the order import in test-order-address.php, each in
 * the world built for that path.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-88s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$ROOT = dirname( __DIR__ );

// --- the shop the plugin runs in ---------------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$UPLOADS = sys_get_temp_dir() . '/tcg-other-sites-harness-' . getmypid();
if ( ! is_dir( $UPLOADS ) ) {
	mkdir( $UPLOADS, 0777, true );
}

$POSTS      = array(); // id => array( 'type', 'status', 'product_type' )
$META       = array(); // id => array( key => value ): WooCommerce's own fields too
$TERMS      = array(); // id => product_cat term IDs
$OPTIONS    = array();
$TRANSIENTS = array();
$LOG        = array();
$CREATED    = array(); // products made by save()
$WRITES     = array(); // every post meta and stock write: id, key
$DELETED    = array(); // wp_delete_post() calls - there must be none
$QUEUE      = array();
$HOOKS      = array(); // add_action(): hook => [ callback, args ]
$EBAY       = array();
$NEXT_ID    = 900;
$CAN_IMPORT = true;    // the free plan's verdict
$CAN        = true;    // current_user_can()
$USER_ID    = 1;       // get_current_user_id()
$SCREEN     = 'toplevel_page_tcgiant-sync';
$TRASH_FILTER  = null;  // what a pre_trash_post filter returns: null lets the trash go ahead
$TRASH_LOOPS   = array(); // product => how deep the save-loop breaker was round its trash

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

/** A product row and its meta, as the plugin's queries see it. */
function live_product( $id ) {
	$post = $GLOBALS['POSTS'][ $id ] ?? null;
	return $post && 'product' === $post['type'] && ! in_array( $post['status'], array( 'trash', 'auto-draft' ), true );
}

/**
 * The shop's own product, as the database is asked it (its tcg_own EXISTS):
 * a push that succeeded wrote one of these, or a push of it is on its way or
 * did not finish. tests/test-site-sql.php runs that SQL itself.
 */
function played_own( array $meta ) {
	return '' !== (string) ( $meta['_ebay_export_last_pushed'] ?? '' )
		|| '' !== (string) ( $meta['_ebay_export_pushed_version'] ?? '' )
		|| in_array( $meta['_ebay_export_status'] ?? null, array( 'pushed', 'queued', 'pushing', 'failed' ), true );
}

/**
 * The database, as far as these paths ask it anything. Each query is answered
 * from the posts and their meta, by what its SQL says; anything it does not
 * recognise finds nothing.
 */
class Harness_Wpdb {
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $options            = 'wp_options';
	public $term_relationships = 'wp_term_relationships';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $prefix             = 'wp_';
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
			if ( false !== strpos( $query, "post_status != 'trash'" ) && 'trash' === $post['status'] ) {
				continue;
			}
			if ( false !== strpos( $query, "AND p.post_status = 'trash'" ) && 'trash' !== $post['status'] ) {
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
	/**
	 * TCGiant_Sync_Sites' list of products from other sites: linked, not in the
	 * trash, not the shop's own, and recorded on a site outside the query's NOT IN.
	 */
	private function other_site_products( $query ) {
		preg_match( "/s\\.meta_value NOT IN \\( ([^)]*) \\)/", $query, $m );
		$keep = array_map( function ( $s ) { return trim( $s, " '" ); }, explode( ',', $m[1] ?? '' ) );
		$no_trash  = false !== strpos( $query, "p.post_status NOT IN ( 'trash', 'auto-draft' )" );
		$not_own   = false !== strpos( $query, 'NOT EXISTS ( SELECT 1 FROM wp_postmeta tcg_own' );
		$linked    = false !== strpos( $query, "i.meta_key = '_ebay_item_id' AND i.meta_value != ''" );
		$out       = array();
		foreach ( array_keys( $GLOBALS['POSTS'] ) as $id ) {
			$meta = $GLOBALS['META'][ $id ] ?? array();
			$post = $GLOBALS['POSTS'][ $id ];
			if ( 'product' !== $post['type'] || ( $no_trash && ! live_product( $id ) )
				|| ( $linked && '' === (string) ( $meta['_ebay_item_id'] ?? '' ) ) || ! array_key_exists( '_ebay_site', $meta )
				|| '' === (string) $meta['_ebay_site'] || in_array( (string) $meta['_ebay_site'], $keep, true )
				|| ( $not_own && played_own( $meta ) ) ) {
				continue;
			}
			$out[ $id ] = (string) $meta['_ebay_site'];
		}
		ksort( $out );
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
		// Linked live products whose site is not known yet.
		if ( false !== strpos( $query, "st.meta_key = '_ebay_listing_status' AND st.meta_value = 'Active'" ) ) {
			$not_own = false !== strpos( $query, 'NOT EXISTS ( SELECT 1 FROM wp_postmeta tcg_own' );
			$n       = 0;
			foreach ( array_keys( $GLOBALS['POSTS'] ) as $id ) {
				$meta = $GLOBALS['META'][ $id ] ?? array();
				if ( live_product( $id ) && '' !== (string) ( $meta['_ebay_item_id'] ?? '' ) && 'Active' === ( $meta['_ebay_listing_status'] ?? null )
					&& '' === (string) ( $meta['_ebay_site'] ?? '' ) && ! ( $not_own && played_own( $meta ) ) ) {
					$n++;
				}
			}
			return (string) $n;
		}
		// Those of the other sites' products in one of the query's categories.
		if ( false !== strpos( $query, "tt.taxonomy = 'product_cat'" ) && false !== strpos( $query, "s.meta_key = '_ebay_site'" ) ) {
			preg_match( '/tt\.term_id IN \( ([^)]*) \)/', $query, $t );
			$cats = array_map( 'intval', explode( ',', $t[1] ?? '' ) );
			$n    = 0;
			foreach ( array_keys( $this->other_site_products( $query ) ) as $id ) {
				if ( array_intersect( $cats, $GLOBALS['TERMS'][ $id ] ?? array() ) ) {
					$n++;
				}
			}
			return (string) $n;
		}
		if ( false === strpos( $query, "'_ebay_item_id'" ) ) {
			return null;
		}
		$ids = $this->matching( $query );
		return empty( $ids ) ? null : (string) $ids[0];
	}
	public function get_col( $query ) {
		if ( false !== strpos( $query, "s.meta_key = '_ebay_site'" ) ) {
			return array_map( 'strval', array_keys( $this->other_site_products( $query ) ) );
		}
		// The mapper's search for a trashed product to take photos from.
		if ( false !== strpos( $query, "'_ebay_sku'" ) ) {
			$ids = $this->matching( $query );
			return null === $ids ? array() : array_map( 'strval', $ids );
		}
		return array();
	}
	public function get_results( $query, $output = null ) {
		if ( false !== strpos( $query, 'GROUP BY s.meta_value' ) ) {
			$counts = array_count_values( $this->other_site_products( $query ) );
			$rows   = array();
			foreach ( $counts as $site => $n ) {
				$rows[] = array( 'site' => (string) $site, 'n' => (string) $n );
			}
			return $rows;
		}
		// The pruner's list of linked products, with the marks the 3.28.0
		// pruner reads beside each: its listing status, stock status, when it
		// was last asked about and when the scans began to miss it.
		if ( false !== stripos( $query, 'meta_value as ebay_id' ) ) {
			$rows = array();
			foreach ( $GLOBALS['POSTS'] as $id => $post ) {
				$m    = $GLOBALS['META'][ $id ] ?? array();
				$item = (string) ( $m['_ebay_item_id'] ?? '' );
				if ( 'product' === $post['type'] && 'trash' !== $post['status'] && '' !== $item ) {
					$rows[] = (object) array(
						'post_id'        => (string) $id,
						'ebay_id'        => $item,
						'listing_status' => (string) ( $m['_ebay_listing_status'] ?? '' ),
						'stock_status'   => (string) ( $m['_stock_status'] ?? '' ),
						'checked'        => (string) ( $m['_ebay_prune_checked'] ?? '0' ),
						'missed'         => (string) ( $m['_ebay_prune_missed'] ?? '0' ),
					);
				}
			}
			return $rows;
		}
		return array();
	}
	public function query( $query ) { return 0; }
	public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
	public function delete( $table, $where = array(), $format = null ) { return 0; }
}
$wpdb = new Harness_Wpdb();

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function _n( $single, $plural, $n ) { return 1 === (int) $n ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text ) { return esc_html( $text ); }
function esc_html_e( $text ) { echo esc_html( $text ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_attr__( $text ) { return esc_html( $text ); }
function esc_url( $url ) { return (string) $url; }
function esc_js( $text ) { return addslashes( (string) $text ); }
function wp_kses_post( $html ) { return (string) $html; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['HOOKS'][ $hook ][] = array( $callback, $accepted_args );
	return true;
}
// So the runtime's save-loop breaker comes on where the plugin turns it on. It
// adds its callback to the save hooks, which nothing here fires.
function remove_action( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['HOOKS'][ $hook ] ?? array() as $i => $h ) {
		if ( $h[0] === $callback ) {
			unset( $GLOBALS['HOOKS'][ $hook ][ $i ] );
		}
	}
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function did_action( $hook ) { return 0; }
function wp_clear_scheduled_hook() {}
// The full sync's WP-Cron backstop, armed as it starts (3.27.6).
function wp_schedule_single_event() { return true; }
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
function wc_format_decimal( $number, $dp = false ) { return false === $dp ? (string) $number : number_format( (float) $number, (int) $dp, '.', '' ); }
function wp_set_object_terms() { return array(); }
function has_term( $terms, $taxonomy, $id ) { return (bool) array_intersect( array_map( 'intval', (array) $terms ), $GLOBALS['TERMS'][ (int) $id ] ?? array() ); }
function wp_cache_delete() { return true; }
function admin_url( $path = '' ) { return 'https://shop.example.com/wp-admin/' . $path; }
function wp_create_nonce( $action = '' ) { return 'nonce'; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=nonce-' . $action; }
function get_edit_post_link( $id ) { return admin_url( 'post.php?post=' . (int) $id . '&action=edit' ); }
function paginate_links( $args ) { return '<span class="pages">' . (int) $args['total'] . ' pages</span>'; }
function current_user_can( $cap ) { return $GLOBALS['CAN']; }
function get_current_user_id() { return $GLOBALS['USER_ID']; }
function get_current_screen() { $s = new stdClass(); $s->id = $GLOBALS['SCREEN']; return $s; }

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['OPTION_WRITES'][] = $name;
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
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $name ] = $value; $GLOBALS['TRANSIENT_TTL'][ $name ] = $ttl; return true; }
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
// As WordPress core's: with the trash switched off (EMPTY_TRASH_DAYS 0) it
// deletes for good instead, and a pre_trash_post filter can refuse it.
function wp_trash_post( $id ) {
	$GLOBALS['TRASH_LOOPS'][ (int) $id ] = loop_depth();
	if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
		return wp_delete_post( $id, true );
	}
	if ( $GLOBALS['TRASH_FILTER'] instanceof Throwable ) {
		throw $GLOBALS['TRASH_FILTER']; // Another plugin's hook on the trash.
	}
	if ( null !== $GLOBALS['TRASH_FILTER'] ) {
		return $GLOBALS['TRASH_FILTER'];
	}
	$GLOBALS['POSTS'][ (int) $id ]['status'] = 'trash';
	return true;
}
/** How deep the runtime's save-loop breaker is: 0 while it is off. */
function loop_depth() {
	$loops = new ReflectionProperty( 'TCGiant_Sync_Runtime', 'loops' );
	$loops->setAccessible( true );
	$state = $loops->getValue();
	return null === $state ? 0 : (int) $state['depth'];
}
function wp_delete_post( $id, $force = false ) {
	$GLOBALS['DELETED'][] = (int) $id;
	unset( $GLOBALS['POSTS'][ (int) $id ] );
	return true;
}
function get_post_thumbnail_id( $id ) { return 0; }

// WooCommerce's product. Its fields live in the same meta the plugin reads
// directly; a set_*() is held until save().
class WC_Product {
	protected $id      = 0;
	protected $changes = array();
	protected $deleted = array();
	protected $kind    = 'simple';
	public function __construct( $id = 0 ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_type() { return $this->kind; }
	public function is_type( $type ) { return $type === $this->kind; }
	protected function field( $key, $default = '' ) {
		if ( array_key_exists( $key, $this->changes ) ) {
			return $this->changes[ $key ];
		}
		if ( isset( $this->deleted[ $key ] ) ) {
			return $default;
		}
		return $GLOBALS['META'][ $this->id ][ $key ] ?? $default;
	}
	public function get_meta( $key, $single = true ) { return $this->field( $key ); }
	public function update_meta_data( $key, $value ) { unset( $this->deleted[ $key ] ); $this->changes[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->changes[ $key ] ); $this->deleted[ $key ] = true; }
	// Reads the store each time, so there is nothing to read again.
	public function read_meta_data( $force_read = false ) {}
	public function get_weight() { return (string) $this->field( '_weight' ); }
	public function get_sku() { return (string) $this->field( '_sku' ); }
	public function set_sku( $sku ) { $this->changes['_sku'] = (string) $sku; }
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
		foreach ( $this->changes as $key => $value ) {
			if ( '__status' === $key ) {
				$GLOBALS['POSTS'][ $this->id ]['status'] = $value;
				continue;
			}
			$GLOBALS['WRITES'][] = array( $this->id, $key );
			$GLOBALS['META'][ $this->id ][ $key ] = $value;
		}
		foreach ( array_keys( $this->deleted ) as $key ) {
			if ( isset( $GLOBALS['META'][ $this->id ][ $key ] ) ) {
				$GLOBALS['WRITES'][] = array( $this->id, '-' . $key );
			}
			unset( $GLOBALS['META'][ $this->id ][ $key ] );
		}
		$this->changes = array();
		$this->deleted = array();
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
	public function get_position() { return $this->d['position']; }
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
	$now = (int) ( $GLOBALS['META'][ $id ]['_stock'] ?? 0 );
	$now = 'decrease' === $operation ? $now - (int) $quantity : ( 'increase' === $operation ? $now + (int) $quantity : (int) $quantity );
	$GLOBALS['WRITES'][] = array( $id, '_stock' );
	$GLOBALS['META'][ $id ]['_stock']        = $now;
	$GLOBALS['META'][ $id ]['_stock_status'] = $now > 0 ? 'instock' : 'outofstock';
	return $now;
}
function wc_update_product_stock_status( $id, $status ) {
	$GLOBALS['META'][ (int) $id ]['_stock_status'] = $status;
}

// Action Scheduler, as the delta, the scan and the stock checks use it.
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	$GLOBALS['QUEUE'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group );
	return count( $GLOBALS['QUEUE'] );
}
function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) { return as_enqueue_async_action( $hook, $args, $group ); }
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

// The admin-ajax request a bulk job's batch arrives as, and an admin page's
// redirect: sending either ends the request; here it ends the call.
class Harness_Sent extends Exception {
	public $payload;
	public function __construct( $payload ) { parent::__construct( 'sent' ); $this->payload = $payload; }
}
function check_ajax_referer() { return 1; }
function check_admin_referer( $action ) { return 1; }
function sanitize_text_field( $text ) { return trim( (string) $text ); }
function sanitize_textarea_field( $text ) { return trim( (string) $text ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ); }
function map_deep( $value, $callback ) { return is_array( $value ) ? array_map( function ( $v ) use ( $callback ) { return map_deep( $v, $callback ); }, $value ) : call_user_func( $callback, $value ); }
function wp_unslash( $value ) { return $value; }
function absint( $n ) { return abs( (int) $n ); }
function wp_send_json_success( $data = null ) { throw new Harness_Sent( array( 'success' => true, 'data' => $data ) ); }
function wp_send_json_error( $data = null ) { throw new Harness_Sent( array( 'success' => false, 'data' => $data ) ); }
function wp_get_referer() { return 'https://shop.example.com/wp-admin/admin.php?page=tcgiant-settings'; }
function wp_safe_redirect( $to ) { throw new Harness_Sent( array( 'redirect' => $to ) ); }
function wp_die( $message = '' ) { throw new Harness_Sent( array( 'died' => $message ) ); }
function wp_generate_uuid4() { return sprintf( '%08x-0000-4000-8000-%012x', mt_rand(), mt_rand() ); }

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
	public static function upsert( $data ) { return true; }
	public static function table_exists() { return false; }
}
class TCGiant_Sync_Entitlements {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function can_import() { return $GLOBALS['CAN_IMPORT']; }
	public function get_active_product_count() { return 50; }
	public function get_free_limit() { return 50; }
}
class TCGiant_Sync_Image_Localizer {
	public static function set_external_images() {}
	public static function find_products_with_duplicate_images() { return array(); }
}

// A live shop, by wp-config, for TCGiant_Sync_Environment (3.28.0), which
// the push, Relist and auto-relist ask before anything goes to eBay.
// Staging copies are test-staging-detect.php's.
define( 'TCGIANT_SYNC_IS_STAGING', false );
require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $ROOT . '/includes/class-tcgiant-sync-mapper.php';
require $ROOT . '/includes/class-tcgiant-sync-importer.php';
require $ROOT . '/includes/class-tcgiant-sync-jobs.php';
require $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require $ROOT . '/admin/class-tcgiant-sync-admin.php';

/**
 * eBay: the real API class, with GetItem, GetSellerEvents and the day's orders
 * answered from a script, and anything else that would have gone out recorded.
 */
class Scripted_eBay extends TCGiant_Sync_API {
	public function get_item( $item_id ) {
		$GLOBALS['EBAY']['get_items'][] = (string) $item_id;
		return $GLOBALS['EBAY']['items'][ (string) $item_id ] ?? new WP_Error( 'api_error', 'The item could not be found.' );
	}
	public function get_seller_events( $from, $to, $max_body_bytes = 0 ) {
		$GLOBALS['EBAY']['event_reads']++;
		return array( 'Ack' => 'Success', 'ItemArray' => empty( $GLOBALS['EBAY']['events'] ) ? array() : array( 'Item' => $GLOBALS['EBAY']['events'] ) );
	}
	public function get_orders( $limit = 50, $offset = 0 ) { return array( 'orders' => $GLOBALS['EBAY']['orders'] ); }
	public function get_official_time() { return array( 'Ack' => 'Success' ); }
	// A push: the revise answers as scripted - by default, that the listing has
	// ended - and a new listing is created with the next item number, unless
	// eBay is scripted to answer the AddItem otherwise ($EBAY['add']).
	public function revise_fixed_price_item( $item_xml ) { return $this->revise( 'ReviseFixedPriceItem' ); }
	public function revise_item( $item_xml ) { return $this->revise( 'ReviseItem' ); }
	private function revise( $call ) {
		$GLOBALS['EBAY']['wire'][] = $call;
		return $GLOBALS['EBAY']['revise'] ?? new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
	}
	public function add_fixed_price_item( $item_xml ) { return $this->add( 'AddFixedPriceItem' ); }
	public function add_item( $item_xml ) { return $this->add( 'AddItem' ); }
	private function add( $call ) {
		$GLOBALS['EBAY']['wire'][] = $call;
		if ( isset( $GLOBALS['EBAY']['add'] ) ) {
			return $GLOBALS['EBAY']['add'];
		}
		return array( 'Ack' => 'Success', 'ItemID' => (string) ( $GLOBALS['EBAY']['new_item'] ?? '110000009999' ) );
	}
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$GLOBALS['EBAY']['wire'][] = $call_name;
		return new WP_Error( 'harness', 'unexpected eBay call ' . $call_name );
	}
	public function request( $endpoint, $method = 'GET', $body = array(), $params = array(), $log_errors = true ) {
		$GLOBALS['EBAY']['wire'][] = 'REST ' . $method . ' ' . $endpoint;
		return new WP_Error( 'harness', 'unexpected eBay request' );
	}
}
$slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$slot->setAccessible( true );
}
$slot->setValue( null, new Scripted_eBay() );

// The admin screens, without the hooks their constructor registers.
$ADMIN = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();
$slot  = new ReflectionProperty( 'TCGiant_Sync_Admin', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$slot->setAccessible( true );
}
$slot->setValue( null, $ADMIN );

$MAPPER = TCGiant_Sync_Mapper::instance();
$IMP    = TCGiant_Sync_Importer::instance();

/** Call a private method of one of the plugin's classes. */
function call_private( $object_or_class, $method, ...$args ) {
	$m = new ReflectionMethod( $object_or_class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invokeArgs( is_object( $object_or_class ) ? $object_or_class : null, $args );
}

/** Forget what a static remembered for the length of a request. */
function new_request() {
	foreach ( array( array( 'TCGiant_Sync_Importer', 'limit_reported', false ), array( 'TCGiant_Sync_Sites', 'stop_said', array() ) ) as $static ) {
		$p = new ReflectionProperty( $static[0], $static[1] );
		if ( PHP_VERSION_ID < 80100 ) {
			$p->setAccessible( true );
		}
		$p->setValue( null, $static[2] );
	}
}

// --- driving it --------------------------------------------------------------

$ONLY_US = array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' );

/** A shop with nothing in it. */
function fresh_shop( array $settings = array() ) {
	$GLOBALS['POSTS']      = array();
	$GLOBALS['META']       = array();
	$GLOBALS['TERMS']      = array();
	$GLOBALS['OPTIONS']    = $settings ? array( 'tcgiant_sync_ebay_settings' => $settings ) : array();
	$GLOBALS['TRANSIENTS'] = array();
	$GLOBALS['LOG']        = array();
	$GLOBALS['CREATED']    = array();
	$GLOBALS['WRITES']     = array();
	$GLOBALS['DELETED']    = array();
	$GLOBALS['QUEUE']      = array();
	$GLOBALS['CAN_IMPORT'] = true;
	$GLOBALS['TRASH_FILTER']  = null;
	$GLOBALS['TRASH_LOOPS']   = array();
	$GLOBALS['EBAY']       = array( 'items' => array(), 'get_items' => array(), 'events' => array(), 'event_reads' => 0, 'orders' => array(), 'wire' => array() );
	@unlink( trailingslashit( $GLOBALS['UPLOADS'] ) . 'tcgiant_sync.lock' );
	new_request();
}

/** A product. Meta given replaces the defaults. */
function product( $id, array $meta = array(), $status = 'publish' ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => 'product', 'status' => $status, 'product_type' => 'simple' );
	$GLOBALS['META'][ $id ]  = array_merge( array(
		'_name'         => 'Product #' . $id,
		'_manage_stock' => 'yes',
		'_stock'        => 1,
		'_stock_status' => 'instock',
	), $meta );
}

/** A product imported from listing $item_id on $site (null: site not recorded yet). */
function linked_product( $id, $item_id, $site, array $meta = array() ) {
	$base = array(
		'_sku'                 => 'SKU-' . $id,
		'_ebay_sku'            => 'SKU-' . $id,
		'_ebay_item_id'        => $item_id,
		'_ebay_listing_status' => 'Active',
		'_regular_price'       => '10.00',
	);
	if ( null !== $site ) {
		$base['_ebay_site'] = $site;
	}
	product( $id, array_merge( $base, $meta ) );
}

/** A listing as GetItem returns it: everything a full import needs. */
function listing( $item_id, $site, array $over = array() ) {
	$item = array_replace_recursive( array(
		'ItemID'          => $item_id,
		'Title'           => 'Robot arm ' . $item_id,
		'SKU'             => 'ARM-' . $item_id,
		'Quantity'        => '10',
		'ListingType'     => 'FixedPriceItem',
		'ListingDuration' => 'GTC',
		'StartPrice'      => '25.00',
		'SellingStatus'   => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0', 'CurrentPrice' => '25.00' ),
		'ListingDetails'  => array( 'StartTime' => '2026-10-10T10:00:00.000Z', 'EndTime' => '2026-11-09T10:00:00.000Z' ),
		'ItemSpecifics'   => array( 'NameValueList' => array() ),
		'PictureDetails'  => array( 'PictureURL' => 'https://i.ebayimg.com/' . $item_id . '.jpg' ),
	), $over );
	if ( null !== $site ) {
		$item['Site'] = $site;
	}
	return $item;
}

function ebay_has( $item_id, $item ) {
	$GLOBALS['EBAY']['items'][ $item_id ] = is_wp_error( $item ) ? $item : array( 'Ack' => 'Success', 'Item' => $item );
}

/** Import one listing as every import path does: map it, then save it. */
function import( array $item ) {
	$data = $GLOBALS['MAPPER']->map_ebay_to_woo( $item );
	return $GLOBALS['MAPPER']->save_as_product( $data );
}

/** One page of a full sync, as scan_all_pages() hands it over. */
function scan_page( array $items, $page = 1, $pages = 1 ) {
	call_private( $GLOBALS['IMP'], 'process_page_items', $items, $page, $pages );
}

/** One delta run over the given changes, started an hour ago. */
function delta( array $events ) {
	$GLOBALS['EBAY']['events'] = $events;
	$from = time() - HOUR_IN_SECONDS;
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
	new_request();
	$GLOBALS['IMP']->fetch_delta_events();
}

/** A row of eBay's list of changes: brief, as GetSellerEvents sends it. */
function event( $item_id, $site, $status = 'Active', array $over = array() ) {
	$row = array_replace_recursive( array(
		'ItemID'        => $item_id,
		'Quantity'      => '10',
		'SellingStatus' => array( 'ListingStatus' => $status, 'QuantitySold' => '0' ),
	), $over );
	if ( null !== $site ) {
		$row['Site'] = $site;
	}
	return $row;
}

/** One line of an eBay order, as the Fulfillment API sends it. */
function sale_line( $line_item_id, $item_id, $sku, $listed_on, $quantity = 1 ) {
	return array( 'lineItemId' => $line_item_id, 'legacyItemId' => $item_id, 'sku' => $sku, 'quantity' => $quantity, 'listingMarketplaceId' => $listed_on, 'purchaseMarketplaceId' => $listed_on );
}

/** Run everything queued, as Action Scheduler would: through the hook. */
function run_queued() {
	while ( $GLOBALS['QUEUE'] ) {
		$action = array_shift( $GLOBALS['QUEUE'] );
		foreach ( $GLOBALS['HOOKS'][ $action['hook'] ] ?? array() as $handler ) {
			call_user_func_array( $handler[0], array_slice( array_values( (array) $action['args'] ), 0, $handler[1] ) );
		}
	}
}

/** Start a bulk job through the real admin-ajax handler. */
function start_job( array $post ) {
	$_POST = $post;
	try {
		TCGiant_Sync_Jobs::instance()->ajax_start_job();
	} catch ( Harness_Sent $sent ) {
		return $sent->payload;
	}
	return null;
}

/** Run a bulk job's batches through the real handler until it is done. */
function run_job( $job_id ) {
	$last = null;
	for ( $i = 0; $i < 100; $i++ ) {
		$_POST = array( 'job_id' => $job_id );
		try {
			TCGiant_Sync_Jobs::instance()->ajax_process_batch();
		} catch ( Harness_Sent $sent ) {
			$last = $sent->payload;
		}
		if ( 'running' !== ( $last['data']['status'] ?? '' ) ) {
			break;
		}
	}
	return $last;
}

/** What a screen prints. */
function render( callable $fn ) {
	ob_start();
	try {
		$fn();
	} finally {
		$out = (string) ob_get_clean();
	}
	return $out;
}

function meta( $id, $key ) { return $GLOBALS['META'][ $id ][ $key ] ?? null; }
function stock( $id ) { return isset( $GLOBALS['META'][ $id ]['_stock'] ) ? (int) $GLOBALS['META'][ $id ]['_stock'] : null; }
function status_of( $id ) { return $GLOBALS['POSTS'][ $id ]['status'] ?? null; }
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
function queued_imports() {
	return array_values( array_map( function ( $a ) { return (string) $a['args']['item_id']; }, array_filter( $GLOBALS['QUEUE'], function ( $a ) { return 'tcgiant_sync_process_item_import' === $a['hook']; } ) ) );
}
function iso( $ts ) { return gmdate( 'Y-m-d\TH:i:s.000\Z', $ts ); }
function state() { return TCGiant_Sync_Importer::get_sync_state(); }
function settings( $import_sites, $marketplace ) {
	$s = array();
	if ( null !== $import_sites ) {
		$s['import_sites'] = $import_sites;
	}
	if ( null !== $marketplace ) {
		$s['marketplace'] = $marketplace;
	}
	$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = $s;
}


echo "\nNOTHING CHANGES UNTIL A SHOP CHOOSES IT\n" . str_repeat( '=', 142 ) . "\n";

settings( null, 'EBAY_US' );
check( 'setting absent: a UK listing is imported', TCGiant_Sync_Sites::leaves_out( 'UK' ), false );
settings( 'all', 'EBAY_US' );
check( 'All eBay sites: a UK listing is imported', TCGiant_Sync_Sites::leaves_out( 'UK' ), false );
settings( 'bogus', 'EBAY_US' );
check( 'an unknown value reads as All eBay sites', TCGiant_Sync_Sites::leaves_out( 'UK' ), false );
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'import_sites' => array( 'primary' ), 'marketplace' => 'EBAY_US' );
check( '  and so does one that is not even a string', TCGiant_Sync_Sites::primary_only(), false );
settings( 'primary', null );
check( 'only the primary, but no marketplace saved: not in force', TCGiant_Sync_Sites::primary_only(), false );
check( '  so a UK listing is imported', TCGiant_Sync_Sites::leaves_out( 'UK' ), false );
settings( 'primary', 'EBAY_XX' );
check( 'only the primary, with a marketplace the plugin does not support: not in force', TCGiant_Sync_Sites::primary_only(), false );
$OPTIONS['tcgiant_sync_ebay_settings'] = 'not an array';
check( 'settings that are not an array: not in force', TCGiant_Sync_Sites::primary_only(), false );

echo "\nPRIMARY EBAY MARKETPLACE: UNITED STATES\n" . str_repeat( '=', 142 ) . "\n";

settings( 'primary', 'EBAY_US' );
check( 'US kept', TCGiant_Sync_Sites::leaves_out( 'US' ), false );
check( 'eBayMotors kept - a vertical inside eBay US', TCGiant_Sync_Sites::leaves_out( 'eBayMotors' ), false );
check( 'UK left out', TCGiant_Sync_Sites::leaves_out( 'UK' ), true );
check( 'Germany left out', TCGiant_Sync_Sites::leaves_out( 'Germany' ), true );
check( 'Canada left out', TCGiant_Sync_Sites::leaves_out( 'Canada' ), true );
check( 'Australia left out', TCGiant_Sync_Sites::leaves_out( 'Australia' ), true );
check( 'a site the plugin does not support (Austria) left out', TCGiant_Sync_Sites::leaves_out( 'Austria' ), true );
check( 'an unknown site is never left out here', TCGiant_Sync_Sites::leaves_out( '' ), false );
check( 'case matters: "us" is not US', TCGiant_Sync_Sites::leaves_out( 'us' ), true );
check( 'the sites kept', TCGiant_Sync_Sites::primary_sites(), array( 'US', 'eBayMotors' ) );
check( 'named', TCGiant_Sync_Sites::primary_label(), 'eBay United States' );
check( 'Fulfillment EBAY_US kept', TCGiant_Sync_Sites::leaves_out_marketplace( 'EBAY_US' ), false );
check( 'Fulfillment EBAY_MOTORS_US kept', TCGiant_Sync_Sites::leaves_out_marketplace( 'EBAY_MOTORS_US' ), false );
check( 'Fulfillment EBAY_GB left out', TCGiant_Sync_Sites::leaves_out_marketplace( 'EBAY_GB' ), true );
check( 'Fulfillment line with no marketplace never left out', TCGiant_Sync_Sites::leaves_out_marketplace( '' ), false );
check( '  nor one that is not a string', TCGiant_Sync_Sites::leaves_out_marketplace( array( 'EBAY_GB' ) ), false );

echo "\nOTHER PRIMARIES\n" . str_repeat( '=', 142 ) . "\n";

settings( 'primary', 'EBAY_GB' );
check( 'GB: UK kept', TCGiant_Sync_Sites::leaves_out( 'UK' ), false );
check( 'GB: US left out', TCGiant_Sync_Sites::leaves_out( 'US' ), true );
check( 'GB: eBayMotors left out', TCGiant_Sync_Sites::leaves_out( 'eBayMotors' ), true );
check( 'GB: Fulfillment EBAY_US left out', TCGiant_Sync_Sites::leaves_out_marketplace( 'EBAY_US' ), true );
check( 'GB: Fulfillment EBAY_GB kept', TCGiant_Sync_Sites::leaves_out_marketplace( 'EBAY_GB' ), false );
settings( 'primary', 'EBAY_CA' );
check( 'CA: Canada kept', TCGiant_Sync_Sites::leaves_out( 'Canada' ), false );
check( 'CA: CanadaFrench kept - eBay.ca in French', TCGiant_Sync_Sites::leaves_out( 'CanadaFrench' ), false );
check( 'CA: US left out', TCGiant_Sync_Sites::leaves_out( 'US' ), true );
settings( 'primary', 'EBAY_DE' );
check( 'DE: Germany kept', TCGiant_Sync_Sites::leaves_out( 'Germany' ), false );
check( 'DE: France left out', TCGiant_Sync_Sites::leaves_out( 'France' ), true );

echo "\nREADING ITEM.SITE\n" . str_repeat( '=', 142 ) . "\n";

check( 'a plain string', TCGiant_Sync_Sites::site_of( array( 'Site' => 'UK' ) ), 'UK' );
check( 'missing', TCGiant_Sync_Sites::site_of( array( 'ItemID' => '1' ) ), '' );
check( 'a value with attributes (#text), as the XML converter can leave it', TCGiant_Sync_Sites::site_of( array( 'Site' => array( '#text' => 'UK', '@attributes' => array() ) ) ), 'UK' );
check( 'an empty <Site/>, which arrives as an empty array', TCGiant_Sync_Sites::site_of( array( 'Site' => array() ) ), '' );
// An order's transaction with an empty <Item/>: a TypeError here would end
// the order import part way through eBay's answer.
foreach ( array( 'an empty array' => array(), 'a string' => 'x', 'null' => null, 'a number' => 5 ) as $what => $not_an_item ) {
	check( sprintf( 'not an item at all (%s): no site, and nothing thrown', $what ), TCGiant_Sync_Sites::site_of( $not_an_item ), '' );
}
check( 'label for UK', TCGiant_Sync_Sites::label( 'UK' ), 'eBay United Kingdom' );
check( 'label for eBayMotors', TCGiant_Sync_Sites::label( 'eBayMotors' ), 'eBay United States' );
check( 'label for CanadaFrench', TCGiant_Sync_Sites::label( 'CanadaFrench' ), 'eBay Canada' );
check( 'label for a site the plugin does not support', TCGiant_Sync_Sites::label( 'Austria' ), 'eBay Austria' );
check( 'the marketplace of eBayMotors', TCGiant_Sync_Sites::marketplace_of( 'eBayMotors' ), 'EBAY_US' );

echo "\nTHE SITES SEEN, AND WHAT THEY COST\n" . str_repeat( '=', 142 ) . "\n";

$OPTIONS       = array();
$OPTION_WRITES = array();
$shutdowns     = count( $HOOKS['shutdown'] ?? array() );
TCGiant_Sync_Sites::note( 'US' );
TCGiant_Sync_Sites::note( 'UK' );
TCGiant_Sync_Sites::note( 'US' );
TCGiant_Sync_Sites::note( '' );
check( 'written at shutdown, registered once however many listings are seen', count( $HOOKS['shutdown'] ?? array() ) - $shutdowns, 1 );
check( '  and nothing written before then', array_key_exists( TCGiant_Sync_Sites::SEEN_OPTION, $OPTIONS ), false );
TCGiant_Sync_Sites::flush_seen();
check( 'both sites recorded', TCGiant_Sync_Sites::seen(), array( 'UK', 'US' ) );
check( '  in one write', array_count_values( $OPTION_WRITES )[ TCGiant_Sync_Sites::SEEN_OPTION ] ?? 0, 1 );
TCGiant_Sync_Sites::note( 'US' );
TCGiant_Sync_Sites::flush_seen();
check( 'seen again within the day: not written again', array_count_values( $OPTION_WRITES )[ TCGiant_Sync_Sites::SEEN_OPTION ] ?? 0, 1 );
$OPTIONS[ TCGiant_Sync_Sites::SEEN_OPTION ]['US'] = time() - 2 * DAY_IN_SECONDS;
TCGiant_Sync_Sites::note( 'US' );
TCGiant_Sync_Sites::flush_seen();
check( '  seen again two days on: written, once', array_count_values( $OPTION_WRITES )[ TCGiant_Sync_Sites::SEEN_OPTION ] ?? 0, 2 );
$OPTIONS[ TCGiant_Sync_Sites::SEEN_OPTION ]['UK'] = time() - 40 * DAY_IN_SECONDS;
check( 'older than 30 days drops out', TCGiant_Sync_Sites::seen(), array( 'US' ) );
$OPTIONS[ TCGiant_Sync_Sites::SEEN_OPTION ] = array( 'US' => time(), 'eBayMotors' => time() );
check( 'eBay United States and eBay Motors: one site, named once', TCGiant_Sync_Sites::seen_labels(), array( 'eBay United States' ) );
$OPTIONS[ TCGiant_Sync_Sites::SEEN_OPTION ] = array( 'Canada' => time(), 'CanadaFrench' => time() );
check( 'eBay.ca in English and French: one site', TCGiant_Sync_Sites::seen_labels(), array( 'eBay Canada' ) );
$OPTIONS[ TCGiant_Sync_Sites::SEEN_OPTION ] = array( 'US' => time(), 'eBayMotors' => time(), 'UK' => time(), 'Germany' => time() );
check( 'eBay.com with Motors, eBay.co.uk and eBay.de: three sites', TCGiant_Sync_Sites::seen_labels(), array( 'eBay Germany', 'eBay United Kingdom', 'eBay United States' ) );


/** Run the delta's queued follow-on runs, each a request of its own. */
function drain_delta() {
	$runs = 0;
	while ( $runs < 50 ) {
		$at = array_search( 'tcgiant_sync_fetch_delta_events', array_column( $GLOBALS['QUEUE'], 'hook' ), true );
		if ( false === $at ) {
			break;
		}
		array_splice( $GLOBALS['QUEUE'], $at, 1 );
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
		new_request();
		$GLOBALS['IMP']->fetch_delta_events();
		$runs++;
	}
	return $runs;
}

$SITES = array( 'UK', 'Germany', 'France', 'Italy', 'Australia', 'Canada', 'Spain' );

/** An eBay.com listing and its seven Web Interpret copies: one SKU, eight item numbers. */
function web_interpret( $us_item, $sku, array $over = array() ) {
	$set = array( listing( $us_item, 'US', array_replace_recursive( array( 'SKU' => $sku ), $over ) ) );
	foreach ( $GLOBALS['SITES'] as $n => $site ) {
		$set[] = listing( $us_item . '-' . $n, $site, array_replace_recursive( array( 'SKU' => $sku, 'Title' => 'Robot arm (' . $site . ' copy)' ), $over ) );
	}
	return $set;
}

echo "\nA WEB INTERPRET SHOP'S FULL SYNC: ONE EBAY.COM LISTING AND ITS SEVEN COPIES (FAILS ON 3.27.4)\n" . str_repeat( '=', 142 ) . "\n";

// What 3.27.4 does, and what it still does for every shop that has not chosen:
// the eight listings become eight products, the copies with the SKU made
// unique, priced in their own currencies as though they were dollars.
fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
$page = web_interpret( '410000000001', 'ARM-7' );
ebay_has( '410000000001', $page[0] );
scan_page( $page );
check( 'all eBay sites, as before: eight products', count( $CREATED ), 8 );
check( '  each recorded with the site it came from', array_values( array_map( function ( $id ) { return meta( $id, '_ebay_site' ); }, $CREATED ) ), array( 'US', 'UK', 'Germany', 'France', 'Italy', 'Australia', 'Canada', 'Spain' ) );

// Rabbi Ent, with Import listings from set to eBay United States only.
fresh_shop( $ONLY_US );
scan_page( $page );
$us_product = $CREATED[0] ?? 0;
check( 'eBay United States only: one product', count( $CREATED ), 1 );
check( '  from the eBay.com listing', meta( $us_product, '_ebay_item_id' ), '410000000001' );
check( '  holding the SKU itself, not a "-<item>" copy of it', meta( $us_product, '_sku' ), 'ARM-7' );
check( '  recorded as on eBay United States', meta( $us_product, '_ebay_site' ), 'US' );
check( 'no copy asked about, and none queued for GetItem', array( $EBAY['get_items'], queued_imports() ), array( array(), array() ) );
check( 'all eight are still live for the pruner, so it never trashes a product from a copy', count( array_intersect( array_column( $page, 'ItemID' ), (array) get_option( TCGiant_Sync_Importer::ACTIVE_IDS_OPTION, array() ) ) ), 8 );
check( 'the page says so', logged( 'Page 1/1: Scanned 8 items, 1 imported inline, 7 on other eBay sites left out.' ), true );
check( '  and no copy is logged one by one - left out before anything is asked', log_lines_naming( 'Skipped eBay item' ), 0 );
check( 'the full sync counts them for its warning', array( state()['site_primary_seen'] ?? null, state()['site_other_seen'] ?? null ), array( 1, 7 ) );
check( '  by site', state()['site_other_sites'] ?? null, $SITES );

// A listing eBay's list answer was too brief to import inline goes to GetItem
// - but a copy never does.
fresh_shop( $ONLY_US );
$brief_us = listing( '410000000002', 'US' );
$brief_uk = listing( '410000000003', 'UK' );
unset( $brief_us['ItemSpecifics'], $brief_uk['ItemSpecifics'] );
scan_page( array( $brief_us, $brief_uk ) );
check( 'too brief to import inline: the eBay.com listing is queued for GetItem, the copy is not', queued_imports(), array( '410000000002' ) );
ebay_has( '410000000002', listing( '410000000002', 'US' ) );
run_queued();
check( '  and the queued import makes its product', count( $CREATED ), 1 );

echo "\nTHE DELTA LEAVES THE COPIES OUT BEFORE ANYTHING IS ASKED (FAILS ON 3.27.4)\n" . str_repeat( '=', 142 ) . "\n";

// A day's new listings: three on eBay.com, seven copies of each. eBay's list
// of changes is brief, so each listing costs a GetItem - 24 a day here, about
// 160 for Rabbi Ent's twenty.
fresh_shop( $ONLY_US );
$events = array();
foreach ( array( '420000000001', '420000000002', '420000000003' ) as $us ) {
	ebay_has( $us, listing( $us, 'US' ) );
	$events[] = event( $us, 'US' );
	foreach ( $SITES as $n => $site ) {
		$events[] = event( $us . '-' . $n, $site );
	}
}
delta( $events );
check( 'GetItem is asked only for the three eBay.com listings', $EBAY['get_items'], array( '420000000001', '420000000002', '420000000003' ) );
check( '  three products made', count( $CREATED ), 3 );
check( '  no error counted', (int) ( state()['total_errors'] ?? -1 ), 0 );
check( '  the window finishes', state()['status'] ?? null, 'complete' );
check( '  the summary says why the rest were not imported', logged( 'Delta sync complete! 3 imported, 0 errors out of 24 modified items, 21 left out (on other eBay sites), 3 GetItem fallbacks.' ), true );
// Counted, never named one by one, so a shop searching the log for a copy's
// item number finds nothing there. The candidate's changelog said the log
// names each listing left out once every ten days; only an import that reaches
// the listing does (Sync Specific Items, below), never the delta or the scan.
$changelog = (string) file_get_contents( $ROOT . '/changelog.txt' );
check( '  no copy named in the log, as the changelog says', array( log_lines_naming( 'Skipped eBay item' ), false !== strpos( $changelog, 'The log counts the listings left out in each batch and page, without naming each one. A listing left out only once it has reached the import, such as one already queued when the setting changed, is named once every ten days, and Sync Specific Items names it every time.' ), false !== strpos( $changelog, 'says why a listing was skipped once per listing every ten days' ) ), array( 0, true, false ) );

// The same changes, for a shop that has not chosen: exactly as 3.27.4.
fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
foreach ( $events as $row ) {
	ebay_has( $row['ItemID'], listing( $row['ItemID'], $row['Site'], array( 'SKU' => 'ARM-' . $row['ItemID'] ) ) );
}
delta( $events );
check( 'all eBay sites: every listing asked about, as before', count( $EBAY['get_items'] ), 24 );
check( '  and every one made into a product', count( $CREATED ), 24 );
check( '  with no word of other sites', logged( 'other eBay sites' ), false );

// A window too long for one run: the second run carries on from the rows kept
// in brief, without asking eBay for the window again. Those rows keep each
// listing's site, so the copies are still left out without a GetItem.
fresh_shop( $ONLY_US );
$events = array();
for ( $i = 0; $i < 10; $i++ ) {
	$us = sprintf( '43000000%04d', $i );
	ebay_has( $us, listing( $us, 'US' ) );
	$events[] = event( $us, 'US' );
	foreach ( $SITES as $n => $site ) {
		$events[] = event( $us . '-' . $n, $site );
	}
}
delta( $events );
$runs = drain_delta();
check( 'eighty changes: more than one run', $runs > 0, true );
check( '  eBay\'s list read once - the later runs use the rows kept in brief', $EBAY['event_reads'], 1 );
check( '  GetItem only for the ten eBay.com listings, in every run', count( $EBAY['get_items'] ), 10 );
check( '  ten products', count( $CREATED ), 10 );
check( '  the window finishes', state()['status'] ?? null, 'complete' );

// A window kept in brief by 3.27.4, whose rows carry no site, carried on after
// the update: eBay is asked about each listing with no product, and its answer
// leaves the copies out.
fresh_shop( $ONLY_US );
$mod_from = iso( time() - HOUR_IN_SECONDS );
$mod_to   = iso( time() - 60 );
$old_rows = array();
foreach ( array( '440000000001' => 'US', '440000000002' => 'UK', '440000000003' => 'Germany' ) as $id => $site ) {
	$id = (string) $id; // a numeric key comes back as a number
	ebay_has( $id, listing( $id, $site ) );
	$old_rows[] = array( 'ItemID' => $id, 'Quantity' => '10', 'SellingStatus' => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0' ) );
}
update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( TCGiant_Sync_Importer::get_sync_state(), array( 'status' => 'scanning', 'sync_mode' => 'delta', 'delta_mod_from' => $mod_from, 'delta_window_minutes' => 2880 ) ) );
update_option( TCGiant_Sync_Importer::DELTA_PROGRESS_OPTION, array( 'from' => $mod_from, 'to' => $mod_to, 'cursor' => '', 'done' => 0, 'in_flight' => '', 'body_bytes' => 100, 'ts' => time() ) );
update_option( TCGiant_Sync_Importer::DELTA_WINDOW_CACHE_OPTION, array( 'key' => $mod_from . '|' . $mod_to, 'rows' => $old_rows ) );
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
new_request();
$IMP->fetch_delta_events();
check( 'a 3.27.4 window carried on: served from the rows it kept', $EBAY['event_reads'], 0 );
check( '  each row without a site is asked about', $EBAY['get_items'], array( '440000000001', '440000000002', '440000000003' ) );
check( '  and only the eBay.com listing made into a product', array( count( $CREATED ), meta( $CREATED[0] ?? 0, '_ebay_item_id' ) ), array( 1, '440000000001' ) );
check( '  the other two said to be left out', logged( ', 2 left out (on other eBay sites)' ), true );

// A row complete enough to import as it stands, but with no site: eBay is
// asked first, rather than importing a copy because the row did not say.
fresh_shop( $ONLY_US );
$complete = listing( '450000000001', null );
ebay_has( '450000000001', listing( '450000000001', 'UK' ) );
delta( array( $complete ) );
check( 'a complete row with no site, nothing on the website from it: eBay is asked', $EBAY['get_items'], array( '450000000001' ) );
check( '  and its answer, eBay United Kingdom, leaves it out', $CREATED, array() );

echo "\nPRODUCTS ALREADY IMPORTED FROM OTHER SITES KEEP SYNCING\n" . str_repeat( '=', 142 ) . "\n";

// Imported from an eBay.co.uk copy before the shop chose eBay United States
// only. Freezing it would leave it on sale with figures that stopped being true.
fresh_shop( $ONLY_US );
linked_product( 301, '460000000001', null, array( '_stock' => 5, '_regular_price' => '10.00' ) );
scan_page( array( listing( '460000000001', 'UK', array( 'SKU' => 'SKU-301', 'Quantity' => '4', 'StartPrice' => '12.00', 'SellingStatus' => array( 'CurrentPrice' => '12.00' ) ) ) ) );
check( 'its listing on the full sync: still updated', array( stock( 301 ), meta( 301, '_regular_price' ) ), array( 4, '12.00' ) );
check( '  and now recorded as from eBay United Kingdom', meta( 301, '_ebay_site' ), 'UK' );
check( '  no new product', $CREATED, array() );

// Through the delta, which asks eBay for the listing.
ebay_has( '460000000001', listing( '460000000001', 'UK', array( 'SKU' => 'SKU-301', 'Quantity' => '3', 'StartPrice' => '12.00', 'SellingStatus' => array( 'CurrentPrice' => '12.00' ) ) ) );
delta( array( event( '460000000001', 'UK' ) ) );
check( 'its listing on the delta: asked about and updated', array( $EBAY['get_items'], stock( 301 ) ), array( array( '460000000001' ), 3 ) );

// Its listing ends: still settled, and the site recorded while it is.
fresh_shop( $ONLY_US );
linked_product( 331, '460000000031', null, array( '_stock' => 3 ) );
delta( array( event( '460000000031', 'UK', 'Completed', array( 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '1' ) ) ) ) );
check( 'its listing ended: still settled', array( meta( 331, '_ebay_listing_status' ), stock( 331 ) ), array( 'Ended', 2 ) );
check( '  and its site recorded - an ended listing is never saved again', meta( 331, '_ebay_site' ), 'UK' );

// The sale check settles an ended listing from GetItem, the delta not involved.
fresh_shop( $ONLY_US );
linked_product( 332, '460000000032', null, array( '_stock' => 3 ) );
ebay_has( '460000000032', listing( '460000000032', 'Germany', array( 'Quantity' => '3', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ) ) ) );
$IMP->settle_item( '460000000032' );
check( 'the sale check settles one and records its site too', array( meta( 332, '_ebay_listing_status' ), stock( 332 ), meta( 332, '_ebay_site' ) ), array( 'Ended', 2, 'Germany' ) );

// An ended row that does not say its site does not wipe the one recorded.
fresh_shop( $ONLY_US );
linked_product( 333, '460000000033', 'UK', array( '_stock' => 3 ) );
delta( array( event( '460000000033', null, 'Completed', array( 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '1' ) ) ) ) );
check( 'an ended row with no site: settled, and the recorded site kept', array( meta( 333, '_ebay_listing_status' ), meta( 333, '_ebay_site' ) ), array( 'Ended', 'UK' ) );

// Held back by the category filter, which runs after: still recorded, so Stock
// Review can find it.
fresh_shop( array_merge( $ONLY_US, array( 'import_standard_category_ids' => array( '999' ) ) ) );
linked_product( 341, '460000000041', null );
scan_page( array( listing( '460000000041', 'UK', array( 'PrimaryCategory' => array( 'CategoryID' => '123' ) ) ) ) );
check( 'kept from saving by the category filter: its site is still recorded', meta( 341, '_ebay_site' ), 'UK' );
fresh_shop( array_merge( $ONLY_US, array( 'import_standard_category_ids' => array( '999' ) ) ) );
linked_product( 342, '460000000042', null );
delta( array( event( '460000000042', 'UK', 'Active', array( 'PrimaryCategory' => array( 'CategoryID' => '123' ) ) ) ) );
check( '  and on the delta, which never asks eBay about it', array( meta( 342, '_ebay_site' ), $EBAY['get_items'] ), array( 'UK', array() ) );

echo "\nNO PRODUCT IS TAKEN OVER BY A LISTING ON ANOTHER SITE (FAILS ON 3.27.4)\n" . str_repeat( '=', 142 ) . "\n";

// The shop's own product, not linked to eBay, with the SKU an eBay.de copy has.
fresh_shop( $ONLY_US );
product( 501, array( '_sku' => 'GRIP-2', '_regular_price' => '60.00', '_stock' => 5 ) );
$got = import( listing( '470000000001', 'Germany', array( 'SKU' => 'GRIP-2' ) ) );
check( 'an eBay.de copy with an unlinked product\'s SKU: not linked', array( $got, meta( 501, '_ebay_item_id' ) ), array( false, null ) );
check( '  an intended skip, not a failure', TCGiant_Sync_Mapper::$last_skipped, true );
check( '  nothing written to the product at all', writes_to( 501 ), array() );
check( '  no product created', $CREATED, array() );
check( '  the log names both, and the setting', logged( 'eBay item 470000000001 on eBay Germany has the same SKU as WC #501, which is not linked to eBay. Import listings from is set to eBay United States only, so WC #501 was left alone.' ), true );

// The eBay.com product whose own listing has ended, and an eBay.co.uk copy
// with its SKU still live. 3.27.4 moved the product onto the copy.
fresh_shop( $ONLY_US );
linked_product( 502, '470000000002', 'US', array( '_sku' => 'ARM-9', '_ebay_sku' => 'ARM-9', '_ebay_listing_status' => 'Ended' ) );
$got = import( listing( '470000000003', 'UK', array( 'SKU' => 'ARM-9' ) ) );
check( 'an eBay.com product whose listing ended is not moved onto an eBay.co.uk copy', array( $got, meta( 502, '_ebay_item_id' ) ), array( false, '470000000002' ) );
check( '  nothing created', $CREATED, array() );
check( '  and eBay not asked about the old listing', $EBAY['get_items'], array() );
fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
linked_product( 502, '470000000002', 'US', array( '_sku' => 'ARM-9', '_ebay_sku' => 'ARM-9', '_ebay_listing_status' => 'Ended' ) );
import( listing( '470000000003', 'UK', array( 'SKU' => 'ARM-9' ) ) );
check( '  all eBay sites, as in 3.27.4: it is', meta( 502, '_ebay_item_id' ), '470000000003' );

// The shop deleted the product for a copy: the trash still says so.
fresh_shop( $ONLY_US );
linked_product( 503, '470000000004', 'UK' );
$POSTS[503]['status'] = 'trash';
$got = import( listing( '470000000004', 'UK' ) );
check( 'a copy whose product is in the trash: skipped as deleted on purpose', array( $got, logged( 'its WooCommerce product (#503) is in the Trash' ) ), array( false, true ) );

// Said once a listing, not on every sync of it.
fresh_shop( $ONLY_US );
import( listing( '470000000005', 'UK' ) );
import( listing( '470000000005', 'UK' ) );
check( 'the same copy twice: one line', log_lines_naming( 'Skipped eBay item 470000000005' ), 1 );
check( '  naming the site and the setting', logged( 'Skipped eBay item 470000000005 ("Robot arm 470000000005"): it is listed on eBay United Kingdom, and Import listings from is set to eBay United States only (Settings, Import Filters).' ), true );
check( '  remembered for ten days', isset( $TRANSIENTS[ TCGiant_Sync_Sites::SKIP_TRANSIENT . '470000000005' ] ), true );

// eBay did not say: let through, and said.
fresh_shop( $ONLY_US );
$got = import( listing( '470000000006', null ) );
check( 'a listing eBay gave no site for: imported', $got > 0, true );
check( '  with a warning', logged( 'eBay did not say which site item 470000000006 is listed on; importing it.', 'warning' ), true );
check( '  and no site recorded', meta( $got, '_ebay_site' ), null );
// A later answer without a site does not wipe one recorded before.
linked_product( 504, '470000000007', 'UK' );
import( listing( '470000000007', null, array( 'SKU' => 'SKU-504' ) ) );
check( 'an answer with no site leaves the recorded one alone', meta( 504, '_ebay_site' ), 'UK' );

echo "\nSYNC SPECIFIC ITEMS SAYS WHY, EVERY TIME IT IS ASKED\n" . str_repeat( '=', 142 ) . "\n";

fresh_shop( $ONLY_US );
ebay_has( '480000000001', listing( '480000000001', 'UK' ) );
$IMP->start_specific_sync( array( '480000000001' ) );
run_queued();
check( 'an eBay.co.uk item asked for by number: not imported', $CREATED, array() );
check( '  saying why', logged( 'Skipped eBay item 480000000001 ("Robot arm 480000000001"): it is listed on eBay United Kingdom, and Import listings from is set to eBay United States only' ), true );
check( '  not counted as an error', (int) ( state()['total_errors'] ?? -1 ), 0 );
$IMP->start_specific_sync( array( '480000000001' ) );
run_queued();
check( 'asked for again: says so again', log_lines_naming( 'Skipped eBay item 480000000001' ), 2 );
$IMP->process_item_import( '480000000001' );
check( '  but the same item from a sync\'s queue, days within: not again', log_lines_naming( 'Skipped eBay item 480000000001' ), 2 );

echo "\nTHE MARKETPLACE, THE FREE PLAN, AND THE DEFAULT\n" . str_repeat( '=', 142 ) . "\n";

// Only the primary chosen, but no marketplace saved: not in force, and the
// marketplace is not taken from the first listing seen - an eBay.co.uk copy
// seen first would otherwise leave out every eBay.com listing after it.
fresh_shop( array( 'import_sites' => 'primary' ) );
$with_package = array( 'ShippingPackageDetails' => array( 'WeightMajor' => '1', 'WeightMinor' => '0' ) );
$uk_first = import( listing( '490000000001', 'UK', $with_package ) );
$us_after = import( listing( '490000000002', 'US', $with_package ) );
check( 'no marketplace saved: the eBay.co.uk listing seen first is imported', $uk_first > 0, true );
check( '  the marketplace is not taken from it', array_key_exists( 'marketplace', $OPTIONS['tcgiant_sync_ebay_settings'] ), false );
check( '  so the eBay.com listing after it is imported too', $us_after > 0, true );
check( '  and nothing said about a marketplace being adopted', logged( 'Marketplace was not set' ), false );

// At the free plan's limit, a copy is not what reaches it.
fresh_shop( $ONLY_US );
$CAN_IMPORT = false;
delta( array( event( '491000000001', 'UK' ) ) );
check( 'at the free limit, a copy with its site: not taken for a new import', state()['status'] ?? null, 'complete' );
check( '  and the shop is not told it reached the limit by it', logged( 'Import limit reached' ), false );
fresh_shop( $ONLY_US );
$CAN_IMPORT = false;
delta( array( event( '491000000002', 'US' ) ) );
check( '  an eBay.com listing at the limit still is', logged( 'Import limit reached' ), true );

echo "\nTHE WEB INTERPRET SHOP AFTER THE SYNC: SALES ON COPIES, AND THE EBAY.COM QUANTITY\n" . str_repeat( '=', 142 ) . "\n";

fresh_shop( array_merge( $ONLY_US, array( 'enable_order_sync' => '1' ) ) );
$page = web_interpret( '492000000001', 'ARM-12', array( 'Quantity' => '10' ) );
scan_page( $page );
$arm = $CREATED[0] ?? 0;
check( 'one product, ten in stock', array( count( $CREATED ), stock( $arm ) ), array( 1, 10 ) );
// Two sell on copies: eBay.co.uk and eBay.de. Each sale arrives in the day's
// orders, with the copy's item number, its SKU and its marketplace.
$EBAY['orders'] = array( array( 'orderId' => '27-0001', 'lineItems' => array(
	sale_line( 'L1', '492000000001-0', 'ARM-12', 'EBAY_GB' ),
	sale_line( 'L2', '492000000001-1', 'ARM-12', 'EBAY_DE' ),
) ) );
$IMP->sync_recent_orders();
run_queued();
check( 'the copies\' sales leave the website\'s stock alone', stock( $arm ), 10 );
check( '  and ask eBay nothing - no product follows a copy', $EBAY['get_items'], array() );
// Web Interpret puts the two sales on the eBay.com listing, and its copies
// follow: the delta sees all of them change.
$sold_two = listing( '492000000001', 'US', array( 'SKU' => 'ARM-12', 'Quantity' => '10', 'SellingStatus' => array( 'QuantitySold' => '2' ) ) );
ebay_has( '492000000001', $sold_two );
$changes = array( event( '492000000001', 'US', 'Active', array( 'SellingStatus' => array( 'QuantitySold' => '2' ) ) ) );
foreach ( $SITES as $n => $site ) {
	$changes[] = event( '492000000001-' . $n, $site, 'Active', array( 'Quantity' => '8' ) );
}
delta( $changes );
check( 'the eBay.com listing\'s quantity change reaches the website: 8', stock( $arm ), 8 );
check( '  once, from the one listing it follows', $EBAY['get_items'], array( '492000000001' ) );
check( '  and still one product', count( $CREATED ), 1 );

echo "\nA FULL SYNC THAT FINDS NOTHING ON THE PRIMARY MARKETPLACE SAYS SO\n" . str_repeat( '=', 142 ) . "\n";

// A shop that set eBay United Kingdom while its listings are all on eBay.com
// and eBay.de, then chose only the primary: nothing new is imported, ever.
fresh_shop( array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_GB' ) );
TCGiant_Sync_Importer::update_sync_state( array( 'site_primary_seen' => 5, 'site_other_seen' => 9, 'site_other_sites' => array( 'UK' ) ) );
check( 'a full sync starts', $IMP->start_full_sync( true ), true );
check( '  from nothing counted', array( state()['site_primary_seen'] ?? null, state()['site_other_seen'] ?? null, state()['site_other_sites'] ?? null ), array( 0, 0, array() ) );
scan_page( array( listing( '493000000001', 'US' ), listing( '493000000002', 'Germany' ), listing( '493000000003', 'US' ) ) );
call_private( $IMP, 'finalize_scan', true );
check( 'nothing on eBay United Kingdom: warned', logged( 'The full sync found no listings on eBay United Kingdom, your Primary eBay Marketplace, so nothing new was imported. Your listings are on eBay Germany, eBay United States.', 'warning' ), true );
check( '  and kept for the notice', TCGiant_Sync_Sites::mismatch()['sites'] ?? null, array( 'eBay Germany', 'eBay United States' ) );
$SCREEN = 'tcgiant-sync_page_tcgiant-settings';
check( '  which the plugin\'s pages show', false !== strpos( render( function () use ( $ADMIN ) { $ADMIN->other_sites_notice(); } ), 'The last full sync found no listings on eBay United Kingdom, your Primary eBay Marketplace' ), true );
// Saving settings with nothing changed leaves it; a new marketplace answers it.
$ADMIN->sanitize_settings( array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_GB' ) );
check( 'settings saved unchanged: the warning stays', null !== TCGiant_Sync_Sites::mismatch(), true );
$ADMIN->sanitize_settings( array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' ) );
check( '  a new marketplace saved: it comes down', get_option( TCGiant_Sync_Sites::MISMATCH_OPTION, null ), null );
update_option( TCGiant_Sync_Sites::MISMATCH_OPTION, array( 'marketplace' => 'EBAY_GB', 'sites' => array( 'eBay United States' ), 'at' => time() ) );
$OPTIONS['tcgiant_sync_ebay_settings']['marketplace'] = 'EBAY_US';
check( '  nor shown once the marketplace has changed by any other way', TCGiant_Sync_Sites::mismatch(), null );
$OPTIONS['tcgiant_sync_ebay_settings']['marketplace'] = 'EBAY_GB';
// Set again, a full sync that finds listings on the marketplace takes it down.
TCGiant_Sync_Sites::after_full_scan( 0, 3, array( 'US' ) );
check( 'warned again', null !== TCGiant_Sync_Sites::mismatch(), true );
$IMP->start_full_sync( true );
scan_page( array( listing( '493000000004', 'UK' ), listing( '493000000006', 'US' ) ) );
call_private( $IMP, 'finalize_scan', true );
check( 'a full sync that finds the marketplace\'s listings, beside others: no warning', get_option( TCGiant_Sync_Sites::MISMATCH_OPTION, null ), null );
// And a shop importing every site is never warned.
fresh_shop( array( 'marketplace' => 'EBAY_GB' ) );
$IMP->start_full_sync( true );
scan_page( array( listing( '493000000005', 'US' ) ) );
call_private( $IMP, 'finalize_scan', true );
check( 'all eBay sites: never warned', array( logged( 'found no listings on' ), get_option( TCGiant_Sync_Sites::MISMATCH_OPTION, null ) ), array( false, null ) );

echo "\nSTOCK REVIEW LISTS OTHER SITES' PRODUCTS, AND MOVES THEM TO THE TRASH ONLY WHEN ASKED\n" . str_repeat( '=', 142 ) . "\n";

/** The Stock Review screen, as the shop sees it. */
function stock_review() {
	return render( function () { require $GLOBALS['ROOT'] . '/admin/views/stock-review.php'; } );
}

/** Press the page's "Move them to the trash", as its script does: with the token of the list it drew. */
function press_trash_button( $html ) {
	if ( ! preg_match( "/var list    = '([^']*)';/", $html, $m ) || false === strpos( $html, "select_all: 1, list: list," ) ) {
		return array( 'success' => null, 'data' => array( 'message' => 'the page has no button' ) );
	}
	return start_job( array( 'type' => 'bulk_trash_other_sites', 'select_all' => 1, 'list' => $m[1] ) );
}

fresh_shop( array_merge( $ONLY_US, array( 'preserve_woo_category_ids' => array( '55' ) ) ) );
linked_product( 601, '500000000001', 'UK' );
linked_product( 602, '500000000002', 'UK' );
linked_product( 603, '500000000003', 'Germany' );
linked_product( 604, '500000000004', 'US' );
linked_product( 605, '500000000005', 'eBayMotors' );
linked_product( 606, '500000000006', null );                                              // not checked yet
linked_product( 607, '500000000007', null, array( '_ebay_listing_status' => 'Ended' ) );   // not checked, but ended
linked_product( 608, '500000000008', 'UK' );
$POSTS[608]['status'] = 'trash';                                                           // already in the trash
linked_product( 609, '500000000009', 'UK', array( '_ebay_export_status' => 'pushed' ) );  // the shop's own, pushed
linked_product( 610, '500000000010', 'UK' );
$TERMS[610] = array( 55 );                                                                 // in a preserved category
product( 611, array( '_sku' => 'OWN-1' ) );                                                // not linked to eBay

check( 'the products from other sites: not the eBay.com ones, not unchecked, not trashed, not pushed', TCGiant_Sync_Sites::find_other_site_products(), array( 601, 602, 603, 610 ) );
check( '  counted by site', TCGiant_Sync_Sites::count_other_site_products_by_site(), array( 'eBay United Kingdom' => 3, 'eBay Germany' => 1 ) );
check( '  and one live product still to be checked - the ended one never would be', TCGiant_Sync_Sites::count_unchecked_products(), 1 );

$html = stock_review();
check( 'Stock Review says how many, from where', false !== strpos( $html, '4 products on your website came from listings on other eBay sites (eBay United Kingdom: 3, eBay Germany: 1).' ), true );
check( '  that they keep syncing, and their prices were not converted', false !== strpos( $html, 'Import listings from is set to eBay United States only, so no more will be imported, but these keep syncing until you remove them. Their prices were copied from eBay without currency conversion.' ), true );
check( '  lists exactly those four', array_map( function ( $id ) use ( $html ) { return false !== strpos( $html, 'Product #' . $id . '<' ); }, array( 601, 602, 603, 604, 605, 606, 608, 609, 610, 611 ) ), array( true, true, true, false, false, false, false, false, true, false ) );
check( '  with what would happen to each: three to the trash', substr_count( $html, 'Moved to the trash' ), 3 );
check( '  and the preserved one kept', substr_count( $html, 'Kept - in a preserved category' ), 1 );
check( '  the one not checked yet, and how to check it', false !== strpos( $html, '1 linked product has not been checked yet. Run Import from eBay, Fetch Inventory to check it.' ), true );
check( '  one button, asking first', false !== strpos( $html, 'id="tc-trash-other-sites"' ), true );
check( '  for the three that would go, not the preserved one too (it asked about 4)', false !== strpos( $html, 'Move 3 products from other eBay sites to the trash? Their eBay listings are not changed.' ), true );
check( 'looking at it changes nothing', array( $WRITES, array_map( 'status_of', array( 601, 602, 603, 610 ) ) ), array( array(), array( 'publish', 'publish', 'publish', 'publish' ) ) );

// The button: the list the page drew, kept on the server for it.
$started = press_trash_button( $html );
check( 'the job starts with the four on the list', array( $started['success'] ?? null, $started['data']['total'] ?? null ), array( true, 4 ) );
// Between the list and the job reaching it, 602 turns out to be on eBay.com.
update_post_meta( 602, '_ebay_site', 'US' );
$done = run_job( $started['data']['job_id'] );
check( 'finished', $done['data']['status'] ?? null, 'completed' );
check( '  two moved to the trash, two kept, none failed', array( $done['data']['succeeded'] ?? null, $done['data']['kept'] ?? null, $done['data']['failed'] ?? null ), array( 2, 2, 0 ) );
check( '  601 and 603 in the trash', array( status_of( 601 ), status_of( 603 ) ), array( 'trash', 'trash' ) );
check( '  602, found to be on eBay.com since, spared', status_of( 602 ), 'publish' );
check( '  610, in a preserved category, kept', status_of( 610 ), 'publish' );
check( '  saying why', logged( 'Other-site cleanup: WC #610 (eBay United Kingdom listing 500000000010) kept - it is in a category set to be preserved (Preserve Categories, Do Not Trash).' ), true );
check( '  the rest untouched', array_map( 'status_of', array( 604, 605, 606, 607, 609, 611 ) ), array_fill( 0, 6, 'publish' ) );
check( 'the trash only - nothing deleted for good', $DELETED, array() );
check( '  and eBay never called: their listings carry on', array( $EBAY['wire'], $EBAY['get_items'] ), array( array(), array() ) );
check( 'each one logged', array( logged( 'Other-site cleanup: WC #601 (eBay United Kingdom listing 500000000001) moved to the trash.' ), logged( 'Other-site cleanup: WC #603 (eBay Germany listing 500000000003) moved to the trash.' ) ), array( true, true ) );
check( 'the list now holds only the kept one', TCGiant_Sync_Sites::find_other_site_products(), array( 610 ) );
delta( array( event( '500000000001', 'UK' ) ) );
check( 'a trashed one\'s listing changes again: not imported again', array( $CREATED, status_of( 601 ) ), array( array(), 'trash' ) );

// A product handed to the job by ID that is not from another site is spared.
$started = start_job( array( 'type' => 'bulk_trash_other_sites', 'product_ids' => array( 604, 609 ) ) );
$done    = run_job( $started['data']['job_id'] );
check( 'the eBay.com product and the pushed one, handed over by ID: both kept', array( status_of( 604 ), status_of( 609 ), $done['data']['kept'] ?? null ), array( 'publish', 'publish', 2 ) );

// The shop goes back to all sites while the job runs: nothing more is moved.
fresh_shop( $ONLY_US );
linked_product( 621, '510000000001', 'UK' );
linked_product( 622, '510000000002', 'UK' );
$started = press_trash_button( stock_review() );
$OPTIONS['tcgiant_sync_ebay_settings']['import_sites'] = 'all';
$done = run_job( $started['data']['job_id'] );
check( 'set back to All eBay sites mid-job: nothing moved to the trash', array( status_of( 621 ), status_of( 622 ), $done['data']['kept'] ?? null ), array( 'publish', 'publish', 2 ) );
check( '  said once, not once a product', log_lines_naming( 'Other-site cleanup: stopped' ), 1 );

// While every site is imported, nothing is offered.
fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
linked_product( 631, '520000000001', 'UK' );
check( 'all eBay sites: Stock Review shows no such panel', false !== strpos( stock_review(), 'other eBay site' ), false );
$started = start_job( array( 'type' => 'bulk_trash_other_sites', 'select_all' => 1 ) );
check( '  and a request for "everything shown" has no list to act on', array( $started['success'] ?? null, $started['data']['message'] ?? null ), array( false, 'This list is out of date. Reload Stock Review to see it as it is now.' ) );
check( '  nothing moved', status_of( 631 ), 'publish' );

echo "\nTHE BUTTON ACTS ON THE LIST THE PAGE SHOWED (FAILS ON THE FIRST 3.27.5 BUILD)\n" . str_repeat( '=', 142 ) . "\n";

// The page lists one product from eBay.co.uk and asks for two more to be
// checked. While it is open the shop does as it says - or a delta runs - and
// those two are recorded as eBay.co.uk too. Its question still says one. The
// first build drew the list again when the button was pressed, and moved
// three to the trash.
fresh_shop( $ONLY_US );
linked_product( 641, '514000000001', 'UK' );
linked_product( 642, '514000000002', null );
linked_product( 643, '514000000003', null );
$html = stock_review();
check( 'the page lists one, and asks for the other two to be checked', array( false !== strpos( $html, '1 product on your website came from a listing on another eBay site' ), false !== strpos( $html, '2 linked products have not been checked yet' ) ), array( true, true ) );
check( '  and its button asks about that one', false !== strpos( $html, 'Move 1 product from another eBay site to the trash?' ), true );
scan_page( array( listing( '514000000002', 'UK' ), listing( '514000000003', 'UK' ) ) );
check( 'a sync while it is open records the other two as eBay.co.uk', array( meta( 642, '_ebay_site' ), meta( 643, '_ebay_site' ) ), array( 'UK', 'UK' ) );
$started = press_trash_button( $html );
check( 'the button on that page: a job for the one it showed', array( $started['success'] ?? null, $started['data']['total'] ?? null ), array( true, 1 ) );
run_job( $started['data']['job_id'] ?? '' );
check( '  so only that one is moved to the trash', array_map( 'status_of', array( 641, 642, 643 ) ), array( 'trash', 'publish', 'publish' ) );
$again = press_trash_button( $html );
check( 'pressed again from the same page: refused - its list was used', array( $again['success'] ?? null, $again['data']['message'] ?? null ), array( false, 'This list is out of date. Reload Stock Review to see it as it is now.' ) );
$older = stock_review();
$newer = stock_review();
check( 'a page older than the last one drawn: refused', press_trash_button( $older )['success'] ?? null, false );
$started = press_trash_button( $newer );
check( '  the newest page acts on what it shows: the two', $started['data']['total'] ?? null, 2 );
$mine    = stock_review();
$USER_ID = 2;
$theirs  = stock_review();
$USER_ID = 1;
check( 'each user\'s page holds its own list: another\'s is refused', press_trash_button( $theirs )['success'] ?? null, false );
check( '  and this user\'s own still works', press_trash_button( $mine )['data']['total'] ?? null, 2 );
$USER_ID = 2;
check( '  as does theirs, for them', press_trash_button( $theirs )['data']['total'] ?? null, 2 );
$USER_ID = 1;
check( 'a list is kept for a day at most', $TRANSIENT_TTL[ TCGiant_Sync_Sites::LIST_TRANSIENT . '2' ] ?? null, DAY_IN_SECONDS );

// All of them preserved: nothing to move, so no button and no list held.
fresh_shop( array_merge( $ONLY_US, array( 'preserve_woo_category_ids' => array( '55' ) ) ) );
linked_product( 644, '514000000004', 'UK' );
$TERMS[644] = array( 55 );
$html = stock_review();
check( 'every one in a preserved category: no button', false !== strpos( $html, 'id="tc-trash-other-sites"' ), false );
check( '  saying why', false !== strpos( $html, 'Every one of them is in a category your Preserve Categories protect, so there is nothing to move to the trash.' ), true );
check( '  and no list kept for a button that is not there', array_key_exists( TCGiant_Sync_Sites::LIST_TRANSIENT . '1', $TRANSIENTS ), false );

echo "\nTHE SHOP'S OWN PRODUCTS ARE NEVER OFFERED, HOWEVER THEIR LAST PUSH WENT (FAILS ON THE FIRST 3.27.5 BUILD)\n" . str_repeat( '=', 142 ) . "\n";

// A shop that moved from eBay US to eBay UK. Its own catalogue went to eBay.com
// by Push years ago. Since then one product's revise failed, one is queued,
// one is being pushed, and one had its error cleared by hand. The first build
// spared only the one still saying "pushed".
fresh_shop( array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_GB' ) );
$pushed = array( '_ebay_export_last_pushed' => '2025-01-01 10:00:00', '_ebay_export_pushed_version' => '3.20.0' );
linked_product( 651, '515000000001', 'US', array_merge( $pushed, array( '_ebay_export_status' => 'pushed' ) ) );
linked_product( 652, '515000000002', 'US', array_merge( $pushed, array( '_ebay_export_status' => 'error', '_ebay_export_error' => 'Revise failed' ) ) );
linked_product( 653, '515000000003', 'US', array_merge( $pushed, array( '_ebay_export_status' => 'queued' ) ) );
linked_product( 654, '515000000004', 'US', array( '_ebay_export_status' => 'pushing', '_ebay_export_last_pushed' => '2025-01-01 10:00:00' ) );
linked_product( 655, '515000000005', 'US', array( '_ebay_export_status' => '', '_ebay_export_pushed_version' => '3.20.0' ) );
// Imported from eBay.com listings, never pushed. The shop pressed "Update eBay
// Listing" on one and eBay refused it: an attempt is not a push. Another is
// queued, a third is being pushed, and a fourth's push died (3.27.4's
// statuses). A push that dies once eBay has made the listing leaves its
// number nowhere else, so in the trash that listing would be left live with
// its product gone; queued or under way, a push may be about to make one.
linked_product( 656, '515000000006', 'US', array( '_ebay_export_status' => 'error', '_ebay_export_error' => 'No category' ) );
linked_product( 657, '515000000007', 'US', array( '_ebay_export_status' => 'queued' ) );
linked_product( 658, '515000000008', 'US', array( '_ebay_export_status' => 'pushing', '_ebay_export_pushing_since' => (string) time() ) );
linked_product( 659, '515000000009', 'US', array( '_ebay_export_status' => 'failed', '_ebay_export_error' => 'Push did not finish - check eBay, then use Link.' ) );
check( 'which are the shop\'s own', array_map( array( 'TCGiant_Sync_Sites', 'is_shops_own' ), range( 651, 659 ) ), array( true, true, true, true, true, false, true, true, true ) );
check( 'pushed by the shop, or a push of it on its way or that did not finish: none offered', TCGiant_Sync_Sites::find_other_site_products(), array( 656 ) );
check( '  counted the same way', TCGiant_Sync_Sites::count_other_site_products_by_site(), array( 'eBay United States' => 1 ) );
$started = press_trash_button( stock_review() );
run_job( $started['data']['job_id'] ?? '' );
check( 'the button moves only the one whose push eBay refused', array_map( 'status_of', range( 651, 659 ) ), array( 'publish', 'publish', 'publish', 'publish', 'publish', 'trash', 'publish', 'publish', 'publish' ) );
$started = start_job( array( 'type' => 'bulk_trash_other_sites', 'product_ids' => array( 652, 653, 654, 655, 657, 658, 659 ) ) );
run_job( $started['data']['job_id'] ?? '' );
check( '  and handed over by ID, the shop\'s own and those mid-push are still kept', array_map( 'status_of', array( 652, 653, 654, 655, 657, 658, 659 ) ), array_fill( 0, 7, 'publish' ) );
// The queued push runs and eBay refuses it: an attempt again, so offered.
update_post_meta( 657, '_ebay_export_status', 'error' );
check( '  a queued push eBay then refuses: offered again', TCGiant_Sync_Sites::find_other_site_products(), array( 657 ) );
fresh_shop( $ONLY_US );
linked_product( 665, '515000000015', null, array_merge( $pushed, array( '_ebay_export_status' => 'error' ) ) );
linked_product( 666, '515000000016', null, array( '_ebay_export_status' => 'error' ) );
linked_product( 667, '515000000017', null, array( '_ebay_export_status' => 'failed' ) );
linked_product( 668, '515000000018', null, array( '_ebay_export_status' => 'queued' ) );
check( 'not checked yet: the shop\'s own and those mid-push are not counted, an import whose push failed is', TCGiant_Sync_Sites::count_unchecked_products(), 1 );

echo "\nSTOCK REVIEW'S TRASH SAVES UNDER THE SAVE-LOOP BREAKER (3.27.4)\n" . str_repeat( '=', 142 ) . "\n";

// Moving a product to the trash goes through wp_update_post() and save_post,
// which another plugin can save the product again from - Jon's loop.
fresh_shop( $ONLY_US );
linked_product( 691, '519000000001', 'UK' );
linked_product( 692, '519000000002', 'Germany' );
$started = press_trash_button( stock_review() );
run_job( $started['data']['job_id'] ?? '' );
check( 'each product the button moves is trashed with the breaker on', array( status_of( 691 ), status_of( 692 ), $TRASH_LOOPS ), array( 'trash', 'trash', array( 691 => 1, 692 => 1 ) ) );
check( '  and it is off again after', loop_depth(), 0 );
fresh_shop( $ONLY_US );
linked_product( 693, '519000000003', 'UK' );
$TRASH_FILTER = new RuntimeException( 'another plugin threw' );
try {
	TCGiant_Sync_Sites::trash_other_site_product( 693 );
	$threw = '';
} catch ( RuntimeException $e ) {
	$threw = $e->getMessage();
}
$TRASH_FILTER = null;
check( 'a trash that throws: the breaker is off again all the same', array( $threw, $TRASH_LOOPS, loop_depth() ), array( 'another plugin threw', array( 693 => 1 ), 0 ) );

echo "\nA PRODUCT OUTSIDE THE IMPORT FILTER IS CHECKED TOO, SO \"NOT CHECKED YET\" CLEARS (FAILS ON THE FIRST 3.27.5 BUILD)\n" . str_repeat( '=', 142 ) . "\n";

// Imported before 3.27.5, from an eBay.com listing and an eBay.co.uk copy whose
// category the shop has since taken out of its Standard Categories filter.
// Stock Review asks for a Fetch Inventory to check them; the first build
// recorded only the copy, so the line never went away.
fresh_shop( array_merge( $ONLY_US, array( 'import_standard_category_ids' => array( '111' ) ) ) );
linked_product( 661, '516000000001', null );
linked_product( 662, '516000000002', null );
check( 'before: two not checked yet', TCGiant_Sync_Sites::count_unchecked_products(), 2 );
scan_page( array(
	listing( '516000000001', 'US', array( 'PrimaryCategory' => array( 'CategoryID' => '222' ), 'Quantity' => '7' ) ),
	listing( '516000000002', 'UK', array( 'PrimaryCategory' => array( 'CategoryID' => '222' ), 'Quantity' => '7' ) ),
) );
check( 'Fetch Inventory, both outside the filter: the eBay.co.uk product recorded', meta( 662, '_ebay_site' ), 'UK' );
check( '  and the eBay.com one', meta( 661, '_ebay_site' ), 'US' );
check( '  so none is left to check', TCGiant_Sync_Sites::count_unchecked_products(), 0 );
check( '  and Stock Review stops asking for one', false !== strpos( stock_review(), 'not been checked yet' ), false );
check( '  the filter still holds: neither is saved from its listing', array( stock( 661 ), stock( 662 ), $CREATED ), array( 1, 1, array() ) );
fresh_shop( array_merge( $ONLY_US, array( 'import_standard_category_ids' => array( '111' ) ) ) );
linked_product( 663, '516000000003', null );
delta( array( event( '516000000003', 'US', 'Active', array( 'PrimaryCategory' => array( 'CategoryID' => '222' ) ) ) ) );
check( 'the delta records it as well, and asks eBay nothing', array( meta( 663, '_ebay_site' ), $EBAY['get_items'] ), array( 'US', array() ) );
fresh_shop( array( 'marketplace' => 'EBAY_US', 'import_standard_category_ids' => array( '111' ) ) );
linked_product( 664, '516000000004', null );
scan_page( array( listing( '516000000004', 'US', array( 'PrimaryCategory' => array( 'CategoryID' => '222' ) ) ) ) );
check( 'all eBay sites: nothing written to a product the filter skips, as before', writes_to( 664 ), array() );

echo "\nA DELTA ROW WITHOUT A SITE IS ASKED ABOUT ONCE, NOT EVERY TIME IT CHANGES (FAILS ON THE FIRST 3.27.5 BUILD)\n" . str_repeat( '=', 142 ) . "\n";

// Should eBay's list of changes ever leave out <Site>, a copy with no product
// was asked about on every change - every quantity update Web Interpret makes
// to each of 35,000 copies - and its site was recorded nowhere.
fresh_shop( $ONLY_US );
ebay_has( '517000000001', listing( '517000000001', 'UK' ) );
$row = listing( '517000000001', null );
delta( array( $row ) );
delta( array( $row ) );
delta( array( $row ) );
check( 'an eBay.co.uk copy changes three times, its rows without a site: eBay asked once', $EBAY['get_items'], array( '517000000001' ) );
check( '  what it said is remembered', get_transient( TCGiant_Sync_Sites::SITE_TRANSIENT . '517000000001' ), 'UK' );
check( '  for ten days', $TRANSIENT_TTL[ TCGiant_Sync_Sites::SITE_TRANSIENT . '517000000001' ] ?? null, 10 * DAY_IN_SECONDS );
check( '  and no product made from it', $CREATED, array() );
check( '  each change counted as left out', log_lines_naming( '1 left out (on other eBay sites)' ), 3 );
// Asked for the category the filter needs: remembered from that answer too.
fresh_shop( array_merge( $ONLY_US, array( 'import_standard_category_ids' => array( '999' ) ) ) );
ebay_has( '517000000002', listing( '517000000002', 'UK' ) );
delta( array( event( '517000000002', null ) ) );
delta( array( event( '517000000002', null ) ) );
check( 'a row with no site and no category: asked once, for the filter', $EBAY['get_items'], array( '517000000002' ) );
// An eBay.com listing: its site remembered, and its product made as before.
fresh_shop( $ONLY_US );
ebay_has( '517000000003', listing( '517000000003', 'US' ) );
delta( array( listing( '517000000003', null ) ) );
check( 'the shop\'s own listing with no site in its row: asked, and imported', array( $EBAY['get_items'], count( $CREATED ) ), array( array( '517000000003' ), 1 ) );
check( '  its site remembered and recorded', array( get_transient( TCGiant_Sync_Sites::SITE_TRANSIENT . '517000000003' ), meta( $CREATED[0] ?? 0, '_ebay_site' ) ), array( 'US', 'US' ) );
fresh_shop( array( 'import_sites' => 'all', 'marketplace' => 'EBAY_US' ) );
ebay_has( '517000000001', listing( '517000000001', 'UK' ) );
delta( array( $row ) );
check( 'all eBay sites: a full row needs no GetItem, as in 3.27.4', $EBAY['get_items'], array() );
ebay_has( '517000000004', listing( '517000000004', 'UK' ) );
delta( array( event( '517000000004', null ) ) );
check( '  a brief one is asked about for its details, as before', $EBAY['get_items'], array( '517000000004' ) );
check( '  and nothing is remembered either way', array( array_key_exists( TCGiant_Sync_Sites::SITE_TRANSIENT . '517000000001', $TRANSIENTS ), array_key_exists( TCGiant_Sync_Sites::SITE_TRANSIENT . '517000000004', $TRANSIENTS ) ), array( false, false ) );

echo "\nWHAT THE TRASH CAN REFUSE, AND HOW MANY GO AT ONCE\n" . str_repeat( '=', 142 ) . "\n";

// A pre_trash_post filter - another plugin's - can stop it: core then returns
// whatever the filter did. The first build counted and logged the product as
// moved all the same.
foreach ( array( 'false' => false, 'a value of its own' => true ) as $what => $filter_says ) {
	fresh_shop( $ONLY_US );
	linked_product( 671, '518000000001', 'UK' );
	$TRASH_FILTER = $filter_says;
	$started      = press_trash_button( stock_review() );
	$done         = run_job( $started['data']['job_id'] ?? '' );
	$TRASH_FILTER = null;
	check( sprintf( 'a filter stops the trash, returning %s: kept, and not counted as moved', $what ), array( status_of( 671 ), $done['data']['succeeded'] ?? null, $done['data']['kept'] ?? null ), array( 'publish', 0, 1 ) );
	check( '  the log says it was not moved', array( logged( 'Other-site cleanup: WC #671 (eBay United Kingdom listing 518000000001) kept - WordPress did not move it to the trash.', 'warning' ), logged( 'listing 518000000001) moved to the trash' ) ), array( true, false ) );
}

// It asks eBay nothing, so a request takes fifty, not the five of a job that does.
fresh_shop( $ONLY_US );
for ( $i = 1; $i <= 60; $i++ ) {
	linked_product( 700 + $i, sprintf( '5190000000%02d', $i ), 'UK' );
}
$started = press_trash_button( stock_review() );
$_POST   = array( 'job_id' => $started['data']['job_id'] ?? '' );
try {
	TCGiant_Sync_Jobs::instance()->ajax_process_batch();
	$first = null;
} catch ( Harness_Sent $sent ) {
	$first = $sent->payload;
}
check( 'sixty products: fifty moved in the first request', array( $first['data']['processed'] ?? null, $first['data']['succeeded'] ?? null, $first['data']['status'] ?? null ), array( 50, 50, 'running' ) );
check( '  the rest in the next', ( run_job( $started['data']['job_id'] ?? '' ) )['data']['processed'] ?? null, 60 );
check( '  every one in the trash', count( array_filter( range( 701, 760 ), function ( $id ) { return 'trash' === status_of( $id ); } ) ), 60 );
$src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-jobs.php' );
check( '  the other jobs still take five', false !== strpos( $src, "? TCGiant_Sync_Sites::CLEANUP_BATCH : 5;" ), true );

// The page draws fifty rows at a time, and the button moves the whole list it
// drew, every page of it, as its question says. The candidate's readme said it
// moved "the ones the page showed you": for a Web Interpret shop with 35,000
// copies, fifty on screen.
fresh_shop( $ONLY_US );
for ( $i = 1; $i <= 55; $i++ ) {
	linked_product( 3000 + $i, sprintf( '5195000000%02d', $i ), 'UK' );
}
$html    = stock_review();
$started = press_trash_button( $html );
run_job( $started['data']['job_id'] ?? '' );
check( 'fifty-five products: fifty rows drawn, and the question and the button take all fifty-five', array( substr_count( $html, 'Moved to the trash' ), false !== strpos( $html, 'Product #3050<' ), false !== strpos( $html, 'Product #3051<' ), false !== strpos( $html, 'Move 55 products from other eBay sites to the trash?' ), count( array_filter( range( 3001, 3055 ), function ( $id ) { return 'trash' === status_of( $id ); } ) ) ), array( 50, true, false, true, 55 ) );
$readme = (string) file_get_contents( $ROOT . '/readme.txt' );
check( '  as the readme says: every product on the list, on all its pages', array( false !== strpos( $readme, 'When you press the button, it moves every product on the list the page drew, on all its pages, to the trash.' ), false !== strpos( $readme, 'the ones the page showed you' ) ), array( true, false ) );

echo "\nA MARKETPLACE SET WRONGLY: STOCK REVIEW MOVES NOTHING (FAILS ON THE 3.27.5 CANDIDATE)\n" . str_repeat( '=', 142 ) . "\n";

// An eBay.com shop with eBay United Kingdom saved chooses only its Primary eBay
// Marketplace, and runs Fetch Inventory as Stock Review asks. Every product is
// recorded as eBay United States, so the whole catalogue is "from another eBay
// site". The full sync says the marketplace is almost certainly wrong, and the
// candidate's Stock Review offered to move every product to the trash all the
// same - and did, by the button and by ID. Once the marketplace was put right,
// the syncs left them in the trash, as products deleted on purpose, and once
// the trash was emptied 3.27.4's photo cleanup took their photos.
fresh_shop( array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_GB' ) );
linked_product( 1001, '110000001001', null );
linked_product( 1002, '110000001002', null );
linked_product( 1003, '110000001003', null );
$running = start_job( array( 'type' => 'bulk_trash_other_sites', 'product_ids' => array( 1001, 1002 ) ) );
$IMP->start_full_sync( true );
scan_page( array( listing( '110000001001', 'US' ), listing( '110000001002', 'US' ), listing( '110000001003', 'US' ) ) );
call_private( $IMP, 'finalize_scan', true );
$SCREEN = 'tcgiant-sync_page_tcgiant-stock-review';
check( 'nothing found on eBay United Kingdom: the full sync says so, on Stock Review too', array( TCGiant_Sync_Sites::mismatch()['sites'] ?? null, false !== strpos( render( function () use ( $ADMIN ) { $ADMIN->other_sites_notice(); } ), 'The last full sync found no listings on eBay United Kingdom' ) ), array( array( 'eBay United States' ), true ) );
$html = stock_review();
check( 'Stock Review still lists them', array( TCGiant_Sync_Sites::find_other_site_products(), false !== strpos( $html, '3 products on your website came from listings on other eBay sites (eBay United States: 3).' ) ), array( array( 1001, 1002, 1003 ), true ) );
check( '  says why it removes nothing, and does not promise the trash', array( false !== strpos( $html, 'The last full sync found no listings on eBay United Kingdom, your Primary eBay Marketplace, so it is almost certainly set wrongly, and these are most likely your own products. This page does not remove them until you correct Primary eBay Marketplace in Settings, or a full sync finds listings on it.' ), false !== strpos( $html, 'They go to the trash, not away for good.' ) ), array( true, false ) );
check( '  no button, and each one "left here" - none "moved", nor "every one preserved"', array( false !== strpos( $html, 'id="tc-trash-other-sites"' ), substr_count( $html, 'Left here - check Primary eBay Marketplace first' ), substr_count( $html, 'Moved to the trash' ), false !== strpos( $html, 'Every one of them is in a category' ) ), array( false, 3, 0, false ) );
check( '  and no list held for a button that is not there', array_key_exists( TCGiant_Sync_Sites::LIST_TRANSIENT . '1', $TRANSIENTS ), false );
$started = start_job( array( 'type' => 'bulk_trash_other_sites', 'product_ids' => array( 1003 ) ) );
check( 'a job asked for by ID: refused, saying why', array( $started['success'] ?? null, $started['data']['message'] ?? null ), array( false, 'The last full sync found no listings on eBay United Kingdom, your Primary eBay Marketplace, so it is almost certainly set wrongly. Nothing was moved. Correct it in Settings, then reload Stock Review.' ) );
$done = run_job( $running['data']['job_id'] ?? '' );
check( 'a job started before the full sync said so: both kept, none moved', array( $done['data']['kept'] ?? null, $done['data']['succeeded'] ?? null, array_map( 'status_of', array( 1001, 1002, 1003 ) ) ), array( 2, 0, array( 'publish', 'publish', 'publish' ) ) );
check( '  the log says why, once, and never that one moved', array( log_lines_naming( 'Other-site cleanup: stopped - the last full sync found no listings on eBay United Kingdom, your Primary eBay Marketplace, so it is almost certainly set wrongly. Nothing more was removed.' ), logged( 'moved to the trash.' ) ), array( 1, false ) );
// Put right: the list is empty, and the products carry on as the shop's own.
$ADMIN->sanitize_settings( array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' ) );
$OPTIONS['tcgiant_sync_ebay_settings']['marketplace'] = 'EBAY_US';
check( 'the marketplace corrected: nothing listed, nothing moved', array( TCGiant_Sync_Sites::mismatch(), TCGiant_Sync_Sites::find_other_site_products(), array_map( 'status_of', array( 1001, 1002, 1003 ) ) ), array( null, array(), array( 'publish', 'publish', 'publish' ) ) );
// A real copy, once a full sync has found the shop's own listings: offered.
linked_product( 1004, '220000001004', 'UK' );
$IMP->start_full_sync( true );
scan_page( array( listing( '110000001001', 'US' ), listing( '220000001004', 'UK' ) ) );
call_private( $IMP, 'finalize_scan', true );
$started = press_trash_button( stock_review() );
run_job( $started['data']['job_id'] ?? '' );
check( '  and a real copy is offered and moved, as before', array( $started['data']['total'] ?? null, status_of( 1004 ), status_of( 1001 ) ), array( 1, 'trash', 'publish' ) );

echo "\nA PUSH THAT FINDS ITS LISTING ENDED NEVER RELISTS ANOTHER SITE'S COPY ON THE SHOP'S OWN (FAILS ON THE FIRST 3.27.5 BUILD)\n" . str_repeat( '=', 142 ) . "\n";

define( 'TCGIANT_SYNC_VERSION', '3.27.5' );
require_once $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-exporter.php';

// process_single_push(), do_push(), the ended-listing check and relist as
// shipped, with only the private listing builder answered from a script:
// build_item_xml() reads the photos, the description and the policies, none of
// which is in question here. The export settings and their validation are the
// probe's own, below.
$src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-exporter.php' );
$src = preg_replace( '/^<\?php/', '', $src );
$src = str_replace( "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}", '', $src );
$src = str_replace( 'class TCGiant_Sync_Exporter {', 'class Push_Harness_Exporter {', $src, $renamed );
$src = str_replace( 'private function build_item_xml( WC_Product $product, array $settings, $revise = false ) {', 'private function build_item_xml( WC_Product $product, array $settings, $revise = false ) { return \'<Title>\' . $product->get_name() . \'</Title>\'; } private function build_item_xml_as_shipped( WC_Product $product, array $settings, $revise = false ) {', $src, $swapped );
if ( 1 !== $renamed || 1 !== $swapped ) {
	echo "could not prepare the exporter\n";
	exit( 2 );
}
eval( $src ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

class Push_Probe extends Push_Harness_Exporter {
	public function get_export_settings( $product_id ) { return array( 'listing_type' => 'FixedPriceItem', 'listing_duration' => 'GTC' ); }
	public function validate_export_settings( array $settings, $new_listing = true ) { return true; }
	public function find_missing_required_aspects( WC_Product $product, array $settings ) { return array(); }
}
$PROBE = ( new ReflectionClass( 'Push_Probe' ) )->newInstanceWithoutConstructor();

/** Push one product, as the background push does: "Update eBay Listing", or the Listings screen's Push. */
function push( $id ) {
	$GLOBALS['EBAY']['wire']      = array();
	$GLOBALS['EBAY']['get_items'] = array();
	$GLOBALS['PROBE']->process_single_push( $id );
}
function relist( $id ) {
	$GLOBALS['EBAY']['wire']      = array();
	$GLOBALS['EBAY']['get_items'] = array();
	return TCGiant_Sync_Exporter::instance()->relist_ended_listing( $id, 'Bulk relist' );
}
/** What a relist did: refused (with the reason), or sent to eBay. */
function relist_outcome( $result ) {
	if ( array_intersect( array( 'RelistFixedPriceItem', 'RelistItem' ), $GLOBALS['EBAY']['wire'] ) ) {
		return 'sent to eBay';
	}
	return is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'relisted';
}
/** GetItem's answer for an ended listing that sold nothing. */
function ended( $item_id, $site ) {
	return listing( $item_id, $site, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0' ), 'Quantity' => '3' ) );
}
$UK_REFUSAL = 'its eBay listing is on eBay United Kingdom, and Import listings from is set to eBay United States only';

// Imported from a Web Interpret copy on eBay.co.uk before the shop chose eBay
// United States only. The copy has ended with stock left. The shop selects
// everything on the Listings screen and pushes: the revise finds the listing
// ended, and the first build created a new eBay.com listing at the copy's
// figures, in pounds, and marked the product pushed - off Stock Review for good.
fresh_shop( $ONLY_US );
linked_product( 801, '220000000801', 'UK', array( '_ebay_listing_status' => 'Ended', '_stock' => 3, '_regular_price' => '19.99' ) );
push( 801 );
check( 'an ended eBay.co.uk copy pushed: no new listing made', $EBAY['wire'], array( 'ReviseFixedPriceItem' ) );
check( '  refused from its recorded site, before eBay is asked about it', $EBAY['get_items'], array() );
check( '  the push says why, where the shop looks', array( meta( 801, '_ebay_export_status' ), meta( 801, '_ebay_export_error' ) ), array( 'error', $UK_REFUSAL ) );
check( '  still linked to its own listing, still recorded on eBay.co.uk', array( meta( 801, '_ebay_item_id' ), meta( 801, '_ebay_site' ) ), array( '220000000801', 'UK' ) );
check( '  and a refused push does not make it the shop\'s own: still offered on Stock Review', TCGiant_Sync_Sites::find_other_site_products(), array( 801 ) );
check( '  nor let Relist put the copy back up', relist_outcome( relist( 801 ) ), 'relist_refused: ' . $UK_REFUSAL );

// Imported before 3.27.5, so no site is recorded: the ended-listing check asks
// eBay anyway, and its answer decides.
fresh_shop( $ONLY_US );
linked_product( 802, '220000000802', null, array( '_ebay_listing_status' => 'Ended', '_stock' => 3 ) );
ebay_has( '220000000802', ended( '220000000802', 'UK' ) );
push( 802 );
check( 'no site recorded, eBay says eBay.co.uk: no new listing made', $EBAY['wire'], array( 'ReviseFixedPriceItem' ) );
check( '  judged on the answer the ended-listing check already asked for - one GetItem', $EBAY['get_items'], array( '220000000802' ) );
check( '  refused, and the site recorded', array( meta( 802, '_ebay_export_error' ), meta( 802, '_ebay_site' ) ), array( $UK_REFUSAL, 'UK' ) );

// Recorded as eBay United States, wrongly: eBay's answer still decides.
fresh_shop( $ONLY_US );
linked_product( 803, '220000000803', 'US', array( '_ebay_listing_status' => 'Ended', '_stock' => 3 ) );
ebay_has( '220000000803', ended( '220000000803', 'UK' ) );
push( 803 );
check( 'recorded as eBay.com, eBay says eBay.co.uk: refused on eBay\'s word', array( $EBAY['wire'], meta( 803, '_ebay_site' ) ), array( array( 'ReviseFixedPriceItem' ), 'UK' ) );

// The shop's own eBay.com listing has ended: a new one, as before, and recorded
// on the shop's own site.
fresh_shop( $ONLY_US );
linked_product( 804, '110000000804', 'US', array( '_ebay_listing_status' => 'Ended', '_stock' => 3 ) );
ebay_has( '110000000804', ended( '110000000804', 'US' ) );
$EBAY['new_item'] = '110000009804';
push( 804 );
check( 'the shop\'s own ended eBay.com listing pushed: a new listing, as before', array( $EBAY['wire'], meta( 804, '_ebay_item_id' ), meta( 804, '_ebay_export_status' ) ), array( array( 'ReviseFixedPriceItem', 'AddFixedPriceItem' ), '110000009804', 'pushed' ) );
check( '  on eBay United States', meta( 804, '_ebay_site' ), 'US' );

// All eBay sites, the default: an ended copy is relisted on the shop's own site
// as in 3.27.4 - and recorded on the site the new listing is on, not the copy's.
// The first build kept "UK" on it, so once the shop chose eBay United States
// only, a push of that eBay.com listing when it ended was refused for the
// wrong site.
fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
linked_product( 805, '220000000805', 'UK', array( '_ebay_listing_status' => 'Ended', '_stock' => 3 ) );
ebay_has( '220000000805', ended( '220000000805', 'UK' ) );
$EBAY['new_item'] = '110000009805';
push( 805 );
check( 'all eBay sites: an ended copy pushed is relisted on the shop\'s site, as in 3.27.4', array( $EBAY['wire'], meta( 805, '_ebay_item_id' ) ), array( array( 'ReviseFixedPriceItem', 'AddFixedPriceItem' ), '110000009805' ) );
check( '  and recorded on the new listing\'s site, eBay United States', meta( 805, '_ebay_site' ), 'US' );
$OPTIONS['tcgiant_sync_ebay_settings'] = $ONLY_US;
$GLOBALS['META'][805]['_ebay_listing_status'] = 'Ended';
ebay_has( '110000009805', ended( '110000009805', 'US' ) );
$EBAY['new_item'] = '110000019805';
push( 805 );
check( '  then eBay United States only, and that eBay.com listing ends: pushed again, it is relisted', array( $EBAY['wire'], meta( 805, '_ebay_item_id' ) ), array( array( 'ReviseFixedPriceItem', 'AddFixedPriceItem' ), '110000019805' ) );
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_GB' );
product( 806, array( '_sku' => 'OWN-806' ) );
$EBAY['new_item'] = '220000009806';
push( 806 );
check( 'a product listed for the first time: recorded on the shop\'s own site (eBay United Kingdom here)', array( $EBAY['wire'], meta( 806, '_ebay_site' ) ), array( array( 'AddFixedPriceItem' ), 'UK' ) );
// A revise changes nothing about where the listing is.
fresh_shop( $ONLY_US );
linked_product( 807, '220000000807', 'UK' );
$EBAY['revise'] = array( 'Ack' => 'Success', 'ItemID' => '220000000807' );
push( 807 );
check( 'a live copy revised: still recorded on eBay.co.uk', array( $EBAY['wire'], meta( 807, '_ebay_site' ) ), array( array( 'ReviseFixedPriceItem' ), 'UK' ) );

echo "\nAN ENDED COPY WITH NO SITE RECORDED IS ASKED ABOUT, EVEN WHERE ITS SALES ARE ALREADY IN (FAILS ON THE SECOND 3.27.5 BUILD)\n" . str_repeat( '=', 142 ) . "\n";

// The usual state of a copy that ended before 3.27.5: the 3.27.4 delta, the
// hourly check or Stock Review settled it, so its sales are in the stock, and
// no site was recorded - the full sync reads live listings only. The ended-
// listing check asks eBay nothing for it, and the second build, with neither a
// site nor an answer to judge by, made a new eBay.com listing at the copy's
// figures, recorded it as eBay United States and marked it pushed: off Stock
// Review for good, and spared by Relist from then on.
$settled = function ( $item_id ) {
	return array( '_ebay_listing_status' => 'Ended', '_stock' => 3, '_regular_price' => '19.99', '_ebay_stock_settled_at' => '2026-09-01 10:00:00', '_ebay_stock_settled_item' => $item_id );
};
fresh_shop( $ONLY_US );
linked_product( 811, '220000000811', null, $settled( '220000000811' ) );
ebay_has( '220000000811', ended( '220000000811', 'UK' ) );
$EBAY['new_item'] = '110000009811';
push( 811 );
check( 'settled, no site recorded, eBay says eBay.co.uk: no new listing made', $EBAY['wire'], array( 'ReviseFixedPriceItem' ) );
check( '  eBay asked which site it is on, once', $EBAY['get_items'], array( '220000000811' ) );
check( '  refused, saying why, where the shop looks', array( meta( 811, '_ebay_export_status' ), meta( 811, '_ebay_export_error' ) ), array( 'error', $UK_REFUSAL ) );
check( '  still on its eBay.co.uk listing, now recorded there', array( meta( 811, '_ebay_item_id' ), meta( 811, '_ebay_previous_item_id' ), meta( 811, '_ebay_site' ) ), array( '220000000811', null, 'UK' ) );
check( '  so it is still the copy it was: on Stock Review, not the shop\'s own', array( TCGiant_Sync_Sites::find_other_site_products(), TCGiant_Sync_Sites::is_shops_own( 811 ) ), array( array( 811 ), false ) );
push( 811 );
check( '  and pushed again, refused before eBay is asked anything', array( $EBAY['wire'], $EBAY['get_items'] ), array( array( 'ReviseFixedPriceItem' ), array() ) );

// A variable product: the check never asks about a variation listing.
fresh_shop( $ONLY_US );
linked_product( 812, '220000000812', null, array( '_ebay_listing_status' => 'Ended', '_stock' => 3 ) );
$POSTS[812]['product_type'] = 'variable';
ebay_has( '220000000812', ended( '220000000812', 'UK' ) );
$EBAY['new_item'] = '110000009812';
push( 812 );
check( 'a variable product, no site recorded, eBay says eBay.co.uk: no new listing made', array( $EBAY['wire'], $EBAY['get_items'] ), array( array( 'ReviseFixedPriceItem' ), array( '220000000812' ) ) );
check( '  refused, and recorded', array( meta( 812, '_ebay_item_id' ), meta( 812, '_ebay_export_error' ), meta( 812, '_ebay_site' ) ), array( '220000000812', $UK_REFUSAL, 'UK' ) );

// eBay does not answer: not listed on a guess, as Relist does.
fresh_shop( $ONLY_US );
linked_product( 813, '220000000813', null, $settled( '220000000813' ) );
$EBAY['new_item'] = '110000009813';
push( 813 );
check( 'settled, no site recorded, eBay does not answer: no new listing made', array( $EBAY['wire'], $EBAY['get_items'] ), array( array( 'ReviseFixedPriceItem' ), array( '220000000813' ) ) );
check( '  and the push says so', array( meta( 813, '_ebay_item_id' ), meta( 813, '_ebay_export_status' ), meta( 813, '_ebay_export_error' ) ), array( '220000000813', 'error', 'could not ask eBay which site listing 220000000813 is on (The item could not be found.), so it was not listed again' ) );

// The shop's own eBay.com listing, settled, no site recorded: eBay says so, and
// a new listing is made as before.
fresh_shop( $ONLY_US );
linked_product( 814, '110000000814', null, $settled( '110000000814' ) );
ebay_has( '110000000814', ended( '110000000814', 'US' ) );
$EBAY['new_item'] = '110000009814';
push( 814 );
check( 'settled, no site recorded, eBay says eBay.com: a new listing, as before', array( $EBAY['wire'], $EBAY['get_items'], meta( 814, '_ebay_item_id' ), meta( 814, '_ebay_site' ) ), array( array( 'ReviseFixedPriceItem', 'AddFixedPriceItem' ), array( '110000000814' ), '110000009814', 'US' ) );
// Its site is written by the push's own save, once. The product was read
// before the push, so a write of eBay's answer straight to the database first
// would leave a second _ebay_site row beside the one the save adds.
check( '  its site written once, by the push\'s own save', count( array_filter( writes_to( 814 ), function ( $w ) { return '_ebay_site' === $w[1]; } ) ), 1 );

// The same, and eBay refuses the new listing - a category it no longer takes,
// say (fails on the 3.27.5 candidate). Each push after that asked eBay which
// site the ended listing is on again, for the answer it had just been given:
// one GetItem more on every retry, from the budget every shop shares, where
// 3.27.4 asked nothing for a listing already settled.
fresh_shop( $ONLY_US );
linked_product( 817, '110000000817', null, $settled( '110000000817' ) );
ebay_has( '110000000817', ended( '110000000817', 'US' ) );
$EBAY['add'] = new WP_Error( 'api_error', 'The category selected is not a leaf category.' );
push( 817 );
check( 'settled, no site recorded, eBay says eBay.com and refuses the new listing: asked once', array( $EBAY['wire'], $EBAY['get_items'], meta( 817, '_ebay_export_status' ) ), array( array( 'ReviseFixedPriceItem', 'AddFixedPriceItem' ), array( '110000000817' ), 'error' ) );
check( '  still on its ended listing, now recorded on eBay.com', array( meta( 817, '_ebay_item_id' ), meta( 817, '_ebay_site' ) ), array( '110000000817', 'US' ) );
push( 817 );
// Since 3.28.0 every push of an ended listing asks eBay about it once - for a
// relist made on eBay since, which a new listing would sit beside - and the
// site is judged from that same answer: never a second call for the site.
check( '  pushed again: eBay asked once, as for every push of an ended listing, not again for its site', array( $EBAY['wire'], $EBAY['get_items'] ), array( array( 'ReviseFixedPriceItem', 'AddFixedPriceItem' ), array( '110000000817' ) ) );
unset( $EBAY['add'] );
$EBAY['new_item'] = '110000009817';
push( 817 );
check( '  and once eBay takes it, the new listing, on eBay.com', array( $EBAY['wire'], $EBAY['get_items'], meta( 817, '_ebay_item_id' ), meta( 817, '_ebay_site' ), meta( 817, '_ebay_export_status' ) ), array( array( 'ReviseFixedPriceItem', 'AddFixedPriceItem' ), array( '110000000817' ), '110000009817', 'US', 'pushed' ) );
// eBay answers without an item number: nothing to link either, so the same -
// here for a variable product, which the ended-listing check never asks about.
fresh_shop( $ONLY_US );
linked_product( 818, '110000000818', null, array( '_ebay_listing_status' => 'Ended', '_stock' => 3 ) );
$POSTS[818]['product_type'] = 'variable';
ebay_has( '110000000818', ended( '110000000818', 'US' ) );
$EBAY['add'] = array( 'Ack' => 'Success' );
push( 818 );
$first = $EBAY['get_items'];
push( 818 );
check( '  a variable product, eBay giving no item number: asked once a push, recorded, still on its ended listing', array( $first, $EBAY['get_items'], meta( 818, '_ebay_site' ), meta( 818, '_ebay_item_id' ) ), array( array( '110000000818' ), array( '110000000818' ), 'US', '110000000818' ) );
// A first listing eBay refuses has no ended listing, and records nothing.
fresh_shop( array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_GB' ) );
product( 819, array( '_sku' => 'OWN-819' ) );
$EBAY['add'] = new WP_Error( 'api_error', 'The category selected is not a leaf category.' );
push( 819 );
check( '  a first listing eBay refuses: no site recorded', array( $EBAY['wire'], meta( 819, '_ebay_site' ), meta( 819, '_ebay_export_status' ) ), array( array( 'AddFixedPriceItem' ), null, 'error' ) );

// Nothing extra asked where the site is known, or where every site is imported:
// the one GetItem every push of an ended listing makes since 3.28.0, for a
// relist made on eBay since, and no more.
fresh_shop( $ONLY_US );
linked_product( 815, '110000000815', 'US', $settled( '110000000815' ) );
ebay_has( '110000000815', ended( '110000000815', 'US' ) );
$EBAY['new_item'] = '110000009815';
push( 815 );
check( 'settled, recorded on eBay.com: a new listing, with the one GetItem for a relist', array( $EBAY['wire'], $EBAY['get_items'] ), array( array( 'ReviseFixedPriceItem', 'AddFixedPriceItem' ), array( '110000000815' ) ) );
fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
linked_product( 816, '220000000816', null, $settled( '220000000816' ) );
ebay_has( '220000000816', ended( '220000000816', 'UK' ) );
$EBAY['new_item'] = '110000009816';
push( 816 );
check( 'all eBay sites: settled, no site recorded - a new listing, as in 3.27.4, with the one GetItem for a relist', array( $EBAY['wire'], $EBAY['get_items'] ), array( array( 'ReviseFixedPriceItem', 'AddFixedPriceItem' ), array( '220000000816' ) ) );

// 3.28.0's push follows a relist eBay names, and revises it when it is live.
// A copy whose relist Web Interpret made on its own site, nothing recorded:
// the site decides first, and the copy's relist is neither followed nor
// revised from the shop's product.
fresh_shop( $ONLY_US );
linked_product( 831, '220000000831', null, $settled( '220000000831' ) );
ebay_has( '220000000831', array_replace_recursive( ended( '220000000831', 'UK' ), array( 'ListingDetails' => array( 'RelistedItemID' => '220000000832' ) ) ) );
ebay_has( '220000000832', listing( '220000000832', 'UK', array( 'SKU' => 'SKU-831' ) ) );
push( 831 );
check( 'an ended copy relisted on its own site, pushed: not moved on to the relist, nor the relist revised', array( $EBAY['wire'], $EBAY['get_items'], meta( 831, '_ebay_item_id' ) ), array( array( 'ReviseFixedPriceItem' ), array( '220000000831' ), '220000000831' ) );
check( '  refused for its site, and the site recorded', array( meta( 831, '_ebay_export_error' ), meta( 831, '_ebay_site' ) ), array( $UK_REFUSAL, 'UK' ) );
// The shop's own site: followed and revised, as 3.28.0 does it.
fresh_shop( $ONLY_US );
linked_product( 832, '110000000832', null, $settled( '110000000832' ) );
ebay_has( '110000000832', array_replace_recursive( ended( '110000000832', 'US' ), array( 'ListingDetails' => array( 'RelistedItemID' => '110000000833' ) ) ) );
ebay_has( '110000000833', listing( '110000000833', 'US', array( 'SKU' => 'SKU-832' ) ) );
push( 832 );
check( '  its own site\'s: moved on to the live relist, which the push revises', array( meta( 832, '_ebay_item_id' ), $EBAY['wire'] ), array( '110000000833', array( 'ReviseFixedPriceItem', 'ReviseFixedPriceItem' ) ) );

echo "\nA COPY REVISED ONCE BY PUSH IS NEVER RELISTED ON ITS OWN SITE (FAILS ON THE SECOND 3.27.5 BUILD)\n" . str_repeat( '=', 142 ) . "\n";

// A live eBay.co.uk copy, pushed once from the Listings screen - select all,
// Push. The revise succeeds, and writes what every successful push writes, so
// the product counts as the shop's own from then on: Stock Review keeps it, as
// it keeps anything in doubt. The second build let Relist and Auto-relist spare
// such products too, so when the copy ended it went back up on eBay.co.uk,
// beside the one Web Interpret makes.
fresh_shop( $ONLY_US );
linked_product( 821, '220000000821', 'UK', array( '_stock' => 3, '_regular_price' => '19.99' ) );
$EBAY['revise'] = array( 'Ack' => 'Success', 'ItemID' => '220000000821' );
push( 821 );
unset( $EBAY['revise'] );
check( 'a live eBay.co.uk copy revised once by Push', array( meta( 821, '_ebay_export_status' ), '' !== (string) meta( 821, '_ebay_export_last_pushed' ), meta( 821, '_ebay_site' ) ), array( 'pushed', true, 'UK' ) );
check( '  counts as the shop\'s own, so Stock Review keeps it', array( TCGiant_Sync_Sites::is_shops_own( 821 ), TCGiant_Sync_Sites::find_other_site_products() ), array( true, array() ) );
$GLOBALS['META'][821]['_ebay_listing_status'] = 'Ended';
ebay_has( '220000000821', ended( '220000000821', 'UK' ) );
check( '  then it ends: Relist refuses it, by its recorded site', relist_outcome( relist( 821 ) ), 'relist_refused: ' . $UK_REFUSAL );
check( '  before eBay is asked anything', $EBAY['get_items'], array() );
push( 821 );
check( '  and so does Push, as before', array( $EBAY['wire'], meta( 821, '_ebay_export_error' ) ), array( array( 'ReviseFixedPriceItem' ), $UK_REFUSAL ) );
check( '  auto-relist\'s query leaves it out with the copies - tests/test-site-sql.php runs it', false !== strpos( TCGiant_Sync_Sites::sql_keeps_out_other_sites( 'l.product_id' ), 'tcg_own' ), false );

// Revised before its site was known: eBay's answer, which Relist asks for,
// decides.
fresh_shop( $ONLY_US );
linked_product( 822, '220000000822', null, array( '_stock' => 3 ) );
$EBAY['revise'] = array( 'Ack' => 'Success', 'ItemID' => '220000000822' );
push( 822 );
unset( $EBAY['revise'] );
$GLOBALS['META'][822]['_ebay_listing_status'] = 'Ended';
ebay_has( '220000000822', ended( '220000000822', 'UK' ) );
check( 'revised with no site recorded, then ended: Relist refuses it on eBay\'s answer', array( relist_outcome( relist( 822 ) ), $EBAY['get_items'], meta( 822, '_ebay_site' ) ), array( 'relist_refused: ' . $UK_REFUSAL, array( '220000000822' ), 'UK' ) );

echo "\nA PRODUCT THE PRUNER KEEPS, THEN LINKED BY HAND, TAKES THE SITE OF THE LISTING IT IS LINKED TO (FAILS ON THE FIRST 3.27.5 BUILD)\n" . str_repeat( '=', 142 ) . "\n";

function link_by_hand( $product_id, $item_id ) {
	$_POST = array( 'product_id' => $product_id, 'ebay_item_id' => $item_id );
	try {
		$GLOBALS['ADMIN']->ajax_link_ebay();
	} catch ( Harness_Sent $sent ) {
		return $sent->payload;
	}
	return null;
}
/** Back in stock, as the shop restocks a product the pruner set to 0. */
function restock( $id, $n = 3 ) {
	$GLOBALS['META'][ $id ]['_stock']        = $n;
	$GLOBALS['META'][ $id ]['_stock_status'] = 'instock';
}
/** GetItem's answer for a listing eBay no longer has: error 17. */
function gone_from_ebay() {
	return new WP_Error( 'api_error', 'This item cannot be accessed because the listing has been deleted, is a Half.com listing, or you are not the seller.', array( 'Ack' => 'Failure', 'Errors' => array( 'ShortMessage' => 'Item cannot be accessed.', 'ErrorCode' => '17' ) ) );
}
function prune_with_active( array $active ) {
	update_option( TCGiant_Sync_Importer::ACTIVE_IDS_OPTION, $active );
	update_option( TCGiant_Sync_Importer::STATE_OPTION, array_merge( state(), array( 'scan_complete_clean' => true ) ) );
	$GLOBALS['IMP']->prune_orphaned_items();
}
/** Nine products on the shop's own live eBay.com listings, so the pruner's safety ratio lets one go. */
function nine_us_products() {
	$active = array();
	for ( $i = 1; $i <= 9; $i++ ) {
		$item = '11000000081' . $i;
		linked_product( 810 + $i, $item, 'US' );
		$active[] = $item;
	}
	return $active;
}

// From a Web Interpret copy, kept in a Preserve Category. The copy ends and
// leaves eBay, so the next clean full sync prunes it: kept, at 0, its item
// number gone - and, in the first build, eBay.co.uk still recorded on it.
fresh_shop( array_merge( $ONLY_US, array( 'preserve_woo_category_ids' => array( '77' ) ) ) );
$active = nine_us_products();
linked_product( 821, '220000000821', 'UK' );
$TERMS[821] = array( 77 );
// Gone from eBay, as eBay says when the pruner asks (since 3.27.6, before it
// retires anything).
ebay_has( '220000000821', gone_from_ebay() );
prune_with_active( $active );
// 3.27.6 unlinked a preserved product it retired, and dropped its site with
// it. Since 3.28.0 the pruner never trashes, zeroes or unlinks: a listing eBay
// no longer has is marked ended, the product kept as it is, on that listing -
// so its site is still the site of the listing it is on.
check( 'the pruner keeps it, marked ended, on the listing it was on', array( status_of( 821 ), meta( 821, '_ebay_item_id' ), meta( 821, '_ebay_listing_status' ) ), array( 'publish', '220000000821', 'Ended' ) );
check( '  and that listing\'s site with it', meta( 821, '_ebay_site' ), 'UK' );
// With no item number its eBay panel offers "Link to this listing", and the
// shop links it to its own eBay.com listing.
ebay_has( '110000000829', listing( '110000000829', 'US' ) );
$sent = link_by_hand( 821, '110000000829' );
check( 'linked by hand to the eBay.com listing', array( $sent['success'] ?? null, meta( 821, '_ebay_item_id' ) ), array( true, '110000000829' ) );
check( '  recorded on the site eBay just gave', meta( 821, '_ebay_site' ), 'US' );
check( '  so Stock Review does not offer it as another site\'s', TCGiant_Sync_Sites::find_other_site_products(), array() );
restock( 821 );
$GLOBALS['META'][821]['_ebay_listing_status'] = 'Ended';
ebay_has( '110000000829', ended( '110000000829', 'US' ) );
check( '  and when that listing ends, Relist puts it back up', relist_outcome( relist( 821 ) ), 'sent to eBay' );

// The same, the other way: an eBay.com product, kept, linked by hand to an
// ended eBay.co.uk listing. The first build kept "US", and Relist sent
// RelistFixedPriceItem for the eBay.co.uk listing.
fresh_shop( array_merge( $ONLY_US, array( 'preserve_woo_category_ids' => array( '77' ) ) ) );
$active = nine_us_products();
linked_product( 822, '110000000822', 'US' );
$TERMS[822] = array( 77 );
ebay_has( '110000000822', gone_from_ebay() );
prune_with_active( $active );
ebay_has( '220000000822', ended( '220000000822', 'UK' ) );
link_by_hand( 822, '220000000822' );
restock( 822 );
check( 'linked by hand to an ended eBay.co.uk listing: recorded as eBay.co.uk', meta( 822, '_ebay_site' ), 'UK' );
check( '  and Relist refuses it', relist_outcome( relist( 822 ) ), 'relist_refused: ' . $UK_REFUSAL );
// eBay's answer without a site: nothing left behind from the last listing.
fresh_shop( $ONLY_US );
linked_product( 823, '220000000823', 'UK' );
unset( $GLOBALS['META'][823]['_ebay_item_id'] );
ebay_has( '110000000823', listing( '110000000823', null ) );
link_by_hand( 823, '110000000823' );
check( 'linked to a listing eBay gives no site for: the old site is not kept', meta( 823, '_ebay_site' ), null );

echo "\nTHE SETTING, AND WHAT TELLS A SHOP ABOUT IT\n" . str_repeat( '=', 142 ) . "\n";

fresh_shop( array( 'marketplace' => 'EBAY_US' ) );
foreach ( array( 'primary' => 'primary', 'all' => 'all', 'everything' => 'all', '' => 'all', 'Primary' => 'all' ) as $posted => $stored ) {
	check( sprintf( 'saved as "%s": stored as "%s"', $posted, $stored ), $ADMIN->sanitize_settings( array( 'marketplace' => 'EBAY_US', 'import_sites' => $posted ) )['import_sites'] ?? null, $stored );
}
check( 'a form without the field writes nothing for it - no upgrade step, no default stored', array_key_exists( 'import_sites', $ADMIN->sanitize_settings( array( 'marketplace' => 'EBAY_US' ) ) ), false );

/** The plugin's notice about eBay sites, on the given screen. */
function sites_notice( $screen = 'toplevel_page_tcgiant-sync' ) {
	$GLOBALS['SCREEN'] = $screen;
	return render( function () { $GLOBALS['ADMIN']->other_sites_notice(); } );
}

$OPTIONS[ TCGiant_Sync_Sites::SEEN_OPTION ] = array( 'US' => time(), 'UK' => time() );
$shown = sites_notice();
check( 'listings seen on eBay.com and eBay.co.uk, every site imported: the shop is told', false !== strpos( $shown, 'Your eBay account has listings on more than one eBay site (eBay United Kingdom, eBay United States). All eBay sites are imported. To import only one site&#039;s listings, go to TCGiant Sync, Settings, Import listings from.' ), true );
check( '  with a way not to be told again', false !== strpos( $shown, 'action=tcgiant_dismiss_sites_notice' ), true );
check( '  on the plugin\'s own pages only', sites_notice( 'edit-post' ), '' );
$CAN = false;
check( '  and only to someone who can change settings', sites_notice(), '' );
$CAN = true;
$OPTIONS[ TCGiant_Sync_Sites::SEEN_OPTION ] = array( 'US' => time(), 'eBayMotors' => time() );
check( 'eBay.com and eBay Motors only: one site, no notice', sites_notice(), '' );
$OPTIONS[ TCGiant_Sync_Sites::SEEN_OPTION ] = array( 'US' => time(), 'UK' => time() );
$OPTIONS['tcgiant_sync_ebay_settings'] = $ONLY_US;
check( 'already importing only eBay United States: no notice', sites_notice(), '' );
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'marketplace' => 'EBAY_US' );
try {
	$ADMIN->handle_dismiss_sites_notice();
	$back = null;
} catch ( Harness_Sent $sent ) {
	$back = $sent->payload['redirect'] ?? null;
}
check( 'dismissed: back to the page it was on', $back, wp_get_referer() );
check( '  and not shown again', sites_notice(), '' );
$OPTIONS[ TCGiant_Sync_Sites::SEEN_OPTION ]['Germany'] = time();
check( 'until a site appears that was not there when it was dismissed', false !== strpos( sites_notice(), '(eBay Germany, eBay United Kingdom, eBay United States)' ), true );

echo "\nTHE PIECES THE REST DEPENDS ON\n" . str_repeat( '=', 142 ) . "\n";

// The delta's rows kept in brief carry the site, and only when eBay gave one.
$compact = call_private( 'TCGiant_Sync_Importer', 'compact_delta_item', event( '530000000001', 'UK' ) );
check( 'a row kept in brief keeps its site', $compact['Site'] ?? null, 'UK' );
$compact = call_private( 'TCGiant_Sync_Importer', 'compact_delta_item', event( '530000000002', null ) );
check( '  and has none when eBay gave none', array_key_exists( 'Site', $compact ), false );

// A copy of a product, or one unlinked from its listing, has no site.
check( 'the site is among the keys a duplicate or an unlink drops', in_array( '_ebay_site', TCGiant_Sync_Listing_Link::listing_identity_meta_keys(), true ), true );

// Saving one settings form keeps everything the other one owns. The Export
// form dropped the Standard Categories import filter (and image hosting,
// image size and log detail), and the Import form dropped Best Offer and the
// GPSR fields: each form replaces the whole option.
$view = (string) file_get_contents( $ROOT . '/admin/views/settings.php' );
$cut  = strpos( $view, '<!-- Push to eBay Settings -->' );
preg_match_all( '/tcgiant_sync_ebay_settings\[([a-z_]+)\]/', substr( $view, 0, $cut ), $m );
$import_fields = array_values( array_unique( $m[1] ) );
preg_match_all( '/tcgiant_sync_ebay_settings\[([a-z_]+)\]/', substr( $view, $cut ), $m );
$export_fields = array_values( array_unique( $m[1] ) );
// 3.28.0: a form no longer carries the other's fields back. A save from one
// changes only its own settings (TCGiant_Sync_Admin::SETTINGS_FORMS) and keeps
// every other as stored - so none of one form's fields may be the other's.
$own_import = TCGiant_Sync_Admin::SETTINGS_FORMS['import'];
$own_export = TCGiant_Sync_Admin::SETTINGS_FORMS['export'];
check( 'the Export Settings form keeps every Import Settings field', array( array_values( array_diff( $import_fields, $own_import ) ), array_values( array_intersect( $import_fields, $own_export ) ) ), array( array(), array() ) );
check( '  Import listings from among them', array( in_array( 'import_sites', $own_import, true ), in_array( 'import_sites', $own_export, true ) ), array( true, false ) );
check( '  and the Standard Categories import filter', array( in_array( 'import_standard_category_ids', $own_import, true ), in_array( 'import_standard_category_ids', $own_export, true ) ), array( true, false ) );
check( 'the Import Settings form keeps every Export Settings field', array( array_values( array_diff( $export_fields, $own_export ) ), array_values( array_intersect( $export_fields, $own_import ) ) ), array( array(), array() ) );
check( 'the field is in the Import Settings form', in_array( 'import_sites', $import_fields, true ), true );

// The wizard cannot be reached, so it is not where the setting lives.
check( 'the setup wizard is left as it was', false === strpos( (string) file_get_contents( $ROOT . '/admin/views/wizard.php' ), 'import_sites' ), true );

// The cleanup never deletes for good, whatever path reaches it.
$src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-sites.php' );
check( 'nothing in the site filter deletes a product permanently', false === strpos( $src, 'wp_delete_post' ), true );

echo "\nTHE TRASH SWITCHED OFF: NOTHING IS DELETED FOR GOOD (FAILS ON THE FIRST 3.27.5 BUILD)\n" . str_repeat( '=', 142 ) . "\n";

// Last, because a constant cannot be taken back. define( 'EMPTY_TRASH_DAYS', 0 )
// in wp-config.php switches the trash off, and then wp_trash_post() deletes
// for good - WordPress core's first line, played above. The first build's
// cleanup did that to every product on its list, and its log said "moved to
// the trash" of each.
fresh_shop( $ONLY_US );
linked_product( 951, '531000000001', 'UK' );
linked_product( 952, '531000000002', 'UK' );
$running = start_job( array( 'type' => 'bulk_trash_other_sites', 'product_ids' => array( 951, 952 ) ) );
$open    = stock_review();
define( 'EMPTY_TRASH_DAYS', 0 );
$html = stock_review();
check( 'Stock Review no longer promises the trash', false !== strpos( $html, 'They go to the trash, not away for good.' ), false );
check( '  but says why it removes nothing', false !== strpos( $html, 'The trash is switched off on this website (EMPTY_TRASH_DAYS is 0), so WordPress would delete these products for good instead of moving them to the trash. This page therefore does not remove them.' ), true );
check( '  with no button', false !== strpos( $html, 'id="tc-trash-other-sites"' ), false );
check( '  and still lists them, each "left here"', array( false !== strpos( $html, 'Product #951<' ), substr_count( $html, 'Left here - the trash is switched off' ) ), array( true, 2 ) );
$TRASH_OFF = 'The trash is switched off on this website (EMPTY_TRASH_DAYS is 0), so WordPress would delete these products for good instead of moving them to the trash. Nothing was moved.';
$started = press_trash_button( $open );
check( 'a page drawn while the trash was on: its button is refused, saying why', array( $started['success'] ?? null, $started['data']['message'] ?? null ), array( false, $TRASH_OFF ) );
$started = start_job( array( 'type' => 'bulk_trash_other_sites', 'product_ids' => array( 951 ) ) );
check( '  and a job asked for by ID', array( $started['success'] ?? null, $started['data']['message'] ?? null ), array( false, $TRASH_OFF ) );
$done = run_job( $running['data']['job_id'] ?? '' );
check( 'a job already running when it is switched off: both kept, none moved', array( $done['data']['kept'] ?? null, $done['data']['succeeded'] ?? null ), array( 2, 0 ) );
check( '  nothing deleted for good', array( status_of( 951 ), status_of( 952 ), $DELETED ), array( 'publish', 'publish', array() ) );
check( '  the log says why, once, and never that one moved', array( log_lines_naming( 'Other-site cleanup: stopped - the trash is switched off on this website (EMPTY_TRASH_DAYS is 0)' ), logged( 'moved to the trash.' ) ), array( 1, false ) );
printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
