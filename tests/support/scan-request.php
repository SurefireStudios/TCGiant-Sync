<?php
/**
 * One request in the played shop of test-scan-handoff.php.
 *
 * Usage: php scan-request.php <world folder> <what> [argument]
 *
 * Every request is a PHP process of its own, as on a site, and everything it
 * keeps - options, Action Scheduler's queue, WP-Cron, the log - is written to
 * the world folder as it is written, so a request killed part way, by a PHP
 * fatal or from outside with nothing run, leaves exactly what it had written.
 *
 * Loaded for real: TCGiant_Sync_Runtime and TCGiant_Sync_Importer (and, for
 * "poll", TCGiant_Sync_Admin; for "apixml", TCGiant_Sync_API alone).
 * Played: WordPress, Action Scheduler, WP-Cron, eBay, the product save, and
 * the rest of the plugin as far as these paths go.
 *
 * What:
 *   fetch            Fetch Inventory: start_full_sync( true )
 *   batch <scan>     An Action Scheduler action of the scan, with its argument
 *   backstop         The WP-Cron backstop, tcgiant_sync_scan_resume
 *   resume           Resume Import
 *   import <item>    An Action Scheduler item import
 *   delta            The hourly sync: start_delta_sync()
 *   stop             Emergency Stop, as its button does it
 *   screen           What the screens are told: screen_progress()
 *   poll             The dashboard's status poll, for real
 *   apixml           The real API class's GetSellerList request, with and
 *                    without an end time to start from
 *
 * @package TCGiant_Sync
 */

$WORLD = rtrim( str_replace( '\\', '/', (string) ( $argv[1] ?? '' ) ), '/' );
$DO    = (string) ( $argv[2] ?? '' );
$ARG   = (string) ( $argv[3] ?? '' );
$ARG   = '-' === $ARG ? '' : $ARG; // Nothing, as the shell is given it.
$ROOT  = dirname( __DIR__, 2 );

if ( '' === $WORLD || ! is_dir( $WORLD ) ) {
	fwrite( STDERR, "scan-request: no world folder\n" );
	exit( 2 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// --- The world, in files ------------------------------------------------------

function w_file( $name ) { return $GLOBALS['WORLD'] . '/' . $name; }
function w_load( $name, $default ) {
	$path = w_file( $name . '.ser' );
	if ( ! is_file( $path ) ) {
		return $default;
	}
	$raw = file_get_contents( $path );
	$val = '' === $raw ? false : unserialize( $raw );
	return false === $val ? $default : $val;
}
function w_save( $name, $value ) { file_put_contents( w_file( $name . '.ser' ), serialize( $value ), LOCK_EX ); }
function w_line( $name, $line ) { file_put_contents( w_file( $name . '.txt' ), $line . "\n", FILE_APPEND | LOCK_EX ); }
function scenario( $key, $default = null ) {
	$s = w_load( 'scenario', array() );
	return array_key_exists( $key, $s ) ? $s[ $key ] : $default;
}

// This request, numbered, for the record of which request saved what.
$REQ = (int) w_load( 'requests', 0 ) + 1;
w_save( 'requests', $REQ );

// --- WordPress ----------------------------------------------------------------

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
function add_action() { return true; }
function remove_action() { return true; }
function add_filter( $hook = '', $callback = null ) { w_line( 'filters', (string) $hook ); return true; }
function remove_filter() { return true; }
function apply_filters( $hook, $value ) { return $value; }
function has_term() { return false; }
function maybe_serialize( $value ) { return ( is_array( $value ) || is_object( $value ) ) ? serialize( $value ) : $value; }
function maybe_unserialize( $value ) {
	if ( is_string( $value ) && ( 'b:0;' === $value || false !== @unserialize( $value ) ) ) { // phpcs:ignore
		return unserialize( $value );
	}
	return $value;
}
function get_option( $name, $default = false ) {
	$o = w_load( 'options', array() );
	return array_key_exists( $name, $o ) ? $o[ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	// The list of live listings: a write the host ends the request in, before
	// it lands, or one the database refuses.
	if ( 'tcgiant_sync_active_ids' === $name ) {
		$n = (int) w_load( 'ids_writes', 0 ) + 1;
		w_save( 'ids_writes', $n );
		$die = (array) scenario( 'die_in_ids_write', array() );
		if ( isset( $die['write'] ) && (int) $die['write'] === $n ) {
			harness_die( $die['how'], 'live-list write ' . $n );
		}
		if ( (int) scenario( 'ids_write_refused', 0 ) === $n ) {
			return false;
		}
	}
	$o          = w_load( 'options', array() );
	$o[ $name ] = $value;
	w_save( 'options', $o );
	return true;
}
function add_option( $name, $value, $deprecated = '', $autoload = null ) { return update_option( $name, $value ); }
function delete_option( $name ) {
	$o = w_load( 'options', array() );
	unset( $o[ $name ] );
	w_save( 'options', $o );
	return true;
}
function get_transient( $name ) { return array( 'synced_products' => 7, 'pending_jobs' => 0 ); }
function set_transient() { return true; }
function delete_transient() { return true; }
function wp_cache_delete() { return true; }
function wp_cache_get() { return false; }
function wp_cache_set() { return true; }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['WORLD'] ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) { return gmdate( $format, strtotime( $date . ' UTC' ) ); }
function wp_date( $format, $ts = null ) { return gmdate( $format, null === $ts ? time() : $ts ); }
function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-4%03x-a%03x-%012x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xffffffffffff ) ); }
function get_post_meta( $id, $key = '', $single = false ) {
	if ( '_ebay_item_id' === $key ) {
		return (string) ( w_load( 'linked', array() )[ (int) $id ] ?? '' );
	}
	return '';
}
function get_post_status( $id ) {
	if ( ! array_key_exists( (int) $id, w_load( 'linked', array() ) ) ) {
		return false;
	}
	$trashed = is_file( w_file( 'trashed.txt' ) ) ? file( w_file( 'trashed.txt' ), FILE_IGNORE_NEW_LINES ) : array();
	return in_array( (string) (int) $id, $trashed, true ) ? 'trash' : 'publish';
}
function wp_trash_post( $id ) { w_line( 'trashed', (string) (int) $id ); return true; }

