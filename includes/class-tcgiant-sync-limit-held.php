<?php
/**
 * New eBay listings the free limit held back, kept for a move to Pro.
 *
 * At the free limit a sync imports no new listing: the delta, the full scan
 * and a queued import each step over one with no product here. The delta
 * never reads it again - its window moves on - so a shop whose Pro licence
 * lapsed for an hour, or could not be checked, lost every listing it put on
 * eBay meanwhile until the weekly full sync, and the log called them
 * "skipped (category filter)".
 *
 * So each one held back is written down here (note()), the newest
 * LIMIT_HELD_MAX, and when the site turns Pro - a key activated, or a check
 * that finds the licence valid again - they are imported through
 * TCGiant_Sync_Importer::start_specific_sync(), REPLAY_PER_RUN at a time,
 * inside the day's eBay calls (replay()). A free shop at its 50 is not
 * replayed when a slot frees: only a move to Pro replays.
 *
 * Only the Pro edition has a limit; in the others nothing is ever held back,
 * and nothing here runs.
 *
 * @package TCGiant_Sync
 * @since 3.36.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listings the free limit held back, and their replay.
 */
class TCGiant_Sync_Limit_Held {

	/**
	 * Option, not autoloaded: eBay ItemID => when it was last held back
	 * (Unix seconds), oldest first.
	 */
	const LIMIT_HELD_OPTION = 'tcgiant_sync_limit_held';

	/**
	 * Option: the replay's own record - why it last waited, so that is said
	 * once.
	 */
	const REPLAY_STATE_OPTION = 'tcgiant_sync_limit_replay';

	/**
	 * How many are kept. Past it the oldest go, and the log says how many.
	 */
	const LIMIT_HELD_MAX = 2000;

	/**
	 * How many a run of the replay queues for import.
	 */
	const REPLAY_PER_RUN = 100;

	/**
	 * Seconds between runs of the replay.
	 */
	const REPLAY_GAP = 900;

	/**
	 * Action Scheduler hook of a run of the replay.
	 */
	const REPLAY_HOOK = 'tcgiant_sync_limit_replay';

	/**
	 * Action Scheduler group, shared with the licence's own checks.
	 */
	const GROUP = 'tcgiant_sync_license';

	/**
	 * Held back in this request, written at its end: ItemID => when.
	 *
	 * @var array<string,int>
	 */
	private static $noted = array();

