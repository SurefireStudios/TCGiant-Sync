<?php
/**
 * Harness: relisting an ended eBay listing - and refusing to.
 *
 * A coin dealer ticked everything on the Ended tab and pressed Relist. Every
 * coin that had sold went straight back up on eBay at the quantity it was first
 * listed with, and so did products sitting in the trash: bulk Relist sent
 * RelistItem for whatever was ticked and checked nothing at all. Auto-relist
 * was barely better. It looked at WooCommerce's stock but never asked eBay
 * whether the listing had sold out, and it sent no quantity, so eBay relisted
 * at whatever it had left. And a push that found its listing ended created a
 * brand new one without asking either - a sold coin whose stock had been put
 * back, by hand or by the old ended-listing settle, was listed again and sold
 * twice.
 *
 * 3.27.0 gives both relist routes one guarded path,
 * TCGiant_Sync_Exporter::relist_ended_listing(), and has the push ask eBay
 * before it recreates an ended listing.
 *
 * Loaded for real: TCGiant_Sync_Exporter, TCGiant_Sync_API,
 * TCGiant_Sync_Catalog, TCGiant_Sync_Mapper (whose rule for telling a relist
 * from another item the relist path shares), TCGiant_Sync_Variations, the
 * inventory class's variation settle, and the bulk job handler and
 * daily scheduler that call the relist (TCGiant_Sync_Jobs,
 * TCGiant_Sync_Cron), with WordPress and
 * WooCommerce stubbed. For the exporter's decisions eBay is a subclass of the
 * real API class that records what it was asked and answers from a script.
 * For what is actually sent, the real API class runs with only the HTTP call
 * captured, so the RelistItem and RelistFixedPriceItem requests checked here
 * are the bytes that would go to eBay. Nothing leaves the machine.
 *
 * The section on a coin restocked after its sell-out fails on 3.27.0 on
 * purpose: a shop's restock made after a sell-out was already settled is
 * settled away again by the guard.
 *
 * The two sections on listings eBay had already relisted fail on 3.27.2 on
 * purpose. A listing the seller had already relisted on eBay was relisted
 * again beside it - eBay accepts a second relist of one listing and simply
 * makes another - and a push to it created a new listing as well. 3.28.0
 * follows eBay's record of the relist instead, and moves the product on to it.
 *
 * So does the first on a coin auction: 3.27.2 let the coin be bought on the
 * website while its auction ran. Whether it can be is decided by the real
 * inventory class, around a relist made by the real exporter and API class.
 *
 * After an unsold end only an auction the shop listed from WooCommerce goes
 * back on sale; one imported from eBay keeps eBay's opening bid as its price,
 * and stays off. The push that lists an auction notes it as the shop's own
 * (_ebay_auction_listed_item), a relist carries the note on, and an Update
 * pushed to an imported auction, or an auction started on eBay, gets none.
 * The push is driven through the real process_single_push(), as Action
 * Scheduler runs it, so what it writes to the product is checked too.
 *
 * The section on a relist edited into another item fails before the third
 * review on purpose: eBay's note of a relist was followed whatever goods the
 * relist carried, so a coin moved on to, and a push revised, another coin's
 * listing. The import's SKU and barcode rule now decides here too.
 *
 * The sections on variation listings are 3.28.0's too. A card shop sells deck
 * sleeves in one listing, Black and White, each colour from its own stock.
 * 3.27.2's check passed every variable product by, and its relist went to eBay
 * with no variations at all, so eBay put a colour that had sold out back up at
 * the quantity it was first listed with, and the rest at eBay's figure rather
 * than the shop's. Now each variation's own sales come in first - through the
 * real inventory class and TCGiant_Sync_Variations, which pairs eBay's
 * variations with the shop's - and the relist names every variation, at the
 * shop's quantity and eBay's price. The hourly check and Stock Review settle
 * the variations as well, before the parent.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-78s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$ROOT = dirname( __DIR__ );

// --- the world the exporter runs in ------------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'TCGIANT_SYNC_VERSION', '3.28.0' ); // what a push records as the build that sent it

$POSTS    = array(); // id => array( 'status' => ..., 'type' => ... )
$META     = array(); // id => array( meta_key => value ), read by WooCommerce and written by update_post_meta() alike
$OPTIONS  = array();
$SETTINGS = array(); // what TCGiant_Sync_OAuth::get_settings() hands back
$LOG      = array();
$ROWS     = array(); // TCGiant_Sync_DB::upsert() calls
$SETTLED  = array(); // TCGiant_Sync_Inventory calls
$WIRE     = array(); // every request that reached the HTTP layer
$ANSWERS  = array(); // eBay call name => inner XML of its answer
$SYNCED   = array(); // WC_Product_Variable::sync() calls: parent, and whether pushes to eBay were held off

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

// The Listings table's ended rows, as the daily auto-relist reads them.
define( 'ARRAY_A', 'ARRAY_A' );
$ENDED_ROWS = array();
$QUERIES    = array(); // every list the database was asked for

class Harness_Wpdb {
	public $options  = 'wp_options';
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
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
	public function query( $query ) { return 0; }
	/** The day's eBay call count, read from its row since 3.28.0: none made here. */
	public function get_var( $query ) { return null; }
	/**
	 * The rows the database would return for the query asked.
	 *
	 * The hourly ended-listing check's (3.28.0): products whose recorded end
	 * has passed and that are not marked Ended yet, read from the products
	 * themselves. The ended rows otherwise, with the query's LIMIT, and
	 * without products recorded on another eBay site when the query keeps
	 * those out (3.27.5).
	 */
	public function get_results( $query, $output = null ) {
		$GLOBALS['QUERIES'][] = $query;
		if ( preg_match( "/pm_end\\.meta_value < '([^']+)'/", $query, $now ) ) {
			$rows = array();
			foreach ( $GLOBALS['POSTS'] as $id => $post ) {
				$m = $GLOBALS['META'][ $id ] ?? array();
				if ( 'trash' !== $post['status'] && '' !== (string) ( $m['_ebay_end_time'] ?? '' )
					&& (string) $m['_ebay_end_time'] < $now[1] && 'Ended' !== ( $m['_ebay_listing_status'] ?? '' ) ) {
					$rows[] = (object) array( 'ID' => (string) $id, 'duration' => (string) ( $m['_ebay_listing_duration'] ?? '' ) );
				}
			}
			return $rows;
		}
		$rows = $GLOBALS['ENDED_ROWS'];
		if ( preg_match( "/(NOT )?EXISTS \( SELECT 1 FROM \S+ tcg_site.*?tcg_site\.meta_value NOT IN \( ([^)]*) \)/s", $query, $m ) ) {
			$keep    = array_map( function ( $s ) { return trim( $s, " '" ); }, explode( ',', $m[2] ) );
			$without = 'NOT ' === $m[1];
			// ... unless it is the shop's own, when the query says so (tcg_own).
			$own_in  = false !== strpos( $query, 'OR EXISTS ( SELECT 1 FROM wp_postmeta tcg_own' );
			$rows    = array_values( array_filter( $rows, function ( $row ) use ( $keep, $without, $own_in ) {
				$meta  = $GLOBALS['META'][ (int) ( $row['product_id'] ?? $row['post_id'] ?? 0 ) ] ?? array();
				$site  = (string) ( $meta['_ebay_site'] ?? '' );
				$other = '' !== $site && ! in_array( $site, $keep, true );
				$own   = '' !== (string) ( $meta['_ebay_export_last_pushed'] ?? '' ) || '' !== (string) ( $meta['_ebay_export_pushed_version'] ?? '' )
					|| 'pushed' === ( $meta['_ebay_export_status'] ?? null );
				return $without ? ( ! $other || ( $own_in && $own ) ) : $other;
			} ) );
		}
		if ( preg_match( '/LIMIT (\d+)/', $query, $limit ) ) {
			$rows = array_slice( $rows, 0, (int) $limit[1] );
		}
		return $rows;
	}
	/** Other live products linked to a listing - see TCGiant_Sync_Listing_Link. */
	public function get_col( $query ) {
		if ( ! preg_match( "/pm\\.meta_value = '([^']*)'/", $query, $item ) || ! preg_match( '/pm\.post_id != (\d+)/', $query, $not ) ) {
			return array();
		}
		$ids = array();
		foreach ( $GLOBALS['POSTS'] as $id => $post ) {
			if ( (int) $id !== (int) $not[1] && 'trash' !== $post['status'] && (string) ( $GLOBALS['META'][ $id ]['_ebay_item_id'] ?? '' ) === $item[1] ) {
				$ids[] = (string) $id;
			}
		}
		return $ids;
	}
}
$wpdb = new Harness_Wpdb();

// The admin-ajax request a bulk job's batch arrives as. Sending the JSON ends
// a real request; here it ends the call, carrying what would have been sent.
class Harness_Json_Sent extends Exception {
	public $payload;
	public function __construct( $payload ) { parent::__construct( 'json sent' ); $this->payload = $payload; }
}
function check_ajax_referer() { return 1; }
function current_user_can() { return true; }
function sanitize_text_field( $text ) { return trim( (string) $text ); }
function wp_unslash( $value ) { return $value; }
function wp_send_json_success( $data = null ) { throw new Harness_Json_Sent( array( 'success' => true, 'data' => $data ) ); }
function wp_send_json_error( $data = null ) { throw new Harness_Json_Sent( array( 'success' => false, 'data' => $data ) ); }

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function add_action() {}
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function wp_rand( $min = 0, $max = 0 ) { return mt_rand( $min, $max ); }
function wp_cache_delete() { return true; }
function get_transient( $name ) { return false; }
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['SET_TRANSIENTS'][ $name ] = $value; return true; }
function delete_transient( $name ) { return true; }
function add_filter() {}
function delete_option( $name ) { unset( $GLOBALS['OPTIONS'][ $name ] ); return true; }
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( ! array_key_exists( $name, $GLOBALS['OPTIONS'] ) ) {
		$GLOBALS['OPTIONS'][ $name ] = $value;
	}
	return true;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['OPTIONS'][ $name ] = $value;
	return true;
}
// Core's own, so the UUID eBay sees has the shape it would on a real site.
function wp_generate_uuid4() {
	return sprintf(
		'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
		mt_rand( 0, 0xffff ),
		mt_rand( 0, 0x0fff ) | 0x4000,
		mt_rand( 0, 0x3fff ) | 0x8000,
		mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
	);
}

// Posts and their meta: one store, so what update_post_meta() writes is what
// the product object reads back, as on a real site.
function get_post_status( $id ) { return $GLOBALS['POSTS'][ (int) $id ]['status'] ?? false; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['META'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['META'][ (int) $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['META'][ (int) $id ][ $key ] ); return true; }

// WooCommerce's product, as far as the relist path reads one. A product that
// does not manage stock has no quantity (null), as WooCommerce reports it.
class WC_Product {
	protected $id;
	protected $sku_change  = null;
	protected $meta_change = array(); // what a push wrote, held until save(); null for a delete
	public function __construct( $id ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_name() { return 'Coin #' . $this->id; }
	public function get_meta( $key, $single = true ) {
		if ( array_key_exists( $key, $this->meta_change ) ) {
			return $this->meta_change[ $key ] ?? '';
		}
		return $GLOBALS['META'][ $this->id ][ $key ] ?? '';
	}
	// As far as a push records its listing on one, as WooCommerce does.
	public function update_meta_data( $key, $value ) { $this->meta_change[ $key ] = $value; }
	public function delete_meta_data( $key ) { $this->meta_change[ $key ] = null; }
	// Meta is read from $META each time, so a push's fresh read (3.27.4) has nothing to do.
	public function read_meta_data( $force_read = false ) {}
	public function is_type( $type ) { return $type === ( $GLOBALS['POSTS'][ $this->id ]['type'] ?? 'simple' ); }
	public function managing_stock() { return 'yes' === $this->get_meta( '_manage_stock' ); }
	public function get_stock_quantity() { return $this->managing_stock() ? (int) $this->get_meta( '_stock' ) : null; }
	public function get_stock_status() { return $this->get_meta( '_stock_status' ) ?: 'instock'; }
	public function is_in_stock() { return 'outofstock' !== $this->get_stock_status(); }
	public function get_status() { return $GLOBALS['POSTS'][ $this->id ]['status'] ?? 'publish'; }
	public function get_parent_id() { return (int) ( $GLOBALS['POSTS'][ $this->id ]['parent'] ?? 0 ); }
	/** A variable product's variations, the trash left out. */
	public function get_children() {
		$ids = array();
		foreach ( $GLOBALS['POSTS'] as $id => $post ) {
			if ( (int) ( $post['parent'] ?? 0 ) === $this->id && 'trash' !== $post['status'] ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
	// As far as building a listing for a push reads one. A variation with no
	// SKU of its own shows its parent's, as WooCommerce's does - except when
	// asked for its own ('edit').
	public function get_sku( $context = 'view' ) {
		$own = null !== $this->sku_change ? $this->sku_change : (string) $this->get_meta( '_sku' );
		if ( '' === $own && 'view' === $context && $this->get_parent_id() ) {
			$parent = wc_get_product( $this->get_parent_id() );
			return $parent ? $parent->get_sku() : '';
		}
		return $own;
	}
	public function set_sku( $sku ) { $this->sku_change = (string) $sku; }
	public function save() {
		if ( null !== $this->sku_change ) {
			$GLOBALS['META'][ $this->id ]['_sku'] = $this->sku_change;
		}
		foreach ( $this->meta_change as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS['META'][ $this->id ][ $key ] );
			} else {
				$GLOBALS['META'][ $this->id ][ $key ] = $value;
			}
		}
		$this->meta_change = array();
		return $this->id;
	}
	public function get_regular_price() { return '89.99'; }
	public function get_description() { return 'A coin.'; }
	public function get_short_description() { return ''; }
	// A variation's own: attribute => value, as WooCommerce gives it. A
	// product here has none of the other kind.
	public function get_attributes() { return $GLOBALS['POSTS'][ $this->id ]['attrs'] ?? array(); }
	public function get_image_id() { return 0; }
	public function get_gallery_image_ids() { return array(); }
	public function get_weight() { return ''; }
}
class WC_Product_Variable extends WC_Product {
	/** Every value its variations use, per attribute, as a push lists them. */
	public function get_variation_attributes() {
		$out = array();
		foreach ( $this->get_children() as $child_id ) {
			foreach ( (array) ( $GLOBALS['POSTS'][ $child_id ]['attrs'] ?? array() ) as $name => $value ) {
				$out[ $name ][] = $value;
			}
		}
		return array_map( 'array_values', array_map( 'array_unique', $out ) );
	}
	/** The parent's price range and stock status follow its variations: noted, with whether pushes were held off. */
	public static function sync( $id ) { $GLOBALS['SYNCED'][] = array( (int) $id, Harness_Real_Inventory::is_push_suppressed() ); }
}
class WC_Product_Variation extends WC_Product {
	public function get_variation_attributes() {
		$out = array();
		foreach ( $this->get_attributes() as $name => $value ) {
			$out[ 'attribute_' . $name ] = $value;
		}
		return $out;
	}
}
// No global attributes in this shop: every variation attribute is the
// product's own, named as the shop typed it.
function taxonomy_exists( $taxonomy ) { return false; }
function wc_attribute_label( $name, $product = '' ) { return (string) $name; }
function get_term_by( $field, $value, $taxonomy = '' ) { return false; }
function sanitize_title( $title ) { return trim( preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( trim( (string) $title ) ) ), '-' ); }
/** WooCommerce's own: set or move a managed quantity, the status following it. */
function wc_update_product_stock( $product, $quantity = null, $operation = 'set' ) {
	$id = is_object( $product ) ? $product->get_id() : (int) $product;
	if ( null === $quantity || 'yes' !== ( $GLOBALS['META'][ $id ]['_manage_stock'] ?? '' ) ) {
		return false;
	}
	// Another plugin holds this one's quantity: the write does not take.
	if ( in_array( $id, $GLOBALS['REFUSE_STOCK'] ?? array(), true ) ) {
		return (int) ( $GLOBALS['META'][ $id ]['_stock'] ?? 0 );
	}
	$now = (int) ( $GLOBALS['META'][ $id ]['_stock'] ?? 0 );
	$now = 'decrease' === $operation ? $now - (int) $quantity : ( 'increase' === $operation ? $now + (int) $quantity : (int) $quantity );
	$GLOBALS['META'][ $id ]['_stock']        = $now;
	$GLOBALS['META'][ $id ]['_stock_status'] = $now > 0 ? 'instock' : 'outofstock';
	return $now;
}
function wc_update_product_stock_status( $id, $status ) { $GLOBALS['META'][ (int) $id ]['_stock_status'] = $status; }

/** WooCommerce's own lookup leaves the trash out. */
function wc_get_product_id_by_sku( $sku ) {
	foreach ( $GLOBALS['POSTS'] as $id => $post ) {
		if ( '' !== (string) $sku && 'trash' !== $post['status'] && (string) ( $GLOBALS['META'][ $id ]['_sku'] ?? '' ) === (string) $sku ) {
			return $id;
		}
	}
	return 0;
}
function wc_format_decimal( $number, $dp = false ) { return number_format( (float) $number, (int) $dp, '.', '' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return (string) $url; }
function esc_xml( $text ) { return htmlspecialchars( (string) $text, ENT_XML1, 'UTF-8' ); }
function apply_filters( $hook, $value ) { return $value; }
function get_post_type( $id ) { return isset( $GLOBALS['POSTS'][ (int) $id ] ) ? 'product' : false; }

function wc_get_product( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['POSTS'][ $id ] ) ) {
		return false;
	}
	switch ( $GLOBALS['POSTS'][ $id ]['type'] ) {
		case 'variable':
			return new WC_Product_Variable( $id );
		case 'variation':
			return new WC_Product_Variation( $id );
		default:
			return new WC_Product( $id );
	}
}

// The plugin's neighbours.
class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	// What the record of a push PHP killed is written with (3.27.4).
	public static function write_now( $message, $level = 'error' ) { self::log( $message, $level ); }
}

class TCGiant_Sync_DB {
	public static function upsert( $data ) { $GLOBALS['ROWS'][] = $data; return true; }
	public static function table_name() { return 'wp_tcgiant_sync_listings'; }
	// A site whose listings table was never created reads from post meta.
	public static function table_exists() { return $GLOBALS['TABLE_EXISTS'] ?? true; }
	// Auto-relist leaves out sold rows by WooCommerce's stock status; this
	// database plays its rows from ENDED_ROWS, so a stand-in flag.
	// test-site-sql.php and test-sold-jobs.php run the real query.
	public static function out_of_stock_sql( $column = 'l.product_id' ) { return array( '', '0' ); }
}

// The settle is tested on its own elsewhere; here only whether it is asked.
// The settle's real rule - it only ever lowers stock, and notes which listing
// it settled - is tested against the real class in test-ended-settle.php. Here
// it is recorded, and applied to the fake store so the relist path can read
// back what the stock came to.
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS         = Harness_Real_Inventory::SETTLE_KEYS;
	const AUCTION_FIGURE_KEYS = Harness_Real_Inventory::AUCTION_FIGURE_KEYS;
	const AUCTION_LISTED_KEY  = Harness_Real_Inventory::AUCTION_LISTED_KEY;
	// Whether a variation's stock is its parent's, as the real class decides.
	public static function stock_held_by_parent( $variation ) {
		return Harness_Real_Inventory::stock_held_by_parent( $variation );
	}
	// The real move of an item-number SKU, as follow_relist() uses it.
	public static function follow_item_number_sku( $product_id, $old_item_id, $new_item_id ) {
		Harness_Real_Inventory::follow_item_number_sku( $product_id, $old_item_id, $new_item_id );
	}
	// The real notes of a listing's type, and of an auction in the shop (3.28.0).
	public static function note_listing_type( $product_id, $item_id, $type ) {
		Harness_Real_Inventory::note_listing_type( $product_id, $item_id, $type );
	}
	public static function auctions_seen() {
		Harness_Real_Inventory::auctions_seen();
	}
	public static function apply_ended_listing_stock( $product_id, $listed, $sold ) {
		$GLOBALS['SETTLED'][] = array( 'apply_ended_listing_stock', (int) $product_id, null === $listed ? null : (int) $listed, null === $sold ? null : (int) $sold );
		if ( null === $listed || null === $sold || ! empty( $GLOBALS['SETTLE_FAILS'] ) ) {
			return null;
		}
		$id        = (int) $product_id;
		$remaining = max( 0, (int) $listed - (int) $sold );
		$m         = $GLOBALS['META'][ $id ] ?? array();
		if ( 'yes' === ( $m['_manage_stock'] ?? '' ) ) {
			$held = min( (int) ( $m['_stock'] ?? 0 ), $remaining );
			$GLOBALS['META'][ $id ]['_stock'] = $held;
			if ( $held <= 0 ) {
				$GLOBALS['META'][ $id ]['_stock_status'] = 'outofstock';
			}
		} else {
			$held = $remaining;
			if ( 0 === $remaining ) {
				$GLOBALS['META'][ $id ]['_stock_status'] = 'outofstock';
			}
		}
		$GLOBALS['META'][ $id ]['_ebay_stock_settled_at']   = '2026-10-12 12:00:00';
		$GLOBALS['META'][ $id ]['_ebay_stock_settled_item'] = (string) ( $m['_ebay_item_id'] ?? '' );
		return $held;
	}
	public static function listing_already_settled( $product_id, $ebay_item_id ) {
		$m = $GLOBALS['META'][ (int) $product_id ] ?? array();
		return '' !== (string) ( $m['_ebay_stock_settled_at'] ?? '' ) && (string) $ebay_item_id === (string) ( $m['_ebay_stock_settled_item'] ?? '' );
	}
	// The website order is scripted; whether the product still looks restocked
	// by the old settle is read from it as the real query reads it - Ended,
	// in stock, settled with no listing noted, not reviewed - so a move on to
	// a relist, which forgets the settle, is seen to forget this too.
	public static function restocked_after_website_sale( $product_id ) {
		$m = $GLOBALS['META'][ (int) $product_id ] ?? array();
		if ( 'Ended' !== ( $m['_ebay_listing_status'] ?? '' ) || 'instock' !== ( $m['_stock_status'] ?? '' )
			|| '' === (string) ( $m['_ebay_stock_settled_at'] ?? '' ) || isset( $m['_ebay_stock_settled_item'] ) || isset( $m['_ebay_restock_reviewed'] ) ) {
			return 0;
		}
		return (int) ( $GLOBALS['RESTOCKED'][ (int) $product_id ] ?? 0 );
	}
	// The real forgetting, so whatever the real class counts as one listing's
	// settle - the variations' too - goes with it.
	public static function clear_settled_mark( $product_id ) {
		Harness_Real_Inventory::clear_settled_mark( $product_id );
	}
	// A variation listing's variations are settled for real: paired with the
	// shop's by TCGiant_Sync_Variations, and each lowered by the real
	// lower_stock_to(). Recorded first, so the order against the parent's
	// settle can be read.
	public static function settle_variations( $parent_id, array $item, $source, $before_relist = false ) {
		$GLOBALS['SETTLED'][] = array( 'settle_variations', (int) $parent_id, (string) $source );
		return Harness_Real_Inventory::settle_variations( $parent_id, $item, $source, $before_relist );
	}
	public static function variations_already_settled( $parent_id, $ebay_item_id ) {
		return Harness_Real_Inventory::variations_already_settled( $parent_id, $ebay_item_id );
	}
	public static function maybe_remove_sold_out( $product_id, $listed, $sold ) {
		$GLOBALS['SETTLED'][] = array( 'maybe_remove_sold_out', (int) $product_id, null === $listed ? null : (int) $listed, null === $sold ? null : (int) $sold );
		return false;
	}
	// Moving a product on to a relist is not played here: the plugin's own
	// code does it, lifted out of the real class below, so what the exporter
	// hands it is applied exactly as on a site.
	public static function listing_facts( array $item ) {
		return Harness_Real_Inventory::listing_facts( $item );
	}
	public static function follow_relist( $product_id, $old_item_id, $new_item_id, array $facts, $source ) {
		$GLOBALS['FOLLOWED'][] = array( (int) $product_id, (string) $old_item_id, (string) $new_item_id );
		Harness_Real_Inventory::follow_relist( $product_id, $old_item_id, $new_item_id, $facts, $source );
	}
}

// The real TCGiant_Sync_Inventory, under another name, for the move above.
$FOLLOWED      = array();
$inventory_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-inventory.php' );
eval( '?>' . preg_replace( '/^class TCGiant_Sync_Inventory\b/m', 'class Harness_Real_Inventory', $inventory_src, 1 ) );

class TCGiant_Sync_OAuth {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_settings() { return $GLOBALS['SETTINGS']; }
	public function get_access_token() { return 'harness-token'; }
	public function refresh_access_token() { return false; }
}

