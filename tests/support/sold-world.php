<?php
/**
 * The world the Listings screen's views and the Stock Review lists run in,
 * for test-sold-view.php and test-sold-jobs.php.
 *
 * Posts, post meta, categories, the listings table and WooCommerce's product
 * lookup table live in SQLite, with WordPress's own shapes, so the plugin's
 * SQL really runs: every count, filter and list is the database's answer,
 * not a reading of the query. WordPress and WooCommerce are played here; the
 * harnesses load the plugin's own classes. Without PHP's sqlite3 extension
 * the harnesses say so and report nothing.
 *
 * @package TCGiant_Sync
 */

if ( ! class_exists( 'SQLite3' ) ) {
	echo "\n  PHP's sqlite3 extension is not loaded: these cases need it, and are skipped.\n\n  0 passed, 0 failed\n\n";
	exit( 0 );
}

$ROOT = dirname( __DIR__, 2 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'TCGIANT_SYNC_PATH', $ROOT . '/' );

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-86s got %-18s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

/**
 * $wpdb, on SQLite. prepare() quotes as WordPress does; SHOW TABLES and SHOW
 * COLUMNS are answered from SQLite's own catalogue; an SQL error is printed
 * and kept, never read as "no rows".
 */
class Sold_Wpdb {
	public $prefix             = 'wp_';
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $options            = 'wp_options';
	public $term_relationships = 'wp_term_relationships';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $insert_id          = 0;
	public $errors             = array();
	public $queries            = array();
	public $db;
	public function __construct() { $this->reset(); }
	public function reset() {
		$this->db = new SQLite3( ':memory:' );
		$this->db->exec( "CREATE TABLE wp_posts ( ID INTEGER PRIMARY KEY, post_type TEXT NOT NULL DEFAULT 'product', post_status TEXT NOT NULL DEFAULT 'publish', post_title TEXT NOT NULL DEFAULT '', post_parent INTEGER NOT NULL DEFAULT 0 )" );
		$this->db->exec( 'CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT, meta_value TEXT )' );
		// WordPress's own indexes on it: post_id, and meta_key.
		$this->db->exec( 'CREATE INDEX post_id ON wp_postmeta ( post_id )' );
		$this->db->exec( 'CREATE INDEX meta_key ON wp_postmeta ( meta_key )' );
		$this->db->exec( 'CREATE TABLE wp_term_taxonomy ( term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT )' );
		$this->db->exec( 'CREATE TABLE wp_term_relationships ( object_id INTEGER, term_taxonomy_id INTEGER )' );
		// The plugin's listings table, as maybe_create_table() makes it.
		$this->db->exec( "CREATE TABLE wp_tcgiant_listings ( id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL UNIQUE, ebay_item_id TEXT NOT NULL DEFAULT '', listing_type TEXT NOT NULL DEFAULT 'FixedPriceItem', listing_status TEXT NOT NULL DEFAULT 'Active', ebay_price REAL NOT NULL DEFAULT 0, ebay_quantity INTEGER NOT NULL DEFAULT 0, ebay_url TEXT NOT NULL DEFAULT '', ebay_title TEXT NOT NULL DEFAULT '', ebay_start_time TEXT NOT NULL DEFAULT '', ebay_end_time TEXT NOT NULL DEFAULT '', last_synced TEXT, last_pushed TEXT, variation_cache TEXT, sync_error TEXT, created_at TEXT NOT NULL DEFAULT '2026-10-01 00:00:00', updated_at TEXT NOT NULL DEFAULT '2026-10-01 00:00:00' )" );
		// WooCommerce's product lookup table, the columns the plugin reads.
		$this->db->exec( 'CREATE TABLE wp_wc_product_meta_lookup ( product_id INTEGER PRIMARY KEY, stock_quantity REAL, min_price REAL, stock_status TEXT )' );
		$this->errors  = array();
		$this->queries = array();
	}
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0; $db = $this->db;
		return preg_replace_callback( '/%%|%[sdf]/', function ( $m ) use ( &$i, $args, $db ) {
			if ( '%%' === $m[0] ) { return '%'; }
			$v = $args[ $i++ ] ?? '';
			if ( '%d' === $m[0] ) { return (string) (int) $v; }
			if ( '%f' === $m[0] ) { return (string) (float) $v; }
			return "'" . $db->escapeString( (string) $v ) . "'";
		}, $query );
	}
	private function rows( $query ) {
		$this->queries[] = $query;
		if ( preg_match( "/^\s*SHOW TABLES LIKE '([^']+)'/", $query, $m ) ) {
			$found = $this->db->querySingle( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $this->db->escapeString( $m[1] ) . "'" );
			return $found ? array( array( 'Tables' => $found ) ) : array();
		}
		if ( preg_match( '/^\s*SHOW COLUMNS FROM (\w+)/', $query, $m ) ) {
			$res = $this->db->query( 'PRAGMA table_info(' . $m[1] . ')' );
			$out = array();
			while ( $r = $res->fetchArray( SQLITE3_ASSOC ) ) { $out[] = array( 'Field' => $r['name'] ); }
			return $out;
		}
		$res = @$this->db->query( $query ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $res ) {
			$this->errors[] = $this->db->lastErrorMsg();
			echo '  SQL ERROR: ' . $this->db->lastErrorMsg() . "\n  in: " . preg_replace( '/\s+/', ' ', $query ) . "\n";
			return array();
		}
		$out = array();
		while ( $r = $res->fetchArray( SQLITE3_ASSOC ) ) {
			$out[] = array_map( function ( $v ) { return null === $v ? null : (string) $v; }, $r );
		}
		return $out;
	}
	public function get_results( $q, $o = OBJECT ) { $r = $this->rows( $q ); return ARRAY_A === $o ? $r : array_map( function ( $x ) { return (object) $x; }, $r ); }
	public function get_row( $q, $o = OBJECT ) { $r = $this->rows( $q ); if ( ! $r ) { return null; } return ARRAY_A === $o ? $r[0] : (object) $r[0]; }
	public function get_var( $q ) { $r = $this->rows( $q ); if ( ! $r ) { return null; } $f = reset( $r[0] ); return null === $f ? null : (string) $f; }
	public function get_col( $q ) { return array_map( function ( $r ) { return reset( $r ); }, $this->rows( $q ) ); }
	public function query( $q ) { $this->queries[] = $q; return @$this->db->exec( $q ) ? $this->db->changes() : false; }
	public function update( $table, array $data, array $where ) {
		$set = array(); foreach ( $data as $k => $v ) { $set[] = $k . ' = ' . ( null === $v ? 'NULL' : "'" . $this->db->escapeString( (string) $v ) . "'" ); }
		$wh  = array(); foreach ( $where as $k => $v ) { $wh[] = $k . " = '" . $this->db->escapeString( (string) $v ) . "'"; }
		return $this->query( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $wh ) );
	}
	public function insert( $table, array $data ) {
		$vals = array_map( function ( $v ) { return null === $v ? 'NULL' : "'" . $this->db->escapeString( (string) $v ) . "'"; }, array_values( $data ) );
		$ok = $this->query( "INSERT INTO {$table} ( " . implode( ', ', array_keys( $data ) ) . ' ) VALUES ( ' . implode( ', ', $vals ) . ' )' );
		$this->insert_id = (int) $this->db->lastInsertRowID();
		return $ok;
	}
	public function delete( $table, array $where ) {
		$wh = array(); foreach ( $where as $k => $v ) { $wh[] = $k . " = '" . $this->db->escapeString( (string) $v ) . "'"; }
		return $this->query( "DELETE FROM {$table} WHERE " . implode( ' AND ', $wh ) );
	}
	public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }
	public function get_charset_collate() { return ''; }
}
$wpdb = new Sold_Wpdb();