// WP-Cron.
function wp_schedule_single_event( $ts, $hook, $args = array() ) {
	$c   = w_load( 'cron', array() );
	$c[] = array( $hook, (int) $ts );
	w_save( 'cron', $c );
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	w_save( 'cron', array_values( array_filter( w_load( 'cron', array() ), function ( $e ) use ( $hook ) { return $e[0] !== $hook; } ) ) );
	return 0;
}
function wp_next_scheduled( $hook ) {
	foreach ( w_load( 'cron', array() ) as $e ) {
		if ( $e[0] === $hook ) {
			return $e[1];
		}
	}
	return false;
}

// Action Scheduler: an action is pending, or running while its request runs.
class ActionScheduler_Store {
	const STATUS_PENDING = 'pending';
	const STATUS_RUNNING = 'in-progress';
}
function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	$q   = w_load( 'queue', array() );
	$id  = (int) w_load( 'next_action', 1 );
	$q[] = array( 'id' => $id, 'hook' => $hook, 'args' => $args, 'group' => $group, 'status' => 'pending', 'priority' => (int) $priority );
	w_save( 'queue', $q );
	w_save( 'next_action', $id + 1 );
	// Every action ever queued, for counting.
	w_line( 'actions', $hook . ' ' . json_encode( $args ) . ' ' . (int) $priority );
	return $id;
}
function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) { return as_enqueue_async_action( $hook, $args, $group ); }
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( w_load( 'queue', array() ) as $a ) {
		if ( $a['hook'] === $hook && ( null === $args || $a['args'] == $args ) ) {
			return 'pending' === $a['status'] ? time() : true;
		}
	}
	return false;
}
function as_get_scheduled_actions( $args, $return_format = 'objects' ) {
	$want  = (string) ( $args['status'] ?? 'pending' );
	$found = array();
	foreach ( w_load( 'queue', array() ) as $a ) {
		if ( $a['hook'] === ( $args['hook'] ?? '' ) && $a['status'] === $want ) {
			$found[] = $a['id'];
		}
	}
	return $found;
}
function as_unschedule_all_actions( $hook, $args = null, $group = '' ) {
	w_save( 'queue', array_values( array_filter( w_load( 'queue', array() ), function ( $a ) use ( $hook ) {
		return ! ( $a['hook'] === $hook && 'pending' === $a['status'] );
	} ) ) );
}

