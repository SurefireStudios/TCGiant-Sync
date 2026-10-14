<?php
/**
 * Importer Logic
 *
 * Manages the batch import process from eBay to WooCommerce.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Importer class
 */
class TCGiant_Sync_Importer {

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * Sync state option key.
	 */
	const STATE_OPTION = 'tcgiant_sync_state';

	/**
	 * Action Scheduler group names.
	 *
	 * The three queues run in parallel so that page scanning is never stuck
	 * behind item imports or image downloads. Cancelling or querying an action
	 * requires the RIGHT group — passing the wrong one silently matches nothing,
	 * which is how "Emergency Stop" came to cancel neither imports nor images.
	 * Use these constants rather than repeating the literals.
	 */
	const GROUP_SCAN    = 'tcgiant_sync_group';
	const GROUP_IMPORTS = 'tcgiant_sync_imports';
	const GROUP_IMAGES  = 'tcgiant_sync_images';

	/**
	 * Per-listing stock checks.
	 *
	 * A group of their own because starting a sync clears the scan and import
	 * groups, and a stock check queued for a sale must survive that: it is the
	 * one thing that takes a sold item off the site while the delta is behind.
	 */
	const GROUP_SETTLE  = 'tcgiant_sync_settle';

	/**
	 * Option holding the eBay Item IDs seen during the current scan.
	 */
	const ACTIVE_IDS_OPTION = 'tcgiant_sync_active_ids';

	/**
	 * Append eBay Item IDs to the running active-ID list for this scan.
	 *
	 * Written with autoload = false. WordPress decides autoload when an option
	 * is first created, and update_option() never revisits that decision — so
	 * because page 1 creates a small option, a 10,000-item scan used to leave a
	 * ~300 KB serialized array being loaded into memory on every front-end and
	 * admin request until the scan finished and the pruner deleted it.
	 *
	 * @param array $ids eBay Item IDs seen on this page.
	 * @return void
	 */
	private static function append_active_ids( array $ids ) {
		if ( empty( $ids ) ) {
			return;
		}

		$existing = get_option( self::ACTIVE_IDS_OPTION, array() );
		$existing = is_array( $existing ) ? $existing : array();

		$merged = array_values( array_unique( array_merge( $existing, $ids ) ) );

		// Delete-then-add is the only reliable way to change an existing
		// option's autoload flag on WordPress versions before 6.4.
		if ( ! empty( $existing ) ) {
			delete_option( self::ACTIVE_IDS_OPTION );
		}
		add_option( self::ACTIVE_IDS_OPTION, $merged, '', false );
	}

