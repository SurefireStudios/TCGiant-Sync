<?php
/**
 * Harness: the SQL behind "Import listings from", run by a real SQL engine.
 *
 * test-other-sites.php and test-relist-guard.php play the database: they
 * answer each query from the products by what its SQL says, so they cannot
 * see a join on the wrong column, a clause left out, or a parenthesis that
 * does not close. On MySQL that last one makes get_results() fail, and
 * auto-relist then logs "No ended listings found" for a shop with fifty to
 * relist. Here the same queries - TCGiant_Sync_Sites' list of other sites'
 * products, its count by site, its count of those in a preserved category,
 * its count of products not checked yet, and both of auto-relist's queries
 * with the clause that keeps other sites' products out - run against posts,
 * post meta and categories in SQLite.
 *
 * Loaded for real: TCGiant_Sync_API (the marketplaces), TCGiant_Sync_Sites
 * and TCGiant_Sync_Cron. $wpdb is SQLite with WordPress's own table shapes.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-86s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$ROOT = dirname( __DIR__ );

if ( ! class_exists( 'SQLite3' ) ) {
	echo "\n  SQLite3 is not available to this PHP, so the SQL cannot be run. Skipped.\n";
	printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
	exit( 0 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

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
function add_action() { return true; }
$OPTIONS = array();
function get_option( $name, $default = false ) { return $GLOBALS['OPTIONS'][ $name ] ?? $default; }

/**
 * $wpdb, on SQLite. prepare() quotes as WordPress does; every query runs for
 * real, and an error is kept and printed rather than read as "no rows".
 */
class Sqlite_Wpdb {
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $term_relationships = 'wp_term_relationships';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $prefix             = 'wp_';
	public $errors             = array();
	public $queries            = array();
	private $db;
	public function __construct() {
		$this->db = new SQLite3( ':memory:' );
		$this->db->exec( 'CREATE TABLE wp_posts ( ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT )' );
		$this->db->exec( 'CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT )' );
		$this->db->exec( 'CREATE TABLE wp_term_taxonomy ( term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT )' );
		$this->db->exec( 'CREATE TABLE wp_term_relationships ( object_id INTEGER, term_taxonomy_id INTEGER )' );
		$this->db->exec( 'CREATE TABLE wp_tcgiant_sync_listings ( id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, ebay_item_id TEXT, listing_status TEXT )' );
		$this->db->exec( 'CREATE TABLE wp_wc_product_meta_lookup ( product_id INTEGER PRIMARY KEY, stock_status TEXT )' );
	}
	/** WooCommerce's stock status for a product, in its lookup table. */
	public function stock_status( $product_id, $status ) {
		$this->db->exec( $this->prepare( 'INSERT OR REPLACE INTO wp_wc_product_meta_lookup ( product_id, stock_status ) VALUES ( %d, %s )', $product_id, $status ) );
	}
	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( $args, &$i ) {
			$value = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $value : "'" . SQLite3::escapeString( (string) $value ) . "'";
		}, $query );
	}
	private function run( $query ) {
		$this->queries[] = $query;
		$result = @$this->db->query( $query ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $result ) {
			$this->errors[] = $this->db->lastErrorMsg();
			echo '  SQL ERROR: ' . $this->db->lastErrorMsg() . "\n  in: " . preg_replace( '/\s+/', ' ', $query ) . "\n";
			return array();
		}
		$rows = array();
		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			$rows[] = array_map( function ( $v ) { return null === $v ? null : (string) $v; }, $row );
		}
		return $rows;
	}
	public function get_col( $query ) { return array_map( function ( $row ) { return reset( $row ); }, $this->run( $query ) ); }
	public function get_var( $query ) { $rows = $this->run( $query ); return $rows ? reset( $rows[0] ) : null; }
	public function get_results( $query, $output = null ) {
		$rows = $this->run( $query );
		return ARRAY_A === $output ? $rows : array_map( function ( $row ) { return (object) $row; }, $rows );
	}
	/** One meta value, read without being counted among the queries asked. */
	public function meta( $id, $key ) {
		$v = $this->db->querySingle( $this->prepare( 'SELECT meta_value FROM wp_postmeta WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1', $id, $key ) );
		return null === $v ? '' : (string) $v;
	}
	public function set_meta( $id, $key, $value ) {
		$this->db->exec( $this->prepare( 'DELETE FROM wp_postmeta WHERE post_id = %d AND meta_key = %s', $id, $key ) );
		$this->db->exec( $this->prepare( 'INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( %d, %s, %s )', $id, $key, (string) $value ) );
	}
	// Filling it.
	public function product( $id, array $meta, $status = 'publish' ) {
		$this->db->exec( $this->prepare( 'INSERT INTO wp_posts VALUES ( %d, %s, %s )', $id, 'product', $status ) );
		foreach ( $meta as $key => $value ) {
			$this->db->exec( $this->prepare( 'INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( %d, %s, %s )', $id, $key, $value ) );
		}
	}
	public function category( $term_id, array $product_ids ) {
		$this->db->exec( $this->prepare( 'INSERT INTO wp_term_taxonomy VALUES ( %d, %d, %s )', 100 + $term_id, $term_id, 'product_cat' ) );
		foreach ( $product_ids as $id ) {
			$this->db->exec( $this->prepare( 'INSERT INTO wp_term_relationships VALUES ( %d, %d )', $id, 100 + $term_id ) );
		}
	}
	public function listing_row( $product_id, $item_id, $status ) {
		$this->db->exec( $this->prepare( 'INSERT INTO wp_tcgiant_sync_listings ( product_id, ebay_item_id, listing_status ) VALUES ( %d, %s, %s )', $product_id, $item_id, $status ) );
	}
}
$wpdb = new Sqlite_Wpdb();
// Auto-relist reads a product's own marks before asking eBay (3.28.0).
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['wpdb']->meta( (int) $id, (string) $key ); }
// And marks each row's turn (3.28.5).
function update_post_meta( $id, $key, $value ) { $GLOBALS['wpdb']->set_meta( (int) $id, (string) $key, $value ); return true; }