// The database, as far as the scan reads and writes it straight. prepare()
// keeps the query and its values apart, so nothing here parses SQL.
class Harness_wpdb {
	public $options    = 'wp_options';
	public $postmeta   = 'wp_postmeta';
	public $posts      = 'wp_posts';
	public $last_error = '';
	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return "\x01" . serialize( array( $query, $args ) );
	}
	private function parts( $q ) {
		return 0 === strpos( (string) $q, "\x01" ) ? unserialize( substr( $q, 1 ) ) : array( (string) $q, array() );
	}
	public function get_var( $q ) {
		list( $sql, $args ) = $this->parts( $q );
		if ( false !== strpos( $sql, 'SELECT option_value FROM' ) ) {
			$o = w_load( 'options', array() );
			return array_key_exists( $args[0], $o ) ? maybe_serialize( $o[ $args[0] ] ) : null;
		}
		if ( false !== strpos( $sql, "'_ebay_item_id'" ) && isset( $args[0] ) ) {
			foreach ( w_load( 'linked', array() ) as $pid => $item ) {
				if ( (string) $item === (string) $args[0] ) {
					return (string) $pid;
				}
			}
		}
		return null;
	}
	public function query( $q ) {
		list( $sql, $args ) = $this->parts( $q );
		$sql = ltrim( $sql );
		// Emergency Stop, pressed in another request just as a batch, past
		// the status it read, takes the scan's place.
		if ( scenario( 'stop_on_claim', false ) && ! is_file( w_file( 'stopped-on-claim' ) )
			&& ( 0 === strpos( $sql, 'UPDATE' ) || 0 === strpos( $sql, 'INSERT INTO' ) ) ) {
			touch( w_file( 'stopped-on-claim' ) );
			harness_event( 'stop' );
		}
		$o   = w_load( 'options', array() );
		if ( 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'option_value = %s WHERE option_name = %s AND option_value = %s' ) ) {
			list( $value, $name, $was ) = $args;
			// A batch that should lose the race, for the race test.
			if ( scenario( 'lose_claim' ) ) {
				return 0;
			}
			if ( array_key_exists( $name, $o ) && maybe_serialize( $o[ $name ] ) === $was ) {
				$o[ $name ] = maybe_unserialize( $value );
				w_save( 'options', $o );
				return 1;
			}
			return 0;
		}
		if ( 0 === strpos( $sql, 'INSERT INTO' ) ) {
			list( $name, $value ) = $args;
			if ( array_key_exists( $name, $o ) ) {
				$this->last_error = "Duplicate entry '$name'";
				return false;
			}
			$o[ $name ] = maybe_unserialize( $value );
			w_save( 'options', $o );
			return 1;
		}
		return 1;
	}
	public function suppress_errors( $suppress = true ) { return false; }
	public function get_row( $q ) { return null; }
	public function get_results( $q ) {
		$rows    = array();
		$trashed = is_file( w_file( 'trashed.txt' ) ) ? file( w_file( 'trashed.txt' ), FILE_IGNORE_NEW_LINES ) : array();
		foreach ( w_load( 'linked', array() ) as $pid => $item ) {
			if ( ! in_array( (string) $pid, $trashed, true ) ) {
				$rows[] = (object) array( 'post_id' => $pid, 'ebay_id' => $item );
			}
		}
		return $rows;
	}
}
$wpdb = new Harness_wpdb();

