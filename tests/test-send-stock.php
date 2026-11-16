<?php
/**
 * Harness: "Send stock to eBay" (3.40.0), on the products list's Bulk actions
 * and the Listings page's.
 *
 * 3.39.0 stopped a stock write that leaves the stock as it was - WooCommerce's
 * own Bulk Edit writes every product's stock again - from sending it to eBay.
 * A bulk edit used to put a wrong eBay quantity right by accident. This is the
 * deliberate way: the stock each chosen product has now, queued through the
 * code a stock change queues through, so each push and every request it sends
 * is a stock change's to that figure. Only 3.39.0's unchanged-stock rule is
 * not asked; every other rule is. The click itself sends nothing to eBay.
 *
 * The world is tests/test-stock-push.php's, taken as it stands up to its
 * first case (as the stock captures take it): WordPress's own WP_Hook, with
 * the hooks kept in $wp_filter; WooCommerce's deprecated-hook shim hooked
 * from the start, as on every site; WooCommerce's stock functions with their
 * before-set-stock actions; Action Scheduler; eBay at the HTTP call. Two of
 * its stubs are replaced by WordPress's own: check_ajax_referer()
 * (wp-includes/pluggable.php, 7.1.3) and a current_user_can() that a case can
 * refuse. Played here, from WordPress 7.1.3's source:
 *
 * - wp-admin/edit.php's run of a bulk action (lines 74-229): its
 *   check_admin_referer( 'bulk-posts' ) (pluggable.php), the sendback, the
 *   post IDs from $_REQUEST['post'], apply_filters(
 *   "handle_bulk_actions-{$screen}" ) with every handler the plugin
 *   registered, the sendback cleaned, and the redirect;
 * - wp-admin/admin-ajax.php's run of an action (lines 178-211): the
 *   registered wp_ajax_{$action}, or wp_die( '0', 400 ). Its
 *   do_action( 'admin_init' ) is not run: the admin's admin_init callbacks
 *   (the OAuth callback, the settings) are not what is tested, and need a
 *   WordPress this world does not play.
 *
 * Nonces are played: wp_create_nonce() is a hash of the action and the user,
 * and wp_verify_nonce() compares with it. WordPress's str_starts_with()
 * (a PHP 8 function WordPress polyfills) is written as strpos() === 0.
 * A staging copy is a child run of this file with TCGIANT_SYNC_IS_STAGING
 * defined, as wp-config.php defines it: the constant is fixed for a process.
 *
 * @package TCGiant_Sync
 */

$STAGING_CHILD = in_array( '--staging', (array) ( $argv ?? array() ), true );
if ( $STAGING_CHILD ) {
	define( 'TCGIANT_SYNC_IS_STAGING', true );
}

// --- WordPress, as tests/test-stock-push.php's world does not play it -------

/** A wp_die(), or a die() WordPress reaches: the request ends here. */
class Harness_Died extends Exception {}

$USER_ID   = 1;
$CAN       = null; // null: every capability; else callable( $capability, ...$args ).
$SCREEN    = 'edit-product';
$REFERER   = false;
$REDIRECTS = array();

function current_user_can( $capability, ...$args ) {
	return null === $GLOBALS['CAN'] ? true : (bool) ( $GLOBALS['CAN'] )( $capability, ...$args );
}
function get_current_user_id() { return (int) $GLOBALS['USER_ID']; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function wp_create_nonce( $action = -1 ) { return substr( md5( 'harness|' . $action . '|' . get_current_user_id() ), 0, 10 ); }
function wp_verify_nonce( $nonce, $action = -1 ) {
	$nonce = (string) $nonce;
	if ( '' === $nonce ) {
		return false;
	}
	return hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
}
function _doing_it_wrong() {}
function wp_die( $message = '', $title = '', $args = array() ) { throw new Harness_Died( is_scalar( $message ) ? (string) $message : 'wp_die' ); }
function wp_nonce_ays( $action ) { wp_die( 'The link you followed has expired.', 403 ); }
function wp_doing_ajax() { return (bool) apply_filters( 'wp_doing_ajax', defined( 'DOING_AJAX' ) && DOING_AJAX ); }
function has_action( $hook_name, $callback = false ) { return has_filter( $hook_name, $callback ); }
function admin_url( $path = '' ) { return 'https://coinshop.example.com/wp-admin/' . ltrim( (string) $path, '/' ); }
function wp_get_referer() { return $GLOBALS['REFERER']; }
function wp_redirect( $location ) { $GLOBALS['REDIRECTS'][] = $location; return true; }
function get_current_screen() { return (object) array( 'id' => $GLOBALS['SCREEN'] ); }
function sanitize_text_field( $str ) { return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) ); }
function sanitize_key( $key ) { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function wp_generate_uuid4() { static $n = 0; return sprintf( '00000000-0000-4000-8000-%012d', ++$n ); }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); }
/** WordPress's remove_query_arg(), for a URL. */
function remove_query_arg( $keys, $url ) {
	if ( false === $url || '' === (string) $url ) {
		return $url;
	}
	$parts = explode( '?', (string) $url, 2 );
	if ( ! isset( $parts[1] ) ) {
		return $url;
	}
	parse_str( $parts[1], $q );
	foreach ( (array) $keys as $k ) {
		unset( $q[ $k ] );
	}
	return $parts[0] . ( $q ? '?' . http_build_query( $q ) : '' );
}
/** WordPress 7.1.3's (pluggable.php), str_starts_with() as strpos() === 0. */
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	if ( -1 === $action ) {
		_doing_it_wrong( __FUNCTION__, 'You should specify an action to be verified by using the first parameter.', '3.2.0' );
	}
	$adminurl = strtolower( admin_url() );
	$referer  = strtolower( (string) wp_get_referer() );
	$result   = isset( $_REQUEST[ $query_arg ] ) ? wp_verify_nonce( $_REQUEST[ $query_arg ], $action ) : false;
	do_action( 'check_admin_referer', $action, $result );
	if ( ! $result && ! ( -1 === $action && 0 === strpos( $referer, $adminurl ) ) ) {
		wp_nonce_ays( $action );
		die();
	}
	return $result;
}
/** WordPress 7.1.3's (pluggable.php). */
function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
	if ( -1 === $action ) {
		_doing_it_wrong( __FUNCTION__, 'You should specify an action to be verified by using the first parameter.', '4.7.0' );
	}
	$nonce = '';
	if ( $query_arg && isset( $_REQUEST[ $query_arg ] ) ) {
		$nonce = $_REQUEST[ $query_arg ];
	} elseif ( isset( $_REQUEST['_ajax_nonce'] ) ) {
		$nonce = $_REQUEST['_ajax_nonce'];
	} elseif ( isset( $_REQUEST['_wpnonce'] ) ) {
		$nonce = $_REQUEST['_wpnonce'];
	}
	$result = wp_verify_nonce( $nonce, $action );
	do_action( 'check_ajax_referer', $action, $result );
	if ( $stop && false === $result ) {
		if ( wp_doing_ajax() ) {
			wp_die( -1, 403 );
		} else {
			die( '-1' );
		}
	}
	return $result;
}

// --- The world: tests/test-stock-push.php's, up to its first case -------------

$WORLD_SRC = (string) file_get_contents( __DIR__ . '/test-stock-push.php' );
$WORLD_CUT = strpos( $WORLD_SRC, 'echo "' . chr( 92 ) . 'nTHE HOOKS' );
$WORLD_STUBS = "function check_ajax_referer() { return 1; }\nfunction current_user_can() { return true; }\n";
if ( false === $WORLD_CUT || 1 !== substr_count( $WORLD_SRC, $WORLD_STUBS ) ) {
	echo "the world of tests/test-stock-push.php is not as this harness takes it\n\n  0 passed, 1 failed\n";
	exit( 1 );
}
$WORLD_SRC = str_replace( $WORLD_STUBS, '', substr( $WORLD_SRC, 0, $WORLD_CUT ) );
$WORLD_SRC = str_replace( '__DIR__', var_export( __DIR__, true ), $WORLD_SRC );
eval( '?>' . $WORLD_SRC );
unset( $WORLD_SRC );

require $ROOT . '/includes/class-tcgiant-sync-jobs.php';

// The admin's constructor registers the Shop Categories screen too, which
// this world does not load.
class TCGiant_Sync_Shop_Categories {
	public static function register_admin() {}
}
$ADMIN = TCGiant_Sync_Admin::instance();

/** WordPress's maybe_unserialize(), for an option read from its row. */
function maybe_unserialize( $data ) {
	return is_string( $data ) && preg_match( '/^(a|O|s|i|b|d):/', $data ) ? @unserialize( $data ) : $data; // phpcs:ignore
}
/**
 * The world's database, answering from their rows as well the disconnect's
 * stamp and the day's call count, which the budget reads (the world's wpdb
 * answers only the rows its own cases read).
 */
$wpdb = new class() extends Harness_Wpdb {
	public function get_var( $query ) {
		if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '(tcgiant_sync_disconnected_at|tcgiant_sync_api_calls_\\d{4}-\\d{2}-\\d{2})' LIMIT 1$/", trim( $query ), $m ) ) {
			return isset( $GLOBALS['OPTIONS'][ $m[1] ] ) ? (string) $GLOBALS['OPTIONS'][ $m[1] ] : null;
		}
		return parent::get_var( $query );
	}
};
$JOBS  = TCGiant_Sync_Jobs::instance();

// --- Driving it -------------------------------------------------------------

/**
 * wp-admin/edit.php (WordPress 7.1.3, lines 74-229) running a bulk action on
 * the products list: the list's own nonce checked, the chosen posts, every
 * handler on handle_bulk_actions-edit-product, the redirect.
 */