// The HTTP layer, where the real API class meets eBay. Captured, and answered
// in eBay's own envelope from $ANSWERS.
function wp_remote_request( $url, $args ) {
	$call = $args['headers']['X-EBAY-API-CALL-NAME'] ?? '';
	$GLOBALS['WIRE'][] = array( 'url' => $url, 'call' => $call, 'body' => $args['body'] ?? '' );
	$inner = $GLOBALS['ANSWERS'][ $call ] ?? '<Ack>Success</Ack>';
	// An answer for one listing in particular, where one is scripted.
	if ( preg_match( '#<ItemID>(\d+)</ItemID>#', (string) ( $args['body'] ?? '' ), $asked ) && isset( $GLOBALS['ANSWERS'][ $call . ':' . $asked[1] ] ) ) {
		$inner = $GLOBALS['ANSWERS'][ $call . ':' . $asked[1] ];
	}
	return array(
		'headers'  => array(),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'body'     => '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<' . $call . 'Response xmlns="urn:ebay:apis:eBLBaseComponents">' . $inner . '</' . $call . 'Response>',
	);
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? ''; }
function wp_remote_retrieve_header( $response, $header ) { return $response['headers'][ $header ] ?? ''; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }

// The live shop. Since 3.28.0 the API class and the auto-relist ask
// TCGiant_Sync_Environment before anything that changes eBay; at a live address
// it lets everything through, so nothing below changes. Staging copies are
// test-staging-detect.php's.
function wp_load_alloptions() { return array( 'home' => 'https://numismax.com', 'siteurl' => 'https://numismax.com' ); }
function wp_get_environment_type() { return 'production'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
// The import's own rule for telling a relist from another item, which the
// relist path asks too (TCGiant_Sync_Mapper::other_goods_sku()).
require $ROOT . '/includes/class-tcgiant-sync-mapper.php';
// How eBay's variations are paired with a variable product's, for the settle
// and the relist alike.
require $ROOT . '/includes/class-tcgiant-sync-variations.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
// A push is watched, so a death names it (3.27.4).
require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-exporter.php';
require $ROOT . '/includes/class-tcgiant-sync-jobs.php';
require $ROOT . '/includes/class-tcgiant-sync-cron.php';

/**
 * eBay for the exporter's decisions: the real API class, with the three calls
 * the relist path makes recorded and answered from a script. Anything else
 * that tried to reach eBay is recorded too, and refused.
 */
class Recording_eBay extends TCGiant_Sync_API {
	public $calls   = array();
	public $item    = array(); // GetItem's answer, or a WP_Error
	public $items   = array(); // ... or one per listing: item ID => answer
	public $relists = array(); // the relist call's answer, a WP_Error, or a callable given the item ID

	public function get_item( $item_id ) {
		$this->calls[] = array( 'GetItem', (string) $item_id );
		return $this->items[ (string) $item_id ] ?? $this->item;
	}
	public function relist_item( $item_id, $quantity = null ) {
		$this->calls[] = array( 'RelistItem', (string) $item_id, $quantity );
		return is_callable( $this->relists ) ? call_user_func( $this->relists, (string) $item_id ) : $this->relists;
	}
	// A variation listing's Variations block, when one is sent, is recorded as
	// a fourth entry.
	public function relist_fixed_price_item( $item_id, $quantity = null, $variations_xml = '' ) {
		$this->calls[] = '' === (string) $variations_xml
			? array( 'RelistFixedPriceItem', (string) $item_id, $quantity )
			: array( 'RelistFixedPriceItem', (string) $item_id, $quantity, (string) $variations_xml );
		return is_callable( $this->relists ) ? call_user_func( $this->relists, (string) $item_id ) : $this->relists;
	}
	// A push: revising a listing, or adding a new one. Recorded with the
	// listing the request names, and answered per listing from $revises.
	public $revises = array();
	public $sent    = array(); // what each push sent, keyed as the call list is
	private function pushed( $call, $item_xml ) {
		$named = preg_match( '#<ItemID>(\d+)</ItemID>#', (string) $item_xml, $m ) ? $m[1] : '';
		$this->calls[] = array( $call, $named );
		$this->sent[ count( $this->calls ) - 1 ] = (string) $item_xml;
		if ( 0 === strpos( $call, 'Add' ) ) {
			return array( 'Ack' => 'Success', 'ItemID' => '226100077777' );
		}
		$answer = $this->revises[ $named ] ?? array( 'Ack' => 'Success', 'ItemID' => $named );
		// Something that happens while eBay is revising it, such as the
		// request being killed.
		return $answer instanceof Closure ? $answer() : $answer;
	}
	public function revise_fixed_price_item( $item_xml ) { return $this->pushed( 'ReviseFixedPriceItem', $item_xml ); }
	public function revise_item( $item_xml ) { return $this->pushed( 'ReviseItem', $item_xml ); }
	public function add_fixed_price_item( $item_xml ) { return $this->pushed( 'AddFixedPriceItem', $item_xml ); }
	public function add_item( $item_xml ) { return $this->pushed( 'AddItem', $item_xml ); }
	public function get_category_aspects( $category_id ) { return array(); }
	/** The relist calls only, by listing. */
	public function relists_of( $item_id ) {
		return array_values( array_filter( $this->calls, function ( $c ) use ( $item_id ) {
			return 'GetItem' !== $c[0] && ( $c[1] ?? null ) === (string) $item_id;
		} ) );
	}
	/** Every call that named this listing. */
	public function calls_about( $item_id ) {
		return array_values( array_filter( $this->calls, function ( $c ) use ( $item_id ) {
			return ( $c[1] ?? null ) === (string) $item_id;
		} ) );
	}
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$this->calls[] = array( $call_name );
		return new WP_Error( 'harness', 'unexpected eBay call ' . $call_name );
	}
}

// --- driving it --------------------------------------------------------------

/** Put an eBay in place of the one TCGiant_Sync_API::instance() hands out. */
function use_ebay( $api ) {
	$slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
	if ( PHP_VERSION_ID < 80100 ) {
		$slot->setAccessible( true );
	}
	$slot->setValue( null, $api );
	return $api;
}

/** A shop with nothing in it, and an eBay that has not been asked anything. */
function fresh_shop( $settings = array() ) {
	$GLOBALS['POSTS']      = array();
	$GLOBALS['META']       = array();
	$GLOBALS['OPTIONS']    = array();
	$GLOBALS['SETTINGS']   = $settings;
	$GLOBALS['LOG']        = array();
	$GLOBALS['ROWS']       = array();
	$GLOBALS['SETTLED']    = array();
	$GLOBALS['WIRE']       = array();
	$GLOBALS['ANSWERS']    = array();
	$GLOBALS['ENDED_ROWS'] = array();
	$GLOBALS['REFUSE_STOCK'] = array();
	$GLOBALS['FOLLOWED']   = array();
	$GLOBALS['SYNCED']     = array();
	return use_ebay( new Recording_eBay() );
}

/** Run one batch of a bulk job through the real admin-ajax handler. */
function run_batch( $job_id ) {
	$_POST['job_id'] = $job_id;
	try {
		TCGiant_Sync_Jobs::instance()->ajax_process_batch();
	} catch ( Harness_Json_Sent $sent ) {
		return $sent->payload;
	}
	return null;
}

/** A coin on the Ended tab: published, fixed price, three in stock. */
function coin( $id, array $meta = array(), $status = 'publish', $type = 'simple' ) {
	$GLOBALS['POSTS'][ $id ] = array( 'status' => $status, 'type' => $type );
	$GLOBALS['META'][ $id ]  = array_merge( array(
		'_ebay_item_id'        => '2261000' . str_pad( (string) $id, 5, '0', STR_PAD_LEFT ),
		'_ebay_listing_type'   => 'FixedPriceItem',
		'_ebay_listing_status' => 'Ended',
		'_ebay_end_time'       => '2026-09-01T10:00:00.000Z',
		'_manage_stock'        => 'yes',
		'_stock'               => '3',
		'_stock_status'        => 'instock',
	), $meta );
	return $GLOBALS['META'][ $id ]['_ebay_item_id'];
}

/**
 * A variation listing on the Ended tab: a card shop's deck sleeves, sold by
 * colour or size. The parent holds the listing's total, as the import writes
 * it; each variation holds its own.
 */
function sleeves( $id, array $meta = array() ) {
	return coin( $id, $meta, 'publish', 'variable' );
}
/** One variation of it: its own SKU ('' for none), its stock (null when not counted), and what it is. */
function sleeve( $id, $parent, $sku, $stock, array $attrs, $status = 'publish' ) {
	$GLOBALS['POSTS'][ $id ] = array( 'status' => $status, 'type' => 'variation', 'parent' => $parent, 'attrs' => $attrs );
	$GLOBALS['META'][ $id ]  = array(
		'_sku'          => $sku,
		'_manage_stock' => null === $stock ? 'no' : 'yes',
		'_stock'        => null === $stock ? '' : (string) $stock,
		'_stock_status' => null === $stock || $stock > 0 ? 'instock' : 'outofstock',
	);
}
/** One of eBay's variations, as the real API class decodes it: the price arrives with its currency. */
function ebay_variation( $sku, $listed, $sold, array $specifics, $price = '10.00' ) {
	$pairs = array();
	foreach ( $specifics as $name => $value ) {
		$pairs[] = array( 'Name' => $name, 'Value' => $value );
	}
	$variation = array(
		'StartPrice'         => array( '@attributes' => array( 'currencyID' => 'USD' ), 0 => $price ),
		'Quantity'           => (string) $listed,
		'SellingStatus'      => array( 'QuantitySold' => (string) $sold ),
		'VariationSpecifics' => array( 'NameValueList' => 1 === count( $pairs ) ? $pairs[0] : $pairs ),
	);
	return '' !== $sku ? array( 'SKU' => $sku ) + $variation : $variation;
}
/** GetItem's answer for a variation listing: the listing's own figures, and each variation's. */
function ebay_says_variations( $item_id, $status, $listed, $sold, array $variations ) {
	$answer                       = ebay_says( $status, $listed, $sold );
	$answer['Item']['ItemID']     = $item_id;
	$answer['Item']['Variations'] = array( 'Variation' => 1 === count( $variations ) ? $variations[0] : $variations );
	return $answer;
}

/** GetItem's answer, as decoded by the real API class: every figure a string. */
function ebay_says( $status, $listed, $sold, $type = 'FixedPriceItem' ) {
	return array(
		'Ack'  => 'Success',
		'Item' => array(
			'ItemID'        => '226100000000',
			'ListingType'   => $type,
			'Quantity'      => (string) $listed,
			'SellingStatus' => array( 'ListingStatus' => $status, 'QuantitySold' => (string) $sold ),
		),
	);
}

/** What a successful relist answers. */
function relisted_as( $new_id, $start = '2026-10-12T09:00:00.000Z', $end = '2026-11-11T09:00:00.000Z' ) {
	$answer = array( 'Ack' => 'Success', 'ItemID' => $new_id, 'Fees' => array( 'Fee' => array() ) );
	if ( null !== $start ) {
		$answer['StartTime'] = $start;
	}
	if ( null !== $end ) {
		$answer['EndTime'] = $end;
	}
	return $answer;
}

/** Relist through the one path. In a build without it, that is the answer. */
function relist( $product_id, $source = 'Bulk relist' ) {
	$exporter = TCGiant_Sync_Exporter::instance();
	if ( ! method_exists( $exporter, 'relist_ended_listing' ) ) {
		return new WP_Error( 'no_relist_path', 'TCGiant_Sync_Exporter::relist_ended_listing() does not exist' );
	}
	return $exporter->relist_ended_listing( $product_id, $source );
}

function outcome( $result ) { return is_wp_error( $result ) ? $result->get_error_code() : 'relisted'; }
function meta( $id, $key ) { return $GLOBALS['META'][ $id ][ $key ] ?? null; }
function last_row() { return $GLOBALS['ROWS'] ? end( $GLOBALS['ROWS'] ) : array(); }
function logged( $needle ) {
	foreach ( $GLOBALS['LOG'] as $line ) {
		if ( false !== strpos( $line[1], $needle ) ) {
			return true;
		}
	}
	return false;
}

/** A request as eBay would parse it, or null when it is not well-formed XML. */
function parse_wire( $body ) {
	if ( '' === (string) $body ) {
		return null;
	}
	$previous = libxml_use_internal_errors( true );
	libxml_clear_errors();
	$dom    = new DOMDocument();
	$ok     = $dom->loadXML( $body );
	$errors = libxml_get_errors();
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	if ( ! $ok || $errors ) {
		return null;
	}
	$xpath = new DOMXPath( $dom );
	$xpath->registerNamespace( 'e', 'urn:ebay:apis:eBLBaseComponents' );
	return $xpath;
}
function wire_root( $xpath ) { return $xpath ? $xpath->document->documentElement->localName : null; }
function wire_ns( $xpath ) { return $xpath ? $xpath->document->documentElement->namespaceURI : null; }
function wire_text( $xpath, $path ) { return $xpath ? $xpath->evaluate( 'string(/e:*/e:Item/e:' . $path . ')' ) : null; }
function wire_count( $xpath, $path ) { return $xpath ? (int) $xpath->evaluate( 'count(/e:*/e:Item/e:' . $path . ')' ) : null; }

/** One variation, as GetItem's XML carries it. */
function variation_xml( $sku, $listed, $sold, $name, $value, $price = '10.00' ) {
	return '<Variation>' . ( '' !== $sku ? '<SKU>' . $sku . '</SKU>' : '' )
		. '<StartPrice currencyID="USD">' . $price . '</StartPrice><Quantity>' . $listed . '</Quantity>'
		. '<SellingStatus><QuantitySold>' . $sold . '</QuantitySold></SellingStatus>'
		. '<VariationSpecifics><NameValueList><Name>' . $name . '</Name><Value>' . $value . '</Value></NameValueList></VariationSpecifics></Variation>';
}
/** GetItem's XML for an ended variation listing. */
function variations_getitem_xml( $item_id, $listed, $sold, $variations ) {
	return '<Ack>Success</Ack><Item><ItemID>' . $item_id . '</ItemID><ListingType>FixedPriceItem</ListingType><Quantity>' . $listed . '</Quantity>'
		. '<SellingStatus><QuantitySold>' . $sold . '</QuantitySold><ListingStatus>Completed</ListingStatus></SellingStatus>'
		. '<Variations>' . $variations . '</Variations></Item>';
}
/**
 * The variations a request sends, as eBay would read them: by SKU, or by its
 * specifics for one with none - its quantity, its price, and the specifics it
 * is named by.
 */
function sent_variations( $xpath ) {
	if ( ! $xpath ) {
		return null;
	}
	$out = array();
	foreach ( $xpath->query( '/e:*/e:Item/e:Variations/e:Variation' ) as $variation ) {
		$named = array();
		foreach ( $xpath->query( 'e:VariationSpecifics/e:NameValueList', $variation ) as $pair ) {
			$named[] = $xpath->evaluate( 'string(e:Name)', $pair ) . ': ' . $xpath->evaluate( 'string(e:Value)', $pair );
		}
		$named = implode( ', ', $named );
		$sku   = $xpath->evaluate( 'string(e:SKU)', $variation );
		$out[ '' !== $sku ? $sku : $named ] = array( $xpath->evaluate( 'string(e:Quantity)', $variation ), $xpath->evaluate( 'string(e:StartPrice)', $variation ), $named );
	}
	return $out;
}
/** The Variations block a recorded relist carried, read as the request eBay would get. */
function recorded_variations( $call ) {
	return parse_wire( '<RelistFixedPriceItemRequest xmlns="urn:ebay:apis:eBLBaseComponents"><Item>' . ( $call[3] ?? '' ) . '</Item></RelistFixedPriceItemRequest>' );
}

/**
 * One method's code, comments taken out, from the file as shipped - so that a
 * note recalling what a call used to be cannot answer for the call itself.
 */
function method_code( $file, $name ) {
	$tokens = token_get_all( (string) file_get_contents( $file ) );
	$n      = count( $tokens );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( $j >= $n || ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] || $name !== $tokens[ $j ][1] ) {
			continue;
		}
		while ( $j < $n && '{' !== $tokens[ $j ] ) {
			$j++;
		}
		$depth = 0;
		$code  = '';
		for ( ; $j < $n; $j++ ) {
			$t = $tokens[ $j ];
			if ( is_array( $t ) && ( T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) ) {
				continue;
			}
			if ( '{' === $t || ( is_array( $t ) && ( T_CURLY_OPEN === $t[0] || T_DOLLAR_OPEN_CURLY_BRACES === $t[0] ) ) ) {
				$depth++;
			} elseif ( '}' === $t ) {
				$depth--;
			}
			$code .= is_array( $t ) ? $t[1] : $t;
			if ( 0 === $depth ) {
				return $code;
			}
		}
	}
	return '';
}

/** Direct RelistItem / RelistFixedPriceItem calls in a piece of code. */
function direct_relist_calls( $code ) {
	return preg_match_all( '/->\s*relist_(?:fixed_price_)?item\s*\(/', $code );
}

/** Where a pattern first appears in some code, or -1. */
function at( $code, $needle ) {
	$p = strpos( $code, $needle );
	return false === $p ? -1 : $p;
}

echo "\nNOTHING IS ASKED OF EBAY FOR A PRODUCT THE SHOP CANNOT SELL\n" . str_repeat( '=', 126 ) . "\n";

// The coin dealer's select-all on the Ended tab took trashed products with it.
$ebay = fresh_shop();
coin( 501, array(), 'trash' );
check( 'a product in the trash is not relisted', outcome( relist( 501 ) ), 'relist_refused' );
check( '  and eBay is not asked anything', $ebay->calls, array() );

$ebay = fresh_shop();
coin( 502, array(), 'draft' );
check( 'nor is a draft', outcome( relist( 502 ) ), 'relist_refused' );
check( '  and eBay is not asked anything', $ebay->calls, array() );

$ebay = fresh_shop();
check( 'nor a product that no longer exists', outcome( relist( 503 ) ), 'relist_refused' );
check( '  and eBay is not asked anything', $ebay->calls, array() );

$ebay = fresh_shop();
coin( 504, array( '_ebay_item_id' => '' ) );
check( 'nor a product with no eBay listing', outcome( relist( 504 ) ), 'relist_refused' );
check( '  and eBay is not asked anything', $ebay->calls, array() );

$ebay = fresh_shop();
coin( 505, array( '_stock' => '0', '_stock_status' => 'outofstock' ) );
$result = relist( 505 );
check( 'a coin with none left in WooCommerce is not relisted', outcome( $result ), 'relist_refused' );
check( '  and eBay is not asked anything', $ebay->calls, array() );
check( '  and the shop is told why', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'no stock' ), true );

// Managed stock of 0 is the test, not only the status a plugin may have left.
$ebay = fresh_shop();
coin( 506, array( '_stock' => '0', '_stock_status' => 'instock' ) );
check( 'stock 0 is refused even while the status still says in stock', outcome( relist( 506 ) ), 'relist_refused' );
check( '  and eBay is not asked anything', $ebay->calls, array() );

$ebay = fresh_shop();
coin( 507, array( '_manage_stock' => 'no', '_stock' => '', '_stock_status' => 'outofstock' ) );
check( 'an untracked product marked out of stock is refused', outcome( relist( 507 ) ), 'relist_refused' );
check( '  and eBay is not asked anything', $ebay->calls, array() );

echo "\nNOR FOR ONE WHOSE LAST PUSH IS STILL UNDER WAY, OR DID NOT FINISH\n" . str_repeat( '=', 126 ) . "\n";

// A push that finds its listing ended makes a new one in its place. One that
// died once eBay had made it leaves the product linked to the ended listing,
// still Ended, and relisting that is a second new listing beside the one the
// push made. test-push-visibility.php has the pushes dying for real, and the
// daily auto-relist and the Listings screen meeting them.
foreach ( array(
	'a coin whose last push did not finish'             => array( '_ebay_export_status' => 'failed' ),
	'a coin whose push has said pushing for 20 minutes' => array( '_ebay_export_status' => 'pushing', '_ebay_export_pushing_since' => time() - 20 * 60 ),
	'a coin whose push is under way now'                => array( '_ebay_export_status' => 'pushing', '_ebay_export_pushing_since' => time() - 30 ),
) as $what => $push ) {
	$ebay          = fresh_shop();
	coin( 508, $push );
	$ebay->item    = ebay_says( 'Completed', 3, 0 );
	$ebay->relists = relisted_as( '226100009508' );
	$result        = relist( 508 );
	check( $what . ' is not relisted', outcome( $result ), 'relist_refused' );
	check( '  and eBay is not asked anything', $ebay->calls, array() );
	check( '  and the shop is told why', is_wp_error( $result ) ? $result->get_error_message() : null, 'its last push is still under way or did not finish, and may already have made a new listing in place of this one - its eBay tab says what to do' );
}

// A push that finished, or one eBay refused - which made nothing - is no
// reason not to.
foreach ( array( 'pushed', 'error' ) as $status ) {
	$ebay          = fresh_shop();
	coin( 509, array( '_ebay_export_status' => $status ) );
	$ebay->item    = ebay_says( 'Completed', 3, 0 );
	$ebay->relists = relisted_as( '226100009509' );
	check( 'a coin whose last push says ' . $status . ' is relisted', outcome( relist( 509 ) ), 'relisted' );
}

echo "\nEBAY IS ASKED FIRST, AND BELIEVED\n" . str_repeat( '=', 126 ) . "\n";

// Still live on eBay: relisting would have put a second copy up for sale.
$ebay       = fresh_shop();
$item       = coin( 510 );
$ebay->item = ebay_says( 'Active', 3, 0 );
check( 'a listing eBay says is still live is not relisted', outcome( relist( 510 ) ), 'relist_refused' );
check( '  eBay was asked about that listing, and nothing else', $ebay->calls, array( array( 'GetItem', $item ) ) );
check( '  the product is marked Active again', meta( 510, '_ebay_listing_status' ), 'Active' );
check( '  and so is its Listings row', array( last_row()['ebay_item_id'] ?? null, last_row()['listing_status'] ?? null ), array( $item, 'Active' ) );
check( '  its stock is not touched', $GLOBALS['SETTLED'], array() );

// The coin shop: sold on eBay, stock put back - by hand or the old settle.
$ebay       = fresh_shop();
$item       = coin( 511, array( '_stock' => '1' ) );
$ebay->item = ebay_says( 'Completed', 1, 1 );
$result     = relist( 511 );
check( 'a listing that sold out on eBay is not relisted', outcome( $result ), 'relist_refused' );
check( '  only GetItem was sent - no relist', $ebay->calls, array( array( 'GetItem', $item ) ) );
check( '  the stock is settled from eBay\'s figures', $GLOBALS['SETTLED'][0] ?? null, array( 'apply_ended_listing_stock', 511, 1, 1 ) );
// Not trashed, though: a relist is a request to list it. The sync's own
// removal decides that, where the shop's settings say so.
check( '  and nothing is trashed by a relist', in_array( 'maybe_remove_sold_out', array_column( $GLOBALS['SETTLED'], 0 ), true ), false );
check( '  the shop is told it sold, and how many', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), '1 listed, 1 sold' ), true );
check( '  the product keeps its old listing', meta( 511, '_ebay_item_id' ), $item );
check( '  still shown as ended', meta( 511, '_ebay_listing_status' ), 'Ended' );

$ebay       = fresh_shop();
coin( 512, array( '_stock' => '4' ) );
$ebay->item = ebay_says( 'Completed', 5, 5 );
// (A coin restocked AFTER its sell-out was settled is a different case - see
// the section near the end.)
check( 'five listed and five sold is sold out too', outcome( relist( 512 ) ), 'relist_refused' );
check( '  and nothing was relisted', count( $ebay->calls ), 1 );

// A partial sale is not a sell-out: the rest are the shop's to sell, at the
// shop's figure - two here, where eBay thinks three are left.
$ebay          = fresh_shop();
$item          = coin( 513, array( '_stock' => '2' ) );
$ebay->item    = ebay_says( 'Completed', 5, 2 );
$ebay->relists = relisted_as( '226100009513' );
check( 'five listed and two sold is relisted', outcome( relist( 513 ) ), 'relisted' );
check( '  at WooCommerce\'s two, not the three eBay had left', $ebay->calls[1] ?? null, array( 'RelistFixedPriceItem', $item, 2 ) );
// The listing's sales were not in the stock yet, so they are brought in
// first - which can only lower it, and two is already below three.
check( '  its sales are brought in first', $GLOBALS['SETTLED'], array( array( 'apply_ended_listing_stock', 513, 5, 2 ) ) );
check( '  which leaves the shop\'s two alone', (int) meta( 513, '_stock' ), 2 );

echo "\nTHE RIGHT CALL, AT THE SHOP'S QUANTITY\n" . str_repeat( '=', 126 ) . "\n";