// A live shop, by wp-config, for TCGiant_Sync_Environment (3.28.0), which
// the push, Relist and auto-relist ask before anything goes to eBay.
// Staging copies are test-staging-detect.php's.
define( 'TCGIANT_SYNC_IS_STAGING', false );
require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';

$ONLY_US = array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' );
$OPTIONS['tcgiant_sync_ebay_settings'] = $ONLY_US;

/** A product linked to a live listing, recorded on $site (null: no site row at all). */
function linked( $id, $site, array $more = array(), $status = 'publish' ) {
	$meta = array( '_ebay_item_id' => '9000' . $id, '_ebay_listing_status' => 'Active' );
	if ( null !== $site ) {
		$meta['_ebay_site'] = $site;
	}
	$GLOBALS['wpdb']->product( $id, array_merge( $meta, $more ), $status );
}

$pushed = array( '_ebay_export_last_pushed' => '2025-01-01 10:00:00' );

echo "\nTHE OTHER SITES' PRODUCTS, AS STOCK REVIEW LISTS AND COUNTS THEM\n" . str_repeat( '=', 140 ) . "\n";

linked( 1, 'UK', array( '_ebay_export_status' => 'imported' ) );           // listed
linked( 2, 'US' );                                                          // the shop's own site
linked( 3, 'eBayMotors' );                                                  // part of eBay US
linked( 4, 'UK', array( '_ebay_export_status' => 'pushed' ) );             // the shop's own: pushed
linked( 5, 'UK', array(), 'trash' );                                        // in the trash
linked( 6, 'Germany' );                                                     // listed
$wpdb->product( 7, array( '_ebay_site' => 'UK' ) );                         // not linked to a listing
linked( 8, null );                                                          // not checked yet
linked( 9, '' );                                                            // not checked yet, empty row
linked( 10, null, array( '_ebay_listing_status' => 'Ended' ) );             // never checked: ended
linked( 11, 'UK', array_merge( $pushed, array( '_ebay_export_status' => 'error' ) ) );   // the shop's own, last push failed
linked( 12, 'UK', array_merge( $pushed, array( '_ebay_export_status' => 'queued' ) ) );  // the shop's own, push queued
linked( 13, 'UK', array( '_ebay_export_pushed_version' => '3.20.0' ) );     // the shop's own, error cleared
linked( 14, 'UK', array( '_ebay_export_status' => 'error' ) );              // imported; its push failed: listed
linked( 15, 'UK', array( '_ebay_export_last_pushed' => '' ) );              // an empty key is not a push: listed
linked( 16, 'Canada' );                                                     // listed, eBay Canada
linked( 17, 'CanadaFrench' );                                               // listed, eBay Canada too
linked( 18, null, $pushed );                                                // the shop's own, not checked: not counted
linked( 19, '', array(), 'auto-draft' );                                    // not a real product yet
linked( 31, 'UK', array( '_ebay_export_status' => 'queued' ) );             // imported; a push of it queued
linked( 32, 'UK', array( '_ebay_export_status' => 'pushing' ) );            // imported; being pushed
linked( 33, 'UK', array( '_ebay_export_status' => 'failed' ) );             // imported; its push did not finish
linked( 34, null, array( '_ebay_export_status' => 'failed' ) );             // the same, not checked: not counted
linked( 35, null, array( '_ebay_export_status' => 'pushing' ) );            // being pushed, not checked: not counted
$wpdb->category( 55, array( 1, 6, 4, 2 ) );                                 // preserved: 1 and 6 of those listed
$wpdb->category( 56, array( 14 ) );                                         // another category

