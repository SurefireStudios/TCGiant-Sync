<?php
/**
 * Harness: a pushed listing finds its product again (3.28.7).
 *
 * A push the host kills after eBay's AddFixedPriceItem has made the listing,
 * but before the product is saved, leaves a live listing nothing here points
 * to. The next sync imported it as a new product - a copy of the one pushed -
 * unless the SKU match caught it, which it cannot when the product has no
 * SKU, the eBay SKU goes to the Bin Location, or the product was pushed
 * again. Every new listing a push makes now carries Item.ApplicationData
 * naming this site and the product, and an import that meets a listing with
 * no product reads it first: the product it names is linked to the listing
 * as the push would have linked it, and nothing is imported.
 *
 * The push side runs in a process of its own
 * (tests/support/push-mark-export.php), with the real exporter's do_push()
 * and item build. The import side runs here, in
 * tests/support/renewal-world.php: the real importer (the delta, the full
 * sync's inline save, the queued GetItem import), the mapper, the inventory
 * (item 14's stock records), the API class, TCGiant_Sync_Environment, and
 * the exporter's own save of a push (record_listing_made()). eBay's GetItem
 * answers carry ApplicationData, as the live shop's did; GetSellerEvents'
 * rows do not, as eBay's call reference says.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-96s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

// --- The push, with the real exporter --------------------------------------------

exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/push-mark-export.php' ) . ' 2>&1', $exp_out, $exp_code );
$EXP = json_decode( (string) end( $exp_out ), true );
if ( ! is_array( $EXP ) ) {
	echo implode( "\n", $exp_out ), "\n";
	$EXP = array();
}

/** Every ApplicationData element in a body. */
function marks_in( $body ) {
	preg_match_all( '#<ApplicationData>(.*?)</ApplicationData>#s', (string) $body, $m );
	return $m[1];
}
/** Each call a push made: its name and the ApplicationData it carried. */
function calls_with_marks( $case ) {
	return array_map( function ( $c ) { return array( $c['name'], marks_in( $c['body'] ) ); }, $GLOBALS['EXP'][ $case ]['calls'] ?? array() );
}

echo "\nA NEW LISTING CARRIES THE PUSH MARK; NOTHING ELSE DOES\n" . str_repeat( '=', 132 ) . "\n";

$key = substr( md5( 'tcgiant-sync push mark|shop.example' ), 0, 8 );
check( 'the push side ran to its end', $exp_code, 0 );
check( 'AddFixedPriceItem for a new listing carries it, once, naming the site and the product', calls_with_marks( 'new' ), array( array( 'AddFixedPriceItem', array( 'tcgsync1.' . $key . '.701' ) ) ) );
check( '  right after the UUID, inside the Item', 1 === preg_match( '#<Item>\n<UUID>[0-9a-f]{32}</UUID>\n<ApplicationData>tcgsync1\.' . $key . '\.701</ApplicationData>\n<Title>#', (string) ( $EXP['new']['calls'][0]['body'] ?? '' ) ), true );
check( '  and the push made the listing', $EXP['new']['result'] ?? null, 'created 110000000001' );
check( 'AddItem for a new auction carries it', calls_with_marks( 'auction' ), array( array( 'AddItem', array( 'tcgsync1.' . $key . '.702' ) ) ) );
check( 'a revise of a live listing does not', calls_with_marks( 'revise' ), array( array( 'ReviseFixedPriceItem', array() ) ) );
check( 'a listing that had ended: the revise does not, the new listing in its place does', calls_with_marks( 'ended' ), array( array( 'ReviseFixedPriceItem', array() ), array( 'GetItem', array() ), array( 'AddFixedPriceItem', array( 'tcgsync1.' . $key . '.704' ) ) ) );
check( 'Verify\'s item (and the preview\'s) has none: it makes nothing', marks_in( $EXP['verify'] ?? '' ), array() );
check( 'RelistFixedPriceItem and RelistItem send no Item fields beyond number, UUID and stock: none', array_map( function ( $c ) { return array( $c['name'], marks_in( $c['body'] ) ); }, $EXP['relists'] ?? array() ), array( array( 'RelistFixedPriceItem', array() ), array( 'RelistItem', array() ) ) );
check( 'a push in place of an ended listing records that listing as ended, with eBay\'s end, before it asks for the new one', $EXP['ended_at_add'] ?? null, array( 'Ended', '2026-10-30T08:00:00.000Z' ) );
check( 'process_single_push(): when a push began, kept when eBay refuses it', $EXP['attempt_refused'] ?? null, array( 'error', true ) );
check( '  after an earlier push that did not finish, that one\'s time stands', $EXP['attempt_kept'] ?? null, array( 'failed', 1000 ) );
check( '  a push that finishes takes it off, the latest push\'s time, and the note of what order sync took off meanwhile', $EXP['attempt_done'] ?? null, array( 'pushed', '', '', '' ) );
$repush = $EXP['repush_at_add'] ?? array();
check( 'pushed again ten days after a push that did not finish: as eBay makes the listing, the first push\'s time stands, and this one\'s is the latest', array( $repush['seen'][0] ?? null, ( $repush['seen'][1] ?? 0 ) === ( $repush['given'] ?? -1 ), ( $repush['seen'][2] ?? 0 ) >= ( $repush['began'] ?? PHP_INT_MAX ) ), array( 'pushing', true, true ) );
check( 'process_single_push() in place of an ended listing, the product saving as WooCommerce\'s does: the new listing Active, no end, the old one its previous', $EXP['ended_saved'] ?? null, array( '110000000001', 'Active', '', '110000000716', 'pushed' ) );
$rec = $EXP['record'] ?? array();
check( 'the description record a link writes: the template the listing\'s comment names, the hashes of what it holds', array( $rec['marker']['template_id'] ?? null, $rec['marker']['version'] ?? null, $rec['marker']['hash'] ?? null, $rec['marker']['region_hash'] ?? null, $rec['marker']['tpl_hash'] ?? null ), array( 12, 3, sha1( ( $rec['held'] ?? '' ) . '<!--tcg:tpl=12.3-->' ), sha1( '<p>Rebuilt.</p>' ), '' ) );
check( '  with eBay\'s comment gone, the template the product is set to', array( ( $rec['tpl'] ?? 0 ) > 0, $rec['no_marker']['template_id'] ?? null, $rec['no_marker']['region_hash'] ?? null ), array( true, $rec['tpl'] ?? 'tpl', sha1( '<p>Rebuilt.</p>' ) ) );
check( '  a plain description: no template, no region, its hash', array( $rec['plain']['template_id'] ?? null, $rec['plain']['region_hash'] ?? null, $rec['plain']['hash'] ?? null ), array( 0, '', sha1( '<p>Plain.</p>' ) ) );
check( '  no description: no record', array_key_exists( 'empty', $rec ) ? $rec['empty'] : 'missing', null );
check( 'no site address to make a key from: the push goes without, as before', array( calls_with_marks( 'no_address' ), $EXP['no_address']['result'] ?? null ), array( array( array( 'AddFixedPriceItem', array() ) ), 'created 110000000001' ) );

echo "\nTHE MARK: 32 ASCII CHARACTERS AT MOST, THE SITE'S KEY FROM ITS OWN ADDRESS\n" . str_repeat( '=', 132 ) . "\n";

$marks = $EXP['marks'] ?? array();
check( 'https://shop.example: key and mark', $marks['https://shop.example'] ?? null, array( $key, 'tcgsync1.' . $key . '.4717' ) );
check( '  http, www. and a trailing slash: the same address, the same key', $marks['http://www.shop.example/'][0] ?? null, $key );
check( '  the host in capitals: the same key', $marks['https://SHOP.example'][0] ?? null, $key );
check( '  a staging copy at another address: a key of its own', array( $marks['https://staging.shop.example'][0] ?? null, ( $marks['https://staging.shop.example'][0] ?? '' ) !== $key ), array( substr( md5( 'tcgiant-sync push mark|staging.shop.example' ), 0, 8 ), true ) );
check( '  a shop in a folder: its own key', $marks['https://shop.example/store'][0] ?? null, substr( md5( 'tcgiant-sync push mark|shop.example/store' ), 0, 8 ) );
check( '  no address: no key, no mark', $marks[''] ?? null, array( '', '' ) );
check( 'fourteen digits fit in 32 characters; fifteen give none rather than a cut one; nor 0 or below', $EXP['edge'] ?? null, array( '14 digits' => 'tcgsync1.' . $key . '.99999999999999', '15 digits' => '', '0' => '', '-5' => '' ) );
check( '  32 characters, letters, digits and dots only', array( strlen( (string) ( $EXP['edge']['14 digits'] ?? '' ) ), 1 === preg_match( '/^[a-z0-9.]+$/', (string) ( $EXP['edge']['14 digits'] ?? '' ) ) ), array( 32, true ) );
check( '  not the probe plugin\'s "tcgprobe-"', 0 === strpos( 'tcgsync1.' . $key . '.701', 'tcgprobe-' ), false );

// --- The import ------------------------------------------------------------------

require __DIR__ . '/support/renewal-world.php';

define( 'TCGIANT_SYNC_VERSION', '3.28.7' );

// The site's own address, as WordPress keeps it: TCGiant_Sync_Environment
// reads the home option from here.
$HOME = 'https://shop.example';
function wp_load_alloptions() {
	return array( 'home' => $GLOBALS['HOME'] );
}
/** And the one more question the relist step asks the database: which products follow a listing. */
class Push_Mark_Wpdb extends Harness_Wpdb {
	public function get_col( $query ) {
		if ( ! preg_match( "/pm\\.meta_key = '_ebay_item_id' AND pm\\.meta_value = '([^']*)'\\s+AND p\\.post_type = 'product'\\s+AND p\\.post_status NOT IN \\( 'trash', 'auto-draft' \\)/", $query, $m ) ) {
			return parent::get_col( $query );
		}
		$out = array();
		foreach ( $GLOBALS['POSTS'] as $id => $post ) {
			if ( 'product' === $post['type'] && ! in_array( $post['status'], array( 'trash', 'auto-draft' ), true ) && (string) ( $GLOBALS['META'][ $id ]['_ebay_item_id'] ?? "\0" ) === $m[1] ) {
				$out[] = (string) $id;
			}
		}
		return $out;
	}
	/** The earliest push still under way or that did not finish (TCGiant_Sync_Importer::unfinished_push_since()), from post meta; every query asked, kept. */
	public $asked = array();
	public function get_var( $query ) {
		$this->asked[] = $query;
		if ( false !== strpos( $query, "a.meta_key = '_ebay_push_attempt'" ) ) {
			// As MySQL would answer it: the first unfinished push's time (a)
			// and, where the query joins it, the latest's (l); the least of
			// the column it asks the MIN of, over the products whose value it
			// compares is at or after the time it names.
			$joins = 1 === preg_match( "/LEFT JOIN \S+ l ON l\.post_id = s\.post_id AND l\.meta_key = '_ebay_push_attempt_last'/", $query );
			$of    = preg_match( '/SELECT MIN\( CAST\( ([al])\.meta_value AS UNSIGNED \) \)/', $query, $m ) ? $m[1] : null;
			$cut   = preg_match( '/CAST\( (COALESCE\( l\.meta_value, a\.meta_value \)|a\.meta_value|l\.meta_value) AS UNSIGNED \) >= (\d+)/', $query, $c ) ? $c : null;
			$min   = null;
			foreach ( $GLOBALS['META'] as $id => $meta ) {
				if ( null === $of || null === $cut || ! in_array( (string) ( $meta['_ebay_export_status'] ?? '' ), array( 'pushing', 'failed' ), true ) || ! isset( $meta['_ebay_push_attempt'] ) ) {
					continue;
				}
				$col  = array( 'a' => (int) $meta['_ebay_push_attempt'], 'l' => $joins && isset( $meta['_ebay_push_attempt_last'] ) ? (int) $meta['_ebay_push_attempt_last'] : null );
				$seen = 'a.meta_value' === $cut[1] ? $col['a'] : ( 'l.meta_value' === $cut[1] ? $col['l'] : ( $col['l'] ?? $col['a'] ) );
				if ( null === $seen || $seen < (int) $cut[2] || null === $col[ $of ] ) {
					continue;
				}
				$min = null === $min ? $col[ $of ] : min( $min, $col[ $of ] );
			}
			return null === $min ? null : (string) $min;
		}
		return parent::get_var( $query );
	}
}
$wpdb = new Push_Mark_Wpdb();