// A fixed-price coin with three in stock, ended by the seller.
$ebay = fresh_shop();
$item = coin( 520, array(
	'_ebay_stock_settled_at' => '2026-09-01 10:05:00',
	'_ebay_unsold_at_end'    => 3,
	'_ebay_quantity_sold'    => 0,
	'_ebay_quantity_listed'  => 3,
) );
// Settled since 3.27.0, which notes the listing it settled.
$GLOBALS['META'][520]['_ebay_stock_settled_item'] = $item;
$ebay->item    = ebay_says( 'Completed', 3, 0 );
$ebay->relists = relisted_as( '226100009520' );
$result        = relist( 520 );
check( 'a fixed-price coin with stock is relisted', outcome( $result ), 'relisted' );
check( '  GetItem first, then RelistFixedPriceItem with quantity 3', $ebay->calls, array( array( 'GetItem', $item ), array( 'RelistFixedPriceItem', $item, 3 ) ) );
check( '  the new listing is returned with the one it replaced', is_array( $result ) ? array( $result['item_id'], $result['previous_item_id'] ) : $result, array( '226100009520', $item ) );
check( '  the product now points at the new listing', meta( 520, '_ebay_item_id' ), '226100009520' );
check( '  which is Active', meta( 520, '_ebay_listing_status' ), 'Active' );
check( '  ending when eBay says, not when the old one did', meta( 520, '_ebay_end_time' ), '2026-11-11T09:00:00.000Z' );
check( '  and starting when eBay says', meta( 520, '_ebay_start_time' ), '2026-10-12T09:00:00.000Z' );
check( '  the old listing\'s settle mark is gone', meta( 520, '_ebay_stock_settled_at' ), null );
check( '  and its unsold-at-end figure', meta( 520, '_ebay_unsold_at_end' ), null );
check( '  and its sold figure', meta( 520, '_ebay_quantity_sold' ), null );
check( '  and its listed figure', meta( 520, '_ebay_quantity_listed' ), null );
check( '  the Listings row carries the new listing, Active', array( last_row()['ebay_item_id'] ?? null, last_row()['listing_status'] ?? null ), array( '226100009520', 'Active' ) );
check( '  the log says who asked, and the quantity sent', logged( 'Bulk relist: WC #520 relisted on eBay as 226100009520 (was ' . $item . '), quantity 3.' ), true );
check( '  nothing was settled', $GLOBALS['SETTLED'], array() );

// eBay's answer without times: the old end time must not survive, or the
// hourly check marks the fresh listing ended at once.
$ebay          = fresh_shop();
coin( 521 );
$ebay->item    = ebay_says( 'Completed', 3, 0 );
$ebay->relists = relisted_as( '226100009521', null, null );
relist( 521, 'Auto-Relist' );
$start = (string) meta( 521, '_ebay_start_time' );
check( 'with no end time in the answer the old one is removed', meta( 521, '_ebay_end_time' ), null );
check( '  and the start time is now, in eBay\'s format', 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.000Z$/', $start ) && abs( strtotime( $start ) - time() ) < 120, true );
check( '  the log names auto-relist as the source', logged( 'Auto-Relist: WC #521 relisted' ), true );

// A shop that does not track stock: its default quantity, read through the
// real get_export_settings().
$ebay          = fresh_shop( array( 'export_default_quantity' => 2 ) );
$item          = coin( 522, array( '_manage_stock' => 'no', '_stock' => '' ) );
$ebay->item    = ebay_says( 'Completed', 2, 0 );
$ebay->relists = relisted_as( '226100009522' );
relist( 522 );
check( 'an untracked product is relisted at the shop\'s default quantity', $ebay->calls[1] ?? null, array( 'RelistFixedPriceItem', $item, 2 ) );

// An auction is one item, whatever is in stock, and goes through RelistItem.
$ebay          = fresh_shop();
$item          = coin( 523, array( '_ebay_listing_type' => 'Chinese' ) );
$ebay->item    = ebay_says( 'Completed', 1, 0, 'Chinese' );
$ebay->relists = relisted_as( '226100009523' );
check( 'an auction that did not sell is relisted', outcome( relist( 523 ) ), 'relisted' );
check( '  through RelistItem, with no quantity', $ebay->calls[1] ?? null, array( 'RelistItem', $item, null ) );
check( '  and never through the fixed-price call', in_array( 'RelistFixedPriceItem', array_column( $ebay->calls, 0 ), true ), false );

// A variation listing carries its own quantities, so eBay has to say what
// each variation sold before it goes back up. 3.27.2 relisted this one bare,
// and eBay put every variation back at the quantity it was first listed with.
// Here eBay reports the listing's figures and no variations: what sold cannot
// be checked, so nothing is relisted. (Variation listings are relisted in
// their own sections near the end.)
$ebay          = fresh_shop();
$item          = sleeves( 524, array( '_manage_stock' => 'no', '_stock' => '' ) );
sleeve( 525, 524, 'SLV-BLK', 2, array( 'color' => 'Black' ) );
$ebay->item    = ebay_says( 'Completed', 6, 2 );
$ebay->relists = relisted_as( '226100009524' );
$result        = relist( 524 );
check( 'a variable product eBay reports no variations for is not relisted', outcome( $result ), 'relist_unchecked' );
check( '  because their sales could not be checked', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'eBay did not report the variations of listing ' . $item . ', so their sales could not be checked' ), true );
check( '  only GetItem was sent', $ebay->calls, array( array( 'GetItem', $item ) ) );
check( '  the variation is left as it was, and the parent not settled without it', array( (int) meta( 525, '_stock' ), array_column( $GLOBALS['SETTLED'], 0 ) ), array( 2, array( 'settle_variations' ) ) );

echo "\nWHEN EBAY CANNOT BE ASKED, OR SAYS NO\n" . str_repeat( '=', 126 ) . "\n";

// Not knowing whether it sold is not permission to relist it.
$ebay       = fresh_shop();
$item       = coin( 530 );
$ebay->item = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$result     = relist( 530 );
check( 'GetItem failing stops the relist', outcome( $result ), 'relist_unchecked' );
check( '  which is not mistaken for a refusal the shop can ignore', 'relist_refused' !== outcome( $result ), true );
check( '  no relist was sent', $ebay->calls, array( array( 'GetItem', $item ) ) );
check( '  the shop is told what eBay said', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'cURL error 28' ), true );
check( '  the product keeps its old listing', meta( 530, '_ebay_item_id' ), $item );

$ebay       = fresh_shop();
coin( 531 );
$ebay->item = array( 'Ack' => 'Success' );
check( 'an answer with no Item in it stops the relist too', outcome( relist( 531 ) ), 'relist_unchecked' );
check( '  no relist was sent', count( $ebay->calls ), 1 );

$ebay          = fresh_shop();
$item          = coin( 532 );
$ebay->item    = ebay_says( 'Completed', 3, 0 );
$ebay->relists = new WP_Error( 'api_error', 'Item cannot be relisted.' );
$result        = relist( 532 );
check( 'eBay refusing the relist is passed back as it came', outcome( $result ), 'api_error' );
check( '  the product keeps its old listing', meta( 532, '_ebay_item_id' ), $item );
check( '  and is not marked Active', meta( 532, '_ebay_listing_status' ), 'Ended' );
check( '  and no Listings row says otherwise', $GLOBALS['ROWS'], array() );

$ebay          = fresh_shop();
$item          = coin( 533 );
$ebay->item    = ebay_says( 'Completed', 3, 0 );
$ebay->relists = array( 'Ack' => 'Success' );
check( 'an answer with no new Item ID is an error', outcome( relist( 533 ) ), 'no_item_id' );
check( '  the product keeps its old listing', meta( 533, '_ebay_item_id' ), $item );

echo "\nTHE DEALER'S SELECT-ALL: BULK RELIST, THROUGH THE REAL JOB HANDLER\n" . str_repeat( '=', 126 ) . "\n";

// Four ticked on the Ended tab: a coin that sold on eBay and was put back in
// stock, one in the trash, one that ended unsold with three in stock, and an
// auction that did not sell.
$ebay    = fresh_shop();
$sold    = coin( 601, array( '_stock' => '1' ) );
$trashed = coin( 602, array(), 'trash' );
$unsold  = coin( 603 );
$auction = coin( 604, array( '_ebay_listing_type' => 'Chinese', '_stock' => '2' ) );
$ebay->items = array(
	$sold    => ebay_says( 'Completed', 1, 1 ),
	$trashed => ebay_says( 'Completed', 1, 1 ),
	$unsold  => ebay_says( 'Completed', 3, 0 ),
	$auction => ebay_says( 'Completed', 1, 0, 'Chinese' ),
);
$ebay->relists = function ( $item_id ) { return relisted_as( '9' . substr( $item_id, 1 ) ); };

$job    = TCGiant_Sync_Jobs::create_job( 'bulk_relist', array( 601, 602, 603, 604 ) );
$sent   = run_batch( $job );
$done   = (array) TCGiant_Sync_Jobs::get_job( $job );
$errors = implode( "\n", (array) ( $done['errors'] ?? array() ) );

check( 'the batch runs to the end', $sent['data']['status'] ?? null, 'completed' );
check( 'the coin that sold is not relisted', $ebay->relists_of( $sold ), array() );
check( '  its stock is settled from eBay instead', $GLOBALS['SETTLED'][0] ?? null, array( 'apply_ended_listing_stock', 601, 1, 1 ) );
check( 'the coin in the trash is never mentioned to eBay', $ebay->calls_about( $trashed ), array() );
check( 'the unsold coin is relisted at its three, through RelistFixedPriceItem', $ebay->relists_of( $unsold ), array( array( 'RelistFixedPriceItem', $unsold, 3 ) ) );
check( '  and now points at its new listing', meta( 603, '_ebay_item_id' ), '9' . substr( $unsold, 1 ) );
check( 'the auction is relisted through RelistItem', $ebay->relists_of( $auction ), array( array( 'RelistItem', $auction, null ) ) );
check( 'two relisted, two kept, none failed', array( $done['succeeded'] ?? null, $done['kept'] ?? null, $done['failed'] ?? null ), array( 2, 2, 0 ) );
check( '  the shop is told why each was kept', array( false !== strpos( $errors, '#601: not relisted' ), false !== strpos( $errors, '#602: not relisted' ) ), array( true, true ) );

echo "\nTHE DAILY AUTO-RELIST, THROUGH THE REAL SCHEDULER\n" . str_repeat( '=', 126 ) . "\n";

$ebay   = fresh_shop();
$sold   = coin( 611, array( '_stock' => '1' ) );
$unsold = coin( 612 );
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'auto_relist_enabled' => 1 );
$ENDED_ROWS = array(
	array( 'product_id' => '611', 'ebay_item_id' => $sold ),
	array( 'product_id' => '612', 'ebay_item_id' => $unsold ),
);
$ebay->items   = array( $sold => ebay_says( 'Completed', 1, 1 ), $unsold => ebay_says( 'Completed', 3, 0 ) );
$ebay->relists = function ( $item_id ) { return relisted_as( '9' . substr( $item_id, 1 ) ); };

TCGiant_Sync_Cron::instance()->auto_relist_ended_items();

check( 'auto-relist asks eBay about the coin that sold, and stops there', $ebay->calls_about( $sold ), array( array( 'GetItem', $sold ) ) );
check( '  settling its stock from eBay instead', $GLOBALS['SETTLED'][0] ?? null, array( 'apply_ended_listing_stock', 611, 1, 1 ) );
check( 'the unsold coin is relisted with its quantity, 3', $ebay->relists_of( $unsold ), array( array( 'RelistFixedPriceItem', $unsold, 3 ) ) );
check( 'the run says what it did', logged( 'Auto-Relist: Complete. 1 relisted, 1 not relisted' ), true );

echo "\nONE PATH FOR BULK RELIST AND AUTO-RELIST, IN THE SOURCE\n" . str_repeat( '=', 126 ) . "\n";

$exporter_file = $ROOT . '/includes/class-tcgiant-sync-exporter.php';
$jobs_batch    = method_code( $ROOT . '/includes/class-tcgiant-sync-jobs.php', 'ajax_process_batch' );
$bulk_relist   = '';
if ( false !== ( $p = strpos( $jobs_batch, "case 'bulk_relist':" ) ) ) {
	$next        = strpos( $jobs_batch, 'case \'', $p + 1 );
	$bulk_relist = false === $next ? substr( $jobs_batch, $p ) : substr( $jobs_batch, $p, $next - $p );
}
$auto_relist = method_code( $ROOT . '/includes/class-tcgiant-sync-cron.php', 'auto_relist_ended_items' );
$guarded     = method_code( $exporter_file, 'relist_ended_listing' );

check( 'the exporter has the one guarded path', method_exists( 'TCGiant_Sync_Exporter', 'relist_ended_listing' ), true );
check( 'bulk Relist goes through it', 1 === preg_match( '/->\s*relist_ended_listing\s*\(/', $bulk_relist ), true );
check( '  and never calls RelistItem itself', '' !== $bulk_relist ? direct_relist_calls( $bulk_relist ) : null, 0 );
check( '  a refusal is counted as kept, not failed', false !== strpos( $bulk_relist, "'relist_refused'" ) && false !== strpos( $bulk_relist, '$kept++' ), true );
check( 'auto-relist goes through it', 1 === preg_match( '/->\s*relist_ended_listing\s*\(/', $auto_relist ), true );
check( '  and never calls RelistItem itself', '' !== $auto_relist ? direct_relist_calls( $auto_relist ) : null, 0 );

// And nothing else in the plugin goes round it.
$elsewhere = array();
foreach ( array_merge(
	glob( $ROOT . '/includes/*.php' ) ?: array(),
	glob( $ROOT . '/admin/*.php' ) ?: array(),
	glob( $ROOT . '/admin/views/*.php' ) ?: array(),
	array( $ROOT . '/tcgiant-sync.php' )
) as $file ) {
	if ( ! is_file( $file ) ) {
		continue;
	}
	$calls = direct_relist_calls( php_strip_whitespace( $file ) );
	if ( realpath( $file ) === realpath( $exporter_file ) ) {
		$calls -= direct_relist_calls( $guarded );
	}
	if ( $calls > 0 ) {
		$elsewhere[] = basename( $file );
	}
}
check( 'no other file sends RelistItem or RelistFixedPriceItem', $elsewhere, array() );

echo "\nA PUSH THAT FINDS ITS LISTING ENDED ASKS EBAY BEFORE LISTING AGAIN\n" . str_repeat( '=', 126 ) . "\n";

// Read from the method as shipped. Driving do_push() for real needs the whole
// listing builder; what matters here is the order of three calls.
$push    = method_code( $exporter_file, 'do_push' );
$check   = method_code( $exporter_file, 'ended_listing_check' );
$ended   = at( $push, 'is_ended_listing_error(' );
$asked   = at( $push, '->ended_listing_check(' );
$add_fp  = at( $push, '->add_fixed_price_item(' );
$add     = at( $push, '->add_item(' );

check( 'the push still recognises an ended listing', $ended > 0, true );
check( '  and then checks it the way a relist does', $asked > $ended && $ended > 0, true );
check( '  before AddFixedPriceItem', $asked > 0 && $add_fp > $asked, true );
check( '  and before AddItem', $asked > 0 && $add > $asked, true );
check( '  rebuilding the listing if the stock came down', false !== strpos( substr( $push, $asked, max( 0, $add_fp - $asked ) ), 'build_item_xml(' ), true );
check( 'the check asks eBay with GetItem', false !== strpos( $check, '->get_item(' ), true );
check( '  reads how many were listed and how many sold', false !== strpos( $check, "['Quantity']" ) && false !== strpos( $check, "['QuantitySold']" ), true );
check( '  brings them into the stock', false !== strpos( $check, 'apply_ended_listing_stock(' ), true );
check( '  only when this listing is not already settled', false !== strpos( $check, 'listing_already_settled(' ), true );
check( '  refuses a coin the old settle put back after a website sale', false !== strpos( $check, 'restocked_after_website_sale(' ), true );
check( '  and never trashes the product', false === strpos( $check, 'maybe_remove_sold_out(' ), true );

echo "\nWHAT GOES TO EBAY\n" . str_repeat( '=', 126 ) . "\n";

// The real API class, with only the HTTP call captured.
$wire = new TCGiant_Sync_API();
fresh_shop();
use_ebay( $wire );
$ANSWERS['RelistFixedPriceItem'] = '<Ack>Success</Ack><ItemID>226100009540</ItemID><StartTime>2026-10-12T09:00:00.000Z</StartTime><EndTime>2026-11-11T09:00:00.000Z</EndTime>';
$ANSWERS['RelistItem']           = '<Ack>Success</Ack><ItemID>226100009541</ItemID>';

$answer = $wire->relist_fixed_price_item( '226100000540', 3 );
$sent   = parse_wire( $WIRE[0]['body'] ?? '' );
check( 'RelistFixedPriceItem with a quantity is one request', count( $WIRE ), 1 );
check( '  sent as RelistFixedPriceItem', $WIRE[0]['call'] ?? null, 'RelistFixedPriceItem' );
check( '  well-formed XML', null !== $sent, true );
check( '  a RelistFixedPriceItemRequest', wire_root( $sent ), 'RelistFixedPriceItemRequest' );
check( '  in eBay\'s namespace', wire_ns( $sent ), 'urn:ebay:apis:eBLBaseComponents' );
check( '  for the ended listing', wire_text( $sent, 'ItemID' ), '226100000540' );
check( '  with a UUID eBay accepts', 1 === preg_match( '/^[0-9a-f]{32}$/', (string) wire_text( $sent, 'UUID' ) ), true );
check( '  and the quantity, once, inside the Item', array( wire_count( $sent, 'Quantity' ), wire_text( $sent, 'Quantity' ) ), array( 1, '3' ) );
check( '  eBay\'s answer comes back decoded', is_array( $answer ) ? $answer['ItemID'] : $answer, '226100009540' );

$WIRE = array();
$wire->relist_fixed_price_item( '226100000540' );
$sent = parse_wire( $WIRE[0]['body'] ?? '' );
check( 'RelistFixedPriceItem without a quantity is well-formed', null !== $sent, true );
check( '  and carries no Quantity', wire_count( $sent, 'Quantity' ), 0 );
check( '  but still the listing and a UUID', array( wire_text( $sent, 'ItemID' ), 1 === preg_match( '/^[0-9a-f]{32}$/', (string) wire_text( $sent, 'UUID' ) ) ), array( '226100000540', true ) );

$WIRE = array();
$wire->relist_item( '226100000541', 2 );
$sent = parse_wire( $WIRE[0]['body'] ?? '' );
check( 'RelistItem with a quantity is well-formed', null !== $sent, true );
check( '  a RelistItemRequest, sent as RelistItem', array( wire_root( $sent ), $WIRE[0]['call'] ?? null ), array( 'RelistItemRequest', 'RelistItem' ) );
check( '  for the ended listing', wire_text( $sent, 'ItemID' ), '226100000541' );
check( '  with the quantity', array( wire_count( $sent, 'Quantity' ), wire_text( $sent, 'Quantity' ) ), array( 1, '2' ) );

$WIRE = array();
$wire->relist_item( '226100000541' );
$sent = parse_wire( $WIRE[0]['body'] ?? '' );
check( 'RelistItem without a quantity is well-formed', null !== $sent, true );
check( '  and carries no Quantity', wire_count( $sent, 'Quantity' ), 0 );

$WIRE = array();
$wire->relist_item( '226100000541', 1 );
$wire->relist_item( '226100000541', 1 );
check( 'two relists are two UUIDs', count( $WIRE ) === 2 && wire_text( parse_wire( $WIRE[0]['body'] ), 'UUID' ) !== wire_text( parse_wire( $WIRE[1]['body'] ), 'UUID' ), true );

echo "\nEND TO END, THROUGH THE REAL API CLASS\n" . str_repeat( '=', 126 ) . "\n";

// The exporter's choice of call and quantity, all the way to the request.
fresh_shop();
use_ebay( new TCGiant_Sync_API() );
$item = coin( 550 );
$ANSWERS['GetItem']              = '<Ack>Success</Ack><Item><ItemID>' . $item . '</ItemID><ListingType>FixedPriceItem</ListingType><Quantity>3</Quantity><SellingStatus><QuantitySold>0</QuantitySold><ListingStatus>Completed</ListingStatus></SellingStatus></Item>';
$ANSWERS['RelistFixedPriceItem'] = '<Ack>Success</Ack><ItemID>226100009550</ItemID><StartTime>2026-10-12T09:00:00.000Z</StartTime><EndTime>2026-11-11T09:00:00.000Z</EndTime>';
$result = relist( 550 );
$sent   = parse_wire( $WIRE[1]['body'] ?? '' );
check( 'a coin with three in stock is relisted', outcome( $result ), 'relisted' );
check( '  GetItem, then RelistFixedPriceItem, and nothing else', array_column( $WIRE, 'call' ), array( 'GetItem', 'RelistFixedPriceItem' ) );
check( '  the request on the wire carries Quantity 3', null !== $sent ? array( wire_text( $sent, 'ItemID' ), wire_text( $sent, 'Quantity' ) ) : null, array( $item, '3' ) );
check( '  the product holds the new listing and its end', array( meta( 550, '_ebay_item_id' ), meta( 550, '_ebay_end_time' ) ), array( '226100009550', '2026-11-11T09:00:00.000Z' ) );

fresh_shop();
use_ebay( new TCGiant_Sync_API() );
$item = coin( 551, array( '_stock' => '1' ) );
$ANSWERS['GetItem'] = '<Ack>Success</Ack><Item><ItemID>' . $item . '</ItemID><ListingType>FixedPriceItem</ListingType><Quantity>1</Quantity><SellingStatus><QuantitySold>1</QuantitySold><ListingStatus>Completed</ListingStatus></SellingStatus></Item>';
check( 'a sold coin whose stock was put back is not relisted', outcome( relist( 551 ) ), 'relist_refused' );
check( '  only GetItem reached eBay', array_column( $WIRE, 'call' ), array( 'GetItem' ) );

echo "\nA COIN RESTOCKED AFTER ITS SELL-OUT WAS ALREADY SETTLED\n" . str_repeat( '=', 126 ) . "\n";

// These two FAIL on 3.27.0, deliberately.
//
// Three listed, three sold on eBay. The plugin has already settled that: stock
// taken to 0, and eBay's figures and the time recorded against the product
// (_ebay_stock_settled_at, _ebay_quantity_listed, _ebay_quantity_sold). The
// shop then buys five more and sets the stock to 5 - or an eBay order is
// cancelled and WooCommerce puts the stock back. Both are the shop's real
// stock, arriving after the sale was already taken off.
//
// relist_ended_listing() applies the same old sell-out again: the real
// apply_ended_listing_stock() lowers 5 to min(5, 3 - 3) = 0 and marks the
// product out of stock, and maybe_remove_sold_out() trashes it unless it was
// pushed from WooCommerce or removal is off. Setting the stock back and
// pressing Relist again does the same, and so does the daily auto-relist,
// silently - and the push fallback runs the same settle - so no route in the
// plugin can ever list the restock again while the product holds the old
// Item ID.
$ebay = fresh_shop();
$item = coin( 560, array(
	'_stock'                 => '5',
	'_ebay_stock_settled_at' => '2026-10-01 12:00:00',
	'_ebay_quantity_listed'  => 3,
	'_ebay_quantity_sold'    => 3,
) );
$GLOBALS['META'][560]['_ebay_stock_settled_item'] = $item;
$ebay->item    = ebay_says( 'Completed', 3, 3 );
$ebay->relists = relisted_as( '226100009560' );
relist( 560 );
check( 'a sell-out already settled is not applied again over the restock of 5', $GLOBALS['SETTLED'], array() );
check( '  and the five the shop now holds are relisted', $ebay->relists_of( $item ), array( array( 'RelistFixedPriceItem', $item, 5 ) ) );

// Settled before 3.27.0, which did not note the listing. That mark could
// belong to an older listing, so it is not trusted: the first relist
// brings this listing's sales in and refuses. It is the safe side, and it
// is recoverable - the shop enters its stock again, the listing is now on
// record as settled, and the next relist goes ahead.
$ebay = fresh_shop();
$item = coin( 561, array(
	'_stock'                 => '5',
	'_ebay_stock_settled_at' => '2026-10-01 12:00:00',
) );
$ebay->item    = ebay_says( 'Completed', 3, 3 );
$ebay->relists = relisted_as( '226100009561' );
check( 'an older settle mark is not trusted: the first relist refuses', outcome( relist( 561 ) ), 'relist_refused' );
check( '  having brought the sell-out in', (int) meta( 561, '_stock' ), 0 );
$GLOBALS['META'][561]['_stock']        = '5';
$GLOBALS['META'][561]['_stock_status'] = 'instock';
check( '  the shop enters its stock again, and the relist goes ahead', outcome( relist( 561 ) ), 'relisted' );
check( '  at the five it holds', $ebay->relists_of( $item ), array( array( 'RelistFixedPriceItem', $item, 5 ) ) );

echo "\nA COIN THE OLD SETTLE PUT BACK AFTER A WEBSITE SALE\n" . str_repeat( '=', 126 ) . "\n";

