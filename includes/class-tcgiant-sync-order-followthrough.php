<?php
/**
 * Order follow-through (3.37.0): positive feedback for eBay buyers, and the
 * refunds made on eBay recorded on the WooCommerce orders imported from it.
 *
 * Two parts, each with its own setting where the order settings are:
 *
 * - Feedback. "Leave positive feedback for eBay buyers": Off (the default),
 *   "When eBay says the order is paid" - the order import sees it paid, as
 *   it arrives or later - or "When the order is marked Completed here". An
 *   order that reaches the chosen moment is queued, and an Action Scheduler
 *   run reads it from eBay by its OrderID and sends one CompleteSale for
 *   each of its lines, with FeedbackInfo (Positive, the shop's comment, the
 *   order's BuyerUserID): eBay takes feedback a line at a time, and the
 *   OrderID-level form is for one-line orders only. Each line's outcome is
 *   kept on the order's line item (FEEDBACK_META), written before every
 *   call, so a line is never sent feedback again once it has some: eBay's
 *   "already left" (a seller who left it in Seller Hub) counts as left, any
 *   other refusal is tried again twice at most on later runs, then left
 *   with one log line. Nothing is sent for an order eBay says is unpaid,
 *   cancelled or refunded, or that is cancelled, refunded or failed here,
 *   for a line past eBay's 60 days, or from a staging copy. Only an order
 *   that reaches the moment after the setting is on is queued: nothing old
 *   is sent.
 *
 * - Refunds. "Record eBay refunds on WooCommerce orders", on by default.
 *   Every six hours an Action Scheduler run asks GetOrders for the orders
 *   eBay changed since the last run (ModTimeFrom, 30 days back at most; two
 *   days back the first time), every page, with OutputSelector keeping the
 *   answer to each order's ID and its MonetaryDetails. A refund eBay has
 *   completed on an order imported here is recorded with wc_create_refund(),
 *   restocking nothing and refunding no payment: eBay's amount, capped at
 *   what is left to refund, once each, by eBay's reference for it. Nothing
 *   else is touched, and no order is ever made. A full refund makes
 *   WooCommerce set the order to Refunded, which sends eBay nothing and moves
 *   no stock; WooCommerce's "Refunded order" email to the customer is not
 *   sent for an eBay order, whose buyer eBay itself tells.
 *
 * Both respect the day's eBay calls: they wait while this site has used half
 * of its own allowance, while every shop together has less than a quarter
 * of the day's ceiling left, or while the day's figure is not known
 * (pause()), so neither is ever put ahead of a stock push - and Action
 * Scheduler runs them after the stock pushes due at the same time
 * (PRIORITY). Emergency Stop ends a run before its next eBay call. It holds
 * feedback until the shop lets it go again - Resume Import, Fetch Inventory
 * or a save of the order settings - and the sweep, which sends eBay nothing,
 * for six hours (fix round 1). A staging copy queues no feedback at all.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.37.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Order_Followthrough class.
 */
class TCGiant_Sync_Order_Followthrough {

	/** Feedback's Action Scheduler hook: one run sends what is due. */
	const FEEDBACK_HOOK = 'tcgiant_sync_feedback_run';

	/** The refund sweep's Action Scheduler hook: one window, a few pages a run. */
	const SWEEP_HOOK = 'tcgiant_sync_refund_sweep';

	/** Both hooks' Action Scheduler group. */
	const GROUP = 'tcgiant_sync_orders';

	/**
	 * Action Scheduler's priority for both: after the stock pushes (10, its
	 * default) due at the same moment. A feedback or a sweep is never more
	 * important than a stock call.
	 */
	const PRIORITY = 20;

	/** The settings: the feedback trigger, its comment, and the refunds switch. */
	const FEEDBACK_SETTING = 'feedback_buyers';
	const COMMENT_SETTING  = 'feedback_comment';
	const REFUNDS_SETTING  = 'record_ebay_refunds';

	/** The feedback triggers, as stored; anything else is Off. */
	const TRIGGERS = array( 'paid', 'completed' );

	/** The comment when the shop has given none. */
	const DEFAULT_COMMENT = 'Great buyer, thank you!';

	/**
	 * The comment's longest, in characters. eBay's feedback form takes 80;
	 * CompleteSale's reference gives CommentText 500, but a longer comment
	 * than eBay's own form allows is not one to try on a real buyer.
	 */
	const COMMENT_MAX = 80;

	/**
	 * Orders waiting for feedback: WooCommerce order ID => array( 'ebay' =>
	 * its eBay order ID, 'why' => the trigger that queued it, 'at' => when,
	 * and, once it has had one, 'next' => when to try again, 'unread' =>
	 * reads that did not answer for it ). Never autoloaded.
	 */
	const QUEUE_OPTION = 'tcgiant_sync_feedback_queue';

	/**
	 * The refund sweep: 'doing' => the window under way (from, to, page, and
	 * its counts); 'last_to' => the end of the last window read whole;
	 * 'last_end' => when that sweep finished; 'wait_until' => a pause;
	 * 'fails' => reads eBay has refused in a row (fix round 1).
	 */
	const SWEEP_OPTION = 'tcgiant_sync_refund_sweep_state';

	/** When Emergency Stop was last pressed, for the sweep's hold after it. */
	const STOP_OPTION = 'tcgiant_sync_followthrough_stop';

	/**
	 * When Emergency Stop held feedback (fix round 1): set by stop(), taken
	 * away only by the shop - resume(), from Resume Import, Fetch Inventory
	 * or a save of the order settings. While it is set no feedback goes,
	 * whatever the time; the orders reaching the trigger meanwhile wait.
	 * Not the sync's own "stopped": the scheduled delta sync clears that
	 * within the hour, by itself.
	 */
	const FEEDBACK_HELD_OPTION = 'tcgiant_sync_feedback_held';

	/** One request at a time sends feedback, and one sweeps: see take_lock(). */
	const LOCK_PREFIX = 'tcgiant_sync_followthrough_lock_';

	/**
	 * On an order's line item: what feedback for its eBay line came to -
	 * 'state' (sending, left, already, late, refused, gave_up), 'tries',
	 * 'at', the eBay 'line', and for a refusal 'next'. Shop data: kept on
	 * uninstall.
	 */
	const FEEDBACK_META = '_tcgiant_ebay_feedback';

	/**
	 * On an order: the eBay refunds recorded on it, by eBay's reference for
	 * each (refund_key()) => array( 'amount' recorded, 'ebay' amount, 'time'
	 * eBay gave, 'refund' ID, 'at' ). Shop data: kept on uninstall.
	 */
	const REFUNDS_META = '_tcgiant_ebay_refunds';

	/** On each WooCommerce refund this records: the same reference, saved with it. */
	const REFUND_KEY_META = '_tcgiant_ebay_refund_key';

	/** The outcomes after which a line is never sent feedback again. */
	const DONE_STATES = array( 'left', 'already', 'late', 'gave_up' );

	/** eBay's window for feedback, from the line's sale, and the margin kept inside it. */
	const FEEDBACK_DAYS   = 60;
	const FEEDBACK_MARGIN = 3600;

	/** Calls a line is sent at most: the first, and two later runs. */
	const FEEDBACK_TRIES = 3;

	/** How long a line eBay refused waits before it is tried again. */
	const RETRY_AFTER = 3600;

	/** Reads by OrderID that did not answer for an order, before it is let go. */
	const READ_TRIES = 3;

	/** Orders a GetOrders asks for by their OrderID: one page of eBay's. */
	const ORDERS_PER_READ = 100;

	/** CompleteSale calls a feedback run makes at most; the rest go a minute on. */
	const SENDS_PER_RUN = 50;

	/** Orders kept waiting at most; past it, the newest are kept. */
	const QUEUE_MAX = 1000;

	/**
	 * eBay's codes for feedback already left for the line: 834, "duplicate
	 * feedback"; 55, "invalid item number or invalid transaction or feedback
	 * already left". Either way eBay will not take it, and nothing is sent
	 * again.
	 */
	const ALREADY_CODES = array( '834', '55' );

	/** eBay's code for a line past its 60 days for feedback. */
	const LATE_CODES = array( '21916062' );

	/**
	 * Errors from a call that never reached eBay, or that eBay turned away for
	 * calls or the token: not a try. The run waits.
	 */
	const NOT_SENT_CODES = array( 'rate_limited', 'no_token', 'staging_blocked' );

	/** How often the refund sweep runs. */
	const SWEEP_SECONDS = 21600;

	/** How far back the first sweep reads, so turning it on records no old refunds en masse. */
	const SWEEP_FIRST_DAYS = 2;

	/**
	 * How far back a sweep ever reads: inside eBay's 30-day ModTime window,
	 * with a day to spare (fix round 1). A window, new or resumed, that
	 * would start further back starts here.
	 */
	const SWEEP_FLOOR_DAYS = 29;

	/** Each window starts this long before the last one ended. */
	const SWEEP_OVERLAP = 900;

	/** Pages a sweep run reads; the rest of its window a minute on, in another request. */
	const SWEEP_PAGES_RUN = 10;

	/** Pages a window reads at most: 5,000 orders changed. */
	const SWEEP_MAX_PAGES = 50;

	/** Orders a page holds: GetOrders' largest. */
	const SWEEP_PER_PAGE = 100;

	/** How long feedback waits after eBay could not be read. */
	const SWEEP_RETRY = 1800;

	/**
	 * How long the sweep waits after eBay refused or did not answer a read,
	 * by how many in a row (fix round 1): 30 minutes, then 1, 2, 4 and 6
	 * hours, and 6 hours from then on - never more often than it runs.
	 */
	const SWEEP_BACKOFF = array( 1800, 3600, 7200, 14400, 21600 );