/** A post, for the listing templates' own lookups: a template here is a post of type tcgiant_tpl. */
function get_post( $id ) {
	$post = $GLOBALS['POSTS'][ (int) $id ] ?? null;
	return null === $post ? null : (object) array( 'ID' => (int) $id, 'post_type' => $post['type'], 'post_status' => $post['status'], 'post_title' => '', 'post_modified' => '' );
}

/** eBay with the orders order sync reads ($ORDERS), as the Fulfillment API gives them. */
class Push_Mark_eBay extends Scripted_eBay {
	public function get_orders( $limit = 50, $offset = 0 ) {
		return array( 'orders' => $GLOBALS['ORDERS'] );
	}
}
use_ebay( new Push_Mark_eBay() );
$ORDERS = array();

require_once $ROOT . '/includes/class-tcgiant-sync-environment.php';
require_once $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require_once $ROOT . '/includes/class-tcgiant-sync-exporter.php';

function gets() { return count( $GLOBALS['EBAY']['get_items'] ); }
function meta( $pid, $key ) { return $GLOBALS['META'][ (int) $pid ][ $key ] ?? null; }
function products() { return count( array_filter( $GLOBALS['POSTS'], function ( $p ) { return 'product' === $p['type']; } ) ); }
function logged( $text, $level = null ) {
	foreach ( $GLOBALS['LOG'] as $line ) {
		if ( false !== strpos( $line[1], $text ) && ( null === $level || $level === $line[0] ) ) {
			return true;
		}
	}
	return false;
}
function count_logged( $text ) {
	$n = 0;
	foreach ( $GLOBALS['LOG'] as $line ) {
		if ( false !== strpos( $line[1], $text ) ) {
			$n++;
		}
	}
	return $n;
}
/** The product now holding listing $item_id, other than $not. */
function holder( $item_id, $not = 0 ) {
	foreach ( $GLOBALS['META'] as $id => $meta ) {
		if ( (int) $id !== (int) $not && (string) ( $meta['_ebay_item_id'] ?? '' ) === (string) $item_id && 'product' === ( $GLOBALS['POSTS'][ $id ]['type'] ?? '' ) ) {
			return (int) $id;
		}
	}
	return 0;
}

// Connected, so a stock push can be queued; auctions imported; eBay's title,
// description and price written over the shop's on an import, so that a
// product left as it was is one the link kept.
/** When the listings made_listing() makes started: after the push pushed_product() says began, an hour ago. */
define( 'START', time() - 1800 );
const SETTINGS = array( 'access_token' => 'harness', 'refresh_token' => 'harness', 'import_auctions' => '1', 'overwrite_title' => '1', 'overwrite_desc' => '1', 'overwrite_price' => '1' );

/**
 * Product $id as a push of it that did not finish leaves it (3.27.4): no item
 * number, the push said failed, with the shop's own title, description,
 * price, SKU and stock.
 */
function pushed_product( $id, array $meta = array(), $type = 'simple', $status = 'publish', $post_type = 'product' ) {
	$GLOBALS['POSTS'][ $id ] = array( 'type' => $post_type, 'status' => $status, 'product_type' => $type );
	$GLOBALS['META'][ $id ]  = array_merge( array(
		'_name'                      => 'Starter motor ' . $id,
		'_description'               => 'Rebuilt and bench tested.',
		'_regular_price'             => '31.50',
		'_sku'                       => '',
		'_manage_stock'              => 'yes',
		'_stock'                     => 3,
		'_thumbnail_id'              => 88,
		'_ebay_export_status'        => 'failed',
		'_ebay_export_error'         => 'The push of "Starter motor ' . $id . '" (WC #' . $id . ') did not finish - your server stopped it. If the listing is on eBay, use Link on the product with its item number; pushing it again would list it twice.',
		'_ebay_export_pushing_since' => time() - 3600,
		// When that push began, as it writes it (3.28.7).
		'_ebay_push_attempt'         => time() - 3600,
	), $meta );
}

/** The listing that push made, as GetItem returns it: no SKU, the mark as given; started half an hour ago, after the push began (pushed_product()). */
function made_listing( $item_id, $mark, array $over = array() ) {
	$listing = item( $item_id );
	unset( $listing['SKU'] );
	$listing = array_replace_recursive( $listing, array( 'Quantity' => '3', 'ListingDetails' => array( 'StartTime' => iso( START ), 'EndTime' => iso( START + 30 * DAY_IN_SECONDS ) ) ), $over );
	if ( null !== $mark ) {
		$listing['ApplicationData'] = $mark;
	}
	$GLOBALS['EBAY']['items'][ (string) $item_id ] = $listing;
	return $listing;
}

/** A queued GetItem import of the listing: Fetch Inventory's, and Sync Specific Items'. */
function import_one( $item_id ) {
	new_request();
	TCGiant_Sync_Importer::instance()->process_item_import( (string) $item_id );
}

/** The full sync's GetSellerList page, saved inline. */
function full_sync_page( array $items ) {
	new_request();
	call_private( TCGiant_Sync_Importer::instance(), 'process_page_items', $items, 1, 1 );
}

/** What a linked product looks like: the link as a push saves it, the shop's own fields, no copy. */
function linked_as_pushed( $pid, $item_id, $products_before ) {
	return array(
		'item'        => meta( $pid, '_ebay_item_id' ),
		'status'      => meta( $pid, '_ebay_export_status' ),
		'error'       => meta( $pid, '_ebay_export_error' ),
		'since'       => meta( $pid, '_ebay_export_pushing_since' ),
		'pushed'      => '' !== (string) meta( $pid, '_ebay_export_last_pushed' ),
		'version'     => meta( $pid, '_ebay_export_pushed_version' ),
		'listing'     => meta( $pid, '_ebay_listing_status' ),
		'site'        => meta( $pid, '_ebay_site' ),
		'type'        => array( meta( $pid, '_ebay_listing_type' ), meta( $pid, '_ebay_listing_type_item' ) ),
		'attempt'     => meta( $pid, '_ebay_push_attempt' ),
		'title/desc/price/sku' => array( meta( $pid, '_name' ), meta( $pid, '_description' ), meta( $pid, '_regular_price' ), meta( $pid, '_sku' ) ),
		'no copy'     => products() === $products_before,
		'no photos'   => empty( $GLOBALS['PHOTO_SETS'] ),
	);
}
function as_pushed( $item_id, $pid ) {
	return array(
		'item'        => (string) $item_id,
		'status'      => 'pushed',
		'error'       => '',
		'since'       => null,
		'pushed'      => true,
		'version'     => '3.28.7',
		'listing'     => 'Active',
		'site'        => 'US',
		'type'        => array( 'FixedPriceItem', (string) $item_id ),
		'attempt'     => null,
		'title/desc/price/sku' => array( 'Starter motor ' . $pid, 'Rebuilt and bench tested.', '31.50', '' ),
		'no copy'     => true,
		'no photos'   => true,
	);
}
function linked_line( $item_id, $pid ) {
	return sprintf( 'Listing %1$s was created by a push of WC #%2$d that didn\'t finish; linked it to WC #%2$d instead of importing a copy.', $item_id, $pid );
}
/** Imported as today: a new product holds the listing, and the product named is as it was. */
function imported_as_today( $pid, $item_id, $products_before ) {
	$copy = holder( $item_id, $pid );
	return array( products() === $products_before + 1 && $copy > 0, meta( $pid, '_ebay_item_id' ), meta( $pid, '_ebay_export_status' ), logged( 'that didn\'t finish; linked it' ) );
}

echo "\nTHE IMPORT: THE SAME KEY, FROM THE SAME ADDRESS\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop( SETTINGS );
check( 'this world\'s key is the push side\'s', TCGiant_Sync_API::push_site_key(), $key );
check( 'the mark the real push sent names product 701 here', TCGiant_Sync_API::marked_product( marks_in( $EXP['new']['calls'][0]['body'] ?? '' )[0] ?? '' ), 701 );

echo "\nEACH WAY A LISTING REACHES A NEW PRODUCT: LINKED INSTEAD\n" . str_repeat( '=', 132 ) . "\n";

// The delta: GetSellerEvents' row has no ApplicationData, and its GetItem has.
fresh_shop( SETTINGS );
$mark = marks_in( $EXP['new']['calls'][0]['body'] ?? '' )[0] ?? '';
pushed_product( 701 );
made_listing( '960068033342', $mark );
$EBAY['window'] = array( '960068033342' );
$before = products();
delta();
check( 'the delta (mark from the real push): linked as the push would have, nothing imported', linked_as_pushed( 701, '960068033342', $before ), as_pushed( '960068033342', 701 ) );
check( '  one GetItem: the row from GetSellerEvents has no ApplicationData', gets(), 1 );
check( '  the one line, once', array( count_logged( linked_line( '960068033342', 701 ) ), logged( linked_line( '960068033342', 701 ), 'success' ) ), array( 1, true ) );
check( '  no "Delta imported", no error', array( logged( 'Delta imported' ), logged( 'Failed to save product' ) ), array( false, false ) );
check( '  the debug count: 0 of 1 changed rows carry it', logged( 'Delta: 0 of 1 changed listings read carry this site\'s push mark (ApplicationData).', 'debug' ), true );
check( '  its dates from eBay', array( meta( 701, '_ebay_start_time' ), meta( 701, '_ebay_end_time' ) ), array( iso( START ), iso( START + 30 * DAY_IN_SECONDS ) ) );
check( '  the Listings row, as a push writes it, with eBay\'s dates', array_intersect_key( $ROWS[701] ?? array(), array_flip( array( 'ebay_item_id', 'listing_status', 'ebay_title', 'ebay_price', 'ebay_quantity', 'ebay_start_time', 'ebay_end_time', 'listing_type' ) ) ), array( 'listing_status' => 'Active', 'ebay_item_id' => '960068033342', 'ebay_title' => 'Starter motor 701', 'ebay_start_time' => iso( START ), 'ebay_end_time' => iso( START + 30 * DAY_IN_SECONDS ), 'listing_type' => 'FixedPriceItem', 'ebay_price' => 31.5, 'ebay_quantity' => 3 ) );
check( '  "last pushed" on the row', '' !== (string) ( $ROWS[701]['last_pushed'] ?? '' ), true );
check( '  the push is complete: the screens no longer say it did not finish', TCGiant_Sync_Exporter::push_progress( 701 ), '' );
check( '  stock eBay\'s, with item 14\'s record of its sales', array( meta( 701, '_stock' ), meta( 701, '_ebay_sold_seen' ) ), array( 3, array( 'item' => '960068033342', 'sku' => '', 'sold' => 0 ) ) );
check( '  the sync log says so', ( meta( 701, '_tcgiant_sync_log' )[0]['decisions'] ?? null ), array( 'Linked to eBay listing 960068033342, which a push of this product made and did not live to save. Title, description, price and photos kept (WooCommerce won)', 'Stock kept at 3: WooCommerce had taken off every eBay sale of this listing' ) );
check( '  nothing sent to eBay', $EBAY['wire'], array() );
check( '  no renewal record: its next renewal takes the full path (3.28.6)', meta( 701, '_ebay_event_sig' ), null );
check( '  no relist mark for the duplicate-pairs check', meta( 701, '_ebay_relist_parent_id' ), null );
check( '  the shop\'s own listing, made by its push: its Updates follow the shop\'s defaults (3.28.8)', array( meta( 701, '_ebay_origin' ), TCGiant_Sync_Ebay_Live::follows_defaults( 701 ) ), array( 'push', true ) );
check( '  eBay\'s own values not recorded by the link, which no Update of it reads: its next full read records them', meta( 701, '_ebay_live' ), null );