// Sold on the website, its listing ended by the plugin (eBay: 1 listed, 0
// sold), and put back in stock by the settle before 3.27.0. eBay says nothing
// sold, so no sold-out check can catch it - and the daily auto-relist would
// list it again before the shop ever opened Stock Review.
$ebay = fresh_shop();
$item = coin( 562, array( '_stock' => '1', '_ebay_stock_settled_at' => '2026-10-10 12:00:00' ) );
$GLOBALS['RESTOCKED'] = array( 562 => 812 );
$ebay->item    = ebay_says( 'Completed', 1, 0 );
$ebay->relists = relisted_as( '226100009562' );
$result        = relist( 562 );
check( 'it is not relisted', outcome( $result ), 'relist_refused' );
check( '  the refusal names the website order', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'order #812' ), true );
check( '  and points to Stock Review', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'Stock Review' ), true );
check( '  nothing relisted', $ebay->relists_of( $item ), array() );
check( '  its stock is left for the shop to judge', (int) meta( 562, '_stock' ), 1 );
$GLOBALS['RESTOCKED'] = array();

echo "\nNOTHING SOLD ON EBAY, NOTHING TO BRING IN\n" . str_repeat( '=', 126 ) . "\n";

// A coin sold on the website; the plugin ended its listing (eBay: 1 listed,
// 0 sold). The shop restocks ten and relists before the sync gets there. There
// is no eBay sale to bring in, so the ten are listed - not cut to eBay's 1.
$ebay = fresh_shop();
$item = coin( 570, array( '_stock' => '10' ) );
$ebay->item    = ebay_says( 'Completed', 1, 0 );
$ebay->relists = relisted_as( '226100009570' );
check( 'no eBay sale: the restock of ten is relisted', outcome( relist( 570 ) ), 'relisted' );
check( '  at ten', $ebay->relists_of( $item ), array( array( 'RelistFixedPriceItem', $item, 10 ) ) );
check( '  nothing settled over it', $GLOBALS['SETTLED'], array() );

echo "\nA SALE THAT CANNOT BE BROUGHT IN STOPS THE RELIST\n" . str_repeat( '=', 126 ) . "\n";

// eBay sold one, but the stock write did not take - another plugin holding it.
$ebay = fresh_shop();
$item = coin( 571, array( '_stock' => '1' ) );
$ebay->item    = ebay_says( 'Completed', 1, 1 );
$ebay->relists = relisted_as( '226100009571' );
$GLOBALS['SETTLE_FAILS'] = true;
check( 'it is not relisted', outcome( relist( 571 ) ), 'relist_unchecked' );
check( '  nothing sent to eBay', $ebay->relists_of( $item ), array() );
$GLOBALS['SETTLE_FAILS'] = false;

echo "\nA LISTING EBAY HAS ALREADY RELISTED IS NOT RELISTED AGAIN (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// These FAIL on 3.27.2, deliberately.
//
// The seller relisted a coin's ended listing on eBay themselves, and eBay notes
// the new listing on the old one, as RelistedItemID. 3.27.2 looked only at the
// old listing - ended, nothing sold - and relisted it again. eBay accepts a
// second relist of one listing and simply makes another, so a second copy of
// the coin went up beside the seller's; and if the seller's had sold, the old
// listing's figures still said nothing had.

/** GetItem's answer for one listing in particular, with anything else given. */
function listing_says( $item_id, $status, $listed, $sold, array $extra = array() ) {
	$answer                   = ebay_says( $status, $listed, $sold );
	$answer['Item']['ItemID'] = $item_id;
	$answer['Item']           = array_replace_recursive( $answer['Item'], $extra );
	return $answer;
}
/** What eBay notes on an old listing once it has been relisted. */
function relisted_on_ebay_as( $new_id ) {
	return array( 'ListingDetails' => array( 'RelistedItemID' => $new_id ) );
}
function relist_calls( $ebay ) {
	return array_values( array_filter( $ebay->calls, function ( $c ) { return 0 === strpos( $c[0], 'Relist' ); } ) );
}
function log_lines_naming( $needle ) {
	return array_values( array_filter( $GLOBALS['LOG'], function ( $line ) use ( $needle ) { return false !== strpos( $line[1], $needle ); } ) );
}

// The relist is live.
$ebay        = fresh_shop();
$item        = coin( 580 );
$relist_id   = '226100008580';
// 3.27.8: the product's record of the description it last sent, to this listing.
$GLOBALS['META'][580]['_tcgiant_tpl_item'] = $item;
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Active', 3, 0, array( 'ListingDetails' => array( 'StartTime' => '2026-10-10T09:00:00.000Z', 'EndTime' => '2026-11-09T09:00:00.000Z' ) ) ),
);
$ebay->relists = relisted_as( '226100099580' );
$result        = relist( 580 );
check( 'relisted on eBay already, and live there: not relisted again', outcome( $result ), 'relist_refused' );
check( '  the shop is told where it went', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'already relisted on eBay as ' . $relist_id ), true );
check( '  no RelistItem or RelistFixedPriceItem sent at all', relist_calls( $ebay ), array() );
check( '  eBay was asked about the old listing, then the relist', $ebay->calls, array( array( 'GetItem', $item ), array( 'GetItem', $relist_id ) ) );
check( '  the product now follows the relist', meta( 580, '_ebay_item_id' ), $relist_id );
check( '  which is live', meta( 580, '_ebay_listing_status' ), 'Active' );
check( '  ending when the relist does', meta( 580, '_ebay_end_time' ), '2026-11-09T09:00:00.000Z' );
check( '  remembering the listing it followed', meta( 580, '_ebay_previous_item_id' ), $item );
check( '  and the record of the description sent moves to the relist, which kept it (3.27.8)', meta( 580, '_tcgiant_tpl_item' ), $relist_id );
check( '  the Listings row moved with it', array( last_row()['ebay_item_id'] ?? null, last_row()['listing_status'] ?? null ), array( $relist_id, 'Active' ) );
check( '  its stock is not touched', $GLOBALS['SETTLED'], array() );

// The relist sold. Its figures count, not the old listing's.
$ebay        = fresh_shop();
$item        = coin( 581, array( '_stock' => '1', '_tcgiant_tpl_item' => '226100000999' ) ); // a record of a send to another listing
$relist_id   = '226100008581';
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 1, 0, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Completed', 1, 1 ),
);
$ebay->relists = relisted_as( '226100099581' );
$result        = relist( 581 );
check( 'the relist sold: nothing is relisted', outcome( $result ), 'relist_refused' );
check( '  no relist sent', relist_calls( $ebay ), array() );
check( '  the stock is settled from the relist\'s figures', $GLOBALS['SETTLED'], array( array( 'apply_ended_listing_stock', 581, 1, 1 ) ) );
check( '  to 0', (int) meta( 581, '_stock' ), 0 );
check( '  the shop is told it sold', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), '1 listed, 1 sold' ), true );
check( '  and the product follows the relist that sold', meta( 581, '_ebay_item_id' ), $relist_id );
check( '  a record of a description sent to another listing is not moved to the relist (3.27.8)', meta( 581, '_tcgiant_tpl_item' ), '226100000999' );

// The relist ended unsold: that is the listing to relist, through the real
// API class, so what is checked is the request itself.
fresh_shop();
use_ebay( new TCGiant_Sync_API() );
$item      = coin( 582 );
$relist_id = '226100008582';
$ANSWERS['GetItem:' . $item]      = '<Ack>Success</Ack><Item><ItemID>' . $item . '</ItemID><ListingType>FixedPriceItem</ListingType><Quantity>1</Quantity><ListingDetails><RelistedItemID>' . $relist_id . '</RelistedItemID></ListingDetails><SellingStatus><QuantitySold>0</QuantitySold><ListingStatus>Completed</ListingStatus></SellingStatus></Item>';
$ANSWERS['GetItem:' . $relist_id] = '<Ack>Success</Ack><Item><ItemID>' . $relist_id . '</ItemID><ListingType>FixedPriceItem</ListingType><Quantity>1</Quantity><SellingStatus><QuantitySold>0</QuantitySold><ListingStatus>Completed</ListingStatus></SellingStatus></Item>';
$ANSWERS['RelistFixedPriceItem']  = '<Ack>Success</Ack><ItemID>226100009582</ItemID><StartTime>2026-10-12T09:00:00.000Z</StartTime><EndTime>2026-11-11T09:00:00.000Z</EndTime>';
$result = relist( 582 );
$sent   = parse_wire( $WIRE[2]['body'] ?? '' );
check( 'the relist ended unsold: it is relisted', outcome( $result ), 'relisted' );
check( '  GetItem for each, then RelistFixedPriceItem', array_column( $WIRE, 'call' ), array( 'GetItem', 'GetItem', 'RelistFixedPriceItem' ) );
check( '  the request names the relist, not the old listing, with WooCommerce\'s 3', null !== $sent ? array( wire_text( $sent, 'ItemID' ), wire_text( $sent, 'Quantity' ) ) : null, array( $relist_id, '3' ) );
check( '  the product holds the newest listing', meta( 582, '_ebay_item_id' ), '226100009582' );
check( '  and remembers the one it replaced', meta( 582, '_ebay_previous_item_id' ), $relist_id );

// The relist already has a product of its own - imported before 3.28.0, say.
$ebay        = fresh_shop();
$item        = coin( 583 );
$relist_id   = coin( 584, array( '_ebay_item_id' => '226100008583', '_ebay_listing_status' => 'Active' ) );
$ebay->items = array( $item => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( $relist_id ) ) );
$ebay->relists = relisted_as( '226100099583' );
$before        = $GLOBALS['META'][583];
$result        = relist( 583 );
check( 'the relist has a product of its own: refused', outcome( $result ), 'relist_refused' );
check( '  naming that product', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'which WC #584 follows' ), true );
check( '  eBay asked only about the old listing, and nothing relisted', $ebay->calls, array( array( 'GetItem', $item ) ) );
check( '  nothing written to the product but a note of the pair, for Auto-Relist', $GLOBALS['META'][583], $before + array( '_ebay_relist_held' => $item . '>226100008583' ) );
check( '  nor to the Listings table', $GLOBALS['ROWS'], array() );
check( '  the log says so, naming both', logged( 'eBay item ' . $item . ' was relisted as 226100008583, which WC #584 follows' ), true );
relist( 583 );
check( '  asked again, it is not said again', count( log_lines_naming( 'which WC #584 follows' ) ), 1 );

// A relist that was relisted in turn: followed to the newest.
$ebay        = fresh_shop();
$item        = coin( 585 );
$ebay->items = array(
	$item          => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( '226100008585' ) ),
	'226100008585' => listing_says( '226100008585', 'Completed', 3, 0, relisted_on_ebay_as( '226100009585' ) ),
	'226100009585' => listing_says( '226100009585', 'Completed', 3, 0 ),
);
$ebay->relists = relisted_as( '226100099585' );
relist( 585 );
check( 'relisted twice over on eBay: the newest is what is relisted', relist_calls( $ebay ), array( array( 'RelistFixedPriceItem', '226100009585', 3 ) ) );

// Relisted more often than is followed: listing anything could add one more
// copy beside the ones already up.
// Every listing in the chain can be read, and the last has ended unsold, so
// only the limit stops it.
$ebay        = fresh_shop();
$item        = coin( 586 );
$ebay->items = array( $item => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( '226100001586' ) ) );
foreach ( array( '226100001586', '226100002586', '226100003586' ) as $n => $id ) {
	$ebay->items[ $id ] = listing_says( $id, 'Completed', 3, 0, relisted_on_ebay_as( '22610000' . ( $n + 2 ) . '586' ) );
}
$ebay->items['226100004586'] = listing_says( '226100004586', 'Completed', 3, 0 );
$ebay->relists = relisted_as( '226100099586' );
$before = $GLOBALS['META'][586];
$result = relist( 586 );
check( 'relisted more than three times over: not relisted', outcome( $result ), 'relist_unchecked' );
check( '  for the limit, not an unreadable listing', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'relisted more than 3 times over' ), true );
check( '  eBay asked about the old listing and three relists, no more', array_column( $ebay->calls, 1 ), array( $item, '226100001586', '226100002586', '226100003586' ) );
check( '  nothing sent', relist_calls( $ebay ), array() );
check( '  nothing written', $GLOBALS['META'][586], $before );

// eBay's records loop: the relist names the old listing as its own relist.
$ebay        = fresh_shop();
$item        = coin( 589 );
$ebay->items = array(
	$item          => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( '226100008589' ) ),
	'226100008589' => listing_says( '226100008589', 'Completed', 3, 0, relisted_on_ebay_as( $item ) ),
);
$ebay->relists = relisted_as( '226100099589' );
relist( 589 );
check( 'a relist eBay says was relisted as the old listing: each asked about once', array_column( array_filter( $ebay->calls, function ( $c ) { return 'GetItem' === $c[0]; } ), 1 ), array( $item, '226100008589' ) );

// The relist eBay names cannot be read.
$ebay        = fresh_shop();
$item        = coin( 587 );
$ebay->items = array(
	$item          => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( '226100008587' ) ),
	'226100008587' => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
);
$before = $GLOBALS['META'][587];
$result = relist( 587 );
check( 'the relist cannot be read: not relisted, and not taken for a refusal', outcome( $result ), 'relist_unchecked' );
check( '  the shop is told which listing and why', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), '226100008587 could not be read (cURL error 28' ), true );
check( '  nothing sent', relist_calls( $ebay ), array() );
check( '  nothing written', $GLOBALS['META'][587], $before );

// A live relist eBay gives no end for: the old end goes, rather than stay to
// have the hourly check mark the relist Ended on the old listing's date.
$ebay        = fresh_shop();
$item        = coin( 588 );
$ebay->items = array(
	$item          => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( '226100008588' ) ),
	'226100008588' => listing_says( '226100008588', 'Active', 3, 0 ),
);
relist( 588 );
check( 'a live relist with no end time: the old end time is removed', meta( 588, '_ebay_end_time' ), null );

// A coin imported with no SKU of its own carries its item number as its SKU.
// Relisted from here, that SKU moves to the new number, as it does when the
// product follows a relist made on eBay. Left behind, it read as goods of the
// shop's own two relists later, and a relist the seller gave a custom label
// was taken for another item - so Auto-Relist put up a second listing of the
// same coin.
$ebay          = fresh_shop();
$item          = coin( 661 );
$GLOBALS['META'][661]['_sku']      = $item;
$GLOBALS['META'][661]['_ebay_sku'] = $item;
$ebay->item    = listing_says( $item, 'Completed', 3, 0 );
$ebay->relists = relisted_as( '226100009661' );
relist( 661 );
check( 'relisted from here: the item-number SKU moves to the new listing', array( meta( 661, '_ebay_item_id' ), meta( 661, '_sku' ), meta( 661, '_ebay_sku' ) ), array( '226100009661', '226100009661', '226100009661' ) );

echo "\nA PUSH TO A LISTING EBAY HAS ALREADY RELISTED REVISES THE RELIST (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// These FAIL on 3.27.2, deliberately.
//
// A push revises the listing the product has. eBay says it has ended - and,
// asked, that it was relisted as a listing that is live. 3.27.2 created a
// brand new listing instead: a second copy beside the seller's relist.
//
// Driven through the real do_push(), with the listing built by the real
// builder from the shop's export settings.
function push( $product_id ) {
	$do_push = new ReflectionMethod( 'TCGiant_Sync_Exporter', 'do_push' );
	if ( PHP_VERSION_ID < 80100 ) {
		$do_push->setAccessible( true );
	}
	return $do_push->invoke( TCGiant_Sync_Exporter::instance(), wc_get_product( $product_id ) );
}

$ebay      = fresh_shop( array(
	'export_category_id'        => '9355',
	'export_location'           => 'Austin, TX',
	'export_postal_code'        => '78701',
	'export_fulfillment_policy' => 'F1',
	'export_return_policy'      => 'R1',
	'export_payment_policy'     => 'P1',
) );
$item      = coin( 590 );
$relist_id = '226100008590';
$week_end  = gmdate( 'Y-m-d\TH:i:s.000\Z', time() + 7 * DAY_IN_SECONDS );
$GLOBALS['META'][590]['_sku'] = $item; // imported with no SKU of its own
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Active', 3, 0, array( 'ListingDuration' => 'Days_7', 'ListingDetails' => array( 'StartTime' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - DAY_IN_SECONDS ), 'EndTime' => $week_end ) ) ),
);
$ebay->revises[ $item ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$result  = push( 590 );
$revises = array_keys( array_filter( $ebay->calls, function ( $c ) { return 0 === strpos( $c[0], 'Revise' ); } ) );
check( 'the push goes through', is_wp_error( $result ) ? $result->get_error_message() : 'pushed', 'pushed' );
check( '  as an update of the relist', is_array( $result ) ? array( $result['item_id'], $result['action'] ) : null, array( $relist_id, 'updated' ) );
check( '  no AddItem or AddFixedPriceItem', count( array_filter( $ebay->calls, function ( $c ) { return 0 === strpos( $c[0], 'Add' ); } ) ), 0 );
check( '  revised: the old listing, refused, then the relist', array_map( function ( $i ) use ( $ebay ) { return $ebay->calls[ $i ]; }, $revises ), array( array( 'ReviseFixedPriceItem', $item ), array( 'ReviseFixedPriceItem', $relist_id ) ) );
check( '  the product follows the relist', meta( 590, '_ebay_item_id' ), $relist_id );
check( '  its SKU, the old item number, moved with it', meta( 590, '_sku' ), $relist_id );
check( '  and the relist was sent that SKU, not the old one', false !== strpos( $ebay->sent[ end( $revises ) ] ?? '', '<SKU>' . $relist_id . '</SKU>' ), true );
// 3.27.8: a push that eBay accepted says which description it sent, so that
// process_single_push() records it on the product (record_description_sent()).
preg_match( '#<Description><!\[CDATA\[(.*?)\]\]></Description>#s', (string) ( $ebay->sent[ end( $revises ) ] ?? '' ), $tcg_desc );
check( '  and says which description it sent the relist, for the product\'s record of it', array( is_array( $result ) && isset( $result['desc']['hash'] ), ( $result['desc']['hash'] ?? '' ) === sha1( str_replace( ']]]]><![CDATA[>', ']]>', $tcg_desc[1] ?? 'none' ) ) ), array( true, true ) );
check( '  its end is the relist\'s, a week away', meta( 590, '_ebay_end_time' ), $week_end );

// The hourly check, an hour on, looks for products whose recorded end has
// passed. The relist runs a week; the old listing's end, had it been kept, is
// long gone, and a seven-day listing whose end has passed is marked Ended
// without asking.
coin( 591, array( '_ebay_listing_status' => 'Active', '_ebay_listing_duration' => 'Days_7' ) );
$ebay->items['226100000591'] = listing_says( '226100000591', 'Completed', 3, 0 );
TCGiant_Sync_Cron::instance()->check_ended_listings();
check( 'the hourly check leaves the product on the relist Active', meta( 590, '_ebay_listing_status' ), 'Active' );
check( '  and does not ask eBay about it', $ebay->calls_about( $relist_id ), array( array( 'GetItem', $relist_id ), array( 'ReviseFixedPriceItem', $relist_id ) ) );
check( '  while a seven-day listing whose end really has passed is marked Ended', meta( 591, '_ebay_listing_status' ), 'Ended' );

echo "\nA PUSH TO A LISTING EBAY RELISTED: THE RELIST SOLD, ENDED UNSOLD, OR CANNOT BE ASKED\n" . str_repeat( '=', 126 ) . "\n";

$push_settings = array(
	'export_category_id'        => '9355',
	'export_location'           => 'Austin, TX',
	'export_postal_code'        => '78701',
	'export_fulfillment_policy' => 'F1',
	'export_return_policy'      => 'R1',
	'export_payment_policy'     => 'P1',
);
function adds_of( $ebay ) {
	return array_values( array_filter( $ebay->calls, function ( $c ) { return 0 === strpos( $c[0], 'Add' ); } ) );
}
function revises_of( $ebay ) {
	return array_values( array_filter( $ebay->calls, function ( $c ) { return 0 === strpos( $c[0], 'Revise' ); } ) );
}

// The relist sold 1 of 1. The old listing's figures say nothing sold.
$ebay        = fresh_shop( $push_settings );
$item        = coin( 592, array( '_stock' => '1' ) );
$relist_id   = '226100008592';
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 1, 0, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Completed', 1, 1 ),
);
$ebay->revises[ $item ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$result = push( 592 );
check( 'push: the relist sold - refused', is_wp_error( $result ) ? $result->get_error_code() : 'pushed', 'relist_refused' );
check( '  no AddItem or AddFixedPriceItem', adds_of( $ebay ), array() );
check( '  stock settled from the relist\'s figures', $GLOBALS['SETTLED'], array( array( 'apply_ended_listing_stock', 592, 1, 1 ) ) );
check( '  to 0', (int) meta( 592, '_stock' ), 0 );
check( '  the product follows the relist that sold', meta( 592, '_ebay_item_id' ), $relist_id );

// The relist ended unsold: a new listing goes up for it.
$ebay        = fresh_shop( $push_settings );
$item        = coin( 593 );
$relist_id   = '226100008593';
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Completed', 3, 0 ),
);
$ebay->revises[ $item ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$result = push( 593 );
check( 'push: the relist ended unsold - a new listing', is_array( $result ) ? array( $result['action'], $result['previous_item_id'] ) : ( is_wp_error( $result ) ? $result->get_error_message() : $result ), array( 'relisted', $relist_id ) );
check( '  one Add call', count( adds_of( $ebay ) ), 1 );
check( '  nothing settled (nothing sold)', $GLOBALS['SETTLED'], array() );

// GetItem on the ended listing fails.
$ebay        = fresh_shop( $push_settings );
$item        = coin( 594 );
$ebay->items = array( $item => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
$ebay->revises[ $item ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$before = $GLOBALS['META'][594];
try {
	$result = push( 594 );
	$code   = is_wp_error( $result ) ? $result->get_error_code() : 'pushed';
} catch ( Throwable $e ) {
	$code = 'threw ' . get_class( $e ) . ': ' . $e->getMessage();
}
check( 'push: GetItem fails - relist_unchecked', $code, 'relist_unchecked' );
check( '  no Add call', adds_of( $ebay ), array() );
check( '  nothing written', $GLOBALS['META'][594], $before );

echo "\nTHE OLD LISTING'S OWN SALES COME IN BEFORE ITS RELIST IS FOLLOWED (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// The coin sold on eBay - its only one - and the delta has not reached the
// sale yet. The seller used Relist on the sold listing to put up the next
// copy. A price push of the sold coin used to follow on to the relist and
// revise it with this coin's title, pictures and quantity, and the sale was
// never brought in: the sold coin stayed for sale here, following the next
// copy's listing. The sale is brought in first, and that is the end of it.
$ebay        = fresh_shop( $push_settings );
$item        = coin( 620, array( '_stock' => '1' ) );
$relist_id   = '226100008620';
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 1, 1, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Active', 1, 0 ),
);
$ebay->revises[ $item ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$result = push( 620 );
check( 'push of a coin that sold, its listing relisted for the next copy: refused, sold out', is_wp_error( $result ) ? $result->get_error_code() : 'pushed', 'relist_refused' );
check( '  the sale is brought in: stock 0', array( $GLOBALS['SETTLED'][0] ?? null, (int) meta( 620, '_stock' ) ), array( array( 'apply_ended_listing_stock', 620, 1, 1 ), 0 ) );
check( '  the next copy\'s listing is not touched', $ebay->calls_about( $relist_id ), array() );
check( '  and the product stays on the listing that sold', meta( 620, '_ebay_item_id' ), $item );

// Five listed, two sold and not brought in yet; the relist offers the other
// three. The push sent WooCommerce's 5 to the relist: two oversold.
$ebay        = fresh_shop( $push_settings );
$item        = coin( 621, array( '_stock' => '5' ) );
$relist_id   = '226100008621';
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 5, 2, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Active', 3, 0 ),
);
$ebay->revises[ $item ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$result  = push( 621 );
$revised = revises_of( $ebay );
check( 'push: five listed, two sold, relisted with three - the relist is revised', is_array( $result ) ? array( $result['item_id'], $result['action'] ) : $result, array( $relist_id, 'updated' ) );
check( '  with 3, not 5', false !== strpos( $ebay->sent[ array_search( array( 'ReviseFixedPriceItem', $relist_id ), $ebay->calls, true ) ] ?? '', '<Quantity>3</Quantity>' ), true );

// The same, relisted from the Listings screen: the relist ended unsold, and
// is relisted with what is really left.
$ebay        = fresh_shop();
$item        = coin( 622, array( '_stock' => '5' ) );
$relist_id   = '226100008622';
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 5, 2, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Completed', 3, 0 ),
);
$ebay->relists = relisted_as( '226100099622' );
relist( 622 );
check( 'relist: the old listing\'s two sales come in first, so the relist goes up with 3', relist_calls( $ebay ), array( array( 'RelistFixedPriceItem', $relist_id, 3 ) ) );

echo "\nA COIN THE OLD SETTLE PUT BACK, THAT THE SELLER ALSO RELISTED ON EBAY (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// These FAIL before the item 8 review, deliberately.
//
// Sold on the website; the plugin ended its listing (eBay: 1 listed, 0 sold);
// the settle before 3.27.0 put it back in stock, so Stock Review lists it.
// Then the seller relisted everything on eBay's Unsold list. Moving on to the
// relist forgot the settle this is known by - and with it the refusal - so
// Auto-Relist relisted a coin a website buyer already has. It is refused
// before anything moves, and stays on Stock Review.
foreach ( array( 'Completed' => 'ended unsold', 'Active' => 'still live' ) as $relist_status => $how ) {
	$ebay      = fresh_shop();
	$id        = 'Active' === $relist_status ? 624 : 623;
	$item      = coin( $id, array( '_stock' => '1', '_ebay_stock_settled_at' => '2026-10-10 12:00:00' ) );
	$relist_id = '22610000' . $id . '0';
	$GLOBALS['RESTOCKED'] = array( $id => 4711 );
	$ebay->items = array(
		$item      => listing_says( $item, 'Completed', 1, 0, relisted_on_ebay_as( $relist_id ) ),
		$relist_id => listing_says( $relist_id, $relist_status, 1, 0 ),
	);
	$ebay->relists = relisted_as( '226100099' . $id );
	$result        = relist( $id, 'Auto-Relist' );
	check( 'relisted on eBay, the relist ' . $how . ': refused, naming the order', array( outcome( $result ), is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'order #4711' ) ), array( 'relist_refused', true ) );
	check( '  nothing relisted', relist_calls( $ebay ), array() );
	check( '  still on its old listing, still on Stock Review', array( meta( $id, '_ebay_item_id' ), TCGiant_Sync_Inventory::restocked_after_website_sale( $id ) ), array( $item, 4711 ) );
}

// A push of it, the relist live: refused the same way, before anything moves.
$ebay        = fresh_shop( $push_settings );
$item        = coin( 625, array( '_stock' => '1', '_ebay_stock_settled_at' => '2026-10-10 12:00:00' ) );
$relist_id   = '226100008625';
$GLOBALS['RESTOCKED'] = array( 625 => 4712 );
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 1, 0, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Active', 1, 0 ),
);
$ebay->revises[ $item ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$result = push( 625 );
check( 'a push of it: refused, naming the order', array( is_wp_error( $result ) ? $result->get_error_code() : 'pushed', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'order #4712' ) ), array( 'relist_refused', true ) );
check( '  the relist is not revised, and nothing is added', array( $ebay->calls_about( $relist_id ), adds_of( $ebay ) ), array( array(), array() ) );
check( '  still on its old listing', meta( 625, '_ebay_item_id' ), $item );