// --- The rest of the plugin, as far as these paths go -------------------------

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { w_line( 'log', '[' . $level . '] ' . $message ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function write_now( $message, $level = 'error' ) { self::log( $message, $level ); }
}
class TCGiant_Sync_OAuth {
	public static function instance() { return new self(); }
	public function get_settings() { return (array) scenario( 'settings', array() ); }
}
class TCGiant_Sync_Entitlements {
	public static function instance() { return new self(); }
	public function can_import() { return (bool) scenario( 'can_import', true ); }
	public function get_active_product_count() { return 7; }
	public function get_free_limit() { return 0; }
	public function get_status_for_ui() { return array( 'is_pro' => true, 'active_count' => 7 ); }
}
class TCGiant_Sync_Image_Localizer {
	public static function set_external_images() {}
}
class TCGiant_Sync_Cron {
	public static function request_dispatch() { w_line( 'dispatch', (string) $GLOBALS['REQ'] ); }
}
/** The site filter: on when the scenario says, and eBay United States the shop's own. */
class TCGiant_Sync_Sites {
	const SKIP_TRANSIENT = 'tcgiant_sync_site_skip_';
	public static function primary_only() { return (bool) scenario( 'primary_only', false ); }
	public static function site_of( $item ) { return (string) ( $item['Site'] ?? '' ); }
	public static function note( $site ) {}
	public static function after_full_scan() { w_line( 'after_full_scan', (string) $GLOBALS['REQ'] ); }
	public static function record_site( $product_id, $site ) { w_line( 'record_site', (int) $product_id . ' ' . $site ); }
	public static function leaves_out( $site ) { return self::primary_only() && '' !== (string) $site && 'US' !== (string) $site; }
	public static function remembered_site() { return ''; }
	public static function remember_site() {}
}

/**
 * The product save. Each one costs the request time on the played clock, and
 * may be where the host ends the request: by a PHP fatal (out of memory), or
 * from outside, with nothing run (taskkill /F, or kill -9).
 */
class TCGiant_Sync_Mapper {
	public static $last_skipped = false;
	public static function instance() { return new self(); }
	public function map_ebay_to_woo( $item ) {
		return array( 'id' => $item['ItemID'], 'price' => '1.00', 'stock_quantity' => 1, 'attributes' => array(), 'images' => array(), 'variations' => array() );
	}
	public function save_as_product( &$data ) {
		$id = (string) $data['id'];
		$GLOBALS['SAVES_HERE']++;

		// What the screens would read at this moment.
		$state = get_option( 'tcgiant_sync_state', array() );
		w_line( 'seen', (int) ( $state['total_processed'] ?? -1 ) . ' ' . (int) ( $state['total_found'] ?? -1 ) );

		// Things that happen while this listing is saved.
		$on = (array) scenario( 'on_save', array() );
		if ( isset( $on[ $id ] ) ) {
			harness_event( $on[ $id ] );
		}

		// The host ends the request here: some listings every time, or every
		// request after so many saves.
		$deaths = w_load( 'deaths', array() );
		$die    = (array) scenario( 'die', array() );
		if ( isset( $die[ $id ] ) && (int) ( $deaths[ $id ] ?? 0 ) < (int) $die[ $id ]['times'] ) {
			$deaths[ $id ] = (int) ( $deaths[ $id ] ?? 0 ) + 1;
			w_save( 'deaths', $deaths );
			harness_die( $die[ $id ]['how'], $id );
		}
		$after = (int) scenario( 'host_kills_after', 0 );
		if ( $after > 0 && $GLOBALS['SAVES_HERE'] > $after ) {
			harness_die( 'hard', $id );
		}

		// The host ends every request this far in, from outside.
		if ( (float) scenario( 'kill_at', 0 ) > 0 && $GLOBALS['FAKE_ELAPSED'] + (float) scenario( 'cost', 0 ) >= (float) scenario( 'kill_at', 0 ) ) {
			harness_die( 'hard', $id . ' at ' . $GLOBALS['FAKE_ELAPSED'] );
		}
		$GLOBALS['FAKE_ELAPSED'] += (float) scenario( 'cost', 0 );
		if ( (int) scenario( 'memory_after', 0 ) > 0 && $GLOBALS['SAVES_HERE'] >= (int) scenario( 'memory_after', 0 ) ) {
			$GLOBALS['FAKE_MEMORY'] = (float) scenario( 'memory_to', 0.9 );
		}

		// eBay's model moves on a minute a listing.
		$model = w_load( 'model', null );
		if ( is_array( $model ) ) {
			$model['now'] += 60;
			$model['saves']++;
			// Good 'Til Cancelled listings renew as their 30 days run out: the
			// end time moves on 30 days, to the back of eBay's order.
			if ( scenario( 'gtc_renew', false ) ) {
				foreach ( $model['listings'] as $lid => $l ) {
					if ( 'Active' === $l['status'] && $l['end'] <= $model['now'] ) {
						$model['listings'][ $lid ]['end'] += 30 * DAY_IN_SECONDS;
						w_line( 'renewed', (string) $lid );
					}
				}
			}
			foreach ( (array) ( $model['events'][ $model['saves'] ] ?? array() ) as $ends ) {
				if ( 0 === strpos( (string) $ends, 'renew:' ) ) {
					$model['listings'][ substr( $ends, 6 ) ]['end'] += 30 * DAY_IN_SECONDS;
					w_line( 'renewed', substr( $ends, 6 ) );
					continue;
				}
				$model['listings'][ $ends ]['end']    = $model['now'] - 1;
				$model['listings'][ $ends ]['status'] = 'Completed';
			}
			w_save( 'model', $model );
		}

		w_line( 'saved', $GLOBALS['REQ'] . ' ' . $id );
		return 1000 + (int) substr( $id, -4 );
	}
}

/** Something that happens while a listing is saved, in another request. */
function harness_event( $what ) {
	if ( 'stop' === $what ) {
		// Emergency Stop, as its button does it.
		TCGiant_Sync_Importer::stop_all();
		TCGiant_Sync_Importer::update_sync_state( array( 'status' => 'stopped', 'scan_complete_clean' => false ) );
	} elseif ( 'slow_then_backstop' === $what ) {
		// This save takes more than four minutes: the place written before it
		// is that old when WP-Cron's backstop comes due, in another request.
		$o = w_load( 'options', array() );
		$o['tcgiant_sync_scan_progress']['ts'] -= 301;
		w_save( 'options', $o );
		w_line( 'events', 'backstop starts while request ' . $GLOBALS['REQ'] . ' is still saving' );
		$out = array();
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $GLOBALS['WORLD'] ) . ' backstop - 2>&1', $out );
		w_line( 'events', 'backstop returned: ' . implode( ' ', $out ) );
	} elseif ( 'refetch' === $what ) {
		// A newer full sync, started in another request.
		$state                    = get_option( 'tcgiant_sync_state', array() );
		$state['full_started_at'] = gmdate( 'Y-m-d\TH:i:s.000\Z', time() + 3600 );
		$state['current_page']    = 1;
		update_option( 'tcgiant_sync_state', $state );
		delete_option( 'tcgiant_sync_scan_progress' );
		delete_option( 'tcgiant_sync_scan_page' );
	}
}