// Its next change: found by its item number, as any linked product.
$EBAY['items']['960068033342']['ListingDetails']['EndTime'] = '2027-01-01T09:15:00.000Z';
$EBAY['get_items'] = array();
$LOG = array();
delta();
check( '  its next change (a renewal): the full path, found by item number, no copy', array( gets(), holder( '960068033342' ), products() === $before, logged( 'Delta imported: "Brake caliper 960068033342" → WC #701' ) ), array( 1, 701, true, true ) );

// The full sync's GetSellerList page, saved inline: eBay's reference says
// ReturnAll gives ApplicationData back.
fresh_shop( SETTINGS );
pushed_product( 4717 );
$listing = made_listing( '960068033343', TCGiant_Sync_API::push_mark( 4717 ) );
$before  = products();
full_sync_page( array( $listing ) );
check( 'the full sync\'s inline save of a GetSellerList page: linked', linked_as_pushed( 4717, '960068033343', $before ), as_pushed( '960068033343', 4717 ) );
check( '  no GetItem, the line, no "Imported"', array( gets(), logged( linked_line( '960068033343', 4717 ) ), logged( 'Imported:' ) ), array( 0, true, false ) );
check( '  the debug count: 1 of 1 on the page carries it', logged( 'Full sync page 1: 1 of 1 listings read carry this site\'s push mark (ApplicationData).', 'debug' ), true );

// The queued GetItem import: Fetch Inventory's, and Sync Specific Items'.
fresh_shop( SETTINGS );
pushed_product( 4718 );
made_listing( '960068033344', TCGiant_Sync_API::push_mark( 4718 ) );
$before = products();
import_one( '960068033344' );
check( 'a queued GetItem import (Fetch Inventory, Sync Specific Items): linked', linked_as_pushed( 4718, '960068033344', $before ), as_pushed( '960068033344', 4718 ) );
check( '  the line, no "Imported", no error counted', array( logged( linked_line( '960068033344', 4718 ) ), logged( 'Imported:' ), (int) ( get_option( 'tcgiant_sync_state', array() )['total_errors'] ?? 0 ) ), array( true, false, 0 ) );

// A push still waiting for eBay's answer when the sync reads the listing:
// linked; the push's own save, when it comes, writes the same.
fresh_shop( SETTINGS );
pushed_product( 4719, array( '_ebay_export_status' => 'pushing', '_ebay_export_error' => '', '_ebay_export_pushing_since' => time() - 20 ) );
made_listing( '960068033345', TCGiant_Sync_API::push_mark( 4719 ) );
$before = products();
import_one( '960068033345' );
check( 'a push still under way when the sync reads its listing: linked, no copy', linked_as_pushed( 4719, '960068033345', $before ), as_pushed( '960068033345', 4719 ) );

echo "\nWHERE THE SKU MATCH COULD NOT\n" . str_repeat( '=', 132 ) . "\n";

// Bin Location mode: the eBay SKU goes to the bin, so the SKU match is off.
fresh_shop( array( 'sku_maps_to' => 'bin_location' ) + SETTINGS );
pushed_product( 4720, array( '_sku' => 'SM-4720' ) );
made_listing( '960068033346', TCGiant_Sync_API::push_mark( 4720 ), array( 'SKU' => 'SM-4720' ) );
$before = products();
import_one( '960068033346' );
check( 'Bin Location mode: linked by the mark', array( meta( 4720, '_ebay_item_id' ), products() === $before, meta( 4720, '_sku' ), meta( 4720, '_name' ) ), array( '960068033346', true, 'SM-4720', 'Starter motor 4720' ) );

// The mark is read before the SKU: the SKU match would have linked it too,
// and written eBay's title over the shop's.
fresh_shop( SETTINGS );
pushed_product( 4721, array( '_sku' => 'SM-4721' ) );
made_listing( '960068033347', TCGiant_Sync_API::push_mark( 4721 ), array( 'SKU' => 'SM-4721' ) );
import_one( '960068033347' );
check( 'item number, then the mark, then the SKU: linked by the mark, the shop\'s title kept', array( meta( 4721, '_ebay_item_id' ), meta( 4721, '_name' ), logged( linked_line( '960068033347', 4721 ) ) ), array( '960068033347', 'Starter motor 4721', true ) );
fresh_shop( SETTINGS );
pushed_product( 4722, array( '_sku' => 'SM-4722' ) );
made_listing( '960068033348', null, array( 'SKU' => 'SM-4722' ) );
import_one( '960068033348' );
check( '  without a mark, the SKU links it as before, with eBay\'s title', array( meta( 4722, '_ebay_item_id' ), meta( 4722, '_name' ), logged( 'that didn\'t finish; linked it' ) ), array( '960068033348', 'Brake caliper 960068033348', false ) );

echo "\nPUSHED AGAIN, AND THAT PUSH FINISHED: TWO LISTINGS FOR ONE PRODUCT\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop( SETTINGS );
pushed_product( 4723, array( '_ebay_item_id' => '960068033999', '_ebay_export_status' => 'pushed', '_ebay_export_error' => '' ) );
made_listing( '960068033349', TCGiant_Sync_API::push_mark( 4723 ) );
$before = products();
import_one( '960068033349' );
check( 'linked to another listing: imported as today, the product keeps its own', imported_as_today( 4723, '960068033349', $before ), array( true, '960068033999', 'pushed', false ) );
check( '  a warning naming both listings', logged( 'eBay listing 960068033349 was made by a push of WC #4723, but WC #4723 is linked to listing 960068033999, so it is not linked to 960068033349: 960068033349 is imported as any other listing. If both are live on eBay, WC #4723 is listed twice', 'warning' ), true );

// Once a day per listing: an auction left out of the import is met on every bid.
fresh_shop( array( 'import_auctions' => '0' ) + SETTINGS );
pushed_product( 4724, array( '_ebay_item_id' => '960068033998', '_ebay_export_status' => 'pushed', '_ebay_export_error' => '' ) );
made_listing( '960068033350', TCGiant_Sync_API::push_mark( 4724 ), array( 'ListingType' => 'Chinese', 'ListingDuration' => 'Days_7', 'Quantity' => '1' ) );
import_one( '960068033350' );
import_one( '960068033350' );
check( '  said once a day for a listing met again', count_logged( 'eBay listing 960068033350 was made by a push of WC #4724' ), 1 );

echo "\nONLY A PRODUCT WHOSE PUSH DID NOT FINISH\n" . str_repeat( '=', 132 ) . "\n";

/** Product $id with no push state at all: never pushed, or unlinked after a push that finished. */
function never_pushed( $id, array $meta = array() ) {
	pushed_product( $id, $meta );
	unset( $GLOBALS['META'][ $id ]['_ebay_export_status'], $GLOBALS['META'][ $id ]['_ebay_export_error'], $GLOBALS['META'][ $id ]['_ebay_export_pushing_since'] );
}

// A product number used again: the database restored from a backup, or a
// rebuild at the same address. The listing's mark names 4802, which is now
// another item that never pushed; the listing's own SKU is 4803's.
fresh_shop( SETTINGS );
never_pushed( 4802 );
never_pushed( 4803, array( '_sku' => 'CARD-4803' ) );
made_listing( '960068033802', TCGiant_Sync_API::push_mark( 4802 ), array( 'SKU' => 'CARD-4803' ) );
$before = products();
import_one( '960068033802' );
check( 'a product number used again, never pushed: not linked by the mark; the SKU match links the right product', array( holder( '960068033802' ), meta( 4802, '_ebay_item_id' ), meta( 4802, '_ebay_export_status' ), meta( 4802, '_stock' ), products() === $before, logged( 'that didn\'t finish; linked it' ) ), array( 4803, null, null, 3, true, false ) );
check( '  said at debug level', logged( 'eBay listing 960068033802 was made by a push of WC #4802, but WC #4802 does not say a push of it did not finish (it says ""): the listing is imported as any other.', 'debug' ), true );

// A push that finished, then the shop unlinked it on purpose (Unlink's own
// work, TCGiant_Sync_Listing_Link::unlink_product_from_ebay(); the Unlink
// button keeps nothing of a push that finished).
fresh_shop( SETTINGS );
pushed_product( 4804, array( '_ebay_item_id' => '960068033804', '_ebay_export_status' => 'pushed', '_ebay_export_error' => '', '_ebay_export_last_pushed' => '2026-10-30 10:00:00' ) );
made_listing( '960068033804', TCGiant_Sync_API::push_mark( 4804 ) );
TCGiant_Sync_Listing_Link::unlink_product_from_ebay( 4804, 'unlinked by admin' );
$before = products();
import_one( '960068033804' );
check( 'a finished push the shop has unlinked: not linked back; imported as today', imported_as_today( 4804, '960068033804', $before ), array( true, null, null, false ) );

// The Unlink button keeps a push that did not finish as failed (the shop is
// told to Unlink, then Link the new listing): the sync links it.
fresh_shop( SETTINGS );
pushed_product( 4805, array( '_ebay_item_id' => '960068033905', '_ebay_listing_status' => 'Ended' ) );
made_listing( '960068033805', TCGiant_Sync_API::push_mark( 4805 ) );
$attempt = meta( 4805, '_ebay_push_attempt' );
TCGiant_Sync_Listing_Link::unlink_product_from_ebay( 4805, 'unlinked by admin' );
check( 'Unlink\'s own work takes when the push began with the rest of the link', meta( 4805, '_ebay_push_attempt' ), null );
update_post_meta( 4805, '_ebay_export_status', 'failed' );
update_post_meta( 4805, '_ebay_push_attempt', $attempt );
$before = products();
import_one( '960068033805' );
check( 'a push that did not finish, Unlinked as its message says: linked', array( meta( 4805, '_ebay_item_id' ), meta( 4805, '_ebay_export_status' ), products() === $before ), array( '960068033805', 'pushed', true ) );

/** Product 4806 unlinked, saying $state, and a listing its mark names. */
function state_case( $n, $state ) {
	fresh_shop( SETTINGS );
	pushed_product( 4806, null === $state ? array() : array( '_ebay_export_status' => $state ) );
	if ( null === $state ) {
		unset( $GLOBALS['META'][4806]['_ebay_export_status'] );
	}
	$item_id = '9600680338' . $n;
	made_listing( $item_id, TCGiant_Sync_API::push_mark( 4806 ) );
	$before = products();
	import_one( $item_id );
	return array( meta( 4806, '_ebay_item_id' ) === $item_id, products() === $before );
}
check( 'it says pushing (the push still under way, or killed before it was seen): linked', state_case( '10', 'pushing' ), array( true, true ) );
check( 'it says failed (the death was seen): linked', state_case( '11', 'failed' ), array( true, true ) );
check( 'it says pushed: imported as today', state_case( '12', 'pushed' ), array( false, false ) );
check( 'it says queued: imported as today', state_case( '13', 'queued' ), array( false, false ) );
check( 'it says error: imported as today', state_case( '14', 'error' ), array( false, false ) );
check( 'it says nothing: imported as today', state_case( '15', null ), array( false, false ) );

