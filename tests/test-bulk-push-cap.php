<?php
/**
 * Harness: a per-site daily cap on bulk pushes.
 *
 * Every TCGiant shop shares one eBay allowance, 50,000 calls a day, and each
 * push costs 1 to 13 of them. Until 3.27.8 only the fleet-wide stop at 80%
 * limited a bulk push, so a shop applying a new template by pushing its whole
 * catalogue could spend everyone's day. Now each site may queue so many
 * products a day through bulk pushes - 2,000 unless it says otherwise, from
 * 50 to 20,000 - counted by the same UTC day as the call counter. Past it the
 * rest are refused, with a notice saying how many were queued, how many not,
 * and that the limit resets tomorrow. A push from a product's own screen
 * does not count.
 *
 * Loaded for real: the exporter's bulk push and cap, the Listings screen's job
 * runner (its bulk Push), the products list's notice, the template settings'
 * save, and the API's call counter.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

class Harness_Stop extends Exception {
	public $kind;
	public $data;
	public function __construct( $kind, $data = null ) { parent::__construct( $kind ); $this->kind = $kind; $this->data = $data; }
}
function check_admin_referer( $action = -1, $arg = '_wpnonce' ) { return 1; }
function check_ajax_referer( $action = -1, $arg = false, $die = true ) { return 1; }
function wp_die( $message = '' ) { throw new Harness_Stop( 'died', $message ); }
function wp_safe_redirect( $url ) { throw new Harness_Stop( 'redirect', $url ); }
function wp_send_json_success( $data = null ) { throw new Harness_Stop( 'json_ok', $data ); }
function wp_send_json_error( $data = null ) { throw new Harness_Stop( 'json_error', $data ); }
function add_query_arg( $args, $url = '' ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }

// Action Scheduler, played: what was scheduled, and what was cancelled.
// 'pending' is what Action Scheduler still holds: scheduled, not cancelled.
$AS = array( 'scheduled' => array(), 'unscheduled' => 0, 'async' => array(), 'pending' => array() );
function as_schedule_single_action( $time, $hook, $args = array(), $group = '' ) { $GLOBALS['AS']['scheduled'][] = (int) $args['product_id']; $GLOBALS['AS']['pending'][] = (int) $args['product_id']; return 1; }
function as_unschedule_all_actions( $hook, $args = null, $group = '' ) { $GLOBALS['AS']['unscheduled']++; $GLOBALS['AS']['pending'] = array(); }
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { $GLOBALS['AS']['async'][] = (int) $args['product_id']; $GLOBALS['AS']['pending'][] = (int) $args['product_id']; return 1; }

class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS = array();
}

require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-jobs.php';
require dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php';
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-template-admin.php';

for ( $i = 1; $i <= 120; $i++ ) {
	new WC_Product( 1000 + $i, array( 'name' => 'P' . $i ) );
}
$ids = function ( $from, $n ) {
	return range( 1000 + $from, 1000 + $from + $n - 1 );
};
$fresh = function () {
	$GLOBALS['AS'] = array( 'scheduled' => array(), 'unscheduled' => 0, 'async' => array(), 'pending' => $GLOBALS['AS']['pending'] ?? array() );
	$GLOBALS['W']['log'] = array();
};
$x = exporter();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE CAP, IN ITS OWN OPTION\n" . str_repeat( '=', 124 ) . "\n";

check( 'unset: 2,000 a day', TCGiant_Sync_Exporter::bulk_push_cap(), 2000 );
TCGiant_Sync_Exporter::set_bulk_push_cap( 10 );
check( 'never below 50', TCGiant_Sync_Exporter::bulk_push_cap(), 50 );
TCGiant_Sync_Exporter::set_bulk_push_cap( 99999 );
check( 'never above 20,000', TCGiant_Sync_Exporter::bulk_push_cap(), 20000 );
check( '  and stored so', get_option( TCGiant_Sync_Exporter::BULK_PUSH_CAP_OPTION ), 20000 );
TCGiant_Sync_Exporter::set_bulk_push_cap( 10 );
check( 'stored at 50 when set lower', get_option( TCGiant_Sync_Exporter::BULK_PUSH_CAP_OPTION ), 50 );
update_option( TCGiant_Sync_Exporter::BULK_PUSH_CAP_OPTION, 7 );
check( 'read as 50 when the option holds less', TCGiant_Sync_Exporter::bulk_push_cap(), 50 );
update_option( TCGiant_Sync_Exporter::BULK_PUSH_CAP_OPTION, 1000000 );
check( 'read as 20,000 when it holds more', TCGiant_Sync_Exporter::bulk_push_cap(), 20000 );
update_option( TCGiant_Sync_Exporter::BULK_PUSH_CAP_OPTION, 'lots' );
check( 'nonsense stored: the default', TCGiant_Sync_Exporter::bulk_push_cap(), 2000 );
TCGiant_Sync_Exporter::set_bulk_push_cap( 60 );
check( 'kept in tcgiant_sync_bulk_push_cap, not in tcgiant_sync_ebay_settings', array( get_option( 'tcgiant_sync_bulk_push_cap' ), isset( get_option( 'tcgiant_sync_ebay_settings', array() )['bulk_push_cap'] ) ), array( 60, false ) );

$admin = new TCGiant_Sync_Template_Admin();
$_POST = array( 'tpl' => array(), 'bulk_push_cap' => '75' );
try {
	$admin->handle_settings();
} catch ( Harness_Stop $stop ) {
	unset( $stop );
}
check( 'set on the Templates settings tab', TCGiant_Sync_Exporter::bulk_push_cap(), 75 );
TCGiant_Sync_Exporter::set_bulk_push_cap( 60 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nPAST IT, THE REST ARE REFUSED\n" . str_repeat( '=', 124 ) . "\n";

$fresh();
check( 'a bulk push of 40, with 60 a day: all 40 queued', array( $x->bulk_push_products( $ids( 1, 40 ) ), $x->last_bulk_refused(), count( $GLOBALS['AS']['scheduled'] ) ), array( 40, 0, 40 ) );
check( '  and counted', TCGiant_Sync_Exporter::bulk_pushed_today(), 40 );
check( '  nothing said of a limit', count( array_filter( $GLOBALS['W']['log'], function ( $l ) { return false !== strpos( $l[1], 'bulk pushes a day' ); } ) ), 0 );

$fresh();
check( 'then 30 more: 20 queued, 10 refused', array( $x->bulk_push_products( $ids( 41, 30 ) ), $x->last_bulk_refused() ), array( 20, 10 ) );
check( '  the first 20 of them, in the order chosen', $GLOBALS['AS']['scheduled'], $ids( 41, 20 ) );
check( '  the 10 refused are not marked queued', array_values( array_filter( $ids( 61, 10 ), function ( $id ) { return '' !== (string) get_post_meta( $id, '_ebay_export_status', true ); } ) ), array() );
$said = array_values( array_filter( $GLOBALS['W']['log'], function ( $l ) { return false !== strpos( $l[1], 'bulk pushes a day' ); } ) );
check( '  the log says how many were queued and how many not, and when it resets', array( count( $said ), false !== strpos( $said[0][1] ?? '', '20 product(s) queued and 10 not' ), false !== strpos( $said[0][1] ?? '', 'resets at midnight UTC' ) ), array( 1, true, true ) );
check( '  the day\'s count is the cap', TCGiant_Sync_Exporter::bulk_pushed_today(), 60 );

$fresh();
update_post_meta( 1100, '_ebay_export_status', 'pushed' );
$x->bulk_push_products( $ids( 100, 5 ) );
check( 'with nothing left: none queued, and the queue left exactly as it was', array( $x->last_bulk_refused(), $GLOBALS['AS']['scheduled'], $GLOBALS['AS']['unscheduled'], get_post_meta( 1100, '_ebay_export_status', true ) ), array( 5, array(), 0, 'pushed' ) );

// The products list's notice.
function admin_notice( array $request ) {
	$_REQUEST = $request;
	ob_start();
	( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor()->bulk_push_admin_notice();
	return ob_get_clean();
}
$notice = admin_notice( array( 'tcgiant_pushed' => '20', 'tcgiant_not_pushed' => '10' ) );
check( 'the notice: how many queued', false !== strpos( $notice, '20 products queued for push to eBay.' ), true );
check( '  how many not, and that the limit resets tomorrow', array( false !== strpos( $notice, '10 were not' ), false !== strpos( $notice, 'resets tomorrow' ), false !== strpos( $notice, 'notice-warning' ) ), array( true, true, true ) );
check( '  with nothing refused, as it was', array( false === strpos( admin_notice( array( 'tcgiant_pushed' => '3' ) ), 'were not' ), false !== strpos( admin_notice( array( 'tcgiant_pushed' => '3' ) ), 'notice-success' ) ), array( true, true ) );

$back = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor()->handle_bulk_push_action( 'edit.php', 'tcgiant_push_to_ebay', $ids( 110, 3 ) );
check( 'the products list\'s bulk action carries what the cap refused to that notice', false !== strpos( $back, 'tcgiant_not_pushed=3' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA NEW DAY, AS THE CALL COUNTER COUNTS IT\n" . str_repeat( '=', 124 ) . "\n";

update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'count' => 60 ) );
check( 'yesterday\'s count does not hold today', TCGiant_Sync_Exporter::bulk_pushed_today(), 0 );
$fresh();
check( '  so today\'s pushes go', $x->bulk_push_products( $ids( 1, 5 ) ), 5 );
// Since 3.28.0 the call count is a row of its own per UTC day (CALL_COUNT_PREFIX).
check( 'the day is the UTC date, as the eBay call counter\'s is', array( get_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION )['day'], false !== strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-api.php' ), "self::CALL_COUNT_PREFIX . gmdate( 'Y-m-d' )" ) ), array( gmdate( 'Y-m-d' ), true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PRODUCT'S OWN PUSH DOES NOT COUNT; THE LISTINGS SCREEN'S BULK PUSH DOES\n" . str_repeat( '=', 124 ) . "\n";

$before = TCGiant_Sync_Exporter::bulk_pushed_today();
$x->push_product( 1050 );
check( 'a push from the product\'s own screen: queued, and not counted', array( $GLOBALS['AS']['async'], TCGiant_Sync_Exporter::bulk_pushed_today() ), array( array( 1050 ), $before ) );

// The Listings screen's bulk Push runs through the job queue, five a batch.
update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 57 ) );
$fresh();
$job = TCGiant_Sync_Jobs::create_job( 'bulk_push', $ids( 1, 5 ) );
TCGiant_Sync_Jobs::update_job( $job, array( 'status' => 'running' ) );
$_POST = array( 'job_id' => $job );
try {
	( new TCGiant_Sync_Jobs() )->ajax_process_batch();
} catch ( Harness_Stop $answer ) {
	$batch = $answer->data;
}
check( 'the Listings screen\'s bulk Push: 3 queued, then the cap', array( $batch['queued'], $batch['kept'], $GLOBALS['AS']['async'] ), array( 3, 2, $ids( 1, 3 ) ) );
check( '  the reply says how many the cap left out, for its progress window (push-path M1)', $batch['capped'], 2 );
$listings_js = file_get_contents( dirname( __DIR__ ) . '/admin/views/listings.php' );
check( '  which shows queued and not pushed, not "0 succeeded"', array( false !== strpos( $listings_js, "jobCounts(d)" ), false !== strpos( $listings_js, "%d not pushed (the day\\'s bulk-push limit)" ), false !== strpos( $listings_js, "var capped = d.capped || 0" ) ), array( true, true, true ) );
check( '  each refused one says why', count( array_filter( TCGiant_Sync_Jobs::get_job( $job )['errors'], function ( $e ) { return false !== strpos( $e, 'limit of 60 bulk pushes a day' ); } ) ), 2 );
check( '  and counted with the products list\'s', TCGiant_Sync_Exporter::bulk_pushed_today(), 60 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA BULK PUSH THAT REPLACES THE PUSHES STILL WAITING GIVES THEM BACK\n" . str_repeat( '=', 124 ) . "\n";

// A new bulk push cancels the pushes still waiting from the last one, as
// 3.27.7 did. Counted today and never run, they are given back first
// (push-path M2), and the notice says so. Action Scheduler's pending pushes,
// played from what was scheduled and not cancelled.
if ( ! class_exists( 'ActionScheduler_Store' ) ) {
	class TCGiant_Sync_Importer {
		const GROUP_SCAN = 'tcgiant_sync_group';
	}
	class ActionScheduler_Store {
		const STATUS_PENDING = 'pending';
		const STATUS_RUNNING = 'in-progress';
	}
	class Harness_Action {
		private $args;
		public function __construct( $args ) { $this->args = $args; }
		public function get_args() { return $this->args; }
		public function get_hook() { return TCGiant_Sync_Exporter::PUSH_ACTION; }
	}
	function as_get_scheduled_actions( $args = array(), $return = 'OBJECT' ) {
		if ( ( $args['status'] ?? '' ) !== ActionScheduler_Store::STATUS_PENDING || 'tcgiant_sync_group' !== ( $args['group'] ?? '' ) ) {
			return array();
		}
		return array_map( function ( $id ) { return new Harness_Action( array( 'product_id' => $id ) ); }, $GLOBALS['AS']['pending'] );
	}
}
TCGiant_Sync_Exporter::set_bulk_push_cap( 50 );
update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 0 ) );
$fresh();
$GLOBALS['AS']['pending'] = array();
$x->bulk_push_products( $ids( 1, 40 ) );
check( 'a cap of 50: 40 queued, still waiting', array( TCGiant_Sync_Exporter::bulk_pushed_today(), count( $GLOBALS['AS']['pending'] ) ), array( 40, 40 ) );
$fresh();
$queued = $x->bulk_push_products( $ids( 41, 20 ) );
check( 'then 20 more: all 20 queued, the 40 still waiting replaced and given back', array( $queued, $x->last_bulk_refused(), $x->last_bulk_replaced(), TCGiant_Sync_Exporter::bulk_pushed_today(), $GLOBALS['AS']['unscheduled'] ), array( 20, 0, 40, 20, 1 ) );
check( '  and the log says so', 1 === count( array_filter( $GLOBALS['W']['log'], function ( $l ) { return false !== strpos( $l[1], 'were replaced by this one' ); } ) ), true );
$back = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor()->handle_bulk_push_action( 'edit.php', 'tcgiant_push_to_ebay', $ids( 61, 3 ) );
check( '  the products list carries it to the notice', false !== strpos( $back, 'tcgiant_replaced=20' ), true );
check( '  which says so', false !== strpos( admin_notice( array( 'tcgiant_pushed' => '3', 'tcgiant_replaced' => '20' ) ), '20 pushes still waiting from your last bulk push were replaced by this one' ), true );

// Waiting from a push counted on another day: nothing to give back today.
$GLOBALS['AS']['pending'] = $ids( 61, 3 );
update_option( TCGiant_Sync_Exporter::BULK_WAITING_OPTION, array( 'day' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'ids' => $ids( 61, 3 ) ) );
$fresh();
$x->bulk_push_products( $ids( 70, 2 ) );
check( 'pushes waiting since yesterday are not given back today', array( $x->last_bulk_replaced(), TCGiant_Sync_Exporter::bulk_pushed_today() ), array( 0, 5 ) );

// Pushes waiting that no bulk push counted (a product's own push, say):
// never counted, so never given back.
update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 10 ) );
update_option( TCGiant_Sync_Exporter::BULK_WAITING_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'ids' => $ids( 80, 2 ) ) );
$GLOBALS['AS']['pending'] = array_merge( $ids( 80, 2 ), $ids( 110, 4 ) );
$fresh();
$x->bulk_push_products( $ids( 84, 1 ) );
check( 'only waiting pushes a bulk push counted are given back', array( $x->last_bulk_replaced(), TCGiant_Sync_Exporter::bulk_pushed_today() ), array( 2, 9 ) );

// Refused whole: nothing cancelled, so nothing given back.
update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 50 ) );
update_option( TCGiant_Sync_Exporter::BULK_WAITING_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'ids' => $ids( 90, 5 ) ) );
$GLOBALS['AS']['pending'] = $ids( 90, 5 );
$fresh();
$refused_all = $x->bulk_push_products( $ids( 100, 3 ) );
check( 'with 50 of 50 used and five waiting from today: queued, as the five are given back', array( $refused_all, $x->last_bulk_replaced(), TCGiant_Sync_Exporter::bulk_pushed_today() ), array( 3, 5, 48 ) );
$GLOBALS['AS']['pending'] = array();
update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 50 ) );
$fresh();
check( '  with none waiting, refused, and nothing cancelled', array( $x->bulk_push_products( $ids( 103, 2 ) ), $x->last_bulk_replaced(), $GLOBALS['AS']['unscheduled'], TCGiant_Sync_Exporter::bulk_pushed_today() ), array( 0, 0, 0, 50 ) );
check( 'a push from the Listings screen gives nothing back', TCGiant_Sync_Exporter::take_bulk_push_allowance( 1 ), 0 );

// Counted by the Listings screen's job, then cancelled by a bulk push from
// the products list: given back too (push-path round 2, P2).
TCGiant_Sync_Exporter::set_bulk_push_cap( 50 );
update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 0 ) );
delete_option( TCGiant_Sync_Exporter::BULK_WAITING_OPTION );
$GLOBALS['AS']['pending'] = array();
$fresh();
$job = TCGiant_Sync_Jobs::create_job( 'bulk_push', $ids( 1, 5 ) );
TCGiant_Sync_Jobs::update_job( $job, array( 'status' => 'running' ) );
$_POST = array( 'job_id' => $job );
try {
	( new TCGiant_Sync_Jobs() )->ajax_process_batch();
} catch ( Harness_Stop $answer ) {
	unset( $answer );
}
check( 'the Listings screen\'s job: five counted and waiting', array( TCGiant_Sync_Exporter::bulk_pushed_today(), count( $GLOBALS['AS']['pending'] ) ), array( 5, 5 ) );
$fresh();
$x->bulk_push_products( $ids( 20, 10 ) );
check( '  a bulk push from the products list replaces them and gives them back', array( $x->last_bulk_replaced(), TCGiant_Sync_Exporter::bulk_pushed_today() ), array( 5, 10 ) );

// Counted pushes that have run no longer wait: the same products pushed
// again from their own screens, never counted, are never given back.
update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 0 ) );
delete_option( TCGiant_Sync_Exporter::BULK_WAITING_OPTION );
$GLOBALS['AS']['pending'] = array();
$fresh();
$x->bulk_push_products( $ids( 1, 4 ) );
foreach ( $ids( 1, 4 ) as $ran ) {
	$x->process_single_push( $ran );
}
$GLOBALS['AS']['pending'] = $ids( 1, 4 );
$fresh();
$x->bulk_push_products( $ids( 30, 2 ) );
check( 'a counted push that has run is not given back for a later push of the product from its own screen', array( $x->last_bulk_replaced(), TCGiant_Sync_Exporter::bulk_pushed_today() ), array( 0, 6 ) );
update_option( TCGiant_Sync_Exporter::BULK_WAITING_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'ids' => $ids( 40, 2 ) ) );
$GLOBALS['AS']['pending'] = array_merge( $ids( 40, 2 ), $ids( 40, 2 ) );
$fresh();
$x->bulk_push_products( $ids( 45, 1 ) );
check( '  and a product waiting twice is given back once', $x->last_bulk_replaced(), 2 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHERE A BULK PUSH STARTS, THE DAY SO FAR\n" . str_repeat( '=', 124 ) . "\n";

TCGiant_Sync_Exporter::set_bulk_push_cap( 2000 );
update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 1250 ) );
check( '"Bulk pushes today: X of N"', TCGiant_Sync_Exporter::bulk_push_line(), 'Bulk pushes today: 1,250 of 2,000' );
$root = dirname( __DIR__ );
check( 'on the products list, the Listings screen, the Push to eBay screen, the coin update and the Templates settings', array(
	substr_count( file_get_contents( $root . '/admin/class-tcgiant-sync-admin.php' ), 'TCGiant_Sync_Exporter::bulk_push_line()' ),
	substr_count( file_get_contents( $root . '/admin/views/listings.php' ), 'TCGiant_Sync_Exporter::bulk_push_line()' ),
	substr_count( file_get_contents( $root . '/admin/views/export.php' ), 'TCGiant_Sync_Exporter::bulk_push_line()' ),
	substr_count( file_get_contents( $root . '/admin/views/template-settings.php' ), 'TCGiant_Sync_Exporter::bulk_push_line()' ),
), array( 2, 1, 1, 1 ) );

// Every call, read from PHP's own tokens so a comment naming one is not one.
$callers = array();
foreach ( array_merge( glob( $root . '/includes/*.php' ), glob( $root . '/admin/*.php' ), glob( $root . '/admin/views/*.php' ) ) as $file ) {
	$tokens = array_values( array_filter( token_get_all( file_get_contents( $file ) ), function ( $t ) { return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ); } ) );
	foreach ( $tokens as $i => $t ) {
		if ( is_array( $t ) && T_STRING === $t[0] && in_array( $t[1], array( 'bulk_push_products', 'take_bulk_push_allowance', 'push_product' ), true )
			&& '(' === ( $tokens[ $i + 1 ] ?? null ) && ! ( is_array( $tokens[ $i - 1 ] ) && T_FUNCTION === $tokens[ $i - 1 ][0] ) ) {
			$callers[ basename( $file ) . ' ' . $t[1] ] = ( $callers[ basename( $file ) . ' ' . $t[1] ] ?? 0 ) + 1;
		}
	}
}
ksort( $callers );
check( 'every bulk push comes through the cap: the products list and the coin update through bulk_push_products(), the Listings screen\'s job a product at a time; push_product() only from the product\'s own screen and that job', $callers, array(
	'class-tcgiant-sync-admin.php bulk_push_products'          => 2,
	'class-tcgiant-sync-admin.php push_product'                => 1,
	'class-tcgiant-sync-exporter.php take_bulk_push_allowance' => 1,
	'class-tcgiant-sync-jobs.php push_product'                 => 1,
	'class-tcgiant-sync-jobs.php take_bulk_push_allowance'     => 1,
) );

done();