/** The host ends this request. */
function harness_die( $how, $id ) {
	w_line( 'died', $GLOBALS['REQ'] . ' ' . $how . ' ' . $id );
	if ( 'fatal' === $how ) {
		// A real, uncatchable fatal: what a host's memory limit does.
		ini_set( 'memory_limit', '32M' );
		$GLOBALS['TOO_BIG'] = str_repeat( 'x', 64 * 1048576 );
	}
	// From outside, with no shutdown function run: PHP-FPM's
	// request_terminate_timeout, LiteSpeed, the out-of-memory killer.
	if ( function_exists( 'posix_kill' ) ) {
		posix_kill( getmypid(), 9 );
	} elseif ( '\\' === DIRECTORY_SEPARATOR ) {
		exec( 'taskkill /F /PID ' . getmypid() . ' 2>NUL' );
	} else {
		exec( 'kill -9 ' . getmypid() );
	}
	sleep( 30 ); // Never reached.
}

if ( 'apixml' !== $DO ) {
	/**
	 * eBay. Plain pages of listings by number - every fourth without its item
	 * specifics, so queued for GetItem, the rest imported as they are read - or,
	 * with a model, the seller's listings in the order they end, read from an
	 * end time as GetSellerList reads them.
	 */
	class TCGiant_Sync_API {
		const DAILY_CALL_LIMIT = 50000;
		public static function instance() { return new self(); }
		public function get_remaining_daily_budget() { return 40000; }
		public function get_item( $item_id ) {
			$item_id = (string) $item_id;
			w_line( 'getitem', $GLOBALS['REQ'] . ' ' . $item_id );
			// eBay's rate limit, once, for a listing a GetItem import asks about.
			if ( in_array( $item_id, (array) scenario( 'import_rate_limited', array() ), true ) && ! is_file( w_file( 'gi-limited-' . $item_id ) ) ) {
				touch( w_file( 'gi-limited-' . $item_id ) );
				return new WP_Error( 'rate_limited', 'Call limit exceeded' );
			}
			// No answer, as scripted: rate_limited, http_request_failed...
			$fails = (array) scenario( 'getitem_fails', array() );
			if ( isset( $fails[ $item_id ] ) ) {
				return new WP_Error( $fails[ $item_id ], 'eBay did not answer' );
			}
			$status = self::status_of( $item_id );
			if ( '' === $status ) {
				return new WP_Error( 'api_error', 'This item cannot be accessed because the listing has been deleted, is a Half.com listing, or you are not the seller.', array( 'Ack' => 'Failure', 'Errors' => array( 'ShortMessage' => 'Item cannot be accessed.', 'ErrorCode' => '17' ) ) );
			}
			return array( 'Item' => array( 'ItemID' => $item_id, 'Title' => 'GetItem ' . $item_id, 'SellingStatus' => array( 'ListingStatus' => $status ), 'ItemSpecifics' => array(), 'PictureDetails' => array( 'PictureURL' => 'u' ) ) );
		}
		/** A listing's status as eBay has it, or '' for one it does not have. */
		private static function status_of( $item_id ) {
			$set = (array) scenario( 'getitem_status', array() );
			if ( isset( $set[ $item_id ] ) ) {
				return (string) $set[ $item_id ];
			}
			$model = w_load( 'model', null );
			if ( is_array( $model ) ) {
				return isset( $model['listings'][ $item_id ] ) ? (string) $model['listings'][ $item_id ]['status'] : '';
			}
			$page = (int) floor( (int) $item_id / 1000 );
			$n    = (int) $item_id % 1000;
			if ( ! ctype_digit( $item_id ) || $page < 1 || $page > (int) scenario( 'pages', 1 ) || $n < 1 || $n > (int) scenario( 'per_page', 8 ) ) {
				return '';
			}
			return (string) ( ( (array) scenario( 'ended', array() ) )[ $item_id ] ?? 'Active' );
		}
		public function get_active_listings( $page, $per_page = 200, $mod_from = '', $end_from = '' ) {
			w_line( 'reads', $page . ' ' . ( '' === $end_from ? '-' : $end_from ) );
			$GLOBALS['FAKE_ELAPSED'] += (float) scenario( 'read_cost', 0 );
			if ( (float) scenario( 'kill_at', 0 ) > 0 && $GLOBALS['FAKE_ELAPSED'] >= (float) scenario( 'kill_at', 0 ) ) {
				harness_die( 'hard', 'read ' . $page );
			}
			// The host ends the request while eBay's answer is read.
			$on_read = (array) scenario( 'die_on_read', array() );
			$deaths  = w_load( 'deaths', array() );
			if ( isset( $on_read[ $page ] ) && (int) ( $deaths[ 'read' . $page ] ?? 0 ) < (int) $on_read[ $page ]['times'] ) {
				$deaths[ 'read' . $page ] = (int) ( $deaths[ 'read' . $page ] ?? 0 ) + 1;
				w_save( 'deaths', $deaths );
				harness_die( $on_read[ $page ]['how'], 'page ' . $page );
			}
			$limited = (array) scenario( 'rate_limit_pages', array() );
			if ( in_array( (int) $page, $limited, true ) && ! is_file( w_file( 'limited-' . $page ) ) ) {
				touch( w_file( 'limited-' . $page ) );
				return new WP_Error( 'rate_limited', 'Call limit exceeded' );
			}
			// Something throws while the page is read: an error no one caught.
			$throws = (array) scenario( 'throw_on_read', array() );
			if ( isset( $throws[ $page ] ) && (int) ( $deaths[ 'throw' . $page ] ?? 0 ) < (int) $throws[ $page ] ) {
				$deaths[ 'throw' . $page ] = (int) ( $deaths[ 'throw' . $page ] ?? 0 ) + 1;
				w_save( 'deaths', $deaths );
				throw new RuntimeException( 'Something threw while page ' . $page . ' was read' );
			}
			$model = w_load( 'model', null );
			if ( is_array( $model ) ) {
				return self::from_model( $model, $page, $end_from );
			}
			$pages = (int) scenario( 'pages', 1 );
			$per   = (int) scenario( 'per_page', 8 );
			if ( $page > $pages ) {
				return array( 'Ack' => 'Success' );
			}
			$sites = (array) scenario( 'sites', array() );
			$ended = (array) scenario( 'ended', array() );
			$items = array();
			for ( $i = 1; $i <= $per; $i++ ) {
				$id   = sprintf( '%d%03d', $page, $i );
				$item = array( 'ItemID' => $id, 'Title' => "p$page i$i", 'SellingStatus' => array( 'ListingStatus' => $ended[ $id ] ?? 'Active' ), 'PictureDetails' => array( 'PictureURL' => 'u' ) );
				if ( 0 !== $i % 4 ) {
					$item['ItemSpecifics'] = array();
				}
				if ( isset( $sites[ $id ] ) ) {
					$item['Site'] = $sites[ $id ];
				}
				$items[] = $item;
			}
			return array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $items ), 'PaginationResult' => array( 'TotalNumberOfPages' => $pages ) );
		}
		/** GetSellerList over the model: ending from a time, by end time, a page at a time. */
		private static function from_model( array $model, $page, $end_from ) {
			// "now": the model's clock, as a request made at that moment would send it.
			$from = ( 'fixed' === $model['window'] && '' !== (string) $end_from ) ? strtotime( $end_from ) : $model['now'];
			$rows = array();
			foreach ( $model['listings'] as $id => $l ) {
				if ( $l['end'] >= $from && $l['end'] <= $from + 120 * DAY_IN_SECONDS ) {
					$rows[] = array( 'id' => (string) $id, 'end' => $l['end'], 'status' => $l['status'] );
				}
			}
			usort( $rows, function ( $a, $b ) { return $a['end'] === $b['end'] ? strcmp( $a['id'], $b['id'] ) : $a['end'] - $b['end']; } );
			$per   = (int) $model['per_page'];
			$pages = max( 1, (int) ceil( count( $rows ) / $per ) );
			$slice = array_slice( $rows, ( $page - 1 ) * $per, $per );
			if ( empty( $slice ) ) {
				return array( 'Ack' => 'Success' );
			}
			$items = array();
			foreach ( $slice as $r ) {
				$items[] = array( 'ItemID' => $r['id'], 'Title' => 'model ' . $r['id'], 'SellingStatus' => array( 'ListingStatus' => $r['status'] ), 'ItemSpecifics' => array(), 'PictureDetails' => array( 'PictureURL' => 'u' ) );
			}
			return array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $items ), 'PaginationResult' => array( 'TotalNumberOfPages' => $pages ) );
		}
	}
}