echo "\nEVERY REFUSAL IMPORTS AS TODAY\n" . str_repeat( '=', 132 ) . "\n";

/** Product 4730 as a dead push left it, and listing 9600680334NN carrying $mark, imported by GetItem. */
function refused_case( $n, $mark, array $product = array(), $type = 'simple', $status = 'publish', $post_type = 'product', array $over = array() ) {
	fresh_shop( SETTINGS );
	pushed_product( 4730, $product, $type, $status, $post_type );
	$item_id = '9600680334' . $n;
	made_listing( $item_id, $mark, $over );
	$before = products();
	import_one( $item_id );
	return imported_as_today( 4730, $item_id, $before );
}
$today = array( true, null, 'failed', false );

$HOME  = 'https://other-shop.example';
$other = TCGiant_Sync_API::push_mark( 4730 );
$HOME  = 'https://shop.example';
check( 'another site\'s mark for the same product number', refused_case( '10', $other ), $today );
check( 'the probe plugin\'s value', refused_case( '11', 'tcgprobe-11755' ), $today );
check( 'a seller\'s own value', refused_case( '12', 'PART-4730' ), $today );
check( 'the mark in capitals', refused_case( '13', strtoupper( TCGiant_Sync_API::push_mark( 4730 ) ) ), $today );
check( 'the mark with a line after it', refused_case( '14', TCGiant_Sync_API::push_mark( 4730 ) . "\n" ), $today );
check( 'the mark with something after it', refused_case( '15', TCGiant_Sync_API::push_mark( 4730 ) . '0' ), $today );
check( 'the mark\'s prefix in capitals, the key as ours', refused_case( '22', 'TCGSYNC1.' . $key . '.4730' ), $today );
check( 'the product number with a leading zero', refused_case( '23', 'tcgsync1.' . $key . '.04730' ), $today );
check( 'the probe plugin\'s prefix before our key and number', refused_case( '24', 'tcgprobe-' . $key . '.4730' ), $today );
$HOME = '';
check( 'a site with no address to make a key from: no listing is its own', refused_case( '25', 'tcgsync1.' . $key . '.4730' ), $today );
$HOME = 'https://shop.example';
check( 'naming product 0', refused_case( '16', 'tcgsync1.' . $key . '.0' ), $today );
check( 'the product in the Trash', refused_case( '17', TCGiant_Sync_API::push_mark( 4730 ), array(), 'simple', 'trash' ), $today );
check( '  said at debug level', logged( 'eBay listing 960068033417 was made by a push of WC #4730, which is in the Trash: the listing is imported as any other.', 'debug' ), true );
fresh_shop( SETTINGS );
made_listing( '960068033418', TCGiant_Sync_API::push_mark( 4799 ) );
$before = products();
import_one( '960068033418' );
check( 'the product is not there at all', array( products() === $before + 1, holder( '960068033418' ) > 0, logged( 'WC #4799, which is not a product here', 'debug' ) ), array( true, true, true ) );
check( 'the number is a post that is not a product', refused_case( '19', TCGiant_Sync_API::push_mark( 4730 ), array(), 'simple', 'publish', 'post' ), array( true, null, 'failed', false ) );
check( 'a variable product, a listing without variations', refused_case( '20', TCGiant_Sync_API::push_mark( 4730 ), array(), 'variable' ), $today );
check( '  said at debug level', logged( 'eBay listing 960068033420 was made by a push of WC #4730, but the listing has no variations and the product is variable', 'debug' ), true );
$with_variations = array( 'Variations' => array( 'Variation' => array(
	array( 'SKU' => 'SM-R', 'StartPrice' => '31.50', 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => '0' ), 'VariationSpecifics' => array( 'NameValueList' => array( 'Name' => 'Side', 'Value' => 'Right' ) ) ),
	array( 'SKU' => 'SM-L', 'StartPrice' => '31.50', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0' ), 'VariationSpecifics' => array( 'NameValueList' => array( 'Name' => 'Side', 'Value' => 'Left' ) ) ),
) ) );
check( 'a simple product, a listing with variations', refused_case( '21', TCGiant_Sync_API::push_mark( 4730 ), array(), 'simple', 'publish', 'product', $with_variations ), $today );
// Neither simple nor variable: WooCommerce will not manage its stock, and
// throws if asked, which linking it did on every sync.
check( 'an external product saying its push failed', refused_case( '26', TCGiant_Sync_API::push_mark( 4730 ), array(), 'external' ), $today );
check( '  said at debug level, nothing counted as an error', array( logged( 'eBay listing 960068033426 was made by a push of WC #4730, but WC #4730 is a product of type "external", not simple or variable: the listing is imported as any other.', 'debug' ), (int) ( get_option( 'tcgiant_sync_state', array() )['total_errors'] ?? 0 ), logged( 'Exception importing item' ) ), array( true, 0, false ) );
check( 'a grouped product saying its push failed', refused_case( '27', TCGiant_Sync_API::push_mark( 4730 ), array(), 'grouped' ), $today );

echo "\nA STAGING COPY AT ANOTHER ADDRESS DOES NOT CLAIM THE LIVE SHOP'S LISTINGS\n" . str_repeat( '=', 132 ) . "\n";

// The live shop pushed; the database was copied to staging, where the
// import reads the same listing.
fresh_shop( SETTINGS );
$live_mark = TCGiant_Sync_API::push_mark( 4740 );
$HOME      = 'https://staging.shop.example';
pushed_product( 4740 );
made_listing( '960068033440', $live_mark );
$before = products();
import_one( '960068033440' );
check( 'the copy imports the live shop\'s listing as today', imported_as_today( 4740, '960068033440', $before ), $today );
// The copy's own pushes, at its own address - were any let through - name
// its products, and a copy's import of them would link them there.
fresh_shop( SETTINGS );
pushed_product( 4741 );
made_listing( '960068033441', TCGiant_Sync_API::push_mark( 4741 ) );
import_one( '960068033441' );
check( '  its own mark, at its own address, is its own', meta( 4741, '_ebay_item_id' ), '960068033441' );
// The live shop reads its own marks back however its address is written.
fresh_shop( SETTINGS );
pushed_product( 4740 );
made_listing( '960068033442', $live_mark );
$HOME = 'https://shop.example/';
import_one( '960068033442' );
$HOME = 'https://shop.example';
check( '  the live shop, its address with a slash: its own', meta( 4740, '_ebay_item_id' ), '960068033442' );
fresh_shop( SETTINGS );
pushed_product( 4740 );
made_listing( '960068033443', $live_mark );
$HOME = 'http://www.shop.example/';
import_one( '960068033443' );
$HOME = 'https://shop.example';
check( '  the live shop, read as http://www.: still its own', meta( 4740, '_ebay_item_id' ), '960068033443' );

echo "\nRELISTS: RELISTPARENTID DECIDES, THE MARK IS NOT READ\n" . str_repeat( '=', 132 ) . "\n";