// Once the shop has looked at it on Stock Review and kept its stock, the
// relist is followed and relisted, as for any other.
$ebay      = fresh_shop();
$item      = coin( 626, array( '_stock' => '1', '_ebay_stock_settled_at' => '2026-10-10 12:00:00', '_ebay_restock_reviewed' => 'kept' ) );
$relist_id = '226100008626';
$GLOBALS['RESTOCKED'] = array( 626 => 4713 );
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 1, 0, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Completed', 1, 0 ),
);
$ebay->relists = relisted_as( '226100099626' );
relist( 626 );
check( 'reviewed and kept: the relist is followed and relisted', relist_calls( $ebay ), array( array( 'RelistFixedPriceItem', $relist_id, 1 ) ) );
$GLOBALS['RESTOCKED'] = array();

echo "\nA SINGLE ITEM THAT SOLD IS NOT FOLLOWED ON TO ITS RELIST (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// The coin sold on eBay and that is settled; the shop has another copy and
// set the stock to 1. The seller had relisted the sold listing for a copy of
// their own - the next coin - which no product here follows. Following it
// would put this coin's title and pictures on that coin's listing, as the
// import will not; this coin is listed again on its own, as before 3.28.0.
$ebay      = fresh_shop();
$item      = coin( 627, array( '_stock' => '1', '_ebay_stock_settled_at' => '2026-10-10 12:00:00', '_ebay_stock_settled_item' => '226100000627' ) );
$relist_id = '226100008627';
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 1, 1, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Completed', 1, 0 ),
);
$ebay->relists = relisted_as( '226100099627' );
relist( 627 );
check( 'sold its only one, restocked: relisted itself, not on to the next copy', array( relist_calls( $ebay ), $GLOBALS['FOLLOWED'] ), array( array( array( 'RelistFixedPriceItem', $item, 1 ) ), array() ) );
check( '  eBay not asked about the next copy', $ebay->calls_about( $relist_id ), array() );

// A relist that was relisted in turn, where the relist is the one that sold:
// followed as far as that, and no further.
$ebay      = fresh_shop();
$item      = coin( 628, array( '_stock' => '1' ) );
$ebay->items = array(
	$item          => listing_says( $item, 'Completed', 1, 0, relisted_on_ebay_as( '226100008628' ) ),
	'226100008628' => listing_says( '226100008628', 'Completed', 1, 1, relisted_on_ebay_as( '226100009628' ) ),
);
$result = relist( 628 );
check( 'relisted, and the relist sold its only one: followed that far, and refused as sold out', array( outcome( $result ), meta( 628, '_ebay_item_id' ), (int) meta( 628, '_stock' ) ), array( 'relist_refused', '226100008628', 0 ) );
check( '  the next copy after it not asked about', $ebay->calls_about( '226100009628' ), array() );

echo "\nAUTO-RELIST REMEMBERS A RELIST ANOTHER PRODUCT FOLLOWS (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// An old duplicate pair: this product on the old listing, another on eBay's
// relist of it. Auto-Relist asked eBay about the old listing every day and
// was refused every day, logging "no stock, sold out on eBay, or already
// live" - none of which was the reason. Now it is said once, and not asked
// again while the other product has the relist.
$ebay   = fresh_shop();
$old    = coin( 630 );
$other  = coin( 631, array( '_ebay_item_id' => '226100008630', '_ebay_listing_status' => 'Active' ) );
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'auto_relist_enabled' => 1 );
$ENDED_ROWS  = array( array( 'product_id' => '630', 'ebay_item_id' => $old ) );
$ebay->items = array( $old => listing_says( $old, 'Completed', 3, 0, relisted_on_ebay_as( '226100008630' ) ) );
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
check( 'two days of Auto-Relist: eBay asked once', $ebay->calls, array( array( 'GetItem', $old ) ) );
check( '  the reason is said once, naming the other product', count( log_lines_naming( 'which WC #631 follows' ) ), 1 );
check( '  nothing relisted', relist_calls( $ebay ), array() );
// The other product lets go of the relist: asked again.
$GLOBALS['META'][631]['_ebay_item_id'] = '226100009631';
$ebay->relists = relisted_as( '226100099630' );
$ebay->items['226100008630'] = listing_says( '226100008630', 'Completed', 3, 0 );
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
check( 'once nothing else has the relist, it is asked about again, and followed', relist_calls( $ebay ), array( array( 'RelistFixedPriceItem', '226100008630', 3 ) ) );

echo "\nA RELIST EDITED INTO ANOTHER ITEM IS NOT FOLLOWED (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// These FAIL before the third review, deliberately.
//
// The coin shop listed coin A from WooCommerce, SKU COIN-A. The listing ended
// unsold, and in Seller Hub the seller pressed Relist on it and edited the
// relist into a different coin, B - title, photos, custom label COIN-B. eBay
// still notes the relist on the old listing. The import already took a
// relist carrying another SKU for another item; Auto-Relist, bulk Relist and
// a push followed eBay's note regardless. Coin A's product took over coin B's
// listing, so the next import wrote coin B over it, and a push revised coin
// B's live listing with coin A's title and pictures. Now the relist is judged
// by the import's own rule, and coin A is listed on its own, as before 3.28.0.

/** The log line that says a relist is another item, as the exporter words it. */
function other_item_line( $source, $relist_id, $from_id, $what, $product_id, $held ) {
	return sprintf( '%1$s: eBay listing %2$s is a relist of %3$s, but its %4$s is not WC #%5$d\'s "%6$s", so it is another item and WC #%5$d is not moved on to it.', $source, $relist_id, $from_id, $what, $product_id, $held );
}

// Bulk Relist, through the real job handler. Coin B's listing is live, and no
// product here follows it yet - an export-only shop, say.
$ebay        = fresh_shop();
$item        = coin( 650, array( '_sku' => 'COIN-A' ) );
$relist_id   = '226100008650';
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 3, 0, array( 'SKU' => 'COIN-A' ) + relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Active', 1, 0, array( 'SKU' => 'COIN-B', 'Title' => 'Coin B, 1909-S' ) ),
);
$ebay->relists = relisted_as( '226100099650' );
$job  = TCGiant_Sync_Jobs::create_job( 'bulk_relist', array( 650 ) );
run_batch( $job );
$done = (array) TCGiant_Sync_Jobs::get_job( $job );
check( 'relisted on eBay as coin B (SKU COIN-B): bulk Relist relists coin A itself', array( $done['succeeded'] ?? null, $done['kept'] ?? null, $done['failed'] ?? null ), array( 1, 0, 0 ) );
check( '  GetItem for each, then RelistFixedPriceItem naming coin A\'s own listing, at its 3', $ebay->calls, array( array( 'GetItem', $item ), array( 'GetItem', $relist_id ), array( 'RelistFixedPriceItem', $item, 3 ) ) );
check( '  the product is not moved on to coin B\'s listing', $GLOBALS['FOLLOWED'], array() );
check( '  it follows its own new listing', array( meta( 650, '_ebay_item_id' ), meta( 650, '_ebay_previous_item_id' ) ), array( '226100099650', $item ) );
check( '  keeping its own SKU', meta( 650, '_sku' ), 'COIN-A' );
check( '  the log says coin B\'s listing is another item', logged( other_item_line( 'Bulk relist', $relist_id, $item, 'SKU "COIN-B"', 650, 'COIN-A' ) ), true );
check( '  and never that coin A follows it', logged( 'now follows the new listing' ), false );

// The daily Auto-Relist, through the real scheduler, a coin imported with the
// seller's SKU. Coin B's listing has ended unsold too: following it would have
// relisted coin B as coin A.
$ebay        = fresh_shop();
$item        = coin( 651, array( '_sku' => 'COIN-A', '_ebay_sku' => 'COIN-A' ) );
$relist_id   = '226100008651';
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'auto_relist_enabled' => 1 );
$ENDED_ROWS  = array( array( 'product_id' => '651', 'ebay_item_id' => $item ) );
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Completed', 1, 0, array( 'SKU' => 'COIN-B' ) ),
);
$ebay->relists = relisted_as( '226100099651' );
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
check( 'Auto-Relist, coin B\'s listing ended unsold: coin A\'s own listing is relisted, not coin B\'s', relist_calls( $ebay ), array( array( 'RelistFixedPriceItem', $item, 3 ) ) );
check( '  coin A is not moved on to coin B\'s listing', $GLOBALS['FOLLOWED'], array() );
check( '  the log says why, naming Auto-Relist', logged( other_item_line( 'Auto-Relist', $relist_id, $item, 'SKU "COIN-B"', 651, 'COIN-A' ) ), true );
check( '  and the run counts it relisted', logged( 'Auto-Relist: Complete. 1 relisted, 0 not relisted' ), true );

// A coin imported under its barcode, relisted as goods with another barcode.
$ebay        = fresh_shop();
$item        = coin( 652, array( '_sku' => '012345678905', '_ebay_sku' => '012345678905' ) );
$relist_id   = '226100008652';
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Active', 1, 0, array( 'ProductListingDetails' => array( 'UPC' => '036000291452' ) ) ),
);
$ebay->relists = relisted_as( '226100099652' );
check( 'relisted as goods with another barcode: relisted itself', array( outcome( relist( 652 ) ), relist_calls( $ebay ) ), array( 'relisted', array( array( 'RelistFixedPriceItem', $item, 3 ) ) ) );
check( '  the log calls it a barcode', logged( other_item_line( 'Bulk relist', $relist_id, $item, 'barcode "036000291452"', 652, '012345678905' ) ), true );

// A push of coin A: coin B's live listing is not revised with coin A's title
// and pictures. Coin A gets a listing of its own, as before 3.28.0.
$ebay        = fresh_shop( $push_settings );
$item        = coin( 653, array( '_sku' => 'COIN-A' ) );
$relist_id   = '226100008653';
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 3, 0, array( 'SKU' => 'COIN-A' ) + relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Active', 1, 0, array( 'SKU' => 'COIN-B', 'Title' => 'Coin B, 1909-S' ) ),
);
$ebay->revises[ $item ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$result = push( 653 );
$add_at = array_search( array( 'AddFixedPriceItem', '' ), $ebay->calls, true );
check( 'push of coin A: coin B\'s listing is only read, never revised', $ebay->calls_about( $relist_id ), array( array( 'GetItem', $relist_id ) ) );
check( '  the only revise is coin A\'s own ended listing, refused', revises_of( $ebay ), array( array( 'ReviseFixedPriceItem', $item ) ) );
check( '  coin A is listed anew instead, in place of its ended listing', is_array( $result ) ? array( $result['action'], $result['item_id'], $result['previous_item_id'] ) : ( is_wp_error( $result ) ? $result->get_error_message() : $result ), array( 'relisted', '226100077777', $item ) );
check( '  through one AddFixedPriceItem', adds_of( $ebay ), array( array( 'AddFixedPriceItem', '' ) ) );
check( '  carrying coin A\'s SKU', false !== $add_at && false !== strpos( $ebay->sent[ $add_at ] ?? '', '<SKU>COIN-A</SKU>' ), true );
check( '  the product is not moved on to coin B\'s listing', array( meta( 653, '_ebay_item_id' ), $GLOBALS['FOLLOWED'] ), array( $item, array() ) );
check( '  the log says coin B\'s listing is another item', logged( other_item_line( 'Push', $relist_id, $item, 'SKU "COIN-B"', 653, 'COIN-A' ) ), true );

// Coin B's listing already has a product here - the import made one, having
// said "it is another item" itself. Coin A was refused, with a warning to
// merge two different coins, and never relisted.
$ebay   = fresh_shop();
$coin_a = coin( 654, array( '_sku' => 'COIN-A' ) );
coin( 655, array( '_ebay_item_id' => '226100008654', '_ebay_listing_status' => 'Active', '_sku' => 'COIN-B', '_ebay_sku' => 'COIN-B' ) );
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'auto_relist_enabled' => 1 );
$ENDED_ROWS  = array( array( 'product_id' => '654', 'ebay_item_id' => $coin_a ) );
$ebay->items = array(
	$coin_a        => listing_says( $coin_a, 'Completed', 3, 0, relisted_on_ebay_as( '226100008654' ) ),
	'226100008654' => listing_says( '226100008654', 'Active', 1, 0, array( 'SKU' => 'COIN-B' ) ),
);
$ebay->relists = relisted_as( '226100099654' );
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
check( 'coin B\'s listing has a product of its own: Auto-Relist relists coin A', relist_calls( $ebay ), array( array( 'RelistFixedPriceItem', $coin_a, 3 ) ) );
check( '  having asked eBay what coin B\'s listing is', $ebay->calls_about( '226100008654' ), array( array( 'GetItem', '226100008654' ) ) );
check( '  no warning to merge two different coins', count( log_lines_naming( 'Merge them' ) ), 0 );
check( '  nothing noted to hold Auto-Relist back', meta( 654, '_ebay_relist_held' ), null );
check( '  the log says coin B\'s listing is another item', logged( other_item_line( 'Auto-Relist', '226100008654', $coin_a, 'SKU "COIN-B"', 654, 'COIN-A' ) ), true );
check( '  coin A follows its own new listing, coin B keeps its', array( meta( 654, '_ebay_item_id' ), meta( 655, '_ebay_item_id' ) ), array( '226100099654', '226100008654' ) );

// The same pair, but the relist carries coin A's own SKU: a real duplicate,
// refused and warned about as before - after the one look at the relist.
$ebay   = fresh_shop();
$coin_a = coin( 656, array( '_sku' => 'COIN-A' ) );
coin( 657, array( '_ebay_item_id' => '226100008656', '_ebay_listing_status' => 'Active', '_sku' => 'COIN-A-2' ) );
$ebay->items = array(
	$coin_a        => listing_says( $coin_a, 'Completed', 3, 0, relisted_on_ebay_as( '226100008656' ) ),
	'226100008656' => listing_says( '226100008656', 'Active', 3, 0, array( 'SKU' => 'COIN-A' ) ),
);
$ebay->relists = relisted_as( '226100099656' );
$result        = relist( 656, 'Auto-Relist' );
check( 'the relist is coin A itself, and another product follows it: refused as before', array( outcome( $result ), is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'which WC #657 follows' ) ), array( 'relist_refused', true ) );
check( '  eBay asked about the old listing and the relist, nothing relisted', $ebay->calls, array( array( 'GetItem', $coin_a ), array( 'GetItem', '226100008656' ) ) );
check( '  the merge warning is given, once', count( log_lines_naming( 'Merge them' ) ), 1 );
check( '  and the pair noted for Auto-Relist', meta( 656, '_ebay_relist_held' ), $coin_a . '>226100008656' );

// ... or the relist cannot be read: nothing is known, so refused as before.
$ebay   = fresh_shop();
$coin_a = coin( 658, array( '_sku' => 'COIN-A' ) );
coin( 659, array( '_ebay_item_id' => '226100008658', '_ebay_listing_status' => 'Active', '_sku' => 'COIN-B' ) );
$ebay->items = array(
	$coin_a        => listing_says( $coin_a, 'Completed', 3, 0, relisted_on_ebay_as( '226100008658' ) ),
	'226100008658' => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
);
$ebay->relists = relisted_as( '226100099658' );
$result        = relist( 658, 'Auto-Relist' );
check( 'the relist another product follows cannot be read: refused as before', array( outcome( $result ), relist_calls( $ebay ), meta( 658, '_ebay_relist_held' ) ), array( 'relist_refused', array(), $coin_a . '>226100008658' ) );

echo "\nA RELIST OF THE SAME GOODS IS FOLLOWED AS BEFORE (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// Each of these is followed exactly as the relist in the first 3.28.0 section
// is: GetItem for the old listing and the relist, and the product moves on.
$same_goods = array(
	// The relist keeps coin A's SKU.
	'the relist carries the product\'s own SKU'                          => array( 660, array( '_sku' => 'COIN-A' ), array( 'SKU' => 'COIN-A' ) ),
	// Imported while another listing had the same SKU, so WooCommerce's has
	// the item number added; the SKU eBay knows it by is the one compared.
	'its SKU as eBay knows it, WooCommerce\'s made unique'               => array( 666, array( '_sku' => 'COIN-A-226100000666', '_ebay_sku' => 'COIN-A' ), array( 'SKU' => 'COIN-A' ) ),
	// Imported with no SKU of its own, so the SKU is the item number, which
	// names no goods; the seller gave the relist a custom label.
	'an item-number SKU, the relist given a custom label'                => array( 661, array( '_sku' => '226100000661' ), array( 'SKU' => 'COIN-7' ) ),
	'an item-number SKU in its EBAY- form, the same'                     => array( 662, array( '_sku' => 'EBAY-226100000662', '_ebay_sku' => 'EBAY-226100000662' ), array( 'SKU' => 'COIN-7' ) ),
	// Relisted from here before, which keeps the SKU: the number it was
	// imported with is the listing before this one.
	'the item number of the listing before, the same'                    => array( 663, array( '_ebay_item_id' => '226100001663', '_ebay_previous_item_id' => '226100000663', '_sku' => '226100000663' ), array( 'SKU' => 'COIN-7' ) ),
	// Imported under its barcode; on relist the seller added a custom label
	// and kept the barcode.
	'imported under its barcode, the relist adding a SKU but keeping it' => array( 664, array( '_sku' => '012345678905', '_ebay_sku' => '012345678905' ), array( 'SKU' => 'CARD-664', 'ProductListingDetails' => array( 'UPC' => '012345678905' ) ) ),
);
foreach ( $same_goods as $label => $case ) {
	list( $id, $meta, $relist_extra ) = $case;
	$ebay        = fresh_shop();
	$item        = coin( $id, $meta );
	$relist_id   = '22610000' . $id . '8';
	$ebay->items = array(
		$item      => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( $relist_id ) ),
		$relist_id => listing_says( $relist_id, 'Active', 3, 0, $relist_extra ),
	);
	$ebay->relists = relisted_as( '226100099' . $id );
	$result        = relist( $id );
	check( $label . ': followed', array( outcome( $result ), is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'already relisted on eBay as ' . $relist_id ) ), array( 'relist_refused', true ) );
	check( '  GetItem for the old listing and the relist, nothing relisted', $ebay->calls, array( array( 'GetItem', $item ), array( 'GetItem', $relist_id ) ) );
	check( '  the product now follows the relist', array( meta( $id, '_ebay_item_id' ), $GLOBALS['FOLLOWED'] ), array( $relist_id, array( array( $id, $item, $relist_id ) ) ) );
	check( '  and no line calls it another item', logged( 'so it is another item' ), false );
}

// Two relists on: the coin's item-number SKU is still read against the listing
// it follows, not the relist in between, so the custom label the seller gave
// the second relist does not stop it there.
$ebay        = fresh_shop();
$item        = coin( 665, array( '_sku' => '226100000665' ) );
$ebay->items = array(
	$item          => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( '226100001665' ) ),
	'226100001665' => listing_says( '226100001665', 'Completed', 3, 0, relisted_on_ebay_as( '226100002665' ) ),
	'226100002665' => listing_says( '226100002665', 'Completed', 3, 0, array( 'SKU' => 'COIN-7' ) ),
);
$ebay->relists = relisted_as( '226100099665' );
relist( 665 );
check( 'an item-number SKU, relisted twice on eBay, the second given a custom label: the newest is relisted', relist_calls( $ebay ), array( array( 'RelistFixedPriceItem', '226100002665', 3 ) ) );
check( '  and no line calls it another item', logged( 'so it is another item' ), false );

echo "\nA COIN AUCTION THAT ENDED UNSOLD, THEN RELISTED (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// The coin shop listed a coin at auction from WooCommerce - the push that
// listed it noted the auction as the shop's own. It ended unsold, and the
// hourly check settled it: one listed, none sold. Between that and the next
// day's Auto-Relist the coin is for sale on the website, at the shop's own
// price. Once relisted it is on auction again, and is not. The relist is
// still the shop's auction, so when that ends unsold too the coin is back on
// sale again. The real inventory class decides, through the same WooCommerce
// filter a shop runs.
//
// Fails on 3.27.2, which has no such filter: the coin can be bought whatever
// the auction is doing.
$purchasable = function ( $id ) {
	return method_exists( 'Harness_Real_Inventory', 'filter_purchasable' )
		? Harness_Real_Inventory::filter_purchasable( true, wc_get_product( $id ) )
		: true;
};
fresh_shop();
use_ebay( new TCGiant_Sync_API() );
$item = coin( 595, array(
	'_ebay_listing_type'        => 'Chinese',
	'_ebay_listing_duration'    => 'Days_7',
	'_stock'                    => '1',
	'_ebay_export_last_pushed'  => '2026-10-05 10:00:00',
	'_ebay_auction_listed_item' => '226100000595',
) );
check( 'while its auction may still be running, the coin cannot be bought on the website', $purchasable( 595 ), false );
$GLOBALS['META'][595]['_ebay_stock_settled_at']   = '2026-10-12 12:00:00';
$GLOBALS['META'][595]['_ebay_stock_settled_item'] = $item;
$GLOBALS['META'][595]['_ebay_quantity_listed']    = 1;
$GLOBALS['META'][595]['_ebay_quantity_sold']      = 0;
check( 'ended unsold and settled: listed from WooCommerce, so back on sale', $purchasable( 595 ), true );
$ANSWERS['GetItem']    = '<Ack>Success</Ack><Item><ItemID>' . $item . '</ItemID><ListingType>Chinese</ListingType><Quantity>1</Quantity><SellingStatus><QuantitySold>0</QuantitySold><ListingStatus>Completed</ListingStatus><BidCount>0</BidCount></SellingStatus></Item>';
$ANSWERS['RelistItem'] = '<Ack>Success</Ack><ItemID>226100009595</ItemID><StartTime>2026-10-13T03:00:00.000Z</StartTime><EndTime>2026-10-20T03:00:00.000Z</EndTime>';
$result = relist( 595, 'Auto-Relist' );
$sent   = parse_wire( $WIRE[1]['body'] ?? '' );
check( 'Auto-Relist relists it', outcome( $result ), 'relisted' );
check( '  GetItem, then RelistItem, and nothing else', array_column( $WIRE, 'call' ), array( 'GetItem', 'RelistItem' ) );
check( '  as the same auction: no Quantity on the wire', null !== $sent ? array( wire_text( $sent, 'ItemID' ), wire_count( $sent, 'Quantity' ) ) : null, array( $item, 0 ) );
check( '  the old listing\'s settle is gone', meta( 595, '_ebay_stock_settled_at' ), null );
check( '  so on auction again, it cannot be bought on the website', $purchasable( 595 ), false );
check( '  the relist is noted as the shop\'s own auction too', meta( 595, '_ebay_auction_listed_item' ), '226100009595' );
// A week on, the relist ends unsold as well, and the hourly check settles it.
$GLOBALS['META'][595]['_ebay_listing_status'] = 'Ended';
TCGiant_Sync_Inventory::apply_ended_listing_stock( 595, 1, 0 );
check( '  and when the relist ends unsold too, the coin is back on sale', $purchasable( 595 ), true );