// --- The request --------------------------------------------------------------

$SAVES_HERE   = 0;
$FAKE_ELAPSED = (float) scenario( 'start_elapsed', 0 );
$FAKE_MEMORY  = 0.0;
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );

if ( 'apixml' === $DO ) {
	// The real API class, and the GetSellerList request it would send.
	require $ROOT . '/includes/class-tcgiant-sync-api.php';
	/** The real class, with the request it would send to eBay kept rather than sent. */
	class Harness_Capture_API extends TCGiant_Sync_API {
		public $xml = '';
		public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
			$this->xml = $xml_body;
			return array();
		}
		public function xml_for( $page, $end_from, $mod_from = '' ) {
			$this->get_active_listings( $page, 200, $mod_from, $end_from );
			return $this->xml;
		}
	}
	$api = ( new ReflectionClass( 'Harness_Capture_API' ) )->newInstanceWithoutConstructor();
	echo json_encode( array(
		'fixed' => $api->xml_for( 1, '2026-10-20T08:00:00.000Z' ),
		'now'   => $api->xml_for( 1, '' ),
		'delta' => $api->xml_for( 1, '', '2026-10-21T00:00:00.000Z' ),
	) );
	exit( 0 );
}

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';
require $ROOT . '/includes/class-tcgiant-sync-importer.php';