// A relist carries the ApplicationData of the listing it came from: here a
// product the push of which made that listing, unlinked since.
fresh_shop( SETTINGS );
pushed_product( 4750 );
$EBAY['items']['960068033450'] = item( '960068033450', array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0' ) ) );
made_listing( '960068033451', TCGiant_Sync_API::push_mark( 4750 ), array( 'RelistParentID' => '960068033450' ) );
$before = products();
import_one( '960068033451' );
check( 'a relist carrying a mark: not linked by it, imported as today', imported_as_today( 4750, '960068033451', $before ), $today );

// The product follows the listing that ended, and eBay relisted it: the relist
// step moves the product on and imports the relist into it (3.28.0 item 8),
// whatever the mark says.
fresh_shop( SETTINGS );
pushed_product( 4751, array( '_ebay_item_id' => '960068033452', '_ebay_export_status' => 'pushed', '_ebay_export_error' => '', '_ebay_listing_status' => 'Ended' ) );
$EBAY['items']['960068033452'] = item( '960068033452', array( 'Quantity' => '3', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0' ), 'ListingDetails' => array( 'RelistedItemID' => '960068033453' ) ) );
made_listing( '960068033453', TCGiant_Sync_API::push_mark( 4751 ), array( 'RelistParentID' => '960068033452' ) );
$before = products();
import_one( '960068033453' );
check( 'a relist of the listing the product follows, carrying its mark: moved on by the relist step, as before', array( meta( 4751, '_ebay_item_id' ), products() === $before, logged( 'that didn\'t finish; linked it' ), in_array( 'Relisted on eBay: now follows listing 960068033453 (was 960068033452)', (array) ( meta( 4751, '_tcgiant_sync_log' )[0]['decisions'] ?? array() ), true ) ), array( '960068033453', true, false, true ) );

echo "\nA PRODUCT IN THE TRASH HOLDS THE LISTING\n" . str_repeat( '=', 132 ) . "\n";

// A product of any status holding the listing decides, as for a relist: in
// the Trash it was deleted on purpose, and the listing is skipped - or, with
// tcgiant_sync_reimport_deleted_products on, imported again as a new product.
fresh_shop( SETTINGS );
pushed_product( 4755 );
$POSTS[4756] = array( 'type' => 'product', 'status' => 'trash', 'product_type' => 'simple' );
$META[4756]  = array( '_ebay_item_id' => '960068033455', '_name' => 'Deleted', '_manage_stock' => 'yes', '_stock' => 1 );
made_listing( '960068033455', TCGiant_Sync_API::push_mark( 4755 ) );
$before = products();
import_one( '960068033455' );
check( 'held by a product in the Trash: skipped, as before, and the mark not read', array( products() === $before, meta( 4755, '_ebay_item_id' ), logged( 'is in the Trash, so it was deleted deliberately' ) ), array( true, null, true ) );
$FILTERS['tcgiant_sync_reimport_deleted_products'] = function () { return true; };
$LOG = array();
import_one( '960068033455' );
unset( $FILTERS['tcgiant_sync_reimport_deleted_products'] );
check( '  with deleted products imported again: a new product, as before, and the mark not read', array( products() === $before + 1, meta( 4755, '_ebay_item_id' ), logged( 'that didn\'t finish; linked it' ) ), array( true, null, false ) );

echo "\nSTOCK AT THE LINK: WOOCOMMERCE'S NOW, LESS WHAT EBAY SOLD THAT IT HAS NOT TAKEN OFF\n" . str_repeat( '=', 132 ) . "\n";

// Every state below is one a dead push can really be in: no stock change of
// an unlinked product is ever marked as on its way to eBay, so the product's
// figure is WooCommerce's own, and eBay's sales of the listing are all it
// has not heard of - unless order sync took them off by its SKU.

/** The pushes queued to eBay for product $pid's listing $item. */
function pushes_queued( $pid, $item ) {
	return count( array_filter( $GLOBALS['QUEUE'], function ( $a ) use ( $pid, $item ) {
		return TCGiant_Sync_Inventory::PUSH_HOOK === $a['hook'] && (int) $pid === (int) ( $a['args'][0]['product_id'] ?? 0 ) && (string) $item === (string) ( $a['args'][0]['item'] ?? '' );
	} ) );
}

/** Product 4760 with these meta, pushed at the listing's Quantity, listing 96006803346N with these sales; linked by GetItem import. */
function stock_case( $n, array $product, $quantity, $sold, array $over = array() ) {
	fresh_shop( SETTINGS );
	pushed_product( 4760, $product );
	$item_id = '96006803346' . $n;
	made_listing( $item_id, TCGiant_Sync_API::push_mark( 4760 ), array_replace_recursive( array( 'Quantity' => (string) $quantity, 'SellingStatus' => array( 'QuantitySold' => (string) $sold ) ), $over ) );
	import_one( $item_id );
	return array( meta( 4760, '_ebay_item_id' ) === $item_id, meta( 4760, '_stock' ), meta( 4760, '_ebay_sold_seen' )['sold'] ?? null, pushes_queued( 4760, $item_id ), meta( 4760, '_tcgiant_sync_log' )[0]['decisions'][1] ?? null );
}

check( 'nothing sold anywhere: 3 stays 3, eBay already shows it, nothing sent', stock_case( 1, array( '_stock' => 3 ), 3, 0 ), array( true, 3, 0, 0, 'Stock kept at 3: WooCommerce had taken off every eBay sale of this listing' ) );
check( 'eBay sold 1 before the sync read it: 3 less 1 is 2, which eBay shows', stock_case( 2, array( '_stock' => 3 ), 3, 1 ), array( true, 2, 1, 0, 'Stock set to 2: WooCommerce\'s 3 less the 1 eBay sold of this listing that it had not taken off' ) );
check( 'the website sold 1 after the push: 2 stays 2, and eBay, showing 3, is sent it', stock_case( 3, array( '_stock' => 2 ), 3, 0 ), array( true, 2, 0, 1, 'Stock kept at 2: WooCommerce had taken off every eBay sale of this listing. eBay showed 3 and is sent it' ) );
check( 'both sold 1: 2 less 1 is 1, and eBay, showing 2, is sent it', stock_case( 4, array( '_stock' => 2 ), 3, 1 ), array( true, 1, 1, 1, 'Stock set to 1: WooCommerce\'s 2 less the 1 eBay sold of this listing that it had not taken off. eBay showed 2 and is sent it' ) );
check( 'eBay sold more than the website has left: 0, never below, and eBay is sent 0', stock_case( 5, array( '_stock' => 1 ), 3, 2 ), array( true, 0, 2, 1, 'Stock set to 0: WooCommerce\'s 1 less the 2 eBay sold of this listing that it had not taken off. eBay showed 1 and is sent it' ) );
check( 'an auction the website has since sold: 0, and eBay is told it has run out', stock_case( 6, array( '_stock' => 0 ), 1, 0, array( 'ListingType' => 'Chinese', 'ListingDuration' => 'Days_7' ) ), array( true, 0, 0, 1, 'Stock kept at 0: WooCommerce had taken off every eBay sale of this listing. eBay showed 1 and is sent it' ) );
check( '  recorded as the shop\'s own auction, as the push records one', array( meta( 4760, '_ebay_listing_type' ), meta( 4760, TCGiant_Sync_Inventory::AUCTION_LISTED_KEY ) ), array( 'Chinese', '960068033466' ) );
check( 'an auction with stock left: no quantity is ever sent to one', stock_case( 7, array( '_stock' => 3 ), 1, 0, array( 'ListingType' => 'Chinese', 'ListingDuration' => 'Days_7' ) ), array( true, 3, 0, 0, 'Stock kept at 3: WooCommerce had taken off every eBay sale of this listing' ) );
check( 'a product that kept no count: eBay\'s figure, counted from now on, as the import does', stock_case( 8, array( '_manage_stock' => 'no' ), 3, 1 ), array( true, 2, 1, 0, 'Stock set to 2, eBay\'s figure, and counted from now on: WooCommerce kept no count of it' ) );
check( '  its stock managed from now on', meta( 4760, '_manage_stock' ), 'yes' );

// Order sync's "Reduce WooCommerce stock on eBay sale?" took the sale off the
// product by its SKU while nothing pointed to the listing: run for real, it
// notes the listing, and the link takes nothing off again.
fresh_shop( array( 'enable_order_sync' => '1' ) + SETTINGS );
pushed_product( 4761, array( '_sku' => 'SM-4761', '_stock' => 3 ) );
made_listing( '960068033469', TCGiant_Sync_API::push_mark( 4761 ), array( 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '1' ), 'SKU' => 'SM-4761' ) );
$ORDERS = array( array( 'orderId' => '01-1', 'creationDate' => iso( time() - 600 ), 'lineItems' => array( array( 'lineItemId' => 'LI-4761', 'legacyItemId' => '960068033469', 'sku' => 'SM-4761', 'quantity' => 1, 'listingMarketplaceId' => 'EBAY_US' ) ) ) );
new_request();
TCGiant_Sync_Importer::instance()->sync_recent_orders();
check( 'order sync took the eBay sale off the product with no listing, and noted the listing', array( meta( 4761, '_stock' ), meta( 4761, '_ebay_unlinked_sold' ) ), array( 2, array( '960068033469' => 1 ) ) );
import_one( '960068033469' );
check( '  the link takes it off once only: 2 stays 2, which eBay shows; the note is spent', array( meta( 4761, '_ebay_item_id' ), meta( 4761, '_stock' ), meta( 4761, '_ebay_sold_seen' )['sold'] ?? null, pushes_queued( 4761, '960068033469' ), meta( 4761, '_ebay_unlinked_sold' ) ), array( '960068033469', 2, 1, 0, null ) );
fresh_shop( array( 'enable_order_sync' => '1' ) + SETTINGS );
never_pushed( 4762, array( '_sku' => 'SM-4762', '_stock' => 3 ) );
$ORDERS = array( array( 'orderId' => '01-2', 'creationDate' => iso( time() - 600 ), 'lineItems' => array( array( 'lineItemId' => 'LI-4762', 'legacyItemId' => '960068033468', 'sku' => 'SM-4762', 'quantity' => 1, 'listingMarketplaceId' => 'EBAY_US' ) ) ) );
new_request();
TCGiant_Sync_Importer::instance()->sync_recent_orders();
check( '  a product no push of which is unfinished: reduced as before, nothing noted', array( meta( 4762, '_stock' ), meta( 4762, '_ebay_unlinked_sold' ) ), array( 2, null ) );
$ORDERS = array();

// Written as the import's save writes stock: the stock push to eBay held back
// for the save, since the figure came from eBay, under the save-loop breaker.
$mapper_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-mapper.php' );
$link_stock = substr( $mapper_src, (int) strpos( $mapper_src, 'private function link_stock(' ) );
$link_stock = substr( $link_stock, 0, (int) strpos( $link_stock, "\n\t/**" ) );
check( '  its save an eBay-origin write, under the save-loop breaker', 1 === preg_match( '/TCGiant_Sync_Inventory::begin_ebay_origin\(\);\s*TCGiant_Sync_Runtime::guard_loops\(\);\s*try \{\s*\$product->save\(\);\s*\} finally \{\s*TCGiant_Sync_Runtime::unguard_loops\(\);\s*TCGiant_Sync_Inventory::end_ebay_origin\(\);\s*TCGiant_Sync_Inventory::disarm_record\( \$armed \);/', $link_stock ), true );

// A variable product's stock is on its variations: linked, and they are left
// for the listing's next import.
fresh_shop( SETTINGS );
pushed_product( 4770, array( '_stock' => null, '_manage_stock' => 'no' ), 'variable' );
made_listing( '960068033470', TCGiant_Sync_API::push_mark( 4770 ), $with_variations );
$before = products();
import_one( '960068033470' );
check( 'a variable product, a listing with variations: linked, no copy, no stock written', array( meta( 4770, '_ebay_item_id' ), meta( 4770, '_ebay_export_status' ), products() === $before, meta( 4770, '_stock' ), meta( 4770, '_ebay_sold_seen' ) ), array( '960068033470', 'pushed', true, null, null ) );
check( '  the sync log says so', meta( 4770, '_tcgiant_sync_log' )[0]['decisions'][1] ?? null, 'Stock left as it is: its variations take eBay\'s figures at the listing\'s next import' );

echo "\nA LISTING A DEAD PUSH MADE THAT ENDED BEFORE ANY SYNC READ IT: LINKED, AND ITS SALES TAKEN OFF ONCE\n" . str_repeat( '=', 132 ) . "\n";

/**
 * Product $pid as a dead push left it, and its listing, ended on eBay before
 * any sync read it: $quantity listed, $sold sold, started $started_ago
 * seconds ago. In the delta's window.
 */
function ended_orphan( $pid, $item_id, array $product, $quantity, $sold, $started_ago, $mark = true ) {
	pushed_product( $pid, $product );
	made_listing( $item_id, $mark ? TCGiant_Sync_API::push_mark( $pid ) : null, array(
		'Quantity'       => (string) $quantity,
		'SellingStatus'  => array( 'ListingStatus' => 'Completed', 'QuantitySold' => (string) $sold ),
		'ListingDetails' => array( 'StartTime' => iso( time() - $started_ago ), 'EndTime' => iso( time() - 120 ) ),
	) );
	$GLOBALS['EBAY']['window'][] = (string) $item_id;
}
/** The product after the link and the settle: its listing, its status there, its stock, the record of eBay's sales. */
function settled_on( $pid ) {
	return array( meta( $pid, '_ebay_item_id' ), meta( $pid, '_ebay_listing_status' ), meta( $pid, '_ebay_export_status' ), meta( $pid, '_stock' ), meta( $pid, '_ebay_sold_seen' )['sold'] ?? null );
}

// Sold out within the hour: the delta's relist check reads it with GetItem
// anyway, and its answer carries the mark.
fresh_shop( SETTINGS );
ended_orphan( 4790, '960068033490', array( '_stock' => 1 ), 1, 1, 3600 );
$before = products();
delta();
check( 'sold out before the delta read it: linked and settled, stock 0, no copy', array( settled_on( 4790 ), products() === $before ), array( array( '960068033490', 'Ended', 'pushed', 0, 1 ), true ) );
check( '  one GetItem - the relist check\'s own - and no more', gets(), 1 );
check( '  the link line, then the settle', array( logged( linked_line( '960068033490', 4790 ) ), logged( 'eBay item 960068033490 is Completed — WC #4790 marked ended, stock now 0' ) ), array( true, true ) );

// Ended by the seller with 2 of 3 left, the website having sold 1 meanwhile:
// 3 - 1 - 1 leaves 1. Settled from eBay's 2 alone, the product kept 2.
fresh_shop( SETTINGS );
ended_orphan( 4791, '960068033491', array( '_stock' => 2 ), 3, 1, 3600 );
delta();
check( 'ended by the seller, the website and eBay each having sold 1: 1 left, not 2', settled_on( 4791 ), array( '960068033491', 'Ended', 'pushed', 1, 1 ) );
check( '  nothing sent to an ended listing', pushes_queued( 4791, '960068033491' ), 0 );

// eBay's row still said Active; its GetItem says it has ended since.
fresh_shop( SETTINGS );
ended_orphan( 4789, '960068033489', array( '_stock' => 2 ), 2, 2, 3600 );
$EBAY['event_over']['960068033489'] = array( 'SellingStatus' => array( 'ListingStatus' => 'Active', 'QuantitySold' => '1' ), 'Quantity' => '2' );
delta();
check( 'its row said Active, its GetItem says it sold out since: linked and settled', array( gets(), settled_on( 4789 ) ), array( 1, array( '960068033489', 'Ended', 'pushed', 0, 2 ) ) );

// Order sync's "reduce unlinked" already took eBay's sale off by the SKU: it
// is not taken off again.
fresh_shop( array( 'enable_order_sync' => '1' ) + SETTINGS );
ended_orphan( 4792, '960068033492', array( '_stock' => 3, '_sku' => 'SM-4792' ), 3, 1, 3600 );
$EBAY['items']['960068033492']['SKU'] = 'SM-4792';
$ORDERS = array( array( 'orderId' => '02-1', 'creationDate' => iso( time() - 600 ), 'lineItems' => array( array( 'lineItemId' => 'LI-4792', 'legacyItemId' => '960068033492', 'sku' => 'SM-4792', 'quantity' => 1, 'listingMarketplaceId' => 'EBAY_US' ) ) ) );
new_request();
TCGiant_Sync_Importer::instance()->sync_recent_orders();
$ORDERS = array();
delta();
check( 'order sync took the sale off by SKU first: 2, not 1 - taken off once', settled_on( 4792 ), array( '960068033492', 'Ended', 'pushed', 2, 1 ) );

// Started more than a day before the changes being read, which the relist
// check leaves unread: read only because a push that did not finish began
// before it started.
fresh_shop( SETTINGS );
ended_orphan( 4793, '960068033493', array( '_stock' => 1, '_ebay_push_attempt' => time() - 4 * DAY_IN_SECONDS ), 1, 1, 3 * DAY_IN_SECONDS );
delta();
check( 'started 3 days ago, after a push begun 4 days ago that did not finish: read, linked, settled', array( gets(), settled_on( 4793 ) ), array( 1, array( '960068033493', 'Ended', 'pushed', 0, 1 ) ) );

fresh_shop( SETTINGS );
ended_orphan( 4794, '960068033494', array( '_stock' => 1, '_ebay_push_attempt' => time() - DAY_IN_SECONDS ), 1, 1, 3 * DAY_IN_SECONDS );
delta();
check( '  started 3 days ago, the unfinished push begun yesterday: not read, as before', array( gets(), meta( 4794, '_ebay_item_id' ), meta( 4794, '_stock' ) ), array( 0, null, 1 ) );
fresh_shop( SETTINGS );
ended_orphan( 4795, '960068033495', array( '_stock' => 1, '_ebay_push_attempt' => time() - 4 * DAY_IN_SECONDS + 500 ), 1, 1, 4 * DAY_IN_SECONDS );
delta();
check( '  started within the ten minutes\' slack before that push began (another clock): read', array( gets(), meta( 4795, '_ebay_item_id' ) ), array( 1, '960068033495' ) );

fresh_shop( SETTINGS );
never_pushed( 4796, array( '_ebay_push_attempt' => time() - 4 * DAY_IN_SECONDS ) );
made_listing( '960068033496', TCGiant_Sync_API::push_mark( 4796 ), array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ), 'Quantity' => '1', 'ListingDetails' => array( 'StartTime' => iso( time() - 3 * DAY_IN_SECONDS ) ) ) );
$EBAY['window'] = array( '960068033496' );
$wpdb->asked = array();
delta();
check( '  no product says a push of it did not finish: not read, at the cost of one query', array( gets(), meta( 4796, '_ebay_item_id' ), count( array_filter( $wpdb->asked, function ( $q ) { return false !== strpos( $q, '_ebay_push_attempt' ); } ) ) ), array( 0, null, 1 ) );