echo "\nAN AUCTION THE SHOP DID NOT LIST STAYS OFF SALE, HOWEVER OFTEN IT IS RELISTED (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

/** What the import records about the auction a product follows. */
function auction_figures( $id ) {
	$figures = array();
	foreach ( array( '_ebay_start_price', '_ebay_current_price', '_ebay_bid_count', '_ebay_buy_it_now_price' ) as $key ) {
		$figures[ $key ] = meta( $id, $key );
	}
	return $figures;
}
$no_figures = array( '_ebay_start_price' => null, '_ebay_current_price' => null, '_ebay_bid_count' => null, '_ebay_buy_it_now_price' => null );

// A card shop imports its eBay auctions. One ended unsold. Its price on the
// website is eBay's opening bid, not one the shop set, so it stays off sale.
// Auto-Relist puts it up again: still eBay's auction, not one the shop
// listed, so it stays off sale after that ends unsold too. And the import's
// figures for the old auction - its opening bid, bids and Buy It Now - go
// with the old listing, rather than be shown on the new one.
$ebay = fresh_shop();
$item = coin( 596, array(
	'_ebay_listing_type'     => 'Chinese',
	'_ebay_listing_duration' => 'Days_7',
	'_stock'                 => '1',
	'_ebay_start_price'      => '0.99',
	'_ebay_current_price'    => '0.99',
	'_ebay_bid_count'        => '0',
	'_ebay_buy_it_now_price' => '25.00',
) );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 596, 1, 0 );
check( 'an imported auction that ended unsold stays off sale on the website', $purchasable( 596 ), false );
$ebay->item    = ebay_says( 'Completed', 1, 0, 'Chinese' );
$ebay->relists = relisted_as( '226100009596' );
check( '  Auto-Relist relists it', array( outcome( relist( 596, 'Auto-Relist' ) ), relist_calls( $ebay ) ), array( 'relisted', array( array( 'RelistItem', $item, null ) ) ) );
check( '  the relist is not noted as an auction the shop listed', meta( 596, '_ebay_auction_listed_item' ), null );
check( '  the old auction\'s opening bid, bids and Buy It Now are gone', auction_figures( 596 ), $no_figures );
$GLOBALS['META'][596]['_ebay_listing_status'] = 'Ended';
TCGiant_Sync_Inventory::apply_ended_listing_stock( 596, 1, 0 );
check( '  and when the relist ends unsold too, it stays off sale', $purchasable( 596 ), false );

// The coin shop listed a coin at auction from WooCommerce, and it ended
// unsold. On eBay the seller then used Sell Similar, starting an auction of
// their own at 0.99, and the import moved the coin on to it by its SKU. The
// note still names the shop's old auction, not this one, and Auto-Relisting
// this one does not make it the shop's.
$ebay = fresh_shop();
$item = coin( 597, array(
	'_ebay_listing_type'        => 'Chinese',
	'_ebay_listing_duration'    => 'Days_7',
	'_stock'                    => '1',
	'_ebay_auction_listed_item' => '226100000111',
) );
TCGiant_Sync_Inventory::apply_ended_listing_stock( 597, 1, 0 );
check( 'an auction the seller started on eBay, after the shop\'s own ended, stays off sale', $purchasable( 597 ), false );
$ebay->item    = ebay_says( 'Completed', 1, 0, 'Chinese' );
$ebay->relists = relisted_as( '226100009597' );
check( '  Auto-Relist relists it', outcome( relist( 597, 'Auto-Relist' ) ), 'relisted' );
check( '  the note is not moved on to the relist', meta( 597, '_ebay_auction_listed_item' ), '226100000111' );
$GLOBALS['META'][597]['_ebay_listing_status'] = 'Ended';
TCGiant_Sync_Inventory::apply_ended_listing_stock( 597, 1, 0 );
check( '  and when the relist ends unsold, it stays off sale', $purchasable( 597 ), false );

// R3-M2 (3.28.0, fix round 3): an auction an older version recorded as
// fixed-price (the 3.27 backfill), relisted from here. eBay's answer says it
// is an auction; before, only the item number moved, and a hand edit to 0
// in a shop with no auction known ended it with no read, bids and all.
$ebay = fresh_shop();
$item = coin( 598, array( '_stock' => '1' ) );
$GLOBALS['SET_TRANSIENTS'] = array();
$ebay->item    = ebay_says( 'Completed', 1, 0, 'Chinese' );
$ebay->relists = relisted_as( '226100009598' );
check( 'R3-M2 an auction recorded as fixed-price by the 3.27 backfill, relisted from here', outcome( relist( 598, 'Auto-Relist' ) ), 'relisted' );
check( '  recorded as eBay says, an auction, for the relist', array( meta( 598, '_ebay_listing_type' ), meta( 598, '_ebay_listing_type_item' ) ), array( 'Chinese', '226100009598' ) );
check( '  and the shop is known to have an auction from then on', $GLOBALS['SET_TRANSIENTS']['tcgiant_shop_has_auctions'] ?? null, 'yes' );
$ebay = fresh_shop();
$item = coin( 599, array( '_stock' => '1' ) );
$ebay->item    = ebay_says( 'Completed', 1, 0, 'FixedPriceItem' );
$ebay->relists = relisted_as( '226100009599' );
relist( 599, 'Auto-Relist' );
check( '  a fixed-price listing relisted: its type is confirmed for the relist', array( meta( 599, '_ebay_listing_type' ), meta( 599, '_ebay_listing_type_item' ) ), array( 'FixedPriceItem', '226100009599' ) );
// A push that revises a listing whose type nothing has recorded: no type
// made up for it, in the listings table or, through it, on the product.
$ebay = fresh_shop( $push_settings );
$item = coin( 669, array( '_ebay_listing_status' => 'Active' ) );
unset( $GLOBALS['META'][669]['_ebay_listing_type'] );
$ebay->items     = array( $item => listing_says( $item, 'Active', 3, 0 ) );
$GLOBALS['ROWS'] = array();
$pushed          = push_from_queue( 669 );
$row             = end( $GLOBALS['ROWS'] ) ?: array();
check( 'R3-M2 a push revising a listing whose type nothing recorded: no FixedPriceItem made up, in the row or on the product', array( $pushed, array_key_exists( 'listing_type', $row ), meta( 669, '_ebay_listing_type' ) ), array( 'pushed', false, null ) );

echo "\nTHE SHOP'S OWN AUCTION, RELISTED ON EBAY BY THE SELLER (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// The coin shop's own auction ended unsold, and the seller relisted it on
// eBay before Auto-Relist got there. The coin moves on to that relist, which
// is still the auction the shop listed: off sale while it runs, and back on
// sale here if it ends unsold.
$ebay        = fresh_shop();
$item        = coin( 598, array(
	'_ebay_listing_type'        => 'Chinese',
	'_ebay_listing_duration'    => 'Days_7',
	'_stock'                    => '1',
	'_ebay_auction_listed_item' => '226100000598',
) );
$relist_id   = '226100008598';
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 1, 0, array( 'ListingType' => 'Chinese' ) + relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Active', 1, 0, array( 'ListingType' => 'Chinese', 'ListingDetails' => array( 'EndTime' => '2026-10-20T03:00:00.000Z' ) ) ),
);
check( 'the shop\'s auction, relisted on eBay already: not relisted again', array( outcome( relist( 598, 'Auto-Relist' ) ), relist_calls( $ebay ) ), array( 'relist_refused', array() ) );
check( '  the coin follows the relist, noted as the shop\'s own auction', array( meta( 598, '_ebay_item_id' ), meta( 598, '_ebay_auction_listed_item' ) ), array( $relist_id, $relist_id ) );
check( '  it cannot be bought on the website while the relist runs', $purchasable( 598 ), false );
$GLOBALS['META'][598]['_ebay_listing_status'] = 'Ended';
TCGiant_Sync_Inventory::apply_ended_listing_stock( 598, 1, 0 );
check( '  and is back on sale once the relist ends unsold', $purchasable( 598 ), true );

echo "\nA PUSH NOTES AN AUCTION AS THE SHOP'S OWN ONLY WHEN IT LISTS ONE (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// Driven through the real process_single_push(), as Action Scheduler runs it
// for the Push to eBay button and for a bulk push: do_push() and then what is
// written to the product. The listing is built by the real builder.

/** Push a product, and say how it went: 'pushed', or why not. */
function push_from_queue( $product_id ) {
	TCGiant_Sync_Exporter::instance()->process_single_push( $product_id );
	$status = (string) meta( $product_id, '_ebay_export_status' );
	if ( 'pushed' === $status ) {
		return $status;
	}
	$last = end( $GLOBALS['LOG'] );
	return $status . ' ' . meta( $product_id, '_ebay_export_error' ) . ' / ' . ( $last ? $last[1] : '' );
}

// The coin shop lists a coin at auction from WooCommerce for the first time.
// The new auction is the shop's own: off sale while it runs, back on sale
// here at the shop's price once it ends unsold.
$ebay = fresh_shop( $push_settings );
coin( 640, array(
	'_ebay_item_id'                 => '',
	'_ebay_listing_type'            => '',
	'_ebay_listing_status'          => '',
	'_ebay_end_time'                => '',
	'_ebay_export_listing_type'     => 'Chinese',
	'_ebay_export_listing_duration' => 'Days_7',
	'_stock'                        => '1',
) );
$GLOBALS['SET_TRANSIENTS'] = array();
check( 'a coin pushed at auction for the first time is listed', push_from_queue( 640 ), 'pushed' );
check( '  through AddItem, and nothing else', $ebay->calls, array( array( 'AddItem', '' ) ) );
check( '  the coin follows the new auction', array( meta( 640, '_ebay_item_id' ), meta( 640, '_ebay_listing_type' ) ), array( '226100077777', 'Chinese' ) );
check( '  and the shop is known to have an auction from then on (3.28.0)', $GLOBALS['SET_TRANSIENTS']['tcgiant_shop_has_auctions'] ?? null, 'yes' );
check( '  noted as the shop\'s own auction', meta( 640, '_ebay_auction_listed_item' ), '226100077777' );
check( '  it cannot be bought on the website while it runs', $purchasable( 640 ), false );
$GLOBALS['META'][640]['_ebay_listing_status'] = 'Ended';
TCGiant_Sync_Inventory::apply_ended_listing_stock( 640, 1, 0 );
check( '  and is back on sale once it ends unsold', $purchasable( 640 ), true );

// The shop's auction of a coin ended unsold. The shop switched the coin to
// Fixed Price and pushed: the push finds the auction ended and lists the coin
// at a fixed price instead. It is no longer an auction the shop listed, and
// the import's figures for the old auction go with it.
$ebay = fresh_shop( $push_settings );
$item = coin( 641, array(
	'_ebay_listing_type'        => 'Chinese',
	'_ebay_listing_duration'    => 'Days_7',
	'_ebay_export_listing_type' => 'FixedPriceItem',
	'_stock'                    => '1',
	'_ebay_auction_listed_item' => '226100000641',
	'_ebay_start_price'         => '89.99',
	'_ebay_current_price'       => '89.99',
	'_ebay_bid_count'           => '0',
	'_ebay_buy_it_now_price'    => '120.00',
) );
$ebay->items            = array( $item => listing_says( $item, 'Completed', 1, 0, array( 'ListingType' => 'Chinese' ) ) );
$ebay->revises[ $item ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
check( 'the shop\'s ended auction, pushed again at a fixed price, is listed', push_from_queue( 641 ), 'pushed' );
check( '  ReviseItem refused as ended, GetItem, then AddFixedPriceItem', array_column( $ebay->calls, 0 ), array( 'ReviseItem', 'GetItem', 'AddFixedPriceItem' ) );
check( '  the coin follows the new fixed-price listing', array( meta( 641, '_ebay_item_id' ), meta( 641, '_ebay_listing_type' ), meta( 641, '_ebay_previous_item_id' ) ), array( '226100077777', 'FixedPriceItem', $item ) );
check( '  its type known to be the new listing\'s, so the stock push may end it without asking (3.28.0)', meta( 641, '_ebay_listing_type_item' ), '226100077777' );
check( '  no longer noted as an auction the shop listed', meta( 641, '_ebay_auction_listed_item' ), null );
check( '  the old auction\'s opening bid, bids and Buy It Now are gone', auction_figures( 641 ), $no_figures );

// A card shop's imported auction ended unsold. The shop set its own price in
// WooCommerce and pushed the card at auction: the push lists a new auction
// from WooCommerce, at the shop's price, so this one is the shop's own.
$ebay = fresh_shop( $push_settings );
$item = coin( 644, array(
	'_ebay_listing_type'            => 'Chinese',
	'_ebay_listing_duration'        => 'Days_7',
	'_ebay_export_listing_type'     => 'Chinese',
	'_ebay_export_listing_duration' => 'Days_7',
	'_stock'                        => '1',
) );
$ebay->items            = array( $item => listing_says( $item, 'Completed', 1, 0, array( 'ListingType' => 'Chinese' ) ) );
$ebay->revises[ $item ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
check( 'an imported auction that ended, pushed again at auction from WooCommerce, is listed', push_from_queue( 644 ), 'pushed' );
check( '  ReviseItem refused as ended, GetItem, then AddItem', array_column( $ebay->calls, 0 ), array( 'ReviseItem', 'GetItem', 'AddItem' ) );
check( '  the new auction is noted as the shop\'s own', array( meta( 644, '_ebay_item_id' ), meta( 644, '_ebay_auction_listed_item' ) ), array( '226100077777', '226100077777' ) );
$GLOBALS['META'][644]['_ebay_listing_status'] = 'Ended';
TCGiant_Sync_Inventory::apply_ended_listing_stock( 644, 1, 0 );
check( '  ended unsold, it is on sale here at the shop\'s price', $purchasable( 644 ), true );

// A card shop imports its eBay auctions, and pressed Push to eBay on one to
// send a better title. That revises eBay's auction; it does not make it the
// shop's, though any push at all used to count. It ends unsold: still off
// sale here, where its price is eBay's opening bid.
$ebay = fresh_shop( $push_settings );
$item = coin( 642, array(
	'_ebay_listing_type'     => 'Chinese',
	'_ebay_listing_duration' => 'Days_7',
	'_ebay_listing_status'   => 'Active',
	'_stock'                 => '1',
	'_ebay_start_price'      => '0.99',
	'_ebay_current_price'    => '0.99',
	'_ebay_bid_count'        => '0',
	'_ebay_buy_it_now_price' => '25.00',
) );
check( 'an Update pushed to an imported auction goes through', push_from_queue( 642 ), 'pushed' );
check( '  as a ReviseItem of that auction', $ebay->calls, array( array( 'ReviseItem', $item ) ) );
check( '  recorded as pushed', '' !== (string) meta( 642, '_ebay_export_last_pushed' ), true );
check( '  but not noted as an auction the shop listed', meta( 642, '_ebay_auction_listed_item' ), null );
check( '  its opening bid, bids and Buy It Now are kept: the auction is the same', auction_figures( 642 ), array( '_ebay_start_price' => '0.99', '_ebay_current_price' => '0.99', '_ebay_bid_count' => '0', '_ebay_buy_it_now_price' => '25.00' ) );
$GLOBALS['META'][642]['_ebay_listing_status'] = 'Ended';
TCGiant_Sync_Inventory::apply_ended_listing_stock( 642, 1, 0 );
check( '  ended unsold, it stays off sale on the website', $purchasable( 642 ), false );

// The coin shop's own auction, revised with a better description while it
// runs: still the shop's, and back on sale here if it ends unsold.
$ebay = fresh_shop( $push_settings );
$item = coin( 643, array(
	'_ebay_listing_type'        => 'Chinese',
	'_ebay_listing_duration'    => 'Days_7',
	'_ebay_listing_status'      => 'Active',
	'_stock'                    => '1',
	'_ebay_auction_listed_item' => '226100000643',
) );
check( 'an Update pushed to the shop\'s own auction goes through', push_from_queue( 643 ), 'pushed' );
check( '  as a ReviseItem of that auction', $ebay->calls, array( array( 'ReviseItem', $item ) ) );
check( '  and leaves it noted as the shop\'s', meta( 643, '_ebay_auction_listed_item' ), $item );
$GLOBALS['META'][643]['_ebay_listing_status'] = 'Ended';
TCGiant_Sync_Inventory::apply_ended_listing_stock( 643, 1, 0 );
check( '  ended unsold, it is back on sale', $purchasable( 643 ), true );

echo "\nA VARIATION LISTING IS RELISTED VARIATION BY VARIATION, THROUGH THE REAL API CLASS (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// The card shop's deck sleeves, Black and White, in one listing. Black: 1
// listed and 1 sold on eBay, and the sync has not brought the sale in, so
// WooCommerce still shows 1. White: 2 listed and none sold on eBay, but one
// sold on the website, so WooCommerce holds 1. 3.27.2 relisted it bare: eBay
// put Black back up at the 1 it was first listed with - a sleeve already
// sold - and White at its 2, one more than the shop has.
fresh_shop();
use_ebay( new TCGiant_Sync_API() );
$item = sleeves( 700 );
sleeve( 701, 700, 'BLK', 1, array( 'color' => 'Black' ) );
sleeve( 702, 700, 'WHT', 1, array( 'color' => 'White' ) );
$ANSWERS['GetItem']              = variations_getitem_xml( $item, 3, 1, variation_xml( 'BLK', 1, 1, 'Color', 'Black' ) . variation_xml( 'WHT', 2, 0, 'Color', 'White' ) );
$ANSWERS['RelistFixedPriceItem'] = '<Ack>Success</Ack><ItemID>226100009700</ItemID><StartTime>2026-10-12T09:00:00.000Z</StartTime><EndTime>2026-11-11T09:00:00.000Z</EndTime>';
$result = relist( 700 );
$sent   = parse_wire( $WIRE[1]['body'] ?? '' );
check( 'the sleeves are relisted', outcome( $result ), 'relisted' );
check( '  GetItem, then RelistFixedPriceItem, and nothing else', array_column( $WIRE, 'call' ), array( 'GetItem', 'RelistFixedPriceItem' ) );
check( '  well-formed, for the ended listing', null !== $sent ? array( wire_root( $sent ), wire_text( $sent, 'ItemID' ) ) : null, array( 'RelistFixedPriceItemRequest', $item ) );
check( '  with no quantity for the listing as a whole', wire_count( $sent, 'Quantity' ), 0 );
check( '  every variation named as eBay knows it, at eBay\'s price: Black at 0, White at the shop\'s 1', sent_variations( $sent ), array( 'BLK' => array( '0', '10.00', 'Color: Black' ), 'WHT' => array( '1', '10.00', 'Color: White' ) ) );
check( '  Black\'s sale brought in first: 0, and out of stock here', array( (int) meta( 701, '_stock' ), meta( 701, '_stock_status' ) ), array( 0, 'outofstock' ) );
check( '  White left at 1, not raised to eBay\'s 2', (int) meta( 702, '_stock' ), 1 );
check( '  the shop is warned what was lowered, and how to undo a restock', array_column( log_lines_naming( 'Relist check: eBay item ' . $item . ' - variations of WC #700 lowered to eBay\'s figures before listing it again: #701 1 to 0. eBay sold them on that listing and the sync had not brought it in yet. If you restocked one since, set that variation\'s stock again and push the product to eBay.' ), 0 ), array( 'warning' ) );
check( '  and not told to set the parent\'s stock, which the relist does not send', log_lines_naming( 'WC #700 stock lowered from' ), array() );
check( '  and that Black goes up at 0', logged( 'Relisting eBay listing ' . $item . ' for WC #700: these variations have none to sell and go up at 0 (eBay drops them unless out-of-stock control is on): BLK.' ), true );
check( '  the parent follows its variations once, with nothing pushed back to eBay', $SYNCED, array( array( 700, true ) ) );
check( '  the product holds the new listing, the old one\'s variation settle gone with it', array( meta( 700, '_ebay_item_id' ), meta( 700, '_ebay_variations_settled_item' ) ), array( '226100009700', null ) );

echo "\nA VARIATION LISTING: WHEN IT IS NOT RELISTED, AND WHAT EACH VARIATION GOES UP AT (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// Both colours sold out on eBay, and neither sale is in the stock yet.
$ebay = fresh_shop();
$item = sleeves( 710 );
sleeve( 711, 710, 'BLK', 1, array( 'color' => 'Black' ) );
sleeve( 712, 710, 'WHT', 1, array( 'color' => 'White' ) );
$ebay->item    = ebay_says_variations( $item, 'Completed', 2, 2, array( ebay_variation( 'BLK', 1, 1, array( 'Color' => 'Black' ) ), ebay_variation( 'WHT', 1, 1, array( 'Color' => 'White' ) ) ) );
$ebay->relists = relisted_as( '226100009710' );
$result        = relist( 710 );
check( 'every variation sold out on eBay: not relisted', outcome( $result ), 'relist_refused' );
check( '  the shop is told so, with eBay\'s figures', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'no variation has stock left once eBay\'s sales were brought in (2 listed, 2 sold on eBay)' ), true );
check( '  only GetItem was sent', $ebay->calls, array( array( 'GetItem', $item ) ) );
check( '  both colours brought to 0', array( (int) meta( 711, '_stock' ), (int) meta( 712, '_stock' ) ), array( 0, 0 ) );
check( '  the variations first, then the parent - and nothing trashed', $GLOBALS['SETTLED'], array( array( 'settle_variations', 710, 'Relist check' ), array( 'apply_ended_listing_stock', 710, 2, 2 ) ) );
check( '  the listing noted as settled, its variations too', array( meta( 710, '_ebay_variations_settled_item' ), meta( 710, '_ebay_stock_settled_item' ) ), array( $item, $item ) );

// eBay reports the variations, but nothing sold, so there is no sale to
// bring in. The relist still has to name every variation, and eBay's answer
// names none.
$ebay = fresh_shop();
$item = sleeves( 713 );
sleeve( 714, 713, 'BLK', 1, array( 'color' => 'Black' ) );
$ebay->item    = listing_says( $item, 'Completed', 3, 0 );
$ebay->relists = relisted_as( '226100009713' );
$result        = relist( 713 );
check( 'nothing sold, and eBay names no variations: not relisted', array( outcome( $result ), is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'eBay did not report the variations of listing ' . $item . ', so it was not relisted' ) ), array( 'relist_unchecked', true ) );
check( '  only GetItem was sent', $ebay->calls, array( array( 'GetItem', $item ) ) );

// eBay's answer leaves out one variation's price. A relist that names a
// variation has to send its price, and the shop's own is not eBay's.
$ebay  = fresh_shop();
$item  = sleeves( 715 );
sleeve( 716, 715, 'BLK', 1, array( 'color' => 'Black' ) );
sleeve( 717, 715, 'WHT', 1, array( 'color' => 'White' ) );
$white = ebay_variation( 'WHT', 1, 0, array( 'Color' => 'White' ) );
unset( $white['StartPrice'] );
$ebay->item    = ebay_says_variations( $item, 'Completed', 2, 0, array( ebay_variation( 'BLK', 1, 0, array( 'Color' => 'Black' ) ), $white ) );
$ebay->relists = relisted_as( '226100009715' );
$result        = relist( 715 );
check( 'eBay gives no price for one variation: not relisted', array( outcome( $result ), is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'did not report the price and quantity of every variation of listing ' . $item ) ), array( 'relist_unchecked', true ) );
check( '  only GetItem was sent', $ebay->calls, array( array( 'GetItem', $item ) ) );