// The played clock: each save costs what the scenario says.
TCGiant_Sync_Runtime::$clock = function () {
	// Emergency Stop pressed, in another request, just as the time budget
	// is looked at and found used up.
	if ( scenario( 'stop_as_time_runs_out', false ) && empty( $GLOBALS['STOPPED_AS_LATE'] ) && $GLOBALS['FAKE_ELAPSED'] >= 60 ) {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 4 ) as $frame ) {
			if ( 'over_time_budget' === ( $frame['function'] ?? '' ) ) {
				$GLOBALS['STOPPED_AS_LATE'] = true;
				harness_event( 'stop' );
				break;
			}
		}
	}
	return $_SERVER['REQUEST_TIME_FLOAT'] + $GLOBALS['FAKE_ELAPSED'];
};
if ( (int) scenario( 'memory_after', 0 ) > 0 || (float) scenario( 'memory_base', 0 ) > 0 ) {
	// A request that already holds memory_base of the limit, from the start.
	ini_set( 'memory_limit', '256M' );
	TCGiant_Sync_Runtime::$memory_probe = function () {
		$share = $GLOBALS['FAKE_MEMORY'] > 0 ? $GLOBALS['FAKE_MEMORY'] : (float) scenario( 'memory_base', 0 );
		return $share > 0 ? (int) ( 256 * 1048576 * $share ) : 1048576;
	};
}
// No two seconds between pages here. (Run against 3.27.5, which has no
// such seam, to see what it did, the pause stays.)
if ( property_exists( 'TCGiant_Sync_Importer', 'page_pause' ) ) {
	TCGiant_Sync_Importer::$page_pause = 0;
}