	/**
	 * Cancel every queued sync job and clear all sync-related cron events.
	 *
	 * Used by the "Emergency Stop" button. Also releases the concurrency lock
	 * and clears the WP-Cron scan-resume event, without which the scan simply
	 * picks up again on the next cron tick.
	 *
	 * Image localization is deliberately left running: it finishes downloading
	 * images for products that were already imported, and is not part of the
	 * scan/import pipeline being stopped.
	 *
	 * @return void
	 */
	public static function stop_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_listings', null, self::GROUP_SCAN );
			as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages', null, self::GROUP_SCAN );
			as_unschedule_all_actions( 'tcgiant_sync_fetch_delta_events', null, self::GROUP_SCAN );
			as_unschedule_all_actions( 'tcgiant_sync_prune_orphans', null, self::GROUP_SCAN );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, self::GROUP_IMPORTS );
			as_unschedule_all_actions( 'tcgiant_sync_download_images', null, self::GROUP_IMAGES );
		}

		// The scan resumes itself through WP-Cron, not Action Scheduler.
		wp_clear_scheduled_hook( 'tcgiant_sync_scan_resume' );

		// A stopped sync starts its next window cleanly.
		self::clear_delta_progress();

		// Otherwise the next sync is blocked until the lock goes stale.
		self::release_lock();
	}

	/**
	 * Main instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * TCGiant_Sync_Importer Constructor.
	 */
	public function __construct() {
		add_action( 'tcgiant_sync_process_item_import', array( $this, 'process_item_import' ), 10, 1 );
		add_action( 'tcgiant_sync_scan_all_pages', array( $this, 'scan_all_pages' ) );
		add_action( 'tcgiant_sync_scan_resume', array( $this, 'scan_all_pages' ) ); // WP-Cron resume hook.
		add_action( 'tcgiant_sync_fetch_delta_events', array( $this, 'fetch_delta_events' ) );
		add_action( 'tcgiant_sync_prune_orphans', array( $this, 'prune_orphaned_items' ) );
		add_action( 'tcgiant_sync_settle_item', array( $this, 'settle_item' ), 10, 1 );

		// Note: 'tcgiant_sync_fetch_listings' (per-page scanning, superseded by
		// scan_all_pages in 2.0.0) and 'tcgiant_sync_download_images' (the
		// synchronous image downloader, superseded by the background localizer
		// in 3.0.0) no longer have handlers. Both are still unscheduled in
		// stop_all() and when a sync starts, so any actions left queued on an
		// upgraded site are cleared rather than retried forever.
	}

	/**
	 * Get the current sync state.
	 *
	 * @return array Sync state data.
	 */
	public static function get_sync_state() {
		return get_option( self::STATE_OPTION, array(
			'status'                  => 'idle',       // idle, scanning, importing, complete, stopped, error, rate_limited
			'sync_mode'               => 'full',       // full or delta
			'delta_mod_from'          => '',           // ISO 8601 timestamp for delta sync ModTimeFrom
			'total_found'             => 0,
			'total_queued'            => 0,
			'total_processed'         => 0,
			'total_errors'            => 0,
			'current_page'            => 0,
			'total_pages'             => 0,
			'filter_name'             => '',
			'started_at'              => '',
			'last_activity'           => '',
			'last_completed'          => '',
			'last_item_title'         => '',
			'last_successful_sync_at' => '',           // ISO 8601 UTC timestamp of last successful sync (used as ModTimeFrom for next delta)
			'rate_limit_retries'      => 0,            // Number of consecutive rate limit hits (for escalating backoff)
			'scan_complete_clean'     => false,        // True only when every page was scanned without error — gates orphan pruning
			'last_error'              => '',           // Why the last run stopped, so the dashboard can say rather than show a red dot
		) );
	}

	/**
	 * Maximum share of eBay-linked products the pruner may trash in one run.
	 *
	 * A correct scan rarely retires more than a few percent of a catalogue at
	 * once. Anything above this threshold almost certainly means the scan was
	 * incomplete, so we refuse and ask for a human decision rather than
	 * trashing the store.
	 */
	const PRUNE_MAX_RATIO = 0.20;

	/**
	 * Whether the free-limit notice has already been logged this request, so a
	 * store over the limit gets one line rather than one per listing.
	 *
	 * @var bool
	 */
	private static $limit_reported = false;

	/**
	 * How many changed items one delta run may process.
	 *
	 * Every item can cost a GetItem call, and on some accounts every single one
	 * does. Processing an unbounded list in a single request is what stalled the
	 * sync outright: a busy window returns hundreds of items, the request dies
	 * part way through, and because the run never reaches its own completion the
	 * position is never advanced and the state is never cleared.
	 */
	const DELTA_ITEMS_PER_RUN = 40;

	/**
	 * Where the delta is inside the window it is working through.
	 *
	 * Written before every listing, in an option of its own and never
	 * autoloaded: the sync state is autoloaded, and rewriting it once a listing
	 * would rewrite every autoloaded option in a persistent object cache with
	 * it. Holds: from, to, cursor (last ItemID finished), done, in_flight (the
	 * ItemID being worked on), kills, kill_item, body_bytes, ts.
	 */
	const DELTA_PROGRESS_OPTION = 'tcgiant_sync_delta_progress';

	/**
	 * The listings in the window being worked through, in brief, so that later
	 * runs need not ask eBay for all of them again. Never autoloaded.
	 */
	const DELTA_WINDOW_CACHE_OPTION = 'tcgiant_sync_delta_window';

	/**
	 * Listings the delta had to step over because importing them kept killing
	 * the request. Never autoloaded; shown on Stock Review.
	 */
	const DELTA_HELD_OPTION = 'tcgiant_sync_delta_held';

	/**
	 * Runs that may die on the same listing before it is stepped over.
	 *
	 * Two: one death can be bad luck - a slow eBay answer, a busy server - and
	 * the retry costs one listing. A second on the same listing is the listing.
	 */
	const DELTA_SLICE_KILLS = 2;

	/**
	 * Runs in a row the delta may stop on one listing eBay will not hand over
	 * before it moves past it.
	 *
	 * A stop keeps the place and tries again later, because the cause is
	 * usually eBay or the way to it - a timeout, a token that will not
	 * refresh, the relay down. A listing that fails this way run after run is
	 * moved past in the end, so one bad listing cannot hold the sync for good.
	 * Running out of calls never counts against a listing.
	 */
	const DELTA_READ_STOPS = 3;

	/**
	 * The name the delta is watched under, and its deaths recorded under.
	 */
	const DELTA_JOB = 'Delta sync';

	/**
	 * Share of the memory limit already in use at which a run hands the read
	 * to a fresh request rather than start it here. Once per window.
	 */
	const DELTA_START_MEMORY_SHARE = 0.5;

	/**
	 * Most listings held back on the review list at once.
	 */
	const DELTA_HELD_MAX = 50;

	/**
	 * Whether the delta is part way through importing a listing, for the
	 * shutdown handler.
	 *
	 * @var bool
	 */
	private static $slice_in_flight = false;

	/**
	 * Widest delta window, in minutes. eBay refuses more than 48 hours.
	 */
	const DELTA_WINDOW_MAX_MINUTES = 2880;

	/**
	 * Narrowest the window may be squeezed before we stop trying.
	 *
	 * eBay also refuses any window whose answer would exceed 3000 records,
	 * however short it is. A busy seller can breach that well inside 48 hours,
	 * and the only remedy eBay offers is to ask for less time — its own error
	 * says exactly that. Halving reaches a quarter of an hour in eight steps,
	 * which is far enough for any plausible volume.
	 */
	const DELTA_WINDOW_MIN_MINUTES = 15;

	/**
	 * The most listings eBay will put in one GetSellerEvents answer.
	 *
	 * It does not always refuse when there are more. Its documentation says
	 * that an answer of this many means some may be missing and a shorter
	 * period should be asked for - with no error, no warning and no flag. The
	 * count is the only sign, and a full answer used to be trusted and the
	 * whole period marked read, so whatever eBay left out was never seen.
	 */
	const DELTA_EBAY_MAX_ITEMS = 3000;

	/**
	 * How far each window reaches back over the end of the last one, to allow
	 * for eBay recording a change a little after it happened.
	 */
	const DELTA_OVERLAP_SECONDS = 300;

	/**
	 * How many windows one sync may read back to back before leaving the rest
	 * of a backlog to the next scheduled run.
	 *
	 * A finished window used to stop there and wait for the next run. Each one
	 * reaches back five minutes, so once a window has to be narrow that could
	 * mean reading forty minutes of changes an hour on an hourly schedule, and
	 * falling further behind every hour. So a window that was cut short goes
	 * straight on to the next, up to this many in a row.
	 */
	const DELTA_CHAIN_MAX = 24;

	/**
	 * Share of the day's eBay calls that must be left before a sync goes on to
	 * the next window by itself. The allowance is shared by every shop using
	 * the plugin, so one catching up on a long backlog must not spend it all.
	 */
	const DELTA_CHASE_BUDGET_SHARE = 0.25;

	/**
	 * How many times a request for the shortest window may be stopped before
	 * that window is stepped over.
	 *
	 * Once is not enough to go on. Hosts recycle their workers and sites are
	 * redeployed, and a request caught by either looks exactly like one that
	 * ran out of memory - but only the second kind happens every time.
	 */
	const DELTA_FLOOR_KILLS = 3;

	/**
	 * After a request is stopped while reading, how far the size ceiling is
	 * lowered (by half each time, to no less than this) and for how long.
	 */
	const DELTA_LEARNED_CEILING_MIN  = 1048576;
	const DELTA_LEARNED_CEILING_DAYS = 7;

	/**
	 * How long a sync may show no progress before it is presumed dead.
	 *
	 * The file lock has always broken itself after ten minutes, but the "already
	 * in progress" status had no such release. A run that died left that status
	 * behind for good, and every later run was turned away by it — the guard
	 * outlived the thing it was guarding.
	 */
	const SYNC_STALL_MINUTES = 30;

	/**
	 * Update sync state.
	 *
	 * @param array $updates Key-value pairs to merge into current state.
	 */
	public static function update_sync_state( $updates ) {
		$state = self::get_sync_state();

		// When the delta last moved its position, for telling a sync working
		// through a backlog from one that has stopped.
		if ( isset( $updates['last_successful_sync_at'] )
			&& (string) $updates['last_successful_sync_at'] !== (string) ( $state['last_successful_sync_at'] ?? '' ) ) {
			$updates['delta_moved_at'] = time();
		}

		$state = array_merge( $state, $updates );
		$state['last_activity'] = current_time( 'mysql' );
		// The same moment as a plain timestamp, for working out how long ago it
		// was. The line above is local time, for showing to the shop owner.
		$state['last_activity_ts'] = time();
		update_option( self::STATE_OPTION, $state );
	}

	/**
	 * How long the sync has gone without recording any progress, in seconds.
	 *
	 * This was worked out from last_activity, which is the site's local time,
	 * read back with mysql2date( 'U' ) - and that adds the site's UTC offset a
	 * second time. WordPress's own source says the format "should never be
	 * used". So the age was wrong by exactly the site's offset: on any site in
	 * the Americas a sync looked stale the moment it started, and every cron
	 * tick tore down a healthy one and began its window again from the top,
	 * while in Sydney a sync that had died was waited on for eleven and a half
	 * hours instead of thirty minutes. Only a site set to UTC behaved as meant.
	 * A plain timestamp has no timezone to get wrong.
	 *
	 * @param array $state Sync state.
	 * @return int|null Null when the state has never recorded any activity.
	 */
	public static function sync_idle_seconds( $state ) {
		// The delta notes its place before every listing without touching the
		// state, so a run working steadily through a long batch is not idle.
		$latest   = (int) ( $state['last_activity_ts'] ?? 0 );
		$progress = get_option( self::DELTA_PROGRESS_OPTION, array() );

		if ( is_array( $progress ) && ! empty( $progress['ts'] ) ) {
			$latest = max( $latest, (int) $progress['ts'] );
		}

		if ( $latest > 0 ) {
			return time() - $latest;
		}

		// Written before the timestamp existed, so convert the local time
		// properly. Anything at all written after the update adds the
		// timestamp, so this only ever reads a state from the old version. A
		// date it cannot read comes back as 1970, which reads as stalled - as
		// it did before.
		foreach ( array( 'last_activity', 'started_at' ) as $key ) {
			if ( ! empty( $state[ $key ] ) ) {
				return time() - (int) get_gmt_from_date( (string) $state[ $key ], 'U' );
			}
		}

		return null;
	}

	// ───────────────────────────────────────────────────────────────────────────
	// Concurrent execution lock — file-based with stale detection.
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Lock file path.
	 */
	private static function get_lock_file_path() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'tcgiant_sync.lock';
	}

	/**
	 * Acquire the sync lock.
	 *
	 * @param string $operation Label for which operation is acquiring (for diagnostics).
	 * @return bool True if lock acquired, false if already locked.
	 */
	/**
	 * Whether a sync claiming to be running has actually stopped making progress.
	 *
	 * @param array $state Current sync state.
	 * @return bool
	 */
	/**
	 * Whether eBay refused a request because the answer would be too large.
	 *
	 * Matched on the wording rather than an error number: eBay returns this from
	 * several different calls and the number is not consistent between them, but
	 * the sentence about a smaller result set is.
	 *
	 * @param WP_Error $error Error returned by the API layer.
	 * @return bool
	 */
	/**
	 * Is there already a product for this eBay listing?
	 *
	 * Used to tell importing a new product from updating one already imported,
	 * which the free limit treats very differently.
	 *
	 * @param string $item_id eBay Item ID.
	 * @return bool
	 */
		private static function product_exists_for_item( $item_id ) {
		global $wpdb;

		if ( empty( $item_id ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_ebay_item_id' AND pm.meta_value = %s
			   AND p.post_type IN ( 'product', 'product_variation' )
			   AND p.post_status != 'trash'
			 LIMIT 1",
			$item_id
		) );
	}

	private static function is_result_set_too_large( $error ) {
		$message = strtolower( $error->get_error_message() );

		foreach ( array( 'smaller complete result set', 'results are supported at a time' ) as $phrase ) {
			if ( false !== strpos( $message, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	private static function sync_has_stalled( $state ) {
		$idle = self::sync_idle_seconds( $state );

		// No timestamp at all means the state predates this check. Treat it as
		// stalled rather than letting it block every future run for ever.
		if ( null === $idle ) {
			return true;
		}

		return $idle > ( self::SYNC_STALL_MINUTES * MINUTE_IN_SECONDS );
	}

	private static function acquire_lock( $operation = 'sync' ) {
		$lock_file = self::get_lock_file_path();

		if ( file_exists( $lock_file ) ) {
			$lock_time = (int) file_get_contents( $lock_file );
			$age = time() - $lock_time;

			// Stale detection: if lock is older than 10 minutes, break it.
			if ( $age > 600 ) {
				TCGiant_Sync_Logger::warning( sprintf(
					'Lock: Stale lock detected (%d minutes old). Breaking lock for "%s".',
					round( $age / 60 ), $operation
				) );
				@unlink( $lock_file );
			} else {
				TCGiant_Sync_Logger::log( sprintf(
					'Lock: Cannot acquire for "%s" — already held (%d seconds ago).',
					$operation, $age
				) );
				return false;
			}
		}

		// Write lock file with current timestamp.
		$written = @file_put_contents( $lock_file, (string) time() );
		if ( false === $written ) {
			TCGiant_Sync_Logger::warning( 'Lock: Failed to write lock file — proceeding without lock.' );
			return true; // Proceed anyway; lock is advisory.
		}

		return true;
	}

	/**
	 * Release the sync lock.
	 */
	private static function release_lock() {
		$lock_file = self::get_lock_file_path();
		if ( file_exists( $lock_file ) ) {
			@unlink( $lock_file );
		}
	}

	/**
	 * Start a full sync.
	 *
	 * @param bool $force If true, bypass the "already running" guard.
	 */
	public function start_full_sync( $force = false ) {
		// License check: can the user import more products?
		$license = TCGiant_Sync_Entitlements::instance();
		// The limit caps how many products a store may hold, not whether the
		// ones it already has stay in step with eBay.
		//
		// This is the fault 3.7.12 set out to fix and did not. That release
		// corrected the per-item checks inside the import loops, but every way
		// INTO those loops still refused at the door — so the loops it fixed
		// were never reached and nothing changed. A store over the limit went
		// on receiving nothing at all: no stock, no prices, no ended listings,
		// and no pruning either, since pruning only follows a scan that ran.
		//
		// The per-item checks hold back listings that would create a new
		// product. Everything already imported carries on.
		if ( ! $license->can_import() ) {
			TCGiant_Sync_Logger::log(
				sprintf(
					'Free tier limit reached (%d/%d active products). Products already imported carry on syncing; only new listings are held back. Upgrade to TCGiant Sync Pro for unlimited imports.',
					$license->get_active_product_count(),
					$license->get_free_limit()
				),
				'warning'
			);
		}

		// Guard: don't restart if a sync is already in progress (prevents cron overlap).
		if ( ! $force ) {
			$current = self::get_sync_state();
			if ( in_array( $current['status'], array( 'scanning', 'importing' ), true ) ) {
				TCGiant_Sync_Logger::log( 'Sync already in progress - skipping duplicate request.' );
				return new WP_Error(
					'already_running',
					__( 'A sync is already running.', 'tcgiant-sync' )
				);
			}
		}

		// File lock: prevent concurrent execution from overlapping cron triggers.
		//
		// This is why "force" is not absolute: a scan holding the lock keeps it
		// until it finishes or the lock goes stale, so the caller has to be told
		// the request was declined rather than shown a success message.
		if ( ! self::acquire_lock( 'full_sync' ) ) {
			return new WP_Error(
				'sync_locked',
				__( 'Another sync is currently running. Wait for it to finish, or use Emergency Stop first.', 'tcgiant-sync' )
			);
		}

		// Clear any previous pending sync jobs to prevent stacking.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_listings', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages', null, 'tcgiant_sync_group' );
			wp_clear_scheduled_hook( 'tcgiant_sync_scan_resume' );
			as_unschedule_all_actions( 'tcgiant_sync_fetch_delta_events', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_imports' );
			as_unschedule_all_actions( 'tcgiant_sync_download_images', null, 'tcgiant_sync_images' );
		}

		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$filter_name = ! empty( $settings['category_ids'] ) ? $settings['category_ids'] : 'All Categories';

		// Clear cached category ID transient so we get fresh resolution.
		if ( ! empty( $settings['category_ids'] ) ) {
			delete_transient( 'tcgiant_sync_cats_' . md5( $settings['category_ids'] ) );
		}

		// Reset sync state.
		self::update_sync_state( array(
			'status'              => 'scanning',
			'sync_mode'           => 'full',
			'delta_mod_from'      => '',
			'total_found'         => 0,
			'total_queued'        => 0,
			'total_processed'     => 0,
			'total_errors'        => 0,
			'current_page'        => 1,
			'total_pages'         => 0,
			'filter_name'         => $filter_name,
			'started_at'          => current_time( 'mysql' ),
			// Where the delta picks up once this finishes - see full_sync_position().
			'full_started_at'     => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'last_item_title'     => '',
			'rate_limit_retries'  => 0,
			'scan_complete_clean' => false,
			'delta_paused_for'    => '',
			'delta_paused_code'   => '',
			'delta_paused_until'  => 0,
		) );

		// Clear active IDs list for pruning orphaned items.
		delete_option( self::ACTIVE_IDS_OPTION );

		$api = TCGiant_Sync_API::instance();
		TCGiant_Sync_Logger::log( sprintf(
			'Starting full sync for: %s (API budget: %d/%d calls remaining today)',
			$filter_name, $api->get_remaining_daily_budget(), TCGiant_Sync_API::DAILY_CALL_LIMIT
		) );
		as_enqueue_async_action( 'tcgiant_sync_scan_all_pages', array(), 'tcgiant_sync_group' );

		return true;
	}

	/**
	 * Start a delta sync — only import items modified since the last successful sync.
	 *
	 * Falls back to a full sync if there is no previous sync timestamp (e.g., first run).
	 */
	public function start_delta_sync() {
		$state = self::get_sync_state();
		$last_sync = ! empty( $state['last_successful_sync_at'] ) ? $state['last_successful_sync_at'] : '';

		// No previous sync? Fall back to full sync.
		if ( empty( $last_sync ) ) {
			TCGiant_Sync_Logger::log( 'No previous sync timestamp found — falling back to full sync.' );
			$this->start_full_sync();
			return;
		}

		// No licence check here. Being over the limit holds back new listings,
		// which the per-item check in run_delta_events() does; it is not a
		// reason to stop updating the products a store already has.

		// Guard: don't restart if a sync is already in progress.
		//
		// Nor if its next run is waiting in the queue, however long that has
		// taken. On a quiet site whose scheduled tasks only run when someone
		// visits, or behind a long queue of image downloads, a live sync can
		// go well past thirty minutes between runs - and tearing it down then
		// begins its window again from the top, so a large one never finished.
		if ( in_array( $state['status'], array( 'scanning', 'importing' ), true ) ) {
			if ( ! self::sync_has_stalled( $state ) || self::delta_run_queued() ) {
				TCGiant_Sync_Logger::log( 'Sync already in progress — skipping delta sync request.' );
				return;
			}

			TCGiant_Sync_Logger::warning( sprintf(
				'Previous sync has shown no progress for over %d minutes and is presumed to have failed. Starting a fresh one.',
				self::SYNC_STALL_MINUTES
			) );
			self::release_lock();
		}

		// File lock: prevent concurrent execution.
		if ( ! self::acquire_lock( 'delta_sync' ) ) {
			return;
		}

		// Clear any previous pending sync jobs.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_listings', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages', null, 'tcgiant_sync_group' );
			wp_clear_scheduled_hook( 'tcgiant_sync_scan_resume' );
			as_unschedule_all_actions( 'tcgiant_sync_fetch_delta_events', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_imports' );
			as_unschedule_all_actions( 'tcgiant_sync_download_images', null, 'tcgiant_sync_images' );
		}

		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$filter_name = ! empty( $settings['category_ids'] ) ? $settings['category_ids'] : 'All Categories';

		// Add a small overlap buffer (5 min) to avoid missing items due to clock skew.
		$buffer_seconds = self::DELTA_OVERLAP_SECONDS;
		$mod_from_ts = strtotime( $last_sync ) - $buffer_seconds;
		$mod_from_iso = gmdate( 'Y-m-d\TH:i:s.000\Z', $mod_from_ts );

		// Restarting the same window keeps its place. A restart used to begin
		// again from the first listing, so a window whose runs kept dying was
		// worked from the top every hour and never got further.
		$progress = self::delta_progress();
		if ( ! empty( $progress ) && (string) ( $progress['from'] ?? '' ) !== $mod_from_iso ) {
			self::clear_delta_progress();
		}

		self::update_sync_state( array(
			'status'          => 'scanning',
			'sync_mode'       => 'delta',
			'delta_mod_from'  => $mod_from_iso,
			'total_found'     => 0,
			'total_queued'    => 0,
			'total_processed' => 0,
			'total_errors'    => 0,
			'current_page'    => 1,
			'total_pages'     => 0,
			'filter_name'     => $filter_name,
			'started_at'      => current_time( 'mysql' ),
			'last_item_title' => '',
			// A delta sync never builds a complete active-ID list, so it must
			// never leave the pruner authorised.
			'scan_complete_clean' => false,
			// Start of a new window, so start of the item list.
			'delta_offset'        => 0,
			'delta_cursor'        => '',
			'delta_window_from'   => '',
			'delta_window_to'     => '',
			'delta_chain'         => 0,
			'delta_paused_for'    => '',
			'delta_paused_code'   => '',
			'delta_paused_until'  => 0,
			// delta_attempt is deliberately kept. It is how the first run of a
			// restarted sync learns that the last one died reading this window.
			'last_activity'       => current_time( 'mysql' ),
		) );

		TCGiant_Sync_Logger::log( sprintf(
			'Starting delta sync for: %s (changes since %s)',
			$filter_name,
			$mod_from_iso
		) );
		as_enqueue_async_action( 'tcgiant_sync_fetch_delta_events', array(), 'tcgiant_sync_group' );
	}

	/**
	 * Start a sync for specific item IDs.
	 *
	 * @param array $item_ids List of eBay Item IDs to sync.
	 */
	public function start_specific_sync( $item_ids ) {
		// No licence check here either. Someone asking for particular items by
		// hand is usually asking about ones they already have, and refusing
		// outright meant they could not refresh anything at all.

		$state = self::get_sync_state();
		$new_queued = $state['total_queued'] + count( $item_ids );

		$update = array(
			'total_queued' => $new_queued,
			// Syncing a hand-picked set of items says nothing about which
			// listings are still active, so it must not authorise pruning.
			'scan_complete_clean' => false,
		);

		// Marked as its own kind of sync, unless one is already running - in
		// which case these items simply join it. Taking over a running delta
		// sync's status let check_sync_completion() finish it early, part way
		// through a window, and a scheduled run then started that window again
		// from the top.
		if ( ! in_array( $state['status'], array( 'scanning', 'importing' ), true ) ) {
			$update['status']    = 'importing';
			$update['sync_mode'] = 'specific';
		}

		self::update_sync_state( $update );

		foreach ( $item_ids as $item_id ) {
			as_enqueue_async_action( 'tcgiant_sync_process_item_import', array( 'item_id' => $item_id ), 'tcgiant_sync_imports' );
		}

		TCGiant_Sync_Logger::log( sprintf( 'Manually queued %d specific item(s) for sync.', count( $item_ids ) ), 'success' );
	}

	/**
	 * Re-sync images only for specific eBay Item IDs.
	 *
	 * Fetches image URLs from eBay via GetItem but does NOT update
	 * product data (title, price, stock, etc). Only re-downloads images.
	 *
	 * @param array $item_ids List of eBay Item IDs.
	 */
	public function start_images_only_sync( $item_ids ) {
		$api = TCGiant_Sync_API::instance();
		$mapper = TCGiant_Sync_Mapper::instance();
		$queued = 0;

		foreach ( $item_ids as $item_id ) {
			$item_id = trim( $item_id );
			if ( empty( $item_id ) ) {
				continue;
			}

			// Find the existing WooCommerce product for this eBay item.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$product_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ebay_item_id' AND meta_value = %s LIMIT 1",
				$item_id
			) );

			if ( ! $product_id ) {
				TCGiant_Sync_Logger::warning( sprintf( 'Images-only sync: No WooCommerce product found for eBay Item %s. Skipping.', $item_id ) );
				continue;
			}

			// Fetch the item from eBay to get current image URLs.
			$ebay_response = $api->get_item( $item_id );
			if ( is_wp_error( $ebay_response ) || ! isset( $ebay_response['Item'] ) ) {
				$msg = is_wp_error( $ebay_response ) ? $ebay_response->get_error_message() : 'No item data returned';
				TCGiant_Sync_Logger::error( sprintf( 'Images-only sync: Failed to fetch eBay Item %s: %s', $item_id, $msg ) );
				continue;
			}

			$ebay_item = $ebay_response['Item'];

			// Extract main product images.
			$all_images = array();
			if ( isset( $ebay_item['PictureDetails']['PictureURL'] ) ) {
				$pics = $ebay_item['PictureDetails']['PictureURL'];
				$all_images = is_array( $pics ) ? $pics : array( $pics );
			}

			// Extract variation-specific images — map them to WC variation IDs.
			if ( isset( $ebay_item['Variations'] ) ) {
				$product_data = $mapper->map_ebay_to_woo( $ebay_item );
				// We need to call save_as_product to get variation_ids populated,
				// but we don't want to update product data.
				// Instead, match existing variations by SKU.
				if ( ! empty( $product_data['variations'] ) ) {
					foreach ( $product_data['variations'] as $var ) {
						if ( ! empty( $var['image_url'] ) && ! empty( $var['sku'] ) ) {
							$var_wc_id = wc_get_product_id_by_sku( $var['sku'] );
							if ( $var_wc_id ) {
								$all_images[] = array( $var['image_url'], $var_wc_id );
							}
						}
					}
				}
			}

			if ( empty( $all_images ) ) {
				TCGiant_Sync_Logger::log( sprintf( 'Images-only sync: No images found on eBay for Item %s.', $item_id ) );
				continue;
			}

			// Set external eBay image URLs — localized in background.
			TCGiant_Sync_Image_Localizer::set_external_images( (int) $product_id, $all_images );

			$queued++;
			TCGiant_Sync_Logger::log( sprintf(
				'Images-only sync: Queued %d image(s) for WC #%d (eBay %s).',
				count( $all_images ), $product_id, $item_id
			) );
		}

		TCGiant_Sync_Logger::log( sprintf( 'Images-only sync: Queued image downloads for %d product(s).', $queued ), 'success' );
	}

	/**
	 * Fetch modified items via GetSellerEvents and process them directly.
	 *
	 * Wrapper only. start_delta_sync() takes the lock and then hands off to this
	 * asynchronously, so this is where it has to be given back — on every exit,
	 * including the early returns and anything thrown. It never was, so each run
	 * left the file behind for the next one to report stale and break. That was
	 * merely noisy at a 15 minute interval, but on a shorter one every run after
	 * the first was refused the lock and skipped in silence, and a manual Fetch
	 * Inventory within ten minutes of a delta sync was refused for the same
	 * reason.
	 */
	public function fetch_delta_events() {
		// A run PHP kills leaves a line saying how, and on which listing, where
		// it used to leave nothing at all - the lines that would have said so
		// were still in the log buffer.
		TCGiant_Sync_Runtime::watch( self::DELTA_JOB, array( __CLASS__, 'on_delta_death' ) );

		try {
			$this->run_delta_events();
		} finally {
			TCGiant_Sync_Runtime::unwatch();
			self::$slice_in_flight = false;
			self::release_lock();
		}
	}

	/**
	 * PHP has killed a delta run. Runs on shutdown, so it must allocate little.
	 *
	 * The `finally` above never runs after a fatal, so the lock is released
	 * here. And a run killed while importing a listing hands straight on to the
	 * next, instead of leaving the sync to sit until the hourly poll decides it
	 * is dead: that run finds the listing it died on, tries it once more, and
	 * steps over it if it dies again, so this cannot go round for ever.
	 *
	 * @param string $message What the death record said.
	 */
	public static function on_delta_death( $message ) {
		self::release_lock();

		if ( ! self::$slice_in_flight || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		// Not after Emergency Stop, which the dying run may not have seen.
		if ( self::stop_pressed() ) {
			return;
		}

		// Pending, not merely queued: Action Scheduler still counts the action
		// this request was running. When the host ends a request without a
		// PHP error - a time limit of its own - nothing marks that action
		// finished for five minutes, so "queued" was true, nothing was handed
		// on, and the sync sat until the hourly poll found it idle.
		if ( ! self::delta_run_pending() ) {
			as_enqueue_async_action( 'tcgiant_sync_fetch_delta_events', array(), self::GROUP_SCAN );
		}
	}

	/**
	 * Fetch modified items via GetSellerEvents and process them directly.
	 *
	 * GetSellerEvents says which listings changed in a period, but in practice
	 * without their item specifics or full pictures, so most changed listings
	 * still cost a GetItem each - see the fallback in the loop. Max time window
	 * is 48 hours per eBay API rules.
	 */
	private function run_delta_events() {
		// Raised before the fetch, which is the slow part. It used to be raised
		// a hundred lines further down, after the download and the parse had
		// already run under whatever limit the host set.
		TCGiant_Sync_Runtime::raise_time_limit( 300 );

		$state = self::get_sync_state();
		$mod_from = $state['delta_mod_from'] ?? '';

		if ( empty( $mod_from ) ) {
			TCGiant_Sync_Logger::error( 'Delta events: No delta_mod_from timestamp in state.' );
			self::update_sync_state( array(
				'status'                  => 'complete',
				'last_completed'          => current_time( 'mysql' ),
				'last_successful_sync_at' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			) );
			return;
		}

		// How much time to ask for. Starts at eBay's maximum and is cut down
		// whenever the answer would be too large — see the handling below.
		$window_minutes = (int) ( $state['delta_window_minutes'] ?? self::DELTA_WINDOW_MAX_MINUTES );
		$window_minutes = max( self::DELTA_WINDOW_MIN_MINUTES, min( self::DELTA_WINDOW_MAX_MINUTES, $window_minutes ) );

		// The last run to ask for exactly this window never came back.
		//
		// A request that runs out of memory or time is stopped dead: no error
		// reaches this code, nothing is written, and the next run asked for the
		// same window and died the same way, for ever. That is what a shop on
		// WordPress.com was seeing - the action failing after several minutes,
		// over and over, with its newest listings never arriving. So every fetch
		// notes what it is about to ask for and clears the note once eBay has
		// answered. Finding the note still here means the request was killed
		// while reading, and the only cure in our hands is to ask for less. The
		// size ceiling comes down with it, for a while: whatever stopped the
		// host, it stopped it below the ceiling we had set, and otherwise the
		// window would widen straight back into the same wall.
		$kills         = 0;
		$died_on_floor = false;

		if ( self::delta_attempt_key( $mod_from, $window_minutes ) === (string) ( $state['delta_attempt'] ?? '' ) ) {
			$kills = (int) ( $state['delta_attempt_kills'] ?? 0 ) + 1;

			if ( $window_minutes > self::DELTA_WINDOW_MIN_MINUTES ) {
				$asked    = min( $window_minutes, max( 1, (int) ( $state['delta_attempt_span'] ?? $window_minutes ) ) );
				$narrower = self::narrower_delta_window( $window_minutes, $asked );
				$ceiling  = max( self::DELTA_LEARNED_CEILING_MIN, (int) floor( self::delta_byte_ceiling( $state ) / 2 ) );

				TCGiant_Sync_Logger::warning( sprintf(
					'The last attempt to read %d minutes of changes from eBay was stopped before it finished, usually by the host for using too much memory or time. Asking for %d minutes instead, and reading no more than %d MB at a time for the next %d days — the sync position is unchanged, so nothing is skipped.',
					$asked,
					$narrower,
					max( 1, (int) round( $ceiling / 1048576 ) ),
					self::DELTA_LEARNED_CEILING_DAYS
				) );

				self::update_sync_state( array_merge( self::fresh_delta_window(), array(
					'delta_window_minutes'     => $narrower,
					'delta_byte_ceiling'       => $ceiling,
					'delta_byte_ceiling_until' => time() + self::DELTA_LEARNED_CEILING_DAYS * DAY_IN_SECONDS,
				) ) );

				$window_minutes = $narrower;
				$kills          = 0;
				$state          = self::get_sync_state();
			} elseif ( $kills >= self::DELTA_FLOOR_KILLS ) {
				$died_on_floor = true;
			} else {
				TCGiant_Sync_Logger::warning( sprintf(
					'The last attempt to read the %d minutes of changes from %s was stopped before it finished. Trying again (attempt %d of %d) before stepping over them.',
					self::DELTA_WINDOW_MIN_MINUTES,
					$mod_from,
					$kills + 1,
					self::DELTA_FLOOR_KILLS
				) );
			}
		}

		// Carrying on through a window keeps the end it started with. It used to
		// become "now" again on every run, so the window grew between runs while
		// the place in it was still counted from the top. The window's start is
		// stored with its end, so a place belonging to some other window is
		// never applied to this one. A sync updated part way through a window
		// has no record of it either way, and carries on exactly as it did.
		//
		// The place is kept per listing, not per batch of forty, and outlives a
		// restart of the same window. Before, a run that died anywhere in its
		// batch had saved nothing, the hourly restart wiped what an earlier
		// batch had saved, and the next run began the window again from its
		// first listing - every hour, for ever. Two shops sat on one window
		// that way, one for eleven days.
		$progress = self::delta_progress();

		if ( empty( $progress ) ) {
			$progress = self::legacy_delta_progress( $state, $mod_from );
		}

		$resuming = (string) ( $progress['from'] ?? '' ) === $mod_from && '' !== (string) ( $progress['to'] ?? '' );

		if ( ! $resuming && ! empty( $progress ) ) {
			self::clear_delta_progress();
			$progress = array();
		}

		// The last run died while importing a listing - after eBay had
		// answered, so the read note above says nothing about it.
		if ( $resuming && '' !== (string) ( $progress['in_flight'] ?? '' ) ) {
			$progress = self::after_slice_death( $progress, $mod_from );
		}

		$cursor    = $resuming ? (string) ( $progress['cursor'] ?? '' ) : '';
		$offset    = $resuming ? max( 0, (int) ( $progress['done'] ?? 0 ) ) : 0;
		$pinned_to = $resuming ? (string) $progress['to'] : '';

		// A sync updated from 3.25 part way through a window kept only a count,
		// and no record of which window. It carries on by that count, as it
		// always did.
		if ( ! $resuming && '' === (string) ( $state['delta_window_from'] ?? '' ) ) {
			$offset = max( 0, (int) ( $state['delta_offset'] ?? 0 ) );
		}

		// Carrying on through a window already read: its listings were kept,
		// in brief, the first time, so eBay is not asked for all of them again.
		// Every run used to re-download and re-parse the whole window to work
		// on forty of it.
		$cached     = $resuming ? self::cached_delta_rows( $mod_from, $pinned_to ) : null;
		$from_cache = null !== $cached;

		// A request already heavy before eBay has even been asked - the hourly
		// poll shares its request with the order sync and anything else due -
		// hands the read to a fresh one. Once per window, so it cannot bounce.
		if ( ! $from_cache
			&& TCGiant_Sync_Runtime::memory_share() > self::DELTA_START_MEMORY_SHARE
			&& $mod_from !== (string) ( $state['delta_fresh_request_for'] ?? '' ) ) {
			TCGiant_Sync_Logger::log( sprintf(
				'This request was already using %d%% of the memory limit before reading eBay, so the read moves to a fresh request.',
				(int) round( TCGiant_Sync_Runtime::memory_share() * 100 )
			) );
			self::continue_delta( array( 'delta_fresh_request_for' => $mod_from ) );
			return;
		}

		$from_ts = strtotime( $mod_from );
		$window  = $window_minutes * 60;

		if ( $resuming ) {
			$mod_to = $pinned_to;
		} else {
			$mod_to = gmdate( 'Y-m-d\TH:i:s.000\Z' );

			if ( ( strtotime( $mod_to ) - $from_ts ) > $window ) {
				$mod_to = gmdate( 'Y-m-d\TH:i:s.000\Z', $from_ts + $window );
				TCGiant_Sync_Logger::log( sprintf(
					'Reading changes from %s to %s this run (a %d minute window); the next sync resumes from there.',
					$mod_from, $mod_to, $window_minutes
				), 'warning' );
			}
		}

		// How much time this run actually asks for, which is less than the
		// window whenever the sync is nearly caught up. Narrowing halves this,
		// not the window: a shop polled hourly with a 48-hour window used to
		// need six refusals, each one downloaded in full, before the window was
		// shorter than the hour it was actually asking about.
		$span_minutes = max( 1, (int) ceil( ( strtotime( $mod_to ) - $from_ts ) / 60 ) );

		// Cut short by the window rather than reaching the present, so there is
		// more after it still to read.
		$clamped = ( strtotime( $mod_to ) - $from_ts ) >= $window;

		// Already at the shortest window, and the request reading it has been
		// killed every time it was tried - without a size ceiling, so trying
		// again would only die again. Refusing to move would stop the sync for
		// good, which is worse than stepping over fifteen minutes and saying
		// so, as the refusal below does when eBay will not answer at all.
		if ( $died_on_floor ) {
			TCGiant_Sync_Logger::error( sprintf(
				'The changes between %s and %s could not be read even %d minutes at a time; each of %d attempts was stopped before it finished. Moving past them so syncing continues — run Fetch Inventory to pick up anything altered during that period.',
				$mod_from,
				$mod_to,
				self::DELTA_WINDOW_MIN_MINUTES,
				$kills
			) );

			self::update_sync_state( array_merge( self::fresh_delta_window(), array(
				'status'                  => 'complete',
				'last_completed'          => current_time( 'mysql' ),
				'last_successful_sync_at' => $mod_to,
				'delta_attempt'           => '',
				'delta_attempt_kills'     => 0,
			) ) );
			return;
		}

		$api = TCGiant_Sync_API::instance();

		// No ceiling once the window is as short as it goes. There is nothing
		// narrower to ask for, so the choice is between trying and skipping,
		// and the ceiling sits well below what would actually fail.
		$ceiling    = self::delta_byte_ceiling( $state );
		$byte_limit = $window_minutes > self::DELTA_WINDOW_MIN_MINUTES ? $ceiling : 0;

		if ( $from_cache ) {
			// Shaped as eBay's answer, so everything below reads it the same way.
			$response   = array( 'Ack' => 'Success', 'ItemArray' => array( 'Item' => $cached ) );
			$body_bytes = (int) ( $progress['body_bytes'] ?? 0 );
			unset( $cached );
		} else {
			self::update_sync_state( array(
				'delta_attempt'       => self::delta_attempt_key( $mod_from, $window_minutes ),
				'delta_attempt_kills' => $kills,
				'delta_attempt_span'  => $span_minutes,
			) );

			$response   = $api->get_seller_events( $mod_from, $mod_to, $byte_limit );
			$body_bytes = $api->get_last_body_bytes();

			// Answered, one way or another, so this request was not killed reading it.
			$answered = array(
				'delta_attempt'       => '',
				'delta_attempt_kills' => 0,
			);

			// Stop pressed while eBay was being read - the slow part of a run.
			// This request's copy of the state predates it, and writing the
			// note above into that copy put "scanning" back over the Stop.
			// The note is cleared in the same write: left behind, the next
			// run would take the read for one the host had killed.
			if ( self::stop_pressed() ) {
				$answered['status'] = 'stopped';
				self::update_sync_state( $answered );
				TCGiant_Sync_Logger::log( 'Delta sync stopped as requested. It starts again at the next scheduled sync.' );
				return;
			}

			self::update_sync_state( $answered );
		}

		// Handle rate limiting.
		if ( is_wp_error( $response ) && 'rate_limited' === $response->get_error_code() ) {
			$state_for_retry = self::get_sync_state();
			$paused_code     = self::is_out_of_calls( $response ) ? 'out_of_calls' : 'rate_limited';
			$retries         = self::pauses_in_a_row( $state_for_retry, $paused_code, false );
			$retry_delay     = $this->get_rate_limit_backoff_delay( $retries );
			self::update_sync_state( array(
				'status'             => 'rate_limited',
				'rate_limit_retries' => $retries + 1,
				'delta_paused_for'   => $response->get_error_message(),
				'delta_paused_code'  => $paused_code,
				'delta_paused_until' => time() + $retry_delay,
			) );
			TCGiant_Sync_Logger::log( sprintf(
				'eBay API rate limit hit during delta events fetch (attempt %d). Auto-retry in %d minutes.',
				$retries + 1, $retry_delay / 60
			), 'warning' );
			as_schedule_single_action( time() + $retry_delay, 'tcgiant_sync_fetch_delta_events', array(), 'tcgiant_sync_group' );
			return;
		}

		// More than this site can safely read at once - or so much that eBay
		// could not send it before the request timed out, which used to retry
		// the identical window for ever. The remedy for both is the same as for
		// eBay's own refusal below - ask for less time - but kept apart from it,
		// because that branch steps over the period once the window can narrow
		// no further, and that must never happen to changes that are sitting
		// there to be read. At the shortest window, which is fetched without a
		// ceiling, a timeout falls to the general error handling instead, which
		// holds the position and tries again on the next run.
		$too_large = is_wp_error( $response ) && 'response_too_large' === $response->get_error_code();
		$timed_out = is_wp_error( $response ) && 'http_request_failed' === $response->get_error_code()
			&& false !== stripos( $response->get_error_message(), 'timed out' );

		if ( ( $too_large || $timed_out ) && $window_minutes > self::DELTA_WINDOW_MIN_MINUTES ) {
			$narrower = self::narrower_delta_window( $window_minutes, $span_minutes );

			if ( $too_large ) {
				TCGiant_Sync_Logger::log( sprintf(
					'The changes for those %d minutes come to more than %d MB, which is more than this site can safely read at once with a %s memory limit. Asking for %d minutes instead and trying again — the sync position is unchanged, so nothing is skipped.',
					$span_minutes,
					max( 1, (int) round( $byte_limit / 1048576 ) ),
					(string) ini_get( 'memory_limit' ),
					$narrower
				), 'warning' );
			} else {
				TCGiant_Sync_Logger::log( sprintf(
					'eBay did not finish sending the changes for those %d minutes before the request timed out. Asking for %d minutes instead and trying again — the sync position is unchanged, so nothing is skipped.',
					$span_minutes,
					$narrower
				), 'warning' );
			}

			self::continue_delta( array_merge( self::fresh_delta_window(), array(
				'status'               => 'complete',
				'last_completed'       => current_time( 'mysql' ),
				'delta_window_minutes' => $narrower,
			) ) );
			return;
		}

		// eBay refuses any window whose answer would exceed 3000 records, and
		// says to ask for less time. Retrying the same window can only ever earn
		// the same refusal, so a busy period stopped the sync outright: the
		// position is held (rightly — nothing may be skipped), the window is
		// recalculated identically, and eBay says no again. One seller sat five
		// days behind exactly this way, with no new listings arriving at all.
		//
		// So halve the window and try again.
		if ( is_wp_error( $response ) && self::is_result_set_too_large( $response ) ) {

			if ( $window_minutes > self::DELTA_WINDOW_MIN_MINUTES ) {
				$narrower = self::narrower_delta_window( $window_minutes, $span_minutes );

				TCGiant_Sync_Logger::log( sprintf(
					'eBay will not return that many changes at once. Asking for %d minutes instead of %d and trying again — the sync position is unchanged, so nothing is skipped.',
					$narrower,
					$span_minutes
				), 'warning' );

				self::continue_delta( array_merge( self::fresh_delta_window(), array(
					'status'               => 'complete',
					'last_completed'       => current_time( 'mysql' ),
					'last_activity'        => current_time( 'mysql' ),
					'delta_window_minutes' => $narrower,
				) ) );
				return;
			}

			// Already at the shortest window we are prepared to ask for, and
			// still too much. Refusing to move would deadlock for ever, which is
			// worse than stepping over it and saying so plainly.
			TCGiant_Sync_Logger::error( sprintf(
				'eBay will not return the changes between %s and %s even at a %d minute window. Moving past it so syncing continues — run Fetch Inventory to pick up anything altered during that period.',
				$mod_from,
				$mod_to,
				self::DELTA_WINDOW_MIN_MINUTES
			) );

			self::update_sync_state( array_merge( self::fresh_delta_window(), array(
				'status'                  => 'complete',
				'last_completed'          => current_time( 'mysql' ),
				'last_activity'           => current_time( 'mysql' ),
				'last_successful_sync_at' => $mod_to,
			) ) );
			return;
		}

		if ( is_wp_error( $response ) ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Delta events API error: %s. Keeping the sync position at %s so this window is retried — nothing changed on eBay meanwhile will be missed.',
				$response->get_error_message(),
				$mod_from
			) );

			// Deliberately does NOT advance last_successful_sync_at. It used to,
			// which meant every failed run moved the start of the next window
			// past a period that had never been read. Anything listed or changed
			// on eBay during an outage — an expired token, a relay problem — fell
			// into a gap that no later delta sync would ever look at again, and
			// only a full Fetch Inventory could recover.
			//
			// Why, for the notice, when eBay or the way to it was out of
			// action: a token that has died shows here first on a shop that
			// is up to date, hour after hour, and the notice had no reason to
			// give - nor the pointer to reconnect.
			$passing = self::is_passing_read_failure( $response );
			self::update_sync_state( array(
				'status'            => 'complete',
				'last_completed'    => current_time( 'mysql' ),
				'delta_paused_for'  => $passing ? $response->get_error_message() : '',
				'delta_paused_code' => $passing ? (string) $response->get_error_code() : '',
			) );
			return;
		}

		// Parse items from response.
		$items = array();
		if ( isset( $response['ItemArray']['Item'] ) ) {
			$items = $response['ItemArray']['Item'];
			if ( isset( $items['ItemID'] ) ) {
				$items = array( $items ); // Single item — normalize to array.
			}
		}

		// Only this run's slice of the items is kept below. The rest of the
		// answer used to stay held through the whole import loop, the largest
		// single thing in memory while forty products were saved.
		unset( $response );

		if ( empty( $items ) ) {
			TCGiant_Sync_Logger::log( 'Delta sync complete. No items modified since last sync.' );

			$chase = self::delta_chase_state( $state, $mod_to, $clamped );

			// fresh_delta_window() matters here: a window can come back empty
			// part way through, once everything left in it has changed again
			// and moved on to a later one, and its place must not carry over.
			self::update_sync_state( array_merge( self::fresh_delta_window(), array(
				'status'                  => 'complete',
				'last_completed'          => current_time( 'mysql' ),
				'last_successful_sync_at' => $mod_to,
				'delta_window_minutes'    => self::next_delta_window_minutes( $window_minutes, $mod_to, 0, $body_bytes, $ceiling ),
				'rate_limit_retries'      => 0,
			), $chase ) );

			if ( $chase ) {
				as_enqueue_async_action( 'tcgiant_sync_fetch_delta_events', array(), self::GROUP_SCAN );
			}
			return;
		}

		$total_items = count( $items );
		$first_run   = '' === $cursor && 0 === $offset;

		// eBay's answer was full, so it may not be all of it - see
		// DELTA_EBAY_MAX_ITEMS. Read the period again in shorter pieces rather
		// than mark it done. This copies the narrowing in the refusal branch
		// above but not the step-over beneath it: there, eBay sent nothing,
		// while here every one of these listings is real and in hand.
		if ( $total_items >= self::DELTA_EBAY_MAX_ITEMS ) {
			if ( $window_minutes > self::DELTA_WINDOW_MIN_MINUTES ) {
				$narrower = self::narrower_delta_window( $window_minutes, $span_minutes );

				TCGiant_Sync_Logger::log( sprintf(
					'eBay sent its maximum of %d changed listings for those %d minutes, which means some may have been left out. Asking for %d minutes instead and reading them again — the sync position is unchanged, so nothing is skipped.',
					$total_items,
					$span_minutes,
					$narrower
				), 'warning' );

				self::continue_delta( array_merge( self::fresh_delta_window(), array(
					'status'               => 'complete',
					'last_completed'       => current_time( 'mysql' ),
					'delta_window_minutes' => $narrower,
				) ) );
				return;
			}

			// Nothing shorter to ask for. Import what eBay did send and say
			// plainly that there may be more - once, not on every run through.
			if ( $first_run ) {
				TCGiant_Sync_Logger::warning( sprintf(
					'eBay sent its maximum of %d changed listings even for the %d minutes from %s, so some changes in that period may not have been sent. Importing the ones that were — run Fetch Inventory to pick up the rest.',
					$total_items,
					self::DELTA_WINDOW_MIN_MINUTES,
					$mod_from
				) );
			}
		}

		// Work through the window a slice at a time, resuming where the last run
		// finished. A single request cannot be relied on to get through hundreds
		// of items when each one may need its own eBay lookup.
		//
		// Resuming by position only works if the list is in the same order every
		// time, and eBay does not promise that — the same window queried twice may
		// come back arranged differently. Sorting by Item ID makes the order ours
		// rather than theirs.
		//
		// Sorting fixes the order but not the membership. A listing that changes
		// again while its window is being worked through - another sale, say -
		// moves on to a later window, and everything after it in this one moves
		// up a place. Counting from the top then jumped over one listing, which
		// no later window would ever include. So a run resumes after the last
		// listing it finished, by its ID, which nothing can move.
		usort( $items, function ( $a, $b ) {
			return strcmp( (string) ( $a['ItemID'] ?? '' ), (string) ( $b['ItemID'] ?? '' ) );
		} );

		if ( ! $from_cache ) {
			self::cache_delta_rows( $mod_from, $mod_to, $items );
		}

		if ( '' !== $cursor ) {
			$remaining = array_values( array_filter( $items, function ( $item ) use ( $cursor ) {
				return strcmp( (string) ( $item['ItemID'] ?? '' ), $cursor ) > 0;
			} ) );
		} else {
			// A sync updated part way through a window, which kept only a count.
			if ( $offset >= $total_items ) {
				$offset = 0;
			}
			$remaining = array_slice( $items, $offset );
		}

		$done  = $total_items - count( $remaining );
		$items = array_slice( $remaining, 0, self::DELTA_ITEMS_PER_RUN );
		$more  = count( $remaining ) > count( $items );
		unset( $remaining );

		// Pinned before any listing is touched, so that a first batch which dies
		// still leaves its window behind for the next run to carry on with.
		$progress = array_merge( $progress, array(
			'from'       => $mod_from,
			'to'         => $mod_to,
			'cursor'     => $cursor,
			'done'       => $done,
			'in_flight'  => '',
			'body_bytes' => $body_bytes,
			'ts'         => time(),
		) );
		self::save_delta_progress( $progress );

		// Category filtering (same logic as process_page_items).
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$is_custom_filtering = ! empty( $settings['category_ids'] );
		$standard_cats       = ! empty( $settings['import_standard_category_ids'] ) && is_array( $settings['import_standard_category_ids'] ) ? $settings['import_standard_category_ids'] : array();
		$is_filtering        = $is_custom_filtering || ! empty( $standard_cats );
		
		$valid_custom_ids = $is_custom_filtering ? $this->get_allowed_category_ids() : array();

		$mapper = TCGiant_Sync_Mapper::instance();
		$license = TCGiant_Sync_Entitlements::instance();
		$processed = 0;
		$errors = 0;
		$skipped = 0;
		$fallback_count = 0;

		// The whole window, not this run's slice of it: "40 found" on every run
		// of a 2,000 item window made it impossible to see how far along it was.
		//
		// Scanning, not importing. "Importing" belongs to the full sync's queue
		// of item imports, and two things finish a sync by watching that queue
		// run dry - the dashboard, whenever it is open, and the end of every
		// item import. A delta sync works through its batch itself and queues
		// none, so to both of them it looked finished the moment it started.
		// The dashboard marked it complete mid-batch, logging "Sync complete!
		// ... out of 0 total", and the next scheduled run began the window
		// again from the top; a shop with fifteen thousand products, watching
		// its dashboard, saw its sales never reached. A batch that died left
		// the same status behind, and was "completed" the same way.
		//
		// And not over an Emergency Stop pressed while eBay was being read.
		// This write is what erased it, so the run went on to queue the next
		// one as though nobody had pressed anything.
		$update = array( 'total_found' => $total_items );
		// Written either way: this request's copy of the state may still say
		// "scanning" from before Stop was pressed, and leaving the status out
		// wrote that copy's back.
		$update['status'] = self::stop_pressed() ? 'stopped' : 'scanning';
		self::update_sync_state( $update );

		TCGiant_Sync_Logger::log( sprintf(
			'Delta events: %d modified items found. Processing %d to %d [delta].',
			$total_items,
			$done + 1,
			$done + count( $items )
		) );

		$handled      = 0;
		$last_handled = $cursor;
		$current      = null;
		$stopped_for  = '';
		$read_stop    = null;

		foreach ( $items as $ebay_item ) {
			// The listing before this one is finished, whichever way it went:
			// every `continue` below lands here.
			if ( null !== $current ) {
				if ( '' !== $current ) {
					$last_handled = $current;
				}
				$handled++;
				$current = null;
			}

			// Stop starting listings well short of whatever this host allows,
			// and hand the rest to a fresh request - rather than run on until
			// the host kills the request part way through one. Always at least
			// one listing, so every run moves the window on.
			if ( $handled > 0 ) {
				$stopped_for = TCGiant_Sync_Runtime::over_budget();
				if ( '' !== $stopped_for ) {
					break;
				}
			}

			$current = (string) ( $ebay_item['ItemID'] ?? '' );

			// Before the work, so that if this request dies on this listing the
			// next one knows which, and where to carry on from.
			self::save_delta_progress( array_merge( $progress, array(
				'cursor'    => $last_handled,
				'done'      => $done + $handled,
				'in_flight' => $current,
				'ts'        => time(),
			) ) );
			TCGiant_Sync_Runtime::context( sprintf(
				'eBay item %s (%d of %d in the changes from %s)',
				$current,
				$done + $handled + 1,
				$total_items,
				$mod_from
			) );
			self::$slice_in_flight = true;

			// The last listing's answers are done with.
			unset( $full_response, $product_data, $full_for_filter );
			if ( $handled > 0 && 0 === $handled % 10 ) {
				TCGiant_Sync_Runtime::release_memory();
			}

			try {
				// License check.
				//
				// The free limit caps how many products a store may hold, not
				// whether the ones it already has stay in step with eBay. This
				// stopped the loop outright, so a store that went over stopped
				// syncing altogether — no stock, no prices, no ended listings
				// — while the seller carried on trading from figures that were
				// no longer true. Only listings that would create a new product
				// are held back now.
				//
				// Cheap lookup first, and the verdict is remembered. can_import()
				// runs an uncached COUNT over every product on the store, and it
				// used to run once per listing; asking it only about listings that
				// have no product yet, and only until it has said no once, takes
				// that from thousands of counts a sync down to at most one. Once a
				// store is over the limit it stays over, because nothing further
				// is imported.
				if ( ! self::product_exists_for_item( isset( $ebay_item['ItemID'] ) ? $ebay_item['ItemID'] : '' )
					&& ( self::$limit_reported || ! $license->can_import() ) ) {
					if ( ! self::$limit_reported ) {
						self::$limit_reported = true;
						self::update_sync_state( array( 'status' => 'limit_reached' ) );
						TCGiant_Sync_Logger::log( sprintf(
							'Import limit reached (%d/%d products). New listings will not be imported; products already imported carry on syncing normally.',
							$license->get_active_product_count(),
							$license->get_free_limit()
						), 'warning' );
					}
					$skipped++;
					continue;
				}

				$item_id = $ebay_item['ItemID'] ?? '';
				if ( empty( $item_id ) ) {
					continue;
				}

				// Category pre-filter.
				if ( $is_filtering ) {
					$primary_cat = $ebay_item['PrimaryCategory']['CategoryID'] ?? '';
					$store_cat1  = $ebay_item['Storefront']['StoreCategoryID'] ?? '';
					$store_cat2  = $ebay_item['Storefront']['StoreCategory2ID'] ?? '';

					// GetSellerEvents frequently returns a partial item — that is
					// the entire reason the GetItem fallback below exists. But the
					// filter ran first and judged the item on whatever happened to
					// be present, so an item that arrived without any category
					// information at all was silently discarded before the call
					// that would have identified it. Newly listed items are the
					// ones that suffer: they never reach WooCommerce, and the only
					// trace is the "skipped (category filter)" tally.
					//
					// Only pay for the lookup when there is genuinely nothing to
					// judge by; an item that did arrive with categories is filtered
					// as before, at no extra cost.
					if ( '' === (string) $primary_cat && '' === (string) $store_cat1 && '' === (string) $store_cat2 ) {
						$full_for_filter = TCGiant_Sync_API::instance()->get_item( $item_id );
						if ( ! is_wp_error( $full_for_filter ) && isset( $full_for_filter['Item'] ) ) {
							$ebay_item   = $full_for_filter['Item'];
							$fallback_count++;
							$primary_cat = $ebay_item['PrimaryCategory']['CategoryID'] ?? '';
							$store_cat1  = $ebay_item['Storefront']['StoreCategoryID'] ?? '';
							$store_cat2  = $ebay_item['Storefront']['StoreCategory2ID'] ?? '';
						} else {
							// Could not find out. Letting it through is the safer
							// error: a product that should have been filtered is
							// visible and can be removed, one that was wrongly
							// dropped is invisible and never comes back.
							TCGiant_Sync_Logger::warning( sprintf(
								'Delta: no category data for eBay item %s and GetItem failed — importing it rather than dropping it.',
								$item_id
							) );
							$primary_cat = '';
							$store_cat1  = '';
							$store_cat2  = '';
						}
					}

					$match = false;

					// Nothing to judge by even after asking eBay — import it.
					if ( '' === (string) $primary_cat && '' === (string) $store_cat1 && '' === (string) $store_cat2 ) {
						$match = true;
					}
					
					// Check against Standard Categories
					if ( ! empty( $standard_cats ) ) {
						if ( in_array( (string) $primary_cat, $standard_cats, true ) ) $match = true;
					}

					// Check against Custom Store Categories
					if ( ! empty( $valid_custom_ids ) ) {
						if ( in_array( (string) $primary_cat, $valid_custom_ids, true ) ) $match = true;
						if ( in_array( (string) $store_cat1, $valid_custom_ids, true ) ) $match = true;
						if ( in_array( (string) $store_cat2, $valid_custom_ids, true ) ) $match = true;
					}

					if ( ! $match ) {
						$skipped++;
						continue;
					}
				}

				// Don't create products for listings that have already ended.
				//
				// A sale IS a modification, so GetSellerEvents reports the item
				// on the very next delta sync — meaning the items most likely to
				// arrive here are the ones that just sold and ended. Importing
				// those created a fresh product for something no longer for sale,
				// and for a merchant who had deleted the product after the sale
				// it looked like deleted items rising from the dead.
				//
				// An existing product still gets its status updated, so ended
				// listings are still reflected; only creation is prevented.
				$listing_status = $ebay_item['SellingStatus']['ListingStatus'] ?? '';
				if ( '' !== $listing_status && 'Active' !== $listing_status ) {
					$existing_id = self::find_live_product_for_item( $item_id );

					if ( $existing_id && self::settle_ended_listing( $existing_id, $ebay_item, $listing_status, 'Delta' ) ) {
						$fallback_count++;
					}

					$skipped++;
					continue;
				}

				// Fallback: if critical data is missing, fetch via GetItem.
				$needs_fallback = false;
				if ( ! isset( $ebay_item['Title'] ) || ! isset( $ebay_item['SellingStatus'] ) ) {
					$needs_fallback = true;
				}
				// If specs or images are missing, we need GetItem to fetch the full data.
				// Test PictureURL rather than the PictureDetails container: a summary
				// response can carry PictureDetails holding only a GalleryURL, which
				// looked complete here but yields no importable images at all.
				if ( ! isset( $ebay_item['ItemSpecifics'] ) || ! isset( $ebay_item['PictureDetails']['PictureURL'] ) ) {
					$needs_fallback = true;
				}
				// Check for variations that should exist but are missing.
				if ( isset( $ebay_item['Variations'] ) && empty( $ebay_item['Variations']['Variation'] ) ) {
					$needs_fallback = true;
				}

				if ( $needs_fallback ) {
					$fallback_count++;
					$api = TCGiant_Sync_API::instance();
					$full_response = $api->get_item( $item_id );
					if ( ! is_wp_error( $full_response ) && isset( $full_response['Item'] ) ) {
						$ebay_item = $full_response['Item'];
						TCGiant_Sync_Logger::log( sprintf( 'Delta fallback: Used GetItem for %s (missing data in events response).', $item_id ) );
					} else {
						// eBay, or the way to it, was out of action - which says
						// nothing about this listing. Moving past it lost its
						// changes, since nothing reads a finished window again,
						// and with every call failing a whole window went that
						// way in seconds. So stop here and keep the place.
						if ( self::is_passing_read_failure( $full_response ) ) {
							// Never this listing's doing: out of calls, or no
							// token to ask with - decided before eBay is asked.
							$out_of_calls = self::is_out_of_calls( $full_response );
							$not_its_own  = $out_of_calls || 'no_token' === $full_response->get_error_code();
							$before       = (string) ( $progress['stop_item'] ?? '' ) === (string) $item_id ? (int) ( $progress['stops'] ?? 0 ) : 0;
							$stops        = $not_its_own ? $before : $before + 1;

							// A timeout or an eBay error page, run after run, may
							// be eBay or the way to it being down rather than this
							// listing. Before blaming the listing, see whether
							// eBay answers anything at all.
							if ( ! $not_its_own && $stops >= self::DELTA_READ_STOPS
								&& self::is_passing_read_failure( TCGiant_Sync_API::instance()->get_official_time() ) ) {
								$stops = self::DELTA_READ_STOPS - 1;
							}

							if ( $not_its_own || $stops < self::DELTA_READ_STOPS ) {
								$read_stop = array(
									'item'   => (string) $item_id,
									'stops'  => $stops,
									'reason' => $full_response->get_error_message(),
									'code'   => $out_of_calls ? 'out_of_calls' : (string) $full_response->get_error_code(),
								);
								// Not handled, so the next run starts with it.
								$current = null;
								break;
							}

							TCGiant_Sync_Logger::error( sprintf(
								'Delta: eBay item %s could not be read %d runs in a row (%s). Moving past it so the rest can be read. It is queued for a stock check of its own and listed on the Stock Review page.',
								$item_id,
								$stops,
								$full_response->get_error_message()
							) );
							self::hold_back_listing( $item_id, $mod_from );
							self::queue_settle( $item_id );
							$errors++;
							continue;
						}

						$errors++;
						TCGiant_Sync_Logger::error( sprintf( 'Delta fallback failed for item %s. Skipping.', $item_id ) );
						continue;
					}

					// What eBay says now, not what the window said. A window can take
					// hours to work through, and a listing that was live when it was
					// read may have sold or ended since - on the website, say, with the
					// plugin ending the eBay listing. Saved as live, its stock would be
					// set from eBay's figures and a sold coin put back on sale.
					$now_status = (string) ( $ebay_item['SellingStatus']['ListingStatus'] ?? '' );
					if ( '' !== $now_status && 'Active' !== $now_status ) {
						$existing_id = self::find_live_product_for_item( $item_id );
						if ( $existing_id ) {
							self::settle_ended_listing( $existing_id, $ebay_item, $now_status, 'Delta' );
						}
						$skipped++;
						continue;
					}
				}

				// Map and save — same flow as process_item_import but without the GetItem call.
				$title = $ebay_item['Title'] ?? 'Unknown';
				$product_data = $mapper->map_ebay_to_woo( $ebay_item );
				$product_id = $mapper->save_as_product( $product_data );

				if ( $product_id ) {
					// Append variation images to the download queue
					if ( ! empty( $product_data['variations'] ) ) {
						foreach ( $product_data['variations'] as $var ) {
							if ( ! empty( $var['image_url'] ) && ! empty( $var['variation_id'] ) ) {
								$product_data['images'][] = array( $var['image_url'], $var['variation_id'] );
							}
						}
					}

					// Set external eBay image URLs — localized in background.
					if ( ! empty( $product_data['images'] ) ) {
						TCGiant_Sync_Image_Localizer::set_external_images( $product_id, $product_data['images'] );
					}
					$processed++;

					$price_display = ! empty( $product_data['price'] ) ? '$' . $product_data['price'] : 'No price';
					$attr_count = count( $product_data['attributes'] );
					TCGiant_Sync_Logger::log( sprintf(
						'Delta imported: "%s" → WC #%d (%s, Qty: %d, %d attrs)',
						$title, $product_id, $price_display, $product_data['stock_quantity'], $attr_count
					), 'success' );
				} elseif ( ! TCGiant_Sync_Mapper::$last_skipped ) {
					$errors++;
					TCGiant_Sync_Logger::error( sprintf( 'Delta: Failed to save product for eBay Item: %s (%s)', $item_id, $title ) );
				}

			} catch ( Exception $e ) {
				$errors++;
				TCGiant_Sync_Logger::error( 'Delta import exception: ' . $e->getMessage() );
			} catch ( Error $e ) {
				$errors++;
				TCGiant_Sync_Logger::error( 'Delta import fatal: ' . $e->getMessage() );
			}
		}

		// The last listing reached finished too, unless the budget stopped the
		// loop before it began.
		if ( null !== $current ) {
			if ( '' !== $current ) {
				$last_handled = $current;
			}
			$handled++;
		}

		self::$slice_in_flight = false;
		TCGiant_Sync_Runtime::context( '' );

		$more     = $more || $handled < count( $items );
		$progress = array_merge( $progress, array(
			'cursor'    => $last_handled,
			'done'      => $done + $handled,
			'in_flight' => '',
			'kills'     => 0,
			'kill_item' => '',
			'stops'     => null !== $read_stop ? $read_stop['stops'] : 0,
			'stop_item' => null !== $read_stop ? $read_stop['item'] : '',
			'ts'        => time(),
		) );

		// Log summary. "Complete" only when the window is: it was said after
		// every batch of forty, so a sync two weeks behind read as finished.
		$filter_label = $skipped > 0 ? sprintf( ', %d skipped (category filter)', $skipped ) : '';
		$fallback_label = $fallback_count > 0 ? sprintf( ', %d GetItem fallbacks', $fallback_count ) : '';
		TCGiant_Sync_Logger::log( sprintf(
			$more || null !== $read_stop
				? 'Delta batch done: %d imported, %d errors out of %d modified items%s%s.'
				: 'Delta sync complete! %d imported, %d errors out of %d modified items%s%s.',
			$processed, $errors, $handled, $filter_label, $fallback_label
		), 'success' );

		// eBay could not be read. Keep the window and the place, stay running
		// with the next run queued for later - the hourly poll leaves a sync
		// with a queued run alone - and back off: 15 minutes, doubling to two
		// hours. Not the "rate_limited" status, whose Resume button starts a
		// full sync instead.
		if ( null !== $read_stop ) {
			self::save_delta_progress( $progress );

			if ( self::stop_pressed() ) {
				TCGiant_Sync_Logger::log( 'Delta sync stopped as requested. It starts again at the next scheduled sync.' );
				return;
			}

			$retries = self::pauses_in_a_row( self::get_sync_state(), $read_stop['code'], $handled > 0 );
			$delay   = $this->get_rate_limit_backoff_delay( $retries );

			self::update_sync_state( array(
				'status'             => 'scanning',
				'rate_limit_retries' => $retries + 1,
				'delta_offset'       => $done + $handled,
				'delta_cursor'       => $last_handled,
				'delta_window_from'  => $mod_from,
				'delta_window_to'    => $mod_to,
				'total_found'        => $total_items,
				'total_processed'    => (int) ( $state['total_processed'] ?? 0 ) + $processed,
				'total_errors'       => (int) ( $state['total_errors'] ?? 0 ) + $errors,
				'delta_paused_for'   => $read_stop['reason'],
				'delta_paused_code'  => $read_stop['code'],
				'delta_paused_until' => time() + $delay,
			) );

			TCGiant_Sync_Logger::warning( sprintf(
				'Delta sync: paused at eBay item %s with %d of %d changed listings done, because eBay could not be read just now (%s). The place is kept and nothing is skipped; it tries again in %d minutes.',
				$read_stop['item'],
				$done + $handled,
				$total_items,
				$read_stop['reason'],
				(int) round( $delay / 60 )
			) );

			if ( ! self::delta_run_pending() ) {
				as_schedule_single_action( time() + $delay, 'tcgiant_sync_fetch_delta_events', array(), self::GROUP_SCAN );
			}

			return;
		}

		if ( '' !== $stopped_for ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Delta sync: stopped this batch after %d listing(s) to stay within this host\'s limits (%s). The rest carries on in a fresh request.',
				$handled,
				$stopped_for
			) );
		}

		// More of this window still to go: record where we got to, keep the sync
		// marked as running, and pick up from here on the next pass. The position
		// is deliberately not advanced until the whole window is done, so an
		// interruption costs a repeat rather than a hole.
		if ( $more ) {
			self::save_delta_progress( $progress );

			TCGiant_Sync_Logger::log( sprintf(
				'Delta sync: %d of %d changed items done. Continuing with the rest.',
				$done + $handled,
				$total_items
			) );

			// The state keeps a copy of the place for a site that goes back to
			// 3.26 mid-window; the progress option is what this version reads.
			self::continue_delta( array(
				'status'             => 'scanning',
				'delta_offset'       => $done + $handled,
				'delta_cursor'       => $last_handled,
				'delta_window_from'  => $mod_from,
				'delta_window_to'    => $mod_to,
				'total_found'        => $total_items,
				'total_processed'    => (int) ( $state['total_processed'] ?? 0 ) + $processed,
				'total_errors'       => (int) ( $state['total_errors'] ?? 0 ) + $errors,
				'last_activity'      => current_time( 'mysql' ),
				'rate_limit_retries' => 0,
				'delta_paused_for'   => '',
				'delta_paused_code'  => '',
				'delta_paused_until' => 0,
			) );

			return;
		}

		// The end of the window actually queried, not the clock. When the window
		// is clamped to eBay's 48 hour maximum, "now" is beyond what was read —
		// so the backlog the clamp exists to work through incrementally was
		// skipped instead, despite the log promising the next sync would
		// continue. It also closes a small gap where anything modified between
		// the API call and this line was stepped over.
		$chase = self::delta_chase_state( $state, $mod_to, $clamped );

		self::update_sync_state( array_merge( self::fresh_delta_window(), array(
			'status'                  => 'complete',
			'delta_window_minutes'    => self::next_delta_window_minutes( $window_minutes, $mod_to, $total_items, $body_bytes, $ceiling ),
			'total_found'             => $total_items,
			'total_queued'            => $processed + $errors,
			'total_processed'         => (int) ( $state['total_processed'] ?? 0 ) + $processed,
			'total_errors'            => (int) ( $state['total_errors'] ?? 0 ) + $errors,
			'last_completed'          => current_time( 'mysql' ),
			'last_activity'           => current_time( 'mysql' ),
			'last_successful_sync_at' => $mod_to,
			'rate_limit_retries'      => 0,
			'delta_paused_for'        => '',
			'delta_paused_code'       => '',
			'delta_paused_until'      => 0,
		), $chase ) );

		if ( $chase ) {
			as_enqueue_async_action( 'tcgiant_sync_fetch_delta_events', array(), self::GROUP_SCAN );
		}
	}

	/**
	 * The note a delta fetch leaves while it is waiting on eBay.
	 *
	 * @param string $mod_from       Start of the window.
	 * @param int    $window_minutes Width of the window.
	 * @return string
	 */
	private static function delta_attempt_key( $mod_from, $window_minutes ) {
		return $mod_from . '|' . (int) $window_minutes;
	}

	/**
	 * The delta's place in its window, read fresh.
	 *
	 * @return array
	 */
	private static function delta_progress() {
		// Another request may have moved it on since this one first read it.
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::DELTA_PROGRESS_OPTION, 'options' );
		}

		$progress = get_option( self::DELTA_PROGRESS_OPTION, array() );

		return is_array( $progress ) ? $progress : array();
	}

	/**
	 * Write the delta's place in its window.
	 *
	 * @param array $progress Place.
	 */
	private static function save_delta_progress( array $progress ) {
		update_option( self::DELTA_PROGRESS_OPTION, $progress, false );
	}

	/**
	 * Forget the delta's place and the window it was kept for.
	 */
	private static function clear_delta_progress() {
		delete_option( self::DELTA_PROGRESS_OPTION );
		delete_option( self::DELTA_WINDOW_CACHE_OPTION );
	}

	/**
	 * A place kept by 3.26, which held it in the sync state.
	 *
	 * @param array  $state    Sync state.
	 * @param string $mod_from Start of the window being read.
	 * @return array Progress, or empty if there is none for this window.
	 */
	private static function legacy_delta_progress( array $state, $mod_from ) {
		$from   = (string) ( $state['delta_window_from'] ?? '' );
		$to     = (string) ( $state['delta_window_to'] ?? '' );
		$cursor = (string) ( $state['delta_cursor'] ?? '' );
		$done   = (int) ( $state['delta_offset'] ?? 0 );

		if ( $from !== $mod_from || '' === $to || ( '' === $cursor && $done <= 0 ) ) {
			return array();
		}

		return array(
			'from'   => $from,
			'to'     => $to,
			'cursor' => $cursor,
			'done'   => $done,
		);
	}

	/**
	 * The last run died while importing a listing: try it once more, or step
	 * over it if it has killed a run before.
	 *
	 * Stepping over is not dropping. The listing is queued for a stock check of
	 * its own - which reads eBay's figures without the import that died - and
	 * listed on Stock Review, because for a quantity-1 coin or card a sale the
	 * sync never saw is an oversell waiting to happen.
	 *
	 * @param array  $progress Place, with in_flight set.
	 * @param string $mod_from Start of the window.
	 * @return array Place, updated and saved.
	 */
	private static function after_slice_death( array $progress, $mod_from ) {
		$dead_on = (string) $progress['in_flight'];
		$kills   = (string) ( $progress['kill_item'] ?? '' ) === $dead_on
			? (int) ( $progress['kills'] ?? 0 ) + 1
			: 1;

		if ( $kills >= self::DELTA_SLICE_KILLS ) {
			TCGiant_Sync_Logger::error( sprintf(
				'The delta sync was stopped %d times in a row while importing eBay item %s (in the changes from %s). Moving past it so the rest can be read. It is queued for a stock check of its own and listed on the Stock Review page; the error line before this one says what stopped it, where PHP said.',
				$kills,
				$dead_on,
				$mod_from
			) );

			self::hold_back_listing( $dead_on, $mod_from );
			self::queue_settle( $dead_on );

			$progress['cursor']    = $dead_on;
			$progress['done']      = (int) ( $progress['done'] ?? 0 ) + 1;
			$progress['kills']     = 0;
			$progress['kill_item'] = '';
		} else {
			TCGiant_Sync_Logger::warning( sprintf(
				'The last delta run stopped while importing eBay item %s. Trying it once more before moving past it.',
				$dead_on
			) );

			$progress['kills']     = $kills;
			$progress['kill_item'] = $dead_on;
		}

		$progress['in_flight'] = '';
		self::save_delta_progress( $progress );

		return $progress;
	}

	/**
	 * Add a listing to the held-back list.
	 *
	 * @param string $item_id  eBay ItemID.
	 * @param string $mod_from Start of the window it was in.
	 */
	private static function hold_back_listing( $item_id, $mod_from ) {
		$held = self::held_back_listings();

		$held[ (string) $item_id ] = array(
			'item_id' => (string) $item_id,
			'from'    => (string) $mod_from,
			'at'      => time(),
		);

		if ( count( $held ) > self::DELTA_HELD_MAX ) {
			$held = array_slice( $held, -self::DELTA_HELD_MAX, null, true );
		}

		update_option( self::DELTA_HELD_OPTION, $held, false );
	}

	/**
	 * Seconds since the delta last finished reading eBay's changes, or 0 when
	 * that is not a question worth asking - syncing switched off, or no sync yet.
	 *
	 * @return int
	 */
	public static function delta_behind_seconds() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$interval = is_array( $settings ) && ! empty( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'tcgiant_hourly';

		// Nothing to fall behind on with syncing switched off or no eBay account.
		if ( 'disabled' === $interval || ! TCGiant_Sync_OAuth::instance()->is_authenticated() ) {
			return 0;
		}

		$last = (string) ( self::get_sync_state()['last_successful_sync_at'] ?? '' );
		$ts   = '' !== $last ? strtotime( $last ) : false;

		return $ts ? max( 0, time() - (int) $ts ) : 0;
	}

	/**
	 * How far behind the delta may be before that is worth saying.
	 *
	 * Two sync intervals, and never less than a day - a shop syncing daily is
	 * a day behind as a matter of course.
	 *
	 * @return int Seconds.
	 */
	public static function delta_behind_threshold() {
		$settings  = get_option( 'tcgiant_sync_ebay_settings', array() );
		$interval  = is_array( $settings ) && ! empty( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'tcgiant_hourly';
		$schedules = function_exists( 'wp_get_schedules' ) ? wp_get_schedules() : array();
		$seconds   = isset( $schedules[ $interval ]['interval'] ) ? (int) $schedules[ $interval ]['interval'] : HOUR_IN_SECONDS;

		return max( DAY_IN_SECONDS, 2 * $seconds );
	}

	/**
	 * Listings the delta had to step over, keyed by ItemID.
	 *
	 * @return array
	 */
	public static function held_back_listings() {
		$held = get_option( self::DELTA_HELD_OPTION, array() );

		return is_array( $held ) ? $held : array();
	}

	/**
	 * Take listings off the held-back list.
	 *
	 * @param string[] $item_ids ItemIDs, or empty for all of them.
	 */
	public static function forget_held_back( array $item_ids = array() ) {
		if ( empty( $item_ids ) ) {
			delete_option( self::DELTA_HELD_OPTION );
			return;
		}

		$held = self::held_back_listings();

		foreach ( $item_ids as $id ) {
			unset( $held[ (string) $id ] );
		}

		update_option( self::DELTA_HELD_OPTION, $held, false );
	}

	/**
	 * Keep the window's listings in brief.
	 *
	 * Only what the loop reads before it decides whether to ask eBay for the
	 * whole listing: its ID, status, quantities, times and categories. Nothing
	 * a GetItem would not supply again - which it does, because a brief row
	 * carries no item specifics or pictures, and in practice eBay's events
	 * answer did not either.
	 *
	 * @param string $from  Start of the window.
	 * @param string $to    End of the window.
	 * @param array  $items Listings, as eBay sent them.
	 */
	private static function cache_delta_rows( $from, $to, array $items ) {
		$rows = array();

		foreach ( $items as $item ) {
			if ( is_array( $item ) && ! empty( $item['ItemID'] ) ) {
				$rows[] = self::compact_delta_item( $item );
			}
		}

		update_option( self::DELTA_WINDOW_CACHE_OPTION, array( 'key' => $from . '|' . $to, 'rows' => $rows ), false );
	}

	/**
	 * The listings kept for this window, or null if there are none.
	 *
	 * @param string $from Start of the window.
	 * @param string $to   End of the window.
	 * @return array|null
	 */
	private static function cached_delta_rows( $from, $to ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::DELTA_WINDOW_CACHE_OPTION, 'options' );
		}

		$cache = get_option( self::DELTA_WINDOW_CACHE_OPTION, array() );

		if ( ! is_array( $cache ) || (string) ( $cache['key'] ?? '' ) !== $from . '|' . $to
			|| ! isset( $cache['rows'] ) || ! is_array( $cache['rows'] ) || empty( $cache['rows'] ) ) {
			return null;
		}

		return $cache['rows'];
	}

	/**
	 * One listing, reduced to what the loop reads before a GetItem.
	 *
	 * @param array $item Listing as eBay sent it.
	 * @return array
	 */
	private static function compact_delta_item( array $item ) {
		$row = array( 'ItemID' => (string) $item['ItemID'] );

		if ( isset( $item['Quantity'] ) && ! is_array( $item['Quantity'] ) ) {
			$row['Quantity'] = $item['Quantity'];
		}

		foreach ( array( 'ListingStatus', 'QuantitySold' ) as $key ) {
			if ( isset( $item['SellingStatus'][ $key ] ) && ! is_array( $item['SellingStatus'][ $key ] ) ) {
				$row['SellingStatus'][ $key ] = $item['SellingStatus'][ $key ];
			}
		}

		foreach ( array( 'StartTime', 'EndTime' ) as $key ) {
			if ( ! empty( $item['ListingDetails'][ $key ] ) && ! is_array( $item['ListingDetails'][ $key ] ) ) {
				$row['ListingDetails'][ $key ] = $item['ListingDetails'][ $key ];
			}
		}

		if ( isset( $item['PrimaryCategory']['CategoryID'] ) && ! is_array( $item['PrimaryCategory']['CategoryID'] ) ) {
			$row['PrimaryCategory']['CategoryID'] = $item['PrimaryCategory']['CategoryID'];
		}

		foreach ( array( 'StoreCategoryID', 'StoreCategory2ID' ) as $key ) {
			if ( isset( $item['Storefront'][ $key ] ) && ! is_array( $item['Storefront'][ $key ] ) ) {
				$row['Storefront'][ $key ] = $item['Storefront'][ $key ];
			}
		}

		// Marks it as a variation listing, which makes the loop fetch it whole.
		if ( ! empty( $item['Variations'] ) ) {
			$row['Variations'] = array( 'Variation' => array() );
		}

		return $row;
	}

	/**
	 * State that forgets where the sync was inside a window.
	 *
	 * Every path that finishes a window or changes its width writes this, so
	 * a place in one window can never be carried into another.
	 *
	 * @return array
	 */
	private static function fresh_delta_window() {
		self::clear_delta_progress();

		return array(
			'delta_offset'       => 0,
			'delta_cursor'       => '',
			'delta_window_from'  => '',
			'delta_window_to'    => '',
			'delta_paused_for'   => '',
			'delta_paused_code'  => '',
			'delta_paused_until' => 0,
		);
	}

	/**
	 * How many pauses in a row came before this one, for the back-off and for
	 * the notice: only pauses of the same kind, with nothing read between.
	 *
	 * Running out of calls is one kind, everything else - a timeout, a token,
	 * eBay down - the other, so a long wait for the day's calls never makes a
	 * single timeout afterwards look like an outage. A run that got through
	 * listings before it paused starts the count again.
	 *
	 * @param array  $state    Sync state.
	 * @param string $code     The kind of pause about to be recorded.
	 * @param bool   $read_any Whether this run read any listings first.
	 * @return int
	 */
	private static function pauses_in_a_row( array $state, $code, $read_any ) {
		$before = (string) ( $state['delta_paused_code'] ?? '' );

		if ( $read_any || '' === $before || ( 'out_of_calls' === $before ) !== ( 'out_of_calls' === (string) $code ) ) {
			return 0;
		}

		return (int) ( $state['rate_limit_retries'] ?? 0 );
	}

	/**
	 * Half the time actually asked for, or the shortest window allowed.
	 *
	 * @param int $window_minutes Width of the window.
	 * @param int $span_minutes   How much of it the request covered.
	 * @return int
	 */
	private static function narrower_delta_window( $window_minutes, $span_minutes ) {
		return max( self::DELTA_WINDOW_MIN_MINUTES, (int) floor( min( $window_minutes, max( 1, $span_minutes ) ) / 2 ) );
	}

	/**
	 * Largest eBay answer this site should read just now, in bytes.
	 *
	 * The memory limit decides it, unless a request has recently been stopped
	 * while reading, in which case the lower figure learned from that holds
	 * for a while - see run_delta_events().
	 *
	 * @param array $state Sync state.
	 * @return int
	 */
	private static function delta_byte_ceiling( $state ) {
		$ceiling = TCGiant_Sync_API::get_events_byte_limit();
		$learned = (int) ( $state['delta_byte_ceiling'] ?? 0 );

		if ( $learned > 0 && time() < (int) ( $state['delta_byte_ceiling_until'] ?? 0 ) ) {
			$ceiling = min( $ceiling, $learned );
		}

		return $ceiling;
	}

	/**
	 * Record where the delta sync has got to and queue its next run - unless
	 * it has been stopped in the meantime.
	 *
	 * Emergency Stop clears the queue and marks the sync stopped, but a run
	 * already under way when it was pressed went on to queue the next one, and
	 * the sync carried on regardless.
	 *
	 * @param array $updates State to write.
	 */
	private static function continue_delta( array $updates ) {
		if ( self::stop_pressed() ) {
			// Said outright: this request's copy of the state may predate the
			// Stop, and merging into it wrote "scanning" back over it.
			$updates['status'] = 'stopped';
			self::update_sync_state( $updates );
			TCGiant_Sync_Logger::log( 'Delta sync stopped as requested. It starts again at the next scheduled sync.' );
			return;
		}

		self::update_sync_state( $updates );
		as_enqueue_async_action( 'tcgiant_sync_fetch_delta_events', array(), self::GROUP_SCAN );
	}

	/**
	 * Whether the delta sync's next run is waiting in the queue, or running.
	 *
	 * @return bool
	 */
	private static function delta_run_queued() {
		return function_exists( 'as_next_scheduled_action' )
			&& false !== as_next_scheduled_action( 'tcgiant_sync_fetch_delta_events', null, self::GROUP_SCAN );
	}

	/**
	 * Whether a delta run is waiting in the queue, not counting one running.
	 *
	 * @return bool
	 */
	private static function delta_run_pending( $since = null ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return self::delta_run_queued();
		}

		$query = array(
			'hook'     => 'tcgiant_sync_fetch_delta_events',
			'group'    => self::GROUP_SCAN,
			'status'   => 'pending',
			'per_page' => 1,
		);

		// Only one due since then, or due later: a run queued long ago that
		// the queue never got round to is not a sync that is moving.
		if ( null !== $since ) {
			$query['date']         = (int) $since;
			$query['date_compare'] = '>=';
		}

		return ! empty( as_get_scheduled_actions( $query, 'ids' ) );
	}

	/**
	 * Whether Emergency Stop has been pressed, read from the table.
	 *
	 * A run's own copy of the state is the one from when it read it, and a
	 * Stop pressed since is not in it. Reloading every autoloaded option to
	 * refresh it is too much to ask - of a request that has run out of memory
	 * above all - so the one row is read directly.
	 *
	 * @return bool
	 */
	private static function stop_pressed() {
		global $wpdb;

		$state = null;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && isset( $wpdb->options ) ) {
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::STATE_OPTION ) );
			if ( is_string( $raw ) ) {
				$state = maybe_unserialize( $raw );
			}
		}

		if ( ! is_array( $state ) ) {
			$state = self::get_sync_state();
		}

		return 'stopped' === ( $state['status'] ?? '' );
	}

	/**
	 * Whether a failed eBay read says nothing about the listing asked for:
	 * out of calls, timed out, no token, the relay or eBay down. Trying again
	 * later should work.
	 *
	 * An error from eBay about the listing itself - ended long ago, not this
	 * seller's - is not one of these. Waiting would not change it.
	 *
	 * @param mixed $error What the call returned.
	 * @return bool
	 */
	private static function is_passing_read_failure( $error ) {
		return is_wp_error( $error )
			&& in_array( $error->get_error_code(), array( 'rate_limited', 'no_token', 'http_request_failed', 'invalid_xml' ), true );
	}

	/**
	 * Whether a failed call ran into a call allowance - this shop's, every
	 * shop's together, or eBay's own - rather than anything about the
	 * listing. eBay's 10007 is reported as a rate limit too, but it is an
	 * error inside eBay that one listing can keep hitting, so it is not this.
	 *
	 * @param mixed $error What the call returned.
	 * @return bool
	 */
	private static function is_out_of_calls( $error ) {
		if ( ! is_wp_error( $error ) || 'rate_limited' !== $error->get_error_code() ) {
			return false;
		}

		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			return false;
		}

		// The plugin's own budget, and eBay's HTTP 429.
		if ( ! empty( $data['daily_budget'] ) || array_key_exists( 'retry_after', $data ) ) {
			return true;
		}

		// eBay's 518, "exceeded the call limit".
		$errors = $data['Errors'] ?? array();
		if ( is_array( $errors ) && isset( $errors['ErrorCode'] ) ) {
			$errors = array( $errors );
		}
		foreach ( (array) $errors as $one ) {
			if ( is_array( $one ) && '518' === (string) ( $one['ErrorCode'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The shop has just connected to eBay again.
	 *
	 * A delta paused because eBay would not take the old token is told so,
	 * and its next run - which may be two hours off - is brought forward.
	 * Until then the notice went on saying to reconnect, as though doing it
	 * had not worked.
	 */
	public static function after_reconnect() {
		$state = self::get_sync_state();

		if ( 'delta' !== ( $state['sync_mode'] ?? '' ) || 'no_token' !== ( $state['delta_paused_code'] ?? '' ) ) {
			return;
		}

		self::update_sync_state( array(
			'rate_limit_retries' => 0,
			'delta_paused_for'   => '',
			'delta_paused_code'  => '',
			'delta_paused_until' => 0,
		) );

		// Only a run waiting for later; one running now uses the new token
		// on its next call.
		if ( 'scanning' === ( $state['status'] ?? '' ) && self::delta_run_pending()
			&& function_exists( 'as_unschedule_all_actions' ) && function_exists( 'as_enqueue_async_action' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_delta_events', null, self::GROUP_SCAN );
			as_enqueue_async_action( 'tcgiant_sync_fetch_delta_events', array(), self::GROUP_SCAN );
		}
	}

	/**
	 * Where the delta has got to while it works through a backlog, or null
	 * when it is not moving.
	 *
	 * Moving means a run is waiting in the queue, or the sync did some work
	 * recently: started a listing, or finished a window. Recently is two sync
	 * intervals, and never less than an hour, because a sync working through
	 * a long backlog rests between scheduled syncs once it has read a batch
	 * of windows.
	 *
	 * @return array|null done, total, from (the window being worked through,
	 *                    or empty between windows), paused_for and
	 *                    paused_until (a pause because eBay could not be read).
	 */
	public static function delta_catch_up() {
		$state = self::get_sync_state();

		// A Fetch Inventory that is moving brings eBay's listings in too, and
		// the delta waits for it.
		if ( 'full' === ( $state['sync_mode'] ?? '' ) ) {
			$idle   = self::sync_idle_seconds( $state );
			$moving = in_array( $state['status'] ?? '', array( 'scanning', 'importing' ), true )
				&& null !== $idle && $idle <= self::SYNC_STALL_MINUTES * MINUTE_IN_SECONDS;

			return $moving ? array( 'done' => 0, 'total' => 0, 'from' => '', 'paused_for' => '', 'paused_until' => 0, 'paused_code' => '' ) : null;
		}

		if ( 'stopped' === ( $state['status'] ?? '' ) || 'delta' !== ( $state['sync_mode'] ?? '' ) ) {
			return null;
		}

		$settings  = get_option( 'tcgiant_sync_ebay_settings', array() );
		$interval  = is_array( $settings ) && ! empty( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'tcgiant_hourly';
		$schedules = function_exists( 'wp_get_schedules' ) ? wp_get_schedules() : array();
		$seconds   = isset( $schedules[ $interval ]['interval'] ) ? (int) $schedules[ $interval ]['interval'] : HOUR_IN_SECONDS;
		$recent    = max( HOUR_IN_SECONDS, 2 * $seconds );

		$progress  = self::delta_progress();
		$worked_at = max( (int) ( $progress['ts'] ?? 0 ), (int) ( $state['delta_moved_at'] ?? 0 ) );

		if ( ( time() - $worked_at ) > $recent && ! self::delta_run_pending( time() - $recent ) ) {
			return null;
		}

		// Paused three runs in a row - an hour and three quarters - for
		// anything but the day's calls running out is not a passing outage,
		// whatever the queue says: a token that will not refresh, say.
		$paused_code = (string) ( $state['delta_paused_code'] ?? '' );
		if ( '' !== (string) ( $state['delta_paused_for'] ?? '' ) && 'out_of_calls' !== $paused_code
			&& (int) ( $state['rate_limit_retries'] ?? 0 ) >= self::DELTA_READ_STOPS ) {
			return null;
		}

		$in_window = ! empty( $progress ) && (string) ( $progress['from'] ?? '' ) === (string) ( $state['delta_mod_from'] ?? '' )
			&& in_array( $state['status'] ?? '', array( 'scanning', 'importing' ), true );
		$paused    = (int) ( $state['delta_paused_until'] ?? 0 ) > time() ? (string) ( $state['delta_paused_for'] ?? '' ) : '';

		return array(
			'done'         => $in_window ? (int) ( $progress['done'] ?? 0 ) : 0,
			'total'        => $in_window ? (int) ( $state['total_found'] ?? 0 ) : 0,
			'from'         => $in_window ? (string) $progress['from'] : '',
			'paused_for'   => $paused,
			'paused_until' => '' !== $paused ? (int) $state['delta_paused_until'] : 0,
			'paused_code'  => '' !== $paused ? $paused_code : '',
		);
	}

	/**
	 * How wide the next delta window should be, judged by how the last went.
	 *
	 * A narrowed window used to widen again only once the sync had caught up
	 * with the present - which a narrow window may never manage, since it is
	 * narrow precisely because there is a lot to read. Once narrowed it could
	 * stay that way for good, reading slivers of time long after the busy
	 * period had passed. So a window that came back comfortably small doubles
	 * as well. "Comfortably" means under a quarter of both limits, so a
	 * doubling cannot carry it straight back over either and set it see-sawing.
	 *
	 * @param int    $window_minutes Width of the window just read.
	 * @param string $mod_to         End of the window just read.
	 * @param int    $item_count     Listings eBay sent for it.
	 * @param int    $body_bytes     Size of eBay's answer.
	 * @param int    $ceiling        The size ceiling in force.
	 * @return int
	 */
	private static function next_delta_window_minutes( $window_minutes, $mod_to, $item_count, $body_bytes, $ceiling ) {
		// Back to the widest window once the backlog is gone. A store in step
		// with eBay has little in any given window, so there is no reason to
		// keep asking for slivers of time for ever.
		if ( ( time() - strtotime( $mod_to ) ) < ( 2 * MINUTE_IN_SECONDS ) ) {
			return self::DELTA_WINDOW_MAX_MINUTES;
		}

		$comfortable = ( $item_count * 4 ) < self::DELTA_EBAY_MAX_ITEMS
			&& ( $body_bytes * 4 ) < $ceiling;

		if ( $comfortable ) {
			return min( self::DELTA_WINDOW_MAX_MINUTES, $window_minutes * 2 );
		}

		return $window_minutes;
	}

	/**
	 * State that sends the sync straight on to the next window, or nothing.
	 *
	 * Written in the same update that finishes the window, so there is no
	 * moment at which the sync reads as finished while the next window is
	 * already on its way - a scheduled run arriving then would start a second
	 * one alongside it.
	 *
	 * @param array  $state   Sync state as this run found it.
	 * @param string $mod_to  End of the window just finished.
	 * @param bool   $clamped Whether that window stopped short of the present.
	 * @return array
	 */
	private static function delta_chase_state( $state, $mod_to, $clamped ) {
		if ( ! $clamped ) {
			return array();
		}

		// Stopped while this window was being read. The next scheduled sync
		// carries on; this one does not. Read from the table: this request's
		// copy of the state predates the Stop, and chased straight past it.
		if ( self::stop_pressed() ) {
			return array();
		}

		$chain = (int) ( $state['delta_chain'] ?? 0 );

		if ( $chain >= self::DELTA_CHAIN_MAX ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Read %d windows of changes in a row. The rest of the backlog carries on from %s at the next scheduled sync.',
				$chain + 1,
				$mod_to
			) );
			return array();
		}

		$reserve = (int) ( TCGiant_Sync_API::DAILY_CALL_LIMIT * TCGiant_Sync_API::DAILY_CALL_SOFT_CEILING * self::DELTA_CHASE_BUDGET_SHARE );

		if ( TCGiant_Sync_API::instance()->get_remaining_daily_budget() < $reserve ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Most of today\'s eBay allowance is used, so the rest of the backlog carries on from %s at the next scheduled sync rather than straight away.',
				$mod_to
			) );
			return array();
		}

		return array(
			'status'         => 'scanning',
			// Exactly where start_delta_sync() would put it, overlap included.
			'delta_mod_from' => gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( $mod_to ) - self::DELTA_OVERLAP_SECONDS ),
			'delta_chain'    => $chain + 1,
		);
	}

	/**
	 * Calculate the retry delay for rate-limited operations using exponential backoff.
	 *
	 * Starts at 15 minutes and doubles on each consecutive rate limit hit,
	 * capped at 2 hours. This prevents burning through the daily API budget
	 * when eBay is actively throttling the account.
	 *
	 * @param int $retry_count Number of consecutive rate limit retries so far.
	 * @return int Delay in seconds before the next retry.
	 */
	private function get_rate_limit_backoff_delay( $retry_count ) {
		$base_delay = 900;  // 15 minutes
		$max_delay  = 7200; // 2 hours
		$delay = $base_delay * pow( 2, min( $retry_count, 4 ) );
		return min( $delay, $max_delay );
	}

	/**
	 * Resume a sync that was paused due to API rate limiting.
	 *
	 * Unlike start_full_sync(), this preserves existing totals and continues
	 * from the last scanned page or re-queues pending item imports.
	 */
	public function resume_sync() {
		$state = self::get_sync_state();

		// Only resume from rate_limited or stopped states.
		if ( ! in_array( $state['status'], array( 'rate_limited', 'stopped', 'error' ), true ) ) {
			TCGiant_Sync_Logger::log( 'Cannot resume: sync is not in a paused/limited state.', 'warning' );
			return;
		}

		// No licence check here. A scan that stopped part way through has
		// products of its own still to update, and refusing to resume left it
		// stranded with no way to finish.

		// Clear any stale scheduled actions.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tcgiant_sync_fetch_listings', null, 'tcgiant_sync_group' );
			as_unschedule_all_actions( 'tcgiant_sync_scan_all_pages', null, 'tcgiant_sync_group' );
			wp_clear_scheduled_hook( 'tcgiant_sync_scan_resume' );
			as_unschedule_all_actions( 'tcgiant_sync_process_item_import', null, 'tcgiant_sync_imports' );
		}

		$resume_page = max( 1, (int) $state['current_page'] );
		$total_pages = (int) $state['total_pages'];

		// Reset backoff counter on manual resume.
		self::update_sync_state( array( 'rate_limit_retries' => 0 ) );

		// If we still have pages to scan, resume scanning.
		if ( $total_pages === 0 || $resume_page <= $total_pages ) {
			self::update_sync_state( array( 'status' => 'scanning' ) );
			TCGiant_Sync_Logger::log( sprintf(
				'Resuming sync from page %d%s. Progress so far: %d found, %d queued, %d imported.',
				$resume_page,
				$total_pages ? '/' . $total_pages : '',
				$state['total_found'],
				$state['total_queued'],
				$state['total_processed']
			), 'success' );
			as_enqueue_async_action( 'tcgiant_sync_scan_all_pages', array(), 'tcgiant_sync_group' );
		} else {
			// All pages were scanned but import phase was interrupted.
			// Transition to importing — any pending item imports will be re-processed.
			if ( $state['total_queued'] > ( $state['total_processed'] + $state['total_errors'] ) ) {
				self::update_sync_state( array( 'status' => 'importing' ) );
				TCGiant_Sync_Logger::log( sprintf(
					'Resuming import phase. %d/%d items still pending.',
					$state['total_queued'] - $state['total_processed'] - $state['total_errors'],
					$state['total_queued']
				), 'success' );
			} else {
				self::update_sync_state( array(
					'status'         => 'complete',
					'last_completed' => current_time( 'mysql' ),
				) );
				TCGiant_Sync_Logger::log( 'Resume check: all items were already processed. Sync complete.', 'success' );
			}
		}
	}

	/**
	 * Scan eBay listing pages in batches with WP-Cron resume.
	 *
	 * Processes up to PAGES_PER_BATCH pages per execution, then schedules
	 * an immediate WP-Cron event to continue. This handles hosts with strict
	 * 30-second PHP time limits where set_time_limit() is disabled.
	 *
	 * Uses wp_schedule_single_event() (WordPress built-in cron) instead of
	 * Action Scheduler for resumption. WP-Cron events fire directly on the
	 * next page load — they don't compete with the AS batch queue.
	 *
	 * A 47-page store completes scanning in ~5 minutes across ~5 batches.
	 */
	const PAGES_PER_BATCH = 10;

	public function scan_all_pages() {
		// A batch PHP kills leaves a line saying how, and on which page.
		TCGiant_Sync_Runtime::watch( 'Full sync scan' );

		try {
			$this->run_scan_all_pages();
		} finally {
			TCGiant_Sync_Runtime::unwatch();
		}
	}

	/**
	 * The body of scan_all_pages(), watched by it.
	 */
	private function run_scan_all_pages() {
		// Try to extend the time limit, but don't rely on it.
		TCGiant_Sync_Runtime::raise_time_limit( 300 );

		$state = self::get_sync_state();
		$start_page = max( 1, (int) ( $state['current_page'] ?? 1 ) );

		// If resuming, start from the NEXT page (current_page was already processed).
		if ( $start_page > 1 && in_array( $state['status'] ?? '', array( 'scanning', 'rate_limited' ), true ) ) {
			$start_page++;
		}

		$api = TCGiant_Sync_API::instance();
		$mod_time_from = ( 'delta' === ( $state['sync_mode'] ?? 'full' ) ) ? ( $state['delta_mod_from'] ?? '' ) : '';

		TCGiant_Sync_Logger::log( sprintf(
			'Scan batch starting from page %d (up to %d pages). API budget: %d/%d.',
			$start_page, self::PAGES_PER_BATCH, $api->get_remaining_daily_budget(), TCGiant_Sync_API::DAILY_CALL_LIMIT
		) );

		$total_pages = (int) ( $state['total_pages'] ?? 0 );
		$pages_this_batch = 0;

		for ( $current_page = $start_page; $pages_this_batch < self::PAGES_PER_BATCH; $current_page++ ) {
			if ( $pages_this_batch > 0 ) {
				TCGiant_Sync_Runtime::release_memory();
			}
			TCGiant_Sync_Runtime::context( sprintf( 'page %d of %s', $current_page, $total_pages > 0 ? (string) $total_pages : 'an unknown number' ) );

			self::update_sync_state( array(
				'status'              => 'scanning',
				'current_page'        => $current_page,
				'rate_limit_retries'  => 0,
			) );

			$response = $api->get_active_listings( $current_page, 200, $mod_time_from );

			// Handle rate limiting — pause and schedule via WP-Cron.
			if ( is_wp_error( $response ) && 'rate_limited' === $response->get_error_code() ) {
				$retries = (int) ( self::get_sync_state()['rate_limit_retries'] ?? 0 );
				$retry_delay = $this->get_rate_limit_backoff_delay( $retries );
				self::update_sync_state( array(
					'status'             => 'rate_limited',
					'current_page'       => $current_page - 1, // Last successful page.
					'rate_limit_retries' => $retries + 1,
				) );
				TCGiant_Sync_Logger::log( sprintf(
					'eBay API rate limit hit on page %d (attempt %d). Auto-retry in %d minutes.',
					$current_page, $retries + 1, $retry_delay / 60
				), 'warning' );
				wp_schedule_single_event( time() + $retry_delay, 'tcgiant_sync_scan_resume' );
				return;
			}

			// A failed request is NOT the same as "no more pages".
			// Treating it as end-of-scan used to hand a half-populated
			// active-ID list to the pruner, which then trashed every product
			// belonging to the pages we never reached.
			if ( is_wp_error( $response ) ) {
				TCGiant_Sync_Logger::error( sprintf(
					'Scan aborted on page %d: %s. Progress kept — use "Resume Import" to continue. Orphan pruning skipped.',
					$current_page,
					$response->get_error_message()
				) );
				self::update_sync_state( array(
					'status'              => 'error',
					'current_page'        => max( 1, $current_page - 1 ), // Last page we actually completed.
					'scan_complete_clean' => false,
					// Kept so the dashboard can say what went wrong. It showed a
					// red dot and the word "Error", and the reason lived only in
					// the log where nobody thought to look.
					'last_error'          => sprintf(
						'Stopped on page %d: %s',
						$current_page,
						$response->get_error_message()
					),
				) );
				self::release_lock();
				return;
			}

			// Genuinely empty page — we have reached the end of the listings.
			if ( ! isset( $response['ItemArray']['Item'] ) ) {
				$this->finalize_scan( true );
				return;
			}

			// Parse items from the response.
			$items = $response['ItemArray']['Item'];
			if ( ! isset( $items[0] ) ) {
				$items = array( $items );
			}

			$total_pages = isset( $response['PaginationResult']['TotalNumberOfPages'] ) ? (int) $response['PaginationResult']['TotalNumberOfPages'] : 1;
			self::update_sync_state( array( 'total_pages' => $total_pages ) );

			// Process all items on this page inline.
			$this->process_page_items( $items, $current_page, $total_pages );
			$pages_this_batch++;

			TCGiant_Sync_Logger::log( sprintf(
				'Page %d/%d scanned (%d/%d in this batch).',
				$current_page, $total_pages, $pages_this_batch, self::PAGES_PER_BATCH
			) );

			// Check if we've reached the last page.
			if ( $current_page >= $total_pages ) {
				$this->finalize_scan( true );
				return;
			}

			// Rate-limit pause between pages — 2 seconds to avoid hammering eBay.
			sleep( 2 );
		}

		// Batch complete but more pages remain.
		// Schedule immediate resume via WP-Cron (NOT Action Scheduler).
		TCGiant_Sync_Logger::log( sprintf(
			'Batch complete (%d pages). Scheduling immediate resume for page %d/%d via WP-Cron.',
			$pages_this_batch, $current_page, $total_pages
		) );
		wp_schedule_single_event( time() - 1, 'tcgiant_sync_scan_resume' );

		// spawn_cron() was a no-op here: it returns immediately when DOING_CRON
		// is defined, which it is, because this scan is itself running from
		// cron. Dispatching on shutdown instead means the loopback fires once
		// this run has released the cron lock, so the next batch starts in
		// seconds rather than waiting for the next natural tick.
		TCGiant_Sync_Cron::request_dispatch();
	}

	/**
	 * Where the delta sync should pick up after a full sync: when it STARTED.
	 *
	 * A full sync reads a page at a time over minutes or hours. A listing on a
	 * page already read that sold before the scan finished was recorded as
	 * read up to the moment the scan finished, so the delta started after the
	 * sale and never saw it - and on a quantity-1 shop a missed sale is an
	 * oversell. Starting from the scan's own start re-reads anything that
	 * changed during it, which costs a little and misses nothing.
	 *
	 * @param array $state Sync state.
	 * @return string ISO 8601 UTC.
	 */
	private static function full_sync_position( array $state ) {
		$started = (string) ( $state['full_started_at'] ?? '' );

		return '' !== $started && false !== strtotime( $started ) ? $started : gmdate( 'Y-m-d\TH:i:s.000\Z' );
	}

	/**
	 * Finalize the scan — transition to importing or complete state.
	 *
	 * @param bool $clean True when every page was scanned without error. Only a
	 *                    clean scan may authorise orphan pruning, since the
	 *                    pruner trashes anything absent from the active-ID list.
	 */
	private function finalize_scan( $clean = false ) {
		$state = self::get_sync_state();

		if ( $state['total_queued'] > 0 || $state['total_processed'] > 0 ) {
			self::update_sync_state( array(
				'status'              => 'importing',
				'scan_complete_clean' => (bool) $clean,
			) );
			TCGiant_Sync_Logger::log( sprintf(
				'Scan complete. %d found, %d imported inline, %d queued for GetItem across %d pages.',
				$state['total_found'], $state['total_processed'], $state['total_queued'], $state['total_pages']
			) );
		} else {
			self::update_sync_state( array(
				'status'                  => 'complete',
				'last_completed'          => current_time( 'mysql' ),
				'last_successful_sync_at' => self::full_sync_position( $state ),
				'scan_complete_clean'     => (bool) $clean,
			) );
			TCGiant_Sync_Logger::log( 'Scan complete. No matching items found.' );
			if ( $clean ) {
				as_enqueue_async_action( 'tcgiant_sync_prune_orphans', array(), 'tcgiant_sync_group' );
			}
		}

		// Release the concurrent execution lock.
		self::release_lock();
	}

	/**
	 * Process all items from a single page of eBay listings.
	 *
	 * Called from the page-scanning loop in scan_all_pages().
	 *
	 * @param array $items       Array of eBay item arrays.
	 * @param int   $page_number Current page number.
	 * @param int   $total_pages Total pages available.
	 */
	private function process_page_items( $items, $page_number, $total_pages ) {
		$queued_count = 0;
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$is_custom_filtering = ! empty( $settings['category_ids'] );
		$standard_cats       = ! empty( $settings['import_standard_category_ids'] ) && is_array( $settings['import_standard_category_ids'] ) ? $settings['import_standard_category_ids'] : array();
		$is_filtering        = $is_custom_filtering || ! empty( $standard_cats );

		$valid_custom_ids = $is_custom_filtering ? $this->get_allowed_category_ids() : array();

		// Debug: log resolved category IDs on the first page.
		if ( $page_number === 1 && $is_filtering ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Category filter active. Custom IDs: [%s]. Standard IDs: [%s].',
				implode( ', ', $valid_custom_ids ),
				implode( ', ', $standard_cats )
			) );
			if ( ! empty( $items[0] ) ) {
				$sample = $items[0];
				TCGiant_Sync_Logger::log( sprintf(
					'Sample item "%s": PrimaryCat=%s, StoreCat1=%s, StoreCat2=%s',
					$sample['Title'] ?? '?',
					$sample['PrimaryCategory']['CategoryID'] ?? 'n/a',
					$sample['Storefront']['StoreCategoryID'] ?? 'n/a',
					$sample['Storefront']['StoreCategory2ID'] ?? 'n/a'
				) );
			}
		}

		$active_ids_batch = array();
		$mapper = TCGiant_Sync_Mapper::instance();
		$license = TCGiant_Sync_Entitlements::instance();
		$inline_processed = 0;
		$inline_errors = 0;

		foreach ( $items as $item ) {
			$item_id = $item['ItemID'] ?? '';

			if ( empty( $item_id ) ) {
				continue;
			}

			$active_ids_batch[] = $item_id;

			// Category pre-filter.
			if ( $is_filtering ) {
				$primary_cat = $item['PrimaryCategory']['CategoryID'] ?? '';
				$store_cat1 = $item['Storefront']['StoreCategoryID'] ?? '';
				$store_cat2 = $item['Storefront']['StoreCategory2ID'] ?? '';

				$match = false;

				if ( ! empty( $standard_cats ) ) {
					if ( in_array( (string) $primary_cat, $standard_cats, true ) ) $match = true;
				}

				if ( ! empty( $valid_custom_ids ) ) {
					if ( in_array( (string) $primary_cat, $valid_custom_ids, true ) ) $match = true;
					if ( in_array( (string) $store_cat1, $valid_custom_ids, true ) ) $match = true;
					if ( in_array( (string) $store_cat2, $valid_custom_ids, true ) ) $match = true;
				}

				if ( ! $match ) {
					continue;
				}
			}

			// License check per item.
			//
			// The free limit caps how many products a store may hold, not
			// whether the ones it already has stay in step with eBay. This
			// stopped the loop outright, so a store that went over stopped
			// syncing altogether — no stock, no prices, no ended listings
			// — while the seller carried on trading from figures that were
			// no longer true. Only listings that would create a new product
			// are held back now.
			if ( ! self::product_exists_for_item( isset( $item['ItemID'] ) ? $item['ItemID'] : '' )
				&& ( self::$limit_reported || ! $license->can_import() ) ) {
				if ( ! self::$limit_reported ) {
					self::$limit_reported = true;
					self::update_sync_state( array( 'status' => 'limit_reached' ) );
					TCGiant_Sync_Logger::log( sprintf(
						'Import limit reached (%d/%d products). New listings will not be imported; products already imported carry on syncing normally.',
						$license->get_active_product_count(),
						$license->get_free_limit()
					), 'warning' );
				}
				continue;
			}

			// Determine if this item needs a GetItem fallback.
			$has_variations = isset( $item['Variations'] );
			$missing_critical = ! isset( $item['Title'] ) || ! isset( $item['SellingStatus'] );
			// PictureURL, not the PictureDetails container — see fetch_delta_events().
			$missing_specs_or_images = ! isset( $item['ItemSpecifics'] ) || ! isset( $item['PictureDetails']['PictureURL'] );

			$can_inline = ! $missing_critical && ! $missing_specs_or_images;
			if ( $has_variations && $can_inline ) {
				$can_inline = isset( $item['Variations']['Variation'] ) && ! empty( $item['Variations']['Variation'] );
			}

			if ( $can_inline ) {
				try {
					$title = $item['Title'] ?? 'Unknown';
					$product_data = $mapper->map_ebay_to_woo( $item );
					$product_id = $mapper->save_as_product( $product_data );

					if ( $product_id ) {
						if ( ! empty( $product_data['variations'] ) ) {
							foreach ( $product_data['variations'] as $var ) {
								if ( ! empty( $var['image_url'] ) && ! empty( $var['variation_id'] ) ) {
									$product_data['images'][] = array( $var['image_url'], $var['variation_id'] );
								}
							}
						}

						// Set external eBay image URLs — localized in background.
						if ( ! empty( $product_data['images'] ) ) {
							TCGiant_Sync_Image_Localizer::set_external_images( $product_id, $product_data['images'] );
						}

						$inline_processed++;
						$price_display = ! empty( $product_data['price'] ) ? '$' . $product_data['price'] : 'No price';
						$attr_count = count( $product_data['attributes'] );
						$weight_display = ! empty( $product_data['weight'] ) ? ', ' . $product_data['weight'] . get_option( 'woocommerce_weight_unit', 'lbs' ) : '';
						$var_label = $has_variations ? ' [inline+vars]' : ' [inline]';
						TCGiant_Sync_Logger::log( sprintf(
							'Imported: "%s" -> WC #%d (%s, Qty: %d, %d attrs%s)%s',
							$title, $product_id, $price_display, $product_data['stock_quantity'], $attr_count, $weight_display, $var_label
						), 'success' );
					} elseif ( ! TCGiant_Sync_Mapper::$last_skipped ) {
						$inline_errors++;
						TCGiant_Sync_Logger::error( sprintf( 'Failed to save product for eBay Item: %s [inline]', $item_id ) );
					}
				} catch ( Exception $e ) {
					$inline_errors++;
					TCGiant_Sync_Logger::error( 'Inline import exception for ' . $item_id . ': ' . $e->getMessage() );
				} catch ( Error $e ) {
					$inline_errors++;
					TCGiant_Sync_Logger::error( 'Inline import fatal for ' . $item_id . ': ' . $e->getMessage() );
				}
			} else {
				// Queue for GetItem fallback.
				$delay = min( $queued_count, 60 );
				as_schedule_single_action( time() + $delay, 'tcgiant_sync_process_item_import', array( 'item_id' => $item_id ), 'tcgiant_sync_imports' );
				$queued_count++;
			}
		}

		self::append_active_ids( $active_ids_batch );

		// Update state with running totals.
		$state = self::get_sync_state();
		$new_total_found = $state['total_found'] + count( $items );
		$new_total_queued = $state['total_queued'] + $queued_count;
		$new_total_processed = $state['total_processed'] + $inline_processed;
		$new_total_errors = $state['total_errors'] + $inline_errors;

		$last_title = '';
		if ( ! empty( $items ) ) {
			$last_item = end( $items );
			$last_title = $last_item['Title'] ?? '';
		}

		self::update_sync_state( array(
			'current_page'    => $page_number,
			'total_found'     => $new_total_found,
			'total_queued'    => $new_total_queued,
			'total_processed' => $new_total_processed,
			'total_errors'    => $new_total_errors,
			'last_item_title' => $last_title,
		) );

		// Log summary.
		if ( $page_number <= $total_pages ) {
			$matched_label = $is_filtering ? sprintf( ', %d matched filter', $inline_processed + $queued_count ) : '';
			$inline_label = $inline_processed > 0 ? sprintf( ', %d imported inline', $inline_processed ) : '';
			$queued_label = $queued_count > 0 ? sprintf( ', %d queued for GetItem', $queued_count ) : '';
			$filter_label = '';
			TCGiant_Sync_Logger::log( sprintf(
				'Page %d/%d: Scanned %d items%s%s%s%s.',
				$page_number, $total_pages, count( $items ), $matched_label, $inline_label, $queued_label, $filter_label
			) );
		}
	}



	/**
	 * Resolves User Category String Settings into eBay Category IDs.
	 */
	private function get_allowed_category_ids() {
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$raw_category_setting = $settings['category_ids'] ?? '';
		
		$transient_key = 'tcgiant_sync_cats_' . md5( $raw_category_setting );
		$valid_ids = get_transient( $transient_key );
		if ( false !== $valid_ids ) {
			return $valid_ids;
		}

		$allowed_strings = ! empty( $raw_category_setting ) ? explode( ',', $raw_category_setting ) : array();
		
		if ( empty( $allowed_strings ) ) {
			return array();
		}

		$allowed_strings = array_map( 'strtolower', array_map( 'trim', $allowed_strings ) );
		$valid_ids = array();

		// Hard global numeric IDs.
		foreach ( $allowed_strings as $str ) {
			if ( is_numeric( $str ) ) {
				$valid_ids[] = (string) $str;
			}
		}

		// Download Store Category Tree.
		$api = TCGiant_Sync_API::instance();
		$store_response = $api->get_store();
		if ( ! is_wp_error( $store_response ) && isset( $store_response['Store']['CustomCategories']['CustomCategory'] ) ) {
			$categories = $store_response['Store']['CustomCategories']['CustomCategory'];
			if ( isset( $categories['CategoryID'] ) ) {
				$categories = array( $categories );
			}
			
			$flatten = function( $cats ) use ( &$flatten, &$valid_ids, $allowed_strings ) {
				if ( ! is_array( $cats ) ) return;
				foreach ( $cats as $cat ) {
					$name = strtolower( trim( $cat['Name'] ?? '' ) );
					$id = $cat['CategoryID'] ?? '';
					if ( $name && $id && in_array( $name, $allowed_strings, true ) ) {
						$valid_ids[] = (string) $id;
					}
					if ( isset( $cat['ChildCategory'] ) ) {
						$children = isset( $cat['ChildCategory']['CategoryID'] ) ? array( $cat['ChildCategory'] ) : $cat['ChildCategory'];
						$flatten( $children );
					}
				}
			};
			$flatten( $categories );
		}

		$valid_ids = array_unique( $valid_ids );
		set_transient( $transient_key, $valid_ids, 3600 );
		return $valid_ids;
	}

	/**
	 * The live (non-trashed) product linked to an eBay listing, if any.
	 *
	 * @param string $item_id eBay ItemID.
	 * @return int Product ID, or 0.
	 */
	public static function find_live_product_for_item( $item_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_ebay_item_id' AND pm.meta_value = %s
			   AND p.post_status != 'trash'
			 LIMIT 1",
			(string) $item_id
		) );
	}

	/**
	 * Record that a product's eBay listing is over, settle its stock, and decide
	 * whether the product should still be here.
	 *
	 * Shared by the delta sync and the per-listing stock check, so the two can
	 * never disagree about what an ended listing means.
	 *
	 * Products are never CREATED for ended listings - a sale is a modification,
	 * so the items most likely to arrive here are the ones that just sold, and
	 * importing them looked like deleted products rising from the dead. Only an
	 * existing product is brought up to date.
	 *
	 * A listing with one of something ends the moment it sells, so this - not
	 * the ordinary import - is where a sale of a single item is seen. eBay's own
	 * figures decide: sold out leaves nothing, a listing ended by hand still
	 * reports what went unsold. And they can only lower WooCommerce's stock, never
	 * raise it - see apply_ended_listing_stock().
	 *
	 * @param int    $existing_id    Product linked to the listing.
	 * @param array  $ebay_item      The listing as eBay sent it.
	 * @param string $listing_status eBay's ListingStatus.
	 * @param string $source         Prefix for the log lines.
	 * @return bool Whether a GetItem call was needed for the figures.
	 */
	private static function settle_ended_listing( $existing_id, array $ebay_item, $listing_status, $source ) {
		$existing_id = (int) $existing_id;
		$item_id     = (string) ( $ebay_item['ItemID'] ?? '' );
		$used_get    = false;

		update_post_meta( $existing_id, '_ebay_listing_status', 'Ended' );
		if ( ! empty( $ebay_item['ListingDetails']['EndTime'] ) ) {
			update_post_meta( $existing_id, '_ebay_end_time', $ebay_item['ListingDetails']['EndTime'] );
		}

		// Recorded on the way past even though the listing is over: a seller
		// rotating unsold stock wants to know how long the last attempt ran.
		if ( ! empty( $ebay_item['ListingDetails']['StartTime'] ) ) {
			update_post_meta( $existing_id, '_ebay_start_time', $ebay_item['ListingDetails']['StartTime'] );
		}

		// This listing's ending is already in the stock - settled by the sale
		// check, or when the plugin ended it at 0. Whatever the product holds
		// now was entered since, and applying the old figures again would wipe
		// a restock.
		if ( TCGiant_Sync_Inventory::listing_already_settled( $existing_id, $item_id ) ) {
			TCGiant_Sync_Logger::log( sprintf(
				'%s: eBay item %s is %s — WC #%d marked ended; its sales were already in the stock, so the stock is left as it is.',
				$source, $item_id, $listing_status, $existing_id
			) );
			return false;
		}

		$listed = $ebay_item['Quantity'] ?? null;
		$sold   = $ebay_item['SellingStatus']['QuantitySold'] ?? null;

		// The events feed is often partial. Ask for the item outright rather
		// than guess at a quantity.
		if ( null === $listed || null === $sold ) {
			$ended_detail = TCGiant_Sync_API::instance()->get_item( $item_id );
			if ( ! is_wp_error( $ended_detail ) && isset( $ended_detail['Item'] ) ) {
				$used_get = true;
				$listed   = $ended_detail['Item']['Quantity'] ?? null;
				$sold     = $ended_detail['Item']['SellingStatus']['QuantitySold'] ?? null;
			}
		}

		$remaining = TCGiant_Sync_Inventory::apply_ended_listing_stock( (int) $existing_id, $listed, $sold );

		if ( null === $remaining ) {
			TCGiant_Sync_Logger::log( sprintf(
				'%s: eBay item %s is %s — marked WC #%d as ended. Its stock could not be settled, so it is unchanged; the preceding line says why.',
				$source, $item_id, $listing_status, $existing_id
			), 'warning' );
			return $used_get;
		}

		// Settle the stock first and decide afterwards whether the product should
		// still be here at all. In that order, because a removal that is refused
		// must still leave the quantity correct.
		if ( TCGiant_Sync_Inventory::maybe_remove_sold_out( (int) $existing_id, $listed, $sold ) ) {
			TCGiant_Sync_Logger::log( sprintf(
				'%s: eBay item %s sold out — WC #%d moved to the trash (%d listed, %d sold).',
				$source, $item_id, $existing_id, (int) $listed, (int) $sold
			) );
			return $used_get;
		}

		TCGiant_Sync_Logger::log( sprintf(
			'%s: eBay item %s is %s — WC #%d marked ended, stock now %d (eBay: %d listed, %d sold).',
			$source, $item_id, $listing_status, $existing_id, $remaining, (int) $listed, (int) $sold
		) );

		return $used_get;
	}

	/**
	 * Queue a stock check for one eBay listing, unless one is already waiting.
	 *
	 * @param string $item_id eBay ItemID.
	 * @return bool Whether one was queued.
	 */
	public static function queue_settle( $item_id ) {
		$item_id = (string) $item_id;

		if ( '' === $item_id || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		if ( function_exists( 'as_next_scheduled_action' )
			&& false !== as_next_scheduled_action( 'tcgiant_sync_settle_item', array( $item_id ), self::GROUP_SETTLE ) ) {
			return false;
		}

		as_enqueue_async_action( 'tcgiant_sync_settle_item', array( $item_id ), self::GROUP_SETTLE );

		return true;
	}

	/**
	 * Bring one product into line with its eBay listing, now.
	 *
	 * Queued for every eBay sale of a linked product, and for a listing the delta
	 * had to step over. Order sync used to skip linked products altogether, on
	 * the grounds that the delta sync sets their stock from eBay - true while the
	 * delta keeps up, and nothing at all while it is behind. A shop whose delta
	 * had been stuck for eleven days saw no eBay sale reach the site in that
	 * time, and removed sold items by hand.
	 *
	 * An ended listing is settled exactly as the delta would settle it. A live
	 * one only has its stock lowered to what eBay says is left, never raised -
	 * the delta and the mapper own everything else about a live listing.
	 *
	 * @param string $item_id eBay ItemID.
	 */
	public function settle_item( $item_id ) {
		$item_id     = (string) $item_id;
		$existing_id = self::find_live_product_for_item( $item_id );

		if ( ! $existing_id ) {
			return;
		}

		$detail = TCGiant_Sync_API::instance()->get_item( $item_id );

		if ( is_wp_error( $detail ) || empty( $detail['Item'] ) ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Sale check: could not read eBay item %s for WC #%d (%s). The delta sync will settle it when it reaches the sale.',
				$item_id,
				$existing_id,
				is_wp_error( $detail ) ? $detail->get_error_message() : 'eBay returned nothing'
			) );
			return;
		}

		$item   = $detail['Item'];
		$status = (string) ( $item['SellingStatus']['ListingStatus'] ?? '' );

		if ( '' !== $status && 'Active' !== $status ) {
			self::settle_ended_listing( $existing_id, $item, $status, 'Sale check' );
			return;
		}

		// A variation listing's Quantity is the whole listing's, which says
		// nothing about any one variation. The delta handles those.
		if ( ! empty( $item['Variations'] ) ) {
			return;
		}

		$listed = $item['Quantity'] ?? null;
		$sold   = $item['SellingStatus']['QuantitySold'] ?? null;

		if ( null === $listed || null === $sold ) {
			return;
		}

		$now = TCGiant_Sync_Inventory::lower_stock_to( $existing_id, max( 0, (int) $listed - (int) $sold ) );

		if ( null !== $now ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Sale check: eBay item %s - WC #%d stock now %d (eBay: %d listed, %d sold).',
				$item_id, $existing_id, $now, (int) $listed, (int) $sold
			) );
		}
	}

	/**
	 * Sync recent orders and reduce stock.
	 */
	public function sync_recent_orders() {
		$api = TCGiant_Sync_API::instance();
		$response = $api->get_orders();

		if ( is_wp_error( $response ) ) {
			TCGiant_Sync_Logger::error( 'Order Sync failed: ' . $response->get_error_message() );
			return;
		}

		if ( empty( $response['orders'] ) ) {
			TCGiant_Sync_Logger::log( 'No recent orders found on eBay.' );
			return;
		}

		$order_count  = 0;
		$settle_count = 0;

		// "Reduce WooCommerce stock on eBay sale?" - for products NOT linked to
		// a listing. Linked products get a stock check whatever it says.
		$sync_settings   = get_option( 'tcgiant_sync_ebay_settings', array() );
		$reduce_unlinked = is_array( $sync_settings ) && '1' === (string) ( $sync_settings['enable_order_sync'] ?? '' );
		foreach ( $response['orders'] as $order ) {
			if ( empty( $order['lineItems'] ) ) {
				continue;
			}
			
			foreach ( $order['lineItems'] as $line ) {
				$sku = $line['sku'] ?? '';
				$legacy_item_id = $line['legacyItemId'] ?? '';

				if ( empty( $sku ) && ! empty( $legacy_item_id ) ) {
					$sku = 'EBAY-' . $legacy_item_id;
				}

				$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
				$line_item_id = $line['lineItemId'] ?? '';

				if ( empty( $sku ) || empty( $line_item_id ) ) {
					continue;
				}

				$product_id = wc_get_product_id_by_sku( $sku );

				if ( ! $product_id && ! empty( $legacy_item_id ) ) {
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$fallback_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ebay_item_id' AND meta_value = %s LIMIT 1", $legacy_item_id ) );
					if ( $fallback_id ) {
						$product_id = (int) $fallback_id;
					}
				}

				if ( ! $product_id ) {
					continue;
				}

				$processed = get_post_meta( $product_id, '_ebay_order_processed_' . $line_item_id, true );
				if ( $processed ) {
					continue;
				}

				// Products linked to an eBay listing have their quantity set outright
				// from eBay on every sync ("Stock is ALWAYS managed by eBay" in the
				// mapper), and eBay's figure already excludes what has sold. Taking
				// another unit off here deducted the same sale twice. Products with no
				// eBay link are not touched by that path, so they still need this.
				//
				// But only while the delta keeps up, and a delta that is behind
				// brought no eBay sale to the site at all. So each sale of a linked
				// product now queues a stock check for its listing: it reads eBay's
				// own figures and only ever lowers stock, so it cannot deduct twice.
				$linked_item = (string) get_post_meta( $product_id, '_ebay_item_id', true );
				if ( '' !== $linked_item ) {
					// The listing that sold, which is not always the one this
					// product's SKU happened to match.
					if ( self::queue_settle( '' !== (string) $legacy_item_id ? (string) $legacy_item_id : $linked_item ) ) {
						$settle_count++;
					}
					update_post_meta( $product_id, '_ebay_order_processed_' . $line_item_id, current_time( 'mysql' ) );
					continue;
				}

				// Not linked, and the shop has not asked for these to be reduced.
				// Left unmarked, so switching the setting on later still reduces
				// a recent sale.
				if ( ! $reduce_unlinked ) {
					continue;
				}

				// eBay already decremented its own quantity when the order was
				// placed — suppress the WC → eBay push so we don't echo it back.
				TCGiant_Sync_Inventory::begin_ebay_origin();
				try {
					$stock_reduced = wc_update_product_stock( $product_id, $quantity, 'decrease' );
				} finally {
					TCGiant_Sync_Inventory::end_ebay_origin();
				}

				if ( ! is_wp_error( $stock_reduced ) ) {
					update_post_meta( $product_id, '_ebay_order_processed_' . $line_item_id, current_time( 'mysql' ) );
					TCGiant_Sync_Logger::log( sprintf( 'Reduced stock for WC Product %d by %d (eBay order).', $product_id, $quantity ), 'success' );
					$order_count++;
				}
			}
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Order sync complete. Processed %d new line items; %d eBay sales of linked products queued for a stock check.',
			$order_count,
			$settle_count
		) );
	}

	/**
	 * Process a single eBay Item ID.
	 *
	 * @param string $item_id The eBay Item ID to import.
	 */
	public function process_item_import( $item_id ) {
		try {
			// Per-item license check.
			//
			// Only for listings that would create a new product. An item already
			// imported is queued here because eBay's list response was short of
			// detail, and refusing to finish it would freeze that product at
			// whatever it last held. The queue is no longer cleared for the same
			// reason: it holds updates to existing products as well as new ones,
			// and this returns before spending an API call anyway.
			$license = TCGiant_Sync_Entitlements::instance();
			if ( ! self::product_exists_for_item( $item_id )
				&& ( self::$limit_reported || ! $license->can_import() ) ) {
				if ( ! self::$limit_reported ) {
					self::$limit_reported = true;
					self::update_sync_state( array( 'status' => 'limit_reached' ) );
					TCGiant_Sync_Logger::log(
						sprintf(
							'Import limit reached (%d/%d products). New listings will not be imported; products already imported carry on syncing normally. Upgrade to Pro for unlimited.',
							$license->get_active_product_count(),
							$license->get_free_limit()
						),
						'warning'
					);
				}
				return;
			}

			$api = TCGiant_Sync_API::instance();
			$ebay_response = $api->get_item( $item_id );

			// Handle rate limiting: reschedule this item for later with escalating backoff.
			if ( is_wp_error( $ebay_response ) && 'rate_limited' === $ebay_response->get_error_code() ) {
				$state_for_retry = self::get_sync_state();
				$retries = (int) ( $state_for_retry['rate_limit_retries'] ?? 0 );
				$retry_delay = $this->get_rate_limit_backoff_delay( $retries );
				self::update_sync_state( array(
					'status'             => 'rate_limited',
					'rate_limit_retries' => $retries + 1,
				) );
				TCGiant_Sync_Logger::log( sprintf(
					'eBay API rate limit hit while importing item %s (attempt %d). Rescheduled for %d minutes later.',
					$item_id,
					$retries + 1,
					$retry_delay / 60
				), 'warning' );
				as_schedule_single_action( time() + $retry_delay, 'tcgiant_sync_process_item_import', array( 'item_id' => $item_id ), 'tcgiant_sync_imports' );
				return;
			}

			if ( is_wp_error( $ebay_response ) || ! isset( $ebay_response['Item'] ) ) {
				self::update_sync_state( array(
					'total_errors' => self::get_sync_state()['total_errors'] + 1,
				) );
				TCGiant_Sync_Logger::error( 'Import failed for eBay Item ID: ' . $item_id );
				$this->check_sync_completion();
				return;
			}

			$ebay_item = $ebay_response['Item'];
			$title = $ebay_item['Title'] ?? 'Unknown';

			$mapper = TCGiant_Sync_Mapper::instance();
			$product_data = $mapper->map_ebay_to_woo( $ebay_item );
			
			$product_id = $mapper->save_as_product( $product_data );

			if ( $product_id ) {
				// Append variation images to the download queue
				if ( ! empty( $product_data['variations'] ) ) {
					foreach ( $product_data['variations'] as $var ) {
						if ( ! empty( $var['image_url'] ) && ! empty( $var['variation_id'] ) ) {
							$product_data['images'][] = array( $var['image_url'], $var['variation_id'] );
						}
					}
				}

				// Set external eBay image URLs — localized in background.
				if ( ! empty( $product_data['images'] ) ) {
					TCGiant_Sync_Image_Localizer::set_external_images( $product_id, $product_data['images'] );
				}

				$state = self::get_sync_state();
				self::update_sync_state( array(
					'total_processed' => $state['total_processed'] + 1,
					'last_item_title' => $title,
				) );

				$price_display = ! empty( $product_data['price'] ) ? '$' . $product_data['price'] : 'No price';
				$attr_count = count( $product_data['attributes'] );
				$weight_display = ! empty( $product_data['weight'] ) ? ', ' . $product_data['weight'] . get_option( 'woocommerce_weight_unit', 'lbs' ) : '';
				TCGiant_Sync_Logger::log( sprintf(
					'Imported: "%s" -> WC #%d (%s, Qty: %d, %d attrs%s)',
					$title, $product_id, $price_display, $product_data['stock_quantity'], $attr_count, $weight_display
				), 'success' );
			} elseif ( ! TCGiant_Sync_Mapper::$last_skipped ) {
				self::update_sync_state( array(
					'total_errors' => self::get_sync_state()['total_errors'] + 1,
				) );
				TCGiant_Sync_Logger::error( sprintf( 'Failed to save product for eBay Item: %s (%s)', $item_id, $title ) );
			}

			$this->check_sync_completion();
			
		} catch ( Exception $e ) {
			self::update_sync_state( array(
				'total_errors' => self::get_sync_state()['total_errors'] + 1,
			) );
			TCGiant_Sync_Logger::error( 'Exception importing item ' . $item_id . ': ' . $e->getMessage() );
			$this->check_sync_completion();
		} catch ( Error $e ) {
			self::update_sync_state( array(
				'total_errors' => self::get_sync_state()['total_errors'] + 1,
			) );
			TCGiant_Sync_Logger::error( 'Fatal error importing item ' . $item_id . ': ' . $e->getMessage() );
			$this->check_sync_completion();
		}
	}

	/**
	 * Check if the sync is complete (all items processed or no jobs remain).
	 */
	private function check_sync_completion() {
		$state = self::get_sync_state();
		if ( 'importing' !== $state['status'] ) {
			return;
		}

		$completed = $state['total_processed'] + $state['total_errors'];

		// Primary check: all items accounted for.
		$is_done = ( $completed >= $state['total_queued'] && $state['total_queued'] > 0 );

		// Fallback: if Action Scheduler has no remaining jobs, we're done regardless of counters.
		if ( ! $is_done && function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions( array(
				'hook'   => 'tcgiant_sync_process_item_import',
				'group'  => self::GROUP_IMPORTS,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			) );
			if ( empty( $pending ) ) {
				$is_done = true;
			}
		}

		if ( $is_done ) {
			$mode       = $state['sync_mode'] ?? 'full';
			$mode_label = ( 'delta' === $mode ) ? 'Delta sync' : 'Sync';
			$done_state = array(
				'status'             => 'complete',
				'last_completed'     => current_time( 'mysql' ),
				'rate_limit_retries' => 0,
			);

			// Only a full sync has read everything up to now, so only a full
			// sync may say so. Finishing a few hand-picked items moved the
			// position the delta sync reads from to the present as well, and
			// every change on eBay since its last window - an hour's worth on
			// a shop in step, far more on one catching up - was never read.
			if ( 'full' === $mode ) {
				$done_state['last_successful_sync_at'] = self::full_sync_position( $state );
			}

			self::update_sync_state( $done_state );
			TCGiant_Sync_Logger::log( sprintf(
				'%s complete! %d imported, %d errors out of %d total.',
				$mode_label, $state['total_processed'], $state['total_errors'], $state['total_queued']
			), 'success' );

			// Trigger cleanup of sold/ended items.
			as_enqueue_async_action( 'tcgiant_sync_prune_orphans', array(), 'tcgiant_sync_group' );
		}
	}

	/**
	 * Remove WooCommerce products that are no longer active on eBay.
	 *
	 * This is the single most destructive operation in the plugin, so it is
	 * gated three ways:
	 *
	 *   1. The last scan must have completed cleanly (scan_complete_clean).
	 *      A scan aborted by an API error leaves a partial active-ID list, and
	 *      pruning against that trashes every product on the pages we never
	 *      reached.
	 *   2. The active-ID list must be non-empty. An empty list cannot be
	 *      distinguished from a failure, and verifying each product against the
	 *      API instead costs one Trading API call per product.
	 *   3. The prune set must not exceed PRUNE_MAX_RATIO of the catalogue.
	 */
	public function prune_orphaned_items() {
		$state = self::get_sync_state();

		if ( empty( $state['scan_complete_clean'] ) ) {
			TCGiant_Sync_Logger::warning(
				'Inventory Pruning: Skipped — the last scan did not complete cleanly. '
				. 'Run a full sync to completion before orphaned products can be retired.'
			);
			return;
		}

		$active_ids = get_option( self::ACTIVE_IDS_OPTION, array() );
		if ( ! is_array( $active_ids ) || empty( $active_ids ) ) {
			TCGiant_Sync_Logger::warning(
				'Inventory Pruning: Skipped — no active eBay Item IDs were recorded during the scan. '
				. 'Refusing to prune, since an empty list is indistinguishable from a failed scan.'
			);
			return;
		}

		global $wpdb;
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$preserve_cats = isset( $settings['preserve_woo_category_ids'] ) && is_array( $settings['preserve_woo_category_ids'] ) ? $settings['preserve_woo_category_ids'] : array();

		// O(1) membership test. in_array() over a 10k-element list, once per
		// product, is 100M string comparisons on a large catalogue.
		$active_lookup = array_flip( array_map( 'strval', $active_ids ) );

		// Get all products that have an _ebay_item_id.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ebay_linked_products = $wpdb->get_results(
			"SELECT post_id, meta_value as ebay_id
			FROM {$wpdb->postmeta} pm
			JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_ebay_item_id' AND pm.meta_value != '' AND p.post_type = 'product' AND p.post_status != 'trash'"
		);

		$total_linked = count( $ebay_linked_products );

		// Decide the prune set up front so we can sanity-check its size before
		// touching anything.
		$prune_rows = array();
		foreach ( $ebay_linked_products as $row ) {
			if ( ! isset( $active_lookup[ (string) $row->ebay_id ] ) ) {
				$prune_rows[] = $row;
			}
		}

		$prune_count = count( $prune_rows );

		if ( 0 === $prune_count ) {
			TCGiant_Sync_Logger::log( 'Inventory Pruning: No orphaned products found.' );
			delete_option( self::ACTIVE_IDS_OPTION );
			return;
		}

		/**
		 * Filter the maximum share of eBay-linked products a single prune may retire.
		 *
		 * @param float $ratio        Between 0 and 1. Default self::PRUNE_MAX_RATIO.
		 * @param int   $prune_count  Number of products this run would retire.
		 * @param int   $total_linked Total eBay-linked products.
		 */
		$max_ratio = (float) apply_filters( 'tcgiant_sync_prune_max_ratio', self::PRUNE_MAX_RATIO, $prune_count, $total_linked );

		if ( $total_linked > 0 && $max_ratio > 0 && ( $prune_count / $total_linked ) > $max_ratio ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Inventory Pruning: ABORTED — would have trashed %d of %d eBay-linked products (%.1f%%, limit %.0f%%). '
				. 'This normally means the scan was incomplete. Nothing was changed. '
				. 'Re-run a full sync; if the removal is genuine, raise the limit with the '
				. 'tcgiant_sync_prune_max_ratio filter.',
				$prune_count,
				$total_linked,
				( $prune_count / $total_linked ) * 100,
				$max_ratio * 100
			) );
			return;
		}

		$trashed_count   = 0;
		$preserved_count = 0;

		foreach ( $prune_rows as $row ) {
			$product_id = (int) $row->post_id;

			$is_preserved = ! empty( $preserve_cats ) && has_term( $preserve_cats, 'product_cat', $product_id );

			if ( $is_preserved ) {
				// The listing is already gone from eBay; zeroing WC stock must
				// not trigger a push (which would try to end an already-ended
				// listing and burn an API call per product).
				TCGiant_Sync_Inventory::begin_ebay_origin();
				try {
					wc_update_product_stock( $product_id, 0, 'set' );
					wc_update_product_stock_status( $product_id, 'outofstock' );
				} finally {
					TCGiant_Sync_Inventory::end_ebay_origin();
				}
				delete_post_meta( $product_id, '_ebay_item_id' );
				$preserved_count++;
			} else {
				wp_trash_post( $product_id );
				$trashed_count++;
			}
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Inventory Pruning: Trashed %d products, preserved %d products (set to out of stock), out of %d eBay-linked.',
			$trashed_count, $preserved_count, $total_linked
		), 'success' );

		delete_option( self::ACTIVE_IDS_OPTION );
	}








}