// Sold on eBay and already settled - the variations' sales and the
// listing's are both in the stock. The shop then restocked Large to 4. These
// sleeves have no SKUs, on eBay or here, so each is known by its size. eBay
// also still has Small, which the shop has no variation for.
$ebay = fresh_shop();
$item = sleeves( 720, array( '_ebay_stock_settled_at' => '2026-10-12 10:00:00' ) );
$GLOBALS['META'][720]['_ebay_stock_settled_item']      = $item;
$GLOBALS['META'][720]['_ebay_variations_settled_item'] = $item;
sleeve( 721, 720, '', 4, array( 'size' => 'Large' ) );
sleeve( 722, 720, '', 1, array( 'size' => 'Medium' ) );
$ebay->item = ebay_says_variations( $item, 'Completed', 4, 2, array(
	ebay_variation( '', 2, 2, array( 'Size' => 'Large' ), '7.50' ),
	ebay_variation( '', 1, 0, array( 'Size' => 'Medium' ), '7.50' ),
	ebay_variation( '', 1, 0, array( 'Size' => 'Small' ), '7.50' ),
) );
$ebay->relists = relisted_as( '226100009720' );
$result        = relist( 720 );
$call          = relist_calls( $ebay )[0] ?? array();
$block         = recorded_variations( $call );
check( 'already settled, Large restocked since: relisted', outcome( $result ), 'relisted' );
check( '  nothing settled again over the restock', $GLOBALS['SETTLED'], array() );
check( '  no quantity for the listing as a whole', array_slice( $call, 0, 3 ), array( 'RelistFixedPriceItem', $item, null ) );
check( '  Large at the restock of 4, Medium at 1, and Small - which the shop has none of - at eBay\'s 1 left', sent_variations( $block ), array( 'Size: Large' => array( '4', '7.50', 'Size: Large' ), 'Size: Medium' => array( '1', '7.50', 'Size: Medium' ), 'Size: Small' => array( '1', '7.50', 'Size: Small' ) ) );
check( '  named by size alone: eBay has no SKU for them, and none is sent', wire_count( $block, 'Variations/e:Variation/e:SKU' ), 0 );
check( '  Large still holds its 4', (int) meta( 721, '_stock' ), 4 );

// The same shop, its sales not settled yet. Small, which the shop has none
// of, is noted against the product and said once; the note goes with the old
// listing when the relist is made.
$ebay = fresh_shop();
$item = sleeves( 723 );
sleeve( 724, 723, '', 1, array( 'size' => 'Large' ) );
$ebay->item = ebay_says_variations( $item, 'Completed', 3, 1, array(
	ebay_variation( '', 2, 1, array( 'Size' => 'Large' ), '7.50' ),
	ebay_variation( '', 1, 0, array( 'Size' => 'Small' ), '7.50' ),
) );
$noted         = null;
$ebay->relists = function ( $item_id ) use ( &$noted ) {
	$noted = meta( 723, '_ebay_unmatched_variations' );
	return relisted_as( '226100009723' );
};
check( 'eBay has a size the shop has none of: relisted', outcome( relist( 723 ) ), 'relisted' );
check( '  that size noted before the relist went, and said once', array( $noted, count( log_lines_naming( 'has variations with no matching variation on WC #723 (Size=Small)' ) ) ), array( array( 'Size=Small' ), 1 ) );
check( '  sent at eBay\'s 1 left, beside Large at the shop\'s 1', sent_variations( recorded_variations( relist_calls( $ebay )[0] ?? array() ) ), array( 'Size: Large' => array( '1', '7.50', 'Size: Large' ), 'Size: Small' => array( '1', '7.50', 'Size: Small' ) ) );
check( '  and the note gone with the old listing', meta( 723, '_ebay_unmatched_variations' ), null );

// Settled by 3.27.0, which settled the parent alone: the listing's figures
// are in its stock, Black's sale is not in Black's. The first relist since
// brings Black's sale in, and leaves the parent's settle as it was.
$ebay = fresh_shop();
$item = sleeves( 730, array( '_stock' => '2', '_ebay_stock_settled_at' => '2026-10-01 12:00:00' ) );
$GLOBALS['META'][730]['_ebay_stock_settled_item'] = $item;
sleeve( 731, 730, 'BLK', 1, array( 'color' => 'Black' ) );
sleeve( 732, 730, 'WHT', 1, array( 'color' => 'White' ) );
$ebay->item    = ebay_says_variations( $item, 'Completed', 3, 1, array( ebay_variation( 'BLK', 1, 1, array( 'Color' => 'Black' ) ), ebay_variation( 'WHT', 2, 0, array( 'Color' => 'White' ) ) ) );
$ebay->relists = relisted_as( '226100009730' );
$result        = relist( 730 );
check( 'settled by 3.27.0, the parent alone: relisted', outcome( $result ), 'relisted' );
check( '  Black\'s sale brought in, the parent not settled a second time', $GLOBALS['SETTLED'], array( array( 'settle_variations', 730, 'Relist check' ) ) );
check( '  so Black goes up at 0 and White at 1', sent_variations( recorded_variations( relist_calls( $ebay )[0] ?? array() ) ), array( 'BLK' => array( '0', '10.00', 'Color: Black' ), 'WHT' => array( '1', '10.00', 'Color: White' ) ) );
check( '  and the parent keeps its 2', (int) meta( 730, '_stock' ), 2 );

// The parent's own stock reads 0 - the shop counts the colours, not the
// listing - while Red has 2. eBay sold the one Blue. 3.27.2 went by the
// parent's 0 and refused it. The colours decide.
foreach ( array( 1 => 'eBay sold the one Blue', 0 => 'nothing sold on eBay' ) as $blue_sold => $how ) {
	$ebay = fresh_shop();
	$id   = $blue_sold ? 740 : 743;
	$item = sleeves( $id, array( '_stock' => '0', '_stock_status' => 'outofstock' ) );
	sleeve( $id + 1, $id, 'RED', 2, array( 'color' => 'Red' ) );
	sleeve( $id + 2, $id, 'BLU', 1, array( 'color' => 'Blue' ) );
	$ebay->item    = ebay_says_variations( $item, 'Completed', 3, $blue_sold, array( ebay_variation( 'RED', 2, 0, array( 'Color' => 'Red' ) ), ebay_variation( 'BLU', 1, $blue_sold, array( 'Color' => 'Blue' ) ) ) );
	$ebay->relists = relisted_as( '22610000' . $id . '9' );
	$result        = relist( $id );
	check( 'the parent\'s own stock 0, Red still in stock, ' . $how . ': relisted', outcome( $result ), 'relisted' );
	check( '  Red at its 2, Blue at ' . ( $blue_sold ? '0' : 'its 1' ), sent_variations( recorded_variations( relist_calls( $ebay )[0] ?? array() ) ), array( 'RED' => array( '2', '10.00', 'Color: Red' ), 'BLU' => array( $blue_sold ? '0' : '1', '10.00', 'Color: Blue' ) ) );
}

// The other way about: the parent still reads 3, but every colour is out of
// stock here. Nothing to sell, and eBay is not asked.
$ebay = fresh_shop();
$item = sleeves( 746 );
sleeve( 747, 746, 'RED', 0, array( 'color' => 'Red' ) );
sleeve( 748, 746, 'BLU', 0, array( 'color' => 'Blue' ) );
$result = relist( 746 );
check( 'every colour out of stock, whatever the parent reads: not relisted', array( outcome( $result ), is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'no stock to sell' ) ), array( 'relist_refused', true ) );
check( '  and eBay is not asked anything', $ebay->calls, array() );

// A shop that does not count its sleeves: eBay's figures are all there is.
// Red: 3 listed, 1 sold, so 2 go back up. Blue sold its only one.
$ebay = fresh_shop();
$item = sleeves( 750, array( '_manage_stock' => 'no', '_stock' => '' ) );
sleeve( 751, 750, 'RED', null, array( 'color' => 'Red' ) );
sleeve( 752, 750, 'BLU', null, array( 'color' => 'Blue' ) );
$ebay->item    = ebay_says_variations( $item, 'Completed', 4, 2, array( ebay_variation( 'RED', 3, 1, array( 'Color' => 'Red' ) ), ebay_variation( 'BLU', 1, 1, array( 'Color' => 'Blue' ) ) ) );
$ebay->relists = relisted_as( '226100009750' );
check( 'colours with no stock count: relisted', outcome( relist( 750 ) ), 'relisted' );
check( '  each at what eBay had left: Red 2, Blue 0', sent_variations( recorded_variations( relist_calls( $ebay )[0] ?? array() ) ), array( 'RED' => array( '2', '10.00', 'Color: Red' ), 'BLU' => array( '0', '10.00', 'Color: Blue' ) ) );

// The same shop, both colours sold out on eBay: none left to put up.
$ebay = fresh_shop();
$item = sleeves( 753, array( '_manage_stock' => 'no', '_stock' => '' ) );
sleeve( 754, 753, 'RED', null, array( 'color' => 'Red' ) );
sleeve( 755, 753, 'BLU', null, array( 'color' => 'Blue' ) );
$ebay->item    = ebay_says_variations( $item, 'Completed', 2, 2, array( ebay_variation( 'RED', 1, 1, array( 'Color' => 'Red' ) ), ebay_variation( 'BLU', 1, 1, array( 'Color' => 'Blue' ) ) ) );
$ebay->relists = relisted_as( '226100009753' );
$result        = relist( 753 );
check( 'uncounted colours all sold out on eBay: not relisted', array( outcome( $result ), is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'no variation has stock left once eBay\'s sales were brought in' ) ), array( 'relist_refused', true ) );
check( '  both marked out of stock here, as eBay sold them out', array( meta( 754, '_stock_status' ), meta( 755, '_stock_status' ) ), array( 'outofstock', 'outofstock' ) );
check( '  only GetItem was sent', $ebay->calls, array( array( 'GetItem', $item ) ) );

// The shop took Blue off sale - its variation is private now - though eBay
// still had 2 Blue left. Nothing sold on eBay. Blue goes up at 0, not at
// eBay's 2: the shop is not selling it.
$ebay = fresh_shop();
$item = sleeves( 760 );
sleeve( 761, 760, 'RED', 2, array( 'color' => 'Red' ) );
sleeve( 762, 760, 'BLU', 2, array( 'color' => 'Blue' ), 'private' );
$ebay->item    = ebay_says_variations( $item, 'Completed', 4, 0, array( ebay_variation( 'RED', 2, 0, array( 'Color' => 'Red' ) ), ebay_variation( 'BLU', 2, 0, array( 'Color' => 'Blue' ) ) ) );
$ebay->relists = relisted_as( '226100009760' );
check( 'a colour the shop took off sale: relisted', outcome( relist( 760 ) ), 'relisted' );
check( '  Red at its 2, Blue at 0', sent_variations( recorded_variations( relist_calls( $ebay )[0] ?? array() ) ), array( 'RED' => array( '2', '10.00', 'Color: Red' ), 'BLU' => array( '0', '10.00', 'Color: Blue' ) ) );

// Bulk Relist and Auto-Relist go the same way, through the real job handler
// and scheduler.
$ebay = fresh_shop();
$item = sleeves( 765 );
sleeve( 766, 765, 'BLK', 1, array( 'color' => 'Black' ) );
sleeve( 767, 765, 'WHT', 1, array( 'color' => 'White' ) );
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'auto_relist_enabled' => 1 );
$ENDED_ROWS    = array( array( 'product_id' => '765', 'ebay_item_id' => $item ) );
$ebay->item    = ebay_says_variations( $item, 'Completed', 3, 1, array( ebay_variation( 'BLK', 1, 1, array( 'Color' => 'Black' ) ), ebay_variation( 'WHT', 2, 0, array( 'Color' => 'White' ) ) ) );
$ebay->relists = relisted_as( '226100009765' );
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
check( 'Auto-Relist: Black\'s sale in first, then Black at 0 and White at 1', array( array_column( $GLOBALS['SETTLED'], 0 ), sent_variations( recorded_variations( relist_calls( $ebay )[0] ?? array() ) ) ), array( array( 'settle_variations', 'apply_ended_listing_stock' ), array( 'BLK' => array( '0', '10.00', 'Color: Black' ), 'WHT' => array( '1', '10.00', 'Color: White' ) ) ) );

$ebay = fresh_shop();
$item = sleeves( 768 );
sleeve( 769, 768, 'BLK', 1, array( 'color' => 'Black' ) );
$ebay->item    = ebay_says_variations( $item, 'Completed', 1, 1, array( ebay_variation( 'BLK', 1, 1, array( 'Color' => 'Black' ) ) ) );
$ebay->relists = relisted_as( '226100009768' );
$job  = TCGiant_Sync_Jobs::create_job( 'bulk_relist', array( 768 ) );
run_batch( $job );
$done = (array) TCGiant_Sync_Jobs::get_job( $job );
check( 'bulk Relist of sleeves whose one colour sold out on eBay: kept, not relisted', array( $done['succeeded'] ?? null, $done['kept'] ?? null, $done['failed'] ?? null, relist_calls( $ebay ) ), array( 0, 1, 0, array() ) );
check( '  Black brought to 0', (int) meta( 769, '_stock' ), 0 );

echo "\nA PUSH OF A VARIATION LISTING THAT ENDED BRINGS EACH VARIATION'S SALES IN FIRST (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// A push of the sleeves finds their listing ended, and nothing on eBay has
// relisted it. Before the new listing is made, Black's sale comes in, and the
// listing is built again from what is left: Black goes up at 0, not at the 1
// WooCommerce showed. 3.27.2 passed variable products by, and listed Black
// again.
$ebay = fresh_shop( $push_settings );
$item = sleeves( 770 );
sleeve( 771, 770, 'BLK', 1, array( 'color' => 'Black' ) );
sleeve( 772, 770, 'WHT', 1, array( 'color' => 'White' ) );
$ebay->items            = array( $item => ebay_says_variations( $item, 'Completed', 3, 1, array( ebay_variation( 'BLK', 1, 1, array( 'Color' => 'Black' ) ), ebay_variation( 'WHT', 2, 0, array( 'Color' => 'White' ) ) ) ) );
$ebay->revises[ $item ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$result = push( 770 );
$add_at = array_search( array( 'AddFixedPriceItem', '' ), $ebay->calls, true );
$added  = false !== $add_at ? parse_wire( '<AddFixedPriceItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">' . ( $ebay->sent[ $add_at ] ?? '' ) . '</AddFixedPriceItemRequest>' ) : null;
check( 'push of a variation listing that ended: a new listing in its place', is_array( $result ) ? array( $result['action'], $result['previous_item_id'] ) : ( is_wp_error( $result ) ? $result->get_error_message() : $result ), array( 'relisted', $item ) );
check( '  revised and refused, GetItem, then AddFixedPriceItem', array_column( $ebay->calls, 0 ), array( 'ReviseFixedPriceItem', 'GetItem', 'AddFixedPriceItem' ) );
check( '  the variations\' sales brought in before the parent\'s, and nothing trashed', $GLOBALS['SETTLED'], array( array( 'settle_variations', 770, 'Relist check' ), array( 'apply_ended_listing_stock', 770, 3, 1 ) ) );
check( '  Black to 0', (int) meta( 771, '_stock' ), 0 );
check( '  and the new listing built from that: Black 0, White 1', null !== $added ? array_map( function ( $v ) { return $v[0]; }, (array) sent_variations( $added ) ) : null, array( 'BLK' => '0', 'WHT' => '1' ) );

echo "\nTHE HOURLY CHECK AND STOCK REVIEW SETTLE EACH VARIATION, BEFORE THE PARENT (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// A seven-day listing of the sleeves has run its course. The parent's own
// stock reads 0 - the shop counts the colours - while Black still shows the
// 1 eBay sold. 3.27.2 looked at the parent's stock alone, found nothing to
// settle, and marked the listing Ended: Black stayed for sale on the website.
$ebay = fresh_shop();
$item = sleeves( 780, array( '_stock' => '0', '_stock_status' => 'outofstock', '_ebay_listing_status' => 'Active', '_ebay_listing_duration' => 'Days_7' ) );
sleeve( 781, 780, 'BLK', 1, array( 'color' => 'Black' ) );
sleeve( 782, 780, 'WHT', 0, array( 'color' => 'White' ) );
$ebay->item = ebay_says_variations( $item, 'Completed', 2, 1, array( ebay_variation( 'BLK', 1, 1, array( 'Color' => 'Black' ) ), ebay_variation( 'WHT', 1, 0, array( 'Color' => 'White' ) ) ) );
TCGiant_Sync_Cron::instance()->check_ended_listings();
check( 'the hourly check asks eBay about a seven-day variation listing whose parent reads 0', $ebay->calls, array( array( 'GetItem', $item ) ) );
check( '  Black\'s sale comes in: 0, and out of stock', array( (int) meta( 781, '_stock' ), meta( 781, '_stock_status' ) ), array( 0, 'outofstock' ) );
check( '  the variations first, then the parent', array_column( $GLOBALS['SETTLED'], 0 ), array( 'settle_variations', 'apply_ended_listing_stock', 'maybe_remove_sold_out' ) );
check( '  under the hourly check\'s name', $GLOBALS['SETTLED'][0][2] ?? null, 'Ended listing check' );
check( '  and the listing is marked Ended', meta( 780, '_ebay_listing_status' ), 'Ended' );

// A Good 'Til Cancelled listing of the sleeves: the hourly check asks eBay
// whether it has ended, and settles it when it has - the colours first.
$ebay = fresh_shop();
$item = sleeves( 785, array( '_ebay_listing_status' => 'Active', '_ebay_listing_duration' => 'GTC' ) );
sleeve( 786, 785, 'BLK', 1, array( 'color' => 'Black' ) );
sleeve( 787, 785, 'WHT', 2, array( 'color' => 'White' ) );
$ebay->item = ebay_says_variations( $item, 'Completed', 3, 1, array( ebay_variation( 'BLK', 1, 1, array( 'Color' => 'Black' ) ), ebay_variation( 'WHT', 2, 0, array( 'Color' => 'White' ) ) ) );
TCGiant_Sync_Cron::instance()->check_ended_listings();
check( 'a Good \'Til Cancelled variation listing eBay says has ended: the colours first, then the parent', array_column( $GLOBALS['SETTLED'], 0 ), array( 'settle_variations', 'apply_ended_listing_stock', 'maybe_remove_sold_out' ) );
check( '  Black to 0, White kept at 2', array( (int) meta( 786, '_stock' ), (int) meta( 787, '_stock' ) ), array( 0, 2 ) );

// Stock Review: the shop ticks the sleeves and presses Settle from eBay.
$ebay = fresh_shop();
$item = sleeves( 790 );
sleeve( 791, 790, 'BLK', 1, array( 'color' => 'Black' ) );
sleeve( 792, 790, 'WHT', 1, array( 'color' => 'White' ) );
$ebay->item = ebay_says_variations( $item, 'Completed', 3, 1, array( ebay_variation( 'BLK', 1, 1, array( 'Color' => 'Black' ) ), ebay_variation( 'WHT', 2, 0, array( 'Color' => 'White' ) ) ) );
$job  = TCGiant_Sync_Jobs::create_job( 'bulk_settle', array( 790 ) );
run_batch( $job );
$done = (array) TCGiant_Sync_Jobs::get_job( $job );
check( 'Stock Review\'s settle: the colours first, then the parent', $GLOBALS['SETTLED'], array( array( 'settle_variations', 790, 'Stock review' ), array( 'apply_ended_listing_stock', 790, 3, 1 ), array( 'maybe_remove_sold_out', 790, 3, 1 ) ) );
check( '  Black to 0, White kept at 1', array( (int) meta( 791, '_stock' ), (int) meta( 792, '_stock' ) ), array( 0, 1 ) );
check( '  counted as settled', array( $done['succeeded'] ?? null, $done['failed'] ?? null ), array( 1, 0 ) );

// eBay's answer has the listing's figures and no variations: the colours
// cannot be checked, and the parent is not settled on the listing's alone.
$ebay = fresh_shop();
$item = sleeves( 795 );
sleeve( 796, 795, 'BLK', 1, array( 'color' => 'Black' ) );
$ebay->item = listing_says( $item, 'Completed', 3, 1 );
$job    = TCGiant_Sync_Jobs::create_job( 'bulk_settle', array( 795 ) );
run_batch( $job );
$done   = (array) TCGiant_Sync_Jobs::get_job( $job );
$errors = implode( "\n", (array) ( $done['errors'] ?? array() ) );
check( 'eBay reports no variations: failed, the stock left as it was', array( $done['succeeded'] ?? null, $done['failed'] ?? null, (int) meta( 795, '_stock' ), (int) meta( 796, '_stock' ) ), array( 0, 1, 3, 1 ) );
check( '  the shop is told why', false !== strpos( $errors, '#795: eBay did not report the variations of this listing; their stock was not checked.' ), true );
check( '  the parent not settled', in_array( 'apply_ended_listing_stock', array_column( $GLOBALS['SETTLED'], 0 ), true ), false );

echo "\nA VARIATION WHOSE STOCK COULD NOT BE SET IS NOT LISTED AGAIN (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// The sleeves again, on a site where another plugin holds Black's quantity:
// the write that would bring Black's eBay sale in does not take. Relisted,
// Black would go back up at the 1 WooCommerce still shows - a sleeve eBay sold.
$ebay = fresh_shop();
$item = sleeves( 800 );
sleeve( 801, 800, 'BLK', 1, array( 'color' => 'Black' ) );
sleeve( 802, 800, 'WHT', 1, array( 'color' => 'White' ) );
$GLOBALS['REFUSE_STOCK'] = array( 801 );
$ebay->item    = ebay_says_variations( $item, 'Completed', 3, 1, array( ebay_variation( 'BLK', 1, 1, array( 'Color' => 'Black' ) ), ebay_variation( 'WHT', 2, 0, array( 'Color' => 'White' ) ) ) );
$ebay->relists = relisted_as( '226100009800' );
$result        = relist( 800 );
check( 'Black\'s stock write did not take: not relisted', array( outcome( $result ), is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'the sales on eBay listing ' . $item . ' could not be brought into the stock of every variation, so it was not listed again' ) ), array( 'relist_unchecked', true ) );
check( '  only GetItem was sent', $ebay->calls, array( array( 'GetItem', $item ) ) );
check( '  Black still 1, the product still on the old listing, nothing noted as settled', array( (int) meta( 801, '_stock' ), meta( 800, '_ebay_item_id' ), meta( 800, '_ebay_variations_settled_item' ), meta( 800, '_ebay_stock_settled_item' ) ), array( 1, $item, null, null ) );
check( '  the parent not settled either, so the next attempt checks both', array_column( $GLOBALS['SETTLED'], 0 ), array( 'settle_variations' ) );
check( '  the shop is told to set Black by hand', count( log_lines_naming( 'Relist check: eBay item ' . $item . ' has ended, but the stock of variation(s) #801 of WC #800 could not be set to eBay\'s figures' ) ), 1 );
$GLOBALS['REFUSE_STOCK'] = array();
$ebay->calls             = array();
$result                  = relist( 800 );
check( 'once Black can be set: relisted, Black at 0', array( outcome( $result ), sent_variations( recorded_variations( relist_calls( $ebay )[0] ?? array() ) ) ), array( 'relisted', array( 'BLK' => array( '0', '10.00', 'Color: Black' ), 'WHT' => array( '1', '10.00', 'Color: White' ) ) ) );

// The same on a push of the sleeves after their listing ended.
$ebay = fresh_shop( $push_settings );
$item = sleeves( 803 );
sleeve( 804, 803, 'BLK', 1, array( 'color' => 'Black' ) );
$GLOBALS['REFUSE_STOCK'] = array( 804 );
$ebay->items            = array( $item => ebay_says_variations( $item, 'Completed', 1, 1, array( ebay_variation( 'BLK', 1, 1, array( 'Color' => 'Black' ) ) ) ) );
$ebay->revises[ $item ] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$result = push( 803 );
check( 'a push, Black\'s write not taking: nothing new listed', array( is_wp_error( $result ) ? $result->get_error_code() : 'pushed', array_column( $ebay->calls, 0 ) ), array( 'relist_unchecked', array( 'ReviseFixedPriceItem', 'GetItem' ) ) );
check( '  Black still 1', (int) meta( 804, '_stock' ), 1 );

echo "\nA RELIST THAT CANNOT SEND THE STOCK THE SHOP HAS SAYS SO, ONCE (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// A shop that does not count its sleeves. eBay sold every colour, and the
// sale is settled: both were marked out of stock. The shop marked them in
// stock again. A relist can only send what eBay had left of a colour no one
// counts - none - so it cannot list them. The shop is told once to push
// instead, and Auto-Relist does not ask eBay again every day.
$ebay = fresh_shop();
$item = sleeves( 805, array( '_manage_stock' => 'no', '_stock' => '', '_ebay_stock_settled_at' => '2026-10-12 10:00:00' ) );
$GLOBALS['META'][805]['_ebay_stock_settled_item']      = $item;
$GLOBALS['META'][805]['_ebay_variations_settled_item'] = $item;
sleeve( 806, 805, 'RED', null, array( 'color' => 'Red' ) );
sleeve( 807, 805, 'BLU', null, array( 'color' => 'Blue' ) );
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'auto_relist_enabled' => 1 );
$ENDED_ROWS    = array( array( 'product_id' => '805', 'ebay_item_id' => $item ) );
$ebay->item    = ebay_says_variations( $item, 'Completed', 2, 2, array( ebay_variation( 'RED', 1, 1, array( 'Color' => 'Red' ) ), ebay_variation( 'BLU', 1, 1, array( 'Color' => 'Blue' ) ) ) );
$ebay->relists = relisted_as( '226100009805' );
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
check( 'uncounted colours marked in stock again after eBay sold them: Auto-Relist asks eBay, and does not relist', array( $ebay->calls, relist_calls( $ebay ) ), array( array( array( 'GetItem', $item ) ), array() ) );
check( '  the shop is told why, and to push it', array_column( log_lines_naming( 'WC #805 was not relisted: its stock is on variations eBay listing ' . $item . ' does not have, or on shared or untracked stock eBay sold out of, which a relist cannot raise. Push the product to list it again.' ), 0 ), array( 'warning' ) );
$ebay->calls = array();
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
check( '  the next day: eBay not asked again, and nothing said again', array( $ebay->calls, count( log_lines_naming( 'WC #805 was not relisted' ) ) ), array( array(), 1 ) );
$result = relist( 805 );
check( '  a Bulk Relist by hand still asks, gets the same answer, and the log does not repeat it', array( outcome( $result ), array_column( $ebay->calls, 0 ), count( log_lines_naming( 'WC #805 was not relisted' ) ) ), array( 'relist_refused', array( 'GetItem' ), 1 ) );
$ebay->calls = array();
$GLOBALS['META'][806]['_manage_stock'] = 'yes';
$GLOBALS['META'][806]['_stock']        = '2';
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
check( '  the shop counts 2 Red: asked again, and relisted with Red at 2', array( array_column( $ebay->calls, 0 ), sent_variations( recorded_variations( relist_calls( $ebay )[0] ?? array() ) ) ), array( array( 'GetItem', 'RelistFixedPriceItem' ), array( 'RED' => array( '2', '10.00', 'Color: Red' ), 'BLU' => array( '0', '10.00', 'Color: Blue' ) ) ) );

