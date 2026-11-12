<?php
/**
 * Re-send to eBay: a template-side change sent to the listings already on
 * eBay, description only (3.34.0).
 *
 * A listing gets its template at its next push, which costs 1 to 13 eBay
 * calls and sends the title, price, item specifics and photos again. After a
 * template is picked or edited, this sends the new description alone: one
 * Revise call a listing, carrying the ItemID and the Description and nothing
 * else. A revise changes only the fields it is sent (checked live on
 * 2026-11-02), so price, quantity, item specifics and photos stay as they
 * are on eBay. Variation listings are left to their next push: eBay's
 * documentation does not say what a revise without its <Variations> does
 * to them, and none has been sent one live.
 *
 * Three parts:
 *
 * - The count. After any template-side change - a template saved, restored,
 *   copied or deleted, a block, the Template settings, the default, the bulk
 *   template action, a product's own choice, policies fetched that changed -
 *   one count is queued, and ten changes in a minute make one count. It
 *   renders, in batches of SCAN_BATCH in Action Scheduler, the description
 *   the next push would send each listing, and compares it with what the
 *   last send recorded. It changes nothing and asks eBay nothing: the render
 *   reads only what this site keeps (TCGiant_Sync_API::cached_only()). A
 *   listing whose description would change goes in the group of the template
 *   it would now get, or "Back to the plain description"; one that may not
 *   be sent goes under its reason; the rest cost nothing.
 *
 * - Test on 5. Five listings of one group, sent at once, each shown with its
 *   eBay link and eBay's answer. A test where every one came back OK
 *   unlocks Re-send all for that template as it is now; a new save locks it
 *   again.
 *
 * - Re-send all. The group's listings, queued in order - those at quantity
 *   0 that eBay's Out-of-Stock Control keeps alive last - and sent in
 *   batches of SEND_BATCH, each taken from the day's bulk-push limit
 *   (TCGiant_Sync_Exporter::take_bulk_push_allowance()), at most a twelfth
 *   of that limit in any two hours, and only while every shop's eBay
 *   budget and this site's own allowance have room (pause()). Every listing
 *   is rendered and judged again just before it goes: one that no longer
 *   qualifies is dropped, with its reason. A failure is tried again on up to
 *   RETRY_DAYS later days, then needs attention. Stop, and Emergency Stop,
 *   end it.
 *
 * Nothing here is a push: _ebay_export_last_pushed and the pushed version
 * are not written. What eBay accepted is recorded as a push records it
 * (TCGiant_Sync_Exporter::record_resend()).
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Template_Resend class.
 */
class TCGiant_Sync_Template_Resend {

	/** The count's Action Scheduler hook: one batch a run. */
	const SCAN_HOOK = 'tcgiant_sync_tpl_resend_scan';

	/** Re-send all's Action Scheduler hook: one batch a run. */
	const RUN_HOOK = 'tcgiant_sync_tpl_resend_run';

	/** Both hooks' Action Scheduler group. */
	const GROUP = 'tcgiant_sync_templates';

	/**
	 * The count: the last one finished ('done') and one under way ('doing'),
	 * each with its time, its groups and reasons; and the template-side
	 * changes so far ('changes', one more each), and when the last was
	 * ('wanted'). A count notes the changes it began after, so one that came
	 * in while it counted - in the same second or not - asks for another.
	 * Not autoloaded, and small: the listings themselves are in IDS_PREFIX
	 * options.
	 */
	const COUNT_OPTION = 'tcgiant_sync_tpl_resend_count';

	/**
	 * The listings a count found would change, one option for each
	 * SAVE_EVERY products it has looked at that found any, named by the
	 * count and a number: group => array( product ID => 1 if its quantity is
	 * 0, else 0 ). At most SAVE_EVERY listings each, never autoloaded, so
	 * 20,000 listings are a few hundred small rows rather than one large one.
	 */
	const IDS_PREFIX = 'tcgiant_sync_tpl_resend_ids_';

	/** Re-send all: where it is, what it has done, why it waits. */
	const STATE_OPTION = 'tcgiant_sync_tpl_resend_state';

	/** Re-send all's queue: QUEUE_CHUNK product IDs an option. */
	const QUEUE_PREFIX = 'tcgiant_sync_tpl_resend_queue_';

	/** Listings eBay refused today, to be tried again on a later day. */
	const RETRY_OPTION = 'tcgiant_sync_tpl_resend_retry';

	/** Test on 5's last result for each group, which unlocks Re-send all. */
	const TESTS_OPTION = 'tcgiant_sync_tpl_resend_tests';

	/** Re-sends a minute, for the last two hours: minute => sends. */
	const WINDOW_OPTION = 'tcgiant_sync_tpl_resend_window';

	/** One request at a time counts, and one sends: see take_lock(). */
	const LOCK_PREFIX = 'tcgiant_sync_tpl_resend_lock_';

	/** Listings a batch of the count looks at. */
	const SCAN_BATCH = 200;

	/**
	 * How long a count stopped for this request's time waits before its next
	 * batch, so that batch runs in another request, with time of its own.
	 */
	const LATE_DELAY = 60;

	/**
	 * The count keeps what it has found each time it has looked at this
	 * many products, so a batch the host ends part way loses no more.
	 */
	const SAVE_EVERY = 50;

	/**
	 * A listing whose call comes back with no answer about it this many
	 * times in a row is taken as refused (see send()), so that one eBay
	 * always fails to answer cannot hold Re-send all on it for good.
	 */
	const NO_ANSWER_LIMIT = 3;

	/**
	 * The errors that are about the one call - eBay not reached, or an
	 * answer that could not be read - and so count toward NO_ANSWER_LIMIT.
	 * eBay's own limit on calls (rate_limited, 518, 10007), no token and the
	 * day's budget are about every call, and pause without counting.
	 */
	const NO_ANSWER_CODES = array( 'http_request_failed', 'invalid_xml', 'response_too_large' );

	/** The day the log last said the relay's figure has no site allowance. */
	const NO_CAP_TRANSIENT = 'tcgiant_resend_nocap';

	/** Re-sends a batch sends at most. */
	const SEND_BATCH = 50;

	/** Listings a batch of Re-send all looks at, sent or dropped, at most. */
	const LOOK_BATCH = 200;

	/** How many Test on 5 sends. */
	const TEST_SIZE = 5;

	/** Listings Test on 5 judges at most to find its five. */
	const TEST_LOOK = 100;

	/** Product IDs an option of the queue. */
	const QUEUE_CHUNK = 500;

	/** How long a count waits after a change, so ten changes make one count. */
	const SCAN_DELAY = 60;

	/**
	 * A listing sent a description in the last day is left until the next:
	 * eBay limits how often a listing may be revised in a day, and the stock
	 * pushes use the same allowance.
	 */
	const RECENT_SECONDS = 86400;

	/** Later days a refused re-send is tried again on, before it needs attention. */
	const RETRY_DAYS = 3;

	/** The window the throttle counts in, and the share of the day's limit it allows. */
	const WINDOW_SECONDS = 7200;
	const WINDOW_SHARE   = 12;

	/** Paused while every shop together has less than this share of its ceiling left. */
	const FLEET_FLOOR = 0.25;

	/** Paused once this site has used this share of its own allowance today. */
	const OWN_SHARE = 0.5;

	/**
	 * How far a product's date_modified may be past its last push and still
	 * count as not changed since: the push's own save, a moment after it
	 * records the time. See date_rule().
	 */
	const MODIFIED_SLACK = 30;

	/** How long a hold on counting or sending lasts at most. */
	const LOCK_SECONDS = 600;

	/** eBay's ceiling on a description, in characters, tags included. */
	const MAX_CHARS = 500000;

	/** The group of listings going back to the plain description. */
	const NONE_GROUP = 'none';

	/**
	 * The holds this request has: name => value.
	 *
	 * @var array<string, string>
	 */
	private static $held = array();

	/**
	 * The two hooks, on every request: Action Scheduler runs them from
	 * WP-Cron and its own requests as well as the admin.
	 */
	public static function register() {
		add_action( self::SCAN_HOOK, array( __CLASS__, 'run_scan' ) );
		add_action( self::RUN_HOOK, array( __CLASS__, 'run_batch' ) );
	}

	// -------------------------------------------------------------------------
	// The count
	// -------------------------------------------------------------------------

	/**
	 * Ask for a count, after a template-side change or Count again.
	 *
	 * One waits SCAN_DELAY seconds, so ten saves in a row make one count; a
	 * count under way when a change comes in runs again once it is done.
	 *
	 * A shop with no published template, that was never sent one and never
	 * counted, gets nothing from a change: no count, no option and no line
	 * in the log. Count again, pressed, counts all the same.
	 *
	 * @param string $why What changed, for the log.
	 * @param bool   $now Count again: start without waiting.
	 */
	public static function queue_scan( $why = '', $now = false ) {
		if ( ! $now && ! self::read( self::COUNT_OPTION ) && ! self::reaches_any() ) {
			return;
		}

		$count            = self::read( self::COUNT_OPTION );
		$count['changes'] = (int) ( $count['changes'] ?? 0 ) + 1;
		$count['wanted']  = time();
		$count['why']     = (string) $why;
		self::write( self::COUNT_OPTION, $count );

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$pending = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( self::SCAN_HOOK, null, self::GROUP ) : false;

		// A count under way runs again at its end, for this change - unless
		// it died part way: nothing waits to carry it on, and it has not
		// moved for longer than a hold lasts. Then it carries on now.
		if ( isset( $count['doing'] ) ) {
			if ( false === $pending && time() - (int) ( $count['doing']['touched'] ?? 0 ) > self::LOCK_SECONDS ) {
				as_schedule_single_action( time(), self::SCAN_HOOK, array(), self::GROUP );
			}
			return;
		}

		// One already waiting serves; Count again does not wait for it.
		if ( false !== $pending && ! ( $now && is_int( $pending ) && $pending > time() && function_exists( 'as_unschedule_action' ) ) ) {
			return;
		}
		if ( false !== $pending ) {
			as_unschedule_action( self::SCAN_HOOK, array(), self::GROUP );
		}

		as_schedule_single_action( time() + ( $now ? 0 : self::SCAN_DELAY ), self::SCAN_HOOK, array(), self::GROUP );
	}