// At most PUSH_MARK_READS such reads a request.
fresh_shop( SETTINGS );
pushed_product( 4797, array( '_ebay_push_attempt' => time() - 5 * DAY_IN_SECONDS ) );
for ( $i = 10; $i < 22; $i++ ) {
	made_listing( '9600680335' . $i, null, array( 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ), 'Quantity' => '1', 'ListingDetails' => array( 'StartTime' => iso( time() - 3 * DAY_IN_SECONDS ) ) ) );
	$EBAY['window'][] = '9600680335' . $i;
}
delta();
check( '  twelve such ended listings no product holds: ten read, no more; none linked, nothing made', array( gets(), products() ), array( 10, 1 ) );

// Only a push begun within the last PUSH_MARK_DAYS: a product left saying its
// push did not finish does not keep the reads going for good.
fresh_shop( SETTINGS );
ended_orphan( 4801, '960068033501', array( '_stock' => 1, '_ebay_push_attempt' => time() - 7 * DAY_IN_SECONDS + 600 ), 1, 1, 3 * DAY_IN_SECONDS );
delta();
check( '  a push begun just under 7 days ago: read, linked', array( gets(), meta( 4801, '_ebay_item_id' ) ), array( 1, '960068033501' ) );
fresh_shop( SETTINGS );
ended_orphan( 4801, '960068033502', array( '_stock' => 1, '_ebay_push_attempt' => time() - 7 * DAY_IN_SECONDS - 600 ), 1, 1, 3 * DAY_IN_SECONDS );
delta();
check( '  just over 7 days ago: not read, as before', array( gets(), meta( 4801, '_ebay_item_id' ), meta( 4801, '_stock' ) ), array( 0, null, 1 ) );

// The first unfinished push over a week ago, the product pushed again since
// and that push dying too - as the push side shows it leaves the product, the
// first push's time and the latest's: the week runs from the latest, and the
// listings looked for are those started since the first.
fresh_shop( SETTINGS );
ended_orphan( 4807, '960068033507', array( '_stock' => 2, '_ebay_push_attempt' => time() - 10 * DAY_IN_SECONDS, '_ebay_push_attempt_last' => time() - 3 * DAY_IN_SECONDS ), 2, 1, 3 * DAY_IN_SECONDS - 60 );
delta();
check( '  a push 10 days ago that did not finish, pushed again 3 days ago and that push dying too: its ended listing read, linked, settled', array( gets(), settled_on( 4807 ) ), array( 1, array( '960068033507', 'Ended', 'pushed', 1, 1 ) ) );
fresh_shop( SETTINGS );
ended_orphan( 4807, '960068033508', array( '_stock' => 1, '_ebay_push_attempt' => time() - 5 * DAY_IN_SECONDS, '_ebay_push_attempt_last' => time() - 2 * DAY_IN_SECONDS ), 1, 1, 5 * DAY_IN_SECONDS - 60 );
delta();
check( '  the listing the first of those pushes made, before the latest began: read and linked too', array( gets(), meta( 4807, '_ebay_item_id' ) ), array( 1, '960068033508' ) );
fresh_shop( SETTINGS );
ended_orphan( 4807, '960068033509', array( '_stock' => 1, '_ebay_push_attempt' => time() - 10 * DAY_IN_SECONDS, '_ebay_push_attempt_last' => time() - 8 * DAY_IN_SECONDS ), 1, 1, 8 * DAY_IN_SECONDS - 60 );
delta();
check( '  the latest push over a week ago too: not read', array( gets(), meta( 4807, '_ebay_item_id' ) ), array( 0, null ) );

// Never on a copy of the site: its key is not the live shop's, so no read
// there could ever link.
fresh_shop( SETTINGS );
ended_orphan( 4801, '960068033503', array( '_stock' => 1, '_ebay_push_attempt' => time() - 4 * DAY_IN_SECONDS ), 1, 1, 3 * DAY_IN_SECONDS );
TCGiant_Sync_Environment::reason();
$verdict = new ReflectionProperty( 'TCGiant_Sync_Environment', 'reason' );
$verdict->setAccessible( true );
$verdict->setValue( null, 'address:staging' );
delta();
$verdict->setValue( null, '' );
check( '  on a staging copy: not read', array( gets(), meta( 4801, '_ebay_item_id' ) ), array( 0, null ) );

// Sync Specific Items, and Fetch Inventory's queued imports, have eBay's
// answer in hand.
fresh_shop( SETTINGS );
ended_orphan( 4798, '960068033498', array( '_stock' => 2 ), 2, 2, 3600 );
import_one( '960068033498' );
check( 'a queued GetItem import of it (Sync Specific Items): linked and settled, sold out', settled_on( 4798 ), array( '960068033498', 'Ended', 'pushed', 0, 2 ) );
check( '  its settle line, and no "has ended, so no product was created" line', array( logged( 'Import: eBay item 960068033498 is Completed — WC #4798 marked ended' ), logged( 'so no product was created for it' ) ), array( true, false ) );

// The weekly full sync meets a listing that ended while it ran: the page
// carries ApplicationData, so no call.
fresh_shop( SETTINGS );
ended_orphan( 4799, '960068033499', array( '_stock' => 1 ), 1, 1, 3600 );
full_sync_page( array( $EBAY['items']['960068033499'] ) );
check( 'the full sync\'s page: linked and settled with no call', array( gets(), settled_on( 4799 ) ), array( 0, array( '960068033499', 'Ended', 'pushed', 0, 1 ) ) );

// Every rule of the link holds for an ended listing too.
fresh_shop( SETTINGS );
ended_orphan( 4800, '960068033500', array( '_stock' => 1 ), 1, 1, 3600 );
unset( $META[4800]['_ebay_export_status'] );
delta();
check( 'ended, the product no longer saying its push did not finish: not linked, as before', array( meta( 4800, '_ebay_item_id' ), meta( 4800, '_stock' ), products() ), array( null, 1, 1 ) );

echo "\nA DEAD PUSH THAT WAS MAKING A NEW LISTING IN PLACE OF AN ENDED ONE: MOVED ON TO IT\n" . str_repeat( '=', 132 ) . "\n";

// The product is still on the listing that ended, which its push recorded as
// Ended before asking eBay for the new one.
fresh_shop( SETTINGS );
pushed_product( 4810, array( '_ebay_item_id' => '960068033900', '_ebay_listing_status' => 'Ended', '_sku' => '960068033900' ) );
made_listing( '960068033810', TCGiant_Sync_API::push_mark( 4810 ) );
$before = products();
import_one( '960068033810' );
check( 'moved on as that push would have: the new listing, the old one as its previous, no copy', array( meta( 4810, '_ebay_item_id' ), meta( 4810, '_ebay_previous_item_id' ), meta( 4810, '_ebay_listing_status' ), meta( 4810, '_ebay_export_status' ), products() === $before ), array( '960068033810', '960068033900', 'Active', 'pushed', true ) );
check( '  the "Relisted" line, and the link line', array( logged( 'Relisted "Starter motor 4810" (WC #4810): ended listing 960068033900 replaced by new listing 960068033810.' ), logged( linked_line( '960068033810', 4810 ) ) ), array( true, true ) );
check( '  a SKU made from the old item number follows it, as for any relist', meta( 4810, '_sku' ), '960068033810' );
check( '  the sync log says it moved on', meta( 4810, '_tcgiant_sync_log' )[0]['decisions'][0] ?? null, 'Moved on to eBay listing 960068033810 in place of ended listing 960068033900: a push of this product made it and did not live to save it. Title, description, price and photos kept (WooCommerce won)' );
check( '  no "listed twice" warning', logged( 'listed twice' ), false );

// Recorded as over another way: settled, or given an end reason.
fresh_shop( SETTINGS );
pushed_product( 4811, array( '_ebay_item_id' => '960068033901', '_ebay_listing_status' => 'Active', '_ebay_stock_settled_item' => '960068033901' ) );
made_listing( '960068033811', TCGiant_Sync_API::push_mark( 4811 ) );
import_one( '960068033811' );
check( '  its old listing settled: moved on too', array( meta( 4811, '_ebay_item_id' ), meta( 4811, '_ebay_previous_item_id' ) ), array( '960068033811', '960068033901' ) );
fresh_shop( SETTINGS );
pushed_product( 4812, array( '_ebay_item_id' => '960068033902', '_ebay_listing_status' => 'Active', '_ebay_end_reason_item' => '960068033902', '_ebay_end_reason' => 'by_shop' ) );
made_listing( '960068033812', TCGiant_Sync_API::push_mark( 4812 ) );
import_one( '960068033812' );
check( '  its old listing given a reason it ended: moved on too', array( meta( 4812, '_ebay_item_id' ), meta( 4812, '_ebay_previous_item_id' ) ), array( '960068033812', '960068033902' ) );