	/**
	 * Write down a listing the free limit held back. Kept in the request and
	 * written once, at its end (flush()): a full scan of a large account at
	 * the limit holds back hundreds.
	 *
	 * @param string $item_id eBay ItemID.
	 * @return void
	 */
	public static function note( $item_id ) {
		$item_id = trim( (string) $item_id );

		if ( 1 !== preg_match( '/^\d{1,20}$/D', $item_id ) ) {
			return;
		}

		if ( empty( self::$noted ) && function_exists( 'add_action' ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}

		self::$noted[ $item_id ] = time();
	}

	/**
	 * Write what this request held back. One held back again moves to the
	 * newest end; past LIMIT_HELD_MAX the oldest go, and the log says how
	 * many.
	 *
	 * @return void
	 */
	public static function flush() {
		if ( empty( self::$noted ) ) {
			return;
		}

		$held = self::all();
		foreach ( self::$noted as $item_id => $at ) {
			unset( $held[ $item_id ] );
			$held[ $item_id ] = (int) $at;
		}
		self::$noted = array();

		$dropped = count( $held ) - self::LIMIT_HELD_MAX;
		if ( $dropped > 0 ) {
			$held = array_slice( $held, $dropped, null, true );
			TCGiant_Sync_Logger::warning( sprintf(
				'Free licence limit: %1$d of the oldest new eBay listings it held back were taken off the list kept for a move to Pro, which keeps the newest %2$d. A full sync (Fetch Inventory) after the move imports every listing.',
				$dropped,
				self::LIMIT_HELD_MAX
			) );
		}

		update_option( self::LIMIT_HELD_OPTION, $held, false );
	}

	/**
	 * Every listing held back and not yet queued: ItemID => when, oldest
	 * first.
	 *
	 * @return array<string|int,int>
	 */
	public static function all() {
		$held = get_option( self::LIMIT_HELD_OPTION, array() );

		return is_array( $held ) ? $held : array();
	}

	/**
	 * How many are waiting for a move to Pro.
	 *
	 * @return int
	 */
	public static function count() {
		return count( array_replace( self::all(), self::$noted ) );
	}

	/**
	 * The site has just turned Pro: import what the limit held back. Queued,
	 * so the request that turned it - an activation, a check - does not wait
	 * on eBay. A replay already waiting carries on as it is.
	 *
	 * @param string $why What turned it Pro, for the log.
	 * @return bool Whether anything waits to be replayed.
	 */
	public static function start( $why ) {
		self::flush();
		$count = count( self::all() );

		if ( $count <= 0 || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		update_option( self::REPLAY_STATE_OPTION, array( 'started' => time(), 'noted' => '' ), false );

		TCGiant_Sync_Logger::log( sprintf(
			'Licence: Pro (%1$s). The %2$d new eBay listings the free limit held back are imported now, %3$d at a time, within the day\'s eBay calls.',
			$why,
			$count,
			self::REPLAY_PER_RUN
		), 'success' );

		if ( function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( self::REPLAY_HOOK, null, self::GROUP ) ) {
			return true;
		}

		as_schedule_single_action( time(), self::REPLAY_HOOK, array(), self::GROUP );
		return true;
	}

	/**
	 * A run of the replay: up to REPLAY_PER_RUN listings queued for import
	 * through TCGiant_Sync_Importer::start_specific_sync(), the next run
	 * REPLAY_GAP later.
	 *
	 * Under every budget guard the sync is under (wait()), and the request's
	 * own time budget (TCGiant_Sync_Runtime::over_time_budget()). Each
	 * import's GetItem then goes through the API's own budget check, as any
	 * import's does. A listing linked to a product by now - a full sync
	 * imported it meanwhile - is left off, at no call. The site no longer
	 * Pro: nothing is queued, and the rest waits for the next move to Pro.
	 *
	 * @return void
	 */
	public static function replay() {
		if ( ! TCGiant_Sync_Entitlements::instance()->is_pro() ) {
			self::say_once( 'free', 'Licence: the site is on the free tier again, so the new eBay listings the free limit held back wait for the next move to Pro.' );
			return;
		}

		self::flush();
		$held = self::all();
		if ( empty( $held ) ) {
			return;
		}

		$wait = self::wait();
		if ( null !== $wait ) {
			self::say_once( $wait['why'], sprintf(
				'Licence: %1$d new eBay listings the free limit held back wait to be imported: %2$s. %3$s',
				count( $held ),
				$wait['why'],
				$wait['until'] > 0 ? sprintf( 'They carry on at %s UTC.', gmdate( 'Y-m-d H:i', $wait['until'] ) ) : 'Fetch Inventory imports them.'
			) );
			if ( $wait['until'] > 0 ) {
				as_schedule_single_action( $wait['until'], self::REPLAY_HOOK, array(), self::GROUP );
			}
			return;
		}

		$batch    = array();
		$linked   = 0;
		$out_time = false;
		foreach ( array_keys( $held ) as $item_id ) {
			if ( count( $batch ) >= self::REPLAY_PER_RUN ) {
				break;
			}
			if ( '' !== TCGiant_Sync_Runtime::over_time_budget() ) {
				$out_time = true;
				break;
			}
			unset( $held[ $item_id ] );
			if ( TCGiant_Sync_Importer::find_live_product_for_item( (string) $item_id ) ) {
				$linked++;
				continue;
			}
			$batch[] = (string) $item_id;
		}

		// The request's time was spent before any: a minute on, in another.
		if ( $out_time && ! $batch && ! $linked ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::REPLAY_HOOK, array(), self::GROUP );
			return;
		}

		// Written first: a request killed after queueing them must not queue
		// them again.
		update_option( self::LIMIT_HELD_OPTION, $held, false );
		update_option( self::REPLAY_STATE_OPTION, array( 'started' => time(), 'noted' => '' ), false );

		if ( $batch ) {
			TCGiant_Sync_Importer::instance()->start_specific_sync( $batch );
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Licence: %1$d new eBay listings the free limit held back queued for import%2$s; %3$d still to go.',
			count( $batch ),
			$linked > 0 ? sprintf( ' (%d more already imported meanwhile, left off)', $linked ) : '',
			count( $held )
		) );

		if ( $held ) {
			as_schedule_single_action( time() + self::REPLAY_GAP, self::REPLAY_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Why the replay waits, and until when, or null when it may go.
	 *
	 * - not connected to eBay: an hour;
	 * - Emergency Stop pressed: an hour (start_specific_sync() would start
	 *   importing over it);
	 * - automatic sync turned off: not on its own at all - Fetch Inventory
	 *   imports them;
	 * - the day's eBay calls used up (is_daily_budget_exhausted()), every
	 *   shop's calls left under the share the delta keeps for a new day
	 *   (DELTA_CHASE_BUDGET_SHARE, through get_remaining_daily_budget()), or
	 *   this site at half of its own allowance, kept for stock and orders:
	 *   the next UTC day.
	 *
	 * @return array{why: string, until: int}|null
	 */
	private static function wait() {
		$now      = time();
		$tomorrow = ( intdiv( $now, DAY_IN_SECONDS ) + 1 ) * DAY_IN_SECONDS + 5 * MINUTE_IN_SECONDS;

		if ( ! TCGiant_Sync_OAuth::instance()->is_authenticated() ) {
			return array( 'why' => 'the site is not connected to eBay', 'until' => $now + HOUR_IN_SECONDS );
		}

		$state = TCGiant_Sync_Importer::get_sync_state();
		if ( 'stopped' === ( $state['status'] ?? '' ) ) {
			return array( 'why' => 'Emergency Stop was pressed', 'until' => $now + HOUR_IN_SECONDS );
		}

		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		if ( 'disabled' === ( is_array( $settings ) ? ( $settings['sync_interval'] ?? '' ) : '' ) ) {
			return array( 'why' => 'automatic sync is turned off', 'until' => 0 );
		}

		$api = TCGiant_Sync_API::instance();
		if ( $api->is_daily_budget_exhausted() ) {
			return array( 'why' => 'the day\'s eBay calls are used up', 'until' => $tomorrow );
		}

		$reserve = (int) ( TCGiant_Sync_API::DAILY_CALL_LIMIT * TCGiant_Sync_API::DAILY_CALL_SOFT_CEILING * TCGiant_Sync_Importer::DELTA_CHASE_BUDGET_SHARE );
		if ( $api->get_remaining_daily_budget() < $reserve ) {
			return array( 'why' => 'most of the day\'s eBay calls are used', 'until' => $tomorrow );
		}

		$own = $api->site_daily_allowance();
		if ( $own > 0 && $api->get_daily_call_count() >= (int) floor( $own / 2 ) ) {
			return array( 'why' => 'this site has used half of its own eBay calls for today, the rest kept for stock and orders', 'until' => $tomorrow );
		}

		return null;
	}

	/**
	 * Log a line once until the replay moves on.
	 *
	 * @param string $key  What it is about.
	 * @param string $line The line.
	 * @return void
	 */
	private static function say_once( $key, $line ) {
		$state = get_option( self::REPLAY_STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		if ( ( $state['noted'] ?? '' ) === $key ) {
			return;
		}

		$state['noted'] = $key;
		update_option( self::REPLAY_STATE_OPTION, $state, false );
		TCGiant_Sync_Logger::log( $line, 'warning' );
	}
}