check( 'find_other_site_products(): the copies, not the shop\'s own, the trash, unlinked or unchecked', TCGiant_Sync_Sites::find_other_site_products(), array( 1, 6, 14, 15, 16, 17 ) );
check( '  nor one whose push is queued, under way or did not finish (3.27.4), pushed before or not', array_values( array_intersect( array( 12, 31, 32, 33 ), TCGiant_Sync_Sites::find_other_site_products() ) ), array() );
check( 'count_other_site_products_by_site(): by name, largest first, eBay.ca in French folded in', TCGiant_Sync_Sites::count_other_site_products_by_site(), array( 'eBay United Kingdom' => 3, 'eBay Canada' => 2, 'eBay Germany' => 1 ) );
check( 'count_preserved_other_site_products(): those in a preserved category', TCGiant_Sync_Sites::count_preserved_other_site_products( array( '55' ) ), 2 );
check( '  in either of two', TCGiant_Sync_Sites::count_preserved_other_site_products( array( '55', '56' ) ), 3 );
check( '  none saved: nothing asked', TCGiant_Sync_Sites::count_preserved_other_site_products( array() ), 0 );
check( 'count_unchecked_products(): live, linked, no site, not the shop\'s own', TCGiant_Sync_Sites::count_unchecked_products(), 2 );
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_GB' );
check( 'eBay United Kingdom only: the eBay.com products instead, still not the pushed ones', TCGiant_Sync_Sites::find_other_site_products(), array( 2, 3, 6, 16, 17 ) );
$by_site = TCGiant_Sync_Sites::count_other_site_products_by_site();
ksort( $by_site );
check( '  counted with eBay Motors under eBay United States', $by_site, array( 'eBay Canada' => 2, 'eBay Germany' => 1, 'eBay United States' => 2 ) );
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'import_sites' => 'all', 'marketplace' => 'EBAY_US' );
check( 'all eBay sites: none, and nothing asked', array( TCGiant_Sync_Sites::find_other_site_products(), TCGiant_Sync_Sites::count_other_site_products_by_site(), TCGiant_Sync_Sites::count_preserved_other_site_products( array( '55' ) ) ), array( array(), array(), 0 ) );
$OPTIONS['tcgiant_sync_ebay_settings'] = $ONLY_US;

echo "\nAUTO-RELIST, BOTH OF ITS QUERIES, THROUGH THE REAL CRON METHOD\n" . str_repeat( '=', 140 ) . "\n";

class TCGiant_Sync_Logger {
	public static $lines = array();
	public static function log( $message, $level = 'info' ) { self::$lines[] = $message; }
	public static function error( $message ) { self::log( $message ); }
	public static function warning( $message ) { self::log( $message ); }
}
class TCGiant_Sync_DB {
	public static $exists = true;
	public static function table_exists() { return self::$exists; }
	public static function table_name() { return 'wp_tcgiant_sync_listings'; }
	// Whether WooCommerce has the product out of stock, as a 1/0 flag from
	// its lookup table: auto-relist leaves out sold rows by it. A stand-in of
	// one shape the real TCGiant_Sync_DB::out_of_stock_sql() gives;
	// test-sold-view.php and test-sold-jobs.php run the real one.
	public static function out_of_stock_sql( $column = 'l.product_id' ) {
		return array( " LEFT JOIN wp_wc_product_meta_lookup lk ON lk.product_id = {$column}", "COALESCE(lk.stock_status = 'outofstock', 0)" );
	}
}
/** Relist, as far as auto-relist is concerned: which products it was handed. */
class TCGiant_Sync_Exporter {
	public static $asked = array();
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function relist_ended_listing( $product_id, $source ) {
		self::$asked[] = (int) $product_id;
		return new WP_Error( 'relist_refused', 'harness' );
	}
}
require $ROOT . '/includes/class-tcgiant-sync-cron.php';
$cron = ( new ReflectionClass( 'TCGiant_Sync_Cron' ) )->newInstanceWithoutConstructor();
$OPTIONS['tcgiant_sync_ebay_settings']['auto_relist_enabled'] = '1';