	/**
	 * One batch of the count (Action Scheduler).
	 */
	public static function run_scan() {
		if ( ! self::take_lock( 'count', self::LOCK_SECONDS ) ) {
			// Another request is counting: this one's turn comes after.
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 60, self::SCAN_HOOK, array(), self::GROUP );
			}
			return;
		}

		try {
			self::scan_batch();
		} finally {
			self::release_lock( 'count' );
		}
	}

	/**
	 * Count SCAN_BATCH more products, and finish when there are no more.
	 *
	 * What it has found is kept every SAVE_EVERY products, and it stops
	 * early when this request has used the time the host gives it
	 * (TCGiant_Sync_Runtime::over_time_budget()): the next batch carries on
	 * from the last product kept, so a batch the host ends part way stalls
	 * nothing. Stopped for time, it comes back a minute on (LATE_DELAY), in
	 * another request with its own time, as the photo cleanup does - not at
	 * once in this one, which would count one product an action. Under
	 * WP-CLI no host ends the request, so it does not stop for time.
	 */
	private static function scan_batch() {
		$count = self::read( self::COUNT_OPTION );
		$doing = isset( $count['doing'] ) && is_array( $count['doing'] ) ? $count['doing'] : array(
			'id'        => substr( md5( uniqid( '', true ) ), 0, 10 ),
			'started'   => time(),
			'changes'   => (int) ( $count['changes'] ?? 0 ),
			'cursor'    => 0,
			'chunks'    => 0,
			'looked'    => 0,
			'unchanged' => 0,
			'missed'    => 0,
			'groups'    => array(),
			'reasons'   => array(),
		);

		$ids     = self::products_after( (int) $doing['cursor'], self::SCAN_BATCH );
		$members = array();
		$since   = 0;
		$late    = false;
		$timed   = self::host_ends_requests();
		foreach ( $ids as $n => $id ) {
			// Out of time: what is counted so far is kept below, and the
			// next batch carries on. Always one product, so each batch moves.
			if ( $n > 0 && $timed && '' !== (string) TCGiant_Sync_Runtime::over_time_budget() ) {
				$late = true;
				break;
			}

			$doing['cursor'] = $id;
			$verdict         = self::judge( $id );
			if ( 'skip' !== $verdict['verdict'] ) {
				$doing['looked']++;
				$doing['missed'] += (int) $verdict['missed'];
			}
			if ( 'unchanged' === $verdict['verdict'] ) {
				$doing['unchanged']++;
			} elseif ( 'left' === $verdict['verdict'] ) {
				$doing['reasons'][ $verdict['reason'] ] = (int) ( $doing['reasons'][ $verdict['reason'] ] ?? 0 ) + 1;
			} elseif ( 'send' === $verdict['verdict'] ) {
				$group = $verdict['group'];
				if ( ! isset( $doing['groups'][ $group ] ) ) {
					$doing['groups'][ $group ] = array(
						'template_id' => (int) $verdict['render']['template_id'],
						'version'     => (int) $verdict['render']['version'],
						'name'        => (string) ( $verdict['render']['name'] ?? '' ),
						'count'       => 0,
						'last'        => 0,
					);
				}
				$doing['groups'][ $group ]['count']++;
				$doing['groups'][ $group ]['last'] += $verdict['last'] ? 1 : 0;
				$members[ $group ][ $id ] = $verdict['last'] ? 1 : 0;
			}

			if ( ++$since >= self::SAVE_EVERY ) {
				$doing   = self::save_count( $doing, $members );
				$members = array();
				$since   = 0;
			}
		}

		if ( $late || count( $ids ) >= self::SCAN_BATCH ) {
			self::save_count( $doing, $members );
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + ( $late ? self::LATE_DELAY : 0 ), self::SCAN_HOOK, array(), self::GROUP );
			}
			return;
		}

		if ( $members ) {
			self::write( self::IDS_PREFIX . $doing['id'] . '_' . $doing['chunks'], $members );
			$doing['chunks']++;
		}

		// Done: this count replaces the last, whose listings go.
		$doing['finished']  = time();
		$doing['templates'] = array();
		foreach ( TCGiant_Sync_Templates::all() as $tpl ) {
			$doing['templates'][ (int) $tpl['id'] ] = (int) $tpl['version'];
		}

		$count = self::read( self::COUNT_OPTION );
		$old   = isset( $count['done'] ) && is_array( $count['done'] ) ? $count['done'] : null;
		unset( $count['doing'] );
		$count['done'] = $doing;
		self::write( self::COUNT_OPTION, $count );
		if ( $old ) {
			self::forget_ids( $old );
		}

		$total = 0;
		foreach ( $doing['groups'] as $group ) {
			$total += (int) $group['count'];
		}
		TCGiant_Sync_Logger::log( sprintf(
			'Re-send to eBay: counted %1$d listing(s) a template reaches. %2$d would change (%3$s), %4$d left out%5$s, %6$d unchanged. Nothing was sent to eBay.',
			(int) $doing['looked'],
			$total,
			$total ? self::groups_words( $doing['groups'] ) : 'none',
			array_sum( $doing['reasons'] ),
			$doing['reasons'] ? ' (' . self::reasons_words( $doing['reasons'] ) . ')' : '',
			(int) $doing['unchanged']
		) );

		// A change came in while this counted: count again for it.
		if ( self::stale( $count ) && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::SCAN_DELAY, self::SCAN_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Whether a template-side change came in after the last count began.
	 *
	 * @param array $count COUNT_OPTION.
	 * @return bool
	 */
	private static function stale( array $count ) {
		return isset( $count['done'] ) && is_array( $count['done'] ) && (int) ( $count['changes'] ?? 0 ) > (int) ( $count['done']['changes'] ?? 0 );
	}

	/**
	 * Whether a change came in that no finished count has seen: stale(),
	 * or the first count of all not finished yet.
	 *
	 * @param array $count COUNT_OPTION.
	 * @return bool
	 */
	private static function wanted( array $count ) {
		$done = isset( $count['done'] ) && is_array( $count['done'] ) ? $count['done'] : array();
		return (int) ( $count['changes'] ?? 0 ) > (int) ( $done['changes'] ?? 0 );
	}

	/**
	 * Whether a host may end this request for time: not under WP-CLI, where
	 * the time budget is the runtime's own and nothing ends the run.
	 *
	 * @return bool
	 */
	private static function host_ends_requests() {
		return ! ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Keep what a count has found so far: the listings in a row of their
	 * own, and where it has got to, with when.
	 *
	 * @param array $doing   The count under way.
	 * @param array $members The listings found since the last save, by group.
	 * @return array The count under way, as kept.
	 */
	private static function save_count( array $doing, array $members ) {
		if ( $members ) {
			self::write( self::IDS_PREFIX . $doing['id'] . '_' . $doing['chunks'], $members );
			$doing['chunks']++;
		}
		$doing['touched'] = time();
		$count            = self::read( self::COUNT_OPTION );
		$count['doing']   = $doing;
		self::write( self::COUNT_OPTION, $count );
		return $doing;
	}

	/**
	 * Whether a template reaches any product: one is published, or a product
	 * was sent one. A draft reaches nothing - it resolves to None - and every
	 * shop has the starters as drafts. A shop with neither has nothing to
	 * count.
	 *
	 * @return bool
	 */
	private static function reaches_any() {
		global $wpdb;

		if ( TCGiant_Sync_Templates::all( 'publish' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tcgiant_tpl_id' AND meta_value NOT IN ( '', '0' ) LIMIT 1" );
	}

	/**
	 * The next products after an ID, in ID order. Every product, linked or
	 * not: a product a template reaches that is not on eBay is counted as
	 * such. Variations are their parent's listing.
	 *
	 * @param int $after ID to start after.
	 * @param int $limit How many.
	 * @return int[]
	 */
	private static function products_after( $after, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ( 'publish', 'private', 'draft', 'pending', 'future' ) AND ID > %d ORDER BY ID ASC LIMIT %d",
			(int) $after,
			(int) $limit
		) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Delete the listings an old count kept.
	 *
	 * @param array $count The count.
	 */
	private static function forget_ids( array $count ) {
		for ( $n = 0; $n < (int) ( $count['chunks'] ?? 0 ); $n++ ) {
			delete_option( self::IDS_PREFIX . $count['id'] . '_' . $n );
		}
	}

	/**
	 * The members of a group in the last count, those at quantity 0 last.
	 *
	 * @param string $group Group key.
	 * @param int    $limit Stop after this many; 0 for all.
	 * @return int[]
	 */
	private static function members( $group, $limit = 0 ) {
		$done = self::read( self::COUNT_OPTION )['done'] ?? null;
		if ( ! is_array( $done ) ) {
			return array();
		}

		$first = array();
		$last  = array();
		for ( $n = 0; $n < (int) ( $done['chunks'] ?? 0 ); $n++ ) {
			$chunk = self::read( self::IDS_PREFIX . $done['id'] . '_' . $n );
			foreach ( (array) ( $chunk[ $group ] ?? array() ) as $id => $zero ) {
				if ( $zero ) {
					$last[] = (int) $id;
				} else {
					$first[] = (int) $id;
				}
			}
			if ( $limit > 0 && count( $first ) >= $limit ) {
				break;
			}
		}

		$all = array_merge( $first, $last );
		return $limit > 0 ? array_slice( $all, 0, $limit ) : $all;
	}

	// -------------------------------------------------------------------------
	// Whether a listing would change, and may be sent
	// -------------------------------------------------------------------------

	/**
	 * What the re-send makes of one product, now.
	 *
	 * The count and the send ask the same, so a listing is judged by one set
	 * of rules: once when it is counted, and again just before it goes.
	 *
	 * - skip: a template does not reach it - it resolves to None and was
	 *   never sent with one, or it is not a product. Not counted: a shop
	 *   with no template gets nothing new.
	 * - unchanged: the description the next push would send is the one the
	 *   listing has. Costs nothing.
	 * - left: it would change, or cannot be compared, and is left out for
	 *   'reason'.
	 * - send: it goes in 'group' - "t" and the template's ID, or NONE_GROUP -
	 *   with 'last' when its quantity is 0.
	 *
	 * Nothing here writes, and nothing asks eBay: the render reads only what
	 * this site keeps.
	 *
	 * @param int  $product_id Product.
	 * @param bool $sending    Just before a send: a push of it under way
	 *                         leaves it to that push.
	 * @return array verdict, reason, group, last, missed; and, to send,
	 *               product, settings, render, item_id, fixed.
	 */
	public static function judge( $product_id, $sending = false ) {
		$product_id = (int) $product_id;
		$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product || $product->is_type( 'variation' ) ) {
			return self::verdict( 'skip' );
		}

		// A template reaches it: it resolves to one, or was last sent one.
		$sent_with = (int) $product->get_meta( '_tcgiant_tpl_id' );
		$resolved  = TCGiant_Sync_Templates::resolve( $product );
		if ( (int) $resolved['template_id'] <= 0 && $sent_with <= 0 ) {
			return self::verdict( 'skip' );
		}

		$item_id = (string) $product->get_meta( '_ebay_item_id' );
		if ( '' === $item_id ) {
			return self::verdict( 'left', 'unlinked' );
		}
		if ( TCGiant_Sync_Exporter::linked_listing_ended( $product_id ) ) {
			return self::verdict( 'left', 'ended' );
		}

		// Only the shop's own eBay site: a copy on eBay.de or eBay.co.uk, or
		// on eBay Motors, is left to its next push. One whose site is not
		// recorded waits for a sync to record it - the full sync does, for
		// every linked listing, whatever "Import listings from" says - and is
		// never taken for the shop's own: a push before 3.27.5 recorded no
		// site, and the shop may have changed its marketplace since.
		$site = (string) $product->get_meta( '_ebay_site' );
		if ( '' === $site ) {
			return self::verdict( 'left', 'site_unknown' );
		}
		if ( $site !== (string) ( TCGiant_Sync_API::instance()->get_marketplace_config()['site'] ?? '' ) ) {
			return self::verdict( 'left', 'other_site' );
		}

		// A variation listing waits for its next push: eBay's documentation
		// does not say what a revise without its <Variations> does to its
		// variations, their photos or their sales, and none has been sent
		// one live.
		if ( $product->is_type( 'variable' ) ) {
			return self::verdict( 'left', 'variable' );
		}

		$exporter = TCGiant_Sync_Exporter::instance();
		$settings = $exporter->resend_settings( $product );

		if ( self::is_auction( $product, $settings ) ) {
			return self::verdict( 'left', 'auction' );
		}
		if ( TCGiant_Sync_Listing_Link::find_products_sharing_item_id( $product_id, $item_id ) ) {
			return self::verdict( 'left', 'shared' );
		}

		// eBay refused its re-send: on every day it was tried, or for an
		// ended listing, it needs attention; today, it waits for tomorrow,
		// so neither Test on 5 nor Re-send all started again sends it twice.
		$refusal = get_post_meta( $product_id, TCGiant_Sync_Exporter::RESEND_FAILED_META, true );
		if ( is_array( $refusal ) && (string) ( $refusal['item'] ?? '' ) === $item_id ) {
			if ( ! empty( $refusal['attention'] ) ) {
				return self::verdict( 'left', 'attention' );
			}
			if ( gmdate( 'Y-m-d' ) === (string) ( $refusal['day'] ?? '' ) ) {
				return self::verdict( 'left', 'refused_today' );
			}
		}
		if ( $sending && self::push_under_way( $product_id ) ) {
			return self::verdict( 'left', 'pushing' );
		}

		// A description that cannot be made - a shortcode that stops with an
		// error, say - is counted under its reason, and the count goes on.
		$missed = 0;
		try {
			$render = self::render( $exporter, $product, $settings, $missed );
		} catch ( Throwable $e ) {
			return self::verdict( 'left', 'render_failed' );
		}

		// eBay's item specifics for its category are not kept on this site
		// just now, so the description is not the one a push would make: it
		// cannot be compared with what was sent, and waits until they are.
		// Before the comparisons, which would read it as changed.
		if ( $missed > 0 ) {
			return self::verdict( 'left', 'cache_cold', $missed );
		}

		$sent = (string) $product->get_meta( '_tcgiant_desc_hash' );
		if ( '' !== $sent && $sent === (string) $render['hash'] && (string) $product->get_meta( '_tcgiant_tpl_item' ) === $item_id ) {
			return self::verdict( 'unchanged', '', $missed );
		}

		$sent_at = (int) $product->get_meta( '_tcgiant_desc_sent_at' );
		if ( $sent_at > time() - self::RECENT_SECONDS ) {
			return self::verdict( 'left', 'recent', $missed );
		}
		if ( (int) $render['chars'] > self::MAX_CHARS ) {
			return self::verdict( 'left', 'too_long', $missed );
		}
		if ( ! empty( $render['nested'] ) ) {
			return self::verdict( 'left', 'nested', $missed );
		}
		if ( self::push_refuses( $exporter, $product, $settings, $missed ) ) {
			return self::verdict( 'left', 'refused', $missed );
		}

		// Held for a push, whose reason says why; one at quantity 0 needs
		// restocking first, as a push refuses it until then.
		$held = self::title_differs( $exporter, $product ) ? 'title_differs' : self::product_changed( $product, $render, $item_id );
		if ( '' !== $held ) {
			return self::verdict( 'left', self::out_of_stock( $product ) ? 'held_empty' : $held, $missed );
		}

		// A shortcode that shows a date, a counter or a random pick makes a
		// new description every time: sent, it would look changed again.
		try {
			$again = self::render( $exporter, $product, $settings );
		} catch ( Throwable $e ) {
			return self::verdict( 'left', 'render_failed', $missed );
		}
		if ( $again['hash'] !== $render['hash'] ) {
			return self::verdict( 'left', 'unstable', $missed );
		}

		// The same for the check of eBay's required item specifics (a
		// listing going back to the plain description, whose render reads
		// none): it could not be made, so it waits.
		if ( $missed > 0 ) {
			return self::verdict( 'left', 'cache_cold', $missed );
		}

		$verdict             = self::verdict( 'send', '', $missed );
		$verdict['group']    = ! empty( $render['none'] ) ? self::NONE_GROUP : 't' . (int) $render['template_id'];
		$verdict['last']     = self::out_of_stock( $product );
		$verdict['product']  = $product;
		$verdict['settings'] = $settings;
		$verdict['render']   = $render;
		$verdict['item_id']  = $item_id;
		$verdict['fixed']    = $exporter->uses_fixed_price_calls( $product, $settings, $item_id );

		return $verdict;
	}

	/**
	 * A verdict.
	 *
	 * @param string $verdict skip, unchanged, left or send.
	 * @param string $reason  Why it is left out.
	 * @param int    $missed  Look-ups the render found nothing kept for.
	 * @return array
	 */
	private static function verdict( $verdict, $reason = '', $missed = 0 ) {
		return array(
			'verdict' => $verdict,
			'reason'  => $reason,
			'group'   => '',
			'last'    => false,
			'missed'  => (int) $missed,
		);
	}

	/**
	 * The description the next push would send, asking eBay nothing.
	 *
	 * @param TCGiant_Sync_Exporter $exporter Exporter.
	 * @param WC_Product            $product  Product.
	 * @param array                 $settings TCGiant_Sync_Exporter::resend_settings().
	 * @param int                   $missed   Set to the look-ups found not kept.
	 * @return array
	 */
	private static function render( $exporter, WC_Product $product, array $settings, &$missed = 0 ) {
		return TCGiant_Sync_API::instance()->cached_only( function () use ( $exporter, $product, $settings ) {
			return $exporter->resend_description( $product, $settings );
		}, $missed );
	}

	/**
	 * Whether the listing is an auction, which is left to its next push:
	 * eBay restricts what may be revised once it has bids or within its last
	 * twelve hours. What the listing is, as the push reads it; with nothing
	 * recorded, the format the product would be pushed as.
	 *
	 * @param WC_Product $product  Product.
	 * @param array      $settings Its settings.
	 * @return bool
	 */
	private static function is_auction( WC_Product $product, array $settings ) {
		$type = (string) $product->get_meta( '_ebay_listing_type' );
		if ( '' !== $type ) {
			return 'Chinese' === $type;
		}
		return 'Chinese' === (string) ( $settings['listing_type'] ?? '' );
	}

	/**
	 * Whether eBay refused this listing's re-send on every day it was tried.
	 *
	 * @param int    $product_id Product.
	 * @param string $item_id    Its listing.
	 * @return bool
	 */
	private static function needs_attention( $product_id, $item_id ) {
		$record = get_post_meta( $product_id, TCGiant_Sync_Exporter::RESEND_FAILED_META, true );
		return is_array( $record ) && (string) ( $record['item'] ?? '' ) === (string) $item_id && ! empty( $record['attention'] );
	}

	/**
	 * Whether a push of the product is running, or waiting in the queue. It
	 * sends the template itself, and the whole listing beside it.
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	private static function push_under_way( $product_id ) {
		if ( 'pushing' === TCGiant_Sync_Exporter::push_progress( $product_id ) ) {
			return true;
		}
		return function_exists( 'as_next_scheduled_action' )
			&& false !== as_next_scheduled_action( TCGiant_Sync_Exporter::PUSH_ACTION, array( 'product_id' => (int) $product_id ), 'tcgiant_sync_group' );
	}

	/**
	 * Whether a push of the product would be refused before eBay, by the
	 * checks a push of a live listing makes that need no call: its settings
	 * (validate_export_settings()), a format the live listing cannot change
	 * to, eBay's required item specifics as this site keeps them, and a price
	 * below the lowest the plugin sends. Not the photo and identifier checks,
	 * which are a new listing's, nor stock: a listing at quantity 0 is sent,
	 * last.
	 *
	 * @param TCGiant_Sync_Exporter $exporter Exporter.
	 * @param WC_Product            $product  Product.
	 * @param array                 $settings Its settings.
	 * @param int                   $missed   Look-ups found not kept, added to.
	 * @return bool
	 */
	private static function push_refuses( $exporter, WC_Product $product, array $settings, &$missed = 0 ) {
		if ( is_wp_error( $exporter->validate_export_settings( $settings, false ) ) ) {
			return true;
		}

		// As do_push(): a live listing whose format the product asks to change.
		$live   = (string) $product->get_meta( '_ebay_listing_type' );
		$wanted = (string) $product->get_meta( '_ebay_export_listing_type' );
		if ( 'Active' === (string) $product->get_meta( '_ebay_listing_status' ) && '' !== $live && '' !== $wanted && $live !== $wanted ) {
			return true;
		}

		if ( TCGiant_Sync_Exporter::checks_aspects( $settings, true ) ) {
			$cold    = 0;
			$missing = TCGiant_Sync_API::instance()->cached_only( function () use ( $exporter, $product, $settings ) {
				return $exporter->find_missing_required_aspects( $product, $settings, true );
			}, $cold );
			$missed += (int) $cold;
			if ( ! empty( $missing ) ) {
				return true;
			}
		}

		return (bool) TCGiant_Sync_Ebay_Price::floor_refusal( $product, TCGiant_Sync_Ebay_Price::for_product( $product ) );
	}

	/**
	 * Whether the title the next push would send is not the one the Listings
	 * table has for the listing (ebay_title, which the sync and the push
	 * write): a title edited on the website and not pushed, or edited on
	 * eBay's Seller Hub. Only a push sends a title, so a description that
	 * names it waits for that push. No title on record: held too.
	 *
	 * Compared as eBay keeps a title: no angle brackets, at most 80
	 * characters, entities read and runs of spaces as one.
	 *
	 * @param TCGiant_Sync_Exporter $exporter Exporter.
	 * @param WC_Product            $product  Product.
	 * @return bool
	 */
	private static function title_differs( $exporter, WC_Product $product ) {
		$row  = class_exists( 'TCGiant_Sync_DB' ) ? TCGiant_Sync_DB::get_by_product( $product->get_id() ) : null;
		$ebay = is_array( $row ) ? (string) ( $row['ebay_title'] ?? '' ) : '';
		if ( '' === trim( $ebay ) ) {
			return true;
		}

		$plain = function ( $title ) {
			$title = html_entity_decode( (string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $title ) );
		};
		return $plain( $exporter->title_sent( $product->get_name() ) ) !== $plain( $exporter->title_sent( $ebay ) );
	}

	/**
	 * Why the product is held for a full push, never sent a description
	 * alone, or '' when it is not: it has changed since its listing was last
	 * sent, or nothing says when that was. When in doubt, held.
	 *
	 * - Last sent with a template, to this listing, and rendered with one
	 *   now: the content hash recorded then against this render's. Both are
	 *   made from the product's own facts alone (TCGiant_Sync_Templates::
	 *   content_hash()), so a new template, version or colour does not hold
	 *   it back: 'changed' when they differ.
	 * - Otherwise - last sent the plain description, whose content hash is
	 *   made differently; sent before 3.34.0, or for another listing, whose
	 *   hash cannot be used; going back to the plain description; or never
	 *   sent from here: date_rule().
	 *
	 * @param WC_Product $product Product.
	 * @param array      $render  This render.
	 * @param string     $item_id The listing it would be sent to.
	 * @return string '', changed, held or never_pushed.
	 */
	private static function product_changed( WC_Product $product, array $render, $item_id ) {
		$recorded = (string) $product->get_meta( '_tcgiant_content_hash' );
		if ( empty( $render['none'] )
			&& (int) $product->get_meta( '_tcgiant_tpl_id' ) > 0
			&& 0 === strpos( $recorded, TCGiant_Sync_Templates::CONTENT_HASH_PREFIX )
			&& (string) $product->get_meta( '_tcgiant_tpl_item' ) === (string) $item_id ) {
			return $recorded !== (string) $render['content_hash'] ? 'changed' : '';
		}

		return self::date_rule( $product );
	}

	/**
	 * The rule for a listing with no content hash to compare (design section
	 * 7): the product counts as unchanged only when its date_modified is not
	 * later than its last push (_ebay_export_last_pushed), with
	 * MODIFIED_SLACK seconds for the push's own save, a moment after it
	 * records the time. Both are the site's own clock (current_time(
	 * 'mysql' )), compared with the product's date in the same.
	 *
	 * WooCommerce moves date_modified on every save of a product, its stock
	 * included (wc_update_product_stock() saves it), so a listing that sold
	 * since its last push is held: 'held'. That is the safe side.
	 *
	 * With no push on record - a listing only ever imported, or one whose
	 * date the sync wrote when it linked the listing an unfinished push
	 * made (TCGiant_Sync_Listing_Link::LINK_DATED_META) - nothing says when
	 * the product last matched eBay: 'never_pushed'. The Listings table's
	 * last_synced does not: every sync and every renewal of a listing
	 * writes it, whatever the website's product says.
	 *
	 * @param WC_Product $product Product.
	 * @return string '', held or never_pushed.
	 */
	private static function date_rule( WC_Product $product ) {
		$at = (string) $product->get_meta( '_ebay_export_last_pushed' );
		if ( '' === $at || $at === (string) $product->get_meta( TCGiant_Sync_Listing_Link::LINK_DATED_META ) ) {
			return 'never_pushed';
		}

		$modified = $product->get_date_modified();
		$then     = strtotime( $at . ' UTC' );
		if ( ! $modified || false === $then ) {
			return 'held';
		}

		return (int) $modified->getOffsetTimestamp() <= $then + self::MODIFIED_SLACK ? '' : 'held';
	}

	/**
	 * Whether the listing is kept alive at quantity 0 by eBay's Out-of-Stock
	 * Control: buyers cannot see it, so it goes last. A push refuses such a
	 * product until it is restocked.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private static function out_of_stock( WC_Product $product ) {
		return $product->managing_stock() && (int) $product->get_stock_quantity() < 1;
	}

	// -------------------------------------------------------------------------
	// When it may send
	// -------------------------------------------------------------------------

	/**
	 * Why nothing may be sent now, and when to look again, or null.
	 *
	 * - a staging copy: the API refuses every Revise there;
	 * - not connected to eBay;
	 * - every shop's figure from the relay missing, or over two hours old
	 *   (TCGiant_Sync_API::get_relay_budget()), so a burst cannot hide behind
	 *   a stale one;
	 * - the day's budget spent (is_daily_budget_exhausted());
	 * - every shop together with under FLEET_FLOOR of its ceiling left, this
	 *   site's own calls since the relay's figure included
	 *   (get_remaining_daily_budget(), through fleet_calls_now());
	 * - this site at OWN_SHARE or more of its own allowance today, which
	 *   keeps room for stock and orders.
	 *
	 * The last three last the UTC day: it tries again the next.
	 *
	 * @return array{why: string, until: int}|null
	 */
	public static function pause() {
		$now = time();

		if ( TCGiant_Sync_Environment::write_block() ) {
			return array( 'why' => 'staging', 'until' => $now + HOUR_IN_SECONDS );
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
		if ( $own <= 0 ) {
			self::note_no_cap();
		} elseif ( $api->get_daily_call_count() >= $own * self::OWN_SHARE ) {
			return array( 'why' => 'own_half', 'until' => self::next_day() );
		}

		return null;
	}

	/**
	 * Say once a UTC day that the relay's figure gave no allowance of this
	 * site's own, so the half-allowance pause cannot hold re-sends back:
	 * only the bulk-push limit, the two-hour window and every shop's
	 * quarter do.
	 */
	private static function note_no_cap() {
		$today = gmdate( 'Y-m-d' );
		if ( get_transient( self::NO_CAP_TRANSIENT ) === $today ) {
			return;
		}
		set_transient( self::NO_CAP_TRANSIENT, $today, DAY_IN_SECONDS );
		TCGiant_Sync_Logger::warning( 'Re-send: the relay\'s figure for today says nothing of this site\'s own eBay allowance, so re-sends are held back by the day\'s bulk-push limit, the two-hour window and every shop\'s quarter left, not by half of this site\'s allowance. This is said once a day.' );
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
	 * Re-sends allowed in any two hours: a twelfth of the day's bulk-push
	 * limit, so no stale budget figure lets a burst through.
	 *
	 * @return int
	 */
	public static function window_cap() {
		return max( 1, (int) floor( TCGiant_Sync_Exporter::bulk_push_cap() / self::WINDOW_SHARE ) );
	}

	/**
	 * Re-sends in the last two hours. A minute's sends count until two hours
	 * after the minute's end, so none leaves the window early.
	 *
	 * @param int $now Now.
	 * @return int
	 */
	public static function window_used( $now ) {
		$used = 0;
		foreach ( self::read( self::WINDOW_OPTION ) as $minute => $n ) {
			if ( (int) $minute + 60 + self::WINDOW_SECONDS > $now ) {
				$used += (int) $n;
			}
		}
		return $used;
	}

	/**
	 * When the oldest re-send still in the window leaves it.
	 *
	 * @param int $now Now.
	 * @return int
	 */
	private static function window_frees( $now ) {
		$first = null;
		foreach ( self::read( self::WINDOW_OPTION ) as $minute => $n ) {
			if ( (int) $n > 0 && (int) $minute + 60 + self::WINDOW_SECONDS > $now && ( null === $first || (int) $minute < $first ) ) {
				$first = (int) $minute;
			}
		}
		return null === $first ? $now + 60 : $first + 60 + self::WINDOW_SECONDS;
	}

	/**
	 * Count a re-send in the window.
	 *
	 * @param int $now Now.
	 */
	private static function window_note( $now ) {
		$window = array();
		foreach ( self::read( self::WINDOW_OPTION ) as $minute => $n ) {
			if ( (int) $minute + 60 + self::WINDOW_SECONDS > $now ) {
				$window[ (int) $minute ] = (int) $n;
			}
		}
		$minute            = $now - ( $now % 60 );
		$window[ $minute ] = (int) ( $window[ $minute ] ?? 0 ) + 1;
		self::write( self::WINDOW_OPTION, $window );
	}

	// -------------------------------------------------------------------------
	// Sending one
	// -------------------------------------------------------------------------

	/**
	 * Send one listing its description: one Revise call, carrying only its
	 * ItemID and Description, through the call the push would use.
	 *
	 * On success - Ack Success or Warning - the description is recorded as
	 * a push records one, and one line logged, with eBay's warnings in its
	 * words. On a refusal the record stays, so the listing stays changed;
	 * eBay's words are logged and kept, and it is tried again on a later day
	 * (failed()). A call that did not get an answer about the listing - out
	 * of calls, no token, eBay not reached - is not a refusal: the run
	 * pauses, and the listing goes again. Unless eBay was not reached for
	 * this listing, or its answer could not be read (NO_ANSWER_CODES),
	 * NO_ANSWER_LIMIT times in a row: then it is taken as refused, so the
	 * run moves on. eBay's own limit on calls never counts.
	 *
	 * @param array $verdict judge()'s, to send.
	 * @param bool  $retry   Keep a refusal for Re-send all to try again on a
	 *                       later day. Test on 5's are tried again when the
	 *                       group is next sent.
	 * @return array ok, and message (eBay's warnings, when it took it); or
	 *               pause, with nothing said of the listing.
	 */
	private static function send( array $verdict, $retry = true ) {
		$product_id = (int) $verdict['product']->get_id();
		$item_id    = (string) $verdict['item_id'];
		$render     = $verdict['render'];
		$fixed      = (bool) $verdict['fixed'];

		$xml = '<Item>' . "\n" . '<ItemID>' . esc_attr( $item_id ) . '</ItemID>' . "\n" . TCGiant_Sync_Exporter::description_xml( $render['html'] ) . "\n" . '</Item>';
		$api = TCGiant_Sync_API::instance();

		$response = TCGiant_Sync_Exporter::on_listing_site( $verdict['settings'], function () use ( $api, $fixed, $xml ) {
			return $fixed ? $api->revise_fixed_price_item( $xml ) : $api->revise_item( $xml );
		}, 'revise', sprintf( 'eBay listing %1$s of WC #%2$d', $item_id, $product_id ), $product_id );

		$ack = is_array( $response ) ? (string) TCGiant_Sync_API::scalar_value( $response['Ack'] ?? '' ) : '';
		if ( ! is_wp_error( $response ) && in_array( $ack, array( 'Success', 'Warning' ), true ) ) {
			$before   = (string) get_post_meta( $product_id, '_tcgiant_desc_hash', true );
			$warnings = implode( ' | ', self::warnings_of( $response ) );
			TCGiant_Sync_Exporter::record_resend( $product_id, $render, $item_id );
			TCGiant_Sync_Logger::log( sprintf(
				'Re-send: eBay listing %1$s of WC #%2$d was sent %3$s, description only (%4$s): description %5$s -> %6$s, 1 call.%7$s',
				$item_id,
				$product_id,
				! empty( $render['none'] ) ? 'the plain description' : sprintf( 'template "%s" version %d', (string) ( $render['name'] ?? '' ), (int) $render['version'] ),
				$fixed ? 'ReviseFixedPriceItem' : 'ReviseItem',
				'' !== $before ? substr( $before, 0, 12 ) : 'none recorded',
				substr( (string) $render['hash'], 0, 12 ),
				'' !== $warnings ? ' eBay took it with a warning: ' . $warnings : ''
			), '' !== $warnings ? 'warning' : 'success' );
			return array( 'ok' => true, 'message' => $warnings );
		}

		if ( is_wp_error( $response ) && 'staging_blocked' === $response->get_error_code() ) {
			return array( 'ok' => false, 'pause' => array( 'why' => 'staging', 'until' => time() + HOUR_IN_SECONDS ) );
		}
		if ( is_wp_error( $response ) && TCGiant_Sync_API::is_transient_error( $response ) ) {
			$data = $response->get_error_data();
			if ( 'no_token' === $response->get_error_code() ) {
				$pause = array( 'why' => 'not_connected', 'until' => time() + HOUR_IN_SECONDS );
			} elseif ( is_array( $data ) && ! empty( $data['daily_budget'] ) ) {
				$pause = array( 'why' => 'budget', 'until' => self::next_day() );
			} elseif ( in_array( $response->get_error_code(), self::NO_ANSWER_CODES, true ) ) {
				// eBay not reached, or no answer it could read, for this
				// listing: the third time in a row is taken as a refusal.
				$streak = self::no_answer( $product_id, $item_id );
				if ( $streak >= self::NO_ANSWER_LIMIT ) {
					$message = sprintf(
						/* translators: 1: times in a row, 2: what came back */
						__( 'eBay gave no answer about it %1$d times in a row: %2$s', 'tcgiant-sync' ),
						$streak,
						$response->get_error_message()
					);
					self::failed( $product_id, $item_id, $message, false, $retry );
					return array( 'ok' => false, 'message' => $message );
				}
				$pause = array( 'why' => 'unreachable', 'until' => time() + 30 * MINUTE_IN_SECONDS );
			} else {
				// eBay's own limit on calls (HTTP 429, or its 518 and 10007
				// without the plugin's daily-budget mark): about every call,
				// not this listing, so not counted against it.
				$pause = array( 'why' => 'unreachable', 'until' => time() + 30 * MINUTE_IN_SECONDS );
			}
			TCGiant_Sync_Logger::warning( sprintf(
				'Re-send: eBay listing %1$s of WC #%2$d was not sent a description: %3$s. It goes again when the re-send carries on.',
				$item_id,
				$product_id,
				$response->get_error_message()
			) );
			return array( 'ok' => false, 'pause' => $pause );
		}

		$message = is_wp_error( $response )
			? $response->get_error_message()
			/* translators: %s: eBay's Ack */
			: sprintf( __( 'eBay did not say it took the description (Ack: %s).', 'tcgiant-sync' ), '' !== $ack ? $ack : __( 'none', 'tcgiant-sync' ) );
		self::failed( $product_id, $item_id, $message, is_wp_error( $response ) && TCGiant_Sync_Exporter::is_ended_listing_error( $response ), $retry );

		return array( 'ok' => false, 'message' => $message );
	}

	/**
	 * Keep a refusal: the listing is tried again on a later day, up to
	 * RETRY_DAYS of them, then needs attention, with eBay's words. One eBay
	 * says has ended needs attention at once: no later day changes that,
	 * and the next sync marks it ended.
	 *
	 * @param int    $product_id Product.
	 * @param string $item_id    Its listing.
	 * @param string $message    eBay's words.
	 * @param bool   $final      Needs attention now.
	 * @param bool   $retry      Put it on Re-send all's list for a later day.
	 */
	private static function failed( $product_id, $item_id, $message, $final = false, $retry = true ) {
		$today  = gmdate( 'Y-m-d' );
		$record = get_post_meta( $product_id, TCGiant_Sync_Exporter::RESEND_FAILED_META, true );
		if ( ! is_array( $record ) || (string) ( $record['item'] ?? '' ) !== (string) $item_id ) {
			$record = array( 'item' => (string) $item_id, 'tries' => 0, 'day' => '' );
		}
		if ( $today !== (string) ( $record['day'] ?? '' ) ) {
			$record['tries'] = (int) ( $record['tries'] ?? 0 ) + 1;
		}
		$record['day']       = $today;
		$record['message']   = (string) $message;
		$record['attention'] = $final || (int) $record['tries'] > self::RETRY_DAYS;
		$record['no_answer'] = 0;
		update_post_meta( $product_id, TCGiant_Sync_Exporter::RESEND_FAILED_META, $record );

		if ( $retry && ! $record['attention'] ) {
			$list                = self::read( self::RETRY_OPTION );
			$list[ $product_id ] = $today;
			self::write( self::RETRY_OPTION, $list );
		}

		TCGiant_Sync_Logger::warning( sprintf(
			'Re-send: eBay refused the description of listing %1$s of WC #%2$d: %3$s %4$s',
			$item_id,
			(int) $product_id,
			(string) $message,
			$record['attention']
				? 'It needs attention: it is not tried again until a push or a re-send of it succeeds.'
				: sprintf( 'Its description on eBay is as it was. That was day %1$d of %2$d it is tried on; it goes again on a later day.', (int) $record['tries'], self::RETRY_DAYS + 1 )
		) );
	}

	/**
	 * Count one more call for a listing that came back with no answer about
	 * it, and say how many in a row. A refusal (failed()) starts the count
	 * again, and a send or push that eBay takes deletes the record.
	 *
	 * @param int    $product_id Product.
	 * @param string $item_id    Its listing.
	 * @return int
	 */
	private static function no_answer( $product_id, $item_id ) {
		$record = get_post_meta( $product_id, TCGiant_Sync_Exporter::RESEND_FAILED_META, true );
		if ( ! is_array( $record ) || (string) ( $record['item'] ?? '' ) !== (string) $item_id ) {
			$record = array( 'item' => (string) $item_id, 'tries' => 0, 'day' => '' );
		}
		$record['no_answer'] = (int) ( $record['no_answer'] ?? 0 ) + 1;
		update_post_meta( $product_id, TCGiant_Sync_Exporter::RESEND_FAILED_META, $record );
		return (int) $record['no_answer'];
	}

	/**
	 * eBay's warnings in an answer it took: each one's LongMessage, or its
	 * ShortMessage when it has none.
	 *
	 * @param mixed $response eBay's answer.
	 * @return string[]
	 */
	private static function warnings_of( $response ) {
		$errors = is_array( $response ) && isset( $response['Errors'] ) && is_array( $response['Errors'] ) ? $response['Errors'] : array();
		if ( isset( $errors['ErrorCode'] ) || isset( $errors['ShortMessage'] ) || isset( $errors['LongMessage'] ) || isset( $errors['SeverityCode'] ) ) {
			$errors = array( $errors );
		}

		$out = array();
		foreach ( $errors as $one ) {
			if ( ! is_array( $one ) || 'Warning' !== TCGiant_Sync_API::scalar_value( $one['SeverityCode'] ?? '' ) ) {
				continue;
			}
			$long = trim( (string) TCGiant_Sync_API::scalar_value( $one['LongMessage'] ?? '' ) );
			$out[] = '' !== $long ? $long : trim( (string) TCGiant_Sync_API::scalar_value( $one['ShortMessage'] ?? '' ) );
		}
		return array_values( array_unique( array_filter( $out, function ( $words ) {
			return '' !== $words;
		} ) ) );
	}

	// -------------------------------------------------------------------------
	// Test on 5
	// -------------------------------------------------------------------------

	/**
	 * Test on 5: five listings of a group, sent now, each with its outcome.
	 *
	 * Under every check Re-send all is under - the budget (pause()) and the
	 * day's bulk-push limit, which it takes from - and counted in the
	 * two-hour window. A test in which every listing came back OK unlocks
	 * Re-send all for the template as it is now (unlocked()).
	 *
	 * @param string $group Group key.
	 * @return array ok, message, rows.
	 */
	public static function test( $group ) {
		$group   = (string) $group;
		$refusal = self::refuse( $group, false );
		if ( '' !== $refusal ) {
			return array( 'ok' => false, 'message' => $refusal, 'rows' => array() );
		}

		if ( ! self::take_lock( 'send', self::LOCK_SECONDS ) ) {
			return array( 'ok' => false, 'message' => __( 'A re-send is going to eBay right now. Try again in a minute.', 'tcgiant-sync' ), 'rows' => array() );
		}

		try {
			$pause = self::pause();
			if ( $pause ) {
				return array( 'ok' => false, 'message' => self::pause_words( $pause, false ), 'rows' => array() );
			}

			$picked = array();
			$looked = 0;
			foreach ( self::members( $group, self::TEST_LOOK ) as $id ) {
				if ( count( $picked ) >= self::TEST_SIZE || $looked >= self::TEST_LOOK ) {
					break;
				}
				$looked++;
				$verdict = self::judge( $id, true );
				if ( 'send' === $verdict['verdict'] && $group === $verdict['group'] ) {
					$picked[] = $verdict;
				}
			}
			if ( ! $picked ) {
				return array( 'ok' => false, 'message' => __( 'None of these listings can be sent now: each has changed since the count, or is left out for a reason it shows. Press Count again.', 'tcgiant-sync' ), 'rows' => array() );
			}

			$day   = gmdate( 'Y-m-d' );
			$taken = TCGiant_Sync_Exporter::take_bulk_push_allowance( count( $picked ) );
			if ( $taken < count( $picked ) ) {
				TCGiant_Sync_Exporter::give_back_bulk_push_allowance( $taken, $day );
				return array( 'ok' => false, 'message' => sprintf(
					/* translators: 1: the test's size, 2: the line about today's bulk pushes */
					__( 'Today\'s bulk-push limit has no room for %1$d more. %2$s. It resets at midnight UTC.', 'tcgiant-sync' ),
					count( $picked ),
					TCGiant_Sync_Exporter::bulk_push_line()
				), 'rows' => array() );
			}

			$rows = array();
			$sent = 0;
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			// Once anything pauses it - the budget, or a call that got no
			// answer about its listing - the rest are not sent.
			$pause = null;
			foreach ( $picked as $verdict ) {
				$row = array(
					'product_id' => (int) $verdict['product']->get_id(),
					'name'       => (string) $verdict['product']->get_name(),
					'item_id'    => (string) $verdict['item_id'],
					'ok'         => false,
					'message'    => '',
				);
				if ( ! $pause ) {
					$pause = self::pause();
				}
				if ( ! $pause ) {
					$result = self::send( $verdict, false );
					$sent++;
					self::window_note( time() );
					$pause = $result['pause'] ?? null;
					if ( ! $pause ) {
						$row['ok']      = (bool) $result['ok'];
						$row['message'] = (string) $result['message'];
					}
				}
				if ( $pause ) {
					$row['message'] = __( 'Not sent.', 'tcgiant-sync' ) . ' ' . self::pause_words( $pause, false );
				}
				$rows[] = $row;
			}
			TCGiant_Sync_Exporter::give_back_bulk_push_allowance( $taken - $sent, $day );

			$clean = true;
			foreach ( $rows as $row ) {
				$clean = $clean && $row['ok'];
			}

			$tests           = self::read( self::TESTS_OPTION );
			$tests[ $group ] = array_merge( self::lock_key( $group ), array(
				'at'    => time(),
				'clean' => $clean,
				'rows'  => $rows,
			) );
			self::write( self::TESTS_OPTION, $tests );

			$ok = 0;
			foreach ( $rows as $row ) {
				$ok += $row['ok'] ? 1 : 0;
			}
			TCGiant_Sync_Logger::log( sprintf(
				'Re-send: Test on 5 for %1$s sent %2$d listing(s): %3$d OK.%4$s',
				self::group_name( $group ),
				count( $rows ),
				$ok,
				$clean ? ' Re-send all is unlocked for it.' : ''
			), $clean ? 'success' : 'warning' );

			return array(
				'ok'      => $clean,
				'message' => $clean
					/* translators: %d: listings */
					? sprintf( _n( 'Test sent: %d listing came back OK. Check it on eBay; Re-send all is now unlocked for this template.', 'Test sent: all %d listings came back OK. Check them on eBay; Re-send all is now unlocked for this template.', count( $rows ), 'tcgiant-sync' ), count( $rows ) )
					/* translators: 1: listings OK, 2: listings tried */
					: sprintf( __( 'Test sent: %1$d of %2$d came back OK. Re-send all stays locked until a test comes back clean; eBay\'s words are below.', 'tcgiant-sync' ), $ok, count( $rows ) ),
				'rows'    => $rows,
			);
		} finally {
			self::release_lock( 'send' );
		}
	}

	/**
	 * What a group's test is of: the template, its version and its hash
	 * (which a block saved changes too). The plain description has none.
	 *
	 * @param string $group Group key.
	 * @return array template_id, version, tpl_hash.
	 */
	private static function lock_key( $group ) {
		if ( self::NONE_GROUP === $group ) {
			return array( 'template_id' => 0, 'version' => 0, 'tpl_hash' => '' );
		}
		$tpl = TCGiant_Sync_Templates::get( (int) substr( (string) $group, 1 ) );
		return array(
			'template_id' => $tpl ? (int) $tpl['id'] : -1,
			'version'     => $tpl ? (int) $tpl['version'] : -1,
			'tpl_hash'    => $tpl ? (string) $tpl['hash'] : '',
		);
	}

	/**
	 * Whether Re-send all is unlocked for a group: its last Test on 5 came
	 * back clean, for the template as it is now.
	 *
	 * @param string $group Group key.
	 * @return bool
	 */
	public static function unlocked( $group ) {
		$test = self::read( self::TESTS_OPTION )[ (string) $group ] ?? null;
		if ( ! is_array( $test ) || empty( $test['clean'] ) ) {
			return false;
		}
		$key = self::lock_key( (string) $group );
		return (int) $key['template_id'] === (int) ( $test['template_id'] ?? -2 )
			&& (int) $key['version'] === (int) ( $test['version'] ?? -2 )
			&& (string) $key['tpl_hash'] === (string) ( $test['tpl_hash'] ?? '' )
			&& -1 !== (int) $key['template_id'];
	}

	/**
	 * Why Test on 5 or Re-send all cannot start for a group, or ''.
	 *
	 * @param string $group Group key.
	 * @param bool   $all   Re-send all, which needs a clean test.
	 * @return string
	 */
	private static function refuse( $group, $all ) {
		$blocked = TCGiant_Sync_Environment::write_block();
		if ( $blocked ) {
			return $blocked->get_error_message();
		}
		if ( ! TCGiant_Sync_OAuth::instance()->is_authenticated() ) {
			return __( 'This site is not connected to eBay. Connect it on the Settings screen first.', 'tcgiant-sync' );
		}

		$count = self::read( self::COUNT_OPTION );
		$done  = $count['done'] ?? null;
		if ( isset( $count['doing'] ) || self::stale( $count ) ) {
			return __( 'The count is being made again after your last change. The buttons work again once it is done; reload this page in a minute or two.', 'tcgiant-sync' );
		}
		if ( ! is_array( $done ) || empty( $done['groups'][ $group ]['count'] ) ) {
			return __( 'No listings of that group would change. Press Count again if you have changed something since.', 'tcgiant-sync' );
		}
		if ( self::running() ) {
			return __( 'A Re-send all is already going. Stop it first, or wait for it to finish.', 'tcgiant-sync' );
		}
		if ( $all && ! self::unlocked( $group ) ) {
			return __( 'Test on 5 first: Re-send all unlocks once a test of this template, as it is now, comes back clean.', 'tcgiant-sync' );
		}
		return '';
	}

	// -------------------------------------------------------------------------
	// Re-send all
	// -------------------------------------------------------------------------

	/**
	 * Re-send all: queue the group's listings, those at quantity 0 last, and
	 * start sending them in Action Scheduler.
	 *
	 * @param string $group Group key.
	 * @return array ok, message.
	 */
	public static function start( $group ) {
		$group   = (string) $group;
		$refusal = self::refuse( $group, true );
		if ( '' !== $refusal ) {
			return array( 'ok' => false, 'message' => $refusal );
		}

		$ids = self::members( $group );
		if ( ! $ids ) {
			return array( 'ok' => false, 'message' => __( 'No listings of that group would change. Press Count again if you have changed something since.', 'tcgiant-sync' ) );
		}

		self::forget_queue( self::state() );
		$chunks = 0;
		foreach ( array_chunk( $ids, self::QUEUE_CHUNK ) as $chunk ) {
			self::write( self::QUEUE_PREFIX . $chunks, $chunk );
			$chunks++;
		}
		delete_option( self::RETRY_OPTION );

		// The run's own ID: a batch of an earlier run, still part way when
		// this one started, sees it is no longer its run, and writes nothing.
		$key = self::lock_key( $group );
		self::save_state( array(
			'run'         => substr( md5( uniqid( '', true ) ), 0, 12 ),
			'status'      => 'running',
			'group'       => $group,
			'name'        => self::group_name( $group ),
			'template_id' => $key['template_id'],
			'version'     => $key['version'],
			'tpl_hash'    => $key['tpl_hash'],
			'started'     => time(),
			'total'       => count( $ids ),
			'chunks'      => $chunks,
			'cursor'      => 0,
			'sent'        => 0,
			'failed'      => 0,
			'attention'   => 0,
			'dropped'     => 0,
			'dropped_why' => array(),
			'pause'       => null,
			'retry_day'   => '',
		) );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::RUN_HOOK, null, self::GROUP );
		}
		self::later( time() );

		TCGiant_Sync_Logger::log( sprintf(
			'Re-send: Re-send all started for %1$s: %2$d listing(s), description only, 1 eBay call each, %3$d at most in any two hours, within the day\'s bulk-push limit.',
			self::group_name( $group ),
			count( $ids ),
			self::window_cap()
		) );

		return array(
			'ok'      => true,
			/* translators: %d: listings */
			'message' => sprintf( _n( 'Re-send all started: %d listing is queued. It goes in the background, a batch at a time; this box shows how far it has got.', 'Re-send all started: %d listings are queued. They go in the background, a batch at a time; this box shows how far it has got.', count( $ids ), 'tcgiant-sync' ), count( $ids ) ),
		);
	}

	/**
	 * Stop Re-send all: Stop on the screen, or Emergency Stop. A batch
	 * part way through reads this before each listing and ends there.
	 *
	 * @param string $why 'stop' or 'emergency'.
	 * @return bool Whether one was going.
	 */
	public static function stop( $why = 'stop' ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::RUN_HOOK, null, self::GROUP );
		}

		$state = self::state();
		if ( ! self::active( $state ) ) {
			return false;
		}
		$state['status']  = 'stopped';
		$state['end_why'] = 'emergency' === $why ? 'emergency' : 'stop';
		$state['ended']   = time();
		$state['pause']   = null;
		self::save_state( $state );

		TCGiant_Sync_Logger::log( sprintf(
			'Re-send: Re-send all for %1$s %2$s after %3$d sent; %4$d were still to send. Nothing more goes until it is started again.',
			(string) ( $state['name'] ?? '' ),
			'emergency' === $why ? 'ended by Emergency Stop' : 'stopped',
			(int) $state['sent'],
			max( 0, (int) $state['total'] - (int) $state['cursor'] )
		), 'warning' );
		return true;
	}

	/**
	 * One batch of Re-send all (Action Scheduler).
	 */
	public static function run_batch() {
		if ( ! self::take_lock( 'send', self::LOCK_SECONDS ) ) {
			// Test on 5, or another batch, is sending: never two at once.
			if ( self::active( self::state() ) && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 60, self::RUN_HOOK, array(), self::GROUP );
			}
			return;
		}

		try {
			self::batch();
		} finally {
			self::release_lock( 'send' );
		}
	}

	/**
	 * Send up to SEND_BATCH listings of the queue.
	 *
	 * Where it has got to is kept before each listing, so a batch the host
	 * ends part way loses nothing, and it stops early when this request has
	 * used the time the host gives it (TCGiant_Sync_Runtime::
	 * over_time_budget()). It writes only while the run it began with is the
	 * one in the table (its 'run'), and still going.
	 */
	private static function batch() {
		$state = self::state();
		if ( ! self::active( $state ) ) {
			return;
		}
		$run = (string) ( $state['run'] ?? '' );

		// The template as it was tested: a new save locks it again.
		if ( self::NONE_GROUP !== $state['group'] ) {
			$tpl = TCGiant_Sync_Templates::get( (int) $state['template_id'] );
			if ( ! $tpl || 'publish' !== $tpl['status'] ) {
				self::finish( $state, 'template_gone' );
				return;
			}
			if ( (int) $tpl['version'] !== (int) $state['version'] || (string) $tpl['hash'] !== (string) $state['tpl_hash'] ) {
				self::finish( $state, 'changed' );
				return;
			}
		}

		// The listings eBay refused, on a later day.
		if ( 'waiting_retry' === $state['status'] ) {
			if ( gmdate( 'Y-m-d' ) <= (string) $state['retry_day'] ) {
				self::later( self::next_day() );
				return;
			}
			$retry = array_keys( self::read( self::RETRY_OPTION ) );
			delete_option( self::RETRY_OPTION );
			if ( ! $retry ) {
				self::finish( $state, 'done' );
				return;
			}
			self::forget_queue( $state );
			$state['chunks'] = 0;
			foreach ( array_chunk( array_map( 'intval', $retry ), self::QUEUE_CHUNK ) as $chunk ) {
				self::write( self::QUEUE_PREFIX . $state['chunks'], $chunk );
				$state['chunks']++;
			}
			$state['total']  = count( $retry );
			$state['cursor'] = 0;
			$state['status'] = 'running';
			TCGiant_Sync_Logger::log( sprintf( 'Re-send: trying again the %d listing(s) eBay refused on an earlier day.', count( $retry ) ) );
		}

		$now   = time();
		$room  = 0;
		$pause = self::pause();
		if ( ! $pause ) {
			$room = self::window_cap() - self::window_used( $now );
			if ( $room <= 0 ) {
				$pause = array( 'why' => 'window', 'until' => self::window_frees( $now ) );
			}
		}
		if ( $pause ) {
			self::pause_run( $state, $pause );
			return;
		}

		$want  = min( self::SEND_BATCH, $room, max( 0, (int) $state['total'] - (int) $state['cursor'] ) );
		$day   = gmdate( 'Y-m-d' );
		$taken = $want > 0 ? TCGiant_Sync_Exporter::take_bulk_push_allowance( $want ) : 0;
		if ( $want > 0 && $taken <= 0 ) {
			self::pause_run( $state, array( 'why' => 'limit', 'until' => self::next_day() ) );
			return;
		}

		// Nothing in the way: it is running again, whatever it waited for.
		$state['status'] = 'running';
		$state['pause']  = null;

		$sent   = 0;
		$looked = 0;
		$pause  = null;
		$queue  = array();
		while ( $sent < $taken && $looked < self::LOOK_BATCH && (int) $state['cursor'] < (int) $state['total'] ) {
			// Where it has got to, kept; and Stop, Emergency Stop or a new
			// run, as the table has them now, end it here.
			if ( ! self::save_progress( $state, $run ) ) {
				break;
			}
			// Out of time: the next batch carries on. Always one listing.
			if ( $looked > 0 && '' !== (string) TCGiant_Sync_Runtime::over_time_budget() ) {
				break;
			}

			$at    = (int) $state['cursor'];
			$chunk = intdiv( $at, self::QUEUE_CHUNK );
			if ( ! isset( $queue[ $chunk ] ) ) {
				$queue = array( $chunk => array_values( (array) self::read( self::QUEUE_PREFIX . $chunk ) ) );
			}
			$id = (int) ( $queue[ $chunk ][ $at % self::QUEUE_CHUNK ] ?? 0 );
			$state['cursor']++;
			$looked++;

			$verdict = self::judge( $id, true );
			if ( 'send' !== $verdict['verdict'] || $verdict['group'] !== $state['group'] ) {
				$why = 'send' === $verdict['verdict'] ? 'moved' : ( 'left' === $verdict['verdict'] ? $verdict['reason'] : $verdict['verdict'] );
				$state['dropped']++;
				$state['dropped_why'][ $why ] = (int) ( $state['dropped_why'][ $why ] ?? 0 ) + 1;
				continue;
			}

			// The budget again, before each call.
			$pause = self::pause();
			if ( $pause ) {
				$state['cursor']--;
				break;
			}

			$result = self::send( $verdict );
			$sent++;
			self::window_note( time() );
			if ( ! empty( $result['pause'] ) ) {
				$pause = $result['pause'];
				$state['cursor']--;
				break;
			}
			if ( $result['ok'] ) {
				$state['sent']++;
			} else {
				$state['failed']++;
				if ( self::needs_attention( $id, $verdict['item_id'] ) ) {
					$state['attention']++;
				}
			}
		}
		TCGiant_Sync_Exporter::give_back_bulk_push_allowance( $taken - $sent, $day );

		// A new run started meanwhile: the state is its own, and this batch
		// writes nothing more, nor asks for another.
		$now_state = self::state();
		if ( (string) ( $now_state['run'] ?? '' ) !== $run ) {
			return;
		}

		// Stopped meanwhile: its figures, and the stop.
		if ( ! self::active( $now_state ) ) {
			$now_state = array_merge( $state, array_intersect_key( $now_state, array_flip( array( 'status', 'end_why', 'ended', 'pause' ) ) ) );
			self::save_state( $now_state );
			return;
		}

		if ( $pause ) {
			self::pause_run( $state, $pause );
			return;
		}

		$state['pause'] = null;
		if ( (int) $state['cursor'] < (int) $state['total'] ) {
			self::save_state( $state );
			self::later( time() + 30 );
			return;
		}

		// The queue is done: the refusals on a later day, or the end.
		if ( self::read( self::RETRY_OPTION ) ) {
			$state['status']    = 'waiting_retry';
			$state['retry_day'] = gmdate( 'Y-m-d' );
			self::save_state( $state );
			self::later( self::next_day() );
			return;
		}
		self::finish( $state, 'done' );
	}

	/**
	 * Keep where a batch has got to, while its run is the one in the table
	 * and still going.
	 *
	 * @param array  $state The batch's state.
	 * @param string $run   The run it began with.
	 * @return bool Whether it is: false for Stop, Emergency Stop or a new run.
	 */
	private static function save_progress( array $state, $run ) {
		$now = self::state();
		if ( ! self::active( $now ) || (string) ( $now['run'] ?? '' ) !== (string) $run ) {
			return false;
		}
		self::save_state( $state );
		return true;
	}

	/**
	 * Pause Re-send all, and look again then. Said once a reason.
	 *
	 * @param array $state Its state.
	 * @param array $pause why, until.
	 */
	private static function pause_run( array $state, array $pause ) {
		$was             = $state['pause']['why'] ?? '';
		$state['status'] = 'paused';
		$state['pause']  = $pause;
		self::save_state( $state );
		self::later( (int) $pause['until'] );

		if ( $was !== $pause['why'] ) {
			TCGiant_Sync_Logger::log( 'Re-send: paused. ' . self::pause_words( $pause, true ), 'info' );
		}
	}

	/**
	 * End Re-send all.
	 *
	 * @param array  $state Its state.
	 * @param string $why   done, changed (the template was saved again) or
	 *                      template_gone.
	 */
	private static function finish( array $state, $why ) {
		$state['status']  = 'done' === $why ? 'done' : 'stopped';
		$state['end_why'] = $why;
		$state['ended']   = time();
		$state['pause']   = null;
		self::save_state( $state );
		self::forget_queue( $state );
		delete_option( self::RETRY_OPTION );

		$words = array(
			'done'          => 'finished',
			'changed'       => 'ended: the template was saved again since its Test on 5, so it needs a new test',
			'template_gone' => 'ended: the template was deleted or set to Draft',
		);
		TCGiant_Sync_Logger::log( sprintf(
			'Re-send: Re-send all for %1$s %2$s. %3$d sent, %4$d refused by eBay (%5$d need attention), %6$d dropped because they no longer qualified.',
			(string) ( $state['name'] ?? '' ),
			$words[ $why ] ?? $why,
			(int) $state['sent'],
			(int) $state['failed'],
			(int) $state['attention'],
			(int) $state['dropped']
		), 'done' === $why ? 'success' : 'warning' );
	}

	/**
	 * Run the next batch then.
	 *
	 * @param int $when Unix time.
	 */
	private static function later( $when ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( max( time(), (int) $when ), self::RUN_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Delete Re-send all's queue.
	 *
	 * @param array $state Its state.
	 */
	private static function forget_queue( array $state ) {
		for ( $n = 0; $n < (int) ( $state['chunks'] ?? 0 ); $n++ ) {
			delete_option( self::QUEUE_PREFIX . $n );
		}
	}

	/**
	 * Write Re-send all's state, with when it last moved.
	 *
	 * @param array $state State.
	 */
	private static function save_state( array $state ) {
		$state['touched'] = time();
		self::write( self::STATE_OPTION, $state );
	}

	/**
	 * Carry on a Re-send all, or a count, whose batch died part way: nothing
	 * waits in the queue to carry it on, and it has not moved for longer
	 * than a hold lasts. And start a count a change asked for that none is
	 * making - its first batch died before it kept anything - so the box
	 * never says it is counting when nothing is. Asked whenever the
	 * Templates page is drawn.
	 */
	public static function maybe_resume() {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$state = self::state();
		if ( self::active( $state ) && false === as_next_scheduled_action( self::RUN_HOOK, null, self::GROUP ) && time() - (int) ( $state['touched'] ?? 0 ) > self::LOCK_SECONDS ) {
			TCGiant_Sync_Logger::log( 'Re-send: Re-send all had stopped part way with nothing to carry it on, so it carries on now.', 'warning' );
			as_schedule_single_action( time(), self::RUN_HOOK, array(), self::GROUP );
		}

		$count = self::read( self::COUNT_OPTION );
		if ( false !== as_next_scheduled_action( self::SCAN_HOOK, null, self::GROUP ) ) {
			return;
		}
		if ( isset( $count['doing'] ) ? time() - (int) ( $count['doing']['touched'] ?? 0 ) > self::LOCK_SECONDS : self::wanted( $count ) ) {
			as_schedule_single_action( time(), self::SCAN_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Re-send all's state, as the table has it now.
	 *
	 * @return array
	 */
	public static function state() {
		return self::read( self::STATE_OPTION );
	}

	/**
	 * Whether a state is of a Re-send all still going.
	 *
	 * @param array $state State.
	 * @return bool
	 */
	private static function active( array $state ) {
		return in_array( (string) ( $state['status'] ?? '' ), array( 'running', 'paused', 'waiting_retry' ), true );
	}

	/**
	 * Whether a Re-send all is going.
	 *
	 * @return bool
	 */
	public static function running() {
		return self::active( self::state() );
	}

	// -------------------------------------------------------------------------
	// Holds
	// -------------------------------------------------------------------------

	/**
	 * Hold a piece of this work for one request: one counts at a time, and
	 * one sends at a time - Test on 5 or a batch - so two never send the
	 * same listing.
	 *
	 * A plain INSERT is the hold, as the photo cleanup holds its work: the
	 * options table's unique name means it goes in for one request and fails
	 * for every other. add_option() will not do - it inserts with ON
	 * DUPLICATE KEY UPDATE, so two requests would both be told they had it.
	 * A hold past its time is a request that was killed, and is taken over
	 * by deleting exactly the value read. The row is read back before the
	 * hold counts, earliest first, for a table that has lost its unique key.
	 *
	 * @param string $what    count or send.
	 * @param int    $seconds How long the hold lasts at most.
	 * @return bool
	 */
	private static function take_lock( $what, $seconds ) {
		global $wpdb;

		$name  = self::LOCK_PREFIX . $what;
		$now   = time();
		$value = ( $now + (int) $seconds ) . '|' . wp_generate_uuid4();
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
				// Another request's row came first. This one goes back out.
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
	 * @param string $what count or send.
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

	// -------------------------------------------------------------------------
	// The screen
	// -------------------------------------------------------------------------

	/**
	 * What the Re-send to eBay box shows.
	 *
	 * @return array
	 */
	public static function screen() {
		self::maybe_resume();

		$count   = self::read( self::COUNT_OPTION );
		$done    = isset( $count['done'] ) && is_array( $count['done'] ) ? $count['done'] : null;
		$pending = function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( self::SCAN_HOOK, null, self::GROUP );
		$tests   = self::read( self::TESTS_OPTION );

		$groups = array();
		foreach ( (array) ( $done['groups'] ?? array() ) as $key => $group ) {
			$groups[ $key ] = array_merge( $group, array(
				'key'      => (string) $key,
				'label'    => self::group_name( (string) $key, (int) $group['version'] ),
				'test'     => is_array( $tests[ $key ] ?? null ) ? $tests[ $key ] : null,
				'unlocked' => self::unlocked( (string) $key ),
			) );
		}

		$state   = self::state();
		$blocked = TCGiant_Sync_Environment::write_block();

		return array(
			'done'      => $done,
			'counting'  => isset( $count['doing'] ) || $pending,
			'stale'     => self::stale( $count ),
			'groups'    => $groups,
			'reasons'   => (array) ( $done['reasons'] ?? array() ),
			'state'     => $state,
			'running'   => self::active( $state ),
			'retry'     => count( self::read( self::RETRY_OPTION ) ),
			'blocked'   => $blocked ? $blocked->get_error_message() : '',
			'connected' => TCGiant_Sync_OAuth::instance()->is_authenticated(),
			'bulk'      => TCGiant_Sync_Exporter::bulk_push_line(),
			'window'    => self::window_cap(),
		);
	}

	/**
	 * A group's name: the template's, with the version counted.
	 *
	 * @param string   $group   Group key.
	 * @param int|null $version The version; the template's own when null.
	 * @return string
	 */
	public static function group_name( $group, $version = null ) {
		if ( self::NONE_GROUP === (string) $group ) {
			return __( 'Back to the plain description', 'tcgiant-sync' );
		}
		$tpl = TCGiant_Sync_Templates::get( (int) substr( (string) $group, 1 ) );
		if ( ! $tpl ) {
			return __( 'A template since deleted', 'tcgiant-sync' );
		}
		/* translators: 1: template name, 2: version */
		return sprintf( __( '%1$s, version %2$d', 'tcgiant-sync' ), $tpl['name'], null === $version ? (int) $tpl['version'] : (int) $version );
	}

	/**
	 * Why a listing is left out, in the screen's words.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function reason_words( $reason ) {
		$words = array(
			'unlinked'      => __( 'Not on eBay: not linked to a listing. They get the template when you push them.', 'tcgiant-sync' ),
			'ended'         => __( 'Ended on eBay.', 'tcgiant-sync' ),
			'variable'      => __( 'Variation listings: they get the template at their next push.', 'tcgiant-sync' ),
			'auction'       => __( 'Auctions: eBay restricts changes once there are bids, so they get the template at their next push.', 'tcgiant-sync' ),
			'other_site'    => __( 'On another eBay site, or on eBay Motors: they get it at their next push.', 'tcgiant-sync' ),
			'site_unknown'  => __( 'Their eBay site is not recorded yet. The weekly full sync records it, or Fetch Inventory on the Import from eBay page does now; then press Count again.', 'tcgiant-sync' ),
			'shared'        => __( 'Their eBay listing is claimed by more than one product. Unlink the extra product first.', 'tcgiant-sync' ),
			'attention'     => __( 'Need attention: eBay refused the re-send on every day it was tried, or said the listing has ended, which needs attention at once. Its words are in the log; a push of the product clears this.', 'tcgiant-sync' ),
			'refused_today' => __( 'Refused by eBay today: tried again tomorrow at the earliest. Its words are in the log.', 'tcgiant-sync' ),
			'recent'        => __( 'Sent a description in the last 24 hours. Press Count again tomorrow to include them: nothing counts them again by itself.', 'tcgiant-sync' ),
			'render_failed' => __( 'Their description could not be made: something in it stopped with an error, often a shortcode. Preview one of them.', 'tcgiant-sync' ),
			'too_long'      => __( 'Over eBay\'s 500,000 characters, tags included.', 'tcgiant-sync' ),
			'nested'        => __( 'Need a look: the product description already holds a design (a <style> block), usually another listing template imported with it. Preview one of them.', 'tcgiant-sync' ),
			'unstable'      => __( 'Come out differently each time, usually because of a shortcode that shows a date, a counter or a random pick.', 'tcgiant-sync' ),
			'refused'       => __( 'A push would refuse them as they are: open one and push it to see why.', 'tcgiant-sync' ),
			'title_differs' => __( 'Held for a push: the title a push would send is not the one their listing has on eBay - edited on the website, or on eBay - and only a push sends a title.', 'tcgiant-sync' ),
			'changed'       => __( 'Held for a push: the product\'s title, item specifics, condition, photos or price have changed since its listing was last sent, so only a full push keeps them together.', 'tcgiant-sync' ),
			'held'          => __( 'Held for a push: the product has been saved since its listing was last sent (a sale or a sync counts), so only a full push keeps its title, price and description together.', 'tcgiant-sync' ),
			'never_pushed'  => __( 'Never pushed from this site, or its last push did not finish: push it once, and it can be re-sent after that.', 'tcgiant-sync' ),
			'held_empty'    => __( 'Held for a push, at quantity 0: a push refuses a product with no stock, so it needs restocking first. That push sends the template too.', 'tcgiant-sync' ),
			'cache_cold'    => __( 'eBay\'s item specifics for their category are not kept on this site just now, so the description cannot be made as a push would make it. Open one product\'s eBay tab, or push one, then press Count again.', 'tcgiant-sync' ),
			'pushing'       => __( 'A push of it is on its way, and sends the template itself.', 'tcgiant-sync' ),
			'unchanged'     => __( 'No longer changes: something sent it meanwhile.', 'tcgiant-sync' ),
			'moved'         => __( 'Now gets another template.', 'tcgiant-sync' ),
			'skip'          => __( 'A template no longer reaches it.', 'tcgiant-sync' ),
		);
		return $words[ $reason ] ?? (string) $reason;
	}

	/**
	 * The reasons as the box lists them: Not on eBay last, which asks
	 * nothing of the shop, and with a default template is the largest.
	 *
	 * @param array $reasons Reason => count.
	 * @return array
	 */
	public static function reasons_in_order( array $reasons ) {
		if ( isset( $reasons['unlinked'] ) ) {
			$unlinked = $reasons['unlinked'];
			unset( $reasons['unlinked'] );
			$reasons['unlinked'] = $unlinked;
		}
		return $reasons;
	}

	/**
	 * Why Re-send all waits, in the screen's words, and when it looks again.
	 *
	 * @param array $pause why, until.
	 * @param bool  $when  Say when it tries again.
	 * @return string
	 */
	public static function pause_words( array $pause, $when = true ) {
		$words = array(
			'staging'       => __( 'This site looks like a staging or development copy, so nothing is sent to eBay from it.', 'tcgiant-sync' ),
			'not_connected' => __( 'This site is not connected to eBay.', 'tcgiant-sync' ),
			'fleet_unknown' => __( 'The figure for every shop\'s eBay calls today is missing or more than two hours old. It comes with this site\'s next eBay connection refresh, about every two hours.', 'tcgiant-sync' ),
			'budget'        => __( 'The day\'s eBay calls are used up.', 'tcgiant-sync' ),
			'fleet_low'     => __( 'Every TCGiant shop together has less than a quarter of the day\'s eBay calls left, which are kept for stock and orders.', 'tcgiant-sync' ),
			'own_half'      => __( 'This site has used half of its own eBay calls for today. The rest are kept for stock and orders.', 'tcgiant-sync' ),
			'limit'         => __( 'Today\'s bulk-push limit is reached. Re-sends count against it, as bulk pushes do.', 'tcgiant-sync' ),
			'window'        => __( 'It has sent a twelfth of the day\'s bulk-push limit in the last two hours, the most it sends in any two.', 'tcgiant-sync' ),
			'unreachable'   => __( 'eBay could not be reached, or was busy.', 'tcgiant-sync' ),
		);
		$text = $words[ $pause['why'] ?? '' ] ?? (string) ( $pause['why'] ?? '' );
		if ( $when && ! empty( $pause['until'] ) ) {
			$text .= ' ' . sprintf(
				/* translators: %s: a date and time */
				__( 'It tries again at %s.', 'tcgiant-sync' ),
				function_exists( 'wp_date' ) ? wp_date( 'j M Y, H:i', (int) $pause['until'] ) : gmdate( 'j M Y, H:i', (int) $pause['until'] ) . ' UTC'
			);
		}
		return $text;
	}

	/**
	 * The line every notice after a template-side change ends with: nothing
	 * has gone to eBay, and where to send it.
	 *
	 * @return string
	 */
	public static function pointer() {
		return __( 'Listings already on eBay keep their description until their next push. To send them the new description alone, use Re-send to eBay on the Templates page once it has counted them.', 'tcgiant-sync' );
	}

	/**
	 * The groups, for the log.
	 *
	 * @param array $groups Groups.
	 * @return string
	 */
	private static function groups_words( array $groups ) {
		$out = array();
		foreach ( $groups as $key => $group ) {
			$out[] = sprintf( '%s: %d', self::NONE_GROUP === (string) $key ? 'the plain description' : sprintf( 'template #%d version %d', (int) $group['template_id'], (int) $group['version'] ), (int) $group['count'] );
		}
		return implode( ', ', $out );
	}

	/**
	 * The reasons, for the log.
	 *
	 * @param array $reasons Reason => count.
	 * @return string
	 */
	private static function reasons_words( array $reasons ) {
		$out = array();
		foreach ( $reasons as $reason => $n ) {
			$out[] = $reason . ' ' . (int) $n;
		}
		return implode( ', ', $out );
	}

	// -------------------------------------------------------------------------
	// Options
	// -------------------------------------------------------------------------

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
	 */
	private static function write( $name, array $value ) {
		update_option( $name, $value, false );
	}

	/**
	 * Policies fetched from eBay, as the policy blocks read them: compared
	 * before and after Fetch eBay Policies, which asks for a count when they
	 * changed.
	 *
	 * @return string
	 */
	public static function policies_fingerprint() {
		return sha1( (string) wp_json_encode( array(
			get_option( 'tcgiant_export_policies_return', array() ),
			get_option( 'tcgiant_export_policies_fulfillment', array() ),
		) ) );
	}
}