echo "\nA VARIATION LISTING'S SALES COME IN BEFORE IT IS LISTED AGAIN, IN THE SOURCE (3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

$var_check = method_code( $exporter_file, 'ended_listing_check' );
$settles   = at( $var_check, 'settle_variations(' );
check( 'the check no longer passes a variable product by', preg_match( '/if\s*\([^{]*is_type\(\s*\'variable\'\s*\)[^{]*\{\s*return\s+false\s*;/', $var_check ), 0 );
check( '  it settles the variations', $settles > 0, true );
check( '  before the listing\'s figures go into the parent', $settles > 0 && at( $var_check, 'apply_ended_listing_stock(' ) > $settles, true );
check( '  and still never trashes the product', false === strpos( $var_check, 'maybe_remove_sold_out(' ), true );
check( 'the relist builds the variations it sends', false !== strpos( $guarded, 'relist_variations_xml(' ), true );
check( '  and passes them to RelistFixedPriceItem, as its third argument', 1 === preg_match( '/->\s*relist_fixed_price_item\s*\(\s*[^;()]*,[^;()]*,\s*\$\w+\s*\)/', $guarded ), true );

echo "\nA LISTING ON ANOTHER EBAY SITE IS NEVER PUT BACK UP (3.27.5)\n" . str_repeat( '=', 126 ) . "\n";

// Rabbi Ent lists on eBay.com, and Web Interpret copies every listing onto
// eBay.co.uk, eBay.de and the rest. With "Import listings from" set to eBay
// United States only, a product imported from one of those copies before the
// setting was chosen keeps syncing until the shop removes it - but relisting
// it would put a listing back up on a site the shop has said to leave alone.
$only_us = array( 'auto_relist_enabled' => 1, 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' );

/** GetItem's answer for a listing on the given eBay site. */
function ebay_says_on( $site, $status, $listed, $sold ) {
	$answer                 = ebay_says( $status, $listed, $sold );
	$answer['Item']['Site'] = $site;
	return $answer;
}

// The site already recorded on the product: refused before eBay is asked.
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
$uk_item = coin( 701, array( '_ebay_site' => 'UK' ) );
$ebay->item    = ebay_says_on( 'UK', 'Completed', 3, 0 );
$ebay->relists = relisted_as( '226100009701' );
$result = relist( 701 );
check( 'recorded on eBay United Kingdom: refused', outcome( $result ), 'relist_refused' );
check( '  saying where it is listed and what the setting is', is_wp_error( $result ) ? $result->get_error_message() : '', 'its eBay listing is on eBay United Kingdom, and Import listings from is set to eBay United States only' );
check( '  before eBay is asked anything - not even GetItem', $ebay->calls, array() );
check( '  still pointing at its own listing', meta( 701, '_ebay_item_id' ), $uk_item );

// Not recorded yet (imported before 3.27.5): eBay's answer says, and the
// sale it reports is still brought into the stock from that same answer.
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
$de_item = coin( 702 );
$ebay->item    = ebay_says_on( 'Germany', 'Completed', 3, 1 );
$ebay->relists = relisted_as( '226100009702' );
$result = relist( 702 );
check( 'no site recorded, eBay says Germany: refused', outcome( $result ), 'relist_refused' );
check( '  naming eBay Germany', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'is on eBay Germany' ), true );
check( '  after one GetItem and no relist', $ebay->calls, array( array( 'GetItem', $de_item ) ) );
check( '  the sale eBay reported is still settled from that answer', $GLOBALS['SETTLED'][0] ?? null, array( 'apply_ended_listing_stock', 702, 3, 1 ) );
check( '  and the site is recorded, so next time nothing is asked', meta( 702, '_ebay_site' ), 'Germany' );
$ebay->calls = array();
check( '  asked again: refused at once', array( outcome( relist( 702 ) ), $ebay->calls ), array( 'relist_refused', array() ) );

// The shop's own site, and eBay Motors, which is part of it, are relisted.
foreach ( array( 'US' => 703, 'eBayMotors' => 704 ) as $site => $id ) {
	$ebay = fresh_shop();
	$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
	$item = coin( $id, array( '_ebay_site' => $site ) );
	$ebay->item    = ebay_says_on( $site, 'Completed', 3, 0 );
	$ebay->relists = relisted_as( '22610000' . $id );
	check( sprintf( 'recorded on %s: relisted', $site ), outcome( relist( $id ) ), 'relisted' );
	check( '  through the relist call, at its quantity', $ebay->relists_of( $item ), array( array( 'RelistFixedPriceItem', $item, 3 ) ) );
}

// Recorded as the shop's own site, wrongly - a product linked by hand to a
// different listing, say: eBay's answer, already asked for, still decides. The
// first build read it only when nothing was recorded, and relisted the copy.
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
$uk_item = coin( 708, array( '_ebay_site' => 'US' ) );
$ebay->item    = ebay_says_on( 'UK', 'Completed', 3, 0 );
$ebay->relists = relisted_as( '226100009708' );
$result = relist( 708 );
check( 'recorded as eBay United States, eBay says eBay United Kingdom: refused', is_wp_error( $result ) ? $result->get_error_message() : outcome( $result ), 'its eBay listing is on eBay United Kingdom, and Import listings from is set to eBay United States only' );
check( '  after the one GetItem, and no relist', $ebay->calls, array( array( 'GetItem', $uk_item ) ) );
check( '  and the site eBay gave is recorded', meta( 708, '_ebay_site' ), 'UK' );

// Pushed before makes no difference. A copy revised once by Push - select all
// on the Listings screen, Push - carries the same marks as a product the shop
// pushed itself, and the second build spared both: the copy went back up on
// eBay.co.uk beside the one Web Interpret makes. A shop that moved from eBay US
// to eBay UK is told why its eBay.com listings are not relisted instead.
$pushed = array( '_ebay_export_last_pushed' => '2025-01-01 10:00:00', '_ebay_export_status' => 'pushed', '_ebay_export_pushed_version' => '3.27.5' );
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
$own_item = coin( 709, array_merge( $pushed, array( '_ebay_site' => 'UK' ) ) );
$ebay->item    = ebay_says_on( 'UK', 'Completed', 3, 0 );
$ebay->relists = relisted_as( '226100009709' );
$result = relist( 709 );
check( 'pushed before (revised once by Push), recorded on eBay United Kingdom: refused', is_wp_error( $result ) ? $result->get_error_message() : outcome( $result ), 'its eBay listing is on eBay United Kingdom, and Import listings from is set to eBay United States only' );
check( '  before eBay is asked anything', $ebay->calls, array() );
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
coin( 710, array( '_ebay_site' => 'UK', '_ebay_export_status' => 'error' ) );
$ebay->relists = relisted_as( '226100009710' );
check( 'imported from another site, its push failed: refused', array( outcome( relist( 710 ) ), $ebay->calls ), array( 'relist_refused', array() ) );
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
$own_item = coin( 711, array( '_ebay_export_pushed_version' => '3.20.0' ) );
$ebay->item    = ebay_says_on( 'Germany', 'Completed', 3, 0 );
$ebay->relists = relisted_as( '226100009711' );
check( 'pushed before, no site recorded, eBay says eBay Germany: refused', outcome( relist( 711 ) ), 'relist_refused' );
check( '  after the one GetItem, and no relist', $ebay->calls, array( array( 'GetItem', $own_item ) ) );
check( '  and the site recorded from the answer', meta( 711, '_ebay_site' ), 'Germany' );
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'auto_relist_enabled' => 1, 'import_sites' => 'primary', 'marketplace' => 'EBAY_GB' );
coin( 712, array_merge( $pushed, array( '_ebay_site' => 'US' ) ) );
$ebay->relists = relisted_as( '226100009712' );
$result = relist( 712 );
check( 'the shop moved to eBay United Kingdom only: its own eBay.com listing is refused, saying why', is_wp_error( $result ) ? $result->get_error_message() : outcome( $result ), 'its eBay listing is on eBay United States, and Import listings from is set to eBay United Kingdom only' );

// eBay says nothing about the site: let through, as everywhere else.
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
coin( 705 );
$ebay->item    = ebay_says( 'Completed', 3, 0 );
$ebay->relists = relisted_as( '226100009705' );
check( 'no site anywhere: relisted, not refused on a guess', outcome( relist( 705 ) ), 'relisted' );
check( '  and no site recorded', meta( 705, '_ebay_site' ), null );

// All eBay sites, the default for every shop: nothing changes.
foreach ( array(
	'never chosen'   => array( 'auto_relist_enabled' => 1, 'marketplace' => 'EBAY_US' ),
	'All eBay sites' => array( 'auto_relist_enabled' => 1, 'marketplace' => 'EBAY_US', 'import_sites' => 'all' ),
) as $how => $settings ) {
	$ebay = fresh_shop();
	$OPTIONS['tcgiant_sync_ebay_settings'] = $settings;
	coin( 706, array( '_ebay_site' => 'UK' ) );
	$ebay->item    = ebay_says_on( 'UK', 'Completed', 3, 0 );
	$ebay->relists = relisted_as( '226100009706' );
	check( sprintf( 'import listings from: %s - the eBay United Kingdom listing is relisted as before', $how ), outcome( relist( 706 ) ), 'relisted' );
}

// Bulk Relist, through the real job handler: kept, with the reason shown.
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
coin( 707, array( '_ebay_site' => 'UK' ) );
$ebay->relists = relisted_as( '226100009707' );
$job  = TCGiant_Sync_Jobs::create_job( 'bulk_relist', array( 707 ) );
$sent = run_batch( $job );
check( 'bulk Relist of it: kept, not failed', array( $sent['data']['kept'] ?? null, $sent['data']['failed'] ?? null, $sent['data']['succeeded'] ?? null ), array( 1, 0, 0 ) );
check( '  the shop is told why', false !== strpos( implode( ' ', (array) ( $sent['data']['errors'] ?? array() ) ), '#707: not relisted - its eBay listing is on eBay United Kingdom' ), true );
check( '  and eBay is not asked', $ebay->calls, array() );

echo "\nAUTO-RELIST LEAVES OTHER SITES' PRODUCTS OUT OF ITS FIFTY (3.27.5)\n" . str_repeat( '=', 126 ) . "\n";

// Sixty products from eBay United Kingdom copies have ended and still have
// stock; one coin on the shop's own eBay.com listing has too, and comes
// sixty-first. Fifty a day are taken. Refused one by one, the sixty would take
// every place, every day, at a GetItem each, and the coin would never be
// relisted.
$ebay = fresh_shop();
$GLOBALS['QUERIES'] = array();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
$ENDED_ROWS = array();
for ( $i = 0; $i < 60; $i++ ) {
	$uk           = coin( 800 + $i, array( '_ebay_site' => 'UK' ) );
	$ENDED_ROWS[] = array( 'product_id' => (string) ( 800 + $i ), 'ebay_item_id' => $uk );
}
$us_coin      = coin( 899, array( '_ebay_site' => 'US' ) );
$ENDED_ROWS[] = array( 'product_id' => '899', 'ebay_item_id' => $us_coin );
$ebay->items   = array( $us_coin => ebay_says_on( 'US', 'Completed', 3, 0 ) );
$ebay->relists = function ( $item_id ) { return relisted_as( '9' . substr( $item_id, 1 ) ); };
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
check( 'the coin on the shop\'s own site is relisted', $ebay->relists_of( $us_coin ), array( array( 'RelistFixedPriceItem', $us_coin, 3 ) ) );
check( '  and eBay asked about nothing else', array_values( array_unique( array_column( $ebay->calls, 1 ) ) ), array( $us_coin ) );
check( '  because the query itself leaves them out', false !== strpos( (string) end( $GLOBALS['QUERIES'] ), "tcg_site.meta_value NOT IN ( 'US', 'eBayMotors' )" ), true );
check( '  by each product\'s recorded site, in the listings table\'s query', false !== strpos( (string) end( $GLOBALS['QUERIES'] ), 'tcg_site.post_id = l.product_id' ), true );
check( 'the run says what it did', logged( 'Auto-Relist: Complete. 1 relisted, 0 not relisted' ), true );

// The same shop without the listings table: auto-relist reads post meta
// instead, and leaves the same products out by that query's own product column.
$ebay = fresh_shop();
$GLOBALS['QUERIES']      = array();
$GLOBALS['TABLE_EXISTS'] = false;
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
$ENDED_ROWS = array();
for ( $i = 0; $i < 60; $i++ ) {
	$uk           = coin( 800 + $i, array( '_ebay_site' => 'UK' ) );
	$ENDED_ROWS[] = array( 'post_id' => (string) ( 800 + $i ), 'ebay_item_id' => $uk );
}
$us_coin      = coin( 899, array( '_ebay_site' => 'US' ) );
$ENDED_ROWS[] = array( 'post_id' => '899', 'ebay_item_id' => $us_coin );
$ebay->items   = array( $us_coin => ebay_says_on( 'US', 'Completed', 3, 0 ) );
$ebay->relists = function ( $item_id ) { return relisted_as( '9' . substr( $item_id, 1 ) ); };
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
$auto_query              = (string) end( $GLOBALS['QUERIES'] );
$GLOBALS['TABLE_EXISTS'] = true;
check( 'no listings table: the coin on the shop\'s own site is still relisted', $ebay->relists_of( $us_coin ), array( array( 'RelistFixedPriceItem', $us_coin, 3 ) ) );
check( '  and eBay asked about nothing else', array_values( array_unique( array_column( $ebay->calls, 1 ) ) ), array( $us_coin ) );
check( '  the post meta query leaves the others out, by its own product column', array( false !== strpos( $auto_query, 'FROM wp_postmeta pm1' ), false !== strpos( $auto_query, 'tcg_site.post_id = pm1.post_id' ) ), array( true, true ) );

// A product pushed before, recorded on another site, is left out with the
// copies: Relist would refuse it, so it would only take a place. The second
// build let it through, and Relist put it back up.
$ebay = fresh_shop();
$GLOBALS['QUERIES'] = array();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
$ENDED_ROWS = array();
for ( $i = 0; $i < 60; $i++ ) {
	$uk           = coin( 800 + $i, array( '_ebay_site' => 'UK' ) );
	$ENDED_ROWS[] = array( 'product_id' => (string) ( 800 + $i ), 'ebay_item_id' => $uk );
}
$pushed_coin  = coin( 898, array( '_ebay_site' => 'UK', '_ebay_export_last_pushed' => '2025-01-01 10:00:00', '_ebay_export_status' => 'pushed' ) );
$ENDED_ROWS[] = array( 'product_id' => '898', 'ebay_item_id' => $pushed_coin );
$us_coin      = coin( 899, array( '_ebay_site' => 'US' ) );
$ENDED_ROWS[] = array( 'product_id' => '899', 'ebay_item_id' => $us_coin );
$ebay->items   = array(
	$pushed_coin => ebay_says_on( 'UK', 'Completed', 3, 0 ),
	$us_coin     => ebay_says_on( 'US', 'Completed', 3, 0 ),
);
$ebay->relists = function ( $item_id ) { return relisted_as( '9' . substr( $item_id, 1 ) ); };
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
check( 'pushed before, recorded on eBay United Kingdom: not relisted by auto-relist', $ebay->relists_of( $pushed_coin ), array() );
check( '  nor asked about: only the coin on the shop\'s own site is', array_values( array_unique( array_column( $ebay->calls, 1 ) ) ), array( $us_coin ) );
check( '  because the query lets nothing on another site through', false === strpos( (string) end( $GLOBALS['QUERIES'] ), 'tcg_own' ), true );
check( 'the summary names other sites among the reasons a product is passed over', logged( 'Auto-Relist: Complete. 1 relisted, 0 not relisted (no stock, sold out on eBay, already live, on another eBay site, or its last push is still under way or did not finish).' ), true );

echo "\nTHE HOURLY ENDED CHECK RECORDS THE SITE EBAY GIVES (3.27.5)\n" . str_repeat( '=', 126 ) . "\n";

// The hourly check settles a listing it finds ended from a GetItem, and an
// ended listing is never read by a sync again: the full sync reads live ones
// only. Its site is recorded from that answer, at no cost, so Relist, Push and
// auto-relist refuse an ended copy before asking eBay, and Stock Review lists it.
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
$fixed_uk = coin( 720, array( '_ebay_listing_status' => 'Active', '_ebay_end_time' => '2026-01-01T00:00:00.000Z', '_ebay_listing_duration' => 'Days_7' ) );
$gtc_de   = coin( 721, array( '_ebay_listing_status' => 'Active', '_ebay_end_time' => '2026-01-01T00:00:00.000Z', '_ebay_listing_duration' => 'GTC' ) );
$gtc_us   = coin( 722, array( '_ebay_listing_status' => 'Active', '_ebay_end_time' => '2026-01-01T00:00:00.000Z', '_ebay_listing_duration' => 'GTC' ) );
$ENDED_ROWS = array(
	(object) array( 'ID' => '720', 'duration' => 'Days_7' ),
	(object) array( 'ID' => '721', 'duration' => 'GTC' ),
	(object) array( 'ID' => '722', 'duration' => 'GTC' ),
);
$ebay->items = array(
	$fixed_uk => ebay_says_on( 'UK', 'Completed', 3, 0 ),
	$gtc_de   => ebay_says_on( 'Germany', 'Completed', 3, 0 ),
	$gtc_us   => ebay_says_on( 'US', 'Active', 3, 0 ),
);
TCGiant_Sync_Cron::instance()->check_ended_listings();
check( 'a fixed-length listing settled: recorded on eBay United Kingdom', array( meta( 720, '_ebay_listing_status' ), meta( 720, '_ebay_site' ) ), array( 'Ended', 'UK' ) );
check( 'a GTC listing found ended: recorded on eBay Germany', array( meta( 721, '_ebay_listing_status' ), meta( 721, '_ebay_site' ) ), array( 'Ended', 'Germany' ) );
check( 'a GTC listing still live: recorded on eBay United States', array( meta( 722, '_ebay_listing_status' ), meta( 722, '_ebay_site' ) ), array( 'Active', 'US' ) );
$ebay->calls = array();
check( '  then Relist refuses the ended copy before eBay is asked', array( outcome( relist( 720 ) ), $ebay->calls ), array( 'relist_refused', array() ) );
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
$no_site = coin( 723, array( '_ebay_listing_status' => 'Active', '_ebay_end_time' => '2026-01-01T00:00:00.000Z', '_ebay_listing_duration' => 'GTC', '_ebay_site' => 'UK' ) );
$ENDED_ROWS  = array( (object) array( 'ID' => '723', 'duration' => 'GTC' ) );
$ebay->items = array( $no_site => ebay_says( 'Completed', 3, 0 ) );
TCGiant_Sync_Cron::instance()->check_ended_listings();
check( 'an answer with no site leaves the recorded one alone', array( meta( 723, '_ebay_listing_status' ), meta( 723, '_ebay_site' ) ), array( 'Ended', 'UK' ) );

$ebay = fresh_shop();
$GLOBALS['QUERIES'] = array();
$OPTIONS['tcgiant_sync_ebay_settings'] = array( 'auto_relist_enabled' => 1, 'marketplace' => 'EBAY_US' );
$ENDED_ROWS = array( array( 'product_id' => '899', 'ebay_item_id' => '226100000899' ) );
TCGiant_Sync_Cron::instance()->auto_relist_ended_items();
check( 'all eBay sites: auto-relist\'s query is as it was', false === strpos( (string) end( $GLOBALS['QUERIES'] ), 'tcg_site' ), true );

echo "\nA COPY ON ANOTHER EBAY SITE, RELISTED ON EBAY, IS NOT FOLLOWED BY RELIST EITHER (3.27.5 WITH 3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// 3.28.0 follows a relist eBay names before deciding anything else, and moves
// the product on to it; 3.27.5 refuses a copy on another site from eBay's
// answer. A copy with no site recorded, relisted on its own site by Web
// Interpret: the site decides first, from the old listing's answer, and the
// product is not moved on to the copy's relist.
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
$de_copy   = coin( 740 );
$de_relist = '226100009740';
$ebay->items = array(
	$de_copy   => listing_says( $de_copy, 'Completed', 3, 0, relisted_on_ebay_as( $de_relist ) + array( 'Site' => 'Germany' ) ),
	$de_relist => listing_says( $de_relist, 'Active', 3, 0, array( 'Site' => 'Germany' ) ),
);
$ebay->relists = relisted_as( '226100099740' );
$result        = relist( 740 );
check( 'a copy on eBay Germany, relisted there: refused for its site', is_wp_error( $result ) ? $result->get_error_message() : outcome( $result ), 'its eBay listing is on eBay Germany, and Import listings from is set to eBay United States only' );
check( '  not moved on to the relist', array( meta( 740, '_ebay_item_id' ), $GLOBALS['FOLLOWED'] ), array( $de_copy, array() ) );
check( '  eBay asked about its own listing only', $ebay->calls, array( array( 'GetItem', $de_copy ) ) );
check( '  and its site recorded, so the next refusal asks nothing', meta( 740, '_ebay_site' ), 'Germany' );
// The shop's own site: followed, as 3.28.0 follows it.
$ebay = fresh_shop();
$OPTIONS['tcgiant_sync_ebay_settings'] = $only_us;
$us_coin   = coin( 741 );
$us_relist = '226100009741';
$ebay->items = array(
	$us_coin   => listing_says( $us_coin, 'Completed', 3, 0, relisted_on_ebay_as( $us_relist ) + array( 'Site' => 'US' ) ),
	$us_relist => listing_says( $us_relist, 'Active', 3, 0, array( 'Site' => 'US' ) ),
);
$ebay->relists = relisted_as( '226100099741' );
check( '  one on eBay United States: moved on to its relist, as before', array( outcome( relist( 741 ) ), meta( 741, '_ebay_item_id' ) ), array( 'relist_refused', $us_relist ) );

echo "\nA PUSH KILLED REVISING THE RELIST IT FOLLOWED SAYS SO, ON THAT LISTING (3.27.4 WITH 3.28.0)\n" . str_repeat( '=', 126 ) . "\n";

// 3.27.4 records, before eBay is asked, which listing a push began on and
// whether it had ended; a push PHP kills says so from that. 3.28.0's push
// then moves the product on to a relist eBay names, and revises it. Killed
// there, the record said the product was on the listing that had ended and
// that the push was making a new one in its place: Unlink, Link the new item
// number, "pushing it again would list it twice" - while the product was on
// the live relist, and nothing new could have been made.
$ebay = fresh_shop( $push_settings );
$item = coin( 745, array( '_ebay_listing_status' => 'Active' ) );
$relist_id = '226100009745';
$ebay->items = array(
	$item      => listing_says( $item, 'Completed', 3, 0, relisted_on_ebay_as( $relist_id ) ),
	$relist_id => listing_says( $relist_id, 'Active', 3, 0 ),
);
$ebay->revises[ $item ]      = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$ebay->revises[ $relist_id ] = function () {
	// PHP kills the request here: the record of the death runs.
	TCGiant_Sync_Exporter::on_push_death( 'Push stopped by a PHP fatal error' );
	throw new RuntimeException( 'the request was killed' );
};
push_from_queue( 745 );
$said = (string) meta( 745, '_ebay_export_error' );
check( 'killed revising the relist it followed: the product follows that relist', meta( 745, '_ebay_item_id' ), $relist_id );
check( '  and its push did not finish, saying so', meta( 745, '_ebay_export_status' ), 'failed' );
check( '  naming the relist it is linked to, not the listing that had ended', array( false !== strpos( $said, 'It is linked to eBay listing ' . $relist_id . '.' ), false !== strpos( $said, $item ) ), array( true, false ) );
check( '  and not saying it was making a new listing in place of one that had ended', false !== strpos( $said, 'which had ended, so this push was making a new one' ), false );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