/** Products auto-relist hands to Relist, sorted. */
function auto_relist_asks() {
	TCGiant_Sync_Exporter::$asked = array();
	$GLOBALS['cron']->auto_relist_ended_items();
	$asked = array_values( array_unique( TCGiant_Sync_Exporter::$asked ) );
	sort( $asked );
	return $asked;
}

// Ended: 21 on eBay.co.uk, 22 on eBay.com, 23 not recorded, 24 on eBay.co.uk
// and pushed before (its re-push queued), 25 on eBay.co.uk with an empty
// last-pushed, 26 on eBay Motors, 27 recorded with an empty site, 28 an
// eBay.co.uk copy revised once by Push - what a revise that succeeded writes.
// Pushed before or not, a product on another site is refused by Relist, so it
// is left out; the second build let 24 and 28 through, and Relist relisted them.
$revised = array( '_ebay_export_status' => 'pushed', '_ebay_export_error' => '', '_ebay_export_last_pushed' => '2026-10-16 09:00:00', '_ebay_export_pushed_version' => '3.27.5' );
foreach ( array( 21 => 'UK', 22 => 'US', 23 => null, 24 => 'UK', 25 => 'UK', 26 => 'eBayMotors', 27 => '', 28 => 'UK' ) as $id => $site ) {
	$more = array( '_ebay_listing_status' => 'Ended' );
	if ( 24 === $id ) {
		$more = array_merge( $more, $pushed, array( '_ebay_export_status' => 'queued' ) );
	}
	if ( 25 === $id ) {
		$more['_ebay_export_last_pushed'] = '';
	}
	if ( 28 === $id ) {
		$more = array_merge( $more, $revised );
	}
	linked( $id, $site, $more );
	$wpdb->listing_row( $id, '9000' . $id, 'Ended' );
}
check( 'the listings table\'s query: everything on another site left out, pushed before or not', auto_relist_asks(), array( 22, 23, 26, 27 ) );
TCGiant_Sync_DB::$exists = false;
// The post meta query reads every ended product, 10 included.
check( 'the post meta query, without the table: the same', auto_relist_asks(), array( 10, 22, 23, 26, 27 ) );
TCGiant_Sync_DB::$exists = true;
$OPTIONS['tcgiant_sync_ebay_settings']['import_sites'] = 'all';
check( 'all eBay sites: every ended product comes up, as before', auto_relist_asks(), array( 21, 22, 23, 24, 25, 26, 27, 28 ) );
check( '  by a query with no such clause', false === strpos( (string) end( $wpdb->queries ), 'tcg_site' ), true );

echo "\nAUTO-RELIST LEAVES OUT WHAT SOLD, SO IT CANNOT CROWD OUT WHAT DID NOT\n" . str_repeat( '=', 140 ) . "\n";

// Out of stock in WooCommerce - the Listings screen's Sold view - each was
// picked, refused by Relist for having no stock, every day; fifty of them
// took every place in the LIMIT 50, and the one with stock never came up.
// 22 and 23 sold; 26 is on backorder; 27 has no lookup row; 21, 24, 25 and
// 28 are other sites', all sites now imported.
$wpdb->stock_status( 22, 'outofstock' );
$wpdb->stock_status( 23, 'outofstock' );
$wpdb->stock_status( 26, 'onbackorder' );
$wpdb->stock_status( 21, 'instock' );
check( 'the listings table\'s query: the sold ones are not handed to Relist', auto_relist_asks(), array( 21, 24, 25, 26, 27, 28 ) );
check( '  by the clause that says so', false !== strpos( (string) end( $wpdb->queries ), "COALESCE(lk.stock_status = 'outofstock', 0) = 0" ), true );
TCGiant_Sync_DB::$exists = false;
check( 'the post meta query: the same, 10 included', auto_relist_asks(), array( 10, 21, 24, 25, 26, 27, 28 ) );
TCGiant_Sync_DB::$exists = true;

// Sixty sold, then one with stock, in the order the table holds them.
for ( $id = 100; $id < 160; $id++ ) {
	linked( $id, 'US', array( '_ebay_listing_status' => 'Ended' ) );
	$wpdb->listing_row( $id, '9000' . $id, 'Ended' );
	$wpdb->stock_status( $id, 'outofstock' );
}
linked( 160, 'US', array( '_ebay_listing_status' => 'Ended' ) );
$wpdb->listing_row( 160, '9000160', 'Ended' );
$wpdb->stock_status( 160, 'instock' );
check( 'sixty sold rows ahead of it: the one with stock is still reached', in_array( 160, auto_relist_asks(), true ), true );
check( '  and none of the sixty is', array_values( array_intersect( range( 100, 159 ), auto_relist_asks() ) ), array() );

check( 'no query was refused', $wpdb->errors, array() );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