// Not when the product does not say its push did not finish, nor when its
// listing is not recorded as over.
fresh_shop( SETTINGS );
pushed_product( 4813, array( '_ebay_item_id' => '960068033903', '_ebay_listing_status' => 'Ended', '_ebay_export_status' => 'pushed', '_ebay_export_error' => '' ) );
made_listing( '960068033813', TCGiant_Sync_API::push_mark( 4813 ) );
$before = products();
import_one( '960068033813' );
check( 'on an ended listing, its push finished: not moved; imported as today', array( meta( 4813, '_ebay_item_id' ), products() === $before + 1 ), array( '960068033903', true ) );
check( '  a warning that says the old listing has ended, not that both are live', array( logged( 'eBay listing 960068033813 was made by a push of WC #4813, but WC #4813 is still linked to listing 960068033903, which has ended, and does not say that push did not finish', 'warning' ), logged( 'listed twice' ) ), array( true, false ) );
fresh_shop( SETTINGS );
pushed_product( 4814, array( '_ebay_item_id' => '960068033904', '_ebay_listing_status' => 'Active' ) );
made_listing( '960068033814', TCGiant_Sync_API::push_mark( 4814 ) );
$before = products();
import_one( '960068033814' );
check( 'on a live listing, its push did not finish: not moved; imported as today, "listed twice" said', array( meta( 4814, '_ebay_item_id' ), products() === $before + 1, logged( 'If both are live on eBay, WC #4814 is listed twice', 'warning' ) ), array( '960068033904', true, true ) );

echo "\nTHE DESCRIPTION: THE FIRST IMPORT AFTER A LINK KEEPS THE PRODUCT'S OWN\n" . str_repeat( '=', 132 ) . "\n";

require_once $ROOT . '/includes/class-tcgiant-sync-templates.php';

// Sent with a template: the listing holds the rendered page, its content
// region marked, and the template's comment. "Overwrite description" is on.
$templated = '<style>.tcg-desc{color:#222}</style><div class="tcg-desc" data-tcg="content"><p>Rebuilt and bench tested.</p></div><!--tcg:tpl=12.3-->';
fresh_shop( SETTINGS );
pushed_product( 4820 );
made_listing( '960068033820', TCGiant_Sync_API::push_mark( 4820 ), array( 'Description' => $templated ) );
import_one( '960068033820' );
check( 'linked: the record of what the push sent, from the listing\'s own description', array( meta( 4820, '_tcgiant_tpl_id' ), meta( 4820, '_tcgiant_tpl_ver' ), meta( 4820, '_tcgiant_tpl_item' ), meta( 4820, '_tcgiant_desc_hash' ), meta( 4820, '_tcgiant_region_hash' ) ), array( '12', '3', '960068033820', sha1( $templated ), sha1( '<p>Rebuilt and bench tested.</p>' ) ) );
$EBAY['items']['960068033820']['SellingStatus']['CurrentPrice'] = '33.00';
import_one( '960068033820' );
check( '  the next import, overwrite on: the product\'s own description kept', array( meta( 4820, '_description' ), in_array( 'Description kept: the listing holds what TCGiant sent it', (array) ( meta( 4820, '_tcgiant_sync_log' )[0]['decisions'] ?? array() ), true ) ), array( 'Rebuilt and bench tested.', true ) );
// Without the record - as before this - the same import writes the listing's
// content region over it.
foreach ( TCGiant_Sync_Templates::SENT_META as $sent_key ) {
	unset( $META[4820][ $sent_key ] );
}
import_one( '960068033820' );
check( '  (with no record, the same import writes the listing\'s HTML over it)', meta( 4820, '_description' ), '<p>Rebuilt and bench tested.</p>' );

// An eBay-only description, sent without a template.
fresh_shop( SETTINGS );
pushed_product( 4821, array( '_tcgiant_ebay_description' => 'Fits 2004-2008 only.' ) );
made_listing( '960068033821', TCGiant_Sync_API::push_mark( 4821 ), array( 'Description' => '<p>Fits 2004-2008 only.</p>' ) );
import_one( '960068033821' );
import_one( '960068033821' );
check( 'an eBay-only description: the website\'s own description kept at the next import', array( meta( 4821, '_tcgiant_desc_hash' ), meta( 4821, '_description' ) ), array( sha1( '<p>Fits 2004-2008 only.</p>' ), 'Rebuilt and bench tested.' ) );

// eBay dropped the template's comment (whether it does is unverified): the
// template the product is set to stands in for it.
fresh_shop( SETTINGS );
$POSTS[31] = array( 'type' => 'tcgiant_tpl', 'status' => 'publish', 'product_type' => '' );
pushed_product( 4822, array( '_tcgiant_tpl_override' => '31' ) );
$no_comment = '<div class="tcg-desc" data-tcg="content"><p>Rebuilt and bench tested.</p></div><div class="tcg-footer">Shipping and returns</div>';
made_listing( '960068033822', TCGiant_Sync_API::push_mark( 4822 ), array( 'Description' => $no_comment ) );
import_one( '960068033822' );
import_one( '960068033822' );
check( '  the template\'s comment gone from the listing: the product\'s template stands in, and its description is kept', array( meta( 4822, '_tcgiant_tpl_id' ), meta( 4822, '_tcgiant_region_hash' ), meta( 4822, '_description' ) ), array( '31', sha1( '<p>Rebuilt and bench tested.</p>' ), 'Rebuilt and bench tested.' ) );

echo "\nTHE MARK NAMES THE PRODUCT, NOT THE PUSH: AN OLDER LISTING OF IT IS NOT THE UNFINISHED PUSH'S\n" . str_repeat( '=', 132 ) . "\n";

// P1 (review of dcdaa33). 5101 was pushed to L0 (5 listed); eBay sold 2,
// synced in: WooCommerce holds 3. The shop Unlinks it (a push that finished:
// nothing kept), pushes again, and that push makes L1 and dies. L0 changes
// first, and the sync reads it.
fresh_shop( SETTINGS );
pushed_product( 5101, array( '_ebay_item_id' => '960000000100', '_ebay_export_status' => 'pushed', '_ebay_export_error' => '', '_stock' => 3, '_ebay_sold_seen' => array( 'item' => '960000000100', 'sku' => '', 'sold' => 2 ) ) );
unset( $META[5101]['_ebay_push_attempt'] );
made_listing( '960000000100', TCGiant_Sync_API::push_mark( 5101 ), array( 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '2' ), 'ListingDetails' => array( 'StartTime' => iso( time() - 20 * DAY_IN_SECONDS ) ) ) );
TCGiant_Sync_Listing_Link::unlink_product_from_ebay( 5101, 'unlinked by admin' );
$META[5101]['_ebay_export_status'] = 'failed';
$META[5101]['_ebay_push_attempt']  = time() - 600;
made_listing( '960000000101', TCGiant_Sync_API::push_mark( 5101 ), array( 'Quantity' => '3', 'ListingDetails' => array( 'StartTime' => iso( time() - 590 ) ) ) );
import_one( '960000000100' );
check( 'P1 the listing it was unlinked from, started 20 days before the push: not linked', meta( 5101, '_ebay_item_id' ), null );
check( '  its stock stays 3 (those 2 sales were in it), nothing sent to eBay', array( meta( 5101, '_stock' ), pushes_queued( 5101, '960000000100' ) ), array( 3, 0 ) );
check( '  said at debug level', logged( 'eBay listing 960000000100 was made by a push of WC #5101, but it started before the push of WC #5101 that did not finish began (' . gmdate( 'Y-m-d H:i:s', (int) meta( 5101, '_ebay_push_attempt' ) ) . ' UTC), so an earlier push made it: the listing is imported as any other.', 'debug' ), true );
import_one( '960000000101' );
check( '  then the dead push\'s own listing: linked, no copy of it', array( meta( 5101, '_ebay_item_id' ), holder( '960000000101', 5101 ), meta( 5101, '_stock' ) ), array( '960000000101', 0, 3 ) );

// P1b: the same through the delta, both in one window.
fresh_shop( SETTINGS );
pushed_product( 5111, array( '_ebay_item_id' => '960000000110', '_ebay_export_status' => 'pushed', '_ebay_export_error' => '', '_stock' => 3, '_ebay_sold_seen' => array( 'item' => '960000000110', 'sku' => '', 'sold' => 2 ) ) );
unset( $META[5111]['_ebay_push_attempt'] );
made_listing( '960000000110', TCGiant_Sync_API::push_mark( 5111 ), array( 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '2' ), 'ListingDetails' => array( 'StartTime' => iso( time() - 20 * DAY_IN_SECONDS ) ) ) );
TCGiant_Sync_Listing_Link::unlink_product_from_ebay( 5111, 'unlinked by admin' );
$META[5111]['_ebay_export_status'] = 'failed';
$META[5111]['_ebay_push_attempt']  = time() - 600;
made_listing( '960000000111', TCGiant_Sync_API::push_mark( 5111 ), array( 'Quantity' => '3', 'ListingDetails' => array( 'StartTime' => iso( time() - 590 ) ) ) );
$EBAY['window'] = array( '960000000111', '960000000110' );
delta();
check( 'P1b through the delta: linked to the dead push\'s listing, stock 3, no copy of it', array( meta( 5111, '_ebay_item_id' ), meta( 5111, '_stock' ), holder( '960000000111', 5111 ) ), array( '960000000111', 3, 0 ) );

// P2: a relist chain. 5102 moved L0 -> L1 by a push that finished; L1 ended,
// and the push making L2 in its place died (B). L0, ended long ago, is read.
fresh_shop( SETTINGS );
pushed_product( 5102, array( '_ebay_item_id' => '960000000201', '_ebay_listing_status' => 'Ended', '_stock' => 3, '_ebay_push_attempt' => time() - 600 ) );
made_listing( '960000000200', TCGiant_Sync_API::push_mark( 5102 ), array( 'Quantity' => '5', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '2' ), 'ListingDetails' => array( 'StartTime' => iso( time() - 60 * DAY_IN_SECONDS ), 'EndTime' => iso( time() - 30 * DAY_IN_SECONDS ) ) ) );
made_listing( '960000000202', TCGiant_Sync_API::push_mark( 5102 ), array( 'ListingDetails' => array( 'StartTime' => iso( time() - 590 ) ) ) );
import_one( '960000000200' );
check( 'P2 an older ended listing of it: not moved back onto it, stock stays 3', array( meta( 5102, '_ebay_item_id' ), meta( 5102, '_stock' ) ), array( '960000000201', 3 ) );
import_one( '960000000202' );
check( '  then the dead push\'s own: moved on to it, no copy', array( meta( 5102, '_ebay_item_id' ), meta( 5102, '_ebay_previous_item_id' ), holder( '960000000202', 5102 ) ), array( '960000000202', '960000000201', 0 ) );

// The edge: ten minutes of slack for eBay's clock against this server's.
fresh_shop( SETTINGS );
pushed_product( 5103, array( '_ebay_push_attempt' => time() - 7200 ) );
made_listing( '960000000300', TCGiant_Sync_API::push_mark( 5103 ), array( 'ListingDetails' => array( 'StartTime' => iso( time() - 7200 - 600 ) ) ) );
import_one( '960000000300' );
check( 'started ten minutes before the push began, by eBay\'s clock: linked', meta( 5103, '_ebay_item_id' ), '960000000300' );
fresh_shop( SETTINGS );
pushed_product( 5103, array( '_ebay_push_attempt' => time() - 7200 ) );
made_listing( '960000000301', TCGiant_Sync_API::push_mark( 5103 ), array( 'ListingDetails' => array( 'StartTime' => iso( time() - 7200 - 601 ) ) ) );
import_one( '960000000301' );
check( '  a second more: not linked', meta( 5103, '_ebay_item_id' ), null );

