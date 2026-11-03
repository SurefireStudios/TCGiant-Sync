<?php
/**
 * Harness: whose values a listing's Updates send, chosen in bulk, and who
 * made each listing on the Listings screen (3.29.1).
 *
 * 3.28.8 judges each linked product imported from eBay or pushed from here,
 * on evidence: an imported listing's Update leaves eBay's own category,
 * condition, business policies, location and format alone, and a pushed one
 * is sent the shop's defaults. Most products pushed before 3.28.8 and Updated
 * since now count as imported. A product's eBay tab has a box that says
 * otherwise for it (_ebay_export_follow_defaults: '1' sends the defaults, '0'
 * keeps eBay's values, '' goes by who made it). Now:
 *
 * - three bulk actions, on the products list and on the Listings screen -
 *   "Send my shop defaults to eBay on Update", "Keep each listing's own eBay
 *   values on Update" and "Decide by who made the listing" - save through
 *   the box's own code as the shop's own choice: Send stores '1' and Keep
 *   '0' whoever made the listing (the coordinator's ruling on question 1),
 *   so a Keep on a listing not known yet is still a Keep once a read finds
 *   it pushed, and the third clears it; the box itself still stores nothing
 *   where it agrees with who made the listing, and since the review changes
 *   nothing when it is saved as the tab showed it - a bulk choice included -
 *   and keeps '1' or '0' when changed on a listing not known yet; each needs
 *   its nonce and the capability, and a failure of either changes nothing;
 *   the products list changes at most 500 a request, the ones already so
 *   not counted, so the same selection again finishes it
 *   and says how many it left, the Listings screen 100 a request through its
 *   job runner, any number; a notice gives the counts; nothing goes to eBay;
 * - the Listings screen has an Origin column (Imported, Pushed, Not known
 *   yet, and whether its Updates send the shop's defaults, by its own choice
 *   or by who made it) and a filter by the same values, run in the listings
 *   query itself, the page, its count and "everything matching" alike;
 * - once 3.28.8's pass has judged the products linked before it, a notice on
 *   TCGiant Sync's pages says how many judged imported have a push from here
 *   on record and no choice of their own, counted in one query, and points to
 *   the filter for them and the bulk actions; it goes when none is left, or
 *   when dismissed, for good.
 *
 * Loaded for real: TCGiant_Sync_Admin, TCGiant_Sync_Jobs, TCGiant_Sync_DB,
 * TCGiant_Sync_Ebay_Live, TCGiant_Sync_Inventory (with the runtime),
 * TCGiant_Sync_Exporter, TCGiant_Sync_API, TCGiant_Sync_Catalog and
 * admin/views/listings.php itself. Posts, meta and the listings table are
 * SQLite (tests/support/sold-world.php), so every query runs. eBay is never
 * reached: every HTTP function and Action Scheduler are played, and counted.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/sold-world.php';

class TCGiant_Sync_Logger {
	public static $lines = array();
	public static function log( $message, $level = 'info' ) { self::$lines[] = $message; }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
}
function is_admin() { return true; }
function wp_doing_cron() { return false; }

/** How a handler ends: an answer, a redirect, or a stop. */
class Harness_Stop extends Exception {
	public $kind;
	public $data;
	public function __construct( $kind, $data = null ) { parent::__construct( $kind ); $this->kind = $kind; $this->data = $data; }
}
function wp_send_json_success( $data = null ) { throw new Harness_Stop( 'json_ok', $data ); }
function wp_send_json_error( $data = null ) { throw new Harness_Stop( 'json_error', $data ); }
function wp_die( $message = '' ) { throw new Harness_Stop( 'died', $message ); }
function wp_safe_redirect( $url ) { throw new Harness_Stop( 'redirect', $url ); }
function wp_get_referer() { return false; }

// The user: what they may do ($CAN, cap => bool; anything else allowed) and
// the products they may not edit.
$CAN        = array();
$DENY_POSTS = array();
function current_user_can( $cap, ...$args ) {
	if ( 'edit_post' === $cap && in_array( (int) ( $args[0] ?? 0 ), $GLOBALS['DENY_POSTS'], true ) ) {
		return false;
	}
	return $GLOBALS['CAN'][ $cap ] ?? true;
}
// Nonces: a good one is "nonce-" and its action. The AJAX one by $AJAX_OK.
$AJAX_OK = true;
function wp_verify_nonce( $nonce, $action = -1 ) { return 'nonce-' . $action === (string) $nonce ? 1 : false; }
function check_ajax_referer( $action = -1, $arg = false, $die = true ) {
	if ( ! $GLOBALS['AJAX_OK'] ) {
		throw new Harness_Stop( 'died', 'nonce' );
	}
	return 1;
}
function check_admin_referer( $action = -1, $arg = '_wpnonce' ) {
	if ( 'nonce-' . $action !== (string) ( $_REQUEST['_wpnonce'] ?? '' ) ) {
		throw new Harness_Stop( 'died', 'nonce' );
	}
	return 1;
}
function wp_nonce_url( $url, $action = -1 ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . '_wpnonce=nonce-' . $action; }
$SCREEN = 'tcgiant-sync_page_tcgiant-listings';
$USER_ID = 7;
function get_current_user_id() { return $GLOBALS['USER_ID']; }
// When each product was made (post_date_gmt), for origin()'s date rules.
$DATES = array();
function get_post_field( $field, $id ) { return 'post_date_gmt' === $field ? ( $GLOBALS['DATES'][ (int) $id ] ?? '' ) : ''; }
function get_current_screen() { return null === $GLOBALS['SCREEN'] ? null : (object) array( 'id' => $GLOBALS['SCREEN'] ); }

// eBay and the push queue, played: anything that reached them is counted.
$EBAY = array();
function wp_remote_request( $url, $args = array() ) { $GLOBALS['EBAY'][] = $url; return new WP_Error( 'harness', 'no network' ); }
function wp_remote_post( $url, $args = array() ) { $GLOBALS['EBAY'][] = $url; return new WP_Error( 'harness', 'no network' ); }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['EBAY'][] = $url; return new WP_Error( 'harness', 'no network' ); }
function wp_safe_remote_post( $url, $args = array() ) { $GLOBALS['EBAY'][] = $url; return new WP_Error( 'harness', 'no network' ); }
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { $GLOBALS['EBAY'][] = 'queue ' . $hook; return 1; }
function as_schedule_single_action( $time, $hook, $args = array(), $group = '' ) { $GLOBALS['EBAY'][] = 'queue ' . $hook; return 1; }

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-db.php';
require $ROOT . '/includes/class-tcgiant-sync-inventory.php';
require $ROOT . '/includes/class-tcgiant-sync-exporter.php';
require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require $ROOT . '/includes/class-tcgiant-sync-jobs.php';
require $ROOT . '/admin/class-tcgiant-sync-admin.php';

const FOLLOW = '_ebay_export_follow_defaults';

/** The admin, with nothing registered. */
function admin() {
	static $a = null;
	if ( null === $a ) {
		$a = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();
	}
	return $a;
}
/** A linked product, with its listings row. */
function linked( $id, array $meta = array(), $status = 'publish' ) {
	sold_product( $id, array_merge( array( '_ebay_item_id' => '7' . $id, '_ebay_listing_status' => 'Active' ), $meta ), array(), $status );
}
/** Its choice, as stored. */
function follow( $id ) {
	return (string) get_post_meta( $id, FOLLOW, true );
}
/** How many rows its choice has. */
function follow_rows( $id ) {
	global $wpdb;
	return (int) $wpdb->db->querySingle( sprintf( "SELECT COUNT(*) FROM wp_postmeta WHERE post_id = %d AND meta_key = '%s'", (int) $id, FOLLOW ) );
}
/** Set its choice as it stood before ('' for none). */
function start( $id, $value ) {
	delete_post_meta( $id, FOLLOW );
	if ( '' !== $value ) {
		update_post_meta( $id, FOLLOW, $value );
	}
}
/**
 * The eBay tab's box, saved with the product: ticked or not, posted beside the
 * state the tab showed it in ('1' or '0') - by default what it shows now, as
 * the page does; false for a page that does not say.
 */