// WordPress, as far as these paths read it.
$OPTIONS    = array();
$TRANSIENTS = array();
$EVENTS     = array();
function __( $t, $d = '' ) { return $t; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return (string) $t; }
function esc_js( $t ) { return addslashes( (string) $t ); }
function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
function esc_attr__( $t, $d = '' ) { return esc_attr( $t ); }
function esc_html_e( $t, $d = '' ) { echo esc_html( $t ); }
function esc_attr_e( $t, $d = '' ) { echo esc_attr( $t ); }
function add_action() { return true; }
function add_filter() { return true; }
function do_action() {}
function apply_filters( $hook, $value ) { return $value; }
function current_time( $type, $gmt = 0 ) { return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ); }
function get_gmt_from_date( $d, $f = 'Y-m-d H:i:s' ) { return gmdate( $f, strtotime( $d ) ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function wp_unslash( $v ) { return $v; }
function wp_list_pluck( $list, $field ) { return array_map( function ( $r ) use ( $field ) { return is_array( $r ) ? $r[ $field ] : $r->$field; }, (array) $list ); }
function update_postmeta_cache( $ids ) { $GLOBALS['EVENTS'][] = 'meta cache ' . implode( ',', $ids ); return true; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function wp_date( $f, $ts = null ) { return gmdate( 'Y-m-d H:i', (int) $ts ); }
function date_i18n( $f, $ts = null ) { return gmdate( 'Y-m-d', (int) $ts ); }
function human_time_diff( $a, $b = 0 ) { return '1 hour'; }
function admin_url( $p = '' ) { return 'https://shop.example/wp-admin/' . $p; }
function add_query_arg( $k, $v = null, $url = null ) {
	if ( is_array( $k ) ) { $url = $v; $args = $k; } else { $args = array( $k => $v ); }
	$url = null === $url ? 'https://shop.example/wp-admin/admin.php?page=tcgiant-listings' : (string) $url;
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}
function selected( $a, $b, $echo = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function wp_nonce_field( $a = '' ) { echo '<input type="hidden" name="_wpnonce" value="nonce" />'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wc_price( $p ) { return '$' . number_format( (float) $p, 2 ); }
function get_the_title( $id ) { global $wpdb; return (string) $wpdb->db->querySingle( 'SELECT post_title FROM wp_posts WHERE ID = ' . (int) $id ); }
function get_edit_post_link( $id ) { return 'https://shop.example/wp-admin/post.php?post=' . (int) $id; }
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['OPTIONS'][ $n ] = $v; return true; }
function add_option( $n, $v = '', $dep = '', $a = 'yes' ) { if ( array_key_exists( $n, $GLOBALS['OPTIONS'] ) ) { return false; } $GLOBALS['OPTIONS'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['OPTIONS'][ $n ] ); return true; }
function get_transient( $k ) { return $GLOBALS['TRANSIENTS'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['TRANSIENTS'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['TRANSIENTS'][ $k ] ); return true; }
function wp_generate_uuid4() { static $n = 0; return 'job-' . ( ++$n ); }
function wp_cache_delete() { return true; }

// Post meta, posts and categories, in SQLite.
function get_post_meta( $id, $key = '', $single = false ) {
	global $wpdb;
	$v = $wpdb->db->querySingle( sprintf( "SELECT meta_value FROM wp_postmeta WHERE post_id = %d AND meta_key = '%s' ORDER BY meta_id ASC LIMIT 1", (int) $id, SQLite3::escapeString( $key ) ) );
	if ( is_string( $v ) && 0 === strpos( $v, 'a:' ) && false !== ( $u = @unserialize( $v ) ) ) { $v = $u; }
	return null === $v ? ( $single ? '' : array() ) : ( $single ? $v : array( $v ) );
}
function update_post_meta( $id, $key, $value ) {
	global $wpdb;
	$GLOBALS['EVENTS'][] = sprintf( 'meta %d %s=%s', $id, $key, is_scalar( $value ) ? $value : json_encode( $value ) );
	$wpdb->db->exec( sprintf( "DELETE FROM wp_postmeta WHERE post_id = %d AND meta_key = '%s'", (int) $id, SQLite3::escapeString( $key ) ) );
	$wpdb->db->exec( sprintf( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( %d, '%s', '%s' )", (int) $id, SQLite3::escapeString( $key ), SQLite3::escapeString( is_array( $value ) ? serialize( $value ) : (string) $value ) ) );
	// updated_post_meta: the plugin's own mirror of the listing columns.
	static $db = null;
	if ( null === $db && class_exists( 'TCGiant_Sync_DB' ) ) {
		$db = ( new ReflectionClass( 'TCGiant_Sync_DB' ) )->newInstanceWithoutConstructor();
	}
	if ( $db ) {
		$db->mirror_meta_to_row( 0, (int) $id, $key, $value );
	}
	return true;
}
function delete_post_meta( $id, $key ) {
	global $wpdb;
	$GLOBALS['EVENTS'][] = sprintf( 'unmeta %d %s', $id, $key );
	$wpdb->db->exec( sprintf( "DELETE FROM wp_postmeta WHERE post_id = %d AND meta_key = '%s'", (int) $id, SQLite3::escapeString( $key ) ) );
	return true;
}
function get_post_status( $id ) { global $wpdb; $s = $wpdb->db->querySingle( 'SELECT post_status FROM wp_posts WHERE ID = ' . (int) $id ); return $s ? $s : false; }
function get_post_type( $id ) { global $wpdb; $s = $wpdb->db->querySingle( 'SELECT post_type FROM wp_posts WHERE ID = ' . (int) $id ); return $s ? $s : false; }
function wp_trash_post( $id ) {
	global $wpdb;
	$GLOBALS['EVENTS'][] = 'trash ' . (int) $id;
	$wpdb->db->exec( "UPDATE wp_posts SET post_status = 'trash' WHERE ID = " . (int) $id );
	return true;
}
function has_term( $terms, $tax, $id ) {
	global $wpdb;
	$terms = array_map( 'intval', (array) $terms );
	if ( ! $terms ) { return false; }
	return (bool) $wpdb->db->querySingle( sprintf( "SELECT 1 FROM wp_term_relationships tr INNER JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = '%s' AND tt.term_id IN ( %s )", (int) $id, SQLite3::escapeString( $tax ), implode( ',', $terms ) ) );
}

// WooCommerce's product, reading its meta afresh.
class Sold_Product {
	private $id;
	public function __construct( $id ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_name() { return get_the_title( $this->id ); }
	public function managing_stock() { return 'yes' === get_post_meta( $this->id, '_manage_stock', true ); }
	public function get_stock_quantity() { return $this->managing_stock() ? (int) get_post_meta( $this->id, '_stock', true ) : null; }
	public function get_stock_status() { return get_post_meta( $this->id, '_stock_status', true ) ?: 'instock'; }
	public function is_in_stock() { return 'outofstock' !== $this->get_stock_status(); }
	public function is_type( $t ) { return ( 'variable' === get_post_meta( $this->id, '_type', true ) ? 'variable' : 'simple' ) === $t; }
	public function get_meta( $k, $s = true ) { return get_post_meta( $this->id, $k, true ); }
	public function get_price() { return (string) get_post_meta( $this->id, '_price', true ); }
	public function get_children() { return array(); }
}
function wc_get_product( $id ) { return get_post_status( $id ) ? new Sold_Product( $id ) : false; }
/** WooCommerce writes the stock, its status and its lookup row together. */
function wc_update_product_stock( $product, $qty = null, $op = 'set' ) {
	$id = is_object( $product ) ? $product->get_id() : (int) $product;
	$GLOBALS['EVENTS'][] = sprintf( 'stock %d %s %d', $id, $op, $qty );
	update_post_meta( $id, '_stock', (int) $qty );
	wc_update_product_stock_status( $id, (int) $qty > 0 ? 'instock' : 'outofstock' );
	return (int) $qty;
}
function wc_update_product_stock_status( $id, $status ) {
	global $wpdb;
	update_post_meta( (int) $id, '_stock_status', $status );
	$wpdb->db->exec( sprintf( "UPDATE wp_wc_product_meta_lookup SET stock_status = '%s' WHERE product_id = %d", SQLite3::escapeString( $status ), (int) $id ) );
}

/**
 * A product and, unless $row is false, its listings-table row and lookup
 * row. $meta is its post meta; _stock_status also goes to the lookup row
 * unless $lookup is false (WooCommerce has no row for it).
 */
function sold_product( $id, array $meta, $row = array(), $status = 'publish', $lookup = true, $type = 'product' ) {
	global $wpdb;
	$wpdb->db->exec( sprintf( "INSERT INTO wp_posts ( ID, post_type, post_status, post_title ) VALUES ( %d, '%s', '%s', '%s' )", $id, $type, $status, 'Coin ' . $id ) );
	foreach ( $meta as $key => $value ) {
		$wpdb->db->exec( sprintf( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( %d, '%s', '%s' )", $id, SQLite3::escapeString( $key ), SQLite3::escapeString( is_array( $value ) ? serialize( $value ) : (string) $value ) ) );
	}
	if ( false !== $row ) {
		$row = array_merge( array(
			'product_id'     => $id,
			'ebay_item_id'   => (string) ( $meta['_ebay_item_id'] ?? '' ),
			'listing_status' => (string) ( $meta['_ebay_listing_status'] ?? 'Active' ),
			'ebay_title'     => 'Coin ' . $id,
		), $row );
		$wpdb->insert( 'wp_tcgiant_listings', $row );
	}
	if ( $lookup && isset( $meta['_stock_status'] ) ) {
		$wpdb->db->exec( sprintf( "INSERT INTO wp_wc_product_meta_lookup ( product_id, stock_quantity, min_price, stock_status ) VALUES ( %d, %s, 10, '%s' )", $id, isset( $meta['_stock'] ) ? (int) $meta['_stock'] : 'NULL', SQLite3::escapeString( (string) $meta['_stock_status'] ) ) );
	}
}
/** A product category, and the products in it. */
function sold_category( $term_id, array $ids ) {
	global $wpdb;
	$wpdb->db->exec( sprintf( "INSERT OR IGNORE INTO wp_term_taxonomy VALUES ( %d, %d, 'product_cat' )", 100 + $term_id, $term_id ) );
	foreach ( $ids as $id ) {
		$wpdb->db->exec( sprintf( 'INSERT INTO wp_term_relationships VALUES ( %d, %d )', $id, 100 + $term_id ) );
	}
}
/** Forget what TCGiant_Sync_DB cached about the tables this request. */
function sold_forget_tables() {
	foreach ( array( 'table_exists', 'lookup_table', 'columns' ) as $prop ) {
		$p = new ReflectionProperty( 'TCGiant_Sync_DB', $prop );
		if ( PHP_VERSION_ID < 80100 ) { $p->setAccessible( true ); }
		$p->setValue( null, null );
	}
}