function edit_php( $doaction, array $ids, $nonce = null ) {
	$_REQUEST = array(
		'post_type' => 'product',
		'action'    => $doaction,
		'post'      => array_map( 'strval', $ids ),
		'_wpnonce'  => null === $nonce ? wp_create_nonce( 'bulk-posts' ) : $nonce,
	);
	$GLOBALS['REFERER']   = 'https://coinshop.example.com/wp-admin/edit.php?post_type=product&tc_ebay_status=listed';
	$GLOBALS['SCREEN']    = 'edit-product';
	$GLOBALS['REDIRECTS'] = array();
	try {
		check_admin_referer( 'bulk-posts' );
		$sendback = remove_query_arg( array( 'trashed', 'untrashed', 'deleted', 'locked', 'ids' ), wp_get_referer() );
		if ( ! $sendback ) {
			$sendback = admin_url( 'edit.php?post_type=product' );
		}
		$sendback = add_query_arg( array( 'paged' => 1 ), $sendback );
		$post_ids = array();
		if ( ! empty( $_REQUEST['post'] ) ) {
			$post_ids = array_map( 'intval', $_REQUEST['post'] );
		}
		if ( empty( $post_ids ) ) {
			wp_redirect( $sendback );
			return array( 'redirect' => $sendback, 'died' => null );
		}
		$screen   = get_current_screen()->id;
		$sendback = apply_filters( "handle_bulk_actions-{$screen}", $sendback, $doaction, $post_ids );
		$sendback = remove_query_arg( array( 'action', 'action2', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status', 'post', 'bulk_edit', 'post_view' ), $sendback );
		wp_redirect( $sendback );
		return array( 'redirect' => $sendback, 'died' => null );
	} catch ( Harness_Died $e ) {
		return array( 'redirect' => null, 'died' => $e->getMessage() );
	}
}

/** wp-admin/admin-ajax.php (WordPress 7.1.3, lines 178-211), for a logged-in user. */
function admin_ajax( array $post ) {
	if ( ! defined( 'DOING_AJAX' ) ) {
		define( 'DOING_AJAX', true );
	}
	$_POST    = $post;
	$_REQUEST = $post;
	try {
		$action = $_REQUEST['action'];
		if ( is_user_logged_in() ) {
			if ( ! has_action( "wp_ajax_{$action}" ) ) {
				wp_die( '0', 400 );
			}
			do_action( "wp_ajax_{$action}" );
		} else {
			if ( ! has_action( "wp_ajax_nopriv_{$action}" ) ) {
				wp_die( '0', 400 );
			}
			do_action( "wp_ajax_nopriv_{$action}" );
		}
		wp_die( '0' );
	} catch ( Harness_Sent $e ) {
		return $e->payload;
	} catch ( Harness_Died $e ) {
		return array( 'died' => $e->getMessage() );
	}
	return null;
}

/**
 * The Listings page's Bulk Actions, "Send stock to eBay", Apply: the job
 * started, then each batch asked for until the job is done, as the page's
 * script does.
 */
function listings_send( array $ids, $nonce = null ) {
	$nonce = null === $nonce ? wp_create_nonce( 'tcgiant_sync_ajax' ) : $nonce;
	$start = admin_ajax( array( 'action' => 'tcgiant_job_start', 'type' => 'bulk_send_stock', 'product_ids' => array_map( 'strval', $ids ), '_ajax_nonce' => $nonce ) );
	if ( empty( $start['success'] ) ) {
		return array( 'start' => $start, 'last' => null, 'batches' => 0 );
	}
	$batches = 0;
	do {
		$last = admin_ajax( array( 'action' => 'tcgiant_job_process', 'job_id' => $start['data']['job_id'], '_ajax_nonce' => $nonce ) );
		$batches++;
	} while ( ! empty( $last['success'] ) && 'running' === $last['data']['status'] && $batches < 100 );
	return array( 'start' => $start, 'last' => $last, 'batches' => $batches );
}

/** The products list's notice after the redirect, as admin_notices draws it: its kind and its words. */
function notice_drawn() {
	ob_start();
	$GLOBALS['ADMIN']->send_stock_notice();
	$html = (string) ob_get_clean();
	return array(
		preg_match( '#class="notice ([a-z-]+)#', $html, $c ) ? $c[1] : '',
		preg_match( '#<p>(.*)</p>#s', $html, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '',
	);
}
function list_notice() { return notice_drawn()[1]; }
/** What the products list's action kept for its notice. */
function said() { return get_transient( TCGiant_Sync_Admin::SEND_STOCK_SAID . get_current_user_id() ); }
/** The action's log lines. */
function send_lines() { return array_values( array_map( function ( $l ) { return $l[1]; }, array_filter( $GLOBALS['LOG'], function ( $l ) { return 0 === strpos( $l[1], 'Send stock to eBay' ); } ) ) ); }
/** The queue's hooks: only stock pushes, or what else. */
function queued_hooks() { return array_values( array_unique( array_column( $GLOBALS['QUEUE'], 'hook' ) ) ); }
/**
 * A push's arguments but its token, which uniqid() makes, and the action's
 * own flag, 'asked' (fix round 1), which asked_flags() reads: the rest is
 * what a stock change's push carries.
 */
function push_jobs() { return array_map( function ( $a ) { $j = $a['args'][0]; unset( $j['token'], $j['asked'] ); return $j; }, pushes() ); }
/** Each queued push's 'asked' flag, or null. */
function asked_flags() { return array_map( function ( $a ) { return $a['args'][0]['asked'] ?? null; }, pushes() ); }
/** Each request as it went: the call, its body. */
function wire() { return array_map( function ( $w ) { return array( $w[0], $w[1] ); }, $GLOBALS['WIRE'] ); }

/**
 * A stock change of the product, then the queue run: what was queued and every
 * request sent. And the action on the same shop, the stock already at the
 * figure: the same.
 */
function by_change( callable $shop, $id, $stock, array $settings = array() ) {
	fresh_shop( $settings );
	$shop();
	// A real change, never a write of the figure already there (3.39.0).
	$GLOBALS['META'][ $id ]['_stock'] = (int) $stock + 1;
	wc_update_product_stock( $id, $stock, 'set' );
	$jobs  = push_jobs();
	$asked = asked_flags();
	run_queue( true, 200 );
	return array( 'jobs' => $jobs, 'asked' => $asked, 'wire' => wire() );
}
function by_action( callable $shop, array $ids, array $settings = array(), $id_to_set = null, $stock = null ) {
	fresh_shop( $settings );
	$shop();
	if ( null !== $id_to_set ) {
		// The stock already at the figure, as the shop holds it: written as
		// WooCommerce stores a set, with nothing listening.
		$GLOBALS['META'][ $id_to_set ]['_stock'] = number_format( (float) $stock, 6, '.', '' );
	}
	$GLOBALS['LAST_CLICK'] = edit_php( 'tcgiant_send_stock', $ids );
	$GLOBALS['LAST_SAID']  = said();
	$GLOBALS['CLICK_WIRE'] = wire();
	$GLOBALS['CLICK_HOOKS'] = queued_hooks();
	$jobs  = push_jobs();
	$asked = asked_flags();
	run_queue( true, 200 );
	return array( 'jobs' => $jobs, 'asked' => $asked, 'wire' => wire() );
}
function calls_of( array $run ) { return array_column( $run['wire'], 0 ); }
/** WooCommerce's Bulk Edit with Stock at No change: the figure it loaded, written back. */
function write_as_is( $id ) {
	$p = wc_get_product( $id );
	wc_update_product_stock( $p, $p->get_stock_quantity(), 'set' );
}

$NOW_SENT = function () { return array( $GLOBALS['CLICK_WIRE'], $GLOBALS['CLICK_HOOKS'] ); };

// The notice's own sentences, as send_stock_lines() words them (fix round 1).
function first_line( $n ) {
	if ( 0 === $n ) {
		return 'Send stock to eBay: nothing was queued.';
	}
	return 1 === $n
		? 'Send stock to eBay: 1 push queued. It sends a product\'s stock in the background, as a stock change would.'
		: 'Send stock to eBay: ' . $n . ' pushes queued. Each sends a product\'s stock in the background, as a stock change would.';
}
$CLOSE = 'A push eBay refuses is tried again. Once today\'s eBay calls are used up, a push above 0 waits until tomorrow.';
function today_line( $n ) { return 'Send stock to eBay today: ' . $n . ' of 200.'; }
/** The lines the products list kept, as its notice draws them. */
function notice_of( $said ) {
	set_transient( TCGiant_Sync_Admin::SEND_STOCK_SAID . get_current_user_id(), $said, 300 );
	return notice_drawn();
}
/** A job's status as the database holds it. */
function job_status( $id ) { $j = TCGiant_Sync_Jobs::get_job_fresh( $id ); return is_array( $j ) ? $j['status'] : null; }


if ( $STAGING_CHILD ) {
	goto staging;
}

/*
 * php tests/test-send-stock.php --listings-page: the Listings page's Bulk
 * Actions, its job window and its script, drawn from admin/views/listings.php
 * itself (each piece cut from the view and run as the view runs it), for
 * tools/check-listings-js.js to drive in a browser-like page.
 */
if ( in_array( '--listings-page', (array) ( $argv ?? array() ), true ) ) {
	require_once $ROOT . '/includes/class-tcgiant-sync-catalog.php';
	function esc_html_e( $text ) { echo esc_html( $text ); }
	function esc_attr_e( $text ) { echo esc_attr( $text ); }
	function esc_js( $text ) { return addslashes( (string) $text ); }
	$view   = (string) file_get_contents( $ROOT . '/admin/views/listings.php' );
	$cut    = function ( $from, $to, $inclusive ) use ( $view ) {
		$a = strpos( $view, $from );
		$b = false === $a ? false : strpos( $view, $to, $a );
		if ( false === $a || false === $b ) {
			fwrite( STDERR, 'the view has no ' . $from . "\n" );
			exit( 2 );
		}
		return substr( $view, $a, $b - $a + ( $inclusive ? strlen( $to ) : 0 ) );
	};
	$follow_labels  = TCGiant_Sync_Admin::follow_action_labels();
	$current_status = '';
	$current_type   = '';
	$search         = '';
	$current_origin = '';
	$total          = 2;
	$page = '<div class="alignleft actions">' . $cut( '<select id="tc-bulk-action">', '<?php // A bulk Push here counts', false ) . '</div>'
		. '<input type="checkbox" id="tc-select-all-matching" /><input type="checkbox" id="tc-cb-select-all" />'
		. '<input type="checkbox" class="tc-listing-cb" value="11" /><input type="checkbox" class="tc-listing-cb" value="12" />'
		. $cut( '<!-- Job Progress Modal -->', '<script>', false )
		. $cut( '<script>', '</script>', true );
	ob_start();
	eval( '?>' . $page );
	echo ob_get_clean();
	exit( 0 );
}

echo "\nTHE ACTION, WHERE IT IS\n" . str_repeat( '=', 132 ) . "\n";

$actions = apply_filters( 'bulk_actions-edit-product', array() );
$group   = 'TCGiant Sync: about one eBay call a listing';
check( 'on the products list: "Send stock to eBay", in a group whose label says what it costs', $actions[ $group ] ?? null, array( 'tcgiant_send_stock' => 'Send stock to eBay' ) );
$reg = function ( $hook, $method ) {
	foreach ( harness_listeners( $hook ) as $l ) {
		if ( is_array( $l[0] ) && $method === $l[0][1] ) {
			return array( $l[1], $l[2] );
		}
	}
	return null;
};
check( '  its handler on handle_bulk_actions-edit-product, three arguments, and its notice on admin_notices', array( $reg( 'handle_bulk_actions-edit-product', 'handle_send_stock_bulk_action' ), $reg( 'admin_notices', 'send_stock_notice' ) ), array( array( 3, 10 ), array( 1, 10 ) ) );
$view = (string) file_get_contents( $ROOT . '/admin/views/listings.php' );
check( 'on the Listings page: an option of its Bulk Actions, the same label, through the page\'s job', array( 1 === substr_count( $view, '<option value="bulk_send_stock"><?php esc_html_e( \'Send stock to eBay\', \'tcgiant-sync\' ); ?></option>' ), in_array( 'bulk_send_stock', TCGiant_Sync_Jobs::LISTING_JOB_TYPES, true ) ), array( true, true ) );
check( '  its cost and today\'s count said beside Apply when chosen, and the cost in the confirm before it runs', array( 1 === preg_match( '#<button type="button" id="tc-bulk-apply"[^\n]*\n\n[^\n]*\n\t\t\t<span id="tc-send-stock-help" #', $view ), 1 === substr_count( $view, "<?php echo esc_html( TCGiant_Sync_Admin::send_stock_cost_words() . ' ' . TCGiant_Sync_Inventory::send_stock_today_line() ); ?></span>" ), 1 === substr_count( $view, "\$('#tc-send-stock-help').toggle(\$(this).val()==='bulk_send_stock');" ), 1 === substr_count( $view, "var cost = action === 'bulk_send_stock' ? ' ' + <?php echo wp_json_encode( TCGiant_Sync_Admin::send_stock_cost_words() ); ?> : '';" ), 1 === substr_count( $view, "listing(s)?' + cost)) return;" ) ), array( true, true, true, true, true ) );
check( '  the cost, in words: about one call a listing, and the read at 0', TCGiant_Sync_Admin::send_stock_cost_words(), 'Sends each listing the stock WooCommerce has now, in the background, as a stock change would: about one eBay call a listing. At 0, eBay is read first: a listing eBay already shows at 0 is left alone; otherwise, with auto-end on, it is ended.' );

echo "\nA LINKED SIMPLE PRODUCT: THE PUSH A STOCK CHANGE TO THE SAME FIGURE QUEUES, AND ITS REQUEST, BYTE FOR BYTE\n" . str_repeat( '=', 132 ) . "\n";

$shop = function () {
	product( 6001, 4, '600100006001' );
	ebay_has( '600100006001', listing( '600100006001', 5, 0 ) );
};
$change = by_change( $shop, 6001, 3 );
$action = by_action( $shop, array( 6001 ), array(), 6001, 3 );
check( 'a stock change 4 to 3 queues one push; the action at 3 queues the same: product, reason, listing', $action['jobs'], $change['jobs'] );
check( '  as a stock change\'s: an edit push for the product\'s own listing', $action['jobs'], array( array( 'product_id' => 6001, 'reason' => 'edit', 'item' => '600100006001' ) ) );
check( '  and one key more, the action\'s own (fix round 1): asked; a stock change\'s push carries none', array( $action['asked'], $change['asked'] ), array( array( 1 ), array( null ) ) );
check( '  the request it sends: IDENTICAL, byte for byte, to the stock change\'s (ReviseInventoryStatus 3, no read)', array( $action['wire'] === $change['wire'], calls_of( $action ), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( true, array( 'ReviseInventoryStatus' ), 3 ) );
check( '  the click itself: no eBay call, no HTTP request, and nothing queued but the stock push', $NOW_SENT(), array( array(), array( 'tcgiant_sync_push_stock' ) ) );
check( '  the redirect: the list it came from, nothing added to the address', $LAST_CLICK, array( 'redirect' => 'https://coinshop.example.com/wp-admin/edit.php?post_type=product&tc_ebay_status=listed&paged=1', 'died' => null ) );
check( '  its 60-second mark and its pending mark, as a stock change sets them', array( get_transient( 'tcgiant_stock_pending_6001' ), is_array( meta( 6001, '_ebay_push_pending' ) ) ), array( false, true ) );
check( '  the log: one line, no per-product "stock changed" line', send_lines(), array( 'Send stock to eBay (products list, user #1): 1 queued (ReviseInventoryStatus 1: #6001); skipped 0.' ) );
check( '  and no "stock changed to" line, which a stock change writes', logged( 'stock changed to' ), false );
check( '  the notice: what was queued, that a push waits past the day\'s share, and today\'s count', notice_of( $LAST_SAID ), array( 'notice-success', first_line( 1 ) . ' 1 sets eBay\'s quantity to its stock. ' . $CLOSE . ' ' . today_line( 1 ) ) );
check( '  shown once', array( said(), list_notice() ), array( false, '' ) );

echo "\nUNCHANGED STOCK, EBAY ALREADY RIGHT: STILL SENT (THE POINT OF IT)\n" . str_repeat( '=', 132 ) . "\n";

$shop = function () {
	product( 6002, 3, '600200006002' );
	ebay_has( '600200006002', listing( '600200006002', 3, 0 ) );
};
fresh_shop();
$shop();
write_as_is( 6002 );
check( 'a stock save that changes nothing (3.39.0): nothing queued, as before', count( pushes() ), 0 );
$action = by_action( $shop, array( 6002 ) );
check( 'the action at the same 3: one ReviseInventoryStatus of 3, no read', array( calls_of( $action ), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'ReviseInventoryStatus' ), 3 ) );
$change = by_change( function () { product( 6002, 4, '600200006002' ); ebay_has( '600200006002', listing( '600200006002', 3, 0 ) ); }, 6002, 3 );
check( '  IDENTICAL to a real change to 3', $action['wire'], $change['wire'] );

echo "\nVARIATIONS, A VARIABLE PRODUCT, THE POOL\n" . str_repeat( '=', 132 ) . "\n";

// A variation keeping its own stock.
$shop = function () {
	variable( 6010, '601000006010' );
	variation( 6011, 6010, 'CAP-R', 3, array( 'Colour' => 'Red' ), array( '_ebay_variation_sku' => 'CAP-R' ) );
	ebay_has( '601000006010', variation_listing( '601000006010', array( ev( 'CAP-R', 3, 0, array( 'Colour' => 'Red' ) ) ) ) );
};
$change = by_change( $shop, 6011, 2 );
$action = by_action( $shop, array( 6010 ), array(), 6011, 2 );
check( 'a variation keeping its own stock, its variable product ticked: its own push, as its own change to 2', array( $action['jobs'], $action['wire'] === $change['wire'], calls_of( $action ), statuses( sent( 'ReviseInventoryStatus' ) ) ), array( $change['jobs'], true, array( 'ReviseInventoryStatus' ), array( 'CAP-R' => 2 ) ) );
check( '  the log names the variation', send_lines(), array( 'Send stock to eBay (products list, user #1): 1 queued (ReviseInventoryStatus 1: #6011); skipped 0.' ) );
$action2 = by_action( $shop, array( 6011 ), array(), 6011, 2 );
check( '  the variation\'s own ID given: the same', array( $action2['jobs'], $action2['wire'] === $change['wire'] ), array( $change['jobs'], true ) );

// Stock the parent keeps for its variations: the pool, once.
$shop = function () {
	variable( 6020, '602000006020', array( '_manage_stock' => 'yes', '_stock' => 7 ) );
	variation( 6021, 6020, 'CAP-T', null, array( 'size' => 'S' ) );
	variation( 6022, 6020, 'CAP-U', null, array( 'size' => 'M' ) );
	ebay_has( '602000006020', variation_listing( '602000006020', array( ev( 'CAP-T', 6, 0, array( 'size' => 'S' ) ), ev( 'CAP-U', 6, 0, array( 'size' => 'M' ) ) ) ), variation_listing( '602000006020', array( ev( 'CAP-T', 5, 0, array( 'size' => 'S' ) ), ev( 'CAP-U', 5, 0, array( 'size' => 'M' ) ) ) ) );
};
$change = by_change( $shop, 6020, 5 );
$action = by_action( $shop, array( 6020 ), array(), 6020, 5 );
check( 'stock the parent keeps for its variations, the variable product ticked: one pool push, IDENTICAL to a change to 5', array( $action['jobs'], $action['wire'] === $change['wire'], calls_of( $action ) ), array( $change['jobs'], true, array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ) ) );
check( '  its request: each variation taking it set to 5, in one call', statuses( sent( 'ReviseInventoryStatus' ) ), array( 'CAP-T' => 5, 'CAP-U' => 5 ) );
check( '  the log: one shared-stock push', send_lines(), array( 'Send stock to eBay (products list, user #1): 1 queued (shared stock (GetItem, ReviseInventoryStatus, GetItem) 1: #6020); skipped 0.' ) );
$action = by_action( $shop, array( 6021 ), array(), 6020, 5 );
check( 'a variation whose stock its parent keeps, given by its ID: sent as its parent, the same pool push', array( $action['jobs'], $action['wire'] === $change['wire'] ), array( $change['jobs'], true ) );
$action = by_action( $shop, array( 6020, 6021, 6022 ), array(), 6020, 5 );
check( '  the parent and both its variations given: the pool sent once, nothing said skipped', array( count( $action['jobs'] ), send_lines() ), array( 1, array( 'Send stock to eBay (products list, user #1): 1 queued (shared stock (GetItem, ReviseInventoryStatus, GetItem) 1: #6020); skipped 0.' ) ) );

// Both: a pool, and a variation keeping its own stock beside it.
$shop = function () {
	variable( 6030, '603000006030', array( '_manage_stock' => 'yes', '_stock' => 4 ) );
	variation( 6031, 6030, 'MIX-A', 2, array( 'size' => 'S' ), array( '_ebay_variation_sku' => 'MIX-A' ) );
	variation( 6032, 6030, 'MIX-B', null, array( 'size' => 'M' ) );
	ebay_has( '603000006030', variation_listing( '603000006030', array( ev( 'MIX-A', 1, 0, array( 'size' => 'S' ) ), ev( 'MIX-B', 3, 0, array( 'size' => 'M' ) ) ) ), variation_listing( '603000006030', array( ev( 'MIX-A', 1, 0, array( 'size' => 'S' ) ), ev( 'MIX-B', 4, 0, array( 'size' => 'M' ) ) ) ) );
};
$action = by_action( $shop, array( 6030 ) );
check( 'a variable product ticked, with a pool and a variation of its own: two pushes, the pool (parent) and the variation', array_column( $action['jobs'], 'product_id' ), array( 6030, 6031 ) );
check( '  the pool sends its 4 to the variation taking it, never to the one keeping its own; that one its own 2', array( array_map( 'statuses', all_sent( 'ReviseInventoryStatus' ) ) ), array( array( array( 'MIX-B' => 4 ), array( 'MIX-A' => 2 ) ) ) );
$change_pool = by_change( $shop, 6030, 4 );
$change_own  = by_change( $shop, 6031, 2 );
$action      = by_action( $shop, array( 6030 ) );
check( '  each IDENTICAL to its own stock change: the pool\'s requests, then the variation\'s', $action['wire'], array_merge( $change_pool['wire'], $change_own['wire'] ) );

// A variable product whose variations keep no stock and which keeps none.
fresh_shop();
variable( 6040, '604000006040' );
variation( 6041, 6040, 'NONE-A', null, array( 'size' => 'S' ) );
edit_php( 'tcgiant_send_stock', array( 6040 ) );
check( 'a variable product with no stock kept anywhere: its variation not sent, named, no push', array( count( pushes() ), send_lines() ), array( 0, array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (stock not managed 1: #6041).' ) ) );

// A variable product keeping a total its variations do not take (as an
// import keeps one): each variation keeping its own stock is sent, the total
// never.
fresh_shop();
variable( 6060, '606000006060', array( '_manage_stock' => 'yes', '_stock' => 9 ) );
variation( 6061, 6060, 'OWN-A', 2, array( 'size' => 'S' ), array( '_ebay_variation_sku' => 'OWN-A' ) );
variation( 6062, 6060, 'OWN-B', 3, array( 'size' => 'M' ), array( '_ebay_variation_sku' => 'OWN-B' ) );
edit_php( 'tcgiant_send_stock', array( 6060 ) );
check( 'a variable product keeping a total its variations do not take: each variation\'s own push, the total never sent', array( push_args(), send_lines(), logged( 'is a variable product whose variations keep their own stock' ) ), array( array( array( 6061, 'edit', null ), array( 6062, 'edit', null ) ), array( 'Send stock to eBay (products list, user #1): 2 queued (ReviseInventoryStatus 2: #6061, #6062); skipped 0.' ), false ) );

// A variable product not on eBay: named once, by itself.
fresh_shop();
variable( 6080, '' );
variation( 6081, 6080, 'OFF-A', 2, array( 'size' => 'S' ) );
edit_php( 'tcgiant_send_stock', array( 6080 ) );
check( 'a variable product not on eBay: named once, by its own ID', send_lines(), array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (not on eBay 1: #6080).' ) );

// A linked variable product with no variations and no stock of its own:
// said, as a stock change of it would log it.
fresh_shop();
variable( 6070, '607000006070' );
edit_php( 'tcgiant_send_stock', array( 6070 ) );
check( 'a linked variable product with nothing to send: named, the stock change\'s own warning logged', array( count( pushes() ), send_lines(), logged( 'WC #6070 is a variable product', 'warning' ) ), array( 0, array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (cannot be sent, logged 1: #6070).' ), true ) );

// Fix round 1 (M4, review 1's R7): a disabled variation is not on eBay.
$shop = function () {
	variable( 6090, '609000006090' );
	variation( 6091, 6090, 'P-A', 2, array( 'size' => 'S' ), array( '_ebay_variation_sku' => 'P-A' ) );
	variation( 6092, 6090, 'P-OFF', 5, array( 'size' => 'M' ) );
	$GLOBALS['POSTS'][6092]['status'] = 'private';
	$l = variation_listing( '609000006090', array( ev( 'P-A', 2, 0, array( 'size' => 'S' ) ) ) );
	ebay_has( '609000006090', $l, $l, $l );
};
$action = by_action( $shop, array( 6090 ) );
check( 'a disabled (private) variation keeping its own stock: not sent, named as not on eBay; the enabled one sent', array( array_column( $action['jobs'], 'product_id' ), calls_of( $action ), send_lines(), logged( 'P-OFF' ) ), array( array( 6091 ), array( 'ReviseInventoryStatus' ), array( 'Send stock to eBay (products list, user #1): 1 queued (ReviseInventoryStatus 1: #6091); skipped 1 (not on eBay 1: #6092).' ), false ) );
fresh_shop();
variable( 6095, '609500006095' );
variation( 6096, 6095, 'Q-OFF', 3, array( 'size' => 'S' ) );
$GLOBALS['POSTS'][6096]['status'] = 'private';
edit_php( 'tcgiant_send_stock', array( 6095 ) );
check( '  every variation disabled: each named, the product itself not said again', array( count( pushes() ), send_lines() ), array( 0, array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (not on eBay 1: #6096).' ) ) );
// Fix round 2 (review 2's L-2, its C9b): the parent keeps 4 for its only
// variation, which is disabled - and eBay, listing it from before, still
// shows it. The pool is not sent: it would put the disabled variation on
// sale with 4.
$shop = function () {
	variable( 6097, '609700006097', array( '_manage_stock' => 'yes', '_stock' => 4 ) );
	variation( 6098, 6097, 'PD-A', null, array( 'size' => 'S' ) );
	$GLOBALS['POSTS'][6098]['status'] = 'private';
	$l = variation_listing( '609700006097', array( ev( 'PD-A', 2, 0, array( 'size' => 'S' ) ) ) );
	ebay_has( '609700006097', $l, $l, $l );
};
$action = by_action( $shop, array( 6097 ) );
check( 'the parent keeps the stock of its only variation, which is disabled (C9b): not queued, nothing sent, the variation named as not on eBay', array( count( $action['jobs'] ), calls_of( $action ), send_lines() ), array( 0, array(), array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (not on eBay 1: #6098).' ) ) );
check( '  the notice says so', notice_of( $LAST_SAID ), array( 'notice-warning', first_line( 0 ) . ' 1 not sent: not on eBay: #6098. ' . today_line( 0 ) ) );
fresh_shop();
variable( 6085, '608500006085', array( '_manage_stock' => 'yes', '_stock' => 4 ) );
variation( 6086, 6085, 'PE-A', null, array( 'size' => 'S' ) );
variation( 6087, 6085, 'PE-OFF', null, array( 'size' => 'M' ) );
$GLOBALS['POSTS'][6087]['status'] = 'private';
edit_php( 'tcgiant_send_stock', array( 6085 ) );
check( '  an enabled variation taking it too: the pool sent once, the disabled one named', array( push_args(), send_lines() ), array( array( array( 6085, 'edit', null ) ), array( 'Send stock to eBay (products list, user #1): 1 queued (shared stock (GetItem, ReviseInventoryStatus, GetItem) 1: #6085); skipped 1 (not on eBay 1: #6087).' ) ) );

echo "\nAT 0: EBAY IS READ FIRST (FIX ROUND 1, THE OWNER'S DECISION), EACH SHAPE\n" . str_repeat( '=', 132 ) . "\n";

// A simple product, the listing known to be fixed-price, auto-end on.
$shop_up = function () {
	product( 6052, 2, '605200006052' );
	ebay_has( '605200006052', listing( '605200006052', 2, 0 ) );
};
$shop_zero = function () {
	product( 6052, 2, '605200006052' );
	ebay_has( '605200006052', listing( '605200006052', 3, 3 ) ); // eBay: 0 available, kept live by Out-of-Stock control
};
$change = by_change( $shop_up, 6052, 0 );
$action = by_action( $shop_up, array( 6052 ), array(), 6052, 0 );
check( 'a simple product at 0, eBay showing 2, auto-end on: read first, then ended, as a change to 0 ends it', array( calls_of( $action ), array_slice( $action['wire'], 1 ) === $change['wire'], meta( 6052, '_ebay_listing_status' ) ), array( array( 'GetItem', 'EndItem' ), true, 'Ended' ) );
check( '  the stock change to 0 itself: unchanged - EndItem, no read', calls_of( $change ), array( 'EndItem' ) );
check( '  named among those whose listing will be ended if eBay still shows stock; a warning', notice_of( $LAST_SAID ), array( 'notice-warning', first_line( 1 ) . ' 1 at 0: its eBay listing will be ended if eBay still shows stock (an auction with bids is left running): #6052. ' . $CLOSE . ' ' . today_line( 1 ) ) );
check( '  the log line', send_lines(), array( 'Send stock to eBay (products list, user #1): 1 queued (at 0, EndItem if eBay shows stock 1: #6052); skipped 0.' ) );
$action = by_action( $shop_zero, array( 6052 ), array(), 6052, 0 );
check( '  eBay already showing 0 (Out-of-Stock control keeps it live): read, nothing more - not ended', array( calls_of( $action ), meta( 6052, '_ebay_listing_status' ) ), array( array( 'GetItem' ), 'Active' ) );
check( '  the log says it was left as it is', logged( 'Stock push for WC #6052 (Send stock to eBay): eBay listing 605200006052 already shows 0 available, so it was left as it is.' ), true );
$change = by_change( $shop_zero, 6052, 0 );
check( '  a stock change to 0 there: as before, EndItem with no read', calls_of( $change ), array( 'EndItem' ) );

// The type not confirmed: the push reads anyway; one read, never two.
$shop_unconfirmed = function () {
	product( 6057, 2, '605700006057', array( '_ebay_listing_type' => '', '_ebay_listing_type_item' => null ) );
	ebay_has( '605700006057', listing( '605700006057', 2, 0 ) );
};
$change = by_change( $shop_unconfirmed, 6057, 0 );
$action = by_action( $shop_unconfirmed, array( 6057 ), array(), 6057, 0 );
check( 'a listing whose type is not confirmed: the push\'s own read serves - one GetItem, IDENTICAL to a change to 0', array( calls_of( $action ), $action['wire'] === $change['wire'] ), array( array( 'GetItem', 'EndItem' ), true ) );

// Auto-end off.
$change = by_change( $shop_up, 6052, 0, array( 'auto_end_zero_stock' => '0' ) );
$action = by_action( $shop_up, array( 6052 ), array( 'auto_end_zero_stock' => '0' ), 6052, 0 );
check( 'auto-end off, eBay showing 2: read first, then ReviseInventoryStatus 0, as a change to 0', array( calls_of( $action ), array_slice( $action['wire'], 1 ) === $change['wire'], calls_of( $change ), qty_sent( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), true, array( 'ReviseInventoryStatus' ), 0 ) );
check( '  said as set to 0, ended only if eBay will not take 0; a warning', notice_of( $LAST_SAID ), array( 'notice-warning', first_line( 1 ) . ' 1 at 0: eBay is set to 0 if it still shows stock. If eBay will not take 0 (Out-of-Stock control off), the listing is ended: #6052. ' . $CLOSE . ' ' . today_line( 1 ) ) );
check( '  the log line', send_lines(), array( 'Send stock to eBay (products list, user #1): 1 queued (at 0, set to 0 if eBay shows stock 1: #6052); skipped 0.' ) );
$action = by_action( $shop_zero, array( 6052 ), array( 'auto_end_zero_stock' => '0' ), 6052, 0 );
check( '  eBay already at 0: read, nothing more', calls_of( $action ), array( 'GetItem' ) );
// Review 1's R6: eBay will not take 0 (Out-of-Stock control off).
$shop_refuse = function () {
	product( 7500, 2, '750000007500' );
	ebay_has( '750000007500', listing( '750000007500', 2, 0 ), listing( '750000007500', 2, 0 ), listing( '750000007500', 2, 0 ) );
	ebay_answers( 'ReviseInventoryStatus', refused( 21919188, 'Quantity must be greater than 0.' ) );
};
$change = by_change( $shop_refuse, 7500, 0, array( 'auto_end_zero_stock' => '0' ) );
$action = by_action( $shop_refuse, array( 7500 ), array( 'auto_end_zero_stock' => '0' ), 7500, 0 );
check( 'auto-end off, eBay refusing 0: ended, as a change to 0 is - and the notice said so beforehand', array( calls_of( $action ), array_slice( $action['wire'], 1 ) === $change['wire'], meta( 7500, '_ebay_listing_status' ), $LAST_SAID['zero']['ids'] ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), true, 'Ended', array( 7500 ) ) );

// A variation keeping its own stock, at 0.
$shop_var = function ( $avail ) {
	return function () use ( $avail ) {
		variable( 6053, '605300006053' );
		variation( 6054, 6053, 'ZERO-V', 1, array( 'size' => 'S' ), array( '_ebay_variation_sku' => 'ZERO-V' ) );
		variation( 6058, 6053, 'ZERO-W', 4, array( 'size' => 'M' ), array( '_ebay_variation_sku' => 'ZERO-W' ) );
		$l = variation_listing( '605300006053', array( ev( 'ZERO-V', 2, 2 - $avail, array( 'size' => 'S' ) ), ev( 'ZERO-W', 4, 0, array( 'size' => 'M' ) ) ) );
		ebay_has( '605300006053', $l, $l, $l );
	};
};
$change = by_change( $shop_var( 2 ), 6054, 0 );
$action = by_action( $shop_var( 2 ), array( 6054 ), array(), 6054, 0 );
check( 'a variation at 0, eBay showing 2, auto-end on: read first, then ReviseInventoryStatus 0 - never EndItem', array( calls_of( $action ), array_slice( $action['wire'], 1 ) === $change['wire'], calls_of( $change ), statuses( sent( 'ReviseInventoryStatus' ) ) ), array( array( 'GetItem', 'ReviseInventoryStatus' ), true, array( 'ReviseInventoryStatus' ), array( 'ZERO-V' => 0 ) ) );
check( '  said as set to 0', send_lines(), array( 'Send stock to eBay (products list, user #1): 1 queued (at 0, set to 0 if eBay shows stock 1: #6054); skipped 0.' ) );
$action = by_action( $shop_var( 0 ), array( 6054 ), array(), 6054, 0 );
check( '  eBay already showing it at 0: read, nothing more; the log says so', array( calls_of( $action ), logged( 'Stock push for WC #6054 (Send stock to eBay): variation ZERO-V of eBay listing 605300006053 already shows 0 available, so it was left as it is.' ) ), array( array( 'GetItem' ), true ) );
// Review 1's R5b: eBay's last variation with stock, and eBay will not take 0.
$shop_last = function () {
	variable( 7400, '740000007400' );
	variation( 7401, 7400, 'V-A', 0, array( 'size' => 'S' ), array( '_ebay_variation_sku' => 'V-A' ) );
	$l = variation_listing( '740000007400', array( ev( 'V-A', 1, 0, array( 'size' => 'S' ) ), ev( 'V-B', 1, 1, array( 'size' => 'M' ) ) ) );
	ebay_has( '740000007400', $l, $l, $l, $l );
	ebay_answers( 'ReviseInventoryStatus', refused( 942, 'Quantity is not valid for an item with variations.' ) );
};
$change = by_change( $shop_last, 7401, 0 );
$action = by_action( $shop_last, array( 7400 ), array(), 7401, 0 );
check( 'the last variation with stock at 0, eBay refusing 0: the listing ended, as a change to 0 ends it; said beforehand as may be', array( calls_of( $action ), array_slice( $action['wire'], 1 ) === $change['wire'], meta( 7400, '_ebay_listing_status' ), $LAST_SAID['zero']['ids'] ), array( array( 'GetItem', 'ReviseInventoryStatus', 'GetItem', 'EndItem' ), true, 'Ended', array( 7401 ) ) );

// An auction at 0: its bid check's read is the read.
$shop_auction = function ( $sold ) {
	return function () use ( $sold ) {
		product( 6051, 1, '605100006051', array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_type_item' => '605100006051' ) );
		ebay_has( '605100006051', listing( '605100006051', 1, $sold, array( 'ListingType' => 'Chinese' ) ) );
	};
};
$change = by_change( $shop_auction( 0 ), 6051, 0 );
$action = by_action( $shop_auction( 0 ), array( 6051 ), array(), 6051, 0 );
check( 'an auction at 0, nobody bidding: GetItem for its bids, then EndItem - one read, IDENTICAL to a change to 0', array( calls_of( $action ), $action['wire'] === $change['wire'], $action['jobs'] === $change['jobs'] ), array( array( 'GetItem', 'EndItem' ), true, true ) );
check( '  said with those to be ended if eBay still shows stock', send_lines(), array( 'Send stock to eBay (products list, user #1): 1 queued (at 0, EndItem if eBay shows stock 1: #6051); skipped 0.' ) );
$action = by_action( $shop_auction( 1 ), array( 6051 ), array(), 6051, 0 );
check( '  eBay showing the auction with nothing left: that same read, nothing more', array( calls_of( $action ), logged( 'Stock push for WC #6051 (Send stock to eBay): eBay auction 605100006051 already shows 0 available, so it was left as it is.' ) ), array( array( 'GetItem' ), true ) );
$action = by_action( $shop_auction( 0 ), array( 6051 ), array( 'auto_end_zero_stock' => '0' ), 6051, 0 );
check( '  with auto-end off: queued as a change to 0 is, left running, no call, no read; said so', array( count( $action['jobs'] ), calls_of( $action ), send_lines() ), array( 1, array(), array( 'Send stock to eBay (products list, user #1): 1 queued (auction at 0 with auto-end off, no call 1: #6051); skipped 0.' ) ) );
fresh_shop();
product( 6050, 1, '605000006050', array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_type_item' => '605000006050' ) );
edit_php( 'tcgiant_send_stock', array( 6050 ) );
check( 'an auction above 0: skipped - one item, nothing to send - no push, no call, no "stock changed" line', array( count( pushes() ), $WIRE, logged( 'stock changed to' ), send_lines() ), array( 0, array(), false, array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (auction above 0 1: #6050).' ) ) );

// A shared stock at 0: it reads first already, and sends only what differs.
$shop = function () {
	variable( 6055, '605500006055', array( '_manage_stock' => 'yes', '_stock' => 0 ) );
	variation( 6056, 6055, 'POOL-Z', null, array( 'size' => 'S' ) );
	variation( 6059, 6055, 'POOL-Y', null, array( 'size' => 'M' ) );
	ebay_has( '605500006055', variation_listing( '605500006055', array( ev( 'POOL-Z', 2, 0, array( 'size' => 'S' ) ), ev( 'POOL-Y', 2, 2, array( 'size' => 'M' ) ) ) ), variation_listing( '605500006055', array( ev( 'POOL-Z', 0, 0, array( 'size' => 'S' ) ), ev( 'POOL-Y', 2, 2, array( 'size' => 'M' ) ) ) ) );
};
$change = by_change( $shop, 6055, 0 );
$action = by_action( $shop, array( 6055 ), array(), 6055, 0 );
check( 'a shared stock at 0: one pool push, unchanged - IDENTICAL to a change to 0, only the variation eBay shows above 0 sent', array( count( $action['jobs'] ), $action['wire'] === $change['wire'], calls_of( $action ), statuses( sent( 'ReviseInventoryStatus' ) ) ), array( 1, true, array( 'GetItem', 'ReviseInventoryStatus', 'GetItem' ), array( 'POOL-Z' => 0 ) ) );
check( '  said with the listing that may be ended; a warning', notice_of( $LAST_SAID ), array( 'notice-warning', first_line( 1 ) . ' 1 shared stock at 0: each variation is set to 0 if eBay still shows it with stock; if eBay will not take 0 (Out-of-Stock control off), the listing is ended: #6055. ' . $CLOSE . ' ' . today_line( 1 ) ) );

// A try again keeps the read: the flag goes with it.
$shop = function () {
	product( 6063, 2, '606300006063' );
	ebay_has( '606300006063', refused( 21917053, 'The listing could not be read just now.' ), listing( '606300006063', 3, 3 ) );
};
fresh_shop();
$shop();
$META[6063]['_stock'] = 0;
edit_php( 'tcgiant_send_stock', array( 6063 ) );
run_queue( false, 5 );
$retry = array_values( array_filter( $QUEUE, function ( $a ) { return TCGiant_Sync_Inventory::PUSH_HOOK === $a['hook']; } ) );
check( 'an asked push at 0 whose read failed: tried again later, still asked (push_job() keeps it)', array( calls(), count( $retry ), $retry[0]['args'][0]['asked'] ?? null, (int) ( $retry[0]['args'][0]['attempt'] ?? 0 ) ), array( array( 'GetItem' ), 1, 1, 2 ) );
run_queue( true, 5 );
check( '  its try again: eBay at 0, left as it is - nothing ended', array( calls(), logged( 'Stock push for WC #6063 (Send stock to eBay): eBay listing 606300006063 already shows 0 available, so it was left as it is.' ), meta( 6063, '_ebay_listing_status' ) ), array( array( 'GetItem', 'GetItem' ), true, 'Active' ) );

echo "\nA LISTING ALREADY ENDED, ITS SALES IN: NOT QUEUED (FIX ROUND 1, M1)\n" . str_repeat( '=', 132 ) . "\n";

$shop = function () {
	product( 7300, 0, '730000007300', array( '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-11-01 10:00:00', '_ebay_stock_settled_item' => '730000007300', '_ebay_end_reason' => 'stock_zero' ) );
};
$action = by_action( $shop, array( 7300 ) );
check( 'a simple listing recorded Ended and settled (the Sold view): not queued, no call, named', array( count( $action['jobs'] ), calls_of( $action ), send_lines() ), array( 0, array(), array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (listing ended 1: #7300).' ) ) );
check( '  the notice: relist it; a warning, nothing queued', notice_of( $LAST_SAID ), array( 'notice-warning', first_line( 0 ) . ' 1 not sent: the eBay listing has ended - relist it to sell it again: #7300. ' . today_line( 0 ) ) );
$change = by_change( $shop, 7300, 0 );
check( '  a stock change of it: queued as before, and its push sends nothing either', array( count( $change['jobs'] ), calls_of( $change ) ), array( 1, array() ) );
fresh_shop();
product( 7302, 2, '730200007302', array( '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-11-01 10:00:00', '_ebay_stock_settled_item' => '730200007302', '_ebay_quantity_listed' => 3 ) );
product( 7303, 2, '730300007303', array( '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-11-01 10:00:00', '_ebay_stock_settled_item' => '730300007303' ) );
edit_php( 'tcgiant_send_stock', array( 7302, 7303 ) );
check( '  above 0 too; but a mark 3.27 left (no end reason, no figures) is not trusted: that one is sent, its push reads first', array( push_args(), send_lines() ), array( array( array( 7303, 'edit', null ) ), array( 'Send stock to eBay (products list, user #1): 1 queued (ReviseInventoryStatus 1: #7303); skipped 1 (listing ended 1: #7302).' ) ) );
fresh_shop();
product( 7301, 0, '730100007301', array( '_ebay_listing_type' => 'Chinese', '_ebay_listing_type_item' => '730100007301', '_ebay_listing_status' => 'Ended', '_ebay_stock_settled_at' => '2026-11-01 10:00:00', '_ebay_stock_settled_item' => '730100007301', '_ebay_end_reason' => 'stock_zero' ) );
edit_php( 'tcgiant_send_stock', array( 7301 ) );
check( 'an auction at 0 recorded ended and settled: not queued, named', array( count( pushes() ), send_lines() ), array( 0, array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (listing ended 1: #7301).' ) ) );

echo "\nEACH SKIP, COUNTED AND NAMED, AND THE LOG LINE\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 6100, 2, '610000006100' );                    // sent
product( 6101, 2 );                                      // not on eBay
product( 6102, null, '610200006102' );                  // stock not managed
product( 6103, 1, '610300006103', array( '_ebay_listing_type' => 'Chinese' ) ); // auction above 0
product( 6104, 2, '610400006104' );                      // a push waiting
set_transient( 'tcgiant_stock_pending_6104', 1, 60 );
product( 6105, 2, '610500006105' );                      // not yours
$CAN = function ( $cap, ...$args ) { return ! ( 'edit_post' === $cap && 6105 === (int) ( $args[0] ?? 0 ) ); };
variable( 6106, '610600006106' );
variation( 6107, 6106, '', 2, array( 'size' => 'S' ) );   // a variation eBay cannot be told of: no SKU
$click = edit_php( 'tcgiant_send_stock', array( 6100, 6101, 6102, 6103, 6104, 6105, 6106, 9999 ) );
$CAN   = null;
check( 'eight chosen: one queued, each other one skipped for its reason', push_args(), array( array( 6100, 'edit', null ) ) );
check( '  the log line: N queued, then each reason with its IDs', send_lines(), array( 'Send stock to eBay (products list, user #1): 1 queued (ReviseInventoryStatus 1: #6100); skipped 7 (not on eBay 2: #6101, #9999; stock not managed 1: #6102; auction above 0 1: #6103; already on its way 1: #6104; no permission 1: #6105; cannot be sent, logged 1: #6107).' ) );
check( '  the variation with no SKU: the stock change\'s own warning, as before', logged( 'Variation has no SKU', 'warning' ), true );
check( '  the notice: each reason, with its IDs (a success: something was queued, nothing at 0)', notice_of( said() ), array( 'notice-success', first_line( 1 ) . ' 1 sets eBay\'s quantity to its stock. 2 not sent: not on eBay: #6101, #9999. 1 not sent: WooCommerce does not manage its stock, or has no figure for it: #6102. 1 not sent: an auction with stock is one item, so there is no quantity to send: #6103. 1 not sent: a push of its stock is already on its way, or eBay\'s own figure was just brought in: #6104. 1 not sent: you cannot edit it: #6105. 1 not sent: its stock cannot be sent to its listing, and the log says why: #6107. ' . $CLOSE . ' ' . today_line( 1 ) ) );
check( '  the push waiting keeps its own mark; nothing else marked', array( get_transient( 'tcgiant_stock_pending_6104' ), get_transient( 'tcgiant_stock_pending_6101' ), get_transient( 'tcgiant_stock_pending_6105' ) ), array( 1, false, false ) );

fresh_shop();
$many = array();
for ( $i = 0; $i < 7; $i++ ) {
	product( 6110 + $i, 2 );
	$many[] = 6110 + $i;
}
edit_php( 'tcgiant_send_stock', $many );
check( 'seven not on eBay: five named, "and 2 more"', send_lines(), array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 7 (not on eBay 7: #6110, #6111, #6112, #6113, #6114 and 2 more).' ) );
check( '  the notice the same, and a warning: nothing was queued', notice_of( said() ), array( 'notice-warning', first_line( 0 ) . ' 7 not sent: not on eBay: #6110, #6111, #6112, #6113, #6114 and 2 more. ' . today_line( 0 ) ) );
fresh_shop();
for ( $i = 0; $i < 2; $i++ ) {
	product( 6140 + $i, null, (string) ( 614000000000 + $i ) );
}
edit_php( 'tcgiant_send_stock', array( 6140, 6141 ) );
check( '  two not managing stock: the plural words', notice_of( said() )[1], first_line( 0 ) . ' 2 not sent: WooCommerce does not manage their stock, or has no figure for them: #6140, #6141. ' . today_line( 0 ) );

echo "\nALREADY ON ITS WAY: THE 60-SECOND MARK, A LIVE SALE PUSH, EBAY'S OWN FIGURE\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 6120, 3, '612000006120' );
$META[6120]['_ebay_push_pending'] = array( 'sale-6120' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) );
edit_php( 'tcgiant_send_stock', array( 6120 ) );
check( 'a website sale\'s push on its way (it reads eBay and sends the stock when it runs): skipped, named', array( count( pushes() ), send_lines() ), array( 0, array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (already on its way 1: #6120).' ) ) );
fresh_shop();
product( 6120, 4, '612000006120' );
$META[6120]['_ebay_push_pending'] = array( 'sale-6120' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) );
hand_edit( 6120, 3 );
check( '  a stock change with the same sale push waiting still queues its own, as before (only the action asks)', push_args(), array( array( 6120, 'edit', null ) ) );
fresh_shop();
product( 6121, 3, '612100006121' );
$META[6121]['_ebay_push_pending'] = array( 'sale-6121' => array( 'until' => time() + HOUR_IN_SECONDS, 'queued_at' => time() ) );
set_transient( 'tcgiant_push_tomorrow_6121', 1, DAY_IN_SECONDS );
edit_php( 'tcgiant_send_stock', array( 6121 ) );
check( '  a sale push parked for tomorrow is not on its way: sent', push_args(), array( array( 6121, 'edit', null ) ) );
fresh_shop();
product( 6122, 3, '612200006122' );
TCGiant_Sync_Inventory::mark_ebay_origin( 6122 );
edit_php( 'tcgiant_send_stock', array( 6122 ) );
check( 'eBay\'s own figure written in the last 30 seconds: not sent back, as a stock change of it is not', array( count( pushes() ), send_lines() ), array( 0, array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (already on its way 1: #6122).' ) ) );
fresh_shop();
product( 6123, 3, '612300006123' );
edit_php( 'tcgiant_send_stock', array( 6123 ) );
edit_php( 'tcgiant_send_stock', array( 6123 ) );
check( 'chosen twice within the minute: one push, the second said on its way, and only one of today\'s taken', array( count( pushes() ), send_lines()[1] ?? null, TCGiant_Sync_Inventory::send_stock_today() ), array( 1, 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (already on its way 1: #6123).', 1 ) );

fresh_shop();
product( 6124, 3, '612400006124' );
TCGiant_Sync_Inventory::begin_ebay_origin();
edit_php( 'tcgiant_send_stock', array( 6124 ) );
TCGiant_Sync_Inventory::end_ebay_origin();
check( 'inside eBay\'s own write (pushes suppressed): not sent, said as eBay\'s own figure, not as one that cannot be sent', array( count( pushes() ), send_lines() ), array( 0, array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (already on its way 1: #6124).' ) ) );

echo "\nAN UNSENT SALE, A STOCK REVIEW ROW: SENT, AS A STOCK CHANGE OF THEM IS\n" . str_repeat( '=', 132 ) . "\n";

$shop = function () {
	product( 6130, 4, '613000006130' );
	$GLOBALS['META'][6130]['_ebay_push_pending'] = array( 'unsent' => time() + DAY_IN_SECONDS );
	$GLOBALS['META'][6130]['_ebay_unsent_qty']   = array( 'sale-x' => 1 );
	ebay_has( '613000006130', listing( '613000006130', 5, 0 ) );
};
$change = by_change( $shop, 6130, 4 );
$action = by_action( $shop, array( 6130 ) );
check( 'a product with a sale that never reached eBay (unsent): sent, IDENTICAL to a stock change of it', array( count( $action['jobs'] ), $action['wire'] === $change['wire'], calls_of( $action ) ), array( 1, true, calls_of( $change ) ) );
$shop = function () {
	product( 6131, 4, '613100006131' );
	$GLOBALS['OPTIONS']['tcgiant_sync_push_failures'] = array( array( 'time' => time(), 'product_id' => 6131, 'item_id' => '613100006131', 'sku' => '', 'reason' => 'edit', 'qty' => 0, 'order' => 0, 'stock' => 4, 'code' => '518', 'message' => 'x' ) );
};
$change = by_change( $shop, 6131, 4 );
$action = by_action( $shop, array( 6131 ) );
check( 'a product on Stock Review\'s list of pushes eBay refused: sent, IDENTICAL to a stock change of it', array( count( $action['jobs'] ), $action['wire'] === $change['wire'], calls_of( $action ) ), array( 1, true, array( 'ReviseInventoryStatus' ) ) );

echo "\nSETTINGS AND CONNECTION, NEVER WORKED AROUND\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop( array( 'push_stock_to_ebay' => '0' ) );
product( 6140, 3, '614000006140' );
edit_php( 'tcgiant_send_stock', array( 6140 ) );
check( '"Send website stock changes to eBay?" No: nothing queued, the log says why', array( count( pushes() ), send_lines() ), array( 0, array( 'Send stock to eBay (products list, user #1): nothing queued - "Send website stock changes to eBay?" is No.' ) ) );
check( '  stopped before any product is looked at: the action\'s line is the only one, no stock change\'s "not sent" note', array_column( $LOG, 1 ), array( 'Send stock to eBay (products list, user #1): nothing queued - "Send website stock changes to eBay?" is No.' ) );
check( '  the notice says why, and where to change it; a warning', notice_of( said() ), array( 'notice-warning', 'Send stock to eBay: nothing was queued, because "Send website stock changes to eBay?" is No in TCGiant Sync, Settings. Set it to Yes to send stock to eBay.' ) );
$r = listings_send( array( 6140 ) );
check( '  the Listings page: the job refused whole, in the same words, and logged', array( $r['start']['success'], $r['start']['data']['message'], count( send_lines() ), count( pushes() ) ), array( false, 'Send stock to eBay: nothing was queued, because "Send website stock changes to eBay?" is No in TCGiant Sync, Settings. Set it to Yes to send stock to eBay.', 2, 0 ) );

// Switched off part way through a click (a race, review 1's L3): it stops,
// and says what it queued and why it stopped.
fresh_shop();
product( 6145, 3, '614500006145' );
product( 6146, 3, '614600006146' );
product( 6147, 3, '614700006147' );
$ON_QUEUE = function () { $GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings']['push_stock_to_ebay'] = '0'; };
edit_php( 'tcgiant_send_stock', array( 6145, 6146, 6147 ) );
unset( $ON_QUEUE );
check( 'switched off part way through a click: one queued, the rest not looked at; the log says both', array( push_args(), send_lines() ), array( array( array( 6145, 'edit', null ) ), array( 'Send stock to eBay (products list, user #1): 1 queued (ReviseInventoryStatus 1: #6145); skipped 0. Then it stopped - "Send website stock changes to eBay?" is No, so those still waiting will not be sent; send stock to eBay again once it is Yes.' ) ) );
check( '  the notice says both; a warning', notice_of( said() ), array( 'notice-warning', 'Send stock to eBay: 1 push queued, then it stopped: "Send website stock changes to eBay?" is No in TCGiant Sync, Settings, so it will not be sent if it is still waiting. Send stock to eBay again once it is Yes. 1 sets eBay\'s quantity to its stock. ' . today_line( 1 ) ) );

// The connection gone part way through a click, past the reconnect window
// (fix round 2): the same stop, in its own words.
fresh_shop();
product( 6155, 3, '615500006155' );
product( 6156, 3, '615600006156' );
$ON_QUEUE = function () {
	$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings']['access_token']  = '';
	$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings']['refresh_token'] = '';
	$GLOBALS['OPTIONS'][ TCGiant_Sync_Inventory::DISCONNECTED_OPTION ]  = time() - TCGiant_Sync_Inventory::PUSH_RECONNECT_WINDOW - 60;
};
edit_php( 'tcgiant_send_stock', array( 6155, 6156 ) );
unset( $ON_QUEUE );
check( 'disconnected part way through a click: one queued; the log says those still waiting will not be sent, and to send again once connected', array( push_args(), send_lines() ), array( array( array( 6155, 'edit', null ) ), array( 'Send stock to eBay (products list, user #1): 1 queued (ReviseInventoryStatus 1: #6155); skipped 0. Then it stopped - this site is not connected to eBay, so those still waiting will not be sent; send stock to eBay again once it is connected.' ) ) );
check( '  the notice the same; a warning', notice_of( said() ), array( 'notice-warning', 'Send stock to eBay: 1 push queued, then it stopped: this site is not connected to eBay, so it will not be sent if it is still waiting. Send stock to eBay again once it is connected. 1 sets eBay\'s quantity to its stock. ' . today_line( 1 ) ) );

fresh_shop( array( 'access_token' => '', 'refresh_token' => '' ) );
product( 6141, 3, '614100006141' );
edit_php( 'tcgiant_send_stock', array( 6141 ) );
check( 'not connected to eBay: nothing queued, said', array( count( pushes() ), send_lines() ), array( 0, array( 'Send stock to eBay (products list, user #1): nothing queued - this site is not connected to eBay.' ) ) );
check( '  the notice', notice_of( said() )[1], 'Send stock to eBay: nothing was queued, because this site is not connected to eBay. Connect it in TCGiant Sync, Settings, then send the stock again.' );
// Fix round 2 (review 2's L-1).
$r = listings_send( array( 6141 ) );
check( '  the Listings page: the job refused whole, in the same words - no job, no start line, the refusal logged', array( $r['start']['success'], $r['start']['data']['message'], get_option( TCGiant_Sync_Jobs::OPTION_KEY, array() ), array_slice( send_lines(), 1 ), count( pushes() ) ), array( false, 'Send stock to eBay: nothing was queued, because this site is not connected to eBay. Connect it in TCGiant Sync, Settings, then send the stock again.', array(), array( 'Send stock to eBay (Listings page, user #1): nothing queued - this site is not connected to eBay.' ), 0 ) );
fresh_shop( array( 'access_token' => '', 'refresh_token' => '' ) );
product( 6148, 3 );
product( 6149, 2 );
edit_php( 'tcgiant_send_stock', array( 6148, 6149 ) );
check( 'not connected, only products not on eBay chosen: said as not connected, not as not on eBay', array( count( pushes() ), send_lines(), notice_of( said() ) ), array( 0, array( 'Send stock to eBay (products list, user #1): nothing queued - this site is not connected to eBay.' ), array( 'notice-warning', 'Send stock to eBay: nothing was queued, because this site is not connected to eBay. Connect it in TCGiant Sync, Settings, then send the stock again.' ) ) );
fresh_shop( array( 'access_token' => '', 'refresh_token' => '' ) );
product( 6142, 3, '614200006142' );
update_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, time() - 600 );
edit_php( 'tcgiant_send_stock', array( 6142 ) );
check( 'disconnected ten minutes ago, inside the window: queued, as a stock change is (its tries carry it past a reconnect)', push_args(), array( array( 6142, 'edit', null ) ) );
fresh_shop( array( 'access_token' => '', 'refresh_token' => '' ) );
product( 6143, 3, '614300006143' );
update_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, time() - TCGiant_Sync_Inventory::PUSH_RECONNECT_WINDOW - 60 );
edit_php( 'tcgiant_send_stock', array( 6143 ) );
check( 'disconnected past the window: nothing queued, said not connected', array( count( pushes() ), send_lines() ), array( 0, array( 'Send stock to eBay (products list, user #1): nothing queued - this site is not connected to eBay.' ) ) );
fresh_shop( array( 'access_token' => '', 'refresh_token' => '' ) );
product( 6144, 4, '614400006144' );
update_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, time() - TCGiant_Sync_Inventory::PUSH_RECONNECT_WINDOW - 60 );
hand_edit( 6144, 3 );
check( '  as a stock change: nothing queued either', count( pushes() ), 0 );

echo "\nCAPABILITY AND NONCE, ON BOTH PATHS\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
product( 6150, 3, '615000006150' );
$click = edit_php( 'tcgiant_send_stock', array( 6150 ), 'not-the-nonce' );
check( 'the products list, a wrong nonce: WordPress stops it before any handler - nothing queued, kept or logged', array( $click['died'], count( pushes() ), said(), send_lines() ), array( 'The link you followed has expired.', 0, false, array() ) );
$_REQUEST = array( '_wpnonce' => 'not-the-nonce' );
$back = $ADMIN->handle_send_stock_bulk_action( 'https://coinshop.example.com/wp-admin/edit.php?post_type=product', 'tcgiant_send_stock', array( 6150 ) );
check( '  the handler reached some other way with a wrong nonce: checks again - nothing, the address as it was', array( $back, count( pushes() ), said(), send_lines() ), array( 'https://coinshop.example.com/wp-admin/edit.php?post_type=product', 0, false, array() ) );
$_REQUEST = array();
$back = $ADMIN->handle_send_stock_bulk_action( 'edit.php', 'tcgiant_send_stock', array( 6150 ) );
check( '  no nonce at all: the same', array( $back, count( pushes() ), said() ), array( 'edit.php', 0, false ) );
$CAN   = function ( $cap ) { return 'edit_products' !== $cap; };
$click = edit_php( 'tcgiant_send_stock', array( 6150 ) );
check( '  a user without edit_products, the list\'s good nonce: nothing queued, kept or logged', array( $click['died'], count( pushes() ), said(), send_lines() ), array( null, 0, false, array() ) );
set_transient( TCGiant_Sync_Admin::SEND_STOCK_SAID . 1, TCGiant_Sync_Inventory::send_stock_said(), 300 );
check( '  and no notice is drawn for such a user, even with a record kept for them', array( list_notice(), is_array( said() ) ), array( '', true ) );
delete_transient( TCGiant_Sync_Admin::SEND_STOCK_SAID . 1 );
$CAN = null;
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'bulk-posts' ) );
check( '  another bulk action passes through it untouched, with the good nonce: nothing queued', array( $ADMIN->handle_send_stock_bulk_action( 'edit.php', 'tcgiant_follow_send', array( 6150 ) ), count( pushes() ) ), array( 'edit.php', 0 ) );
$CAN   = function ( $cap, ...$args ) { return ! ( 'edit_post' === $cap && 6150 === (int) ( $args[0] ?? 0 ) ); };
product( 6151, 3, '615100006151' );
edit_php( 'tcgiant_send_stock', array( 6150, 6151 ) );
$CAN = null;
check( '  capability per product: the one the user cannot edit skipped and named, the other sent', array( push_args(), send_lines() ), array( array( array( 6151, 'edit', null ) ), array( 'Send stock to eBay (products list, user #1): 1 queued (ReviseInventoryStatus 1: #6151); skipped 1 (no permission 1: #6150).' ) ) );

fresh_shop();
product( 6152, 3, '615200006152' );
$r = listings_send( array( 6152 ), 'not-the-nonce' );
check( 'the Listings page, a wrong nonce: admin-ajax ends it (-1) - no job, nothing queued', array( $r['start'], count( pushes() ), get_option( TCGiant_Sync_Jobs::OPTION_KEY, array() ) ), array( array( 'died' => '-1' ), 0, array() ) );
$CAN = function ( $cap ) { return 'manage_options' !== $cap; };
$r   = listings_send( array( 6152 ) );
$CAN = null;
check( '  a user without manage_options: refused, nothing queued', array( $r['start'], count( pushes() ) ), array( array( 'success' => false, 'data' => array( 'message' => 'Unauthorized.' ) ), 0 ) );
$CAN = function ( $cap, ...$args ) { return ! ( 'edit_post' === $cap && 6152 === (int) ( $args[0] ?? 0 ) ); };
$r   = listings_send( array( 6152 ) );
$CAN = null;
check( '  capability per product there too: skipped and named', array( count( pushes() ), $r['last']['data']['lines'] ?? null ), array( 0, array( first_line( 0 ), '1 not sent: you cannot edit it: #6152.', today_line( 0 ) ) ) );

echo "\nTHE LISTINGS PAGE: ITS JOB, ITS COUNTS, ITS LOG LINES\n" . str_repeat( '=', 132 ) . "\n";

fresh_shop();
$ids = array();
for ( $i = 0; $i < 150; $i++ ) {
	product( 6200 + $i, 2, (string) ( 620000000000 + $i ) );
	$ids[] = 6200 + $i;
}
product( 6400, 2 );
product( 6401, 1, '640100006401', array( '_ebay_listing_type' => 'Chinese' ) );
product( 6402, 0, '640200006402' );
$ids[] = 6400;
$ids[] = 6401;
$ids[] = 6402;
$r = listings_send( $ids );
check( '153 chosen: the job in two batches of up to 100 pushes, completed', array( $r['start']['success'], $r['start']['data']['total'], $r['batches'], $r['last']['data']['status'] ), array( true, 153, 2, 'completed' ) );
check( '  151 pushes queued, 2 not sent: the job\'s counts and the progress window\'s', array( count( pushes() ), $r['last']['data']['queued'], $r['last']['data']['kept'], $r['last']['data']['type'] ), array( 151, 151, 2, 'bulk_send_stock' ) );
check( '  the click itself: no eBay call, no HTTP request, nothing but stock pushes', array( $WIRE, queued_hooks() ), array( array(), array( 'tcgiant_sync_push_stock' ) ) );
check( '  a line at the start, so that a closed tab leaves a trace; one at the end, written by its last batch', send_lines(), array(
	'Send stock to eBay (Listings page, user #1): started on 153 products. Send stock to eBay today: 0 of 200.',
	'Send stock to eBay (Listings page, user #1): 151 queued (ReviseInventoryStatus 150: #6200, #6201, #6202, #6203, #6204 and 145 more; at 0, EndItem if eBay shows stock 1: #6402); skipped 2 (not on eBay 1: #6400; auction above 0 1: #6401).',
) );
check( '  the notice in the progress window: the counts, the one at 0, each skip, today\'s count', $r['last']['data']['lines'], array(
	first_line( 151 ),
	'150 set eBay\'s quantity to their stock.',
	'1 at 0: its eBay listing will be ended if eBay still shows stock (an auction with bids is left running): #6402.',
	'1 not sent: not on eBay: #6400.',
	'1 not sent: an auction with stock is one item, so there is no quantity to send: #6401.',
	$CLOSE,
	today_line( 151 ),
) );
$jq = push_jobs();
check( '  each push the one a stock change queues, and asked', array( $jq[0], $jq[150], array_values( array_unique( asked_flags() ) ) ), array( array( 'product_id' => 6200, 'reason' => 'edit', 'item' => '620000000000' ), array( 'product_id' => 6402, 'reason' => 'edit', 'item' => '640200006402' ), array( 1 ) ) );
$job = TCGiant_Sync_Jobs::get_job( $r['start']['data']['job_id'] );
check( '  the job keeps its lists, not the whole selection', array( $job['send_stock']['revise']['n'], count( $job['send_stock']['revise']['ids'] ) ), array( 150, 5 ) );
check( '  the progress window\'s count words for it', 1 === substr_count( $view, "if (d.type === 'bulk_send_stock') {" ) && 1 === substr_count( $view, "if (d.lines && d.lines.length) {" ), true );

// A batch counts pushes, not products (review 1's L2).
fresh_shop();
variable( 6800, '680000006800' );
for ( $i = 1; $i <= 150; $i++ ) {
	variation( 6800 + $i, 6800, 'MANY-' . $i, 1, array( 'n' => (string) $i ), array( '_ebay_variation_sku' => 'MANY-' . $i ) );
}
product( 6960, 2, '696000006960' );
product( 6961, 2, '696100006961' );
$nonce = wp_create_nonce( 'tcgiant_sync_ajax' );
$start = admin_ajax( array( 'action' => 'tcgiant_job_start', 'type' => 'bulk_send_stock', 'product_ids' => array( '6800', '6960', '6961' ), '_ajax_nonce' => $nonce ) );
$b1    = admin_ajax( array( 'action' => 'tcgiant_job_process', 'job_id' => $start['data']['job_id'], '_ajax_nonce' => $nonce ) );
check( 'a variable product with 150 variations of their own: the batch stops after it - 150 pushes, 1 product of 3 done', array( $b1['data']['status'], $b1['data']['processed'], $b1['data']['queued'] ), array( 'running', 1, 150 ) );
$b2 = admin_ajax( array( 'action' => 'tcgiant_job_process', 'job_id' => $start['data']['job_id'], '_ajax_nonce' => $nonce ) );
check( '  the next batch takes the other two, and ends the job', array( $b2['data']['status'], $b2['data']['processed'], $b2['data']['queued'], count( pushes() ) ), array( 'completed', 3, 152, 152 ) );

// Switched off after a batch queued pushes (review 1's R1, M2).
fresh_shop();
$ids = array();
for ( $i = 0; $i < 150; $i++ ) {
	product( 7000 + $i, 2, (string) ( 700000000000 + $i ) );
	$ids[] = 7000 + $i;
}
$start = admin_ajax( array( 'action' => 'tcgiant_job_start', 'type' => 'bulk_send_stock', 'product_ids' => array_map( 'strval', $ids ), '_ajax_nonce' => $nonce ) );
$b1    = admin_ajax( array( 'action' => 'tcgiant_job_process', 'job_id' => $start['data']['job_id'], '_ajax_nonce' => $nonce ) );
$OPTIONS['tcgiant_sync_ebay_settings']['push_stock_to_ebay'] = '0';
$b2 = admin_ajax( array( 'action' => 'tcgiant_job_process', 'job_id' => $start['data']['job_id'], '_ajax_nonce' => $nonce ) );
check( 'switched off after a batch queued 100: the job ends; the window says what was queued, then why it stopped', array( $b1['data']['queued'], $b2['data']['status'], $b2['data']['lines'] ), array( 100, 'completed', array(
	'Send stock to eBay: 100 pushes queued, then it stopped: "Send website stock changes to eBay?" is No in TCGiant Sync, Settings, so those still waiting will not be sent. Send stock to eBay again once it is Yes.',
	'100 set eBay\'s quantity to their stock.',
	today_line( 100 ),
) ) );
check( '  the log line too', end( $LOG )[1], 'Send stock to eBay (Listings page, user #1): 100 queued (ReviseInventoryStatus 100: #7000, #7001, #7002, #7003, #7004 and 95 more); skipped 0. Then it stopped - "Send website stock changes to eBay?" is No, so those still waiting will not be sent; send stock to eBay again once it is Yes.' );

// Cancelled after a batch (review 1's R2, M2).
fresh_shop();
for ( $i = 0; $i < 150; $i++ ) {
	product( 7000 + $i, 2, (string) ( 700000000000 + $i ) );
}
$start = admin_ajax( array( 'action' => 'tcgiant_job_start', 'type' => 'bulk_send_stock', 'product_ids' => array_map( 'strval', $ids ), '_ajax_nonce' => $nonce ) );
$b1    = admin_ajax( array( 'action' => 'tcgiant_job_process', 'job_id' => $start['data']['job_id'], '_ajax_nonce' => $nonce ) );
$c     = admin_ajax( array( 'action' => 'tcgiant_job_cancel', 'job_id' => $start['data']['job_id'], '_ajax_nonce' => $nonce ) );
$b2    = admin_ajax( array( 'action' => 'tcgiant_job_process', 'job_id' => $start['data']['job_id'], '_ajax_nonce' => $nonce ) );
check( 'cancelled after a batch: the Cancel logs what was queued, "cancelled after 100 of 150 products"; nothing more queued', array( $c['success'], $b2['data']['status'], count( pushes() ), end( $LOG )[1] ), array( true, 'cancelled', 100, 'Send stock to eBay (Listings page, user #1): cancelled after 100 of 150 products: 100 queued (ReviseInventoryStatus 100: #7000, #7001, #7002, #7003, #7004 and 95 more); skipped 0.' ) );

// The race: Cancel saved by another request while a batch runs.
fresh_shop();
for ( $i = 0; $i < 150; $i++ ) {
	product( 7000 + $i, 2, (string) ( 700000000000 + $i ) );
}
$start = admin_ajax( array( 'action' => 'tcgiant_job_start', 'type' => 'bulk_send_stock', 'product_ids' => array_map( 'strval', $ids ), '_ajax_nonce' => $nonce ) );
$b1    = admin_ajax( array( 'action' => 'tcgiant_job_process', 'job_id' => $start['data']['job_id'], '_ajax_nonce' => $nonce ) );
$CANCEL_ONCE = $start['data']['job_id'];
$ON_QUEUE    = function () {
	if ( null !== $GLOBALS['CANCEL_ONCE'] ) {
		$id = $GLOBALS['CANCEL_ONCE'];
		$GLOBALS['CANCEL_ONCE'] = null;
		$keep = array( $_POST, $_REQUEST );
		admin_ajax( array( 'action' => 'tcgiant_job_cancel', 'job_id' => $id, '_ajax_nonce' => wp_create_nonce( 'tcgiant_sync_ajax' ) ) );
		list( $_POST, $_REQUEST ) = $keep;
	}
};
$b2 = admin_ajax( array( 'action' => 'tcgiant_job_process', 'job_id' => $start['data']['job_id'], '_ajax_nonce' => $nonce ) );
unset( $ON_QUEUE );
check( 'Cancel landing while a batch runs: the batch does not write it over - the job stays cancelled', array( $b2['data']['status'], job_status( $start['data']['job_id'] ) ), array( 'cancelled', 'cancelled' ) );
check( '  the Cancel logs the first batch\'s 100; the batch under way logs its own 50', array_slice( send_lines(), -2 ), array(
	'Send stock to eBay (Listings page, user #1): cancelled after 100 of 150 products: 100 queued (ReviseInventoryStatus 100: #7000, #7001, #7002, #7003, #7004 and 95 more); skipped 0.',
	'Send stock to eBay (Listings page, user #1): a batch under way when the job was cancelled: 50 queued (ReviseInventoryStatus 50: #7100, #7101, #7102, #7103, #7104 and 45 more); skipped 0.',
) );
$after = admin_ajax( array( 'action' => 'tcgiant_job_process', 'job_id' => $start['data']['job_id'], '_ajax_nonce' => $nonce ) );
check( '  and the next ask is told it is cancelled', $after['data']['status'], 'cancelled' );
// Fix round 2 (review 2's L-5): one product, said as one.
fresh_shop();
product( 6153, 3, '615300006153' );
listings_send( array( 6153 ) );
check( 'one product on the Listings page: "started on 1 product"', send_lines()[0], 'Send stock to eBay (Listings page, user #1): started on 1 product. Send stock to eBay today: 0 of 200.' );
fresh_shop();
product( 6154, 3, '615400006154' );
$start = admin_ajax( array( 'action' => 'tcgiant_job_start', 'type' => 'bulk_send_stock', 'product_ids' => array( '6154' ), '_ajax_nonce' => $nonce ) );
admin_ajax( array( 'action' => 'tcgiant_job_cancel', 'job_id' => $start['data']['job_id'], '_ajax_nonce' => $nonce ) );
check( '  cancelled before its batch: "cancelled after 0 of 1 product"', end( $LOG )[1], 'Send stock to eBay (Listings page, user #1): cancelled after 0 of 1 product: 0 queued; skipped 0.' );

// update_job(), for every job (fix round 1): a cancelled job stays cancelled.
fresh_shop();
$any = TCGiant_Sync_Jobs::create_job( 'bulk_set_format', array( 1, 2 ), array( 'format_type' => 'FixedPriceItem', 'format_duration' => '' ) );
TCGiant_Sync_Jobs::update_job( $any, array( 'status' => 'cancelled' ) );
TCGiant_Sync_Jobs::update_job( $any, array( 'status' => 'running', 'processed' => 1 ) );
$any = TCGiant_Sync_Jobs::get_job( $any );
check( 'any job cancelled: a later write keeps it cancelled, and still takes its progress', array( $any['status'], $any['processed'] ), array( 'cancelled', 1 ) );

fresh_shop( array( 'push_stock_to_ebay' => '0' ) );
product( 6403, 2, '640300006403' );
$JOBS_ID = TCGiant_Sync_Jobs::create_job( 'bulk_send_stock', array( 6403 ) );
TCGiant_Sync_Jobs::update_job( $JOBS_ID, array( 'status' => 'running' ) );
$last = admin_ajax( array( 'action' => 'tcgiant_job_process', 'job_id' => $JOBS_ID, '_ajax_nonce' => wp_create_nonce( 'tcgiant_sync_ajax' ) ) );
check( 'switched off before its first batch: that batch queues nothing, the job ends, one line says why', array( count( pushes() ), $last['data']['status'], send_lines() ), array( 0, 'completed', array( 'Send stock to eBay (Listings page, user #1): nothing queued - "Send website stock changes to eBay?" is No.' ) ) );

echo "\nTODAY'S ALLOWANCE ON THE FREE PLAN: 200 PUSHES A DAY (FIX ROUND 1, THE OWNER'S DECISION)\n" . str_repeat( '=', 132 ) . "\n";

$budget = function ( $site_cap, $age = 0 ) {
	set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'daily_limit' => 50000, 'global_calls' => 100, 'remaining' => 49900, 'site_cap' => $site_cap, 'updated_at' => time() - $age ) );
};
fresh_shop();
check( 'the plan: none known counts as free (200)', TCGiant_Sync_Inventory::send_stock_daily_cap(), 200 );
$budget( 1000 );
check( '  the relay\'s 1,000 (the free plan): 200', TCGiant_Sync_Inventory::send_stock_daily_cap(), 200 );
$budget( 10000, 5 * HOUR_IN_SECONDS );
check( '  10,000 (a paid plan), even from a figure five hours old: no limit', TCGiant_Sync_Inventory::send_stock_daily_cap(), 0 );
$TRANSIENTS = array();
set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), array( 'site_cap' => 10000, 'updated_at' => time() - DAY_IN_SECONDS ) );
check( '  yesterday\'s figure, before today\'s first refresh: read', TCGiant_Sync_Inventory::send_stock_daily_cap(), 0 );

fresh_shop();
$ids = array();
for ( $i = 0; $i < 205; $i++ ) {
	product( 7600 + $i, 2, (string) ( 760000000000 + $i ) );
	$ids[] = 7600 + $i;
}
edit_php( 'tcgiant_send_stock', $ids );
check( '205 chosen on the free plan: 200 queued, 5 not sent; 200 of today\'s taken', array( count( pushes() ), said()['capped']['n'], TCGiant_Sync_Inventory::send_stock_today() ), array( 200, 5, 200 ) );
check( '  the log line', send_lines(), array( 'Send stock to eBay (products list, user #1): 200 queued (ReviseInventoryStatus 200: #7600, #7601, #7602, #7603, #7604 and 195 more); skipped 5 (today\'s limit reached 5: #7800, #7801, #7802, #7803, #7804).' ) );
check( '  the notice: the limit, to send them tomorrow; a warning; today\'s count', notice_of( said() ), array( 'notice-warning', first_line( 200 ) . ' 200 set eBay\'s quantity to their stock. 5 not sent: today\'s limit for Send stock to eBay (200 on the free plan) is reached - select them again tomorrow, or upgrade: #7800, #7801, #7802, #7803, #7804. ' . $CLOSE . ' ' . today_line( 200 ) ) );
check( '  the 60-second mark only on those queued', array( get_transient( 'tcgiant_stock_pending_7799' ), get_transient( 'tcgiant_stock_pending_7800' ) ), array( 1, false ) );
product( 7900, 2, '790000007900' );
product( 7901, 2 );
edit_php( 'tcgiant_send_stock', array( 7901, 7900 ) );
check( '  another click the same day: the allowance is shared - not sent; a product not on eBay said as such, not as capped', end( $LOG )[1], 'Send stock to eBay (products list, user #1): 0 queued; skipped 2 (not on eBay 1: #7901; today\'s limit reached 1: #7900).' );
$OPTIONS[ TCGiant_Sync_Inventory::SEND_STOCK_TODAY_OPTION ] = array( 'day' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'count' => 200 );
check( '  the next UTC day: none used yet', array( TCGiant_Sync_Inventory::send_stock_today(), TCGiant_Sync_Inventory::send_stock_today_line() ), array( 0, 'Send stock to eBay today: 0 of 200.' ) );
product( 7902, 0, '790200007902' );
edit_php( 'tcgiant_send_stock', array( 7900, 7901, 7902 ) );
check( '  each push queued takes one, at 0 too; a product skipped takes none', array( count( pushes() ) - 200, TCGiant_Sync_Inventory::send_stock_today() ), array( 2, 2 ) );
variable( 7903, '790300007903', array( '_manage_stock' => 'yes', '_stock' => 3 ) );
variation( 7904, 7903, 'AL-1', null, array( 'size' => 'S' ) );
edit_php( 'tcgiant_send_stock', array( 7903 ) );
check( '  a shared stock\'s push takes one', TCGiant_Sync_Inventory::send_stock_today(), 3 );

fresh_shop();
$budget( 10000 );
for ( $i = 0; $i < 205; $i++ ) {
	product( 7600 + $i, 2, (string) ( 760000000000 + $i ) );
}
edit_php( 'tcgiant_send_stock', $ids );
$paid = notice_of( said() );
check( 'a paid plan: no limit - 205 queued, nothing counted; the notice says no daily limit', array( count( pushes() ), get_option( TCGiant_Sync_Inventory::SEND_STOCK_TODAY_OPTION, false ), $paid[0], substr( $paid[1], -48 ) ), array( 205, false, 'notice-success', 'Send stock to eBay: no daily limit on your plan.' ) );
// Fix round 2 (review 2's L-4): Action Scheduler does not save a push - its
// store throws, as its database store does on a failed insert. What was
// taken for it is given back, and the action goes on to its line and notice.
fresh_shop();
product( 6160, 3, '616000006160' );
product( 6161, 3, '616100006161' );
product( 6162, 3, '616200006162' );
$THROW_ONCE = true;
$ON_QUEUE   = function () {
	if ( 1 === count( pushes() ) && $GLOBALS['THROW_ONCE'] ) {
		$GLOBALS['THROW_ONCE'] = false;
		throw new RuntimeException( 'Error saving action' );
	}
};
$click = edit_php( 'tcgiant_send_stock', array( 6160, 6161, 6162 ) );
unset( $ON_QUEUE );
check( 'Action Scheduler throws on the second of three: the other two queued, its unit of today given back', array( $click['died'], push_args(), TCGiant_Sync_Inventory::send_stock_today() ), array( null, array( array( 6160, 'edit', null ), array( 6162, 'edit', null ) ), 2 ) );
check( '  the action\'s line still written, the product said as not sent; the reason logged as a warning', array( send_lines(), logged( 'Stock push for WC #6161 (Send stock to eBay) not queued: Action Scheduler did not save it (Error saving action). Nothing was sent; send its stock again.', 'warning' ) ), array( array( 'Send stock to eBay (products list, user #1): 2 queued (ReviseInventoryStatus 2: #6160, #6162); skipped 1 (cannot be sent, logged 1: #6161).' ), true ) );
check( '  the notice still drawn', notice_of( said() ), array( 'notice-success', first_line( 2 ) . ' 2 set eBay\'s quantity to their stock. 1 not sent: its stock cannot be sent to its listing, and the log says why: #6161. ' . $CLOSE . ' ' . today_line( 2 ) ) );
check( '  its 60-second mark and its pending mark taken off; the others keep theirs', array( get_transient( 'tcgiant_stock_pending_6161' ), meta( 6161, TCGiant_Sync_Inventory::PUSH_PENDING_KEY ), get_transient( 'tcgiant_stock_pending_6160' ), count( (array) meta( 6160, TCGiant_Sync_Inventory::PUSH_PENDING_KEY ) ) ), array( false, null, 1, 1 ) );
hand_edit( 6161, 2 );
check( '  so a stock change of it straight after is queued, not taken for a push on its way', end( $GLOBALS['QUEUE'] )['args'][0]['product_id'] ?? null, 6161 );
fresh_shop();
product( 6163, 3, '616300006163' );
$ON_QUEUE = function () { return false; };
edit_php( 'tcgiant_send_stock', array( 6163 ) );
unset( $ON_QUEUE );
check( '  Action Scheduler giving no action ID (0): the same - not sent, nothing counted, logged', array( count( pushes() ), TCGiant_Sync_Inventory::send_stock_today(), send_lines(), logged( 'Stock push for WC #6163 (Send stock to eBay) not queued: Action Scheduler did not save it. Nothing was sent; send its stock again.', 'warning' ) ), array( 0, 0, array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (cannot be sent, logged 1: #6163).' ), true ) );
fresh_shop();
$budget( 10000 );
product( 6164, 3, '616400006164' );
// 5 counted earlier today, on the free plan, before the shop upgraded.
$OPTIONS[ TCGiant_Sync_Inventory::SEND_STOCK_TODAY_OPTION ] = array( 'day' => gmdate( 'Y-m-d' ), 'count' => 5 );
$ON_QUEUE = function () { throw new RuntimeException( 'Error saving action' ); };
edit_php( 'tcgiant_send_stock', array( 6164 ) );
unset( $ON_QUEUE );
check( '  on a paid plan nothing was counted, so nothing is given back', array( count( pushes() ), get_option( TCGiant_Sync_Inventory::SEND_STOCK_TODAY_OPTION, false ), send_lines() ), array( 0, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 5 ), array( 'Send stock to eBay (products list, user #1): 0 queued; skipped 1 (cannot be sent, logged 1: #6164).' ) ) );

// The Listings page shares it, and once it is used ends the job cheaply.
fresh_shop();
$OPTIONS[ TCGiant_Sync_Inventory::SEND_STOCK_TODAY_OPTION ] = array( 'day' => gmdate( 'Y-m-d' ), 'count' => 150 );
$ids = array();
for ( $i = 0; $i < 120; $i++ ) {
	product( 7600 + $i, 2, (string) ( 760000000000 + $i ) );
	$ids[] = 7600 + $i;
}
$r = listings_send( $ids );
check( 'the Listings page, 150 used today: 50 queued, the other 70 said as capped unread, the job ended in one batch', array( count( pushes() ), $r['batches'], $r['last']['data']['status'], $r['last']['data']['processed'], $r['last']['data']['queued'], $r['last']['data']['kept'] ), array( 50, 1, 'completed', 120, 50, 70 ) );
check( '  its start line and window give today\'s count', array( send_lines()[0], end( $r['last']['data']['lines'] ) ), array( 'Send stock to eBay (Listings page, user #1): started on 120 products. Send stock to eBay today: 150 of 200.', today_line( 200 ) ) );
check( '  the capped line names the first five', $r['last']['data']['lines'][2], '70 not sent: today\'s limit for Send stock to eBay (200 on the free plan) is reached - select them again tomorrow, or upgrade: #7650, #7651, #7652, #7653, #7654 and 65 more.' );

echo "\nTHE BUDGET: THESE PUSHES AS A STOCK CHANGE'S\n" . str_repeat( '=', 132 ) . "\n";

// Past the fleet's 80% ceiling: a quantity above 0 waits for tomorrow, and
// one at 0 goes past it (is_priority()), whoever queued it. At 0 the action's
// push reads first: that GetItem goes past it too.
$shop = function () {
	product( 6500, 4, '650000006500' );
	product( 6501, 1, '650100006501' );
	ebay_has( '650100006501', listing( '650100006501', 1, 0 ) );
	fleet_used( 0.85 );
};
$after = function () {
	return array( calls(), array_map( function ( $a ) { return array( $a['hook'], $a['args'][0]['product_id'] ?? null, ! empty( $a['args'][0]['tomorrow'] ), $a['when'] ); }, $GLOBALS['QUEUE'] ) );
};
fresh_shop();
$shop();
wc_update_product_stock( 6500, 3, 'set' );
wc_update_product_stock( 6501, 0, 'set' );
run_queue( false, 20 );
$by_change = $after();
fresh_shop();
$shop();
$META[6500]['_stock'] = 3;
$META[6501]['_stock'] = 0;
edit_php( 'tcgiant_send_stock', array( 6500, 6501 ) );
run_queue( false, 20 );
$by_action = $after();
check( 'past the fleet\'s 80% ceiling: the 3 waits for tomorrow as a stock change\'s does; the 0 ends the listing', array( $by_action[1], $by_change[0] ), array( $by_change[1], array( 'EndItem' ) ) );
check( '  the action\'s 0 reads first, past the ceiling too: GetItem, then EndItem; the 3 not sent, held for tomorrow, still asked', array( $by_action[0], count( $by_action[1] ), $by_action[1][0][2] ?? null, $QUEUE[0]['args'][0]['asked'] ?? null ), array( array( 'GetItem', 'EndItem' ), 1, true, 1 ) );

// This site's own allowance (the relay's cap: 1,000 a day on the free plan)
// used up: a quantity above 0 waits for tomorrow; one at 0 goes past it.
$shop = function () {
	product( 6510, 4, '651000006510' );
	product( 6511, 1, '651100006511' );
	ebay_has( '651100006511', listing( '651100006511', 1, 0 ) );
	set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array( 'daily_limit' => 50000, 'global_calls' => 1000, 'remaining' => 49000, 'site_cap' => 1000, 'updated_at' => time() ) );
	update_option( 'tcgiant_sync_api_calls_' . gmdate( 'Y-m-d' ), 1000 );
};
fresh_shop();
$shop();
wc_update_product_stock( 6510, 3, 'set' );
wc_update_product_stock( 6511, 0, 'set' );
run_queue( false, 20 );
$by_change = $after();
fresh_shop();
$shop();
$META[6510]['_stock'] = 3;
$META[6511]['_stock'] = 0;
edit_php( 'tcgiant_send_stock', array( 6510, 6511 ) );
run_queue( false, 20 );
$by_action = $after();
check( 'this site\'s own daily allowance (1,000) used: the 3 waits for tomorrow, as a stock change\'s', $by_action[1], $by_change[1] );
check( '  the 0: GetItem and EndItem past it (a stock change\'s: EndItem)', array( $by_action[0], $by_change[0], $by_action[1][0][1] ?? null, $by_action[1][0][2] ?? null ), array( array( 'GetItem', 'EndItem' ), array( 'EndItem' ), 6510, true ) );

echo "\nWHAT IS STORED, AND THE WORDS ELSEWHERE\n" . str_repeat( '=', 132 ) . "\n";

$uninstall = (string) file_get_contents( $ROOT . '/uninstall.php' );
check( 'the notice\'s record: a per-user transient under the plugin\'s prefix, which uninstall deletes with the others', array( TCGiant_Sync_Admin::SEND_STOCK_SAID, 0 === strpos( TCGiant_Sync_Admin::SEND_STOCK_SAID, 'tcgiant_' ), false !== strpos( $uninstall, 'tcgiant_sync_send_stock_said_<user ID>' ) ), array( 'tcgiant_sync_send_stock_said_', true, true ) );
check( 'today\'s count: its own option, which uninstall deletes', array( TCGiant_Sync_Inventory::SEND_STOCK_TODAY_OPTION, 1 === substr_count( $uninstall, "\t'tcgiant_sync_send_stock_today',   // Send stock to eBay: pushes counted today on the free plan, 3.40.0.\n" ) ), array( 'tcgiant_sync_send_stock_today', true ) );
check( 'Stock Review\'s list of pushes eBay refused names the action, once the cause is fixed', false !== strpos( (string) file_get_contents( $ROOT . '/admin/views/stock-review.php' ), 'set its quantity there if it is wrong. Once the cause is fixed, Send stock to eBay (Bulk actions) sends your website\\\'s stock again.' ), true );
$readme = (string) file_get_contents( $ROOT . '/readme.txt' );
$md     = (string) file_get_contents( $ROOT . '/README.md' );
check( 'the readme and README.md count the free plan\'s 200 in pushes; with auto-end off a listing is ended if eBay will not take 0 (fix round 2, review 2\'s L-5)', array(
	substr_count( $readme . $md, '200 products a day' ),
	substr_count( $readme, 'Send stock to eBay sends up to 200 pushes a day (UTC; each listing, or each variation keeping its own stock, is one)' ),
	substr_count( $readme, 'At 0, eBay is read first. Up to 200 pushes a day on the free plan.' ),
	substr_count( $md, 'Up to 200 pushes a day on the free plan.' ),
	substr_count( $readme, 'with it off, eBay is set to 0, and the listing is ended if eBay will not take 0 (Out-of-Stock control off). A variation at 0 ends the listing only when it is eBay\'s last with stock and eBay will not take 0.' ),
), array( 0, 1, 1, 1, 1 ) );

// The staging copy: a child of this file, with TCGIANT_SYNC_IS_STAGING defined.
echo "\nA STAGING COPY (A CHILD RUN, TCGIANT_SYNC_IS_STAGING DEFINED)\n" . str_repeat( '=', 132 ) . "\n";
$child = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --staging 2>&1', $child );
foreach ( $child as $line ) {
	if ( preg_match( '/^\s*(\d+) passed, (\d+) failed/', $line, $m ) ) {
		$pass += (int) $m[1];
		$fail += (int) $m[2];
		printf( "  (child: %d checks passed, %d failed)\n", $m[1], $m[2] );
		continue;
	}
	echo $line, "\n";
}
if ( ! preg_grep( '/passed, \d+ failed/', $child ) ) {
	$fail++;
	echo "  FAIL  the staging child gave no summary\n";
}

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );

staging:
check( 'the child is a staging copy', TCGiant_Sync_Environment::write_block() instanceof WP_Error, true );
$shop = function () {
	product( 6600, 3, '660000006600' );
	ebay_has( '660000006600', listing( '660000006600', 5, 0 ) );
};
$change = by_change( $shop, 6600, 2 );
$action = by_action( $shop, array( 6600 ), array(), 6600, 2 );
check( 'queued as a stock change on a staging copy is; the push\'s own block stops it - no call, as the change\'s', array( count( $action['jobs'] ), $action['wire'], $change['wire'] ), array( 1, array(), array() ) );
check( '  the log line says so', send_lines(), array( 'Send stock to eBay (products list, user #1): 1 queued (ReviseInventoryStatus 1: #6600); skipped 0. A staging copy: each push stops before eBay.' ) );
set_transient( TCGiant_Sync_Admin::SEND_STOCK_SAID . 1, $LAST_SAID, 300 );
check( '  the notice says so, a warning', notice_drawn(), array( 'notice-warning', first_line( 1 ) . ' 1 sets eBay\'s quantity to its stock. This is a staging copy: each push stops before it reaches eBay, and nothing is sent. ' . today_line( 1 ) ) );
check( '  the push\'s block noted it, as it notes a stock change\'s', logged( 'Stock change for WC #6600' ) || is_array( get_transient( TCGiant_Sync_Environment::BLOCKED_TRANSIENT ) ), true );
printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
