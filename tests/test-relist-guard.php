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
 * TCGiant_Sync_Catalog, and the bulk job handler and daily scheduler that
 * call the relist (TCGiant_Sync_Jobs, TCGiant_Sync_Cron), with WordPress and
 * WooCommerce stubbed. For the exporter's decisions eBay is a subclass of the
 * real API class that records what it was asked and answers from a script.
 * For what is actually sent, the real API class runs with only the HTTP call
 * captured, so the RelistItem and RelistFixedPriceItem requests checked here
 * are the bytes that would go to eBay. Nothing leaves the machine.
 *
 * The last section fails on 3.27.0 on purpose: a shop's restock made after a
 * sell-out was already settled is settled away again by the guard.
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

$POSTS    = array(); // id => array( 'status' => ..., 'type' => ... )
$META     = array(); // id => array( meta_key => value ), read by WooCommerce and written by update_post_meta() alike
$OPTIONS  = array();
$SETTINGS = array(); // what TCGiant_Sync_OAuth::get_settings() hands back
$LOG      = array();
$ROWS     = array(); // TCGiant_Sync_DB::upsert() calls
$SETTLED  = array(); // TCGiant_Sync_Inventory calls
$WIRE     = array(); // every request that reached the HTTP layer
$ANSWERS  = array(); // eBay call name => inner XML of its answer

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
	/**
	 * The ended rows, as the database would return them for the query asked:
	 * with its LIMIT, and without products recorded on another eBay site when
	 * the query keeps those out (3.27.5).
	 */
	public function get_results( $query, $output = null ) {
		$GLOBALS['QUERIES'][] = $query;
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
	public function __construct( $id ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_name() { return 'Coin #' . $this->id; }
	public function get_meta( $key, $single = true ) { return $GLOBALS['META'][ $this->id ][ $key ] ?? ''; }
	public function is_type( $type ) { return $type === ( $GLOBALS['POSTS'][ $this->id ]['type'] ?? 'simple' ); }
	public function managing_stock() { return 'yes' === $this->get_meta( '_manage_stock' ); }
	public function get_stock_quantity() { return $this->managing_stock() ? (int) $this->get_meta( '_stock' ) : null; }
	public function get_stock_status() { return $this->get_meta( '_stock_status' ) ?: 'instock'; }
	public function is_in_stock() { return 'outofstock' !== $this->get_stock_status(); }
}
class WC_Product_Variable extends WC_Product {}

function wc_get_product( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['POSTS'][ $id ] ) ) {
		return false;
	}
	return 'variable' === $GLOBALS['POSTS'][ $id ]['type'] ? new WC_Product_Variable( $id ) : new WC_Product( $id );
}

// The plugin's neighbours.
class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
}

class TCGiant_Sync_DB {
	public static function upsert( $data ) { $GLOBALS['ROWS'][] = $data; return true; }
	public static function table_name() { return 'wp_tcgiant_sync_listings'; }
	// A site whose listings table was never created reads from post meta.
	public static function table_exists() { return $GLOBALS['TABLE_EXISTS'] ?? true; }
}

// The settle is tested on its own elsewhere; here only whether it is asked.
// The settle's real rule - it only ever lowers stock, and notes which listing
// it settled - is tested against the real class in test-ended-settle.php. Here
// it is recorded, and applied to the fake store so the relist path can read
// back what the stock came to.
class TCGiant_Sync_Inventory {
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
	public static function restocked_after_website_sale( $product_id ) {
		return (int) ( $GLOBALS['RESTOCKED'][ (int) $product_id ] ?? 0 );
	}
	public static function clear_settled_mark( $product_id ) {
		foreach ( array( '_ebay_stock_settled_at', '_ebay_stock_settled_item', '_ebay_unsold_at_end', '_ebay_quantity_listed', '_ebay_quantity_sold' ) as $key ) {
			unset( $GLOBALS['META'][ (int) $product_id ][ $key ] );
		}
	}
	public static function maybe_remove_sold_out( $product_id, $listed, $sold ) {
		$GLOBALS['SETTLED'][] = array( 'maybe_remove_sold_out', (int) $product_id, null === $listed ? null : (int) $listed, null === $sold ? null : (int) $sold );
		return false;
	}
}

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
	return array(
		'headers'  => array(),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'body'     => '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<' . $call . 'Response xmlns="urn:ebay:apis:eBLBaseComponents">' . $inner . '</' . $call . 'Response>',
	);
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? ''; }
function wp_remote_retrieve_header( $response, $header ) { return $response['headers'][ $header ] ?? ''; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }

require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
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
	public function relist_fixed_price_item( $item_id, $quantity = null ) {
		$this->calls[] = array( 'RelistFixedPriceItem', (string) $item_id, $quantity );
		return is_callable( $this->relists ) ? call_user_func( $this->relists, (string) $item_id ) : $this->relists;
	}
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

// A variation listing carries its own quantities; RelistItem would drop them.
$ebay          = fresh_shop();
$item          = coin( 524, array( '_manage_stock' => 'no', '_stock' => '' ), 'publish', 'variable' );
$ebay->item    = ebay_says( 'Completed', 6, 2 );
$ebay->relists = relisted_as( '226100009524' );
check( 'a variable product is relisted', outcome( relist( 524 ) ), 'relisted' );
check( '  through RelistFixedPriceItem, with no quantity of its own', $ebay->calls[1] ?? null, array( 'RelistFixedPriceItem', $item, null ) );

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

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