$IMP = TCGiant_Sync_Importer::instance();

switch ( $DO ) {
	case 'fetch':
		$r = $IMP->start_full_sync( true );
		echo is_wp_error( $r ) ? 'ERR:' . $r->get_error_code() . ':' . $r->get_error_message() : 'OK';
		break;
	case 'batch':
		// Action Scheduler catches what an action throws, and marks it failed.
		try {
			$IMP->scan_all_pages( $ARG );
			echo 'RETURNED';
		} catch ( Throwable $thrown ) {
			echo 'THREW: ' . $thrown->getMessage();
		}
		break;
	case 'backstop':
		$IMP->scan_all_pages();
		echo 'RETURNED';
		break;
	case 'resume':
		$IMP->resume_sync();
		echo 'RETURNED';
		break;
	case 'import':
		$IMP->process_item_import( $ARG );
		echo 'RETURNED';
		break;
	case 'delta':
		$IMP->start_delta_sync();
		echo 'RETURNED';
		break;
	case 'prune':
		// The clean-up a full sync queues as it completes.
		$IMP->prune_orphaned_items();
		echo 'RETURNED';
		break;
	case 'prune_follow_up':
		// The pruner's follow-up run, an hour on.
		$IMP->prune_orphaned_items( true );
		echo 'RETURNED';
		break;
	case 'stop':
		harness_event( 'stop' );
		echo 'STOPPED';
		break;
	case 'screen':
		echo json_encode( TCGiant_Sync_Importer::screen_progress( TCGiant_Sync_Importer::get_sync_state() ) );
		break;
	case 'poll':
		// The dashboard's status poll, for real.
		function check_ajax_referer() { return 1; }
		function current_user_can() { return true; }
		function wp_send_json_success( $data = null ) { echo json_encode( $data ); throw new RuntimeException( 'sent' ); }
		function wp_send_json_error( $data = null ) { echo json_encode( $data ); throw new RuntimeException( 'error' ); }
		require $ROOT . '/admin/class-tcgiant-sync-admin.php';
		try {
			( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor()->ajax_sync_status();
		} catch ( RuntimeException $sent ) {
			unset( $sent );
		}
		break;
}
exit( 0 );