function box( $id, $ticked, $shown = null ) {
	if ( null === $shown ) {
		$shown = TCGiant_Sync_Ebay_Live::follows_defaults( $id ) ? '1' : '0';
	}
	$_POST = array( '_ebay_export_follow_defaults_shown' => '1' ) + ( false !== $shown ? array( '_ebay_export_follow_defaults_was' => $shown ) : array() ) + ( $ticked ? array( '_ebay_export_follow_defaults' => '1' ) : array() );
	admin()->save_product_export_meta( $id );
	$_POST = array();
	return follow( $id );
}
/** What the products list's bulk action kept for the user's next page. */
function said() {
	return get_transient( TCGiant_Sync_Admin::FOLLOW_SAID . get_current_user_id() );
}
/** The products list's bulk action: where it sends the browser. */
function by_list( array $ids, $choice, $nonce = 'nonce-bulk-posts' ) {
	delete_transient( TCGiant_Sync_Admin::FOLLOW_SAID . get_current_user_id() );
	$_REQUEST = array( '_wpnonce' => $nonce );
	$back     = admin()->handle_follow_bulk_action( 'https://shop.example/wp-admin/edit.php?post_type=product', 'tcgiant_follow_' . $choice, $ids );
	$_REQUEST = array();
	return $back;
}
/** The products list's notice, on a screen. */
function list_notice( $screen = 'edit-product' ) {
	$GLOBALS['SCREEN'] = $screen;
	ob_start();
	admin()->follow_bulk_notice();
	return html_entity_decode( (string) ob_get_clean(), ENT_QUOTES );
}
/** A job runner call: its answer, or how it stopped. */
function job_call( $method, array $post ) {
	$_POST = $post;
	try {
		( new TCGiant_Sync_Jobs() )->$method();
	} catch ( Harness_Stop $stop ) {
		$_POST = array();
		return $stop;
	}
	$_POST = array();
	return null;
}
/** A Listings screen job started: its ID, or how it stopped. */
function job_start( $type, array $ids, array $more = array() ) {
	$stop = job_call( 'ajax_start_job', array_merge( array( 'type' => $type, 'product_ids' => $ids ), $more ) );
	return ( $stop && 'json_ok' === $stop->kind ) ? (string) $stop->data['job_id'] : $stop;
}
/** One batch of a job: its answer. */
function job_batch( $job_id ) {
	$stop = job_call( 'ajax_process_batch', array( 'job_id' => $job_id ) );
	return ( $stop && 'json_ok' === $stop->kind ) ? $stop->data : $stop;
}
/** A job run to its end, as the Listings screen's progress window runs it: its last answer, and the batches. */
function by_job( array $ids, $choice, array $more = array() ) {
	$job = job_start( 'bulk_follow_' . $choice, $ids, $more );
	if ( ! is_string( $job ) ) {
		return array( $job, 0 );
	}
	$n = 0;
	do {
		$answer = job_batch( $job );
		$n++;
	} while ( is_array( $answer ) && 'running' === $answer['status'] && $n < 1000 );
	return array( $answer, $n );
}
/** The Listings page as WordPress would draw it for $get. */
function listings_page( array $get ) {
	$_GET = $get;
	ob_start();
	include $GLOBALS['ROOT'] . '/admin/views/listings.php';
	$_GET = array();
	return (string) ob_get_clean();
}
/** Its rows: product ID => the Origin cell's two lines. */
function origin_cells( $page ) {
	$out = array();
	preg_match_all( '#<tr>\s*<th scope="row" class="check-column"[^>]*><input type="checkbox" class="tc-listing-cb" value="(\d+)" /></th>(.*?)</tr>#s', $page, $rows, PREG_SET_ORDER );
	foreach ( $rows as $row ) {
		$cell = preg_match( '#<td class="column-origin">(.*?)</td>#s', $row[2], $m ) ? $m[1] : '';
		$out[ (int) $row[1] ] = trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( str_replace( '<br>', ' / ', $cell ) ), ENT_QUOTES ) ) );
	}
	return $out;
}
/** The listings a filter shows, from the page query, the count and "everything matching". */
function filtered( $filter, array $more = array() ) {
	$r     = TCGiant_Sync_DB::query( array_merge( array( 'origin' => $filter, 'per_page' => 500, 'orderby' => 'product_id', 'order' => 'ASC' ), $more ) );
	$rows  = array_map( 'intval', wp_list_pluck( $r['items'], 'product_id' ) );
	$found = TCGiant_Sync_DB::find_product_ids( array_merge( array( 'origin' => $filter ), $more ) );
	$count = TCGiant_Sync_DB::count( array_merge( array( 'origin' => $filter ), $more ) );
	return ( $rows === $found && count( $rows ) === $count && (int) $r['total'] === $count ) ? $rows : array( 'disagree', $rows, $found, $count, (int) $r['total'] );
}
/** The upgrade notice on a screen. */
function upgrade_notice( $screen = 'tcgiant-sync_page_tcgiant-listings' ) {
	$GLOBALS['SCREEN'] = $screen;
	ob_start();
	admin()->origin_upgrade_notice();
	return (string) ob_get_clean();
}
/** The queries that counted listings since $from. */
function count_queries( $from ) {
	return count( array_filter( array_slice( $GLOBALS['wpdb']->queries, $from ), function ( $q ) { return false !== strpos( $q, 'SELECT COUNT(*)' ) && false !== strpos( $q, '_ebay_export_last_pushed' ); } ) );
}
/** A fresh shop. */
function fresh_shop() {
	global $wpdb;
	$wpdb->reset();
	sold_forget_tables();
	$GLOBALS['OPTIONS']    = array();
	$GLOBALS['TRANSIENTS'] = array();
	$GLOBALS['CAN']        = array();
	$GLOBALS['DENY_POSTS'] = array();
	$GLOBALS['AJAX_OK']    = true;
	$GLOBALS['EVENTS']     = array();
}
/** How many job records the runner keeps. */
function jobs_kept() {
	return count( (array) get_option( TCGiant_Sync_Jobs::OPTION_KEY, array() ) );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEACH BULK ACTION SAVES THROUGH THE EBAY TAB'S BOX, AS THE SHOP'S OWN CHOICE\n" . str_repeat( '=', 140 ) . "\n";

fresh_shop();
// Imported, pushed, and not known yet (pushed before 3.28.8, nothing to tell by).
linked( 301, array( '_ebay_origin' => 'import', '_ebay_origin_by' => 'import' ) );
linked( 302, array( '_ebay_origin' => 'push', '_ebay_origin_by' => 'push' ) );
linked( 303, array( '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-06-01 10:00:00' ) );
check( 'the three: imported, pushed, not known yet', array( TCGiant_Sync_Ebay_Live::origin( 301 ), TCGiant_Sync_Ebay_Live::origin( 302 ), TCGiant_Sync_Ebay_Live::origin( 303 ) ), array( 'import', 'push', 'unknown' ) );

$box_of  = array( 'send' => true, 'keep' => false );
$matrix  = array();
$box_say = array();
foreach ( array( 301, 302, 303 ) as $id ) {
	foreach ( array( '', '1', '0' ) as $was ) {
		foreach ( array( 'send', 'keep' ) as $choice ) {
			start( $id, $was );
			$box_say[ "$id $was $choice" ] = box( $id, $box_of[ $choice ] );
		}
	}
}
// What the box stores, from each start, saved as every save posts it: left as
// the tab showed it, nothing changes, a choice of the shop's included; ticked
// or unticked, none where it agrees with who made the listing, and for one
// not known yet always 1 or 0.
$want = array(
	'301  send' => '1', '301  keep' => '',  '301 1 send' => '1', '301 1 keep' => '',  '301 0 send' => '1', '301 0 keep' => '0',
	'302  send' => '',  '302  keep' => '0', '302 1 send' => '1', '302 1 keep' => '0', '302 0 send' => '',  '302 0 keep' => '0',
	'303  send' => '1', '303  keep' => '',  '303 1 send' => '1', '303 1 keep' => '0', '303 0 send' => '1', '303 0 keep' => '0',
);
check( 'the box: as shown, nothing changes; changed, none where it agrees with the origin, else 1 or 0, always for not known yet', $box_say, $want );
$old_page = array();
foreach ( array( 301, 302, 303 ) as $id ) {
	foreach ( array( '', '1', '0' ) as $was ) {
		foreach ( array( 'send', 'keep' ) as $choice ) {
			start( $id, $was );
			$old_page[ "$id $was $choice" ] = box( $id, $box_of[ $choice ], false );
		}
	}
}
check( '  a page that does not post what it showed: read as it shows now, the same', $old_page, $want );
// Shown, then a bulk Keep from another tab, then saved as it was shown: the Keep stays.
start( 302, '' );
TCGiant_Sync_Admin::set_follow_choice( 302, 'keep' );
check( '  a choice made after the tab was drawn is not undone by saving it as drawn', box( 302, true, '1' ), '0' );

$paths = array(
	'set_follow_choice()' => function ( $id, $choice ) { TCGiant_Sync_Admin::set_follow_choice( $id, $choice ); },
	'the products list'   => function ( $id, $choice ) { by_list( array( $id ), $choice ); },
	'the Listings screen' => function ( $id, $choice ) { by_job( array( $id ), $choice ); },
);
// The bulk actions: the choice itself, whoever made the listing.
$bulk_want = array();
foreach ( array( 301, 302, 303 ) as $id ) {
	foreach ( array( '', '1', '0' ) as $was ) {
		$bulk_want[ "$id $was send" ] = '1';
		$bulk_want[ "$id $was keep" ] = '0';
	}
}
foreach ( $paths as $name => $run ) {
	$got   = array();
	$clear = array();
	foreach ( array( 301, 302, 303 ) as $id ) {
		foreach ( array( '', '1', '0' ) as $was ) {
			foreach ( array( 'send', 'keep' ) as $choice ) {
				start( $id, $was );
				$run( $id, $choice );
				$got[ "$id $was $choice" ] = follow( $id );
			}
			start( $id, $was );
			$run( $id, 'origin' );
			$clear[ "$id $was" ] = array( follow( $id ), follow_rows( $id ) );
		}
	}
	check( $name . ': Send stores 1 and Keep 0, whoever made the listing, from every start', $got, $bulk_want );
	check( '  Decide by who made the listing clears the choice, leaving no row', array_values( array_unique( array_map( 'json_encode', $clear ) ) ), array( '["",0]' ) );
}

// Sets, overrides and clears, said.
start( 301, '' );
check( 'an imported product, Send: sets 1', array( TCGiant_Sync_Admin::set_follow_choice( 301, 'send' ), follow( 301 ) ), array( 'changed', '1' ) );
check( '  Send again: already so', array( TCGiant_Sync_Admin::set_follow_choice( 301, 'send' ), follow( 301 ) ), array( 'same', '1' ) );
check( '  Keep: overridden to 0, though its origin keeps eBay\'s values anyway', array( TCGiant_Sync_Admin::set_follow_choice( 301, 'keep' ), follow( 301 ) ), array( 'changed', '0' ) );
start( 302, '' );
check( 'a pushed product, Keep: sets 0', array( TCGiant_Sync_Admin::set_follow_choice( 302, 'keep' ), follow( 302 ) ), array( 'changed', '0' ) );
check( '  Send over it: overridden to 1, though its origin sends the defaults anyway', array( TCGiant_Sync_Admin::set_follow_choice( 302, 'send' ), follow( 302 ), follow_rows( 302 ) ), array( 'changed', '1', 1 ) );
check( '  Send again: already so', array( TCGiant_Sync_Admin::set_follow_choice( 302, 'send' ), follow_rows( 302 ) ), array( 'same', 1 ) );
start( 302, '0' );
check( '  Decide by who made the listing: cleared', array( TCGiant_Sync_Admin::set_follow_choice( 302, 'origin' ), follow( 302 ), follow_rows( 302 ) ), array( 'changed', '', 0 ) );
check( '  again: already so, and no row written', array( TCGiant_Sync_Admin::set_follow_choice( 302, 'origin' ), follow_rows( 302 ) ), array( 'same', 0 ) );
start( 303, '' );
check( 'not known yet, Send: sets 1', array( TCGiant_Sync_Admin::set_follow_choice( 303, 'send' ), follow( 303 ) ), array( 'changed', '1' ) );
check( '  Keep: sets 0 - where the box would store none', array( TCGiant_Sync_Admin::set_follow_choice( 303, 'keep' ), follow( 303 ) ), array( 'changed', '0' ) );
// Already so: nothing is written, nor deleted.
start( 301, '1' );
start( 302, '0' );
start( 303, '' );
$GLOBALS['EVENTS'] = array();
TCGiant_Sync_Admin::set_follow_choice( 301, 'send' );
TCGiant_Sync_Admin::set_follow_choice( 302, 'keep' );
TCGiant_Sync_Admin::set_follow_choice( 303, 'origin' );
check( 'already so: nothing written or deleted', array_values( preg_grep( '/' . FOLLOW . '/', $GLOBALS['EVENTS'] ) ), array() );
TCGiant_Sync_Admin::set_follow_choice( 301, 'send' );
TCGiant_Sync_Admin::set_follow_choice( 302, 'keep' );
TCGiant_Sync_Admin::set_follow_choice( 303, 'keep' );
check( 'and follows_defaults() reads what was saved: imported sends, pushed keeps, not known yet keeps', array( TCGiant_Sync_Ebay_Live::follows_defaults( 301 ), TCGiant_Sync_Ebay_Live::follows_defaults( 302 ), TCGiant_Sync_Ebay_Live::follows_defaults( 303 ) ), array( true, false, false ) );

// A bulk Keep is the shop's own: when the next Update's read of a listing
// not known yet finds it pushed from here, it still keeps eBay's values.
// The box left unticked stores nothing, and then goes by what the read found.
linked( 305, array( '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-06-01 10:00:00' ) );
linked( 306, array( '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-06-01 10:00:00' ) );
$DATES[305] = '2026-05-01 08:00:00';
$DATES[306] = '2026-05-01 08:00:00';
check( 'two products pushed before 3.28.8, not known yet', array( TCGiant_Sync_Ebay_Live::origin( 305 ), TCGiant_Sync_Ebay_Live::origin( 306 ) ), array( 'unknown', 'unknown' ) );
TCGiant_Sync_Admin::set_follow_choice( 305, 'keep' );
box( 306, false );
check( '  a bulk Keep on one stores 0; the box left unticked on the other stores none', array( follow( 305 ), follow( 306 ) ), array( '0', '' ) );
$read = function ( $id ) {
	return array( 'ItemID' => '7' . $id, 'ListingDetails' => array( 'StartTime' => '2026-06-01T09:58:00.000Z' ) );
};
$told_305 = null;
$told_306 = null;
check( '  their next Update reads each listing: pushed from here, by its date', array( TCGiant_Sync_Ebay_Live::learn_origin( 305, $read( 305 ), $told_305 ), $told_305, TCGiant_Sync_Ebay_Live::learn_origin( 306, $read( 306 ), $told_306 ), $told_306 ), array( 'push', true, 'push', true ) );
check( '  the bulk Keep still keeps eBay\'s values; the box\'s none now sends the shop defaults', array( TCGiant_Sync_Ebay_Live::follows_defaults( 305 ), TCGiant_Sync_Ebay_Live::follows_defaults( 306 ) ), array( false, true ) );
check( '  and the Origin column says why', array( TCGiant_Sync_Ebay_Live::follow_state( 305 ), TCGiant_Sync_Ebay_Live::follow_state( 306 ) ), array( array( 'origin' => 'push', 'follows' => false, 'own' => true ), array( 'origin' => 'push', 'follows' => true, 'own' => false ) ) );

// The review's case: a bulk Keep, then the product saved with its tab as it
// shows it - a price changed, say - then the read.
linked( 307, array( '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-06-01 10:00:00' ) );
$DATES[307] = '2026-05-01 08:00:00';
TCGiant_Sync_Admin::set_follow_choice( 307, 'keep' );
check( 'a bulk Keep on a product not known yet, then its tab saved as it shows it: still 0', array( box( 307, false ), follow( 307 ) ), array( '0', '0' ) );
$told_307 = null;
check( '  its next Update reads the listing, pushed from here: it still keeps eBay\'s values', array( TCGiant_Sync_Ebay_Live::learn_origin( 307, $read( 307 ), $told_307 ), TCGiant_Sync_Ebay_Live::follows_defaults( 307 ) ), array( 'push', false ) );
check( '  until the shop ticks the box: it goes by who made it now, the shop defaults', array( box( 307, true ), follow_rows( 307 ), TCGiant_Sync_Ebay_Live::follows_defaults( 307 ) ), array( '', 0, true ) );
// The box changed by the shop on a listing not known yet: kept as 1 or 0.
linked( 308, array( '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-06-01 10:00:00' ) );
$DATES[308] = '2026-05-01 08:00:00';
check( 'the box ticked on a product not known yet: 1', box( 308, true ), '1' );
check( '  unticked again: 0, not none, as who made it is not known', box( 308, false ), '0' );
check( '  saved again as it shows: 0', box( 308, false ), '0' );
$told_308 = null;
check( '  its read finds it pushed: it still keeps eBay\'s values', array( TCGiant_Sync_Ebay_Live::learn_origin( 308, $read( 308 ), $told_308 ), TCGiant_Sync_Ebay_Live::follows_defaults( 308 ) ), array( 'push', false ) );
// A shown state that is not one: read as the tab shows it now.
linked( 309, array( '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-06-01 10:00:00' ) );
check( 'a shown state that is not 1 or 0 is no shown state: the box unticked as it shows, nothing written', array( box( 309, false, 'x' ), follow_rows( 309 ) ), array( '', 0 ) );
// A bulk Send on a pushed product, then its tab saved as shown: kept.
start( 302, '' );
TCGiant_Sync_Admin::set_follow_choice( 302, 'send' );
check( 'a bulk Send on a pushed product, then its tab saved as shown: still 1', box( 302, true ), '1' );
check( '  unticked by the shop: 0', box( 302, false ), '0' );

// The box itself, as 3.28.8 saved it, but no row for no choice.
start( 301, '' );
start( 302, '' );
check( 'the box left as it was on a pushed product writes no row', array( box( 302, true ), follow_rows( 302 ) ), array( '', 0 ) );
check( '  unticked: 0', box( 302, false ), '0' );
check( '  ticked again: the row goes', array( box( 302, true ), follow_rows( 302 ) ), array( '', 0 ) );
$_POST = array();
admin()->save_product_export_meta( 301 );
check( 'a save without the box on screen changes nothing', follow( 301 ), '' );

// No listing: the box is not shown, and the bulk actions leave it alone.
sold_product( 304, array(), array() );
check( 'a product with no listing: left alone by all three', array( TCGiant_Sync_Admin::set_follow_choice( 304, 'send' ), TCGiant_Sync_Admin::set_follow_choice( 304, 'keep' ), TCGiant_Sync_Admin::set_follow_choice( 304, 'origin' ), follow_rows( 304 ) ), array( 'unlinked', 'unlinked', 'unlinked', 0 ) );
update_post_meta( 304, FOLLOW, '0' );
check( '  even one with a choice left on it', array( TCGiant_Sync_Admin::set_follow_choice( 304, 'origin' ), follow( 304 ) ), array( 'unlinked', '0' ) );
delete_post_meta( 304, FOLLOW );
check( 'any other choice: nothing done', array( TCGiant_Sync_Admin::set_follow_choice( 301, 'yes' ), TCGiant_Sync_Admin::set_follow_choice( 301, '' ), follow( 301 ) ), array( 'invalid', 'invalid', '' ) );
check( '  nor for a list', array( TCGiant_Sync_Admin::apply_follow_choice( array( 301, 302 ), 'tcgiant_follow_send', 10 ), follow( 301 ) ), array( array( 'changed' => 0, 'same' => 0, 'unlinked' => 0, 'denied' => 0, 'left' => 0 ), '' ) );
check( 'product 0: denied', TCGiant_Sync_Admin::set_follow_choice( 0, 'send' ), 'denied' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA NONCE OR CAPABILITY THAT FAILS CHANGES NOTHING\n" . str_repeat( '=', 140 ) . "\n";

start( 301, '' );
start( 302, '' );
$back = by_list( array( 301, 302 ), 'send', 'nonce-other' );
check( 'the products list, a wrong nonce: nothing changed or kept, the address as it was', array( follow( 301 ), said(), $back ), array( '', false, 'https://shop.example/wp-admin/edit.php?post_type=product' ) );
$back = by_list( array( 301 ), 'send', '' );
check( '  no nonce: the same', array( follow( 301 ), said(), $back ), array( '', false, 'https://shop.example/wp-admin/edit.php?post_type=product' ) );
$CAN = array( 'edit_products' => false );
$back = by_list( array( 301 ), 'send' );
check( '  a user who may not edit products: the same', array( follow( 301 ), said(), $back ), array( '', false, 'https://shop.example/wp-admin/edit.php?post_type=product' ) );
$CAN        = array();
$DENY_POSTS = array( 301 );
$back       = by_list( array( 301, 302 ), 'keep' );
check( '  a product the user may not edit is left, and counted', array( follow( 301 ), follow( 302 ), said()['denied'] ?? null, said()['changed'] ?? null ), array( '', '0', 1, 1 ) );
$DENY_POSTS = array();
start( 302, '' );
$_REQUEST = array( '_wpnonce' => 'nonce-bulk-posts' );
delete_transient( TCGiant_Sync_Admin::FOLLOW_SAID . get_current_user_id() );
check( 'another bulk action passes through untouched, with the list\'s good nonce: nothing changed or kept', array( admin()->handle_follow_bulk_action( 'edit.php', 'tcgiant_push_to_ebay', array( 301 ) ), follow( 301 ), said() ), array( 'edit.php', '', false ) );
check( '  as does none', admin()->handle_follow_bulk_action( 'edit.php', '', array( 301 ) ), 'edit.php' );
$_REQUEST = array();

// The Listings screen: its job runner's nonce and manage_options, at the start
// and at every batch.
delete_option( TCGiant_Sync_Jobs::OPTION_KEY );
$AJAX_OK = false;
$stop    = job_start( 'bulk_follow_send', array( 301 ) );
check( 'the Listings screen, a wrong nonce: stopped, no job', array( $stop instanceof Harness_Stop ? $stop->kind : $stop, jobs_kept(), follow( 301 ) ), array( 'died', 0, '' ) );
$AJAX_OK = true;
$CAN     = array( 'manage_options' => false );
$stop    = job_start( 'bulk_follow_send', array( 301 ) );
check( '  without manage_options: refused, no job', array( $stop->kind, $stop->data['message'], jobs_kept() ), array( 'json_error', 'Unauthorized.', 0 ) );
$CAN = array();
$job = job_start( 'bulk_follow_send', array( 301 ) );
$AJAX_OK = false;
$stop    = job_batch( $job );
check( '  a batch with a wrong nonce: stopped, nothing changed', array( $stop instanceof Harness_Stop ? $stop->kind : $stop, follow( 301 ) ), array( 'died', '' ) );
$AJAX_OK = true;
$CAN     = array( 'manage_options' => false );
$stop    = job_batch( $job );
check( '  a batch without manage_options: refused, nothing changed', array( $stop->kind, follow( 301 ) ), array( 'json_error', '' ) );
$CAN        = array();
$DENY_POSTS = array( 301 );
$answer     = job_batch( $job );
check( '  a product the user may not edit: left, counted, and said', array( follow( 301 ), $answer['failed'], $answer['succeeded'], $answer['errors'] ), array( '', 1, 0, array( '#301: not changed - you cannot edit this product.' ) ) );
$DENY_POSTS = array();
start( 301, '' );
start( 302, '1' );
list( $answer ) = by_job( array( 301, 302, 304 ), 'send' );
check( '  a job: what changed, what already did, what has no listing', array( $answer['succeeded'], $answer['kept'], $answer['failed'], $answer['type'], $answer['errors'] ), array( 1, 1, 1, 'bulk_follow_send', array( '#304: no eBay listing, so there is nothing to choose for it.' ) ) );
check( '  its type is a Listings screen job, for "everything matching"', array( in_array( 'bulk_follow_send', TCGiant_Sync_Jobs::LISTING_JOB_TYPES, true ), in_array( 'bulk_follow_keep', TCGiant_Sync_Jobs::LISTING_JOB_TYPES, true ), in_array( 'bulk_follow_origin', TCGiant_Sync_Jobs::LISTING_JOB_TYPES, true ) ), array( true, true, true ) );
$stop = job_start( 'bulk_follow_maybe', array( 301 ) );
check( '  an unknown job type is refused', array( $stop->kind, $stop->data['message'] ), array( 'json_error', 'Invalid job type.' ) );

// The notice's dismiss link: its nonce and manage_options.
$OPTIONS[ TCGiant_Sync_Ebay_Live::SWEEP_OPTION ] = array( 'done' => 1, 'import' => 2, 'push' => 1, 'unknown' => 0 );
$_REQUEST = array( '_wpnonce' => 'nonce-other' );
try {
	admin()->handle_dismiss_origin_notice();
	$stop = null;
} catch ( Harness_Stop $s ) {
	$stop = $s;
}
check( 'the notice\'s dismiss, a wrong nonce: stopped, not dismissed', array( $stop ? $stop->kind : null, get_option( TCGiant_Sync_Ebay_Live::NOTICE_OPTION, '' ) ), array( 'died', '' ) );
$_REQUEST = array( '_wpnonce' => 'nonce-tcgiant_dismiss_origin_notice' );
$CAN      = array( 'manage_options' => false );
try {
	admin()->handle_dismiss_origin_notice();
	$stop = null;
} catch ( Harness_Stop $s ) {
	$stop = $s;
}
check( '  without manage_options: refused, not dismissed', array( $stop ? $stop->kind : null, get_option( TCGiant_Sync_Ebay_Live::NOTICE_OPTION, '' ) ), array( 'died', '' ) );
$CAN      = array();
$_REQUEST = array();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PRODUCTS LIST: AT MOST 500 A REQUEST, AND A NOTICE WITH THE COUNTS\n" . str_repeat( '=', 140 ) . "\n";

fresh_shop();
$wpdb->db->exec( 'BEGIN' );
for ( $i = 1; $i <= 5000; $i++ ) {
	linked( 10000 + $i, array( '_ebay_origin' => 'import', '_ebay_origin_by' => 'sweep', '_ebay_export_last_pushed' => '2026-05-01 10:00:00' ) );
}
$wpdb->db->exec( 'COMMIT' );
$all = range( 10001, 15000 );
check( 'the cap is 500 a request', TCGiant_Sync_Admin::FOLLOW_REQUEST_CAP, 500 );
$back   = by_list( array_merge( $all, array( 10001, 10002 ) ), 'send' );
$said   = said();
check( '5,000 chosen (two twice): 500 changed, 4,500 not looked at, and nothing in the address', array( $said['changed'], $said['left'], $said['choice'], $back ), array( 500, 4500, 'send', 'https://shop.example/wp-admin/edit.php?post_type=product' ) );
check( '  the first 500 in the order chosen, and not the 501st', array( follow( 10001 ), follow( 10500 ), follow( 10501 ), follow( 15000 ) ), array( '1', '1', '', '' ) );
$stored = (int) $wpdb->db->querySingle( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '" . FOLLOW . "'" );
check( '  500 rows written, no more', $stored, 500 );
check( '  kept for this user alone', array( is_array( $said ), ( function () { $GLOBALS['USER_ID'] = 8; $other = said(); $GLOBALS['USER_ID'] = 7; return $other; } )() ), array( true, false ) );
check( '  not shown on another screen, and kept for the products list', array( list_notice( 'dashboard' ), is_array( said() ) ), array( '', true ) );
$notice = list_notice();
check( 'the notice: how many now send the defaults', false !== strpos( $notice, '500 products now send your shop defaults to eBay at their next Update.' ), true );
check( '  shown once', array( said(), list_notice() ), array( false, '' ) );
check( '  how many were not looked at, and where to do any number', array( false !== strpos( $notice, '4500 were not looked at: at most 500 products are changed at a time here.' ), false !== strpos( $notice, 'TCGiant Sync, Listings, which works through any number' ), false !== strpos( $notice, 'notice-warning' ) ), array( true, true, true ) );
check( '  and that nothing went to eBay', false !== strpos( $notice, 'Nothing has been sent to eBay: each listing gets it at its next Update.' ), true );

/** The products list's record, as the action leaves it, for these counts. */
function said_as( array $said ) {
	set_transient( TCGiant_Sync_Admin::FOLLOW_SAID . get_current_user_id(), $said + array( 'changed' => 0, 'same' => 0, 'unlinked' => 0, 'denied' => 0, 'left' => 0 ), 300 );
}
by_list( range( 10001, 10010 ), 'send' );
$said = said();
said_as( array( 'unlinked' => 2 ) + $said );
$notice = list_notice();
check( 'all ten already did: said so, in green', array( $said['changed'], $said['same'], false !== strpos( $notice, '0 products now send' ), false !== strpos( $notice, '10 already did.' ), false !== strpos( $notice, '2 have no eBay listing, so there is nothing to choose for them.' ), false !== strpos( $notice, 'notice-success' ) ), array( 0, 10, true, true, true, true ) );
foreach ( array( 'keep' => '1 product now keeps its listing\'s own values on eBay at its next Update.', 'origin' => '1 product now goes by who made its listing.' ) as $choice => $words ) {
	said_as( array( 'choice' => $choice, 'changed' => 1 ) );
	check( '  ' . $choice . ': ' . $words, false !== strpos( list_notice(), $words ), true );
}
said_as( array( 'choice' => 'send', 'changed' => 3, 'denied' => 2 ) );
$notice = list_notice();
check( 'some the user may not edit: said, in amber, and nothing said of what did not happen', array( false !== strpos( $notice, '3 products now send' ), false !== strpos( $notice, '2 were not changed: you cannot edit them.' ), false !== strpos( $notice, 'notice-warning' ), false === strpos( $notice, 'already did' ), false === strpos( $notice, 'no eBay listing' ), false === strpos( $notice, 'not looked at' ) ), array( true, true, true, true, true, true ) );
said_as( array( 'choice' => 'something', 'changed' => 1 ) );
check( 'no notice for a choice it does not know', list_notice(), '' );
said_as( array( 'choice' => 'send', 'changed' => 1 ) );
$CAN = array( 'edit_products' => false );
check( '  nor for a user who may not edit products', list_notice(), '' );
$CAN = array();
delete_transient( TCGiant_Sync_Admin::FOLLOW_SAID . get_current_user_id() );
$_GET     = array( 'tcgiant_follow' => 'send', 'tcgiant_follow_changed' => '900' );
$_REQUEST = $_GET;
check( '  nor from an address that says so', list_notice(), '' );
$_GET     = array();
$_REQUEST = array();
$GLOBALS['SCREEN'] = null;
said_as( array( 'choice' => 'send', 'changed' => 1 ) );
ob_start();
admin()->follow_bulk_notice();
check( '  nor with no screen', (string) ob_get_clean(), '' );
delete_transient( TCGiant_Sync_Admin::FOLLOW_SAID . get_current_user_id() );
$GLOBALS['SCREEN'] = 'tcgiant-sync_page_tcgiant-listings';

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE LISTINGS SCREEN: ANY NUMBER, 100 A REQUEST\n" . str_repeat( '=', 140 ) . "\n";

$job = job_start( 'bulk_follow_keep', array(), array( 'select_all' => 1, 'listing_status' => '', 'listing_type' => '', 's' => '', 'listing_origin' => 'send_own' ) );
check( 'everything matching "Sends my shop defaults: its own choice": the 500 set just now', TCGiant_Sync_Jobs::get_job( $job )['total'], 500 );
$first = job_batch( $job );
check( '  the first request: 100, no more', array( $first['processed'], $first['succeeded'], $first['status'], TCGiant_Sync_Admin::FOLLOW_JOB_BATCH ), array( 100, 100, 'running', 100 ) );
check( '  the 101st not yet', array( follow( 10100 ), follow( 10101 ) ), array( '0', '1' ) );
$n = 1;
do {
	$answer = job_batch( $job );
	$n++;
} while ( 'running' === $answer['status'] && $n < 100 );
check( '  five requests in all, every one changed', array( $n, $answer['processed'], $answer['succeeded'], $answer['status'] ), array( 5, 500, 500, 'completed' ) );
check( '  each now keeps eBay\'s values by its own choice: 0, not none', array( (int) $wpdb->db->querySingle( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '" . FOLLOW . "' AND meta_value = '0'" ), (int) $wpdb->db->querySingle( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '" . FOLLOW . "'" ) ), array( 500, 500 ) );

list( $answer, $n ) = by_job( array(), 'send', array( 'select_all' => 1, 'listing_origin' => 'import_pushed' ) );
check( 'all 5,000 judged imported with a push on record: 50 requests, 5,000 changed', array( $n, $answer['processed'], $answer['succeeded'], $answer['failed'] ), array( 50, 5000, 5000, 0 ) );
check( '  each now sends the defaults', (int) $wpdb->db->querySingle( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '" . FOLLOW . "' AND meta_value = '1'" ), 5000 );
$view_src = (string) file_get_contents( $ROOT . '/admin/views/listings.php' );
check( 'the page sends its origin filter with "everything matching"', false !== strpos( $view_src, 'payload.listing_origin = <?php echo wp_json_encode( $current_origin ); ?>;' ), true );
check( '  and its progress window says what changed, and that nothing went to eBay', array( false !== strpos( $view_src, "if (followJob(d)) {" ), false !== strpos( $view_src, '%1$d changed, %2$d already did' ), false !== strpos( $view_src, "(followJob(d) ? ' ' + <?php echo wp_json_encode( __( 'Nothing has been sent to eBay: each listing gets it at its next Update.'" ) ), array( true, true, true ) );

// The products list again: the same 1,200 chosen, round after round, finish.
$twelve = range( 10501, 11700 );
$rounds = array();
for ( $round = 1; $round <= 4; $round++ ) {
	by_list( $twelve, 'keep' );
	$rounds[] = array( said()['changed'], said()['same'], said()['left'] );
}
check( '1,200 chosen on the products list, four rounds: 500, then 500 past the 500 done, then the last 200, then all done', $rounds, array( array( 500, 0, 700 ), array( 500, 500, 200 ), array( 200, 1000, 0 ), array( 0, 1200, 0 ) ) );
check( '  every one of them keeps eBay\'s values, and no other changed', array( (int) $wpdb->db->querySingle( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '" . FOLLOW . "' AND meta_value = '0'" ), follow( 10500 ), follow( 11701 ) ), array( 1200, '1', '1' ) );
check( 'nothing reached eBay or the push queue', array( $EBAY, (int) $wpdb->db->querySingle( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key IN ('_ebay_export_status', '_ebay_push_attempt')" ) ), array( array(), 0 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE ORIGIN COLUMN AND ITS FILTER\n" . str_repeat( '=', 140 ) . "\n";

fresh_shop();
$OPTIONS[ TCGiant_Sync_Ebay_Live::SWEEP_OPTION ] = array( 'done' => 1, 'after' => 999, 'import' => 4, 'push' => 1, 'unknown' => 1 );
$pushed_at = array( '_ebay_export_last_pushed' => '2026-05-01 10:00:00' );
linked( 401, array( '_ebay_origin' => 'import', '_ebay_origin_by' => 'import' ) + $pushed_at );                              // made by the import, Updated since
linked( 402, array( '_ebay_origin' => 'import', '_ebay_origin_by' => 'sweep' ) + $pushed_at );                               // judged imported by the pass, pushed once
linked( 403, array( '_ebay_origin' => 'import', '_ebay_origin_by' => 'judged', FOLLOW => '1' ) + $pushed_at );               // judged at a push, its own choice to send
linked( 404, array( '_ebay_origin' => 'import', '_ebay_origin_by' => 'sweep' ) );                                            // judged imported, never pushed
linked( 405, array( '_ebay_origin' => 'push', '_ebay_origin_by' => 'push' ) + $pushed_at );                                  // pushed
linked( 406, array( '_ebay_origin' => 'push', '_ebay_origin_by' => 'push', FOLLOW => '0' ) + $pushed_at );                   // pushed, its own choice to keep
linked( 407, array( '_ebay_export_status' => 'pushed' ) + $pushed_at );                                                      // not known yet
linked( 408, array( '_ebay_export_status' => 'pushed', FOLLOW => '1' ) + $pushed_at );                                       // not known yet, its own choice to send
linked( 409, array( '_ebay_origin' => 'import', '_ebay_origin_by' => 'sweep' ) + $pushed_at, 'trash' );                      // in the trash
linked( 410, array( '_ebay_origin' => 'import', '_ebay_origin_by' => 'link' ) + $pushed_at );                                // linked by hand
linked( 411, array( '_ebay_origin' => 'pushed', '_ebay_export_status' => 'pushed' ) + $pushed_at );                          // something not an origin kept
linked( 412, array( '_ebay_origin' => 'import' ) + $pushed_at );                                                             // the 3.28.8 preview's, no writer
linked( 413, array( '_ebay_origin' => 'import', '_ebay_origin_by' => 'sweep', FOLLOW => 'yes' ) + $pushed_at );              // a choice that is not one
// Two origin rows: the first is the one origin() reads.
linked( 414, array( '_ebay_origin_by' => 'sweep' ) + $pushed_at );
$wpdb->db->exec( "INSERT INTO wp_postmeta ( post_id, meta_key, meta_value ) VALUES ( 414, '_ebay_origin', 'import' ), ( 414, '_ebay_origin', 'push' )" );
// A listings row whose product has lost its listing.
sold_product( 415, array( '_ebay_listing_status' => 'Active' ), array( 'ebay_item_id' => '7415' ) );

$want = array(
	'import'        => array( 401, 402, 403, 404, 410, 412, 413, 414 ),
	'import_pushed' => array( 402, 403, 412, 413, 414 ),
	'import_pushed_open' => array( 402, 412, 413, 414 ),
	'push'          => array( 405, 406 ),
	'unknown'       => array( 407, 408, 411 ),
	'send_own'      => array( 403, 408 ),
	'send_origin'   => array( 405 ),
	'keep_own'      => array( 406 ),
	'keep_origin'   => array( 401, 402, 404, 407, 410, 411, 412, 413, 414 ),
);
foreach ( $want as $filter => $ids ) {
	check( 'filter ' . $filter . ': the page, its count and everything matching agree', filtered( $filter ), $ids );
}
check( 'every origin row is in exactly one "who made it" and one "what an Update sends"', array( count( array_merge( $want['import'], $want['push'], $want['unknown'] ) ), count( array_merge( $want['send_own'], $want['send_origin'], $want['keep_own'], $want['keep_origin'] ) ) ), array( 13, 13 ) );
check( 'with another filter beside it: Active and imported', filtered( 'import', array( 'status' => 'Active', 'search' => '402' ) ), array( 402 ) );
check( 'no filter, or one it does not know: every row', array( count( filtered( '' ) ), count( filtered( 'pushed_by_me' ) ) ), array( 14, 14 ) );
$twice = array();
foreach ( TCGiant_Sync_Ebay_Live::FILTERS as $filter ) {
	foreach ( array( '_ebay_origin', FOLLOW, '_ebay_item_id', '_ebay_origin_by', '_ebay_export_last_pushed' ) as $key ) {
		if ( substr_count( TCGiant_Sync_Ebay_Live::filter_sql( $filter ), "meta_key = '" . $key . "'" ) > 1 ) {
			$twice[] = $filter . ' ' . $key;
		}
	}
}
check( 'each filter reads each meta value with one subquery a row', $twice, array() );
check( 'filter_sql() has nothing for one it does not know', array( TCGiant_Sync_Ebay_Live::filter_sql( 'pushed_by_me' ), TCGiant_Sync_Ebay_Live::filter_sql( '' ) ), array( '', '' ) );
check( 'the filter reads no product', count( preg_grep( '/^meta cache /', $GLOBALS['EVENTS'] ) ), 0 );

// The column says what origin() and follows_defaults() say.
$agree = array();
foreach ( array( 401, 402, 403, 404, 405, 406, 407, 408, 410, 411, 412, 413, 414, 415 ) as $id ) {
	$state = TCGiant_Sync_Ebay_Live::follow_state( $id );
	if ( $state['origin'] !== TCGiant_Sync_Ebay_Live::origin( $id ) || $state['follows'] !== TCGiant_Sync_Ebay_Live::follows_defaults( $id ) ) {
		$agree[] = $id;
	}
}
check( 'follow_state() agrees with origin() and follows_defaults() for every row', $agree, array() );
$cells = origin_cells( listings_page( array( 'orderby' => 'product_id', 'order' => 'ASC' ) ) );
check( 'the Origin column, row by row', $cells, array(
	401 => 'Imported / Keeps eBay\'s values (by who made it)',
	402 => 'Imported / Keeps eBay\'s values (by who made it)',
	403 => 'Imported / Sends my shop defaults (its own choice)',
	404 => 'Imported / Keeps eBay\'s values (by who made it)',
	405 => 'Pushed / Sends my shop defaults (by who made it)',
	406 => 'Pushed / Keeps eBay\'s values (its own choice)',
	407 => 'Not known yet / Keeps eBay\'s values (by who made it)',
	408 => 'Not known yet / Sends my shop defaults (its own choice)',
	410 => 'Imported / Keeps eBay\'s values (by who made it)',
	411 => 'Not known yet / Keeps eBay\'s values (by who made it)',
	412 => 'Imported / Keeps eBay\'s values (by who made it)',
	413 => 'Imported / Keeps eBay\'s values (by who made it)',
	414 => 'Imported / Keeps eBay\'s values (by who made it)',
	415 => '—',
) );
$page = listings_page( array( 'listing_origin' => 'import_pushed' ) );
$shown = array_keys( origin_cells( $page ) );
sort( $shown );
check( 'filtered: the rows the filter gives, and only those', $shown, array( 402, 403, 412, 413, 414 ) );
check( '  its choice is selected, the type form keeps it in its select alone', array( 1 === preg_match( '#<option value="import_pushed"\s+selected="selected">#', $page ), substr_count( $page, 'name="listing_origin"' ) ), array( true, 2 ) );
check( '  the search form carries it', 1 === preg_match( '#<input type="hidden" name="listing_origin" value="import_pushed" />#', $page ), true );
check( '  "everything matching" sends it', false !== strpos( $page, 'payload.listing_origin = "import_pushed";' ), true );
check( '  the Origin header, and "No listings found" spans every column', array( false !== strpos( $page, '>Origin</th>' ), substr_count( $view_src, '<th scope="col"' ) + 1, false !== strpos( $view_src, '<td colspan="11">' ) ), array( true, 11, true ) );
check( '  the three bulk actions in a group of their own', 1 === preg_match( '#<optgroup label="What an Update sends eBay">\s*<option value="bulk_follow_send">Send my shop defaults to eBay on Update</option>\s*<option value="bulk_follow_keep">Keep each listing&\#039;s own eBay values on Update</option>\s*<option value="bulk_follow_origin">Decide by who made the listing</option>#', $page ), true );
check( '  nothing said of the pass, which is done', false === strpos( $page, 'tc-origin-sweeping' ), true );
$OPTIONS[ TCGiant_Sync_Ebay_Live::SWEEP_OPTION ] = array( 'after' => 200, 'import' => 4 );
check( 'while 3.28.8\'s pass is still at work, a filtered page says the ones not reached are not known yet', false !== strpos( listings_page( array( 'listing_origin' => 'unknown' ) ), 'the ones not reached yet are under Not known yet here' ), true );
check( '  an unfiltered one says nothing', false === strpos( listings_page( array() ), 'tc-origin-sweeping' ), true );
$page = listings_page( array( 'listing_origin' => 'x\'"<' ) );
check( 'a filter it does not know is no filter, and is not carried', array( count( origin_cells( $page ) ), false !== strpos( $page, 'x\'"<' ), false !== strpos( $page, 'type="hidden" name="listing_origin"' ), false !== strpos( $page, 'payload.listing_origin = "";' ) ), array( 14, false, false, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE NOTICE AFTER 3.28.8'S PASS\n" . str_repeat( '=', 140 ) . "\n";

$OPTIONS[ TCGiant_Sync_Ebay_Live::SWEEP_OPTION ] = array( 'after' => 200, 'import' => 4, 'push' => 1 );
$from = count( $wpdb->queries );
check( 'the pass not done: no notice, and nothing counted', array( upgrade_notice(), count_queries( $from ) ), array( '', 0 ) );
$OPTIONS[ TCGiant_Sync_Ebay_Live::SWEEP_OPTION ] = array( 'done' => 1, 'after' => 0 );
check( '  done with nothing judged (a shop from after 3.28.8): none', array( upgrade_notice(), count_queries( $from ) ), array( '', 0 ) );
$OPTIONS[ TCGiant_Sync_Ebay_Live::SWEEP_OPTION ] = array( 'done' => 1, 'after' => 999, 'import' => 4, 'push' => 1, 'unknown' => 1 );
$notice = upgrade_notice();
check( 'done, its counts known: the notice, counted in one query', array( '' !== $notice, count_queries( $from ) ), array( true, 1 ) );
check( '  how many - not the one with a choice of its own - as the filter for them lists them', array( false !== strpos( $notice, 'TCGiant Sync - 4 products judged imported from eBay were pushed from here before, and nothing is chosen for them' ), TCGiant_Sync_Ebay_Live::pushed_imported_count(), count( filtered( 'import_pushed_open' ) ) ), array( true, 4, 4 ) );
check( '  what it says of them is so: none has a choice, and none sends the shop defaults', array_values( array_filter( filtered( 'import_pushed_open' ), function ( $id ) { $state = TCGiant_Sync_Ebay_Live::follow_state( $id ); return $state['own'] || $state['follows']; } ) ), array() );
check( '  the pass\'s counts', false !== strpos( $notice, '4 imported from eBay, 1 pushed from here, 1 not known yet' ), true );
check( '  the filter and both bulk actions by name, and that it goes when none is left', array( false !== strpos( $notice, 'filter by &quot;Judged imported, with a push from here on record, nothing chosen yet&quot;' ), false !== strpos( $notice, 'choose the bulk action &quot;Send my shop defaults to eBay on Update&quot;, and the rest &quot;Keep each listing&#039;s own eBay values on Update&quot;' ), false !== strpos( $notice, 'this notice goes when none is left' ) ), array( true, true, true ) );
check( '  a link to the filtered Listings, and one to dismiss it with its nonce', array( false !== strpos( $notice, 'href="https://shop.example/wp-admin/admin.php?page=tcgiant-listings&listing_origin=import_pushed_open"' ), false !== strpos( $notice, 'admin-post.php?action=tcgiant_dismiss_origin_notice&_wpnonce=nonce-tcgiant_dismiss_origin_notice' ) ), array( true, true ) );
check( '  once on the page', substr_count( $notice, 'id="tc-origin-notice"' ), 1 );
$admin_src = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
check( '  hooked once', substr_count( $admin_src, "add_action( 'admin_notices', array( \$this, 'origin_upgrade_notice' ) );" ), 1 );
$from = count( $wpdb->queries );
upgrade_notice();
check( 'shown again within the hour: not counted again', count_queries( $from ), 0 );
check( 'on TCGiant Sync\'s pages only', array( upgrade_notice( 'dashboard' ), upgrade_notice( 'edit-product' ), '' !== upgrade_notice( 'toplevel_page_tcgiant-sync' ) ), array( '', '', true ) );
$CAN = array( 'manage_options' => false );
check( '  and for someone who manages the site', upgrade_notice(), '' );
$CAN = array();
$GLOBALS['SCREEN'] = null;
ob_start();
admin()->origin_upgrade_notice();
check( '  none with no screen', (string) ob_get_clean(), '' );
// The shop chooses: each choice counted at once, and the notice goes by itself.
TCGiant_Sync_Admin::apply_follow_choice( array( 402 ), 'send', 500 );
check( 'a bulk Send on one: counted again at once, three left', array( get_transient( TCGiant_Sync_Ebay_Live::NOTICE_COUNT ), TCGiant_Sync_Ebay_Live::pushed_imported_count() ), array( false, 3 ) );
check( '  the box ticked on another: two', array( box( 414, true ), get_transient( TCGiant_Sync_Ebay_Live::NOTICE_COUNT ), TCGiant_Sync_Ebay_Live::pushed_imported_count() ), array( '1', false, 2 ) );
by_job( array( 412, 413 ), 'keep' );
check( '  a Listings screen Keep on the last two: none left, and the notice gone by itself, not dismissed', array( get_transient( TCGiant_Sync_Ebay_Live::NOTICE_COUNT ), TCGiant_Sync_Ebay_Live::pushed_imported_count(), upgrade_notice(), get_option( TCGiant_Sync_Ebay_Live::NOTICE_OPTION, '' ) ), array( false, 0, '', '' ) );
TCGiant_Sync_Admin::apply_follow_choice( array( 402 ), 'origin', 500 );
check( '  a choice taken away: back, for that one', array( TCGiant_Sync_Ebay_Live::pushed_imported_count(), false !== strpos( upgrade_notice(), 'TCGiant Sync - 1 product judged imported from eBay was pushed from here before, and nothing is chosen for it' ) ), array( 1, true ) );
$from = count( $wpdb->queries );
TCGiant_Sync_Admin::apply_follow_choice( array( 402 ), 'origin', 500 );
upgrade_notice();
check( '  nothing changed: not counted again', count_queries( $from ), 0 );

$_REQUEST = array( '_wpnonce' => 'nonce-tcgiant_dismiss_origin_notice' );
try {
	admin()->handle_dismiss_origin_notice();
	$stop = null;
} catch ( Harness_Stop $s ) {
	$stop = $s;
}
$_REQUEST = array();
check( 'dismissed: back to where the shop was, kept for good', array( $stop ? $stop->kind : null, get_option( TCGiant_Sync_Ebay_Live::NOTICE_OPTION ), get_transient( TCGiant_Sync_Ebay_Live::NOTICE_COUNT ) ), array( 'redirect', 'dismissed', false ) );
$from = count( $wpdb->queries );
check( '  never shown again, nor counted', array( upgrade_notice(), upgrade_notice( 'toplevel_page_tcgiant-sync' ), count_queries( $from ) ), array( '', '', 0 ) );
check( '  whatever the pass says later', ( function () { $GLOBALS['OPTIONS'][ TCGiant_Sync_Ebay_Live::SWEEP_OPTION ]['import'] = 50; return TCGiant_Sync_Ebay_Live::pushed_imported_count(); } )(), 0 );
$OPTIONS[ TCGiant_Sync_Ebay_Live::NOTICE_OPTION ] = '';
fresh_shop();
$OPTIONS[ TCGiant_Sync_Ebay_Live::SWEEP_OPTION ] = array( 'done' => 1, 'import' => 3 );
linked( 501, array( '_ebay_origin' => 'import', '_ebay_origin_by' => 'sweep' ) );
linked( 502, array( '_ebay_origin' => 'import', '_ebay_origin_by' => 'import' ) + $pushed_at );
check( 'a shop whose products judged imported were never pushed: no notice', array( upgrade_notice(), TCGiant_Sync_Ebay_Live::pushed_imported_count() ), array( '', 0 ) );

// A site whose Listings table is missing: nothing counted, no notice, no error.
fresh_shop();
$OPTIONS[ TCGiant_Sync_Ebay_Live::SWEEP_OPTION ] = array( 'done' => 1, 'import' => 3 );
linked( 503, array( '_ebay_origin' => 'import', '_ebay_origin_by' => 'sweep' ) + $pushed_at );
$wpdb->db->exec( 'DROP TABLE wp_tcgiant_listings' );
sold_forget_tables();
check( 'no Listings table: nothing counted, no notice, no SQL error', array( TCGiant_Sync_DB::count( array( 'origin' => 'import_pushed' ) ), upgrade_notice(), $wpdb->errors ), array( 0, '', array() ) );

$uninstall = (string) file_get_contents( $ROOT . '/uninstall.php' );
check( 'uninstall deletes the notice\'s option; its count goes with the plugin\'s transients', array( false !== strpos( $uninstall, "'" . TCGiant_Sync_Ebay_Live::NOTICE_OPTION . "'," ), 0 === strpos( TCGiant_Sync_Ebay_Live::NOTICE_COUNT, 'tcgiant_' ) ), array( true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nREGISTERED, AND NOTHING SENT TO EBAY\n" . str_repeat( '=', 140 ) . "\n";

$groups = admin()->register_follow_bulk_actions( array( 'edit' => 'Edit' ) );
check( 'the products list: the three in a group of their own, WordPress\'s left', array( $groups['edit'], $groups['TCGiant Sync: what an Update sends eBay'] ?? null ), array( 'Edit', array( 'tcgiant_follow_send' => 'Send my shop defaults to eBay on Update', 'tcgiant_follow_keep' => 'Keep each listing\'s own eBay values on Update', 'tcgiant_follow_origin' => 'Decide by who made the listing' ) ) );
check( '  registered on the products list, with their handler and notice', array(
	substr_count( $admin_src, "add_filter( 'bulk_actions-edit-product', array( \$this, 'register_follow_bulk_actions' ) );" ),
	substr_count( $admin_src, "add_filter( 'handle_bulk_actions-edit-product', array( \$this, 'handle_follow_bulk_action' ), 10, 3 );" ),
	substr_count( $admin_src, "add_action( 'admin_notices', array( \$this, 'follow_bulk_notice' ) );" ),
	substr_count( $admin_src, "add_action( 'admin_post_tcgiant_dismiss_origin_notice', array( \$this, 'handle_dismiss_origin_notice' ) );" ),
), array( 1, 1, 1, 1 ) );
check( 'nothing reached eBay, the push queue or the log', array( $EBAY, TCGiant_Sync_Logger::$lines ), array( array(), array() ) );

done();

function done() {
	global $pass, $fail;
	printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