// No time to judge by: not linked.
fresh_shop( SETTINGS );
pushed_product( 5104 );
unset( $META[5104]['_ebay_push_attempt'] );
made_listing( '960000000400', TCGiant_Sync_API::push_mark( 5104 ) );
$before = products();
import_one( '960000000400' );
check( 'a product saying its push failed, with no time that push began: not linked; imported as today', imported_as_today( 5104, '960000000400', $before ), $today );
check( '  said at debug level', logged( 'eBay listing 960000000400 was made by a push of WC #5104, but WC #5104 does not say when its push that did not finish began: the listing is imported as any other.', 'debug' ), true );
fresh_shop( SETTINGS );
pushed_product( 5105 );
made_listing( '960000000500', TCGiant_Sync_API::push_mark( 5105 ) );
unset( $EBAY['items']['960000000500']['ListingDetails']['StartTime'] );
$before = products();
import_one( '960000000500' );
check( 'a listing eBay gave no start time for: not linked; imported as today', imported_as_today( 5105, '960000000500', $before ), $today );
check( '  said at debug level', logged( 'eBay listing 960000000500 was made by a push of WC #5105, but eBay gave no start time for it: the listing is imported as any other.', 'debug' ), true );
fresh_shop( SETTINGS );
pushed_product( 5105, array( '_ebay_push_attempt' => 500 ) );
made_listing( '960000000501', TCGiant_Sync_API::push_mark( 5105 ) );
unset( $EBAY['items']['960000000501']['ListingDetails']['StartTime'] );
import_one( '960000000501' );
check( '  nor when the push began within the slack of 1970', meta( 5105, '_ebay_item_id' ), null );

echo "\nAN OLDER LISTING THAT STARTED JUST BEFORE THE UNFINISHED PUSH (RE-VERIFY OF aebe4a1)\n" . str_repeat( '=', 132 ) . "\n";

// S1: L0 -> L1 by a push that finished, L1 ended, and the push making L2 in
// its place died - all within the hour of L0's start; L0, ended 25 minutes
// before that push, is then read (a queued import, Sync Specific Items).
fresh_shop( SETTINGS );
pushed_product( 5181, array( '_ebay_item_id' => '960000001811', '_ebay_listing_status' => 'Ended', '_stock' => 3, '_ebay_push_attempt' => time() - 600, '_ebay_push_attempt_last' => time() - 600 ) );
made_listing( '960000001810', TCGiant_Sync_API::push_mark( 5181 ), array( 'Quantity' => '2', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ), 'ListingDetails' => array( 'StartTime' => iso( time() - 3000 ), 'EndTime' => iso( time() - 2100 ) ) ) );
made_listing( '960000001812', TCGiant_Sync_API::push_mark( 5181 ), array( 'ListingDetails' => array( 'StartTime' => iso( time() - 590 ) ) ) );
import_one( '960000001810' );
check( 'S1 not moved back onto L0, which ended before the unfinished push began; stock 3', array( meta( 5181, '_ebay_item_id' ), meta( 5181, '_stock' ) ), array( '960000001811', 3 ) );
import_one( '960000001812' );
check( '  then the dead push\'s L2 linked, no copy', array( meta( 5181, '_ebay_item_id' ), holder( '960000001812', 5181 ) ), array( '960000001812', 0 ) );

// S2: the same through a lagging delta, L0 and L2 in one window, L0 first.
fresh_shop( SETTINGS );
pushed_product( 5182, array( '_ebay_item_id' => '960000001821', '_ebay_listing_status' => 'Ended', '_stock' => 3, '_ebay_push_attempt' => time() - 600, '_ebay_push_attempt_last' => time() - 600 ) );
made_listing( '960000001820', TCGiant_Sync_API::push_mark( 5182 ), array( 'Quantity' => '2', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ), 'ListingDetails' => array( 'StartTime' => iso( time() - 3000 ), 'EndTime' => iso( time() - 2100 ) ) ) );
made_listing( '960000001822', TCGiant_Sync_API::push_mark( 5182 ), array( 'ListingDetails' => array( 'StartTime' => iso( time() - 590 ) ) ) );
$EBAY['window'] = array( '960000001820', '960000001822' );
delta( 2 );
check( 'S2 the delta: P on the dead push\'s L2, stock 3, no copy of L2', array( meta( 5182, '_ebay_item_id' ), meta( 5182, '_stock' ), holder( '960000001822', 5182 ) ), array( '960000001822', 3, 0 ) );

// The same, all within the ten minutes' slack: L0 started 5 minutes before
// the push began, so its start lets it through; it ended before the push
// began, which the listing that push made cannot have.
fresh_shop( SETTINGS );
pushed_product( 5183, array( '_ebay_item_id' => '960000001831', '_ebay_listing_status' => 'Ended', '_stock' => 3, '_ebay_push_attempt' => time() - 600, '_ebay_push_attempt_last' => time() - 600 ) );
made_listing( '960000001830', TCGiant_Sync_API::push_mark( 5183 ), array( 'Quantity' => '2', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ), 'ListingDetails' => array( 'StartTime' => iso( time() - 900 ), 'EndTime' => iso( time() - 800 ) ) ) );
made_listing( '960000001832', TCGiant_Sync_API::push_mark( 5183 ), array( 'ListingDetails' => array( 'StartTime' => iso( time() - 590 ) ) ) );
import_one( '960000001830' );
check( 'an older listing that started within the slack and ended before the push began: not moved back onto it; stock 3', array( meta( 5183, '_ebay_item_id' ), meta( 5183, '_stock' ) ), array( '960000001831', 3 ) );
check( '  said at debug level', logged( 'eBay listing 960000001830 was made by a push of WC #5183, but it ended before the push of WC #5183 that did not finish began (' . gmdate( 'Y-m-d H:i:s', (int) meta( 5183, '_ebay_push_attempt' ) ) . ' UTC), so an earlier push made it: the listing is imported as any other.', 'debug' ), true );
import_one( '960000001832' );
check( '  then the dead push\'s own linked, no copy', array( meta( 5183, '_ebay_item_id' ), holder( '960000001832', 5183 ) ), array( '960000001832', 0 ) );

// The listing the push made, ended at the very second the push began by the
// two clocks: linked.
fresh_shop( SETTINGS );
pushed_product( 5184, array( '_stock' => 1, '_ebay_push_attempt' => time() - 600 ) );
made_listing( '960000001840', TCGiant_Sync_API::push_mark( 5184 ), array( 'Quantity' => '1', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ), 'ListingDetails' => array( 'StartTime' => iso( (int) meta( 5184, '_ebay_push_attempt' ) - 60 ), 'EndTime' => iso( (int) meta( 5184, '_ebay_push_attempt' ) ) ) ) );
import_one( '960000001840' );
check( 'a listing that ended as the push began, by the two clocks: linked and settled', array( meta( 5184, '_ebay_item_id' ), meta( 5184, '_stock' ) ), array( '960000001840', 0 ) );
fresh_shop( SETTINGS );
pushed_product( 5186 );
made_listing( '960000001860', TCGiant_Sync_API::push_mark( 5186 ) );
unset( $EBAY['items']['960000001860']['ListingDetails']['EndTime'] );
import_one( '960000001860' );
check( 'a listing eBay gave no end time for: linked by its start', meta( 5186, '_ebay_item_id' ), '960000001860' );

// The live variant: the shop Unlinks a finished push's listing L0 (started
// 50 minutes ago, 2 of 5 sold, synced in) and pushes again; that push dies.
fresh_shop( SETTINGS );
pushed_product( 5185, array( '_ebay_item_id' => '960000001850', '_ebay_export_status' => 'pushed', '_ebay_export_error' => '', '_stock' => 3, '_ebay_sold_seen' => array( 'item' => '960000001850', 'sku' => '', 'sold' => 2 ) ) );
unset( $META[5185]['_ebay_push_attempt'] );
made_listing( '960000001850', TCGiant_Sync_API::push_mark( 5185 ), array( 'Quantity' => '5', 'SellingStatus' => array( 'QuantitySold' => '2' ), 'ListingDetails' => array( 'StartTime' => iso( time() - 3000 ) ) ) );
TCGiant_Sync_Listing_Link::unlink_product_from_ebay( 5185, 'unlinked by admin' );
$META[5185]['_ebay_export_status']     = 'failed';
$META[5185]['_ebay_push_attempt']      = time() - 600;
$META[5185]['_ebay_push_attempt_last'] = time() - 600;
made_listing( '960000001851', TCGiant_Sync_API::push_mark( 5185 ), array( 'Quantity' => '3', 'ListingDetails' => array( 'StartTime' => iso( time() - 590 ) ) ) );
import_one( '960000001850' );
check( 'Unlinked, pushed again 40 minutes after its listing started, that push dead: the old listing not linked back; stock 3, nothing sent', array( meta( 5185, '_ebay_item_id' ), meta( 5185, '_stock' ), pushes_queued( 5185, '960000001850' ) ), array( null, 3, 0 ) );
import_one( '960000001851' );
check( '  then the dead push\'s own linked, no copy', array( meta( 5185, '_ebay_item_id' ), holder( '960000001851', 5185 ), meta( 5185, '_stock' ) ), array( '960000001851', 0, 3 ) );

echo "\nAN ENDED LISTING ON A SITE THE SHOP DOES NOT IMPORT FROM IS NOT LINKED\n" . str_repeat( '=', 132 ) . "\n";

// P4 (review of dcdaa33): "Import listings from" the primary site only, and
// an ended eBay.de listing carrying this site's mark - a copy tool that
// copies ApplicationData. The full sync's page, and a queued import.
fresh_shop( array( 'import_sites' => 'primary', 'marketplace' => 'EBAY_US' ) + SETTINGS );
pushed_product( 5106, array( '_stock' => 3 ) );
$copy = made_listing( '960000000600', TCGiant_Sync_API::push_mark( 5106 ), array( 'Site' => 'Germany', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ), 'Quantity' => '3' ) );
full_sync_page( array( $copy ) );
check( 'P4 the full sync\'s page: not linked, stock stays 3', array( meta( 5106, '_ebay_item_id' ), meta( 5106, '_stock' ) ), array( null, 3 ) );
import_one( '960000000600' );
check( '  a queued import of it: not linked either', array( meta( 5106, '_ebay_item_id' ), meta( 5106, '_stock' ) ), array( null, 3 ) );
fresh_shop( array( 'import_sites' => 'all', 'marketplace' => 'EBAY_US' ) + SETTINGS );
pushed_product( 5106, array( '_stock' => 3 ) );
$copy = made_listing( '960000000601', TCGiant_Sync_API::push_mark( 5106 ), array( 'Site' => 'Germany', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '1' ), 'Quantity' => '3' ) );
full_sync_page( array( $copy ) );
check( '  every site imported: linked, as step 1c would', meta( 5106, '_ebay_item_id' ), '960000000601' );

echo "\nTHE CATEGORY FILTER: A ROW IT SKIPS MAKES NO PRODUCT, SO NEEDS NOTHING\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop( array( 'import_standard_category_ids' => array( '99999' ) ) + SETTINGS );
pushed_product( 4780 );
$listing = made_listing( '960068033480', TCGiant_Sync_API::push_mark( 4780 ) );
$before  = products();
full_sync_page( array( $listing ) );
check( 'a listing outside the filter: nothing made, nothing linked', array( products() === $before, meta( 4780, '_ebay_item_id' ) ), array( true, null ) );

echo "\nA PAGE WITHOUT THE MARK SAYS SO\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop( SETTINGS );
$listing = made_listing( '960068033490', 'tcgprobe-11755' );
full_sync_page( array( $listing, made_listing( '960068033491', TCGiant_Sync_API::push_mark( 4799 ) ) ) );
check( 'the debug count: 1 of 2 (the probe\'s value is not a mark)', logged( 'Full sync page 1: 1 of 2 listings read carry this site\'s push mark (ApplicationData).', 'debug' ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