	/**
	 * What the sweep asks GetOrders to return: each order's ID and its money
	 * details - Refunds among them - and the pages.
	 */
	const SELECTORS = array( 'OrderArray.Order.OrderID', 'OrderArray.Order.MonetaryDetails', 'PaginationResult', 'HasMoreOrders' );

	/** How long Emergency Stop holds the sweep (feedback waits for the shop: FEEDBACK_HELD_OPTION). */
	const STOP_HOLD = 21600;

	/** How long a hold on sending or sweeping lasts at most. */
	const LOCK_SECONDS = 600;

	/** Paused while every shop together has less than this share of its ceiling left. */
	const FLEET_FLOOR = 0.25;

	/** Paused once this site has used this share of its own allowance today. */
	const OWN_SHARE = 0.5;

	/**
	 * The holds this request has: name => value.
	 *
	 * @var array<string, string>
	 */
	private static $held = array();

	/**
	 * The reference of the eBay refund being recorded now, for the refund
	 * WooCommerce is about to save (mark_refund()); '' otherwise.
	 *
	 * @var string
	 */
	private static $recording = '';

	/**
	 * The hooks, on every request: Action Scheduler runs them from WP-Cron
	 * and its own requests, and an order is completed from the admin, the
	 * REST API and other plugins.
	 */
	public static function register() {
		add_action( self::FEEDBACK_HOOK, array( __CLASS__, 'run_feedback' ) );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'run_sweep' ) );

		// "When the order is marked Completed here." After the tracking
		// push, which is not changed: feedback is a call of its own.
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'completed_here' ), 20, 2 );

		// The sweep is kept scheduled by the order import's own 15 minutes.
		add_action( 'tcgiant_sync_import_orders', array( __CLASS__, 'ensure_sweep' ), 20 );

		// The refund this records carries eBay's reference, saved with it.
		add_action( 'woocommerce_create_refund', array( __CLASS__, 'mark_refund' ), 10, 2 );

		// No "Refunded order" email to an eBay buyer, whole or partial:
		// WooCommerce names the email by which it is.
		add_filter( 'woocommerce_email_enabled_customer_refunded_order', array( __CLASS__, 'no_refund_email' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_partially_refunded_order', array( __CLASS__, 'no_refund_email' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	/**
	 * The plugin's settings as stored.
	 *
	 * @return array
	 */
	private static function settings() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Which moment leaves feedback: 'paid', 'completed', or 'off'. Off while
	 * eBay orders are not imported.
	 *
	 * @param array|null $settings The settings; the stored ones when null.
	 * @return string
	 */
	public static function feedback_mode( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : self::settings();
		if ( empty( $settings['enable_order_import'] ) ) {
			return 'off';
		}
		$mode = (string) ( $settings[ self::FEEDBACK_SETTING ] ?? '' );
		return in_array( $mode, self::TRIGGERS, true ) ? $mode : 'off';
	}

	/**
	 * The comment feedback is left with: the shop's, or the default when it
	 * gave none, cut to COMMENT_MAX characters.
	 *
	 * @param array|null $settings The settings; the stored ones when null.
	 * @return string
	 */
	public static function feedback_comment( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : self::settings();
		$text     = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $settings[ self::COMMENT_SETTING ] ?? '' ) ) );
		if ( '' === $text ) {
			$text = self::DEFAULT_COMMENT;
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::COMMENT_MAX, 'UTF-8' ) : substr( $text, 0, self::COMMENT_MAX );
	}

	/**
	 * Whether eBay's refunds are recorded: on unless the shop said No, and
	 * only while eBay orders are imported.
	 *
	 * @param array|null $settings The settings; the stored ones when null.
	 * @return bool
	 */
	public static function records_refunds( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : self::settings();
		return ! empty( $settings['enable_order_import'] ) && '0' !== (string) ( $settings[ self::REFUNDS_SETTING ] ?? '1' );
	}

	// -------------------------------------------------------------------------
	// Feedback: the triggers
	// -------------------------------------------------------------------------

	/**
	 * The order import has seen eBay say an order is paid: as it arrived, or
	 * read again (TCGiant_Sync_Orders). Queued for feedback when that is the
	 * shop's moment.
	 *
	 * @param WC_Order|mixed $order The order here.
	 * @return void
	 */
	public static function ebay_paid( $order ) {
		if ( 'paid' === self::feedback_mode() ) {
			self::queue( $order, 'paid' );
		}
	}

	/**
	 * Hook: woocommerce_order_status_completed. An eBay order marked
	 * Completed here is queued for feedback when that is the shop's moment.
	 *
	 * @param int            $order_id The order.
	 * @param WC_Order|mixed $order    The order, when WooCommerce passes it.
	 * @return void
	 */
	public static function completed_here( $order_id, $order = null ) {
		if ( 'completed' !== self::feedback_mode() ) {
			return;
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( is_object( $order ) && method_exists( $order, 'get_meta' ) && 'ebay' === $order->get_meta( '_ebay_order_source' ) ) {
			self::queue( $order, 'completed' );
		}
	}

	/**
	 * Queue an order for feedback, and ask for a run. Never throws: it runs
	 * inside the order import's own writes and WooCommerce's status change,
	 * which a failure here - Action Scheduler's table refusing a row, say -
	 * must not stop. Such an order is logged and gets no feedback.
	 *
	 * @param WC_Order|mixed $order The order here.
	 * @param string         $why   The trigger.
	 * @return void
	 */
	private static function queue( $order, $why ) {
		try {
			self::queue_order( $order, $why );
		} catch ( \Throwable $e ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Feedback: WC Order #%d could not be queued for feedback — %s. It gets none unless it is queued again.',
				is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
				$e->getMessage()
			) );
		}
	}

	/**
	 * queue()'s work.
	 *
	 * @param WC_Order|mixed $order The order here.
	 * @param string         $why   The trigger.
	 * @return void
	 */
	private static function queue_order( $order, $why ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		$ebay_id = (string) $order->get_meta( '_ebay_order_id' );
		$id      = (int) $order->get_id();
		if ( '' === $ebay_id || $id <= 0 ) {
			return;
		}

		// A staging copy queues nothing (fix round 1): what it queued would
		// wait, unsent, and a copy's database pushed to the live shop would
		// send it - feedback for orders completed while testing. On a copy
		// whose block is lifted ("This is my live shop"), only the orders
		// reaching the trigger from then on are queued.
		if ( TCGiant_Sync_Environment::write_block() ) {
			self::note_copy();
			return;
		}

		$queue = self::read( self::QUEUE_OPTION );
		if ( ! isset( $queue[ $id ] ) || ( $queue[ $id ]['why'] ?? '' ) !== $why ) {
			$queue[ $id ] = array(
				'ebay' => $ebay_id,
				'why'  => $why,
				'at'   => time(),
			);
			if ( count( $queue ) > self::QUEUE_MAX ) {
				uasort( $queue, function ( $a, $b ) {
					return (int) ( $b['at'] ?? 0 ) - (int) ( $a['at'] ?? 0 );
				} );
				$queue = array_slice( $queue, 0, self::QUEUE_MAX, true );
			}
			self::write( self::QUEUE_OPTION, $queue );
		}

		self::want_run( self::FEEDBACK_HOOK, time() );
	}

	/**
	 * Say once a UTC day that feedback is off on this copy.
	 *
	 * @return void
	 */
	private static function note_copy() {
		$name = 'tcgiant_followthrough_copy';
		$day  = gmdate( 'Y-m-d' );
		if ( get_transient( $name ) === $day ) {
			return;
		}
		set_transient( $name, $day, DAY_IN_SECONDS );
		TCGiant_Sync_Logger::log( 'Feedback for eBay buyers is off on this site: it looks like a staging or development copy, so orders paid or completed here are not queued for feedback, and none will be sent for them if this copy is ever made the live shop. This is said once a day.', 'warning' );
	}

	// -------------------------------------------------------------------------
	// Feedback: the run
	// -------------------------------------------------------------------------

	/**
	 * One feedback run (Action Scheduler).
	 */
	public static function run_feedback() {
		if ( ! self::take_lock( 'feedback' ) ) {
			// Another request is sending; what it leaves runs after it.
			self::run_again( self::FEEDBACK_HOOK, time() + 60 );
			return;
		}

		try {
			self::feedback_batch();
		} finally {
			self::release_lock( 'feedback' );
		}
	}

	/**
	 * Send the feedback that is due.
	 *
	 * The orders waiting are judged here first - deleted, cancelled,
	 * refunded or failed here, or made more than 60 days ago, and they go
	 * with a log line and no call - then read from eBay by their OrderID, a
	 * hundred to a call, and each line of each sent its feedback. An order
	 * eBay refused to answer for is asked about on its own after that, one a
	 * run, and let go after READ_TRIES. What is left - lines eBay refused,
	 * waiting RETRY_AFTER; orders past this run's SENDS_PER_RUN - runs again
	 * when it is due.
	 *
	 * @return void
	 */
	private static function feedback_batch() {
		$was = self::read( self::QUEUE_OPTION );
		if ( ! $was ) {
			return;
		}

		$queue = $was;
		$mode  = self::feedback_mode();
		$start = time();

		if ( 'off' === $mode ) {
			self::save_queue( $was, array() );
			TCGiant_Sync_Logger::log( sprintf(
				'Feedback: "Leave positive feedback for eBay buyers" is off, so the %d order(s) waiting for feedback were let go. Nothing was sent to eBay.',
				count( $was )
			) );
			return;
		}

		// Held since Emergency Stop until the shop resumes (fix round 1):
		// nothing runs again until resume() asks.
		if ( self::feedback_held() ) {
			return;
		}

		$pause = self::pause( true );
		if ( $pause ) {
			if ( 'staging' === $pause['why'] ) {
				// A copy never sends: the orders wait, and nothing runs again
				// until another is queued.
				TCGiant_Sync_Environment::note_blocked( 'Feedback for eBay buyers' );
				return;
			}
			self::note_pause( 'feedback', $pause );
			self::run_again( self::FEEDBACK_HOOK, (int) $pause['until'] );
			return;
		}

		// The orders due, oldest first. One queued by the other trigger - the
		// shop changed its mind since - is let go.
		$due     = array();
		$changed = 0;
		foreach ( $queue as $id => $entry ) {
			if ( ! is_array( $entry ) || ( $entry['why'] ?? '' ) !== $mode ) {
				unset( $queue[ $id ] );
				$changed++;
				continue;
			}
			if ( (int) ( $entry['next'] ?? 0 ) <= $start ) {
				$due[ $id ] = $entry;
			}
		}
		if ( $changed ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Feedback: %d order(s) queued for feedback under the other choice of "Leave positive feedback for eBay buyers" were let go. Nothing was sent to eBay for them.',
				$changed
			) );
		}
		uasort( $due, function ( $a, $b ) {
			return (int) ( $a['at'] ?? 0 ) - (int) ( $b['at'] ?? 0 );
		} );

		// Judged here first: no call for an order that is to have none.
		$ask = array();
		foreach ( $due as $id => $entry ) {
			$order = wc_get_order( (int) $id );
			$why   = self::refused_here( $order );
			if ( '' !== $why ) {
				unset( $queue[ $id ] );
				TCGiant_Sync_Logger::log( sprintf(
					'Feedback: none left for WC Order #%d (eBay order %s): %s.',
					(int) $id,
					(string) ( $entry['ebay'] ?? '' ),
					$why
				) );
				continue;
			}
			if ( ! self::lines_open( $order ) ) {
				unset( $queue[ $id ] );
				continue;
			}
			$ask[ (int) $id ] = $order;
		}

		// eBay refused to answer for these before: one, alone. The rest, a
		// hundred to a call.
		$alone = array();
		$rest  = array();
		foreach ( $ask as $id => $order ) {
			if ( (int) ( $queue[ $id ]['unread'] ?? 0 ) > 0 ) {
				if ( ! $alone ) {
					$alone[ $id ] = $order;
				}
				continue;
			}
			$rest[ $id ] = $order;
		}
		$chunks = array_chunk( $rest, self::ORDERS_PER_READ, true );
		if ( $alone ) {
			array_unshift( $chunks, $alone );
		}

		$api     = TCGiant_Sync_API::instance();
		$comment = self::feedback_comment();
		$sent    = 0;
		$again   = null;

		foreach ( $chunks as $chunk ) {
			if ( self::stopped_since( $start ) ) {
				break;
			}
			$pause = self::pause( true );
			if ( $pause ) {
				self::note_pause( 'feedback', $pause );
				$again = (int) $pause['until'];
				break;
			}

			$ids = array();
			foreach ( $chunk as $id => $order ) {
				$ids[ (string) $order->get_meta( '_ebay_order_id' ) ] = (int) $id;
			}

			$result = $api->trading_api_request( 'GetOrders', self::orders_xml( array_keys( $ids ) ) );

			if ( is_wp_error( $result ) && 'api_error' !== $result->get_error_code() ) {
				// eBay not reached, out of calls, no token: every order waits.
				TCGiant_Sync_Logger::warning( 'Feedback: the orders waiting for feedback could not be read from eBay — ' . $result->get_error_message() . '. They are tried again later.' );
				$again = time() + self::SWEEP_RETRY;
				break;
			}

			if ( is_wp_error( $result ) ) {
				// eBay refused the call: each of its orders is asked about on
				// its own after this, and let go after READ_TRIES.
				foreach ( $ids as $ebay_id => $id ) {
					$queue[ $id ]['unread'] = (int) ( $queue[ $id ]['unread'] ?? 0 ) + 1;
					if ( $queue[ $id ]['unread'] >= self::READ_TRIES ) {
						unset( $queue[ $id ] );
						TCGiant_Sync_Logger::warning( sprintf(
							'Feedback: eBay refused %d times to answer for eBay order %s (WC Order #%d) by its OrderID — %s. No feedback was left for it.',
							self::READ_TRIES, (string) $ebay_id, (int) $id, $result->get_error_message()
						) );
					}
				}
				continue;
			}

			$answers = self::order_list( $result );
			foreach ( $ids as $ebay_id => $id ) {
				$ebay_order = self::answer_for( (string) $ebay_id, $chunk[ $id ], $answers, $ids );
				if ( null === $ebay_order ) {
					$queue[ $id ]['unread'] = (int) ( $queue[ $id ]['unread'] ?? 0 ) + 1;
					if ( $queue[ $id ]['unread'] >= self::READ_TRIES ) {
						unset( $queue[ $id ] );
						TCGiant_Sync_Logger::warning( sprintf(
							'Feedback: eBay did not return eBay order %s (WC Order #%d) when asked for it by its OrderID, %d times. No feedback was left for it.',
							(string) $ebay_id, (int) $id, self::READ_TRIES
						) );
					}
					continue;
				}

				unset( $queue[ $id ]['unread'] );
				$done = self::feedback_for( $chunk[ $id ], $ebay_order, $comment, $start, $sent );

				if ( 'done' === $done ) {
					unset( $queue[ $id ] );
				} elseif ( 'later' === $done ) {
					$queue[ $id ]['next'] = time() + self::RETRY_AFTER;
				} else {
					// Stopped: Emergency Stop, a pause, this run's last send.
					$again = is_int( $done ) ? $done : null;
					break 2;
				}
			}
		}

		self::save_queue( $was, $queue );

		// What is left runs again when it is due: not after Emergency Stop,
		// which holds it, nor on a copy, which never sends.
		$left = self::read( self::QUEUE_OPTION );
		if ( $left && ! self::stopped_since( $start ) && ! TCGiant_Sync_Environment::write_block() ) {
			if ( null === $again ) {
				$again = PHP_INT_MAX;
				foreach ( $left as $entry ) {
					$again = min( $again, (int) ( $entry['next'] ?? 0 ) );
				}
			}
			self::run_again( self::FEEDBACK_HOOK, max( time() + 60, (int) $again ) );
		}
	}

	/**
	 * Why an order here is to have no feedback, or '' when it may.
	 *
	 * @param WC_Order|mixed $order The order, as wc_get_order() found it.
	 * @return string
	 */
	private static function refused_here( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) || ! method_exists( $order, 'get_meta' ) || 'ebay' !== $order->get_meta( '_ebay_order_source' ) ) {
			return 'it is no longer here';
		}

		$status = (string) $order->get_status();
		if ( in_array( $status, array( 'cancelled', 'refunded', 'failed', 'trash', 'pending' ), true ) ) {
			return sprintf( 'it is %s here', function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : $status );
		}

		if ( method_exists( $order, 'get_total_refunded' ) && (float) $order->get_total_refunded() > 0 ) {
			return 'it has a refund';
		}

		$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
		if ( is_object( $created ) && method_exists( $created, 'getTimestamp' ) && (int) $created->getTimestamp() < time() - self::FEEDBACK_DAYS * DAY_IN_SECONDS + self::FEEDBACK_MARGIN ) {
			return 'it was made more than 60 days ago, past eBay\'s time for feedback';
		}

		return '';
	}

	/**
	 * Whether an order has an eBay line not yet done with.
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	private static function lines_open( $order ) {
		foreach ( self::ebay_items( $order ) as $item ) {
			$record = self::record_of( $item );
			if ( ! in_array( (string) ( $record['state'] ?? '' ), self::DONE_STATES, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * An order's line items that came from eBay lines, in order.
	 *
	 * @param WC_Order $order The order.
	 * @return array
	 */
	private static function ebay_items( $order ) {
		$items = array();
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return $items;
		}
		foreach ( $order->get_items() as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_meta' ) && '' !== (string) $item->get_meta( '_ebay_item_id' ) ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * What a line item holds of feedback.
	 *
	 * @param object $item The line item.
	 * @return array
	 */
	private static function record_of( $item ) {
		$record = $item->get_meta( self::FEEDBACK_META );
		return is_array( $record ) ? $record : array();
	}

	/**
	 * Write a line item's feedback record, now.
	 *
	 * @param object $item   The line item.
	 * @param array  $record The record.
	 * @return void
	 */
	private static function save_record( $item, array $record ) {
		$item->update_meta_data( self::FEEDBACK_META, $record );
		if ( method_exists( $item, 'save_meta_data' ) ) {
			$item->save_meta_data();
		} elseif ( method_exists( $item, 'save' ) ) {
			$item->save();
		}
	}

	/**
	 * GetOrders for some orders by their OrderID, as the order import's
	 * follow-up asks.
	 *
	 * @param string[] $ids eBay order IDs.
	 * @return string
	 */
	private static function orders_xml( array $ids ) {
		$xml = '
<OrderIDArray>';
		foreach ( $ids as $id ) {
			$xml .= '
<OrderID>' . esc_xml( (string) $id ) . '</OrderID>';
		}
		$xml .= '
</OrderIDArray>
<OrderRole>Seller</OrderRole>
<Pagination>
<EntriesPerPage>' . (int) self::ORDERS_PER_READ . '</EntriesPerPage>
<PageNumber>1</PageNumber>
</Pagination>';
		return $xml;
	}

	/**
	 * The orders in a GetOrders answer.
	 *
	 * @param array $result The answer.
	 * @return array[]
	 */
	private static function order_list( $result ) {
		$list = is_array( $result ) && isset( $result['OrderArray']['Order'] ) ? $result['OrderArray']['Order'] : array();
		if ( is_array( $list ) && isset( $list['OrderID'] ) ) {
			$list = array( $list );
		}
		return array_values( array_filter( (array) $list, 'is_array' ) );
	}

	/**
	 * The answer that is an order here: by its OrderID, or - eBay answers
	 * an unpaid order's ID with the paid order, under its new ID - by an
	 * item of it, from the same buyer, among the answers no other order
	 * asked about holds by its ID (two buyers of one listing share its
	 * item). With no buyer recorded here, only by its OrderID.
	 *
	 * @param string   $ebay_id The eBay order ID asked for.
	 * @param WC_Order $order   The order here.
	 * @param array[]  $answers The orders eBay returned.
	 * @param array    $asked   Every eBay order ID asked about, as keys.
	 * @return array|null
	 */
	private static function answer_for( $ebay_id, $order, array $answers, array $asked ) {
		foreach ( $answers as $answer ) {
			if ( TCGiant_Sync_API::scalar_value( $answer['OrderID'] ?? '' ) === $ebay_id ) {
				return $answer;
			}
		}

		$mine = array();
		foreach ( self::ebay_items( $order ) as $item ) {
			$mine[ (string) $item->get_meta( '_ebay_item_id' ) ] = true;
		}
		// No buyer recorded here: the answer cannot be confirmed as this
		// order's, so it is not taken (fix round 1, L3).
		$buyer = (string) $order->get_meta( '_ebay_buyer_user_id' );
		if ( '' === $buyer ) {
			return null;
		}
		foreach ( $answers as $answer ) {
			if ( isset( $asked[ TCGiant_Sync_API::scalar_value( $answer['OrderID'] ?? '' ) ] ) ) {
				continue;
			}
			if ( TCGiant_Sync_API::scalar_value( $answer['BuyerUserID'] ?? '' ) !== $buyer ) {
				continue;
			}
			foreach ( self::transactions( $answer ) as $tx ) {
				if ( isset( $mine[ TCGiant_Sync_API::scalar_value( $tx['Item']['ItemID'] ?? '' ) ] ) ) {
					return $answer;
				}
			}
		}

		return null;
	}

	/**
	 * An eBay order's lines.
	 *
	 * @param array $ebay_order The order.
	 * @return array[]
	 */
	private static function transactions( array $ebay_order ) {
		$tx = $ebay_order['TransactionArray']['Transaction'] ?? array();
		if ( is_array( $tx ) && isset( $tx['TransactionID'] ) ) {
			$tx = array( $tx );
		}
		return array_values( array_filter( (array) $tx, 'is_array' ) );
	}

	/**
	 * Send one order's feedback, a line at a time.
	 *
	 * Nothing for an order eBay says is not paid (OrderStatus other than
	 * Completed), has a cancellation (CancelStatus other than NotApplicable)
	 * or a refund, or that names no buyer. A line past eBay's 60 days is
	 * marked late, unsent. Each call is recorded on the line item before it
	 * goes, the try counted, and its outcome after.
	 *
	 * @param WC_Order $order      The order here.
	 * @param array    $ebay_order eBay's order, just read.
	 * @param string   $comment    The comment.
	 * @param int      $start      When this run began.
	 * @param int      $sent       CompleteSale calls this run, so far.
	 * @return string|int 'done', 'later' (a line waits to be tried again), or
	 *                    the stop: 'stop', or the time to run again.
	 */
	private static function feedback_for( $order, array $ebay_order, $comment, $start, &$sent ) {
		$id      = (int) $order->get_id();
		$ebay_id = TCGiant_Sync_API::scalar_value( $ebay_order['OrderID'] ?? '' );
		$status  = TCGiant_Sync_API::scalar_value( $ebay_order['OrderStatus'] ?? '' );
		$cancel  = TCGiant_Sync_API::scalar_value( $ebay_order['CancelStatus'] ?? '' );
		$buyer   = TCGiant_Sync_API::scalar_value( $ebay_order['BuyerUserID'] ?? '' );

		$never = '';
		if ( 'completed' !== strtolower( $status ) ) {
			$never = sprintf( 'eBay says it is %s, not paid', '' !== $status ? $status : 'not known' );
		} elseif ( '' !== $cancel && 'notapplicable' !== strtolower( $cancel ) ) {
			$never = sprintf( 'eBay records a cancellation (%s)', $cancel );
		} elseif ( self::refunds_in( $ebay_order ) ) {
			$never = 'eBay records a refund';
		} elseif ( '' === $buyer ) {
			$never = 'eBay did not say who the buyer is';
		}
		if ( '' !== $never ) {
			TCGiant_Sync_Logger::log( sprintf( 'Feedback: none left for WC Order #%d (eBay order %s): %s.', $id, $ebay_id, $never ) );
			return 'done';
		}

		$api     = TCGiant_Sync_API::instance();
		$items   = self::ebay_items( $order );
		$used    = array();
		$left    = 0;
		$result  = 'done';
		$created = strtotime( TCGiant_Sync_API::scalar_value( $ebay_order['CreatedTime'] ?? '' ) );

		foreach ( self::transactions( $ebay_order ) as $tx ) {
			$item_id = TCGiant_Sync_API::scalar_value( $tx['Item']['ItemID'] ?? '' );
			$tx_id   = TCGiant_Sync_API::scalar_value( $tx['TransactionID'] ?? '' );
			$line    = TCGiant_Sync_API::scalar_value( $tx['OrderLineItemID'] ?? '' );
			$line    = '' !== $line ? $line : ( '' !== $item_id && '' !== $tx_id ? $item_id . '-' . $tx_id : '' );
			if ( '' === $line ) {
				continue;
			}

			// The order here's line for it: the one that recorded this line,
			// else the next with its item that recorded none.
			$item = self::item_for( $items, $item_id, $line, $used );
			if ( null === $item ) {
				continue;
			}

			$record = self::record_of( $item );
			$state  = (string) ( $record['state'] ?? '' );
			$tries  = (int) ( $record['tries'] ?? 0 );
			if ( in_array( $state, self::DONE_STATES, true ) ) {
				continue;
			}

			$when = strtotime( TCGiant_Sync_API::scalar_value( $tx['CreatedDate'] ?? '' ) );
			$when = $when ? $when : $created;
			if ( $when && $when < time() - self::FEEDBACK_DAYS * DAY_IN_SECONDS + self::FEEDBACK_MARGIN ) {
				self::save_record( $item, array( 'state' => 'late', 'tries' => $tries, 'at' => time(), 'line' => $line ) );
				TCGiant_Sync_Logger::log( sprintf( 'Feedback: none left for eBay order %s, line %s (WC Order #%d): it is past eBay\'s 60 days for feedback.', $ebay_id, $line, $id ) );
				continue;
			}

			if ( $tries >= self::FEEDBACK_TRIES ) {
				// Its last try ended without an answer.
				self::save_record( $item, array( 'state' => 'gave_up', 'tries' => $tries, 'at' => time(), 'line' => $line ) );
				TCGiant_Sync_Logger::warning( sprintf( 'Feedback: eBay order %s, line %s (WC Order #%d) was sent feedback %d times without an answer. It is not sent again: check it on eBay.', $ebay_id, $line, $id, $tries ) );
				continue;
			}
			if ( 'refused' === $state && (int) ( $record['next'] ?? 0 ) > time() ) {
				$result = 'later';
				continue;
			}

			if ( self::stopped_since( $start ) ) {
				return 'stop';
			}
			if ( $sent >= self::SENDS_PER_RUN ) {
				return time() + 60;
			}
			$pause = self::pause( true );
			if ( $pause ) {
				self::note_pause( 'feedback', $pause );
				return 'staging' === $pause['why'] ? 'stop' : (int) $pause['until'];
			}

			// Recorded before it goes: a request that dies with the call
			// under way is a try, and eBay's "already left" answers the next.
			self::save_record( $item, array( 'state' => 'sending', 'tries' => $tries + 1, 'at' => time(), 'line' => $line ) );
			$sent++;

			$response = $api->trading_api_request( 'CompleteSale', self::feedback_xml( $tx, $buyer, $comment ) );
			$outcome  = self::outcome( $response );
			$words    = is_wp_error( $response ) ? $response->get_error_message() : '';

			if ( 'left' === $outcome ) {
				self::save_record( $item, array( 'state' => 'left', 'tries' => $tries + 1, 'at' => time(), 'line' => $line ) );
				$left++;
				TCGiant_Sync_Logger::log( sprintf(
					'Feedback: positive feedback left for eBay buyer %s on eBay order %s, line %s (WC Order #%d): "%s".',
					$buyer, $ebay_id, $line, $id, $comment
				), 'success' );
			} elseif ( 'already' === $outcome ) {
				self::save_record( $item, array( 'state' => 'already', 'tries' => $tries + 1, 'at' => time(), 'line' => $line ) );
				TCGiant_Sync_Logger::log( sprintf(
					'Feedback: eBay says feedback was already left for eBay order %s, line %s (WC Order #%d) - by hand in Seller Hub, most likely - so it is taken as done. eBay: %s',
					$ebay_id, $line, $id, $words
				) );
			} elseif ( 'late' === $outcome ) {
				self::save_record( $item, array( 'state' => 'late', 'tries' => $tries + 1, 'at' => time(), 'line' => $line ) );
				TCGiant_Sync_Logger::log( sprintf( 'Feedback: none left for eBay order %s, line %s (WC Order #%d): eBay says it is past its 60 days for feedback.', $ebay_id, $line, $id ) );
			} elseif ( 'not_sent' === $outcome ) {
				// Not a try: eBay never had it. The line is as it was, and the
				// run waits.
				self::save_record( $item, $record );
				return is_wp_error( $response ) && is_array( $response->get_error_data() ) && ! empty( $response->get_error_data()['daily_budget'] ) ? self::next_day() : time() + self::SWEEP_RETRY;
			} elseif ( $tries + 1 >= self::FEEDBACK_TRIES ) {
				self::save_record( $item, array( 'state' => 'gave_up', 'tries' => $tries + 1, 'at' => time(), 'line' => $line ) );
				TCGiant_Sync_Logger::warning( sprintf(
					'Feedback: eBay refused feedback for eBay order %s, line %s (WC Order #%d) %d times — %s. It is not tried again: leave it on eBay by hand if you want to.',
					$ebay_id, $line, $id, $tries + 1, '' !== $words ? $words : 'no reason given'
				) );
			} else {
				self::save_record( $item, array( 'state' => 'refused', 'tries' => $tries + 1, 'at' => time(), 'line' => $line, 'next' => time() + self::RETRY_AFTER ) );
				$result = 'later';
				if ( 0 === $tries ) {
					TCGiant_Sync_Logger::warning( sprintf(
						'Feedback: eBay refused feedback for eBay order %s, line %s (WC Order #%d) — %s. It is tried again later, twice at most.',
						$ebay_id, $line, $id, '' !== $words ? $words : 'no reason given'
					) );
				}
			}
		}

		if ( $left > 0 && method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( sprintf(
				/* translators: 1: the buyer's eBay user ID, 2: the comment, 3: how many lines */
				__( 'TCGiant Sync left positive feedback on eBay for the buyer, %1$s: "%2$s" (%3$d line(s) of the order).', 'tcgiant-sync' ),
				$buyer,
				$comment,
				$left
			) );
		}

		return $result;
	}

	/**
	 * The line item an eBay line is: the one that recorded the line, else
	 * the first not yet taken with its item that recorded no line - one
	 * that recorded another is that line's. eBay's lines come in the order
	 * the import made the line items from them.
	 *
	 * @param array  $items   The order's eBay line items.
	 * @param string $item_id The line's ItemID.
	 * @param string $line    The line's ID.
	 * @param array  $used    Items already taken this order, by index.
	 * @return object|null
	 */
	private static function item_for( array $items, $item_id, $line, array &$used ) {
		foreach ( $items as $i => $item ) {
			if ( ! isset( $used[ $i ] ) && (string) ( self::record_of( $item )['line'] ?? '' ) === $line ) {
				$used[ $i ] = true;
				return $item;
			}
		}

		foreach ( $items as $i => $item ) {
			if ( isset( $used[ $i ] ) || (string) $item->get_meta( '_ebay_item_id' ) !== $item_id || '' !== (string) ( self::record_of( $item )['line'] ?? '' ) ) {
				continue;
			}
			$used[ $i ] = true;
			return $item;
		}

		return null;
	}

	/**
	 * CompleteSale for one line's feedback: FeedbackInfo, and the line by its
	 * OrderLineItemID, else its ItemID and TransactionID. Nothing else.
	 *
	 * @param array  $tx      The line.
	 * @param string $buyer   The buyer's eBay user ID.
	 * @param string $comment The comment.
	 * @return string
	 */
	private static function feedback_xml( array $tx, $buyer, $comment ) {
		$xml = '
<FeedbackInfo>
<CommentText>' . esc_xml( (string) $comment ) . '</CommentText>
<CommentType>Positive</CommentType>
<TargetUser>' . esc_xml( (string) $buyer ) . '</TargetUser>
</FeedbackInfo>';

		$line = TCGiant_Sync_API::scalar_value( $tx['OrderLineItemID'] ?? '' );
		if ( '' !== $line ) {
			return $xml . '
<OrderLineItemID>' . esc_xml( $line ) . '</OrderLineItemID>';
		}

		return $xml . '
<ItemID>' . esc_xml( TCGiant_Sync_API::scalar_value( $tx['Item']['ItemID'] ?? '' ) ) . '</ItemID>
<TransactionID>' . esc_xml( TCGiant_Sync_API::scalar_value( $tx['TransactionID'] ?? '' ) ) . '</TransactionID>';
	}

	/**
	 * What a CompleteSale's answer comes to: 'left'; 'already' (eBay has
	 * feedback for the line); 'late'; 'not_sent' (eBay never had it, or
	 * turned it away for calls or the token); or 'refused'.
	 *
	 * @param array|WP_Error $response The answer.
	 * @return string
	 */
	private static function outcome( $response ) {
		if ( ! is_wp_error( $response ) ) {
			return 'PartialFailure' === TCGiant_Sync_API::scalar_value( $response['Ack'] ?? '' ) ? 'refused' : 'left';
		}
		if ( in_array( (string) $response->get_error_code(), self::NOT_SENT_CODES, true ) ) {
			return 'not_sent';
		}
		$codes = TCGiant_Sync_API::error_codes( $response );
		if ( array_intersect( $codes, self::ALREADY_CODES ) ) {
			return 'already';
		}
		if ( array_intersect( $codes, self::LATE_CODES ) ) {
			return 'late';
		}
		return 'refused';
	}

	/**
	 * Keep the queue: what this run removed and changed, put onto the queue
	 * as stored now, so an order queued meanwhile is kept.
	 *
	 * @param array $was   The queue as this run read it.
	 * @param array $queue The queue as this run leaves it.
	 * @return void
	 */
	private static function save_queue( array $was, array $queue ) {
		$stored = self::read( self::QUEUE_OPTION );
		$list   = $stored;
		foreach ( array_keys( array_diff_key( $was, $queue ) ) as $id ) {
			// Queued again meanwhile, by another trigger: kept.
			if ( isset( $list[ $id ] ) && ( $list[ $id ] ?? null ) === ( $was[ $id ] ?? null ) ) {
				unset( $list[ $id ] );
			}
		}
		foreach ( $queue as $id => $entry ) {
			if ( ! isset( $was[ $id ] ) || $was[ $id ] !== $entry ) {
				$list[ $id ] = $entry;
			}
		}
		if ( $list === $stored ) {
			return;
		}
		if ( $list ) {
			self::write( self::QUEUE_OPTION, $list );
		} else {
			delete_option( self::QUEUE_OPTION );
		}
	}

	// -------------------------------------------------------------------------
	// Refunds: the sweep
	// -------------------------------------------------------------------------

	/**
	 * Keep the sweep scheduled, from the order import's 15 minutes: when the
	 * last ended six hours ago, or now when one is part way. Cancelled when
	 * recording is off.
	 *
	 * @return void
	 */
	public static function ensure_sweep() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( ! self::records_refunds() ) {
			if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_unschedule_all_actions' ) && false !== as_next_scheduled_action( self::SWEEP_HOOK, null, self::GROUP ) ) {
				as_unschedule_all_actions( self::SWEEP_HOOK, null, self::GROUP );
			}
			return;
		}

		$state = self::read( self::SWEEP_OPTION );
		$due   = isset( $state['doing'] ) ? time() : (int) ( $state['last_end'] ?? 0 ) + self::SWEEP_SECONDS;
		$due   = max( time(), $due, (int) ( $state['wait_until'] ?? 0 ) );

		self::want_run( self::SWEEP_HOOK, $due );
	}

	/**
	 * One sweep run (Action Scheduler).
	 */
	public static function run_sweep() {
		if ( ! self::take_lock( 'sweep' ) ) {
			// Another request is sweeping, and schedules what comes next.
			return;
		}

		try {
			self::sweep_batch();
		} finally {
			self::release_lock( 'sweep' );
		}
	}

	/**
	 * Read up to SWEEP_PAGES_RUN pages of the window under way - a new one
	 * when none is - and record the refunds they show; finish the window
	 * when its last page is read.
	 *
	 * Where it has got to is kept after every page, so a run ended part way
	 * - by the host, a pause, an error, Emergency Stop - carries on from the
	 * next page, never reading one twice but the page that failed. It reads
	 * eBay only, so it runs on a staging copy too; it never makes an order.
	 *
	 * @return void
	 */
	private static function sweep_batch() {
		if ( ! self::records_refunds() ) {
			return;
		}

		$start = time();
		$held  = self::held_until();
		if ( $held > $start ) {
			self::run_again( self::SWEEP_HOOK, $held );
			return;
		}

		$state = self::read( self::SWEEP_OPTION );
		$doing = isset( $state['doing'] ) && is_array( $state['doing'] ) ? $state['doing'] : null;
		if ( null === $doing && (int) ( $state['last_end'] ?? 0 ) + self::SWEEP_SECONDS > $start + 60 ) {
			// Early - run by hand from Action Scheduler's screen, say: the
			// next is the one the last sweep scheduled, which ensure_sweep()
			// puts back if it is gone.
			return;
		}
		if ( (int) ( $state['wait_until'] ?? 0 ) > $start + 60 ) {
			// Inside a pause or a back-off (fix round 1): the same, so a run
			// by hand never makes eBay's refusals more often.
			return;
		}

		$pause = self::pause( false );
		if ( $pause ) {
			$state['wait_until'] = (int) $pause['until'];
			self::write( self::SWEEP_OPTION, $state );
			self::note_pause( 'sweep', $pause );
			self::run_again( self::SWEEP_HOOK, (int) $pause['until'] );
			return;
		}
		unset( $state['wait_until'] );

		if ( null === $doing ) {
			$doing = self::new_window( $state, $start );
		} elseif ( (int) $doing['from'] < $start - self::SWEEP_FLOOR_DAYS * DAY_IN_SECONDS ) {
			// A window left part way - refunds turned off, order import off,
			// Action Scheduler stalled - and resumed too late for eBay to
			// read (fix round 1): a new window from the floor, said once.
			$doing = self::new_window( array( 'last_to' => (int) $doing['from'] + self::SWEEP_OVERLAP ), $start );
		}

		$api   = TCGiant_Sync_API::instance();
		$pages = 0;

		while ( true ) {
			$late = class_exists( 'TCGiant_Sync_Runtime' ) ? TCGiant_Sync_Runtime::over_time_budget() : '';
			if ( $pages >= self::SWEEP_PAGES_RUN || '' !== $late ) {
				$state['doing'] = $doing;
				self::write( self::SWEEP_OPTION, $state );
				self::run_again( self::SWEEP_HOOK, time() + 60 );
				return;
			}
			if ( $pages > 0 ) {
				if ( self::stopped_since( $start ) ) {
					$state['doing'] = $doing;
					self::write( self::SWEEP_OPTION, $state );
					return;
				}
				$pause = self::pause( false );
				if ( $pause ) {
					$state['doing']      = $doing;
					$state['wait_until'] = (int) $pause['until'];
					self::write( self::SWEEP_OPTION, $state );
					self::note_pause( 'sweep', $pause );
					self::run_again( self::SWEEP_HOOK, (int) $pause['until'] );
					return;
				}
			}

			$result = $api->trading_api_request( 'GetOrders', self::sweep_xml( (int) $doing['from'], (int) $doing['to'], (int) $doing['page'] ) );
			$doing['calls'] = (int) $doing['calls'] + 1;
			$pages++;

			if ( is_wp_error( $result ) ) {
				$data = $result->get_error_data();
				if ( 'rate_limited' === $result->get_error_code() && is_array( $data ) && ! empty( $data['daily_budget'] ) ) {
					// The day's budget: the next day, not a refusal.
					$wait = self::next_day();
				} else {
					// Refused, or no answer: backed off, 30 minutes to 6 hours,
					// and said once for a run of them (fix round 1).
					$fails          = (int) ( $state['fails'] ?? 0 ) + 1;
					$state['fails'] = $fails;
					$wait           = time() + self::SWEEP_BACKOFF[ min( $fails, count( self::SWEEP_BACKOFF ) ) - 1 ];
					if ( 1 === $fails ) {
						TCGiant_Sync_Logger::warning( sprintf(
							'Refund sweep: eBay\'s orders could not be read (page %d) — %s. It tries again from that page at %s UTC, then less often, every 6 hours at most, and says when it reads them again.',
							(int) $doing['page'], $result->get_error_message(), gmdate( 'Y-m-d H:i', $wait )
						) );
					}
				}
				$state['doing']      = $doing;
				$state['wait_until'] = $wait;
				self::write( self::SWEEP_OPTION, $state );
				self::run_again( self::SWEEP_HOOK, $wait );
				return;
			}

			if ( ! empty( $state['fails'] ) ) {
				TCGiant_Sync_Logger::log( sprintf(
					'Refund sweep: eBay\'s orders were read again (page %d), after %d failed tries.',
					(int) $doing['page'], (int) $state['fails']
				) );
				unset( $state['fails'] );
			}

			$orders = self::order_list( $result );
			$named  = 0;
			foreach ( $orders as $ebay_order ) {
				$ebay_id = TCGiant_Sync_API::scalar_value( $ebay_order['OrderID'] ?? '' );
				if ( '' === $ebay_id ) {
					continue;
				}
				$named++;
				$doing['read'] = (int) $doing['read'] + 1;

				$order = self::find_order( $ebay_id );
				if ( null === $order ) {
					continue;
				}
				$doing['here'] = (int) $doing['here'] + 1;

				try {
					$got = self::record_refunds( $order, $ebay_order );
				} catch ( \Throwable $e ) {
					$got = array( 0, 0 );
					TCGiant_Sync_Logger::error( sprintf(
						'Refund sweep: eBay order %s (WC Order #%d) could not be brought up to date — %s. Record any refund on it by hand.',
						$ebay_id, (int) $order->get_id(), $e->getMessage()
					) );
				}
				$doing['recorded'] = (int) $doing['recorded'] + $got[0];
				$doing['waiting']  = (int) $doing['waiting'] + $got[1];
			}
			if ( $orders && ! $named ) {
				TCGiant_Sync_Logger::warning( 'Refund sweep: eBay returned orders without their order IDs, so none of them could be matched. Nothing was recorded from this page.' );
			}

			$total = (int) TCGiant_Sync_API::scalar_value( $result['PaginationResult']['TotalNumberOfPages'] ?? '' );
			$more  = 'true' === strtolower( TCGiant_Sync_API::scalar_value( $result['HasMoreOrders'] ?? '' ) ) || (int) $doing['page'] < $total;
			$doing['page'] = (int) $doing['page'] + 1;

			if ( $more && (int) $doing['page'] > self::SWEEP_MAX_PAGES ) {
				TCGiant_Sync_Logger::warning( sprintf(
					'Refund sweep: eBay changed more than %d orders between %s and %s UTC; only the first %d pages were read. A refund on an order past them is not recorded: record it by hand.',
					self::SWEEP_MAX_PAGES * self::SWEEP_PER_PAGE, gmdate( 'Y-m-d H:i', (int) $doing['from'] ), gmdate( 'Y-m-d H:i', (int) $doing['to'] ), self::SWEEP_MAX_PAGES
				) );
				$more = false;
			}
			if ( ! $more ) {
				break;
			}

			$state['doing'] = $doing;
			self::write( self::SWEEP_OPTION, $state );
		}

		self::write( self::SWEEP_OPTION, array(
			'last_to'  => (int) $doing['to'],
			'last_end' => time(),
		) );

		TCGiant_Sync_Logger::log( sprintf(
			'Refund sweep: %d eBay order(s) changed between %s and %s UTC read, %d of them imported here; %d refund(s) recorded%s; %d eBay call(s).',
			(int) $doing['read'],
			gmdate( 'Y-m-d H:i', (int) $doing['from'] ),
			gmdate( 'Y-m-d H:i', (int) $doing['to'] ),
			(int) $doing['here'],
			(int) $doing['recorded'],
			(int) $doing['waiting'] > 0 ? sprintf( ', %d not complete on eBay yet', (int) $doing['waiting'] ) : '',
			(int) $doing['calls']
		) );

		self::run_again( self::SWEEP_HOOK, time() + self::SWEEP_SECONDS );
	}

	/**
	 * A new window: from where the last one ended, less SWEEP_OVERLAP - two
	 * days back the first time - and never more than SWEEP_FLOOR_DAYS back,
	 * inside eBay's 30; to a minute ago.
	 *
	 * @param array $state The sweep's state.
	 * @param int   $now   Now.
	 * @return array
	 */
	private static function new_window( array $state, $now ) {
		$to    = $now - 60;
		$from  = isset( $state['last_to'] ) ? (int) $state['last_to'] - self::SWEEP_OVERLAP : $now - self::SWEEP_FIRST_DAYS * DAY_IN_SECONDS;
		$floor = $now - self::SWEEP_FLOOR_DAYS * DAY_IN_SECONDS;
		if ( $from < $floor ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Refund sweep: the sweep had read eBay up to %s UTC, more than %d days ago, and it reads only the last %d days (eBay allows 30). A refund made on eBay between then and %s UTC is not recorded: record it by hand.',
				gmdate( 'Y-m-d H:i', (int) $state['last_to'] ), self::SWEEP_FLOOR_DAYS, self::SWEEP_FLOOR_DAYS, gmdate( 'Y-m-d H:i', $floor )
			) );
			$from = $floor;
		}

		return array(
			'from'     => $from,
			'to'       => $to,
			'page'     => 1,
			'read'     => 0,
			'here'     => 0,
			'recorded' => 0,
			'waiting'  => 0,
			'calls'    => 0,
		);
	}

	/**
	 * GetOrders for the orders eBay changed in a window, one page, with only
	 * what the sweep reads.
	 *
	 * @param int $from Unix time.
	 * @param int $to   Unix time.
	 * @param int $page Page, from 1.
	 * @return string
	 */
	private static function sweep_xml( $from, $to, $page ) {
		$xml = '
<ModTimeFrom>' . gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $from ) . '</ModTimeFrom>
<ModTimeTo>' . gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $to ) . '</ModTimeTo>
<OrderRole>Seller</OrderRole>
<OrderStatus>All</OrderStatus>
<Pagination>
<EntriesPerPage>' . (int) self::SWEEP_PER_PAGE . '</EntriesPerPage>
<PageNumber>' . max( 1, (int) $page ) . '</PageNumber>
</Pagination>';
		foreach ( self::SELECTORS as $selector ) {
			$xml .= '
<OutputSelector>' . $selector . '</OutputSelector>';
		}
		return $xml;
	}

	/**
	 * The eBay order imported here under an eBay order ID: the first made,
	 * as the order import finds it. Never one in the Trash. Never made.
	 *
	 * @param string $ebay_order_id eBay order ID.
	 * @return WC_Order|null
	 */
	private static function find_order( $ebay_order_id ) {
		global $wpdb;

		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table = $wpdb->prefix . 'wc_orders_meta';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT order_id FROM {$table} WHERE meta_key = '_ebay_order_id' AND meta_value = %s ORDER BY order_id ASC LIMIT 1",
				$ebay_order_id
			) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ebay_order_id' AND meta_value = %s ORDER BY post_id ASC LIMIT 1",
				$ebay_order_id
			) );
		}

		$order = $id ? wc_get_order( (int) $id ) : false;
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'get_status' ) ) {
			return null;
		}
		if ( 'ebay' !== $order->get_meta( '_ebay_order_source' ) || 'trash' === $order->get_status() ) {
			return null;
		}
		return $order;
	}

	/**
	 * An eBay order's refunds: MonetaryDetails.Refunds.Refund, one or a list.
	 *
	 * @param array $ebay_order The order.
	 * @return array[]
	 */
	private static function refunds_in( array $ebay_order ) {
		$refunds = $ebay_order['MonetaryDetails']['Refunds']['Refund'] ?? array();
		if ( is_array( $refunds ) && ( isset( $refunds['RefundAmount'] ) || isset( $refunds['RefundTime'] ) || isset( $refunds['ReferenceID'] ) ) ) {
			$refunds = array( $refunds );
		}
		return array_values( array_filter( (array) $refunds, 'is_array' ) );
	}

	/**
	 * Record the refunds eBay has completed on an order here, once each.
	 *
	 * A refund counts as complete when eBay gives it an amount and a
	 * RefundTime: eBay's reference says RefundTime is not returned for a
	 * refund that did not succeed, and GetOrders' Refund has no status of
	 * its own - one given anyway must be a success. One not complete is left
	 * until a later sweep finds it complete. Each is known by refund_key();
	 * one already on the order - in REFUNDS_META, or on a refund carrying
	 * the key - is never recorded again.
	 *
	 * @param WC_Order $order      The order here.
	 * @param array    $ebay_order eBay's order.
	 * @return int[] array( recorded, not complete yet ).
	 */
	private static function record_refunds( $order, array $ebay_order ) {
		$refunds = self::refunds_in( $ebay_order );
		if ( ! $refunds ) {
			return array( 0, 0 );
		}

		$ebay_id  = TCGiant_Sync_API::scalar_value( $ebay_order['OrderID'] ?? '' );
		$id       = (int) $order->get_id();
		$recorded = 0;
		$waiting  = 0;
		$seen     = array();

		foreach ( $refunds as $refund ) {
			$amount = TCGiant_Sync_API::money_value( $refund['RefundAmount'] ?? 0 );
			$time   = TCGiant_Sync_API::scalar_value( $refund['RefundTime'] ?? '' );
			$status = strtolower( TCGiant_Sync_API::scalar_value( $refund['RefundStatus'] ?? '' ) );
			$money  = $refund['RefundAmount'] ?? null;
			$cur    = is_array( $money ) && isset( $money['@attributes']['currencyID'] ) ? (string) $money['@attributes']['currencyID'] : '';
			$key    = self::refund_key( $refund, $amount, $time, $seen );

			if ( self::has_refund( $order, $key ) ) {
				continue;
			}
			if ( '' === $time || $amount <= 0 || ( '' !== $status && ! in_array( $status, array( 'successful', 'succeeded', 'success', 'completed', 'complete' ), true ) ) ) {
				$waiting++;
				continue;
			}

			$order = wc_get_order( $id );
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
				break;
			}
			$list     = self::refunds_meta( $order );
			$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
			$left     = method_exists( $order, 'get_remaining_refund_amount' ) ? (float) $order->get_remaining_refund_amount() : (float) $order->get_total() - (float) $order->get_total_refunded();
			$when     = strtotime( $time );
			$when_txt = $when ? gmdate( 'Y-m-d H:i', $when ) . ' UTC' : $time;
			$shown    = trim( $cur . ' ' . number_format( $amount, 2, '.', '' ) );

			if ( $left <= 0 ) {
				$list[ $key ] = array( 'amount' => 0, 'ebay' => $amount, 'time' => $time, 'refund' => 0, 'at' => time() );
				$order->update_meta_data( self::REFUNDS_META, $list );
				$order->save();
				TCGiant_Sync_Logger::log( sprintf(
					'Refund sweep: eBay refunded %s on eBay order %s (WC Order #%d) at %s, but nothing is left to refund on the order here, so nothing was recorded.',
					$shown, $ebay_id, $id, $when_txt
				) );
				continue;
			}

			$take = min( round( $amount, $decimals ), $left );

			self::$recording = $key;
			try {
				$made = wc_create_refund( array(
					'amount'         => $take,
					'reason'         => sprintf( 'Refunded on eBay (%s)', self::reference_words( $refund, $when_txt ) ),
					'order_id'       => $id,
					'line_items'     => array(),
					'refund_payment' => false,
					'restock_items'  => false,
				) );
			} catch ( \Throwable $e ) {
				$made = new WP_Error( 'refund_failed', $e->getMessage() );
			} finally {
				self::$recording = '';
			}

			if ( is_wp_error( $made ) ) {
				TCGiant_Sync_Logger::error( sprintf(
					'Refund sweep: eBay\'s refund of %s on eBay order %s (WC Order #%d) at %s could not be recorded here — %s. Record it by hand.',
					$shown, $ebay_id, $id, $when_txt, $made->get_error_message()
				) );
				continue;
			}

			// The order as WooCommerce left it: Refunded, when this was the rest.
			$order = wc_get_order( $id );
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
				break;
			}
			$list         = self::refunds_meta( $order );
			$list[ $key ] = array( 'amount' => $take, 'ebay' => $amount, 'time' => $time, 'refund' => (int) $made->get_id(), 'at' => time() );
			$order->update_meta_data( self::REFUNDS_META, $list );
			$order->add_order_note( sprintf(
				/* translators: 1: the amount recorded, 2: eBay's amount, 3: when eBay refunded it */
				__( 'TCGiant Sync recorded a refund of %1$s made on eBay (eBay refunded %2$s at %3$s)%4$s. Nothing was sent to eBay or the buyer, and no stock was changed.', 'tcgiant-sync' ),
				number_format( $take, $decimals, '.', '' ),
				$shown,
				$when_txt,
				$take < round( $amount, $decimals ) ? __( ', capped at what was left to refund here', 'tcgiant-sync' ) : ''
			) );
			$order->save();
			$recorded++;

			TCGiant_Sync_Logger::log( sprintf(
				'Refund sweep: eBay\'s refund of %s on eBay order %s at %s recorded on WC Order #%d as a refund of %s%s%s.',
				$shown, $ebay_id, $when_txt, $id, number_format( $take, $decimals, '.', '' ),
				$take < round( $amount, $decimals ) ? ' (capped at what was left to refund)' : '',
				'refunded' === $order->get_status() ? '; the order is now Refunded' : ''
			), 'success' );
		}

		return array( $recorded, $waiting );
	}

	/**
	 * eBay's reference for a refund: its ReferenceID when eBay gives one -
	 * its reference says that field is now kept for in-store pick-up - else
	 * its time and amount, with how many of the same came before it on the
	 * order, so two alike stay two.
	 *
	 * @param array  $refund The refund.
	 * @param float  $amount Its amount.
	 * @param string $time   Its RefundTime.
	 * @param array  $seen   The keys so far on this order, counted.
	 * @return string
	 */
	private static function refund_key( array $refund, $amount, $time, array &$seen ) {
		$ref = TCGiant_Sync_API::scalar_value( $refund['ReferenceID'] ?? '' );
		if ( '' !== $ref ) {
			return 'ref:' . $ref;
		}
		$base          = 't:' . $time . '|' . number_format( (float) $amount, 2, '.', '' );
		$seen[ $base ] = (int) ( $seen[ $base ] ?? 0 ) + 1;
		return $base . '#' . $seen[ $base ];
	}

	/**
	 * The reason's reference: eBay's ReferenceID, or the refund's time.
	 *
	 * @param array  $refund   The refund.
	 * @param string $when_txt Its time, as shown.
	 * @return string
	 */
	private static function reference_words( array $refund, $when_txt ) {
		$ref = TCGiant_Sync_API::scalar_value( $refund['ReferenceID'] ?? '' );
		return '' !== $ref ? 'reference ' . $ref : 'refund of ' . $when_txt;
	}

	/**
	 * The refunds recorded on an order.
	 *
	 * @param WC_Order $order The order.
	 * @return array
	 */
	private static function refunds_meta( $order ) {
		$list = $order->get_meta( self::REFUNDS_META );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Whether an eBay refund is on an order already: in its record, or on a
	 * refund that carries the key (one whose record was not written after).
	 *
	 * @param WC_Order $order The order.
	 * @param string   $key   The refund's reference.
	 * @return bool
	 */
	private static function has_refund( $order, $key ) {
		if ( isset( self::refunds_meta( $order )[ $key ] ) ) {
			return true;
		}
		if ( method_exists( $order, 'get_refunds' ) ) {
			foreach ( (array) $order->get_refunds() as $refund ) {
				if ( is_object( $refund ) && method_exists( $refund, 'get_meta' ) && $key === (string) $refund->get_meta( self::REFUND_KEY_META ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Hook: woocommerce_create_refund. The refund being recorded from eBay
	 * carries eBay's reference, saved with it.
	 *
	 * @param WC_Order_Refund|mixed $refund The refund, before its save.
	 * @param array                 $args   wc_create_refund()'s arguments.
	 * @return void
	 */
	public static function mark_refund( $refund, $args = array() ) {
		if ( '' !== self::$recording && is_object( $refund ) && method_exists( $refund, 'update_meta_data' ) ) {
			$refund->update_meta_data( self::REFUND_KEY_META, self::$recording );
		}
	}

	/**
	 * Hooks: woocommerce_email_enabled_customer_refunded_order and
	 * ..._partially_refunded_order. No "Refunded order" email for an eBay
	 * order: the buyer is eBay's, and eBay tells them.
	 *
	 * @param bool           $enabled Whether WooCommerce would send it.
	 * @param WC_Order|mixed $order   The order.
	 * @return bool
	 */
	public static function no_refund_email( $enabled, $order = null ) {
		if ( $enabled && is_object( $order ) && method_exists( $order, 'get_meta' ) && 'ebay' === $order->get_meta( '_ebay_order_source' ) ) {
			return false;
		}
		return $enabled;
	}

	// -------------------------------------------------------------------------
	// Emergency Stop, pauses and scheduling
	// -------------------------------------------------------------------------

	/**
	 * Emergency Stop: cancel both runs. Feedback is held until the shop lets
	 * it go again (resume()); the sweep, which sends eBay nothing, for
	 * STOP_HOLD (fix round 1, the owner's choice). A run under way stops
	 * before its next eBay call; the orders waiting for feedback, and where
	 * the sweep had got to, are kept.
	 *
	 * @return void
	 */
	public static function stop() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::FEEDBACK_HOOK, null, self::GROUP );
			as_unschedule_all_actions( self::SWEEP_HOOK, null, self::GROUP );
		}
		update_option( self::STOP_OPTION, time(), false );
		update_option( self::FEEDBACK_HELD_OPTION, time(), false );
		TCGiant_Sync_Logger::log( 'Emergency Stop: feedback for eBay buyers is held until you let it go again - press Resume Import or Fetch Inventory on the Import from eBay page, or save the order settings on Settings. The eBay refund sweep, which sends eBay nothing, waits 6 hours. One under way stops before its next eBay call; nothing waiting is lost.', 'warning' );
	}

	/**
	 * The shop lets feedback go again after Emergency Stop (fix round 1):
	 * Resume Import, Fetch Inventory, or a save of the order settings. The
	 * orders that reached the trigger meanwhile go now. Nothing when
	 * feedback was not held.
	 *
	 * @param string $how What the shop did, for the log.
	 * @return void
	 */
	public static function resume( $how = '' ) {
		if ( ! self::feedback_held() ) {
			return;
		}
		delete_option( self::FEEDBACK_HELD_OPTION );
		TCGiant_Sync_Logger::log( sprintf(
			'Feedback for eBay buyers carries on after Emergency Stop%s.',
			'' !== (string) $how ? ': ' . $how : ''
		) );
		if ( self::read( self::QUEUE_OPTION ) ) {
			try {
				self::want_run( self::FEEDBACK_HOOK, time() );
			} catch ( \Throwable $e ) {
				TCGiant_Sync_Logger::error( 'Feedback: its run could not be scheduled — ' . $e->getMessage() . '. It runs when the next order is queued.' );
			}
		}
	}

	/**
	 * Whether Emergency Stop holds feedback, as the table has it now.
	 *
	 * @return bool
	 */
	public static function feedback_held() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::FEEDBACK_HELD_OPTION, 'options' );
		}
		return (int) get_option( self::FEEDBACK_HELD_OPTION, 0 ) > 0;
	}

	/**
	 * Until when Emergency Stop holds the sweep; 0 when it does not.
	 *
	 * @return int
	 */
	private static function held_until() {
		$stop = self::stop_time();
		return $stop > 0 && $stop + self::STOP_HOLD > time() ? $stop + self::STOP_HOLD : 0;
	}

	/**
	 * Whether Emergency Stop has been pressed since a run began.
	 *
	 * @param int $start When the run began.
	 * @return bool
	 */
	private static function stopped_since( $start ) {
		return self::stop_time() >= (int) $start;
	}

	/**
	 * When Emergency Stop was last pressed, as the table has it now.
	 *
	 * @return int
	 */
	private static function stop_time() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::STOP_OPTION, 'options' );
		}
		return (int) get_option( self::STOP_OPTION, 0 );
	}

	/**
	 * Why feedback or the sweep waits now, and until when; null when it may
	 * go:
	 *
	 * - a staging copy, for feedback only (the sweep only reads);
	 * - no eBay connection;
	 * - no relay figure for today, or one over two hours old;
	 * - the day's budget spent (is_daily_budget_exhausted());
	 * - every shop together with under FLEET_FLOOR of its ceiling left;
	 * - this site at OWN_SHARE or more of its own allowance today, which
	 *   keeps room for stock and orders.
	 *
	 * @param bool $writes Whether what waits sends a change to eBay.
	 * @return array{why: string, until: int}|null
	 */
	public static function pause( $writes ) {
		$now = time();

		if ( $writes && TCGiant_Sync_Environment::write_block() ) {
			return array( 'why' => 'staging', 'until' => 0 );
		}
		if ( ! TCGiant_Sync_OAuth::instance()->is_authenticated() ) {
			return array( 'why' => 'not_connected', 'until' => $now + HOUR_IN_SECONDS );
		}

		$api   = TCGiant_Sync_API::instance();
		$relay = $api->get_relay_budget();
		if ( false === $relay ) {
			return array( 'why' => 'fleet_unknown', 'until' => $now + 30 * MINUTE_IN_SECONDS );
		}
		if ( $api->is_daily_budget_exhausted() ) {
			return array( 'why' => 'budget', 'until' => self::next_day() );
		}

		$ceiling = (int) ( (int) ( $relay['daily_limit'] ?? 0 ) * TCGiant_Sync_API::DAILY_CALL_SOFT_CEILING );
		if ( $ceiling <= 0 || $api->get_remaining_daily_budget() < $ceiling * self::FLEET_FLOOR ) {
			return array( 'why' => 'fleet_low', 'until' => self::next_day() );
		}

		$own = $api->site_daily_allowance();
		if ( $own > 0 && $api->get_daily_call_count() >= $own * self::OWN_SHARE ) {
			return array( 'why' => 'own_half', 'until' => self::next_day() );
		}

		return null;
	}

	/**
	 * Say once a UTC day, for each, why feedback or the sweep waits.
	 *
	 * @param string $job   'feedback' or 'sweep'.
	 * @param array  $pause pause()'s answer.
	 * @return void
	 */
	private static function note_pause( $job, array $pause ) {
		$name = 'tcgiant_followthrough_paused_' . $job;
		$mark = gmdate( 'Y-m-d' ) . '|' . $pause['why'];
		if ( get_transient( $name ) === $mark ) {
			return;
		}
		set_transient( $name, $mark, DAY_IN_SECONDS );

		$words = array(
			'not_connected' => 'eBay is not connected',
			'fleet_unknown' => 'today\'s figure for eBay calls is not known yet',
			'budget'        => 'the day\'s eBay calls are used up',
			'fleet_low'     => 'every shop together has less than a quarter of the day\'s eBay calls left',
			'own_half'      => 'this site has used half of its own eBay calls for the day, which keeps the rest for stock and orders',
		);
		TCGiant_Sync_Logger::log( sprintf(
			'%s waits until %s UTC: %s. This is said once a day.',
			'feedback' === $job ? 'Feedback for eBay buyers' : 'The eBay refund sweep',
			gmdate( 'Y-m-d H:i', (int) $pause['until'] ),
			$words[ $pause['why'] ] ?? $pause['why']
		), 'warning' );
	}

	/**
	 * Five minutes into the next UTC day, when the counts start again.
	 *
	 * @return int
	 */
	private static function next_day() {
		return (int) strtotime( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) . ' 00:05:00 UTC' );
	}

	/**
	 * Ask for a run, from outside one: at the time given, or sooner when one
	 * is already waiting for then. One waiting earlier, or one under way,
	 * serves. Never inside Emergency Stop's hold: the sweep's six hours, or
	 * feedback's until the shop resumes.
	 *
	 * @param string $hook The hook.
	 * @param int    $when The time.
	 * @return void
	 */
	private static function want_run( $hook, $when ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( self::FEEDBACK_HOOK === $hook && self::feedback_held() ) {
			// resume() asks for it.
			return;
		}
		$when = self::SWEEP_HOOK === $hook ? max( (int) $when, self::held_until() ) : (int) $when;
		$next = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( $hook, null, self::GROUP ) : false;
		if ( false !== $next && ( ! is_int( $next ) || $next <= $when ) ) {
			return;
		}
		if ( false !== $next && function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( $hook, array(), self::GROUP );
		}
		self::schedule_at( $hook, $when );
	}

	/**
	 * The next run, from inside one. Never inside Emergency Stop's hold.
	 *
	 * @param string $hook The hook.
	 * @param int    $when The time.
	 * @return void
	 */
	private static function run_again( $hook, $when ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( self::FEEDBACK_HOOK === $hook ) {
			if ( ! self::feedback_held() ) {
				self::schedule_at( $hook, (int) $when );
			}
			return;
		}
		self::schedule_at( $hook, max( (int) $when, self::held_until() ) );
	}

	/**
	 * Schedule one of the two hooks, each named where it is scheduled (as
	 * tools/check-hooks.php reads them), in the group, after the stock
	 * pushes due then.
	 *
	 * @param string $hook The hook.
	 * @param int    $when The time.
	 * @return void
	 */
	private static function schedule_at( $hook, $when ) {
		if ( self::SWEEP_HOOK === $hook ) {
			as_schedule_single_action( (int) $when, self::SWEEP_HOOK, array(), self::GROUP, false, self::PRIORITY );
		} else {
			as_schedule_single_action( (int) $when, self::FEEDBACK_HOOK, array(), self::GROUP, false, self::PRIORITY );
		}
	}

	// -------------------------------------------------------------------------
	// Holds and options
	// -------------------------------------------------------------------------

	/**
	 * Hold a piece of this work for one request: one sends feedback at a
	 * time, and one sweeps. As Re-send to eBay holds its work: a plain
	 * INSERT, which the options table's unique name lets in for one request
	 * only; a hold past its time is a request that was killed, and is taken
	 * over by deleting exactly the value read.
	 *
	 * @param string $what feedback or sweep.
	 * @return bool
	 */
	private static function take_lock( $what ) {
		global $wpdb;

		$name  = self::LOCK_PREFIX . $what;
		$now   = time();
		$value = ( $now + self::LOCK_SECONDS ) . '|' . wp_generate_uuid4();
		$first = "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s ORDER BY option_id ASC LIMIT 1";

		for ( $try = 0; $try < 2; $try++ ) {
			$quiet = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$took = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				$name,
				$value
			) );
			$wpdb->suppress_errors( $quiet );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$held = $wpdb->get_var( $wpdb->prepare( $first, $name ) );

			if ( $took && $value === $held ) {
				self::$held[ $what ] = $value;
				return true;
			}
			if ( $took ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value ) );
			}
			if ( null === $held || (int) $held > $now ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $held ) );
		}

		return false;
	}

	/**
	 * Let go of a hold: this request's own, never one taken over since.
	 *
	 * @param string $what feedback or sweep.
	 * @return void
	 */
	private static function release_lock( $what ) {
		global $wpdb;

		if ( empty( self::$held[ $what ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_PREFIX . $what, self::$held[ $what ] ) );
		unset( self::$held[ $what ] );
	}

	/**
	 * An option as the table has it now, not as this request first read it.
	 *
	 * @param string $name Option.
	 * @return array
	 */
	private static function read( $name ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
		}
		$value = get_option( $name, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Write an option, never autoloaded.
	 *
	 * @param string $name  Option.
	 * @param array  $value Value.
	 * @return void
	 */
	private static function write( $name, array $value ) {
		update_option( $name, $value, false );
	}
}
